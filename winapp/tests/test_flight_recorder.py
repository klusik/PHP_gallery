# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_flight_recorder.py
# Module Type: Flight Analysis Tests
# Purpose: Verify contact analysis, sim-time regression and bounded plot output.
# Responsibilities:
#   - Reject taxi and one-frame ground-flag chatter as landings.
#   - Recognize plausible rebounds without counting every ground frame.
#   - Preserve touchdown events when long-flight chart samples are reduced.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Deterministic replay coverage for flight session analysis."""
import json
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

from uploader.flight_data import (
    ANALYSIS_SAMPLE_WINDOW,
    ANALYSIS_VERSION,
    PHASE_GOAROUND_SUSTAIN_SECONDS,
    ROLLOUT_TAXI_CONFIRM_SECONDS,
    FlightSample,
    _sample,
    analyze_session,
)
from uploader.flight_recorder import (
    FlightRecorder,
    LIVE_TELEMETRY_MAX_AGE_SECONDS,
    _FlightPersistencePolicy,
)

# Numeric fixture policy boundaries use one-second samples to make expected
# transition times transparent. These are synthetic vectors, not runtime defaults.
TEST_SAMPLE_INTERVAL_SECONDS = 1.0
TAXI_SAMPLE_COUNT = 4
LANDING_APPROACH_SAMPLE_COUNT = 16
LONG_HAUL_SAMPLE_COUNT = 9000
# Type: int. Units: simulated seconds. Scope: recorder replay fixture.
# Consumers: longhaul persistence test. Rationale: exercise sparse cruise writes.
RECORDER_LONG_HAUL_SECONDS = 3600
# Type: float. Units: seconds per raw frame. Scope: recorder replay fixture.
# Consumers: longhaul persistence test. Rationale: model 20 Hz acquisition.
RECORDER_FRAME_INTERVAL_SECONDS = 0.05
# Type: int. Units: frames. Scope: approach and contact fixture.
# Consumers: longhaul persistence test. Rationale: retain four seconds of evidence.
RECORDER_APPROACH_FRAME_COUNT = 80
# Type: int. Units: frames. Scope: touchdown-analysis fixture.
# Consumers: test_taxi_is_ignored_and_descent_uses_simulator_clock.
# Rationale: require sustained airborne evidence before the flare samples.
APPROACH_AIRBORNE_PREROLL_COUNT = 4
# Type: float. Units: metres AGL. Scope: touchdown-analysis fixture.
# Consumers: test_taxi_is_ignored_and_descent_uses_simulator_clock.
# Rationale: exceed the production airborne threshold for each preroll frame.
APPROACH_AIRBORNE_PREROLL_AGL_METRES = 4.0
# Type: float. Units: metres per second. Scope: touchdown-analysis fixture.
# Consumers: test_taxi_is_ignored_and_descent_uses_simulator_clock.
# Rationale: keep synthetic altitude and simulator-clock slopes consistent.
APPROACH_FIXTURE_VERTICAL_SPEED_MPS = -4.0


def make_sample(
    elapsed: float,
    *,
    on_ground: bool | None,
    agl_m: float | None,
    vertical_speed_mps: float | None,
    altitude_m: float,
    latitude_deg: float = 49.0,
    simulator_elapsed: float | None = None,
    discontinuity: str | None = None,
    ground_speed_mps: float = 60.0,
) -> dict[str, object]:
    """Build one JSON-compatible synthetic recorder row.

    Args:
        elapsed (float): Host-monotonic session time in seconds.
        on_ground (bool | None): SDK ground state or unknown.
        agl_m (float | None): Height above ground in metres or unknown.
        vertical_speed_mps (float | None): Vertical speed in SI units.
        altitude_m (float): Aircraft altitude in metres.
        latitude_deg (float): Synthetic route latitude.
        simulator_elapsed (float | None): Simulator elapsed clock.
        discontinuity (str | None): Optional track-split reason.
        ground_speed_mps (float): Synthetic groundspeed in metres per second.

    Returns:
        dict[str, object]: One persisted JSONL sample envelope.
    """
    return {
        "type": "sample",
        "sample": {
            "session_elapsed_seconds": elapsed,
            "recorded_utc": f"2026-01-01T00:00:{int(elapsed) % 60:02d}+00:00",
            "simulator_elapsed_seconds": simulator_elapsed,
            "latitude_deg": latitude_deg,
            "longitude_deg": 14.0,
            "altitude_m": altitude_m,
            "agl_m": agl_m,
            "vertical_speed_mps": vertical_speed_mps,
            "ground_speed_mps": ground_speed_mps,
            "on_ground": on_ground,
            "discontinuity_before": discontinuity,
        },
    }


def write_session(
    directory: Path,
    rows: list[dict[str, object]],
    *,
    recording_ended: bool = False,
    simulator_build: str | None = None,
) -> None:
    """Persist synthetic JSONL samples and a partial-session checkpoint.

    Args:
        directory (Path): Temporary folder used as the session directory.
        rows (list[dict[str, object]]): Ordered sample envelopes.
        recording_ended (bool): Whether the recorder stopped cleanly.
        simulator_build (str | None): OPEN server/API version provenance.


    Returns:
        None: Writes the files consumed by offline recovery.
    """
    (directory / "session.json").write_text(
        json.dumps({
            "session_id": "replay",
            "recording_ended": recording_ended,
            "simulator": "Microsoft Flight Simulator 2024",
            "simulator_build": simulator_build,
            "completed": False,
            "ended_monotonic": None,
        }),
        encoding="utf-8",
    )
    (directory / "samples.jsonl").write_text(
        "".join(json.dumps(row) + "\n" for row in rows),
        encoding="utf-8",
    )


