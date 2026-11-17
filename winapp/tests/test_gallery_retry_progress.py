# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_retry_progress.py
# Module Type: Native Gallery Retry Integration Test
# Purpose: Verify real thumbnail retry waits reach the mounted progress indicator.
# Responsibilities:
#   - Exercise the real session batch parser and the native Tk viewer together.
#   - Cover transient HTTP 502, HTTP 503 and response-header timeout recovery.
#   - Preserve exact one-retry transport behavior and the recovered photo order.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Prove real session cooldown feedback appears in the gallery viewer."""

import hashlib
import io
import sys
import threading
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

import test_native_gallery_viewer as native_fixture
import test_thumbnail_batch_session as batch_fixture
import test_gallery_load_progress as progress_fixture

from uploader import app_linking as linking
from uploader.flight_report_translations import tr


# Purpose: Use the same gallery identity as the existing real-session page parser.
# Type: int. Units: gallery identifiers. Scope: one real-session retry fixture.
# Consumers: GalleryRetryProgressTests._exercise_retry.
# Rationale: batch_fixture.register_page parses a gallery-42 payload by contract.
TEST_RETRY_GALLERY_ID = 42

# Purpose: Cover the closed transient response matrix owned by the batch reader.
# Type: tuple[tuple[str, int | None], ...]. Units: failure kind and HTTP status.
# Scope: real Tk and session retry integration. Consumers: retry test cases.
# Rationale: 502, 503 and a response-free header timeout are the existing
# automatic retry classes; None denotes the response-free timeout case.
TEST_RETRY_CASES = (
    ('http-502', 502),
    ('http-503', 503),
    ('header-timeout', None),
)

# Purpose: Keep the raster payload visibly distinct while remaining tiny.
# Type: tuple[int, int, int]. Units: 8-bit RGB channel values.
# Scope: one valid PGB1 thumbnail body. Consumers: _exercise_retry.
# Rationale: A real JPEG decodes in Pillow and carries a stable content digest.
TEST_RETRY_IMAGE_RGB = (44, 116, 182)


