# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/workspace/support.py
# Module Type: Photos workspace shared contracts
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
from dataclasses import dataclass, replace
import logging
import queue
import threading
import time
import tkinter as tk
from tkinter import ttk
from typing import Callable, TypeAlias

# Keep the pre-split logger identity used by compatibility callers and tests.
PHOTOS_WORKSPACE_LOGGER = logging.getLogger("uploader.photos_workspace")

from uploader.localization import gettext
from ....linking import (
    LINKING_CREATE_TITLE_MAX_BYTES,
    LINKING_GALLERY_ID_MAX,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    NATIVE_MEDIA_MAX_BYTES,
    LinkedGallery, LinkedGalleryDetail, LinkedGalleryEditSnapshot,
    LinkedGalleryPage, LinkedGalleryTree, LinkingGalleryEditFailure,
    LinkedMediaDetails,
    LinkedMediaGalleryInfo, LinkedPhoto, LinkingSession,
    LinkingMediaThrottled, LinkingResponseError, gallery_edit_text_valid,
)
from ....linking.ui import LinkingManager
from ....gallery_catalogue_cache import (
    GalleryCatalogueCache, GalleryCatalogueSnapshot, GalleryCatalogueWriter,
)
from ....photos_catalogue_performance import CataloguePerformanceWindow
from ....diagnostics import (
    format_safe_photos_failure, is_safe_photos_observation,
)
from ....flight_report_translations import tr
from ....gallery_folders import GalleryFolderShelf
from ....gallery_information import (
    PHOTOS_INFO_CACHE_ENTRY_LIMIT, PHOTOS_INFO_CACHE_FRESH_SECONDS,
    photo_information_groups, thumbnail_quality_caption,
)
from ....gallery_preload import (
    GalleryCacheProbeRow, GalleryPreloadWorker, PreloadProgress,
    probe_gallery_cache_status,
)
from ....gallery_media_cache import (
    GalleryMediaCache,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES as THUMBNAIL_DISK_DEFAULT_BYTES,
)
from ....gallery_view_preferences import (
    GALLERY_FOLDER_SHARE_BOUNDS, GALLERY_FOLDER_SHARE_SCALE,
    PHOTO_CARD_FORMATS, normalize_card_format,
)
from ....config import (
    THUMBNAIL_CACHE_LIMIT_CHOICES_GIB, set_thumbnail_cache_limit_gib,
)
from ....process_memory import current_process_ram_bytes
from ....linked_create import (
    BoundCreateReceipt, CreateDispatch,
)
from ....linked_upload import LinkedUploadTarget
from ....native_gallery_viewer import (
    GalleryThumbnailMemory, _log_safe_media_failure,
    mount_native_gallery_view,
)
from ....tk_scroll_input import vertical_scroll_delta
from ....ui_layout import (
    PHOTOS_HEADING_STACK_WIDTH, PHOTOS_HEADING_TITLE_MINIMUM,
    PHOTOS_INFO_PANE_WIDTH, PHOTOS_TREE_WIDTH,
)
from ....ui_palette import PHOTOS_CARD_FORMAT_ICON_SIZE
from ....ui_theme import (
    Button,
    GlassCard, MetricCard, METRIC_ICON_SIZE, RaisedDialogSurface, ThemeImage,
    _PALETTE, attach_inline_tooltip,
    create_smooth_icon, icon_button,
)

# Purpose: Make the thin section line easy to grab without adding a toolbar.
# Type: int. Units: logical Tk pixels. Scope: mixed gallery divider hit area.
# Consumers: PhotosWorkspace._build_widgets.
# Rationale: 7 keeps the visible line thin with three pixels of padding per side.
FOLDER_DIVIDER_HIT_HEIGHT = 7

# Purpose: Allow keyboard resizing with the same local persistence as dragging.
# Type: int. Units: thousandths of pane height. Scope: focused gallery divider.
# Consumers: PhotosWorkspace._key_folder_resize.
# Rationale: 20 changes two percent per arrow press without a large jump.
FOLDER_DIVIDER_KEY_STEP = 20


