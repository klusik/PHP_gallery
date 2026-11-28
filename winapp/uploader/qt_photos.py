# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_photos.py
# Module Type: Qt Photos workspace
# Purpose: Browse authorized or explicitly selected photos in the retained scene.
# Responsibilities: Compose Qt Photos behavior over one retained Qt Quick viewport.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Connect the Qt Quick scene to cancellable Photos operations."""

from .photos.qt.support import (
    ACTION_ICON_SIZE,
    ActionButton,
    BoundCreateReceipt,
    CacheProbeState,
    Callable,
    ConnectionActions,
    CreatedGallery,
    CredentialStoreUnavailable,
    DESKTOP_DIALOG_MARGIN,
    DESKTOP_DIALOG_SIZE,
    DesktopLinkedSite,
    Event,
    FOLDER_AUTO_SHARE,
    FOLDER_KEY_STEP,
    FOLDER_PHOTO_RESERVE,
    GALLERIES_SCOPE,
    GALLERY_FOLDER_SHARE_BOUNDS,
    GALLERY_FOLDER_SHARE_SCALE,
    GALLERY_VIEWPORT_HISTORY,
    GalleryActionResult,
    GalleryCacheProbeRow,
    GalleryCreateJournal,
    GalleryPlayback,
    GalleryPreloadWorker,
    GalleryViewport,
    INSPECTOR_PREVIEW_PIXELS,
    InformationGroup,
    InspectorMetadata,
    InspectorPane,
    InspectorPreview,
    LIGHTBOX_ACTION_EDGE,
    LIGHTBOX_CONTROLS_IDLE_MS,
    LIGHTBOX_EDGE_INSET,
    LIGHTBOX_TOOLBAR_TOP_INSET,
    LINKING_ADDRESS_VISIBLE_ROWS,
    LINKING_KEEPALIVE_SECONDS,
    LINKING_RECOVERY_MAX_FILE_BYTES,
    LinkedGallery,
    LinkedMediaDetails,
    LinkedPhoto,
    LinkedPhotos,
    LinkedRecoveryActions,
    LinkedRecoveryObservation,
    LinkedUploadTarget,
    LinkingAttempt,
    LinkingError,
    LinkingProgress,
    LinkingReconnectRequired,
    LinkingRefreshRejected,
    LinkingResponseError,
    LinkingSession,
    LinkingThumbnailProofChanged,
    LinkingUnavailable,
    LiveSiteCatalogue,
    MEDIA_HTTP_BATCH_INITIAL_SIZE,
    MEDIA_RECENT_ORDER_PHOTO_LIMIT,
    MEDIA_SLIDESHOW_DEFAULT_SECONDS,
    MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
    MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS,
    MEDIA_THUMBNAIL_SIZE,
    MEDIA_VIEWPORT_COALESCE_MS,
    MacQtHDRSurface,
    MediaInformation,
    MediaInformationFailure,
    ModeRecord,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    NativeCredentialStore,
    OfflineBinding,
    OfflineRefreshPending,
    OrderedDict,
    PALETTE,
    PHOTOS_CARD_FORMAT_ICON_SIZE,
    PHOTOS_HEADING_STACK_WIDTH,
    PHOTOS_HEADING_TITLE_MINIMUM,
    PHOTOS_INFO_CACHE_ENTRY_LIMIT,
    PHOTOS_INFO_CACHE_FRESH_SECONDS,
    PHOTOS_INFO_PANE_WIDTH,
    PHOTOS_TREE_WIDTH,
    PHOTO_CAPTURE_PULSE_MS,
    PHOTO_CARD_FORMATS,
    PHOTO_CARD_MARGINS,
    PHOTO_CONNECT_OUTER_INSET,
    PHOTO_CONTEXT_PADDING,
    PHOTO_FORMAT_HORIZONTAL_INSET,
    PHOTO_FORMAT_VERTICAL_INSET,
    PHOTO_HEADING_ICON_PADDING,
    PHOTO_HEADING_RECORDING_SIZE,
    PHOTO_LOCATION_MAP_ZOOM,
    PHOTO_TREE_MARKER_SIZE,
    PRELOAD_STATUS_POLL_MS,
    Path,
    PhotoCatalogue,
    PhotoFrame,
    PhotoListModel,
    PhotoRow,
    PhotoTextures,
    PhotosLoadResult,
    PhotosTreePreferences,
    PhotosUploadUpgrade,
    Property,
    QAction,
    QApplication,
    QButtonGroup,
    QCheckBox,
    QColor,
    QComboBox,
    QContextMenuEvent,
    QDialog,
    QEvent,
    QFileDialog,
    QFrame,
    QGridLayout,
    QHBoxLayout,
    QHideEvent,
    QIMAGE_MAX_BYTES_PER_PIXEL,
    QIcon,
    QInputDialog,
    QKeyEvent,
    QLabel,
    QLayout,
    QLineEdit,
    QMenu,
    QMessageBox,
    QModelIndex,
    QObject,
    QPlainTextEdit,
    QPoint,
    QProgressBar,
    QPushButton,
    QQuickWidget,
    QRectF,
    QResizeEvent,
    QShowEvent,
    QSignalBlocker,
    QSize,
    QSizePolicy,
    QSortFilterProxyModel,
    QSplitter,
    QTimer,
    QToolButton,
    QTreeView,
    QTreeWidget,
    QTreeWidgetItem,
    QUrl,
    QVBoxLayout,
    QWidget,
    Qt,
    QtFolderShelf,
    QtGalleryCacheProbe,
    QtGalleryEditor,
    QtReadLane,
    RECENT_GALLERY_ADDRESS_LIMIT,
    RaisedSurface,
    ReadCancelled,
    SHELL_ICON_BUTTON_EDGE,
    SavedLinkRecovery,
    Signal,
    Slot,
    ThumbnailActivity,
    ThumbnailEvent,
    ThumbnailGroup,
    ThumbnailProofPage,
    ToggleSwitch,
    UI_LAYOUT_INFO_WIDTH_BOUNDS,
    UI_LAYOUT_VIEWER_MINIMUM_WIDTH,
    UI_PHOTO_FORMAT_LABEL_POINTS,
    UPLOAD_SCOPE,
    UUID,
    UnlinkedSite,
    VIEWPORT_TEXTURE_LIMIT,
    _SCENE,
    dataclass,
    delete_transient,
    desktop_upload_vault,
    discover_linking_capabilities,
    enable_rolling_renewal,
    escape,
    field,
    find_live_link,
    forget_browser_upload_grant,
    format_gallery_bytes,
    format_safe_photos_failure,
    gallery_card_dimensions,
    gallery_editor_url,
    gettext,
    gettext_noop,
    isfinite,
    linking_issuer,
    linking_prompt_issuer,
    live_link_sites,
    load_gallery_card_format,
    load_gallery_folder_split,
    load_gallery_photo_order,
    load_offline_binding,
    load_photos_tree_preferences,
    load_recent_gallery_addresses,
    load_thumbnail_batch_size,
    load_transient,
    load_ui_language,
    logging,
    open_saved_upload_session,
    persist_browser_gallery_grant,
    persist_browser_scope_upgrade,
    persist_browser_upload_grant,
    photo_information_groups,
    photos_account_tree_path,
    probe_gallery_cache_status,
    prod,
    qt_icon,
    read_gallery_photos,
    read_gallery_tree,
    read_photo,
    remember_desktop_transient_link,
    remove_offline_binding,
    replace,
    requested_linking_scope,
    restore_desktop_gallery_link,
    revoke_saved_gallery_grant,
    save_gallery_card_format,
    save_gallery_folder_split,
    save_gallery_photo_order,
    save_offline_binding,
    save_photos_tree_preferences,
    save_recent_gallery_addresses,
    saved_desktop_gallery_binding,
    saved_offline_hint_path,
    scope_base,
    store_approved_refresh,
    stream_revalidated_thumbnails,
    stream_thumbnails,
    sys,
    thumbnail_quality_caption,
    time,
    tr,
    uuid4,
    verify_linked_upload_target,
    verify_live_gallery_activity,
    webbrowser,
)

from .photos.qt.ui_mixin import QtPhotosUiMixin
from .photos.qt.link_management import QtPhotosLinkMixin
from .photos.qt.actions import QtPhotosActionsMixin
from .photos.qt.media import QtPhotosMediaMixin

