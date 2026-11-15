# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_view_preferences.py
# Module Type: Local Gallery View Preferences
# Purpose: Persist WinApp photo-card formats and order independently of uploads.
# Responsibilities:
#   - Validate supported card formats and bounded per-account local preferences.
#   - Atomically save one gallery choice while preserving other gallery choices.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Local-only photo presentation preferences for verified gallery identities."""

import json
from pathlib import Path

from .app_linking import LINKING_GALLERY_ID_MAX
from .state_store import atomic_write_json


# Purpose: Offer the same familiar card proportions without changing web policy.
# Type: tuple[str, ...]. Units: width:height choices, plus the default key.
# Scope: WinApp photo grids. Consumers: Photos toolbar, viewer and persistence.
# Rationale: retain natural previews by default and offer six explicit crops.
PHOTO_CARD_FORMATS = (
    "default", "16:9", "21:9", "4:3", "1:1", "4:5", "9:16",
)

# Purpose: Bound the local file while retaining independent gallery choices.
# Type: int. Units: gallery preferences. Scope: one verified site/account.
# Consumers: local preference reader and writer. Rationale: 10,000 matches the
# existing tree-preference capacity without allowing an unbounded mapping.
GALLERY_VIEW_PREFERENCES_MAX_GALLERIES = 10000

# Purpose: Bound local JSON reads before decoding externally changed files.
# Type: int. Units: bytes. Scope: one verified site/account preference file.
# Consumers: _read_preferences. Rationale: 1 MiB comfortably holds 10,000
# gallery-ID/format pairs, including indentation, without unbounded allocation.
GALLERY_VIEW_PREFERENCES_MAX_BYTES = 1024 * 1024


def normalize_card_format(value: str) -> str:
    """Return a supported format or the existing natural-preview default.

    Args:
        value (str): Requested local format key, such as default or 16:9.
    Returns:
        str: Supported format key; unknown values become default.
    """
    return value if value in PHOTO_CARD_FORMATS else "default"


def _read_preferences(path: Path) -> dict[str, dict[str, str]]:
    """Read independent local format and order choices without remote state.

    Args:
        path (Path): Credential-free file for one verified site/account.
    Returns:
        dict[str, dict[str, str]]: card_formats and photo_orders maps keyed by
            canonical positive gallery IDs; a missing file has empty maps.
    Raises:
        OSError: The file cannot be read.
        ValueError: The file is unsafe, oversized, malformed or unsupported.
    """
    if path.is_symlink() or path.parent.is_symlink():
        raise ValueError("Gallery view preferences cannot be a symbolic link.")
    try:
        with path.open("rb") as source:
            # One extra byte distinguishes the exact limit from an overflow.
            encoded = source.read(GALLERY_VIEW_PREFERENCES_MAX_BYTES + 1)
    except FileNotFoundError:
        return {"card_formats": {}, "photo_orders": {}}
    if len(encoded) > GALLERY_VIEW_PREFERENCES_MAX_BYTES:
        raise ValueError("Gallery view preferences exceed their size limit.")
    try:
        payload = json.loads(encoded.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise ValueError("Gallery view preferences are malformed.") from exc
    if (not isinstance(payload, dict)
            or type(payload.get("version")) is not int
            or payload["version"] != 1
            or not isinstance(payload.get("card_formats"), dict)):
        raise ValueError("Unsupported gallery view preferences.")
    # Version 1 files predating photo order have only card_formats. This
    # permanent additive reader preserves those existing user choices.
    result: dict[str, dict[str, str]] = {}
    for field, choices, default in (
        ("card_formats", PHOTO_CARD_FORMATS, "default"),
        ("photo_orders", ("oldest", "newest"), "oldest"),
    ):
        rows = payload.get(field, {})
        if (not isinstance(rows, dict)
                or len(rows) > GALLERY_VIEW_PREFERENCES_MAX_GALLERIES):
            raise ValueError("Invalid gallery view preferences.")
        result[field] = {}
        for raw_id, value in rows.items():
            if (not isinstance(raw_id, str) or not raw_id.isdecimal()
                    or not isinstance(value, str) or value not in choices):
                continue
            gallery_id = int(raw_id)
            if 0 < gallery_id <= LINKING_GALLERY_ID_MAX and value != default:
                result[field][str(gallery_id)] = value
    return result


def load_gallery_card_format(path: Path, gallery_id: int) -> str:
    """Restore one gallery's local presentation without changing its source.

    Args:
        path (Path): Credential-free file for one verified site/account.
        gallery_id (int): Positive gallery identity selected in Photos.
    Returns:
        str: Saved supported key, or default when missing, invalid or unreadable.
    """
    if type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX:
        return "default"
    try:
        return _read_preferences(path)["card_formats"].get(str(gallery_id), "default")
    except (OSError, ValueError):
        return "default"


def save_gallery_card_format(path: Path, gallery_id: int, value: str) -> bool:
    """Save one local choice atomically while preserving other galleries.

    Args:
        path (Path): Credential-free file for one verified site/account.
        gallery_id (int): Positive gallery identity selected in Photos.
        value (str): Supported format key; default removes an explicit override.
    Returns:
        bool: True after atomic persistence; False for invalid input, full state
            or a failed read/write. Unreadable existing choices are preserved.
    """
    if (type(gallery_id) is not int
            or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
            or value not in PHOTO_CARD_FORMATS):
        return False
    try:
        preferences = _read_preferences(path)
        rows = preferences["card_formats"]
        key = str(gallery_id)
        if value == "default":
            rows.pop(key, None)
        else:
            rows[key] = value
        if len(rows) > GALLERY_VIEW_PREFERENCES_MAX_GALLERIES:
            return False
        # Version 1 is the structural local-view document schema, not a policy.
        atomic_write_json(path, {"version": 1, **preferences})
        return True
    except (OSError, ValueError):
        return False


def load_gallery_photo_order(path: Path, gallery_id: int) -> str:
    """Restore the persistent WinApp order for one verified gallery.

    Args:
        path (Path): Local file scoped to the verified site and account.
        gallery_id (int): Selected physical gallery identity.
    Returns:
        str: newest or the original oldest order for absent/unreadable state.
    """
    if type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX:
        return "oldest"
    try:
        return _read_preferences(path)["photo_orders"].get(str(gallery_id), "oldest")
    except (OSError, ValueError):
        # The local preference owner keeps browsing usable on damaged storage;
        # saves still refuse that file. This is permanent source-mode support.
        return "oldest"


def save_gallery_photo_order(path: Path, gallery_id: int, value: str) -> bool:
    """Persist order without changing formats, other galleries or the server.

    Args:
        path (Path): Local file scoped to the verified site and account.
        gallery_id (int): Selected physical gallery identity.
        value (str): oldest or newest; oldest removes the explicit override.
    Returns:
        bool: True after atomic persistence; False preserves unreadable state.
    """
    if (type(gallery_id) is not int
            or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
            or value not in ("oldest", "newest")):
        return False
    try:
        preferences = _read_preferences(path)
        rows = preferences["photo_orders"]
        key = str(gallery_id)
        if value == "oldest":
            rows.pop(key, None)
        else:
            rows[key] = value
        if len(rows) > GALLERY_VIEW_PREFERENCES_MAX_GALLERIES:
            return False
        atomic_write_json(path, {"version": 1, **preferences})
        return True
    except (OSError, ValueError):
        return False
