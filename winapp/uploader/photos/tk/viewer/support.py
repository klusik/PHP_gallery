# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/viewer/support.py
# Module Type: Windows Client UI
# Purpose: Display bearer-authorized gallery media inside the Photos workspace.
# Responsibilities:
#   - Mount one bounded page and its thumbnails without worker-side Tk calls.
#   - Show coalesced metadata and visible-thumbnail progress without delaying photos.
#   - Render selected details, prefetch nearby photos and run manual slideshows.
#   - Play explicitly selected MP3/WAV tracks through Windows winmm only.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native, bounded viewer for media returned by a linked gallery session."""

from collections import OrderedDict, deque
from dataclasses import dataclass, replace
import ctypes
import hashlib
import io
import logging
import os
import queue
import signal
import subprocess
import sys
import tempfile
import threading
import time
import tkinter as tk
from tkinter import ttk
import uuid
from typing import Callable, Literal, TypeAlias

# Keep the pre-split logger identity used by compatibility callers and tests.
NATIVE_GALLERY_VIEWER_LOGGER = logging.getLogger(
    "uploader.native_gallery_viewer",
)

from ....linking import (
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    NATIVE_MEDIA_MAX_BYTES,
    NATIVE_MEDIA_LEGACY_PAGE_SIZE,
    NATIVE_MEDIA_PAGE_SIZE,
    LINKING_CANCELLATION_POLL_SECONDS,
    LinkedGallery,
    LinkedMediaPage,
    LinkedPhoto,
    LinkedPresentation,
    LinkedTrack,
    LinkingMediaThrottled,
    LinkingThumbnailBatchDeferred,
    LinkingThumbnailProofChanged,
    LinkingMediaUnavailable,
    LinkingError,
    LinkingResponseError,
    LinkingRevisionUnsupported,
    LinkingSession,
    MediaReadTimings,
    ThumbnailBatchStats,
    ThumbnailPublication,
    LegacyThumbnailPublication,
)
from ....flight_report_translations import tr
from ....diagnostics import (
    PhotosDecodeError,
    PhotosDecodeFacts,
    format_safe_photos_decode_facts,
    format_safe_photos_failure,
    format_safe_photos_shortcut,
    is_safe_photos_observation,
)
from ....photos_performance import ThumbnailBatchPhysicalWindow, ThumbnailPerformanceWindow
from ....viewport_performance import ViewportPerformanceWindow
from ....gallery_lightbox import (
    LIGHTBOX_ZOOM_STEP,
    PhotoLightbox,
    detect_black_matte_crop,
)
from ....gallery_audio import (
    MEDIA_AUDIO_POLL_MS, MEDIA_AUDIO_REAP_SECONDS, MEDIA_MCI_MODE_CAPACITY,
    MciSender, load_winmm, send_mci,
)
from ....canvas_gallery_grid import CanvasGalleryGrid, CanvasTileProxy, TooltipValue
from ....ui_theme import (
    Button, GALLERY_TILE_BORDER_INSET, RaisedDialogSurface, _attach_tooltip,
    ensure_component_theme, gallery_grid_theme, icon_button,
)
from ....tk_scroll_input import vertical_scroll_delta
from ....gallery_view_preferences import gallery_card_dimensions, normalize_card_format
from ....config import (
    THUMBNAIL_BATCH_CHOICES, THUMBNAIL_BATCH_DEFAULT_SIZE,
    resolve_canvas_gallery_renderer_enabled,
)
from ....gallery_view_order import GalleryViewOrder
from ....gallery_load_progress import GalleryLoadProgress
from ....gallery_image_policy import (
    MEDIA_HTTP_BATCH_INITIAL_SIZE,
    MEDIA_HTTP_BATCH_LARGE_BYTES,
    MEDIA_HTTP_BATCH_SLOW_SECONDS,
    MEDIA_IMAGE_MAX_PIXELS,
    MEDIA_IMAGE_MAX_SIZE,
    MEDIA_SLIDESHOW_DEFAULT_SECONDS,
    MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
    MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS,
    MEDIA_THUMBNAIL_SIZE,
    MEDIA_VIEWPORT_COALESCE_MS,
)

