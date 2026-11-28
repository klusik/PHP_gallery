# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/reports/comparison.py
# Module Type: Flight report comparison owner
# Purpose: Own comparison behavior for multilingual postflight PDF reports.
# Responsibilities: Preserve report rendering behavior behind explicit owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own the comparison domain of flight report generation."""

import math
from datetime import datetime, timezone
from typing import List
from xml.sax.saxutils import escape


from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import simpleSplit
from reportlab.platypus import Flowable, Paragraph, PageBreak, Spacer, Table, TableStyle
from reportlab.graphics.shapes import Circle, Drawing, Line, Rect, String

from uploader.flight_data import FlightAnalysis
from uploader.flight_report_translations import tr
from uploader.flight_comparison import ALTITUDE_BAND_FT, ROUTE_BAND_NM, COVERAGE_EPSILON_SECONDS, GRAPH_INTERVAL_SECONDS, WeightedValue, grouped_phases, mass_kg

from .policy import (
    CHART_HEIGHT,
    CHART_WIDTH,
    COMPARISON_CELL_LEADING,
    COMPARISON_CELL_SIZE,
    COMPARISON_CHART_HEIGHT,
    COMPARISON_PANEL_GAP,
    DEVIATION_AXIS_DIVISIONS,
    DEVIATION_HISTOGRAM_BARS,
    DISPLAY_DECIMALS,
    FLIGHTPLAN_IDENT_CHARACTERS,
    POUNDS_PER_KG,
    _COLOR_BLUE,
    _COLOR_GREEN,
    _COLOR_GRID,
    _COLOR_INK,
    _COLOR_MUTED,
    _COLOR_PLANNED,
    _COLOR_RED,
    _COLOR_TEAL,
    _FONT_BOLD,
    _FONT_NAME,
)

from .landing import (
    _translate_phase_event,
)

from .presentation import (
    _axis_limits,
    _format_duration,
    _number,
    _panel_row,
    _paragraph,
    _safe_source_text,
    _styles,
)


def _comparison_value(
    value: float | None, unit: str, language: str, mass_unit: str = "kg",
    signed: bool = False,
) -> str:
    """Format comparison quantities with explicit units and signed differences.

    Args:
        value (float | None): Optional shared analysis scalar.
        unit (str): Canonical s, kg, NM, kt or ft metric unit.
        language (str): Supported report edition.
        mass_unit (str): Display kg or lb, default kilograms.
        signed (bool): Include a plus sign for nonnegative deltas, default false.
    Returns:
        str: Localized value with units, or a compact missing-value dash.
    """
    if value is None:
        return "—"
    prefix = ("+" if value >= 0 else "−") if signed else ("−" if value < 0 else "")
    value = abs(value)
    if unit == "s":
        return prefix + _format_duration(value, language)
    if unit == "kg":
        value *= POUNDS_PER_KG if mass_unit == "lb" else 1.0
        unit = mass_unit
    return prefix + _number(value, language) + " " + unit

def _comparison_table(
    headers: list[str], rows: list[list[str | Paragraph]], widths: list[float],
    language: str, table_width: float = CHART_WIDTH,
) -> Table:
    """Build a readable landscape table with repeating headers and escaped cells.

    Args:
        headers (list[str]): Localized column headings.
        rows (list[list[str | Paragraph]]): Ordered plain text or renderer-owned
            colored paragraphs; plain text remains escaped.
        widths (list[float]): Column width fractions summing to one.
        language (str): Supported report edition used for paragraph styles.
        table_width (float): Available panel width in PDF points; default full page.
    Returns:
        Table: Splittable vector table with wrapped text and repeated headings.
    """
    style = ParagraphStyle("comparison-cell-" + language,
        parent=_styles()["small"], fontSize=COMPARISON_CELL_SIZE,
        leading=COMPARISON_CELL_LEADING)
    data = [[cell if isinstance(cell, Paragraph)
             else _paragraph(_safe_source_text(cell), style)
             for cell in row] for row in [headers, *rows]]
    table = Table(data, colWidths=[table_width * width for width in widths],
                  repeatRows=1, hAlign="LEFT")
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#DDEEF0")),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#F2F6FA")]),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 4),
        ("RIGHTPADDING", (0, 0), (-1, -1), 4),
        ("TOPPADDING", (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ("LINEBELOW", (0, 0), (-1, 0), 0.5, _COLOR_GRID),
    ]))
    return table

