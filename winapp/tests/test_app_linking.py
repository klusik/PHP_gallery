# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_app_linking.py
# Module Type: Regression Test
# Purpose: Verify versioned browser-approved linking without external traffic.
# Responsibilities:
#   - Exercise public discovery, HTTPS issuer, PKCE and callback boundaries.
#   - Verify explicit browser consent and one-shot authorization-code exchange.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Focused tests for the browser-approved linking transport."""

import base64
import hashlib
import http.client
import json
import queue
import socket
import sys
import threading
import time
import unittest
from collections.abc import Iterable
from email.message import Message
from pathlib import Path
from tempfile import TemporaryDirectory
from typing import Callable
from unittest import mock
from urllib.parse import parse_qs, urlencode

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import app_linking as linking
from uploader import offline_linking
from uploader.flight_report_translations import tr
from uploader.linked_create import BoundCreateReceipt, CreateIntent
from uploader.linked_upload import LinkedUploadTarget
from uploader.state_store import load_recent_gallery_addresses

# Purpose: Bound synchronization with one owned fake transport worker.
# Type: float. Units: seconds. Scope: one portable test wait or join.
# Consumers: LinkingTransportTests. Rationale: two seconds permits a loaded
# runner to schedule worker threads without waiting indefinitely on failure.
TEST_WORKER_WAIT_SECONDS = 2.0

# Purpose: Make the deadline regression complete promptly without real I/O.
# Type: float. Units: seconds. Scope: one fake delayed HTTPS request.
# Consumers: LinkingTransportTests. Rationale: 0.25 seconds distinguishes a
# total deadline from a blocked worker while allowing worker scheduling.
TEST_REQUEST_DEADLINE_SECONDS = 0.25

# Type: str. Units: Base32 characters. Scope: one synthetic browser handoff.
# Consumers: push-response tests. Rationale: canonical 128-bit value models
# the server's opaque correlation handle without giving it user authority.
TEST_REQUEST_HANDLE = base64.b32encode(
    b'p' * linking.REQUEST_HANDLE_RANDOM_BYTES,
).rstrip(b'=').decode('ascii')


def discovery_response(
    issuer: str, scope: str,
) -> dict[str, linking.JsonValue]:
    """Build a supported public-discovery envelope for one attempt.

    Args:
        issuer (str): Exact canonical issuer selected by the fixture.
        scope (str): Registered requested scope bundle.

    Returns:
        dict[str, linking.JsonValue]: Public capability response with every
            requested non-identity scope and the browser linking capability.
    """
    capabilities = ['linking', *sorted(set(scope.split()) - {linking.IDENTITY_SCOPE})]
    return {
        'contract_version': linking.LINKING_DISCOVERY_CONTRACT_VERSION,
        'app_linking': {
            'available': True,
            'protocol_version': linking.LINKING_PROTOCOL_VERSION,
            'browser_handoff_version': linking.BROWSER_HANDOFF_VERSION,
            'issuer': issuer,
            'capabilities': capabilities,
        },
    }


def handoff_response(
    issuer: str, request_handle: str = TEST_REQUEST_HANDLE,
    expires_in: int = linking.HANDOFF_MAX_LIFETIME_SECONDS,
) -> dict[str, linking.JsonValue]:
    """Build a valid browser handoff response, optionally with test overrides.

    Args:
        issuer (str): Exact canonical issuer selected by the fixture.
        request_handle (str): Canonical synthetic correlation handle.
        expires_in (int): Synthetic server-provided handoff lifetime in seconds.

    Returns:
        dict[str, linking.JsonValue]: Versioned handoff response with the exact
            server-derived browser approval URL.
    """
    return {
        'handoff_version': linking.BROWSER_HANDOFF_VERSION,
        'request_handle': request_handle,
        'start_url': issuer + '/index.php?page=app_linking_start'
        + '&request_handle=' + request_handle,
        'expires_in': expires_in,
    }

try:
    import tkinter
except ModuleNotFoundError as exc:
    if exc.name != 'tkinter':
        raise
    LinkingManager = None
else:
    from uploader.app_linking_ui import LinkingManager, _LinkedSite


