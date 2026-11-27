# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_361_tk_workloads.py
# Module Type: Windows Native UI Workload Driver
# Purpose: Measure production Watcher and Photos Tk callbacks with safe synthetic data.
# Responsibilities:
#   - Run dashboard, import, gallery and lightbox workloads in isolated processes.
#   - Record callback, event-loop and interaction timings with explicit limits.
#   - Keep media synthetic and fixture state inside a temporary directory.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Run P0 native Tk callback workloads against a supplied source checkout."""

import argparse
import importlib.machinery
import importlib.util
import hashlib
import json
import math
import os
import platform
import queue
import random
import stat
from collections import OrderedDict
import sys
import tempfile
import threading
import time
import weakref
import tkinter as tk
from io import BytesIO
from pathlib import Path, PurePosixPath
from tkinter import ttk
from types import ModuleType, SimpleNamespace, TracebackType
from typing import Callable, Concatenate, Generic, ParamSpec, Protocol, TypeAlias, TypeVar, TypedDict, cast
import traceback as traceback_module
from unittest import mock
import zipfile
from PIL import Image, __version__ as PILLOW_VERSION

from PIL import ImageDraw, ImageGrab, ImageTk

try:
    import psutil
except ImportError:
    psutil = None

# Select the benchmark's production checkout before any uploader imports. Both
# the standalone child and the Issue 434 child receive the exact same --source.
_source_parser = argparse.ArgumentParser(add_help=False, allow_abbrev=False)
_source_parser.add_argument("--source", type=Path)
_source_options, _unused_options = _source_parser.parse_known_args()
_source_root = _source_options.source or Path(__file__).resolve().parents[2]
_winapp_source = str(_source_root.resolve() / "winapp")
if _winapp_source not in sys.path:
    sys.path.insert(0, _winapp_source)

from uploader import native_gallery_viewer as native
from uploader.app_linking import (
    LinkedGallery, LinkedMediaPage, LinkedPhoto, LinkedPresentation, LinkingSession,
)
from uploader.gallery_lightbox import PhotoLightbox
from uploader.native_gallery_viewer import GalleryThumbnailMemory
from uploader.ui_theme import Button
from uploader.windows_display import prepare_windows_display

# Purpose: Provide reproducible synthetic colors and row values.
# Type: int. Units: PRNG seed. Scope: one independent scenario process.
# Consumers: make_photo_bodies. Rationale: Identical source/cache trials use identical fixtures.
DEFAULT_SEED = 3612026
# Purpose: Bound synthetic failure detail retained in one evidence record.
# Type: int. Units: characters. Scope: one child invocation.
# Consumers: Tk callback and child exception reporting.
# Rationale: 1000 characters preserves the actionable error without raw payloads.
FAILURE_TEXT_LIMIT = 1000
# Purpose: Retain bounded source call-site evidence on a synthetic failure.
# Type: int. Units: characters. Scope: one child traceback.
# Consumers: callback_exception and run. Rationale: 12000 retains useful frames
# while bounding per-process failure artifacts.
FAILURE_TRACEBACK_LIMIT = 12000
# Purpose: Keep one process within the hosted interactive trial budget.
# Type: int. Units: milliseconds. Scope: measured Tk mainloop phase.
# Consumers: begin_measurement. Rationale: Eight seconds yields repeated callbacks at the
# 250 ms cadence while preserving a short Windows CI workload.
MEASURED_MS = 8000
# Purpose: Let Tk map native controls and drain startup callbacks before measurement.
# Type: int. Units: milliseconds. Scope: one scenario process.
# Consumers: begin_measurement. Rationale: 800 ms is the minimum mapping delay;
# warm-cache readiness has its separate bounded preparation deadline.
WARMUP_MS = 800
# Purpose: Drive synthetic telemetry and interactions on a consistent cadence.
# Type: int. Units: milliseconds. Scope: dashboard and UI interactions.
# Consumers: schedule_tick. Rationale: 250 ms matches the Watcher monitor cadence.
TICK_MS = 250
# Purpose: Bound measured raw timing observations retained in one child report.
# Type: int. Units: observations. Scope: one invocation.
# Consumers: Recorder.add. Rationale: 20,000 measured samples capture jitter
# without unbounded output; excluded warmup consumes none of this capacity.
RAW_EVENT_LIMIT = 20000
# Purpose: Exercise the production import item row cap.
# Type: int. Units: items. Scope: one ImportJob fixture.
# Consumers: make_import_job. Rationale: the real renderer caps at 1,000 items.
IMPORT_ROWS = 1000
# Purpose: Exercise history and activity owner limits with larger source collections.
# Type: int. Units: jobs or events. Scope: import scenario fixture.
# Consumers: make_import_job and build_import_widgets. Rationale: 1,000 source rows
# prove the production views retain their independent 200-job and 100-event caps.
SOURCE_HISTORY_ROWS = 1000
SOURCE_ACTIVITY_ROWS = 1000
# Purpose: Traverse a synthetic Photos catalogue across real media pages.
# Type: int. Units: photos. Scope: gallery and lightbox scenarios.
# Consumers: build_gallery. Rationale: 500 rows span eight validated 64-photo pages.
PHOTO_COUNT = 500
# Purpose: Version the deterministic archive manifest independently of reports.
# Type: int. Units: schema version. Scope: one issue 399 fixture manifest.
# Consumers: bundle writer and reader. Rationale: identify this first fixed layout.
FIXTURE_BUNDLE_SCHEMA_VERSION = 1
# Purpose: Preserve private regular-file attributes in every canonical ZIP member.
# Type: int. Units: Unix mode bits. Scope: generated synthetic fixture members.
# Consumers: bundle writer. Rationale: regular owner-only files are portable and safe.
FIXTURE_BUNDLE_FILE_MODE = stat.S_IFREG | 0o600
# Purpose: Place Unix attributes in their standard ZIP external-attribute field.
# Type: int. Units: bit positions. Scope: canonical ZIP member headers.
# Consumers: bundle writer. Rationale: ZIP stores the Unix mode in the upper word.
FIXTURE_BUNDLE_UNIX_ATTR_SHIFT = 16
# Purpose: Use a stable platform identifier for the canonical Unix mode field.
# Type: int. Units: ZIP creator platform ID. Scope: synthetic fixture members.
# Consumers: bundle writer. Rationale: Unix ID 3 makes archive bytes host-independent.
FIXTURE_BUNDLE_ZIP_CREATOR = 3
# Purpose: Bound serialized deterministic fixture bundles used by issue 399.
# Type: int. Units: bytes. Scope: one owned benchmark-stage archive.
# Consumers: bundle writer and reader. Rationale: retain bounded temp storage.
FIXTURE_BUNDLE_MAX_BYTES = 134217728
# Purpose: Bound the JSON member describing deterministic fixture identities.
# Type: int. Units: bytes. Scope: one fixture bundle manifest.
# Consumers: bundle writer and reader. Rationale: 5000 hashes fit below this cap.
FIXTURE_BUNDLE_MANIFEST_MAX_BYTES = 1048576
# Purpose: Bound any single image body admitted from the fixture bundle.
# Type: int. Units: bytes. Scope: one PNG or JPEG member.
# Consumers: bundle writer and reader. Rationale: generated fixtures are far smaller.
FIXTURE_BUNDLE_MEMBER_MAX_BYTES = 33554432
# Purpose: Cap the largest fixture catalogue accepted by the shared bundle code.
# Type: int. Units: photos. Scope: issue 399 scrolling extents.
# Consumers: bundle writer and reader. Rationale: match the protocol's largest extent.
FIXTURE_BUNDLE_MAX_PHOTOS = 5000
# Purpose: Bound streaming archive digest memory.
# Type: int. Units: bytes. Scope: one fixture bundle hash operation.
# Consumers: fixture bundle writer and reader.
# Rationale: fixed small reads avoid loading the archive as one byte string.
FIXTURE_BUNDLE_HASH_BLOCK_BYTES = 1048576
# Purpose: Fix ZIP member timestamps for repeatable archive identities.
# Type: tuple[int, int, int, int, int, int].
# Units: ZIP timestamp fields. Scope: every generated fixture bundle.
# Consumers: prepare_fixture_bundle. Rationale: remove wall-clock variation.
FIXTURE_BUNDLE_ZIP_TIMESTAMP = (1980, 1, 1, 0, 0, 0)

# Purpose: Keep one deterministic synthetic thumbnail catalog per child process.
# Type: dict[int, tuple[bytes, ...]]. Units: seed to image body set. Scope: one process.
# Consumers: build_gallery. Rationale: Exclude fixture generation from timed UI callbacks.
PHOTO_BODY_FIXTURES: dict[int, tuple[bytes, ...]] = {}
# Purpose: Match the production media descriptor page capacity.
# Type: int. Units: photos per page. Scope: synthetic LinkingSession.
# Consumers: build_gallery. Rationale: Keep synthetic page shape within client admission.
PHOTO_PAGE_SIZE = 64
# Purpose: Admit later media pages during repeated production viewport actions.
# Type: tuple[float, ...]. Units: viewport fractions. Scope: gallery scenario actions.
# Consumers: _on_grid_viewport workload. Rationale: Frequent bottom views admit all eight pages.
GALLERY_SCROLL_POSITIONS = (0.0, 1.0, 1.0, 0.5, 1.0, 1.0, 1.0, 1.0)
# Purpose: Vary bounded thumbnail dimensions through actual decode/resize code.
# Type: tuple[tuple[int, int], ...]. Units: pixels. Scope: the 500-photo dataset.
# Consumers: make_photo_bodies. Rationale: 64x64, 80x56, 48x72 and96x64
# exercise four reproducible aspect ratios without large fixture allocations.
THUMBNAIL_SIZE_VARIANTS = ((64, 64), (80, 56), (48, 72), (96, 64))
# Purpose: Bound lazily generated synthetic detail images.
# Type: tuple[int, int]. Units: pixels. Scope: one selected lightbox detail.
# Consumers: build_gallery. Rationale: 3840 by 2160 exercises real 4K detail decoding.
HIRES_SIZE = (3840, 2160)
# Purpose: Bound the synthetic session selected-detail lookup.
# Type: int. Units: lookup entries. Scope: one synthetic media session.
# Consumers: build_gallery. Rationale: one selected entry references the separate
# two-body immutable fixture pool without generating images during measurements.
HIRES_CACHE_LIMIT = 1
# Purpose: Keep detail fixture generation out of worker timings.
# Type: int. Units: distinct hires bodies. Scope: one process and deterministic seed.
# Consumers: run and build_gallery. Rationale: Two images vary real decode while bounding memory.
HIRES_FIXTURE_VARIANTS = 2
HIRES_FIXTURE_BODIES: dict[int, tuple[bytes, ...]] = {}
# Purpose: Add visible spatial texture to synthetic image bodies.
# Type: int. Units: pixels per texture cell. Scope: generated thumbnails and hires.
# Consumers: make_pattern_body. Rationale: Eight-pixel cells survive resize and distinguish content.
TEXTURE_CELL_PIXELS = 8
# Purpose: Keep RGB arithmetic within the encoded channel range.
# Type: int. Units: channel levels. Scope: generated synthetic pixels.
# Consumers: make_pattern_body. Rationale: 256 is the exclusive upper bound for 8-bit color.
RGB_LEVEL_COUNT = 256
# Purpose: Bound deterministic pixel noise added to spatial gradients.
# Type: int. Units: channel levels. Scope: generated synthetic pixels.
# Consumers: make_pattern_body. Rationale: 17 levels add detail without hiding the gradient.
PIXEL_NOISE_LEVELS = 17
# Purpose: Select the nearest-rank percentile reported by the driver.
# Type: float. Units: fraction. Scope: p95 callback and feedback metrics.
# Consumers: percentile_ms. Rationale: 0.95 is the agreed tail percentile.
P95_FRACTION = 0.95
# Purpose: Use a consistent native viewport for independent scenarios.
# Type: tuple[int, int]. Units: Tk logical pixels. Scope: root window.
# Consumers: create_root. Rationale: 900 by 680 exposes multiple real UI rows.
# Purpose: Force a measurable client resize while retaining the normal root viewport.
# Type: int. Units: logical pixels. Scope: lightbox actions.
# Consumers: root Configure workload. Rationale: 24 pixels triggers responsive layout.
RESIZE_DELTA_PIXELS = 24
VIEWPORT = (900, 680)
# Purpose: Bound the child mainloop and leave time for teardown.
# Type: int. Units: milliseconds. Scope: one independent process.
# Consumers: watchdog callback. Rationale: Sixty seconds bounds deterministic priming,
# eight measured seconds and owned-worker shutdown within the 90-second process budget.
WATCHDOG_MS = 60000
# Purpose: Require enough scheduled actions to distinguish a live workload from a hang.
# Type: int. Units: actions. Scope: one measured scenario.
# Consumers: tick and report validation. Rationale: twenty fixed actions make the
# operation schedule identical across variants; eight seconds allows their Tk work.
MIN_ACTIONS = 20
MEASURED_ACTION_COUNT = MIN_ACTIONS
# Purpose: Give stale-result fences a deterministic rapid-switch burst.
# Type: int. Units: selections. Scope: one lightbox action.
# Consumers: lightbox_action. Rationale: Two selections overlap detail completion.
RAPID_SWITCHES = 2
# Purpose: Warm the fixed detail working set and restore the initial selection.
# Type: tuple[int, ...]. Units: photo indices. Scope: one warm lightbox process.
# Consumers: prime. Rationale: 0, 1, 0 admits both rapid-switch photos through
# the real bounded cache and ends on the same initial photo as a cold process.
LIGHTBOX_PRIMING_INDICES = (0, 1, 0)
# Purpose: Identify synthetic media within one isolated session.
# Type: int. Units: gallery/image identifiers. Scope: gallery fixture.
# Consumers: build_gallery. Rationale: Stable positive IDs make response routing explicit.
GALLERY_ID = 44001
# Purpose: Keep parent and selected album identities distinct in fixture pages.
# Type: int. Units: gallery ID. Scope: synthetic metadata.
# Consumers: build_gallery. Rationale: 12001 cannot be confused with album44001.
PARENT_ID = 12001
# Purpose: Make the ordered synthetic photo IDs stable across independent runs.
# Type: int. Units: first photo ID. Scope: the 500-photo dataset.
# Consumers: build_gallery. Rationale: 30100 starts a positive deterministic range.
FIRST_IMAGE_ID = 30100

