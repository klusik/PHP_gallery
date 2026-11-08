# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_catalogue_cache.py
# Module Type: Regression Test
# Purpose: Verify optional per-account catalogue snapshot persistence.
# Responsibilities:
#   - Preserve complete metadata, namespaces and local integrity.
#   - Refuse corrupt, redirected, cancelled and over-limit cache inputs.
#   - Retain the prior snapshot when atomic publication fails.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Catalogue persistence fixtures with owned temporary storage and no GUI."""

import hashlib
import os
from pathlib import Path
from tempfile import TemporaryDirectory
import sys
import threading
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.app_linking import LinkedGallery
from uploader import gallery_catalogue_cache as catalogue


class GalleryCatalogueCacheTests(unittest.TestCase):
    """Exercise the actual optional storage owner without linking or Tk."""

    def test_complete_snapshot_round_trip_and_binding_isolation(self) -> None:
        """Retain complete Unicode identities only in their exact account scope.

        Returns:
            None: Another account, issuer or scope cannot reuse the snapshot.
        """
        snapshot = catalogue.GalleryCatalogueSnapshot((
            LinkedGallery(1, None, 'Fixture červená'),
            LinkedGallery(2, 1, 'Nested fixture'),
        ), 'a' * 64)
        with TemporaryDirectory() as directory, mock.patch.dict(
            os.environ, {'LOCALAPPDATA': directory},
        ):
            cache = catalogue.GalleryCatalogueCache.for_current_user(
                'https://gallery.example', 7, 'link:identity galleries:list',
            )
            self.assertIsNone(cache.load())
            self.assertTrue(cache.save(snapshot))
            self.assertEqual(snapshot, cache.load())
            for issuer, user, scope in (
                ('https://other.example', 7, 'link:identity galleries:list'),
                ('https://gallery.example', 8, 'link:identity galleries:list'),
                ('https://gallery.example', 7, 'link:identity'),
            ):
                other = catalogue.GalleryCatalogueCache.for_current_user(
                    issuer, user, scope,
                )
                self.assertIsNone(other.load())
                # A copied file must not turn the other namespace into a hit.
                other._path.write_bytes(cache._path.read_bytes())
                if os.name != 'nt':
                    other._path.chmod(0o600)
                self.assertIsNone(other.load())
            if os.name == 'nt':
                self.assertNotIn(
                    snapshot.galleries[0].title.encode('utf-8'),
                    cache._path.read_bytes(),
                )

    def test_corrupt_and_redirected_snapshot_are_cache_misses(self) -> None:
        """Refuse damaged content and unsafe path observations without crashing.

        Returns:
            None: Corruption and redirection cannot produce a complete tree.
        """
        with TemporaryDirectory() as directory:
            cache = catalogue.GalleryCatalogueCache(
                Path(directory) / 'catalogue', hashlib.sha256(b'fixture').hexdigest(),
            )
            snapshot = catalogue.GalleryCatalogueSnapshot((
                LinkedGallery(1, None, 'Original fixture'),
            ))
            self.assertTrue(cache.save(snapshot))
            original = cache._path.read_bytes()
            cache._path.write_bytes(original[:-1] + bytes([original[-1] ^ 1]))
            self.assertIsNone(cache.load())
            cache._path.write_bytes(original)
            with mock.patch.object(
                catalogue.GalleryCatalogueCache, '_is_reparse', return_value=True,
            ):
                self.assertIsNone(cache.load())
                self.assertFalse(cache.save(snapshot))
            self.assertEqual(snapshot, cache.load())

    def test_failed_publication_retains_prior_complete_tree(self) -> None:
        """Keep the previous complete snapshot after a replacement failure.

        Returns:
            None: Failed preparation never leaves a partially updated tree.
        """
        prior = catalogue.GalleryCatalogueSnapshot((
            LinkedGallery(1, None, 'Prior fixture'),
        ), 'a' * 64)
        newer = catalogue.GalleryCatalogueSnapshot((
            LinkedGallery(1, None, 'New fixture'),
        ), 'b' * 64)
        with TemporaryDirectory() as directory:
            cache = catalogue.GalleryCatalogueCache(
                Path(directory) / 'catalogue', hashlib.sha256(b'fixture').hexdigest(),
            )
            self.assertTrue(cache.save(prior))
            with mock.patch.object(
                catalogue.os, 'replace', side_effect=OSError('fixture-only failure'),
            ):
                self.assertFalse(cache.save(newer))
            self.assertEqual(prior, cache.load())
            self.assertFalse(tuple(cache._directory.glob('*.tmp')))

    def test_cancellation_empty_catalogue_and_descriptor_bounds(self) -> None:
        """Distinguish a complete empty tree from cancellation and invalid rows.

        Returns:
            None: Cancellation and invalid order/title/revision retain prior data.
        """
        with TemporaryDirectory() as directory:
            cache = catalogue.GalleryCatalogueCache(
                Path(directory) / 'catalogue', hashlib.sha256(b'fixture').hexdigest(),
            )
            cancelled = threading.Event()
            cancelled.set()
            empty = catalogue.GalleryCatalogueSnapshot(())
            self.assertFalse(cache.save(empty, cancelled))
            self.assertIsNone(cache.load(cancelled))
            cancelled.clear()
            self.assertTrue(cache.save(empty))
            self.assertEqual(empty, cache.load())
            for invalid in (
                catalogue.GalleryCatalogueSnapshot((
                    LinkedGallery(2, None, 'Second'),
                    LinkedGallery(1, None, 'First'),
                )),
                catalogue.GalleryCatalogueSnapshot((
                    LinkedGallery(1, None, 'Duplicate'),
                    LinkedGallery(1, None, 'Duplicate'),
                )),
                catalogue.GalleryCatalogueSnapshot((
                    LinkedGallery(1, None, '\ud800'),
                )),
                catalogue.GalleryCatalogueSnapshot((), 'invalid revision'),
            ):
                self.assertFalse(cache.save(invalid))
                self.assertEqual(empty, cache.load())
            cache._path.write_bytes(b'x' * (catalogue.CATALOGUE_CACHE_MAX_BYTES + 1))
            self.assertIsNone(cache.load())


if __name__ == '__main__':
    unittest.main()
