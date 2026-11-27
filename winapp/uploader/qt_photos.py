# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_photos.py
# Module Type: Qt Photos workspace
# Purpose: Browse authorized or explicitly selected photos in the retained scene.
# Responsibilities: Own current gallery, viewport admission, selection,
#   progressive thumbnail publication, lightbox and explicit gallery authorization.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Connect the Qt Quick scene to cancellable Photos operations."""

from collections import OrderedDict
from dataclasses import dataclass, field, replace
from html import escape
import logging
from math import isfinite, prod
from pathlib import Path
import sys
from threading import Event
import time
from typing import Callable
from uuid import UUID, uuid4
import webbrowser

from PySide6.QtCore import (
    QEvent, QModelIndex, QObject, QPoint, Property, QRectF, QSignalBlocker, QSize,
    QSortFilterProxyModel, Qt, QTimer, QUrl, Signal, Slot,
)
from PySide6.QtGui import (
    QAction, QColor, QContextMenuEvent, QHideEvent, QIcon, QKeyEvent, QResizeEvent, QShowEvent,
)
from PySide6.QtQuickWidgets import QQuickWidget
from PySide6.QtWidgets import (
    QApplication, QButtonGroup, QCheckBox, QComboBox, QDialog, QFileDialog,
    QGridLayout, QHBoxLayout, QInputDialog, QLayout,
    QLabel, QLineEdit, QFrame, QMenu, QMessageBox, QPlainTextEdit, QProgressBar,
    QPushButton, QSizePolicy, QSplitter, QToolButton, QTreeView,
    QTreeWidget, QTreeWidgetItem,
    QVBoxLayout, QWidget,
)

from .app_linking import (
    GALLERIES_SCOPE, LINKING_KEEPALIVE_SECONDS, LINKING_RECOVERY_MAX_FILE_BYTES,
    MEDIA_RECENT_ORDER_PHOTO_LIMIT,
    NATIVE_MEDIA_DELETE_BATCH_MAX, UPLOAD_SCOPE, LinkedGallery, LinkedMediaDetails,
    LinkedPhoto, LinkingAttempt, LinkingError, LinkingReconnectRequired,
    LinkingRefreshRejected, LinkingResponseError, LinkingSession,
    LinkingThumbnailProofChanged, LinkingUnavailable,
    discover_linking_capabilities,
    linking_issuer, linking_prompt_issuer, requested_linking_scope, scope_base,
)
from .config import load_thumbnail_batch_size, load_ui_language
from .credential_store import CredentialStoreUnavailable, NativeCredentialStore
from .desktop_connection import (
    ConnectionActions, DesktopLinkedSite, LinkedRecoveryActions, desktop_upload_vault,
    find_live_link, forget_browser_upload_grant, gallery_editor_url,
    live_link_sites, open_saved_upload_session, persist_browser_gallery_grant,
    persist_browser_scope_upgrade, persist_browser_upload_grant,
    remember_desktop_transient_link, restore_desktop_gallery_link,
    revoke_saved_gallery_grant, saved_desktop_gallery_binding, verify_live_gallery_activity,
)
from .diagnostics import format_safe_photos_failure
from .gallery_information import (
    PHOTOS_INFO_CACHE_ENTRY_LIMIT, PHOTOS_INFO_CACHE_FRESH_SECONDS,
    InformationGroup, format_gallery_bytes, photo_information_groups, thumbnail_quality_caption,
)
from .gallery_preload import (
    CacheProbeState, GalleryCacheProbeRow, GalleryPreloadWorker,
    probe_gallery_cache_status,
)
from .gallery_image_policy import (
    MEDIA_HTTP_BATCH_INITIAL_SIZE, MEDIA_SLIDESHOW_DEFAULT_SECONDS,
    MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES, MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS,
    MEDIA_THUMBNAIL_SIZE, MEDIA_VIEWPORT_COALESCE_MS,
)
from .gallery_view_preferences import (
    GALLERY_FOLDER_SHARE_BOUNDS, GALLERY_FOLDER_SHARE_SCALE, PHOTO_CARD_FORMATS,
    PhotosTreePreferences, gallery_card_dimensions, load_gallery_card_format,
    load_gallery_folder_split, load_gallery_photo_order, load_photos_tree_preferences,
    photos_account_tree_path, save_gallery_card_format, save_gallery_folder_split,
    save_gallery_photo_order, save_photos_tree_preferences,
)
from .flight_report_translations import tr
from .localization import gettext, gettext_noop
from .linked_create import BoundCreateReceipt, GalleryCreateJournal
from .linked_upload import LinkedUploadTarget, verify_linked_upload_target
from .offline_linking import (
    OfflineBinding, enable_rolling_renewal, load_offline_binding,
    remove_offline_binding, save_offline_binding, saved_offline_hint_path,
    store_approved_refresh,
)
from .qt_folder_shelf import QtFolderShelf
from .qt_gallery_editor import QtGalleryEditor
from .qt_photo_inspector import (
    INSPECTOR_PREVIEW_PIXELS, InspectorMetadata, InspectorPane, InspectorPreview,
)
from .qt_photo_model import PhotoListModel, PhotoTextures
from .qt_gallery_playback import GalleryPlayback
from .qt_hdr_macos import MacQtHDRSurface
from .qt_photo_source import (
    PhotoFrame, PhotoRow, ThumbnailActivity, ThumbnailEvent, ThumbnailGroup,
    ThumbnailProofPage, read_gallery_photos, read_gallery_tree, read_photo,
    stream_revalidated_thumbnails, stream_thumbnails,
)
from .qt_read_lane import QtReadLane, ReadCancelled
from .qt_theme import ACTION_ICON_SIZE, ActionButton, RaisedSurface, ToggleSwitch, qt_icon
from .session_resume import delete_transient, load_transient
from .state_store import (
    RECENT_GALLERY_ADDRESS_LIMIT, load_recent_gallery_addresses,
    save_recent_gallery_addresses,
)
from .upload_mode import ModeRecord
from .ui_layout import (
    DESKTOP_DIALOG_MARGIN, DESKTOP_DIALOG_SIZE, LINKING_ADDRESS_VISIBLE_ROWS, PHOTOS_HEADING_STACK_WIDTH,
    PHOTOS_HEADING_TITLE_MINIMUM, PHOTOS_INFO_PANE_WIDTH, PHOTOS_TREE_WIDTH,
    SHELL_ICON_BUTTON_EDGE, UI_LAYOUT_INFO_WIDTH_BOUNDS,
    UI_LAYOUT_VIEWER_MINIMUM_WIDTH,
)
from .ui_palette import PALETTE, PHOTOS_CARD_FORMAT_ICON_SIZE, UI_PHOTO_FORMAT_LABEL_POINTS

_SCENE = Path(__file__).with_name("resources") / "qml" / "Photos.qml"

# Purpose: Sample the existing preload worker without polling the network.
# Type: int. Units: milliseconds. Scope: one active Photos preload.
# Consumers: QtPhotos._preload_timer.
# Rationale: 150 keeps counts legible and detects terminal changes promptly.
PRELOAD_STATUS_POLL_MS = 150

# Purpose: Account for Qt's largest decoded thumbnail format.
# Type: int. Units: bytes per pixel. Scope: Qt Photos viewport capacity.
# Consumers: VIEWPORT_TEXTURE_LIMIT. Rationale: 16 covers four 32-bit
# floating-point channels in QImage's RGBA32FPx4 formats.
QIMAGE_MAX_BYTES_PER_PIXEL = 16
# Purpose: Bound the active texture window from the existing decoded-cache budget.
# Type: int. Units: thumbnails. Scope: one Qt Photos viewport.
# Consumers: QtPhotos._apply_viewport. Rationale: This fits all intersecting
# 21:9 cards at 4K while bounding even the largest QImage thumbnail format.
VIEWPORT_TEXTURE_LIMIT = min(
    MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES // (prod(MEDIA_THUMBNAIL_SIZE) * QIMAGE_MAX_BYTES_PER_PIXEL),
)
# Purpose: Bound retained positions and selections across gallery switches.
# Type: int. Units: gallery viewports. Scope: one live Photos authorization.
# Consumers: QtPhotos._remember_viewport.
# Rationale: Eight supports navigating between recent galleries without keeping
# every large catalogue's selected identities for the entire account.
GALLERY_VIEWPORT_HISTORY = 8
# Purpose: Match the map zoom used by the native Photos Lightbox.
# Type: int. Units: OpenStreetMap zoom levels. Scope: one linked photo location.
# Consumers: QtPhotos._open_map. Rationale: 15 centers a useful local context.
PHOTO_LOCATION_MAP_ZOOM = 15
# Purpose: Keep the native detail card clear of the canvas edge.
# Type: int. Units: logical pixels. Scope: Qt Photos Lightbox toolbar card.
# Consumers: QtPhotos._layout_lightbox_controls.
# Rationale: 16 px matches the inherited compact overlay inset.
LIGHTBOX_TOOLBAR_TOP_INSET = 16
# Purpose: Match the compact native overlay's action targets.
# Type: int. Units: logical pixels. Scope: Lightbox toolbar and edge controls.
# Consumers: QtPhotos.__init__. Rationale: Tk's 25 px targets fit nine actions.
LIGHTBOX_ACTION_EDGE = 25
# Purpose: Keep each directional action clear of the screen edge.
# Type: float. Units: fraction of view width. Scope: Lightbox edge navigation.
# Consumers: QtPhotos._layout_lightbox_controls.
# Rationale: 2.5% matches the native Tk overlay anchor.
LIGHTBOX_EDGE_INSET = 0.025
# Purpose: Hide fullscreen toolbar chrome after a short pointer idle period.
# Type: int. Units: milliseconds. Scope: one fullscreen Qt Photos Lightbox.
# Consumers: QtPhotos._lightbox_controls_timer.
# Rationale: 1400 matches the native Tk viewer's pointer-idle interval.
LIGHTBOX_CONTROLS_IDLE_MS = 1400

# Purpose: Preserve space for photos in a mixed gallery with automatic sizing.
# Type: float. Units: fraction of pane height. Scope: one visible folder shelf.
# Consumers: QtPhotos._layout_folder_split. Rationale: 0.4 matches the Tk shelf.
FOLDER_AUTO_SHARE = 0.4
# Purpose: Leave a reachable photo row during a manual subgallery resize.
# Type: int. Units: logical pixels. Scope: mixed gallery photo viewport.
# Consumers: QtPhotos._layout_folder_split. Rationale: 128 matches the Tk reserve.
FOLDER_PHOTO_RESERVE = 128
# Purpose: Enable predictable keyboard adjustment of the mixed gallery divider.
# Type: int. Units: thousandths of the split. Scope: one Up/Down press.
# Consumers: QtPhotos.eventFilter. Rationale: 50 moves the divider by five percent.
FOLDER_KEY_STEP = 50
# Purpose: Keep action chrome and the first image off the card's edge.
# Type: tuple[int, int, int, int]. Units: logical pixels. Scope: linked Photos.
# Consumers: QtPhotos layout and Lightbox restoration.
# Rationale: The 8 px horizontal and 4/6 px vertical offsets match Tk's heading.
PHOTO_CARD_MARGINS = (8, 4, 8, 6)
# Purpose: Fit the retained status glyph inside the compact heading target.
# Type: int. Units: logical pixels per side. Scope: gallery heading icons.
# Consumers: QtPhotos.__init__. Rationale: Three pixels leave room for the
# 18-pixel artwork and both focus borders in a 26-pixel cell.
PHOTO_HEADING_ICON_PADDING = 3
# Purpose: Keep each ratio's icon and text fully visible without toolbar padding.
# Type: int. Units: logical pixels. Scope: photo and folder ratio buttons.
# Consumers: QtPhotos._make_format_button.
# Rationale: 8 covers the two borders and three pixels of padding on each side.
PHOTO_FORMAT_HORIZONTAL_INSET = 8
# Purpose: Keep the native aspect icon and its text on separate visible lines.
# Type: int. Units: logical pixels. Scope: photo and folder ratio buttons.
# Consumers: QtPhotos._make_format_button.
# Rationale: 8 covers native tool-button spacing, padding and the focus border.
PHOTO_FORMAT_VERTICAL_INSET = 8
# Purpose: Match the inherited compact raised Photos menu surface.
# Type: int. Units: logical pixels. Scope: one contextual Photos card.
# Consumers: QtPhotos._open_photo_context. Rationale: Tk uses an 8 px inset.
PHOTO_CONTEXT_PADDING = 8
# Purpose: Use the same compact active-capture glyphs as the reference tree.
# Type: int. Units: logical pixels. Scope: Photos tree and selected heading.
# Consumers: QtPhotos._sync_capture_markers.
# Rationale: The Tk tree marker is 18 px and the separate heading dot is 9 px.
PHOTO_TREE_MARKER_SIZE = 18
PHOTO_HEADING_RECORDING_SIZE = 9
# Purpose: Match the existing Watcher recording pulse while Photos is active.
# Type: int. Units: milliseconds. Scope: visible scoped Photos recording glyphs.
# Consumers: QtPhotos._capture_pulse_timer. Rationale: 267 is the reference cadence.
PHOTO_CAPTURE_PULSE_MS = 267
# Purpose: Preserve the inherited empty-state card's second outer inset.
# Type: int. Units: logical pixels. Scope: disconnected Photos connect card.
# Consumers: QtPhotos.__init__. Rationale: Tk combines an 18 px outer and inner inset.
PHOTO_CONNECT_OUTER_INSET = 9


@dataclass(frozen=True)
class LinkedPhotos:
    """Transfer a newly approved ephemeral grant and its validated catalogue."""

    session: LinkingSession
    issuer: str
    user_id: int
    galleries: tuple[LinkedGallery, ...]
    tree_state: PhotosTreePreferences
    owns_session: bool = True
    revalidate_gallery: int | None = None


@dataclass(frozen=True)
class PhotosUploadUpgrade:
    """Hold one verified Admin grant until the GUI owner can commit it.

    Attributes:
        session (LinkingSession): New same-account grant retained for the exact target.
        previous_session (LinkingSession): Photos grant retained until the commit succeeds.
        target (LinkedUploadTarget): Server-verified unchanged physical gallery.
        previous_mode (ModeRecord): Saved upload binding captured before consent.
        previous_target (LinkedUploadTarget): Exact currently active upload target.
        binding (OfflineBinding): New grant's issuer, account and scope.
        store (NativeCredentialStore): Existing native credential owner for this account.
        previous_scope (str): Exact persistent scope replaced transactionally.
        refresh (str): New native-vault refresh value, never rendered or logged.
        refresh_deadline (float): Monotonic expiration of the browser exchange.
        galleries (tuple[LinkedGallery, ...]): Tree already read before commit.
        tree_state (PhotosTreePreferences): Existing local navigation preferences.
    """

    session: LinkingSession = field(repr=False, compare=False)
    previous_session: LinkingSession = field(repr=False, compare=False)
    target: LinkedUploadTarget = field(repr=False, compare=False)
    previous_mode: ModeRecord
    previous_target: LinkedUploadTarget = field(repr=False, compare=False)
    binding: OfflineBinding
    store: NativeCredentialStore = field(repr=False, compare=False)
    previous_scope: str
    refresh: str = field(repr=False, compare=False)
    refresh_deadline: float
    galleries: tuple[LinkedGallery, ...]
    tree_state: PhotosTreePreferences


@dataclass(frozen=True)
class LiveSiteCatalogue:
    """Transfer a read-only tree for an already approved parked Photos site.

    Attributes:
        site (DesktopLinkedSite): Exact retained issuer, session, and account.
        galleries (tuple[LinkedGallery, ...]): Verified tree rows from that session.
        tree_state (PhotosTreePreferences): Local presentation state for that account.
    """

    site: DesktopLinkedSite
    galleries: tuple[LinkedGallery, ...]
    tree_state: PhotosTreePreferences


@dataclass(frozen=True)
class GalleryViewport:
    """Remember where the operator was in one authorized gallery by stable IDs."""

    first: int
    focus: int | None
    anchor: int | None
    selection: frozenset[int]


@dataclass(frozen=True)
class PhotoCatalogue:
    """Mass-publish immutable logical rows for one current gallery."""

    rows: tuple[PhotoRow, ...]


@dataclass(frozen=True)
class QtGalleryCacheProbe:
    """Carry one local cache observation to its exact Qt Photos generation.

    Attributes:
        session (LinkingSession): Current authorized session that supplied rows.
        gallery_id (int): Physical gallery owning the loaded descriptors.
        revision (int): Catalogue generation whose local bytes were inspected.
        row (GalleryCacheProbeRow): State, counts, completeness and pin snapshot.
    """

    session: LinkingSession
    gallery_id: int
    revision: int
    row: GalleryCacheProbeRow


@dataclass(frozen=True)
class LinkingProgress:
    """Carry one localized gallery-linking state through the current read lane.

    Attributes:
        caption (str): Localized caption for the approved link operation's current phase.
        failed (bool): Keep this caption when the same operation finishes with an error.
    """

    caption: str
    failed: bool = False


PhotosLoadResult = (
    LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress | PhotosUploadUpgrade
)


@dataclass(frozen=True)
class MediaInformation:
    """Carry verified localizable metadata for one live gallery or photo."""

    session: LinkingSession
    gallery_id: int
    photo_id: int | None
    fields: LinkedMediaDetails
    include_location: bool


@dataclass(frozen=True)
class MediaInformationFailure:
    """Carry a sanitized metadata lookup failure through its owning read lane."""

    summary: str


@dataclass(frozen=True)
class CreatedGallery:
    """Carry a verified original creation receipt to the current live tree."""

    session: LinkingSession
    receipt: BoundCreateReceipt


@dataclass(frozen=True)
class UnlinkedSite:
    """Carry the exact site and confirmed server outcome of explicit unlinking.

    Attributes:
        site (DesktopLinkedSite): The site and original approved session removed.
        revoked (bool): Whether the server confirmed revocation or absence.
    """

    site: DesktopLinkedSite
    revoked: bool


GalleryActionResult = CreatedGallery | UnlinkedSite | LinkedPhoto | tuple[int, ...] | None


@dataclass(frozen=True)
class LinkedRecoveryObservation:
    """Carry one target-bound observation back to its still-open chooser.

    Attributes:
        picker_token (UUID): Opaque identity of the picker that admitted work.
        target (LinkedUploadTarget): Original verified upload target observed.
        outcome (str): Owner-reported ``present`` or ``unknown`` observation.
        marker (str): Exact journal marker, retained privately for revalidation.
    """

    picker_token: UUID
    target: LinkedUploadTarget = field(repr=False, compare=False)
    outcome: str
    marker: str = field(repr=False, compare=False)