try:
    from PIL import Image, ImageTk
except ImportError:
    Image = None
    ImageTk = None


# Purpose: Reduce large server fallback JPEGs before thumbnail RGB allocation.
# Type: tuple[int, int]. Units: decoded pixels. Scope: one thumbnail worker.
# Consumers: _NativeGalleryViewer._prepare_thumbnail.
# Rationale: 288 px keeps twice the final tile dimensions and enough detail
# for matte sampling; final 144 px and original source safety limits remain.
MEDIA_THUMBNAIL_DECODE_SIZE = (288, 288)

# Purpose: Retain neighboring rows around the exact visible thumbnail viewport.
# Type: int. Units: grid rows on each side. Scope: one live gallery viewer.
# Consumers: _NativeGalleryViewer._apply_grid_viewport.
# Rationale: One row avoids edge churn while keeping Tk image retention bounded.
MEDIA_THUMBNAIL_OVERSCAN_ROWS = 1

# Purpose: Bound revision checks and failed thumbnail-proof refresh retries.
# Type: int. Units: milliseconds. Scope: one embedded viewer.
# Consumers: _NativeGalleryViewer._schedule_revision_check,
# _NativeGalleryViewer._queue_thumbnail_proof_refresh.
# Rationale: 30 seconds keeps detection useful while limiting shared API load.
MEDIA_REVISION_POLL_MS = 30000


class GalleryThumbnailMemory:
    """Hold bounded decoded thumbnails for one live gallery read session.

    Returns:
        None: The cache starts empty and owns no server metadata.
    """

    def __init__(self) -> None:
        """Initialize the lock and empty bounded decoded-image LRU.

        Returns:
            None: The cache is ready for authorized thumbnail reads.
        """
        self.lock = threading.RLock()
        self.entries: OrderedDict[
            tuple[int, int, bool | None, str | None, int | None],
            tuple['Image.Image', int, float],
        ] = OrderedDict()
        # Map photo identity to its sole current proof key; this owns no rasters.
        self.identity_keys: dict[
            tuple[int, int],
            tuple[int, int, bool | None, str | None, int | None],
        ] = {}
        self.size_bytes = 0
        self.hits = 0
        self.misses = 0
        self.evictions = 0
        self.admission_refusals = 0
        self.expirations = 0
        self.validator_changes = 0

    def metrics_snapshot(self) -> dict[str, int]:
        """Return fixed cumulative counters without retaining photo identities.

        Returns:
            dict[str, int]: Copy with keys hits, misses, evictions, bytes,
                entries, admission_refusals, expirations, and validator_changes.
                Hits and misses count authorized full-key lookups, not unique
                photos or physical media reads. Counters survive clear; a new
                owner starts at zero.
        """
        with self.lock:
            return {
                'hits': self.hits,
                'misses': self.misses,
                'evictions': self.evictions,
                'bytes': self.size_bytes,
                'entries': len(self.entries),
                'admission_refusals': self.admission_refusals,
                'expirations': self.expirations,
                'validator_changes': self.validator_changes,
            }

    def clear(self) -> None:
        """Release every retained raster when its owning session ends.

        Returns:
            None: No decoded gallery pixels or identity mappings remain.
        """
        with self.lock:
            for image, _size, _stored_at in self.entries.values():
                image.close()
            self.entries.clear()
            self.identity_keys.clear()
            self.size_bytes = 0

    def remove_photos(
        self, gallery_id: int, image_ids: tuple[int, ...],
    ) -> None:
        """Release cached rasters only for server-confirmed deleted IDs.

        Args:
            gallery_id (int): Exact gallery owning the deleted photos.
            image_ids (tuple[int, ...]): Confirmed positive photo identifiers.

        Returns:
            None: Unrelated gallery and photo cache entries remain warm.
        """
        targets = set(image_ids)
        with self.lock:
            for image_id in targets:
                identity = (gallery_id, image_id)
                key = self.identity_keys.pop(identity, None)
                if key is None:
                    continue
                cached = self.entries.pop(key, None)
                if cached is None:
                    continue
                image, size, _stored_at = cached
                self.size_bytes -= size
                image.close()

