# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_preload.py
# Module Type: Regression Test
# Purpose: Verify bounded gallery preload and thumbnail cache receipts.
# Responsibilities:
#   - Exercise sequential catalogue traversal and local cache verification.
#   - Preserve safe outcomes for no-store bytes, cancellation and throttling.
#   - Measure cold/warm work using isolated real sessions and deterministic transport.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Focused coverage for the native gallery preload worker."""

import hashlib
import json
import os
import sys
import threading
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

# Purpose: Exercise both accepted public media-page sizes in one listing.
# Type: tuple[int, int]. Units: descriptors. Scope: synthetic cursor fixture.
# Consumers: test_thumbnail_listing_sizes_preserve_cursor_and_metrics.
# Rationale: 64 is current page size and 8 is the supported legacy page size.
TEST_LISTING_PAGE_SIZES = (linking.NATIVE_MEDIA_PAGE_SIZE,
                           linking.NATIVE_MEDIA_LEGACY_PAGE_SIZE)

# Purpose: Compare the real media path at current and legacy list sizes.
# Type: tuple[int, int]. Units: photos. Scope: isolated cache benchmark runs.
# Consumers: test_real_session_cold_warm_and_legacy_cache_benchmark.
# Rationale: 265 models a large preload and 8 is the legacy response page size.
TEST_REAL_PRELOAD_COUNTS = (265, linking.NATIVE_MEDIA_LEGACY_PAGE_SIZE)

# Purpose: Bound worker joins in blocked-listing concurrency fixtures.
# Type: float. Units: seconds. Scope: one preload test worker join.
# Consumers: listing snapshot and cancellation regressions.
# Rationale: Five seconds leaves ample CI scheduling margin while detecting hangs.
TEST_PRELOAD_JOIN_TIMEOUT_SECONDS = 5.0

# Purpose: Keep the isolated real-session authorization alive for each benchmark.
# Type: int. Units: seconds. Scope: one local LinkingSession fixture.
# Consumers: test_real_session_cold_warm_and_legacy_cache_benchmark.
# Rationale: Five minutes exceeds the synchronous file/cache workload margin.
TEST_REAL_SESSION_LIFETIME_SECONDS = 300

