# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/reports/presentation.py
# Module Type: Flight report presentation owner
# Purpose: Own presentation behavior for multilingual postflight PDF reports.
# Responsibilities: Preserve report rendering behavior behind explicit owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own the presentation domain of flight report generation."""

import math
from datetime import datetime
from typing import Dict, List, Optional, Sequence, Tuple, Union
from xml.sax.saxutils import escape


from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.lib.utils import simpleSplit
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import BaseDocTemplate, Flowable, Paragraph, Spacer, Table, TableStyle
from reportlab.graphics.shapes import Drawing, Group, Path as ShapePath, Rect, String

from uploader.diagnostics import redact_text
from uploader.flight_data import FlightAnalysis, FlightSample
from uploader.flight_report_translations import tr

from .policy import (
    METRIC_COLUMNS,
    CHART_WIDTH,
    AXIS_EPSILON,
    AXIS_FLAT_PADDING,
    AXIS_MAJOR_MULTIPLE,
    AXIS_MINOR_DIVISIONS,
    COORDINATE_DECIMALS,
    DISPLAY_DECIMALS,
    KNOTS_PER_MPS,
    MAX_GRID_LINES,
    METRIC_ROW_HEIGHT,
    PRECISE_TIME_DECIMALS,
    REPORT_PAGE_SIZE,
    REPORT_SIDE_MARGIN,
    VERTICAL_SPEED_GRID_FPM,
    _COLOR_GRID,
    _COLOR_INK,
    _COLOR_MUTED,
    _COLOR_NAVY,
    _COLOR_PAGE,
    _COLOR_PANEL,
    _COLOR_TEAL,
    _FONT_BOLD,
    _FONT_NAME,
)

from .identity import (
    _register_font,
)


def _number(value: Optional[float], language: str) -> str:
    """Format a finite numeric value or an explicit unavailable marker.

    Args:
        value (Optional[float]): Captured value, which may be absent or
            non-finite when the simulator did not provide a usable reading.
        language (str): Language used to localize the decimal separator.

    Returns:
        str: Rounded display number, or the localized unavailable label.
    """
    if value is None or not math.isfinite(float(value)):
        return tr(language, "unavailable")
    result = format(float(value), "." + str(DISPLAY_DECIMALS) + "f")
    return result.replace(".", ",") if language in ("cs", "de", "sv") else result

def _format_duration(seconds: Optional[float], language: str) -> str:
    """Format elapsed seconds as hours, minutes and seconds.

    Args:
        seconds (Optional[float]): Nonnegative elapsed time in seconds.
        language (str): Language used for unavailable values.

    Returns:
        str: Human-readable duration, or the unavailable label.
    """
    if seconds is None or not math.isfinite(float(seconds)):
        return tr(language, "unavailable")
    total = max(0, int(seconds))
    hours, remainder = divmod(total, 3600)
    minutes, seconds_value = divmod(remainder, 60)
    if hours:
        return "{:d}:{:02d}:{:02d}".format(hours, minutes, seconds_value)
    return "{:d}:{:02d}".format(minutes, seconds_value)

def _format_speed(value: Optional[float], language: str) -> str:
    """Format metres per second as knots while preserving missing values.

    Args:
        value (Optional[float]): Ground speed in metres per second.
        language (str): Language used for unavailable values.

    Returns:
        str: Knot value or the localized unavailable label.
    """
    if value is None:
        return tr(language, "unavailable")
    return _number(float(value) * KNOTS_PER_MPS, language) + " kt"

def _coordinate(value: Optional[float], language: str) -> str:
    """Format one observed coordinate without implying exact impact location.

    Args:
        value (Optional[float]): Coordinate in decimal degrees.
        language (str): Language used for unavailable values and decimals.

    Returns:
        str: Five-decimal coordinate or the localized unavailable label.
    """
    if value is None or not math.isfinite(float(value)):
        return tr(language, "unavailable")
    result = format(float(value), ".{}f".format(COORDINATE_DECIMALS))
    return result.replace(".", ",") if language in ("cs", "de", "sv") else result

