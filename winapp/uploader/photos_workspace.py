# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos_workspace.py
# Module Type: Windows Client UI
# Purpose: Show linked galleries and selected photos in one main-window workspace.
# Responsibilities:
#   - Restore known gallery structure and reconcile bounded fresh catalogue reads.
#   - Display a hierarchy and mount one embedded gallery viewer.
#   - Anchor scoped photo, gallery and creation actions in the workspace.
#   - Cancel reads and release gallery state when the session or page changes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Main-window gallery hierarchy and embedded photo workspace."""

from collections import OrderedDict, deque
from dataclasses import dataclass
import logging
import queue
import threading
import time
import tkinter as tk
from tkinter import ttk
from typing import Callable, TypeAlias

from .app_linking import (
    LINKING_CREATE_TITLE_MAX_BYTES,
    LINKING_GALLERY_ID_MAX,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    NATIVE_MEDIA_MAX_BYTES,
    LinkedGallery, LinkedGalleryDetail, LinkedGalleryPage, LinkedGalleryTree,
    LinkedMediaDetails,
    LinkedMediaGalleryInfo, LinkedPhoto, LinkingSession,
    LinkingMediaThrottled, LinkingResponseError,
)
from .app_linking_ui import LinkingManager
from .gallery_catalogue_cache import (
    GalleryCatalogueCache, GalleryCatalogueSnapshot, GalleryCatalogueWriter,
)
from .diagnostics import (
    format_safe_photos_failure, is_safe_photos_observation,
)
from .flight_report_translations import tr
from .gallery_folders import GalleryFolderShelf
from .gallery_preload import GalleryPreloadWorker, PreloadProgress
from .gallery_media_cache import (
    GalleryMediaCache,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES as THUMBNAIL_DISK_DEFAULT_BYTES,
)
from .gallery_view_preferences import PHOTO_CARD_FORMATS, normalize_card_format
from .config import (
    THUMBNAIL_CACHE_LIMIT_CHOICES_GIB, set_thumbnail_cache_limit_gib,
)
from .process_memory import current_process_ram_bytes
from .linked_create import (
    BoundCreateReceipt, CreateDispatch, CreateJournalSnapshot,
)
from .linked_upload import LinkedUploadTarget
from .native_gallery_viewer import (
    GalleryThumbnailMemory, _log_safe_media_failure,
    mount_native_gallery_view,
)
from .tk_scroll_input import vertical_scroll_delta
from .ui_theme import (
    GlassCard, ThemeImage, _PALETTE, attach_inline_tooltip,
    create_smooth_icon, icon_button,
)


# Purpose: Keep Tk result delivery responsive during sequential page loading.
# Type: int. Units: milliseconds. Scope: one PhotosWorkspace lifetime.
# Consumers: PhotosWorkspace._poll_events.
# Rationale: 80 ms gives quick feedback without a busy UI polling loop.
PHOTOS_CATALOG_POLL_MS = 80

# Purpose: Start with a compact hierarchy and leave room for photos.
# Type: int. Units: pixels. Scope: the left gallery-tree pane.
# Consumers: PhotosWorkspace._build_widgets.
# Rationale: 210 px keeps common names visible and the splitter adjustable.
PHOTOS_TREE_WIDTH = 210

# Purpose: Bound implicit attempts to find a gallery with visible photos.
# Type: int. Units: gallery candidates. Scope: one session auto-selection.
# Consumers: PhotosWorkspace._ensure_initial_gallery.
# Rationale: Three candidates handle a few empty/protected galleries quickly.
PHOTOS_INITIAL_CANDIDATE_LIMIT = 3

# Purpose: Bound the initial unpaced catalogue burst against shared request limits.
# Type: int. Units: pages. Scope: initial unpaced burst per live session.
# Consumers: PhotosWorkspace._continue_catalogue.
# Rationale: Ten 50-row pages load common trees while preserving site capacity.
PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT = 10

# Purpose: Pace catalogue continuation after the first automatic burst.
# Type: int. Units: milliseconds. Scope: each page after the initial pass cap.
# Consumers: PhotosWorkspace._continue_catalogue.
# Rationale: One five-second interval limits steady requests without a user tap.
PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS = 5_000

# Purpose: Wait a full genuine gallery request budget without Retry-After.
# Type: int. Units: seconds. Scope: one Photos catalogue HTTP 429 recovery.
# Consumers: _schedule_catalogue_throttled_page. Rationale: the existing IP
# limiter permits 120 requests per 300-second window.
PHOTOS_CATALOGUE_RATE_RETRY_DEFAULT_SECONDS = 300

# Purpose: Bound consecutive 429 retries without starving the Photos UI.
# Type: int. Units: attempts per page. Scope: one live catalogue generation.
# Consumers: _schedule_catalogue_throttled_page. Rationale: two retries
# tolerate a temporarily shared limiter without an unbounded retry storm.
PHOTOS_CATALOGUE_RATE_MAX_RETRIES = 2

# Purpose: Advance prepared activity artwork without continuous widget redraws.
# Type: int. Units: milliseconds. Scope: Photos catalogue/preload/storage activity.
# Consumers: PhotosWorkspace catalogue, preload and storage animation callbacks.
# Rationale: 80 ms is smooth using pre-rendered artwork with no Pillow frame work.
PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS = 80

# Purpose: Bound retained pre-rendered catalogue spinner frames.
# Type: int. Units: images. Scope: one PhotosWorkspace indicator.
# Consumers: PhotosWorkspace._build_widgets.
# Rationale: Twelve evenly spaced frames make motion smooth with tiny retained art.
PHOTOS_CATALOGUE_SPINNER_FRAME_COUNT = 12

# Purpose: Keep mounting an encrypted complete catalogue responsive on Tk.
# Type: int. Units: gallery rows per UI callback. Scope: cached-tree display.
# Consumers: PhotosWorkspace._mount_cached_catalogue_batch.
# Rationale: 64 rows bounds each UI slice while keeping large trees useful.
PHOTOS_CATALOGUE_CACHE_BATCH_SIZE = 64

# Purpose: Yield between cached-tree UI batches without a zero-delay loop.
# Type: int. Units: milliseconds. Scope: cached-tree display callbacks.
# Consumers: PhotosWorkspace._schedule_cached_catalogue_batch.
# Rationale: 16 ms yields one frame to other workspace events.
PHOTOS_CATALOGUE_CACHE_BATCH_DELAY_MS = 16

# Purpose: Bound metadata-only cache checks for large galleries.
# Type: int. Units: pages. Scope: one gallery info action.
# Consumers: PhotosWorkspace._gallery_info.
# Rationale: Ten pages cover typical albums while capping remote cursor reads.
PHOTOS_CACHE_PAGE_LIMIT = 10

# Purpose: Keep long metadata cards scrollable inside the current workspace.
# Type: int. Units: pixels. Scope: one on-demand information card.
# Consumers: PhotosWorkspace._render_info_card.
# Rationale: 480 px leaves room for surrounding controls and remains bounded.
PHOTOS_INFO_CARD_MAX_HEIGHT = 480

# Purpose: Keep detail cards readable without covering the full workspace.
# Type: int. Units: pixels. Scope: one on-demand information card.
# Consumers: PhotosWorkspace._render_info_card.
# Rationale: 420 px fits two wrapped label/value lines at narrow widths.
PHOTOS_INFO_CARD_MAX_WIDTH = 420

# Purpose: Retain recent authorized media-info responses for quick revisit.
# Type: int. Units: photo metadata rows. Scope: one live LinkingSession.
# Consumers: PhotosWorkspace._photo_info and _inspect_photo.
# Rationale: 32 entries cover nearby browsing without retaining a catalogue.
PHOTOS_INFO_CACHE_ENTRY_LIMIT = 32

# Purpose: Revalidate photo metadata after a short same-session reuse interval.
# Type: float. Units: monotonic seconds. Scope: one metadata LRU entry.
# Consumers: PhotosWorkspace._cached_photo_info.
# Rationale: One minute matches media freshness without retaining changed
# descriptions, access information or location indefinitely.
PHOTOS_INFO_CACHE_FRESH_SECONDS = 60.0

# Purpose: Keep inspector labels readable after the pane is collapsed.
# Type: int. Units: pixels. Scope: minimum width of the optional info pane.
# Consumers: PhotosWorkspace._show_photo_inspector_pane.
# Rationale: 128 px leaves space for a short label and one scrollbar.
PHOTOS_INFO_PANE_MIN_WIDTH = 128

# Purpose: Keep one safe metadata-inspector column compact and adjustable.
# Type: int. Units: pixels. Scope: optional right Photos pane.
# Consumers: PhotosWorkspace._build_widgets.
# Rationale: 250 px fits common localized labels and remains collapsible.
PHOTOS_INFO_PANE_WIDTH = 250

# Purpose: Keep a thumbnail column usable when the inspector first opens.
# Type: int. Units: pixels. Scope: initial grid/inspector width allocation.
# Consumers: PhotosWorkspace._show_photo_inspector_pane.
# Rationale: 180 px fits the existing 144 px image and surrounding grid padding;
# exceptionally narrow layouts share the available space instead.
PHOTOS_INFO_GRID_RESERVE_WIDTH = 180

# Purpose: Keep inspector titles clear of the scrollbar and pane edge.
# Type: int. Units: pixels. Scope: heading and summary wrap width.
# Consumers: PhotosWorkspace._resize_photo_inspector_viewport.
# Rationale: 16 px preserves the surrounding compact frame padding.
PHOTOS_INFO_PANE_HEADER_INSET = 16

# Purpose: Keep wrapped metadata rows clear of their pane boundaries.
# Type: int. Units: pixels. Scope: inspector field label wrap width.
# Consumers: PhotosWorkspace._resize_photo_inspector_viewport.
# Rationale: 24 px leaves inner frame padding on both sides.
PHOTOS_INFO_PANE_ROW_INSET = 24

# Purpose: Move Photos heading controls below the title in a narrow pane.
# Type: int. Units: pixels. Scope: the selected-gallery heading.
# Consumers: PhotosWorkspace._on_heading_resize.
# Rationale: 440 pixels gives the localized switch and title useful space.
PHOTOS_HEADING_STACK_WIDTH = 440

# Purpose: Reserve readable title space beside both desktop toolbar groups.
# Type: int. Units: pixels. Scope: selected-gallery heading.
# Consumers: PhotosWorkspace._on_heading_resize.
# Rationale: 120 pixels avoids collapsing the gallery name to a sliver.
PHOTOS_HEADING_TITLE_MINIMUM = 120

# Purpose: Size compact photo-format silhouettes in the gallery heading.
# Type: tuple[int, int]. Units: logical pixels. Scope: one format button.
# Consumers: PhotosWorkspace._build_widgets.
# Rationale: 28 by 18 keeps portrait silhouettes legible while the icon and
# short ratio label still fit together in one compact toolbar control.
PHOTOS_CARD_FORMAT_ICON_SIZE = (28, 18)

# Purpose: Keep the recording dot compact beside a gallery name.
# Type: int. Units: pixels. Scope: one tree or heading indicator.
# Consumers: PhotosWorkspace._recording_image.
# Rationale: Nine pixels remain legible without changing the tree row height.
PHOTOS_RECORDING_DOT_SIZE = 9

# Purpose: Debounce the Photos tree sash before updating local profile state.
# Type: int. Units: milliseconds. Scope: one PhotosWorkspace sash drag.
# Consumers: PhotosWorkspace._schedule_tree_width_save.
# Rationale: 300 ms coalesces pointer motion into one atomic preference update.
PHOTOS_TREE_WIDTH_SAVE_DELAY_MS = 300

# Purpose: Debounce the right inspector sash using the established tree policy.
# Type: int. Units: milliseconds. Scope: one inspector sash drag.
# Consumers: PhotosWorkspace._schedule_info_width_save.
# Rationale: 300 ms coalesces pointer motion into one profile update.
PHOTOS_INFO_WIDTH_SAVE_DELAY_MS = 300

# Purpose: Restore the inspector after Tk settles a reinserted pane.
# Type: int. Units: milliseconds. Scope: one inspector show operation.
# Consumers: PhotosWorkspace._show_photo_inspector_pane.
# Rationale: 50 ms lets native pane geometry settle before positioning its sash.
PHOTOS_INFO_RESTORE_DELAY_MS = 50
# Purpose: Bound persisted right inspector widths across display sizes.
# Type: tuple[int, int]. Units: Tk pixels. Scope: saved Photos inspector width.
# Consumers: PhotosWorkspace._restore_info_width and _persist_info_width.
# Rationale: 180 px keeps values readable and 600 px preserves viewer room.
PHOTOS_INFO_WIDTH_BOUNDS = (180, 600)
# Purpose: Preserve a usable viewer beside the visible right inspector.
# Type: int. Units: Tk pixels. Scope: visible right-side inspector layout.
# Consumers: PhotosWorkspace._restore_info_width.
# Rationale: 320 px leaves room for a useful photo column and viewer controls.
PHOTOS_INFO_VIEWER_MINIMUM_WIDTH = 320

# Purpose: Keep composite cache and Watcher marks legible beside gallery titles.
# Type: int. Units: logical pixels. Scope: one Photos tree marker image.
# Consumers: PhotosWorkspace._tree_marker.
# Rationale: 18 px fits the 30 px tree row without changing title spacing.
PHOTOS_TREE_MARKER_SIZE = 18

# Purpose: Coalesce successful media reads before checking local cache headers.
# Type: int; Units: milliseconds; Scope: the current Photos workspace.
# Consumers: PhotosWorkspace._cache_snapshot_changed.
# Rationale: 300 ms groups thumbnail completions without remote metadata reads.
PHOTOS_CACHE_REFRESH_MS = 300

# Purpose: Coalesce cross-process disk accounting and optional RAM observations.
# Type: int. Units: milliseconds. Scope: one PhotosWorkspace lifetime.
# Consumers: PhotosWorkspace._poll_storage. Rationale: 2000 ms keeps live values
# fresh without opening SQLite per GUI tick; all I/O remains off Tk.
PHOTOS_STORAGE_REFRESH_MS = 2000
# Purpose: Deliver event-driven accounting while the existing observer works.
# Type: int. Units: milliseconds. Scope: one active storage observation.
# Consumers: _request_storage_refresh and _poll_storage.
# Rationale: 80 ms exposes ready totals promptly; polls never perform disk I/O.
PHOTOS_STORAGE_ACTIVE_POLL_MS = 80

# Purpose: Show quota use as a compact visual beside the measured values.
# Type: int; Units: pixels; Scope: one Photos storage context.
# Consumers: PhotosWorkspace._open_storage_context. Rationale: 48 by 4 px
# provides a legible cue without expanding the compact storage row.
PHOTOS_STORAGE_BAR_WIDTH = 48
PHOTOS_STORAGE_BAR_HEIGHT = 4
# Purpose: Keep progress text clear of the activity ring and cancel action.
# Type: int. Units: pixels. Scope: one responsive preload status row.
# Consumers: PhotosWorkspace._on_heading_resize. Rationale: 16 px covers the
# existing control gaps without forcing a wide label on narrow viewports.
PHOTOS_PRELOAD_CONTROL_GAP = 16

# Purpose: Keep a compact failure trail available to copied support diagnostics.
# Type: int. Units: diagnostic summaries. Scope: one live Photos session.
# Consumers: PhotosWorkspace._photo_diagnostic_recent.
# Rationale: Retain the latest useful failures without an unbounded event log.
PHOTOS_DIAGNOSTIC_RECENT_LIMIT = 12

# Purpose: Bound one safe Photos diagnostic line retained for support.
# Type: int. Units: characters. Scope: one Photos failure summary.
# Consumers: PhotosWorkspace._record_photo_diagnostic.
# Rationale: Formatter facts are short; reject any accidental oversized payload.
PHOTOS_DIAGNOSTIC_MAX_CHARS = 512

# Purpose: Keep a small successful-observation trail separate from failures.
# Type: int. Units: observations. Scope: one live Photos session.
# Consumers: PhotosWorkspace._photo_observation_recent.
# Rationale: Shortcut and decoder notes aid support without replacing failures.
PHOTOS_OBSERVATION_RECENT_LIMIT = 8

@dataclass(frozen=True)
class _CatalogueCacheResult:
    """Deliver optional account-bound cache data to the Tk owner.

    Args:
        generation (int): Catalogue pass that began the cache read.
        snapshot (GalleryCatalogueSnapshot | None): Validated last-known local
            display metadata, or None when storage was unavailable or empty.
    """

    generation: int
    snapshot: GalleryCatalogueSnapshot | None


@dataclass(frozen=True)
class _CatalogueTreeResult:
    """Deliver a validated fresh tree-v1 response to the Tk owner.

    Args:
        generation (int): Catalogue pass that began the request.
        tree (LinkedGalleryTree): Complete rows or matching unchanged proof.
    """

    generation: int
    tree: LinkedGalleryTree


@dataclass(frozen=True)
class _CataloguePageResult:
    """Deliver one validated legacy gallery page to the Tk owner.

    Args:
        generation (int): Catalogue pass that began the request.
        page (LinkedGalleryPage | None): Fresh page or safe failure marker.
    """

    generation: int
    page: LinkedGalleryPage | None
    throttled: bool = False
    retry_after_seconds: int | None = None
    cursor: int | None = None


_GalleryResult: TypeAlias = (
    _CatalogueCacheResult | _CatalogueTreeResult | _CataloguePageResult
)
_CatalogueCacheFactory: TypeAlias = Callable[
    [LinkingSession], GalleryCatalogueCache | None,
]
_InfoRow: TypeAlias = tuple[str, str | int | float | bool | None]
_InfoGroup: TypeAlias = tuple[str, list[_InfoRow]]
_PhotoInfoResult: TypeAlias = tuple[
    int, LinkingSession, int, LinkedPhoto, LinkedMediaDetails | None,
]
# Each row contains state, complete-photo count, observed-photo count,
# catalogue completeness and the locally persisted pin (None if unavailable).
_CacheRow: TypeAlias = tuple[str | None, int, int, bool, bool | None]
# Ordinary totals, retained totals, process RAM bytes and completed-scan flag.
_StorageObservation: TypeAlias = tuple[
    tuple[int | None, int | None, int | None, bool, str | None],
    tuple[int | None, int | None, int | None, int | None, int | None, bool],
    int | None,
    bool,
]


