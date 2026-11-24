# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_lightbox_raster.py
# Module Type: Windows Tooling Regression Test
# Purpose: Verify bounded latest-wins Lightbox raster ownership.
# Responsibilities:
#   - Exercise replacement and invalidation with a deterministically held resize.
#   - Verify source retirement waits for active work and lane close is nonblocking.
#   - Verify retained Lightbox Canvas publication rejects stale raster results.
#   - Compare an optional real Pillow crop-resize result with legacy semantics.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Test the thread-only Lightbox raster lane and image ownership contract."""

import sys
import threading
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

try:
    from PIL import Image, ImageDraw
except ImportError:
    Image = None

from uploader.lightbox_raster import (
    LightboxRasterWorker,
    RasterOwner,
    RasterRequest,
    RasterResult,
    SourcePreparationResult,
)
from uploader import gallery_lightbox as LIGHTBOX_MODULE
from uploader.gallery_lightbox import PhotoLightbox

# Purpose: Bound waits for deterministic worker entry and terminal events.
# Type: float. Units: seconds. Scope: one isolated raster worker regression.
# Consumers: ControlledImage.resize and raster completion assertions.
# Rationale: Local worker actions should finish promptly; a missed transition
# fails the test instead of leaving a hosted test thread blocked indefinitely.
TEST_WORKER_WAIT_SECONDS = 3.0

# Purpose: Bound polling for the worker's public nonblocking result handoff.
# Type: float. Units: seconds. Scope: one awaited Lightbox raster result.
# Consumers: wait_for_result. Rationale: The lane intentionally exposes no Tk
# callback; bounded polling waits only for its independently owned completion.
TEST_RESULT_WAIT_SECONDS = 3.0

# Purpose: Yield briefly between nonblocking result-handoff observations.
# Type: float. Units: seconds. Scope: test-only worker result polling.
# Consumers: wait_for_result. Rationale: Avoid a busy loop while keeping the
# polling interval small relative to the named worker completion deadline.
TEST_RESULT_POLL_SECONDS = 0.01


class FakeRaster:
    """Represent one disposable resize output without importing Pillow."""

    def __init__(self, size: tuple[int, int]) -> None:
        """Create a tracked output image.

        Args:
            size (tuple[int, int]): Output width and height in pixels.
        Returns:
            None: Size and disposal observation start initialized.
        """
        self.size = size
        self.closed = threading.Event()

    def close(self) -> None:
        """Record disposal by the worker or the result owner.

        Returns:
            None: The close event is set for deterministic ownership checks.
        """
        self.closed.set()


class ControlledImage:
    """Hold one resize on demand and record the exact Pillow call shape."""

    def __init__(self, *, hold_first: bool = False) -> None:
        """Create a source raster with optional first-call synchronization.

        Args:
            hold_first (bool): Hold the first resize until explicitly released.
        Returns:
            None: Source size, call history and lifecycle events are initialized.
        """
        self.size = (8, 6)
        self.hold_first = hold_first
        self.started = threading.Event()
        self.output_ready = threading.Event()
        self.release_resize = threading.Event()
        self.closed = threading.Event()
        self.calls: list[
            tuple[tuple[int, int], int, tuple[int, int, int, int]]
        ] = []
        self.rasters: list[FakeRaster] = []

    def resize(
        self,
        size: tuple[int, int],
        resampling: int,
        *,
        box: tuple[int, int, int, int],
    ) -> FakeRaster:
        """Capture the requested crop and optionally block the first resize.

        Args:
            size (tuple[int, int]): Requested output width and height.
            resampling (int): Pillow resampling value supplied by the worker.
            box (tuple[int, int, int, int]): Source crop passed to Pillow.
        Returns:
            FakeRaster: Tracked output with the requested dimensions.

        Raises:
            TimeoutError: The test did not release its held resize in time.
        """
        self.calls.append((size, resampling, box))
        if self.hold_first and len(self.calls) == 1:
            self.started.set()
            if not self.release_resize.wait(TEST_WORKER_WAIT_SECONDS):
                raise TimeoutError("Held fixture resize was not released.")
        raster = FakeRaster(size)
        self.rasters.append(raster)
        self.output_ready.set()
        return raster

    def close(self) -> None:
        """Record that the worker retired its source image.

        Returns:
            None: Source disposal is observable by its close event.
        """
        self.closed.set()


def wait_for_result(
    worker: LightboxRasterWorker, owner: RasterOwner,
) -> RasterResult:
    """Await a current result through the public nonblocking handoff.

    Args:
        worker (LightboxRasterWorker): Lane producing the raster result.
        owner (RasterOwner): Current owner token required for result transfer.
    Returns:
        RasterResult: Completed result transferred to the caller.

    Raises:
        AssertionError: No result became available within the fixture deadline.
    """
    deadline = time.monotonic() + TEST_RESULT_WAIT_SECONDS
    while time.monotonic() < deadline:
        result = worker.take_result(owner)
        if result is not None:
            return result
        time.sleep(TEST_RESULT_POLL_SECONDS)
    raise AssertionError("Lightbox raster result did not become ready.")


