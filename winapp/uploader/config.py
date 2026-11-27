# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Migrate and normalize versioned uploader configuration.
# Responsibilities:
#   - Preserve existing preferences while applying supported configuration defaults.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/uploader/config.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
#
# Notes:
#   - Keep comments and docstrings intact when modifying this file.
"""
Versioned configuration migration and normalization for the Windows uploader.
"""

import os
from pathlib import Path
from typing import Any, Dict, Mapping, Tuple
from .flight_jobs import read_document
from .gallery_media_cache import GalleryMediaCache, MEDIA_CACHE_MAX_BYTES
from .localization import DEFAULT_LANGUAGE, LANGUAGE_NAMES, gettext
from .state_store import atomic_write_json
from .thumbnail_batch_protocol import PRODUCTION_MAX_ITEMS

APP_PROFILE_NAME = "PHPGalleryUploader"

# Purpose: Share the established worker admission limits and preset counts.
# Type: int and tuple[int, ...]. Units: workers. Scope: WinApp import settings.
# Consumers: Tk and Qt worker selectors and upload/thumbnail policy.
# Rationale: Thumbnail preparation can use up to 32 workers while FIFO sends
# one upload at a time. These are the existing Tk choices within those bounds.
MAX_THUMBNAIL_WORKERS = 32
MAX_UPLOAD_WORKERS = 1
WORKER_COUNT_PRESETS = (1, 2, 4, 6, 8, 12, 16, 24, 32)


def worker_choices(maximum: int) -> list[tuple[int, str]]:
    """Present the stable worker counts and localized automatic choice.

    Args:
        maximum (int): Highest numeric choice allowed for this operation.
    Returns:
        list[tuple[int, str]]: Persisted count and display caption in reference
            order. Zero is the existing automatic selection.
    """
    choices = [(0, gettext("Auto"))]
    choices.extend((count, str(count)) for count in WORKER_COUNT_PRESETS if count <= maximum)
    return choices


def ai_backend_labels() -> dict[str, str]:
    """Describe the selectable AI providers and modes in the current locale.

    Returns:
        dict[str, str]: Stable backend codes mapped to display labels.
    """
    return {
        "auto": gettext("Auto"),
        "pillow": "Pillow",
        "transformers": "Transformers",
        "ollama": "Ollama",
        "external": gettext("External command"),
    }

# Purpose: Convert user-facing archive size limits to their durable byte values.
# Type: int. Units: bytes per GiB. Scope: both WinApp Settings forms.
# Consumers: WatcherConfig editors. Rationale: IEC binary units match legacy preferences.
BYTES_PER_GIB = 1024 ** 3
LANGUAGE_FILE = "ui-language.json"
THUMBNAIL_BATCH_FILE = "thumbnail-download.json"

# Purpose: Offer ordered steady download limits within the deployed protocol.
# Type: tuple[int, ...]. Units: photos per API request. Scope: one WinApp profile.
# Consumers: Settings, thumbnail preference I/O and the Photos viewer.
# Rationale: 8, 16, 24 and the wire cap of 32 permit smaller requests on slow
# installations without changing the first-load, byte or decode limits.
THUMBNAIL_BATCH_CHOICES = (8, 16, 24, PRODUCTION_MAX_ITEMS)

# Purpose: Use the full negotiated wire count on a healthy thumbnail stream.
# Type: int. Units: photos per API request. Scope: steady Photos loads.
# Consumers: saved preference reader and the Photos viewer.
# Rationale: 32 is four times the eight-photo probe within the existing API.
THUMBNAIL_BATCH_DEFAULT_SIZE = PRODUCTION_MAX_ITEMS


def load_ui_language(profile: Path) -> str:
    """Read the app language or inherit the earlier flight interface choice.

    Args:
        profile (Path): Owned per-user WinApp profile containing preferences.

    Returns:
        str: Supported language code, or English if neither document has one.
    """
    # Read the old value independently of report settings. Older installations
    # can still read their own preferences if the app is rolled back.
    for path, key in (
        (profile / LANGUAGE_FILE, "language"),
        (profile / "flights" / "preferences.json", "ui_language"),
    ):
        try:
            value = read_document(path).get(key)
        except (ValueError, RecursionError):
            continue
        if isinstance(value, str) and value in LANGUAGE_NAMES:
            return value
    return DEFAULT_LANGUAGE