def _recorded_flight_date(
    analysis: FlightAnalysis,
    language: str,
) -> str:
    """Return the date from the first valid recorded UTC sample.

    Args:
        analysis (FlightAnalysis): Immutable recorder analysis.
        language (str): Language used for unavailable values.

    Returns:
        str: ISO date derived from recorded_utc, never from wall-clock time.
    """
    for sample in analysis.samples:
        value = sample.recorded_utc.strip()
        if not value:
            continue
        try:
            parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        except ValueError:
            continue
        return parsed.date().isoformat()
    return tr(language, "unavailable")

def _safe_source_text(value: str) -> str:
    """Redact local paths and credential assignments from recorded evidence.

    Args:
        value (str): Warning, event evidence or diagnostic reason string.

    Returns:
        str: Text safe to include in a locally generated or uploaded report.
    """
    return redact_text(value, redact_paths=True)

def _paragraph(
    text: str,
    style: ParagraphStyle,
) -> Paragraph:
    """Create an escaped ReportLab paragraph from untrusted model text.

    Args:
        text (str): Plain text from translated labels or captured metadata.
        style (ParagraphStyle): Paragraph style to apply.

    Returns:
        Paragraph: Safe paragraph with XML-sensitive characters escaped.
    """

    return Paragraph(escape(text).replace("\n", "<br/>"), style)

def _styles() -> Dict[str, ParagraphStyle]:
    """Create report styles using the embedded multilingual font.

    Returns:
        Dict[str, ParagraphStyle]: Named styles for title, headings, body and
            compact table cells.
    """
    _register_font()
    base = getSampleStyleSheet()
    return {
        "title": ParagraphStyle(
            "FlightTitle",
            parent=base["Title"],
            fontName=_FONT_BOLD,
            fontSize=28,
            leading=34,
            textColor=_COLOR_INK,
            alignment=TA_LEFT,
            spaceAfter=2 * mm,
        ),
        "subtitle": ParagraphStyle(
            "FlightSubtitle",
            parent=base["Normal"],
            fontName=_FONT_NAME,
            fontSize=12,
            leading=16,
            textColor=_COLOR_MUTED,
            spaceAfter=2 * mm,
        ),
        "heading": ParagraphStyle(
            "FlightHeading",
            parent=base["Heading2"],
            keepWithNext=True,
            fontName=_FONT_BOLD,
            fontSize=17,
            leading=21,
            textColor=_COLOR_INK,
            spaceBefore=4 * mm,
            spaceAfter=2 * mm,
        ),
        "body": ParagraphStyle(
            "FlightBody",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=12,
            leading=16,
            textColor=_COLOR_INK,
        ),
        "small": ParagraphStyle(
            "FlightSmall",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=9.5,
            leading=13,
            textColor=_COLOR_MUTED,
        ),
        "label": ParagraphStyle(
            "FlightLabel",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=9.5,
            leading=13,
            textColor=_COLOR_MUTED,
        ),
        "value": ParagraphStyle(
            "FlightValue",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=16,
            leading=20,
            textColor=_COLOR_INK,
        ),
        "center": ParagraphStyle(
            "FlightCenter",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=10,
            leading=14,
            alignment=TA_CENTER,
            textColor=_COLOR_INK,
        ),
    }

def _metric_card(label: str, value: str, styles: Dict[str, ParagraphStyle]) -> Table:
    """Create a compact shaded metric card.

    Args:
        label (str): Localized metric name.
        value (str): Formatted value or unavailable marker.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        Table: One-cell card with label and prominent value.
    """
    content = [
        _paragraph(label.upper(), styles["label"]),
        Spacer(1, 1.5 * mm),
        _paragraph(value, styles["value"]),
    ]
    card = Table([[content]], colWidths=[43 * mm], rowHeights=[19 * mm])
    card.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, -1), _COLOR_PANEL),
                ("BOX", (0, 0), (-1, -1), 0.5, _COLOR_GRID),
                ("LEFTPADDING", (0, 0), (-1, -1), 3 * mm),
                ("RIGHTPADDING", (0, 0), (-1, -1), 3 * mm),
                ("TOPPADDING", (0, 0), (-1, -1), 2.5 * mm),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 2 * mm),
                ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ]
        )
    )
    return card

