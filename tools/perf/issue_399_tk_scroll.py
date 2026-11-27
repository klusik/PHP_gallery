#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_399_tk_scroll.py
# Module Type: Virtualized Gallery Baseline Harness
# Purpose: Measure large Photos catalogue mounting and scroll interactions.
# Responsibilities:
#   - Mount the production Photos viewer with deterministic offline metadata
#   - Exercise real Tk wheel, viewport and resize event paths in isolation
#   - Retain bounded timing evidence and reject invalid catalogue observations
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Measure production Photos mount, page loading and scroll input."""

import argparse
import json
import math
import os
import platform
import tempfile
import time
import threading
import tkinter as tk
import traceback
from types import TracebackType
from pathlib import Path
from typing import NotRequired, TypedDict

import issue_361_tk_workloads as common
from PIL import ImageGrab, __version__ as PILLOW_VERSION
import psutil

class LifecycleAfterCounts(TypedDict):
    """Retain whole-process scheduled-callback counts around one viewer close."""

    before_close: int
    after_close: NotRequired[int]


class LifecycleState(TypedDict):
    """Describe the concrete lifecycle evidence accumulated by the harness."""

    index: int
    mount_started_ns: int
    gallery_ids: list[int]
    timings_ms: dict[str, float]
    worker_joins: int
    old_callbacks: int
    postclose_image_counts: list[int]
    after_counts: list[LifecycleAfterCounts]


# Purpose: Throttle diagnostic progress writes without hiding page/action milestones.
# Type: int. Units: page reads or completed actions.
# Scope: one issue 399 child checkpoint sidecar.
# Consumers: run_interactions and poll_catalogue progress checkpoints.
# Rationale: eight-unit checkpoints expose slow phases with few filesystem writes.
PROGRESS_CHECKPOINT_INTERVAL = 8

# Purpose: Map public scenarios to deterministic synthetic catalogue sizes.
# Type: dict[str, int]. Units: photos. Scope: one child process.
# Consumers: parse_args and run. Rationale: fixed sizes make source pairs comparable.
PHOTO_COUNTS = {
    "scroll100": 100, "scroll500": 500,
    "scroll2000": 2000, "scroll5000": 5000,
}
# Purpose: Bound one process below the central child deadline.
# Type: int. Units: seconds. Scope: mount and interaction phases.
# Consumers: poll_catalogue, await_settle. Rationale: leave teardown time inside 90 seconds.
CHILD_DEADLINE_SECONDS = 80
# Purpose: Yield asynchronous readiness checks to Tk's regular event loop.
# Type: int. Units: milliseconds. Scope: page and viewport settling.
# Consumers: poll_catalogue, await_settle. Rationale: bounded checks avoid nested update loops.
POLL_INTERVAL_MS = 10
# Purpose: Compare normalized Tk viewport fractions without treating rounding as movement.
# Type: float. Units: fraction of total canvas height. Scope: one wheel event.
# Consumers: wheel movement assertions. Rationale: yview fractions are floating-point values.
WHEEL_MOVEMENT_TOLERANCE = 0.000001
# Purpose: Identify the terminal yview fraction for boundary wheel inputs.
# Type: float. Units: normalized yview fraction. Scope: wheel boundary check.
# Consumers: wheel movement assertions. Rationale: Tk reports the final visible fraction as one.
WHEEL_BOTTOM_FRACTION = 1.0
# Purpose: Keep the initial mounted client width identical across sources.
# Type: int. Units: logical pixels. Scope: root window setup and warm replay.
# Consumers: INITIAL_GEOMETRY. Rationale: 900 pixels fit the qualified hosted desktop.
INITIAL_WIDTH_PX = 900
# Purpose: Keep a fixed client height throughout the resize trace.
# Type: int. Units: logical pixels. Scope: root window setup, reset and resize.
# Consumers: INITIAL_GEOMETRY and run_interactions. Rationale: isolate width changes.
WINDOW_HEIGHT_PX = 680
# Purpose: Keep the exact warm replay's starting root geometry.
# Type: str. Units: Tk geometry syntax. Scope: gallery window reset.
# Consumers: warm replay. Rationale: replay starts from the mounted default viewport.
# The structural +0+0 places the window at the desktop origin without auto-offset.
INITIAL_GEOMETRY = f"{INITIAL_WIDTH_PX}x{WINDOW_HEIGHT_PX}+0+0"
# Purpose: Bound copied Tk callback traceback text.
# Type: int. Units: characters. Scope: one callback failure.
# Consumers: callback_exception. Rationale: retain useful frames within evidence bounds.
CALLBACK_TRACEBACK_LIMIT = 4000
# Purpose: Set worker delay for selected stable thumbnail IDs.
# Type: tuple[tuple[int, float], ...]. Units: image IDs and seconds.
# Scope: every warm/cold data source. Consumers: delayed_media_photo.
# Rationale: deterministic short delays exercise async worker publication.
DELAYED_RESPONSE_SECONDS = (
    (common.FIRST_IMAGE_ID + 1, 0.004),
    (common.FIRST_IMAGE_ID + 2, 0.016),
)
# Purpose: Bound fixture image detail variety.
# Type: int. Units: generated hi-res bodies. Scope: one synthetic source seed.
# Consumers: common.HIRES_FIXTURE_BODIES. Rationale: reuse the established two-body fixture.
HIRES_VARIANT_COUNT = common.HIRES_FIXTURE_VARIANTS
# Purpose: Preserve the Windows runner's peak working-set measurement.
# Type: str. Units: psutil memory field. Scope: one process resource report.
# Consumers: paired RSS metric. Rationale: peak_wset reports actual process high-water memory.
PEAK_MEMORY_FIELD = "peak_wset"
# Purpose: Pace consecutive drag commands without settling after each command.
# Type: int. Units: milliseconds. Scope: one continuous drag burst.
# Consumers: run_interactions. Rationale: preserve actual repeated input pressure.
DRAG_INTERVAL_MS = 16
# Purpose: Limit each representative switch/reopen mount to a bounded fixture.
# Type: int. Units: photos. Scope: lifecycle-only gallery sample.
# Consumers: run_lifecycle. Rationale: two pages exercise real protocol pagination at low cost.
LIFECYCLE_PHOTO_COUNT = 100
# Purpose: Set the exact successful switch and reopen transition count.
# Type: int. Units: gallery transitions. Scope: post-measurement lifecycle phase.
# Consumers: lifecycle completion assertion. Rationale: one switch and two reopens are required.
LIFECYCLE_TRANSITION_COUNT = 3
# Purpose: Join an owned viewer worker during teardown.
# Type: float. Units: seconds. Scope: one retired media worker.
# Consumers: run finally block. Rationale: bound orderly cancellation evidence.
WORKER_JOIN_SECONDS = 1.0
# Purpose: Bound retained timing records.
# Type: int. Units: observations. Scope: one measured child report.
# Consumers: report validation. Rationale: preserve raw events without unbounded evidence.
RAW_EVENT_LIMIT = common.RAW_EVENT_LIMIT
# Purpose: Pace intentionally separated slow wheel events.
# Type: int. Units: milliseconds. Scope: each slow wheel input.
# Consumers: interaction_plan. Rationale: allow distinct user events to settle.
SLOW_WHEEL_INTERVAL_MS = 100
# Purpose: Space events in a continuous wheel burst.
# Type: int. Units: milliseconds. Scope: queued fast wheel inputs.
# Consumers: interaction_plan. Rationale: preserve a short repeat cadence.
FAST_WHEEL_INTERVAL_MS = 10
# Purpose: Define slow and fast wheel event counts and directions.
# Type: tuple[int, int, int, int]. Units: input events. Scope: one trace replay.
# Consumers: interaction_plan. Rationale: four down/up slow inputs and twelve down/four up fast inputs.
SLOW_WHEEL_DOWN_COUNT = 4
SLOW_WHEEL_UP_COUNT = 4
FAST_WHEEL_DOWN_COUNT = 12
FAST_WHEEL_UP_COUNT = 4
# Purpose: Bound smooth scrollbar movement samples in one drag.
# Type: int. Units: moveto commands. Scope: one continuous drag sequence.
# Consumers: interaction_plan. Rationale: sixteen ordered positions span the rail.
DRAG_SAMPLE_COUNT = 16
# Purpose: Preserve Tk's Shift modifier for synthetic tile selection.
# Type: int. Units: Tk state bitmask. Scope: shift-click input.
# Consumers: run_interactions. Rationale: Tk ShiftMask selects the range gesture.
TK_SHIFT_MASK = 0x0001
# Purpose: Specify repeatable viewport jump positions.
# Type: tuple[float, ...]. Units: normalized canvas fractions. Scope: one scenario.
# Consumers: interaction_plan. Rationale: exercise quarter, midpoint, near-end and top.
JUMP_FRACTIONS = (0.25, 0.50, 0.90, 0.0)
# Purpose: Define the two responsive widths used after input samples.
# Type: tuple[int, int]. Units: logical pixels. Scope: root window.
# Consumers: run_interactions. Rationale: fit narrow and wide layouts within standard hosted desktops.
RESIZE_WIDTHS = (640, 960)


def parse_args(
    argv: list[str] | None = None, *, capacity: bool = False,
) -> argparse.Namespace:
    """Parse the Issue 361 compatible child interface.

    Args:
        argv (list[str] | None): Argument vector without executable name.
        capacity (bool): Admit explicit cache treatment and renderer in the
            separate capacity workload only.

    Returns:
        argparse.Namespace: Validated scenario and source identity.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True)
    parser.add_argument("--source-id", required=True)
    parser.add_argument("--seed", type=int, default=common.DEFAULT_SEED)
    parser.add_argument("--scenario", choices=tuple(PHOTO_COUNTS), required=True)
    parser.add_argument("--cache", choices=("cold", "warm"), required=True)
    parser.add_argument("--trial", type=int, required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--instrumentation", choices=("on", "off"), required=True)
    parser.add_argument("--fixture-bundle", required=True)
    parser.add_argument("--fixture-bundle-sha256", required=True)
    parser.add_argument("--fixture-bundle-photo-count", type=int, required=True)
    parser.add_argument("--fixture-common-sha256", required=True)
    parser.add_argument("--progress", required=True)
    if capacity:
        parser.add_argument(
            "--cache-renderer", choices=("native", "canvas"), required=True,
        )
        parser.add_argument(
            "--capacity-policy", choices=("small", "shipped"), required=True,
        )
        parser.add_argument(
            "--capacity-phase", choices=("sweep", "reopen"), required=True,
        )
    args = parser.parse_args(argv)
    if args.seed < 0 or args.trial < 0:
        parser.error("seed and trial must be nonnegative")
    if not Path(args.source).is_dir():
        parser.error("--source must be an application root directory")
    if not Path(args.output).is_absolute():
        parser.error("--output must be an absolute path")
    if not Path(args.fixture_bundle).is_absolute():
        parser.error("--fixture-bundle must be an absolute path")
    if not Path(args.progress).is_absolute():
        parser.error("--progress must be an absolute path")
    if not (PHOTO_COUNTS[args.scenario] <= args.fixture_bundle_photo_count
            <= max(PHOTO_COUNTS.values())):
        parser.error("--fixture-bundle-photo-count must cover the requested bounded extent")
    for digest_name in ("fixture_bundle_sha256", "fixture_common_sha256"):
        digest = getattr(args, digest_name)
        if (len(digest) != 64
                or any(character not in "0123456789abcdef" for character in digest)):
            parser.error(f"--{digest_name.replace('_', '-')} must be lowercase SHA-256")
    return args



# Purpose: Bound failure snapshots to nearby viewport indices.
# Type: int. Units: photo indices. Scope: one failure snapshot.
# Consumers: capture_failure_snapshot. Rationale: preserve local evidence without large reports.
DIAGNOSTIC_INDEX_LIMIT = 32
# Purpose: Bound each copied diagnostic string.
# Type: int. Units: characters. Scope: one failure snapshot.
# Consumers: capture_failure_snapshot. Rationale: renderer diagnostics contain fixed counters.
DIAGNOSTIC_TEXT_LIMIT = 1200
# Purpose: Bound copied Tk timer callback descriptions.
# Type: int. Units: callbacks. Scope: one failure snapshot.
# Consumers: capture_failure_snapshot. Rationale: retain short scheduling evidence only.
DIAGNOSTIC_AFTER_LIMIT = 12
# Purpose: Bound each copied Tk timer callback script.
# Type: int. Units: characters. Scope: one callback name.
# Consumers: capture_failure_snapshot. Rationale: avoid retaining arbitrary Tcl script text.
DIAGNOSTIC_AFTER_TEXT_LIMIT = 160


# Purpose: Bound copied Tk callback failure text.
# Type: int. Units: characters. Scope: one failed callback.
# Consumers: callback_exception. Rationale: retain context while bounding the report.
CALLBACK_FAILURE_LIMIT = 1000
# Purpose: Define deterministic keyboard and tile selection operations.
# Type: tuple[str, ...]. Units: operation names. Scope: each replay.
# Consumers: interaction_plan. Rationale: exercise click selection and keyboard focus.
SELECTION_ACTIONS = ("click", "shift_click", "key_right", "key_space")


def invoke_scrollbar_moveto(
    root: tk.Tk, viewer: common.NativeViewerContract, fraction: float,
) -> None:
    """Invoke the mounted production scrollbar command with a target fraction.

    Args:
        root (tk.Tk): Tk interpreter that owns the scrollbar Tcl command.
        viewer (common.NativeViewerContract): Mounted production viewer.
        fraction (float): Normalized target position from zero through one.

    Returns:
        None: The exact grid scrollbar command receives the moveto request.

    Raises:
        RuntimeError: The production scrollbar has no usable Tcl command.
    """
    command = root.tk.splitlist(viewer.grid_scrollbar.cget("command"))
    if not command:
        raise RuntimeError("Grid scrollbar has no Tcl command.")
    root.tk.call(*command, "moveto", str(fraction))

def count_native_widgets(root: tk.Misc) -> int:
    """Count actual Tk widget instances below the scenario root.

    Args:
        root (tk.Misc): Mounted root whose current native widget descendants count.

    Returns:
        int: Number of actual Tk widget objects, excluding Canvas logical items.
    """
    pending = list(root.winfo_children())
    total = 0
    while pending:
        widget = pending.pop()
        if isinstance(widget, tk.Widget):
            total += 1
        pending.extend(widget.winfo_children())
    return total


# Purpose: List only application timers owned by the Photos controller.
# Type: tuple[str, ...]. Units: viewer attribute names. Scope: lifecycle teardown checks.
# Consumers: owned_after_ids. Rationale: do not mistake shared Theme/root callbacks for viewer leaks.
VIEWER_AFTER_ID_ATTRIBUTES = (
    "_after_id", "_viewport_after_id", "_thumbnail_gui_after_id",
    "_revision_timer", "_thumbnail_resume_after_id", "_slideshow_id",
    "_audio_poll_id", "_canvas_after_id",
)


def focus_matches(
    root: tk.Tk, viewer: common.NativeViewerContract, index: int,
) -> bool:
    """Check expected focus for native tiles or the explicit Canvas adapter.

    Args:
        root (tk.Tk): Tk root that reports the actual keyboard focus widget.
        viewer (common.NativeViewerContract): Mounted Photos viewer.
        index (int): Expected photo index owning keyboard focus.

    Returns:
        bool: True only when the active renderer reports the exact expected focus.
    """
    canvas_grid = getattr(viewer, "_canvas_grid", None)
    if canvas_grid is None:
        return (
            0 <= index < len(viewer._tile_buttons)
            and root.focus_get() is viewer._tile_buttons[index]
        )
    return (
        root.focus_get() is viewer.grid_canvas
        and getattr(canvas_grid, "focused_index", None) == index
    )


def owned_after_ids(viewer: common.NativeViewerContract) -> set[str]:
    """Collect currently active timer IDs from the viewer's own lifecycle fields.

    Args:
        viewer (common.NativeViewerContract): Viewer whose owned timers are checked.

    Returns:
        set[str]: Active Tcl after IDs exposed by the current viewer contract.
    """
    return {
        value for name in VIEWER_AFTER_ID_ATTRIBUTES
        if isinstance((value := getattr(viewer, name, None)), str)
    }


def run(args: argparse.Namespace) -> dict[str, common.JsonValue]:
    """Measure exact catalogue loading and deterministic native input actions.

    Args:
        args (argparse.Namespace): Validated child configuration.

    Returns:
        dict[str, common.JsonValue]: Issue 361 compatible structured evidence.
    """
    count = PHOTO_COUNTS[args.scenario]
    source_root = Path(args.source).resolve()
    child_started_ns = time.perf_counter_ns()
    started_ns = child_started_ns
    recorder = common.Recorder(args.instrumentation == "on")
    root: tk.Tk | None = None
    viewer = None
    callback_failures: list[str] = []
    terminal = {"complete": False}
    watchdog_after_id: str | None = None
    primary_cpu_elapsed_ns: int | None = None
    cpu_measure_started_ns: list[int | None] = [None]
    primary_peak_working_set_bytes: float | None = None
    primary_operation_counts: dict[str, int] = {}
    lifecycle_state: LifecycleState = {
        "index": 0,
        "mount_started_ns": 0,
        "gallery_ids": [],
        "timings_ms": {},
        "worker_joins": 0,
        "old_callbacks": 0,
        "postclose_image_counts": [],
        "after_counts": [],
    }
    lifecycle_current_state: list[common.GalleryFixtureState | None] = [None]
    temporary = tempfile.TemporaryDirectory(prefix="php-gallery-issue399-")
    profile_names = ("APPDATA", "LOCALAPPDATA", "USERPROFILE", "PHP_GALLERY_CANVAS_GRID")
    previous_profiles = {key: os.environ.get(key) for key in profile_names}
    for key in profile_names:
        profile = Path(temporary.name) / "profile" / key.lower()
        profile.mkdir(parents=True, exist_ok=True)
        if key == "PHP_GALLERY_CANVAS_GRID":
            os.environ[key] = "1"
        else:
            os.environ[key] = str(profile)
    metrics: dict[str, float] = {}
    operations: dict[str, int] = {
        "page_reads": 0, "actions": 0, "wheel_requested_count": 0,
        "wheel_applied_count": 0, "wheel_boundary_count": 0,
        "wheel_nonedge_requested_count": 0, "wheel_nonedge_applied_count": 0,
        "lifecycle_transitions_requested": 0,
        "lifecycle_transitions_completed": 0,
    }
    resources: dict[str, common.JsonValue] = {}
    resize_observations: list[dict[str, int | str]] = []
    action_context: dict[str, common.JsonValue] = {
        "name": "setup", "index": -1,
    }
    fixture_state: list[common.GalleryFixtureState | None] = [None]
    fixture_photos: tuple[bytes, ...] = ()
    fixture_hires: tuple[bytes, ...] = ()
    fixture_dataset_sha256: str | None = None
    last_checkpoint_pages = {"value": 0}
    last_checkpoint_actions = {"value": 0}

    def progress_checkpoint(phase: str, force: bool = False) -> None:
        """Atomically write one bounded issue 399 timeout diagnostic snapshot.

        Args:
            phase (str): Fixed lifecycle phase name from the driver allowlist.
            force (bool): Write even when the phase name did not change.

        Returns:
            None: Replaces the small progress sidecar with current counters.
        """
        if phase == progress_phase["value"] and not force:
            return
        progress_phase["value"] = phase
        progress_path = Path(args.progress)
        page_reads = 0
        if fixture_state[0] is not None:
            page_reads = int(fixture_state[0]["page_reads"])
        checkpoint: dict[str, common.JsonValue] = {
            "source_sha": args.source_id,
            "scenario": args.scenario,
            "cache": args.cache,
            "instrumentation": args.instrumentation,
            "trial": args.trial,
            "seed": args.seed,
            "phase": phase,
            "elapsed_seconds": (
                time.perf_counter_ns() - child_started_ns
            ) / 1_000_000_000,
            "photo_count": count,
            "loaded_photos": len(viewer._photos) if viewer is not None else 0,
            "page_reads": page_reads,
            "actions": operations["actions"],
            "raw_event_count": len(recorder.events),
            "raw_events_truncated": recorder.truncated,
            "fixture_bundle_sha256": args.fixture_bundle_sha256,
            "dataset_sha256": fixture_dataset_sha256,
        }
        temporary_path: Path | None = None
        try:
            with tempfile.NamedTemporaryFile(
                mode="w",
                encoding="utf-8",
                dir=progress_path.parent,
                prefix=progress_path.name + ".",
                suffix=".tmp",
                delete=False,
            ) as stream:
                temporary_path = Path(stream.name)
                stream.write(
                    json.dumps(checkpoint, sort_keys=True, allow_nan=False) + "\n"
                )
            temporary_path.replace(progress_path)
        except Exception:
            if temporary_path is not None:
                temporary_path.unlink(missing_ok=True)
            raise

    progress_phase = {"value": ""}

    def capture_failure_snapshot(reason: str) -> None:
        """Store bounded application and Tk state before failure teardown.

        Args:
            reason (str): Failure stage that requested the diagnostic capture.

        Returns:
            None: Adds one JSON-safe snapshot to the child resources report.
        """
        snapshot: dict[str, common.JsonValue] = {
            "reason": reason[:DIAGNOSTIC_TEXT_LIMIT],
            "action": dict(action_context),
        }
        try:
            if root is not None:
                snapshot["root_geometry"] = str(root.geometry())[:DIAGNOSTIC_TEXT_LIMIT]
                snapshot["screen_size"] = [
                    root.winfo_screenwidth(), root.winfo_screenheight(),
                ]
                snapshot["wm_maxsize"] = list(root.maxsize())
                try:
                    timer_ids = tuple(root.tk.splitlist(root.tk.call("after", "info")))
                    callbacks: list[str] = []
                    for timer_id in timer_ids[:DIAGNOSTIC_AFTER_LIMIT]:
                        try:
                            timer_info = tuple(root.tk.splitlist(
                                root.tk.call("after", "info", timer_id),
                            ))
                            if timer_info:
                                callbacks.append(
                                    str(timer_info[0])[:DIAGNOSTIC_AFTER_TEXT_LIMIT]
                                )
                        except tk.TclError:
                            callbacks.append("<unavailable>")
                    snapshot["pending_after_count"] = len(timer_ids)
                    snapshot["pending_after_callbacks"] = callbacks
                except tk.TclError:
                    snapshot["pending_after_callbacks"] = "unavailable"
            if viewer is not None:
                visible = set(viewer._visible_thumbnail_indices)
                admitted = set(viewer._admitted_thumbnail_indices)
                loaded = set(viewer._thumbnail_loaded)
                missing_visible = visible - loaded
                snapshot.update({
                    "gallery_id": int(viewer.gallery.gallery_id),
                    "photo_count": len(viewer._photos),
                    "visible_count": len(visible),
                    "visible_indices": sorted(visible)[:DIAGNOSTIC_INDEX_LIMIT],
                    "admitted_count": len(admitted),
                    "admitted_indices": sorted(admitted)[:DIAGNOSTIC_INDEX_LIMIT],
                    "loaded_count": len(loaded),
                    "loaded_indices": sorted(loaded)[:DIAGNOSTIC_INDEX_LIMIT],
                    "missing_visible_count": len(missing_visible),
                    "missing_visible_indices": sorted(missing_visible)[:DIAGNOSTIC_INDEX_LIMIT],
                    "worker_kind": str(viewer._worker_kind)[:DIAGNOSTIC_TEXT_LIMIT],
                    "worker_alive": bool(
                        viewer._worker is not None and viewer._worker.is_alive()
                    ),
                    "event_queue_size": int(viewer._events.qsize()),
                    "thumbnail_pending_count": len(viewer._thumbnail_pending),
                    "thumbnail_gui_after_id": viewer._thumbnail_gui_after_id,
                    "viewport_after_id": viewer._viewport_after_id,
                    "warm_cache_hits": int(viewer._thumbnail_warm_cache_hit_count),
                    "retained_cache_hits": int(viewer._thumbnail_retained_hit_count),
                    "render_diagnostics": str(viewer.render_diagnostics())[
                        :DIAGNOSTIC_TEXT_LIMIT
                    ],
                })
            current_state = fixture_state[0]
            if current_state is not None:
                snapshot["page_reads"] = int(current_state["page_reads"])
        except Exception as error:
            snapshot["snapshot_error"] = (
                f"{type(error).__name__}: {str(error)}"
            )[:DIAGNOSTIC_TEXT_LIMIT]
        resources["failure_snapshot"] = snapshot
    raw: list[common.JsonValue] = []
    missing_metrics = ["physical_presentation_ms"]
    status, failure = "FAIL", None
    metadata: dict[str, common.JsonValue] = {
        "scenario": args.scenario, "cache": args.cache, "trial": args.trial,
        "source": args.source_id, "seed": args.seed,
        "instrumentation": args.instrumentation,
        "renderer_request": "canvas-experiment",
        "focus_semantics": (
            "native tile identity or Canvas focused_index; no accessibility claim"
        ),
    }
    metadata["setup_metric_semantics"] = (
        "bounded callback/image operation aggregates during mount and catalogue setup; "
        "excluded from measured raw event vectors"
    )
    cpu_started_ns: int | None = None
    metadata["setup_aggregate_metrics"] = sorted(common.SETUP_AGGREGATE_METRICS)
    metadata["operation_count_semantics"] = (
        "actual setup plus measured calls; warm trace priming and lifecycle excluded"
    )
    metadata["startup_event_loop_drift"] = (
        "not sampled; startup callback costs have fixed aggregate summaries"
    )
    recorder.phase = "setup"
    try:
        progress_checkpoint("fixture_load_started", force=True)
        fixture_load_started_ns = time.perf_counter_ns()
        fixture_photos, fixture_hires, fixture_dataset_sha256 = (
            common.load_fixture_bundle(
                Path(args.fixture_bundle),
                args.fixture_bundle_sha256,
                args.seed,
                count,
                args.fixture_bundle_photo_count,
                args.fixture_common_sha256,
            )
        )
        metrics["fixture_load_ms"] = (
            time.perf_counter_ns() - fixture_load_started_ns
        ) / common.NS_PER_MS
        metadata.update({
            "fixture_bundle_sha256": args.fixture_bundle_sha256,
            "fixture_dataset_sha256": fixture_dataset_sha256,
            "fixture_common_sha256": args.fixture_common_sha256,
            "fixture_load_ms": metrics["fixture_load_ms"],
            "fixture_load_semantics": (
                "validated archive hash and exact requested photo prefix plus hi-res bytes"
            ),
        })
        common.PHOTO_BODY_FIXTURES[args.seed] = fixture_photos
        common.HIRES_FIXTURE_BODIES[args.seed] = fixture_hires
        started_ns = time.perf_counter_ns()
        progress_checkpoint("fixtures_ready")
        cpu_started_ns = time.process_time_ns()
        root = tk.Tk()
        root.geometry(INITIAL_GEOMETRY)
        root.title("PHP Gallery issue 399 scroll baseline")
        largest_resize_width = max(RESIZE_WIDTHS)
        screen_width = root.winfo_screenwidth()
        max_width = root.maxsize()[0]
        host_width_limit = min(screen_width, max_width)
        if largest_resize_width > host_width_limit:
            raise RuntimeError(
                "Host display cannot support the requested resize trace: "
                f"needs {largest_resize_width}px, width limit is "
                f"{host_width_limit}px (screen={screen_width}px, "
                f"window_max={max_width}px)."
            )

        def callback_exception(
            exception_type: type[BaseException],
            error: BaseException,
            callback_traceback: TracebackType | None,
        ) -> None:
            """Capture Tk callback errors and terminate through the Tk owner.

            Args:
                exception_type (type[BaseException]): Raised callback class.
                error (BaseException): Original callback error.
                callback_traceback (TracebackType | None): Tk supplied traceback value.

            Returns:
                None: Bounded failure evidence is saved and mainloop quits.
            """
            callback_failures.append(
                f"{exception_type.__name__}: {str(error)[:CALLBACK_FAILURE_LIMIT]}",
            )
            capture_failure_snapshot("tk_callback_exception")
            if callback_traceback is not None:
                callback_failures.append("".join(traceback.format_exception(
                    exception_type, error, callback_traceback,
                ))[-CALLBACK_TRACEBACK_LIMIT:])
            root.quit()

        root.report_callback_exception = callback_exception
        recorder.install()
        mount_started = time.perf_counter_ns()
        viewer, session, fixture, state = common.build_gallery(
            root, source_root, args.seed, photo_count=count,
        )
        fixture_state[0] = state
        if fixture["dataset_sha256"] != fixture_dataset_sha256:
            raise RuntimeError("Loaded fixture dataset digest differs from mounted gallery.")
        progress_checkpoint("tk_mounted")
        metrics["first_viewport_mount_ms"] = (
            time.perf_counter_ns() - mount_started
        ) / 1_000_000
        common.wrap_publication(viewer, recorder)
        original_media_photo = session.media_photo.side_effect

        def delayed_media_photo(
            gallery_id: int, image_id: int, thumbnail: bool,
            cancelled: threading.Event | None = None, *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: "common.MediaReadTimings | None" = None,
            use_preloaded_snapshot: bool = False,
        ) -> tuple[bytes, str]:
            """Delay fixed IDs before returning the original synthetic response.

            Args:
                gallery_id (int): Requested gallery identity.
                image_id (int): Requested photo identity.
                thumbnail (bool): Whether the request asks for a thumbnail.
                cancelled (threading.Event | None): Production cancellation token.
                cache_eligible (bool | None): Existing cache admission policy.
                thumbnail_etag (str | None): Existing thumbnail validator.
                thumbnail_verified_at (float | None): Existing proof timestamp.
                preload_eligible (bool | None): Existing preload admission.
                preload_thumbnail_etag (str | None): Existing preload validator.
                preload_verified_at (float | None): Existing preload proof.
                timings (common.MediaReadTimings | None): Optional read timing owner.
                use_preloaded_snapshot (bool): Whether a preloaded source is requested.

            Returns:
                tuple[bytes, str]: Original synthetic body and media type.

            Raises:
                Exception: Propagates the original fixture read failure.
            """
            delay = dict(DELAYED_RESPONSE_SECONDS).get(image_id)
            if delay is not None:
                time.sleep(delay)
            return original_media_photo(
                gallery_id, image_id, thumbnail, cancelled,
                cache_eligible=cache_eligible,
                thumbnail_etag=thumbnail_etag,
                thumbnail_verified_at=thumbnail_verified_at,
                preload_eligible=preload_eligible,
                preload_thumbnail_etag=preload_thumbnail_etag,
                preload_verified_at=preload_verified_at,
                timings=timings,
                use_preloaded_snapshot=use_preloaded_snapshot,
            )

        session.media_photo.side_effect = delayed_media_photo
        phase_started_ns = time.perf_counter_ns()
        deadline = time.monotonic() + CHILD_DEADLINE_SECONDS
        first_page_recorded = {"done": False}
        action_plan: list[tuple[str, float]] = []
        action_plan.extend(
            ("wheel_slow", -1.0) for _ in range(SLOW_WHEEL_DOWN_COUNT)
        )
        action_plan.extend(
            ("wheel_slow", 1.0) for _ in range(SLOW_WHEEL_UP_COUNT)
        )
        action_plan.extend(
            ("wheel_fast", -1.0) for _ in range(FAST_WHEEL_DOWN_COUNT)
        )
        action_plan.extend(
            ("wheel_fast", 1.0) for _ in range(FAST_WHEEL_UP_COUNT)
        )
        action_plan.extend(
            ("drag", index / DRAG_SAMPLE_COUNT)
            for index in range(1, DRAG_SAMPLE_COUNT + 1)
        )
        action_plan.extend(("jump", position) for position in JUMP_FRACTIONS)
        action_plan.extend(("resize", width) for width in RESIZE_WIDTHS)
        action_plan.extend((name, 0.0) for name in SELECTION_ACTIONS)
        replay_round = {"value": 0}
        input_samples: dict[str, list[int]] = {}
        action_index = {"value": 0}
        burst_started_ns = {"value": None}

        lifecycle_targets = (
            common.SECOND_GALLERY_ID,
            common.GALLERY_ID,
            common.GALLERY_ID,
        )
        lifecycle_old_after_ids: list[set[str]] = [set()]
        lifecycle_before_close_after_count: list[int] = [0]
        lifecycle_old_frame: list[tk.Widget | None] = [None]
        lifecycle_old_viewer: list[common.NativeViewerContract | None] = [None]
        lifecycle_finalizing = [False]

        def after_identifiers() -> set[str]:
            """Return currently scheduled Tk after callback identities.

            Returns:
                set[str]: Identifiers owned by the current Tcl interpreter.
            """
            result = root.tk.call("after", "info")
            return set(root.tk.splitlist(result))

        def retire_current(finalize: bool = False) -> None:
            """Close the current gallery and verify its callbacks and worker retire.

            Args:
                finalize (bool): Whether this is the final lifecycle close.

            Returns:
                None: Schedules an idle-bound cleanup verification.
            """
            owner = viewer
            if owner is None:
                raise RuntimeError("Lifecycle has no mounted viewer to close.")
            lifecycle_finalizing[0] = finalize
            lifecycle_old_viewer[0] = owner
            lifecycle_old_frame[0] = owner.frame
            all_after_ids = after_identifiers()
            lifecycle_before_close_after_count[0] = len(all_after_ids)
            lifecycle_old_after_ids[0] = owned_after_ids(owner) & all_after_ids
            old_worker = owner._worker
            owner.close()
            if old_worker is not None:
                old_worker.join(WORKER_JOIN_SECONDS)
                lifecycle_state["worker_joins"] = (
                    int(lifecycle_state["worker_joins"]) + 1
                )
                if old_worker.is_alive():
                    raise RuntimeError("Retired gallery worker remained alive.")
            old_host = lifecycle_old_frame[0].master
            old_host.destroy()
            root.after_idle(check_retired_gallery)

        def check_retired_gallery() -> None:
            """Require the previous frame and callbacks to be fully gone.

            Returns:
                None: Starts the next open or marks lifecycle completion.
            """
            old_viewer = lifecycle_old_viewer[0]
            old_frame = lifecycle_old_frame[0]
            if old_viewer is None or old_frame is None:
                raise RuntimeError("Lifecycle retirement lost its prior owner.")
            if not old_viewer._closed:
                raise RuntimeError("Retired gallery did not report closed state.")
            try:
                frame_exists = bool(old_frame.winfo_exists())
            except tk.TclError:
                frame_exists = False
            if frame_exists:
                raise RuntimeError("Retired gallery frame remains mounted.")
            current_after_ids = after_identifiers()
            lifecycle_state["after_counts"].append({
                "before_close": lifecycle_before_close_after_count[0],
                "after_close": len(current_after_ids),
            })
            active_old_callbacks = (
                current_after_ids & lifecycle_old_after_ids[0]
            )
            if active_old_callbacks:
                raise RuntimeError("Retired gallery left Tk after callbacks active.")
            lifecycle_state["old_callbacks"] = (
                int(lifecycle_state["old_callbacks"]) + len(active_old_callbacks)
            )
            lifecycle_state["postclose_image_counts"].append(
                sum(image is not None for image in old_viewer._photo_images)
            )
            if lifecycle_finalizing[0]:
                expected_gallery_ids = list(lifecycle_targets)
                if lifecycle_state["gallery_ids"] != expected_gallery_ids:
                    raise RuntimeError("Lifecycle galleries were not switched and reopened exactly.")
                if operations["lifecycle_transitions_completed"] != LIFECYCLE_TRANSITION_COUNT:
                    raise RuntimeError("Lifecycle transition count is incomplete.")
                if lifecycle_state["old_callbacks"] != 0:
                    raise RuntimeError("Retired gallery callbacks remain active.")
                if len(lifecycle_state["postclose_image_counts"]) != LIFECYCLE_TRANSITION_COUNT + 1:
                    raise RuntimeError("Lifecycle close observation count is incomplete.")
                terminal["complete"] = True
                progress_checkpoint("complete", force=True)
                root.quit()
                return
            mount_next_gallery()

        def mount_next_gallery() -> None:
            """Open the next synthetic gallery for the lifecycle sample.

            Returns:
                None: Starts asynchronous loading and readiness verification.
            """
            nonlocal viewer
            index = int(lifecycle_state["index"])
            target_gallery_id = lifecycle_targets[index]
            lifecycle_state["mount_started_ns"] = time.perf_counter_ns()
            viewer, _lifecycle_session, _lifecycle_fixture, life_state = (
                common.build_gallery(
                    root, source_root, args.seed,
                    gallery_id=target_gallery_id,
                    photo_count=LIFECYCLE_PHOTO_COUNT,
                )
            )
            lifecycle_state["gallery_ids"].append(target_gallery_id)
            lifecycle_current_state[0] = life_state
            root.after(POLL_INTERVAL_MS, poll_lifecycle_gallery)

        def poll_lifecycle_gallery() -> None:
            """Load and validate the bounded gallery through production pages.

            Returns:
                None: Records one transition after the 100-photo view settles.
            """
            if time.monotonic() >= deadline:
                raise TimeoutError("Gallery lifecycle exceeded the child deadline.")
            if viewer is None:
                raise RuntimeError("Lifecycle gallery failed to mount.")
            if len(viewer._photos) < LIFECYCLE_PHOTO_COUNT:
                if viewer._worker is None:
                    viewer._load_more()
                root.after(POLL_INTERVAL_MS, poll_lifecycle_gallery)
                return
            visible = set(viewer._visible_thumbnail_indices)
            ready = (
                viewer._worker is None and viewer._events.empty()
                and viewer._viewport_after_id is None and bool(visible)
                and all(index in viewer._thumbnail_loaded for index in visible)
            )
            if not ready:
                root.after(POLL_INTERVAL_MS, poll_lifecycle_gallery)
                return
            target = lifecycle_targets[int(lifecycle_state["index"])]
            actual_ids = tuple(photo.image_id for photo in viewer._photos)
            expected_ids = tuple(
                range(common.FIRST_IMAGE_ID,
                      common.FIRST_IMAGE_ID + LIFECYCLE_PHOTO_COUNT)
            )
            current_gallery_state = lifecycle_current_state[0]
            if current_gallery_state is None:
                raise RuntimeError("Lifecycle state owner is missing.")
            if (viewer.gallery.gallery_id != target or actual_ids != expected_ids
                    or current_gallery_state["page_reads"]
                    != math.ceil(LIFECYCLE_PHOTO_COUNT / common.PHOTO_PAGE_SIZE)):
                raise RuntimeError("Lifecycle gallery identity or order is incorrect.")
            elapsed_ms = (
                time.perf_counter_ns()
                - int(lifecycle_state["mount_started_ns"])
            ) / common.NS_PER_MS
            stage_names = (
                "gallery_switch_ms", "gallery_reopen_1_ms", "gallery_reopen_2_ms",
            )
            stage = stage_names[int(lifecycle_state["index"])]
            lifecycle_state["timings_ms"][stage] = elapsed_ms
            if args.instrumentation == "on":
                metrics[stage] = elapsed_ms
            lifecycle_state["index"] = int(lifecycle_state["index"]) + 1
            operations["lifecycle_transitions_completed"] += 1
            resources["lifecycle_last_catalogue_rows"] = len(actual_ids)
            if int(lifecycle_state["index"]) == LIFECYCLE_TRANSITION_COUNT:
                retire_current(finalize=True)
            else:
                retire_current()

        def start_lifecycle() -> None:
            """Run the switch and reopen sequence after primary timings are frozen.

            Returns:
                None: Begins three isolated representative lifecycle transitions.
            """
            recorder.stop_heartbeat()
            recorder.phase = "warmup"
            common.PHOTO_BODY_FIXTURES[args.seed] = (
                fixture_photos[:LIFECYCLE_PHOTO_COUNT]
            )
            progress_checkpoint("lifecycle", force=True)
            operations["lifecycle_transitions_requested"] = (
                LIFECYCLE_TRANSITION_COUNT
            )
            retire_current()

        def finish_if_valid() -> None:
            """Validate primary workload and freeze its evidence before lifecycle.

            Returns:
                None: The representative switch/reopen phase starts after primary capture.
            """
            nonlocal primary_cpu_elapsed_ns, primary_peak_working_set_bytes
            if (operations["wheel_nonedge_requested_count"] < 1
                    or operations["wheel_nonedge_applied_count"]
                    != operations["wheel_nonedge_requested_count"]):
                raise RuntimeError("A non-edge wheel event did not move the gallery.")
            expected = tuple(range(
                common.FIRST_IMAGE_ID, common.FIRST_IMAGE_ID + count,
            ))
            actual = tuple(photo.image_id for photo in viewer._photos)
            if actual != expected:
                raise RuntimeError("Catalogue IDs or stable order changed.")
            if state["page_reads"] != math.ceil(count / common.PHOTO_PAGE_SIZE):
                raise RuntimeError("Actual media-page read count is incorrect.")
            operations["page_reads"] = state["page_reads"]
            resources["catalogue_rows"] = len(actual)
            resources["unique_ids"] = len(set(actual))
            resources["logical_tile_count"] = len(viewer._tile_buttons)
            resources["native_widget_count"] = count_native_widgets(root)
            resources["canvas_grid_active"] = bool(
                getattr(viewer, "_canvas_grid", None) is not None
            )
            resources["canvas_items"] = len(viewer.grid_canvas.find_all())
            resources["live_photo_images"] = sum(
                image is not None for image in viewer._photo_images
            )
            resources["dataset_sha256"] = fixture["dataset_sha256"]
            metadata["renderer_actual"] = (
                "canvas" if getattr(viewer, "_canvas_grid", None) is not None
                else "widgets"
            )
            resources["thumbnail_body_bytes"] = fixture["thumbnail_body_bytes"]
            primary_operation_counts.update(recorder.operation_counts)
            image_names = tuple(
                root.tk.splitlist(root.tk.call("image", "names"))
            )
            canvas_grid = getattr(viewer, "_canvas_grid", None)
            if canvas_grid is None:
                canvas_item_reuses = 0
            else:
                canvas_item_reuses = getattr(
                    canvas_grid, "item_reuse_count", None,
                )
                if type(canvas_item_reuses) is not int or canvas_item_reuses < 0:
                    raise RuntimeError(
                        "Canvas renderer must expose a nonnegative item_reuse_count."
                    )
            metrics.update({
                "native_widgets_count": float(resources["native_widget_count"]),
                "logical_tiles_count": float(resources["logical_tile_count"]),
                "canvas_items_count": float(resources["canvas_items"]),
                "tk_images_count": float(len(image_names)),
                "photo_images_count": float(resources["live_photo_images"]),
                "canvas_item_reuses_count": float(canvas_item_reuses),
            })
            thumbnail_create_count = int(
                getattr(viewer, "_thumbnail_image_create_count")
            )
            metrics["thumbnail_image_creations_count"] = float(
                thumbnail_create_count
            )
            metadata["thumbnail_image_creation_semantics"] = (
                "Tk PhotoImage constructor calls during the primary gallery phase"
            )
            if args.instrumentation == "on":
                primary_peak_working_set_bytes = float(
                    getattr(psutil.Process().memory_info(), PEAK_MEMORY_FIELD)
                )
            metrics["scenario_elapsed_ms"] = (
                time.perf_counter_ns() - started_ns
            ) / common.NS_PER_MS
            if cpu_measure_started_ns[0] is not None:
                primary_cpu_elapsed_ns = (
                    time.process_time_ns() - cpu_measure_started_ns[0]
                )
            if args.trial == 0 and args.instrumentation == "on":
                screenshot = Path(args.output).with_name(
                    f"{Path(args.output).stem}-{args.source_id[:8]}-viewport.png"
                )
                try:
                    bounds = (
                        root.winfo_rootx(), root.winfo_rooty(),
                        root.winfo_rootx() + root.winfo_width(),
                        root.winfo_rooty() + root.winfo_height(),
                    )
                    ImageGrab.grab(bbox=bounds).save(screenshot)
                    metadata["visual_evidence"] = str(screenshot)
                except (
                    OSError, RuntimeError, tk.TclError, ValueError,
                    NotImplementedError,
                ):
                    metadata["visual_evidence"] = "unavailable"
            else:
                metadata["visual_evidence"] = "not requested"
            progress_checkpoint("primary_complete", force=True)
            start_lifecycle()

        def begin_measured_replay() -> None:
            """Start measurement after warm trace and viewport reset settle.

            Returns:
                None: The identical input trace starts with measured event capture.
            """
            visible = set(viewer._visible_thumbnail_indices)
            ready = (
                viewer._worker is None and viewer._events.empty()
                and viewer._viewport_after_id is None
                and all(index in viewer._thumbnail_loaded for index in visible)
            )
            if not ready:
                root.after(POLL_INTERVAL_MS, begin_measured_replay)
                return
            recorder.phase = "measured"
            cpu_measure_started_ns[0] = time.process_time_ns()
            recorder.start_heartbeat(root)
            progress_checkpoint("measurement_started", force=True)
            action_index["value"] = 0
            run_interactions()

        def run_interactions() -> None:
            """Queue one actual Tk input and await its settled viewport.

            Returns:
                None: Schedules the next input only after Tk idle and viewer work settle.
            """
            if time.monotonic() >= deadline:
                raise TimeoutError("Scroll workload exceeded its child deadline.")
            index = action_index["value"]
            if index >= len(action_plan):
                if args.cache == "warm" and replay_round["value"] == 0:
                    replay_round["value"] = 1
                    action_index["value"] = 0
                    operations["actions"] = 0
                    root.geometry(INITIAL_GEOMETRY)
                    viewer.grid_canvas.yview_moveto(0.0)
                    root.after(POLL_INTERVAL_MS, begin_measured_replay)
                    return
                finish_if_valid()
                return
            name, value = action_plan[index]
            action_context.update({"name": name, "index": index})
            selection_before = set(viewer.selected_photo_ids())
            started = time.perf_counter_ns()
            if name in ("wheel_fast", "drag") and (
                    index == 0 or action_plan[index - 1][0] != name):
                burst_started_ns["value"] = started
            canvas = viewer.grid_canvas
            wheel_before_view: tuple[float, float] | None = None
            wheel_at_edge: bool | None = None
            if name.startswith("wheel"):
                pointer_x = max(1, canvas.winfo_width() // 2)
                pointer_y = max(1, canvas.winfo_height() // 2)
                pointer_root_x = canvas.winfo_rootx() + pointer_x
                pointer_root_y = canvas.winfo_rooty() + pointer_y
                wheel_before_view = tuple(float(part) for part in canvas.yview())
                wheel_at_edge = (
                    value > 0
                    and wheel_before_view[0] <= WHEEL_MOVEMENT_TOLERANCE
                ) or (
                    value < 0 and wheel_before_view[1]
                    >= WHEEL_BOTTOM_FRACTION - WHEEL_MOVEMENT_TOLERANCE
                )
                if recorder.phase == "measured":
                    operations["wheel_requested_count"] += 1
                    if not wheel_at_edge:
                        operations["wheel_nonedge_requested_count"] += 1
                canvas.event_generate(
                    "<Motion>", x=pointer_x, y=pointer_y,
                    rootx=pointer_root_x, rooty=pointer_root_y, warp=True,
                )
                delta = int(value) * 120
                canvas.event_generate(
                    "<MouseWheel>", x=pointer_x, y=pointer_y,
                    rootx=pointer_root_x, rooty=pointer_root_y,
                    delta=delta, when="tail",
                )
                interval = (
                    SLOW_WHEEL_INTERVAL_MS if name == "wheel_slow"
                    else FAST_WHEEL_INTERVAL_MS
                )
            elif name == "drag":
                invoke_scrollbar_moveto(root, viewer, value)
                interval = DRAG_INTERVAL_MS
            elif name == "jump":
                canvas.yview_moveto(value)
                interval = 0
            elif name in SELECTION_ACTIONS:
                visible = sorted(viewer._visible_thumbnail_indices)
                if not visible:
                    raise RuntimeError("No visible photo tile can receive input.")
                first_index = visible[0]
                first_photo_id = viewer._photos[first_index].image_id
                tile = viewer._tile_buttons[first_index]
                root.focus_force()
                canvas_grid = getattr(viewer, "_canvas_grid", None)
                # Both native Buttons and Canvas proxies focus the exact tile.
                tile.focus_force()
                if name == "click":
                    tile.event_generate("<ButtonPress-1>", x=4, y=4, when="tail")
                    tile.event_generate("<ButtonRelease-1>", x=4, y=4, when="tail")
                elif name == "shift_click":
                    second_index = min(first_index + 1, len(viewer._tile_buttons) - 1)
                    tile = viewer._tile_buttons[second_index]
                    second_photo_id = viewer._photos[second_index].image_id
                    tile.focus_force()
                    tile.event_generate("<ButtonPress-1>", x=4, y=4, state=TK_SHIFT_MASK, when="tail")
                    tile.event_generate("<ButtonRelease-1>", x=4, y=4, state=TK_SHIFT_MASK, when="tail")
                elif name == "key_right":
                    right_index = min(
                        first_index + 1, len(viewer._tile_buttons) - 1,
                    )
                    expected_focus_index = right_index
                    expected_selection = {
                        viewer._photos[right_index].image_id,
                    }
                    key_target = (
                        tile if canvas_grid is None else viewer.grid_canvas
                    )
                    key_target.event_generate("<KeyPress-Right>", when="tail")
                else:
                    expected_focus_index = first_index
                    expected_selection = selection_before.symmetric_difference({
                        first_photo_id,
                    })
                    key_target = (
                        tile if canvas_grid is None else viewer.grid_canvas
                    )
                    key_target.event_generate("<KeyPress-space>", when="tail")
                interval = 0
            else:
                root.geometry(f"{int(value)}x{WINDOW_HEIGHT_PX}+0+0")
                interval = 0

            def after_idle() -> None:
                """Record application feedback after the queued Tk event reaches idle.

                Returns:
                    None: Stores raw operation and generic feedback observations.
                """
                elapsed = time.perf_counter_ns() - started
                recorder.add(name, elapsed)
                recorder.add("interaction_feedback", elapsed)
                input_samples.setdefault(name, []).append(elapsed)
                if name.startswith("wheel"):
                    if wheel_before_view is None:
                        raise RuntimeError("Wheel input has no pre-event viewport.")
                    after_view = tuple(
                        float(part) for part in viewer.grid_canvas.yview()
                    )
                    movement = after_view[0] - wheel_before_view[0]
                    if wheel_at_edge is None:
                        raise RuntimeError("Wheel input has no edge classification.")
                    at_edge = wheel_at_edge
                    moved_in_requested_direction = (
                        movement > WHEEL_MOVEMENT_TOLERANCE
                        if value < 0
                        else movement < -WHEEL_MOVEMENT_TOLERANCE
                    )
                    moved_opposite_direction = (
                        movement < -WHEEL_MOVEMENT_TOLERANCE
                        if value < 0
                        else movement > WHEEL_MOVEMENT_TOLERANCE
                    )
                    if recorder.phase == "measured":
                        if moved_in_requested_direction:
                            operations["wheel_applied_count"] += 1
                            if not at_edge:
                                operations["wheel_nonedge_applied_count"] += 1
                        elif at_edge:
                            operations["wheel_boundary_count"] += 1
                    if moved_opposite_direction or (
                        not at_edge and not moved_in_requested_direction
                    ):
                        raise RuntimeError(
                            "Wheel event was ignored or moved against its direction."
                        )

                selected_after = set(viewer.selected_photo_ids())
                if name == "click" and first_photo_id not in selected_after:
                    raise RuntimeError("Photo click did not select its exact ID.")
                if name == "shift_click" and not {
                    first_photo_id, second_photo_id,
                }.issubset(selected_after):
                    raise RuntimeError("Shift-click did not retain both selected IDs.")
                if name in ("key_right", "key_space"):
                    if selected_after != expected_selection:
                        raise RuntimeError(
                            f"{name} produced unexpected selected photo IDs."
                        )
                    if expected_focus_index is None or not focus_matches(
                        root, viewer, expected_focus_index,
                    ):
                        raise RuntimeError(
                            f"{name} left focus on an unexpected photo."
                        )
                action_index["value"] += 1
                operations["actions"] += 1
                if operations["actions"] % PROGRESS_CHECKPOINT_INTERVAL == 0:
                    progress_checkpoint(
                        "measurement_started" if recorder.phase == "measured"
                        else "warmup_interactions",
                        force=True,
                    )
                next_same_burst = (
                    name in ("wheel_fast", "drag")
                    and action_index["value"] < len(action_plan)
                    and action_plan[action_index["value"]][0] == name
                )
                if next_same_burst:
                    elapsed_ms = elapsed / common.NS_PER_MS
                    root.after(
                        max(0, math.ceil(interval - elapsed_ms)),
                        run_interactions,
                    )
                else:
                    root.after_idle(await_settle)

            def await_settle() -> None:
                """Wait for viewport scheduling, worker publication and visible images.

                Returns:
                    None: Continues on Tk timers until the current action is applied.
                """
                if time.monotonic() >= deadline:
                    capture_failure_snapshot("viewport_settle_timeout")
                    raise TimeoutError("Viewport did not settle before deadline.")
                visible = set(viewer._visible_thumbnail_indices)
                actual_width = root.winfo_width()
                if name == "resize" and actual_width != int(value):
                    root.after(POLL_INTERVAL_MS, await_settle)
                    return
                ready = (
                    viewer._viewport_after_id is None
                    and viewer._worker is None and viewer._events.empty()
                    and all(index in viewer._thumbnail_loaded for index in visible)
                )
                if not ready:
                    root.after(POLL_INTERVAL_MS, await_settle)
                    return
                if name == "resize":
                    resize_observations.append({
                        "phase": recorder.phase,
                        "requested_width": int(value),
                        "actual_width": actual_width,
                    })
                    resources["resize_observations"] = [
                        dict(observation) for observation in resize_observations
                    ]
                settled_started = (
                    burst_started_ns["value"]
                    if name in ("wheel_fast", "drag") and burst_started_ns["value"]
                    else started
                )
                settled_ns = time.perf_counter_ns() - settled_started
                recorder.add("input_to_settled", settled_ns)
                recorder.add("viewport_settle", settled_ns)
                recorder.add(f"{name}_settled", time.perf_counter_ns() - started)
                if name in ("wheel_fast", "drag"):
                    recorder.add(f"{name}_burst_settled", settled_ns)
                    burst_started_ns["value"] = None
                elapsed_ms = (time.perf_counter_ns() - started) / common.NS_PER_MS
                root.after(max(0, math.ceil(interval - elapsed_ms)), run_interactions)

            root.after_idle(after_idle)

        def poll_catalogue() -> None:
            """Settle the initial visible band, then load the full catalogue.

            Returns:
                None: Records initial viewport and total catalogue phases separately.
            """
            if time.monotonic() >= deadline:
                raise TimeoutError("Catalogue did not settle before deadline.")
            progress_checkpoint("catalogue_loading")
            page_reads_now = (
                int(fixture_state[0]["page_reads"])
                if fixture_state[0] is not None else 0
            )
            if (page_reads_now - last_checkpoint_pages["value"]
                    >= PROGRESS_CHECKPOINT_INTERVAL):
                last_checkpoint_pages["value"] = page_reads_now
                progress_checkpoint("catalogue_loading", force=True)
            if not first_page_recorded["done"]:
                if len(viewer._photos) >= min(count, common.PHOTO_PAGE_SIZE):
                    first_page_recorded["done"] = True
                    metrics["first_page_metadata_ms"] = (
                        time.perf_counter_ns() - phase_started_ns
                    ) / 1_000_000
                    first_page_recorded["loaded_started_ns"] = time.perf_counter_ns()
                else:
                    root.after(POLL_INTERVAL_MS, poll_catalogue)
                    return
            if "first_viewport_settle_ms" not in metrics:
                visible = set(viewer._visible_thumbnail_indices)
                ready = (
                    viewer._worker is None and viewer._events.empty()
                    and viewer._viewport_after_id is None and bool(visible)
                    and all(index in viewer._thumbnail_loaded for index in visible)
                )
                if not ready:
                    root.after(POLL_INTERVAL_MS, poll_catalogue)
                    return
                metrics["first_viewport_settle_ms"] = (
                    time.perf_counter_ns()
                    - first_page_recorded["loaded_started_ns"]
                ) / 1_000_000
                first_page_recorded["catalogue_load_started_ns"] = (
                    time.perf_counter_ns()
                )
            if len(viewer._photos) < count:
                if viewer._worker is None:
                    viewer._load_more()
                root.after(POLL_INTERVAL_MS, poll_catalogue)
                return
            visible = set(viewer._visible_thumbnail_indices)
            ready = (
                viewer._worker is None and viewer._events.empty()
                and viewer._viewport_after_id is None and bool(visible)
                and all(index in viewer._thumbnail_loaded for index in visible)
            )
            if not ready:
                root.after(POLL_INTERVAL_MS, poll_catalogue)
                return
            metrics["loaded_catalogue_ms"] = (
                time.perf_counter_ns()
                - first_page_recorded["catalogue_load_started_ns"]
            ) / 1_000_000
            progress_checkpoint("catalogue_ready", force=True)
            if args.cache == "warm":
                recorder.phase = "warmup"
                progress_checkpoint("warmup_interactions", force=True)
            else:
                recorder.phase = "measured"
                cpu_measure_started_ns[0] = time.process_time_ns()
                recorder.start_heartbeat(root)
                progress_checkpoint("measurement_started", force=True)
            run_interactions()

        root.after(0, poll_catalogue)
        watchdog_after_id = root.after(
            CHILD_DEADLINE_SECONDS * 1000, root.quit,
        )
        root.mainloop()
        if callback_failures:
            raise RuntimeError("\n".join(callback_failures))
        if not terminal["complete"]:
            raise TimeoutError("Tk mainloop ended without terminal workload completion.")
        metrics.setdefault(
            "scenario_elapsed_ms",
            (time.perf_counter_ns() - started_ns) / common.NS_PER_MS,
        )
        if args.instrumentation == "on":
            for metric, summary in recorder.setup_summaries.items():
                metrics[f"setup_{metric}_count"] = float(summary["count"])
                metrics[f"setup_{metric}_total_ms"] = (
                    summary["total_ns"] / common.NS_PER_MS
                )
                metrics[f"setup_{metric}_max_ms"] = (
                    summary["max_ns"] / common.NS_PER_MS
                )
            measured_events = [
                event for event in recorder.events if event["phase"] == "measured"
            ]
            metric_map = {
                "event_loop_drift": "event_loop_drift",
                "callback": "callback",
                "interaction_feedback": "interaction_feedback",
                "image_publication": "image_publication",
                "input_to_settled": "input_to_settled",
                "wheel_fast_burst_settled": "wheel_fast_burst_settled",
                "drag_burst_settled": "drag_burst_settled",
                **{name: name for name, _ in action_plan},
                **{f"{name}_settled": f"{name}_settled" for name, _ in action_plan},
                "viewport_settle": "viewport_settle",
            }
            for output_name, raw_name in metric_map.items():
                samples = [
                    int(event["ns"]) for event in measured_events
                    if event["metric"] == raw_name
                ]
                if output_name in {
                    "event_loop_drift", "callback", "interaction_feedback",
                    "image_publication",
                } and not samples:
                    raise RuntimeError(f"Required recorder metric missing: {raw_name}")
                for suffix, fraction in common.LATENCY_FRACTIONS.items():
                    value = common.percentile_ms(samples, fraction)
                    if value is not None:
                        metrics[f"{output_name}_{suffix}_ms"] = value
                if samples:
                    metrics[f"{output_name}_max_ms"] = max(samples) / 1_000_000
            raw = [dict(event) for event in measured_events]
            operations.update(primary_operation_counts)
            for operation_name, count_value in primary_operation_counts.items():
                metrics[f"{operation_name}_count"] = float(count_value)
            if recorder.truncated or len(raw) > RAW_EVENT_LIMIT:
                raise RuntimeError("Recorder raw-event budget was exceeded.")
            if primary_cpu_elapsed_ns is None:
                raise RuntimeError("Primary process CPU interval was not captured.")
            metrics["process_cpu_ms"] = primary_cpu_elapsed_ns / common.NS_PER_MS
            if primary_peak_working_set_bytes is None:
                raise RuntimeError("Primary process peak working set was not captured.")
            metrics["process_peak_rss_bytes"] = primary_peak_working_set_bytes
            metadata.update({
                "python": platform.python_version(),
                "os": platform.platform(),
                "pillow": PILLOW_VERSION,
                "tcl": str(root.tk.call("info", "patchlevel")),
                "tk": str(root.tk.call("set", "tk_patchLevel")),
                "windowing_system": str(root.tk.call("tk", "windowingsystem")),
                "display": {
                    "width": root.winfo_screenwidth(),
                    "height": root.winfo_screenheight(),
                    "dpi": root.winfo_fpixels("1i"),
                },
                "dataset_sha256": fixture["dataset_sha256"],
                "physical_presentation": "not measured",
                "focus_semantics": (
                    "native tile focus identity or explicit Canvas focused_index; "
                    "not an accessibility claim"
                ),
                "interaction_latency_semantics": (
                    "input enqueue to Tk idle proxy; viewport settlement is separate"
                ),
            })
        else:
            count_metrics = {
                key: value for key, value in metrics.items()
                if key.endswith("_count")
            }
            metrics = {
                "scenario_elapsed_ms": metrics["scenario_elapsed_ms"],
                **count_metrics,
            }
            missing_metrics.extend(("process_cpu_ms", "process_peak_rss_bytes"))
        if any(not math.isfinite(value) or value < 0 for value in metrics.values()):
            raise RuntimeError("A metric was non-finite or negative.")
        status = "PASS"
    except Exception as error:
        try:
            progress_checkpoint("failed", force=True)
        except OSError:
            pass
        capture_failure_snapshot("run_exception")
        failure = f"{type(error).__name__}: {str(error)[-CALLBACK_TRACEBACK_LIMIT:]}"
        if root is not None and callback_failures:
            failure += "\n" + "".join(traceback.format_exc())[-CALLBACK_TRACEBACK_LIMIT:]
    finally:
        recorder.stop_heartbeat()
        if viewer is not None and "renderer_actual" not in metadata:
            metadata["renderer_actual"] = (
                "canvas" if getattr(viewer, "_canvas_grid", None) is not None
                else "widgets"
            )
        if viewer is not None and not viewer._closed:
            worker = viewer._worker
            viewer.close()
            if worker is not None:
                worker.join(WORKER_JOIN_SECONDS)
                if worker.is_alive():
                    status = "FAIL"
                    failure = "Viewer worker did not relinquish ownership at teardown."
        recorder.restore()
        if root is not None:
            try:
                metadata.update({
                    "python": platform.python_version(),
                    "os": platform.platform(),
                    "pillow": PILLOW_VERSION,
                    "tcl": str(root.tk.call("info", "patchlevel")),
                    "tk": str(root.tk.call("set", "tk_patchLevel")),
                    "windowing_system": str(root.tk.call("tk", "windowingsystem")),
                    "display": {
                        "width": root.winfo_screenwidth(),
                        "height": root.winfo_screenheight(),
                        "dpi": root.winfo_fpixels("1i"),
                    },
                    "delayed_thumbnail_response_ids_ms": {
                        str(image_id): delay * 1000
                        for image_id, delay in DELAYED_RESPONSE_SECONDS
                    },
                    "physical_presentation": "not measured",
                    "warm_priming": (
                        "same fixed interaction trace before measurement"
                        if args.cache == "warm" else "none; fresh process"
                    ),
                })
            except tk.TclError:
                metadata["display"] = "unavailable"
            try:
                root.destroy()
            except tk.TclError:
                pass
        for key, value in previous_profiles.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value
        temporary.cleanup()
    metadata["lifecycle_timings_ms"] = lifecycle_state["timings_ms"]
    metadata["lifecycle_after_callback_counts"] = lifecycle_state["after_counts"]
    metadata["lifecycle_gallery_ids"] = lifecycle_state["gallery_ids"]
    metadata["lifecycle_worker_joins"] = lifecycle_state["worker_joins"]
    metadata["lifecycle_stale_callbacks"] = lifecycle_state["old_callbacks"]
    metadata["lifecycle_postclose_image_counts"] = lifecycle_state["postclose_image_counts"]
    return {
        "schema_version": 1, "status": status,
        "scenario": args.scenario, "cache": args.cache, "trial": args.trial,
        "source": args.source_id, "seed": args.seed, "metrics": metrics,
        "operations": operations, "resources": resources, "metadata": metadata,
        "missing_metrics": missing_metrics, "missing_required_metrics": [],
        "raw_events": raw, "raw_events_truncated": recorder.truncated,
        "failure": failure,
    }


def main(argv: list[str] | None = None) -> int:
    """Write one Issue 361 compatible child report.

    Args:
        argv (list[str] | None): Optional argument vector without executable name.

    Returns:
        int: Zero only for a complete valid catalogue and interaction run.
    """
    args = parse_args(argv)
    report = run(args)
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(f"{report['status']} {args.scenario}/{args.cache} trial={args.trial}")
    return 0 if report["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
