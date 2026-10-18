# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Render local multilingual postflight PDF reports from recorded flights.
# Responsibilities:
#   - Format the shared flight analysis without changing or inferring its data.
#   - Draw vector flight-profile and route graphics in four languages.
#   - Write atomic, checksummed reports with retry-stable identities.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/uploader/flight_report.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)

"""Render local multilingual postflight reports from normalized flight data."""

import hashlib
import json
import math
import os
import re
import tempfile
from datetime import datetime
from functools import partial
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Tuple, Union

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import (
    Flowable,
    KeepTogether,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.graphics.shapes import Circle, Drawing, Line, PolyLine, String

from .diagnostics import redact_text
from .flight_data import (
    ANALYSIS_VERSION,
    MAX_DESCENT_FIT_SECONDS,
    MAX_DESCENT_SAMPLE_GAP_SECONDS,
    MIN_DESCENT_FIT_SECONDS,
    FlightAnalysis,
    FlightSample,
    Landing,
)
from .flight_report_translations import supported_languages, tr


# Template identity is included in every manifest and output filename so a
# future layout revision does not silently reuse a prior rendering.
REPORT_TEMPLATE_VERSION = "1"

# Type: int; Units: PDF points; Scope: chart drawing geometry;
# Consumers: _profile_chart and _route_chart; Rationale: compact charts fit
# the printable A4 content width while retaining legible axes; value: 470.
CHART_WIDTH = 470
# Type: int; Units: PDF points; Scope: chart drawing geometry;
# Consumers: _profile_chart and _route_chart; Rationale: three profiles fit
# with flight/version metadata in the A4 frame; value: 144 (two inches).
CHART_HEIGHT = 144
# Type: float; Units: knots per metre per second; Scope: displayed speeds;
# Consumers: _format_speed; Rationale: convert SI capture values to aviation
# units without changing source data; value: 1.9438444924406.
KNOTS_PER_MPS = 1.9438444924406
# Type: int; Units: decimal places; Scope: displayed numeric values;
# Consumers: _number; Rationale: readable flight summaries without implying
# instrument precision beyond the captured values; value: 1.
DISPLAY_DECIMALS = 1
# Type: int; Units: decimal places; Scope: contact and event timestamps.
# Consumers: _precise_elapsed; Rationale: distinguish retained 20 Hz
# observations without implying finer precision than two decimal places;
# value: 2.
PRECISE_TIME_DECIMALS = 2
# Type: int; Units: decimal places; Scope: reported GPS coordinates.
# Consumers: _coordinate; Rationale: show useful position precision without
# implying gear-impact accuracy; value: 5.
COORDINATE_DECIMALS = 5
# Type: int; Units: samples per second; Scope: acquisition-method note.
# Consumers: _methodology_notes; Rationale: report the recorder target while
# acknowledging that actual simulator delivery varies; value: 20.
RECORDER_TARGET_HZ = 20
# Type: int; Units: decimal places; Scope: disclosed analysis thresholds.
# Consumers: _methodology_notes; Rationale: retain the configured 0.25-second
# gap boundary instead of rounding it into a misleading 0.3-second claim;
# value: 2.
METHOD_THRESHOLD_DECIMALS = 2
# Type: float; Units: feet per metre; Scope: approach/rollout height chart.
# Consumers: _landing_section; Rationale: display captured SI altitude in
# aviation height units without altering stored values; value: 3.280839895.
METRES_TO_FEET = 3.280839895
# Type: float; Units: feet per minute per metre per second;
# Scope: observed vertical-speed charts; Consumers: _build_story and
# _landing_section; Rationale: distinguish converted sample rate from landing
# analysis rate; value: 196.8503937.
FEET_PER_MINUTE_PER_MPS = 196.8503937
# Type: float; Units: seconds before detected first contact;
# Scope: each landing profile; Consumers: _landing_samples;
# Rationale: show a fixed one-minute approach context where samples exist;
# value: 60.0.
PRECONTACT_WINDOW_SECONDS = 60.0
# Type: float; Units: seconds after detected last contact;
# Scope: each landing profile; Consumers: _landing_samples;
# Rationale: show a fixed two-minute rollout context where samples exist;
# value: 120.0.
ROLLOUT_WINDOW_SECONDS = 120.0

_COLOR_INK = colors.HexColor("#17324D")
_COLOR_BLUE = colors.HexColor("#1677A8")
_COLOR_GREEN = colors.HexColor("#25836B")
_COLOR_RED = colors.HexColor("#C64A45")
_COLOR_MUTED = colors.HexColor("#637381")
_COLOR_GRID = colors.HexColor("#D9E2E8")
_COLOR_PANEL = colors.HexColor("#F2F6F8")
_FONT_NAME = "FlightReportSans"
_REQUIRED_GLYPHS = "Příliš žluťoučký kůň åäö üß"


def _resource_directory() -> Path:
    """Return the directory containing bundled report assets.

    Returns:
        Path: Installed package resource directory beside this module.
    """
    return Path(__file__).resolve().parent / "resources"


def _register_font() -> None:
    """Register the bundled DejaVu font with required European glyphs.

    Returns:
        None: Register the regular font family for ReportLab paragraphs.
    """
    if _FONT_NAME in pdfmetrics.getRegisteredFontNames():
        return
    font_path = _resource_directory() / "DejaVuSans.ttf"
    if not font_path.is_file():
        raise FileNotFoundError(
            "Postflight font asset is missing: " + str(font_path)
        )
    font = TTFont(_FONT_NAME, str(font_path))
    char_to_glyph = font.face.charToGlyph
    missing = [
        char
        for char in _REQUIRED_GLYPHS
        if char not in " "
        and char_to_glyph.get(ord(char), 0) == 0
    ]
    if missing:
        raise ValueError(
            "Bundled postflight font lacks required glyphs: "
            + ", ".join(sorted(set(missing)))
        )
    pdfmetrics.registerFont(font)


def _analysis_identity(analysis: FlightAnalysis) -> Tuple[Dict[str, object], str]:
    """Return canonical payload and checksum for one immutable analysis.

    Args:
        analysis (FlightAnalysis): Shared normalized model for either simulator.

    Returns:
        Tuple[Dict[str, object], str]: JSON-safe payload and SHA-256 identity
            checksum.
    """
    payload = analysis.to_payload()
    canonical = json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
        allow_nan=False,
    ).encode("utf-8")
    return payload, hashlib.sha256(canonical).hexdigest()


