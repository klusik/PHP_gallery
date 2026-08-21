"""
Local image decoder/thumbnail capability checks for the Windows uploader.
"""

from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Set


@dataclass
class MediaCapability:
    """Capability description for one media suffix or concrete file."""

    suffix: str
    server_uploadable: bool
    local_previewable: bool
    local_thumbnailable: bool
    detail: str


def registered_pillow_suffixes(image_module: Any) -> Set[str]:
    """Return suffixes registered by the active Pillow installation."""
    if image_module is None:
        return set()
    try:
        registered = image_module.registered_extensions()
    except Exception:  # noqa: BLE001
        return set()
    return {str(suffix).lower() for suffix in registered if isinstance(suffix, str)}


def detect_suffix_capabilities(supported_suffixes: Iterable[str], image_module: Any) -> Dict[str, MediaCapability]:
    """Detect generic local-open capability for every server-supported suffix."""
    registered = registered_pillow_suffixes(image_module)
    result: Dict[str, MediaCapability] = {}
    for suffix in sorted({str(value).lower() for value in supported_suffixes}):
        local = suffix in registered
        if image_module is None:
            detail = "Pillow is not installed; server upload remains available."
        elif local:
            detail = "Pillow reports a local decoder for this file type."
        else:
            detail = "No Pillow decoder is registered; use server-side thumbnails."
        result[suffix] = MediaCapability(suffix, True, local, local, detail)
    return result


def probe_file(path: Path, supported_suffixes: Set[str], image_module: Any) -> MediaCapability:
    """
    Probe one selected file without rejecting server-uploadable originals.

    Decoder failures are isolated to this item and returned as capability data.
    """
    suffix = path.suffix.lower()
    if suffix not in supported_suffixes:
        return MediaCapability(suffix, False, False, False, "File type is not accepted by the Windows uploader.")
    if image_module is None:
        return MediaCapability(suffix, True, False, False, "Pillow is unavailable; original can still be sent to the gallery.")
    generic = detect_suffix_capabilities({suffix}, image_module)[suffix]
    if not generic.local_previewable:
        return generic
    try:
        with image_module.open(str(path)) as opened:
            opened.verify()
        return MediaCapability(suffix, True, True, True, "Local decoder opened and verified this file.")
    except Exception as exc:  # noqa: BLE001
        return MediaCapability(suffix, True, False, False, "Local decoder failed for this item: " + str(exc))


def capability_notes(capabilities: Dict[str, MediaCapability]) -> List[str]:
    """Return concise user-facing lines for capability diagnostics."""
    notes: List[str] = []
    for suffix, capability in sorted(capabilities.items()):
        if capability.local_thumbnailable:
            status = "local preview + thumbnails"
        else:
            status = "server upload only on this PC"
        notes.append(f"{suffix}: {status}")
    return notes
