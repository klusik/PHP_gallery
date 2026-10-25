# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_comparison.py
# Module Type: Postflight Comparison Service
# Purpose: Compare retained simulator observations with an attached dispatch.
# Responsibilities:
#   - Aggregate original samples with simulator-time weighting and explicit gaps.
#   - Match ordered OFP fixes without inventing missing passages or measurements.
#   - Prepare language-independent statistics and the completed flightplan.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Bounded, offline plan-versus-actual analysis independent of PDF presentation."""

import math
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from typing import TYPE_CHECKING, Iterable, Sequence

from uploader.flight_simbrief import DispatchPlan, DispatchPoint

if TYPE_CHECKING:
    from uploader.flight_data import FlightPhaseEvent, FlightSample, Landing

# Exact unit conversions and spherical model, shared by all comparison fields.
FEET_PER_METRE = 1 / 0.3048
KNOTS_PER_MPS = 1 / (1852 / 3600)
POUNDS_PER_KG = 1 / 0.45359237
# Purpose: Match the existing recorder's spherical geographic distance model.
# Type: float; Units: nautical miles; Scope: comparison geometry.
# Consumers: _distance. Rationale: 3440.065 NM is the shared mean-Earth model;
# keeping the same model avoids a different distance basis between report parts.
EARTH_RADIUS_NM = 3440.065
# Purpose: Refuse interpolation across lost acquisition and relocated positions.
# Type: float; Units: host seconds / nautical miles; Scope: comparison intervals.
# Consumers: compare_flight. Rationale: five seconds tolerates normal 1 Hz storage;
# fifty NM preserves the recorder's established relocation boundary.
MAX_INTERVAL_SECONDS = 5.0
MAX_SEGMENT_NM = 50.0
# Purpose: Require a close passage for numerical waypoint comparisons.
# Type: float; Units: nautical miles; Scope: one OFP fix / airport endpoint.
# Consumers: compare_flight. Rationale: 2 NM is stricter than the chart's 10 NM
# visual association; 10 NM accommodates takeoff confirmation near an airport.
FIX_MATCH_NM = 2.0
AIRPORT_MATCH_NM = 10.0
# Purpose: Bound repeated visits while preserving later chronological matches.
# Type: int; Units: passages per fix; Scope: one comparison.
# Consumers: compare_flight. Rationale: eight visits cover ordinary holds without
# retaining an unbounded trajectory; additional visits are explicitly disclosed.
MAX_FIX_VISITS = 8
# Purpose: Bound spatial indexing of very long or polar planning legs.
# Type: int; Units: one-degree cells per leg; Scope: route lookup index.
# Consumers: _GeometryIndex. Rationale: 256 cells bounds each insertion; wide
# legs stay in a common fallback list rather than disappearing from matching.
MAX_LEG_CELLS = 256
# Purpose: Define descriptive route and height bands, not operational limits.
# Type: float; Units: NM / feet; Scope: report comparison statistics.
# Consumers: compare_flight. Rationale: 5 NM and 300 ft are disclosed analytical
# bands; an exceedance is never classified as a pilot or ATC violation.
ROUTE_BAND_NM = 5.0
ALTITUDE_BAND_FT = 300.0
# Purpose: Require stationary parking-brake evidence before estimated block times.
# Type: float; Units: metres/second / simulator seconds; Scope: block detector.
# Consumers: compare_flight. Rationale: 0.5 m/s and five seconds reject momentary
# ground stops; the result remains an estimate and never identifies a stand.
PARKED_SPEED_MPS = 0.5
BLOCK_CONFIRM_SECONDS = 5.0
# Purpose: Group cruise pressure observations without unbounded unique values.
# Type: int; Units: feet per bin / retained bins; Scope: one cruise histogram.
# Consumers: compare_flight. Rationale: 100-ft bins describe observed levels;
# 2000 bins bound malformed captures without discarding ordinary flight levels.
CRUISE_BIN_FT = 100
MAX_CRUISE_BINS = 2000
# Purpose: Identify polar legs where longitude indexing becomes unreliable.
# Type: float; Units: latitude degrees; Scope: spatial index.
# Consumers: _GeometryIndex.cells. Rationale: 85 degrees selects full lookup
# for near-polar geometry while leaving ordinary route cells bounded.
POLAR_LATITUDE_DEGREES = 85.0
# Purpose: Compare accumulated interval coverage despite floating-point sums.
# Type: float; Units: seconds; Scope: coverage and adjacent passage comparisons.
# Consumers: compare_flight. Rationale: one millisecond absorbs addition noise
# without admitting a missing one-second cruise observation.
COVERAGE_EPSILON_SECONDS = 0.001
# Purpose: Refuse a schedule delay computed against a different simulator date.
# Type: float; Units: seconds; Scope: one planned/actual UTC pair.
# Consumers: _schedule_delta. Rationale: one day permits midnight arrivals but
# suppresses misleading multi-year lateness in historical simulator sessions.
MAX_SCHEDULE_DIFFERENCE_SECONDS = 24 * 60 * 60
# Purpose: Bound graph detail independently from full-resolution statistics.
# Type: int; Units: graph points; Scope: one comparison.
# Consumers: compare_flight. Rationale: 2000 vector points keep long-haul PDFs
# bounded; adaptive thinning affects only presentation, never aggregate metrics.
DEVIATION_POINT_LIMIT = 2000
# Purpose: Retain one initial graph observation per simulator second.
# Type: float; Units: simulator seconds; Scope: graph-only presentation samples.
# Consumers: compare_flight. Rationale: one second matches normal cruise storage;
# adaptive widening keeps graphs bounded while every raw interval feeds stats.
GRAPH_INTERVAL_SECONDS = 1.0
# Purpose: Quantize weighted distributions with explicit precision and memory.
# Type: int; Units: histogram bins; Scope: each WeightedValue.
# Consumers: WeightedValue.add. Rationale: 2000 bins bound long recordings;
# the final bin is an overflow bin and its percentile is a disclosed lower bound.
HISTOGRAM_BINS = 2000
# Purpose: Set meaningful histogram resolution for each physical comparison.
# Type: float; Units: scalar / NM / feet; Scope: weighted histogram bins.
# Consumers: WeightedValue, FlightComparison. Rationale: 1 scalar unit, 0.1 NM
# and 25 ft retain useful percentile detail without implying sensor precision.
SCALAR_BIN_WIDTH = 1.0
ROUTE_BIN_WIDTH_NM = 0.1
HEIGHT_BIN_WIDTH_FT = 25.0
# Purpose: Define the reported absolute-error percentile.
# Type: float; Units: cumulative weight fraction; Scope: descriptive statistics.
# Consumers: WeightedValue.p95. Rationale: 0.95 covers ordinary deviations while
# maxima separately retain the most extreme observed error.
P95_FRACTION = 0.95

