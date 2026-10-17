# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_data.py
# Module Type: Flight Analysis Data
# Purpose: Define durable telemetry rows and conservative landing analysis.
# Responsibilities:
#   - Describe normalized samples and evidence-backed landing events.
#   - Stream all records through bounded event state before plot reduction.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Normalized telemetry models and bounded offline flight analysis."""
import json
import math
from collections import deque
from dataclasses import asdict, dataclass, field, replace
from pathlib import Path
from typing import Any, Iterator

# Identify the JSON analysis schema used by saved flight reports.
# Type: str. Units: schema version. Scope: one analysis payload.
# Consumers: FlightAnalysis.to_payload and report rendering.
# Rationale: downstream consumers can distinguish compatible payload shapes.
ANALYSIS_VERSION = "1"

# Bound displayed trajectory points.
# Type: int. Units: points. Scope: one analysis payload.
# Consumers: analyze_session. Rationale: keep charts responsive.
CHART_SAMPLE_LIMIT = 2000
# Bound the plot working set while preserving event tracking over all rows.
# Type: int. Units: samples. Scope: one analysis invocation.
# Consumers: analyze_session. Rationale: cap memory on long-haul recordings.
ANALYSIS_SAMPLE_WINDOW = 8000
# Retain recent full-resolution approach samples for descent fit.
# Type: int. Units: samples. Scope: landing detector.
# Consumers: _LandingTracker. Rationale: hold dense pre-contact evidence.
LANDING_CONTEXT_SAMPLES = 120
# Bound warning records from malformed or implausible input.
# Type: int. Units: messages. Scope: analysis payload.
# Consumers: analyze_session. Rationale: corrupt input cannot grow memory.
ANALYSIS_WARNING_LIMIT = 100
# Bound reported contacts for pathological sessions.
# Type: int. Units: events. Scope: one analysis payload.
# Consumers: _LandingTracker. Rationale: cap contact-event memory.
LANDING_EVENT_LIMIT = 1000
# Bound the timeline of inferred phase changes.
# Type: int. Units: events. Scope: one analysis payload.
# Consumers: _FlightPhaseTracker. Rationale: malformed sessions cannot grow it.
PHASE_EVENT_LIMIT = 200
# Frames needed to admit an airborne transition for phase evidence.
# Type: int. Units: samples. Scope: takeoff detection.
# Consumers: _FlightPhaseTracker.push. Rationale: reject isolated false flags.
PHASE_AIRBORNE_CONFIRM_SAMPLES = 3
# Frames needed to distinguish taxi motion from parked preflight.
# Type: int. Units: samples. Scope: ground phase detection.
# Consumers: _FlightPhaseTracker.push. Rationale: reject position chatter.
PHASE_GROUND_CONFIRM_SAMPLES = 5
# Ground speed that indicates meaningful taxi movement.
# Type: float. Units: metres per second. Scope: phase detection.
# Consumers: _FlightPhaseTracker.push. Rationale: separate motion from drift.
PHASE_TAXI_SPEED_MPS = 5.0
# Ground speed used to flag a possible rejected takeoff.
# Type: float. Units: metres per second. Scope: takeoff roll evidence.
# Consumers: _FlightPhaseTracker.push. Rationale: avoid labeling normal taxi.
PHASE_TAKEOFF_ROLL_SPEED_MPS = 35.0
# AGL boundary for approach phase classification.
# Type: float. Units: metres. Scope: descent phase detection.
# Consumers: _FlightPhaseTracker.push. Rationale: use broad conservative gate.
PHASE_APPROACH_AGL_METRES = 1000.0
# AGL boundary for flare phase classification.
# Type: float. Units: metres. Scope: final approach detection.
# Consumers: _FlightPhaseTracker.push. Rationale: avoid runway-contact claims.
PHASE_FLARE_AGL_METRES = 20.0
# Altitude gain that corroborates a go-around climb.
# Type: float. Units: metres. Scope: go-around detection.
# Consumers: _FlightPhaseTracker.push. Rationale: exclude vertical-speed chatter.
PHASE_GOAROUND_GAIN_METRES = 5.0
# Minimum sustained climb evidence before labeling a go-around.
# Type: float. Units: seconds. Scope: one climb candidate.
# Consumers: _FlightPhaseTracker.push. Rationale: filter isolated frame spikes.
PHASE_GOAROUND_SUSTAIN_SECONDS = 0.75
# Stable ground frames needed to call a recorded session landed.
# Type: int. Units: samples. Scope: completed-flight qualification.
# Consumers: analyze_session. Rationale: require final sustained ground evidence.
FINAL_GROUND_CONFIRM_SAMPLES = 5
# Stable climb frames used to classify cruise after climb.
# Type: int. Units: samples. Scope: cruise phase detection.
# Consumers: _FlightPhaseTracker.push. Rationale: reject transient level flight.
PHASE_CRUISE_CONFIRM_SAMPLES = 10
# Vertical speed boundary for climb evidence.
# Type: float. Units: metres per second. Scope: phase classification.
# Consumers: _FlightPhaseTracker.push. Rationale: ignore small sensor noise.
PHASE_CLIMB_MINIMUM_MPS = 1.0
# Vertical speed boundary for descent evidence.
# Type: float. Units: metres per second. Scope: phase classification.
# Consumers: _FlightPhaseTracker.push. Rationale: ignore small sensor noise.
PHASE_DESCENT_MAXIMUM_MPS = -1.5
# Maximum vertical speed magnitude for near-level cruise evidence.
# Type: float. Units: metres per second. Scope: phase classification.
# Consumers: _FlightPhaseTracker.push. Rationale: require near-level motion.
PHASE_LEVEL_LIMIT_MPS = 0.5
# Maximum retained telemetry snapshots per landing or possible bounce.
# Type: int. Units: contact snapshots. Scope: one landing event.
# Consumers: _LandingTracker._append_contact_measurement. Rationale: bound PDF payloads.
LANDING_CONTACT_SAMPLE_LIMIT = 100
# Largest gap bridged while accumulating a confirmed-ground rollout segment.
# Type: float. Units: seconds. Scope: rollout distance and elapsed time.
# Consumers: _LandingTracker._advance_rollout. Rationale: avoid bridging pauses.
MAX_ROLLOUT_SAMPLE_GAP_SECONDS = 0.25
# Maximum plausible GPS distance for one adjacent rollout segment.
# Type: float. Units: nautical miles. Scope: rollout distance accumulation.
# Consumers: _LandingTracker._advance_rollout. Rationale: reject position jumps.
MAX_ROLLOUT_SEGMENT_NM = 50.0
# Speed threshold below which sustained ground motion indicates taxiing.
# Type: float. Units: metres per second. Scope: rollout endpoint detection.
# Consumers: _LandingTracker._advance_rollout. Rationale: omit prolonged taxi.
ROLLOUT_TAXI_SPEED_LIMIT_MPS = 10.0
# Slow ground motion duration that ends observed rollout accumulation.
# Type: float. Units: seconds. Scope: rollout endpoint detection.
# Consumers: _LandingTracker._advance_rollout. Rationale: tolerate short deceleration.
ROLLOUT_TAXI_CONFIRM_SECONDS = 3.0
# Required consecutive on-ground frames for ordinary contact confirmation.
# Type: int. Units: samples. Scope: one touchdown candidate.
# Consumers: _LandingTracker.push. Rationale: reject one-frame ground chatter.
GROUND_CONFIRM_SAMPLES = 2
# Maximum short airtime joined to a contact as a possible rebound.
# Type: float. Units: seconds. Scope: one landing event.
# Consumers: _LandingTracker.push. Rationale: separate bounce from go-around.
BOUNCE_GAP_LIMIT_SECONDS = 8.0
# Height above which a rebound is treated as a climb/go-around.
# Type: float. Units: metres. Scope: landing event grouping.
# Consumers: _LandingTracker.push. Rationale: avoid joining a later flight.
BOUNCE_HEIGHT_LIMIT_METRES = 20.0
# Minimum simulator elapsed span accepted for local touchdown regression.
# Type: float. Units: seconds. Scope: pre-contact fit.
# Consumers: _descent_rate_fpm. Rationale: require measurable local evidence.
MIN_DESCENT_FIT_SECONDS = 0.3
# Limit regression to the final second before first confirmed contact.
# Type: float. Units: seconds. Scope: pre-contact fit.
# Consumers: _descent_rate_fpm. Rationale: capture flare-aware touchdown trend.
MAX_DESCENT_FIT_SECONDS = 1.0
# Largest acceptable simulator-time gap inside the local fit.
# Type: float. Units: seconds. Scope: regression observations.
# Consumers: _descent_rate_fpm. Rationale: reject missing frame sequences.
MAX_DESCENT_SAMPLE_GAP_SECONDS = 0.25
# Confirm airborne state across multiple independent frames.
# Type: int. Units: samples. Scope: flight admission.
# Consumers: _LandingTracker.push. Rationale: reject load/menu transients.
AIRBORNE_CONFIRM_SAMPLES = 3
# Require altitude separation with user-aircraft ground motion.
# Type: float. Units: metres. Scope: flight admission.
# Consumers: _LandingTracker._strong_airborne. Rationale: reject menu state.
AIRBORNE_AGL_MINIMUM_METRES = 3.0
# Require meaningful aircraft groundspeed alongside AGL evidence.
# Type: float. Units: metres per second. Scope: flight admission.
# Consumers: _LandingTracker._strong_airborne. Rationale: reject static loads.
AIRBORNE_GROUND_SPEED_MINIMUM_MPS = 20.0
EARTH_RADIUS_NM = 3440.065
FEET_PER_MINUTE_PER_METRES_PER_SECOND = 196.8503937008