def wait_for_preparation(
    worker: LightboxRasterWorker, owner: RasterOwner,
) -> SourcePreparationResult:
    """Await a current source-crop result through the public handoff.

    Args:
        worker (LightboxRasterWorker): Lane producing the crop result.
        owner (RasterOwner): Current owner token required for result transfer.
    Returns:
        SourcePreparationResult: Crop result transferred to the caller.

    Raises:
        AssertionError: No preparation result became ready before the deadline.
    """
    deadline = time.monotonic() + TEST_RESULT_WAIT_SECONDS
    while time.monotonic() < deadline:
        result = worker.take_source_preparation(owner)
        if result is not None:
            return result
        time.sleep(TEST_RESULT_POLL_SECONDS)
    raise AssertionError("Lightbox source preparation did not become ready.")


class LightboxRasterWorkerTests(unittest.TestCase):
    """Keep worker backlog, stale output and source ownership bounded."""

    def test_newest_pending_request_replaces_older_viewport_requests(self) -> None:
        """Run only the newest queued request after an active resize.

        Returns:
            None: The active stale raster is disposed and the newest queued
                crop request is the only result transferred to the caller.
        """
        worker = LightboxRasterWorker()
        owner = RasterOwner()
        source = ControlledImage(hold_first=True)
        self.addCleanup(worker.close)
        self.addCleanup(source.release_resize.set)
        self.assertTrue(worker.set_source(owner, source))
        first = RasterRequest(1, (0, 0, 8, 6), (4, 3), 2)
        middle = RasterRequest(2, (1, 1, 7, 5), (3, 2), 3)
        newest = RasterRequest(3, (2, 1, 8, 6), (5, 4), 1)

        self.assertTrue(worker.submit(owner, first))
        self.assertTrue(source.started.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertTrue(worker.has_pending_work(owner))
        self.assertTrue(worker.submit(owner, middle))
        self.assertTrue(worker.submit(owner, newest))
        self.assertEqual(1, len(source.calls))

        source.release_resize.set()
        result = wait_for_result(worker, owner)
        self.assertIsNotNone(result.raster, result.error)
        self.addCleanup(result.raster.close)

        self.assertEqual(3, result.serial)
        self.assertEqual(newest.crop_box, result.crop_box)
        self.assertEqual(newest.output_size, result.output_size)
        self.assertEqual(newest.resampling, result.resampling)
        self.assertFalse(worker.has_pending_work(owner))
        self.assertEqual(
            [
                (first.output_size, first.resampling, first.crop_box),
                (newest.output_size, newest.resampling, newest.crop_box),
            ],
            source.calls,
        )
        self.assertTrue(source.rasters[0].closed.is_set())

    def test_invalidate_discards_active_pixels_but_retains_source(self) -> None:
        """Reject an active stale result while allowing a fresh request.

        Returns:
            None: Invalidation closes the late output, leaves the authorized
                source available, and admits the next request for that owner.
        """
        worker = LightboxRasterWorker()
        owner = RasterOwner()
        source = ControlledImage(hold_first=True)
        self.addCleanup(worker.close)
        self.addCleanup(source.release_resize.set)
        self.assertTrue(worker.set_source(owner, source))
        stale = RasterRequest(10, (0, 0, 8, 6), (4, 3), 2)
        current = RasterRequest(11, (1, 0, 7, 6), (6, 4), 1)

        self.assertTrue(worker.submit(owner, stale))
        self.assertTrue(source.started.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertTrue(worker.invalidate(owner))
        self.assertFalse(worker.has_pending_work(owner))
        self.assertFalse(source.closed.is_set())
        source.release_resize.set()
        self.assertTrue(source.output_ready.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertTrue(source.rasters[0].closed.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertIsNone(worker.take_result(owner))
        self.assertFalse(worker.has_pending_work(owner))
        self.assertFalse(source.closed.is_set())

        self.assertTrue(worker.submit(owner, current))
        result = wait_for_result(worker, owner)
        self.assertIsNotNone(result.raster, result.error)
        self.addCleanup(result.raster.close)
        self.assertEqual(current.serial, result.serial)
        self.assertEqual(2, len(source.calls))

    def test_source_crop_preparation_runs_before_viewport_resize(self) -> None:
        """Run matte detection on the lane and gate raster submission on it.

        Returns:
            None: Detector output preserves its crop and source ownership while
                all Pillow operations remain off the Tk caller thread.
        """
        worker = LightboxRasterWorker()
        owner = RasterOwner()
        source = ControlledImage()
        detector_threads: list[threading.Thread] = []
        crop_box = (1, 1, 7, 5)
        self.addCleanup(worker.close)
        self.assertTrue(worker.set_source(
            owner,
            source,
            detector=lambda image: (
                detector_threads.append(threading.current_thread())
                or crop_box
            ),
        ))
        request = RasterRequest(15, crop_box, (5, 3), 2)

        self.assertFalse(worker.submit(owner, request))
        preparation = wait_for_preparation(worker, owner)

        self.assertEqual(crop_box, preparation.crop_box)
        self.assertIsNone(preparation.error)
        self.assertEqual(1, len(detector_threads))
        self.assertIsNot(threading.current_thread(), detector_threads[0])
        self.assertFalse(source.closed.is_set())
        self.assertTrue(worker.submit(owner, request))
        result = wait_for_result(worker, owner)
        self.assertEqual(crop_box, result.crop_box)
        self.addCleanup(result.raster.close)

    def test_lane_matte_detector_preserves_legacy_crop_output(self) -> None:
        """Keep asynchronous matte bounds equal to the existing detector API.

        Returns:
            None: Worker execution moves unchanged detector pixel behavior off
                Tk and retains the same crop coordinates for viewport requests.
        """
        if Image is None:
            self.skipTest("Pillow is optional for portable raster-lane coverage.")
        image = Image.new("RGB", (24, 18), "black")
        ImageDraw.Draw(image).rectangle(
            (0, 3, 23, 14), fill="#B8425A",
        )
        expected = LIGHTBOX_MODULE.detect_black_matte_crop(image)
        worker = LightboxRasterWorker()
        owner = RasterOwner()
        self.addCleanup(worker.close)

        self.assertTrue(worker.set_source(
            owner, image, detector=LIGHTBOX_MODULE.detect_black_matte_crop,
        ))
        result = wait_for_preparation(worker, owner)

        self.assertIsNone(result.error)
        self.assertEqual(expected, result.crop_box)

    def test_invalidated_source_crop_result_is_rejected_by_epoch(self) -> None:
        """Drop crop bounds computed for a selection that was invalidated.

        Returns:
            None: Active source access completes safely while no result from its
                superseded epoch can be transferred to the Lightbox owner.
        """
        worker = LightboxRasterWorker()
        owner = RasterOwner()
        source = ControlledImage()
        started = threading.Event()
        release_scan = threading.Event()
        finished = threading.Event()
        self.addCleanup(worker.close)
        self.addCleanup(release_scan.set)

        def hold_detector(
            image: ControlledImage,
        ) -> tuple[int, int, int, int]:
            """Pause one detector until its selection epoch is invalidated.

            Args:
                image (ControlledImage): Source retained by the worker lane.
            Returns:
                tuple[int, int, int, int]: A stale crop that must be discarded.
            """
            started.set()
            if not release_scan.wait(TEST_WORKER_WAIT_SECONDS):
                raise TimeoutError("Held source detector was not released.")
            finished.set()
            return (0, 0, 8, 6)

        self.assertTrue(worker.set_source(owner, source, detector=hold_detector))
        self.assertTrue(started.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertTrue(worker.invalidate(owner))
        self.assertFalse(worker.has_pending_work(owner))
        release_scan.set()
        self.assertTrue(finished.wait(TEST_WORKER_WAIT_SECONDS))

        self.assertIsNone(worker.take_source_preparation(owner))
        self.assertFalse(worker.has_pending_work(owner))
        self.assertFalse(source.closed.is_set())

    def test_release_and_owner_replacement_wait_for_active_source_use(self) -> None:
        """Release an old owner only after its active resize stops reading.

        Returns:
            None: Old pixels are discarded and closed while the reused lane
                returns a result only for the replacement runtime owner.
        """
        worker = LightboxRasterWorker()
        old_owner = RasterOwner()
        new_owner = RasterOwner()
        old_source = ControlledImage(hold_first=True)
        new_source = ControlledImage()
        self.addCleanup(worker.close)
        self.addCleanup(old_source.release_resize.set)
        self.assertTrue(worker.set_source(old_owner, old_source))
        old_request = RasterRequest(20, (0, 0, 8, 6), (4, 3), 2)
        new_request = RasterRequest(21, (0, 1, 8, 6), (5, 3), 1)

        self.assertTrue(worker.submit(old_owner, old_request))
        self.assertTrue(old_source.started.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertTrue(worker.release(old_owner))
        self.assertFalse(worker.has_pending_work(old_owner))
        self.assertFalse(old_source.closed.is_set())
        self.assertTrue(worker.set_source(new_owner, new_source))
        self.assertTrue(worker.submit(new_owner, new_request))
        self.assertTrue(worker.has_pending_work(new_owner))
        old_source.release_resize.set()

        self.assertTrue(old_source.closed.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertIsNone(worker.take_result(old_owner))
        result = wait_for_result(worker, new_owner)
        self.assertIsNotNone(result.raster, result.error)
        self.addCleanup(result.raster.close)
        self.assertEqual(new_request.serial, result.serial)
        self.assertIs(result.owner, new_owner)
        self.assertTrue(old_source.rasters[0].closed.is_set())
        self.assertFalse(new_source.closed.is_set())

    def test_close_returns_while_active_work_finishes_and_disposes_output(self) -> None:
        """Close the lane without joining its held resize on the caller thread.

        Returns:
            None: Close returns before the gate opens; late output and source
                are disposed by the worker after the test releases its gate.
        """
        worker = LightboxRasterWorker()
        owner = RasterOwner()
        source = ControlledImage(hold_first=True)
        close_returned = threading.Event()
        self.addCleanup(source.release_resize.set)
        self.addCleanup(worker.close)
        self.assertTrue(worker.set_source(owner, source))
        self.assertTrue(worker.submit(
            owner, RasterRequest(30, (0, 0, 8, 6), (4, 3), 2),
        ))
        self.assertTrue(source.started.wait(TEST_WORKER_WAIT_SECONDS))

        closer = threading.Thread(
            target=lambda: (worker.close(), close_returned.set()),
            daemon=True,
        )
        closer.start()
        self.addCleanup(closer.join, TEST_WORKER_WAIT_SECONDS)
        self.assertTrue(close_returned.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertFalse(source.closed.is_set())

        source.release_resize.set()
        self.assertTrue(source.output_ready.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertTrue(source.rasters[0].closed.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertTrue(source.closed.wait(TEST_WORKER_WAIT_SECONDS))
        self.assertIsNone(worker.take_result(owner))
        self.assertFalse(worker.has_pending_work(owner))

    def test_pillow_output_matches_legacy_crop_resize_when_available(self) -> None:
        """Keep actual Pillow output pixels equal to the synchronous legacy call.

        Returns:
            None: The worker passes output size, resampling and source box through
                unchanged when Pillow is installed for this test environment.
        """
        if Image is None:
            self.skipTest("Pillow is optional for portable raster-lane coverage.")

        source = Image.new("RGB", (8, 6))
        self.addCleanup(source.close)
        for y in range(6):
            for x in range(8):
                source.putpixel((x, y), (x * 20, y * 30, (x + y) * 10))
        crop_box = (1, 1, 7, 5)
        output_size = (5, 3)
        resampling = Image.Resampling.BILINEAR
        expected = source.resize(output_size, resampling, box=crop_box)
        owner = RasterOwner()
        worker = LightboxRasterWorker()
        self.addCleanup(worker.close)
        self.assertTrue(worker.set_source(owner, source))
        self.assertTrue(worker.submit(
            owner,
            RasterRequest(40, crop_box, output_size, resampling),
        ))
        result = wait_for_result(worker, owner)
        self.assertIsNotNone(result.raster)
        self.addCleanup(result.raster.close)

        self.assertEqual(expected.tobytes(), result.raster.tobytes())
        expected.close()


class PhotoLightboxRasterPublicationTests(unittest.TestCase):
    """Verify current worker pixels publish once through retained Tk items."""

    def _make_lightbox(self) -> PhotoLightbox:
        """Create the complete owner state needed by focused bypass tests.

        Returns:
            PhotoLightbox: Constructor-bypassed owner with current raster state.
        """
        lightbox = object.__new__(PhotoLightbox)
        lightbox._active = True
        lightbox._fullscreen = False
        lightbox._fullscreen_center = None
        lightbox._source_image = object()
        lightbox._crop_box = (0, 0, 8, 6)
        lightbox._detected_crop_box = (0, 0, 8, 6)
        lightbox._content_crop_enabled = True
        lightbox._zoom = 1.0
        lightbox._photo = None
        lightbox._photo_item_id = None
        lightbox._photo_source_box = None
        lightbox._pending_header = None
        lightbox._status_message = "Loading"
        lightbox._status_item_id = None
        lightbox._pan_origin = None
        lightbox._resize_job = None
        lightbox._resize_preview_job = None
        lightbox._raster_worker = mock.Mock()
        lightbox._raster_owner_token = RasterOwner()
        lightbox._raster_serial = 0
        lightbox._raster_poll_job = None
        lightbox._raster_quality = "final"
        lightbox._raster_last_error = None
        lightbox._raster_source_ready = True
        lightbox._raster_request_inflight = False
        lightbox._raster_preview_deferred = False
        lightbox._source_preparation_pending = False
        lightbox._initial_final_pending = False
        lightbox._raster_observer = mock.Mock()
        lightbox._photo_resolution_sent = False
        lightbox._on_photo_resolved = None
        lightbox.window = mock.Mock()
        lightbox.window.after.return_value = "raster-poll"
        lightbox._canvas = mock.Mock()
        lightbox._canvas.winfo_width.return_value = 640
        lightbox._canvas.winfo_height.return_value = 480
        lightbox._canvas.find_withtag.return_value = ()
        lightbox._overlay = mock.Mock()
        lightbox._toolbar = mock.Mock()
        lightbox._previous_edge = mock.Mock()
        lightbox._next_edge = mock.Mock()
        lightbox._location_panel = mock.Mock()
        lightbox._center_or_constrain = mock.Mock()
        lightbox._draw_status = mock.Mock()
        lightbox._render_hdr = mock.Mock(return_value=True)
        lightbox._refresh_hdr_controls = mock.Mock()
        lightbox._render_image = mock.Mock()
        return lightbox

    def test_final_request_publishes_only_after_retained_canvas_attachment(self) -> None:
        """Measure a successful final attach at the actual Canvas boundary.

        Returns:
            None: Request timing follows accepted submit and successful
                publication timing is sampled after Canvas attachment and
                positioning, before status and HDR refresh.
        """
        if LIGHTBOX_MODULE.Image is None:
            self.skipTest("Pillow is optional for portable Lightbox coverage.")
        lightbox = self._make_lightbox()
        lightbox._raster_source_ready = True
        lightbox._crop_box = (0, 0, 800, 600)
        lightbox._raster_worker.submit.return_value = True
        photo = object()
        with mock.patch.object(
            LIGHTBOX_MODULE,
            "ImageTk",
            SimpleNamespace(PhotoImage=mock.Mock(return_value=photo)),
        ):
            PhotoLightbox._render_image(lightbox)
            request = lightbox._raster_worker.submit.call_args.args[1]
            raster = FakeRaster(request.output_size)
            lightbox._raster_worker.take_result.return_value = RasterResult(
                lightbox._raster_owner_token,
                request.serial,
                request.crop_box,
                request.output_size,
                request.resampling,
                raster,
                None,
                800 * 600,
                request.output_size[0] * request.output_size[1],
            )
            lightbox._canvas.create_image.return_value = 73
            lightbox._raster_poll_job = "raster-poll"
            lightbox._poll_raster_result()

        self.assertEqual((0, 0, 800, 600), request.crop_box)
        # The 640x480 fixture leaves the existing 48x92 insets unchanged.
        self.assertEqual((517, 388), request.output_size)
        self.assertEqual("final", lightbox._raster_quality)
        lightbox.window.after.assert_called_once_with(
            LIGHTBOX_MODULE.LIGHTBOX_RASTER_POLL_MS,
            lightbox._poll_raster_result,
        )
        lightbox._canvas.create_image.assert_called_once()
        lightbox._canvas.itemconfigure.assert_not_called()
        self.assertEqual(73, lightbox._photo_item_id)
        self.assertIs(photo, lightbox._photo)
        self.assertTrue(raster.closed.is_set())
        lightbox._raster_observer.on_request.assert_called_once()
        published = lightbox._raster_observer.on_published.call_args.args
        self.assertEqual(request.serial, published[0])
        self.assertEqual("final", published[1])
        self.assertIs(photo, published[2])
        self.assertEqual(73, published[3])
        self.assertEqual("create_image", published[4])
        self.assertTrue(published[5])
        self.assertGreaterEqual(
            published[6],
            lightbox._raster_observer.on_request.call_args.args[2],
        )

    def test_resize_preview_keeps_configured_filter_and_request_quality(self) -> None:
        """Keep preview filter and quality distinct from final LANCZOS work.

        Returns:
            None: A resize preview submits the configured internal filter and
                emits preview quality without canceling its trailing final job.
        """
        if LIGHTBOX_MODULE.Image is None:
            self.skipTest("Pillow is optional for portable Lightbox coverage.")
        lightbox = self._make_lightbox()
        lightbox._raster_source_ready = True
        lightbox._raster_worker.submit.return_value = True
        lightbox._resize_job = "settled-render"
        lightbox._render_image = PhotoLightbox._render_image.__get__(lightbox, PhotoLightbox)

        with mock.patch.object(LIGHTBOX_MODULE, "ImageTk", mock.Mock()):
            lightbox._render_resize_preview()

        request = lightbox._raster_worker.submit.call_args.args[1]
        self.assertEqual(
            getattr(
                LIGHTBOX_MODULE.Image.Resampling,
                LIGHTBOX_MODULE.LIGHTBOX_PREVIEW_RESAMPLING,
            ),
            request.resampling,
        )
        self.assertEqual("preview", lightbox._raster_quality)
        self.assertEqual("settled-render", lightbox._resize_job)
        lightbox._raster_observer.on_request.assert_called_once()
        self.assertEqual(
            "preview", lightbox._raster_observer.on_request.call_args.args[1],
        )

    def test_preview_waits_for_active_resize_then_uses_latest_geometry(self) -> None:
        """Avoid replacing every active preview during rapid geometry input.

        Returns:
            None: One active request is allowed to finish before a preview of
                the latest viewport is submitted, preserving visible feedback.
        """
        if LIGHTBOX_MODULE.Image is None:
            self.skipTest("Pillow is optional for portable Lightbox coverage.")
        lightbox = self._make_lightbox()
        lightbox._raster_request_inflight = True
        lightbox._resize_job = "settled-render"

        lightbox._render_resize_preview()

        self.assertTrue(lightbox._raster_preview_deferred)
        lightbox._render_image.assert_not_called()
        lightbox._raster_worker.submit.assert_not_called()

        lightbox._raster_request_inflight = False
        lightbox._resume_deferred_preview()

        lightbox._render_image.assert_called_once_with(
            resampling=getattr(
                LIGHTBOX_MODULE.Image.Resampling,
                LIGHTBOX_MODULE.LIGHTBOX_PREVIEW_RESAMPLING,
            ),
            preserve_resize_jobs=True,
        )
        self.assertFalse(lightbox._raster_preview_deferred)

    def test_crop_result_starts_an_offline_preview_before_settled_quality(self) -> None:
        """Admit the first viewport only after asynchronous matte discovery.

        Returns:
            None: The detected source crop is retained and the first submitted
                viewport uses the named lightweight preview filter.
        """
        if LIGHTBOX_MODULE.Image is None:
            self.skipTest("Pillow is optional for portable Lightbox coverage.")
        lightbox = self._make_lightbox()
        crop_box = (2, 1, 7, 5)
        lightbox._source_preparation_pending = True
        lightbox._raster_worker.take_source_preparation.return_value = (
            SourcePreparationResult(
                lightbox._raster_owner_token,
                4,
                crop_box,
                None,
            )
        )

        lightbox._poll_raster_result()

        self.assertEqual(crop_box, lightbox._detected_crop_box)
        self.assertEqual(crop_box, lightbox._crop_box)
        self.assertTrue(lightbox._raster_source_ready)
        self.assertFalse(lightbox._source_preparation_pending)
        lightbox._render_image.assert_called_once_with(
            resampling=LIGHTBOX_MODULE.Image.Resampling.BILINEAR,
        )

    def test_current_result_updates_the_existing_photo_canvas_item(self) -> None:
        """Replace the current Canvas image in place on a later resize.

        Returns:
            None: The Canvas keeps one photo item and reports itemconfigure as
                the actual successful publication operation.
        """
        lightbox = self._make_lightbox()
        lightbox._raster_serial = 9
        lightbox._raster_quality = "preview"
        lightbox._initial_final_pending = True
        lightbox._on_photo_resolved = mock.Mock()
        lightbox._title = mock.Mock()
        lightbox._position = mock.Mock()
        lightbox._pending_header = ("next-photo.jpg", "2 of 2")
        lightbox._photo_item_id = 73
        lightbox._canvas.find_withtag.return_value = (73,)
        raster = FakeRaster((320, 240))
        result = RasterResult(
            lightbox._raster_owner_token,
            9,
            (0, 0, 8, 6),
            (320, 240),
            2,
            raster,
            None,
            48,
            320 * 240,
        )
        photo = object()

        with mock.patch.object(
            LIGHTBOX_MODULE,
            "ImageTk",
            SimpleNamespace(PhotoImage=mock.Mock(return_value=photo)),
        ):
            lightbox._publish_raster_result(result, "preview")

        lightbox._canvas.itemconfigure.assert_called_once_with(73, image=photo)
        lightbox._canvas.create_image.assert_not_called()
        self.assertEqual(73, lightbox._photo_item_id)
        self.assertTrue(raster.closed.is_set())
        self.assertFalse(lightbox._initial_final_pending)
        lightbox._render_image.assert_called_once_with()
        lightbox._on_photo_resolved.assert_called_once_with(True)
        lightbox._title.configure.assert_called_once_with(text="next-photo.jpg")
        lightbox._position.configure.assert_called_once_with(text="2 of 2")
        published = lightbox._raster_observer.on_published.call_args.args
        self.assertEqual("preview", published[1])
        self.assertEqual("itemconfigure", published[4])

    def test_stale_result_is_closed_without_canvas_or_observer_publication(self) -> None:
        """Reject late worker pixels after a newer render serial is requested.

        Returns:
            None: Stale output is closed, not attached, and no successful
                publication callback is delivered.
        """
        lightbox = self._make_lightbox()
        lightbox._raster_serial = 12
        raster = FakeRaster((320, 240))
        lightbox._raster_worker.take_result.return_value = RasterResult(
            lightbox._raster_owner_token,
            11,
            (0, 0, 8, 6),
            (320, 240),
            2,
            raster,
            None,
            48,
            320 * 240,
        )
        lightbox._raster_worker.has_pending_work.return_value = False

        lightbox._poll_raster_result()

        self.assertTrue(raster.closed.is_set())
        lightbox._canvas.create_image.assert_not_called()
        lightbox._canvas.itemconfigure.assert_not_called()
        lightbox._raster_observer.on_published.assert_not_called()
        self.assertFalse(lightbox._photo_resolution_sent)
        lightbox.window.after.assert_not_called()

    def test_revoked_pending_raster_is_closed_before_canvas_attachment(self) -> None:
        """Reject completed pixels when the injected media guard is revoked.

        Returns:
            None: The worker result is closed, the active overlay is dismissed,
                and no unauthorized PhotoImage reaches the Canvas.
        """
        lightbox = self._make_lightbox()
        lightbox._can_publish = lambda: False
        lightbox.dismiss = mock.Mock()
        raster = FakeRaster((320, 240))
        result = RasterResult(
            lightbox._raster_owner_token,
            0,
            (0, 0, 8, 6),
            (320, 240),
            2,
            raster,
            None,
            48,
            320 * 240,
        )

        PhotoLightbox._publish_raster_result(lightbox, result, "final")

        self.assertTrue(raster.closed.is_set())
        lightbox.dismiss.assert_called_once_with()
        lightbox._canvas.create_image.assert_not_called()
        lightbox._canvas.itemconfigure.assert_not_called()
        lightbox._raster_observer.on_published.assert_not_called()
        self.assertFalse(lightbox._photo_resolution_sent)

    def test_visible_error_resolves_present_once_as_failure(self) -> None:
        """Report a shown current error to the slideshow owner exactly once.

        Returns:
            None: Visible failure resolves this selection without claiming that
                a photo was attached or changing the resolution result later.
        """
        lightbox = self._make_lightbox()
        lightbox._on_photo_resolved = mock.Mock()
        lightbox._clear_image = mock.Mock()
        lightbox._clear_location = mock.Mock()
        lightbox._location_panel.place_forget = mock.Mock()
        lightbox._apply_pending_header = mock.Mock()
        lightbox._show_status = mock.Mock()

        PhotoLightbox.show_error(lightbox, "Could not load this image.")
        PhotoLightbox.show_error(lightbox, "Could not load this image again.")

        lightbox._on_photo_resolved.assert_called_once_with(False)

    def test_revoked_incoming_image_is_closed_before_source_acceptance(self) -> None:
        """Close detail pixels arriving after local read permission is lost.

        Returns:
            None: A revoked image never transfers to the root-owned lane and
                an active overlay is dismissed immediately.
        """
        lightbox = self._make_lightbox()
        lightbox._can_publish = lambda: False
        lightbox.dismiss = mock.Mock()
        image = FakeRaster((8, 6))

        PhotoLightbox.show_prepared_image(lightbox, image)

        self.assertTrue(image.closed.is_set())
        lightbox.dismiss.assert_called_once_with()
        lightbox._raster_worker.set_source.assert_not_called()

    def test_invalidated_active_work_does_not_keep_tk_polling(self) -> None:
        """Stop polling while an invalidated worker job retires off-thread.

        Returns:
            None: The worker may still be disposing active stale pixels, but no
                current result can arrive until a new request is submitted.
        """
        lightbox = self._make_lightbox()
        lightbox._raster_request_inflight = True
        lightbox._raster_worker.take_result.return_value = None
        lightbox._raster_worker.has_pending_work.return_value = False

        lightbox._poll_raster_result()

        self.assertFalse(lightbox._raster_request_inflight)
        lightbox.window.after.assert_not_called()

    def test_next_photo_geometry_cannot_resubmit_the_retained_old_source(self) -> None:
        """Keep the old frame visible without resizing it during next-photo load.

        Returns:
            None: Selection invalidation fences source resampling until the new
                prepared image is installed, while old Canvas pixels remain held.
        """
        lightbox = self._make_lightbox()
        old_source = lightbox._source_image
        old_photo = object()
        lightbox._photo = old_photo
        lightbox.text = lambda key: key
        lightbox._clear_location = mock.Mock()
        lightbox._hdr_surface = mock.Mock()
        lightbox._location_panel.place_forget = mock.Mock()
        lightbox._previous_button = mock.Mock()
        lightbox._next_button = mock.Mock()
        lightbox._title = mock.Mock()
        lightbox._position = mock.Mock()
        lightbox._photo_resolution_sent = True
        lightbox._show_status = PhotoLightbox._show_status.__get__(
            lightbox, PhotoLightbox,
        )
        lightbox._draw_status = mock.Mock()
        lightbox._refresh_location = mock.Mock()

        PhotoLightbox.present(
            lightbox,
            "next-photo.jpg",
            "2 of 2",
            can_previous=True,
            can_next=False,
        )
        self.assertFalse(lightbox._raster_source_ready)
        self.assertFalse(lightbox._photo_resolution_sent)
        self.assertEqual(
            ("next-photo.jpg", "2 of 2"), lightbox._pending_header,
        )
        lightbox._title.configure.assert_not_called()
        lightbox._position.configure.assert_not_called()
        self.assertIs(old_source, lightbox._source_image)
        self.assertIs(old_photo, lightbox._photo)

        PhotoLightbox._queue_render(lightbox, mock.Mock())
        PhotoLightbox._render_resize_preview(lightbox)
        PhotoLightbox._render_image(lightbox)
        PhotoLightbox._change_zoom(lightbox, 1.25)
        PhotoLightbox._pan_start(lightbox, mock.Mock())

        lightbox._raster_worker.submit.assert_not_called()
        lightbox._render_image.assert_not_called()
        lightbox._canvas.create_image.assert_not_called()
        lightbox._canvas.itemconfigure.assert_not_called()
        self.assertIs(old_source, lightbox._source_image)
        self.assertIs(old_photo, lightbox._photo)

    def test_replacing_source_retains_canvas_photo_but_retires_old_hdr(self) -> None:
        """Hold authorized Canvas pixels until the replacement frame attaches.

        Returns:
            None: Pillow and HDR ownership retire immediately while the old Tk
                PhotoImage remains displayed without a stale HDR redraw path.
        """
        lightbox = self._make_lightbox()
        old_photo = object()
        lightbox._photo = old_photo
        lightbox._photo_item_id = 83
        lightbox._photo_source_box = (0, 0, 8, 6)
        lightbox._status_item_id = 93
        lightbox._hdr_surface = mock.Mock()

        PhotoLightbox._clear_image(
            lightbox,
            clear_status=False,
            preserve_photo=True,
        )

        lightbox._raster_worker.release.assert_called_once_with(
            lightbox._raster_owner_token,
        )
        lightbox._hdr_surface.clear.assert_called_once_with()
        lightbox._canvas.delete.assert_not_called()
        self.assertIs(old_photo, lightbox._photo)
        self.assertEqual(83, lightbox._photo_item_id)
        self.assertIsNone(lightbox._photo_source_box)
        self.assertEqual(93, lightbox._status_item_id)

        lightbox._status_message = None
        PhotoLightbox._refresh_hdr_controls(lightbox)

        lightbox._render_hdr.assert_not_called()
        self.assertIsNone(lightbox._source_image)

    def test_status_and_control_changes_retain_canvas_state_without_reraster(self) -> None:
        """Update status in place and realign HDR for UI-only control changes.

        Returns:
            None: Status uses one retained Canvas window and mapped controls
                refresh HDR occlusion without submitting a raster request.
        """
        lightbox = self._make_lightbox()
        lightbox._canvas.create_window.return_value = 91
        lightbox._status_label = None
        lightbox._status_message = "Loading"
        lightbox._status_fill = "#AABCCB"
        lightbox._draw_status = PhotoLightbox._draw_status.__get__(
            lightbox, PhotoLightbox,
        )

        with mock.patch.object(
            LIGHTBOX_MODULE.ttk,
            "Label",
            return_value=mock.Mock(),
        ):
            lightbox._draw_status()
            lightbox._status_message = "Still loading"
            lightbox._draw_status()

        lightbox._canvas.create_window.assert_called_once()
        lightbox._canvas.delete.assert_not_called()
        lightbox._canvas.itemconfigure.assert_called_once_with(
            91, window=lightbox._status_label,
        )
        self.assertEqual(91, lightbox._status_item_id)

        lightbox._active = True
        lightbox._source_image = object()
        lightbox._canvas.winfo_width.return_value = 640
        lightbox._canvas.winfo_height.return_value = 480
        lightbox._render_image.reset_mock()
        lightbox._refresh_hdr_controls.reset_mock()
        lightbox._show_controls(render=True)

        lightbox._render_image.assert_not_called()
        lightbox._refresh_hdr_controls.assert_called_once()
