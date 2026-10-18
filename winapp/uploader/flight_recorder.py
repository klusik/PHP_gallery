# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_recorder.py
# Module Type: Flight Recording Service
# Purpose: Record optional read-only simulator observations to durable sessions.
# Responsibilities:
#   - Own the recorder thread and append bounded JSONL checkpoints.
#   - Recover incomplete sessions through the shared analysis model.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Background frame-sampled flight recording with durable JSONL checkpoints."""
import json
from collections import deque
import logging
import math
import threading
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

from uploader.flight_data import (
    AIRBORNE_AGL_MINIMUM_METRES,
    AIRBORNE_GROUND_SPEED_MINIMUM_MPS,
    PHASE_AIRBORNE_CONFIRM_SAMPLES,
    PHASE_APPROACH_AGL_METRES,
    PHASE_CLIMB_MINIMUM_MPS,
    PHASE_DESCENT_MAXIMUM_MPS,
    FlightAnalysis,
    FlightSample,
    analyze_session,
)
from uploader.simconnect_flight import SimConnectFlightClient

# Initial wait after a disconnected simulator or open failure.
# Type: float. Units: seconds. Scope: consecutive connection failures.
# Consumers: FlightRecorder.run. Rationale: avoid repeated native loader attempts.
FLIGHT_RECONNECT_INITIAL_SECONDS = 2.0
# Maximum wait between retries while the simulator remains unavailable.
# Type: float. Units: seconds. Scope: consecutive connection failures.
# Consumers: FlightRecorder.run. Rationale: bounded quiet recovery without busy spin.
FLIGHT_RECONNECT_MAX_SECONDS = 30.0
# Limit accepted frame samples to twenty per second; SimConnect supplies the
# newest core packet from a bounded queue drain at this requested cadence.
# Type: float. Units: seconds. Scope: one recorder thread.
# Consumers: FlightRecorder.run. Rationale: preserve touchdown evidence while
# bounding file growth below the simulator's native visual-frame frequency.
FLIGHT_SAMPLE_INTERVAL_SECONDS = 0.05
# Treat this host elapsed gap as a pause or lost cadence.
# Type: float. Units: seconds. Scope: consecutive samples.
# Consumers: FlightRecorder._discontinuity.
# Rationale: large gaps cannot prove continuous trajectory.
FLIGHT_GAP_LIMIT_SECONDS = 5.0
# Force session metadata checkpoint at this sample count.
# Type: int. Units: samples. Scope: one active session.
# Consumers: FlightRecorder._write_sample. Rationale: bound crash recovery loss.
CHECKPOINT_SAMPLE_INTERVAL = 100
# Break track after implausible movement between adjacent positions.
# Type: float. Units: nautical miles. Scope: consecutive samples.
# Consumers: FlightRecorder._discontinuity.
# Rationale: detect teleport or aircraft-position reloads.
TELEPORT_DISTANCE_LIMIT_NM = 10.0
# Keep five seconds of raw frames before approach trigger for touchdown evidence.
# Type: int. Units: samples. Scope: one recorder process.
# Consumers: FlightRecorder.run. Rationale: recover short pre-contact windows.
FLIGHT_RAW_RING_SAMPLES = 100
# Persist only each one-second cruise observation after acquisition at 20 Hz.
# Type: float. Units: seconds. Scope: stable high-altitude flight.
# Consumers: FlightRecorder.run. Rationale: keep multi-hour sessions bounded.
FLIGHT_CRUISE_PERSIST_INTERVAL_SECONDS = 1.0
# Enter high-resolution persistence near the surface.
# Type: float. Units: metres AGL. Scope: one flight session.
# Consumers: FlightRecorder.run. Rationale: preserve approach and touchdown evidence.
FLIGHT_APPROACH_CAPTURE_AGL_METRES = 300.0
# Return to sparse cruise persistence after a climb away from an approach.
# Type: float. Units: metres AGL. Scope: approach/go-around detection.
# Consumers: FlightRecorder.run. Rationale: keep go-around capture bounded.
FLIGHT_APPROACH_EXIT_AGL_METRES = 500.0
# Keep dense touchdown and initial rollout frames for one minute.
# Type: float. Units: seconds. Scope: each landing rollout.
# Consumers: FlightRecorder.run. Rationale: preserve contact context and early rollout.
FLIGHT_ROLLOUT_CAPTURE_SECONDS = 60.0
# Global ceiling for raw flight samples; existing PDFs are excluded.
# Type: int. Units: bytes. Scope: one recorder storage root.
# Consumers: FlightRecorder._initialize_storage_budget and _write_sample.
# Rationale: retain every report while bounding aggregate raw-session storage.
MAX_GLOBAL_RAW_SESSION_BYTES = 2147483648
# Maximum raw bytes retained in one session.
# Type: int. Units: bytes. Scope: one session directory.
# Consumers: FlightRecorder._write_sample. Rationale: hard bound on disk usage.
MAX_SESSION_BYTES = 268435456
# Bound in-memory warning evidence.
# Type: int. Units: messages. Scope: one recording session.
# Consumers: FlightRecorder._checkpoint and run.
# Rationale: repeated errors cannot grow metadata indefinitely.
MAX_SESSION_WARNINGS = 100
# Bound finalization wait before refusing analysis of an active writer.
# Type: float. Units: seconds. Scope: explicit finalize call.
# Consumers: FlightRecorder.finalize.
# Rationale: never read a concurrently appended JSONL file.
FLIGHT_FINALIZE_WAIT_SECONDS = 15.0
EARTH_RADIUS_NM = 3440.065