class PhotosWorkspace(ttk.Frame):
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
        catalogue_cache: _CatalogueCacheFactory | None = None,
        load_tree_width: Callable[[], int | None] | None = None,
        save_tree_width: Callable[[int], None] | None = None,
        load_info_width: Callable[[], int | None] | None = None,
        save_info_width: Callable[[int], None] | None = None,
        storage_parent: tk.Misc | None = None,
        storage_surface: tk.Misc | None = None,
        diagnostic_callback: Callable[[str], None] | None = None,
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

        Returns:
            None: Starts a bounded asynchronous catalogue read when available.
        """
        super().__init__(parent, style="Backdrop.TFrame")
        self._session_provider = session_provider
        self._connect = connect
        self._language = language
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
        self._load_card_format = load_card_format
        self._save_card_format = save_card_format
        self._card_format_overrides: dict[int, str] = {}
        self._card_format_unsaved: set[int] = set()
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
        self._create_receipt: BoundCreateReceipt | None = None
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
        self._catalogue_generation = 0
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

    def _text(self, key: str) -> str:
        """Translate a stable workspace key for the current language.

        Args:
            key (str): Photos-workspace translation key.

        Returns:
            str: Localized label with the translation module's fallback.
        """
        return tr(self._language(), "photos_workspace_" + key)

    def _build_widgets(self) -> None:
        """Create the connection action, hierarchy, viewer and inspector panes.

        Returns:
            None: Installs the workspace widgets in the main window.
        """
        self.connect_view = ttk.Frame(self, style="Card.TFrame", padding=18)
        style = ttk.Style(self)
        danger = style.lookup('Watcher.Active.TLabel', 'foreground') or '#FF91A2'
        style.configure(
            'Danger.Action.Photos.Icon.TButton', foreground=danger,
        )
        self.connect_view.grid(row=0, column=0, sticky="nw", padx=18, pady=18)
        ttk.Button(
            self.connect_view,
            text=tr(self._language(), "linking_start"),
            command=self._connect,
            style="Accent.Compact.TButton",
        ).grid(row=0, column=0, sticky="w")

        self.panes = ttk.Panedwindow(self, orient="horizontal")
        self.panes.grid(row=0, column=0, sticky="nsew")
        self.tree_pane = ttk.Frame(self.panes, style="Card.TFrame", padding=8)
        self.viewer_pane = ttk.Frame(self.panes, style="Card.TFrame", padding=8)
        self.info_pane = ttk.Frame(self.panes, style="Card.TFrame", padding=8)
        self.panes.add(self.tree_pane, weight=0)
        self.panes.add(self.viewer_pane, weight=1)
        self.info_pane.configure(width=PHOTOS_INFO_PANE_WIDTH)
        self.info_pane.grid_propagate(False)
        self.info_pane.rowconfigure(2, weight=1)
        self.info_pane.columnconfigure(0, weight=1)
        self._photo_inspector_heading = ttk.Label(
            self.info_pane, text=self._text("photo_info"),
            style="Section.TLabel",
            wraplength=(
                PHOTOS_INFO_PANE_WIDTH - PHOTOS_INFO_PANE_HEADER_INSET
            ),
        )
        self._photo_inspector_heading.grid(
            row=0, column=0, sticky="ew", pady=(0, 4),
        )
        self._photo_inspector_summary = ttk.Label(
            self.info_pane, text=self._text("select_photo_hint"),
            style="Muted.TLabel",
            wraplength=(
                PHOTOS_INFO_PANE_WIDTH - PHOTOS_INFO_PANE_HEADER_INSET
            ),
            justify="left",
        )
        self._photo_inspector_summary.grid(
            row=1, column=0, sticky="ew", pady=(0, 6),
        )
        inspector_body = ttk.Frame(self.info_pane, style="Card.TFrame")
        inspector_body.grid(row=2, column=0, sticky="nsew")
        inspector_body.rowconfigure(0, weight=1)
        inspector_body.columnconfigure(0, weight=1)
        self._photo_inspector_canvas = tk.Canvas(
            inspector_body, highlightthickness=0, borderwidth=0,
            background=ttk.Style(self).lookup(
                "Card.TFrame", "background",
            ) or _PALETTE["card_surface"],
        )
        self._photo_inspector_scrollbar = ttk.Scrollbar(
            inspector_body, orient="vertical",
            command=self._photo_inspector_canvas.yview,
        )
        self._photo_inspector_canvas.configure(
            yscrollcommand=self._photo_inspector_scrollbar.set,
        )
        self._bind_photo_inspector_scroll(self._photo_inspector_canvas)
        self._photo_inspector_canvas.grid(row=0, column=0, sticky="nsew")
        self._photo_inspector_scrollbar.grid(row=0, column=1, sticky="ns")
        self._photo_inspector_content = ttk.Frame(
            self._photo_inspector_canvas, style="Card.TFrame", padding=(2, 1),
        )
        self._photo_inspector_window = (
            self._photo_inspector_canvas.create_window(
                (0, 0), window=self._photo_inspector_content,
                anchor="nw",
            )
        )
        self._photo_inspector_content.bind(
            "<Configure>", self._resize_photo_inspector_content, add="+",
        )
        self._bind_photo_inspector_scroll(self._photo_inspector_content)
        self._photo_inspector_canvas.bind(
            "<Configure>", self._resize_photo_inspector_viewport, add="+",
        )
        self.tree_pane.configure(width=PHOTOS_TREE_WIDTH)
        self.tree_pane.grid_propagate(False)
        self.tree_pane.rowconfigure(2, weight=1)
        self.tree_pane.columnconfigure(0, weight=1)
        controls = ttk.Frame(self.tree_pane, style="Card.TFrame")
        controls.grid(row=0, column=0, sticky="ew", pady=(0, 6))
        icon_button(
            controls,
            "+",
            lambda: self._set_all_open(True),
            self._text("expand_all"),
        ).pack(side="left")
        icon_button(
            controls,
            "−",
            lambda: self._set_all_open(False),
            self._text("collapse_all"),
        ).pack(side="left", padx=(4, 0))
        self.add_root_button = icon_button(
            controls,
            "⊕",
            lambda: self._open_creation_context(self.add_root_button, None),
            self._text("create_root"),
        )
        self.add_root_button.pack(side="left", padx=(4, 0))
        self.tree_refresh_button = icon_button(
            controls, "refresh", self._refresh_catalogue,
            self._text("refresh_galleries"),
        )
        self.tree_refresh_button.pack(side="left", padx=(4, 0))
        self.load_more_button = ttk.Button(
            controls,
            text=self._text("load_more"),
            command=self._load_more_catalogue,
            style="Compact.TButton",
        )
        self.load_more_button.pack(side="right")
        self.load_more_button.pack_forget()
        self.retry_button = ttk.Button(
            controls,
            text=self._text("retry"),
            command=self._retry_catalogue,
            style="TButton",
        )
        self.retry_button.pack(side="right")
        self.retry_button.pack_forget()
        self._catalogue_status = ttk.Frame(
            self.tree_pane, style="Card.TFrame",
        )
        self._catalogue_status.grid(
            row=1, column=0, columnspan=2, sticky="ew", pady=(0, 4),
        )
        self._catalogue_status.columnconfigure(1, weight=1)
        spinner_frames = tuple(
            image for frame_index in range(
                PHOTOS_CATALOGUE_SPINNER_FRAME_COUNT,
            )
            if (image := create_smooth_icon(
                self._catalogue_status,
                "loading_spinner",
                (16, 16),
                angle=(360 * frame_index)
                // PHOTOS_CATALOGUE_SPINNER_FRAME_COUNT,
                tone="primary",
            )) is not None
        )
        self._catalogue_spinner_frames = spinner_frames
        self._catalogue_spinner_index = 0
        self._catalogue_spinner_label = ttk.Label(
            self._catalogue_status,
            image=spinner_frames[0] if spinner_frames else "",
            style="Card.TLabel",
        )
        self._catalogue_spinner_label.grid(
            row=0, column=0, sticky="w", padx=(0, 6),
        )
        self._catalogue_spinner_label.grid_remove()
        self.tree_status = ttk.Label(
            self._catalogue_status, text="", style="Muted.TLabel",
            wraplength=PHOTOS_TREE_WIDTH - 48,
        )
        self.tree_status.grid(row=0, column=1, sticky="w")
        self.tree = ttk.Treeview(
            self.tree_pane,
            show="tree",
            selectmode="browse",
            takefocus=True,
        )
        self.tree.grid(row=2, column=0, sticky="nsew")
        tree_scroll = ttk.Scrollbar(
            self.tree_pane,
            orient="vertical",
            command=self.tree.yview,
        )
        tree_scroll.grid(row=2, column=1, sticky="ns")
        self.tree.configure(yscrollcommand=tree_scroll.set)
        self.tree.bind("<<TreeviewSelect>>", self._on_gallery_selected)
        self.tree.bind("<<TreeviewOpen>>", self._on_tree_expansion, add="+")
        self.tree.bind("<<TreeviewClose>>", self._on_tree_expansion, add="+")

        for sequence in ("<Button-3>", "<Button-2>", "<Control-Button-1>"):
            self.tree.bind(sequence, self._tree_context)
        storage_parent = self._storage_parent or self.viewer_pane
        self._storage_heading = GlassCard(storage_parent, padding=(8, 4))
        self._storage_heading.pack(anchor='e')
        self._storage_text = tk.StringVar(self, self._text('storage_loading'))
        self._storage_button = ttk.Button(
            self._storage_heading, textvariable=self._storage_text,
            command=self._open_storage_context, style='Storage.Metric.TButton',
            takefocus=True,
        )
        self._storage_button.pack(side='right')
        self._storage_icon = create_smooth_icon(
            self._storage_button, 'local', (16, 16), tone='primary',
        )
        if self._storage_icon is not None:
            self._storage_button.configure(image=self._storage_icon, compound='left')
        self._storage_hint = tk.StringVar(self, self._text('storage_scope_hint'))
        attach_inline_tooltip(
            self._storage_button, self._storage_hint,
            self._storage_surface or self.viewer_pane,
        )
        self._storage_heading.bind('<Configure>', self._resize_storage, add='+')
        self._gallery_heading = ttk.Frame(self.viewer_pane, style="Card.TFrame")
        self._gallery_heading.pack(fill="x", padx=8, pady=(4, 6))
        self._gallery_heading.columnconfigure(0, weight=1)
        self._heading_stacked: bool | None = None
        # The progress strip follows the occupied heading rows: one desktop
        # row, two rows with paired controls, or three when groups cannot fit.
        self._heading_preload_row = 1
        self._heading_title = ttk.Frame(self._gallery_heading, style="Card.TFrame")
        self._heading_controls = ttk.Frame(self._gallery_heading, style="Card.TFrame")
        self._heading_title.grid(row=0, column=0, sticky="ew")
        self._heading_controls.grid(row=0, column=2, sticky="e")
        self._cache_tooltip = tk.StringVar(self, self._text('cache_unknown'))
        self._cache_button = icon_button(
            self._heading_controls, '◌', self._open_cache_context,
            self._cache_tooltip,
        )
        # Fit the retention pin and coverage glyph together using Tk's width.
        self._cache_button.configure(width=-1)
        self._cache_button.pack(side='right', padx=(0, 8))
        self._gallery_title = tk.StringVar(self._gallery_heading)
        self._show_subgalleries = tk.BooleanVar(self._gallery_heading, value=True)
        self.refresh_button = icon_button(
            self._heading_controls, "refresh", self._refresh_photos,
            self._text("refresh"),
        )
        self.refresh_button.pack(side="right", padx=(0, 8))
        self.add_child_button = icon_button(
            self._heading_controls,
            "add",
            self._open_selected_creation_context,
            self._text("create_child"),
        )
        self.add_child_button.configure(state="disabled")
        self.add_child_button.pack(side="right", padx=(0, 8))
        self._subgallery_switch = ttk.Checkbutton(
            self._heading_controls, text=self._text("subgalleries"),
            style="Card.TCheckbutton", variable=self._show_subgalleries,
            command=self._sync_subgalleries,
        )
        self._subgallery_switch.pack(side="right")
        self._card_format_controls = ttk.Frame(
            self._gallery_heading, style="Card.TFrame",
        )
        self._card_format_controls.grid(row=0, column=1, sticky="w")
        self._card_format_buttons: dict[str, ttk.Button] = {}
        self._card_format_images: dict[tuple[str, bool], ThemeImage | None] = {}
        self._card_format_hint = tk.StringVar(self, self._text("card_format_hint"))
        for index, key in enumerate(PHOTO_CARD_FORMATS):
            caption = self._text("card_format_default") if key == "default" else key
            for selected in (False, True):
                self._card_format_images[(key, selected)] = create_smooth_icon(
                    self, "aspect_" + key, PHOTOS_CARD_FORMAT_ICON_SIZE,
                    tone="primary" if selected else "muted",
                )
            artwork = self._card_format_images[(key, False)]
            button = ttk.Button(
                self._card_format_controls, text=caption,
                image=artwork if artwork is not None else "", compound="top",
                style="Format.Photos.Icon.TButton", state="disabled",
                takefocus=True, width=-1,
                command=lambda value=key: self._activate_card_format(value),
            )
            button.grid(row=0, column=index, sticky="ew")
            attach_inline_tooltip(button, self._card_format_hint, self.viewer_pane)
            self._card_format_buttons[key] = button
        self._card_format_notice = tk.StringVar(self, "")
        notice = ttk.Label(
            self._card_format_controls, textvariable=self._card_format_notice,
            style="Card.TLabel",
        )
        notice.grid(row=0, column=len(PHOTO_CARD_FORMATS))
        self._card_format_notice_label = notice
        self._card_format_columns = len(PHOTO_CARD_FORMATS)
        attach_inline_tooltip(notice, self._card_format_hint, self.viewer_pane)
        self._recording_heading = ttk.Label(
            self._heading_title, text="", style="Card.TLabel",
        )
        self._recording_heading.pack(side="left", padx=(0, 4))
        self._gallery_title_label = ttk.Label(
            self._heading_title, textvariable=self._gallery_title,
            style="Section.TLabel", justify="left",
        )
        self._gallery_title_label.pack(side="left", fill="x", expand=True)
        self._preload_strip = ttk.Frame(self._gallery_heading, style='Card.TFrame')
        self._preload_strip.columnconfigure(0, weight=1)
        self._preload_text = tk.StringVar(self)
        self._preload_cancel_hint = tk.StringVar(self, self._text('preload_cancel'))
        self._preload_label = ttk.Label(
            self._preload_strip, textvariable=self._preload_text,
            style='Muted.TLabel', wraplength=440,
        )
        self._preload_label.grid(row=0, column=0, sticky='w')
        self._preload_activity = ttk.Label(
            self._preload_strip, style='Card.TLabel', takefocus=False,
        )
        self._preload_activity.grid(row=0, column=1, padx=(4, 0))
        self._preload_cancel = icon_button(
            self._preload_strip, '×', self._cancel_preload,
            self._preload_cancel_hint,
        )
        self._preload_cancel.grid(row=0, column=2, padx=(6, 0))
        self._preload_bar = ttk.Progressbar(
            self._preload_strip, mode='indeterminate',
        )
        self._preload_bar.grid(row=1, column=0, columnspan=3, sticky='ew')
        self._gallery_heading.bind("<Configure>", self._on_heading_resize, add="+")
        self._folder_shelf = GalleryFolderShelf(
            self.viewer_pane, self._open_subgallery, self._gallery_context,
        )
        self.viewer_status = ttk.Label(
            self.viewer_pane,
            text=self._text("select_gallery"),
            style="Muted.TLabel",
            wraplength=520,
        )
        self.viewer_status.pack(fill="x", padx=8, pady=8)
        self.viewer_pane.bind("<Configure>", self._on_viewer_resize, add="+")
        self.panes.grid_remove()

    def _restore_tree_width(self) -> None:
        """Apply the saved Photos pane width after the Panedwindow is mapped.

        Returns:
            None: Applies the latest trusted preference after mapped geometry
                is usable, and leaves the default alone when no preference exists.
        """
        if self._tree_width_restored:
            return
        if not self.panes.winfo_ismapped():
            return
        if self._tree_width_dragging:
            return
        available = self.panes.winfo_width()
        if available <= 1:
            return
        width = self._preferred_tree_width
        if width is not None:
            self.panes.sashpos(0, min(width, available - 1))
        self._tree_width_restored = True

    def _restore_info_width(self) -> None:
        """Restore the preferred right-pane width when its sash is visible.

        Returns:
            None: Keeps the requested width for a later map when space is narrow.
        """
        if (self._destroyed or not self._photo_inspector_visible
                or self._info_width_dragging
                or not self.panes.winfo_ismapped()):
            return
        pane_width = self.panes.winfo_width()
        if (pane_width <= 1
                or str(self.panes.panes()[-1]) != str(self.info_pane)):
            return
        tree_edge = int(self.panes.sashpos(0))
        available = pane_width - tree_edge
        low, high = PHOTOS_INFO_WIDTH_BOUNDS
        if available < low + PHOTOS_INFO_VIEWER_MINIMUM_WIDTH:
            return
        target = min(
            high, max(low, self._preferred_info_width),
            available - PHOTOS_INFO_VIEWER_MINIMUM_WIDTH,
        )
        current_width = pane_width - int(self.panes.sashpos(1))
        if target > 0 and abs(current_width - target) > 2:
            self.panes.sashpos(1, pane_width - target)
        self._info_width_restored = True

    def _schedule_info_width_save(self, event: tk.Event) -> None:
        """Debounce a user drag of only the viewer/inspector sash.

        Args:
            event (tk.Event): Pointer or keyboard release in the Panedwindow.

        Returns:
            None: Schedules persistence of the right pane's actual width.
        """
        if not self._photo_inspector_visible or not self.panes.winfo_ismapped():
            return
        if not self._info_width_dragging:
            return
        pane_width = self.panes.winfo_width()
        width = pane_width - int(self.panes.sashpos(1))
        if width <= 0:
            return
        self._pending_info_width = width
        low, high = PHOTOS_INFO_WIDTH_BOUNDS
        self._preferred_info_width = min(high, max(low, width))
        self._info_width_dragging = False
        self._info_width_restored = True
        if self._info_width_save_after is not None:
            try:
                self.after_cancel(self._info_width_save_after)
            except tk.TclError:
                pass
        self._info_width_save_after = self.after(
            PHOTOS_INFO_WIDTH_SAVE_DELAY_MS, self._persist_info_width,
        )

    def _persist_info_width(self) -> None:
        """Persist the settled right inspector width through the host owner.

        Returns:
            None: Updates the desired width and calls the optional profile writer.
        """
        self._info_width_save_after = None
        if self._destroyed:
            return
        width = getattr(self, '_pending_info_width', None)
        self._pending_info_width = None
        if type(width) is not int or width <= 0:
            return
        low, high = PHOTOS_INFO_WIDTH_BOUNDS
        width = min(high, max(low, width))
        self._preferred_info_width = width
        if self._save_info_width is not None:
            self._save_info_width(width)

    def current_info_width(self) -> int | None:
        """Return the latest desired inspector width, including pending drags.

        Returns:
            int | None: Desired preference independent of transient pane geometry,
                or None before a valid width has been established.
        """
        pending = getattr(self, '_pending_info_width', None)
        if type(pending) is int and pending > 0:
            low, high = PHOTOS_INFO_WIDTH_BOUNDS
            return min(high, max(low, pending))
        preferred = getattr(self, '_preferred_info_width', None)
        if type(preferred) is int and preferred > 0:
            low, high = PHOTOS_INFO_WIDTH_BOUNDS
            return min(high, max(low, preferred))
        return None

    def _on_panes_unmap(self, event: tk.Event) -> None:
        """Retain a pending settled sash value before hiding the pane.

        Args:
            event (tk.Event): Panedwindow mapping change from Tk.

        Returns:
            None: A captured drag is flushed before the next map restores the
                latest preference; transient unmapped sash geometry is ignored.
        """
        if event.widget is not self.panes:
            return
        if self._tree_width_save_after is not None:
            try:
                self.after_cancel(self._tree_width_save_after)
            except tk.TclError:
                pass
            self._tree_width_save_after = None
            self._persist_tree_width()
        if self._info_width_save_after is not None:
            try:
                self.after_cancel(self._info_width_save_after)
            except tk.TclError:
                pass
            self._info_width_save_after = None
            self._persist_info_width()
        self._tree_width_dragging = False
        self._info_width_dragging = False
        self._tree_width_restored = False
        self._info_width_restored = False

    def _begin_tree_width_drag(self, event: tk.Event) -> None:
        """Mark pointer interaction with the Panedwindow sash as user-owned.

        Args:
            event (tk.Event): Pointer press and coordinates from Tk.

        Returns:
            None: A later Map callback cannot replace an active sash drag.
        """
        # Ttk identifies a sash by its index (often "0"), not the word "sash".
        if self._sash_index_at(event.x, event.y) == 0:
            self._tree_width_dragging = True

    def _sash_index_at(self, x: int, y: int) -> int | None:
        """Return the numeric sash index reported at one pointer position.

        Args:
            x (int): Horizontal Panedwindow coordinate.
            y (int): Vertical Panedwindow coordinate.

        Returns:
            int | None: Identified sash index, or None for a non-sash element.
        """
        identified = self.panes.identify(x, y)
        if isinstance(identified, (tuple, list)):
            parts = tuple(str(part) for part in identified)
        else:
            parts = str(identified).replace(',', ' ').split()
        for part in reversed(parts):
            try:
                index = int(part)
            except ValueError:
                continue
            if index >= 0:
                return index
        return None

    def _begin_info_width_drag(self, event: tk.Event) -> None:
        """Mark only the right inspector sash as directly user-controlled.

        Args:
            event (tk.Event): Pointer press and coordinates from Tk.

        Returns:
            None: The inspector preference survives later geometry changes.
        """
        if (self._photo_inspector_visible
                and self._sash_index_at(event.x, event.y) == 1):
            self._info_width_dragging = True

    def _schedule_tree_width_save(self, _event: tk.Event) -> None:
        """Debounce sash release before persisting the user-selected width.

        Args:
            _event (tk.Event): Button or keyboard release on the Panedwindow.

        Returns:
            None: Schedules one callback after the current sash interaction.
        """
        if not self.panes.winfo_ismapped():
            return
        if (_event.type == tk.EventType.ButtonRelease
                and self._sash_index_at(_event.x, _event.y) != 0):
            return
        if not self._tree_width_dragging:
            return
        self._tree_width_dragging = False
        self._tree_width_restored = True
        width = int(self.panes.sashpos(0))
        if width <= 0:
            return
        self._pending_tree_width = width
        if self._tree_width_save_after is not None:
            try:
                self.after_cancel(self._tree_width_save_after)
            except tk.TclError:
                pass
        self._tree_width_save_after = self.after(
            PHOTOS_TREE_WIDTH_SAVE_DELAY_MS, self._persist_tree_width,
        )

    def _persist_tree_width(self) -> None:
        """Save the current positive sash position through its profile owner.

        Returns:
            None: The current Photos tree width is atomically persisted by the
                host callback when one is configured.
        """
        self._tree_width_save_after = None
        if self._destroyed:
            return
        width = self._pending_tree_width
        self._pending_tree_width = None
        if type(width) is not int or width <= 0:
            return
        self._preferred_tree_width = width
        if self._save_tree_width is not None:
            self._save_tree_width(width)

    def current_tree_width(self) -> int | None:
        """Return a mapped Photos sash width only after trusted initialization.

        Returns:
            int | None: Current positive Panedwindow sash position in Tk pixels,
                or None while its mapped user-selected width is not established.
        """
        if (not self._tree_width_restored
                or not self.panes.winfo_ismapped()):
            return None
        position = int(self.panes.sashpos(0))
        return position if position > 0 else None

    def _on_heading_resize(self, event: tk.Event) -> None:
        """Stack controls below the album title when its pane is narrow.

        Args:
            event (tk.Event): Heading width change from Tk geometry management.

        Returns:
            None: Keeps one row on desktop and pairs the control groups below
                the title when they fit, stacking each only when necessary.
                Preload progress remains below every occupied heading row.
        """
        buttons = list(self._card_format_buttons.values())
        columns = len(buttons)
        while columns > 1:
            needed = sum(
                max(button.winfo_reqwidth() for button in buttons[column::columns])
                for column in range(columns)
            ) + self._card_format_notice_label.winfo_reqwidth()
            if needed <= event.width:
                break
            columns -= 1
        if columns != self._card_format_columns:
            self._card_format_columns = columns
            for index, button in enumerate(buttons):
                button.grid_configure(row=index // columns, column=index % columns)
            self._card_format_notice_label.grid_configure(
                row=(len(buttons) - 1) // columns, column=columns,
            )
        groups_width = (
            self._heading_controls.winfo_reqwidth()
            + self._card_format_controls.winfo_reqwidth()
        )
        stacked = (
            event.width < PHOTOS_HEADING_STACK_WIDTH
            or groups_width + PHOTOS_HEADING_TITLE_MINIMUM > event.width
        )
        paired = stacked and groups_width <= event.width
        preload_row = 2 if paired else 3 if stacked else 1
        if (stacked != self._heading_stacked
                or preload_row != self._heading_preload_row):
            self._heading_stacked = stacked
            self._heading_preload_row = preload_row
            self._heading_title.grid_configure(columnspan=3 if stacked else 1)
            self._card_format_controls.grid(
                row=1 if stacked else 0, column=0 if stacked else 1,
                columnspan=2 if paired else 3 if stacked else 1, sticky="w",
            )
            self._heading_controls.grid(
                row=1 if paired else 2 if stacked else 0,
                column=0 if stacked and not paired else 2,
                columnspan=3 if stacked and not paired else 1,
                sticky="w" if stacked and not paired else "e",
                pady=(4, 0) if stacked and not paired else 0,
            )
            if self._preload_strip.winfo_manager() == 'grid':
                self._preload_strip.grid_configure(row=preload_row)
        self._preload_label.configure(wraplength=max(
            1, event.width - self._preload_cancel.winfo_reqwidth()
            - self._preload_activity.winfo_reqwidth() - PHOTOS_PRELOAD_CONTROL_GAP,
        ))
        available = event.width
        if not stacked:
            available -= (self._heading_controls.winfo_reqwidth()
                          + self._card_format_controls.winfo_reqwidth())
        self._gallery_title_label.configure(wraplength=max(1, available - 16))

    def _on_viewer_resize(self, event: tk.Event) -> None:
        """Keep the contextual viewer status within its pane width.

        Args:
            event (tk.Event): Resize event from the right-hand pane.

        Returns:
            None: Status text wraps at the currently available width.
        """
        self.viewer_status.configure(wraplength=max(160, event.width - 24))

    def _photos_changed(self, gallery_id: int) -> None:
        """Invalidate stale cache coverage after a changed photo snapshot.

        Args:
            gallery_id (int): Gallery owned by the current viewer.

        Returns:
            None: Only the corresponding tree row is updated when needed.
        """
        self._invalidate_photo_info(gallery_id)
        if gallery_id == self._selected_gallery_id:
            self._clear_photo_inspector()
        if gallery_id in self._cache_state:
            del self._cache_state[gallery_id]
            self._cache_counts.pop(gallery_id, None)
            self._render_tree([gallery_id])
            self._refresh_cache_widgets(gallery_id)

    def _refresh_photos(self) -> None:
        """Refresh the selected gallery through its current viewer owner.

        Returns:
            None: The viewer preserves the current page, selection and cache.
        """
        if self._selected_gallery_id is not None:
            self._invalidate_photo_info(self._selected_gallery_id)
            self._clear_photo_inspector()
        if self._viewer is not None:
            controller = getattr(self._viewer, '_gallery_viewer_controller', None)
            if controller is not None:
                controller.refresh_photos()

    def _open_selected_creation_context(self) -> None:
        """Open child creation for the currently selected gallery.

        Returns:
            None: Shows the existing create action or its approval guidance.
        """
        gallery_id = self._selected_gallery_id
        if gallery_id is not None:
            self._open_creation_context(self.add_child_button, gallery_id)

    def _context_event(self, widget: tk.Misc) -> tk.Event:
        """Build the pointer coordinates used by the existing inline card.

        Args:
            widget (tk.Misc): Toolbar or context trigger anchoring the card.

        Returns:
            tk.Event: Synthetic event anchored below the trigger widget.
        """
        event = tk.Event()
        event.widget = widget
        event.x_root = widget.winfo_rootx() + widget.winfo_width() // 2
        event.y_root = widget.winfo_rooty() + widget.winfo_height()
        return event

    def _open_creation_context(
        self, widget: tk.Misc, gallery_id: int | None,
    ) -> None:
        """Anchor the existing create action to a visible toolbar button.

        Args:
            widget (tk.Misc): Root or child creation toolbar button.
            gallery_id (int | None): Exact parent gallery, or web root.

        Returns:
            None: Displays the inline creation form or explicit consent route.
        """
        if self._session is None:
            return
        if (gallery_id is not None
                and gallery_id not in self._fresh_catalogue_ids):
            return
        event = self._context_event(widget)
        card = self._open_context(event)
        if self._session.can_create_gallery() and self._manager is not None:
            self._creation_form(card, self._session, gallery_id)
            return
        self._context_content(card, self._text("create_requires_approval"))
        self._add_creation_approval_action(card, self._session, gallery_id)
        self._place_context(card)

    def _add_creation_approval_action(
        self, card: GlassCard, session: LinkingSession,
        parent_id: int | None,
    ) -> None:
        """Offer the explicit consent route for an unavailable create grant.

        Args:
            card (GlassCard): Current inline creation surface.
            session (LinkingSession): Session that lacks the create grant.
            parent_id (int | None): Chosen parent gallery, or web root.

        Returns:
            None: Legacy links open Admin consent; other cases open management.
        """
        label = self._text("create_root" if parent_id is None else "create_child")
        self._context_action(
            card, "+ " + label,
            lambda: self._request_creation_approval(card, session),
        )

    def _add_gallery_creation_action(
        self, card: GlassCard, session: LinkingSession,
        parent_id: int | None,
    ) -> None:
        """Add creation or explicit approval for one chosen location.

        Args:
            card (GlassCard): Current gallery or photo context surface.
            session (LinkingSession): Current linked gallery session.
            parent_id (int | None): Chosen parent gallery, or web root.

        Returns:
            None: The action uses the existing create journal after consent.
        """
        if (parent_id is not None
                and parent_id not in self._fresh_catalogue_ids):
            return
        if self._manager is not None and session.can_create_gallery():
            label = self._text("create_root" if parent_id is None else "create_child")
            self._context_action(
                card, "+ " + label,
                lambda: self._creation_form(card, session, parent_id),
            )
        else:
            self._add_creation_approval_action(card, session, parent_id)

    def _request_creation_approval(
        self, card: GlassCard, session: LinkingSession,
    ) -> None:
        """Start the existing explicit consent or link management flow.

        Args:
            card (GlassCard): Current inline approval surface.
            session (LinkingSession): Session whose scope was checked.

        Returns:
            None: Admin consent starts for legacy links; otherwise management.
        """
        self._dismiss_context()
        if (self._manager is not None
                and self._session is session
                and not session.can_read_admin_media()):
            self._manager.approve_admin_photos()
        elif self._manage_link is not None:
            self._manage_link()

    def refresh_session(self) -> None:
        """Switch to the current live gallery session and reload its tree.

        Returns:
            None: Releases stale reads, decoded media and contextual actions;
                safe failure history resets only when the live session changes.
        """
        if self._destroyed:
            return
        session = self._session_provider()
        if session is self._session:
            return
        self._dismiss_context(restore=False)
        self._cancel_catalogue_continuation()
        with self._photo_diagnostic_lock:
            self._session = session
        if session is not None:
            session.set_upload_pipeline_waiting(self._upload_pipeline_waiting)
        self._card_format_overrides.clear()
        self._card_format_unsaved.clear()
        self._tree_state = (
            self._load_tree_state(session)
            if session is not None and self._load_tree_state is not None else {}
        )
        try:
            preferred_gallery = (
                self._load_selected_gallery(session)
                if session is not None and self._load_selected_gallery is not None
                else None
            )
        except (OSError, TypeError, ValueError):
            preferred_gallery = None
        self._preferred_gallery_id = (
            preferred_gallery if type(preferred_gallery) is int
            and 0 < preferred_gallery <= LINKING_GALLERY_ID_MAX else None
        )
        self._user_selected_gallery = False
        self._selection_saved = False
        self._cache_state.clear()
        self._gallery_pins.clear()
        self._cache_counts.clear()
        self._cache_pending.clear()
        self._photo_info_cache.clear()
        self._clear_photo_inspector()
        if self._preload_worker is not None:
            self._preload_worker.stop()
        self._preload_strip.grid_remove()
        self._preload_bar.stop()
        self._set_preload_activity(False)
        self._create_receipt = None
        self._generation += 1
        self._catalogue_generation += 1
        self._catalogue_cancel.set()
        self._catalogue_cancel = threading.Event()
        self._clear_photo_diagnostics()
        self._cancel.set()
        self._cancel = threading.Event()
        self._clear_catalogue()
        self._catalogue_cache = None
        if session is not None and self._catalogue_cache_factory is not None:
            try:
                self._catalogue_cache = self._catalogue_cache_factory(session)
            except (OSError, TypeError, ValueError):
                self._catalogue_cache = None
        self._thumbnail_memory.clear()
        self._thumbnail_memory = GalleryThumbnailMemory()
        self._pages_this_pass = 0
        self._catalogue_rate_retries = 0
        self.load_more_button.pack_forget()
        self._pending_cursor = None
        self._has_pending_page = False
        if session is None:
            self.panes.grid_remove()
            self.connect_view.grid()
            return
        self.connect_view.grid_remove()
        self.panes.grid()
        self._set_catalogue_loading(True)
        self.viewer_status.configure(text=self._text("loading"))
        self._request_page(None)

    def _clear_catalogue(self) -> None:
        """Clear the previous catalogue, selection and embedded viewer.

        Returns:
            None: Old site data and image references are released immediately.
        """
        self._cancel_catalogue_continuation()
        self._cancel_cached_catalogue_mount()
        self._clear_photo_inspector()
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._galleries.clear()
        self._children_by_parent.clear()
        self._selected_gallery_id = None
        self.add_child_button.configure(state="disabled")
        for button in self._card_format_buttons.values():
            button.configure(state="disabled")
        self._card_format_notice.set("")
        self._gallery_title.set("")
        self._recording_heading.configure(image="")
        self._recording_rows.clear()
        self._sync_subgalleries()
        self._next_after = None
        self._catalogue_cache_snapshot = None
        self._catalogue_cache_ids.clear()
        self._fresh_catalogue_ids.clear()
        self._cached_mount_rows = ()
        self._cached_mount_index = 0
        self._complete_catalogue_tree = None
        self._implicit_pending = False
        self._implicit_attempted.clear()
        self._initial_attempt_count = 0
        self._catalogue_paused_for_preview = False
        self.tree.delete(*self.tree.get_children())
        self.tree_status.configure(text="")
        self._set_catalogue_loading(False)
        self.viewer_status.configure(text=self._text("select_gallery"))
        self.viewer_status.pack(fill="x", padx=8, pady=8)

    def _set_catalogue_loading(self, loading: bool) -> None:
        """Show localized catalogue status and animate the optional spinner.

        Args:
            loading (bool): Whether this session has an unfinished catalogue.

        Returns:
            None: Catalogue status and spinner timer share one loading state.
        """
        if not loading:
            self._cancel_catalogue_spinner()
        if self._destroyed:
            self._catalogue_loading = loading
            return
        if self._catalogue_loading == loading:
            return
        self._catalogue_loading = loading
        self.tree_status.configure(
            text=self._text("loading") if loading else "",
        )
        if loading and self._catalogue_spinner_frames:
            self._catalogue_spinner_label.configure(
                image=self._catalogue_spinner_frames[
                    self._catalogue_spinner_index
                ],
            )
            self._catalogue_spinner_label.grid()
            self._schedule_catalogue_spinner()
        else:
            self._catalogue_spinner_label.grid_remove()

    def _schedule_catalogue_spinner(self) -> None:
        """Schedule one identity-fenced step for the pre-rendered spinner.

        Returns:
            None: At most one active spinner callback is owned by this workspace.
        """
        if (self._destroyed or not self._catalogue_loading
                or not self._catalogue_spinner_frames
                or self._catalogue_spinner_after_id is not None):
            return
        after_id: str | None = None

        def advance() -> None:
            """Advance only the spinner timer that is still current.

            Returns:
                None: Delegates identity and lifecycle checks to the owner.
            """
            self._advance_catalogue_spinner(after_id)

        after_id = self.after(
            PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS, advance,
        )
        self._catalogue_spinner_after_id = after_id

    def _advance_catalogue_spinner(self, after_id: str | None) -> None:
        """Display the next retained frame and schedule one later frame.

        Args:
            after_id (str | None): Identity captured by the current Tk timer.

        Returns:
            None: Stale callbacks do not clear or update replacement animations.
        """
        if (after_id is None
                or after_id != self._catalogue_spinner_after_id):
            return
        self._catalogue_spinner_after_id = None
        if (self._destroyed or not self._catalogue_loading
                or not self._catalogue_spinner_frames):
            return
        self._catalogue_spinner_index = (
            self._catalogue_spinner_index + 1
        ) % len(self._catalogue_spinner_frames)
        self._catalogue_spinner_label.configure(
            image=self._catalogue_spinner_frames[
                self._catalogue_spinner_index
            ],
        )
        self._schedule_catalogue_spinner()

    def _cancel_catalogue_spinner(self) -> None:
        """Cancel the single scheduled catalogue-spinner frame callback.

        Returns:
            None: Clears its identity before best-effort Tk cancellation.
        """
        after_id = self._catalogue_spinner_after_id
        self._catalogue_spinner_after_id = None
        if after_id is None:
            return
        try:
            self.after_cancel(after_id)
        except tk.TclError:
            pass

    def _clear_photo_diagnostics(self) -> None:
        """Forget failures and observations when the live session changes.

        Returns:
            None: No prior-session trace remains in the copied snapshot.
        """
        with self._photo_diagnostic_lock:
            self._photo_diagnostic_latest = None
            self._photo_diagnostic_recent.clear()
            self._photo_observation_recent.clear()

    def _record_photo_observation(
        self, generation: int, summary: str,
    ) -> None:
        """Retain a safe non-failure Photos trace for current-session copying.

        Args:
            generation (int): Viewer/session generation that produced the trace.
            summary (str): Allowlisted shortcut or image-decoder observation.

        Returns:
            None: Observations stay separate from Latest failure and stale
                generations cannot enter the copied support snapshot.
        """
        if not is_safe_photos_observation(summary):
            return
        with self._photo_diagnostic_lock:
            if generation != self._generation:
                return
            self._photo_observation_recent.append(summary)

    def _record_preload_diagnostic(
        self, session: LinkingSession, summary: str,
    ) -> None:
        """Retain one safe preload fact only for its exact live session.

        Args:
            session (LinkingSession): Session captured by the preload worker.
            summary (str): Fixed safe line from the Photos diagnostic formatter.

        Returns:
            None: Same-session failures survive album changes; stale accounts
                and unsafe strings are refused.
        """
        if (type(summary) is not str
                or len(summary) > PHOTOS_DIAGNOSTIC_MAX_CHARS
                or not summary.isprintable()):
            return
        with self._photo_diagnostic_lock:
            if session is not self._session:
                return
            self._photo_diagnostic_latest = summary
            self._photo_diagnostic_recent.append(summary)
        self._notify_diagnostic_callback(summary)

    def _record_photo_diagnostic(
        self, generation: int, summary: str,
    ) -> None:
        """Retain one already-sanitized failure from the current generation.

        Args:
            generation (int): Viewer/session generation that produced the event.
            summary (str): Fixed safe line from the Photos diagnostic formatter.

        Returns:
            None: Current-session failures enter a bounded copyable snapshot.
        """
        if (type(summary) is not str
                or len(summary) > PHOTOS_DIAGNOSTIC_MAX_CHARS
                or not summary.isprintable()):
            return
        with self._photo_diagnostic_lock:
            if generation != self._generation:
                return
            self._photo_diagnostic_latest = summary
            self._photo_diagnostic_recent.append(summary)
        self._notify_diagnostic_callback(summary)

    def _notify_diagnostic_callback(self, summary: str) -> None:
        """Forward one retained safe line to the optional thread-safe host.

        Args:
            summary (str): Already validated current-session diagnostic line.

        Returns:
            None: Callback failures cannot replace or erase the local snapshot.
        """
        callback = getattr(self, '_diagnostic_callback', None)
        if not callable(callback):
            return
        try:
            callback(summary)
        except Exception:
            logging.getLogger(__name__).warning(
                'Photos diagnostic host callback failed.',
            )

    def diagnostics_snapshot(self) -> str:
        """Return safe Photos failures, observations and live render counters.

        Returns:
            str: Latest safe failure, bounded failure history, allowlisted
                observations and numeric counters when a grid is mounted.
                A fixed message identifies a session without recorded failures.
        """
        with self._photo_diagnostic_lock:
            latest = self._photo_diagnostic_latest
            recent = tuple(self._photo_diagnostic_recent)
            observations = tuple(self._photo_observation_recent)
        if latest is None:
            lines = ['No Photos failure recorded for the current session.']
        else:
            lines = [
                'Latest failure: ' + latest,
                'Recent failures:',
                *('  ' + entry for entry in recent),
            ]
        if observations:
            lines.extend((
                'Recent observations:',
                *('  ' + entry for entry in observations),
            ))
        controller = getattr(
            getattr(self, '_viewer', None), '_gallery_viewer_controller', None,
        )
        render_diagnostics = getattr(controller, 'render_diagnostics', None)
        if callable(render_diagnostics):
            try:
                lines.append(render_diagnostics())
            except Exception as error:
                # A diagnostic-only failure must not hide the original cause.
                logging.getLogger(__name__).warning(
                    'Photos render counters unavailable: %s',
                    format_safe_photos_failure('media-apply', 'other', error),
                )
        return '\n'.join(lines)

    def _request_page(self, after_id: int | None) -> None:
        """Queue one server catalogue page on the current session.

        Args:
            after_id (int | None): Exclusive cursor from the prior page.

        Returns:
            None: At most one request occupies the session transport; safe
                catalogue failures remain available in the Photos snapshot.
        """
        if self._session is None or self._destroyed:
            return
        if self._worker is not None:
            self._pending_cursor = after_id
            self._has_pending_page = True
            return
        session = self._session
        generation = self._catalogue_generation
        diagnostic_generation = self._generation
        cancel = self._catalogue_cancel
        catalogue_cache = self._catalogue_cache
        retained_snapshot = self._catalogue_cache_snapshot

        def fetch() -> None:
            """Load optional display cache, then one fresh tree or page.

            Returns:
                None: Every result is generation-fenced by the Tk polling owner.
            """
            cached = retained_snapshot if after_id is None else None
            if after_id is None:
                if cached is None and catalogue_cache is not None:
                    cached = catalogue_cache.load(cancel)
                if not cancel.is_set():
                    self._events.put(_CatalogueCacheResult(generation, cached))
            if cancel.is_set():
                return
            tree_reader = getattr(session, 'gallery_tree', None)
            tree_support = getattr(session, 'supports_gallery_tree', None)
            if (after_id is None and tree_reader is not None
                    and tree_support is not None and tree_support()):
                try:
                    tree = tree_reader(
                        cached.revision if cached is not None else None,
                        cancelled=cancel,
                    )
                except Exception as exc:
                    if (isinstance(exc, LinkingResponseError)
                            and exc.status == 429 and not cancel.is_set()):
                        self._events.put(_CataloguePageResult(
                            generation, None, True,
                            exc.retry_after_seconds, after_id,
                        ))
                        return
                    if (not cancel.is_set()
                            and not isinstance(exc, LinkingMediaThrottled)):
                        _log_safe_media_failure(
                            'page-fetch', 'catalogue', exc,
                            diagnostic_callback=lambda summary: (
                                self._record_photo_diagnostic(
                                    diagnostic_generation, summary,
                                )
                            ),
                        )
                    if not cancel.is_set():
                        self._events.put(
                            _CataloguePageResult(generation, None),
                        )
                    return
                if cancel.is_set():
                    return
                if tree is not None:
                    self._events.put(_CatalogueTreeResult(generation, tree))
                    return
            try:
                page = session.gallery_page(after_id, cancelled=cancel)
            except Exception as exc:
                if (isinstance(exc, LinkingResponseError)
                        and exc.status == 429 and not cancel.is_set()):
                    self._events.put(_CataloguePageResult(
                        generation, None, True,
                        exc.retry_after_seconds, after_id,
                    ))
                    return
                if (not cancel.is_set()
                        and not isinstance(exc, LinkingMediaThrottled)):
                    _log_safe_media_failure(
                        'page-fetch', 'catalogue', exc,
                        diagnostic_callback=lambda summary: (
                            self._record_photo_diagnostic(
                                diagnostic_generation, summary,
                            )
                        ),
                    )
                page = None
            if not cancel.is_set():
                self._events.put(_CataloguePageResult(generation, page))

        self._worker = threading.Thread(
            target=fetch,
            name="photos-catalogue-page",
            daemon=True,
        )
        self._worker.start()
        self._schedule_poll()

    def _schedule_poll(self) -> None:
        """Schedule one nonblocking result poll for active catalogue work.

        Returns:
            None: A single timer services this workspace's worker queue.
        """
        if self._poll_id is None and not self._destroyed:
            self._poll_id = self.after(PHOTOS_CATALOG_POLL_MS, self._poll_events)

    def _poll_events(self) -> None:
        """Apply context and catalogue results on the Tk thread.

        Returns:
            None: Current results apply before pending catalogue work advances.
        """
        self._poll_id = None
        self._update_preload_priority()
        self._poll_cache_work()
        while True:
            try:
                generation, completion = self._context_results.get_nowait()
            except queue.Empty:
                break
            self._context_busy = False
            if ((generation is None or generation == self._generation)
                    and self._session_provider() is self._session):
                completion()
        self._apply_photo_info_results()
        while True:
            try:
                result = self._events.get_nowait()
            except queue.Empty:
                break
            if (result.generation == self._catalogue_generation
                    and self._session is not None
                    and self._session_provider() is self._session
                    and not self._catalogue_cancel.is_set()):
                if isinstance(result, _CatalogueCacheResult):
                    self._accept_cached_catalogue(result.snapshot)
                elif isinstance(result, _CatalogueTreeResult):
                    self._accept_complete_catalogue(result.tree)
                else:
                    self._accept_page(
                        result.page, throttled=result.throttled,
                        retry_after_seconds=result.retry_after_seconds,
                        cursor=result.cursor,
                    )
        if self._worker is not None and not self._worker.is_alive():
            if not self._events.empty():
                self._schedule_poll()
                return
            self._worker = None
        if self._worker is None and self._has_pending_page:
            cursor = self._pending_cursor
            self._pending_cursor = None
            self._has_pending_page = False
            self._request_page(cursor)
        self._update_preload_priority()
        if (self._worker is not None or self._has_pending_page or self._context_busy
                or self._cache_worker is not None or self._cache_pending
                or self._inspector_worker is not None
                or self._inspector_pending is not None
                or (self._preload_worker is not None
                    and self._preload_worker.is_alive())):
            self._schedule_poll()

    def _accept_cached_catalogue(
        self, snapshot: GalleryCatalogueSnapshot | None,
    ) -> None:
        """Queue bounded display of the last-known tree without authority.

        Args:
            snapshot (GalleryCatalogueSnapshot | None): Integrity-checked
                account-and-scope-bound cached metadata, or a cache miss.

        Returns:
            None: Cached rows remain display-only until fresh reads confirm them.
        """
        if snapshot is None or self._destroyed:
            return
        self._catalogue_cache_snapshot = snapshot
        self._catalogue_cache_ids = {
            gallery.gallery_id for gallery in snapshot.galleries
        }
        self._cached_mount_rows = snapshot.galleries
        self._cached_mount_index = 0
        self._mount_cached_catalogue_batch()

    def _mount_cached_catalogue_batch(self) -> None:
        """Mount one bounded batch of cached or freshly confirmed tree rows.

        Returns:
            None: Fresh rows win over cache and remaining rows yield to Tk.
        """
        self._cached_mount_after = None
        if self._complete_catalogue_tree is not None:
            self._mount_complete_catalogue_batch()
            return
        if (self._destroyed or self._catalogue_cache_snapshot is None
                or self._cached_mount_index >= len(self._cached_mount_rows)):
            return
        stop = min(
            len(self._cached_mount_rows),
            self._cached_mount_index + PHOTOS_CATALOGUE_CACHE_BATCH_SIZE,
        )
        changed: list[int] = []
        while self._cached_mount_index < stop:
            gallery = self._cached_mount_rows[self._cached_mount_index]
            self._cached_mount_index += 1
            gallery_id = gallery.gallery_id
            if gallery_id in self._fresh_catalogue_ids:
                continue
            self._galleries[gallery_id] = gallery
            changed.append(gallery_id)
            if gallery.parent_id is not None:
                self._children_by_parent.setdefault(
                    gallery.parent_id, set(),
                ).add(gallery_id)
        if changed:
            self._render_tree(changed)
            for gallery_id in changed:
                self._cache_pending.setdefault(gallery_id, (None, False))
            self._start_cache_probe()
            self._sync_subgalleries()
            preferred_id = self._preferred_gallery_id
            if (self._selected_gallery_id is None
                    and not self._user_selected_gallery
                    and preferred_id in changed):
                preferred = self._galleries.get(preferred_id)
                if preferred is not None:
                    self._select_gallery(preferred, restore=True)
        if self._cached_mount_index < len(self._cached_mount_rows):
            self._schedule_cached_catalogue_batch()

    def _schedule_cached_catalogue_batch(self) -> None:
        """Schedule the next cached or complete row slice on one owned timer.

        Returns:
            None: A single delayed callback advances the cached display.
        """
        if self._destroyed or self._cached_mount_after is not None:
            return
        after_id: str | None = None
        generation = self._catalogue_generation
        session = self._session
        cancel = self._catalogue_cancel

        def mount() -> None:
            """Advance only the cached batch callback still owned here.

            Returns:
                None: Replaced or cancelled callbacks cannot advance the tree.
            """
            if (after_id is None or self._cached_mount_after != after_id
                    or self._catalogue_generation != generation
                    or self._session is not session
                    or self._session_provider() is not session
                    or self._catalogue_cancel is not cancel or cancel.is_set()):
                return
            self._mount_cached_catalogue_batch()

        after_id = self.after(
            PHOTOS_CATALOGUE_CACHE_BATCH_DELAY_MS, mount,
        )
        self._cached_mount_after = after_id

    def _cancel_cached_catalogue_mount(self) -> None:
        """Cancel a pending cached-row batch and release its remaining rows.

        Returns:
            None: Late callbacks fail their identity check after cancellation.
        """
        after_id = self._cached_mount_after
        self._cached_mount_after = None
        if after_id is None:
            return
        try:
            self.after_cancel(after_id)
        except tk.TclError:
            pass

    def _accept_complete_catalogue(self, tree: LinkedGalleryTree) -> None:
        """Stage a fresh full tree for bounded, difference-only UI updates.

        Args:
            tree (LinkedGalleryTree): Validated complete tree-v1 response.

        Returns:
            None: Cached display survives until the complete tree is mounted.
        """
        cached = self._catalogue_cache_snapshot
        if tree.unchanged:
            if (cached is None or cached.revision != tree.revision):
                self._request_page(None)
                return
            galleries = cached.galleries
        else:
            galleries = tree.galleries
        self._cancel_cached_catalogue_mount()
        self.retry_button.pack_forget()
        self._catalogue_cache_ids.update(self._galleries)
        self._fresh_catalogue_ids.clear()
        self._complete_catalogue_tree = tree
        self._cached_mount_rows = galleries
        self._cached_mount_index = 0
        # The response is already complete even while Tk mounts its rows in
        # slices. Save it now so quitting during display retains the full tree.
        self._catalogue_cache_snapshot = GalleryCatalogueSnapshot(
            galleries, tree.revision,
        )
        if not tree.unchanged:
            self._queue_catalogue_snapshot_save(
                self._catalogue_cache_snapshot,
            )
        self._set_catalogue_loading(True)
        self._mount_complete_catalogue_batch()

    def _mount_complete_catalogue_batch(self) -> None:
        """Confirm a bounded full-tree slice and render only changed rows.

        Returns:
            None: Unchanged mounted rows keep their widgets, expansion and focus.
        """
        tree = self._complete_catalogue_tree
        if tree is None or self._destroyed or self._catalogue_cancel.is_set():
            return
        stop = min(
            len(self._cached_mount_rows),
            self._cached_mount_index + PHOTOS_CATALOGUE_CACHE_BATCH_SIZE,
        )
        changed: list[int] = []
        confirmed: list[int] = []
        while self._cached_mount_index < stop:
            gallery = self._cached_mount_rows[self._cached_mount_index]
            self._cached_mount_index += 1
            gallery_id = gallery.gallery_id
            prior = self._galleries.get(gallery_id)
            if (prior is not None and prior.parent_id is not None
                    and prior.parent_id != gallery.parent_id):
                children = self._children_by_parent.get(prior.parent_id)
                if children is not None:
                    children.discard(gallery_id)
            self._galleries[gallery_id] = gallery
            self._fresh_catalogue_ids.add(gallery_id)
            confirmed.append(gallery_id)
            if gallery.parent_id is not None:
                self._children_by_parent.setdefault(
                    gallery.parent_id, set(),
                ).add(gallery_id)
            if prior != gallery or not self.tree.exists(str(gallery_id)):
                changed.append(gallery_id)
        self._persist_selected_gallery_if_fresh(confirmed)
        if changed:
            self._render_tree(changed)
            for gallery_id in changed:
                self._cache_pending.setdefault(gallery_id, (None, False))
            self._start_cache_probe()
            self._sync_subgalleries()
        if self._selected_gallery_id in self._fresh_catalogue_ids:
            self.add_child_button.configure(state="normal")
        if self._cached_mount_index < len(self._cached_mount_rows):
            self._restore_or_choose_gallery(complete=False)
            self._schedule_cached_catalogue_batch()
            return
        self._complete_catalogue_tree = None
        self._prune_cached_catalogue_rows()
        self._discard_unconfirmed_selection()
        self._pages_this_pass = 1
        self._next_after = None
        self._has_pending_page = False
        self._pending_cursor = None
        self._set_catalogue_loading(False)
        if not self._cached_mount_rows:
            self.tree_status.configure(text=self._text("empty"))
            self.viewer_status.configure(text=self._text("empty"))
        else:
            self.tree_status.configure(text="")
        self._restore_or_choose_gallery(complete=True)

    def _persist_selected_gallery_if_fresh(
        self, fresh_ids: tuple[int, ...] | list[int],
    ) -> None:
        """Save a user choice only after its ID appears in a fresh response.

        Args:
            fresh_ids (tuple[int, ...] | list[int]): IDs confirmed by the
                current full tree or one validated current page.

        Returns:
            None: Cached-only selections never become persisted preferences.
        """
        selected_id = self._selected_gallery_id
        session = self._session
        if (not self._user_selected_gallery or self._selection_saved
                or selected_id is None or selected_id not in fresh_ids
                or selected_id not in self._fresh_catalogue_ids
                or session is None or self._save_selected_gallery is None):
            return
        try:
            self._save_selected_gallery(session, selected_id)
        except (OSError, TypeError, ValueError):
            logging.debug("Photos selection preference could not be saved.")
            return
        self._selection_saved = True

    def _restore_or_choose_gallery(self, *, complete: bool) -> None:
        """Restore a fresh saved row or choose the established default.

        Args:
            complete (bool): Whether all current server rows are known.

        Returns:
            None: User selection wins; absent saved IDs fall back only at finish.
        """
        if self._user_selected_gallery or self._selected_gallery_id is not None:
            return
        preferred_id = self._preferred_gallery_id
        if (preferred_id is not None
                and preferred_id in self._fresh_catalogue_ids):
            preferred = self._galleries.get(preferred_id)
            if preferred is not None:
                self._select_gallery(preferred, restore=True)
                return
        if preferred_id is not None and complete:
            self._preferred_gallery_id = None
        if self._preferred_gallery_id is None and self._fresh_catalogue_ids:
            self._implicit_pending = True
            self._ensure_initial_gallery()

    def _prune_cached_catalogue_rows(self) -> None:
        """Remove cached-only IDs after a successful complete page pass.

        Returns:
            None: Failed or partial page sequences retain the prior complete tree.
        """
        stale_ids = self._catalogue_cache_ids - self._fresh_catalogue_ids
        for fresh_id in self._fresh_catalogue_ids:
            item_id = str(fresh_id)
            if not self.tree.exists(item_id):
                continue
            parent_id = self.tree.parent(item_id)
            if parent_id and int(parent_id) in stale_ids:
                self.tree.move(
                    item_id, "", self._ordered_index("", fresh_id),
                )
        for stale_id in stale_ids:
            item_id = str(stale_id)
            if self.tree.exists(item_id):
                self.tree.delete(item_id)
            self._galleries.pop(stale_id, None)
        if stale_ids:
            self._children_by_parent = {}
            for gallery in self._galleries.values():
                if gallery.parent_id is not None:
                    self._children_by_parent.setdefault(
                        gallery.parent_id, set(),
                    ).add(gallery.gallery_id)
            self._sync_subgalleries()

    def _discard_unconfirmed_selection(self) -> None:
        """Release a cached-only selection after a complete fresh catalogue.

        Returns:
            None: A stale viewer cannot outlive confirmation of its gallery ID.
        """
        selected_id = self._selected_gallery_id
        if (selected_id is None or selected_id in self._fresh_catalogue_ids):
            return
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._clear_photo_inspector()
        self._selected_gallery_id = None
        self._user_selected_gallery = False
        self._selection_saved = False
        self.add_child_button.configure(state="disabled")
        self._gallery_title.set("")
        self.viewer_status.configure(text=self._text("select_gallery"))
        self.viewer_status.pack(fill="x", padx=8, pady=8)
        self.tree.selection_remove(*self.tree.selection())

    def _queue_catalogue_snapshot_save(
        self, snapshot: GalleryCatalogueSnapshot,
    ) -> None:
        """Serialize the latest known structure through one worker slot.

        Args:
            snapshot (GalleryCatalogueSnapshot): Last-known display tree;
                only a complete tree-v1 result carries a server revision.

        Returns:
            None: At most one writer runs and one newest snapshot waits behind
                it. Already accepted local metadata finishes saving on exit.
        """
        cache = self._catalogue_cache
        session = self._session
        if (cache is None or session is None or self._destroyed
                or self._cancel.is_set()):
            return
        self._catalogue_cache_writer.submit(cache, snapshot)

    def _remember_catalogue_progress(self) -> None:
        """Persist confirmed additions without dropping previously known rows.

        Returns:
            None: Every successful page or creation updates the atomic cache.
                Unmounted cached rows survive; partial data has no tree revision
                and cannot authorize actions or prove remote removal.
        """
        snapshot = self._catalogue_cache_snapshot
        known = {
            gallery.gallery_id: gallery
            for gallery in snapshot.galleries
        } if snapshot is not None else {}
        known.update(
            (gallery_id, gallery)
            for gallery_id, gallery in self._galleries.items()
            # During full-tree mounting, visible old rows may already be absent
            # from the confirmed response. Only fresh rows can extend/replace
            # that snapshot; unmounted new descriptors must keep their titles.
            if gallery_id in self._fresh_catalogue_ids
        )
        self._catalogue_cache_snapshot = GalleryCatalogueSnapshot(tuple(
            known[gallery_id] for gallery_id in sorted(known)
        ))
        self._queue_catalogue_snapshot_save(self._catalogue_cache_snapshot)

    def _accept_page(
        self, page: LinkedGalleryPage | None, *,
        throttled: bool = False, retry_after_seconds: int | None = None,
        cursor: int | None = None,
    ) -> None:
        """Reconcile and persist one page before requesting its continuation.

        Args:
            page (LinkedGalleryPage | None): Validated response or failure marker.
            throttled (bool): Actual catalogue HTTP 429 response marker.
            retry_after_seconds (int | None): Validated server backoff seconds.
            cursor (int | None): Original failing request cursor, if any.

        Returns:
            None: Confirmed metadata updates the known tree immediately; only
                the last successful page removes absent cached rows.
        """
        if page is None:
            if (throttled and self._schedule_catalogue_throttled_page(
                    cursor, retry_after_seconds)):
                return
            self._cancel_catalogue_continuation()
            self._set_catalogue_loading(False)
            self.tree_status.configure(text=self._text("error"))
            self.viewer_status.configure(text=self._text("error"))
            self.retry_button.pack(side="right")
            return
        self.retry_button.pack_forget()
        self._catalogue_rate_retries = 0
        page_gallery_ids: list[int] = []
        changed_ids: list[int] = []
        for gallery in page.galleries:
            prior = self._galleries.get(gallery.gallery_id)
            if (prior is not None and prior.parent_id is not None
                    and prior.parent_id != gallery.parent_id):
                prior_children = self._children_by_parent.get(prior.parent_id)
                if prior_children is not None:
                    prior_children.discard(gallery.gallery_id)
            self._galleries[gallery.gallery_id] = gallery
            page_gallery_ids.append(gallery.gallery_id)
            if (prior != gallery
                    or not self.tree.exists(str(gallery.gallery_id))):
                changed_ids.append(gallery.gallery_id)
            self._fresh_catalogue_ids.add(gallery.gallery_id)
            if gallery.parent_id is not None:
                self._children_by_parent.setdefault(
                    gallery.parent_id, set(),
                ).add(gallery.gallery_id)
        self._persist_selected_gallery_if_fresh(page_gallery_ids)
        if changed_ids:
            self._render_tree(changed_ids)
        if self._selected_gallery_id in self._fresh_catalogue_ids:
            self.add_child_button.configure(state="normal")
        for gallery_id in changed_ids:
            self._cache_pending.setdefault(gallery_id, (None, False))
        self._start_cache_probe()
        self._sync_subgalleries()
        self._pages_this_pass += 1
        self._next_after = page.next_after
        if page.next_after is not None:
            self._remember_catalogue_progress()
            self._restore_or_choose_gallery(complete=False)
            self._set_catalogue_loading(True)
            if not self._catalogue_paused_for_preview:
                self._continue_catalogue()
        else:
            self._cancel_cached_catalogue_mount()
            self._prune_cached_catalogue_rows()
            self._discard_unconfirmed_selection()
            self._restore_or_choose_gallery(complete=True)
            self._set_catalogue_loading(False)
            if not self._galleries:
                self.tree_status.configure(text=self._text("empty"))
                self.viewer_status.configure(text=self._text("empty"))
            else:
                self.tree_status.configure(text="")
            complete_snapshot = GalleryCatalogueSnapshot(
                tuple(sorted(
                    self._galleries.values(),
                    key=lambda gallery: gallery.gallery_id,
                )),
            )
            self._catalogue_cache_snapshot = complete_snapshot
            self._queue_catalogue_snapshot_save(complete_snapshot)

    def _first_gallery(self) -> LinkedGallery | None:
        """Choose an untried leaf gallery, falling back to an orphan row.

        Returns:
            LinkedGallery | None: Lowest-ID leaf, then any untried row.
        """
        ordered = sorted(
            (gallery for gallery_id, gallery in self._galleries.items()
             if gallery_id in self._fresh_catalogue_ids),
            key=lambda item: item.gallery_id,
        )
        parents = {gallery.parent_id for gallery in ordered}
        for gallery in ordered:
            if (gallery.gallery_id not in parents
                    and gallery.gallery_id not in self._implicit_attempted):
                return gallery
        for gallery in ordered:
            if gallery.gallery_id not in self._implicit_attempted:
                return gallery
        return None

    def _ensure_initial_gallery(self) -> None:
        """Mount the next leaf candidate for the implicit first preview.

        Returns:
            None: Only a few candidates are probed before leaving the tree usable.
        """
        if (not self._implicit_pending or self._selected_gallery_id is not None
                or self._initial_attempt_count >= PHOTOS_INITIAL_CANDIDATE_LIMIT):
            return
        gallery = self._first_gallery()
        if gallery is None:
            return
        self._catalogue_paused_for_preview = True
        self._initial_attempt_count += 1
        self._implicit_attempted.add(gallery.gallery_id)
        self._select_gallery(gallery, implicit=True)

    def _on_implicit_preview(
        self, gallery_id: int, has_photos: bool | None,
    ) -> None:
        """Advance the implicit choice if its first preview has no photos.

        Args:
            gallery_id (int): Gallery identity associated with the callback.
            has_photos (bool | None): Whether photos exist; None means a
                temporary failure that should preserve this selection.

        Returns:
            None: Explicit user choices are never replaced by this fallback.
        """
        if (not self._implicit_pending
                or self._selected_gallery_id != gallery_id):
            return
        if has_photos is True:
            self._implicit_pending = False
        elif has_photos is None:
            self._implicit_pending = False
        else:
            self._selected_gallery_id = None
            self._ensure_initial_gallery()
            if self._selected_gallery_id is None:
                self._implicit_pending = False
                self._selected_gallery_id = gallery_id
        if not self._implicit_pending and self._next_after is not None:
            self._catalogue_paused_for_preview = False
            self._continue_catalogue()

    def _render_tree(self, page_gallery_ids: list[int]) -> None:
        """Incrementally add a page and attach newly resolvable orphans.

        Args:
            page_gallery_ids (list[int]): IDs introduced or refreshed by the
                current validated catalogue page.

        Returns:
            None: Existing item state remains intact as later pages arrive.
        """
        self._rendering_tree = True
        candidates = set(page_gallery_ids)
        for gallery_id in page_gallery_ids:
            gallery = self._galleries[gallery_id]
            item_id = str(gallery_id)
            title = gallery.title or self._text("untitled")
            marked = item_id in self._recording_rows
            target = self._watcher_target
            hollow = marked and target is not None and target.gallery_id != gallery_id
            marker = self._tree_marker(gallery_id, marked, hollow)
            label = title if marker is not None else self._cache_glyph(gallery_id) + "  " + title
            image = marker
            if image is None and marked:
                image = self._recording_image(hollow)
            if self.tree.exists(item_id):
                self.tree.item(
                    item_id,
                    text=label,
                    image=image or "",
                )
            else:
                self.tree.insert(
                    "",
                    self._ordered_index("", gallery_id),
                    iid=str(gallery_id),
                    text=label,
                    image=image or "",
                    open=self._tree_state.get(gallery_id, self._expand_new_nodes),
                )
            candidates.update(self._children_by_parent.get(gallery_id, ()))
        for gallery_id in sorted(candidates):
            self._attach_gallery(gallery_id)
        if self._selected_gallery_id is not None:
            selected = str(self._selected_gallery_id)
            if self.tree.exists(selected):
                self.tree.selection_set(selected)
        self._rendering_tree = False
        self._update_recording_rows()

    def _ordered_index(self, parent_id: str, gallery_id: int) -> int:
        """Find the insertion index that orders siblings by gallery ID.

        Args:
            parent_id (str): Tree item ID, or empty string for the root.
            gallery_id (int): ID of the row being inserted or moved.

        Returns:
            int: Count of existing siblings with a smaller ID.
        """
        index = 0
        for sibling in self.tree.get_children(parent_id):
            if int(sibling) > gallery_id:
                break
            index += 1
        return index

    def _attach_gallery(self, gallery_id: int) -> None:
        """Place a known row under its available, cycle-safe parent.

        Args:
            gallery_id (int): Current gallery row to attach or keep at root.

        Returns:
            None: Missing parents and malformed cycles remain reachable.
                Moving the selected gallery or its ancestor reveals the new
                path so the active row stays visible after reconciliation.
        """
        gallery = self._galleries[gallery_id]
        item_id = str(gallery_id)
        parent_id = gallery.parent_id
        parent_item = (
            str(parent_id)
            if (parent_id is not None and parent_id in self._galleries
                and self.tree.exists(str(parent_id)))
            else ""
        )
        ancestor = parent_item
        while ancestor:
            if ancestor == item_id:
                parent_item = ""
                break
            ancestor = self.tree.parent(ancestor)
        if self.tree.parent(item_id) == parent_item:
            return
        self.tree.move(
            item_id,
            parent_item,
            self._ordered_index(parent_item, gallery_id),
        )
        selected = self._selected_gallery_id
        if selected is not None and self.tree.exists(str(selected)):
            ancestor = str(selected)
            while ancestor:
                if ancestor == item_id:
                    self.tree.see(str(selected))
                    break
                ancestor = self.tree.parent(ancestor)

    def _continue_catalogue(self) -> None:
        """Continue immediately or schedule one paced post-cap page.

        Returns:
            None: One bounded timer continues the current exact session/cursor.
        """
        if (self._destroyed or self._session is None
                or self._next_after is None
                or self._catalogue_paused_for_preview):
            return
        self.load_more_button.pack_forget()
        if self._pages_this_pass >= PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT:
            if self._catalogue_after is not None:
                return
            self._set_catalogue_loading(True)
            session = self._session
            generation = self._catalogue_generation
            cancel = self._catalogue_cancel
            cursor = self._next_after
            after_id: str | None = None

            def continue_page() -> None:
                """Request the captured next page after validating its owner.

                Returns:
                    None: Stale session, cursor, preview or teardown cancels it.
                """
                if self._catalogue_after != after_id:
                    return
                self._catalogue_after = None
                if (self._destroyed or self._catalogue_generation != generation
                        or self._session is not session
                        or self._session_provider() is not session
                        or self._catalogue_cancel is not cancel or cancel.is_set()
                        or self._next_after != cursor
                        or self._catalogue_paused_for_preview):
                    return
                self._request_page(cursor)

            after_id = self.after(
                PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS, continue_page,
            )
            self._catalogue_after = after_id
            return
        self._request_page(self._next_after)

    def _schedule_catalogue_throttled_page(
        self, cursor: int | None, retry_after_seconds: int | None,
    ) -> bool:
        """Resume the captured page only after the server's rate budget resets.

        Args:
            cursor (int | None): Exclusive cursor of the refused gallery page.
            retry_after_seconds (int | None): Safe server-supplied retry delay.

        Returns:
            bool: True if one generation-fenced timer was created; otherwise
                retain the manual error/retry control without a retry storm.
        """
        if (self._destroyed or self._session is None
                or self._catalogue_rate_retries
                >= PHOTOS_CATALOGUE_RATE_MAX_RETRIES):
            return False
        delay = (retry_after_seconds
                 if type(retry_after_seconds) is int
                 else PHOTOS_CATALOGUE_RATE_RETRY_DEFAULT_SECONDS)
        if not 1 <= delay <= PHOTOS_CATALOGUE_RATE_RETRY_DEFAULT_SECONDS:
            # Never retry before an unreasonably large server instruction.
            return False
        self._cancel_catalogue_continuation()
        session = self._session
        generation = self._catalogue_generation
        cancel = self._catalogue_cancel
        self._catalogue_rate_retries += 1
        after_id: str | None = None

        def retry_page() -> None:
            """Dispatch only when the captured session, timer and page persist.

            Returns:
                None: Stale or cancelled timers have no network side effects.
            """
            if self._catalogue_after != after_id:
                return
            self._catalogue_after = None
            if (self._destroyed or self._catalogue_generation != generation
                    or self._session is not session
                    or self._session_provider() is not session
                    or self._catalogue_cancel is not cancel or cancel.is_set()
                    or self._next_after != cursor
                    or self._catalogue_paused_for_preview):
                return
            self._request_page(cursor)

        after_id = self.after(delay * 1000, retry_page)
        self._catalogue_after = after_id
        self._set_catalogue_loading(True)
        return True

    def _cancel_catalogue_continuation(self) -> None:
        """Cancel the one pending post-cap page timer, if present.

        Returns:
            None: Repeated cancellation is harmless and stale callbacks fail
                their session/cursor checks.
        """
        after_id = self._catalogue_after
        self._catalogue_after = None
        if after_id is None:
            return
        try:
            self.after_cancel(after_id)
        except tk.TclError:
            pass

    def _load_more_catalogue(self) -> None:
        """Resume the paced continuation from the current server cursor.

        Returns:
            None: Existing burst limits and request cadence remain unchanged.
        """
        self._continue_catalogue()

    def _all_items(self) -> list[str]:
        """Return every current tree item in parent-before-child order.

        Returns:
            list[str]: Tree item identifiers for the current in-memory catalog.
        """
        result: list[str] = []
        pending = list(self.tree.get_children())
        while pending:
            item = pending.pop()
            result.append(item)
            pending.extend(self.tree.get_children(item))
        return result

    def _set_all_open(self, opened: bool) -> None:
        """Expand or collapse all currently loaded gallery branches.

        Args:
            opened (bool): True to expand every loaded node, false to collapse.

        Returns:
            None: Tree expansion is purely local and makes no server request.
        """
        self._expand_new_nodes = opened
        for item in self._all_items():
            self.tree.item(item, open=opened)
        self._save_expansion_state()
        self._update_recording_rows()

    def set_watcher_recording(
        self, target: LinkedUploadTarget | None, pulse: bool,
    ) -> None:
        """Display the active Watcher target in this exact linked account.

        Args:
            target (LinkedUploadTarget | None): Active linked target, or None.
            pulse (bool): Whether the current animation phase is bright.

        Returns:
            None: Only previously and currently marked tree rows are updated.
        """
        self._watcher_target = target
        self._watcher_pulse = pulse
        self._update_recording_rows()

    def _recording_image(self, hollow: bool) -> ThemeImage:
        """Return a small red indicator for the current pulse phase.

        Args:
            hollow (bool): Draw an outline for an obscured descendant.

        Returns:
            ThemeImage: Retained Pillow/Tk image for this phase and marker shape.
        """
        key = (hollow, self._watcher_pulse)
        image = self._recording_images.get(key)
        if image is None:
            image = create_smooth_icon(
                self, "recording",
                (PHOTOS_RECORDING_DOT_SIZE, PHOTOS_RECORDING_DOT_SIZE),
                hollow=hollow, pulse=self._watcher_pulse,
            )
            if image is None:
                image = tk.PhotoImage(
                    master=self, width=PHOTOS_RECORDING_DOT_SIZE,
                    height=PHOTOS_RECORDING_DOT_SIZE,
                )
                color = _PALETTE[
                    'recording' if self._watcher_pulse else 'recording_dim'
                ]
                center = PHOTOS_RECORDING_DOT_SIZE // 2
                for y in range(PHOTOS_RECORDING_DOT_SIZE):
                    for x in range(PHOTOS_RECORDING_DOT_SIZE):
                        distance = (x - center) ** 2 + (y - center) ** 2
                        if distance <= center ** 2 and (
                            not hollow or distance >= 7
                        ):
                            image.put(color, (x, y))
            self._recording_images[key] = image
        return image

    def _tree_marker(
        self, gallery_id: int, recording: bool, hollow: bool,
    ) -> ThemeImage | None:
        """Return the composed cache, pin and Watcher marker for one row.

        Args:
            gallery_id (int): Gallery represented by the tree row.
            recording (bool): Whether this exact row is recording.
            hollow (bool): Whether the row hides a recording descendant.

        Returns:
            ThemeImage | None: Retained combined image, or None without Pillow.
        """
        status = self._cache_state.get(gallery_id, "unknown")
        pinned = self._gallery_pins.get(gallery_id) is True
        key = (status, pinned, recording, hollow, self._watcher_pulse)
        image = self._tree_marker_images.get(key)
        if image is None:
            image = create_smooth_icon(
                self, status,
                (PHOTOS_TREE_MARKER_SIZE, PHOTOS_TREE_MARKER_SIZE),
                pinned=pinned,
                recording=recording, hollow=hollow, pulse=self._watcher_pulse,
            )
            if image is not None:
                self._tree_marker_images[key] = image
        return image

    def _on_tree_expansion(self, _event: tk.Event) -> None:
        """Update the hidden-target indicator after Tk changes expansion.

        Args:
            _event (tk.Event): Tree open or close notification.

        Returns:
            None: Deferred work observes Tk's completed expansion change.
        """
        self.after_idle(self._save_expansion_state)
        self.after_idle(self._update_recording_rows)

    def _save_expansion_state(self) -> None:
        """Persist loaded rows while retaining states for later catalogue pages.

        Returns:
            None: The host saves the merged account-scoped preferences.
        """
        session = self._session
        if self._destroyed or session is None or self._save_tree_state is None:
            return
        for item in self._all_items():
            self._tree_state[int(item)] = bool(self.tree.item(item, "open"))
        self._save_tree_state(session, dict(self._tree_state))

    def _update_recording_rows(self) -> None:
        """Update markers for the exact target and collapsed hiding ancestors.

        Returns:
            None: Unrelated tree rows and selection remain untouched.
        """
        if self._destroyed:
            return
        target = self._watcher_target
        manager = self._manager
        matching = (
            target is not None and self._session is not None
            and manager is not None
            and manager.photos_site_identity(self._session)
                == (target.issuer, target.user_id)
        )
        markers: dict[str, bool] = {}
        if matching and target is not None:
            item = str(target.gallery_id)
            if self.tree.exists(item):
                markers[item] = False
                parent = self.tree.parent(item)
                while parent:
                    if not self.tree.item(parent, 'open'):
                        markers[parent] = True
                    parent = self.tree.parent(parent)
        for item in self._recording_rows | set(markers):
            if self.tree.exists(item):
                gallery_id = int(item)
                is_marked = item in markers
                image = self._tree_marker(
                    gallery_id, is_marked, markers.get(item, False),
                )
                title = self._galleries.get(gallery_id)
                text = (
                    (title.title or self._text("untitled"))
                    if title is not None else str(self.tree.item(item, "text"))
                )
                if image is None:
                    text = self._cache_glyph(gallery_id) + "  " + text
                    if is_marked:
                        image = self._recording_image(markers[item])
                self.tree.item(item, image=image or "", text=text)
        self._recording_rows = set(markers)
        selected_target = matching and target is not None and (
            self._selected_gallery_id == target.gallery_id
        )
        self._recording_heading.configure(
            image=self._recording_image(False) if selected_target else '',
        )

    def _refresh_catalogue(self) -> None:
        """Restart server reconciliation while retaining the known visible tree.

        Returns:
            None: Selection, expansion and the photo viewer survive. Obsolete
                catalogue responses are cancelled and fenced independently of
                media and context work; fresh reads update names and parents.
        """
        if (self._destroyed or self._session is None
                or self._session_provider() is not self._session):
            return
        self._dismiss_context(restore=False)
        self._cancel_catalogue_continuation()
        self._cancel_cached_catalogue_mount()
        self._catalogue_cancel.set()
        self._catalogue_cancel = threading.Event()
        self._catalogue_generation += 1
        self._catalogue_cache_ids.update(self._galleries)
        self._fresh_catalogue_ids.clear()
        self._complete_catalogue_tree = None
        self._pages_this_pass = 0
        self._next_after = None
        self._pending_cursor = None
        self._has_pending_page = False
        self._catalogue_paused_for_preview = False
        self._implicit_pending = False
        self.add_child_button.configure(state="disabled")
        self.retry_button.pack_forget()
        self._set_catalogue_loading(True)
        self._request_page(None)

    def _retry_catalogue(self) -> None:
        """Retry the current failed page through its last cursor.

        Returns:
            None: The existing session and page boundary remain authoritative.
        """
        if self._session is None:
            return
        self._cancel_catalogue_continuation()
        self.retry_button.pack_forget()
        self._set_catalogue_loading(True)
        self._request_page(self._next_after)

    def _on_gallery_selected(self, _event: tk.Event) -> None:
        """Mount the selected gallery in the embedded viewer pane.

        Args:
            _event (tk.Event): Tree selection event owned by Tk.

        Returns:
            None: Same-session selection replaces the previous viewer frame.
        """
        if self._rendering_tree:
            return
        selection = self.tree.selection()
        if not selection:
            return
        gallery = self._galleries.get(int(selection[0]))
        if (gallery is not None
                and gallery.gallery_id != self._selected_gallery_id):
            self._dismiss_context(restore=False)
            self._select_gallery(gallery)

    def _select_gallery(
        self, gallery: LinkedGallery, *, implicit: bool = False,
        restore: bool = False,
    ) -> None:
        """Mount one exact gallery descriptor against the active session.

        Args:
            gallery (LinkedGallery): Fresh or saved display descriptor selected
                in the tree; media performs its own current authorization.
            implicit (bool): Whether this is an automatic first-preview attempt.
            restore (bool): Whether this restores a saved preference, without
                treating cached metadata as confirmation or a new user choice.

        Returns:
            None: The previous bounded viewer is replaced; its safe failure
                facts flow to the current-session Photos snapshot.
        """
        session = self._session
        if session is None:
            return
        self._selected_gallery_id = gallery.gallery_id
        self._restore_card_format(session, gallery.gallery_id)
        if not implicit and not restore:
            self._user_selected_gallery = True
            self._selection_saved = False
            self._persist_selected_gallery_if_fresh((gallery.gallery_id,))
        self.add_child_button.configure(
            state=("normal" if gallery.gallery_id in self._fresh_catalogue_ids
                   else "disabled"),
        )
        title = ''.join(char if char.isprintable() else ' ' for char in gallery.title)
        self._gallery_title.set(title.strip() or self._text("untitled"))
        self._update_recording_rows()
        if not implicit:
            self._implicit_pending = False
            if self._catalogue_paused_for_preview:
                self._catalogue_paused_for_preview = False
                if self._next_after is not None:
                    self._continue_catalogue()
        self.tree.selection_set(str(gallery.gallery_id))
        if not implicit:
            self.tree.see(str(gallery.gallery_id))
        diagnostic_generation = self._generation
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._clear_photo_inspector()
        self.viewer_status.pack_forget()
        try:
            self._viewer = mount_native_gallery_view(
                self.viewer_pane,
                session,
                gallery,
                self._language,
                show_title=False,
                thumbnail_memory=self._thumbnail_memory,
                context_menu=lambda photo, event: self._viewer_context(
                    gallery.gallery_id, photo, event,
                ),
                diagnostic_callback=lambda summary: (
                    self._record_photo_diagnostic(
                        diagnostic_generation, summary,
                    )
                ),
                photo_selected=self._inspect_photo,
                observation_callback=lambda summary: (
                    self._record_photo_observation(
                        diagnostic_generation, summary,
                    )
                ),
                preview_result=(
                    lambda has_photos: self._on_implicit_preview(
                        gallery.gallery_id,
                        has_photos,
                    )
                ) if implicit else None,
            )
            controller = self._viewer._gallery_viewer_controller
            controller.set_card_format(self._card_format_overrides[gallery.gallery_id])
            controller.on_photos_changed = lambda: self._photos_changed(
                gallery.gallery_id,
            )
            controller.on_cache_changed = lambda photos, complete: (
                self._cache_snapshot_changed(gallery.gallery_id, photos, complete)
            )
            self._viewer.pack(fill="both", expand=True)
        except tk.TclError:
            self.viewer_status.configure(text=self._text("error"))
            self.viewer_status.pack(fill="x", padx=8, pady=8)
            if implicit:
                self._on_implicit_preview(gallery.gallery_id, False)
        self._sync_subgalleries()
        self._refresh_cache_widgets(gallery.gallery_id)
        if self._preload_last is not None:
            self._show_preload_progress(self._preload_last)

    def _restore_card_format(
        self, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Restore the gallery choice without issuing a write on navigation.

        Args:
            session (LinkingSession): Current Photos account/session identity.
            gallery_id (int): Gallery whose local preview format is selected.
        Returns:
            None: The compact selector reflects saved or session-local intent.
        """
        if gallery_id not in self._card_format_overrides:
            value = "default"
            if self._load_card_format is not None:
                try:
                    value = self._load_card_format(session, gallery_id)
                except (OSError, TypeError, ValueError):
                    pass
            self._card_format_overrides[gallery_id] = normalize_card_format(value)
        key = self._card_format_overrides[gallery_id]
        for format_key, button in self._card_format_buttons.items():
            selected = format_key == key
            artwork = self._card_format_images[(format_key, selected)]
            button.configure(
                state="normal", image=artwork if artwork is not None else "",
                style=("Selected.Format.Photos.Icon.TButton" if selected
                       else "Format.Photos.Icon.TButton"),
            )
        unsaved = gallery_id in self._card_format_unsaved
        self._card_format_notice.set("!" if unsaved else "")
        self._card_format_hint.set(self._text(
            "card_format_unsaved" if unsaved else "card_format_hint",
        ))

    def _activate_card_format(self, key: str) -> None:
        """Apply a visible format choice to the currently selected gallery.

        Args:
            key (str): Supported local photo-card format selected by the user.

        Returns:
            None: A current session and gallery receive the immediate choice.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if session is not None and gallery_id is not None:
            self._choose_card_format(session, gallery_id, key)

    def _choose_card_format(
        self, session: LinkingSession, gallery_id: int, key: str,
    ) -> None:
        """Apply one photo-card format and save only its local gallery choice.

        Args:
            session (LinkingSession): Exact session captured when opening choices.
            gallery_id (int): Exact gallery captured by the format action.
            key (str): Supported key activated in the visible format strip.
        Returns:
            None: Existing thumbnails reflow immediately; persistence failure
                leaves the displayed intent and a compact visible warning.
        """
        if (session is not self._session or session is not self._session_provider()
                or gallery_id != self._selected_gallery_id
                or key not in PHOTO_CARD_FORMATS):
            return
        self._card_format_overrides[gallery_id] = key
        if self._viewer is not None:
            self._viewer._gallery_viewer_controller.set_card_format(key)
        saved = False
        if self._save_card_format is not None:
            try:
                saved = self._save_card_format(session, gallery_id, key) is True
            except (OSError, TypeError, ValueError):
                pass
        if saved:
            self._card_format_unsaved.discard(gallery_id)
        else:
            self._card_format_unsaved.add(gallery_id)
        self._restore_card_format(session, gallery_id)

    def _sync_subgalleries(self) -> None:
        """Refresh the selected title and project its direct child folder strip.

        Returns:
            None: The default-on switch controls only the folder cards.
        """
        parent_id = self._selected_gallery_id
        selected = self._galleries.get(parent_id) if parent_id is not None else None
        if selected is not None:
            title = ''.join(
                char if char.isprintable() else ' ' for char in selected.title
            ).strip() or self._text("untitled")
            if self._gallery_title.get() != title:
                self._gallery_title.set(title)
        children = tuple(
            self._galleries[child_id]
            for child_id in sorted(self._children_by_parent.get(parent_id, ()))
            if child_id != parent_id
        )
        visible = bool(children) and self._show_subgalleries.get()
        if self._viewer is not None:
            self._viewer._gallery_viewer_controller.set_folder_shelf_visible(visible)
        if not visible:
            self._folder_shelf.pack_forget()
            self._folder_shelf.show_children(self._session, parent_id, ())
            return
        self._folder_shelf.show_children(self._session, parent_id, children)
        if not self._folder_shelf.winfo_manager():
            self._folder_shelf.pack(fill="x", padx=8, after=self._gallery_heading)

    def _open_subgallery(self, gallery_id: int) -> None:
        """Open a folder card using the existing tree navigation path.

        Args:
            gallery_id (int): Exact server identity represented by the card.

        Returns:
            None: A current direct child can replace the selected gallery.
        """
        gallery = self._galleries.get(gallery_id)
        if (gallery is not None and gallery.parent_id == self._selected_gallery_id
                and gallery_id != self._selected_gallery_id):
            self._dismiss_context(restore=False)
            self._select_gallery(gallery)

    def _dismiss_context(self, *, restore: bool = True) -> None:
        """Close the inline card and release the owning window's bindings.

        Args:
            restore (bool): Whether to return focus to the surviving trigger.

        Returns:
            None: Other workspace controls remain available.
        """
        dispatch = self._create_dispatch
        self._create_dispatch = None
        if dispatch is not None:
            dispatch.cancel()
        if self._probe_cancel is not None:
            self._probe_cancel.set()
            self._probe_cancel = None
        if self._context_focus_check_id is not None:
            try:
                self.after_cancel(self._context_focus_check_id)
            except tk.TclError:
                pass
            self._context_focus_check_id = None
        card, origin = self._context_card, self._context_origin
        self._context_card = self._context_origin = None
        for sequence, binding in self._context_bindings:
            try:
                self.winfo_toplevel().unbind(sequence, binding)
            except tk.TclError:
                pass
        self._context_bindings.clear()
        top = self.winfo_toplevel()
        for widget in self._context_tagged_widgets:
            try:
                tags = widget.bindtags()
                if self._context_bindtag in tags:
                    widget.bindtags(tuple(
                        tag for tag in tags if tag != self._context_bindtag
                    ))
            except tk.TclError:
                pass
        self._context_tagged_widgets.clear()
        if self._context_click_binding is not None:
            try:
                top.unbind_class(self._context_bindtag, "<ButtonPress>")
                top._root().deletecommand(self._context_click_binding)
            except tk.TclError:
                pass
            self._context_click_binding = None
        if self._context_focus_binding is not None:
            try:
                top.unbind_class(self._context_bindtag, "<FocusOut>")
                top._root().deletecommand(self._context_focus_binding)
            except tk.TclError:
                pass
            self._context_focus_binding = None
        if card is not None:
            card.destroy()
        if restore and origin is not None and origin.winfo_exists():
            origin.focus_set()

    def _context_outside(self, event: tk.Event) -> None:
        """Dismiss on a press outside the current contextual card.

        Args:
            event (tk.Event): Main-window pointer press and target widget.

        Returns:
            None: The original press can continue to its target.
        """
        if getattr(event, "num", None) in (4, 5):
            return  # X11 represents wheel motion as button events.
        card = self._context_card
        if card is not None and not (
            event.widget is card or str(event.widget).startswith(str(card) + '.')
        ):
            focused = self.focus_get()
            self._dismiss_context(restore=(
                focused is card or str(focused).startswith(str(card) + '.')
            ))

    def _bind_context_clicks(self) -> None:
        """Place a dismiss binding before child widget bindtags.

        Returns:
            None: Clicks outside the card dismiss it before child handlers run.
        """
        top = self.winfo_toplevel()
        self._context_click_binding = top.bind_class(
            self._context_bindtag, "<ButtonPress>",
            self._context_outside, add="+",
        )
        self._context_focus_binding = top.bind_class(
            self._context_bindtag, "<FocusOut>",
            self._context_focus_out, add="+",
        )

        def tag_widget(widget: tk.Misc) -> None:
            """Place context dismissal before a mapped widget's own actions.

            Args:
                widget (tk.Misc): Current or newly mapped widget in the window.

            Returns:
                None: The widget receives at most one temporary context tag.
            """
            if self._context_card is None or not isinstance(widget, tk.Misc):
                return
            self._tag_context_widget(widget)

        pending: list[tk.Misc] = [top]
        while pending:
            widget = pending.pop()
            if widget.winfo_viewable():
                tag_widget(widget)
                pending.extend(widget.winfo_children())
        binding = top.bind("<Map>", lambda event: tag_widget(event.widget), add="+")
        self._context_bindings.append(("<Map>", binding))

    def _tag_context_widget(self, widget: tk.Misc) -> None:
        """Place context dismissal before one widget's default event handlers.

        Args:
            widget (tk.Misc): Existing mapped control or active-card child.

        Returns:
            None: The widget observes outside presses and focus changes first.
        """
        try:
            tags = widget.bindtags()
            if self._context_bindtag not in tags:
                widget.bindtags((self._context_bindtag, *tags))
                self._context_tagged_widgets.append(widget)
        except tk.TclError:
            return

    def _context_focus_out(self, _event: tk.Event) -> None:
        """Check after focus transitions whether the card lost focus.

        Args:
            _event (tk.Event): Focus change delivered by the owning toplevel.

        Returns:
            None: Focus moving between controls inside the card keeps it open.
        """
        if (self._context_card is not None
                and self._context_focus_check_id is None):
            self._context_focus_check_id = self.after_idle(
                self._check_context_focus,
            )

    def _check_context_focus(self) -> None:
        """Dismiss the card when focus settles outside its descendants.

        Returns:
            None: External keyboard focus closes the current context card.
        """
        self._context_focus_check_id = None
        card = self._context_card
        focused = self.focus_get()
        if card is not None and not (
            focused is card
            or focused is not None
            and str(focused).startswith(str(card) + '.')
        ):
            self._dismiss_context(restore=False)

    def _open_context(
        self, event: tk.Event, *, surface: tk.Misc | None = None,
    ) -> GlassCard:
        """Anchor a compact context to its trigger on the visible owner.

        Args:
            event (tk.Event): Trigger widget and screen coordinates.
            surface (tk.Misc | None): Optional visible parent for a global
                trigger, or None to place the card inside Photos.

        Returns:
            GlassCard: The new current contextual surface.
        """
        self._dismiss_context(restore=False)
        parent = surface if surface is not None else self
        card = GlassCard(parent, padding=8, takefocus=True, rounded_clip=True)
        self._context_card, self._context_origin = card, event.widget
        self._context_surface = parent
        self._context_xy = (
            event.x_root - parent.winfo_rootx(), event.y_root - parent.winfo_rooty(),
        )
        self._bind_context_clicks()
        for sequence, callback in (
            ("<Escape>", self._context_escape),
            ("<FocusOut>", self._context_focus_out),
        ):
            binding = self.winfo_toplevel().bind(sequence, callback, add="+")
            self._context_bindings.append((sequence, binding))
        if parent is not self:
            binding = self.winfo_toplevel().bind(
                '<Configure>', lambda _event: self._place_context(card), add='+',
            )
            self._context_bindings.append(('<Configure>', binding))
        card.bind("<Configure>", lambda _event: self._place_context(card), add="+")
        card.bind("<Map>", lambda _event: card.focus_set(), add="+")
        self._place_context(card)
        self._tag_context_widget(card)
        return card

    def _context_escape(self, _event: tk.Event) -> str:
        """Close the inline surface before other Escape actions.

        Args:
            _event (tk.Event): Main-window Escape event.

        Returns:
            str: Tk break marker after restoring focus.
        """
        self._dismiss_context()
        return "break"

    def _place_context(self, card: GlassCard | None) -> None:
        """Clamp the current card within its visible surface after changes.

        Args:
            card (GlassCard | None): The current surface, if one exists.

        Returns:
            None: The panel stays visible at desktop and narrow window widths.
        """
        if card is None or card is not self._context_card:
            return
        x, y = self._context_xy
        surface = self._context_surface
        width = max(card.winfo_reqwidth(), card.winfo_width())
        height = max(card.winfo_reqheight(), card.winfo_height())
        if self._context_origin is self._storage_button:
            # The header and Photos occupy different branches of the window.
            # Re-read the trigger after every layout rather than retaining the
            # screen coordinates captured before a resize or a text update.
            y = (self._storage_button.winfo_rooty()
                 + self._storage_button.winfo_height()
                 - surface.winfo_rooty())
            pending = list(card.winfo_children())
            while pending:
                child = pending.pop()
                if child.winfo_ismapped():
                    width = max(
                        width, child.winfo_rootx() + child.winfo_width()
                        - card.winfo_rootx(),
                    )
                pending.extend(child.winfo_children())
        top = self.winfo_toplevel()
        right = min(
            surface.winfo_width(),
            top.winfo_rootx() + top.winfo_width() - surface.winfo_rootx(),
        )
        bottom = min(
            surface.winfo_height(),
            top.winfo_rooty() + top.winfo_height() - surface.winfo_rooty(),
        )
        if self._context_origin is self._storage_button:
            # Align the right edge with the visible client/surface intersection.
            x = right - width
        card.place(
            x=max(0, min(x, right - width)),
            y=max(0, min(y, bottom - height)),
            # Coordinates above use the surface's exterior root origin.
            # Tk's default inside mode adds its internal border/padding again.
            bordermode='outside',
        )
        card.lift()

    def _context_content(self, card: GlassCard, title: str) -> None:
        """Replace the contents of a card with its current heading or status.

        Args:
            card (GlassCard): Current contextual card.
            title (str): Localized heading or status message.

        Returns:
            None: A wrapped heading and keyboard focus remain inside the surface.
        """
        card.focus_set()
        for child in card.winfo_children():
            child.destroy()
        ttk.Label(
            card, text=title, style="Card.TLabel",
            wraplength=min(PHOTOS_TREE_WIDTH, self.winfo_width()),
        ).pack(fill="x")
        self._tag_context_widget(card.winfo_children()[-1])

    def _context_action(
        self, card: GlassCard, text: str, command: Callable[[], None], *,
        destructive: bool = False,
    ) -> ttk.Button:
        """Add a compact themed action to the active card.

        Args:
            card (GlassCard): Contextual surface receiving the action.
            text (str): Localized action label.
            command (Callable[[], None]): Action invoked on the Tk thread.
            destructive (bool): Use the theme danger text for a permanent action.

        Returns:
            ttk.Button: Focusable action control.
        """
        button = ttk.Button(
            card, text=text, command=command,
            style=(
                'Danger.Action.Photos.Icon.TButton' if destructive
                else 'Action.Photos.Icon.TButton'
            ),
        )
        button.pack(fill="x", pady=(4, 0))
        self._tag_context_widget(button)
        return button

    def _run_context(
        self, card: GlassCard, operation: Callable[[], Callable[[], None]],
        failure: Callable[[], None], *, survive_view_change: bool = False,
    ) -> None:
        """Run one Photos context operation asynchronously on its exact session.

        Args:
            card (GlassCard): Contextual surface that initiated the work.
            operation (Callable[[], Callable[[], None]]): Worker returning a Tk
                callback for its validated result.
            failure (Callable[[], None]): Tk callback for an unavailable result.
            survive_view_change (bool): Keep terminal reconciliation bound to the
                captured session when another gallery is selected meanwhile.

        Returns:
            None: Admits at most one concurrent context operation.
        """
        if self._context_busy or self._session_provider() is not self._session:
            return
        self._context_content(card, self._text("working"))
        self._place_context(card)
        self._context_busy = True
        generation = None if survive_view_change else self._generation

        def work() -> None:
            """Queue the completion without touching Tk from the worker.

            Returns:
                None: Exactly one success or failure reaches the workspace queue.
            """
            try:
                completion = operation()
            except Exception:
                completion = failure
            self._context_results.put((generation, completion))

        worker = threading.Thread(target=work, name="photos-context", daemon=True)
        try:
            worker.start()
        except RuntimeError:
            self._context_busy = False
            failure()
            return
        self._schedule_poll()

    def _toggle_photo_selection(self, gallery_id: int, image_id: int) -> None:
        """Toggle one visible photo selection without opening its lightbox.

        Args:
            gallery_id (int): Gallery owning the current tile.
            image_id (int): Exact photo ID represented by that tile.

        Returns:
            None: Selection remains local to the current viewer instance.
        """
        controller = getattr(
            self._viewer, '_gallery_viewer_controller', None,
        )
        if (controller is None or gallery_id != self._selected_gallery_id
                or self._session is None):
            return
        controller.toggle_photo_selection(image_id)
        self._dismiss_context()

    def _confirm_photo_delete(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        image_ids: tuple[int, ...],
    ) -> None:
        """Show one anchored confirmation for the exact permanent photo set.

        Args:
            card (GlassCard): Contextual card anchored at the clicked tile.
            session (LinkingSession): Exact session whose delete scope is required.
            gallery_id (int): Exact gallery for the captured image IDs.
            image_ids (tuple[int, ...]): Unique exact IDs to delete after consent.

        Returns:
            None: The destructive request starts only from the final button.
        """
        if (not image_ids or len(image_ids) > NATIVE_MEDIA_DELETE_BATCH_MAX
                or self._session is not session
                or self._selected_gallery_id != gallery_id
                or not session.can_delete_admin_media()):
            return
        gallery = self._galleries.get(gallery_id)
        gallery_name = ' '.join(
            ''.join(char if char.isprintable() else ' ' for char in gallery.title).split()
        ) if gallery is not None else str(gallery_id)
        self._context_content(
            card, self._text('delete_confirm').format(
                count=len(image_ids), gallery=gallery_name,
            ),
        )
        self._context_action(
            card, self._text('cancel'), self._dismiss_context,
        )
        self._context_action(
            card, self._text('delete_permanently'),
            lambda: self._delete_photos(
                card, session, gallery_id, image_ids,
            ),
            destructive=True,
        )
        self._place_context(card)

    def _delete_photos(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        image_ids: tuple[int, ...],
    ) -> None:
        """Send one confirmed batch and reconcile its exact terminal outcome.

        Args:
            card (GlassCard): Confirmation card that initiated deletion.
            session (LinkingSession): Exact live site session captured by Photos.
            gallery_id (int): Exact physical gallery ID.
            image_ids (tuple[int, ...]): Exact confirmed photo IDs, in gallery order.

        Returns:
            None: The single server action is never automatically replayed.
        """
        def operation() -> Callable[[], None]:
            """Perform the one-shot deletion away from the Tk thread.

            Returns:
                Callable[[], None]: UI reconciliation for the captured operation.
            """
            deleted = session.delete_media_photos(gallery_id, image_ids)

            def complete() -> None:
                """Refresh only the still-mounted owner of the deleted gallery.

                Returns:
                    None: Confirmed IDs leave selection and metadata reloads in place.
                """
                if (self._session is not session
                        or self._session_provider() is not session):
                    return
                self._invalidate_photo_info(gallery_id, deleted)
                self._thumbnail_memory.remove_photos(gallery_id, deleted)
                controller = getattr(
                    self._viewer, '_gallery_viewer_controller', None,
                )
                if (self._selected_gallery_id == gallery_id and controller is not None):
                    controller.clear_photo_selection(deleted)
                    controller.refresh_photos()
                    controller.status.pack(anchor='w', pady=(4, 8))
                    controller.status.configure(text=self._text('delete_complete'))
                if self._context_card is card:
                    self._dismiss_context()

            return complete

        def failure() -> None:
            """Surface an ambiguous outcome without inviting request replay.

            Returns:
                None: The current gallery reloads to inspect server state.
            """
            def report() -> None:
                """Show uncertainty and request a passive current-gallery refresh.

                Returns:
                    None: The user must inspect the reloaded gallery before acting.
                """
                if self._session is not session:
                    return
                controller = getattr(
                    self._viewer, '_gallery_viewer_controller', None,
                )
                if (self._selected_gallery_id == gallery_id and controller is not None):
                    controller.status.pack(anchor='w', pady=(4, 8))
                    controller.status.configure(text=self._text('delete_uncertain'))
                    controller.refresh_photos()
                if self._context_card is card:
                    self._context_content(card, self._text('delete_uncertain'))
                    self._place_context(card)
            report()

        self._run_context(
            card, operation, failure, survive_view_change=True,
        )

    def _tree_context(self, event: tk.Event) -> str | None:
        """Open a gallery-row menu or the blank tree's root creation action.

        Args:
            event (tk.Event): Secondary pointer press in the gallery tree.

        Returns:
            str | None: Tk break for a handled Photos context.
        """
        item = self.tree.identify_row(event.y)
        return self._gallery_context(
            int(item) if item else None, event, allow_root=True,
        )

    def _viewer_context(
        self, gallery_id: int, photo: LinkedPhoto | None, event: tk.Event,
    ) -> str | None:
        """Dispatch an exact photo or empty-grid context.

        Args:
            gallery_id (int): Gallery owning the currently mounted grid.
            photo (LinkedPhoto | None): Exact clicked photo, or empty space.
            event (tk.Event): Secondary pointer press in the grid.

        Returns:
            str | None: Tk break for a handled Photos context.
        """
        session = self._session
        if session is None or self._selected_gallery_id != gallery_id:
            return None
        if photo is None:
            return self._gallery_context(gallery_id, event, creation_only=True)
        controller = getattr(
            self._viewer, '_gallery_viewer_controller', None,
        )
        selected_ids = (
            controller.selected_photo_ids() if controller is not None else ()
        )
        card = self._open_context(event)
        can_create = self._manager is not None and session.can_create_gallery()
        self._context_content(card, self._text("photo_menu"))
        if controller is not None:
            is_selected = photo.image_id in selected_ids
            self._context_action(
                card,
                self._text('deselect_photo' if is_selected else 'select_photo'),
                lambda: self._toggle_photo_selection(
                    gallery_id, photo.image_id,
                ),
            )
        if session.can_delete_admin_media():
            delete_ids = (
                selected_ids
                if photo.image_id in selected_ids else (photo.image_id,)
            )
            if len(delete_ids) <= NATIVE_MEDIA_DELETE_BATCH_MAX:
                delete_label = (
                    self._text('delete_selected').format(count=len(delete_ids))
                    if photo.image_id in selected_ids
                    else self._text('delete_photo')
                )
                self._context_action(
                    card, delete_label,
                    lambda: self._confirm_photo_delete(
                        card, session, gallery_id, delete_ids,
                    ),
                )
        self._context_action(
            card, self._text("photo_info"),
            lambda: self._photo_info(card, photo),
        )
        if can_create:
            self._add_gallery_creation_action(card, session, gallery_id)
        if photo.visibility is not None and session.can_change_photo_visibility():
            target = "private" if photo.visibility == "public" else "public"
            self._context_action(
                card, "👁 " + self._text("visibility_" + target),
                lambda: self._write_photo_visibility(
                    card, session, gallery_id, photo, target,
                ),
            )
        elif self._manager is not None and not session.can_read_admin_media():
            self._context_action(
                card, self._text("approve_admin"), self._approve_admin,
            )
        self._place_context(card)
        return "break"

    def _gallery_context(
        self, gallery_id: int | None, event: tk.Event, *,
        creation_only: bool = False, allow_root: bool = False,
    ) -> str | None:
        """Open the exact gallery's actions, or root creation on blank tree.

        Args:
            gallery_id (int | None): Target gallery ID, or web root.
            event (tk.Event): Pointer event anchoring the contextual card.
            creation_only (bool): Whether the trigger was blank photo-grid space.
            allow_root (bool): Whether a tree-row menu also offers root creation.

        Returns:
            str | None: Tk break for a handled Photos context.
        """
        session = self._session
        if session is None:
            return None
        can_create = self._manager is not None and session.can_create_gallery()
        card = self._open_context(event)
        title = (
            "root_menu" if gallery_id is None
            else "subgallery_menu" if creation_only else "gallery_menu"
        )
        heading = self._text(title)
        if not can_create:
            heading += "\n" + self._text("create_requires_approval")
        self._context_content(card, heading)
        if gallery_id is not None and not creation_only:
            self._gallery_info(card, session, gallery_id)
            if session.can_change_gallery_visibility():
                self._context_action(
                    card, "👁 " + self._text("visibility"),
                    lambda: self._gallery_visibility(card, session, gallery_id),
                )
            if self._manager is not None and not session.can_read_admin_media():
                self._context_action(
                    card, self._text("approve_admin"), self._approve_admin,
                )
        gallery = self._galleries.get(gallery_id) if gallery_id is not None else None
        if gallery is not None:
            if not creation_only and session.can_read_media():
                self._add_cache_actions(card, session, gallery)
            if not creation_only and self._manager is not None:
                open_editor = getattr(
                    self._manager, 'open_photos_gallery_editor', None,
                )
                if callable(open_editor):
                    self._context_action(
                        card, self._text('edit_gallery'),
                        lambda: self._launch_gallery_editor(
                            card, session, gallery,
                        ),
                    )
            self._add_host_gallery_actions(card, session, gallery)
        self._add_gallery_creation_action(card, session, gallery_id)
        if gallery_id is not None and allow_root:
            self._add_gallery_creation_action(card, session, None)
        self._place_context(card)
        return "break"

    def _launch_gallery_editor(
        self, card: GlassCard, session: LinkingSession,
        gallery: LinkedGallery,
    ) -> None:
        """Open only the current listed gallery in its authenticated browser.

        Args:
            card (GlassCard): Gallery context containing the action.
            session (LinkingSession): Exact live session captured by the menu.
            gallery (LinkedGallery): Exact gallery row captured by the menu.

        Returns:
            None: Opens the editor or reports a localized safe failure in Photos.
        """
        open_editor = getattr(
            self._manager, 'open_photos_gallery_editor', None,
        )
        if (self._context_card is not card or self._session is not session
                or self._session_provider() is not session
                or self._galleries.get(gallery.gallery_id) is not gallery
                or not callable(open_editor)):
            self._dismiss_context()
            return
        try:
            opened = open_editor(session, gallery)
        except Exception:  # noqa: BLE001
            opened = False
        if opened:
            self._dismiss_context()
            return
        self.tree_status.configure(text=self._text('edit_gallery_failed'))
        self._context_content(card, self._text('edit_gallery_failed'))
        self._place_context(card)

    def selected_watcher_gallery(
        self,
    ) -> tuple[LinkingSession, LinkedGallery] | None:
        """Return the selected gallery for the current linked session.

        Returns:
            tuple[LinkingSession, LinkedGallery] | None: Exact current session
                and selected physical gallery, or None when selection is stale.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if (session is None or gallery_id is None
                or gallery_id not in self._fresh_catalogue_ids
                or self._session_provider() is not session):
            return None
        gallery = self._galleries.get(gallery_id)
        return (session, gallery) if gallery is not None else None

    def open_selected_watcher_context(self, event: tk.Event) -> str | None:
        """Open Watcher actions from an external rail using the shared card.

        Args:
            event (tk.Event): Pointer event from the Watcher rail trigger.

        Returns:
            str | None: Tk break for a handled selected-gallery context.
        """
        current = self.selected_watcher_gallery()
        if current is None or not current[0].can_upload():
            return None
        session, gallery = current
        card = self._open_context(event)
        self._context_content(card, self._text("watcher_menu"))
        if self._start_watcher is not None:
            self._context_action(
                card, self._text("start_watcher"),
                lambda: self._launch_watcher(card, session, gallery),
            )
        if (self._simbrief_action is not None
                and self._simbrief_available is not None
                and self._simbrief_available()):
            self._context_action(
                card, self._text("simbrief"),
                lambda: self._launch_simbrief(card),
            )
        self._place_context(card)
        return "break"

    def _add_host_gallery_actions(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Add host-owned gallery actions when their callbacks are available.

        Args:
            card (GlassCard): Exact gallery's active context card.
            session (LinkingSession): Exact live session for this gallery.
            gallery (LinkedGallery): Physical row represented by this action.

        Returns:
            None: Only explicitly authorized, configured host actions appear.
        """
        if self._start_watcher is not None and session.can_upload():
            self._context_action(
                card, self._text("start_watcher"),
                lambda: self._launch_watcher(card, session, gallery),
            )
        if (self._simbrief_action is not None
                and self._simbrief_available is not None
                and self._simbrief_available()):
            self._context_action(
                card, self._text("simbrief"),
                lambda: self._launch_simbrief(card),
            )

    def _launch_watcher(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Start the exact current gallery or explain its refusal in Photos.

        Args:
            card (GlassCard): Context card owning the action.
            session (LinkingSession): Exact session captured at opening.
            gallery (LinkedGallery): Exact selected physical gallery.

        Returns:
            None: A current target reaches the host; an unavailable target
                keeps an actionable explanation in the originating card.
        """
        if card is not self._context_card:
            return
        if (self._start_watcher is None or self._session is not session
                or self._session_provider() is not session
                or not session.available() or not session.can_upload()
                or self._galleries.get(gallery.gallery_id) != gallery
                or gallery.gallery_id not in self._fresh_catalogue_ids):
            self.tree_status.configure(text=self._text("watcher_unavailable"))
            self._context_status(card, "watcher_unavailable")
            return
        self._dismiss_context()
        self._start_watcher(session, gallery)

    def _launch_simbrief(self, card: GlassCard) -> None:
        """Dismiss the card and invoke the current host SimBrief action.

        Args:
            card (GlassCard): Context card owning the action.

        Returns:
            None: The host controls the simulator panel lifecycle.
        """
        self._dismiss_context()
        if (self._simbrief_action is not None
                and self._simbrief_available is not None
                and self._simbrief_available()):
            self._simbrief_action()

    def _approve_admin(self) -> None:
        """Start the existing browser approval flow from the context card.

        Returns:
            None: The current selection remains until the session changes.
        """
        self._dismiss_context()
        if self._manager is not None:
            self._manager.approve_admin_photos()

    def _context_status(self, card: GlassCard, key: str) -> None:
        """Show the localized outcome while the originating card remains open.

        Args:
            card (GlassCard): Originating contextual surface.
            key (str): Localized status suffix.

        Returns:
            None: A dismissed card is never reopened by a worker.
        """
        if card is self._context_card:
            self._context_content(card, self._text(key))
            self._place_context(card)

    def _photo_info(self, card: GlassCard, photo: LinkedPhoto) -> None:
        """Show authorized photo metadata, reusing the bounded session cache.

        Args:
            card (GlassCard): Photo context surface.
            photo (LinkedPhoto): Exact photo row already authorized for the grid.

        Returns:
            None: Displays server details or safe local fallback information.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if session is None or gallery_id is None:
            return
        cached = self._cached_photo_info(session, gallery_id, photo.image_id)
        if cached is not None:
            self._show_details(
                card, self._text("photo_info"), cached,
                session=session, fallback=False,
            )
            return
        cancel = threading.Event()
        self._probe_cancel = cancel
        diagnostic_generation = self._generation

        def read() -> Callable[[], None]:
            """Fetch server metadata or build a local descriptor fallback.

            Returns:
                Callable[[], None]: Tk callback rendering validated photo data.
            """
            details = self._read_photo_info(
                session, gallery_id, photo, cancel, diagnostic_generation,
            )
            fields = details or {
                "title": photo.title,
                "size": photo.size,
                "visibility": photo.visibility,
                "nsfw": photo.nsfw,
            }
            return lambda: self._show_context_photo_info(
                card, session, gallery_id, photo, fields, details,
            )

        self._run_context(
            card, read,
            lambda: self._show_details(
                card, self._text("photo_info"), {
                    "title": photo.title,
                    "size": photo.size,
                }, session=session, fallback=True,
            ),
        )

    def _read_photo_info(
        self, session: LinkingSession, gallery_id: int, photo: LinkedPhoto,
        cancel: threading.Event, diagnostic_generation: int,
    ) -> LinkedMediaDetails | None:
        """Read metadata once through the shared authorized transport owner.

        Args:
            session (LinkingSession): Captured session for this worker.
            gallery_id (int): Exact gallery owning the descriptor.
            photo (LinkedPhoto): Exact photo whose metadata is requested.
            cancel (threading.Event): Consumer-owned cancellation signal.
            diagnostic_generation (int): Captured workspace session generation.

        Returns:
            LinkedMediaDetails | None: Validated scalar server fields, or None
                for an older unsupported server, cancellation or safe failure.
        """
        method = getattr(session, "media_photo_info", None)
        if not callable(method) or cancel.is_set():
            return None
        try:
            return method(gallery_id, photo.image_id, cancelled=cancel)
        except Exception as error:
            if not cancel.is_set():
                _log_safe_media_failure(
                    "media-fetch", "photo_info", error,
                    diagnostic_callback=lambda summary: (
                        self._record_photo_diagnostic(
                            diagnostic_generation, summary,
                        )
                    ),
                )
            return None

    def _show_context_photo_info(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        photo: LinkedPhoto, fields: LinkedMediaDetails,
        details: LinkedMediaDetails | None,
    ) -> None:
        """Cache validated context metadata and render its existing card view.

        Args:
            card (GlassCard): Context surface that initiated the information read.
            session (LinkingSession): Captured authorization scope.
            gallery_id (int): Exact gallery owning the photo.
            photo (LinkedPhoto): Exact selected photo descriptor.
            fields (LinkedMediaDetails): Validated response or safe local fallback.
            details (LinkedMediaDetails | None): Server response when available.

        Returns:
            None: Cache and widget work occur together on the Tk thread.
        """
        if (card is not self._context_card or session is not self._session
                or self._session_provider() is not session
                or self._selected_gallery_id != gallery_id):
            return
        if details is not None:
            self._remember_photo_info(
                session, gallery_id, photo.image_id, details,
            )
        self._show_details(
            card, self._text("photo_info"), fields,
            session=session, fallback=details is None,
        )

    def _inspect_photo(self, photo: LinkedPhoto) -> None:
        """Show a selected photo's safe summary and coalesce its metadata read.

        Args:
            photo (LinkedPhoto): Exact descriptor selected in the current viewer.

        Returns:
            None: At most one metadata worker runs with only the latest pending
                photo retained for the active session and gallery.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if session is None or gallery_id is None:
            self._clear_photo_inspector()
            return
        self._inspector_generation += 1
        generation = self._inspector_generation
        if self._inspector_cancel is not None:
            self._inspector_cancel.set()
        self._inspector_pending = None
        self._inspector_summary(photo, self._text("working"))
        cached = self._cached_photo_info(session, gallery_id, photo.image_id)
        if cached is not None:
            self._render_photo_inspector(
                photo, cached, session=session, fallback=False,
            )
            return
        self._inspector_pending = (session, gallery_id, photo, generation)
        self._start_photo_info_worker()

    def _inspector_summary(self, photo: LinkedPhoto, status: str) -> None:
        """Render the immediate safe title, size and metadata-read status.

        Args:
            photo (LinkedPhoto): Current selected gallery descriptor.
            status (str): Localized status shown below the safe descriptor.

        Returns:
            None: No media bytes or worker result are required for this summary.
        """
        self._show_photo_inspector_pane()
        title = " ".join(
            "".join(char if char.isprintable() else " " for char in photo.title)
            .split()
        )
        self._photo_inspector_heading.configure(
            text=title or self._text("photo_info"),
        )
        self._photo_inspector_summary.configure(
            text=(self._text("field_size") + ": "
                  + (self._format_bytes(photo.size)
                     if type(photo.size) is int
                     else self._text("unknown"))
                  + ("\n" + status if status else "")),
        )
        for child in self._photo_inspector_content.winfo_children():
            child.destroy()

    def _start_photo_info_worker(self) -> None:
        """Start the latest queued metadata request when the single slot is free.

        Returns:
            None: One daemon reader publishes a result for Tk-thread fencing.
        """
        if (self._destroyed or self._inspector_worker is not None
                or self._inspector_pending is None):
            return
        session, gallery_id, photo, generation = self._inspector_pending
        self._inspector_pending = None
        cancel = threading.Event()
        self._inspector_cancel = cancel
        diagnostic_generation = self._generation

        def read() -> None:
            """Fetch one authorized metadata response without touching Tk.

            Returns:
                None: The exact descriptor and optional response reach the Tk queue.
            """
            details = self._read_photo_info(
                session, gallery_id, photo, cancel, diagnostic_generation,
            )
            self._inspector_results.put((
                generation, session, gallery_id, photo, details,
            ))

        self._inspector_worker = threading.Thread(
            target=read, name="photos-inspector", daemon=True,
        )
        try:
            self._inspector_worker.start()
        except RuntimeError:
            self._inspector_worker = None
            self._inspector_cancel = None
            self._render_photo_inspector(
                photo, None, session=session, fallback=True,
            )
            return
        self._schedule_poll()

    def _apply_photo_info_results(self) -> None:
        """Apply current metadata results and release the single reader slot.

        Returns:
            None: Stale selections are discarded and only the latest pending
                descriptor can start the next request.
        """
        while True:
            try:
                generation, session, gallery_id, photo, details = (
                    self._inspector_results.get_nowait()
                )
            except queue.Empty:
                break
            if self._inspector_cancel is not None:
                self._inspector_cancel = None
            current_request = (
                generation == self._inspector_generation
                and gallery_id == self._selected_gallery_id
            )
            if (session is self._session
                    and self._session_provider() is session):
                if details is not None and current_request:
                    self._remember_photo_info(
                        session, gallery_id, photo.image_id, details,
                    )
                if current_request:
                    self._render_photo_inspector(
                        photo, details, session=session,
                        fallback=details is None,
                    )
        if (self._inspector_worker is not None
                and not self._inspector_worker.is_alive()):
            self._inspector_worker = None
            self._inspector_cancel = None
        self._start_photo_info_worker()

    def _cached_photo_info(
        self, session: LinkingSession, gallery_id: int, image_id: int,
    ) -> LinkedMediaDetails | None:
        """Return and promote one entry in the current session's metadata LRU.

        Args:
            session (LinkingSession): Exact live authorization scope.
            gallery_id (int): Exact gallery owning the photo.
            image_id (int): Exact photo identifier.

        Returns:
            LinkedMediaDetails | None: A fresh shallow response copy for the
                exact live session, or no currently authorized cached row.
        """
        if (session is not self._session
                or self._session_provider() is not session
                or not session.can_read_media()):
            return None
        key = (id(session), gallery_id, image_id)
        cached = self._photo_info_cache.get(key)
        if cached is None:
            return None
        verified_at, details = cached
        age = time.monotonic() - verified_at
        if not 0 <= age < PHOTOS_INFO_CACHE_FRESH_SECONDS:
            self._photo_info_cache.pop(key, None)
            return None
        self._photo_info_cache.move_to_end(key)
        return dict(details)

    def _remember_photo_info(
        self, session: LinkingSession, gallery_id: int, image_id: int,
        details: LinkedMediaDetails,
    ) -> None:
        """Retain a shallow copy of validated metadata within the session LRU.

        Args:
            session (LinkingSession): Exact session that authorized the read.
            gallery_id (int): Exact gallery owning the photo.
            image_id (int): Exact photo identifier.
            details (LinkedMediaDetails): Validated scalar server metadata.

        Returns:
            None: Current results receive a bounded freshness time; stale
                sessions cannot populate the LRU.
        """
        if (session is not self._session
                or self._session_provider() is not session
                or not session.can_read_media()):
            return
        key = (id(session), gallery_id, image_id)
        self._photo_info_cache[key] = (time.monotonic(), dict(details))
        self._photo_info_cache.move_to_end(key)
        while len(self._photo_info_cache) > PHOTOS_INFO_CACHE_ENTRY_LIMIT:
            self._photo_info_cache.popitem(last=False)

    def _clear_photo_inspector(self) -> None:
        """Cancel pending metadata work and clear the visible inspector.

        Returns:
            None: A late read cannot repaint a replaced gallery or session.
        """
        self._inspector_generation += 1
        if self._info_width_restore_after is not None:
            self.after_cancel(self._info_width_restore_after)
            self._info_width_restore_after = None
        if self._inspector_cancel is not None:
            self._inspector_cancel.set()
            self._inspector_cancel = None
        self._inspector_pending = None
        self._photo_inspector_heading.configure(text=self._text("photo_info"))
        self._photo_inspector_summary.configure(
            text=self._text("select_photo_hint"),
        )
        for child in self._photo_inspector_content.winfo_children():
            child.destroy()
        if self._photo_inspector_visible:
            self.panes.forget(self.info_pane)
            self._photo_inspector_visible = False

    def _show_photo_inspector_pane(self) -> None:
        """Show the optional metadata pane after a tile becomes selected.

        Returns:
            None: The grid keeps its full width until photo details are requested.
        """
        if self._photo_inspector_visible or self._destroyed:
            return
        available = self.panes.winfo_width() - self.tree_pane.winfo_width()
        # Share narrow remaining space rather than imposing an unsupported
        # Tk panedwindow "minsize" option or covering the photo grid.
        preferred = min(PHOTOS_INFO_PANE_WIDTH, max(
            PHOTOS_INFO_PANE_MIN_WIDTH,
            available - PHOTOS_INFO_GRID_RESERVE_WIDTH,
        ))
        width = min(preferred, max(1, available // 2))
        self.info_pane.configure(width=width)
        self.panes.add(self.info_pane, weight=0)
        self._photo_inspector_visible = True
        self._info_width_restored = False
        self.after_idle(self._restore_info_width)
        self._info_width_restore_after = self.after(
            PHOTOS_INFO_RESTORE_DELAY_MS, self._settle_info_width,
        )

    def _settle_info_width(self) -> None:
        """Restore the desired width once after native pane layout settles.

        Returns:
            None: Clears its owned timer and respects teardown, hiding and any
                newer user drag through the existing restoration guards.
        """
        self._info_width_restore_after = None
        self._restore_info_width()

    def _render_photo_inspector(
        self, photo: LinkedPhoto, fields: LinkedMediaDetails | None, *,
        session: LinkingSession, fallback: bool,
    ) -> None:
        """Render shared authorized metadata groups in the right-side pane.

        Args:
            photo (LinkedPhoto): Descriptor for the captured selected photo.
            fields (LinkedMediaDetails | None): Server response, if available.
            session (LinkingSession): Authorization scope captured for the read.
            fallback (bool): Whether to show the local-descriptor note.

        Returns:
            None: Only groups allowed by the current session are displayed.
        """
        if session is not self._session:
            return
        current = fields or {
            "title": photo.title,
            "size": photo.size,
            "visibility": photo.visibility,
            "nsfw": photo.nsfw,
        }
        self._inspector_summary(
            photo,
            self._text("details_fallback") if fallback
            else self._text("photo_info_ready"),
        )
        groups = self._photo_info_groups(current, session)
        for heading, rows in groups:
            rows = [(key, value) for key, value in rows if value is not None]
            if not rows:
                continue
            ttk.Label(
                self._photo_inspector_content,
                text=self._text(heading.removeprefix("photos_workspace_")),
                style="Muted.TLabel",
            ).pack(anchor="w", pady=(6, 1))
            self._bind_photo_inspector_scroll(
                self._photo_inspector_content.winfo_children()[-1],
            )
            for key, value in rows:
                text = self._text(key.removeprefix("photos_workspace_"))
                rendered = self._format_detail_value(key, value)
                ttk.Label(
                    self._photo_inspector_content,
                    text=text + ": " + rendered,
                    style="Card.TLabel",
                    wraplength=(
                        PHOTOS_INFO_PANE_WIDTH - PHOTOS_INFO_PANE_ROW_INSET
                    ),
                    justify="left",
                ).pack(fill="x", anchor="w")
                self._bind_photo_inspector_scroll(
                    self._photo_inspector_content.winfo_children()[-1],
                )
        self._photo_inspector_content.update_idletasks()
        self._photo_inspector_canvas.configure(
            scrollregion=self._photo_inspector_canvas.bbox("all"),
        )

    def _resize_photo_inspector_content(self, _event: tk.Event) -> None:
        """Keep the inspector scrollregion aligned with its current rows.

        Args:
            _event (tk.Event): Content-frame geometry notification.

        Returns:
            None: The scrollbar follows the complete grouped metadata content.
        """
        self._photo_inspector_canvas.configure(
            scrollregion=self._photo_inspector_canvas.bbox("all"),
        )

    def _bind_photo_inspector_scroll(self, widget: tk.Misc) -> None:
        """Route wheel input from inspector labels to its metadata viewport.

        Args:
            widget (tk.Misc): Inspector content widget beneath the pointer.

        Returns:
            None: Wheel and touchpad motion scroll only the information pane.
        """
        widget.bind(
            "<MouseWheel>", self._scroll_photo_inspector, add="+",
        )
        widget.bind("<Button-4>", self._scroll_photo_inspector, add="+")
        widget.bind("<Button-5>", self._scroll_photo_inspector, add="+")
        try:
            widget.bind(
                "<TouchpadScroll>",
                lambda event: self._scroll_photo_inspector(
                    event, precise=True,
                ),
                add="+",
            )
        except tk.TclError:
            pass

    def _scroll_photo_inspector(
        self, event: tk.Event, *, precise: bool = False,
    ) -> None:
        """Scroll the metadata pane using platform-adjusted wheel deltas.

        Args:
            event (tk.Event): Native mouse wheel or touchpad event.
            precise (bool): Whether the event contains pixel-level delta data.

        Returns:
            None: The inspector canvas alone consumes the scroll distance.
        """
        delta = vertical_scroll_delta(self, event, precise=precise)
        if precise:
            bounds = self._photo_inspector_canvas.bbox("all")
            if bounds is not None:
                height = bounds[3] - bounds[1]
                if height > 0 and delta:
                    self._photo_inspector_canvas.yview_moveto(max(
                        0.0, min(
                            1.0,
                            self._photo_inspector_canvas.yview()[0]
                            - delta / height,
                        ),
                    ))
        elif delta:
            self._photo_inspector_canvas.yview_scroll(
                -1 if delta > 0 else 1, "units",
            )

    def _resize_photo_inspector_viewport(self, event: tk.Event) -> None:
        """Match the wrapped inspector content to the visible canvas width.

        Args:
            event (tk.Event): Canvas width after pane resizing.

        Returns:
            None: Labels wrap to fit the current pane width.
        """
        self._photo_inspector_canvas.itemconfigure(
            self._photo_inspector_window, width=event.width,
        )
        self._photo_inspector_heading.configure(
            wraplength=max(1, event.width - PHOTOS_INFO_PANE_HEADER_INSET),
        )
        self._photo_inspector_summary.configure(
            wraplength=max(1, event.width - PHOTOS_INFO_PANE_HEADER_INSET),
        )
        for child in self._photo_inspector_content.winfo_children():
            if isinstance(child, ttk.Label):
                child.configure(wraplength=max(
                    1, event.width - PHOTOS_INFO_PANE_ROW_INSET,
                ))

    def _open_cache_context(self) -> None:
        """Open the selected gallery's existing anchored action panel.

        Returns:
            None: Exposes cache status, preload and retention beside the trigger.
        """
        gallery_id = self._selected_gallery_id
        if gallery_id is not None:
            self._gallery_context(gallery_id, self._context_event(self._cache_button))

    def _cache_description(self, gallery_id: int) -> str:
        """Describe observed local availability without claiming offline access.

        Args:
            gallery_id (int): Exact gallery represented by the status.

        Returns:
            str: Localized availability, optional observed count and retention.
        """
        state = self._cache_state.get(gallery_id, 'unknown')
        message = self._text('cache_' + state)
        counts = self._cache_counts.get(gallery_id)
        if counts is not None:
            cached, total, complete = counts
            message += ' · ' + self._text(
                'cache_count' if complete else 'cache_loaded_count',
            ).format(cached=cached, total=total)
        if self._gallery_pins.get(gallery_id) is True:
            message += ' · ' + self._text('keep_preloaded')
        return message

    def _refresh_cache_widgets(self, gallery_id: int) -> None:
        """Refresh existing cache controls after a background observation.

        Args:
            gallery_id (int): Exact current site's gallery whose result changed.

        Returns:
            None: Updates the selected heading and any surviving cache menu.
        """
        if self._destroyed:
            return
        if self._selected_gallery_id == gallery_id:
            self._cache_button.configure(text=self._cache_glyph(gallery_id))
            self._cache_tooltip.set(
                self._cache_description(gallery_id) + '\n' + self._text('cache_legend'),
            )
        if self._cache_context_gallery != gallery_id:
            return
        button = self._cache_pin_button
        if button is None or not button.winfo_exists():
            return
        if self._cache_context_status is not None:
            self._cache_context_status.set(self._cache_description(gallery_id))
        active = self._preload_worker is not None and self._preload_worker.is_alive()
        known = gallery_id in self._gallery_pins
        if not active and self._cache_pin_var is not None:
            self._cache_pin_var.set(self._gallery_pins.get(gallery_id, False))
        button.state(['!disabled'] if known and not active else ['disabled'])

    def _cache_snapshot_changed(
        self, gallery_id: int, photos: tuple[LinkedPhoto, ...], complete: bool,
    ) -> None:
        """Coalesce authorized viewer snapshots for local-only cache checks.

        Args:
            gallery_id (int): Exact gallery owned by the notifying viewer.
            photos (tuple[LinkedPhoto, ...]): Already authorized loaded descriptors.
            complete (bool): Whether this snapshot reaches the catalogue's end.

        Returns:
            None: Queues one latest snapshot per gallery without network reads.
        """
        if self._destroyed or gallery_id not in self._galleries:
            return
        self._request_storage_refresh()
        self._cache_pending[gallery_id] = (photos, complete)
        if self._cache_after is None:
            self._cache_after = self.after(
                PHOTOS_CACHE_REFRESH_MS, self._start_cache_probe,
            )
        self._schedule_poll()

    def _start_cache_probe(self) -> None:
        """Inspect one bounded batch of local headers and pin preferences.

        Returns:
            None: Cancels the pending delayed invocation before an immediate
                probe; one daemon reader publishes current-generation results.
        """
        after_id = self._cache_after
        self._cache_after = None
        if after_id is not None:
            # Catalogue and cache-result paths can request this probe before
            # the coalesced timer fires. Retain no orphan Tcl callback when
            # that immediate request replaces it or the workspace is closed.
            self.after_cancel(after_id)
        session = self._session
        if (self._destroyed or session is None or self._cache_worker is not None
                or not self._cache_pending):
            return
        requests, self._cache_pending = self._cache_pending, {}
        generation, cancel = self._generation, self._cancel

        def read() -> None:
            """Read local metadata without loading another server catalogue.

            Returns:
                None: Publishes state/count/completeness/pin rows for this batch.
            """
            rows: dict[int, _CacheRow] = {}
            for gallery_id, (photos, complete) in requests.items():
                if cancel.is_set():
                    return
                try:
                    pin_method = getattr(session, 'gallery_pinned', None)
                    pinned = pin_method(gallery_id) is True if callable(pin_method) else False
                    state: str | None = None
                    cached = 0
                    any_cached = False
                    if photos is not None:
                        for photo in photos:
                            if cancel.is_set():
                                return
                            thumb = session.has_cached_photo(
                                gallery_id, photo, thumbnail=True,
                            )
                            detail = session.has_cached_photo(gallery_id, photo)
                            cached += int(bool(thumb and detail))
                            any_cached |= bool(thumb or detail)
                        state = (
                            'local' if complete and photos and cached == len(photos)
                            else 'partial' if any_cached
                            else 'cloud' if complete else 'unknown'
                        )
                    rows[gallery_id] = (
                        state, cached, len(photos or ()), complete, pinned,
                    )
                except Exception:
                    rows[gallery_id] = ('unknown', 0, 0, False, None)
            self._cache_results.put((generation, rows))

        self._cache_worker = threading.Thread(
            target=read, name='GalleryCacheStatus', daemon=True,
        )
        self._cache_worker.start()
        self._schedule_poll()

    def _poll_cache_work(self) -> None:
        """Apply local cache observations and explicit preload progress on Tk.

        Returns:
            None: Rejects stale sessions and starts at most one next local probe.
        """
        while True:
            try:
                generation, rows = self._cache_results.get_nowait()
            except queue.Empty:
                break
            if (generation != self._generation
                    or self._session_provider() is not self._session):
                continue
            changed: list[int] = []
            for gallery_id, (state, cached, total, complete, pinned) in rows.items():
                if gallery_id not in self._galleries:
                    continue
                if state is not None:
                    prior = self._cache_counts.get(gallery_id)
                    retained_complete = (
                        self._gallery_pins.get(gallery_id) is True
                        and self._cache_state.get(gallery_id) == 'local'
                        and prior is not None and prior[2]
                        and not complete and total > 0 and cached == total
                    )
                    if not retained_complete:
                        self._cache_state[gallery_id] = state
                        self._cache_counts[gallery_id] = (cached, total, complete)
                if pinned is not None:
                    self._gallery_pins[gallery_id] = pinned
                else:
                    self._gallery_pins.pop(gallery_id, None)
                changed.append(gallery_id)
                self._refresh_cache_widgets(gallery_id)
            if changed:
                self._render_tree(changed)
        if self._cache_worker is not None and not self._cache_worker.is_alive():
            self._cache_worker = None
        if self._cache_pending and self._cache_after is None:
            self._start_cache_probe()
        worker = self._preload_worker
        if worker is None or self._preload_generation != self._generation:
            return
        progress = worker.snapshot()
        if progress == self._preload_last:
            self._show_preload_progress(progress)
            return
        self._preload_last = progress
        self._show_preload_progress(progress)
        if progress.phase in ('complete', 'partial', 'cancelled', 'error'):
            gallery_id = progress.gallery_id
            if progress.cache_state != 'unknown' and not progress.thumbnails_only:
                self._cache_state[gallery_id] = progress.cache_state
                self._cache_counts[gallery_id] = (
                    progress.cached, progress.total or 0, True,
                )
            self._cache_pending.setdefault(gallery_id, (None, False))
            if gallery_id in self._galleries:
                self._render_tree([gallery_id])
                self._refresh_cache_widgets(gallery_id)

    def _add_cache_actions(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Add explicit preload and retention to the existing gallery menu.

        Args:
            card (GlassCard): Exact gallery's current action card.
            session (LinkingSession): Current authorized Photos session.
            gallery (LinkedGallery): Exact listed physical gallery.

        Returns:
            None: Shows observed status and a pending-safe local preference.
        """
        gallery_id = gallery.gallery_id
        self._cache_context_gallery = gallery_id
        self._cache_context_status = tk.StringVar(
            card, self._cache_description(gallery_id),
        )
        ttk.Label(
            card, textvariable=self._cache_context_status, style='Muted.TLabel',
            wraplength=PHOTOS_INFO_CARD_MAX_WIDTH,
        ).pack(anchor='w', fill='x', pady=(4, 2))
        active = self._preload_worker is not None and self._preload_worker.is_alive()
        if active:
            self._context_action(card, self._text('preload_cancel'), self._cancel_preload)
        else:
            self._context_action(
                card, self._text('preload'),
                lambda: self._launch_preload(card, session, gallery),
            )
            self._context_action(
                card, self._text('preload_thumbnails'),
                lambda: self._launch_preload(
                    card, session, gallery, thumbnails_only=True,
                ),
            )
        self._cache_pin_var = tk.BooleanVar(
            card, self._gallery_pins.get(gallery_id, False),
        )
        self._cache_pin_button = ttk.Checkbutton(
            card, text=self._text('keep_preloaded'), style='Card.TCheckbutton',
            variable=self._cache_pin_var,
            command=lambda: self._launch_preload(
                card, session, gallery,
                pinned=self._cache_pin_var.get(),
            ),
        )
        self._cache_pin_button.pack(anchor='w', pady=(4, 2))
        attach_inline_tooltip(
            self._cache_pin_button, self._text('keep_preloaded_hint'),
            self.winfo_toplevel(),
        )
        self._cache_pending.setdefault(gallery_id, (None, False))
        self._start_cache_probe()
        self._refresh_cache_widgets(gallery_id)

    def _launch_preload(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
        *, pinned: bool | None = None, thumbnails_only: bool = False,
    ) -> None:
        """Start one explicit, cancellable preload for the captured gallery.

        Args:
            card (GlassCard): Current menu containing the activated action.
            session (LinkingSession): Exact session captured at menu opening.
            gallery (LinkedGallery): Exact listed target of this action.
            pinned (bool | None): Set retention, or None to preserve it.
            thumbnails_only (bool): Explicitly fetch only direct thumbnails,
                preserving the original full-preload completion semantics.

        Returns:
            None: Starts work off Tk; disabling retention performs no download.
        """
        if (self._context_card is not card or self._session is not session
                or self._session_provider() is not session
                or self._galleries.get(gallery.gallery_id) is not gallery):
            return
        if self._preload_worker is not None and self._preload_worker.is_alive():
            self._preload_strip.grid(
                row=self._heading_preload_row,
                column=0, columnspan=3, sticky='ew',
            )
            return
        diagnostic_generation = self._generation
        self._update_preload_priority()
        self._preload_worker = GalleryPreloadWorker(
            session, gallery.gallery_id, pinned=pinned,
            download=pinned is not False,
            diagnostic_callback=lambda summary: self._record_preload_diagnostic(
                session, summary,
            ),
            thumbnails_only=thumbnails_only,
            yield_to_foreground=self._preload_foreground.is_set,
            foreground_reason=(
                lambda: self._preload_foreground_reason
            ),
        )
        self._preload_generation = diagnostic_generation
        self._preload_last = None
        self._preload_unpin = pinned is False
        self._preload_text.set(self._text('preload_listing'))
        self._preload_strip.grid(
            row=self._heading_preload_row,
            column=0, columnspan=3, sticky='ew', pady=(4, 0),
        )
        self._preload_bar.configure(mode='indeterminate')
        self._preload_bar.grid()
        self._preload_bar.start(PHOTOS_CATALOG_POLL_MS)
        self._set_preload_activity(True)
        self._preload_cancel.state(['!disabled'])
        self._preload_worker.start()
        self._dismiss_context()
        self._schedule_poll()

    def _update_preload_priority(self) -> None:
        """Publish foreground demand to preload without worker-side Tk reads.

        Returns:
            None: The background worker sees only a thread-safe Event. An
                already admitted bounded HTTP read may finish before yielding.
        """
        controller = getattr(self._viewer, '_gallery_viewer_controller', None)
        demanded = bool(self._upload_pipeline_waiting or
            (self._worker is not None and self._worker.is_alive())
            or self._has_pending_page or self._context_busy
            or (self._inspector_worker is not None
                and self._inspector_worker.is_alive())
            or self._inspector_pending is not None
        )
        reason = 'metadata' if demanded else ''
        if controller is not None and not controller._closed:
            urgent = bool(
                (controller._worker is not None
                 and controller._worker.is_alive()
                 and controller._worker_kind in (
                     'detail', 'prefetch', 'page', 'more', 'slide_more',
                     'audio', 'presentation',
                 ))
                or controller._pending_detail_index is not None
                or controller._pending_page_request is not None
                or controller._audio_pending is not None
            )
            if urgent:
                demanded = True
                reason = 'browsing'
        # Explicit preload shares serial admission with viewport and Watcher.
        # Speculative RAM/disk/overscan queues have no transport claim and must
        # not keep this event set indefinitely.
        self._preload_foreground_reason = reason
        if demanded:
            self._preload_foreground.set()
        else:
            self._preload_foreground.clear()

    def set_upload_pipeline_waiting(self, waiting: bool) -> None:
        """Suspend automatic media work while upload retry or review owns priority.

        Args:
            waiting (bool): Current queue backpressure snapshot from its worker.

        Returns:
            None: Yields preload/prefetch without disabling explicit Photos controls.
        """
        self._upload_pipeline_waiting = waiting
        if self._session is not None:
            self._session.set_upload_pipeline_waiting(waiting)
        self._update_preload_priority()

    def _show_preload_progress(self, progress: PreloadProgress) -> None:
        """Show truthful transfer counts and terminal reasons in the heading.

        Args:
            progress (PreloadProgress): Latest immutable worker snapshot.

        Returns:
            None: Keeps active work/cancellation visible across navigation;
                terminal results stay scoped to the exact selected gallery.
        """
        terminal = progress.phase in ('complete', 'partial', 'cancelled', 'error')
        previous_storage_witness = self._storage_preload_witness
        storage_witness = (
            progress.gallery_id, progress.cached,
            progress.stored, progress.stored_bytes,
            progress.phase,
        )
        storage_progress_changed = bool(
            previous_storage_witness is not None
            and previous_storage_witness[:4] != storage_witness[:4]
        )
        new_terminal_snapshot = bool(
            terminal and (previous_storage_witness is None
                          or previous_storage_witness != storage_witness)
        )
        self._storage_preload_witness = storage_witness
        if storage_progress_changed or new_terminal_snapshot:
            self._request_storage_refresh()
        different_gallery = self._selected_gallery_id != progress.gallery_id
        if different_gallery and terminal:
            self._preload_strip.grid_remove()
            self._set_preload_activity(False)
            return
        self._preload_strip.grid(
            row=self._heading_preload_row,
            column=0, columnspan=3, sticky='ew', pady=(4, 0),
        )
        gallery = self._galleries.get(progress.gallery_id)
        title = gallery.title if gallery is not None else ''
        phase = self._text(
            'preload_unpinned' if self._preload_unpin and progress.phase == 'complete'
            else 'preload_' + progress.phase,
        )
        if progress.phase == 'yielding' and progress.wait_reason == 'metadata':
            phase = self._text('preload_wait_metadata')
        if progress.phase == 'waiting' and progress.retry_after_seconds is not None:
            phase += ' · ' + self._text('preload_retry_countdown').format(
                seconds=progress.retry_after_seconds,
            )
        if progress.total is not None:
            if progress.thumbnails_only and not terminal:
                phase += ' · ' + self._text('preload_thumbnail_counts').format(
                    done=progress.completed, total=progress.total,
                    hits=progress.current_hits, stored=progress.stored,
                )
            else:
                phase += ' · ' + self._text('preload_counts').format(
                    done=progress.completed, total=progress.total,
                    cached=progress.cached, skipped=progress.skipped,
                )
        if progress.reason:
            phase += ' · ' + self._text('preload_reason_' + progress.reason)
        self._preload_text.set(
            (title + ': ' if different_gallery and title else '') + phase,
        )
        self._set_preload_activity(not terminal)
        self._preload_cancel_hint.set(self._text(
            'preload_close_status' if terminal else 'preload_cancel',
        ))
        if terminal:
            self._preload_bar.stop()
            self._preload_bar.grid_remove()
            self._preload_cancel.state(['!disabled'])
        elif progress.total is None:
            self._preload_bar.configure(mode='indeterminate')
            self._preload_bar.start(PHOTOS_CATALOG_POLL_MS)
        else:
            self._preload_bar.stop()
            self._preload_bar.grid()
            self._preload_bar.configure(
                mode='determinate', maximum=max(1, progress.total or 1),
                value=progress.completed,
            )

    def _set_preload_activity(self, active: bool) -> None:
        """Animate the existing smooth ring independently of photo progress.

        Args:
            active (bool): Whether listing, checks, waiting or transfer runs.
        Returns:
            None: Maintains one small animation timer and cancels it at rest.
        """
        if not active:
            if self._preload_activity_after is not None:
                self.after_cancel(self._preload_activity_after)
                self._preload_activity_after = None
            try:
                self._preload_activity.configure(image='', text='')
            except tk.TclError:
                # A frame Destroy callback can follow destruction of its label.
                pass
        elif self._preload_activity_after is None:
            self._animate_preload_activity()

    def _animate_preload_activity(self) -> None:
        """Advance pre-rendered activity artwork without cache or Pillow work.

        Returns:
            None: Schedules one bounded smooth-ring frame while work is active.
        """
        self._preload_activity_after = None
        if self._destroyed:
            return
        if self._catalogue_spinner_frames:
            self._preload_activity_index = (
                self._preload_activity_index + 1
            ) % len(self._catalogue_spinner_frames)
            self._preload_activity.configure(
                image=self._catalogue_spinner_frames[self._preload_activity_index],
            )
        else:
            self._preload_activity.configure(text='…')
        self._preload_activity_after = self.after(
            PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS, self._animate_preload_activity,
        )

    @staticmethod
    def _storage_bytes(value: int) -> str:
        """Format physical storage with consistent binary units.

        Args:
            value (int): Nonnegative observed physical bytes.
        Returns:
            str: Compact B/KiB/MiB/GiB text using powers of 1024.
        """
        scaled = float(value)
        for unit in ('B', 'KiB', 'MiB', 'GiB'):
            if scaled < 1024 or unit == 'GiB':
                return f'{scaled:.0f} {unit}' if unit == 'B' else f'{scaled:.1f} {unit}'
            scaled /= 1024
        return ''

    def _resize_storage(self, event: tk.Event) -> None:
        """Wrap storage text in a narrow viewer without moving other controls.

        Args:
            event (tk.Event): Storage heading's current mapped width.
        Returns:
            None: Updates text only when the layout mode changes.
        """
        narrow = (
            self._storage_parent is None
            and event.width < PHOTOS_HEADING_STACK_WIDTH
        )
        if narrow != self._storage_narrow:
            self._storage_narrow = narrow
            self._show_storage_snapshot()

    def _show_storage_snapshot(self) -> None:
        """Show total thumbnail count and bytes across both measured pools.

        Returns:
            None: Incomplete totals remain unknown; the detail keeps retained
                bytes separate from the ordinary thumbnail quota.
        """
        count, used, _limit, ready, error = self._storage_snapshot
        loading = not ready and not error
        if not loading:
            if self._storage_activity_after is not None:
                self.after_cancel(self._storage_activity_after)
                self._storage_activity_after = None
            self._storage_button.configure(image=self._storage_icon or '')
        elif self._storage_activity_after is None:
            self._animate_storage_activity()
        _, kept_count, kept_bytes, _, _other_bytes, kept_ready = (
            self._retained_storage_snapshot
        )
        total_count = (
            f'{count + kept_count:,}'
            if ready and count is not None and kept_ready and kept_count is not None
            else '—'
        )
        total_bytes = (
            self._storage_bytes(used + kept_bytes)
            if ready and used is not None and kept_ready and kept_bytes is not None
            else '—'
        )
        self._storage_text.set(
            '—' if total_count == '—' or total_bytes == '—' else
            ('' if self._storage_icon is not None else '▧ ')
            + total_count + ' · ' + total_bytes,
        )
        self._storage_hint.set(
            self._text('storage_scope_hint')
            + ('\n' + self._text('storage_unavailable') if error else ''),
        )

    def _animate_storage_activity(self) -> None:
        """Reuse prepared ring frames while initial storage accounting waits.

        Returns:
            None: Keeps one cheap Tk timer until a measured or unavailable
                snapshot arrives; no I/O or Pillow rendering occurs here.
        """
        self._storage_activity_after = None
        if (self._destroyed or self._storage_snapshot[3]
                or self._storage_snapshot[4]
                or not self._catalogue_spinner_frames):
            return
        self._storage_activity_index = (
            self._storage_activity_index + 1
        ) % len(self._catalogue_spinner_frames)
        self._storage_button.configure(
            image=self._catalogue_spinner_frames[self._storage_activity_index],
            compound='left',
        )
        self._storage_activity_after = self.after(
            PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS, self._animate_storage_activity,
        )

    def _request_storage_refresh(self) -> None:
        """Coalesce a live cache event into the existing storage observer.

        Returns:
            None: At most one owner worker remains active; a running observer
                gets a short continuation until its result and follow-up arrive.
        """
        if self._destroyed:
            return
        self._storage_refresh_requested = True
        if self._storage_refresh_waiting:
            return
        self._storage_refresh_waiting = True
        if self._storage_after is not None:
            self.after_cancel(self._storage_after)
            self._storage_after = None
        worker_active = (
            self._storage_worker is not None
            and self._storage_worker.is_alive()
        )
        delay = (
            PHOTOS_STORAGE_ACTIVE_POLL_MS if worker_active else 0
        )
        self._storage_after = self.after(delay, self._poll_storage)

    def _poll_storage(self) -> None:
        """Coalesce off-Tk accounting/RAM observations into a safe snapshot.

        Returns:
            None: Retains at most one worker and one result; teardown cancels
                recovery and the next timer. No scans or hashes run on Tk.
        """
        self._storage_after = None
        if self._destroyed:
            return
        try:
            snapshot, retained, ram, complete = self._storage_results.get_nowait()
            self._storage_snapshot = snapshot
            if retained[5] or not self._retained_storage_snapshot[5]:
                self._retained_storage_snapshot = retained
            if complete:
                self._storage_ram = ram
            self._show_storage_snapshot()
        except queue.Empty:
            pass
        worker_finished = (
            self._storage_worker is not None
            and not self._storage_worker.is_alive()
        )
        if worker_finished:
            self._storage_worker = None
            if (self._storage_worker_requested
                    and not self._storage_refresh_requested):
                self._storage_worker_requested = False
                self._storage_refresh_waiting = False
                self._storage_after = self.after(
                    PHOTOS_STORAGE_REFRESH_MS, self._poll_storage,
                )
                return
            self._storage_worker_requested = False
        if self._storage_worker is None:
            cancel = self._storage_cancel
            requested = self._storage_refresh_requested
            self._storage_refresh_requested = False

            def observe() -> None:
                """Read physical accounting without touching linked media.

                Returns:
                    None: Publishes one immutable storage/RAM observation.
                """
                try:
                    cache = GalleryMediaCache.for_current_user(
                        NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
                    )
                except Exception:  # noqa: BLE001
                    cache = None
                try:
                    snapshot = (cache.thumbnail_storage_snapshot(cancel)
                                if cache is not None else
                                (None, None, None, False, 'unavailable'))
                except Exception:  # noqa: BLE001
                    snapshot = (None, None, None, False, 'unavailable')
                partial = (
                    snapshot,
                    (None, None, None, None, None, False),
                    None,
                    False,
                )
                if not cancel.is_set():
                    publish_result(partial)
                try:
                    retained = (cache.retained_storage_snapshot(cancel)
                                if cache is not None else
                                (None, None, None, None, None, False))
                except Exception:  # noqa: BLE001
                    retained = (None, None, None, None, None, False)
                try:
                    ram = current_process_ram_bytes()
                except Exception:  # noqa: BLE001
                    ram = None
                if not cancel.is_set():
                    publish_result((snapshot, retained, ram, True))

            def publish_result(
                result: _StorageObservation,
            ) -> None:
                """Replace a stale queued observation with the latest phase.

                Args:
                    result (_StorageObservation): Ordinary and retained snapshots,
                        process RAM bytes and completion of retained/RAM work.

                Returns:
                    None: The bounded queue keeps only the newest observation.
                """
                if cancel.is_set():
                    return
                try:
                    self._storage_results.put_nowait(result)
                    return
                except queue.Full:
                    pass
                try:
                    self._storage_results.get_nowait()
                except queue.Empty:
                    pass
                try:
                    self._storage_results.put_nowait(result)
                except queue.Full:
                    pass

            self._storage_worker = threading.Thread(
                target=observe, name='photos-storage-observation', daemon=True,
            )
            self._storage_worker_requested = requested
            self._storage_worker.start()
        polling_interval = (
            PHOTOS_STORAGE_ACTIVE_POLL_MS
            if self._storage_refresh_waiting
            else PHOTOS_STORAGE_REFRESH_MS
        )
        self._storage_after = self.after(
            polling_interval, self._poll_storage,
        )

    def _open_storage_context(self) -> None:
        """Open anchored physical storage detail and shared quota settings.

        Returns:
            None: Reuses glass context/focus/Escape owners. RAM describes this
                process only; unavailable per-process VRAM stays unknown.
        """
        card = self._open_context(
            self._context_event(self._storage_button),
            surface=self._storage_surface,
        )
        self._context_content(card, self._text('storage_scope'))
        count, used, limit, ready, _error = self._storage_snapshot
        galleries, thumbs, thumb_bytes, others, other_bytes, kept_ready = (
            self._retained_storage_snapshot
        )
        ordinary_ready = (
            ready and count is not None and used is not None and limit is not None
        )
        kept_complete = kept_ready and all(value is not None for value in (
            galleries, thumbs, thumb_bytes, others, other_bytes,
        ))
        ordinary_hint = (
            self._text('storage_ordinary_detail').format(
                count=f'{count:,}', used=self._storage_bytes(used),
                limit=self._storage_bytes(limit),
            ) if ordinary_ready else self._text('storage_ordinary_unknown')
        )
        kept_hint = (
            self._text('storage_keep_detail').format(
                galleries=f'{galleries:,}', thumbs=f'{thumbs:,}',
                thumb_bytes=self._storage_bytes(thumb_bytes),
                others=f'{others:,}', other_bytes=self._storage_bytes(other_bytes),
            ) if kept_complete else self._text('storage_keep_unknown')
        )
        ordinary_value = (
            f'{count:,} · {self._storage_bytes(used)} / {self._storage_bytes(limit)}'
            if ordinary_ready else '—'
        )
        kept_value = (
            f'{galleries:,} · {thumbs:,} · {self._storage_bytes(thumb_bytes)}'
            f'  /  {others:,} · {self._storage_bytes(other_bytes)}'
            if kept_complete else '—'
        )
        ram = '—' if self._storage_ram is None else self._storage_bytes(self._storage_ram)
        rows = (
            ('local', '▧', ordinary_value, ordinary_hint),
            ('pin', '⌖', kept_value, kept_hint),
            ('list', '≡', ram, self._text('storage_ram').format(value=ram)),
            ('info', 'ⓘ', '—', self._text('storage_vram')),
        )
        for icon, fallback, value, hint in rows:
            row = ttk.Frame(card, style='Card.TFrame')
            row.pack(anchor='w', fill='x', pady=(3, 0))
            artwork = create_smooth_icon(row, icon, (16, 16), tone='primary')
            mark = ttk.Label(
                row, image=artwork or '', text='' if artwork else fallback,
                style='Card.TLabel',
            )
            mark.image = artwork
            mark.pack(side='left', padx=(0, 5))
            label = ttk.Label(row, text=value, style='Card.TLabel')
            label.pack(side='left')
            hint_var = tk.StringVar(row, hint)
            row.storage_hint = hint_var
            for widget in (mark, label):
                attach_inline_tooltip(widget, hint_var, self._storage_surface or self.viewer_pane)
            if icon == 'local' and ordinary_ready and limit > 0:
                # A small quota proportion is visual only; accounting remains in
                # the storage observer and retained files remain outside the limit.
                bar = tk.Canvas(
                    row, width=PHOTOS_STORAGE_BAR_WIDTH,
                    height=PHOTOS_STORAGE_BAR_HEIGHT, highlightthickness=0,
                    background=_PALETTE['line'],
                )
                bar.pack(side='left', padx=(6, 0))
                bar.create_rectangle(
                    0, 0, round(PHOTOS_STORAGE_BAR_WIDTH * min(1.0, used / limit)),
                    PHOTOS_STORAGE_BAR_HEIGHT,
                    fill=_PALETTE['primary'], outline='',
                )
                attach_inline_tooltip(bar, hint_var, self._storage_surface or self.viewer_pane)
        quota_row = ttk.Frame(card, style='Card.TFrame')
        quota_row.pack(anchor='w', fill='x', pady=(6, 0))
        quota_icon = create_smooth_icon(quota_row, 'settings', (16, 16), tone='primary')
        quota_label = ttk.Label(
            quota_row, image=quota_icon or '', text='' if quota_icon else '⚙',
            style='Card.TLabel',
        )
        quota_label.image = quota_icon
        quota_label.pack(side='left', padx=(0, 5))
        quota_hint = tk.StringVar(quota_row, self._text('storage_limit'))
        quota_row.storage_hint = quota_hint
        attach_inline_tooltip(quota_label, quota_hint, self._storage_surface or self.viewer_pane)
        quota = tk.StringVar(card)
        current_limit = self._storage_snapshot[2]
        quota.set(str((current_limit or THUMBNAIL_DISK_DEFAULT_BYTES) // 1024**3))
        selector = ttk.Combobox(
            quota_row, textvariable=quota, state='readonly', width=4,
            values=[str(value) for value in THUMBNAIL_CACHE_LIMIT_CHOICES_GIB],
        )
        selector.pack(side='left')
        ttk.Label(quota_row, text='GiB', style='Muted.TLabel').pack(side='left', padx=(4, 0))
        attach_inline_tooltip(selector, quota_hint, self._storage_surface or self.viewer_pane)
        status = ttk.Label(card, text='', style='Muted.TLabel')
        selector.bind(
            '<<ComboboxSelected>>',
            lambda _event: self._save_storage_limit(quota, selector, status),
        )
        selector.bind(
            '<Return>',
            lambda _event: self._save_storage_limit(quota, selector, status),
        )
        self._tag_context_widget(selector)
        self._place_context(card)

    def _save_storage_limit(
        self, quota: tk.StringVar, selector: ttk.Combobox, status: ttk.Label,
    ) -> None:
        """Persist one selected quota off Tk while preventing competing writes.

        Args:
            quota (tk.StringVar): Selected ordinary thumbnail limit in GiB.
            selector (ttk.Combobox): Read-only selector disabled during the write.
            status (ttk.Label): Inline error destination in the originating card.
        Returns:
            None: Restores the previous selection on failure and refreshes the
                observation after a successful configuration write.
        """
        previous = (self._storage_snapshot[2] or THUMBNAIL_DISK_DEFAULT_BYTES) // 1024**3
        try:
            value = int(quota.get())
        except ValueError:
            quota.set(str(previous))
            return
        if value not in THUMBNAIL_CACHE_LIMIT_CHOICES_GIB or self._context_busy:
            quota.set(str(previous))
            return
        if value == previous:
            return
        selector.configure(state='disabled')
        status.configure(text='')
        status.pack_forget()
        self._context_busy = True

        def finish(success: bool) -> None:
            """Reconcile the saved quota and restore the still-visible control.

            Args:
                success (bool): Whether the configuration owner saved the value.
            Returns:
                None: Publishes a fresh observation or an inline failure.
            """
            if success:
                count, used, _limit, ready, error = self._storage_snapshot
                self._storage_snapshot = (count, used, value * 1024**3, ready, error)
                self._request_storage_refresh()
            if selector.winfo_exists():
                if not success:
                    quota.set(str(previous))
                    status.configure(text=self._text('storage_save_failed'))
                    status.pack(anchor='w')
                selector.configure(state='readonly')

        def save() -> None:
            """Write one quota on a worker and deliver its result to Tk.

            Returns:
                None: Enqueues exactly one success or failure callback.
            """
            try:
                success = set_thumbnail_cache_limit_gib(value)
            except Exception:
                success = False
            self._context_results.put((None, lambda: finish(success)))

        try:
            threading.Thread(target=save, name='photos-storage-quota', daemon=True).start()
        except RuntimeError:
            self._context_busy = False
            finish(False)
            return
        self._schedule_poll()

    def _cancel_preload(self) -> None:
        """Cancel new reads, or dismiss a completed preload status strip.

        Returns:
            None: Keeps any explicitly pinned bytes and existing cache contents.
        """
        if self._preload_worker is not None and self._preload_worker.is_alive():
            self._preload_worker.stop()
            self._preload_text.set(self._text('preload_cancelling'))
            self._preload_cancel.state(['disabled'])
        else:
            self._preload_strip.grid_remove()
            self._set_preload_activity(False)
            self._preload_last = None

    def _cache_glyph(self, gallery_id: int) -> str:
        """Return the icon for the latest local coverage check.

        Args:
            gallery_id (int): Exact gallery being displayed.

        Returns:
            str: Cloud, partial/local circle, or dotted circle when unchecked;
                a pin marks a locally retained gallery.
        """
        glyph = {"cloud": "☁", "local": "●", "partial": "◐"}.get(
            self._cache_state.get(gallery_id, "unknown"), "◌",
        )
        return ('PIN ' if self._gallery_pins.get(gallery_id) is True else '') + glyph

    def _gallery_info(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Add an explicit action for fetching gallery information.

        Args:
            card (GlassCard): Current gallery context surface.
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Gallery whose direct photos should be checked.

        Returns:
            None: Server metadata is requested only after activation.
        """
        self._context_action(
            card, "i " + self._text("gallery_info"),
            lambda: self._fetch_gallery_info(card, session, gallery_id),
        )

    def _fetch_gallery_info(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Fetch gallery details and the existing local cache coverage on demand.

        Args:
            card (GlassCard): Context card that initiated the request.
            session (LinkingSession): Exact current linked session.
            gallery_id (int): Physical gallery identity.

        Returns:
            None: A detail card replaces the menu when the worker completes.
        """
        cancel = threading.Event()
        self._probe_cancel = cancel

        def read() -> Callable[[], None]:
            """Read validated detail fields and fallback inventory metadata.

            Returns:
                Callable[[], None]: Tk callback rendering this gallery's result.
            """
            details: LinkedMediaDetails | None = None
            method = getattr(session, "media_gallery_details", None)
            if callable(method):
                try:
                    details = method(gallery_id, cancelled=cancel)
                except Exception:
                    details = None
            try:
                info, state, visible = self._inspect_gallery_cache(
                    session, gallery_id, cancel,
                    include_admin_inventory=details is None,
                )
            except Exception:
                info, state, visible = None, "unknown", None
            return lambda: self._show_gallery_details(
                card, gallery_id, details, info, state, visible,
            )

        self._run_context(
            card, read,
            lambda: self._show_gallery_details(
                card, gallery_id, None, None, "unknown", None,
            ),
        )

    def _inspect_gallery_cache(
        self, session: LinkingSession, gallery_id: int, cancel: threading.Event,
        *, include_admin_inventory: bool = True,
    ) -> tuple[LinkedMediaGalleryInfo | None, str, int]:
        """Read direct metadata and inspect cache entries without media downloads.

        Args:
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Gallery whose authorized photos are inspected.
            cancel (threading.Event): Context dismissal and session change signal.
            include_admin_inventory (bool): Whether to use the legacy counts API.

        Returns:
            tuple[LinkedMediaGalleryInfo | None, str, int]: Optional Admin
                inventory, observed cache status and visible photo count.
        """
        info = (
            session.media_gallery_info(gallery_id, cancelled=cancel)
            if include_admin_inventory and session.can_read_admin_media()
            else None
        )
        after = None
        any_cached = False
        all_cached = True
        seen = 0
        for _page_index in range(PHOTOS_CACHE_PAGE_LIMIT):
            if cancel.is_set():
                break
            page = session.media_page(gallery_id, after, cancelled=cancel)
            for photo in page.photos:
                if cancel.is_set():
                    break
                detail = session.has_cached_photo(gallery_id, photo)
                thumb = session.has_cached_photo(gallery_id, photo, thumbnail=True)
                any_cached |= detail or thumb
                all_cached &= detail and thumb
                seen += 1
            after = page.next_after
            if after is None:
                break
        complete = (
            not cancel.is_set() and after is None
            and (info is None or seen == info.photo_count)
        )
        state = (
            "local" if complete and seen and all_cached
            else "partial" if any_cached
            else "cloud" if complete else "unknown"
        )
        return info, state, seen

    def _show_gallery_details(
        self, card: GlassCard, gallery_id: int,
        details: LinkedMediaDetails | None,
        info: LinkedMediaGalleryInfo | None, state: str,
        visible_count: int | None,
    ) -> None:
        """Render grouped gallery metadata with cache and legacy fallback.

        Args:
            card (GlassCard): Context card that requested the information.
            gallery_id (int): Exact gallery represented by the card.
            details (LinkedMediaDetails | None): Optional current server fields.
            info (LinkedMediaGalleryInfo | None): Legacy Admin inventory.
            state (str): Observed local-cache coverage state.
            visible_count (int | None): Visible count, or None on read error.

        Returns:
            None: The active card shows available fields and cache status.
        """
        if card is not self._context_card:
            return
        fields: LinkedMediaDetails = dict(details or {})
        if info is not None:
            fields.setdefault("photo_count", info.photo_count)
            fields.setdefault("file_count", info.file_count)
            fields.setdefault("size", info.size)
        if visible_count is not None:
            fields.setdefault("photo_count", visible_count)
        self._cache_state[gallery_id] = state
        self._cache_counts.pop(gallery_id, None)
        self._refresh_cache_widgets(gallery_id)
        if gallery_id in self._galleries:
            self._render_tree([gallery_id])
        groups = [
            ("photos_workspace_group_inventory", [
                ("photos_workspace_field_photo_count", fields.get("photo_count")),
                ("photos_workspace_field_file_count", fields.get("file_count")),
                ("photos_workspace_field_size", fields.get("size")),
            ]),
            ("photos_workspace_group_gallery", [
                ("photos_workspace_field_title", fields.get("title")),
                ("photos_workspace_field_description", fields.get("description")),
                ("photos_workspace_field_child_count", fields.get("child_count")),
                ("photos_workspace_field_created_at", fields.get("created_at")),
                ("photos_workspace_field_updated_at", fields.get("updated_at")),
            ]),
            ("photos_workspace_group_access", [
                ("photos_workspace_field_visibility", fields.get("visibility")),
                ("photos_workspace_field_nsfw", fields.get("nsfw")),
                ("photos_workspace_field_listed", fields.get("listed")),
            ]),
            ("photos_workspace_group_thumbnail", [
                ("photos_workspace_field_thumbnail_settings",
                 self._thumbnail_settings(fields)),
            ]),
        ]
        suffix = (self._text("cache_status") + ": "
                  + self._text("cache_" + state))
        if details is None:
            suffix += "\n" + self._text("details_fallback")
        if self._probe_cancel is not None:
            self._probe_cancel = None
        self._render_info_card(
            card, self._text("gallery_info"), groups,
            suffix=suffix,
        )

    def _show_details(
        self, card: GlassCard, title: str, fields: LinkedMediaDetails, *,
        session: LinkingSession, fallback: bool,
    ) -> None:
        """Render grouped photo metadata while protecting location information.

        Args:
            card (GlassCard): Context card that requested the information.
            title (str): Localized card title.
            fields (LinkedMediaDetails): Bounded scalar metadata from the server.
            session (LinkingSession): Current permission scope for sensitive rows.
            fallback (bool): Whether local descriptor fields were used.

        Returns:
            None: Only fields approved for the live scope are displayed.
        """
        if card is not self._context_card:
            return
        if self._probe_cancel is not None:
            self._probe_cancel = None
        groups = self._photo_info_groups(fields, session)
        self._render_info_card(
            card, title, groups,
            suffix=(self._text("details_fallback") if fallback else ""),
        )

    def _photo_info_groups(
        self, fields: LinkedMediaDetails, session: LinkingSession,
    ) -> list[_InfoGroup]:
        """Prepare the same authorized metadata groups for both Photos views.

        Args:
            fields (LinkedMediaDetails): Bounded scalar metadata response.
            session (LinkingSession): Scope controlling sensitive location rows.

        Returns:
            list[_InfoGroup]: Ordered localized group keys and typed field rows.
        """
        file_rows: list[_InfoRow] = []
        description_rows: list[_InfoRow] = []
        date_rows: list[_InfoRow] = []
        camera_rows: list[_InfoRow] = []
        location_rows: list[_InfoRow] = []
        access_rows: list[_InfoRow] = []
        mapping: tuple[tuple[list[_InfoRow], str], ...] = (
            (file_rows, "title"), (file_rows, "filename"),
            (file_rows, "mime_type"), (file_rows, "size"),
            (file_rows, "width"), (file_rows, "height"),
            (file_rows, "display_width"), (file_rows, "display_height"),
            (file_rows, "exif_orientation"),
            (date_rows, "created_at"), (date_rows, "modified_at"),
            (date_rows, "exif_taken_at"),
            (camera_rows, "exif_camera_make"),
            (camera_rows, "exif_camera_model"),
            (camera_rows, "exif_lens_model"),
            (camera_rows, "exif_focal_length"),
            (camera_rows, "exif_aperture"),
            (camera_rows, "exif_exposure_time"), (camera_rows, "exif_iso"),
            (description_rows, "description"),
            (location_rows, "gps_lat"), (location_rows, "gps_lng"),
            (location_rows, "gps_altitude"),
            (access_rows, "visibility"), (access_rows, "nsfw"),
        )
        for rows, key in mapping:
            value = fields.get(key)
            if value is not None:
                rows.append(("photos_workspace_field_" + key, value))
        derivative = self._thumbnail_settings(fields)
        if derivative is not None:
            file_rows.append((
                "photos_workspace_field_thumbnail_settings", derivative,
            ))
        groups = [
            ("photos_workspace_group_file", file_rows),
            ("photos_workspace_group_description", description_rows),
            ("photos_workspace_group_dates", date_rows),
            ("photos_workspace_group_camera", camera_rows),
        ]
        if session.can_read_admin_media():
            groups.append(("photos_workspace_group_location", location_rows))
        groups.append(("photos_workspace_group_access", access_rows))
        return groups

    def _thumbnail_settings(
        self, fields: LinkedMediaDetails,
    ) -> str | None:
        """Describe configured quality for future generated thumbnails.

        Args:
            fields (LinkedMediaDetails): Detail response with optional qualities.

        Returns:
            str | None: Explicit JPEG/WebP settings, or None when absent.
        """
        jpeg, webp = fields.get("jpeg_quality"), fields.get("webp_quality")
        if jpeg is None and webp is None:
            return None
        qualities = []
        if jpeg is not None:
            qualities.append("JPEG " + str(jpeg))
        if webp is not None:
            qualities.append("WebP " + str(webp))
        return " · ".join(qualities)

    def _render_info_card(
        self, card: GlassCard, title: str, groups: list[_InfoGroup], *,
        suffix: str = "",
    ) -> None:
        """Replace a context menu with compact, wrapped metadata rows.

        Args:
            card (GlassCard): Active anchored information surface.
            title (str): Localized heading.
            groups (list[_InfoGroup]): Ordered localized headings and rows.
            suffix (str): Optional cache state or compatibility note.

        Returns:
            None: Rows wrap to fit the current Photos workspace width.
        """
        if card is not self._context_card:
            return
        for child in card.winfo_children():
            child.destroy()
        width = max(160, min(
            PHOTOS_INFO_CARD_MAX_WIDTH - 40, self.winfo_width() - 60,
        ))
        body = ttk.Frame(card, style="Card.TFrame")
        canvas = tk.Canvas(
            body, highlightthickness=0, borderwidth=0,
            background=ttk.Style().lookup("Card.TLabel", "background"),
        )
        scrollbar = ttk.Scrollbar(body, orient="vertical", command=canvas.yview)
        canvas.configure(yscrollcommand=scrollbar.set)
        body.pack(fill="both", expand=True)
        canvas.pack(side="left", fill="both", expand=True)
        scrollbar.pack(side="right", fill="y")
        content = ttk.Frame(canvas, style="Card.TFrame", padding=(2, 1))
        content_id = canvas.create_window(
            (0, 0), window=content, anchor="nw", width=width,
        )
        for widget in (body, canvas, scrollbar, content):
            self._tag_context_widget(widget)

        def scroll_detail(event: tk.Event, *, precise: bool = False) -> None:
            """Scroll the metadata view by native wheel or touchpad input.

            Args:
                event (tk.Event): Native Tk wheel/precise event.
                precise (bool): Whether this is Tk's pixel delta event.

            Returns:
                None: Wheel motion affects only this card's metadata viewport.
            """
            delta = vertical_scroll_delta(self, event, precise=precise)
            if precise:
                bounds = canvas.bbox("all")
                if bounds is None:
                    return
                content_height = bounds[3] - bounds[1]
                if content_height > 0 and delta:
                    current = canvas.yview()[0]
                    canvas.yview_moveto(max(
                        0.0, min(1.0, current - delta / content_height),
                    ))
            elif delta:
                canvas.yview_scroll(-1 if delta > 0 else 1, "units")

        def bind_scroll(widget: tk.Misc) -> None:
            """Bind scroll input directly to each metadata row.

            Args:
                widget (tk.Misc): Row or canvas receiving pointer events.

            Returns:
                None: Tk 9 precise input is optional on older supported runtimes.
            """
            widget.bind("<MouseWheel>", scroll_detail, add="+")
            widget.bind("<Button-4>", scroll_detail, add="+")
            widget.bind("<Button-5>", scroll_detail, add="+")
            try:
                widget.bind(
                    "<TouchpadScroll>",
                    lambda event: scroll_detail(event, precise=True), add="+",
                )
            except tk.TclError:
                pass

        bind_scroll(canvas)
        ttk.Label(
            content, text=title, style="Card.TLabel", wraplength=width,
        ).pack(fill="x")
        self._tag_context_widget(content.winfo_children()[-1])
        bind_scroll(content.winfo_children()[-1])
        for heading, rows in groups:
            rows = [(key, value) for key, value in rows if value is not None]
            if not rows:
                continue
            ttk.Label(
                content,
                text=self._text(heading.removeprefix("photos_workspace_")),
                style="Muted.TLabel",
            ).pack(anchor="w", pady=(6, 1))
            self._tag_context_widget(content.winfo_children()[-1])
            bind_scroll(content.winfo_children()[-1])
            for key, value in rows:
                label = self._text(key.removeprefix("photos_workspace_"))
                rendered = self._format_detail_value(key, value)
                ttk.Label(
                    content, text=label + ": " + rendered,
                    style="Card.TLabel", wraplength=width,
                    justify="left",
                ).pack(fill="x", anchor="w")
                self._tag_context_widget(content.winfo_children()[-1])
                bind_scroll(content.winfo_children()[-1])
        if suffix:
            ttk.Label(
                content, text=suffix, style="Muted.TLabel",
                wraplength=width, justify="left",
            ).pack(fill="x", pady=(6, 0))
            self._tag_context_widget(content.winfo_children()[-1])
            bind_scroll(content.winfo_children()[-1])
        content.update_idletasks()
        def resize_content(_event: tk.Event) -> None:
            """Update scroll bounds after metadata rows change.

            Args:
                _event (tk.Event): Content frame geometry notification.

            Returns:
                None: The scrollbar follows the measured content height.
            """
            canvas.configure(scrollregion=canvas.bbox("all"))

        def resize_canvas(event: tk.Event) -> None:
            """Keep the content width matched to the viewport.

            Args:
                event (tk.Event): Canvas geometry notification.

            Returns:
                None: Wrapped values use the available narrow or wide width.
            """
            canvas.itemconfigure(content_id, width=event.width)

        content.bind("<Configure>", resize_content, add="+")
        canvas.bind("<Configure>", resize_canvas, add="+")
        content_height = content.winfo_reqheight() + 6
        maximum_height = min(
            PHOTOS_INFO_CARD_MAX_HEIGHT - 20,
            max(100, self.winfo_height() - 24),
            content_height,
        )
        body.configure(height=maximum_height, width=width + 22)
        body.pack_propagate(False)
        card.update_idletasks()
        card.focus_set()
        self._place_context(card)

    def _format_detail_value(self, key: str, value: str | int | float | bool) -> str:
        """Format validated scalar metadata for a readable localized row.

        Args:
            key (str): Stable metadata field key.
            value (str | int | float | bool): Validated scalar from the server.

        Returns:
            str: Safe readable value with byte sizes and visibility localized.
        """
        if key.endswith("_size") or key == "photos_workspace_field_size":
            if type(value) is int:
                return self._format_bytes(value)
        if isinstance(value, bool):
            return self._text("yes" if value else "no")
        if key.endswith("_gps_altitude") and isinstance(value, (int, float)):
            return f"{value:g} m"
        if key.endswith("_visibility"):
            visibility = "unpublished" if value == "draft" else str(value)
            return self._text("visibility_state_" + visibility)
        return "".join(char if char.isprintable() else " " for char in str(value))

    def _gallery_visibility(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Read the stored gallery state before showing explicit eye actions.

        Args:
            card (GlassCard): Originating gallery context card.
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Selected physical gallery ID.

        Returns:
            None: Shows choices only after a validated revision is available.
        """
        cancel = self._cancel

        def read() -> Callable[[], None]:
            """Read the current one-use gallery visibility snapshot.

            Returns:
                Callable[[], None]: Tk callback displaying the fresh choices.
            """
            detail = session.gallery_detail(gallery_id, cancelled=cancel)
            return lambda: self._show_gallery_visibility(card, session, detail)

        self._run_context(
            card, read, lambda: self._context_status(card, "info_unavailable"),
        )

    def _show_gallery_visibility(
        self, card: GlassCard, session: LinkingSession, detail: LinkedGalleryDetail,
    ) -> None:
        """Offer exact gallery visibility choices for a verified revision.

        Args:
            card (GlassCard): Originating gallery context surface.
            session (LinkingSession): Session that read the snapshot.
            detail (LinkedGalleryDetail): Exact revision and stored visibility.

        Returns:
            None: Available explicit actions replace the loading heading.
        """
        if card is not self._context_card:
            return
        current = "unpublished" if detail.visibility == "draft" else detail.visibility
        self._context_content(
            card, "👁 " + self._text("visibility_state_" + current),
        )
        cancel = self._cancel
        for choice in ("public", "private", "unpublished"):
            if choice == current:
                continue
            self._context_action(
                card, self._text("visibility_" + choice),
                lambda choice=choice: self._run_context(
                    card,
                    lambda: self._change_gallery(session, detail, choice, cancel, card),
                    lambda: self._visibility_done(card, detail.gallery_id, False),
                ),
            )
        self._place_context(card)

    def _change_gallery(
        self, session: LinkingSession, detail: LinkedGalleryDetail, choice: str,
        cancel: threading.Event, card: GlassCard,
    ) -> Callable[[], None]:
        """Submit a gallery visibility edit once against the reviewed state.

        Args:
            session (LinkingSession): Current linked session.
            detail (LinkedGalleryDetail): Previously presented exact snapshot.
            choice (str): Explicit target visibility.
            cancel (threading.Event): Workspace session lifetime signal.
            card (GlassCard): Originating card for the result.

        Returns:
            Callable[[], None]: Tk callback for a confirmed result.
        """
        session.change_gallery_visibility(detail, choice, cancelled=cancel)
        return lambda: self._visibility_done(card, detail.gallery_id, True)

    def _write_photo_visibility(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        photo: LinkedPhoto, target: str,
    ) -> None:
        """Submit the selected photo's explicit visibility action.

        Args:
            card (GlassCard): Originating photo context surface.
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Direct owner of the clicked photo.
            photo (LinkedPhoto): Authorized photo row containing its current state.
            target (str): Explicit public or private target.

        Returns:
            None: One request runs asynchronously against the exact photo.
        """
        cancel = self._cancel

        def write() -> Callable[[], None]:
            """Request one revision-checked photo visibility change.

            Returns:
                Callable[[], None]: Tk callback for a confirmed result.
            """
            session.change_photo_visibility(gallery_id, photo, target, cancelled=cancel)
            return lambda: self._visibility_done(card, gallery_id, True)

        self._run_context(
            card, write, lambda: self._visibility_done(card, gallery_id, False),
        )

    def _visibility_done(self, card: GlassCard, gallery_id: int, saved: bool) -> None:
        """Refresh authoritative media after either a confirmed or uncertain edit.

        Args:
            card (GlassCard): Originating contextual surface.
            gallery_id (int): Exact owner of the submitted visibility action.
            saved (bool): Whether the server acknowledged and verified the edit.

        Returns:
            None: Keeps the gallery selection and avoids replaying the write.
        """
        previous_cache = self._cache_state
        self._cache_state = {}
        self._render_tree(list(previous_cache))
        self._thumbnail_memory.clear()
        self._thumbnail_memory = GalleryThumbnailMemory()
        self._folder_shelf.show_children(None, None, ())
        gallery = self._galleries.get(gallery_id)
        if gallery is not None and self._selected_gallery_id == gallery_id:
            if card is self._context_card:
                self._context_origin = self.tree
            self._select_gallery(gallery)
        else:
            self._sync_subgalleries()
        self._context_status(card, "saved" if saved else "visibility_uncertain")

    def _creation_form(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
    ) -> None:
        """Collect a gallery title inline for the explicitly chosen location.

        Args:
            card (GlassCard): Context surface for the action.
            session (LinkingSession): Exact active session with create consent.
            parent_id (int | None): Clicked parent gallery, or web root.

        Returns:
            None: Shows a title field or a pending acknowledgement.
        """
        if self._create_receipt is not None:
            self._creation_completed(card, session, self._create_receipt)
            return
        key = "create_root" if parent_id is None else "create_child"
        self._context_content(card, self._text(key))
        if parent_id is not None:
            parent = self._galleries[parent_id]
            ttk.Label(
                card, text=parent.title, style="Card.TLabel",
                wraplength=PHOTOS_TREE_WIDTH,
            ).pack(fill="x")
        entry = ttk.Entry(card)
        entry.pack(fill="x", pady=(4, 0))
        action = self._context_action(
            card, self._text("create"),
            lambda: self._create_gallery(card, session, parent_id, entry.get().strip()),
        )
        entry.bind("<Return>", lambda _event: action.invoke())
        self._place_context(card)
        entry.focus_set()

    def _create_gallery(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str,
    ) -> None:
        """Inspect saved work before dispatching one Photos gallery creation.

        Args:
            card (GlassCard): Originating name form.
            session (LinkingSession): Exact verified session at submission.
            parent_id (int | None): Chosen parent ID, or root.
            title (str): Entered physical gallery title.

        Returns:
            None: A saved operation is checked, resolved or confirmed inline.
        """
        manager = self._manager
        if not title or manager is None:
            return
        try:
            valid_title = (
                bool(title.strip(' \t\n\r\x00\x0b'))
                and len(title.encode('utf-8')) <= LINKING_CREATE_TITLE_MAX_BYTES
                and not any(ord(char) < 32 or ord(char) == 127 for char in title)
            )
        except UnicodeError:
            valid_title = False
        if not valid_title:
            if card is self._context_card:
                self._context_content(card, self._text('create_invalid_title'))
                self._place_context(card)
            return
        identity = manager.photos_site_identity(session)
        if (identity is None or not self._creation_context_is_current(
                card, session, parent_id, identity)):
            self._creation_context_changed(card)
            return

        def inspect() -> Callable[[], None]:
            """Read the key-free journal before any new operation is sent.

            Returns:
                Callable[[], None]: Tk callback routing the validated state.
            """
            try:
                snapshot = manager.inspect_photos_gallery_creation()
            except Exception as error:
                manager.record_photos_creation_failure('inspect', error)
                return lambda: self._creation_check_failed(
                    card,
                    lambda: self._retry_photos_create_check(
                        card, session, parent_id, title, preflight=True,
                    ),
                )
            return lambda: self._route_photos_create_snapshot(
                card, session, parent_id, title, identity, snapshot,
            )

        self._run_context(
            card, inspect,
            lambda: self._creation_check_failed(
                card,
                lambda: self._retry_photos_create_check(
                    card, session, parent_id, title, preflight=True,
                ),
            ),
        )

    def _creation_context_is_current(
        self, card: GlassCard, session: LinkingSession,
        parent_id: int | None, identity: tuple[str, int],
    ) -> bool:
        """Recheck the exact context, live account and direct parent on Tk.

        Args:
            card (GlassCard): Context card captured for the submitted draft.
            session (LinkingSession): Exact live session captured by Photos.
            parent_id (int | None): Chosen direct parent, or the web root.
            identity (tuple[str, int]): Canonical issuer and verified account.

        Returns:
            bool: True while the same card/session and parent remain available.
        """
        manager = self._manager
        if (manager is None or card is not self._context_card
                or self._session is not session
                or self._session_provider() is not session
                or not session.available() or not session.can_create_gallery()
                or manager.photos_site_identity(session) != identity):
            return False
        if parent_id is None:
            return True
        parent = self._galleries.get(parent_id)
        return (parent is not None and parent.gallery_id == parent_id
                and self.tree.exists(str(parent_id)))

    def _route_photos_create_snapshot(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str, identity: tuple[str, int],
        snapshot: CreateJournalSnapshot,
    ) -> None:
        """Choose the safe Photos action for the inspected journal state.

        Args:
            card (GlassCard): Exact current create surface.
            session (LinkingSession): Session captured by the submitted draft.
            parent_id (int | None): Exact selected parent or web root.
            title (str): Current user-entered title.
            identity (tuple[str, int]): Captured current issuer and account.
            snapshot (CreateJournalSnapshot): Key-free profile state.

        Returns:
            None: Only an empty journal dispatches a new request automatically.
        """
        if not self._creation_context_is_current(
            card, session, parent_id, identity,
        ):
            self._creation_context_changed(card)
            return
        if snapshot.state == 'empty':
            self._start_photos_gallery_create(
                card, session, parent_id, title, identity,
            )
            return
        if (snapshot.issuer is None or type(snapshot.account_id) is not int
                or snapshot.account_id <= 0 or snapshot.title is None):
            self._creation_status_unconfirmed(
                card, session, parent_id, title,
            )
            return
        if snapshot.state == 'pending':
            if snapshot.issuer == identity[0] and snapshot.account_id == identity[1]:
                self._start_photos_gallery_status(
                    card, session, parent_id, title, identity, snapshot,
                    already_exists=False,
                )
            else:
                self._creation_pending_at_other_site(card, snapshot.issuer)
            return
        if snapshot.state != 'completed' or snapshot.receipt is None:
            self._creation_status_unconfirmed(
                card, session, parent_id, title,
            )
            return
        same_original = (
            snapshot.issuer == identity[0]
            and snapshot.account_id == identity[1]
            and snapshot.parent_id == parent_id
            and snapshot.title.strip(' \t\n\r\x00\x0b')
            == title.strip(' \t\n\r\x00\x0b')
        )
        if same_original:
            self._start_photos_gallery_status(
                card, session, parent_id, title, identity, snapshot,
                already_exists=True,
            )
            return
        self._offer_continue_after_prior_completion(
            card, session, parent_id, title, identity, snapshot,
        )

    def _start_photos_gallery_status(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str, identity: tuple[str, int],
        snapshot: CreateJournalSnapshot, *, already_exists: bool,
    ) -> None:
        """Revalidate only a same-account saved operation off the Tk thread.

        Args:
            card (GlassCard): Current create context.
            session (LinkingSession): Exact currently approved session.
            parent_id (int | None): Draft parent used for stale-context checks.
            title (str): Draft title used for stale-context checks.
            identity (tuple[str, int]): Captured current site/account binding.
            snapshot (CreateJournalSnapshot): Matching saved journal state.
            already_exists (bool): Report a matching completed receipt as an
                existing gallery instead of creating or describing it anew.

        Returns:
            None: A fresh server status is required before displaying a receipt.
        """
        manager = self._manager
        if manager is None or not self._creation_context_is_current(
            card, session, parent_id, identity,
        ):
            self._creation_context_changed(card)
            return

        def check_status() -> Callable[[], None]:
            """Check the matching operation without resending its create key.

            Returns:
                Callable[[], None]: Tk callback for the verified result.
            """
            try:
                receipt = manager.reconcile_photos_gallery_creation(
                    session, snapshot,
                )
            except Exception as error:
                manager.record_photos_creation_failure('status', error)
                return lambda: self._creation_status_unconfirmed(
                    card, session, parent_id, title,
                )
            if receipt is None:
                return lambda: self._creation_status_unconfirmed(
                    card, session, parent_id, title,
                )
            if already_exists:
                return lambda: self._creation_completed(
                    card, session, receipt, already_exists=True,
                )
            return lambda: self._creation_completed(card, session, receipt)

        self._run_context(
            card, check_status,
            lambda: self._creation_status_unconfirmed(
                card, session, parent_id, title,
            ),
        )

    def _offer_continue_after_prior_completion(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str, identity: tuple[str, int],
        snapshot: CreateJournalSnapshot,
    ) -> None:
        """Ask before clearing an older completion and creating this draft.

        Args:
            card (GlassCard): Current title-entry context.
            session (LinkingSession): Current live create-authorized session.
            parent_id (int | None): Current draft parent or web root.
            title (str): Current draft title.
            identity (tuple[str, int]): Current issuer and verified account.
            snapshot (CreateJournalSnapshot): Older completed local record.

        Returns:
            None: No record is cleared until the user presses Continue.
        """
        if (snapshot.receipt is None or snapshot.issuer is None
                or snapshot.title is None
                or not self._creation_context_is_current(
                    card, session, parent_id, identity,
                )):
            self._creation_context_changed(card)
            return
        self._context_content(
            card,
            self._text('create_continue_prior').format(
                previous_title=snapshot.title, site=snapshot.issuer, title=title,
            ),
        )
        self._context_action(
            card, self._text('create_continue'),
            lambda: self._continue_after_prior_completion(
                card, session, parent_id, title, identity, snapshot,
            ),
        )
        self._context_action(card, self._text('cancel'), self._dismiss_context)
        self._place_context(card)

    def _continue_after_prior_completion(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str, identity: tuple[str, int],
        snapshot: CreateJournalSnapshot,
    ) -> None:
        """Acknowledge the shown old result, then create the current draft once.

        Args:
            card (GlassCard): Context card whose Continue action was explicit.
            session (LinkingSession): Current live session and grant.
            parent_id (int | None): Captured current direct parent or root.
            title (str): Captured current user-entered title.
            identity (tuple[str, int]): Captured current issuer/account.
            snapshot (CreateJournalSnapshot): Exact prior receipt shown to the
                user before the explicit Continue action.

        Returns:
            None: A stale card or changed binding cannot start a new request.
        """
        if not self._creation_context_is_current(
            card, session, parent_id, identity,
        ):
            self._creation_context_changed(card)
            return
        dispatch = CreateDispatch()
        self._create_dispatch = dispatch
        self._run_photos_gallery_create(
            card, session, parent_id, title, identity, dispatch,
            prior_snapshot=snapshot,
        )

    def _start_photos_gallery_create(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str, identity: tuple[str, int],
    ) -> None:
        """Start a new create only after the empty journal was rechecked on Tk.

        Args:
            card (GlassCard): Exact current title-entry card.
            session (LinkingSession): Exact live Photos session.
            parent_id (int | None): Exact current direct parent or root.
            title (str): User-entered physical gallery title.
            identity (tuple[str, int]): Current issuer and verified account.

        Returns:
            None: Creates once if the context remains current and the journal
                still admits a new operation.
        """
        if not self._creation_context_is_current(
            card, session, parent_id, identity,
        ):
            self._creation_context_changed(card)
            return
        dispatch = CreateDispatch()
        self._create_dispatch = dispatch
        self._run_photos_gallery_create(
            card, session, parent_id, title, identity, dispatch,
        )

    def _run_photos_gallery_create(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str, identity: tuple[str, int], dispatch: CreateDispatch,
        *, prior_snapshot: CreateJournalSnapshot | None = None,
    ) -> None:
        """Run explicit prior acknowledgement and one journaled create.

        Args:
            card (GlassCard): Originating current Photos context.
            session (LinkingSession): Exact current live session.
            parent_id (int | None): Captured direct parent or web root.
            title (str): User-entered physical gallery title.
            identity (tuple[str, int]): Captured issuer and Admin account.
            dispatch (CreateDispatch): Cancellation gate tied to this context.
            prior_snapshot (CreateJournalSnapshot | None): Prior completed
                record explicitly shown and accepted, if one must be cleared.

        Returns:
            None: A validated receipt or safe recovery notice reaches Tk.
        """
        manager = self._manager
        if manager is None:
            self._finish_photos_create_dispatch(dispatch)
            return

        def create() -> Callable[[], None]:
            """Run the authorized journal operations without touching Tk.

            Returns:
                Callable[[], None]: Tk completion for the confirmed receipt.
            """
            if prior_snapshot is not None:
                try:
                    if dispatch.cancelled.is_set():
                        return lambda: self._finish_photos_create_recovery(
                            card, dispatch, session, parent_id, title,
                        )
                    manager.acknowledge_prior_photos_gallery(
                        prior_snapshot, session, identity[0], identity[1],
                    )
                except Exception as error:
                    manager.record_photos_creation_failure('acknowledge', error)
                    return lambda: self._finish_photos_create_recovery(
                        card, dispatch, session, parent_id, title,
                    )
            if dispatch.cancelled.is_set():
                return lambda: self._finish_photos_create_recovery(
                    card, dispatch, session, parent_id, title,
                )
            try:
                receipt = manager.create_photos_gallery(
                    session, title, parent_id, dispatch,
                )
            except Exception as error:
                manager.record_photos_creation_failure('create', error)
                return lambda: self._finish_photos_create_recovery(
                    card, dispatch, session, parent_id, title,
                )
            return lambda: self._finish_photos_create_success(
                card, session, receipt, dispatch,
            )

        self._run_context(
            card, create,
            lambda: self._finish_photos_create_recovery(
                card, dispatch, session, parent_id, title,
            ),
        )

    def _finish_photos_create_dispatch(self, dispatch: CreateDispatch) -> None:
        """Release a finished context's cancellation gate on the Tk thread.

        Args:
            dispatch (CreateDispatch): Gate created for one Photos create flow.

        Returns:
            None: A later context cannot cancel an already finished request.
        """
        if self._create_dispatch is dispatch:
            self._create_dispatch = None

    def _finish_photos_create_success(
        self, card: GlassCard, session: LinkingSession,
        receipt: BoundCreateReceipt, dispatch: CreateDispatch,
    ) -> None:
        """Clear the gate and present the newly confirmed gallery.

        Args:
            card (GlassCard): Originating context card, if still open.
            session (LinkingSession): Session that completed the request.
            receipt (BoundCreateReceipt): Freshly confirmed gallery receipt.
            dispatch (CreateDispatch): Gate owned by this completed operation.

        Returns:
            None: The result is reconciled through the existing Photos tree.
        """
        self._finish_photos_create_dispatch(dispatch)
        self._creation_completed(card, session, receipt)

    def _finish_photos_create_recovery(
        self, card: GlassCard, dispatch: CreateDispatch,
        session: LinkingSession, parent_id: int | None, title: str,
    ) -> None:
        """Clear a finished create gate and show the existing recovery action.

        Args:
            card (GlassCard): Originating context, if it remains mounted.
            dispatch (CreateDispatch): Gate owned by this finished operation.
            session (LinkingSession): Session captured for the create draft.
            parent_id (int | None): Captured direct parent or web root.
            title (str): Captured current user-entered title.

        Returns:
            None: Unconfirmed work remains saved and is never replayed here.
        """
        self._finish_photos_create_dispatch(dispatch)
        self._creation_recovery(card, session, parent_id, title)

    def _creation_check_failed(
        self, card: GlassCard, retry: Callable[[], None] | None = None,
    ) -> None:
        """Explain a preflight refusal before any new gallery request is sent.

        Args:
            card (GlassCard): Current create context, if still mounted.
            retry (Callable[[], None] | None): Safe read-only recheck action.

        Returns:
            None: The user may retry after checking the existing operation.
        """
        if card is not self._context_card:
            return
        self._context_content(card, self._text('create_check_failed'))
        if retry is not None:
            self._context_action(
                card, self._text('create_check_again'), retry,
            )
        if self._manage_link is not None:
            self._context_action(
                card, self._text('manage'), self._manage_link,
            )
        self._place_context(card)

    def _creation_status_unconfirmed(
        self, card: GlassCard, session: LinkingSession,
        parent_id: int | None, title: str,
    ) -> None:
        """Offer a read-only retry for the same unresolved journal operation.

        Args:
            card (GlassCard): Current create card.
            session (LinkingSession): Current same-account live session.
            parent_id (int | None): Captured current draft parent or root.
            title (str): Captured current draft title.

        Returns:
            None: The retry reads only status and never resends a create request.
        """
        if card is not self._context_card:
            return
        self._context_content(card, self._text('create_status_unconfirmed'))
        self._context_action(
            card, self._text('create_check_again'),
            lambda: self._retry_photos_create_check(
                card, session, parent_id, title,
            ),
        )
        if self._manage_link is not None:
            self._context_action(card, self._text('manage'), self._manage_link)
        self._place_context(card)

    def _creation_pending_at_other_site(
        self, card: GlassCard, issuer: str,
    ) -> None:
        """Keep a pending key at its original account and site.

        Args:
            card (GlassCard): Current create context.
            issuer (str): Original issuer from the key-free saved snapshot.

        Returns:
            None: The current session is never sent the other site's key.
        """
        if card is not self._context_card:
            return
        self._context_content(
            card,
            self._text('create_pending_other').format(site=issuer),
        )
        if self._manage_link is not None:
            self._context_action(card, self._text('manage'), self._manage_link)
        self._place_context(card)

    def _retry_photos_create_check(
        self, card: GlassCard, session: LinkingSession,
        parent_id: int | None, title: str, *, preflight: bool = False,
    ) -> None:
        """Reinspect saved state without automatically dispatching a new POST.

        Args:
            card (GlassCard): Current Photos context card.
            session (LinkingSession): Exact session from the original draft.
            parent_id (int | None): Captured parent or web root.
            title (str): Captured title, used only to compare completed state.
            preflight (bool): True only when no create POST has been admitted.

        Returns:
            None: An empty journal returns to the title form for a fresh click.
        """
        manager = self._manager
        if manager is None:
            return
        identity = manager.photos_site_identity(session)
        if identity is None or not self._creation_context_is_current(
            card, session, parent_id, identity,
        ):
            self._creation_context_changed(card)
            return

        def inspect() -> Callable[[], None]:
            """Read the key-free profile state on the worker thread.

            Returns:
                Callable[[], None]: Tk callback that does not send a mutation.
            """
            try:
                snapshot = manager.inspect_photos_gallery_creation()
            except Exception as error:
                manager.record_photos_creation_failure('inspect', error)
                if preflight:
                    return lambda: self._creation_check_failed(
                        card,
                        lambda: self._retry_photos_create_check(
                            card, session, parent_id, title, preflight=True,
                        ),
                    )
                return lambda: self._creation_status_unconfirmed(
                    card, session, parent_id, title,
                )
            if snapshot.state == 'empty':
                return lambda: self._creation_form(card, session, parent_id)
            return lambda: self._route_photos_create_snapshot(
                card, session, parent_id, title, identity, snapshot,
            )

        self._run_context(
            card, inspect,
            (lambda: self._creation_check_failed(
                card,
                lambda: self._retry_photos_create_check(
                    card, session, parent_id, title, preflight=True,
                ),
            )) if preflight else lambda: self._creation_status_unconfirmed(
                card, session, parent_id, title,
            ),
        )

    def _creation_context_changed(self, card: GlassCard) -> None:
        """Refuse a stale Photos card without starting or acknowledging work.

        Args:
            card (GlassCard): Previously captured context card.

        Returns:
            None: A current card receives the localized stale-context message.
        """
        if card is self._context_card:
            self._context_content(card, self._text('create_context_changed'))
            self._place_context(card)

    def _creation_completed(
        self, card: GlassCard, session: LinkingSession,
        receipt: BoundCreateReceipt, *, already_exists: bool = False,
    ) -> None:
        """Admit the current confirmed gallery and offer receipt acknowledgement.

        Args:
            card (GlassCard): Originating context, if still open.
            session (LinkingSession): Exact link that completed the creation.
            receipt (BoundCreateReceipt): Validated gallery and original intent.
            already_exists (bool): Present a fresh status result as an existing
                matching gallery, not as a creation from this click.

        Returns:
            None: A current receipt makes its gallery immediately actionable.
                The first root becomes active; existing selection is preserved.
                Stale session results cannot change the catalogue.
        """
        if (self._session is not session
                or self._session_provider() is not session
                or not session.available()):
            self._creation_context_changed(card)
            return
        self._create_receipt = receipt
        gallery = receipt.gallery
        had_selection = self._selected_gallery_id is not None
        self._galleries[gallery.gallery_id] = gallery
        # Creation and recovered status receipts are fresh server responses,
        # just like catalogue pages. Admit this exact row before rendering or
        # selecting it; a display-only cached row must still remain blocked.
        self._fresh_catalogue_ids.add(gallery.gallery_id)
        if gallery.parent_id is not None:
            self._children_by_parent.setdefault(
                gallery.parent_id, set(),
            ).add(gallery.gallery_id)
            parent = str(gallery.parent_id)
            if self.tree.exists(parent):
                self.tree.item(parent, open=True)
        self._render_tree([gallery.gallery_id])
        self._sync_subgalleries()
        self._remember_catalogue_progress()
        if not had_selection and gallery.parent_id is None:
            self.tree_status.configure(text="")
            self._select_gallery(gallery)
        if card is self._context_card:
            self._context_content(
                card,
                self._text(
                    'create_already_exists' if already_exists else 'created',
                ).format(title=gallery.title),
            )
            self._context_action(
                card, self._text('create_done'),
                lambda: self._acknowledge_creation(card, session, receipt),
            ).focus_set()
            self._place_context(card)

    def _acknowledge_creation(
        self, card: GlassCard, session: LinkingSession, receipt: BoundCreateReceipt,
    ) -> None:
        """Clear the exact receipt only after the user acknowledges the result.

        Args:
            card (GlassCard): Receipt confirmation surface.
            session (LinkingSession): Session bound to the original result.
            receipt (BoundCreateReceipt): Exact completed journal receipt.

        Returns:
            None: Acknowledgement runs without changing the upload target.
        """
        manager = self._manager
        if manager is None:
            return

        def acknowledge() -> Callable[[], None]:
            """Clear the matching receipt through the shared journal.

            Returns:
                Callable[[], None]: Tk callback clearing the acknowledged result.
            """
            try:
                manager.acknowledge_photos_gallery(session, receipt)
            except Exception as error:
                manager.record_photos_creation_failure('acknowledge', error)
                raise
            return lambda: self._creation_acknowledged(card)

        self._run_context(card, acknowledge, lambda: self._creation_recovery(card))

    def _creation_acknowledged(self, card: GlassCard) -> None:
        """Release the confirmed receipt after successful journal cleanup.

        Args:
            card (GlassCard): Receipt confirmation surface.

        Returns:
            None: A later create can submit a new independent operation.
        """
        self._create_receipt = None
        if card is self._context_card:
            self._dismiss_context()

    def _creation_recovery(
        self, card: GlassCard, session: LinkingSession | None = None,
        parent_id: int | None = None, title: str | None = None,
    ) -> None:
        """Offer the established recovery surface for an unconfirmed operation.

        Args:
            card (GlassCard): Originating creation or receipt confirmation card.
            session (LinkingSession | None): Captured session for an optional
                read-only status retry.
            parent_id (int | None): Captured direct parent or root for retry.
            title (str | None): Captured title for the optional status retry.

        Returns:
            None: The operation remains journalled for review.
        """
        if card is not self._context_card:
            return
        self._context_content(card, self._text("creation_uncertain"))
        if session is not None and title is not None:
            self._context_action(
                card, self._text('create_check_again'),
                lambda: self._retry_photos_create_check(
                    card, session, parent_id, title,
                ),
            )
        if self._manage_link is not None:
            self._context_action(card, self._text("manage"), self._open_creation_recovery)
        self._place_context(card)

    def _open_creation_recovery(self) -> None:
        """Return receipt ownership to the established management surface.

        Returns:
            None: Management can review and resolve the same persisted journal.
        """
        if self._manage_link is not None:
            self._create_receipt = None
            self._dismiss_context()
            self._manage_link()

    def _on_destroy(self, event: tk.Event) -> None:
        """Cancel page work and release the embedded viewer on destruction.

        Args:
            event (tk.Event): Widget destruction event for this frame or child.

        Returns:
            None: Cleanup runs only when the workspace itself is destroyed.
        """
        if event.widget is not self or self._destroyed:
            return
        self._destroyed = True
        self._storage_cancel.set()
        if self._storage_activity_after is not None:
            self.after_cancel(self._storage_activity_after)
            self._storage_activity_after = None
        if self._storage_after is not None:
            self.after_cancel(self._storage_after)
            self._storage_after = None
        self._set_preload_activity(False)
        self._dismiss_context(restore=False)
        self._cancel_catalogue_continuation()
        self._cancel_cached_catalogue_mount()
        self._set_catalogue_loading(False)
        self._generation += 1
        self._inspector_generation += 1
        if self._inspector_cancel is not None:
            self._inspector_cancel.set()
            self._inspector_cancel = None
        self._inspector_pending = None
        self._cancel.set()
        self._catalogue_cancel.set()
        if self._preload_worker is not None:
            self._preload_worker.stop()
        if self._cache_after is not None:
            try:
                self.after_cancel(self._cache_after)
            except tk.TclError:
                pass
        if self._tree_width_save_after is not None:
            try:
                self.after_cancel(self._tree_width_save_after)
            except tk.TclError:
                pass
        if self._info_width_save_after is not None:
            try:
                self.after_cancel(self._info_width_save_after)
            except tk.TclError:
                pass
        if self._info_width_restore_after is not None:
            try:
                self.after_cancel(self._info_width_restore_after)
            except tk.TclError:
                pass
            self._info_width_restore_after = None
        if self._poll_id is not None:
            try:
                self.after_cancel(self._poll_id)
            except tk.TclError:
                pass
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._galleries.clear()
        self._thumbnail_memory.clear()
        if self._storage_parent is not None:
            try:
                self._storage_heading.destroy()
            except tk.TclError:
                pass

    def _invalidate_photo_info(
        self, gallery_id: int, image_ids: tuple[int, ...] | None = None,
    ) -> None:
        """Remove stale metadata after a confirmed mutation or gallery refresh.

        Args:
            gallery_id (int): Exact gallery whose metadata may have changed.
            image_ids (tuple[int, ...] | None): Exact changed photo IDs, or None
                to invalidate every cached photo in that gallery.

        Returns:
            None: Unrelated current-session metadata remains reusable.
        """
        targets = set(image_ids) if image_ids is not None else None
        for key in tuple(self._photo_info_cache):
            _session_id, cached_gallery, image_id = key
            if cached_gallery == gallery_id and (
                targets is None or image_id in targets
            ):
                self._photo_info_cache.pop(key, None)