@dataclass
class FlightSample:
    """Describe one read-only observation and its explicit unknown values.

    Positions use degrees, altitude/AGL use metres, motion uses metres per
    second, and attitude uses degrees. Gear, flap and brake positions use
    SimConnect percentages from 0 to 100; fuel uses US gallons, mass kilograms,
    engines RPM and load factor g. Host monotonic receipt seconds and host UTC
    remain distinct from normalized simulator elapsed seconds and rate.
    """

    session_elapsed_seconds: float
    recorded_utc: str
    simulator_elapsed_seconds: float | None = None
    simulator_rate: float | None = None
    latitude_deg: float | None = None
    longitude_deg: float | None = None
    altitude_m: float | None = None
    agl_m: float | None = None
    vertical_speed_mps: float | None = None
    ground_speed_mps: float | None = None
    true_airspeed_mps: float | None = None
    indicated_airspeed_mps: float | None = None
    load_factor_g: float | None = None
    pitch_deg: float | None = None
    bank_deg: float | None = None
    heading_deg: float | None = None
    on_ground: bool | None = None
    lights: dict[str, bool | None] = field(default_factory=dict)
    gear_position: float | None = None
    flaps_position: float | None = None
    engine_rpm: tuple[float | None, ...] = ()
    fuel_gallons: float | None = None
    wind_velocity_mps: float | None = None
    wind_direction_deg: float | None = None
    total_weight_kg: float | None = None
    left_brake_position: float | None = None
    right_brake_position: float | None = None
    autopilot_master: bool | None = None
    discontinuity_before: str | None = None

    def to_dict(self) -> dict[str, Any]:
        """Return JSON-compatible sample fields.

        Returns:
            dict[str, Any]: Fields with engine_rpm represented as a list.
        """
        payload = asdict(self)
        payload["engine_rpm"] = list(self.engine_rpm)
        return payload


@dataclass
class FlightPhaseEvent:
    """Phase marker with relative time, confidence and evidence statements."""

    phase: str
    elapsed_seconds: float
    confidence: str
    evidence: list[str]


