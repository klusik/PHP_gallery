# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/reports/graphics.py
# Module Type: Flight report graphics owner
# Purpose: Own graphics behavior for multilingual postflight PDF reports.
# Responsibilities: Preserve report rendering behavior behind explicit owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own the graphics domain of flight report generation."""

import math
from dataclasses import replace
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Tuple


from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.platypus import Flowable, PageBreak, Spacer, Table, TableStyle
from reportlab.graphics.shapes import Circle, Drawing, Line, Polygon, Rect, String

from uploader.flight_data import MAX_DESCENT_FIT_SECONDS, FlightAnalysis, FlightSample, Landing
from uploader.flight_report_translations import tr

from .policy import (
    ASSUMED_KG_PER_US_GALLON,
    CHART_WIDTH,
    FEET_PER_MINUTE_PER_MPS,
    KNOTS_PER_MPS,
    LANDING_CADENCE_WINDOW_SECONDS,
    LANDING_CHART_HEIGHT,
    MAX_CONFIGURATION_EVENTS,
    METRES_PER_NM,
    METRES_TO_FEET,
    METRIC_COLUMNS,
    OVERVIEW_METRIC_FRACTION,
    PHASE_COLUMNS,
    PHASE_ROW_HEIGHT,
    POUNDS_PER_KG,
    REPORT_FRAME_PADDING,
    _COLOR_BLUE,
    _COLOR_GREEN,
    _COLOR_GRID,
    _COLOR_INK,
    _COLOR_INTERPOLATED,
    _COLOR_MUTED,
    _COLOR_RED,
    _FONT_BOLD,
    _FONT_NAME,
)

from .landing import (
    _landing_samples,
    _translate_confidence,
    _translate_phase_event,
)

from .presentation import (
    _format_duration,
    _format_speed,
    _number,
    _panel_row,
    _paragraph,
    _plane_icon,
    _precise_elapsed,
    _recorded_flight_date,
    _safe_source_text,
    _styles,
    _visual_metrics,
)

from .route import (
    _profile_chart,
    _route_chart,
)


def _phase_infographic(
    analysis: FlightAnalysis, language: str, heading_style: ParagraphStyle,
) -> Table:
    """Lay out the timeline in rows that can continue onto later pages.

    Args:
        analysis (FlightAnalysis): Original phase changes; repeated phases remain.
        language (str): Supported localized labels and elapsed times.
        heading_style (ParagraphStyle): Style for the repeated section heading.

    Returns:
        Table: Localized heading and separate vector rows with up to fourteen
            milestones each, or an explicit empty row when no phases are known.
    """
    events = [event for event in analysis.events
              if event.phase not in {"unknown", "discontinuity", "ended"}]
    columns = min(PHASE_COLUMNS, max(1, len(events)))
    timeline_width = CHART_WIDTH - 2 * REPORT_FRAME_PADDING
    width = timeline_width / columns
    rows: list[list[Flowable]] = [
        [_paragraph(tr(language, "phase_timeline"), heading_style)],
    ]
    for start in range(0, max(1, len(events)), columns):
        row_events = events[start:start + columns]
        drawing = Drawing(timeline_width, PHASE_ROW_HEIGHT)
        for index, event in enumerate(row_events):
            x = (index + 0.5) * width
            _plane_icon(drawing, x, 40, event.phase, _COLOR_BLUE, size=0.6)
            key = "strip_" + event.phase
            label = tr(language, key)
            if label == key:
                label = _translate_phase_event(event.phase, language)
            label_size = min(8.5, (width - 2) / max(
                pdfmetrics.stringWidth(label, _FONT_NAME, 1), 1,
            ))
            drawing.add(String(x, 21, label, textAnchor="middle",
                               fontName=_FONT_NAME, fontSize=label_size,
                               fillColor=_COLOR_INK))
            drawing.add(String(x, 6, _format_duration(event.elapsed_seconds, language),
                               textAnchor="middle", fontName=_FONT_NAME,
                               fontSize=8, fillColor=_COLOR_MUTED))
            if index < len(row_events) - 1:
                drawing.add(Line(x + 9, 40, x + width - 9, 40,
                                 strokeColor=_COLOR_GRID, strokeWidth=0.5))
        if not row_events:
            drawing.add(String(0, 15, tr(language, "no_phase_events"),
                               fontName=_FONT_NAME, fontSize=8,
                               fillColor=_COLOR_MUTED))
        rows.append([drawing])
    # Repeat the single heading row and leave each drawing row indivisible.
    table = Table(rows, colWidths=[timeline_width], repeatRows=1, hAlign="LEFT",
                  spaceBefore=heading_style.spaceBefore)
    table.setStyle(TableStyle([
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (0, 0), heading_style.spaceAfter),
    ]))
    return table