# Purpose: Identify a tile whose authorized preview could not be loaded.
# Type: str. Units: one visible Unicode glyph. Scope: unavailable photo tile.
# Consumers: _NativeGalleryViewer._show_thumbnail.
# Rationale: A neutral image mark avoids substituting server filenames for media.
MEDIA_THUMBNAIL_PLACEHOLDER = '▧'

# Purpose: Bound the sanitized title retained for one gallery tile.
# Type: int. Units: characters. Scope: one tile title and its tooltip string.
# Consumers: _NativeGalleryViewer._accept_page and _accept_refresh.
# Rationale: 160 characters preserve useful names without retaining long labels.
MEDIA_TILE_LABEL_MAX_CHARS = 160


# Purpose: Keep adjacent photo cards close while preserving a visible gap.
# Type: int. Units: pixels. Scope: horizontal and vertical photo-grid gutters.
# Consumers: _NativeGalleryViewer._on_grid_resize and _accept_page.
# Rationale: 6 px separates selection rims without spreading the grid.
MEDIA_GRID_GUTTER = 6

# Purpose: Keep row geometry steady as viewport thumbnails are released.
# Type: int. Units: pixels. Scope: one photo row in the scrollable album.
# Consumers: _NativeGalleryViewer._accept_page, _on_grid_resize.
# Rationale: The image, shared tile rim and gutters must fit without clipping.
MEDIA_GRID_ROW_MINIMUM_HEIGHT = (
    MEDIA_THUMBNAIL_SIZE[1] + MEDIA_GRID_GUTTER
    + 2 * GALLERY_TILE_BORDER_INSET
)

# Purpose: Bound the compact contextual presentation-track selector width.
# Type: int. Units: pixels. Scope: one open music chooser.
# Consumers: _NativeGalleryViewer._toggle_music_panel.
# Rationale: 290 px fits a readable track label on desktop and shrinks to view.
MEDIA_MUSIC_PANEL_WIDTH = 290

# Purpose: Keep detail data warm for seamless nearby keyboard navigation.
# Type: float. Units: seconds. Scope: one open gallery viewer.
# Consumers: _NativeGalleryViewer._cached_detail.
# Rationale: 15 seconds is long enough for navigation and shorter than disk TTL.
MEDIA_DETAIL_CACHE_FRESH_SECONDS = 15.0

# Purpose: Bound retained selected and adjacent prepared detail images.
# Type: int. Units: images. Scope: one open gallery viewer.
# Consumers: _NativeGalleryViewer._remember_detail.
# Rationale: Three entries cover the current photo and both nearby directions.
MEDIA_DETAIL_CACHE_ENTRY_LIMIT = 3

# Purpose: Hold the selected photo and both adjacent prepared detail images.
# Type: int. Units: bytes. Scope: one open gallery viewer.
# Consumers: _NativeGalleryViewer._remember_detail.
# Rationale: Three maximum source bodies plus decoded RGB fit within the cap.
MEDIA_DETAIL_CACHE_MAX_BYTES = NATIVE_MEDIA_MAX_BYTES * 6

# Purpose: Limit speculative adjacent image work behind foreground operations.
# Type: int. Units: photos. Scope: one open gallery viewer.
# Consumers: _NativeGalleryViewer._queue_adjacent_prefetch.
# Rationale: One next and one previous image improve arrows without scanning.
MEDIA_DETAIL_PREFETCH_LIMIT = 2

# Purpose: Bound initial visible-thumbnail priority before a background revision probe.
# Type: int. Units: thumbnail reads. Scope: one viewer before its first revision check.
# Consumers: _NativeGalleryViewer._dispatch_pending_work.
# Rationale: Eight reads bound the initial batch while revision still gets a turn.
MEDIA_INITIAL_THUMBNAIL_PRIORITY_LIMIT = 8