@dataclass
class Landing:
    """Retain confirmed contacts and conservative aircraft-reference estimates.

    Contact snapshots retain original SI telemetry before plot reduction.
    Positive descent rates use feet per minute. Observed rollout begins at the
    final confirmed contact and ends at sustained taxi speed, a gap, or the last
    recorded ground frame; seconds use valid simulator time and distance uses
    nautical miles. These measurements do not identify a gear impact point.
    """

    index: int
    first_contact_elapsed_seconds: float
    last_contact_elapsed_seconds: float
    latitude_deg: float | None
    longitude_deg: float | None
    altitude_m: float | None
    descent_rate_fpm: float | None
    contact_count: int
    bounce_possible: bool
    evidence: list[str]
    distance_from_previous_nm: float | None
    confidence: str
    contact_samples: list[FlightSample] = field(default_factory=list)
    contact_descent_rates_fpm: list[float | None] = field(default_factory=list)
    rollout_seconds: float | None = None
    rollout_distance_nm: float | None = None


@dataclass
class FlightAnalysis:
    """Flight summary with bounded plot points and retained contact events."""

    session_id: str
    simulator: str
    aircraft: str | None
    completed: bool
    duration_seconds: float
    distance_nm: float | None
    samples: list[FlightSample]
    landings: list[Landing]
    warnings: list[str]
    events: list[FlightPhaseEvent] = field(default_factory=list)
    simulator_build: str | None = None

    def to_payload(self) -> dict[str, Any]:
        """Return the complete JSON-compatible analysis payload.

        Returns:
            dict[str, Any]: Mapping with session_id, simulator, aircraft,
                completed, duration_seconds, distance_nm, sample dictionaries,
                landing dictionaries, warnings and phase event dictionaries.
                Each phase event has phase, elapsed_seconds, confidence and
                evidence fields. Contact samples preserve full telemetry;
                descent rates and rollout measurements may be null.
        """
        return {
            "analysis_version": ANALYSIS_VERSION,
            "session_id": self.session_id,
            "simulator": self.simulator,
            "simulator_build": self.simulator_build,
            "aircraft": self.aircraft,
            "completed": self.completed,
            "duration_seconds": self.duration_seconds,
            "distance_nm": self.distance_nm,
            "samples": [sample.to_dict() for sample in self.samples],
            "landings": [
                {
                    **asdict(landing),
                    "contact_samples": [
                        sample.to_dict()
                        for sample in landing.contact_samples
                    ],
                }
                for landing in self.landings
            ],
            "warnings": list(self.warnings),
            "events": [asdict(event) for event in self.events],
        }


def _number(value: Any) -> float | None:
    """Return finite numeric values as floats.

    Args:
        value (Any): Candidate JSON value.

    Returns:
        float | None: Finite float or None for invalid/missing values.
    """
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return None
    result = float(value)
    return result if math.isfinite(result) else None


def _rows(directory: Path) -> Iterator[dict[str, Any] | None]:
    """Yield sample mappings and corruption markers from the JSONL stream.

    Args:
        directory (Path): Session directory containing samples.jsonl.

    Yields:
        dict[str, Any] | None: Sample row or None for malformed JSON records.
    """
    try:
        stream = (directory / "samples.jsonl").open("r", encoding="utf-8")
    except OSError:
        return
    with stream:
        for line in stream:
            try:
                envelope = json.loads(line)
            except (json.JSONDecodeError, UnicodeDecodeError):
                yield None
                continue
            if isinstance(envelope, dict) and envelope.get("type") == "sample":
                row = envelope.get("sample")
                yield row if isinstance(row, dict) else None


def _sample(row: dict[str, Any]) -> FlightSample | None:
    """Convert one persisted mapping into the typed telemetry model.

    Args:
        row (dict[str, Any]): Decoded JSON sample row.

    Returns:
        FlightSample | None: Valid sample or None when required fields are absent.
    """
    elapsed = _number(row.get("session_elapsed_seconds"))
    recorded = row.get("recorded_utc")
    if elapsed is None or not isinstance(recorded, str):
        return None
    raw_lights = row.get("lights")
    lights = {
        str(key): value if isinstance(value, bool) else None
        for key, value in raw_lights.items()
    } if isinstance(raw_lights, dict) else {}
    engines = row.get("engine_rpm")
    return FlightSample(
        session_elapsed_seconds=elapsed,
        recorded_utc=recorded,
        simulator_elapsed_seconds=_number(row.get("simulator_elapsed_seconds")),
        simulator_rate=_number(row.get("simulator_rate")),
        latitude_deg=_number(row.get("latitude_deg")),
        longitude_deg=_number(row.get("longitude_deg")),
        altitude_m=_number(row.get("altitude_m")),
        agl_m=_number(row.get("agl_m")),
        vertical_speed_mps=_number(row.get("vertical_speed_mps")),
        ground_speed_mps=_number(row.get("ground_speed_mps")),
        true_airspeed_mps=_number(row.get("true_airspeed_mps")),
        indicated_airspeed_mps=_number(row.get("indicated_airspeed_mps")),
        load_factor_g=_number(row.get("load_factor_g")),
        pitch_deg=_number(row.get("pitch_deg")),
        bank_deg=_number(row.get("bank_deg")),
        heading_deg=_number(row.get("heading_deg")),
        on_ground=row.get("on_ground") if isinstance(row.get("on_ground"), bool) else None,
        lights=lights,
        gear_position=_number(row.get("gear_position")),
        flaps_position=_number(row.get("flaps_position")),
        engine_rpm=tuple(_number(value) for value in engines) if isinstance(engines, list) else (),
        fuel_gallons=_number(row.get("fuel_gallons")),
        wind_velocity_mps=_number(row.get("wind_velocity_mps")),
        wind_direction_deg=_number(row.get("wind_direction_deg")),
        total_weight_kg=_number(row.get("total_weight_kg")),
        left_brake_position=_number(row.get("left_brake_position")),
        right_brake_position=_number(row.get("right_brake_position")),
        autopilot_master=(
            row.get("autopilot_master")
            if isinstance(row.get("autopilot_master"), bool) else None
        ),
        discontinuity_before=row.get("discontinuity_before") if isinstance(row.get("discontinuity_before"), str) else None,
    )


