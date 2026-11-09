# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_thumbnail_cache_return.py
# Module Type: Regression Test
# Purpose: Verify stable thumbnail cache reuse and persistent disk recovery.
# Responsibilities:
#   - Exercise private decoded-cache proof changes without Tk or user state.
#   - Verify bounded metadata refresh behavior for expired thumbnail proofs.
#   - Exercise real thumbnail disk writes, reopen reads and corrupt-entry misses.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable regressions for linked-gallery thumbnail cache returns."""

import hashlib
import sys
import tempfile
import threading
import time
import unittest
from dataclasses import replace
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import app_linking as linking
from uploader import native_gallery_viewer as viewer_module


# Purpose: Keep one synthetic account-scoped Admin thumbnail identity stable.
# Type: int. Units: account IDs. Scope: this fixture's private cache witness.
# Consumers: ThumbnailRamProofTests. Rationale: positive IDs are required by
# the authenticated account cache identity and distinguish account changes.
TEST_USER_ID = 41

# Purpose: Keep the fixture's private thumbnail validator deterministic.
# Type: str. Units: strong ETag. Scope: one synthetic image rendition.
# Consumers: ThumbnailRamProofTests. Rationale: a quoted SHA-256-shaped token
# satisfies the production Admin thumbnail proof format.
TEST_THUMBNAIL_ETAG = '"' + 'a' * 64 + '"'

# Purpose: Bind disk integration assertions to one isolated media identity.
# Type: tuple[int, int]. Units: gallery and image IDs. Scope: temporary cache.
# Consumers: ThumbnailDiskPersistenceTests. Rationale: positive IDs satisfy
# the native media route identity validation without referring to user data.
TEST_DISK_MEDIA_ID = (73, 19)

# Purpose: Keep every synthetic session live through one cache operation.
# Type: float. Units: seconds. Scope: temporary test-only linking sessions.
# Consumers: ThumbnailRamProofTests and ThumbnailDiskPersistenceTests.
# Rationale: two minutes exceeds these synchronous fixtures without resembling
# a persisted or user-owned session lifetime.
TEST_SESSION_LIFETIME_SECONDS = 120.0

# Purpose: Move proof clocks across freshness and retry boundaries precisely.
# Type: float. Units: seconds. Scope: deterministic timestamp test vectors.
# Consumers: ThumbnailRamProofTests. Rationale: 10 ms separates either side
# of the retry boundary while one second creates a newer metadata proof.
TEST_CLOCK_EDGE_SECONDS = 0.01
TEST_PROOF_ADVANCE_SECONDS = 1.0