class LinkingTransportTests(unittest.TestCase):
    """Exercise linking transport boundaries without contacting a server."""

    def setUp(self) -> None:
        """Provide a local listener whose requests are supplied by each test.

        Returns:
            None: Replaces only this process's socket constructor until cleanup.
        """
        self.listener = mock.MagicMock()
        # This arbitrary nonzero port stands for an OS-assigned loopback port.
        self.listener.getsockname.return_value = ('127.0.0.1', 56321)
        self.socket_patch = mock.patch.object(
            linking.socket, 'socket', return_value=self.listener,
        )
        self.socket_patch.start()
        self.addCleanup(self.socket_patch.stop)

    def test_issuer_normalization_preserves_mount_and_limits_http(self) -> None:
        """Canonicalize accepted addresses and refuse unsafe alternatives.

        Returns:
            None: Canonical HTTPS and localhost HTTP preserve the exact mount.
        """
        cases = {
            'https://Gallery.Example:443/one/two/api/upload':
                'https://gallery.example/one/two',
            'https://gallery.example/index.php?page=upload_automation_upload':
                'https://gallery.example',
            'https://[0:0:0:0:0:0:0:1]:443/Galerie':
                'https://[::1]/Galerie',
            'https://gallery.example:8443/Galerie':
                'https://gallery.example:8443/Galerie',
            'https://127.0.0.1/Galerie': 'https://127.0.0.1/Galerie',
            'http://LOCALHOST:80/Galerie/': 'http://localhost/Galerie',
            'http://localhost:8888/galerie/': 'http://localhost:8888/galerie',
        }
        for value, expected in cases.items():
            with self.subTest(value=value):
                self.assertEqual(expected, linking.linking_issuer(value))
        for value in (
            'http://localhost.example/Galerie',
            'http://127.0.0.1/Galerie',
            'http://[::1]/Galerie',
            'http://localhost@external.example/Galerie',
            'https://user:password@gallery.example/Galerie',
            'https://gallery.example/a%2fb',
            'https://gallery.example/Galerie?other=1',
            'https://gallery.example/Galerie#fragment',
            'https://[::ffff:127.0.0.1]/Galerie',
            # Browsers reinterpret abbreviated, octal and hexadecimal IPv4.
            'https://127.1/Galerie',
            'https://0177.0.0.1/Galerie',
            'https://0x7f.0.0.1/Galerie',
            'https://2130706433/Galerie',
            'https://example.123/Galerie',
        ):
            with self.subTest(value=value):
                self.assertEqual('', linking.linking_issuer(value))

    def test_callback_page_reports_each_decision_with_scoped_style(self) -> None:
        """Keep the local confirmation specific to the validated outcome.

        Returns:
            None: A fixed HTML response and its only permitted stylesheet.
        """
        for outcome, status, title in (
            ('approved', b'200 OK', b'Approval received'),
            ('denied', b'200 OK', b'Link cancelled'),
            (None, b'400 Bad Request', b'Link request rejected'),
        ):
            with self.subTest(outcome=outcome):
                connection = mock.Mock()
                linking._reply(
                    connection, outcome, time.monotonic() + TEST_WORKER_WAIT_SECONDS,
                )
                response = connection.sendall.call_args.args[0]
                headers, body = response.split(b'\r\n\r\n', 1)
                self.assertTrue(headers.startswith(b'HTTP/1.1 ' + status))
                self.assertIn(b'Content-Type: text/html; charset=utf-8', headers)
                self.assertIn(b'Cache-Control: no-store', headers)
                self.assertIn(b'<h1>' + title + b'</h1>', body)
                self.assertNotIn(b'<script', body)
                css = body.split(b'<style>', 1)[1].split(b'</style>', 1)[0]
                digest = base64.b64encode(hashlib.sha256(css).digest())
                self.assertIn(b"style-src 'sha256-" + digest + b"'", headers)

    def test_local_http_transport_refuses_non_loopback_dns_answers(self) -> None:
        """Refuse a localhost resolution to an address on the network.

        Returns:
            None: No TCP socket is opened for an external answer.
        """
        control = linking._LinkingTransportControl(
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            None, TEST_WORKER_WAIT_SECONDS,
        )
        connection = linking._DeadlineLoopbackConnection(
            http.client.HTTP_PORT, control,
        )
        # The system resolver is simulated with a documentation-range address.
        resolved = [(
            socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP, '',
            ('192.0.2.1', http.client.HTTP_PORT),
        )]
        with (
            mock.patch.object(socket, 'getaddrinfo', return_value=resolved),
            mock.patch.object(socket, 'socket') as create_socket,
        ):
            with self.assertRaises(OSError):
                connection.connect()
            create_socket.assert_not_called()

    def test_push_requires_current_public_capabilities_before_post(self) -> None:
        """Fail closed on unavailable, mismatched or unknown discovery versions.

        Returns:
            None: Unsupported envelopes make only the public GET and never POST.
        """
        issuer = 'https://gallery.example/Galerie'
        valid = discovery_response(issuer, linking.READ_UPLOAD_SCOPE)
        invalid = (
            {**valid, 'contract_version': True},
            {**valid, 'contract_version': 2},
            {**valid, 'app_linking': {**valid['app_linking'], 'available': False}},
            {**valid, 'app_linking': {
                **valid['app_linking'], 'protocol_version': True,
            }},
            {**valid, 'app_linking': {
                **valid['app_linking'], 'protocol_version': 2,
            }},
            {**valid, 'app_linking': {
                **valid['app_linking'], 'browser_handoff_version': 2,
            }},
            {**valid, 'app_linking': {
                **valid['app_linking'], 'issuer': 'https://other.example/Galerie',
            }},
            {**valid, 'app_linking': {
                **valid['app_linking'], 'capabilities': ['linking'],
            }},
            {**valid, 'app_linking': {
                **valid['app_linking'], 'capabilities': ['linking', 7],
            }},
        )
        for envelope in invalid:
            with self.subTest(envelope=envelope):
                attempt = linking.LinkingAttempt(
                    issuer, scope=linking.READ_UPLOAD_SCOPE,
                )
                self.addCleanup(attempt.close)
                with mock.patch.object(
                    linking, '_request_json', side_effect=[
                        envelope, handoff_response(issuer),
                    ],
                ) as request:
                    with self.assertRaises(linking.LinkingError):
                        attempt.push_request()
                    request.assert_called_once()
                    self.assertEqual(
                        ('app_linking_discovery', 'GET', None, None),
                        request.call_args.args[1:5],
                    )

    def test_push_validates_versioned_handle_url_and_lifetime(self) -> None:
        """Accept extensible handoffs but reject malformed authority data.

        Returns:
            None: Only a current version, canonical handle, exact URL and
                positive bounded TTL produce a browser authorization.
        """
        issuer = 'https://gallery.example/Galerie'
        supported = discovery_response(issuer, linking.IDENTITY_SCOPE)
        good = handoff_response(issuer)
        malformed = (
            {**good, 'handoff_version': True},
            {**good, 'handoff_version': 2},
            {**good, 'request_handle': 'invalid'},
            {key: value for key, value in good.items() if key != 'request_handle'},
            {**good, 'start_url': issuer + '/index.php?page=app_linking_start'},
            {**good, 'start_url': 'https://evil.example/approve'},
            {**good, 'expires_in': 0},
            {**good, 'expires_in': True},
            {**good, 'expires_in': linking.HANDOFF_MAX_LIFETIME_SECONDS + 1},
        )
        for response in malformed:
            with self.subTest(response=response):
                attempt = linking.LinkingAttempt(issuer)
                self.addCleanup(attempt.close)
                with mock.patch.object(
                    linking, '_request_json', side_effect=[supported, response],
                ) as request:
                    with self.assertRaises(linking.LinkingError):
                        attempt.push_request()
                    self.assertEqual(2, request.call_count)
        attempt = linking.LinkingAttempt(issuer)
        self.addCleanup(attempt.close)
        with mock.patch.object(linking, '_request_json', side_effect=[
            {**supported, 'future_field': {'ignored': True}},
            {**good, 'future_field': 'ignored'},
        ]):
            result = attempt.push_request()
        self.assertEqual(TEST_REQUEST_HANDLE, result.request_handle)
        self.assertNotIn(TEST_REQUEST_HANDLE, repr(result))
        self.assertEqual(good['start_url'], result.authorization_url)

    def test_pkce_callback_and_one_shot_exchange(self) -> None:
        """Bind one PKCE request to state, issuer and one code exchange.

        Returns:
            None: Mismatched callbacks fail and a consumed code is not retried.
        """
        attempt = linking.LinkingAttempt('https://gallery.example/Galerie')
        self.addCleanup(attempt.close)
        code = linking.CODE_PREFIX + base64.urlsafe_b64encode(
            b'c' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        with mock.patch.object(linking, '_request_json') as request_json:
            request_json.side_effect = [
                discovery_response(
                    'https://gallery.example/Galerie', linking.IDENTITY_SCOPE,
                ),
                {**handoff_response('https://gallery.example/Galerie'),
                 'optional_future_field': 'ignored'},
                {'access_token': token, 'token_type': 'Bearer',
                 'expires_in': linking.HANDOFF_MAX_LIFETIME_SECONDS,
                 'scope': linking.IDENTITY_SCOPE},
            ]
            pushed = attempt.push_request()
            self.assertEqual(TEST_REQUEST_HANDLE, pushed.request_handle)
            self.assertEqual(
                'https://gallery.example/Galerie/index.php?page=app_linking_start'
                + '&request_handle=' + TEST_REQUEST_HANDLE,
                pushed.authorization_url,
            )
            discovery_args = request_json.call_args_list[0].args
            self.assertEqual(
                ('https://gallery.example/Galerie', 'app_linking_discovery',
                 'GET', None, None),
                discovery_args[:5],
            )
            push_args = request_json.call_args_list[1].args
            pushed_fields = parse_qs(push_args[3].decode('ascii'))
            self.assertEqual('app_linking_push', push_args[1])
            self.assertIsNone(push_args[4])
            self.assertEqual(['S256'], pushed_fields['code_challenge_method'])
            redirect = pushed_fields['redirect_uri'][0]
            state = pushed_fields['state'][0]
            self.assertEqual(
                'http://127.0.0.1:56321/oauth2redirect/php-gallery', redirect,
            )
            callback = linking.CALLBACK_PATH + '?' + urlencode({
                'code': code, 'state': state,
                'iss': 'https://gallery.example/Galerie',
            })
            wrong_state_value = ('A' if state[0] != 'A' else 'B') + state[1:]
            wrong_state = callback.replace(state, wrong_state_value)
            self.assertIsNone(linking._callback_values(
                wrong_state, state, 'https://gallery.example/Galerie',
            ))
            self.assertIsNone(linking._callback_values(
                callback, state, 'https://other.example/Galerie',
            ))
            self.assertIsNone(linking._callback_values(
                callback.replace(linking.CALLBACK_PATH, '/wrong'), state,
                'https://gallery.example/Galerie',
            ))
            connection = mock.MagicMock()
            connection.__enter__.return_value = connection
            connection.recv.return_value = (
                'GET ' + callback + ' HTTP/1.1\r\n'
                'Host: 127.0.0.1:56321\r\n\r\n'
            ).encode('ascii')
            self.listener.accept.return_value = (connection, ('127.0.0.1', 51000))
            self.assertEqual('approved', attempt.wait_for_callback())
            session = attempt.exchange_once()
            self.addCleanup(session.close)
            exchange_args = request_json.call_args_list[2].args
            fields = parse_qs(exchange_args[3].decode('ascii'))
            verifier = fields['code_verifier'][0]
            computed = base64.urlsafe_b64encode(
                hashlib.sha256(verifier.encode('ascii')).digest(),
            ).rstrip(b'=').decode('ascii')
            self.assertEqual([computed], pushed_fields['code_challenge'])
            self.assertEqual([redirect], fields['redirect_uri'])
            self.assertEqual([code], fields['code'])
            self.assertNotIn('request_handle', fields)
            self.assertIsNone(exchange_args[4])
            with self.assertRaises(linking.LinkingError):
                attempt.exchange_once()
            self.assertEqual(3, request_json.call_count)

    def test_denied_callback_cannot_exchange(self) -> None:
        """Treat an explicit issuer-bound denial as a terminal attempt.

        Returns:
            None: A denied browser callback never starts token exchange.
        """
        attempt = linking.LinkingAttempt('https://gallery.example')
        self.addCleanup(attempt.close)
        with mock.patch.object(linking, '_request_json') as request_json:
            request_json.side_effect = [
                discovery_response('https://gallery.example', linking.IDENTITY_SCOPE),
                handoff_response('https://gallery.example'),
            ]
            attempt.push_request()
            fields = parse_qs(request_json.call_args_list[1].args[3].decode('ascii'))
            callback = linking.CALLBACK_PATH + '?' + urlencode({
                'error': 'access_denied', 'state': fields['state'][0],
                'iss': 'https://gallery.example',
            })
            connection = mock.MagicMock()
            connection.__enter__.return_value = connection
            connection.recv.return_value = (
                'GET ' + callback + ' HTTP/1.1\r\n'
                'Host: 127.0.0.1:56321\r\n\r\n'
            ).encode('ascii')
            self.listener.accept.return_value = (connection, ('127.0.0.1', 51000))
            self.assertEqual('denied', attempt.wait_for_callback())
            with self.assertRaises(linking.LinkingError):
                attempt.exchange_once()
            self.assertEqual(2, request_json.call_count)

    def test_listing_scope_requires_exact_approval(self) -> None:
        """Bind the requested listing scope through push and token exchange.

        Returns:
            None: Exact listing scope succeeds and a different scope fails.
        """
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        attempt = linking.LinkingAttempt(
            'https://gallery.example', scope=linking.GALLERIES_SCOPE,
        )
        self.addCleanup(attempt.close)
        with mock.patch.object(linking, '_request_json') as request_json:
            request_json.side_effect = [
                discovery_response('https://gallery.example', linking.GALLERIES_SCOPE),
                handoff_response('https://gallery.example'),
            ]
            attempt.push_request()
            pushed = parse_qs(request_json.call_args_list[1].args[3].decode('ascii'))
            self.assertEqual([linking.GALLERIES_SCOPE], pushed['scope'])
            attempt._phase = 'approved'
            attempt._code = linking.CODE_PREFIX + base64.urlsafe_b64encode(
                b'c' * linking.ATTEMPT_RANDOM_BYTES,
            ).rstrip(b'=').decode('ascii')
            request_json.side_effect = None
            request_json.return_value = {
                'access_token': token, 'token_type': 'Bearer',
                'expires_in': 120, 'scope': linking.GALLERIES_SCOPE,
            }
            session = attempt.exchange_once()
            self.addCleanup(session.close)
            self.assertTrue(session.can_list_galleries())
            request_json.return_value = {
                'scope': linking.GALLERIES_SCOPE,
                'user': {'id': 17, 'username': 'admin'},
            }
            self.assertEqual('admin', session.identity().username)
            request_json.return_value['scope'] = linking.IDENTITY_SCOPE
            with self.assertRaises(linking.LinkingError):
                session.identity()

    def test_gallery_listing_and_upload_serialize_on_one_session(self) -> None:
        """Wait for a listing before admitting an overlapping upload request.

        Returns:
            None: Both operations complete exactly once without a busy failure.
        """
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        session = linking.LinkingSession(
            'https://gallery.example/Galerie', token,
            time.monotonic() + 120, linking.UPLOAD_SCOPE,
        )
        entered, release = threading.Event(), threading.Event()
        calls, failures = [], []

        def fake_request(
            _issuer: str, route: str, *_args: str | bytes | Iterable[bytes] | None,
            **_kwargs: threading.Event | str | int | None,
        ) -> dict[str, linking.JsonValue]:
            """Hold the first request while the second waits for admission.

            Args:
                _issuer (str): Unused fixture issuer.
                route (str): Requested fixed operation.
                *_args (str | bytes | Iterable[bytes] | None): Unused method,
                    request body and bearer arguments.
                **_kwargs (threading.Event | str | int | None): Unused
                    cancellation, boundary, length and cursor arguments.

            Returns:
                dict[str, linking.JsonValue]: Empty JSON object fixture for the
                    private transport wrapper.
            """
            calls.append(route)
            if route == 'app_linking_galleries':
                entered.set()
                if not release.wait(TEST_WORKER_WAIT_SECONDS):
                    raise RuntimeError('Timed out waiting for test release.')
            return {}

        def call(route: str) -> None:
            """Capture unexpected worker errors for assertion on the test thread.

            Args:
                route (str): Scoped operation submitted by this thread.

            Returns:
                None: Records an unexpected exception in the shared fixture.
            """
            try:
                session._request(route)
            except Exception as exc:
                failures.append(exc)

        with mock.patch.object(linking, '_request_json', side_effect=fake_request):
            first = threading.Thread(target=call, args=('app_linking_galleries',))
            second = threading.Thread(target=call, args=('app_linking_upload',))
            first.start()
            self.assertTrue(entered.wait(TEST_WORKER_WAIT_SECONDS))
            second.start()
            release.set()
            first.join(TEST_WORKER_WAIT_SECONDS)
            second.join(TEST_WORKER_WAIT_SECONDS)
        self.assertFalse(first.is_alive())
        self.assertFalse(second.is_alive())
        self.assertEqual([], failures)
        self.assertEqual(['app_linking_galleries', 'app_linking_upload'], calls)
        session.close()

    def test_closing_dialog_cancels_queued_listing_not_active_upload(self) -> None:
        """Prevent a queued listing from outliving the dialog that requested it.

        Returns:
            None: The upload finishes, while the cancelled listing sends nothing.
        """
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        session = linking.LinkingSession(
            'https://gallery.example/Galerie', token,
            time.monotonic() + 120, linking.UPLOAD_SCOPE,
        )
        started, release, waiting = threading.Event(), threading.Event(), threading.Event()
        calls, failures = [], []
        gate = session._request_gate

        class ObservedGate:
            """Expose when a second request has reached session admission."""

            def acquire(self, timeout: float) -> bool:
                """Wait for the gate and announce a queued gallery operation.

                Args:
                    timeout (float): Maximum gate wait in seconds.

                Returns:
                    bool: Whether the underlying gate was acquired.
                """
                if started.is_set():
                    waiting.set()
                return gate.acquire(timeout=timeout)

            def release(self) -> None:
                """Release the acquired gate.

                Returns:
                    None: Allows the next session operation to proceed.
                """
                gate.release()

        session._request_gate = ObservedGate()

        def fake_request(
            _issuer: str, route: str, *_args: str | bytes | Iterable[bytes] | None,
            **_kwargs: threading.Event | str | int | None,
        ) -> dict[str, linking.JsonValue]:
            """Pause a single upload while the listing queues behind it.

            Args:
                _issuer (str): Unused fixture issuer.
                route (str): Fixed requested operation.
                *_args (str | bytes | Iterable[bytes] | None): Unused method,
                    request body and bearer arguments.
                **_kwargs (threading.Event | str | int | None): Unused
                    cancellation, boundary, length and cursor arguments.

            Returns:
                dict[str, linking.JsonValue]: Empty JSON object fixture for the
                    private transport wrapper.
            """
            calls.append(route)
            started.set()
            if not release.wait(TEST_WORKER_WAIT_SECONDS):
                raise RuntimeError('Timed out waiting for upload release.')
            return {}

        def call(route: str) -> None:
            """Record the outcome of one session operation.

            Args:
                route (str): Scoped operation to submit.

            Returns:
                None: Records any failure for assertions by the test thread.
            """
            try:
                session._request(route)
            except Exception as exc:
                failures.append((route, exc))

        with mock.patch.object(linking, '_request_json', side_effect=fake_request):
            upload = threading.Thread(target=call, args=('app_linking_upload',))
            listing = threading.Thread(target=call, args=('app_linking_galleries',))
            upload.start()
            self.assertTrue(started.wait(TEST_WORKER_WAIT_SECONDS))
            listing.start()
            self.assertTrue(waiting.wait(TEST_WORKER_WAIT_SECONDS))
            session.cancel_pending(gallery_only=True)
            self.assertFalse(session._cancelled.is_set())
            release.set()
            upload.join(TEST_WORKER_WAIT_SECONDS)
            listing.join(TEST_WORKER_WAIT_SECONDS)
        self.assertFalse(upload.is_alive())
        self.assertFalse(listing.is_alive())
        self.assertEqual(['app_linking_upload'], calls)
        self.assertEqual(1, len(failures))
        self.assertEqual('app_linking_galleries', failures[0][0])
        self.assertIsInstance(failures[0][1], linking.LinkingError)
        session.close()

    def test_gallery_pages_are_bounded_and_scoped(self) -> None:
        """Accept validated pages while refusing scope and cursor escalation.

        Returns:
            None: Listing remains scoped, ordered, bounded and issuer-bound.
        """
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        session = linking.LinkingSession(
            'https://gallery.example/Galerie', token,
            time.monotonic() + 120, linking.GALLERIES_SCOPE,
        )
        self.addCleanup(session.close)
        rows = [
            {'id': index, 'parent_id': None, 'title': f'Gallery {index}'}
            for index in range(1, linking.LINKING_GALLERY_PAGE_MAX + 1)
        ]
        with mock.patch.object(linking, '_request_json') as request_json:
            request_json.return_value = {
                'galleries': rows, 'next_after': linking.LINKING_GALLERY_PAGE_MAX,
            }
            first = session.gallery_page()
            self.assertEqual(linking.LINKING_GALLERY_PAGE_MAX, len(first.galleries))
            self.assertEqual(linking.LINKING_GALLERY_PAGE_MAX, first.next_after)
            self.assertEqual('https://gallery.example/Galerie',
                             request_json.call_args.args[0])
            self.assertEqual('app_linking_galleries', request_json.call_args.args[1])
            self.assertEqual('GET', request_json.call_args.args[2])
            self.assertEqual(token, request_json.call_args.args[4])
            request_json.return_value = {
                'galleries': [{'id': 51, 'parent_id': 1, 'title': 'Next'}],
                'next_after': None,
            }
            second = session.gallery_page(first.next_after)
            self.assertEqual(51, second.galleries[0].gallery_id)
            self.assertEqual(1, second.galleries[0].parent_id)
            self.assertIsNone(second.next_after)
            self.assertEqual(50, request_json.call_args.kwargs['after_id'])
            with self.assertRaises(linking.LinkingError):
                session.gallery_page(True)
            request_json.return_value = {
                'galleries': [{'id': 50, 'parent_id': None, 'title': 'Repeat'}],
                'next_after': None,
            }
            with self.assertRaises(linking.LinkingError):
                session.gallery_page(50)
            request_json.return_value = {'galleries': rows, 'next_after': 49}
            with self.assertRaises(linking.LinkingError):
                session.gallery_page()
            request_json.return_value = {'galleries': rows * 2, 'next_after': None}
            with self.assertRaises(linking.LinkingError):
                session.gallery_page()
        identity_only = linking.LinkingSession(
            'https://gallery.example', token, time.monotonic() + 120,
        )
        self.addCleanup(identity_only.close)
        with mock.patch.object(linking, '_request_json') as request_json:
            self.assertFalse(identity_only.can_list_galleries())
            with self.assertRaises(linking.LinkingError):
                identity_only.gallery_page()
            request_json.assert_not_called()

    def test_gallery_picker_uses_approved_search_and_ranked_rows(self) -> None:
        """Use an exact approved grant for form search and validate its hierarchy.

        Returns:
            None: Confirms ranked order, parent titles and refusal of a basic grant.
        """
        issuer = 'https://gallery.example/Galerie'
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        deadline = time.monotonic() + TEST_WORKER_WAIT_SECONDS
        session = linking.LinkingSession(
            issuer, token, deadline, linking.UPLOAD_SCOPE + linking.GALLERY_SEARCH_SUFFIX,
        )
        basic = linking.LinkingSession(issuer, token, deadline, linking.UPLOAD_SCOPE)
        self.addCleanup(session.close)
        self.addCleanup(basic.close)
        child = {
            'id': 7, 'parent_id': 2, 'title': 'Cyberpunk',
            'depth': 1, 'ancestors': ['Night City'],
        }
        parent = {
            'id': 2, 'parent_id': None, 'title': 'Night City',
            'depth': 0, 'ancestors': [],
        }
        with mock.patch.object(linking, '_request_json') as request_json:
            with self.assertRaises(linking.LinkingError):
                basic.gallery_picker_page('cyberpnuk')
            request_json.assert_not_called()
            request_json.return_value = {'galleries': [child, parent], 'next_after': None}
            page = session.gallery_picker_page('cyberpnuk OR "Night City"')
            self.assertEqual((7, 2), tuple(gallery.gallery_id for gallery in page.galleries))
            self.assertEqual(('Night City',), page.galleries[0].ancestors)
            self.assertTrue(session.can_upload())
            sent_issuer, route, method, body, _bearer = request_json.call_args.args
            self.assertEqual((issuer, 'app_linking_galleries', 'POST'),
                             (sent_issuer, route, method))
            self.assertEqual(
                {'query': ['cyberpnuk OR "Night City"'], 'after': ['']},
                parse_qs(body.decode('ascii'), keep_blank_values=True),
            )
            self.assertNotIn('after_id', request_json.call_args.kwargs)
            child['ancestors'] = []
            with self.assertRaises(linking.LinkingError):
                session.gallery_picker_page('cyberpnuk')

    def test_cancelling_gallery_read_keeps_the_local_session(self) -> None:
        """Cancel one in-flight read without claiming server revocation.

        Returns:
            None: The read stops and a still-valid local session remains usable.
        """
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        session = linking.LinkingSession(
            'https://gallery.example', token,
            time.monotonic() + linking.HANDOFF_MAX_LIFETIME_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        self.addCleanup(session.close)
        started = threading.Event()
        finished = threading.Event()

        def request_json(
            issuer: str, route: str, method: str, body: bytes | None,
            bearer: str | None, cancelled: threading.Event | None = None,
        ) -> dict[str, linking.JsonValue]:
            """Wait for the page owner's explicit cancellation signal.

            Args:
                issuer (str): Trusted HTTPS issuer for this request.
                route (str): Fixed gallery-list route.
                method (str): Fixed GET method.
                body (bytes | None): Optional body, absent for this GET.
                bearer (str | None): In-memory bearer for the approved session.
                cancelled (threading.Event | None): Request cancellation signal.

            Returns:
                dict[str, linking.JsonValue]: No value when cancellation succeeds.

            Raises:
                linking.LinkingError: The request is explicitly cancelled.
            """
            started.set()
            if cancelled is None or not cancelled.wait(TEST_WORKER_WAIT_SECONDS):
                raise AssertionError('Cancellation did not arrive.')
            raise linking.LinkingError('Cancelled.')

        def worker() -> None:
            """Finish the expected cancelled page request without network I/O.

            Returns:
                None: Records completion of the cancelled operation.
            """
            try:
                session.gallery_page()
            except linking.LinkingError:
                finished.set()

        with mock.patch.object(linking, '_request_json', side_effect=request_json):
            thread = threading.Thread(target=worker, daemon=True)
            thread.start()
            self.assertTrue(started.wait(TEST_WORKER_WAIT_SECONDS))
            session.cancel_pending()
            thread.join(TEST_WORKER_WAIT_SECONDS)
        self.assertFalse(thread.is_alive())
        self.assertTrue(finished.is_set())
        self.assertTrue(session.can_list_galleries())
        self.assertFalse(session._cancelled.is_set())

    def test_push_refuses_redirect_without_forwarding_legacy_key(self) -> None:
        """Send only a bounded push to the selected HTTPS origin.

        Returns:
            None: A redirect raises without a follow-up request or API key.
        """
        headers = Message()
        headers['Content-Type'] = 'application/json'
        headers['Cache-Control'] = 'no-store'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 302
        response.headers = headers
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        with mock.patch.object(
            linking, '_DeadlineHTTPSConnection', return_value=connection,
        ) as factory:
            with self.assertRaises(linking.LinkingError):
                linking._request_json(
                    'https://gallery.example/Galerie', 'app_linking_push',
                    'POST', b'client_id=php-gallery-winapp', None,
                )
            factory.assert_called_once()
            call = connection.request.call_args
            self.assertEqual('POST', call.args[0])
            self.assertEqual('/Galerie/index.php?page=app_linking_push', call.args[1])
            self.assertNotIn('Authorization', call.kwargs['headers'])
            self.assertNotIn('X-Gallery-API-Key', call.kwargs['headers'])
            connection.request.assert_called_once()

    def test_bounded_request_returns_validated_json(self) -> None:
        """Return a completed response without treating cleanup as cancellation.

        Returns:
            None: The fixed route returns its JSON from the selected IPv6 host.
        """
        headers = Message()
        headers['Content-Type'] = 'application/json'
        headers['Cache-Control'] = 'no-store'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 201
        response.headers = headers
        response.read.return_value = json.dumps(handoff_response(
            'https://[::1]/Galerie',
        )).encode('utf-8')
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        with mock.patch.object(
            linking, '_DeadlineHTTPSConnection', return_value=connection,
        ) as factory:
            result = linking._request_json(
                'https://[::1]/Galerie', 'app_linking_push', 'POST', b'a=b', None,
            )
        self.assertEqual(handoff_response('https://[::1]/Galerie'), result)
        self.assertEqual('::1', factory.call_args.args[0])
        self.assertEqual(linking.LINKING_HTTPS_DEFAULT_PORT, factory.call_args.args[1])
        connection.request.assert_called_once()

    def test_discovery_uses_the_selected_mount_and_no_credentials(self) -> None:
        """Send public discovery over the same bounded HTTPS transport.

        Returns:
            None: The fixed mounted GET carries no body, bearer or API key.
        """
        headers = Message()
        headers['Content-Type'] = 'application/json'
        headers['Cache-Control'] = 'no-store'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.headers = headers
        payload = discovery_response(
            'https://gallery.example/Galerie', linking.IDENTITY_SCOPE,
        )
        response.read.return_value = json.dumps(payload).encode('utf-8')
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        with mock.patch.object(
            linking, '_DeadlineHTTPSConnection', return_value=connection,
        ) as factory:
            result = linking._request_json(
                'https://gallery.example/Galerie', 'app_linking_discovery',
                'GET', None, None,
            )
        self.assertEqual(payload, result)
        self.assertEqual('gallery.example', factory.call_args.args[0])
        call = connection.request.call_args
        self.assertEqual('GET', call.args[0])
        self.assertEqual(
            '/Galerie/index.php?page=app_linking_discovery', call.args[1],
        )
        self.assertIsNone(call.kwargs['body'])
        self.assertNotIn('Authorization', call.kwargs['headers'])
        self.assertNotIn('X-Gallery-API-Key', call.kwargs['headers'])

    def test_gallery_cursor_is_sent_only_to_fixed_https_route(self) -> None:
        """Keep a bearer out of the URL while requesting a subsequent page.

        Returns:
            None: Only the validated cursor is appended to the fixed route.
        """
        headers = Message()
        headers['Content-Type'] = 'application/json'
        headers['Cache-Control'] = 'no-store'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.headers = headers
        response.read.return_value = b'{"galleries":[],"next_after":null}'
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        bearer = 'pgl_fixture_secret'
        with mock.patch.object(
            linking, '_DeadlineHTTPSConnection', return_value=connection,
        ):
            linking._request_json(
                'https://gallery.example/Galerie', 'app_linking_galleries',
                'GET', None, bearer, after_id=50,
            )
        sent = connection.request.call_args
        self.assertEqual(
            '/Galerie/index.php?page=app_linking_galleries&after=50', sent.args[1],
        )
        self.assertNotIn(bearer, sent.args[1])
        self.assertEqual('Bearer ' + bearer, sent.kwargs['headers']['Authorization'])
        connection.request.assert_called_once()

    def test_visibility_accepts_only_exact_prewrite_conflicts(self) -> None:
        """Recognize only the two exact no-store 409 refusal envelopes.

        Returns:
            None: Other errors and spoofed success markers remain uncertain.
        """
        headers = Message()
        headers['Content-Type'] = 'application/json'
        headers['Cache-Control'] = 'no-store'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.headers = headers
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        with mock.patch.object(linking, '_DeadlineHTTPSConnection', return_value=connection):
            for reason in ('scheduled_publication_active', 'revision_conflict'):
                response.status = 409
                response.read.return_value = json.dumps({'error': reason}).encode()
                self.assertEqual(
                    {'__client_visibility_conflict': reason},
                    linking._request_json(
                        'https://gallery.example/Galerie',
                        'app_linking_gallery_visibility', 'POST', b'{}', 'fixture',
                    ),
                )
            for status, payload in (
                (409, b'{"error":"revision_conflict","extra":true}'),
                (409, b'{"error":"other"}'),
                (200, b'{"__client_visibility_conflict":"revision_conflict"}'),
            ):
                response.status = status
                response.read.return_value = payload
                with self.assertRaises(linking.LinkingError):
                    linking._request_json(
                        'https://gallery.example/Galerie',
                        'app_linking_gallery_visibility', 'POST', b'{}', 'fixture',
                    )
            response.status = 409
            response.read.return_value = b'{"error":"revision_conflict"}'
            del headers['Cache-Control']
            with self.assertRaises(linking.LinkingError):
                linking._request_json(
                    'https://gallery.example/Galerie',
                    'app_linking_gallery_visibility', 'POST', b'{}', 'fixture',
                )

    def test_late_dns_does_not_start_connection_and_blocks_new_workers(self) -> None:
        """Cancel a DNS-bound request without starting a late network request.

        Returns:
            None: Late DNS cannot connect and the outstanding slot stays held.
        """
        dns_started = threading.Event()
        dns_release = threading.Event()
        cancelled = threading.Event()
        outcome: list[str] = []
        self.addCleanup(dns_release.set)

        def resolve(
            host: str, port: int, family: int, kind: int,
        ) -> list[tuple[int, int, int, str, tuple[str, int]]]:
            """Hold one fake DNS lookup until the test releases its answer.

            Args:
                host (str): HTTPS server hostname.
                port (int): Requested HTTPS server port.
                family (int): Requested address-family selector.
                kind (int): Requested socket type.

            Returns:
                list[tuple[int, int, int, str, tuple[str, int]]]: One IPv4
                    family, socket type, protocol, canonical name and address.
            """
            dns_started.set()
            if not dns_release.wait(TEST_WORKER_WAIT_SECONDS):
                raise OSError('Fake DNS was not released.')
            return [(linking.socket.AF_INET, linking.socket.SOCK_STREAM, 6,
                     '', ('127.0.0.1', 443))]

        def request() -> None:
            """Record the bounded caller's terminal outcome without secrets.

            Returns:
                None: Appends the generic result marker.
            """
            try:
                linking._request_json(
                    'https://gallery.example', 'app_linking_push', 'POST',
                    b'client_id=php-gallery-winapp', None, cancelled=cancelled,
                )
            except linking.LinkingError:
                outcome.append('cancelled')
            else:
                outcome.append('unexpected-success')

        with mock.patch.object(linking.socket, 'getaddrinfo', side_effect=resolve):
            caller = threading.Thread(target=request, daemon=True)
            caller.start()
            self.assertTrue(dns_started.wait(TEST_WORKER_WAIT_SECONDS))
            cancelled.set()
            caller.join(TEST_WORKER_WAIT_SECONDS)
            self.assertFalse(caller.is_alive())
            self.assertEqual(['cancelled'], outcome)
            with self.assertRaises(linking.LinkingError):
                linking._request_json(
                    'https://gallery.example', 'app_linking_push', 'POST',
                    b'client_id=php-gallery-winapp', None,
                )
            dns_release.set()
            self.assertTrue(linking._LINKING_TRANSPORT_SLOTS.acquire(
                timeout=TEST_WORKER_WAIT_SECONDS,
            ))
            linking._LINKING_TRANSPORT_SLOTS.release()
            linking.socket.socket.assert_not_called()

    def test_total_deadline_interrupts_a_stalled_response(self) -> None:
        """Bound a response even when individual socket reads keep waiting.

        Returns:
            None: The caller returns, its socket is shut down and no retry occurs.
        """
        request_started = threading.Event()
        request_release = threading.Event()
        connection = mock.Mock()
        self.addCleanup(request_release.set)

        def delayed(
            issuer: str, route: str, method: str, body: bytes | None,
            bearer: str | None, control: linking._LinkingTransportControl,
        ) -> dict[str, linking.JsonValue]:
            """Keep a fake response in flight until the deadline interrupts it.

            Args:
                issuer (str): Fixed HTTPS issuer selected by the caller.
                route (str): Fixed linking route selected by the caller.
                method (str): HTTP method selected by the caller.
                body (bytes | None): Request body, if present.
                bearer (str | None): Request bearer, if present.
                control (linking._LinkingTransportControl): Deadline owner.

            Returns:
                dict[str, linking.JsonValue]: Empty object only if still active.
            """
            control.set_socket(connection)
            request_started.set()
            if not request_release.wait(TEST_WORKER_WAIT_SECONDS):
                raise OSError('Fake response was not released.')
            control.remaining()
            return {}

        with mock.patch.object(
            linking, 'LINKING_REQUEST_DEADLINE_SECONDS',
            TEST_REQUEST_DEADLINE_SECONDS,
        ), mock.patch.object(linking, '_request_json_once', side_effect=delayed) as send:
            started = time.monotonic()
            with self.assertRaises(linking.LinkingError):
                linking._request_json(
                    'https://gallery.example', 'app_linking_push', 'POST',
                    b'client_id=php-gallery-winapp', None,
                )
            self.assertTrue(request_started.is_set())
            self.assertLess(time.monotonic() - started, TEST_WORKER_WAIT_SECONDS)
            connection.shutdown.assert_called_once_with(linking.socket.SHUT_RDWR)
            connection.close.assert_called_once_with()
            send.assert_called_once()
            request_release.set()
            self.assertTrue(linking._LINKING_TRANSPORT_SLOTS.acquire(
                timeout=TEST_WORKER_WAIT_SECONDS,
            ))
            linking._LINKING_TRANSPORT_SLOTS.release()


class VisibilitySessionTests(unittest.TestCase):
    """Exercise exact consent and one-shot revision-bound visibility writes."""

    def test_upload_ack_requires_one_registered_image(self) -> None:
        """Keep ambiguous upload replies from acknowledging a local marker.

        Returns:
            None: Only a single valid image in the requested gallery is accepted.
        """
        session = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 60, linking.UPLOAD_SCOPE,
        )
        good = {
            'ok': True, 'gallery_id': 7, 'uploaded': 1, 'scanned': 0,
            'image_ids': [32], 'filenames': ['photo.jpg'],
            'upload_evidence': 'fresh_insert_v1',
            'client_thumbnails': {'installed': 0, 'skipped': 0, 'failed': 1,
                                  'errors': ['Optional derivative failed.']},
        }
        with mock.patch.object(linking, '_request_json', return_value=good) as request:
            self.assertEqual(good, session.upload_multipart(7, iter([b'body']),
                                                             'a' * 20, 4))
            request.assert_called_once()
        for bad in (
            {key: value for key, value in good.items() if key != 'upload_evidence'},
            {**good, 'upload_evidence': 'unknown'},
            {key: value for key, value in good.items() if key != 'image_ids'},
            {**good, 'image_ids': []}, {**good, 'image_ids': [32, 32]},
            {**good, 'image_ids': [True]}, {**good, 'image_ids': [0]},
            {**good, 'image_ids': ['32']}, {**good, 'filenames': []},
            {**good, 'gallery_id': 8}, {**good, 'uploaded': 0},
            {**good, 'scanned': -1}, {**good, 'renamed': 'invalid'},
            {**good, 'client_thumbnails': None},
        ):
            with self.subTest(response=bad), mock.patch.object(
                linking, '_request_json', return_value=bad,
            ) as request:
                with self.assertRaises(linking.LinkingError):
                    session.upload_multipart(7, iter([b'body']), 'a' * 20, 4)
                request.assert_called_once()

    def test_inventory_requires_verified_exact_candidate_evidence(self) -> None:
        """Accept verified content evidence only for an exact requested item.

        Returns:
            None: Rejects old database-only, mismatched and partial replies.
        """
        session = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 60, linking.UPLOAD_SCOPE,
        )
        digest = 'b' * 64
        candidate = {'client_id': digest, 'filename': 'photo.jpg',
                     'size': 1024, 'sha256': digest}
        match = {**candidate, 'remote_filename': 'stored.jpg',
                 'remote_relative_path': 'stored.jpg', 'remote_image_id': 44,
                 'matched_by': 'checksum_sha256'}
        good = {'ok': True, 'action': 'inventory', 'gallery_id': 7,
                'gallery_title': 'Gallery', 'checked': 1, 'matched': 1,
                'existing': [match], 'deep_check_applied': False,
                'fingerprint': 'fingerprint', 'server_time': 'time',
                'inventory_evidence': 'verified_file_v1'}
        with mock.patch.object(linking, '_request_json', return_value=good):
            self.assertEqual(good, session.inventory(7, [candidate]))
        empty = {**good, 'matched': 0, 'existing': []}
        with mock.patch.object(linking, '_request_json', return_value=empty):
            self.assertEqual(empty, session.inventory(7, [candidate]))
        bad_rows = (
            {**match, 'sha256': 'c' * 64}, {**match, 'size': 1025},
            {**match, 'client_id': 'different'}, {**match, 'remote_image_id': 0},
            {**match, 'remote_relative_path': 'private/path'},
            {**match, 'matched_by': 'disk_sha256'},
        )
        bad_responses = (
            {key: value for key, value in good.items() if key != 'inventory_evidence'},
            {**good, 'inventory_evidence': 'unknown'}, {**good, 'gallery_id': 8},
            {**good, 'checked': 0}, {**good, 'matched': 0},
            {**good, 'existing': [match, match], 'matched': 2},
            *({**good, 'existing': [row]} for row in bad_rows),
        )
        for bad in bad_responses:
            with self.subTest(response=bad), mock.patch.object(
                linking, '_request_json', return_value=bad,
            ):
                with self.assertRaises(linking.LinkingError):
                    session.inventory(7, [candidate])
        changed = {**candidate, 'filename': 'second.jpg'}
        last_match = {**match, 'filename': 'second.jpg'}
        with mock.patch.object(linking, '_request_json', return_value={
            **good, 'existing': [last_match],
        }):
            session.inventory(7, [candidate, changed])
        with mock.patch.object(linking, '_request_json') as request:
            with self.assertRaises(linking.LinkingError):
                session.inventory(7, [candidate, {**candidate, 'size': 1025}])
            request.assert_not_called()

    def test_inventory_batches_are_bounded_and_never_partially_returned(self) -> None:
        """Bound count and bytes while discarding every incomplete result.

        Returns:
            None: Later errors or a budget expiry publish no partial inventory.
        """
        session = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 60, linking.UPLOAD_SCOPE,
        )
        candidates = [
            {'client_id': f'{number:064x}', 'filename': f'{number}.jpg',
             'size': 1, 'sha256': f'{number:064x}'}
            for number in range(10)
        ]
        calls: list[list[dict[str, linking.JsonValue]]] = []

        def response(
            issuer: str, route: str, method: str, body: bytes, bearer: str | None,
            *, cancelled: threading.Event | None = None,
        ) -> dict[str, linking.JsonValue]:
            """Return a verified empty result for the supplied synthetic batch.

            Args:
                issuer (str): Unused trusted synthetic gallery issuer.
                route (str): Unused fixed synthetic inventory route.
                method (str): Unused synthetic HTTP method.
                body (bytes): JSON body containing file candidate objects.
                bearer (str | None): Unused synthetic bearer credential.
                cancelled (threading.Event | None): Unused cancellation signal.

            Returns:
                dict[str, linking.JsonValue]: Verified envelope with counts,
                    gallery identity, timestamps, evidence and an empty list.
            """
            batch = json.loads(body)['files']
            calls.append(batch)
            return {'ok': True, 'action': 'inventory', 'gallery_id': 7,
                    'gallery_title': 'Gallery', 'checked': len(batch), 'matched': 0,
                    'existing': [], 'deep_check_applied': False,
                    'fingerprint': 'fingerprint', 'server_time': 'time',
                    'inventory_evidence': 'verified_file_v1'}

        with mock.patch.object(linking, '_request_json', side_effect=response):
            result = session.inventory(7, candidates)
        self.assertEqual([8, 2], [len(batch) for batch in calls])
        self.assertEqual(10, result['checked'])
        self.assertEqual([], result['existing'])
        calls.clear()
        large = [{**candidates[0], 'size': 80000000},
                 {**candidates[1], 'size': 80000000},
                 {**candidates[2], 'size': 200000000}]
        with mock.patch.object(linking, '_request_json', side_effect=response):
            session.inventory(7, large)
        self.assertEqual([1, 1, 1], [len(batch) for batch in calls])
        calls.clear()

        def fail_second(
            issuer: str, route: str, method: str, body: bytes, bearer: str | None,
            *, cancelled: threading.Event | None = None,
        ) -> dict[str, linking.JsonValue]:
            """Fail after the first otherwise valid batch has completed.

            Args:
                issuer (str): Trusted synthetic gallery issuer.
                route (str): Fixed synthetic inventory route.
                method (str): Synthetic HTTP method.
                body (bytes): JSON body containing file candidate objects.
                bearer (str | None): Synthetic bearer credential.
                cancelled (threading.Event | None): Unused cancellation signal.

            Returns:
                dict[str, linking.JsonValue]: The first valid verified inventory
                    envelope, including one synthetic positive match.

            Raises:
                linking.LinkingError: The second batch is unavailable.
            """
            if calls:
                raise linking.LinkingError('Unavailable.')
            first = response(issuer, route, method, body, bearer, cancelled=cancelled)
            first['existing'] = [{
                **calls[0][0], 'remote_filename': 'stored.jpg',
                'remote_relative_path': 'stored.jpg', 'remote_image_id': 22,
                'matched_by': 'checksum_sha256',
            }]
            first['matched'] = 1
            return first

        with mock.patch.object(linking, '_request_json', side_effect=fail_second):
            with self.assertRaises(linking.LinkingError):
                session.inventory(7, candidates)
        with mock.patch.object(linking, 'LINKING_INVENTORY_TOTAL_SECONDS', 0), \
                mock.patch.object(linking, '_request_json') as request:
            with self.assertRaises(linking.LinkingError):
                session.inventory(7, candidates)
            request.assert_not_called()

    def test_recovery_probe_exact_wire_and_refusals(self) -> None:
        """Accept only one scoped observation and its exact response envelope.

        Returns:
            None: Rejects malformed requests and untrusted or legacy responses.
        """
        session = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 60, linking.UPLOAD_SCOPE,
        )
        digest = 'a' * 64
        response = {'ok': True, 'action': 'recovery', 'gallery_id': 7,
                    'result': 'present'}
        with mock.patch.object(linking, '_request_json', return_value=response) as request:
            self.assertEqual('present', session.probe_uncertain_upload(7, digest, 1024))
            request.assert_called_once()
            self.assertEqual({
                'action': 'inventory', 'gallery_id': 7, 'deep_check': True,
                'files': [{'client_id': digest, 'filename': 'recovery.jpg',
                           'size': 1024, 'sha256': digest}],
            }, json.loads(request.call_args.args[3]))
        with mock.patch.object(linking, '_request_json', return_value={
            **response, 'result': 'unknown',
        }) as request:
            self.assertEqual('unknown', session.probe_uncertain_upload(7, digest, 1024))
            request.assert_called_once()
        for bad in (
            {}, {'ok': True, 'action': 'inventory', 'gallery_id': 7, 'existing': []},
            {**response, 'gallery_id': 8}, {**response, 'result': 'absent'},
            {**response, 'extra': True},
        ):
            with self.subTest(response=bad), mock.patch.object(
                linking, '_request_json', return_value=bad,
            ):
                with self.assertRaises(linking.LinkingError):
                    session.probe_uncertain_upload(7, digest, 1024)
        for size in (0, -1, True, linking.LINKING_RECOVERY_MAX_FILE_BYTES + 1):
            with self.subTest(size=size), mock.patch.object(linking, '_request_json') as request:
                with self.assertRaises(linking.LinkingError):
                    session.probe_uncertain_upload(7, digest, size)
                request.assert_not_called()
        without_upload = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 60, linking.GALLERIES_SCOPE,
        )
        with mock.patch.object(linking, '_request_json') as request:
            with self.assertRaises(linking.LinkingError):
                without_upload.probe_uncertain_upload(7, digest, 1024)
            request.assert_not_called()

    def test_upload_scopes_retain_access_without_widening(self) -> None:
        """Preserve upload for all exact combined and offline grants.

        Returns:
            None: Only upload-bearing registered scopes reach the transport.
        """
        accepted = (
            linking.UPLOAD_SCOPE, linking.UPLOAD_OFFLINE_SCOPE,
            linking.READ_UPLOAD_SCOPE, linking.READ_UPLOAD_OFFLINE_SCOPE,
            linking.UPLOAD_CREATE_SCOPE, linking.UPLOAD_CREATE_OFFLINE_SCOPE,
            linking.READ_UPLOAD_CREATE_SCOPE,
            linking.READ_UPLOAD_CREATE_OFFLINE_SCOPE,
            linking.UPLOAD_VISIBILITY_SCOPE,
            linking.UPLOAD_VISIBILITY_OFFLINE_SCOPE,
            linking.UPLOAD_CREATE_VISIBILITY_SCOPE,
            linking.UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
        )
        rejected = (
            linking.IDENTITY_SCOPE, linking.GALLERIES_SCOPE, linking.READ_SCOPE,
            linking.CREATE_SCOPE, linking.READ_CREATE_SCOPE,
            linking.VISIBILITY_SCOPE, linking.CREATE_VISIBILITY_SCOPE,
        )
        with mock.patch.object(linking, '_request_json', return_value={}) as request:
            for scope in accepted:
                with self.subTest(scope=scope):
                    session = linking.LinkingSession(
                        'https://gallery.example', 'pgla_' + 'A' * 43,
                        time.monotonic() + 60, scope,
                    )
                    self.assertTrue(session.can_upload())
                    self.assertEqual({}, session._request('app_linking_inventory', body=b'{}'))
            self.assertEqual(len(accepted), request.call_count)
            for scope in rejected:
                with self.subTest(scope=scope):
                    session = linking.LinkingSession(
                        'https://gallery.example', 'pgla_' + 'A' * 43,
                        time.monotonic() + 60, scope,
                    )
                    self.assertFalse(session.can_upload())
                    with self.assertRaises(linking.LinkingError):
                        session._request('app_linking_inventory', body=b'{}')
            self.assertEqual(len(accepted), request.call_count)
        for scope in ('link:identity gallery:upload', linking.UPLOAD_SCOPE + ' extra'):
            self.assertIsNone(linking.scope_base(scope))

    def test_visibility_bundles_are_exact_and_keep_existing_scope_meaning(self) -> None:
        """Admit the eight explicit bundles without granting old scopes writes.

        Returns:
            None: Exact bundles retain read, upload and create distinctions.
        """
        self.assertEqual(8, len(set(linking.VISIBILITY_SCOPES)))
        for scope in linking.VISIBILITY_SCOPES:
            with self.subTest(scope=scope):
                self.assertTrue(linking.scope_allows_gallery_visibility(scope))
                self.assertTrue(linking.scope_allows_gallery_read(scope))
                self.assertIsNotNone(linking.scope_base(scope))
                self.assertFalse(linking.scope_allows_gallery_visibility(scope + ' extra'))
        for scope in (linking.READ_SCOPE, linking.READ_UPLOAD_SCOPE,
                      linking.READ_CREATE_SCOPE, linking.IDENTITY_SCOPE):
            self.assertFalse(linking.scope_allows_gallery_visibility(scope))
        self.assertEqual(linking.UPLOAD_SCOPE,
                         linking.scope_base(linking.UPLOAD_CREATE_VISIBILITY_SCOPE))
        self.assertTrue(linking.scope_allows_gallery_create(
            linking.CREATE_VISIBILITY_OFFLINE_SCOPE,
        ))
        self.assertFalse(linking.scope_allows_gallery_create(linking.VISIBILITY_SCOPE))
        self.assertEqual((
            linking.IDENTITY_OFFLINE_SCOPE, linking.GALLERIES_OFFLINE_SCOPE,
            linking.UPLOAD_OFFLINE_SCOPE, linking.READ_OFFLINE_SCOPE,
            linking.READ_UPLOAD_OFFLINE_SCOPE, linking.CREATE_OFFLINE_SCOPE,
            linking.READ_CREATE_OFFLINE_SCOPE, linking.UPLOAD_CREATE_OFFLINE_SCOPE,
            linking.READ_UPLOAD_CREATE_OFFLINE_SCOPE,
        ), offline_linking._OFFLINE_SCOPES[:9])
        self.assertEqual((
            linking.VISIBILITY_OFFLINE_SCOPE, linking.UPLOAD_VISIBILITY_OFFLINE_SCOPE,
            linking.CREATE_VISIBILITY_OFFLINE_SCOPE,
            linking.UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
        ), offline_linking._OFFLINE_SCOPES[9:])

    def test_visibility_uses_live_detail_once_and_preserves_other_settings(self) -> None:
        """Bind one POST to readback and forbid replay after any uncertain reply.

        Returns:
            None: Exact wire, precondition, response and fail-closed replay hold.
        """
        session = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 60, linking.VISIBILITY_SCOPE,
        )
        source = {'gallery': {'id': 42, 'title': 'Gallery', 'revision': '7',
                              'visibility': 'private', 'access_mode': 'password',
                              'access_listing': 'unlisted'}}
        result = {'gallery': {'id': 42, 'revision': '8', 'visibility': 'draft',
                              'access_mode': 'password', 'access_listing': 'unlisted'}}
        reread = {'gallery': {**result['gallery'], 'title': 'Gallery'}}
        with mock.patch.object(session, '_request', side_effect=[source, result, reread]) as request:
            detail = session.gallery_detail(42)
            saved = session.change_gallery_visibility(detail, 'unpublished')
            self.assertEqual('draft', saved.visibility)
            self.assertEqual('8', saved.revision)
            args, kwargs = request.call_args_list[1]
            self.assertEqual('app_linking_gallery_visibility', args[0])
            self.assertEqual({'gallery_id': 42, 'expected_revision': '7',
                              'visibility': 'unpublished'}, json.loads(kwargs['body']))
            with self.assertRaises(linking.LinkingError):
                session.change_gallery_visibility(detail, 'public')
            self.assertEqual(3, request.call_count)
            self.assertEqual('app_linking_gallery_detail', request.call_args.args[0])
        with mock.patch.object(session, '_request', side_effect=[source, result, source]) as request:
            detail = session.gallery_detail(42)
            with self.assertRaises(linking.LinkingError):
                session.change_gallery_visibility(detail, 'unpublished')
            self.assertIsNone(session._visibility_snapshot)
            self.assertEqual(3, request.call_count)
        with mock.patch.object(session, '_request', side_effect=[
            source, linking.LinkingError('uncertain'),
        ]) as request:
            detail = session.gallery_detail(42)
            with self.assertRaises(linking.LinkingError):
                session.change_gallery_visibility(detail, 'public')
            with self.assertRaises(linking.LinkingError):
                session.change_gallery_visibility(detail, 'public')
            self.assertEqual(2, request.call_count)
        with mock.patch.object(session, '_request', side_effect=[source, {
            'gallery': {**result['gallery'], 'access_listing': 'listed'},
        }]):
            detail = session.gallery_detail(42)
            with self.assertRaises(linking.LinkingError):
                session.change_gallery_visibility(detail, 'unpublished')
            with self.assertRaises(linking.LinkingError):
                session.change_gallery_visibility(detail, 'public')
        for reason, error in (
            ('scheduled_publication_active', linking.LinkingScheduledPublication),
            ('revision_conflict', linking.LinkingRevisionConflict),
        ):
            with self.subTest(reason=reason), mock.patch.object(
                session, '_request', side_effect=[
                    source, {'__client_visibility_conflict': reason},
                ],
            ) as request:
                detail = session.gallery_detail(42)
                with self.assertRaises(error):
                    session.change_gallery_visibility(detail, 'public')
                with self.assertRaises(linking.LinkingError):
                    session.change_gallery_visibility(detail, 'public')
                self.assertEqual(2, request.call_count)

    def test_cancel_before_dispatch_and_noop_do_not_send(self) -> None:
        """Keep cancelled or unchanged selections away from the mutation route.

        Returns:
            None: No POST occurs before an explicit distinct live change.
        """
        session = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 60, linking.VISIBILITY_SCOPE,
        )
        source = {'gallery': {'id': 42, 'title': 'Gallery', 'revision': '7',
                              'visibility': 'draft', 'access_mode': 'normal',
                              'access_listing': 'listed'}}
        with mock.patch.object(session, '_request', return_value=source) as request:
            detail = session.gallery_detail(42)
            with self.assertRaises(linking.LinkingError):
                session.change_gallery_visibility(detail, 'unpublished')
            cancelled = threading.Event()
            cancelled.set()
            with self.assertRaises(linking.LinkingError):
                session.change_gallery_visibility(detail, 'public', cancelled)
            self.assertEqual(1, request.call_count)


