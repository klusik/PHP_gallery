# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/native_gallery_viewer.py
# Module Type: Windows Client UI
# Purpose: Display bearer-authorized gallery media inside the Photos workspace.
# Responsibilities: Compose bounded viewer behavior over one retained Tk frame.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native, bounded viewer for media returned by a linked gallery session."""

from .photos.tk.viewer.support import (
    Button,
    Callable,
    CanvasGalleryGrid,
    CanvasTileProxy,
    GALLERY_TILE_BORDER_INSET,
    GalleryLoadProgress,
    GalleryThumbnailMemory,
    GalleryViewOrder,
    LIGHTBOX_ZOOM_STEP,
    LINKING_CANCELLATION_POLL_SECONDS,
    LegacyThumbnailPublication,
    LinkedGallery,
    LinkedMediaPage,
    LinkedPhoto,
    LinkedPresentation,
    LinkedTrack,
    LinkingError,
    LinkingMediaThrottled,
    LinkingMediaUnavailable,
    LinkingResponseError,
    LinkingRevisionUnsupported,
    LinkingSession,
    LinkingThumbnailBatchDeferred,
    LinkingThumbnailProofChanged,
    Literal,
    MEDIA_AUDIO_POLL_MS,
    MEDIA_AUDIO_REAP_SECONDS,
    MEDIA_DETAIL_CACHE_ENTRY_LIMIT,
    MEDIA_DETAIL_CACHE_FRESH_SECONDS,
    MEDIA_DETAIL_CACHE_MAX_BYTES,
    MEDIA_DETAIL_PREFETCH_LIMIT,
    MEDIA_FULLSCREEN_COMMAND_MODIFIERS,
    MEDIA_FULLSCREEN_WINDOWS_COMMAND_MODIFIERS,
    MEDIA_GRID_GUTTER,
    MEDIA_GRID_ROW_MINIMUM_HEIGHT,
    MEDIA_HTTP_BATCH_INITIAL_SIZE,
    MEDIA_HTTP_BATCH_LARGE_BYTES,
    MEDIA_HTTP_BATCH_SLOW_SECONDS,
    MEDIA_IMAGE_MAX_PIXELS,
    MEDIA_IMAGE_MAX_SIZE,
    MEDIA_INITIAL_THUMBNAIL_PRIORITY_LIMIT,
    MEDIA_MCI_MODE_CAPACITY,
    MEDIA_MUSIC_PANEL_WIDTH,
    MEDIA_QUEUE_ACTIVE_POLL_MS,
    MEDIA_REVISION_POLL_MS,
    MEDIA_SLIDESHOW_DEFAULT_SECONDS,
    MEDIA_SLIDESHOW_INTERVALS,
    MEDIA_THUMBNAIL_BATCH_SIZE,
    MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
    MEDIA_THUMBNAIL_DECODE_SIZE,
    MEDIA_THUMBNAIL_GUI_SLICE_SECONDS,
    MEDIA_THUMBNAIL_GUI_SLICE_SIZE,
    MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS,
    MEDIA_THUMBNAIL_OVERSCAN_ROWS,
    MEDIA_THUMBNAIL_PLACEHOLDER,
    MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
    MEDIA_THUMBNAIL_SIZE,
    MEDIA_TILE_LABEL_MAX_CHARS,
    MEDIA_VIEWPORT_COALESCE_MS,
    MciSender,
    MediaReadTimings,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    NATIVE_MEDIA_LEGACY_PAGE_SIZE,
    NATIVE_MEDIA_MAX_BYTES,
    NATIVE_MEDIA_PAGE_SIZE,
    OrderedDict,
    PhotoLightbox,
    PhotosDecodeError,
    PhotosDecodeFacts,
    RaisedDialogSurface,
    THUMBNAIL_BATCH_CHOICES,
    THUMBNAIL_BATCH_DEFAULT_SIZE,
    ThumbnailBatchPhysicalWindow,
    ThumbnailBatchStats,
    ThumbnailPerformanceWindow,
    ThumbnailPublication,
    TooltipValue,
    TypeAlias,
    ViewportPerformanceWindow,
    _HttpBatchCompletion,
    _SafePhotosPhase,
    _ThumbnailDiskLane,
    _ThumbnailResult,
    _WorkerEvent,
    _WorkerPayload,
    _attach_tooltip,
    _healthy_http_thumbnail_group,
    _log_safe_media_failure,
    _log_safe_photos_observation,
    ctypes,
    dataclass,
    deque,
    detect_black_matte_crop,
    ensure_component_theme,
    format_safe_photos_decode_facts,
    format_safe_photos_failure,
    format_safe_photos_shortcut,
    gallery_card_dimensions,
    gallery_grid_theme,
    hashlib,
    icon_button,
    io,
    is_safe_photos_observation,
    load_winmm,
    logging,
    normalize_card_format,
    os,
    queue,
    replace,
    resolve_canvas_gallery_renderer_enabled,
    send_mci,
    signal,
    subprocess,
    sys,
    tempfile,
    threading,
    time,
    tk,
    tr,
    ttk,
    uuid,
    vertical_scroll_delta,
)

