# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_native_thumbnail_batch.py
# Module Type: Windows Native Regression Test
# Purpose: Verify streamed batch thumbnail delivery in the real Tk viewer.
# Responsibilities:
#   - Prove the first tile is displayed before its batch worker completes.
#   - Retain the four-item queue and captured session proof checks.
#   - Fence a queued partial-batch raster across an authoritative photo refresh.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise the native batch viewer using existing isolated Tk fixtures."""

import sys
import threading
import time
import unittest
from dataclasses import replace
from typing import Callable
from unittest import mock

import test_native_gallery_viewer as native_fixture
from uploader import app_linking as linking
from uploader import native_gallery_viewer as viewer_module


@unittest.skipUnless(
    sys.platform == 'win32' and native_fixture.tk is not None
    and native_fixture.Image is not None and native_fixture.ImageTk is not None,
    'The native batch viewer regression requires Windows, Tk and Pillow.',
)
class NativeThumbnailBatchTests(unittest.TestCase):
    """Exercise batch callbacks against actual native viewer widgets."""

    setUp = native_fixture.NativeGalleryViewerTests.setUp
    _destroy_root = native_fixture.NativeGalleryViewerTests._destroy_root
    _wait_until = native_fixture.NativeGalleryViewerTests._wait_until
    _gallery_data = native_fixture.NativeGalleryViewerTests._gallery_data
    _session = native_fixture.NativeGalleryViewerTests._session
    _png = native_fixture.NativeGalleryViewerTests._png
    _open_viewer = native_fixture.NativeGalleryViewerTests._open_viewer
    _assert_no_browsing_toplevels = (
        native_fixture.NativeGalleryViewerTests._assert_no_browsing_toplevels
    )

    def _publication(
        self, session: mock.Mock, gallery_id: int, photo: linking.LinkedPhoto,
        body: bytes, *, ram_eligible: bool = False,
    ) -> linking.ThumbnailPublication:
        """Build a production-shaped publication for the mock proof seam.

        Args:
            session (mock.Mock): Exact fixture session owning this witness.
            gallery_id (int): Captured physical gallery identity.
            photo (linking.LinkedPhoto): Captured catalogue descriptor.
            body (bytes): Fixture PNG decoded by the real viewer.
            ram_eligible (bool): Fixture authorization for decoded reuse.

        Returns:
            linking.ThumbnailPublication: Immutable typed session result.
        """
        witness = linking.ThumbnailBatchWitness(
            session, 'https://gallery.example/Galerie', gallery_id, photo,
            'public', (photo.thumbnail_etag, photo.thumbnail_verified_at), None, 0,
        )
        return linking.ThumbnailPublication(
            witness, photo, 'ok', body, 'image/png', photo.thumbnail_etag,
            ram_eligible,
        )

    def test_group_size_adapts_only_after_healthy_batch(self) -> None:
        """Increase a healthy group and downshift exceptional observations.

        Returns:
            None: Small, slow and exceptional groups retain the initial limit.
        """
        stats = linking.ThumbnailBatchStats(
            batch_sizes={viewer_module.MEDIA_HTTP_BATCH_INITIAL_SIZE: 1},
        )
        completion = viewer_module._HttpBatchCompletion(
            1, 'ok', (), stats, linking.MediaReadTimings(), 0.0, True, 0,
        )
        self.assertEqual(viewer_module.MEDIA_HTTP_BATCH_STEADY_SIZE,
                         viewer_module._next_http_thumbnail_group_limit(completion))
        self.assertEqual(viewer_module.MEDIA_HTTP_BATCH_INITIAL_SIZE,
                         viewer_module._next_http_thumbnail_group_limit(replace(
                             completion, elapsed=viewer_module.MEDIA_HTTP_BATCH_SLOW_SECONDS,
                         )))
        stats.wire_failures = 1
        self.assertEqual(viewer_module.MEDIA_HTTP_BATCH_INITIAL_SIZE,
                         viewer_module._next_http_thumbnail_group_limit(completion))

    def test_first_batch_tile_is_visible_before_worker_completes(self) -> None:
        """Display a streamed tile while the same operation remains open.

        Returns:
            None: Tk displays the first tile before the held batch completes.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.supports_thumbnail_batch.return_value = True
        session.thumbnail_batch_preferred.return_value = True
        session.thumbnail_publication_current.return_value = True
        first_sent = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)

        def read_batch(
            gallery_id: int, photos: tuple[linking.LinkedPhoto, ...],
            publish: Callable[[linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
                               linking.MediaReadTimings, Callable[[], None]], None],
            cancelled: threading.Event, *, timings: linking.MediaReadTimings,
            stats: linking.ThumbnailBatchStats,
        ) -> None:
            """Publish one owned image and hold the remaining synthetic stream.

            Args:
                gallery_id (int): Captured physical gallery identifier.
                photos (tuple[linking.LinkedPhoto, ...]): Admitted descriptors.
                publish (Callable[[linking.ThumbnailPublication | linking.LegacyThumbnailPublication, linking.MediaReadTimings, Callable[[], None]], None]):
                    Worker-safe result callback.
                cancelled (threading.Event): Viewer cancellation owner.
                timings (linking.MediaReadTimings): Physical observations.
                stats (linking.ThumbnailBatchStats): Numeric group observations.

            Returns:
                None: The operation remains open until released or cancelled.
            """
            photo = photos[0]
            item = self._publication(session, gallery_id, photo, image_bytes[photo.image_id])
            timings.http_requests = 1
            timings.encoded_bytes = len(item.body)
            stats.batch_sizes[len(photos)] = 1
            stats.wire_items = 1
            publish(item, linking.MediaReadTimings(), lambda: None)
            first_sent.set()
            release.wait(native_fixture.TEST_GUI_WAIT_SECONDS)
            if cancelled.is_set():
                return
            for photo in photos[1:]:
                body = image_bytes[photo.image_id]
                publish(self._publication(session, gallery_id, photo, body),
                        linking.MediaReadTimings(), lambda: None)
                stats.wire_items += 1
                timings.encoded_bytes += len(body)

        session.media_thumbnails.side_effect = read_batch
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID, 'Batch',
        )
        viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: first_sent.is_set() and any(viewer._photo_images),
            'The first streamed thumbnail did not reach Tk.',
        )
        self.assertIsNotNone(viewer._worker)
        self.assertTrue(viewer._worker.is_alive())
        self.assertEqual(viewer_module.MEDIA_THUMBNAIL_BATCH_SIZE,
                         viewer._events.maxsize)
        release.set()
        self._wait_until(lambda: viewer._worker is None,
                         'The batch completion did not reach Tk.')
        self.assertIn('http_batches=1', viewer.render_diagnostics())
        self.assertIn('route=thumbnail_batch operations=1 http_attempts=1',
                      viewer.render_diagnostics())

    def test_partial_batch_refresh_discards_queued_raster(self) -> None:
        """Fence a queued later frame while refreshing a partial batch.

        Returns:
            None: Refresh waits for the cancelled batch worker, the fresh page
                removes its second photo, and the queued old raster is closed.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.supports_thumbnail_batch.return_value = True
        session.thumbnail_batch_preferred.return_value = True
        session.thumbnail_publication_current.return_value = True
        first_published = threading.Event()
        release_second = threading.Event()
        second_queued = threading.Event()
        release_batch = threading.Event()
        cancellation_observed = threading.Event()
        refresh_started = threading.Event()
        old_worker_alive_at_refresh: list[bool] = []

        def read_batch(
            gallery_id: int, photos: tuple[linking.LinkedPhoto, ...],
            publish: Callable[[linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
                               linking.MediaReadTimings, Callable[[], None]], None],
            cancelled: threading.Event, *, timings: linking.MediaReadTimings,
            stats: linking.ThumbnailBatchStats,
        ) -> None:
            """Publish two frames with a barrier around the queued second.

            Args:
                gallery_id (int): Captured physical gallery identity.
                photos (tuple[linking.LinkedPhoto, ...]): Admitted descriptors.
                publish (Callable[[linking.ThumbnailPublication | linking.LegacyThumbnailPublication, linking.MediaReadTimings, Callable[[], None]], None]):
                    Real viewer worker callback for each validated frame.
                cancelled (threading.Event): Captured batch cancellation signal.
                timings (linking.MediaReadTimings): Physical batch observations.
                stats (linking.ThumbnailBatchStats): Bounded batch counters.

            Returns:
                None: Leaves the second result queued until refresh cancels work.

            Raises:
                TimeoutError: A barrier was not released within the existing
                    native fixture wait bound.
            """
            timings.http_requests = 1
            stats.batch_sizes[len(photos)] = 1
            # Two fixture rows provide one displayed and one queued frame.
            for photo_index, photo in enumerate(photos[:2]):
                publish(
                    self._publication(
                        session, gallery_id, photo, image_bytes[photo.image_id],
                    ),
                    linking.MediaReadTimings(), lambda: None,
                )
                stats.wire_items += 1
                if photo_index == 0:
                    first_published.set()
                    if not release_second.wait(
                        native_fixture.TEST_GUI_WAIT_SECONDS,
                    ):
                        raise TimeoutError('Refresh did not release second frame.')
                    continue
                second_queued.set()
                if not release_batch.wait(native_fixture.TEST_GUI_WAIT_SECONDS):
                    raise TimeoutError('Refresh did not release batch worker.')
                if cancelled.is_set():
                    cancellation_observed.set()

        session.media_thumbnails.side_effect = read_batch
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID, 'Partial refresh',
        )
        viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self.addCleanup(release_second.set)
        self.addCleanup(release_batch.set)
        self._wait_until(
            lambda: first_published.is_set()
            and bool(viewer._photo_images)
            and viewer._photo_images[0] is not None
            and viewer._worker is not None
            and viewer._worker.is_alive(),
            'The first batch frame did not display before its second barrier.',
        )
        old_worker = viewer._worker
        assert old_worker is not None
        first_tile = viewer._tile_buttons[0]
        first_image = viewer._photo_images[0]
        old_generation = viewer._generation
        prior_page_calls = session.media_page.call_count
        fresh_page = linking.LinkedMediaPage(
            page.gallery_id, page.parent_id, (page.photos[0],), None,
        )

        def read_fresh_page(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
            *, timings: linking.MediaReadTimings | None = None,
        ) -> linking.LinkedMediaPage:
            """Return the authoritative page after the old worker exits.

            Args:
                gallery_id (int): Selected physical gallery identity.
                after_id (int | None): Exclusive page cursor, if present.
                cancelled (threading.Event | None): Refresh cancellation owner.
                timings (linking.MediaReadTimings | None): Optional page metrics.

            Returns:
                linking.LinkedMediaPage: Current page containing only the retained photo.
            """
            del gallery_id, after_id, cancelled, timings
            old_worker_alive_at_refresh.append(old_worker.is_alive())
            refresh_started.set()
            return fresh_page

        session.media_page.side_effect = read_fresh_page
        prepare_spy = mock.Mock(wraps=viewer._prepare_thumbnail)
        show_spy = mock.Mock(wraps=viewer._show_thumbnail)
        try:
            with mock.patch.object(
                viewer, '_prepare_thumbnail', new=prepare_spy,
            ), mock.patch.object(
                viewer, '_show_thumbnail', new=show_spy,
            ):
                release_second.set()
                self.assertTrue(
                    second_queued.wait(native_fixture.TEST_GUI_WAIT_SECONDS),
                    'The second raster was not queued by the real worker.',
                )
                with viewer._events.mutex:
                    queued_events = tuple(viewer._events.queue)
                queued_batch_items = tuple(
                    event for event in queued_events
                    if event[1] == 'thumbnail_batch_item'
                )
                self.assertEqual(1, len(queued_batch_items))
                queued_generation, _, queued_result = queued_batch_items[0]
                self.assertIsInstance(
                    queued_result, viewer_module._ThumbnailResult,
                )
                assert isinstance(queued_result, viewer_module._ThumbnailResult)
                self.assertEqual(old_generation, queued_generation)
                self.assertEqual(1, queued_result.index)
                stale_raster = queued_result.image
                self.assertIsNotNone(stale_raster)
                self.assertEqual(1, prepare_spy.call_count)
                self.assertEqual(
                    image_bytes[page.photos[1].image_id],
                    prepare_spy.call_args.args[0],
                )

                viewer.refresh_photos()
                self.assertTrue(viewer._thumbnail_cancelled.is_set())
                self.assertGreater(viewer._generation, old_generation)
                self.assertFalse(refresh_started.is_set())
                self.assertEqual(prior_page_calls, session.media_page.call_count)
                release_batch.set()
                self._wait_until(
                    lambda: refresh_started.is_set()
                    and viewer._worker is None
                    and viewer._photos == fresh_page.photos
                    and len(viewer._tile_buttons) == 1,
                    'The fresh page did not settle after the partial batch.',
                )

                self.assertTrue(cancellation_observed.is_set())
                self.assertEqual([False], old_worker_alive_at_refresh)
                self.assertEqual(prior_page_calls + 1, session.media_page.call_count)
                self.assertEqual((page.photos[0].image_id,), tuple(
                    photo.image_id for photo in viewer._photos
                ))
                self.assertIsNot(first_tile, viewer._tile_buttons[0])
                self.assertTrue(viewer._tile_buttons[0].winfo_exists())
                self.assertIs(first_image, viewer._photo_images[0])
                self.assertNotIn(1, tuple(
                    call.args[0] for call in show_spy.call_args_list
                ))
                assert stale_raster is not None
                with self.assertRaises(ValueError):
                    stale_raster.getpixel((0, 0))
                self.assertFalse(viewer._http_thumbnail_failure_latched)
                self.assertEqual((), viewer._http_batch_retry_indices)
                self.assertIsNone(viewer._http_batch_completion)
                self.assertTrue(viewer._events.empty())
                self.assertEqual(1, session.media_thumbnails.call_count)
        finally:
            release_second.set()
            release_batch.set()
            old_worker.join(native_fixture.TEST_WORKER_JOIN_SECONDS)
        self.assertFalse(old_worker.is_alive())

    def test_changed_session_proof_discards_queued_image(self) -> None:
        """Discard a queued batch raster when its proof changes before Tk.

        Returns:
            None: The prior tile remains and the obsolete raster is closed.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID, 'Batch',
        )
        viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: viewer._worker is None and bool(viewer._admitted_thumbnail_indices)
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices),
            'The initial thumbnail fixture did not settle.',
        )
        index = min(viewer._admitted_thumbnail_indices)
        previous = viewer._photo_images[index]
        # A four-pixel side is a structural, tiny disposable raster fixture.
        raster = native_fixture.Image.new('RGB', (4, 4), 'red')
        publication = self._publication(
            session, gallery.gallery_id, viewer._photos[index],
            image_bytes[viewer._photos[index].image_id], ram_eligible=True,
        )
        session.thumbnail_publication_current.return_value = False
        result = viewer_module._ThumbnailResult(
            gallery.gallery_id, index, viewer._photos[index], raster,
            linking.MediaReadTimings(), 0.0, time.perf_counter(), publication,
        )
        viewer._events.put_nowait((viewer._generation, 'thumbnail_batch_item', result))
        viewer._poll_queue()
        self.assertIs(previous, viewer._photo_images[index])
        with self.assertRaises(ValueError):
            raster.getpixel((0, 0))

    def test_unverified_original_uses_existing_single_read_path(self) -> None:
        """Preserve original single-photo handling when the owner declines batch.

        Returns:
            None: A capable server still uses existing GET and memory owners.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.supports_thumbnail_batch.return_value = True
        session.thumbnail_batch_preferred.return_value = False
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID, 'Batch',
        )
        viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: viewer._worker is None and bool(viewer._admitted_thumbnail_indices)
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices),
            'The single-read thumbnail fixture did not settle.',
        )
        self.assertGreater(session.media_photo.call_count, 0)
        session.media_thumbnails.assert_not_called()

    def test_close_releases_worker_blocked_by_full_gui_queue(self) -> None:
        """Close while a fifth streamed raster waits for the four-item queue.

        Returns:
            None: The worker exits and every queued result is released.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID, 'Batch',
        )
        viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: viewer._worker is None and viewer._events.empty()
            and bool(viewer._admitted_thumbnail_indices)
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices),
            'The initial thumbnail fixture did not settle.',
        )
        photos = tuple(
            replace(page.photos[0], image_id=native_fixture.TEST_FIRST_IMAGE_ID + index)
            for index in range(viewer_module.MEDIA_THUMBNAIL_BATCH_SIZE + 1)
        )
        entering_last = threading.Event()

        def read_batch(
            gallery_id: int, requested: tuple[linking.LinkedPhoto, ...],
            publish: Callable[[linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
                               linking.MediaReadTimings, Callable[[], None]], None],
            cancelled: threading.Event, *, timings: linking.MediaReadTimings,
            stats: linking.ThumbnailBatchStats,
        ) -> None:
            """Fill the GUI queue and enter one additional publication.

            Args:
                gallery_id (int): Captured physical gallery identifier.
                requested (tuple[linking.LinkedPhoto, ...]): Captured descriptors.
                publish (Callable[[linking.ThumbnailPublication | linking.LegacyThumbnailPublication, linking.MediaReadTimings, Callable[[], None]], None]):
                    Worker-safe result callback.
                cancelled (threading.Event): Viewer cancellation owner.
                timings (linking.MediaReadTimings): Physical observations.
                stats (linking.ThumbnailBatchStats): Numeric group observations.

            Returns:
                None: Attempts every item until cancellation stops publication.
            """
            del cancelled, timings, stats
            body = image_bytes[page.photos[0].image_id]
            for index, photo in enumerate(requested):
                if index == viewer_module.MEDIA_THUMBNAIL_BATCH_SIZE:
                    entering_last.set()
                publish(self._publication(session, gallery_id, photo, body),
                        linking.MediaReadTimings(), lambda: None)

        session.media_thumbnails.side_effect = read_batch
        viewer._thumbnail_cancelled.clear()
        worker = threading.Thread(
            target=viewer._run_http_thumbnail_batch,
            args=(viewer._generation, gallery.gallery_id, tuple(range(len(photos))),
                  photos, viewer._thumbnail_cancelled),
        )
        worker.start()
        self.assertTrue(entering_last.wait(native_fixture.TEST_WORKER_JOIN_SECONDS))
        self.assertEqual(viewer_module.MEDIA_THUMBNAIL_BATCH_SIZE, viewer._events.qsize())
        viewer.close()
        worker.join(native_fixture.TEST_WORKER_JOIN_SECONDS)
        self.assertFalse(worker.is_alive())
        self.assertTrue(viewer._events.empty())

    def test_partial_batch_error_blocks_remaining_network_until_retry(self) -> None:
        """Keep later viewport candidates idle after a partially failed batch.

        Returns:
            None: The first tile displays, pending work waits and Retry clears
                the failure latch without an automatic single-read fallback.
        """
        page, presentation, image_bytes = self._gallery_data()
        photos = tuple(
            replace(page.photos[0], image_id=native_fixture.TEST_FIRST_IMAGE_ID + index)
            for index in range(viewer_module.MEDIA_HTTP_BATCH_STEADY_SIZE)
        )
        body = image_bytes[page.photos[0].image_id]
        image_bytes.update({photo.image_id: body for photo in photos})
        page = linking.LinkedMediaPage(page.gallery_id, page.parent_id, photos, None)
        session = self._session(page, presentation, image_bytes)
        session.supports_thumbnail_batch.return_value = True
        session.thumbnail_batch_preferred.return_value = True
        session.thumbnail_publication_current.return_value = True

        def partial_batch(
            gallery_id: int, requested: tuple[linking.LinkedPhoto, ...],
            publish: Callable[[linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
                               linking.MediaReadTimings, Callable[[], None]], None],
            cancelled: threading.Event, *, timings: linking.MediaReadTimings,
            stats: linking.ThumbnailBatchStats,
        ) -> None:
            """Publish one complete frame then fail the whole batch operation.

            Args:
                gallery_id (int): Captured gallery identity.
                requested (tuple[linking.LinkedPhoto, ...]): Admitted descriptors.
                publish (Callable[[linking.ThumbnailPublication | linking.LegacyThumbnailPublication, linking.MediaReadTimings, Callable[[], None]], None]):
                    Worker-safe item receiver.
                cancelled (threading.Event): Viewer cancellation owner.
                timings (linking.MediaReadTimings): Physical metrics.
                stats (linking.ThumbnailBatchStats): Bounded group counters.

            Returns:
                None: The deliberately incomplete operation always raises.

            Raises:
                linking.LinkingError: Simulated failed envelope after one item.
            """
            del cancelled, timings, stats
            publish(self._publication(session, gallery_id, requested[0], body),
                    linking.MediaReadTimings(), lambda: None)
            raise linking.LinkingError('Synthetic batch failure.')

        session.media_thumbnails.side_effect = partial_batch
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID, 'Batch',
        )
        viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: viewer._http_thumbnail_failure_latched
            and viewer._worker is None and bool(viewer._thumbnail_pending),
            'The partial batch failure did not settle.',
        )
        self.assertTrue(any(viewer._photo_images))
        self.assertTrue(viewer._thumbnail_pending)
        self.assertEqual(1, session.media_thumbnails.call_count)
        viewer._on_grid_viewport(None)
        self.root.update()
        self.root.update()
        self.assertEqual(1, session.media_thumbnails.call_count)
        session.media_photo.assert_not_called()
        session.media_thumbnails.side_effect = lambda *_args, **_kwargs: None
        viewer._retry_current()
        self.assertFalse(viewer._http_thumbnail_failure_latched)
