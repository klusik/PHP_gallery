# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/reports/identity.py
# Module Type: Flight report identity owner
# Purpose: Own identity behavior for multilingual postflight PDF reports.
# Responsibilities: Preserve report rendering behavior behind explicit owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own the identity domain of flight report generation."""

import hashlib
import json
import os
import tempfile
from pathlib import Path
from typing import Dict, Tuple


from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

from uploader.flight_data import FlightAnalysis

from .policy import (
    REPORT_TEMPLATE_VERSION,
    _FONT_BOLD,
    _FONT_NAME,
    _REQUIRED_GLYPHS,
)


def _resource_directory() -> Path:
    """Return the directory containing bundled report assets.

    Returns:
        Path: Installed package resource directory beside this module.
    """
    return Path(__file__).resolve().parents[2] / "resources"

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
