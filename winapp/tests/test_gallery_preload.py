# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_preload.py
# Module Type: Regression Test
# Purpose: Verify bounded gallery preload without Tk or external traffic.
# Responsibilities:
#   - Exercise sequential catalogue traversal and local cache verification.
#   - Preserve safe outcomes for no-store bytes, cancellation and throttling.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Focused coverage for the native gallery preload worker."""

import sys
import hashlib
import time
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import app_linking as linking
from uploader.gallery_preload import (
    GalleryPreloadWorker, PRELOAD_MAX_PHOTOS,
)

# Purpose: Exercise one legal continuation and the exact gallery worker target.
# Type: tuple[int, ...]. Units: photo IDs. Scope: one synthetic listing.
# Consumers: GalleryPreloadWorkerTests. Rationale: Eight rows match the
# server's full legacy page size required before a continuation is accepted.
TEST_PRELOAD_PHOTO_IDS = tuple(range(101, 109))
TEST_PRELOAD_GALLERY_ID = 42
TEST_PRELOAD_USER_ID = 57

# Purpose: Keep the synthetic private session live for one synchronous worker.
# Type: int. Units: seconds. Scope: test-only LinkingSession expiry.
# Consumers: test_private_worker_persists_both_renditions.
# Rationale: A five-minute margin avoids wall-clock expiry during the fixture.
TEST_SESSION_LIFETIME_SECONDS = 300