# Bound warm priming without starting a measured phase over incomplete caches.
# Type: int. Units: milliseconds. Scope: one warm process.
# Consumers: prime and begin_measurement. Rationale: 15 seconds admits bounded
# native page/cache preparation while preserving the 60-second watchdog.
WARMUP_LIMIT_MS = 15000
# Pace readiness observations without blocking Tk.
# Type: int. Units: milliseconds. Scope: warm cache readiness.
# Consumers: prime. Rationale: 50 ms yields to source workers and input.
PRIMING_POLL_MS = 50
# Change a small incremental set during steady import progress.
# Type: int. Units: rows per action. Scope: the import replay.
# Consumers: action. Rationale: five rows represent ordinary progress.
IMPORT_PROGRESS_ROWS = 5
# Exercise periodic burst reconciliation as well as steady progress.
# Type: int. Units: rows per burst. Scope: the import replay.
# Consumers: action. Rationale: 128 rows stress batching within the 1000-row table.
IMPORT_BURST_ROWS = 128
# Space the defined import burst deterministically.
# Type: int. Units: actions. Scope: the import replay.
# Consumers: action. Rationale: every fourth action mixes burst and steady updates.
IMPORT_BURST_CADENCE = 4
# Identify the second synthetic album without changing server identity semantics.
# Type: int. Units: gallery ID. Scope: one isolated session.
# Consumers: gallery switching. Rationale: 44002 differs from the first album.
SECOND_GALLERY_ID = GALLERY_ID + 1
# Bound worker joins outside the measured phase.
# Type: float. Units: seconds. Scope: one retired fixture worker.
# Consumers: teardown. Rationale: one second permits owned cancellation to finish.
WORKER_SHUTDOWN_SECONDS = 1.0
# Define the finite textured precursor for synthetic 4K JPEG data.
# Type: tuple[int, int]. Units: pixels. Scope: one fixture image.
# Consumers: make_hires_body. Rationale: 320x180 provides detailed deterministic
# texture without constructing eight million Python pixel tuples.
HIRES_TEXTURE_SIZE = (320, 180)
# Draw a visible structural grid over the 4K source fixture.
# Type: int. Units: pixels. Scope: one synthetic detail.
# Consumers: make_hires_body. Rationale: 128px spacing remains visible after resize.
HIRES_GRID_STEP_PIXELS = 128
# Fix encoded detail content for repeated decoder comparisons.
# Type: int. Units: JPEG quality points. Scope: the synthetic detail set.
# Consumers: make_hires_body. Rationale: quality82 balances detail and bounded bytes.
HIRES_JPEG_QUALITY = 82
# Keep a stable measured-operation schema, including observed zero counts.
# Type: tuple[str, ...]. Units: operation identifiers. Scope: enabled Recorder.
# Consumers: Recorder.__init__. Rationale: identical keys support like-for-like
# baseline/candidate summaries even when an optimization removes an operation.
OPERATION_COUNT_KEYS = (
    "widget_create", "widget_destroy", "treeview_insert", "treeview_item_update",
    "treeview_delete", "treeview_move", "canvas_create", "canvas_itemconfigure",
    "canvas_coords", "canvas_delete", "tk_photoimage_create",
    "pillow_photoimage", "pillow_resize",
)

# Define repeatable burst and ordinary producer traffic.
# Type: int. Units: events per sample / samples per burst. Scope: dashboard.
# Consumers: produce_worker_events. Rationale: four ordinary events plus32-event
# bursts every fourth250ms sample exercise cooperative queue pressure.
DASHBOARD_STEADY_EVENTS = 4
DASHBOARD_BURST_EVENTS = 32
DASHBOARD_BURST_CADENCE = 4
# Exercise column-changing and stable native grid sizes.
# Type: tuple[int, int] and int. Units: logical pixels / actions.
# Scope: gallery replay. Consumers: action.
# Rationale: widths900/640 every fourth action exercise responsive geometry.
# Start away from the initial 900-pixel root so every requested resize changes geometry.
GALLERY_RESIZE_WIDTHS = (640, 900)
GALLERY_RESIZE_CADENCE = 4
# Convert perf-counter nanoseconds without changing physical units.
# Type: int. Units: nanoseconds per millisecond. Scope: all latency summaries.
# Consumers: metric aggregation. Rationale: exact SI conversion is one million.
NS_PER_MS = 1000000
# Define the requested within-process latency distribution summaries.
# Type: dict[str, float]. Units: percentile fractions. Scope: measured events.
# Consumers: metric aggregation. Rationale: p50/p95/p99 preserve typical and tail behavior.
LATENCY_FRACTIONS = {"p50": 0.50, "p95": 0.95, "p99": 0.99}
# Time expensive image operations without sampling every table mutation.
# Type: tuple[str, str]. Units: operation names. Scope: enabled Recorder.
# Consumers: _wrap_operation. Rationale: separate raster preparation and Tk transfer.
IMAGE_TIMED_OPERATIONS = ("pillow_photoimage", "pillow_resize")

# Preserve Tk's documented Button1 binding-state bit in synthetic drag input.
# Type: int. Units: Tk state bitmask. Scope: the pan gesture.
# Consumers: lightbox action. Rationale: 0x0100 is Tk Button1Mask, so the
# existing <B1-Motion> production binding receives the event.
TK_BUTTON1_MASK = 0x0100

P = ParamSpec("P")
R = TypeVar("R")
Q = ParamSpec("Q")
S = TypeVar("S")

RawEvent: TypeAlias = dict[str, str | int]
AfterMethod: TypeAlias = Callable[
    Concatenate[
        tk.Misc, int | str, Callable[P, R] | None, P,
    ],
    str | None,
]
JsonValue: TypeAlias = str | int | float | bool | None | list["JsonValue"] | dict[str, "JsonValue"]

class FixtureBundleMember(TypedDict):
    """Describe one allowlisted fixture archive member."""

    name: str
    size: int
    sha256: str


class FixtureBundleManifest(TypedDict):
    """Describe deterministic fixture identities bound by the archive digest."""

    schema_version: int
    seed: int
    photo_count: int
    common_sha256: str
    pillow_version: str
    photos: list[FixtureBundleMember]
    hires: list[FixtureBundleMember]



class WatcherHost(Protocol):
    """Describe concrete fields bound by the isolated Watcher fixture."""

    root: tk.Misc
    events: queue.Queue[tuple[str, str]]
    import_item_tree: ttk.Treeview
    activity_item_tree: ttk.Treeview
    job_history_tree: ttk.Treeview
    activity_drawer: ttk.Treeview
    activity_events: list["ActivityEventContract"]
    activity_filter_var: tk.StringVar
    current_job: "ImportJobContract | None"
    _activity_render_after_id: str | None
    _activity_drawer_flush_count: int
    notebook: ttk.Notebook
    exiting: bool
    _event_drain_total_count: int
    refresh_import_item_tree: Callable[["ImportJobContract | None"], None]
    refresh_job_history: Callable[[], None]
    refresh_activity_drawer: Callable[[], None]
    select_history_job: Callable[[tk.Event | None], None]
    update_monitor_state: Callable[[str, str], None]
    _schedule_event_drain: Callable[[int], None]
    _cancel_event_ui_callbacks: Callable[[], None]


class PhotoDescriptor(Protocol):
    """Expose synthetic photo identity consumed by grid actions."""

    image_id: int


class PhotoHandle(Protocol):
    """Expose retained Tk image identity without changing ownership."""

    def width(self) -> int:
        """Return retained image width in Tk pixels.

        Returns:
            int: Current width reported by the original Tk image.
        """


class LightboxControl(Protocol):
    """Describe lightbox controls used by the real interaction workload."""

    _photo: PhotoHandle | None
    _canvas: tk.Canvas
    _zoom: float
    _fullscreen: bool
    _buttons: list[ttk.Button]

    def _change_zoom(self, factor: float) -> None:
        """Apply one production zoom action.

        Args:
            factor (float): Requested multiplicative zoom factor.

        Returns:
            None: The lightbox zoom state is updated.
        """

    def _pan_start(self, event: tk.Event) -> None:
        """Start production pan state from canvas coordinates.

        Args:
            event (tk.Event): Tk pointer event carrying the initial canvas position.

        Returns:
            None: Pan origin and drag state are recorded.
        """

    def _pan_motion(self, event: tk.Event) -> None:
        """Apply production pan movement.

        Args:
            event (tk.Event): Tk pointer event carrying the current canvas position.

        Returns:
            None: Lightbox image position is updated.
        """

    def _toggle_fullscreen(self) -> None:
        """Toggle production fullscreen state.

        Returns:
            None: The lightbox window state is toggled.
        """


class ImportItemContract(Protocol):
    """Describe the concrete synthetic row fields consumed by Watcher views."""

    id: str
    path: str
    source_label: str
    size: int
    state: str
    attempt_count: int
    reason: str
    server_result: dict[str, int | str | bool | None]
    local_decode: str


class ImportJobContract(Protocol):
    """Describe the production import snapshot passed to Watcher callbacks."""

    id: str
    source_kind: str
    source_label: str
    target_gallery_label: str
    items: list[ImportItemContract]
    bytes_total: int
    bytes_sent: int
    started_at: float
    finished_at: float
    created_at: float

    def counts(self) -> dict[str, int]:
        """Return production import item counts grouped by status.

        Returns:
            dict[str, int]: Status names mapped to their current item counts.
        """


class ActivityEventContract(Protocol):
    """Describe one production activity row consumed by the drawer."""

    level: str
    message: str
    operation: str
    filename: str
    job_id: str
    timestamp: float


class QueueReadiness(Protocol):
    """Expose the source result queue readiness without interpreting its payloads."""

    empty: Callable[[], bool]


class GalleryIdentity(Protocol):
    """Expose the exact selected server gallery identity."""

    gallery_id: int


class NativeViewerContract(Protocol):
    """Describe real Photos controller state used by measured interactions."""

    grid_canvas: tk.Canvas
    _photos: tuple[PhotoDescriptor, ...]
    _tile_buttons: list[tk.Widget]
    _photo_images: list[PhotoHandle | None]
    _visible_thumbnail_indices: set[int]
    _worker: threading.Thread | None
    _lightbox: LightboxControl | None
    _closed: bool
    frame: ttk.Frame
    _next_after: int | None
    gallery: GalleryIdentity
    _events: QueueReadiness
    _pending_detail_index: int | None
    refresh_photos: Callable[[], None]
    _load_more: Callable[[], None]

    def close(self) -> None:
        """Cancel viewer-owned work and release Tk references.

        Returns:
            None: The viewer is closed and owned work is cancelled.
        """

    def _open_grid_photo(self, index: int) -> None:
        """Open the selected production detail callback.

        Args:
            index (int): Position of the selected grid photo.

        Returns:
            None: The production lightbox is opened for the selected photo.
        """

    def _on_grid_viewport(self, event: tk.Event | None) -> None:
        """Apply the production viewport callback.

        Args:
            event (tk.Event | None): Configure event or None for explicit refresh.

        Returns:
            None: Visible thumbnail work is reconciled with the viewport.
        """


class FixtureMediaInfo(TypedDict):
    """Identify bounded synthetic data and lazy hires retention."""

    dataset_sha256: str
    thumbnail_body_bytes: int
    hires_cache_peak: int
    hires_cache_limit: int


class GalleryFixtureState(TypedDict):
    """Retain page-refresh and lazy hires counters for one synthetic session."""

    catalogue_variant: int
    page_reads: int
    hires_cache_peak: int
    gallery_id: int


# Purpose: Sample independent Tk event-loop scheduling latency.
# Type: int. Units: milliseconds. Scope: enabled Recorder heartbeat.
# Consumers: Recorder.start_heartbeat and Recorder._schedule_heartbeat.
# Rationale: A 16 ms cadence approximates a 60 Hz responsiveness probe.
HEARTBEAT_INTERVAL_MS = 16
# Purpose: Compare heartbeat dispatch against its expected perf-counter deadline.
# Type: int. Units: nanoseconds. Scope: one heartbeat interval.
# Consumers: Recorder.start_heartbeat and Recorder._heartbeat_tick.
# Rationale: Convert the named 16 ms policy using the existing SI conversion.
HEARTBEAT_INTERVAL_NS = HEARTBEAT_INTERVAL_MS * NS_PER_MS


# Purpose: Bound setup-phase timing summaries to known event metrics.
# Type: frozenset[str]. Units: event metric names. Scope: one Recorder instance.
# Consumers: Recorder.add() during the explicit setup phase.
# Rationale: fixed keys prevent callback-specific or unbounded setup evidence.
SETUP_AGGREGATE_METRICS = frozenset({
    "callback",
    "image_publication",
    "image_resize_gui",
    "image_resize_worker",
})


class SetupMetricSummary(TypedDict):
    """Describe bounded aggregate setup timing for one fixed event metric."""

    count: int
    total_ns: int
    max_ns: int