def _delta_color(key: str, delta: float | None) -> colors.Color:
    """Apply metric-specific descriptive polarity without scoring the pilot.

    Args:
        key (str): Comparison key or deviation field name.
        delta (float | None): Signed actual-minus-plan quantity.
    Returns:
        colors.Color: Green for lower time/consumption or higher remaining fuel,
            red for their opposite, and neutral ink for unsigned context metrics.
            Altitude/route colors describe the documented comparison bands.
    """
    if delta is None or round(delta, DISPLAY_DECIMALS) == 0:
        return _COLOR_MUTED
    if key in {"altitude_delta_ft", "height"}:
        return _COLOR_GREEN if abs(delta) <= ALTITUDE_BAND_FT else _COLOR_RED
    if key == "route_error_nm":
        return _COLOR_BLUE if delta <= ROUTE_BAND_NM else _COLOR_RED
    higher_is_better = {"landing_fuel", "reserve_margin", "fuel_delta_kg", "fuel_remaining"}
    lower_is_better = {
        "airtime", "distance", "trip_fuel", "block", "taxiout", "taxiin",
        "departure_delay", "arrival_delay", "takeoff_delay", "landing_delay",
        "time_delta_seconds", "burn_delta_kg", "phase_fuel", "phase_time",
    }
    if key in higher_is_better:
        return _COLOR_GREEN if delta > 0 else _COLOR_RED
    if key in lower_is_better:
        return _COLOR_GREEN if delta < 0 else _COLOR_RED
    return _COLOR_INK

def _comparison_cell(parts: list[tuple[str, colors.Color]], language: str) -> Paragraph:
    """Create escaped colored lines with the shared compact table typography.

    Args:
        parts (list[tuple[str, colors.Color]]): Ordered text/color line pairs.
        language (str): Supported report edition for inherited font styles.
    Returns:
        Paragraph: Renderer-owned colored cell; external text is always escaped.
    """

    style = ParagraphStyle("comparison-colored-" + language,
        parent=_styles()["small"], fontSize=COMPARISON_CELL_SIZE,
        leading=COMPARISON_CELL_LEADING)
    markup = "<br/>".join(
        '<font color="' + color.hexval().replace("0x", "#") + '">'
        + escape(_safe_source_text(text)) + "</font>"
        for text, color in parts
    )
    return Paragraph(markup, style)

def _comparison_pair(
    planned: float | None, actual: float | None, unit: str, language: str,
    mass_unit: str, key: str, show_delta: bool = True, actual_suffix: str = "",
) -> Paragraph:
    """Stack one compact planned/actual pair and its semantic signed difference.

    Args:
        planned (float | None): Optional dispatch quantity.
        actual (float | None): Optional observed/interpolated quantity.
        unit (str): Canonical quantity unit; units appear in table headers.
        language (str): Supported report edition.
        mass_unit (str): Explicit kg/lb display unit.
        key (str): Metric-specific color interpretation.
        show_delta (bool): Include a comparable difference, default true.
        actual_suffix (str): Controlled reference marker, default empty text.
    Returns:
        Paragraph: P/A pair followed by an optional colored delta; absent values
            use one compact dash instead of repeated unavailable paragraphs.
    """
    suffix = " " + (mass_unit if unit == "kg" else unit)
    left = _comparison_value(planned, unit, language, mass_unit).removesuffix(suffix)
    right = _comparison_value(actual, unit, language, mass_unit).removesuffix(suffix)
    if actual is not None:
        right += actual_suffix
    parts = [("P: " + left + " / A: " + right, _COLOR_INK)]
    if show_delta and planned is not None and actual is not None:
        delta = actual - planned
        text = _comparison_value(delta, unit, language, mass_unit, True).removesuffix(suffix)
        parts.append(("Δ " + text, _delta_color(key, delta)))
    return _comparison_cell(parts, language)