AIR_PHASES = {"takeoff", "climb", "cruise", "descent", "approach", "flare"}
GROUND_PHASES = {"preflight", "taxiout", "touchdown", "rollout", "taxiin"}


@dataclass
class WeightedValue:
    """A time-weighted scalar with its own coverage, extrema and weighted sum."""

    seconds: float = 0.0
    total: float = 0.0
    minimum: float | None = None
    maximum: float | None = None
    absolute_total: float = 0.0
    squared_total: float = 0.0
    bin_width: float = SCALAR_BIN_WIDTH
    histogram: dict[int, float] = field(default_factory=dict)

    def add(self, value: float, seconds: float) -> None:
        """Accumulate one finite interval observation.

        Args:
            value (float): Observed interval mean in the owning metric's units.
            seconds (float): Positive simulator duration represented by the mean.
        Returns:
            None: Updates the weighted mean state and observed extrema.
        """
        self.total += value * seconds
        self.seconds += seconds
        self.minimum = value if self.minimum is None else min(self.minimum, value)
        self.maximum = value if self.maximum is None else max(self.maximum, value)
        self.absolute_total += abs(value) * seconds
        self.squared_total += value * value * seconds
        index = min(HISTOGRAM_BINS - 1, int(abs(value) / self.bin_width))
        self.histogram[index] = self.histogram.get(index, 0.0) + seconds

    @property
    def mean(self) -> float | None:
        """Return the simulator-time-weighted mean or unknown without coverage.

        Returns:
            float | None: Weighted mean in the metric's units, or None.
        """
        return self.total / self.seconds if self.seconds else None

    @property
    def mae(self) -> float | None:
        """Return the time-weighted mean absolute observed deviation.

        Returns:
            float | None: MAE in the owning units, or None without coverage.
        """
        return self.absolute_total / self.seconds if self.seconds else None

    @property
    def rmse(self) -> float | None:
        """Return the time-weighted root mean squared observed deviation.

        Returns:
            float | None: RMSE in the owning units, or None without coverage.
        """
        return math.sqrt(self.squared_total / self.seconds) if self.seconds else None

    @property
    def p95(self) -> float | None:
        """Return an upper-bin estimate of the 95th absolute-error percentile.

        Returns:
            float | None: Approximate P95, or None without coverage. The overflow
                bin reports its lower bound; observed maxima stay exact.
        """
        cumulative = 0.0
        for index, seconds in sorted(self.histogram.items()):
            cumulative += seconds
            if cumulative >= self.seconds * P95_FRACTION:
                return (index if index == HISTOGRAM_BINS - 1 else index + 1) * self.bin_width
        return None


@dataclass
class ComparisonMetric:
    """One named comparison with optional planned/observed values and units."""

    key: str
    unit: str
    planned: float | None
    actual: float | None


@dataclass
class PhaseStatistics:
    """Observed phase totals and scalar coverage; fuel is measured mass only."""

    phase: str
    seconds: float = 0.0
    distance_nm: float = 0.0
    distance_seconds: float = 0.0
    fuel_kg: float = 0.0
    fuel_seconds: float = 0.0
    values: dict[str, WeightedValue] = field(default_factory=dict)
    planned_seconds: float | None = None
    planned_fuel_kg: float | None = None


@dataclass
class Passage:
    """Closest observed passage; interpolation is confined to one valid raw leg."""

    host_seconds: float
    simulator_seconds: float | None
    simulator_utc: str | None
    fuel_kg: float | None
    pressure_altitude_ft: float | None
    altitude_amsl_ft: float | None
    weight_kg: float | None
    tas_kt: float | None
    gs_kt: float | None
    wind_speed_kt: float | None
    wind_direction_deg: float | None
    separation_nm: float
    block: int = 0
    last_seen: float = 0.0


@dataclass
class FlightplanRow:
    """One original OFP point and its optional ordered observed passage."""

    point: DispatchPoint
    passage: Passage | None = None
    actual_elapsed_seconds: float | None = None
    planned_utc: str | None = None
    altitude_comparable: bool = False


@dataclass
class DeviationPoint:
    """Graph-only route-position comparison; missing values split plotted lines."""

    elapsed_seconds: float
    time_delta_seconds: float | None
    fuel_delta_kg: float | None
    burn_delta_kg: float | None
    altitude_delta_ft: float | None
    route_error_nm: float | None
    break_before: bool = False


@dataclass
class FlightComparison:
    """Bounded language-neutral statistics serialized with the flight analysis."""

    metrics: list[ComparisonMetric] = field(default_factory=list)
    phases: list[PhaseStatistics] = field(default_factory=list)
    rows: list[FlightplanRow] = field(default_factory=list)
    notes: list[str] = field(default_factory=list)
    route_error_nm: WeightedValue = field(default_factory=lambda: WeightedValue(bin_width=ROUTE_BIN_WIDTH_NM))
    altitude_error_ft: WeightedValue = field(default_factory=lambda: WeightedValue(bin_width=HEIGHT_BIN_WIDTH_FT))
    time_error_seconds: WeightedValue = field(default_factory=WeightedValue)
    deviations: list[DeviationPoint] = field(default_factory=list)
    route_outside_seconds: float = 0.0
    altitude_outside_seconds: float = 0.0
    valid_seconds: float = 0.0
    airborne_seconds: float = 0.0
    excluded_intervals: int = 0
    matched_fixes: int = 0
    total_fixes: int = 0
    cruise_levels: dict[int, float] = field(default_factory=dict)
    offblock_utc: str | None = None
    onblock_utc: str | None = None
    takeoff_utc: str | None = None
    landing_utc: str | None = None
    route_accepted: bool = False


def number(value: str | float | int | None) -> float | None:
    """Read finite nonnegative planned quantities without inventing zero.

    Args:
        value (str | float | int | None): Optional dispatch scalar.
    Returns:
        float | None: Valid nonnegative quantity, or None for unknown/invalid data.
    """
    try:
        result = float(value)
        return result if math.isfinite(result) and result >= 0 else None
    except (ValueError, TypeError):
        return None