class Recorder(Generic[P, R]):
    """Collect bounded raw interaction timings and fixed setup aggregates."""

    def __init__(self, enabled: bool) -> None:
        """Initialize optional scheduler instrumentation and phase state.

        Args:
            enabled (bool): Whether callbacks, interactions, and operations are sampled.

        Returns:
            None: The recorder is ready for one isolated process.
        """
        self.enabled = enabled
        self.phase = "warmup"
        self.events: list[RawEvent] = []
        self.truncated = 0
        self.setup_summaries: dict[str, SetupMetricSummary] = {}
        self._setup_lock = threading.Lock()
        self.operation_counts: dict[str, int] = dict.fromkeys(OPERATION_COUNT_KEYS, 0)
        self._original_after: AfterMethod[P, R] | None = None
        self._restore_callbacks: list[Callable[[], None]] = []
        self._tk_owner_thread = threading.get_ident()
        self._heartbeat_root: tk.Misc | None = None
        self._heartbeat_after_id: str | None = None
        self._heartbeat_active = False
        self._heartbeat_deadline_ns: int | None = None

    def install(self) -> None:
        """Install callback and UI-operation wrappers when enabled.

        Returns:
            None: Original methods are retained for exact restoration.
        """
        if not self.enabled or self._original_after is not None:
            return
        self._original_after = cast(AfterMethod[P, R], tk.Misc.after)

        def measured_after(
            widget: tk.Misc,
            ms: int | str,
            func: Callable[P, R] | None = None,
            *args: P.args,
        ) -> str | None:
            """Forward Tk after arguments unchanged and measure callback dispatch.

            Args:
                widget (tk.Misc): Tk owner scheduling the callback.
                ms (int | str): Delay or Tk idle marker.
                func (Callable[P, R] | None): Original callback, if present.
                args (P.args): Positional values forwarded to the callback.

            Returns:
                str | None: Exact callback identifier returned by Tk.
            """
            original = self._original_after
            assert original is not None
            if func is None:
                return original(widget, ms, func, *args)
            scheduled_ns = time.perf_counter_ns()
            phase = self.phase
            try:
                delay_ns = max(0, int(ms)) * 1_000_000
            except (TypeError, ValueError):
                delay_ns = 0

            def observe(*callback_args: P.args) -> R:
                """Call the original callback with Tk's exact positional values.

                Args:
                    callback_args (P.args): Positional arguments delivered by Tk.

                Returns:
                    R: Original callback result.

                Raises:
                    BaseException: The original callback failure is propagated.
                """
                started_ns = time.perf_counter_ns()
                dispatch_delay_ns = max(
                    0,
                    started_ns - scheduled_ns - delay_ns,
                )
                try:
                    return func(*callback_args)
                finally:
                    elapsed_ns = time.perf_counter_ns() - started_ns
                    completed_phase = phase if self.phase == phase else "warmup"
                    callback_name = getattr(
                        func,
                        "__qualname__",
                        type(func).__name__,
                    )
                    self.add(
                        "callback",
                        elapsed_ns,
                        completed_phase,
                        callback_name=callback_name,
                        dispatch_delay_ns=dispatch_delay_ns,
                    )

            return original(widget, ms, observe, *args)

        tk.Misc.after = measured_after
        self._wrap_operation(
            "widget_create",
            tk.Widget.__init__,
            lambda wrapped: setattr(tk.Widget, "__init__", wrapped),
        )
        self._wrap_operation(
            "widget_destroy",
            tk.Misc.destroy,
            lambda wrapped: setattr(tk.Misc, "destroy", wrapped),
        )
        self._wrap_operation(
            "treeview_insert",
            ttk.Treeview.insert,
            lambda wrapped: setattr(ttk.Treeview, "insert", wrapped),
        )
        self._wrap_treeview_item_updates()
        self._wrap_operation(
            "treeview_delete",
            ttk.Treeview.delete,
            lambda wrapped: setattr(ttk.Treeview, "delete", wrapped),
        )
        self._wrap_operation(
            "treeview_move",
            ttk.Treeview.move,
            lambda wrapped: setattr(ttk.Treeview, "move", wrapped),
        )
        self._wrap_operation(
            "canvas_create",
            tk.Canvas._create,
            lambda wrapped: setattr(tk.Canvas, "_create", wrapped),
        )
        self._wrap_operation(
            "canvas_itemconfigure",
            tk.Canvas.itemconfigure,
            lambda wrapped: setattr(tk.Canvas, "itemconfigure", wrapped),
        )
        self._wrap_operation(
            "canvas_coords",
            tk.Canvas.coords,
            lambda wrapped: setattr(tk.Canvas, "coords", wrapped),
        )
        self._wrap_operation(
            "canvas_delete",
            tk.Canvas.delete,
            lambda wrapped: setattr(tk.Canvas, "delete", wrapped),
        )
        self._wrap_operation(
            "tk_photoimage_create",
            tk.PhotoImage.__init__,
            lambda wrapped: setattr(tk.PhotoImage, "__init__", wrapped),
        )
        self._wrap_operation(
            "pillow_photoimage", ImageTk.PhotoImage.__init__,
            lambda wrapped: setattr(ImageTk.PhotoImage, "__init__", wrapped),
        )
        self._wrap_operation(
            "pillow_resize", Image.Image.resize,
            lambda wrapped: setattr(Image.Image, "resize", wrapped),
        )

    def _wrap_operation(
        self,
        operation: str,
        original: Callable[Q, S],
        replace_method: Callable[[Callable[Q, S]], None],
    ) -> None:
        """Count measured calls while preserving the wrapped method signature.

        Args:
            operation (str): Stable operation-count key.
            original (Callable[Q, S]): Original Tkinter method.
            replace_method (Callable[[Callable[Q, S]], None]): Method installer.

        Returns:
            None: The generic wrapper is installed and its restore action retained.
        """
        def counted(*args: Q.args, **kwargs: Q.kwargs) -> S:
            """Forward one Tkinter call and count it only in measured phase.

            Args:
                args (Q.args): Original positional method arguments.
                kwargs (Q.kwargs): Original keyword method arguments.

            Returns:
                S: Exact value returned by the original method.
            """
            phase = self.phase
            if operation == "pillow_photoimage" and threading.get_ident() != self._tk_owner_thread:
                raise RuntimeError("A Tk image was created outside its GUI owner thread.")
            started_ns = time.perf_counter_ns() if operation in IMAGE_TIMED_OPERATIONS else None
            self._count_operation(operation)
            result = original(*args, **kwargs)
            if started_ns is not None:
                metric = ("image_publication" if operation == "pillow_photoimage"
                          else "image_resize_gui" if threading.get_ident() == self._tk_owner_thread
                          else "image_resize_worker")
                self.add(metric, time.perf_counter_ns() - started_ns, phase)
            return result

        replace_method(counted)
        self._restore_callbacks.append(lambda: replace_method(original))

    def _wrap_treeview_item_updates(self) -> None:
        """Count Treeview item mutations without counting read-only queries.

        Returns:
            None: The original item method is restored with other wrappers.
        """
        original = ttk.Treeview.item

        def counted(*args: Q.args, **kwargs: Q.kwargs) -> S:
            """Forward the item call and count item-option updates.

            Args:
                args (Q.args): Original positional item arguments.
                kwargs (Q.kwargs): Item options supplied as keyword arguments.

            Returns:
                S: Exact value returned by the original Treeview method.
            """
            positional_options = len(args) > 2 and isinstance(args[2], dict)
            if kwargs or positional_options:
                self._count_operation("treeview_item_update")
            return original(*args, **kwargs)

        replace_method = lambda wrapped: setattr(ttk.Treeview, "item", wrapped)
        replace_method(counted)
        self._restore_callbacks.append(lambda: replace_method(original))

    def _count_operation(self, operation: str) -> None:
        """Increment one operation counter during setup or measurement.

        Args:
            operation (str): Stable operation-count key.

        Returns:
            None: Explicit setup and measured phases advance the count.
        """
        if not self.enabled or self.phase not in ("setup", "measured"):
            return
        self.operation_counts[operation] = self.operation_counts.get(operation, 0) + 1

    def restore(self) -> None:
        """Stop the heartbeat and restore process-global Tk methods.

        Returns:
            None: The independent probe stops and all original methods return.

        Raises:
            RuntimeError: Restoration is attempted off the Tk owner thread.
        """
        self.stop_heartbeat()
        if self._original_after is not None:
            tk.Misc.after = self._original_after
            self._original_after = None
        for restore in reversed(self._restore_callbacks):
            restore()
        self._restore_callbacks.clear()

    def start_heartbeat(self, root: tk.Misc) -> None:
        """Start independent measured-phase Tk dispatch observations.

        Args:
            root (tk.Misc): Mapped Tk owner whose mainloop dispatches the probe.

        Returns:
            None: One original after callback is scheduled at the probe cadence.

        Raises:
            RuntimeError: The call is not on the Tk owner thread or install is absent.
        """
        if not self.enabled:
            return
        if threading.get_ident() != self._tk_owner_thread:
            raise RuntimeError("Tk heartbeat must start on its owner thread.")
        if self._original_after is None:
            raise RuntimeError("Install Recorder before starting its heartbeat.")
        if self._heartbeat_active:
            return
        self._heartbeat_root = root
        self._heartbeat_active = True
        self._heartbeat_deadline_ns = (
            time.perf_counter_ns() + HEARTBEAT_INTERVAL_NS
        )
        self._schedule_heartbeat()

    def stop_heartbeat(self) -> None:
        """Cancel the owned heartbeat timer without touching unrelated callbacks.

        Returns:
            None: The probe is inactive and its pending identifier is cancelled.
        """
        root = self._heartbeat_root
        after_id = self._heartbeat_after_id
        if (root is not None and after_id is not None
                and threading.get_ident() != self._tk_owner_thread):
            raise RuntimeError("Tk heartbeat must stop on its owner thread.")
        self._heartbeat_active = False
        self._heartbeat_root = None
        self._heartbeat_after_id = None
        self._heartbeat_deadline_ns = None
        if root is not None and after_id is not None:
            try:
                root.after_cancel(after_id)
            except tk.TclError:
                pass

    def _schedule_heartbeat(self) -> None:
        """Schedule one heartbeat through the captured original Tk.after method.

        Returns:
            None: No callback-wrapper sample is emitted for the probe itself.
        """
        root = self._heartbeat_root
        original = self._original_after
        if not self._heartbeat_active or root is None or original is None:
            return
        self._heartbeat_after_id = original(
            root, HEARTBEAT_INTERVAL_MS, self._heartbeat_tick,
        )

    def _heartbeat_tick(self) -> None:
        """Record one independent heartbeat's deadline lateness and reschedule.

        Returns:
            None: The next deadline starts from this dispatch, so prior lateness
                cannot accumulate into later observations.
        """
        root = self._heartbeat_root
        if not self._heartbeat_active or root is None:
            return
        if threading.get_ident() != self._tk_owner_thread:
            raise RuntimeError("Tk heartbeat callback escaped its owner thread.")
        self._heartbeat_after_id = None
        dispatched_ns = time.perf_counter_ns()
        deadline_ns = self._heartbeat_deadline_ns
        if deadline_ns is not None:
            self.add(
                "event_loop_drift",
                max(0, dispatched_ns - deadline_ns),
                "measured",
            )
        self._heartbeat_deadline_ns = (
            time.perf_counter_ns() + HEARTBEAT_INTERVAL_NS
        )
        self._schedule_heartbeat()

    def add(
        self,
        metric: str,
        elapsed_ns: int,
        phase: str | None = None,
        callback_name: str | None = None,
        dispatch_delay_ns: int | None = None,
    ) -> None:
        """Append one measured observation or count genuine measured overflow.

        Args:
            metric (str): Fixed aggregate metric identifier.
            elapsed_ns (int): Nonnegative duration in nanoseconds.
            phase (str | None): Explicit phase or the Recorder's current phase.
            callback_name (str | None): Optional Tk callback qualified name.
            dispatch_delay_ns (int | None): Optional nonnegative scheduling
                lateness in nanoseconds attached to the callback observation.

        Returns:
            None: Setup samples aggregate under fixed metric keys. Warmup samples
                are discarded; measured samples retain details until the raw cap.
        """
        event_phase = phase or self.phase
        if not self.enabled:
            return
        elapsed = max(0, elapsed_ns)
        if event_phase == "setup":
            if metric not in SETUP_AGGREGATE_METRICS:
                return
            # GUI callbacks and image workers can publish aggregates together.
            # Preserve exact counts without depending on interpreter scheduling.
            with self._setup_lock:
                summary = self.setup_summaries.setdefault(
                    metric,
                    {"count": 0, "total_ns": 0, "max_ns": 0},
                )
                summary["count"] += 1
                summary["total_ns"] += elapsed
                summary["max_ns"] = max(summary["max_ns"], elapsed)
            return
        if event_phase != "measured":
            return
        if len(self.events) >= RAW_EVENT_LIMIT:
            self.truncated += 1
            return
        event: RawEvent = {
            "metric": metric,
            "ns": elapsed,
            "phase": event_phase,
        }
        if callback_name is not None:
            event["callback_name"] = callback_name
        if dispatch_delay_ns is not None:
            event["dispatch_delay_ns"] = max(0, dispatch_delay_ns)
        self.events.append(event)

    def interaction(self, root: tk.Misc, widget: tk.Widget) -> None:
        """Queue a real Tk button press and measure action-to-idle feedback.

        Args:
            root (tk.Misc): Mapped root that owns the event loop.
            widget (tk.Widget): Theme button whose production command is bound.

        Returns:
            None: Tk processes the generated pointer events before idle feedback.
        """
        phase = self.phase
        started_ns = time.perf_counter_ns()
        width = max(1, widget.winfo_width())
        height = max(1, widget.winfo_height())
        widget.event_generate(
            "<ButtonPress-1>", x=width // 2, y=height // 2, when="tail",
        )
        widget.event_generate(
            "<ButtonRelease-1>", x=width // 2, y=height // 2, when="tail",
        )
        if self.enabled:
            root.after_idle(lambda: self.add(
                "interaction_feedback",
                time.perf_counter_ns() - started_ns,
                phase,
            ))

def percentile_ms(samples: list[int], fraction: float = P95_FRACTION) -> float | None:
    """Return the requested nearest-rank percentile in milliseconds.

    Args:
        samples (list[int]): Raw nanosecond observations for one metric.
        fraction (float): Requested nearest-rank probability from zero to one.

    Returns:
        float | None: Requested percentile, or None when no sample exists.
    """
    if not samples:
        return None
    ordered = sorted(samples)
    index = max(0, min(len(ordered) - 1, math.ceil(len(ordered) * fraction) - 1))
    return round(ordered[index] / 1_000_000, 6)