# Purpose: Ignore Windows fullscreen shortcuts modified by Control or Alt.
# Type: int. Units: Tk event-state bits. Scope: Windows fullscreen shortcut.
# Consumers: _NativeGalleryViewer._on_fullscreen.
# Rationale: WinMod2 and ALT_MASK map Alt; Mod1 NumLock, Lock and Mod3
# ScrollLock are state indicators and must not block the F key.
MEDIA_FULLSCREEN_WINDOWS_COMMAND_MODIFIERS = 0x0004 | 0x0010 | 0x20000

# Purpose: Preserve command shortcuts on non-Windows Tk backends.
# Type: int. Units: Tk event-state bits. Scope: non-Windows fullscreen shortcut.
# Consumers: _NativeGalleryViewer._on_fullscreen.
# Rationale: Control, Mod1 (Alt/Command) and Mod4 are command modifiers;
# Shift, CapsLock and other lock indicators remain usable.
MEDIA_FULLSCREEN_COMMAND_MODIFIERS = 0x0004 | 0x0008 | 0x0040

# Purpose: Poll only active worker requests at a responsive interval.
# Type: int. Units: milliseconds. Scope: one active viewer worker.
# Consumers: _NativeGalleryViewer._schedule_queue_poll.
# Rationale: 30 ms lowers visible wait without polling idle viewers.
MEDIA_QUEUE_ACTIVE_POLL_MS = 30

# Purpose: Fetch several consecutive admitted thumbnails in one serialized
# worker turn while keeping cancellation and foreground dispatch boundaries.
# Type: int. Units: thumbnails. Scope: one background thumbnail batch.
# Consumers: _NativeGalleryViewer._load_next_thumbnail.
# Rationale: Four amortizes GUI scheduling while bounding queued raster results.
MEDIA_THUMBNAIL_BATCH_SIZE = 4

# Purpose: Bound thumbnail attachment work performed in one Tk callback.
# Type: int. Units: images. Scope: one viewer GUI drain callback.
# Consumers: _NativeGalleryViewer._poll_queue.
# Rationale: Four keeps image creation responsive and matches the batch bound.
MEDIA_THUMBNAIL_GUI_SLICE_SIZE = 4

# Purpose: Limit one thumbnail attachment slice even when image creation is slow.
# Type: float. Units: seconds. Scope: one viewer GUI drain callback.
# Consumers: _NativeGalleryViewer._poll_queue.
# Rationale: Eight ms is a cooperative budget, checked after each image so
# an individual Tk attachment can finish before yielding to input and repaint.
MEDIA_THUMBNAIL_GUI_SLICE_SECONDS = 0.008

# Purpose: Yield between ready thumbnail result slices without GUI polling delay.
# Type: int. Units: milliseconds. Scope: one viewer's result continuation.
# Consumers: _NativeGalleryViewer._drain_warm_thumbnail_slice,
# _NativeGalleryViewer._schedule_queue_poll.
# Rationale: One millisecond promptly resumes queued results after Tk yields.
MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS = 1

# Purpose: Match the only validated native presentation intervals.
# Type: tuple[int, ...]. Units: seconds. Scope: one manual slideshow interval.
# Consumers: _NativeGalleryViewer._slideshow_delay_ms.
# Rationale: These are the server's fixed presets, not arbitrary timer input.
MEDIA_SLIDESHOW_INTERVALS = (3, 5, 10)

@dataclass(frozen=True)
class _ThumbnailResult:
    """Carry one immutable thumbnail identity and its owned decoded result.

    Attributes:
        gallery_id (int): Physical gallery that authorized this media result.
        index (int): Captured zero-based tile position.
        photo (LinkedPhoto): Exact metadata descriptor admitted for the read.
        image (Image.Image | None): Owned decoded raster, or None on failure.
        timings (MediaReadTimings): Mutable timing facts filled by the session.
        decode_ms (float): Thumbnail decode duration in milliseconds.
        enqueued_at (float): Monotonic result-publication timestamp.
        publication (ThumbnailPublication | LegacyThumbnailPublication | None):
            Optional batch-session proof; None preserves existing single reads.
    """

    gallery_id: int
    index: int
    photo: LinkedPhoto
    image: 'Image.Image | None'
    timings: MediaReadTimings
    decode_ms: float
    enqueued_at: float
    publication: ThumbnailPublication | LegacyThumbnailPublication | None = None


