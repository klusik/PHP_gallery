# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_view_preferences.py
# Module Type: Local Gallery View Preferences
# Purpose: Persist local gallery formats, order and section-divider positions.
# Responsibilities:
#   - Validate supported card formats and bounded per-account local preferences.
#   - Atomically save one gallery choice while preserving other gallery choices.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Local-only gallery presentation preferences for verified identities."""

from dataclasses import dataclass, field
import json
from hashlib import sha256
from pathlib import Path

from .linking import LINKING_GALLERY_ID_MAX
from .flight_jobs import read_document
from .gallery_image_policy import MEDIA_THUMBNAIL_SIZE
from .state_store import atomic_write_json


# Purpose: Offer the same familiar card proportions without changing web policy.
# Type: tuple[str, ...]. Units: width:height choices, plus the default key.
# Scope: WinApp photo grids. Consumers: Photos toolbar, viewer and persistence.
# Rationale: retain natural previews by default and offer six explicit crops.
PHOTO_CARD_FORMATS = (
    "default", "16:9", "21:9", "4:3", "1:1", "4:5", "9:16",
)


def gallery_card_dimensions(card_format: str) -> tuple[int, int]:
    """Fit a selected card ratio within the original Tk thumbnail square.

    Args:
        card_format (str): Shared presentation key or an invalid saved value.
    Returns:
        tuple[int, int]: Logical image width and height, with a positive edge.
    """
    normalized = normalize_card_format(card_format)
    if normalized == "default":
        return MEDIA_THUMBNAIL_SIZE
    numerator, denominator = normalized.split(":", 1)
    ratio = int(numerator) / int(denominator)
    width, height = MEDIA_THUMBNAIL_SIZE
    if ratio >= 1:
        return width, max(1, round(width / ratio))
    return max(1, round(height * ratio)), height

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

# Purpose: Retain useful folder and photo viewports at both ends of a drag.
# Type: tuple[int, int]. Units: thousandths of the viewer-pane height.
# Scope: one mixed gallery. Consumers: divider UI and local preference IO.
# Rationale: 100..800 permits a broad adjustment while reserving photo space;
# the native layout also enforces physical minimums in small windows.
GALLERY_FOLDER_SHARE_BOUNDS = (100, 800)

# Purpose: Store relative divider positions independent of monitor dimensions.
# Type: int. Units: parts per whole viewer height. Scope: local gallery views.
# Consumers: folder shelf, divider drag and preference serialization.
# Rationale: 1000 gives subpixel precision at ordinary desktop window heights.
GALLERY_FOLDER_SHARE_SCALE = 1000


@dataclass(frozen=True)
class PhotosTreePreferences:
    """Carry one verified account's tree expansion and explicit selection."""

    opened: dict[int, bool] = field(default_factory=dict)
    selected: int | None = None


def load_photos_tree_preferences(path: Path) -> PhotosTreePreferences:
    """Read the existing version-one or version-two account tree preference.

    Args:
        path (Path): Credential-free path for the exact verified account.
    Returns:
        PhotosTreePreferences: Validated expansion and selection, or defaults
            when local data is missing or damaged. No catalogue is implied.
    """
    try:
        payload = read_document(path)
        version = payload.get('version')
        if version not in (1, 2):  # Existing private-preference wire versions.
            return PhotosTreePreferences()
        selected = payload.get('selected_gallery_id')
        opened: dict[int, bool] = {}
        rows = payload.get('open')
        if isinstance(rows, dict):
            for raw_id, value in rows.items():
                if len(opened) >= GALLERY_VIEW_PREFERENCES_MAX_GALLERIES:
                    break
                if isinstance(raw_id, str) and raw_id.isdecimal() and type(value) is bool:
                    gallery_id = int(raw_id)
                    if 0 < gallery_id <= LINKING_GALLERY_ID_MAX:
                        opened[gallery_id] = value
        if version != 2 or type(selected) is not int or not 0 < selected <= LINKING_GALLERY_ID_MAX:
            selected = None
        return PhotosTreePreferences(opened, selected)
    except (OSError, ValueError, TypeError):
        return PhotosTreePreferences()