class _FlightPersistencePolicy:
    """Bound raw frame retention while preserving approach and contact detail."""

    def __init__(self) -> None:
        """Create a five-second pre-event buffer and cruise cadence state.

        Returns:
            None: The policy is ready for chronological raw frame input.
        """
        self.raw_ring: deque[FlightSample] = deque(
            maxlen=FLIGHT_RAW_RING_SAMPLES
        )
        self.airborne_seen = False
        self.high_resolution = False
        self.last_cruise_persist = -FLIGHT_CRUISE_PERSIST_INTERVAL_SECONDS
        self.ground_fullrate_until: float | None = None
        self.rollout_capture_active = False
        self.rollout_capture_done = False
        self.last_persisted_elapsed = -1.0

    def samples_to_persist(self, sample: FlightSample) -> list[FlightSample]:
        """Choose durable frames for one newly acquired simulator observation.

        Args:
            sample (FlightSample): New 20 Hz user-aircraft observation.

        Returns:
            list[FlightSample]: Zero or more frames to append in chronological
                order; cruise is sparse and approach/contact windows are dense.
        """
        self.raw_ring.append(sample)
        if (
            sample.on_ground is False
            or (
                sample.agl_m is not None
                and sample.agl_m > AIRBORNE_AGL_MINIMUM_METRES
            )
        ):
            self.airborne_seen = True
        near_surface = (
            sample.agl_m is not None
            and sample.agl_m <= FLIGHT_APPROACH_CAPTURE_AGL_METRES
            and self.airborne_seen
            and sample.on_ground is not True
        )
        touchdown = self.airborne_seen and sample.on_ground is True
        if sample.on_ground is False:
            self.rollout_capture_done = False
        if touchdown and not self.rollout_capture_active and not self.rollout_capture_done:
            self.ground_fullrate_until = (
                sample.session_elapsed_seconds + FLIGHT_ROLLOUT_CAPTURE_SECONDS
            )
            self.rollout_capture_active = True
            self.rollout_capture_done = True
        if (
            self.high_resolution
            and self.ground_fullrate_until is not None
            and sample.session_elapsed_seconds > self.ground_fullrate_until
        ):
            self.high_resolution = False
            self.rollout_capture_active = False
            self.ground_fullrate_until = None
        if (
            self.high_resolution
            and sample.on_ground is False
            and sample.agl_m is not None
            and sample.agl_m > FLIGHT_APPROACH_EXIT_AGL_METRES
        ):
            self.high_resolution = False
            self.rollout_capture_active = False
            self.ground_fullrate_until = None
        preserve_rollout = (
            self.rollout_capture_active
            and self.ground_fullrate_until is not None
            and sample.session_elapsed_seconds <= self.ground_fullrate_until
        )
        persisted: list[FlightSample] = []
        if near_surface or preserve_rollout:
            if not self.high_resolution:
                persisted = [
                    buffered
                    for buffered in self.raw_ring
                    if buffered.session_elapsed_seconds
                    > self.last_persisted_elapsed
                ]
                self.high_resolution = True
            else:
                persisted = [sample]
        elif not self.high_resolution and (
            sample.session_elapsed_seconds - self.last_cruise_persist
            >= FLIGHT_CRUISE_PERSIST_INTERVAL_SECONDS
        ):
            persisted = [sample]
            self.last_cruise_persist = sample.session_elapsed_seconds
        elif self.high_resolution:
            persisted = [sample]
        if persisted:
            self.last_persisted_elapsed = persisted[-1].session_elapsed_seconds
        return persisted


