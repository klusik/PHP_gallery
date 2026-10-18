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
from dataclasses import replace
from datetime import datetime, timezone
from functools import partial
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Tuple, Union

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.lib.utils import simpleSplit
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import (
    Flowable,
    KeepTogether,
    Paragraph,
    PageBreak,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.graphics.shapes import (
    Circle, Drawing, Group, Line, Path as ShapePath,
    PolyLine, Polygon, Rect, String,
)

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
from .flight_jobs import readable_pdf_name, read_document


# Template identity is included in every manifest and output filename so a
# future layout revision does not silently reuse a prior rendering.
REPORT_TEMPLATE_VERSION = "4"

# Purpose: Share true A4 landscape geometry across document and page chrome.
# Type: tuple[float, float]; Units: PDF points; Scope: rendered report pages.
# Consumers: render_report, _draw_page and all landscape panel owners.
# Rationale: 297 x 210 mm provides wide plots and tablet-friendly reading.
REPORT_PAGE_SIZE = landscape(A4)
# Purpose: Align page content, chart panels and running furniture.
# Type: float; Units: PDF points; Scope: horizontal report margins.
# Consumers: render_report and _draw_page. Rationale: fourteen millimetres
# leaves a printable margin without sacrificing the landscape content area.
REPORT_SIDE_MARGIN = 14 * mm
# Purpose: Leave room for the navy header and quiet bottom furniture.
# Type: tuple[float, float]; Units: PDF points; Scope: page frame.
# Consumers: render_report. Rationale: 22/16 mm separates content from chrome.
REPORT_VERTICAL_MARGINS = (22 * mm, 16 * mm)

# Type: float; Units: PDF points; Scope: landscape chart geometry.
# Consumers: _profile_chart, _route_chart and panel layout owners.
# Rationale: use the full printable width of A4 landscape, inside shared margins.
CHART_WIDTH = REPORT_PAGE_SIZE[0] - 2 * REPORT_SIDE_MARGIN
# Type: int; Units: PDF points; Scope: chart drawing geometry;
# Consumers: _profile_chart and _route_chart; Rationale: two full-width
# profiles fit one landscape page with larger labels and a short legend.
CHART_HEIGHT = 184
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

# Purpose: Pad constant sensor ranges to keep a visible enclosing axis.
# Type: float; Units: fraction; Scope: chart axes; Consumers: _axis_limits.
# Rationale: five percent avoids a zero-height range without changing readings.
AXIS_FLAT_PADDING = 0.05
# Purpose: Keep log/tick rounding defined at zero and avoid floating grid noise.
# Type: float; Units: display units or tick-ratio tolerance; Scope: chart axes.
# Consumers: _nice_step and _profile_chart. Rationale: one millionth is below
# captured display precision while giving a positive logarithm argument.
AXIS_EPSILON = 0.000001
# Purpose: Keep aviation minor ticks fine but bounded.
# Type: int; Units: intervals; Scope: Y grids; Consumers: _axis_limits.
# Rationale: forty minor divisions allow readable thousand-foot altitude ticks.
AXIS_MINOR_DIVISIONS = 40
# Purpose: Use familiar descent/climb grid spacing.
# Type: float; Units: fpm; Scope: V/S charts; Consumers: _axis_limits.
# Rationale: 200 fpm minor lines and five-times major lines match aviation reading.
VERTICAL_SPEED_GRID_FPM = 200.0
# Purpose: Bound vector grid density for unusually long/extreme captures.
# Type: int; Units: lines per axis; Scope: profiles; Consumers: _axis_limits,
# _profile_chart. Rationale: two hundred lines keep output size predictable.
MAX_GRID_LINES = 200
# Purpose: Distinguish minor and emphasized tick levels.
# Type: int; Units: minor intervals; Scope: chart axes; Consumers: _axis_limits,
# _profile_chart. Rationale: five minor ticks make one labeled major interval.
AXIS_MAJOR_MULTIPLE = 5
# Purpose: Share a fine elapsed grid across short contact windows.
# Type: int; Units: intervals; Scope: short-time axes; Consumers: _profile_chart.
# Rationale: eighteen desired divisions gives ten-second ticks over three minutes.
CONTACT_TIME_DIVISIONS = 18
# Purpose: Use ten-minute context through ordinary and long flights.
# Type: float; Units: seconds; Scope: full-flight grids; Consumers: _profile_chart.
# Rationale: 600 seconds stays subtle while hourly lines receive emphasis.
TIME_GRID_SECONDS = 600.0
# Purpose: Label long-flight major ticks as elapsed hours.
# Type: int; Units: seconds; Scope: profile axes; Consumers: _profile_chart.
# Rationale: one hour contains 3600 seconds.
HOUR_SECONDS = 3600
# Purpose: Select a finer grid for short recordings.
# Type: int; Units: seconds; Scope: time axes; Consumers: _profile_chart.
# Rationale: below twenty minutes a ten-minute minor grid would be too sparse.
SHORT_FLIGHT_SECONDS = 1200
# Purpose: Keep summary cards legible within A4 width.
# Type: int; Units: columns; Scope: dashboards; Consumers: _visual_metrics,
# _configuration_graphic. Rationale: three cards fit readable labels and values.
METRIC_COLUMNS = 3
# Purpose: Reserve consistent dashboard card height.
# Type: int; Units: PDF points; Scope: metric grids; Consumers: _visual_metrics.
# Rationale: 74 points fits two label lines and one or two large value lines.
METRIC_ROW_HEIGHT = 74
# Purpose: Wrap observed phases without long evidence tables.
# Type: int; Units: columns; Scope: phase timeline; Consumers: _phase_infographic.
# Rationale: fourteen small milestones keep an ordinary flight on one line.
PHASE_COLUMNS = 14
# Purpose: Keep phase icons and elapsed times vertically separated.
# Type: int; Units: PDF points; Scope: phase timeline; Consumers: _phase_infographic.
# Rationale: 54 points fits readable labels and a larger aircraft symbol.
PHASE_ROW_HEIGHT = 54
# Purpose: Fill small chart-only omissions without changing measured evidence.
# Type: float; Units: seconds; Scope: scalar profile drawing.
# Consumers: _profile_chart. Rationale: up to five seconds uses the series color;
# longer bounded gaps use an amber dashed estimate and an explicit legend.
PROFILE_SHORT_BRIDGE_SECONDS = 5.0
# Purpose: Refuse long-range extrapolation disguised as a continuous flight.
# Type: float; Units: seconds; Scope: missing scalar plot segments.
# Consumers: _profile_chart. Rationale: five minutes bridges a short
# interruption in a long flight;
# longer losses, reloads, pauses and position jumps remain visibly separated.
PROFILE_MAX_BRIDGE_SECONDS = 300.0
# Purpose: Fit four readable landing profiles beside concise analysis on A4.
# Type: int; Units: PDF points; Scope: paired landing charts.
# Consumers: _landing_visuals. Rationale: 174 points fits two chart rows on
# a dedicated detail page with nine-point axis labels.
LANDING_CHART_HEIGHT = 174
# Purpose: Describe observed delivery cadence close to touchdown.
# Type: float; Units: seconds; Scope: pre-contact cadence summary.
# Consumers: _landing_visuals. Rationale: ten seconds smooths frame jitter
# while exposing substantial sampling gaps within the landing window.
LANDING_CADENCE_WINDOW_SECONDS = 10.0
# Purpose: Keep noisy configuration changes from becoming an unreadable wall.
# Type: int; Units: event cards; Scope: one landing infographic.
# Consumers: _configuration_events. Rationale: show up to eighteen first/last
# changes and disclose the displayed/total count; raw observations remain intact.
MAX_CONFIGURATION_EVENTS = 18
# Purpose: Offer explicitly estimated historical fuel mass when density was absent.
# Type: float; Units: kg per US gallon; Scope: old fuel captures.
# Consumers: _fuel_profile. Rationale: assume 0.80 kg/L and 3.785411784 L/US gal;
# always mark this display conversion as estimated, never alter source telemetry.
ASSUMED_KG_PER_US_GALLON = 0.80 * 3.785411784
# Purpose: Convert measured fuel mass for Boeing-style displays.
# Type: float; Units: lb/kg; Scope: fuel display; Consumers: _fuel_profile.
# Rationale: reciprocal of the exact international pound conversion.
POUNDS_PER_KG = 1.0 / 0.45359237
# Purpose: Show observed rollout distance using familiar runway-distance units.
# Type: int; Units: metres/NM; Scope: landing cards; Consumers: _landing_visuals.
# Rationale: one nautical mile equals exactly 1852 metres.
METRES_PER_NM = 1852

_COLOR_INK = colors.HexColor("#17324D")
_COLOR_BLUE = colors.HexColor("#1677A8")
_COLOR_GREEN = colors.HexColor("#25836B")
_COLOR_RED = colors.HexColor("#C64A45")
_COLOR_MUTED = colors.HexColor("#637381")
_COLOR_GRID = colors.HexColor("#D9E2E8")
_COLOR_MINOR_GRID = colors.HexColor("#F0F3F6")
_COLOR_PANEL = colors.HexColor("#F2F6F8")
_COLOR_PAGE = colors.HexColor("#F4F7FC")
_COLOR_NAVY = colors.HexColor("#0E1B2E")
_COLOR_TEAL = colors.HexColor("#00A8AC")
_COLOR_INTERPOLATED = colors.HexColor("#C58A22")
_FONT_NAME = "FlightReportSans"
_FONT_BOLD = "FlightReportSansBold"
_REQUIRED_GLYPHS = "Příliš žluťoučký kůň åäö üß"


def _resource_directory() -> Path:
    """Return the directory containing bundled report assets.

    Returns:
        Path: Installed package resource directory beside this module.
    """
    return Path(__file__).resolve().parent / "resources"


def _register_font() -> None:
    """Register bundled regular and bold DejaVu faces with multilingual coverage.

    Returns:
        None: Both faces are available for paragraphs and vector labels.

    Raises:
        FileNotFoundError: A required bundled face is missing.
        ValueError: A bundled face lacks required European glyphs.
    """
    for name, filename in ((_FONT_NAME, "DejaVuSans.ttf"),
                           (_FONT_BOLD, "DejaVuSans-Bold.ttf")):
        if name in pdfmetrics.getRegisteredFontNames():
            continue
        font_path = _resource_directory() / filename
        if not font_path.is_file():
            raise FileNotFoundError(
                "Postflight font asset is missing: " + str(font_path)
            )
        font = TTFont(name, str(font_path))
        char_to_glyph = font.face.charToGlyph
        missing = [char for char in _REQUIRED_GLYPHS
                   if char != " " and char_to_glyph.get(ord(char), 0) == 0]
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
    """Return private metadata storage beside the user-facing PDF folder.

    Args:
        destination (Path): PDF output path.

    Returns:
        Path: JSON sidecar under .metadata with the PDF basename retained.
    """
    metadata = destination.parent / ".metadata"
    path = metadata / (destination.name + ".manifest.json")
    if metadata.is_symlink() or path.is_symlink():
        raise ValueError("PDF metadata cannot redirect outside its directory.")
    return path


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
            enter landing analysis, raw storage or measured extrema.
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
    min_x, max_x = x_bounds if x_bounds else (0.0, max(analysis.duration_seconds, 1.0))
    if max_x <= min_x:
        max_x = min_x + 1.0
    low, high, minor, major = _axis_limits(
        min((v for _t, v in valid), default=0.0),
        max((v for _t, v in valid), default=1.0), attribute,
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


def _route_chart(
    analysis: FlightAnalysis, language: str,
    chart_width: float = CHART_WIDTH, chart_height: float = CHART_HEIGHT,
) -> Drawing:
    """Draw only captured aircraft positions and preserve all coordinate gaps.

    Args:
        analysis (FlightAnalysis): Ordered user-aircraft position samples.
        language (str): Language used for the route heading.
        chart_width (float): Landscape panel width in PDF points.
        chart_height (float): Landscape panel height in PDF points.

    Returns:
        Drawing: Recorded aircraft-position chart; no camera route is inferred.
    """
    drawing = Drawing(chart_width, chart_height)
    drawing.add(Rect(0, 4, chart_width - 1, chart_height - 8,
                     fillColor=colors.white, strokeColor=_COLOR_GRID,
                     strokeWidth=0.6, rx=8))
    drawing.add(
        String(
            15,
            chart_height - 23,
            tr(language, "aircraft_track"),
            fontName=_FONT_BOLD,
            fontSize=11,
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
    left, bottom = 61, 38
    width, height = chart_width - 80, chart_height - 108
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
    longitude_scale = max(0.01, math.cos(math.radians((min_y + max_y) / 2)))
    span_x = max((max_x - min_x) * longitude_scale, 0.0001)
    span_y = max(max_y - min_y, 0.0001)
    scale = min(width / span_x, height / span_y)
    offset_x = left + (width - span_x * scale) / 2
    offset_y = bottom + (height - span_y * scale) / 2
    for fraction in (0.0, 0.25, 0.5, 0.75, 1.0):
        x = offset_x + fraction * span_x * scale
        y = offset_y + fraction * span_y * scale
        drawing.add(Line(x, bottom, x, bottom + height, strokeColor=_COLOR_MINOR_GRID, strokeWidth=0.4))
        drawing.add(Line(left, y, left + width, y, strokeColor=_COLOR_MINOR_GRID, strokeWidth=0.4))
        longitude = (min_x + (max_x - min_x) * fraction + 180) % 360 - 180
        drawing.add(String(x, bottom - 14, format(longitude, ".2f") + "°", textAnchor="middle", fontName=_FONT_NAME, fontSize=8.5, fillColor=_COLOR_MUTED))
        drawing.add(String(left - 5, y - 2, format(min_y + (max_y - min_y) * fraction, ".2f") + "°", textAnchor="end", fontName=_FONT_NAME, fontSize=8.5, fillColor=_COLOR_MUTED))
    drawing.add(String(left + width / 2, 10, tr(language, "longitude"), textAnchor="middle", fontName=_FONT_NAME, fontSize=8.5, fillColor=_COLOR_MUTED))
    drawing.add(String(15, chart_height - 44, tr(language, "latitude"), fontName=_FONT_NAME, fontSize=8.5, fillColor=_COLOR_MUTED))
    drawing.add(String(chart_width - 15, chart_height - 23, "N ↑", textAnchor="end", fontName=_FONT_NAME, fontSize=9, fillColor=_COLOR_MUTED))
    drawing.add(Circle(20, chart_height - 60, 2, fillColor=_COLOR_GREEN, strokeColor=None))
    drawing.add(String(27, chart_height - 63, tr(language, "track_start"), fontName=_FONT_NAME, fontSize=8.5, fillColor=_COLOR_MUTED))
    drawing.add(Circle(chart_width / 2 + 8, chart_height - 60, 2, fillColor=_COLOR_INK, strokeColor=None))
    drawing.add(String(chart_width / 2 + 15, chart_height - 63, tr(language, "track_end"), fontName=_FONT_NAME, fontSize=8.5, fillColor=_COLOR_MUTED))
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
        x = offset_x + (longitude - min_x) * longitude_scale * scale
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
            bounds in session elapsed seconds. Original retained landing detail
            takes priority; older in-memory analyses without it keep their
            bounded overview series as a permanently supported display fallback.
    """
    contact_start = float(landing.first_contact_elapsed_seconds)
    contact_end = float(landing.last_contact_elapsed_seconds)
    window_start = contact_start - PRECONTACT_WINDOW_SECONDS
    window_end = contact_end + ROLLOUT_WINDOW_SECONDS
    samples = [
        sample
        for sample in (landing.profile_samples or analysis.samples)
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
    canvas: Canvas, document: SimpleDocTemplate,
    language: str, analysis_sha256: str,
) -> None:
    """Draw landscape page furniture in navy, teal and a pale reading surface.

    Args:
        canvas (Canvas): ReportLab canvas supplied by the document owner.
        document (SimpleDocTemplate): Owner with current page number.
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


def _phase_infographic(analysis: FlightAnalysis, language: str) -> Drawing:
    """Present observed phase milestones as a compact, normally single-row strip.

    Args:
        analysis (FlightAnalysis): Original phase changes; repeated phases remain.
        language (str): Supported localized labels and elapsed times.

    Returns:
        Drawing: Up to fourteen milestones per fifty-four-point row; exceptional
            longer timelines wrap without dropping changes or inventing phases.
    """
    events = [event for event in analysis.events
              if event.phase not in {"unknown", "discontinuity", "ended"}]
    columns = min(PHASE_COLUMNS, max(1, len(events)))
    rows = max(1, math.ceil(len(events) / columns))
    drawing = Drawing(CHART_WIDTH, rows * PHASE_ROW_HEIGHT)
    width = CHART_WIDTH / columns
    for index, event in enumerate(events):
        x = index % columns * width
        y = (rows - 1 - index // columns) * PHASE_ROW_HEIGHT
        _plane_icon(drawing, x + width / 2, y + 40, event.phase, _COLOR_BLUE, size=0.6)
        key = "strip_" + event.phase
        label = tr(language, key)
        if label == key:
            label = _translate_phase_event(event.phase, language)
        label_size = min(8.5, (width - 2) / max(pdfmetrics.stringWidth(label, _FONT_NAME, 1), 1))
        drawing.add(String(x + width / 2, y + 21, label, textAnchor="middle",
                           fontName=_FONT_NAME, fontSize=label_size, fillColor=_COLOR_INK))
        drawing.add(String(x + width / 2, y + 6, _format_duration(event.elapsed_seconds, language),
                           textAnchor="middle", fontName=_FONT_NAME, fontSize=8, fillColor=_COLOR_MUTED))
        if index % columns < columns - 1 and index < len(events) - 1:
            drawing.add(Line(x + width / 2 + 9, y + 40, x + width * 1.5 - 9, y + 40,
                             strokeColor=_COLOR_GRID, strokeWidth=0.5))
    if not events:
        drawing.add(String(0, 15, tr(language, "no_phase_events"), fontName=_FONT_NAME,
                           fontSize=8, fillColor=_COLOR_MUTED))
    return drawing


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


def _build_story(analysis: FlightAnalysis, language: str, analysis_sha256: str) -> List[Flowable]:
    """Arrange unchanged report content into landscape overview and paired profiles.

    Args:
        analysis (FlightAnalysis): Immutable shared flight-analysis model.
        language (str): Supported output language.
        analysis_sha256 (str): Private canonical identity retained in PDF metadata.

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
    metric_width = CHART_WIDTH * 0.4
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
            _route_chart(analysis, language, CHART_WIDTH - metric_width - 8, 240),
        ], [metric_width, CHART_WIDTH - metric_width]),
        _paragraph(tr(language, "phase_timeline"), styles["heading"]),
        _phase_infographic(analysis, language),
        _paragraph(tr(language, "capture_scope"), styles["small"]),
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
            pagesize=REPORT_PAGE_SIZE,
            rightMargin=REPORT_SIDE_MARGIN,
            leftMargin=REPORT_SIDE_MARGIN,
            topMargin=REPORT_VERTICAL_MARGINS[0],
            bottomMargin=REPORT_VERTICAL_MARGINS[1],
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
        "created_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "page_count": document.page,
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
    group_path = output_directory / ".metadata" / (
        "postflight-manifest-" + safe_session + "-" + analysis_sha256[:16]
        + "-v" + REPORT_TEMPLATE_VERSION + ".json"
    )
    if group_path.parent.is_symlink():
        raise ValueError("PDF group metadata cannot redirect.")
    previous = read_document(group_path) if group_path.exists() else {}
    number = previous.get("number")
    if type(number) is not int or number < 1:
        versions = [re.search(r" - v(\d+)\.pdf$", path.name) for path in output_directory.glob("*.pdf")]
        number = max((int(match.group(1)) for match in versions if match), default=0) + 1
    for language in normalized:
        target = output_directory / (
            readable_pdf_name(
                analysis.samples[0].recorded_utc if analysis.samples else "Flight",
                analysis.aircraft or "Aircraft", language, number,
            )
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
        "number": number,
        "analysis_sha256": analysis_sha256,
        "reports": entries,
        "session_id": analysis.session_id,
        "template_version": REPORT_TEMPLATE_VERSION,
    }
    _atomic_write(
        group_path,
        json.dumps(
            group_manifest,
            ensure_ascii=False,
            sort_keys=True,
            indent=2,
        ).encode("utf-8"),
    )
    return outputs