def _distance_nm(first: FlightSample, second: FlightSample) -> float | None:
    """Calculate a great-circle segment when both positions are available.

    Args:
        first (FlightSample): Earlier position.
        second (FlightSample): Later position.

    Returns:
        float | None: Nautical-mile distance or None for unknown coordinates.
    """
    coordinates = (
        first.latitude_deg, first.longitude_deg,
        second.latitude_deg, second.longitude_deg,
    )
    if any(value is None for value in coordinates):
        return None
    lat1, lon1, lat2, lon2 = (
        math.radians(value) for value in coordinates if value is not None
    )
    half_chord = (
        math.sin((lat2 - lat1) / 2) ** 2
        + math.cos(lat1) * math.cos(lat2)
        * math.sin((lon2 - lon1) / 2) ** 2
    )
    return 2 * EARTH_RADIUS_NM * math.asin(
        min(1.0, math.sqrt(half_chord))
    )


def _descent_rate_fpm(
    samples: list[FlightSample],
    contact_sim_time: float | None,
) -> float | None:
    """Fit altitude against valid simulator elapsed time before touchdown.

    Args:
        samples (list[FlightSample]): Recent full-resolution context.
        contact_sim_time (float | None): Simulator elapsed time at first contact.

    Returns:
        float | None: Positive descent estimate, or None if evidence is weak.
    """
    if contact_sim_time is None:
        return None
    points: list[tuple[float, float]] = []
    for sample in reversed(samples):
        if sample.discontinuity_before:
            break
        sim_time = sample.simulator_elapsed_seconds
        if sample.on_ground is True and (
            sim_time is None or sim_time < contact_sim_time
        ):
            break
        if sim_time is None or sim_time >= contact_sim_time:
            continue
        delta = contact_sim_time - sim_time
        if delta > MAX_DESCENT_FIT_SECONDS:
            break
        if sample.altitude_m is not None:
            points.append((sim_time, sample.altitude_m))
    points.reverse()
    if len(points) < 4:
        return None
    times = [point[0] for point in points]
    if times[-1] - times[0] < MIN_DESCENT_FIT_SECONDS:
        return None
    if any(
        right - left > MAX_DESCENT_SAMPLE_GAP_SECONDS
        for left, right in zip(times, times[1:])
    ):
        return None
    mean_time = sum(times) / len(times)
    mean_altitude = sum(point[1] for point in points) / len(points)
    denominator = sum((value - mean_time) ** 2 for value in times)
    if denominator <= 0:
        return None
    slope = sum(
        (x - mean_time) * (y - mean_altitude)
        for x, y in points
    ) / denominator
    if slope >= 0:
        return None
    return -slope * FEET_PER_MINUTE_PER_METRES_PER_SECOND


