# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_445_gui_benchmark.py
# Module Type: Hosted paired WinApp benchmark
# Purpose: Compare current Tk photo rendering and Qt Quick on one runner.
# Responsibilities: Use deterministic media and equal input, validate exact
#   source identity, retain per-process samples and paired uncertainty.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Compare Photos data, resource publication and frame proxies on Windows."""

import argparse
import csv
from hashlib import sha256
import json
from math import isclose, isfinite
import os
from pathlib import Path
import platform
import subprocess
import sys
import tempfile
from threading import Event
import time
from typing import Callable
from unittest import mock

# Keep the candidate WinApp discoverable for coordinator metadata. The Tk fixture
# may prepend an immutable source before any selected application owner is imported.
PROJECT_ROOT = Path(__file__).resolve().parents[2]
WINAPP_SOURCE = str(PROJECT_ROOT / "winapp")
if WINAPP_SOURCE not in sys.path:
    sys.path.insert(0, WINAPP_SOURCE)

import psutil
import issue_361_tk_workloads as fixtures
from issue_361_tk_benchmark import bootstrap_delta_ci, describe, extract_commit, host_metadata
from uploader.ui_theme import configure_theme

if "--child-renderer" not in sys.argv or "qt" in sys.argv:
    # The coordinator and Qt child use the candidate environment helper. Tk
    # control children may have the immutable baseline already bound as uploader.
    from uploader.dependency_versions import dependency_versions

if "--child-renderer" in sys.argv and "qt" in sys.argv:
    from PySide6.QtCore import qVersion
    from PySide6.QtQuick import QQuickItem
    from PySide6.QtWidgets import QApplication
    from uploader.app_linking import MediaReadTimings, ThumbnailBatchWitness, ThumbnailPublication
    from uploader.qt_photo_source import PhotoRow
    from uploader.qt_photos import QtPhotos
    from uploader.qt_theme import apply_qt_theme

if "--child-renderer" not in sys.argv:
    import issue_445_import_throughput as import_benchmark

RENDERERS = ("tk-widgets", "tk-canvas", "qt")
TK_SOURCE_MODULES = (
    "uploader", "uploader.native_gallery_viewer", "uploader.ui_theme",
)
QT_SOURCE_MODULES = (
    *TK_SOURCE_MODULES, "uploader.dependency_versions", "uploader.qt_photo_source",
    "uploader.qt_photos", "uploader.qt_theme",
)
# Purpose: Exercise the same catalogue scales as the earlier Canvas trial.
# Type: tuple[int, ...]. Units: photo rows. Scope: one paired workload cell.
# Consumers: main and the issue 445 runner matrix.
# Rationale: 100, 500 and 2,000 cover a small, medium and long virtual grid.
PHOTO_CASES = (100, 500, 2000)
# Immutable control source required by issue 445's Tk parity review.
IMMUTABLE_TK_SHA = "b63d6d5e5bee014ab3750080833f49dcc27e9a34"
# Purpose: Obtain independently ordered process groups for paired inference.
# Type: int. Units: independent groups. Scope: one size/cache cell.
# Consumers: run_comparison. Rationale: Twelve groups permit each of the six
# renderer permutations exactly twice while preserving renderer pairing.
GROUPS = 12
ORDERS = (
    ("tk-widgets", "tk-canvas", "qt"),
    ("tk-widgets", "qt", "tk-canvas"),
    ("tk-canvas", "tk-widgets", "qt"),
    ("tk-canvas", "qt", "tk-widgets"),
    ("qt", "tk-widgets", "tk-canvas"),
    ("qt", "tk-canvas", "tk-widgets"),
)
# Purpose: Use the same ordered logical jumps on each viewport.
# Type: tuple[float, ...]. Units: fraction of maximum logical grid row. Scope: one trial.
# Consumers: measure and each renderer's target-row adapter.
# Rationale: Four separated row targets avoid no-op scrolling while preserving
# identical first visible photo identities despite different viewport adapters.
JUMPS = (0.35, 0.75, 0.12, 0.60)
# Purpose: Match the outer desktop geometry used for every renderer process.
# Type: tuple[int, int]. Units: logical pixels. Scope: one trial window.
# Consumers: run_tk and run_quick.
# Rationale: 980 by 580 is the project's default shell geometry; measured
# content footprints are recorded and validated separately.
VIEW_SIZE = (980, 580)
# Purpose: Give Qt Quick the same content surface measured in both Tk controls.
# Type: tuple[int, int]. Units: logical pixels. Scope: one paired Photos viewport.
# Consumers: run_quick. Rationale: The immutable Tk fixture measures 956 by 531
# inside the shared outer window after its native frame and shell controls.
VIEWPORT_FOOTPRINT = (956, 531)
# Purpose: Define the shared maximum wait for one production view transition.
# Type: int. Units: visible rows. Scope: paired target row selection.
# Consumers: target_row_for_fraction. Rationale: Four rows fit the measured
# 531-pixel viewport for the current shared card geometry.
SHARED_VISIBLE_ROWS = 4
# Purpose: Keep paired Tk widget targets inside the immutable control's native reach.
# Type: int. Units: photos. Scope: paired viewport positions in each trial.
# Consumers: run_tk, run_quick and benchmark report metadata.
# Rationale: Hosted #445 run 45735870818 reached only row 207 with the old
# widget grid when asked for row 248. Choosing positions in the first 1,000
# photos preserves full catalogue size and keeps targets in the shared range.
SCROLL_REACH_PHOTOS = 1000
# Purpose: Select a deep identity beyond the paired benchmark's common range.
# Type: int. Units: photo index. Scope: separate 2,000-photo navigation probe.
# Consumers: deep_navigation_targets. Rationale: 1,200 proves scroll beyond the
# 1,000-photo shared range while leaving a distinct near-end target.
DEEP_NAVIGATION_ANCHOR_INDEX = 1200
# Purpose: Name the two isolated deep-navigation observations.
# Type: tuple[str, ...]. Units: target labels. Scope: separate 2,000-photo probe.
# Consumers: deep_navigation_targets and deep-navigation report serialization.
# Rationale: One point beyond the shared range and one at the last viewport row.
DEEP_NAVIGATION_LABELS = ("beyond_shared_range", "near_end")
# Purpose: Bound waiting for observed view completion.
# Type: float. Units: seconds. Scope: one catalogue or viewport publication.
# Consumers: await_view. Rationale: Four detects a broken synthetic view while
# allowing a first native render on a Windows VM.
VIEW_SECONDS = 4.0
# Purpose: Prepare the complete paginated catalogue before measured jumps.
# Type: float. Units: seconds. Scope: one fresh Photos widget tree.
# Consumers: measure. Rationale: 60 seconds permits measuring jumps even when
# the older 2,000-row widget control takes more than 20 seconds to prepare.
CATALOGUE_SECONDS = 60.0
# Purpose: Yield between GUI observations while keeping the same sampling cadence.
# Type: float. Units: seconds. Scope: one observed native message loop.
# Consumers: await_view. Rationale: Five milliseconds balances resolution and CPU.
OBSERVE_SECONDS = 0.005
# Purpose: Keep a failed or hung renderer from consuming the whole paired cell.
# Type: int. Units: seconds. Scope: one renderer child.
# Consumers: run_comparison. Rationale: 90 matches the original #399 process
# ceiling and includes dataset preparation and independent jump observations.
CHILD_SECONDS = 90
# Purpose: Sample live renderer memory while it performs its workload.
# Type: float. Units: seconds. Scope: one child process.
# Consumers: run_comparison. Rationale: 50 ms catches sustained GUI peaks
# without materially changing the native event loop's workload.
RSS_SAMPLE_SECONDS = 0.05
# Purpose: Bound correction for Tk Canvas integer scroll-coordinate rounding.
# Type: int. Units: native view-position corrections per jump. Scope: one Tk move.
# Consumers: run_tk.move. Rationale: Three recalculations cover edge/border rounding.
CANVAS_POSITION_CALIBRATION_ATTEMPTS = 3
# Purpose: Accept an aligned target within half a native coordinate unit.
# Type: float. Units: Canvas coordinate pixels. Scope: one Tk scroll target.
# Consumers: run_tk.move. Rationale: Tk exposes integer raster positions.
CANVAS_POSITION_TOLERANCE = 0.5
# Purpose: Convert measured publication windows to per-second throughput.
# Type: int. Units: milliseconds per second. Scope: Photos publication metric.
# Consumers: measure, validation, and comparison summary.
# Rationale: Raw first-availability samples are retained in milliseconds.
MILLISECONDS_PER_SECOND = 1000


