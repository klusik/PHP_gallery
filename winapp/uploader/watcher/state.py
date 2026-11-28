# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/state.py
# Module Type: Windows Client State
# Purpose: Own Watcher configuration and durable local upload state.
# Responsibilities:
#   - Define the Watcher runtime settings and their configuration codecs.
#   - Coordinate configuration changes with upload-mode evidence owners.
#   - Persist legacy and linked upload state with bounded recovery markers.
#   - Capture immutable worker settings and validate backend selections.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own Watcher settings, local state and validated worker configuration."""

import copy
import hashlib
import json
import math
import os
import os
import re
import stat
import threading
import time
import uuid
from dataclasses import dataclass, field, replace
from pathlib import Path
from types import TracebackType
from typing import Any, Callable, Dict, Optional

from ..config import (
    APP_PROFILE_NAME,
    CONFIG_SCHEMA_VERSION,
    DEFAULTS as REDESIGN_CONFIG_DEFAULTS,
    MAX_THUMBNAIL_WORKERS,
    MAX_UPLOAD_WORKERS,
    migrate_config_payload,
)
from ..config_snapshot import ConfigSnapshot, ConfigSnapshotError, ConfigSnapshotStore
from ..credential_store import WindowsUploadModeStore
from ..linked_upload import LinkedTargetLease, LinkedUploadTarget
from ..mode_coordinator import ModeCoordinatorError
from ..models import JsonValue
from ..state_store import (
    accepted_work_owner,
    linked_upload_state_path,
    load_upload_state_for_mutation,
    merge_upload_sections,
    _reject_mode_history_constant,
    _strict_mode_history_float,
    _strict_mode_history_object,
)
from ..http_transport import DEFAULT_INVENTORY_REFRESH_SECONDS, normalize_upload_url
from ..localization import gettext, gettext_noop

APP_NAME = APP_PROFILE_NAME
APP_DISPLAY_NAME = gettext_noop("PHP Gallery uploader")
CONFIG_DIR = Path(os.environ.get("APPDATA", str(Path.home()))) / APP_NAME
CONFIG_PATH = CONFIG_DIR / "config.json"
STATE_PATH = CONFIG_DIR / "upload_state.json"
LOG_PATH = CONFIG_DIR / "watcher.log"
STAGING_ROOT = CONFIG_DIR / "staging"

SUPPORTED_SUFFIXES = {
    ".jpg", ".jpeg", ".png", ".gif", ".webp", ".heic", ".heif", ".dng",
}
DEFAULT_INTERVAL_SECONDS = 1.0
DEFAULT_STABLE_SECONDS = 2.0
# Purpose: Limit how often the watcher checks the directory.
# Type: float. Units: seconds. Scope: the Watcher form.
# Consumers: WatcherApp.current_watcher_config.
# Rationale: Preserve the existing 0.2-second minimum for scan scheduling.
MIN_INTERVAL_SECONDS = 0.2
# Purpose: Give a file time to stop changing before a watcher upload.
# Type: float. Units: seconds. Scope: the Watcher form.
# Consumers: WatcherApp.current_watcher_config.
# Rationale: Preserve the existing half-second minimum for file stability.
MIN_STABLE_SECONDS = 0.5
# Purpose: Set the shared Watcher recording marker phase interval.
# Type: int. Units: milliseconds. Scope: one active GUI watcher.
# Consumers: WatcherApp._set_watcher_recording, _pulse_watcher_recording,
# _render_watcher_recording and refresh_watcher_header.
# Rationale: 267 ms is roughly one third of the previous 800 ms phase interval;
# two phases form an approximately 534 ms bright/dim cycle without hiding it.
WATCHER_RECORDING_PULSE_MS = 267

# Purpose: Animate unknown-duration Watcher linking and initial folder capture.
# Type: int. Units: milliseconds. Scope: Watcher startup activity only.
# Consumers: WatcherApp._set_watch_start_activity.
# Rationale: 50 ms keeps activity visible without suggesting measured progress.
WATCHER_START_ACTIVITY_MS = 50
# Purpose: Preserve transient-failure backoff without unbound historical state.
# Type: int. Units: seconds. Scope: one live legacy watcher and source hash.
# Consumers: WatcherThread.scan_once. Rationale: start at the existing five
# seconds and retain the old one-hour ceiling while avoiding another gallery's
# persisted retry metadata. The exponent remains bounded at the existing seven.
LEGACY_WATCH_RETRY_BASE_SECONDS = 5
LEGACY_WATCH_RETRY_CAP_SECONDS = 3600
LEGACY_WATCH_RETRY_EXPONENT_MAX = 7

DEFAULT_AI_WORKER_POLL_SECONDS = 60.0
DEFAULT_AI_WORKER_LEASE_SECONDS = 600
AI_WORKER_MIN_POLL_SECONDS = 5.0
AI_WORKER_MAX_LEASE_SECONDS = 3600

