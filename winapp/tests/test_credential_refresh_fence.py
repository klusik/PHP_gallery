# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_credential_refresh_fence.py
# Module Type: Regression Test
# Purpose: Check the local refresh fence without invoking Windows APIs.
# Responsibilities:
#   - Retain a pending fence after ambiguous refresh outcomes.
#   - Permit replacement only after verifying a distinct rotated credential.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise the refresh protocol with in-memory vault and mutex doubles."""

import os
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from types import TracebackType
from unittest.mock import MagicMock, patch

from uploader import credential_store as credentials


class _Vault:
    """Provide a predictable credential vault double with ordered events."""

    def __init__(self) -> None:
        """Prepare empty records and an operation history.

        Returns:
            None: Initializes the vault double.
        """
        self.records = {}
        self.events = []

    def read(self, target: str) -> bytes | None:
        """Read one record from the test vault.

        Args:
            target (str): Versioned test target.

        Returns:
            bytes | None: Stored record or None if absent.
        """
        self.events.append(('read', target))
        return self.records.get(target)

    def write(self, target: str, value: bytes) -> None:
        """Replace one record in the test vault.

        Args:
            target (str): Versioned test target.
            value (bytes): Encoded bound record.

        Returns:
            None: Records the replacement.
        """
        self.events.append(('write', target))
        self.records[target] = value

    def delete(self, target: str) -> bool:
        """Delete one record from the test vault.

        Args:
            target (str): Versioned test target.

        Returns:
            bool: True if the record existed, otherwise False.
        """
        self.events.append(('delete', target))
        return self.records.pop(target, None) is not None