class QtPhotos(QWidget, QtPhotosUiMixin, QtPhotosLinkMixin, QtPhotosActionsMixin, QtPhotosMediaMixin):
    """Own the Photos workspace, with one Qt scene and no independent transport."""
    detailChanged = Signal()
    formatChanged = Signal()
    photosActiveChanged = Signal()
    connection_changed = Signal()
    gallery_context_changed = Signal(bool)
    lightbox_state_changed = Signal(bool)
    zoomInRequested = Signal()
    zoomOutRequested = Signal()
    zoomFitRequested = Signal()
    scrollTo = Signal(int)
    restoreScroll = Signal(int, int)
    splitter_moved = Signal()
    work_changed = Signal(bool)
    link_action_reported = Signal(str)
    diagnostic_reported = Signal(str)
    def __init__(
        self, profile: Path, gallery_address: str,
        connections: ConnectionActions | None = None,
        transfer_busy: Callable[[], bool] | None = None,
        start_contextual: Callable[[LinkedUploadTarget], None] | None = None,
        *, gallery_rename_busy: Callable[[], bool] | None = None,
    ) -> None:
        """Compose compact photo controls around a retained Qt Quick viewport.

        Args:
            profile (Path): Per-user interface preference owner.
            gallery_address (str): Saved non-secret gallery address suggestion.
            connections (ConnectionActions | None): Explicit verified upload mode transitions.
            transfer_busy (Callable[[], bool] | None): Other views still own the current grant.
            start_contextual (Callable[[LinkedUploadTarget], None] | None):
                Starts the verified gallery's Watcher with its temporary key.
            gallery_rename_busy (Callable[[], bool] | None): Checks transfer
                ownership for a title edit. None uses the general transfer check.
        Returns:
            None: Prepares the empty catalogue and native renderer.
        """
        super().__init__()
        self.setObjectName("photosWorkspace")
        self.profile = profile
        self._recent_gallery_address_path = profile / 'recent-gallery-links.json'
        self._recent_gallery_addresses = load_recent_gallery_addresses(
            self._recent_gallery_address_path,
        )
        self.language = load_ui_language(profile)
        self.gallery_address = gallery_address
        self.connections = connections
        self.transfer_busy = transfer_busy or (lambda: False)
        self.start_contextual = start_contextual
        self._gallery_rename_busy = gallery_rename_busy
        self.simbrief_available: Callable[[], bool] | None = None
        self.simbrief_action: Callable[[], None] | None = None
        self.load_simbrief_action: Callable[[], None] | None = None
        self._active_gallery_menu: QMenu | None = None
        self._live_sites_menu: QMenu | None = None
        self._gallery_context_demand = False
        self._simbrief_grid_demand = False
        self._gallery_presence_demand = False
        self._simbrief_menu_entry: QAction | None = None
        self._identity: tuple[str, int] | None = None
        self._parked_links: tuple[DesktopLinkedSite, ...] = ()
        self._pending_live_site: DesktopLinkedSite | None = None
        self._keepalive_after = 0.0
        self._photos_active = True
        self._maintenance_busy = False
        self._unlink_pending = False
        self._action_success = ''
        self._action_failure = ''
        self._refresh_after_action = False
        self._refresh_photos_after_action: tuple[int, str] | None = None
        self._catalogue_notice: tuple[int, str] | None = None
        self._information_dialog: QDialog | None = None
        self._gallery_picker: QDialog | None = None
        self._gallery_picker_token: UUID | None = None
        self._gallery_picker_feedback: QLabel | None = None
        self._authorization_dialog: QDialog | None = None
        self._authorization_address: QLineEdit | None = None
        self._authorization_status: QLabel | None = None
        self._authorization_failure = ''
        self._authorization_submit: ActionButton | None = None
        self._authorization_recent: QTreeWidget | None = None
        self._authorization_forget: ActionButton | None = None
        self._photo_context: QFrame | None = None
        self._photo_context_anchor = QPoint()
        self._inspected_photo: LinkedPhoto | None = None
        self._photo_info_cache: OrderedDict[
            tuple[int, int, bool], tuple[float, MediaInformation]
        ] = OrderedDict()
        self._capture_target: tuple[str, int, int] | None = None
        self._capture_markers: set[int] = set()
        self._capture_marker_hollows: dict[int, bool] = {}
        self._cache_states: dict[int, CacheProbeState] = {}
        self._gallery_pins: dict[int, bool] = {}
        # The status and four display flags have a fixed, small vocabulary.
        # Reuse the Qt icon even when many rows share it or a pulse changes.
        self._tree_marker_icons: dict[tuple[str, bool, bool, bool, bool], QIcon] = {}
        self._cache_probe_key: tuple[LinkingSession, int, int] | None = None
        self._capture_pulse = True
        self._capture_pulse_timer = QTimer(self)
        self._capture_pulse_timer.setInterval(PHOTO_CAPTURE_PULSE_MS)
        self._capture_pulse_timer.timeout.connect(self._pulse_capture_marker)
        self._gallery_editor: QtGalleryEditor | None = None
        self._create_draft: tuple[LinkingSession, int | None, str] | None = None
        self._preload_worker: GalleryPreloadWorker | None = None
        self._preload_session: LinkingSession | None = None
        self._preload_unpin = False
        self._preload_foreground = Event()
        self._preload_wait_reason = ''
        self._pipeline_waiting = False
        self.model = PhotoListModel()
        self.textures = PhotoTextures()
        self._session: LinkingSession | None = None
        self._tree_path: Path | None = None
        self._opened_galleries: dict[int, bool] = {}
        self._gallery_items: dict[int, QTreeWidgetItem] = {}
        self._gallery_children: dict[int, tuple[LinkedGallery, ...]] = {}
        self._view_path: Path | None = None
        self._attempt: LinkingAttempt | None = None
        self._authorization_active = False
        self._startup_browse = False
        self._startup_saved_mode: ModeRecord | None = None
        self._selecting_saved_target = False
        self._scope_dialog: QDialog | None = None
        self._scope_issuer = ''
        self._scope_capabilities: frozenset[str] = frozenset()
        self._scope_checks: dict[str, QCheckBox] = {}
        self._scope_status: QLabel | None = None
        self._scope_update_button: ActionButton | None = None
        self._scope_disconnect_button: ActionButton | None = None
        self._scope_reconnect_button: ActionButton | None = None
        self._scope_forget_button: ActionButton | None = None
        self._scope_saved_mode: ModeRecord | None = None
        self._scope_active_target: LinkedUploadTarget | None = None
        self._scope_offline_storage_ready = False
        self._scope_offline_store_available = False
        self._scope_offline_binding: OfflineBinding | None = None
        self._scope_live_session: LinkingSession | None = None
        self._scope_live_identity: tuple[str, int] | None = None
        self._local_photos_active = False
        self._inspector_width = PHOTOS_INFO_PANE_WIDTH
        self._gallery_id: int | None = None
        self._rendered_gallery: int | None = None
        self._viewports: OrderedDict[int, GalleryViewport] = OrderedDict()
        self._focused: int | None = None
        self._tree_order: tuple[PhotoRow, ...] = ()
        self._preferred_tree_width = PHOTOS_TREE_WIDTH
        self._tiles: dict[int, str] = {}
        self._retained: OrderedDict[int, tuple[PhotoFrame, float, int]] = OrderedDict()
        self._retained_bytes = 0
        self._requested = (0, 0)
        self._wanted: tuple[int, ...] = ()
        self._attempted: set[int] = set()
        self._group: tuple[int, ...] = ()
        self._renewing_thumbnail_proofs = False
        self._thumbnail_proof_changed = False
        self._loading_thumbnails: set[int] = set()
        self._healthy = False
        self._batch_limit = load_thumbnail_batch_size(profile)
        self._revision = 0
        self._anchor: int | None = None
        self._newest = True
        self._format = "default"
        self._format_unsaved: set[int] = set()
        self._format_columns = len(PHOTO_CARD_FORMATS)
        self._folder_format = 'default'
        self._folder_newest = False
        self._folder_split_share: int | None = None
        self._folder_split_loaded: tuple[Path, int] | None = None
        self._folder_modes_unsaved: set[str] = set()
        self._gallery_has_photos: bool | None = None
        self._using_folder_section = False
        self._detail_index: int | None = None
        self._detail_display_index: int | None = None
        self._detail_url = ""
        self._detail_title = ""
        self._detail_hdr_source: bytes | None = None
        self._detail_feedback = ''
        self._detail_failed = False
        self._detail_crop_rect: QRectF | None = None
        self._detail_content_crop_enabled = False
        self._hdr_restore_pending = False
        self._hdr_image_rect = QRectF()
        self._detail_keys: tuple[str, ...] = ()
        self._fullscreen = False
        self._lightbox_layout_restore: tuple[dict[QWidget, bool], list[int]] | None = None
        self._lightbox_controls_visible = True
        self._detail_menu: QMenu | None = None
        self._lightbox_controls_timer = QTimer(self)
        self._lightbox_controls_timer.setSingleShot(True)
        self._lightbox_controls_timer.setInterval(LIGHTBOX_CONTROLS_IDLE_MS)
        self._lightbox_controls_timer.timeout.connect(self._hide_lightbox_controls)
        self._was_maximized = False
        self._playing = False
        self._slideshow_owns_music = False
        self._load = QtReadLane[PhotosLoadResult](
            self._receive_catalogue, self._catalogue_finished, self,
            dispose=lambda result: result.session.close()
            if (isinstance(result, LinkedPhotos) and result.owns_session)
            or isinstance(result, PhotosUploadUpgrade) else None,
        )
        # Cancelled or failed local probes leave the previous display unchanged.
        self._cache_probe = QtReadLane[QtGalleryCacheProbe](
            self._receive_cache_probe, lambda _failure: None, self,
        )
        self._scope_discovery = QtReadLane[
            tuple[str, frozenset[str]]
        ](
            self._receive_scope_capabilities, self._scope_discovery_finished, self,
        )
        self._thumbnails = QtReadLane[ThumbnailEvent](
            self._receive_thumbnail, self._group_finished, self,
        )
        self._details = QtReadLane[PhotoFrame](
            self._receive_detail, self._detail_finished, self,
        )
        self._maintenance = QtReadLane[
            str | CreatedGallery | UnlinkedSite | LinkedRecoveryObservation
        ](
            self._receive_maintenance, self._maintenance_finished, self,
        )
        self._information = QtReadLane[MediaInformation | MediaInformationFailure](
            self._show_information, self._information_finished, self,
        )
        self._inspector_read = QtReadLane[PhotoFrame](
            self._show_inspector_preview, self._inspector_preview_finished, self,
        )
        self._keepalive = QtReadLane[DesktopLinkedSite](
            self._keepalive_problem, lambda _failure: None, self,
        )
        self._preload_timer = QTimer(self)
        self._preload_timer.setInterval(PRELOAD_STATUS_POLL_MS)
        self._preload_timer.timeout.connect(self._poll_preload)
        self._viewport_timer = QTimer(self)
        self._viewport_timer.setSingleShot(True)
        self._viewport_timer.setInterval(MEDIA_VIEWPORT_COALESCE_MS)
        self._viewport_timer.timeout.connect(self._apply_viewport)
        self._slideshow = QTimer(self)
        self._slideshow.setSingleShot(True)
        # QTimer uses milliseconds while the shared slideshow preference is seconds.
        self._slideshow.setInterval(MEDIA_SLIDESHOW_DEFAULT_SECONDS * 1000)
        self._slideshow.timeout.connect(self._advance_slideshow)

        layout = QVBoxLayout(self)
        self._photos_layout = layout
        self._photos_margins = layout.contentsMargins()
        self._connect_card = QFrame()
        self._connect_card.setObjectName("photosConnectCard")
        self._connect_card.setProperty("shellCard", True)
        self._connect_card.setSizePolicy(QSizePolicy.Policy.Fixed, QSizePolicy.Policy.Fixed)
        self._connect_card_outer = QWidget()
        self._connect_card_outer.setObjectName('photosConnectCardOuter')
        self._connect_card_outer.setSizePolicy(
            QSizePolicy.Policy.Expanding, QSizePolicy.Policy.Fixed,
        )
        connect_outer_layout = QVBoxLayout(self._connect_card_outer)
        connect_outer_layout.setContentsMargins(*(
            PHOTO_CONNECT_OUTER_INSET, PHOTO_CONNECT_OUTER_INSET,
            PHOTO_CONNECT_OUTER_INSET, PHOTO_CONNECT_OUTER_INSET,
        ))
        connect_outer_layout.setSpacing(0)
        connect_layout = QHBoxLayout(self._connect_card)
        # Match the compact Tk connection card and keep its unused row clear.
        connect_layout.setContentsMargins(18, 18, 18, 18)
        self.link_button = ActionButton(
            tr(self.language, "linking_start"), self.link,
        )
        self.link_button.setObjectName("photosConnectButton")
        self.link_button.setStyleSheet(
            "QPushButton { background: transparent; border-color: transparent; "
            f"color: {PALETTE['focus']}; }} "
            f"QPushButton:hover {{ background: {PALETTE['surface_alt']}; }} "
            f"QPushButton:focus {{ border-color: {PALETTE['focus']}; }} "
            f"QPushButton:disabled {{ color: {PALETTE['muted']}; }}"
        )
        connect_layout.addWidget(self.link_button)
        connect_outer_layout.addWidget(
            self._connect_card,
            alignment=Qt.AlignmentFlag.AlignLeft | Qt.AlignmentFlag.AlignTop,
        )
        layout.addWidget(self._connect_card_outer)

        self._center_toolbar = QWidget()
        center_toolbar = QGridLayout(self._center_toolbar)
        center_toolbar.setContentsMargins(0, 0, 0, 0)
        center_toolbar.setSpacing(4)
        center_toolbar.setColumnStretch(0, 1)
        self._gallery_header_layout = center_toolbar
        self._gallery_heading_actions = QWidget()
        self._gallery_heading_actions.setObjectName('photosGalleryHeadingActions')
        self._gallery_heading_actions.setStyleSheet(
            'QWidget#photosGalleryHeadingActions QPushButton[compactIcon="true"] {'
            f' padding: {PHOTO_HEADING_ICON_PADDING}px; }}',
        )
        heading_controls = QHBoxLayout(self._gallery_heading_actions)
        heading_controls.setContentsMargins(0, 0, 0, 0)
        self._gallery_title = QLabel()
        self._gallery_title.setObjectName("heading")
        self._gallery_title.setTextFormat(Qt.TextFormat.PlainText)
        self._gallery_title.setWordWrap(True)
        self._gallery_title.setTextInteractionFlags(
            Qt.TextInteractionFlag.TextSelectableByMouse,
        )
        title_actions = QWidget()
        self._gallery_heading_title = title_actions
        title_layout = QHBoxLayout(title_actions)
        title_layout.setContentsMargins(0, 0, 0, 0)
        title_layout.setSpacing(center_toolbar.horizontalSpacing())
        self._capture_heading = QLabel()
        self._capture_heading.setObjectName('photosWatcherRecording')
        recording_hint = gettext('Watcher is still running')
        self._capture_heading.setAccessibleName(recording_hint)
        self._capture_heading.setToolTip(recording_hint)
        self._capture_heading.hide()
        title_layout.addWidget(self._capture_heading)
        title_layout.addWidget(self._gallery_title)
        self._edit_title_button = ActionButton(
            '✎', lambda: self.edit_gallery(field='title', trigger=self._edit_title_button),
        )
        self._edit_title_button.setObjectName('photosEditGalleryTitle')
        self._edit_title_button.setProperty('compactIcon', True)
        self._edit_title_button.setAccessibleName(self._text('edit_title'))
        self._edit_title_button.setToolTip(self._text('edit_title'))
        self._edit_title_button.hide()
        title_layout.addWidget(self._edit_title_button)
        title_layout.addStretch()
        center_toolbar.addWidget(title_actions, 0, 0)
        refresh_button = ActionButton(
            self._text("refresh"), self.refresh,
            icon="refresh", icon_only=True,
        )
        self._gallery_options = ActionButton(
            self._text("gallery_menu"), lambda: None, icon='unknown', icon_only=True,
        )
        self._gallery_options.setObjectName('photosGalleryActions')
        self._gallery_options.setStyleSheet('QPushButton::menu-indicator { image: none; width: 0; }')
        self._gallery_menu = QMenu(self._gallery_options)
        self._gallery_options.setMenu(self._gallery_menu)
        self._gallery_options.setIcon(self._gallery_state_icon(None, show_capture=False))
        self._gallery_options.setIconSize(QSize(PHOTO_TREE_MARKER_SIZE, PHOTO_TREE_MARKER_SIZE))
        self._gallery_menu.aboutToShow.connect(self._populate_gallery_menu)
        self._gallery_menu.aboutToHide.connect(lambda: self._end_gallery_context(self._gallery_menu))
        self._gallery_options.hide()
        self._create_child_button = ActionButton(
            self._text('create_child'), lambda: self.create_gallery(parent_id=self._gallery_id, choose_parent=False),
            icon='add', icon_only=True,
        )
        self._subgallery_switch = ToggleSwitch(self._text('subgalleries'))
        self._subgallery_switch.setObjectName('photosSubgalleriesToggle')
        self._subgallery_switch.setChecked(True)
        self._subgallery_switch.toggled.connect(self._sync_folder_section)
        self._subgallery_switch.hide()
        heading_controls.addWidget(self._subgallery_switch)
        heading_controls.addWidget(self._create_child_button)
        heading_controls.addWidget(refresh_button)
        heading_controls.addWidget(self._gallery_options)
        for button in (self._create_child_button, refresh_button, self._gallery_options):
            button.setFixedSize(SHELL_ICON_BUTTON_EDGE, SHELL_ICON_BUTTON_EDGE)
            compact_icon_size = min(button.iconSize().width(), PHOTO_TREE_MARKER_SIZE)
            button.setIconSize(QSize(compact_icon_size, compact_icon_size))
        center_toolbar.addWidget(self._gallery_heading_actions, 0, 2)

        self._format_row = QWidget()
        format_row = QHBoxLayout(self._format_row)
        format_row.setContentsMargins(0, 0, 0, 0)
        format_row.setSpacing(4)
        self._load_simbrief_button = ActionButton(
            tr(self.language, 'simbrief_load_quick'), self._load_header_simbrief,
            icon='cloud',
        )
        self._load_simbrief_button.setObjectName('photosLoadSimBrief')
        self._load_simbrief_button.hide()
        self._simbrief_status = QLabel()
        self._simbrief_status.setObjectName('photosSimBriefRoute')
        self._simbrief_status.setTextFormat(Qt.TextFormat.PlainText)
        self._simbrief_status.setWordWrap(True)
        self._simbrief_status.setMinimumWidth(0)
        self._simbrief_status.setSizePolicy(
            QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Preferred,
        )
        self._simbrief_status.hide()
        self._simbrief_route = ''
        self._simbrief_row = QWidget()
        self._simbrief_row.setObjectName('photosSimBriefRow')
        simbrief_layout = QHBoxLayout(self._simbrief_row)
        simbrief_layout.setContentsMargins(0, 0, 0, 0)
        simbrief_layout.setSpacing(8)
        simbrief_layout.addWidget(
            self._load_simbrief_button, alignment=Qt.AlignmentFlag.AlignLeft,
        )
        simbrief_layout.addWidget(self._simbrief_status, stretch=1)
        self._simbrief_row.hide()
        self._order_button = ActionButton(
            "↓" if self._newest else "↑", self.toggle_order,
        )
        self._order_button.setProperty('compactIcon', True)
        self._order_button.setObjectName("photosOrderButton")
        self._sync_order_action()
        format_row.addWidget(self._order_button)
        self._format_actions = QWidget()
        self._format_actions.setObjectName("photosCardFormatStrip")
        self._format_actions.setAccessibleName(gettext('Photo card format'))
        format_layout = QGridLayout(self._format_actions)
        format_layout.setSizeConstraint(QLayout.SizeConstraint.SetNoConstraint)
        format_layout.setContentsMargins(0, 0, 0, 0)
        format_layout.setHorizontalSpacing(2)
        format_layout.setVerticalSpacing(2)
        format_layout.setAlignment(Qt.AlignmentFlag.AlignLeft | Qt.AlignmentFlag.AlignTop)
        self._format_group = QButtonGroup(self)
        self._format_group.setExclusive(True)
        self._format_buttons: dict[str, QToolButton] = {}
        for ratio in PHOTO_CARD_FORMATS:
            button = self._make_format_button(ratio, self._set_card_format, folders=False)
            self._format_group.addButton(button)
            self._format_buttons[ratio] = button
            format_layout.addWidget(button, 0, len(self._format_buttons) - 1)
        self._format_buttons[self._format].setChecked(True)
        self._format_notice = QLabel("!")
        self._format_notice.setObjectName("photosCardFormatNotice")
        self._format_notice.setAccessibleName(self._text("card_format_unsaved"))
        self._format_notice.setToolTip(self._text("card_format_unsaved"))
        self._format_notice.hide()
        format_layout.addWidget(self._format_notice, 0, len(PHOTO_CARD_FORMATS))
        self._format_actions.setSizePolicy(
            QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Preferred,
        )
        format_row.addWidget(self._format_actions, stretch=1)
        center_toolbar.addWidget(self._format_row, 0, 1)
        self._slideshow_button = ActionButton(
            tr(self.language, "linking_media_slideshow_start"),
            self.toggle_slideshow, icon="play", icon_only=True,
        )
        self._center_panel = QWidget()
        self._center_panel.setMinimumWidth(UI_LAYOUT_VIEWER_MINIMUM_WIDTH)
        self._center_panel.setObjectName('photosGalleryContent')
        self._center_panel.setStyleSheet(
            f'QWidget#photosGalleryContent {{ background: {PALETTE["card_surface"]}; }}',
        )
        self._center_layout = QVBoxLayout(self._center_panel)
        self._center_layout.setContentsMargins(*PHOTO_CARD_MARGINS)
        self._center_layout.addWidget(self._center_toolbar)
        self._center_layout.addWidget(self._simbrief_row)
        self._folder_split = QSplitter(Qt.Orientation.Vertical)
        self._folder_split.setChildrenCollapsible(False)
        self._folder_split.setOpaqueResize(False)
        self._folder_split.setStyleSheet(
            f'QSplitter::handle:vertical {{ background: {PALETTE["line"]}; }}',
        )
        self._center_layout.addWidget(self._folder_split, stretch=1)
        self._folder_section = QWidget()
        folder_layout = QVBoxLayout(self._folder_section)
        folder_layout.setContentsMargins(0, 0, 0, 0)
        self._folder_formats = QWidget()
        self._folder_formats.setObjectName('photosFolderFormats')
        self._folder_formats.setAccessibleName(self._text('folder_format_hint'))
        folder_formats = QGridLayout(self._folder_formats)
        folder_formats.setSizeConstraint(QLayout.SizeConstraint.SetNoConstraint)
        folder_formats.setContentsMargins(0, 0, 0, 0)
        folder_formats.setSpacing(2)
        folder_formats.setAlignment(Qt.AlignmentFlag.AlignLeft | Qt.AlignmentFlag.AlignTop)
        self._folder_formats.setSizePolicy(
            QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Fixed,
        )
        self._folder_order = ActionButton('↑', self._toggle_folder_order)
        self._folder_order.setProperty('compactIcon', True)
        self._folder_order.setObjectName('photosFolderOrder')
        folder_formats.addWidget(self._folder_order, 0, 0)
        self._folder_format_group = QButtonGroup(self)
        self._folder_format_buttons: dict[str, QToolButton] = {}
        for column, ratio in enumerate(PHOTO_CARD_FORMATS, start=1):
            button = self._make_format_button(ratio, self._set_folder_format, folders=True)
            self._folder_format_group.addButton(button)
            self._folder_format_buttons[ratio] = button
            folder_formats.addWidget(button, 0, column)
        folder_layout.addWidget(self._folder_formats)
        self._folder_shelf = QtFolderShelf(self._text('subgalleries'))
        self._folder_shelf.gallery_activated.connect(self._open_folder)
        self._folder_shelf.gallery_menu.connect(self._folder_context_menu)
        folder_layout.addWidget(self._folder_shelf, stretch=1)
        self._folder_split.addWidget(self._folder_section)
        self._photo_pane = QWidget()
        self._photo_pane_layout = QVBoxLayout(self._photo_pane)
        self._photo_pane_layout.setContentsMargins(0, 0, 0, 0)
        self._folder_split.addWidget(self._photo_pane)
        self._folder_split.setStretchFactor(1, 1)
        self._folder_split.splitterMoved.connect(self._remember_folder_split)
        divider = self._folder_split.handle(1)
        divider.setFocusPolicy(Qt.FocusPolicy.StrongFocus)
        divider.setAccessibleName(self._text('folder_split_hint'))
        divider.setToolTip(self._text('folder_split_hint'))
        self._folder_section.hide()
        self.playback = GalleryPlayback(self.language)
        self.playback.setObjectName("photosPlayback")
        self.playback.interval_changed.connect(self._presentation_interval)
        self._transport_row = QWidget()
        transport_layout = QHBoxLayout(self._transport_row)
        transport_layout.setContentsMargins(0, 0, 0, 0)
        transport_layout.addWidget(self._slideshow_button)
        transport_layout.addWidget(self.playback)
        transport_layout.addStretch()

        self._split = QSplitter()
        self._split.setChildrenCollapsible(False)
        self._gallery_panel = QFrame()
        self._gallery_panel.setObjectName("photosGalleryPanel")
        self._gallery_panel.setProperty("shellCard", True)
        gallery_layout = QVBoxLayout(self._gallery_panel)
        gallery_layout.setContentsMargins(*PHOTO_CARD_MARGINS)
        gallery_toolbar = QHBoxLayout()
        gallery_toolbar.setContentsMargins(0, 0, 0, 0)
        gallery_toolbar.addWidget(ActionButton(
            self._text("expand_all"), self._expand_galleries,
            icon="add", icon_only=True,
        ))
        gallery_toolbar.addWidget(ActionButton(
            self._text("collapse_all"), self._gallery_tree_collapse_all,
            icon="remove", icon_only=True,
        ))
        gallery_toolbar.addWidget(ActionButton(
            self._text("create_root"), lambda: self.create_gallery(choose_parent=False),
            icon="add", icon_only=True,
        ))
        gallery_toolbar.addWidget(ActionButton(
            self._text("refresh_galleries"), self.refresh_gallery_tree,
            icon="refresh", icon_only=True,
        ))
        gallery_toolbar.addStretch()
        gallery_layout.addLayout(gallery_toolbar)
        self._gallery_tree = QTreeWidget()
        self._gallery_tree.setObjectName("photosGalleryTree")
        self._gallery_tree.setIconSize(QSize(PHOTO_TREE_MARKER_SIZE, PHOTO_TREE_MARKER_SIZE))
        self._gallery_tree.setHeaderHidden(True)
        self._gallery_tree.currentItemChanged.connect(self._choose_gallery)
        self._gallery_tree.itemExpanded.connect(lambda item: self._set_gallery_expanded(item, True))
        self._gallery_tree.itemCollapsed.connect(lambda item: self._set_gallery_expanded(item, False))
        self._gallery_tree.setContextMenuPolicy(Qt.ContextMenuPolicy.CustomContextMenu)
        self._gallery_tree.customContextMenuRequested.connect(self._tree_context_menu)
        self._gallery_tree.hide()
        gallery_layout.addWidget(self._gallery_tree)
        self._gallery_panel.hide()
        self._split.addWidget(self._gallery_panel)
        self._inspector_panel = InspectorPane()
        inspector_layout = QVBoxLayout(self._inspector_panel.card)
        inspector_title = QHBoxLayout()
        self._inspector_heading = QLabel(self._text('photo_info'))
        self._inspector_heading.setObjectName('heading')
        self._inspector_heading.setTextFormat(Qt.TextFormat.PlainText)
        # File names must not contribute their intrinsic width to the splitter.
        self._inspector_heading.setSizePolicy(
            QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Preferred,
        )
        self._inspector_heading.setAccessibleName(self._text('photo_info'))
        inspector_title.addWidget(self._inspector_heading, stretch=1)
        inspector_title.addWidget(ActionButton(
            self._text('close_photo_info'), self._close_inspector,
            icon='close', icon_only=True,
        ))
        inspector_layout.addLayout(inspector_title)
        self._inspector_preview = InspectorPreview(self._text('photo_preview'))
        inspector_layout.addWidget(self._inspector_preview)
        self._inspector_summary = QLabel()
        self._inspector_summary.setObjectName('status')
        self._inspector_summary.setTextFormat(Qt.TextFormat.PlainText)
        self._inspector_summary.setWordWrap(True)
        inspector_layout.addWidget(self._inspector_summary)
        self._inspector = InspectorMetadata(
            self._text('photo_info'), self._text('show_full_name'), self._text('collapse_name'),
        )
        inspector_layout.addWidget(self._inspector, stretch=1)
        self._inspector_panel.hide()
        self._preload_row = QWidget()
        preload_actions = QHBoxLayout(self._preload_row)
        preload_actions.setContentsMargins(0, 0, 0, 0)
        self._preload_label = QLabel()
        self._preload_label.setTextFormat(Qt.TextFormat.PlainText)
        self._preload_label.setWordWrap(True)
        preload_actions.addWidget(self._preload_label, stretch=1)
        self._preload_stop = ActionButton(self._text('preload_cancel'), self._stop_preload)
        preload_actions.addWidget(self._preload_stop)
        self._preload_row.hide()
        layout.addWidget(self._preload_row)

        self._detail_controls = QWidget()
        self._detail_controls.setObjectName("photosLightboxOverlay")
        self._detail_controls.setAttribute(Qt.WidgetAttribute.WA_TranslucentBackground, True)
        self._detail_controls.setAutoFillBackground(False)
        detail_overlay = QVBoxLayout(self._detail_controls)
        detail_overlay.setContentsMargins(0, 0, 0, 0)
        self._detail_toolbar = QFrame()
        self._detail_toolbar.setObjectName("photosLightboxToolbar")
        self._detail_toolbar.setProperty("shellCard", True)
        detail_overlay.addWidget(self._detail_toolbar)
        detail_overlay.addStretch()
        card_layout = QVBoxLayout(self._detail_toolbar)
        card_layout.setContentsMargins(8, 5, 8, 4)
        card_layout.setSpacing(3)
        title_row = QHBoxLayout()
        title_row.setContentsMargins(0, 0, 0, 0)
        control_row = QHBoxLayout()
        control_row.setContentsMargins(0, 0, 0, 0)
        control_row.setSpacing(2)
        self._detail_toolbar.setStyleSheet(
            f'QFrame#photosLightboxToolbar {{ border: 1px solid {PALETTE["line"]}; }}'
            'QFrame#photosLightboxToolbar QPushButton[compactIcon="true"] { padding: 0; }',
        )
        card_layout.addLayout(title_row)
        card_layout.addLayout(control_row)
        self._detail_title_label = QLabel()
        self._detail_title_label.setObjectName("photosLightboxTitle")
        self._detail_title_label.setTextFormat(Qt.TextFormat.PlainText)
        self._detail_title_label.setMaximumWidth(240)
        self._detail_title_label.setTextInteractionFlags(
            Qt.TextInteractionFlag.TextSelectableByMouse,
        )
        title_row.addWidget(self._detail_title_label, stretch=1)
        self._detail_position_label = QLabel()
        self._detail_position_label.setObjectName("photosLightboxPosition")
        title_row.addWidget(self._detail_position_label)
        self._detail_previous_button = ActionButton(
            tr(self.language, "linking_media_previous"), lambda: self.stepPhoto(-1),
            icon="back", icon_only=True,
        )
        self._detail_next_button = ActionButton(
            tr(self.language, "linking_media_next"), lambda: self.stepPhoto(1),
            icon="next", icon_only=True,
        )
        self._detail_edges = (self._detail_previous_button, self._detail_next_button)
        for edge in self._detail_edges:
            edge.setProperty('lightboxEdge', True)
        control_row.addWidget(ActionButton(
            tr(self.language, "linking_media_zoom_out"), self.zoomOutRequested.emit,
            icon="zoom_out", icon_only=True,
        ))
        self._detail_fit_button = ActionButton(
            tr(self.language, "linking_media_fit_content"),
            self.toggle_content_crop,
            icon="fit", icon_only=True,
        )
        control_row.addWidget(self._detail_fit_button)
        control_row.addWidget(ActionButton(
            tr(self.language, "linking_media_zoom_in"), self.zoomInRequested.emit,
            icon="zoom_in", icon_only=True,
        ))
        self._detail_fullscreen_button = ActionButton(
            tr(self.language, "linking_media_fullscreen"), self.toggle_fullscreen,
            icon="fullscreen", icon_only=True,
        )
        control_row.addWidget(self._detail_fullscreen_button)
        self._detail_location_button = ActionButton(
            tr(self.language, "linking_media_location"),
            self._show_location_menu, icon="location", icon_only=True,
        )
        self._detail_location_button.setObjectName('lightboxLocation')
        control_row.addWidget(self._detail_location_button)
        control_row.addWidget(ActionButton(
            tr(self.language, "linking_media_close"), self.dismissPhoto,
            icon="close", icon_only=True,
        ))
        for button in self.playback.lightbox_controls():
            control_row.addWidget(button)
        self._detail_controls.hide()
        self._detail_controls.setFocusPolicy(Qt.FocusPolicy.NoFocus)
        for button in (*self._detail_controls.findChildren(QPushButton), *self._detail_edges):
            button.setFocusPolicy(Qt.FocusPolicy.TabFocus)
            button.setFixedSize(LIGHTBOX_ACTION_EDGE, LIGHTBOX_ACTION_EDGE)

        self.progress = QProgressBar()
        self.progress.setTextVisible(False)
        self.progress.setAccessibleName(gettext("Photos loading progress"))
        self.progress.hide()
        self.status = QLabel(gettext("Link a gallery or open local photos."))
        self.status.setWordWrap(True)
        self.status.setTextFormat(Qt.TextFormat.PlainText)
        self.status.setObjectName("status")
        status_row = QWidget()
        status_layout = QHBoxLayout(status_row)
        status_layout.setContentsMargins(0, 0, 0, 0)
        status_layout.addWidget(self.status, stretch=1)
        self._link_cancel_button = ActionButton(
            gettext('Cancel'), self.cancel_authorization, icon='close',
        )
        self._link_cancel_button.setObjectName('photosLinkCancelButton')
        self._link_cancel_button.hide()
        status_layout.addWidget(self._link_cancel_button)

        self.quick = QQuickWidget()
        self.quick.setResizeMode(QQuickWidget.ResizeMode.SizeRootObjectToView)
        self.quick.setClearColor(QColor(PALETTE["card_surface"]))
        self.quick.setFocusPolicy(Qt.FocusPolicy.StrongFocus)
        self.quick.setMouseTracking(True)
        self.quick.installEventFilter(self)
        loading_icon = qt_icon('refresh', tone='focus').pixmap(
            QSize(ACTION_ICON_SIZE, ACTION_ICON_SIZE), self.devicePixelRatioF(),
        )
        self.textures.store('ui/thumbnail-loading', loading_icon.toImage())
        self.quick.engine().addImageProvider("photos", self.textures)
        context = self.quick.rootContext()
        context.setContextProperty("desktopColors", PALETTE)
        context.setContextProperty("photoModel", self.model)
        context.setContextProperty("photoActions", self)
        self.quick.setSource(QUrl.fromLocalFile(str(_SCENE)))
        if self.quick.status() != QQuickWidget.Status.Ready:
            raise RuntimeError("Qt Photos scene did not initialize.")
        self._hdr_surface = MacQtHDRSurface(self.quick)
        self._detail_controls.setParent(self.quick)
        self._detail_controls.installEventFilter(self)
        for button in self._detail_edges:
            button.setParent(self.quick)
            button.installEventFilter(self)
            button.hide()
        for child in self._detail_controls.findChildren(QWidget):
            child.installEventFilter(self)
        self._detail_controls.raise_()
        self._photo_pane_layout.addWidget(self.quick, stretch=1)
        self._photo_pane_layout.addWidget(self._transport_row)
        self._empty_gallery_message = QLabel(tr(self.language, 'linking_media_empty'))
        self._empty_gallery_message.setObjectName('photosEmptyGalleryMessage')
        self._empty_gallery_message.setTextFormat(Qt.TextFormat.PlainText)
        self._empty_gallery_message.setWordWrap(True)
        self._empty_gallery_message.hide()
        self._photo_pane_layout.addWidget(self._empty_gallery_message)
        self._split.addWidget(self._center_panel)
        self._split.addWidget(self._inspector_panel)
        self._split.setStretchFactor(1, 1)
        self._split.splitterMoved.connect(self._remember_pane_widths)
        layout.addWidget(self._split, stretch=1)
        layout.addWidget(self.progress)
        layout.addWidget(status_row)
        self._format_actions.installEventFilter(self)
        self._center_toolbar.installEventFilter(self)
        self._inspector_heading.installEventFilter(self)
        self._split.installEventFilter(self)
        self._folder_formats.installEventFilter(self)
        self._folder_split.installEventFilter(self)
        divider.installEventFilter(self)
        self._sync_connection_state()
        self.detailChanged.connect(self._sync_lightbox_controls)
        self.detailChanged.connect(self._sync_capture_pulse)
        application = QApplication.instance()
        if isinstance(application, QApplication):
            application.applicationStateChanged.connect(self._sync_capture_pulse)

    def disconnect(self) -> None:
        """Retire current authorization and clear its model and scene references.

        Returns:
            None: Cancels all reads without resuming or changing stored grants.
        """
        if not self._can_disconnect():
            return
        self._detach_current_site()


    @Property(bool, notify=photosActiveChanged)
    def photosActive(self) -> bool:
        """Report whether the Photos workspace is currently presented.

        Returns:
            bool: True while its parent Qt surface is visible.
        """
        return self._photos_active

    @Property(str, notify=detailChanged)
    def detailPosition(self) -> str:
        """Return the ordinal for the last accepted Lightbox image frame.

        Returns:
            str: One-based current position over the loaded album, or empty.
        """
        index = self._detail_display_index
        if index is None:
            return ""
        return f"{index + 1} / {len(self.model.rows)}"

    @Property(bool, notify=detailChanged)
    def detailOpen(self) -> bool:
        """Return whether the Lightbox owns the scene's keyboard and pixels.

        Returns:
            bool: True while a current detail request is displayed or pending.
        """
        return self._detail_index is not None

    @Property(str, notify=detailChanged)
    def detailUrl(self) -> str:
        """Expose the last valid retained detail texture.

        Returns:
            str: Current provider URL, or empty before the first image is ready.
        """
        return self._detail_url

    @Property(str, notify=detailChanged)
    def detailTitle(self) -> str:
        """Expose the last shown image's accessible name.

        Returns:
            str: Title of the retained detail frame.
        """
        return self._detail_title

    @Property(str, notify=detailChanged)
    def detailFeedback(self) -> str:
        """Expose the current Lightbox load or failure text to the retained scene.

        Returns:
            str: Localized progress or error text, or empty after successful publication.
        """
        return self._detail_feedback

    @Property(bool, notify=detailChanged)
    def detailFailed(self) -> bool:
        """Identify the active detail request's error treatment.

        Returns:
            bool: True after a current detail failed while the retained frame stayed valid.
        """
        return self._detail_failed

    @Property(bool, notify=detailChanged)
    def detailFullscreen(self) -> bool:
        """Expose the same Lightbox fit mode as the containing native window.

        Returns:
            bool: True when fullscreen fits the raster to the entire viewport.
        """
        return self._fullscreen

    @Property(bool, notify=detailChanged)
    def detailHasContentCrop(self) -> bool:
        """Report whether the current detail has confirmed matte bounds.

        Returns:
            bool: True only when the worker proved a non-empty content crop.
        """
        return self._detail_crop_rect is not None

    @Property(bool, notify=detailChanged)
    def contentCropEnabled(self) -> bool:
        """Report whether the Lightbox currently shows detected content bounds.

        Returns:
            bool: True while a confirmed matte crop is applied to the image.
        """
        return self._detail_content_crop_enabled

    @Property(QRectF, notify=detailChanged)
    def detailCropRect(self) -> QRectF:
        """Return normalized content bounds in the retained detail raster.

        Returns:
            QRectF: Normalized left, top, width and height, or the full frame.
        """
        if self._detail_crop_rect is None:
            return QRectF(0.0, 0.0, 1.0, 1.0)
        return QRectF(self._detail_crop_rect)

    @Property(QRectF, notify=detailChanged)
    def hdrImageRect(self) -> QRectF:
        """Return the transformed image rectangle currently visible in QML.

        Returns:
            QRectF: Viewport-relative image bounds including zoom and pan.
        """
        return QRectF(self._hdr_image_rect)

    @hdrImageRect.setter
    def hdrImageRect(self, geometry: QRectF) -> None:
        """Synchronize native EDR placement with the retained QML image.

        Args:
            geometry (QRectF): QML image bounds after Flickable translation.
        Returns:
            None: Updates the optional native overlay or leaves SDR visible.
        """
        self._hdr_image_rect = QRectF(geometry)
        self._hdr_surface.set_geometry(self._hdr_image_rect)

    @Slot()
    def detailImageReady(self) -> None:
        """Restore EDR only after QML accepts the matching original frame.

        Returns:
            None: Cropped SDR remains visible until the full source is ready.
        """
        if (self._hdr_restore_pending and not self._detail_content_crop_enabled
                and self._detail_hdr_source is not None):
            self._hdr_restore_pending = False
            self._hdr_surface.present(self._detail_hdr_source, self._hdr_image_rect)

    @Slot()
    def toggle_content_crop(self) -> None:
        """Toggle confirmed content bounds and keep cropped HDR on SDR fallback.

        Returns:
            None: QML retains the complete QImage and only changes its source clip.
        """
        if not self.detailHasContentCrop:
            self.zoomFitRequested.emit()
            return
        self._detail_content_crop_enabled = not self._detail_content_crop_enabled
        if self._detail_content_crop_enabled:
            # The native layer currently renders the full CI extent. Retire it
            # while QML clips the SDR preview so no mismatched EDR rectangle shows.
            self._hdr_restore_pending = False
            self._hdr_surface.clear()
        else:
            self._hdr_restore_pending = True
        self._sync_content_crop_action()
        self.detailChanged.emit()
        self.zoomFitRequested.emit()

    @Property(int, notify=formatChanged)
    def tileWidth(self) -> int:
        """Expose the reference image width for landscape and portrait cards.

        Returns:
            int: Logical raster width from the same sizing policy as Tk.
        """
        return gallery_card_dimensions(self._format)[0]

    @Property(int, notify=formatChanged)
    def tileHeight(self) -> int:
        """Expose the reference image height in the retained grid.

        Returns:
            int: Logical raster height from the same sizing policy as Tk.
        """
        return gallery_card_dimensions(self._format)[1]

    @Property(bool, notify=formatChanged)
    def localPhotoCaptions(self) -> bool:
        """Keep filenames visible in the explicitly selected local preview mode.

        Returns:
            bool: True for local files. Linked tiles follow the image-only reference.
        """
        return self._local_photos_active

    @Property(bool, notify=formatChanged)
    def cropTiles(self) -> bool:
        """Tell the scene whether to fill the explicitly proportioned card.

        Returns:
            bool: Explicit formats crop at presentation without decoding anew.
        """
        return self._format != "default"

    @Slot(int)
    def set_batch_limit(self, size: int) -> None:
        """Update the next network group after the Settings owner saves its limit.

        Args:
            size (int): Newly saved steady request limit from Settings.
        Returns:
            None: Pending work still retains its previously admitted bound.
        """
        self._batch_limit = size

    @Slot(int, int, int)
    def photoMenu(self, index: int, x: int, y: int) -> None:
        """Anchor current row actions at the scene's triggered tile.

        Args:
            index (int): Current logical photo row.
            x (int): Trigger's logical x in the embedded scene.
            y (int): Trigger's logical y in the embedded scene.
        Returns:
            None: Shows only actions that are applicable to the exact descriptor.
        """
        if self.detailOpen or not 0 <= index < len(self.model.rows):
            return
        session, gallery = self._session, self._rendered_gallery
        photo = self.model.rows[index].source
        if (session is None or gallery is None or gallery != self._gallery_id
                or not isinstance(photo, LinkedPhoto) or not session.available()):
            return
        selected = photo.image_id in self.model.selected
        toggle = 'deselect_photo' if selected else 'select_photo'
        anchor = QPoint(x, y)
        actions: list[tuple[str, Callable[[], None]]] = [
            (self._text(toggle), lambda: self.choosePhoto(index, True, False)),
        ]
        writable = not self.busy() and not self.transfer_busy()
        if writable and session.can_delete_admin_media():
            ids = tuple(row.identity for row in self.model.rows if row.identity in self.model.selected)
            image_ids = ids if selected else (photo.image_id,)
            if 0 < len(image_ids) <= NATIVE_MEDIA_DELETE_BATCH_MAX:
                label = self._text('delete_selected').format(count=len(ids)) if selected else self._text('delete_photo')
                actions.append((label, lambda: self._confirm_photo_delete(
                    session, gallery, image_ids, anchor,
                )))
        actions.append((
            self._text('photo_info'), lambda: self._request_information(session, gallery, photo.image_id),
        ))
        if session.can_create_gallery():
            actions.append(('+ ' + self._text('create_child'), lambda: self.create_gallery(
                parent_id=gallery, choose_parent=False,
            )))
        if writable and photo.visibility is not None and session.can_change_photo_visibility():
            target = 'private' if photo.visibility == 'public' else 'public'
            actions.append((
                '👁 ' + self._text('visibility_' + target),
                lambda: self._change_photo_visibility(session, gallery, photo, target),
            ))
        elif not session.can_read_admin_media():
            actions.append((self._text('approve_admin'), self.approve_admin))

        def current() -> bool:
            """Check that the opened card still refers to the same physical photo.

            Returns:
                bool: The original item, owner and current Photos surface agree.
            """
            return (
                self.isVisible() and not self.detailOpen
                and gallery == self._rendered_gallery == self._gallery_id
                and self._owns_gallery(session, gallery)
                and self.model.index_by_id.get(photo.image_id) == index
                and self.model.rows[index].source is photo
            )

        self._open_photo_context(self._text('photo_menu'), anchor, actions, current)

    @Slot(int, int)
    def gallerySpaceMenu(self, x: int, y: int) -> None:
        """Offer creation at the exact gallery's empty photo-grid space.

        Args:
            x (int): Trigger's logical x in the embedded scene.
            y (int): Trigger's logical y in the embedded scene.
        Returns:
            None: Opens only the applicable creation or approval action.
        """
        session, gallery = self._session, self._gallery_id
        if session is None or gallery is None or self.detailOpen or not self._owns_gallery(session, gallery):
            return
        title = self._text('subgallery_menu')
        if session.can_create_gallery():
            actions = [('+ ' + self._text('create_child'), lambda: self.create_gallery(
                parent_id=gallery, choose_parent=False,
            ))]
        else:
            title += '\n' + self._text('create_requires_approval')
            actions = [(self._text('approve_admin'), self.approve_admin)]
        self._open_photo_context(title, QPoint(x, y), actions, lambda: (
            self.isVisible() and not self.detailOpen and gallery == self._gallery_id
            and self._owns_gallery(session, gallery)
        ))

    @Slot(int)
    def focusedPhoto(self, index: int) -> None:
        """Keep the stable photo identity for keyboard focus on a later restore.

        Args:
            index (int): Current Quick grid index, negative when there is no row.
        Returns:
            None: Saves only the active catalogue's photo identity.
        """
        self._focused = self.model.rows[index].identity if 0 <= index < len(self.model.rows) else None

    @Slot(int, int)
    def viewport(self, first: int, after_last: int) -> None:
        """Receive a visible row interval without issuing I/O in a Qt binding.

        Args:
            first (int): First intersecting model index.
            after_last (int): One past the last intersecting model index.
        Returns:
            None: Schedules one coalesced viewport update.
        """
        if not self._photos_active:
            return
        wanted = (max(0, first), max(0, first, after_last))
        if wanted != self._requested:
            self._requested = wanted
            if not self._viewport_timer.isActive():
                self._viewport_timer.start()

    @Slot(int, bool, bool)
    def choosePhoto(self, index: int, toggle: bool, extend: bool) -> None:
        """Select a logical row using standard click and range modifiers.

        Args:
            index (int): Visible scene model index.
            toggle (bool): Toggle the row while retaining other selected IDs.
            extend (bool): Extend selection from the established anchor.
        Returns:
            None: Updates selection overlays and the plain-text inspector.
        """
        if not 0 <= index < len(self.model.rows):
            return
        row = self.model.rows[index]
        identities = set(self.model.selected)
        if extend and self._anchor in self.model.index_by_id:
            begin, end = sorted((index, self.model.index_by_id[self._anchor]))
            identities.update(photo.identity for photo in self.model.rows[begin:end + 1])
        elif toggle:
            identities.symmetric_difference_update((row.identity,))
            self._anchor = row.identity
        else:
            identities = {row.identity}
            self._anchor = row.identity
        if len(identities) > NATIVE_MEDIA_DELETE_BATCH_MAX:
            self.status_message(tr(self.language, 'linking_media_selection_limit').format(
                limit=NATIVE_MEDIA_DELETE_BATCH_MAX,
            ))
            return
        self.model.select(identities)
        session, gallery = self._session, self._rendered_gallery
        selected = self.model.index_by_id.get(next(iter(identities))) if len(identities) == 1 else None
        if session is not None and gallery is not None and selected is not None:
            self._request_information(session, gallery, self.model.rows[selected].identity)
        else:
            self._dismiss_information()

    @Slot(int)
    def openPhoto(self, index: int) -> None:
        """Open a grid photo and retain selection while navigating the Lightbox.

        Args:
            index (int): Current logical photo index.
        Returns:
            None: Selects an unselected grid entry on first open and requests
                its detailed rendition; Lightbox navigation keeps the grid set.
        """
        if not 0 <= index < len(self.model.rows):
            return
        row = self.model.rows[index]
        if not self.detailOpen and row.identity not in self.model.selected:
            self.choosePhoto(index, False, False)
        if self._detail_menu is not None:
            self._detail_menu.close()
        self._dismiss_photo_context()
        self._dismiss_information()
        self._slideshow.stop()
        self._cancel_thumbnail_group()
        self._detail_index = index
        self._detail_feedback = gettext("Loading photo…")
        self._detail_failed = False
        self._hdr_surface.clear()
        session, gallery = self._session, self._gallery_id
        self._detail_controls.show()
        self._layout_lightbox_controls()
        self.quick.setFocus(Qt.FocusReason.OtherFocusReason)
        self.detailChanged.emit()
        self._details.submit(lambda cancel, publish: publish(
            read_photo(row, session, gallery, cancel, thumbnail=False),
        ))
        self._update_preload_priority()

    @Slot(int)
    def stepPhoto(self, offset: int) -> None:
        """Request adjacent gallery details using the existing logical order.

        Args:
            offset (int): Signed navigation step from keyboard or toolbar.
        Returns:
            None: Wraps the already verified catalogue at either boundary.
        """
        if self._detail_index is not None and len(self.model.rows) > 1:
            self.openPhoto((self._detail_index + offset) % len(self.model.rows))

    @Slot(name="toggleFullscreen")
    def toggle_fullscreen(self) -> None:
        """Toggle fullscreen while remembering the prior desktop window state.

        Returns:
            None: Retains the same scene, selection and current texture.
        """
        window = self.window()
        if self._detail_menu is not None:
            self._detail_menu.close()
        if self._fullscreen:
            if self._was_maximized:
                window.showMaximized()
            else:
                window.showNormal()
        else:
            self._was_maximized = window.isMaximized()
            window.showFullScreen()
        self._fullscreen = not self._fullscreen
        if not self._fullscreen:
            self._lightbox_controls_timer.stop()
        self._show_lightbox_controls()
        self.detailChanged.emit()

    @Slot()
    def dismissPhoto(self) -> None:
        """Close the current detail without accepting a late worker result.

        Returns:
            None: Releases retained detail slots and returns focus to the grid.
        """
        self.stop_slideshow()
        if self._detail_index is None:
            return
        if self._detail_menu is not None:
            self._detail_menu.close()
        self._lightbox_controls_timer.stop()
        self._lightbox_controls_visible = True
        self._dismiss_information()
        if self._fullscreen:
            self.toggle_fullscreen()
        selected = self._detail_index
        self._details.cancel()
        self._detail_index = None
        self._detail_display_index = None
        self._detail_title = ""
        self._detail_feedback = ''
        self._detail_failed = False
        self._detail_hdr_source = None
        self._detail_crop_rect = None
        self._detail_content_crop_enabled = False
        self._hdr_restore_pending = False
        self._hdr_surface.clear()
        self._detail_url = ""
        self._detail_controls.hide()
        self.detailChanged.emit()
        for key in self._detail_keys:
            self.textures.discard(key)
        self._detail_keys = ()
        self.progress.hide()
        self.status.clear()
        self.scrollTo.emit(selected)
        self.quick.setFocus(Qt.FocusReason.OtherFocusReason)
        self._viewport_timer.start()

    @Slot(name='escapeLightbox')
    def escape_lightbox(self) -> None:
        """Dismiss only the innermost active Lightbox surface on Escape.

        Returns:
            None: The menu and fullscreen exits preserve the current frame.
        """
        if self._detail_menu is not None:
            self._detail_menu.close()
        elif self._fullscreen:
            self.toggle_fullscreen()
        else:
            self.dismissPhoto()

    @Slot()
    def lightboxPointerMoved(self) -> None:
        """Reveal fullscreen controls after native Quick pointer movement.

        Returns:
            None: Pointer activity resets only the compact control idle timer.
        """
        if self._fullscreen and self.detailOpen:
            self._show_lightbox_controls()

    def resizeEvent(self, event: QResizeEvent) -> None:
        """Place an open action card within the resized native workspace.

        Args:
            event (QResizeEvent): Geometry change delivered to Photos.
        Returns:
            None: Prevents an anchored surface from being clipped by its owner.
        """
        super().resizeEvent(event)
        self._position_photo_context()


    def eventFilter(self, watched: QObject, event: QEvent) -> bool:
        """Route contextual focus and keyboard navigation from the scene host.

        Args:
            watched (QObject): Watched action, scene, resize owner or focused control.
            event (QEvent): Native key, pointer, focus or geometry request.
        Returns:
            bool: True only when Photos handled this event.
        """
        card = self._photo_context
        if card is not None:
            inside = isinstance(watched, QWidget) and (watched is card or card.isAncestorOf(watched))
            if event.type() == QEvent.Type.MouseButtonPress and isinstance(watched, QWidget) and not inside:
                self._dismiss_photo_context()
            elif event.type() == QEvent.Type.WindowDeactivate and watched is self.window():
                self._dismiss_photo_context()
            elif (inside and event.type() == QEvent.Type.KeyPress
                  and isinstance(event, QKeyEvent) and event.key() == Qt.Key.Key_Escape):
                self._dismiss_photo_context(restore_focus=True)
                return True
        if watched is self._format_actions:
            if event.type() == QEvent.Type.Resize:
                self._layout_format_buttons()
            return super().eventFilter(watched, event)
        if watched is self._center_toolbar:
            if event.type() == QEvent.Type.Resize:
                self._layout_gallery_heading()
            return super().eventFilter(watched, event)
        if watched is self._inspector_heading:
            if event.type() in (QEvent.Type.Resize, QEvent.Type.FontChange):
                self._layout_inspector_heading()
            return super().eventFilter(watched, event)
        if watched is self._split:
            if event.type() == QEvent.Type.Resize:
                self.restore_inspector_width(self._inspector_width)
            return super().eventFilter(watched, event)
        if watched is self._folder_formats:
            if event.type() == QEvent.Type.Resize:
                self._layout_folder_formats()
                self._layout_folder_split()
            return super().eventFilter(watched, event)
        if watched is self._folder_split:
            if event.type() == QEvent.Type.Resize:
                self._layout_folder_split()
            return super().eventFilter(watched, event)
        if (watched is self._folder_split.handle(1) and self._using_folder_section
                and event.type() == QEvent.Type.KeyPress and isinstance(event, QKeyEvent)):
            key = event.key()
            if key == Qt.Key.Key_Home:
                self._save_folder_split(None)
                return True
            if key in (Qt.Key.Key_Up, Qt.Key.Key_Down):
                upper, lower = self._folder_split.sizes()
                if upper + lower > 0:
                    share = round(upper * GALLERY_FOLDER_SHARE_SCALE / (upper + lower))
                    step = -FOLDER_KEY_STEP if key == Qt.Key.Key_Up else FOLDER_KEY_STEP
                    self._save_folder_split(share + step)
                return True
        lightbox_control = (
            isinstance(watched, QWidget) and (
                watched in self._detail_edges or watched is self._detail_controls
                or self._detail_controls.isAncestorOf(watched)
            )
        )
        if self.detailOpen and (watched is self.quick or lightbox_control):
            if watched is self.quick and event.type() == QEvent.Type.Resize:
                self._layout_lightbox_controls()
                return super().eventFilter(watched, event)
            if watched is self.quick and isinstance(event, QContextMenuEvent):
                position = (
                    event.globalPos() if event.reason() == QContextMenuEvent.Reason.Mouse
                    else self.quick.mapToGlobal(self.quick.rect().center())
                )
                self._show_lightbox_controls()
                self._show_lightbox_context(position)
                event.accept()
                return True
            if event.type() in (QEvent.Type.MouseMove, QEvent.Type.FocusIn):
                if self._fullscreen:
                    self._show_lightbox_controls()
                return super().eventFilter(watched, event)
            if event.type() == QEvent.Type.KeyPress and isinstance(event, QKeyEvent):
                if self._fullscreen:
                    self._show_lightbox_controls()
                key = event.key()
                if key in (Qt.Key.Key_Plus, Qt.Key.Key_Equal):
                    self.zoomInRequested.emit()
                elif key == Qt.Key.Key_Minus:
                    self.zoomOutRequested.emit()
                elif key == Qt.Key.Key_0:
                    self.zoomFitRequested.emit()
                elif key == Qt.Key.Key_Escape:
                    self.escape_lightbox()
                elif key == Qt.Key.Key_Left:
                    self.stepPhoto(-1)
                elif key == Qt.Key.Key_Right:
                    self.stepPhoto(1)
                elif key in (Qt.Key.Key_F, Qt.Key.Key_F11):
                    self.toggle_fullscreen()
                else:
                    return super().eventFilter(watched, event)
                event.accept()
                return True
        return super().eventFilter(watched, event)


    def hideEvent(self, event: QHideEvent) -> None:
        """Suspend speculative thumbnail reads when this workspace is hidden.

        Args:
            event (QHideEvent): Qt hide notification from the workspace owner.
        Returns:
            None: Auth, detail reads, explicit preload, transfers and retained
                valid textures keep their existing owners.
        """
        super().hideEvent(event)
        self._capture_pulse_timer.stop()
        self._cache_probe.cancel()
        self._cache_probe_key = None
        self._dismiss_photo_context()
        if self._active_gallery_menu is not None:
            self._active_gallery_menu.close()
        self.playback.close_panel()
        if self._photos_active:
            self._photos_active = False
            self.photosActiveChanged.emit()
        if not self.detailOpen:
            self._viewport_timer.stop()
            self._cancel_thumbnail_group()
            self.progress.hide()
        self._sync_simbrief_grid_action()


    def showEvent(self, event: QShowEvent) -> None:
        """Resume bounded viewport reads when the workspace becomes visible.

        Args:
            event (QShowEvent): Qt show notification from the workspace owner.
        Returns:
            None: Re-admits visible thumbnails while retaining all valid pixels.
        """
        super().showEvent(event)
        if not self._photos_active:
            self._photos_active = True
            self.photosActiveChanged.emit()
        if not self.detailOpen:
            self._viewport_timer.start()
        if self._tree_order and self._gallery_id is not None:
            self._queue_gallery_cache_probe(self._tree_order)
        self._sync_capture_pulse()
        self._sync_simbrief_grid_action()