# Purpose: Keep file inventory polling within the existing UI policy.
# Type: float. Units: seconds. Scope: both desktop forms.
# Consumers: WatcherConfig editors. Rationale: One second preserves server load.
MIN_INVENTORY_REFRESH_SECONDS = 1.0
# Purpose: Preserve adequate time for an AI worker's first heartbeat.
# Type: int. Units: seconds. Scope: saved settings and AI worker admission.
# Consumers: WatcherConfig editors and the worker. Rationale: One minute is the existing floor.
AI_WORKER_MIN_LEASE_SECONDS = 60
# Purpose: Bound archive extraction settings before they enter the shared worker.
# Type: tuple[int, int]. Units: ZIP entries. Scope: both desktop forms.
# Consumers: WatcherConfig editors. Rationale: Ten to fifty thousand entries
# preserve the existing operator guard while admitting large archives.
ZIP_ENTRY_RANGE = (10, 50000)
# Purpose: Bound uncompressed size across all files in one import.
# Type: tuple[float, float]. Units: GiB. Scope: both desktop forms.
# Consumers: WatcherConfig editors. Rationale: 0.01 to 128 GiB matches the existing quota.
ZIP_TOTAL_RANGE = (0.01, 128.0)
# Purpose: Bound each extracted entry before the shared staging owner opens it.
# Type: tuple[float, float]. Units: GiB. Scope: both desktop forms.
# Consumers: WatcherConfig editors. Rationale: 0.001 to 32 GiB preserves the existing limit.
ZIP_FILE_RANGE = (0.001, 32.0)
# Purpose: Keep ZIP expansion ratio overrides within the existing capture limits.
# Type: tuple[float, float]. Units: uncompressed/compressed ratio.
# Scope: both desktop forms. Consumers: WatcherConfig editors.
# Rationale: A range of 2 to 10000 preserves manual override limits.
ZIP_EXPANSION_RANGE = (2.0, 10000.0)
# Purpose: Bound retention for disposable import staging.
# Type: tuple[float, float]. Units: hours. Scope: both desktop forms.
# Consumers: WatcherConfig editors. Rationale: One hour to thirty days preserves retry windows.
STAGING_RETENTION_RANGE = (1.0, 24.0 * 30.0)
# Purpose: Keep optional native detector scores in their calibrated input range.
# Type: tuple[float, float]. Units: probability. Scope: both desktop forms.
# Consumers: WatcherConfig editors. Rationale: 0.01 to 0.95 matches the current UI guard.
AI_DETECTION_RANGE = (0.01, 0.95)
AI_ANALYZER_TIMEOUT_SECONDS = 1800
AI_HEARTBEAT_MIN_SECONDS = 15.0
AI_MODEL_NAME_DEFAULT = "local-image-metadata"
AI_MODEL_VERSION_DEFAULT = "1"
AI_VISION_BACKEND_DEFAULT = "auto"
AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT = "Salesforce/blip-image-captioning-base"
AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT = "google/owlvit-base-patch32"
AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT = 0.18
AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT = (
    "person, people, man, woman, child, face, dog, cat, horse, bird, animal, car, truck, bus, "
    "train, aircraft, airplane, helicopter, boat, bicycle, motorcycle, house, building, bridge, "
    "road, street, city, village, church, castle, tower, window, door, guitar, piano, violin, "
    "musical instrument, food, drink, table, chair, bed, computer, laptop, phone, camera, flower, "
    "tree, forest, mountain, beach, sea, river, lake, sky, cloud, sunset, snow, rain, airport, "
    "runway, cockpit, airplane wing"
)
AI_OLLAMA_URL_DEFAULT = "http://127.0.0.1:11434"
AI_OLLAMA_MODEL_DEFAULT = "llava:latest"
AI_SEMANTIC_PROMPT_DEFAULT = (
    "Describe this image for private gallery search metadata. "
    "Return JSON only with keys internal_description, labels, objects, scene, activities, text, and confidence_notes. "
    "Use concise lower-case labels for visible objects such as person, bridge, guitar, house, car, animal, aircraft, food, landscape, building. "
    "Do not invent objects that are not visually apparent. The metadata is internal search data, not public prose."
)
AI_VISION_BACKEND_CHOICES = ("auto", "pillow", "transformers", "ollama", "external")
THUMBNAIL_SIZES = [300, 600, 800, 960, 1280, 1600]
DEFAULT_THUMBNAIL_WORKERS = max(2, min(12, (os.cpu_count() or 4) // 2 or 2))
DEFAULT_UPLOAD_WORKERS = 1
@dataclass
class WatcherConfig:
    """
    Runtime and persisted configuration for the watcher app.

    The values are intentionally simple JSON-compatible primitives so the
    configuration file can be edited manually when needed. The GUI writes the
    same structure as command-line mode reads, which keeps both entry points
    aligned.

    Args:
        schema_version (int): Existing configuration migration marker.
        delete_uploaded_files (bool): Saved preference to delete a watched
            source after exact captured bytes receive upload confirmation.
        watch_recursive (bool): Include descendants in watch-folder discovery.
        manual_thumbnail_mode (str): Server or local thumbnail generation.
        archive_max_entries (int): Maximum accepted ZIP entry count.
        archive_max_uncompressed_bytes (int): Maximum expanded ZIP bytes.
        archive_max_file_bytes (int): Maximum individual expanded file bytes.
        archive_max_compression_ratio (float): Maximum accepted expansion
            ratio.
        staging_cleanup_age_hours (float): Age before unowned staging expires.
        close_to_tray (bool): Keep background work when closing to the tray.
        start_with_windows (bool): Start the installed app in the tray at logon.
        experimental_canvas_gallery_renderer (bool): Persisted renderer
            choice; new and missing legacy values default to Canvas, while
            explicit False keeps the native gallery renderer.
        activity_history_limit (int): Maximum retained durable activity
            records.
        watched_folder (str): Local folder to observe for newly added images.
        gallery_url (str): PHP Gallery site root or explicit upload endpoint
            URL.
        api_key (str): Gallery-scoped API key used in the X-Gallery-API-Key
            header.
        scan_interval_seconds (float): Polling delay between folder scans.
        stable_seconds (float): Minimum unchanged duration before a file is
            uploaded.
        create_thumbnails (bool): Whether the server should create thumbnails
            after accepting the upload.
        attach_sim_camera_metadata (bool): Whether watched-folder uploads
            should try to attach the current Flight Simulator camera location.
        simconnect_dll_path (str): Optional user-selected SimConnect.dll
            override.
        sync_utc_time (bool): Opt-in UTC correction while watching; off by
            default.
        manual_thumbnail_workers (int): Manual upload process count for local
            thumbnail generation. Zero means automatic.
        manual_upload_workers (int): Manual upload thread count for multipart
            HTTP upload requests. Zero means automatic.
        inventory_refresh_seconds (float): Seconds between remote inventory
            handshakes. The default keeps long batches using fresh short-lived
            API requests.
        ai_worker_enabled (bool): Whether this app should run the optional AI
            metadata worker. Disabled by default so existing upload behavior is
            unchanged after upgrade.
        ai_worker_poll_seconds (float): Delay between queue polls when the
            server has no AI work for this API key.
        ai_worker_lease_seconds (int): Requested server lease length for one
            claimed AI job. Heartbeats extend the lease while work continues.
        ai_model_name (str): Worker-reported model or analyzer family name.
        ai_model_version (str): Worker-reported model or analyzer version.
        ai_external_command (str): Optional local command that receives an
            image path placeholder and returns JSON metadata on stdout.
        ai_vision_backend (str): Analyzer selection. Auto prefers the external
            command when configured, then the in-process Transformers backend,
            then local Ollama vision, then Pillow.
        ai_transformers_caption_model (str): Optional Hugging Face image-to-
            text model used inside this Python process for semantic captions.
        ai_transformers_detector_model (str): Optional Hugging Face zero-shot
            object detector used inside this Python process for semantic
            labels.
        ai_transformers_object_labels (str): Candidate object labels passed to
            the local zero-shot detector.
        ai_transformers_detection_threshold (float): Minimum detector score
            saved as an internal object label.
        ai_ollama_url (str): Local Ollama base URL used for semantic vision
            captions. The default points to localhost.
        ai_ollama_model (str): Ollama vision model name, for example
            llava:latest or llama3.2-vision:latest.
        ai_semantic_prompt (str): Prompt sent only to a local vision backend.
        _snapshot_token (object | None): Private config-owner generation; never
            serialized or used as a credential.
    """

    schema_version: int = CONFIG_SCHEMA_VERSION
    watched_folder: str = ""
    gallery_url: str = ""
    api_key: str = ""
    scan_interval_seconds: float = DEFAULT_INTERVAL_SECONDS
    stable_seconds: float = DEFAULT_STABLE_SECONDS
    create_thumbnails: bool = True
    attach_sim_camera_metadata: bool = True
    simconnect_dll_path: str = ""
    sync_utc_time: bool = False
    delete_uploaded_files: bool = True
    watch_recursive: bool = bool(REDESIGN_CONFIG_DEFAULTS["watch_recursive"])
    manual_thumbnail_mode: str = str(REDESIGN_CONFIG_DEFAULTS["manual_thumbnail_mode"])
    manual_thumbnail_workers: int = 0
    manual_upload_workers: int = 0
    inventory_refresh_seconds: float = DEFAULT_INVENTORY_REFRESH_SECONDS
    archive_max_entries: int = int(REDESIGN_CONFIG_DEFAULTS["archive_max_entries"])
    archive_max_uncompressed_bytes: int = int(REDESIGN_CONFIG_DEFAULTS["archive_max_uncompressed_bytes"])
    archive_max_file_bytes: int = int(REDESIGN_CONFIG_DEFAULTS["archive_max_file_bytes"])
    archive_max_compression_ratio: float = float(REDESIGN_CONFIG_DEFAULTS["archive_max_compression_ratio"])
    staging_cleanup_age_hours: float = float(REDESIGN_CONFIG_DEFAULTS["staging_cleanup_age_hours"])
    close_to_tray: bool = bool(REDESIGN_CONFIG_DEFAULTS["close_to_tray"])
    start_with_windows: bool = bool(REDESIGN_CONFIG_DEFAULTS["start_with_windows"])
    experimental_canvas_gallery_renderer: bool = bool(
        REDESIGN_CONFIG_DEFAULTS["experimental_canvas_gallery_renderer"]
    )
    activity_history_limit: int = int(REDESIGN_CONFIG_DEFAULTS["activity_history_limit"])
    ai_worker_enabled: bool = False
    ai_worker_poll_seconds: float = DEFAULT_AI_WORKER_POLL_SECONDS
    ai_worker_lease_seconds: int = DEFAULT_AI_WORKER_LEASE_SECONDS
    ai_model_name: str = AI_MODEL_NAME_DEFAULT
    ai_model_version: str = AI_MODEL_VERSION_DEFAULT
    ai_external_command: str = ""
    ai_vision_backend: str = AI_VISION_BACKEND_DEFAULT
    ai_transformers_caption_model: str = AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT
    ai_transformers_detector_model: str = AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT
    ai_transformers_object_labels: str = AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT
    ai_transformers_detection_threshold: float = AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT
    ai_ollama_url: str = AI_OLLAMA_URL_DEFAULT
    ai_ollama_model: str = AI_OLLAMA_MODEL_DEFAULT
    ai_semantic_prompt: str = AI_SEMANTIC_PROMPT_DEFAULT
    _snapshot_token: object | None = field(default=None, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "WatcherConfig":
        """
        Build a typed configuration object from decoded JSON data.

        Missing keys are treated as defaults. This makes configuration upgrades
        safe because older config files keep working when new fields are added.
        Numeric values are coerced from strings where possible because manual
        edits and GUI variables often produce text.

        Args:
            data (Dict[str, Any]): JSON configuration keyed by the
                WatcherConfig field names; string connection/model/path values,
                boolean switches, integer counts/byte limits and floating-point
                durations/ratios.

        Returns:
            WatcherConfig: Normalized WatcherConfig instance.

        Raises:
            ValueError: Raised by float conversion when numeric values are
                present but cannot be parsed.
        """
        data, _migrated = migrate_config_payload(data)
        return cls(
            schema_version=CONFIG_SCHEMA_VERSION,
            watched_folder=str(data.get("watched_folder", "")),
            gallery_url=str(data.get("gallery_url", "")),
            api_key=str(data.get("api_key", "")),
            scan_interval_seconds=float(data.get("scan_interval_seconds", DEFAULT_INTERVAL_SECONDS) or DEFAULT_INTERVAL_SECONDS),
            stable_seconds=float(data.get("stable_seconds", DEFAULT_STABLE_SECONDS) or DEFAULT_STABLE_SECONDS),
            create_thumbnails=bool(data.get("create_thumbnails", True)),
            attach_sim_camera_metadata=bool(data.get("attach_sim_camera_metadata", True)),
            simconnect_dll_path=str(data.get("simconnect_dll_path", "")),
            sync_utc_time=data.get("sync_utc_time", False) is True,
            delete_uploaded_files=bool(data.get("delete_uploaded_files", True)),
            watch_recursive=bool(data.get("watch_recursive", REDESIGN_CONFIG_DEFAULTS["watch_recursive"])),
            manual_thumbnail_mode=str(data.get("manual_thumbnail_mode", REDESIGN_CONFIG_DEFAULTS["manual_thumbnail_mode"]) or "server"),
            manual_thumbnail_workers=int(data.get("manual_thumbnail_workers", 0) or 0),
            manual_upload_workers=int(data.get("manual_upload_workers", 0) or 0),
            inventory_refresh_seconds=float(data.get("inventory_refresh_seconds", DEFAULT_INVENTORY_REFRESH_SECONDS) or DEFAULT_INVENTORY_REFRESH_SECONDS),
            archive_max_entries=int(data.get("archive_max_entries", REDESIGN_CONFIG_DEFAULTS["archive_max_entries"]) or REDESIGN_CONFIG_DEFAULTS["archive_max_entries"]),
            archive_max_uncompressed_bytes=int(data.get("archive_max_uncompressed_bytes", REDESIGN_CONFIG_DEFAULTS["archive_max_uncompressed_bytes"]) or REDESIGN_CONFIG_DEFAULTS["archive_max_uncompressed_bytes"]),
            archive_max_file_bytes=int(data.get("archive_max_file_bytes", REDESIGN_CONFIG_DEFAULTS["archive_max_file_bytes"]) or REDESIGN_CONFIG_DEFAULTS["archive_max_file_bytes"]),
            archive_max_compression_ratio=float(data.get("archive_max_compression_ratio", REDESIGN_CONFIG_DEFAULTS["archive_max_compression_ratio"]) or REDESIGN_CONFIG_DEFAULTS["archive_max_compression_ratio"]),
            staging_cleanup_age_hours=float(data.get("staging_cleanup_age_hours", REDESIGN_CONFIG_DEFAULTS["staging_cleanup_age_hours"]) or REDESIGN_CONFIG_DEFAULTS["staging_cleanup_age_hours"]),
            close_to_tray=bool(data.get("close_to_tray", REDESIGN_CONFIG_DEFAULTS["close_to_tray"])),
            start_with_windows=data["start_with_windows"] is True,
            experimental_canvas_gallery_renderer=(
                data["experimental_canvas_gallery_renderer"] is True
            ),
            activity_history_limit=int(data.get("activity_history_limit", REDESIGN_CONFIG_DEFAULTS["activity_history_limit"]) or REDESIGN_CONFIG_DEFAULTS["activity_history_limit"]),
            ai_worker_enabled=bool(data.get("ai_worker_enabled", False)),
            ai_worker_poll_seconds=float(data.get("ai_worker_poll_seconds", DEFAULT_AI_WORKER_POLL_SECONDS) or DEFAULT_AI_WORKER_POLL_SECONDS),
            ai_worker_lease_seconds=int(data.get("ai_worker_lease_seconds", DEFAULT_AI_WORKER_LEASE_SECONDS) or DEFAULT_AI_WORKER_LEASE_SECONDS),
            ai_model_name=str(data.get("ai_model_name", AI_MODEL_NAME_DEFAULT) or AI_MODEL_NAME_DEFAULT),
            ai_model_version=str(data.get("ai_model_version", AI_MODEL_VERSION_DEFAULT) or AI_MODEL_VERSION_DEFAULT),
            ai_external_command=str(data.get("ai_external_command", "")),
            ai_vision_backend=normalize_ai_vision_backend(str(data.get("ai_vision_backend", AI_VISION_BACKEND_DEFAULT) or AI_VISION_BACKEND_DEFAULT)),
            ai_transformers_caption_model=str(data.get("ai_transformers_caption_model", AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT) or AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT),
            ai_transformers_detector_model=str(data.get("ai_transformers_detector_model", AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT) or AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT),
            ai_transformers_object_labels=str(data.get("ai_transformers_object_labels", AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT) or AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT),
            ai_transformers_detection_threshold=float(data.get("ai_transformers_detection_threshold", AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT) or AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT),
            ai_ollama_url=str(data.get("ai_ollama_url", AI_OLLAMA_URL_DEFAULT) or AI_OLLAMA_URL_DEFAULT),
            ai_ollama_model=str(data.get("ai_ollama_model", AI_OLLAMA_MODEL_DEFAULT) or AI_OLLAMA_MODEL_DEFAULT),
            ai_semantic_prompt=str(data.get("ai_semantic_prompt", AI_SEMANTIC_PROMPT_DEFAULT) or AI_SEMANTIC_PROMPT_DEFAULT),
        )

    def to_dict(self) -> Dict[str, Any]:
        """
        Convert this configuration to a JSON-serializable dictionary.

        Returns:
            Dict[str, Any]: Configuration field names mapped to string
                connection/model/path values, boolean switches, integer
                counts/byte limits and floating-point durations/ratios,
                suitable for JSON.
        """
        return {
            "schema_version": CONFIG_SCHEMA_VERSION,
            "watched_folder": self.watched_folder,
            "gallery_url": self.gallery_url,
            "api_key": self.api_key,
            "scan_interval_seconds": self.scan_interval_seconds,
            "stable_seconds": self.stable_seconds,
            "create_thumbnails": self.create_thumbnails,
            "attach_sim_camera_metadata": self.attach_sim_camera_metadata,
            "simconnect_dll_path": self.simconnect_dll_path,
            "sync_utc_time": self.sync_utc_time,
            "delete_uploaded_files": self.delete_uploaded_files,
            "watch_recursive": self.watch_recursive,
            "manual_thumbnail_mode": self.manual_thumbnail_mode,
            "manual_thumbnail_workers": self.manual_thumbnail_workers,
            "manual_upload_workers": self.manual_upload_workers,
            "inventory_refresh_seconds": self.inventory_refresh_seconds,
            "archive_max_entries": self.archive_max_entries,
            "archive_max_uncompressed_bytes": self.archive_max_uncompressed_bytes,
            "archive_max_file_bytes": self.archive_max_file_bytes,
            "archive_max_compression_ratio": self.archive_max_compression_ratio,
            "staging_cleanup_age_hours": self.staging_cleanup_age_hours,
            "close_to_tray": self.close_to_tray,
            "start_with_windows": self.start_with_windows,
            "experimental_canvas_gallery_renderer": (
                self.experimental_canvas_gallery_renderer
            ),
            "activity_history_limit": self.activity_history_limit,
            "ai_worker_enabled": self.ai_worker_enabled,
            "ai_worker_poll_seconds": self.ai_worker_poll_seconds,
            "ai_worker_lease_seconds": self.ai_worker_lease_seconds,
            "ai_model_name": self.ai_model_name,
            "ai_model_version": self.ai_model_version,
            "ai_external_command": self.ai_external_command,
            "ai_vision_backend": self.ai_vision_backend,
            "ai_transformers_caption_model": self.ai_transformers_caption_model,
            "ai_transformers_detector_model": self.ai_transformers_detector_model,
            "ai_transformers_object_labels": self.ai_transformers_object_labels,
            "ai_transformers_detection_threshold": self.ai_transformers_detection_threshold,
            "ai_ollama_url": self.ai_ollama_url,
            "ai_ollama_model": self.ai_ollama_model,
            "ai_semantic_prompt": self.ai_semantic_prompt,
        }


def trim_path(value: str) -> Optional[Path]:
    """
    Convert one optional path string into a usable Path object.

    Args:
        value (str): Raw environment variable or config value.

    Returns:
        Optional[Path]: Path when the string is non-empty, otherwise None.
    """
    text = str(value).strip()
    if not text:
        return None
    return Path(text)


class CapturedWatcherConfig(WatcherConfig):
    """Hold sealed primitive settings for cooperating worker code.

    This is not a security boundary against arbitrary code in this process.
    """

    def __setattr__(self, name: str,
                    value: str | bool | int | float | object | None) -> None:
        """Refuse later changes to the worker's captured settings.

        Args:
            name (str): Config field selected by the assignment.
            value (str | bool | int | float | object | None): Configuration
                primitive or private snapshot token, accepted during construction.

        Returns:
            None: Stores initial fields before the capture is sealed.

        Raises:
            AttributeError: A sealed capture cannot be changed by a worker.
        """
        if getattr(self, '_capture_sealed', False):
            raise AttributeError('Captured worker configuration is immutable.')
        super().__setattr__(name, value)

    def __delattr__(self, name: str) -> None:
        """Refuse accidental removal of any sealed captured field.

        Args:
            name (str): Field selected for deletion.

        Returns:
            None: Deletes only an unsealed construction-time field.

        Raises:
            AttributeError: A sealed capture cannot be changed by a worker.
        """
        if getattr(self, '_capture_sealed', False):
            raise AttributeError('Captured worker configuration is immutable.')
        super().__delattr__(name)

    def __repr__(self) -> str:
        """Avoid exposing the captured API key in diagnostics.

        Returns:
            str: A fixed non-secret description.
        """
        return 'CapturedWatcherConfig(<private>)'


def capture_worker_config(config: WatcherConfig) -> CapturedWatcherConfig:
    """Create a sealed worker copy preserving the original snapshot token.

    Args:
        config (WatcherConfig): Current host configuration selected for work.

    Returns:
        CapturedWatcherConfig: Independent immutable primitive settings.

    Raises:
        ValueError: Fields or the owner generation cannot be safely captured.
    """
    values = config.to_dict()
    if (config._snapshot_token is None or any(
            type(value) not in (str, int, float, bool) for value in values.values())):
        raise ValueError('Worker configuration is unavailable.')
    captured = CapturedWatcherConfig.from_dict(values)
    captured._snapshot_token = config._snapshot_token
    if captured.to_dict() != values:
        raise ValueError('Worker configuration changed during capture.')
    captured._capture_sealed = True
    return captured


class ConfigStore:
    """
    Small persistence wrapper for the local configuration file.

    The class owns all filesystem details for config persistence so the GUI and
    command-line code do not duplicate path handling, JSON parsing, and atomic
    write behavior.
    """

    def __init__(self, path: Path | str = CONFIG_PATH) -> None:
        """
        Create a config store for one JSON file.

        Args:
            path (Path | str): Selected JSON config path. A string preserves
                its original spelling for mode evidence; Path callers retain
                the spelling already represented by their Path object.

        Returns:
            None: Create a config store for one JSON file.
        """
        self._mode_raw_path = str(path)
        self.path = Path(path)
        self._snapshot_store = ConfigSnapshotStore(
            self.path, frozenset(WatcherConfig().to_dict()),
        )
        self._snapshot = None
        self._lock = threading.RLock()
        self._revision = object()
        self._mode_transaction_active = False

    def load(self, *, read_only: bool = False) -> WatcherConfig:
        """
        Load the configuration file.

        A confirmed missing file returns first-run defaults. An unreadable,
        malformed or ambiguous file is not treated as an empty configuration.

        Args:
            read_only (bool): Normalize supported legacy fields in memory
                without saving a migration to the connection configuration.

        Returns:
            WatcherConfig: Loaded configuration or first-run defaults.

        Raises:
            OSError: Configuration or its parent cannot be safely observed.
        """
        # First launches and explicit --config paths may need a new parent.
        # A created directory does not prove prior mode evidence never existed.
        with self._lock:
            if self._mode_transaction_active:
                raise ConfigSnapshotError('Configuration mode transaction is active.')
            self.path.parent.mkdir(parents=True, exist_ok=True)
            snapshot = self._snapshot_store.inspect()
            self._snapshot = snapshot
            self._revision = object()
            if snapshot.state == 'missing':
                return WatcherConfig(_snapshot_token=self._revision)
            if snapshot.state != 'valid':
                raise ConfigSnapshotError(
                    'Configuration is unavailable. Preserve the file and restore '
                    'a valid backup before starting uploads.'
                )
            try:
                data = self._snapshot_store.read(snapshot)
                normalized, migrated = migrate_config_payload(data)
                config = WatcherConfig.from_dict(normalized)
                config._snapshot_token = self._revision
                canonical_url = normalize_upload_url(config.gallery_url) if config.gallery_url.strip() else ""
                if canonical_url and canonical_url != config.gallery_url:
                    config.gallery_url = canonical_url
                    migrated = True
                if migrated and not read_only:
                    self.save(config)
                return config
            except (ValueError, TypeError, OverflowError):
                raise ConfigSnapshotError(
                    'Configuration is invalid. Preserve the file and restore '
                    'a valid backup before starting uploads.'
                ) from None

    def save(self, config: WatcherConfig) -> None:
        """
        Persist configuration atomically.

        Preserve fields outside WatcherConfig and compare the complete prior
        snapshot before publishing. This does not exclude unrelated writers or
        establish directory durability after a power failure.

        Args:
            config (WatcherConfig): Configuration object to persist.

        Raises:
            OSError: Propagated when the file cannot be written.

        Returns:
            None: Persist configuration atomically.
        """
        with self._lock:
            if self._mode_transaction_active:
                raise ConfigSnapshotError('Configuration mode transaction is active.')
            fresh_owner = self._snapshot is None
            if self._snapshot is None:
                self.path.parent.mkdir(parents=True, exist_ok=True)
                self._snapshot = self._snapshot_store.inspect()
            if (config._snapshot_token is not self._revision
                    and not (fresh_owner and self._snapshot.state == 'missing'
                             and config._snapshot_token is None)):
                raise ConfigSnapshotError('Configuration selection is stale.')
            if fresh_owner and self._snapshot.state == 'missing':
                # Retain this attempt's generation if staging fails. A retry
                # still has to match the original missing snapshot on disk.
                config._snapshot_token = self._revision
            self._snapshot = self._snapshot_store.save(
                self._snapshot, config.to_dict(),
            )
            self._revision = object()
            config._snapshot_token = self._revision

    def mode_port(self, config: WatcherConfig) -> "_ConfigModePort":
        """Bind mode transactions to the current host-owned config object.

        Args:
            config (WatcherConfig): Exact live configuration selected by the host.

        Returns:
            _ConfigModePort: Port retaining the config object and its owner.
        """
        with self._lock:
            if (str(self.path) != str(self._snapshot_store.path)
                    or str(self.path) != str(Path(self._mode_raw_path))):
                raise ConfigSnapshotError('Configuration path changed.')
            return _ConfigModePort(self, config, str(self.path), self._mode_raw_path)


class _ConfigModePort:
    """Bind a mode transaction factory to one host-owned config generation."""

    def __init__(self, store: ConfigStore, config: WatcherConfig, path: str,
                 raw_path: str) -> None:
        """Retain the exact config object whose token may be refreshed.

        Args:
            store (ConfigStore): Existing owner for the complete config file.
            config (WatcherConfig): Host-owned active config object.
            path (str): Exact selected Path spelling captured by the store.
            raw_path (str): Original string supplied before Path conversion.

        Returns:
            None: Prepares a port without filesystem work.
        """
        self._store = store
        self._config = config
        self._path = path
        self._raw_path = raw_path

    def mode_transaction(self) -> "_ConfigModeTransaction":
        """Prepare one transaction sharing the original ConfigStore lock.

        Returns:
            _ConfigModeTransaction: Unentered transaction bound to this config.
        """
        return _ConfigModeTransaction(self._store, self._config, self._path, self._raw_path)


class _ConfigModeTransaction:
    """Hold one ConfigStore lock while its mode anchor is observed or updated."""

    def __init__(self, store: ConfigStore, config: WatcherConfig, path: str,
                 raw_path: str) -> None:
        """Bind an unentered mode transaction to the existing config owner.

        Args:
            store (ConfigStore): ConfigStore which owns the full document.
            config (WatcherConfig): Exact host-owned config selected for update.
            path (str): Exact Path spelling captured by the mode port.
            raw_path (str): Original spelling captured before Path conversion.

        Returns:
            None: Creates an unentered transaction without filesystem work.
        """
        self._store = store
        self._config = config
        self._path = path
        self._raw_path = raw_path
        self._thread_id: int | None = None

    def __enter__(self) -> "_ConfigModeTransaction":
        """Acquire the config lock for this transaction's calling thread.

        Returns:
            _ConfigModeTransaction: Entered transaction for the current thread.

        Raises:
            ConfigSnapshotError: The transaction is already entered.
        """
        if self._thread_id is not None:
            raise ConfigSnapshotError('Configuration transaction is unavailable.')
        self._store._lock.acquire()
        try:
            if (self._store._mode_transaction_active
                    or self._config._snapshot_token is not self._store._revision
                    or str(self._store.path) != self._path
                    or str(self._store._snapshot_store.path) != self._path
                    or self._store._mode_raw_path != self._raw_path
                    or str(Path(self._raw_path)) != self._path):
                raise ConfigSnapshotError('Configuration selection is stale.')
            self._assert_config_matches_snapshot()
            self._thread_id = threading.get_ident()
            self._store._mode_transaction_active = True
        except BaseException:
            self._store._lock.release()
            raise
        return self

    def _check_owner(self) -> None:
        """Require the same thread that acquired the config-owner lock.

        Returns:
            None: The transaction belongs to the current thread.

        Raises:
            ConfigSnapshotError: The transaction is inactive or belongs elsewhere.
        """
        if (self._thread_id is None or self._thread_id != threading.get_ident()
                or self._config._snapshot_token is not self._store._revision
                or str(self._store.path) != self._path
                or str(self._store._snapshot_store.path) != self._path
                or self._store._mode_raw_path != self._raw_path
                or str(Path(self._raw_path)) != self._path):
            raise ConfigSnapshotError('Configuration transaction is unavailable.')
        self._assert_config_matches_snapshot()

    def _assert_config_matches_snapshot(self) -> None:
        """Require current in-memory settings to match the owned config evidence.

        This comparison performs no migration write and never reports values.

        Returns:
            None: Known fields match the stored document or first-run defaults.

        Raises:
            ConfigSnapshotError: Known settings or snapshot cannot be verified.
        """
        snapshot = self._store._snapshot
        try:
            if snapshot is None:
                raise ValueError
            if snapshot.state == 'missing':
                expected = WatcherConfig()
            elif snapshot.state == 'valid':
                expected = WatcherConfig.from_dict(
                    self._store._snapshot_store.read(snapshot),
                )
            else:
                raise ValueError
            if self._config.to_dict() != expected.to_dict():
                raise ValueError
        except (ValueError, TypeError, OverflowError, OSError):
            raise ConfigSnapshotError('Configuration selection is stale.') from None

    @property
    def config_path(self) -> str:
        """Return the original selected path spelling for mode validation.

        Returns:
            str: Original string spelling, or the already-converted Path
                spelling for a Path caller; not filesystem identity proof.

        Raises:
            ConfigSnapshotError: This transaction is inactive or belongs elsewhere.
        """
        self._check_owner()
        return self._raw_path

    def snapshot(self) -> ConfigSnapshot:
        """Return the exact loaded and still-current complete config snapshot.

        Returns:
            ConfigSnapshot: Existing validated or positively missing config.

        Raises:
            ConfigSnapshotError: The config is changed or unavailable.
        """
        self._check_owner()
        snapshot = self._store._snapshot
        if (snapshot is None or snapshot.state not in ('valid', 'missing')
                or self._store._snapshot_store.inspect() != snapshot):
            raise ConfigSnapshotError('Configuration evidence changed.')
        self._check_owner()
        return snapshot

    def read_mode_anchor(self, snapshot: ConfigSnapshot) -> bytes | None:
        """Read the exact current anchor without treating invalid data as absent.

        Args:
            snapshot (ConfigSnapshot): Exact current snapshot from this owner.

        Returns:
            bytes | None: Canonical mode record, or proven field absence.

        Raises:
            ConfigSnapshotError: Evidence is stale, invalid or unavailable.
        """
        self._check_owner()
        if snapshot is not self._store._snapshot:
            raise ConfigSnapshotError('Configuration evidence changed.')
        payload = self._store._snapshot_store.read_mode_anchor(snapshot)
        self._check_owner()
        return payload

    def write_mode_anchor(self, snapshot: ConfigSnapshot, payload: bytes) -> ConfigSnapshot:
        """Publish a mode anchor and invalidate every older config generation.

        Args:
            snapshot (ConfigSnapshot): Exact current snapshot from this owner.
            payload (bytes): Canonical, validated upload-mode record.

        Returns:
            ConfigSnapshot: Verified new complete configuration observation.

        Raises:
            ConfigSnapshotError: Evidence or publication is unsafe or uncertain.
        """
        self._check_owner()
        if snapshot is not self._store._snapshot:
            raise ConfigSnapshotError('Configuration evidence changed.')
        updated = self._store._snapshot_store.save_mode_anchor(snapshot, payload)
        self._check_owner()
        self._store._snapshot = updated
        self._store._revision = object()
        self._config._snapshot_token = self._store._revision
        return updated

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Release the owner-thread lock without suppressing caller failures.

        Args:
            exc_type (type[BaseException] | None): Raised exception class.
            exc_value (BaseException | None): Raised exception instance.
            traceback (TracebackType | None): Raised exception traceback.

        Returns:
            bool: False so caller failures remain visible.

        Raises:
            ConfigSnapshotError: The transaction belongs to another thread.
        """
        if self._thread_id is None or self._thread_id != threading.get_ident():
            raise ConfigSnapshotError('Configuration transaction is unavailable.')
        self._thread_id = None
        self._store._mode_transaction_active = False
        self._store._lock.release()
        return False


class UploadState:
    """
    Tracks successful uploads, duplicate content, and retry scheduling.

    State is deliberately local and conservative. The server remains the final
    authority, but the helper avoids repeatedly uploading the same file content
    when the user restarts the app or copies a duplicate image into the watched
    folder.
    """

    def __init__(self, path: Path = STATE_PATH) -> None:
        """
        Create and load the upload state store.

        Args:
            path (Path): Path to the JSON state file.

        Returns:
            None: Create and load the upload state store.
        """
        self.path = path
        self.data: Dict[str, Any] = {
            "schema_version": 2,
            "uploaded_paths": {},
            "uploaded_hashes": {},
            "failures": {},
            "jobs": {},
            "recent_events": [],
        }
        self.load()

    def load(self) -> None:
        """
        Load upload state from disk into memory.

        Malformed sections are not discarded during writer admission: a legacy
        state cache may be tolerant for display, but accepted upload history
        cannot be silently replaced after a damaged or concurrent write.
        The baseline is captured independently under the accepted-work owner.

        Returns:
            None: The verified persisted sections are loaded into memory.
        """
        with accepted_work_owner(self.path.parent):
            data = load_upload_state_for_mutation(self.path)
            sections = ("uploaded_paths", "uploaded_hashes", "failures")
            self._disk_baseline = {
                key: copy.deepcopy(data[key]) for key in sections
            }
        self.data["schema_version"] = 2
        for key in sections:
            self.data[key] = data[key]
        self.data["jobs"] = data["jobs"]
        self.data["recent_events"] = data["recent_events"]

    def save(self) -> None:
        """
        Persist upload state atomically.

        Raises:
            OSError: Propagated when the state file cannot be written.
            ValueError: Current watcher history differs from our baseline.
            RuntimeError: The resulting document could not be verified.

        Returns:
            None: Persist upload state atomically.
        """
        confirmed = merge_upload_sections(
            self.path, self.data, self._disk_baseline,
        )
        self._disk_baseline = {
            key: copy.deepcopy(confirmed[key])
            for key in ("uploaded_paths", "uploaded_hashes", "failures")
        }

    def already_uploaded_path(self, path: Path, file_hash: str) -> bool:
        """
        Check whether the exact path and content were already uploaded.

        Args:
            path (Path): Local image path being considered.
            file_hash (str): SHA-256 hash of the current file content.

        Returns:
            bool: True when this path already succeeded with the same content.
        """
        entry = self.data["uploaded_paths"].get(str(path))
        return isinstance(entry, dict) and entry.get("sha256") == file_hash

    def already_uploaded_hash(self, file_hash: str) -> bool:
        """
        Check whether identical content already uploaded under any file name.

        Args:
            file_hash (str): SHA-256 hash of the current file content.

        Returns:
            bool: True when this byte-identical file content is already known.
        """
        return file_hash in self.data["uploaded_hashes"]

    def mark_uploaded(self, path: Path, file_hash: str, size: int, response: Dict[str, Any]) -> None:
        """
        Record a successful upload after the server confirms it.

        Args:
            path (Path): Local file path that was uploaded.
            file_hash (str): SHA-256 hash captured at upload time.
            size (int): File size in bytes captured at upload time.
            response (Dict[str, Any]): JSON object returned by the PHP Gallery
                endpoint.

        Returns:
            None: Record a successful upload after the server confirms it.
        """
        now = time.time()
        record = {
            "sha256": file_hash,
            "size": size,
            "uploaded_at": now,
            "response": response,
        }
        self.data["uploaded_paths"][str(path)] = record
        self.data["uploaded_hashes"][file_hash] = {
            "first_path": str(path),
            "uploaded_at": now,
        }
        # A confirmed upload clears any retry metadata for the same path.
        self.data["failures"].pop(str(path), None)
        self.save()

    def mark_duplicate(self, path: Path, file_hash: str, size: int) -> None:
        """
        Record a skipped file whose content was already uploaded earlier.

        The path-level record prevents the same duplicate file from being
        repeatedly revisited during later scans.

        Args:
            path (Path): Local duplicate file path.
            file_hash (str): SHA-256 hash matching already uploaded content.
            size (int): File size in bytes.

        Returns:
            None: Record a skipped file whose content was already uploaded
                earlier.
        """
        self.data["uploaded_paths"][str(path)] = {
            "sha256": file_hash,
            "size": size,
            "uploaded_at": time.time(),
            "skipped_duplicate": True,
        }
        self.data["failures"].pop(str(path), None)
        self.save()

    def retry_allowed(self, path: Path, file_hash: str) -> bool:
        """
        Determine whether a failed upload may be attempted now.

        Retry delay is keyed by path and content hash. Replacing the file with
        different content immediately clears the wait because it is no longer
        the same failed upload attempt.

        Args:
            path (Path): Local file path being considered.
            file_hash (str): Current SHA-256 hash of the file.

        Returns:
            bool: True when no backoff delay is active.
        """
        failure = self.data["failures"].get(str(path))
        if not isinstance(failure, dict):
            return True
        if failure.get("sha256") != file_hash:
            return True
        return time.time() >= float(failure.get("next_retry_at", 0) or 0)

    def mark_failure(self, path: Path, file_hash: str, message: str) -> None:
        """
        Record a failed upload attempt and schedule a later retry.

        The retry delay uses exponential backoff capped at one hour. This avoids
        hammering the gallery endpoint while still recovering automatically from
        transient network, hosting, or maintenance failures.

        Args:
            path (Path): Local file path that failed to upload.
            file_hash (str): SHA-256 hash of the file at failure time.
            message (str): Human-readable failure reason.

        Returns:
            None: Record a failed upload attempt and schedule a later retry.
        """
        previous = self.data["failures"].get(str(path))
        previous_attempts = int(previous.get("attempts", 0)) if isinstance(previous, dict) and previous.get("sha256") == file_hash else 0
        attempts = previous_attempts + 1
        retry_delay = min(3600, 5 * (2 ** min(attempts - 1, 7)))
        now = time.time()
        self.data["failures"][str(path)] = {
            "sha256": file_hash,
            "attempts": attempts,
            "last_error": message,
            "last_failed_at": now,
            "next_retry_at": now + retry_delay,
        }
        self.save()


# Bound a target recovery file before parsing. Oversize is unknown history, not
# permission to reset the target or retry an upload.
LINKED_RECOVERY_STATE_MAX_BYTES = 16 * 1024 * 1024


def write_linked_upload_state(path: Path, payload: Dict[str, JsonValue]) -> None:
    """Atomically write a linked target's recovery state and require file fsync.

    Args:
        path (Path): Verified destination-owned JSON state file.
        payload (Dict[str, JsonValue]): Existing upload-state object containing only
            path/hash, failure, job and event collections without credentials.

    Returns:
        None: Publishes the complete file only after a successful file flush.

    Raises:
        OSError: Creation, writing, file synchronization or replacement fails.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + '.' + uuid.uuid4().hex + '.tmp')
    descriptor: Optional[int] = None
    try:
        flags = (os.O_WRONLY | os.O_CREAT | os.O_EXCL
                 | getattr(os, 'O_BINARY', 0) | getattr(os, 'O_NOFOLLOW', 0))
        descriptor = os.open(temporary, flags, 0o600)
        with os.fdopen(descriptor, 'w', encoding='utf-8', newline='\n') as handle:
            descriptor = None
            json.dump(payload, handle, indent=2, ensure_ascii=False)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if descriptor is not None:
            os.close(descriptor)
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


class LinkedUploadState(UploadState):
    """Keep linked-gallery retry state separate from all legacy galleries."""

    def __init__(self, target: LinkedUploadTarget) -> None:
        """Bind local retry state to one exact installation, account and gallery.

        Args:
            target (LinkedUploadTarget): Captured in-memory delegated destination.

        Returns:
            None: Loads only the independently scoped, non-secret state file.
        """
        self._binding = target.binding()
        state_path = linked_upload_state_path(CONFIG_DIR, self._binding)
        # Determine existence during the owner-protected load, not from a
        # separate pre-lock path observation that could already be stale.
        self.preexisting = False
        self._history_created = False
        self._disk_baseline: Optional[Dict[str, JsonValue]] = None
        super().__init__(state_path)
        self.data.setdefault('reviewed_upload_attempts', [])

    @staticmethod
    def _valid_reviewed_upload_attempt(value: JsonValue) -> bool:
        """Validate an archived uncertain marker and its one-use approval.

        Args:
            value (JsonValue): One complete retained upload-attempt entry.

        Returns:
            bool: True only for the known uncertainty marker and typed details.
        """
        if (not isinstance(value, dict)
                or set(value) != {'approval_token', 'source_path', 'sha256', 'failure'}
                or not isinstance(value.get('approval_token'), str)
                or re.fullmatch(r'[a-f0-9]{32}', value['approval_token']) is None
                or not isinstance(value.get('source_path'), str)
                or not Path(value['source_path']).is_absolute()
                or not isinstance(value.get('sha256'), str)
                or re.fullmatch(r'[a-f0-9]{64}', value['sha256']) is None
                or not isinstance(value.get('failure'), dict)):
            return False
        failure = value['failure']
        allowed_failure_keys = {
            'sha256', 'uncertain', 'reviewed', 'last_error',
            'last_failed_at', 'approval_token',
        }
        return bool(
            set(failure).issubset(allowed_failure_keys)
            and failure.get('uncertain') is True
            and failure.get('sha256') == value['sha256']
            and ('reviewed' not in failure or failure['reviewed'] is True)
            and ('last_error' not in failure or isinstance(failure['last_error'], str))
            and ('last_failed_at' not in failure
                 or type(failure['last_failed_at']) in (int, float)
                 and math.isfinite(failure['last_failed_at'])
                 and failure['last_failed_at'] >= 0)
            and ('approval_token' not in failure
                 or isinstance(failure['approval_token'], str)
                 and re.fullmatch(r'[a-f0-9]{32}', failure['approval_token']) is not None)
        )

    def _safe_existing_document(self) -> Optional[Dict[str, JsonValue]]:
        """Read a linked state document without repairing ambiguous history.

        Returns:
            Optional[Dict[str, JsonValue]]: Schema version, uploaded path and
                hash maps, failure rows, jobs and recent events, or None before
                any scoped history exists. Reviewed failure rows and archived
                upload attempts retain the uncertain flag and SHA-256.

        Raises:
            ValueError: Stored history is unreadable or lacks required sections.
        """
        self.path = linked_upload_state_path(CONFIG_DIR, self._binding)
        try:
            before = self.path.lstat()
        except FileNotFoundError:
            if self._history_created:
                raise ValueError('Linked upload history disappeared; review before recovery.')
            return None
        except OSError as exc:
            raise ValueError('Linked upload state requires recovery.') from exc
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
                or before.st_dev <= 0 or before.st_ino <= 0
                or before.st_size > LINKED_RECOVERY_STATE_MAX_BYTES):
            raise ValueError('Linked upload state requires recovery.')
        flags = (os.O_RDONLY | getattr(os, 'O_BINARY', 0)
                 | getattr(os, 'O_NOFOLLOW', 0)
                 | getattr(os, 'O_NONBLOCK', 0))
        try:
            with os.fdopen(os.open(self.path, flags), 'rb') as source:
                opened = os.fstat(source.fileno())
                raw = source.read(LINKED_RECOVERY_STATE_MAX_BYTES + 1)
                finished = os.fstat(source.fileno())
            after = self.path.lstat()
            snapshots = (before, opened, finished, after)
            identities = {
                (entry.st_dev, entry.st_ino, entry.st_size, entry.st_mtime_ns)
                for entry in snapshots
            }
            if (len(raw) > LINKED_RECOVERY_STATE_MAX_BYTES
                    or len(raw) != opened.st_size or len(identities) != 1
                    or any(not stat.S_ISREG(entry.st_mode) or entry.st_nlink != 1
                           for entry in snapshots)):
                raise ValueError('Linked upload history changed during read.')
            data = json.loads(
                raw.decode('utf-8'),
                object_pairs_hook=_strict_mode_history_object,
                parse_constant=_reject_mode_history_constant,
                parse_float=_strict_mode_history_float,
            )
        except (OSError, UnicodeError, ValueError, TypeError, OverflowError,
                RecursionError) as exc:
            raise ValueError('Linked upload state requires recovery.') from exc
        if (not isinstance(data, dict) or data.get('schema_version') != 2
                or any(not isinstance(data.get(key), dict) for key in (
                    'uploaded_paths', 'uploaded_hashes', 'failures', 'jobs',
                )) or not isinstance(data.get('recent_events'), list)):
            raise ValueError('Linked upload state requires recovery.')
        data.setdefault('reviewed_upload_attempts', [])
        reviewed_attempts = data['reviewed_upload_attempts']
        if (not isinstance(reviewed_attempts, list)
                or any(not self._valid_reviewed_upload_attempt(entry)
                       for entry in reviewed_attempts)
                or len({entry['approval_token'] for entry in reviewed_attempts})
                != len(reviewed_attempts)):
            raise ValueError('Reviewed upload attempt history is malformed.')
        for value in data['failures'].values():
            if (not isinstance(value, dict)
                    or ('uncertain' in value and type(value['uncertain']) is not bool)
                    or ('reviewed' in value and (
                        value['reviewed'] is not True
                        or value.get('uncertain') is not True
                    ))
                    or (value.get('uncertain') is True and (
                        not isinstance(value.get('sha256'), str)
                        or re.fullmatch(r'[a-f0-9]{64}', value['sha256']) is None))):
                raise ValueError('Linked upload recovery marker is malformed.')
        return data

    def load(self) -> None:
        """Revalidate the scoped state path before reading its local history.

        Returns:
            None: Loads only state belonging to this linked destination.

        Raises:
            ValueError: The scoped state path is unsafe or redirected.
        """
        with accepted_work_owner(CONFIG_DIR):
            data = self._safe_existing_document()
            self.preexisting = data is not None
            if data is not None:
                self._history_created = True
                self.data.update(data)
            # Keep an independent snapshot so in-memory transitions cannot
            # change the state we subsequently compare against fresh disk.
            self._disk_baseline = copy.deepcopy(data)

    def save(self) -> None:
        """Publish a linked target state only from its last owned disk snapshot.

        The shared accepted-work owner includes this fresh read, full-file
        publication and readback. A stale instance must not erase an uncertain
        HTTP outcome or another process's confirmed result. This cannot attest
        to history lost before construction or collectively rolled back files.

        Returns:
            None: Persists unchanged target provenance and new retry metadata.

        Raises:
            ValueError: Scoped history disappeared or changed since our load.
            RuntimeError: Published linked history could not be read back.
            OSError: The state file cannot be written.
        """
        with accepted_work_owner(CONFIG_DIR):
            current = self._safe_existing_document()
            if current != self._disk_baseline:
                raise ValueError(
                    'Linked upload recovery history changed; review before retry.'
                )
            write_linked_upload_state(self.path, self.data)
            self._history_created = True
            confirmed = self._safe_existing_document()
            if confirmed != self.data:
                raise RuntimeError('Published linked recovery state is unconfirmed.')
            self._disk_baseline = copy.deepcopy(confirmed)

    def has_uncertain_hash(self, file_hash: str) -> bool:
        """Check for any unresolved transfer of this content to this gallery.

        Args:
            file_hash (str): Lowercase SHA-256 of a proposed local source.

        Returns:
            bool: True when any recorded path has an ambiguous matching upload.
        """
        return any(
            isinstance(value, dict) and value.get('uncertain') is True
            and value.get('sha256') == file_hash
            for value in self.data['failures'].values()
        )

    def has_unresolved_upload(self) -> bool:
        """Block other transfers until the operator reviews the unknown result.

        Returns:
            bool: True when an uncertain marker lacks explicit review. A
                reviewed original remains uncertain and excluded separately.
        """
        return any(
            isinstance(value, dict) and value.get('uncertain') is True
            and value.get('reviewed') is not True
            for value in self.data['failures'].values()
        )

    def recovery_marker(self) -> tuple[str, str]:
        """Identify the unreviewed marker without opening its recorded path.

        Returns:
            tuple[str, str]: Its validated SHA-256 and local identity digest.

        Raises:
            ValueError: The journal is missing, malformed or ambiguous.
        """
        with accepted_work_owner(CONFIG_DIR):
            data = self._safe_existing_document()
            if data is None:
                raise ValueError('Linked upload recovery marker is unavailable.')
            uncertain = [(path, entry) for path, entry in data['failures'].items()
                         if entry.get('uncertain') is True
                         and entry.get('reviewed') is not True]
            if len(uncertain) != 1:
                raise ValueError('Linked upload recovery marker is unavailable.')
            path, entry = uncertain[0]
            encoded = json.dumps([path, entry], ensure_ascii=True, sort_keys=True,
                                 separators=(',', ':')).encode('ascii')
            return entry['sha256'], hashlib.sha256(encoded).hexdigest()

    def review_uncertainty(self, marker: str) -> None:
        """Record review of one exact marker while retaining its unknown result.

        The caller holds the same gallery lease as Watcher and Import. Review
        permits other originals only; the path and matching content stay
        excluded even across restart. No receipt or retry permission is made.

        Args:
            marker (str): Digest of the unreviewed marker the operator inspected.

        Returns:
            None: Publishes review while preserving the original failure row.

        Raises:
            ValueError: The marker changed or the current history is unsafe.
            OSError: The scoped journal could not be written.
            RuntimeError: Publication could not be confirmed by readback.
        """
        if self.recovery_marker()[1] != marker:
            raise ValueError('The uncertain upload changed during review.')
        pending = [entry for entry in self.data['failures'].values()
                   if entry.get('uncertain') is True
                   and entry.get('reviewed') is not True]
        if len(pending) != 1:
            raise ValueError('The uncertain upload needs manual review.')
        pending[0]['reviewed'] = True
        # save() rereads and compares the complete owned baseline under the
        # publication lock; a newer journal is not replaced by this review.
        self.save()

    def has_uncertain_source(self, path: Path, file_hash: str) -> bool:
        """Exclude retained unknown paths and the same bytes at other paths.

        Args:
            path (Path): Local source being considered for dispatch.
            file_hash (str): Lowercase SHA-256 of its current bytes.

        Returns:
            bool: True for any matching uncertain history, reviewed or not.
        """
        failure = self.data['failures'].get(str(path))
        return ((isinstance(failure, dict) and failure.get('uncertain') is True)
                or self.has_uncertain_hash(file_hash))

    def retry_allowed(self, path: Path, file_hash: str) -> bool:
        """Refuse automatic retry of a transfer with an uncertain result.

        Args:
            path (Path): Local source path being considered.
            file_hash (str): Current lowercase SHA-256 of the source file.

        Returns:
            bool: False for the same ambiguous transfer; otherwise the existing
                bounded retry policy applies.
        """
        if self.has_uncertain_source(path, file_hash):
            return False
        return super().retry_allowed(path, file_hash)

    def approve_uncertain_repeat(
        self, path: Path, file_hash: str, approval_token: str,
    ) -> None:
        """Archive and review one exact unknown attempt for explicit re-upload.

        Args:
            path (Path): Exact retained source path shown to the user.
            file_hash (str): SHA-256 attached to the current uncertain head.
            approval_token (str): One-use token from the durable queue intent.

        Returns:
            None: Retains the original failure in append-only reviewed history.

        Raises:
            ValueError: The marker, token or other unresolved history changed.
            OSError: The linked state cannot be durably published.
            RuntimeError: Publication cannot be confirmed by readback.
        """
        prior = next((entry for entry in self.data['reviewed_upload_attempts']
                      if entry['approval_token'] == approval_token), None)
        if prior is not None:
            if prior['source_path'] != str(path) or prior['sha256'] != file_hash:
                raise ValueError('Photo approval token belongs to another upload.')
            # The token can be present in memory after a failed save. Repair
            # must confirm the archive on disk before releasing the queue head.
            with accepted_work_owner(CONFIG_DIR):
                current = self._safe_existing_document()
                if current != self._disk_baseline or current != self.data:
                    raise ValueError(
                        'Linked upload approval was not durably confirmed; reopen recovery.'
                    )
            return
        pending = [(name, entry) for name, entry in self.data['failures'].items()
                   if isinstance(entry, dict) and entry.get('uncertain') is True
                   and entry.get('reviewed') is not True]
        failure = self.data['failures'].get(str(path))
        if (len(pending) > 1 or pending and pending[0] != (str(path), failure)
                or not isinstance(failure, dict)
                or failure.get('uncertain') is not True
                or failure.get('sha256') != file_hash):
            raise ValueError('The exact uncertain upload cannot be approved.')
        self.data['reviewed_upload_attempts'].append({
            'approval_token': approval_token, 'source_path': str(path),
            'sha256': file_hash, 'failure': copy.deepcopy(failure),
        })
        failure['reviewed'] = True
        self.save()

    def mark_uncertain(
        self, path: Path, file_hash: str, *,
        user_confirmed_repeat: bool = False, approval_token: str = '',
    ) -> None:
        """Persist an ambiguous transfer without scheduling an automatic retry.

        Args:
            path (Path): Source retained for explicit human review.
            file_hash (str): Lowercase SHA-256 of the attempted source.
            user_confirmed_repeat (bool): Whether this exact dispatch consumed
                the user's one-time photo approval.
            approval_token (str): Matching archived one-time approval token.

        Returns:
            None: Preserves an unresolved marker scoped to this exact gallery.

        Raises:
            OSError: The marker cannot be persisted.
            ValueError: The journal is unsafe, or this source or another
                unreviewed marker already owns the transfer boundary, or the
                one-use approval does not match this archived attempt.
            RuntimeError: Publication could not be confirmed by readback.
        """
        existing = self.data['failures'].get(str(path))
        if user_confirmed_repeat:
            archived = any(
                entry['approval_token'] == approval_token
                and entry['source_path'] == str(path)
                and entry['sha256'] == file_hash
                for entry in self.data['reviewed_upload_attempts']
            )
            if (not approval_token or not archived or not isinstance(existing, dict)
                    or existing.get('uncertain') is not True
                    or existing.get('sha256') != file_hash
                    or existing.get('reviewed') is not True
                    or self.has_unresolved_upload()):
                raise ValueError('Explicit photo approval is unavailable or stale.')
            self.data['failures'][str(path)] = {
                'sha256': file_hash, 'uncertain': True,
                'approval_token': approval_token,
                'last_error': 'Upload result uncertain; review the gallery manually.',
                'last_failed_at': time.time(),
            }
            self.save()
            return
        if (self.has_unresolved_upload()
                or self.has_uncertain_source(path, file_hash)) and not (
            isinstance(existing, dict) and existing.get('uncertain') is True
            and existing.get('sha256') == file_hash
            and existing.get('reviewed') is not True
        ):
            raise ValueError('Linked gallery has an unresolved upload; review it before continuing.')
        self.data['failures'][str(path)] = {
            'sha256': file_hash,
            'uncertain': True,
            'last_error': 'Upload result uncertain; review the gallery manually.',
            'last_failed_at': time.time(),
        }
        self.save()

    def release_rejected_attempt(self, path: Path, file_hash: str) -> None:
        """Clear only this live head's proven unsent or pre-storage refused attempt.

        The caller still holds the target lease and has observed actual transport
        completion. Inventory absence and user refresh never call this transition.

        Args:
            path (Path): Exact source of the live rejected attempt.
            file_hash (str): SHA-256 stored in its write-ahead marker.

        Returns:
            None: Removes only the matching unreviewed marker after safe refusal.

        Raises:
            ValueError: The marker changed or belongs to other bytes.
            OSError: Publication of the safe retry evidence fails.
        """
        marker = self.data['failures'].get(str(path))
        if (not isinstance(marker, dict) or marker.get('sha256') != file_hash
                or marker.get('uncertain') is not True or marker.get('reviewed') is True):
            raise ValueError('Rejected attempt does not own its recovery marker.')
        self.data['failures'].pop(str(path))
        self.save()

    def mark_uploaded(
        self, path: Path, file_hash: str, size: int, response: Dict[str, Any],
    ) -> None:
        """Clear only the marker for the exact acknowledged transfer.

        Args:
            path (Path): Local path used for the acknowledged upload.
            file_hash (str): SHA-256 of the exact acknowledged bytes.
            size (int): Source size in bytes.
            response (Dict[str, Any]): Validated acknowledgement with success,
                gallery ID, uploaded and scanned counts, fresh insertion
                evidence, image IDs and filenames, and optional warnings or
                thumbnail results.

        Returns:
            None: Saves the acknowledgement and clears its own marker.

        Raises:
            ValueError: The marker is absent, reviewed or belongs to other bytes.
            OSError: The scoped state could not be persisted.
            RuntimeError: Publication could not be confirmed by readback.
        """
        pending = self.data['failures'].get(str(path))
        if (not isinstance(pending, dict) or pending.get('uncertain') is not True
                or pending.get('sha256') != file_hash
                or pending.get('reviewed') is True):
            raise ValueError('Linked upload acknowledgement does not match its marker.')
        super().mark_uploaded(path, file_hash, size, response)


def probe_linked_upload_outcome(target: LinkedUploadTarget, size: int) -> tuple[str, str]:
    """Observe the recorded hash once through its original linked gallery.

    Args:
        target (LinkedUploadTarget): Exact selected linked gallery and Admin.
        size (int): Source size in bytes explicitly provided by the operator.
    Returns:
        tuple[str, str]: Server observation and the current local marker digest.
    Raises:
        ValueError: The journal or the linked identity changed or is unavailable.
        OSError: The target's recovery evidence cannot be read safely.
        RuntimeError: Gallery observation or exclusive lease acquisition failed.
    """
    lease = LinkedTargetLease(CONFIG_DIR, target.binding())
    lease.acquire()
    try:
        file_hash, marker = LinkedUploadState(target).recovery_marker()
        if (not target.session.matches_issuer(target.issuer)
                or target.session.identity().user_id != target.user_id):
            raise ValueError('Linked identity is unavailable.')
        result = target.session.probe_uncertain_upload(target.gallery_id, file_hash, size)
        if (not target.session.matches_issuer(target.issuer)
                or target.session.identity().user_id != target.user_id
                or LinkedUploadState(target).recovery_marker() != (file_hash, marker)):
            raise ValueError('Linked recovery state changed.')
        return result, marker
    finally:
        lease.release()


def publish_linked_upload_observation(
    target: LinkedUploadTarget, marker: str,
    selected: Callable[[], LinkedUploadTarget | None],
    publish: Callable[[], None] | None = None,
) -> bool:
    """Validate that an observation still belongs to its exact live selection.

    Args:
        target (LinkedUploadTarget): Original gallery and Admin observed.
        marker (str): Digest of the original uncertain journal entry.
        selected (Callable[[], LinkedUploadTarget | None]): Read the GUI's exact
            verified selection under the recovery lease.
        publish (Callable[[], None] | None): Optional caller-thread feedback
            to publish while the selection and journal remain verified.
    Returns:
        bool: True once the same marker is revalidated, otherwise False.
    """
    lease = LinkedTargetLease(CONFIG_DIR, target.binding())
    try:
        lease.acquire()
        if (selected() is not target
                or LinkedUploadState(target).recovery_marker()[1] != marker):
            return False
        if publish is not None:
            publish()
        return True
    except (OSError, ValueError, RuntimeError):
        return False
    finally:
        lease.release()


def review_linked_upload_marker(
    target: LinkedUploadTarget, marker: str | None,
    selected: Callable[[], LinkedUploadTarget | None],
) -> str | None:
    """Inspect or explicitly review one exact marker under the upload lease.

    Args:
        target (LinkedUploadTarget): Original selected gallery and Admin.
        marker (str | None): None to inspect, or the exact confirmed marker.
        selected (Callable[[], LinkedUploadTarget | None]): Verify the currently
            selected live target before accessing its journal.
    Returns:
        str | None: Exact unchanged marker, or None if work or selection changed.
    """
    lease = LinkedTargetLease(CONFIG_DIR, target.binding())
    try:
        lease.acquire()
        if selected() is not target:
            return None
        state = LinkedUploadState(target)
        current = state.recovery_marker()[1]
        if marker is not None:
            if current != marker or selected() is not target:
                return None
            state.review_uncertainty(marker)
        return current
    except (OSError, ValueError, RuntimeError):
        return None
    finally:
        lease.release()




def clamp_int(value: int, minimum: int, maximum: int) -> int:
    """
    Restrict an integer to a configured inclusive range.

    Args:
        value (int): Candidate integer value.
        minimum (int): Lowest accepted value.
        maximum (int): Highest accepted value.

    Returns:
        int: Value restricted to the accepted range.
    """
    return max(minimum, min(maximum, int(value)))



def automatic_thumbnail_worker_count() -> int:
    """
    Choose a conservative multiprocessing thumbnail worker count.

    The automatic value intentionally avoids using every logical CPU. Image
    resizing and WebP encoding also consume disk I/O, memory bandwidth, and RAM.
    On high-core CPUs, using roughly half the logical CPUs is usually faster and
    more stable than launching one encoder process per thread.

    Returns:
        int: Worker process count for thumbnail generation.
    """
    cpu_count = os.cpu_count() or 4
    return clamp_int(max(2, cpu_count // 2), 2, min(MAX_THUMBNAIL_WORKERS, cpu_count))


def automatic_upload_worker_count() -> int:
    """
    Choose a conservative manual upload worker count.

    PHP Gallery serializes mutations for one gallery under a server-side lock.
    Parallel multipart requests therefore do not increase mutation throughput; they
    only occupy additional PHP workers while waiting for the same gallery lock. A
    single network upload worker is intentionally used to protect shared hosting.

    Returns:
        int: Worker thread count for multipart uploads.
    """
    return DEFAULT_UPLOAD_WORKERS


def resolve_thumbnail_worker_count(configured_value: int) -> int:
    """
    Resolve the manual thumbnail worker setting into a real process count.

    Args:
        configured_value (int): Stored UI value, where zero means automatic.

    Returns:
        int: Safe worker process count.
    """
    if int(configured_value) <= 0:
        return automatic_thumbnail_worker_count()
    return clamp_int(int(configured_value), 1, MAX_THUMBNAIL_WORKERS)


def resolve_upload_worker_count(configured_value: int) -> int:
    """
    Resolve the manual upload worker setting into a real thread count.

    Args:
        configured_value (int): Stored UI value, where zero means automatic.

    Returns:
        int: Safe worker thread count.
    """
    if int(configured_value) <= 0:
        return automatic_upload_worker_count()
    return clamp_int(int(configured_value), 1, MAX_UPLOAD_WORKERS)

def normalize_ai_vision_backend(value: str) -> str:
    """
    Return a supported AI vision backend identifier.

    Args:
        value (str): Raw backend value from config or UI.

    Returns:
        str: One of the supported backend identifiers.
    """
    normalized = value.strip().lower()
    if normalized in AI_VISION_BACKEND_CHOICES:
        return normalized
    return AI_VISION_BACKEND_DEFAULT