class ThumbnailRamProofTests(unittest.TestCase):
    """Check stable decoded entries while live proof gates remain mandatory."""

    def _viewer(
        self, identity: tuple[int, str, float, int] | None,
    ) -> tuple[
        viewer_module._NativeGalleryViewer,
        linking.LinkingSession,
        viewer_module.GalleryThumbnailMemory,
    ]:
        """Build the minimum non-Tk viewer state for cache methods.

        Args:
            identity (tuple[int, str, float, int] | None): Current private
                proof identity returned by the live session gate.

        Returns:
            tuple[NativeGalleryViewer, LinkingSession, GalleryThumbnailMemory]:
                Viewer shell, its live session, and its empty RAM cache.
        """
        session = linking.LinkingSession(
            'https://gallery.example/Galerie',
            linking.TOKEN_PREFIX + 'A' * 43,
            time.monotonic() + TEST_SESSION_LIFETIME_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
            admin_preload_supported=True,
        )
        if identity is not None:
            user_id, etag, verified_at, epoch = identity
            session._bound_user_id = user_id
            session._private_preload_proofs[TEST_DISK_MEDIA_ID[0]] = (
                user_id, verified_at,
                {TEST_DISK_MEDIA_ID[1]: (etag, verified_at, epoch, None)},
                False,
            )
        memory = viewer_module.GalleryThumbnailMemory()
        self.addCleanup(session.close)
        self.addCleanup(memory.clear)
        instance = object.__new__(viewer_module._NativeGalleryViewer)
        instance.session = session
        instance.gallery = SimpleNamespace(gallery_id=TEST_DISK_MEDIA_ID[0])
        instance._thumbnail_memory = memory
        instance._visible_thumbnail_indices = {0}
        instance._photos = ()
        return instance, session, memory

    def _photo(self, *, etag: str = TEST_THUMBNAIL_ETAG,
               cache_eligible: bool | None = False,
               preload_eligible: bool | None = True) -> linking.LinkedPhoto:
        """Create a descriptor representing a private Admin thumbnail.

        Args:
            etag (str): Strong server proof for the thumbnail bytes.
            cache_eligible (bool | None): Independent public cache policy.
            preload_eligible (bool | None): Admin thumbnail admission fact.

        Returns:
            LinkedPhoto: One authorized non-NSFW photo descriptor.
        """
        return linking.LinkedPhoto(
            TEST_DISK_MEDIA_ID[1], 'synthetic photo', nsfw=False,
            cache_eligible=cache_eligible, preload_eligible=preload_eligible,
            preload_thumbnail_etag=etag,
            preload_verified_at=time.monotonic(),
        )

    @unittest.skipIf(viewer_module.Image is None, 'Pillow is not installed')
    def test_same_validator_reuses_ram_after_proof_time_and_epoch_change(self) -> None:
        """Keep equivalent bytes warm when a fresh proof supersedes the old.

        Returns:
            None: A live fresh private proof gates the stable identity and no
                second decode entry is required after timestamp or epoch drift.
        """
        old_time = time.monotonic()
        old_identity = (TEST_USER_ID, TEST_THUMBNAIL_ETAG, old_time, 2)
        instance, session, memory = self._viewer(old_identity)
        photo = replace(self._photo(), preload_verified_at=old_time)
        instance._photos = (photo,)
        with mock.patch.object(viewer_module.time, 'monotonic', return_value=old_time):
            instance._remember_thumbnail(
                photo, viewer_module.Image.new('RGB', (12, 12)),
            )
        renewed_identity = (
            TEST_USER_ID, TEST_THUMBNAIL_ETAG,
            old_time + TEST_PROOF_ADVANCE_SECONDS, 3,
        )
        session._private_preload_proofs[TEST_DISK_MEDIA_ID[0]] = (
            TEST_USER_ID, renewed_identity[2],
            {
                TEST_DISK_MEDIA_ID[1]: (
                    TEST_THUMBNAIL_ETAG, renewed_identity[2], renewed_identity[3], None,
                ),
            },
            False,
        )
        with mock.patch.object(
            viewer_module.time, 'monotonic',
            return_value=old_time + TEST_PROOF_ADVANCE_SECONDS + TEST_CLOCK_EDGE_SECONDS,
        ):
            image = instance._cached_thumbnail(photo)
        self.assertIsNotNone(image)
        image.close()
        self.assertEqual(1, len(memory.entries))
        self.assertEqual(0, memory.validator_changes)
        memory.clear()

    @unittest.skipIf(viewer_module.Image is None, 'Pillow is not installed')
    def test_changed_authority_or_policy_refuses_private_ram_entry(self) -> None:
        """Reject stale, changed-validator and changed-account authority.

        Returns:
            None: Every cache lookup rechecks current private authority before
                deriving a stable account and validator cache key.
        """
        now = time.monotonic()
        original = (TEST_USER_ID, TEST_THUMBNAIL_ETAG, now, 4)
        instance, session, memory = self._viewer(original)
        photo = replace(self._photo(), preload_verified_at=now)
        instance._photos = (photo,)
        instance._remember_thumbnail(
            photo, viewer_module.Image.new('RGB', (12, 12)),
        )
        self.assertEqual(1, len(memory.entries))
        cases = (
            ('expired proof', None, photo),
            ('changed account', (TEST_USER_ID + 1, TEST_THUMBNAIL_ETAG, now, 4), photo),
            ('changed validator', (TEST_USER_ID, '"' + 'b' * 64 + '"', now, 4), photo),
            ('changed policy', None, self._photo(cache_eligible=True)),
        )
        for label, identity, descriptor in cases:
            with self.subTest(case=label):
                if identity is None:
                    session._private_preload_proofs.clear()
                else:
                    user_id, etag, verified_at, epoch = identity
                    session._bound_user_id = user_id
                    session._private_preload_proofs[TEST_DISK_MEDIA_ID[0]] = (
                        user_id, verified_at,
                        {TEST_DISK_MEDIA_ID[1]: (etag, verified_at, epoch, None)},
                        False,
                    )
                self.assertIsNone(instance._cached_thumbnail(descriptor))
        self.assertEqual(1, len(memory.entries))
        memory.clear()

    @unittest.skipIf(viewer_module.Image is None, 'Pillow is not installed')
    def test_private_thumbnail_without_current_proof_is_not_retained(self) -> None:
        """Do not retain private bytes when no-store response lacks proof.

        Returns:
            None: Admission with no live proof closes the supplied raster and
                leaves the session-owned cache empty.
        """
        instance, _session, memory = self._viewer(None)
        photo = self._photo()
        instance._photos = (photo,)
        image = viewer_module.Image.new('RGB', (12, 12))
        instance._remember_thumbnail(photo, image)
        self.assertFalse(memory.entries)
        self.assertEqual(1, memory.admission_refusals)
        with self.assertRaises(ValueError):
            image.getpixel((0, 0))

    def _refresh_viewer(
        self, photo: linking.LinkedPhoto,
    ) -> tuple[viewer_module._NativeGalleryViewer, linking.LinkingSession]:
        """Build a viewer shell with only proof-refresh state initialized.

        Args:
            photo (LinkedPhoto): One visible admitted descriptor.

        Returns:
            tuple[object, LinkingSession]: Refresh-capable shell and its live
                private or public session owner.
        """
        identity = (TEST_USER_ID, TEST_THUMBNAIL_ETAG, time.monotonic(), 8)
        instance, session, _memory = self._viewer(identity)
        instance._closed = False
        instance._refresh_pending = False
        instance._worker_kind = None
        instance._thumbnail_proof_refresh_attempt_at = None
        instance._thumbnail_proof_refresh_count = 0
        instance._admitted_thumbnail_indices = {0}
        instance._photos = (photo,)
        instance._cancel_thumbnail_gui_slice = mock.Mock()
        instance._thumbnail_disk_lane = mock.Mock()
        instance._thumbnail_cancelled = threading.Event()
        instance._generation = 0
        return instance, session

    def test_expired_proof_refresh_coalesces_and_bounds_failed_retry(self) -> None:
        """Queue one catalogue read for expired proofs and cap retries.

        Returns:
            None: Existing refresh work coalesces; a failed attempt cannot
                schedule another refresh until the existing revision interval.
        """
        now = time.monotonic()
        photo = linking.LinkedPhoto(
            TEST_DISK_MEDIA_ID[1], 'public photo', cache_eligible=True,
            thumbnail_etag=TEST_THUMBNAIL_ETAG,
            thumbnail_verified_at=(
                now - viewer_module.MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS
                - TEST_PROOF_ADVANCE_SECONDS
            ),
        )
        instance, session = self._refresh_viewer(photo)
        with mock.patch.object(viewer_module.time, 'monotonic', return_value=now):
            self.assertTrue(instance._queue_thumbnail_proof_refresh())
            self.assertTrue(instance._refresh_pending)
            self.assertEqual(1, instance._thumbnail_proof_refresh_count)
            instance._refresh_pending = False
            with mock.patch.object(
                viewer_module.time, 'monotonic',
                return_value=(
                    now + viewer_module.MEDIA_REVISION_POLL_MS / 1000.0
                    - TEST_CLOCK_EDGE_SECONDS
                ),
            ):
                self.assertFalse(instance._queue_thumbnail_proof_refresh())
            with mock.patch.object(
                viewer_module.time, 'monotonic',
                return_value=(
                    now + viewer_module.MEDIA_REVISION_POLL_MS / 1000.0
                    + TEST_CLOCK_EDGE_SECONDS
                ),
            ):
                self.assertTrue(instance._queue_thumbnail_proof_refresh())
        self.assertEqual(2, instance._thumbnail_proof_refresh_count)
        session.close()

    def test_live_newer_private_proof_and_existing_refresh_do_not_restart(self) -> None:
        """Accept a fresh ledger proof and coalesce work already underway.

        Returns:
            None: A descriptor superseded by a fresh same-validator ledger row
                stays usable; existing refresh ownership reports queued.
        """
        now = time.monotonic()
        photo = self._photo()
        photo = replace(
            photo,
            preload_verified_at=(
                now - viewer_module.MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS
                - TEST_PROOF_ADVANCE_SECONDS
            ),
        )
        instance, session = self._refresh_viewer(photo)
        now = time.monotonic()
        with mock.patch.object(viewer_module.time, 'monotonic', return_value=now):
            self.assertFalse(instance._queue_thumbnail_proof_refresh())
            self.assertEqual(0, instance._thumbnail_proof_refresh_count)
            instance._refresh_pending = True
            self.assertTrue(instance._queue_thumbnail_proof_refresh())
            instance._refresh_pending = False
            instance._worker_kind = 'refresh'
            self.assertTrue(instance._queue_thumbnail_proof_refresh())
        self.assertEqual(0, instance._thumbnail_proof_refresh_count)
        session.close()

    def test_revoked_access_closed_viewer_and_unproven_rows_are_skipped(self) -> None:
        """Skip renewal when authority is gone or descriptor lacks proof.

        Returns:
            None: Closed viewers, revoked sessions, no-store-like metadata and
                unsupported private rows never queue a background refresh.
        """
        now = time.monotonic()
        expired_at = (
            now - viewer_module.MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS
            - TEST_PROOF_ADVANCE_SECONDS
        )
        public_expired = linking.LinkedPhoto(
            TEST_DISK_MEDIA_ID[1], 'expired', cache_eligible=True,
            thumbnail_etag=TEST_THUMBNAIL_ETAG, thumbnail_verified_at=expired_at,
        )
        instance, session = self._refresh_viewer(public_expired)
        session.close()
        self.assertFalse(instance._queue_thumbnail_proof_refresh())
        instance._closed = True
        self.assertFalse(instance._queue_thumbnail_proof_refresh())

        unproven_rows = (
            linking.LinkedPhoto(
                TEST_DISK_MEDIA_ID[1], 'no store', cache_eligible=True,
            ),
            linking.LinkedPhoto(
                TEST_DISK_MEDIA_ID[1], 'unsupported private', nsfw=False,
                cache_eligible=False, preload_eligible=False,
                preload_thumbnail_etag=TEST_THUMBNAIL_ETAG,
            ),
        )
        for descriptor in unproven_rows:
            with self.subTest(title=descriptor.title):
                shell, live_session = self._refresh_viewer(descriptor)
                with mock.patch.object(
                    viewer_module.time, 'monotonic', return_value=now,
                ):
                    self.assertFalse(shell._queue_thumbnail_proof_refresh())
                self.assertEqual(0, shell._thumbnail_proof_refresh_count)
                live_session.close()


