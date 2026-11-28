# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_loopback_linked_switch_fixture.py
# Module Type: Hosted Regression Test
# Purpose: Exercise a real rolling browser link against an isolated local authority.
# Responsibilities:
#   - Simulate explicit consent and PKCE on direct localhost HTTP only.
#   - Verify the linked identity and selected upload gallery through production APIs.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Hosted loopback fixture for native linked backend-switch journeys."""

from pathlib import Path
import socket
import sys
import threading
from tempfile import TemporaryDirectory
from threading import RLock
import time
from types import TracebackType
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import linking as app_linking
from qualify_loopback_authority import (
    FIXTURE_ADMIN_ID, FIXTURE_CALLBACK_WAIT_SECONDS, FIXTURE_GALLERY_ID,
    FIXTURE_OPERATION_TIMEOUT_SECONDS, LoopbackLinkingAuthority,
)
from uploader.config_snapshot import ConfigSnapshot
from uploader.desktop_connection import persist_browser_upload_grant
from uploader.linked_upload import verify_linked_upload_target
from uploader.mode_coordinator import ModeCoordinator
from uploader.offline_linking import (
    load_offline_binding, offline_binding_path, restore_saved_offline_session,
)
from uploader.upload_mode import ModeRecord

class _MemoryCredentialTransaction:
    """Model the credential owner's fenced refresh transaction in memory."""

    def __init__(self, store: '_MemoryCredentialStore') -> None:
        """Bind a rotation transaction to its isolated credential store.

        Args:
            store (_MemoryCredentialStore): In-memory native-store substitute.
        Returns:
            None: Prepares transaction state without touching the host vault.
        """
        self.store = store

    def __enter__(self) -> '_MemoryCredentialTransaction':
        """Acquire the fixture's per-process credential lock.

        Returns:
            _MemoryCredentialTransaction: Entered fixture transaction.
        """
        self.store.lock.acquire()
        if self.store.pending:
            self.store.lock.release()
            raise RuntimeError('Fixture credential rotation is already pending.')
        return self

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Release the fixture lock without clearing uncertain rotation state.

        Args:
            exc_type (type[BaseException] | None): Protected exception type.
            exc_value (BaseException | None): Protected exception instance.
            traceback (TracebackType | None): Protected exception traceback.
        Returns:
            bool: False so transaction failures remain visible.
        """
        self.store.lock.release()
        return False

    def read_current(self) -> bytes | None:
        """Read the current opaque refresh record under its lock.

        Returns:
            bytes | None: Stored record or positively absent credential.
        """
        return self.store.record

    def begin_rotation(self) -> None:
        """Mark the current credential uncertain before local network exchange.

        Returns:
            None: Sets the in-memory pending fence.
        """
        self.store.pending = True

    def commit_rotated(self, payload: bytes) -> None:
        """Replace the refresh record and clear its fence after verification.

        Args:
            payload (bytes): Newly validated packed refresh token.
        Returns:
            None: Commits the new value and clears pending state.
        """
        self.store.record = payload
        self.store.pending = False


class _MemoryCredentialStore:
    """Provide the native-store interface without persisting test credentials."""

    def __init__(self) -> None:
        """Create an empty isolated store for this one test.

        Returns:
            None: Initializes a memory-only credential record and lock.
        """
        self.record: bytes | None = None
        self.pending = False
        self.lock = RLock()

    def binding_transaction(
        self, issuer: str, account_id: int,
    ) -> '_MemoryBindingTransaction':
        """Provide the shared binding transaction context for local fixture work.

        Args:
            issuer (str): Exact fixture issuer used by the production call.
            account_id (int): Exact fixture identity used by the production call.
        Returns:
            _MemoryBindingTransaction: Unentered serialized binding lease.
        """
        return _MemoryBindingTransaction(self)

    def write(self, _issuer: str, _account_id: int, payload: bytes) -> None:
        """Store an opaque approved credential only in this process.

        Args:
            _issuer (str): Exact fixture issuer.
            _account_id (int): Exact fixture identity.
            payload (bytes): Packed refresh token from production validation.
        Returns:
            None: Replaces the memory-only credential.
        """
        self.record = payload

    def read(self, _issuer: str, _account_id: int) -> bytes | None:
        """Read the fixture credential through the production store interface.

        Args:
            _issuer (str): Exact fixture issuer.
            _account_id (int): Exact fixture identity.
        Returns:
            bytes | None: Stored record or confirmed absence.
        """
        return self.record

    def refresh_transaction(
        self, _issuer: str, _account_id: int,
    ) -> _MemoryCredentialTransaction:
        """Create one lock-owning in-memory refresh transaction.

        Args:
            _issuer (str): Exact fixture issuer.
            _account_id (int): Exact fixture identity.
        Returns:
            _MemoryCredentialTransaction: Unentered serialized transaction.
        """
        return _MemoryCredentialTransaction(self)

    def discard_pending_refresh(self, _issuer: str, _account_id: int) -> bool:
        """Clear only a fixture pending fence when explicitly requested.

        Args:
            _issuer (str): Exact fixture issuer.
            _account_id (int): Exact fixture identity.
        Returns:
            bool: Whether an uncertain memory-only credential was removed.
        """
        if not self.pending:
            return False
        self.record = None
        self.pending = False
        return True


class _MemoryBindingTransaction:
    """Serialize one test credential binding without native storage APIs."""

    def __init__(self, store: _MemoryCredentialStore) -> None:
        """Bind the metadata lease to the fixture's in-memory lock.

        Args:
            store (_MemoryCredentialStore): Fixture credential owner.
        Returns:
            None: Prepares an unentered binding lease.
        """
        self.store = store

    def __enter__(self) -> None:
        """Acquire the fixture lock before checking or writing the binding.

        Returns:
            None: The fixture lease is active on this thread.
        """
        self.store.lock.acquire()

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Release the binding lease without suppressing failures.

        Args:
            exc_type (type[BaseException] | None): Protected exception type.
            exc_value (BaseException | None): Protected exception instance.
            traceback (TracebackType | None): Protected exception traceback.
        Returns:
            bool: False so errors remain visible.
        """
        self.store.lock.release()
        return False


