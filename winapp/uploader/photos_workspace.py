# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos_workspace.py
# Module Type: Windows Client UI
# Purpose: Show linked galleries and selected photos in one main-window workspace.
# Responsibilities: Compose catalogue, layout, details, and gallery action behavior.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Main-window gallery hierarchy and embedded photo workspace."""

from .photos.tk.workspace.support import (
    BoundCreateReceipt,
    Button,
    Callable,
    CataloguePerformanceWindow,
    CreateDispatch,
    FOLDER_DIVIDER_HIT_HEIGHT,
    FOLDER_DIVIDER_KEY_STEP,
    GALLERY_FOLDER_SHARE_BOUNDS,
    GALLERY_FOLDER_SHARE_SCALE,
    GalleryCacheProbeRow,
    GalleryCatalogueCache,
    GalleryCatalogueSnapshot,
    GalleryCatalogueWriter,
    GalleryFolderShelf,
    GalleryMediaCache,
    GalleryPreloadWorker,
    GalleryThumbnailMemory,
    GlassCard,
    LINKING_CREATE_TITLE_MAX_BYTES,
    LINKING_GALLERY_ID_MAX,
    LinkedGallery,
    LinkedGalleryDetail,
    LinkedGalleryEditSnapshot,
    LinkedGalleryPage,
    LinkedGalleryTree,
    LinkedMediaDetails,
    LinkedMediaGalleryInfo,
    LinkedPhoto,
    LinkedUploadTarget,
    LinkingGalleryEditFailure,
    LinkingManager,
    LinkingMediaThrottled,
    LinkingResponseError,
    LinkingSession,
    METRIC_ICON_SIZE,
    MetricCard,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    NATIVE_MEDIA_MAX_BYTES,
    OrderedDict,
    PHOTOS_CACHE_PAGE_LIMIT,
    PHOTOS_CACHE_REFRESH_MS,
    PHOTOS_CARD_FORMAT_ICON_SIZE,
    PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT,
    PHOTOS_CATALOGUE_CACHE_BATCH_DELAY_MS,
    PHOTOS_CATALOGUE_CACHE_BATCH_SIZE,
    PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS,
    PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS,
    PHOTOS_CATALOGUE_RATE_MAX_RETRIES,
    PHOTOS_CATALOGUE_RATE_RETRY_DEFAULT_SECONDS,
    PHOTOS_CATALOGUE_SPINNER_FRAME_COUNT,
    PHOTOS_CATALOG_POLL_MS,
    PHOTOS_DIAGNOSTIC_MAX_CHARS,
    PHOTOS_DIAGNOSTIC_RECENT_LIMIT,
    PHOTOS_GALLERY_EDITOR_COLUMNS,
    PHOTOS_GALLERY_EDITOR_ROWS,
    PHOTOS_HEADING_STACK_WIDTH,
    PHOTOS_HEADING_TITLE_MINIMUM,
    PHOTOS_INFO_CACHE_ENTRY_LIMIT,
    PHOTOS_INFO_CACHE_FRESH_SECONDS,
    PHOTOS_INFO_CARD_MAX_HEIGHT,
    PHOTOS_INFO_CARD_MAX_WIDTH,
    PHOTOS_INFO_GRID_RESERVE_WIDTH,
    PHOTOS_INFO_PANE_HEADER_INSET,
    PHOTOS_INFO_PANE_MIN_WIDTH,
    PHOTOS_INFO_PANE_ROW_INSET,
    PHOTOS_INFO_PANE_WIDTH,
    PHOTOS_INFO_RESTORE_DELAY_MS,
    PHOTOS_INFO_VIEWER_MINIMUM_WIDTH,
    PHOTOS_INFO_WIDTH_BOUNDS,
    PHOTOS_INFO_WIDTH_SAVE_DELAY_MS,
    PHOTOS_INITIAL_CANDIDATE_LIMIT,
    PHOTOS_LEGACY_NOTICE_TEXT_WIDTH,
    PHOTOS_OBSERVATION_RECENT_LIMIT,
    PHOTOS_PRELOAD_CONTROL_GAP,
    PHOTOS_RECORDING_DOT_SIZE,
    PHOTOS_STORAGE_ACTIVE_POLL_MS,
    PHOTOS_STORAGE_BAR_HEIGHT,
    PHOTOS_STORAGE_BAR_WIDTH,
    PHOTOS_STORAGE_REFRESH_MS,
    PHOTOS_TREE_MARKER_SIZE,
    PHOTOS_TREE_WIDTH,
    PHOTOS_TREE_WIDTH_SAVE_DELAY_MS,
    PHOTO_CARD_FORMATS,
    PreloadProgress,
    RaisedDialogSurface,
    THUMBNAIL_CACHE_LIMIT_CHOICES_GIB,
    THUMBNAIL_DISK_DEFAULT_BYTES,
    ThemeImage,
    TypeAlias,
    _CacheRow,
    _CatalogueCacheFactory,
    _CatalogueCacheResult,
    _CataloguePageResult,
    _CatalogueTreeResult,
    _GalleryResult,
    _InfoGroup,
    _InfoRow,
    _PALETTE,
    _PhotoInfoResult,
    _StorageObservation,
    _log_safe_media_failure,
    attach_inline_tooltip,
    create_smooth_icon,
    current_process_ram_bytes,
    dataclass,
    deque,
    format_safe_photos_failure,
    gallery_edit_text_valid,
    gettext,
    icon_button,
    is_safe_photos_observation,
    logging,
    mount_native_gallery_view,
    normalize_card_format,
    photo_information_groups,
    probe_gallery_cache_status,
    queue,
    replace,
    set_thumbnail_cache_limit_gib,
    threading,
    thumbnail_quality_caption,
    time,
    tk,
    tr,
    ttk,
    vertical_scroll_delta,
)