class QtPhotos(QWidget):
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
        self._active_gallery_menu: QMenu | None = None
        self._live_sites_menu: QMenu | None = None
        self._gallery_context_demand = False
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

    def linked_gallery_address(self) -> str:
        """Return the verified link issuer without exposing account credentials.

        Returns:
            str: Current canonical issuer or empty when no gallery is linked.
        """
        return self._identity[0] if self._identity is not None else ""

    def manage_link(self) -> None:
        """Open the scoped grant controls for the exact current gallery issuer.

        Returns:
            None: Discovery is read-only; selected grants change only after an
                explicit browser authorization and the existing validation lane.
        """
        if self._scope_dialog is not None:
            self._scope_dialog.raise_()
            self._scope_dialog.activateWindow()
            return
        if self._authorization_active or not self._can_disconnect():
            return
        issuer = self._identity[0] if self._identity is not None else ''
        try:
            saved = self.connections.saved() if self.connections is not None else None
            if not issuer and saved is not None and saved.mode == 'linked':
                issuer = saved.issuer or ''
        except RuntimeError:
            self.status_message('Gallery connection is unavailable.')
            return
        issuer = linking_issuer(issuer or self.gallery_address)
        if not issuer:
            self.link()
            return
        try:
            active_target = self.connections.active() if self.connections is not None else None
            saved = self.connections.saved() if self.connections is not None else None
        except RuntimeError:
            self.status_message('Gallery connection is unavailable.')
            return
        try:
            store = desktop_upload_vault(self.profile)
            binding = saved_desktop_gallery_binding(
                self.profile / 'offline-link.json', issuer,
            )
        except (OSError, RuntimeError, ValueError):
            store = None
            binding = None
        self._scope_issuer = issuer
        self._scope_capabilities = frozenset()
        self._scope_saved_mode = saved
        self._scope_active_target = active_target
        self._scope_offline_storage_ready = store is not None and binding is None
        self._scope_offline_store_available = store is not None
        self._scope_offline_binding = binding
        self._scope_live_session = self._session
        self._scope_live_identity = self._identity
        self._build_scope_dialog()
        if self._scope_live_session is not None:
            self._populate_scope_checks(self._scope_live_session)
            self._scope_status.clear()
            self._sync_scope_controls()
            return
        self._scope_status.setText(tr(self.language, 'linking_requesting'))

        def inspect(
            cancelled: Event,
            publish: Callable[[tuple[str, frozenset[str]]], None],
        ) -> None:
            """Read the issuer's public linking capabilities without credentials.

            Args:
                cancelled (Event): Exact management-dialog discovery lifetime.
                publish (Callable[[tuple[str, frozenset[str]]], None]): Current
                    generation's GUI-thread result sink.
            Returns:
                None: Publishes only the validated issuer and bounded names.
            """
            capabilities = discover_linking_capabilities(issuer, cancelled)
            if cancelled.is_set():
                raise ReadCancelled
            publish((issuer, capabilities))

        self._scope_discovery.submit(inspect)

    def _build_scope_dialog(self) -> None:
        """Build the scope, secure-storage and grant-management controls.

        Returns:
            None: Shows only permissions represented by the shared linking owner.
        """
        dialog = QDialog(self.window())
        dialog.setObjectName('photosLinkManagementDialog')
        dialog.setWindowTitle(gettext('Manage link'))
        dialog.setWindowModality(Qt.WindowModality.WindowModal)
        dialog.setProperty('shellCard', True)
        dialog.setAttribute(Qt.WidgetAttribute.WA_DeleteOnClose)
        layout = QVBoxLayout(dialog)
        heading = QLabel(gettext('Manage link'))
        heading.setObjectName('heading')
        layout.addWidget(heading)
        issuer = QLabel(self._scope_issuer)
        issuer.setObjectName('photosLinkManagementIssuer')
        issuer.setTextFormat(Qt.TextFormat.PlainText)
        issuer.setWordWrap(True)
        layout.addWidget(issuer)
        explanation = QLabel(gettext(
            'Choose permissions for this connection. Optional secure storage '
            'requires separate browser consent and permits renewal for up to '
            '30 days, with a seven-day inactivity limit. The saved API key '
            'remains available; server grants may remain until revoked.',
        ))
        explanation.setObjectName('photosLinkManagementExplanation')
        explanation.setWordWrap(True)
        layout.addWidget(explanation)
        self._scope_checks = {}
        for key, text in (
            ('browse', gettext('Allow listing galleries')),
            ('upload', gettext('Allow gallery uploads')),
            ('read', gettext('Read gallery details')),
            ('create', gettext('Allow creating galleries')),
            ('visibility', tr(self.language, 'linking_visibility_action')),
            ('media', tr(self.language, 'linking_media_consent')),
            ('remember', gettext('Remember securely')),
        ):
            control = QCheckBox(text)
            control.setObjectName(f'photosLinkScope{key.title()}')
            control.setEnabled(False)
            control.toggled.connect(self._scope_controls_changed)
            layout.addWidget(control)
            self._scope_checks[key] = control
        self._scope_status = QLabel()
        self._scope_status.setObjectName('photosLinkManagementStatus')
        self._scope_status.setWordWrap(True)
        layout.addWidget(self._scope_status)
        actions = QHBoxLayout()
        self._scope_update_button = ActionButton(
            tr(self.language, 'linking_start'),
            self._submit_scope_request, primary=True,
        )
        self._scope_update_button.setObjectName('photosLinkScopeUpdate')
        actions.addWidget(self._scope_update_button)
        self._scope_disconnect_button = ActionButton(
            gettext('Disconnect'), self._disconnect_managed_link,
        )
        self._scope_disconnect_button.setObjectName('photosLinkDisconnect')
        actions.addWidget(self._scope_disconnect_button)
        actions.addStretch()
        layout.addLayout(actions)
        secondary = QHBoxLayout()
        self._scope_reconnect_button = ActionButton(
            gettext('Reconnect saved link'), self._reconnect_managed_link,
        )
        self._scope_reconnect_button.setObjectName('photosLinkReconnectSaved')
        secondary.addWidget(self._scope_reconnect_button)
        self._scope_forget_button = ActionButton(
            gettext('Forget saved link'), self._forget_managed_link,
        )
        self._scope_forget_button.setObjectName('photosLinkForgetSaved')
        secondary.addWidget(self._scope_forget_button)
        manage_browser = ActionButton(
            gettext('Manage gallery links'), self._open_managed_browser_grants,
        )
        manage_browser.setObjectName('photosLinkBrowserGrants')
        secondary.addWidget(manage_browser)
        secondary.addStretch()
        secondary.addWidget(ActionButton(gettext('Close'), dialog.reject))
        layout.addLayout(secondary)
        self._scope_dialog = dialog
        dialog.finished.connect(self._scope_dialog_finished)
        self._sync_scope_controls()
        dialog.show()

    def _populate_scope_checks(self, session: LinkingSession) -> None:
        """Reflect the exact active grant in read-only consent controls.

        Args:
            session (LinkingSession): Current live grant shown by the dialog.
        Returns:
            None: Never infers server permission from the saved upload target.
        """
        if not session.available():
            return
        grants = {
            'browse': session.can_list_galleries(),
            'upload': session.can_upload(),
            'read': session.can_read_gallery_detail(),
            'create': session.can_create_gallery(),
            'visibility': session.can_change_gallery_visibility(),
            'media': session.can_read_media(),
            'remember': 'offline_access' in session.approved_scope().split(),
        }
        for key, granted in grants.items():
            control = self._scope_checks[key]
            blocker = QSignalBlocker(control)
            control.setChecked(granted)
            del blocker

    def _receive_scope_capabilities(
        self, result: tuple[str, frozenset[str]],
    ) -> None:
        """Apply discovery only to the issuer and dialog that requested it.

        Args:
            result (tuple[str, frozenset[str]]): Canonical issuer and public
                capability names returned by the linking transport.
        Returns:
            None: Unsupported controls stay disabled and no authority is added.
        """
        issuer, capabilities = result
        if (self._scope_dialog is None or issuer != self._scope_issuer
                or self._session is not None or not self._scope_dialog.isVisible()):
            return
        self._scope_capabilities = capabilities
        browse = self._scope_checks.get('browse')
        if browse is not None and 'galleries:list' not in capabilities:
            browse.setChecked(False)
        media = self._scope_checks.get('media')
        if media is not None:
            media.setText(tr(
                self.language,
                'linking_admin_media_consent' if 'media:admin' in capabilities
                else 'linking_media_consent',
            ))
        if capabilities:
            self._scope_status.clear()
        else:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
        self._sync_scope_controls()

    def _scope_discovery_finished(self, failure: str) -> None:
        """Report a fixed discovery failure and refresh gated controls.

        Args:
            failure (str): Safe exception class from the bounded read lane.
        Returns:
            None: No discovery error text or remote response is displayed.
        """
        if self._scope_dialog is None:
            return
        if failure:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
        self._sync_scope_controls()

    def _scope_controls_changed(self, _checked: bool = False) -> None:
        """Apply Tk's permission dependencies before enabling submission.

        Args:
            _checked (bool): Toggle state supplied by Qt; current state is read
                from the owned controls.
        Returns:
            None: Dependent write/read scopes imply listing and visibility implies read.
        """
        if self._scope_live_session is None:
            dependent = any(
                self._scope_checks[key].isChecked()
                for key in ('upload', 'read', 'create', 'visibility', 'media')
            )
            if dependent:
                blocker = QSignalBlocker(self._scope_checks['browse'])
                self._scope_checks['browse'].setChecked(True)
                del blocker
            if self._scope_checks['visibility'].isChecked():
                blocker = QSignalBlocker(self._scope_checks['read'])
                self._scope_checks['read'].setChecked(True)
                del blocker
            if self._scope_checks['media'].isChecked():
                blocker = QSignalBlocker(self._scope_checks['remember'])
                self._scope_checks['remember'].setChecked(False)
                del blocker
        self._sync_scope_controls()

    def _scope_selection_matches(self) -> bool:
        """Match the mode and live target captured by this management dialog.

        Returns:
            bool: True only while both shared connection snapshots are unchanged.
        """
        if self.connections is None:
            return (
                self._scope_saved_mode is None
                and self._scope_active_target is None
            )
        try:
            return (
                self.connections.saved() == self._scope_saved_mode
                and self.connections.active() == self._scope_active_target
            )
        except RuntimeError:
            return False

    def _scope_binding_matches(self) -> bool:
        """Re-read the exact captured offline binding before local removal.

        Returns:
            bool: True only when the same hinted account/scope still exists.
        """
        binding = self._scope_offline_binding
        if binding is None or not self._scope_offline_store_available:
            return False
        try:
            current = saved_desktop_gallery_binding(
                self.profile / 'offline-link.json', self._scope_issuer,
            )
        except (OSError, RuntimeError, ValueError):
            return False
        return current == binding

    def _sync_scope_controls(self) -> None:
        """Gate each visible consent and action against live owner state.

        Returns:
            None: Current grants stay read-only until the live grant is disconnected.
        """
        if self._scope_dialog is None:
            return
        active = self._scope_live_session is not None
        selection_matches = self._scope_selection_matches()
        capabilities = self._scope_capabilities
        supported = {
            'browse': 'galleries:list' in capabilities,
            'upload': 'gallery:upload' in capabilities,
            'read': 'gallery:read' in capabilities,
            'create': 'galleries:create' in capabilities,
            'visibility': 'gallery:visibility' in capabilities,
            'media': 'media:read' in capabilities,
            'remember': ('offline_access' in capabilities
                         and self._scope_offline_storage_ready),
        }
        if active:
            same_live = (
                selection_matches
                and self._session is self._scope_live_session
                and self._identity == self._scope_live_identity
                and self._session is not None and self._session.available()
            )
            supported = {key: same_live for key in supported}
            disconnect_ready = same_live
        else:
            disconnect_ready = False
        for key, control in self._scope_checks.items():
            control.setEnabled(not active and supported[key])
        request_ready = bool(
            not active and selection_matches and capabilities
            and not self._scope_discovery.busy()
            and not self._authorization_active and not self.busy()
            and not self.transfer_busy()
        )
        if request_ready:
            try:
                self._current_requested_scope()
            except LinkingError:
                request_ready = False
        self._scope_update_button.setEnabled(request_ready)
        self._scope_disconnect_button.setEnabled(disconnect_ready)
        saved = self._scope_saved_mode
        same_saved_link = bool(
            selection_matches and saved is not None and saved.mode == 'linked'
            and saved.issuer == self._scope_issuer
        )
        binding = self._scope_offline_binding
        saved_offline = bool(
            same_saved_link and saved is not None and saved.scope
            and saved.scope.endswith(' offline_access')
            and self._scope_offline_store_available
            and binding is not None
            and (binding.issuer, binding.account_id, binding.scope)
            == (saved.issuer, saved.account_id, saved.scope)
            and self._scope_binding_matches()
        )
        self._scope_reconnect_button.setVisible(True)
        self._scope_reconnect_button.setEnabled(saved_offline and not active)
        self._scope_forget_button.setVisible(True)
        self._scope_forget_button.setEnabled(saved_offline and not active)

    def _current_requested_scope(self) -> str:
        """Resolve the current controls through the shared Tk/Qt scope mapper.

        Returns:
            str: Exact ordered scope for the explicit browser request.
        Raises:
            LinkingError: Selection, capabilities or native storage are unavailable.
        """
        return requested_linking_scope(
            list_galleries=self._scope_checks['browse'].isChecked(),
            upload=self._scope_checks['upload'].isChecked(),
            read_details=self._scope_checks['read'].isChecked(),
            create_galleries=self._scope_checks['create'].isChecked(),
            change_visibility=self._scope_checks['visibility'].isChecked(),
            read_media=self._scope_checks['media'].isChecked(),
            remember_securely=self._scope_checks['remember'].isChecked(),
            capabilities=self._scope_capabilities,
            visibility_ready=True,
            native_media_ready=True,
            offline_storage_ready=self._scope_offline_storage_ready,
        )

    def _submit_scope_request(self) -> None:
        """Start a new request only after the captured profile/target fences hold.

        Returns:
            None: Browser approval alone cannot select or replace the upload target.
        """
        dialog = self._scope_dialog
        if (dialog is None or self._session is not None
                or not self._can_disconnect()):
            return
        try:
            if not self._scope_selection_matches():
                raise RuntimeError('Connection selection changed.')
            scope = self._current_requested_scope()
        except (OSError, RuntimeError, ValueError, LinkingError):
            self._scope_status.setText(gettext(
                'Gallery connection is unavailable.',
            ))
            return
        issuer = linking_issuer(self._scope_issuer)
        if issuer != self._scope_issuer:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        self._scope_dialog = None
        self._scope_discovery.cancel()
        dialog.accept()
        uploads = 'gallery:upload' in scope.split()
        self._authorize(
            uploads, address_override=issuer, requested_scope=scope,
        )

    def _disconnect_managed_link(self) -> None:
        """Disconnect only the exact live grant shown in the dialog.

        Returns:
            None: Existing target, capture, and revocation guards remain authoritative.
        """
        if (self._scope_dialog is None or self._session is not self._scope_live_session
                or self._identity != self._scope_live_identity
                or not self._scope_selection_matches()
                or self._session is None or not self._session.available()
                or not self._can_disconnect()):
            return
        self._scope_dialog.accept()
        self._scope_dialog = None
        self.unlink()

    def _reconnect_managed_link(self) -> None:
        """Reconnect only the saved linked account captured when opened.

        Returns:
            None: Existing saved-target and scope verification remains in `_authorize`.
        """
        if self.connections is None or self._scope_saved_mode is None:
            return
        if not self._scope_selection_matches():
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        if not self._scope_binding_matches():
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        try:
            if self.connections.saved() != self._scope_saved_mode:
                self._scope_status.setText(gettext('Gallery connection is unavailable.'))
                return
        except RuntimeError:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        saved = self._scope_saved_mode
        if saved.mode != 'linked' or saved.issuer != self._scope_issuer:
            return
        captured_saved = saved
        if self._scope_dialog is not None:
            self._scope_dialog.accept()
            self._scope_dialog = None
        self._authorize(True, captured_saved)

    def _forget_managed_link(self) -> None:
        """Forget only the matching saved link through the existing confirmation.

        Returns:
            None: Native-vault removal and its writer fence remain in `forget_uploads`.
        """
        if self.connections is None or self._scope_saved_mode is None:
            return
        if (not self._scope_selection_matches()
                or not self._scope_binding_matches()):
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        try:
            if self.connections.saved() != self._scope_saved_mode:
                self._scope_status.setText(gettext('Gallery connection is unavailable.'))
                return
        except RuntimeError:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        if self._scope_dialog is not None:
            self._scope_dialog.accept()
            self._scope_dialog = None
        self.forget_uploads()

    def _open_managed_browser_grants(self) -> None:
        """Open only the exact validated installation's grant-management page.

        Returns:
            None: No user-entered URL or unvalidated remote redirect is followed.
        """
        issuer = linking_issuer(self._scope_issuer + '/index.php')
        if issuer != self._scope_issuer:
            return
        try:
            if not webbrowser.open(issuer + '/index.php?page=app_linking_devices'):
                self._scope_status.setText(gettext('Could not open the gallery in your browser.'))
        except (OSError, RuntimeError):
            self._scope_status.setText(gettext('Could not open the gallery in your browser.'))

    def _scope_dialog_finished(self, _result: int) -> None:
        """Fence capability reads when the management dialog is dismissed.

        Args:
            _result (int): Native modal result; no grant state is inferred from it.
        Returns:
            None: Clears only this dialog's view-owned discovery generation.
        """
        self._scope_discovery.cancel()
        self._scope_dialog = None
        self._scope_checks.clear()
        self._scope_status = None
        self._scope_update_button = None
        self._scope_disconnect_button = None
        self._scope_reconnect_button = None
        self._scope_forget_button = None
        self._scope_issuer = ''
        self._scope_capabilities = frozenset()
        self._scope_saved_mode = None
        self._scope_active_target = None
        self._scope_offline_storage_ready = False
        self._scope_offline_store_available = False
        self._scope_offline_binding = None
        self._scope_live_session = None
        self._scope_live_identity = None


    def _sync_connection_state(self) -> None:
        """Show the single connect card or the active Photos workspace.

        Returns:
            None: Offline actions stay in shell menus while local or linked
                photo surfaces retain their existing owner and session.
        """
        active = self._identity is not None or self._local_photos_active
        connecting = self._authorization_active or (
            self._identity is None and not self.link_button.isEnabled()
        )
        detail = self._lightbox_layout_restore is not None
        self._connect_card_outer.setVisible(not active and not detail)
        self._connect_card.setVisible(not active and not detail)
        self._center_toolbar.setVisible(active and not detail)
        self._split.setVisible(active or detail)
        self._gallery_panel.setVisible(
            self._identity is not None
            and self._gallery_tree.topLevelItemCount() > 0 and not detail,
        )
        offline_prompt = gettext("Link a gallery or open local photos.")
        self.status.setVisible(
            self._authorization_dialog is None
            and (connecting or active
             or (bool(self.status.text()) and self.status.text() != offline_prompt))
            and not detail,
        )
        self._sync_folder_section()

    def _make_format_button(
        self, ratio: str, select: Callable[[str], None], *, folders: bool,
    ) -> QToolButton:
        """Use the same icon, visible label and focus treatment in both format strips.

        Args:
            ratio (str): Shared card format key.
            select (Callable[[str], None]): Applies one explicit local format.
            folders (bool): Build the separate subgallery format control.
        Returns:
            QToolButton: Localized toggle with an accessible visible caption.
        """
        caption = self._text('card_format_default') if ratio == 'default' else ratio
        prefix = 'photosFolderFormat' if folders else 'photosCardFormat'
        button = QToolButton()
        button.setObjectName(prefix + ''.join(part.title() for part in ratio.split(':')))
        button.setText(caption)
        button.setAccessibleName(caption)
        button.setToolTip(self._text('folder_format_hint' if folders else 'card_format_hint'))
        button.setToolButtonStyle(Qt.ToolButtonStyle.ToolButtonTextUnderIcon)
        button.setIconSize(QSize(*PHOTOS_CARD_FORMAT_ICON_SIZE))
        button.setIcon(qt_icon(
            'aspect_' + ratio, size=PHOTOS_CARD_FORMAT_ICON_SIZE, tone='muted', checked_tone='primary',
        ))
        button.setCheckable(True)
        caption_font = button.font()
        caption_font.setPointSize(UI_PHOTO_FORMAT_LABEL_POINTS)
        button.setFont(caption_font)
        button.setStyleSheet(
            'QToolButton {'
            f'color: {PALETTE["muted"]}; background: transparent; '
            'border: 1px solid transparent; border-radius: 6px; padding: 1px 3px; '
            '} QToolButton:checked {'
            f'color: {PALETTE["primary"]}; background: {PALETTE["surface_alt"]}; '
            f'border-color: {PALETTE["line"]}; '
            '} QToolButton:focus {'
            f'border-color: {PALETTE["focus"]}; '
            '} QToolButton:disabled {'
            f'color: {PALETTE["muted"]}; background: {PALETTE["surface"]}; }}'
        )
        content_width = max(button.iconSize().width(), button.fontMetrics().horizontalAdvance(caption))
        button.setFixedWidth(content_width + PHOTO_FORMAT_HORIZONTAL_INSET)
        button.ensurePolished()
        button.setFixedHeight(max(
            button.sizeHint().height(),
            button.iconSize().height() + button.fontMetrics().height() + PHOTO_FORMAT_VERTICAL_INSET,
        ))
        button.clicked.connect(lambda _checked=False: select(ratio))
        return button

    def prepare_graphics_readiness(self) -> None:
        """Expose the real Quick scene for the launcher's first-frame proof.

        Returns:
            None: The shell stays transparent until this scene completes a frame.
        """
        if (self._identity is None and not self._local_photos_active
                and not self.detailOpen):
            self._gallery_panel.hide()
            self._center_toolbar.hide()
            self._transport_row.hide()
            self._split.show()

    def finish_graphics_readiness(self) -> None:
        """Restore normal Photos pane visibility after the first Quick frame.

        Returns:
            None: A disconnected page returns to its single Connect card state.
        """
        self._sync_connection_state()

    def _layout_format_buttons(self) -> None:
        """Wrap ratio controls only when the available Photos width requires it.

        Returns:
            None: Preserves Tk's ratio order and keeps every caption visible.
        """
        width = self._format_actions.width()
        if width <= 0:
            return
        buttons = tuple(self._format_buttons.values())
        columns = len(buttons)
        layout = self._format_actions.layout()
        spacing = layout.horizontalSpacing()
        notice_width = (
            self._format_notice.sizeHint().width()
            if self._format_notice.isVisible() else 0
        )
        while columns > 1:
            needed = sum(
                max(button.minimumWidth() for button in buttons[column::columns])
                for column in range(columns)
            ) + spacing * (columns - 1)
            if notice_width:
                needed += notice_width + spacing
            if needed <= width:
                break
            columns -= 1
        if columns != self._format_columns:
            self._format_columns = columns
            for button in buttons:
                layout.removeWidget(button)
            for index, button in enumerate(buttons):
                layout.addWidget(button, index // columns, index % columns)
            layout.removeWidget(self._format_notice)
            notice_row = (len(buttons) - 1) // columns
            layout.addWidget(self._format_notice, notice_row, columns)
        rows = (len(buttons) + columns - 1) // columns
        row_height = max(button.minimumHeight() for button in buttons)
        height = rows * row_height + (rows - 1) * layout.verticalSpacing()
        if self._format_actions.minimumHeight() != height:
            self._format_actions.setFixedHeight(height)

    def _layout_gallery_heading(self) -> None:
        """Match the reference's right-aligned controls and narrow rows.

        Returns:
            None: Preserves real text and action widths at narrow viewport sizes.
        """
        layout = self._gallery_header_layout
        has_header_formats = layout.indexOf(self._format_row) >= 0 and not self._format_row.isHidden()
        ratio_width = sum(button.minimumWidth() for button in self._format_buttons.values())
        spacing = self._format_actions.layout().horizontalSpacing()
        ratio_width += spacing * (len(self._format_buttons) - 1)
        if not self._format_notice.isHidden():
            ratio_width += self._format_notice.sizeHint().width() + spacing
        format_width = (
            ratio_width + self._order_button.sizeHint().width()
            + self._format_row.layout().spacing()
            if has_header_formats else 0
        )
        group_spacing = layout.horizontalSpacing()
        group_width = self._gallery_heading_actions.sizeHint().width() + group_spacing
        if has_header_formats:
            group_width += format_width + group_spacing
        width = self._center_toolbar.width()
        stacked = (width < PHOTOS_HEADING_STACK_WIDTH
                   or group_width + PHOTOS_HEADING_TITLE_MINIMUM > width)
        paired = stacked and has_header_formats and group_width <= width
        minimum_formats = format_width if not stacked else 0
        if self._format_row.minimumWidth() != minimum_formats:
            self._format_row.setMinimumWidth(minimum_formats)
        title_width = self._gallery_title.fontMetrics().horizontalAdvance(self._gallery_title.text())
        title_actions_width = 0 if self._edit_title_button.isHidden() else (
            self._edit_title_button.sizeHint().width() + self._gallery_heading_title.layout().spacing()
        )
        if not self._capture_heading.isHidden():
            title_actions_width += PHOTO_HEADING_RECORDING_SIZE + self._gallery_heading_title.layout().spacing()
        title_available = width - title_actions_width - (0 if stacked else group_width)
        title_fits = title_width <= title_available
        self._gallery_title.setWordWrap(not title_fits)
        self._gallery_title.setMinimumWidth(title_width if title_fits else 0)
        # Grid coordinates are structural slots matching the Tk title/control rows.
        positions = [(self._gallery_heading_title, (0, 0, 1, 3 if stacked else 1))]
        if not stacked or paired:
            positions.append((self._gallery_heading_actions, (1 if paired else 0, 2, 1, 1)))
        else:
            positions.append((self._gallery_heading_actions, (2 if has_header_formats else 1, 0, 1, 3)))
        if has_header_formats:
            format_position = (1, 0, 1, 2 if paired else 3) if stacked else (0, 1, 1, 1)
            positions.append((self._format_row, format_position))
        for widget, position in positions:
            if tuple(layout.getItemPosition(layout.indexOf(widget))) != position:
                layout.removeWidget(widget)
                alignment = Qt.AlignmentFlag(0)
                if widget is self._gallery_heading_actions:
                    alignment = (Qt.AlignmentFlag.AlignRight if not stacked or paired
                                 else Qt.AlignmentFlag.AlignLeft)
                layout.addWidget(widget, *position, alignment=alignment)

    def _sync_folder_section(self) -> None:
        """Place direct subgalleries and the independent Photos strip in their sections.

        Returns:
            None: Updates only live child cards, controls and split placement.
        """
        session, gallery = self._session, self._gallery_id
        enabled = session is not None and gallery is not None and session.available()
        detail = self._lightbox_layout_restore is not None
        rows = self._gallery_children.get(gallery, ()) if enabled and gallery is not None else ()
        self._subgallery_switch.setVisible(bool(rows) and not detail)
        self._create_child_button.setVisible(enabled and not detail)
        self._edit_title_button.setVisible(enabled and not detail)
        self._create_child_button.setEnabled(enabled)
        folders = bool(rows) and self._subgallery_switch.isChecked() and not detail
        known_empty = self._gallery_has_photos is False
        mixed = folders and not known_empty
        if self._using_folder_section != mixed:
            self._using_folder_section = mixed
            if mixed:
                self._gallery_header_layout.removeWidget(self._format_row)
                self._photo_pane_layout.insertWidget(0, self._format_row)
            else:
                self._photo_pane_layout.removeWidget(self._format_row)
                self._gallery_header_layout.addWidget(self._format_row, 0, 1)
        self._folder_shelf.configure(
            session, gallery, rows[::-1] if self._folder_newest else rows, self._folder_format,
        )
        self._folder_section.setVisible(folders)
        self._photo_pane.setVisible(not folders or not known_empty)
        self._format_row.setVisible(enabled and self._gallery_has_photos is True and not detail)
        self._empty_gallery_message.setVisible(enabled and known_empty and not folders and not detail)
        self._sync_format_actions()
        self._sync_folder_format_actions()
        self._layout_folder_formats()
        self._layout_folder_split()
        self._layout_gallery_heading()

    def set_capture_target(self, target: tuple[str, int, int] | None) -> None:
        """Display the exact current linked Watcher ledger's gallery marker.

        Args:
            target (tuple[str, int, int] | None): Operation's normalized issuer,
                account and gallery IDs, or None when the worker no longer owns them.
        Returns:
            None: Updates only the matching authorized tree and current heading.
        """
        if self._capture_target != target:
            self._capture_target = target
            self._capture_pulse = True
            self._sync_capture_markers()

    def _refresh_tree_marker(self, gallery_id: int) -> None:
        """Compose observed cache, pin, and active Watcher states for one row.

        Args:
            gallery_id (int): Exact gallery represented by a mounted tree item.
        Returns:
            None: Updates the existing icon, tooltip and accessible description.
        """
        item = self._gallery_items.get(gallery_id)
        if item is not None:
            marker = self._gallery_state_icon(gallery_id, show_capture=True)
            if item.icon(0).cacheKey() != marker.cacheKey():
                item.setIcon(0, marker)
            description = self._gallery_marker_description(gallery_id)
            if item.toolTip(0) != description:
                item.setToolTip(0, description)
                item.setData(0, Qt.ItemDataRole.AccessibleDescriptionRole, description)
        if self._gallery_id == gallery_id:
            status = self._gallery_state_icon(gallery_id, show_capture=False)
            if self._gallery_options.icon().cacheKey() != status.cacheKey():
                self._gallery_options.setIcon(status)

    def _gallery_marker_description(self, gallery_id: int) -> str:
        """Describe each visible part of a gallery's composed status icon.

        Args:
            gallery_id (int): Gallery represented by this native tree item.
        Returns:
            str: Localized cache availability and any pin or Watcher overlays.
        """
        state = self._cache_states.get(gallery_id, 'unknown')
        lines = [self._text(f'cache_{state}')]
        if self._gallery_pins.get(gallery_id) is True:
            lines.append(self._text('cache_marker_pinned'))
        if gallery_id in self._capture_markers:
            lines.append(self._text(
                'cache_marker_watcher_descendant'
                if self._capture_marker_hollows.get(gallery_id) else 'cache_marker_watcher',
            ))
        return '\n'.join(lines)

    def _gallery_state_icon(self, gallery_id: int | None, *, show_capture: bool) -> QIcon:
        """Reuse the local cache glyph and optional Watcher overlay.

        Args:
            gallery_id (int | None): Exact gallery or the unknown default.
            show_capture (bool): Include the scoped Watcher state for tree rows.
        Returns:
            QIcon: Shared local status artwork at the tree and menu glyph size.
        """
        state = (self._cache_states.get(gallery_id) if gallery_id is not None else None) or 'unknown'
        pinned = gallery_id is not None and self._gallery_pins.get(gallery_id) is True
        recording = show_capture and gallery_id is not None and gallery_id in self._capture_markers
        hollow = recording and self._capture_marker_hollows.get(gallery_id, False)
        pulse = recording and self._capture_pulse
        marker = state, pinned, recording, hollow, pulse
        icon = self._tree_marker_icons.get(marker)
        if icon is None:
            icon = qt_icon(
                state, size=PHOTO_TREE_MARKER_SIZE, pinned=pinned,
                recording=recording, hollow=hollow, pulse=pulse, item_view=True,
            )
            self._tree_marker_icons[marker] = icon
        return icon

    def _queue_gallery_cache_probe(self, rows: tuple[PhotoRow, ...]) -> None:
        """Probe only already loaded linked rows while Photos is visible.

        Args:
            rows (tuple[PhotoRow, ...]): Current complete catalogue snapshot.
        Returns:
            None: Admits local cache-header reads through one cancellable lane.
        """
        session, gallery = self._session, self._gallery_id
        if (not self._photos_active or not self.isVisible() or self._local_photos_active
                or session is None or gallery is None or not session.available()):
            return
        revision = self._revision
        key = (session, gallery, revision)
        if self._cache_probe_key == key:
            return
        self._cache_probe_key = key

        def probe(cancelled: Event, publish: Callable[[QtGalleryCacheProbe], None]) -> None:
            """Read local cache metadata for the captured authorized snapshot.

            Args:
                cancelled (Event): Current Photos generation cancellation fence.
                publish (Callable[[QtGalleryCacheProbe], None]): Ordered lane result.
            Returns:
                None: No gallery listing or media transfer is performed.
            """
            if cancelled.is_set():
                raise ReadCancelled
            sources = tuple(row.source for row in rows if isinstance(row.source, LinkedPhoto))
            if len(sources) != len(rows):
                return
            row = probe_gallery_cache_status(
                session, gallery, sources or None, bool(sources), cancelled,
            )
            if row is None:
                raise ReadCancelled
            publish(QtGalleryCacheProbe(session, gallery, revision, row))

        self._cache_probe.submit(probe)

    def _receive_cache_probe(self, result: QtGalleryCacheProbe) -> None:
        """Apply a cache observation only to its unchanged Qt gallery owner.

        Args:
            result (QtGalleryCacheProbe): Local read tied to a session and revision.
        Returns:
            None: Refreshes the exact tree marker without touching photo pixels.
        """
        if (not self._photos_active or self._session is not result.session
                or self._gallery_id != result.gallery_id
                or self._revision != result.revision
                or not result.session.available()):
            return
        state, _cached, _total, _complete, pinned = result.row
        if state is not None:
            self._cache_states[result.gallery_id] = state
        else:
            self._cache_states.pop(result.gallery_id, None)
        if pinned is not None:
            self._gallery_pins[result.gallery_id] = pinned
        else:
            self._gallery_pins.pop(result.gallery_id, None)
        self._refresh_tree_marker(result.gallery_id)

    def _sync_capture_markers(self) -> None:
        """Refresh the active tree row, collapsed ancestors and selected marker.

        Returns:
            None: Leaves unrelated account, selection and renderer state unchanged.
        """
        target = self._capture_target
        markers: dict[int, bool] = {}
        if (target is not None and target[:2] == self._identity
                and self._session is not None and self._session.available()):
            item = self._gallery_items.get(target[2])
            if item is not None:
                markers[target[2]] = False
                ancestor = item.parent()
                while ancestor is not None:
                    if not ancestor.isExpanded():
                        gallery = int(ancestor.data(0, Qt.ItemDataRole.UserRole))
                        markers[gallery] = True
                    ancestor = ancestor.parent()
        changed = self._capture_markers.union(markers)
        self._capture_markers = set(markers)
        self._capture_marker_hollows = markers
        for gallery in changed:
            self._refresh_tree_marker(gallery)
        heading_marked = target is not None and target[2] == self._gallery_id and target[2] in markers
        was_marked = not self._capture_heading.isHidden()
        if heading_marked:
            icon = qt_icon('recording', size=PHOTO_HEADING_RECORDING_SIZE, pulse=self._capture_pulse)
            self._capture_heading.setPixmap(icon.pixmap(PHOTO_HEADING_RECORDING_SIZE))
        if heading_marked != was_marked:
            self._capture_heading.setVisible(heading_marked)
            self._layout_gallery_heading()
        self._sync_capture_pulse()

    def _sync_capture_pulse(self, _state: Qt.ApplicationState | None = None) -> None:
        """Animate the marker only in an active visible Photos workspace.

        Args:
            _state (Qt.ApplicationState | None): Optional native application state notification.
        Returns:
            None: A hidden, minimized or inactive view has no marker timer wakeups.
        """
        active = (bool(self._capture_markers) and self.isVisible() and not self.detailOpen
                  and not self.window().isMinimized()
                  and QApplication.applicationState() == Qt.ApplicationState.ApplicationActive)
        if active:
            if not self._capture_pulse_timer.isActive():
                self._capture_pulse_timer.start()
        else:
            self._capture_pulse_timer.stop()

    def _pulse_capture_marker(self) -> None:
        """Change only the visible scoped recording glyph's retained palette.

        Returns:
            None: Reuses the icon cache and never reads a gallery or recorder.
        """
        self._sync_capture_pulse()
        if self._capture_pulse_timer.isActive():
            self._capture_pulse = not self._capture_pulse
            self._sync_capture_markers()

    def _layout_folder_formats(self) -> None:
        """Wrap independent folder formats when a larger tree or DPI reduces space.

        Returns:
            None: All ratio captions and chronological order remain reachable.
        """
        if self._folder_section.isHidden():
            return
        layout = self._folder_formats.layout()
        controls = (self._folder_order, *self._folder_format_buttons.values())
        row = column = used = 0
        width = self._folder_formats.width()
        for button in controls:
            control_width = button.maximumWidth() if isinstance(button, QToolButton) else button.sizeHint().width()
            advance = control_width + layout.horizontalSpacing()
            if column and used + advance > width:
                row += 1
                column = used = 0
            index = layout.indexOf(button)
            if layout.getItemPosition(index)[:2] != (row, column):
                layout.removeWidget(button)
                layout.addWidget(button, row, column)
            column += 1
            used += advance
        row_height = max(
            self._folder_order.sizeHint().height(),
            *(button.minimumHeight() for button in self._folder_format_buttons.values()),
        )
        height = (row + 1) * row_height + row * layout.verticalSpacing()
        if self._folder_formats.minimumHeight() != height:
            self._folder_formats.setFixedHeight(height)

    def _sync_folder_format_actions(self) -> None:
        """Render the current per-gallery folder order, dimensions and save feedback.

        Returns:
            None: Updates native controls while preserving independently saved photo settings.
        """
        if self._folder_section.isHidden():
            return
        order = 'folder_order_newest' if self._folder_newest else 'folder_order_oldest'
        order_hint = self._text('folder_order_unsaved' if 'order' in self._folder_modes_unsaved else order)
        mark = ' !' if 'order' in self._folder_modes_unsaved else ''
        self._folder_order.setText(('↓' if self._folder_newest else '↑') + mark)
        self._folder_order.setAccessibleName(order_hint)
        self._folder_order.setToolTip(order_hint)
        for ratio, button in self._folder_format_buttons.items():
            selected = ratio == self._folder_format
            button.setChecked(selected)
            button.setIcon(qt_icon(
                'aspect_' + ratio, size=PHOTOS_CARD_FORMAT_ICON_SIZE,
                tone='primary' if selected else 'muted',
                checked_tone='primary',
            ))
            button.setToolTip(self._text(
                'folder_format_unsaved' if 'format' in self._folder_modes_unsaved else 'folder_format_hint',
            ))

    def _set_folder_format(self, value: str) -> None:
        """Change the current gallery's local folder ratio without changing photo tiles.

        Args:
            value (str): Shared aspect-format key from the native control.
        Returns:
            None: Retains current choices and exposes any local persistence failure.
        """
        if self._view_path is None or self._gallery_id is None or value not in PHOTO_CARD_FORMATS:
            return
        self._folder_format = value
        if save_gallery_card_format(self._view_path, self._gallery_id, value, folders=True):
            self._folder_modes_unsaved.discard('format')
        else:
            self._folder_modes_unsaved.add('format')
            self.status_message(self._text('folder_format_unsaved'))
        self._sync_folder_section()

    def _toggle_folder_order(self) -> None:
        """Reverse the authorized direct children and persist their separate order.

        Returns:
            None: Reorders folder cards and leaves the photos unchanged.
        """
        if self._view_path is None or self._gallery_id is None:
            return
        self._folder_newest = not self._folder_newest
        order = 'newest' if self._folder_newest else 'oldest'
        if save_gallery_photo_order(self._view_path, self._gallery_id, order, folders=True):
            self._folder_modes_unsaved.discard('order')
        else:
            self._folder_modes_unsaved.add('order')
        self._sync_folder_section()

    def _layout_folder_split(self) -> None:
        """Restore the saved divider or leave room for one compact automatic folder section.

        Returns:
            None: Keeps the reference photo reserve even at a narrow or short size.
        """
        if not self._using_folder_section:
            return
        split = self._folder_split
        height = split.height() - split.handleWidth()
        if height <= 0:
            return
        share = self._folder_split_share
        if share is None:
            cell = self._folder_shelf.gridSize()
            columns = max(1, self._folder_shelf.viewport().width() // max(1, cell.width()))
            rows = (len(self._folder_shelf.cards.rows) + columns - 1) // columns
            chrome = self._folder_formats.minimumHeight() + self._folder_section.layout().spacing()
            one_row = chrome + cell.height()
            wanted = min(max(int(height * FOLDER_AUTO_SHARE), one_row), chrome + cell.height() * rows)
        else:
            wanted = round(height * share / GALLERY_FOLDER_SHARE_SCALE)
        upper = min(wanted, max(0, height - FOLDER_PHOTO_RESERVE))
        split.setSizes([upper, height - upper])

    def _remember_folder_split(self, _position: int, _index: int) -> None:
        """Save the actual split ratio only after an operator drags the divider.

        Args:
            _position (int): Qt logical splitter position.
            _index (int): Qt handle index inside the two-pane split.
        Returns:
            None: Stores bounded thousandths with the current linked gallery.
        """
        upper, lower = self._folder_split.sizes()
        if self._using_folder_section and upper + lower > 0:
            share = round(upper * GALLERY_FOLDER_SHARE_SCALE / (upper + lower))
            self._save_folder_split(share)

    def _save_folder_split(self, share: int | None) -> None:
        """Save a bounded position or restore the shared automatic divider policy.

        Args:
            share (int | None): Proposed thousandths, or None for automatic height.
        Returns:
            None: Keeps the local layout with honest persistence feedback.
        """
        if not self._using_folder_section or self._view_path is None or self._gallery_id is None:
            return
        if share is not None:
            smallest, largest = GALLERY_FOLDER_SHARE_BOUNDS
            share = min(largest, max(smallest, share))
        self._folder_split_share = share
        saved = save_gallery_folder_split(self._view_path, self._gallery_id, share)
        hint = self._text('folder_split_hint' if saved else 'folder_split_unsaved')
        self._folder_split.handle(1).setToolTip(hint)
        self._layout_folder_split()

    def _sync_format_actions(self) -> None:
        """Reflect the selected gallery, saved ratio and local-write result.

        Returns:
            None: Controls remain disabled outside an authorized gallery.
        """
        enabled = self._identity is not None and self._gallery_id is not None
        self._order_button.setEnabled(enabled)
        self._format_actions.setVisible(enabled)
        if self._gallery_id is not None:
            self._format_notice.setVisible(self._gallery_id in self._format_unsaved)
        for ratio, button in self._format_buttons.items():
            selected = ratio == self._format
            button.setChecked(selected)
            button.setEnabled(enabled)
            button.setIcon(qt_icon(
                "aspect_" + ratio, size=PHOTOS_CARD_FORMAT_ICON_SIZE,
                tone="primary" if selected else "muted",
                checked_tone="primary",
            ))
            button.setToolTip(self._text(
                "card_format_unsaved"
                if self._gallery_id in self._format_unsaved
                else "card_format_hint",
            ))
        self._layout_gallery_heading()
        self._layout_format_buttons()

    def _sync_lightbox_controls(self) -> None:
        """Keep overlay labels and edge actions aligned with the retained frame.

        Returns:
            None: Only presentation state changes; the active detail read remains.
        """
        controls_visible = self.detailOpen and (not self._fullscreen or self._lightbox_controls_visible)
        self._detail_controls.setVisible(controls_visible)
        for button in self._detail_edges:
            button.setVisible(controls_visible)
        self._set_lightbox_layout(self.detailOpen)
        self._layout_lightbox_controls()
        self._sync_content_crop_action()
        self._detail_title_label.setText(self.detailTitle)
        self._detail_position_label.setText(self.detailPosition)
        index = self._detail_index
        for button in self._detail_edges:
            button.setEnabled(index is not None and len(self.model.rows) > 1)
        self._detail_location_button.setEnabled(self._detail_location() is not None)

    def _set_lightbox_layout(self, active: bool) -> None:
        """Let the retained detail scene fill Photos while preserving normal layout.

        Args:
            active (bool): Whether the current detail owns the visual workspace.
        Returns:
            None: The same Quick widget remains mounted and restores exact split sizes.
        """
        if active and self._lightbox_layout_restore is None:
            widgets = (
                self._connect_card_outer, self._connect_card,
                self._center_toolbar, self._format_row,
                self._transport_row,
                self._gallery_panel, self.progress, self.status, self._inspector_panel,
                self._preload_row,
            )
            previous = {widget: widget.isHidden() for widget in widgets}
            self._lightbox_layout_restore = (previous, self._split.sizes())
            for widget in widgets:
                widget.hide()
            self.playback.close_panel()
            self._photos_layout.setContentsMargins(0, 0, 0, 0)
            self._center_layout.setContentsMargins(0, 0, 0, 0)
            self._sync_folder_section()
            self.lightbox_state_changed.emit(True)
            return
        if not active and self._lightbox_layout_restore is not None:
            previous, sizes = self._lightbox_layout_restore
            self._lightbox_layout_restore = None
            self._photos_layout.setContentsMargins(self._photos_margins)
            self._center_layout.setContentsMargins(*PHOTO_CARD_MARGINS)
            for widget, was_hidden in previous.items():
                if widget in (self._connect_card_outer, self._connect_card,
                              self._gallery_panel,
                              self.progress, self.status):
                    continue
                widget.setHidden(was_hidden)
            self._sync_connection_state()
            self._gallery_panel.setVisible(
                self._gallery_tree.topLevelItemCount() > 0,
            )
            self.progress.hide()
            self.status.setVisible(bool(self.status.text()))
            self._split.setSizes(sizes)
            self.lightbox_state_changed.emit(False)

    def _layout_lightbox_controls(self) -> None:
        """Center the compact toolbar card and limit its hit-test rectangle.

        Returns:
            None: All image-space pointer input remains outside the card bounds.
        """
        size = self._detail_toolbar.sizeHint()
        width = min(size.width(), max(1, self.quick.width() - 2 * LIGHTBOX_TOOLBAR_TOP_INSET))
        height = size.height()
        left = max(0, (self.quick.width() - width) // 2)
        self._detail_controls.setGeometry(
            left, LIGHTBOX_TOOLBAR_TOP_INSET, width, height,
        )
        inset = round(self.quick.width() * LIGHTBOX_EDGE_INSET)
        for position, button in enumerate(self._detail_edges):
            size = button.size()
            column = inset if position == 0 else self.quick.width() - inset - size.width()
            row = (self.quick.height() - size.height()) // 2
            button.setGeometry(column, row, size.width(), size.height())
            button.raise_()
        self._detail_controls.raise_()
        self._hdr_surface.set_controls(tuple(
            QRectF(control.geometry())
            for control in (self._detail_controls, *self._detail_edges)
            if not control.isHidden()
        ))

    def _show_lightbox_controls(self) -> None:
        """Reveal fullscreen chrome and restart its bounded idle timer.

        Returns:
            None: Pointer or keyboard use keeps accessible controls available.
        """
        if not self.detailOpen:
            return
        was_visible = self._lightbox_controls_visible
        self._lightbox_controls_visible = True
        if not was_visible or not self._detail_controls.isVisible():
            self._detail_controls.show()
            for button in self._detail_edges:
                button.show()
            self._layout_lightbox_controls()
        if self._fullscreen and self._detail_menu is None:
            self._lightbox_controls_timer.start()

    def _hide_lightbox_controls(self) -> None:
        """Hide only the fullscreen toolbar after pointer idle.

        Returns:
            None: The retained Quick image and HDR layer are not repainted.
        """
        if not self._fullscreen or not self.detailOpen:
            return
        self._lightbox_controls_visible = False
        self._detail_controls.hide()
        for button in self._detail_edges:
            button.hide()
        self._hdr_surface.set_controls(())

    def _detail_location(self) -> tuple[float, float] | None:
        """Read coordinate data only for the currently displayed linked image.

        Returns:
            tuple[float, float] | None: Valid coordinates from the exact live gallery.
        """
        index, session, gallery = self._detail_display_index, self._session, self._rendered_gallery
        if (index is None or not 0 <= index < len(self.model.rows)
                or session is None or gallery is None or not self._owns_gallery(session, gallery)):
            return None
        photo = self.model.rows[index].source
        if not isinstance(photo, LinkedPhoto):
            return None
        latitude, longitude = photo.latitude, photo.longitude
        if (type(latitude) not in (int, float)
                or type(longitude) not in (int, float)
                or not isfinite(latitude) or not isfinite(longitude)
                or not -90 <= latitude <= 90 or not -180 <= longitude <= 180):
            return None
        return float(latitude), float(longitude)

    def _show_location_menu(self) -> None:
        """Show the photo's validated coordinates beside the visible map pin.

        Returns:
            None: Opens the localized map action for the current linked image.
        """
        location = self._detail_location()
        if location is None:
            return
        latitude, longitude = location
        button = self._detail_location_button
        menu = QMenu(button)
        coordinates = menu.addAction(f"{latitude:.6f}, {longitude:.6f}")
        coordinates.setEnabled(False)
        menu.addAction(
            tr(self.language, "linking_media_open_map"),
            lambda: self._open_map(latitude, longitude),
        )
        self._open_detail_menu(menu, button.mapToGlobal(button.rect().bottomLeft()))

    def _show_lightbox_context(self, position: QPoint) -> None:
        """Offer secondary detail commands from the canvas context menu.

        Args:
            position (QPoint): Global pointer or keyboard invocation anchor.
        Returns:
            None: Leaves the primary Lightbox rail in the reference action order.
        """
        menu = QMenu(self.quick)
        index = self._detail_display_index
        if (index is not None and 0 <= index < len(self.model.rows)
                and isinstance(self.model.rows[index].source, LinkedPhoto)):
            menu.addAction(self._text('photo_info'), self._information_for_detail)
        key = 'linking_media_slideshow_stop' if self._playing else 'linking_media_slideshow_start'
        menu.addAction(tr(self.language, key), self.toggle_slideshow)
        self._open_detail_menu(menu, position)

    def _open_detail_menu(self, menu: QMenu, position: QPoint) -> None:
        """Anchor one transient popup to the current displayed detail.

        Args:
            menu (QMenu): Native menu created for the current detail action.
            position (QPoint): Global popup anchor.
        Returns:
            None: Retains the fullscreen rail while the popup is active.
        """
        if self._detail_menu is not None:
            self._detail_menu.close()
        self._detail_menu = menu
        self._lightbox_controls_timer.stop()
        menu.aboutToHide.connect(self._detail_menu_closed)
        menu.aboutToHide.connect(menu.deleteLater)
        menu.popup(position)

    def _detail_menu_closed(self) -> None:
        """Clear the popup reference and return control to the fullscreen timer.

        Returns:
            None: Preserves the retained photo and any permitted slideshow.
        """
        self._detail_menu = None
        if self._fullscreen and self.detailOpen:
            self._lightbox_controls_timer.start()

    def _open_map(self, latitude: float, longitude: float) -> None:
        """Open the current linked photo coordinates in OpenStreetMap.

        Args:
            latitude (float): Valid latitude from the accepted linked photo.
            longitude (float): Valid longitude from the accepted linked photo.
        Returns:
            None: Reports a browser launch failure in the Photos status line.
        """
        if self._detail_location() != (latitude, longitude):
            return
        url = (
            "https://www.openstreetmap.org/?mlat="
            f"{latitude:.6f}&mlon={longitude:.6f}#map={PHOTO_LOCATION_MAP_ZOOM}/"
            f"{latitude:.6f}/{longitude:.6f}"
        )
        try:
            opened = webbrowser.open(url)
        except Exception:
            opened = False
        if not opened:
            self.status_message(tr(self.language, "linking_media_map_open_failed"))

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

    def _sync_content_crop_action(self) -> None:
        """Name the fit action for its current crop/original result.

        Returns:
            None: The action restores the zoom when no matte was detected.
        """
        caption = tr(
            self.language,
            ("linking_media_show_original_frame" if self._detail_content_crop_enabled
             else "linking_media_fit_content") if self.detailHasContentCrop
            else "linking_media_fit",
        )
        self._detail_fit_button.setAccessibleName(caption)
        self._detail_fit_button.setToolTip(caption)
        self._detail_fit_button.setEnabled(bool(self._detail_keys))

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

    def _set_card_format(self, format_value: str) -> None:
        """Apply a presentation format without replacing authorized images.

        Args:
            format_value (str): One validated icon-row aspect ratio.
        Returns:
            None: Reflows only the visible Qt Quick delegates.
        """
        if format_value not in PHOTO_CARD_FORMATS:
            return
        self._format = format_value
        self._format_buttons[format_value].setChecked(True)
        if self._view_path is not None and self._gallery_id is not None:
            if save_gallery_card_format(
                self._view_path, self._gallery_id, self._format,
            ):
                self._format_unsaved.discard(self._gallery_id)
            else:
                self._format_unsaved.add(self._gallery_id)
        self._sync_format_actions()
        self.formatChanged.emit()

    @Slot(int)
    def set_batch_limit(self, size: int) -> None:
        """Update the next network group after the Settings owner saves its limit.

        Args:
            size (int): Newly saved steady request limit from Settings.
        Returns:
            None: Pending work still retains its previously admitted bound.
        """
        self._batch_limit = size

    def choose_files(self) -> None:
        """Open an explicit read-only local selection through the native picker.

        Returns:
            None: Uses the chosen files without modifying their contents.
        """
        if not self._can_disconnect():
            return
        paths, _selected = QFileDialog.getOpenFileNames(
            self, gettext("Open local photos"), "", gettext("Image files") + " (*.jpg *.jpeg *.png *.webp *.gif *.bmp *.tif *.tiff)",
        )
        if paths:
            self.open_files(tuple(Path(path) for path in paths))

    def open_files(self, paths: tuple[Path, ...]) -> None:
        """Replace the catalogue with explicitly selected local photo paths.

        Args:
            paths (tuple[Path, ...]): Paths from a native picker or isolated test.
        Returns:
            None: Displays bounded logical rows; file reads start in the viewport.
        """
        if not self._can_disconnect():
            return
        self.disconnect()
        self._local_photos_active = True
        self._gallery_title.setText(gettext("Local photos"))
        self._install_catalogue(tuple(
            PhotoRow(index, path.name, path)
            for index, path in enumerate(paths[:MEDIA_RECENT_ORDER_PHOTO_LIMIT])
        ))
        self._sync_connection_state()
        self.formatChanged.emit()

    def link(self) -> None:
        """Ask which gallery the operator wants to authorize for browsing.

        Returns:
            None: Starts a transient browser flow only on explicit acceptance.
        """
        self._authorize(False)

    def resume_saved_browse(self) -> None:
        """Restore the most recent verified Photos grant after native readiness.

        Returns:
            None: Requests at most one saved browsing grant on the read lane.
                Reopening Photos leaves upload admission with its explicit owner.
        """
        if (sys.platform not in ('win32', 'darwin') or self._session is not None
                or self._local_photos_active or self._load.busy()
                or self._authorization_dialog is not None):
            return
        recent = self._recent_gallery_addresses
        if self.connections is None and not recent:
            return
        self._startup_browse = True

        def restore(
            cancelled: Event,
            publish: Callable[[
                LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress
            ], None],
        ) -> None:
            """Resolve the stored issuer and restore it without browser consent.

            Args:
                cancelled (Event): Lifetime of this startup read.
                publish (Callable[[LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress], None]):
                    Current UI owner's catalogue sink.
            Returns:
                None: Mounts the verified account, if a cached grant is available.
            """
            try:
                saved = self.connections.saved() if self.connections is not None else None
            except RuntimeError:
                saved = None
            issuer = saved.issuer if saved is not None and saved.mode == 'linked' else None
            if not issuer and recent:
                issuer = recent[0]
            if issuer:
                publish(LinkingProgress(gettext('Checking the securely saved connection…')))
                self._publish_saved_browse(issuer, cancelled, publish)

        self._load.submit(restore)

    def _publish_saved_browse(
        self, issuer: str, cancelled: Event, publish: Callable[[
            LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress
        ], None],
    ) -> bool:
        """Transfer a restored gallery session to the current Photos generation.

        Args:
            issuer (str): Exact canonical address chosen by profile or operator.
            cancelled (Event): Current lifetime of the owning Photos read.
            publish (Callable[[LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress], None]):
                Queued receiver for the verified browsing session and tree.
        Returns:
            bool: True when a verified grant was published, false when absent.
        Raises:
            RuntimeError: Secure storage or gallery verification failed.
            ReadCancelled: The operation was replaced before publication.
        """
        site = restore_desktop_gallery_link(self.profile, issuer, cancelled)
        if site is None:
            return False
        try:
            galleries = read_gallery_tree(site.session, cancelled)
            path = photos_account_tree_path(self.profile, site.issuer, site.user_id)
            tree_state = load_photos_tree_preferences(path)
            if cancelled.is_set():
                raise ReadCancelled
            publish(LinkedPhotos(site.session, site.issuer, site.user_id, galleries, tree_state))
        except Exception:
            site.session.close()
            raise
        return True

    def _live_sites(self) -> tuple[DesktopLinkedSite, ...]:
        """Project available grants and retire expired parked sessions.

        Returns:
            tuple[DesktopLinkedSite, ...]: Current Photos grant followed by
                reusable parked grants that remain available.
        """
        selected = None
        if self._session is not None and self._identity is not None:
            selected = DesktopLinkedSite(
                self._identity[0], self._session, self._identity[1],
            )
        sites = live_link_sites(selected, self._parked_links)
        retained = tuple(
            parked for parked in self._parked_links
            if any(site.session is parked.session for site in sites)
        )
        expired = tuple(
            parked for parked in self._parked_links
            if not any(site.session is parked.session for site in retained)
        )
        self._parked_links = retained
        for site in expired:
            site.session.close()
        return sites

    def _park_current_link(self) -> None:
        """Retain the current exact grant while leaving its gallery view.

        Returns:
            None: Keeps only the grant identity and session, never its old view.
        """
        if self._session is None or self._identity is None:
            return
        current = DesktopLinkedSite(
            self._identity[0], self._session, self._identity[1],
        )
        if not self._contains_live_site(current):
            return
        self._parked_links = (
            current,
            *(site for site in self._parked_links if site.session is not current.session),
        )

    def _contains_live_site(self, site: DesktopLinkedSite) -> bool:
        """Match a projected live grant by issuer, session identity, and account.

        Args:
            site (DesktopLinkedSite): Exact grant candidate to validate.
        Returns:
            bool: True only while that exact grant remains selected or parked.
        """
        return any(
            candidate.issuer == site.issuer
            and candidate.session is site.session
            and candidate.user_id == site.user_id
            for candidate in self._live_sites()
        )

    def _select_live_site(self, site: DesktopLinkedSite) -> None:
        """Reload and mount one already approved site's tree on the read lane.

        Args:
            site (DesktopLinkedSite): Exact live grant chosen by the operator.
        Returns:
            None: Leaves the old site visible until the selected tree is verified.
        """
        if site.session is self._session and self._identity == (site.issuer, site.user_id):
            return
        if (self._authorization_active or not self._contains_live_site(site)
                or not self._can_switch_photos_site()):
            return
        self._pending_live_site = site
        self._start_progress()
        self.status.setText(gettext('Loading galleries…'))
        self._sync_connection_state()

        def load_tree(
            cancelled: Event,
            publish: Callable[
                [LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress], None
            ],
        ) -> None:
            """Read one parked grant's current tree and account preferences.

            Args:
                cancelled (Event): Cancellation event for this lane generation.
                publish (Callable[[LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress], None]):
                    GUI-thread delivery callback owned by the current read lane.
            Returns:
                None: Publishes only the selected grant's exact tree snapshot.
            """
            if cancelled.is_set():
                raise ReadCancelled
            galleries = (
                read_gallery_tree(site.session, cancelled)
                if site.session.can_list_galleries() else ()
            )
            path = photos_account_tree_path(self.profile, site.issuer, site.user_id)
            tree_state = load_photos_tree_preferences(path)
            if cancelled.is_set():
                raise ReadCancelled
            publish(LiveSiteCatalogue(site, galleries, tree_state))

        self._load.submit(load_tree)

    def link_uploads(self) -> None:
        """Request explicit upload and available Admin Photos permission.

        Returns:
            None: Opens browser consent and leaves target selection to the operator.
        """
        self._authorize(True)

    def approve_admin(self) -> None:
        """Request explicit Admin media consent for the currently linked site.

        Returns:
            None: Preserves existing Photos access until a replacement is verified.
        """
        session, identity = self._session, self._identity
        if session is not None and identity is not None:
            self._authorize(
                session.can_upload(), admin_previous=identity,
            )

    def reconnect_uploads(self) -> None:
        """Reconnect the exact previously selected gallery and Admin account.

        Returns:
            None: Verifies the saved target through renewed browser consent.
        """
        try:
            saved = self.connections.saved() if self.connections is not None else None
            if saved is not None and saved.mode == 'linked':
                self._authorize(True, saved)
            else:
                self.status_message('Gallery connection is unavailable.')
        except RuntimeError:
            self.status_message('Gallery connection is unavailable.')

    def reapprove_uploads(self) -> None:
        """Request fresh browser consent for the exact selected saved upload mode.

        Returns:
            None: Replacement requires explicit consent for the same account,
                gallery and approved scope.
        """
        connections = self.connections
        if connections is None or not self._can_disconnect():
            return
        try:
            saved = connections.saved()
            if saved is not None and saved.mode == 'linked':
                self._authorize(True, saved, reapprove=True)
                return
        except RuntimeError:
            pass
        self.status_message('The securely saved gallery connection is unavailable.')

    def forget_uploads(self) -> None:
        """Remove the selected offline credential through its existing native owner.

        Returns:
            None: Matches the stable local-only removal contract after confirmation.
        """
        connections = self.connections
        if connections is None or not self._can_disconnect():
            return
        try:
            saved = connections.saved()
        except RuntimeError:
            saved = None
        if saved is None or saved.mode != 'linked' or not saved.scope or not saved.scope.endswith(' offline_access'):
            self.status_message('The securely saved gallery connection is unavailable.')
            return
        answer = QMessageBox.question(
            self, gettext('Forget saved link'), gettext(
                'Remove the saved local credential? The current session remains active. '
                'This does not revoke the server grant. Manage gallery links to review or revoke it.',
            ), QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No,
            QMessageBox.StandardButton.No,
        )
        if answer != QMessageBox.StandardButton.Yes or not self._can_disconnect():
            return
        active = connections.active()

        def remove(cancelled: Event) -> None:
            """Remove only the mode that was still selected after confirmation.

            Args:
                cancelled (Event): Lifetime of the explicit local removal.
            Returns:
                None: Any current same-scope bearer stays usable until expiry.
            """
            if cancelled.is_set() or connections.saved() != saved:
                raise ReadCancelled
            forget_browser_upload_grant(self.profile, saved)
            if (active is not None and active.issuer == saved.issuer
                    and active.user_id == saved.account_id
                    and active.session.approved_scope() == saved.scope):
                active.session.disable_renewal()

        self._run_gallery_action(remove, gettext_noop(
            'Saved local credential removed; the current session and server grant may still exist.',
        ))

    def unlink(self) -> None:
        """Withdraw the selected gallery approval through its existing owner.

        Returns:
            None: Removes only the current grant and keeps its saved destination
                eligible for the normal verified reconnect and recovery workflow.
        """
        session, identity = self._session, self._identity
        if session is None or identity is None or not self._can_disconnect():
            return
        if self._authorization_active or self._startup_browse:
            self.status_message('Wait for the current gallery request to finish.')
            return
        site = DesktopLinkedSite(identity[0], session, identity[1])
        scope = session.approved_scope()
        self._keepalive.cancel()
        self.status_message('Removing the saved connection…')
        self.link_action_reported.emit(self.status.text())

        def remove(cancelled: Event) -> UnlinkedSite:
            """Remove only this exact binding and request its one server revocation.

            Args:
                cancelled (Event): Lifetime of the guarded unlink operation.
            Returns:
                UnlinkedSite: Exact local unlink and its sanitized server outcome.
            """
            if cancelled.is_set():
                raise ReadCancelled
            if sys.platform == 'darwin':
                transient = load_transient(site.issuer)
                if (transient is not None and transient.account_id == site.user_id
                        and transient.scope == scope):
                    delete_transient(site.issuer)
            binding = saved_desktop_gallery_binding(self.profile / 'offline-link.json', site.issuer)
            revoked = None
            if binding is not None and binding.account_id == site.user_id and binding.scope == scope:
                store = desktop_upload_vault(self.profile)
                if store is None:
                    raise LinkingError('Secure storage is unavailable.')
                revoked = revoke_saved_gallery_grant(
                    self.profile, site.issuer, site.user_id, binding, store,
                )
            site.session.disable_renewal()
            if revoked is None:
                try:
                    site.session.revoke()
                except (RuntimeError, OSError):
                    revoked = False
                else:
                    revoked = True
            return UnlinkedSite(site, revoked)

        self._unlink_pending = True
        self._run_gallery_action(remove, '', failure_caption=gettext_noop(
            'The saved secure reconnect could not be removed. The gallery remains connected.',
        ))

    def _authorize(
        self, uploads: bool, saved: ModeRecord | None = None, *,
        admin_previous: tuple[str, int] | None = None,
        reapprove: bool = False,
        address_override: str | None = None,
        requested_scope: str | None = None,
    ) -> None:
        """Obtain an explicit scoped grant without admitting background transfers.

        Args:
            uploads (bool): Request upload permission in addition to browsing.
            saved (ModeRecord | None): Original target when explicitly reconnecting.
            admin_previous (tuple[str, int] | None): Current verified site and
                account for a separately approved Admin grant.
            reapprove (bool): Request a new browser handoff for the exact saved scope.
            address_override (str | None): Validated address submitted by the
                persistent Photos authorization dialog.
            requested_scope (str | None): Exact scope chosen in the management
                controls, never a persisted upload-target transition.
        Returns:
            None: Opens approval and mounts a verified live gallery catalogue.
        """
        if uploads or saved is not None or admin_previous is not None:
            if not self._can_disconnect():
                return
        elif not self._can_switch_photos_site():
            return
        if requested_scope is not None and (
            saved is not None or admin_previous is not None or self._session is not None
            or scope_base(requested_scope) is None
        ):
            self.status_message('Gallery connection is unavailable.')
            return
        if address_override is None and (saved is None or admin_previous is not None):
            self._open_authorization_dialog(uploads, saved, admin_previous, reapprove)
            return
        if admin_previous is not None:
            address = admin_previous[0]
        elif address_override is not None:
            address = address_override
        elif saved is None:
            address = self.gallery_address
        else:
            address = saved.issuer or ''
        current, current_identity = self._session, self._identity
        admin_mode_snapshot = None
        admin_target_snapshot = None
        admin_binding_snapshot = None
        admin_store = None
        admin_upgrade_target = None
        if admin_previous is not None:
            if (current is None or current_identity != admin_previous
                    or not current.available()
                    or not current.matches_issuer(admin_previous[0])):
                self.status_message('Gallery connection is unavailable.')
                return
            if self.connections is not None:
                try:
                    admin_mode_snapshot = self.connections.saved()
                    admin_target_snapshot = self.connections.active()
                except RuntimeError:
                    admin_mode_snapshot = None
                    admin_target_snapshot = None
                    mode_snapshot_readable = False
                else:
                    mode_snapshot_readable = True
                current_scope = current.approved_scope()
                same_account_mode = bool(
                    admin_mode_snapshot is not None
                    and admin_mode_snapshot.mode == 'linked'
                    and (admin_mode_snapshot.issuer, admin_mode_snapshot.account_id)
                    == admin_previous
                )
                same_account_target = bool(
                    admin_target_snapshot is not None
                    and (admin_target_snapshot.issuer, admin_target_snapshot.user_id)
                    == admin_previous
                )
                exact_target_mode = bool(
                    same_account_mode and same_account_target
                    and admin_mode_snapshot is not None
                    and admin_target_snapshot is not None
                    and admin_mode_snapshot.gallery_id == admin_target_snapshot.gallery_id
                    and admin_mode_snapshot.scope
                    == admin_target_snapshot.session.approved_scope()
                    and admin_target_snapshot.session.available()
                    and admin_target_snapshot.session.can_upload()
                    and admin_target_snapshot.session.matches_issuer(admin_previous[0])
                )
                if (mode_snapshot_readable
                        and (exact_target_mode or not same_account_mode and not same_account_target)
                        and (exact_target_mode or not current.can_upload())
                        and (exact_target_mode or current_scope.endswith(' offline_access'))):
                    try:
                        binding = saved_desktop_gallery_binding(
                            self.profile / 'offline-link.json', admin_previous[0],
                        )
                        store = desktop_upload_vault(self.profile)
                    except (OSError, RuntimeError, ValueError):
                        binding = None
                        store = None
                    expected_scope = (
                        admin_mode_snapshot.scope if exact_target_mode
                        and admin_mode_snapshot is not None else current_scope
                    )
                    if (store is not None and binding is not None
                            and (binding.issuer, binding.account_id, binding.scope)
                            == (*admin_previous, expected_scope)):
                        # Reuse only the exact rolling grant backing this view or target.
                        admin_store = store
                        admin_binding_snapshot = binding
                        if exact_target_mode:
                            admin_upgrade_target = admin_target_snapshot
        fresh_browse = (
            requested_scope is None and saved is None
            and admin_previous is None and not uploads
        )
        reusable = None
        if (admin_previous is None and saved is not None and not reapprove and current is not None
                and self._identity == (saved.issuer, saved.account_id)
                and current.available() and current.can_upload()
                and current.approved_scope() == saved.scope):
            reusable = current
        preserve_current = admin_previous is not None or saved is None or reusable is not None
        if not preserve_current:
            self.disconnect()
        if self._attempt is not None:
            self._attempt.close()
        attempt = None
        try:
            issuer = linking_issuer(address)
            if fresh_browse and issuer:
                live_site = find_live_link(self._live_sites(), issuer)
                if live_site is not None:
                    self._close_authorization_dialog()
                    self._select_live_site(live_site)
                    self._remember_recent_address(issuer)
                    return
            if requested_scope is not None:
                scope = requested_scope
            elif admin_previous is not None:
                scope = (
                    UPLOAD_SCOPE if uploads or admin_upgrade_target is not None
                    else GALLERIES_SCOPE
                )
            elif saved is not None:
                scope = saved.scope
            else:
                scope = UPLOAD_SCOPE if uploads else GALLERIES_SCOPE
            if scope is None:
                raise LinkingError('Saved gallery access is unavailable.')
            if reusable is None and (
                admin_previous is not None or reapprove
                or not (saved is not None and scope.endswith(' offline_access'))
            ):
                attempt = LinkingAttempt(issuer, scope=scope)
        except LinkingError:
            if self._authorization_status is not None:
                self._authorization_status.setText(gettext("Gallery connection is unavailable."))
                self._authorization_status.show()
            else:
                self.status.setText(gettext("Gallery connection is unavailable."))
            return
        self._pending_live_site = None
        self._startup_browse = False
        self._attempt = attempt
        self._authorization_active = True
        self._authorization_failure = ''
        self._selecting_saved_target = saved is not None
        self._link_cancel_button.setVisible(
            self._authorization_dialog is None and not self._selecting_saved_target,
        )
        self.link_button.setEnabled(False)
        saved_link_prompt = gettext("Checking the securely saved connection…")
        self.status.setText(
            tr(self.language, 'linking_requesting') if attempt is not None
            else saved_link_prompt
        )
        self._sync_authorization_dialog()
        self.progress.hide()
        self._sync_connection_state()
        if self._selecting_saved_target:
            self.work_changed.emit(True)

        def authorize(
            cancelled: Event,
            publish: Callable[[PhotosLoadResult], None],
        ) -> None:
            """Exchange an explicit browser grant and verify its exact scope.

            Args:
                cancelled (Event): Current linking operation cancellation.
                publish (Callable[[PhotosLoadResult], None]): Queued receiver for
                    approved grants, authorized catalogues and progress.
            Returns:
                None: Transfers a live authorized grant or closes it on failure.
            """
            session = reusable
            saved_storage_unavailable = False
            step = 'restore'
            try:
                store = admin_store
                refresh = None
                refresh_deadline = None
                if attempt is not None:
                    if fresh_browse:
                        try:
                            if self._publish_saved_browse(issuer, cancelled, publish):
                                return
                        except CredentialStoreUnavailable:
                            # Explicit Connect may continue with browser consent
                            # when a saved native grant cannot be read safely.
                            saved_storage_unavailable = True
                        except (LinkingReconnectRequired, LinkingRefreshRejected):
                            # An explicit Connect may ask the user for fresh consent.
                            pass
                    if fresh_browse or (
                        uploads and self.connections is not None
                        and (reapprove or (saved is None and admin_previous is None))
                    ) or (requested_scope is not None
                          and 'offline_access' in requested_scope.split()):
                        store = (
                            None if saved_storage_unavailable
                            else desktop_upload_vault(self.profile)
                        )
                    if reapprove and scope.endswith(' offline_access') and store is None:
                        raise LinkingError('The securely saved gallery connection is unavailable.')
                    if (requested_scope is not None
                            and 'offline_access' in requested_scope.split()
                            and store is None):
                        raise LinkingError('Secure offline storage is unavailable.')
                    step = 'request'
                    request = attempt.push_request(
                        prefer_admin_media=(
                            admin_previous is not None
                            or saved is None and requested_scope is None
                        ),
                        require_admin_media=admin_previous is not None,
                        prefer_rolling=(
                            store is not None and (
                                admin_upgrade_target is not None
                                or saved is None and requested_scope is None
                            )
                        ),
                        prefer_presentation=(
                            saved is None and requested_scope is None
                        ),
                    )
                    if cancelled.is_set():
                        raise ReadCancelled
                    step = 'browser'
                    if not webbrowser.open(request.authorization_url):
                        raise LinkingError("Browser could not open the approval page.")
                    publish(LinkingProgress(tr(self.language, 'linking_approve_browser')))
                    step = 'callback'
                    if attempt.wait_for_callback() != "approved" or cancelled.is_set():
                        raise LinkingError("Browser access was not approved.")
                    step = 'exchange'
                    publish(LinkingProgress(gettext('Checking the approved identity…')))
                    if attempt.requests_offline_access():
                        exchange_started = time.monotonic()
                        session, refresh, lifetime = attempt.exchange_offline_once()
                        refresh_deadline = exchange_started + lifetime
                    else:
                        session = attempt.exchange_once()
                elif session is None:
                    assert saved is not None
                    session = open_saved_upload_session(self.profile, saved, cancelled)
                step = 'identity'
                if requested_scope is None:
                    request_upload = uploads or admin_upgrade_target is not None
                    if (request_upload and not session.can_upload()
                            or not request_upload and (
                                not session.can_read_media() or session.can_upload()
                            )):
                        raise LinkingError('Gallery connection is unavailable.')
                else:
                    requested_tokens = set(requested_scope.split())
                    approved_scope = session.approved_scope()
                    approved_tokens = set(approved_scope.split())
                    if (
                        not requested_tokens.issubset(approved_tokens)
                        or approved_tokens - requested_tokens - {'galleries:search'}
                        or scope_base(approved_scope) != scope_base(requested_scope)
                        or not session.matches_issuer(issuer)
                    ):
                        raise LinkingError('Gallery connection is unavailable.')
                    selected_permissions = (
                        ('galleries:list', session.can_list_galleries()),
                        ('gallery:upload', session.can_upload()),
                        ('gallery:read', session.can_read_gallery_detail()),
                        ('galleries:create', session.can_create_gallery()),
                        ('gallery:visibility', session.can_change_gallery_visibility()),
                    )
                    if any(
                        token in requested_tokens and not admitted
                        for token, admitted in selected_permissions
                    ):
                        raise LinkingError('Gallery connection is unavailable.')
                    if (('media:read' in requested_tokens or 'media:admin' in requested_tokens)
                            and not session.can_read_media()):
                        raise LinkingError('Gallery connection is unavailable.')
                identity = session.identity()
                if admin_previous is not None and (
                    not session.can_read_admin_media() or identity.user_id != admin_previous[1]
                ):
                    raise LinkingError('Admin photo access is unavailable.')
                target = None
                selected = None
                if admin_upgrade_target is not None:
                    if (self.connections is None or admin_mode_snapshot is None
                            or admin_binding_snapshot is None or admin_store is None
                            or admin_upgrade_target.issuer != issuer
                            or admin_upgrade_target.user_id != identity.user_id
                            or admin_mode_snapshot.gallery_id != admin_upgrade_target.gallery_id
                            or admin_mode_snapshot.scope != admin_binding_snapshot.scope
                            or session.approved_scope() == admin_binding_snapshot.scope
                            or not session.can_upload()
                            or not session.can_read_admin_media()):
                        raise LinkingError('Admin photo access is unavailable.')
                    target = verify_linked_upload_target(
                        session, issuer, identity.user_id, session.approved_scope(),
                        admin_upgrade_target.gallery_id, cancelled,
                    )
                elif saved is not None:
                    if (self.connections is None or saved.account_id != identity.user_id
                            or saved.gallery_id is None or saved.scope != session.approved_scope()):
                        raise LinkingError('Gallery connection is unavailable.')
                    target = verify_linked_upload_target(
                        session, issuer, identity.user_id, session.approved_scope(),
                        saved.gallery_id, cancelled,
                    )
                    if cancelled.is_set() or self.connections.saved() != saved:
                        raise ReadCancelled
                step = 'catalogue'
                publish(LinkingProgress(gettext('Loading galleries…')))
                galleries = (
                    read_gallery_tree(session, cancelled)
                    if session.can_list_galleries() else ()
                )
                if (admin_upgrade_target is not None and refresh is not None
                        and refresh_deadline is not None and admin_store is not None):
                    remaining = int(refresh_deadline - time.monotonic())
                    if cancelled.is_set() or remaining <= 0 or target is None:
                        raise ReadCancelled
                    binding = OfflineBinding(issuer, identity.user_id, session.approved_scope())
                    if (self.connections is None
                            or self.connections.saved() != admin_mode_snapshot
                            or self.connections.active() is not admin_upgrade_target
                            or saved_desktop_gallery_binding(
                                self.profile / 'offline-link.json', issuer,
                            ) != admin_binding_snapshot):
                        raise LinkingError('Saved gallery credential changed.')
                    tree_state = load_photos_tree_preferences(
                        photos_account_tree_path(self.profile, issuer, identity.user_id),
                    )
                    publish(PhotosUploadUpgrade(
                        session, current, target, admin_mode_snapshot,
                        admin_upgrade_target, binding, admin_store,
                        admin_binding_snapshot.scope, refresh, refresh_deadline,
                        galleries, tree_state,
                    ))
                    session = None
                    return
                if refresh is not None and refresh_deadline is not None and store is not None:
                    step = 'storage'
                    remaining = int(refresh_deadline - time.monotonic())
                    if requested_scope is not None:
                        if cancelled.is_set() or remaining <= 0 or not session.available():
                            raise ReadCancelled
                        approved_scope = session.approved_scope()
                        binding = OfflineBinding(issuer, identity.user_id, approved_scope)
                        path = saved_offline_hint_path(
                            self.profile / 'offline-link.json', issuer, approved_scope,
                        )
                        with store.binding_transaction(issuer, identity.user_id):
                            if (cancelled.is_set()
                                    or load_offline_binding(path, issuer) is not None
                                    or store.read(issuer, identity.user_id) is not None):
                                raise LinkingError('Saved gallery credential changed.')
                            save_offline_binding(path, binding)
                            try:
                                store_approved_refresh(
                                    store, issuer, identity.user_id, approved_scope,
                                    refresh, remaining,
                                )
                            except Exception:
                                remove_offline_binding(path, binding)
                                raise
                    elif fresh_browse:
                        persist_browser_gallery_grant(
                            self.profile, store, issuer, identity, session,
                            refresh, remaining, cancelled,
                        )
                    elif (saved is None and admin_upgrade_target is None and current is not None
                          and current_identity == (issuer, identity.user_id)
                          and not current.can_upload()
                          and current.approved_scope().endswith(' offline_access')):
                        if admin_previous is not None:
                            if (admin_store is None or admin_binding_snapshot is None
                                    or self.connections is None
                                    or self.connections.saved() != admin_mode_snapshot
                                    or self.connections.active() != admin_target_snapshot
                                    or saved_desktop_gallery_binding(
                                        self.profile / 'offline-link.json', issuer,
                                    ) != admin_binding_snapshot):
                                raise LinkingError('Saved gallery credential changed.')
                        if cancelled.is_set() or remaining <= 0 or not current.available():
                            raise ReadCancelled
                        prior_mode = self.connections.saved() if self.connections is not None else None
                        approved_scope = session.approved_scope()
                        if (prior_mode is not None and prior_mode.mode == 'linked'
                                and (prior_mode.issuer, prior_mode.account_id) == current_identity
                                and prior_mode.scope != approved_scope):
                            raise LinkingError('Reconnect with the original Watcher upload permissions.')
                        binding = OfflineBinding(issuer, identity.user_id, approved_scope)
                        persist_browser_scope_upgrade(
                            self.profile / 'offline-link.json', store, binding,
                            current.approved_scope(),
                            refresh, remaining,
                        )
                        current.disable_renewal()
                        hint = saved_offline_hint_path(
                            self.profile / 'offline-link.json', issuer, approved_scope,
                        )
                        enable_rolling_renewal(session, store, hint, binding)
                    else:
                        persist_browser_upload_grant(
                            self.profile, store, issuer, identity, session, refresh,
                            remaining, cancelled, previous_mode=saved if reapprove else None,
                        )
                elif (attempt is not None
                      and (not fresh_browse or store is not None)):
                    remember_desktop_transient_link(session, identity.user_id)
                if target is not None and admin_upgrade_target is None:
                    assert self.connections is not None
                    if cancelled.is_set() or self.connections.saved() != saved:
                        raise ReadCancelled
                    self.connections.select(target)
                    selected = target.gallery_id
                path = photos_account_tree_path(self.profile, issuer, identity.user_id)
                tree_state = load_photos_tree_preferences(path)
                if selected is not None:
                    tree_state = replace(tree_state, selected=selected)
                publish(LinkedPhotos(
                    session, issuer, identity.user_id, galleries, tree_state,
                    owns_session=reusable is None,
                ))
                session = None
            except ReadCancelled:
                raise
            except Exception as error:
                if not cancelled.is_set():
                    caption = self._authorization_failure_caption(step, error)
                    publish(LinkingProgress(caption, failed=True))
                raise
            finally:
                if attempt is not None:
                    attempt.close()
                if session is not None and session is not reusable:
                    session.close()

        self._load.submit(authorize)

    def _authorization_failure_caption(self, step: str, error: Exception) -> str:
        """Report a linking failure without logging the address or credentials.

        Args:
            step (str): Fixed phase owned by the current authorization worker.
            error (Exception): Worker failure; only bounded protocol facts or its
                class are admitted to the log.
        Returns:
            str: Localized failure caption for the current dialog or Photos status.
        """
        logger = logging.getLogger(__name__)
        if isinstance(error, (LinkingResponseError, LinkingUnavailable)):
            logger.warning('Qt gallery linking failed: step=%s; %s', step, error.safe_summary)
            if isinstance(error, LinkingUnavailable):
                return tr(self.language, 'linking_website_address_unavailable')
            transport_message = {
                'dns_failure': gettext_noop('Could not resolve the gallery address (DNS).'),
                'connect_failure': gettext_noop('Could not connect to the gallery service.'),
                'tls_failure': gettext_noop('Could not establish secure HTTPS to the gallery.'),
                'timeout': gettext_noop('The gallery request timed out.'),
            }.get(error.reason)
            if transport_message:
                return gettext(transport_message)
        else:
            logger.warning('Qt gallery linking failed: step=%s; reason=%s',
                           step, type(error).__name__)
        if step == 'callback':
            return tr(self.language, 'linking_browser_approval_failed')
        message = {
            'request': gettext_noop('Could not connect to the gallery service.'),
            'browser': gettext_noop('Could not open the browser. Try connecting again.'),
            'exchange': gettext_noop('The linking response could not be accepted.'),
            'identity': gettext_noop('The linking response could not be accepted.'),
            'catalogue': gettext_noop('Could not load galleries.'),
            'storage': gettext_noop('The approved Photos connection could not be saved safely.'),
        }.get(step, gettext_noop('Gallery connection is unavailable.'))
        return gettext(message)

    def _open_authorization_dialog(
        self, uploads: bool, saved: ModeRecord | None,
        admin_previous: tuple[str, int] | None, reapprove: bool,
    ) -> None:
        """Present the persistent address and approval controls for Photos.

        Args:
            uploads (bool): Whether the submitted grant requests upload scope.
            saved (ModeRecord | None): Existing saved target, when reconnecting.
            admin_previous (tuple[str, int] | None): Existing Admin identity.
            reapprove (bool): Whether the existing scope requires a new grant.
        Returns:
            None: Opens the modal address form without blocking Qt's event loop.
        """
        if self._authorization_dialog is not None:
            self._authorization_dialog.raise_()
            self._authorization_dialog.activateWindow()
            return
        if self._startup_browse:
            self._startup_browse = False
            self._load.cancel()
            self.status.clear()
        dialog = QDialog(self)
        dialog.setObjectName('photosAuthorizationDialog')
        dialog.setWindowTitle(tr(self.language, 'linking_connect_website'))
        dialog.setWindowModality(Qt.WindowModality.WindowModal)
        dialog.setProperty('shellCard', True)
        layout = QVBoxLayout(dialog)
        title = QLabel(tr(self.language, 'linking_website_address'))
        title.setObjectName('heading')
        layout.addWidget(title)
        address = QLineEdit(self.gallery_address)
        address.setObjectName('photosAuthorizationAddress')
        address.setPlaceholderText(tr(self.language, 'linking_website_address'))
        active_issuer = self._identity[0] if self._identity is not None else ''
        initial_address = active_issuer or self.gallery_address or (
            self._recent_gallery_addresses[0]
            if self._recent_gallery_addresses else ''
        )
        address.setText(initial_address)
        if saved is not None or admin_previous is not None:
            address.setReadOnly(True)
            address.setText(
                admin_previous[0] if admin_previous is not None
                else saved.issuer if saved is not None else ''
            )
        layout.addWidget(address)
        recent = QTreeWidget(dialog)
        recent.setObjectName('photosAuthorizationRecent')
        recent.setAccessibleName(tr(self.language, 'linking_website_address'))
        recent.setHeaderHidden(True)
        recent.setUniformRowHeights(True)
        recent.setHorizontalScrollBarPolicy(Qt.ScrollBarPolicy.ScrollBarAlwaysOff)
        layout.addWidget(recent)
        status = QLabel(tr(self.language, 'linking_website_address_hint'))
        status.setObjectName('photosAuthorizationStatus')
        status.setWordWrap(True)
        layout.addWidget(status)
        layout.addStretch()

        def submit_address() -> None:
            """Validate the visible address before starting authorization.

            Returns:
                None: Retains the dialog on invalid input or starts the request.
            """
            issuer = linking_prompt_issuer(address.text())
            if not issuer:
                status.setText(tr(self.language, 'linking_website_address_invalid'))
                address.setFocus()
                return
            address.setText(issuer)
            submit.setProperty('authorizationSubmitted', True)
            self._sync_authorization_history_actions(issuer)
            self.gallery_address = issuer
            self._authorize(
                uploads, saved, admin_previous=admin_previous,
                reapprove=reapprove, address_override=issuer,
            )

        buttons = QHBoxLayout()
        forget = ActionButton(
            tr(self.language, 'linking_forget_address'),
            self._forget_authorization_recent_address,
            icon='close', icon_only=True,
        )
        forget.setObjectName('photosAuthorizationForget')
        buttons.addWidget(forget)
        buttons.addStretch()
        cancel = ActionButton(gettext('Cancel'), self.cancel_authorization)
        cancel.setObjectName('photosAuthorizationCancel')
        buttons.addWidget(cancel)
        submit = ActionButton(
            tr(self.language, 'linking_connect_website'), submit_address, primary=True,
        )
        submit.setObjectName('photosAuthorizationSubmit')
        submit.setDefault(True)
        submit.setProperty('mayReplaceWebsiteRequest', saved is None and admin_previous is None and not uploads)
        submit.setProperty('authorizationSubmitted', False)
        buttons.addWidget(submit)
        layout.addLayout(buttons)
        self._authorization_dialog = dialog
        self._authorization_address = address
        self._authorization_status = status
        self._authorization_submit = submit
        self._authorization_recent = recent
        self._authorization_forget = forget
        self._render_authorization_addresses()
        recent.currentItemChanged.connect(self._select_authorization_recent_address)
        address.textChanged.connect(self._sync_authorization_history_actions)
        self._sync_authorization_history_actions(address.text())
        owner = self.window()
        dialog.resize(
            min(DESKTOP_DIALOG_SIZE[0], max(1, owner.width() - 2 * DESKTOP_DIALOG_MARGIN)),
            min(DESKTOP_DIALOG_SIZE[1], max(1, owner.height() - 2 * DESKTOP_DIALOG_MARGIN)),
        )
        dialog.move(owner.mapToGlobal(owner.rect().center()) - dialog.rect().center())
        dialog.finished.connect(self._authorization_dialog_closed)
        dialog.open()
        self._sync_connection_state()
        address.setFocus()

    def _sync_authorization_dialog(self) -> None:
        """Mirror the active authorization phase inside its modal surface.

        Returns:
            None: Freezes the address and permits replacement only for browsing.
        """
        dialog = self._authorization_dialog
        address = self._authorization_address
        status = self._authorization_status
        submit = self._authorization_submit
        if dialog is None or status is None or address is None or submit is None:
            return
        if self.status.text():
            status.setText(self.status.text())
        address.setEnabled(not self._authorization_active)
        submit.setEnabled(
            not self._authorization_active
            or bool(submit.property('mayReplaceWebsiteRequest')),
        )
        self._sync_authorization_history_actions(address.text())

    def _render_authorization_addresses(self) -> None:
        """Show active and recent addresses as distinct, compact inline groups.

        Returns:
            None: Keeps the prefilled address as a keyboard starting point.
                Only user input marks a history row selected. Bound addresses
                remain read-only.
        """
        tree = self._authorization_recent
        address = self._authorization_address
        if tree is None or address is None:
            return
        live = () if address.isReadOnly() else tuple(site.issuer for site in self._live_sites())
        current = self._identity[0] if self._identity is not None else ''
        recent = tuple(issuer for issuer in self._recent_gallery_addresses
                       if issuer not in live and not (address.isReadOnly() and issuer == current))
        count = 0
        with QSignalBlocker(tree):
            tree.clear()
            for group_name, issuers in (
                ('linking_live_addresses', live), ('linking_recent_addresses', recent),
            ):
                if not issuers:
                    continue
                group = QTreeWidgetItem(tree, [tr(self.language, group_name)])
                group.setFlags(group.flags() & ~Qt.ItemFlag.ItemIsSelectable)
                for issuer in issuers:
                    item = QTreeWidgetItem(group, [('✓ ' + issuer) if issuer == current else issuer])
                    item.setData(0, Qt.ItemDataRole.UserRole, issuer)
                    if issuer == address.text():
                        tree.setCurrentItem(item)
                group.setExpanded(True)
                count += len(issuers) + 1  # The group label occupies one row.
            tree.clearSelection()
        tree.setVisible(count > 0)
        if count:
            row_height = max(tree.fontMetrics().height(), tree.sizeHintForRow(0))
            tree.setFixedHeight(min(count, LINKING_ADDRESS_VISIBLE_ROWS) * row_height + 2 * tree.frameWidth())

    def _select_authorization_recent_address(
        self, current: QTreeWidgetItem | None, _previous: QTreeWidgetItem | None,
    ) -> None:
        """Prefill the address field from the shared local recent list.

        Args:
            current (QTreeWidgetItem | None): Newly selected address or group.
            _previous (QTreeWidgetItem | None): Former selection emitted by Qt.
        Returns:
            None: Copies only an admitted history value into the address field.
        """
        address = self._authorization_address
        issuer = current.data(0, Qt.ItemDataRole.UserRole) if current is not None else None
        if (not isinstance(issuer, str) or not issuer or address is None
                or address.isReadOnly() or self._authorization_active
                or (issuer not in self._recent_gallery_addresses
                    and find_live_link(self._live_sites(), issuer) is None)):
            return
        address.setText(issuer)

    def _sync_authorization_history_actions(self, text: str) -> None:
        """Update the live-site action and local history removal state.

        Args:
            text (str): Current field text emitted by QLineEdit.
        Returns:
            None: Keeps the submit caption and removal affordance aligned with
                the visible address without changing any grant or target.
        """
        recent = self._authorization_recent
        forget = self._authorization_forget
        address = self._authorization_address
        submit = self._authorization_submit
        if recent is None or forget is None or address is None or submit is None:
            return
        issuer = linking_issuer(text)
        live_issuers = {site.issuer for site in self._live_sites()}
        recent.setEnabled(not self._authorization_active and not address.isReadOnly())
        caption = 'linking_connect_website'
        if self._authorization_active:
            caption = 'linking_connect_again'
        elif (bool(submit.property('mayReplaceWebsiteRequest'))
              and issuer in live_issuers):
            caption = 'linking_open_saved_link'
        elif bool(submit.property('authorizationSubmitted')):
            caption = 'linking_connect_again'
        submit.setText(tr(self.language, caption))
        forget.setVisible(
            bool(issuer and issuer in self._recent_gallery_addresses
                 and issuer not in live_issuers),
        )
        forget.setEnabled(not self._authorization_active)

    def _forget_authorization_recent_address(self) -> None:
        """Remove the selected inactive address from shared profile history.

        Returns:
            None: Removes only the local non-secret history entry; grants remain.
        """
        recent = self._authorization_recent
        address = self._authorization_address
        status = self._authorization_status
        if recent is None or address is None or status is None:
            return
        issuer = linking_issuer(address.text())
        live_issuers = {site.issuer for site in self._live_sites()}
        if (not issuer or issuer not in self._recent_gallery_addresses
                or issuer in live_issuers or self._authorization_active):
            return
        remaining = tuple(
            value for value in self._recent_gallery_addresses if value != issuer
        )
        try:
            save_recent_gallery_addresses(
                self._recent_gallery_address_path, remaining,
            )
        except (OSError, ValueError):
            self.status.setText(tr(self.language, 'linking_address_history_error'))
            status.setText(self.status.text())
            return
        self._recent_gallery_addresses = remaining
        address.clear()
        self._render_authorization_addresses()
        self.status.setText(tr(self.language, 'linking_address_removed'))
        status.setText(self.status.text())
        self._sync_authorization_history_actions(address.text())

    def _remember_recent_address(self, issuer: str) -> None:
        """Move a verified site to the front of the bounded shared history.

        Args:
            issuer (str): Exact canonical issuer from an accepted Photos session.
        Returns:
            None: Records only non-secret address history; failure preserves old data.
        """
        if not issuer or linking_issuer(issuer + '/index.php') != issuer:
            return
        updated = (issuer, *(value for value in self._recent_gallery_addresses
                             if value != issuer))
        addresses = updated[:RECENT_GALLERY_ADDRESS_LIMIT]
        if addresses == self._recent_gallery_addresses:
            return
        try:
            save_recent_gallery_addresses(
                self._recent_gallery_address_path, addresses,
            )
        except (OSError, ValueError):
            self.status.setText(tr(self.language, 'linking_address_history_error'))
            return
        self._recent_gallery_addresses = addresses

    def _authorization_dialog_closed(self, result: int) -> None:
        """Cancel pending authorization when the native modal is dismissed.

        Args:
            result (int): Native QDialog completion code.
        Returns:
            None: Clears dialog references after fencing active work.
        """
        dialog = self._authorization_dialog
        self._authorization_dialog = None
        self._authorization_address = None
        self._authorization_status = None
        self._authorization_submit = None
        self._authorization_recent = None
        self._authorization_forget = None
        if dialog is not None:
            dialog.deleteLater()
        if self._authorization_active:
            self.cancel_authorization()
        else:
            self._sync_connection_state()

    def _close_authorization_dialog(self) -> None:
        """Close a completed Photos authorization prompt safely.

        Returns:
            None: Dismisses the modal and releases its stored widget references.
        """
        dialog = self._authorization_dialog
        if dialog is not None:
            dialog.done(0)

    def cancel_authorization(self) -> None:
        """Cancel only the active link request while retaining prior approvals.

        Returns:
            None: Fences pending Photos results and leaves any current grant live.
        """
        if self._selecting_saved_target:
            return
        if not self._authorization_active:
            self._close_authorization_dialog()
            return
        self._authorization_active = False
        self._load.cancel()
        attempt, self._attempt = self._attempt, None
        if attempt is not None:
            attempt.close()
        self.link_button.setEnabled(True)
        self._link_cancel_button.hide()
        self.progress.hide()
        self.status.setText(gettext('Gallery linking cancelled.'))
        self._close_authorization_dialog()
        self._sync_connection_state()

    def status_message(self, message: str) -> None:
        """Display one localized gallery result without copying remote details.

        Args:
            message (str): Fixed local status or a structured sanitized caption.
        Returns:
            None: Updates the workspace, modal and picker status owners.
        """
        caption = gettext(message)
        self.status.setText(caption)
        if self._authorization_status is not None:
            self._authorization_status.setText(caption)
        if self._gallery_picker_feedback is not None:
            self._gallery_picker_feedback.setText(caption)

    def _text(self, key: str) -> str:
        """Use the shared localized Photos action and metadata catalogue.

        Args:
            key (str): Photos translation key without its namespace.
        Returns:
            str: Label in the active interface language.
        """
        return tr(self.language, f'photos_workspace_{key}')

    def busy(self) -> bool:
        """Identify a gallery operation that must settle before replacing its session.

        Returns:
            bool: True while the gallery action owner can still publish a result.
        """
        return (
            self._maintenance_busy or self._selecting_saved_target
            or self._gallery_editor is not None and self._gallery_editor.busy()
        )

    def _can_disconnect(self) -> bool:
        """Check grant ownership before browsing replaces the current connection.

        Returns:
            bool: Closing this grant cannot withdraw an accepted request.
        """
        if self.busy() or self.transfer_busy():
            self.status_message('Wait for the current operation to finish.')
            return False
        return True

    def _can_switch_photos_site(self) -> bool:
        """Guard view replacement while leaving the independent upload target alone.

        Returns:
            bool: True when no Photos mutation owns the current gallery view.
        """
        if self.busy():
            self.status_message('Wait for the current operation to finish.')
            return False
        return True

    def select_saved_key(self) -> None:
        """Request the existing explicit return to the saved API-key mode.

        Returns:
            None: Keeps the browsing grant separate from the newly selected mode.
        """
        connections = self.connections
        if connections is None or not self._can_disconnect():
            return
        caption = gettext('Use saved API key')
        if QMessageBox.question(self, caption, caption) == QMessageBox.StandardButton.Yes:
            self._run_gallery_action(
                lambda _cancel: connections.legacy(), gettext_noop('Saved API-key connection selected.'),
            )

    def open_gallery_picker(self) -> None:
        """Offer the verified site's gallery list from the global header.

        Returns:
            None: Opens a scoped searchable chooser using the current tree model.
        """
        session, identity = self._session, self._identity
        if session is None or identity is None or not session.can_list_galleries():
            self.status_message('Link the gallery to load its gallery list.')
            return
        if self._gallery_picker is not None:
            self._gallery_picker.raise_()
            self._gallery_picker.activateWindow()
            return
        dialog = QDialog(self.window())
        dialog.setObjectName('linkedGalleryPicker')
        dialog.setAttribute(Qt.WidgetAttribute.WA_DeleteOnClose)
        dialog.setWindowModality(Qt.WindowModality.WindowModal)
        picker_token = uuid4()
        title = gettext('Choose gallery')
        dialog.setWindowTitle(title)
        layout = QVBoxLayout(dialog)
        layout.addWidget(QLabel(identity[0]))
        search_label = tr(self.language, 'linking_gallery_search')
        search_row = QHBoxLayout()
        search = QLineEdit()
        search.setObjectName('linkedGallerySearch')
        search.setPlaceholderText(search_label)
        search.setAccessibleName(search_label)
        search_row.addWidget(search, stretch=1)
        search_row.addWidget(ActionButton(
            tr(self.language, 'linking_clear_gallery_search'), search.clear,
            icon='close', icon_only=True,
        ))
        layout.addLayout(search_row)
        model = QSortFilterProxyModel(dialog)
        model.setSourceModel(self._gallery_tree.model())
        model.setRecursiveFilteringEnabled(True)
        model.setFilterCaseSensitivity(Qt.CaseSensitivity.CaseInsensitive)
        tree = QTreeView()
        tree.setObjectName('linkedGalleryChoices')
        tree.setAccessibleName(title)
        tree.setModel(model)
        tree.setHeaderHidden(True)
        tree.expandAll()
        tree.setCurrentIndex(model.mapFromSource(self._gallery_tree.currentIndex()))
        layout.addWidget(tree, stretch=1)
        feedback = QLabel()
        feedback.setWordWrap(True)
        feedback.setObjectName('status')
        layout.addWidget(feedback)
        recovery = self.connections.recovery if self.connections is not None else None
        recovery_target = self._current_linked_recovery_target(session, identity)
        recovery_row = QWidget(dialog)
        recovery_layout = QHBoxLayout(recovery_row)
        recovery_layout.setContentsMargins(0, 0, 0, 0)
        check_recovery = ActionButton(
            gettext('Check uncertain upload'),
            lambda: self._check_linked_recovery(
                dialog, session, identity, recovery_target, recovery, picker_token,
            ),
        )
        check_recovery.setObjectName('linkedRecoveryCheck')
        review_recovery = ActionButton(
            tr(self.language, 'linking_review_title'),
            lambda: self._review_linked_recovery(
                dialog, session, identity, recovery_target, recovery,
            ),
        )
        review_recovery.setObjectName('linkedRecoveryReview')
        recovery_layout.addWidget(check_recovery)
        recovery_layout.addWidget(review_recovery)
        recovery_layout.addStretch()
        recovery_available = recovery_target is not None and recovery is not None
        recovery_row.setVisible(recovery_available)
        layout.addWidget(recovery_row)

        def recovery_work_changed(active: bool) -> None:
            """Keep recovery actions inside the exact current writer fence.

            Args:
                active (bool): Whether a Photos operation currently owns work.
            Returns:
                None: Stale or busy target actions cannot be newly admitted.
            """
            allowed = bool(
                not active and not self.transfer_busy()
                and recovery_target is not None
                and self._current_linked_recovery_target(session, identity)
                is recovery_target
            )
            check_recovery.setEnabled(allowed)
            review_recovery.setEnabled(allowed)

        recovery_work_changed(self.busy())
        footer = QHBoxLayout()
        can_upload = session.can_upload()
        caption = tr(self.language, 'linking_use_for_upload' if can_upload else 'linking_select_gallery')

        def choose() -> None:
            """Apply the exact gallery only while its original site owns the tree.

            Returns:
                None: Selects the Photos gallery or queues one verified upload target.
            """
            selected = tree.currentIndex().data(Qt.ItemDataRole.UserRole)
            if (not selected or self._session is not session or self._identity != identity
                    or not session.available()):
                return
            gallery = int(selected)
            if gallery not in self._gallery_items:
                return
            if can_upload:
                self.select_upload_gallery(gallery, confirm=False)
            else:
                self._gallery_tree.setCurrentItem(self._gallery_items[gallery])
                feedback.setText(tr(self.language, 'linking_upload_approval_needed'))

        select = ActionButton(caption, choose, primary=True)
        select.setObjectName('linkedGalleryChoose')
        select.setEnabled(tree.currentIndex().isValid())

        def selection_changed(index: QModelIndex, _previous: QModelIndex) -> None:
            """Enable actions only after a currently filtered row is selected.

            Args:
                index (QModelIndex): New selection in the shared model projection.
                _previous (QModelIndex): Previous selection, unused.
            Returns:
                None: Updates just the modal's primary action.
            """
            select.setEnabled(index.isValid() and not self.busy())

        def work_changed(active: bool) -> None:
            """Hold repeating selection while exact upload admission is pending.

            Args:
                active (bool): Whether a guarded Photos operation is still active.
            Returns:
                None: Reflects work ownership in the current dialog's action.
            """
            select.setEnabled(not active and tree.currentIndex().isValid())

        def closed(_result: int) -> None:
            """Release the dialog-owned status and operation observer.

            Args:
                _result (int): Native close code, unused.
            Returns:
                None: Pending owner work may finish safely after its view closes.
            """
            self.work_changed.disconnect(work_changed)
            self.work_changed.disconnect(recovery_work_changed)
            self._gallery_picker = None
            if self._gallery_picker_token == picker_token:
                self._gallery_picker_token = None
            self._gallery_picker_feedback = None

        tree.selectionModel().currentChanged.connect(selection_changed)
        tree.doubleClicked.connect(lambda _index: choose())
        search.textChanged.connect(model.setFilterFixedString)
        search.returnPressed.connect(tree.setFocus)
        footer.addWidget(select)
        footer.addStretch()
        footer.addWidget(ActionButton(gettext('Close'), dialog.reject))
        layout.addLayout(footer)
        self._gallery_picker = dialog
        self._gallery_picker_token = picker_token
        self._gallery_picker_feedback = feedback
        self.work_changed.connect(work_changed)
        self.work_changed.connect(recovery_work_changed)
        dialog.finished.connect(closed)
        dialog.open()
        search.setFocus(Qt.FocusReason.ShortcutFocusReason)

    def _current_linked_recovery_target(
        self, session: LinkingSession, identity: tuple[str, int],
    ) -> LinkedUploadTarget | None:
        """Return only the exact upload target bound to this live picker.

        Args:
            session (LinkingSession): Live Photos approval represented by the picker.
            identity (tuple[str, int]): Exact canonical issuer and Admin account.
        Returns:
            LinkedUploadTarget | None: Current same-session target with recovery
                authority, or None when mode/session evidence is unavailable.
        """
        connections = self.connections
        if connections is None or connections.recovery is None:
            return None
        try:
            target = connections.active()
        except (RuntimeError, ValueError):
            return None
        if (target is None or target.session is not session
                or (target.issuer, target.user_id) != identity
                or self._session is not session or self._identity != identity
                or not session.available() or not session.can_upload()):
            return None
        return target

    def _check_linked_recovery(
        self, dialog: QDialog, session: LinkingSession,
        identity: tuple[str, int], target: LinkedUploadTarget | None,
        recovery: LinkedRecoveryActions | None, picker_token: UUID,
    ) -> None:
        """Observe one explicitly sized uncertain upload without opening files.

        Args:
            dialog (QDialog): Current chooser that owns the action and feedback.
            session (LinkingSession): Exact live Photos approval at admission.
            identity (tuple[str, int]): Canonical issuer and verified Admin ID.
            target (LinkedUploadTarget | None): Exact target captured by the chooser.
            recovery (LinkedRecoveryActions | None): Shared journal and lease owner.
            picker_token (UUID): Opaque identity of the exact chooser instance.
        Returns:
            None: Queues one bounded observation or leaves the journal untouched.
        """
        if (target is None or recovery is None or self._gallery_picker is not dialog
                or self._current_linked_recovery_target(session, identity) is not target
                or not self._can_disconnect()):
            return
        size, accepted = QInputDialog.getInt(
            dialog, gettext('Check uncertain upload'),
            gettext(
                'Enter the original file size in bytes. No local file is opened. '
                'A match only reports observed bytes and does not confirm an upload.'
            ),
            value=1, minValue=1, maxValue=LINKING_RECOVERY_MAX_FILE_BYTES,
        )
        picker_current = (
            self._gallery_picker is dialog
            and self._current_linked_recovery_target(session, identity) is target
        )
        can_continue = picker_current and self._can_disconnect()
        if not accepted:
            return
        if (not can_continue or type(size) is not int
                or not 0 < size <= LINKING_RECOVERY_MAX_FILE_BYTES):
            return
        self._maintenance_busy = True
        self._action_success = ''
        self._action_failure = gettext(
            'Could not verify the uncertain upload. It remains unresolved.',
        )
        self._refresh_after_action = False
        self._refresh_photos_after_action = None
        self.work_changed.emit(True)
        self.status_message(gettext('Checking the uncertain upload once…'))

        def observe(
            cancelled: Event,
            publish: Callable[
                [str | CreatedGallery | UnlinkedSite | LinkedRecoveryObservation], None,
            ],
        ) -> None:
            """Use the shared no-file-open observer away from Qt's event thread.

            Args:
                cancelled (Event): Exact maintenance-lane cancellation fence.
                publish (Callable[[str | CreatedGallery | UnlinkedSite | LinkedRecoveryObservation], None]):
                    Queued result sink owned by the current Qt lane.
            Returns:
                None: Publishes only a validated owner outcome and marker identity.
            Raises:
                ReadCancelled: The view operation was withdrawn before completion.
                ValueError: The shared recovery owner returned an invalid result.
            """
            if cancelled.is_set():
                raise ReadCancelled
            outcome, marker = recovery.probe(target, size)
            if (outcome not in ('present', 'unknown')
                    or type(marker) is not str or not marker):
                raise ValueError('The recovery owner returned an invalid observation.')
            if cancelled.is_set():
                raise ReadCancelled
            publish(LinkedRecoveryObservation(
                picker_token, target, outcome, marker,
            ))

        self._maintenance.submit(observe)
        self._update_preload_priority()

    def _review_linked_recovery(
        self, dialog: QDialog, session: LinkingSession,
        identity: tuple[str, int], target: LinkedUploadTarget | None,
        recovery: LinkedRecoveryActions | None,
    ) -> None:
        """Record explicit review for only the exact retained marker.

        Args:
            dialog (QDialog): Current chooser that owns the user confirmation.
            session (LinkingSession): Exact live Photos approval at review time.
            identity (tuple[str, int]): Canonical issuer and verified Admin ID.
            target (LinkedUploadTarget | None): Exact target captured by the chooser.
            recovery (LinkedRecoveryActions | None): Shared journal and lease owner.
        Returns:
            None: A default-No prompt records only confirmed review of the same marker.
        """
        if (target is None or recovery is None or self._gallery_picker is not dialog
                or self._current_linked_recovery_target(session, identity) is not target
                or not self._can_disconnect()):
            return
        unavailable = tr(self.language, 'linking_review_unavailable')
        try:
            marker = recovery.review(target, None)
        except Exception:
            marker = None
        if marker is None:
            self.status_message(unavailable)
            return
        caption = tr(self.language, 'linking_review_title')
        prompt = tr(self.language, 'linking_review_prompt').format(gallery=target.title)
        answer = QMessageBox.question(
            dialog, caption, prompt,
            QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No,
            QMessageBox.StandardButton.No,
        )
        picker_current = (
            self._gallery_picker is dialog
            and self._current_linked_recovery_target(session, identity) is target
        )
        can_continue = picker_current and self._can_disconnect()
        if answer != QMessageBox.StandardButton.Yes:
            return
        if not can_continue:
            self.status_message(unavailable)
            return
        try:
            reviewed = recovery.review(target, marker) == marker
        except Exception:
            reviewed = False
        if (self._gallery_picker is not dialog
                or self._current_linked_recovery_target(session, identity) is not target
                or not reviewed):
            self.status_message(unavailable)
            return
        self.status_message(tr(self.language, 'linking_review_done'))

    def select_upload_gallery(self, gallery: int | None = None, *, confirm: bool = True) -> None:
        """Verify a specifically approved gallery before publishing its mode.

        Args:
            gallery (int | None): Exact row chosen in the scoped picker, or the
                active Photos gallery for its existing current-gallery command.
            confirm (bool): Ask for confirmation when no picker action approved it.
        Returns:
            None: Selects the real linked target without starting any transfer.
        """
        connections, session, identity = self.connections, self._session, self._identity
        gallery = self._gallery_id if gallery is None else gallery
        if (connections is None or session is None or gallery is None or identity is None
                or not session.can_upload() or not self._can_disconnect()):
            self.status_message('Gallery connection is unavailable.')
            return
        title = gettext('Select upload gallery')
        if confirm and QMessageBox.question(
            self, title, f'{title} #{gallery}?',
        ) != QMessageBox.StandardButton.Yes:
            return
        self.status_message('Verifying the administrator and selected gallery…')

        def select(cancel: Event) -> None:
            """Verify live account, scope and exact selected remote destination.

            Args:
                cancel (Event): Outstanding operation lifetime fence.
            Returns:
                None: Publishes the mode only after the shared verification barrier.
            """
            target = verify_linked_upload_target(session, *identity, session.approved_scope(), gallery, cancel)
            if not cancel.is_set():
                connections.select(target)

        self._run_gallery_action(select, gettext_noop('Watcher gallery selected.'))

    def create_gallery(self, *, parent_id: int | None = None, choose_parent: bool = True) -> None:
        """Create an explicitly named root or child through the durable journal.

        Args:
            parent_id (int | None): Exact contextual parent, or None for root.
            choose_parent (bool): Offer the generic Settings/connection action's
                root/current selection instead of the contextual target.
        Returns:
            None: Presents a verified result or retains its original intent for retry.
        """
        session, identity = self._session, self._identity
        if session is None or identity is None or not self._can_disconnect():
            self.status_message('Gallery connection is unavailable.')
            return
        if not session.can_create_gallery():
            self.approve_admin()
            return
        caption = gettext('Create gallery')
        if choose_parent:
            parent_id = self._gallery_id
        if choose_parent and parent_id is not None:
            choices = (gettext('Root gallery'), f'{gettext("Parent gallery")} #{parent_id}')
            choice, accepted = QInputDialog.getItem(self, caption, gettext('Parent gallery'), choices, editable=False)
            if not accepted:
                return
            if choice == choices[0]:
                parent_id = None
        retained = self._create_draft
        initial = retained[2] if retained is not None and retained[:2] == (session, parent_id) else ''
        title, accepted = QInputDialog.getText(self, caption, gettext('Gallery title:'), text=initial)
        if (not accepted or not title.strip() or session is not self._session
                or parent_id is not None and not self._owns_gallery(session, parent_id)):
            return
        title = title.strip()
        self._create_draft = session, parent_id, title

        def create(cancel: Event) -> CreatedGallery:
            """Run the exact-request owner and consume only a verified receipt.

            Args:
                cancel (Event): Lifecycle cancellation checked before dispatch.
            Returns:
                CreatedGallery: Verified receipt linked to the exact live session.
            """
            if cancel.is_set():
                raise ReadCancelled
            journal = GalleryCreateJournal(self.profile).for_request(*identity, title, parent_id)
            receipt = journal.create_or_resume(session, *identity, title, parent_id)
            if not cancel.is_set():
                try:
                    journal.acknowledge(*identity, receipt)
                except (OSError, RuntimeError, ValueError):
                    # The authoritative receipt is already recorded by the journal.
                    pass
            return CreatedGallery(session, receipt)

        self._run_gallery_action(
            create, gettext_noop('Gallery created.'), failure_caption=self._text('create_failed'),
        )

    def edit_gallery(
        self, gallery_id: int | None = None, *, field: str | None = None, trigger: QWidget | None = None,
    ) -> None:
        """Edit one explicitly selected gallery field with its shared revision checks.

        Args:
            gallery_id (int | None): Exact contextual gallery, or the current selection.
            field (str | None): Direct title action, or show the explicit field selector.
            trigger (QWidget | None): Visible trigger to use as the dialog anchor.
        Returns:
            None: Publishes only the requested field and refreshes the catalogue.
        """
        session = self._session
        gallery = self._gallery_id if gallery_id is None else gallery_id
        if session is None or gallery is None or not self._owns_gallery(session, gallery):
            self.status_message('Gallery connection is unavailable.')
            return
        if self.busy():
            self.status_message('Wait for the current operation to finish.')
            return
        if field == 'title' and not session.can_edit_gallery_metadata():
            self.approve_admin()
            return
        fields: dict[str, str] = {}
        if session.can_edit_gallery_metadata():
            fields.update({
                gettext('Title'): 'title', gettext('Description'): 'description',
                gettext('NSFW'): 'nsfw', gettext('Listed'): 'listed',
            })
        if session.can_change_gallery_visibility():
            fields[gettext('Visibility')] = 'visibility'
        if not fields:
            self.status_message('Gallery connection is unavailable.')
            return
        if field is None:
            caption, accepted = QInputDialog.getItem(
                self, gettext('Edit gallery'), f'{gettext("Gallery")} #{gallery}',
                list(fields), editable=False,
            )
            if not accepted or not self._owns_gallery(session, gallery):
                return
            field = fields[caption]
        else:
            caption = next((name for name, key in fields.items() if key == field), '')
            if not caption:
                return
        transfer_busy = (
            (self._gallery_rename_busy or self.transfer_busy)
            if field == 'title' else self.transfer_busy
        )
        if self.busy() or transfer_busy():
            self.status_message('Wait for the current operation to finish.')
            return
        if self._gallery_editor is not None:
            self._gallery_editor.reject()
        editor = QtGalleryEditor(
            self, session, gallery, field, caption, self.language,
            lambda: (self._owns_gallery(session, gallery) and not self._maintenance_busy
                     and not self._selecting_saved_target and not transfer_busy()),
        )
        self._gallery_editor = editor
        editor.writing_changed.connect(self.work_changed.emit)
        editor.changed.connect(lambda: self._gallery_edit_saved(gallery, field))

        def forget(_result: int) -> None:
            """Drop the dialog reference after its native lifecycle completes.

            Args:
                _result (int): Qt dialog result already handled by the editor.
            Returns:
                None: Only clears the editor that emitted the result.
            """
            if self._gallery_editor is editor:
                self._gallery_editor = None

        editor.finished.connect(forget)
        anchor = trigger or self._gallery_options
        editor.present(anchor.mapToGlobal(QPoint(0, anchor.height())))

    def _gallery_edit_saved(self, gallery: int, field: str) -> None:
        """Refresh authorized gallery metadata after a verified field change.

        Args:
            gallery (int): Exact gallery whose field was saved.
            field (str): Validated field owned by the native editor.
        Returns:
            None: Preserves layout for text changes and revalidates media after access changes.
        """
        revalidate = gallery if field in ('visibility', 'nsfw', 'listed') else None
        if revalidate is not None and revalidate == self._gallery_id:
            self._remember_viewport()
            self._install_catalogue(())
        self.status.setText(gettext('Gallery changes saved.'))
        self.refresh_gallery_tree(revalidate_gallery=revalidate)

    def _populate_gallery_menu(self) -> None:
        """Show the actions available for the live gallery and current grant.

        Returns:
            None: Rebuilds the native menu before either mouse or keyboard use.
        """
        self._gallery_menu.clear()
        if self._live_sites_menu is not None:
            self._live_sites_menu.deleteLater()
            self._live_sites_menu = None
        sites = self._live_sites()
        if sites:
            site_menu = QMenu(
                tr(self.language, 'linking_live_addresses'), self._gallery_menu,
            )
            self._live_sites_menu = site_menu
            self._gallery_menu.addMenu(site_menu)
            duplicate_issuers = {
                site.issuer for site in sites
                if sum(candidate.issuer == site.issuer for candidate in sites) > 1
            }
            for site in sites:
                label = (
                    f'{site.issuer} ({site.user_id})'
                    if site.issuer in duplicate_issuers else site.issuer
                )
                action = site_menu.addAction(label)
                action.setCheckable(True)
                action.setChecked(
                    site.session is self._session
                    and self._identity == (site.issuer, site.user_id),
                )
                action.triggered.connect(
                    lambda _checked=False, selected=site: self._select_live_site(selected),
                )
            self._gallery_menu.addAction(
                tr(self.language, 'linking_connect_website'), self.link,
            )
            if self._gallery_id is not None:
                self._gallery_menu.addSeparator()
        if self._gallery_id is not None:
            self._fill_gallery_menu(self._gallery_menu, self._gallery_id)

    def _fill_gallery_menu(self, menu: QMenu, gallery: int) -> None:
        """Offer the existing scoped actions for the exact current or child gallery.

        Args:
            menu (QMenu): Native header or contextual menu to populate.
            gallery (int): Exact authorized row that owns these commands.
        Returns:
            None: Commands are bound to the grant and target shown by the menu.
        """
        session = self._session
        if session is None or not self._owns_gallery(session, gallery):
            return
        self._active_gallery_menu = menu
        self._simbrief_menu_entry = None
        menu.addAction(self._text('gallery_info'), lambda: self._request_information(session, gallery))
        if self.start_contextual is not None and self.connections is not None and session.can_upload():
            menu.addAction(self._text('start_watcher'), lambda: self._start_contextual_watcher(session, gallery))
        if not session.can_read_admin_media() or not session.can_edit_gallery_metadata():
            menu.addAction(self._text('approve_admin'), self.approve_admin)
        if session.can_read_media():
            menu.addSeparator()
            if self._preload_worker is not None and self._preload_worker.is_alive():
                menu.addAction(self._text('preload_cancel'), self._stop_preload)
            else:
                menu.addAction(self._text('preload'), lambda: self._start_preload(session, gallery))
                menu.addAction(
                    self._text('preload_thumbnails'),
                    lambda: self._start_preload(session, gallery, thumbnails_only=True),
                )
                pinned = menu.addAction(self._text('keep_preloaded'))
                pinned.setCheckable(True)
                pinned.setChecked(session.gallery_pinned(gallery))
                pinned.setToolTip(self._text('keep_preloaded_hint'))
                pinned.triggered.connect(lambda keep: self._start_preload(session, gallery, pinned=keep))
        if session.can_create_gallery():
            menu.addAction(self._text('create_child'), lambda: self.create_gallery(
                parent_id=gallery, choose_parent=False,
            ) if self._owns_gallery(session, gallery) else None)
        else:
            menu.addAction(self._text('create_child'), self.approve_admin)
        if session.can_edit_gallery_metadata():
            for caption, field in (
                (self._text('edit_title'), 'title'), (self._text('edit_description'), 'description'),
                (gettext('NSFW'), 'nsfw'), (gettext('Listed'), 'listed'),
            ):
                menu.addAction(caption, lambda _checked=False, key=field: self.edit_gallery(gallery, field=key)
                               if self._owns_gallery(session, gallery) else None)
        if session.can_change_gallery_visibility():
            menu.addAction(self._text('visibility'), lambda: self.edit_gallery(gallery, field='visibility')
                           if self._owns_gallery(session, gallery) else None)
        menu.addAction(self._text('edit_gallery'), lambda: self._open_web_gallery_editor(session, gallery))
        if self.simbrief_action is not None and self.simbrief_available is not None:
            self._simbrief_menu_entry = menu.addAction(
                self._text('simbrief'), lambda: self._open_context_simbrief(session, gallery),
            )
            self.update_simbrief_context(self.simbrief_available())
            if not self._gallery_context_demand:
                self._gallery_context_demand = True
                self.gallery_context_changed.emit(True)

    def update_simbrief_context(self, available: bool) -> None:
        """Toggle the host SimBrief action as its cached simulator status changes.

        Args:
            available (bool): The shared GamePresence observer detects a simulator.
        Returns:
            None: Changes only an action in the existing open gallery menu.
        """
        if self._simbrief_menu_entry is not None:
            self._simbrief_menu_entry.setVisible(available)

    def _open_context_simbrief(self, session: LinkingSession, gallery: int) -> None:
        """Delegate the current menu action to the existing Flight prompt.

        Args:
            session (LinkingSession): Exact displayed gallery's current grant.
            gallery (int): Exact gallery supplying the explicit context action.
        Returns:
            None: Opens the operator prompt only with current simulator and gallery ownership.
        """
        action, available = self.simbrief_action, self.simbrief_available
        if (action is not None and available is not None and available()
                and self.isVisible() and self._owns_gallery(session, gallery)):
            action()

    def _end_gallery_context(self, menu: QMenu) -> None:
        """Release the current menu and retire visibility demand after activation.

        Args:
            menu (QMenu): Exact menu that is closing or releasing its actions.
        Returns:
            None: Allows an explicit native menu selection to complete that event first.
        """
        if menu is self._active_gallery_menu:
            self._active_gallery_menu = None
            self._simbrief_menu_entry = None
            QTimer.singleShot(0, self, self._clear_gallery_context_demand)

    def _clear_gallery_context_demand(self) -> None:
        """Suspend optional simulator polling once its gallery context is gone.

        Returns:
            None: A replacement menu in the same event loop keeps its visibility demand.
        """
        if self._active_gallery_menu is None and self._gallery_context_demand:
            self._gallery_context_demand = False
            self.gallery_context_changed.emit(False)

    def _open_web_gallery_editor(self, session: LinkingSession, gallery: int) -> None:
        """Open the reference browser editor using only the approved installation.

        Args:
            session (LinkingSession): Exact live site session from the menu.
            gallery (int): Exact current or contextual linked gallery.
        Returns:
            None: The browser owns its own authentication and receives no gallery credentials.
        """
        if self._identity is None or not self._owns_gallery(session, gallery):
            return
        url = gallery_editor_url(session, self._identity[0], gallery)
        if url is None:
            return
        try:
            opened = webbrowser.open(url)
        except Exception:
            opened = False
        if not opened:
            self.status_message(self._text('edit_gallery_failed'))

    def _owns_gallery(self, session: LinkingSession, gallery: int) -> bool:
        """Validate contextual actions against the exact live gallery catalogue.

        Args:
            session (LinkingSession): Grant that supplied the action's target.
            gallery (int): Exact linked gallery ID from the active view.
        Returns:
            bool: True while that grant and gallery are still displayed in the tree.
        """
        return (session is self._session and session.available()
                and (gallery == self._gallery_id or gallery in self._gallery_items))

    def _open_folder(self, gallery: int) -> None:
        """Navigate an authorized child through the existing tree selection handler.

        Args:
            gallery (int): Exact direct child selected on a folder card.
        Returns:
            None: Expands the parent and selects the child through normal navigation.
        """
        item = self._gallery_items.get(gallery)
        if item is not None and self._session is not None and self._session.available():
            parent = item.parent()
            if parent is not None:
                parent.setExpanded(True)
            self._gallery_tree.setCurrentItem(item)
            self._gallery_tree.scrollToItem(item)

    def _folder_context_menu(self, gallery: int, anchor: QPoint) -> None:
        """Anchor a real gallery menu on an authorized child-folder card.

        Args:
            gallery (int): Child gallery under the pointer.
            anchor (QPoint): Global logical pointer position.
        Returns:
            None: Presents an ephemeral menu without changing the current gallery.
        """
        if gallery not in self._gallery_items:
            return
        menu = QMenu(self._folder_shelf)
        menu.aboutToHide.connect(lambda: self._end_gallery_context(menu))
        menu.aboutToHide.connect(menu.deleteLater)
        self._fill_gallery_menu(menu, gallery)
        if menu.isEmpty():
            menu.deleteLater()
        else:
            menu.popup(anchor)

    def _tree_context_menu(self, pos: QPoint) -> None:
        """Expose gallery or root creation commands at the tree pointer.

        Args:
            pos (QPoint): Viewport-relative location of the context request.
        Returns:
            None: Opens only the corresponding native scoped menu.
        """
        item = self._gallery_tree.itemAt(pos)
        anchor = self._gallery_tree.viewport().mapToGlobal(pos)
        if item is not None:
            self._folder_context_menu(int(item.data(0, Qt.ItemDataRole.UserRole)), anchor)
        elif self._session is not None and self._session.can_create_gallery():
            menu = QMenu(self._gallery_tree)
            menu.aboutToHide.connect(menu.deleteLater)
            menu.addAction(self._text('create_root'), lambda: self.create_gallery(choose_parent=False))
            menu.popup(anchor)

    def _start_contextual_watcher(self, session: LinkingSession, gallery: int) -> None:
        """Verify one exact photo destination and start its scoped Watcher.

        Args:
            session (LinkingSession): Existing browser-approved Photos session.
            gallery (int): Exact physical gallery shown in the current menu.
        Returns:
            None: Starts work after explicit consent through the shared queue owner.
        """
        start, connections, identity = self.start_contextual, self.connections, self._identity
        if (start is None or connections is None or identity is None
                or not self._owns_gallery(session, gallery)
                or not session.can_upload() or not self._can_disconnect()):
            return
        caption = self._text('start_watcher')
        if QMessageBox.question(self, caption, f'{caption} #{gallery}?') != QMessageBox.StandardButton.Yes:
            return
        if not self._owns_gallery(session, gallery):
            return

        def launch(cancelled: Event) -> None:
            """Transfer the registered key only after exact target admission.

            Args:
                cancelled (Event): Current explicit gallery action lifetime.
            Returns:
                None: The capture owns the key or the unused key is retired.
            """
            target = verify_linked_upload_target(session, *identity, session.approved_scope(), gallery, cancelled)
            key = session.open_watch_key(gallery, cancelled=cancelled)
            try:
                contextual = replace(target, watch_key=key)
                if cancelled.is_set():
                    raise ReadCancelled
                connections.select(target)
                start(contextual)
                key = None
            finally:
                if key is not None:
                    session.close_watch_key(gallery, key)

        self._run_gallery_action(launch, gettext_noop('Watcher preparation started.'))

    def _start_preload(
        self, session: LinkingSession, gallery: int, *,
        pinned: bool | None = None, thumbnails_only: bool = False,
    ) -> None:
        """Start one Photos cache operation through the established worker.

        Args:
            session (LinkingSession): Current media grant for the selected gallery.
            gallery (int): Exact physical gallery selected when opening the menu.
            pinned (bool | None): Optional explicit retention change.
            thumbnails_only (bool): Limit reads and accounting to thumbnails.
        Returns:
            None: Displays the current operation or leaves its existing owner active.
        """
        if (not self._owns_gallery(session, gallery)
                or not session.can_read_media() or self.busy()
                or (self._preload_worker is not None and self._preload_worker.is_alive())):
            return
        self._preload_session = session
        self._preload_unpin = pinned is False
        worker = GalleryPreloadWorker(
            session, gallery, pinned=pinned, download=pinned is not False,
            thumbnails_only=thumbnails_only,
            yield_to_foreground=self._preload_foreground.is_set,
            foreground_reason=lambda: self._preload_wait_reason,
        )
        self._preload_worker = worker
        self._update_preload_priority()
        self._preload_stop.setEnabled(True)
        worker.start()
        self._preload_timer.start()
        self._poll_preload()

    def _stop_preload(self) -> None:
        """Cancel a live preload or dismiss its completed status.

        Returns:
            None: Respects the current worker's cancellation and finish order.
        """
        worker = self._preload_worker
        if worker is None or not worker.is_alive():
            self._preload_row.hide()
        else:
            worker.stop()
            self._preload_stop.setEnabled(False)

    def _retire_preload(self) -> None:
        """Withdraw cache work and status for the former media grant.

        Returns:
            None: The original worker cooperatively releases its transport.
        """
        if self._preload_worker is not None:
            self._preload_worker.stop()
        self._preload_session = None
        self._preload_row.hide()

    def set_upload_pipeline_waiting(self, waiting: bool) -> None:
        """Give the same live upload pipeline priority over gallery preloading.

        Args:
            waiting (bool): Current pending Watcher queue or recovery status.
        Returns:
            None: Updates the media transport's existing priority fence.
        """
        self._pipeline_waiting = waiting
        for site in self._live_sites():
            site.session.set_upload_pipeline_waiting(waiting)
        self._update_preload_priority()

    def _update_preload_priority(self) -> None:
        """Publish foreground demand without allowing worker-side widget reads.

        Returns:
            None: Only explicit foreground requests preempt the cache worker.
        """
        detail = self._details.busy()
        busy = (
            detail or self._load.busy() or self._information.busy()
            or self._inspector_read.busy() or self.busy()
        )
        self._preload_wait_reason = 'browsing' if detail else 'metadata' if busy else ''
        if self._pipeline_waiting or busy:
            self._preload_foreground.set()
        else:
            self._preload_foreground.clear()

    def _poll_preload(self) -> None:
        """Display the shared retained-cache snapshot for its authorized session.

        Returns:
            None: Stops observation when the operation has completely settled.
        """
        worker = self._preload_worker
        if worker is None:
            self._preload_timer.stop()
            return
        active = worker.is_alive()
        if not active:
            self._preload_timer.stop()
        if self._preload_session is not self._session or self._session is None:
            return
        self._update_preload_priority()
        status = worker.snapshot()
        if (not active and status.phase in ('complete', 'partial', 'cancelled', 'error')
                and status.gallery_id in self._gallery_items):
            if status.pinned in (True, False):
                self._gallery_pins[status.gallery_id] = status.pinned
            if status.cache_state != 'unknown' and not status.thumbnails_only:
                self._cache_states[status.gallery_id] = status.cache_state
            self._refresh_tree_marker(status.gallery_id)
        if not active and status.gallery_id != self._gallery_id:
            self._preload_row.hide()
            return
        phase = 'unpinned' if self._preload_unpin and status.phase == 'complete' else status.phase
        if status.phase == 'yielding' and status.wait_reason == 'metadata':
            phase = 'wait_metadata'
        parts = [self._text('preload_' + phase)]
        if status.retry_after_seconds is not None:
            parts.append(self._text('preload_retry_countdown').format(seconds=status.retry_after_seconds))
        if status.total is not None:
            if status.thumbnails_only and active:
                parts.append(self._text('preload_thumbnail_counts').format(
                    done=status.completed, total=status.total, hits=status.current_hits, stored=status.stored,
                ))
            else:
                parts.append(self._text('preload_counts').format(
                    done=status.completed, total=status.total, cached=status.cached, skipped=status.skipped,
                ))
        if status.reason:
            parts.append(self._text('preload_reason_' + status.reason))
        self._preload_label.setText(' · '.join(parts))
        self._preload_row.show()
        if not active:
            self._preload_stop.setEnabled(True)
        self._preload_stop.setText(self._text('preload_cancel' if active else 'preload_close_status'))

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

    def _open_photo_context(
        self, title: str, anchor: QPoint, actions: list[tuple[str, Callable[[], None]]],
        valid: Callable[[], bool], *, destructive_caption: str | None = None,
    ) -> None:
        """Mount the reference compact contextual card within Photos.

        Args:
            title (str): Localized heading for this target.
            anchor (QPoint): Trigger position in the visible Quick scene.
            actions (list[tuple[str, Callable[[], None]]]): Ordered visible captions and explicit commands.
            valid (Callable[[], bool]): Current exact target and grant check before any action.
            destructive_caption (str | None): Permanent action styled with the danger color.
                Confirmation initially focuses its safe action when supplied.
        Returns:
            None: Clamps an inline surface and gives it native keyboard focus.
        """
        self._dismiss_photo_context()
        card = RaisedSurface(self, 'photosPhotoContext')
        card.setAccessibleName(title)
        card.setFocusPolicy(Qt.FocusPolicy.StrongFocus)
        card.setStyleSheet(
            'QFrame#photosPhotoContext QPushButton {'
            'text-align: left; background: transparent; padding: 4px 5px; '
            'border: 1px solid transparent; border-radius: 3px; '
            '} QFrame#photosPhotoContext QPushButton:hover {'
            f'background: {PALETTE["surface_alt"]}; '
            '} QFrame#photosPhotoContext QPushButton:focus {'
            f'border-color: {PALETTE["focus"]}; '
            '} QFrame#photosPhotoContext QPushButton[destructive="true"] {'
            f'color: {PALETTE["danger"]}; }}'
        )
        layout = QVBoxLayout(card)
        inset = PHOTO_CONTEXT_PADDING
        layout.setContentsMargins(inset, inset, inset, inset)
        layout.setSizeConstraint(QLayout.SizeConstraint.SetFixedSize)
        layout.setSpacing(0)
        heading = QLabel(title)
        heading.setTextFormat(Qt.TextFormat.PlainText)
        if destructive_caption is not None:
            heading.setWordWrap(True)
            heading.setFixedWidth(min(PHOTOS_TREE_WIDTH, max(1, self.width() - inset * 2)))
        layout.addWidget(heading)

        def invoke(action: Callable[[], None]) -> None:
            """Dispatch the requested action only while its target still owns the card.

            Args:
                action (Callable[[], None]): Explicitly selected Photos command.
            Returns:
                None: Restores grid focus and refuses stale actions.
            """
            allowed = valid()
            self._dismiss_photo_context(restore_focus=True)
            if allowed:
                action()

        focus: QWidget = card
        for caption, action in actions:
            button = ActionButton(caption, lambda command=action: invoke(command))
            if caption == destructive_caption:
                button.setProperty('destructive', True)
            elif destructive_caption is not None and focus is card:
                focus = button
            layout.addWidget(button)
        self._photo_context = card
        self._photo_context_anchor = anchor
        card.ensurePolished()
        card.adjustSize()
        self._position_photo_context()
        card.show()
        card.raise_()
        focus.setFocus(Qt.FocusReason.PopupFocusReason)
        application = QApplication.instance()
        if isinstance(application, QApplication):
            application.installEventFilter(self)
            application.focusChanged.connect(self._check_photo_context_focus)

    def _position_photo_context(self) -> None:
        """Keep the contextual card within the visible Photos workspace.

        Returns:
            None: Clamps the scene's logical trigger before any native painting.
        """
        card = self._photo_context
        if card is not None:
            point = self.quick.mapTo(self, self._photo_context_anchor)
            card.move(
                max(0, min(point.x(), self.width() - card.width())),
                max(0, min(point.y(), self.height() - card.height())),
            )

    def _check_photo_context_focus(self, _old: QWidget | None, current: QWidget | None) -> None:
        """Close the contextual card when native focus leaves its actions.

        Args:
            _old (QWidget | None): Previous focused widget, if any.
            current (QWidget | None): New focused widget, if any.
        Returns:
            None: The operator's newly focused control retains its focus.
        """
        card = self._photo_context
        if card is not None and (current is None or (
            current is not card and not card.isAncestorOf(current)
        )):
            self._dismiss_photo_context()

    def _dismiss_photo_context(self, *, restore_focus: bool = False) -> None:
        """Retire the contextual surface and its temporary event connections.

        Args:
            restore_focus (bool): Return keyboard focus to Photos after explicit dismissal.
        Returns:
            None: Releases only the contextual widget, keeping selection and textures.
        """
        card = self._photo_context
        if card is None:
            return
        self._photo_context = None
        application = QApplication.instance()
        if isinstance(application, QApplication):
            application.removeEventFilter(self)
            application.focusChanged.disconnect(self._check_photo_context_focus)
        card.hide()
        card.deleteLater()
        if restore_focus:
            self.quick.setFocus(Qt.FocusReason.PopupFocusReason)

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

    def _confirm_photo_delete(
        self, session: LinkingSession, gallery: int, image_ids: tuple[int, ...], anchor: QPoint,
    ) -> None:
        """Confirm the exact permanent deletion, then send it at most once.

        Args:
            session (LinkingSession): Current scoped gallery connection.
            gallery (int): Exact physical owner of the selected photo IDs.
            image_ids (tuple[int, ...]): Selected direct photo IDs in visible order.
            anchor (QPoint): Original selected tile position in the Quick scene.
        Returns:
            None: Submits only after confirmation and refreshes either outcome.
        """
        def current() -> bool:
            """Recheck the authorized photo set before executing an inline action.

            Returns:
                bool: The same gallery still owns all IDs and permits deletion.
            """
            return (
                self.isVisible() and not self.detailOpen
                and gallery == self._rendered_gallery == self._gallery_id
                and self._owns_gallery(session, gallery) and session.can_delete_admin_media()
                and 0 < len(image_ids) <= NATIVE_MEDIA_DELETE_BATCH_MAX
                and all(image_id in self.model.index_by_id for image_id in image_ids)
                and self._can_disconnect()
            )

        if not current():
            return
        item = self._gallery_tree.currentItem()
        name = item.text(0) if item is not None else str(gallery)
        name = ' '.join(''.join(char if char.isprintable() else ' ' for char in name).split())

        def remove() -> None:
            """Dispatch one irreversible delete through the existing mutation owner.

            Returns:
                None: Refreshes the precise gallery once the response settles.
            """
            self._run_gallery_action(
                lambda cancelled: session.delete_media_photos(gallery, image_ids, cancelled),
                self._text('delete_complete'),
                refresh_photos=(gallery, self._text('delete_uncertain')),
            )

        caption = self._text('delete_permanently')
        self._open_photo_context(
            self._text('delete_confirm').format(count=len(image_ids), gallery=name), anchor,
            [(self._text('cancel'), lambda: None), (caption, remove)], current,
            destructive_caption=caption,
        )

    def _change_photo_visibility(
        self, session: LinkingSession, gallery: int, photo: LinkedPhoto, target: str,
    ) -> None:
        """Send one explicitly selected visibility change with its observed state.

        Args:
            session (LinkingSession): Active Admin Photos session.
            gallery (int): Exact physical owner of the displayed photo.
            photo (LinkedPhoto): Current descriptor with prior visibility.
            target (str): Explicit target visibility chosen from its action menu.
        Returns:
            None: Requests a passive refresh after the one-shot write settles.
        """
        index = self.model.index_by_id.get(photo.image_id)
        if (session is not self._session or gallery != self._rendered_gallery
                or index is None or self.model.rows[index].source is not photo
                or not session.can_change_photo_visibility()):
            return
        self._run_gallery_action(
            lambda cancel: session.change_photo_visibility(gallery, photo, target, cancel),
            self._text('saved'), refresh_photos=(gallery, self._text('visibility_uncertain')),
        )

    def _request_information(
        self, session: LinkingSession, gallery: int, photo_id: int | None = None,
    ) -> None:
        """Read metadata and, for the inspector, a bounded authorized thumbnail.

        Args:
            session (LinkingSession): Current exact media session.
            gallery (int): Physical gallery whose details are requested.
            photo_id (int | None): Exact selected direct photo, or None for gallery details.
        Returns:
            None: Displays current metadata and schedules any outstanding
                passive metadata or preview reads independently.
        """
        if not self._owns_gallery(session, gallery) or photo_id is not None and gallery != self._gallery_id:
            return
        in_inspector = photo_id is not None and not self.detailOpen
        row: PhotoRow | None = None
        photo: LinkedPhoto | None = None
        if in_inspector and photo_id is not None:
            index = self.model.index_by_id.get(photo_id)
            row = self.model.rows[index] if index is not None else None
            if row is None or not isinstance(row.source, LinkedPhoto):
                return
            photo = row.source
        self._dismiss_information(keep_pane=in_inspector)
        if row is not None and photo is not None:
            self._inspected_photo = photo
            self._set_inspector_summary(photo, self._text('working'))
            self._inspector_preview.present(None, self._text('working'))
            if self._inspector_panel.isHidden():
                self._inspector_panel.reveal()
                self._split.refresh()
                self.restore_inspector_width(self._inspector_width)
            self._inspector_read.submit(lambda cancelled, publish: publish(
                read_photo(row, session, gallery, cancelled, thumbnail=True,
                           thumbnail_size=INSPECTOR_PREVIEW_PIXELS),
            ))
            self._update_preload_priority()
            key = (gallery, photo.image_id, session.can_read_admin_media())
            cached = self._photo_info_cache.get(key)
            if cached is not None:
                stored, result = cached
                if (result.session is session and session.can_read_media()
                        and 0 <= time.monotonic() - stored < PHOTOS_INFO_CACHE_FRESH_SECONDS):
                    self._photo_info_cache.move_to_end(key)
                    self._show_information(result, remember=False)
                    return
                self._photo_info_cache.pop(key)

        def read(
            cancelled: Event,
            publish: Callable[[MediaInformation | MediaInformationFailure], None],
        ) -> None:
            """Read the authorized catalogue's exact media information.

            Args:
                cancelled (Event): Current menu or detail lifetime.
                publish (Callable[[MediaInformation | MediaInformationFailure], None]):
                    Queued receiver for current metadata or a sanitized failure.
            Returns:
                None: Publishes validated fields or a privacy-filtered diagnostic.
            """
            try:
                if photo_id is None:
                    fields = session.media_gallery_details(gallery, cancelled=cancelled)
                else:
                    fields = session.media_photo_info(gallery, photo_id, cancelled=cancelled)
            except Exception as error:
                if not cancelled.is_set():
                    kind = 'catalogue' if photo_id is None else 'photo_info'
                    summary = format_safe_photos_failure('media-fetch', kind, error)
                    publish(MediaInformationFailure(summary))
                raise
            publish(MediaInformation(session, gallery, photo_id, fields, session.can_read_admin_media()))

        if not in_inspector:
            self.status_message(self._text('working'))
        self._information.submit(read)
        self._update_preload_priority()

    def _show_inspector_preview(self, frame: PhotoFrame) -> None:
        """Publish a preview only while its exact selection and grant are current.

        Args:
            frame (PhotoFrame): Bounded raster delivered by the current preview lane.
        Returns:
            None: Updates the preview without modifying the grid's retained textures.
        """
        session, gallery = self._session, self._gallery_id
        if (self._inspected_photo is frame.row.source and session is not None
                and gallery is not None and self._owns_gallery(session, gallery)
                and session.can_read_media()):
            self._inspector_preview.present(frame.image)

    def _inspector_preview_finished(self, _failure: str) -> None:
        """Complete the inspector preview independently of its metadata.

        Args:
            _failure (str): Sanitized read outcome from the preview lane.
        Returns:
            None: Shows an unavailable status if no current raster was published.
        """
        if self._inspected_photo is not None and self._inspector_preview.image.isNull():
            self._inspector_preview.present(None, self._text('preview_unavailable'))
        self._update_preload_priority()

    def _information_for_detail(self) -> None:
        """Request metadata for the exact displayed Lightbox rendition.

        Returns:
            None: Skips local previews and any detail that has already closed.
        """
        index, session, gallery = self._detail_display_index, self._session, self._rendered_gallery
        if (index is not None and 0 <= index < len(self.model.rows)
                and session is not None and gallery is not None and self._owns_gallery(session, gallery)):
            photo = self.model.rows[index].source
            if isinstance(photo, LinkedPhoto):
                self._request_information(session, gallery, photo.image_id)

    def _show_information(
        self, result: MediaInformation | MediaInformationFailure, *, remember: bool = True,
    ) -> None:
        """Display current authorized metadata as selectable details.

        Args:
            result (MediaInformation | MediaInformationFailure): Current authorized
                metadata or the operation's privacy-filtered diagnostic.
            remember (bool): Retain a newly accepted inspector result. Reuse
                never extends its original validation interval.
        Returns:
            None: Shows current metadata or retains its failure in Activity.
        """
        if isinstance(result, MediaInformationFailure):
            logging.getLogger(__name__).warning('Photos failure %s', result.summary)
            self.diagnostic_reported.emit(result.summary)
            return
        if (not self._owns_gallery(result.session, result.gallery_id)
                or result.photo_id is not None and self._gallery_id != result.gallery_id):
            return
        fields = result.fields
        if not isinstance(fields, dict):
            self._information_finished('InvalidMetadata')
            return
        groups: list[InformationGroup]
        if result.photo_id is None:
            title = self._text('gallery_info')
            groups = [
                ('photos_workspace_group_inventory', [
                    ('photos_workspace_field_photo_count', fields.get('photo_count')),
                    ('photos_workspace_field_file_count', fields.get('file_count')),
                    ('photos_workspace_field_size', fields.get('size')),
                ]),
                ('photos_workspace_group_gallery', [
                    (f'photos_workspace_field_{key}', fields.get(key))
                    for key in ('title', 'description', 'child_count', 'created_at', 'updated_at')
                ]),
                ('photos_workspace_group_access', [
                    (f'photos_workspace_field_{key}', fields.get(key))
                    for key in ('visibility', 'nsfw', 'listed')
                ]),
                ('photos_workspace_group_thumbnail', [
                    ('photos_workspace_field_thumbnail_settings', thumbnail_quality_caption(fields)),
                ]),
            ]
        else:
            title = self._text('photo_info')
            if not result.session.can_read_media():
                return
            location_allowed = result.session.can_read_admin_media()
            groups = photo_information_groups(
                fields, include_location=result.include_location and location_allowed,
            )
        if result.photo_id is not None and not self.detailOpen:
            photo = self._inspected_photo
            if photo is not None and photo.image_id == result.photo_id:
                if remember and result.include_location == location_allowed:
                    key = (result.gallery_id, result.photo_id, result.include_location)
                    scoped_fields = {name: value for name, value in fields.items()
                                     if location_allowed or not name.startswith('gps_')}
                    self._photo_info_cache[key] = (
                        time.monotonic(), replace(result, fields=scoped_fields),
                    )
                    self._photo_info_cache.move_to_end(key)
                    if len(self._photo_info_cache) > PHOTOS_INFO_CACHE_ENTRY_LIMIT:
                        self._photo_info_cache.popitem(last=False)
                self._set_inspector_summary(
                    photo, self._text('photo_info_ready'),
                    size_in_details=fields.get('size') is not None,
                )
                self._set_inspector_metadata(groups)
            return
        dialog = QDialog(self)
        dialog.setWindowTitle(title)
        layout = QVBoxLayout(dialog)
        details = QPlainTextEdit(self._information_rows(groups))
        details.setReadOnly(True)
        details.setAccessibleName(title)
        layout.addWidget(details)
        layout.addWidget(ActionButton(self._text('close'), dialog.close))
        self._information_dialog = dialog
        dialog.open()

    def _set_inspector_summary(
        self, photo: LinkedPhoto, status: str, *, size_in_details: bool = False,
    ) -> None:
        """Show the immediate descriptor and the current metadata read status.

        Args:
            photo (LinkedPhoto): Exact original authorized descriptor.
            status (str): Localized working, ready or partial-data notice.
            size_in_details (bool): Whether the structured rows show the original size.
        Returns:
            None: Shows status and retains the descriptor size until details include it.
        """
        title = ' '.join(''.join(char if char.isprintable() else ' ' for char in photo.title).split())
        title = title or self._text('photo_info')
        self._inspector_heading.setAccessibleName(title)
        self._inspector_heading.setToolTip(f'<span>{escape(title)}</span>')
        self._layout_inspector_heading()
        if not size_in_details:
            size = (self._format_information('photos_workspace_field_size', photo.size)
                    if photo.size is not None else self._text('unknown'))
            status = f"{self._text('field_size')}: {size}\n{status}"
        self._inspector_summary.setText(status)

    def _layout_inspector_heading(self) -> None:
        """Fit the file title into the header while retaining its full name.

        Returns:
            None: Shortens only the visible caption using the current font and width.
        """
        label = self._inspector_heading
        label.setText(label.fontMetrics().elidedText(
            label.accessibleName(), Qt.TextElideMode.ElideMiddle,
            label.contentsRect().width(),
        ))

    def _set_inspector_metadata(self, groups: list[InformationGroup]) -> None:
        """Localize the authorized fields for the compact inspector document.

        Args:
            groups (list[InformationGroup]): Localized group keys and validated fields.
        Returns:
            None: Displays grouped metadata and expandable names for this photo.
        """
        self._inspector.set_groups([
            (tr(self.language, heading), [
                (key, tr(self.language, key), self._format_information(key, value))
                for key, value in rows if value is not None
            ])
            for heading, rows in groups
        ])

    def _information_rows(self, groups: list[InformationGroup]) -> str:
        """Format the same ordered scoped groups as the existing Tk inspector.

        Args:
            groups (list[InformationGroup]): Localized heading and validated nullable fields.
        Returns:
            str: Selectable plain text with only populated groups and fields.
        """
        content: list[str] = []
        for heading, rows in groups:
            values = [(key, value) for key, value in rows if value is not None]
            if values:
                content.append(tr(self.language, heading))
                content.extend(
                    f'{tr(self.language, key)}: {self._format_information(key, value)}'
                    for key, value in values
                )
                content.append('')
        return '\n'.join(content)

    def _format_information(self, key: str, value: str | int | float | bool) -> str:
        """Format bounded media metadata for localized plain text display.

        Args:
            key (str): Stable shared metadata field key.
            value (str | int | float | bool): Validated value in the active grant.
        Returns:
            str: Shared size caption, localized access state or printable text.
        """
        if isinstance(value, bool):
            return self._text('yes' if value else 'no')
        if key.endswith('_size') and isinstance(value, int):
            return format_gallery_bytes(value)
        if key.endswith('_gps_altitude') and isinstance(value, (int, float)):
            return f'{value:g} m'
        if key.endswith('_visibility'):
            visibility = 'unpublished' if value == 'draft' else str(value)
            return self._text('visibility_state_' + visibility)
        return ''.join(char if char.isprintable() else ' ' for char in str(value))

    def _information_finished(self, failure: str) -> None:
        """Keep the authorized detail or show a safe lookup failure.

        Args:
            failure (str): Empty after success or a sanitized exception category.
        Returns:
            None: Updates the existing Photos status.
        """
        photo = self._inspected_photo
        if photo is not None and not self._inspector_panel.isHidden():
            if failure:
                self._set_inspector_summary(
                    photo, self._text('details_fallback'),
                    size_in_details=photo.size is not None,
                )
                groups = photo_information_groups({
                    'title': photo.title, 'size': photo.size,
                    'visibility': photo.visibility, 'nsfw': photo.nsfw,
                }, include_location=False)
                self._set_inspector_metadata(groups)
            return
        self.status_message(self._text('info_unavailable') if failure else '')

    def _dismiss_information(self, *, keep_pane: bool = False) -> None:
        """Withdraw stale metadata and previews before navigation or shutdown.

        Args:
            keep_pane (bool): Whether a new request keeps the current split geometry.
        Returns:
            None: Cancels both reads and clears the pane or previous detail dialog.
        """
        self._information.cancel()
        self._inspector_read.cancel()
        self._inspected_photo = None
        if not keep_pane and not self._inspector_panel.isHidden():
            self._inspector_panel.hide()
            self.restore_tree_width(self._preferred_tree_width)
        self._inspector_summary.clear()
        self._inspector_preview.present(None)
        self._inspector.set_groups([])
        if self._information_dialog is not None:
            self._information_dialog.close()
            self._information_dialog.deleteLater()
            self._information_dialog = None

    def _close_inspector(self) -> None:
        """Dismiss metadata and return keyboard focus to the selected photo.

        Returns:
            None: Leaves the current selection, viewport and textures in place.
        """
        self._dismiss_information()
        self.quick.setFocus(Qt.FocusReason.OtherFocusReason)

    def _run_gallery_action(
        self, command: Callable[[Event], GalleryActionResult], success: str, *,
        refresh_tree: bool = False, refresh_photos: tuple[int, str] | None = None,
        failure_caption: str = '',
    ) -> None:
        """Start one exclusive explicit action and preserve the active grant until it ends.

        Args:
            command (Callable[[Event], GalleryActionResult]): Current verified
                operation. Only a creation receipt changes the tree directly.
            success (str): Localized-ready caption for a confirmed result.
            refresh_tree (bool): Refresh the gallery hierarchy after confirmed mutation.
            refresh_photos (tuple[int, str] | None): Exact gallery ID and
                uncertainty caption for a one-shot photo mutation.
            failure_caption (str): Scoped localized failure, or the default connection message.
        Returns:
            None: Queues a result or presents an error without replaying a request.
        """
        if not self._can_disconnect():
            return
        self._maintenance_busy = True
        self._action_success = success
        self._action_failure = failure_caption
        self._refresh_after_action = refresh_tree
        self._refresh_photos_after_action = refresh_photos
        self.work_changed.emit(True)

        def run(
            cancelled: Event, publish: Callable[[str | CreatedGallery | UnlinkedSite], None],
        ) -> None:
            """Forward only a confirmed result from the shared owner.

            Args:
                cancelled (Event): Exact action lifetime.
                publish (Callable[[str | CreatedGallery | UnlinkedSite], None]): Scoped result sink.
            Returns:
                None: Emits a caption on completion.
            """
            confirmed = command(cancelled)
            publish(confirmed if isinstance(confirmed, (CreatedGallery, UnlinkedSite)) else success)

        self._maintenance.submit(run)
        self._update_preload_priority()

    def _receive_maintenance(
        self, result: str | CreatedGallery | UnlinkedSite | LinkedRecoveryObservation,
    ) -> None:
        """Present confirmed work and admit a newly verified tree member in place.

        Args:
            result (str | CreatedGallery | UnlinkedSite | LinkedRecoveryObservation):
                Safe status, authorized creation receipt, recovery observation
                or confirmed outcome for the exact disconnected site.
        Returns:
            None: Keeps the original selection except when the new root is the first one.
        """
        if isinstance(result, str):
            self.status_message(result)
            return
        if isinstance(result, LinkedRecoveryObservation):
            if self._gallery_picker_token != result.picker_token:
                return
            connections = self.connections
            recovery = connections.recovery if connections is not None else None
            current = bool(
                recovery is not None
                and self._current_linked_recovery_target(
                    result.target.session,
                    (result.target.issuer, result.target.user_id),
                ) is result.target
            )
            if current and recovery is not None:
                try:
                    current = recovery.is_current(result.target, result.marker)
                except Exception:
                    current = False
            if not current:
                self.status_message(gettext('The upload observation is no longer current.'))
                return
            self.status_message(tr(
                self.language,
                'linking_recovery_observed'
                if result.outcome == 'present'
                else 'linking_recovery_unknown',
            ))
            return
        if isinstance(result, UnlinkedSite):
            if self._session is result.site.session:
                self._detach_current_site()
            if result.revoked:
                self.status_message(gettext_noop('Gallery unlinked.'))
            else:
                self.status_message(gettext_noop(
                    'Unlinked locally. Check Manage link to confirm server revocation.',
                ))
            self.link_action_reported.emit(self.status.text())
            self._sync_connection_state()
            return
        if self._session is not result.session or not result.session.available():
            return
        gallery = result.receipt.gallery
        self._create_draft = None
        item = self._gallery_items.get(gallery.gallery_id)
        parent = self._gallery_items.get(gallery.parent_id) if gallery.parent_id is not None else None
        if item is None:
            item = QTreeWidgetItem([gallery.title])
            item.setData(0, Qt.ItemDataRole.UserRole, str(gallery.gallery_id))
            if parent is None:
                self._gallery_tree.addTopLevelItem(item)
            else:
                parent.addChild(item)
            self._gallery_items[gallery.gallery_id] = item
            self._refresh_tree_marker(gallery.gallery_id)
        else:
            item.setText(0, gallery.title)
        if parent is not None and gallery.parent_id is not None:
            parent.setExpanded(True)
            existing = self._gallery_children.get(gallery.parent_id, ())
            siblings = tuple(row for row in existing if row.gallery_id != gallery.gallery_id) + (gallery,)
            self._gallery_children[gallery.parent_id] = tuple(sorted(siblings, key=lambda row: row.gallery_id))
        self._gallery_tree.show()
        self._sync_connection_state()
        if self._gallery_id is None and gallery.parent_id is None:
            self._gallery_tree.setCurrentItem(item)
        self.status_message(self._text('created').format(title=gallery.title))

    def _maintenance_finished(self, failure: str) -> None:
        """Reopen local commands after the durable operation has settled.

        Args:
            failure (str): Safe exception category or empty after verified success.
        Returns:
            None: Refreshes current gallery rows when the mutation was confirmed.
        """
        self._maintenance_busy = False
        unlinking, self._unlink_pending = self._unlink_pending, False
        self.work_changed.emit(False)
        photo_refresh = self._refresh_photos_after_action
        self._refresh_photos_after_action = None
        if photo_refresh is not None:
            gallery, uncertainty = photo_refresh
            notice = uncertainty if failure else self._action_success
            if gallery == self._gallery_id:
                self._catalogue_notice = gallery, notice
                self.refresh()
            else:
                self.status_message(notice)
            return
        if failure:
            self.status_message(self._action_failure or 'Gallery connection is unavailable.')
            if unlinking:
                self.link_action_reported.emit(self.status.text())
            return
        session, identity = self._session, self._identity
        if self._refresh_after_action and session is not None and identity is not None and self._tree_path is not None:
            path = self._tree_path
            issuer, account = identity
            self._load.submit(lambda cancel, publish: publish(LinkedPhotos(
                session, issuer, account, read_gallery_tree(session, cancel),
                load_photos_tree_preferences(path), owns_session=False,
            )))

    def disconnect(self) -> None:
        """Retire current authorization and clear its model and scene references.

        Returns:
            None: Cancels all reads without resuming or changing stored grants.
        """
        if not self._can_disconnect():
            return
        self._detach_current_site()

    def _detach_current_site(self) -> None:
        """Clear the current authorized view after local release or explicit unlink.

        Returns:
            None: Withdraws only the matching catalogue, media and in-memory session.
        """
        if self._gallery_editor is not None:
            self._gallery_editor.reject()
        self._pending_live_site = None
        self._startup_browse = False
        self._create_draft = None
        self._authorization_active = False
        self._link_cancel_button.hide()
        self._load.cancel()
        self._dismiss_information()
        self._photo_info_cache.clear()
        self._retire_preload()
        self.playback.bind(None, None)
        if self._attempt is not None:
            self._attempt.close()
            self._attempt = None
        if self._session is not None:
            self._session.close()
        had_connection = self._identity is not None
        self._session = None
        self._gallery_id = None
        self._identity = None
        self._cache_states.clear()
        self._gallery_pins.clear()
        self._gallery_title.clear()
        if had_connection:
            self.connection_changed.emit()
        self._rendered_gallery = None
        self._tree_path = None
        self._opened_galleries.clear()
        self._gallery_items.clear()
        self._gallery_children.clear()
        self._sync_capture_markers()
        self._folder_split_loaded = None
        self._folder_modes_unsaved.clear()
        self._gallery_has_photos = None
        self._viewports.clear()
        self._view_path = None
        self._catalogue_notice = None
        self._gallery_tree.blockSignals(True)
        self._gallery_tree.clear()
        self._gallery_tree.blockSignals(False)
        self._local_photos_active = False
        self._format = 'default'
        self._format_unsaved.clear()
        self._sync_connection_state()
        self._gallery_tree.hide()
        self._gallery_panel.hide()
        self._gallery_options.setIcon(self._gallery_state_icon(None, show_capture=False))
        self._gallery_options.setVisible(bool(self._parked_links))
        self.link_button.setEnabled(True)
        self._install_catalogue(())
        self.status.setText(gettext("Link a gallery or open local photos."))
        self._sync_connection_state()
        self.formatChanged.emit()

    def _receive_catalogue(self, result: PhotosLoadResult) -> None:
        """Apply one current worker-owned tree or photo catalogue.

        Args:
            result (PhotosLoadResult): Current authorization, catalogue or progress result.
        Returns:
            None: Mounts current authorized rows and retains the live viewer
                when only its tree labels or parent paths changed.
        """
        if isinstance(result, PhotosUploadUpgrade):
            self._finish_admin_upload_upgrade(result)
            return
        if isinstance(result, LinkingProgress):
            if self._authorization_active or self._startup_browse:
                if result.failed:
                    self._authorization_failure = result.caption
                self.status.setText(result.caption)
                self._sync_authorization_dialog()
                self._sync_connection_state()
            return
        switching_site = False
        if isinstance(result, LiveSiteCatalogue):
            site = result.site
            pending = self._pending_live_site
            if (pending is None or pending.session is not site.session
                    or pending.issuer != site.issuer or pending.user_id != site.user_id
                    or not self._contains_live_site(site)):
                return
            self._pending_live_site = None
            switching_site = True
            result = LinkedPhotos(
                site.session, site.issuer, site.user_id,
                result.galleries, result.tree_state, owns_session=False,
            )
        if isinstance(result, PhotoCatalogue):
            if self._session is None or not self._session.available():
                return
            self._install_catalogue(result.rows)
            self._queue_gallery_cache_probe(result.rows)
            self._gallery_has_photos = bool(result.rows)
            self._sync_folder_section()
            notice = self._catalogue_notice
            self._catalogue_notice = None
            if notice is not None and notice[0] == self._gallery_id:
                self.status_message(notice[1])
            return
        same_tree_owner = (
            not result.owns_session and result.session is self._session
            and self._identity == (result.issuer, result.user_id) and result.session.available()
        )
        same_writer_owner = False
        if (not result.owns_session and not same_tree_owner
                and not switching_site and self.connections is not None):
            try:
                writer_target = self.connections.active()
            except RuntimeError:
                writer_target = None
            same_writer_owner = bool(
                writer_target is not None and writer_target.session is result.session
                and writer_target.issuer == result.issuer
                and writer_target.user_id == result.user_id
            )
        current_gallery = self._gallery_id if same_tree_owner else None
        if (not result.owns_session and not same_tree_owner and not switching_site
                and not same_writer_owner):
            return
        if self._startup_browse:
            self._startup_browse = False
            self.status.clear()
        if self._authorization_active:
            self._authorization_active = False
            self._attempt = None
            self._link_cancel_button.hide()
            self.status.clear()
        if self._selecting_saved_target:
            self._selecting_saved_target = False
            self.work_changed.emit(False)
        if isinstance(result, LinkedPhotos):
            self._close_authorization_dialog()
        replacing_account = not same_tree_owner and (
            self._identity == (result.issuer, result.user_id)
            or any(
                site.session is not result.session
                and (site.issuer, site.user_id) == (result.issuer, result.user_id)
                for site in self._parked_links
            )
        )
        writer_session = None
        if replacing_account and self.connections is not None:
            try:
                writer = self.connections.active()
                if writer is not None:
                    writer_session = writer.session
            except RuntimeError:
                # An in-flight transfer still owns its grant even when mode
                # inspection cannot establish a new upload target.
                pass
        if self._session is not None and self._session is not result.session:
            same_account = self._identity == (result.issuer, result.user_id)
            if (not self._can_switch_photos_site()
                    or same_account and not self._can_disconnect()):
                if result.owns_session:
                    result.session.close()
                self.link_button.setEnabled(True)
                self.progress.hide()
                return
            current = DesktopLinkedSite(
                self._identity[0], self._session, self._identity[1],
            ) if self._identity is not None else None
            if current is not None and same_account and current.session is not writer_session:
                current.session.close()
            elif current is not None and self._contains_live_site(current):
                self._park_current_link()
            else:
                self._session.close()
            self._gallery_id = None
            self._retire_preload()
            self._install_catalogue(())
            self._viewports.clear()
            self._dismiss_information()
            self._photo_info_cache.clear()
        elif self._local_photos_active:
            self._install_catalogue(())
            self._viewports.clear()
            self._dismiss_information()
        retained_sites: list[DesktopLinkedSite] = []
        for site in self._parked_links:
            if site.session is result.session:
                continue
            if (site.issuer == result.issuer and site.user_id == result.user_id
                    and site.session is not writer_session
                    and not self.transfer_busy()):
                site.session.close()
                continue
            retained_sites.append(site)
        self._parked_links = tuple(retained_sites)
        next_identity = (result.issuer, result.user_id)
        if self._identity != next_identity:
            self._cache_states.clear()
            self._gallery_pins.clear()
        self._session = result.session
        self._session.set_upload_pipeline_waiting(self._pipeline_waiting)
        self._identity = result.issuer, result.user_id
        if not same_tree_owner:
            self.connection_changed.emit()
        tree_path = photos_account_tree_path(self.profile, result.issuer, result.user_id)
        self._tree_path = tree_path
        if not same_tree_owner:
            self._opened_galleries = dict(result.tree_state.opened)
        self._view_path = tree_path.with_name(tree_path.stem + ".view.json")
        tree = self._gallery_tree
        tree.blockSignals(True)
        tree.clear()
        items = {row.gallery_id: QTreeWidgetItem([row.title]) for row in result.galleries}
        children: dict[int, list[LinkedGallery]] = {}
        # Nested Qt items are rooted only when every ancestor is ordered above
        # its child. A missing or malformed ancestry remains visible at root.
        visited: set[int] = set()
        for row in result.galleries:
            item = items[row.gallery_id]
            item.setData(0, Qt.ItemDataRole.UserRole, str(row.gallery_id))
            if row.parent_id is not None and row.parent_id in visited:
                items[row.parent_id].addChild(item)
                children.setdefault(row.parent_id, []).append(row)
            else:
                tree.addTopLevelItem(item)
            visited.add(row.gallery_id)
        for gallery_id, item in items.items():
            item.setExpanded(self._opened_galleries.get(gallery_id, False))
        current_item = items.get(current_gallery)
        preferred = current_item or items.get(result.tree_state.selected)
        item = preferred or tree.topLevelItem(0)
        if item is not None:
            ancestor = item.parent()
            while ancestor is not None:
                ancestor.setExpanded(True)
                ancestor = ancestor.parent()
            tree.setCurrentItem(item)
        tree.blockSignals(False)
        self._gallery_items = items
        self._gallery_children = {
            parent: tuple(sorted(rows, key=lambda row: row.gallery_id))
            for parent, rows in children.items()
        }
        for gallery_id in items:
            self._refresh_tree_marker(gallery_id)
        tree.setVisible(bool(items))
        self._gallery_panel.setVisible(bool(items) and not self.detailOpen)
        self._local_photos_active = False
        self._sync_connection_state()
        if not same_tree_owner:
            self.restore_tree_width(self._preferred_tree_width)
        self.link_button.setEnabled(True)
        if current_item is not None:
            self._gallery_title.setText(current_item.text(0))
            self._sync_folder_section()
            if result.revalidate_gallery == current_gallery:
                self.refresh()
        elif item is not None:
            self._select_gallery(int(item.data(0, Qt.ItemDataRole.UserRole)))
        else:
            self._gallery_id = None
            self._gallery_title.clear()
            self.playback.bind(None, None)
            self._gallery_options.setIcon(self._gallery_state_icon(None, show_capture=False))
            self._gallery_options.setVisible(self._identity is not None)
            self._install_catalogue(())
            self.status.setText(gettext("No galleries found."))
        self._sync_capture_markers()
        if result.owns_session:
            self._remember_recent_address(result.issuer)

    def restore_tree_width(self, width: int) -> None:
        """Restore the shared Photos tree geometry when a gallery is linked.

        Args:
            width (int): Saved width of the hierarchy in logical pixels.
        Returns:
            None: Applies the split or retains it while the tree is hidden.
        """
        self._preferred_tree_width = width
        if not self._gallery_panel.isHidden():
            self._apply_pane_widths(width)

    def tree_width(self) -> int:
        """Return the current hierarchy width for shared layout persistence.

        Returns:
            int: Current logical width, zero if the hierarchy is hidden.
        """
        return self._split.sizes()[0]

    def restore_inspector_width(self, width: int) -> None:
        """Restore the common Photos inspector width when metadata opens.

        Args:
            width (int): Saved right pane width in logical pixels.
        Returns:
            None: Retains the preferred width or applies it within available space.
        """
        minimum, maximum = UI_LAYOUT_INFO_WIDTH_BOUNDS
        self._inspector_width = min(maximum, max(minimum, width))
        if not self._inspector_panel.isHidden():
            self._apply_pane_widths(self.tree_width())

    def _apply_pane_widths(self, tree: int) -> None:
        """Reserve space for the gallery before sizing the right inspector.

        Args:
            tree (int): Requested visible tree width in logical pixels, zero when hidden.
        Returns:
            None: Sets native handle limits and restores the preferred split where it fits.
        """
        inspector_visible = not self._inspector_panel.isHidden()
        # One handle divides each adjacent pair of visible panes.
        handles = int(not self._gallery_panel.isHidden()) + int(inspector_visible)
        pane_width = (
            self._split.contentsRect().width() - handles * self._split.handleWidth()
        )
        remaining = pane_width - tree
        inspector = 0
        if inspector_visible:
            minimum, maximum = UI_LAYOUT_INFO_WIDTH_BOUNDS
            natural_minimum = self._inspector_panel.minimumSizeHint().width()
            maximum = min(maximum, max(
                natural_minimum, remaining - UI_LAYOUT_VIEWER_MINIMUM_WIDTH,
            ))
            self._inspector_panel.setMinimumWidth(min(minimum, maximum))
            self._inspector_panel.setMaximumWidth(maximum)
            inspector = min(self._inspector_width, maximum)
        sizes = [tree, max(0, remaining - inspector), inspector]
        if self._split.sizes() != sizes:
            self._split.setSizes(sizes)

    def inspector_width(self) -> int:
        """Read the visible inspector width without overwriting hidden preferences.

        Returns:
            int: Logical right pane width, or zero while the pane is hidden.
        """
        return self._split.sizes()[2]

    @property
    def preferred_inspector_width(self) -> int:
        """Return the user-controlled width independently of temporary geometry.

        Returns:
            int: Logical inspector width to retain in the shared profile.
        """
        return self._inspector_width

    def _remember_pane_widths(self, _position: int, index: int) -> None:
        """Publish user splitter movement through the common layout owner.

        Args:
            _position (int): Native leading edge of the moved handle.
            index (int): Index of the pane immediately following that handle.
        Returns:
            None: Retains the moved pane preference for subsequent openings.
        """
        following = self._split.widget(index)
        if following is self._center_panel:
            self.restore_tree_width(self.tree_width())
        elif following is self._inspector_panel:
            minimum, maximum = UI_LAYOUT_INFO_WIDTH_BOUNDS
            self._inspector_width = min(maximum, max(minimum, self.inspector_width()))
        self.splitter_moved.emit()

    def _finish_admin_upload_upgrade(self, upgrade: PhotosUploadUpgrade) -> None:
        """Commit a same-target Admin renewal on the Qt owner thread.

        Args:
            upgrade (PhotosUploadUpgrade): Verified grant and captured owner snapshots.
        Returns:
            None: Mounts the committed Photos grant or retains the previous view.
        """
        connections = self.connections
        target, session = upgrade.target, upgrade.session
        if (connections is None or connections.commit_scope_upgrade is None
                or self._session is not upgrade.previous_session
                or self._identity != (upgrade.binding.issuer, upgrade.binding.account_id)
                or self.busy() or self.transfer_busy()
                or not session.available() or not session.can_upload()
                or not session.can_read_admin_media()
                or not session.matches_issuer(upgrade.binding.issuer)
                or session.approved_scope() != upgrade.binding.scope
                or (target.issuer, target.user_id, target.gallery_id)
                != (upgrade.binding.issuer, upgrade.binding.account_id,
                    upgrade.previous_target.gallery_id)
                or target.session is not session):
            session.close()
            self.status_message('The selected gallery changed during approval.')
            return
        try:
            old_binding = OfflineBinding(
                upgrade.binding.issuer, upgrade.binding.account_id,
                upgrade.previous_scope,
            )
            if (connections.saved() != upgrade.previous_mode
                    or connections.active() is not upgrade.previous_target
                    or saved_desktop_gallery_binding(
                        self.profile / 'offline-link.json', upgrade.binding.issuer,
                    ) != old_binding):
                raise LinkingError('Saved gallery credential changed.')
            hint = saved_offline_hint_path(
                self.profile / 'offline-link.json', upgrade.binding.issuer,
                upgrade.binding.scope,
            )
            remaining = int(upgrade.refresh_deadline - time.monotonic())
            if remaining <= 0:
                raise LinkingError('The approved Photos connection expired.')
            enable_rolling_renewal(session, upgrade.store, hint, upgrade.binding)
            persist_browser_scope_upgrade(
                self.profile / 'offline-link.json', upgrade.store, upgrade.binding,
                upgrade.previous_scope, upgrade.refresh, remaining,
                commit=lambda: connections.commit_scope_upgrade(
                    upgrade.previous_mode, upgrade.previous_target, target,
                ),
            )
        except Exception:
            session.close()
            self.status_message('The approved Photos connection could not be saved safely.')
            return
        old_target_session = upgrade.previous_target.session
        old_target_session.disable_renewal()
        if upgrade.previous_session is not old_target_session:
            upgrade.previous_session.disable_renewal()
        self._receive_catalogue(LinkedPhotos(
            session, upgrade.binding.issuer, upgrade.binding.account_id,
            upgrade.galleries, upgrade.tree_state, owns_session=False,
        ))

    def _catalogue_finished(self, failure: str) -> None:
        """Expose a failed catalogue read without copying transport details.

        Args:
            failure (str): Sanitized exception class, or empty on success.
        Returns:
            None: Restores retry and connection affordances on failure.
        """
        restoring, self._startup_browse = self._startup_browse, False
        authorization_failure, self._authorization_failure = self._authorization_failure, ''
        if self._authorization_active:
            self._authorization_active = False
            self._attempt = None
            self._link_cancel_button.hide()
        if self._selecting_saved_target:
            self._selecting_saved_target = False
            self.work_changed.emit(False)
        if failure:
            self._pending_live_site = None
            self.progress.hide()
            self.link_button.setEnabled(True)
            if self._catalogue_notice is not None and self._catalogue_notice[0] == self._gallery_id:
                self.status_message(self._catalogue_notice[1])
                self._catalogue_notice = None
                return
            self._catalogue_notice = None
            if restoring:
                self.status.setText(gettext('Could not verify the saved link. Try again.'))
            else:
                self.status.setText(
                    authorization_failure or gettext("Gallery connection is unavailable."),
                )
            self._sync_authorization_dialog()
        elif restoring:
            self.status.clear()
        elif self._session is not None and self._gallery_id is None:
            self.progress.hide()
        self._sync_connection_state()

    def _choose_gallery(self, item: QTreeWidgetItem | None, _prior: QTreeWidgetItem | None) -> None:
        """Switch to one physical gallery from the verified tree.

        Args:
            item (QTreeWidgetItem | None): Newly selected linked gallery row.
            _prior (QTreeWidgetItem | None): Earlier navigation selection.
        Returns:
            None: Invalidates old reads and requests fresh selected metadata.
        """
        if item is None or self._session is None or not self._session.available():
            return
        gallery_id = int(item.data(0, Qt.ItemDataRole.UserRole))
        if self._tree_path is not None:
            save_photos_tree_preferences(self._tree_path, selected=gallery_id)
        self._select_gallery(gallery_id)

    def _expand_galleries(self) -> None:
        """Expand the complete currently verified gallery hierarchy.

        Returns:
            None: Existing per-account expansion callbacks persist row changes.
        """
        self._gallery_tree.expandAll()

    def _gallery_tree_collapse_all(self) -> None:
        """Collapse the complete currently verified gallery hierarchy.

        Returns:
            None: Existing per-account expansion callbacks persist row changes.
        """
        self._gallery_tree.collapseAll()

    def refresh_gallery_tree(self, *, revalidate_gallery: int | None = None) -> None:
        """Reload the current authorized tree without replacing its grant.

        Args:
            revalidate_gallery (int | None): Exact gallery whose access field changed,
                or None when the request only updates the visible tree.
        Returns:
            None: Publishes only the live session's validated gallery rows.
        """
        session, identity, path = self._session, self._identity, self._tree_path
        if (session is None or identity is None or path is None
                or not session.available()):
            self.status_message("Gallery connection is unavailable.")
            return
        if self._load.busy() and revalidate_gallery is None:
            self.status_message("A gallery request is already in progress.")
            return
        issuer, account = identity
        self._load.submit(lambda cancel, publish: publish(LinkedPhotos(
            session, issuer, account, read_gallery_tree(session, cancel),
            load_photos_tree_preferences(path), owns_session=False,
            revalidate_gallery=revalidate_gallery,
        )))

    def _select_gallery(self, gallery_id: int) -> None:
        """Mount a fresh authorized gallery and its existing local choices.

        Args:
            gallery_id (int): Physical gallery ID in the current confirmed tree.
        Returns:
            None: Reloads the verified catalogue with the saved display format.
        """
        if gallery_id != self._gallery_id:
            self._remember_viewport()
            self._catalogue_notice = None
            self._dismiss_information()
            self._gallery_has_photos = None
        self._gallery_id = gallery_id
        item = self._gallery_tree.currentItem()
        self._gallery_title.setText(
            item.text(0) if item is not None
            and int(item.data(0, Qt.ItemDataRole.UserRole)) == gallery_id else "",
        )
        self._gallery_options.show()
        self._refresh_tree_marker(gallery_id)
        if self._view_path is not None:
            self._newest = load_gallery_photo_order(self._view_path, gallery_id) == "newest"
            self._format = load_gallery_card_format(self._view_path, gallery_id)
            self._sync_format_actions()
            self._sync_order_action()
            self.formatChanged.emit()
            folder_key = self._view_path, gallery_id
            if folder_key != self._folder_split_loaded:
                self._folder_split_loaded = folder_key
                self._folder_format = load_gallery_card_format(self._view_path, gallery_id, folders=True)
                self._folder_newest = load_gallery_photo_order(self._view_path, gallery_id, folders=True) == 'newest'
                self._folder_split_share = load_gallery_folder_split(self._view_path, gallery_id)
                self._folder_modes_unsaved.clear()
                self._folder_split.handle(1).setToolTip(self._text('folder_split_hint'))
        self._sync_folder_section()
        self._sync_capture_markers()
        self.refresh()

    def _sync_order_action(self) -> None:
        """Present chronological order as the compact direction action.

        Returns:
            None: Updates the direction glyph and its accessible description.
        """
        caption = tr(self.language, "order_newest" if self._newest else "order_oldest")
        self._order_button.setText("↓" if self._newest else "↑")
        self._order_button.setAccessibleName(caption)
        self._order_button.setToolTip(caption)

    def _set_gallery_expanded(self, item: QTreeWidgetItem, expanded: bool) -> None:
        """Save explicit expansion through the same per-account tree owner.

        Args:
            item (QTreeWidgetItem): Current authorized parent gallery row.
            expanded (bool): User's explicit expansion choice.
        Returns:
            None: Updates local layout preferences for the exact live account.
        """
        if self._tree_path is None or self._session is None or not self._session.available():
            return
        gallery_id = int(item.data(0, Qt.ItemDataRole.UserRole))
        self._opened_galleries[gallery_id] = expanded
        save_photos_tree_preferences(self._tree_path, opened=self._opened_galleries)
        self._sync_capture_markers()

    def refresh(self) -> None:
        """Replace stale photo metadata while keeping the same gallery binding.

        Returns:
            None: Cancels old pixels before reading the selected catalogue.
        """
        session, gallery = self._session, self._gallery_id
        if session is None or gallery is None:
            self._install_catalogue(self._tree_order)
            return
        for key in tuple(self._photo_info_cache):
            if key[0] == gallery:
                del self._photo_info_cache[key]
        if self._rendered_gallery == gallery:
            self._remember_viewport()
        self._gallery_has_photos = None
        self._install_catalogue(())
        self._sync_folder_section()
        self.status.setText(gettext("Loading photos…"))
        self._start_progress()
        self._load.submit(lambda cancel, publish: publish(
            PhotoCatalogue(read_gallery_photos(session, gallery, cancel)),
        ))
        self.playback.bind(session, gallery)
        self._update_preload_priority()

    def _remember_viewport(self) -> None:
        """Remember the last displayed viewport before refresh or navigation.

        Returns:
            None: Retains only recent gallery positions inside this authorization.
        """
        gallery = self._rendered_gallery
        first = self._requested[0]
        if gallery is None or not 0 <= first < len(self.model.rows):
            return
        self._viewports[gallery] = GalleryViewport(
            self.model.rows[first].identity, self._focused, self._anchor,
            frozenset(self.model.selected),
        )
        self._viewports.move_to_end(gallery)
        if len(self._viewports) > GALLERY_VIEWPORT_HISTORY:
            self._viewports.popitem(last=False)

    def _start_progress(self) -> None:
        """Show indeterminate progress while fetching catalogue metadata.

        Returns:
            None: Keeps photo controls usable while information is pending.
        """
        self.progress.setRange(0, 0)
        self.progress.show()

    def _install_catalogue(self, rows: tuple[PhotoRow, ...]) -> None:
        """Replace the view's identity generation before publishing any new pixels.

        Args:
            rows (tuple[PhotoRow, ...]): Newest-first rows, or an empty replacement.
        Returns:
            None: Resets state and queues the first visible thumbnail window.
        """
        self._cancel_thumbnail_group()
        self._dismiss_photo_context()
        self._dismiss_information()
        self.dismissPhoto()
        self._revision += 1
        self._healthy = False
        self._attempted.clear()
        self._anchor = None
        self._focused = None
        retired = tuple(self._tiles.values())
        self._tiles.clear()
        self._retained.clear()
        self._retained_bytes = 0
        self._wanted = ()
        self._tree_order = rows
        self._rendered_gallery = self._gallery_id if rows else None
        viewport = self._viewports.get(self._gallery_id) if rows else None
        self.model.replace(
            rows if self._newest else rows[::-1],
            selection=viewport.selection if viewport is not None else None,
        )
        for key in retired:
            self.textures.discard(key)
        self.progress.hide()
        self.status.setText(gettext("No photos found.") if not rows and self._local_photos_active else "")
        if rows:
            if viewport is None:
                self.scrollTo.emit(0)
            else:
                visible = self.model.index_by_id
                self._anchor = viewport.anchor if viewport.anchor in visible else None
                first = visible.get(viewport.first, 0)
                focus = visible.get(viewport.focus, first)
                self._focused = self.model.rows[focus].identity
                self.restoreScroll.emit(first, focus)
            self._viewport_timer.start()

    @Slot(int)
    def focusedPhoto(self, index: int) -> None:
        """Keep the stable photo identity for keyboard focus on a later restore.

        Args:
            index (int): Current Quick grid index, negative when there is no row.
        Returns:
            None: Saves only the active catalogue's photo identity.
        """
        self._focused = self.model.rows[index].identity if 0 <= index < len(self.model.rows) else None

    def toggle_order(self) -> None:
        """Switch chronological presentation while retaining selected photo IDs.

        Returns:
            None: Reorders logical rows and restores the selected position.
        """
        self.dismissPhoto()
        self._newest = not self._newest
        if self._view_path is not None and self._gallery_id is not None:
            save_gallery_photo_order(
                self._view_path, self._gallery_id, "newest" if self._newest else "oldest",
            )
        self._sync_order_action()
        rows = self._tree_order
        self.model.replace(rows if self._newest else rows[::-1], preserve=True)
        self.scrollTo.emit(self.model.index_by_id.get(self._anchor, 0))
        self._viewport_timer.start()

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

    def _apply_viewport(self) -> None:
        """Retain only visible textures and schedule the next bounded group.

        Returns:
            None: Prioritizes current rows and cancels obsolete transport.
        """
        if not self._photos_active or self.detailOpen:
            return
        begin, end = self._requested
        rows = self.model.rows[begin:min(end, begin + VIEWPORT_TEXTURE_LIMIT)]
        self._wanted = tuple(row.identity for row in rows)
        wanted = set(self._wanted)
        self._attempted.intersection_update(wanted)
        departing = self._tiles.keys() - wanted
        # Clear the live QML sources before removing their provider slots.
        self.model.update_textures(dict.fromkeys(departing, ""))
        for identity in departing:
            self.textures.discard(self._tiles.pop(identity))
        texture_changes: dict[int, str] = {}
        for row in rows:
            if row.identity not in self._tiles:
                cached = self._cached_frame(row)
                if cached is not None:
                    texture_changes[row.identity] = self._store_thumbnail(cached)
        self.model.update_textures(texture_changes)
        if self._group and not wanted.intersection(self._group):
            self._cancel_thumbnail_group()
        self.model.set_loading(self._loading_thumbnails.intersection(wanted).difference(self._tiles))
        self._start_group()

    def _cancel_thumbnail_group(self) -> None:
        """Cancel thumbnail ownership and retire the group's loading indicators.

        Returns:
            None: Replaced and inactive work no longer drives scene animation.
        """
        self._thumbnails.cancel()
        self._group = ()
        self._renewing_thumbnail_proofs = False
        self._thumbnail_proof_changed = False
        self._loading_thumbnails.clear()
        self.model.set_loading(set())

    def _start_group(self, *, renew_proofs: bool = False) -> None:
        """Admit one initial or healthy steady group through the shared transport.

        Args:
            renew_proofs (bool): Refresh authorized pages for one stale visible group.
        Returns:
            None: Maintains one thumbnail lane and an independent detail lane.
        """
        if not self._photos_active or self._group or self.detailOpen:
            return
        limit = (self._batch_limit if self._healthy and not renew_proofs
                 else MEDIA_HTTP_BATCH_INITIAL_SIZE)
        wanted = tuple(
            identity for identity in self._wanted
            if identity not in self._tiles and identity not in self._attempted
        )[:limit]
        if not wanted:
            self.progress.hide()
            return
        self._group = wanted
        rows = tuple(self.model.rows[self.model.index_by_id[identity]] for identity in wanted)
        session, gallery = self._session, self._gallery_id
        self._renewing_thumbnail_proofs = renew_proofs
        self._thumbnail_proof_changed = False
        if renew_proofs and session is not None and gallery is not None:
            self._thumbnails.submit(lambda cancel, publish: stream_revalidated_thumbnails(
                rows, session, gallery, cancel, publish,
            ))
        else:
            self._thumbnails.submit(lambda cancel, publish: stream_thumbnails(
                rows, session, gallery, cancel, publish,
            ))
        self._update_thumbnail_progress()

    def _receive_thumbnail(self, value: ThumbnailEvent) -> None:
        """Update visible loading indicators and admit still-authorized pixels.

        Args:
            value (ThumbnailEvent): Renewed page, decoded frame, read activity
                or health from the current serialized lane.
        Returns:
            None: Progressively publishes only current authorized pixels.
        """
        if isinstance(value, ThumbnailGroup):
            self._healthy = value.healthy
            return
        if isinstance(value, ThumbnailProofPage):
            self._apply_thumbnail_proof_page(value)
            return
        if isinstance(value, ThumbnailActivity):
            self._loading_thumbnails = set(value.identities).intersection(self._group)
            self.model.set_loading(self._loading_thumbnails.intersection(self._wanted).difference(self._tiles))
            return
        identity = value.row.identity
        self._loading_thumbnails.discard(identity)
        if identity in self.model.loading_ids:
            self.model.set_loading(self.model.loading_ids.difference((identity,)))
        if identity not in self._wanted or identity not in self._group:
            return
        if value.publication is not None and (
            self._session is None or not self._session.thumbnail_publication_current(value.publication)
        ):
            self._thumbnail_proof_changed = True
            return
        self._retain_frame(value)
        self.model.texture(identity, self._store_thumbnail(value))
        self._update_thumbnail_progress()

    def _apply_thumbnail_proof_page(self, result: ThumbnailProofPage) -> None:
        """Reconcile renewed rows and revoke obsolete pixels through the model.

        Args:
            result (ThumbnailProofPage): Authorized page from the active gallery read lane.
        Returns:
            None: Updates the existing catalogue slots without resetting scroll or selection.
        """
        page = result.page
        session = self._session
        if (page.gallery_id != self._rendered_gallery or session is None
                or not session.can_read_media()):
            return
        photos = {photo.image_id: photo for photo in page.photos}
        replacements: dict[int, PhotoRow] = {}
        retired: set[int] = set()
        for row in self.model.rows:
            identity = row.identity
            if (not isinstance(row.source, LinkedPhoto)
                    or identity <= (result.after_id or 0)
                    or (page.next_after is not None and identity > page.next_after)):
                continue
            photo = photos.get(identity)
            if photo is None:
                retired.add(identity)
                continue
            replacements[identity] = PhotoRow(identity, photo.title, photo)
            # A newly confirmed strong validator can keep the displayed tile.
            # It cannot renew the authority on a separately retained frame.
            if (not (photo.thumbnail_etag or photo.preload_thumbnail_etag)
                    or replace(row.source,
                               thumbnail_verified_at=photo.thumbnail_verified_at,
                               preload_verified_at=photo.preload_verified_at) != photo):
                retired.add(identity)
        self.model.update_textures(dict.fromkeys(retired, ''))
        for identity in retired:
            key = self._tiles.pop(identity, None)
            if key is not None:
                self.textures.discard(key)
        for identity in retired.union(replacements):
            cached = self._retained.pop(identity, None)
            if cached is not None:
                self._retained_bytes -= cached[2]
        if self._inspected_photo is not None and self._inspected_photo.image_id in retired:
            self._dismiss_information()
        for key in tuple(self._photo_info_cache):
            if key[0] == page.gallery_id and key[1] in retired:
                del self._photo_info_cache[key]
        self.model.renew_rows(replacements)
        if replacements:
            self._tree_order = self.model.rows if self._newest else self.model.rows[::-1]
        self._attempted.difference_update(retired.intersection(replacements))
        # A verified disappearance is terminal for this visit to the viewport.
        self._attempted.update(retired.difference(replacements).intersection(self._wanted))

    def _store_thumbnail(self, frame: PhotoFrame) -> str:
        """Prepare a viewport raster for publication without re-decoding.

        Args:
            frame (PhotoFrame): Current decoded and verified gallery result.
        Returns:
            str: Owned provider URL ready for the exact current model role.
        """
        identity = frame.row.identity
        key = f"tile/{self._revision}/{identity}"
        self._tiles[identity] = key
        return self.textures.store(key, frame.image)

    def _retain_frame(self, frame: PhotoFrame) -> None:
        """Retain only permitted decoded thumbnails within the common RAM cap.

        Args:
            frame (PhotoFrame): Current local preview or verified remote image.
        Returns:
            None: Local or independently admitted frames can warm the viewport.
        """
        publication = frame.publication
        if not isinstance(frame.row.source, Path) and (
            publication is None or not publication.ram_eligible
        ):
            return
        identity = frame.row.identity
        previous = self._retained.pop(identity, None)
        if previous is not None:
            self._retained_bytes -= previous[2]
        size = frame.image.sizeInBytes()
        self._retained[identity] = (frame, time.monotonic(), size)
        self._retained_bytes += size
        while (len(self._retained) > MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT
                or self._retained_bytes > MEDIA_THUMBNAIL_CACHE_MAX_BYTES):
            _evicted, (_frame, _stored, removed) = self._retained.popitem(last=False)
            self._retained_bytes -= removed

    def _cached_frame(self, row: PhotoRow) -> PhotoFrame | None:
        """Recheck proof before reusing a frame that left the scene.

        Args:
            row (PhotoRow): Exact current row from the active catalogue.
        Returns:
            PhotoFrame | None: Retained pixels or a miss after stale proof,
                different identity or unchanged cache age expires.
        """
        cached = self._retained.get(row.identity)
        if cached is None:
            return None
        frame, stored, size = cached
        publication = frame.publication
        current = frame.row == row and time.monotonic() - stored < MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS
        if publication is not None:
            current = current and self._session is not None and self._session.thumbnail_publication_current(publication)
        if not current:
            self._retained.pop(row.identity)
            self._retained_bytes -= size
            return None
        self._retained.move_to_end(row.identity)
        return frame

    def _group_finished(self, failure: str) -> None:
        """Advance the viewport or renew its stale thumbnail evidence once.

        Args:
            failure (str): Sanitized failure class, or empty on completion.
        Returns:
            None: Keeps departed rows eligible and bounds failed visible reads.
        """
        missing = set(self._group).intersection(self._wanted).difference(self._tiles)
        proof_changed = (failure == LinkingThumbnailProofChanged.__name__
                         or self._thumbnail_proof_changed)
        already_renewed = self._renewing_thumbnail_proofs
        self._group = ()
        self._renewing_thumbnail_proofs = False
        self._thumbnail_proof_changed = False
        self._loading_thumbnails.clear()
        self.model.set_loading(set())
        if proof_changed and not missing:
            self._start_group()
            return
        session = self._session
        if (proof_changed and not already_renewed and session is not None
                and self._gallery_id is not None and session.can_read_media()):
            self._healthy = False
            logging.getLogger(__name__).info('Photos renewing thumbnail proofs for visible rows.')
            self._start_group(renew_proofs=True)
            return
        # A group can finish after some of its original rows have left the
        # viewport. They need a new attempt when they enter the view again.
        self._attempted.update(missing)
        if failure or proof_changed:
            self._healthy = False
            self.progress.hide()
            logging.getLogger(__name__).warning(
                'Photos thumbnail group stopped: failure=%s; visible_missing=%d',
                failure or LinkingThumbnailProofChanged.__name__, len(missing),
            )
            self.status.setText(gettext("Some photos could not be loaded. Use Refresh to retry."))
            self.check_link()
            return
        self._start_group()

    def _update_thumbnail_progress(self) -> None:
        """Count exact currently published visible thumbnails.

        Returns:
            None: Updates the fixed progress strip without covering photos.
        """
        total = len(self._wanted)
        self.progress.setRange(0, total)
        # The viewport retires departed slots before launching another group.
        self.progress.setValue(len(self._tiles))
        self.progress.show()

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

    def _receive_detail(self, frame: PhotoFrame) -> None:
        """Install a decoded frame only if it belongs to the current selection.

        Args:
            frame (PhotoFrame): Current authorized original or local preview.
        Returns:
            None: Switches the retained URL and metadata without rescaling.
        """
        index = self._detail_index
        if index is None or frame.row != self.model.rows[index]:
            return
        if self._session is not None and not self._session.available():
            return
        self._revision += 1
        key = f"detail/{self._revision}"
        self._detail_url = self.textures.store(key, frame.image)
        self._detail_keys = (*self._detail_keys, key)
        self._detail_title = frame.row.title
        self._detail_display_index = index
        self._detail_hdr_source = frame.hdr_source
        crop = frame.content_crop
        self._detail_crop_rect = None
        if crop is not None:
            left, top, right, bottom = crop
            self._detail_crop_rect = QRectF(
                left, top, right - left, bottom - top,
            )
        if self._detail_crop_rect is not None:
            self._detail_content_crop_enabled = True
            self._hdr_restore_pending = False
            self._hdr_surface.clear()
        else:
            self._detail_content_crop_enabled = False
            self._hdr_restore_pending = False
            self._hdr_surface.present(self._detail_hdr_source, self._hdr_image_rect)
        self._sync_content_crop_action()
        self.detailChanged.emit()
        # Only a newly accepted picture resets the viewport's transform.
        self.zoomFitRequested.emit()
        # Keep the previous provider frame until the next URL is accepted.
        for retired in self._detail_keys[:-2]:
            self.textures.discard(retired)
        self._detail_keys = self._detail_keys[-2:]

    def _detail_finished(self, failure: str) -> None:
        """Expose the requested detail's error independently of the old frame.

        Args:
            failure (str): Sanitized decode or transport outcome.
        Returns:
            None: Hides progress and retains the last valid current frame.
        """
        self.progress.hide()
        self._detail_feedback = gettext("Photo could not be loaded.") if failure else ''
        self._detail_failed = bool(failure)
        self.detailChanged.emit()
        if failure:
            self.check_link()
        if self._playing and self.detailOpen:
            if failure and len(self.model.rows) == 1:
                self.stop_slideshow()
            else:
                self._slideshow.start()

    def check_link(self) -> None:
        """Retire expired authority and schedule bounded approved keepalive work.

        Returns:
            None: Clears all dependent pixels and selection if access is over.
        """
        self.playback.check_authority()
        if self._session is not None and not self._session.available():
            if self._tree_order or self.detailOpen:
                self._install_catalogue(())
            if self._attempt is None or not self._load.busy():
                self.disconnect()
            self.status.setText(gettext("Gallery connection is unavailable."))
        self._schedule_keepalive()

    def _schedule_keepalive(self) -> None:
        """Verify idle session identity on one cancellable lane.

        Returns:
            None: Starts at most one idle round per shared interval and gives
                active capture and Photos authorization precedence.
        """
        now = time.monotonic()
        if (self._authorization_active or self._startup_browse or self._pipeline_waiting
                or self._keepalive.busy() or now < self._keepalive_after):
            return
        due = tuple(site for site in self._live_sites() if site.session.keepalive_due() is True)
        if not due:
            return
        self._keepalive_after = now + LINKING_KEEPALIVE_SECONDS

        def verify(cancelled: Event, publish: Callable[[DesktopLinkedSite], None]) -> None:
            """Check only the already approved account on a read-only route.

            Args:
                cancelled (Event): Current owner lifetime for the idle round.
                publish (Callable[[DesktopLinkedSite], None]): Reports a failed
                    check for the still-exact in-memory site.
            Returns:
                None: Ends after all admitted sites or cancellation.
            """
            for site in due:
                if cancelled.is_set():
                    return
                if site.session.available():
                    try:
                        verify_live_gallery_activity(site)
                    except (RuntimeError, OSError):
                        publish(site)

        self._keepalive.submit(verify)

    def _keepalive_problem(self, site: DesktopLinkedSite) -> None:
        """Report a failed health check only for its current grant.

        Args:
            site (DesktopLinkedSite): Exact original site and account checked.
        Returns:
            None: Withdraws an expired current site or shows the safe retry caption.
        """
        if site.session is not self._session or self._identity != (site.issuer, site.user_id):
            self._live_sites()
            return
        if site.session.available():
            self.status.setText(tr(self.language, 'linking_connection_retry'))
        else:
            self.check_link()
            self.status.setText(tr(self.language, 'linking_reconnect_needed'))

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

    def toggle_slideshow(self) -> None:
        """Toggle the manual slideshow from the grid or the current detail.

        Returns:
            None: Playback starts from an exact photo after explicit operator
                input and keeps one outstanding detail read.
        """
        if self._playing:
            self.stop_slideshow()
            return
        if not self.model.rows:
            return
        self._playing = True
        if not self._fullscreen:
            self.toggle_fullscreen()
        stop_caption = tr(self.language, "linking_media_slideshow_stop")
        self._slideshow_button.setText("")
        self._slideshow_button.setIcon(qt_icon("pause"))
        self._slideshow_button.setAccessibleName(stop_caption)
        self._slideshow_button.setToolTip(stop_caption)
        index = self._detail_index
        if index is None:
            index = self.model.index_by_id.get(self._anchor, 0)
        self.openPhoto(index)
        self._slideshow_owns_music = self.playback.start_slideshow_music()

    def _presentation_interval(self, seconds: int) -> None:
        """Apply the gallery's allowed interval to explicit slideshow playback.

        Args:
            seconds (int): Validated presentation interval in seconds.
        Returns:
            None: Updates the active timer and permits newly authorized music.
        """
        # QTimer stores milliseconds, while the server's presets are seconds.
        self._slideshow.setInterval(seconds * 1000)
        if self._playing and not self._slideshow_owns_music:
            self._slideshow_owns_music = self.playback.start_slideshow_music()

    def stop_slideshow(self) -> None:
        """Stop the slideshow timer while keeping the currently shown detail.

        Returns:
            None: No automatic selection survives a page or session change.
        """
        self._slideshow.stop()
        if not self._playing:
            return
        self._playing = False
        if self._slideshow_owns_music:
            self._slideshow_owns_music = False
            self.playback.stop()
        start_caption = tr(self.language, "linking_media_slideshow_start")
        self._slideshow_button.setText("")
        self._slideshow_button.setIcon(qt_icon("play"))
        self._slideshow_button.setAccessibleName(start_caption)
        self._slideshow_button.setToolTip(start_caption)

    def _advance_slideshow(self) -> None:
        """Advance after the displayed frame's paced wait with stable wrapping.

        Returns:
            None: Reads one next frame or does nothing after cancellation.
        """
        if self._playing and self._detail_index is not None and self.model.rows:
            self.openPhoto((self._detail_index + 1) % len(self.model.rows))

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

    def shutdown(self) -> None:
        """Cancel all view work before the single Qt application exits.

        Returns:
            None: Releases ephemeral grants, retires the scene while its
                bindings are still owned and stops result publication.
        """
        self._dismiss_photo_context()
        self._capture_pulse_timer.stop()
        if self._gallery_picker is not None:
            self._gallery_picker.reject()
        parked = self._parked_links
        self._parked_links = ()
        self.disconnect()
        for site in parked:
            if site.session is not self._session:
                site.session.close()
        self._viewport_timer.stop()
        self._preload_timer.stop()
        self.playback.shutdown()
        self._folder_shelf.shutdown()
        for lane in (
            self._load, self._cache_probe, self._thumbnails, self._details, self._maintenance,
            self._information, self._inspector_read, self._keepalive, self._scope_discovery,
        ):
            lane.close()
        self.quick.setSource(QUrl())