def _metric_grid(
    analysis: FlightAnalysis,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> Table:
    """Create a four-column summary of known flight facts.

    Args:
        analysis (FlightAnalysis): Flight values from the shared model.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        Table: Two rows of summary cards with unavailable values kept explicit.
    """
    status = (
        tr(language, "complete")
        if analysis.completed
        else tr(language, "incomplete")
    )
    cards = [
        _metric_card(tr(language, "simulator"), analysis.simulator, styles),
        _metric_card(
            tr(language, "aircraft"),
            _safe_source_text(analysis.aircraft)
            if analysis.aircraft
            else tr(language, "unavailable"),
            styles,
        ),
        _metric_card(tr(language, "status"), status, styles),
        _metric_card(tr(language, "duration"), _format_duration(analysis.duration_seconds, language), styles),
        _metric_card(
            tr(language, "distance"),
            (
                tr(language, "unavailable")
                if analysis.distance_nm is None
                else _number(analysis.distance_nm, language) + " nm"
            ),
            styles,
        ),
        _metric_card(
            tr(language, "samples"),
            str(len(analysis.samples)),
            styles,
        ),
        _metric_card(tr(language, "landings"), str(len(analysis.landings)), styles),
        _metric_card(tr(language, "identity"), analysis.session_id[:16], styles),
    ]
    rows = [
        cards[0:4],
        cards[4:8],
    ]
    grid = Table(rows, colWidths=[43 * mm] * 4, hAlign="LEFT")
    grid.setStyle(
        TableStyle(
            [
                ("LEFTPADDING", (0, 0), (-1, -1), 0),
                ("RIGHTPADDING", (0, 0), (-1, -1), 1.5 * mm),
                ("TOPPADDING", (0, 0), (-1, -1), 0),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 1.5 * mm),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ]
        )
    )
    return grid

def _chart_series(
    samples: Sequence[FlightSample],
    attribute: str,
) -> List[Tuple[Optional[float], Optional[float], bool]]:
    """Extract points and explicit breaks for one captured sensor series.

    Args:
        samples (Sequence[FlightSample]): Ordered recorder samples.
        attribute (str): Numeric FlightSample attribute to draw.

    Returns:
        List[Tuple[Optional[float], Optional[float], bool]]: Elapsed seconds,
            value and segment-break marker; missing readings remain breaks.
    """
    points: List[Tuple[Optional[float], Optional[float], bool]] = []
    for sample in samples:
        if sample.discontinuity_before is not None:
            points.append((None, None, True))
        value = getattr(sample, attribute)
        elapsed = sample.session_elapsed_seconds
        if value is None or elapsed is None:
            points.append((None, None, True))
            continue
        numeric_value = float(value)
        numeric_elapsed = float(elapsed)
        if math.isfinite(numeric_value) and math.isfinite(numeric_elapsed):
            points.append((numeric_elapsed, numeric_value, False))
        else:
            points.append((None, None, True))
    return points

def _relative_time(value: float, zero_time: float) -> str:
    """Format captured session seconds relative to a chosen contact sample.

    Args:
        value (float): Session-elapsed sample time in seconds.
        zero_time (float): Session-elapsed first-contact time in seconds.

    Returns:
        str: Relative time using recorder elapsed seconds, never wall time.
    """
    offset = int(round(value - zero_time))
    sign = "+" if offset >= 0 else "-"
    total = abs(offset)
    minutes, seconds = divmod(total, 60)
    return "T" + sign + "{:d}:{:02d}".format(minutes, seconds)

def _precise_elapsed(
    value: float,
    zero_time: float,
    language: str,
) -> str:
    """Format signed elapsed seconds without rounding contact samples to seconds.

    Args:
        value (float): Session elapsed timestamp in seconds.
        zero_time (float): Reference timestamp in session elapsed seconds.
        language (str): Language used for decimal punctuation.

    Returns:
        str: Signed seconds rounded to the configured hundredth-second display.
    """
    offset = float(value) - float(zero_time)
    sign = "+" if offset >= 0 else "-"
    number = format(abs(offset), ".{}f".format(PRECISE_TIME_DECIMALS))
    if language in ("cs", "de", "sv"):
        number = number.replace(".", ",")
    return sign + number + " s"

def _nice_step(value: float) -> float:
    """Round a positive spacing up to a readable decimal interval.

    Args:
        value (float): Desired positive tick spacing in displayed units.

    Returns:
        float: A spacing of one, two, five or ten times a decimal power.
    """
    exponent = 10 ** math.floor(math.log10(max(value, AXIS_EPSILON)))
    return next(item * exponent for item in (1, 2, 5, 10) if item * exponent >= value)

