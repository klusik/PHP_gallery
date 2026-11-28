# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/reports/route.py
# Module Type: Flight report route owner
# Purpose: Own route behavior for multilingual postflight PDF reports.
# Responsibilities: Preserve report rendering behavior behind explicit owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own the route domain of flight report generation."""

import io
import math
from dataclasses import replace
from datetime import datetime, timezone
from pathlib import Path
from typing import List, Optional, Sequence, Tuple

from PIL import Image as PillowImage

from reportlab.lib import colors
from reportlab.pdfbase import pdfmetrics
from reportlab.platypus import Flowable, PageBreak
from reportlab.graphics.shapes import Circle, Drawing, Image as MapShape, Line, PolyLine, Rect, String

from uploader.flight_maps import map_viewport, print_size, render_map
from uploader.flight_data import FlightAnalysis, FlightSample, _distance_nm
from uploader.flight_report_translations import tr
from uploader.flight_comparison import route_excursions

from .policy import (
    CHART_HEIGHT,
    AXIS_EPSILON,
    AXIS_MAJOR_MULTIPLE,
    CHART_WIDTH,
    CONTACT_TIME_DIVISIONS,
    DISPATCH_POSITION_MATCH_NM,
    HOUR_SECONDS,
    MAP_OVERVIEW_FADE,
    MAX_GRID_LINES,
    METRES_TO_FEET,
    OVERVIEW_METRIC_FRACTION,
    POUNDS_PER_KG,
    PROFILE_MAX_BRIDGE_SECONDS,
    PROFILE_SHORT_BRIDGE_SECONDS,
    REPORT_POSITION_JUMP_NM,
    SHORT_FLIGHT_SECONDS,
    TIME_GRID_SECONDS,
    _COLOR_BLUE,
    _COLOR_GREEN,
    _COLOR_GRID,
    _COLOR_INK,
    _COLOR_INTERPOLATED,
    _COLOR_MINOR_GRID,
    _COLOR_MUTED,
    _COLOR_PLANNED,
    _COLOR_RED,
    _FONT_BOLD,
    _FONT_NAME,
)

from .presentation import (
    _axis_limits,
    _format_duration,
    _number,
    _chart_series,
    _nice_step,
    _paragraph,
    _relative_time,
    _safe_source_text,
    _styles,
    _visual_metrics,
)


def _dispatch_series(
    analysis: FlightAnalysis, attribute: str, scale: float, unit: str,
) -> list[tuple[float | None, float | None, bool]]:
    """Align timed OFP fixes to observed takeoff through captured simulator time.

    Args:
        analysis (FlightAnalysis): Recorded samples, phase events and optional OFP.
        attribute (str): Supported recorded field: altitude_m or fuel_weight_kg.
        scale (float): Recorded SI-to-displayed-unit multiplier.
        unit (str): Display unit; planned fuel admits only known kg/lb units.
    Returns:
        list[tuple[float | None, float | None, bool]]: Session-elapsed seconds,
            already converted planned values and explicit gaps. Missing takeoff,
            simulator clock, compatible units or detailed navlog produces no line.
    """
    plan = analysis.dispatch
    takeoff = next((event for event in analysis.events if event.phase == "takeoff"), None)
    if plan is None or takeoff is None or attribute not in {"altitude_m", "fuel_weight_kg"}:
        return []
    timed = [sample for sample in analysis.samples if sample.simulator_elapsed_seconds is not None]
    if len(timed) < 2:
        return []
    anchor_time = None
    for left, right in zip(timed, timed[1:]):
        if left.session_elapsed_seconds <= takeoff.elapsed_seconds <= right.session_elapsed_seconds:
            host_span = right.session_elapsed_seconds - left.session_elapsed_seconds
            if (
                host_span <= 0 or host_span > PROFILE_MAX_BRIDGE_SECONDS
                or right.discontinuity_before
                or right.simulator_elapsed_seconds <= left.simulator_elapsed_seconds
            ):
                continue
            fraction = (takeoff.elapsed_seconds - left.session_elapsed_seconds) / host_span
            anchor_time = left.simulator_elapsed_seconds + fraction * (
                right.simulator_elapsed_seconds - left.simulator_elapsed_seconds)
            break
    if anchor_time is None:
        return []
    if attribute == "fuel_weight_kg" and (
        plan.units.lower() not in {"kg", "kgs", "lb", "lbs"}
        or unit.rstrip(" *") not in {"kg", "lb"}
    ):
        return []
    points: list[tuple[float | None, float | None, bool]] = []
    previous_time = -1.0
    previous_host = None
    for fix in plan.points:
        value = fix.altitude_ft if attribute == "altitude_m" else fix.fuel_onboard
        if fix.elapsed_seconds is None or value is None:
            points.append((None, None, True))
            continue
        if fix.elapsed_seconds < previous_time:
            points.append((None, None, True))
            continue
        previous_time = fix.elapsed_seconds
        target = anchor_time + fix.elapsed_seconds
        host_time = None
        for left, right in zip(timed, timed[1:]):
            if not left.simulator_elapsed_seconds <= target <= right.simulator_elapsed_seconds:
                continue
            if right.session_elapsed_seconds < takeoff.elapsed_seconds:
                continue
            span = right.simulator_elapsed_seconds - left.simulator_elapsed_seconds
            if span <= 0 or right.discontinuity_before:
                continue
            if right.session_elapsed_seconds - left.session_elapsed_seconds > PROFILE_MAX_BRIDGE_SECONDS:
                continue
            fraction = (target - left.simulator_elapsed_seconds) / span
            host_time = left.session_elapsed_seconds + fraction * (
                right.session_elapsed_seconds - left.session_elapsed_seconds)
            break
        if host_time is None:
            points.append((None, None, True))
            continue
        if previous_host is not None and any(
            sample.discontinuity_before
            and previous_host < sample.session_elapsed_seconds <= host_time
            for sample in timed
        ):
            points.append((None, None, True))
        previous_host = host_time
        if attribute == "altitude_m":
            displayed = value / METRES_TO_FEET * scale
        else:
            kilograms = value / POUNDS_PER_KG if plan.units.lower() in {"lb", "lbs"} else value
            displayed = kilograms * scale
        points.append((host_time, displayed, False))
    return points