class GalleryPreloadWorkerTests(unittest.TestCase):
    """Exercise preload progress and cache truth with synthetic session results."""

    def _session(self) -> mock.Mock:
        """Create a live media session boundary with no real network access.

        Returns:
            mock.Mock: LinkingSession-shaped owner with permissive local media
                access and empty-cache defaults.
        """
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_read_media.return_value = True
        session.can_read_admin_media.return_value = False
        session.gallery_pinned.return_value = False
        session.prepare_cached_photo.return_value = False
        session.media_photo.return_value = (b'fixture body', 'image/jpeg')
        return session

    def test_paginated_preload_uses_verified_local_renditions(self) -> None:
        """Traverse direct pages and finish only after both local renditions.

        Returns:
            None: A complete photo set is reported local without starting binary
                transport when each body already passes local hash verification.
        """
        session = self._session()
        photos = tuple(
            linking.LinkedPhoto(image_id, 'Photo')
            for image_id in TEST_PRELOAD_PHOTO_IDS
        )
        first = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, photos, photos[-1].image_id,
        )
        final_photo = linking.LinkedPhoto(109, 'Photo')
        last = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (final_photo,), None,
        )
        session.media_page.side_effect = (first, last)
        session.prepare_cached_photo.return_value = True
        worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)

        worker.run()

        progress = worker.snapshot()
        self.assertEqual('complete', progress.phase)
        self.assertEqual('local', progress.cache_state)
        self.assertEqual(9, progress.total)
        self.assertEqual(9, progress.cached)
        self.assertEqual(0, progress.skipped)
        self.assertEqual(2, session.media_page.call_count)
        session.media_photo.assert_not_called()

    def test_final_check_downgrades_evicted_and_no_store_items(self) -> None:
        """Report only final disk state after ordinary quota pressure.

        Returns:
            None: Attempted downloads and initially cached bytes cannot produce
                a complete state when final checks find misses or no-store rows.
        """
        session = self._session()
        cached_photo = linking.LinkedPhoto(101, 'Cached candidate')
        no_store_photo = linking.LinkedPhoto(
            102, 'Non-cacheable', cache_eligible=False,
        )
        page = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None,
            (cached_photo, no_store_photo), None,
        )
        session.media_page.return_value = page
        session.prepare_cached_photo.side_effect = (
            False, False, True, False,
        )
        worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)

        worker.run()

        progress = worker.snapshot()
        self.assertEqual('partial', progress.phase)
        self.assertEqual('partial', progress.cache_state)
        self.assertEqual(2, progress.total)
        self.assertEqual(0, progress.cached)
        self.assertEqual(2, progress.skipped)
        self.assertEqual('partial', progress.cache_state)
        self.assertEqual(2, session.media_photo.call_count)
        self.assertTrue(all(
            call.kwargs['cache_eligible'] is None
            for call in session.media_photo.call_args_list
        ))

    def test_non_cacheable_gallery_reports_the_storage_policy(self) -> None:
        """Explain a complete no-store catalogue without requesting bodies.

        Returns:
            None: The result stays online-only with one skipped photo and a
                specific policy reason, rather than an invented network error.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None,
            (linking.LinkedPhoto(101, 'Transient', cache_eligible=False),), None,
        )
        worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)
        worker.run()
        progress = worker.snapshot()
        self.assertEqual('error', progress.phase)
        self.assertEqual('no_store', progress.reason)
        self.assertEqual('cloud', progress.cache_state)
        self.assertEqual(1, progress.skipped)
        session.media_photo.assert_not_called()

    def test_empty_gallery_finishes_without_inventing_local_files(self) -> None:
        """Finish an empty catalogue while keeping its zero-file coverage clear.

        Returns:
            None: No media is requested and the empty gallery stays cloud-only.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (), None,
        )
        worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)
        worker.run()
        self.assertEqual('complete', worker.snapshot().phase)
        self.assertEqual('cloud', worker.snapshot().cache_state)
        self.assertEqual(0, worker.snapshot().total)
        session.media_photo.assert_not_called()

    def test_throttle_keeps_pin_and_reports_fixed_reason(self) -> None:
        """Leave retention preference intact on an explicit server throttle.

        Returns:
            None: A throttled preload stops without retries and exposes only a
                fixed safe reason code plus previously completed progress.
        """
        session = self._session()
        photo = linking.LinkedPhoto(101, 'Photo')
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (photo,), None,
        )
        session.prepare_cached_photo.return_value = False
        session.set_gallery_pinned.return_value = True
        session.media_photo.side_effect = linking.LinkingMediaThrottled()
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID, pinned=True,
        )

        worker.run()

        progress = worker.snapshot()
        self.assertEqual('error', progress.phase)
        self.assertEqual('throttled', progress.reason)
        self.assertTrue(progress.pinned)
        session.set_gallery_pinned.assert_called_once_with(
            TEST_PRELOAD_GALLERY_ID, True,
        )
        self.assertEqual(1, session.media_photo.call_count)

    def test_bounded_retry_wait_is_cancelable_and_resumes_once(self) -> None:
        """Resume one request after its bounded server delay without sleeping.

        Returns:
            None: A validated delay causes one retry and successful final
                rendition verification without blocking a UI or test thread.
        """
        session = self._session()
        photo = linking.LinkedPhoto(101, 'Photo', cache_eligible=True)
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (photo,), None,
        )
        session.prepare_cached_photo.side_effect = (
            False, False, True, True,
        )
        session.media_photo.side_effect = (
            linking.LinkingMediaThrottled(7),
            (b'thumbnail bytes', 'image/jpeg'),
            (b'detail bytes', 'image/jpeg'),
        )
        worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)
        with mock.patch.object(
            worker, '_wait_before_retry', return_value=True,
        ) as wait:
            worker.run()

        progress = worker.snapshot()
        self.assertEqual('complete', progress.phase)
        self.assertEqual(1, progress.cached)
        wait.assert_called_once_with(7, 1, 0)
        self.assertEqual(3, session.media_photo.call_count)

    def test_bounded_retry_wait_cancellation_stops_before_retry(self) -> None:
        """Stop a throttled transfer when its cancelable wait is interrupted.

        Returns:
            None: Cancellation during the delay prevents a second transport
                call and publishes no false storage success.
        """
        session = self._session()
        photo = linking.LinkedPhoto(101, 'Photo', cache_eligible=True)
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (photo,), None,
        )
        session.prepare_cached_photo.return_value = False
        session.media_photo.side_effect = linking.LinkingMediaThrottled(7)
        worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)
        with mock.patch.object(
            worker, '_wait_before_retry', return_value=False,
        ) as wait:
            worker.run()

        self.assertEqual('cancelled', worker.snapshot().phase)
        self.assertEqual(0, worker.snapshot().cached)
        wait.assert_called_once_with(7, 1, 0)
        self.assertEqual(1, session.media_photo.call_count)

    def test_private_worker_persists_both_renditions(self) -> None:
        """Exercise private preload from worker transfer through final disk proof.

        Returns:
            None: A real session and cache retain both private bodies and the
                explicit account/gallery intent survives a cache owner restart.
        """
        issuer = 'https://gallery.example/Galerie'
        photo_id = 101
        photo = linking.LinkedPhoto(
            photo_id, 'Private', cache_eligible=False,
            preload_eligible=True,
        )
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_SESSION_LIFETIME_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
            admin_preload_supported=True,
        )
        session._bound_user_id = TEST_PRELOAD_USER_ID
        page = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (photo,), None,
        )
        bodies = (
            b'private thumbnail fixture',
            b'private detail fixture',
        )
        responses = tuple(
            (
                body, 'image/jpeg',
                '"' + hashlib.sha256(body).hexdigest() + '"', True,
            )
            for body in bodies
        )
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), 1024 * 1024)
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                session, 'media_page', return_value=page,
            ), mock.patch.object(
                session, '_media_read', side_effect=responses,
            ) as transport:
                worker = GalleryPreloadWorker(
                    session, TEST_PRELOAD_GALLERY_ID,
                )
                worker.run()

            progress = worker.snapshot()
            self.assertEqual('complete', progress.phase)
            self.assertEqual(1, progress.cached)
            self.assertEqual(0, progress.skipped)
            self.assertEqual(2, transport.call_count)
            self.assertTrue(all(
                call.kwargs['admin_preload'] is True
                for call in transport.call_args_list
            ))

            restarted = linking.GalleryMediaCache(
                Path(directory), 1024 * 1024,
            )
            self.assertTrue(restarted.has_private_preload_intent(
                issuer, TEST_PRELOAD_USER_ID, TEST_PRELOAD_GALLERY_ID,
            ))
            for thumbnail, body in zip((True, False), bodies):
                key = restarted.private_key(
                    issuer, TEST_PRELOAD_USER_ID, TEST_PRELOAD_GALLERY_ID,
                    photo_id, thumbnail,
                )
                entry = restarted.read(
                    key,
                    gallery_key=restarted.gallery_key(
                        issuer, TEST_PRELOAD_GALLERY_ID,
                    ),
                )
                self.assertIsNotNone(entry)
                self.assertEqual(body, entry.body)

    def test_cancelled_worker_does_not_list(self) -> None:
        """Observe cooperative cancellation before the first catalogue request.

        Returns:
            None: Stop preserves the current pin and avoids listing or transport.
        """
        session = self._session()
        worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)
        worker.stop()

        worker.run()

        self.assertEqual('cancelled', worker.snapshot().phase)
        session.media_page.assert_not_called()
        session.media_photo.assert_not_called()

    def test_photo_work_bound_is_explicit(self) -> None:
        """Keep the supported descriptor boundary explicit and positive.

        Returns:
            None: The worker admits the documented large-gallery bound.
        """
        self.assertEqual(100_000, PRELOAD_MAX_PHOTOS)

    def test_photo_work_bound_stops_oversized_catalogue(self) -> None:
        """Stop before binary transfer when one run exceeds its work bound.

        Returns:
            None: The fixed safe too-large outcome leaves existing retention
                settings intact and does not start any media request.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None,
            (
                linking.LinkedPhoto(101, 'First'),
                linking.LinkedPhoto(102, 'Second'),
            ),
            None,
        )
        worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)
        with mock.patch(
            'uploader.gallery_preload.PRELOAD_MAX_PHOTOS', 1,
        ):
            worker.run()

        progress = worker.snapshot()
        self.assertEqual('partial', progress.phase)
        self.assertEqual('too_large', progress.reason)
        session.media_photo.assert_not_called()