@dataclass(frozen=True)
class _HttpBatchCompletion:
    """Retain one worker completion independently of the bounded image queue.

    Attributes:
        generation (int): Viewer generation that dispatched this group.
        outcome (str): Fixed safe completion category.
        remaining (tuple[int, ...]): Captured indices without a queued result.
        stats (ThumbnailBatchStats): Bounded per-call numeric observations.
        timings (MediaReadTimings): Physical transport observations once.
        elapsed (float): End-to-end worker duration in seconds.
        physical_complete (bool): Whether the transport worker has finished.
        decode_failures (int): Independently failed image decode attempts.
        deferred_seconds (int | None): Existing cooldown remainder, if deferred.
        deferred_source (Literal['transient', 'server_retry_after', 'unknown']):
            Trusted source category for the cooldown.
        prior_operation_finished (threading.Event | None): Completion signal for
            a physical operation that preceded this no-request deferral.
    """

    generation: int
    outcome: str
    remaining: tuple[int, ...]
    stats: ThumbnailBatchStats
    timings: MediaReadTimings
    elapsed: float
    physical_complete: bool
    decode_failures: int
    deferred_seconds: int | None = None
    deferred_source: Literal[
        'transient', 'server_retry_after', 'unknown',
    ] = 'unknown'
    prior_operation_finished: threading.Event | None = None


def _healthy_http_thumbnail_group(completion: _HttpBatchCompletion) -> bool:
    """Decide whether the completed worker turn permits the steady limit.

    Args:
        completion (_HttpBatchCompletion): Bounded numeric result of the turn.

    Returns:
        bool: True after a healthy framed transfer of at least the probe size.
    """
    stats = completion.stats
    return (
        completion.physical_complete and completion.outcome == 'ok'
        and any(size >= MEDIA_HTTP_BATCH_INITIAL_SIZE for size in stats.batch_sizes)
        and completion.elapsed < MEDIA_HTTP_BATCH_SLOW_SECONDS
        and completion.timings.encoded_bytes < MEDIA_HTTP_BATCH_LARGE_BYTES
        and not stats.wire_failures and not completion.decode_failures
        and not (stats.fallback_capability_missing or stats.fallback_unsupported
                 or stats.fallback_wire_disabled or stats.fallback_restricted
                 or stats.fallback_exceptional or stats.operation_failures)
    )


