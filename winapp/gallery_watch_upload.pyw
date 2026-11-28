# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Watch a local folder and upload stable images to PHP Gallery.
# Responsibilities:
#   - Coordinate local observation, multipart transport and retry state while PHP owns authorization and storage.
#   - Route one explicitly approved uncertain photo through ordinary FIFO and duplicate checks.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/gallery_watch_upload.pyw
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
PHP Gallery watched-folder uploader.

This Windows companion app watches one local folder and uploads new image
files to PHP Gallery through a gallery-scoped API key. The Python side is
intentionally thin: it observes files, waits until each file is stable, sends
an HTTP multipart upload request, and records local retry state.

Gallery-side validation, permissions, storage decisions, database indexing,
thumbnail generation, and any future business rules remain owned by PHP
Gallery. Keeping those rules server-side prevents this helper from becoming a
second, divergent implementation of upload behavior.
"""

import json
import multiprocessing
import sys
from pathlib import Path

if __name__ == "__main__":
    multiprocessing.freeze_support()
    if len(sys.argv) == 3 and sys.argv[1] == "--runtime-info":
        from uploader.runtime_check import runtime_info

        # Exit before configuration/logging/Tk. The builder owns this fresh
        # staging path and requires both successful exit and its exact report.
        try:
            Path(sys.argv[2]).write_text(
                json.dumps(runtime_info()), encoding="utf-8"
            )
        except (OSError, RuntimeError) as exc:
            if sys.stderr is not None:
                print("Runtime check failed: " + str(exc), file=sys.stderr)
            raise SystemExit(1)
        raise SystemExit(0)
    if sys.argv[1:] == ["--self-update-smoke"]:
        try:
            from uploader.update_helper import WindowsBackend
            expected_version = (Path(__file__).resolve().parent / "VERSION").read_text(encoding="utf-8").strip()
            if WindowsBackend().version(sys.executable) != expected_version:
                raise RuntimeError("Frozen executable version does not match bundled VERSION")
        except Exception as exc:
            # Windowed builds may have no stderr; the exit code remains authoritative.
            if sys.stderr is not None:
                print("Frozen update smoke failed: " + str(exc)[:500], file=sys.stderr)
            raise SystemExit(1)
        raise SystemExit(0)
    if sys.argv[1:] == ["--version"]:
        print((Path(__file__).resolve().parent / "VERSION").read_text(encoding="utf-8").strip())
        raise SystemExit(0)
    if len(sys.argv) >= 2 and sys.argv[1] == "--self-update-helper":
        if len(sys.argv) != 3:
            raise SystemExit(2)
        from uploader.update_helper import main as update_helper_main
        raise SystemExit(update_helper_main(sys.argv[2]))

# Stable executable compatibility facade. Implementations live in their owners.
from uploader.launcher import (
    LAUNCHER_PATH,
    _launch_desktop,
    _start_qt_supervisor,
    desktop_import_actions,
    main,
    parse_args,
    run_once,
    setup_logging,
)
from uploader.http_transport import (
    DEFAULT_INVENTORY_REFRESH_SECONDS,
    DEFAULT_TIMEOUT_SECONDS,
    LINKED_INVENTORY_BATCH_SIZE,
    GalleryKeyRedirectError,
    LegacyUploadResponseError,
    LocalThumbnail,
    UploadPreparationError,
    inventory_candidate,
    multipart_field_part,
    multipart_file_part,
    multipart_upload,
    normalize_upload_url,
    post_binary_to_file,
    post_json,
    post_json_resolved,
    revoke_upload_key,
    sha256_file,
    upload_endpoint_candidates,
    upload_failure_needs_inventory_reconciliation,
)
import uploader.http_transport as http_transport
from uploader.watcher import runtime, state as watcher_state
from uploader.watcher.runtime import (
    APP_DIR,
    APP_VERSION,
    ASSETS_DIR,
    CORE_REQUIREMENTS_PATH,
    REQUIREMENTS_PATH,
    TRAY_ICON_ICO_PATH,
    TRAY_ICON_PNG_PATH,
    filedialog,
    Image,
    ImageDraw,
    ImageOps,
    ImageStat,
    messagebox,
    pystray,
    tk,
    ttk,
)
from uploader.watcher.state import (
    AI_ANALYZER_TIMEOUT_SECONDS,
    AI_DETECTION_RANGE,
    AI_HEARTBEAT_MIN_SECONDS,
    AI_MODEL_NAME_DEFAULT,
    AI_MODEL_VERSION_DEFAULT,
    AI_OLLAMA_MODEL_DEFAULT,
    AI_OLLAMA_URL_DEFAULT,
    AI_SEMANTIC_PROMPT_DEFAULT,
    AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT,
    AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT,
    AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT,
    AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT,
    AI_VISION_BACKEND_CHOICES,
    AI_VISION_BACKEND_DEFAULT,
    AI_WORKER_MAX_LEASE_SECONDS,
    AI_WORKER_MIN_LEASE_SECONDS,
    AI_WORKER_MIN_POLL_SECONDS,
    APP_DISPLAY_NAME,
    APP_NAME,
    CapturedWatcherConfig,
    CONFIG_DIR,
    CONFIG_PATH,
    ConfigStore,
    DEFAULT_AI_WORKER_LEASE_SECONDS,
    DEFAULT_AI_WORKER_POLL_SECONDS,
    DEFAULT_INTERVAL_SECONDS,
    DEFAULT_STABLE_SECONDS,
    DEFAULT_THUMBNAIL_WORKERS,
    DEFAULT_UPLOAD_WORKERS,
    LEGACY_WATCH_RETRY_BASE_SECONDS,
    LEGACY_WATCH_RETRY_CAP_SECONDS,
    LEGACY_WATCH_RETRY_EXPONENT_MAX,
    LINKED_RECOVERY_STATE_MAX_BYTES,
    LOG_PATH,
    MIN_INTERVAL_SECONDS,
    MIN_INVENTORY_REFRESH_SECONDS,
    MIN_STABLE_SECONDS,
    STATE_PATH,
    STAGING_ROOT,
    STAGING_RETENTION_RANGE,
    SUPPORTED_SUFFIXES,
    THUMBNAIL_SIZES,
    WATCHER_RECORDING_PULSE_MS,
    WATCHER_START_ACTIVITY_MS,
    ZIP_ENTRY_RANGE,
    ZIP_EXPANSION_RANGE,
    ZIP_FILE_RANGE,
    ZIP_TOTAL_RANGE,
    LinkedUploadState,
    UploadState,
    WatcherConfig,
    _ConfigModePort,
    _ConfigModeTransaction,
    capture_worker_config,
    clamp_int,
    normalize_ai_vision_backend,
    probe_linked_upload_outcome,
    publish_linked_upload_observation,
    resolve_thumbnail_worker_count,
    resolve_upload_worker_count,
    review_linked_upload_marker,
    trim_path,
    write_linked_upload_state,
)
from uploader.watcher.workers.watcher import (
    WATCHER_CONTINUATION_ADMISSION_TIMEOUT_SECONDS,
    WATCHER_REPARSE_POINT_ATTRIBUTE,
    WATCHER_UPDATE_PAUSE_POLL_SECONDS,
    WATCH_RECOVERY_PREVIEW_PAGE_LIMIT,
    WATCH_RECOVERY_PREVIEW_TIME_LIMIT_SECONDS,
    WatcherThread,
    create_resumed_watch_worker,
    watch_recovery_owner_held,
)
from uploader.watcher.workers.common import FileStabilityTracker
from uploader.watcher.workers.thumbnails import (
    generate_linked_thumbnails,
    generate_local_thumbnails,
    image_has_alpha,
    prepare_jpeg_image,
    prepare_webp_image,
)
from uploader.ai.image_analysis import AIImageAnalyzer
from uploader.ai.worker import (
    AIAnalysisWorkerThread,
    ai_worker_id,
    create_ai_worker,
    enter_background_thread_mode,
    extension_for_content_type,
)
from uploader.ai.heartbeat import PeriodicAIHeartbeat
from uploader.ai import dependencies as ai_dependencies
from uploader.imports.manual_upload import ManualUploadThread, create_import_worker
from uploader.imports.preflight import ImportPreflightThread
from uploader.imports.policy import (
    WATCHER_IMPORT_TREE_ROW_LIMIT,
    protected_staging_job_ids,
)
from uploader.watcher.desktop import (
    CAPTURE_DRAIN_POLL_SECONDS,
    CAPTURE_EVENT_CAPACITY,
    DesktopCapture,
)
from uploader.watcher.configuration import DesktopConfiguration
from uploader.watcher.ui.app import WatcherApp
from uploader.watcher.event_policy import (
    WATCHER_EVENT_BATCH_BUDGET_MS,
    WATCHER_EVENT_BATCH_LIMIT,
    WATCHER_EVENT_CONTINUATION_MS,
    WATCHER_EVENT_IDLE_POLL_MS,
)
from uploader.watcher.ui.support import (
    WATCHER_SETTINGS_SCREEN_MARGIN,
    WATCHER_SETTINGS_FALLBACK_SIZE,
    ScrollableFrame,
    _ImportViewProjection,
    _TreeRowSnapshot,
    _layout_display_bounds,
    activity_filter_labels,
    activity_label,
    format_worker_choice,
    operational_activity_caption,
    render_tray_icon,
    selected_image_filetypes,
    watch_queue_state_caption,
    worker_choice_values,
    parse_worker_choice,
    _publish_watcher_text,
    _publish_watcher_value,
)
from uploader.watcher.update_policy import (
    UPDATE_CHECKPOINT_TIMEOUT_SECONDS,
    UPDATE_CONTINUATION_LINK_SECONDS,
    UPDATE_CONTINUATION_POLL_MS,
    UPDATE_CONTINUATION_TARGET_RETRY_MS,
    UPDATE_RESUME_EXCEPTION_CLASS_MAX_CHARS,
)
from uploader.discovery import WatchDirectorySignal
from uploader.watcher.queue import WatchQueueItem, WatchQueueStore, WatchSource, WatchRecoveryError
from uploader.watcher.recovery import (
    PREVIEW_MAX_BYTES,
    WatchRecoveryCoordinator,
    WatchRecoveryRemote,
    WatchRecoverySnapshot,
)
from uploader.watcher.recovery_ui import WatchRecoveryReviewDialog
from uploader.watcher.profile import WatcherProfile
from uploader.watcher.capture_session import (
    CaptureDecision,
    CaptureEvents,
    CaptureReview,
    CaptureStatus,
    RetainedQueueSummary,
    WatchFolderInspection,
    inspect_retained_queue,
    inspect_watch_folder,
    persist_retained_capture_closure,
    prepare_capture_recording,
    verify_unfrozen_capture,
    watcher_interrupt_available,
)
from uploader.config import ai_backend_labels
from uploader.game_presence import GamePresence
from uploader.desktop_connection import DependencyActions
from uploader.flight_jobs import pending_sessions
from uploader.ui_layout import WATCHER_WINDOW_GEOMETRY, WATCHER_WINDOW_MINIMUM
from uploader.ui_theme import Button, GlassCard
from uploader.mode_coordinator import (
    CoordinatedWork,
    GuiModeOwner,
    ModeConfigPort,
    ModeCoordinator,
    ModeCoordinatorError,
    ModeView,
)
from uploader.mode_admission import LEGACY_UPLOAD_STATES, WorkKind
from uploader.models import (
    ImportItem,
    ImportJob,
    ImportPlan,
    ITEM_ACTIVE_STATES,
    LEGACY_UPLOAD_UNCERTAIN_REASON,
    LINKED_UPLOAD_UNCERTAIN_REASON,
    RECOVERABLE_ITEM_STATES,
    JsonValue,
)
from uploader.state_store import ActivityWriter, JobStateStore, atomic_write_json

if __name__ == "__main__":
    raise SystemExit(main())
