# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/app.py
# Module Type: Windows Watcher Application Controller
# Purpose: Compose the Watcher desktop lifecycle with focused feature UI owners.
# Responsibilities:
#   - Initialize the shared Tk application and its long-lived controllers.
#   - Compose shell, Watcher, Photos, settings, Import and event domains.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Compose the executable Watcher application from explicit UI owners."""

from collections import deque
from typing import Callable, Optional, Tuple
from uploader.config import (
    ai_backend_labels,
    load_thumbnail_batch_size,
    load_ui_language,
    resolve_canvas_gallery_renderer_enabled,
)
from uploader.credential_store import WindowsUploadModeStore
from uploader.game_presence import GamePresence
from uploader.http_transport import (
    normalize_upload_url,
    revoke_upload_key,
)
from uploader.localization import (
    configure_language,
    gettext,
    gettext_noop,
)
from uploader.macos_mode import MacUploadModeStore
from uploader.media import detect_suffix_capabilities
from uploader.mode_coordinator import GuiModeOwner
from uploader.photos_workspace import PHOTOS_TREE_WIDTH
from uploader.simconnect_time import UtcSyncManager
from uploader.state_store import (
    ActivityWriter,
    JobStateStore,
)
from uploader.ui_layout import (
    ViewLayoutState,
    WATCHER_WINDOW_GEOMETRY,
    WATCHER_WINDOW_MINIMUM,
    _clamp_ui_layout_state,
    _read_ui_layout,
)
from uploader.watcher import runtime
from uploader.watcher.profile import WatcherProfile
from uploader.watcher.state import normalize_ai_vision_backend
from uploader.windows_display import prepare_windows_display
from uploader.windows_startup import (
    ACTIVATION_POLL_MS,
    STARTUP_TRAY_TIMEOUT_SECONDS,
    synchronize_startup,
)
import queue
import sys
import threading
import time
from uploader import http_transport
from .. import runtime
from .. import state as watcher_state
from ...flight_ui import FlightUI
from ...linking.ui.manager import LinkingManager
from ...ui_shell import initialize_shell
from ...update_ui import UpdateController
from ..event_policy import WATCHER_EVENT_IDLE_POLL_MS
from .event_delivery import EventDeliveryMixin
from .imports_activity import ImportActivityMixin
from .photos_connection import PhotosConnectionMixin
from .settings_lifecycle import SettingsLifecycleMixin
from .event_pump import TkEventPump
from .shell import WatcherShellMixin
from .support import (
    _layout_display_bounds, activity_filter_labels, format_worker_choice,
)
from .watcher import WatcherPageMixin