class _ThumbnailDiskLane:
    """Read authorized local thumbnails on one bounded, network-free lane."""

    def __init__(
        self, session: LinkingSession,
        prepare: Callable[[bytes], 'Image.Image'],
        observe: Callable[[MediaReadTimings, float], None],
    ) -> None:
        """Create a lazy cache worker without touching Tk or the transport gate.

        Args:
            session (LinkingSession): Exact authorization and cache owner.
            prepare (Callable[[bytes], Image.Image]): Worker-only RGB decoder.
            observe (Callable[[MediaReadTimings, float], None]): Thread-safe
                recorder for all attempted work, including discarded results.

        Returns:
            None: No thread starts until the first admitted local lookup.
        """
        self._session = session
        self._prepare = prepare
        self._observe = observe
        self._condition = threading.Condition()
        self._jobs: OrderedDict[
            tuple[int, int], tuple[int, LinkedPhoto],
        ] = OrderedDict()
        self._pending: dict[tuple[int, int], tuple[int, LinkedPhoto]] = {}
        self._ready: deque[tuple[int, _ThumbnailResult]] = deque()
        self._epoch = 0
        self._closed = False
        self._cancelled = threading.Event()
        self._thread: threading.Thread | None = None
        self._active_key: tuple[int, int] | None = None
        self.discarded_results = 0

    def submit(self, gallery_id: int, index: int, photo: LinkedPhoto) -> None:
        """Reserve one admitted descriptor before its disk-only lookup.

        Args:
            gallery_id (int): Physical gallery authorizing the descriptor.
            index (int): Current zero-based tile position.
            photo (LinkedPhoto): Exact current server descriptor.

        Returns:
            None: Duplicate pending descriptors share the existing lookup.
        """
        with self._condition:
            key = (gallery_id, index)
            identity = (self._epoch, photo)
            if self._closed or self._pending.get(key) == identity:
                return
            self._pending[key] = identity
            self._jobs[key] = identity
            if self._thread is None:
                self._thread = threading.Thread(
                    target=self._run, name='linked-gallery-disk', daemon=True,
                )
                self._thread.start()
            self._condition.notify_all()

    def retain(self, gallery_id: int, photos: tuple[LinkedPhoto, ...],
               indices: tuple[int, ...]) -> None:
        """Reprioritize only the current visible-first admitted descriptors.

        Args:
            gallery_id (int): Gallery currently mounted on Tk.
            photos (tuple[LinkedPhoto, ...]): Current ordered descriptors.
            indices (tuple[int, ...]): Visible-first indices in the bounded band.

        Returns:
            None: Departed jobs lose their reservation and cannot publish.
        """
        with self._condition:
            wanted = {(gallery_id, index): photos[index] for index in indices}
            finishing = {key for key in (self._active_key,) if key is not None}
            finishing.update(
                (result.gallery_id, result.index) for _epoch, result in self._ready
            )
            self._pending = {
                key: value for key, value in self._pending.items()
                if wanted.get(key) == value[1] or key in finishing
            }
            self._jobs = OrderedDict(
                ((gallery_id, index), self._jobs[(gallery_id, index)])
                for index in indices if (gallery_id, index) in self._jobs
                and (gallery_id, index) in self._pending
            )
            self._condition.notify_all()

    def contains(self, gallery_id: int, index: int, photo: LinkedPhoto) -> bool:
        """Inspect whether one current descriptor already has local work.

        Args:
            gallery_id (int): Exact gallery owning the tile.
            index (int): Zero-based tile position.
            photo (LinkedPhoto): Current descriptor to compare.

        Returns:
            bool: True for queued, in-flight or ready work for this identity.
        """
        with self._condition:
            return self._pending.get((gallery_id, index)) == (self._epoch, photo)

    def busy(self) -> bool:
        """Inspect local work without waiting for network or decoding.

        Returns:
            bool: Whether a pending descriptor or ready result needs delivery.
        """
        with self._condition:
            return bool(self._pending or self._ready)

    def pop_ready(self) -> _ThumbnailResult | None:
        """Transfer one current decoded result or miss to the GUI owner.

        Returns:
            _ThumbnailResult | None: One bounded result, or None when empty.
        """
        with self._condition:
            while self._ready:
                epoch, result = self._ready.popleft()
                key = (result.gallery_id, result.index)
                if self._pending.get(key) == (epoch, result.photo):
                    self._pending.pop(key)
                    self._condition.notify_all()
                    return result
                if result.image is not None:
                    result.image.close()
                self.discarded_results += 1
            self._condition.notify_all()
            return None

    def invalidate(self, *, close: bool = False) -> None:
        """Fence metadata replacement and release queued local rasters.

        Args:
            close (bool): Permanently stop this worker during viewer teardown.

        Returns:
            None: Late reads cannot publish into a new metadata generation.
        """
        with self._condition:
            self._epoch += 1
            self._jobs.clear()
            self._pending.clear()
            for _epoch, result in self._ready:
                if result.image is not None:
                    result.image.close()
            self.discarded_results += len(self._ready)
            self._ready.clear()
            if close:
                self._closed = True
                self._cancelled.set()
            self._condition.notify_all()

    def _run(self) -> None:
        """Decode only verified local bytes while bounding ready rasters.

        Returns:
            None: Close wakes the single worker and discards late results.
        """
        while True:
            with self._condition:
                self._condition.wait_for(lambda: self._closed or (
                    bool(self._jobs)
                    and len(self._ready) < MEDIA_THUMBNAIL_BATCH_SIZE
                ))
                if self._closed:
                    return
                (gallery_id, index), (epoch, photo) = self._jobs.popitem(
                    last=False,
                )
                self._active_key = (gallery_id, index)
            timings = MediaReadTimings()
            image: 'Image.Image | None' = None
            decode_ms = 0.0
            try:
                cached = self._session.cached_media_photo(
                    gallery_id, photo, thumbnail=True,
                    cancelled=self._cancelled, timings=timings,
                )
                if cached is not None and not self._cancelled.is_set():
                    started = time.perf_counter()
                    try:
                        image = self._prepare(cached[0])
                    finally:
                        decode_ms = (time.perf_counter() - started) * 1000.0
            except Exception:
                # A failed local lookup is a miss. The normal session read
                # remains the sole authorization/revalidation/network owner.
                pass
            self._observe(timings, decode_ms)
            result = _ThumbnailResult(
                gallery_id, index, photo, image, timings, decode_ms,
                time.perf_counter(),
            )
            with self._condition:
                key = (gallery_id, index)
                self._active_key = None
                if (self._closed or self._pending.get(key) != (epoch, photo)):
                    if image is not None:
                        image.close()
                    self.discarded_results += 1
                    continue
                self._ready.append((epoch, result))