def _comparison_pages(analysis: FlightAnalysis, language: str) -> List[Flowable]:
    """Present compact semantic dashboards while retaining all usable evidence.

    Args:
        analysis (FlightAnalysis): Original-sample comparison and attached OFP.
        language (str): Supported report edition.
    Returns:
        List[Flowable]: Summary, grouped phases, contextual performance ranges
            and deviation dashboards; missing channels are explained once.
    """
    comparison, plan = analysis.comparison, analysis.dispatch
    if comparison is None or plan is None:
        return []
    styles = _styles()
    unit = "lb" if plan.units.lower() in {"lb", "lbs"} else "kg"
    groups: list[list[list[str | Paragraph]]] = [[], []]
    for item in comparison.metrics:
        if item.actual is None and (
            item.key.endswith("_delay") or item.key.startswith(("toc_", "tod_"))
        ):
            continue
        if item.actual is None and item.planned is None:
            continue
        delta = (
            item.actual - item.planned
            if item.actual is not None and item.planned is not None else None
        )
        delta_text = _comparison_value(delta, item.unit, language, unit, True)
        if delta is not None and item.planned > 0:
            delta_text += " / " + _number(delta / item.planned * 100, language) + "%"
        row = [
            tr(language, "cmp_" + item.key),
            _comparison_cell([(
                _comparison_value(item.planned, item.unit, language, unit),
                _COLOR_PLANNED,
            )], language),
            _comparison_value(item.actual, item.unit, language, unit),
            _comparison_cell([(delta_text, _delta_color(item.key, delta))], language),
        ]
        groups[1 if item.unit == "kg" else 0].append(row)
    panel_width = (CHART_WIDTH - COMPARISON_PANEL_GAP) / 2
    panels = [
        _comparison_table(
            [tr(language, "cmp_" + key), tr(language, "cmp_plan"),
             tr(language, "cmp_actual"), "Δ / %"],
            rows, [0.40, 0.19, 0.19, 0.22], language, panel_width,
        )
        for key, rows in zip(("time_route", "fuel_weight"), groups)
    ]
    summary = Table(
        [[panels[0], Spacer(COMPARISON_PANEL_GAP, 1), panels[1]]],
        colWidths=[panel_width, COMPARISON_PANEL_GAP, panel_width],
    )
    summary.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
    ]))
    story: List[Flowable] = [
        PageBreak(), _paragraph(tr(language, "cmp_title"), styles["heading"]),
        _paragraph(tr(language, "cmp_compact_legend"), styles["small"]),
        Spacer(1, 6), summary, Spacer(1, 6),
        _paragraph(tr(language, "cmp_compact_data").format(
            matched=comparison.matched_fixes, total=comparison.total_fixes,
            valid=_format_duration(comparison.valid_seconds, language),
            gaps=comparison.excluded_intervals,
        ), styles["small"]),
    ]
    if not comparison.altitude_error_ft.seconds:
        story.append(_paragraph(tr(language, "cmp_legacy_height"), styles["small"]))
    if not any(row.passage and row.passage.simulator_utc for row in comparison.rows):
        story.append(_paragraph(tr(language, "cmp_legacy_clock"), styles["small"]))
    for note in dict.fromkeys(comparison.notes):
        if note not in {"pressure_missing", "block_estimate"}:
            story.append(_paragraph(tr(language, "cmp_note_" + note), styles["small"]))

    phases = grouped_phases(comparison)
    phase_rows: list[list[str | Paragraph]] = []
    for phase in phases:
        fuel_complete = (
            abs(phase.fuel_seconds - phase.seconds) <= COVERAGE_EPSILON_SECONDS
        )
        actual_fuel = phase.fuel_kg if phase.fuel_seconds else None
        flow = phase.fuel_kg / phase.fuel_seconds * 3600 if phase.fuel_seconds else None
        efficiency = (
            phase.fuel_kg / phase.distance_nm
            if fuel_complete and phase.distance_nm > 0
            and abs(phase.distance_seconds - phase.seconds) <= COVERAGE_EPSILON_SECONDS
            else None
        )
        fuel_delta = (
            actual_fuel - phase.planned_fuel_kg
            if actual_fuel is not None and phase.planned_fuel_kg is not None
            and fuel_complete else None
        )
        percent = (
            fuel_delta / phase.planned_fuel_kg * 100
            if fuel_delta is not None and phase.planned_fuel_kg > 0 else None
        )
        phase_rows.append([
            tr(language, "cmp_group_" + phase.phase),
            _comparison_pair(phase.planned_seconds, phase.seconds, "s",
                language, unit, "phase_time"),
            _comparison_pair(phase.planned_fuel_kg, actual_fuel, "kg",
                language, unit, "phase_fuel", fuel_complete),
            _number(phase.fuel_seconds / phase.seconds * 100, language) + "%"
                if phase.seconds else "—",
            _comparison_value(flow, "kg", language, unit) + "/h"
                if flow is not None else "—",
            _comparison_value(efficiency, "kg", language, unit) + "/NM"
                if efficiency is not None else "—",
            _comparison_cell([(
                _number(percent, language) + "%" if percent is not None else "—",
                _delta_color("phase_fuel", fuel_delta),
            )], language),
        ])
    story.extend([
        PageBreak(), _paragraph(tr(language, "cmp_phases"), styles["heading"]),
        _paragraph(tr(language, "cmp_group_basis"), styles["small"]),
        _comparison_table([
            tr(language, "cmp_phase"), tr(language, "cmp_time_pair"),
            tr(language, "cmp_fuel_pair") + " " + unit,
            tr(language, "cmp_coverage"), unit + "/h", unit + "/NM", "Δ %",
        ], phase_rows, [0.19, 0.20, 0.22, 0.09, 0.11, 0.11, 0.08], language),
        Spacer(1, 8),
        _panel_row([
            _phase_delta_chart(analysis, language, "time", panel_width),
            _phase_delta_chart(analysis, language, "fuel", panel_width),
        ], [CHART_WIDTH / 2, CHART_WIDTH / 2]),
    ])
    performance: list[list[str | Paragraph]] = []
    for phase in phases:
        row: list[str | Paragraph] = [tr(language, "cmp_group_" + phase.phase)]
        for key in ("ias", "tas", "gs", "vs"):
            value = phase.values.get(key)
            if value is None:
                row.append("—")
            else:
                text = _number(value.mean, language)
                span = _number(value.minimum, language) + " – " + _number(value.maximum, language)
                if value.seconds + COVERAGE_EPSILON_SECONDS < phase.seconds:
                    span += " · " + _format_duration(value.seconds, language)
                row.append(_comparison_cell([
                    (text, _COLOR_INK), (span, _COLOR_MUTED),
                ], language))
        for key in ("headwind", "crosswind"):
            value = phase.values.get(key)
            row.append(_number(value.mean, language) if value else "—")
        performance.append(row)
    story.extend([
        PageBreak(), _paragraph(tr(language, "cmp_performance"), styles["heading"]),
        _paragraph(tr(language, "cmp_range_basis"), styles["small"]),
        _comparison_table([
            tr(language, "cmp_phase"), "IAS kt", "TAS kt", "GS kt", "V/S fpm",
            tr(language, "cmp_headwind") + " kt", tr(language, "cmp_crosswind") + " kt",
        ], performance, [0.19, 0.15, 0.15, 0.15, 0.16, 0.10, 0.10], language),
        Spacer(1, 8), _cruise_range_chart(analysis, language),
        _paragraph(tr(language, "cmp_whisker_basis"), styles["small"]),
    ])
    story.extend(_deviation_pages(analysis, language))
    return story

