# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_read_linking.py
# Module Type: Regression Test
# Purpose: Verify gallery-read authorization and indexed thumbnail cache safety.
# Responsibilities:
#   - Preserve existing scope authority and offline credential indices.
#   - Reject malformed, unauthorized or mismatched gallery detail responses.
#   - Exercise persistent thumbnail accounting, receipts and concurrent writes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise gallery-detail authorization without network or native APIs."""

import base64
import hashlib
import json
import multiprocessing
import os
import queue
import tempfile
import time
import unittest
from pathlib import Path
from multiprocessing.queues import Queue as MultiprocessingQueue
from unittest.mock import patch

from uploader import app_linking as linking
from uploader import gallery_media_cache as media_cache
from uploader import offline_linking as offline


# Purpose: Bound temporary media accepted by each isolated cache regression.
# Type: int. Units: bytes. Scope: one synthetic photo-cache body.
# Consumers: ThumbnailCacheIndexTests._write_thumbnail.
# Rationale: 1 MiB comfortably admits short synthetic bodies and cache headers.
TEST_CACHE_BODY_LIMIT_BYTES = 1024 * 1024

# Purpose: Verify indexed admission with a realistic small preload batch.
# Type: int. Units: entries. Scope: one cache-index operation-count fixture.
# Consumers: test_indexed_batch_has_constant_directory_work.
# Rationale: 265 media match the observed ordinary preload target under test.
TEST_CACHE_PRELOAD_WRITE_BATCH = 265

# Purpose: Compare first index discovery at small and multi-thousand pool sizes.
# Type: tuple[int, int]. Units: synthetic files. Scope: fixture-only pool sizes.
# Consumers: test_indexed_batch_has_constant_directory_work.
# Rationale: 100 and 3072 expose whether each write rescans pool growth.
TEST_CACHE_SEED_COUNTS = (100, 3072)

# Purpose: Bound independent processes contending for the shared cache lock.
# Type: int. Units: processes. Scope: one concurrent writer fixture.
# Consumers: ThumbnailCacheIndexTests.test_concurrent_writers_share_quota.
# Rationale: Three writers expose process locking without oversubscribing CI.
TEST_CACHE_CONCURRENT_WRITERS = 3

# Purpose: Bound successful unique writes for each concurrent writer.
# Type: int. Units: writes. Scope: one writer process.
# Consumers: ThumbnailCacheIndexTests.test_concurrent_writers_share_quota.
# Rationale: Four writes each exercise repeated lock and index publication.
TEST_CACHE_CONCURRENT_WRITES_PER_PROCESS = 4

# Purpose: Bound retries of an expected nonblocking cross-process lock collision.
# Type: int. Units: attempts. Scope: one concurrent cache write.
# Consumers: _thumbnail_cache_concurrent_writer.
# Rationale: 100 short retries allow ordinary scheduling without unbounded waits.
TEST_CACHE_LOCK_RETRY_ATTEMPTS = 100

# Purpose: Yield briefly after a nonblocking media-lock collision.
# Type: float. Units: seconds. Scope: one fixture writer retry interval.
# Consumers: _thumbnail_cache_concurrent_writer.
# Rationale: 20 ms avoids a busy loop while keeping the fixture bounded.
TEST_CACHE_LOCK_RETRY_INTERVAL_SECONDS = 0.02

# Purpose: Bound completion of independent cache writer processes.
# Type: float. Units: seconds. Scope: all children in one test.
# Consumers: ThumbnailCacheIndexTests.test_concurrent_writers_share_quota.
# Rationale: 30 seconds covers spawn and durable cache commits on loaded CI.
TEST_CACHE_CHILD_TIMEOUT_SECONDS = 30.0

# Purpose: Keep every concurrent thumbnail below the shared quota boundary.
# Type: int. Units: bytes. Scope: one synthetic writer media body.
# Consumers: concurrent writer and shared quota fixture.
# Rationale: 24 bytes makes exact header-inclusive capacity deterministic.
TEST_CACHE_CONCURRENT_BODY_BYTES = 24

# Purpose: Bound retained concurrency output to half of all submitted writes.
# Type: int. Units: entries. Scope: shared synthetic thumbnail index.
# Consumers: ThumbnailCacheIndexTests.test_concurrent_writers_share_quota.
# Rationale: Six slots force safe evictions while all 12 writes are admitted.
TEST_CACHE_CONCURRENT_QUOTA_ENTRIES = 6


