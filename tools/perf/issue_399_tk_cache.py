#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_399_tk_cache.py
# Module Type: Decoded Thumbnail Capacity Workload
# Purpose: Measure the effect of Tk thumbnail cache capacity in hosted CI.
# Responsibilities:
#   - Drive complete sweeps through the mounted Photos viewer and scrollbar
#   - Compare fixed cache treatments on the same application source
#   - Record real cache, decode and publication work with exact photo coverage
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise the retained Tk decoded-thumbnail cache through real viewports."""

import argparse
from collections.abc import Callable
from dataclasses import replace
import json
import os
from pathlib import Path
import sys
import tempfile
from threading import Event
import time
import tkinter as tk
import traceback
from types import TracebackType
from unittest import mock

from PIL import Image, __version__ as PILLOW_VERSION
import psutil

import issue_361_tk_workloads as common
from issue_399_tk_scroll import (
    CHILD_DEADLINE_SECONDS,
    INITIAL_GEOMETRY,
    LIFECYCLE_PHOTO_COUNT,
    PEAK_MEMORY_FIELD,
    PHOTO_COUNTS,
    POLL_INTERVAL_MS,
    WORKER_JOIN_SECONDS,
    invoke_scrollbar_moveto,
    parse_args,
)
from uploader.app_linking import (
    LegacyThumbnailPublication, LinkedMediaPage, LinkedPhoto, LinkingError,
    MediaReadTimings, ThumbnailBatchStats, ThumbnailBatchWitness, ThumbnailPublication,
)

# Purpose: Compare the pre-expansion budget to the current production budget.
# Type: dict[str, tuple[int, int]]. Units: bytes and decoded images.
# Scope: one disposable benchmark child. Consumers: run, central driver validation.
# Rationale: 16 MiB/256 entries and 256 MiB/4096 entries isolate the historical
# capacity change without changing policy, identity indexing or production defaults.
CACHE_TREATMENTS = {
    "small": (16 * 1024 * 1024, 256),
    "shipped": (256 * 1024 * 1024, 4096),
}
# Purpose: Advance the scrollbar far enough to limit redundant viewport work.
# Type: float. Units: fraction of the current viewport.
# Scope: one down or back sweep. Consumers: sweep.
# Rationale: 85% overlaps the next viewport so rounding cannot hide a row gap.
SWEEP_STRIDE = 0.85
# Purpose: Convert the shared Tk poll interval to time.sleep units.
# Type: int. Units: milliseconds per second.
# Scope: hosted child polling. Consumers: await_state.
# Rationale: 1000 ms is one second. Retain the shared 10 ms poll interval.
MS_PER_SECOND = 1000
# Purpose: Keep the entire large synthetic catalogue within the native grid.
# Type: str. Units: width:height. Scope: all capacity trial viewports.
# Consumers: mount. Rationale: the existing 21:9 preference yields shorter rows
# after native square-card viewports stopped reaching every photo at 2,000.
CARD_FORMAT = "21:9"
RECORDED_METRICS = frozenset({
    "callback", "event_loop_drift", "image_publication", "interaction_feedback",
    "thumbnail_prepare", "viewport_settle",
})
BatchSink = Callable[[
    ThumbnailPublication | LegacyThumbnailPublication,
    MediaReadTimings, Callable[[], None],
], None]


class CapacityRecorder(common.Recorder):
    """Use the existing bounded recorder for the capacity protocol's events."""

    def add(
        self, metric: str, elapsed_ns: int, phase: str | None = None,
        callback_name: str | None = None, dispatch_delay_ns: int | None = None,
    ) -> None:
        """Keep every observation of the capacity protocol's named metrics.

        Args:
            metric (str): Original event key emitted by the real operation hook.
            elapsed_ns (int): Measured elapsed time in nanoseconds.
            phase (str | None): Explicit phase or active recorder phase.
            callback_name (str | None): Fixed application callback identity.
            dispatch_delay_ns (int | None): Observed callback dispatch delay.

        Returns:
            None: Delivers selected events to the original recorder and cap.
        """
        if metric in RECORDED_METRICS:
            super().add(metric, elapsed_ns, phase, callback_name, dispatch_delay_ns)