def _dispatch_position_series(
    analysis: FlightAnalysis, attribute: str, scale: float, unit: str,
) -> list[tuple[float | None, float | None, bool]]:
    """Place planned fix values where the recorded trajectory passed nearby.

    Args:
        analysis (FlightAnalysis): Captured positions and attached timed OFP.
        attribute (str): Supported altitude_m or fuel_weight_kg profile field.
        scale (float): Recorded SI-to-displayed-unit multiplier.
        unit (str): Display unit; fuel requires compatible known kg/lb mass.
    Returns:
        list[tuple[float | None, float | None, bool]]: Captured host seconds,
            converted planned values and breaks. At least two distinct matched
            positions are required; absent or distant matches return no series.
            This compares at route positions and does not infer a takeoff time.
    """
    plan = analysis.dispatch
    if plan is None or attribute not in {"altitude_m", "fuel_weight_kg"}:
        return []
    if attribute == "fuel_weight_kg" and (
        plan.units.lower() not in {"kg", "kgs", "lb", "lbs"}
        or unit.rstrip(" *") not in {"kg", "lb"}
    ):
        return []
    legs: list[tuple[FlightSample, FlightSample, int]] = []
    block = 0
    for left, right in zip(analysis.samples, analysis.samples[1:]):
        span = right.session_elapsed_seconds - left.session_elapsed_seconds
        distance = _distance_nm(left, right)
        if (
            right.discontinuity_before or not 0 < span <= PROFILE_MAX_BRIDGE_SECONDS
            or distance is None or distance >= REPORT_POSITION_JUMP_NM
        ):
            # Reuse captured-route jump exclusion; never match across a gap.
            block += 1
            continue
        legs.append((left, right, block))
    points: list[tuple[float | None, float | None, bool]] = []
    previous_host = -1.0
    previous_block = None
    for fix in plan.points:
        value = fix.altitude_ft if attribute == "altitude_m" else fix.fuel_onboard
        if value is None:
            points.append((None, None, True))
            continue
        best: tuple[float, float, int, FlightSample, float, float] | None = None
        cosine = math.cos(math.radians(fix.latitude))
        for left, right, leg_block in legs:
            # Local equirectangular projection selects a nearby point on each
            # short captured leg. Final acceptance uses the geodesic owner.
            ax = (left.longitude_deg - fix.longitude + 180) % 360 - 180
            bx = (right.longitude_deg - fix.longitude + 180) % 360 - 180
            if abs(bx - ax) > 180:
                continue
            ay = left.latitude_deg - fix.latitude
            by = right.latitude_deg - fix.latitude
            dx, dy = (bx - ax) * cosine, by - ay
            length = dx * dx + dy * dy
            fraction = (
                max(0.0, min(1.0, -(ax * cosine * dx + ay * dy) / length))
                if length else 0.0
            )
            host = left.session_elapsed_seconds + fraction * (
                right.session_elapsed_seconds - left.session_elapsed_seconds)
            if host <= previous_host:
                continue
            squared = (ax * cosine + fraction * dx) ** 2 + (ay + fraction * dy) ** 2
            if best is None or squared < best[0]:
                latitude = left.latitude_deg + fraction * (
                    right.latitude_deg - left.latitude_deg)
                longitude = (
                    fix.longitude + ax + fraction * (bx - ax) + 180
                ) % 360 - 180
                best = (squared, host, leg_block, left, latitude, longitude)
        if best is None:
            points.append((None, None, True))
            continue
        _squared, host, leg_block, source, latitude, longitude = best
        separation = _distance_nm(
            replace(source, latitude_deg=latitude, longitude_deg=longitude),
            replace(source, latitude_deg=fix.latitude, longitude_deg=fix.longitude),
        )
        if separation is None or separation > DISPATCH_POSITION_MATCH_NM:
            points.append((None, None, True))
            continue
        if previous_block is not None and leg_block != previous_block:
            points.append((None, None, True))
        previous_host, previous_block = host, leg_block
        if attribute == "altitude_m":
            displayed = value / METRES_TO_FEET * scale
        else:
            kilograms = (
                value / POUNDS_PER_KG
                if plan.units.lower() in {"lb", "lbs"} else value
            )
            displayed = kilograms * scale
        points.append((host, displayed, False))
    # A comparison line needs two distinct matched positions, rather than one
    # coincidental airport/fix shared with an otherwise unrelated flight.
    matched = sum(host is not None for host, _value, _gap in points)
    return points if matched >= 2 else []