def save_photos_tree_preferences(
    path: Path, *, opened: dict[int, bool] | None = None, selected: int | None = None,
) -> bool:
    """Publish one explicit tree change while retaining the other setting.

    Args:
        path (Path): Preference path from the current verified account.
        opened (dict[int, bool] | None): Complete bounded expansion mapping,
            or None to keep the current saved expansion state.
        selected (int | None): Fresh explicit gallery, or None to keep selection.
    Returns:
        bool: True if the shared account preference was written.
    """
    if selected is not None and (type(selected) is not int or not 0 < selected <= LINKING_GALLERY_ID_MAX):
        return False
    previous = load_photos_tree_preferences(path)
    expanded = previous.opened if opened is None else opened
    bounded = {
        str(gallery_id): value for gallery_id, value in expanded.items()
        if type(gallery_id) is int and 0 < gallery_id <= LINKING_GALLERY_ID_MAX and type(value) is bool
    }
    if len(bounded) > GALLERY_VIEW_PREFERENCES_MAX_GALLERIES:
        return False
    try:
        atomic_write_json(path, {
            'version': 2,  # Compatible tree schema with explicit selection.
            'open': bounded,
            'selected_gallery_id': previous.selected if selected is None else selected,
        })
    except (OSError, ValueError):
        return False
    return True


def photos_account_tree_path(profile: Path, issuer: str, user_id: int) -> Path:
    """Resolve the existing per-account preference identity in either frontend.

    Args:
        profile (Path): Current local WinApp profile.
        issuer (str): Canonical issuer verified by the current Photos grant.
        user_id (int): Positive account ID confirmed by that grant's identity.
    Returns:
        Path: Existing hashed preference key for this site and account.
    """
    key = sha256(f"{issuer}\0{user_id}".encode("utf-8")).hexdigest()
    return profile / "photos-tree" / f"{key}.json"


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
        dict[str, dict[str, str]]: Independent card_formats, photo_orders,
            folder_formats, folder_orders and folder_splits keyed by positive
            gallery IDs. Split values are canonical decimal thousandths.
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
        return {key: {} for key in (
            'card_formats', 'photo_orders', 'folder_formats', 'folder_orders',
            'folder_splits',
        )}
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
    # The local preference owner permanently supports additive version 1 maps:
    # older files omit order/folder fields and retain their existing photo view.
    result: dict[str, dict[str, str]] = {}
    for field, choices, default in (
        ("card_formats", PHOTO_CARD_FORMATS, "default"),
        ("photo_orders", ("oldest", "newest"), "oldest"),
        ("folder_formats", PHOTO_CARD_FORMATS, "default"),
        ("folder_orders", ("oldest", "newest"), "oldest"),
        ("folder_splits", None, ""),
    ):
        rows = payload.get(field, {})
        if (not isinstance(rows, dict)
                or len(rows) > GALLERY_VIEW_PREFERENCES_MAX_GALLERIES):
            raise ValueError("Invalid gallery view preferences.")
        result[field] = {}
        for raw_id, value in rows.items():
            if (not isinstance(raw_id, str) or not raw_id.isdecimal()
                    or not isinstance(value, str)):
                continue
            if choices is not None and value not in choices:
                continue
            if choices is None and (
                not value.isascii() or not value.isdecimal()
                or str(int(value)) != value
                or not GALLERY_FOLDER_SHARE_BOUNDS[0] <= int(value)
                <= GALLERY_FOLDER_SHARE_BOUNDS[1]
            ):
                continue
            gallery_id = int(raw_id)
            if 0 < gallery_id <= LINKING_GALLERY_ID_MAX and value != default:
                result[field][str(gallery_id)] = value
    return result


def _write_preferences(path: Path, preferences: dict[str, dict[str, str]]) -> None:
    """Publish a complete view file only when it remains readable within its cap.

    Args:
        path (Path): Credential-free local identity file.
        preferences (dict[str, dict[str, str]]): Validated independent maps for
            card_formats, photo_orders, folder_formats, folder_orders and
            folder_splits, each keyed by canonical positive gallery IDs.
    Returns:
        None: The complete bounded version 1 snapshot is atomically published.
    Raises:
        OSError: Atomic local publication fails.
        ValueError: The encoded complete file would exceed the read-size limit.
    """
    payload = {"version": 1, **preferences}
    # Match the state-store owner's UTF-8/indentation; the schema number and
    # indentation width describe the existing JSON wire format.
    encoded = json.dumps(payload, indent=2, ensure_ascii=False).encode('utf-8')
    if len(encoded) > GALLERY_VIEW_PREFERENCES_MAX_BYTES:
        raise ValueError('Gallery view preferences exceed their size limit.')
    atomic_write_json(path, payload)


def load_gallery_folder_split(path: Path, gallery_id: int) -> int | None:
    """Restore a local relative divider position for one physical gallery.

    Args:
        path (Path): Credential-free local file for the verified identity.
        gallery_id (int): Selected positive gallery identity.
    Returns:
        int | None: Saved thousandths of pane height, or None for automatic
            compact layout when missing, invalid or unreadable.
    """
    if type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX:
        return None
    try:
        value = _read_preferences(path)['folder_splits'].get(str(gallery_id))
        return int(value) if value is not None else None
    except (OSError, ValueError):
        # Local preference owner permanently keeps browsing usable with damaged
        # storage. The writer refuses unreadable state rather than replacing it.
        return None