def configure_batch_media(
    session: mock.Mock, expected_gallery_id: int, bodies: tuple[bytes, ...],
) -> None:
    """Model the current public thumbnail-batch seam for the mounted viewer.

    Args:
        session (mock.Mock): Strict synthetic production LinkingSession fixture.
        expected_gallery_id (int): Exact current synthetic gallery.
        bodies (tuple[bytes, ...]): Deterministic image bodies in descriptor order.

    Returns:
        None: Replaces only catalogue and thumbnail transport beyond the viewer.
    """
    read_catalogue = session.media_page.side_effect

    def read_page(
        gallery_id: int, after_id: int | None = None, cancelled: Event | None = None,
    ) -> LinkedMediaPage:
        """Attach stable public thumbnail validators to the loaded catalogue.

        Args:
            gallery_id (int): Exact synthetic gallery owning the requested page.
            after_id (int | None): Previous page continuation, or first page.
            cancelled (Event | None): Current catalogue cancellation fence.

        Returns:
            LinkedMediaPage: Fixture page with validated public photo descriptors.
        """
        page = read_catalogue(gallery_id, after_id, cancelled)
        verified_at = time.monotonic()
        return replace(page, photos=tuple(
            replace(
                row, cache_eligible=True, thumbnail_etag=f'"synthetic-{row.image_id}"',
                thumbnail_verified_at=verified_at,
            )
            for row in page.photos
        ))

    def read_batch(
        gallery_id: int, photos: tuple[LinkedPhoto, ...], publish: BatchSink,
        cancelled: Event, *, timings: MediaReadTimings, stats: ThumbnailBatchStats,
        retry_wait: Callable[[int], None] | None = None,
    ) -> None:
        """Publish each authorized synthetic frame through the actual batch callback.

        Args:
            gallery_id (int): Exact mounted synthetic gallery identifier.
            photos (tuple[LinkedPhoto, ...]): Real viewer-admitted descriptors.
            publish (BatchSink): Native worker's verified-media publication sink.
            cancelled (Event): Current gallery batch cancellation fence.
            timings (MediaReadTimings): Simulated framed-path volume metadata.
            stats (ThumbnailBatchStats): Counts driving normal batch sizing.
            retry_wait (Callable[[int], None] | None): Unused simulated cooldown observer.

        Returns:
            None: Sends exact synthetic bytes through real decode and Tk publication.
        """
        if gallery_id != expected_gallery_id:
            raise AssertionError("Batch requested the wrong fixture gallery.")

        def check_active() -> None:
            """Honor the published batch cancellation between frames.

            Returns:
                None: Continues only while this fixture batch owns its request.
            """
            if cancelled.is_set():
                raise LinkingError("Fixture thumbnail batch cancelled.")

        timings.http_requests = 1  # One simulated framed response per group.
        stats.batch_sizes[len(photos)] = 1
        stats.requested = len(photos)
        for photo in photos:
            check_active()
            offset = photo.image_id - common.FIRST_IMAGE_ID
            if photo.cache_eligible is not True or not 0 <= offset < len(bodies):
                raise AssertionError("Batch does not own an exact public photo.")
            body = bodies[offset]
            witness = ThumbnailBatchWitness(
                session, 'https://gallery.example.test', gallery_id, photo,
                'public', (photo.thumbnail_etag, photo.thumbnail_verified_at), None, 0,
            )
            timings.encoded_bytes += len(body)
            stats.wire_items += 1
            item = ThumbnailPublication(
                witness, photo, 'ok', body, 'image/png', photo.thumbnail_etag, True,
            )
            publish(item, MediaReadTimings(), check_active)
            stats.returned += 1
            stats.delivered += 1

    # Call-recording mocks retain every per-tile proof check in memory. These
    # fixture callbacks preserve the same public authority without retaining a
    # call history in the measured CPU and working set.
    session.media_page = read_page
    session.media_photo = session.media_photo.side_effect
    session.can_read_media = lambda: True
    session.can_read_admin_media = lambda: False
    session.can_reuse_cached_media = lambda: True
    session.background_media_allowed = lambda: False
    session.supports_thumbnail_batch = lambda: True
    session.thumbnail_batch_preferred = lambda _gallery_id, _photo: True
    session.thumbnail_publication_current = lambda item, cancelled: (
        isinstance(item, ThumbnailPublication) and item.witness.owner is session
        and item.witness.gallery_id == expected_gallery_id and not cancelled.is_set()
    )
    session.media_thumbnails = read_batch