class ThumbnailDiskPersistenceTests(unittest.TestCase):
    """Use isolated real cache files and a simulated media transport."""

    def _session(self) -> linking.LinkingSession:
        """Return a live public media session with no external transport.

        Returns:
            LinkingSession: Synthetic gallery-scoped session for cache tests.
        """
        session = linking.LinkingSession(
            'https://gallery.example/Galerie',
            linking.TOKEN_PREFIX + 'A' * 43,
            time.monotonic() + TEST_SESSION_LIFETIME_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        self.addCleanup(session.close)
        return session

    def test_thumbnail_write_survives_cache_reopen_and_corruption_misses(self) -> None:
        """Persist a thumbnail, reopen it, then fail closed on bad disk bytes.

        Returns:
            None: Valid hashed files produce disk hits after reopen; corrupt and
                missing files produce misses and an actual read uses transport.
        """
        gallery_id, image_id = TEST_DISK_MEDIA_ID
        issuer = 'https://gallery.example/Galerie'
        body = b'isolated thumbnail bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        photo = linking.LinkedPhoto(
            image_id, 'synthetic photo', cache_eligible=True,
            thumbnail_etag=etag, thumbnail_verified_at=time.monotonic(),
        )
        session = self._session()
        with tempfile.TemporaryDirectory(prefix='phpgallery-thumb-return-') as directory:
            root = Path(directory)
            cache = linking.GalleryMediaCache(
                root, linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
            )
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=cache,
            ), mock.patch.object(
                session, '_media_read', return_value=(body, 'image/jpeg', etag, True),
            ) as network:
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.media_photo(
                        gallery_id, image_id, thumbnail=True,
                        cache_eligible=True, thumbnail_etag=etag,
                        thumbnail_verified_at=time.monotonic(),
                    ),
                )
                network.assert_called_once()
            cache_path = cache._pool_entry_path(
                cache.key(issuer, gallery_id, image_id, True), True,
            )
            self.assertTrue(cache_path.is_file())

            reopened = linking.GalleryMediaCache(
                root, linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
            )
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=reopened,
            ), mock.patch.object(
                session, '_media_read', side_effect=AssertionError('Disk hit expected.'),
            ):
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.cached_media_photo(gallery_id, photo, thumbnail=True),
                )

            cache_path.write_bytes(b'corrupt')
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=reopened,
            ):
                self.assertIsNone(
                    session.cached_media_photo(gallery_id, photo, thumbnail=True),
                )
                with mock.patch.object(
                    session, '_media_read',
                    return_value=(body, 'image/jpeg', etag, True),
                ) as fallback:
                    self.assertEqual(
                        (body, 'image/jpeg'),
                        session.media_photo(
                            gallery_id, image_id, thumbnail=True,
                            cache_eligible=True, thumbnail_etag=etag,
                            thumbnail_verified_at=time.monotonic(),
                        ),
                    )
                    fallback.assert_called_once()

            cache_path.unlink()
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=reopened,
            ):
                self.assertIsNone(
                    session.cached_media_photo(gallery_id, photo, thumbnail=True),
                )

    def test_no_store_thumbnail_response_does_not_create_disk_entry(self) -> None:
        """Keep a no-store body transient even when public cache is eligible.

        Returns:
            None: The validated response is returned, while a later local lookup
                misses because the response policy did not permit persistence.
        """
        gallery_id, image_id = TEST_DISK_MEDIA_ID
        issuer = 'https://gallery.example/Galerie'
        body = b'transient no-store thumbnail'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        session = self._session()
        photo = linking.LinkedPhoto(image_id, 'synthetic photo', cache_eligible=True)
        with tempfile.TemporaryDirectory(prefix='phpgallery-thumb-nostore-') as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
            )
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=cache,
            ), mock.patch.object(
                session, '_media_read', return_value=(body, 'image/jpeg', etag, False),
            ):
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.media_photo(
                        gallery_id, image_id, thumbnail=True,
                        cache_eligible=True,
                    ),
                )
                self.assertIsNone(session.cached_media_photo(
                    gallery_id, photo, thumbnail=True,
                ))
            key = cache.key(issuer, gallery_id, image_id, True)
            self.assertIsNone(cache.read(key))