def load_watcher(source_root: Path) -> ModuleType:
    """Load the real Watcher source module from the supplied application checkout.

    Args:
        source_root (Path): Application root provided by the benchmark driver.

    Returns:
        ModuleType: Namespace exporting WatcherApp, ImportItem, ImportJob and
            ActivityEvent classes without starting the application entrypoint.
    """
    winapp = str(source_root / "winapp")
    if winapp not in sys.path:
        sys.path.insert(0, winapp)
    path = source_root / "winapp" / "gallery_watch_upload.pyw"
    loader = importlib.machinery.SourceFileLoader("issue_361_watcher_source", str(path))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        raise RuntimeError("Could not create Watcher module specification.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[loader.name] = module
    loader.exec_module(module)
    return module


def make_import_job(module: ModuleType, private_root: Path) -> ImportJobContract:
    """Create one real production ImportJob with deterministic source rows.

    Args:
        module (ModuleType): Watcher module exporting ImportItem and ImportJob.
        private_root (Path): Private directory for synthetic source labels.

    Returns:
        ImportJobContract: ImportJob with exactly 1,000 synthetic rows and no file reads.
    """
    items = [
        module.ImportItem(
            id=f"item-{index:04d}",
            path=str(private_root / f"source-{index:04d}.png"),
            source_label=f"synthetic/source-{index:04d}.png",
            size=1024 + index,
            state="discovered" if index % 2 == 0 else "confirmed",
            attempt_count=index % 3,
            reason="Synthetic fixture row" if index % 7 == 0 else "",
            local_decode="local",
        )
        for index in range(IMPORT_ROWS)
    ]
    return module.ImportJob(
        id="synthetic-job", source_kind="folder",
        source_label="Synthetic benchmark folder",
        target_gallery_label="Synthetic gallery", items=items,
        bytes_total=sum(item.size for item in items),
        created_at=1_800_000_000.0,
    )


def attach_method(app: WatcherHost, module: ModuleType, name: str) -> None:
    """Bind one production Watcher method to a constructor-bypassed UI host.

    Args:
        app (WatcherHost): Fixture host with the method's complete Tk fields.
        module (ModuleType): Loaded Watcher source module.
        name (str): Exact production method name.

    Returns:
        None: The selected method is bound to the fixture owner.
    """
    method = getattr(module.WatcherApp, name)
    setattr(app, name, method.__get__(app, module.WatcherApp))


def build_import_widgets(
    root: tk.Tk, module: ModuleType, private_root: Path,
) -> tuple[WatcherHost, ImportJobContract]:
    """Mount production import rows in real tabs and bind selection callbacks.

    Args:
        root (tk.Tk): Native root owned by the scenario process.
        module (ModuleType): Loaded Watcher production module.
        private_root (Path): Private temporary directory for synthetic source labels.

    Returns:
        tuple[WatcherHost, ImportJobContract]: Complete fixture host and real job snapshot.
    """

    app = module.WatcherApp.__new__(module.WatcherApp)
    app.root = root
    app.exiting = False
    app.control_frame = ttk.Frame(root)
    app.control_frame.pack(fill="x")
    notebook = ttk.Notebook(root)
    notebook.pack(fill="both", expand=True)
    import_tab = ttk.Frame(notebook)
    history_tab = ttk.Frame(notebook)
    activity_tab = ttk.Frame(notebook)
    notebook.add(import_tab, text="Import")
    notebook.add(history_tab, text="History")
    notebook.add(activity_tab, text="Activity")
    app.import_item_tree = ttk.Treeview(
        import_tab, columns=("name", "size", "decode", "state", "reason"),
    )
    app.activity_item_tree = ttk.Treeview(
        import_tab, columns=("name", "state", "attempts", "detail"),
    )
    app.import_item_tree.pack(fill="both", expand=True)
    app.activity_item_tree.pack(fill="both", expand=True)
    app.job_history_tree = ttk.Treeview(
        history_tab, columns=("created", "source", "target", "status"),
    )
    app.job_history_tree.pack(fill="both", expand=True)
    app.activity_drawer = ttk.Treeview(
        activity_tab, columns=("time", "level", "file", "operation", "message"),
    )
    app.activity_drawer.pack(fill="both", expand=True)
    app._activity_render_after_id = None
    app._activity_drawer_flush_count = 0
    app.activity_filter_var = tk.StringVar(root, value="all")
    job = make_import_job(module, private_root)
    app.current_job = job
    app.activity_events = [
        module.ActivityEvent(
            "warning" if index % 5 == 0 else "info",
            f"Synthetic activity event {index}", operation="import",
            filename=f"source-{index:04d}.png",
            timestamp=1_800_000_000.0 + index,
        )
        for index in range(SOURCE_ACTIVITY_ROWS)
    ]
    jobs = [job] + [
        module.ImportJob(
            id=f"history-{index:04d}", source_kind="folder",
            source_label=f"Synthetic history job {index}",
            target_gallery_label="Synthetic gallery",
        )
        for index in range(1, SOURCE_HISTORY_ROWS)
    ]
    jobs_by_id = {entry.id: entry for entry in jobs}
    app.job_store = SimpleNamespace(
        list_jobs=lambda: jobs, get_job=lambda job_id: jobs_by_id.get(job_id),
    )
    app.activity_job_summary_var = tk.StringVar(root, value="")
    app.retry_failed_button = Button(
        app.control_frame, text="Retry", command=lambda: None,
        style="Photos.Icon.TButton",
    )
    app.retry_failed_button.pack(side="right")
    for name in (
        "refresh_import_item_tree", "refresh_job_history",
        "refresh_activity_drawer", "select_history_job",
    ):
        attach_method(app, module, name)
    app.job_history_tree.bind(
        "<<TreeviewSelect>>", app.select_history_job, add="+",
    )
    app.notebook = notebook
    return app, job

def setup_dashboard(root: tk.Tk, module: ModuleType) -> WatcherHost:
    """Initialize real Watcher queue, log, activity drawer and monitor callbacks.

    Args:
        root (tk.Tk): Native root owned by the scenario process.
        module (ModuleType): Loaded Watcher production module.

    Returns:
        WatcherHost: Constructor-bypassed Watcher host with complete pump state.
    """

    app = module.WatcherApp.__new__(module.WatcherApp)
    app.root = root
    app.exiting = False
    app.events = queue.Queue()
    app.manual_worker = None
    app.current_job = None
    app.api_key_var = tk.StringVar(root, value="")
    app.latest_activity_var = tk.StringVar(root, value="")
    app.latest_activity_label = None
    app.activity_events = []
    app.config = SimpleNamespace(activity_history_limit=500)
    app.activity_writer = SimpleNamespace(submit=lambda _event: True)
    app.notebook = ttk.Notebook(root)
    log_tab = ttk.Frame(app.notebook)
    activity_tab = ttk.Frame(app.notebook)
    app.notebook.add(log_tab, text="Watch")
    app.notebook.add(activity_tab, text="Activity")
    app.notebook.pack(fill="both", expand=True)
    app.log_text = tk.Text(log_tab, height=8, state="disabled")
    app.log_text.pack(fill="both", expand=True)
    for tag in ("timestamp", "system", "success", "warning", "error"):
        app.log_text.tag_configure(tag)
    app.status_var = tk.StringVar(root, value="Idle")
    app.watcher_activity_var = tk.StringVar(root, value="Watch: idle")
    app.watch_last_scan_var = tk.StringVar(root, value="Last scan: idle")
    app.monitor_state_var = tk.StringVar(root, value="")
    app.monitor_detail_var = tk.StringVar(root, value="")
    for variable in (
        app.status_var, app.watcher_activity_var, app.watch_last_scan_var,
        app.monitor_state_var, app.monitor_detail_var,
    ):
        ttk.Label(root, textvariable=variable).pack(anchor="w")
    app.monitor_light = tk.Canvas(root, width=18, height=18)
    app.monitor_light.pack()
    app.activity_drawer = ttk.Treeview(
        activity_tab, columns=("time", "level", "file", "operation", "message"),
    )
    app.activity_drawer.pack(fill="both", expand=True)
    app.activity_filter_var = tk.StringVar(root, value="all")
    app._activity_render_after_id = None
    app._activity_drawer_flush_count = 0
    app._drain_after_id = None
    app._drain_running = False
    app._event_drain_last_count = 0
    app._event_drain_total_count = 0
    app._event_drain_yield_count = 0
    app._event_drain_last_ms = 0.0
    app._event_drain_max_ms = 0.0
    app._drain_event_batch = module.WatcherApp._drain_event_batch.__get__(
        app, module.WatcherApp,
    )
    for name in (
        "classify_log_level", "write_log", "record_activity",
        "_schedule_activity_drawer_refresh", "_flush_activity_drawer_refresh",
        "refresh_activity_drawer", "update_monitor_state", "drain_events",
        "_schedule_event_drain", "_run_scheduled_event_drain",
        "_cancel_event_ui_callbacks",
    ):
        attach_method(app, module, name)
    app.refresh_contextual_watcher_copy = lambda: None
    app.refresh_activity_monitor_state = lambda: None
    app.refresh_revoke_button_state = lambda: None
    app.monitor_button = Button(
        root, text="Monitor", command=lambda: None, style="Photos.Icon.TButton",
    )
    app.monitor_button.pack()
    return app

def make_pattern_body(size: tuple[int, int], seed: int) -> bytes:
    """Encode one deterministic gradient, checker and noise image.

    Args:
        size (tuple[int, int]): Requested image width and height in pixels.
        seed (int): Stable per-photo PRNG seed.

    Returns:
        bytes: PNG body encoded in memory without disk or network access.
    """
    width, height = size
    generator = random.Random(seed)
    pixels: list[tuple[int, int, int]] = []
    # The small RGB coefficients below define fixture texture rather than limits.
    for y in range(height):
        for x in range(width):
            checker = ((x // TEXTURE_CELL_PIXELS) + (y // TEXTURE_CELL_PIXELS)) % 2
            noise = generator.randrange(PIXEL_NOISE_LEVELS)
            # Channel coefficients build deterministic spatial texture, not timing policy.
            red = (seed + x * 3 + y + checker * 31 + noise) % RGB_LEVEL_COUNT
            green = (seed // 3 + x + y * 2 + checker * 47 + noise) % RGB_LEVEL_COUNT
            blue = (seed // 7 + x * 2 + y * 3 + checker * 19 + noise) % RGB_LEVEL_COUNT
            pixels.append((red, green, blue))
    image = Image.new("RGB", size)
    image.putdata(pixels)
    stream = BytesIO()
    image.save(stream, format="PNG")
    image.close()
    return stream.getvalue()


def make_hires_body(seed: int) -> bytes:
    """Create one deterministic 4K JPEG from a textured low-resolution seed.

    Args:
        seed (int): Stable per-photo seed.

    Returns:
        bytes: One 3840 by 2160 JPEG generated on demand and never bulk-retained.
    """
    source_size = HIRES_TEXTURE_SIZE
    source = Image.new("RGB", source_size)
    generator = random.Random(seed)
    source.putdata([
        (
            (seed + x * 3 + y + generator.randrange(PIXEL_NOISE_LEVELS)) % RGB_LEVEL_COUNT,
            (seed // 3 + x + y * 2 + generator.randrange(PIXEL_NOISE_LEVELS)) % RGB_LEVEL_COUNT,
            (seed // 7 + x * 2 + y * 3 + generator.randrange(PIXEL_NOISE_LEVELS)) % RGB_LEVEL_COUNT,
        )
        for y in range(source_size[1]) for x in range(source_size[0])
    ])
    image = source.resize(HIRES_SIZE, Image.Resampling.LANCZOS)
    source.close()
    draw = ImageDraw.Draw(image)
    for edge in range(0, HIRES_SIZE[0], HIRES_GRID_STEP_PIXELS):
        draw.line((edge, 0, edge, HIRES_SIZE[1] - 1), fill=(80, 120, 160), width=1)
    stream = BytesIO()
    image.save(stream, format="JPEG", quality=HIRES_JPEG_QUALITY)
    image.close()
    return stream.getvalue()


def make_photo_bodies(seed: int, photo_count: int = PHOTO_COUNT) -> tuple[bytes, ...]:
    """Encode deterministic textured synthetic thumbnails in memory.

    Args:
        seed (int): Explicit deterministic PRNG seed from the driver.
        photo_count (int): Positive whole-number count; defaults to the frozen
            Issue 361 workload size.

    Returns:
        tuple[bytes, ...]: Bounded textured PNG bodies across four dimensions.

    Raises:
        ValueError: photo_count is not a positive built-in integer.
    """
    if type(photo_count) is not int or photo_count < 1:
        raise ValueError("photo_count must be a positive integer.")
    return tuple(
        make_pattern_body(
            THUMBNAIL_SIZE_VARIANTS[index % len(THUMBNAIL_SIZE_VARIANTS)],
            seed + index,
        )
        for index in range(photo_count)
    )


def _fixture_bundle_json_object(
    pairs: list[tuple[str, JsonValue]],
) -> dict[str, JsonValue]:
    """Reject duplicate keys while decoding the fixture manifest.

    Args:
        pairs (list[tuple[str, JsonValue]]): JSON object key/value pairs.

    Returns:
        dict[str, JsonValue]: Unique-key JSON object.

    Raises:
        ValueError: If the object repeats a key.
    """
    result: dict[str, JsonValue] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Fixture manifest contains a duplicate JSON key.")
        result[key] = value
    return result


def _fixture_sha256(body: bytes) -> str:
    """Return the lowercase SHA-256 of one exact fixture member.

    Args:
        body (bytes): Exact image-body bytes.

    Returns:
        str: Lowercase hexadecimal SHA-256 digest.
    """
    return hashlib.sha256(body).hexdigest()


def _fixture_bundle_file_sha256(path: Path) -> str:
    """Hash an archive using bounded reads.

    Args:
        path (Path): Existing fixture archive.

    Returns:
        str: Lowercase hexadecimal SHA-256 digest.
    """
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(
            lambda: stream.read(FIXTURE_BUNDLE_HASH_BLOCK_BYTES),
            b"",
        ):
            digest.update(block)
    return digest.hexdigest()


def prepare_fixture_bundle(
    archive_path: Path,
    seed: int,
    photo_count: int,
    common_sha256: str,
) -> str:
    """Generate and persist one deterministic, source-bound scroll fixture bundle.

    Args:
        archive_path (Path): New path inside the driver's owned temporary folder.
        seed (int): Exact workload seed used for every generated fixture.
        photo_count (int): Largest scenario extent included in the bundle.
        common_sha256 (str): Exact SHA-256 of this shared workload module.

    Returns:
        str: SHA-256 digest of the deterministic ZIP archive.

    Raises:
        ValueError: Inputs exceed the fixed protocol identity or size bounds.
        OSError: The archive cannot be written or hashed.
    """
    if type(seed) is not int or seed < 0:
        raise ValueError("Fixture bundle seed must be a nonnegative integer.")
    if (type(photo_count) is not int or photo_count < 1
            or photo_count > FIXTURE_BUNDLE_MAX_PHOTOS):
        raise ValueError("Fixture bundle photo count is outside its fixed bound.")
    if (len(common_sha256) != 64
            or any(character not in "0123456789abcdef" for character in common_sha256)):
        raise ValueError("Fixture bundle common SHA-256 is invalid.")
    if archive_path.exists():
        raise ValueError("Fixture bundle path must not already exist.")

    photos = make_photo_bodies(seed, photo_count)
    hires = tuple(
        make_hires_body(seed + variant)
        for variant in range(HIRES_FIXTURE_VARIANTS)
    )
    photo_members: list[FixtureBundleMember] = []
    hires_members: list[FixtureBundleMember] = []
    for index, body in enumerate(photos):
        if not body or len(body) > FIXTURE_BUNDLE_MEMBER_MAX_BYTES:
            raise ValueError("Generated photo body exceeds the member bound.")
        photo_members.append({
            "name": f"photos/{index:05d}.png",
            "size": len(body),
            "sha256": _fixture_sha256(body),
        })
    for index, body in enumerate(hires):
        if not body or len(body) > FIXTURE_BUNDLE_MEMBER_MAX_BYTES:
            raise ValueError("Generated hires body exceeds the member bound.")
        hires_members.append({
            "name": f"hires/{index:02d}.jpg",
            "size": len(body),
            "sha256": _fixture_sha256(body),
        })

    manifest: FixtureBundleManifest = {
        "schema_version": FIXTURE_BUNDLE_SCHEMA_VERSION,
        "seed": seed,
        "photo_count": photo_count,
        "common_sha256": common_sha256,
        "pillow_version": PILLOW_VERSION,
        "photos": photo_members,
        "hires": hires_members,
    }
    manifest_bytes = json.dumps(
        manifest, sort_keys=True, separators=(",", ":"), allow_nan=False,
    ).encode("utf-8")
    if len(manifest_bytes) > FIXTURE_BUNDLE_MANIFEST_MAX_BYTES:
        raise ValueError("Fixture manifest exceeds its fixed size bound.")

    archive_path.parent.mkdir(parents=True, exist_ok=True)
    try:
        with archive_path.open("xb") as output_stream:
            with zipfile.ZipFile(
                output_stream,
                mode="w",
                compression=zipfile.ZIP_STORED,
                allowZip64=False,
            ) as archive:
                for index, body in enumerate(photos):
                    info = zipfile.ZipInfo(
                        f"photos/{index:05d}.png",
                        date_time=FIXTURE_BUNDLE_ZIP_TIMESTAMP,
                    )
                    info.compress_type = zipfile.ZIP_STORED
                    info.create_system = FIXTURE_BUNDLE_ZIP_CREATOR
                    info.external_attr = FIXTURE_BUNDLE_FILE_MODE << FIXTURE_BUNDLE_UNIX_ATTR_SHIFT
                    archive.writestr(info, body)
                for index, body in enumerate(hires):
                    info = zipfile.ZipInfo(
                        f"hires/{index:02d}.jpg",
                        date_time=FIXTURE_BUNDLE_ZIP_TIMESTAMP,
                    )
                    info.compress_type = zipfile.ZIP_STORED
                    info.create_system = FIXTURE_BUNDLE_ZIP_CREATOR
                    info.external_attr = FIXTURE_BUNDLE_FILE_MODE << FIXTURE_BUNDLE_UNIX_ATTR_SHIFT
                    archive.writestr(info, body)
                manifest_info = zipfile.ZipInfo(
                    "manifest.json",
                    date_time=FIXTURE_BUNDLE_ZIP_TIMESTAMP,
                )
                manifest_info.compress_type = zipfile.ZIP_STORED
                manifest_info.create_system = FIXTURE_BUNDLE_ZIP_CREATOR
                manifest_info.external_attr = FIXTURE_BUNDLE_FILE_MODE << FIXTURE_BUNDLE_UNIX_ATTR_SHIFT
                archive.writestr(manifest_info, manifest_bytes)
        if archive_path.stat().st_size > FIXTURE_BUNDLE_MAX_BYTES:
            raise ValueError("Fixture bundle exceeds its fixed archive size bound.")
        return _fixture_bundle_file_sha256(archive_path)
    except Exception:
        archive_path.unlink(missing_ok=True)
        raise


def _validate_fixture_member_rows(
    value: JsonValue,
    member_kind: str,
    expected_count: int,
) -> list[FixtureBundleMember]:
    """Validate ordered manifest entries without opening archive members.

    Args:
        value (JsonValue): Candidate JSON value from the manifest.
        member_kind (str): Fixed photos or hires member category.
        expected_count (int): Exact expected number of entries.

    Returns:
        list[FixtureBundleMember]: Validated names, lengths and digests.

    Raises:
        ValueError: The member table does not match its exact bounded shape.
    """
    if not isinstance(value, list) or len(value) != expected_count:
        raise ValueError("Fixture manifest member count is invalid.")
    entries: list[FixtureBundleMember] = []
    suffix = "png" if member_kind == "photos" else "jpg"
    for index, item in enumerate(value):
        if not isinstance(item, dict) or set(item) != {"name", "size", "sha256"}:
            raise ValueError("Fixture manifest member shape is invalid.")
        name = item["name"]
        size = item["size"]
        digest = item["sha256"]
        expected_name = f"{member_kind}/{index:05d}.{suffix}" if member_kind == "photos" else f"{member_kind}/{index:02d}.{suffix}"
        if name != expected_name:
            raise ValueError("Fixture manifest member path is invalid.")
        if (type(size) is not int or size < 1
                or size > FIXTURE_BUNDLE_MEMBER_MAX_BYTES):
            raise ValueError("Fixture manifest member length is invalid.")
        if (not isinstance(digest, str) or len(digest) != 64
                or any(character not in "0123456789abcdef" for character in digest)):
            raise ValueError("Fixture manifest member SHA-256 is invalid.")
        entries.append({"name": name, "size": size, "sha256": digest})
    return entries


def load_fixture_bundle(
    archive_path: Path,
    expected_archive_sha256: str,
    expected_seed: int,
    requested_photo_count: int,
    expected_bundle_photo_count: int,
    expected_common_sha256: str,
) -> tuple[tuple[bytes, ...], tuple[bytes, ...], str]:
    """Verify a fixture archive and load only the requested photo prefix.

    Args:
        archive_path (Path): Driver-owned ZIP archive; never extracted.
        expected_archive_sha256 (str): Exact archive digest supplied by the driver.
        expected_seed (int): Workload seed required by the current child.
        requested_photo_count (int): Scenario photo count loaded into this process.
        expected_bundle_photo_count (int): Exact maximum extent in the archive.
        expected_common_sha256 (str): Exact shared workload source digest.

    Returns:
        tuple[tuple[bytes, ...], tuple[bytes, ...], str]:
            Requested photo prefix, both hires bodies, and prefix dataset digest.

    Raises:
        ValueError: Identity, path, ZIP metadata, length, or content validation fails.
        OSError: The archive cannot be read.
    """
    if (len(expected_archive_sha256) != 64
            or any(character not in "0123456789abcdef"
                   for character in expected_archive_sha256)):
        raise ValueError("Expected fixture archive SHA-256 is invalid.")
    if (type(requested_photo_count) is not int or requested_photo_count < 1
            or requested_photo_count > expected_bundle_photo_count
            or expected_bundle_photo_count > FIXTURE_BUNDLE_MAX_PHOTOS):
        raise ValueError("Requested fixture extent is outside its fixed bounds.")
    if type(expected_seed) is not int or expected_seed < 0:
        raise ValueError("Expected fixture seed must be a nonnegative integer.")
    if (type(expected_bundle_photo_count) is not int
            or expected_bundle_photo_count < 1
            or expected_bundle_photo_count > FIXTURE_BUNDLE_MAX_PHOTOS):
        raise ValueError("Expected bundle extent is outside its fixed bound.")
    if (len(expected_common_sha256) != 64
            or any(character not in "0123456789abcdef"
                   for character in expected_common_sha256)):
        raise ValueError("Expected common source SHA-256 is invalid.")
    if archive_path.is_symlink() or not archive_path.is_file():
        raise ValueError("Fixture archive must be an owned regular file.")
    archive_size = archive_path.stat().st_size
    if archive_size > FIXTURE_BUNDLE_MAX_BYTES:
        raise ValueError("Fixture archive is absent or exceeds its size bound.")
    if _fixture_bundle_file_sha256(archive_path) != expected_archive_sha256:
        raise ValueError("Fixture archive SHA-256 mismatch.")

    try:
        with zipfile.ZipFile(archive_path, mode="r") as archive:
            infos = archive.infolist()
            names = [info.filename for info in infos]
            if len(names) != len(set(names)):
                raise ValueError("Fixture archive contains duplicate member paths.")
            if len(infos) != expected_bundle_photo_count + HIRES_FIXTURE_VARIANTS + 1:
                raise ValueError("Fixture archive contains an unexpected member count.")
            info_by_name: dict[str, zipfile.ZipInfo] = {}
            for info in infos:
                member = PurePosixPath(info.filename)
                if (member.is_absolute() or ".." in member.parts
                        or "\\" in info.filename or ":" in info.filename
                        or not member.parts or member.as_posix() != info.filename):
                    raise ValueError("Fixture archive contains an unsafe member path.")
                if (info.is_dir() or info.flag_bits & 0x1
                        or info.compress_type != zipfile.ZIP_STORED):
                    raise ValueError("Fixture archive member type is unsupported.")
                mode = (info.external_attr >> 16) & 0o170000
                if mode not in (0, stat.S_IFREG):
                    raise ValueError("Fixture archive contains a non-regular member.")
                if (info.file_size < 1
                        or info.file_size > FIXTURE_BUNDLE_MEMBER_MAX_BYTES):
                    raise ValueError("Fixture archive member exceeds its size bound.")
                info_by_name[info.filename] = info

            manifest_info = info_by_name.get("manifest.json")
            if (manifest_info is None
                    or manifest_info.file_size > FIXTURE_BUNDLE_MANIFEST_MAX_BYTES):
                raise ValueError("Fixture archive manifest is absent or too large.")
            manifest_value = json.loads(
                archive.read(manifest_info).decode("utf-8"),
                object_pairs_hook=_fixture_bundle_json_object,
            )
            if not isinstance(manifest_value, dict):
                raise ValueError("Fixture manifest root must be an object.")
            if set(manifest_value) != {
                "schema_version", "seed", "photo_count", "common_sha256",
                "pillow_version", "photos", "hires",
            }:
                raise ValueError("Fixture manifest fields are invalid.")
            if (type(manifest_value["schema_version"]) is not int
                    or manifest_value["schema_version"] != FIXTURE_BUNDLE_SCHEMA_VERSION
                    or type(manifest_value["seed"]) is not int
                    or manifest_value["seed"] != expected_seed
                    or type(manifest_value["photo_count"]) is not int
                    or manifest_value["photo_count"] != expected_bundle_photo_count
                    or manifest_value["common_sha256"] != expected_common_sha256
                    or manifest_value["pillow_version"] != PILLOW_VERSION):
                raise ValueError("Fixture manifest source identity mismatch.")
            photo_rows = _validate_fixture_member_rows(
                manifest_value["photos"], "photos", expected_bundle_photo_count,
            )
            hires_rows = _validate_fixture_member_rows(
                manifest_value["hires"], "hires", HIRES_FIXTURE_VARIANTS,
            )
            expected_names = {
                "manifest.json",
                *(entry["name"] for entry in photo_rows),
                *(entry["name"] for entry in hires_rows),
            }
            if set(info_by_name) != expected_names:
                raise ValueError("Fixture archive member paths do not match manifest.")

            photos: list[bytes] = []
            for entry in photo_rows[:requested_photo_count]:
                info = info_by_name[entry["name"]]
                if info.file_size != entry["size"]:
                    raise ValueError("Photo body length differs from its manifest.")
                body = archive.read(info)
                if (len(body) != entry["size"]
                        or _fixture_sha256(body) != entry["sha256"]):
                    raise ValueError("Photo body SHA-256 mismatch.")
                photos.append(body)

            hires: list[bytes] = []
            for entry in hires_rows:
                info = info_by_name[entry["name"]]
                if info.file_size != entry["size"]:
                    raise ValueError("Hires body length differs from its manifest.")
                body = archive.read(info)
                if (len(body) != entry["size"]
                        or _fixture_sha256(body) != entry["sha256"]):
                    raise ValueError("Hires body SHA-256 mismatch.")
                hires.append(body)
    except (zipfile.BadZipFile, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise ValueError("Fixture archive or manifest is malformed.") from error

    dataset_sha = hashlib.sha256(b"".join(
        hashlib.sha256(body).digest() for body in photos
    )).hexdigest()
    return tuple(photos), tuple(hires), dataset_sha


def build_gallery(
    root: tk.Tk, source_root: Path, seed: int,
    gallery_id: int = GALLERY_ID,
    thumbnail_memory: "GalleryThumbnailMemory | None" = None,
    photo_count: int = PHOTO_COUNT,
    prepare_session: Callable[[mock.Mock], None] | None = None,
) -> tuple[NativeViewerContract, mock.Mock, FixtureMediaInfo, GalleryFixtureState]:
    """Mount production Photos with protocol-sized pages and prebuilt 4K media.

    Args:
        root (tk.Tk): Native root owned by the scenario process.
        source_root (Path): Checkout containing production viewer modules.
        seed (int): Fixture seed for deterministic image patterns.
        gallery_id (int): Exact synthetic gallery ID admitted by this session.
        thumbnail_memory (GalleryThumbnailMemory | None): Session-owned decoded cache.
        photo_count (int): Positive whole-number catalogue size; defaults to the
            frozen Issue 361 workload count.
        prepare_session (Callable[[mock.Mock], None] | None): Optional workload
            callback to extend the strict LinkingSession fixture before mount.

    Returns:
        tuple[NativeViewerContract, mock.Mock, FixtureMediaInfo, GalleryFixtureState]:
            Real viewer, strict session fake, bounded dataset facts and live counters.
    """
    if type(photo_count) is not int or photo_count < 1:
        raise ValueError("photo_count must be a positive integer.")
    winapp = str(source_root / "winapp")
    if winapp not in sys.path:
        sys.path.insert(0, winapp)

    bodies = PHOTO_BODY_FIXTURES[seed]
    digest_input = b"".join(hashlib.sha256(body).digest() for body in bodies)
    dataset_sha256 = hashlib.sha256(digest_input).hexdigest()
    photos = tuple(
        LinkedPhoto(FIRST_IMAGE_ID + index, f"Synthetic photo {index}")
        for index in range(photo_count)
    )
    pages = tuple(
        LinkedMediaPage(
            gallery_id, PARENT_ID, photos[start:start + PHOTO_PAGE_SIZE],
            photos[start + PHOTO_PAGE_SIZE - 1].image_id
            if start + PHOTO_PAGE_SIZE < photo_count else None,
        )
        for start in range(0, photo_count, PHOTO_PAGE_SIZE)
    )
    session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
    session.can_read_media.return_value = True
    session.can_read_presentation.return_value = True
    session.supports_gallery_tree.return_value = False
    session.can_read_admin_media.return_value = False
    session.can_edit_gallery_metadata.return_value = False
    session.can_reuse_cached_media.return_value = True
    session.background_media_allowed.return_value = False
    session.private_preload_user_id.return_value = None
    session.private_preload_cache_identity.return_value = None
    session.route_performance_summary.return_value = ""
    session.server_timing_summary.return_value = ""
    session.cached_media_photo.return_value = None
    session.media_presentation.return_value = LinkedPresentation(
        enabled=True, autoplay=False, interval_seconds=10,
        transition="medium", music=False, tracks=(),
    )
    state: GalleryFixtureState = {
        "catalogue_variant": 0, "page_reads": 0, "hires_cache_peak": 0,
        "gallery_id": gallery_id,
    }
    hires_cache: OrderedDict[int, bytes] = OrderedDict()
    hires_bodies = HIRES_FIXTURE_BODIES[seed]

    def read_page(
        gallery_id: int, after_id: int | None = None,
        cancelled: threading.Event | None = None,
    ) -> "LinkedMediaPage":
        """Return the exact next page or one changed-title revision.

        Args:
            gallery_id (int): Production gallery identity requested.
            after_id (int | None): Cursor after the last admitted photo.
            cancelled (threading.Event | None): Production cancellation token.

        Returns:
            LinkedMediaPage: Protocol-sized synthetic page in stable order.

        Raises:
            ValueError: Request escaped the fixture gallery or cursor sequence.
            RuntimeError: Production cancellation interrupted the fixture read.
        """
        if gallery_id != state["gallery_id"]:
            raise ValueError("Unexpected synthetic gallery.")
        if cancelled is not None and cancelled.is_set():
            raise RuntimeError("Synthetic page read cancelled.")
        if after_id is None:
            page_index = 0
        else:
            matches = [
                index + 1 for index, previous in enumerate(pages[:-1])
                if previous.next_after == after_id
            ]
            if len(matches) != 1:
                raise ValueError("Cursor is not an exact previous-page terminal.")
            page_index = matches[0]
        if not 0 <= page_index < len(pages):
            raise ValueError("Unexpected synthetic page cursor.")
        state["page_reads"] += 1
        page = pages[page_index]
        if state["catalogue_variant"] == 1 and page_index == 0:
            changed_first = LinkedPhoto(
                page.photos[0].image_id, "Synthetic changed catalogue title",
            )
            return LinkedMediaPage(
                page.gallery_id, page.parent_id,
                (changed_first, *page.photos[1:]), page.next_after,
            )
        return page

    def read_photo(
        gallery_id: int,
        image_id: int,
        thumbnail: bool,
        cancelled: threading.Event | None = None,
        *,
        cache_eligible: bool | None = None,
        thumbnail_etag: str | None = None,
        thumbnail_verified_at: float | None = None,
        preload_eligible: bool | None = None,
        preload_thumbnail_etag: str | None = None,
        preload_verified_at: float | None = None,
        timings: "MediaReadTimings | None" = None,
        use_preloaded_snapshot: bool = False,
    ) -> tuple[bytes, str]:
        """Return synthetic thumbnail or lazily generated hires media.

        Args:
            gallery_id (int): Requested fixture gallery identity.
            image_id (int): Requested synthetic image identity.
            thumbnail (bool): Whether the worker requests the 64-pixel thumbnail.
            cancelled (threading.Event | None): Production cancellation token.
            cache_eligible (bool | None): Current public-cache admission.
            thumbnail_etag (str | None): Current thumbnail validator.
            thumbnail_verified_at (float | None): Catalogue proof timestamp.
            preload_eligible (bool | None): Current Admin preload admission.
            preload_thumbnail_etag (str | None): Admin thumbnail validator.
            preload_verified_at (float | None): Admin proof timestamp.
            timings (MediaReadTimings | None): Optional production timing record.
            use_preloaded_snapshot (bool): Whether the caller requests a saved source.

        Returns:
            tuple[bytes, str]: In-memory body and exact image media type.

        Raises:
            RuntimeError: Production cancellation interrupted fixture delivery.
            ValueError: Request escaped the exact synthetic gallery or photo set.
        """
        del (
            cache_eligible, thumbnail_etag, thumbnail_verified_at,
            preload_eligible, preload_thumbnail_etag, preload_verified_at,
            timings, use_preloaded_snapshot,
        )
        if cancelled is not None and cancelled.is_set():
            raise RuntimeError("Synthetic media read cancelled.")
        if gallery_id != state["gallery_id"]:
            raise ValueError("Unexpected synthetic gallery.")
        offset = image_id - FIRST_IMAGE_ID
        if not 0 <= offset < len(bodies):
            raise ValueError("Unexpected synthetic photo identity.")
        if thumbnail:
            return bodies[offset], "image/png"
        if image_id not in hires_cache:
            hires_cache[image_id] = hires_bodies[offset % len(hires_bodies)]
            if len(hires_cache) > HIRES_CACHE_LIMIT:
                hires_cache.popitem(last=False)
            state["hires_cache_peak"] = max(
                state["hires_cache_peak"], len(hires_cache),
            )
        hires_cache.move_to_end(image_id)
        return hires_cache[image_id], "image/jpeg"

    session.media_page.side_effect = read_page
    session.media_photo.side_effect = read_photo
    if prepare_session is not None:
        prepare_session(session)
    host = ttk.Frame(root)
    host.pack(fill="both", expand=True)
    frame = native.mount_native_gallery_view(
        host, session, LinkedGallery(
            gallery_id, PARENT_ID, f"Synthetic album {gallery_id}",
        ),
        lambda: "en", thumbnail_memory=thumbnail_memory,
    )
    frame.pack(fill="both", expand=True)
    viewer = frame._gallery_viewer_controller
    info: FixtureMediaInfo = {
        "dataset_sha256": dataset_sha256,
        "thumbnail_body_bytes": sum(len(body) for body in bodies),
        "hires_cache_peak": 0,
        "hires_cache_limit": HIRES_CACHE_LIMIT,
    }
    return viewer, session, info, state

def wrap_publication(viewer: NativeViewerContract, recorder: Recorder) -> None:
    """Measure production decode and Tk publication boundaries separately.

    Args:
        viewer (NativeViewerContract): Mounted production viewer controller.
        recorder (Recorder): Active process recorder.

    Returns:
        None: The harness observes original worker and GUI owners in place.
    """
    original_thumbnail = viewer._prepare_thumbnail
    original_detail = viewer._prepare_detail
    original_show_thumbnail = viewer._show_thumbnail
    original_show_detail = viewer._show_detail

    def measured_thumbnail(body: bytes) -> Image.Image:
        """Measure production thumbnail decode and resize preparation.

        Args:
            body (bytes): Complete synthetic thumbnail response body.

        Returns:
            Image.Image: Original production bounded thumbnail raster.
        """
        started_ns = time.perf_counter_ns()
        try:
            return original_thumbnail(body)
        finally:
            recorder.add("thumbnail_prepare", time.perf_counter_ns() - started_ns)

    def measured_detail(body: bytes) -> Image.Image:
        """Measure production detail decode and resize preparation.

        Args:
            body (bytes): Complete synthetic 4K JPEG response body.

        Returns:
            Image.Image: Original production bounded detail raster.
        """
        started_ns = time.perf_counter_ns()
        try:
            return original_detail(body)
        finally:
            recorder.add("detail_prepare", time.perf_counter_ns() - started_ns)

    def measured_show_thumbnail(
        index: int, data: Image.Image | None, *, remember: bool = True,
    ) -> None:
        """Measure handler cost and record only a new current thumbnail attachment.

        Args:
            index (int): Production tile index.
            data (Image.Image | None): Prepared Pillow raster or failure placeholder.
            remember (bool): Whether the production owner retains the source raster.

        Returns:
            None: Stale or failed handlers do not count as publication.
        """
        prior = (
            viewer._photo_images[index]
            if 0 <= index < len(viewer._photo_images) else None
        )
        started_ns = time.perf_counter_ns()
        try:
            original_show_thumbnail(index, data, remember=remember)
        finally:
            elapsed_ns = time.perf_counter_ns() - started_ns
            recorder.add("thumbnail_publication_handler", elapsed_ns)
            attached = (
                data is not None and 0 <= index < len(viewer._photo_images)
                and viewer._photo_images[index] is not None
                and viewer._photo_images[index] is not prior
            )
            if attached:
                recorder.add("image_attachment_callback", elapsed_ns)

    def measured_show_detail(
        detail_serial: int, image_id: int,
        display_image: Image.Image | None,
        cached_image: Image.Image | None,
        encoded: bytes | None,
        decode_reason: str | None = None,
    ) -> None:
        """Measure handler cost and count only a fresh current detail attachment.

        Args:
            detail_serial (int): Captured production detail-selection generation.
            image_id (int): Exact synthetic photo identity.
            display_image (Image.Image | None): Prepared display raster or None.
            cached_image (Image.Image | None): Independent retained cache copy.
            encoded (bytes | None): Encoded response retained by production cache.
            decode_reason (str | None): Safe decode failure class, if present.

        Returns:
            None: Stale or failed detail results do not count as publication.
        """
        current = viewer._lightbox
        prior = current._photo if current is not None else None
        started_ns = time.perf_counter_ns()
        try:
            original_show_detail(
                detail_serial, image_id, display_image, cached_image,
                encoded, decode_reason,
            )
        finally:
            elapsed_ns = time.perf_counter_ns() - started_ns
            recorder.add("detail_publication_handler", elapsed_ns)
            current = viewer._lightbox
            valid_photo = any(
                photo.image_id == image_id for photo in viewer._photos
            )
            attached = (
                display_image is not None and valid_photo
                and detail_serial == viewer._detail_serial
                and current is not None and current._photo is not None
                and current._photo is not prior
            )
            if attached:
                recorder.add("image_attachment_callback", elapsed_ns)

    viewer._prepare_thumbnail = measured_thumbnail
    viewer._prepare_detail = measured_detail
    viewer._show_thumbnail = measured_show_thumbnail
    viewer._show_detail = measured_show_detail

def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    """Parse the immutable child-driver interface.

    Args:
        argv (list[str] | None): Optional argument vector without executable name.

    Returns:
        argparse.Namespace: Exact source identity and bounded scenario settings.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True)
    parser.add_argument("--source-id", required=True)
    parser.add_argument("--seed", type=int, default=DEFAULT_SEED)
    parser.add_argument(
        "--scenario", choices=("dashboard", "import", "gallery", "lightbox"),
        required=True,
    )
    parser.add_argument("--cache", choices=("cold", "warm"), required=True)
    parser.add_argument("--trial", type=int, required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--instrumentation", choices=("on", "off"), required=True)
    args = parser.parse_args(argv)
    if args.trial < 0:
        parser.error("--trial must be nonnegative")
    if not Path(args.source).is_dir():
        parser.error("--source must be an application root directory")
    if not Path(args.output).is_absolute():
        parser.error("--output must be an absolute path")
    if args.seed < 0:
        parser.error("--seed must be nonnegative")
    return args


def run(args: argparse.Namespace) -> dict[str, JsonValue]:
    """Run one strict scenario in a real Tk mainloop.

    Args:
        args (argparse.Namespace): Validated source, cache and workload settings.

    Returns:
        dict[str, JsonValue]: Identity-bound timings, lifecycle counts and limitations.
    """
    source_root = Path(args.source).resolve()
    source_id = args.source_id
    winapp_path = str(source_root / "winapp")
    if winapp_path not in sys.path:
        sys.path.insert(0, winapp_path)
    recorder = Recorder(args.instrumentation == "on")
    recorder.install()
    root: tk.Tk | None = None
    temporary = tempfile.TemporaryDirectory(prefix="php-gallery-p0-")
    private_root = Path(temporary.name)
    profile_names = ("APPDATA", "LOCALAPPDATA", "USERPROFILE")
    saved_profile = {name: os.environ.get(name) for name in profile_names}
    for name in profile_names:
        profile_path = private_root / "profile" / name.lower()
        profile_path.mkdir(parents=True, exist_ok=True)
        os.environ[name] = str(profile_path)
    watchdog_fired = threading.Event()
    started_ns = time.perf_counter_ns()
    measure_started_ns = [started_ns]
    measured_end_ns: list[int | None] = [None]
    operations: dict[str, int] = {
        "ticks": 0, "actions": 0, "worker_events": 0, "drained_events": 0,
        "rows": 0, "history_rows": 0, "activity_rows": 0,
        "activity_item_rows": 0, "refresh_unchanged_requested": 0,
        "refresh_unchanged_completed": 0, "refresh_changed_requested": 0,
        "refresh_changed_completed": 0, "gallery_switches_requested": 0,
        "gallery_switches_completed": 0, "gallery_reopens_requested": 0,
        "gallery_reopens_completed": 0, "details_presented": 0,
        "rapid_switches": 0, "rapid_switches_completed": 0, "zoom_actions": 0,
        "pan_actions": 0, "fullscreen_actions": 0,
        "resize_actions_requested": 0, "resize_actions_completed": 0,
    }
    resources: dict[str, int | str | bool | None] = {
        "tree_items": 0, "tk_images": 0, "queue_peak": 0,
    }
    status = "FAIL"
    failure: str | None = None
    priming_completed = args.cache == "cold"
    visual_evidence = "unavailable"
    display: dict[str, int | float | str] | None = None
    tcl_version: str | None = None
    tk_version: str | None = None
    windowing_system: str | None = None
    metrics: dict[str, float] = {}
    missing_metrics = ["physical_presentation_ms", "process_peak_rss_bytes"]
    cpu_measure_start_ns: list[int | None] = [None]
    missing_required_metrics: list[str] = []
    callback_failures: list[str] = []
    failure_tracebacks: list[str] = []

    def callback_exception(
        exception_type: type[BaseException], error: BaseException,
        callback_traceback: TracebackType | None,
    ) -> None:
        """Capture bounded Tk callback diagnostics for the evidence report.

        Args:
            exception_type (type[BaseException]): Exception class raised by Tk work.
            error (BaseException): Synthetic fixture callback failure.
            callback_traceback (TracebackType | None): Exact callback traceback.

        Returns:
            None: A bounded diagnostic is retained for final status.
        """
        callback_failures.append(
            f"{exception_type.__name__}: {str(error)[:FAILURE_TEXT_LIMIT]}",
        )
        failure_tracebacks.append("".join(traceback_module.format_exception(
            exception_type, error, callback_traceback,
        ))[-FAILURE_TRACEBACK_LIMIT:])
        if root is not None:
            root.quit()

    def hard_watchdog() -> None:
        """Persist a bounded BLOCKED record if a native Tk callback hangs.

        Returns:
            None: Process exits only after a recoverable watchdog record is written.
        """
        watchdog_fired.set()
        fallback: dict[str, JsonValue] = {
            "schema_version": 1, "status": "BLOCKED",
            "scenario": args.scenario, "cache": args.cache,
            "trial": args.trial, "source": source_id, "seed": args.seed,
            "metrics": {"scenario_elapsed_ms": None},
            "operations": operations, "resources": {},
            "metadata": {"scenario": args.scenario, "cache": args.cache,
                         "trial": args.trial, "source": source_id,
                         "seed": args.seed, "watchdog": "expired"},
            "missing_metrics": ["physical_presentation_ms"],
            "missing_required_metrics": ["mainloop_watchdog"],
            "raw_events": [], "raw_events_truncated": 0,
            "failure": "Tk mainloop watchdog expired.",
        }
        target = Path(args.output)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(fallback, indent=2) + "\n", encoding="utf-8")
        os._exit(1)

    watchdog = threading.Timer(WATCHDOG_MS / 1000, hard_watchdog)
    watchdog.daemon = True
    viewer_holder: dict[str, NativeViewerContract] = {}
    shared_thumbnails: "GalleryThumbnailMemory | None" = None
    warm_gallery_leg = [0]
    warm_lightbox_requested = [False]
    lightbox_burst_previous: list[weakref.ReferenceType[PhotoHandle] | None] = [None]
    warm_lightbox_prime_step = [0]
    lightbox_burst_pending = [0]
    retired_workers: list[threading.Thread] = []
    state_holder: dict[str, GalleryFixtureState] = {}
    lightbox_render_original: Callable[..., None] | None = None
    dashboard_holder: dict[str, WatcherHost] = {}
    producer_stop = threading.Event()
    producer_thread: threading.Thread | None = None
    producer_action: Callable[[], None] | None = None
    try:
        PHOTO_BODY_FIXTURES[args.seed] = make_photo_bodies(args.seed)
        HIRES_FIXTURE_BODIES[args.seed] = tuple(
            make_hires_body(args.seed + variant)
            for variant in range(HIRES_FIXTURE_VARIANTS)
        )
        watchdog.start()
        prepare_windows_display()
        root = tk.Tk()
        root.geometry(f"{VIEWPORT[0]}x{VIEWPORT[1]}")
        root.title("PHP Gallery isolated P0 workload")
        root.report_callback_exception = callback_exception
        resize_target: list[tuple[int, int] | None] = [None]

        def on_root_configure(event: tk.Event) -> None:
            """Count a native root resize only at its requested client geometry.

            Args:
                event (tk.Event): Root Configure event delivered by Tk.

            Returns:
                None: A matching resize increments the completion count once.
            """
            target = resize_target[0]
            if (event.widget is root and target == (event.width, event.height)):
                operations["resize_actions_completed"] += 1
                resize_target[0] = None

        if args.scenario in ("gallery", "lightbox"):
            root.bind("<Configure>", on_root_configure, add="+")
        action: Callable[[], None]
        if args.scenario in ("dashboard", "import"):
            module = load_watcher(source_root)
            if args.scenario == "import":
                app, job = build_import_widgets(root, module, private_root)
                def action() -> None:
                    """Mutate synthetic import progress and refresh production views.

                    Returns:
                        None: Item trees, history and activity views reflect the new state.
                    """
                    next_state = (
                        "failed_retryable"
                        if operations["actions"] % 2 == 0 else "confirmed"
                    )
                    change_count = (IMPORT_BURST_ROWS if operations["actions"] % IMPORT_BURST_CADENCE == 0
                                    else IMPORT_PROGRESS_ROWS)
                    for offset in range(change_count):
                        index = (operations["actions"] * IMPORT_PROGRESS_ROWS + offset) % len(job.items)
                        item = job.items[index]
                        item.state = next_state
                        item.attempt_count += 1
                        item.reason = (
                            "Synthetic retry state"
                            if item.state == "failed_retryable" else ""
                        )
                    app.activity_filter_var.set(
                        "warnings" if operations["actions"] % 2 == 0 else "all",
                    )
                    started_ns = time.perf_counter_ns()
                    app.refresh_import_item_tree(job)
                    recorder.add(
                        "callback:WatcherApp.refresh_import_item_tree",
                        time.perf_counter_ns() - started_ns,
                    )
                    started_ns = time.perf_counter_ns()
                    app.refresh_job_history()
                    recorder.add(
                        "callback:WatcherApp.refresh_job_history",
                        time.perf_counter_ns() - started_ns,
                    )
                    started_ns = time.perf_counter_ns()
                    app.refresh_activity_drawer()
                    recorder.add(
                        "callback:WatcherApp.refresh_activity_drawer",
                        time.perf_counter_ns() - started_ns,
                    )
                    items = app.import_item_tree.get_children()
                    history = app.job_history_tree.get_children()
                    activity = app.activity_drawer.get_children()
                    detail_items = app.activity_item_tree.get_children()
                    app.notebook.select(operations["actions"] % app.notebook.index("end"))
                    if items:
                        app.import_item_tree.yview_moveto(1.0)
                        app.import_item_tree.selection_set(items[-1])
                        app.import_item_tree.see(items[-1])
                    if history:
                        app.job_history_tree.selection_set(history[0])
                        app.job_history_tree.event_generate(
                            "<<TreeviewSelect>>", when="tail",
                        )
                    operations["actions"] += 1
                    operations["rows"] = len(items)
                    operations["history_rows"] = len(history)
                    operations["activity_rows"] = len(activity)
                    operations["activity_item_rows"] = len(detail_items)
                    resources["tree_items"] = (
                        operations["rows"] + operations["history_rows"]
                        + operations["activity_rows"] + operations["activity_item_rows"]
                    )
            else:
                app = setup_dashboard(root, module)

                dashboard_holder["app"] = app
                queued_times: dict[str, int] = {}
                queued_lock = threading.Lock()
                original_get_event = app.events.get_nowait

                def timed_get_event() -> tuple[str, str]:
                    """Observe actual dequeue latency without changing FIFO events.

                    Returns:
                        tuple[str, str]: Original severity and synthetic message.

                    Raises:
                        queue.Empty: The unchanged source queue has no event.
                    """
                    event = original_get_event()
                    with queued_lock:
                        enqueued_ns = queued_times.pop(event[1], None)
                    if enqueued_ns is not None:
                        recorder.add("worker_enqueue_to_consume",
                                     time.perf_counter_ns() - enqueued_ns)
                    return event

                if recorder.enabled:
                    app.events.get_nowait = timed_get_event

                def produce_worker_events() -> None:
                    """Publish synthetic queue messages off the Tk thread.

                    Returns:
                        None: The producer exits after its measured cancellation signal.
                    """
                    sample = 0
                    while not producer_stop.wait(TICK_MS / 1000):
                        count = (DASHBOARD_BURST_EVENTS if sample % DASHBOARD_BURST_CADENCE == 0
                                 else DASHBOARD_STEADY_EVENTS)
                        for event_index in range(count):
                            message = f"Watching synthetic folder sample {sample}:{event_index}"
                            if recorder.enabled:
                                with queued_lock:
                                    queued_times[message] = time.perf_counter_ns()
                            app.events.put(("info", message))
                            operations["worker_events"] += 1
                        resources["queue_peak"] = max(
                            int(resources["queue_peak"]), app.events.qsize(),
                        )
                        sample += 1

                producer_action = produce_worker_events

                def action() -> None:
                    """Update actual monitor visuals from synthetic telemetry state.

                    Returns:
                        None: Production monitor callback updates labels and canvas.
                    """
                    app.notebook.select(operations["ticks"] % len(app.notebook.tabs()))
                    monitor_started_ns = time.perf_counter_ns()
                    app.update_monitor_state(
                        "green" if operations["ticks"] % 2 == 0 else "red",
                        f"Synthetic telemetry sample {operations['ticks']}",
                    )
                    recorder.add(
                        "callback:WatcherApp.update_monitor_state",
                        time.perf_counter_ns() - monitor_started_ns,
                    )
                    operations["drained_events"] = app._event_drain_total_count
                    operations["actions"] += 1
                    resources["tree_items"] = len(app.activity_drawer.get_children())
        else:
            def ensure_viewer(gallery_id: int = GALLERY_ID) -> NativeViewerContract:
                """Create the real Photos viewer at the cold or warm cache boundary.

                Args:
                    gallery_id (int): Exact synthetic album selected by this mount.

                Returns:
                    NativeViewerContract: Real viewer bound to synthetic data.
                """
                nonlocal lightbox_render_original, shared_thumbnails
                viewer = viewer_holder.get("viewer")
                if viewer is None:
                    if shared_thumbnails is None:
                        shared_thumbnails = GalleryThumbnailMemory()
                    viewer, session, fixture, state = build_gallery(
                        root, source_root, args.seed, gallery_id=gallery_id,
                        thumbnail_memory=shared_thumbnails,
                    )
                    if recorder.enabled:
                        wrap_publication(viewer, recorder)
                        if lightbox_render_original is None:
                            lightbox_render_original = PhotoLightbox._render_image

                            def measured_lightbox_render(
                                lightbox: PhotoLightbox, *,
                                resampling: int | None = None,
                                preserve_resize_jobs: bool = False,
                            ) -> None:
                                """Measure original high-resolution render callback.

                                Args:
                                    lightbox (PhotoLightbox): Production overlay.
                                    resampling (int | None): Optional image filter.
                                    preserve_resize_jobs (bool): Keep owned resize work.

                                Returns:
                                    None: Original Tk render completes before timing.
                                """
                                started_ns = time.perf_counter_ns()
                                try:
                                    lightbox_render_original(
                                        lightbox, resampling=resampling,
                                        preserve_resize_jobs=preserve_resize_jobs,
                                    )
                                finally:
                                    recorder.add(
                                        "callback:PhotoLightbox._render_image",
                                        time.perf_counter_ns() - started_ns,
                                    )

                            PhotoLightbox._render_image = measured_lightbox_render
                    viewer_holder["viewer"] = viewer
                    state_holder["state"] = state
                    resources["dataset_sha256"] = str(fixture["dataset_sha256"])
                    resources["thumbnail_body_bytes"] = int(fixture["thumbnail_body_bytes"])
                    resources["hires_cache_peak"] = int(fixture["hires_cache_peak"])
                    resources["hires_cache_limit"] = int(fixture["hires_cache_limit"])
                return viewer_holder["viewer"]

            def switch_viewer(gallery_id: int) -> NativeViewerContract:
                """Switch the real mounted viewer while retaining its session cache.

                Args:
                    gallery_id (int): Exact synthetic album to display next.

                Returns:
                    NativeViewerContract: New live controller for the selected album.
                """
                previous = viewer_holder.pop("viewer", None)
                if previous is not None:
                    host = previous.frame.master
                    worker = previous._worker
                    previous.close()
                    if worker is not None:
                        retired_workers.append(worker)
                    if host is not root:
                        host.destroy()
                return ensure_viewer(gallery_id)

            def advance_gallery_sequence() -> None:
                """Advance each fixed metadata/switch action after owned completion.

                Returns:
                    None: One unchanged refresh, changed refresh and album switch
                        are requested at most once; page continuations retain
                        their real source owner and cancellation fences.
                """
                viewer = ensure_viewer()
                if not viewer._photos:
                    return
                if len(viewer._photos) < PHOTO_COUNT and viewer._worker is None:
                    # The visible Load more action is unavailable during an owned
                    # request; do not inject duplicate captured cursors behind it.
                    viewer._load_more()
                if (len(viewer._photos) >= PHOTO_COUNT
                        and not operations["refresh_unchanged_requested"]):
                    state_holder["state"]["catalogue_variant"] = 0
                    state_holder["state"]["page_reads"] = 0
                    viewer.refresh_photos()
                    operations["refresh_unchanged_requested"] = 1
                elif (operations["refresh_unchanged_completed"]
                        and not operations["refresh_changed_requested"]):
                    state_holder["state"]["catalogue_variant"] = 1
                    state_holder["state"]["page_reads"] = 0
                    viewer.refresh_photos()
                    operations["refresh_changed_requested"] = 1
                if (viewer._worker is None and viewer._photos
                        and viewer._photos[0].title == "Synthetic photo 0"
                        and operations["refresh_unchanged_requested"]
                        and state_holder["state"]["page_reads"] >= len(range(0, PHOTO_COUNT, PHOTO_PAGE_SIZE))):
                    operations["refresh_unchanged_completed"] = 1
                if (viewer._worker is None and viewer._photos
                        and viewer._photos[0].title
                        == "Synthetic changed catalogue title"
                        and operations["refresh_changed_requested"]):
                    operations["refresh_changed_completed"] = 1
                if (operations["refresh_changed_completed"]
                        and not operations["gallery_switches_requested"]
                        and viewer._worker is None):
                    operations["gallery_switches_requested"] = 1
                    viewer = switch_viewer(SECOND_GALLERY_ID)
                elif (operations["gallery_switches_requested"]
                        and viewer.gallery.gallery_id == SECOND_GALLERY_ID
                        and viewer_ready_for_warm_cache()):
                    operations["gallery_switches_completed"] = 1

                if (operations["gallery_switches_completed"]
                        and not operations["gallery_reopens_requested"]):
                    operations["gallery_reopens_requested"] = 1
                    viewer = switch_viewer(GALLERY_ID)
                elif (operations["gallery_reopens_requested"]
                        and viewer.gallery.gallery_id == GALLERY_ID
                        and len(viewer._photos) == PHOTO_COUNT
                        and viewer_ready_for_warm_cache()):
                    operations["gallery_reopens_completed"] = 1


            def action() -> None:
                """Drive production catalogue scrolling or lightbox controls.

                Returns:
                    None: Actual Tk widgets, worker results and cache publication are observed.
                """
                viewer = ensure_viewer()
                if args.scenario == "gallery":
                    if viewer._photos:
                        viewer.grid_canvas.yview_moveto(
                            GALLERY_SCROLL_POSITIONS[
                                operations["actions"] % len(GALLERY_SCROLL_POSITIONS)
                            ],
                        )
                        viewer._on_grid_viewport(None)
                        if operations["actions"] % GALLERY_RESIZE_CADENCE == 0:
                            width = GALLERY_RESIZE_WIDTHS[
                                (operations["actions"] // GALLERY_RESIZE_CADENCE) % len(GALLERY_RESIZE_WIDTHS)
                            ]
                            resize_target[0] = (width, VIEWPORT[1])
                            operations["resize_actions_requested"] += 1
                            root.geometry(f"{width}x{VIEWPORT[1]}")
                        advance_gallery_sequence()
                elif viewer._photos:
                    lightbox = viewer._lightbox
                    if (lightbox is None or lightbox._photo is None
                            or viewer._pending_detail_index is not None):
                        raise RuntimeError(
                            "Lightbox readiness changed after a measured click was queued."
                        )
                    operations["details_presented"] += 1
                    prior_zoom = lightbox._zoom
                    lightbox._buttons[2].invoke()
                    if lightbox._zoom != prior_zoom:
                        operations["zoom_actions"] += 1
                    prior_coords = tuple(lightbox._canvas.coords("photo"))
                    lightbox._canvas.event_generate(
                        "<ButtonPress-1>", x=20, y=20, when="now",
                    )
                    lightbox._canvas.event_generate(
                        "<B1-Motion>", x=26, y=24, state=TK_BUTTON1_MASK, when="now",
                    )
                    lightbox._canvas.event_generate(
                        "<ButtonRelease-1>", x=26, y=24, when="now",
                    )
                    next_coords = tuple(lightbox._canvas.coords("photo"))
                    if len(prior_coords) >= 2 and next_coords != prior_coords:
                        operations["pan_actions"] += 1
                    prior_fullscreen = lightbox._fullscreen
                    lightbox._buttons[3].invoke()
                    entered_fullscreen = lightbox._fullscreen != prior_fullscreen
                    lightbox._buttons[3].invoke()
                    if entered_fullscreen and lightbox._fullscreen == prior_fullscreen:
                        operations["fullscreen_actions"] += 1
                    target_size = (
                        VIEWPORT if operations["actions"] % 2
                        else (VIEWPORT[0] + RESIZE_DELTA_PIXELS, VIEWPORT[1] + RESIZE_DELTA_PIXELS)
                    )
                    resize_target[0] = target_size
                    operations["resize_actions_requested"] += 1
                    root.geometry(f"{target_size[0]}x{target_size[1]}")
                    # Reuse the same two-photo working set in every macrostep.
                    # The original source owns serial validation and cancellation;
                    # a settled replacement PhotoImage proves fresh publication.
                    lightbox_burst_previous[0] = weakref.ref(lightbox._photo)
                    lightbox_burst_pending[0] = RAPID_SWITCHES
                    for offset in range(RAPID_SWITCHES):
                        viewer._open_grid_photo(offset % len(viewer._photos))
                    operations["rapid_switches"] += RAPID_SWITCHES
                operations["actions"] += 1
                operations["rows"] = max(
                    operations["rows"], len(viewer._photos),
                )
                resources["tree_items"] = len(viewer._tile_buttons)
                resources["hires_cache_peak"] = state_holder["state"]["hires_cache_peak"]
                resources["tk_images"] = sum(
                    image is not None for image in viewer._photo_images
                )
        interaction_button = Button(
            root, text="Run sample", command=action,
            style="Photos.Icon.TButton",
        )
        interaction_button.pack(side="top", anchor="w")

        def lightbox_measurement_ready() -> bool:
            """Require a real selected detail image and idle viewer ownership.

            Returns:
                bool: True after photo, worker and detail state all settle.
            """
            viewer = viewer_holder.get("viewer")
            return bool(
                viewer is not None and viewer._photos
                and viewer._lightbox is not None
                and viewer._lightbox._photo is not None
                and viewer._worker is None
                and viewer._pending_detail_index is None
            )

        def complete_lightbox_burst_if_ready() -> bool:
            """Count a burst only after its replacement production image settles.

            Returns:
                bool: True when no burst is pending or its fresh publication completed.
            """
            if lightbox_burst_pending[0] == 0:
                return True
            viewer = viewer_holder.get("viewer")
            previous_photo = lightbox_burst_previous[0]
            if (viewer is None or previous_photo is None
                    or not lightbox_measurement_ready()
                    or viewer._lightbox._photo is previous_photo()):
                return False
            operations["rapid_switches_completed"] += lightbox_burst_pending[0]
            lightbox_burst_pending[0] = 0
            lightbox_burst_previous[0] = None
            return True

        def tick() -> None:
            """Run one accepted action and preserve its requested cadence.

            Returns:
                None: Readiness polling creates no input or interaction sample.
            """
            if args.scenario == "gallery":
                viewer = ensure_viewer()
                if not viewer._photos:
                    root.after(PRIMING_POLL_MS, tick)
                    return
            elif args.scenario == "lightbox":
                viewer = ensure_viewer()
                if viewer._photos and not warm_lightbox_requested[0]:
                    if (viewer._worker is None
                            and viewer._pending_detail_index is None):
                        viewer._open_grid_photo(0)
                        warm_lightbox_requested[0] = True
                    root.after(PRIMING_POLL_MS, tick)
                    return
                if not lightbox_measurement_ready():
                    root.after(PRIMING_POLL_MS, tick)
                    return
                if not complete_lightbox_burst_if_ready():
                    root.after(PRIMING_POLL_MS, tick)
                    return
            operations["ticks"] += 1
            if operations["actions"] < MEASURED_ACTION_COUNT:
                recorder.interaction(root, interaction_button)
                root.after(TICK_MS, tick)

        def viewer_ready_for_warm_cache() -> bool:
            """Check that actual initial thumbnail publication completed.

            Returns:
                bool: True only after the first real viewport is idle and attached.
            """
            viewer = viewer_holder.get("viewer")
            return bool(
                viewer is not None and viewer._photos
                and viewer._worker is None and viewer._events.empty()
                and viewer._visible_thumbnail_indices
                and all(
                    viewer._photo_images[index] is not None
                    for index in viewer._visible_thumbnail_indices
                )
            )

        def prime() -> None:
            """Prime production UI once and wait by Tk callbacks for completion.

            Returns:
                None: Warm measurements start only after actual visible state is ready.
            """
            nonlocal priming_completed
            if args.scenario in ("gallery", "lightbox"):
                viewer = ensure_viewer()
                if (args.scenario == "lightbox" and viewer._photos
                        and not warm_lightbox_requested[0]
                        and viewer_ready_for_warm_cache()):
                    # Detail admission cancels pending grid publication. Prime
                    # the visible grid first, then wait only on detail ownership.
                    viewer._open_grid_photo(0)
                    warm_lightbox_requested[0] = True
                if (args.scenario == "gallery" and len(viewer._photos) < PHOTO_COUNT
                        and viewer._worker is None):
                    viewer._load_more()
                ready = viewer_ready_for_warm_cache()
                if args.scenario == "gallery":
                    ready = ready and len(viewer._photos) >= PHOTO_COUNT
                    if ready and warm_gallery_leg[0] < 2:
                        warm_gallery_leg[0] += 1
                        switch_viewer(SECOND_GALLERY_ID if warm_gallery_leg[0] == 1 else GALLERY_ID)
                        ready = False
                if args.scenario == "lightbox":
                    ready = bool(
                        warm_lightbox_requested[0]
                        and viewer._lightbox is not None
                        and viewer._lightbox._photo is not None
                        and viewer._pending_detail_index is None
                        and viewer._worker is None
                    )
                    if (ready and warm_lightbox_prime_step[0]
                            < len(LIGHTBOX_PRIMING_INDICES) - 1):
                        warm_lightbox_prime_step[0] += 1
                        viewer._open_grid_photo(
                            LIGHTBOX_PRIMING_INDICES[warm_lightbox_prime_step[0]],
                        )
                        ready = False
            else:
                recorder.interaction(root, interaction_button)
                ready = True
            if ready:
                priming_completed = True
                return
            if time.perf_counter_ns() - started_ns < WARMUP_LIMIT_MS * 1_000_000:
                root.after(PRIMING_POLL_MS, prime)

        def begin_measurement() -> None:
            """Start measurement after the declared warmup/cache preparation phase.

            Returns:
                None: Warmup timings are excluded and actions reset for the trial.
            """
            nonlocal producer_thread
            if args.cache == "warm" and not priming_completed:
                if time.perf_counter_ns() - started_ns >= WARMUP_LIMIT_MS * 1_000_000:
                    raise RuntimeError("Deterministic cache priming did not finish.")
                root.after(PRIMING_POLL_MS, begin_measurement)
                return
            recorder.phase = "measured"
            recorder.start_heartbeat(root)
            measure_started_ns[0] = time.perf_counter_ns()
            cpu_measure_start_ns[0] = time.process_time_ns()
            for key in operations:
                operations[key] = 0
            if producer_action is not None:
                producer_thread = threading.Thread(
                    target=producer_action, name="synthetic-watch-events",
                    daemon=True,
                )
                producer_thread.start()
                dashboard_holder["app"]._schedule_event_drain(0)
            root.after(0, tick)
            root.after(MEASURED_MS, finish_measurement)

        def finish_measurement() -> None:
            """Stop scenario workers and leave the root's mainloop.

            Returns:
                None: Viewer close owns cancellation before the root is destroyed.
            """
            nonlocal status, visual_evidence
            if args.scenario == "gallery":
                advance_gallery_sequence()
            if args.scenario == "lightbox":
                complete_lightbox_burst_if_ready()
            if operations["actions"] < MEASURED_ACTION_COUNT or (
                    args.scenario == "gallery" and not operations["gallery_reopens_completed"]) or (
                    args.scenario == "lightbox" and lightbox_burst_pending[0] > 0):
                root.after(PRIMING_POLL_MS, finish_measurement)
                return
            measured_end_ns[0] = time.perf_counter_ns()
            recorder.stop_heartbeat()
            recorder.phase = "warmup"
            if cpu_measure_start_ns[0] is not None and recorder.enabled:
                metrics["process_cpu_ms"] = round(
                    (time.process_time_ns() - cpu_measure_start_ns[0]) / 1_000_000,
                    3,
                )
            try:
                bounds = (
                    root.winfo_rootx(), root.winfo_rooty(),
                    root.winfo_rootx() + root.winfo_width(),
                    root.winfo_rooty() + root.winfo_height(),
                )
                ImageGrab.grab(bbox=bounds).save(Path(args.output).with_suffix(".png"))
                visual_evidence = "root-window-capture"
            except (OSError, RuntimeError, tk.TclError):
                visual_evidence = "unavailable"
            producer_stop.set()
            if producer_thread is not None:
                producer_thread.join(1.0)
                resources["producer_stopped"] = not producer_thread.is_alive()
            dashboard_app = dashboard_holder.get("app")
            if dashboard_app is not None:
                operations["drained_events"] = dashboard_app._event_drain_total_count
                operations["pending_queue_depth"] = dashboard_app.events.qsize()
                dashboard_app.exiting = True
                dashboard_app._cancel_event_ui_callbacks()
            viewer = viewer_holder.get("viewer")
            if viewer is not None:
                resources["final_catalogue_rows"] = len(viewer._photos)
                resources["final_catalogue_unique_rows"] = len({
                    photo.image_id for photo in viewer._photos
                })
                viewer.close()
                worker = viewer._worker
                if worker is not None:
                    worker.join(1.0)
                    resources["worker_stopped"] = not worker.is_alive()
                resources["viewer_closed"] = viewer._closed
            status = "PASS" if operations["actions"] == MEASURED_ACTION_COUNT else "FAIL"
            root.quit()

        root.after(WARMUP_MS, begin_measurement)
        if args.cache == "warm":
            root.after(100, prime)
        root.mainloop()
        watchdog.cancel()
        if recorder.enabled:
            operations.update(recorder.operation_counts)
            for operation_name, count in recorder.operation_counts.items():
                metrics[f"{operation_name}_count"] = float(count)
        if args.cache == "warm" and not priming_completed:
            status = "BLOCKED"
            failure = "Warm cache priming did not reach its production readiness condition."
        if callback_failures:
            status = "FAIL"
            failure = callback_failures[0]
        if args.scenario == "dashboard" and (
                operations["worker_events"] == 0 or operations["drained_events"] == 0
                or resources.get("producer_stopped") is False):
            status = "FAIL"
            failure = "Worker telemetry did not settle through the production event pump."
        if args.scenario == "import":
            if (
                operations["rows"] != IMPORT_ROWS
                or operations["history_rows"] != 200
                or operations["activity_rows"] != 100
                or operations["activity_item_rows"] != IMPORT_ROWS
            ):
                status = "FAIL"
                failure = "Import and activity views did not reach their production caps."
        if args.scenario == "gallery" and (
                resources.get("final_catalogue_rows") != PHOTO_COUNT
                or resources.get("final_catalogue_unique_rows") != PHOTO_COUNT):
            status = "FAIL"
            failure = "Catalogue traversal did not retain exactly 500 unique photos."
        if args.scenario == "gallery":
            expected_gallery_resizes = len(range(0, MEASURED_ACTION_COUNT, GALLERY_RESIZE_CADENCE))
            if (operations["resize_actions_requested"] != expected_gallery_resizes
                    or operations["resize_actions_completed"] != expected_gallery_resizes):
                status = "FAIL"
                failure = "The fixed gallery resize sequence did not complete."
        if args.scenario == "gallery" and operations["rows"] < PHOTO_COUNT:
            status = "FAIL"
            failure = "Synthetic catalog did not reach all 500 photos."
        if args.scenario == "gallery" and (
                not operations["refresh_unchanged_completed"]
                or not operations["refresh_changed_completed"]
                or not operations["gallery_switches_completed"]
                or not operations["gallery_reopens_completed"]
        ):
            status = "FAIL"
            failure = "A required refresh, gallery switch or reopen did not complete."
        if args.scenario == "lightbox":
            expected_burst_count = MEASURED_ACTION_COUNT * RAPID_SWITCHES
            expected_action_counts = {
                "details_presented": MEASURED_ACTION_COUNT,
                "rapid_switches": expected_burst_count,
                "rapid_switches_completed": expected_burst_count,
                "zoom_actions": MEASURED_ACTION_COUNT,
                "pan_actions": MEASURED_ACTION_COUNT,
                "fullscreen_actions": MEASURED_ACTION_COUNT,
                "resize_actions_requested": MEASURED_ACTION_COUNT,
                "resize_actions_completed": MEASURED_ACTION_COUNT,
            }
            for key, expected_count in expected_action_counts.items():
                if operations[key] != expected_count:
                    status = "FAIL"
                    failure = (
                        f"Lightbox action {key} completed "
                        f"{operations[key]} of {expected_count} required."
                    )
                    break
        if resources.get("viewer_closed") is False or resources.get("worker_stopped") is False:
            status = "FAIL"
            failure = "Viewer worker ownership did not settle at teardown."
        if recorder.enabled and recorder.truncated:
            status = "FAIL"
            failure = f"Measured timing record capacity exceeded by {recorder.truncated} observations."
        if status == "PASS" and recorder.enabled:
            measured = [
                event for event in recorder.events
                if event["phase"] == "measured"
            ]
            required_names = [
                "event_loop_drift", "callback", "interaction_feedback",
            ]
            if args.scenario in ("gallery", "lightbox"):
                required_names.append("image_publication")
            output_names = {
                "event_loop_drift": "event_loop_drift_p95_ms",
                "callback": "callback_p95_ms",
                "interaction_feedback": "interaction_feedback_p95_ms",
                "image_publication": "image_publication_p95_ms",
                "thumbnail_prepare": "thumbnail_prepare_p95_ms",
                "detail_prepare": "detail_prepare_p95_ms",
                "callback:PhotoLightbox._render_image": "lightbox_render_p95_ms",
            }
            for name in required_names:
                samples = [
                    int(event["ns"]) for event in measured
                    if event["metric"] == name
                ]
                value = percentile_ms(samples)
                if value is None:
                    missing_required_metrics.append(output_names[name])
                else:
                    metrics[output_names[name]] = value
            for raw_name in (
                "thumbnail_prepare", "detail_prepare", "image_resize_gui",
                "image_resize_worker", "worker_enqueue_to_consume",
                "callback:PhotoLightbox._render_image", "image_attachment_callback",
                "callback:WatcherApp.refresh_import_item_tree",
                "callback:WatcherApp.refresh_job_history",
                "callback:WatcherApp.refresh_activity_drawer",
                "callback:WatcherApp.update_monitor_state",
                "thumbnail_publication_handler", "detail_publication_handler",
            ):
                samples = [int(event["ns"]) for event in measured if event["metric"] == raw_name]
                metric_name = raw_name.replace(":", "_").replace(".", "_")
                metrics[metric_name + "_count"] = float(len(samples))
                metrics[metric_name + "_total_ms"] = sum(samples) / NS_PER_MS
            for raw_name in ("event_loop_drift", "callback", "interaction_feedback"):
                samples = [int(event["ns"]) for event in measured if event["metric"] == raw_name]
                for suffix, fraction in LATENCY_FRACTIONS.items():
                    value = percentile_ms(samples, fraction)
                    if value is not None:
                        metrics[raw_name + "_" + suffix + "_ms"] = value
                if samples:
                    metrics[raw_name + "_max_ms"] = max(samples) / NS_PER_MS
            if missing_required_metrics:
                status = "FAIL"
                failure = "Required timing probes produced no measured samples."
        if not recorder.enabled and status == "PASS":
            metrics = {}
        if root is not None:
            display = {
                "screen_width": root.winfo_screenwidth(),
                "screen_height": root.winfo_screenheight(),
                "dpi": root.winfo_fpixels("1i"),
            }
            tcl_version = str(root.tk.call("info", "patchlevel"))
            tk_version = str(root.tk.call("set", "tk_patchLevel"))
            windowing_system = str(root.tk.call("tk", "windowingsystem"))
    except Exception as error:
        status = "BLOCKED" if root is None else "FAIL"
        failure = f"{type(error).__name__}: {str(error)[:FAILURE_TEXT_LIMIT]}"
        failure_tracebacks.append(traceback_module.format_exc()[-FAILURE_TRACEBACK_LIMIT:])
    finally:
        watchdog.cancel()
        producer_stop.set()
        if producer_thread is not None:
            producer_thread.join(WORKER_SHUTDOWN_SECONDS)
        recorder.phase = "warmup"
        recorder.stop_heartbeat()
        active_viewer = viewer_holder.get("viewer")
        if active_viewer is not None and not active_viewer._closed:
            worker = active_viewer._worker
            active_viewer.close()
            if worker is not None:
                worker.join(WORKER_SHUTDOWN_SECONDS)
                if worker.is_alive():
                    status = "FAIL"
                    failure = "A cancelled scenario worker did not relinquish ownership."
        if lightbox_render_original is not None:
            PhotoLightbox._render_image = lightbox_render_original
        recorder.restore()
        for retired_worker in retired_workers:
            retired_worker.join(WORKER_SHUTDOWN_SECONDS)
            if retired_worker.is_alive():
                status = "FAIL"
                failure = "A retired gallery worker did not relinquish ownership."
        if shared_thumbnails is not None:
            shared_thumbnails.clear()
        temporary.cleanup()
        for name, value in saved_profile.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value
        if root is not None:
            try:
                root.destroy()
            except tk.TclError:
                pass

    if recorder.enabled:
        if psutil is None:
            raise RuntimeError("Instrumented resource sampling requires psutil.")
        metrics["process_peak_rss_bytes"] = float(psutil.Process().memory_info().peak_wset)
        missing_metrics = [name for name in missing_metrics if name != "process_peak_rss_bytes"]
    metrics["scenario_elapsed_ms"] = round(
        max(0, (measured_end_ns[0] or time.perf_counter_ns()) - measure_started_ns[0]) / 1_000_000, 3,
    )
    if not recorder.enabled:
        metrics = {"scenario_elapsed_ms": metrics["scenario_elapsed_ms"]}
        missing_metrics.append("process_cpu_ms")
    if args.scenario not in ("gallery", "lightbox"):
        metrics.pop("image_publication_p95_ms", None)
    raw: list[JsonValue] = [dict(event) for event in recorder.events]
    safe_resources = {
        key: value for key, value in resources.items()
        if isinstance(value, (int, float, bool, str)) or value is None
    }
    metadata: dict[str, JsonValue] = {
        "scenario": args.scenario, "cache": args.cache,
        "trial": args.trial, "source": source_id, "seed": args.seed,
        "source_path": str(source_root), "instrumentation": args.instrumentation,
        "python": platform.python_version(), "platform": sys.platform,
        "pillow": PILLOW_VERSION,
        "tcl": tcl_version, "tk": tk_version,
        "tk_windowing_system": windowing_system,
        "elapsed_ms": round((time.perf_counter_ns() - started_ns) / 1_000_000, 3),
        "display": display, "cache_start_state": (
            "empty" if args.cache == "cold" else "same-process-primed"
        ),
        "priming_completed": priming_completed,
        "interaction_latency_semantics": "queued theme-button events to Tk idle",
        "image_publication_semantics": "ImageTk.PhotoImage constructor completion; attachment handlers separate",
        "operation_count_units": "Tk method calls; bulk deletion cardinality is separate",
        "warmup_timing_retention": "excluded; raw event budget belongs only to measured phase",
        "event_loop_drift_semantics": "independent 16 ms Tk heartbeat dispatch lateness",
        "callback_record_semantics": "one duration record with callback_name and dispatch_delay_ns",
        "synthetic_hires_generation": "prebuilt before Tk mainloop and excluded from callbacks",
        "visual_evidence": visual_evidence,
    }
    return {
        "schema_version": 1, "status": status,
        "scenario": args.scenario, "cache": args.cache,
        "trial": args.trial, "source": source_id, "seed": args.seed,
        "metrics": metrics, "operations": operations,
        "resources": safe_resources, "metadata": metadata,
        "missing_metrics": sorted(set(missing_metrics)),
        "missing_required_metrics": missing_required_metrics,
        "raw_events": raw, "raw_events_truncated": recorder.truncated,
        "failure": failure,
        "failure_traceback": failure_tracebacks[-1] if failure_tracebacks else None,
    }

def main(argv: list[str] | None = None) -> int:
    """Execute one workload and write its strict JSON evidence record.

    Args:
        argv (list[str] | None): Optional argument vector without executable name.

    Returns:
        int: Zero only when the required workload and probes passed.
    """
    args = parse_args(argv)
    result = run(args)
    target = Path(args.output)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(f"{result['status']} {args.scenario}/{args.cache} trial={args.trial}")
    return 0 if result["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