def _axis_limits(minimum: float, maximum: float, attribute: str) -> Tuple[float, float, float, float]:
    """Choose enclosing readable limits and faint minor/major grid intervals.

    Args:
        minimum (float): Lowest finite measurement in displayed units.
        maximum (float): Highest finite measurement in displayed units.
        attribute (str): Sensor name selecting aviation-specific spacing.

    Returns:
        Tuple[float, float, float, float]: Lower bound, upper bound, minor step
            and emphasized major step; all measurements remain inside the range.
    """
    span = max(maximum - minimum, abs(maximum) * AXIS_FLAT_PADDING, 1.0)
    if attribute == "vertical_speed_mps":
        minor = max(VERTICAL_SPEED_GRID_FPM, _nice_step(span / MAX_GRID_LINES))
    else:
        minor = _nice_step(span / AXIS_MINOR_DIVISIONS)
    major = minor * AXIS_MAJOR_MULTIPLE
    lower = math.floor(minimum / minor) * minor
    upper = math.ceil(maximum / minor) * minor
    if lower == upper:
        lower -= minor
        upper += minor
    return lower, upper, minor, major

def _draw_page(
    canvas: Canvas, document: BaseDocTemplate,
    language: str, analysis_sha256: str,
) -> None:
    """Draw landscape page furniture in navy, teal and a pale reading surface.

    Args:
        canvas (Canvas): ReportLab canvas supplied by the document owner.
        document (BaseDocTemplate): Owner with current page number.
        language (str): Supported header/footer language.
        analysis_sha256 (str): Private source identity retained in PDF metadata.

    Returns:
        None: Paints the background, running header and footer before content.
    """
    canvas.saveState()
    width, height = REPORT_PAGE_SIZE
    canvas.setFillColor(_COLOR_PAGE)
    canvas.rect(0, 0, width, height, fill=1, stroke=0)
    canvas.setFillColor(_COLOR_NAVY)
    canvas.rect(0, height - 30, width, 30, fill=1, stroke=0)
    canvas.setFillColor(_COLOR_TEAL)
    canvas.rect(0, height - 32, width, 2, fill=1, stroke=0)
    canvas.setFont(_FONT_BOLD, 10)
    canvas.setFillColor(colors.white)
    canvas.drawString(REPORT_SIDE_MARGIN, height - 20, tr(language, "title"))
    canvas.setFont(_FONT_NAME, 9)
    canvas.drawRightString(width - REPORT_SIDE_MARGIN, height - 20, "PHP GALLERY")
    canvas.setStrokeColor(_COLOR_GRID)
    canvas.setLineWidth(0.6)
    canvas.line(REPORT_SIDE_MARGIN, 29, width - REPORT_SIDE_MARGIN, 29)
    canvas.setFont(_FONT_NAME, 8.5)
    canvas.setFillColor(_COLOR_MUTED)
    canvas.drawString(REPORT_SIDE_MARGIN, 16, tr(language, "footer"))
    canvas.setFont(_FONT_BOLD, 10)
    canvas.drawRightString(width - REPORT_SIDE_MARGIN, 16, f"{document.page:02d}")
    canvas.restoreState()

def _plane_icon(
    drawing: Drawing, x: float, y: float, phase: str, color: colors.Color,
    size: float = 1.0, angle_degrees: Optional[float] = None,
) -> None:
    """Draw a rounded aircraft silhouette with swept wings and tail surfaces.

    Args:
        drawing (Drawing): Destination vector infographic.
        x (float): Symbol center in PDF points.
        y (float): Symbol center in PDF points.
        phase (str): Observed phase selecting climb/descent orientation.
        color (colors.Color): Semantic aircraft color.
        size (float): Scale of the native thirty-point silhouette.
        angle_degrees (Optional[float]): Counterclockwise orientation from east,
            or None for a phase-specific tilt.

    Returns:
        None: Adds one self-contained vector aircraft without bitmap assets.
    """
    if angle_degrees is None:
        angle_degrees = 18.0 if phase in {"takeoff", "climb", "goaround"} else (
            -14.0 if phase in {"descent", "approach", "flare", "touchdown"} else 0.0
        )
    path = ShapePath(fillColor=color, strokeColor=None)
    path.moveTo(15, 0)
    path.curveTo(15, 1.3, 11, 1.8, 5, 1.8)
    for px, py in ((2, 2), (-4, 10), (-7, 10), (-3, 2),
                   (-10, 1.4), (-13, 5), (-15, 5), (-13, 0),
                   (-15, -5), (-13, -5), (-10, -1.4), (-3, -2),
                   (-7, -10), (-4, -10), (2, -2), (5, -1.8)):
        path.lineTo(px, py)
    path.curveTo(11, -1.8, 15, -1.3, 15, 0)
    path.closePath()
    radians = math.radians(angle_degrees)
    cosine, sine = math.cos(radians) * size, math.sin(radians) * size
    group = Group(path)
    group.transform = (cosine, sine, -sine, cosine, x, y)
    drawing.add(group)