class _LandingTracker:
    """Track airborne transitions, confirmed contacts and short rebounds."""

    def __init__(self) -> None:
        """Initialize empty full-resolution event state.

        Returns:
            None: Tracker is ready to accept chronological samples.
        """
        self.airborne_seen = False
        self.airborne_count = 0
        self.current: Landing | None = None
        self.candidate: FlightSample | None = None
        self.candidate_count = 0
        self.air_gap_started: float | None = None
        self.context: deque[FlightSample] = deque(maxlen=LANDING_CONTEXT_SAMPLES)
        self.landings: list[Landing] = []
        self.previous_landing: FlightSample | None = None
        self.rollout_previous_sample: FlightSample | None = None
        self.rollout_active = False
        self.rollout_taxi_seconds = 0.0
        self.contacts_truncated = False
        self.truncated = False

    def _strong_airborne(self, sample: FlightSample) -> bool:
        """Require altitude separation or climb alongside the airborne flag.

        Args:
            sample (FlightSample): Current observation.

        Returns:
            bool: True when independent telemetry supports flight.
        """
        return (
            sample.on_ground is False
            and sample.agl_m is not None
            and sample.agl_m > AIRBORNE_AGL_MINIMUM_METRES
            and sample.ground_speed_mps is not None
            and sample.ground_speed_mps > AIRBORNE_GROUND_SPEED_MINIMUM_MPS
        )

    def _contact_signal(self, sample: FlightSample) -> bool:
        """Require prior airborne evidence and a real ground-state SimVar.

        Args:
            sample (FlightSample): Current observation.

        Returns:
            bool: True only for a near-ground true on-ground flag.
        """
        if not self.airborne_seen:
            return False
        return (
            sample.on_ground is True
            and (
                sample.agl_m is None
                or sample.agl_m <= BOUNCE_HEIGHT_LIMIT_METRES
            )
        )

    def _record_landing(self, first: FlightSample) -> None:
        """Append one confirmed event and estimate its approach descent.

        Args:
            first (FlightSample): First sample in the confirmed contact.

        Returns:
            None: Adds an event unless the configured event cap was reached.
        """
        if len(self.landings) >= LANDING_EVENT_LIMIT:
            self.truncated = True
            return
        self.landings.append(Landing(
            index=len(self.landings) + 1,
            first_contact_elapsed_seconds=first.session_elapsed_seconds,
            last_contact_elapsed_seconds=first.session_elapsed_seconds,
            latitude_deg=first.latitude_deg,
            longitude_deg=first.longitude_deg,
            altitude_m=first.altitude_m,
            descent_rate_fpm=_descent_rate_fpm(
                list(self.context), first.simulator_elapsed_seconds
            ),
            contact_count=1,
            bounce_possible=False,
            evidence=["airborne before sustained near-ground on-ground flag"],
            distance_from_previous_nm=(
                _distance_nm(self.previous_landing, first)
                if self.previous_landing is not None else None
            ),
            confidence=(
                "moderate"
                if self.candidate_count >= GROUND_CONFIRM_SAMPLES
                else "low"
            ),
        ))
        landing = self.landings[-1]
        self._append_contact_measurement(landing, first)
        landing.rollout_seconds = (
            0.0 if first.simulator_elapsed_seconds is not None else None
        )
        landing.rollout_distance_nm = 0.0
        self.current = landing
        self.previous_landing = first
        self.rollout_previous_sample = first
        self.rollout_active = True
        self.rollout_taxi_seconds = 0.0

    def _append_contact_measurement(
        self,
        landing: Landing,
        sample: FlightSample,
    ) -> None:
        """Retain one bounded, full-resolution confirmed-contact snapshot.

        Args:
            landing (Landing): Event receiving the contact evidence.
            sample (FlightSample): First frame in the confirmed contact.

        Returns:
            None: Stores telemetry and its local pre-contact descent estimate.
        """
        if len(landing.contact_samples) >= LANDING_CONTACT_SAMPLE_LIMIT:
            self.contacts_truncated = True
            return
        landing.contact_samples.append(sample)
        landing.contact_descent_rates_fpm.append(
            _descent_rate_fpm(
                list(self.context),
                sample.simulator_elapsed_seconds,
            )
        )

    def _advance_rollout(self, sample: FlightSample) -> None:
        """Add only a continuous pair of confirmed on-ground observations.

        Args:
            sample (FlightSample): Latest frame in a confirmed ground run.

        Returns:
            None: Updates rollout duration and distance or stops at a gap.
        """
        landing = self.current
        previous = self.rollout_previous_sample
        if not self.rollout_active or landing is None or previous is None:
            return
        host_delta = (
            sample.session_elapsed_seconds
            - previous.session_elapsed_seconds
        )
        simulator_delta = (
            sample.simulator_elapsed_seconds
            - previous.simulator_elapsed_seconds
            if (
                sample.simulator_elapsed_seconds is not None
                and previous.simulator_elapsed_seconds is not None
            )
            else None
        )
        if (
            sample.discontinuity_before
            or previous.on_ground is not True
            or sample.on_ground is not True
            or host_delta <= 0.0
            or host_delta > MAX_ROLLOUT_SAMPLE_GAP_SECONDS
            or simulator_delta is None
            or simulator_delta <= 0.0
            or simulator_delta > MAX_ROLLOUT_SAMPLE_GAP_SECONDS
        ):
            self.rollout_active = False
            return
        if landing.rollout_seconds is not None:
            landing.rollout_seconds += simulator_delta
        segment = _distance_nm(previous, sample)
        if segment is None or segment > MAX_ROLLOUT_SEGMENT_NM:
            landing.rollout_distance_nm = None
        elif landing.rollout_distance_nm is not None:
            landing.rollout_distance_nm += segment
        if (
            previous.ground_speed_mps is not None
            and sample.ground_speed_mps is not None
            and previous.ground_speed_mps <= ROLLOUT_TAXI_SPEED_LIMIT_MPS
            and sample.ground_speed_mps <= ROLLOUT_TAXI_SPEED_LIMIT_MPS
        ):
            self.rollout_taxi_seconds += simulator_delta
            if self.rollout_taxi_seconds >= ROLLOUT_TAXI_CONFIRM_SECONDS:
                self.rollout_active = False
        else:
            self.rollout_taxi_seconds = 0.0
        self.rollout_previous_sample = sample

    def push(self, sample: FlightSample) -> None:
        """Advance the landing state machine by one full-resolution sample.

        Args:
            sample (FlightSample): Current chronological telemetry.

        Returns:
            None: Confirms events and appends bounded evidence.
        """
        if sample.discontinuity_before:
            self.rollout_active = False
            self.rollout_previous_sample = None
            self.airborne_seen = False
            self.airborne_count = 0
            self.current = None
            self.candidate = None
            self.candidate_count = 0
            self.air_gap_started = None
            self.context.clear()
        self.context.append(sample)
        strong_air = self._strong_airborne(sample)
        if strong_air:
            self.airborne_count += 1
        else:
            self.airborne_count = 0
        if self.airborne_count >= AIRBORNE_CONFIRM_SAMPLES:
            self.airborne_seen = True
        contact = self._contact_signal(sample)
        if sample.on_ground is not True:
            self.rollout_active = False
        if strong_air and self.airborne_seen:
            self.candidate = None
            self.candidate_count = 0
            if self.current is not None:
                if self.air_gap_started is None:
                    self.air_gap_started = sample.session_elapsed_seconds
                gap = (
                    sample.session_elapsed_seconds
                    - self.current.last_contact_elapsed_seconds
                )
                too_high = (
                    sample.agl_m is not None
                    and sample.agl_m > BOUNCE_HEIGHT_LIMIT_METRES
                )
                if gap > BOUNCE_GAP_LIMIT_SECONDS or too_high:
                    self.current = None
                    self.air_gap_started = None
                    self.candidate = None
                    self.candidate_count = 0
            return
        rebound_evidence = (
            self.current is not None
            and sample.on_ground is False
            and sample.agl_m is not None
            and sample.agl_m > AIRBORNE_AGL_MINIMUM_METRES
            and sample.vertical_speed_mps is not None
            and sample.vertical_speed_mps > 0.5
        )
        if contact:
            if self.candidate is None:
                self.candidate = sample
                self.candidate_count = 1
            else:
                self.candidate_count += 1
            confirmed = self.candidate_count >= GROUND_CONFIRM_SAMPLES
            if confirmed:
                first = self.candidate
                expired_air_gap = (
                    self.air_gap_started is not None
                    and sample.session_elapsed_seconds
                    - self.air_gap_started > BOUNCE_GAP_LIMIT_SECONDS
                )
                if self.current is not None and expired_air_gap:
                    self.current = None
                if self.current is None:
                    self._record_landing(first)
                    self._advance_rollout(sample)
                else:
                    height_evidence = any(
                        item.agl_m is not None
                        and item.agl_m > AIRBORNE_AGL_MINIMUM_METRES
                        for item in self.context
                    )
                    short_air_gap = (
                        self.air_gap_started is not None
                        and sample.session_elapsed_seconds
                        - self.air_gap_started <= BOUNCE_GAP_LIMIT_SECONDS
                    )
                    if short_air_gap and height_evidence:
                        self.current.contact_count += 1
                        self.current.bounce_possible = True
                        self.current.evidence.append(
                            "brief climb followed by renewed ground-state contact"
                        )
                        self.current.last_contact_elapsed_seconds = (
                            first.session_elapsed_seconds
                        )
                        self._append_contact_measurement(
                            self.current,
                            first,
                        )
                        self.current.rollout_seconds = (
                            0.0
                            if first.simulator_elapsed_seconds is not None
                            else None
                        )
                        self.current.rollout_distance_nm = 0.0
                        self.rollout_previous_sample = first
                        self.rollout_active = True
                        self.rollout_taxi_seconds = 0.0
                        self._advance_rollout(sample)
                    elif self.rollout_active:
                        self._advance_rollout(sample)
                    self.air_gap_started = None
                self.candidate = None
                self.candidate_count = 0
        elif rebound_evidence:
            if self.air_gap_started is None:
                self.air_gap_started = sample.session_elapsed_seconds
            self.candidate = None
            self.candidate_count = 0
        elif (
            sample.on_ground is False
            and sample.agl_m is not None
            and sample.agl_m > BOUNCE_HEIGHT_LIMIT_METRES
        ):
            self.candidate = None
            self.candidate_count = 0
            if self.current is not None:
                self.current = None
                self.air_gap_started = None
            self.airborne_seen = True
        else:
            self.candidate = None
            self.candidate_count = 0