def save_ui_language(profile: Path, language: str) -> None:
    """Atomically save the language without writing connection or report state.

    Args:
        profile (Path): Owned per-user WinApp profile receiving the selection.
        language (str): Supported WinApp locale code from the settings control.

    Returns:
        None: The saved selection will be read when WinApp next starts.

    Raises:
        ValueError: The language code is unsupported.
        OSError: The profile or selection cannot be written.
    """
    if language not in LANGUAGE_NAMES:
        raise ValueError("Unsupported interface language")
    atomic_write_json(profile / LANGUAGE_FILE, {"language": language})


def load_thumbnail_batch_size(profile: Path) -> int:
    """Read the local steady thumbnail request limit.

    Args:
        profile (Path): Owned per-user WinApp profile.
    Returns:
        int: One supported limit, or the factory default for missing or damaged
            preferences. The existing file remains available for recovery.
    """
    try:
        size = read_document(profile / THUMBNAIL_BATCH_FILE).get("batch_size")
    except (ValueError, RecursionError):
        # Previous profiles have no file. Keep browsing available for those
        # profiles and for a damaged optional preference.
        return THUMBNAIL_BATCH_DEFAULT_SIZE
    if type(size) is int and size in THUMBNAIL_BATCH_CHOICES:
        return size
    return THUMBNAIL_BATCH_DEFAULT_SIZE


def save_thumbnail_batch_size(profile: Path, size: int) -> None:
    """Publish the next thumbnail request limit independently of uploader state.

    Args:
        profile (Path): Owned per-user WinApp profile.
        size (int): One advertised thumbnail request limit.
    Returns:
        None: Atomically replaces only the thumbnail transport preference.
    Raises:
        ValueError: The requested limit is unsupported.
        OSError: The preference cannot be persisted.
    """
    if type(size) is not int or size not in THUMBNAIL_BATCH_CHOICES:
        raise ValueError("Unsupported thumbnail request size")
    atomic_write_json(profile / THUMBNAIL_BATCH_FILE, {"batch_size": size})


# Purpose: Offer a compact set of shared thumbnail quota choices.
# Type: tuple[int, ...]. Units: GiB. Scope: per-user ordinary thumbnail storage.
# Consumers: Photos storage settings. Rationale: 1–64 GiB covers small disks
# and large collections; 4 GiB is the cache owner's factory default.
THUMBNAIL_CACHE_LIMIT_CHOICES_GIB = (1, 2, 4, 8, 16, 32, 64)


def set_thumbnail_cache_limit_gib(value: int) -> bool:
    """Save one per-user cache preference through the shared storage owner.

    Args:
        value (int): One advertised ordinary thumbnail quota in GiB.
    Returns:
        bool: True only after shared persistence confirms the update. Profile
            configuration is untouched because the physical pool is shared.
    """
    if type(value) is not int or value not in THUMBNAIL_CACHE_LIMIT_CHOICES_GIB:
        return False
    return GalleryMediaCache.for_current_user(
        MEDIA_CACHE_MAX_BYTES, thumbnails=True,
    ).set_thumbnail_limit_bytes(value * 1024**3)

CONFIG_SCHEMA_VERSION = 2

DEFAULTS: Dict[str, Any] = {
    "schema_version": CONFIG_SCHEMA_VERSION,
    "watch_recursive": False,
    "manual_thumbnail_mode": "server",
    "archive_max_entries": 5000,
    "archive_max_uncompressed_bytes": 16 * 1024 * 1024 * 1024,
    "archive_max_file_bytes": 2 * 1024 * 1024 * 1024,
    "archive_max_compression_ratio": 250.0,
    "staging_cleanup_age_hours": 24.0,
    "close_to_tray": True,
    "start_with_windows": True,
    "delete_uploaded_files": True,
    "activity_history_limit": 500,
    "experimental_canvas_gallery_renderer": True,
}

ALLOWED_EXTRA_KEYS = set(DEFAULTS)

# Purpose: Name the optional environment override for experimental rendering.
# Type: str. Units: environment variable name. Scope: one process startup.
# Consumers: resolve_canvas_gallery_renderer_enabled. Rationale: keep the
#   override explicit for legacy benchmark and launcher callers.
CANVAS_GALLERY_RENDERER_ENVIRONMENT_VARIABLE = "PHP_GALLERY_CANVAS_GRID"