def run(args: argparse.Namespace, profile_root: Path) -> dict[str, common.JsonValue]:
    """Mount the selected renderer and record the measured sweep and reopen.

    Args:
        args (argparse.Namespace): Scenario, exact source, cache state and treatment.
        profile_root (Path): Disposable root for all per-user storage.

    Returns:
        dict[str, common.JsonValue]: Validated-measurement inputs for the child report.
    """
    count = PHOTO_COUNTS[args.scenario]
    byte_limit, entry_limit = CACHE_TREATMENTS[args.capacity_policy]
    native = common.native
    if (
        native.MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
        native.MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
    ) != CACHE_TREATMENTS["shipped"]:
        raise RuntimeError("Shipped decoded-cache limits differ from the protocol.")
    native.MEDIA_THUMBNAIL_CACHE_MAX_BYTES = byte_limit
    native.MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT = entry_limit
    for key in ("APPDATA", "LOCALAPPDATA", "USERPROFILE"):
        directory = profile_root / key.lower()
        directory.mkdir()
        os.environ[key] = str(directory)
    os.environ["PHP_GALLERY_CANVAS_GRID"] = "1" if args.cache_renderer == "canvas" else "0"
    bodies, hires, dataset_sha256 = common.load_fixture_bundle(
        Path(args.fixture_bundle), args.fixture_bundle_sha256, args.seed,
        count, args.fixture_bundle_photo_count, args.fixture_common_sha256,
    )
    common.PHOTO_BODY_FIXTURES[args.seed] = bodies
    common.HIRES_FIXTURE_BODIES[args.seed] = hires
    common.prepare_windows_display()
    root = tk.Tk()
    root.geometry(INITIAL_GEOMETRY)
    root.title("PHP Gallery decoded thumbnail cache qualification")
    recorder = CapacityRecorder(args.instrumentation == "on")
    memory = common.GalleryThumbnailMemory()
    viewer: common.NativeViewerContract | None = None
    callback_errors: list[BaseException] = []
    deadline = time.monotonic() + CHILD_DEADLINE_SECONDS
    phase = "initial mount"

    def save_callback_error(
        kind: type[BaseException], error: BaseException, trace: TracebackType | None,
    ) -> None:
        """Surface exceptions swallowed by Tk's callback boundary.

        Args:
            kind (type[BaseException]): Actual error class from Tk.
            error (BaseException): Original callback error.
            trace (TracebackType | None): Callback traceback, already held by the error.

        Returns:
            None: The next state poll fails with the original cause.
        """
        callback_errors.append(error)

    root.report_callback_exception = save_callback_error

    def await_state(ready: Callable[[], bool]) -> None:
        """Pump Tk until an exact state arrives inside the existing deadline.

        Args:
            ready (Callable[[], bool]): Predicate for the active viewport or catalogue.

        Returns:
            None: The predicate held after processing the current Tk events.
        """
        while time.monotonic() < deadline:
            root.update()
            if callback_errors:
                raise RuntimeError(f"Tk callback failed during {phase}.") from callback_errors[0]
            if ready():
                return
            time.sleep(POLL_INTERVAL_MS / MS_PER_SECOND)
        raise TimeoutError(f"Decoded-cache child deadline expired during {phase}.")

    def viewport_ready() -> bool:
        """Check that the current viewport has settled with real Tk images.

        Returns:
            bool: Every visible tile has a current production image.
        """
        assert viewer is not None
        visible = viewer._visible_thumbnail_indices
        return (
            bool(visible)
            and viewer._viewport_after_id is None
            and all(viewer._photo_images[index] is not None for index in visible)
        )

    def quiet_viewport() -> bool:
        """Fence measurement boundaries against outstanding overscan work.

        Returns:
            bool: Visible images and production publication work are complete.
        """
        assert viewer is not None
        return (
            viewport_ready() and viewer._thumbnail_gui_after_id is None
            and viewer._worker is None and viewer._events.empty()
        )

    def retire() -> None:
        """Close the viewport and release its images while retaining session PIL data.

        Returns:
            None: The real viewer, frame, and media worker have ended.
        """
        nonlocal viewer
        if viewer is None:
            return
        worker = viewer._worker
        host = viewer.frame.master
        viewer.close()
        host.destroy()
        if worker is not None:
            worker.join(WORKER_JOIN_SECONDS)
            if worker.is_alive():
                raise RuntimeError("Retired cache workload left its media worker alive.")
        if any(image is not None for image in viewer._photo_images):
            raise RuntimeError("Retired viewer retained Tk PhotoImage references.")
        viewer = None

    def mount(gallery_id: int, extent: int) -> float:
        """Load every page of a real Photos window using the shared decoded cache.

        Args:
            gallery_id (int): Synthetic gallery identity for this mount.
            extent (int): Exact descriptor count expected in catalogue order.

        Returns:
            float: Milliseconds through complete catalogue and initial viewport.
        """
        nonlocal viewer
        start = time.perf_counter_ns()
        viewer, _session, fixture, state = common.build_gallery(
            root, Path(args.source), args.seed, gallery_id=gallery_id,
            thumbnail_memory=memory, photo_count=extent,
            prepare_session=lambda session: configure_batch_media(session, gallery_id, bodies),
        )
        viewer.set_card_format(CARD_FORMAT)
        if (
            fixture["dataset_sha256"] != dataset_sha256
            or viewer._thumbnail_memory is not memory
            or ("canvas" if viewer._canvas_grid_enabled else "native") != args.cache_renderer
        ):
            raise RuntimeError("The mounted fixture, cache owner or renderer differs.")
        original = viewer._prepare_thumbnail

        def observe_decode(body: bytes) -> Image.Image:
            """Time one invocation of the original thumbnail preparation.

            Args:
                body (bytes): Fixture image body returned through the media boundary.

            Returns:
                Image.Image: Original prepared raster from the production worker.
            """
            decode_start = time.perf_counter_ns()
            try:
                return original(body)
            finally:
                recorder.add("thumbnail_prepare", time.perf_counter_ns() - decode_start)

        viewer._prepare_thumbnail = observe_decode

        def catalogue_ready() -> bool:
            """Continue existing pagination until all synthetic descriptors are ready.

            Returns:
                bool: Validated catalogue and first visible viewport are ready.
            """
            assert viewer is not None
            if len(viewer._photos) < extent:
                if viewer._worker is None and viewer._next_after is not None:
                    viewer._load_more()
                return False
            return quiet_viewport()

        await_state(catalogue_ready)
        ids = [photo.image_id for photo in viewer._photos]
        if ids != list(range(common.FIRST_IMAGE_ID, common.FIRST_IMAGE_ID + extent)):
            raise RuntimeError("Catalogue descriptors differ from the exact ordered fixture.")
        expected_pages = (extent + common.PHOTO_PAGE_SIZE - 1) // common.PHOTO_PAGE_SIZE
        if state["page_reads"] != expected_pages:
            raise RuntimeError("Catalogue did not read each of its expected pages once.")
        return (time.perf_counter_ns() - start) / common.NS_PER_MS

    def sweep() -> tuple[int, int]:
        """Require every photo to appear in a settled viewport in each direction.

        Returns:
            tuple[int, int]: Counts of distinct rendered photo indices down and back.
        """
        assert viewer is not None
        visited: list[int] = []
        for direction in ("down", "back"):
            seen: set[int] = set()
            while True:
                seen.update(viewer._visible_thumbnail_indices)
                memory_state = memory.metrics_snapshot()
                if memory_state["entries"] > entry_limit or memory_state["bytes"] > byte_limit:
                    raise RuntimeError("Decoded cache exceeded the applied treatment.")
                first, last = viewer.grid_canvas.yview()
                at_end = last == 1.0 if direction == "down" else first == 0.0
                if at_end:
                    break
                stride = (last - first) * SWEEP_STRIDE
                position = min(1.0, first + stride) if direction == "down" else max(0.0, first - stride)
                start = time.perf_counter_ns()
                invoke_scrollbar_moveto(root, viewer, position)
                root.update_idletasks()
                recorder.add("interaction_feedback", time.perf_counter_ns() - start)
                await_state(viewport_ready)
                new_first, _last = viewer.grid_canvas.yview()
                advanced = new_first > first if direction == "down" else new_first < first
                if not advanced:
                    raise RuntimeError(f"Scrollbar stopped moving during the {direction} sweep.")
                recorder.add("viewport_settle", time.perf_counter_ns() - start)
            if seen != set(range(count)):
                raise RuntimeError(
                    f"{direction} sweep covered {len(seen)} of {count} photos; "
                    f"min={min(seen, default=-1)} max={max(seen, default=-1)}; "
                    f"grid_extent={viewer.grid_canvas.cget('scrollregion')}."
                )
            visited.append(len(seen))
        await_state(quiet_viewport)
        return visited[0], visited[1]

    try:
        recorder.install()
        if args.cache == "warm":
            phase = "full warm priming sweep"
            mount(common.GALLERY_ID, count)
            sweep()
            retire()
        if args.capacity_phase == "reopen":
            phase = "switch to second gallery"
            mount(common.SECOND_GALLERY_ID, min(count, LIFECYCLE_PHOTO_COUNT))
            retire()

        phase = f"measured {args.capacity_phase} and full sweep"
        before = memory.metrics_snapshot()
        if args.cache == "cold" and before["entries"]:
            raise RuntimeError("Cold measurement did not start with an empty cache.")
        if args.cache == "warm" and not before["entries"]:
            raise RuntimeError("Warm priming did not retain decoded rasters.")
        recorder.phase = "measured"
        recorder.start_heartbeat(root)
        cpu_start = time.process_time_ns()
        wall_start = time.perf_counter_ns()
        mount_ms = mount(common.GALLERY_ID, count)
        sweep_start = time.perf_counter_ns()
        down, back = sweep()
        sweep_end = time.perf_counter_ns()
        cpu_ms = (time.process_time_ns() - cpu_start) / common.NS_PER_MS
        recorder.stop_heartbeat()
        recorder.phase = "warmup"
        measured = memory.metrics_snapshot()
        if args.cache == "cold" and not viewer._http_batch_requests:
            raise RuntimeError("Cold traversal did not exercise the framed thumbnail path.")
        process_memory = psutil.Process().memory_info()
        if not hasattr(process_memory, PEAK_MEMORY_FIELD):
            raise RuntimeError("The hosted process does not expose a peak working set.")

        samples = {
            name: [event["ns"] for event in recorder.events if event["metric"] == name]
            for name in (
                "event_loop_drift", "callback", "interaction_feedback",
                "image_publication", "viewport_settle", "thumbnail_prepare",
            )
        }
        metrics: dict[str, float] = {
            "scenario_elapsed_ms": (sweep_end - wall_start) / common.NS_PER_MS,
            "mount_ms": mount_ms,
            "sweep_ms": (sweep_end - sweep_start) / common.NS_PER_MS,
            "measured_cpu_ms": cpu_ms,
            "measured_peak_working_set_bytes": getattr(process_memory, PEAK_MEMORY_FIELD),
            "decoded_cache_hits_count": measured["hits"] - before["hits"],
            "decoded_cache_misses_count": measured["misses"] - before["misses"],
            "decoded_cache_evictions_count": measured["evictions"] - before["evictions"],
            "decoded_cache_expirations_count": measured["expirations"] - before["expirations"],
            "decoded_cache_end_bytes": measured["bytes"],
            "decoded_cache_end_count": measured["entries"],
            "thumbnail_decode_count": len(samples["thumbnail_prepare"]),
            "tk_publication_count": len(samples["image_publication"]),
            "synthetic_batch_groups_count": viewer._http_batch_requests,
        }
        for name, values in samples.items():
            if name == "thumbnail_prepare":
                continue
            for label, fraction in common.LATENCY_FRACTIONS.items():
                value = common.percentile_ms(values, fraction)
                if value is None:
                    raise RuntimeError(f"No measured samples for {name}.")
                metrics[f"{name}_{label}_ms"] = value
        return {
            "stage": "capacity",
            "metrics": metrics,
            "operations": {"down_photos": down, "back_photos": back, "mounted_photos": count},
            "metadata": {
                "renderer": args.cache_renderer, "capacity_policy": args.capacity_policy,
                "capacity_phase": args.capacity_phase, "card_format": CARD_FORMAT,
                "media_fixture": "public framed thumbnails",
                "capacity_bytes": byte_limit, "capacity_entries": entry_limit,
                "photo_count": count, "instrumentation": args.instrumentation,
                "fixture_bundle_sha256": args.fixture_bundle_sha256,
                "fixture_dataset_sha256": dataset_sha256,
                "fixture_common_sha256": args.fixture_common_sha256,
                "python": sys.version, "tcl": str(root.tk.call("info", "patchlevel")),
                "tk": str(root.tk.call("package", "provide", "Tk")),
                "tk_windowing_system": str(root.tk.call("tk", "windowingsystem")),
                "display": [root.winfo_screenwidth(), root.winfo_screenheight()],
                "dpi": root.winfo_fpixels("1i"), "pillow": PILLOW_VERSION,
                "interaction_latency_semantics": "Tk idle and settled image proxies",
            },
            "resources": {"cache_before": before, "cache_after": measured},
            "raw_events": recorder.events,
            "raw_events_truncated": recorder.truncated,
        }
    finally:
        recorder.stop_heartbeat()
        try:
            retire()
        finally:
            recorder.restore()
            root.destroy()
            memory.clear()


def main() -> int:
    """Run a standalone hosted child and write its exact identity and result.

    Returns:
        int: Zero for completed evidence, one for a workload failure.
    """
    args = parse_args(capacity=True)
    if args.instrumentation != "on":
        raise ValueError("Cache-capacity trials require instrumented evidence.")
    if args.capacity_phase == "reopen" and args.cache != "warm":
        raise ValueError("Gallery-return trials require a retained prior sweep.")
    report: dict[str, common.JsonValue] = {
        "schema_version": 1, "seed": args.seed, "scenario": args.scenario,
        "cache": args.cache, "trial": args.trial, "source": args.source_id,
        "status": "FAIL", "failure": None, "missing_required_metrics": [],
        "missing_metrics": ["physical_presentation_ms"],
    }
    try:
        with tempfile.TemporaryDirectory(prefix="php-gallery-399-cache-") as temporary:
            report.update(run(args, Path(temporary)))
        report["status"] = "PASS"
    except Exception as error:
        report["failure"] = f"{type(error).__name__}: {error}"
        traceback.print_exc()
    Path(args.output).write_text(
        json.dumps(report, allow_nan=False, sort_keys=True) + "\n", encoding="utf-8",
    )
    return 0 if report["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