_WorkerPayload: TypeAlias = (
    LinkedMediaPage
    | LinkedPresentation
    | str
    | int
    | tuple[LinkedMediaPage, ...]
    | tuple[LinkedMediaPage, LinkedPresentation | None]
    | tuple[threading.Event, int, bytes | None]
    | tuple[int, bytes | None]
    | tuple[int, bytes]
    | tuple[int, int, bytes]
    | tuple[int, int, 'Image.Image', 'Image.Image | None', bytes | None]
    | tuple[int, int, None, None, None, str]
    | tuple[int, int, None, None, None]
    | tuple[int, 'Image.Image | None']
    | tuple[int, 'Image.Image', bytes | None]
    | _ThumbnailResult
    | bytes
    | None
)
_WorkerEvent: TypeAlias = tuple[int, str, _WorkerPayload]
_SafePhotosPhase: TypeAlias = Literal[
    "page-fetch", "media-fetch", "media-decode", "page-apply", "media-apply",
]

def _log_safe_media_failure(
    phase: _SafePhotosPhase,
    kind: str,
    error: Exception,
    *,
    diagnostic_callback: Callable[[str], None] | None = None,
) -> str:
    """Log safe phase metadata without exception messages or request data.

    Args:
        phase (_SafePhotosPhase): Fixed phase distinguishing media transport
            from image decoding and page work.
        kind (str): Internal viewer operation, reduced to a known safe label.
        error (Exception): Caught failure whose class name is diagnostic only.
        diagnostic_callback (Callable[[str], None] | None): Optional owner for
            retaining the bounded summary in copied Photos diagnostics.

    Returns:
        str: Fixed phase, class, owned source site and validated response facts.
    """
    summary = format_safe_photos_failure(phase, kind, error)
    NATIVE_GALLERY_VIEWER_LOGGER.warning(
        "Photos failure %s", summary,
    )
    if diagnostic_callback is not None:
        try:
            diagnostic_callback(summary)
        except Exception:
            # Retention is supplemental and must not replace media fallback.
            NATIVE_GALLERY_VIEWER_LOGGER.warning(
                "Photos diagnostic retention callback failed.",
            )
    return summary


def _log_safe_photos_observation(
    summary: str | None,
    observation_callback: Callable[[str], None] | None,
) -> None:
    """Log and retain one allowlisted non-failure Photos observation.

    Args:
        summary (str | None): Prevalidated bounded observation, if available.
        observation_callback (Callable[[str], None] | None): Optional
            thread-safe owner for copied support observations.

    Returns:
        None: Callback failure cannot affect viewer input or image decoding.
    """
    if summary is None or not is_safe_photos_observation(summary):
        return
    NATIVE_GALLERY_VIEWER_LOGGER.info("Photos observation %s", summary)
    if observation_callback is not None:
        try:
            observation_callback(summary)
        except Exception:
            NATIVE_GALLERY_VIEWER_LOGGER.warning(
                "Photos observation retention callback failed.",
            )