def _fuel_profile(analysis: FlightAnalysis) -> Tuple[FlightAnalysis, str, float, str, bool]:
    """Select aircraft-aware fuel units while separating measured and estimated mass.

    Args:
        analysis (FlightAnalysis): Recorded aircraft identity and optional fuel data.

    Returns:
        Tuple[FlightAnalysis, str, float, str, bool]: Display samples, numeric
            field, unit scale, unit label and whether a density assumption was
            needed. Raw analysis and telemetry are never modified.
    """
    aircraft = (analysis.aircraft or "").lower()
    if any(name in aircraft for name in ("cessna", "piper", "beech")):
        return analysis, "fuel_gallons", 1.0, "US gal", False
    unit = "lb" if any(name in aircraft for name in ("boeing", "pmdg", "737", "747", "757", "767", "777", "787")) else "kg"
    estimated = any(sample.fuel_weight_kg is None and sample.fuel_gallons is not None for sample in analysis.samples)
    samples = [replace(sample, fuel_weight_kg=(
        sample.fuel_weight_kg if sample.fuel_weight_kg is not None else
        sample.fuel_gallons * ASSUMED_KG_PER_US_GALLON if sample.fuel_gallons is not None else None
    )) for sample in analysis.samples]
    return replace(analysis, samples=samples), "fuel_weight_kg", (POUNDS_PER_KG if unit == "lb" else 1.0), unit, estimated

def _configuration_graphic(sample: Optional[FlightSample], language: str) -> Drawing:
    """Show touchdown gear, flap, autopilot and lights in two readable landscape lines.

    Args:
        sample (Optional[FlightSample]): First retained contact, or None.
        language (str): Supported state and sensor labels.

    Returns:
        Drawing: Sixty-four-point configuration strip with compact light indicators.
    """
    drawing = Drawing(CHART_WIDTH, 64)
    drawing.add(Rect(0, 3, CHART_WIDTH - 1, 58, fillColor=colors.white,
                     strokeColor=_COLOR_GRID, strokeWidth=0.6, rx=8))
    for index, (attribute, label) in enumerate((("gear_position", "gear"), ("flaps_position", "flaps"), ("autopilot_master", "autopilot"))):
        x = index * CHART_WIDTH / METRIC_COLUMNS
        value = getattr(sample, attribute, None)
        text = tr(language, "unavailable") if value is None else (
            tr(language, "state_on" if value else "state_off") if isinstance(value, bool)
            else _number(value, language) + "%"
        )
        drawing.add(Circle(x + 15, 42, 3, fillColor=_COLOR_GREEN if value else _COLOR_GRID, strokeColor=None))
        drawing.add(String(x + 24, 38, tr(language, label) + "  " + text,
                           fontName=_FONT_NAME, fontSize=10, fillColor=_COLOR_INK))
    lights = ("beacon", "landing", "nav", "strobe", "taxi", "wing")
    for index, name in enumerate(lights):
        x = index * CHART_WIDTH / len(lights)
        state = sample.lights.get(name) if sample else None
        drawing.add(Circle(x + 15, 18, 2.5, fillColor=_COLOR_GREEN if state else _COLOR_GRID, strokeColor=None))
        drawing.add(String(x + 24, 15, name.upper() + (" ?" if state is None else ""),
                           fontName=_FONT_NAME, fontSize=8.5, fillColor=_COLOR_MUTED))
    return drawing