class _FlightPhaseTracker:
    """Infer conservative phase transitions from continuous telemetry."""

    def __init__(self) -> None:
        """Initialize bounded phase state and evidence counters.

        Returns:
            None: Ready to process chronological samples.
        """
        self.events: list[FlightPhaseEvent] = []
        self.phase: str | None = None
        self.airborne_count = 0
        self.ground_count = 0
        self.slow_ground_count = 0
        self.taxi_motion_count = 0
        self.high_speed_seen = False
        self.preflight_confirmed = False
        self.cruise_count = 0
        self.goaround_count = 0
        self.goaround_start_agl: float | None = None
        self.goaround_start_elapsed: float | None = None
        self.previous_agl: float | None = None
        self.last_landing_elapsed: float | None = None
        self.airborne_since_landing = False
        self.final_ground_count = 0
        self.latest_elapsed = 0.0
        self.truncated = False

    def _append(
        self,
        phase: str,
        sample: FlightSample,
        confidence: str,
        evidence: list[str],
    ) -> None:
        """Append a bounded phase event.

        Args:
            phase (str): Phase or exceptional transition label.
            sample (FlightSample): Telemetry anchoring the event.
            confidence (str): Conservative confidence label.
            evidence (list[str]): Concrete telemetry evidence.

        Returns:
            None: Event is added unless the event cap was reached.
        """
        if len(self.events) >= PHASE_EVENT_LIMIT:
            self.truncated = True
            return
        self.events.append(FlightPhaseEvent(
            phase=phase,
            elapsed_seconds=sample.session_elapsed_seconds,
            confidence=confidence,
            evidence=evidence,
        ))

    def _transition(
        self,
        phase: str,
        sample: FlightSample,
        confidence: str,
        evidence: list[str],
    ) -> None:
        """Append a phase event only when the inferred phase changes.

        Args:
            phase (str): New inferred phase.
            sample (FlightSample): Telemetry anchoring the transition.
            confidence (str): Conservative confidence label.
            evidence (list[str]): Concrete telemetry evidence.

        Returns:
            None: Updates the current phase and bounded timeline.
        """
        if phase == self.phase:
            return
        self.phase = phase
        self._append(phase, sample, confidence, evidence)

    def push(
        self,
        sample: FlightSample,
        new_landing: Landing | None,
    ) -> None:
        """Advance phase inference with one sample and optional new contact.

        Args:
            sample (FlightSample): Current normalized telemetry.
            new_landing (Landing | None): Newly confirmed contact, if any.

        Returns:
            None: Updates bounded events and completion evidence.
        """
        self.latest_elapsed = sample.session_elapsed_seconds
        if not self.events:
            self._transition(
                "unknown", sample, "low",
                ["recording attached before phase evidence was established"],
            )
        if sample.discontinuity_before:
            self.airborne_count = 0
            self.goaround_count = 0
            self.goaround_start_agl = None
            self.goaround_start_elapsed = None
            self.cruise_count = 0
            self.ground_count = 0
            self.slow_ground_count = 0
            self.taxi_motion_count = 0
            self.previous_agl = None
            self._append(
                "discontinuity", sample, "moderate",
                ["recorded telemetry marks a pause, load or clock break"],
            )
        ground = sample.on_ground is True
        if ground:
            self.ground_count += 1
            self.airborne_count = 0
        elif sample.on_ground is False:
            self.ground_count = 0
            self.airborne_count += 1
        else:
            self.ground_count = 0
            self.airborne_count = 0
        if ground and sample.ground_speed_mps is not None:
            if sample.ground_speed_mps <= PHASE_TAXI_SPEED_MPS:
                self.slow_ground_count += 1
                self.taxi_motion_count = 0
            else:
                self.slow_ground_count = 0
                self.taxi_motion_count += 1
            if sample.ground_speed_mps >= PHASE_TAKEOFF_ROLL_SPEED_MPS:
                self.high_speed_seen = True
        else:
            self.slow_ground_count = 0
            self.taxi_motion_count = 0
        if (
            self.high_speed_seen
            and self.ground_count >= PHASE_GROUND_CONFIRM_SAMPLES
            and self.slow_ground_count >= PHASE_GROUND_CONFIRM_SAMPLES
            and not self.airborne_since_landing
        ):
            self._append(
                "rejectedtakeoff", sample, "moderate",
                ["high-speed ground roll returned to sustained low-speed ground"],
            )
            self.high_speed_seen = False
        if new_landing is not None:
            self.last_landing_elapsed = sample.session_elapsed_seconds
            self.airborne_since_landing = False
            self.high_speed_seen = False
            self.final_ground_count = 1 if ground else 0
            self._transition(
                "touchdown", sample,
                new_landing.confidence,
                list(new_landing.evidence),
            )
        elif ground:
            self.final_ground_count += 1
            if self.phase == "touchdown" or (
                self.phase == "rollout"
                and self.final_ground_count < PHASE_GROUND_CONFIRM_SAMPLES
            ):
                self._transition(
                    "rollout", sample, "moderate",
                    ["on-ground flag persisted after contact evidence"],
                )
            elif (
                self.last_landing_elapsed is not None
                and self.final_ground_count >= PHASE_GROUND_CONFIRM_SAMPLES
                and self.slow_ground_count >= PHASE_GROUND_CONFIRM_SAMPLES
            ):
                self._transition(
                    "taxiin", sample, "moderate",
                    ["sustained on-ground flag and low groundspeed"],
                )
            elif (
                self.last_landing_elapsed is None
                and self.ground_count >= PHASE_GROUND_CONFIRM_SAMPLES
                and self.slow_ground_count
                >= PHASE_GROUND_CONFIRM_SAMPLES
            ):
                self.preflight_confirmed = True
                self._transition(
                    "preflight", sample, "low",
                    [
                        "sustained on-ground state and low speed",
                        "initial ground context cannot distinguish parked states",
                    ],
                )
            elif (
                self.last_landing_elapsed is None
                and self.ground_count >= PHASE_GROUND_CONFIRM_SAMPLES
                and self.taxi_motion_count >= PHASE_GROUND_CONFIRM_SAMPLES
            ):
                self._transition(
                    "taxiout", sample, "moderate",
                    ["sustained on-ground movement"],
                )
            return
        else:
            self.final_ground_count = 0
        airborne = (
            self.airborne_count >= PHASE_AIRBORNE_CONFIRM_SAMPLES
            and sample.agl_m is not None
            and sample.agl_m > AIRBORNE_AGL_MINIMUM_METRES
            and sample.ground_speed_mps is not None
            and sample.ground_speed_mps > AIRBORNE_GROUND_SPEED_MINIMUM_MPS
        )
        if not airborne:
            self.previous_agl = sample.agl_m
            return
        if not self.airborne_since_landing:
            self.airborne_since_landing = True
            touch_and_go = (
                self.last_landing_elapsed is not None
                and sample.session_elapsed_seconds
                - self.last_landing_elapsed <= BOUNCE_GAP_LIMIT_SECONDS
                and sample.agl_m is not None
                and sample.agl_m <= BOUNCE_HEIGHT_LIMIT_METRES
            )
            if touch_and_go:
                self.high_speed_seen = False
                self._append(
                    "touchandgo", sample, "moderate",
                    ["airborne again shortly after confirmed contact"],
                )
            elif self.preflight_confirmed or self.phase == "taxiout":
                self._transition(
                    "takeoff", sample, "moderate",
                    ["airborne flag, AGL and groundspeed persisted"],
                )
        vertical = sample.vertical_speed_mps
        agl = sample.agl_m
        sustained_climb = (
            vertical is not None
            and vertical > PHASE_CLIMB_MINIMUM_MPS
            and agl is not None
            and self.previous_agl is not None
            and agl >= self.previous_agl
        )
        if sustained_climb:
            if self.goaround_start_agl is None:
                self.goaround_start_agl = self.previous_agl
                self.goaround_start_elapsed = sample.session_elapsed_seconds
            self.goaround_count += 1
        else:
            self.goaround_count = 0
            self.goaround_start_agl = None
            self.goaround_start_elapsed = None
        accumulated_gain = (
            agl - self.goaround_start_agl
            if agl is not None and self.goaround_start_agl is not None
            else 0.0
        )
        climb_span = (
            sample.session_elapsed_seconds - self.goaround_start_elapsed
            if self.goaround_start_elapsed is not None else 0.0
        )
        if (
            self.goaround_count >= PHASE_AIRBORNE_CONFIRM_SAMPLES
            and accumulated_gain >= PHASE_GOAROUND_GAIN_METRES
            and climb_span >= PHASE_GOAROUND_SUSTAIN_SECONDS
            and self.phase in ("descent", "approach", "flare")
        ):
            self._append(
                "goaround", sample, "moderate",
                ["positive vertical speed and accumulated AGL gain persisted"],
            )
            self._transition(
                "climb", sample, "moderate",
                ["positive vertical speed and accumulated AGL gain persisted"],
            )
        elif vertical is not None and vertical < PHASE_DESCENT_MAXIMUM_MPS:
            self.cruise_count = 0
            if agl is not None and agl <= PHASE_FLARE_AGL_METRES:
                phase = "flare"
                evidence = ["descending with low AGL"]
            elif agl is not None and agl <= PHASE_APPROACH_AGL_METRES:
                phase = "approach"
                evidence = ["descending within approach AGL boundary"]
            else:
                phase = "descent"
                evidence = ["negative vertical speed persisted in flight"]
            self._transition(phase, sample, "moderate", evidence)
        elif vertical is not None and vertical > PHASE_CLIMB_MINIMUM_MPS:
            self.cruise_count = 0
            if self.phase not in ("descent", "approach", "flare"):
                self._transition(
                    "climb", sample, "moderate",
                    ["positive vertical speed persisted in flight"],
                )
        elif vertical is not None and abs(vertical) <= PHASE_LEVEL_LIMIT_MPS:
            self.cruise_count += 1
            if self.cruise_count >= PHASE_CRUISE_CONFIRM_SAMPLES:
                self._transition(
                    "cruise", sample, "low",
                    ["near-level vertical speed persisted"],
                )
        self.previous_agl = agl

    def finish(self, sample: FlightSample | None) -> None:
        """Append an end marker when recording metadata confirms a stop.

        Args:
            sample (FlightSample | None): Last valid sample, if any.

        Returns:
            None: Adds a final event without asserting flight completion.
        """
        if sample is not None:
            self._append(
                "ended", sample, "high",
                ["flight recording stopped"],
            )