def _profile_chart(
    analysis: FlightAnalysis,
    attribute: str,
    label: str,
    color: colors.Color,
    scale: float,
    unit: str,
    language: str,
    samples: Optional[Sequence[FlightSample]] = None,
    x_bounds: Optional[Tuple[float, float]] = None,
    zero_time: float = 0.0,
    contact_bounds: Optional[Tuple[float, float]] = None,
    chart_width: float = CHART_WIDTH,
    chart_height: float = CHART_HEIGHT,
) -> Drawing:
    """Draw aviation axes with bounded, explicitly identified plot interpolation.

    Args:
        analysis (FlightAnalysis): Immutable source and full captured duration.
        attribute (str): Numeric sample field for this profile.
        label (str): Localized heading, including AGL/AMSL where appropriate.
        color (colors.Color): Observed-series stroke color.
        scale (float): Conversion from recorded SI to displayed units.
        unit (str): Display unit, including an estimate marker when necessary.
        language (str): Supported label language.
        samples (Optional[Sequence[FlightSample]]): Dedicated landing-window
            observations or None for the bounded full-flight series.
        x_bounds (Optional[Tuple[float, float]]): Shared exact elapsed limits.
        zero_time (float): Elapsed seconds defining contact-relative zero.
        contact_bounds (Optional[Tuple[float, float]]): Recorded contact markers.
        chart_width (float): Drawing width in PDF points.
        chart_height (float): Drawing height in PDF points.

    Returns:
        Drawing: Rounded enclosing Y range, aligned labels, timed grids and
            measured segments and bounded linear bridges; no inferred samples
            enter landing analysis, raw storage or measured extrema. Eligible
            full-flight altitude/fuel profiles also show a separate OFP plan,
            aligned through takeoff/simulator clocks or explicitly labelled
            nearby captured route positions when time alignment is unavailable.
    """
    drawing = Drawing(chart_width, chart_height)
    drawing.add(Rect(0, 4, chart_width - 1, chart_height - 8,
                     fillColor=colors.white, strokeColor=_COLOR_GRID,
                     strokeWidth=0.6, rx=8))
    left, bottom = 61, 37
    width, height = chart_width - 81, chart_height - 79
    suffix = " (" + unit + ")"
    title = label if label.endswith(suffix) else label + suffix
    drawing.add(String(15, chart_height - 23, title,
                       fontName=_FONT_BOLD,
                       fontSize=min(11, (chart_width - 30) / max(pdfmetrics.stringWidth(title, _FONT_BOLD, 1), 1)),
                       fillColor=_COLOR_INK))
    chosen = analysis.samples if samples is None else samples
    points = _chart_series(chosen, attribute)
    valid = [(t, v * scale) for t, v, _gap in points if t is not None and v is not None]
    planned = _dispatch_series(analysis, attribute, scale, unit) if samples is None else []
    positional = False
    # One timed point gives a marker, but a comparison curve needs two points.
    if samples is None and sum(t is not None for t, _v, _gap in planned) < 2:
        positions = _dispatch_position_series(analysis, attribute, scale, unit)
        if positions:
            planned, positional = positions, True
    planned_valid = [(t, v) for t, v, _gap in planned if t is not None and v is not None]
    min_x, max_x = x_bounds if x_bounds else (0.0, max(analysis.duration_seconds, 1.0))
    if max_x <= min_x:
        max_x = min_x + 1.0
    low, high, minor, major = _axis_limits(
        min((v for _t, v in valid + planned_valid), default=0.0),
        max((v for _t, v in valid + planned_valid), default=1.0), attribute,
    )
    count = int(round((high - low) / minor))
    for index in range(count + 1):
        value = low + index * minor
        y = bottom + (value - low) / (high - low) * height
        emphasized = math.isclose(value / major, round(value / major), abs_tol=AXIS_EPSILON)
        drawing.add(Line(left, y, left + width, y,
                         strokeColor=_COLOR_GRID if emphasized else _COLOR_MINOR_GRID,
                         strokeWidth=0.5 if emphasized else 0.25))
        # Label the enclosing limits; omit nearby major labels that would
        # collide with them in this compact chart geometry (twelve PDF points).
        if index in (0, count) or (emphasized and min(y - bottom, bottom + height - y) >= 12):
            decimals = max(0, -math.floor(math.log10(minor)))
            text = format(value, "." + str(decimals) + "f")
            if language != "en":
                text = text.replace(".", ",")
            drawing.add(String(left - 6, y - 2, text, textAnchor="end",
                               fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    span = max_x - min_x
    if contact_bounds is not None:
        time_minor = _nice_step(span / CONTACT_TIME_DIVISIONS)
        time_major = time_minor * AXIS_MAJOR_MULTIPLE
    elif span >= SHORT_FLIGHT_SECONDS:
        time_minor = max(TIME_GRID_SECONDS, _nice_step(span / MAX_GRID_LINES))
        time_major = HOUR_SECONDS if span >= HOUR_SECONDS else TIME_GRID_SECONDS
    else:
        time_minor = _nice_step(span / CONTACT_TIME_DIVISIONS)
        time_major = time_minor * AXIS_MAJOR_MULTIPLE
    first = math.ceil((min_x - zero_time) / time_minor)
    last = math.floor((max_x - zero_time) / time_minor)
    for index in range(first, last + 1):
        relative = index * time_minor
        x = left + (relative + zero_time - min_x) / span * width
        emphasized = math.isclose(relative / time_major, round(relative / time_major), abs_tol=AXIS_EPSILON)
        drawing.add(Line(x, bottom, x, bottom + height,
                         strokeColor=_COLOR_GRID if emphasized else _COLOR_MINOR_GRID,
                         strokeWidth=0.5 if emphasized else 0.25))
        if emphasized:
            if contact_bounds is None and span >= HOUR_SECONDS:
                text = "{:d}:{:02d}".format(int(relative // HOUR_SECONDS), int(relative % HOUR_SECONDS // 60))
            else:
                text = _relative_time(relative + zero_time, zero_time)
            drawing.add(String(x, bottom - 15, text, textAnchor="middle",
                               fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    drawing.add(String(left + width / 2, 9,
                       tr(language, "contact_relative" if contact_bounds else "elapsed"),
                       textAnchor="middle", fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    drawing.add(Line(left, bottom, left, bottom + height, strokeColor=_COLOR_MUTED, strokeWidth=0.5))
    drawing.add(Line(left, bottom, left + width, bottom, strokeColor=_COLOR_MUTED, strokeWidth=0.5))
    if contact_bounds:
        for elapsed in dict.fromkeys(contact_bounds):
            if min_x <= elapsed <= max_x:
                x = left + (elapsed - min_x) / span * width
                drawing.add(Line(x, bottom, x, bottom + height, strokeColor=_COLOR_RED, strokeWidth=0.8, strokeDashArray=[3, 3]))
    segment: List[float] = []
    prior: Optional[Tuple[float, float, float]] = None
    missing, hard_break = False, False
    for sample in chosen:
        elapsed, value = sample.session_elapsed_seconds, getattr(sample, attribute)
        reason = sample.discontinuity_before
        if reason:
            missing = True
            # Only an ordinary clock/cadence gap may be connected. A pause,
            # flight/aircraft reload or teleport must stay an explicit break.
            hard_break = hard_break or reason != "simulator clock gap"
        if value is None or not math.isfinite(value):
            missing = True
            continue
        if not min_x <= elapsed <= max_x:
            continue
        x = left + (elapsed - min_x) / span * width
        y = bottom + (value * scale - low) / (high - low) * height
        if prior and (missing or elapsed <= prior[0]):
            _draw_profile_segment(drawing, segment, color)
            segment = []
            delta = elapsed - prior[0]
            if not hard_break and 0 < delta <= PROFILE_MAX_BRIDGE_SECONDS:
                estimated = delta > PROFILE_SHORT_BRIDGE_SECONDS
                drawing.add(Line(prior[1], prior[2], x, y,
                                 strokeColor=_COLOR_INTERPOLATED if estimated else color,
                                 strokeWidth=1.25,
                                 strokeDashArray=[3, 2] if estimated else None))
        segment.extend([x, y])
        prior = (elapsed, x, y)
        missing, hard_break = False, False
    _draw_profile_segment(drawing, segment, color)
    segment = []
    for elapsed, value, _gap in planned + [(None, None, True)]:
        if elapsed is None or value is None or not min_x <= elapsed <= max_x:
            if len(segment) >= 4:
                drawing.add(PolyLine(segment, strokeColor=_COLOR_PLANNED,
                    strokeWidth=1.6, strokeDashArray=[5, 3]))
            elif len(segment) == 2:
                drawing.add(Circle(*segment, 2, fillColor=_COLOR_PLANNED, strokeColor=None))
            segment = []
            continue
        segment.extend((left + (elapsed - min_x) / span * width,
                        bottom + (value - low) / (high - low) * height))
    if planned_valid:
        legend = "simbrief_position_legend" if positional else "simbrief_overlay_legend"
        drawing.add(String(chart_width - 16, chart_height - 36,
            tr(language, legend), textAnchor="end",
            fontName=_FONT_NAME, fontSize=8, fillColor=_COLOR_MUTED))
    elif samples is None and attribute in {"altitude_m", "fuel_weight_kg"}:
        explanation = (
            "simbrief_comparison_unavailable"
            if analysis.dispatch else "simbrief_not_attached"
        )
        drawing.add(String(chart_width - 16, chart_height - 36,
            tr(language, explanation),
            textAnchor="end", fontName=_FONT_NAME, fontSize=8,
            fillColor=_COLOR_MUTED))
    if not valid:
        drawing.add(String(left + 8, bottom + height / 2, tr(language, "unavailable"),
                           fontName=_FONT_NAME, fontSize=10, fillColor=_COLOR_MUTED))
    return drawing

def _draw_profile_segment(
    drawing: Drawing,
    points: List[float],
    color: colors.Color,
) -> None:
    """Draw one contiguous sensor segment without bridging a missing sample.

    Args:
        drawing (Drawing): Destination vector chart.
        points (List[float]): Alternating x/y coordinates for one segment.
        color (colors.Color): Sensor-series color.

    Returns:
        None: Draw a line for multiple samples or a marker for one sample.
    """
    if len(points) >= 4:
        drawing.add(PolyLine(points, strokeColor=color, strokeWidth=1.6))
    elif len(points) == 2:
        drawing.add(
            Circle(
                points[0],
                points[1],
                1.8,
                fillColor=color,
                strokeColor=None,
            )
        )

def _recorded_positions(analysis: FlightAnalysis) -> list[tuple[float, float] | None]:
    """Prepare measured route geometry with explicit capture and jump breaks.

    Args:
        analysis (FlightAnalysis): Immutable recorded aircraft samples.
    Returns:
        list[tuple[float, float] | None]: Latitude/longitude pairs with None
            separators for missing positions, discontinuities or 50 NM jumps;
            detached jump-and-return blocks are omitted from map geometry only.
    """
    segments: list[list[FlightSample]] = []
    current: list[FlightSample] = []
    previous = None
    for sample in analysis.samples:
        missing = sample.latitude_deg is None or sample.longitude_deg is None
        distance = _distance_nm(previous, sample) if previous is not None and not missing else None
        if (missing or sample.discontinuity_before
                or (distance is not None and distance >= REPORT_POSITION_JUMP_NM)):
            if current:
                segments.append(current)
                current = []
            previous = None
        if not missing:
            current.append(sample)
            previous = sample
    if current:
        segments.append(current)
    points: list[tuple[float, float] | None] = []
    for index, segment in enumerate(segments):
        # Exclude only a detached excursion bounded by a return near its entry.
        # An actual relocation or a remote beginning/end stays visible. Original
        # samples and analysis are untouched; no line bridges the omitted block.
        if 0 < index < len(segments) - 1:
            before, after = segments[index - 1][-1], segments[index + 1][0]
            return_distance = _distance_nm(before, after)
            if (return_distance is not None and return_distance < REPORT_POSITION_JUMP_NM
                    and all(
                        (_distance_nm(before, sample) or 0.0) >= REPORT_POSITION_JUMP_NM
                        and (_distance_nm(after, sample) or 0.0) >= REPORT_POSITION_JUMP_NM
                        for sample in segment
                    )):
                points.append(None)
                continue
        if points:
            points.append(None)
        points.extend((sample.latitude_deg, sample.longitude_deg) for sample in segment
                      if sample.latitude_deg is not None and sample.longitude_deg is not None)
    return points

def _report_map_image(
    analysis: FlightAnalysis, cache: Path | None,
    chart_width: float, chart_height: float, light: bool,
) -> bytes:
    """Compose one language-independent map using the report's exact geometry.

    Args:
        analysis (FlightAnalysis): Recorded and optional planned positions.
        cache (Path | None): Existing private tile cache or offline mode.
        chart_width (float): Map panel width in PDF points.
        chart_height (float): Map panel height in PDF points.
        light (bool): Whether to apply the existing overview background fade.
    Returns:
        bytes: Native-resolution PNG with offline tile status, or empty bytes
            when no valid positions permit the existing map viewport.
    """
    points = _recorded_positions(analysis) + [None]
    if analysis.dispatch:
        points.extend((point.latitude, point.longitude)
                      for point in analysis.dispatch.points)
    return render_map(
        points, cache, size=print_size(chart_width - 24, chart_height - 77),
        fade=MAP_OVERVIEW_FADE if light else 0.0, draw_track=False,
    ) or b""

def prepare_report_maps(
    analysis: FlightAnalysis, directory: Path,
) -> tuple[bytes, bytes]:
    """Prepare both map backgrounds once before independent language rendering.

    Args:
        analysis (FlightAnalysis): Immutable normalized recording and OFP.
        directory (Path): Common PDF output directory owning the tile cache.
    Returns:
        tuple[bytes, bytes]: Overview/full PNGs, empty without valid positions.
            Child renderers never fetch, evict or race on shared tile files.
    """
    cache = directory / ".metadata" / "map-tiles"
    return (
        _report_map_image(analysis, cache,
            CHART_WIDTH - CHART_WIDTH * OVERVIEW_METRIC_FRACTION - 8, 240, True),
        _report_map_image(analysis, cache, CHART_WIDTH, CHART_WIDTH / 2, False),
    )

def _route_chart(
    analysis: FlightAnalysis, language: str,
    chart_width: float = CHART_WIDTH, chart_height: float = CHART_HEIGHT,
    cache: Path | None = None, *, planned: bool = False, light: bool = True,
    map_image: bytes | None = None,
) -> Drawing:
    """Draw printable OSM backgrounds beneath vector recorded/planned routes.

    Args:
        analysis (FlightAnalysis): Recorded samples and optional OFP geometry.
        language (str): Supported route/legend language.
        chart_width (float): Panel width in PDF points.
        chart_height (float): Panel height in PDF points.
        cache (Path | None): Private tile cache; None uses the offline background.
        planned (bool): Use the comparison-map heading for the full-page view.
        light (bool): Fade the overview basemap so recorded detail stays prominent.
        map_image (bytes | None): Prepared PNG background; None composes it here.
    Returns:
        Drawing: Native 300-dpi tile background with vector tracks, labels and
            attribution; gaps and the international dateline remain explicit.
    """
    drawing = Drawing(chart_width, chart_height)
    drawing.add(Rect(0, 4, chart_width - 1, chart_height - 8,
                     fillColor=colors.white, strokeColor=_COLOR_GRID,
                     strokeWidth=0.6, rx=8))
    title = tr(language, "simbrief_compare_route" if planned else "aircraft_track")
    drawing.add(String(15, chart_height - 23, title,
        fontName=_FONT_BOLD, fontSize=11, fillColor=_COLOR_INK))
    recorded = _recorded_positions(analysis)
    omitted_positions = sum(
        sample.latitude_deg is not None and sample.longitude_deg is not None
        for sample in analysis.samples
    ) - sum(point is not None for point in recorded)
    dispatch = [(p.latitude, p.longitude) for p in analysis.dispatch.points] if analysis.dispatch else []
    points = recorded + [None] + dispatch
    left, bottom = 12, 38
    width, height = chart_width - 24, chart_height - 77
    size = print_size(width, height)
    viewport = map_viewport(points, size)
    if viewport is None:
        drawing.add(String(left, bottom + height / 2, tr(language, "unavailable"),
            fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
        return drawing
    image = map_image if map_image is not None else _report_map_image(
        analysis, cache, chart_width, chart_height, light,
    )
    if image:
        raster = PillowImage.open(io.BytesIO(image))
        raster.load()
        drawing.add(MapShape(left, bottom, width, height, raster))
        if raster.info.get("tile_status") == "unavailable":
            drawing.add(String(left, 28, tr(language, "map_tiles_unavailable"),
                fontName=_FONT_NAME, fontSize=7, fillColor=_COLOR_MUTED))
    if omitted_positions:
        drawing.add(String(left, 5, tr(language, "map_positions_omitted"),
            fontName=_FONT_NAME, fontSize=7, fillColor=_COLOR_MUTED))
    drawing.add(String(left, 16,
        "© OpenStreetMap contributors · openstreetmap.org/copyright",
        fontName=_FONT_NAME, fontSize=7, fillColor=_COLOR_MUTED))
    recorded_count = len(recorded)
    split = recorded_count + 1
    for subset, color, dashed in (
        (viewport.points[split:], _COLOR_PLANNED, True),
        (viewport.points[:recorded_count], _COLOR_BLUE, False),
    ):
        segment: List[float] = []
        for point in list(subset) + [None]:
            if point is None:
                if len(segment) >= 4:
                    drawing.add(PolyLine(segment, strokeColor=color,
                        strokeWidth=1.7, strokeDashArray=[5, 3] if dashed else None))
                elif len(segment) == 2:
                    drawing.add(Circle(*segment, 2, fillColor=color, strokeColor=None))
                segment = []
                continue
            x = left + (point[0] * viewport.world - viewport.left) / size[0] * width
            y = bottom + height - (point[1] * viewport.world - viewport.top) / size[1] * height
            segment.extend((x, y))
    actual = [point for point in viewport.points[:recorded_count] if point is not None]
    if analysis.dispatch and analysis.comparison and analysis.comparison.route_accepted:
        outside = route_excursions(analysis.dispatch, recorded)
        map_points = viewport.points[:recorded_count]
        for index in range(1, min(len(map_points), len(outside))):
            first, last = map_points[index - 1:index + 1]
            if first is not None and last is not None and outside[index - 1] and outside[index]:
                drawing.add(Line(
                    left + (first[0] * viewport.world - viewport.left) / size[0] * width,
                    bottom + height - (first[1] * viewport.world - viewport.top) / size[1] * height,
                    left + (last[0] * viewport.world - viewport.left) / size[0] * width,
                    bottom + height - (last[1] * viewport.world - viewport.top) / size[1] * height,
                    strokeColor=_COLOR_INTERPOLATED, strokeWidth=2.2,
                ))
        if any(outside):
            drawing.add(String(left, chart_height - 36, tr(language, "cmp_route_highlight"),
                fontName=_FONT_NAME, fontSize=7, fillColor=_COLOR_INTERPOLATED))
    for point, color in ((actual[0], _COLOR_GREEN), (actual[-1], _COLOR_INK)) if actual else ():
        x = left + (point[0] * viewport.world - viewport.left) / size[0] * width
        y = bottom + height - (point[1] * viewport.world - viewport.top) / size[1] * height
        drawing.add(Circle(x, y, 3, fillColor=color, strokeColor=colors.white, strokeWidth=0.5))
    planned_points = [point for point in viewport.points[split:] if point is not None]
    if planned_points and analysis.dispatch:
        for point, code in ((planned_points[0], analysis.dispatch.origin),
                            (planned_points[-1], analysis.dispatch.destination)):
            x = left + (point[0] * viewport.world - viewport.left) / size[0] * width
            y = bottom + height - (point[1] * viewport.world - viewport.top) / size[1] * height
            drawing.add(String(x + 5, y + 5, code, fontName=_FONT_BOLD,
                fontSize=8, fillColor=_COLOR_PLANNED))
    drawing.add(String(chart_width - 14, chart_height - 36,
        tr(language, "recorded_short") + (" / " + tr(language, "planned_short") if dispatch else ""),
        textAnchor="end", fontName=_FONT_NAME, fontSize=7, fillColor=_COLOR_MUTED))
    return drawing

def _draw_route_segment(drawing: Drawing, points: List[float]) -> None:
    """Draw one contiguous segment of directly recorded aircraft positions.

    Args:
        drawing (Drawing): Destination vector route chart.
        points (List[float]): Alternating x/y coordinates of valid positions.

    Returns:
        None: Draw a line for multiple positions or a marker for one position.
    """
    if len(points) >= 4:
        drawing.add(PolyLine(points, strokeColor=_COLOR_BLUE, strokeWidth=1.8))
    elif len(points) == 2:
        drawing.add(
            Circle(
                points[0],
                points[1],
                1.8,
                fillColor=_COLOR_BLUE,
                strokeColor=None,
            )
        )

def _dispatch_pages(analysis: FlightAnalysis, language: str) -> List[Flowable]:
    """Present optional dispatch identity, loading, schedule and fuel planning.

    Args:
        analysis (FlightAnalysis): Recorded analysis with optional planned OFP data.
        language (str): Supported report edition.
    Returns:
        List[Flowable]: Two factual planning pages, or no pages without an OFP;
            missing values remain unknown and never become observed quantities.
    """
    plan = analysis.dispatch
    if plan is None:
        return []
    styles = _styles()
    unknown = tr(language, "unavailable")

    def value(text: str, unit: str = "") -> str:
        """Format a finite planned scalar without converting absent data to zero.

        Args:
            text (str): Normalized optional provider number.
            unit (str): Explicit original or declared display unit.
        Returns:
            str: Localized value/unit, or the unknown label for missing data.
        """
        try:
            numeric = float(text)
            if not math.isfinite(numeric) or numeric < 0:
                return unknown
            formatted = str(int(numeric)) if numeric.is_integer() else _number(numeric, language)
            return formatted + (" " + unit if unit else "")
        except ValueError:
            return unknown

    def duration(text: str) -> str:
        """Format provider duration seconds without inventing an estimate.

        Args:
            text (str): Optional provider duration in seconds.
        Returns:
            str: Duration label or unknown when the source has no valid duration.
        """
        try:
            seconds = float(text)
            return _format_duration(seconds, language) if math.isfinite(seconds) and seconds >= 0 else unknown
        except ValueError:
            return unknown

    def scheduled_time(text: str) -> str:
        """Show a normalized OFP schedule without technical ISO suffixes.

        Args:
            text (str): Optional timezone-aware ISO schedule value.
        Returns:
            str: Human-readable UTC date and minute, or the unknown label.
        """
        try:
            stamp = datetime.fromisoformat(text.replace("Z", "+00:00"))
            return stamp.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M") if stamp.tzinfo else unknown
        except ValueError:
            return unknown

    unit = {"kgs": "kg", "kg": "kg", "lbs": "lb", "lb": "lb"}.get(plan.units.lower(), "")
    weight = lambda text: value(text, unit) if unit else unknown
    identity = plan.flight_number or plan.callsign or unknown
    route = plan.origin + " → " + plan.destination
    story: List[Flowable] = [
        PageBreak(), _paragraph(tr(language, "simbrief_dispatch_title"), styles["title"]),
        _paragraph(_safe_source_text(identity + " · " + route), styles["subtitle"]),
        _paragraph(_safe_source_text((plan.origin_name or plan.origin) + " → "
            + (plan.destination_name or plan.destination)), styles["small"]),
        _visual_metrics([
            (tr(language, "simbrief_passengers"), value(plan.passengers)),
            (tr(language, "simbrief_cargo"), weight(plan.cargo)),
            (tr(language, "simbrief_payload"), weight(plan.payload)),
            (tr(language, "simbrief_distance"), value(plan.distance_nm, "NM")),
            (tr(language, "simbrief_duration"), duration(plan.duration_seconds)),
            (tr(language, "simbrief_cruise"), value(plan.cruise_altitude_ft, "ft AMSL")),
            (tr(language, "simbrief_registration"), _safe_source_text(plan.registration or unknown)),
            (tr(language, "simbrief_callsign"), _safe_source_text(plan.callsign or identity)),
            (tr(language, "simbrief_cost_index"), value(plan.cost_index)),
            (tr(language, "simbrief_runways"), _safe_source_text((plan.origin_runway or "—") + " → " + (plan.destination_runway or "—"))),
            (tr(language, "simbrief_alternate"), _safe_source_text(plan.alternate or unknown)),
            ("AIRAC", _safe_source_text(plan.airac or unknown)),
        ], language),
        _paragraph(tr(language, "simbrief_schedule") + ": "
            + scheduled_time(plan.scheduled_departure_utc) + " → "
            + scheduled_time(plan.scheduled_arrival_utc), styles["small"]),
        _paragraph(tr(language, "simbrief_evidence"), styles["small"]),
        PageBreak(), _paragraph(tr(language, "simbrief_fuel_load_title"), styles["title"]),
        _visual_metrics([
            (tr(language, "simbrief_fuel"), weight(plan.fuel)),
            (tr(language, "simbrief_trip_fuel"), weight(plan.fuel_trip)),
            (tr(language, "simbrief_taxi_fuel"), weight(plan.fuel_taxi)),
            (tr(language, "simbrief_reserve_fuel"), weight(plan.fuel_reserve)),
            (tr(language, "simbrief_contingency"), weight(plan.fuel_contingency)),
            (tr(language, "simbrief_zfw"), weight(plan.zero_fuel_weight)),
            (tr(language, "simbrief_tow"), weight(plan.takeoff_weight)),
            (tr(language, "simbrief_ldw"), weight(plan.landing_weight)),
            (tr(language, "simbrief_aircraft"), _safe_source_text(plan.aircraft or unknown)),
        ], language),
        _paragraph(tr(language, "simbrief_alignment_note"), styles["small"]),
        _paragraph(tr(language, "simbrief_evidence"), styles["small"]),
        _paragraph(tr(language, "simbrief_route_text"), styles["heading"]),
        _paragraph(_safe_source_text(plan.route or unknown), styles["small"]),
    ]
    return story

def _map_pages(
    analysis: FlightAnalysis, language: str, cache: Path,
    map_images: tuple[bytes, bytes] | None = None,
) -> List[Flowable]:
    """Finish with a print-quality vector route comparison after dispatch pages.

    Args:
        analysis (FlightAnalysis): Recorded trajectory and optional OFP route.
        language (str): Supported report language.
        cache (Path): Private native-tile cache shared by all editions.
        map_images (tuple[bytes, bytes] | None): Prepared overview/full PNGs;
            None keeps standalone map composition.
    Returns:
        List[Flowable]: Optional planning pages followed by the full-width map.
    """
    story = _dispatch_pages(analysis, language)
    story.extend([
        PageBreak(),
        _route_chart(analysis, language, CHART_WIDTH, CHART_WIDTH / 2,
                     cache, planned=analysis.dispatch is not None, light=False,
                     map_image=map_images[1] if map_images else None),
    ])
    return story
