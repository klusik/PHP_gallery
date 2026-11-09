# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/native_gallery_viewer.py
# Module Type: Windows Client UI
# Purpose: Display bearer-authorized gallery media inside the Photos workspace.
# Responsibilities:
#   - Mount one bounded page and its thumbnails without worker-side Tk calls.
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

from .app_linking import (
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    NATIVE_MEDIA_MAX_BYTES,
    NATIVE_MEDIA_LEGACY_PAGE_SIZE,
    NATIVE_MEDIA_PAGE_SIZE,
    LinkedGallery,
    LinkedMediaPage,
    LinkedPhoto,
    LinkedPresentation,
    LinkedTrack,
    LinkingMediaThrottled,
    LinkingMediaUnavailable,
    LinkingError,
    LinkingResponseError,
    LinkingRevisionUnsupported,
    LinkingSession,
    MediaReadTimings,
)
from .flight_report_translations import tr
from .diagnostics import (
    PhotosDecodeError,
    PhotosDecodeFacts,
    format_safe_photos_decode_facts,
    format_safe_photos_failure,
    format_safe_photos_shortcut,
    is_safe_photos_observation,
)
from .gallery_lightbox import (
    LIGHTBOX_ZOOM_STEP,
    PhotoLightbox,
    detect_black_matte_crop,
)
from .ui_theme import GALLERY_TILE_BORDER_INSET, _attach_tooltip, icon_button
from .tk_scroll_input import vertical_scroll_delta

try:
    from PIL import Image, ImageTk
except ImportError:
    Image = None
    ImageTk = None


# Purpose: Retain useful photo detail while bounding normalized image memory.
# Type: tuple[int, int]. Units: pixels. Scope: one selected detail image.
# Consumers: _prepare_image. Rationale: 2048 by 1536 supports zoomed inspection.
MEDIA_IMAGE_MAX_SIZE = (2048, 1536)

# Purpose: Bound thumbnail width and height on the responsive gallery grid.
# Type: tuple[int, int]. Units: pixels. Scope: one gallery photo thumbnail.
# Consumers: _NativeGalleryViewer._load_thumbnails.
# Rationale: 144 px bounds both natural-aspect dimensions and retained pixels.
MEDIA_THUMBNAIL_SIZE = (144, 144)

# Purpose: Reduce large server fallback JPEGs before thumbnail RGB allocation.
# Type: tuple[int, int]. Units: decoded pixels. Scope: one thumbnail worker.
# Consumers: _NativeGalleryViewer._prepare_thumbnail.
# Rationale: 288 px keeps twice the final tile dimensions and enough detail
# for matte sampling; final 144 px and original source safety limits remain.
MEDIA_THUMBNAIL_DECODE_SIZE = (288, 288)

# Purpose: Coalesce wheel, scrollbar and geometry events into one viewport pass.
# Type: int. Units: milliseconds. Scope: one live gallery viewer.
# Consumers: _NativeGalleryViewer._on_grid_viewport.
# Rationale: 24 ms coalesces bursts while canvas position changes immediately;
# this initial scheduling policy does not establish a frame-rate guarantee.
MEDIA_VIEWPORT_COALESCE_MS = 24

# Purpose: Retain neighboring rows around the exact visible thumbnail viewport.
# Type: int. Units: grid rows on each side. Scope: one live gallery viewer.
# Consumers: _NativeGalleryViewer._apply_grid_viewport.
# Rationale: One row avoids edge churn while keeping Tk image retention bounded.
MEDIA_THUMBNAIL_OVERSCAN_ROWS = 1

# Purpose: Keep visible decoded thumbnails warm across album selections.
# Type: int. Units: decoded images. Scope: one shared live-session cache.
# Consumers: _NativeGalleryViewer._remember_thumbnail.
# Rationale: 256 bounds bookkeeping independently of raster dimensions. The
# byte cap is authoritative for full-sized images, including wide viewports.
MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT = 256

# Purpose: Bound decoded thumbnail memory across album selections.
# Type: int. Units: bytes. Scope: one shared live-session cache.
# Consumers: _NativeGalleryViewer._remember_thumbnail.
# Rationale: 16 MiB retains two 84-item bands (60 visible plus two 12-item
# overscan rows) at four accounted bytes per pixel, with room for history.
# Visible entries lead eviction; speculative overscan cannot displace history.
MEDIA_THUMBNAIL_CACHE_MAX_BYTES = 16 * 1024 * 1024

# Purpose: Limit decoded-thumbnail reuse when the catalogue has no content ETag.
# Type: float. Units: seconds. Scope: one entry in a live session cache.
# Consumers: _NativeGalleryViewer._cached_thumbnail.
# Rationale: One minute matches the server's public media freshness interval.
MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS = 60.0

# Purpose: Limit background revision checks to a quiet visible album cadence.
# Type: int. Units: milliseconds. Scope: one embedded viewer.
# Consumers: _NativeGalleryViewer._schedule_revision_check.
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
            tuple[
                int, int, bool | None, str | None, int | None,
                float | None, int | None,
            ],
            tuple['Image.Image', int, float],
        ] = OrderedDict()
        self.size_bytes = 0
        self.evictions = 0
        self.admission_refusals = 0
        self.expirations = 0
        self.validator_changes = 0

    def clear(self) -> None:
        """Release every retained raster when its owning session ends.

        Returns:
            None: No decoded gallery pixels remain in this cache.
        """
        with self.lock:
            for image, _size, _stored_at in self.entries.values():
                image.close()
            self.entries.clear()
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
            for key in tuple(self.entries):
                if key[0] == gallery_id and key[1] in targets:
                    image, size, _stored_at = self.entries.pop(key)
                    self.size_bytes -= size
                    image.close()

# Purpose: Identify a tile whose authorized preview could not be loaded.
# Type: str. Units: one visible Unicode glyph. Scope: unavailable photo tile.
# Consumers: _NativeGalleryViewer._show_thumbnail.
# Rationale: A neutral image mark avoids substituting server filenames for media.
MEDIA_THUMBNAIL_PLACEHOLDER = '▧'

# Purpose: Make an unavailable-preview mark legible without a caption.
# Type: int. Units: points. Scope: fallback photo-tile glyph.
# Consumers: LinkedPhotoPlaceholder.TButton.
# Rationale: 30 pt reads as an image status mark at ordinary desktop scaling.
MEDIA_THUMBNAIL_PLACEHOLDER_FONT_SIZE = 30

# Purpose: Keep the photo tile grid dense while retaining clear separation.
# Type: int. Units: pixels. Scope: horizontal and vertical tile gutters.
# Consumers: _NativeGalleryViewer._build_widgets.
# Rationale: 12 px reads clearly without wasting a narrow viewer viewport.
MEDIA_GRID_GUTTER = 12

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

# Purpose: Bound decoded image rasters before allocating an RGB bitmap.
# Type: int. Units: pixels. Scope: one original or thumbnail response.
# Consumers: _prepare_image, _prepare_thumbnail and _prepare_detail.
# Rationale: 36 megapixels bounds RGB memory. Details may first reduce JPEG/MPO
# decoder dimensions with draft; other rasters must already fit this bound.
MEDIA_IMAGE_MAX_PIXELS = 36_000_000

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

# Purpose: Provide the established medium slideshow pace without server settings.
# Type: int. Units: seconds. Scope: one manually started slideshow.
# Consumers: _slideshow_delay_ms. Rationale: five seconds is the prior default.
MEDIA_SLIDESHOW_DEFAULT_SECONDS = 5

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

# Purpose: Observe native player completion for gap-free playlist handoff.
# Type: int. Units: milliseconds. Scope: one active local track.
# Consumers: _NativeGalleryViewer._poll_audio.
# Rationale: 100 ms keeps handoffs responsive without polling an idle viewer.
MEDIA_AUDIO_POLL_MS = 100

# Purpose: Bound the Windows MCI playback-mode response buffer.
# Type: int. Units: wide characters. Scope: one native status command.
# Consumers: _NativeGalleryViewer._poll_audio.
# Rationale: 32 characters accommodate the documented MCI mode names.
MEDIA_MCI_MODE_CAPACITY = 32

# Purpose: Reap the stopped macOS audio player within a bounded wait.
# Type: float. Units: seconds. Scope: one owned afplay process.
# Consumers: _NativeGalleryViewer._release_audio_output.
# Rationale: 0.2 seconds lets an already killed child exit promptly.
MEDIA_AUDIO_REAP_SECONDS = 0.2

# Purpose: Resolve the Windows system directory before loading multimedia APIs.
# Type: int. Units: wide characters. Scope: GetSystemDirectoryW result buffer.
# Consumers: _NativeGalleryViewer._load_winmm.
# Rationale: 32768 accommodates the documented maximum Windows path.
WINDOWS_SYSTEM_DIRECTORY_BUFFER = 32768