class WatcherApp(WatcherShellMixin, WatcherPageMixin, PhotosConnectionMixin, SettingsLifecycleMixin, ImportActivityMixin, EventDeliveryMixin):
    """
        Tkinter user interface for the watched-folder uploader.

        The GUI is intentionally small: it collects configuration, starts and stops
        the worker, and mirrors worker events into a visible status log. Upload logic
        stays in WatcherThread so the same behavior can be reused by command-line
        mode.
        """

    def __init__(
            self, *, config_store: Optional[watcher_state.ConfigStore] = None,
            start_minimized: bool = False,
            after_update: Optional[str] = None,
        ) -> None:
            """
            Initialize the Tkinter application and load saved configuration.

            Args:
                config_store (Optional[ConfigStore]): Selected configuration owner;
                    None uses the standard per-user profile.
                start_minimized (bool): Withdraw before painting and start in the
                    tray; restore the window if a usable tray cannot be created.
                after_update (Optional[str]): Exact non-secret helper nonce for an
                    explicitly prepared continuation, or None for ordinary startup.
            Raises:
                RuntimeError: Raised when Tkinter is not available.

            Returns:
                None: Loads the interface language, creates the window and idle
                    coordinators, then starts the read-only game-presence observer.
            """
            self._saved_ui_language = load_ui_language(watcher_state.CONFIG_DIR)
            self._thumbnail_batch_size = load_thumbnail_batch_size(watcher_state.CONFIG_DIR)
            configure_language(self._saved_ui_language)
            if runtime.tk is None or runtime.ttk is None or runtime.filedialog is None or runtime.messagebox is None:
                raise RuntimeError(
                    gettext_noop("Tkinter is not available in this Python installation.")
                )

            # Establish the display context before Tk creates its first window.
            prepare_windows_display()
            self.root = runtime.tk.Tk()
            initialize_shell(self.root)
            self.linking_manager = LinkingManager(
                self.root, self.linked_target_change_refusal, self.linked_target_changed,
                self.probe_linked_uncertain_upload, self.publish_linked_recovery,
                self.saved_linked_mode_selected, self.commit_linked_upload_target,
                self.commit_saved_api_key,
                language=lambda: self.flights.ui_language.get(),
                recovery_review=self.review_linked_uncertainty,
                upload_workspace_ready=self.linked_workspace_ready,
                saved_upload_identity=self.saved_linked_upload_identity,
                saved_api_address=lambda: (
                    self.config.gallery_url if self.config.api_key.strip() else ''
                ),
                diagnostic_callback=lambda message: self.events.put(
                    (
                        "info" if message.endswith("reason=connected") else "warning",
                        "[linking] " + message,
                    ),
                ),
            )
            if start_minimized:
                self.root.withdraw()
            self.root.title(f"{gettext(watcher_state.APP_DISPLAY_NAME)} {runtime.APP_VERSION}")

            self.config_store = config_store or watcher_state.ConfigStore()
            self.config = self.config_store.load()
            self._watcher_profile: WatcherProfile | None = None
            self._watcher_profile_invalid = False
            self._watcher_reset_running = False
            self._watcher_reset_waiting = ''
            self._watcher_reset_latest = ''
            self._watcher_reset_thread: threading.Thread | None = None
            try:
                self._watcher_profile = WatcherProfile.load(watcher_state.CONFIG_DIR / 'flights')
            except OSError:
                self._watcher_profile_invalid = True
            watcher_config = WatcherApp._saved_watcher_config(self)
            # Freeze the effective renderer choice for this process. Settings edits
            # are persisted immediately but take effect only after application restart.
            self._canvas_grid_enabled_at_startup = (
                resolve_canvas_gallery_renderer_enabled(
                    self.config.experimental_canvas_gallery_renderer,
                )
            )
            self._ui_layout_path = watcher_state.CONFIG_DIR / "ui-layout.json"
            display_bounds = _layout_display_bounds(self.root)
            loaded_layout = _read_ui_layout(
                self._ui_layout_path, display_bounds, WATCHER_WINDOW_MINIMUM,
            )
            self._ui_layout_restored = loaded_layout is not None
            default_layout_width, default_layout_height = (
                int(value) for value in WATCHER_WINDOW_GEOMETRY.split("x")
            )
            self._ui_layout_state = loaded_layout or _clamp_ui_layout_state(
                ViewLayoutState(
                    default_layout_width, default_layout_height,
                    display_bounds[0], display_bounds[1], "normal", PHOTOS_TREE_WIDTH,
                ),
                display_bounds,
                WATCHER_WINDOW_MINIMUM,
            )
            self._ui_layout_save_after: Optional[str] = None
            startup_error = ""
            try:
                synchronize_startup(self.config.start_with_windows)
            except OSError as exc:
                startup_error = str(exc)
            self.job_store = JobStateStore(watcher_state.STATE_PATH, self.config.activity_history_limit)
            self.activity_writer = ActivityWriter(self.job_store)
            self.events: "queue.Queue[Any]" = queue.Queue()
            self.mode_owner = GuiModeOwner(
                self.config_store.mode_port(self.config),
                (lambda: MacUploadModeStore(watcher_state.CONFIG_DIR))
                if sys.platform == "darwin" else WindowsUploadModeStore,
            )
            threading.Thread(
                target=self._initialize_gui_mode, name='PHPGalleryModeStartup', daemon=True,
            ).start()
            self.utc_sync = UtcSyncManager(self.emit_utc_activity)
            self.simulator_presence = GamePresence(
                lambda: self.config.simconnect_dll_path, watcher_state.CONFIG_DIR
            )
            self.worker: Optional[WatcherThread] = None
            self._force_finished_workers: set[WatcherThread] = set()
            self._force_finished_operations: set[FinalizationWorker] = set()
            self._force_finish_callbacks: dict[int, list[Callable[[str], None]]] = {}
            self._force_finish_failure_type = ''
            self._watch_recovery_latest = ''
            self._watch_recovery_last_failure = ''
            self._after_update_nonce = after_update
            self._gui_mode_ready = False
            self._update_resume_attempted = False
            self._update_resume_inspecting = False
            self._update_resume_cancelled = False
            self._update_resume_deadline = 0.0
            self._update_resume_generation = 0
            self._update_resume_status = 'No update continuation requested.'
            self._update_checkpoint_ready = False
            self._update_continuation: Optional[WatcherContinuation] = None
            self._resume_continuation: Optional[WatcherContinuation] = None
            self._update_checkpoint_workers = ()
            self._update_context_target: Optional[LinkedUploadTarget] = None
            self._update_context_key_closed = False
            self._update_resume_context_target: Optional[LinkedUploadTarget] = None
            self._update_resume_context_key_closing = False
            self._update_resume_context_key_worker: Optional[threading.Thread] = None
            self._update_resume_preparing = False
            self._ordinary_restart_closing = False
            self._ordinary_restart_prepared = False
            self._ordinary_restart_resuming = False
            self._ordinary_restart_thread: Optional[threading.Thread] = None
            self._finish_resume_attempted_sessions: set[str] = set()
            self._finish_resume_generation = 0
            self._discard_update_resume_intent()
            self._resume_retry_available = False
            self.manual_worker: Optional[ManualUploadThread] = None
            self.preflight_worker: Optional[ImportPreflightThread] = None
            self.ai_worker: Optional[AIAnalysisWorkerThread] = None
            self.ai_worker_stop_requested = False
            self.semantic_ai_install_running = False
            self.semantic_ai_install_button: Optional[Any] = None
            self.connection_test_running = False
            self.api_key_revoke_running = False
            self.api_key_revoke_thread: Optional[threading.Thread] = None
            self._verified_legacy_gallery: Optional[
                Tuple[object, str, str, str]
            ] = None
            self.manual_paths: List[Path] = []
            self.manual_folder: Optional[Path] = None
            self.manual_zip: Optional[Path] = None
            self.manual_plan: Optional[ImportPlan] = None
            self._preflight_approved: Optional[Tuple[str, object, str]] = None
            self.current_job: Optional[ImportJob] = None
            self._live_import_view: _ImportViewProjection | None = None
            self._history_import_view: _ImportViewProjection | None = None
            self._presented_import_views: dict[ImportDetailPane, _ImportViewProjection] = {}
            self._import_presentation_after = 0.0
            self._import_presentation_flushes = 0
            self._import_history_reader: ImportHistoryReader | None = None
            self._import_detail_request: tuple[int, str, Literal["selected", "finished"]] | None = None
            self._import_history_requested = False
            self._recheck_import_history = False
            self._import_history_rows: dict[str, ImportHistorySummary] = {}
            self._import_history_dirty: dict[str, ImportHistorySummary] = {}
            self._import_history_rebuild = False
            self.tray_icon: Optional[Any] = None
            self.tray_thread: Optional[threading.Thread] = None
            self.update_available_version: Optional[str] = None
            self.update_banner: Optional["ttk.Frame"] = None
            self.update_banner_label: Optional["ttk.Label"] = None
            self.update_banner_action: Optional["ttk.Button"] = None
            self.check_updates_button: Optional["ttk.Button"] = None
            self.window_hidden_to_tray = start_minimized
            self.startup_waiting_for_tray = start_minimized
            self.recovery_offer_pending = start_minimized
            self.instance: Optional[WindowsInstance] = None
            self.exiting = False
            self._drain_running = False
            self._event_pump = TkEventPump(self.root)
            self._activity_render_after_id: Optional[str] = None
            self._event_drain_last_count = 0
            self._event_drain_total_count = 0
            self._event_drain_yield_count = 0
            self._event_drain_last_ms = 0.0
            self._event_drain_max_ms = 0.0
            self._activity_drawer_flush_count = 0
            self._activity_drawer_dirty = False
            self._activity_tab_selected = False
            self._import_tab_selected = False
            self._pending_activity_footer: ActivityEvent | None = None
            self._pending_log_lines: deque[tuple[str, str, str]] = deque(
                maxlen=self.job_store.history_limit,
            )
            self._displayed_log_lines = 0
            self.activity_events: List[ActivityEvent] = self.job_store.list_events()
            self.media_capabilities = detect_suffix_capabilities(watcher_state.SUPPORTED_SUFFIXES, runtime.Image)

            self.watched_folder_var = runtime.tk.StringVar(value=watcher_config.watched_folder)
            self.watch_folder_display_var = runtime.tk.StringVar(
                value=watcher_config.watched_folder or "No folder selected",
            )
            self.watched_folder_var.trace_add("write", self.update_watch_folder_display)
            self.gallery_url_var = runtime.tk.StringVar(value=self.config.gallery_url)
            self.api_key_var = runtime.tk.StringVar(value=self.config.api_key)
            self.gallery_url_var.trace_add(
                "write", self._invalidate_verified_legacy_gallery,
            )
            self.api_key_var.trace_add(
                "write", self._invalidate_verified_legacy_gallery,
            )
            self.api_key_visible_var = runtime.tk.BooleanVar(value=False)
            self.api_key_status_var = runtime.tk.StringVar(value=gettext("API key configured") if self.config.api_key.strip() else gettext("API key not configured"))
            self.revoke_blocker_var = runtime.tk.StringVar(value="")
            self.interval_var = runtime.tk.StringVar(value=str(watcher_config.scan_interval_seconds))
            self.stable_var = runtime.tk.StringVar(value=str(watcher_config.stable_seconds))
            self.create_thumbnails_var = runtime.tk.BooleanVar(value=self.config.create_thumbnails)
            self.attach_sim_camera_metadata_var = runtime.tk.BooleanVar(value=watcher_config.attach_sim_camera_metadata)
            self.simconnect_dll_path_var = runtime.tk.StringVar(
                value=self.config.simconnect_dll_path
            )
            self.sync_utc_time_var = runtime.tk.BooleanVar(value=watcher_config.sync_utc_time)
            self.utc_sync_status_var = runtime.tk.StringVar(value=gettext("UTC sync stopped"))
            self.delete_uploaded_files_var = runtime.tk.BooleanVar(value=watcher_config.delete_uploaded_files)
            self.watch_recursive_var = runtime.tk.BooleanVar(value=watcher_config.watch_recursive)
            self.manual_folder_recursive_var = runtime.tk.BooleanVar(value=False)
            self.delete_source_zip_var = runtime.tk.BooleanVar(value=False)
            self.manual_local_thumbnails_var = runtime.tk.BooleanVar(value=self.config.manual_thumbnail_mode == "local")
            self.manual_thumbnail_workers_var = runtime.tk.StringVar(value=format_worker_choice(self.config.manual_thumbnail_workers))
            self.manual_upload_workers_var = runtime.tk.StringVar(value=format_worker_choice(self.config.manual_upload_workers))
            self.inventory_refresh_var = runtime.tk.StringVar(value=f"{self.config.inventory_refresh_seconds:g}")
            self.archive_max_entries_var = runtime.tk.StringVar(value=str(self.config.archive_max_entries))
            self.archive_max_total_gib_var = runtime.tk.StringVar(value=f"{self.config.archive_max_uncompressed_bytes / (1024 ** 3):g}")
            self.archive_max_file_gib_var = runtime.tk.StringVar(value=f"{self.config.archive_max_file_bytes / (1024 ** 3):g}")
            self.archive_max_ratio_var = runtime.tk.StringVar(value=f"{self.config.archive_max_compression_ratio:g}")
            self.staging_cleanup_hours_var = runtime.tk.StringVar(value=f"{self.config.staging_cleanup_age_hours:g}")
            self.close_to_tray_var = runtime.tk.BooleanVar(value=self.config.close_to_tray)
            self.start_with_windows_var = runtime.tk.BooleanVar(
                value=self.config.start_with_windows,
            )
            self.experimental_canvas_gallery_renderer_var = runtime.tk.BooleanVar(
                value=self.config.experimental_canvas_gallery_renderer,
            )
            self.settings_advanced_visible_var = runtime.tk.BooleanVar(value=False)
            self.ai_worker_enabled_var = runtime.tk.BooleanVar(value=self.config.ai_worker_enabled)
            self.ai_worker_poll_var = runtime.tk.StringVar(value=f"{self.config.ai_worker_poll_seconds:g}")
            self.ai_worker_lease_var = runtime.tk.StringVar(value=str(self.config.ai_worker_lease_seconds))
            self.ai_model_name_var = runtime.tk.StringVar(value=self.config.ai_model_name)
            self.ai_model_version_var = runtime.tk.StringVar(value=self.config.ai_model_version)
            self.ai_external_command_var = runtime.tk.StringVar(value=self.config.ai_external_command)
            backend = normalize_ai_vision_backend(self.config.ai_vision_backend)
            self.ai_vision_backend_var = runtime.tk.StringVar(value=ai_backend_labels()[backend])
            self.ai_transformers_caption_model_var = runtime.tk.StringVar(value=self.config.ai_transformers_caption_model)
            self.ai_transformers_detector_model_var = runtime.tk.StringVar(value=self.config.ai_transformers_detector_model)
            self.ai_transformers_object_labels_var = runtime.tk.StringVar(value=self.config.ai_transformers_object_labels)
            self.ai_transformers_detection_threshold_var = runtime.tk.StringVar(value=f"{self.config.ai_transformers_detection_threshold:g}")
            self.ai_ollama_url_var = runtime.tk.StringVar(value=self.config.ai_ollama_url)
            self.ai_ollama_model_var = runtime.tk.StringVar(value=self.config.ai_ollama_model)
            self.ai_semantic_prompt_var = runtime.tk.StringVar(value=self.config.ai_semantic_prompt)
            self.ai_advanced_visible_var = runtime.tk.BooleanVar(value=False)
            self.ai_advanced_frame = None
            self.settings_advanced_frame = None
            self.thumbnail_runtime_var = runtime.tk.StringVar(value=runtime.thumbnail_runtime_status(for_display=True))
            self.manual_selection_var = runtime.tk.StringVar(value=gettext("Choose files, a folder, or a ZIP archive"))
            self.manual_preflight_var = runtime.tk.StringVar(value=gettext("Select a source to build an import summary. Nothing uploads until you press Start import."))
            self.manual_status_var = runtime.tk.StringVar(value=gettext("Import idle"))
            self.manual_progress_text_var = runtime.tk.StringVar(value=gettext("No active import"))
            self.manual_progress_var = runtime.tk.DoubleVar(value=0.0)
            self.status_var = runtime.tk.StringVar(value=gettext("Watcher stopped"))
            self.watch_ignored_var = runtime.tk.StringVar(value=gettext("Ignored at startup: -"))
            self.watch_last_scan_var = runtime.tk.StringVar(value=gettext("Last scan: -"))
            self.ai_status_var = runtime.tk.StringVar(value=gettext("AI metadata worker idle"))
            self.monitor_state_var = runtime.tk.StringVar(value="Idle")
            self.monitor_detail_var = runtime.tk.StringVar(value=gettext("No background worker is active."))
            self.connection_status_var = runtime.tk.StringVar()
            self.connection_detail_var = runtime.tk.StringVar()
            self._connection_diagnostic: dict[str, str] = {}
            self._publish_connection_text(
                "status", gettext_noop("Not tested") if self.config.api_key.strip() else "",
            )
            self._publish_connection_text(
                "detail", gettext_noop("Save or test the gallery connection in Settings."),
            )
            self.gallery_label_var = runtime.tk.StringVar(value=gettext("Target gallery: unknown"))
            self.watcher_gallery_label_var = runtime.tk.StringVar(value=self.gallery_label_var.get())
            self.watcher_gallery_title_var = runtime.tk.StringVar()
            self.watcher_connection_status_var = runtime.tk.StringVar()
            self.gallery_title_var = runtime.tk.StringVar(value="")
            self.watcher_activity_var = runtime.tk.StringVar(value="Watch: stopped")
            self.watch_queue_status_var = runtime.tk.StringVar(value='')
            self._watch_queue_snapshot: Dict[str, JsonValue] = {}
            self._queue_recovery_pending = False
            self._queue_recovery_finish_requested = False
            self.watcher_header_count_var = runtime.tk.StringVar(value="–")
            self.watcher_header_time_var = runtime.tk.StringVar(value="–:–")
            self._watcher_queued_count: Optional[int] = None
            self._watcher_queue_worker: Optional[WatcherThread] = None
            self._watcher_last_scan_at: Optional[float] = None
            self.import_activity_var = runtime.tk.StringVar(value="Import: idle")
            self.ai_activity_var = runtime.tk.StringVar(value="AI: stopped")
            self.activity_filter_var = runtime.tk.StringVar(value=activity_filter_labels()["all"])
            self.import_details_visible_var = runtime.tk.BooleanVar(value=False)
            self.activity_job_summary_var = runtime.tk.StringVar(value=gettext("No import job selected"))
            self.latest_activity_var = runtime.tk.StringVar(value=gettext("Ready when you are"))
            self.monitor_state = "disabled"
            self.monitor_detail = "No background worker is active."
            self.log_tags_ready = False
            self.watcher_action_button: Optional["ttk.Button"] = None
            self.watch_recovery_action_button: Optional["ttk.Button"] = None
            self._contextual_watch_target: LinkedUploadTarget | None = None
            self._contextual_watch_is_flight = False
            self._watcher_key_closing = False
            self._watcher_recording_pulse = False
            self._watcher_recording_after: Optional[str] = None
            self._watcher_recording_target: LinkedUploadTarget | None = None
            self._watcher_recording_active = False
            self._watch_start_pending = False
            self.watch_start_progress: Optional[runtime.ttk.Progressbar] = None
            self.latest_activity_label: Optional["ttk.Label"] = None

            # Uncertain linked uploads remain blocked rather than recoverable, but
            # their staged ZIP entries must not be treated as disposable on restart.
            # No rollback-resistant witness currently proves that a staging job
            # missing from a valid local snapshot was never accepted. Age and a
            # legacy tolerant job listing are therefore insufficient to delete it.
            # Keep staged originals intact until durable admission/recovery exists.
            removed_staging: List[str] = []

            self.updates = UpdateController(self, runtime.APP_VERSION)
            self.flights = FlightUI(
                self, watcher_state.CONFIG_DIR / "flights", http_transport.normalize_upload_url,
                http_transport.revoke_upload_key, self.resume_flight_photos,
            )
            self.build_ui()
            if self._watcher_profile is not None and self._watcher_profile.pending:
                self.root.after_idle(self._begin_watcher_reset)
            self.simulator_presence.start()
            self.configure_window_icon()
            self.start_tray_icon()
            self.startup_tray_deadline = (
                time.monotonic() + STARTUP_TRAY_TIMEOUT_SECONDS
            )
            self.root.protocol("WM_DELETE_WINDOW", self.request_window_close)
            self.root.bind("<Unmap>", self.handle_window_unmap, add="+")
            self.root.bind("<Configure>", self._schedule_ui_layout_save, add="+")
            self._schedule_event_drain(WATCHER_EVENT_IDLE_POLL_MS)
            if start_minimized:
                self.root.after(ACTIVATION_POLL_MS, self.complete_startup)
            else:
                self.root.after(350, self.offer_recoverable_jobs)
            if startup_error:
                self.write_log(
                    "Windows startup registration failed: " + startup_error,
                    "warning",
                )
            if removed_staging:
                self.write_log(f"Cleaned {len(removed_staging)} stale staging directorie(s).", "system")