def _thumbnail_cache_concurrent_writer(
    root: str, writer_id: int, results: MultiprocessingQueue,
) -> None:
    """Write unique thumbnail keys from one independent cache process.

    Args:
        root (str): Shared temporary cache root path.
        writer_id (int): Positive process identity used only in synthetic keys.
        results (MultiprocessingQueue): Queue receiving (writer, writes, status).

    Returns:
        None: Reports bounded write success or a fixed failure code.
    """
    completed = 0
    failure = ''
    try:
        cache = media_cache.GalleryMediaCache(
            Path(root), TEST_CACHE_BODY_LIMIT_BYTES, thumbnails=True,
        )
        for offset in range(TEST_CACHE_CONCURRENT_WRITES_PER_PROCESS):
            image_id = 300_000 + writer_id * 100 + offset
            body = f'{writer_id}:{offset}'.encode('ascii').ljust(
                TEST_CACHE_CONCURRENT_BODY_BYTES, b'x',
            )
            key = cache.key('https://cache.example/Galerie', 29, image_id, True)
            etag = '"' + hashlib.sha256(body).hexdigest() + '"'
            admitted = False
            # Every fixture input is valid and the shared quota is sufficient
            # for normal eviction, so only ordinary lock collisions are retried.
            for _attempt in range(TEST_CACHE_LOCK_RETRY_ATTEMPTS):
                if cache.write(key, body, 'image/jpeg', etag):
                    admitted = True
                    completed += 1
                    break
                time.sleep(TEST_CACHE_LOCK_RETRY_INTERVAL_SECONDS)
            if not admitted:
                failure = 'write_failed'
                break
    except Exception:
        failure = 'writer_error'
    finally:
        results.put((writer_id, completed, failure))


def _token() -> str:
    """Construct one deterministic canonical fixture access token.

    Returns:
        str: Test bearer with a valid protocol prefix and 256-bit payload.
    """
    return linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
        b'a' * linking.ATTEMPT_RANDOM_BYTES,
    ).rstrip(b'=').decode('ascii')