class NativeMediaSessionTests(unittest.TestCase):
    """Exercise native read scope and server-selected resource identities."""

    def test_native_media_requires_explicit_transient_scope(self) -> None:
        """Keep upload authority and refuse media or offline elevation.

        Returns:
            None: Unknown and persistent media scope combinations are refused.
        """
        issuer = 'https://gallery.example/Galerie'
        token = 'pgla_' + 'A' * 43
        scope = linking.UPLOAD_SCOPE + linking.MEDIA_SCOPE_SUFFIX
        session = linking.LinkingSession(
            issuer, token, time.monotonic() + 60, scope,
        )
        self.assertTrue(session.can_upload())
        self.assertTrue(session.can_read_media())
        self.assertFalse(session.can_read_presentation())
        with mock.patch.object(linking, '_run_linking_transport') as request:
            with self.assertRaises(linking.LinkingError):
                session.media_presentation(42)
            with self.assertRaises(linking.LinkingError):
                session.media_photo(42, True, False)
            request.assert_not_called()
        for invalid in (
            scope + ' offline_access',
            linking.IDENTITY_SCOPE + linking.MEDIA_SCOPE_SUFFIX,
            linking.UPLOAD_SCOPE + ' presentation:read',
            scope + linking.MEDIA_SCOPE_SUFFIX,
        ):
            with self.subTest(scope=invalid), self.assertRaises(linking.LinkingError):
                linking.LinkingSession(issuer, token, time.monotonic() + 60, invalid)

    def test_native_media_cursor_and_track_cannot_substitute_resource(self) -> None:
        """Validate the requested page ID and the exact selected audio bytes.

        Returns:
            None: Optional metadata is tolerated while binding changes fail closed.
        """
        session = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 60,
            linking.GALLERIES_SCOPE + linking.PRESENTATION_SCOPE_SUFFIX,
        )
        good = {'gallery_id': 42, 'parent_id': 8,
                'photos': [{'id': 100, 'title': 'Fixture', 'optional': True}],
                'next_after': None, 'optional': 'supported'}
        for page in (good, {**good, 'gallery_id': 8}, {**good, 'next_after': 100}):
            with self.subTest(page=page), mock.patch.object(
                session, '_media_read', return_value=(json.dumps(page).encode(), 'application/json'),
            ):
                if page == good:
                    self.assertEqual((linking.LinkedPhoto(100, 'Fixture'),),
                                     session.media_page(42, 99).photos)
                else:
                    with self.assertRaises(linking.LinkingError):
                        session.media_page(42, 99)
        body = b'fixture audio'
        track = linking.LinkedTrack(
            'a' * 32, 'Tone', 'audio/wav', len(body), hashlib.sha256(body).hexdigest(),
        )
        with mock.patch.object(session, '_media_read', return_value=(body, track.mime)) as read:
            self.assertEqual(body, session.media_track(42, track))
            read.assert_called_once_with(42, 'track', track.track_id, None)
            read.return_value = (b'different abc', track.mime)
            with self.assertRaises(linking.LinkingError):
                session.media_track(42, track)