def mass_kg(value: str | float | None, units: str) -> float | None:
    """Normalize explicit OFP mass units; volume never becomes inferred mass.

    Args:
        value (str | float | None): Planned fuel or weight scalar.
        units (str): Declared OFP kg/kgs/lb/lbs mass unit.
    Returns:
        float | None: Kilograms, or None for absent values/unsupported units.
    """
    result = number(value)
    if result is None or units.lower() not in {"kg", "kgs", "lb", "lbs"}:
        return None
    return result / POUNDS_PER_KG if units.lower() in {"lb", "lbs"} else result


def _stamp(value: str | None) -> datetime | None:
    """Parse timezone-aware UTC evidence without assuming a host timezone.

    Args:
        value (str | None): Optional ISO simulator/planning timestamp.
    Returns:
        datetime | None: UTC instant or None for absent/naive/malformed input.
    """
    try:
        result = datetime.fromisoformat(value.replace("Z", "+00:00"))
        return result.astimezone(timezone.utc) if result.tzinfo else None
    except (ValueError, AttributeError, OverflowError):
        return None


def _schedule_delta(planned: str, actual: str | None) -> float | None:
    """Compare compatible simulator schedule timestamps.

    Args:
        planned (str): Optional OFP UTC event time.
        actual (str | None): Observed simulator UTC event time.
    Returns:
        float | None: Signed delay seconds, or None for incompatible dates/data.
    """
    left, right = _stamp(planned), _stamp(actual)
    if left is None or right is None:
        return None
    delta = (right - left).total_seconds()
    return delta if abs(delta) <= MAX_SCHEDULE_DIFFERENCE_SECONDS else None


def _distance(first: tuple[float, float], second: tuple[float, float]) -> float:
    """Measure shortest spherical separation including antimeridian crossings.

    Args:
        first (tuple[float, float]): Latitude/longitude degrees.
        second (tuple[float, float]): Latitude/longitude degrees.
    Returns:
        float: Great-circle distance in nautical miles.
    """
    lat1, lon1, lat2, lon2 = map(math.radians, (*first, *second))
    hav = math.sin((lat2 - lat1) / 2) ** 2 + (
        math.cos(lat1) * math.cos(lat2) * math.sin((lon2 - lon1) / 2) ** 2
    )
    return 2 * EARTH_RADIUS_NM * math.asin(math.sqrt(min(1.0, max(0.0, hav))))


def _position(sample: "FlightSample") -> tuple[float, float] | None:
    """Read a finite geographic observation, rejecting corrupt legacy rows.

    Args:
        sample (FlightSample): Original normalized telemetry observation.
    Returns:
        tuple[float, float] | None: Latitude/longitude degrees or unknown.
    """
    lat, lon = sample.latitude_deg, sample.longitude_deg
    if lat is None or lon is None or not -90 <= lat <= 90 or not -180 <= lon <= 180:
        return None
    return lat, lon


def _projection(
    point: tuple[float, float], start: tuple[float, float], end: tuple[float, float],
) -> tuple[float, float]:
    """Project onto a local planning polyline leg with wrapped longitudes.

    Args:
        point (tuple[float, float]): Query latitude/longitude degrees.
        start (tuple[float, float]): Leg start latitude/longitude degrees.
        end (tuple[float, float]): Leg end latitude/longitude degrees.
    Returns:
        tuple[float, float]: Clamped leg fraction and spherical separation NM;
            geometry describes the OFP polyline, not an airway protection area.
    """
    cosine = math.cos(math.radians(point[0]))
    ax = (start[1] - point[1] + 180) % 360 - 180
    dx = (end[1] - start[1] + 180) % 360 - 180
    ay, dy = start[0] - point[0], end[0] - start[0]
    length = (dx * cosine) ** 2 + dy ** 2
    fraction = (
        max(0.0, min(1.0, -(ax * dx * cosine ** 2 + ay * dy) / length))
        if length else 0.0
    )
    projected = (
        start[0] + fraction * dy,
        (start[1] + fraction * dx + 180) % 360 - 180,
    )
    return fraction, _distance(point, projected)


class _GeometryIndex:
    """Index bounded planning legs/fixes in padded one-degree geographic cells."""

    def __init__(self, points: list[DispatchPoint]) -> None:
        """Retain plan geometry with bounded per-leg indexing.

        Args:
            points (list[DispatchPoint]): Ordered provider-resolved OFP points.
        Returns:
            None: Builds fix cells, leg cells and a wide-leg fallback list.
        """
        self.points = points
        self.fixes: dict[tuple[int, int], list[int]] = defaultdict(list)
        self.legs: dict[tuple[int, int], list[int]] = defaultdict(list)
        self.wide: list[int] = []
        for index, point in enumerate(points):
            longitude = (point.longitude + 180) % 360 - 180
            self.fixes[(math.floor(point.latitude), math.floor(longitude))].append(index)
        for index, (start, end) in enumerate(zip(points, points[1:])):
            cells = self.cells((start.latitude, start.longitude), (end.latitude, end.longitude))
            if cells is None:
                self.wide.append(index)
            else:
                for cell in cells:
                    self.legs[cell].append(index)

    @staticmethod
    def cells(
        start: tuple[float, float], end: tuple[float, float],
    ) -> list[tuple[int, int]] | None:
        """Find padded bounding cells, or request the bounded wide-leg fallback.

        Args:
            start (tuple[float, float]): Geographic leg start in degrees.
            end (tuple[float, float]): Geographic leg end in degrees.
        Returns:
            list[tuple[int, int]] | None: Latitude/wrapped-longitude cell keys,
                or None when the insertion would exceed MAX_LEG_CELLS.
        """
        longitude = start[1] + (end[1] - start[1] + 180) % 360 - 180
        # A one-cell halo admits close passages at cell boundaries. Polar legs
        # use the global fallback because longitude cells shrink at the poles.
        latitudes = range(math.floor(min(start[0], end[0])) - 1,
                          math.floor(max(start[0], end[0])) + 2)
        longitudes = range(math.floor(min(start[1], longitude)) - 1,
                           math.floor(max(start[1], longitude)) + 2)
        if (
            len(latitudes) * len(longitudes) > MAX_LEG_CELLS
            or max(abs(start[0]), abs(end[0])) >= POLAR_LATITUDE_DEGREES
        ):
            return None
        return [(lat, (lon + 180) % 360 - 180) for lat in latitudes for lon in longitudes]

    def nearby_fixes(
        self, start: tuple[float, float], end: tuple[float, float],
    ) -> set[int]:
        """Select candidate fixes for one short continuous recorded leg.

        Args:
            start (tuple[float, float]): Recorded start latitude/longitude.
            end (tuple[float, float]): Recorded end latitude/longitude.
        Returns:
            set[int]: Candidate point indices; wide/polar legs check all fixes.
        """
        cells = self.cells(start, end)
        if cells is None:
            return set(range(len(self.points)))
        return {index for cell in cells for index in self.fixes.get(cell, [])}

    def nearest(self, position: tuple[float, float]) -> tuple[int, float, float] | None:
        """Locate the nearest planning leg without losing distant route segments.

        Args:
            position (tuple[float, float]): Recorded latitude/longitude degrees.
        Returns:
            tuple[int, float, float] | None: Leg index, fraction and separation
                NM, or None for a plan without two geographic points.
        """
        longitude = (position[1] + 180) % 360 - 180
        cell = math.floor(position[0]), math.floor(longitude)
        candidates = set(self.legs.get(cell, [])) | set(self.wide)
        best = None
        for indices in (candidates, range(max(0, len(self.points) - 1))):
            for index in indices:
                left, right = self.points[index:index + 2]
                fraction, distance = _projection(position, (left.latitude, left.longitude), (right.latitude, right.longitude))
                if best is None or distance < best[2]:
                    best = index, fraction, distance
            if best is not None and best[2] <= AIRPORT_MATCH_NM:
                break
        return best


