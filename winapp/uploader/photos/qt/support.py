# Project: PHP Gallery

# Repository: https://github.com/klusik/PHP_gallery

# File: winapp/uploader/photos/qt/support.py

# Module Type: Qt Photos shared contracts

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

# Keep the pre-split logger identity used by compatibility callers and tests.
QT_PHOTOS_LOGGER = logging.getLogger("uploader.qt_photos")

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

from ...linking import (

    GALLERIES_SCOPE, LINKING_KEEPALIVE_SECONDS, LINKING_RECOVERY_MAX_FILE_BYTES,

    MEDIA_RECENT_ORDER_PHOTO_LIMIT,

    NATIVE_MEDIA_DELETE_BATCH_MAX, UPLOAD_SCOPE, LinkedGallery, LinkedMediaDetails,

    LinkedPhoto, LinkingAttempt, LinkingError, LinkingReconnectRequired,

    LinkingRefreshRejected, LinkingResponseError, LinkingSession,

    LinkingThumbnailProofChanged, LinkingUnavailable,

    discover_linking_capabilities,

    linking_issuer, linking_prompt_issuer, requested_linking_scope, scope_base,

)

from ...config import load_thumbnail_batch_size, load_ui_language

from ...credential_store import CredentialStoreUnavailable, NativeCredentialStore

from ...desktop_connection import (

    ConnectionActions, DesktopLinkedSite, LinkedRecoveryActions, desktop_upload_vault,

    find_live_link, forget_browser_upload_grant, gallery_editor_url,

    live_link_sites, open_saved_upload_session, persist_browser_gallery_grant,

    persist_browser_scope_upgrade, persist_browser_upload_grant,

    remember_desktop_transient_link, restore_desktop_gallery_link,

    revoke_saved_gallery_grant, saved_desktop_gallery_binding, verify_live_gallery_activity,

)

from ...diagnostics import format_safe_photos_failure

from ...gallery_information import (

    PHOTOS_INFO_CACHE_ENTRY_LIMIT, PHOTOS_INFO_CACHE_FRESH_SECONDS,

    InformationGroup, format_gallery_bytes, photo_information_groups, thumbnail_quality_caption,

)

from ...gallery_preload import (

    CacheProbeState, GalleryCacheProbeRow, GalleryPreloadWorker,

    probe_gallery_cache_status,

)

from ...gallery_image_policy import (

    MEDIA_HTTP_BATCH_INITIAL_SIZE, MEDIA_SLIDESHOW_DEFAULT_SECONDS,

    MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,

    MEDIA_THUMBNAIL_CACHE_MAX_BYTES, MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS,

    MEDIA_THUMBNAIL_SIZE, MEDIA_VIEWPORT_COALESCE_MS,

)

from ...gallery_view_preferences import (

    GALLERY_FOLDER_SHARE_BOUNDS, GALLERY_FOLDER_SHARE_SCALE, PHOTO_CARD_FORMATS,

    PhotosTreePreferences, gallery_card_dimensions, load_gallery_card_format,

    load_gallery_folder_split, load_gallery_photo_order, load_photos_tree_preferences,

    photos_account_tree_path, save_gallery_card_format, save_gallery_folder_split,

    save_gallery_photo_order, save_photos_tree_preferences,

)

from ...flight_report_translations import tr

from ...localization import gettext, gettext_noop

from ...linked_create import BoundCreateReceipt, GalleryCreateJournal

from ...linked_upload import LinkedUploadTarget, verify_linked_upload_target

from ...offline_linking import (

    OfflineBinding, OfflineRefreshPending, enable_rolling_renewal, load_offline_binding,

    remove_offline_binding, save_offline_binding, saved_offline_hint_path,

    store_approved_refresh,

)

from ...qt_folder_shelf import QtFolderShelf

from ...qt_gallery_editor import QtGalleryEditor

from ...qt_photo_inspector import (

    INSPECTOR_PREVIEW_PIXELS, InspectorMetadata, InspectorPane, InspectorPreview,

)

from ...qt_photo_model import PhotoListModel, PhotoTextures

from ...qt_gallery_playback import GalleryPlayback

from ...qt_hdr_macos import MacQtHDRSurface

from ...qt_photo_source import (

    PhotoFrame, PhotoRow, ThumbnailActivity, ThumbnailEvent, ThumbnailGroup,

    ThumbnailProofPage, read_gallery_photos, read_gallery_tree, read_photo,

    stream_revalidated_thumbnails, stream_thumbnails,

)

from ...qt_read_lane import QtReadLane, ReadCancelled

from ...qt_theme import ACTION_ICON_SIZE, ActionButton, RaisedSurface, ToggleSwitch, qt_icon

from ...session_resume import delete_transient, load_transient

from ...state_store import (

    RECENT_GALLERY_ADDRESS_LIMIT, load_recent_gallery_addresses,

    save_recent_gallery_addresses,

)

from ...upload_mode import ModeRecord

from ...ui_layout import (

    DESKTOP_DIALOG_MARGIN, DESKTOP_DIALOG_SIZE, LINKING_ADDRESS_VISIBLE_ROWS, PHOTOS_HEADING_STACK_WIDTH,

    PHOTOS_HEADING_TITLE_MINIMUM, PHOTOS_INFO_PANE_WIDTH, PHOTOS_TREE_WIDTH,

    SHELL_ICON_BUTTON_EDGE, UI_LAYOUT_INFO_WIDTH_BOUNDS,

    UI_LAYOUT_VIEWER_MINIMUM_WIDTH,

)

from ...ui_palette import PALETTE, PHOTOS_CARD_FORMAT_ICON_SIZE, UI_PHOTO_FORMAT_LABEL_POINTS

_SCENE = (
    Path(__file__).resolve().parents[2]
    / "resources" / "qml" / "Photos.qml"
)

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

@dataclass(frozen=True)

class SavedLinkRecovery:

    """Carry the exact linked upload mode eligible for explicit recovery.

    Attributes:

        saved (ModeRecord): Captured linked destination whose native refresh

            binding matched issuer, account and scope during startup restore.

    """

    saved: ModeRecord

PhotosLoadResult = (

    LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress

    | PhotosUploadUpgrade | SavedLinkRecovery

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

__all__ = [name for name in globals() if not name.startswith('__')]