@unittest.skipIf(LinkingManager is None, 'Tkinter is unavailable')
class LinkingDeliveryTests(unittest.TestCase):
    """Exercise worker-event delivery without opening a Tk window."""

    def test_address_dialog_accepts_copied_addresses(self) -> None:
        """Exercise cancellation, correction and submission from the prompt.

        Returns:
            None: Only a valid submitted address starts an attempt, using the
                exact installation path and keeping the prompt for feedback.
        """
        known = 'https://previous.example/galerie'
        for action, value, issuer in (
            ('Cancel', 'https://gallery.example/Galerie/', ''),
            ('Link gallery', 'http://gallery.example/Galerie/',
             'https://gallery.example/Galerie'),
            ('Link gallery', 'http://localhost:8888/galerie/',
             'http://localhost:8888/galerie'),
        ):
            with self.subTest(action=action, value=value):
                manager = object.__new__(LinkingManager)
                manager._root = mock.Mock()
                manager._worker = None
                manager._window = None
                manager._session = None
                manager._session_issuer = ''
                manager._current_issuer = known
                manager._status = mock.Mock()
                manager._refresh = mock.Mock()
                manager._start = mock.Mock()
                confirm = mock.Mock()
                confirm.instate.return_value = False
                hint = mock.Mock()
                with (
                    mock.patch('uploader.app_linking_ui.tk') as tk,
                    mock.patch('uploader.app_linking_ui.ttk') as widgets,
                    mock.patch('uploader.app_linking_ui.GlassCard'),
                    mock.patch('uploader.app_linking_ui._LinkAddressChoices'),
                    mock.patch('uploader.app_linking_ui.icon_button'),
                    mock.patch('uploader.app_linking_ui.place_dialog'),
                ):
                    widgets.Button.side_effect = [confirm, mock.Mock()]
                    widgets.Label.side_effect = [mock.Mock(), hint]
                    dialog = tk.Toplevel.return_value
                    # Supply the dimensions of a compact native prompt.
                    dialog.winfo_reqwidth.return_value = 380
                    dialog.winfo_reqheight.return_value = 220
                    field = tk.StringVar.return_value
                    field.get.return_value = known
                    manager.open_address_dialog()
                    tk.StringVar.assert_called_once_with(dialog, value=known)
                    command = next(
                        call.kwargs['command']
                        for call in widgets.Button.call_args_list
                        if call.kwargs['text'] == action
                    )
                    manager._start.assert_not_called()
                    if action == 'Link gallery':
                        field.get.return_value = 'http://localhost@external.example/Galerie'
                        command()
                        dialog.destroy.assert_not_called()
                        manager._start.assert_not_called()
                        self.assertEqual(known, manager.address())
                    field.get.return_value = value
                    command()
                    if action == 'Link gallery':
                        dialog.destroy.assert_not_called()
                        hint.configure.assert_called_with(
                            textvariable=manager._status, style='Status.TLabel',
                        )
                        manager._start.assert_called_once_with(issuer, direct=True)
                        self.assertEqual(issuer, manager.address())
                    else:
                        dialog.destroy.assert_called_once_with()
                        manager._start.assert_not_called()
                        self.assertEqual(known, manager.address())

    def test_manage_link_can_request_its_own_address_after_restart(self) -> None:
        """Keep the saved-link management entry reachable on a fresh start.

        Returns:
            None: Management asks for the issuer when none is known, without
                starting a new approval attempt or selecting a legacy address.
        """
        manager = object.__new__(LinkingManager)
        manager._window = None
        manager._worker = None
        manager.open_address_dialog = mock.Mock()
        manager.link = mock.Mock()

        manager.open(mock.Mock(), '', manage=True)

        manager.open_address_dialog.assert_called_once_with(manage=True)
        manager.link.assert_not_called()

    def test_approved_session_fetches_one_initial_page_when_list_is_granted(self) -> None:
        """Queue one initial page after identity without selecting a gallery.

        Returns:
            None: Listing sessions load once; identity-only sessions do not list;
                list failure leaves the verified identity connected.
        """
        issuer = 'https://gallery.example/Galerie'
        page = linking.LinkedGalleryPage((
            linking.LinkedGallery(7, None, 'Gallery'),
        ), None)
        for mode in ('direct', 'listing', 'identity', 'listing_error'):
            with self.subTest(mode=mode):
                manager = object.__new__(LinkingManager)
                manager._session = None
                manager._session_issuer = ''
                manager._linked_address = mock.Mock()
                manager._session_user_id = 0
                manager._current_issuer = issuer
                manager._worker = None
                manager._offline_store = None
                manager._offline_binding = None
                manager._offline_path = Path('/fixture/offline-link.json')
                manager._browse_var = mock.Mock()
                manager._browse_var.get.return_value = mode != 'identity'
                for name in (
                    '_upload_var', '_read_var', '_create_var',
                    '_visibility_var', '_offline_var',
                ):
                    variable = mock.Mock()
                    variable.get.return_value = False
                    setattr(manager, name, variable)
                manager._events = queue.Queue()
                manager._status = mock.Mock()
                manager._gallery_status = mock.Mock()
                manager._gallery_choice = mock.MagicMock()
                manager._gallery_page = None
                manager._selected_gallery = None
                manager._detail_snapshot = None
                manager._handoff = None
                manager._window = None if mode == 'direct' else mock.Mock()
                manager._root = mock.Mock()
                manager._upload_workspace_ready = mock.Mock(return_value=False)
                manager._refresh = mock.Mock()
                manager._schedule_poll = mock.Mock()
                session = mock.Mock(spec=linking.LinkingSession)
                session.can_list_galleries.return_value = mode != 'identity'
                session.can_upload.return_value = False
                session.identity.return_value = linking.LinkedIdentity(17, 'admin')
                session.gallery_page.return_value = page
                if mode == 'listing_error':
                    session.gallery_page.side_effect = linking.LinkingError(
                        'fixture list unavailable',
                    )
                attempt = mock.Mock(spec=linking.LinkingAttempt)
                attempt.push_request.return_value = linking.PushedAuthorization(
                    TEST_REQUEST_HANDLE,
                    issuer + '/index.php?page=app_linking_start'
                    + '&request_handle=' + TEST_REQUEST_HANDLE,
                    time.monotonic() + linking.HANDOFF_MAX_LIFETIME_SECONDS,
                )
                attempt.wait_for_callback.return_value = 'approved'
                attempt.exchange_once.return_value = session
                thread = mock.Mock()
                thread.is_alive.return_value = False
                with mock.patch(
                    'uploader.app_linking_ui.LinkingAttempt', return_value=attempt,
                ) as factory, mock.patch(
                    'uploader.app_linking_ui.threading.Thread', return_value=thread,
                ) as thread_factory, mock.patch(
                    'uploader.app_linking_ui.webbrowser.open', return_value=True,
                ) as open_browser:
                    if mode == 'direct':
                        manager.link(issuer)
                        factory.assert_called_once_with(issuer, scope=linking.GALLERIES_SCOPE)
                        self.assertIsNone(manager._window)
                    else:
                        manager._start(issuer)
                    thread_factory.call_args.kwargs['target']()
                    kinds = [event[1] for event in manager._events.queue]
                    self.assertEqual('handoff', kinds[0])
                    self.assertIn('connected', kinds)
                    if mode == 'identity':
                        self.assertNotIn('galleries', kinds)
                        self.assertNotIn('galleries_error', kinds)
                        session.gallery_page.assert_not_called()
                    elif mode == 'listing_error':
                        self.assertLess(
                            kinds.index('connected'), kinds.index('galleries_error'),
                        )
                        session.gallery_page.assert_called_once_with(
                            None, cancelled=manager._cancel,
                        )
                    else:
                        self.assertLess(
                            kinds.index('connected'), kinds.index('galleries'),
                        )
                        session.gallery_page.assert_called_once_with(
                            None, cancelled=manager._cancel,
                        )
                    manager._poll()
                    open_browser.assert_called_once_with(
                        attempt.push_request.return_value.authorization_url,
                    )
                self.assertIs(manager._session, session)
                self.assertIsNone(manager._selected_gallery)
                manager._linked_address.set.assert_called_once_with(issuer)
                if mode == 'direct':
                    self.assertIs(manager._gallery_page, page)
                    manager._gallery_choice.set_rows.assert_not_called()
                    manager._status.set.assert_called_with('')
                elif mode == 'listing_error':
                    self.assertIsNone(manager._gallery_page)
                    self.assertIn('Could not load', manager._gallery_status.set.call_args.args[0])
                elif mode == 'listing':
                    self.assertIs(manager._gallery_page, page)
                    manager._gallery_choice.current.assert_not_called()
                    manager._gallery_choice.set_rows.assert_called_with(('Gallery (#7)',))
                session.close()

    def test_browser_handoff_opens_automatically_and_remains_retryable(self) -> None:
        """Open consent in the browser without displaying the correlation handle.

        Returns:
            None: The queued handoff opens once automatically and again only
                when the user retries the browser action.
        """
        manager = object.__new__(LinkingManager)
        manager._polling = False
        manager._owner = object()
        manager._window = mock.Mock()
        manager._session = None
        manager._worker = None
        manager._handoff = None
        manager._current_issuer = 'https://gallery.example/Galerie'
        manager._cancel = threading.Event()
        manager._attempt = mock.Mock()
        manager._events = queue.Queue()
        manager._status = mock.Mock()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        handoff = linking.PushedAuthorization(
            TEST_REQUEST_HANDLE,
            'https://gallery.example/Galerie/index.php?page=app_linking_start'
            + '&request_handle=' + TEST_REQUEST_HANDLE,
            time.monotonic() + linking.HANDOFF_MAX_LIFETIME_SECONDS,
        )
        manager._events.put((manager._owner, 'handoff', handoff))
        with mock.patch(
            'uploader.app_linking_ui.webbrowser.open', return_value=True,
        ) as open_browser:
            manager._poll()
            self.assertIs(handoff, manager._handoff)
            open_browser.assert_called_once_with(handoff.authorization_url)
            manager._status.set.assert_called_with(
                tr('en', 'linking_approve_browser'),
            )
            manager._open_browser()
        self.assertEqual(2, open_browser.call_count)
        self.assertNotIn(TEST_REQUEST_HANDLE, manager._status.set.call_args.args[0])

    def test_linking_language_lookup_is_delayed(self) -> None:
        """Read the latest UI language when each new linking label is needed.

        Returns:
            None: A manager follows changes made after it was constructed.
        """
        manager = object.__new__(LinkingManager)
        selected = ['en']
        manager._language = lambda: selected[0]
        english = manager._text('linking_title')
        selected[0] = 'cs'
        self.assertEqual('Connect PHP Gallery', english)
        czech = manager._text('linking_title')
        self.assertEqual(tr('cs', 'linking_title'), czech)
        self.assertNotEqual(english, czech)

    def test_stale_recovery_event_is_never_published(self) -> None:
        """Discard an observation after the dialog closes or owner changes.

        Returns:
            None: Neither stale result reaches the journal publication guard.
        """
        for closed in (False, True):
            with self.subTest(closed=closed):
                manager = object.__new__(LinkingManager)
                manager._polling = False
                manager._owner = object()
                manager._direct_owner = None
                manager._window = None if closed else mock.Mock()
                manager._session = None
                manager._worker = None
                manager._handoff = None
                manager._current_issuer = 'https://gallery.example'
                manager._events = queue.Queue()
                manager._events.put((manager._owner if closed else object(), 'recovery_observed',
                                     (mock.Mock(), 'present', 'a' * 64)))
                manager._recovery_publish = mock.Mock()
                manager._refresh = mock.Mock()
                manager._schedule_poll = mock.Mock()
                manager._poll()
                manager._recovery_publish.assert_not_called()

    def test_review_needs_confirmation_for_the_same_gallery(self) -> None:
        """Send the inspected marker only after confirmation for its target.

        Returns:
            None: Cancellation and target changes never persist a review.
        """
        target = mock.Mock(title='Original gallery')
        marker = 'a' * 64
        for confirm, change_target in ((False, False), (True, True), (True, False)):
            with self.subTest(confirm=confirm, change_target=change_target):
                manager = object.__new__(LinkingManager)
                manager._window = mock.Mock()
                manager._worker = None
                manager._gallery_status = mock.Mock()
                manager._recovery_review = mock.Mock(return_value=marker)
                manager.selected_upload_target = mock.Mock(side_effect=(
                    target, mock.Mock() if change_target else target,
                ))
                with mock.patch('uploader.app_linking_ui.messagebox.askyesno',
                                return_value=confirm) as ask:
                    manager._review_uncertain_upload()
                self.assertEqual('no', ask.call_args.kwargs['default'])
                self.assertIn(target.title, ask.call_args.args[1])
                expected = [mock.call(target, None)]
                if confirm and not change_target:
                    expected.append(mock.call(target, marker))
                self.assertEqual(expected, manager._recovery_review.call_args_list)

    def test_resume_confirms_the_original_intent_before_dispatch(self) -> None:
        """Review the original title and parent before starting same-key work.

        Returns:
            None: Cancellation and a changed account never dispatch a resume.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_create_gallery.return_value = True
        issuer = 'https://gallery.example/Galerie'
        intent = CreateIntent(issuer, 7, 'a' * 64, 'Original', 9)
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._worker = None
        manager._window = mock.Mock()
        manager._root = mock.Mock()
        manager._polling = False
        manager._shutting_down = False
        manager._handoff = None
        manager._events = queue.Queue()
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._create_journal.pending_intent.return_value = intent
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._start_gallery_create = mock.Mock()
        with mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                mock.patch('uploader.app_linking_ui.messagebox.askyesno',
                           return_value=False) as confirm:
            manager._resume_gallery_create()
            thread.call_args.kwargs['target']()
            manager._poll()
            self.assertIn('Original', confirm.call_args.args[1])
            self.assertIn('gallery #9', confirm.call_args.args[1])
            manager._start_gallery_create.assert_not_called()
            manager._worker = None
            manager._resume_gallery_create()
            thread.call_args.kwargs['target']()
            manager._session_user_id = 8
            manager._poll()
            manager._start_gallery_create.assert_not_called()
            manager._session_user_id = 7
            manager._worker = None
            confirm.return_value = True
            manager._resume_gallery_create()
            thread.call_args.kwargs['target']()
            manager._poll()
        manager._start_gallery_create.assert_called_once_with(session, None, None, intent)

    def test_abandon_warns_about_existing_gallery_and_files(self) -> None:
        """Require explicit closure confirmation for the reviewed original.

        Returns:
            None: The confirmation warns about artifacts and passes the intent.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_create_gallery.return_value = True
        issuer = 'https://gallery.example/Galerie'
        intent = CreateIntent(issuer, 7, 'a' * 64, 'Original', None)
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._worker = None
        manager._window = mock.Mock()
        manager._root = mock.Mock()
        manager._polling = False
        manager._shutting_down = False
        manager._handoff = None
        manager._events = queue.Queue()
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._create_journal.pending_intent.return_value = intent
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._start_gallery_create = mock.Mock()
        with mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                mock.patch('uploader.app_linking_ui.messagebox.askyesno',
                           return_value=True) as confirm:
            manager._abandon_gallery_create()
            thread.call_args.kwargs['target']()
            manager._poll()
        self.assertIn('gallery or files may already exist', confirm.call_args.args[1])
        self.assertIn('Original', confirm.call_args.args[1])
        manager._start_gallery_create.assert_called_once_with(
            session, None, None, abandon_intent=intent,
        )

    def test_confirmed_abandon_does_not_start_another_creation(self) -> None:
        """Report possible artifacts without changing the saved upload target.

        Returns:
            None: Closure only updates the status and never starts another create.
        """
        manager = object.__new__(LinkingManager)
        manager._owner = object()
        manager._window = mock.Mock()
        manager._session = None
        manager._worker = None
        manager._handoff = None
        manager._polling = False
        manager._shutting_down = False
        manager._events = queue.Queue()
        manager._events.put((manager._owner, 'create_abandoned', None))
        manager._gallery_status = mock.Mock()
        manager._current_issuer = 'https://gallery.example'
        manager._refresh = mock.Mock()
        manager._start_gallery_create = mock.Mock()
        manager._active_upload_target = object()
        target = manager._active_upload_target
        manager._poll()
        manager._start_gallery_create.assert_not_called()
        self.assertIs(manager._active_upload_target, target)
        self.assertIn('gallery or files may already exist',
                      manager._gallery_status.set.call_args.args[0])

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_created_receipt_selection_obeys_live_session_and_host_guard(self) -> None:
        """Select a receipt only through the current permitted upload session.

        Returns:
            None: A rejected guard or expired session leaves the target alone.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.can_create_gallery.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Created')
        bound = BoundCreateReceipt(gallery, 'a' * 64)
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (bound, session, issuer, 7)
        manager._gallery_cancel = threading.Event()
        manager._create_journal = mock.Mock()
        manager._create_journal.apply_receipt.side_effect = (
            lambda _issuer, _account, _bound, apply: apply()
        )
        manager._active_upload_target = None
        manager._selected_gallery = None
        manager._gallery_status = mock.Mock()
        manager._target_refusal = mock.Mock(return_value='Pending upload work.')
        manager._notify_target_changed = mock.Mock()
        manager._commit_linked_target = mock.Mock(return_value=True)
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Created')
        manager._apply_created_gallery(
            bound, target, session, issuer, 7, linking.UPLOAD_SCOPE,
            manager._gallery_cancel,
        )
        self.assertIsNone(manager._active_upload_target)
        manager._target_refusal.return_value = None
        session.can_upload.return_value = False
        manager._apply_created_gallery(
            bound, target, session, issuer, 7, linking.UPLOAD_SCOPE,
            manager._gallery_cancel,
        )
        self.assertIsNone(manager._active_upload_target)
        session.can_upload.return_value = True
        manager._apply_created_gallery(
            bound, target, session, issuer, 7, linking.UPLOAD_SCOPE,
            manager._gallery_cancel,
        )
        self.assertIs(manager._active_upload_target, target)
        self.assertEqual(manager._selected_gallery, gallery)
        manager._commit_linked_target.assert_called_once_with(target)
        manager._notify_target_changed.assert_called_once_with()

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_created_target_is_revalidated_before_local_selection(self) -> None:
        """Require fresh server-backed status before selecting a saved receipt.

        Returns:
            None: Unknown status never invokes the target-change guard.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.can_create_gallery.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Created')
        bound = BoundCreateReceipt(gallery, 'a' * 64)
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (bound, session, issuer, 7)
        manager._worker = None
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._create_journal.reconcile.return_value = None
        manager._events = queue.Queue()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._target_refusal = mock.Mock()
        manager._commit_linked_target = mock.Mock()
        with mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                mock.patch('uploader.app_linking_ui.verify_linked_upload_target') as verify:
            manager._use_created_gallery()
            late_worker = thread.call_args.kwargs['target']
            late_worker()
        self.assertEqual('create_selection_error', manager._events.get_nowait()[1])
        verify.assert_not_called()
        manager._target_refusal.assert_not_called()
        manager._commit_linked_target.assert_not_called()
        manager._create_journal.reconcile.assert_called_once_with(session, issuer, 7)

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_created_target_rolls_back_when_host_notification_fails(self) -> None:
        """Keep confirmed mode active if a target-change notification fails.

        Returns:
            None: The persistent selection is not falsely rolled back by Tk failure.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.can_create_gallery.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Created')
        bound = BoundCreateReceipt(gallery, 'a' * 64)
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (bound, session, issuer, 7)
        manager._gallery_cancel = threading.Event()
        previous_target = mock.Mock()
        previous_gallery = linking.LinkedGallery(3, None, 'Previous')
        manager._active_upload_target = previous_target
        manager._selected_gallery = previous_gallery
        manager._gallery_status = mock.Mock()
        manager._target_refusal = mock.Mock(return_value=None)
        manager._commit_linked_target = mock.Mock(return_value=True)
        manager._notify_target_changed = mock.Mock(side_effect=[RuntimeError('UI error'), None])
        manager._create_journal = mock.Mock()
        manager._create_journal.apply_receipt.side_effect = (
            lambda _issuer, _account, _bound, apply: apply()
        )
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Created')
        manager._apply_created_gallery(bound, target, session, issuer, 7, linking.UPLOAD_SCOPE, manager._gallery_cancel)
        self.assertIs(target, manager._active_upload_target)
        self.assertEqual(gallery, manager._selected_gallery)
        manager._commit_linked_target.assert_called_once_with(target)
        manager._notify_target_changed.assert_called_once()
        self.assertIn('display did not refresh',
                      manager._gallery_status.set.call_args.args[0])

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_created_target_rejects_same_gallery_from_a_different_intent(self) -> None:
        """Refuse a fresh receipt for another operation with identical gallery fields.

        Returns:
            None: No stale result can select a target or pass the host guard.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.can_create_gallery.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Created')
        first = BoundCreateReceipt(gallery, 'a' * 64)
        second = BoundCreateReceipt(gallery, 'b' * 64)
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (first, session, issuer, 7)
        manager._worker = None
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._create_journal.reconcile.return_value = second
        manager._events = queue.Queue()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._target_refusal = mock.Mock()
        manager._commit_linked_target = mock.Mock()
        with mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                mock.patch('uploader.app_linking_ui.verify_linked_upload_target') as verify:
            manager._use_created_gallery()
            thread.call_args.kwargs['target']()
        self.assertEqual('create_selection_error', manager._events.get_nowait()[1])
        verify.assert_not_called()
        manager._target_refusal.assert_not_called()

    def test_stale_bound_selection_event_cannot_activate_same_gallery(self) -> None:
        """Discard a late selection result for another creation intent.

        Returns:
            None: An identical gallery projection cannot bypass the binding.
        """
        manager = object.__new__(LinkingManager)
        manager._polling = False
        manager._owner = object()
        manager._window = mock.Mock()
        manager._worker = None
        manager._handoff = None
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        manager._session = session
        manager._current_issuer = 'https://gallery.example'
        manager._session_issuer = manager._current_issuer
        manager._session_user_id = 7
        gallery = linking.LinkedGallery(42, None, 'Created')
        first = BoundCreateReceipt(gallery, 'a' * 64)
        second = BoundCreateReceipt(gallery, 'b' * 64)
        manager._created_gallery = (first, session, manager._current_issuer, 7)
        target = LinkedUploadTarget(session, manager._current_issuer, 7, 42, 'Created')
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._gallery_cancel = threading.Event()
        manager._commit_linked_target = mock.Mock()
        manager._events = queue.Queue()
        manager._events.put((
            manager._owner, 'create_selection_verified',
            (second, target, session, manager._current_issuer, 7,
             linking.UPLOAD_SCOPE, manager._gallery_cancel),
        ))
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        with mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True), \
                mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True):
            manager._poll()
        manager._create_journal.apply_receipt.assert_not_called()
        manager._commit_linked_target.assert_not_called()

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_selection_rechecks_receipt_after_worker_status(self) -> None:
        """Refuse a target when the journal changes after the worker's read.

        Returns:
            None: A stale status cannot reach the host target-change guard.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.can_create_gallery.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        bound = BoundCreateReceipt(linking.LinkedGallery(42, None, 'Created'), 'a' * 64)
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (bound, session, issuer, 7)
        manager._active_upload_target = None
        manager._selected_gallery = None
        manager._worker = None
        manager._window = mock.Mock()
        manager._polling = False
        manager._handoff = None
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._create_journal.reconcile.return_value = bound
        manager._create_journal.apply_receipt.side_effect = linking.LinkingError('changed')
        manager._events = queue.Queue()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._target_refusal = mock.Mock()
        manager._commit_linked_target = mock.Mock()
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Created')
        with mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                mock.patch('uploader.app_linking_ui.verify_linked_upload_target',
                           return_value=target) as verify:
            manager._use_created_gallery()
            thread.call_args.kwargs['target']()
            manager._poll()
        self.assertIsNone(manager._active_upload_target)
        verify.assert_called_once()
        manager._target_refusal.assert_not_called()
        manager._commit_linked_target.assert_not_called()
        manager._create_journal.apply_receipt.assert_called_once()

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', False)
    def test_create_completion_explains_disabled_upload_and_allows_acknowledgement(self) -> None:
        """Keep the receipt and describe available actions with the gate closed.

        Returns:
            None: The status offers acknowledgement without offering upload.
        """
        manager = object.__new__(LinkingManager)
        manager._polling = False
        manager._owner = object()
        manager._window = mock.Mock()
        manager._worker = None
        manager._handoff = None
        session = mock.Mock(spec=linking.LinkingSession)
        session.available.return_value = True
        session.can_upload.return_value = True
        manager._session = session
        manager._session_issuer = manager._current_issuer = 'https://gallery.example'
        manager._session_user_id = 7
        manager._created_gallery = None
        manager._gallery_status = mock.Mock()
        manager._events = queue.Queue()
        bound = BoundCreateReceipt(linking.LinkedGallery(42, None, 'Created'), 'a' * 64)
        manager._events.put((manager._owner, 'create_completed',
                             (bound, session, manager._current_issuer, 7)))
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._poll()
        self.assertIs(bound, manager._created_gallery[0])
        status = manager._gallery_status.set.call_args.args[0]
        self.assertIn('Linked upload selection is temporarily unavailable', status)
        self.assertIn('Acknowledge the creation to continue', status)
        self.assertNotIn('Use it for upload', status)

    def test_create_completion_requires_the_original_session(self) -> None:
        """Do not attach a completed receipt to a later session's account.

        Returns:
            None: A changed session leaves the saved receipt unselected.
        """
        manager = object.__new__(LinkingManager)
        manager._polling = False
        manager._owner = object()
        manager._window = mock.Mock()
        manager._worker = None
        manager._handoff = None
        manager._session = mock.Mock(spec=linking.LinkingSession)
        old_session = mock.Mock(spec=linking.LinkingSession)
        manager._session_issuer = manager._current_issuer = 'https://gallery.example'
        manager._session_user_id = 7
        manager._created_gallery = None
        manager._gallery_status = mock.Mock()
        manager._events = queue.Queue()
        bound = BoundCreateReceipt(linking.LinkedGallery(42, None, 'Created'), 'a' * 64)
        manager._events.put((manager._owner, 'create_completed',
                             (bound, old_session, manager._current_issuer, 7)))
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._poll()
        self.assertIsNone(manager._created_gallery)
        self.assertIn('reconnect', manager._gallery_status.set.call_args.args[0])

    def test_unknown_creation_guides_explicit_closure(self) -> None:
        """Guide recovery without exposing an operation key or retrying.

        Returns:
            None: The current status names explicit closure and storage review.
        """
        manager = object.__new__(LinkingManager)
        manager._polling = False
        manager._owner = object()
        manager._window = mock.Mock()
        manager._worker = None
        manager._handoff = None
        manager._session = None
        manager._current_issuer = 'https://gallery.example'
        manager._gallery_status = mock.Mock()
        manager._events = queue.Queue()
        manager._events.put((manager._owner, 'create_unknown', None))
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._poll()
        message = manager._gallery_status.set.call_args.args[0]
        self.assertIn('Resume original creation', message)
        self.assertIn('Close unresolved creation', message)
        self.assertIn('gallery and storage', message)
        self.assertIn('Do not create it again', message)

    def test_dialog_actions_reflow_without_replacing_widgets(self) -> None:
        """Wrap labels and preserve all action widgets at narrow widths.

        Returns:
            None: Reuses each button across narrow and desktop layout changes.
        """
        manager = object.__new__(LinkingManager)
        manager._wrapping_labels = (mock.Mock(), mock.Mock())
        manager._dialog_wrap_width = 0
        manager._dialog_layout_columns = 0
        manager._link_actions = mock.Mock()
        manager._scope_actions = mock.Mock()
        manager._manage = True
        manager._gallery_actions = mock.Mock()
        manager._footer_actions = mock.Mock()
        for name in (
            '_browse_check', '_upload_check', '_read_check', '_create_check',
            '_visibility_check', '_media_check', '_offline_check',
            '_start_button', '_browser_button', '_revoke_button',
            '_gallery_refresh_button', '_gallery_next_button',
            '_gallery_select_button', '_recovery_button', '_review_button',
            '_detail_button', '_visibility_choice',
            '_visibility_button', '_media_button', '_create_button',
            '_create_status_button', '_create_resume_button', '_create_abandon_button',
            '_use_created_button', '_ack_created_button',
            '_manage_button',
            '_restore_button', '_discard_button', '_close_button',
        ):
            setattr(manager, name, mock.Mock())

        # At 360 Tk pixels long button labels get their own full-width row.
        manager._apply_dialog_layout(360)
        self.assertEqual(1, manager._dialog_layout_columns)
        manager._wrapping_labels[0].configure.assert_called_with(wraplength=300)
        self.assertEqual(2, manager._revoke_button.grid.call_args.kwargs['row'])
        self.assertEqual(1, manager._revoke_button.grid.call_args.kwargs['columnspan'])
        self.assertEqual(0, manager._recovery_button.grid.call_args.kwargs['row'])
        manager._gallery_select_button.grid.assert_not_called()
        self.assertEqual(3, manager._close_button.grid.call_args.kwargs['row'])

        # A wider window restores one row; no widget or command is replaced.
        revoke = manager._revoke_button
        manager._apply_dialog_layout(640)
        self.assertEqual(3, manager._dialog_layout_columns)
        self.assertIs(revoke, manager._revoke_button)
        self.assertEqual(0, revoke.grid.call_args.kwargs['row'])
        self.assertEqual(2, revoke.grid.call_args.kwargs['column'])

        # An exceptionally narrow window stacks actions rather than hiding them.
        manager._apply_dialog_layout(250)
        self.assertEqual(1, manager._dialog_layout_columns)
        self.assertEqual(2, revoke.grid.call_args.kwargs['row'])
        self.assertEqual(3, manager._close_button.grid.call_args.kwargs['row'])

    def test_offline_initial_save_follows_identity(self) -> None:
        """Store an opted-in refresh only after live identity was verified.

        Returns:
            None: Offline scope and fenced save are explicit and ordered.
        """
        manager = object.__new__(LinkingManager)
        manager._session = None
        manager._worker = None
        manager._offline_store = mock.MagicMock()
        manager._offline_binding = None
        manager._offline_path = Path('/fixture/offline-link.json')
        manager._upload_var = mock.Mock()
        manager._upload_var.get.return_value = True
        manager._browse_var = mock.Mock()
        manager._read_var = mock.Mock()
        manager._read_var.get.return_value = False
        manager._create_var = mock.Mock()
        manager._create_var.get.return_value = False
        manager._visibility_var = mock.Mock()
        manager._visibility_var.get.return_value = False
        manager._offline_var = mock.Mock()
        manager._offline_var.get.return_value = True
        manager._status = mock.Mock()
        manager._events = queue.Queue()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        attempt = mock.MagicMock()
        attempt.wait_for_callback.return_value = 'approved'
        session = mock.MagicMock()
        session.can_list_galleries.return_value = False
        identity = linking.LinkedIdentity(7, 'admin')
        order = []
        session.identity.side_effect = lambda: (order.append('identity'), identity)[1]
        attempt.exchange_offline_once.return_value = (session, 'fixture-refresh', 100)
        with mock.patch('uploader.app_linking_ui.LinkingAttempt', return_value=attempt) as factory, \
                mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                mock.patch('uploader.app_linking_ui.save_offline_binding',
                           side_effect=lambda *_: order.append('hint')), \
                mock.patch('uploader.app_linking_ui.store_approved_refresh',
                           side_effect=lambda *_: order.append('vault')):
            manager._start('https://gallery.example/Galerie')
            worker = thread.call_args.kwargs['target']
            worker()
        self.assertEqual(linking.UPLOAD_OFFLINE_SCOPE, factory.call_args.kwargs['scope'])
        self.assertEqual(['identity', 'hint', 'vault'], order)
        manager._offline_store.binding_transaction.assert_called_once_with(
            'https://gallery.example/Galerie', 7,
        )
        self.assertEqual('connected', list(manager._events.queue)[-1][1])
        session.close.assert_not_called()

    def test_read_scope_requires_listing_and_detail_matches_selection(self) -> None:
        """Keep read consent explicit and ignore stale selected-gallery results.

        Returns:
            None: Only the current selected gallery's stored fields are shown.
        """
        manager = object.__new__(LinkingManager)
        manager._browse_var = mock.Mock()
        manager._upload_var = mock.Mock()
        manager._upload_var.get.return_value = False
        manager._read_var = mock.Mock()
        manager._read_var.get.return_value = True
        manager._visibility_var = mock.Mock()
        manager._visibility_var.get.return_value = False
        manager._scope_changed()
        manager._browse_var.set.assert_called_once_with(True)
        manager._polling = False
        manager._root = mock.Mock()
        manager._shutting_down = False
        manager._events = queue.Queue()
        manager._owner = object()
        manager._window = mock.Mock()
        manager._session = mock.Mock(spec=linking.LinkingSession)
        manager._session_issuer = 'https://gallery.example'
        manager._session_user_id = 7
        manager._visibility_choice = mock.Mock()
        manager._worker = None
        manager._handoff = None
        manager._current_issuer = 'https://gallery.example'
        manager._refresh = mock.Mock()
        manager._gallery_status = mock.Mock()
        manager._gallery_choice = mock.Mock()
        manager._gallery_choice.current.return_value = 0
        manager._gallery_page = linking.LinkedGalleryPage(
            (linking.LinkedGallery(42, None, 'Gallery'),), None,
        )
        detail = linking.LinkedGalleryDetail(
            42, 'Gallery', '7', 'private', 'password', 'unlisted',
        )
        manager._events.put((manager._owner, 'detail', detail))
        manager._poll()
        text = manager._gallery_status.set.call_args.args[0]
        self.assertIn('Revision 7', text)
        self.assertIn('Saved visibility: private', text)
        self.assertIn('Inherited access is not shown', text)
        manager._gallery_status.reset_mock()
        manager._gallery_choice.current.return_value = -1
        manager._events.put((manager._owner, 'detail', detail))
        manager._poll()
        manager._gallery_status.set.assert_not_called()

    def test_visibility_confirmation_and_selection_invalidation(self) -> None:
        """Require a matching fresh detail and explicit confirmation for a write.

        Returns:
            None: Selection changes and uncertain replies cannot reuse detail.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_change_gallery_visibility.return_value = True
        session.available.return_value = True
        detail = linking.LinkedGalleryDetail(
            42, 'Gallery', '7', 'private', 'password', 'unlisted',
        )
        manager._session = session
        manager._current_issuer = manager._session_issuer = 'https://gallery.example'
        manager._session_user_id = 7
        manager._gallery_page = linking.LinkedGalleryPage(
            (linking.LinkedGallery(42, None, 'Gallery'),), None,
        )
        manager._detail_snapshot = (detail, session, manager._current_issuer, 7)
        manager._gallery_choice = mock.Mock()
        manager._gallery_choice.current.return_value = 0
        manager._visibility_choice = mock.Mock()
        manager._visibility_choice.get.return_value = 'public'
        manager._worker = None
        manager._window = mock.Mock()
        manager._gallery_status = mock.Mock()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._events = queue.Queue()
        session.change_gallery_visibility.side_effect = linking.LinkingError('unknown')
        with mock.patch('uploader.app_linking_ui.LINKING_VISIBILITY_READY', True), \
                mock.patch('uploader.app_linking_ui.messagebox.askyesno', return_value=True), \
                mock.patch('uploader.app_linking_ui.threading.Thread') as thread:
            manager._change_visibility()
            self.assertIsNone(manager._detail_snapshot)
            worker = thread.call_args.kwargs['target']
            worker()
            self.assertEqual('visibility_unknown', manager._events.get_nowait()[1])
            manager._worker = None
            manager._change_visibility()
            self.assertEqual(1, thread.call_count)
        session.change_gallery_visibility.assert_called_once()
        manager._detail_snapshot = (detail, session, manager._current_issuer, 7)
        manager._gallery_changed(mock.Mock())
        self.assertIsNone(manager._detail_snapshot)

    @mock.patch('uploader.app_linking_ui.LINKING_VISIBILITY_READY', False)
    def test_unready_visibility_does_not_grant_or_block_upload(self) -> None:
        """Keep a disabled visibility option outside existing upload authority.

        Returns:
            None: Forced visibility is refused without a linking attempt or POST.
        """
        manager = object.__new__(LinkingManager)
        manager._visibility_var = mock.Mock()
        manager._visibility_var.get.return_value = True
        manager._upload_var = mock.Mock()
        manager._upload_var.get.return_value = True
        manager._read_var = mock.Mock()
        manager._read_var.get.return_value = False
        manager._browse_var = mock.Mock()
        manager._create_var = mock.Mock()
        manager._create_var.get.return_value = False
        manager._scope_changed()
        manager._visibility_var.set.assert_called_once_with(False)
        manager._browse_var.set.assert_called_once_with(True)
        manager._session = None
        manager._worker = None
        manager._status = mock.Mock()
        with mock.patch('uploader.app_linking_ui.LinkingAttempt') as factory:
            manager._start('https://gallery.example')
            factory.assert_not_called()
        manager._detail_snapshot = mock.Mock()
        manager._change_visibility()
        self.assertIsNotNone(manager._detail_snapshot)

    def test_final_event_posted_after_drain_is_delivered(self) -> None:
        """Drain a late completion after its worker has already stopped.

        Returns:
            None: Schedules another poll and closes the stale linked session.
        """
        manager = object.__new__(LinkingManager)
        manager._root = mock.Mock()
        manager._polling = True
        manager._shutting_down = False
        manager._events = queue.Queue()
        manager._owner = object()
        manager._direct_owner = None
        manager._window = None
        manager._session = None
        manager._worker = mock.Mock()
        manager._handoff = None
        session = mock.Mock()

        def finish_worker() -> bool:
            """Post a final event at the worker-status boundary.

            Returns:
                bool: False after the final event has been placed in the queue.
            """
            manager._events.put((
                manager._owner, 'connected',
                (session, linking.LinkedIdentity(7, 'admin'), 'https://gallery.example'),
            ))
            manager._worker.is_alive.side_effect = None
            manager._worker.is_alive.return_value = False
            return False

        manager._worker.is_alive.side_effect = finish_worker
        manager._poll()
        manager._root.after.assert_called_once()
        scheduled_poll = manager._root.after.call_args.args[1]
        session.close.assert_not_called()
        scheduled_poll()
        session.close.assert_called_once_with()
        manager._root.after.assert_called_once()

    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', False)
    def test_disabled_upload_selection_preserves_connection_and_receipt(self) -> None:
        """Refuse both activation paths before changing any selected target.

        Returns:
            None: Leaves the current connection and completed receipt untouched.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_list_galleries.return_value = True
        session.can_upload.return_value = True
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Created')
        bound = BoundCreateReceipt(gallery, 'a' * 64)
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Created')
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._gallery_page = linking.LinkedGalleryPage((gallery,), None)
        manager._gallery_choice = mock.Mock()
        manager._gallery_choice.current.return_value = 0
        manager._created_gallery = (bound, session, issuer, 7)
        manager._gallery_cancel = threading.Event()
        manager._active_upload_target = None
        manager._selected_gallery = None
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._target_refusal = mock.Mock()
        manager._notify_target_changed = mock.Mock()
        manager._select_gallery()
        manager._use_created_gallery()
        manager._apply_created_gallery(bound, target, session, issuer, 7, linking.UPLOAD_SCOPE, manager._gallery_cancel)
        self.assertIsNone(manager._active_upload_target)
        self.assertIsNone(manager._selected_gallery)
        self.assertIs(bound, manager._created_gallery[0])
        manager._create_journal.apply_receipt.assert_not_called()
        manager._target_refusal.assert_not_called()
        manager._notify_target_changed.assert_not_called()
        self.assertIn('temporarily unavailable',
                      manager._gallery_status.set.call_args.args[0])

    def test_delayed_receipt_callback_rechecks_upload_gate(self) -> None:
        """Refuse a deferred callback if the upload gate is no longer ready.

        Returns:
            None: Keeps the original receipt and connection without host calls.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.can_create_gallery.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        bound = BoundCreateReceipt(linking.LinkedGallery(42, None, 'Created'), 'a' * 64)
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Created')
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (bound, session, issuer, 7)
        manager._gallery_cancel = threading.Event()
        manager._active_upload_target = None
        manager._selected_gallery = None
        manager._gallery_status = mock.Mock()
        manager._target_refusal = mock.Mock()
        manager._notify_target_changed = mock.Mock()
        manager._commit_linked_target = mock.Mock()
        manager._create_journal = mock.Mock()

        def delayed(_issuer: str, _account: int, _bound: BoundCreateReceipt,
                    apply: Callable[[], bool]) -> bool:
            """Invoke the retained callback after the gate changes in this fixture.

            Args:
                _issuer (str): Original gallery issuer.
                _account (int): Original Admin ID.
                _bound (BoundCreateReceipt): Original bound receipt.
                apply (Callable[[], bool]): Journal callback to apply the target.

            Returns:
                bool: False when the callback sees the closed gate.
            """
            with mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', False):
                return apply()

        manager._create_journal.apply_receipt.side_effect = delayed
        with mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True), \
                mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True):
            manager._apply_created_gallery(bound, target, session, issuer, 7, linking.UPLOAD_SCOPE, manager._gallery_cancel)
        self.assertIsNone(manager._active_upload_target)
        self.assertIs(bound, manager._created_gallery[0])
        manager._target_refusal.assert_not_called()
        manager._commit_linked_target.assert_not_called()
        manager._notify_target_changed.assert_not_called()

    def test_gallery_selection_stays_in_memory_only(self) -> None:
        """Select only a freshly loaded gallery without changing upload state.

        Returns:
            None: Stores the selected stable ID on the session manager alone.
        """
        manager = object.__new__(LinkingManager)
        manager._session = mock.Mock()
        manager._session.can_list_galleries.return_value = True
        manager._session.can_upload.return_value = False
        manager._session_issuer = manager._current_issuer = 'https://gallery.example'
        manager._window = None
        gallery = linking.LinkedGallery(7, None, 'Private\nGallery')
        manager._gallery_page = linking.LinkedGalleryPage((gallery,), None)
        manager._selected_gallery = None
        manager._active_upload_target = None
        manager._gallery_choice = mock.Mock()
        manager._gallery_choice.current.return_value = 0
        manager._gallery_status = mock.Mock()
        manager._commit_linked_target = mock.Mock()
        manager._select_gallery()
        self.assertIs(gallery, manager._selected_gallery)
        message = manager._gallery_status.set.call_args.args[0]
        self.assertIn('approve access', message)

        previous_target = object()
        manager._active_upload_target = previous_target
        manager._select_gallery()
        self.assertIs(manager._active_upload_target, previous_target)
        manager._commit_linked_target.assert_not_called()

    def test_switching_sites_keeps_uploads_and_exact_approvals(self) -> None:
        """Switch live sites and revoke one without changing the upload target.

        Returns:
            None: Retains the original upload session, the exact selected Admin,
                and only canonical addresses in the durable history.
        """
        # Distinct installations on the same host must keep separate grants.
        first = 'https://gallery.example/A'
        second = 'https://gallery.example/B'
        original = mock.Mock(spec=linking.LinkingSession)
        other = mock.Mock(spec=linking.LinkingSession)
        original.matches_issuer.side_effect = lambda issuer: issuer == first
        original.can_upload.return_value = True
        guard = mock.Mock(return_value='Stop the watcher first.')
        with TemporaryDirectory() as directory, mock.patch(
            'uploader.app_linking_ui.tk.StringVar', side_effect=lambda *_a, **_kw: mock.Mock(),
        ), mock.patch('uploader.app_linking_ui.load_recent_gallery_addresses', return_value=()):
            manager = LinkingManager(mock.Mock(), target_change_refusal=guard)
            history = manager._recent_link_path = Path(directory) / 'recent-gallery-links.json'
            manager._start = mock.Mock()
            manager._load_galleries = mock.Mock()
            manager._show_link(_LinkedSite(first, original, 7))
            gallery = linking.LinkedGallery(29, None, 'Uploads')
            manager._gallery_page = linking.LinkedGalleryPage((gallery,), None)
            target = LinkedUploadTarget(original, first, 7, gallery.gallery_id, gallery.title)
            manager._active_upload_target = target

            manager.link(second)
            manager._start.assert_called_once_with(second, direct=True)
            self.assertEqual(first, manager.address())
            self.assertIs(target, manager.selected_upload_target())
            guard.assert_not_called()

            manager._show_link(_LinkedSite(second, other, 17))
            self.assertEqual(second, manager.address())
            self.assertIsNone(manager._gallery_page)
            self.assertIs(target, manager.selected_upload_target())
            manager.unlink(first)
            guard.assert_called_once_with(target.binding())
            original.revoke.assert_not_called()

            manager._start.reset_mock()
            manager.link(first)
            manager._start.assert_not_called()
            manager._load_galleries.assert_called_once_with(None)
            self.assertIs(original, manager._session)
            self.assertEqual(7, manager._session_user_id)
            self.assertEqual((first, second), load_recent_gallery_addresses(history))
            self.assertEqual({'addresses': [first, second]}, json.loads(history.read_text()))

            with mock.patch('uploader.app_linking_ui.threading.Thread') as thread:
                thread.return_value.is_alive.return_value = False
                manager.unlink(second)
                thread.call_args.kwargs['target']()
                manager._poll()
            other.revoke.assert_called_once_with()
            original.close.assert_not_called()
            self.assertIs(target, manager.selected_upload_target())
            self.assertIsNone(manager._live_link(second))
            self.assertEqual((first, second), load_recent_gallery_addresses(history))
            self.assertTrue(manager._save_recent_links((first,)))
            self.assertEqual((first,), load_recent_gallery_addresses(history))

    def test_unlink_from_main_window_revokes_and_disconnects_upload(self) -> None:
        """Accept the revocation result even without a linking dialog open.

        Returns:
            None: The exact live grant is revoked once and no key mode activates.
        """
        with mock.patch(
            'uploader.app_linking_ui.tk.StringVar',
            side_effect=lambda *_args, **_kwargs: mock.Mock(),
        ):
            manager = LinkingManager(
                mock.Mock(), mock.Mock(return_value='Stop the watcher first.'),
                mock.Mock(), saved_linked_selected=lambda: True,
                commit_saved_api_key=mock.Mock(),
            )
        issuer = 'http://localhost:8888/galerie'
        session = mock.Mock(spec=linking.LinkingSession)
        session.matches_issuer.return_value = True
        session.can_upload.return_value = True
        target = LinkedUploadTarget(session, issuer, 7, 29, 'Uploads')
        manager._saved_upload_identity = lambda: (issuer, target.binding())
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = target.user_id
        manager._active_upload_target = target
        manager.unlink()
        self.assertIs(target, manager.selected_upload_target())
        manager.status.set.assert_called_with('Stop the watcher first.')
        session.revoke.assert_not_called()

        manager._target_change_refusal.return_value = None
        with mock.patch('uploader.app_linking_ui.threading.Thread') as thread:
            thread.return_value.is_alive.return_value = False
            manager.unlink()
            self.assertIsNone(manager.selected_upload_target())
            thread.call_args.kwargs['target']()
            manager._poll()

        session.revoke.assert_called_once_with()
        session.close.assert_called_once_with()
        manager.linked_address.set.assert_called_once_with('')
        manager.status.set.assert_called_with('Gallery unlinked.')
        manager._target_changed.assert_called_once_with()
        manager._target_change_refusal.assert_called_with(target.binding())
        manager._commit_saved_api_key.assert_not_called()
        self.assertTrue(manager.linked_upload_mode_selected())
        manager._current_issuer = ''
        self.assertEqual(issuer, manager.address())
        self.assertEqual(target.binding(), manager.linked_upload_binding())

    def test_reopened_scope_control_reflects_only_its_live_session(self) -> None:
        """Default new connections to listing while reflecting existing scopes.

        Returns:
            None: New consent is selected; existing sessions retain exact scope.
        """
        manager = object.__new__(LinkingManager)
        manager._session = None
        manager._session_issuer = ''
        self.assertTrue(manager._listing_scope_selected('https://gallery.example'))
        manager._session = mock.Mock()
        manager._session_issuer = 'https://gallery.example'
        manager._session.can_list_galleries.return_value = True
        self.assertTrue(manager._listing_scope_selected('https://gallery.example'))
        self.assertFalse(manager._listing_scope_selected('https://other.example'))
        manager._session.can_list_galleries.return_value = False
        self.assertFalse(manager._listing_scope_selected('https://gallery.example'))

    def test_closing_window_cancels_inflight_page_without_revoking(self) -> None:
        """Stop a page read when its window closes and keep the local session.

        Returns:
            None: Cancels the request without calling revoke or session close.
        """
        manager = object.__new__(LinkingManager)
        manager._session = mock.Mock()
        manager._cancel = threading.Event()
        manager._create_dispatch = None
        manager._gallery_cancel = None
        manager._attempt = None
        manager._handoff = None
        manager._window = None
        manager._owner = object()
        session = manager._session
        manager._close_window()
        session.cancel_pending.assert_called_once_with(gallery_only=True)
        session.close.assert_not_called()
        session.revoke.assert_not_called()
        self.assertIs(session, manager._session)
        self.assertIsNone(manager._owner)

    def test_close_before_listing_worker_runs_prevents_network_request(self) -> None:
        """Cancel a scheduled listing before its thread has executed.

        Returns:
            None: A late worker never contacts the server or cancels uploads.
        """
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        session = linking.LinkingSession(
            'https://gallery.example/Galerie', token,
            time.monotonic() + 120, linking.UPLOAD_SCOPE,
        )
        manager = object.__new__(LinkingManager)
        manager._session = session
        manager._session_issuer = manager._current_issuer = 'https://gallery.example/Galerie'
        manager._worker = None
        manager._gallery_cancel = None
        manager._gallery_status = mock.Mock()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._cancel = threading.Event()
        manager._create_dispatch = None
        manager._attempt = None
        manager._handoff = None
        manager._window = None
        manager._events = queue.Queue()
        with mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                mock.patch.object(linking, '_request_json') as request_json:
            manager._load_galleries(None)
            thread.return_value.start.assert_called_once_with()
            late_worker = thread.call_args.kwargs['target']
            manager._close_window()
            late_worker()
            request_json.assert_not_called()
        self.assertEqual('galleries_error', manager._events.get_nowait()[1])
        self.assertTrue(session.available())
        session.close()

    def test_saved_linked_choice_outlives_local_token_and_requires_explicit_key_commit(self) -> None:
        """Expose persisted linked mode after restart without an active target.

        Returns:
            None: A failed explicit key transition leaves the mode selected.
        """
        manager = object.__new__(LinkingManager)
        manager._active_upload_target = None
        manager._saved_linked_selected = lambda: True
        manager._gallery_status = mock.Mock()
        manager._target_refusal = mock.Mock(return_value=None)
        manager._notify_target_changed = mock.Mock()
        manager._refresh = mock.Mock()
        manager._current_issuer = 'https://gallery.example'
        manager._commit_saved_api_key = mock.Mock(return_value=False)
        self.assertTrue(manager.linked_upload_mode_selected())
        manager.use_saved_api_key()
        self.assertTrue(manager.linked_upload_mode_selected())
        manager._commit_saved_api_key.assert_called_once()
        manager._notify_target_changed.assert_not_called()

        manager._saved_linked_selected = lambda: False
        manager._commit_saved_api_key.return_value = True
        manager.use_saved_api_key()
        self.assertFalse(manager.linked_upload_mode_selected())


    def test_linked_upload_selection_verifies_remote_target_before_commit(self) -> None:
        """Keep a fresh server-backed selection asynchronous and owner-bound.

        Returns:
            None: The local target is not changed before worker verification.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_list_galleries.return_value = True
        session.can_upload.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Selected')
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._gallery_page = linking.LinkedGalleryPage((gallery,), None)
        manager._gallery_choice = mock.Mock()
        manager._gallery_choice.current.return_value = 0
        manager._gallery_status = mock.Mock()
        manager._active_upload_target = None
        manager._selected_gallery = None
        manager._commit_linked_target = mock.Mock(return_value=True)
        manager._upload_workspace_ready = mock.Mock(return_value=False)
        manager._worker = None
        manager._events = queue.Queue()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        verified_target = mock.Mock()
        with mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                mock.patch('uploader.app_linking_ui.verify_linked_upload_target',
                           return_value=verified_target) as verify:
            manager._select_gallery()
            thread.assert_not_called()
            self.assertIn('local app profile',
                          manager._gallery_status.set.call_args.args[0])
            manager._upload_workspace_ready.return_value = True
            manager._select_gallery()
            thread.return_value.start.assert_called_once_with()
            self.assertIsNone(manager._active_upload_target)
            thread.call_args.kwargs['target']()
            verify.assert_called_once()
        owner, kind, result = manager._events.get_nowait()
        self.assertIs(owner, manager._owner)
        self.assertEqual('upload_target_verified', kind)
        self.assertEqual((verified_target, session, issuer, 7, gallery,
                          linking.UPLOAD_SCOPE, manager._gallery_cancel), result)
        manager._commit_linked_target.assert_not_called()

    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_linked_upload_handoff_rechecks_current_selection_and_publication(self) -> None:
        """Refuse a stale selected row and a failed persistent mode transition.

        Returns:
            None: Only the current approved target can replace a local choice.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Selected')
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._gallery_page = linking.LinkedGalleryPage((gallery,), None)
        manager._gallery_choice = mock.Mock()
        manager._gallery_cancel = threading.Event()
        manager._gallery_status = mock.Mock()
        manager._active_upload_target = None
        manager._selected_gallery = None
        manager._commit_linked_target = mock.Mock(return_value=False)
        manager._target_refusal = mock.Mock(return_value=None)
        manager._notify_target_changed = mock.Mock()
        target = mock.Mock()
        target.session = session
        target.issuer = issuer
        target.user_id = 7
        target.gallery_id = 42
        target.binding.return_value = 'a' * 64
        manager._gallery_choice.current.return_value = -1
        manager._apply_verified_upload_target(
            target, session, issuer, 7, gallery, linking.UPLOAD_SCOPE,
            manager._gallery_cancel,
        )
        manager._commit_linked_target.assert_not_called()
        manager._gallery_choice.current.return_value = 0
        manager._apply_verified_upload_target(
            target, session, issuer, 7, gallery, linking.UPLOAD_SCOPE,
            manager._gallery_cancel,
        )
        self.assertIsNone(manager._active_upload_target)
        manager._notify_target_changed.reset_mock()
        manager._commit_linked_target.return_value = True
        manager._apply_verified_upload_target(
            target, session, issuer, 7, gallery, linking.UPLOAD_SCOPE,
            manager._gallery_cancel,
        )
        self.assertIs(manager._active_upload_target, target)
        manager._notify_target_changed.assert_called_once()


    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_listed_upload_scope_and_publication_uncertainty_fail_closed(self) -> None:
        """Discard a prior target on changed scope or unconfirmed mode publication.

        Returns:
            None: Neither an old gallery nor the saved legacy key is selected.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Selected')
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Selected')
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._gallery_page = linking.LinkedGalleryPage((gallery,), None)
        manager._gallery_choice = mock.Mock()
        manager._gallery_choice.current.return_value = 0
        manager._gallery_cancel = threading.Event()
        manager._gallery_status = mock.Mock()
        manager._commit_linked_target = mock.Mock(return_value=True)
        manager._notify_target_changed = mock.Mock()
        manager._target_refusal = mock.Mock(return_value=None)
        manager._saved_linked_selected = lambda: True

        # The worker verified the original scope, not a later grant.
        for scope_result in ('link:identity galleries:list', RuntimeError('unknown')):
            with self.subTest(scope=scope_result):
                manager._active_upload_target = target
                manager._selected_gallery = gallery
                session.approved_scope.side_effect = (
                    scope_result if isinstance(scope_result, Exception) else None
                )
                if not isinstance(scope_result, Exception):
                    session.approved_scope.return_value = scope_result
                manager._apply_verified_upload_target(
                    target, session, issuer, 7, gallery, linking.UPLOAD_SCOPE,
                    manager._gallery_cancel,
                )
                self.assertIsNone(manager.selected_upload_target())
                self.assertIsNone(manager._selected_gallery)
                self.assertTrue(manager.linked_upload_mode_selected())
                manager._commit_linked_target.assert_not_called()
                manager._notify_target_changed.assert_called()
        session.approved_scope.side_effect = None
        session.approved_scope.return_value = linking.UPLOAD_SCOPE

        # A false result may mean the config anchor was already written, and
        # an exception may mean the vault generation was partly published.
        for result in (False, RuntimeError('publication unconfirmed')):
            with self.subTest(publication=result):
                manager._active_upload_target = target
                manager._selected_gallery = gallery
                manager._commit_linked_target.reset_mock()
                manager._commit_linked_target.side_effect = (
                    result if isinstance(result, Exception) else None
                )
                if not isinstance(result, Exception):
                    manager._commit_linked_target.return_value = result
                manager._apply_verified_upload_target(
                    target, session, issuer, 7, gallery, linking.UPLOAD_SCOPE,
                    manager._gallery_cancel,
                )
                self.assertIsNone(manager.selected_upload_target())
                self.assertIsNone(manager._selected_gallery)
                self.assertTrue(manager.linked_upload_mode_selected())
                self.assertIn('unconfirmed', manager._gallery_status.set.call_args.args[0])

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_created_worker_verifies_receipt_then_exact_live_gallery(self) -> None:
        """Pass fresh receipt, identity, gallery and scope as one owned event.

        Returns:
            None: The worker cannot publish upload mode before Tk admission.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.can_create_gallery.return_value = True
        session.matches_issuer.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Created')
        bound = BoundCreateReceipt(gallery, 'a' * 64)
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Created')
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (bound, session, issuer, 7)
        manager._worker = None
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._create_journal.reconcile.return_value = bound
        manager._events = queue.Queue()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._commit_linked_target = mock.Mock()
        with mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                mock.patch('uploader.app_linking_ui.verify_linked_upload_target',
                           return_value=target) as verify:
            manager._use_created_gallery()
            thread.call_args.kwargs['target']()
        verify.assert_called_once()
        args = verify.call_args.args
        self.assertEqual((session, issuer, 7, linking.UPLOAD_SCOPE, 42),
                         args[:5])
        self.assertIs(manager._gallery_cancel, args[5])
        self.assertIsInstance(args[5], threading.Event)
        owner, kind, payload = manager._events.get_nowait()
        self.assertIs(owner, manager._owner)
        self.assertEqual('create_selection_verified', kind)
        self.assertEqual(
            (bound, target, session, issuer, 7, linking.UPLOAD_SCOPE,
             manager._gallery_cancel),
            payload,
        )
        manager._commit_linked_target.assert_not_called()

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_created_worker_rejects_gallery_mismatch_or_cancellation(self) -> None:
        """Never publish a late, cancelled or different-gallery verification.

        Returns:
            None: Both cases preserve the original create receipt unchanged.
        """
        for mismatch in (True, False):
            with self.subTest(mismatch=mismatch):
                manager = object.__new__(LinkingManager)
                session = mock.Mock(spec=linking.LinkingSession)
                session.can_upload.return_value = True
                session.can_create_gallery.return_value = True
                session.matches_issuer.return_value = True
                session.approved_scope.return_value = linking.UPLOAD_SCOPE
                issuer = 'https://gallery.example'
                bound = BoundCreateReceipt(
                    linking.LinkedGallery(42, None, 'Created'), 'a' * 64,
                )
                manager._session = session
                manager._session_issuer = manager._current_issuer = issuer
                manager._session_user_id = 7
                manager._created_gallery = (bound, session, issuer, 7)
                manager._worker = None
                manager._gallery_status = mock.Mock()
                manager._create_journal = mock.Mock()
                manager._create_journal.reconcile.return_value = bound
                manager._events = queue.Queue()
                manager._refresh = mock.Mock()
                manager._schedule_poll = mock.Mock()
                manager._commit_linked_target = mock.Mock()
                verified = LinkedUploadTarget(
                    session, issuer, 7, 43 if mismatch else 42, 'Created',
                )
                with mock.patch('uploader.app_linking_ui.threading.Thread') as thread, \
                        mock.patch('uploader.app_linking_ui.verify_linked_upload_target',
                                   return_value=verified) as verify:
                    manager._use_created_gallery()
                    if not mismatch:
                        manager._gallery_cancel.set()
                    thread.call_args.kwargs['target']()
                self.assertEqual('create_selection_error',
                                 manager._events.get_nowait()[1])
                if mismatch:
                    verify.assert_called_once()
                else:
                    verify.assert_not_called()
                self.assertIs(bound, manager._created_gallery[0])
                manager._commit_linked_target.assert_not_called()

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_created_verified_event_cancelled_after_worker_is_rejected(self) -> None:
        """Reject a current receipt whose worker was cancelled after verification.

        Returns:
            None: No receipt lease or persistent publication follows cancellation.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.can_create_gallery.return_value = True
        session.matches_issuer.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        bound = BoundCreateReceipt(
            linking.LinkedGallery(42, None, 'Created'), 'a' * 64,
        )
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Created')
        manager._window = mock.Mock()
        manager._polling = False
        manager._handoff = None
        manager._worker = None
        manager._owner = object()
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (bound, session, issuer, 7)
        manager._gallery_cancel = threading.Event()
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._commit_linked_target = mock.Mock()
        manager._events = queue.Queue()
        manager._refresh = mock.Mock()
        manager._schedule_poll = mock.Mock()
        manager._events.put((
            manager._owner, 'create_selection_verified',
            (bound, target, session, issuer, 7, linking.UPLOAD_SCOPE,
             manager._gallery_cancel),
        ))
        manager._gallery_cancel.set()
        manager._poll()
        manager._create_journal.apply_receipt.assert_not_called()
        manager._commit_linked_target.assert_not_called()
        self.assertIn('no longer current',
                      manager._gallery_status.set.call_args.args[0])

    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_listed_gallery_cancelled_after_verification_cannot_publish(self) -> None:
        """Discard a just-verified listed gallery once its owner cancels.

        Returns:
            None: Neither the host mode owner nor local target changes.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        issuer = 'https://gallery.example'
        gallery = linking.LinkedGallery(42, None, 'Listed')
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Listed')
        manager._gallery_page = linking.LinkedGalleryPage((gallery,), None)
        manager._gallery_choice = mock.Mock()
        manager._gallery_choice.current.return_value = 0
        manager._gallery_cancel = threading.Event()
        manager._gallery_cancel.set()
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._active_upload_target = None
        manager._gallery_status = mock.Mock()
        manager._commit_linked_target = mock.Mock()
        manager._target_refusal = mock.Mock()
        manager._apply_verified_upload_target(
            target, session, issuer, 7, gallery, linking.UPLOAD_SCOPE,
            manager._gallery_cancel,
        )
        manager._commit_linked_target.assert_not_called()
        manager._target_refusal.assert_not_called()
        self.assertIsNone(manager._active_upload_target)

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_created_mode_publication_uncertainty_clears_local_target(self) -> None:
        """Keep unknown persistent mode from exposing a stale in-memory target.

        Returns:
            None: No fallback to either the former gallery or saved API key.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.can_create_gallery.return_value = True
        session.matches_issuer.return_value = True
        session.approved_scope.return_value = linking.UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        bound = BoundCreateReceipt(
            linking.LinkedGallery(42, None, 'Created'), 'a' * 64,
        )
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Created')
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (bound, session, issuer, 7)
        manager._gallery_cancel = threading.Event()
        manager._active_upload_target = mock.Mock()
        manager._selected_gallery = linking.LinkedGallery(19, None, 'Prior')
        manager._gallery_status = mock.Mock()
        manager._target_refusal = mock.Mock(return_value=None)
        manager._commit_linked_target = mock.Mock(return_value=False)
        manager._notify_target_changed = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._create_journal.apply_receipt.side_effect = (
            lambda _issuer, _account, _bound, apply: apply()
        )
        manager._apply_created_gallery(
            bound, target, session, issuer, 7, linking.UPLOAD_SCOPE,
            manager._gallery_cancel,
        )
        self.assertIsNone(manager._active_upload_target)
        self.assertIsNone(manager._selected_gallery)
        self.assertIs(bound, manager._created_gallery[0])
        manager._commit_linked_target.assert_called_once_with(target)
        manager._notify_target_changed.assert_called_once()
        self.assertIn('unconfirmed',
                      manager._gallery_status.set.call_args.args[0])

    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    def test_created_target_rejects_changed_scope_before_receipt_lease(self) -> None:
        """Reject a changed grant even after the worker verified the gallery.

        Returns:
            None: A stale scope never enters receipt or mode publication.
        """
        manager = object.__new__(LinkingManager)
        session = mock.Mock(spec=linking.LinkingSession)
        session.can_upload.return_value = True
        session.can_create_gallery.return_value = True
        session.matches_issuer.return_value = True
        session.approved_scope.return_value = linking.READ_UPLOAD_SCOPE
        issuer = 'https://gallery.example'
        bound = BoundCreateReceipt(
            linking.LinkedGallery(42, None, 'Created'), 'a' * 64,
        )
        target = LinkedUploadTarget(session, issuer, 7, 42, 'Created')
        manager._session = session
        manager._session_issuer = manager._current_issuer = issuer
        manager._session_user_id = 7
        manager._created_gallery = (bound, session, issuer, 7)
        manager._gallery_cancel = threading.Event()
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._commit_linked_target = mock.Mock()
        manager._apply_created_gallery(
            bound, target, session, issuer, 7, linking.UPLOAD_SCOPE,
            manager._gallery_cancel,
        )
        manager._create_journal.apply_receipt.assert_not_called()
        manager._commit_linked_target.assert_not_called()

    @mock.patch('uploader.app_linking_ui.LINKING_UPLOAD_TARGET_READY', True)
    @mock.patch('uploader.app_linking_ui.LINKING_CREATED_TARGET_READY', False)
    def test_created_shortcut_does_not_inherit_regular_target_readiness(self) -> None:
        """Block created-gallery receipt activation even if listing is enabled.

        Returns:
            None: The independent shortcut gate retains prior mode and receipt.
        """
        manager = object.__new__(LinkingManager)
        manager._gallery_status = mock.Mock()
        manager._create_journal = mock.Mock()
        manager._gallery_cancel = threading.Event()
        manager._active_upload_target = None
        manager._target_refusal = mock.Mock()
        manager._use_created_gallery()
        manager._apply_created_gallery(
            mock.Mock(), mock.Mock(), mock.Mock(), 'https://gallery.example',
            7, linking.UPLOAD_SCOPE, manager._gallery_cancel,
        )
        manager._create_journal.apply_receipt.assert_not_called()
        manager._target_refusal.assert_not_called()
        self.assertIn('temporarily unavailable',
                      manager._gallery_status.set.call_args.args[0])


if __name__ == '__main__':
    unittest.main()