class _Mutex:
    """Stand in for the native mutex without exercising Windows functions."""

    def __init__(self, issuer: str, account_id: int) -> None:
        """Accept the binding used by the owner under test.

        Args:
            issuer (str): Canonical gallery issuer.
            account_id (int): Gallery Admin identifier.

        Returns:
            None: Prepares a mutex double.
        """
        credentials.credential_target_name(issuer, account_id)

    def __enter__(self) -> '_Mutex':
        """Enter the test lock.

        Returns:
            _Mutex: This mutex double.
        """
        return self

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Leave the test lock and preserve any exception.

        Args:
            exc_type (type | None): Exception class from the protected block.
            exc_value (BaseException | None): Exception raised by the block.
            traceback (TracebackType | None): Associated traceback, when present.

        Returns:
            bool: False so that exceptions propagate.
        """
        return False

    def check_identity(self) -> None:
        """Accept the simulated unchanged effective identity.

        Returns:
            None: The test identity is unchanged.
        """


class CredentialRefreshFenceTests(unittest.TestCase):
    """Verify conservative ordering and isolation of the refresh fence."""

    def setUp(self) -> None:
        """Prepare a fake vault and replace only the platform mutex owner.

        Returns:
            None: A store using the fake vault is ready for each test.
        """
        self.patch = patch.object(credentials, '_WindowsCredentialMutex', _Mutex)
        self.patch.start()
        self.addCleanup(self.patch.stop)
        self.store = credentials.WindowsCredentialStore.__new__(
            credentials.WindowsCredentialStore,
        )
        self.vault = _Vault()
        self.store._api = self.vault
        self.issuer = 'https://gallery.example/Galerie'
        self.account = 12
        self.target = credentials.credential_target_name(self.issuer, self.account)
        self.marker = self.target + credentials._PENDING_SUFFIX
        self.store.write(self.issuer, self.account, b'old')
        self.vault.events.clear()

    def test_lost_response_keeps_marker_and_blocks_old_credential(self) -> None:
        """Leave an ambiguous rotation fenced across later store instances.

        Returns:
            None: Normal access and a new rotation both reject the marker.
        """
        with self.assertRaisesRegex(RuntimeError, 'lost'):
            with self.store.refresh_transaction(self.issuer, self.account) as tx:
                self.assertEqual(b'old', tx.read_current())
                tx.begin_rotation()
                self.assertIn(self.marker, self.vault.records)
                raise RuntimeError('lost')
        with self.assertRaises(credentials.CredentialStoreError):
            self.store.read(self.issuer, self.account)
        with self.assertRaises(credentials.CredentialStoreError):
            self.store.write(self.issuer, self.account, b'old')
        with self.assertRaises(credentials.CredentialStoreError):
            with self.store.refresh_transaction(self.issuer, self.account):
                pass

    def test_changed_credential_refuses_before_setting_fence(self) -> None:
        """Refuse a stale read if another owner replaced the stored record.

        Returns:
            None: No pending marker is written for the stale credential.
        """
        with self.store.refresh_transaction(self.issuer, self.account) as tx:
            self.assertEqual(b'old', tx.read_current())
            self.vault.records[self.target] = credentials._encode_record(
                self.issuer, self.account, b'newer',
            )
            with self.assertRaises(credentials.CredentialStoreError):
                tx.begin_rotation()
            self.assertNotIn(self.marker, self.vault.records)

    def test_verified_replacement_precedes_marker_deletion(self) -> None:
        """Clear the fence only after reading back the new bound value.

        Returns:
            None: The rotated credential becomes available in the vault.
        """
        with self.store.refresh_transaction(self.issuer, self.account) as tx:
            self.assertEqual(b'old', tx.read_current())
            tx.begin_rotation()
            tx.commit_rotated(b'new')
        self.assertEqual(b'new', self.store.read(self.issuer, self.account))
        self.assertNotIn(self.marker, self.vault.records)
        write_index = self.vault.events.index(('write', self.target))
        delete_index = self.vault.events.index(('delete', self.marker))
        self.assertIn(('read', self.target),
                      self.vault.events[write_index + 1:delete_index])

    def test_unchanged_replacement_keeps_marker(self) -> None:
        """Reject an apparent rotation that returns the old credential.

        Returns:
            None: The unresolved marker continues blocking ordinary reads.
        """
        with self.assertRaises(credentials.CredentialStoreError):
            with self.store.refresh_transaction(self.issuer, self.account) as tx:
                tx.read_current()
                tx.begin_rotation()
                tx.commit_rotated(b'old')
        self.assertIn(self.marker, self.vault.records)
        with self.assertRaises(credentials.CredentialStoreError):
            self.store.read(self.issuer, self.account)

    def test_replacement_write_failure_keeps_fence(self) -> None:
        """Prevent replay when saving a server response fails.

        Returns:
            None: A failed replacement leaves the marker and blocks reads.
        """
        with self.assertRaises(credentials.CredentialStoreError):
            with self.store.refresh_transaction(self.issuer, self.account) as tx:
                tx.read_current()
                tx.begin_rotation()
                with patch.object(
                    self.vault, 'write',
                    side_effect=credentials.CredentialStoreError('failed'),
                ):
                    tx.commit_rotated(b'new')
        self.assertIn(self.marker, self.vault.records)
        with self.assertRaises(credentials.CredentialStoreError):
            self.store.read(self.issuer, self.account)

    def test_unrelated_account_is_not_blocked(self) -> None:
        """Keep the pending fence specific to one issuer and account.

        Returns:
            None: An unrelated account retains access to its own record.
        """
        with self.store.refresh_transaction(self.issuer, self.account) as tx:
            tx.read_current()
            tx.begin_rotation()
        self.store.write(self.issuer, 13, b'other')
        self.assertEqual(b'other', self.store.read(self.issuer, 13))

    def test_explicit_discard_removes_credential_before_marker(self) -> None:
        """Permit new authorization only after the old credential is absent.

        Returns:
            None: The marker is deleted after credential deletion and readback.
        """
        with self.store.refresh_transaction(self.issuer, self.account) as tx:
            tx.read_current()
            tx.begin_rotation()
        self.vault.events.clear()
        self.assertTrue(self.store.discard_pending_refresh(self.issuer, self.account))
        delete_credential = self.vault.events.index(('delete', self.target))
        delete_marker = self.vault.events.index(('delete', self.marker))
        self.assertLess(delete_credential, delete_marker)
        self.assertIn(('read', self.target),
                      self.vault.events[delete_credential + 1:delete_marker])
        self.assertIsNone(self.store.read(self.issuer, self.account))

    def test_failed_discard_keeps_marker(self) -> None:
        """Keep the fence when deleting the old credential fails.

        Returns:
            None: Ordinary reads remain blocked after the failed operation.
        """
        with self.store.refresh_transaction(self.issuer, self.account) as tx:
            tx.read_current()
            tx.begin_rotation()
        with patch.object(self.vault, 'delete',
                          side_effect=credentials.CredentialStoreError('failed')):
            with self.assertRaises(credentials.CredentialStoreError):
                self.store.discard_pending_refresh(self.issuer, self.account)
        self.assertIn(self.marker, self.vault.records)
        with self.assertRaises(credentials.CredentialStoreError):
            self.store.read(self.issuer, self.account)


class CredentialProcessMutexTests(unittest.TestCase):
    """Exercise process-local reentrancy and native cleanup with doubles."""

    def test_same_binding_is_not_reentrant(self) -> None:
        """Reject a nested binding but permit another SID or account.

        Returns:
            None: All successfully acquired process keys are released.
        """
        first = credentials._enter_process_mutex(b'sid-one', 'target-one')
        others = []
        try:
            with self.assertRaises(credentials.CredentialStoreError):
                credentials._enter_process_mutex(b'sid-one', 'target-one')
            others.append(credentials._enter_process_mutex(b'sid-two', 'target-one'))
            others.append(credentials._enter_process_mutex(b'sid-one', 'target-two'))
        finally:
            for key in others:
                credentials._leave_process_mutex(key)
            credentials._leave_process_mutex(first)
        again = credentials._enter_process_mutex(b'sid-one', 'target-one')
        credentials._leave_process_mutex(again)

    def test_native_owner_rejects_nested_entry(self) -> None:
        """Reject reentry even when the simulated native mutex is reentrant.

        Returns:
            None: The owner is released and the second object may then enter.
        """
        first = credentials._WindowsCredentialMutex.__new__(
            credentials._WindowsCredentialMutex,
        )
        second = credentials._WindowsCredentialMutex.__new__(
            credentials._WindowsCredentialMutex,
        )
        for mutex in (first, second):
            mutex._target = 'same-target'
            mutex._handle = None
            mutex._thread_id = None
            mutex._sid = None
            mutex._process_key = None
            mutex._kernel = MagicMock()
            mutex._kernel.CreateMutexW.return_value = 42
            mutex._kernel.WaitForSingleObject.return_value = credentials._WAIT_OBJECT_0
            mutex._kernel.ReleaseMutex.return_value = 1
            mutex._kernel.CloseHandle.return_value = 1
        with patch.object(credentials._WindowsCredentialMutex, '_current_sid',
                          return_value=b'test-sid'):
            with first:
                with self.assertRaises(credentials.CredentialStoreError):
                    second.__enter__()
                second._kernel.CreateMutexW.assert_not_called()
            with second:
                pass

    def test_abandoned_mutex_releases_process_guard(self) -> None:
        """Release the fake abandoned native mutex before permitting reuse.

        Returns:
            None: Cleanup succeeds and the same binding can be entered again.
        """
        mutex = credentials._WindowsCredentialMutex.__new__(
            credentials._WindowsCredentialMutex,
        )
        mutex._target = 'test-target'
        mutex._handle = None
        mutex._thread_id = None
        mutex._sid = None
        mutex._process_key = None
        mutex._kernel = MagicMock()
        mutex._kernel.CreateMutexW.return_value = 42
        mutex._kernel.WaitForSingleObject.return_value = credentials._WAIT_ABANDONED
        mutex._kernel.ReleaseMutex.return_value = 1
        mutex._kernel.CloseHandle.return_value = 1
        with patch.object(mutex, '_current_sid', return_value=b'test-sid'):
            with self.assertRaises(credentials.CredentialStoreError):
                mutex.__enter__()
        mutex._kernel.ReleaseMutex.assert_called_once_with(42)
        mutex._kernel.CloseHandle.assert_called_once_with(42)
        key = credentials._enter_process_mutex(b'test-sid', 'test-target')
        credentials._leave_process_mutex(key)



class KeychainRefreshFenceTests(unittest.TestCase):
    """Exercise the native profile lock with an isolated Keychain boundary."""

    @unittest.skipUnless(os.name == 'posix', 'Keychain adapter uses POSIX flock.')
    def test_pending_marker_survives_lock_release_and_store_reopen(self) -> None:
        """Refuse concurrent rotation and block the old record after failure.

        Returns:
            None: Separate owners share one lock and a persistent vault fence.
        """
        issuer = 'http://localhost:8888/Galerie'
        account = 12
        vault = _Vault()
        with TemporaryDirectory() as directory, (
            patch.object(credentials, '_KeychainCredentialApi', return_value=vault)
        ):
            first = credentials.KeychainCredentialStore(Path(directory))
            reopened = credentials.KeychainCredentialStore(Path(directory))
            first.write(issuer, account, b'old')
            with first.refresh_transaction(issuer, account) as transaction:
                self.assertEqual(b'old', transaction.read_current())
                transaction.begin_rotation()
                with self.assertRaises(credentials.CredentialStoreError):
                    reopened.read(issuer, account)
            with self.assertRaises(credentials.CredentialStoreError):
                reopened.read(issuer, account)
            self.assertTrue(reopened.discard_pending_refresh(issuer, account))
            self.assertIsNone(first.read(issuer, account))


if __name__ == '__main__':
    unittest.main()