def save_gallery_folder_split(
    path: Path, gallery_id: int, share: int | None,
) -> bool:
    """Atomically save only the gallery's local section-divider position.

    Args:
        path (Path): Credential-free local file for the verified identity.
        gallery_id (int): Selected positive physical gallery identity.
        share (int | None): Bounded thousandths of pane height; None restores
            automatic compact layout and removes the explicit override.
    Returns:
        bool: True after local persistence; False for invalid input, full state
            or failed IO. Unreadable existing files remain untouched.
    """
    if (type(gallery_id) is not int
            or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
            or share is not None and (
                type(share) is not int
                or not GALLERY_FOLDER_SHARE_BOUNDS[0] <= share
                <= GALLERY_FOLDER_SHARE_BOUNDS[1]
            )):
        return False
    try:
        preferences = _read_preferences(path)
        rows = preferences['folder_splits']
        if share is None:
            rows.pop(str(gallery_id), None)
        else:
            rows[str(gallery_id)] = str(share)
        if len(rows) > GALLERY_VIEW_PREFERENCES_MAX_GALLERIES:
            return False
        _write_preferences(path, preferences)
        return True
    except (OSError, ValueError):
        return False


def load_gallery_card_format(
    path: Path, gallery_id: int, *, folders: bool = False,
) -> str:
    """Restore one gallery's local presentation without changing its source.

    Args:
        path (Path): Credential-free file for one verified site/account.
        gallery_id (int): Positive gallery identity selected in Photos.
        folders (bool): Read the independent child-folder format when True.
    Returns:
        str: Saved supported key, or default when missing, invalid or unreadable.
    """
    if type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX:
        return "default"
    try:
        field = 'folder_formats' if folders else 'card_formats'
        return _read_preferences(path)[field].get(str(gallery_id), "default")
    except (OSError, ValueError):
        return "default"


def save_gallery_card_format(
    path: Path, gallery_id: int, value: str, *, folders: bool = False,
) -> bool:
    """Save one local choice atomically while preserving other galleries.

    Args:
        path (Path): Credential-free file for one verified site/account.
        gallery_id (int): Positive gallery identity selected in Photos.
        value (str): Supported format key; default removes an explicit override.
        folders (bool): Save only the independent child-folder format when True.
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
        rows = preferences['folder_formats' if folders else 'card_formats']
        key = str(gallery_id)
        if value == "default":
            rows.pop(key, None)
        else:
            rows[key] = value
        if len(rows) > GALLERY_VIEW_PREFERENCES_MAX_GALLERIES:
            return False
        # Version 1 is the structural local-view document schema, not a policy.
        _write_preferences(path, preferences)
        return True
    except (OSError, ValueError):
        return False


def load_gallery_photo_order(
    path: Path, gallery_id: int, *, folders: bool = False,
) -> str:
    """Restore the persistent WinApp order for one verified gallery.

    Args:
        path (Path): Local file scoped to the verified site and account.
        gallery_id (int): Selected physical gallery identity.
        folders (bool): Read child-gallery record order instead of photo order.
    Returns:
        str: newest or the original oldest order for absent/unreadable state.
    """
    if type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX:
        return "oldest"
    try:
        field = 'folder_orders' if folders else 'photo_orders'
        return _read_preferences(path)[field].get(str(gallery_id), "oldest")
    except (OSError, ValueError):
        # The local preference owner keeps browsing usable on damaged storage;
        # saves still refuse that file. This is permanent source-mode support.
        return "oldest"


def save_gallery_photo_order(
    path: Path, gallery_id: int, value: str, *, folders: bool = False,
) -> bool:
    """Persist order without changing formats, other galleries or the server.

    Args:
        path (Path): Local file scoped to the verified site and account.
        gallery_id (int): Selected physical gallery identity.
        value (str): oldest or newest; oldest removes the explicit override.
        folders (bool): Save only the independent child-gallery record order.
    Returns:
        bool: True after atomic persistence; False preserves unreadable state.
    """
    if (type(gallery_id) is not int
            or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
            or value not in ("oldest", "newest")):
        return False
    try:
        preferences = _read_preferences(path)
        rows = preferences['folder_orders' if folders else 'photo_orders']
        key = str(gallery_id)
        if value == "oldest":
            rows.pop(key, None)
        else:
            rows[key] = value
        if len(rows) > GALLERY_VIEW_PREFERENCES_MAX_GALLERIES:
            return False
        _write_preferences(path, preferences)
        return True
    except (OSError, ValueError):
        return False
