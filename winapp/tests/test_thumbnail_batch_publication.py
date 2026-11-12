# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_thumbnail_batch_publication.py
# Module Type: Regression Test
# Purpose: Verify independent authorization of batch thumbnail publication.
# Responsibilities:
#   - Fence cache writes and RAM permission with current catalogue proof.
#   - Refuse changed validators, accounts, generations and cancellation.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise batch publication through real temporary cache storage."""

import hashlib
import sys
import threading
import time
import unittest
from dataclasses import replace
from pathlib import Path
from tempfile import TemporaryDirectory
from typing import Callable
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import app_linking as linking
from uploader.thumbnail_batch_protocol import ValidatedItem


# Purpose: Keep a synthetic live session valid throughout ordinary disk work.
# Type: float. Units: seconds. Scope: one publication regression.
# Consumers: ThumbnailPublicationTests.setUp. Rationale: sixty seconds allows
# slow hosted cache writes without extending actual authorization policy.
TEST_SESSION_LIFETIME_SECONDS = 60.0

# Purpose: Exercise a server cooldown longer than one operation's deadline.
# Type: int. Units: seconds. Scope: one synthetic batch throttle.
# Consumers: test_throttle_remains_active_across_calls.
# Rationale: One hour verifies full server hints without sleeping in a test.
TEST_RETRY_AFTER_SECONDS = 3600