def _passage(
    left: "FlightSample", right: "FlightSample", fraction: float,
    separation: float = 0.0, block: int = 0,
) -> Passage:
    """Estimate passage quantities only within one accepted raw observation leg.

    Args:
        left (FlightSample): Original interval start.
        right (FlightSample): Original interval end, or the same endpoint sample.
        fraction (float): Clamped closest-point position in that interval.
        separation (float): Distance from the fix in nautical miles; default zero.
        block (int): Continuous recording block identity; default initial block.
    Returns:
        Passage: Interpolated time/scalars with unknown inputs kept unknown;
            circular wind direction uses the nearest observed endpoint.
    """
    def scalar(name: str, scale: float = 1.0) -> float | None:
        """Interpolate one jointly available scalar.

        Args:
            name (str): FlightSample scalar attribute.
            scale (float): Unit conversion multiplier, default SI identity.
        Returns:
            float | None: Scaled scalar, or None if either observation is absent.
        """
        first, second = getattr(left, name), getattr(right, name)
        if (
            name == "fuel_weight_kg" and first is not None
            and second is not None and second > first and 0 < fraction < 1
        ):
            return None  # A loading change is not a continuous fuel measurement.
        return (
            (first + fraction * (second - first)) * scale
            if first is not None and second is not None else None
        )

    first_utc, last_utc = _stamp(left.simulator_utc), _stamp(right.simulator_utc)
    utc = None
    if first_utc is not None and last_utc is not None:
        utc = (first_utc + (last_utc - first_utc) * fraction).isoformat(timespec="seconds")
    return Passage(
        left.session_elapsed_seconds + fraction * (right.session_elapsed_seconds - left.session_elapsed_seconds),
        scalar("simulator_elapsed_seconds"), utc, scalar("fuel_weight_kg"),
        scalar("pressure_altitude_m", FEET_PER_METRE),
        scalar("altitude_m", FEET_PER_METRE), scalar("total_weight_kg"),
        scalar("true_airspeed_mps", KNOTS_PER_MPS),
        scalar("ground_speed_mps", KNOTS_PER_MPS),
        scalar("wind_velocity_mps", KNOTS_PER_MPS),
        (left if fraction < 0.5 else right).wind_direction_deg,
        separation, block, right.session_elapsed_seconds,
    )


def route_excursions(
    plan: DispatchPlan, positions: Sequence[tuple[float, float] | None],
) -> list[bool]:
    """Identify displayed positions beyond the descriptive route comparison band.

    Args:
        plan (DispatchPlan): Provider-resolved planning polyline.
        positions (Sequence[tuple[float, float] | None]): Displayed geographic
            positions; None retains a map trajectory break.
    Returns:
        list[bool]: One outside-band flag per position; absent geometry is false.
            These display flags never replace full-resolution error statistics.
    """
    geometry = _GeometryIndex(plan.points)
    flags = []
    for position in positions:
        nearest = geometry.nearest(position) if position is not None else None
        flags.append(nearest is not None and nearest[2] > ROUTE_BAND_NM)
    return flags


def grouped_phases(comparison: FlightComparison) -> list[PhaseStatistics]:
    """Group observed boundary phases into comparable operational report rows.

    Args:
        comparison (FlightComparison): Original phase totals and weighted values.
    Returns:
        list[PhaseStatistics]: New ground-before, taxi-out, climb, cruise,
            descent and ground-after rows. Climb includes takeoff; descent
            includes approach/flare; ground-after includes contact/rollout.
            Original statistics and coverage remain unchanged.
    """
    groups = (
        ("preflight", {"unknown", "preflight"}),
        ("taxiout", {"taxiout"}),
        ("climb", {"takeoff", "climb"}),
        ("cruise", {"cruise"}),
        ("descent", {"descent", "approach", "flare"}),
        ("taxiin", {"touchdown", "rollout", "taxiin"}),
    )
    result = []
    for name, members in groups:
        source = [row for row in comparison.phases if row.phase in members]
        if not source:
            continue
        combined = PhaseStatistics(name)
        for row in source:
            combined.seconds += row.seconds
            combined.distance_nm += row.distance_nm
            combined.distance_seconds += row.distance_seconds
            combined.fuel_kg += row.fuel_kg
            combined.fuel_seconds += row.fuel_seconds
            if row.planned_seconds is not None:
                combined.planned_seconds = (
                    (combined.planned_seconds or 0.0) + row.planned_seconds
                )
            if row.planned_fuel_kg is not None:
                combined.planned_fuel_kg = (
                    (combined.planned_fuel_kg or 0.0) + row.planned_fuel_kg
                )
            for key, value in row.values.items():
                target = combined.values.setdefault(
                    key, WeightedValue(bin_width=value.bin_width),
                )
                target.seconds += value.seconds
                target.total += value.total
                target.absolute_total += value.absolute_total
                target.squared_total += value.squared_total
                if value.minimum is not None:
                    target.minimum = (
                        value.minimum if target.minimum is None
                        else min(target.minimum, value.minimum)
                    )
                if value.maximum is not None:
                    target.maximum = (
                        value.maximum if target.maximum is None
                        else max(target.maximum, value.maximum)
                    )
                for index, seconds in value.histogram.items():
                    target.histogram[index] = (
                        target.histogram.get(index, 0.0) + seconds
                    )
        result.append(combined)
    return result