# Purpose: Admit synthetic media through the real cache validator in the benchmark.
# Type: int. Units: bytes. Scope: one isolated real-session cache.
# Consumers: test_real_session_cold_warm_and_legacy_cache_benchmark.
# Rationale: 1 MiB exceeds every deterministic fixture body and header.
TEST_REAL_SESSION_BODY_LIMIT_BYTES = 1024 * 1024


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
        session.cached_media_photo.return_value = None

        def media_photo(
            _gallery_id: int, _image_id: int, thumbnail: bool,
            _cancelled: threading.Event, *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: linking.MediaReadTimings | None = None,
            cache_receipts: list[linking.PhotoCacheReceipt] | None = None,
        ) -> tuple[bytes, str]:
            """Return a fixture media body and optional current cache receipt.

            Args:
                _gallery_id (int): Exact synthetic gallery identity.
                _image_id (int): Exact synthetic photo identity.
                thumbnail (bool): Whether the caller requested a thumbnail.
                _cancelled (threading.Event): Worker cancellation boundary.
                cache_eligible (bool | None): Public thumbnail write policy.
                thumbnail_etag (str | None): Current descriptor validator.
                thumbnail_verified_at (float | None): Descriptor freshness time.
                preload_eligible (bool | None): Admin preload write policy.
                preload_thumbnail_etag (str | None): Admin descriptor validator.
                preload_verified_at (float | None): Admin descriptor freshness time.
                timings (MediaReadTimings | None): Optional read observations.
                cache_receipts (list[PhotoCacheReceipt] | None): Optional receipt sink.

            Returns:
                tuple[bytes, str]: Synthetic body and accepted image MIME.
            """
            if timings is not None:
                timings.source = 'disk'
                timings.cache_state = 'hit'
            if cache_receipts is not None:
                cache_receipts.append(linking.PhotoCacheReceipt(
                    Path('fixture-thumbnail.bin'), os.stat_result((0,) * 10),
                    '"' + '0' * 64 + '"', 'image/jpeg',
                ))
            return b'fixture body', 'image/jpeg'

        session.media_photo.side_effect = media_photo
        return session

    def test_paginated_preload_uses_verified_local_renditions(self) -> None:
        """Traverse direct pages and finish only after both local renditions.

        Returns:
            None: A complete photo set is reported local without starting binary
                transport when both renditions have current authorized cache hits.
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
        session.cached_media_photo.return_value = (b'fresh cached body', 'image/jpeg')
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

    def test_full_preload_revalidates_existing_stale_detail(self) -> None:
        """Refresh an old full rendition even when both cache files exist.

        Returns:
            None: A current thumbnail is kept, the stale full photo reaches the
                normal server path, and final local coverage remains complete.
        """
        session = self._session()
        photo = linking.LinkedPhoto(TEST_PRELOAD_PHOTO_IDS[0], 'Stored photo')
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (photo,), None,
        )
        session.prepare_cached_photo.return_value = True
        # The thumbnail has a fresh catalogue proof; the full body needs its
        # own server revalidation. File existence alone must not skip that read.
        session.cached_media_photo.side_effect = (
            (b'current thumbnail', 'image/jpeg'), None,
        )
        worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)

        worker.run()

        session.media_photo.assert_called_once()
        self.assertFalse(session.media_photo.call_args.args[2])
        self.assertEqual('complete', worker.snapshot().phase)
        self.assertEqual(1, worker.snapshot().cached)

    def test_full_preload_publishes_confirmed_storage_before_final_check(self) -> None:
        """Count stored full photos during transfer rather than only at the end.

        Returns:
            None: Confirmed disk/publication witnesses advance the live count;
                a returned body without storage confirmation never does.
        """
        for confirmed in (True, False):
            with self.subTest(confirmed=confirmed):
                session = self._session()
                photos = tuple(
                    linking.LinkedPhoto(image_id, 'Progress photo')
                    for image_id in TEST_PRELOAD_PHOTO_IDS[:2]
                )
                session.media_page.return_value = linking.LinkedMediaPage(
                    TEST_PRELOAD_GALLERY_ID, None, photos, None,
                )
                session.prepare_cached_photo.return_value = confirmed
                if not confirmed:
                    session.media_photo.side_effect = None
                    session.media_photo.return_value = (b'transient body', 'image/jpeg')
                worker = GalleryPreloadWorker(session, TEST_PRELOAD_GALLERY_ID)
                with mock.patch.object(
                    worker, '_publish', wraps=worker._publish,
                ) as publish:
                    worker.run()
                counts = [
                    call.kwargs['cached'] for call in publish.call_args_list
                    if call.args[0] == 'preloading' and 'cached' in call.kwargs
                ]
                if confirmed:
                    self.assertEqual([0, 1, 2], counts)
                    self.assertEqual(2, worker.snapshot().cached)
                else:
                    self.assertEqual([0, 0, 0], counts)
                    self.assertEqual(0, worker.snapshot().cached)

    def test_thumbnail_only_fetches_and_verifies_only_thumbnails(self) -> None:
        """Keep thumbnail completion distinct from a complete gallery cache.

        Returns:
            None: Every transport and cache check is thumbnail-only and the
                terminal cache state never claims the whole gallery is local.
        """
        session = self._session()
        photos = tuple(
            linking.LinkedPhoto(image_id, 'Photo')
            for image_id in TEST_PRELOAD_PHOTO_IDS[:2]
        )
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, photos, None,
        )
        session.prepare_cached_photo.side_effect = (True, True)
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID, thumbnails_only=True,
        )

        worker.run()

        progress = worker.snapshot()
        self.assertEqual('complete', progress.phase)
        self.assertTrue(progress.thumbnails_only)
        self.assertNotEqual('local', progress.cache_state)
        self.assertEqual(2, progress.cached)
        self.assertEqual(
            [True, True],
            [
                call.kwargs['thumbnail']
                for call in session.prepare_cached_photo.call_args_list
            ],
        )
        self.assertEqual(2, session.media_photo.call_count)
        self.assertTrue(all(
            call.args[2] is True for call in session.media_photo.call_args_list
        ))

    def test_thumbnail_only_yields_to_foreground_and_can_be_cancelled(self) -> None:
        """Stop before media transport when foreground work is waiting.

        Returns:
            None: A foreground wait remains cancellable and admits no background
                binary request after cancellation.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None,
            (linking.LinkedPhoto(101, 'Photo'),), None,
        )
        session.prepare_cached_photo.return_value = False
        worker: GalleryPreloadWorker

        def foreground_waiting() -> bool:
            """Cancel on the first foreground wait observation.

            Returns:
                bool: True while the fixture holds foreground priority.
            """
            worker.stop()
            return True

        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID, thumbnails_only=True,
            yield_to_foreground=foreground_waiting,
        )
        worker.run()

        self.assertEqual('cancelled', worker.snapshot().phase)
        self.assertTrue(worker.snapshot().thumbnails_only)
        session.media_photo.assert_not_called()

    def test_thumbnail_only_resumes_after_foreground_wait_clears(self) -> None:
        """Resume bounded thumbnail work after foreground demand clears.

        Returns:
            None: The worker polls the demand predicate and issues only the
                thumbnail request after foreground work is no longer waiting.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None,
            (linking.LinkedPhoto(101, 'Photo'),), None,
        )
        session.prepare_cached_photo.return_value = True
        observations = iter((True, False))
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID, thumbnails_only=True,
            yield_to_foreground=lambda: next(observations, False),
        )

        worker.run()

        self.assertEqual('complete', worker.snapshot().phase)
        self.assertTrue(worker.snapshot().thumbnails_only)
        session.media_photo.assert_called_once()
        self.assertIs(session.media_photo.call_args.args[2], True)

    def test_thumbnail_only_resume_reuses_verified_thumbnail(self) -> None:
        """Resume from the current thumbnail proof without fetching again.

        Returns:
            None: A valid retained thumbnail satisfies the thumbnail scope
                without inspecting or requiring the full rendition.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None,
            (linking.LinkedPhoto(101, 'Photo'),), None,
        )
        session.prepare_cached_photo.return_value = True
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID, thumbnails_only=True,
        )

        worker.run()

        self.assertEqual('complete', worker.snapshot().phase)
        self.assertEqual('partial', worker.snapshot().cache_state)
        self.assertTrue(worker.snapshot().thumbnails_only)
        receipt = session.media_photo.call_args.kwargs[
            'cache_receipts'
        ][0]
        session.prepare_cached_photo.assert_has_calls([
            mock.call(
                TEST_PRELOAD_GALLERY_ID, mock.ANY, thumbnail=True,
                receipt=receipt,
            ),
        ])
        session.media_photo.assert_called_once()

    def test_thumbnail_listing_sizes_preserve_cursor_and_metrics(self) -> None:
        """Cover current and legacy listing pages at representative scale.

        Returns:
            None: 265 and 564-photo runs retain ordered cursor traversal,
                verified warm hits, and deterministic operation counts.
        """
        benchmark_rows: list[dict[str, int | float]] = []
        for total in (265, 564):
            for page_size in TEST_LISTING_PAGE_SIZES:
                with self.subTest(total=total, page_size=page_size):
                    photos = tuple(
                        linking.LinkedPhoto(1000 + offset, 'Synthetic')
                        for offset in range(total)
                    )
                    pages: list[linking.LinkedMediaPage] = []
                    offset = 0
                    while offset < total:
                        batch = photos[offset:offset + page_size]
                        offset += len(batch)
                        pages.append(linking.LinkedMediaPage(
                            TEST_PRELOAD_GALLERY_ID, None, batch,
                            batch[-1].image_id if offset < total else None,
                        ))
                    session = self._session()
                    session.prepare_cached_photo.return_value = True
                    session.media_page.side_effect = pages
                    worker = GalleryPreloadWorker(
                        session, TEST_PRELOAD_GALLERY_ID,
                        thumbnails_only=True,
                    )

                    worker.run()

                    progress = worker.snapshot()
                    metrics = worker.metrics_snapshot()
                    self.assertEqual('complete', progress.phase)
                    self.assertEqual(total, progress.cached)
                    self.assertEqual(total, metrics['hits'])
                    self.assertEqual(0, metrics['misses'])
                    self.assertEqual(len(pages), session.media_page.call_count)
                    self.assertEqual(total, session.media_photo.call_count)
                    session._media_read.assert_not_called()
                    self.assertTrue(all(
                        call.args[2] is True
                        for call in session.media_photo.call_args_list
                    ))
                    self.assertEqual(
                        [None, *(page.next_after for page in pages[:-1])],
                        [call.args[1] for call in session.media_page.call_args_list],
                    )
                    benchmark_rows.append({
                        'photos': total,
                        'page_size': page_size,
                        'pages': session.media_page.call_count,
                        'media_reads': session.media_photo.call_count,
                        'hits': int(metrics['hits']),
                        'listing_ms': float(metrics['listing_ms']),
                        'transfer_ms': float(metrics['transfer_ms']),
                        'verification_ms': float(metrics['verification_ms']),
                        'wall_ms': float(metrics['wall_ms']),
                    })
        print('PRELOAD_BENCHMARK ' + json.dumps(
            benchmark_rows, sort_keys=True,
        ))

    def test_real_session_cold_warm_and_legacy_cache_benchmark(self) -> None:
        """Compare actual cache reads, receipt checks and old verification.

        Returns:
            None: Cold requests publish real cache files, warm session reads
                avoid transport and duplicate hashes, and legacy page traversal
                remains valid. The benchmark reports no network timing claim.
        """
        reports: list[dict[str, int | float | str]] = []
        for count in TEST_REAL_PRELOAD_COUNTS:
            photos = tuple(
                linking.LinkedPhoto(
                    50_000 + offset, 'Synthetic', cache_eligible=True,
                )
                for offset in range(count)
            )
            pages: list[linking.LinkedMediaPage] = []
            offset = 0
            page_index = 0
            while offset < count:
                page_size = (
                    linking.NATIVE_MEDIA_LEGACY_PAGE_SIZE
                    if count == linking.NATIVE_MEDIA_LEGACY_PAGE_SIZE
                    else TEST_LISTING_PAGE_SIZES[
                        page_index % len(TEST_LISTING_PAGE_SIZES)
                    ]
                )
                batch = photos[offset:offset + page_size]
                offset += len(batch)
                pages.append(linking.LinkedMediaPage(
                    TEST_PRELOAD_GALLERY_ID, None, batch,
                    batch[-1].image_id if offset < count else None,
                ))
                page_index += 1
            session = linking.LinkingSession(
                'https://gallery.example/Galerie',
                linking.TOKEN_PREFIX + 'A' * linking.BASE64URL_256_LENGTH,
                time.monotonic() + TEST_REAL_SESSION_LIFETIME_SECONDS,
                linking.READ_SCOPE,
            )
            self.addCleanup(session.close)
            payload_bytes = [0]

            def media_read(
                _gallery_id: int, kind: str,
                selector: int | str | None,
                _cancelled: threading.Event | None,
                if_none_match: str | None = None, *,
                admin_preload: bool = False,
                timings: linking.MediaReadTimings | None = None,
            ) -> tuple[bytes, str, str, bool]:
                """Return a deterministic valid thumbnail response to the client.

                Args:
                    _gallery_id (int): Exact synthetic gallery identity.
                    kind (str): Requested native media kind.
                    selector (int | str | None): Requested photo identity.
                    _cancelled (threading.Event | None): Current read cancel event.
                    if_none_match (str | None): Conditional validator, if any.
                    admin_preload (bool): Whether private preload was requested.
                    timings (MediaReadTimings | None): Caller-owned read counters.

                Returns:
                    tuple[bytes, str, str, bool]: Body, MIME, strong ETag and
                        cache permission matching the validated transport contract.
                """
                if kind != 'thumbnail' or type(selector) is not int:
                    raise AssertionError('Benchmark requested non-thumbnail media.')
                if if_none_match is not None or admin_preload:
                    raise AssertionError('Cold benchmark unexpectedly revalidated.')
                body = b'synthetic-thumbnail-' + str(selector).encode('ascii')
                etag = '"' + hashlib.sha256(body).hexdigest() + '"'
                payload_bytes[0] += len(body)
                if timings is not None:
                    timings.network_reads += 1
                    timings.http_requests += 1
                    timings.encoded_bytes += len(body)
                return body, 'image/jpeg', etag, True

            with TemporaryDirectory() as temporary:
                cache = linking.GalleryMediaCache(
                    Path(temporary), TEST_REAL_SESSION_BODY_LIMIT_BYTES,
                    thumbnails=True,
                )
                with mock.patch.object(
                    linking.GalleryMediaCache, 'for_current_user',
                    return_value=cache,
                ), mock.patch.object(
                    session, 'media_page', side_effect=pages,
                ) as listing, mock.patch.object(
                    session, '_media_read', side_effect=media_read,
                ) as transport:
                    cold_worker = GalleryPreloadWorker(
                        session, TEST_PRELOAD_GALLERY_ID,
                        thumbnails_only=True,
                    )
                    cold_worker.run()
                    cold_progress = cold_worker.snapshot()
                    cold_metrics = cold_worker.metrics_snapshot()
                    cold_counts = cache.operation_counts()
                    cold_requests = transport.call_count
                    cold_pages = listing.call_count
                    self.assertEqual('complete', cold_progress.phase)
                    self.assertEqual(count, cold_progress.cached)
                    self.assertEqual(count, cold_progress.stored)
                    self.assertEqual(count, cold_requests)
                    self.assertEqual(len(pages), cold_pages)
                    self.assertEqual(
                        payload_bytes[0],
                        cold_metrics['thumbnail_encoded_bytes'],
                    )
                    self.assertEqual(count, cold_counts['writes'])
                    self.assertEqual(count, cold_counts['flushes'])
                    self.assertEqual(count, cold_counts['hashes'])

                    transport.reset_mock()
                    listing.reset_mock()
                    listing.side_effect = pages
                    warm_before = cache.operation_counts()
                    warm_worker = GalleryPreloadWorker(
                        session, TEST_PRELOAD_GALLERY_ID,
                        thumbnails_only=True,
                    )
                    warm_worker.run()
                    warm_progress = warm_worker.snapshot()
                    warm_metrics = warm_worker.metrics_snapshot()
                    warm_counts = cache.operation_counts()
                    warm_delta = {
                        name: warm_counts[name] - warm_before[name]
                        for name in warm_before
                    }
                    self.assertEqual('complete', warm_progress.phase)
                    self.assertEqual(count, warm_progress.current_hits)
                    self.assertEqual(count, warm_progress.cached)
                    self.assertEqual(0, warm_progress.stored)
                    transport.assert_not_called()
                    self.assertEqual(count, warm_delta['hashes'])
                    self.assertEqual(count, warm_delta['reads'])
                    self.assertEqual(0, warm_delta['inventory_scans'])
                    self.assertEqual(len(pages), listing.call_count)

                    legacy_before = cache.operation_counts()
                    for photo in photos:
                        disk_entry = session.cached_media_photo(
                            TEST_PRELOAD_GALLERY_ID, photo, thumbnail=True,
                        )
                        self.assertIsNotNone(disk_entry)
                        self.assertTrue(session.prepare_cached_photo(
                            TEST_PRELOAD_GALLERY_ID, photo, thumbnail=True,
                        ))
                    legacy_counts = cache.operation_counts()
                    legacy_delta = {
                        name: legacy_counts[name] - legacy_before[name]
                        for name in legacy_before
                    }
                    self.assertEqual(2 * count, legacy_delta['hashes'])
                    reports.append({
                        'photos': count,
                        'pages': len(pages),
                        'cold_metadata_calls': cold_pages,
                        'warm_metadata_calls': listing.call_count,
                        'cold_media_requests': cold_requests,
                        'warm_media_requests': 0,
                        'cold_cache_hashes': cold_counts['hashes'],
                        'cold_cache_scans': cold_counts['inventory_scans'],
                        'cold_cache_stats': cold_counts['stat_calls'],
                        'cold_cache_writes': cold_counts['writes'],
                        'cold_cache_flushes': cold_counts['flushes'],
                        'warm_cache_hashes': warm_delta['hashes'],
                        'warm_cache_reads': warm_delta['reads'],
                        'warm_cache_scans': warm_delta['inventory_scans'],
                        'legacy_cache_hashes': legacy_delta['hashes'],
                        'legacy_cache_reads': legacy_delta['reads'],
                        'cold_encoded_bytes': int(cold_metrics[
                            'thumbnail_encoded_bytes']),
                        'warm_hits': int(warm_metrics['hits']),
                        'cold_hits': int(cold_metrics['hits']),
                        'cold_misses': int(cold_metrics['misses']),
                        'warm_misses': int(warm_metrics['misses']),
                        'cold_wall_ms': float(cold_metrics['wall_ms']),
                        'warm_wall_ms': float(warm_metrics['wall_ms']),
                        'cold_foreground_wait_ms': float(
                            cold_metrics['foreground_wait_ms'],
                        ),
                        'warm_foreground_wait_ms': float(
                            warm_metrics['foreground_wait_ms'],
                        ),
                        'cold_retry_wait_ms': float(cold_metrics['retry_wait_ms']),
                        'warm_retry_wait_ms': float(warm_metrics['retry_wait_ms']),
                        'cold_listing_ms': float(cold_metrics['listing_ms']),
                        'cold_transfer_ms': float(cold_metrics['transfer_ms']),
                        'cold_verification_ms': float(
                            cold_metrics['verification_ms'],
                        ),
                        'warm_listing_ms': float(warm_metrics['listing_ms']),
                        'warm_transfer_ms': float(warm_metrics['transfer_ms']),
                        'warm_verification_ms': float(
                            warm_metrics['verification_ms'],
                        ),
                        'transport_timing': 'unavailable_stubbed_no_network',
                    })
        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
        evidence.mkdir(parents=True, exist_ok=True)
        (evidence / 'thumbnail-preload-measurement.json').write_text(
            json.dumps({
                'data_source': 'real session/cache with stubbed authorized media',
                'active_foreground': False,
                'active_watcher': False,
                'measurements': reports,
            }, indent=2) + '\n', encoding='utf-8',
        )
        print('REAL_CACHE_BENCHMARK ' + json.dumps(reports, sort_keys=True))

    def test_thumbnail_partial_warm_and_failed_store_are_not_overstated(self) -> None:
        """Separate warm hits from confirmed writes and unavailable rows.

        Returns:
            None: Only a current disk hit or cache-confirmed stored network body
                with a receipt reaches final coverage; no-store rows stay skipped.
        """
        session = self._session()
        photos = (
            linking.LinkedPhoto(1, 'Warm'),
            linking.LinkedPhoto(2, 'Cold'),
            linking.LinkedPhoto(3, 'No store', cache_eligible=False),
        )
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, photos, None,
        )
        session.prepare_cached_photo.return_value = True
        disk_read = session.media_photo.side_effect

        def mixed_read(
            gallery_id: int, image_id: int, thumbnail: bool,
            cancelled: threading.Event, *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: linking.MediaReadTimings | None = None,
            cache_receipts: list[linking.PhotoCacheReceipt] | None = None,
        ) -> tuple[bytes, str]:
            """Return one warm hit and one successfully stored network body.

            Args:
                gallery_id (int): Requested gallery identity.
                image_id (int): Requested photo identity.
                thumbnail (bool): Requested rendition.
                cancelled (threading.Event): Worker cancellation boundary.
                cache_eligible (bool | None): Public thumbnail write policy.
                thumbnail_etag (str | None): Current descriptor validator.
                thumbnail_verified_at (float | None): Descriptor freshness time.
                preload_eligible (bool | None): Admin preload write policy.
                preload_thumbnail_etag (str | None): Admin descriptor validator.
                preload_verified_at (float | None): Admin descriptor freshness time.
                timings (MediaReadTimings | None): Optional read observations.
                cache_receipts (list[PhotoCacheReceipt] | None): Optional receipt sink.

            Returns:
                tuple[bytes, str]: Synthetic body and accepted image MIME.
            """
            if image_id == 1:
                return disk_read(
                    gallery_id, image_id, thumbnail, cancelled,
                    cache_eligible=cache_eligible,
                    thumbnail_etag=thumbnail_etag,
                    thumbnail_verified_at=thumbnail_verified_at,
                    preload_eligible=preload_eligible,
                    preload_thumbnail_etag=preload_thumbnail_etag,
                    preload_verified_at=preload_verified_at,
                    timings=timings, cache_receipts=cache_receipts,
                )
            if timings is not None:
                timings.source = 'network'
                timings.cache_stored = True
                timings.stored_bytes = 12
            if cache_receipts is not None:
                cache_receipts.append(linking.PhotoCacheReceipt(
                    Path('written-thumbnail.bin'), os.stat_result((0,) * 10),
                    '"' + '0' * 64 + '"', 'image/jpeg',
                ))
            return b'network body', 'image/jpeg'

        session.media_photo.side_effect = mixed_read
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID, thumbnails_only=True,
        )

        worker.run()

        progress = worker.snapshot()
        self.assertEqual('partial', progress.phase)
        self.assertEqual(2, progress.cached)
        self.assertEqual(1, progress.skipped)
        self.assertEqual(1, progress.current_hits)
        self.assertEqual(1, progress.stored)
        self.assertEqual(12, progress.stored_bytes)
        self.assertEqual(12, progress.prepared_bytes)

        failed = self._session()
        failed.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (photos[1],), None,
        )
        failed.prepare_cached_photo.return_value = True

        def failed_write(
            _gallery_id: int, _image_id: int, _thumbnail: bool,
            _cancelled: threading.Event, *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: linking.MediaReadTimings | None = None,
            cache_receipts: list[linking.PhotoCacheReceipt] | None = None,
        ) -> tuple[bytes, str]:
            """Model a received body whose ordinary cache write failed.

            Args:
                _gallery_id (int): Requested gallery identity.
                _image_id (int): Requested photo identity.
                _thumbnail (bool): Requested rendition.
                _cancelled (threading.Event): Worker cancellation boundary.
                cache_eligible (bool | None): Public thumbnail write policy.
                thumbnail_etag (str | None): Current descriptor validator.
                thumbnail_verified_at (float | None): Descriptor freshness time.
                preload_eligible (bool | None): Admin preload write policy.
                preload_thumbnail_etag (str | None): Admin descriptor validator.
                preload_verified_at (float | None): Admin descriptor freshness time.
                timings (MediaReadTimings | None): Optional read observations.
                cache_receipts (list[PhotoCacheReceipt] | None): Optional receipt sink.

            Returns:
                tuple[bytes, str]: Synthetic uncached body and image MIME.
            """
            if timings is not None:
                timings.source = 'network'
                timings.encoded_bytes = 12
                timings.cache_stored = False
            return b'network body', 'image/jpeg'

        failed.media_photo.side_effect = failed_write
        failed_worker = GalleryPreloadWorker(
            failed, TEST_PRELOAD_GALLERY_ID, thumbnails_only=True,
        )
        failed_worker.run()
        self.assertNotEqual('complete', failed_worker.snapshot().phase)
        self.assertEqual(0, failed_worker.snapshot().cached)
        self.assertEqual(1, failed_worker.snapshot().skipped)
        self.assertEqual(0, failed_worker.snapshot().stored)
        failed.prepare_cached_photo.assert_not_called()

    def test_empty_thumbnail_listing_makes_no_media_reads(self) -> None:
        """Finish an empty warm-up without inventing cache evidence.

        Returns:
            None: One terminal empty listing produces zero transport, hit,
                write, and verified-photo counts.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (), None,
        )
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID, thumbnails_only=True,
        )

        worker.run()

        self.assertEqual('complete', worker.snapshot().phase)
        self.assertEqual(0, worker.snapshot().total)
        self.assertEqual(0, worker.snapshot().cached)
        self.assertEqual(0, worker.metrics_snapshot()['hits'])
        session.media_photo.assert_not_called()
        session.prepare_cached_photo.assert_not_called()

    def test_thumbnail_retry_rechecks_foreground_and_cancels_before_retry(self) -> None:
        """Recheck foreground priority after a throttled thumbnail attempt.

        Returns:
            None: A newly waiting foreground request cancels the retry before
                another thumbnail transport is admitted.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None,
            (linking.LinkedPhoto(101, 'Photo'),), None,
        )
        session.media_photo.side_effect = linking.LinkingMediaThrottled(7)
        observations = iter((False, False, False, True))
        worker: GalleryPreloadWorker

        def foreground_waiting() -> bool:
            """Cancel as foreground priority reappears before the retry.

            Returns:
                bool: True for the newly admitted foreground operation.
            """
            waiting = next(observations, False)
            if waiting:
                worker.stop()
            return waiting

        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID, thumbnails_only=True,
            yield_to_foreground=foreground_waiting,
        )
        with mock.patch.object(worker, '_wait_before_retry', return_value=True):
            worker.run()

        self.assertEqual('cancelled', worker.snapshot().phase)
        self.assertEqual(1, session.media_photo.call_count)
        session.prepare_cached_photo.assert_not_called()

    def test_thumbnail_snapshot_is_available_during_blocked_listing(self) -> None:
        """Publish an immediate cancellable snapshot while listing is blocked.

        Returns:
            None: The UI-facing snapshot remains nonblocking and truthful before
                listing finishes, and cancellation wins after its release.
        """
        session = self._session()
        entered = threading.Event()
        release = threading.Event()

        def blocked_page(
            _gallery_id: int, _after_id: int | None,
            _cancelled: threading.Event, *,
            timings: linking.MediaReadTimings | None = None,
        ) -> linking.LinkedMediaPage:
            """Hold one listing request until the test releases its barrier.

            Args:
                _gallery_id (int): Requested gallery identity.
                _after_id (int | None): Current exclusive pagination cursor.
                _cancelled (threading.Event): Worker cancellation boundary.
                timings (MediaReadTimings | None): Optional listing observations.

            Returns:
                linking.LinkedMediaPage: One valid terminal photo page.
            """
            entered.set()
            release.wait(TEST_PRELOAD_JOIN_TIMEOUT_SECONDS)
            return linking.LinkedMediaPage(
                TEST_PRELOAD_GALLERY_ID, None,
                (linking.LinkedPhoto(101, 'Photo'),), None,
            )

        session.media_page.side_effect = blocked_page
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID, thumbnails_only=True,
        )
        worker.start()
        self.assertTrue(entered.wait(TEST_PRELOAD_JOIN_TIMEOUT_SECONDS))
        snapshot = worker.snapshot()
        self.assertEqual('listing', snapshot.phase)
        self.assertIsNone(snapshot.total)
        self.assertEqual(0, snapshot.completed)
        worker.stop()
        release.set()
        worker.join(TEST_PRELOAD_JOIN_TIMEOUT_SECONDS)
        self.assertFalse(worker.is_alive())
        self.assertEqual('cancelled', worker.snapshot().phase)
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
            True, False,
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

    def test_response_failure_reaches_safe_log_and_copy_snapshot(self) -> None:
        """Retain worker response facts without preserving arbitrary text.

        Returns:
            None: The real worker logs and copies validated response metadata
                while excluding raw error messages and private URL material.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None,
            (linking.LinkedPhoto(101, 'Private album title'),), None,
        )
        error = linking.LinkingResponseError(
            'media', 'app_linking_media', 200, 'other', 'response_policy',
        )
        error.args = (
            'token=secret https://private.example/photo C:\\private\\item',
        )
        session.media_photo.side_effect = error

        callback_values: list[str] = []
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID,
            diagnostic_callback=callback_values.append,
        )

        with self.assertLogs(
            'uploader.gallery_preload', level='WARNING',
        ) as captured:
            worker.run()

        self.assertEqual('error', worker.snapshot().phase)
        self.assertEqual('unavailable', worker.snapshot().reason)
        self.assertEqual(2, len(captured.records))
        self.assertEqual(2, len(callback_values))
        for summary in (*captured.output, *callback_values):
            self.assertIn('phase=preload-fetch', summary)
            self.assertIn('kind=preload', summary)
            self.assertIn('response_kind=other', summary)
            self.assertIn('reason=response_policy', summary)
            self.assertIn('site=gallery_preload.py:_run_owned:', summary)
            self.assertNotIn('secret', summary)
            self.assertNotIn('private.example', summary)
            self.assertNotIn('Private album title', summary)
            self.assertNotIn('C:\\private', summary)
        self.assertIn('preload_phase=error', callback_values[1])
        self.assertIn('completed=1; cached=0; skipped=1', callback_values[1])
        self.assertIn('reason=unavailable', callback_values[1])
        self.assertEqual(
            1,
            sum('preload_phase=error' in line for line in captured.output),
        )

    def test_listing_runtime_failure_is_logged_without_message_and_callback_survives(self) -> None:
        """Retain generic worker failures as safe staged diagnostics.

        Returns:
            None: Listing failures preserve the fixed stage and completion
                counts while excluding exception text and callback failures.
        """
        session = self._session()
        session.media_page.side_effect = RuntimeError(
            'secret token=private https://private.example/gallery',
        )
        callback_values: list[str] = []

        def broken_callback(summary: str) -> None:
            """Capture a safe summary before simulating a broken owner.

            Args:
                summary (str): Safe worker diagnostic supplied for retention.

            Returns:
                None: Stores the first summary and raises to model callback loss.
            """
            callback_values.append(summary)
            raise RuntimeError('callback token=private')

        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID,
            diagnostic_callback=broken_callback,
        )
        with self.assertLogs(
            'uploader.gallery_preload', level='WARNING',
        ) as captured:
            worker.run()

        self.assertEqual('error', worker.snapshot().phase)
        self.assertEqual('storage', worker.snapshot().reason)
        self.assertIn('phase=preload-listing', callback_values[0])
        self.assertIn('site=gallery_preload.py:_list_photos:', callback_values[0])
        self.assertNotIn('private.example', '\n'.join(captured.output))
        self.assertNotIn('token=private', '\n'.join(captured.output))
        self.assertTrue(any('total=unknown' in line for line in captured.output))

    def test_cache_and_verify_errors_report_owned_stages(self) -> None:
        """Distinguish local cache admission from final cache verification.

        Returns:
            None: Each storage exception identifies the operation stage without
                changing the worker's fixed storage terminal reason.
        """
        photo = linking.LinkedPhoto(101, 'Photo')
        page = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (photo,), None,
        )
        cases = (
            ('cache', RuntimeError('cache token=private')),
            ('verify', OSError('verify private path C:\\private')),
        )
        for stage, failure in cases:
            with self.subTest(stage=stage):
                session = self._session()
                session.media_page.return_value = page
                if stage == 'cache':
                    session.cached_media_photo.side_effect = failure
                else:
                    session.prepare_cached_photo.side_effect = failure
                observed: list[str] = []
                worker = GalleryPreloadWorker(
                    session, TEST_PRELOAD_GALLERY_ID,
                    diagnostic_callback=observed.append,
                )
                worker.run()

                self.assertEqual('storage', worker.snapshot().reason)
                self.assertIn(f'phase=preload-{stage}', observed[0])
                self.assertNotIn('token=private', observed[0])
                self.assertNotIn('C:\\private', observed[0])

    def test_storage_failure_reports_storage_stage(self) -> None:
        """Classify a retention-store error before catalogue work begins.

        Returns:
            None: Storage exceptions retain their safe class and fixed phase,
                while arbitrary exception messages stay out of the callback.
        """
        session = self._session()
        session.gallery_pinned.side_effect = OSError(
            'C:\\private\\cache token=secret',
        )
        observed: list[str] = []
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID,
            diagnostic_callback=observed.append,
        )

        worker.run()

        self.assertEqual('storage', worker.snapshot().reason)
        self.assertIn('phase=preload-storage', observed[0])
        self.assertNotIn('C:\\private', observed[0])
        self.assertNotIn('token=secret', observed[0])

    def test_refused_preload_intent_reaches_host_without_exception(self) -> None:
        """Expose a fixed storage refusal even when no exception was raised.

        Returns:
            None: The terminal failure reaches the copied log callback with
                its phase, zero counts and fixed storage reason.
        """
        session = self._session()
        session.can_read_admin_media.return_value = True
        session.supports_admin_preload.return_value = True
        session.set_gallery_preload_intent.return_value = False
        observed: list[str] = []
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID,
            diagnostic_callback=observed.append,
        )
        with self.assertLogs(
            'uploader.gallery_preload', level='WARNING',
        ) as captured:
            worker.run()

        self.assertEqual('error', worker.snapshot().phase)
        self.assertEqual('storage', worker.snapshot().reason)
        self.assertEqual(1, len(observed))
        self.assertIn('preload_phase=error', observed[0])
        self.assertIn('completed=0; cached=0; skipped=0', observed[0])
        self.assertIn('reason=storage', observed[0])
        self.assertTrue(any('preload_phase=error' in line for line in captured.output))

    def test_distinct_fatal_failure_after_throttle_is_retained(self) -> None:
        """Keep a later refusal after one transient server throttle.

        Returns:
            None: Identical consecutive summaries can collapse, but a distinct
                response-policy refusal reaches logs and the copied snapshot.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None,
            (linking.LinkedPhoto(101, 'Photo'),), None,
        )
        session.prepare_cached_photo.return_value = False
        session.media_photo.side_effect = (
            linking.LinkingMediaThrottled(7),
            linking.LinkingResponseError(
                'media', 'app_linking_media', 200, 'other',
                'response_policy',
            ),
        )
        observed: list[str] = []
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID,
            diagnostic_callback=observed.append,
        )
        with mock.patch.object(worker, '_wait_before_retry', return_value=True):
            with self.assertLogs(
                'uploader.gallery_preload', level='WARNING',
            ) as captured:
                worker.run()

        self.assertEqual('error', worker.snapshot().phase)
        self.assertEqual(3, len(observed))
        self.assertIn('reason=throttled', observed[0])
        self.assertIn('reason=response_policy', observed[1])
        self.assertIn('reason=response_policy', observed[2])
        self.assertIn('preload_phase=error', observed[2])
        self.assertEqual(3, len(captured.records))

    def test_successful_preload_logs_completion_without_failure_snapshot(self) -> None:
        """Log successful worker completion without replacing failure history.

        Returns:
            None: The terminal count summary is logged, while success produces
                no copied failure diagnostic.
        """
        session = self._session()
        session.media_page.return_value = linking.LinkedMediaPage(
            TEST_PRELOAD_GALLERY_ID, None, (), None,
        )
        callback_values: list[str] = []
        worker = GalleryPreloadWorker(
            session, TEST_PRELOAD_GALLERY_ID,
            diagnostic_callback=callback_values.append,
        )
        with self.assertLogs(
            'uploader.gallery_preload', level='INFO',
        ) as captured:
            worker.run()

        self.assertEqual('complete', worker.snapshot().phase)
        self.assertEqual([], callback_values)
        self.assertTrue(any('preload_phase=complete' in line for line in captured.output))
        self.assertTrue(any('total=0' in line for line in captured.output))

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
            True, True,
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