from .photos.tk.workspace.layout import PhotosWorkspaceLayoutMixin
from .photos.tk.workspace.catalogue import PhotosWorkspaceCatalogueMixin
from .photos.tk.workspace.cache import PhotosWorkspaceCacheMixin
from .photos.tk.workspace.details import PhotosWorkspaceDetailsMixin
from .photos.tk.workspace.actions import PhotosWorkspaceActionsMixin

class PhotosWorkspace(ttk.Frame, PhotosWorkspaceLayoutMixin, PhotosWorkspaceCatalogueMixin, PhotosWorkspaceCacheMixin, PhotosWorkspaceDetailsMixin, PhotosWorkspaceActionsMixin):
    """Own a linked gallery tree, media viewer and photo information pane.

    Args:
        parent (tk.Misc): Main-window page that owns the workspace.
        session_provider (Callable[[], LinkingSession | None]): Current live
            session admitted to gallery listing and photo reads.
        connect (Callable[[], None]): Existing address-connection action.
        language (Callable[[], str]): Current application language callback.
        manager (LinkingManager | None): Shared approval and creation owner.
        format_bytes (Callable[[int], str]): Existing application size formatter.
        manage_link (Callable[[], None] | None): Open established link recovery.
        start_watcher (Callable[[LinkingSession, LinkedGallery], None] | None):
            Start a watcher for one explicitly chosen gallery.
        simbrief_available (Callable[[], bool] | None): Whether SimBrief actions
            are available from the current desktop session.
        simbrief_action (Callable[[], None] | None): Open the host SimBrief view.
        load_tree_state (Callable[[LinkingSession], dict[int, bool]] | None):
            Load saved expansion states for the current site and account.
        save_tree_state (Callable[[LinkingSession, dict[int, bool]], None] | None):
            Persist expansion states for the current site and account.
        load_selected_gallery (Callable[[LinkingSession], int | None] | None):
            Load the saved explicit gallery ID for the current account.
        save_selected_gallery (Callable[[LinkingSession, int], None] | None):
            Save an explicit selection that came from a fresh server row.
        load_card_format (Callable[[LinkingSession, int], str] | None):
            Load a local format for this verified account and gallery.
        save_card_format (Callable[[LinkingSession, int, str], bool] | None):
            Save a local format; True confirms persistence and False refuses it.
        load_photo_order (Callable[[LinkingSession, int], str] | None):
            Load the persistent local order for this account/gallery.
        save_photo_order (Callable[[LinkingSession, int, str], bool] | None):
            Persist only the local order; False leaves a visible warning.
        load_folder_format (Callable[[LinkingSession, int], str] | None):
            Load the independent local child-folder format.
        save_folder_format (Callable[[LinkingSession, int, str], bool] | None):
            Persist the child-folder format only on this computer.
        load_folder_order (Callable[[LinkingSession, int], str] | None):
            Load the independent local child-gallery record order.
        save_folder_order (Callable[[LinkingSession, int, str], bool] | None):
            Persist child-gallery order without any online write.
        load_folder_split (Callable[[LinkingSession, int], int | None] | None):
            Read the local relative divider position for this gallery.
        save_folder_split (Callable[[LinkingSession, int, int | None], bool] | None):
            Save the divider locally; None restores automatic sizing.
        catalogue_cache (_CatalogueCacheFactory | None): Create the account-and-
            scope-bound structure cache, or omit optional local persistence.
        load_tree_width (Callable[[], int | None] | None):
            Load the per-profile Photos tree sash position.
        save_tree_width (Callable[[int], None] | None):
            Persist a settled Photos tree sash position.
        load_info_width (Callable[[], int | None] | None):
            Load the saved inspector width.
        save_info_width (Callable[[int], None] | None):
            Persist a settled inspector width.
        storage_parent (tk.Misc | None): Optional global header for storage.
        storage_surface (tk.Misc | None): Global context-card placement surface.
        diagnostic_callback (Callable[[str], None] | None): Optional receiver
            for sanitized Photos failures.
        canvas_grid_enabled (bool): Frozen process-startup decision selecting
            experimental Canvas rendering; this workspace never re-reads config.
        thumbnail_batch_size (Callable[[], int] | None): Host's live saved size.
    """
    def __init__(
        self,
        parent: tk.Misc,
        session_provider: Callable[[], LinkingSession | None],
        connect: Callable[[], None],
        language: Callable[[], str],
        *,
        manager: LinkingManager | None = None,
        format_bytes: Callable[[int], str] = str,
        manage_link: Callable[[], None] | None = None,
        start_watcher: Callable[[LinkingSession, LinkedGallery], None] | None = None,
        simbrief_available: Callable[[], bool] | None = None,
        simbrief_action: Callable[[], None] | None = None,
        load_tree_state: Callable[[LinkingSession], dict[int, bool]] | None = None,
        save_tree_state: Callable[[LinkingSession, dict[int, bool]], None] | None = None,
        load_selected_gallery: Callable[[LinkingSession], int | None] | None = None,
        save_selected_gallery: Callable[[LinkingSession, int], None] | None = None,
        load_card_format: Callable[[LinkingSession, int], str] | None = None,
        save_card_format: Callable[[LinkingSession, int, str], bool] | None = None,
        load_photo_order: Callable[[LinkingSession, int], str] | None = None,
        save_photo_order: Callable[[LinkingSession, int, str], bool] | None = None,
        load_folder_format: Callable[[LinkingSession, int], str] | None = None,
        save_folder_format: Callable[[LinkingSession, int, str], bool] | None = None,
        load_folder_order: Callable[[LinkingSession, int], str] | None = None,
        save_folder_order: Callable[[LinkingSession, int, str], bool] | None = None,
        load_folder_split: Callable[[LinkingSession, int], int | None] | None = None,
        save_folder_split: Callable[[LinkingSession, int, int | None], bool] | None = None,
        catalogue_cache: _CatalogueCacheFactory | None = None,
        load_tree_width: Callable[[], int | None] | None = None,
        save_tree_width: Callable[[int], None] | None = None,
        load_info_width: Callable[[], int | None] | None = None,
        save_info_width: Callable[[int], None] | None = None,
        storage_parent: tk.Misc | None = None,
        storage_surface: tk.Misc | None = None,
        diagnostic_callback: Callable[[str], None] | None = None,
        canvas_grid_enabled: bool = False,
        thumbnail_batch_size: Callable[[], int] | None = None,
        restart_with_qt: Callable[[], None] | None = None,
    ) -> None:
        """Create the gallery, viewer and optional metadata panes.

        Args:
            parent (tk.Misc): Main-window page that owns the workspace.
            session_provider (Callable[[], LinkingSession | None]): Current
                session lookup owned by LinkingManager.
            connect (Callable[[], None]): Existing address-connection action.
            language (Callable[[], str]): Current application language callback.
            manager (LinkingManager | None): Optional live linking action owner.
            format_bytes (Callable[[int], str]): Formats an indexed byte count.
            manage_link (Callable[[], None] | None): Existing link recovery action.
            start_watcher (Callable[[LinkingSession, LinkedGallery], None] | None):
                Optional explicit gallery-scoped watcher launch callback.
            simbrief_available (Callable[[], bool] | None): Optional host readiness
                check for the SimBrief contextual action.
            simbrief_action (Callable[[], None] | None): Optional host action to
                open SimBrief for the current desktop selection.
            load_tree_state (Callable[[LinkingSession], dict[int, bool]] | None):
                Load per-gallery expansion states for this site and account.
            save_tree_state (Callable[[LinkingSession, dict[int, bool]], None] | None):
                Save per-gallery expansion states for this site and account.
            load_selected_gallery (Callable[[LinkingSession], int | None] | None):
                Optional account-scoped preferred gallery reader.
            save_selected_gallery (Callable[[LinkingSession, int], None] | None):
                Optional account-scoped explicit-selection writer.
            load_card_format (Callable[[LinkingSession, int], str] | None):
                Optional reader for the gallery's local card-format key.
            save_card_format (Callable[[LinkingSession, int, str], bool] | None):
                Optional writer returning True only after local persistence.
            load_photo_order (Callable[[LinkingSession, int], str] | None):
                Load the persistent local order for this account/gallery.
            save_photo_order (Callable[[LinkingSession, int, str], bool] | None):
                Persist only the local order; False leaves a visible warning.
            load_folder_format (Callable[[LinkingSession, int], str] | None):
                Load the independent local child-folder format.
            save_folder_format (Callable[[LinkingSession, int, str], bool] | None):
                Persist the child-folder format only on this computer.
            load_folder_order (Callable[[LinkingSession, int], str] | None):
                Load the independent local child-gallery record order.
            save_folder_order (Callable[[LinkingSession, int, str], bool] | None):
                Persist child-gallery order without any online write.
            load_folder_split (Callable[[LinkingSession, int], int | None] | None):
                Read local divider thousandths, or None for automatic sizing.
            save_folder_split (Callable[[LinkingSession, int, int | None], bool] | None):
                Persist only the local divider; None removes its override.
            catalogue_cache (_CatalogueCacheFactory | None): Optional verified
                identity cache factory; file access
                occurs only on background workers.
            load_tree_width (Callable[[], int | None] | None):
                Optional per-profile Photos tree sash position.
            save_tree_width (Callable[[int], None] | None):
                Optional owner for persisting the Photos tree sash position.
            load_info_width (Callable[[], int | None] | None):
                Optional profile reader for the right inspector width.
            save_info_width (Callable[[int], None] | None):
                Optional profile writer for the right inspector width.
            storage_parent (tk.Misc | None): Global header owner for the storage
                control, or None to use the Photos pane.
            storage_surface (tk.Misc | None): Visible placement surface for its
                contextual card, or None to use the Photos workspace.
            diagnostic_callback (Callable[[str], None] | None): Optional
                thread-safe host receiver for accepted safe Photos failures.
            canvas_grid_enabled (bool): Frozen process-startup renderer choice;
                changing saved configuration cannot alter existing workspaces.
            thumbnail_batch_size (Callable[[], int] | None): Host reader for
                the current steady thumbnail request size.
            restart_with_qt (Callable[[], None] | None): Optional action for
                restarting the app with the Qt interface.

        Returns:
            None: Starts a bounded asynchronous catalogue read when available.
        """
        super().__init__(parent, style="Backdrop.TFrame")
        self._session_provider = session_provider
        self._connect = connect
        self._language = language
        self._canvas_grid_enabled = canvas_grid_enabled is True
        self._thumbnail_batch_size = thumbnail_batch_size
        self._restart_with_qt = restart_with_qt
        self._manager = manager
        self._format_bytes = format_bytes
        self._manage_link = manage_link
        self._start_watcher = start_watcher
        self._simbrief_available = simbrief_available
        self._simbrief_action = simbrief_action
        self._load_tree_state = load_tree_state
        self._save_tree_state = save_tree_state
        self._load_selected_gallery = load_selected_gallery
        self._save_selected_gallery = save_selected_gallery
        self._load_photo_order = load_photo_order
        self._save_photo_order = save_photo_order
        self._photo_order_overrides: dict[int, str] = {}
        self._photo_order_unsaved: set[int] = set()
        self._load_card_format = load_card_format
        self._save_card_format = save_card_format
        self._card_format_overrides: dict[int, str] = {}
        self._card_format_unsaved: set[int] = set()
        self._load_folder_format = load_folder_format
        self._save_folder_format = save_folder_format
        self._load_folder_order = load_folder_order
        self._save_folder_order = save_folder_order
        self._folder_views: dict[int, tuple[str, str]] = {}
        self._folder_unsaved: set[tuple[int, str]] = set()
        self._load_folder_split = load_folder_split
        self._save_folder_split = save_folder_split
        self._folder_splits: dict[int, int | None] = {}
        self._folder_split_unsaved: set[int] = set()
        self._folder_drag: tuple[LinkingSession, int, int] | None = None
        self._photos_present: bool | None = None
        self._folders_visible = False
        self._catalogue_cache_factory = catalogue_cache
        self._load_tree_width = load_tree_width
        self._save_tree_width = save_tree_width
        self._load_info_width = load_info_width
        self._save_info_width = save_info_width
        self._storage_parent = storage_parent
        self._storage_surface = storage_surface
        self._diagnostic_callback = diagnostic_callback
        self._tree_width_save_after: str | None = None
        self._tree_width_restored = False
        self._tree_width_dragging = False
        self._preferred_tree_width: int | None = None
        self._pending_tree_width: int | None = None
        self._info_width_save_after: str | None = None
        self._info_width_restore_after: str | None = None
        self._info_width_restored = False
        self._info_width_dragging = False
        self._preferred_info_width = PHOTOS_INFO_PANE_WIDTH
        if self._load_info_width is not None:
            try:
                info_width = self._load_info_width()
            except (OSError, TypeError, ValueError):
                info_width = None
            if type(info_width) is int and info_width > 0:
                self._preferred_info_width = info_width
        if self._load_tree_width is not None:
            try:
                preferred_width = self._load_tree_width()
            except (OSError, TypeError, ValueError):
                preferred_width = None
            if type(preferred_width) is int and preferred_width > 0:
                self._preferred_tree_width = preferred_width
        self._tree_state: dict[int, bool] = {}
        self._thumbnail_memory = GalleryThumbnailMemory()
        self._cache_state: dict[int, str] = {}
        self._gallery_pins: dict[int, bool] = {}
        self._cache_counts: dict[int, tuple[int, int, bool]] = {}
        self._cache_pending: dict[
            int, tuple[tuple[LinkedPhoto, ...] | None, bool]
        ] = {}
        self._cache_worker: threading.Thread | None = None
        self._cache_results: queue.Queue[
            tuple[int, dict[int, _CacheRow]]
        ] = queue.Queue()
        self._cache_after: str | None = None
        self._preload_worker: GalleryPreloadWorker | None = None
        self._preload_generation = 0
        self._preload_last: PreloadProgress | None = None
        self._preload_status_dismissed = False
        self._preload_unpin = False
        self._preload_foreground = threading.Event()
        self._preload_foreground_reason = ''
        self._upload_pipeline_waiting = False
        self._preload_activity_after: str | None = None
        self._preload_activity_index = 0
        self._storage_after: str | None = None
        self._storage_activity_after: str | None = None
        self._storage_activity_index = 0
        self._storage_refresh_requested = False
        self._storage_refresh_waiting = False
        self._storage_worker_requested = False
        self._storage_preload_witness: tuple[int, int, int, int, str] | None = None
        self._storage_cancel = threading.Event()
        self._storage_worker: threading.Thread | None = None
        self._storage_results: queue.Queue[_StorageObservation] = queue.Queue(maxsize=1)
        self._storage_snapshot = (None, None, None, False, None)
        self._retained_storage_snapshot = (None, None, None, None, None, False)
        self._storage_ram: int | None = None
        self._storage_narrow = False
        self._cache_context_gallery: int | None = None
        self._cache_context_status: tk.StringVar | None = None
        self._cache_pin_var: tk.BooleanVar | None = None
        self._cache_pin_button: ttk.Checkbutton | None = None
        self._context_card: GlassCard | None = None
        self._context_origin: tk.Misc | None = None
        self._context_surface: tk.Misc = self
        self._context_xy = (0, 0)
        self._context_bindings: list[tuple[str, str]] = []
        self._context_bindtag = "PhotosContext" + str(id(self))
        self._context_tagged_widgets: list[tk.Misc] = []
        self._context_click_binding: str | None = None
        self._context_focus_binding: str | None = None
        self._context_focus_check_id: str | None = None
        self._context_results: queue.Queue[
            tuple[int | None, Callable[[], None]]
        ] = queue.Queue()
        self._context_busy = False
        self._gallery_edit_wait_id: str | None = None
        self._photo_info_cache: OrderedDict[
            tuple[int, int, int], tuple[float, LinkedMediaDetails]
        ] = OrderedDict()
        self._inspector_generation = 0
        self._inspector_cancel: threading.Event | None = None
        self._inspector_worker: threading.Thread | None = None
        self._inspector_pending: tuple[
            LinkingSession, int, LinkedPhoto, int,
        ] | None = None
        self._inspector_results: queue.Queue[_PhotoInfoResult] = queue.Queue()
        self._photo_inspector_visible = False
        self._probe_cancel: threading.Event | None = None
        self._create_dispatch: CreateDispatch | None = None
        self._session: LinkingSession | None = None
        self._photo_diagnostic_lock = threading.Lock()
        self._photo_diagnostic_latest: str | None = None
        self._photo_diagnostic_recent: deque[str] = deque(
            maxlen=PHOTOS_DIAGNOSTIC_RECENT_LIMIT,
        )
        self._photo_observation_recent: deque[str] = deque(
            maxlen=PHOTOS_OBSERVATION_RECENT_LIMIT,
        )
        self._cancel = threading.Event()
        self._generation = 0
        self._worker: threading.Thread | None = None
        self._events: queue.Queue[_GalleryResult] = queue.Queue()
        self._catalogue_cache_snapshot: GalleryCatalogueSnapshot | None = None
        self._catalogue_cache: GalleryCatalogueCache | None = None
        self._catalogue_performance = CataloguePerformanceWindow()
        self._catalogue_generation = 0
        self._viewer_viewport_pass_generation: int | None = None
        self._catalogue_cancel = threading.Event()
        self._catalogue_cache_ids: set[int] = set()
        self._fresh_catalogue_ids: set[int] = set()
        self._cached_mount_rows: tuple[LinkedGallery, ...] = ()
        self._cached_mount_index = 0
        self._cached_mount_after: str | None = None
        self._complete_catalogue_tree: LinkedGalleryTree | None = None
        self._preferred_gallery_id: int | None = None
        self._user_selected_gallery = False
        self._selection_saved = False
        self._catalogue_cache_writer = GalleryCatalogueWriter()
        self._poll_id: str | None = None
        self._catalogue_after: str | None = None
        self._catalogue_spinner_after_id: str | None = None
        self._catalogue_spinner_frames: tuple[ThemeImage, ...] = ()
        self._catalogue_spinner_index = 0
        self._pending_cursor: int | None = None
        self._has_pending_page = False
        self._next_after: int | None = None
        self._pages_this_pass = 0
        self._catalogue_rate_retries = 0
        self._catalogue_loading = False
        self._galleries: dict[int, LinkedGallery] = {}
        self._children_by_parent: dict[int, set[int]] = {}
        self._selected_gallery_id: int | None = None
        self._implicit_pending = False
        self._implicit_attempted: set[int] = set()
        self._initial_attempt_count = 0
        self._catalogue_paused_for_preview = False
        self._viewer: ttk.Frame | None = None
        self._destroyed = False
        self._rendering_tree = False
        self._expand_new_nodes = False
        self._watcher_target: LinkedUploadTarget | None = None
        self._watcher_pulse = False
        self._recording_rows: set[str] = set()
        self._recording_images: dict[tuple[bool, bool], ThemeImage] = {}
        self._tree_marker_images: dict[
            tuple[str, bool, bool, bool, bool], ThemeImage
        ] = {}

        self.rowconfigure(0, weight=1)
        self.columnconfigure(0, weight=1)
        self._build_widgets()
        self.after_idle(self._restore_tree_width)
        self.after_idle(self._restore_info_width)
        self.bind(
            "<Map>",
            lambda _event: self.after_idle(self._restore_tree_width),
            add="+",
        )
        self.panes.bind(
            "<Map>",
            lambda _event: self.after_idle(self._restore_tree_width),
            add="+",
        )
        self.panes.bind(
            "<Map>",
            lambda _event: self.after_idle(self._restore_info_width),
            add="+",
        )
        self.panes.bind("<Unmap>", self._on_panes_unmap, add="+")
        self.panes.bind("<Configure>", lambda _event: self.after_idle(
            self._restore_info_width,
        ), add="+")
        self.panes.bind(
            "<ButtonPress-1>", self._begin_tree_width_drag, add="+",
        )
        self.panes.bind(
            "<ButtonPress-1>", self._begin_info_width_drag, add="+",
        )
        self.panes.bind(
            "<ButtonRelease-1>", self._schedule_tree_width_save, add="+",
        )
        self.panes.bind(
            "<ButtonRelease-1>", self._schedule_info_width_save, add="+",
        )
        self.panes.bind(
            "<KeyRelease>", self._schedule_tree_width_save, add="+",
        )
        self.panes.bind(
            "<KeyRelease>", self._schedule_info_width_save, add="+",
        )
        self.bind("<Destroy>", self._on_destroy, add="+")
        self.bind("<Unmap>", lambda _event: self._dismiss_context(restore=False), add="+")
        self.bind(
            "<Configure>", lambda _event: self._place_context(self._context_card),
            add="+",
        )
        self._show_storage_snapshot()
        self._poll_storage()
        self.refresh_session()