class _ModeConfigPort:
    """Supply deterministic ConfigStore snapshots to the production coordinator."""

    def __init__(self) -> None:
        """Create a valid synthetic profile with an absent mode anchor.

        Returns:
            None: Initializes stable owner-bound configuration evidence.
        """
        self.config_path = r'C:\Users\Fixture\config.json'
        self.owner = object()
        self.parent = object()
        self.anchor: bytes | None = None
        self.generation = 0

    def mode_transaction(self) -> '_ModeConfigPort':
        """Return this isolated owner as its transaction context.

        Returns:
            _ModeConfigPort: Context manager for the same fixture owner.
        """
        return self

    def __enter__(self) -> '_ModeConfigPort':
        """Enter the synthetic ConfigStore transaction.

        Returns:
            _ModeConfigPort: Entered fixture owner.
        """
        return self

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Leave without masking any coordinator failure.

        Args:
            exc_type (type[BaseException] | None): Protected exception type.
            exc_value (BaseException | None): Protected exception instance.
            traceback (TracebackType | None): Protected exception traceback.
        Returns:
            bool: False so failures are retained.
        """
        return False

    def snapshot(self) -> ConfigSnapshot:
        """Return complete immutable evidence for the current fixture bytes.

        Returns:
            ConfigSnapshot: Valid profile snapshot bound to its owner and parent.
        """
        content = b'{"fixture":' + str(self.generation).encode('ascii') + b'}'
        identity = (1, 2, len(content), self.generation)
        return ConfigSnapshot(
            'valid', self.owner, self.parent, identity, content,
        )

    def read_mode_anchor(self, snapshot: ConfigSnapshot) -> bytes | None:
        """Read the exact anchor only from the current complete snapshot.

        Args:
            snapshot (ConfigSnapshot): Expected owner-issued evidence.
        Returns:
            bytes | None: Current mode anchor or confirmed absence.
        """
        if snapshot != self.snapshot():
            raise ValueError('The fixture config snapshot changed.')
        return self.anchor

    def write_mode_anchor(
        self, snapshot: ConfigSnapshot, payload: bytes,
    ) -> ConfigSnapshot:
        """Compare and update only the mode anchor for coordinator publication.

        Args:
            snapshot (ConfigSnapshot): Expected complete prior snapshot.
            payload (bytes): Canonical mode record written by the owner.
        Returns:
            ConfigSnapshot: New fixture snapshot after the anchor update.
        """
        if snapshot != self.snapshot():
            raise ValueError('The fixture config snapshot changed.')
        self.anchor = payload
        self.generation += 1
        return self.snapshot()


class _MemoryModeVault:
    """Supply the mode-record vault protocol to the real coordinator."""

    def __init__(self) -> None:
        """Initialize a confirmed-empty mode vault.

        Returns:
            None: Prepares the separate non-secret mode owner.
        """
        self.record: ModeRecord | None = None

    def transaction(self) -> '_MemoryModeVault':
        """Return this test owner as its mode-vault context.

        Returns:
            _MemoryModeVault: Context manager for one coordinator transaction.
        """
        return self

    def __enter__(self) -> '_MemoryModeVault':
        """Enter the synthetic mode vault transaction.

        Returns:
            _MemoryModeVault: Entered fixture owner.
        """
        return self

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Leave without masking coordinator failures.

        Args:
            exc_type (type[BaseException] | None): Protected exception type.
            exc_value (BaseException | None): Protected exception instance.
            traceback (TracebackType | None): Protected exception traceback.
        Returns:
            bool: False so failures are retained.
        """
        return False

    def read(self) -> ModeRecord | None:
        """Read the current mode record or confirmed absence.

        Returns:
            ModeRecord | None: Non-secret current upload mode evidence.
        """
        return self.record

    def write(self, record: ModeRecord) -> None:
        """Publish the exact mode record to this independent fixture owner.

        Args:
            record (ModeRecord): Canonical selected mode.
        Returns:
            None: Stores the non-secret mode record.
        """
        self.record = record