def _atomic_write(path: Path, content: bytes) -> None:
    """Replace one file atomically using a temporary sibling.

    Args:
        path (Path): Destination file in the target output directory.
        content (bytes): Complete file contents to install.

    Returns:
        None: Replace the destination after all bytes are written.

    Raises:
        OSError: Raised when the directory or filesystem cannot accept output.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    handle = tempfile.NamedTemporaryFile(
        mode="wb",
        prefix=path.name + ".",
        suffix=".tmp",
        dir=str(path.parent),
        delete=False,
    )
    temporary_path = Path(handle.name)
    try:
        with handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary_path, path)
    finally:
        if temporary_path.exists():
            temporary_path.unlink()


def _manifest_path(destination: Path) -> Path:
    """Return the adjacent manifest path for one PDF destination.

    Args:
        destination (Path): PDF output path.

    Returns:
        Path: Sidecar JSON path with the PDF suffix retained.
    """
    return destination.with_suffix(destination.suffix + ".manifest.json")


def _reusable_report(
    destination: Path,
    analysis_sha256: str,
    language: str,
) -> bool:
    """Check whether an existing PDF matches its complete identity manifest.

    Args:
        destination (Path): Candidate previously rendered PDF.
        analysis_sha256 (str): Current canonical flight-data checksum.
        language (str): Requested report language.

    Returns:
        bool: True only when file checksum, model, language and template match.
    """
    manifest_path = _manifest_path(destination)
    if not destination.is_file() or not manifest_path.is_file():
        return False
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        actual_sha256 = hashlib.sha256(destination.read_bytes()).hexdigest()
    except (OSError, ValueError):
        return False
    return (
        manifest.get("analysis_sha256") == analysis_sha256
        and manifest.get("language") == language
        and manifest.get("template_version") == REPORT_TEMPLATE_VERSION
        and manifest.get("pdf_sha256") == actual_sha256
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
    from xml.sax.saxutils import escape

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
            fontName=_FONT_NAME,
            fontSize=23,
            leading=27,
            textColor=_COLOR_INK,
            alignment=TA_LEFT,
            spaceAfter=3 * mm,
        ),
        "subtitle": ParagraphStyle(
            "FlightSubtitle",
            parent=base["Normal"],
            fontName=_FONT_NAME,
            fontSize=9,
            leading=13,
            textColor=_COLOR_MUTED,
            spaceAfter=6 * mm,
        ),
        "heading": ParagraphStyle(
            "FlightHeading",
            parent=base["Heading2"],
            keepWithNext=True,
            fontName=_FONT_NAME,
            fontSize=13,
            leading=17,
            textColor=_COLOR_INK,
            spaceBefore=4 * mm,
            spaceAfter=2 * mm,
        ),
        "body": ParagraphStyle(
            "FlightBody",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=9,
            leading=13,
            textColor=_COLOR_INK,
        ),
        "small": ParagraphStyle(
            "FlightSmall",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=7.5,
            leading=10,
            textColor=_COLOR_MUTED,
        ),
        "label": ParagraphStyle(
            "FlightLabel",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=7.5,
            leading=10,
            textColor=_COLOR_MUTED,
        ),
        "value": ParagraphStyle(
            "FlightValue",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=11,
            leading=14,
            textColor=_COLOR_INK,
        ),
        "center": ParagraphStyle(
            "FlightCenter",
            parent=base["BodyText"],
            fontName=_FONT_NAME,
            fontSize=8,
            leading=11,
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
) -> Drawing:
    """Draw a vector sensor profile without connecting missing observations.

    Args:
        analysis (FlightAnalysis): Source session for default samples.
        attribute (str): Numeric FlightSample attribute for the vertical axis.
        label (str): Localized chart title.
        color (colors.Color): Line color for the plotted series.
        scale (float): Multiplier converting source SI values to display units.
        unit (str): Display unit appended to the chart title.
        language (str): Language used for chart labels and missing-state text.
        samples (Optional[Sequence[FlightSample]]): Optional landing window.
        x_bounds (Optional[Tuple[float, float]]): Fixed session-second bounds
            shared by synchronized approach charts.
        zero_time (float): Session-second origin shown as T+0.
        contact_bounds (Optional[Tuple[float, float]]): Exact detected contact
            start and end times marked on landing-window charts.

    Returns:
        Drawing: Vector chart with all observed gaps left visually open.
    """
    drawing = Drawing(CHART_WIDTH, CHART_HEIGHT)
    left, bottom = 48, 30
    plot_width, plot_height = CHART_WIDTH - 62, CHART_HEIGHT - 52
    chart_title = label
    unit_suffix = " (" + unit + ")"
    if not chart_title.rstrip().endswith(unit_suffix):
        chart_title += unit_suffix
    drawing.add(
        String(
            0,
            CHART_HEIGHT - 12,
            chart_title,
            fontName=_FONT_NAME,
            fontSize=8,
            fillColor=_COLOR_INK,
        )
    )
    chosen_samples = analysis.samples if samples is None else samples
    points = _chart_series(chosen_samples, attribute)
    valid_points = [
        (time_value, value)
        for time_value, value, _ in points
        if time_value is not None and value is not None
    ]
    if x_bounds is not None:
        min_x, max_x = x_bounds
    elif valid_points:
        min_x = min(point[0] for point in valid_points)
        max_x = max(point[0] for point in valid_points)
    else:
        min_x, max_x = 0.0, 1.0
    if max_x <= min_x:
        max_x = min_x + 1.0
    if valid_points:
        scaled_values = [value * scale for _, value in valid_points]
        min_y = min(scaled_values)
        max_y = max(scaled_values)
    else:
        min_y, max_y = 0.0, 1.0
        drawing.add(
            String(
                left,
                bottom + plot_height / 2,
                tr(language, "unavailable"),
                fontName=_FONT_NAME,
                fontSize=8,
                fillColor=_COLOR_MUTED,
            )
        )
    if max_y == min_y:
        max_y += 1.0
        min_y -= 1.0
    for fraction in (0.0, 0.5, 1.0):
        y = bottom + fraction * plot_height
        drawing.add(
            Line(
                left,
                y,
                left + plot_width,
                y,
                strokeColor=_COLOR_GRID,
                strokeWidth=0.5,
            )
        )
    drawing.add(
        Line(
            left,
            bottom,
            left,
            bottom + plot_height,
            strokeColor=_COLOR_MUTED,
            strokeWidth=0.6,
        )
    )
    drawing.add(
        Line(
            left,
            bottom,
            left + plot_width,
            bottom,
            strokeColor=_COLOR_MUTED,
            strokeWidth=0.6,
        )
    )
    drawing.add(
        String(
            left,
            7,
            _relative_time(min_x, zero_time),
            fontName=_FONT_NAME,
            fontSize=6,
            fillColor=_COLOR_MUTED,
        )
    )
    drawing.add(
        String(
            left + plot_width - 38,
            7,
            _relative_time(max_x, zero_time),
            fontName=_FONT_NAME,
            fontSize=6,
            fillColor=_COLOR_MUTED,
        )
    )
    drawing.add(
        String(
            left + plot_width / 2 - 35,
            18,
            tr(
                language,
                "contact_relative" if contact_bounds is not None else "elapsed",
            ),
            fontName=_FONT_NAME,
            fontSize=5.5,
            fillColor=_COLOR_MUTED,
        )
    )
    drawing.add(
        String(
            2,
            bottom + plot_height - 2,
            _number(max_y, language),
            fontName=_FONT_NAME,
            fontSize=5.5,
            fillColor=_COLOR_MUTED,
        )
    )
    drawing.add(
        String(
            2,
            bottom - 2,
            _number(min_y, language),
            fontName=_FONT_NAME,
            fontSize=5.5,
            fillColor=_COLOR_MUTED,
        )
    )
    if contact_bounds is not None:
        for contact_time in dict.fromkeys(contact_bounds):
            if min_x <= contact_time <= max_x:
                x = left + (contact_time - min_x) / (max_x - min_x) * plot_width
                drawing.add(
                    Line(
                        x,
                        bottom,
                        x,
                        bottom + plot_height,
                        strokeColor=_COLOR_RED,
                        strokeWidth=1.0,
                    )
                )
    segment: List[float] = []
    previous_time: Optional[float] = None
    for elapsed, value, break_before in points:
        if (
            break_before
            or elapsed is None
            or value is None
            or (previous_time is not None and elapsed <= previous_time)
        ):
            _draw_profile_segment(drawing, segment, color)
            segment = []
            previous_time = None
            if elapsed is None or value is None:
                continue
        x = left + (elapsed - min_x) / (max_x - min_x) * plot_width
        y = bottom + (value * scale - min_y) / (max_y - min_y) * plot_height
        segment.extend([x, y])
        previous_time = elapsed
    _draw_profile_segment(drawing, segment, color)
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


def _route_chart(analysis: FlightAnalysis, language: str) -> Drawing:
    """Draw only captured aircraft positions and preserve all coordinate gaps.

    Args:
        analysis (FlightAnalysis): Ordered user-aircraft position samples.
        language (str): Language used for the route heading.

    Returns:
        Drawing: Recorded aircraft-position chart; no camera route is inferred.
    """
    drawing = Drawing(CHART_WIDTH, CHART_HEIGHT)
    drawing.add(
        String(
            0,
            CHART_HEIGHT - 12,
            tr(language, "aircraft_track"),
            fontName=_FONT_NAME,
            fontSize=8,
            fillColor=_COLOR_INK,
        )
    )
    coordinates: List[Tuple[Optional[float], Optional[float], bool]] = []
    for sample in analysis.samples:
        latitude = sample.latitude_deg
        longitude = sample.longitude_deg
        if sample.discontinuity_before is not None:
            coordinates.append((None, None, True))
        if latitude is None or longitude is None:
            coordinates.append((None, None, True))
            continue
        if not (-90 <= latitude <= 90 and -180 <= longitude <= 180):
            coordinates.append((None, None, True))
            continue
        coordinates.append((float(longitude), float(latitude), False))
    unwrapped_coordinates: List[
        Tuple[Optional[float], Optional[float], bool]
    ] = []
    previous_longitude: Optional[float] = None
    for longitude, latitude, break_before in coordinates:
        if break_before or longitude is None or latitude is None:
            unwrapped_coordinates.append((None, None, True))
            previous_longitude = None
            continue
        adjusted_longitude = longitude
        if previous_longitude is not None:
            while adjusted_longitude - previous_longitude > 180.0:
                adjusted_longitude -= 360.0
            while adjusted_longitude - previous_longitude < -180.0:
                adjusted_longitude += 360.0
        unwrapped_coordinates.append(
            (adjusted_longitude, latitude, False)
        )
        previous_longitude = adjusted_longitude
    coordinates = unwrapped_coordinates
    valid_coordinates = [
        (longitude, latitude)
        for longitude, latitude, _ in coordinates
        if longitude is not None and latitude is not None
    ]
    left, bottom = 32, 18
    width, height = CHART_WIDTH - 48, CHART_HEIGHT - 38
    if not valid_coordinates:
        drawing.add(
            String(
                left,
                bottom + height / 2,
                tr(language, "unavailable"),
                fontName=_FONT_NAME,
                fontSize=9,
                fillColor=_COLOR_MUTED,
            )
        )
        return drawing
    min_x = min(point[0] for point in valid_coordinates)
    max_x = max(point[0] for point in valid_coordinates)
    min_y = min(point[1] for point in valid_coordinates)
    max_y = max(point[1] for point in valid_coordinates)
    span_x = max(max_x - min_x, 0.0001)
    span_y = max(max_y - min_y, 0.0001)
    scale = min(width / span_x, height / span_y)
    offset_x = left + (width - span_x * scale) / 2
    offset_y = bottom + (height - span_y * scale) / 2
    drawing.add(
        Line(
            left,
            bottom,
            left + width,
            bottom,
            strokeColor=_COLOR_GRID,
            strokeWidth=0.5,
        )
    )
    drawing.add(
        Line(
            left,
            bottom,
            left,
            bottom + height,
            strokeColor=_COLOR_GRID,
            strokeWidth=0.5,
        )
    )
    segment: List[float] = []
    drawn_points: List[Tuple[float, float]] = []
    for longitude, latitude, break_before in coordinates:
        if break_before or longitude is None or latitude is None:
            _draw_route_segment(drawing, segment)
            segment = []
            if longitude is None or latitude is None:
                continue
        x = offset_x + (longitude - min_x) * scale
        y = offset_y + (latitude - min_y) * scale
        segment.extend([x, y])
        drawn_points.append((x, y))
    _draw_route_segment(drawing, segment)
    if drawn_points:
        drawing.add(
            Circle(
                drawn_points[0][0],
                drawn_points[0][1],
                3,
                fillColor=_COLOR_GREEN,
                strokeColor=None,
            )
        )
        drawing.add(
            Circle(
                drawn_points[-1][0],
                drawn_points[-1][1],
                3,
                fillColor=_COLOR_INK,
                strokeColor=None,
            )
        )
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


def _landing_samples(
    analysis: FlightAnalysis,
    landing: Landing,
) -> Tuple[List[FlightSample], Tuple[float, float], Tuple[float, float]]:
    """Select observed samples around one detected landing window.

    Args:
        analysis (FlightAnalysis): Shared session with ordered sensor samples.
        landing (Landing): Detected first/last contact interval.

    Returns:
        Tuple[List[FlightSample], Tuple[float, float], Tuple[float, float]]:
            Captured samples in range, fixed display bounds, and exact contact
            bounds in session elapsed seconds.
    """
    contact_start = float(landing.first_contact_elapsed_seconds)
    contact_end = float(landing.last_contact_elapsed_seconds)
    window_start = contact_start - PRECONTACT_WINDOW_SECONDS
    window_end = contact_end + ROLLOUT_WINDOW_SECONDS
    samples = [
        sample
        for sample in analysis.samples
        if window_start <= sample.session_elapsed_seconds <= window_end
    ]
    return samples, (window_start, window_end), (contact_start, contact_end)


def _landing_event_table(
    samples: Sequence[FlightSample],
    landing: Landing,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> Table:
    """Summarize captured configuration transitions and contact events.

    Args:
        samples (Sequence[FlightSample]): Captured samples in contact window.
        landing (Landing): Detected contact interval with evidence.
        language (str): Localized event labels.
        styles (Dict[str, ParagraphStyle]): Paragraph styles for event rows.

    Returns:
        Table: Time-ordered observed state transitions, with no interpolation.
    """
    events: List[Tuple[float, str, str]] = []
    contact_start = float(landing.first_contact_elapsed_seconds)
    events.append(
        (
            contact_start,
            tr(language, "first_contact"),
            tr(language, "sampled_state"),
        )
    )
    events.append(
        (
            float(landing.last_contact_elapsed_seconds),
            tr(language, "last_contact"),
            tr(language, "sampled_state"),
        )
    )
    previous: Optional[FlightSample] = None
    for sample in samples:
        elapsed = float(sample.session_elapsed_seconds)
        relative = _precise_elapsed(elapsed, contact_start, language)
        if sample.discontinuity_before is not None:
            events.append(
                (
                    elapsed,
                    tr(language, "recorder_gap"),
                    _safe_source_text(sample.discontinuity_before),
                )
            )
            state_parts = _sampled_state_parts(sample, language)
            if state_parts:
                events.append(
                    (
                        elapsed,
                        tr(language, "sampled_state"),
                        "; ".join(state_parts),
                    )
                )
            previous = sample
            continue
        if previous is None:
            state_parts = _sampled_state_parts(sample, language)
            if state_parts:
                events.append(
                    (
                        elapsed,
                        tr(language, "sampled_state"),
                        "; ".join(state_parts),
                    )
                )
            previous = sample
            continue
        if (
            sample.on_ground is not None
            and previous.on_ground is not None
            and sample.on_ground != previous.on_ground
        ):
            events.append(
                (
                    elapsed,
                    tr(language, "on_ground")
                    if sample.on_ground
                    else tr(language, "airborne"),
                    relative,
                )
            )
        for attribute, label in (
            ("gear_position", "gear"),
            ("flaps_position", "flaps"),
        ):
            current = getattr(sample, attribute)
            prior = getattr(previous, attribute)
            if current is not None and prior is not None and current != prior:
                events.append(
                    (
                        elapsed,
                        tr(language, label),
                        _number(float(current), language) + "%",
                    )
                )
        for light_name in sorted(set(previous.lights) | set(sample.lights)):
            current_light = sample.lights.get(light_name)
            prior_light = previous.lights.get(light_name)
            if (
                prior_light is not None
                and current_light is not None
                and current_light != prior_light
            ):
                events.append(
                    (
                        elapsed,
                        tr(language, "light") + " " + light_name,
                        tr(language, "state_on")
                        if current_light
                        else tr(language, "state_off"),
                    )
                )
        previous = sample

    rows: List[List[Union[Paragraph, str]]] = [
        [
            _paragraph(tr(language, "contact_elapsed_short"), styles["small"]),
            _paragraph(tr(language, "recorded_events"), styles["small"]),
            _paragraph(tr(language, "detail"), styles["small"]),
        ]
    ]
    if not events:
        rows.append(
            [
                "",
                _paragraph(tr(language, "no_state_changes"), styles["small"]),
                "",
            ]
        )
    else:
        for elapsed, event_name, detail in sorted(events, key=lambda row: row[0]):
            rows.append(
                [
                    _paragraph(
                        _precise_elapsed(elapsed, contact_start, language),
                        styles["small"],
                    ),
                    _paragraph(event_name, styles["small"]),
                    _paragraph(detail, styles["small"]),
                ]
            )
    table = Table(rows, colWidths=[28 * mm, 53 * mm, 90 * mm], repeatRows=1)
    table.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, 0), _COLOR_PANEL),
                ("LINEBELOW", (0, 0), (-1, 0), 0.6, _COLOR_GRID),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 2),
                ("RIGHTPADDING", (0, 0), (-1, -1), 2),
                ("TOPPADDING", (0, 0), (-1, -1), 3),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
            ]
        )
    )
    return table


def _sampled_state_parts(
    sample: FlightSample,
    language: str,
) -> List[str]:
    """Describe the first observed configuration in a landing window.

    Args:
        sample (FlightSample): Exact sample at the beginning of the displayed
            window.
        language (str): Localized state labels.

    Returns:
        List[str]: Known gear, flap, ground and light states; omitted values
            remain unavailable rather than defaulting to zero or off.
    """
    values: List[str] = []
    if sample.on_ground is not None:
        values.append(
            tr(language, "on_ground")
            if sample.on_ground
            else tr(language, "airborne")
        )
    if sample.gear_position is not None:
        values.append(
            tr(language, "gear")
            + " "
            + _number(float(sample.gear_position), language)
            + "%"
        )
    if sample.flaps_position is not None:
        values.append(
            tr(language, "flaps")
            + " "
            + _number(float(sample.flaps_position), language)
            + "%"
        )
    for light_name, enabled in sorted(sample.lights.items()):
        if enabled is not None:
            values.append(
                tr(language, "light")
                + " "
                + light_name
                + " "
                + (
                    tr(language, "state_on")
                    if enabled
                    else tr(language, "state_off")
                )
            )
    return values


def _landing_section(
    analysis: FlightAnalysis,
    landing: Landing,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[Flowable]:
    """Build synchronized precontact/rollout sensor charts and event timeline.

    Args:
        analysis (FlightAnalysis): Shared immutable flight-analysis model.
        landing (Landing): One detected landing and exact contact interval.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        List[Flowable]: Landing heading, synchronized profiles, and sampled
            configuration/event timeline.
    """
    samples, bounds, contacts = _landing_samples(analysis, landing)
    zero_time = contacts[0]
    title = (
        tr(language, "approach_rollout")
        + " — "
        + tr(language, "landing")
        + " "
        + str(landing.index)
    )
    story: List[Flowable] = [
        _paragraph(title, styles["heading"]),
        _paragraph(
            tr(language, "observed_only")
            + "  "
            + tr(language, "contact_relative"),
            styles["small"],
        ),
        _profile_chart(
            analysis,
            "agl_m",
            tr(language, "agl_altitude"),
            _COLOR_BLUE,
            METRES_TO_FEET,
            "ft",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "ground_speed_mps",
            tr(language, "ground_speed"),
            _COLOR_GREEN,
            KNOTS_PER_MPS,
            "kt",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "vertical_speed_mps",
            tr(language, "vertical_speed"),
            _COLOR_RED,
            FEET_PER_MINUTE_PER_MPS,
            "ft/min",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "pitch_deg",
            tr(language, "pitch"),
            _COLOR_BLUE,
            1.0,
            "°",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "bank_deg",
            tr(language, "bank"),
            _COLOR_GREEN,
            1.0,
            "°",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "indicated_airspeed_mps",
            tr(language, "indicated_airspeed"),
            _COLOR_BLUE,
            KNOTS_PER_MPS,
            "kt",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "load_factor_g",
            tr(language, "load_factor"),
            _COLOR_GREEN,
            1.0,
            "g",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        *_contact_sensor_tables(landing, language, styles),
        _landing_event_table(samples, landing, language, styles),
        Spacer(1, 2 * mm),
    ]
    return story


def _translate_confidence(value: str, language: str) -> str:
    """Translate known evidence confidence values while preserving unknowns.

    Args:
        value (str): Stable confidence category from landing analysis.
        language (str): Report language.

    Returns:
        str: Localized category or original nonempty value when unknown.
    """
    normalized = value.strip().lower()
    translated = tr(language, "confidence_" + normalized)
    if translated != "confidence_" + normalized:
        return translated
    return value or tr(language, "unavailable")


def _translate_phase_event(value: str, language: str) -> str:
    """Translate known recorder phase/event codes with a localized fallback.

    Args:
        value (str): Stable phase or event code emitted by analysis.
        language (str): Report language.

    Returns:
        str: Localized event name, or localized generic phase plus source code.
    """
    normalized = value.strip().lower().replace("-", "_").replace(" ", "_")
    key = "event_" + normalized
    translated = tr(language, key)
    if translated != key:
        return translated
    return tr(language, "phase_event") + ": " + value


def _phase_event_table(
    analysis: FlightAnalysis,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> Table:
    """Build a session-elapsed phase timeline from analyzer evidence.

    Args:
        analysis (FlightAnalysis): Immutable analysis and ordered phase events.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Paragraph styles for table cells.

    Returns:
        Table: Localized timeline; absent events are stated without inference.
    """
    headings = ["phase_time_seconds", "phase", "confidence", "evidence"]
    rows: List[List[Union[Paragraph, str]]] = [
        [_paragraph(tr(language, key), styles["small"]) for key in headings]
    ]
    events = getattr(analysis, "events", ())
    if not events:
        rows.append([
            "",
            _paragraph(tr(language, "no_phase_events"), styles["small"]),
            "",
            "",
        ])
    else:
        for event in events:
            confidence_key = "confidence_" + event.confidence.strip().lower()
            confidence = tr(language, confidence_key)
            if confidence == confidence_key:
                confidence = tr(language, "confidence_uncertain")
            evidence = ", ".join(
                _safe_source_text(value) for value in event.evidence
            ) or tr(language, "unavailable")
            rows.append([
                _paragraph(
                    _precise_elapsed(event.elapsed_seconds, 0.0, language),
                    styles["small"],
                ),
                _paragraph(_translate_phase_event(event.phase, language), styles["small"]),
                _paragraph(confidence, styles["small"]),
                _paragraph(evidence, styles["small"]),
            ])
    table = Table(
        rows,
        colWidths=[24 * mm, 39 * mm, 28 * mm, 83 * mm],
        repeatRows=1,
        hAlign="LEFT",
    )
    table.setStyle(
        TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), _COLOR_PANEL),
            ("LINEBELOW", (0, 0), (-1, 0), 0.6, _COLOR_GRID),
            ("LINEBELOW", (0, 1), (-1, -1), 0.35, _COLOR_GRID),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 2),
            ("RIGHTPADDING", (0, 0), (-1, -1), 2),
            ("TOPPADDING", (0, 0), (-1, -1), 3),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ])
    )
    return table


def _contact_configuration(sample: FlightSample, language: str) -> str:
    """Format known brake, gear, flap, light and autopilot states.

    Args:
        sample (FlightSample): Full-resolution contact sensor observation.
        language (str): Language for configuration labels and state values.

    Returns:
        str: Known values separated by semicolons, or unavailable.
    """
    parts: List[str] = []
    for attribute, key in (
        ("gear_position", "gear"),
        ("flaps_position", "flaps"),
        ("left_brake_position", "brakes"),
        ("right_brake_position", "brakes"),
    ):
        value = getattr(sample, attribute, None)
        if value is not None and math.isfinite(float(value)):
            parts.append(
                tr(language, key) + " "
                + _number(float(value), language) + "%"
            )
    if sample.autopilot_master is not None:
        parts.append(
            tr(language, "autopilot") + " "
            + (tr(language, "yes") if sample.autopilot_master else tr(language, "no"))
        )
    for name, enabled in sorted(sample.lights.items()):
        if enabled is not None:
            parts.append(
                tr(language, "light") + " " + _safe_source_text(name) + " "
                + (tr(language, "state_on") if enabled else tr(language, "state_off"))
            )
    return "; ".join(parts) or tr(language, "unavailable")


def _contact_sensor_tables(
    landing: Landing,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[Flowable]:
    """Build synchronized tables from retained full-resolution contact rows.

    Args:
        landing (Landing): One landing containing optional contact snapshots.
        language (str): Language for all sensor headings and values.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        List[Flowable]: Two compact tables for motion and configuration data.
    """
    samples = getattr(landing, "contact_samples", ())
    rates = getattr(landing, "contact_descent_rates_fpm", ())
    motion_headings = [
        "contact_elapsed_short",
        "latitude_short",
        "longitude_short",
        "contact_descent_short",
        "contact_ias_short",
        "contact_ground_speed_short",
        "contact_vertical_speed_short",
    ]
    state_headings = [
        "contact_elapsed_short",
        "pitch",
        "bank",
        "heading",
        "wind",
        "configuration",
        "load_factor",
        "fuel",
    ]
    motion_rows: List[List[Union[Paragraph, str]]] = [[
        _paragraph(tr(language, key), styles["small"])
        for key in motion_headings
    ]]
    state_rows: List[List[Union[Paragraph, str]]] = [[
        _paragraph(tr(language, key), styles["small"])
        for key in state_headings
    ]]
    if not samples:
        empty = _paragraph(tr(language, "no_contact_samples"), styles["small"])
        motion_rows.append(["", empty, "", "", "", "", ""])
        state_rows.append(["", empty, "", "", "", "", "", ""])
    else:
        for index, sample in enumerate(samples):
            elapsed = _precise_elapsed(
                float(sample.session_elapsed_seconds),
                float(landing.first_contact_elapsed_seconds),
                language,
            )
            latitude = _coordinate(
                getattr(sample, "latitude_deg", None), language
            )
            longitude = _coordinate(
                getattr(sample, "longitude_deg", None), language
            )
            contact_rate = (
                rates[index]
                if index < len(rates)
                else None
            )
            descent = (
                tr(language, "unavailable")
                if contact_rate is None
                else _number(float(contact_rate), language) + " ft/min"
            )
            indicated = getattr(sample, "indicated_airspeed_mps", None)
            ground = getattr(sample, "ground_speed_mps", None)
            vertical = getattr(sample, "vertical_speed_mps", None)
            vertical_text = (
                tr(language, "unavailable")
                if vertical is None
                else _number(
                    float(vertical) * FEET_PER_MINUTE_PER_MPS,
                    language,
                ) + " ft/min"
            )
            motion_rows.append([
                _paragraph(elapsed, styles["small"]),
                _paragraph(latitude, styles["small"]),
                _paragraph(longitude, styles["small"]),
                _paragraph(descent, styles["small"]),
                _paragraph(_format_speed(indicated, language), styles["small"]),
                _paragraph(_format_speed(ground, language), styles["small"]),
                _paragraph(vertical_text, styles["small"]),
            ])
            wind_velocity = getattr(sample, "wind_velocity_mps", None)
            wind_direction = getattr(sample, "wind_direction_deg", None)
            if wind_velocity is None and wind_direction is None:
                wind = tr(language, "unavailable")
            else:
                wind = (
                    _format_speed(wind_velocity, language)
                    + " / "
                    + (
                        _number(float(wind_direction), language) + "°"
                        if wind_direction is not None
                        else tr(language, "unavailable")
                    )
                )
            state_values = [
                _number(getattr(sample, "pitch_deg", None), language) + "°"
                if getattr(sample, "pitch_deg", None) is not None
                else tr(language, "unavailable"),
                _number(getattr(sample, "bank_deg", None), language) + "°"
                if getattr(sample, "bank_deg", None) is not None
                else tr(language, "unavailable"),
                _number(getattr(sample, "heading_deg", None), language) + "°"
                if getattr(sample, "heading_deg", None) is not None
                else tr(language, "unavailable"),
                wind,
                _contact_configuration(sample, language),
                (
                    _number(float(sample.load_factor_g), language) + " g"
                    if getattr(sample, "load_factor_g", None) is not None
                    else tr(language, "unavailable")
                ),
                (
                    _number(float(sample.fuel_gallons), language) + " gal"
                    if getattr(sample, "fuel_gallons", None) is not None
                    else tr(language, "unavailable")
                ),
            ]
            state_rows.append([
                _paragraph(elapsed, styles["small"]),
                *[
                    _paragraph(value, styles["small"])
                    for value in state_values
                ],
            ])
    motion_table = Table(
        motion_rows,
        colWidths=[18 * mm, 20 * mm, 20 * mm, 25 * mm, 18 * mm, 18 * mm, 22 * mm],
        repeatRows=1,
        hAlign="LEFT",
    )
    state_table = Table(
        state_rows,
        colWidths=[18 * mm, 14 * mm, 14 * mm, 17 * mm, 25 * mm, 44 * mm, 18 * mm, 18 * mm],
        repeatRows=1,
        hAlign="LEFT",
    )
    for table in (motion_table, state_table):
        table.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), _COLOR_PANEL),
            ("LINEBELOW", (0, 0), (-1, 0), 0.6, _COLOR_GRID),
            ("LINEBELOW", (0, 1), (-1, -1), 0.35, _COLOR_GRID),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 2),
            ("RIGHTPADDING", (0, 0), (-1, -1), 2),
            ("TOPPADDING", (0, 0), (-1, -1), 3),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ]))
    return [
        _paragraph(tr(language, "contact_observations"), styles["heading"]),
        motion_table,
        state_table,
    ]


def _method_threshold(value: float, language: str) -> str:
    """Format configured algorithm thresholds without obscuring precision.

    Args:
        value (float): Threshold value in its documented unit.
        language (str): Report language used for decimal punctuation.

    Returns:
        str: Two-decimal value preserving the actual configured boundary.
    """
    result = format(float(value), ".{}f".format(METHOD_THRESHOLD_DECIMALS))
    return result.replace(".", ",") if language in ("cs", "de", "sv") else result


def _methodology_notes(
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[Paragraph]:
    """Explain source algorithms, reference points and capture limits.

    Args:
        language (str): Supported report language.
        styles (Dict[str, ParagraphStyle]): Paragraph styles for notes.

    Returns:
        List[Paragraph]: Localized, version-derived method and uncertainty notes.
    """
    values = {
        "window": _method_threshold(MAX_DESCENT_FIT_SECONDS, language),
        "minimum": _method_threshold(MIN_DESCENT_FIT_SECONDS, language),
        "maximum_gap": _method_threshold(
            MAX_DESCENT_SAMPLE_GAP_SECONDS,
            language,
        ),
        "rate": str(RECORDER_TARGET_HZ),
        "precontact": _method_threshold(PRECONTACT_WINDOW_SECONDS, language),
        "rollout": _method_threshold(ROLLOUT_WINDOW_SECONDS, language),
    }
    keys = (
        "descent_method",
        "reference_method",
        "runway_method",
        "gap_method",
        "sampling_method",
        "contact_method",
        "landing_window_method",
    )
    return [
        _paragraph(tr(language, key).format(**values), styles["small"])
        for key in keys
    ]


def _landing_rows(
    analysis: FlightAnalysis,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[List[Union[Paragraph, str]]]:
    """Build localized landing-window table rows from detected evidence.

    Args:
        analysis (FlightAnalysis): Landing windows detected by flight analysis.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        List[List[Union[Paragraph, str]]]: Header plus one row per landing, with unavailable
            measurements clearly labeled.
    """
    headings = [
        "landing",
        "window",
        "landing_descent",
        "contacts",
        "confidence",
        "bounce",
        "previous_distance",
    ]
    rows: List[List[Union[Paragraph, str]]] = [
        [_paragraph(tr(language, key), styles["small"]) for key in headings]
    ]
    for landing in analysis.landings:
        start = _format_duration(landing.first_contact_elapsed_seconds, language)
        end = _format_duration(landing.last_contact_elapsed_seconds, language)
        descent = (
            tr(language, "unavailable")
            if landing.descent_rate_fpm is None
            else _number(landing.descent_rate_fpm, language) + " fpm"
        )
        distance = (
            tr(language, "unavailable")
            if landing.distance_from_previous_nm is None
            else _number(landing.distance_from_previous_nm, language) + " nm"
        )
        evidence = ", ".join(
            _safe_source_text(value) for value in landing.evidence
        ) or tr(language, "unavailable")
        latitude = _coordinate(landing.latitude_deg, language)
        longitude = _coordinate(landing.longitude_deg, language)
        location = (
            tr(language, "latitude") + ": " + latitude + " · "
            + tr(language, "longitude") + ": " + longitude
        )
        rollout_distance = (
            tr(language, "unavailable")
            if getattr(landing, "rollout_distance_nm", None) is None
            else _number(landing.rollout_distance_nm, language) + " nm"
        )
        rollout = (
            tr(language, "rollout_duration") + ": "
            + _format_duration(
                getattr(landing, "rollout_seconds", None),
                language,
            )
            + " · "
            + tr(language, "rollout_distance")
            + ": "
            + rollout_distance
        )
        rows.append(
            [
                _paragraph(str(landing.index), styles["center"]),
                _paragraph(start + " – " + end, styles["small"]),
                _paragraph(descent, styles["small"]),
                _paragraph(str(landing.contact_count), styles["center"]),
                _paragraph(
                    _translate_confidence(landing.confidence, language),
                    styles["small"],
                ),
                _paragraph(
                    tr(language, "yes") if landing.bounce_possible else tr(language, "no"),
                    styles["small"],
                ),
                _paragraph(distance, styles["small"]),
            ]
        )
        rows.append(
            [
                _paragraph(
                    location + " · " + tr(language, "location_caveat")
                    + " · " + rollout
                    + " · " + tr(language, "evidence") + ": " + evidence,
                    styles["small"],
                ),
                "",
                "",
                "",
                "",
                "",
                "",
            ]
        )
    return rows


def _landing_table(
    analysis: FlightAnalysis,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> Table:
    """Create the landing summary table or explicit empty-state message.

    Args:
        analysis (FlightAnalysis): Detected landing windows.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        Table: Landing rows, or a single message when none were detected.
    """
    if not analysis.landings:
        table = Table(
            [[_paragraph(tr(language, "no_landings"), styles["body"])]],
            colWidths=[165 * mm],
        )
        table.setStyle(
            TableStyle(
                [
                    ("BACKGROUND", (0, 0), (-1, -1), _COLOR_PANEL),
                    ("BOX", (0, 0), (-1, -1), 0.5, _COLOR_GRID),
                    ("PADDING", (0, 0), (-1, -1), 3 * mm),
                ]
            )
        )
        return table
    widths = [13 * mm, 29 * mm, 23 * mm, 18 * mm, 25 * mm, 22 * mm, 27 * mm]
    rows = _landing_rows(analysis, language, styles)
    table = Table(
        rows,
        colWidths=widths,
        repeatRows=1,
        hAlign="LEFT",
    )
    table.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, 0), _COLOR_PANEL),
                ("LINEBELOW", (0, 0), (-1, 0), 0.7, _COLOR_GRID),
                *[
                    ("SPAN", (0, row), (-1, row))
                    for row in range(2, len(rows), 2)
                ],
                ("BACKGROUND", (0, 2), (-1, 2), colors.white),
                ("LINEBELOW", (0, 1), (-1, -1), 0.35, _COLOR_GRID),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 2),
                ("RIGHTPADDING", (0, 0), (-1, -1), 2),
                ("TOPPADDING", (0, 0), (-1, -1), 3),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
            ]
        )
    )
    return table


def _quality_notes(
    analysis: FlightAnalysis,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[Paragraph]:
    """Build uncertainty and recorder notes without inventing missing data.

    Args:
        analysis (FlightAnalysis): Flight quality flags and warning messages.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        List[Paragraph]: Human-readable notes for incomplete capture, track
            gaps, and each provided warning.
    """
    notes: List[Paragraph] = []
    if not analysis.completed:
        notes.append(_paragraph(tr(language, "estimate"), styles["body"]))
    if any(sample.discontinuity_before for sample in analysis.samples):
        notes.append(_paragraph(tr(language, "discontinuity"), styles["body"]))
    for warning in analysis.warnings:
        notes.append(
            _paragraph("• " + _safe_source_text(warning), styles["body"])
        )
    if not notes:
        notes.append(_paragraph(tr(language, "unavailable"), styles["small"]))
    return notes


def _draw_page(
    canvas: Canvas,
    document: SimpleDocTemplate,
    language: str,
    analysis_sha256: str,
) -> None:
    """Draw the page footer and full source hash on every page.

    Args:
        canvas (Canvas): ReportLab PDF canvas supplied by SimpleDocTemplate.
        document (SimpleDocTemplate): ReportLab document with current page number.
        language (str): Language used for the footer.
        analysis_sha256 (str): Full canonical checksum identifying source data.

    Returns:
        None: Draw the footer without changing document flow.
    """
    canvas.saveState()
    canvas.setStrokeColor(_COLOR_GRID)
    canvas.setLineWidth(0.5)
    canvas.line(18 * mm, 14 * mm, A4[0] - 18 * mm, 14 * mm)
    canvas.setFont(_FONT_NAME, 7)
    canvas.setFillColor(_COLOR_MUTED)
    canvas.drawString(
        18 * mm,
        9 * mm,
        tr(language, "footer"),
    )
    canvas.drawRightString(
        A4[0] - 18 * mm,
        9 * mm,
        analysis_sha256[:12] + "  ·  " + str(document.page),
    )
    canvas.setFont(_FONT_NAME, 5.5)
    canvas.drawString(
        18 * mm,
        4 * mm,
        tr(language, "shared_checksum") + ": " + analysis_sha256,
    )
    canvas.restoreState()


def _build_story(
    analysis: FlightAnalysis,
    language: str,
    analysis_sha256: str,
) -> List[Flowable]:
    """Assemble the translated, vector-based report document flow.

    Args:
        analysis (FlightAnalysis): Immutable shared flight-analysis model.
        language (str): Supported output language.
        analysis_sha256 (str): Canonical source analysis checksum.

    Returns:
        List[Flowable]: ReportLab flowables in the final reading order.
    """
    styles = _styles()
    story: List[Flowable] = [
        _paragraph(tr(language, "title"), styles["title"]),
        _paragraph(
            tr(language, "subtitle")
            + "  ·  "
            + _safe_source_text(analysis.simulator)
            + "  ·  "
            + _safe_source_text(analysis.session_id[:16]),
            styles["subtitle"],
        ),
        _paragraph(
            tr(language, "flight_date") + ": "
            + _recorded_flight_date(analysis, language)
            + "  ·  " + tr(language, "simulator_build") + ": "
            + _safe_source_text(
                getattr(analysis, "simulator_build", None)
                or tr(language, "unavailable")
            ),
            styles["small"],
        ),
        _paragraph(
            tr(language, "report_template") + ": "
            + REPORT_TEMPLATE_VERSION + " · "
            + tr(language, "analysis_version") + ": "
            + str(ANALYSIS_VERSION),
            styles["small"],
        ),
        _paragraph(tr(language, "summary"), styles["heading"]),
        _metric_grid(analysis, language, styles),
        Spacer(1, 2 * mm),
        _paragraph(tr(language, "charts"), styles["heading"]),
        _profile_chart(
            analysis,
            "altitude_m",
            tr(language, "altitude"),
            _COLOR_BLUE,
            1.0,
            "m",
            language,
        ),
        _profile_chart(
            analysis,
            "ground_speed_mps",
            tr(language, "ground_speed"),
            _COLOR_GREEN,
            KNOTS_PER_MPS,
            "kt",
            language,
        ),
        _profile_chart(
            analysis,
            "vertical_speed_mps",
            tr(language, "vertical_speed"),
            _COLOR_RED,
            FEET_PER_MINUTE_PER_MPS,
            "ft/min",
            language,
        ),
        *(
            [
                _profile_chart(
                    analysis,
                    "fuel_gallons",
                    tr(language, "fuel"),
                    _COLOR_BLUE,
                    1.0,
                    "gal",
                    language,
                )
            ]
            if any(sample.fuel_gallons is not None for sample in analysis.samples)
            else []
        ),
        _route_chart(analysis, language),
        _paragraph(tr(language, "phase_timeline"), styles["heading"]),
        _phase_event_table(analysis, language, styles),
        _paragraph(tr(language, "landing_detail"), styles["heading"]),
        _landing_table(analysis, language, styles),
        _paragraph(tr(language, "events"), styles["heading"]),
    ]
    for landing in analysis.landings:
        story.extend(_landing_section(analysis, landing, language, styles))
    story.append(
        KeepTogether([
            _paragraph(tr(language, "method_notes"), styles["heading"]),
            *_methodology_notes(language, styles),
        ])
    )
    if analysis.warnings:
        story.append(_paragraph(tr(language, "warnings"), styles["small"]))
    story.extend(_quality_notes(analysis, language, styles))
    return story


def render_report(
    analysis: FlightAnalysis,
    language: str,
    destination: Path,
) -> Path:
    """Render one language-specific PDF with atomic, retry-safe output.

    Args:
        analysis (FlightAnalysis): Shared immutable normalized flight model
            produced by the recorder analysis.
        language (str): Output language code: en, cs, de, or sv.
        destination (Path): Target PDF path. The parent directory is created.

    Returns:
        Path: The exact destination path of the completed PDF.

    Raises:
        ValueError: Raised for unsupported languages or a non-PDF destination.
        OSError: Raised when the destination cannot be written.
        ImportError: Raised when the pinned ReportLab runtime is unavailable.
    """
    language = language.lower()
    if language not in supported_languages():
        raise ValueError("Unsupported postflight report language: " + language)
    if destination.suffix.lower() != ".pdf":
        raise ValueError("Postflight report destination must end in .pdf")
    if not analysis.samples:
        raise ValueError(
            "A flight report requires at least one recorded sample"
        )
    target = destination
    _register_font()
    _, analysis_sha256 = _analysis_identity(analysis)
    if _reusable_report(target, analysis_sha256, language):
        return target

    temporary_path: Optional[Path] = None
    target.parent.mkdir(parents=True, exist_ok=True)
    try:
        handle = tempfile.NamedTemporaryFile(
            mode="wb",
            prefix=target.name + ".",
            suffix=".tmp",
            dir=str(target.parent),
            delete=False,
        )
        temporary_path = Path(handle.name)
        handle.close()
        document = SimpleDocTemplate(
            str(temporary_path),
            pagesize=A4,
            rightMargin=18 * mm,
            leftMargin=18 * mm,
            topMargin=16 * mm,
            bottomMargin=20 * mm,
            # Type: int; Units: none (boolean-compatible flag).
            # Scope: generated PDF; Consumers: SimpleDocTemplate._makeCanvas.
            # Rationale: keep identifiers and timestamps stable across retries.
            # Value: 1; enables deterministic document output.
            invariant=1,
            # Type: str; Units: BCP 47 tag; Scope: PDF catalog.
            # Consumers: SimpleDocTemplate._makeCanvas; Rationale: identify
            # the document language for readers and accessibility tools.
            lang=language,
            title=tr(language, "title"),
            author="PHP Gallery",
            subject=(
                "session="
                + analysis.session_id
                + "; analysis_sha256="
                + analysis_sha256
                + "; template="
                + REPORT_TEMPLATE_VERSION
            ),
            keywords=(
                "PHP Gallery, postflight, "
                + analysis.simulator
                + ", "
                + language
            ),
        )
        page_callback = partial(
            _draw_page,
            language=language,
            analysis_sha256=analysis_sha256,
        )
        document.build(
            _build_story(analysis, language, analysis_sha256),
            onFirstPage=page_callback,
            onLaterPages=page_callback,
        )
        os.replace(temporary_path, target)
        temporary_path = None
    finally:
        if temporary_path is not None and temporary_path.exists():
            temporary_path.unlink()

    pdf_sha256 = hashlib.sha256(target.read_bytes()).hexdigest()
    manifest = {
        "analysis_sha256": analysis_sha256,
        "language": language,
        "pdf_sha256": pdf_sha256,
        "session_id": analysis.session_id,
        "template_version": REPORT_TEMPLATE_VERSION,
    }
    _atomic_write(
        _manifest_path(target),
        json.dumps(
            manifest,
            ensure_ascii=False,
            sort_keys=True,
            indent=2,
        ).encode("utf-8"),
    )
    return target


def render_reports(
    analysis: FlightAnalysis,
    languages: Tuple[str, ...],
    directory: Path,
) -> List[Path]:
    """Render a set of language reports plus a verified group manifest.

    Args:
        analysis (FlightAnalysis): One shared analysis used for every language.
        languages (Tuple[str, ...]): Requested language codes; duplicates are
            removed while preserving order.
        directory (Path): Output folder for PDFs and the group manifest.

    Returns:
        List[Path]: Absolute PDF paths in requested language order.

    Raises:
        ValueError: Raised when languages is empty or includes an unsupported
            language.
        OSError: Raised when any report or manifest cannot be written.
    """
    if not languages:
        raise ValueError("At least one postflight report language is required")
    normalized = tuple(dict.fromkeys(language.lower() for language in languages))
    unsupported = [language for language in normalized if language not in supported_languages()]
    if unsupported:
        raise ValueError(
            "Unsupported postflight report language(s): "
            + ", ".join(unsupported)
        )
    output_directory = directory.expanduser().resolve()
    output_directory.mkdir(parents=True, exist_ok=True)
    _, analysis_sha256 = _analysis_identity(analysis)
    safe_session = re.sub(r"[^A-Za-z0-9_-]", "_", analysis.session_id)[:48]
    if not safe_session:
        safe_session = hashlib.sha256(analysis.session_id.encode("utf-8")).hexdigest()[:16]
    outputs: List[Path] = []
    entries: List[Dict[str, str]] = []
    for language in normalized:
        target = output_directory / (
            safe_session
            + "-"
            + analysis_sha256[:16]
            + "-v"
            + REPORT_TEMPLATE_VERSION
            + "-"
            + language
            + ".pdf"
        )
        rendered = render_report(analysis, language, target)
        pdf_sha256 = hashlib.sha256(rendered.read_bytes()).hexdigest()
        outputs.append(rendered)
        entries.append(
            {
                "language": language,
                "path": rendered.name,
                "pdf_sha256": pdf_sha256,
            }
        )
    group_manifest = {
        "analysis_sha256": analysis_sha256,
        "reports": entries,
        "session_id": analysis.session_id,
        "template_version": REPORT_TEMPLATE_VERSION,
    }
    _atomic_write(
        output_directory
        / (
            "postflight-manifest-"
            + safe_session
            + "-"
            + analysis_sha256[:16]
            + "-v"
            + REPORT_TEMPLATE_VERSION
            + ".json"
        ),
        json.dumps(
            group_manifest,
            ensure_ascii=False,
            sort_keys=True,
            indent=2,
        ).encode("utf-8"),
    )
    return outputs