def await_view(
    pump: Callable[[], None], predicate: Callable[[], bool], seconds: float = VIEW_SECONDS,
) -> None:
    """Observe the current toolkit's settled state within the same deadline.

    Args:
        pump (Callable[[], None]): Real native event turn.
        predicate (Callable[[], bool]): Exact expected settled-state predicate.
        seconds (float): Bound for initial preparation or a measured viewport jump.
    Returns:
        None: The current view reached its expected state.
    Raises:
        RuntimeError: The predicate did not become true within this trial.
    """
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        pump()
        if predicate():
            return
        time.sleep(OBSERVE_SECONDS)
    raise RuntimeError("The viewport did not settle before its deadline.")


def selected_source_origins(
    source: Path, renderer: str,
) -> dict[str, str]:
    """Require every renderer owner to come from its exact selected checkout.

    Args:
        source (Path): Checkout passed to the isolated renderer process.
        renderer (str): Exact Tk or Qt renderer identifier.
    Returns:
        dict[str, str]: Loaded owner module names mapped to resolved source files.
    Raises:
        RuntimeError: A required module is absent or escaped its source checkout.
    """
    modules = QT_SOURCE_MODULES if renderer == "qt" else TK_SOURCE_MODULES
    source_winapp = (source.resolve() / "winapp").resolve()
    origins: dict[str, str] = {}
    for name in modules:
        module = sys.modules.get(name)
        module_file = getattr(module, "__file__", None)
        if not isinstance(module_file, str):
            raise RuntimeError(f"Selected source module was not loaded: {name}.")
        origin = Path(module_file).resolve()
        try:
            origin.relative_to(source_winapp)
        except ValueError as error:
            raise RuntimeError(
                f"Source module {name} escaped selected checkout: {origin}."
            ) from error
        origins[name] = str(origin)
    return origins


def measure(
    pump: Callable[[], None], catalogue_ready: Callable[[], bool],
    data_ready: Callable[[], bool],
    resource_published: Callable[[], bool], rendered_frame: Callable[[], bool],
    published_visible_ids: Callable[[], tuple[int, ...]],
    move: Callable[[float], int],
    visible: Callable[[], tuple[int, ...]], catalogue_count: Callable[[], int],
    warm: bool,
    started: float | None = None,
) -> dict[str, fixtures.JsonValue]:
    """Measure equivalent catalogue and viewport actions with raw trial samples.

    Args:
        pump (Callable[[], None]): Toolkit-owned real event turn.
        catalogue_ready (Callable[[], bool]): Entire ordered source catalogue arrived.
        data_ready (Callable[[], bool]): All target pixels are decoded and owned.
        resource_published (Callable[[], bool]): Target URLs/resources are exposed.
        rendered_frame (Callable[[], bool]): Native frame proxy observed after publish.
        published_visible_ids (Callable[[], tuple[int, ...]]): Actual ready IDs
            among the current visible slots or provider URLs.
        move (Callable[[float], int]): Native jump adapter returning the target
            first catalogue index for exact visible identity validation.
        visible (Callable[[], tuple[int, ...]]): Actual currently visible identities.
        catalogue_count (Callable[[], int]): Number of prepared source photos.
        warm (bool): Replay the same jumps once before collecting measured samples.
        started (float | None): Renderer setup start, or None to start after setup.
    Returns:
        dict[str, JsonValue]: Stage latencies, viewport identities, warmup,
            measured samples, and first-availability observations.
    """
    started = time.perf_counter() if started is None else started
    await_view(pump, catalogue_ready, CATALOGUE_SECONDS)
    catalogue_ready_ms = (time.perf_counter() - started) * 1000
    await_view(pump, data_ready, CATALOGUE_SECONDS)
    data_ready_ms = (time.perf_counter() - started) * 1000
    await_view(pump, resource_published)
    published_ms = (time.perf_counter() - started) * 1000
    await_view(pump, rendered_frame)
    rendered_ms = (time.perf_counter() - started) * 1000
    initial_visible = list(visible())
    timings: list[float] = []
    warmup_timings: list[float] = []
    visible_samples: list[list[int]] = []
    data_ready_samples: list[float] = []
    publication_samples: list[float] = []
    frame_samples: list[float] = []
    target_indices: list[int] = []
    throughput_samples: list[dict[str, fixtures.JsonValue]] = []
    for report in ((False, True) if warm else (True,)):
        for position in JUMPS:
            previous = visible()
            previous_ids = set(previous)
            start = time.perf_counter()
            target_index = move(position)
            first_available: dict[int, float] = {}
            expected_first_id = fixtures.FIRST_IMAGE_ID + target_index

            def observe_pump() -> None:
                """Pump one normal GUI turn and sample newly visible publication.

                Returns:
                    None: Records the first observed ready time only while the
                        exact requested first identity owns the viewport.
                """
                pump()
                observed_ms = (time.perf_counter() - start) * MILLISECONDS_PER_SECOND
                current = visible()
                if not current or current[0] != expected_first_id:
                    return
                current_new = set(current) - previous_ids
                available = set(published_visible_ids())
                for image_id in current_new & available:
                    first_available.setdefault(image_id, observed_ms)

            event_pump = observe_pump if report else pump
            await_view(event_pump, lambda: visible() != previous and data_ready())
            data_elapsed_ms = (time.perf_counter() - start) * 1000
            await_view(event_pump, resource_published)
            publication_elapsed_ms = (time.perf_counter() - start) * 1000
            await_view(event_pump, rendered_frame)
            elapsed_ms = (time.perf_counter() - start) * 1000
            sample_visible = list(visible())
            if report:
                if not sample_visible or sample_visible[0] != expected_first_id:
                    raise RuntimeError(
                        "Viewport jump did not land on its shared first identity: "
                        f"target_index={target_index}; "
                        f"expected_first={expected_first_id}; "
                        f"actual_first={sample_visible[0] if sample_visible else None}; "
                        f"actual_last={sample_visible[-1] if sample_visible else None}; "
                        f"visible_photo_count={len(sample_visible)}; "
                        f"catalogue_photo_count={catalogue_count()}."
                    )
                newly_visible = set(sample_visible) - previous_ids
                if not newly_visible or set(first_available) != newly_visible:
                    raise RuntimeError(
                        "Publication observations did not cover exact newly visible IDs: "
                        f"target_index={target_index}; "
                        f"expected={sorted(newly_visible)}; "
                        f"observed={sorted(first_available)}."
                    )
                ordered_ids = sorted(newly_visible)
                available_ms = [first_available[image_id] for image_id in ordered_ids]
                first_published_elapsed_ms = max(available_ms)
                throughput_samples.append({
                    "target_first_index": target_index,
                    "published_ids": ordered_ids,
                    "first_available_ms": available_ms,
                    "elapsed_ms": first_published_elapsed_ms,
                })
                timings.append(elapsed_ms)
                visible_samples.append(sample_visible)
                target_indices.append(target_index)
                data_ready_samples.append(data_elapsed_ms)
                publication_samples.append(publication_elapsed_ms - data_elapsed_ms)
                frame_samples.append(elapsed_ms - publication_elapsed_ms)
            else:
                warmup_timings.append(elapsed_ms)
    publication_total_ms = sum(
        float(sample["elapsed_ms"]) for sample in throughput_samples
    )
    publication_count = sum(
        len(sample["published_ids"]) for sample in throughput_samples
    )
    publication_rate = publication_count * MILLISECONDS_PER_SECOND / publication_total_ms
    return {
        "initial_data_ready_ms": data_ready_ms,
        "initial_data_ready_semantics": "Cumulative from renderer setup through decoded visible tile data; includes catalogue arrival and is not decoder-only.",
        "catalogue_ready_ms": catalogue_ready_ms,
        "initial_resource_published_ms": published_ms - data_ready_ms,
        "initial_rendered_frame_proxy_ms": rendered_ms - published_ms,
        "initial_visible_ids": initial_visible,
        "warmup_viewport_ms": warmup_timings,
        "viewport_visible_ids": visible_samples,
        "viewport_target_first_indices": target_indices,
        "viewport_settle_ms": timings,
        "viewport_data_ready_ms": data_ready_samples,
        "viewport_resource_published_ms": publication_samples,
        "viewport_rendered_frame_proxy_ms": frame_samples,
        "publication_throughput": {
            "samples": throughput_samples,
            "published_count": publication_count,
            "total_ms": publication_total_ms,
            "photos_per_second": publication_rate,
        },
        "scroll_reach_photo_count": min(catalogue_count(), SCROLL_REACH_PHOTOS),
        "scroll_reach_limit_photos": SCROLL_REACH_PHOTOS,
        "scroll_reach_note": (
            "Viewport jumps use the bounded leading range after the immutable "
            "Tk widget control missed a deeper target on the hosted VM; "
            "the complete catalogue is still loaded and measured."
        ),
    }


