# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_config_snapshot.py
# Module Type: Regression Test
# Purpose: Exercise the unintegrated private configuration snapshot owner.
# Responsibilities:
#   - Preserve unrelated fields and refuse stale or ambiguous evidence.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable source fixtures; no Windows API or live configuration is used."""

import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from uploader.app_linking import UPLOAD_SCOPE
from uploader.config_snapshot import (
    CONFIG_MODE_ANCHOR_FIELD, ConfigSnapshotError, ConfigSnapshotStore,
)
from uploader.upload_mode import ModeRecord, encode_mode_record, profile_path_digest


class ConfigSnapshotTests(unittest.TestCase):
    """Check the isolated owner without integrating it into the uploader."""

    def test_mode_anchor_is_strict_and_preserves_unrelated_bytes(self) -> None:
        """Publish one validated anchor while retaining private config fields.

        Returns:
            None: Checks stale and malformed mode evidence without data loss.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            original = b'{"api_key":"pgu_fixture","future":{"n":0.1234567890123456789}}'
            path.write_bytes(original)
            store = ConfigSnapshotStore(path, frozenset({'api_key'}))
            before = store.inspect()
            self.assertIsNone(store.read_mode_anchor(before))
            record = ModeRecord(
                'a' * 32, 'b' * 32, 'linked',
                profile_path_digest(r'C:\Users\Tester\config.json'),
                'https://gallery.example', 7, UPLOAD_SCOPE, 9,
            )
            payload = encode_mode_record(record)
            after = store.save_mode_anchor(before, payload)
            self.assertEqual(payload, store.read_mode_anchor(after))
            self.assertIn(b'"api_key":"pgu_fixture"', path.read_bytes())
            self.assertIn(b'"future":{"n":0.1234567890123456789}', path.read_bytes())
            with self.assertRaises(ConfigSnapshotError):
                store.save_mode_anchor(before, payload)
            with self.assertRaises(ConfigSnapshotError):
                store.save(after, {CONFIG_MODE_ANCHOR_FIELD: None})
            with self.assertRaises(ConfigSnapshotError):
                store.save_mode_anchor(after, b'null')
            self.assertEqual(payload, store.read_mode_anchor(after))

    def test_malformed_anchor_is_not_treated_as_missing(self) -> None:
        """Refuse a present null or malformed anchor without rewriting it.

        Returns:
            None: Each invalid value remains unchanged and unavailable.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            store = ConfigSnapshotStore(path, frozenset({'api_key'}))
            record = ModeRecord(
                'a' * 32, 'b' * 32, 'legacy',
                profile_path_digest(r'C:\Users\Tester\config.json'),
            )
            for value in (b'null', b'{}', b'"legacy"'):
                original = b'{"api_key":"pgu_fixture","upload_mode_v2":' + value + b'}'
                path.write_bytes(original)
                with self.subTest(value=value):
                    snapshot = store.inspect()
                    self.assertEqual('valid', snapshot.state)
                    with self.assertRaises(ConfigSnapshotError):
                        store.read_mode_anchor(snapshot)
                    with self.assertRaises(ConfigSnapshotError):
                        store.save_mode_anchor(snapshot, encode_mode_record(record))
                    self.assertEqual(original, path.read_bytes())

    def test_preserves_legacy_and_unknown_fields_across_updates(self) -> None:
        """Retain the API key and opaque metadata while changing known fields.

        Returns:
            None: Verifies a legacy document and two successive snapshot writes.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            original = {'gallery_url': 'http://localhost:8888/Galerie',
                        'api_key': 'pgu_fixture', 'opaque_mode': {'v': 1},
                        'future': ['keep', 17]}
            path.write_text(json.dumps(original), encoding='utf-8')
            store = ConfigSnapshotStore(path, frozenset({'gallery_url', 'api_key'}))
            before = store.inspect()
            self.assertEqual('valid', before.state)
            self.assertNotIn('pgu_fixture', repr(before))
            after = store.save(before, {'gallery_url': 'https://gallery.example'})
            final = store.save(after, {'gallery_url': 'https://gallery.example/new'})
            document = store.read(final)
            self.assertEqual('pgu_fixture', document['api_key'])
            self.assertEqual({'v': 1}, document['opaque_mode'])
            self.assertEqual(['keep', 17], document['future'])
            self.assertEqual('https://gallery.example/new', document['gallery_url'])

    def test_missing_and_unavailable_are_distinct(self) -> None:
        """Do not turn malformed, ambiguous or inaccessible input into absence.

        Returns:
            None: Covers missing parent, invalid JSON and conflicting keys.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            store = ConfigSnapshotStore(path, frozenset({'api_key'}))
            self.assertEqual('missing', store.inspect().state)
            absent_parent = ConfigSnapshotStore(path / 'missing', frozenset())
            self.assertEqual('unavailable', absent_parent.inspect().state)
            for payload in ('{', '[]', '{"a":1,"a":2}',
                            '{"x":NaN}', '{"x":Infinity}', '{"x":1e999}'):
                with self.subTest(payload=payload):
                    path.write_text(payload, encoding='utf-8')
                    evidence = store.inspect()
                    self.assertEqual('unavailable', evidence.state)
                    with self.assertRaises(ConfigSnapshotError):
                        store.save(evidence, {'api_key': 'pgu_fixture'})
                    self.assertEqual(payload, path.read_text(encoding='utf-8'))

    def test_stale_content_identity_and_unowned_fields_are_refused(self) -> None:
        """Do not overwrite a newer file or alter an unmanaged metadata key.

        Returns:
            None: Confirms stale and unsupported updates preserve the file.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            path.write_text('{"api_key":"first","anchor":{"x":1}}', encoding='utf-8')
            store = ConfigSnapshotStore(path, frozenset({'api_key'}))
            snapshot = store.inspect()
            with self.assertRaises(ConfigSnapshotError):
                store.save(snapshot, {'anchor': None})
            replacement = Path(directory) / 'replacement.json'
            replacement.write_bytes(path.read_bytes())
            replacement.replace(path)
            with self.assertRaises(ConfigSnapshotError):
                store.save(snapshot, {'api_key': 'second'})
            self.assertEqual('first', json.loads(path.read_text())['api_key'])
            refreshed = store.inspect()
            path.write_text('{"api_key":"other","anchor":{"x":1}}', encoding='utf-8')
            with self.assertRaises(ConfigSnapshotError):
                store.save(refreshed, {'api_key': 'second'})
            self.assertEqual('other', json.loads(path.read_text())['api_key'])

    def test_stage_failure_keeps_original_file(self) -> None:
        """Retain original bytes when a staged write cannot be synchronized.

        Returns:
            None: Checks the original file after the isolated injected error.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            original = b'{"api_key":"pgu_fixture"}'
            path.write_bytes(original)
            store = ConfigSnapshotStore(path, frozenset({'api_key'}))
            snapshot = store.inspect()
            with mock.patch('uploader.config_snapshot.os.fsync', side_effect=OSError):
                with self.assertRaises(ConfigSnapshotError):
                    store.save(snapshot, {'api_key': 'new'})
            self.assertEqual(original, path.read_bytes())

    def test_serialized_duplicate_keys_cannot_replace_config(self) -> None:
        """Refuse a nested key collision before creating any replacement.

        Returns:
            None: Leaves the complete original configuration unchanged.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            original = b'{"api_key":"pgu_fixture"}'
            path.write_bytes(original)
            store = ConfigSnapshotStore(path, frozenset({'api_key'}))
            snapshot = store.inspect()
            with self.assertRaises(ConfigSnapshotError):
                store.save(snapshot, {'api_key': {1: 'a', '1': 'b'}})
            self.assertEqual(original, path.read_bytes())

    def test_unverifiable_identity_and_links_are_unavailable(self) -> None:
        """Do not accept unverifiable identity or redirected final components.

        Returns:
            None: Checks unknown identity and a symbolic link where supported.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            original = b'{"api_key":"pgu_fixture"}'
            path.write_bytes(original)
            store = ConfigSnapshotStore(path, frozenset({'api_key'}))
            with mock.patch('uploader.config_snapshot._identifiable', return_value=False):
                self.assertEqual('unavailable', store.inspect().state)
            link = Path(directory) / 'alias.json'
            try:
                link.symlink_to(path)
            except (OSError, NotImplementedError):
                self.skipTest('Symbolic links are unavailable in this fixture.')
            alias_store = ConfigSnapshotStore(link, frozenset({'api_key'}))
            self.assertEqual('unavailable', alias_store.inspect().state)
            self.assertEqual(original, path.read_bytes())

    def test_hardlinked_target_is_unavailable(self) -> None:
        """Refuse an inode shared with a second directory entry.

        Returns:
            None: Leaves the original and its hardlink unchanged.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            original = b'{"api_key":"pgu_fixture"}'
            path.write_bytes(original)
            alias = Path(directory) / 'hardlink.json'
            try:
                os.link(path, alias)
            except (OSError, NotImplementedError):
                self.skipTest('Hardlinks are unavailable in this fixture.')
            store = ConfigSnapshotStore(path, frozenset({'api_key'}))
            self.assertEqual('unavailable', store.inspect().state)
            self.assertEqual(original, path.read_bytes())
            self.assertEqual(original, alias.read_bytes())

    def test_unowned_raw_values_keep_numeric_precision_and_escaping(self) -> None:
        """Preserve opaque values without a lossy JSON decode and re-encode.

        Returns:
            None: The untouched nested JSON value remains byte-for-byte equal.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            value = b'{"number":0.12345678901234567890123456789,"text":"\\u0041"}'
            path.write_bytes(b' \n {"api_key":"original","opaque":' + value + b'} ')
            store = ConfigSnapshotStore(path, frozenset({'api_key'}))
            snapshot = store.inspect()
            updated = store.save(snapshot, {'api_key': 'new'})
            self.assertEqual('valid', updated.state)
            self.assertIn(b'"opaque":' + value, path.read_bytes())