def _phase_delta_chart(
    analysis: FlightAnalysis, language: str, quantity: str, chart_width: float,
) -> Drawing:
    """Draw plan-to-actual phase variance as zero-anchored range bars.

    Args:
        analysis (FlightAnalysis): Shared phase data and optional OFP.
        language (str): Supported report edition.
        quantity (str): Either time or fuel for the compared phase total.
        chart_width (float): Available panel width in PDF points.
    Returns:
        Drawing: Three phase comparison bars; the circle marks plan, the square
            actual, and semantic color describes the signed percentage change.
    """
    height = COMPARISON_CHART_HEIGHT
    drawing = Drawing(chart_width, height)
    title = tr(language, "cmp_phase_" + quantity + "_chart")
    drawing.add(String(8, height - 16, title, fontName=_FONT_BOLD,
        fontSize=10, fillColor=_COLOR_INK))
    rows = []
    if analysis.comparison:
        for phase in grouped_phases(analysis.comparison):
            if phase.phase not in {"climb", "cruise", "descent"}:
                continue
            planned = phase.planned_seconds if quantity == "time" else phase.planned_fuel_kg
            actual = phase.seconds if quantity == "time" else phase.fuel_kg
            if quantity == "fuel" and (
                abs(phase.fuel_seconds - phase.seconds) > COVERAGE_EPSILON_SECONDS
            ):
                continue
            if planned is not None and planned > 0:
                rows.append((phase.phase, (actual - planned) / planned * 100))
    if not rows:
        drawing.add(String(8, height / 2, "—", fontName=_FONT_NAME,
            fontSize=12, fillColor=_COLOR_MUTED))
        return drawing
    limit = max(abs(value) for _phase, value in rows) or 1.0
    left, width = 75, chart_width - 130
    zero = left + width / 2
    drawing.add(Line(zero, 28, zero, height - 38,
        strokeColor=_COLOR_GRID, strokeDashArray=[3, 2]))
    for index, (phase, value) in enumerate(rows):
        y = height - 52 - index * 32
        x = zero + value / limit * width / 2
        color = _delta_color("phase_" + quantity, value)
        drawing.add(String(7, y - 3, _translate_phase_event(phase, language),
            fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
        drawing.add(Line(zero, y, x, y, strokeColor=color, strokeWidth=2))
        drawing.add(Circle(zero, y, 3, fillColor=colors.white, strokeColor=_COLOR_INK))
        drawing.add(Rect(x - 3, y - 6, 6, 12, fillColor=color, strokeColor=None))
        drawing.add(String(chart_width - 5, y - 3,
            ("+" if value >= 0 else "") + _number(value, language) + "%",
            textAnchor="end", fontName=_FONT_BOLD, fontSize=9, fillColor=color))
    drawing.add(String(zero, 9, "P = 0%  →  A",
        textAnchor="middle", fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    return drawing

def _cruise_range_chart(analysis: FlightAnalysis, language: str) -> Drawing:
    """Show observed cruise speed whiskers with means and planned TAS reference.

    Args:
        analysis (FlightAnalysis): Original weighted cruise speed observations.
        language (str): Supported report edition.
    Returns:
        Drawing: Min/max whiskers and mean bodies for IAS/TAS/GS; the OFP TAS
            reference is drawn only when explicitly available.
    """
    drawing = Drawing(CHART_WIDTH, COMPARISON_CHART_HEIGHT)
    drawing.add(String(10, COMPARISON_CHART_HEIGHT - 16,
        tr(language, "cmp_cruise_ranges"), fontName=_FONT_BOLD,
        fontSize=11, fillColor=_COLOR_INK))
    cruise = next((phase for phase in grouped_phases(analysis.comparison)
                   if phase.phase == "cruise"), None) if analysis.comparison else None
    if cruise is None or not cruise.values:
        return drawing
    values = [(key, cruise.values[key]) for key in ("ias", "tas", "gs")
              if key in cruise.values]
    planned = None
    if analysis.dispatch:
        try:
            candidate = float(analysis.dispatch.cruise_tas_kt)
            if math.isfinite(candidate) and candidate >= 0:
                planned = candidate
        except ValueError:
            pass
    maximum = max(1.0, max(value.maximum for _key, value in values)) if values else 1.0
    if planned is not None:
        maximum = max(maximum, planned)
    left, width = 80, CHART_WIDTH - 140
    for index, (key, value) in enumerate(values):
        y = COMPARISON_CHART_HEIGHT - 50 - index * 32
        lo, hi = left + value.minimum / maximum * width, left + value.maximum / maximum * width
        mean = left + value.mean / maximum * width
        drawing.add(String(10, y - 3, key.upper() + " kt", fontName=_FONT_BOLD,
            fontSize=9, fillColor=_COLOR_INK))
        drawing.add(Line(lo, y, hi, y, strokeColor=_COLOR_BLUE, strokeWidth=2))
        for x in (lo, hi):
            drawing.add(Line(x, y - 5, x, y + 5, strokeColor=_COLOR_BLUE))
        drawing.add(Rect(mean - 4, y - 7, 8, 14, fillColor=_COLOR_TEAL, strokeColor=None))
        drawing.add(String(CHART_WIDTH - 6, y - 3, _number(value.mean, language),
            textAnchor="end", fontName=_FONT_BOLD, fontSize=9, fillColor=_COLOR_INK))
        if key == "tas" and planned is not None:
            x = left + planned / maximum * width
            drawing.add(Line(x, y - 10, x, y + 10, strokeColor=_COLOR_PLANNED,
                strokeWidth=1.5))
            drawing.add(String(x + 4, y + 9, "P", fontName=_FONT_BOLD,
                fontSize=9, fillColor=_COLOR_PLANNED))
    return drawing

def _deviation_chart(
    analysis: FlightAnalysis, language: str, attribute: str, label: str,
    unit: str, scale: float = 1.0, band: float | None = None,
    chart_width: float = CHART_WIDTH, chart_height: float = CHART_HEIGHT,
) -> Drawing:
    """Draw a compact semantic error profile with explicit zero and gap context.

    Args:
        analysis (FlightAnalysis): Shared graph-only deviation observations.
        language (str): Supported report edition.
        attribute (str): DeviationPoint scalar field.
        label (str): Localized metric-specific chart title.
        unit (str): Displayed y-axis unit.
        scale (float): Canonical-to-display conversion, default identity.
        band (float | None): Optional descriptive comparison band.
        chart_width (float): Panel width in points, default full-page width.
        chart_height (float): Panel height in points, default original chart size.
    Returns:
        Drawing: Vector plot with readable wrapped title, semantic segment colors,
            simulator-time axis, zero reference and unbridged missing evidence.
    """
    drawing = Drawing(chart_width, chart_height)
    drawing.add(Rect(0, 0, chart_width - 1, chart_height - 1,
        fillColor=colors.white, strokeColor=_COLOR_GRID, rx=6))
    titles = simpleSplit(label, _FONT_BOLD, 10, chart_width - 20)
    for index, title in enumerate(titles):
        drawing.add(String(10, chart_height - 15 - index * 12, title,
            fontName=_FONT_BOLD, fontSize=10, fillColor=_COLOR_INK))
    points = analysis.comparison.deviations if analysis.comparison else []
    values = [getattr(point, attribute) * scale for point in points
              if getattr(point, attribute) is not None]
    if not values:
        return drawing
    left, bottom = 62, 32
    width, height = chart_width - 82, chart_height - 76
    lower, upper, _minor, _major = _axis_limits(
        min(0.0, min(values)), max(0.0, max(values)), attribute,
    )
    maximum_time = max(GRAPH_INTERVAL_SECONDS / 60,
        max(point.elapsed_seconds for point in points) / 60)
    if band is not None:
        band_low = 0.0 if attribute == "route_error_nm" else -band
        lower, upper = min(lower, band_low), max(upper, band)
        drawing.add(Rect(left, bottom + (band_low - lower) / (upper - lower) * height,
            width, (band - band_low) / (upper - lower) * height,
            fillColor=colors.HexColor("#E8F5EF"), strokeColor=None))
    for index in range(DEVIATION_AXIS_DIVISIONS + 1):
        fraction = index / DEVIATION_AXIS_DIVISIONS
        y = bottom + fraction * height
        drawing.add(Line(left, y, left + width, y,
            strokeColor=_COLOR_GRID, strokeWidth=0.5))
        drawing.add(String(left - 6, y - 3,
            _number(lower + fraction * (upper - lower), language),
            textAnchor="end", fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
        x = left + fraction * width
        drawing.add(String(x, bottom - 13,
            str(round(maximum_time * fraction)), textAnchor="middle",
            fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    zero_y = bottom - lower / (upper - lower) * height
    drawing.add(Line(left, zero_y, left + width, zero_y,
        strokeColor=_COLOR_INK, strokeWidth=0.7, strokeDashArray=[3, 2]))
    previous = None
    for point in points:
        value = getattr(point, attribute)
        if point.break_before or value is None:
            previous = None
        if value is None:
            continue
        x = left + point.elapsed_seconds / 60 / maximum_time * width
        y = bottom + (value * scale - lower) / (upper - lower) * height
        color = _delta_color(attribute, value)
        if previous is not None:
            px, py, old_value = previous
            if old_value * value < 0 and attribute in {
                "time_delta_seconds", "fuel_delta_kg", "burn_delta_kg",
            }:
                zero_x = px + (x - px) * abs(old_value) / (abs(old_value) + abs(value))
                drawing.add(Line(px, py, zero_x, zero_y,
                    strokeColor=_delta_color(attribute, old_value), strokeWidth=1.3))
                drawing.add(Line(zero_x, zero_y, x, y,
                    strokeColor=color, strokeWidth=1.3))
            else:
                drawing.add(Line(px, py, x, y, strokeColor=color, strokeWidth=1.3))
        else:
            drawing.add(Circle(x, y, 1.5, fillColor=color, strokeColor=None))
        previous = x, y, value
    drawing.add(String(left + width, chart_height - 37, unit, textAnchor="end",
        fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    drawing.add(String(left + width / 2, 6, tr(language, "cmp_minutes_short"),
        textAnchor="middle", fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    return drawing

def _deviation_histogram(
    value: WeightedValue, language: str, unit: str,
    chart_width: float = CHART_WIDTH, chart_height: float = CHART_HEIGHT,
) -> Drawing:
    """Draw a compact time-weighted absolute-error histogram with P95 reference.

    Args:
        value (WeightedValue): Original-interval distribution and coverage.
        language (str): Supported report edition.
        unit (str): Absolute-error display unit.
        chart_width (float): Panel width in points, default full-page width.
        chart_height (float): Panel height in points, default original chart size.
    Returns:
        Drawing: Weighted duration percentages, absolute-error axis and P95;
            histogram aggregation changes presentation only.
    """
    drawing = Drawing(chart_width, chart_height)
    drawing.add(Rect(0, 0, chart_width - 1, chart_height - 1,
        fillColor=colors.white, strokeColor=_COLOR_GRID, rx=6))
    drawing.add(String(10, chart_height - 16,
        tr(language, "cmp_histogram_short") + " · " + unit,
        fontName=_FONT_BOLD, fontSize=10, fillColor=_COLOR_INK))
    if not value.seconds:
        return drawing
    maximum = max(value.histogram) + 1
    bins = [0.0] * DEVIATION_HISTOGRAM_BARS
    for index, seconds in value.histogram.items():
        target = min(DEVIATION_HISTOGRAM_BARS - 1,
            int(index / maximum * DEVIATION_HISTOGRAM_BARS))
        bins[target] += seconds / value.seconds * 100
    left, bottom = 62, 30
    width, height = chart_width - 82, chart_height - 65
    ceiling = max(bins) or 1.0
    for index in range(DEVIATION_AXIS_DIVISIONS + 1):
        fraction = index / DEVIATION_AXIS_DIVISIONS
        y = bottom + fraction * height
        drawing.add(Line(left, y, left + width, y,
            strokeColor=_COLOR_GRID, strokeWidth=0.5))
        drawing.add(String(left - 6, y - 3,
            str(round(ceiling * fraction)) + "%", textAnchor="end",
            fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
        drawing.add(String(left + width * fraction, bottom - 13,
            _number(maximum * value.bin_width * fraction, language),
            textAnchor="middle", fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    for index, weight in enumerate(bins):
        drawing.add(Rect(left + width * index / len(bins), bottom,
            width / len(bins) - 1, height * weight / ceiling,
            fillColor=_COLOR_TEAL, strokeColor=None))
    if value.p95 is not None:
        x = left + min(1.0, value.p95 / (maximum * value.bin_width)) * width
        drawing.add(Line(x, bottom, x, bottom + height,
            strokeColor=_COLOR_PLANNED, strokeWidth=1, strokeDashArray=[3, 2]))
        drawing.add(String(left + width, chart_height - 31,
            "P95 ≈ " + _number(value.p95, language) + " " + unit,
            textAnchor="end", fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_PLANNED))
    return drawing

def _deviation_pages(analysis: FlightAnalysis, language: str) -> List[Flowable]:
    """Combine profiles, distributions and error statistics in contextual panels.

    Args:
        analysis (FlightAnalysis): Shared original statistics and bounded graphs.
        language (str): Supported report edition.
    Returns:
        List[Flowable]: Two dense dashboards when comparable data exists;
            absent channels never allocate empty chart panels or separate pages.
    """
    comparison = analysis.comparison
    if comparison is None or not comparison.deviations:
        return []
    styles = _styles()
    panel_width = (CHART_WIDTH - COMPARISON_PANEL_GAP) / 2
    mass_unit = "lb" if analysis.dispatch and analysis.dispatch.units.lower() in {"lb", "lbs"} else "kg"
    mass_scale = POUNDS_PER_KG if mass_unit == "lb" else 1.0
    profiles = []
    for attribute, title, unit, scale, band in (
        ("time_delta_seconds", "time_profile", "min", 1 / 60, None),
        ("fuel_delta_kg", "fuel_profile", mass_unit, mass_scale, None),
        ("burn_delta_kg", "burn_profile", mass_unit, mass_scale, None),
        ("route_error_nm", "route_profile", "NM", 1.0, ROUTE_BAND_NM),
    ):
        if any(getattr(point, attribute) is not None for point in comparison.deviations):
            profiles.append(_deviation_chart(
                analysis, language, attribute, tr(language, "cmp_" + title),
                unit, scale, band, panel_width, COMPARISON_CHART_HEIGHT,
            ))
    story: List[Flowable] = [
        PageBreak(), _paragraph(tr(language, "cmp_deviation_charts"), styles["heading"]),
        _paragraph(tr(language, "cmp_chart_short"), styles["small"]),
    ]
    for index in range(0, len(profiles), 2):
        pair = profiles[index:index + 2]
        if len(pair) == 1:
            pair.append(Spacer(panel_width, COMPARISON_CHART_HEIGHT))
        story.extend([
            _panel_row(pair, [CHART_WIDTH / 2, CHART_WIDTH / 2]),
            Spacer(1, 4),
        ])
    rows = []
    for key, value, unit in (
        ("time_profile", comparison.time_error_seconds, "s"),
        ("route_profile", comparison.route_error_nm, "NM"),
        ("height_profile", comparison.altitude_error_ft, "ft"),
    ):
        if not value.seconds:
            continue
        maximum = max(abs(value.minimum), abs(value.maximum))
        rows.append([tr(language, "cmp_" + key),
            _comparison_value(value.mean, unit, language, signed=True),
            _comparison_value(value.mae, unit, language),
            _comparison_value(value.rmse, unit, language),
            _comparison_value(value.p95, unit, language),
            _comparison_value(maximum, unit, language),
            _format_duration(value.seconds, language)])
    if rows:
        story.append(_comparison_table([
            tr(language, "cmp_" + key) for key in
            ("metric", "bias", "mae", "rmse", "p95", "max", "coverage")
        ], rows, [0.28, 0.12, 0.12, 0.12, 0.12, 0.12, 0.12], language))

    story.extend([
        PageBreak(), _paragraph(tr(language, "cmp_statistics"), styles["heading"]),
        _paragraph(tr(language, "cmp_stats_short"), styles["small"]),
    ])
    if comparison.altitude_error_ft.seconds:
        panels = [
            _deviation_chart(analysis, language, "altitude_delta_ft",
                tr(language, "cmp_height_profile"), "ft", 1.0,
                ALTITUDE_BAND_FT, panel_width, COMPARISON_CHART_HEIGHT),
            _deviation_histogram(comparison.altitude_error_ft, language,
                "ft", panel_width, COMPARISON_CHART_HEIGHT),
            _deviation_histogram(comparison.route_error_nm, language,
                "NM", panel_width, COMPARISON_CHART_HEIGHT),
            _deviation_histogram(comparison.time_error_seconds, language,
                "s", panel_width, COMPARISON_CHART_HEIGHT),
        ]
        for index in range(0, len(panels), 2):
            story.extend([
                _panel_row(panels[index:index + 2], [CHART_WIDTH / 2, CHART_WIDTH / 2]),
                Spacer(1, 4),
            ])
    else:
        for value, unit in (
            (comparison.route_error_nm, "NM"), (comparison.time_error_seconds, "s"),
        ):
            if value.seconds:
                story.extend([
                    _deviation_histogram(value, language, unit,
                        CHART_WIDTH, COMPARISON_CHART_HEIGHT),
                    Spacer(1, 4),
                ])
    story.append(_paragraph(tr(language, "cmp_band_short").format(
        route=_format_duration(comparison.route_outside_seconds, language),
        height=_format_duration(comparison.altitude_outside_seconds, language)
            if comparison.altitude_error_ft.seconds else "—",
    ), styles["small"]))
    return story

def _flightplan_pages(analysis: FlightAnalysis, language: str) -> List[Flowable]:
    """End with compact paired values for every original planned route point.

    Args:
        analysis (FlightAnalysis): Shared dispatch and ordered passage evidence.
        language (str): Supported report edition.
    Returns:
        List[Flowable]: A repeated-header flightplan with units in its headings,
            semantic deltas and one explanation of absent legacy channels.
            Geometric legacy altitude is labelled and never used for FL errors.
    """
    comparison, plan = analysis.comparison, analysis.dispatch
    if comparison is None or plan is None:
        return []
    styles = _styles()
    unit = "lb" if plan.units.lower() in {"lb", "lbs"} else "kg"
    has_utc = any(row.passage and row.passage.simulator_utc for row in comparison.rows)
    has_amsl = any(row.passage and row.passage.pressure_altitude_ft is None
                   and row.passage.altitude_amsl_ft is not None for row in comparison.rows)
    dates = [row.planned_utc[:10] for row in comparison.rows if row.planned_utc]
    base_date = dates[0] if dates else ""
    rows: list[list[str | Paragraph]] = []

    def utc(text: str | None) -> str:
        """Show UTC clock time with a date only when it differs from the context.

        Args:
            text (str | None): Optional normalized simulator/OFP timestamp.
        Returns:
            str: UTC time, different-day date/time, or one missing-value dash.
        """
        if not text:
            return "—"
        try:
            stamp = datetime.fromisoformat(text.replace("Z", "+00:00"))
            if stamp.tzinfo is None:
                return "—"
            stamp = stamp.astimezone(timezone.utc)
            return stamp.strftime("%H:%M:%S") if stamp.date().isoformat() == base_date else stamp.strftime("%d/%m %H:%M:%S")
        except ValueError:
            return "—"

    for index, row in enumerate(comparison.rows):
        point, passage = row.point, row.passage
        ident = point.ident[:FLIGHTPLAN_IDENT_CHARACTERS]
        if len(point.ident) > FLIGHTPLAN_IDENT_CHARACTERS:
            ident += "…"
        stage = point.stage[:FLIGHTPLAN_IDENT_CHARACTERS]
        if not stage and index in (0, len(comparison.rows) - 1):
            stage = "DEP" if index == 0 else "ARR"
        actual_height = passage.pressure_altitude_ft if passage else None
        amsl = passage is not None and actual_height is None and passage.altitude_amsl_ft is not None
        if amsl:
            actual_height = passage.altitude_amsl_ft
        utc_cell: str | Paragraph = _comparison_cell(
            [("P: " + utc(row.planned_utc), _COLOR_PLANNED),
             ("A: " + utc(passage.simulator_utc if passage else None), _COLOR_INK)],
            language,
        ) if has_utc else utc(row.planned_utc)
        wind_parts = []
        if point.wind_direction_deg is not None and point.wind_speed_kt is not None:
            wind_parts.append(("P: " + str(round(point.wind_direction_deg)) + "°/"
                + str(round(point.wind_speed_kt)), _COLOR_PLANNED))
        if passage and passage.wind_direction_deg is not None and passage.wind_speed_kt is not None:
            wind_parts.append(("A: " + str(round(passage.wind_direction_deg)) + "°/"
                + str(round(passage.wind_speed_kt)), _COLOR_INK))
        rows.append([
            ident + ("\n" + stage if stage else ""),
            _comparison_pair(point.elapsed_seconds, row.actual_elapsed_seconds,
                "s", language, unit, "time_delta_seconds"),
            utc_cell,
            _comparison_pair(mass_kg(point.fuel_onboard, plan.units),
                passage.fuel_kg if passage else None, "kg",
                language, unit, "fuel_remaining"),
            _comparison_pair(point.altitude_ft, actual_height, "ft",
                language, unit, "height", row.altitude_comparable,
                "*" if amsl else ""),
            _comparison_pair(point.true_airspeed_kt, passage.tas_kt if passage else None,
                "kt", language, unit, "speed", False),
            _comparison_pair(point.ground_speed_kt, passage.gs_kt if passage else None,
                "kt", language, unit, "speed", False),
            _comparison_cell(wind_parts or [("—", _COLOR_MUTED)], language),
            _number(passage.separation_nm, language) if passage else "—",
        ])
    story: List[Flowable] = [
        PageBreak(), _paragraph(tr(language, "cmp_flightplan"), styles["heading"]),
        _paragraph(tr(language, "cmp_flightplan_short").format(date=base_date), styles["small"]),
    ]
    if not has_utc:
        story.append(_paragraph(tr(language, "cmp_legacy_clock"), styles["small"]))
    if has_amsl:
        story.append(_paragraph(tr(language, "cmp_amsl_marker"), styles["small"]))
    if rows:
        story.append(_comparison_table([
            tr(language, "cmp_fix"), tr(language, "cmp_elapsed"),
            tr(language, "cmp_utc") if has_utc else tr(language, "cmp_plan_utc"),
            tr(language, "cmp_fuel_remaining") + " " + unit,
            tr(language, "cmp_height_short") + " ft",
            "TAS kt", "GS kt", tr(language, "cmp_wind"), "NM",
        ], rows, [0.11, 0.16, 0.10, 0.17, 0.13, 0.08, 0.08, 0.10, 0.07], language))
    return story