class GalleryReadLinkingTests(unittest.TestCase):
    """Check explicit gallery detail permissions and exact response shape."""

    def setUp(self) -> None:
        """Prepare one selected gallery and its non-secret detail response.

        Returns:
            None: Fixture issuer, target and response are ready.
        """
        self.issuer = 'https://gallery.example/Galerie'
        self.response = {'gallery': {
            'id': 42, 'title': 'Selected gallery', 'revision': '7',
            'visibility': 'private', 'access_mode': 'password',
            'access_listing': 'unlisted',
        }}

    def _session(self, scope: str) -> linking.LinkingSession:
        """Create a bounded local session for one exact scope fixture.

        Args:
            scope (str): Registered transient or offline scope bundle.

        Returns:
            linking.LinkingSession: Session closed automatically after the test.
        """
        session = linking.LinkingSession(self.issuer, _token(), time.monotonic() + 120, scope)
        self.addCleanup(session.close)
        return session

    def test_old_scopes_never_gain_gallery_detail(self) -> None:
        """Keep all six existing grants unchanged while adding read scopes.

        Returns:
            None: Old sessions reject detail before attempting any request.
        """
        for scope in (linking.IDENTITY_SCOPE, linking.GALLERIES_SCOPE,
                      linking.UPLOAD_SCOPE, linking.IDENTITY_OFFLINE_SCOPE,
                      linking.GALLERIES_OFFLINE_SCOPE, linking.UPLOAD_OFFLINE_SCOPE):
            with self.subTest(scope=scope):
                session = self._session(scope)
                self.assertFalse(session.can_read_gallery_detail())
                with patch.object(linking, '_request_json') as request:
                    with self.assertRaises(linking.LinkingError):
                        session.gallery_detail(42)
                    request.assert_not_called()

    def test_exact_read_scopes_request_selected_id(self) -> None:
        """Read only the named gallery and preserve exact optional upload scope.

        Returns:
            None: Four new bundles return the strict non-secret projection.
        """
        for scope in (linking.READ_SCOPE, linking.READ_UPLOAD_SCOPE,
                      linking.READ_OFFLINE_SCOPE, linking.READ_UPLOAD_OFFLINE_SCOPE):
            with self.subTest(scope=scope):
                session = self._session(scope)
                self.assertTrue(session.can_read_gallery_detail())
                self.assertTrue(session.can_list_galleries())
                self.assertEqual(scope in (linking.READ_UPLOAD_SCOPE,
                                           linking.READ_UPLOAD_OFFLINE_SCOPE),
                                 session.can_upload())
                with patch.object(linking, '_request_json', return_value=self.response) as send:
                    detail = session.gallery_detail(42)
                self.assertEqual(42, detail.gallery_id)
                self.assertEqual('7', detail.revision)
                self.assertEqual('password', detail.access_mode)
                self.assertEqual('app_linking_gallery_detail', send.call_args.args[1])
                self.assertEqual(42, send.call_args.kwargs['gallery_id'])
                self.assertNotIn('folder', repr(detail))

    def test_transport_keeps_detail_id_out_of_bearer_and_other_routes(self) -> None:
        """Pass only the selected positive ID to the fixed detail endpoint.

        Returns:
            None: No invalid ID or unrelated route reaches the HTTPS worker.
        """
        session = self._session(linking.READ_SCOPE)
        with patch.object(linking, '_request_json_once', return_value=self.response) as send:
            detail = session.gallery_detail(42)
        self.assertEqual(42, detail.gallery_id)
        self.assertEqual('app_linking_gallery_detail', send.call_args.args[1])
        self.assertEqual(42, send.call_args.kwargs['gallery_id'])
        for invalid in (0, -1, True, linking.LINKING_GALLERY_ID_MAX + 1):
            with self.subTest(invalid=invalid):
                with patch.object(linking, '_request_json_once') as send:
                    with self.assertRaises(linking.LinkingError):
                        session.gallery_detail(invalid)
                    send.assert_not_called()
        with patch.object(linking, '_request_json_once') as send:
            with self.assertRaises(linking.LinkingError):
                linking._request_json(
                    self.issuer, 'app_linking_identity', 'GET', None, _token(),
                    gallery_id=42,
                )
            send.assert_not_called()

    def test_malformed_or_extra_fields_are_refused(self) -> None:
        """Refuse mismatched IDs, bad revision and unexpected path fields.

        Returns:
            None: No malformed server response yields a detail object.
        """
        session = self._session(linking.READ_SCOPE)
        row = self.response['gallery']
        cases = (
            {'gallery': {**row, 'id': 43}},
            {'gallery': {**row, 'id': True}},
            {'gallery': {**row, 'revision': '00'}},
            {'gallery': {**row, 'revision': str(linking.LINKING_GALLERY_REVISION_MAX + 1)}},
            {'gallery': {**row, 'access_mode': 'inherited'}},
            {'gallery': {**row, 'folder_path': '/private/path'}},
            {'gallery': row, 'extra': True},
        )
        for response in cases:
            with self.subTest(response=response):
                with patch.object(linking, '_request_json', return_value=response):
                    with self.assertRaises(linking.LinkingError):
                        session.gallery_detail(42)

    def test_refresh_record_indices_are_append_only(self) -> None:
        """Keep the three existing serialized scope indices unchanged.

        Returns:
            None: Old payloads decode unchanged and new scopes append only.
        """
        self.assertEqual((linking.IDENTITY_OFFLINE_SCOPE, linking.GALLERIES_OFFLINE_SCOPE,
                          linking.UPLOAD_OFFLINE_SCOPE), offline._OFFLINE_SCOPES[:3])
        self.assertEqual((linking.READ_OFFLINE_SCOPE, linking.READ_UPLOAD_OFFLINE_SCOPE),
                         offline._OFFLINE_SCOPES[3:5])
        appended_scopes = (
            linking.CREATE_OFFLINE_SCOPE,
            linking.READ_CREATE_OFFLINE_SCOPE,
            linking.UPLOAD_CREATE_OFFLINE_SCOPE,
            linking.READ_UPLOAD_CREATE_OFFLINE_SCOPE,
            linking.VISIBILITY_OFFLINE_SCOPE,
            linking.UPLOAD_VISIBILITY_OFFLINE_SCOPE,
            linking.CREATE_VISIBILITY_OFFLINE_SCOPE,
            linking.UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
        )
        self.assertEqual(
            appended_scopes,
            offline._OFFLINE_SCOPES[5:5 + len(appended_scopes)],
        )
        self.assertEqual(len(offline._OFFLINE_SCOPES), len(set(offline._OFFLINE_SCOPES)))


