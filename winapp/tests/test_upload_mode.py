# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_upload_mode.py
# Module Type: Regression Test
# Purpose: Verify unintegrated restart-mode evidence without Windows APIs.
# Responsibilities:
#   - Reject damaged, missing and conflicting independent records.
#   - Preserve fail-closed publication after interrupted transitions.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise mode policy with isolated in-memory storage doubles."""

import unittest
from unittest.mock import MagicMock, Mock, patch

from uploader import credential_store
from uploader import upload_mode
from uploader.app_linking import (
    READ_UPLOAD_CREATE_SCOPE, READ_UPLOAD_SCOPE, UPLOAD_CREATE_SCOPE,
    UPLOAD_SCOPE,
)


class _Vault:
    """Model one entered mode vault without invoking any Windows API."""

    def __init__(self, record: upload_mode.ModeRecord | None = None) -> None:
        """Start with a selected record or confirmed absence.

        Args:
            record (upload_mode.ModeRecord | None): Initial mode evidence.

        Returns:
            None: Creates an isolated in-memory owner.
        """
        self.record = record

    def read(self) -> upload_mode.ModeRecord | None:
        """Read the current exact mode evidence.

        Returns:
            upload_mode.ModeRecord | None: Current record or confirmed absence.
        """
        return self.record

    def write(self, record: upload_mode.ModeRecord) -> None:
        """Publish the selected mode record in this isolated double.

        Args:
            record (upload_mode.ModeRecord): Explicitly selected mode.

        Returns:
            None: Retains the exact record for readback.
        """
        self.record = record