def _reduce_chart_samples(
    samples: list[FlightSample],
    stride: int,
) -> list[FlightSample]:
    """Thin chart points while carrying every omitted track break forward.

    Args:
        samples (list[FlightSample]): Chronological bounded chart working set.
        stride (int): Keep every stride-th point and the final observation.

    Returns:
        list[FlightSample]: Reduced points with break markers preserved.
    """
    reduced: list[FlightSample] = []
    pending_break: str | None = None
    last_index = len(samples) - 1
    for index, sample in enumerate(samples):
        selected = index % stride == 0 or index == last_index
        if sample.discontinuity_before:
            pending_break = sample.discontinuity_before
        if not selected:
            continue
        reason = sample.discontinuity_before or pending_break
        reduced.append(
            sample
            if reason == sample.discontinuity_before
            else replace(sample, discontinuity_before=reason)
        )
        pending_break = None
    return reduced


def analyze_session(directory: Path) -> FlightAnalysis:
    """Stream full-resolution contact evidence and retain bounded chart data.

    Args:
        directory (Path): Session directory containing checkpoint and JSONL file.

    Returns:
        FlightAnalysis: Recovered summary, landing events and bounded plot samples.
    """
    meta: dict[str, Any] = {}
    try:
        loaded = json.loads((directory / "session.json").read_text(encoding="utf-8"))
        if isinstance(loaded, dict):
            meta = loaded
    except (OSError, json.JSONDecodeError, UnicodeDecodeError):
        pass
    raw_warnings = meta.get("warnings", [])
    warnings = (
        [str(value)[:500] for value in raw_warnings[:ANALYSIS_WARNING_LIMIT]]
        if isinstance(raw_warnings, list) else []
    )

    def warn(message: str) -> None:
        """Append one bounded diagnostic when room remains.

        Args:
            message (str): Safe factual analysis warning.

        Returns:
            None: Keeps the warning list within its configured limit.
        """
        if len(warnings) < ANALYSIS_WARNING_LIMIT:
            warnings.append(message)

    chart: list[FlightSample] = []
    tracker = _LandingTracker()
    phases = _FlightPhaseTracker()
    previous: FlightSample | None = None
    latest: FlightSample | None = None
    distance = 0.0
    distance_known = False
    sample_count = 0
    last_elapsed = -1.0
    for row in _rows(directory):
        if row is None:
            warn("Malformed or incomplete telemetry record was skipped.")
            continue
        sample = _sample(row)
        if sample is None:
            warn("Telemetry record with invalid timing was skipped.")
            continue
        sample_count += 1
        if sample.session_elapsed_seconds <= last_elapsed:
            sample.discontinuity_before = (
                sample.discontinuity_before or "out-of-order host sample"
            )
            warn("Out-of-order sample split the trajectory.")
        last_elapsed = sample.session_elapsed_seconds
        latest = sample
        previous_landing_count = len(tracker.landings)
        previous_contact_count = (
            tracker.landings[-1].contact_count
            if tracker.landings else 0
        )
        tracker.push(sample)
        new_contact = (
            tracker.landings[-1]
            if (
                len(tracker.landings) > previous_landing_count
                or (
                    tracker.landings
                    and tracker.landings[-1].contact_count
                    > previous_contact_count
                )
            )
            else None
        )
        phases.push(sample, new_contact)
        if sample.discontinuity_before:
            previous = None
        if previous is not None:
            segment = _distance_nm(previous, sample)
            if segment is not None and segment < 50:
                distance += segment
                distance_known = True
            elif segment is not None:
                warn("Implausible position jump excluded from distance.")
        previous = sample
        chart.append(sample)
        if len(chart) > ANALYSIS_SAMPLE_WINDOW:
            chart = _reduce_chart_samples(chart, 2)
    recording_ended = meta.get("recording_ended") is True
    phases.finish(latest if recording_ended else None)
    if tracker.truncated:
        warn("Landing event output reached its safety limit.")
    if tracker.contacts_truncated:
        warn("Per-landing contact snapshots reached their safety limit.")
    if phases.truncated:
        warn("Flight phase event output reached its safety limit.")
    if sample_count == 0:
        warn("No valid telemetry samples were recovered.")
    if len(chart) > CHART_SAMPLE_LIMIT:
        stride = math.ceil(
            len(chart) / (CHART_SAMPLE_LIMIT - 1)
        )
        chart = _reduce_chart_samples(chart, stride)
    if (
        latest is not None
        and chart
        and chart[-1].session_elapsed_seconds != latest.session_elapsed_seconds
    ):
        chart[-1] = replace(
            latest,
            discontinuity_before=(
                latest.discontinuity_before
                or chart[-1].discontinuity_before
            ),
        )
    duration = _number(meta.get("ended_monotonic"))
    if duration is None:
        duration = last_elapsed if last_elapsed >= 0 else 0.0
    return FlightAnalysis(
        session_id=str(meta.get("session_id", directory.name)),
        simulator=str(meta.get("simulator", "unknown")),
        aircraft=meta.get("aircraft") if isinstance(meta.get("aircraft"), str) else None,
        simulator_build=(
            meta.get("simulator_build")
            if isinstance(meta.get("simulator_build"), str) else None
        ),
        completed=(
            recording_ended
            and bool(tracker.landings)
            and not phases.airborne_since_landing
            and phases.final_ground_count >= FINAL_GROUND_CONFIRM_SAMPLES
        ),
        duration_seconds=max(0.0, duration),
        distance_nm=distance if distance_known else None,
        samples=chart[:CHART_SAMPLE_LIMIT],
        landings=tracker.landings,
        warnings=warnings,
        events=phases.events,
    )