class ThumbnailCacheIndexTests(unittest.TestCase):
    """Verify shared indexed accounting without weakening media integrity."""

    def _cache(self, root: Path) -> media_cache.GalleryMediaCache:
        """Create one isolated ordinary-thumbnail cache owner.

        Args:
            root (Path): Temporary cache root owned by the calling test.

        Returns:
            media_cache.GalleryMediaCache: Cache using the thumbnail child pool.
        """
        return media_cache.GalleryMediaCache(
            root, TEST_CACHE_BODY_LIMIT_BYTES, thumbnails=True,
        )

    def _write_thumbnail(
        self, cache: media_cache.GalleryMediaCache, image_id: int,
        body: bytes,
    ) -> tuple[str, bool, list[media_cache.PhotoCacheReceipt]]:
        """Write one valid synthetic thumbnail and capture its witness.

        Args:
            cache (media_cache.GalleryMediaCache): Isolated cache owner.
            image_id (int): Positive synthetic photo identity.
            body (bytes): Short media body used for this exact cache identity.

        Returns:
            tuple[str, bool, list[PhotoCacheReceipt]]: Key, confirmed write
                result and any cache-confirmed receipt.
        """
        key = cache.key('https://cache.example/Galerie', 9, image_id, True)
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        receipts: list[media_cache.PhotoCacheReceipt] = []
        stored = cache.write(
            key, body, 'image/jpeg', etag, now=1_800_000_000,
            receipts=receipts,
        )
        return key, stored, receipts

    def test_thumbnail_limit_is_shared_and_rejects_invalid_values(self) -> None:
        """Persist one quota across cache instances and reject unsafe inputs.

        Returns:
            None: New cache owners observe the same setting; bool and values
                above the documented integer range leave it unchanged.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'cache'
            first = self._cache(root)
            second = self._cache(root)
            requested = 8 * 1024 * 1024
            self.assertTrue(first.set_thumbnail_limit_bytes(requested))
            self.assertEqual(requested, second.thumbnail_storage_snapshot()[2])
            self.assertFalse(first.set_thumbnail_limit_bytes(True))
            self.assertFalse(first.set_thumbnail_limit_bytes(
                media_cache.MEDIA_THUMBNAIL_CACHE_LIMIT_MAX_BYTES + 1,
            ))
            self.assertEqual(requested, second.thumbnail_storage_snapshot()[2])

    def test_indexed_batch_has_constant_directory_work(self) -> None:
        """Keep post-recovery writes independent of ordinary pool size.

        Returns:
            None: Small and multi-thousand pools incur no per-write directory
                inventory or in-memory sorting during a 265-write batch.
        """
        benchmark: list[dict[str, int | float]] = []
        for seed_count in TEST_CACHE_SEED_COUNTS:
            with self.subTest(seed_count=seed_count):
                with tempfile.TemporaryDirectory() as temporary:
                    root = Path(temporary) / 'cache'
                    cache = self._cache(root)
                    seed_key, stored, _receipts = self._write_thumbnail(
                        cache, 1, b'seed thumbnail',
                    )
                    self.assertTrue(stored)
                    seed_path = cache._pool_entry_path(seed_key, True)
                    seed_bytes = seed_path.read_bytes()
                    cursor = 1
                    created = 0
                    while created < seed_count:
                        name = f'{cursor:064x}.bin'
                        cursor += 1
                        if name == seed_path.name:
                            continue
                        (root / 'thumbnails' / name).write_bytes(seed_bytes)
                        created += 1
                    recovered = cache.thumbnail_storage_snapshot()
                    self.assertTrue(recovered[3], recovered[4])
                    self.assertEqual(seed_count + 1, recovered[0])
                    before = cache.operation_counts()
                    new_observations = {'path_lstat': 0, 'os_fstat': 0}
                    # Cache safety checks use lstat directly. Path.stat does
                    # not observe those no-follow calls on current pathlib.
                    path_class = type(root)
                    original_path_lstat = path_class.lstat
                    original_fstat = os.fstat

                    def observe_path_lstat(
                        path: Path,
                    ) -> os.stat_result:
                        """Count actual no-follow path metadata in this phase.

                        Args:
                            path (Path): Path whose metadata is being observed.

                        Returns:
                            os.stat_result: Original filesystem metadata result.
                        """
                        new_observations['path_lstat'] += 1
                        return original_path_lstat(path)

                    def observe_fstat(descriptor: int) -> os.stat_result:
                        """Count real descriptor metadata calls in this phase.

                        Args:
                            descriptor (int): Open filesystem descriptor.

                        Returns:
                            os.stat_result: Original descriptor metadata result.
                        """
                        new_observations['os_fstat'] += 1
                        return original_fstat(descriptor)

                    started = time.perf_counter()
                    with patch.object(path_class, 'lstat', new=observe_path_lstat), \
                            patch.object(os, 'fstat', new=observe_fstat):
                        for offset in range(TEST_CACHE_PRELOAD_WRITE_BATCH):
                            key, wrote, _receipts = self._write_thumbnail(
                                cache, 10_000 + offset, b'batch thumbnail',
                            )
                            self.assertTrue(wrote, key)
                    elapsed_ms = (time.perf_counter() - started) * 1000.0
                    after = cache.operation_counts()
                    delta = {
                        name: after[name] - before[name]
                        for name in before
                    }
                    self.assertEqual(0, delta['inventory_scans'])
                    self.assertEqual(0, delta['sorts'])
                    self.assertEqual(TEST_CACHE_PRELOAD_WRITE_BATCH,
                                     delta['writes'])
                    self.assertEqual(0, delta['evictions'])
                    self.assertEqual(TEST_CACHE_PRELOAD_WRITE_BATCH,
                                     delta['hashes'])
                    self.assertEqual(
                        len(b'batch thumbnail')
                        * TEST_CACHE_PRELOAD_WRITE_BATCH,
                        delta['hash_bytes'],
                    )
                    self.assertEqual(0, delta['reads'])
                    self.assertGreater(new_observations['path_lstat'], 0)
                    self.assertLessEqual(
                        delta['stat_calls'], 5 * TEST_CACHE_PRELOAD_WRITE_BATCH,
                    )

                    legacy_observations = {'path_lstat': 0, 'os_fstat': 0}
                    legacy_path_lstat = path_class.lstat
                    legacy_fstat = os.fstat

                    def observe_legacy_path_lstat(
                        path: Path,
                    ) -> os.stat_result:
                        """Count no-follow paths for legacy scan comparison.

                        Args:
                            path (Path): Path whose metadata is being observed.

                        Returns:
                            os.stat_result: Original filesystem metadata result.
                        """
                        legacy_observations['path_lstat'] += 1
                        return legacy_path_lstat(path)

                    def observe_legacy_fstat(descriptor: int) -> os.stat_result:
                        """Count descriptor metadata calls in the legacy phase.

                        Args:
                            descriptor (int): Open filesystem descriptor.

                        Returns:
                            os.stat_result: Original descriptor metadata result.
                        """
                        legacy_observations['os_fstat'] += 1
                        return legacy_fstat(descriptor)

                    legacy_before = cache.operation_counts()
                    legacy_started = time.perf_counter()
                    legacy_candidate_stats = 0
                    with patch.object(
                        path_class, 'lstat', new=observe_legacy_path_lstat,
                    ), patch.object(os, 'fstat', new=observe_legacy_fstat):
                        for offset in range(TEST_CACHE_PRELOAD_WRITE_BATCH):
                            candidates = cache._inventory(True)
                            legacy_candidate_stats += len(candidates)
                            candidates.sort(key=lambda entry: entry[2])
                            key, wrote, _receipts = self._write_thumbnail(
                                cache, 10_000 + offset, b'batch thumbnail',
                            )
                            self.assertTrue(wrote, key)
                    legacy_elapsed_ms = (
                        time.perf_counter() - legacy_started
                    ) * 1000.0
                    legacy_after = cache.operation_counts()
                    legacy_delta = {
                        name: legacy_after[name] - legacy_before[name]
                        for name in legacy_before
                    }
                    self.assertEqual(
                        TEST_CACHE_PRELOAD_WRITE_BATCH,
                        legacy_delta['writes'],
                    )
                    benchmark.append({
                        'seed_entries': seed_count,
                        'writes': TEST_CACHE_PRELOAD_WRITE_BATCH,
                        'elapsed_ms': elapsed_ms,
                        'legacy_scan_and_write_ms': legacy_elapsed_ms,
                        'path_lstat_calls': new_observations['path_lstat'],
                        'os_fstat_calls': new_observations['os_fstat'],
                        'legacy_path_lstat_calls': legacy_observations['path_lstat'],
                        'legacy_os_fstat_calls': legacy_observations['os_fstat'],
                        'legacy_inventory_scans': TEST_CACHE_PRELOAD_WRITE_BATCH,
                        'legacy_sort_calls': TEST_CACHE_PRELOAD_WRITE_BATCH,
                        'legacy_candidate_metadata_stats': legacy_candidate_stats,
                        **delta,
                    })
        evidence = Path(__file__).resolve().parents[2] / 'cache' / 'test-audit'
        evidence.mkdir(parents=True, exist_ok=True)
        (evidence / 'thumbnail-cache-measurement.json').write_text(
            json.dumps({
                'data_source': 'isolated synthetic files on runner disk',
                'comparison': 'same writes plus redundant legacy inventory/sort',
                'measurements': benchmark,
            }, indent=2) + '\n', encoding='utf-8',
        )
        print('CACHE_BENCHMARK ' + json.dumps(benchmark, sort_keys=True))

    def test_replacement_and_external_removal_reconcile_exact_totals(self) -> None:
        """Update physical byte totals for replacement and outside deletion.

        Returns:
            None: Replacing one key changes only its byte delta, while an
                external removal is discovered before the next ready snapshot.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'cache'
            cache = self._cache(root)
            key, stored, _receipts = self._write_thumbnail(cache, 5, b'old')
            self.assertTrue(stored)
            initial = cache.thumbnail_storage_snapshot()
            self.assertEqual((1, len(b'old') + media_cache._CACHE_HEADER.size),
                             initial[:2])
            replacement_key = cache.key(
                'https://cache.example/Galerie', 9, 5, True,
            )
            body = b'new replacement bytes'
            self.assertTrue(cache.write(
                replacement_key, body, 'image/jpeg',
                '"' + hashlib.sha256(body).hexdigest() + '"', now=1_800_000_001,
            ))
            replaced = cache.thumbnail_storage_snapshot()
            self.assertEqual(1, replaced[0])
            self.assertEqual(
                len(body) + media_cache._CACHE_HEADER.size, replaced[1],
            )
            cache._pool_entry_path(key, True).unlink()
            removed = cache.thumbnail_storage_snapshot()
            self.assertEqual((0, 0), removed[:2])

    def test_byte_and_entry_limits_evict_only_over_the_exact_boundary(self) -> None:
        """Enforce physical byte and entry ceilings at their exact limits.

        Returns:
            None: Exact capacity admits the boundary item and one additional
                entry evicts an ordinary LRU victim while preserving the limit.
        """
        body = b'1234567890'
        entry_bytes = len(body) + media_cache._CACHE_HEADER.size
        with tempfile.TemporaryDirectory() as temporary:
            cache = self._cache(Path(temporary) / 'cache')
            self.assertTrue(cache.set_thumbnail_limit_bytes(2 * entry_bytes))
            first = self._write_thumbnail(cache, 1, body)
            second = self._write_thumbnail(cache, 2, body)
            self.assertTrue(first[1])
            self.assertTrue(second[1])
            self.assertEqual((2, 2 * entry_bytes),
                             cache.thumbnail_storage_snapshot()[:2])
            third = self._write_thumbnail(cache, 3, body)
            self.assertTrue(third[1])
            snapshot = cache.thumbnail_storage_snapshot()
            self.assertEqual((2, 2 * entry_bytes), snapshot[:2])

        with tempfile.TemporaryDirectory() as temporary:
            cache = self._cache(Path(temporary) / 'cache')
            self.assertTrue(cache.set_thumbnail_limit_bytes(100_000))
            with patch.object(
                media_cache, 'MEDIA_THUMBNAIL_CACHE_MAX_ENTRIES', 2,
            ):
                for image_id in (1, 2, 3):
                    self.assertTrue(self._write_thumbnail(
                        cache, image_id, body,
                    )[1])
                self.assertEqual(2, cache.thumbnail_storage_snapshot()[0])

    def test_failed_replace_recovers_dirty_index_without_false_entry(self) -> None:
        """Reconcile an interrupted atomic replace before reporting ready state.

        Returns:
            None: A failed target does not appear in totals after a new owner
                recovers the durable dirty marker.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'cache'
            cache = self._cache(root)
            _key, stored, _receipts = self._write_thumbnail(
                cache, 1, b'existing',
            )
            self.assertTrue(stored)
            failed_key = cache.key(
                'https://cache.example/Galerie', 9, 2, True,
            )
            failed_body = b'not published'
            with patch.object(media_cache.os, 'replace', side_effect=OSError):
                self.assertFalse(cache.write(
                    failed_key, failed_body, 'image/jpeg',
                    '"' + hashlib.sha256(failed_body).hexdigest() + '"',
                    now=1_800_000_002,
                ))
            recovered = self._cache(root).thumbnail_storage_snapshot()
            self.assertTrue(recovered[3], recovered[4])
            self.assertEqual(1, recovered[0])
            self.assertEqual(
                len(b'existing') + media_cache._CACHE_HEADER.size,
                recovered[1],
            )

    def test_replace_before_index_publication_recovers_physical_entry(self) -> None:
        """Reconcile an atomic file that survived an index publication failure.

        Returns:
            None: An uncertain write returns false without a receipt, then the
                next owner discovers the real complete file through recovery.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'cache'
            cache = self._cache(root)
            key = cache.key('https://cache.example/Galerie', 9, 22, True)
            body = b'published before index failure'
            receipts: list[media_cache.PhotoCacheReceipt] = []
            with patch.object(
                media_cache.ThumbnailCacheIndex, 'publish',
                side_effect=OSError('fixture index publication failure'),
            ):
                stored = cache.write(
                    key, body, 'image/jpeg',
                    '"' + hashlib.sha256(body).hexdigest() + '"',
                    now=1_800_000_022, receipts=receipts,
                )
            self.assertFalse(stored)
            self.assertEqual([], receipts)
            entry_path = cache._pool_entry_path(key, True)
            self.assertTrue(entry_path.is_file())
            recovered = self._cache(root).thumbnail_storage_snapshot()
            self.assertTrue(recovered[3], recovered[4])
            self.assertEqual(1, recovered[0])
            self.assertEqual(entry_path.stat().st_size, recovered[1])

    def test_concurrent_writers_share_quota_across_processes(self) -> None:
        """Serialize independent cache writers under one durable shared quota.

        Returns:
            None: Every bounded child write succeeds despite lock collisions;
                recovered accounting exactly matches surviving physical files.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'cache'
            cache = self._cache(root)
            physical_entry_bytes = (
                TEST_CACHE_CONCURRENT_BODY_BYTES + media_cache._CACHE_HEADER.size
            )
            quota = physical_entry_bytes * TEST_CACHE_CONCURRENT_QUOTA_ENTRIES
            self.assertTrue(cache.set_thumbnail_limit_bytes(quota))
            context = multiprocessing.get_context('spawn')
            result_queue = context.Queue()
            processes = [
                context.Process(
                    target=_thumbnail_cache_concurrent_writer,
                    args=(str(root), writer_id, result_queue),
                )
                for writer_id in range(1, TEST_CACHE_CONCURRENT_WRITERS + 1)
            ]
            try:
                for process in processes:
                    process.start()
                deadline = time.monotonic() + TEST_CACHE_CHILD_TIMEOUT_SECONDS
                for process in processes:
                    process.join(max(0.0, deadline - time.monotonic()))
                self.assertTrue(all(not process.is_alive() for process in processes))
                self.assertTrue(all(process.exitcode == 0 for process in processes))
                results: list[tuple[int, int, str]] = []
                for _process in processes:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0:
                        break
                    try:
                        results.append(result_queue.get(timeout=remaining))
                    except queue.Empty:
                        break
                self.assertEqual(len(processes), len(results))
                self.assertEqual(
                    list(range(1, TEST_CACHE_CONCURRENT_WRITERS + 1)),
                    sorted(writer for writer, _completed, _failure in results),
                )
                self.assertEqual(
                    TEST_CACHE_CONCURRENT_WRITERS
                    * TEST_CACHE_CONCURRENT_WRITES_PER_PROCESS,
                    sum(completed for _writer, completed, _failure in results),
                )
                self.assertTrue(all(not failure for _writer, _completed, failure in results))
                snapshot = self._cache(root).thumbnail_storage_snapshot()
                self.assertTrue(snapshot[3], snapshot[4])
                files = tuple((root / 'thumbnails').glob('*.bin'))
                physical_bytes = sum(path.stat().st_size for path in files)
                self.assertEqual(len(files), snapshot[0])
                self.assertEqual(physical_bytes, snapshot[1])
                self.assertEqual(TEST_CACHE_CONCURRENT_QUOTA_ENTRIES, snapshot[0])
                self.assertLessEqual(snapshot[1], quota)
            finally:
                for process in processes:
                    if process.pid is not None:
                        if process.is_alive():
                            process.terminate()
                        process.join()
                result_queue.close()
                result_queue.join_thread()

    def test_pin_unpin_updates_ordinary_thumbnail_accounting(self) -> None:
        """Exclude retained entries from ordinary thumbnail accounting.

        Returns:
            None: Pinning stays outside ordinary totals and unpin conversion
                places the retained thumbnail back in the ordinary index.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'cache'
            cache = self._cache(root)
            issuer = 'https://cache.example/Galerie'
            gallery_key = cache.gallery_key(issuer, 9)
            key = cache.key(issuer, 9, 7, True)
            body = b'pinned thumbnail'
            self.assertTrue(cache.set_gallery_pinned(issuer, 9, True))
            self.assertTrue(cache.write(
                key, body, 'image/jpeg',
                '"' + hashlib.sha256(body).hexdigest() + '"',
                now=1_800_000_000, gallery_key=gallery_key,
            ))
            self.assertEqual(0, cache.thumbnail_storage_snapshot()[0])
            self.assertTrue(cache.set_gallery_pinned(issuer, 9, False))
            self.assertEqual(1, cache.thumbnail_storage_snapshot()[0])


    def test_symlink_index_path_is_rejected(self) -> None:
        """Refuse a reparse-backed SQLite index before opening it.

        Returns:
            None: An index symlink yields unavailable accounting rather than
                following a path outside the canonical cache root.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'cache'
            target = Path(temporary) / 'outside'
            root.mkdir()
            (root / 'thumbnails').mkdir()
            target.mkdir()
            try:
                (root / '.thumbnail-index.sqlite3').symlink_to(
                    target / 'index.sqlite3',
                )
            except (OSError, NotImplementedError):
                self.skipTest('This runner does not permit symbolic links.')
            unsafe = self._cache(root).thumbnail_storage_snapshot()
            self.assertFalse(unsafe[3])
            self.assertIsNone(unsafe[0])

    def test_symlink_thumbnail_directory_is_rejected(self) -> None:
        """Refuse a thumbnail child directory that redirects elsewhere.

        Returns:
            None: The unsafe pool remains unavailable and is never traversed.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'cache'
            target = Path(temporary) / 'outside'
            root.mkdir()
            target.mkdir()
            try:
                (root / 'thumbnails').symlink_to(
                    target, target_is_directory=True,
                )
            except (OSError, NotImplementedError):
                self.skipTest('This runner does not permit symbolic links.')
            unsafe_pool = self._cache(root).thumbnail_storage_snapshot()
            self.assertFalse(unsafe_pool[3])
            self.assertIsNone(unsafe_pool[0])

    def test_write_receipt_is_bound_to_key_and_stable_file_identity(self) -> None:
        """Accept only the exact unchanged thumbnail publication witness.

        Returns:
            None: The actual target receipt is current for its key, rejected for
                another key, and invalidated by a same-size in-place mutation.
                Publication and verification use opened-file timestamps on
                Windows as well as POSIX, rather than mixing path/handle times.
        """
        with tempfile.TemporaryDirectory() as temporary:
            cache = self._cache(Path(temporary) / 'cache')
            key, stored, receipts = self._write_thumbnail(
                cache, 11, b'fixed size body',
            )
            self.assertTrue(stored)
            self.assertEqual(1, len(receipts))
            receipt = receipts[0]
            with receipt.path.open('rb') as stream:
                published_info = os.fstat(stream.fileno())
            self.assertEqual(
                published_info.st_ctime_ns, receipt.file_info.st_ctime_ns,
            )
            self.assertEqual(
                published_info.st_mtime_ns, receipt.file_info.st_mtime_ns,
            )
            self.assertTrue(cache.receipt_current(receipt, key=key))
            other_key = cache.key(
                'https://cache.example/Galerie', 9, 12, True,
            )
            self.assertFalse(cache.receipt_current(receipt, key=other_key))
            replacement = b'other same body'
            replacement_receipts: list[media_cache.PhotoCacheReceipt] = []
            self.assertTrue(cache.write(
                key, replacement, 'image/jpeg',
                '"' + hashlib.sha256(replacement).hexdigest() + '"',
                now=1_800_000_001, receipts=replacement_receipts,
            ))
            self.assertFalse(cache.receipt_current(receipt, key=key))
            current_receipt = replacement_receipts[0]
            self.assertTrue(cache.receipt_current(current_receipt, key=key))
            with current_receipt.path.open('r+b') as stream:
                original = stream.read()
                stream.seek(0)
                stream.write(bytes([original[0] ^ 1]) + original[1:])
                stream.flush()
                os.fsync(stream.fileno())
            self.assertFalse(cache.receipt_current(current_receipt, key=key))


if __name__ == '__main__':
    unittest.main()