class UploadModeTests(unittest.TestCase):
    """Check strict bindings and two-owner publication semantics."""

    def setUp(self) -> None:
        """Prepare deterministic valid records for independent storage.

        Returns:
            None: Creates a linked and a legacy generation of one profile.
        """
        self.config_path = r'C:\Users\Tester\config.json'
        self.path_hash = upload_mode.profile_path_digest(self.config_path)
        self.linked = upload_mode.ModeRecord(
            'a' * 32, 'b' * 32, 'linked', self.path_hash, 'https://gallery.example',
            7, UPLOAD_SCOPE, 9,
        )
        self.legacy = upload_mode.ModeRecord('a' * 32, 'c' * 32, 'legacy', self.path_hash)

    def test_matching_records_and_legacy_migration(self) -> None:
        """Resolve matching records while keeping linked mode disconnected.

        Returns:
            None: Checks legacy migration and both stored mode variants.
        """
        self.assertEqual(
            upload_mode.resolve_mode(None, None, config_verified=True, config_path=self.config_path),
            ('legacy_unmigrated', None),
        )
        for record, state in ((self.legacy, 'legacy'),
                              (self.linked, 'linked_disconnected')):
            payload = upload_mode.encode_mode_record(record)
            self.assertEqual(upload_mode.decode_mode_record(payload), record)
            self.assertEqual(
                upload_mode.resolve_mode(payload, payload, config_verified=True, config_path=self.config_path),
                (state, record),
            )

    def test_missing_corrupt_or_mismatched_records_refuse(self) -> None:
        """Treat missing and malformed evidence as unavailable, never legacy.

        Returns:
            None: Verifies each independent evidence failure is rejected.
        """
        linked = upload_mode.encode_mode_record(self.linked)
        legacy = upload_mode.encode_mode_record(self.legacy)
        cases = ((None, None, False), (linked, linked, False), (linked, None, True),
                 (None, linked, True), (linked, legacy, True),
                 (b'{"mode":"legacy","mode":"linked"}', linked, True),
                 (b'{}', linked, True))
        for anchor, vault, valid in cases:
            with self.subTest(anchor=anchor, vault=vault, valid=valid):
                with self.assertRaises(upload_mode.UploadModeError):
                    upload_mode.resolve_mode(anchor, vault, config_verified=valid, config_path=self.config_path)

    def test_invalid_linked_binding_is_rejected(self) -> None:
        """Reject wrong issuer, account, scope, gallery and identity types.

        Returns:
            None: Covers malformed records without contacting a server.
        """
        invalid = (
            upload_mode.ModeRecord('a' * 32, 'b' * 32, 'linked', self.path_hash,
                                   'http://gallery.example', 7, UPLOAD_SCOPE, 9),
            upload_mode.ModeRecord('a' * 32, 'b' * 32, 'linked', self.path_hash,
                                   'https://gallery.example', True, UPLOAD_SCOPE, 9),
            upload_mode.ModeRecord('a' * 32, 'b' * 32, 'linked', self.path_hash,
                                   'https://gallery.example', 7, 'link:identity', 9),
            upload_mode.ModeRecord('a' * 32, 'b' * 32, 'linked', self.path_hash,
                                   'https://gallery.example', 7, UPLOAD_SCOPE, 0),
        )
        for record in invalid:
            with self.subTest(record=record):
                with self.assertRaises(upload_mode.UploadModeError):
                    upload_mode.encode_mode_record(record)

    def test_lexical_path_rejects_ambiguous_windows_forms(self) -> None:
        """Refuse relative, traversal, device, alternate-stream and alias syntax.

        Returns:
            None: Checks unsafe spellings without touching the filesystem.
        """
        invalid = (
            'relative\\config.json', r'C:config.json', r'C:\x\..\config.json',
            r'C:\x\.\config.json', r'C:\x\\config.json',
            r'C:\x\config.json:stream', r'C:\x\NUL.txt', r'C:\x\CON .txt',
            r'C:\x\config.json.', r'C:\x\config.json ',
            r'C:/x/config.json', r'\\server\share\config.json',
            r'\\?\C:\x\config.json',
        )
        for config_path in invalid:
            with self.subTest(config_path=config_path):
                with self.assertRaises(upload_mode.UploadModeError):
                    upload_mode.profile_path_digest(config_path)

    def test_existing_legacy_unc_path_needs_no_new_binding(self) -> None:
        """Preserve verified unmigrated legacy use without admitting binding.

        Returns:
            None: A UNC legacy profile resolves but cannot publish a mode.
        """
        unc_path = r'\\server\share\config.json'
        self.assertEqual(
            upload_mode.resolve_mode(
                None, None, config_verified=True, config_path=unc_path,
            ),
            ('legacy_unmigrated', None),
        )
        vault = _Vault()
        vault.write = Mock(wraps=vault.write)
        write_anchor = Mock()
        with self.assertRaises(upload_mode.UploadModeError):
            upload_mode.publish_mode(
                self.linked, vault, lambda: None, write_anchor,
                config_verified=True, expected_previous=None, config_path=unc_path,
            )
        vault.write.assert_not_called()
        write_anchor.assert_not_called()

    def test_other_config_path_cannot_use_or_overwrite_profile(self) -> None:
        """Refuse a copied anchor or different lexical profile before writing.

        Returns:
            None: Matching stored records still require the independent path.
        """
        other = r'C:\Users\Tester\other.json'
        equivalent_case_alias = r'C:\Users\tester\config.json'
        payload = upload_mode.encode_mode_record(self.linked)
        for config_path in (other, equivalent_case_alias):
            with self.subTest(config_path=config_path):
                with self.assertRaises(upload_mode.UploadModeError):
                    upload_mode.resolve_mode(
                        payload, payload, config_verified=True, config_path=config_path,
                    )
                vault = _Vault(self.linked)
                vault.write = Mock(wraps=vault.write)
                anchor_write = Mock()
                with self.assertRaises(upload_mode.UploadModeError):
                    upload_mode.publish_mode(
                        self.legacy, vault, lambda: payload, anchor_write,
                        config_verified=True, expected_previous=self.linked,
                        config_path=config_path,
                    )
                vault.write.assert_not_called()
                anchor_write.assert_not_called()

    def test_previous_record_version_is_not_silently_upgraded(self) -> None:
        """Treat old or malformed record shapes as unavailable evidence.

        Returns:
            None: Never infers a path binding from a prior version.
        """
        payload = upload_mode.encode_mode_record(self.linked)
        old_version = payload.replace(b'"version":2', b'"version":1')
        with self.assertRaises(upload_mode.UploadModeError):
            upload_mode.decode_mode_record(old_version)

    def test_all_existing_upload_bundles_remain_valid(self) -> None:
        """Accept each existing upload bundle and its offline variant.

        Returns:
            None: Verifies exact scope combinations through the shared policy.
        """
        for scope in (UPLOAD_SCOPE, READ_UPLOAD_SCOPE,
                      UPLOAD_CREATE_SCOPE, READ_UPLOAD_CREATE_SCOPE):
            for selected in (scope, scope + ' offline_access'):
                with self.subTest(scope=selected):
                    record = upload_mode.ModeRecord(
                        'a' * 32, 'b' * 32, 'linked', self.path_hash,
                        'https://gallery.example', 7, selected, 9,
                    )
                    self.assertEqual(
                        upload_mode.decode_mode_record(
                            upload_mode.encode_mode_record(record),
                        ), record,
                    )

    def test_publication_orders_vault_before_anchor(self) -> None:
        """Require readback of both new copies before reporting success.

        Returns:
            None: Checks publication from an untouched legacy configuration.
        """
        vault = _Vault()
        anchor: list[bytes | None] = [None]
        events: list[str] = []

        def read_anchor() -> bytes | None:
            """Read the current config anchor.

            Returns:
                bytes | None: Exact anchor or confirmed absence.
            """
            return anchor[0]

        def write_anchor(payload: bytes) -> None:
            """Publish after the new vault record was verified.

            Args:
                payload (bytes): Exact new config anchor bytes.

            Returns:
                None: Stores the payload in the isolated fixture.
            """
            self.assertEqual(vault.record, self.linked)
            events.append('anchor')
            anchor[0] = payload

        upload_mode.publish_mode(
            self.linked, vault, read_anchor, write_anchor, config_verified=True,
            expected_previous=None, config_path=self.config_path,
        )
        self.assertEqual(events, ['anchor'])
        self.assertEqual(
            upload_mode.resolve_mode(anchor[0], upload_mode.encode_mode_record(vault.record),
                                     config_verified=True, config_path=self.config_path),
            ('linked_disconnected', self.linked),
        )

    def test_interrupted_publication_does_not_imply_legacy(self) -> None:
        """Leave mismatched evidence unavailable when anchor writing fails.

        Returns:
            None: Checks the state after a vault-first partial transition.
        """
        old = upload_mode.encode_mode_record(self.legacy)
        vault = _Vault(self.legacy)
        with self.assertRaises(OSError):
            upload_mode.publish_mode(
                self.linked, vault, lambda: old,
                Mock(side_effect=OSError('fixture failure')), config_verified=True,
                expected_previous=self.legacy, config_path=self.config_path,
            )
        self.assertEqual(vault.record, self.linked)
        with self.assertRaises(upload_mode.UploadModeError):
            upload_mode.resolve_mode(
                old, upload_mode.encode_mode_record(self.linked), config_verified=True,
                config_path=self.config_path,
            )

    def test_stale_generation_or_other_profile_cannot_be_published(self) -> None:
        """Keep the existing copies when a stale or foreign update is proposed.

        Returns:
            None: Checks generation and profile binding before a vault write.
        """
        vault = _Vault(self.legacy)
        old = upload_mode.encode_mode_record(self.legacy)
        foreign = upload_mode.ModeRecord('d' * 32, 'e' * 32, 'legacy', self.path_hash)
        for proposed in (self.legacy, foreign):
            with self.subTest(proposed=proposed):
                write = Mock()
                with self.assertRaises(upload_mode.UploadModeError):
                    upload_mode.publish_mode(
                        proposed, vault, lambda: old, write, config_verified=True,
                        expected_previous=self.legacy, config_path=self.config_path,
                    )
                self.assertEqual(vault.record, self.legacy)
                write.assert_not_called()

    def test_vault_read_failure_never_becomes_absence(self) -> None:
        """Refuse publication when vault state cannot be established.

        Returns:
            None: Confirms failure does not write the config anchor.
        """
        vault = Mock()
        vault.read.side_effect = OSError('fixture unavailable')
        write = Mock()
        with self.assertRaises(OSError):
            upload_mode.publish_mode(
                self.linked, vault, lambda: None, write, config_verified=True,
                expected_previous=None, config_path=self.config_path,
            )
        vault.write.assert_not_called()
        write.assert_not_called()

    def test_reviewed_generation_is_required_before_any_write(self) -> None:
        """Refuse a stale user choice when another generation won the race.

        Returns:
            None: Leaves the newer vault and config anchor unchanged.
        """
        vault = _Vault(self.legacy)
        current = upload_mode.encode_mode_record(self.legacy)
        write = Mock()
        with self.assertRaises(upload_mode.UploadModeError):
            upload_mode.publish_mode(
                self.linked, vault, lambda: current, write, config_verified=True,
                expected_previous=None, config_path=self.config_path,
            )
        self.assertEqual(vault.record, self.legacy)
        write.assert_not_called()

    def test_nonempty_stale_generation_never_writes_vault(self) -> None:
        """Refuse a reviewed record after a different generation was committed.

        Returns:
            None: Neither vault nor config is written for the stale choice.
        """
        vault = _Vault(self.legacy)
        vault.write = Mock(wraps=vault.write)
        current = upload_mode.encode_mode_record(self.legacy)
        proposed = upload_mode.ModeRecord(
            'a' * 32, 'd' * 32, 'linked', self.path_hash, 'https://gallery.example',
            7, UPLOAD_SCOPE, 9,
        )
        write_anchor = Mock()
        with self.assertRaises(upload_mode.UploadModeError):
            upload_mode.publish_mode(
                proposed, vault, lambda: current, write_anchor,
                config_verified=True, expected_previous=self.linked,
                config_path=self.config_path,
            )
        self.assertEqual(vault.record, self.legacy)
        vault.write.assert_not_called()
        write_anchor.assert_not_called()

    def test_mode_vault_uses_separate_typed_target(self) -> None:
        """Keep mode metadata independent from refresh credential records.

        Returns:
            None: Verifies fixed target and readback using only native doubles.
        """
        api = Mock()
        mutex = MagicMock()
        mutex.__enter__ = Mock(return_value=mutex)
        mutex.__exit__ = Mock(return_value=False)
        api.read.return_value = upload_mode.encode_mode_record(self.linked)
        with patch.object(credential_store, '_WindowsCredentialApi', return_value=api), \
                patch.object(credential_store, '_WindowsCredentialMutex', return_value=mutex) as mutex_factory:
            store = credential_store.WindowsUploadModeStore()
            transaction = store.transaction()
            with self.assertRaises(credential_store.CredentialStoreError):
                transaction.read()
            with transaction:
                transaction.write(self.linked)
                self.assertEqual(transaction.read(), self.linked)
            mutex_factory.assert_called_once_with(None, None, metadata=True)
        api.write.assert_called_once_with(
            credential_store._UPLOAD_MODE_TARGET,
            upload_mode.encode_mode_record(self.linked),
        )
        self.assertNotIn('LinkedCredential', credential_store._UPLOAD_MODE_TARGET)

    def test_mode_vault_refuses_failed_readback(self) -> None:
        """A changed vault value cannot confirm a mode write.

        Returns:
            None: Rejects a mismatched native read without using Windows APIs.
        """
        api = Mock()
        api.read.return_value = upload_mode.encode_mode_record(self.legacy)
        mutex = MagicMock()
        mutex.__enter__.return_value = mutex
        mutex.__exit__.return_value = False
        with patch.object(credential_store, '_WindowsCredentialApi', return_value=api), \
                patch.object(credential_store, '_WindowsCredentialMutex', return_value=mutex):
            with credential_store.WindowsUploadModeStore().transaction() as transaction:
                with self.assertRaises(credential_store.CredentialStoreError):
                    transaction.write(self.linked)