from .photos.tk.viewer.view import NativeGalleryViewMixin
from .photos.tk.viewer.queue import NativeGalleryQueueMixin
from .photos.tk.viewer.image import NativeGalleryImageMixin
from .photos.tk.viewer.session import NativeGallerySessionMixin

class _NativeGalleryViewer(NativeGalleryViewMixin, NativeGalleryQueueMixin, NativeGalleryImageMixin, NativeGallerySessionMixin):
    """Own one embedded gallery view, worker and temporary playback file.

    Args:
        parent (tk.Misc): Photos workspace frame receiving the gallery view.
        session (LinkingSession): Current scoped session used only for media.
        gallery (LinkedGallery): Explicitly chosen gallery identity and title.
        language (Callable[[], str]): Callback returning the current language.
        preview_result (Callable[[bool | None], None] | None): Optional one-shot result
            for an implicit first-gallery preview.
        show_title (bool): Whether the child view shows its gallery title.
        thumbnail_memory (GalleryThumbnailMemory | None): Session-owned decoded
            thumbnail cache shared by album selections.
        context_menu (Callable[[LinkedPhoto | None, tk.Event], str | None] | None):
            Optional owner for photo or empty-grid context actions.
        photo_selected (Callable[[LinkedPhoto], None] | None):
            Optional Tk-thread receiver for the focused photo descriptor.
        diagnostic_callback (Callable[[str], None] | None): Optional thread-safe
            receiver for already-sanitized Photos failure summaries.
        observation_callback (Callable[[str], None] | None): Optional
            receiver for allowlisted non-failure Photos observations.
    """
    def __init__(
        self,
        parent: tk.Misc,
        session: LinkingSession,
        gallery: LinkedGallery,
        language: Callable[[], str],
        preview_result: Callable[[bool | None], None] | None = None,
        show_title: bool = True,
        thumbnail_memory: GalleryThumbnailMemory | None = None,
        context_menu: Callable[[LinkedPhoto | None, tk.Event], str | None] | None = None,
        diagnostic_callback: Callable[[str], None] | None = None,
        photo_selected: Callable[[LinkedPhoto], None] | None = None,
        observation_callback: Callable[[str], None] | None = None,
        newest_first: bool = False,
        canvas_grid_enabled: bool | None = None,
        thumbnail_batch_size: Callable[[], int] | None = None,
    ) -> None:
        """Create the embedded view and start its first bounded page load.

        Args:
            parent (tk.Misc): Photos workspace frame receiving the gallery view.
            session (LinkingSession): Current scoped session used only for media.
            gallery (LinkedGallery): Explicitly chosen gallery identity and title.
            language (Callable[[], str]): Callback returning the current language.
            preview_result (Callable[[bool | None], None] | None): Optional one-shot
                result for the implicit initial gallery candidate.
            show_title (bool): Whether this child view shows its gallery title.
            thumbnail_memory (GalleryThumbnailMemory | None): Session cache
                shared across selections, or None for a viewer-owned cache.
            context_menu (Callable[[LinkedPhoto | None, tk.Event], str | None] | None):
                Handler for a photo or empty-grid context, or None to omit it.
            diagnostic_callback (Callable[[str], None] | None): Optional
                thread-safe receiver for sanitized failure facts.
            photo_selected (Callable[[LinkedPhoto], None] | None): Optional
                Tk-thread receiver for a selected photo descriptor.
            observation_callback (Callable[[str], None] | None): Optional
                receiver for bounded, non-failure observation summaries.
            newest_first (bool): Reverse the complete metadata sequence locally.
            canvas_grid_enabled (bool | None): Resolved renderer preference;
                None uses the configured factory default and environment choice.
            thumbnail_batch_size (Callable[[], int] | None): Reader for the
                current saved steady request size, or None for the default.

        Returns:
            None: The viewer owns the returned child frame until it is destroyed.
        """
        self.session = session
        self.gallery = gallery
        self.language = language
        self._preview_result = preview_result
        self._show_title = show_title
        self._view_order = (
            GalleryViewOrder(session, gallery.gallery_id) if newest_first else None
        )
        self._owns_thumbnail_memory = thumbnail_memory is None
        self._thumbnail_memory = thumbnail_memory or GalleryThumbnailMemory()
        self._context_menu = context_menu
        # Native Buttons remain the explicit saved opt-out and accessibility
        # fallback while Canvas is the factory default. _NativeGalleryViewer
        # owns this fallback; retain it until physical and screen-reader checks,
        # paired performance qualification, and owner approval. See
        # docs/COMPATIBILITY_LIFECYCLE.md#issue-399-canvas-grid-fallback.
        # Read the resolved renderer choice once for this viewer's lifetime.
        self._canvas_grid_enabled = (
            canvas_grid_enabled
            if canvas_grid_enabled is not None
            else resolve_canvas_gallery_renderer_enabled(True)
        )
        self._canvas_grid: CanvasGalleryGrid | None = None
        self._grid_content_height: int = 0
        self._native_grid_width: int | None = None
        self._diagnostic_callback = diagnostic_callback
        self._on_photo_selected = photo_selected
        self._observation_callback = observation_callback
        self.on_first_thumbnail_presented: Callable[[], None] | None = None
        self._first_thumbnail_reported = False
        self.on_first_viewport_settled: Callable[[], None] | None = None
        self._first_viewport_reported = False
        self._card_format = 'default'
        self.on_photos_changed: Callable[[], None] | None = None
        self.on_cache_changed: Callable[
            [tuple[LinkedPhoto, ...], bool], None
        ] | None = None
        self.window = parent.winfo_toplevel()
        self._main_content = parent
        while (self._main_content is not self.window
               and self._main_content.master is not self.window
               and self._main_content.master is not None):
            self._main_content = self._main_content.master
        self.frame = ttk.Frame(parent, style='Card.TFrame')
        self.frame._gallery_viewer_controller = self
        self.frame.bind('<Destroy>', self._on_destroy, add='+')
        self._window_bindings: list[tuple[str, str | None]] = []
        self._window_bindings.append((
            '<Escape>', self.window.bind('<Escape>', self._on_escape, add='+'),
        ))

        self._events: queue.Queue[_WorkerEvent] = queue.Queue(
            maxsize=MEDIA_THUMBNAIL_BATCH_SIZE,
        )
        # Counts coalesce separately so progress cannot occupy a raster/result
        # slot and delay the first usable page. The existing Tk drain owns both.
        self._load_progress_lock = threading.Lock()
        self._pending_load_progress: tuple[int, int] | None = None
        self._pending_load_wait: tuple[int, int | None, bool] | None = None
        self._thumbnail_result_lock = threading.Lock()
        self._thumbnail_metrics_lock = threading.Lock()
        self._thumbnail_performance = ThumbnailPerformanceWindow()
        self._thumbnail_batch_physical = ThumbnailBatchPhysicalWindow()
        self._viewport_performance = ViewportPerformanceWindow()
        self._detail_media_metrics: dict[str, int] = {
            name: 0 for name in (
                'detail_source_disk', 'detail_source_network',
                'detail_source_revalidated', 'detail_http_requests',
                'detail_encoded_bytes', 'prefetch_source_disk',
                'prefetch_source_network', 'prefetch_source_revalidated',
                'prefetch_http_requests', 'prefetch_encoded_bytes',
                'detail_ram_hits', 'prefetch_adoptions',
            )
        }
        self._thumbnail_disk_lane = _ThumbnailDiskLane(
            session, self._prepare_thumbnail, self._observe_thumbnail_read,
        )
        self._thumbnail_local_miss_count = 0
        self._thumbnail_discarded_count = 0
        self._thumbnail_cancelled_read_count = 0
        self._thumbnail_retained_hit_count = 0
        self._thumbnail_transport_totals: dict[str, float] = {
            name: 0.0 for name in (
                'http_requests', 'connections', 'tls_handshakes',
                'connection_reuses', 'context_ms', 'dns_ms', 'tcp_ms',
                'tls_ms', 'ttfb_ms', 'body_ms',
            )
        }
        self._thumbnail_cache_outcomes: dict[str, int] = {
            name: 0 for name in (
                'miss', 'hit', 'revalidation', 'ineligible', 'changed', 'expired',
            )
        }
        self._cancelled = threading.Event()
        self._generation = 0
        self._worker: threading.Thread | None = None
        self._worker_kind: str | None = None
        self._thumbnail_cancelled = threading.Event()
        self._active_media_cancelled: threading.Event | None = None
        self._active_media_photo_id: int | None = None
        self._closed = False
        self._revision: str | None = None
        self._refresh_revision: str | None = None
        self._revision_supported = True
        self._revision_initial_attempted = False
        self._initial_thumbnail_priority_count = 0
        self._revision_timer: str | None = None
        self._refresh_pending = False
        self._refresh_manual_pending = False
        self._refresh_foreground = False
        self._thumbnail_proof_refresh_attempt_at: float | None = None
        self._thumbnail_proof_refresh_count = 0
        self._selected_photo_ids: set[int] = set()
        self._selection_anchor_index: int | None = None
        self._reconciling = False
        self._photos: tuple[LinkedPhoto, ...] = ()
        self._loading_more = False
        self._wrap_after_metadata = 0
        self._prefetch_edge_direction = 0
        self._next_after: int | None = None
        self._after_id: str | None = None
        self._slideshow_id: str | None = None
        self._slideshow_running = False
        self._slideshow_music_attempted = False
        self._slideshow_started_music = False
        self._slide_index = 0
        self._presentation: LinkedPresentation | None = None
        self._presentation_pending = False
        self._tracks: tuple[LinkedTrack, ...] = ()
        self._audio_path: str | None = None
        self._audio_alias: str | None = None
        self._audio_process: subprocess.Popen[bytes] | None = None
        self._audio_index: int | None = None
        self._audio_paused = False
        self._audio_pending: int | None = None
        self._audio_successor_failed = False
        self._audio_prepared: tuple[int, str] | None = None
        self._audio_cancelled: threading.Event | None = None
        self._audio_poll_id: str | None = None
        self._winmm: object | None = None
        self._mci: MciSender | None = None
        self._photo_images: list[object | None] = []
        # Keep natural pixels only beside currently admitted Tk images. The
        # existing visible/overscan band owns admission and releases both copies;
        # formatting therefore needs no shared-cache proof or extra media reads.
        self._canonical_thumbnail_images: dict[int, 'Image.Image'] = {}
        self._detail_cache: OrderedDict[
            int, tuple[
                float, 'Image.Image', bytes, int,
                tuple[int | None, str | None, float | None, int | None],
            ],
        ] = OrderedDict()
        self._detail_cache_bytes = 0
        self._pending_prefetch_ids: list[int] = []
        self._prefetch_direction = 1
        self._tile_buttons: list[ttk.Button | CanvasTileProxy] = []
        self._tile_tooltips: list[tk.StringVar | TooltipValue] = []
        self._tile_titles: list[str] = []
        self._page_request_cursor: int | None = None
        self._page_request_mode = 'next'
        self._pending_page_request: tuple[int | None, bool] | None = None
        self._detail_serial = 0
        self._pending_detail_index: int | None = None
        self._thumbnail_index = 0
        self._thumbnail_loaded: set[int] = set()
        self._thumbnail_pending: list[int] = []
        self._visible_thumbnail_indices: set[int] = set()
        self._measured_visible: frozenset[int] = frozenset()
        self._viewport_measure_epoch: int | None = None
        self._admitted_thumbnail_indices: set[int] = set()
        self._viewport_after_id: str | None = None
        self._viewport_event_count = 0
        self._viewport_update_count = 0
        self._thumbnail_image_create_count = 0
        self._thumbnail_image_release_count = 0
        self._thumbnail_warm_cache_hit_count = 0
        self._thumbnail_batch_count = 0
        self._thumbnail_batch_indices: tuple[int, ...] = ()
        self._thumbnail_batch_size = thumbnail_batch_size
        self._http_thumbnail_group_healthy = False
        self._http_batch_completion: _HttpBatchCompletion | None = None
        self._http_batch_requests = 0
        self._http_batch_frames = 0
        self._http_batch_bytes = 0
        self._http_batch_failures = 0
        self._http_batch_incomplete = 0
        self._http_batch_fallbacks = 0
        self._http_batch_fallback_reasons: dict[str, int] = {
            'missing': 0, 'unsupported': 0, 'wire': 0,
            'restricted': 0, 'exceptional': 0,
        }
        self._http_batch_first_frame_ms = 0.0
        self._http_batch_sizes: dict[int, int] = {}
        self._http_batch_retry_indices: tuple[int, ...] = ()
        self._http_thumbnail_failure_latched = False
        self._thumbnail_resume_after_id: str | None = None
        self._thumbnail_resume_serial = 0
        self._thumbnail_source_disk_count = 0
        self._thumbnail_source_network_count = 0
        self._thumbnail_source_revalidated_count = 0
        self._thumbnail_disk_ms = 0.0
        self._thumbnail_disk_write_ms = 0.0
        self._thumbnail_gate_wait_ms = 0.0
        self._thumbnail_renewal_ms = 0.0
        self._thumbnail_network_ms = 0.0
        self._thumbnail_network_reads = 0
        self._thumbnail_decode_ms = 0.0
        self._thumbnail_queue_wait_ms = 0.0
        self._thumbnail_tk_ms = 0.0
        self._thumbnail_gui_after_id: str | None = None
        self._warm_thumbnail_pending: list[tuple[int, int, LinkedPhoto]] = []
        self._viewport_peak_update_ms = 0.0
        self._grid_columns = 1
        self._lightbox: PhotoLightbox | None = None
        self._lightbox_key_tag = 'GalleryLightboxKeys' + str(id(self))
        self._lightbox_key_bindings: list[tuple[str, str]] = []
        self._lightbox_key_widgets: list[tk.Misc] = []
        self._select_first_after_page = False
        self._preview_result_pending = preview_result is not None
        self._folder_shelf_visible = False
        self._empty_page = False
        self._retry_kind = 'page'
        self._media_throttled = False
        self._music_panel: ttk.Frame | None = None
        self.track_menu: ttk.Combobox | None = None
        self.panel_track_button: ttk.Button | None = None
        self._panel_track_tooltip = tk.StringVar(
            master=self.frame,
            value=self._text('linking_media_play'),
        )
        self._slideshow_tooltip = tk.StringVar(
            value=self._text('linking_media_slideshow_start'),
        )
        self._selected_track_index = 0
        self._lightbox_track_buttons: tuple[ttk.Button, ...] = ()
        self._lightbox_track_tooltip = tk.StringVar(
            master=self.frame,
            value=self._text('linking_media_play'),
        )

        self._build_widgets()
        self._bind_window_keys()
        self._window_bindings.append((
            '<Button-1>',
            self.window.bind('<Button-1>', self._on_context_click, add='+'),
        ))
        self._poll_queue()
        self._load_first_page()
        self._schedule_revision_check()