class ThumbnailPublicationTests(unittest.TestCase):
    """Prove each result remains bound to its exact current proof."""

    def setUp(self) -> None:
        """Prepare one real cache and an authenticated synthetic catalogue.

        Returns:
            None: Creates isolated public proof and a production cache owner.
        """
        temporary = TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.issuer = 'https://gallery.example/Galerie'
        self.session = linking.LinkingSession(
            self.issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_SESSION_LIFETIME_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        self.addCleanup(self.session.close)
        self.cache = linking.GalleryMediaCache(
            Path(temporary.name), linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
        )
        patcher = mock.patch.object(
            linking.GalleryMediaCache, 'for_current_user', return_value=self.cache,
        )
        patcher.start()
        self.addCleanup(patcher.stop)
        self.body = b'validated thumbnail'
        self.digest = hashlib.sha256(self.body).hexdigest()
        self.etag = '"' + self.digest + '"'
        self.at = time.monotonic()
        self.photo = linking.LinkedPhoto(
            7, 'Photo', cache_eligible=True, thumbnail_etag=self.etag,
            thumbnail_verified_at=self.at,
        )
        self.session._public_media_descriptors[4] = {7: (self.etag, self.at)}
        self.item = ValidatedItem(
            '7', 0, 'ok', 'image/jpeg', self.body, self.digest, self.etag,
        )

    def private_photo(self) -> linking.LinkedPhoto:
        """Bind an Admin session to a fresh exact private row proof.

        Returns:
            linking.LinkedPhoto: Non-NSFW descriptor with account-bound proof.
        """
        self.session._scope = linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX
        self.session._admin_preload_supported = True
        self.session._bound_user_id = 13
        self.session._private_preload_proofs[4] = (
            13, self.at, {7: (self.etag, self.at, 1, None)}, False,
        )
        return linking.LinkedPhoto(
            7, 'Private', nsfw=False, cache_eligible=False,
            preload_eligible=True, preload_thumbnail_etag=self.etag,
            preload_verified_at=self.at,
        )

    def test_public_and_private_write_only_under_exact_proof(self) -> None:
        """Publish matching bytes under the existing isolated cache keys.

        Returns:
            None: Both independently authorized cache paths remain distinct.
        """
        public = self.session.prepare_thumbnail_batch_item(4, self.photo)
        timings = linking.MediaReadTimings()
        result = self.session.finish_thumbnail_batch_item(
            public, self.item, timings=timings,
        )
        self.assertTrue(result.ram_eligible)
        self.assertTrue(timings.cache_stored)
        self.assertGreaterEqual(timings.cache_hashes, 1)
        self.assertTrue(self.session.thumbnail_publication_current(result))
        self.assertEqual(self.body, self.cache.read(
            self.cache.key(self.issuer, 4, 7, True),
            gallery_key=self.cache.gallery_key(self.issuer, 4),
        ).body)
        private_photo = self.private_photo()
        private = self.session.prepare_thumbnail_batch_item(4, private_photo)
        result = self.session.finish_thumbnail_batch_item(private, self.item)
        self.assertTrue(result.ram_eligible)
        self.assertEqual(self.body, self.cache.read(
            self.cache.private_key(self.issuer, 13, 4, 7, True),
        ).body)

    def test_mismatched_and_missing_wire_validators_invalidate_proof(self) -> None:
        """Refuse NULL or changed response validators without cache writes.

        Returns:
            None: Exact old public/private proof is removed for refresh.
        """
        for private in (False, True):
            for missing in (False, True):
                with self.subTest(private=private, missing=missing):
                    self.session._scope = linking.GALLERIES_SCOPE
                    self.session._public_media_descriptors[4] = {7: (self.etag, self.at)}
                    photo = self.private_photo() if private else self.photo
                    witness = self.session.prepare_thumbnail_batch_item(4, photo)
                    other = b'different valid body'
                    digest = hashlib.sha256(other).hexdigest()
                    item = replace(self.item, data=other, sha256=digest,
                                   etag=None if missing else '"' + digest + '"')
                    with mock.patch.object(self.cache, 'write') as write:
                        with self.assertRaises(linking.LinkingError):
                            self.session.finish_thumbnail_batch_item(witness, item)
                        write.assert_not_called()
                    if private:
                        self.assertNotIn(7, self.session._private_preload_proofs[4][2])
                    else:
                        self.assertNotIn(7, self.session._public_media_descriptors[4])

    def test_proof_changes_during_write_prevent_publication(self) -> None:
        """Recheck row, account and generation after real cache publication.

        Returns:
            None: A concurrent proof change cannot produce a reusable result.
        """
        for change in ('public_row', 'private_account', 'private_generation'):
            with self.subTest(change=change):
                self.session._scope = linking.GALLERIES_SCOPE
                self.session._public_media_descriptors[4] = {7: (self.etag, self.at)}
                photo = self.private_photo() if change.startswith('private') else self.photo
                witness = self.session.prepare_thumbnail_batch_item(4, photo)
                original = self.cache.write

                def write_and_change(
                    key: str, body: bytes, mime: str, etag: str, *,
                    gallery_key: str | None = None,
                ) -> bool:
                    """Write once, then simulate a proof replacement before return.

                    Args:
                        key (str): Existing cache identity.
                        body (bytes): Verified image body.
                        mime (str): Accepted image media type.
                        etag (str): Strong body validator.
                        gallery_key (str | None): Optional retained-gallery key.

                    Returns:
                        bool: The underlying cache publication outcome.
                    """
                    stored = original(key, body, mime, etag, gallery_key=gallery_key)
                    if change == 'public_row':
                        self.session._public_media_descriptors[4][7] = ('"' + 'f' * 64 + '"', self.at)
                    elif change == 'private_account':
                        self.session._bound_user_id = 14
                    else:
                        self.session._private_preload_generation += 1
                    return stored

                with mock.patch.object(self.cache, 'write', side_effect=write_and_change):
                    with self.assertRaises(linking.LinkingError):
                        self.session.finish_thumbnail_batch_item(witness, self.item)

    def test_missing_proof_and_cancellation_never_gain_authority(self) -> None:
        """Refuse absent rows and keep unvalidated responses transient only.

        Returns:
            None: Digest, cache refusal and cancellation create no new authority.
        """
        self.session._public_media_descriptors.clear()
        with self.assertRaises(linking.LinkingError):
            self.session.prepare_thumbnail_batch_item(4, self.photo)
        self.session._public_media_descriptors[4] = {7: (None, self.at)}
        photo = replace(self.photo, thumbnail_etag=None)
        witness = self.session.prepare_thumbnail_batch_item(4, photo)
        with mock.patch.object(self.cache, 'write') as write:
            result = self.session.finish_thumbnail_batch_item(witness, self.item)
            self.assertFalse(result.ram_eligible)
            write.assert_not_called()
        cancelled = threading.Event()
        cancelled.set()
        self.assertFalse(self.session.thumbnail_publication_current(result, cancelled))
        with self.assertRaises(linking.LinkingError):
            self.session.finish_thumbnail_batch_item(witness, self.item, cancelled)
        unavailable = replace(
            self.item, status='unavailable', data=b'', sha256=None, etag=None,
            mime='application/octet-stream',
        )
        result = self.session.finish_thumbnail_batch_item(witness, unavailable)
        self.assertIsNone(result.body)
        self.assertFalse(result.ram_eligible)
        private = self.private_photo()
        self.session._bound_user_id = None
        with self.assertRaises(linking.LinkingError):
            self.session.prepare_thumbnail_batch_item(4, private)
        self.session._bound_user_id = 13
        with self.assertRaises(linking.LinkingError):
            self.session.prepare_thumbnail_batch_item(4, replace(private, nsfw=True))

    def test_replaced_public_row_is_not_invalidated_by_old_result(self) -> None:
        """Preserve newer catalogue proof when an older request completes.

        Returns:
            None: The obsolete item fails and cannot remove the replacement.
        """
        witness = self.session.prepare_thumbnail_batch_item(4, self.photo)
        replacement = ('"' + 'f' * 64 + '"', self.at)
        self.session._public_media_descriptors[4][7] = replacement
        with self.assertRaises(linking.LinkingError):
            self.session.finish_thumbnail_batch_item(witness, self.item)
        self.assertEqual(replacement, self.session._public_media_descriptors[4][7])

    def test_witness_cannot_cross_sessions_with_identical_rows(self) -> None:
        """Keep a proof bound to the exact session that captured it.

        Returns:
            None: A second same-issuer session rejects both witness and result.
        """
        witness = self.session.prepare_thumbnail_batch_item(4, self.photo)
        publication = self.session.finish_thumbnail_batch_item(witness, self.item)
        other = linking.LinkingSession(
            self.issuer, 'pgla_' + 'B' * 42 + 'A',
            time.monotonic() + TEST_SESSION_LIFETIME_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        self.addCleanup(other.close)
        other._public_media_descriptors[4] = {7: (self.etag, self.at)}
        self.assertFalse(other.thumbnail_publication_current(publication))
        with mock.patch.object(self.cache, 'write') as write:
            with self.assertRaises(linking.LinkingError):
                other.finish_thumbnail_batch_item(witness, self.item)
            write.assert_not_called()

    def test_unproven_legacy_result_requires_live_admin_scope(self) -> None:
        """Refuse a restricted transient result after an Admin scope downgrade.

        Returns:
            None: The same issuer and live base grant cannot display the result.
        """
        photo = self.private_photo()
        restricted = replace(photo, nsfw=True, preload_eligible=False)
        result = linking.LegacyThumbnailPublication(
            self.session, self.issuer, 4, restricted, 'ok', self.body,
            'image/jpeg', None, None, self.session._private_preload_generation,
        )
        self.assertTrue(self.session.thumbnail_publication_current(result))
        self.session._scope = linking.GALLERIES_SCOPE
        self.assertFalse(self.session.thumbnail_publication_current(result))

    def test_expired_row_and_bad_digest_cannot_publish(self) -> None:
        """Reject expired catalogue proof and altered transport integrity.

        Returns:
            None: Neither failure reaches disk or establishes RAM authority.
        """
        stale = self.at - linking.PHOTO_CACHE_FRESH_SECONDS
        photo = replace(self.photo, thumbnail_verified_at=stale)
        self.session._public_media_descriptors[4] = {7: (self.etag, stale)}
        with self.assertRaises(linking.LinkingError):
            self.session.prepare_thumbnail_batch_item(4, photo)
        self.session._public_media_descriptors[4] = {7: (self.etag, self.at)}
        witness = self.session.prepare_thumbnail_batch_item(4, self.photo)
        with mock.patch.object(self.cache, 'write') as write:
            with self.assertRaises(linking.LinkingError):
                self.session.finish_thumbnail_batch_item(
                    witness, replace(self.item, data=b'altered'),
                )
            write.assert_not_called()

    def test_batch_selection_preserves_unverified_original_single_path(self) -> None:
        """Keep a current validator-free public row on its original GET path.

        Returns:
            None: Strong proof selects batch; stale proof cannot select GET.
        """
        self.assertTrue(self.session.thumbnail_batch_preferred(4, self.photo))
        self.assertFalse(self.session.supports_thumbnail_batch())
        unverified = replace(self.photo, thumbnail_etag=None)
        self.session._public_media_descriptors[4] = {7: (None, self.at)}
        self.assertFalse(self.session.thumbnail_batch_preferred(4, unverified))
        self.session._public_media_descriptors[4] = {}
        with self.assertRaises(linking.LinkingError):
            self.session.thumbnail_batch_preferred(4, unverified)

    def test_refused_cache_write_keeps_only_independent_ram_authority(self) -> None:
        """Do not confuse a refused disk write with new cache permission.

        Returns:
            None: Current independent proof still permits RAM; no disk is claimed.
        """
        witness = self.session.prepare_thumbnail_batch_item(4, self.photo)
        timings = linking.MediaReadTimings()
        with mock.patch.object(self.cache, 'write', return_value=False):
            result = self.session.finish_thumbnail_batch_item(
                witness, self.item, timings=timings,
            )
        self.assertTrue(result.ram_eligible)
        self.assertFalse(timings.cache_stored)
        self.session.close()
        self.assertFalse(self.session.thumbnail_publication_current(result))

    def test_session_group_uses_authorized_cache_before_network(self) -> None:
        """Deliver a verified local thumbnail without starting batch transport.

        Returns:
            None: One cache result is published and no HTTP attempt occurs.
        """
        witness = self.session.prepare_thumbnail_batch_item(4, self.photo)
        self.session.finish_thumbnail_batch_item(witness, self.item)
        self.session._thumbnail_batch_supported = True
        stats = linking.ThumbnailBatchStats()
        results: list[linking.ThumbnailPublication | linking.LegacyThumbnailPublication] = []

        def collect(
            result: linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
            timings: linking.MediaReadTimings, check_active: Callable[[], None],
        ) -> None:
            """Capture one result only while its operation remains active.

            Args:
                result (linking.ThumbnailPublication | linking.LegacyThumbnailPublication):
                    Independently authorized thumbnail.
                timings (linking.MediaReadTimings): Per-item cache observations.
                check_active (Callable[[], None]): Deadline and cancellation fence.

            Returns:
                None: Appends the currently authorized result.
            """
            check_active()
            self.assertEqual('disk', timings.source)
            results.append(result)

        with mock.patch.object(self.session, '_thumbnail_batch_attempt') as network:
            self.session.media_thumbnails(4, (self.photo,), collect, stats=stats)
            network.assert_not_called()
        self.assertEqual(1, stats.cache_hits)
        self.assertEqual(1, stats.returned)
        self.assertEqual(self.body, results[0].body)

    def test_unsupported_route_falls_back_without_cache_publication(self) -> None:
        """Disable an explicit unsupported route and deliver one transient GET.

        Returns:
            None: The actual single response is not persisted or RAM eligible.
        """
        self.session._thumbnail_batch_supported = True
        results: list[linking.ThumbnailPublication | linking.LegacyThumbnailPublication] = []

        def collect(
            result: linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
            timings: linking.MediaReadTimings, check_active: Callable[[], None],
        ) -> None:
            """Capture one transient legacy response under its deadline.

            Args:
                result (linking.ThumbnailPublication | linking.LegacyThumbnailPublication):
                    Validated response without inferred cache authority.
                timings (linking.MediaReadTimings): Per-item observations.
                check_active (Callable[[], None]): Deadline and cancellation fence.

            Returns:
                None: Records the result and checks its source.
            """
            check_active()
            self.assertEqual('network', timings.source)
            results.append(result)

        with mock.patch.object(
            self.session, '_thumbnail_batch_attempt',
            side_effect=linking.LinkingThumbnailBatchUnsupported(),
        ), mock.patch.object(
            self.session, '_media_read',
            side_effect=lambda *args, **kwargs: (
                kwargs['proof_generation_out'].append(
                    self.session._private_preload_generation,
                ) or (self.body, 'image/jpeg', self.etag, True)
            ),
        ) as single, mock.patch.object(self.cache, 'write') as write:
            self.session.media_thumbnails(4, (self.photo,), collect)
            single.assert_called_once()
            write.assert_not_called()
        self.assertFalse(self.session.supports_thumbnail_batch())
        self.assertFalse(results[0].ram_eligible)

    def test_throttle_remains_active_across_calls(self) -> None:
        """Retain the full server delay without requesting legacy images.

        Returns:
            None: A later call defers without another batch or single request.
        """
        self.session._thumbnail_batch_supported = True
        throttle = linking.LinkingMediaThrottled(
            TEST_RETRY_AFTER_SECONDS, route='app_linking_thumbnail_batch',
        )
        callback = mock.Mock()
        with mock.patch.object(self.session, '_thumbnail_batch_attempt',
                               side_effect=throttle) as batch, \
                mock.patch.object(self.session, '_media_read') as single:
            with self.assertRaises(linking.LinkingMediaThrottled):
                self.session.media_thumbnails(4, (self.photo,), callback)
            with self.assertRaises(linking.LinkingThumbnailBatchDeferred):
                self.session.media_thumbnails(4, (self.photo,), callback)
            self.assertEqual(1, batch.call_count)
            single.assert_not_called()
            callback.assert_not_called()

    def test_legacy_read_refuses_expired_operation_deadline(self) -> None:
        """Refuse an expired group deadline before acquiring the media gate.

        Returns:
            None: No request is admitted after the caller's deadline.
        """
        with mock.patch.object(self.session, '_request_gate') as gate:
            with self.assertRaises(linking.LinkingError):
                self.session._media_read(
                    4, 'thumbnail', 7, None,
                    operation_deadline=time.monotonic(),
                )
            gate.acquire.assert_not_called()

    def test_proof_change_survives_transport_worker(self) -> None:
        """Preserve a fixed proof failure across the transport worker boundary.

        Returns:
            None: A proof change is not relabeled as a network timeout.
        """
        def fail(control: linking._LinkingTransportControl) -> dict[str, linking.JsonValue]:
            """Raise one safe proof refusal from the admitted worker.

            Args:
                control (linking._LinkingTransportControl): Active request owner.

            Returns:
                dict[str, linking.JsonValue]: No result because proof was refused.
            """
            control.remaining()
            raise linking.LinkingThumbnailProofChanged('Proof changed.')

        with self.assertRaises(linking.LinkingThumbnailProofChanged):
            linking._run_linking_transport(
                fail, None, TEST_SESSION_LIFETIME_SECONDS,
                linking.LINKING_SOCKET_TIMEOUT_SECONDS,
                phase='media', route='app_linking_thumbnail_batch',
            )