@unittest.skipUnless(
    sys.platform == 'win32' and native_fixture.tk is not None
    and native_fixture.Image is not None and native_fixture.ImageTk is not None,
    'Real session retry feedback requires Windows, Tk and Pillow.',
)
class GalleryRetryProgressTests(unittest.TestCase):
    """Exercise session-owned retries through the actual mounted Tk viewer."""

    setUp = native_fixture.NativeGalleryViewerTests.setUp
    _destroy_root = native_fixture.NativeGalleryViewerTests._destroy_root
    _wait_until = native_fixture.NativeGalleryViewerTests._wait_until
    _png = native_fixture.NativeGalleryViewerTests._png
    _open_viewer = native_fixture.NativeGalleryViewerTests._open_viewer
    _assert_no_browsing_toplevels = (
        native_fixture.NativeGalleryViewerTests._assert_no_browsing_toplevels
    )
    _capture_viewer = (
        progress_fixture.NativeGalleryLoadProgressTests._capture_viewer
    )

    def _exercise_retry(self, failure_name: str, status: int | None) -> None:
        """Show one real internal cooldown and recover the visible album.

        Args:
            failure_name (str): Stable scenario name used in subtest reports.
            status (int | None): Transient response status, or None for a
                response-header timeout before any status was received.
        Returns:
            None: One automatic retry succeeds and renders the current photos.
        """
        cache_directory = TemporaryDirectory()
        self.addCleanup(cache_directory.cleanup)
        cache = linking.GalleryMediaCache(
            Path(cache_directory.name), linking.NATIVE_MEDIA_MAX_BYTES,
            thumbnails=True,
        )
        cache_patch = mock.patch.object(
            linking.GalleryMediaCache, 'for_current_user', return_value=cache,
        )
        cache_patch.start()
        self.addCleanup(cache_patch.stop)
        session = batch_fixture.ThumbnailBatchSessionTests.make_session(self)

        image_stream = io.BytesIO()
        native_fixture.Image.new(
            'RGB', native_fixture.TEST_SYNTHETIC_IMAGE_SIZE,
            TEST_RETRY_IMAGE_RGB,
        ).save(image_stream, format='JPEG')
        image_body = image_stream.getvalue()
        image_etag = '"' + hashlib.sha256(image_body).hexdigest() + '"'
        image_ids = (
            native_fixture.TEST_FIRST_IMAGE_ID,
            native_fixture.TEST_SECOND_IMAGE_ID,
        )
        photos = batch_fixture.ThumbnailBatchSessionTests.register_page(
            self, session, image_ids, etag=image_etag,
        )
        page = linking.LinkedMediaPage(
            TEST_RETRY_GALLERY_ID, None, photos, None,
        )
        wire = batch_fixture.encode_batch(image_ids, body=image_body)
        failed_response = (
            batch_fixture.ThumbnailBatchSessionTests.response(self, status)
            if status is not None else None
        )
        healthy_response = batch_fixture.ThumbnailBatchSessionTests.response(
            self, 200, wire,
        )
        release_failure = threading.Event()
        first_response_entered = threading.Event()
        first_connection = batch_fixture._Connection(
            failed_response or batch_fixture.ThumbnailBatchSessionTests.response(
                self, 200, wire,
            ),
        )

        def held_failure_response() -> batch_fixture._Response:
            """Hold the first physical response until the mounted UI is ready.

            Returns:
                batch_fixture._Response: Configured transient status response.
            Raises:
                TimeoutError: The response-free case simulates a header timeout;
                    a missing test release fails boundedly in other cases.
            """
            first_response_entered.set()
            if not release_failure.wait(native_fixture.TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('The test did not release the first response.')
            if status is None:
                raise TimeoutError('Synthetic response-header timeout.')
            assert failed_response is not None
            return failed_response

        first_connection.getresponse = held_failure_response
        retry_connection = batch_fixture._Connection(healthy_response)
        connection_factory = mock.patch.object(
            linking, '_linking_connection',
            side_effect=(first_connection, retry_connection),
        )
        media_page_patch = mock.patch.object(
            session, 'media_page', return_value=page,
        )
        presentation_patch = mock.patch.object(
            session, 'can_read_presentation', return_value=False,
        )
        gallery = linking.LinkedGallery(
            TEST_RETRY_GALLERY_ID, None, f'Retry progress {failure_name}',
        )
        viewer: native_fixture.native_gallery_viewer._NativeGalleryViewer | None = None
        host: native_fixture.ttk.Frame | None = None
        worker: threading.Thread | None = None
        with connection_factory as connection_spy, media_page_patch, presentation_patch:
            try:
                viewer = self._open_viewer(session, gallery)
                host = viewer.frame.master
                self._wait_until(
                    first_response_entered.is_set,
                    f'{failure_name} did not reach its first batch response.',
                )
                worker = viewer._worker
                self.assertIsNotNone(worker)
                release_failure.set()
                self._wait_until(
                    lambda: viewer._load_progress.phase == 'waiting',
                    f'{failure_name} retry wait did not reach the Tk indicator.',
                )
                self.assertEqual(
                    tr('en', 'linking_media_wait_retry').format(
                        seconds=int(batch_fixture.TEST_BATCH_RETRY_SECONDS),
                    ),
                    viewer._load_progress.label.cget('text'),
                )
                self.assertEqual(
                    'indeterminate', str(viewer._load_progress.bar.cget('mode')),
                )
                self._capture_viewer(viewer, f'{failure_name}-retry-wait')

                self._wait_until(
                    lambda: viewer._load_progress.phase == 'hidden'
                    and viewer._photos
                    and tuple(photo.image_id for photo in viewer._photos) == image_ids
                    and all(
                        viewer._photo_images[index] is not None
                        for index in viewer._visible_thumbnail_indices
                    ),
                    f'{failure_name} did not recover the first visible viewport.',
                )
                self.assertEqual(2, connection_spy.call_count)
                self.assertEqual(1, first_connection.request_count)
                self.assertEqual(1, retry_connection.request_count)
                self.assertEqual(image_ids, tuple(
                    photo.image_id for photo in viewer._photos
                ))
                self.assertFalse(viewer._media_throttled)
                self.assertFalse(viewer._http_thumbnail_failure_latched)
                self._capture_viewer(viewer, f'{failure_name}-recovered')
            finally:
                release_failure.set()
                if viewer is not None:
                    worker = viewer._worker if worker is None else worker
                    viewer.close()
                    if worker is not None:
                        worker.join(native_fixture.TEST_WORKER_JOIN_SECONDS)
                        self.assertFalse(
                            worker.is_alive(),
                            'The cancelled batch outlived its isolated HTTP fixture.',
                        )
                    if host is not None:
                        host.destroy()
                session.close()

    def test_transient_http_and_header_failures_report_wait_then_recover(self) -> None:
        """Prove 502, 503 and header timeout retries are visible to Tk.

        Returns:
            None: Each real session performs exactly one automatic retry after
                a visible internal cooldown and renders both approved photos.
        """
        for failure_name, status in TEST_RETRY_CASES:
            with self.subTest(failure=failure_name):
                self._exercise_retry(failure_name, status)


if __name__ == '__main__':
    unittest.main()