class LoopbackLinkedSwitchFixtureTests(unittest.TestCase):
    """Exercise linked consent, secure-owner ports, and target verification."""

    def test_explicit_rolling_consent_verifies_identity_and_target(self) -> None:
        """Persist consent, admit mode, rotate once, and reverify the target.

        Returns:
            None: The exact temporary authority and target are verified once.
        """
        authority = LoopbackLinkingAuthority()
        self.addCleanup(authority.close)
        attempt = app_linking.LinkingAttempt(
            authority.issuer, scope=app_linking.UPLOAD_SCOPE,
        )
        self.addCleanup(attempt.close)
        native_getaddrinfo = socket.getaddrinfo

        def resolve_fixture_host(
            host: str | bytes, port: int | str | None,
            family: int = 0, type: int = 0, proto: int = 0, flags: int = 0,
        ) -> list[tuple[int, int, int, str, tuple[str, int]]]:
            """Constrain only this authority's localhost resolution to IPv4.

            Args:
                host (str | bytes): Requested resolver host.
                port (int | str | None): Requested authority port.
                family (int): Requested address family.
                type (int): Requested socket type.
                proto (int): Requested protocol.
                flags (int): Requested resolver flags.
            Returns:
                list[tuple[int, int, int, str, tuple[str, int]]]: Fixture-only
                    IPv4 answer, or untouched native answers for other targets.
            """
            if host == 'localhost' and port == authority.server.server_port:
                return native_getaddrinfo(
                    '127.0.0.1', port, family, type, proto, flags,
                )
            return native_getaddrinfo(host, port, family, type, proto, flags)

        with TemporaryDirectory(prefix='gallery-linked-switch-') as temporary:
            profile = Path(temporary) / 'PHPGalleryUploader'
            profile.mkdir()
            credential_store = _MemoryCredentialStore()
            session = None
            restored = None
            try:
                with mock.patch.object(
                    socket, 'getaddrinfo', side_effect=resolve_fixture_host,
                ):
                    handoff = attempt.push_request(prefer_rolling=True)
                    consent_thread = threading.Thread(
                        target=authority.grant_consent,
                        name='LoopbackConsentCallback', daemon=True,
                    )
                    consent_thread.start()
                    attempt._deadline = time.monotonic() + FIXTURE_CALLBACK_WAIT_SECONDS
                    self.assertEqual('approved', attempt.wait_for_callback())
                    consent_thread.join(timeout=FIXTURE_OPERATION_TIMEOUT_SECONDS)
                    self.assertFalse(consent_thread.is_alive())
                    session, refresh, lifetime = attempt.exchange_offline_once()
                    identity = session.identity()
                    target = verify_linked_upload_target(
                        session, authority.issuer, identity.user_id,
                        session.approved_scope(), FIXTURE_GALLERY_ID,
                    )
                    persist_browser_upload_grant(
                        profile, credential_store, authority.issuer, identity,
                        session, refresh, lifetime, threading.Event(),
                    )
                    initial_refresh_record = credential_store.record
                    hint_path = offline_binding_path(
                        profile / 'offline-link.json', authority.issuer,
                    )
                    binding = load_offline_binding(hint_path, authority.issuer)
                    self.assertIsNotNone(binding)
                    self.assertEqual(identity.user_id, binding.account_id)
                    self.assertEqual(session.approved_scope(), binding.scope)

                    config_port = _ModeConfigPort()
                    mode_owner = ModeCoordinator(config_port, _MemoryModeVault())
                    active = mode_owner.select_linked(mode_owner.refresh(), target)
                    self.assertEqual('linked_active', active.state)
                    self.assertEqual(authority.issuer, active.record.issuer)
                    self.assertEqual(identity.user_id, active.record.account_id)
                    self.assertEqual(FIXTURE_GALLERY_ID, active.record.gallery_id)

                    restored_result = restore_saved_offline_session(
                        credential_store, hint_path, binding, threading.Event(),
                    )
                    self.assertIsNotNone(restored_result)
                    restored, restored_identity = restored_result
                    refreshed_target = verify_linked_upload_target(
                        restored, authority.issuer, restored_identity.user_id,
                        restored.approved_scope(), FIXTURE_GALLERY_ID,
                    )
                self.assertEqual(authority.issuer, handoff.authorization_url.split(
                    '/index.php?', 1,
                )[0])
                self.assertEqual(1, authority.token_exchange_count)
                self.assertEqual(1, authority.refresh_exchange_count)
                self.assertNotEqual(initial_refresh_record, credential_store.record)
                self.assertEqual(FIXTURE_ADMIN_ID, identity.user_id)
                self.assertEqual(FIXTURE_GALLERY_ID, target.gallery_id)
                self.assertEqual('Qualification target', target.title)
                self.assertEqual(FIXTURE_GALLERY_ID, refreshed_target.gallery_id)
                self.assertEqual('Qualification target', refreshed_target.title)
                self.assertEqual(identity.user_id, restored_identity.user_id)
                self.assertTrue(session.approved_scope().endswith(
                    app_linking.ROLLING_SCOPE_SUFFIX,
                ))
                self.assertTrue(app_linking._issuer_valid(
                    authority.issuer, allow_local_http=True,
                ))
                self.assertTrue(credential_store.record)
                current_mode = mode_owner.view()
                self.assertEqual(FIXTURE_GALLERY_ID, current_mode.record.gallery_id)
            finally:
                if restored is not None:
                    restored.close()
                if session is not None:
                    session.close()


if __name__ == '__main__':
    unittest.main()
