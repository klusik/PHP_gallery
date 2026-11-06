# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_offline_linking.py
# Module Type: Regression Test
# Purpose: Exercise explicit offline rotation without Windows or network APIs.
# Responsibilities:
#   - Verify fencing, exact account and scope before credential replacement.
#   - Retain the fence after ambiguous, invalid and failed rotation outcomes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Check offline credential transitions using a vault and transport double."""

import base64
import tempfile
import time
from pathlib import Path
import unittest
from types import TracebackType
from unittest.mock import MagicMock, patch
from urllib.parse import parse_qs

from uploader import app_linking as linking
from uploader import credential_store as credentials
from uploader import offline_linking as offline


def _token(prefix: str, byte: bytes) -> str:
    """Build one deterministic canonical test credential.

    Args:
        prefix (str): Credential type prefix.
        byte (bytes): One fixture byte repeated for the protocol's 32-byte value.

    Returns:
        str: Canonical URL-safe test credential.
    """
    return prefix + base64.urlsafe_b64encode(byte * 32).rstrip(b'=').decode('ascii')


class _Transaction:
    """Model the vault owner's conservative refresh fence without native APIs."""

    def __init__(self, store: '_Store') -> None:
        """Bind a transaction to its isolated vault double.

        Args:
            store (_Store): Test owner containing the credential and marker.

        Returns:
            None: Prepares the transaction.
        """
        self.store = store

    def __enter__(self) -> '_Transaction':
        """Enter only when no prior uncertain rotation remains.

        Returns:
            _Transaction: Active simulated transaction.

        Raises:
            RuntimeError: A previous refresh is still pending.
        """
        if self.store.pending:
            raise RuntimeError('pending')
        self.store.events.append('enter')
        return self

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Exit without clearing a pending marker after an exception.

        Args:
            exc_type (type[BaseException] | None): Protected exception type.
            exc_value (BaseException | None): Protected exception instance.
            traceback (TracebackType | None): Protected exception traceback.

        Returns:
            bool: False, preserving the protected exception.
        """
        self.store.events.append('exit')
        return False

    def read_current(self) -> bytes | None:
        """Read the local record only inside the held transaction.

        Returns:
            bytes | None: Current bound credential record or absence.
        """
        self.store.events.append('read')
        return self.store.record

    def begin_rotation(self) -> None:
        """Persist the simulated pending fence before network activity.

        Returns:
            None: Marks the credential pending.
        """
        self.store.events.append('begin')
        self.store.pending = True

    def commit_rotated(self, payload: bytes) -> None:
        """Commit the replacement only when the fixture allows storage.

        Args:
            payload (bytes): Versioned new credential record.

        Returns:
            None: The replacement is stored and pending is cleared.

        Raises:
            RuntimeError: The simulated storage outcome is uncertain.
        """
        self.store.events.append('commit')
        if self.store.fail_commit:
            raise RuntimeError('private simulated storage failure')
        self.store.record = payload
        self.store.pending = False


class _Store:
    """Provide a minimal isolated vault double for the offline workflow."""

    def __init__(self) -> None:
        """Prepare an empty vault and operation sequence.

        Returns:
            None: Initializes per-test storage state.
        """
        self.record = None
        self.pending = False
        self.fail_commit = False
        self.events = []

    def write(self, issuer: str, account_id: int, payload: bytes) -> None:
        """Save a test record through the approved initial-write boundary.

        Args:
            issuer (str): Trusted test issuer.
            account_id (int): Bound fixture Admin ID.
            payload (bytes): Versioned credential record.

        Returns:
            None: Stores the test value.
        """
        self.events.append('write')
        self.record = payload

    def refresh_transaction(self, issuer: str, account_id: int) -> _Transaction:
        """Create a transaction for the fixture issuer and account.

        Args:
            issuer (str): Trusted test issuer.
            account_id (int): Bound fixture Admin ID.

        Returns:
            _Transaction: Fenced transaction double.
        """
        return _Transaction(self)

    def discard_pending_refresh(self, issuer: str, account_id: int) -> bool:
        """Discard local pending state without asserting server revocation.

        Args:
            issuer (str): Trusted test issuer.
            account_id (int): Bound fixture Admin ID.

        Returns:
            bool: True if a pending local record was removed.
        """
        if not self.pending:
            return False
        self.record = None
        self.pending = False
        return True


class OfflineLinkingTests(unittest.TestCase):
    """Exercise validated, one-shot offline refresh sequencing."""

    def setUp(self) -> None:
        """Prepare one explicitly approved test connection.

        Returns:
            None: A deterministic bound credential is ready in a fake vault.
        """
        self.store = _Store()
        self.issuer = 'https://gallery.example/Galerie'
        self.account = 19
        self.scope = offline.IDENTITY_SCOPE + ' offline_access'
        self.old = _token('pglr_', b'a')
        self.new = _token('pglr_', b'b')
        self.access = _token('pgla_', b'c')
        offline.store_approved_refresh(
            self.store, self.issuer, self.account, self.scope, self.old, 100,
        )
        self.store.events.clear()
        self.response = {
            'access_token': self.access, 'token_type': 'Bearer',
            'expires_in': 100, 'scope': self.scope,
            'refresh_token': self.new, 'refresh_expires_in': 100,
        }
        self.identity = {'user': {'id': self.account, 'username': 'admin'},
                         'scope': self.scope}

    def _rotate(self) -> offline.OfflineAccess:
        """Run one refresh using the exact fixture binding.

        Returns:
            offline.OfflineAccess: Verified bearer on successful completion.
        """
        return offline.rotate_offline_access(
            self.store, self.issuer, self.account, self.scope,
        )

    def test_identity_is_verified_before_storing_and_exposing_access(self) -> None:
        """Retain the pending marker through both exact HTTPS operations.

        Returns:
            None: Only a verified replacement yields a redacted bearer result.
        """
        def request(issuer: str, route: str, method: str, body: bytes | None,
                    bearer: str | None, **kwargs: object) -> dict[str, object]:
            """Check request binding while the fixture fence is held.

            Args:
                issuer (str): Exact expected trusted issuer.
                route (str): Fixed token or identity route.
                method (str): Expected HTTPS request method.
                body (bytes | None): Form-encoded token body or absent GET body.
                bearer (str | None): Identity bearer, absent on token request.
                **kwargs (threading.Event | None): Optional cancelled event
                    passed to the transport.

            Returns:
                dict[str, object]: Token response with access_token, token_type,
                    expires_in, scope, refresh_token and refresh_expires_in, or
                    identity response with scope and user id and username.
            """
            self.assertTrue(self.store.pending)
            self.assertEqual(self.issuer, issuer)
            self.store.events.append(route)
            if route == 'app_linking_token':
                self.assertEqual('POST', method)
                self.assertIsNone(bearer)
                self.assertEqual({
                    'grant_type': ['refresh_token'], 'refresh_token': [self.old],
                    'client_id': [offline.CLIENT_ID],
                }, parse_qs(body.decode('ascii')))
                return self.response
            self.assertEqual('app_linking_identity', route)
            self.assertEqual('GET', method)
            self.assertEqual(self.access, bearer)
            return self.identity

        with patch.object(offline, '_request_json', side_effect=request) as send:
            result = self._rotate()
        self.assertEqual(2, send.call_count)
        self.assertEqual(['enter', 'read', 'begin', 'app_linking_token',
                          'app_linking_identity', 'commit', 'exit'], self.store.events)
        self.assertFalse(self.store.pending)
        self.assertEqual(self.access, result.token)
        self.assertNotIn(self.access, repr(result))
        self.assertEqual(self.new, offline._unpack_refresh(self.store.record, self.scope))

    def test_lost_response_never_retries_or_releases_access(self) -> None:
        """Fence an ambiguous network outcome across subsequent calls.

        Returns:
            None: The old credential cannot trigger a second network request.
        """
        with patch.object(offline, '_request_json', side_effect=TimeoutError('secret')) as send:
            with self.assertRaises(offline.OfflineLinkingError) as caught:
                self._rotate()
            self.assertNotIn('secret', str(caught.exception))
            with self.assertRaises(offline.OfflineLinkingError):
                self._rotate()
        self.assertEqual(1, send.call_count)
        self.assertTrue(self.store.pending)
        self.assertTrue(offline.discard_uncertain_refresh(
            self.store, self.issuer, self.account,
        ))
        self.assertIsNone(self.store.record)

    def test_invalid_responses_and_identity_leave_fence(self) -> None:
        """Reject scope, expiry, credential and account mismatches.

        Returns:
            None: Every failure retains the fence and does not commit.
        """
        cases = (
            ({**self.response, 'scope': offline.IDENTITY_SCOPE}, self.identity),
            ({**self.response, 'expires_in': True}, self.identity),
            ({**self.response, 'refresh_expires_in': offline.OFFLINE_REFRESH_MAX_SECONDS + 1},
             self.identity),
            ({**self.response, 'refresh_token': self.old}, self.identity),
            (self.response, {'user': {'id': self.account + 1, 'username': 'admin'},
                             'scope': self.scope}),
            (self.response, {'user': {'id': self.account, 'username': 'admin'},
                             'scope': offline.IDENTITY_SCOPE}),
        )
        initial = self.store.record
        for response, identity in cases:
            with self.subTest(response_keys=tuple(response), identity=identity):
                self.store.record = initial
                self.store.pending = False
                self.store.events.clear()
                with patch.object(offline, '_request_json', side_effect=[response, identity]):
                    with self.assertRaises(offline.OfflineLinkingError):
                        self._rotate()
                self.assertTrue(self.store.pending)
                self.assertNotIn('commit', self.store.events)

    def test_identity_transport_failure_keeps_fence(self) -> None:
        """Never commit a replacement when live identity cannot be checked.

        Returns:
            None: The response failure leaves the original credential fenced.
        """
        with patch.object(offline, '_request_json',
                          side_effect=[self.response, TimeoutError('private')]) as send:
            with self.assertRaises(offline.OfflineLinkingError):
                self._rotate()
        self.assertEqual(2, send.call_count)
        self.assertTrue(self.store.pending)
        self.assertNotIn('commit', self.store.events)

    def test_initial_requires_offline_scope_and_bounded_lifetime(self) -> None:
        """Refuse implicit offline permission or impossible server lifetimes.

        Returns:
            None: No invalid initial credential is written to the vault.
        """
        self.store.events.clear()
        for scope, lifetime in ((offline.IDENTITY_SCOPE, 100),
                                (self.scope, True),
                                (self.scope, offline.OFFLINE_REFRESH_MAX_SECONDS + 1)):
            with self.subTest(scope=scope, lifetime=lifetime):
                with self.assertRaises(offline.OfflineLinkingError):
                    offline.store_approved_refresh(
                        self.store, self.issuer, self.account, scope, self.old, lifetime,
                    )
        self.assertNotIn('write', self.store.events)

    def test_saved_binding_is_bounded_and_never_overwrites(self) -> None:
        """Publish only identity hints and preserve an existing separate link.

        Returns:
            None: Replacement and wrong-issuer loads are refused.
        """
        binding = offline.OfflineBinding(self.issuer, self.account, self.scope)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'offline-link.json'
            offline.save_offline_binding(path, binding)
            self.assertEqual(binding, offline.load_offline_binding(path, self.issuer))
            saved = path.read_text(encoding='utf-8')
            self.assertNotIn(self.old, saved)
            self.assertNotIn(self.access, saved)
            with self.assertRaises(offline.OfflineLinkingError):
                offline.save_offline_binding(
                    path, offline.OfflineBinding(self.issuer, self.account + 1, self.scope),
                )
            self.assertEqual(binding, offline.load_offline_binding(path, self.issuer))
            with self.assertRaises(offline.OfflineLinkingError):
                offline.load_offline_binding(path, 'https://other.example')
            offline.remove_offline_binding(path, binding)
            self.assertIsNone(offline.load_offline_binding(path, self.issuer))

    def test_initial_offline_exchange_preserves_exact_scope(self) -> None:
        """Accept refresh fields only on an explicitly offline code exchange.

        Returns:
            None: The initial bearer retains the server's full scope.
        """
        listener = MagicMock()
        listener.getsockname.return_value = ('127.0.0.1', 56321)
        with patch.object(linking.socket, 'socket', return_value=listener):
            attempt = linking.LinkingAttempt(self.issuer, scope=self.scope)
        self.addCleanup(attempt.close)
        attempt._phase = 'approved'
        attempt._code = _token('pglc_', b'd')
        with patch.object(linking, '_request_json', return_value=self.response):
            session, refresh, lifetime = attempt.exchange_offline_once()
        self.addCleanup(session.close)
        self.assertEqual(self.new, refresh)
        self.assertEqual(100, lifetime)
        self.assertTrue(session.available())
        self.assertFalse(session.can_list_galleries())
        self.assertFalse(session.can_upload())
        with patch.object(linking, '_request_json', return_value=self.identity):
            self.assertEqual(self.account, session.identity().user_id)

    def test_offline_upload_session_keeps_base_authority(self) -> None:
        """Allow only the upload and listing actions present in the base scope.

        Returns:
            None: Exact upload scope works; malformed offline scopes fail.
        """
        session = linking.LinkingSession(
            self.issuer, self.access, time.monotonic() + 100,
            linking.UPLOAD_OFFLINE_SCOPE,
        )
        self.addCleanup(session.close)
        self.assertTrue(session.can_list_galleries())
        self.assertTrue(session.can_upload())
        with self.assertRaises(linking.LinkingError):
            linking.LinkingSession(
                self.issuer, self.access, time.monotonic() + 100,
                linking.IDENTITY_SCOPE + ' offline_access gallery:upload',
            )

    def test_metadata_mutex_uses_distinct_owner_namespace(self) -> None:
        """Request the owner's shared metadata mutex before vault operations.

        Returns:
            None: The metadata flag reaches the native mutex owner.
        """
        store = credentials.WindowsCredentialStore.__new__(
            credentials.WindowsCredentialStore,
        )
        with patch.object(credentials, '_WindowsCredentialMutex') as mutex:
            store.binding_transaction(self.issuer, self.account)
        mutex.assert_called_once_with(self.issuer, self.account, metadata=True)

    def test_storage_failure_never_exposes_new_access(self) -> None:
        """Retain the fence when replacement storage has uncertain outcome.

        Returns:
            None: The caller receives an error and the pending marker remains.
        """
        self.store.fail_commit = True
        with patch.object(offline, '_request_json', side_effect=[self.response, self.identity]):
            with self.assertRaises(offline.OfflineLinkingError):
                self._rotate()
        self.assertTrue(self.store.pending)


if __name__ == '__main__':
    unittest.main()