def _visual_metrics(
    items: Sequence[Tuple[str, str]], language: str,
    panel_width: float = CHART_WIDTH, columns: int = METRIC_COLUMNS,
) -> Drawing:
    """Lay out factual values in landscape cards with large readable typography.

    Args:
        items (Sequence[Tuple[str, str]]): Localized labels and factual values.
        language (str): Supported edition retained as the common view input.
        panel_width (float): Available row width in PDF points.
        columns (int): Positive number of equal-width cards per row.

    Returns:
        Drawing: White rounded cards with teal accents, wrapped labels and
            fitted bold values; every supplied label/value remains visible.
    """
    rows = math.ceil(len(items) / columns)
    drawing = Drawing(panel_width, rows * METRIC_ROW_HEIGHT)
    width = panel_width / columns
    for index, (label, value) in enumerate(items):
        x = index % columns * width
        y = (rows - 1 - index // columns) * METRIC_ROW_HEIGHT
        drawing.add(Rect(x + 1, y + 4, width - 9, METRIC_ROW_HEIGHT - 8,
                         fillColor=colors.white, strokeColor=_COLOR_GRID,
                         strokeWidth=0.6, rx=7))
        drawing.add(Rect(x + 12, y + METRIC_ROW_HEIGHT - 24, 2, 11,
                         fillColor=_COLOR_TEAL, strokeColor=None, rx=1))
        label_size = 9.0
        lines = simpleSplit(label, _FONT_NAME, label_size, width - 36)
        if len(lines) > 2:
            label_size *= 2 / len(lines)
            lines = simpleSplit(label, _FONT_NAME, label_size, width - 36)
        for line_index, line in enumerate(lines):
            drawing.add(String(x + 21, y + METRIC_ROW_HEIGHT - 19 - line_index * 11,
                               line, fontName=_FONT_NAME, fontSize=label_size,
                               fillColor=_COLOR_MUTED))
        value_size = 18.0
        value_lines = simpleSplit(value, _FONT_BOLD, value_size, width - 30)
        if len(value_lines) > 1:
            value_size = min(16, (width - 30) / max(
                (pdfmetrics.stringWidth(word, _FONT_BOLD, 1) for word in value.split()),
                default=1,
            ))
            value_lines = simpleSplit(value, _FONT_BOLD, value_size, width - 30)
        for line_index, line in enumerate(value_lines):
            baseline = 14 if len(value_lines) == 1 else 27 - line_index * 18
            drawing.add(String(x + 13, y + baseline, line, fontName=_FONT_BOLD,
                               fontSize=value_size, fillColor=_COLOR_INK))
    return drawing

def _panel_row(
    panels: Sequence[Union[Flowable, List[Flowable]]],
    widths: Sequence[float],
    gap: float = 0.0,
) -> Table:
    """Align presentation panels in one row without default table padding.

    Args:
        panels (Sequence[Union[Flowable, List[Flowable]]]): Prepared cards or
            lists of prepared paragraphs/drawings stacked inside a table cell.
        widths (Sequence[float]): Corresponding positive column widths in points.
        gap (float): Right padding between neighboring cells in PDF points.

    Returns:
        Table: A top-aligned row preserving every child's dimensions and content.
    """
    table = Table([list(panels)], colWidths=list(widths), hAlign="LEFT")
    table.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
    ]))
    if len(panels) > 1 and gap:
        table.setStyle(TableStyle([
            ("RIGHTPADDING", (0, 0), (-2, -1), gap),
        ]))
    return table