def compare_flight(
    plan: DispatchPlan, samples: Iterable["FlightSample | None"],
    events: Sequence["FlightPhaseEvent"], landings: Sequence["Landing"],
    completed: bool,
) -> FlightComparison:
    """Analyze original saved telemetry with bounded memory and explicit coverage.

    Args:
        plan (DispatchPlan): Exact optional dispatch attached to this flight.
        samples (Iterable[FlightSample | None]): Chronological original samples;
            None represents malformed/missing durable evidence.
        events (Sequence[FlightPhaseEvent]): Existing inferred phase markers.
        landings (Sequence[Landing]): Confirmed contacts from the existing owner.
        completed (bool): Existing final-ground flight-completion qualification.
    Returns:
        FlightComparison: Shared statistics, partial-evidence notes and ordered
            flightplan rows; no file, network or raw-data mutation occurs.
    """
    result = FlightComparison(total_fixes=len(plan.points))
    geometry = _GeometryIndex(plan.points)
    visits: dict[int, list[Passage]] = defaultdict(list)
    phase_rows: dict[str, PhaseStatistics] = {}
    transitions = sorted(
        (event for event in events if event.phase in AIR_PHASES | GROUND_PHASES),
        key=lambda event: event.elapsed_seconds,
    )
    takeoffs = [event.elapsed_seconds for event in events if event.phase == "takeoff"]
    takeoff_time = takeoffs[0] if takeoffs else None
    landing_time = landings[-1].first_contact_elapsed_seconds if completed and landings else None
    event_samples: dict[float, "FlightSample"] = {}
    targets = sorted(
        {event.elapsed_seconds for event in transitions}
        | set(takeoffs)
        | ({landing_time} if landing_time is not None else set())
    )
    target_index = 0
    phase_index = 0
    phase = "unknown"
    previous = None
    previous_phase = phase
    block = 0
    airborne_gaps = 0
    fuel_changed = False
    parked = None
    parked_since = None
    moving_since = None
    offblock = None
    onblock = None
    transition_values = [
        number(plan.transition_altitude_ft),
        number(plan.arrival_transition_altitude_ft),
    ]
    transition = (
        max(transition_values)
        if all(value is not None for value in transition_values) else None
    )
    graph_stride = GRAPH_INTERVAL_SECONDS
    graph_last = -math.inf
    graph_break = False

    for sample in samples:
        if sample is None:
            previous = None
            block += 1
            result.excluded_intervals += 1
            airborne_gaps += 1
            graph_break = True
            continue
        while target_index < len(targets) and targets[target_index] <= sample.session_elapsed_seconds:
            if sample.session_elapsed_seconds - targets[target_index] <= MAX_INTERVAL_SECONDS:
                event_samples[targets[target_index]] = sample
            target_index += 1
        while (
            phase_index < len(transitions)
            and transitions[phase_index].elapsed_seconds
            <= sample.session_elapsed_seconds
        ):
            phase = transitions[phase_index].phase
            phase_index += 1
        if previous is None:
            previous, previous_phase = sample, phase
            continue
        host_delta = sample.session_elapsed_seconds - previous.session_elapsed_seconds
        simulator_delta = (
            sample.simulator_elapsed_seconds - previous.simulator_elapsed_seconds
            if sample.simulator_elapsed_seconds is not None and previous.simulator_elapsed_seconds is not None else None
        )
        left_position, right_position = _position(previous), _position(sample)
        if left_position is None or right_position is None:
            graph_break = True
        distance = (
            _distance(left_position, right_position)
            if left_position is not None and right_position is not None else None
        )
        in_flight = (
            takeoff_time is not None
            and previous.session_elapsed_seconds >= takeoff_time
            and (landing_time is None or sample.session_elapsed_seconds <= landing_time)
        )
        broken = sample.discontinuity_before not in (None, "simulation rate changed")
        valid = (
            not broken and 0 < host_delta <= MAX_INTERVAL_SECONDS
            and simulator_delta is not None and simulator_delta > 0
            and (distance is None or distance < MAX_SEGMENT_NM)
        )
        if not valid:
            block += 1
            result.excluded_intervals += 1
            airborne_gaps += int(in_flight)
            graph_break = True
            parked = parked_since = moving_since = None
            previous, previous_phase = sample, phase
            continue
        dt = simulator_delta
        result.valid_seconds += dt
        stats = phase_rows.setdefault(previous_phase, PhaseStatistics(previous_phase))
        stats.seconds += dt
        if distance is not None:
            stats.distance_nm += distance
            stats.distance_seconds += dt
        if previous.fuel_weight_kg is not None and sample.fuel_weight_kg is not None:
            used = previous.fuel_weight_kg - sample.fuel_weight_kg
            if used >= 0:
                stats.fuel_kg += used
                stats.fuel_seconds += dt
            else:
                fuel_changed = fuel_changed or in_flight
        for attribute, key, scale in (
            ("indicated_airspeed_mps", "ias", KNOTS_PER_MPS),
            ("true_airspeed_mps", "tas", KNOTS_PER_MPS),
            ("ground_speed_mps", "gs", KNOTS_PER_MPS),
            ("vertical_speed_mps", "vs", FEET_PER_METRE * 60),
        ):
            left_value, right_value = getattr(previous, attribute), getattr(sample, attribute)
            if left_value is not None and right_value is not None:
                value = stats.values.setdefault(key, WeightedValue())
                value.add((left_value + right_value) * scale / 2, dt)
                value.minimum = min(value.minimum, left_value * scale, right_value * scale)
                value.maximum = max(value.maximum, left_value * scale, right_value * scale)
        if left_position is not None and right_position is not None and distance:
            # Ground-track-relative wind describes the flown track, rather than
            # incorrectly treating aircraft heading as the intended route.
            lat1, lat2 = math.radians(left_position[0]), math.radians(right_position[0])
            dlon = math.radians(right_position[1] - left_position[1])
            bearing = math.atan2(
                math.sin(dlon) * math.cos(lat2),
                math.cos(lat1) * math.sin(lat2)
                - math.sin(lat1) * math.cos(lat2) * math.cos(dlon),
            )
            if (
                sample.wind_velocity_mps is not None
                and sample.wind_direction_deg is not None
                and previous.wind_velocity_mps is not None
                and previous.wind_direction_deg is not None
            ):
                angle = math.radians(sample.wind_direction_deg) - bearing
                stats.values.setdefault("headwind", WeightedValue()).add(sample.wind_velocity_mps * KNOTS_PER_MPS * math.cos(angle), dt)
                stats.values.setdefault("crosswind", WeightedValue()).add(abs(sample.wind_velocity_mps * KNOTS_PER_MPS * math.sin(angle)), dt)
        if in_flight:
            result.airborne_seconds += dt
            if left_position is not None and right_position is not None:
                for index in geometry.nearby_fixes(left_position, right_position):
                    if index in (0, len(plan.points) - 1):
                        continue  # Airport rows use actual takeoff/landing anchors.
                    point = plan.points[index]
                    fraction, separation = _projection((point.latitude, point.longitude), left_position, right_position)
                    if separation > FIX_MATCH_NM:
                        continue
                    passage = _passage(previous, sample, fraction, separation, block)
                    retained = visits[index]
                    if (
                        retained and retained[-1].block == block
                        and sample.session_elapsed_seconds - retained[-1].last_seen
                        <= host_delta + COVERAGE_EPSILON_SECONDS
                    ):
                        if separation < retained[-1].separation_nm:
                            retained[-1] = passage
                        retained[-1].last_seen = sample.session_elapsed_seconds
                    elif len(retained) < MAX_FIX_VISITS:
                        retained.append(passage)
                    elif "visits_limited" not in result.notes:
                        result.notes.append("visits_limited")
            if left_position is not None and right_position is not None:
                nearest = geometry.nearest(right_position)
                if nearest is not None:
                    index, fraction, separation = nearest
                    result.route_error_nm.add(separation, dt)
                    result.route_outside_seconds += dt if separation > ROUTE_BAND_NM else 0.0
                    left_fix, right_fix = plan.points[index:index + 2]
                    planned_height = (
                        left_fix.altitude_ft + fraction * (right_fix.altitude_ft - left_fix.altitude_ft)
                        if left_fix.altitude_ft is not None and right_fix.altitude_ft is not None else None
                    )
                    actual_height = (
                        sample.pressure_altitude_m * FEET_PER_METRE
                        if sample.pressure_altitude_m is not None
                        and previous.pressure_altitude_m is not None else None
                    )
                    height_delta = None
                    if (
                        transition is not None and planned_height is not None
                        and actual_height is not None
                        and min(planned_height, actual_height) >= transition
                    ):
                        height_delta = actual_height - planned_height
                        result.altitude_error_ft.add(height_delta, dt)
                        result.altitude_outside_seconds += dt if abs(height_delta) > ALTITUDE_BAND_FT else 0.0
                    planned_time = (
                        left_fix.elapsed_seconds + fraction * (right_fix.elapsed_seconds - left_fix.elapsed_seconds)
                        if left_fix.elapsed_seconds is not None and right_fix.elapsed_seconds is not None
                        and right_fix.elapsed_seconds >= left_fix.elapsed_seconds else None
                    )
                    origin_sample = event_samples.get(takeoff_time)
                    elapsed = (
                        sample.simulator_elapsed_seconds
                        - origin_sample.simulator_elapsed_seconds
                        if origin_sample
                        and origin_sample.simulator_elapsed_seconds is not None
                        else None
                    )
                    time_delta = (
                        elapsed - planned_time
                        if elapsed is not None and planned_time is not None
                        and not airborne_gaps else None
                    )
                    if time_delta is not None:
                        result.time_error_seconds.add(time_delta, dt)
                    left_fuel = mass_kg(left_fix.fuel_onboard, plan.units)
                    right_fuel = mass_kg(right_fix.fuel_onboard, plan.units)
                    planned_fuel = (
                        left_fuel + fraction * (right_fuel - left_fuel)
                        if left_fuel is not None and right_fuel is not None else None
                    )
                    fuel_delta = (
                        sample.fuel_weight_kg - planned_fuel
                        if sample.fuel_weight_kg is not None
                        and planned_fuel is not None else None
                    )
                    planned_takeoff_fuel = mass_kg(plan.points[0].fuel_onboard, plan.units)
                    initial_extra = (
                        origin_sample.fuel_weight_kg - planned_takeoff_fuel
                        if origin_sample
                        and origin_sample.fuel_weight_kg is not None
                        and planned_takeoff_fuel is not None else None
                    )
                    burn_delta = (
                        initial_extra - fuel_delta
                        if initial_extra is not None and fuel_delta is not None
                        and not fuel_changed and not airborne_gaps else None
                    )
                    if elapsed is not None and elapsed >= graph_last + graph_stride:
                        result.deviations.append(DeviationPoint(elapsed, time_delta, fuel_delta,
                            burn_delta, height_delta, separation, graph_break))
                        graph_last, graph_break = elapsed, False
                        if len(result.deviations) >= DEVIATION_POINT_LIMIT:
                            # Carry every omitted discontinuity into the next
                            # retained point instead of drawing across a loss.
                            reduced = []
                            pending_break = False
                            for graph_index, graph_point in enumerate(result.deviations):
                                pending_break = pending_break or graph_point.break_before
                                if graph_index % 2 == 0 or graph_index == len(result.deviations) - 1:
                                    graph_point.break_before = pending_break
                                    reduced.append(graph_point)
                                    pending_break = False
                            result.deviations = reduced
                            graph_stride *= 2
                    if previous_phase == "cruise" and actual_height is not None:
                        level = round(actual_height / CRUISE_BIN_FT) * CRUISE_BIN_FT
                        if level in result.cruise_levels or len(result.cruise_levels) < MAX_CRUISE_BINS:
                            result.cruise_levels[level] = result.cruise_levels.get(level, 0.0) + dt
        stationary = (
            sample.on_ground is True and sample.parking_brake is True
            and sample.ground_speed_mps is not None
            and sample.ground_speed_mps <= PARKED_SPEED_MPS
        )
        if stationary:
            if parked_since is None:
                parked_since = sample
            if sample.simulator_elapsed_seconds - parked_since.simulator_elapsed_seconds >= BLOCK_CONFIRM_SECONDS:
                parked = parked_since
                if landing_time is not None and parked_since.session_elapsed_seconds > landing_time and onblock is None:
                    onblock = parked_since
            moving_since = None
        else:
            parked_since = None
            moving = (
                sample.on_ground is True and sample.parking_brake is False
                and sample.ground_speed_mps is not None
                and sample.ground_speed_mps > PARKED_SPEED_MPS
            )
            if moving and parked is not None and offblock is None and (takeoff_time is None or sample.session_elapsed_seconds < takeoff_time):
                if moving_since is None:
                    moving_since = sample
                if sample.simulator_elapsed_seconds - moving_since.simulator_elapsed_seconds >= BLOCK_CONFIRM_SECONDS:
                    offblock = moving_since
            else:
                moving_since = None
        previous, previous_phase = sample, phase

    takeoff = event_samples.get(takeoff_time)
    landing = event_samples.get(landing_time)
    result.takeoff_utc = takeoff.simulator_utc if takeoff else None
    result.landing_utc = landing.simulator_utc if landing else None
    result.offblock_utc = offblock.simulator_utc if offblock else None
    result.onblock_utc = onblock.simulator_utc if onblock else None
    previous_host = -math.inf
    planned_anchor = _stamp(plan.scheduled_takeoff_utc)
    previous_plan_time = -math.inf
    for index, point in enumerate(plan.points):
        observed = None
        duplicate_departure = (
            index == 1 and point.ident.upper() == plan.origin.upper()
            and plan.points[0].ident.upper() == plan.origin.upper()
            and _distance(
                (point.latitude, point.longitude),
                (plan.points[0].latitude, plan.points[0].longitude),
            ) <= FIX_MATCH_NM
        )
        endpoint = takeoff if index == 0 else landing if index == len(plan.points) - 1 else None
        if endpoint is not None and _position(endpoint) is not None:
            separation = _distance(_position(endpoint), (point.latitude, point.longitude))
            if separation <= AIRPORT_MATCH_NM:
                observed = _passage(endpoint, endpoint, 0.0, separation)
        elif index not in (0, len(plan.points) - 1):
            observed = next((visit for visit in visits[index]
                if visit.host_seconds > previous_host or (
                    duplicate_departure
                    and abs(visit.host_seconds - previous_host)
                    <= COVERAGE_EPSILON_SECONDS
                )), None)
        # Airport rows may repeat a departure or arrival navlog fix. Only those
        # verified endpoint duplicates may share the observed event timestamp;
        # intermediate fixes still need a strictly ordered passage. Otherwise
        # an accepted arrival disappears and suppresses every endpoint-based
        # summary despite complete evidence.
        repeated_arrival = (
            index == len(plan.points) - 1 and index > 0
            and point.ident.upper() == plan.destination.upper()
            and plan.points[index - 1].ident.upper() == plan.destination.upper()
            and _distance(
                (point.latitude, point.longitude),
                (plan.points[index - 1].latitude, plan.points[index - 1].longitude),
            ) <= FIX_MATCH_NM
            and observed is not None
            and abs(observed.host_seconds - previous_host)
            <= COVERAGE_EPSILON_SECONDS
        )
        repeated_departure = (
            duplicate_departure and observed is not None
            and abs(observed.host_seconds - previous_host)
            <= COVERAGE_EPSILON_SECONDS
        )
        if observed is not None and (
            observed.host_seconds > previous_host
            or repeated_arrival or repeated_departure
        ):
            previous_host = observed.host_seconds
        else:
            observed = None
        relative = None
        if observed is not None and takeoff is not None and observed.simulator_seconds is not None and takeoff.simulator_elapsed_seconds is not None and not airborne_gaps:
            relative = observed.simulator_seconds - takeoff.simulator_elapsed_seconds
        ordered_time = point.elapsed_seconds is not None and point.elapsed_seconds >= previous_plan_time
        planned_utc = None
        if ordered_time:
            previous_plan_time = point.elapsed_seconds
            if planned_anchor is not None:
                try:
                    planned_utc = (planned_anchor + timedelta(seconds=point.elapsed_seconds)).isoformat(timespec="seconds")
                except OverflowError:
                    pass
        elif point.elapsed_seconds is not None and "plan_time_order" not in result.notes:
            result.notes.append("plan_time_order")
        result.rows.append(FlightplanRow(point, observed, relative if ordered_time else None, planned_utc,
            transition is not None and observed is not None and point.altitude_ft is not None
            and observed.pressure_altitude_ft is not None and min(point.altitude_ft, observed.pressure_altitude_ft) >= transition))
    result.matched_fixes = sum(row.passage is not None for row in result.rows)
    distinct = {(row.point.latitude, row.point.longitude) for row in result.rows if row.passage is not None}
    result.route_accepted = len(distinct) >= 2
    result.phases = list(phase_rows.values())
    # OFP stage totals describe planning phases; observed approach and flare
    # remain separate rows and are not silently added to inferred descent.
    incomplete_stages = False
    incomplete_time: set[str] = set()
    incomplete_fuel: set[str] = set()
    for left_fix, right_fix in zip(plan.points, plan.points[1:]):
        phase_name = {"CLB": "climb", "CRZ": "cruise", "DSC": "descent", "DES": "descent"}.get(right_fix.stage.upper())
        if phase_name is None:
            if left_fix.elapsed_seconds != right_fix.elapsed_seconds:
                incomplete_stages = True
            continue
        if phase_name not in phase_rows:
            continue
        stats = phase_rows[phase_name]
        if left_fix.elapsed_seconds is not None and right_fix.elapsed_seconds is not None and right_fix.elapsed_seconds >= left_fix.elapsed_seconds:
            stats.planned_seconds = (stats.planned_seconds or 0.0) + right_fix.elapsed_seconds - left_fix.elapsed_seconds
        else:
            incomplete_time.add(phase_name)
        left_fuel, right_fuel = mass_kg(left_fix.fuel_onboard, plan.units), mass_kg(right_fix.fuel_onboard, plan.units)
        if left_fuel is not None and right_fuel is not None and left_fuel >= right_fuel:
            stats.planned_fuel_kg = (stats.planned_fuel_kg or 0.0) + left_fuel - right_fuel
        else:
            incomplete_fuel.add(phase_name)
    for stats in result.phases:
        if incomplete_stages or stats.phase in incomplete_time:
            stats.planned_seconds = None
        if incomplete_stages or stats.phase in incomplete_fuel:
            stats.planned_fuel_kg = None

    def metric(key: str, unit: str, planned: float | None, actual: float | None) -> None:
        """Append one factual comparison without fabricating unavailable values.

        Args:
            key (str): Stable localized metric label suffix.
            unit (str): Canonical s, kg, NM, kt or ft unit.
            planned (float | None): Planned comparable quantity.
            actual (float | None): Observed comparable quantity.
        Returns:
            None: Adds a row to the shared analysis.
        """
        result.metrics.append(ComparisonMetric(key, unit, planned, actual))

    endpoints_match = bool(result.rows and result.rows[0].passage and result.rows[-1].passage)
    full = endpoints_match and completed and len(takeoffs) == 1 and len(landings) == 1 and not airborne_gaps
    air_phases = [stats for stats in result.phases if stats.phase in AIR_PHASES]
    fuel_covered = bool(air_phases) and all(abs(stats.fuel_seconds - stats.seconds) <= COVERAGE_EPSILON_SECONDS for stats in air_phases)
    distance_covered = bool(air_phases) and all(abs(stats.distance_seconds - stats.seconds) <= COVERAGE_EPSILON_SECONDS for stats in air_phases)
    metric("airtime", "s", number(plan.duration_seconds), result.airborne_seconds if full else None)
    metric("distance", "NM", number(plan.distance_nm) if plan.distance_source == "route" else None,
           sum(stats.distance_nm for stats in air_phases) if full and distance_covered else None)
    takeoff_fuel = takeoff.fuel_weight_kg if takeoff else None
    landing_fuel = landing.fuel_weight_kg if landing else None
    metric("trip_fuel", "kg", mass_kg(plan.fuel_trip, plan.units),
           takeoff_fuel - landing_fuel if full and fuel_covered and not fuel_changed and takeoff_fuel is not None and landing_fuel is not None else None)
    metric("takeoff_fuel", "kg", mass_kg(plan.points[0].fuel_onboard, plan.units) if plan.points else None, takeoff_fuel if endpoints_match else None)
    metric("landing_fuel", "kg", mass_kg(plan.points[-1].fuel_onboard, plan.units) if plan.points else None, landing_fuel if endpoints_match else None)
    metric("ramp_fuel", "kg", mass_kg(plan.fuel, plan.units), offblock.fuel_weight_kg if offblock and endpoints_match else None)
    for name, endpoint, planned in (("tow", takeoff, plan.takeoff_weight), ("ldw", landing, plan.landing_weight)):
        metric(name, "kg", mass_kg(planned, plan.units), endpoint.total_weight_kg if endpoint and endpoints_match else None)
    metric("zfw", "kg", mass_kg(plan.zero_fuel_weight, plan.units),
           takeoff.total_weight_kg - takeoff_fuel if takeoff and endpoints_match and takeoff.total_weight_kg is not None and takeoff_fuel is not None else None)
    reserve = mass_kg(plan.fuel_reserve, plan.units)
    metric("reserve_margin", "kg", 0.0 if reserve is not None else None, landing_fuel - reserve if endpoints_match and landing_fuel is not None and reserve is not None else None)
    for name, left, right, planned in (
        ("block", offblock, onblock, plan.block_seconds),
        ("taxiout", offblock, takeoff, plan.taxi_out_seconds),
        ("taxiin", landing, onblock, plan.taxi_in_seconds),
    ):
        actual = right.simulator_elapsed_seconds - left.simulator_elapsed_seconds if left and right and left.simulator_elapsed_seconds is not None and right.simulator_elapsed_seconds is not None and not result.excluded_intervals and endpoints_match else None
        metric(name, "s", number(planned), actual)
    for name, planned, actual in (
        ("departure_delay", plan.scheduled_departure_utc, result.offblock_utc),
        ("arrival_delay", plan.scheduled_arrival_utc, result.onblock_utc),
        ("takeoff_delay", plan.scheduled_takeoff_utc, result.takeoff_utc),
        ("landing_delay", plan.scheduled_landing_utc, result.landing_utc),
    ):
        metric(name, "s", 0.0 if planned else None, _schedule_delta(planned, actual) if endpoints_match else None)
        if planned and actual and _schedule_delta(planned, actual) is None:
            result.notes.append("schedule_date")
    cruise = phase_rows.get("cruise")
    metric("cruise_tas", "kt", number(plan.cruise_tas_kt), cruise.values.get("tas", WeightedValue()).mean if cruise else None)
    for ident, phase_name in (("TOC", "cruise"), ("TOD", "descent")):
        point = next((point for point in plan.points if point.ident.upper() == ident), None)
        cruise_events = [event.elapsed_seconds for event in transitions if event.phase == "cruise" and takeoff_time is not None and event.elapsed_seconds > takeoff_time]
        after = takeoff_time if ident == "TOC" else max(cruise_events, default=None)
        event = next((event for event in transitions if event.phase == phase_name and after is not None and event.elapsed_seconds > after), None)
        observed = event_samples.get(event.elapsed_seconds) if event else None
        elapsed = observed.simulator_elapsed_seconds - takeoff.simulator_elapsed_seconds if observed and takeoff and observed.simulator_elapsed_seconds is not None and takeoff.simulator_elapsed_seconds is not None and full else None
        metric(ident.lower() + "_time", "s", point.elapsed_seconds if point else None, elapsed)
        metric(ident.lower() + "_position", "NM", 0.0 if point else None,
               _distance(_position(observed), (point.latitude, point.longitude)) if point and observed and _position(observed) is not None and full else None)
    for condition, note in (
        (not result.route_accepted, "route_unverified"),
        (not full, "partial"), (bool(result.excluded_intervals), "gaps"),
        (fuel_changed, "fuel_changed"), (not fuel_covered, "fuel_missing"),
        (not result.altitude_error_ft.seconds, "pressure_missing"),
        (offblock is None or onblock is None, "block_estimate"),
        (len(takeoffs) > 1 or len(landings) > 1, "multiple_legs"),
    ):
        if condition:
            result.notes.append(note)
    if not result.route_accepted:
        # Preserve all planned rows, but do not present an unrelated nearby
        # capture as an observed OFP passage or a validated deviation metric.
        for row in result.rows:
            row.passage = None
            row.actual_elapsed_seconds = None
        for item in result.metrics:
            item.actual = None
        result.matched_fixes = 0
        result.route_error_nm = WeightedValue()
        result.altitude_error_ft = WeightedValue()
        result.time_error_seconds = WeightedValue()
        result.deviations = []
    return result