# Purpose: Restrict native library lookup to the operating system directory.
# Type: int. Units: LoadLibraryEx flag bits. Scope: kernel32 and winmm loading.
# Consumers: _NativeGalleryViewer._load_winmm.
# Rationale: This flag prevents DLL search through the app or working folders.
LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x00000800

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
    """

    gallery_id: int
    index: int
    photo: LinkedPhoto
    image: 'Image.Image | None'
    timings: MediaReadTimings
    decode_ms: float
    enqueued_at: float


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
_MciSender: TypeAlias = Callable[[str, ctypes.c_wchar_p | None, int, int | None], int]
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
    logging.getLogger(__name__).warning(
        "Photos failure %s", summary,
    )
    if diagnostic_callback is not None:
        try:
            diagnostic_callback(summary)
        except Exception:
            # Retention is supplemental and must not replace media fallback.
            logging.getLogger(__name__).warning(
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
    logging.getLogger(__name__).info("Photos observation %s", summary)
    if observation_callback is not None:
        try:
            observation_callback(summary)
        except Exception:
            logging.getLogger(__name__).warning(
                "Photos observation retention callback failed.",
            )


class _NativeGalleryViewer:
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

        Returns:
            None: The viewer owns the returned child frame until it is destroyed.
        """
        self.session = session
        self.gallery = gallery
        self.language = language
        self._preview_result = preview_result
        self._show_title = show_title
        self._owns_thumbnail_memory = thumbnail_memory is None
        self._thumbnail_memory = thumbnail_memory or GalleryThumbnailMemory()
        self._context_menu = context_menu
        self._diagnostic_callback = diagnostic_callback
        self._on_photo_selected = photo_selected
        self._observation_callback = observation_callback
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
        self._thumbnail_result_lock = threading.Lock()
        self._thumbnail_metrics_lock = threading.Lock()
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
        self._mci: _MciSender | None = None
        self._photo_images: list[object | None] = []
        self._detail_cache: OrderedDict[
            int, tuple[
                float, 'Image.Image', bytes, int,
                tuple[int | None, str | None, float | None, int | None],
            ],
        ] = OrderedDict()
        self._detail_cache_bytes = 0
        self._pending_prefetch_ids: list[int] = []
        self._prefetch_direction = 1
        self._tile_buttons: list[ttk.Button] = []
        self._tile_tooltips: list[tk.StringVar] = []
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
        self._admitted_thumbnail_indices: set[int] = set()
        self._viewport_after_id: str | None = None
        self._viewport_event_count = 0
        self._viewport_update_count = 0
        self._thumbnail_image_create_count = 0
        self._thumbnail_image_release_count = 0
        self._thumbnail_warm_cache_hit_count = 0
        self._thumbnail_batch_count = 0
        self._thumbnail_batch_indices: tuple[int, ...] = ()
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

    def _text(self, key: str) -> str:
        """Return a translated viewer label for the current UI language.

        Args:
            key (str): Stable `linking_media_` translation key.

        Returns:
            str: Localized label with the translation module's English fallback.
        """
        try:
            current = self.language()
        except Exception:
            current = 'en'
        return tr(current, key)

    def _build_widgets(self) -> None:
        """Create the compact gallery and presentation controls.

        Returns:
            None: Widgets are installed in the returned child frame.
        """
        shell = ttk.Frame(self.frame, padding=12, style='Card.TFrame')
        shell.pack(fill='both', expand=True)
        gallery_title = ''.join(
            char if char.isprintable() else ' '
            for char in self.gallery.title
        ).strip()[:160]
        if self._show_title:
            ttk.Label(
                shell,
                text=gallery_title or self._text('linking_media_title'),
                style='Section.TLabel',
            ).pack(anchor='w')
        self.status = ttk.Label(shell, text=self._text('linking_media_loading'))
        self.status.pack(anchor='w', pady=(4, 8))

        body = ttk.Frame(shell, style='Card.TFrame')
        body.pack(fill='both', expand=True)
        style = ttk.Style(self.window)
        surface = style.lookup('Card.TFrame', 'background') or '#253443'
        ink = style.lookup('TLabel', 'foreground') or '#F2F6FA'
        focus = style.lookup('Primary.TButton', 'background') or '#08AED0'
        style.configure(
            'LinkedPhoto.TButton', background=surface, foreground=ink,
            borderwidth=2, padding=0, relief='flat', focuscolor=focus,
        )
        style.configure(
            'LinkedPhotoSelected.TButton', background=surface, foreground=ink,
            bordercolor=focus, lightcolor=focus, darkcolor=focus,
            borderwidth=2, padding=0, relief='solid', focuscolor=focus,
        )
        style.configure(
            'LinkedPhotoPlaceholder.TButton',
            background=surface, foreground=ink, borderwidth=2, padding=0,
            relief='flat', focuscolor=focus,
            font=('Segoe UI Symbol', MEDIA_THUMBNAIL_PLACEHOLDER_FONT_SIZE),
        )
        tile_element = (
            'Gallery.tile' if 'Gallery.tile' in style.element_names()
            else 'Button.border'
        )
        tile_layout = [(tile_element, {
            'sticky': 'nswe',
            'children': [('Button.padding', {
                'sticky': 'nswe',
                'children': [('Button.label', {'sticky': 'nswe'})],
            })],
        })]
        for tile_style in (
            'LinkedPhoto.TButton', 'LinkedPhotoSelected.TButton',
            'LinkedPhotoPlaceholder.TButton',
        ):
            style.layout(tile_style, tile_layout)
        style.map(
            'LinkedPhoto.TButton',
            background=[('pressed', surface), ('active', surface)],
            foreground=[('disabled', ink)],
        )
        style.map(
            'LinkedPhotoSelected.TButton',
            background=[('pressed', surface), ('active', surface)],
            bordercolor=[
                ('selected', focus), ('focus', focus),
                ('pressed', focus), ('active', focus),
            ],
            foreground=[('disabled', ink)],
        )
        right = ttk.Frame(body, style='Card.TFrame')
        right.pack(fill='both', expand=True)
        self.grid_canvas = tk.Canvas(
            right, highlightthickness=0, background=surface,
        )
        self.grid_scrollbar = ttk.Scrollbar(
            right, orient='vertical', command=self.grid_canvas.yview,
        )
        self.grid_canvas.configure(yscrollcommand=self._set_grid_scrollbar)
        self.grid_canvas.pack(side='left', fill='both', expand=True)
        self.grid_scrollbar.pack(side='right', fill='y')
        self.grid_frame = ttk.Frame(self.grid_canvas, style='Card.TFrame')
        self.grid_window = self.grid_canvas.create_window(
            (0, 0), window=self.grid_frame, anchor='nw',
        )
        self.grid_frame.bind('<Configure>', self._on_grid_configure)
        self.grid_canvas.bind('<Configure>', self._on_grid_resize)
        self.grid_canvas.bind('<Configure>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<Map>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<MouseWheel>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<Button-4>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<Button-5>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<MouseWheel>', self._on_grid_wheel, add='+')
        self._bind_precise_scroll(self.grid_canvas)
        self.grid_canvas.bind('<Button-4>', self._on_grid_wheel, add='+')
        self.grid_canvas.bind('<Button-5>', self._on_grid_wheel, add='+')
        self.grid_frame.bind('<MouseWheel>', self._on_grid_wheel, add='+')
        self._bind_precise_scroll(self.grid_frame)
        self.grid_frame.bind('<Button-4>', self._on_grid_wheel, add='+')
        self.grid_frame.bind('<Button-5>', self._on_grid_wheel, add='+')
        for sequence in ('<Button-3>', '<Button-2>', '<Control-Button-1>'):
            self.grid_canvas.bind(sequence, self._on_empty_context, add='+')
            self.grid_frame.bind(sequence, self._on_empty_context, add='+')


        actions = ttk.Frame(shell, style='Card.TFrame')
        actions.pack(fill='x', pady=(10, 0))
        self.retry_button = icon_button(
            actions, 'refresh', self._retry_current,
            self._text('linking_media_retry'),
        )
        self.retry_button.configure(state='disabled')
        self.retry_button.pack(side='left', padx=(8, 0))
        self.retry_button.pack_forget()
        self.slide_button = icon_button(
            actions, 'play', self._toggle_slideshow,
            self._slideshow_tooltip,
        )
        self.slide_button.configure(state='disabled')
        self.slide_button.pack(side='left', padx=(8, 0))
        self.music_button = icon_button(
            actions,
            '♫',
            self._toggle_music_panel,
            self._text('linking_media_presentation'),
        )
        self.music_button.pack(side='left', padx=(8, 0))

    def _bind_window_keys(self) -> None:
        """Bind viewer navigation keys additively on the owning main window.

        Returns:
            None: Only handlers owned by this embedded viewer are registered.
        """
        key_callbacks: tuple[tuple[str, Callable[[tk.Event], str | None]], ...] = (
            ('<Left>', self._on_left),
            ('<Right>', self._on_right),
            ('<F11>', self._on_fullscreen),
            ('<f>', self._on_fullscreen),
            ('<F>', self._on_fullscreen),
            ('<plus>', self._on_zoom_in),
            ('<equal>', self._on_zoom_in),
            ('<minus>', self._on_zoom_out),
            ('<0>', self._on_fit),
        )
        for sequence, callback in key_callbacks:
            identifier = self.window.bind(sequence, callback, add='+')
            self._window_bindings.append((sequence, identifier))

    def _on_left(self, _event: tk.Event) -> str | None:
        """Move to the previous gallery photo when this view is mounted.

        Args:
            _event (tk.Event): Main-window Left key event.

        Returns:
            str | None: Tk break marker when this viewer consumes navigation.
        """
        if self._closed:
            return None
        if self._lightbox is not None and self._lightbox.visible:
            self._move_lightbox(-1)
            return 'break'
        return None

    def _on_right(self, _event: tk.Event) -> str | None:
        """Move to the next gallery photo when this view is mounted.

        Args:
            _event (tk.Event): Main-window Right key event.

        Returns:
            str | None: Tk break marker when this viewer consumes navigation.
        """
        if self._closed:
            return None
        if self._lightbox is not None and self._lightbox.visible:
            self._move_lightbox(1)
            return 'break'
        return None

    def _on_fullscreen(self, _event: tk.Event) -> str | None:
        """Toggle fullscreen for plain F or F11 while the detail is active.

        Args:
            _event (tk.Event): F, shifted F or F11 event with modifier state.

        Returns:
            str | None: Tk break marker for an unmodified fullscreen action;
                text-entry widgets and command combinations retain their keys.
        """
        lightbox = self._lightbox
        visible = lightbox is not None and lightbox.visible
        fullscreen_before = bool(
            lightbox is not None and lightbox._fullscreen,
        )
        widget = getattr(_event, 'widget', None)
        text_input = isinstance(widget, (
            tk.Entry, ttk.Entry, tk.Text, ttk.Combobox,
            tk.Spinbox, ttk.Spinbox,
        ))
        widget_category = (
            'text-entry' if text_input else
            'canvas' if isinstance(widget, tk.Canvas) else
            'control' if isinstance(widget, tk.Misc) else 'other'
        )
        state = getattr(_event, 'state', 0)
        if type(state) is not int:
            state = 0
        key = getattr(_event, 'keysym', '')
        if type(key) is not str:
            key = ''
        reason = 'inactive'
        result: str | None = None
        if visible and not text_input:
            modifiers = (
                MEDIA_FULLSCREEN_WINDOWS_COMMAND_MODIFIERS
                if sys.platform == 'win32'
                else MEDIA_FULLSCREEN_COMMAND_MODIFIERS
            )
            if sys.platform == 'darwin':
                modifiers |= 0x0010
            if state & modifiers:
                reason = 'command-modifier'
            else:
                assert lightbox is not None
                lightbox._toggle_fullscreen()
                reason = 'toggled'
                result = 'break'
        elif text_input:
            reason = 'text-input'
        summary = format_safe_photos_shortcut(
            key, state, widget_category, visible, fullscreen_before,
            bool(lightbox is not None and lightbox._fullscreen), reason,
        )
        _log_safe_photos_observation(
            summary, self._observation_callback,
        )
        return result

    def _on_zoom_in(self, _event: tk.Event) -> str | None:
        """Increase zoom with a plus or equals key.

        Args:
            _event (tk.Event): Main-window plus or equals key event.

        Returns:
            str | None: Tk break marker when a photo receives the shortcut.
        """
        if self._lightbox is None or not self._lightbox.visible:
            return None
        self._lightbox._change_zoom(LIGHTBOX_ZOOM_STEP)
        return 'break'

    def _on_zoom_out(self, _event: tk.Event) -> str | None:
        """Decrease zoom with the minus key.

        Args:
            _event (tk.Event): Main-window minus key event.

        Returns:
            str | None: Tk break marker when a photo receives the shortcut.
        """
        if self._lightbox is None or not self._lightbox.visible:
            return None
        self._lightbox._change_zoom(1 / LIGHTBOX_ZOOM_STEP)
        return 'break'

    def _on_fit(self, _event: tk.Event) -> str | None:
        """Reset active photo zoom without changing its crop mode.

        Args:
            _event (tk.Event): Main-window zero key event.

        Returns:
            str | None: Tk break marker when a photo receives the shortcut.
        """
        if self._lightbox is None or not self._lightbox.visible:
            return None
        return self._lightbox._fit_key(_event)

    def _on_context_click(self, event: tk.Event) -> None:
        """Dismiss music or map panels after clicks outside their anchor.

        Args:
            event (tk.Event): Main-window click event.

        Returns:
            None: Each active contextual panel keeps clicks within its surface.
        """
        self._close_music_outside(event)
        if self._lightbox is not None and self._lightbox.visible:
            self._lightbox._outside_location_click(event)

    def _on_grid_configure(self, _event: tk.Event) -> None:
        """Keep the scrollable photo grid's vertical extent current.

        Args:
            _event (tk.Event): Frame size event emitted after tile layout.

        Returns:
            None: The canvas scroll region follows the complete tile grid.
        """
        bounds = self.grid_canvas.bbox('all')
        self.grid_canvas.configure(scrollregion=bounds)
        if bounds is not None and bounds[3] <= self.grid_canvas.winfo_height():
            self.grid_scrollbar.pack_forget()
        elif not self.grid_scrollbar.winfo_manager():
            self.grid_scrollbar.pack(side='right', fill='y')
        self._on_grid_viewport(_event)

    def _set_grid_scrollbar(self, first: str, last: str) -> None:
        """Update the scrollbar and admit thumbnails for its new viewport.

        Args:
            first (str): First visible canvas fraction from Tk.
            last (str): Last visible canvas fraction from Tk.
        Returns:
            None: Scroll position remains owned by the native canvas.
        """
        self.grid_scrollbar.set(first, last)
        self._on_grid_viewport(None)

    def _visible_photo_indices(self) -> tuple[int, ...]:
        """Return tile indices whose actual widget bounds intersect the viewport.

        Returns:
            tuple[int, ...]: Visible indices eligible for thumbnail requests.
        """
        top = self.grid_canvas.winfo_rooty() - self.grid_frame.winfo_rooty()
        bottom = top + self.grid_canvas.winfo_height()
        columns = max(1, self._grid_columns)
        row_count = (len(self._photos) + columns - 1) // columns
        first_row = max(
            0, min(row_count - 1,
                   self.grid_frame.grid_location(0, int(top))[1]),
        )
        last_row = max(
            0,
            min(
                row_count - 1,
                self.grid_frame.grid_location(0, max(0, int(bottom) - 1))[1],
            ),
        )
        first_index = first_row * columns
        last_index = min(
            len(self._tile_buttons), (last_row + 1) * columns,
        )
        return tuple(
            index for index in range(first_index, last_index)
            if (self._tile_buttons[index].winfo_y()
                + self._tile_buttons[index].winfo_height() > top
                and self._tile_buttons[index].winfo_y() < bottom)
        )

    def _on_grid_viewport(self, _event: tk.Event | None) -> None:
        """Coalesce viewport signals before updating thumbnail admission.

        Args:
            _event (tk.Event | None): Canvas event, or None from scrollbar updates.

        Returns:
            None: One owned timer applies the latest viewport after a short delay.
        """
        if self._closed:
            return
        self._viewport_event_count += 1
        if self._viewport_after_id is not None:
            return

        after_id: str | None = None

        def apply_viewport() -> None:
            """Apply only the timer instance still owned by this viewer.

            Returns:
                None: A cancelled timer cannot clear or run its replacement.
            """
            self._apply_grid_viewport(after_id)

        after_id = self.window.after(
            MEDIA_VIEWPORT_COALESCE_MS, apply_viewport,
        )
        self._viewport_after_id = after_id

    def _apply_grid_viewport(self, after_id: str | None) -> None:
        """Apply visible and one-row overscan thumbnail admission on Tk.

        Args:
            after_id (str | None): Exact callback identity captured at schedule.

        Returns:
            None: Stale callbacks are ignored; adjacent-row PhotoImages remain
                bounded and reusable during small scrolls.
        """
        if after_id is None or self._viewport_after_id != after_id:
            return
        self._viewport_after_id = None
        if self._closed or not self.grid_canvas.winfo_viewable():
            return
        started = time.perf_counter()
        self._viewport_update_count += 1
        if self._photos:
            visible = tuple(self._visible_photo_indices())
            visible_set = set(visible)
            self._visible_thumbnail_indices = visible_set
            admitted: set[int] = set()
            if visible:
                columns = max(1, self._grid_columns)
                first_row = min(visible) // columns
                last_row = max(visible) // columns
                first_row = max(
                    0, first_row - MEDIA_THUMBNAIL_OVERSCAN_ROWS,
                )
                last_row = min(
                    (len(self._photos) - 1) // columns,
                    last_row + MEDIA_THUMBNAIL_OVERSCAN_ROWS,
                )
                admitted.update(range(
                    first_row * columns,
                    min(len(self._photos), (last_row + 1) * columns),
                ))
            self._admitted_thumbnail_indices = admitted
            self._thumbnail_pending = [
                index for index in self._thumbnail_pending if index in admitted
            ]
            for index in tuple(self._thumbnail_loaded - admitted):
                self._release_thumbnail_image(index)
            admitted_order = sorted(
                admitted, key=lambda index: (index not in visible_set, index),
            )
            self._thumbnail_retained_hit_count += len(
                self._thumbnail_loaded & visible_set,
            )
            self._thumbnail_disk_lane.retain(
                self.gallery.gallery_id, self._photos, tuple(admitted_order),
            )
            self._warm_thumbnail_pending = [
                item for item in self._warm_thumbnail_pending
                if (item[0] == self.gallery.gallery_id
                    and item[1] in admitted
                    and self._photos[item[1]] == item[2])
            ]
            for index in admitted_order:
                identity = (self.gallery.gallery_id, index, self._photos[index])
                if (index not in self._thumbnail_loaded
                        and index not in self._thumbnail_pending
                        and not self._thumbnail_disk_lane.contains(
                            self.gallery.gallery_id, index, self._photos[index],
                        )
                        and identity not in self._warm_thumbnail_pending):
                    self._warm_thumbnail_pending.append(identity)
            self._warm_thumbnail_pending.sort(
                key=lambda item: (item[1] not in visible_set, item[1]),
            )
            self._thumbnail_pending.sort(
                key=lambda index: (index not in visible_set, index),
            )
            if self._worker_kind == 'thumbnail_batch':
                unfinished = tuple(
                    index for index in self._thumbnail_batch_indices
                    if index not in self._thumbnail_loaded
                )
                wanted = tuple(
                    index for index in admitted_order
                    if index in self._thumbnail_pending
                )
                if (any(index not in admitted for index in unfinished)
                        or wanted[:len(unfinished)] != unfinished):
                    self._thumbnail_cancelled.set()
            self._drain_warm_thumbnail_slice()
            bounds = self.grid_canvas.bbox('all')
            scrollable = bool(
                bounds is not None
                and bounds[3] > self.grid_canvas.winfo_height()
            )
            viewport_not_filled = bool(
                bounds is not None
                and bounds[3] <= self.grid_canvas.winfo_height()
            )
            near_scroll_end = bool(
                scrollable and visible
                and max(visible) >= len(self._photos) - self._grid_columns * 2
            )
            if (self._next_after is not None and not self._loading_more
                    and visible and (viewport_not_filled or near_scroll_end)):
                # Admit the next metadata page before launching a thumbnail
                # batch that this same viewport turn would immediately cancel.
                self._load_more()
            self._dispatch_pending_work()
        elapsed_ms = (time.perf_counter() - started) * 1000.0
        self._viewport_peak_update_ms = max(
            self._viewport_peak_update_ms, elapsed_ms,
        )

    def _cancel_grid_viewport_callback(self) -> None:
        """Cancel the owned coalescing timer and fence a stale callback.

        Returns:
            None: A late callback cannot run viewport work after replacement.
        """
        after_id, self._viewport_after_id = self._viewport_after_id, None
        if after_id is None:
            return
        try:
            self.window.after_cancel(after_id)
        except tk.TclError:
            pass

    def _drain_warm_thumbnail_slice(self) -> None:
        """Attach a bounded group of warm cached thumbnails on Tk.

        Returns:
            None: At most four cache lookups/copies run before yielding; the
                remaining descriptors continue in an identity-fenced timer.
        """
        if self._closed or self._thumbnail_gui_after_id is not None:
            return
        started = time.perf_counter()
        count = 0
        while (self._warm_thumbnail_pending
               and count < MEDIA_THUMBNAIL_GUI_SLICE_SIZE
               and (count == 0 or time.perf_counter() - started
                    < MEDIA_THUMBNAIL_GUI_SLICE_SECONDS)):
            gallery_id, index, photo = self._warm_thumbnail_pending.pop(0)
            if (gallery_id == self.gallery.gallery_id
                    and index in self._admitted_thumbnail_indices
                    and index < len(self._photos)
                    and self._photos[index] == photo):
                cached = self._cached_thumbnail(photo)
                if cached is not None:
                    self._thumbnail_warm_cache_hit_count += 1
                    attach_started = time.perf_counter()
                    self._show_thumbnail(index, cached, remember=False)
                    self._thumbnail_tk_ms += (
                        time.perf_counter() - attach_started
                    ) * 1000.0
                elif index not in self._thumbnail_pending:
                    self._thumbnail_disk_lane.submit(gallery_id, index, photo)
                    self._schedule_queue_poll()
            count += 1
        self._thumbnail_pending.sort(
            key=lambda index: (
                index not in self._visible_thumbnail_indices, index,
            ),
        )
        if self._warm_thumbnail_pending:
            after_id: str | None = None

            def continue_slice() -> None:
                """Run only the still-owned warm-image continuation.

                Returns:
                    None: A replaced or cancelled timer cannot attach images.
                """
                if self._thumbnail_gui_after_id != after_id:
                    return
                self._thumbnail_gui_after_id = None
                self._drain_warm_thumbnail_slice()
                self._dispatch_pending_work()

            after_id = self.window.after(
                MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS, continue_slice,
            )
            self._thumbnail_gui_after_id = after_id

    def _cancel_thumbnail_gui_slice(self) -> None:
        """Cancel and release pending warm-image work owned by this viewer.

        Returns:
            None: Late slice callbacks fail their timer-identity check.
        """
        after_id, self._thumbnail_gui_after_id = (
            self._thumbnail_gui_after_id, None,
        )
        if after_id is not None:
            try:
                self.window.after_cancel(after_id)
            except tk.TclError:
                pass
        self._warm_thumbnail_pending.clear()

    def _release_thumbnail_image(self, index: int) -> None:
        """Release one Tk thumbnail reference outside its admitted row band.

        Args:
            index (int): Zero-based tile whose retained PhotoImage is released.

        Returns:
            None: Counters record actual image-reference eviction only.
        """
        if not 0 <= index < len(self._photo_images):
            self._thumbnail_loaded.discard(index)
            return
        image = self._photo_images[index]
        if image is not None:
            self._thumbnail_image_release_count += 1
            self._photo_images[index] = None
        self._thumbnail_loaded.discard(index)
        if index < len(self._tile_buttons):
            self._tile_buttons[index].configure(
                image='', text=MEDIA_THUMBNAIL_PLACEHOLDER,
                style='LinkedPhotoPlaceholder.TButton',
            )

    def _observe_thumbnail_read(
        self, timings: MediaReadTimings, decode_ms: float,
    ) -> None:
        """Record attempted I/O before GUI admission or cancellation fencing.

        Args:
            timings (MediaReadTimings): Source and cumulative read observations.
            decode_ms (float): Actual decoder work in milliseconds.

        Returns:
            None: Dropped results still contribute their performed work.
        """
        with self._thumbnail_metrics_lock:
            self._thumbnail_disk_ms += timings.disk_read_ms
            self._thumbnail_disk_write_ms += timings.disk_write_ms
            self._thumbnail_gate_wait_ms += timings.gate_wait_ms
            self._thumbnail_renewal_ms += timings.renewal_ms
            self._thumbnail_network_ms += timings.network_ms
            self._thumbnail_network_reads += timings.network_reads
            self._thumbnail_decode_ms += decode_ms
            for name in self._thumbnail_transport_totals:
                self._thumbnail_transport_totals[name] += getattr(timings, name)
            self._thumbnail_cache_outcomes[timings.cache_state] += 1
            if timings.source == 'disk':
                self._thumbnail_source_disk_count += 1
            elif timings.source == 'network':
                self._thumbnail_source_network_count += 1
            elif timings.source == 'revalidated':
                self._thumbnail_source_revalidated_count += 1

    def _observe_detail_read(
        self, kind: Literal['detail', 'prefetch'], timings: MediaReadTimings,
    ) -> None:
        """Record numeric source and transport facts for one detail operation.

        Args:
            kind (Literal['detail', 'prefetch']): Fixed operation category.
            timings (MediaReadTimings): Caller-owned aggregate media facts.

        Returns:
            None: Source, HTTP request count and encoded bytes join the
                process-safe summary without retaining media or identities.
        """
        with self._thumbnail_metrics_lock:
            if timings.source in ('disk', 'network', 'revalidated'):
                self._detail_media_metrics[
                    f'{kind}_source_{timings.source}'
                ] += 1
            self._detail_media_metrics[f'{kind}_http_requests'] += (
                timings.http_requests
            )
            self._detail_media_metrics[f'{kind}_encoded_bytes'] += (
                timings.encoded_bytes
            )

    def render_diagnostics(self) -> str:
        """Return fixed numeric viewport, thumbnail and detail counters.

        Returns:
            str: Fixed numeric grid/detail sources, transport requests, byte
                counts, event and image totals plus cumulative timing without
                photo IDs, titles or request data.
        """
        retained = sum(
            image is not None for image in self._photo_images
        )
        with self._thumbnail_metrics_lock:
            transport = ' '.join(
                f'{name}={value:.2f}'
                for name, value in self._thumbnail_transport_totals.items()
            )
            outcomes = ' '.join(
                f'cache_{name}={value}'
                for name, value in self._thumbnail_cache_outcomes.items()
            )
            detail_metrics = ' '.join(
                f'{name}={value}'
                for name, value in self._detail_media_metrics.items()
            )
        return (
            'Photos grid: '
            f'viewport_events={self._viewport_event_count} '
            f'viewport_updates={self._viewport_update_count} '
            f'tk_images_created={self._thumbnail_image_create_count} '
            f'tk_images_released={self._thumbnail_image_release_count} '
            f'warm_cache_hits={self._thumbnail_warm_cache_hit_count} '
            f'retained_hits={self._thumbnail_retained_hit_count} '
            f'local_misses={self._thumbnail_local_miss_count} '
            f'discarded={self._thumbnail_discarded_count} '
            f'cancelled_reads={self._thumbnail_cancelled_read_count} '
            f'disk_discarded={self._thumbnail_disk_lane.discarded_results} '
            f'memory_bytes={self._thumbnail_memory.size_bytes} '
            f'evictions={self._thumbnail_memory.evictions} '
            f'admission_refusals={self._thumbnail_memory.admission_refusals} '
            f'expired={self._thumbnail_memory.expirations} '
            f'validator_changes={self._thumbnail_memory.validator_changes} '
            f'batches={self._thumbnail_batch_count} '
            f'source_disk={self._thumbnail_source_disk_count} '
            f'source_network={self._thumbnail_source_network_count} '
            f'source_revalidated={self._thumbnail_source_revalidated_count} '
            f'disk_ms={self._thumbnail_disk_ms:.2f} '
            f'disk_write_ms={self._thumbnail_disk_write_ms:.2f} '
            f'gate_wait_ms={self._thumbnail_gate_wait_ms:.2f} '
            f'renewal_ms={self._thumbnail_renewal_ms:.2f} '
            f'network_ms={self._thumbnail_network_ms:.2f} '
            f'network_reads={self._thumbnail_network_reads} '
            f'{transport} {outcomes} {detail_metrics} '
            f'decode_ms={self._thumbnail_decode_ms:.2f} '
            f'queue_wait_ms={self._thumbnail_queue_wait_ms:.2f} '
            f'tk_ms={self._thumbnail_tk_ms:.2f} '
            f'peak_update_ms={self._viewport_peak_update_ms:.2f} '
            f'visible={len(self._visible_thumbnail_indices)} '
            f'admitted={len(self._admitted_thumbnail_indices)} '
            f'retained={retained}'
        )

    def _on_grid_resize(self, event: tk.Event) -> None:
        """Resize the embedded grid and recompute its responsive columns.

        Args:
            event (tk.Event): Canvas resize event with its current pixel width.

        Returns:
            None: Tiles use all available width with even gutters.
        """
        self.grid_canvas.itemconfigure(self.grid_window, width=event.width)
        columns = max(1, event.width
                      // (MEDIA_THUMBNAIL_SIZE[0] + MEDIA_GRID_GUTTER
                          + 2 * GALLERY_TILE_BORDER_INSET))
        if columns == self._grid_columns:
            return
        for column in range(getattr(self, '_grid_columns', 1)):
            self.grid_frame.columnconfigure(column, weight=0, uniform='')
        for column in range(columns):
            self.grid_frame.columnconfigure(column, weight=1, uniform='photo')
        self._grid_columns = columns
        for index, button in enumerate(self._tile_buttons):
            row = index // columns
            self.grid_frame.grid_rowconfigure(
                row, minsize=MEDIA_GRID_ROW_MINIMUM_HEIGHT,
            )
            button.grid_configure(
                row=row,
                column=index % columns,
                sticky='nsew',
            )
        self._on_grid_viewport(None)

    def _on_grid_wheel(self, event: tk.Event) -> None:
        """Scroll the photo grid while the pointer is over its canvas.

        Args:
            event (tk.Event): Native wheel event carrying the signed delta.

        Returns:
            None: The canvas scrolls one platform-adjusted unit.
        """
        if self._closed or not self.grid_canvas.winfo_exists():
            return
        pointer = self.window.winfo_containing(event.x_root, event.y_root)
        while pointer is not None and pointer is not self.grid_canvas:
            pointer = getattr(pointer, 'master', None)
        if pointer is None:
            return
        delta = vertical_scroll_delta(self.window, event)
        if not delta:
            return
        direction = 1 if delta > 0 else -1
        self.grid_canvas.yview_scroll(-direction, 'units')
        self._on_grid_viewport(event)

    def _bind_precise_scroll(self, widget: tk.Misc) -> None:
        """Bind Tk's high-resolution event only when the interpreter supports it.

        Args:
            widget (tk.Misc): Grid widget receiving native touchpad gestures.
        Returns:
            None: Older Tk runtimes retain ordinary wheel support unchanged.
        """
        try:
            widget.bind(
                '<TouchpadScroll>', self._on_precise_grid_scroll, add='+',
            )
        except tk.TclError:
            return

    def _on_precise_grid_scroll(self, event: tk.Event) -> None:
        """Route an explicitly precise Tk event to the normal grid owner.

        Args:
            event (tk.Event): High-resolution TouchpadScroll event.
        Returns:
            None: The grid applies pixel-aware scrolling for its vertical axis.
        """
        pointer = self.window.winfo_containing(event.x_root, event.y_root)
        while pointer is not None and pointer is not self.grid_canvas:
            pointer = getattr(pointer, 'master', None)
        if pointer is None or self._closed:
            return
        delta = vertical_scroll_delta(self.window, event, precise=True)
        if delta:
            bounds = self.grid_canvas.bbox('all')
            if bounds is None:
                return
            content_height = bounds[3] - bounds[1]
            if content_height <= 0:
                return
            current = self.grid_canvas.yview()
            if not current:
                return
            self.grid_canvas.yview_moveto(current[0] - delta / content_height)
            self._on_grid_viewport(event)

    def _on_photo_context(self, index: int, event: tk.Event) -> str | None:
        """Dispatch a photo context action without activating its tile.

        Args:
            index (int): Zero-based photo tile associated with the event.
            event (tk.Event): Mouse or keyboard context event.
        Returns:
            str | None: Callback result, usually ``break`` after opening a menu.
        """
        if self._context_menu is None or not 0 <= index < len(self._photos):
            return None
        return self._context_menu(self._photos[index], event)

    def selected_photo_ids(self) -> tuple[int, ...]:
        """Return selected visible photo IDs in the current gallery order.

        Returns:
            tuple[int, ...]: Exact loaded IDs selected for an explicit action.
        """
        return tuple(
            photo.image_id for photo in self._photos
            if photo.image_id in self._selected_photo_ids
        )

    def toggle_photo_selection(self, image_id: int) -> bool:
        """Toggle one currently loaded image in the explicit selection set.

        Args:
            image_id (int): Exact photo ID represented by a loaded tile.

        Returns:
            bool: True when selected after the operation.
        """
        index = next((index for index, photo in enumerate(self._photos)
                      if photo.image_id == image_id), None)
        if self._closed or index is None:
            return False
        if image_id in self._selected_photo_ids:
            self._selected_photo_ids.remove(image_id)
            selected = False
        else:
            if len(self._selected_photo_ids) >= NATIVE_MEDIA_DELETE_BATCH_MAX:
                self.status.pack(anchor='w', pady=(4, 8))
                self.status.configure(
                    text=self._text('linking_media_selection_limit').format(
                        limit=NATIVE_MEDIA_DELETE_BATCH_MAX,
                    ),
                )
                return False
            self._selected_photo_ids.add(image_id)
            selected = True
        self._selection_anchor_index = index
        self._focus_tile(index)
        self._render_photo_selection((index,))
        if self._on_photo_selected is not None:
            self._on_photo_selected(self._photos[index])
        return selected

    def select_photo(self, index: int) -> bool:
        """Focus one loaded photo and notify its metadata inspector.

        Args:
            index (int): Zero-based index in the current loaded gallery page.

        Returns:
            bool: True when a current photo became the single selection.
        """
        if self._closed or not 0 <= index < len(self._photos):
            return False
        previous = set(self._selected_photo_ids)
        photo = self._photos[index]
        self._selected_photo_ids = {photo.image_id}
        self._selection_anchor_index = index
        self._focus_tile(index)
        changed = tuple(
            position for position, item in enumerate(self._photos)
            if item.image_id in previous or item.image_id == photo.image_id
        )
        self._render_photo_selection(changed)
        if self._on_photo_selected is not None:
            self._on_photo_selected(photo)
        return True

    def select_photo_range(self, index: int) -> bool:
        """Select an anchored contiguous loaded range within the delete limit.

        Args:
            index (int): Zero-based target tile in the current page.

        Returns:
            bool: True when the complete anchored range was admitted.
        """
        if self._closed or not 0 <= index < len(self._photos):
            return False
        anchor = self._selection_anchor_index
        if anchor is None or not 0 <= anchor < len(self._photos):
            return self.select_photo(index)
        start, end = sorted((anchor, index))
        if end - start + 1 > NATIVE_MEDIA_DELETE_BATCH_MAX:
            self.status.pack(anchor='w', pady=(4, 8))
            self.status.configure(
                text=self._text('linking_media_selection_limit').format(
                    limit=NATIVE_MEDIA_DELETE_BATCH_MAX,
                ),
            )
            return False
        previous = set(self._selected_photo_ids)
        selected = {photo.image_id for photo in self._photos[start:end + 1]}
        self._selected_photo_ids = selected
        self._focus_tile(index)
        changed_ids = previous | selected
        self._render_photo_selection(tuple(
            position for position, photo in enumerate(self._photos)
            if photo.image_id in changed_ids
        ))
        photo = self._photos[index]
        if self._on_photo_selected is not None:
            self._on_photo_selected(photo)
        return True

    def _focus_tile(self, index: int) -> None:
        """Focus and scroll the target grid tile into the visible viewport.

        Args:
            index (int): Zero-based loaded tile to receive keyboard focus.

        Returns:
            None: The current grid owns both focus and scroll position.
        """
        if not 0 <= index < len(self._tile_buttons):
            return
        tile = self._tile_buttons[index]
        tile.focus_set()
        tile_y = tile.winfo_y()
        canvas_y = self.grid_canvas.canvasy(0)
        viewport = self.grid_canvas.winfo_height()
        if tile_y < canvas_y:
            self.grid_canvas.yview_moveto(
                max(0.0, tile_y / max(1, self.grid_frame.winfo_height())),
            )
        elif tile_y + tile.winfo_height() > canvas_y + viewport:
            bottom = tile_y + tile.winfo_height() - viewport
            self.grid_canvas.yview_moveto(
                max(0.0, bottom / max(1, self.grid_frame.winfo_height())),
            )

    def _tile_key(self, index: int, event: tk.Event) -> str | None:
        """Handle local tile navigation and explicit open/toggle commands.

        Args:
            index (int): Tile index that currently owns keyboard focus.
            event (tk.Event): Key event from the focused tile only.

        Returns:
            str | None: Tk break marker for handled tile navigation keys.
        """
        if not 0 <= index < len(self._photos):
            return None
        key = event.keysym
        if key in ('Return', 'KP_Enter'):
            self._open_grid_photo(index)
            return 'break'
        if key == 'space':
            self.toggle_photo_selection(self._photos[index].image_id)
            return 'break'
        delta = {
            'Left': -1, 'Right': 1,
            'Up': -max(1, self._grid_columns),
            'Down': max(1, self._grid_columns),
        }.get(key)
        if delta is None:
            return None
        target = max(0, min(len(self._photos) - 1, index + delta))
        # Tk ShiftMask extends the anchor; Control (or macOS Command) moves
        # focus without discarding the existing multi-selection.
        command_mask = 0x0008 if sys.platform == 'darwin' else 0x0004
        if event.state & 0x0001:
            self.select_photo_range(target)
        elif event.state & command_mask:
            self._focus_tile(target)
            if self._on_photo_selected is not None:
                self._on_photo_selected(self._photos[target])
        else:
            self.select_photo(target)
        return 'break'

    def _open_grid_photo(self, index: int) -> None:
        """Open a grid photo only after ensuring its selection is current.

        Args:
            index (int): Zero-based tile that the user explicitly opened.

        Returns:
            None: The existing foreground detail path owns full-size bytes.
        """
        if not 0 <= index < len(self._photos):
            return
        if self._photos[index].image_id not in self._selected_photo_ids:
            self.select_photo(index)
        self._select_photo(index)

    def clear_photo_selection(self, image_ids: tuple[int, ...]) -> None:
        """Clear only the IDs consumed by one confirmed deletion operation.

        Args:
            image_ids (tuple[int, ...]): Confirmed or explicitly reconciled IDs.

        Returns:
            None: Unrelated selections remain available for the next action.
        """
        targets = set(image_ids)
        self._selected_photo_ids.difference_update(targets)
        if (self._selection_anchor_index is not None
                and self._selection_anchor_index < len(self._photos)
                and self._photos[self._selection_anchor_index].image_id in targets):
            self._selection_anchor_index = None
        self._render_photo_selection(tuple(
            index for index, photo in enumerate(self._photos)
            if photo.image_id in targets
        ))

    def _render_photo_selection(
        self, indexes: tuple[int, ...] | None = None,
    ) -> None:
        """Apply one rounded primary outline without a separate focus ring.

        Args:
            indexes (tuple[int, ...] | None): Changed tile indexes, or all tiles.

        Returns:
            None: Tile identity and thumbnail bytes remain unchanged.
        """
        current = range(len(self._photos)) if indexes is None else indexes
        for index in current:
            if not 0 <= index < len(self._photos):
                continue
            photo = self._photos[index]
            if index >= len(self._tile_buttons):
                continue
            tile = self._tile_buttons[index]
            selected = photo.image_id in self._selected_photo_ids
            tile.state(['selected'] if selected else ['!selected'])
            tile.configure(style=(
                'LinkedPhotoSelected.TButton' if selected else
                'LinkedPhoto.TButton' if self._photo_images[index] is not None else
                'LinkedPhotoPlaceholder.TButton'
            ))

    def _on_empty_context(self, event: tk.Event) -> str | None:
        """Dispatch an empty-grid context action for child-gallery creation.

        Args:
            event (tk.Event): Mouse context event on unused grid space.
        Returns:
            str | None: Callback result, usually ``break`` after opening a menu.
        """
        if self._context_menu is None:
            return None
        return self._context_menu(None, event)

    def _toggle_music_panel(self) -> None:
        """Show the track selector in a compact panel beside its trigger.

        Returns:
            None: Track choice stays separate from the explicit play action.
        """
        if self._music_panel is not None:
            self._close_music_panel()
            return
        if self._closed:
            return
        padding = 8
        parent_width = self.frame.winfo_width()
        width = max(1, min(MEDIA_MUSIC_PANEL_WIDTH, parent_width))
        panel = ttk.Frame(self.frame, style='Glass.TFrame', padding=padding)
        self._music_panel = panel
        ttk.Label(
            panel,
            text=self._text('linking_media_presentation'),
            style='Muted.TLabel',
        ).pack(anchor='w', pady=(0, 4))
        if not self.session.can_read_presentation():
            feedback = 'linking_media_audio_consent'
        elif self._presentation is None or not self._presentation.music or not self._tracks:
            feedback = 'linking_media_audio_empty'
        elif sys.platform not in ('win32', 'darwin'):
            feedback = 'linking_media_audio_unavailable'
        else:
            feedback = None
        if feedback is not None:
            ttk.Label(
                panel,
                text=self._text(feedback),
                wraplength=max(1, width - 2 * padding),
            ).pack(anchor='w', fill='x')
        else:
            self.track_menu = ttk.Combobox(
                panel,
                values=tuple(track.title for track in self._tracks),
                state='readonly',
                width=32,
            )
            self.track_menu.pack(fill='x')
            self.track_menu.current(
                min(self._selected_track_index, len(self._tracks) - 1),
            )
            self.track_menu.bind('<<ComboboxSelected>>', self._on_track_selected)
            self.panel_track_button = icon_button(
                panel,
                'play',
                self._toggle_track,
                self._panel_track_tooltip,
            )
            self._refresh_audio_button()
            self.panel_track_button.pack(anchor='e', pady=(6, 0))
        panel.update_idletasks()
        x = self.music_button.winfo_rootx() - self.frame.winfo_rootx()
        y = self.music_button.winfo_rooty() - self.frame.winfo_rooty()
        parent_width = self.frame.winfo_width()
        parent_height = self.frame.winfo_height()
        height = min(panel.winfo_reqheight(), parent_height)
        gap = padding // 2
        below = y + self.music_button.winfo_height() + gap
        anchored_y = below if below + height <= parent_height else y - height - gap
        panel.place(
            x=max(0, min(parent_width - width, x)),
            y=max(0, min(parent_height - height, anchored_y)),
            width=width,
            height=height,
        )
        if self.track_menu is not None:
            self.track_menu.focus_set()

    def _close_music_panel(self) -> None:
        """Hide the anchored track chooser and clear its widget reference.

        Returns:
            None: The toolbar retains only its compact presentation trigger.
        """
        panel = self._music_panel
        self._music_panel = None
        self.track_menu = None
        self.panel_track_button = None
        if panel is not None:
            try:
                panel.destroy()
            except tk.TclError:
                pass

    def _close_music_on_escape(self, _event: tk.Event) -> str:
        """Close the music panel when Escape is pressed inside it.

        Args:
            _event (tk.Event): Key event from the contextual track selector.

        Returns:
            str: Tk break marker after closing the panel.
        """
        self._close_music_panel()
        self.music_button.focus_set()
        return 'break'

    def _close_music_outside(self, event: tk.Event) -> None:
        """Dismiss music choices when a different viewer control is clicked.

        Args:
            event (tk.Event): Click event received by the parent window.

        Returns:
            None: The panel closes unless its trigger was clicked again.
        """
        panel = self._music_panel
        if panel is None or event.widget is self.music_button:
            return
        widget = event.widget
        while widget is not None:
            if widget is panel:
                return
            widget = getattr(widget, 'master', None)
        self._close_music_panel()

    def _on_track_selected(self, _event: tk.Event) -> None:
        """Remember a track selected in the anchored music chooser.

        Args:
            _event (tk.Event): Combobox selection event from the track panel.

        Returns:
            None: Playback remains a separate explicit toolbar action.
        """
        if self.track_menu is not None:
            self._selected_track_index = self.track_menu.current()

    def _launch(
        self,
        kind: str,
        operation: Callable[[], _WorkerPayload],
        *,
        error_payload: _WorkerPayload = None,
        operation_cancelled: threading.Event | None = None,
    ) -> bool:
        """Run one operation and return its result through the Tk queue.

        Args:
            kind (str): Event type interpreted by the Tk polling callback.
            operation (Callable[[], _WorkerPayload]): Worker-only media work.
            error_payload (_WorkerPayload): Optional identity attached to failure.
            operation_cancelled (threading.Event | None): Per-operation
                supersession signal; a cancelled operation publishes no error.

        Returns:
            bool: True when a daemon worker was started; otherwise False.
        """
        if (self._closed or self._cancelled.is_set()
                or (operation_cancelled is not None
                    and operation_cancelled.is_set())):
            return False
        if not self.session.can_read_media():
            self.close()
            return False
        if self._worker is not None:
            self.status.configure(text=self._text('linking_media_loading'))
            return False
        self._generation += 1
        generation = self._generation
        def run() -> None:
            """Execute the supplied operation and publish its result.

            Returns:
                None: A result or failure marker enters the owned queue.
            """
            try:
                result = operation()
                if (self._cancelled.is_set()
                        or (operation_cancelled is not None
                            and operation_cancelled.is_set())):
                    self._release_worker_result(result, kind)
                    return
                self._events.put((generation, kind, result))
            except LinkingRevisionUnsupported:
                if (not self._cancelled.is_set()
                        and (operation_cancelled is None
                             or not operation_cancelled.is_set())):
                    self._events.put((generation, kind + '_unsupported', error_payload))
            except LinkingMediaUnavailable as exc:
                if (self._cancelled.is_set()
                        or (operation_cancelled is not None
                            and operation_cancelled.is_set())):
                    return
                _log_safe_media_failure(
                    'media-fetch', kind, exc,
                    diagnostic_callback=self._diagnostic_callback,
                )
                self._events.put((generation, kind + '_unavailable', error_payload))
            except LinkingMediaThrottled:
                if (self._cancelled.is_set()
                        or (operation_cancelled is not None
                            and operation_cancelled.is_set())):
                    return
                self._events.put((generation, kind + '_throttled', error_payload))
            except Exception as exc:
                if (self._cancelled.is_set()
                        or (operation_cancelled is not None
                            and operation_cancelled.is_set())):
                    return
                if (kind == 'presentation'
                        and isinstance(exc, LinkingResponseError)
                        and exc.phase == 'media'
                        and exc.route == 'app_linking_media'
                        and exc.status == 404):
                    # Optional presentation settings may be absent on older
                    # galleries. Their absence does not make the photos fail.
                    self._events.put((
                        generation, 'presentation_unavailable', error_payload,
                    ))
                    return
                phase = (
                    "page-fetch"
                    if kind in ("page", "more", "slide_more")
                    else "media-decode"
                    if isinstance(exc, PhotosDecodeError)
                    else "media-fetch"
                )
                _log_safe_media_failure(
                    phase, kind, exc,
                    diagnostic_callback=self._diagnostic_callback,
                )
                failure_payload = error_payload
                if (kind == 'detail'
                        and isinstance(exc, PhotosDecodeError)
                        and isinstance(error_payload, tuple)
                        and len(error_payload) == 5):
                    decode_reason = (
                        exc.facts.reason
                        if exc.facts.reason in (
                            'decode-failed', 'pixel-limit', 'body-limit',
                        ) else 'decode-failed'
                    )
                    failure_payload = (*error_payload, decode_reason)
                self._events.put((generation, kind + '_error', failure_payload))

        self._worker = threading.Thread(
            target=run,
            name='linked-gallery-' + kind,
            daemon=True,
        )
        self._worker_kind = kind
        self._worker.start()
        self._schedule_queue_poll()
        return True

    def _schedule_revision_check(self) -> None:
        """Schedule one bounded background check for the selected viewer.

        Returns:
            None: Hidden viewers defer requests until a later timer tick.
        """
        if not self._closed and self._revision_timer is None:
            self._revision_timer = self.window.after(
                MEDIA_REVISION_POLL_MS, self._check_revision,
            )

    def _check_revision(self) -> None:
        """Probe the visible Admin album without disturbing active work.

        Returns:
            None: Unsupported servers stop probes for this viewer lifetime.
        """
        self._revision_timer = None
        if self._closed:
            return
        if (self._revision_supported and self.session.can_read_admin_media()
                and self.frame.winfo_viewable()
                and not (self._lightbox is not None
                         and self._lightbox.visible)
                and self._worker is None):
            self._launch(
                'revision', lambda: self.session.media_revision(
                    self.gallery.gallery_id, cancelled=self._cancelled,
                ),
            )
        self._schedule_revision_check()

    def refresh_photos(self) -> None:
        """Refresh loaded photo metadata without replacing the viewer.

        Returns:
            None: A busy viewer queues one refresh after its current work.
        """
        if self._closed:
            return
        self._cancel_thumbnail_gui_slice()
        self._thumbnail_disk_lane.invalidate()
        if self._worker_kind == 'thumbnail_batch':
            self._thumbnail_cancelled.set()
            self._generation += 1
        self._refresh_pending = True
        self._refresh_manual_pending = True
        self._dispatch_pending_work()

    def _start_refresh(self) -> None:
        """Read enough pages to cover the currently loaded album extent.

        Returns:
            None: The serialized worker returns a validated metadata snapshot;
                a new photo selection can cancel this background operation.
        """
        # A complete old snapshot admits one additional native page so an
        # upload after an exactly full final page is actually displayed.
        target_count = len(self._photos) + (
            NATIVE_MEDIA_PAGE_SIZE if self._next_after is None else 0
        )
        operation_cancelled = threading.Event()
        self._active_media_cancelled = operation_cancelled

        def read() -> tuple[LinkedMediaPage, ...]:
            """Fetch successive bounded pages through the loaded extent.

            Returns:
                tuple[LinkedMediaPage, ...]: Validated ordered pages.
            """
            pages: list[LinkedMediaPage] = []
            cursor: int | None = None
            count = 0
            # Valid continuations contain at least one legacy-sized page.
            page_limit = max(1, (
                target_count + NATIVE_MEDIA_LEGACY_PAGE_SIZE - 1
            ) // NATIVE_MEDIA_LEGACY_PAGE_SIZE)
            for _page_number in range(page_limit):
                page = self.session.media_page(
                    self.gallery.gallery_id, after_id=cursor,
                    cancelled=operation_cancelled,
                )
                pages.append(page)
                count += len(page.photos)
                next_cursor = page.next_after
                if next_cursor is None or count >= target_count:
                    return tuple(pages)
                if not page.photos or (cursor is not None and next_cursor <= cursor):
                    raise ValueError('Gallery refresh cursor did not advance.')
                cursor = next_cursor
            raise ValueError('Gallery refresh exceeded its loaded page extent.')
        self._refresh_pending = False
        self._refresh_foreground = self._refresh_manual_pending
        self._refresh_manual_pending = False
        if not self._launch(
            'refresh', read, operation_cancelled=operation_cancelled,
        ) and self._active_media_cancelled is operation_cancelled:
            self._active_media_cancelled = None

    def _accept_refresh(self, pages: tuple[LinkedMediaPage, ...]) -> None:
        """Reconcile a refreshed snapshot while preserving scroll and selection.

        Args:
            pages (tuple[LinkedMediaPage, ...]): Ordered pages through loaded extent.

        Returns:
            None: Equal snapshots leave tiles and decoded thumbnails untouched.
        """
        photos = tuple(photo for page in pages for photo in page.photos)
        self._selected_photo_ids.intersection_update(
            photo.image_id for photo in photos
        )
        comparable = lambda photo: replace(
            photo, thumbnail_verified_at=None, preload_verified_at=None,
        )
        if (tuple(map(comparable, photos)) == tuple(map(comparable, self._photos))
                and pages[-1].next_after == self._next_after):
            # Keep fresh server proofs even when the visual rows are unchanged.
            # Retaining the old descriptor age forced needless binary requests.
            self._photos = photos
            # Manual refresh interrupted warm admission even when metadata
            # stayed equal. Restore its work without rebuilding any tile.
            self._on_grid_viewport(None)
            return
        self._cancel_grid_viewport_callback()
        self._cancel_thumbnail_gui_slice()
        self._thumbnail_disk_lane.invalidate()
        anchor_id = (
            self._photos[self._selection_anchor_index].image_id
            if self._selection_anchor_index is not None
            and 0 <= self._selection_anchor_index < len(self._photos)
            else None
        )
        scroll = self.grid_canvas.yview()[0]
        retained = {
            photo.image_id: (comparable(photo), self._photo_images[index])
            for index, photo in enumerate(self._photos)
            if index < len(self._photo_images) and self._photo_images[index] is not None
        }
        previous_images = tuple(
            image for image in self._photo_images if image is not None
        )
        selected = (self._photos[self._slide_index].image_id
                    if self._lightbox is not None and self._photos
                    and self._slide_index < len(self._photos) else None)
        for tile in self._tile_buttons:
            tile.destroy()
        self._tile_buttons.clear()
        self._photo_images.clear()
        self._tile_tooltips.clear()
        self._tile_titles.clear()
        self._photos = ()
        self._thumbnail_loaded.clear()
        self._thumbnail_pending.clear()
        self._visible_thumbnail_indices.clear()
        self._admitted_thumbnail_indices.clear()
        self._page_request_mode = 'initial'
        self._reconciling = True
        try:
            for page in pages:
                self._accept_page(page, self._presentation)
        finally:
            self._reconciling = False
        self._selection_anchor_index = next((
            index for index, photo in enumerate(self._photos)
            if photo.image_id == anchor_id
        ), None)
        for index, photo in enumerate(self._photos):
            old = retained.get(photo.image_id)
            if old is not None and old[0] == comparable(photo):
                self._photo_images[index] = old[1]
                self._tile_buttons[index].configure(
                    image=old[1], text='', style='LinkedPhoto.TButton',
                )
                self._thumbnail_loaded.add(index)
                if index in self._thumbnail_pending:
                    self._thumbnail_pending.remove(index)
        retained_image_ids = {
            id(image) for image in self._photo_images if image is not None
        }
        self._thumbnail_image_release_count += sum(
            1 for image in previous_images
            if id(image) not in retained_image_ids
        )
        self._render_photo_selection()
        if selected is not None:
            index = next((i for i, photo in enumerate(self._photos)
                          if photo.image_id == selected), None)
            if index is not None:
                self._slide_index = index
            elif self._lightbox is not None:
                self._lightbox.dismiss()
        self.grid_canvas.after_idle(lambda: self.grid_canvas.yview_moveto(scroll))
        self._on_grid_viewport(None)
        if self.on_photos_changed is not None:
            self.on_photos_changed()

    def _schedule_queue_poll(self, delay_ms: int = MEDIA_QUEUE_ACTIVE_POLL_MS) -> None:
        """Schedule delivery while work or an already prepared result exists.

        Args:
            delay_ms (int): Bounded wait before the next queue drain in ms.

        Returns:
            None: One bounded timer checks worker or ready in-memory results.
        """
        if (self._after_id is None and not self._closed
                and (self._worker is not None
                     or not self._events.empty()
                     or self._thumbnail_disk_lane.busy())):
            self._after_id = self.window.after(delay_ms, self._poll_queue)

    def _load_first_page(self) -> None:
        """Show recent session metadata and refresh it through the same owner.

        Returns:
            None: Recent rows can render immediately while a fresh server page
                follows; binary authorization and descriptor ages are unchanged.
                Otherwise the first page precedes optional presentation settings.
        """
        self._page_request_cursor = None
        self._page_request_mode = 'initial'
        recent = getattr(self.session, 'recent_media_page', None)
        page = recent(self.gallery.gallery_id) if callable(recent) else None
        if (isinstance(page, LinkedMediaPage) and not self._closed
                and self._worker is None and self._events.empty()):
            # Deliver after mounting, so implicit-preview callbacks and cache
            # owners are installed before they observe the reused page.
            self._generation += 1
            self._events.put_nowait((
                self._generation, 'page_recent', (page, None),
            ))
            self._schedule_queue_poll(MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS)
            return

        def operation() -> tuple[LinkedMediaPage, LinkedPresentation | None]:
            """Fetch one authorized page for the existing page event.

            Returns:
                tuple[LinkedMediaPage, LinkedPresentation | None]: Page and
                    no presentation until its later owned request.
            """
            page = self.session.media_page(
                self.gallery.gallery_id,
                cancelled=self._cancelled,
            )
            return page, None

        self._launch('page', operation)

    def _load_more(
        self, *, automatic: bool = False, select_first: bool = False,
    ) -> None:
        """Request the next server page using its exclusive continuation.

        Args:
            automatic (bool): Whether the active slideshow advanced past its page.
            select_first (bool): Whether to open the first photo after fetching.

        Returns:
            None: Appends ordered metadata or stops an interrupted slide.
        """
        after_id = self._next_after
        if after_id is None:
            return
        self._prefetch_direction = 1
        self._select_first_after_page = select_first or automatic
        if self._worker is not None:
            self._pending_page_request = (after_id, automatic)
            self._thumbnail_cancelled.set()
            return

        self._start_page_after(after_id, automatic=automatic)

    def _start_page_after(
        self,
        after_id: int | None,
        *,
        automatic: bool = False,
    ) -> None:
        """Start one serialized server-page request after its captured cursor.

        Args:
            after_id (int | None): Start cursor for the requested server page.
            automatic (bool): Whether the slideshow owns this page advance.
        Returns:
            None: The page event updates the visible grid on Tk's thread.
        """

        if self._worker is not None:
            self._pending_page_request = (after_id, automatic)
            return
        self._page_request_cursor = after_id
        self._page_request_mode = 'next'

        def operation() -> LinkedMediaPage:
            """Fetch the page after the current exclusive cursor.

            Returns:
                LinkedMediaPage: Validated page from the linked session.
            """
            return self.session.media_page(
                self.gallery.gallery_id,
                after_id=after_id,
                cancelled=self._cancelled,
            )

        if self._launch('slide_more' if automatic else 'more', operation):
            self._loading_more = True
        elif automatic:
            self._stop_slideshow()

    def _accept_page(
        self,
        page: LinkedMediaPage,
        presentation: LinkedPresentation | None,
        *, continuing_slides: bool = False,
    ) -> None:
        """Validate and append one ordered page to the continuous photo grid.

        Args:
            page (LinkedMediaPage): Validated server page for this gallery.
            presentation (LinkedPresentation | None): Optional scoped settings.
            continuing_slides (bool): Keep active playback when crossing a page.

        Returns:
            None: Metadata appends immediately; visible thumbnail work is queued.
        """
        if (not isinstance(page, LinkedMediaPage)
                or (presentation is not None
                    and not isinstance(presentation, LinkedPresentation))
                or page.gallery_id != self.gallery.gallery_id
                or len(page.photos) > NATIVE_MEDIA_PAGE_SIZE
                or any(type(photo.image_id) is not int or photo.image_id <= 0
                       for photo in page.photos)
                or (page.next_after is not None
                    and (type(page.next_after) is not int
                         or page.next_after <= 0))):
            raise ValueError('Invalid media page')
        if self._page_request_mode == 'initial':
            if not getattr(self, '_reconciling', False):
                self._selected_photo_ids.clear()
                self._selection_anchor_index = None
            self._photos = ()
        self._page_request_mode = 'next'
        old_count = len(self._photos)
        photos = page.photos
        self._photos += photos
        self._next_after = page.next_after
        self._loading_more = False
        tracks = tuple(presentation.tracks) if presentation is not None else ()
        if self._audio_index is not None and (
            presentation is None or not presentation.music or tracks != self._tracks
        ):
            self._stop_audio()
            self._close_music_panel()
        self._presentation = presentation
        self._tracks = tracks
        self._refresh_audio_button()
        self._empty_page = not bool(self._photos)
        if old_count == 0:
            self._tile_buttons.clear()
            self._photo_images.clear()
            self._tile_tooltips.clear()
            self._tile_titles.clear()
        self._photo_images.extend([None] * len(photos))
        for index in range(old_count, len(self._photos)):
            photo = self._photos[index]
            title = ''.join(char if char.isprintable() else ' '
                            for char in photo.title).strip()
            full_label = title[:160] or self._text('linking_media_open')
            tile = ttk.Button(
                self.grid_frame,
                text=MEDIA_THUMBNAIL_PLACEHOLDER,
                image='',
                compound='top',
                style='LinkedPhotoPlaceholder.TButton',
                command=lambda selected=index: self.select_photo(selected),
                takefocus=True,
            )
            row = index // max(1, getattr(self, '_grid_columns', 1))
            self.grid_frame.grid_rowconfigure(
                row, minsize=MEDIA_GRID_ROW_MINIMUM_HEIGHT,
            )
            tile.grid(
                row=row,
                column=index % max(1, getattr(self, '_grid_columns', 1)),
                sticky='nsew',
                padx=MEDIA_GRID_GUTTER // 2,
                pady=MEDIA_GRID_GUTTER // 2,
            )
            self._tile_buttons.append(tile)
            tile.bind('<MouseWheel>', self._on_grid_wheel, add='+')
            self._bind_precise_scroll(tile)
            tile.bind('<Button-4>', self._on_grid_wheel, add='+')
            tile.bind('<Button-5>', self._on_grid_wheel, add='+')
            tile.bind(
                '<Button-1>',
                lambda _event, selected=index: (
                    self.select_photo(selected), 'break',
                )[1],
            )
            tile.bind(
                '<Double-Button-1>',
                lambda _event, selected=index: (
                    self._open_grid_photo(selected), 'break',
                )[1],
            )
            tile.bind(
                '<Shift-Button-1>',
                lambda _event, selected=index: (
                    self.select_photo_range(selected), 'break',
                )[1],
            )
            tile.bind(
                '<Return>',
                lambda _event, selected=index: (
                    self._open_grid_photo(selected), 'break',
                )[1],
            )
            for sequence in (
                '<Left>', '<Right>', '<Up>', '<Down>', '<space>', '<KP_Enter>',
            ):
                tile.bind(
                    sequence,
                    lambda event, selected=index: self._tile_key(
                        selected, event,
                    ),
                )
            context_sequences = ('<Button-3>', '<Button-2>')
            if sys.platform == 'darwin':
                context_sequences += ('<Control-Button-1>',)
            for sequence in context_sequences:
                tile.bind(
                    sequence,
                    lambda event, selected=index: self._on_photo_context(
                        selected, event,
                    ),
                    add='+',
                )
            if sys.platform != 'darwin':
                tile.bind(
                    '<Control-Button-1>',
                    lambda _event, selected=index: (
                        self.toggle_photo_selection(
                            self._photos[selected].image_id,
                        ), 'break',
                    )[1] if selected < len(self._photos) else 'break',
                    add='+',
                )
            elif sys.platform == 'darwin':
                tile.bind(
                    '<Command-Button-1>',
                    lambda _event, selected=index: (
                        self.toggle_photo_selection(
                            self._photos[selected].image_id,
                        ), 'break',
                    )[1] if selected < len(self._photos) else 'break',
                    add='+',
                )
            tile.bind(
                '<Shift-F10>',
                lambda event, selected=index: self._on_photo_context(
                    selected, event,
                ),
                add='+',
            )
            tooltip = tk.StringVar(master=self.frame, value=full_label)
            self._tile_tooltips.append(tooltip)
            self._tile_titles.append(full_label)
            _attach_tooltip(tile, tooltip)
        self._render_photo_selection()
        self.retry_button.configure(state='disabled')
        self.retry_button.pack_forget()
        can_slideshow = bool(self._photos) or self._slideshow_running
        self.slide_button.configure(state='normal' if can_slideshow else 'disabled')
        if not self._photos:
            self._stop_slideshow()
            self._refresh_empty_page_status()
            self._notify_preview_result(False)
            return
        self._empty_page = False
        self.status.pack_forget()
        self.status.configure(text='')
        self._thumbnail_index = 0
        if old_count == 0:
            self._thumbnail_loaded.clear()
            self._thumbnail_pending.clear()
        self._thumbnail_cancelled.clear()
        if self._select_first_after_page:
            self._select_first_after_page = False
            if old_count < len(self._photos):
                self._select_photo(old_count, keep_slideshow=continuing_slides)
            elif self._photos and self._next_after is None:
                self._select_photo(0, keep_slideshow=continuing_slides)
        if not self._reconciling:
            self._on_grid_viewport(None)
        if self._wrap_after_metadata and self._next_after is None:
            self._wrap_after_metadata = 0
            self._select_photo(len(self._photos) - 1)
        elif self._wrap_after_metadata and self._next_after is not None:
            self._load_more()
        if (self._prefetch_edge_direction
                and (self._lightbox is None or not self._lightbox.visible)):
            self._prefetch_edge_direction = 0
        if self._prefetch_edge_direction == 1 and photos:
            first_new = self._photos[old_count]
            if self._cached_detail(first_new.image_id) is None:
                self._pending_prefetch_ids.append(first_new.image_id)
            self._prefetch_edge_direction = 0
        elif self._prefetch_edge_direction == -1:
            if self._next_after is not None:
                self._load_more()
            else:
                last_photo = self._photos[-1]
                if self._cached_detail(last_photo.image_id) is None:
                    self._pending_prefetch_ids.append(last_photo.image_id)
                self._prefetch_edge_direction = 0
        if (self._slideshow_running and not self._slideshow_music_attempted
                and self._audio_index is None
                and self._presentation is not None
                and self._presentation.music and self._tracks):
            self._slideshow_music_attempted = True
            self._toggle_track()
            self._slideshow_started_music = self._audio_index is not None
        self._notify_preview_result(True)

    def _notify_preview_result(self, has_photos: bool | None) -> None:
        """Report the implicit initial candidate once on Tk's thread.

        Args:
            has_photos (bool | None): True for photos, False for empty or
                unavailable, or None for a temporary first-page failure.

        Returns:
            None: Explicit gallery choices have no callback to invoke.
        """
        if not self._preview_result_pending:
            return
        self._preview_result_pending = False
        callback = self._preview_result
        self._preview_result = None
        if callback is not None:
            callback(has_photos)

    def set_folder_shelf_visible(self, visible: bool) -> None:
        """Hide empty-gallery feedback while visible child folders are shown.

        Args:
            visible (bool): Whether the workspace currently shows child cards.

        Returns:
            None: Loading and error feedback remain visible in every state.
        """
        self._folder_shelf_visible = bool(visible)
        self._refresh_empty_page_status()

    def _refresh_empty_page_status(self) -> None:
        """Show empty-gallery feedback only when the folder shelf is hidden.

        Returns:
            None: Status visibility reflects empty media and visible children.
        """
        if not self._empty_page:
            return
        if self._folder_shelf_visible:
            self.status.pack_forget()
            return
        self.status.configure(text=self._text('linking_media_empty'))
        self.status.pack(anchor='w', pady=(4, 8))

    def _prepare_thumbnail(self, body: bytes) -> 'Image.Image':
        """Reduce oversized JPEG variants before preparing the bounded tile.

        Args:
            body (bytes): Complete authorized image response.

        Returns:
            Image.Image: Independent RGB raster at the thumbnail pixel bound.
                Decoder subsampling avoids full camera-raster allocation when
                the server supplies a larger fallback as its thumbnail.

        Raises:
            ValueError: Pillow is unavailable, body is oversized, or source
                dimensions exceed the decoded-image policy.
            OSError: The encoded image cannot be opened or decoded safely.
        """
        if Image is None or len(body) > NATIVE_MEDIA_MAX_BYTES:
            raise ValueError('Image support unavailable or response too large')
        image: 'Image.Image | None' = None
        try:
            with Image.open(io.BytesIO(body)) as source:
                if source.width * source.height > MEDIA_IMAGE_MAX_PIXELS:
                    raise ValueError('Image dimensions exceed policy')
                if (source.format in ('JPEG', 'MPO')
                        and (source.width > MEDIA_THUMBNAIL_DECODE_SIZE[0]
                             or source.height > MEDIA_THUMBNAIL_DECODE_SIZE[1])):
                    source.draft('RGB', MEDIA_THUMBNAIL_DECODE_SIZE)
                image = source.convert('RGB')
            crop = detect_black_matte_crop(image)
            if crop != (0, 0, image.width, image.height):
                cropped = image.crop(crop)
                image.close()
                image = cropped
            image.thumbnail(MEDIA_THUMBNAIL_SIZE, Image.Resampling.LANCZOS)
            result = image
            image = None
            return result
        finally:
            if image is not None:
                image.close()

    def _load_thumbnails(
        self,
        photos: tuple[LinkedPhoto, ...],
        index: int,
        timings: MediaReadTimings | None = None,
        gallery_id: int | None = None,
    ) -> tuple[int, 'Image.Image | None', float]:
        """Fetch and downsize one viewport-admitted album thumbnail.

        Args:
            photos (tuple[LinkedPhoto, ...]): Ordered photos in the album so far.
            index (int): Zero-based photo index requested by the queue.
            timings (MediaReadTimings | None): Optional per-read transport
                timing record, or None for a direct compatibility call.
            gallery_id (int | None): Captured gallery identity, or None to use
                the viewer's gallery for a direct compatibility call.

        Returns:
            tuple[int, Image.Image | None, float]: Photo index, bounded RGB
                image and direct thumbnail decode duration in milliseconds.
        """
        if self._thumbnail_cancelled.is_set() or self._cancelled.is_set():
            return index, None, 0.0
        photo = photos[index]
        try:
            body, _mime = self.session.media_photo(
                gallery_id if gallery_id is not None else self.gallery.gallery_id,
                photo.image_id,
                thumbnail=True,
                cancelled=self._thumbnail_cancelled,
                cache_eligible=getattr(photo, 'cache_eligible', None),
                thumbnail_etag=getattr(photo, 'thumbnail_etag', None),
                thumbnail_verified_at=getattr(
                    photo, 'thumbnail_verified_at', None,
                ),
                preload_eligible=getattr(photo, 'preload_eligible', None),
                preload_thumbnail_etag=getattr(
                    photo, 'preload_thumbnail_etag', None,
                ),
                preload_verified_at=getattr(photo, 'preload_verified_at', None),
                timings=timings,
            )
        except LinkingMediaThrottled:
            raise
        except Exception as exc:
            if not self._thumbnail_cancelled.is_set() and not self._cancelled.is_set():
                _log_safe_media_failure(
                    "media-fetch", "thumbnail", exc,
                    diagnostic_callback=self._diagnostic_callback,
                )
            return index, None, 0.0
        if self._thumbnail_cancelled.is_set() or self._cancelled.is_set():
            return index, None, 0.0
        decode_started = time.perf_counter()
        try:
            image = self._prepare_thumbnail(body)
            return index, image, (time.perf_counter() - decode_started) * 1000.0
        except Exception as exc:
            if not self._thumbnail_cancelled.is_set() and not self._cancelled.is_set():
                _log_safe_media_failure(
                    "media-decode", "thumbnail", exc,
                    diagnostic_callback=self._diagnostic_callback,
                )
            return index, None, (time.perf_counter() - decode_started) * 1000.0

    def _load_next_thumbnail(
        self, limit: int = MEDIA_THUMBNAIL_BATCH_SIZE,
    ) -> None:
        """Fetch a bounded visible-first batch on the serialized worker.

        Args:
            limit (int): Maximum admitted items for this turn, capped by the
                batch policy and any remaining initial revision budget.

        Returns:
            None: One worker serially publishes up to the batch limit.
        """
        if (self._media_throttled
                or not self._thumbnail_pending
                or not self.grid_canvas.winfo_viewable()
                or self._thumbnail_cancelled.is_set() or self._closed
                or self._worker is not None
                or self._pending_detail_index is not None
                or self._pending_page_request is not None):
            return
        if not self.session.can_read_media():
            self.close()
            return
        # Keep the in-flight index queued until it resolves. Configure events
        # can otherwise enqueue the same visible tile again during a download.
        indices = tuple(self._thumbnail_pending[:max(
            0, min(limit, MEDIA_THUMBNAIL_BATCH_SIZE),
        )])
        if not indices:
            return
        self._generation += 1
        generation = self._generation
        photos = self._photos
        gallery_id = self.gallery.gallery_id
        descriptors = tuple(photos[index] for index in indices)
        thumbnail_cancelled = self._thumbnail_cancelled
        session_cancelled = self._cancelled
        result_lock = self._thumbnail_result_lock

        def run_batch() -> None:
            """Read and publish each captured thumbnail in serial order.

            Returns:
                None: Bounded image results enter the queue with captured identity.
            """
            try:
                for index, descriptor in zip(indices, descriptors):
                    if (thumbnail_cancelled.is_set()
                            or session_cancelled.is_set()):
                        break
                    timings = MediaReadTimings()
                    decode_ms = 0.0
                    try:
                        photo_index, image, decode_ms = self._load_thumbnails(
                            photos, index, timings, gallery_id,
                        )
                    finally:
                        self._observe_thumbnail_read(timings, decode_ms)
                    if thumbnail_cancelled.is_set() or session_cancelled.is_set():
                        with self._thumbnail_metrics_lock:
                            self._thumbnail_cancelled_read_count += 1
                        if image is not None:
                            image.close()
                        break
                    result = _ThumbnailResult(
                        gallery_id, photo_index, descriptor, image, timings,
                        decode_ms, time.perf_counter(),
                    )
                    with result_lock:
                        if thumbnail_cancelled.is_set() or session_cancelled.is_set():
                            with self._thumbnail_metrics_lock:
                                self._thumbnail_cancelled_read_count += 1
                            if image is not None:
                                image.close()
                            break
                        try:
                            self._events.put_nowait((
                                generation, 'thumbnail_batch_item', result,
                            ))
                        except queue.Full:
                            if image is not None:
                                image.close()
                            break
                    if thumbnail_cancelled.is_set():
                        break
            except LinkingMediaThrottled:
                throttled_index = index
                with result_lock:
                    if (not session_cancelled.is_set()
                            and not thumbnail_cancelled.is_set()):
                        # Throttling replaces an item in the same batch, so
                        # its control marker fits the existing result bound.
                        self._events.put_nowait((
                            generation, 'thumbnail_batch_throttled',
                            throttled_index,
                        ))

        self._thumbnail_batch_count += 1
        self._thumbnail_batch_indices = indices
        self._worker = threading.Thread(
            target=run_batch,
            name='linked-gallery-thumbnail-batch',
            daemon=True,
        )
        self._worker_kind = 'thumbnail_batch'
        self._worker.start()
        self._schedule_queue_poll()

    def _prepare_image(
        self,
        body: bytes,
        max_size: tuple[int, int] = MEDIA_IMAGE_MAX_SIZE,
    ) -> bytes:
        """Decode, constrain and normalize one image as bounded PNG bytes.

        Args:
            body (bytes): Image response body from the linked session.
            max_size (tuple[int, int]): Maximum normalized image dimensions.

        Returns:
            bytes: Resized PNG bytes suitable for Tk-side PhotoImage creation.

        Raises:
            ValueError: Pillow is unavailable or image data exceeds policy.
        """
        if Image is None or len(body) > NATIVE_MEDIA_MAX_BYTES:
            raise ValueError('Image support unavailable or response too large')
        with Image.open(io.BytesIO(body)) as source:
            if source.width * source.height > MEDIA_IMAGE_MAX_PIXELS:
                raise ValueError('Image dimensions exceed policy')
            image = source.convert('RGB')
            if max_size == MEDIA_THUMBNAIL_SIZE:
                crop = detect_black_matte_crop(image)
                if crop != (0, 0, image.width, image.height):
                    cropped = image.crop(crop)
                    image.close()
                    image = cropped
                image.thumbnail(max_size, Image.Resampling.LANCZOS)
            else:
                image.thumbnail(max_size)
            output = io.BytesIO()
            image.save(output, format='PNG')
            result = output.getvalue()
        if len(result) > NATIVE_MEDIA_MAX_BYTES:
            raise ValueError('Normalized image exceeds policy')
        return result

    def _prepare_detail(self, body: bytes) -> 'Image.Image':
        """Decode and constrain a detail image on the owned worker thread.

        JPEG/MPO decoder subsampling is requested whenever source dimensions
        exceed the final detail bounds, allowing supported scaling to reduce
        RGB allocation before the final resize. The pixel ceiling remains a post-draft
        safety bound; unsupported oversized rasters are rejected before convert.

        Args:
            body (bytes): Bounded raster response returned by the linked session.

        Returns:
            Image.Image: RGB image sized for the native lightbox and zoom.

        Raises:
            PhotosDecodeError: Body or raster cannot be safely reduced within
                the existing decoded-pixel and detail-size limits.
        """
        source_size = (0, 0)
        decoded_size = (0, 0)
        image_format = 'OTHER'
        stage = 'source-open'
        if Image is None or len(body) > NATIVE_MEDIA_MAX_BYTES:
            reason = 'body-limit' if len(body) > NATIVE_MEDIA_MAX_BYTES else 'decode-failed'
            raise PhotosDecodeError(PhotosDecodeFacts(
                stage, reason, image_format, source_size, decoded_size,
                len(body), MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
            ))
        image: 'Image.Image | None' = None
        try:
            with Image.open(io.BytesIO(body)) as source:
                source_size = source.size
                pillow_format = source.format
                image_format = (
                    pillow_format if pillow_format in (
                        'JPEG', 'MPO', 'PNG', 'WEBP', 'GIF', 'TIFF', 'BMP',
                    ) else 'OTHER'
                )
                decoded_size = source.size
                source_pixels = source.width * source.height
                is_jpeg = image_format in ('JPEG', 'MPO')
                if source_pixels > MEDIA_IMAGE_MAX_PIXELS and not is_jpeg:
                    raise PhotosDecodeError(PhotosDecodeFacts(
                        'pixel-check', 'pixel-limit', image_format,
                        source_size, decoded_size, len(body),
                        MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
                    ))
                exceeds_detail_bounds = (
                    source.width > MEDIA_IMAGE_MAX_SIZE[0]
                    or source.height > MEDIA_IMAGE_MAX_SIZE[1]
                )
                if is_jpeg and exceeds_detail_bounds:
                    stage = 'draft'
                    try:
                        source.draft('RGB', MEDIA_IMAGE_MAX_SIZE)
                    except Exception:
                        raise PhotosDecodeError(PhotosDecodeFacts(
                            stage, 'decode-failed', image_format, source_size,
                            decoded_size, len(body), MEDIA_IMAGE_MAX_PIXELS,
                            MEDIA_IMAGE_MAX_SIZE,
                        )) from None
                    decoded_size = source.size
                if source.width * source.height > MEDIA_IMAGE_MAX_PIXELS:
                    raise PhotosDecodeError(PhotosDecodeFacts(
                        'pixel-check', 'pixel-limit', image_format,
                        source_size, decoded_size, len(body),
                        MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
                    ))
                if (decoded_size[0] < source_size[0]
                        or decoded_size[1] < source_size[1]):
                    _log_safe_photos_observation(
                        format_safe_photos_decode_facts(PhotosDecodeFacts(
                            'draft', 'reduced', image_format, source_size,
                            decoded_size, len(body), MEDIA_IMAGE_MAX_PIXELS,
                            MEDIA_IMAGE_MAX_SIZE,
                        )),
                        self._observation_callback,
                    )
                stage = 'source-convert'
                decoded_size = source.size
                try:
                    image = source.convert('RGB')
                except Exception:
                    raise PhotosDecodeError(PhotosDecodeFacts(
                        stage, 'decode-failed', image_format, source_size,
                        decoded_size, len(body), MEDIA_IMAGE_MAX_PIXELS,
                        MEDIA_IMAGE_MAX_SIZE,
                    )) from None
            stage = 'resize'
            image.thumbnail(MEDIA_IMAGE_MAX_SIZE)
            result = image
            image = None
            return result
        except PhotosDecodeError:
            raise
        except Exception:
            raise PhotosDecodeError(PhotosDecodeFacts(
                stage, 'decode-failed', image_format, source_size,
                decoded_size, len(body), MEDIA_IMAGE_MAX_PIXELS,
                MEDIA_IMAGE_MAX_SIZE,
            )) from None
        finally:
            if image is not None:
                image.close()

    def _poll_queue(self) -> None:
        """Apply queued worker results on Tk's owning thread.

        Returns:
            None: Delivers results and reports authorized cache snapshots
                without idle polling or another catalogue request.
        """
        self._after_id = None
        if self._closed:
            return
        if not self.session.can_read_media():
            self.close()
            return
        cache_changed = False
        gui_started = time.perf_counter()
        gui_count = 0
        while True:
            try:
                generation, kind, result = self._events.get_nowait()
            except queue.Empty:
                break
            if generation != self._generation or self._closed:
                self._release_worker_result(result, kind)
                continue
            if (kind in ('detail_error', 'detail_unavailable')
                    and isinstance(result, tuple)
                    and result[0] != self._detail_serial):
                kind = 'detail_stale'
            try:
                if kind == 'revision':
                    if (self.frame.winfo_viewable()
                            and not (self._lightbox is not None
                                     and self._lightbox.visible)):
                        if self._revision is None or self._revision != result:
                            if (self._revision is not None
                                    and self.on_photos_changed is not None):
                                self.on_photos_changed()
                            self._refresh_revision = result
                            self._refresh_pending = True
                elif kind == 'refresh':
                    self._accept_refresh(result)
                    if self._refresh_foreground:
                        if self._photos:
                            self.status.configure(text='')
                            self.status.pack_forget()
                        else:
                            self._refresh_empty_page_status()
                    if self._refresh_revision is not None:
                        self._revision = self._refresh_revision
                        self._refresh_revision = None
                elif kind.startswith('refresh_'):
                    # Keep the prior committed revision so a later probe can
                    # retry an interrupted refresh without a tight retry loop.
                    self._refresh_revision = None
                    self._on_grid_viewport(None)
                    if self._refresh_foreground:
                        self.status.pack(anchor='w', pady=(4, 8))
                        error_key = (
                            'linking_media_throttled' if kind == 'refresh_throttled'
                            else 'linking_media_error'
                        )
                        self.status.configure(text=self._text(error_key))
                elif kind == 'revision_unsupported':
                    self._revision_supported = False
                elif kind in ('revision_error', 'revision_throttled'):
                    pass
                elif kind in ('page', 'page_recent'):
                    self._accept_page(*result)
                    self._presentation_pending = self.session.can_read_presentation()
                    if kind == 'page_recent':
                        self._refresh_pending = True
                elif kind == 'presentation':
                    if isinstance(result, LinkedPresentation):
                        self._presentation = result
                        self._tracks = tuple(result.tracks)
                        self._refresh_audio_button()
                        if (self._slideshow_running
                                and not self._slideshow_music_attempted
                                and self._audio_index is None
                                and result.music and self._tracks):
                            self._slideshow_music_attempted = True
                            self._toggle_track()
                            self._slideshow_started_music = self._audio_index is not None
                elif kind.startswith('presentation_'):
                    pass
                elif kind in ('more', 'slide_more'):
                    self._accept_page(
                        result, self._presentation,
                        continuing_slides=kind == 'slide_more' and self._slideshow_running,
                    )
                elif kind == 'thumbnail':
                    self._show_thumbnail(*result)
                elif kind == 'thumbnail_batch_item':
                    if not isinstance(result, _ThumbnailResult):
                        continue
                    valid_identity = bool(
                        result.gallery_id == self.gallery.gallery_id
                        and 0 <= result.index < len(self._photos)
                        and self._photos[result.index] == result.photo
                    )
                    if not valid_identity:
                        if result.image is not None:
                            result.image.close()
                        continue
                    self._thumbnail_queue_wait_ms += max(
                        0.0,
                        (time.perf_counter() - result.enqueued_at) * 1000.0,
                    )
                    show_started = time.perf_counter()
                    private_descriptor = (
                        result.photo.cache_eligible is not True
                        and (self.session.can_read_admin_media()
                             or result.photo.cache_eligible is False
                             or result.photo.preload_eligible is not None)
                    )
                    verified_private_response = (
                        result.timings.source == 'disk'
                        or result.timings.cache_stored
                    )
                    self._show_thumbnail(
                        result.index, result.image,
                        remember=(not private_descriptor
                                  or verified_private_response),
                    )
                    self._thumbnail_tk_ms += (
                        time.perf_counter() - show_started
                    ) * 1000.0
                    gui_count += 1
                    if (gui_count >= MEDIA_THUMBNAIL_GUI_SLICE_SIZE
                            or time.perf_counter() - gui_started
                            >= MEDIA_THUMBNAIL_GUI_SLICE_SECONDS):
                        break
                elif kind == 'detail':
                    self._show_detail(*result)
                elif kind == 'prefetch':
                    image_id, data, encoded = result
                    pending_index = self._pending_detail_index
                    active_cancelled = self._active_media_cancelled
                    adopt_prefetch = bool(
                        data is not None
                        and pending_index is not None
                        and 0 <= pending_index < len(self._photos)
                        and self._photos[pending_index].image_id == image_id
                        and self._active_media_photo_id == image_id
                        and active_cancelled is not None
                        and not active_cancelled.is_set()
                    )
                    if adopt_prefetch:
                        with self._thumbnail_metrics_lock:
                            self._detail_media_metrics[
                                'prefetch_adoptions'
                            ] += 1
                        self._pending_detail_index = None
                        cached_image = data.copy() if encoded is not None else None
                        self._show_detail(
                            self._detail_serial, image_id, data,
                            cached_image, encoded,
                        )
                    elif data is not None and encoded is not None:
                        self._remember_detail(image_id, data, encoded)
                    elif data is not None:
                        data.close()
                elif kind.startswith('audio_track'):
                    self._accept_audio_track(*result)
                elif kind == 'prefetch_error':
                    pass
                elif kind.endswith('_throttled'):
                    self._media_throttled = True
                    self._pending_prefetch_ids.clear()
                    self._pending_detail_index = None
                    self._thumbnail_cancelled.set()
                    if (kind.startswith('thumbnail') and type(result) is int
                            and result in self._visible_thumbnail_indices
                            and result not in self._thumbnail_pending):
                        self._thumbnail_pending.insert(0, result)
                    self._stop_slideshow()
                    self.status.pack(anchor='w', pady=(4, 8))
                    self.status.configure(
                        text=self._text('linking_media_throttled'),
                    )
                    self.retry_button.configure(state='normal')
                    self.retry_button.pack(side='left', padx=(8, 0))
                    self._retry_kind = (
                        'detail' if kind.startswith('detail') else
                        'thumbnail' if kind.startswith('thumbnail') else
                        'page' if kind.startswith(('page', 'more', 'slide_more'))
                        else 'thumbnail'
                    )
                    if kind.startswith('detail') and self._lightbox is not None:
                        self._lightbox.show_error(
                            self._text('linking_media_throttled'),
                        )
                elif kind == 'page_unavailable':
                    self.status.pack(anchor='w', pady=(4, 8))
                    self.status.configure(
                        text=self._text('linking_media_gallery_unavailable'),
                    )
                    self.retry_button.configure(state='normal')
                    self.retry_button.pack(side='left', padx=(8, 0))
                    self._retry_kind = 'page'
                    if self._page_request_mode == 'initial':
                        self._notify_preview_result(False)
                elif kind == 'detail_unavailable':
                    self.status.pack(anchor='w', pady=(4, 8))
                    self.status.configure(
                        text=self._text('linking_media_gallery_unavailable'),
                    )
                    self._show_detail(*result)
                    self.retry_button.configure(state='normal')
                    self.retry_button.pack(side='left', padx=(8, 0))
                    self._retry_kind = 'detail'
                elif kind.endswith('_error'):
                    self.status.pack(anchor='w', pady=(4, 8))
                    self.status.configure(text=self._text('linking_media_error'))
                    if kind.startswith('detail'):
                        self._show_detail(*result)
                        self.retry_button.configure(state='normal')
                        self.retry_button.pack(side='left', padx=(8, 0))
                        self._retry_kind = 'detail'
                    elif kind == 'page_error':
                        self.retry_button.configure(state='normal')
                        self.retry_button.pack(side='left', padx=(8, 0))
                        self._retry_kind = 'page'
                        if self._page_request_mode == 'initial':
                            self._notify_preview_result(None)
                    elif kind in ('more_error', 'slide_more_error'):
                        self._stop_slideshow()
                        self.retry_button.configure(state='normal')
                        self.retry_button.pack(side='left', padx=(8, 0))
                        self._retry_kind = 'page'
            except Exception as exc:
                phase = (
                    "page-apply"
                    if kind in ("page", "page_recent", "more", "slide_more")
                    else "media-apply"
                )
                _log_safe_media_failure(phase, kind, exc)
                if kind == 'refresh':
                    self._refresh_revision = None
                    self._on_grid_viewport(None)
                if kind != 'refresh' or self._refresh_foreground:
                    self.status.configure(text=self._text('linking_media_error'))
            if kind in (
                'page', 'page_recent', 'more', 'slide_more', 'refresh',
                'thumbnail', 'thumbnail_batch_item', 'detail', 'prefetch',
            ):
                cache_changed = True
        while (gui_count < MEDIA_THUMBNAIL_GUI_SLICE_SIZE
               and (gui_count == 0 or time.perf_counter() - gui_started
                    < MEDIA_THUMBNAIL_GUI_SLICE_SECONDS)):
            local = self._thumbnail_disk_lane.pop_ready()
            if local is None:
                break
            valid = (
                local.gallery_id == self.gallery.gallery_id
                and local.index in self._admitted_thumbnail_indices
                and local.index < len(self._photos)
                and self._photos[local.index] == local.photo
                and not self._refresh_pending
            )
            if not valid:
                if local.image is not None:
                    local.image.close()
                self._thumbnail_discarded_count += 1
            elif local.image is not None:
                self._thumbnail_queue_wait_ms += max(
                    0.0, (time.perf_counter() - local.enqueued_at) * 1000.0,
                )
                show_started = time.perf_counter()
                try:
                    self._show_thumbnail(local.index, local.image)
                    cache_changed = True
                except Exception as exc:
                    local.image.close()
                    _log_safe_media_failure('media-apply', 'thumbnail', exc)
                finally:
                    self._thumbnail_tk_ms += (
                        time.perf_counter() - show_started
                    ) * 1000.0
            elif (local.index not in self._thumbnail_pending
                  and local.index not in self._thumbnail_loaded):
                self._thumbnail_local_miss_count += 1
                self._thumbnail_pending.append(local.index)
            gui_count += 1
        self._thumbnail_pending.sort(
            key=lambda index: (index not in self._visible_thumbnail_indices, index),
        )
        cache_callback = getattr(self, 'on_cache_changed', None)
        if cache_changed and cache_callback is not None and not self._closed:
            cache_callback(self._photos, self._next_after is None)
        if not self._events.empty():
            self._schedule_queue_poll(MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS)
            return
        if self._worker is not None and not self._worker.is_alive():
            # The producer can publish between the first empty check and its
            # exit. Once it is dead, this final check is stable: never advance
            # the generation while its last raster is still undelivered.
            if not self._events.empty():
                self._schedule_queue_poll(
                    MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
                )
                return
            self._worker = None
            self._worker_kind = None
            self._thumbnail_batch_indices = ()
            self._active_media_cancelled = None
            self._active_media_photo_id = None
        self._dispatch_pending_work()
        self._schedule_queue_poll()

    def _release_worker_result(
        self, result: _WorkerPayload, kind: str,
    ) -> None:
        """Release decoded rasters that cannot reach the active viewer.

        Args:
            result (_WorkerPayload): Result from a superseded worker operation.
            kind (str): Fixed event kind identifying its payload shape.

        Returns:
            None: Pillow resources are released without Tk access.
        """
        if kind == 'thumbnail_batch_item' and isinstance(result, _ThumbnailResult):
            self._thumbnail_discarded_count += 1
            if result.image is not None:
                result.image.close()
            return
        if Image is None or not isinstance(result, tuple):
            return
        if kind == 'thumbnail' and len(result) >= 2:
            rasters = (result[1],)
        elif kind == 'detail' and len(result) >= 4:
            rasters = (result[2], result[3])
        elif kind == 'prefetch' and len(result) >= 2:
            rasters = (result[1],)
        else:
            return
        released: set[int] = set()
        for raster in rasters:
            if (isinstance(raster, Image.Image) and id(raster) not in released):
                raster.close()
                released.add(id(raster))

    def _dispatch_pending_work(self) -> None:
        """Dispatch foreground work, bounded tiles and visible speculation.

        Returns:
            None: Detail and page requests lead; audio stays ahead of tiles,
                then adjacent lightbox details precede grid thumbnails. Tiles
                still get a bounded initial turn before presentation/revision.
        """
        if self._closed or self._worker is not None:
            return
        if self._pending_detail_index is not None:
            pending = self._pending_detail_index
            self._pending_detail_index = None
            self._load_detail(pending)
            return
        if self._pending_page_request is not None:
            if (self._prefetch_edge_direction
                    and (self._lightbox is None
                         or not self._lightbox.visible)):
                self._pending_page_request = None
                self._prefetch_edge_direction = 0
            else:
                after_id, automatic = self._pending_page_request
                self._pending_page_request = None
                self._start_page_after(
                    after_id,
                    automatic=automatic,
                )
                return
        if self._refresh_pending and self._refresh_manual_pending:
            self._start_refresh()
            return
        if self._dispatch_audio_prefetch():
            return
        if self._pending_prefetch_ids and (
                self._lightbox is not None and self._lightbox.visible):
            if self._load_next_prefetch():
                return
        if self._refresh_pending:
            self._start_refresh()
            return
        revision_due = (
            not self._revision_initial_attempted
            and self._revision_supported
            and self.session.can_read_admin_media()
            and self.frame.winfo_viewable()
            and not (self._lightbox is not None and self._lightbox.visible)
            and bool(self._photos)
        )
        priority_due = revision_due or self._presentation_pending
        if (self._thumbnail_pending and not self._media_throttled
                and (not priority_due
                     or self._initial_thumbnail_priority_count
                     < MEDIA_INITIAL_THUMBNAIL_PRIORITY_LIMIT)):
            previous_worker = self._worker
            self._thumbnail_cancelled.clear()
            remaining = (
                MEDIA_INITIAL_THUMBNAIL_PRIORITY_LIMIT
                - self._initial_thumbnail_priority_count
                if priority_due else MEDIA_THUMBNAIL_BATCH_SIZE
            )
            self._load_next_thumbnail(remaining)
            if self._worker is not None and self._worker is not previous_worker:
                if priority_due:
                    self._initial_thumbnail_priority_count += len(
                        self._thumbnail_batch_indices,
                    )
                return
        if self._presentation_pending:
            if (self._photos and not self._thumbnail_loaded
                    and (self._viewport_after_id is not None
                         or self._warm_thumbnail_pending
                         or self._thumbnail_disk_lane.busy())):
                return
            self._presentation_pending = False
            if self.session.can_read_presentation():
                self._launch(
                    'presentation', lambda: self.session.media_presentation(
                        self.gallery.gallery_id, cancelled=self._cancelled,
                    ),
                )
                return
        if revision_due:
            self._revision_initial_attempted = True
            self._launch(
                'revision', lambda: self.session.media_revision(
                    self.gallery.gallery_id, cancelled=self._cancelled,
                ),
            )
            return
        if self._media_throttled:
            return
        if self._thumbnail_pending:
            self._thumbnail_cancelled.clear()
            self._load_next_thumbnail()
            if self._worker is not None:
                return

    def _image_memory_size(self, image: 'Image.Image') -> int:
        """Estimate the RGB storage retained by one prepared detail image.

        Args:
            image (Image.Image): Worker-prepared RGB detail image.

        Returns:
            int: Conservative storage estimate using four bytes per RGB pixel.
        """
        return image.width * image.height * 4

    def _remember_detail(
        self, image_id: int, image: 'Image.Image', encoded: bytes,
    ) -> None:
        """Retain prepared and encoded detail data within entry and byte budgets.

        Args:
            image_id (int): Gallery photo ID represented by the image.
            image (Image.Image): Independent worker-prepared RGB image.
            encoded (bytes): Bounded original body used for HDR rendering.

        Returns:
            None: Oldest images are released when either cache limit is reached.
        """
        if (type(image_id) is not int or image_id <= 0
                or Image is None or not isinstance(image, Image.Image)):
            if Image is not None and isinstance(image, Image.Image):
                image.close()
            return
        if not isinstance(encoded, bytes) or len(encoded) > NATIVE_MEDIA_MAX_BYTES:
            image.close()
            return
        photo = next(
            (item for item in self._photos if item.image_id == image_id), None,
        )
        private_candidate = bool(
            photo is not None and photo.cache_eligible is not True
            and (self.session.can_read_admin_media()
                 or photo.cache_eligible is False
                 or photo.preload_eligible is not None)
        )
        private_identity = (
            self.session.private_preload_cache_identity(
                self.gallery.gallery_id, photo,
            ) if (self.session.can_read_admin_media() and photo is not None
                  and private_candidate) else None
        )
        if (private_candidate and photo is not None
                and private_identity is None):
            previous = self._detail_cache.pop(image_id, None)
            if previous is not None:
                self._detail_cache_bytes -= previous[3]
                previous[1].close()
            image.close()
            return
        memory_size = self._image_memory_size(image) + len(encoded)
        if memory_size > MEDIA_DETAIL_CACHE_MAX_BYTES:
            image.close()
            return
        previous = self._detail_cache.pop(image_id, None)
        if previous is not None:
            self._detail_cache_bytes -= previous[3]
            previous[1].close()
        self._detail_cache[image_id] = (
            time.monotonic(), image, encoded, memory_size,
            ((private_identity[0], private_identity[1],
              private_identity[2], private_identity[3])
             if private_identity is not None else (None, None, None, None)),
        )
        self._detail_cache_bytes += memory_size
        while (len(self._detail_cache) > MEDIA_DETAIL_CACHE_ENTRY_LIMIT
               or self._detail_cache_bytes > MEDIA_DETAIL_CACHE_MAX_BYTES):
            _old_id, (_fetched_at, old_image, _old_encoded, old_size,
                      _old_proof) = (
                self._detail_cache.popitem(last=False)
            )
            self._detail_cache_bytes -= old_size
            old_image.close()

    def _cached_detail(self, image_id: int) -> 'Image.Image | None':
        """Return a fresh prepared detail and promote its LRU position.

        Args:
            image_id (int): Gallery photo ID requested by the current selection.

        Returns:
            Image.Image | None: Fresh RGB image, or None after absence/expiry.
        """
        entry = self._detail_cache.get(image_id)
        if entry is None:
            return None
        photo = next(
            (item for item in self._photos if item.image_id == image_id), None,
        )
        private_candidate = (
            photo.cache_eligible is not True
            and (self.session.can_read_admin_media()
                 or photo.cache_eligible is False
                 or photo.preload_eligible is not None)
        ) if photo is not None else False
        private_identity = (
            self.session.private_preload_cache_identity(
                self.gallery.gallery_id, photo,
            ) if (self.session.can_read_admin_media() and photo is not None
                  and private_candidate) else None
        )
        current_proof = (private_identity if private_identity is not None
                         else (None, None, None, None))
        if (private_candidate and photo is not None
                and (private_identity is None or entry[4] != current_proof)):
            del self._detail_cache[image_id]
            self._detail_cache_bytes -= entry[3]
            entry[1].close()
            return None
        fetched_at, image, _encoded, memory_size = entry[:4]
        if time.monotonic() - fetched_at > MEDIA_DETAIL_CACHE_FRESH_SECONDS:
            del self._detail_cache[image_id]
            self._detail_cache_bytes -= memory_size
            image.close()
            return None
        self._detail_cache.move_to_end(image_id)
        return image

    def _cached_detail_encoded(self, image_id: int) -> bytes | None:
        """Return the bounded source body paired with a fresh prepared detail.

        Args:
            image_id (int): Gallery photo ID requested by the current selection.
        Returns:
            bytes | None: Encoded response body, or None after absence/expiry.
        """
        if self._cached_detail(image_id) is None:
            return None
        entry = self._detail_cache.get(image_id)
        return entry[2] if entry is not None else None

    def _queue_adjacent_prefetch(self, index: int) -> None:
        """Queue adjacent album details with the current direction first.

        Args:
            index (int): Visible photo whose detail was just shown.

        Returns:
            None: At most two adjacent details, including page edges, are queued.
        """
        self._pending_prefetch_ids.clear()
        if (self._closed or self._lightbox is None
                or not self._lightbox.visible
                or self._pending_detail_index is not None
                or self._pending_page_request is not None):
            self._prefetch_edge_direction = 0
            return
        directions = (self._prefetch_direction,)
        if not self._slideshow_running:
            directions += (-self._prefetch_direction,)
        current_id = self._photos[index].image_id
        self._cached_detail(current_id)
        for direction in directions:
            target = index + direction
            if target < 0 or target >= len(self._photos):
                if self._next_after is None and len(self._photos) > 1:
                    target %= len(self._photos)
                elif target >= len(self._photos) and self._next_after is not None:
                    self._prefetch_edge_direction = 1
                    self._load_more()
                    continue
                elif target < 0 and self._next_after is not None:
                    self._prefetch_edge_direction = -1
                    self._load_more()
                    continue
                else:
                    continue
            if target == index:
                continue
            image_id = self._photos[target].image_id
            if self._cached_detail(image_id) is None:
                self._pending_prefetch_ids.append(image_id)
            if len(self._pending_prefetch_ids) >= MEDIA_DETAIL_PREFETCH_LIMIT:
                break
    def _load_next_prefetch(self) -> bool:
        """Fetch one still-needed adjacent image through the shared worker.

        Returns:
            bool: True when one prefetch worker started; false when none remain.
        """
        if (self._closed or self._lightbox is None
                or not self._lightbox.visible):
            self._pending_prefetch_ids.clear()
            self._prefetch_edge_direction = 0
            return False
        while self._pending_prefetch_ids:
            image_id = self._pending_prefetch_ids.pop(0)
            if self._cached_detail(image_id) is not None:
                continue
            photo = next(
                (item for item in self._photos if item.image_id == image_id),
                None,
            )
            if photo is None:
                continue

            operation_cancelled = threading.Event()
            self._active_media_cancelled = operation_cancelled
            self._active_media_photo_id = photo.image_id

            def operation() -> tuple[
                int, 'Image.Image', bytes | None,
            ]:
                """Read the adjacent image under its operation cancellation.

                Returns:
                    tuple[int, Image.Image, bytes | None]: Photo ID, prepared
                        raster and retained source, or None when only a safe
                        preview exists.
                """
                timings = MediaReadTimings()
                try:
                    image, body = self._read_prepared_detail(
                        photo, timings, cancelled=operation_cancelled,
                    )
                finally:
                    self._observe_detail_read('prefetch', timings)
                if operation_cancelled.is_set():
                    image.close()
                    raise LinkingError('Adjacent photo request was superseded.')
                private_descriptor = (
                    photo.cache_eligible is not True
                    and (self.session.can_read_admin_media()
                         or photo.cache_eligible is False
                         or photo.preload_eligible is not None)
                )
                if (private_descriptor and timings.source != 'disk'
                        and not timings.cache_stored):
                    body = None
                return photo.image_id, image, body

            started = self._launch(
                'prefetch', operation,
                error_payload=(image_id, None),
                operation_cancelled=operation_cancelled,
            )
            if (not started
                    and self._active_media_cancelled is operation_cancelled):
                self._active_media_cancelled = None
                self._active_media_photo_id = None
            return started
        return False

    def _show_thumbnail(
        self, index: int, data: 'Image.Image | None', *, remember: bool = True,
    ) -> None:
        """Attach one completed thumbnail on Tk's owning thread.

        Args:
            index (int): Zero-based photo index represented by the result.
            data (Image.Image | None): Prepared thumbnail, or None on read failure.
            remember (bool): Store freshly decoded bytes, or reuse the existing
                entry without extending its validation time.

        Returns:
            None: The admitted row band retains a bounded Tk image or placeholder.
        """
        if index in self._thumbnail_pending:
            self._thumbnail_pending.remove(index)
        if index >= len(self._tile_buttons):
            if data is not None:
                data.close()
            return
        if index not in self._admitted_thumbnail_indices:
            if data is not None:
                data.close()
            return
        if data is not None and ImageTk is not None:
            image: object | None = None
            try:
                image = ImageTk.PhotoImage(data, master=self.frame)
                self._thumbnail_image_create_count += 1
                if remember:
                    self._remember_thumbnail(self._photos[index], data.copy())
                data.close()
                if self._photo_images[index] is not None:
                    self._thumbnail_image_release_count += 1
                self._photo_images[index] = image
                self._tile_buttons[index].configure(
                    image=image, text='', style='LinkedPhoto.TButton',
                )
            except Exception:
                if image is not None:
                    self._thumbnail_image_release_count += 1
                    if self._photo_images[index] is image:
                        self._photo_images[index] = None
                data.close()
                data = None
        if data is None or ImageTk is None:
            if data is not None:
                data.close()
            self._tile_buttons[index].configure(
                image='', text=MEDIA_THUMBNAIL_PLACEHOLDER,
                style='LinkedPhotoPlaceholder.TButton',
            )
            if index < len(self._tile_tooltips):
                self._tile_tooltips[index].set(
                    f"{self._text('linking_media_thumbnail_unavailable')}\n"
                    f"{self._tile_titles[index]}"
                )
        self._thumbnail_loaded.add(index)
        self._render_photo_selection((index,))

    def _cached_thumbnail(self, photo: LinkedPhoto) -> 'Image.Image | None':
        """Return a retained raster owned by this session's thumbnail LRU.

        Args:
            photo (LinkedPhoto): Current server-authorized photo descriptor.
        Returns:
            Image.Image | None: Independent source raster, or None on a miss.
        """
        if not self.session.can_reuse_cached_media():
            return None
        private_candidate = (
            photo.cache_eligible is not True
            and (self.session.can_read_admin_media()
                 or photo.cache_eligible is False
                 or photo.preload_eligible is not None)
        )
        private_identity = (
            self.session.private_preload_cache_identity(
                self.gallery.gallery_id, photo, thumbnail=True,
            ) if self.session.can_read_admin_media()
            and private_candidate else None
        )
        if private_candidate and private_identity is None:
            return None
        validator = (
            private_identity[1] if private_identity is not None
            else getattr(photo, 'thumbnail_etag', None)
        )
        key = (
            self.gallery.gallery_id, photo.image_id,
            getattr(photo, 'cache_eligible', None),
            validator,
            private_identity[0] if private_identity is not None else None,
            private_identity[2] if private_identity is not None else None,
            private_identity[3] if private_identity is not None else None,
        )
        with self._thumbnail_memory.lock:
            cached = self._thumbnail_memory.entries.get(key)
            if cached is None:
                return None
            image, _size, stored_at = cached
            verified_at = (
                private_identity[2] if private_identity is not None
                else photo.thumbnail_verified_at
            )
            descriptor_current = (
                key[3] is not None and verified_at is not None
                and 0 <= time.monotonic() - verified_at
                < MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS
            )
            if (private_identity is not None and not descriptor_current):
                self._thumbnail_memory.entries.pop(key, None)
                self._thumbnail_memory.size_bytes -= _size
                self._thumbnail_memory.expirations += 1
                image.close()
                return None
            if (not descriptor_current and time.monotonic() - stored_at
                    > MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS):
                self._thumbnail_memory.entries.pop(key, None)
                self._thumbnail_memory.size_bytes -= _size
                self._thumbnail_memory.expirations += 1
                image.close()
                return None
            self._thumbnail_memory.entries.move_to_end(key)
            return image.copy()

    def _remember_thumbnail(self, photo: LinkedPhoto, image: 'Image.Image') -> None:
        """Retain a bounded decoded thumbnail across same-session viewer mounts.

        Args:
            photo (LinkedPhoto): Current server-authorized photo descriptor.
            image (Image.Image): Independent RGB thumbnail copy to retain.
        Returns:
            None: Visible rasters lead eviction priority. Speculative overscan
                cannot evict history; all retained pixels obey the byte bound.
        """
        private_candidate = (
            photo.cache_eligible is not True
            and (self.session.can_read_admin_media()
                 or photo.cache_eligible is False
                 or photo.preload_eligible is not None)
        )
        private_identity = (
            self.session.private_preload_cache_identity(
                self.gallery.gallery_id, photo, thumbnail=True,
            ) if self.session.can_read_admin_media()
            and private_candidate else None
        )
        if private_candidate and private_identity is None:
            image.close()
            with self._thumbnail_memory.lock:
                self._thumbnail_memory.admission_refusals += 1
            return
        validator = (
            private_identity[1] if private_identity is not None
            else getattr(photo, 'thumbnail_etag', None)
        )
        key = (
            self.gallery.gallery_id, photo.image_id,
            getattr(photo, 'cache_eligible', None),
            validator,
            private_identity[0] if private_identity is not None else None,
            private_identity[2] if private_identity is not None else None,
            private_identity[3] if private_identity is not None else None,
        )
        # Pillow RGB is accounted at four bytes per pixel, conservatively
        # including alignment rather than relying on compressed source bytes.
        size = self._image_memory_size(image)
        if size > MEDIA_THUMBNAIL_CACHE_MAX_BYTES:
            image.close()
            with self._thumbnail_memory.lock:
                self._thumbnail_memory.admission_refusals += 1
            return
        memory = self._thumbnail_memory
        with memory.lock:
            for old_key in tuple(memory.entries):
                if old_key[:2] == key[:2] and old_key != key:
                    old_image, old_size, _stored_at = memory.entries.pop(old_key)
                    memory.size_bytes -= old_size
                    memory.validator_changes += 1
                    old_image.close()
            visible_keys: set[
                tuple[
                    int, int, bool | None, str | None, int | None,
                    float | None, int | None,
                ]
            ] = set()
            for index in self._visible_thumbnail_indices:
                if index >= len(self._photos):
                    continue
                visible_photo = self._photos[index]
                visible_identity = (
                    self.session.private_preload_cache_identity(
                        self.gallery.gallery_id, visible_photo,
                        thumbnail=True,
                    ) if visible_photo.cache_eligible is not True
                    and self.session.can_read_admin_media()
                    else None
                )
                visible_validator = (
                    visible_identity[1]
                    if visible_identity is not None
                    else visible_photo.thumbnail_etag
                )
                visible_keys.add((
                    self.gallery.gallery_id, visible_photo.image_id,
                    visible_photo.cache_eligible, visible_validator,
                    visible_identity[0] if visible_identity is not None else None,
                    visible_identity[2] if visible_identity is not None else None,
                    visible_identity[3] if visible_identity is not None else None,
                ))
            speculative = key not in visible_keys and bool(visible_keys)
            previous_size = memory.entries[key][1] if key in memory.entries else 0
            if speculative and (
                (key not in memory.entries
                 and len(memory.entries) >= MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT)
                or memory.size_bytes - previous_size + size
                > MEDIA_THUMBNAIL_CACHE_MAX_BYTES
            ):
                memory.admission_refusals += 1
                image.close()
                return
            previous = memory.entries.pop(key, None)
            if previous is not None:
                memory.size_bytes -= previous[1]
                previous[0].close()
            memory.entries[key] = (image, size, time.monotonic())
            memory.size_bytes += size
            while (len(memory.entries) > MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT
                   or memory.size_bytes > MEDIA_THUMBNAIL_CACHE_MAX_BYTES):
                victim = next(
                    (candidate for candidate in memory.entries
                     if candidate not in visible_keys),
                    next(iter(memory.entries)),
                )
                evicted, evicted_size, _stored_at = memory.entries.pop(victim)
                memory.size_bytes -= evicted_size
                memory.evictions += 1
                evicted.close()

    def _select_photo(self, index: int, *, keep_slideshow: bool = False) -> None:
        """Open one selected tile as the current full-size detail.

        Args:
            index (int): Zero-based photo index in the current server page.
            keep_slideshow (bool): Whether an automatic page crossing owns it.

        Returns:
            None: The selected image becomes the foreground detail request.
        """
        if not keep_slideshow:
            self._stop_slideshow()
        self._wrap_after_metadata = 0
        self._select_first_after_page = False
        self._load_detail(index)

    def _read_photo_body(
        self, photo: LinkedPhoto, *, thumbnail: bool,
        timings: MediaReadTimings | None = None,
        cancelled: threading.Event | None = None,
    ) -> tuple[bytes, str]:
        """Read a thumbnail or saved full version through the session gates.

        Args:
            photo (LinkedPhoto): Current gallery descriptor and cache metadata.
            thumbnail (bool): Whether to request the server's bounded preview.
            timings (MediaReadTimings | None): Optional cache-admission witness.
            cancelled (threading.Event | None): Current detail operation owner.

        Returns:
            tuple[bytes, str]: Authorized body and validated media type.
        """
        return self.session.media_photo(
            self.gallery.gallery_id,
            photo.image_id,
            thumbnail=thumbnail,
            cancelled=(
                cancelled if cancelled is not None else self._cancelled
            ),
            cache_eligible=getattr(photo, 'cache_eligible', None),
            thumbnail_etag=getattr(photo, 'thumbnail_etag', None),
            thumbnail_verified_at=getattr(
                photo, 'thumbnail_verified_at', None,
            ),
            preload_eligible=getattr(photo, 'preload_eligible', None),
            preload_thumbnail_etag=getattr(
                photo, 'preload_thumbnail_etag', None,
            ),
            preload_verified_at=getattr(
                photo, 'preload_verified_at', None,
            ),
            timings=timings,
            use_preloaded_snapshot=not thumbnail,
        )

    def _read_prepared_detail(
        self, photo: LinkedPhoto,
        timings: MediaReadTimings | None = None,
        cancelled: threading.Event | None = None,
    ) -> tuple['Image.Image', bytes | None]:
        """Read and decode a selected detail, using one safe preview fallback.

        Args:
            photo (LinkedPhoto): Exact photo selected or prefetched in this view.
            timings (MediaReadTimings | None): Optional cache-admission witness.
            cancelled (threading.Event | None): Current detail operation owner.

        Returns:
            tuple[Image.Image, bytes | None]: Bounded image plus original bytes;
                preview fallback returns None so it is never cached as a detail.

        Raises:
            PhotosDecodeError: The original and any permitted preview cannot be
                reduced inside the existing decoder limits.
            LinkingError: Existing authorization, transport or cancellation gates fail.
        """
        operation_cancelled = (
            cancelled if cancelled is not None else self._cancelled
        )
        body, _mime = self._read_photo_body(
            photo, thumbnail=False, timings=timings,
            cancelled=operation_cancelled,
        )
        if operation_cancelled.is_set():
            raise LinkingError('Detail request was cancelled.')
        try:
            image = self._prepare_detail(body)
            if operation_cancelled.is_set():
                image.close()
                raise LinkingError('Detail request was cancelled.')
            return image, body
        except PhotosDecodeError as error:
            facts = error.facts
            if (facts.reason != 'pixel-limit'
                    or operation_cancelled.is_set()):
                raise
        if operation_cancelled.is_set():
            raise LinkingError('Detail request was cancelled.')
        _log_safe_photos_observation(
            format_safe_photos_decode_facts(facts),
            self._observation_callback,
        )
        try:
            preview_body, _preview_mime = self._read_photo_body(
                photo, thumbnail=True, timings=timings,
                cancelled=operation_cancelled,
            )
            preview = self._prepare_detail(preview_body)
            if operation_cancelled.is_set():
                preview.close()
                raise LinkingError('Detail request was cancelled.')
        except Exception:
            if operation_cancelled.is_set():
                raise LinkingError('Detail request was cancelled.') from None
            _log_safe_photos_observation(
                format_safe_photos_decode_facts(PhotosDecodeFacts(
                    'thumbnail-fallback', 'preview-fallback',
                    facts.image_format, facts.source_size, (0, 0), len(body),
                    MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
                )),
                self._observation_callback,
            )
            raise
        if operation_cancelled.is_set():
            preview.close()
            raise LinkingError('Detail request was cancelled.')
        _log_safe_photos_observation(
            format_safe_photos_decode_facts(PhotosDecodeFacts(
                'thumbnail-fallback', 'preview-fallback',
                facts.image_format, facts.source_size, preview.size,
                len(body), MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
            )),
            self._observation_callback,
        )
        return preview, None

    def _load_detail(self, index: int) -> bool:
        """Request a selected original and queue its constrained rendering.

        Args:
            index (int): Zero-based index in the visible photo page.

        Returns:
            bool: True when the detail is shown, started or queued; False
                when the viewer, index or live media permission is unavailable.
        """
        if self._closed or index < 0 or index >= len(self._photos):
            return False
        if not self.session.can_read_media():
            self.close()
            return False
        photo = self._photos[index]
        active_media_kind = self._worker_kind
        active_media_cancelled = self._active_media_cancelled
        same_active_media = bool(
            active_media_kind in ('detail', 'prefetch')
            and self._active_media_photo_id == photo.image_id
            and active_media_cancelled is not None
            and not active_media_cancelled.is_set()
        )
        same_active_detail = same_active_media and active_media_kind == 'detail'
        same_active_prefetch = (
            same_active_media and active_media_kind == 'prefetch'
        )
        if active_media_kind == 'thumbnail_batch':
            self._thumbnail_cancelled.set()
            self._generation += 1
        elif (active_media_kind in ('detail', 'prefetch', 'refresh')
              and not same_active_media
              and active_media_cancelled is not None):
            self._active_media_cancelled.set()
            self._generation += 1
            if active_media_kind == 'refresh':
                # Keep the requested refresh, but the user's newer selection
                # now leads it and its bounded adjacent-image preparation.
                self._refresh_pending = True
                self._refresh_manual_pending = False
        self._cancel_thumbnail_gui_slice()
        self._pending_detail_index = None
        self._pending_prefetch_ids.clear()
        if not same_active_detail:
            self._detail_serial += 1
        detail_serial = self._detail_serial
        self._slide_index = index
        self._ensure_lightbox()
        self._lightbox.present(
            ''.join(char if char.isprintable() else ' '
                    for char in photo.title).strip() or self._text('linking_media_open'),
            self._detail_position(index),
            can_previous=(index > 0 or bool(self._photos)),
            can_next=(index + 1 < len(self._photos)
                      or self._next_after is not None or bool(self._photos)),
        )
        self._bind_lightbox_shortcuts(self._lightbox._overlay)
        self.retry_button.configure(state='disabled')
        if same_active_detail:
            return True
        cached = (
            None if same_active_prefetch
            else self._cached_detail(photo.image_id)
        )
        if cached is not None:
            with self._thumbnail_metrics_lock:
                self._detail_media_metrics['detail_ram_hits'] += 1
            self._show_detail(
                detail_serial, photo.image_id, cached.copy(), None,
                self._cached_detail_encoded(photo.image_id),
            )
            self._dispatch_pending_work()
            return True
        if self._worker is not None:
            self._pending_detail_index = index
            return True

        operation_cancelled = threading.Event()
        self._active_media_cancelled = operation_cancelled
        self._active_media_photo_id = photo.image_id

        def operation() -> tuple[
            int, int, 'Image.Image', 'Image.Image | None', bytes | None,
        ]:
            """Fetch and normalize the selected image or safe preview.

            Returns:
                tuple[int, int, Image.Image, Image.Image | None, bytes | None]:
                    Selection, photo ID, display/cache rasters and original body.
            """
            timings = MediaReadTimings()
            try:
                image, body = self._read_prepared_detail(
                    photo, timings, cancelled=operation_cancelled,
                )
            finally:
                self._observe_detail_read('detail', timings)
            if operation_cancelled.is_set():
                image.close()
                raise LinkingError('Selected photo request was superseded.')
            private_descriptor = (
                photo.cache_eligible is not True
                and (self.session.can_read_admin_media()
                     or photo.cache_eligible is False
                     or photo.preload_eligible is not None)
            )
            cached_image: 'Image.Image | None' = image.copy()
            if (private_descriptor and timings.source != 'disk'
                    and not timings.cache_stored):
                cached_image.close()
                cached_image = None
            return detail_serial, photo.image_id, image, cached_image, body

        started = self._launch(
            'detail', operation,
            error_payload=(detail_serial, photo.image_id, None, None, None),
            operation_cancelled=operation_cancelled,
        )
        if not started and self._active_media_cancelled is operation_cancelled:
            self._active_media_cancelled = None
            self._active_media_photo_id = None
        return started

    def _ensure_lightbox(self) -> PhotoLightbox:
        """Create the shared photo overlay and its playlist toolbar actions.

        Returns:
            PhotoLightbox: Active overlay owner for this selected gallery.
        """
        if self._lightbox is None:
            self._lightbox = PhotoLightbox(
                self.window,
                lambda key: self._text(f'linking_media_{key}'),
                self._move_lightbox,
                self._close_lightbox,
                self._main_content,
            )
            self._lightbox_track_buttons = (
                self._lightbox.add_action(
                    '♫‹', lambda: self._move_track(-1),
                    self._text('linking_media_previous'),
                ),
                self._lightbox.add_action(
                    '▶', self._toggle_audio_pause,
                    self._lightbox_track_tooltip,
                ),
                self._lightbox.add_action(
                    '♫›', lambda: self._move_track(1),
                    self._text('linking_media_next'),
                ),
            )
            self._bind_lightbox_shortcuts(self._lightbox._overlay)
            self._refresh_audio_button()
        return self._lightbox

    def _bind_lightbox_shortcuts(self, overlay: tk.Misc) -> None:
        """Route fullscreen keys through one tag on active overlay descendants.

        Args:
            overlay (tk.Misc): Existing overlay tree owned by PhotoLightbox.

        Returns:
            None: One local bindtag handles shortcuts before default button logic.
        """
        retained_widgets: list[tk.Misc] = []
        for widget in self._lightbox_key_widgets:
            try:
                if widget.winfo_exists():
                    retained_widgets.append(widget)
            except tk.TclError:
                pass
        self._lightbox_key_widgets = retained_widgets
        pending = [overlay]
        while pending:
            widget = pending.pop()
            try:
                tags = widget.bindtags()
                if self._lightbox_key_tag not in tags:
                    widget.bindtags((self._lightbox_key_tag, *tags))
                    self._lightbox_key_widgets.append(widget)
                pending.extend(widget.winfo_children())
            except tk.TclError:
                continue
        if not self._lightbox_key_bindings:
            for sequence in ('<KeyPress-f>', '<KeyPress-F>', '<KeyPress-F11>'):
                binding = self.window.bind_class(
                    self._lightbox_key_tag, sequence,
                    self._on_fullscreen, add='+',
                )
                if binding is not None:
                    self._lightbox_key_bindings.append((sequence, binding))

    def _unbind_lightbox_shortcuts(self) -> None:
        """Remove this viewer's lightbox bindtag from its retained widgets.

        Returns:
            None: No fullscreen callback or bindtag outlives its viewer.
        """
        for sequence, binding in self._lightbox_key_bindings:
            try:
                self.window.unbind_class(self._lightbox_key_tag, sequence)
                self.window.deletecommand(binding)
            except tk.TclError:
                pass
        self._lightbox_key_bindings.clear()
        for widget in self._lightbox_key_widgets:
            try:
                widget.bindtags(tuple(
                    tag for tag in widget.bindtags()
                    if tag != self._lightbox_key_tag
                ))
            except tk.TclError:
                pass
        self._lightbox_key_widgets.clear()

    def _show_detail(
        self,
        detail_serial: int,
        image_id: int,
        display_image: 'Image.Image | None',
        cached_image: 'Image.Image | None',
        encoded: bytes | None,
        decode_reason: str | None = None,
    ) -> None:
        """Render one normalized detail image on Tk's owning thread.

        Args:
            detail_serial (int): Selection generation captured by the request.
            image_id (int): Exact gallery photo represented by the data.
            display_image (Image.Image | None): Worker-prepared RGB image
                transferred to the lightbox, or None after failure.
            cached_image (Image.Image | None): Independent prepared copy retained
                only when the worker response is fresh and usable.
            encoded (bytes | None): Complete source body retained for HDR rendering.
            decode_reason (str | None): Fixed decoder outcome for a refused source.

        Returns:
            None: The lightbox owns the rendered image reference.
        """
        if detail_serial != self._detail_serial:
            if display_image is not None:
                display_image.close()
            if cached_image is not None:
                cached_image.close()
            return
        if cached_image is not None:
            if encoded is not None:
                self._remember_detail(image_id, cached_image, encoded)
            else:
                cached_image.close()
        if display_image is None or ImageTk is None:
            if display_image is not None:
                display_image.close()
            if self._lightbox is not None:
                error_key = (
                    'linking_media_decode_error'
                    if decode_reason in (
                        'decode-failed', 'pixel-limit', 'body-limit',
                    ) else 'linking_media_image_error'
                )
                self._lightbox.show_error(self._text(error_key))
            if self._slideshow_running:
                if len(self._photos) > 1:
                    self._schedule_slide()
                else:
                    self._stop_slideshow()
            return
        photo = self._photos[self._slide_index]
        if photo.image_id != image_id:
            display_image.close()
            return
        latitude = getattr(photo, 'latitude', None)
        longitude = getattr(photo, 'longitude', None)
        location = (
            (float(latitude), float(longitude))
            if (type(latitude) in (int, float) and type(longitude) in (int, float))
            else None
        )
        self._lightbox.show_prepared_image(
            display_image, location=location, encoded=encoded,
        )
        self._queue_adjacent_prefetch(self._slide_index)
        if self._slideshow_running and self._slideshow_id is None:
            self._schedule_slide()

    def _detail_position(self, index: int) -> str:
        """Return the visible ordinal across the accumulated album sequence.

        Args:
            index (int): Zero-based photo index on the active page.

        Returns:
            str: Album-wide ordinal without internal server identifiers.
        """
        return f'{index + 1} / {len(self._photos)}'

    def _move_lightbox(self, delta: int) -> None:
        """Navigate one photo from the lightbox's directional controls.

        Args:
            delta (int): Directional step, either negative or positive one.

        Returns:
            None: The viewer starts the corresponding foreground detail read.
        """
        self._prefetch_direction = 1 if delta > 0 else -1
        target = self._slide_index + delta
        if 0 <= target < len(self._photos):
            self._select_photo(target)
        elif delta < 0:
            if self._worker_kind in ('page', 'more', 'slide_more'):
                self._select_first_after_page = False
                self._wrap_after_metadata = -1
                return
            if self._next_after is None:
                self._select_photo(len(self._photos) - 1)
            else:
                self._wrap_after_metadata = -1
                self._load_more()
        elif self._next_after is not None:
            if self._worker_kind in ('page', 'more', 'slide_more'):
                self._select_first_after_page = True
            else:
                self._load_more(select_first=True)
        elif self._photos:
            self._select_photo(0)

    def _close_lightbox(self) -> None:
        """Dismiss the detail panel without closing its gallery viewer.

        Returns:
            None: Grid focus and interrupted viewport admission resume in the
                parent window; active detail/prefetch reads lose their slot.
        """
        self._detail_serial += 1
        if (self._worker_kind in ('detail', 'prefetch')
                and self._active_media_cancelled is not None):
            self._active_media_cancelled.set()
            self._generation += 1
        self._pending_detail_index = None
        self._pending_prefetch_ids.clear()
        if self._prefetch_edge_direction:
            self._pending_page_request = None
        self._select_first_after_page = False
        self._wrap_after_metadata = 0
        self._prefetch_edge_direction = 0
        self._stop_slideshow()
        if (not self._closed
                and 0 <= self._slide_index < len(self._tile_buttons)):
            self._tile_buttons[self._slide_index].focus_set()
        if not self._closed:
            self._on_grid_viewport(None)

    def _on_escape(self, _event: tk.Event) -> str | None:
        """Route Escape through contextual panels while keeping Photos mounted.

        Args:
            _event (tk.Event): Key event received by the viewer toplevel.

        Returns:
            str | None: Break marker when a contextual surface consumes Escape.
        """
        if self._music_panel is not None:
            self._close_music_panel()
            self.music_button.focus_set()
            return 'break'
        if self._lightbox is not None and self._lightbox.visible:
            if not self._lightbox.escape():
                self._lightbox.dismiss()
            return 'break'
        return None

    def _retry_current(self) -> None:
        """Retry the throttled or failed current media operation.

        Returns:
            None: The user action resumes one selected foreground request.
        """
        self.retry_button.configure(state='disabled')
        self._media_throttled = False
        self._thumbnail_cancelled.clear()
        self.status.pack_forget()
        if self._retry_kind == 'detail' and self._photos:
            self._load_detail(self._slide_index)
        elif self._retry_kind == 'thumbnail':
            self._dispatch_pending_work()
        elif self._page_request_mode == 'initial':
            self._load_first_page()
        else:
            self._start_page_after(self._page_request_cursor)

    def _toggle_slideshow(self) -> None:
        """Start or stop the user-requested slideshow timer.

        Returns:
            None: The timer advances photos only while explicitly started.
        """
        if self._slideshow_running:
            self._stop_slideshow()
            return
        if not self._photos:
            return
        if not self.session.can_read_media():
            self.close()
            return
        self._slideshow_running = True
        self._slideshow_music_attempted = self._audio_index is not None
        self._slideshow_started_music = False
        self._ensure_lightbox()
        if not self._lightbox.visible and self._photos:
            self._slide_index = -1
        self._lightbox.enter_fullscreen()
        if self._slide_index + 1 >= len(self._photos) and self._next_after is None:
            self._slide_index = -1
        self.slide_button.configure(text='Ⅱ')
        self._slideshow_tooltip.set(self._text('linking_media_slideshow_stop'))
        self._advance_slide()
        if (self._audio_index is None and self._presentation is not None
                and self._presentation.music
                and self._tracks):
            self._slideshow_music_attempted = True
            self._toggle_track()
            self._slideshow_started_music = self._audio_index is not None

    def _stop_slideshow(self) -> None:
        """Stop slideshow advancement and cancel its one pending Tk timer.

        Returns:
            None: Timer and slideshow-owned music stop; prior background music stays.
        """
        self._slideshow_running = False
        if self._slideshow_id is not None:
            try:
                self.window.after_cancel(self._slideshow_id)
            except tk.TclError:
                pass
            self._slideshow_id = None
        if self._slideshow_started_music:
            self._slideshow_started_music = False
            self._stop_audio()
        if not self._closed:
            self.slide_button.configure(
                text='▶',
            )
            self._slideshow_tooltip.set(
                self._text('linking_media_slideshow_start'),
            )

    def _slideshow_delay_ms(self) -> int:
        """Return the authorized interval or the established five-second pace.

        Returns:
            int: Delay in milliseconds for one manually started slide.
        """
        raw = (self._presentation.interval_seconds
               if self._presentation is not None and self._presentation.enabled
               else MEDIA_SLIDESHOW_DEFAULT_SECONDS)
        seconds = (raw if type(raw) is int and raw in MEDIA_SLIDESHOW_INTERVALS
                   else MEDIA_SLIDESHOW_DEFAULT_SECONDS)
        return seconds * 1000

    def _schedule_slide(self) -> None:
        """Schedule the next manual slideshow advance.

        Returns:
            None: Tk advances one image after the server-provided interval.
        """
        if self._closed or not self._slideshow_running:
            return
        self._slideshow_id = self.window.after(
            self._slideshow_delay_ms(),
            self._advance_slide,
        )

    def _advance_slide(self) -> None:
        """Show the next photo, wrapping continuously while still active.

        Returns:
            None: Explicit playback loops until the user stops it or closes it.
        """
        self._slideshow_id = None
        if self._closed or not self._slideshow_running or not self._photos:
            self._stop_slideshow()
            return
        next_index = self._slide_index + 1
        self._prefetch_direction = 1
        if next_index >= len(self._photos):
            if self._next_after is None:
                self._select_photo(0, keep_slideshow=True)
            else:
                self._load_more(automatic=True)
            return
        if not self._load_detail(next_index):
            self._stop_slideshow()

    def _toggle_track(self) -> None:
        """Start an explicitly selected playlist position or stop playback.

        Returns:
            None: Queues the selected track only after an explicit Play.
        """
        if self._audio_index is not None:
            self._stop_audio()
            return
        if (not self._tracks or not self.session.can_read_presentation()
                or self._presentation is None or not self._presentation.music):
            return
        if sys.platform not in ('win32', 'darwin'):
            self._audio_feedback('linking_media_audio_unavailable')
            return
        selected = (
            self.track_menu.current() if self.track_menu is not None
            else self._selected_track_index
        )
        if not 0 <= selected < len(self._tracks):
            return
        self._selected_track_index = selected
        self._audio_index = selected
        self._audio_pending = selected
        self._audio_cancelled = threading.Event()
        self._refresh_audio_button()
        self._dispatch_audio_prefetch()

    def _move_track(self, delta: int) -> None:
        """Select an adjacent track and resume playback when it was active.

        Args:
            delta (int): Playlist step; negative is previous and positive next.
        Returns:
            None: The bounded playlist wraps at either end.
        """
        if not self._tracks:
            return
        was_active = self._audio_index is not None
        if was_active:
            self._stop_audio()
        self._selected_track_index = (
            self._selected_track_index + delta
        ) % len(self._tracks)
        if self.track_menu is not None:
            self.track_menu.current(self._selected_track_index)
        self._refresh_audio_button()
        if was_active:
            self._toggle_track()

    def _toggle_audio_pause(self) -> None:
        """Pause/resume the current track or start its selected position.

        Returns:
            None: Native playback position is preserved across Pause and Resume.
        """
        if self._audio_index is None:
            self._toggle_track()
            return
        self._set_audio_paused(not self._audio_paused)

    def _set_audio_paused(self, paused: bool) -> None:
        """Apply a requested pause state to the currently owned audio backend.

        Args:
            paused (bool): Whether native playback should hold its position.
        Returns:
            None: Polling stops while paused and resumes with the player.
        """
        if self._audio_index is None or self._audio_paused == paused:
            return
        self._audio_paused = paused
        try:
            self._apply_audio_pause()
            if paused:
                if self._audio_poll_id is not None:
                    self.window.after_cancel(self._audio_poll_id)
                    self._audio_poll_id = None
            elif self._audio_process is not None or self._audio_alias is not None:
                self._audio_poll_id = self.window.after(
                    MEDIA_AUDIO_POLL_MS, self._poll_audio,
                )
        except (OSError, tk.TclError):
            self._audio_failed()
            return
        self._refresh_audio_button()

    def _apply_audio_pause(self) -> None:
        """Send Pause or Resume to the owned MCI player or afplay process.

        Returns:
            None: The current player holds or resumes its exact position.
        """
        process = self._audio_process
        alias = self._audio_alias
        if process is not None:
            process.send_signal(signal.SIGSTOP if self._audio_paused else signal.SIGCONT)
        elif alias is not None and self._mci is not None:
            command = 'pause' if self._audio_paused else 'resume'
            self._mci_command(self._mci, f'{command} {alias}')

    def _dispatch_audio_prefetch(self) -> bool:
        """Dispatch only the explicit selection or its immediate successor.

        Returns:
            bool: True when a track request starts; False when no current track
                is pending, access is cancelled or another worker must finish.
        """
        index = self._audio_pending
        cancelled = self._audio_cancelled
        if (self._worker_kind == 'thumbnail_batch' and index is not None
                and cancelled is not None and not cancelled.is_set()):
            self._thumbnail_cancelled.set()
            self._generation += 1
        if (self._worker is not None or index is None or cancelled is None
                or cancelled.is_set() or self._closed):
            return False
        track = self._tracks[index]

        def operation() -> tuple[threading.Event, int, bytes | None]:
            """Fetch and independently check the selected track's exact bytes.

            Returns:
                tuple[threading.Event, int, bytes | None]: Owner, playlist index
                    and verified bytes, or no bytes after cancellation.
            """
            if cancelled.is_set():
                return cancelled, index, None
            body = self.session.media_track(
                self.gallery.gallery_id, track, cancelled=cancelled,
            )
            if (len(body) > NATIVE_MEDIA_MAX_BYTES
                    or len(body) != track.size
                    or hashlib.sha256(body).hexdigest() != track.sha256):
                raise ValueError('Track integrity check failed')
            return cancelled, index, None if cancelled.is_set() else body

        if not self._launch(
            'audio_track', operation, error_payload=(cancelled, index, None),
        ):
            return False
        self._audio_pending = None
        return True

    def _accept_audio_track(
        self, cancelled: threading.Event, index: int, body: bytes | None,
    ) -> None:
        """Prepare one current or next track and start only the current track.

        Args:
            cancelled (threading.Event): Cancellation owner captured at Play.
            index (int): Position of this verified track in the current playlist.
            body (bytes | None): Verified track bytes, or None on failure.

        Returns:
            None: Current-track failure stops playback. Successor failure
                is surfaced when the current track finishes.
        """
        if cancelled is not self._audio_cancelled or cancelled.is_set():
            return
        if not self.session.can_read_presentation():
            self._audio_failed()
            return
        current = index == self._audio_index
        if body is not None:
            track = self._tracks[index]
            suffix = '.mp3' if track.mime == 'audio/mpeg' else '.wav'
            try:
                with tempfile.NamedTemporaryFile(
                    prefix='php-gallery-track-', suffix=suffix, delete=False,
                ) as stream:
                    self._audio_prepared = (index, stream.name)
                    stream.write(body)
            except (OSError, ValueError):
                self._discard_prepared_audio()
            else:
                if current:
                    self._start_prepared_audio()
                return
        if current:
            self._audio_failed()
        else:
            self._audio_successor_failed = True

    def _start_prepared_audio(self) -> None:
        """Transfer an already prepared file to the native audio player.

        Returns:
            None: Starts or pauses the track and requests one next track.
        """
        prepared = self._audio_prepared
        if prepared is None or prepared[0] != self._audio_index:
            return
        index, path = prepared
        self._audio_prepared = None
        self._audio_path = path
        try:
            if sys.platform == 'darwin':
                self._audio_process = subprocess.Popen(
                    ['/usr/bin/afplay', path], stdin=subprocess.DEVNULL,
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                )
            else:
                if '"' in path:
                    raise ValueError('Unsupported temporary path')
                alias = 'pglink' + uuid.uuid4().hex
                mci = self._load_winmm()
                media_type = 'mpegvideo' if path.endswith('.mp3') else 'waveaudio'
                self._mci_command(mci, f'open "{path}" type {media_type} alias {alias}')
                self._audio_alias = alias
                self._mci_command(mci, f'play {alias}')
            self._selected_track_index = index
            self._refresh_audio_button()
            if self._audio_paused:
                self._apply_audio_pause()
            else:
                self._audio_poll_id = self.window.after(
                    MEDIA_AUDIO_POLL_MS, self._poll_audio,
                )
            next_index = index + 1
            if next_index < len(self._tracks):
                self._audio_pending = next_index
        except (OSError, ValueError, tk.TclError):
            self._audio_failed()

    def _poll_audio(self) -> None:
        """Observe native playback without blocking or querying an unrelated player.

        Returns:
            None: Advances to prepared audio on completion or stops on failure.
        """
        self._audio_poll_id = None
        index = self._audio_index
        if index is None or self._audio_paused:
            return
        if not self.session.can_read_presentation():
            self._audio_failed()
            return
        try:
            if self._audio_process is not None:
                result = self._audio_process.poll()
                if result not in (None, 0):
                    raise OSError('Native audio player failed')
                playing = result is None
            elif self._audio_alias is not None and self._mci is not None:
                mode = ctypes.create_unicode_buffer(MEDIA_MCI_MODE_CAPACITY)
                output = ctypes.cast(mode, ctypes.c_wchar_p)
                if self._mci(f'status {self._audio_alias} mode', output, len(mode), None):
                    raise OSError('Could not read native audio state')
                state = mode.value.lower()
                if state not in ('playing', 'seeking', 'stopped'):
                    raise OSError('Unexpected native audio state')
                playing = state != 'stopped'
            else:
                raise OSError('Native audio player is unavailable')
            if playing:
                self._audio_poll_id = self.window.after(MEDIA_AUDIO_POLL_MS, self._poll_audio)
                return
            if self._audio_successor_failed:
                self._audio_failed()
                return
            self._release_audio_output()
            next_index = index + 1
            if next_index >= len(self._tracks):
                self._stop_audio()
                return
            self._audio_index = next_index
            self._start_prepared_audio()
            self._dispatch_pending_work()
        except (OSError, tk.TclError):
            self._audio_failed()

    def _refresh_audio_button(self) -> None:
        """Refresh track selection and fullscreen Play/Pause availability.

        Returns:
            None: Updates the chooser and compact lightbox track controls.
        """
        active = self._audio_index is not None
        available = bool(
            self._tracks and self._presentation is not None
            and self._presentation.music
            and self.session.can_read_presentation()
        )
        try:
            if self.track_menu is not None:
                self.track_menu.configure(state='disabled' if active else 'readonly')
                self.track_menu.current(
                    min(self._selected_track_index, len(self._tracks) - 1),
                )
            if self.panel_track_button is not None:
                self.panel_track_button.configure(
                    text='■' if active else '▶',
                    state='normal',
                )
                self._panel_track_tooltip.set(self._text(
                    'linking_media_stop' if active else 'linking_media_play',
                ))
            if self._lightbox_track_buttons:
                tooltip_key = (
                    'linking_media_resume'
                    if active and self._audio_paused else
                    'linking_media_pause' if active else 'linking_media_play'
                )
                self._lightbox_track_tooltip.set(self._text(tooltip_key))
                self._lightbox_track_buttons[0].configure(
                    state='normal' if available else 'disabled',
                )
                self._lightbox_track_buttons[1].configure(
                    text='▶' if self._audio_paused or not active else 'Ⅱ',
                    state='normal' if available else 'disabled',
                )
                self._lightbox_track_buttons[2].configure(
                    state='normal' if available else 'disabled',
                )
        except tk.TclError:
            pass

    def _audio_feedback(self, key: str) -> None:
        """Show localized playback feedback in the viewer status surface.

        Args:
            key (str): Localized playback status key.

        Returns:
            None: The message is visible even after photos have loaded.
        """
        self.status.pack(anchor='w', pady=(4, 8))
        self.status.configure(text=self._text(key))

    def _audio_failed(self) -> None:
        """Cancel failed playback and surface the localized failure.

        Returns:
            None: Native output and prepared playback files are released.
        """
        self._stop_audio()
        self._audio_feedback('linking_media_audio_error')

    def _load_winmm(self) -> _MciSender:
        """Load winmm from the OS-reported System32 directory only.

        Returns:
            _MciSender: Typed Unicode MCI command function.

        Raises:
            OSError: The system directory or native library cannot be resolved.
        """
        if sys.platform != 'win32' or not hasattr(ctypes, 'WinDLL'):
            raise OSError('Windows multimedia is unavailable')
        kernel32 = ctypes.WinDLL(
            'kernel32.dll',
            use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        get_system_directory = kernel32.GetSystemDirectoryW
        get_system_directory.argtypes = [ctypes.c_wchar_p, ctypes.c_uint]
        get_system_directory.restype = ctypes.c_uint
        buffer = ctypes.create_unicode_buffer(WINDOWS_SYSTEM_DIRECTORY_BUFFER)
        length = get_system_directory(buffer, len(buffer))
        if length == 0 or length >= len(buffer):
            raise OSError('Could not resolve Windows System32')
        library_path = os.path.join(buffer.value, 'winmm.dll')
        if not os.path.isabs(library_path):
            raise OSError('Windows multimedia path is not absolute')
        library = ctypes.WinDLL(
            library_path,
            use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        mci = library.mciSendStringW
        mci.argtypes = [
            ctypes.c_wchar_p,
            ctypes.c_wchar_p,
            ctypes.c_uint,
            ctypes.c_void_p,
        ]
        mci.restype = ctypes.c_uint
        self._winmm = library
        self._mci = mci
        return mci

    def _mci_command(self, mci: _MciSender, command: str) -> None:
        """Send one bounded command to the current Windows MCI device.

        Args:
            mci (_MciSender): Windows winmm MCI command function.
            command (str): Locally constructed command and owned alias.

        Returns:
            None: Raises OSError if winmm rejects the command.
        """
        result = mci(command, None, 0, None)
        if result:
            raise OSError(f'MCI command failed: {result}')

    def _release_audio_output(self) -> None:
        """Close only this viewer's native player and unlink its current file.

        Returns:
            None: The next prepared track stays available for natural handoff.
        """
        alias, self._audio_alias = self._audio_alias, None
        if alias is not None and self._mci is not None:
            for command in (f'stop {alias}', f'close {alias}'):
                try:
                    self._mci(command, None, 0, None)
                except OSError:
                    pass
        self._mci = None
        self._winmm = None
        process, self._audio_process = self._audio_process, None
        if process is not None and process.poll() is None:
            try:
                process.kill()
                process.wait(timeout=MEDIA_AUDIO_REAP_SECONDS)
            except (OSError, subprocess.TimeoutExpired):
                pass
        path, self._audio_path = self._audio_path, None
        if path is not None:
            try:
                os.unlink(path)
            except OSError:
                pass

    def _stop_audio(self) -> None:
        """Cancel one playlist run and discard its current and next files.

        Returns:
            None: Late worker results cannot restart playback.
        """
        if self._audio_cancelled is not None:
            self._audio_cancelled.set()
            self._audio_cancelled = None
        self._audio_index = None
        self._audio_paused = False
        self._audio_pending = None
        self._audio_successor_failed = False
        if self._audio_poll_id is not None:
            try:
                self.window.after_cancel(self._audio_poll_id)
            except tk.TclError:
                pass
            self._audio_poll_id = None
        self._release_audio_output()
        self._discard_prepared_audio()
        self._refresh_audio_button()

    def _discard_prepared_audio(self) -> None:
        """Unlink the owned successor or a file left by a failed write.

        Returns:
            None: Releases only the file owned by the preparation slot.
        """
        prepared, self._audio_prepared = self._audio_prepared, None
        if prepared is not None:
            try:
                os.unlink(prepared[1])
            except OSError:
                pass

    def close(self, *, destroy_frame: bool = True) -> None:
        """Cancel owned work, stop playback, and optionally destroy the frame.

        Args:
            destroy_frame (bool): Whether this call should destroy its child frame.

        Returns:
            None: Work stops and Tk-owned cycles release on the owner thread.
        """
        if self._closed:
            return
        self._closed = True
        self._cancelled.set()
        if self._active_media_cancelled is not None:
            self._active_media_cancelled.set()
        self._active_media_photo_id = None
        self._thumbnail_disk_lane.invalidate(close=True)
        self._thumbnail_cancelled.set()
        self._cancel_grid_viewport_callback()
        self._cancel_thumbnail_gui_slice()
        if self._revision_timer is not None:
            try:
                self.window.after_cancel(self._revision_timer)
            except tk.TclError:
                pass
            self._revision_timer = None
        self._generation += 1
        if self._after_id is not None:
            try:
                self.window.after_cancel(self._after_id)
            except tk.TclError:
                pass
        self._stop_slideshow()
        self._stop_audio()
        self._close_music_panel()
        if self._lightbox is not None:
            self._unbind_lightbox_shortcuts()
            self._lightbox.dismiss()
            self._lightbox.close()
        self._photos = ()
        self._tracks = ()
        self._presentation = None
        self._pending_detail_index = None
        self._pending_page_request = None
        self._pending_prefetch_ids.clear()
        for _image_id, (_fetched_at, image, _encoded, _size, _proof) in (
            self._detail_cache.items()
        ):
            image.close()
        self._detail_cache.clear()
        self._detail_cache_bytes = 0
        if self._owns_thumbnail_memory:
            self._thumbnail_memory.clear()
        self._thumbnail_image_release_count += sum(
            image is not None for image in self._photo_images
        )
        self._photo_images.clear()
        self._thumbnail_loaded.clear()
        self._thumbnail_pending.clear()
        self._visible_thumbnail_indices.clear()
        self._admitted_thumbnail_indices.clear()
        self._tile_tooltips.clear()
        self._tile_titles.clear()
        self._tile_buttons.clear()
        with self._thumbnail_result_lock:
            while not self._events.empty():
                try:
                    _generation, kind, result = self._events.get_nowait()
                    self._release_worker_result(result, kind)
                except queue.Empty:
                    break
        for sequence, identifier in self._window_bindings:
            if identifier is not None:
                try:
                    self.window.unbind(sequence, identifier)
                except tk.TclError:
                    pass
        self._window_bindings.clear()
        if destroy_frame:
            try:
                self.frame.destroy()
            except tk.TclError:
                pass
        # A cancelled worker may still hold a reference to this controller.
        # Release its Tcl variables while the Tk owner still controls teardown.
        if getattr(self.frame, '_gallery_viewer_controller', None) is self:
            del self.frame._gallery_viewer_controller
        self._context_menu = None
        self._lightbox = None
        del self._panel_track_tooltip
        del self._slideshow_tooltip
        del self._lightbox_track_tooltip
        self._lightbox_track_buttons = ()

    def _on_destroy(self, event: tk.Event) -> None:
        """Release viewer resources when its own child frame is destroyed.

        Args:
            event (tk.Event): Tk destruction event for this frame or a child.

        Returns:
            None: Cleanup starts only for this viewer frame's destroy event.
        """
        if event.widget is self.frame and not self._closed:
            self.close(destroy_frame=False)


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
    )
    return viewer.frame