class FlightRecorder(threading.Thread):
    """Record a session independently from screenshot and upload workers."""

    def __init__(
        self,
        directory: Path,
        gallery_identity: str,
        dll_path: str,
        emit: Callable[[str, str], None],
    ) -> None:
        """Create an idle durable recorder for one gallery identity.

        Args:
            directory (Path): Parent where the session directory is created.
            gallery_identity (str): Non-secret stable gallery label.
            dll_path (str): Optional explicit SimConnect.dll path.
            emit (Callable[[str, str], None]): Thread-safe level/message sink.

        Returns:
            None: Initializes but does not start the worker.
        """
        super().__init__(name="FlightRecorder", daemon=True)
        self.gallery_identity = gallery_identity
        self.dll_path = dll_path
        self.emit = emit
        self.session_id = uuid.uuid4().hex
        self.directory = Path(directory) / self.session_id
        self.stop_event = threading.Event()
        self.pause_event = threading.Event()
        self.started_monotonic: float | None = None
        self.started_utc = datetime.now(timezone.utc).isoformat()
        self.simulator = "unknown"
        self.simulator_build: str | None = None
        self.aircraft: str | None = None
        self.completed = False
        self.warnings: list[str] = []
        self._sample_count = 0
        self._global_remaining_bytes = MAX_GLOBAL_RAW_SESSION_BYTES
        self._last_sample: FlightSample | None = None
        self._last_simulator_elapsed: float | None = None
        self._last_utc: datetime | None = None
        self._checkpoint_lock = threading.Lock()
        self._pending_discontinuity: str | None = None
        self._activity_candidate: str | None = None
        self._activity_candidate_count = 0
        self._activity_phase: str | None = None

    def stop(self) -> None:
        """Request graceful stop and wake the cadence wait.

        Returns:
            None: The worker flushes final metadata before exiting.
        """
        self.stop_event.set()

    def pause(self) -> None:
        """Pause acquisition while preserving the current session.

        Returns:
            None: Resumption begins a discontinuous track segment.
        """
        self.pause_event.set()

    def resume(self) -> None:
        """Resume acquisition and mark the next sample as discontinuous.

        Returns:
            None: Capture resumes on the next frame opportunity.
        """
        if self.pause_event.is_set():
            self.pause_event.clear()
            self._pending_discontinuity = "recorder resumed after pause"

    def _checkpoint(self, ended: bool = False) -> None:
        """Atomically replace metadata after progress or termination.

        Args:
            ended (bool): Whether recording has ended.

        Returns:
            None: A same-directory temporary file is atomically renamed.
        """
        elapsed = (
            time.monotonic() - self.started_monotonic
            if self.started_monotonic is not None else 0.0
        )
        payload = {
            "schema_version": 1,
            "session_id": self.session_id,
            "gallery_identity": self.gallery_identity,
            "simulator": self.simulator,
            "simulator_build": self.simulator_build,
            "aircraft": self.aircraft,
            "started_utc": self.started_utc,
            "started_monotonic": 0.0,
            "ended_monotonic": elapsed if ended else None,
            "recording_ended": ended,
            "warnings": self.warnings[-MAX_SESSION_WARNINGS:],
            "sample_count": self._sample_count,
        }
        path = self.directory / "session.json"
        temp = path.with_suffix(".json.tmp")
        with self._checkpoint_lock:
            temp.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
            temp.replace(path)

    def _discontinuity(
        self,
        sample: FlightSample,
        previous: FlightSample | None,
    ) -> str | None:
        """Identify cadence, clock, position or transport breaks.

        Args:
            sample (FlightSample): Current normalized telemetry.
            previous (FlightSample | None): Previous accepted sample.

        Returns:
            str | None: Break reason or None when continuity is plausible.
        """
        if sample.discontinuity_before:
            return sample.discontinuity_before
        if self._pending_discontinuity:
            reason = self._pending_discontinuity
            self._pending_discontinuity = None
            return reason
        if previous is None:
            return None
        gap = sample.session_elapsed_seconds - previous.session_elapsed_seconds
        if gap < 0 or gap > FLIGHT_GAP_LIMIT_SECONDS:
            return "sample cadence gap"
        if (
            sample.simulator_elapsed_seconds is not None
            and self._last_simulator_elapsed is not None
        ):
            sim_gap = sample.simulator_elapsed_seconds - self._last_simulator_elapsed
            if sim_gap < -0.5 or abs(sim_gap - gap) > FLIGHT_GAP_LIMIT_SECONDS:
                return "simulator time discontinuity or pause"
        if self._last_utc is not None:
            utc_gap = (
                datetime.fromisoformat(sample.recorded_utc) - self._last_utc
            ).total_seconds()
            if abs(utc_gap - gap) > FLIGHT_GAP_LIMIT_SECONDS:
                return "host clock correction"
        distance = self._haversine_nm(previous, sample)
        if distance is not None and distance > TELEPORT_DISTANCE_LIMIT_NM:
            return "aircraft position discontinuity"
        return None

    @staticmethod
    def _haversine_nm(first: FlightSample, second: FlightSample) -> float | None:
        """Measure approximate great-circle distance between positions.

        Args:
            first (FlightSample): Earlier sample.
            second (FlightSample): Later sample.

        Returns:
            float | None: Nautical-mile distance or None when coordinates are unknown.
        """
        coords = (
            first.latitude_deg, first.longitude_deg,
            second.latitude_deg, second.longitude_deg,
        )
        if any(value is None for value in coords):
            return None
        lat1, lon1, lat2, lon2 = (
            math.radians(value) for value in coords if value is not None
        )
        value = (
            math.sin((lat2 - lat1) / 2) ** 2
            + math.cos(lat1) * math.cos(lat2)
            * math.sin((lon2 - lon1) / 2) ** 2
        )
        return 2 * EARTH_RADIUS_NM * math.asin(min(1.0, math.sqrt(value)))

    def _emit_phase_change(self, sample: FlightSample) -> None:
        """Emit a concise stable activity label without creating report events.

        Args:
            sample (FlightSample): Accepted simulator telemetry frame.

        Returns:
            None: A stable label is emitted only when it changes.
        """
        if sample.discontinuity_before:
            self._activity_candidate = None
            self._activity_candidate_count = 0
            return
        phase: str | None = None
        if sample.on_ground is True:
            phase = "ground"
        elif (
            sample.on_ground is False
            and sample.agl_m is not None
            and sample.agl_m > AIRBORNE_AGL_MINIMUM_METRES
            and sample.ground_speed_mps is not None
            and sample.ground_speed_mps > AIRBORNE_GROUND_SPEED_MINIMUM_MPS
        ):
            vertical = sample.vertical_speed_mps
            if vertical is not None and vertical > PHASE_CLIMB_MINIMUM_MPS:
                phase = "climb"
            elif vertical is not None and vertical < PHASE_DESCENT_MAXIMUM_MPS:
                phase = (
                    "approach"
                    if sample.agl_m <= PHASE_APPROACH_AGL_METRES
                    else "descent"
                )
            else:
                phase = "airborne"
        if phase is None:
            self._activity_candidate = None
            self._activity_candidate_count = 0
            return
        if phase == self._activity_candidate:
            self._activity_candidate_count += 1
        else:
            self._activity_candidate = phase
            self._activity_candidate_count = 1
        if (
            self._activity_candidate_count >= PHASE_AIRBORNE_CONFIRM_SAMPLES
            and phase != self._activity_phase
        ):
            self._activity_phase = phase
            self.emit("info", f"Flight activity: {phase}.")

    def _initialize_storage_budget(
        self,
        maximum_bytes: int = MAX_GLOBAL_RAW_SESSION_BYTES,
    ) -> None:
        """Measure stored raw sessions once and reserve remaining global capacity.

        Args:
            maximum_bytes (int): Global budget override, primarily for fixtures.

        Returns:
            None: Existing report files are never inspected or removed.
        """
        stored_bytes = 0
        root = self.directory.parent
        if root.exists():
            for sample_path in root.glob("*/samples.jsonl"):
                try:
                    stored_bytes += sample_path.stat().st_size
                except OSError:
                    continue
        self._global_remaining_bytes = max(
            0,
            maximum_bytes - stored_bytes,
        )

    def _write_sample(self, sample: FlightSample) -> bool:
        """Append a flushed JSONL record if the disk bound allows it.

        Args:
            sample (FlightSample): Current simulator frame.

        Returns:
            bool: True if written; False when the session byte cap is reached.
        """
        path = self.directory / "samples.jsonl"
        sample.discontinuity_before = self._discontinuity(
            sample, self._last_sample
        )
        record = json.dumps(
            {"type": "sample", "sample": sample.to_dict()},
            ensure_ascii=False,
            allow_nan=False,
        ) + "\n"
        current_size = path.stat().st_size if path.exists() else 0
        encoded_size = len(record.encode("utf-8"))
        if (
            current_size + encoded_size > MAX_SESSION_BYTES
            or encoded_size > self._global_remaining_bytes
        ):
            self.warnings.append(
                "Raw flight storage quota reached; recording stopped. "
                "Existing reports were retained."
            )
            self.warnings = self.warnings[-MAX_SESSION_WARNINGS:]
            return False
        with path.open("a", encoding="utf-8") as stream:
            stream.write(record)
            stream.flush()
        self._global_remaining_bytes -= encoded_size
        self._sample_count += 1
        self._last_sample = sample
        self._last_simulator_elapsed = sample.simulator_elapsed_seconds
        self._last_utc = datetime.fromisoformat(sample.recorded_utc)
        if self._sample_count % CHECKPOINT_SAMPLE_INTERVAL == 0:
            self._checkpoint()
        return True

    def run(self) -> None:
        """Acquire frame samples and checkpoint through soft native failures.

        Returns:
            None: Closes the connection and persists final session metadata.
        """
        self._initialize_storage_budget()
        self.directory.mkdir(parents=True, exist_ok=True)
        self.started_monotonic = time.monotonic()
        self._checkpoint()
        client = SimConnectFlightClient(self.dll_path, self.emit)
        persistence = _FlightPersistencePolicy()
        failure_count = 0
        retry_delay = FLIGHT_RECONNECT_INITIAL_SECONDS
        self.emit("info", "Flight recording started.")
        try:
            while not self.stop_event.is_set():
                if self.pause_event.is_set():
                    self.stop_event.wait(FLIGHT_SAMPLE_INTERVAL_SECONDS)
                    continue
                tick = time.monotonic()
                try:
                    observation = client.sample()
                    if observation is None:
                        if client.last_error or not client.handle.value:
                            failure_count += 1
                            if failure_count == 1:
                                self.warnings.append(
                                    "Simulator unavailable; reconnecting with backoff."
                                )
                                self.warnings = self.warnings[-MAX_SESSION_WARNINGS:]
                                self._checkpoint()
                                self.emit(
                                    "warning",
                                    client.last_error or "SimConnect connection unavailable.",
                                )
                            self.stop_event.wait(retry_delay)
                            retry_delay = min(
                                FLIGHT_RECONNECT_MAX_SECONDS,
                                retry_delay * 2.0,
                            )
                    else:
                        failure_count = 0
                        retry_delay = FLIGHT_RECONNECT_INITIAL_SECONDS
                        sample, identity = observation
                        sample.session_elapsed_seconds = max(
                            0.0, time.monotonic() - self.started_monotonic
                        )
                        self.simulator = identity.get("simulator") or self.simulator
                        self.simulator_build = (
                            identity.get("simulator_build")
                            or self.simulator_build
                        )
                        self.aircraft = identity.get("aircraft") or self.aircraft
                        if identity.get("discontinuity"):
                            sample.discontinuity_before = identity["discontinuity"]
                        for persisted_sample in persistence.samples_to_persist(sample):
                            if self._write_sample(persisted_sample):
                                self._emit_phase_change(persisted_sample)
                            else:
                                self.stop_event.set()
                                break
                except Exception as exc:
                    failure_count += 1
                    retry_delay = min(
                        FLIGHT_RECONNECT_MAX_SECONDS,
                        max(FLIGHT_RECONNECT_INITIAL_SECONDS, retry_delay * 2.0),
                    )
                    message = f"Flight sample unavailable: {type(exc).__name__}: {exc}"
                    if not self.warnings or self.warnings[-1] != message:
                        self.warnings.append(message[:500])
                        self.warnings = self.warnings[-MAX_SESSION_WARNINGS:]
                        logging.debug(message, exc_info=True)
                        self._checkpoint()
                    self.stop_event.wait(retry_delay)
                remaining = FLIGHT_SAMPLE_INTERVAL_SECONDS - (
                    time.monotonic() - tick
                )
                self.stop_event.wait(max(0.01, remaining))
        finally:
            client.stop()
            self.completed = True
            self._checkpoint(ended=True)
            self.emit(
                "info",
                f"Flight recording stopped; {self._sample_count} samples saved.",
            )

    def finalize(self) -> FlightAnalysis:
        """Stop, wait for the writer, then analyze a stable session.

        Returns:
            FlightAnalysis: Recovered summary and bounded plot observations.

        Raises:
            RuntimeError: Recorder was not started.
            TimeoutError: Native worker still writes after the bounded wait.
        """
        if self.ident is None:
            raise RuntimeError("Flight recorder must be started before finalize.")
        self.stop()
        self.join(timeout=FLIGHT_FINALIZE_WAIT_SECONDS)
        if self.is_alive():
            raise TimeoutError("Flight recorder has not finished writing.")
        return analyze_session(self.directory)