def _clamp_int(value: Any, default: int, low: int, high: int) -> int:
    """Convert and clamp one integer configuration value."""
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = default
    return max(low, min(high, parsed))


def _clamp_float(value: Any, default: float, low: float, high: float) -> float:
    """Convert and clamp one floating-point configuration value."""
    try:
        parsed = float(value)
    except (TypeError, ValueError):
        parsed = default
    return max(low, min(high, parsed))


def migrate_config_payload(data: Dict[str, Any]) -> Tuple[Dict[str, Any], bool]:
    """
    Normalize redesign-specific keys while preserving legacy application keys.

    Legacy uploader fields are intentionally retained verbatim so the existing
    ``WatcherConfig`` parser remains the compatibility authority.  New values
    are bounded here and a schema marker is added for future migrations.

    Args:
        data (Dict[str, Any]): Decoded JSON configuration with legacy
            connection, watcher and AI keys; preserved values include strings,
            booleans, numbers and string lists.

    Returns:
        Tuple[Dict[str, Any], bool]: Tuple of normalized payload and whether an
            on-disk rewrite is useful.
    """
    original = dict(data) if isinstance(data, dict) else {}
    result = dict(original)
    result["schema_version"] = CONFIG_SCHEMA_VERSION
    result["watch_recursive"] = bool(result.get("watch_recursive", DEFAULTS["watch_recursive"]))
    mode = str(result.get("manual_thumbnail_mode", DEFAULTS["manual_thumbnail_mode"]) or "server").lower()
    result["manual_thumbnail_mode"] = mode if mode in {"server", "local"} else "server"
    result["archive_max_entries"] = _clamp_int(result.get("archive_max_entries"), DEFAULTS["archive_max_entries"], 10, 50000)
    result["archive_max_uncompressed_bytes"] = _clamp_int(
        result.get("archive_max_uncompressed_bytes"), DEFAULTS["archive_max_uncompressed_bytes"], 10 * 1024 * 1024, 128 * 1024 * 1024 * 1024
    )
    result["archive_max_file_bytes"] = _clamp_int(
        result.get("archive_max_file_bytes"), DEFAULTS["archive_max_file_bytes"], 1024 * 1024, 32 * 1024 * 1024 * 1024
    )
    result["archive_max_compression_ratio"] = _clamp_float(
        result.get("archive_max_compression_ratio"), DEFAULTS["archive_max_compression_ratio"], 2.0, 10000.0
    )
    result["staging_cleanup_age_hours"] = _clamp_float(
        result.get("staging_cleanup_age_hours"), DEFAULTS["staging_cleanup_age_hours"], 1.0, 24.0 * 30.0
    )
    result["close_to_tray"] = bool(result.get("close_to_tray", DEFAULTS["close_to_tray"]))
    # Missing legacy preferences enable startup; an explicit opt-out survives
    # migration and every installer/application upgrade.
    result["start_with_windows"] = (
        result.get("start_with_windows", DEFAULTS["start_with_windows"]) is True
    )
    result["delete_uploaded_files"] = (
        result.get("delete_uploaded_files", DEFAULTS["delete_uploaded_files"])
        is True
    )
    result["activity_history_limit"] = _clamp_int(
        result.get("activity_history_limit"), DEFAULTS["activity_history_limit"], 50, 5000
    )
    result["experimental_canvas_gallery_renderer"] = (
        result.get(
            "experimental_canvas_gallery_renderer",
            DEFAULTS["experimental_canvas_gallery_renderer"],
        ) is True
    )
    return result, result != original


def resolve_canvas_gallery_renderer_enabled(
    saved: bool, environment: Mapping[str, str] | None = None,
) -> bool:
    """Resolve the frozen startup choice for the experimental Canvas renderer.

    An explicit environment value is authoritative for compatibility with
    benchmark and legacy callers. Unknown values fail closed rather than
    enabling an experimental renderer accidentally.

    Args:
        saved (bool): Strict saved preference; only the literal True enables it.
        environment (Mapping[str, str] | None): Environment source for testing
            or embedding; None reads the current process environment.

    Returns:
        bool: Whether Canvas rendering is enabled for the current process.
    """
    source = os.environ if environment is None else environment
    if CANVAS_GALLERY_RENDERER_ENVIRONMENT_VARIABLE not in source:
        return saved is True
    return source[CANVAS_GALLERY_RENDERER_ENVIRONMENT_VARIABLE] == "1"