def _configuration_events(
    samples: Sequence[FlightSample], landing: Landing, language: str,
) -> Drawing:
    """Summarize observed configuration changes in compact three-column lines.

    Args:
        samples (Sequence[FlightSample]): Ordered approach/rollout observations.
        landing (Landing): Contact reference for signed event timestamps.
        language (str): Supported configuration labels.

    Returns:
        Drawing: Observed ground, gear, flap and grouped light transitions;
            first/last entries and displayed/total counts bound noisy captures.
    """
    events: List[Tuple[float, str, str]] = []
    for prior, sample in zip(samples, samples[1:]):
        if sample.discontinuity_before:
            continue
        for attribute, label in (("gear_position", "gear"), ("flaps_position", "flaps")):
            value, previous = getattr(sample, attribute), getattr(prior, attribute)
            if value is not None and previous is not None and value != previous:
                events.append((sample.session_elapsed_seconds, tr(language, label), _number(value, language) + "%"))
        if sample.on_ground is not None and prior.on_ground is not None and sample.on_ground != prior.on_ground:
            events.append((sample.session_elapsed_seconds, tr(language, "on_ground") if sample.on_ground else tr(language, "airborne"), ""))
        lights = [name for name in ("landing", "strobe", "taxi") if sample.lights.get(name) is not None and prior.lights.get(name) is not None and sample.lights[name] != prior.lights[name]]
        if lights:
            value = " / ".join(name.upper() + (" +" if sample.lights[name] else " -") for name in lights)
            events.append((sample.session_elapsed_seconds, tr(language, "light"), value))
    half = MAX_CONFIGURATION_EVENTS // 2
    shown = events if len(events) <= MAX_CONFIGURATION_EVENTS else events[:half] + events[-half:]
    # Three concise text cells per line keep this secondary information small.
    columns = METRIC_COLUMNS
    rows = max(1, math.ceil(len(shown) / columns))
    drawing = Drawing(CHART_WIDTH, 22 + rows * 17)
    drawing.add(String(0, drawing.height - 10, str(len(shown)) + "/" + str(len(events)),
                       fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    for index, (elapsed, label, value) in enumerate(shown):
        text = _precise_elapsed(elapsed, landing.first_contact_elapsed_seconds, language) + " · " + label + " " + value
        width = CHART_WIDTH / columns
        font_size = min(9, (width - 8) / max(pdfmetrics.stringWidth(text, _FONT_NAME, 1), 1))
        drawing.add(String(index % columns * width, drawing.height - 28 - index // columns * 17,
                           text, fontName=_FONT_NAME, fontSize=font_size, fillColor=_COLOR_MUTED))
    return drawing

def _wind_graphic(
    sample: Optional[FlightSample], language: str,
    panel_width: float = CHART_WIDTH,
) -> Drawing:
    """Present the recorded wind/heading comparison in a readable compass card.

    Args:
        sample (Optional[FlightSample]): First-contact telemetry, or None.
        language (str): Supported wind/component and heading labels.
        panel_width (float): Available sidebar width in PDF points.

    Returns:
        Drawing: The same recorded true-heading compass, HDG/KT wind and
            heading-relative components, with no runway or measurement changes.
    """
    drawing = Drawing(panel_width, 220)
    drawing.add(Rect(1, 4, panel_width - 3, 212, fillColor=colors.white,
                     strokeColor=_COLOR_GRID, strokeWidth=0.6, rx=8))
    available = tr(language, "unavailable")
    heading = sample.heading_deg if sample else None
    direction = sample.wind_direction_deg if sample else None
    velocity = sample.wind_velocity_mps if sample else None
    speed = velocity * KNOTS_PER_MPS if velocity is not None else None
    wind = f"{round(direction) % 360:03d}/{round(speed):02d} kt" if direction is not None and speed is not None else available
    drawing.add(String(16, 197, tr(language, "wind_standard"),
                       fontName=_FONT_NAME, fontSize=10, fillColor=_COLOR_MUTED))
    drawing.add(String(16, 172, wind, fontName=_FONT_BOLD,
                       fontSize=18, fillColor=_COLOR_INK))
    cx, cy = panel_width / 2, 123
    drawing.add(Circle(cx, cy, 32, fillColor=None, strokeColor=_COLOR_GRID, strokeWidth=0.8))
    for angle in range(0, 360, 30):
        radians = math.radians(angle)
        dx, dy = math.sin(radians), math.cos(radians)
        drawing.add(Line(cx + dx * 29, cy + dy * 29, cx + dx * 33, cy + dy * 33,
                         strokeColor=_COLOR_MUTED, strokeWidth=0.6))
        if angle % 90 == 0:
            drawing.add(String(cx + dx * 42, cy - 3 + dy * 42, f"{angle:03d}", textAnchor="middle",
                               fontName=_FONT_NAME, fontSize=8, fillColor=_COLOR_MUTED))
    if heading is not None:
        _plane_icon(drawing, cx, cy, "landing", _COLOR_BLUE, size=0.85,
                    angle_degrees=90 - heading)
    if direction is not None and speed is not None:
        radians = math.radians(direction)
        dx, dy = math.sin(radians), math.cos(radians)
        drawing.add(Line(cx + dx * 29, cy + dy * 29, cx + dx * 12, cy + dy * 12,
                         strokeColor=_COLOR_INTERPOLATED, strokeWidth=1.5))
        drawing.add(Polygon([cx + dx * 9, cy + dy * 9,
                             cx + dx * 15 + dy * 3, cy + dy * 15 - dx * 3,
                             cx + dx * 15 - dy * 3, cy + dy * 15 + dx * 3],
                            fillColor=_COLOR_INTERPOLATED, strokeColor=None))
        if heading is not None:
            relative = math.radians(direction - heading)
            head, cross = speed * math.cos(relative), speed * math.sin(relative)
            drawing.add(String(16, 55, tr(language, "headwind" if head >= 0 else "tailwind")
                               + f"  {abs(head):.0f} kt", fontName=_FONT_NAME,
                               fontSize=10, fillColor=_COLOR_INK))
            drawing.add(String(16, 39, tr(language, "crosswind") + f"  {abs(cross):.0f} kt "
                               + tr(language, "from_right" if cross >= 0 else "from_left"),
                               fontName=_FONT_NAME, fontSize=10, fillColor=_COLOR_INK))
    drawing.add(String(16, 72, tr(language, "aircraft_heading") + "  " +
                       (f"{round(heading) % 360:03d}°T" if heading is not None else available),
                       fontName=_FONT_NAME, fontSize=9.5, fillColor=_COLOR_MUTED))
    if sample:
        drawing.add(String(16, 19, tr(language, "pitch") + " " + _number(sample.pitch_deg, language)
                           + "° · " + tr(language, "bank") + " " + _number(sample.bank_deg, language) + "°",
                           fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    return drawing

def _landing_visuals(
    analysis: FlightAnalysis, landing: Landing,
    language: str, styles: Dict[str, ParagraphStyle],
) -> List[Flowable]:
    """Lay out unchanged landing evidence as a summary page and large plot page.

    Args:
        analysis (FlightAnalysis): Immutable overview and retained raw windows.
        landing (Landing): Verified airborne-to-ground transition and evidence.
        language (str): Supported report language.
        styles (Dict[str, ParagraphStyle]): Heading and concise note typography.

    Returns:
        List[Flowable]: Landscape summary and detail pages, separately labeled fitted/measured
            rates, observed cadence, heading/wind compass and paired profiles.
            Missing rates never receive an interpolated or inferred measurement.
    """
    samples, bounds, contacts = _landing_samples(analysis, landing)
    contact = landing.contact_samples[0] if landing.contact_samples else None
    loads = [sample.load_factor_g for sample in samples
             if sample.load_factor_g is not None
             and abs(sample.session_elapsed_seconds - landing.first_contact_elapsed_seconds) <= MAX_DESCENT_FIT_SECONDS]
    rate = _number(landing.descent_rate_fpm, language) + " fpm" if landing.descent_rate_fpm is not None else tr(language, "unavailable")
    observed_rate = (f"{contact.vertical_speed_mps * FEET_PER_MINUTE_PER_MPS:.0f} fpm"
                     if contact and contact.vertical_speed_mps is not None else tr(language, "unavailable"))
    items = [
        (tr(language, "landing_descent"), rate),
        (tr(language, "simulator_contact_vs"), observed_rate),
        (tr(language, "load_factor"), _number(max(loads), language) + " g" if loads else tr(language, "unavailable")),
        (tr(language, "contacts"), str(landing.contact_count)),
        (tr(language, "contact_time"), _format_duration(landing.first_contact_elapsed_seconds, language)),
        (tr(language, "rollout_distance"), _number(landing.rollout_distance_nm * METRES_PER_NM, language) + " m" if landing.rollout_distance_nm is not None else tr(language, "unavailable")),
    ]
    title = tr(language, "landing") + (" " + str(landing.index) if len(analysis.landings) > 1 else "")
    story: List[Flowable] = [
        PageBreak(), _paragraph(title, styles["title"]),
        _paragraph(tr(language, "landing_detected") + " · "
                   + _translate_confidence(landing.confidence, language), styles["small"]),
        Spacer(1, 2 * mm),
    ]
    summary_width = CHART_WIDTH * 2 / 3
    summary: List[Flowable] = [
        _visual_metrics(items, language, panel_width=summary_width - 12),
    ]
    # Report actual pre-contact timing rather than advertise the target as achieved.
    recent = [sample for sample in samples if landing.first_contact_elapsed_seconds - LANDING_CADENCE_WINDOW_SECONDS <= sample.session_elapsed_seconds <= landing.first_contact_elapsed_seconds]
    deltas = [right.simulator_elapsed_seconds - left.simulator_elapsed_seconds
              for left, right in zip(recent, recent[1:])
              if not right.discontinuity_before and left.simulator_elapsed_seconds is not None
              and right.simulator_elapsed_seconds is not None
              and right.simulator_elapsed_seconds > left.simulator_elapsed_seconds]
    if deltas:
        cadence = f"{len(deltas) / sum(deltas):.1f}" + " Hz · " + tr(language, "largest_gap") + f" {max(deltas):.2f} s"
        summary.append(_paragraph(tr(language, "observed_cadence") + " " + cadence, styles["small"]))
    if landing.descent_rate_fpm is None:
        summary.append(_paragraph(tr(language, "landing_fit_missing"), styles["small"]))
    if landing.profile_truncated:
        summary.append(_paragraph(tr(language, "landing_detail_limited"), styles["small"]))
    if not landing.rollout_complete:
        summary.append(_paragraph(tr(language, "rollout_partial"), styles["small"]))
    story.extend([
        _panel_row([summary, _wind_graphic(contact, language, CHART_WIDTH - summary_width)],
                   [summary_width, CHART_WIDTH - summary_width], gap=12),
        Spacer(1, 2 * mm), _configuration_graphic(contact, language),
        _paragraph(tr(language, "configuration_changes"), styles["small"]),
        _configuration_events(samples, landing, language),
        PageBreak(), _paragraph(title + " · " + tr(language, "approach_rollout"), styles["title"]),
    ])
    charts = []
    for attribute, label, scale, unit, color in (
        ("agl_m", "height_agl_ft", METRES_TO_FEET, "ft AGL", _COLOR_BLUE),
        ("vertical_speed_mps", "vertical_speed", FEET_PER_MINUTE_PER_MPS, "fpm", _COLOR_RED),
        ("load_factor_g", "load_factor", 1.0, "g", _COLOR_GREEN),
        ("indicated_airspeed_mps", "indicated_airspeed", KNOTS_PER_MPS, "kt IAS", _COLOR_BLUE),
    ):
        charts.append(_profile_chart(analysis, attribute, tr(language, label), color,
            scale, unit, language, samples, bounds, contacts[0], contacts,
            chart_width=CHART_WIDTH / 2 - 4, chart_height=LANDING_CHART_HEIGHT))
    story.extend([
        _panel_row(charts[:2], [CHART_WIDTH / 2] * 2),
        Spacer(1, 2 * mm),
        _panel_row(charts[2:], [CHART_WIDTH / 2] * 2),
        _paragraph(tr(language, "profile_legend"), styles["small"]),
    ])
    return story

def _build_story(
    analysis: FlightAnalysis, language: str, analysis_sha256: str,
    map_cache: Path | None = None,
    map_images: tuple[bytes, bytes] | None = None,
) -> List[Flowable]:
    """Arrange a compact landscape overview and paired flight profiles.

    Args:
        analysis (FlightAnalysis): Immutable shared flight-analysis model.
        language (str): Supported output language.
        analysis_sha256 (str): Private canonical identity retained in PDF metadata.
        map_cache (Path | None): Private tile directory, or None for offline previews.
        map_images (tuple[bytes, bytes] | None): Prepared overview/full PNGs;
            None keeps standalone rendering behavior.

    Returns:
        List[Flowable]: Wide overview, two profiles per page and landing spreads;
            technical identities and algorithm versions stay outside body prose.
    """
    styles = _styles()
    available = tr(language, "unavailable")
    altitude = max((sample.altitude_m for sample in analysis.samples if sample.altitude_m is not None), default=None)
    speed = max((sample.indicated_airspeed_mps for sample in analysis.samples if sample.indicated_airspeed_mps is not None), default=None)
    story: List[Flowable] = [
        _paragraph(tr(language, "title"), styles["title"]),
        _paragraph(_safe_source_text(analysis.aircraft or available), styles["subtitle"]),
        _paragraph(_recorded_flight_date(analysis, language) + " UTC · " + _safe_source_text(analysis.simulator) + " · " + _safe_source_text(analysis.simulator_build or available), styles["small"]),
        Spacer(1, 2 * mm),
    ]
    metric_width = CHART_WIDTH * OVERVIEW_METRIC_FRACTION
    overview = _visual_metrics([
            (tr(language, "duration_short"), _format_duration(analysis.duration_seconds, language)),
            (tr(language, "distance"), _number(analysis.distance_nm, language) + " NM" if analysis.distance_nm is not None else available),
            (tr(language, "landings"), str(len(analysis.landings))),
            (tr(language, "altitude_amsl"), _number(altitude * METRES_TO_FEET, language) + " ft" if altitude is not None else available),
            (tr(language, "indicated_airspeed"), _format_speed(speed, language)),
            (tr(language, "status"), tr(language, "capture_closed" if analysis.completed else "capture_partial")),
        ], language, panel_width=metric_width - 8, columns=2)
    story.extend([
        _panel_row([
            overview,
            _route_chart(analysis, language, CHART_WIDTH - metric_width - 8,
                240, map_cache, map_image=map_images[0] if map_images else None),
        ], [metric_width, CHART_WIDTH - metric_width]),
        _phase_infographic(analysis, language, styles["heading"]),
        PageBreak(), _paragraph(tr(language, "charts"), styles["title"]),
    ])
    for attribute, label, scale, unit, color in (
        ("altitude_m", "altitude_amsl", METRES_TO_FEET, "ft AMSL", _COLOR_BLUE),
        ("indicated_airspeed_mps", "indicated_airspeed", KNOTS_PER_MPS, "kt IAS", _COLOR_GREEN),
        ("vertical_speed_mps", "vertical_speed", FEET_PER_MINUTE_PER_MPS, "fpm", _COLOR_RED),
    ):
        if attribute == "vertical_speed_mps":
            story.extend([
                _paragraph(tr(language, "profile_legend"), styles["small"]),
                PageBreak(), _paragraph(tr(language, "charts"), styles["title"]),
            ])
        story.append(_profile_chart(analysis, attribute, tr(language, label), color, scale, unit, language))
    fuel, attribute, scale, unit, estimated = _fuel_profile(analysis)
    story.append(_profile_chart(fuel, attribute, tr(language, "fuel_quantity"), _COLOR_BLUE, scale, unit + (" *" if estimated else ""), language))
    story.append(Spacer(1, 2 * mm))
    if estimated:
        story.append(_paragraph(tr(language, "fuel_estimate"), styles["small"]))
    story.append(_paragraph(tr(language, "profile_legend"), styles["small"]))
    for landing in analysis.landings:
        story.extend(_landing_visuals(analysis, landing, language, styles))
    notes = []
    if any(sample.discontinuity_before for sample in analysis.samples):
        notes.append(tr(language, "discontinuity"))
    if not analysis.completed:
        notes.append(tr(language, "estimate"))
    if analysis.warnings:
        notes.append(tr(language, "capture_warning"))
    if notes:
        story.append(_paragraph(" · ".join(notes), styles["small"]))
    return story
