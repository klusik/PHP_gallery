"""
Versioned configuration migration and normalization for the Windows uploader.
"""

from typing import Any, Dict, Tuple

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
    "activity_history_limit": 500,
}

ALLOWED_EXTRA_KEYS = set(DEFAULTS)


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

    @return: Tuple of normalized payload and whether an on-disk rewrite is useful.
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
    result["activity_history_limit"] = _clamp_int(
        result.get("activity_history_limit"), DEFAULTS["activity_history_limit"], 50, 5000
    )
    return result, result != original
