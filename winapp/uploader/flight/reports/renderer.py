# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/reports/renderer.py
# Module Type: Flight report renderer owner
# Purpose: Own renderer behavior for multilingual postflight PDF reports.
# Responsibilities: Preserve report rendering behavior behind explicit owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own the renderer domain of flight report generation."""

import hashlib
import json
import os
import re
import tempfile
from datetime import datetime, timezone
from functools import partial
from pathlib import Path
from typing import List, Tuple


from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import BaseDocTemplate, Flowable, Frame, PageTemplate
from reportlab.platypus.doctemplate import LayoutError

from uploader.flight_data import FlightAnalysis
from uploader.flight_report_translations import supported_languages, tr
from uploader.flight_render import render_report_variants
from uploader.flight_jobs import readable_pdf_name, read_document

from .policy import (
    REPORT_FRAME_PADDING,
    _COLOR_RED,
    REPORT_PAGE_SIZE,
    REPORT_SIDE_MARGIN,
    REPORT_TEMPLATE_VERSION,
    REPORT_VERTICAL_MARGINS,
)

from .comparison import (
    _comparison_pages,
    _flightplan_pages,
)

from .graphics import (
    _build_story,
)

from .identity import (
    _analysis_identity,
    _atomic_write,
    _manifest_path,
    _register_font,
    _reusable_report,
)

from .presentation import (
    _paragraph,
    _styles,
    _draw_page,
)

from .route import (
    _map_pages,
    prepare_report_maps,
)


class _FlightReportFrame(Frame):
    """Let the renderer retain the report when an element cannot be laid out."""

    def __init__(self, document: BaseDocTemplate, language: str) -> None:
        """Keep the original printable frame and prepare its omission notice.

        Args:
            document (BaseDocTemplate): Owner of page geometry and margins.
            language (str): Language of the warning inserted into the PDF.

        Returns:
            None: Frame is ready and has an empty layout omission list.
        """
        super().__init__(
            document.leftMargin, document.bottomMargin,
            document.width, document.height, id="normal",
            leftPadding=REPORT_FRAME_PADDING, rightPadding=REPORT_FRAME_PADDING,
            topPadding=REPORT_FRAME_PADDING, bottomPadding=REPORT_FRAME_PADDING,
        )
        self.layout_omissions: list[str] = []
        self._omission_message = tr(language, "layout_omission")
        self._omission_style = ParagraphStyle(
            "FlightLayoutWarning", parent=_styles()["small"], textColor=_COLOR_RED,
        )

    def split(self, flowable: Flowable, canv: Canvas) -> list[Flowable]:
        """Replace an element that cannot fit or split in the empty frame.

        Args:
            flowable (Flowable): The current element or remaining split portion
                that ReportLab could not fit into the available space.
            canv (Canvas): ReportLab canvas used to measure the element.

        Returns:
            list[Flowable]: ReportLab split parts, an empty list to move the
                element to a fresh page, or one localized omission warning.
        """
        try:
            parts = super().split(flowable, canv)
        except LayoutError:
            parts = []
        # ReportLab clears this flag only after the first content is placed.
        # A crowded page still gets the ordinary retry on a fresh page.
        if parts or not self._atTop:
            return parts
        self.layout_omissions.append(type(flowable).__name__)
        return [_paragraph(self._omission_message, self._omission_style)]

def render_report(
    analysis: FlightAnalysis,
    language: str,
    destination: Path,
    map_images: tuple[bytes, bytes] | None = None,
) -> Path:
    """Render an atomic language-specific PDF with notices for layout omissions.

    Args:
        analysis (FlightAnalysis): Shared immutable normalized flight model
            produced by the recorder analysis.
        language (str): Output language code: en, cs, de, or sv.
        destination (Path): Target PDF path. The parent directory is created.
        map_images (tuple[bytes, bytes] | None): Prepared overview/full PNGs;
            None prepares maps during this standalone render.

    Returns:
        Path: Exact path of the completed PDF, including any layout notices.

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
        document = BaseDocTemplate(
            str(temporary_path),
            pagesize=REPORT_PAGE_SIZE,
            rightMargin=REPORT_SIDE_MARGIN,
            leftMargin=REPORT_SIDE_MARGIN,
            topMargin=REPORT_VERTICAL_MARGINS[0],
            bottomMargin=REPORT_VERTICAL_MARGINS[1],
            # Type: int; Units: none (boolean-compatible flag).
            # Scope: generated PDF; Consumers: BaseDocTemplate._makeCanvas.
            # Rationale: keep identifiers and timestamps stable across retries.
            # Value: 1; enables deterministic document output.
            invariant=1,
            # Type: str; Units: BCP 47 tag; Scope: PDF catalog.
            # Consumers: BaseDocTemplate._makeCanvas; Rationale: identify
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
        frame = _FlightReportFrame(document, language)
        document.addPageTemplates(PageTemplate(
            id="FlightReport", frames=[frame], onPage=page_callback,
            pagesize=REPORT_PAGE_SIZE,
        ))
        document.build(
            _build_story(analysis, language, analysis_sha256,
                target.parent / ".metadata" / "map-tiles", map_images)
            + _map_pages(analysis, language,
                target.parent / ".metadata" / "map-tiles", map_images)
            + _comparison_pages(analysis, language)
            + _flightplan_pages(analysis, language),
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
        "layout_omissions": frame.layout_omissions,
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
    """Render language reports concurrently, then publish a verified group manifest.

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
    destinations: dict[str, Path] = {}
    for language in normalized:
        target = output_directory / (
            readable_pdf_name(
                analysis.samples[0].recorded_utc if analysis.samples else "Flight",
                analysis.aircraft or "Aircraft", language, number,
                flight_number=analysis.dispatch.flight_number if analysis.dispatch else "",
                origin=analysis.dispatch.origin if analysis.dispatch else "",
                destination=analysis.dispatch.destination if analysis.dispatch else "",
            )
        )
        destinations[language] = target
    rendered_reports = render_report_variants(
        analysis, destinations, prepare_maps=prepare_report_maps, render=render_report,
    )
    for language in normalized:
        rendered = rendered_reports[language]
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