def deep_navigation_targets(
    photo_count: int, columns: int, visible_rows: int,
) -> tuple[tuple[str, int], ...]:
    """Select an aligned row beyond the paired range and the last viewport row.

    Args:
        photo_count (int): Full catalogue size prepared by this child.
        columns (int): Native columns in the measured viewport.
        visible_rows (int): Whole visible row count for target clamping.
    Returns:
        tuple[tuple[str, int], ...]: Named first-photo indices in distinct deep rows.
    Raises:
        RuntimeError: The requested large catalogue cannot cover both targets.
    """
    if photo_count <= SCROLL_REACH_PHOTOS or columns < 1 or visible_rows < 1:
        raise RuntimeError("Deep navigation requires a catalogue beyond the shared range.")
    total_rows = (photo_count + columns - 1) // columns
    last_row = max(0, total_rows - visible_rows)
    beyond_index = max(
        (DEEP_NAVIGATION_ANCHOR_INDEX // columns) * columns,
        ((SCROLL_REACH_PHOTOS // columns) + 1) * columns,
    )
    beyond_row = beyond_index // columns
    if beyond_row >= last_row:
        raise RuntimeError("The catalogue cannot represent two distinct deep targets.")
    return (
        (DEEP_NAVIGATION_LABELS[0], beyond_index),
        (DEEP_NAVIGATION_LABELS[1], last_row * columns),
    )


def run_tk(
    args: argparse.Namespace, bodies: tuple[bytes, ...],
) -> dict[str, fixtures.JsonValue]:
    """Run the unchanged base Photos control with one of its native renderers.

    Args:
        args (argparse.Namespace): Exact checkout, count, renderer and cache cell.
        bodies (tuple[bytes, ...]): Fixed PNG bodies shared by all renderer processes.
    Returns:
        dict[str, JsonValue]: Raw timings, exact catalogue IDs and renderer.
    """
    os.environ["PHP_GALLERY_CANVAS_GRID"] = "1" if args.child_renderer == "tk-canvas" else "0"
    fixtures.PHOTO_BODY_FIXTURES[fixtures.DEFAULT_SEED] = bodies
    fixtures.HIRES_FIXTURE_BODIES[fixtures.DEFAULT_SEED] = (bodies[0],)
    fixtures.prepare_windows_display()
    root = fixtures.tk.Tk()
    try:
        root.geometry("%dx%d" % VIEW_SIZE)
        configure_theme(root)
        setup_started = time.perf_counter()
        viewer, _session, _fixture, _state = fixtures.build_gallery(
            root, args.source, fixtures.DEFAULT_SEED, photo_count=args.count,
        )
        viewer.grid_canvas.configure(width=VIEW_SIZE[0], height=VIEW_SIZE[1])
        renderer = "tk-canvas" if getattr(viewer, "_canvas_grid", None) is not None else "tk-widgets"
        if renderer != args.child_renderer:
            raise RuntimeError("The requested Tk renderer was not active.")

        def ready() -> bool:
            """Require a complete ordered catalogue and every visible thumbnail.

            Returns:
                bool: True when the current Tk photo slots have pixels.
            """
            indices = viewer._visible_thumbnail_indices
            return (
                len(viewer._photos) == args.count and bool(indices)
                and viewer._viewport_after_id is None and viewer._events.empty()
                and all(index in viewer._thumbnail_loaded for index in indices)
            )

        def catalogue_ready() -> bool:
            """Require the complete ordered photo list before grid measurement.

            Returns:
                bool: Every requested catalogue row has arrived from the source.
            """
            return len(viewer._photos) == args.count

        def rendered() -> bool:
            """Treat Tk event-loop and idle-task completion as its frame proxy.

            Returns:
                bool: Tk has processed the presentation turn after publication.
            """
            root.update_idletasks()
            return ready()

        def pump() -> None:
            """Deliver native events and request explicit remaining catalogue pages.

            Returns:
                None: Uses the same lazy page continuation as the #399 control.
            """
            root.update()
            if 0 < len(viewer._photos) < args.count and viewer._worker is None:
                viewer._load_more()

        # Wait for the real row widgets and viewport dimensions before reading
        # geometry; initial Tk construction can report a zero-sized row.
        await_view(pump, catalogue_ready, CATALOGUE_SECONDS)
        catalogue_ready_ms = (time.perf_counter() - setup_started) * 1000
        columns = max(1, int(viewer._grid_columns))
        if renderer == "tk-canvas":
            row_pitch = (
                viewer._canvas_grid._tile_size[1]
                + 2 * viewer._canvas_grid.theme.border_inset
                + viewer._canvas_grid.gutter
            )
        else:
            first_row = viewer.grid_frame.grid_bbox(0, 0)
            second_row = viewer.grid_frame.grid_bbox(0, 1)
            row_pitch = second_row[1] - first_row[1]
        if row_pitch <= 0:
            raise RuntimeError("The settled Tk grid did not expose positive row spacing.")
        viewer.grid_canvas.yview_moveto(0.0)
        root.update_idletasks()
        leading_offset = float(viewer.grid_canvas.canvasy(0))
        viewport_height = viewer.grid_canvas.winfo_height()
        visible_rows = max(1, (viewport_height + row_pitch - 1) // row_pitch)
        reach_count = min(args.count, SCROLL_REACH_PHOTOS)
        total_rows = (reach_count + columns - 1) // columns

        def move(position: float) -> int:
            """Move Tk to the same logical first tile row used by Qt.

            Args:
                position (float): Fraction of the scrollable logical rows.
            Returns:
                int: Expected first visible catalogue index.
            """
            target_row = round(position * max(0, total_rows - visible_rows))
            target_index = target_row * columns
            return move_to_target_index(target_index)

        def move_to_target_index(target_index: int) -> int:
            """Move Tk to an explicitly aligned shared catalogue row.

            Args:
                target_index (int): First photo index of the requested row.
            Returns:
                int: Aligned first visible catalogue index.
            """
            if target_index % columns:
                raise ValueError("Tk target index must identify the first column.")
            target_row = target_index // columns
            if renderer == "tk-canvas":
                target_y = target_row * row_pitch
                content_height = viewer._canvas_grid._content_height
            else:
                target_bounds = viewer.grid_frame.grid_bbox(0, target_row)
                if target_bounds[3] <= 0:
                    raise RuntimeError(
                        f"Tk widgets did not expose target row geometry: row={target_row}."
                    )
                target_y = target_bounds[1]
                bounds = viewer.grid_canvas.bbox("all")
                content_height = max(1, bounds[3] - bounds[1])
            fraction = (target_y - leading_offset) / max(1, content_height)
            viewer.grid_canvas.yview_moveto(fraction)
            # Tk rounds Canvas view positions to integer native coordinates.
            # Correct that measured remainder without approximating from widget
            # height or content fractions alone.
            for _attempt in range(CANVAS_POSITION_CALIBRATION_ATTEMPTS):
                actual_y = float(viewer.grid_canvas.canvasy(0))
                delta_y = target_y - actual_y
                if abs(delta_y) < CANVAS_POSITION_TOLERANCE:
                    break
                current_fraction = viewer.grid_canvas.yview()[0]
                viewer.grid_canvas.yview_moveto(
                    current_fraction + delta_y / max(1, content_height),
                )
            return target_index

        def visible_ids() -> tuple[int, ...]:
            """Return identities for the current native Tk viewport slots.

            Returns:
                tuple[int, ...]: Sorted photo identities currently admitted by Tk.
            """
            return tuple(sorted(
                viewer._photos[index].image_id
                for index in viewer._visible_thumbnail_indices
            ))

        def published_visible_ids() -> tuple[int, ...]:
            """Return visible Tk IDs whose actual thumbnail slots are loaded.

            Returns:
                tuple[int, ...]: Sorted visible image identities present in
                    Tk's existing loaded-slot owner.
            """
            return tuple(sorted(
                viewer._photos[index].image_id
                for index in viewer._visible_thumbnail_indices
                if index in viewer._thumbnail_loaded
            ))

        if args.deep_navigation:
            deep_started_ms = (time.perf_counter() - setup_started) * 1000
            deep_observations: list[dict[str, fixtures.JsonValue]] = []
            for label, target_index in deep_navigation_targets(
                args.count, columns, visible_rows,
            ):
                expected_first = fixtures.FIRST_IMAGE_ID + target_index
                action_started = time.perf_counter()
                move_to_target_index(target_index)
                await_view(
                    pump,
                    lambda: bool(visible_ids())
                    and visible_ids()[0] == expected_first and ready(),
                    CATALOGUE_SECONDS,
                )
                data_ready_ms = (time.perf_counter() - action_started) * 1000
                rendered()
                rendered_elapsed_ms = (time.perf_counter() - action_started) * 1000
                actual_visible = visible_ids()
                if not actual_visible or actual_visible[0] != expected_first:
                    raise RuntimeError(
                        f"Tk deep target mismatch: label={label}; target_index={target_index}; "
                        f"expected_first={expected_first}; "
                        f"actual_first={actual_visible[0] if actual_visible else None}; "
                        f"actual_last={actual_visible[-1] if actual_visible else None}; "
                        f"visible_photo_count={len(actual_visible)}; "
                        f"catalogue_photo_count={len(viewer._photos)}."
                    )
                deep_observations.append({
                    "label": label, "target_first_index": target_index,
                    "expected_first_id": expected_first,
                    "actual_visible_ids": list(actual_visible),
                    "actual_first_id": actual_visible[0],
                    "actual_last_id": actual_visible[-1],
                    "visible_photo_count": len(actual_visible),
                    "catalogue_photo_count": len(viewer._photos),
                    "actual_canvas_top": float(viewer.grid_canvas.canvasy(0)),
                    "data_ready_ms": data_ready_ms,
                    "resource_published_ms": 0.0,
                    "rendered_idle_proxy_ms": rendered_elapsed_ms,
                })
            identities = tuple(row.image_id for row in viewer._photos)
            footprint = [viewer.grid_canvas.winfo_width(), viewer.grid_canvas.winfo_height()]
            viewer.close()
            return {
                "renderer": renderer, "graphics_api": "Tk",
                "catalogue_identities": list(identities),
                "viewport_footprint": footprint,
                "deep_navigation": deep_observations,
                "initial_full_catalogue_ready_ms": deep_started_ms,
                "catalogue_ready_ms": catalogue_ready_ms,
                "scroll_reach_photo_count": min(args.count, SCROLL_REACH_PHOTOS),
                "scroll_reach_limit_photos": SCROLL_REACH_PHOTOS,
                "dependency_versions": {
                    "tk_patchlevel": str(root.tk.call("package", "provide", "Tk")),
                },
            }

        result = measure(
            pump, catalogue_ready, ready, ready, rendered, published_visible_ids,
            move, visible_ids,
            lambda: len(viewer._photos), args.cache == "warm", setup_started,
        )
        identities = tuple(row.image_id for row in viewer._photos)
        if identities != tuple(range(fixtures.FIRST_IMAGE_ID, fixtures.FIRST_IMAGE_ID + args.count)):
            raise RuntimeError("Tk changed the fixture catalogue order.")
        footprint = [viewer.grid_canvas.winfo_width(), viewer.grid_canvas.winfo_height()]
        tk_patchlevel = str(root.tk.call("package", "provide", "Tk"))
        viewer.close()
        return {
            **result, "renderer": renderer, "graphics_api": "Tk",
            "catalogue_identities": list(identities),
            "viewport_footprint": footprint,
            "dependency_versions": {"tk_patchlevel": tk_patchlevel},
        }
    finally:
        root.destroy()


def run_quick(
    args: argparse.Namespace, profile: Path, bodies: tuple[bytes, ...],
) -> dict[str, fixtures.JsonValue]:
    """Run the production Qt Quick scene with the exact same fixture photos.

    Args:
        args (argparse.Namespace): Exact candidate, count and regime.
        profile (Path): Disposable UI profile with no account or accepted work.
        bodies (tuple[bytes, ...]): The same PNG data supplied to the Tk control.
    Returns:
        dict[str, JsonValue]: Timings, exact catalogue IDs and observed scene API.
    """
    app = QApplication([])
    apply_qt_theme(app)
    setup_started = time.perf_counter()
    viewport = QtPhotos(profile, "")
    viewport.resize(*VIEW_SIZE)
    viewport.quick.setFixedSize(*VIEWPORT_FOOTPRINT)
    session = mock.create_autospec(fixtures.LinkingSession, instance=True, spec_set=True)
    session.thumbnail_publication_current.return_value = True
    session.available.return_value = True
    photos = tuple(
        fixtures.LinkedPhoto(fixtures.FIRST_IMAGE_ID + index, f"Synthetic photo {index}")
        for index in range(args.count)
    )

    def thumbnails(
        gallery_id: int, rows: tuple[fixtures.LinkedPhoto, ...],
        publish: Callable[[ThumbnailPublication, MediaReadTimings, Callable[[], None]], None],
        cancelled: Event, *, active_photos: Callable[[tuple[int, ...]], None] | None = None,
    ) -> None:
        """Feed deterministic data at the already-authorized fixture boundary.

        Args:
            gallery_id (int): Single synthetic gallery selected for this trial.
            rows (tuple[LinkedPhoto, ...]): Exact current viewport descriptors.
            publish (Callable[[ThumbnailPublication, MediaReadTimings, Callable[[], None]], None]):
                Actual decoder's callback used to transfer one complete item.
            cancelled (Event): Lane generation cancellation.
            active_photos (Callable[[tuple[int, ...]], None] | None): Exact IDs
                in the current fixture read, with an empty terminal observation.
        Returns:
            None: Delivers only owned photos with this case's fixed image bytes.
        """
        if active_photos is not None:
            active_photos(tuple(row.image_id for row in rows))
        try:
            for row in rows:
                if cancelled.is_set():
                    return
                body = bodies[row.image_id - fixtures.FIRST_IMAGE_ID]
                validator = '"' + sha256(body).hexdigest() + '"'
                witness = ThumbnailBatchWitness(
                    session, "synthetic", gallery_id, row, "public",
                    (validator, time.monotonic()), None, 0,
                )
                publish(ThumbnailPublication(
                    witness, row, "ok", body, "image/png", validator, True,
                ), MediaReadTimings(), lambda: None)
        finally:
            if active_photos is not None:
                active_photos(())

    session.media_thumbnails.side_effect = thumbnails
    viewport._session = session
    viewport._identity = ("synthetic", fixtures.GALLERY_ID)
    viewport._gallery_id = fixtures.GALLERY_ID
    viewport._sync_connection_state()
    try:
        viewport.show()
        rendered_frames = [0]
        previous_frame = [0]
        window = viewport.quick.quickWindow()
        window.afterRendering.connect(
            lambda: rendered_frames.__setitem__(0, rendered_frames[0] + 1),
        )
        viewport.toggle_order()
        viewport._install_catalogue(tuple(
            PhotoRow(row.image_id, row.title, row) for row in reversed(photos)
        ))
        def visible_textures_ready() -> bool:
            """Require decoded and published textures for every wanted identity.

            Returns:
                bool: Current viewport has a provider URL for each visible photo.
            """
            return bool(viewport._wanted) and set(viewport._wanted) <= viewport.model.texture_urls.keys()

        def visible_data_ready() -> bool:
            """Require decoded tile data before the model exposes its URLs.

            Returns:
                bool: Every wanted identity has a decoded tile image.
            """
            return bool(viewport._wanted) and set(viewport._wanted) <= viewport._tiles.keys()

        grid = viewport.quick.rootObject().findChild(QQuickItem, "photosGrid")
        if grid is None:
            raise RuntimeError("The Photos GridView objectName is missing.")
        columns = max(1, int(grid.width() // grid.property("cellWidth")))
        cell_height = float(grid.property("cellHeight"))
        visible_rows = max(1, (int(grid.height()) + int(cell_height) - 1) // int(cell_height))
        reach_count = min(args.count, SCROLL_REACH_PHOTOS)
        total_rows = (reach_count + columns - 1) // columns

        def move(position: float) -> int:
            """Move Qt to the shared logical first tile row.

            Args:
                position (float): Fraction of the scrollable logical rows.
            Returns:
                int: Expected first visible catalogue index.
            """
            target_row = round(position * max(0, total_rows - visible_rows))
            target_index = target_row * columns
            return move_to_target_index(target_index)

        def move_to_target_index(target_index: int) -> int:
            """Set Qt GridView to an explicitly aligned catalogue row.

            Args:
                target_index (int): First photo index of the requested row.
            Returns:
                int: Aligned first visible catalogue index.
            """
            if target_index % columns:
                raise ValueError("Qt target index must identify the first column.")
            target_row = target_index // columns
            previous_frame[0] = rendered_frames[0]
            grid.setProperty("contentY", target_row * cell_height)
            return target_index

        def visible_ids() -> tuple[int, ...]:
            """Return sorted logical identities presently assigned to QML tiles.

            Returns:
                tuple[int, ...]: Current visible photo identities from QtPhotos.
            """
            return tuple(sorted(viewport._wanted))

        def published_visible_ids() -> tuple[int, ...]:
            """Return visible Qt IDs with provider URLs exposed by the model.

            Returns:
                tuple[int, ...]: Sorted visible identities whose texture URLs
                    are currently observable through the exact model owner.
            """
            return tuple(sorted(set(viewport._wanted) & viewport.model.texture_urls.keys()))

        if args.deep_navigation:
            deep_started = setup_started
            await_view(
                app.processEvents,
                lambda: len(viewport.model.rows) == args.count,
                CATALOGUE_SECONDS,
            )
            catalogue_ready_ms = (time.perf_counter() - deep_started) * 1000
            await_view(app.processEvents, visible_data_ready, CATALOGUE_SECONDS)
            initial_data_ready_ms = (time.perf_counter() - deep_started) * 1000
            await_view(app.processEvents, visible_textures_ready)
            initial_published_ms = (time.perf_counter() - deep_started) * 1000
            await_view(app.processEvents, lambda: rendered_frames[0] > 0)
            initial_frame_ms = (time.perf_counter() - deep_started) * 1000
            observations: list[dict[str, fixtures.JsonValue]] = []
            for label, target_index in deep_navigation_targets(
                args.count, columns, visible_rows,
            ):
                expected_first = fixtures.FIRST_IMAGE_ID + target_index
                action_started = time.perf_counter()
                move_to_target_index(target_index)
                await_view(
                    app.processEvents,
                    lambda: bool(visible_ids()) and visible_ids()[0] == expected_first
                    and visible_data_ready(),
                    CATALOGUE_SECONDS,
                )
                data_ready_ms = (time.perf_counter() - action_started) * 1000
                await_view(app.processEvents, visible_textures_ready)
                published_ms = (time.perf_counter() - action_started) * 1000
                await_view(
                    app.processEvents,
                    lambda: rendered_frames[0] > previous_frame[0],
                )
                rendered_ms = (time.perf_counter() - action_started) * 1000
                actual_visible = visible_ids()
                if not actual_visible or actual_visible[0] != expected_first:
                    raise RuntimeError(
                        f"Qt deep target mismatch: label={label}; target_index={target_index}; "
                        f"expected_first={expected_first}; "
                        f"actual_first={actual_visible[0] if actual_visible else None}; "
                        f"actual_last={actual_visible[-1] if actual_visible else None}; "
                        f"visible_photo_count={len(actual_visible)}; "
                        f"catalogue_photo_count={len(viewport.model.rows)}."
                    )
                observations.append({
                    "label": label, "target_first_index": target_index,
                    "expected_first_id": expected_first,
                    "actual_visible_ids": list(actual_visible),
                    "actual_first_id": actual_visible[0],
                    "actual_last_id": actual_visible[-1],
                    "visible_photo_count": len(actual_visible),
                    "catalogue_photo_count": len(viewport.model.rows),
                    "actual_content_y": float(grid.property("contentY")),
                    "data_ready_ms": data_ready_ms,
                    "resource_published_ms": published_ms - data_ready_ms,
                    "rendered_frame_proxy_ms": rendered_ms - published_ms,
                })
            identities = tuple(row.identity for row in viewport.model.rows)
            if viewport.quick.grabFramebuffer().isNull():
                raise RuntimeError("Qt did not retain a framebuffer after deep navigation.")
            graphics = viewport.quick.quickWindow().rendererInterface().graphicsApi().name
            return {
                "renderer": "qt", "graphics_api": graphics, "qt_version": qVersion(),
                "catalogue_identities": list(identities),
                "viewport_footprint": [viewport.quick.width(), viewport.quick.height()],
                "deep_navigation": observations,
                "initial_full_catalogue_ready_ms": catalogue_ready_ms,
                "catalogue_ready_ms": catalogue_ready_ms,
                "initial_resource_published_ms": initial_published_ms - initial_data_ready_ms,
                "initial_rendered_frame_proxy_ms": initial_frame_ms - initial_published_ms,
                "scroll_reach_photo_count": min(args.count, SCROLL_REACH_PHOTOS),
                "scroll_reach_limit_photos": SCROLL_REACH_PHOTOS,
                "dependency_versions": dependency_versions(),
            }

        result = measure(
            app.processEvents,
            lambda: len(viewport.model.rows) == args.count,
            visible_data_ready, visible_textures_ready,
            lambda: rendered_frames[0] > previous_frame[0], published_visible_ids,
            move,
            visible_ids,
            lambda: len(viewport.model.rows), args.cache == "warm", setup_started,
        )
        identities = tuple(row.identity for row in viewport.model.rows)
        expected = tuple(range(fixtures.FIRST_IMAGE_ID, fixtures.FIRST_IMAGE_ID + args.count))
        if identities != expected:
            raise RuntimeError("Qt changed the fixture catalogue identity order.")
        if viewport.quick.grabFramebuffer().isNull():
            raise RuntimeError("Qt did not render a live framebuffer.")
        graphics = viewport.quick.quickWindow().rendererInterface().graphicsApi().name
        return {
            **result, "renderer": "qt", "graphics_api": graphics,
            "qt_version": qVersion(), "catalogue_identities": list(identities),
            "viewport_footprint": [viewport.quick.width(), viewport.quick.height()],
            "dependency_versions": dependency_versions(),
        }
    finally:
        viewport.shutdown()
        viewport.close()


def run_child(args: argparse.Namespace) -> dict[str, fixtures.JsonValue]:
    """Run one fresh process with its own temporary profile and source revision.

    Args:
        args (argparse.Namespace): Validated synthetic workload and renderer.
    Returns:
        dict[str, JsonValue]: Raw viewport timings, run identity and resources.
    """
    bodies = fixtures.make_photo_bodies(fixtures.DEFAULT_SEED, args.count)
    digest = sha256(b"".join(sha256(body).digest() for body in bodies)).hexdigest()
    with tempfile.TemporaryDirectory(prefix="issue-445-profile-") as folder:
        for key in ("APPDATA", "LOCALAPPDATA", "USERPROFILE"):
            os.environ[key] = folder
        cpu_started = time.process_time()
        result = run_quick(args, Path(folder), bodies) if args.child_renderer == "qt" else run_tk(args, bodies)
        source_origins = selected_source_origins(args.source, args.child_renderer)
        return {
            **result, "source": args.source_id, "count": args.count, "cache": args.cache,
            "dataset_sha256": digest, "cpu_ms": (time.process_time() - cpu_started) * 1000,
            "rss_bytes": psutil.Process().memory_info().rss,
            "source_origins": source_origins,
        }


def validate_source_origins(
    data: dict[str, fixtures.JsonValue], source: Path, renderer: str,
) -> None:
    """Verify raw evidence attributes each app owner to the selected checkout.

    Args:
        data (dict[str, JsonValue]): Raw child report from one renderer process.
        source (Path): Exact baseline or candidate checkout selected by the parent.
        renderer (str): Exact Tk or Qt renderer identifier.
    Returns:
        None: Every required module resolves below the selected checkout's WinApp.
    Raises:
        RuntimeError: A required origin is missing, extra, or outside the source.
    """
    origins = data.get("source_origins")
    required = QT_SOURCE_MODULES if renderer == "qt" else TK_SOURCE_MODULES
    if not isinstance(origins, dict) or set(origins) != set(required):
        raise RuntimeError(f"{renderer} did not report its exact source module owners.")
    selected_winapp = (source.resolve() / "winapp").resolve()
    for name in required:
        value = origins.get(name)
        if not isinstance(value, str):
            raise RuntimeError(f"{renderer} source origin is invalid: {name}.")
        try:
            Path(value).resolve().relative_to(selected_winapp)
        except (OSError, ValueError) as error:
            raise RuntimeError(
                f"{renderer} source module {name} escaped the selected checkout."
            ) from error


def validate_publication_throughput(
    data: dict[str, fixtures.JsonValue], cache: str,
) -> None:
    """Validate exact per-ID first availability and its measured rate.

    Args:
        data (dict[str, JsonValue]): Raw child report and exact visible-ID samples.
        cache (str): The paired cold or warm regime for this process.
    Returns:
        None: Four nonempty matched jump windows have finite first-publication data.
    Raises:
        RuntimeError: Any newly visible ID, timestamp, rate, or aggregate is invalid.
    """
    throughput = data.get("publication_throughput")
    visible_samples = data.get("viewport_visible_ids")
    targets = data.get("viewport_target_first_indices")
    initial_visible = data.get("initial_visible_ids")
    if (not isinstance(throughput, dict) or not isinstance(visible_samples, list)
            or not isinstance(targets, list) or not isinstance(initial_visible, list)):
        raise RuntimeError("Child omitted publication throughput or viewport identity evidence.")
    samples = throughput.get("samples")
    if (not isinstance(samples, list) or len(samples) != len(JUMPS)
            or len(visible_samples) != len(JUMPS) or len(targets) != len(JUMPS)):
        raise RuntimeError("Publication throughput must contain one sample per measured jump.")
    if (any(not isinstance(value, int) or isinstance(value, bool) for value in targets)
            or any(not isinstance(value, int) or isinstance(value, bool)
                   for value in initial_visible)
            or any(not isinstance(row, list)
                   or any(not isinstance(value, int) or isinstance(value, bool)
                          for value in row)
                   for row in visible_samples)):
        raise RuntimeError("Publication target and initial visible IDs must be integers.")
    # Warm trials replay the exact four jumps, so the final warm viewport is
    # also the measured sequence's first-jump baseline.
    previous_ids = (
        visible_samples[-1] if cache == "warm" else initial_visible
    )
    total_count = 0
    elapsed_windows: list[float] = []
    for index, (sample, current_ids, target) in enumerate(
        zip(samples, visible_samples, targets, strict=True),
    ):
        if not isinstance(sample, dict) or not isinstance(current_ids, list):
            raise RuntimeError(f"Publication sample {index} is malformed.")
        if any(not isinstance(value, int) or isinstance(value, bool) for value in current_ids):
            raise RuntimeError(f"Publication sample {index} contains invalid visible IDs.")
        published_ids = sample.get("published_ids")
        first_available = sample.get("first_available_ms")
        elapsed = sample.get("elapsed_ms")
        sample_target = sample.get("target_first_index")
        expected_ids = sorted(set(current_ids) - set(previous_ids))
        if (not isinstance(sample_target, int) or isinstance(sample_target, bool)
                or sample_target != target
                or not expected_ids or published_ids != expected_ids
                or not isinstance(first_available, list)
                or len(first_available) != len(expected_ids)):
            raise RuntimeError(
                f"Publication sample {index} did not cover exact newly exposed IDs."
            )
        times = [float(value) for value in first_available
                 if isinstance(value, (int, float)) and not isinstance(value, bool)]
        if (len(times) != len(expected_ids)
                or any(not isfinite(value) or value <= 0 for value in times)
                or not isinstance(elapsed, (int, float)) or isinstance(elapsed, bool)
                or not isfinite(float(elapsed)) or float(elapsed) <= 0
                or not isclose(float(elapsed), max(times))):
            raise RuntimeError(f"Publication sample {index} has invalid first-availability times.")
        if target < 0 or not current_ids or current_ids[0] != fixtures.FIRST_IMAGE_ID + target:
            raise RuntimeError(f"Publication sample {index} does not match its target identity.")
        total_count += len(expected_ids)
        elapsed_windows.append(float(elapsed))
        previous_ids = current_ids
    total_ms = sum(elapsed_windows)
    expected_rate = total_count * MILLISECONDS_PER_SECOND / total_ms
    reported_count = throughput.get("published_count")
    reported_total = throughput.get("total_ms")
    reported_rate = throughput.get("photos_per_second")
    if (not isinstance(reported_count, int) or isinstance(reported_count, bool)
            or reported_count != total_count or not isinstance(reported_total, (int, float))
            or isinstance(reported_total, bool) or not isfinite(float(reported_total))
            or float(reported_total) <= 0
            or not isclose(float(reported_total), total_ms)
            or not isinstance(reported_rate, (int, float))
            or isinstance(reported_rate, bool) or not isfinite(float(reported_rate))
            or float(reported_rate) <= 0
            or not isclose(float(reported_rate), expected_rate)):
        raise RuntimeError("Child publication throughput aggregate is inconsistent.")


def run_comparison(args: argparse.Namespace) -> int:
    """Collect twelve fresh paired renderer groups for this exact case.

    Args:
        args (argparse.Namespace): Hosted output, workload size and regime.
    Returns:
        int: Zero after all independent renderer samples are valid.
    """
    baseline = os.environ["PHP_GALLERY_TK_PERF_BASELINE"]
    candidate = (
        os.environ.get("PHP_GALLERY_ISSUE_445_CANDIDATE")
        or os.environ.get("PHP_GALLERY_TK_PERF_CANDIDATE", "")
    )
    output = args.output
    output.mkdir(parents=True, exist_ok=True)
    all_runs: list[dict[str, fixtures.JsonValue]] = []
    deep_navigation_runs: list[dict[str, fixtures.JsonValue]] = []
    summary: dict[str, fixtures.JsonValue] = {}
    publication_summary: dict[str, fixtures.JsonValue] = {
        "renderers": {}, "paired": {}, "units": "photos per second",
        "scope": "Not available because the paired workload did not complete.",
    }
    import_throughput: dict[str, fixtures.JsonValue] = {
        "status": "NOT_RUN",
        "scope": "Runs only in the 2,000-photo warm benchmark cell.",
    }
    failure = ""
    try:
        if baseline != IMMUTABLE_TK_SHA:
            raise RuntimeError("The Tk control is not the immutable issue 445 baseline.")
        expected_candidate = (
            os.environ.get("PHP_GALLERY_ISSUE_445_CANDIDATE")
            or os.environ.get("GITHUB_SHA", "")
        )
        actual_head = subprocess.run(
            ["git", "rev-parse", "HEAD"], check=False, capture_output=True,
            text=True, cwd=Path.cwd(),
        )
        if (not candidate or candidate != expected_candidate or actual_head.returncode != 0
                or actual_head.stdout.strip() != candidate):
            raise RuntimeError("The Qt source is not the exact definitive workflow HEAD SHA.")
        parent_dependency_versions = dependency_versions()
        with tempfile.TemporaryDirectory(prefix="issue-445-sources-") as folder:
            reference = Path(folder)
            extract_commit(baseline, reference)
            for pair in range(GROUPS):
                order = ORDERS[pair % len(ORDERS)]
                order_index = pair // len(ORDERS)
                for renderer in order:
                    target = Path.cwd() if renderer == "qt" else reference
                    source = candidate if renderer == "qt" else baseline
                    stem = f"group-{pair:02d}-{renderer}"
                    report = output / f"{stem}.json"
                    process = subprocess.Popen([
                        sys.executable, str(Path(__file__).resolve()),
                        "--child-renderer", renderer, "--source", str(target),
                        "--source-id", source, "--count", str(args.count),
                        "--cache", args.cache, "--output", str(report),
                    ], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    child = psutil.Process(process.pid)
                    peak_rss = 0
                    started = time.monotonic()
                    try:
                        peak_rss = child.memory_info().rss
                    except psutil.Error:
                        pass
                    while process.poll() is None:
                        try:
                            peak_rss = max(peak_rss, child.memory_info().rss)
                        except psutil.Error:
                            pass
                        if time.monotonic() - started > CHILD_SECONDS:
                            process.kill()
                            process.communicate()
                            raise subprocess.TimeoutExpired(process.args, CHILD_SECONDS)
                        time.sleep(RSS_SAMPLE_SECONDS)
                    _stdout, stderr = process.communicate()
                    (output / f"{stem}.stderr.txt").write_bytes(stderr)
                    if process.returncode != 0 or not report.is_file():
                        raise RuntimeError(f"{stem} did not produce a successful child report.")
                    data = json.loads(report.read_text(encoding="utf-8"))
                    if renderer != "qt":
                        child_versions = data.get("dependency_versions")
                        tk_patchlevel = (
                            child_versions.get("tk_patchlevel")
                            if isinstance(child_versions, dict) else None
                        )
                        data["dependency_versions"] = {
                            **parent_dependency_versions,
                            "tk_patchlevel": tk_patchlevel,
                        }
                    data["peak_rss_bytes"] = peak_rss
                    validate_source_origins(data, target, renderer)
                    validate_publication_throughput(data, args.cache)
                    if (data.get("renderer") != renderer or data.get("source") != source
                            or data.get("cache") != args.cache or data.get("count") != args.count
                            or data.get("viewport_footprint") != list(VIEWPORT_FOOTPRINT)
                            or len(data.get("viewport_settle_ms", ())) != len(JUMPS)
                            or len(data.get("viewport_data_ready_ms", ())) != len(JUMPS)
                            or len(data.get("viewport_resource_published_ms", ())) != len(JUMPS)
                            or len(data.get("viewport_rendered_frame_proxy_ms", ())) != len(JUMPS)
                            or len(data.get("viewport_visible_ids", ())) != len(JUMPS)
                            or data.get("scroll_reach_photo_count") != min(args.count, SCROLL_REACH_PHOTOS)
                            or data.get("scroll_reach_limit_photos") != SCROLL_REACH_PHOTOS
                            or not isinstance(data.get("catalogue_ready_ms"), (int, float))
                            or not isinstance(data.get("initial_data_ready_ms"), (int, float))
                            or len(data.get("warmup_viewport_ms", ())) != (len(JUMPS) if args.cache == "warm" else 0)
                            or data.get("catalogue_identities") != list(range(
                                fixtures.FIRST_IMAGE_ID,
                                fixtures.FIRST_IMAGE_ID + args.count,
                            ))
                            or all_runs and data["dataset_sha256"] != all_runs[0]["dataset_sha256"]):
                        raise RuntimeError(f"{stem} did not match the exact workload.")
                    all_runs.append({"pair": pair, "order_index": order_index, **data})
                group_runs = {
                    str(run["renderer"]): run for run in all_runs
                    if run["pair"] == pair
                }
                if set(group_runs) != set(RENDERERS):
                    raise RuntimeError(f"Group {pair} is missing a renderer sample.")
                reference_run = group_runs[RENDERERS[0]]
                reference_publication = reference_run["publication_throughput"]
                for renderer in RENDERERS[1:]:
                    other_run = group_runs[renderer]
                    if (other_run["initial_visible_ids"] != reference_run["initial_visible_ids"]
                            or other_run["viewport_visible_ids"] != reference_run["viewport_visible_ids"]
                            or other_run["viewport_target_first_indices"] != reference_run["viewport_target_first_indices"]):
                        raise RuntimeError(f"Group {pair} does not share exact viewport identities.")
                    if other_run["viewport_footprint"] != reference_run["viewport_footprint"]:
                        raise RuntimeError(f"Group {pair} does not share the same viewport pixel footprint.")
                    other_publication = other_run["publication_throughput"]
                    reference_samples = reference_publication["samples"]
                    other_samples = other_publication["samples"]
                    if (other_publication["published_count"]
                            != reference_publication["published_count"]
                            or [sample["published_ids"] for sample in other_samples]
                            != [sample["published_ids"] for sample in reference_samples]):
                        raise RuntimeError(
                            f"Group {pair} does not share publication ID coverage."
                        )
            if args.count == 2000 and args.cache == "warm":
                for renderer in ("tk-canvas", "qt"):
                    target = Path.cwd() if renderer == "qt" else reference
                    source = candidate if renderer == "qt" else baseline
                    stem = f"deep-navigation-{renderer}"
                    report = output / f"{stem}.json"
                    process = subprocess.Popen([
                        sys.executable, str(Path(__file__).resolve()),
                        "--child-renderer", renderer, "--deep-navigation",
                        "--source", str(target), "--source-id", source,
                        "--count", str(args.count), "--cache", args.cache,
                        "--output", str(report),
                    ], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    child = psutil.Process(process.pid)
                    peak_rss = 0
                    started = time.monotonic()
                    try:
                        peak_rss = child.memory_info().rss
                    except psutil.Error:
                        pass
                    while process.poll() is None:
                        try:
                            peak_rss = max(peak_rss, child.memory_info().rss)
                        except psutil.Error:
                            pass
                        if time.monotonic() - started > CHILD_SECONDS:
                            process.kill()
                            process.communicate()
                            raise subprocess.TimeoutExpired(process.args, CHILD_SECONDS)
                        time.sleep(RSS_SAMPLE_SECONDS)
                    _stdout, stderr = process.communicate()
                    (output / f"{stem}.stderr.txt").write_bytes(stderr)
                    if process.returncode != 0 or not report.is_file():
                        raise RuntimeError(f"{stem} did not produce a successful child report.")
                    data = json.loads(report.read_text(encoding="utf-8"))
                    if renderer != "qt":
                        child_versions = data.get("dependency_versions")
                        tk_patchlevel = (
                            child_versions.get("tk_patchlevel")
                            if isinstance(child_versions, dict) else None
                        )
                        data["dependency_versions"] = {
                            **parent_dependency_versions,
                            "tk_patchlevel": tk_patchlevel,
                        }
                    data["peak_rss_bytes"] = peak_rss
                    validate_source_origins(data, target, renderer)
                    expected_identities = list(range(
                        fixtures.FIRST_IMAGE_ID,
                        fixtures.FIRST_IMAGE_ID + args.count,
                    ))
                    observations = data.get("deep_navigation", ())
                    if (data.get("renderer") != renderer or data.get("source") != source
                            or data.get("count") != args.count or data.get("cache") != args.cache
                            or data.get("viewport_footprint") != list(VIEWPORT_FOOTPRINT)
                            or data.get("catalogue_identities") != expected_identities
                            or data.get("scroll_reach_photo_count") != SCROLL_REACH_PHOTOS
                            or len(observations) != len(DEEP_NAVIGATION_LABELS)):
                        raise RuntimeError(f"{stem} did not match the exact deep-navigation workload.")
                    for observation, expected_label in zip(
                        observations, DEEP_NAVIGATION_LABELS, strict=True,
                    ):
                        if (observation.get("label") != expected_label
                                or observation.get("target_first_index", 0) <= SCROLL_REACH_PHOTOS
                                or observation.get("actual_first_id") != observation.get("expected_first_id")
                                or observation.get("visible_photo_count", 0) < 1
                                or observation.get("catalogue_photo_count") != args.count):
                            raise RuntimeError(
                                f"{stem} failed exact deep identity validation for {expected_label}."
                            )
                    if observations[-1].get("actual_last_id") != expected_identities[-1]:
                        raise RuntimeError(f"{stem} did not reach the final catalogue identity.")
                    deep_navigation_runs.append(data)
                if (deep_navigation_runs[-2]["deep_navigation"][0]["target_first_index"]
                        != deep_navigation_runs[-1]["deep_navigation"][0]["target_first_index"]
                        or deep_navigation_runs[-2]["deep_navigation"][1]["target_first_index"]
                        != deep_navigation_runs[-1]["deep_navigation"][1]["target_first_index"]):
                    raise RuntimeError("Canvas and Qt deep navigation targets are not the same IDs.")
            if args.count == 2000 and args.cache == "warm":
                try:
                    import_throughput = import_benchmark.run_import_comparison(
                        reference, Path.cwd(), candidate, baseline, output, GROUPS,
                    )
                    if (import_throughput.get("status") != "PASS"
                            or import_throughput.get("group_count") != GROUPS
                            or import_throughput.get("candidate_sha") != candidate
                            or import_throughput.get("baseline_sha") != baseline
                            or len(import_throughput.get("samples", ())) != GROUPS):
                        raise RuntimeError("The paired Import throughput comparison is incomplete.")
                except (OSError, RuntimeError, ValueError, subprocess.TimeoutExpired) as error:
                    # The cell still fails. Keep independent Photos sample evidence for diagnosis.
                    failure = str(error)
                    import_throughput = {"status": "FAIL", "failure": failure}
        by_group = {
            group: {run["renderer"]: run for run in all_runs if run["pair"] == group}
            for group in range(GROUPS)
        }
        for group, runs in by_group.items():
            if set(runs) != set(RENDERERS):
                raise RuntimeError(f"Group {group} is missing a renderer sample.")
            references = runs[RENDERERS[0]]
            for renderer in RENDERERS[1:]:
                candidate_run = runs[renderer]
                if (candidate_run["initial_visible_ids"] != references["initial_visible_ids"]
                        or candidate_run["viewport_visible_ids"] != references["viewport_visible_ids"]
                        or candidate_run["catalogue_identities"] != references["catalogue_identities"]):
                    raise RuntimeError(f"Group {group} does not share exact visible identities.")
                if candidate_run["viewport_footprint"] != references["viewport_footprint"]:
                    raise RuntimeError(f"Group {group} does not share the same viewport pixel footprint.")
        timings = {
            renderer: [max(by_group[group][renderer]["viewport_settle_ms"]) for group in range(GROUPS)]
            for renderer in RENDERERS
        }
        publication_rates = {
            renderer: [
                float(by_group[group][renderer]["publication_throughput"]["photos_per_second"])
                for group in range(GROUPS)
            ]
            for renderer in RENDERERS
        }
        publication_summary: dict[str, fixtures.JsonValue] = {
            "renderers": {
                renderer: dict(describe(rates))
                for renderer, rates in publication_rates.items()
            },
            "paired": {},
            "units": "photos per second",
            "scope": (
                "First availability of newly visible IDs in four measured target jumps; "
                "excludes setup, initial viewport, warmup, and offscreen or stale IDs. "
                "Tk observes its loaded thumbnail slots; Qt observes exposed provider URLs. "
                "This is not a network or GPU throughput metric."
            ),
        }
        paired_publication = publication_summary["paired"]
        assert isinstance(paired_publication, dict)
        for control in RENDERERS[:-1]:
            deltas = [
                100 * (qt / tk - 1)
                for qt, tk in zip(
                    publication_rates["qt"], publication_rates[control], strict=True,
                )
            ]
            paired_publication[control] = {
                "percent_delta": dict(describe(deltas)),
                "confidence_95_percent": bootstrap_delta_ci(
                    deltas, f"{args.count}/{args.cache}/publication/{control}",
                ),
            }
        for renderer, sample in timings.items():
            summary[renderer] = dict(describe(sample))
            renderer_runs = [run for run in all_runs if run["renderer"] == renderer]
            for metric in (
                "catalogue_ready_ms", "initial_data_ready_ms",
                "initial_resource_published_ms",
                "initial_rendered_frame_proxy_ms",
            ):
                summary[f"{renderer}-{metric}"] = dict(describe([
                    float(run[metric]) for run in renderer_runs
                ]))
        for control in RENDERERS[:-1]:
            deltas = [qt - tk for qt, tk in zip(timings["qt"], timings[control], strict=True)]
            summary[f"qt-minus-{control}"] = {
                "delta_ms": dict(describe(deltas)),
                "confidence_95_ms": bootstrap_delta_ci(deltas, f"{args.count}/{args.cache}/{control}"),
                "paired_groups": GROUPS,
                "p99_interpretation": "Descriptive only: 12 group maxima cannot support a stable tail estimate.",
            }
            for phase in (
                "viewport_data_ready_ms", "viewport_resource_published_ms",
                "viewport_rendered_frame_proxy_ms",
            ):
                phase_deltas = [
                    max(by_group[group]["qt"][phase]) - max(by_group[group][control][phase])
                    for group in range(GROUPS)
                ]
                summary[f"qt-minus-{control}-{phase}"] = {
                    "delta_ms": dict(describe(phase_deltas)),
                    "confidence_95_ms": bootstrap_delta_ci(
                        phase_deltas, f"{args.count}/{args.cache}/{control}/{phase}",
                    ),
                    "paired_groups": GROUPS,
                    "p99_interpretation": "Stage maxima over 12 groups are descriptive only.",
                }
    except (OSError, RuntimeError, ValueError, subprocess.TimeoutExpired) as error:
        failure = str(error)
    (output / "report.json").write_text(json.dumps({
        "schema_version": 1, "stage": "compare",
        "status": "FAIL" if failure else "PASS",
        "candidate_sha": candidate, "baseline_sha": baseline,
        "harness_sha256": sha256(Path(__file__).read_bytes()).hexdigest(),
        "count": args.count, "cache": args.cache, "group_count": GROUPS,
        "viewport_size": list(VIEW_SIZE),
        "expected_viewport_footprint": list(VIEWPORT_FOOTPRINT),
        "scroll_reach": {
            "photo_count": min(args.count, SCROLL_REACH_PHOTOS),
            "limit_photos": SCROLL_REACH_PHOTOS,
            "jump_fractions": list(JUMPS),
            "scope": "Viewport targets are bounded to the leading range; every trial still prepares the full catalogue.",
            "reason": "The immutable Tk widget control reaches its native coordinate ceiling beyond the bounded range.",
        },
        "order_permutations": [list(order) for order in ORDERS],
        "deep_navigation": {
            "status": "PASS" if len(deep_navigation_runs) == 2 else "NOT_RUN",
            "scope": "Separate fresh Canvas and Qt child processes after the 2,000-photo catalogue arrives; each deep target is a first visit, excluded from paired groups and summary metrics.",
            "cache_policy": "The paired cell is selected as warm, but these independent children do not replay warmups; only their initial viewport is visited before deep movement.",
            "required_positions": list(DEEP_NAVIGATION_LABELS),
            "shared_scroll_range_photos": SCROLL_REACH_PHOTOS,
            "runs": deep_navigation_runs,
        },
        "runs": all_runs, "summary_metrics": summary,
        "publication_throughput": publication_summary,
        "import_throughput": import_throughput,
        "host": {**host_metadata(), "platform": platform.platform(), "python": sys.version},
        "dependency_versions": dependency_versions(),
        "data_ready_semantics": "Cumulative from renderer setup through decoded visible tile data; includes catalogue arrival and is not decoder-only.",
        "frame_proxy": "QQuickWindow.afterRendering; scene graph completed, physical presentation unobserved. Tk records an idle/event-loop completion proxy.",
        "summary": failure or "Valid paired samples; rendered scene graph is a proxy for physical display presentation.",
    }, indent=2, allow_nan=False), encoding="utf-8")
    with (output / "samples.csv").open("w", newline="", encoding="utf-8") as stream:
        fields = ("pair", "order_index", "renderer", "source", "count", "cache",
                  "dataset_sha256", "catalogue_ready_ms", "initial_data_ready_ms",
                  "initial_resource_published_ms", "initial_rendered_frame_proxy_ms",
                  "peak_rss_bytes", "viewport_max_ms", "viewport_samples_ms",
                  "viewport_footprint", "initial_visible_ids", "viewport_visible_ids",
                  "viewport_data_ready_ms", "viewport_resource_published_ms",
                  "viewport_rendered_frame_proxy_ms", "warmup_viewport_ms",
                  "publication_samples", "publication_published_count",
                  "publication_total_ms", "publication_photos_per_second",
                  "dependency_versions",
                  "scroll_reach_photo_count", "scroll_reach_limit_photos",
                  "viewport_target_first_indices", "scroll_reach_note")
        writer = csv.DictWriter(stream, fieldnames=fields)
        writer.writeheader()
        for run in all_runs:
            writer.writerow({
                "pair": run["pair"], "order_index": run["order_index"],
                "renderer": run["renderer"], "source": run["source"],
                "count": run["count"], "cache": run["cache"],
                "dataset_sha256": run["dataset_sha256"],
                "catalogue_ready_ms": run["catalogue_ready_ms"],
                "initial_data_ready_ms": run["initial_data_ready_ms"],
                "initial_resource_published_ms": run["initial_resource_published_ms"],
                "initial_rendered_frame_proxy_ms": run["initial_rendered_frame_proxy_ms"],
                "peak_rss_bytes": run["peak_rss_bytes"],
                "viewport_max_ms": max(run["viewport_settle_ms"]),
                "viewport_samples_ms": json.dumps(run["viewport_settle_ms"]),
                "viewport_footprint": json.dumps(run["viewport_footprint"]),
                "initial_visible_ids": json.dumps(run["initial_visible_ids"]),
                "viewport_visible_ids": json.dumps(run["viewport_visible_ids"]),
                "viewport_data_ready_ms": json.dumps(run["viewport_data_ready_ms"]),
                "viewport_resource_published_ms": json.dumps(run["viewport_resource_published_ms"]),
                "viewport_rendered_frame_proxy_ms": json.dumps(run["viewport_rendered_frame_proxy_ms"]),
                "warmup_viewport_ms": json.dumps(run["warmup_viewport_ms"]),
                "publication_samples": json.dumps(
                    run["publication_throughput"]["samples"],
                ),
                "publication_published_count": run["publication_throughput"]["published_count"],
                "publication_total_ms": run["publication_throughput"]["total_ms"],
                "publication_photos_per_second": run["publication_throughput"]["photos_per_second"],
                "dependency_versions": json.dumps(run["dependency_versions"], sort_keys=True),
                "scroll_reach_photo_count": run["scroll_reach_photo_count"],
                "scroll_reach_limit_photos": run["scroll_reach_limit_photos"],
                "viewport_target_first_indices": json.dumps(run["viewport_target_first_indices"]),
                "scroll_reach_note": run["scroll_reach_note"],
            })
    with (output / "deep-navigation.csv").open(
        "w", newline="", encoding="utf-8",
    ) as stream:
        fields = (
            "renderer", "source", "count", "cache", "graphics_api",
            "label", "target_first_index", "expected_first_id", "actual_first_id",
            "actual_last_id", "visible_photo_count", "catalogue_photo_count",
            "actual_position", "data_ready_ms", "resource_published_ms",
            "rendered_proxy_ms", "peak_rss_bytes",
        )
        writer = csv.DictWriter(stream, fieldnames=fields)
        writer.writeheader()
        for run in deep_navigation_runs:
            for observation in run["deep_navigation"]:
                writer.writerow({
                    "renderer": run["renderer"], "source": run["source"],
                    "count": run["count"], "cache": run["cache"],
                    "graphics_api": run.get("graphics_api", ""),
                    "label": observation["label"],
                    "target_first_index": observation["target_first_index"],
                    "expected_first_id": observation["expected_first_id"],
                    "actual_first_id": observation["actual_first_id"],
                    "actual_last_id": observation["actual_last_id"],
                    "visible_photo_count": observation["visible_photo_count"],
                    "catalogue_photo_count": observation["catalogue_photo_count"],
                    "actual_position": observation.get(
                        "actual_canvas_top", observation.get("actual_content_y", ""),
                    ),
                    "data_ready_ms": observation["data_ready_ms"],
                    "resource_published_ms": observation["resource_published_ms"],
                    "rendered_proxy_ms": observation.get(
                        "rendered_idle_proxy_ms", observation.get("rendered_frame_proxy_ms", ""),
                    ),
                    "peak_rss_bytes": run["peak_rss_bytes"],
                })
    return int(bool(failure))


def main() -> int:
    """Admit the GitHub-owned paired cell or its isolated renderer child.

    Returns:
        int: Zero for a valid result and nonzero for incomplete evidence.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path)
    parser.add_argument("--source-id")
    parser.add_argument("--child-renderer", choices=RENDERERS)
    parser.add_argument("--deep-navigation", action="store_true")
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--count", type=int, choices=PHOTO_CASES,
                        default=os.environ.get("PHP_GALLERY_GUI_PHOTO_COUNT"))
    parser.add_argument("--cache", choices=("cold", "warm"),
                        default=os.environ.get("PHP_GALLERY_GUI_CACHE"))
    args = parser.parse_args()
    if (os.environ.get("GITHUB_ACTIONS") != "true" or sys.platform != "win32"
            or args.count not in PHOTO_CASES or args.cache not in ("cold", "warm")):
        parser.error("An exact hosted Windows workload cell is required.")
    if args.child_renderer is None:
        return run_comparison(args)
    args.output.write_text(json.dumps(run_child(args), allow_nan=False), encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