# Purpose: Keep Tk result delivery responsive during sequential page loading.
# Type: int. Units: milliseconds. Scope: one PhotosWorkspace lifetime.
# Consumers: PhotosWorkspace._poll_events, PhotosWorkspace._begin_gallery_edit.
# Rationale: 80 ms gives quick feedback without a busy UI polling loop.
PHOTOS_CATALOG_POLL_MS = 80

# Purpose: Keep the Legacy Tk notice readable next to its restart action.
# Type: int. Units: logical Tk pixels. Scope: Photos interface notice.
# Consumers: PhotosWorkspace._build_widgets and _resize_legacy_interface_notice.
# Rationale: 260 accommodates the short localized warning at typical font sizes.
PHOTOS_LEGACY_NOTICE_TEXT_WIDTH = 260

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

# Purpose: Keep inspector labels readable after the pane is collapsed.
# Type: int. Units: pixels. Scope: minimum width of the optional info pane.
# Consumers: PhotosWorkspace._show_photo_inspector_pane.
# Rationale: 128 px leaves space for a short label and one scrollbar.
PHOTOS_INFO_PANE_MIN_WIDTH = 128

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
        source (str): Fixed retained, disk, miss or disabled cache origin.
        read_ms (float | None): Actual disk read and validation duration,
            or None if no physical cache lookup was performed.
    """

    generation: int
    snapshot: GalleryCatalogueSnapshot | None
    source: str = 'unknown'
    read_ms: float | None = None


@dataclass(frozen=True)
class _CatalogueTreeResult:
    """Deliver a validated fresh tree-v1 response to the Tk owner.

    Args:
        generation (int): Catalogue pass that began the request.
        tree (LinkedGalleryTree): Complete rows or matching unchanged proof.
        request_ms (float | None): Measured optional tree worker operation,
            or None in direct test/compatibility calls.
    """

    generation: int
    tree: LinkedGalleryTree
    request_ms: float | None = None


@dataclass(frozen=True)
class _CataloguePageResult:
    """Deliver one validated legacy gallery page to the Tk owner.

    Args:
        generation (int): Catalogue pass that began the request.
        page (LinkedGalleryPage | None): Fresh page or safe failure marker.
        throttled (bool): Whether this read returned an HTTP 429.
        retry_after_seconds (int | None): Existing bounded retry instruction.
        cursor (int | None): Captured continuation cursor, never exported.
        request_ms (float | None): Actual measured tree/page worker duration.
        fallback_reason (str): Fixed optional-tree compatibility outcome.
    """

    generation: int
    page: LinkedGalleryPage | None
    throttled: bool = False
    retry_after_seconds: int | None = None
    cursor: int | None = None
    request_ms: float | None = None
    fallback_reason: str = 'none'


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
_CacheRow: TypeAlias = GalleryCacheProbeRow
# Ordinary totals, retained totals, process RAM bytes and completed-scan flag.
_StorageObservation: TypeAlias = tuple[
    tuple[int | None, int | None, int | None, bool, str | None],
    tuple[int | None, int | None, int | None, int | None, int | None, bool],
    int | None,
    bool,
]


# Purpose: Keep multiline edits compact while allowing native text scrolling.
# Type: int. Units: text rows. Scope: one anchored description editor.
# Consumers: PhotosWorkspace._show_gallery_editor. Rationale: five rows leave
# confirm/cancel visible in a narrow workspace without a modal window.
PHOTOS_GALLERY_EDITOR_ROWS = 5

# Purpose: Give title and description fields a useful compact preferred width.
# Type: int. Units: average text columns. Scope: one anchored metadata editor.
# Consumers: PhotosWorkspace._show_gallery_editor. Rationale: forty columns fit
# ordinary gallery names while the context owner clamps the actual panel width.
PHOTOS_GALLERY_EDITOR_COLUMNS = 40