class FlightAnalysisReplayTests(unittest.TestCase):
    """Verify full-resolution event extraction independently from chart sampling."""

    def test_live_display_expires_and_pause_never_exposes_old_values(self) -> None:
        """Keep compact instrument snapshots passive and bounded in freshness.

        Returns:
            None: Fresh acquired values appear without disk persistence; stale,
                paused and stopped recorder states expose no live readings.
        """
        with tempfile.TemporaryDirectory() as temporary:
            recorder = FlightRecorder(Path(temporary), "gallery", "", lambda *_args: None)
            sample = FlightSample(
                session_elapsed_seconds=0.0, recorded_utc="2026-10-18T10:00:00+00:00",
                indicated_airspeed_mps=100.0, altitude_m=3000.0,
                vertical_speed_mps=5.0,
            )
            recorder._latest_sample = sample
            recorder._latest_sample_received = 0.0
            with (
                patch.object(recorder, "is_alive", return_value=True),
                patch("uploader.flight_recorder.time.monotonic", return_value=0.0),
            ):
                self.assertEqual(3000.0, recorder.telemetry_snapshot()["altitude_m"])
                recorder.pause()
                self.assertIsNone(recorder.telemetry_snapshot())
                recorder.resume()
                self.assertIsNone(recorder.telemetry_snapshot())
                recorder._latest_sample = sample
                with patch("uploader.flight_recorder.time.monotonic", return_value=LIVE_TELEMETRY_MAX_AGE_SECONDS + 0.1):
                    self.assertIsNone(recorder.telemetry_snapshot())
                recorder.stop()
                self.assertIsNone(recorder.telemetry_snapshot())

    def test_diagnostics_distinguish_saved_frames_from_paused_recorder(self) -> None:
        """Expose actual durable progress without opening a native connection.

        Returns:
            None: Diagnostics count flushed samples, preserve a failure reason,
                and distinguish paused state from an active worker.
        """
        with tempfile.TemporaryDirectory() as temporary:
            recorder = FlightRecorder(
                Path(temporary), "gallery", "", lambda _level, _message: None
            )
            recorder.directory.mkdir(parents=True)
            recorder.simulator = "Microsoft Flight Simulator 2024"
            sample = FlightSample(
                session_elapsed_seconds=0.0,
                recorded_utc="2026-10-18T09:00:00+00:00",
            )
            self.assertTrue(recorder._write_sample(sample))
            recorder.warnings.append("Core telemetry rejected (7).")
            recorder.pause()
            snapshot = recorder.diagnostics_snapshot()
            self.assertEqual(1, snapshot["sample_count"])
            self.assertTrue(snapshot["paused"])
            self.assertFalse(snapshot["running"])
            self.assertEqual(recorder.simulator, snapshot["simulator"])
            self.assertEqual(recorder.session_id, snapshot["session_id"])
            self.assertEqual(recorder.warnings[-1], snapshot["last_warning"])
            recorder.resume()
            self.assertFalse(recorder.diagnostics_snapshot()["paused"])

    def test_recorder_longhaul_storage_is_sparse_and_keeps_dense_landing(self) -> None:
        """Replay 20 Hz capture through cruise and preserve landing frame detail.

        Returns:
            None: Cruise storage stays bounded and the final landing remains dense.
        """
        policy = _FlightPersistencePolicy()
        persisted: list[FlightSample] = []
        frame_count = int(
            RECORDER_LONG_HAUL_SECONDS / RECORDER_FRAME_INTERVAL_SECONDS
        )
        recorded_utc = "2025-01-01T00:00:00+00:00"
        for index in range(frame_count):
            elapsed = index * RECORDER_FRAME_INTERVAL_SECONDS
            sample = FlightSample(
                session_elapsed_seconds=elapsed,
                recorded_utc=recorded_utc,
                simulator_elapsed_seconds=elapsed,
                agl_m=5000.0,
                on_ground=False,
            )
            persisted.extend(policy.samples_to_persist(sample))
        approach_start = frame_count
        for index in range(RECORDER_APPROACH_FRAME_COUNT):
            elapsed = (
                approach_start + index
            ) * RECORDER_FRAME_INTERVAL_SECONDS
            agl = max(0.0, 300.0 - index * 4.0)
            on_ground = agl == 0.0
            sample = FlightSample(
                session_elapsed_seconds=elapsed,
                recorded_utc=recorded_utc,
                simulator_elapsed_seconds=elapsed,
                agl_m=agl,
                on_ground=on_ground,
            )
            persisted.extend(policy.samples_to_persist(sample))
        persisted_times = [
            sample.session_elapsed_seconds for sample in persisted
        ]
        self.assertLess(len(persisted), 4000)
        self.assertEqual(
            RECORDER_APPROACH_FRAME_COUNT - 1,
            sum(
                left < right and abs(right - left - RECORDER_FRAME_INTERVAL_SECONDS) < 0.0001
                for left, right in zip(
                    persisted_times[-RECORDER_APPROACH_FRAME_COUNT:],
                    persisted_times[-RECORDER_APPROACH_FRAME_COUNT + 1:],
                )
            ),
        )
        self.assertEqual(
            frame_count + RECORDER_APPROACH_FRAME_COUNT - 1,
            int(persisted_times[-1] / RECORDER_FRAME_INTERVAL_SECONDS),
        )

    def test_global_raw_quota_keeps_prior_reports_and_reserves_only_remainder(self) -> None:
        """Measure quota once with a virtual budget instead of large fixture files.

        Returns:
            None: Existing raw sessions consume capacity without being deleted.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "sessions"
            prior = root / "prior"
            prior.mkdir(parents=True)
            (prior / "samples.jsonl").write_text("1234567", encoding="utf-8")
            recorder = FlightRecorder(
                root,
                "gallery",
                "",
                lambda _level, _message: None,
            )
            recorder._initialize_storage_budget(maximum_bytes=10)
            self.assertEqual(3, recorder._global_remaining_bytes)
            self.assertEqual(
                "1234567",
                (prior / "samples.jsonl").read_text(encoding="utf-8"),
            )

    def test_recovery_preserves_optional_frame_fields(self) -> None:
        """Keep optional native telemetry available after offline recovery.

        Returns:
            None: All supported nullable values survive JSONL decoding.
        """
        row: dict[str, object] = {
            "session_elapsed_seconds": 1.0,
            "recorded_utc": "2025-01-01T00:00:00+00:00",
            "wind_velocity_mps": 5.5,
            "wind_direction_deg": 270.0,
            "total_weight_kg": 6200.0,
            "left_brake_position": 0.4,
            "right_brake_position": 0.5,
            "autopilot_master": True,
        }
        recovered = _sample(row)
        self.assertIsNotNone(recovered)
        self.assertEqual(5.5, recovered.wind_velocity_mps)
        self.assertEqual(270.0, recovered.wind_direction_deg)
        self.assertEqual(6200.0, recovered.total_weight_kg)
        self.assertEqual(0.4, recovered.left_brake_position)
        self.assertEqual(0.5, recovered.right_brake_position)
        self.assertTrue(recovered.autopilot_master)

    def test_taxi_is_ignored_and_descent_uses_simulator_clock(self) -> None:
        """Detect one touchdown after takeoff and fit the pre-contact sim-time slope.

        Returns:
            None: Asserts conservative event and bounded-chart output.
        """
        rows = [
            make_sample(
                float(index * TEST_SAMPLE_INTERVAL_SECONDS),
                on_ground=True,
                agl_m=0.0,
                vertical_speed_mps=0.0,
                altitude_m=100.0,
                simulator_elapsed=float(index),
            )
            for index in range(TAXI_SAMPLE_COUNT)
        ]
        approach_start = TAXI_SAMPLE_COUNT
        for offset in range(APPROACH_AIRBORNE_PREROLL_COUNT):
            rows.append(make_sample(
                float(approach_start + offset),
                on_ground=False,
                agl_m=(
                    APPROACH_AIRBORNE_PREROLL_AGL_METRES
                    + APPROACH_FIXTURE_VERTICAL_SPEED_MPS
                    * offset * RECORDER_FRAME_INTERVAL_SECONDS
                ),
                vertical_speed_mps=APPROACH_FIXTURE_VERTICAL_SPEED_MPS,
                altitude_m=(
                    100.0
                    + APPROACH_AIRBORNE_PREROLL_AGL_METRES
                    + APPROACH_FIXTURE_VERTICAL_SPEED_MPS
                    * offset * RECORDER_FRAME_INTERVAL_SECONDS
                ),
                simulator_elapsed=(
                    TAXI_SAMPLE_COUNT
                    + offset * RECORDER_FRAME_INTERVAL_SECONDS
                ),
            ))
        approach_start += APPROACH_AIRBORNE_PREROLL_COUNT
        for index in range(
            approach_start,
            approach_start + LANDING_APPROACH_SAMPLE_COUNT,
        ):
            seconds_before_touch = approach_start + LANDING_APPROACH_SAMPLE_COUNT - index
            agl = float(seconds_before_touch * 0.05 * 4)
            rows.append(make_sample(
                float(index),
                on_ground=False,
                agl_m=agl,
                vertical_speed_mps=APPROACH_FIXTURE_VERTICAL_SPEED_MPS,
                altitude_m=100.0 + agl,
                simulator_elapsed=(
                    TAXI_SAMPLE_COUNT
                    + APPROACH_AIRBORNE_PREROLL_COUNT
                    * RECORDER_FRAME_INTERVAL_SECONDS
                    + (index - approach_start)
                    * RECORDER_FRAME_INTERVAL_SECONDS
                ),
            ))
        touchdown = float(approach_start + LANDING_APPROACH_SAMPLE_COUNT)
        touchdown_simulator_elapsed = (
            APPROACH_AIRBORNE_PREROLL_COUNT + LANDING_APPROACH_SAMPLE_COUNT
        ) * RECORDER_FRAME_INTERVAL_SECONDS + TAXI_SAMPLE_COUNT
        for offset in range(2):
            rows.append(make_sample(
                touchdown + offset,
                on_ground=True,
                agl_m=0.0,
                vertical_speed_mps=-1.0 if offset == 0 else 0.0,
                altitude_m=100.0,
                simulator_elapsed=(
                    touchdown_simulator_elapsed
                    + offset * RECORDER_FRAME_INTERVAL_SECONDS
                ),
            ))
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual(1, len(result.landings))
        self.assertEqual(1, result.landings[0].contact_count)
        self.assertGreater(result.landings[0].descent_rate_fpm or 0.0, 700.0)
        self.assertLess(result.landings[0].descent_rate_fpm or 0.0, 900.0)

    def test_one_ground_flag_frame_is_not_confirmed_touchdown(self) -> None:
        """Reject a single ground flag that is followed by airborne samples.

        Returns:
            None: One low-AGL flag cannot create a landing without confirmation.
        """
        rows = [
            make_sample(
                float(index),
                on_ground=False,
                agl_m=40.0,
                vertical_speed_mps=-1.0,
                altitude_m=500.0,
                simulator_elapsed=float(index),
            )
            for index in range(6)
        ]
        rows.append(make_sample(
            6.0, on_ground=True, agl_m=0.0,
            vertical_speed_mps=-1.0, altitude_m=450.0,
            simulator_elapsed=6.0,
        ))
        rows.extend([
            make_sample(
                float(index),
                on_ground=False,
                agl_m=0.5,
                vertical_speed_mps=-1.0,
                altitude_m=450.0,
                simulator_elapsed=float(index),
            )
            for index in (7, 8, 9)
        ])
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual([], result.landings)

    def test_low_altitude_airborne_flyby_is_not_a_landing(self) -> None:
        """Do not convert low descending AGL into a false touchdown.

        Returns:
            None: Airborne ground-state=false rows produce no contact event.
        """
        rows = [
            make_sample(
                float(index),
                on_ground=False,
                agl_m=max(0.0, 50.0 - index * 5.0),
                vertical_speed_mps=-1.5,
                altitude_m=500.0 - index * 5.0,
                simulator_elapsed=float(index),
            )
            for index in range(11)
        ]
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual([], result.landings)

    def test_rollout_does_not_extend_last_confirmed_contact(self) -> None:
        """Keep landing contact time anchored to touchdown, not taxi parking.

        Returns:
            None: Sustained on-ground frames do not rewrite landing contact time.
        """
        rows = [
            make_sample(
                float(index),
                on_ground=False,
                agl_m=50.0,
                vertical_speed_mps=-1.0,
                altitude_m=500.0,
                simulator_elapsed=float(index),
            )
            for index in range(6)
        ]
        for index in range(6, 20):
            rows.append(make_sample(
                float(index),
                on_ground=True,
                agl_m=0.0,
                vertical_speed_mps=0.0,
                altitude_m=450.0,
                simulator_elapsed=float(index),
            ))
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual(1, len(result.landings))
        self.assertEqual(6.0, result.landings[0].first_contact_elapsed_seconds)
        self.assertEqual(6.0, result.landings[0].last_contact_elapsed_seconds)
        self.assertEqual(1, result.landings[0].contact_count)

    def test_goaround_uses_accumulated_twenty_hz_climb_gain(self) -> None:
        """Detect a sustained climb from its accumulated gain, not per-frame delta.

        Returns:
            None: Replayed approach frames emit one delayed go-around marker.
        """
        rows: list[dict[str, object]] = []
        frame_interval = 0.05
        frame_index = 0

        def add(
            on_ground: bool,
            agl_m: float,
            vertical_speed_mps: float,
            ground_speed_mps: float,
        ) -> None:
            """Append one physically coherent simulator frame.

            Args:
                on_ground (bool): SimConnect ground-state value.
                agl_m (float): Height above ground in metres.
                vertical_speed_mps (float): Vertical speed in metres per second.
                ground_speed_mps (float): Ground speed in metres per second.

            Returns:
                None: Adds one normalized replay row.
            """
            nonlocal frame_index
            elapsed = frame_index * frame_interval
            rows.append(make_sample(
                elapsed,
                on_ground=on_ground,
                agl_m=agl_m,
                vertical_speed_mps=vertical_speed_mps,
                altitude_m=100.0 + agl_m,
                simulator_elapsed=elapsed,
                ground_speed_mps=ground_speed_mps,
            ))
            frame_index += 1

        for _index in range(5):
            add(True, 0.0, 0.0, 0.0)
        for index in range(5):
            add(False, 5.0 + index, 2.0, 60.0)
        for index in range(40):
            add(False, 800.0 - index * 10.0, -2.0, 60.0)
        climb_start_index = frame_index
        for index in range(120):
            add(False, 410.0 + index * 0.1, 2.0, 60.0)
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        goarounds = [event for event in result.events if event.phase == "goaround"]
        self.assertEqual(1, len(goarounds))
        self.assertGreaterEqual(
            goarounds[0].elapsed_seconds,
            climb_start_index * frame_interval
            + PHASE_GOAROUND_SUSTAIN_SECONDS,
        )

    def test_airborne_frame_resets_separated_ground_candidates(self) -> None:
        """Require consecutive contact evidence across intermittent airborne frames.

        Returns:
            None: One grounded frame on either side of a strong-air sample is
                insufficient to establish a touchdown.
        """
        rows = [
            make_sample(
                float(index),
                on_ground=False,
                agl_m=50.0,
                vertical_speed_mps=-1.0,
                altitude_m=500.0,
                simulator_elapsed=float(index),
            )
            for index in range(6)
        ]
        rows.append(make_sample(
            6.0, on_ground=True, agl_m=0.0,
            vertical_speed_mps=-1.0, altitude_m=450.0,
            simulator_elapsed=6.0,
        ))
        rows.append(make_sample(
            7.0, on_ground=False, agl_m=4.0,
            vertical_speed_mps=1.0, altitude_m=454.0,
            simulator_elapsed=7.0,
        ))
        rows.append(make_sample(
            8.0, on_ground=True, agl_m=0.0,
            vertical_speed_mps=0.0, altitude_m=450.0,
            simulator_elapsed=8.0,
        ))
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual([], result.landings)

    def test_ground_flag_chatter_does_not_create_bounce(self) -> None:
        """Reject a high-AGL one-frame ground flag as a second touch.

        Returns:
            None: Asserts conservative event and bounded-chart output.
        """
        rows = []
        for index in range(6):
            rows.append(make_sample(
                float(index),
                on_ground=False,
                agl_m=50.0,
                vertical_speed_mps=-1.0,
                altitude_m=500.0,
                simulator_elapsed=float(index),
            ))
        for index in (6, 7):
            rows.append(make_sample(
                float(index),
                on_ground=True,
                agl_m=0.0,
                vertical_speed_mps=0.0,
                altitude_m=450.0,
                simulator_elapsed=float(index),
            ))
        rows.append(make_sample(
            8.0, on_ground=False, agl_m=50.0,
            vertical_speed_mps=0.0, altitude_m=500.0,
            simulator_elapsed=8.0,
        ))
        for index in (9, 10):
            rows.append(make_sample(
                float(index), on_ground=True, agl_m=50.0,
                vertical_speed_mps=0.0, altitude_m=500.0,
                simulator_elapsed=float(index),
            ))
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual(1, len(result.landings))
        self.assertFalse(result.landings[0].bounce_possible)
        self.assertEqual(1, result.landings[0].contact_count)

    def test_contacts_retain_snapshots_rates_and_ground_rollout(self) -> None:
        """Retain each contact frame and only continuous ground-run metrics.

        Returns:
            None: Contact snapshots, derived rates and rollout remain aligned.
        """
        rows: list[dict[str, object]] = []

        def add(
            elapsed: float,
            on_ground: bool,
            agl_m: float,
            vertical_speed_mps: float,
            *,
            latitude_deg: float | None = None,
            contact_metadata: bool = False,
        ) -> None:
            """Append a frame with deterministic clocks and optional contact data.

            Args:
                elapsed (float): Host and simulator elapsed seconds.
                on_ground (bool): SimConnect ground-state signal.
                agl_m (float): Aircraft height above ground in metres.
                vertical_speed_mps (float): Vertical speed in metres per second.
                latitude_deg (float | None): Optional synthetic latitude.
                contact_metadata (bool): Add optional touchdown telemetry.

            Returns:
                None: Adds one replay sample.
            """
            row = make_sample(
                elapsed,
                on_ground=on_ground,
                agl_m=agl_m,
                vertical_speed_mps=vertical_speed_mps,
                altitude_m=100.0 + agl_m,
                latitude_deg=(
                    latitude_deg if latitude_deg is not None else 49.0
                ),
                simulator_elapsed=elapsed,
                ground_speed_mps=60.0 if not on_ground else 8.0,
            )
            values = row["sample"]
            if not isinstance(values, dict):
                return
            if contact_metadata:
                values.update({
                    "indicated_airspeed_mps": 72.0,
                    "pitch_deg": -1.25,
                    "wind_velocity_mps": 6.5,
                    "wind_direction_deg": 245.0,
                })
            rows.append(row)

        frame_interval = 0.05
        for index in range(20):
            elapsed = index * frame_interval
            add(
                elapsed,
                False,
                10.0 - index * 0.35,
                -7.0,
                latitude_deg=49.0 + index * 0.000001,
            )
        add(
            1.0, True, 0.0, -1.0,
            latitude_deg=49.000020,
            contact_metadata=True,
        )
        add(
            1.05, True, 0.0, 0.0,
            latitude_deg=49.000021,
        )
        for index in range(4):
            elapsed = 1.10 + index * frame_interval
            add(
                elapsed, True, 0.0, 0.0,
                latitude_deg=49.000022 + index * 0.00001,
            )
        for index in range(7):
            elapsed = 1.30 + index * frame_interval
            add(
                elapsed,
                False,
                4.0 + index * 1.0,
                2.0,
                latitude_deg=49.000070 + index * 0.000001,
            )
        descent_start = 1.65
        while descent_start <= 3.95:
            agl = 11.0 - (descent_start - 1.65) * 4.0
            add(
                descent_start,
                False,
                agl,
                -4.0,
                latitude_deg=49.000100 + (descent_start - 1.65) * 0.0001,
            )
            descent_start = round(descent_start + frame_interval, 8)
        add(4.0, True, 0.0, -1.0, latitude_deg=49.000340)
        add(4.05, True, 0.0, 0.0, latitude_deg=49.000341)

        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual(1, len(result.landings))
        landing = result.landings[0]
        self.assertEqual(2, landing.contact_count)
        self.assertEqual(2, len(landing.contact_samples))
        self.assertEqual(2, len(landing.contact_descent_rates_fpm))
        first_contact = landing.contact_samples[0]
        self.assertEqual(1.0, first_contact.session_elapsed_seconds)
        self.assertEqual(72.0, first_contact.indicated_airspeed_mps)
        self.assertEqual(-1.25, first_contact.pitch_deg)
        self.assertEqual(6.5, first_contact.wind_velocity_mps)
        second_contact = landing.contact_samples[1]
        self.assertIsNotNone(first_contact.latitude_deg)
        self.assertIsNotNone(second_contact.latitude_deg)
        self.assertGreater(
            second_contact.latitude_deg or 0.0,
            first_contact.latitude_deg or 0.0,
        )
        self.assertIsNotNone(landing.contact_descent_rates_fpm[0])
        self.assertIsNotNone(landing.contact_descent_rates_fpm[1])
        self.assertGreater(landing.rollout_seconds or 0.0, 0.0)
        self.assertLessEqual(landing.rollout_seconds or 0.0, 0.1)
        self.assertGreater(landing.rollout_distance_nm or 0.0, 0.0)
        self.assertLess(landing.rollout_distance_nm or 0.0, 0.001)
        payload = result.to_payload()
        self.assertEqual(2, len(payload["landings"][0]["contact_samples"]))
        json.dumps(payload)

    def test_short_low_rebound_is_possible_bounce_not_an_extra_landing(self) -> None:
        """Join one evidenced brief rebound to the original contact event.

        Returns:
            None: Asserts conservative event and bounded-chart output.
        """
        rows = []
        for index in range(6):
            rows.append(make_sample(
                float(index), on_ground=False, agl_m=10.0,
                vertical_speed_mps=-1.0, altitude_m=110.0,
                simulator_elapsed=float(index),
            ))
        for index in (6, 7):
            rows.append(make_sample(
                float(index), on_ground=True, agl_m=0.0,
                vertical_speed_mps=-1.0, altitude_m=100.0,
                simulator_elapsed=float(index),
            ))
        for index, height in ((8, 5.0), (9, 9.0)):
            rows.append(make_sample(
                float(index), on_ground=False, agl_m=height,
                vertical_speed_mps=2.0, altitude_m=100.0 + height,
                simulator_elapsed=float(index),
            ))
        for index in (10, 11):
            rows.append(make_sample(
                float(index), on_ground=True, agl_m=0.0,
                vertical_speed_mps=0.0, altitude_m=100.0,
                simulator_elapsed=float(index),
            ))
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual(1, len(result.landings))
        self.assertTrue(result.landings[0].bounce_possible)
        self.assertEqual(2, result.landings[0].contact_count)

    def test_goaround_and_discontinuity_do_not_invent_touchdown(self) -> None:
        """Keep high-altitude go-around frames from satisfying ground evidence.

        Returns:
            None: Asserts conservative event and bounded-chart output.
        """
        rows = [
            make_sample(
                float(index), on_ground=False, agl_m=100.0 + index * 20,
                vertical_speed_mps=8.0, altitude_m=500.0 + index * 20,
                simulator_elapsed=float(index),
                discontinuity="simulator resumed" if index == 3 else None,
            )
            for index in range(20)
        ]
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual([], result.landings)

    def test_phase_timeline_and_completion_require_final_landing(self) -> None:
        """Infer phases and call the flight complete only after final ground hold.

        Returns:
            None: Confirms event order, evidence shape and the completion gate.
        """
        rows: list[dict[str, object]] = []

        def add(
            on_ground: bool,
            agl_m: float,
            vertical_speed_mps: float,
            altitude_m: float,
            ground_speed_mps: float,
        ) -> None:
            """Append one synthetic phase sample.

            Args:
                on_ground (bool): SimConnect ground-state value.
                agl_m (float): Height above ground in metres.
                vertical_speed_mps (float): Vertical speed in SI units.
                altitude_m (float): Altitude in metres.
                ground_speed_mps (float): Ground speed in metres per second.

            Returns:
                None: Adds one chronological telemetry row.
            """
            elapsed = float(len(rows))
            rows.append(make_sample(
                elapsed,
                on_ground=on_ground,
                agl_m=agl_m,
                vertical_speed_mps=vertical_speed_mps,
                altitude_m=altitude_m,
                simulator_elapsed=elapsed,
                ground_speed_mps=ground_speed_mps,
            ))

        for _index in range(5):
            add(True, 0.0, 0.0, 100.0, 0.0)
        for _index in range(5):
            add(True, 0.0, 0.0, 100.0, 8.0)
        for index in range(5):
            add(False, 10.0 + index, 3.0, 110.0 + index, 60.0)
        for _index in range(12):
            add(False, 2000.0, 0.0, 2100.0, 60.0)
        for index in range(5):
            add(False, 1500.0 - index * 100.0, -3.0, 1600.0 - index * 100.0, 60.0)
        for index in range(3):
            add(False, 500.0 - index * 100.0, -3.0, 600.0 - index * 100.0, 60.0)
        for index in range(3):
            add(False, 15.0 - index * 3.0, -2.0, 115.0 - index * 3.0, 60.0)
        for _index in range(6):
            add(True, 0.0, 0.0, 100.0, 0.0)

        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows, recording_ended=True)
            result = analyze_session(directory)
        phases = [event.phase for event in result.events]
        for phase in (
            "preflight", "taxiout", "takeoff", "climb", "cruise",
            "descent", "approach", "flare", "touchdown", "rollout",
            "taxiin", "ended",
        ):
            self.assertIn(phase, phases)
        self.assertTrue(result.completed)
        self.assertEqual(1, len(result.landings))
        self.assertTrue(all(event.evidence for event in result.events))
        self.assertEqual(
            phases,
            [event["phase"] for event in result.to_payload()["events"]],
        )

    def test_midflight_stop_is_partial_and_touch_and_go_is_explicit(self) -> None:
        """Keep a stopped airborne session partial and label a later liftoff.

        Returns:
            None: Checks completion and touch-and-go event evidence.
        """
        rows: list[dict[str, object]] = []
        for index in range(5):
            rows.append(make_sample(
                float(index), on_ground=False, agl_m=10.0 + index,
                vertical_speed_mps=3.0, altitude_m=110.0 + index,
                simulator_elapsed=float(index),
            ))
        for index in (5, 6, 7):
            rows.append(make_sample(
                float(index), on_ground=True, agl_m=0.0,
                vertical_speed_mps=0.0, altitude_m=100.0,
                simulator_elapsed=float(index),
            ))
        for index in range(8, 15):
            height = float(index - 7)
            rows.append(make_sample(
                float(index), on_ground=False, agl_m=height,
                vertical_speed_mps=2.0, altitude_m=100.0 + height,
                simulator_elapsed=float(index),
            ))
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows, recording_ended=True)
            result = analyze_session(directory)
        self.assertFalse(result.completed)
        self.assertIn("touchandgo", [event.phase for event in result.events])
        self.assertEqual("ended", result.events[-1].phase)

    def test_landing_rollout_does_not_generate_a_rejected_takeoff(self) -> None:
        """Keep observed post-contact deceleration in the landing lifecycle.

        Returns:
            None: A confirmed landing followed by high-speed rollout and taxi
                produces touchdown/rollout without a false rejected-takeoff event.
        """
        # Fixed-altitude airborne and ground rows explicitly use zero V/S;
        # this vector isolates lifecycle classification from descent-rate fitting.
        rows = [make_sample(
            float(index), on_ground=False, agl_m=50.0, altitude_m=150.0,
            vertical_speed_mps=0.0,
            ground_speed_mps=60.0, simulator_elapsed=float(index),
        ) for index in range(4)]
        rows.extend(make_sample(
            float(index), on_ground=True, agl_m=0.0, altitude_m=100.0,
            vertical_speed_mps=0.0,
            ground_speed_mps=60.0 if index < 9 else 5.0,
            simulator_elapsed=float(index),
        ) for index in range(4, 18))
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows, recording_ended=True)
            result = analyze_session(directory)
        self.assertEqual(1, len(result.landings))
        self.assertNotIn("rejectedtakeoff", [event.phase for event in result.events])

    def test_rejected_takeoff_and_goaround_are_evidence_gated(self) -> None:
        """Label high-speed aborted ground roll and corroborated go-around.

        Returns:
            None: Confirms distinct exceptional phase markers.
        """
        rejected_rows = [
            make_sample(
                float(index), on_ground=True, agl_m=0.0,
                vertical_speed_mps=0.0, altitude_m=100.0,
                simulator_elapsed=float(index), ground_speed_mps=40.0,
            )
            for index in range(5)
        ]
        rejected_rows.extend(
            make_sample(
                float(index), on_ground=True, agl_m=0.0,
                vertical_speed_mps=0.0, altitude_m=100.0,
                simulator_elapsed=float(index), ground_speed_mps=0.0,
            )
            for index in range(5, 11)
        )
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rejected_rows, recording_ended=True)
            rejected = analyze_session(directory)
        self.assertFalse(rejected.completed)
        self.assertIn("rejectedtakeoff", [event.phase for event in rejected.events])

        goaround_rows = [
            make_sample(
                float(index), on_ground=False, agl_m=500.0 - index,
                vertical_speed_mps=-3.0, altitude_m=600.0 - index,
                simulator_elapsed=float(index),
            )
            for index in range(5)
        ]
        goaround_rows.extend(
            make_sample(
                float(index), on_ground=False,
                agl_m=500.0 + (index - 5) * 10.0,
                vertical_speed_mps=3.0, altitude_m=600.0 + (index - 5) * 10.0,
                simulator_elapsed=float(index),
            )
            for index in range(5, 12)
        )
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, goaround_rows, recording_ended=True)
            goaround = analyze_session(directory)
        self.assertFalse(goaround.completed)
        self.assertIn("goaround", [event.phase for event in goaround.events])

    def test_live_activity_emits_only_stable_changes(self) -> None:
        """Emit concise activity updates after stable recorded telemetry.

        Returns:
            None: Confirms repeated samples produce one phase-change notice.
        """
        emitted: list[tuple[str, str]] = []
        with tempfile.TemporaryDirectory() as temporary:
            recorder = FlightRecorder(
                Path(temporary),
                "test-gallery",
                "",
                lambda level, message: emitted.append((level, message)),
            )
            for index in range(5):
                recorder._emit_phase_change(FlightSample(
                    session_elapsed_seconds=float(index),
                    recorded_utc="2026-01-01T00:00:00+00:00",
                    ground_speed_mps=0.0,
                    on_ground=True,
                ))
        self.assertEqual(1, len(emitted))
        self.assertIn("Flight activity: ground.", emitted[0][1])

    def test_plot_reduction_carries_omitted_discontinuities_forward(self) -> None:
        """Keep a break visible when both bounded reducers omit its source row.

        Returns:
            None: A chart consumer can still separate discontinuous segments.
        """
        rows = [
            make_sample(
                float(index),
                on_ground=False,
                agl_m=100.0,
                vertical_speed_mps=0.0,
                altitude_m=1000.0,
                simulator_elapsed=float(index),
                discontinuity=(
                    "simulator resumed" if index == 3 else None
                ),
            )
            for index in range(ANALYSIS_SAMPLE_WINDOW + 2)
        ]
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        markers = [
            sample.discontinuity_before for sample in result.samples
            if sample.discontinuity_before
        ]
        self.assertIn("simulator resumed", markers)
        self.assertLessEqual(len(result.samples), 2000)

    def test_new_landing_resets_prior_taxi_rollout_confirmation(self) -> None:
        """Start a fresh rollout after taxi and a separately confirmed flight.

        Returns:
            None: Prior taxi confirmation does not suppress the second rollout.
        """
        rows: list[dict[str, object]] = []
        frame_interval = 0.05
        frame_index = 0

        def add(
            on_ground: bool,
            agl_m: float,
            vertical_speed_mps: float,
            ground_speed_mps: float,
        ) -> None:
            """Append one consistent synthetic recorder frame.

            Args:
                on_ground (bool): SimConnect ground-state value.
                agl_m (float): Height above ground in metres.
                vertical_speed_mps (float): Vertical speed in metres per second.
                ground_speed_mps (float): Groundspeed in metres per second.

            Returns:
                None: Adds the current frame to the replay.
            """
            nonlocal frame_index
            elapsed = frame_index * frame_interval
            rows.append(make_sample(
                elapsed,
                on_ground=on_ground,
                agl_m=agl_m,
                vertical_speed_mps=vertical_speed_mps,
                altitude_m=100.0 + agl_m,
                latitude_deg=49.0 + frame_index * 0.000001,
                simulator_elapsed=elapsed,
                ground_speed_mps=ground_speed_mps,
            ))
            frame_index += 1

        for index in range(20):
            add(False, 10.0 - index * 0.35, -7.0, 60.0)
        add(True, 0.0, -1.0, 5.0)
        add(True, 0.0, 0.0, 5.0)
        for _index in range(70):
            add(True, 0.0, 0.0, 5.0)
        for index in range(8):
            add(False, 4.0 + index * 4.0, 2.0, 60.0)
        for index in range(78):
            add(False, 32.0 - index * 0.4, -8.0, 60.0)
        add(True, 0.0, -1.0, 5.0)
        add(True, 0.0, 0.0, 5.0)
        for _index in range(10):
            add(True, 0.0, 0.0, 5.0)

        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual(2, len(result.landings))
        self.assertGreater(result.landings[0].rollout_seconds or 0.0, 2.5)
        self.assertGreater(result.landings[1].rollout_seconds or 0.0, 0.3)

    def test_rollout_stops_after_sustained_taxi_and_uses_simulator_time(self) -> None:
        """Measure only continuous ground run before a taxi/parking segment.

        Returns:
            None: Parking duration and distance do not extend rollout metrics.
        """
        rows: list[dict[str, object]] = []
        frame_interval = 0.05
        frame_index = 0

        def add(on_ground: bool, ground_speed: float) -> None:
            """Append a frame with shared host and simulator clocks.

            Args:
                on_ground (bool): SimConnect ground-state signal.
                ground_speed (float): Aircraft groundspeed in metres per second.

            Returns:
                None: Adds one replay sample.
            """
            nonlocal frame_index
            elapsed = frame_index * frame_interval
            rows.append(make_sample(
                elapsed,
                on_ground=on_ground,
                agl_m=0.0 if on_ground else 20.0,
                vertical_speed_mps=0.0,
                altitude_m=100.0 if on_ground else 120.0,
                latitude_deg=49.0 + frame_index * 0.000001,
                simulator_elapsed=elapsed,
                ground_speed_mps=ground_speed,
            ))
            frame_index += 1

        for _index in range(6):
            add(False, 60.0)
        for _index in range(2):
            add(True, 5.0)
        for _index in range(140):
            add(True, 5.0)
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual(1, len(result.landings))
        landing = result.landings[0]
        self.assertGreater(landing.rollout_seconds or 0.0, 2.5)
        self.assertLessEqual(
            landing.rollout_seconds or 0.0,
            ROLLOUT_TAXI_CONFIRM_SECONDS + 0.2,
        )
        self.assertLess(landing.rollout_distance_nm or 0.0, 0.005)

    def test_analysis_payload_retains_server_version_provenance(self) -> None:
        """Expose recorded OPEN build identity separately from simulator label.

        Returns:
            None: Payload carries its schema and API/server version fields.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(
                directory,
                [],
                simulator_build="11.0.282174.999 / SimConnect 11.0.62651.3",
            )
            result = analyze_session(directory)
        payload = result.to_payload()
        self.assertEqual(ANALYSIS_VERSION, payload["analysis_version"])
        self.assertEqual(
            "11.0.282174.999 / SimConnect 11.0.62651.3",
            payload["simulator_build"],
        )

    def test_long_haul_retains_final_touchdown_after_plot_reduction(self) -> None:
        """Analyze all landing events before reducing a long plot to its point cap.

        Returns:
            None: Asserts conservative event and bounded-chart output.
        """
        rows = [
            make_sample(
                float(index * TEST_SAMPLE_INTERVAL_SECONDS),
                on_ground=False,
                agl_m=100.0,
                vertical_speed_mps=0.0,
                altitude_m=1000.0,
                latitude_deg=49.0 + index * 0.000001,
                simulator_elapsed=float(index),
            )
            for index in range(LONG_HAUL_SAMPLE_COUNT)
        ]
        touch_time = float(LONG_HAUL_SAMPLE_COUNT)
        for index in (LONG_HAUL_SAMPLE_COUNT, LONG_HAUL_SAMPLE_COUNT + 1):
            rows.append(make_sample(
                float(index), on_ground=True, agl_m=0.0,
                vertical_speed_mps=-1.0, altitude_m=1000.0,
                latitude_deg=49.1, simulator_elapsed=float(index),
            ))
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            write_session(directory, rows)
            result = analyze_session(directory)
        self.assertEqual(1, len(result.landings))
        self.assertEqual(touch_time, result.landings[0].first_contact_elapsed_seconds)
        self.assertLessEqual(len(result.samples), 2000)
        self.assertEqual(float(LONG_HAUL_SAMPLE_COUNT + 1), result.samples[-1].session_elapsed_seconds)


if __name__ == "__main__":
    unittest.main()