def mount_native_gallery_view(
    parent: tk.Misc,
    session: LinkingSession,
    gallery: LinkedGallery,
    language: Callable[[], str],
    preview_result: Callable[[bool | None], None] | None = None,
    show_title: bool = True,
    thumbnail_memory: GalleryThumbnailMemory | None = None,
    context_menu: Callable[[LinkedPhoto | None, tk.Event], str | None] | None = None,
    diagnostic_callback: Callable[[str], None] | None = None,
    photo_selected: Callable[[LinkedPhoto], None] | None = None,
    observation_callback: Callable[[str], None] | None = None,
    newest_first: bool = False,
    canvas_grid_enabled: bool | None = None,
    thumbnail_batch_size: Callable[[], int] | None = None,
) -> ttk.Frame:
    """Mount an inline, bounded gallery reader for a selected gallery.

    Args:
        parent (tk.Misc): Photos workspace widget receiving the child frame.
        session (LinkingSession): Live bearer session with required media scope.
        gallery (LinkedGallery): User-selected gallery from a validated page.
        language (Callable[[], str]): Callback returning the current UI language.
        preview_result (Callable[[bool | None], None] | None): One-shot callback for an
            implicit initial choice, called on Tk's thread with page availability.
        show_title (bool): Whether the child view shows its gallery title.
        thumbnail_memory (GalleryThumbnailMemory | None): Session-owned decoded
            thumbnail cache shared by album selections.
        context_menu (Callable[[LinkedPhoto | None, tk.Event], str | None] | None):
            Optional owner for photo or empty-grid context actions.
        diagnostic_callback (Callable[[str], None] | None): Optional thread-safe
            receiver for sanitized failure facts, or None to omit retention.
        photo_selected (Callable[[LinkedPhoto], None] | None): Optional
            Tk-thread receiver for the focused photo descriptor.
        observation_callback (Callable[[str], None] | None): Optional thread-safe
            receiver for allowlisted non-failure observations.
        newest_first (bool): Apply a local reverse order on all metadata refreshes.
        canvas_grid_enabled (bool | None): Resolved renderer preference;
            None uses the configured factory default and environment choice.
        thumbnail_batch_size (Callable[[], int] | None): Reader for the saved
            steady request size, or None for the default.

    Returns:
        ttk.Frame: Child view whose destruction releases worker and UI resources.
    """
    viewer = _NativeGalleryViewer(
        parent,
        session,
        gallery,
        language,
        preview_result=preview_result,
        show_title=show_title,
        thumbnail_memory=thumbnail_memory,
        context_menu=context_menu,
        diagnostic_callback=diagnostic_callback,
        photo_selected=photo_selected,
        observation_callback=observation_callback,
        newest_first=newest_first,
        canvas_grid_enabled=canvas_grid_enabled,
        thumbnail_batch_size=thumbnail_batch_size,
    )
    return viewer.frame
