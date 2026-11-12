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
import http.server
import json
import os
import queue
import socket
import sys
import threading
import time
import unittest
from collections.abc import Iterable
from dataclasses import replace as dataclass_replace
from email.message import Message
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace
from typing import Callable
from unittest import mock
from urllib.parse import parse_qs, urlencode

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import app_linking as linking
from uploader import gallery_media_cache, offline_linking, session_resume
from uploader.diagnostics import (
    PhotosDecodeError, PhotosDecodeFacts,
    SAFE_PHOTOS_DECODE_BODY_MAX_BYTES, SAFE_PHOTOS_DECODE_PIXEL_MAX,
    format_safe_photos_decode_facts, format_safe_photos_failure,
    format_safe_photos_shortcut, is_safe_photos_observation,
)
from uploader.flight_report_translations import tr
from uploader.linked_create import (
    BoundCreateReceipt, CreateIntent, GalleryCreateJournal,
)
from uploader.linked_upload import LinkedUploadTarget
from uploader.state_store import load_recent_gallery_addresses

# Purpose: Bound synchronization with one owned fake transport worker.
# Type: float. Units: seconds. Scope: one portable test wait or join.
# Consumers: LinkingTransportTests. Rationale: two seconds permits a loaded
# runner to schedule worker threads without waiting indefinitely on failure.
TEST_WORKER_WAIT_SECONDS = 2.0

# Purpose: Keep restored grant fixtures live for their synchronous worker call.
# Type: float. Units: seconds. Scope: transient/offline restore regressions.
# Consumers: LinkingTransportTests restore capability fixtures.
# Rationale: Two minutes is independent of scheduling waits and avoids an
#   expiry race without prolonging or sleeping in the test.
TEST_RESTORE_CREDENTIAL_LIFETIME_SECONDS = 120.0

# Purpose: Make the deadline regression complete promptly without real I/O.
# Type: float. Units: seconds. Scope: one fake delayed HTTPS request.
# Consumers: LinkingTransportTests. Rationale: 0.25 seconds distinguishes a
# total deadline from a blocked worker and bounds slot-admission regressions.
TEST_REQUEST_DEADLINE_SECONDS = 0.25

# Purpose: Prove held-slot admission waits without leaving the deadline bound.
# Type: float. Units: seconds. Scope: one held transport-slot fixture.
# Consumers: LinkingTransportTests admission deadline regression.
# Rationale: 0.15s confirms most of the 0.25s budget was observed; the existing
# two-second worker wait remains the scheduling ceiling.
TEST_ADMISSION_WAIT_MIN_SECONDS = 0.15

# Type: str. Units: Base32 characters. Scope: one synthetic browser handoff.
# Consumers: push-response tests. Rationale: canonical 128-bit value models
# the server's opaque correlation handle without giving it user authority.
TEST_REQUEST_HANDLE = base64.b32encode(
    b'p' * linking.REQUEST_HANDLE_RANDOM_BYTES,
).rstrip(b'=').decode('ascii')

# Purpose: Keep retained-cache quota vectors small while exceeding one entry.
# Type: int. Units: bytes. Scope: focused gallery cache retention fixtures.
# Consumers: NativeMediaSessionTests. Rationale: 64 bytes keeps each fixture
# body valid while patched ordinary limits exercise eviction deterministically.
TEST_PINNED_CACHE_BODY_BYTES = 64
TEST_PINNED_CACHE_ENTRY_BYTES = (
    gallery_media_cache._CACHE_HEADER.size + TEST_PINNED_CACHE_BODY_BYTES
)

# Purpose: Force an aggregate unpin overflow without allocating large bodies.
# Type: int. Units: entries. Scope: one thumbnail-pool unpin fixture.
# Consumers: NativeMediaSessionTests.test_unpin_capacity_preflight_keeps_all_retained_sources.
# Rationale: One slot proves both targets must coexist before any source is removed.
TEST_THUMBNAIL_UNPIN_MAX_ENTRIES = 1

# Purpose: Exercise rejection immediately above the optional tree wire limit.
# Type: int. Units: response bytes. Scope: one complete-tree HTTP fixture.
# Consumers: LinkingTransportTests.test_gallery_tree_wire_route_uses_its_own_response_bound.
# Rationale: One extra byte proves the response cap is enforced strictly.
TEST_GALLERY_TREE_OVER_LIMIT_BYTES = (
    linking.LINKING_GALLERY_TREE_RESPONSE_MAX_BYTES + 1
)

# Purpose: Prove sequential pool reuse across several independent GET owners.
# Type: int. Units: GET responses. Scope: one controlled idle-pool fixture.
# Consumers: NativeMediaTransportTests. Rationale: Three reads prove both
# initial creation and repeated reuse without making the fixture unbounded.
TEST_POOLED_GET_COUNT = 3


def discovery_response(
    issuer: str, scope: str, extra_capabilities: tuple[str, ...] = (),
) -> dict[str, linking.JsonValue]:
    """Build a supported public-discovery envelope for one attempt.

    Args:
        issuer (str): Exact canonical issuer selected by the fixture.
        scope (str): Registered requested scope bundle.
        extra_capabilities (tuple[str, ...]): Additional public capability
            markers used to test optional discovery negotiation.

    Returns:
        dict[str, linking.JsonValue]: Public capability response with every
            requested non-identity scope and the browser linking capability.
    """
    capabilities = ['linking', *sorted(set(scope.split()) - {linking.IDENTITY_SCOPE})]
    capabilities.extend(extra_capabilities)
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

    def test_serial_gate_admits_waiter_before_repeated_preload(self) -> None:
        """Preserve FIFO opportunity for a queued Watcher or viewport request.

        Returns:
            None: Waiting calls acquire one serial slot in registration order;
                nonblocking attempts cannot jump the queue or double-release.
        """
        gate = linking._FairRequestGate()
        self.assertTrue(gate.acquire(blocking=False))
        registered = [threading.Event(), threading.Event()]
        order: list[int] = []
        original_wait = gate._condition.wait

        def observed_wait(timeout: float | None = None) -> bool:
            """Signal actual queued condition waiting before releasing the lock.

            Args:
                timeout (float | None): Existing admission wait bound in seconds.
            Returns:
                bool: Result of the real condition wait.
            """
            registered[int(threading.current_thread().name)].set()
            return original_wait(timeout)

        def request(index: int) -> None:
            """Acquire/release the serial gate for one synthetic caller.

            Args:
                index (int): Structural registration ordinal of this caller.
            Returns:
                None: Records admission order only after actual ownership.
            """
            if gate.acquire(timeout=TEST_WORKER_WAIT_SECONDS):
                order.append(index)
                gate.release()

        workers: list[threading.Thread] = []
        with mock.patch.object(gate._condition, 'wait', observed_wait):
            try:
                for index in (0, 1):
                    worker = threading.Thread(target=request, args=(index,), name=str(index))
                    worker.start()
                    workers.append(worker)
                    self.assertTrue(registered[index].wait(TEST_WORKER_WAIT_SECONDS))
                self.assertFalse(gate.acquire(blocking=False))
            finally:
                gate.release()
                for worker in workers:
                    worker.join(TEST_WORKER_WAIT_SECONDS)
        self.assertEqual([0, 1], order)
        self.assertFalse(gate.locked())
        with self.assertRaises(ValueError):
            gate.release()

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

    def test_normal_link_negotiates_rolling_and_keeps_transient_fallback(self) -> None:
        """Select exact advertised access, including local rolling-only discovery.

        Returns:
            None: The sole push asks for the expected browser-consent scope.
        """
        issuer = 'http://localhost:8888/Galerie'
        for advertised, expected in (
            ('rolling_access', linking.ROLLING_GALLERY_SEARCH_SCOPES[1]),
            ('offline_access', linking.GALLERY_SEARCH_SCOPES[1]),
        ):
            with self.subTest(advertised=advertised):
                envelope = discovery_response(
                    issuer, linking.GALLERY_SEARCH_SCOPES[1] + ' ' + advertised,
                )
                attempt = linking.LinkingAttempt(issuer, scope=linking.GALLERIES_SCOPE)
                self.addCleanup(attempt.close)
                with mock.patch.object(
                    linking, '_request_json',
                    side_effect=[envelope, handoff_response(issuer)],
                ) as request:
                    attempt.push_request(prefer_upload=True, prefer_rolling=True)
                self.assertEqual(
                    ('app_linking_discovery', 'app_linking_push'),
                    tuple(call.args[1] for call in request.call_args_list),
                )
                self.assertEqual(
                    [expected], parse_qs(request.call_args.args[3].decode('ascii'))['scope'],
                )
                self.assertEqual(
                    expected.endswith(linking.ROLLING_SCOPE_SUFFIX),
                    attempt.requests_offline_access(),
                )

    def test_normal_link_negotiates_explicit_presentation_bundles(self) -> None:
        """Preserve basic permissions and negotiate only advertised playback.

        Returns:
            None: The push retains upload and search or uses the exact fallback.
        """
        issuer = 'http://localhost:8888/Galerie'
        listing = linking.UPLOAD_SCOPE
        search = linking.GALLERY_SEARCH_SCOPES[1]
        presentation = linking.PRESENTATION_SCOPE_SUFFIX
        rolling = linking.ROLLING_SCOPE_SUFFIX
        media = ' media:read presentation:read'
        marker = ' presentation:rolling'
        for advertised, vault, expected in (
            (search + media + marker, True, search + presentation + rolling),
            (search + media, True, search + rolling),
            (listing + media + marker, False, listing + presentation),
            (search + media + marker, False, search),
        ):
            with self.subTest(advertised=advertised, vault=vault):
                envelope = discovery_response(issuer, advertised + ' rolling_access')
                attempt = linking.LinkingAttempt(issuer, scope=linking.GALLERIES_SCOPE)
                self.addCleanup(attempt.close)
                with mock.patch.object(
                    linking, '_request_json',
                    side_effect=[envelope, handoff_response(issuer)],
                ) as request:
                    attempt.push_request(
                        prefer_upload=True, prefer_rolling=vault, prefer_presentation=True,
                    )
                self.assertEqual(
                    [expected], parse_qs(request.call_args.args[3].decode('ascii'))['scope'],
                )
                self.assertEqual(linking.UPLOAD_SCOPE, linking.scope_base(expected))
                self.assertEqual(
                    'galleries:search' in expected.split(),
                    linking.scope_allows_gallery_search(expected),
                )
                self.assertEqual(
                    'presentation:read' in expected.split(),
                    linking.scope_allows_presentation(expected),
                )

        for invalid in (
            listing + ' presentation:read' + rolling,
            listing + presentation + ' offline_access',
            listing + rolling + presentation,
            search + presentation,
            listing + linking.MEDIA_SCOPE_SUFFIX + presentation,
        ):
            with self.subTest(invalid=invalid):
                self.assertFalse(linking.scope_allows_presentation(invalid))
                self.assertIsNone(linking.scope_base(invalid))

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
            self.assertFalse(session._thumbnail_batch_supported)
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
                discovery_response(
                    'https://gallery.example', linking.GALLERIES_SCOPE,
                    (linking.THUMBNAIL_BATCH_CAPABILITY,),
                ),
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
            self.assertTrue(session._thumbnail_batch_supported)
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

            def acquire(
                self, timeout: float, *,
                cancelled: threading.Event | None = None,
            ) -> bool:
                """Wait for the gate and announce a queued gallery operation.

                Args:
                    timeout (float): Maximum gate wait in seconds.
                    cancelled (threading.Event | None): Caller cancellation
                        forwarded to the real request gate.

                Returns:
                    bool: Whether the underlying gate was acquired.
                """
                if started.is_set():
                    waiting.set()
                return gate.acquire(timeout=timeout, cancelled=cancelled)

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

    def test_gallery_tree_requires_exact_complete_or_matching_unchanged_envelope(self) -> None:
        """Accept only exact ordered tree-v1 rows and matching reuse proof.

        Returns:
            None: Unknown fields, coercible types and changed revisions fail closed.
        """
        revision = 'a' * 64
        complete: dict[str, linking.JsonValue] = {
            'version': 1,
            'revision': revision,
            'unchanged': False,
            'galleries': [
                {'id': 2, 'parent_id': 1, 'title': 'Child'},
                {'id': 9, 'parent_id': None, 'title': 'Root'},
            ],
        }
        tree = linking._linked_gallery_tree(complete, None)
        self.assertEqual((2, 9), tuple(row.gallery_id for row in tree.galleries))
        self.assertEqual(revision, tree.revision)
        self.assertFalse(tree.unchanged)
        unchanged: dict[str, linking.JsonValue] = {
            'version': 1, 'revision': revision, 'unchanged': True,
        }
        self.assertTrue(linking._linked_gallery_tree(unchanged, revision).unchanged)
        invalid = (
            ({**complete, 'extra': True}, None),
            ({**complete, 'version': True}, None),
            ({**complete, 'revision': 'G' * 64}, None),
            ({**complete, 'galleries': list(reversed(complete['galleries']))}, None),
            ({**complete, 'galleries': [
                {'id': 2, 'parent_id': None, 'title': 'First'},
                {'id': 2, 'parent_id': None, 'title': 'Duplicate'},
            ]}, None),
            ({**complete, 'galleries': [
                {'id': 2, 'parent_id': True, 'title': 'Bad parent'},
            ]}, None),
            ({**complete, 'galleries': [
                {'id': 2, 'parent_id': None, 'title': 42},
            ]}, None),
            ({**complete, 'galleries': [
                {'id': 2, 'parent_id': None, 'title': '\ud800'},
            ]}, None),
            ({**unchanged, 'galleries': []}, revision),
            (unchanged, None),
            ({**unchanged, 'revision': 'b' * 64}, revision),
            ({**complete, 'galleries': [
                {'id': True, 'parent_id': None, 'title': 'Bad'},
            ]}, None),
        )
        for payload, requested in invalid:
            with self.subTest(payload=payload):
                with self.assertRaises(linking.LinkingError):
                    linking._linked_gallery_tree(payload, requested)
        bounded_rows: list[linking.JsonValue] = [
            {'id': gallery_id, 'parent_id': None, 'title': 'Row'}
            for gallery_id in range(
                1, linking.LINKING_GALLERY_TREE_MAX_ROWS + 1,
            )
        ]
        bounded = {**complete, 'galleries': bounded_rows}
        self.assertEqual(
            linking.LINKING_GALLERY_TREE_MAX_ROWS,
            len(linking._linked_gallery_tree(bounded, None).galleries),
        )
        over_limit = {
            **complete,
            'galleries': bounded_rows + [
                {'id': linking.LINKING_GALLERY_TREE_MAX_ROWS + 1,
                 'parent_id': None, 'title': 'One too many'},
            ],
        }
        with self.assertRaises(linking.LinkingError):
            linking._linked_gallery_tree(over_limit, None)

    def test_gallery_tree_is_capability_gated_and_falls_back_safely(self) -> None:
        """Use tree-v1 only when advertised and preserve terminal refusals.

        Returns:
            None: Old-server and malformed replies fall back; auth, cancel and
                rate-limit results remain visible to the existing caller.
        """
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        issuer = 'https://gallery.example/Galerie'
        revision = 'c' * 64
        response: dict[str, linking.JsonValue] = {
            'version': 1, 'revision': revision, 'unchanged': False,
            'galleries': [{'id': 3, 'parent_id': None, 'title': 'Fresh'}],
        }
        session = linking.LinkingSession(
            issuer, token, time.monotonic() + 120,
            linking.GALLERIES_SCOPE, gallery_tree_supported=True,
        )
        self.addCleanup(session.close)
        with mock.patch.object(linking, '_request_json', return_value=response) as request:
            tree = session.gallery_tree(revision)
        self.assertIsNotNone(tree)
        self.assertEqual(3, tree.galleries[0].gallery_id)
        self.assertEqual('app_linking_gallery_tree', request.call_args.args[1])
        self.assertEqual('GET', request.call_args.args[2])
        self.assertEqual(revision, request.call_args.kwargs['tree_revision'])
        with mock.patch.object(
            linking, '_request_json',
            return_value={
                'version': 1, 'revision': revision, 'unchanged': True,
            },
        ):
            unchanged = session.gallery_tree(revision)
        self.assertIsNotNone(unchanged)
        self.assertTrue(unchanged.unchanged)
        with mock.patch.object(
            linking, '_request_json',
            return_value={
                'version': 1, 'revision': 'e' * 64, 'unchanged': True,
            },
        ):
            self.assertIsNone(session.gallery_tree(revision))

        cancelled = threading.Event()
        cancelled.set()
        with mock.patch.object(linking, '_request_json') as request:
            with self.assertRaises(linking.LinkingError):
                session.gallery_tree(cancelled=cancelled)
            request.assert_not_called()

        unsupported = linking.LinkingSession(
            issuer, token, time.monotonic() + 120, linking.GALLERIES_SCOPE,
        )
        self.addCleanup(unsupported.close)
        with mock.patch.object(linking, '_request_json') as request:
            self.assertIsNone(unsupported.gallery_tree())
            request.assert_not_called()

        for error in (
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 400, 'html', 'http_status',
            ),
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 503, 'json', 'http_status',
            ),
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 201, 'json', 'http_status',
            ),
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 302, 'html', 'http_status',
            ),
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 410, 'json', 'http_status',
            ),
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 422, 'json', 'http_status',
            ),
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', None, 'other',
                'connect_failure',
            ),
        ):
            with self.subTest(error=type(error).__name__), mock.patch.object(
                linking, '_request_json', side_effect=error,
            ):
                self.assertIsNone(session.gallery_tree())
        malformed: dict[str, linking.JsonValue] = {
            'version': 1, 'revision': revision, 'unchanged': False,
            'galleries': [{'id': '3', 'parent_id': None, 'title': 'Bad ID'}],
        }
        with mock.patch.object(
            linking, '_request_json', return_value=malformed,
        ):
            self.assertIsNone(session.gallery_tree())
        for error in (
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 401, 'json', 'http_status',
            ),
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 403, 'json', 'http_status',
            ),
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 429, 'json', 'http_status',
            ),
            linking.LinkingResponseError(
                'linking', 'app_linking_gallery_tree', 503, 'json', 'throttled',
            ),
            linking.LinkingMediaThrottled(),
            linking.LinkingError('Linking request expired or was cancelled.'),
        ):
            with self.subTest(error=type(error).__name__), mock.patch.object(
                linking, '_request_json', side_effect=error,
            ):
                with self.assertRaises(type(error)):
                    session.gallery_tree()

    def test_gallery_tree_wire_route_uses_its_own_response_bound(self) -> None:
        """Map tree-v1 to the existing route without changing page limits.

        Returns:
            None: Revision query and the tree-only response size cap are exact.
        """
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b't' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        headers = Message()
        headers.add_header('Content-Type', 'application/json')
        headers.add_header('Cache-Control', 'no-store')
        headers.add_header(
            'Server-Timing',
            'gallery-php;dur=1.000, '
            'gallery-sql;dur=0.500;desc="n=1;max_ms=0.500;failed=0;cap=0", '
            'gallery-auth;dur=0.200, gallery-tree;dur=0.200, '
            'gallery-hash;dur=0.000;desc="bytes=0", '
            'gallery-media;dur=0.000',
        )
        response = mock.Mock()
        response.status = 200
        response.headers = headers
        response.read.return_value = b'{}'
        response.__enter__ = mock.Mock(return_value=response)
        response.__exit__ = mock.Mock(return_value=False)
        connection = mock.Mock()
        connection.getresponse.return_value = response
        control = linking._LinkingTransportControl(
            time.monotonic() + 60, None,
        )
        control.server_timing_out = []
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            linking._request_json_once(
                'https://gallery.example/Galerie',
                'app_linking_gallery_tree', 'GET', None, token, control,
                tree_revision='d' * 64,
            )
        self.assertEqual(1, len(control.server_timing_out))
        php_sample, client_headers_ms = control.server_timing_out[0]
        self.assertIsNotNone(php_sample)
        self.assertEqual(1.0, php_sample.php_ms)
        self.assertIsInstance(client_headers_ms, float)
        sent_path = connection.request.call_args.args[1]
        self.assertEqual(
            '/Galerie/index.php?page=app_linking_galleries&tree=1&revision='
            + 'd' * 64,
            sent_path,
        )
        self.assertEqual(
            linking.LINKING_GALLERY_TREE_RESPONSE_MAX_BYTES + 1,
            response.read.call_args.args[0],
        )
        control.stop()
        page_control = linking._LinkingTransportControl(
            time.monotonic() + 60, None,
        )
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            linking._request_json_once(
                'https://gallery.example/Galerie',
                'app_linking_galleries', 'GET', None, token, page_control,
            )
        self.assertEqual(
            linking.LINKING_GALLERIES_RESPONSE_MAX_BYTES + 1,
            response.read.call_args.args[0],
        )
        page_control.stop()
        response.read.return_value = b'x' * TEST_GALLERY_TREE_OVER_LIMIT_BYTES
        rejected_control = linking._LinkingTransportControl(
            time.monotonic() + 60, None,
        )
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            with self.assertRaises(linking.LinkingResponseError) as raised:
                linking._request_json_once(
                    'https://gallery.example/Galerie',
                    'app_linking_gallery_tree', 'GET', None, token,
                    rejected_control,
                )
        self.assertEqual('response_size', raised.exception.reason)
        rejected_control.stop()

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
            server_timing_out: list[
                tuple[linking.PassiveServerTiming | None, float | None]
            ] | None = None,
        ) -> dict[str, linking.JsonValue]:
            """Wait for the page owner's explicit cancellation signal.

            Args:
                issuer (str): Trusted HTTPS issuer for this request.
                route (str): Fixed gallery-list route.
                method (str): Fixed GET method.
                body (bytes | None): Optional body, absent for this GET.
                bearer (str | None): In-memory bearer for the approved session.
                cancelled (threading.Event | None): Request cancellation signal.
                server_timing_out (list | None): Optional response timing
                    sink, left empty by the cancelled test transport.

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

    def test_only_exact_refresh_error_is_a_definite_rejection(self) -> None:
        """Distinguish a complete invalid-grant reply from uncertain failures.

        Returns:
            None: The exact response is classified after one same-issuer POST.
        """
        issuer = 'https://gallery.example/Galerie'
        refresh_body = b'grant_type=refresh_token&refresh_token=fixture&client_id=fixture'
        refusal = b'{"error":"invalid_grant"}'
        for status, form, cache, payload, truncated, definite in (
            (400, refresh_body, 'no-store', refusal, False, True),
            (400, refresh_body, 'no-store', refusal, True, False),
            (400, refresh_body, 'private', refusal, False, False),
            (400, refresh_body, 'no-store', b'{"error":"invalid_request"}', False, False),
            (400, refresh_body, 'no-store',
             b'{"error":"invalid_grant","extra":true}', False, False),
            (400, b'grant_type=authorization_code&code=fixture',
             'no-store', refusal, False, False),
            (503, refresh_body, 'no-store', refusal, False, False),
        ):
            with self.subTest(status=status, form=form, cache=cache, payload=payload,
                              truncated=truncated):
                headers = Message()
                headers['Content-Type'] = 'application/json'
                headers['Cache-Control'] = cache
                headers['Content-Length'] = str(len(payload) + int(truncated))
                response = mock.MagicMock()
                response.__enter__.return_value = response
                response.status = status
                response.headers = headers
                response.read.return_value = payload
                connection = mock.MagicMock()
                connection.getresponse.return_value = response
                with mock.patch.object(
                    linking, '_DeadlineHTTPSConnection', return_value=connection,
                ) as factory, self.assertRaises(linking.LinkingError) as caught:
                    linking._request_json(issuer, 'app_linking_token', 'POST', form, None)
                self.assertEqual(definite, isinstance(
                    caught.exception, linking.LinkingRefreshRejected,
                ))
                self.assertEqual('gallery.example', factory.call_args.args[0])
                connection.request.assert_called_once()
                self.assertEqual(
                    ('POST', '/Galerie/index.php?page=app_linking_token'),
                    connection.request.call_args.args[:2],
                )

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
        self.assertEqual(
            linking.LINKING_USER_AGENT, call.kwargs['headers']['User-Agent'],
        )

    def test_html_and_http_failures_keep_only_safe_response_metadata(self) -> None:
        """Preserve bounded response facts while discarding unsafe body text.

        Returns:
            None: HTML 401 and JSON 429 failures report fixed stage, route,
                status, response kind and reason without retaining body content.
        """
        issuer = 'https://gallery.example/Galerie'
        cases = (
            (401, 'text/html', b'<html>private gateway secret</html>',
             'response_kind=html; reason=http_status'),
            (429, 'application/json', b'{\"secret\":\"private payload\"}',
             'response_kind=json; reason=http_status'),
            (200, 'application/problem+json', b'{\"secret\":\"private payload\"}',
             'response_kind=json; reason=content_type'),
        )
        for status, media_type, body, expected in cases:
            with self.subTest(status=status, media_type=media_type):
                headers = Message()
                headers['Content-Type'] = media_type
                headers['Cache-Control'] = 'no-store'
                response = mock.MagicMock()
                response.__enter__.return_value = response
                response.status = status
                response.headers = headers
                response.read.return_value = body
                connection = mock.MagicMock()
                connection.getresponse.return_value = response
                with mock.patch.object(
                    linking, '_DeadlineHTTPSConnection',
                    return_value=connection,
                ), self.assertRaises(linking.LinkingResponseError) as raised:
                    linking._request_json(
                        issuer, 'app_linking_discovery', 'GET', None, None,
                    )
                summary = raised.exception.safe_summary
                self.assertIn('stage=discovery', summary)
                self.assertIn('route=app_linking_discovery', summary)
                self.assertIn(f'http={status}', summary)
                self.assertIn(expected, summary)
                self.assertNotIn(body.decode('ascii'), summary)
                self.assertNotIn('private', str(raised.exception))

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

    def test_photo_visibility_posts_only_to_the_fixed_media_route(self) -> None:
        """Keep the photo write operation on the existing public route.

        Returns:
            None: The exact fixed path carries a bearer and a JSON POST body.
        """
        headers = Message()
        headers['Content-Type'] = 'application/json'
        headers['Cache-Control'] = 'no-store'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.headers = headers
        response.status = 409
        response.read.return_value = b'{"error":"revision_conflict"}'
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        with mock.patch.object(linking, '_DeadlineHTTPSConnection', return_value=connection):
            result = linking._request_json(
                'https://gallery.example/Galerie',
                'app_linking_photo_visibility', 'POST', b'{}', 'fixture',
            )
            with self.assertRaises(linking.LinkingError):
                linking._request_json(
                    'https://gallery.example/Galerie',
                    'app_linking_photo_visibility', 'GET', None, 'fixture',
                )
        self.assertEqual({'__client_visibility_conflict': 'revision_conflict'}, result)
        connection.request.assert_called_once_with(
            'POST', '/Galerie/index.php?page=app_linking_media', body=b'{}',
            headers={
                'Accept': 'application/json',
                'Authorization': 'Bearer fixture',
                'Content-Type': 'application/json; charset=utf-8',
                'User-Agent': linking.LINKING_USER_AGENT,
            },
        )

    def test_watcher_credential_lifecycle_and_request_header(self) -> None:
        """Register a digest and restrict the raw key to capture requests.

        Returns:
            None: Lifecycle bodies contain the same digest, and only the
                authenticated capture request carries the raw key header.
        """
        issuer = 'https://gallery.example/Galerie'
        token = 'pgla_' + 'A' * 43
        gallery_id = 7
        session = linking.LinkingSession(
            issuer, token, time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.UPLOAD_SCOPE,
        )
        headers = Message()
        headers['Content-Type'] = 'application/json'
        headers['Cache-Control'] = 'no-store'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.headers = headers
        response.status = 200
        response.read.side_effect = [
            json.dumps({'ok': True, 'action': action, 'gallery_id': gallery_id}).encode()
            for action in ('watch_open', 'watch_close')
        ] + [b'{}']
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        with mock.patch.object(linking, '_DeadlineHTTPSConnection', return_value=connection):
            key = session.open_watch_key(gallery_id)
            session.close_watch_key(gallery_id, key)
            linking._request_json(
                issuer, 'app_linking_inventory', 'POST', b'{}', token, watch_key=key,
            )
            with self.assertRaises(linking.LinkingError):
                linking._request_json(
                    issuer, 'app_linking_identity', 'GET', None, token, watch_key=key,
                )
        calls = connection.request.call_args_list
        self.assertEqual(3, len(calls))
        for call, action in zip(calls[:2], ('watch_open', 'watch_close')):
            self.assertEqual({
                'action': action, 'gallery_id': gallery_id,
                'key_hash': hashlib.sha256(key.encode('ascii')).hexdigest(),
            }, json.loads(call.kwargs['body']))
            self.assertNotIn('X-PHP-Gallery-Watch-Key', call.kwargs['headers'])
        self.assertEqual(key, calls[2].kwargs['headers']['X-PHP-Gallery-Watch-Key'])

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

    def test_transport_waits_for_worker_slot_released_by_prior_request(self) -> None:
        """Admit a request when the outstanding worker finally releases its slot.

        Returns:
            None: The waiting caller starts once and leaves the semaphore available.
        """
        slots = linking._LINKING_TRANSPORT_SLOTS
        self.assertTrue(slots.acquire(blocking=False))
        waiting = threading.Event()
        request_started = threading.Event()
        outcome: list[str] = []
        original_thread = threading.Thread

        def acquire_wait(*, timeout: float) -> bool:
            """Signal that the caller reached slot admission before waiting.

            Args:
                timeout (float): Remaining bounded transport deadline slice.

            Returns:
                bool: Result from the real process-wide bounded semaphore.
            """
            waiting.set()
            return slots.acquire(timeout=timeout)

        def request_once(
            control: linking._LinkingTransportControl,
        ) -> dict[str, linking.JsonValue]:
            """Complete one synthetic request after successful admission.

            Args:
                control (_LinkingTransportControl): Admitted deadline owner.

            Returns:
                dict[str, linking.JsonValue]: Empty successful response object.
            """
            control.remaining()
            request_started.set()
            return {}

        def call_transport() -> None:
            """Record the waiting caller's safe completion state.

            Returns:
                None: Appends success or a fixed linking failure marker.
            """
            try:
                linking._run_linking_transport(
                    request_once, None, TEST_WORKER_WAIT_SECONDS,
                    TEST_WORKER_WAIT_SECONDS,
                )
            except linking.LinkingError:
                outcome.append('error')
            else:
                outcome.append('success')

        gate = mock.Mock()
        gate.acquire.side_effect = acquire_wait
        gate.release.side_effect = slots.release
        caller = original_thread(target=call_transport, daemon=True)
        slots_held_by_fixture = True
        try:
            with mock.patch.object(linking, '_LINKING_TRANSPORT_SLOTS', gate):
                caller.start()
                self.assertTrue(waiting.wait(TEST_WORKER_WAIT_SECONDS))
                slots.release()
                slots_held_by_fixture = False
                caller.join(TEST_WORKER_WAIT_SECONDS)
                self.assertFalse(caller.is_alive())
        finally:
            if slots_held_by_fixture:
                slots.release()

        self.assertEqual(['success'], outcome)
        self.assertTrue(request_started.is_set())
        self.assertEqual(1, gate.release.call_count)
        self.assertTrue(slots.acquire(timeout=TEST_WORKER_WAIT_SECONDS))
        slots.release()

    def test_cancel_while_waiting_does_not_create_worker_or_leak_slot(self) -> None:
        """Cancel bounded admission without spawning transport work.

        Returns:
            None: Cancellation returns promptly and leaves the fixture-owned slot.
        """
        slots = linking._LINKING_TRANSPORT_SLOTS
        self.assertTrue(slots.acquire(blocking=False))
        slots_held_by_fixture = True
        waiting = threading.Event()
        cancelled = threading.Event()
        outcome: list[str] = []
        original_thread = threading.Thread

        def acquire_wait(*, timeout: float) -> bool:
            """Observe a wait while delegating to the held real semaphore.

            Args:
                timeout (float): Remaining deadline slice for this wait.

            Returns:
                bool: Whether the real process-wide slot was admitted.
            """
            waiting.set()
            return slots.acquire(timeout=timeout)

        def call_transport() -> None:
            """Capture a fixed terminal marker for the waiting caller.

            Returns:
                None: Reports only whether the bounded call was refused.
            """
            try:
                linking._run_linking_transport(
                    lambda _control: {}, cancelled, TEST_WORKER_WAIT_SECONDS,
                    TEST_WORKER_WAIT_SECONDS,
                )
            except linking.LinkingError:
                outcome.append('cancelled')
            else:
                outcome.append('unexpected-success')

        gate = mock.Mock()
        gate.acquire.side_effect = acquire_wait
        gate.release.side_effect = slots.release
        thread_factory = mock.Mock()
        caller = original_thread(target=call_transport, daemon=True)
        try:
            with mock.patch.object(linking, '_LINKING_TRANSPORT_SLOTS', gate), \
                    mock.patch.object(linking.threading, 'Thread', thread_factory):
                original_thread.start(caller)
                self.assertTrue(waiting.wait(TEST_WORKER_WAIT_SECONDS))
                cancelled.set()
                caller.join(TEST_WORKER_WAIT_SECONDS)
                self.assertFalse(caller.is_alive())
                fixture_slot_was_available = slots.acquire(blocking=False)
                if fixture_slot_was_available:
                    # Balance the probe, then avoid releasing the fixture's
                    # already-released permit a second time in ``finally``.
                    slots.release()
                    slots_held_by_fixture = False
                self.assertFalse(fixture_slot_was_available)
        finally:
            if slots_held_by_fixture:
                slots.release()

        self.assertEqual(['cancelled'], outcome)
        thread_factory.assert_not_called()
        gate.release.assert_not_called()
        self.assertTrue(slots.acquire(timeout=TEST_WORKER_WAIT_SECONDS))
        slots.release()

    def test_transport_deadline_includes_held_slot_admission(self) -> None:
        """Expire while a prior worker owns the only slot, before spawning.

        Returns:
            None: Admission consumes the total deadline and creates no worker.
        """
        slots = linking._LINKING_TRANSPORT_SLOTS
        self.assertTrue(slots.acquire(blocking=False))
        waiting = threading.Event()
        original_thread = threading.Thread

        def acquire_wait(*, timeout: float) -> bool:
            """Signal bounded admission and delegate to the held semaphore.

            Args:
                timeout (float): Remaining total-request deadline slice.

            Returns:
                bool: Whether the fixture's real semaphore became available.
            """
            waiting.set()
            return slots.acquire(timeout=timeout)

        gate = mock.Mock()
        gate.acquire.side_effect = acquire_wait
        gate.release.side_effect = slots.release
        thread_factory = mock.Mock()
        started = time.monotonic()
        try:
            with mock.patch.object(linking, '_LINKING_TRANSPORT_SLOTS', gate), \
                    mock.patch.object(linking.threading, 'Thread', thread_factory):
                with self.assertRaises(linking.LinkingResponseError) as raised:
                    linking._run_linking_transport(
                        lambda _control: {}, None,
                        TEST_REQUEST_DEADLINE_SECONDS,
                        TEST_WORKER_WAIT_SECONDS,
                    )
        finally:
            slots.release()

        elapsed = time.monotonic() - started
        self.assertTrue(waiting.is_set())
        self.assertIn('reason=timeout; transport_step=slot',
                      raised.exception.safe_summary)
        self.assertGreaterEqual(elapsed, TEST_ADMISSION_WAIT_MIN_SECONDS)
        self.assertLess(elapsed, TEST_WORKER_WAIT_SECONDS)
        thread_factory.assert_not_called()
        gate.release.assert_not_called()

    def test_json_header_timeout_keeps_its_observed_transport_step(self) -> None:
        """Report the JSON response-header wait without its issuer or error text.

        Returns:
            None: The safe linking diagnostic records only the headers step.
        """
        connection = mock.MagicMock()
        connection.getresponse.side_effect = socket.timeout(
            'https://private.example/Galerie bearer=fixture-secret',
        )
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            with self.assertRaises(linking.LinkingResponseError) as raised:
                linking._request_json(
                    'https://private.example/Galerie',
                    'app_linking_discovery', 'GET', None, None,
                )

        self.assertEqual('app_linking_discovery', raised.exception.route)
        self.assertEqual('headers', raised.exception.transport_step)
        self.assertNotIn('private.example', raised.exception.safe_summary)
        self.assertNotIn('fixture-secret', raised.exception.safe_summary)
        self.assertNotIn('bearer=', raised.exception.safe_summary)

    def test_cancel_racing_slot_acquire_releases_once_without_worker(self) -> None:
        """Release a just-acquired slot exactly once when cancellation races it.

        Returns:
            None: The caller refuses before creating a transport worker.
        """
        slots = linking._LINKING_TRANSPORT_SLOTS
        cancelled = threading.Event()
        original_thread = threading.Thread

        def acquire_then_cancel(*, timeout: float) -> bool:
            """Acquire the actual slot and set cancellation before returning.

            Args:
                timeout (float): Remaining total-request deadline slice.

            Returns:
                bool: True after the actual semaphore grants its slot.
            """
            acquired = slots.acquire(timeout=timeout)
            if acquired:
                cancelled.set()
            return acquired

        gate = mock.Mock()
        gate.acquire.side_effect = acquire_then_cancel
        gate.release.side_effect = slots.release
        thread_factory = mock.Mock()
        with mock.patch.object(linking, '_LINKING_TRANSPORT_SLOTS', gate), \
                mock.patch.object(linking.threading, 'Thread', thread_factory):
            with self.assertRaises(linking.LinkingError):
                linking._run_linking_transport(
                    lambda _control: {}, cancelled, TEST_WORKER_WAIT_SECONDS,
                    TEST_WORKER_WAIT_SECONDS,
                )

        self.assertTrue(cancelled.is_set())
        thread_factory.assert_not_called()
        self.assertEqual(1, gate.release.call_count)
        self.assertTrue(slots.acquire(timeout=TEST_WORKER_WAIT_SECONDS))
        slots.release()

    def test_transport_thread_start_failure_releases_admission_once(self) -> None:
        """Preserve slot accounting when the native worker cannot start.

        Returns:
            None: Start refusal maps to LinkingError and releases the slot once.
        """
        slots = linking._LINKING_TRANSPORT_SLOTS
        gate = mock.Mock()
        gate.acquire.side_effect = slots.acquire
        gate.release.side_effect = slots.release
        failed_worker = mock.Mock()
        failed_worker.start.side_effect = RuntimeError('fixture failure')
        thread_factory = mock.Mock(return_value=failed_worker)
        with mock.patch.object(linking, '_LINKING_TRANSPORT_SLOTS', gate), \
                mock.patch.object(linking.threading, 'Thread', thread_factory):
            with self.assertRaises(linking.LinkingError):
                linking._run_linking_transport(
                    lambda _control: {}, None, TEST_WORKER_WAIT_SECONDS,
                    TEST_WORKER_WAIT_SECONDS,
                )

        thread_factory.assert_called_once()
        failed_worker.start.assert_called_once_with()
        self.assertEqual(1, gate.release.call_count)
        self.assertTrue(slots.acquire(timeout=TEST_WORKER_WAIT_SECONDS))
        slots.release()

    def test_late_dns_does_not_start_connection_and_bounds_new_workers(self) -> None:
        """Cancel DNS work and bound a later caller waiting for its worker slot.

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
            worker_starts: list[str] = []
            original_start = threading.Thread.start

            def count_transport_start(thread: threading.Thread) -> None:
                """Count a second linker worker without changing thread behavior.

                Args:
                    thread (threading.Thread): Worker candidate being started.

                Returns:
                    None: Starts the thread only when the transport admits it.
                """
                if thread.name == 'PHPGalleryLinkTransport':
                    worker_starts.append(thread.name)
                original_start(thread)

            with mock.patch.object(
                linking, 'LINKING_REQUEST_DEADLINE_SECONDS',
                TEST_REQUEST_DEADLINE_SECONDS,
            ), mock.patch.object(
                linking.threading.Thread, 'start', count_transport_start,
            ):
                waiting_started = time.monotonic()
                with self.assertRaises(linking.LinkingError):
                    linking._request_json(
                        'https://gallery.example', 'app_linking_push', 'POST',
                        b'client_id=php-gallery-winapp', None,
                    )
                self.assertGreaterEqual(
                    time.monotonic() - waiting_started,
                    TEST_ADMISSION_WAIT_MIN_SECONDS,
                )
                self.assertLess(
                    time.monotonic() - waiting_started,
                    TEST_WORKER_WAIT_SECONDS,
                )
            self.assertEqual([], worker_starts)
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
        ), offline_linking._OFFLINE_SCOPES[9:13])
        # Persisted scope indices are append-only. Pin the exact wire order
        # and verify that rolling access adds no visibility, read or create.
        rolling = (
            'link:identity galleries:list rolling_access offline_access',
            'link:identity galleries:list gallery:upload rolling_access offline_access',
            'link:identity galleries:list galleries:search rolling_access offline_access',
            'link:identity galleries:list gallery:upload galleries:search '
            'rolling_access offline_access',
        )
        presentation = (
            'link:identity galleries:list media:read presentation:read '
            'rolling_access offline_access',
            'link:identity galleries:list gallery:upload media:read presentation:read '
            'rolling_access offline_access',
            'link:identity galleries:list galleries:search media:read presentation:read '
            'rolling_access offline_access',
            'link:identity galleries:list gallery:upload galleries:search '
            'media:read presentation:read rolling_access offline_access',
        )
        # Basic rolling occupies persisted slots 13–16. Presentation was
        # appended in slots 17–20, leaving every earlier vault record intact.
        self.assertEqual(rolling, offline_linking._OFFLINE_SCOPES[13:17])
        self.assertEqual(presentation, offline_linking._OFFLINE_SCOPES[17:21])
        # Pin the exact Admin wire permutations independently of their production
        # construction. Appending them must keep all previous byte indices.
        admin_bases = (
            '', ' gallery:upload', ' gallery:read',
            ' gallery:read gallery:upload', ' galleries:create',
            ' gallery:read galleries:create', ' gallery:upload galleries:create',
            ' gallery:read gallery:upload galleries:create',
            ' gallery:read gallery:visibility',
            ' gallery:read gallery:upload gallery:visibility',
            ' gallery:read galleries:create gallery:visibility',
            ' gallery:read gallery:upload galleries:create gallery:visibility',
        )
        admin_rolling = tuple(
            'link:identity galleries:list' + base + search
            + ' media:admin' + playback + ' rolling_access offline_access'
            for base in admin_bases
            for search in ('', ' galleries:search')
            for playback in ('', ' presentation:read')
        )
        self.assertEqual(admin_rolling, linking.ROLLING_ADMIN_MEDIA_SCOPES)
        self.assertEqual(admin_rolling, offline_linking._OFFLINE_SCOPES[21:69])
        admin_delete_rolling = tuple(
            scope.replace(
                ' rolling_access offline_access',
                ' media:delete rolling_access offline_access',
            )
            for scope in admin_rolling
        )
        self.assertEqual(
            admin_delete_rolling, linking.ROLLING_ADMIN_DELETE_SCOPES,
        )
        self.assertEqual(admin_delete_rolling, offline_linking._OFFLINE_SCOPES[69:])
        for bundles, has_presentation in ((rolling, False), (presentation, True)):
            for scope, base, has_search in zip(bundles, (
                linking.GALLERIES_SCOPE, linking.UPLOAD_SCOPE,
                linking.GALLERIES_SCOPE, linking.UPLOAD_SCOPE,
            ), (False, False, True, True)):
                with self.subTest(scope=scope):
                    self.assertEqual(base, linking.scope_base(scope))
                    self.assertEqual(has_search, linking.scope_allows_gallery_search(scope))
                    self.assertEqual(
                        has_presentation, linking.scope_allows_presentation(scope),
                    )
                    self.assertFalse(linking.scope_allows_gallery_visibility(scope))
                    self.assertFalse(linking.scope_allows_gallery_read(scope))
                    self.assertFalse(linking.scope_allows_gallery_create(scope))
                    self.assertIsNone(linking.scope_base(scope + ' gallery:visibility'))

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
    """Verify authorized media scope, cache reads and resource identities."""

    def test_cached_media_photo_returns_verified_hit_without_network(self) -> None:
        """Expose a current local hit without entering authenticated transport.

        Returns:
            None: The verified body is returned and network admission is unused.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        photo = linking.LinkedPhoto(
            image_id=7, title='photo', cache_eligible=True,
        )
        body = b'cached image bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        timings = linking.MediaReadTimings()
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            self.assertTrue(cache.write(
                cache.key(issuer, 4, 7, True), body, 'image/jpeg', etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(session, '_media_read') as network, mock.patch.object(
                session, '_request_gate',
            ) as gate:
                gate.acquire.side_effect = AssertionError('Unexpected request gate.')
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.cached_media_photo(
                        4, photo, thumbnail=True, timings=timings,
                    ),
                )
                network.assert_not_called()
                gate.acquire.assert_not_called()
        self.assertEqual('disk', timings.source)
        self.assertEqual('hit', timings.cache_state)

    def test_cached_media_photo_returns_none_on_cache_miss(self) -> None:
        """Return an explicit miss when the requested entry is absent.

        Returns:
            None: A missing disk entry returns None without network fallback.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        photo = linking.LinkedPhoto(
            image_id=7, title='photo', cache_eligible=True,
        )
        timings = linking.MediaReadTimings()
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(cache, 'read', return_value=None):
                self.assertIsNone(session.cached_media_photo(
                    4, photo, timings=timings,
                ))
        self.assertEqual('miss', timings.cache_state)

    def test_cached_media_photo_rejects_stale_and_changed_proofs(self) -> None:
        """Require exact freshness and descriptor ETag proof for local hits.

        Returns:
            None: Stale entries and changed thumbnail validators are misses.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        body = b'retained thumbnail'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        changed_etag = '"' + hashlib.sha256(b'changed bytes').hexdigest() + '"'
        descriptor = linking.LinkedPhoto(
            7, 'photo', cache_eligible=True,
            thumbnail_etag=changed_etag,
            thumbnail_verified_at=time.monotonic(),
        )
        timings = linking.MediaReadTimings()
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            self.assertTrue(cache.write(
                cache.key(issuer, 4, 7, True), body, 'image/jpeg', etag,
            ))
            entry = cache.read(cache.key(issuer, 4, 7, True))
            self.assertIsNotNone(entry)
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ):
                self.assertIsNone(session.cached_media_photo(
                    4, descriptor, timings=timings,
                ))
                self.assertEqual('changed', timings.cache_state)
                stale_now = (
                    entry.stored_at + linking.PHOTO_CACHE_FRESH_SECONDS + 1
                )
                with mock.patch.object(
                    linking.time, 'time', return_value=stale_now,
                ):
                    self.assertIsNone(session.cached_media_photo(
                        4,
                        linking.LinkedPhoto(7, 'photo', cache_eligible=True),
                        timings=timings,
                    ))
                self.assertEqual('revalidation', timings.cache_state)

    def test_cached_media_photo_checks_exact_issuer_and_cancellation(self) -> None:
        """Keep cache identities issuer-scoped and honor viewer cancellation.

        Returns:
            None: A different issuer misses and a canceled viewer gets no bytes.
        """
        issuer = 'https://gallery.example/Galerie'
        other_issuer = 'https://other.example/Galerie'
        session = linking.LinkingSession(
            other_issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        body = b'issuer-bound thumbnail'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        photo = linking.LinkedPhoto(7, 'photo', cache_eligible=True)
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            self.assertTrue(cache.write(
                cache.key(issuer, 4, 7, True), body, 'image/jpeg', etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ):
                self.assertIsNone(session.cached_media_photo(4, photo))
                cancelled = threading.Event()
                cancelled.set()
                with self.assertRaises(linking.LinkingError):
                    session.cached_media_photo(
                        4, photo, cancelled=cancelled,
                    )

    def test_cached_media_photo_rejects_expired_session_and_private_preload(self) -> None:
        """Do not expose expired or private preload bytes through local lookup.

        Returns:
            None: Renewal-capable expiry and private Admin entries remain misses.
        """
        issuer = 'https://gallery.example/Galerie'
        expired_at = time.monotonic() - TEST_WORKER_WAIT_SECONDS
        renewed_until = time.monotonic() + TEST_WORKER_WAIT_SECONDS
        rolling = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43, renewed_until,
            linking.ROLLING_GALLERIES_SCOPE,
            admin_preload_supported=True,
        )
        rolling._expires_at = expired_at
        rolling.enable_renewal(lambda _cancel: (
            'pgla_' + 'B' * 43, renewed_until,
        ))
        rolling_photo = linking.LinkedPhoto(7, 'photo', cache_eligible=True)
        private = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
            admin_preload_supported=True,
        )
        private_photo = linking.LinkedPhoto(
            7, 'photo', preload_eligible=True,
        )
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            expired_body = b'current cached thumbnail'
            expired_etag = '"' + hashlib.sha256(expired_body).hexdigest() + '"'
            self.assertTrue(cache.write(
                cache.key(issuer, 4, 7, True), expired_body,
                'image/jpeg', expired_etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                linking.time, 'monotonic',
                return_value=expired_at + TEST_WORKER_WAIT_SECONDS,
            ):
                expired_timings = linking.MediaReadTimings()
                self.assertIsNone(rolling.cached_media_photo(
                    4, rolling_photo, timings=expired_timings,
                ))
                self.assertEqual('expired', expired_timings.cache_state)
                private_timings = linking.MediaReadTimings()
                self.assertIsNone(private.cached_media_photo(
                    4, private_photo, timings=private_timings,
                ))
                self.assertEqual('ineligible', private_timings.cache_state)

    def test_media_timings_separate_verified_disk_hit_from_transport(self) -> None:
        """Measure a verified disk hit without entering the session gate.

        Returns:
            None: A controlled two-millisecond lookup returns the authorized
                bytes with zero transport, renewal or cache-write work.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + linking.PHOTO_CACHE_FRESH_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        body = b'verified timing thumbnail'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        timings = linking.MediaReadTimings()
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            self.assertTrue(cache.write(
                cache.key(issuer, 4, 7, True), body, 'image/jpeg', etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                session, '_request_gate',
            ) as gate, mock.patch.object(
                linking, '_run_linking_transport',
            ) as transport, mock.patch.object(
                linking.time, 'perf_counter', side_effect=(0.0, 0.002),
            ):
                # An exact cache hit must not wait for any other session read.
                gate.acquire.side_effect = AssertionError('Unexpected gate.')
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.media_photo(
                        4, 7, True, cache_eligible=True,
                        thumbnail_etag=etag,
                        thumbnail_verified_at=time.monotonic(),
                        timings=timings,
                    ),
                )
                transport.assert_not_called()
                gate.acquire.assert_not_called()
        self.assertEqual('disk', timings.source)
        self.assertAlmostEqual(2.0, timings.disk_read_ms)
        self.assertEqual(0.0, timings.disk_write_ms)
        self.assertEqual(0.0, timings.gate_wait_ms)
        self.assertEqual(0.0, timings.renewal_ms)
        self.assertEqual(0.0, timings.network_ms)
        self.assertEqual(0, timings.network_reads)

    def test_media_timings_separate_gate_renewal_and_network(self) -> None:
        """Observe three transport phases without changing their gate owner.

        Returns:
            None: Controlled clocks report two, three and seven milliseconds
                separately; transient bytes never create a disk cache entry.
        """
        session = linking.LinkingSession(
            'https://gallery.example/Galerie', 'pgla_' + 'A' * 43,
            time.monotonic() + linking.PHOTO_CACHE_FRESH_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        timings = linking.MediaReadTimings()
        with mock.patch.object(
            linking, '_run_linking_transport',
            return_value=(b'transient', 'image/jpeg', None, False),
        ) as transport, mock.patch.object(
            session, '_renew_if_expired', wraps=session._renew_if_expired,
        ) as renew, mock.patch.object(
            linking.GalleryMediaCache, 'for_current_user',
        ) as cache, mock.patch.object(
            linking.time, 'perf_counter',
            side_effect=(0.0, 0.002, 0.002, 0.005, 0.005, 0.012),
        ):
            self.assertEqual(
                (b'transient', 'image/jpeg'),
                session.media_photo(
                    4, 7, True, cache_eligible=False, timings=timings,
                ),
            )
            transport.assert_called_once()
            renew.assert_called_once()
            cache.assert_not_called()
        self.assertEqual('network', timings.source)
        self.assertAlmostEqual(2.0, timings.gate_wait_ms)
        self.assertAlmostEqual(3.0, timings.renewal_ms)
        self.assertAlmostEqual(7.0, timings.network_ms)
        self.assertEqual(0.0, timings.disk_read_ms)
        self.assertEqual(0.0, timings.disk_write_ms)
        self.assertEqual(1, timings.network_reads)
        self.assertFalse(session._request_gate.locked())
        self.assertFalse(session._busy)

    def test_media_timings_count_both_refused_304_transport_attempts(self) -> None:
        """Include the authorized unconditional retry in one timing record.

        Returns:
            None: The refused 304 never supplies bytes; the fresh response
                records two network attempts and one cache publication.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + linking.PHOTO_CACHE_FRESH_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        stale_body, fresh_body = b'stale timing bytes', b'fresh timing bytes'
        stale_etag = '"' + hashlib.sha256(stale_body).hexdigest() + '"'
        fresh_etag = '"' + hashlib.sha256(fresh_body).hexdigest() + '"'
        refusal = linking.LinkingResponseError(
            'media', 'app_linking_media', 304, 'other', 'response_policy',
            media_policy_facts=linking.MediaPolicyFacts(
                'multiple', 'missing', True, True, None, True, False, False,
                'not_requested', False, False,
            ),
        )
        timings = linking.MediaReadTimings()
        # Two cache clock pairs and two sets of three transport clock pairs.
        ticks = tuple(step / 1000.0 for step in range(2 + 2 * 6 + 2))
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            key = cache.key(issuer, 4, 7, True)
            self.assertTrue(cache.write(
                key, stale_body, 'image/jpeg', stale_etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                cache, 'is_fresh', return_value=False,
            ), mock.patch.object(
                linking, '_run_linking_transport',
                side_effect=(
                    refusal, (fresh_body, 'image/jpeg', fresh_etag, True),
                ),
            ) as transport, mock.patch.object(
                linking.time, 'perf_counter', side_effect=ticks,
            ):
                self.assertEqual(
                    (fresh_body, 'image/jpeg'),
                    session.media_photo(4, 7, True, timings=timings),
                )
            self.assertEqual(2, transport.call_count)
            self.assertEqual(fresh_body, cache.read(key).body)
        self.assertEqual('network', timings.source)
        self.assertEqual(2, timings.network_reads)
        self.assertAlmostEqual(1.0, timings.disk_read_ms)
        self.assertAlmostEqual(1.0, timings.disk_write_ms)
        self.assertAlmostEqual(2.0, timings.gate_wait_ms)
        self.assertAlmostEqual(2.0, timings.renewal_ms)
        self.assertAlmostEqual(2.0, timings.network_ms)

    def test_media_timings_identify_authorized_304_revalidation(self) -> None:
        """Distinguish live-authorized cached bytes from a fresh disk hit.

        Returns:
            None: A valid 304 records disk lookup, transport and metadata
                revalidation, with no unconditional retry or new response body.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + linking.PHOTO_CACHE_FRESH_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        body = b'revalidated timing bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        timings = linking.MediaReadTimings()
        # Two cache pairs and the gate/renewal/transport pairs, one ms each.
        ticks = tuple(step / 1000.0 for step in range(2 + 6 + 2))
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            self.assertTrue(cache.write(
                cache.key(issuer, 4, 7, True), body, 'image/jpeg', etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                cache, 'is_fresh', return_value=False,
            ), mock.patch.object(
                linking, '_run_linking_transport',
                return_value=(None, 'image/jpeg', etag, True),
            ) as transport, mock.patch.object(
                linking.time, 'perf_counter', side_effect=ticks,
            ):
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.media_photo(4, 7, True, timings=timings),
                )
                transport.assert_called_once()
        self.assertEqual('revalidated', timings.source)
        self.assertEqual(1, timings.network_reads)
        self.assertAlmostEqual(1.0, timings.disk_read_ms)
        self.assertAlmostEqual(1.0, timings.disk_write_ms)
        self.assertAlmostEqual(1.0, timings.gate_wait_ms)
        self.assertAlmostEqual(1.0, timings.renewal_ms)
        self.assertAlmostEqual(1.0, timings.network_ms)

    def test_media_timings_preserve_rejection_throttle_and_cancellation(self) -> None:
        """Keep diagnostic observation passive on rejected and cancelled reads.

        Returns:
            None: Each adverse transport releases its gate, preserves unknown
                byte source and cannot trigger a retry or cache publication.
        """
        for failure in (
            linking.LinkingGrantRejected('Revoked.'),
            linking.LinkingMediaThrottled(),
            linking.LinkingError('Cancelled.'),
        ):
            with self.subTest(failure=type(failure).__name__):
                session = linking.LinkingSession(
                    'https://gallery.example/Galerie', 'pgla_' + 'A' * 43,
                    time.monotonic() + linking.PHOTO_CACHE_FRESH_SECONDS,
                    linking.GALLERIES_SCOPE,
                )
                cancelled = threading.Event()
                timings = linking.MediaReadTimings()
                # Model cancellation after a transport returns valid bytes;
                # the real session must reject them at its own final fence.
                transport_effect = (
                    (lambda *_args, **_kwargs: (
                        cancelled.set(),
                        (b'late bytes', 'image/jpeg', None, False),
                    )[1]) if type(failure) is linking.LinkingError else failure
                )
                with mock.patch.object(
                    linking, '_run_linking_transport',
                    side_effect=transport_effect,
                ) as transport, mock.patch.object(
                    linking.time, 'perf_counter',
                    side_effect=(0.0, 0.001, 0.001, 0.002, 0.002, 0.003),
                ):
                    with self.assertRaises(type(failure)):
                        session.media_photo(
                            4, 7, True, cancelled, cache_eligible=False,
                            timings=timings,
                        )
                    transport.assert_called_once()
                self.assertEqual('unknown', timings.source)
                self.assertEqual(1, timings.network_reads)
                self.assertAlmostEqual(1.0, timings.network_ms)
                self.assertFalse(session._request_gate.locked())
                self.assertFalse(session._busy)
                self.assertFalse(session._pending_gallery_cancels)
                # Cancellation before dispatch cannot even start observation.
                cancelled.set()
                denied = linking.MediaReadTimings()
                with self.assertRaises(linking.LinkingError):
                    session.media_photo(
                        4, 7, True, cancelled, cache_eligible=False,
                        timings=denied,
                    )
                self.assertEqual(linking.MediaReadTimings(), denied)

    def test_gallery_preference_and_local_preparation_use_exact_issuer(self) -> None:
        """Persist a gallery pin and promote a current local byte on inspection.

        Returns:
            None: A listing-only link controls local retention without extra
                grants, and another issuer's entry remains isolated.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, linking.GALLERIES_SCOPE,
        )
        body = b'current cached thumbnail'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        photo = linking.LinkedPhoto(
            7, 'Photo', thumbnail_etag=etag,
        )
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), 1024)
            key = cache.key(issuer, 4, 7, True)
            gallery_key = cache.gallery_key(issuer, 4)
            self.assertTrue(cache.write(key, body, 'image/jpeg', etag))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ):
                self.assertTrue(session.set_gallery_pinned(4, True))
                self.assertTrue(session.gallery_pinned(4))
                self.assertTrue(session.prepare_cached_photo(4, photo, thumbnail=True))
                self.assertFalse((Path(directory) / (key + '.bin')).exists())
                self.assertTrue(session.set_gallery_pinned(4, False))
                self.assertFalse(session.gallery_pinned(4))
            self.assertIsNotNone(cache.read(key))

    def test_no_store_photo_is_not_written_to_pinned_gallery(self) -> None:
        """Keep a server no-store response out of the retained area.

        Returns:
            None: Playback-compatible bytes reach the caller while no persistent
                object is retained, even after the gallery has been pinned.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, linking.GALLERIES_SCOPE,
        )
        body = b'no-store image bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), 1024)
            self.assertTrue(cache.set_gallery_pinned(issuer, 4, True))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                session, '_media_read',
                return_value=(body, 'image/jpeg', etag, False),
            ):
                self.assertEqual(body, session.media_photo(4, 7, True)[0])
            key = cache.key(issuer, 4, 7, True)
            gallery_key = cache.gallery_key(issuer, 4)
            self.assertIsNone(cache.read(key, gallery_key=gallery_key))

    def test_rejected_conditional_304_refreshes_without_cached_bytes(self) -> None:
        """Refresh one refused conditional image read without stale fallback.

        Returns:
            None: A valid unconditional response replaces stale cache content
                after exactly one safe-policy refusal.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        stale_body = b'stale image bytes'
        stale_etag = '"' + hashlib.sha256(stale_body).hexdigest() + '"'
        current_body = b'fresh image bytes'
        current_etag = '"' + hashlib.sha256(current_body).hexdigest() + '"'
        refusal = linking.LinkingResponseError(
            'media', 'app_linking_media', 304, 'other', 'response_policy',
            media_policy_facts=linking.MediaPolicyFacts(
                'multiple', 'missing', True, True, None, True, False, False,
                'not_requested', False, False,
            ),
        )
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            key = cache.key(issuer, 4, 7, True)
            self.assertTrue(cache.write(
                key, stale_body, 'image/jpeg', stale_etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                cache, 'is_fresh', return_value=False,
            ), mock.patch.object(
                session, '_media_read',
                side_effect=(
                    refusal,
                    (current_body, 'image/jpeg', current_etag, True),
                ),
            ) as read, self.assertLogs(linking.__name__, level='INFO') as logs:
                self.assertEqual(
                    (current_body, 'image/jpeg'),
                    session.media_photo(4, 7, True),
                )
            self.assertEqual(
                [
                    mock.call(4, 'thumbnail', 7, None, stale_etag),
                    mock.call(4, 'thumbnail', 7, None),
                ],
                read.call_args_list,
            )
            self.assertEqual(1, len(logs.output))
            self.assertIn('one unconditional refresh', logs.output[0])
            self.assertNotIn('gallery.example', logs.output[0])
            self.assertNotIn('pgla_', logs.output[0])
            cached = cache.read(key)
            self.assertIsNotNone(cached)
            self.assertEqual(current_body, cached.body)

    def test_rejected_conditional_304_does_not_retry_unconditional_failure(self) -> None:
        """Propagate a failed unconditional refresh without using stale bytes.

        Returns:
            None: Repeated 304 refusal stops after one unconditional read.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        stale_body = b'stale image bytes'
        stale_etag = '"' + hashlib.sha256(stale_body).hexdigest() + '"'
        facts = linking.MediaPolicyFacts(
            'multiple', 'missing', True, True, None, True, False, False,
            'not_requested', False, False,
        )
        refused = linking.LinkingResponseError(
            'media', 'app_linking_media', 304, 'other', 'response_policy',
            media_policy_facts=facts,
        )
        repeated = linking.LinkingResponseError(
            'media', 'app_linking_media', 304, 'other', 'response_policy',
            media_policy_facts=facts,
        )
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            key = cache.key(issuer, 4, 7, True)
            self.assertTrue(cache.write(
                key, stale_body, 'image/jpeg', stale_etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                cache, 'is_fresh', return_value=False,
            ), mock.patch.object(
                session, '_media_read', side_effect=(refused, repeated),
            ) as read:
                with self.assertRaises(linking.LinkingResponseError) as raised:
                    session.media_photo(4, 7, True)
            self.assertIs(repeated, raised.exception)
            self.assertEqual(2, read.call_count)
            self.assertEqual(
                mock.call(4, 'thumbnail', 7, None), read.call_args,
            )

    def test_no_store_conditional_304_uses_one_unconditional_read(self) -> None:
        """Keep the legacy no-store 304 fallback bounded to one fresh read.

        Returns:
            None: A no-store conditional response receives one unconditional
                transfer and does not write the resulting bytes to disk;
                passive timings classify the fresh body as network data.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        stale_body = b'stale image bytes'
        stale_etag = '"' + hashlib.sha256(stale_body).hexdigest() + '"'
        current_body = b'no-store current image bytes'
        current_etag = '"' + hashlib.sha256(current_body).hexdigest() + '"'
        timings = linking.MediaReadTimings()
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            key = cache.key(issuer, 4, 7, True)
            self.assertTrue(cache.write(
                key, stale_body, 'image/jpeg', stale_etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                cache, 'is_fresh', return_value=False,
            ), mock.patch.object(
                session, '_media_read', side_effect=(
                    (None, '', stale_etag, False),
                    (current_body, 'image/jpeg', current_etag, False),
                ),
            ) as read, mock.patch.object(cache, 'write') as write:
                self.assertEqual(
                    (current_body, 'image/jpeg'),
                    session.media_photo(4, 7, True, timings=timings),
                )
                write.assert_not_called()
            self.assertEqual(2, read.call_count)
            self.assertEqual(
                mock.call(4, 'thumbnail', 7, None, timings=timings),
                read.call_args,
            )
            self.assertEqual('network', timings.source)

    def test_conditional_304_retry_requires_exact_unencoded_refusal(self) -> None:
        """Retry only the exact safe 304 refusal without content encoding.

        Returns:
            None: Encoding, authorization, throttling and other status failures
                never issue an unconditional follow-up read.
        """
        issuer = 'https://gallery.example/Galerie'
        facts = linking.MediaPolicyFacts(
            'multiple', 'missing', True, True, None, True, False, False,
            'not_requested', False, True,
        )
        failures: tuple[linking.LinkingError, ...] = (
            linking.LinkingResponseError(
                'media', 'app_linking_media', 304, 'other', 'response_policy',
                media_policy_facts=facts,
            ),
            linking.LinkingResponseError(
                'media', 'app_linking_media', 304, 'other', 'response_policy',
            ),
            linking.LinkingResponseError(
                'media', 'app_linking_media', 401, 'json', 'http_status',
            ),
            linking.LinkingMediaThrottled(),
            linking.LinkingResponseError(
                'media', 'app_linking_media', 503, 'other', 'http_status',
            ),
            linking.LinkingError('Transport failed.'),
        )
        for index, failure in enumerate(failures):
            with self.subTest(failure_type=type(failure).__name__, index=index):
                session = linking.LinkingSession(
                    issuer, 'pgla_' + 'A' * 43,
                    time.monotonic() + TEST_WORKER_WAIT_SECONDS,
                    linking.GALLERIES_SCOPE,
                )
                stale_body = b'stale image bytes'
                stale_etag = '"' + hashlib.sha256(stale_body).hexdigest() + '"'
                with TemporaryDirectory() as directory:
                    cache = linking.GalleryMediaCache(
                        Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
                    )
                    key = cache.key(issuer, 4, 7, True)
                    self.assertTrue(cache.write(
                        key, stale_body, 'image/jpeg', stale_etag,
                    ))
                    with mock.patch.object(
                        linking.GalleryMediaCache, 'for_current_user',
                        return_value=cache,
                    ), mock.patch.object(
                        cache, 'is_fresh', return_value=False,
                    ), mock.patch.object(
                        session, '_media_read', side_effect=failure,
                    ) as read:
                        with self.assertRaises(type(failure)):
                            session.media_photo(4, 7, True)
                    self.assertEqual(1, read.call_count)

        cancelled = threading.Event()
        cancelled.set()
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        with mock.patch.object(session, '_media_read') as read:
            with self.assertRaises(linking.LinkingError):
                session.media_photo(4, 7, True, cancelled)
        read.assert_not_called()

        cancelled.clear()
        refusal = linking.LinkingResponseError(
            'media', 'app_linking_media', 304, 'other', 'response_policy',
            media_policy_facts=linking.MediaPolicyFacts(
                'multiple', 'missing', True, True, None, True, False, False,
                'not_requested', False, False,
            ),
        )

        def cancel_after_refusal(
            gallery_id: int, kind: str,
            selector: int | str | None,
            request_cancelled: threading.Event | None,
            if_none_match: str | None = None,
            *, admin_preload: bool = False,
        ) -> linking._NativeMediaResponse:
            """Set cancellation before returning the conditional refusal.

            Args:
                gallery_id (int): Requested gallery passed to the read owner.
                kind (str): Fixed media kind passed to the read owner.
                selector (int | str | None): Requested photo selector.
                request_cancelled (threading.Event | None): Captured action token.
                if_none_match (str | None): Conditional validator supplied by cache.
                admin_preload (bool): Whether the explicit preload lane was requested.

            Returns:
                linking._NativeMediaResponse: Unreachable because the fixture
                    raises the refused response.

            Raises:
                linking.LinkingResponseError: Preserves the conditional policy
                    refusal after setting the action cancellation event.
            """
            del gallery_id, kind, selector, request_cancelled
            del if_none_match, admin_preload
            cancelled.set()
            raise refusal

        stale_body = b'stale image bytes'
        stale_etag = '"' + hashlib.sha256(stale_body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            key = cache.key(issuer, 4, 7, True)
            self.assertTrue(cache.write(
                key, stale_body, 'image/jpeg', stale_etag,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ), mock.patch.object(
                cache, 'is_fresh', return_value=False,
            ), mock.patch.object(
                session, '_media_read', side_effect=cancel_after_refusal,
            ) as read:
                with self.assertRaises(linking.LinkingResponseError):
                    session.media_photo(4, 7, True, cancelled)
            self.assertEqual(1, read.call_count)

    def test_photo_routes_use_listing_scope_but_presentation_stays_explicit(self) -> None:
        """Use listing access for photos while guarding presentation and music.

        Returns:
            None: Listing reads photos, while presentation and identity-only
                sessions cannot access richer resources.
        """
        issuer = 'https://gallery.example/Galerie'
        token = 'pgla_' + 'A' * 43
        scope = linking.UPLOAD_SCOPE
        session = linking.LinkingSession(
            issuer, token, time.monotonic() + 60, scope,
        )
        self.assertTrue(session.can_upload())
        self.assertTrue(session.can_read_media())
        self.assertFalse(session.can_read_presentation())
        legacy = linking.LinkingSession(
            issuer, token, time.monotonic() + 60,
            scope + linking.MEDIA_SCOPE_SUFFIX,
        )
        self.assertTrue(legacy.can_read_media())
        self.assertFalse(legacy.can_read_presentation())
        with mock.patch.object(
            linking, '_run_linking_transport',
            return_value=(b'{"photos":[]}', 'application/json', None, False),
        ) as request:
            self.assertEqual(
                (b'{"photos":[]}', 'application/json', None, False),
                session._media_read(42, 'photos', None, None),
            )
            with self.assertRaises(linking.LinkingError):
                session.media_presentation(42)
            request.assert_called_once()
        identity = linking.LinkingSession(
            issuer, token, time.monotonic() + 60, linking.IDENTITY_SCOPE,
        )
        with mock.patch.object(linking, '_run_linking_transport') as request:
            with self.assertRaises(linking.LinkingError):
                identity._media_read(42, 'photos', None, None)
            request.assert_not_called()
        for invalid in (
            scope + linking.MEDIA_SCOPE_SUFFIX + ' offline_access',
            linking.IDENTITY_SCOPE + linking.MEDIA_SCOPE_SUFFIX,
            linking.UPLOAD_SCOPE + ' presentation:read',
            scope + linking.MEDIA_SCOPE_SUFFIX + linking.MEDIA_SCOPE_SUFFIX,
        ):
            with self.subTest(scope=invalid), self.assertRaises(linking.LinkingError):
                linking.LinkingSession(issuer, token, time.monotonic() + 60, invalid)

    def test_admin_photo_scope_and_private_disk_refusal(self) -> None:
        """Retain exact Admin consent and keep restricted photos off disk.

        Returns:
            None: Public-only grants keep their old scope. Restricted
                descriptors never consult the persistent cache.
        """
        issuer = 'https://gallery.example/Galerie'
        token = 'pgla_' + 'A' * 43
        scope = linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX
        session = linking.LinkingSession(
            issuer, token, time.monotonic() + TEST_WORKER_WAIT_SECONDS, scope,
        )
        restricted = linking.LinkedPhoto(
            7, 'restricted.jpg', visibility='private', nsfw=True,
            cache_eligible=False,
        )
        self.assertTrue(session.can_read_admin_media())
        self.assertFalse(session.can_change_photo_visibility())
        self.assertFalse(linking.scope_allows_admin_media(linking.GALLERIES_SCOPE))
        self.assertFalse(linking.scope_allows_admin_media(scope + ' offline_access'))
        with mock.patch.object(linking.GalleryMediaCache, 'for_current_user') as cache:
            self.assertFalse(session.has_cached_photo(4, restricted))
            with mock.patch.object(
                session, '_media_read', return_value=(b'photo', 'image/jpeg', None, False),
            ) as request:
                self.assertEqual(
                    (b'photo', 'image/jpeg'),
                    session.media_photo(4, 7, True, cache_eligible=False),
                )
                request.assert_called_once_with(4, 'thumbnail', 7, None, None)
            cache.assert_not_called()

    def test_existing_admin_link_deletes_with_exact_acknowledgement(self) -> None:
        """Use the existing Admin Photos grant and validate the exact result.

        Returns:
            None: Public listing is refused locally. The active Admin grant
                requires one fixed mutation and a matching acknowledgement.
        """
        issuer = 'https://gallery.example/Galerie'
        admin_scope = linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, linking.GALLERIES_SCOPE,
        )
        self.assertFalse(session.can_delete_admin_media())
        with mock.patch.object(session, '_request') as request:
            with self.assertRaises(linking.LinkingError):
                session.delete_media_photos(44, (301,))
            request.assert_not_called()

        granted = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, admin_scope,
        )
        self.assertTrue(granted.can_delete_admin_media())
        granted._request = mock.Mock(return_value={'deleted_ids': [301, 302]})
        self.assertEqual(
            (301, 302), granted.delete_media_photos(44, (301, 302)),
        )
        args, kwargs = granted._request.call_args
        self.assertEqual('app_linking_media_delete', args[0])
        self.assertNotIn('gallery_id', kwargs)
        self.assertEqual(
            {'action': 'delete', 'gallery_id': 44, 'image_ids': [301, 302]},
            json.loads(kwargs['body']),
        )
        self.assertEqual(1, granted._request.call_count)

        granted._request.return_value = {'deleted_ids': [302, 301]}
        with self.assertRaises(linking.LinkingError):
            granted.delete_media_photos(44, (301, 302))
        self.assertEqual(2, granted._request.call_count)

    def test_delete_transport_uses_only_fixed_media_route(self) -> None:
        """POST the bounded action on the fixed path with JSON bearer headers.

        Returns:
            None: Gallery identity stays in JSON and the request is sent once.
        """
        headers = Message()
        headers['Content-Type'] = 'application/json; charset=utf-8'
        headers['Cache-Control'] = 'no-store'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.headers = headers
        response.read.return_value = b'{"deleted_ids":[301]}'
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        session = linking.LinkingSession(
            'https://gallery.example/Galerie', 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
        )
        with mock.patch.object(
            linking, '_DeadlineHTTPSConnection', return_value=connection,
        ):
            result = session.delete_media_photos(44, (301,))
        self.assertEqual((301,), result)
        connection.request.assert_called_once()
        call = connection.request.call_args
        self.assertEqual('POST', call.args[0])
        self.assertEqual(
            '/Galerie/index.php?page=app_linking_media', call.args[1],
        )
        self.assertEqual(
            {'action': 'delete', 'gallery_id': 44, 'image_ids': [301]},
            json.loads(call.kwargs['body']),
        )
        self.assertEqual(
            'application/json; charset=utf-8',
            call.kwargs['headers']['Content-Type'],
        )
        self.assertEqual(
            'Bearer ' + 'pgla_' + 'A' * 43,
            call.kwargs['headers']['Authorization'],
        )

    def test_legacy_rolling_delete_scope_remains_usable(self) -> None:
        """Keep existing rolling links that contain the historical suffix.

        Returns:
            None: The saved binding and live session retain their exact scope.
        """
        issuer = 'https://gallery.example/Galerie'
        scope = (
            linking.GALLERIES_SCOPE
            + linking.ADMIN_PRESENTATION_SCOPE_SUFFIX
            + linking.ADMIN_DELETE_SCOPE_SUFFIX
            + linking.ROLLING_SCOPE_SUFFIX
        )
        binding = offline_linking.OfflineBinding(issuer, 4, scope)
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, binding.scope,
        )
        self.assertEqual(scope, session.approved_scope())
        self.assertTrue(session.can_delete_admin_media())
        self.assertTrue(session.can_read_presentation())

    def test_rich_metadata_binds_the_admin_gallery_and_photo(self) -> None:
        """Keep rich metadata bound to its explicit Admin target and projection.

        Returns:
            None: Indexed metadata survives with the requested IDs, internal
                paths stay absent and a listing grant cannot start the read.
        """
        issuer = 'https://gallery.example/Galerie'
        token = 'pgla_' + 'A' * 43
        deadline = time.monotonic() + TEST_WORKER_WAIT_SECONDS
        admin = linking.LinkingSession(
            issuer, token, deadline, linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
        )
        listing = linking.LinkingSession(issuer, token, deadline, linking.GALLERIES_SCOPE)
        photo = {
            'gallery_id': 4, 'image_id': 7, 'title': 'Photo', 'width': 1200,
            'gps_lat': 49.5, 'gps_lng': 14.5, 'relative_path': 'internal/path.jpg',
        }
        response = json.dumps(photo).encode(), 'application/json', None, False
        with mock.patch.object(linking, '_run_linking_transport', return_value=response) as read:
            details = admin.media_photo_info(4, 7)
            self.assertEqual((1200, 49.5), (details['width'], details['gps_lat']))
            self.assertNotIn('relative_path', details)
            with self.assertRaises(linking.LinkingError):
                admin.media_photo_info(9, 7)
            with self.assertRaises(linking.LinkingError):
                listing.media_photo_info(4, 7)
            self.assertEqual(2, read.call_count)

    def test_photo_cache_key_revalidation_and_disk_failure(self) -> None:
        """Keep only exact image variants and gracefully miss on disk errors.

        Returns:
            None: Expired freshness needs revalidation. Matching validation
                touches metadata and cannot refresh a replacement file.
        """
        body = b'bounded test image bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), 1024)
            key = cache.key('https://gallery.example/Galerie', 4, 7, True)
            detail_key = cache.key('https://gallery.example/Galerie', 4, 7, False)
            other_site = cache.key('https://other.example', 4, 7, True)
            self.assertNotEqual(key, detail_key)
            self.assertNotEqual(key, other_site)
            self.assertNotIn('gallery.example', key)
            # Use an arbitrary fixed clock to check both sides of the limits.
            stored_at = 100
            fresh_until = stored_at + gallery_media_cache.PHOTO_CACHE_FRESH_SECONDS
            self.assertTrue(cache.write(key, body, 'image/jpeg', etag, now=stored_at))
            self.assertTrue(cache.contains(key, etag))
            self.assertFalse(cache.contains(key, '"' + ('0' * 64) + '"'))
            self.assertFalse(cache.contains('invalid-cache-key', etag))
            fresh = cache.read(key, now=fresh_until)
            self.assertIsNotNone(fresh)
            self.assertTrue(cache.is_fresh(fresh, now=fresh_until))
            stale = cache.read(key, now=fresh_until + 1)
            self.assertIsNotNone(stale)
            self.assertFalse(cache.is_fresh(stale, now=fresh_until + 1))
            # Forty-eight hours exceeds the original one-day retention period.
            revalidated_at = stored_at + 2 * 24 * 60 * 60
            stale = cache.read(key, now=revalidated_at)
            self.assertIsNotNone(stale)
            path = Path(directory) / (key + '.bin')
            retained_bytes = path.read_bytes()
            original_inode = path.stat().st_ino
            cache.revalidate(key, stale, now=revalidated_at)
            self.assertEqual(path.read_bytes(), retained_bytes)
            self.assertEqual(path.stat().st_ino, original_inode)
            refreshed = cache.read(key, now=revalidated_at)
            self.assertTrue(cache.is_fresh(refreshed, now=revalidated_at))

            # A 304 for the previous inode cannot freshen a concurrent write.
            replacement = b'replacement image bytes'
            replacement_etag = '"' + hashlib.sha256(replacement).hexdigest() + '"'
            self.assertTrue(cache.write(
                key, replacement, 'image/jpeg', replacement_etag, now=stored_at,
            ))
            cache.revalidate(key, refreshed, now=revalidated_at)
            current = cache.read(key, now=revalidated_at)
            self.assertEqual(current.body, replacement)
            self.assertFalse(cache.is_fresh(current, now=revalidated_at))
            unavailable = Path(directory) / 'not-a-directory'
            unavailable.write_text('file')
            broken = linking.GalleryMediaCache(unavailable / 'cache', 1024)
            self.assertFalse(broken.write(key, body, 'image/jpeg', etag, now=stored_at))
            self.assertIsNone(broken.read(key, now=stored_at))

    def test_private_preload_revalidates_and_needs_explicit_account_intent(self) -> None:
        """Revalidate private cache entries online under current Admin metadata.

        Returns:
            None: A verified private entry is reusable only with the negotiated
                protocol, current eligible descriptor and exact account marker;
                each use still performs a conditional server request, including
                when the caller supplies a passive timing record.
        """
        issuer = 'https://gallery.example/Galerie'
        gallery_id, photo_id, user_id = 4, 7, 42
        body = b'private preload fixture bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
            admin_preload_supported=True,
        )
        session._bound_user_id = user_id
        photo = linking.LinkedPhoto(
            photo_id, 'Private', cache_eligible=False, preload_eligible=True,
        )
        timings = linking.MediaReadTimings()
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), 1024)
            key = cache.private_key(issuer, user_id, gallery_id, photo_id, False)
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=cache,
            ), mock.patch.object(
                session, '_media_read',
                return_value=(None, '', etag, True),
            ) as remote:
                self.assertTrue(session.set_gallery_preload_intent(gallery_id, True))
                self.assertTrue(cache.write(key, body, 'image/jpeg', etag))
                self.assertTrue(session.has_cached_photo(gallery_id, photo))
                self.assertTrue(session.prepare_cached_photo(gallery_id, photo))
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.media_photo(
                        gallery_id, photo_id, False,
                        cache_eligible=False, preload_eligible=True,
                        timings=timings,
                    ),
                )
                remote.assert_called_once_with(
                    gallery_id, 'photo', photo_id, None, etag,
                    admin_preload=True, timings=timings,
                )
                self.assertEqual('revalidated', timings.source)
                no_intent = linking.LinkingSession(
                    issuer, 'pgla_' + 'B' * 42 + 'A',
                    time.monotonic() + TEST_WORKER_WAIT_SECONDS,
                    linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
                    admin_preload_supported=True,
                )
                no_intent._bound_user_id = user_id + 1
                self.assertFalse(no_intent.has_cached_photo(gallery_id, photo))
                self.assertFalse(no_intent.prepare_cached_photo(gallery_id, photo))
                self.assertFalse(session.has_cached_photo(
                    gallery_id, dataclass_replace(photo, preload_eligible=False),
                ))

    def test_admin_first_view_caches_thumbnail_without_full_preload_intent(self) -> None:
        """Cache a viewed thumbnail automatically in the bounded account pool.

        Returns:
            None: Viewed thumbnails persist and hit disk/RAM authority without
                granting full-media intent or inheriting another account's pin.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
            admin_preload_supported=True,
        )
        session._bound_user_id = 42
        body = b'first view verified image bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
            )
            # A shared gallery pin must not move this account's automatic
            # thumbnail outside the ordinary quota without its own opt-in.
            self.assertTrue(cache.set_gallery_pinned(issuer, 4, True))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=cache,
            ), mock.patch.object(
                session, '_media_read',
                return_value=(body, 'image/jpeg', etag, True),
            ) as remote:
                result = session.media_photo(
                    4, 7, True, cache_eligible=False, preload_eligible=True,
                )
                self.assertEqual((body, 'image/jpeg'), result)
                remote.assert_called_once_with(
                    4, 'thumbnail', 7, None, None, admin_preload=True,
                )
                self.assertFalse(session.gallery_preload_intent(4))
                key = cache.private_key(issuer, 42, 4, 7, True)
                self.assertIsNotNone(cache.read(key))
                self.assertTrue(cache._entry_path(key).is_file())
                count, used, _limit, ready, _error = cache.thumbnail_storage_snapshot()
                self.assertTrue(ready)
                self.assertEqual(1, count)
                self.assertGreater(used, len(body))
                verified_at = time.monotonic()
                photo = linking.LinkedPhoto(
                    7, 'Viewed photo', cache_eligible=False, nsfw=False,
                    preload_eligible=True, preload_thumbnail_etag=etag,
                    preload_verified_at=verified_at,
                )
                session._private_preload_proofs[4] = (
                    42, verified_at, {7: (etag, verified_at, 1, None)}, False,
                )
                self.assertEqual(
                    (body, 'image/jpeg'), session.cached_media_photo(4, photo),
                )
                self.assertTrue(session.has_cached_photo(4, photo, thumbnail=True))
                self.assertTrue(session.prepare_cached_photo(4, photo, thumbnail=True))
                self.assertIsNone(session.private_preload_cache_identity(4, photo))
                self.assertIsNotNone(session.private_preload_cache_identity(
                    4, photo, thumbnail=True,
                ))
                remote.assert_called_once()
                remote.reset_mock()
                self.assertEqual((body, 'image/jpeg'), session.media_photo(
                    4, 7, False, cache_eligible=False, preload_eligible=True,
                ))
                full_key = cache.private_key(issuer, 42, 4, 7, False)
                self.assertIsNone(cache.read(full_key))
                self.assertFalse(session.gallery_preload_intent(4))
                remote.return_value = (body, 'image/jpeg', etag, False)
                session.media_photo(
                    4, 8, True, cache_eligible=False, preload_eligible=True,
                )
                refused_key = cache.private_key(issuer, 42, 4, 8, True)
                self.assertIsNone(cache.read(refused_key))

    def test_cancelled_request_withdraws_while_another_owner_holds_gate(self) -> None:
        """Remove an obsolete view's ticket before the active request finishes.

        Returns:
            None: Cancellation releases the waiting caller without admitting it
                or requiring the current request to give up its serial slot.
        """
        gate = linking._FairRequestGate()
        cancelled, finished = threading.Event(), threading.Event()
        admitted: list[bool] = []
        self.assertTrue(gate.acquire())

        def wait_for_gate() -> None:
            """Wait for serial admission until this obsolete view is cancelled.

            Returns:
                None: Records refusal and signals completion without owning a slot.
            """
            admitted.append(gate.acquire(cancelled=cancelled))
            finished.set()

        worker = threading.Thread(target=wait_for_gate, daemon=True)
        worker.start()
        try:
            with gate._condition:
                self.assertTrue(gate._condition.wait_for(
                    lambda: bool(gate._waiters), TEST_WORKER_WAIT_SECONDS,
                ))
            cancelled.set()
            self.assertTrue(finished.wait(TEST_WORKER_WAIT_SECONDS))
            self.assertEqual([False], admitted)
            self.assertTrue(gate.locked())
        finally:
            cancelled.set()
            gate.release()
            worker.join(TEST_WORKER_WAIT_SECONDS)

    def test_newer_session_proof_reuses_full_body_for_older_view_descriptor(self) -> None:
        """Reuse fresh full bytes after background metadata supersedes a view.

        Returns:
            None: A real newer ledger observation supplies authority and its
                timestamp/epoch; stale, absent or changed proofs never do.
        """
        issuer = 'https://gallery.example/Galerie'
        # Purpose: Give the stale descriptor a positive monotonic timestamp
        # even on a freshly booted VM with less than one proof window of uptime.
        # Type: float. Units: seconds. Scope: this deterministic proof fixture.
        # Consumers: session expiry and catalogue proof age checks below.
        # Rationale: 120 seconds (two proof windows) leaves room for a stale
        # positive observation without depending on host uptime or weakening
        # the production fail-closed lower bound.
        monotonic_clock = mock.patch.object(
            linking.time, 'monotonic',
            return_value=2.0 * linking.PHOTO_CACHE_FRESH_SECONDS,
        )
        monotonic_clock.start()
        self.addCleanup(monotonic_clock.stop)
        observed_at = time.monotonic()
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            observed_at + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
            admin_preload_supported=True,
        )
        session._bound_user_id = 42
        thumbnail_etag = '"' + 'c' * 64 + '"'
        # The mounted descriptor predates the freshness boundary. A subsequent
        # authenticated page, represented by the ledger below, remains current.
        descriptor_at = observed_at - linking.PHOTO_CACHE_FRESH_SECONDS - 1
        photo = linking.LinkedPhoto(
            7, 'Preloaded photo', cache_eligible=False, nsfw=False,
            preload_eligible=True, preload_thumbnail_etag=thumbnail_etag,
            preload_verified_at=descriptor_at,
        )
        body = b'verified full photo response'
        full_etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            key = cache.private_key(issuer, 42, 4, 7, False)
            self.assertTrue(cache.write(key, body, 'image/jpeg', full_etag))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=cache,
            ), mock.patch.object(session, '_media_read') as remote:
                self.assertIsNone(session.cached_media_photo(4, photo, thumbnail=False))
                session._private_preload_proofs[4] = (
                    42, observed_at, {7: (thumbnail_etag, observed_at, 1, None)}, True,
                )
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.cached_media_photo(4, photo, thumbnail=False),
                )
                self.assertEqual(
                    (42, thumbnail_etag, observed_at, 1),
                    session.private_preload_cache_identity(4, photo),
                )
                session._private_preload_proofs[4] = (
                    42, observed_at,
                    {7: ('"' + 'd' * 64 + '"', observed_at, 2, None)}, True,
                )
                self.assertIsNone(session.cached_media_photo(4, photo, thumbnail=False))
                session._private_preload_proofs[4] = (
                    42, descriptor_at, {7: (thumbnail_etag, descriptor_at, 3, None)}, True,
                )
                self.assertIsNone(session.private_preload_cache_identity(4, photo))
                remote.assert_not_called()

    def test_preloaded_full_snapshots_skip_body_revalidation_in_the_viewer(self) -> None:
        """Browse old downloaded full copies without refreshing their HTTP age.

        Returns:
            None: Four distinct saved photos use verified disk bytes, beyond the
                viewer's three-image RAM bound. A normal preload-mode read still
                revalidates the full representation through the server.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
            admin_preload_supported=True,
        )
        session._bound_user_id = 42
        thumbnail_etag = '"' + 'c' * 64 + '"'
        # Four separate bodies demonstrate disk reuse beyond the three-entry
        # decoded detail cache. Epoch 100 deliberately predates HTTP freshness.
        image_ids = (7, 8, 9, 10)
        page_response = {
            'gallery_id': 4, 'parent_id': None, 'next_after': None,
            'photos': [{
                'id': image_id, 'title': 'Downloaded photo',
                'visibility': 'public', 'nsfw': False, 'cache_eligible': False,
                'preload_eligible': True,
                'preload_thumbnail_etag': thumbnail_etag,
            } for image_id in image_ids],
        }
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            gallery_key = cache.gallery_key(issuer, 4)
            self.assertTrue(cache.set_gallery_pinned(issuer, 4, True))
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=cache,
            ), mock.patch.object(
                linking, '_run_linking_transport', return_value=(
                    json.dumps(page_response).encode(), 'application/json', None, False,
                ),
            ) as transport, mock.patch.object(
                session, '_media_read', wraps=session._media_read,
            ) as reads:
                self.assertTrue(session.set_gallery_preload_intent(4, True))
                page = session.media_page(4)
                reads.reset_mock()
                transport.reset_mock()
                for photo in page.photos:
                    body = f'downloaded full version {photo.image_id}'.encode()
                    etag = '"' + hashlib.sha256(body).hexdigest() + '"'
                    key = cache.private_key(issuer, 42, 4, photo.image_id, False)
                    self.assertTrue(cache.write(
                        key, body, 'image/jpeg', etag, now=100,
                        gallery_key=gallery_key,
                    ))
                    original = cache.read(key, gallery_key=gallery_key)
                    self.assertFalse(cache.is_fresh(original))
                    self.assertIsNone(session.cached_media_photo(
                        4, photo, thumbnail=False,
                    ))
                    self.assertEqual((body, 'image/jpeg'), session.cached_media_photo(
                        4, photo, thumbnail=False, use_preloaded_snapshot=True,
                    ))
                    timings = linking.MediaReadTimings()
                    self.assertEqual((body, 'image/jpeg'), session.media_photo(
                        4, photo.image_id, False,
                        cache_eligible=photo.cache_eligible,
                        preload_eligible=photo.preload_eligible,
                        preload_thumbnail_etag=photo.preload_thumbnail_etag,
                        preload_verified_at=photo.preload_verified_at,
                        use_preloaded_snapshot=True, timings=timings,
                    ))
                    self.assertEqual('disk', timings.source)
                    self.assertEqual(0, timings.network_reads)
                    self.assertFalse(timings.cache_stored)
                    after_read = cache.read(key, gallery_key=gallery_key)
                    self.assertEqual(original.stored_at, after_read.stored_at)
                    self.assertEqual(
                        original.file_info.st_mtime_ns,
                        after_read.file_info.st_mtime_ns,
                    )
                reads.assert_not_called()
                transport.assert_not_called()

                # Explicit Preload keeps the default HTTP mode, even though
                # the viewer can display these same downloaded bytes directly.
                photo = page.photos[-1]
                transport.return_value = (None, '', etag, True)
                timings = linking.MediaReadTimings()
                self.assertEqual((body, 'image/jpeg'), session.media_photo(
                    4, photo.image_id, False,
                    cache_eligible=photo.cache_eligible,
                    preload_eligible=photo.preload_eligible,
                    preload_thumbnail_etag=photo.preload_thumbnail_etag,
                    preload_verified_at=photo.preload_verified_at,
                    timings=timings,
                ))
                self.assertEqual('photo', reads.call_args.args[1])
                self.assertEqual(etag, reads.call_args.args[4])
                self.assertEqual('revalidated', timings.source)
                self.assertTrue(timings.cache_stored)
                transport.assert_called_once()

    def test_snapshot_refreshes_one_catalogue_page_for_neighboring_full_photos(self) -> None:
        """Renew access metadata once while keeping downloaded full bodies local.

        Returns:
            None: Expired page authority refreshes its original cursor, then
                neighboring images use that fresh page with no binary GET. An
                older mounted descriptor cannot prevent the saved-version read.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
            admin_preload_supported=True,
        )
        session._bound_user_id = 42
        thumbnail_etag = '"' + 'c' * 64 + '"'
        page_response = {
            'gallery_id': 4, 'parent_id': None, 'next_after': None,
            'photos': [{
                'id': image_id, 'title': 'Downloaded photo',
                'visibility': 'private', 'nsfw': False, 'cache_eligible': False,
                'preload_eligible': True,
                'preload_thumbnail_etag': thumbnail_etag,
            } for image_id in (7, 8)],
        }
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            body = b'downloaded full version'
            etag = '"' + hashlib.sha256(body).hexdigest() + '"'
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=cache,
            ), mock.patch.object(
                linking, '_run_linking_transport', return_value=(
                    json.dumps(page_response).encode(), 'application/json', None, False,
                ),
            ) as transport, mock.patch.object(
                session, '_media_read', wraps=session._media_read,
            ) as reads:
                self.assertTrue(session.set_gallery_preload_intent(4, True))
                # This page starts after another page. Its exact cursor must
                # survive expiry rather than always refreshing the first page.
                page = session.media_page(4, 5)
                for photo in page.photos:
                    self.assertTrue(cache.write(
                        cache.private_key(issuer, 42, 4, photo.image_id, False),
                        body, 'image/jpeg', etag, now=100,
                    ))
                proof = session._private_preload_proofs[4]
                expired_at = time.monotonic() - linking.PHOTO_CACHE_FRESH_SECONDS - 1
                session._private_preload_proofs[4] = (
                    proof[0], expired_at,
                    {image_id: (row[0], expired_at, row[2], row[3])
                     for image_id, row in proof[2].items()},
                    proof[3],
                )
                reads.reset_mock()
                transport.reset_mock()
                self.assertIsNone(session.cached_media_photo(
                    4, page.photos[0], thumbnail=False, use_preloaded_snapshot=True,
                ))
                reads.assert_not_called()
                # The current catalogue can describe a changed representation.
                # Snapshot mode deliberately displays the downloaded version;
                # explicit Preload is responsible for refreshing that content.
                for row in page_response['photos']:
                    row['preload_thumbnail_etag'] = '"' + 'd' * 64 + '"'
                transport.return_value = (
                    json.dumps(page_response).encode(), 'application/json', None, False,
                )
                for index, photo in enumerate(page.photos):
                    timings = linking.MediaReadTimings()
                    self.assertEqual((body, 'image/jpeg'), session.media_photo(
                        4, photo.image_id, False,
                        cache_eligible=photo.cache_eligible,
                        preload_eligible=photo.preload_eligible,
                        preload_thumbnail_etag=photo.preload_thumbnail_etag,
                        preload_verified_at=photo.preload_verified_at,
                        use_preloaded_snapshot=True, timings=timings,
                    ))
                    self.assertEqual('disk', timings.source)
                    self.assertEqual(1 if index == 0 else 0, timings.network_reads)
                    entry = cache.read(cache.private_key(
                        issuer, 42, 4, photo.image_id, False,
                    ))
                    self.assertEqual(100, entry.stored_at)
                self.assertEqual([('photos', 5)], [
                    call.args[1:3] for call in reads.call_args_list
                ])
                transport.assert_called_once()

    def test_full_snapshot_rejects_lost_authority_and_damaged_files(self) -> None:
        """Preserve live access, account, opt-in and integrity gates for snapshots.

        Returns:
            None: Lost authorization or a damaged local body never returns the
                saved bytes. Cache-only reads never repair authority over HTTP.
        """
        issuer = 'https://gallery.example/Galerie'
        body = b'saved full photo'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        page_response = {
            'gallery_id': 4, 'parent_id': None, 'next_after': None,
            'photos': [{
                'id': 7, 'title': 'Downloaded photo', 'visibility': 'private',
                'nsfw': False, 'cache_eligible': False,
                'preload_eligible': True, 'preload_thumbnail_etag': etag,
            }],
        }
        cases = (
            'account', 'optout', 'missing_row', 'stale_page', 'nsfw',
            'corrupt', 'cancelled', 'expired', 'closed',
        )
        for case in cases:
            with self.subTest(case=case), TemporaryDirectory() as directory:
                session = linking.LinkingSession(
                    issuer, 'pgla_' + 'A' * 43,
                    time.monotonic() + TEST_WORKER_WAIT_SECONDS,
                    linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
                    admin_preload_supported=True,
                )
                session._bound_user_id = 42
                cache = linking.GalleryMediaCache(
                    Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
                )
                key = cache.private_key(issuer, 42, 4, 7, False)
                cancelled = threading.Event()
                with mock.patch.object(
                    linking.GalleryMediaCache, 'for_current_user', return_value=cache,
                ), mock.patch.object(
                    linking, '_run_linking_transport', return_value=(
                        json.dumps(page_response).encode(), 'application/json', None, False,
                    ),
                ) as transport:
                    self.assertTrue(session.set_gallery_preload_intent(4, True))
                    photo = session.media_page(4).photos[0]
                    self.assertTrue(cache.write(key, body, 'image/jpeg', etag, now=100))
                    if case == 'account':
                        session._bound_user_id = 43
                    elif case == 'optout':
                        self.assertTrue(session.set_gallery_preload_intent(4, False))
                    elif case == 'missing_row':
                        session._private_preload_proofs[4][2].pop(7)
                    elif case == 'stale_page':
                        row = session._private_preload_proofs[4][2][7]
                        session._private_preload_proofs[4][2][7] = (
                            row[0], row[1] - linking.PHOTO_CACHE_FRESH_SECONDS - 1,
                            row[2], row[3],
                        )
                    elif case == 'nsfw':
                        denied = {**page_response, 'photos': [{
                            **page_response['photos'][0], 'nsfw': True,
                            'preload_eligible': False, 'preload_thumbnail_etag': None,
                        }]}
                        transport.return_value = (
                            json.dumps(denied).encode(), 'application/json', None, False,
                        )
                        session.media_page(4)
                    elif case == 'corrupt':
                        path = cache.read(key).path
                        stored = path.read_bytes()
                        path.write_bytes(stored[:-1] + bytes([stored[-1] ^ 1]))
                    elif case == 'cancelled':
                        cancelled.set()
                    elif case == 'expired':
                        session._expires_at = time.monotonic() - 1
                    else:
                        session.close()
                    transport.reset_mock()
                    options = {
                        'thumbnail': False, 'use_preloaded_snapshot': True,
                        'cancelled': cancelled,
                    }
                    if case in ('cancelled', 'expired', 'closed'):
                        with self.assertRaises(linking.LinkingError):
                            session.cached_media_photo(4, photo, **options)
                    else:
                        self.assertIsNone(session.cached_media_photo(4, photo, **options))
                    transport.assert_not_called()

    def test_full_snapshot_propagates_catalogue_refusal_without_stale_fallback(self) -> None:
        """Reject a saved full copy when live catalogue authorization is refused.

        Returns:
            None: An authenticated 403 during page refresh invalidates the old
                proof and never returns the stored body or starts a binary read.
        """
        issuer = 'https://gallery.example/Galerie'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
            admin_preload_supported=True,
        )
        session._bound_user_id = 42
        body = b'previously downloaded full photo'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
            )
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user', return_value=cache,
            ), mock.patch.object(
                session, '_media_read', side_effect=linking.LinkingResponseError(
                    'media', 'app_linking_media', 403, 'other', 'http_status',
                ),
            ) as reads:
                self.assertTrue(session.set_gallery_preload_intent(4, True))
                self.assertTrue(cache.write(
                    cache.private_key(issuer, 42, 4, 7, False),
                    body, 'image/jpeg', etag, now=100,
                ))
                expired_at = time.monotonic() - linking.PHOTO_CACHE_FRESH_SECONDS - 1
                session._private_preload_proofs[4] = (
                    42, expired_at, {7: (etag, expired_at, 1, None)}, True,
                )
                with self.assertRaises(linking.LinkingResponseError):
                    session.media_photo(
                        4, 7, False, cache_eligible=False, preload_eligible=True,
                        preload_thumbnail_etag=etag, preload_verified_at=expired_at,
                        use_preloaded_snapshot=True,
                    )
                self.assertNotIn(4, session._private_preload_proofs)
                self.assertEqual(['photos'], [
                    call.args[1] for call in reads.call_args_list
                ])

    def test_binary_refusal_diagnostic_retains_only_a_fixed_stage(self) -> None:
        """Keep binary framing refusals distinguishable after transport wrapping.

        Returns:
            None: The summary preserves its fixed validator stage while arbitrary
                diagnostic values are rejected before they can be retained.
        """
        headers = linking.http.client.HTTPMessage()
        headers['Cache-Control'] = 'no-store'
        facts = linking._native_media_policy_facts(
            headers, 'X-PHP-Gallery-Photo-Cache',
            'private, max-age=60, must-revalidate', False,
            binary_check='validator',
        )
        error = linking.LinkingResponseError(
            'media', 'app_linking_media', 200, 'other', 'response_policy',
            media_policy_facts=facts,
        )
        self.assertIn('binary:validator', error.safe_summary)
        with self.assertRaises(ValueError):
            dataclass_replace(facts, binary_check='raw server secret')
        with self.assertRaises(ValueError):
            linking.LinkingResponseError(
                'media', 'app_linking_media', None, 'other', 'timeout',
                transport_step='private.example',
            )
        with self.assertRaises(ValueError):
            linking.LinkingResponseError(
                'media', 'app_linking_media', 200, 'other', 'http_status',
                transport_step='headers',
            )

    def test_current_catalogue_proof_reuses_an_expired_thumbnail(self) -> None:
        """Read a matching old thumbnail before starting binary transport.

        Returns:
            None: A current matching proof hits disk and a changed proof
                sends a current binary request.
        """
        issuer = 'https://gallery.example/Galerie'
        gallery_id, photo_id = 4, 7
        body = b'previously verified thumbnail bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        new_body = b'current thumbnail bytes'
        new_etag = '"' + hashlib.sha256(new_body).hexdigest() + '"'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, linking.GALLERIES_SCOPE,
        )
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), linking.NATIVE_MEDIA_MAX_BYTES)
            key = cache.key(issuer, gallery_id, photo_id, True)
            # This epoch predates the server's ordinary cache freshness window.
            self.assertTrue(cache.write(key, body, 'image/jpeg', etag, now=100))
            with mock.patch.object(linking.GalleryMediaCache, 'for_current_user', return_value=cache):
                with mock.patch.object(
                    session, '_media_read',
                    return_value=(new_body, 'image/jpeg', new_etag, True),
                ) as request:
                    self.assertEqual((body, 'image/jpeg'), session.media_photo(
                        gallery_id, photo_id, True, thumbnail_etag=etag,
                        thumbnail_verified_at=time.monotonic(),
                    ))
                    request.assert_not_called()
                    self.assertEqual((new_body, 'image/jpeg'), session.media_photo(
                        gallery_id, photo_id, True, thumbnail_etag=new_etag,
                        thumbnail_verified_at=time.monotonic(),
                    ))
                    request.assert_called_once_with(
                        gallery_id, 'thumbnail', photo_id, None, etag,
                    )


    def test_shared_media_cache_evicts_least_recently_accessed_entry(self) -> None:
        """Exercise one retention budget for both photos and tracks.

        Returns:
            None: Writing audio retains the recently read photo over an old one.
        """
        body = b'fixture bytes'
        digest = hashlib.sha256(body).hexdigest()
        etag = '"' + digest + '"'
        issuer = 'https://gallery.example'
        with TemporaryDirectory() as directory, mock.patch.object(
            gallery_media_cache, 'MEDIA_CACHE_MAX_ENTRIES', 2,
        ):
            cache = linking.GalleryMediaCache(Path(directory), 1024)
            recent = cache.key(issuer, 4, 7, True)
            old = cache.key(issuer, 4, 8, True)
            track = cache.track_key(issuer, 4, 'a' * 32, digest)
            self.assertTrue(cache.write(recent, body, 'image/jpeg', etag, now=100))
            self.assertTrue(cache.write(old, body, 'image/jpeg', etag, now=101))
            self.assertIsNotNone(cache.read(recent, now=102))
            self.assertTrue(cache.write(track, body, 'audio/wav', etag, now=103))
            self.assertIsNone(cache.read(old))
            self.assertEqual(cache.read(track).mime, 'audio/wav')
            self.assertIsNotNone(cache.read(recent))

    def test_thumbnail_cache_pool_is_independent_from_detail_and_audio(self) -> None:
        """Keep ordinary thumbnail quota and files separate from other media.

        Returns:
            None: Detail/audio pressure only evicts the main pool, and thumbnail
                pressure uses its own byte and entry limits.
        """
        issuer = 'https://gallery.example'
        body = b'thumbnail-pool-fixture'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory, mock.patch.multiple(
            gallery_media_cache,
            MEDIA_CACHE_MAX_ENTRIES=1,
            MEDIA_CACHE_MAX_BYTES=TEST_PINNED_CACHE_ENTRY_BYTES,
            MEDIA_THUMBNAIL_CACHE_MAX_ENTRIES=1,
            MEDIA_THUMBNAIL_CACHE_MAX_BYTES=TEST_PINNED_CACHE_ENTRY_BYTES,
        ):
            detail_cache = linking.GalleryMediaCache(Path(directory), 1024)
            thumbnail_cache = linking.GalleryMediaCache(
                Path(directory), 1024, thumbnails=True,
            )
            thumbnail_key = thumbnail_cache.key(issuer, 4, 7, True)
            detail_key = detail_cache.key(issuer, 4, 7, False)
            track_key = detail_cache.track_key(issuer, 4, 'a' * 32, etag[1:-1])
            self.assertTrue(thumbnail_cache.write(
                thumbnail_key, body, 'image/jpeg', etag,
            ))
            self.assertTrue(detail_cache.write(
                detail_key, body, 'image/jpeg', etag,
            ))
            self.assertTrue(detail_cache.write(
                track_key, body, 'audio/wav', etag,
            ))
            self.assertIsNotNone(thumbnail_cache.read(thumbnail_key))
            self.assertIsNone(detail_cache.read(detail_key))
            self.assertEqual('audio/wav', detail_cache.read(track_key).mime)
            self.assertEqual(1, len(thumbnail_cache._inventory()))
            self.assertEqual(1, len(detail_cache._inventory()))
            self.assertTrue((Path(directory) / 'thumbnails' / (thumbnail_key + '.bin')).is_file())
            self.assertFalse((Path(directory) / 'thumbnails' / (detail_key + '.bin')).exists())
            self.assertFalse((Path(directory) / 'thumbnails' / (track_key + '.bin')).exists())
            cached = thumbnail_cache.read(thumbnail_key, now=101)
            thumbnail_path = Path(directory) / 'thumbnails' / (thumbnail_key + '.bin')
            original_identity = thumbnail_path.stat()
            thumbnail_cache.revalidate(thumbnail_key, cached, now=200)
            self.assertTrue(os.path.samestat(
                original_identity, thumbnail_path.stat(),
            ))

    def test_thumbnail_cache_migrates_exact_legacy_entries(self) -> None:
        """Move verified flat and pinned legacy thumbnails into their own pool.

        Returns:
            None: Only the exact thumbnail identity authorizes moving a legacy
                flat or pre-pool retained entry into the thumbnail namespace.
        """
        issuer = 'https://gallery.example'
        self.assertNotEqual(
            linking.GalleryMediaCache.key(issuer, 4, 7, True),
            linking.GalleryMediaCache.private_key(issuer, 42, 4, 7, True),
        )
        body = b'legacy-thumbnail-fixture'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            legacy_cache = linking.GalleryMediaCache(Path(directory), 1024)
            thumbnail_cache = linking.GalleryMediaCache(
                Path(directory), 1024, thumbnails=True,
            )
            gallery_key = legacy_cache.gallery_key(issuer, 4)
            key = legacy_cache.key(issuer, 4, 7, True)
            self.assertTrue(legacy_cache.write(key, body, 'image/jpeg', etag))
            migrated = thumbnail_cache.read(key, gallery_key=gallery_key)
            self.assertEqual(body, migrated.body)
            self.assertFalse((Path(directory) / (key + '.bin')).exists())
            self.assertTrue((Path(directory) / 'thumbnails' / (key + '.bin')).is_file())

            self.assertTrue(legacy_cache.set_gallery_pinned(issuer, 4, True))
            # A distinct historical timestamp detects migration that wrongly
            # refreshes stale content instead of preserving its validation age.
            stored_at = 100
            self.assertTrue(legacy_cache.write(
                key, body, 'image/jpeg', etag, now=stored_at,
                gallery_key=gallery_key,
            ))
            prefixed = (
                Path(directory) / '.retained' / gallery_key / ('t-' + key + '.bin')
            )
            legacy_retained = Path(directory) / '.retained' / gallery_key / (key + '.bin')
            self.assertTrue(legacy_retained.is_file())
            original_mtime = legacy_retained.stat().st_mtime_ns
            migrated = thumbnail_cache.read(key, gallery_key=gallery_key)
            self.assertEqual(body, migrated.body)
            self.assertEqual(stored_at, migrated.stored_at)
            self.assertFalse(legacy_retained.exists())
            self.assertTrue(prefixed.is_file())
            self.assertEqual(original_mtime, prefixed.stat().st_mtime_ns)
            self.assertTrue(legacy_cache.set_gallery_pinned(issuer, 4, False))
            self.assertFalse(legacy_cache.is_gallery_pinned(issuer, 4))
            self.assertFalse(prefixed.exists())
            self.assertEqual(
                body, thumbnail_cache.read(key, gallery_key=gallery_key).body,
            )
            self.assertFalse((Path(directory) / (key + '.bin')).exists())

    def test_only_authorized_thumbnail_responses_use_thumbnail_pool(self) -> None:
        """Route admitted thumbnails to their pool without changing admission.

        Returns:
            None: A public eligible thumbnail persists only below the child
                pool; a descriptor denied cache admission causes no cache write.
        """
        issuer = 'https://gallery.example'
        body = b'eligible-thumbnail-response'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        session = linking.LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_WORKER_WAIT_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        photo_id, gallery_id = 7, 4
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(
                Path(directory), 1024, thumbnails=True,
            )
            photo = linking.LinkedPhoto(
                photo_id, 'Eligible', cache_eligible=True,
            )
            with mock.patch.object(
                linking.GalleryMediaCache, 'for_current_user',
                return_value=cache,
            ) as factory, mock.patch.object(
                session, '_media_read',
                return_value=(body, 'image/jpeg', etag, True),
            ) as remote:
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.media_photo(
                        gallery_id, photo_id, True, cache_eligible=True,
                    ),
                )
                factory.assert_called_once_with(
                    linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
                )
                key = cache.key(issuer, gallery_id, photo_id, True)
                self.assertTrue((Path(directory) / 'thumbnails' / (key + '.bin')).is_file())
                self.assertFalse((Path(directory) / (key + '.bin')).exists())
                self.assertEqual(1, remote.call_count)

                factory.reset_mock()
                self.assertTrue(session.has_cached_photo(
                    gallery_id, photo, thumbnail=True,
                ))
                factory.assert_called_once_with(
                    linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
                )
                factory.reset_mock()
                self.assertTrue(session.prepare_cached_photo(
                    gallery_id, photo, thumbnail=True,
                ))
                factory.assert_called_once_with(
                    linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
                )

                factory.reset_mock()
                remote.reset_mock()
                self.assertEqual(
                    (body, 'image/jpeg'),
                    session.media_photo(
                        gallery_id, photo_id, True, cache_eligible=False,
                    ),
                )
                factory.assert_not_called()
                remote.assert_called_once()

    def test_thumbnail_pool_rejects_reparse_child(self) -> None:
        """Refuse thumbnail cache entries when its child directory is linked.

        Returns:
            None: An unsafe child link is treated as a miss and cannot receive
                a cache write outside the canonical media-cache directory.
        """
        body = b'linked-thumbnail-child'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory, TemporaryDirectory() as target:
            cache = linking.GalleryMediaCache(
                Path(directory), 1024, thumbnails=True,
            )
            legacy = linking.GalleryMediaCache(Path(directory), 1024)
            key = cache.key('https://gallery.example', 4, 7, True)
            self.assertTrue(legacy.write(key, body, 'image/jpeg', etag))
            child = Path(directory) / 'thumbnails'
            try:
                child.symlink_to(Path(target), target_is_directory=True)
            except OSError as error:
                self.skipTest('Directory symlinks are unavailable: ' + str(error))
            self.assertFalse(cache.write(key, body, 'image/jpeg', etag))
            self.assertIsNone(cache.read(key))
            self.assertFalse(cache.contains(key, etag))
            self.assertEqual([], list(Path(target).iterdir()))

    def test_unpin_routes_new_thumbnail_and_detail_retention_separately(self) -> None:
        """Restore prefixed thumbnails and ordinary detail bytes to own pools.

        Returns:
            None: A pinned gallery stores both renditions under one marker, then
                unpin returns each verified object to its independent pool.
        """
        issuer = 'https://gallery.example'
        body = b'pinned-rendition-fixture'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            detail = linking.GalleryMediaCache(Path(directory), 1024)
            thumbnails = linking.GalleryMediaCache(
                Path(directory), 1024, thumbnails=True,
            )
            gallery_key = detail.gallery_key(issuer, 4)
            thumb_key = thumbnails.key(issuer, 4, 7, True)
            detail_key = detail.key(issuer, 4, 7, False)
            self.assertTrue(detail.set_gallery_pinned(issuer, 4, True))
            self.assertTrue(thumbnails.write(
                thumb_key, body, 'image/jpeg', etag, gallery_key=gallery_key,
            ))
            self.assertTrue(detail.write(
                detail_key, body, 'image/jpeg', etag, gallery_key=gallery_key,
            ))
            retained = Path(directory) / '.retained' / gallery_key
            self.assertTrue((retained / ('t-' + thumb_key + '.bin')).is_file())
            self.assertTrue((retained / (detail_key + '.bin')).is_file())
            self.assertTrue(detail.set_gallery_pinned(issuer, 4, False))
            self.assertTrue((Path(directory) / 'thumbnails' / (thumb_key + '.bin')).is_file())
            self.assertTrue((Path(directory) / (detail_key + '.bin')).is_file())
            self.assertEqual(body, thumbnails.read(thumb_key).body)
            self.assertEqual(body, detail.read(detail_key).body)

    def test_unpin_capacity_preflight_keeps_all_retained_sources(self) -> None:
        """Refuse unpin when all thumbnail targets cannot coexist in quota.

        Returns:
            None: A one-entry thumbnail quota cannot accept two retained target
                keys; neither retained source nor the gallery marker is removed.
        """
        issuer = 'https://gallery.example'
        body = b'capacity-target-fixture'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory, mock.patch.object(
            gallery_media_cache, 'MEDIA_THUMBNAIL_CACHE_MAX_ENTRIES',
            TEST_THUMBNAIL_UNPIN_MAX_ENTRIES,
        ):
            detail = linking.GalleryMediaCache(Path(directory), 1024)
            thumbnails = linking.GalleryMediaCache(
                Path(directory), 1024, thumbnails=True,
            )
            gallery_key = detail.gallery_key(issuer, 4)
            keys = [thumbnails.key(issuer, 4, image_id, True) for image_id in (7, 8)]
            self.assertTrue(detail.set_gallery_pinned(issuer, 4, True))
            for key in keys:
                self.assertTrue(thumbnails.write(
                    key, body, 'image/jpeg', etag, gallery_key=gallery_key,
                ))
            retained_dir = Path(directory) / '.retained' / gallery_key
            retained_paths = [retained_dir / ('t-' + key + '.bin') for key in keys]
            self.assertFalse(detail.set_gallery_pinned(issuer, 4, False))
            self.assertTrue(detail.is_gallery_pinned(issuer, 4))
            self.assertTrue(all(path.is_file() for path in retained_paths))
            self.assertEqual([], list((Path(directory) / 'thumbnails').glob('*.bin')))

    def test_pinned_gallery_isolated_from_ordinary_limits_and_restarts(self) -> None:
        """Keep retained media outside ordinary quota and scan accounting.

        Returns:
            None: Multiple pinned renditions survive pressure and a new cache
                owner, while ordinary storage retains only its configured item.
        """
        issuer = 'https://gallery.example/Galerie'
        body = b'p' * TEST_PINNED_CACHE_BODY_BYTES
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory, mock.patch.multiple(
            gallery_media_cache,
            MEDIA_CACHE_MAX_ENTRIES=1,
            MEDIA_CACHE_MAX_BYTES=TEST_PINNED_CACHE_ENTRY_BYTES,
        ):
            cache = linking.GalleryMediaCache(Path(directory), len(body))
            gallery_key = cache.gallery_key(issuer, 4)
            self.assertTrue(cache.set_gallery_pinned(issuer, 4, True))
            pinned_keys = [
                cache.key(issuer, 4, image_id, thumbnail)
                for image_id in (7, 8, 9)
                for thumbnail in (True, False)
            ]
            for key in pinned_keys:
                self.assertTrue(cache.write(
                    key, body, 'image/jpeg', etag,
                    gallery_key=gallery_key,
                ))
            ordinary_key = cache.key(issuer, 99, 1, True)
            self.assertTrue(cache.write(ordinary_key, body, 'image/jpeg', etag))
            self.assertEqual(1, len(cache._inventory()))

            restarted = linking.GalleryMediaCache(Path(directory), len(body))
            self.assertTrue(restarted.is_gallery_pinned(issuer, 4))
            for key in pinned_keys:
                self.assertIsNotNone(restarted.read(
                    key, gallery_key=gallery_key,
                ))
            self.assertIsNotNone(restarted.read(ordinary_key))

    def test_private_preload_intent_and_keyspace_are_account_bound(self) -> None:
        """Keep explicit private bytes and intent isolated across accounts.

        Returns:
            None: Intent survives a cache restart only for its exact issuer,
                verified account and gallery; pinned private bytes use the
                existing retained directory without entering ordinary inventory.
        """
        issuer = 'https://gallery.example/Galerie'
        other_issuer = 'https://other.example/Galerie'
        body = b'account-bound private bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), 1024)
            key = cache.private_key(issuer, 42, 4, 7, False)
            self.assertNotEqual(key, cache.private_key(issuer, 43, 4, 7, False))
            self.assertNotEqual(key, cache.private_key(issuer, 42, 5, 7, False))
            self.assertNotEqual(key, cache.private_key(other_issuer, 42, 4, 7, False))
            self.assertTrue(cache.set_private_preload_intent(issuer, 42, 4, True))
            self.assertTrue(cache.has_private_preload_intent(issuer, 42, 4))
            self.assertFalse(cache.has_private_preload_intent(issuer, 43, 4))
            self.assertFalse(cache.has_private_preload_intent(issuer, 42, 5))
            self.assertFalse(cache.has_private_preload_intent(other_issuer, 42, 4))

            gallery_key = cache.gallery_key(issuer, 4)
            self.assertTrue(cache.set_gallery_pinned(issuer, 4, True))
            self.assertTrue(cache.write(
                key, body, 'image/jpeg', etag, gallery_key=gallery_key,
            ))
            restarted = linking.GalleryMediaCache(Path(directory), 1024)
            self.assertTrue(restarted.has_private_preload_intent(issuer, 42, 4))
            self.assertEqual(
                body, restarted.read(key, gallery_key=gallery_key).body,
            )
            self.assertEqual([], restarted._inventory())
            self.assertTrue(restarted.set_private_preload_intent(
                issuer, 42, 4, False,
            ))
            self.assertFalse(restarted.has_private_preload_intent(issuer, 42, 4))

    def test_pin_promotes_legacy_hit_and_unpin_restores_ordinary_scope(self) -> None:
        """Promote legacy flat entries and return retained files on unpin.

        Returns:
            None: The selected gallery moves out of flat accounting, then back;
                another issuer's pinned directory remains untouched.
        """
        issuer = 'https://gallery.example/Galerie'
        other_issuer = 'https://other.example/Galerie'
        body = b'q' * TEST_PINNED_CACHE_BODY_BYTES
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), len(body))
            key = cache.key(issuer, 4, 7, True)
            other_key = cache.key(other_issuer, 4, 7, True)
            gallery_key = cache.gallery_key(issuer, 4)
            self.assertTrue(cache.set_gallery_pinned(other_issuer, 4, True))
            self.assertTrue(cache.write(key, body, 'image/jpeg', etag))
            self.assertTrue(cache.write(
                other_key, body, 'image/jpeg', etag,
                gallery_key=cache.gallery_key(other_issuer, 4),
            ))
            self.assertTrue(cache.set_gallery_pinned(issuer, 4, True))
            self.assertIsNotNone(cache.read(key, gallery_key=gallery_key))
            self.assertFalse((Path(directory) / (key + '.bin')).exists())
            retained_path = (
                Path(directory) / '.retained' / gallery_key / (key + '.bin')
            )
            self.assertTrue(retained_path.is_file())

            self.assertTrue(cache.set_gallery_pinned(issuer, 4, False))
            self.assertFalse(cache.is_gallery_pinned(issuer, 4))
            self.assertTrue((Path(directory) / (key + '.bin')).is_file())
            self.assertTrue(cache.is_gallery_pinned(other_issuer, 4))
            self.assertIsNotNone(cache.read(
                other_key,
                gallery_key=cache.gallery_key(other_issuer, 4),
            ))

    def test_failed_pin_promotion_is_not_reported_as_retained(self) -> None:
        """Refuse a local-retention proof when promotion cannot commit.

        Returns:
            None: A verified legacy flat body remains intact, but a failed
                retained write cannot be returned as a pinned-cache hit.
        """
        issuer = 'https://gallery.example/Galerie'
        body = b's' * TEST_PINNED_CACHE_BODY_BYTES
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), len(body))
            key = cache.key(issuer, 4, 7, True)
            gallery_key = cache.gallery_key(issuer, 4)
            self.assertTrue(cache.write(key, body, 'image/jpeg', etag))
            self.assertTrue(cache.set_gallery_pinned(issuer, 4, True))
            with mock.patch.object(cache, '_write_locked', return_value=False):
                self.assertIsNone(cache.read(key, gallery_key=gallery_key))
            self.assertTrue((Path(directory) / (key + '.bin')).is_file())
            retained_path = (
                Path(directory) / '.retained' / gallery_key / (key + '.bin')
            )
            self.assertFalse(retained_path.exists())

    def test_failed_unpin_keeps_marker_and_retained_original(self) -> None:
        """Leave a pin intact when ordinary admission cannot finish.

        Returns:
            None: A failed quota-managed copy does not remove the preference or
                any retained gallery object.
        """
        issuer = 'https://gallery.example/Galerie'
        body = b'r' * TEST_PINNED_CACHE_BODY_BYTES
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), len(body))
            gallery_key = cache.gallery_key(issuer, 4)
            key = cache.key(issuer, 4, 7, True)
            self.assertTrue(cache.set_gallery_pinned(issuer, 4, True))
            self.assertTrue(cache.write(
                key, body, 'image/jpeg', etag, gallery_key=gallery_key,
            ))
            with mock.patch.object(
                linking.GalleryMediaCache, '_write_locked', return_value=False,
            ):
                self.assertFalse(cache.set_gallery_pinned(issuer, 4, False))
            self.assertTrue(cache.is_gallery_pinned(issuer, 4))
            self.assertIsNotNone(cache.read(key, gallery_key=gallery_key))

    def test_retained_cleanup_failure_keeps_pin_marker(self) -> None:
        """Keep the pin active when a retained file cannot be removed.

        Returns:
            None: A failed cleanup after ordinary copying returns false and
                leaves the marker and the busy retained entry in place.
        """
        issuer = 'https://gallery.example/Galerie'
        body = b't' * TEST_PINNED_CACHE_BODY_BYTES
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), len(body))
            gallery_key = cache.gallery_key(issuer, 4)
            key = cache.key(issuer, 4, 7, True)
            self.assertTrue(cache.set_gallery_pinned(issuer, 4, True))
            self.assertTrue(cache.write(
                key, body, 'image/jpeg', etag, gallery_key=gallery_key,
            ))
            retained_path = (
                Path(directory) / '.retained' / gallery_key / (key + '.bin')
            )
            with mock.patch.object(
                cache, '_unlink_if_matches', side_effect=[False],
            ):
                self.assertFalse(cache.set_gallery_pinned(issuer, 4, False))
            self.assertTrue(cache.is_gallery_pinned(issuer, 4))
            self.assertTrue(retained_path.is_file())

    def test_gallery_pinning_rejects_unsafe_directory(self) -> None:
        """Refuse retained paths whose hashed parent is not a real directory.

        Returns:
            None: An unexpected regular file at the retained root fails closed.
        """
        issuer = 'https://gallery.example/Galerie'
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), 1024)
            retained_root = Path(directory) / '.retained'
            retained_root.write_text('not a directory', encoding='utf-8')
            self.assertFalse(cache.set_gallery_pinned(issuer, 4, True))
            self.assertFalse(cache.is_gallery_pinned(issuer, 4))

    def test_retained_scan_uses_direct_path_identity(self) -> None:
        """Avoid zero-inode DirEntry metadata in retained cleanup snapshots.

        Returns:
            None: A synthetic Windows-style DirEntry inode of zero is ignored;
                the direct no-follow path stat matches the actual cache object.
        """
        with TemporaryDirectory() as directory:
            cache = linking.GalleryMediaCache(Path(directory), 1024)
            gallery_key = cache.gallery_key('https://gallery.example', 4)
            retained_directory = Path(directory) / '.retained' / gallery_key
            retained_directory.mkdir(parents=True)
            key = cache.key('https://gallery.example', 4, 7, True)
            entry_path = retained_directory / (key + '.bin')
            entry_path.write_bytes(b'fixture')
            listed_entry = mock.Mock()
            listed_entry.name = entry_path.name
            listed_entry.path = str(entry_path)
            listed_entry.stat.return_value = mock.Mock(st_ino=0)
            listing = mock.MagicMock()
            listing.__enter__.return_value = iter((listed_entry,))

            with mock.patch.object(
                gallery_media_cache.os, 'scandir', return_value=listing,
            ):
                retained = cache._retained_entries(retained_directory)

            self.assertIsNotNone(retained)
            self.assertEqual(1, len(retained))
            self.assertTrue(os.path.samestat(
                entry_path.lstat(), retained[0][1],
            ))
            listed_entry.stat.assert_not_called()


class NativeMediaTransportTests(unittest.TestCase):
    """Verify opt-in binary cache headers and conditional response handling."""

    def _pooled_response(
        self, body: bytes = b'{}', *, will_close: bool = False,
    ) -> mock.MagicMock:
        """Build one fully consumed bounded JSON response for pool fixtures.

        Args:
            body (bytes): Exact JSON bytes returned by the fake response.
            will_close (bool): Whether the peer requests connection closure.

        Returns:
            mock.MagicMock: HTTPResponse-shaped object with observable state.
        """
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.version = 11
        response.headers = Message()
        response.headers['Content-Type'] = 'application/json'
        response.headers['Cache-Control'] = 'no-store'
        response.headers['Content-Length'] = str(len(body))
        response.read.return_value = body
        response.will_close = will_close
        response.isclosed.return_value = True
        return response

    def _pooled_connection(
        self, control: linking._LinkingTransportControl,
        responses: tuple[mock.MagicMock, ...],
        request_failures: tuple[Exception | None, ...] = (),
    ) -> tuple[mock.MagicMock, mock.MagicMock]:
        """Make a fake HTTP connection and socket owned by one request.

        Args:
            control (linking._LinkingTransportControl): Initial socket owner.
            responses (tuple[mock.MagicMock, ...]): Ordered response fixtures.
            request_failures (tuple[Exception | None, ...]): Optional ordered
                send outcomes; None represents a successful send.

        Returns:
            tuple[mock.MagicMock, mock.MagicMock]: Client and socket spies.
        """
        connection = mock.MagicMock()
        sock = mock.MagicMock()
        connection.sock = None
        connection._linking_control = control

        def connect() -> None:
            """Attach the fake socket to its first transport control.

            Returns:
                None: The connection now has a control-owned socket.
            """
            connection.sock = sock
            control.set_socket(sock)

        connection.connect.side_effect = connect
        connection.getresponse.side_effect = responses
        if request_failures:
            connection.request.side_effect = request_failures
        return connection, sock

    def test_pool_reuses_one_client_for_sequential_gets(self) -> None:
        """Reuse one consumed HTTP/1.1 connection across sequential GETs.

        Returns:
            None: Counts show one setup and two reuses with three safe responses.
        """
        issuer = 'https://gallery.example/Galerie'
        pool = linking._MediaConnectionPool()
        controls = tuple(
            linking._LinkingTransportControl(
                time.monotonic() + TEST_WORKER_WAIT_SECONDS, None,
            )
            for _ in range(TEST_POOLED_GET_COUNT)
        )
        timings = tuple(linking.MediaReadTimings() for _ in controls)
        responses = tuple(self._pooled_response() for _ in controls)
        created: list[mock.MagicMock] = []

        def make_connection(
            _issuer: linking.SplitResult,
            control: linking._LinkingTransportControl,
            *, context: linking.ssl.SSLContext | None = None,
        ) -> mock.MagicMock:
            """Create one fake TLS client using the pool's context.

            Args:
                _issuer (linking.SplitResult): Parsed installation issuer.
                control (linking._LinkingTransportControl): Current socket owner.
                context (linking.ssl.SSLContext | None): Certificate context
                    supplied by the pool.

            Returns:
                mock.MagicMock: Client with one queued response per GET.
            """
            client, _sock = self._pooled_connection(control, responses)
            created.append(client)
            return client

        with mock.patch.object(
            linking.ssl, 'create_default_context', return_value=mock.Mock(),
        ) as context_factory, mock.patch.object(
            linking, '_linking_connection', side_effect=make_connection,
        ):
            for control, timing in zip(controls, timings, strict=True):
                self.assertEqual(
                    (b'{}', 'application/json', None, False),
                    linking._native_media_read_once(
                        issuer,
                        'page=app_linking_media&gallery_id=4&kind=photos',
                        'opaque-test-bearer', ('application/json',), control,
                        pool=pool, timings=timing,
                    ),
                )

        self.assertEqual(1, len(created))
        self.assertEqual(1, context_factory.call_count)
        self.assertIs(pool._context, context_factory.return_value)
        self.assertEqual([0, 1, 1], [row.connection_reuses for row in timings])
        self.assertEqual([1, 1, 1], [row.http_requests for row in timings])
        self.assertEqual([0, 0, 0], [row.tls_handshakes for row in timings])
        self.assertEqual(TEST_POOLED_GET_COUNT, sum(
            row.http_requests for row in timings
        ))
        self.assertEqual(1, len(created))
        measurement = {
            'runtime': sys.version,
            'platform': sys.platform,
            'fixture': (
                'Simulated HTTPS client and SSLContext; one fake connected '
                'socket; no real TCP connection or TLS handshake.'
            ),
            'requests': sum(row.http_requests for row in timings),
            'connections': len(created),
            'tls_handshakes': sum(row.tls_handshakes for row in timings),
            'connection_reuses': sum(
                row.connection_reuses for row in timings
            ),
            'context_setup_calls': context_factory.call_count,
            'phase_ms': {
                'context': sum(row.context_ms for row in timings),
                'dns': sum(row.dns_ms for row in timings),
                'tcp': sum(row.tcp_ms for row in timings),
                'tls': sum(row.tls_ms for row in timings),
                'ttfb': sum(row.ttfb_ms for row in timings),
                'body': sum(row.body_ms for row in timings),
            },
        }
        measurement_path = (
            WINAPP_DIR.parent / 'cache' / 'test-audit'
            / 'photos-transport-measurement.json'
        )
        measurement_path.parent.mkdir(parents=True, exist_ok=True)
        measurement_path.write_text(
            json.dumps(measurement, indent=2) + '\n', encoding='utf-8',
        )
        pool.discard(close=True)

    def test_loopback_server_observes_one_tcp_connection_for_three_gets(self) -> None:
        """Measure real local HTTP/1.1 keepalive without external traffic.

        Returns:
            None: Three complete GETs use one accepted loopback socket; timing
                values remain observations without a speed threshold.
        """
        class CountingServer(http.server.ThreadingHTTPServer):
            """Count accepted TCP connections for this isolated fixture."""

            daemon_threads = True

            def __init__(
                self, address: tuple[str, int],
                handler: type[http.server.BaseHTTPRequestHandler],
            ) -> None:
                """Initialize synchronized fixture counters before serving.

                Args:
                    address (tuple[str, int]): Loopback host and ephemeral port.
                    handler (type[http.server.BaseHTTPRequestHandler]): Fixture
                        protocol handler.

                Returns:
                    None: Creates a threaded local-only HTTP server.
                """
                self.accepted_connections = 0
                self.request_count = 0
                self.counter_lock = threading.Lock()
                super().__init__(address, handler)

            def get_request(self) -> tuple[socket.socket, tuple[str, int]]:
                """Count each accepted client socket before returning it.

                Returns:
                    tuple[socket.socket, tuple[str, int]]: Accepted connection
                        and its numeric peer address.
                """
                request, peer = super().get_request()
                with self.counter_lock:
                    self.accepted_connections += 1
                return request, peer

        class JsonHandler(http.server.BaseHTTPRequestHandler):
            """Serve one bounded no-store JSON body with HTTP/1.1 framing."""

            protocol_version = 'HTTP/1.1'

            def do_GET(self) -> None:
                """Count one GET and return a complete persistent response.

                Returns:
                    None: Writes a two-byte no-store JSON object to the client.
                """
                server = self.server
                with server.counter_lock:
                    server.request_count += 1
                body = b'{}'
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Cache-Control', 'no-store')
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        server = CountingServer(('127.0.0.1', 0), JsonHandler)
        server.daemon_threads = True
        serve_thread = threading.Thread(
            target=server.serve_forever, name='LinkingPoolLoopbackFixture',
            daemon=True,
        )
        serve_thread.start()
        pool = linking._MediaConnectionPool()
        timings = tuple(
            linking.MediaReadTimings() for _ in range(TEST_POOLED_GET_COUNT)
        )
        issuer = f'http://localhost:{server.server_port}/Galerie'
        native_getaddrinfo = socket.getaddrinfo

        # Zero defaults mirror socket.getaddrinfo's unspecified family/type/
        # protocol/flags. The fixture fixes only the IPv4 listener identity,
        # avoiding an unrelated refused IPv6 peer on Windows.
        def resolve_fixture_host(
            host: str | bytes, port: int | str | None,
            family: int = 0, type: int = 0, proto: int = 0, flags: int = 0,
        ) -> list[tuple[int, int, int, str, tuple[str, int]]]:
            """Resolve the expected fixture host through native IPv4 DNS logic.

            Args:
                host (str | bytes): Host requested by the production transport.
                port (int | str | None): Requested local server port.
                family (int): Address family requested by the transport.
                type (int): Socket type requested by the transport.
                proto (int): Protocol number requested by the transport.
                flags (int): Resolver flags passed by the transport.

            Returns:
                list[tuple[int, int, int, str, tuple[str, int]]]: Native
                    resolver answers constrained to the server's IPv4 family.

            Raises:
                AssertionError: The transport does not target this local fixture.
            """
            if (host != 'localhost' or port != server.server_port):
                raise AssertionError('Unexpected loopback fixture resolver target.')
            return native_getaddrinfo(
                '127.0.0.1', port, family, type, proto, flags,
            )

        try:
            with mock.patch.object(
                linking.socket, 'getaddrinfo', side_effect=resolve_fixture_host,
            ):
                for timing in timings:
                    result = linking._run_linking_transport(
                        lambda control: linking._native_media_read_once(
                            issuer,
                            'page=app_linking_media&gallery_id=4&kind=photos',
                            'opaque-test-bearer', ('application/json',), control,
                            pool=pool, timings=timing,
                        ),
                        None,
                        TEST_WORKER_WAIT_SECONDS,
                        linking.LINKING_SOCKET_TIMEOUT_SECONDS,
                        phase='media', route='app_linking_media',
                    )
                    self.assertEqual(
                        (b'{}', 'application/json', None, False), result,
                    )

            self.assertEqual(1, server.accepted_connections)
            self.assertEqual(TEST_POOLED_GET_COUNT, server.request_count)
            self.assertEqual([1, 0, 0], [row.connections for row in timings])
            self.assertEqual([0, 1, 1], [row.connection_reuses for row in timings])
            self.assertEqual([0, 0, 0], [row.tls_handshakes for row in timings])
            measurement = {
                'runtime': sys.version,
                'platform': sys.platform,
                'fixture': (
                    'Real disposable IPv4 loopback HTTP/1.1; accepted TCP socket '
                    'counted by the fixture; native resolver constrained to '
                    '127.0.0.1; no TLS or external network.'
                ),
                'requests': sum(row.http_requests for row in timings),
                'connections': server.accepted_connections,
                'tls_handshakes': sum(row.tls_handshakes for row in timings),
                'connection_reuses': sum(
                    row.connection_reuses for row in timings
                ),
                'context_setup_calls': 0,
                'phase_ms': {
                    'context': sum(row.context_ms for row in timings),
                    'dns': sum(row.dns_ms for row in timings),
                    'tcp': sum(row.tcp_ms for row in timings),
                    'tls': sum(row.tls_ms for row in timings),
                    'ttfb': sum(row.ttfb_ms for row in timings),
                    'body': sum(row.body_ms for row in timings),
                },
            }
            measurement_path = (
                WINAPP_DIR.parent / 'cache' / 'test-audit'
                / 'photos-loopback-measurement.json'
            )
            measurement_path.parent.mkdir(parents=True, exist_ok=True)
            measurement_path.write_text(
                json.dumps(measurement, indent=2) + '\n', encoding='utf-8',
            )
        finally:
            pool.discard(close=True)
            server.shutdown()
            serve_thread.join(timeout=TEST_WORKER_WAIT_SECONDS)
            server.server_close()

    def test_close_header_forces_fresh_socket_but_reuses_context(self) -> None:
        """Honor server close while reusing the session TLS configuration.

        Returns:
            None: The second GET has a fresh client and identical SSLContext.
        """
        issuer = 'https://gallery.example/Galerie'
        pool = linking._MediaConnectionPool()
        context = mock.Mock()
        controls = tuple(
            linking._LinkingTransportControl(
                time.monotonic() + TEST_WORKER_WAIT_SECONDS, None,
            )
            for _ in range(2)
        )
        responses = (
            self._pooled_response(will_close=True), self._pooled_response(),
        )
        contexts: list[object] = []
        response_index = 0

        def make_connection(
            _issuer: linking.SplitResult,
            control: linking._LinkingTransportControl,
            *, context: linking.ssl.SSLContext | None = None,
        ) -> mock.MagicMock:
            """Create a fake client and record its context identity.

            Args:
                _issuer (linking.SplitResult): Parsed installation issuer.
                control (linking._LinkingTransportControl): Current socket owner.
                context (linking.ssl.SSLContext | None): Shared verified context.

            Returns:
                mock.MagicMock: Fresh connection with a queued response.
            """
            nonlocal response_index
            contexts.append(context)
            response = responses[response_index]
            response_index += 1
            return self._pooled_connection(control, (response,))[0]

        with mock.patch.object(
            linking.ssl, 'create_default_context', return_value=context,
        ) as context_factory, mock.patch.object(
            linking, '_linking_connection', side_effect=make_connection,
        ) as factory:
            for control in controls:
                linking._native_media_read_once(
                    issuer,
                    'page=app_linking_media&gallery_id=4&kind=photos',
                    'opaque-test-bearer', ('application/json',), control,
                    pool=pool,
                )

        self.assertEqual(2, factory.call_count)
        self.assertEqual(1, context_factory.call_count)
        self.assertEqual([context, context], contexts)
        pool.discard(close=True)

    def test_stale_borrow_retries_once_before_response(self) -> None:
        """Retry a borrowed stale GET once while no response exists.

        Returns:
            None: One stale send is replaced by one new connection request.
        """
        issuer = 'https://gallery.example/Galerie'
        pool = linking._MediaConnectionPool()
        first_control = linking._LinkingTransportControl(
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, None,
        )
        first_client, _sock = self._pooled_connection(
            first_control,
            (self._pooled_response(), self._pooled_response()),
            (None, http.client.RemoteDisconnected('stale socket')),
        )
        created: list[mock.MagicMock] = []
        replacement_control: list[linking._LinkingTransportControl] = []
        factory_calls = 0

        def make_connection(
            _issuer: linking.SplitResult,
            control: linking._LinkingTransportControl,
            *, context: linking.ssl.SSLContext | None = None,
        ) -> mock.MagicMock:
            """Create the replacement connection after stale detection.

            Args:
                _issuer (linking.SplitResult): Parsed installation issuer.
                control (linking._LinkingTransportControl): Retry socket owner.
                context (linking.ssl.SSLContext | None): Optional session context.

            Returns:
                mock.MagicMock: Replacement client with a complete response.
            """
            nonlocal factory_calls
            factory_calls += 1
            if factory_calls == 1:
                created.append(first_client)
                return first_client
            replacement_control.append(control)
            client, _sock = self._pooled_connection(
                control, (self._pooled_response(),),
            )
            created.append(client)
            return client

        with mock.patch.object(
            linking, '_linking_connection', side_effect=make_connection,
        ):
            linking._native_media_read_once(
                issuer,
                'page=app_linking_media&gallery_id=4&kind=photos',
                'opaque-test-bearer', ('application/json',), first_control,
                pool=pool,
            )
            retry_control = linking._LinkingTransportControl(
                time.monotonic() + TEST_WORKER_WAIT_SECONDS, None,
            )
            timing = linking.MediaReadTimings()
            result = linking._native_media_read_once(
                issuer,
                'page=app_linking_media&gallery_id=4&kind=photos',
                'opaque-test-bearer', ('application/json',), retry_control,
                pool=pool, timings=timing,
            )

        self.assertEqual((b'{}', 'application/json', None, False), result)
        self.assertEqual(2, len(created))
        self.assertEqual(2, first_client.request.call_count)
        self.assertEqual(1, created[1].request.call_count)
        self.assertEqual(2, timing.http_requests)
        self.assertEqual(1, timing.connection_reuses)
        self.assertEqual([retry_control], replacement_control)
        self.assertIs(first_client._linking_control, retry_control)
        pool.discard(close=True)

    def test_incomplete_response_body_is_never_retried_or_retained(self) -> None:
        """Discard an incomplete response instead of reusing or replaying it.

        Returns:
            None: One body read fails and leaves no idle pool connection.
        """
        issuer = 'https://gallery.example/Galerie'
        pool = linking._MediaConnectionPool()
        control = linking._LinkingTransportControl(
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, None,
        )
        response = self._pooled_response()
        response.read.side_effect = http.client.IncompleteRead(b'{', 1)
        connection, _sock = self._pooled_connection(control, (response,))
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            with self.assertRaises(http.client.IncompleteRead):
                linking._native_media_read_once(
                    issuer,
                    'page=app_linking_media&gallery_id=4&kind=photos',
                    'opaque-test-bearer', ('application/json',), control,
                    pool=pool,
                )
        self.assertEqual(1, connection.request.call_count)
        self.assertIsNone(pool._connection)
        connection.close.assert_called_once()
        pool.discard(close=True)

    def test_late_old_control_cannot_close_socket_borrowed_by_new_control(self) -> None:
        """Protect a successor's socket from late stop and finish calls.

        Returns:
            None: A released owner cannot close a socket borrowed by its successor.
        """
        issuer = linking.urlsplit('https://gallery.example/Galerie')
        pool = linking._MediaConnectionPool()
        old = linking._LinkingTransportControl(
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, None,
        )
        sock = mock.MagicMock()
        old.set_socket(sock)
        connection = mock.MagicMock()
        connection.sock = sock
        self.assertTrue(pool.release(issuer, connection, old, 0))

        current = linking._LinkingTransportControl(
            time.monotonic() + TEST_WORKER_WAIT_SECONDS, None,
        )
        borrowed, reused, _epoch = pool.borrow(issuer, current)
        self.assertIs(connection, borrowed)
        self.assertTrue(reused)
        old.stop()
        old.finish()
        sock.close.assert_not_called()
        current.finish()
        sock.close.assert_called_once()
        pool.discard(close=True)

    def test_pool_rejects_cancelled_expired_and_revoked_lease(self) -> None:
        """Keep cancellation, expiry and epoch changes out of idle storage.

        Returns:
            None: No stale control can publish a connection into the pool.
        """
        issuer = linking.urlsplit('https://gallery.example/Galerie')
        for mode in ('cancelled', 'expired', 'revoked'):
            with self.subTest(mode=mode):
                pool = linking._MediaConnectionPool()
                deadline = (
                    time.monotonic() - 1 if mode == 'expired'
                    else time.monotonic() + TEST_WORKER_WAIT_SECONDS
                )
                control = linking._LinkingTransportControl(deadline, None)
                sock = mock.MagicMock()
                connection = mock.MagicMock()
                connection.sock = sock
                if mode == 'cancelled':
                    control.set_socket(sock)
                    control.stop()
                elif mode == 'revoked':
                    control.set_socket(sock)
                    pool.discard()
                self.assertFalse(pool.release(issuer, connection, control, 0))
                self.assertIsNone(pool._connection)
                pool.discard(close=True)

    def _response(
        self, status: int, headers: Message, body: bytes = b'',
    ) -> tuple[mock.MagicMock, mock.MagicMock]:
        """Build one context-managed binary response and connection.

        Args:
            status (int): HTTP status returned by the server.
            headers (Message): Exact synthetic response headers.
            body (bytes): Bounded binary body returned by ``read``.

        Returns:
            tuple[mock.MagicMock, mock.MagicMock]: Connection and response spies.
        """
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = status
        response.headers = headers
        response.read.return_value = body
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        return connection, response

    def test_header_timeout_reports_only_the_fixed_transport_step(self) -> None:
        """Attribute a header wait without retaining request or error text.

        Returns:
            None: The typed timeout identifies headers and omits fixture secrets.
        """
        connection = mock.MagicMock()
        connection.getresponse.side_effect = socket.timeout(
            'https://private.example/gallery bearer=fixture-secret',
        )
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            with self.assertRaises(linking.LinkingResponseError) as raised:
                linking._run_linking_transport(
                    lambda control: linking._native_media_read_once(
                        'https://private.example/gallery',
                        'page=app_linking_media&gallery_id=4&kind=photos',
                        'fixture-secret', ('application/json',), control,
                    ),
                    None, linking.LINKING_REQUEST_DEADLINE_SECONDS,
                    linking.LINKING_SOCKET_TIMEOUT_SECONDS,
                    phase='media', route='app_linking_media',
                )

        self.assertEqual('timeout', raised.exception.reason)
        self.assertEqual('headers', raised.exception.transport_step)
        self.assertIn('transport_step=headers', raised.exception.safe_summary)
        self.assertNotIn('private.example', raised.exception.safe_summary)
        self.assertNotIn('fixture-secret', raised.exception.safe_summary)

    def test_body_timeout_survives_safe_photos_diagnostic_formatting(self) -> None:
        """Retain the body-read step through the Photos safe formatter.

        Returns:
            None: The bounded summary reports body timeout without payload data.
        """
        headers = Message()
        headers['Content-Type'] = 'application/json'
        headers['Cache-Control'] = 'no-store'
        connection, response = self._response(200, headers)
        response.read.side_effect = socket.timeout('private response body')
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            with self.assertRaises(linking.LinkingResponseError) as raised:
                linking._run_linking_transport(
                    lambda control: linking._native_media_read_once(
                        'https://private.example/gallery',
                        'page=app_linking_media&gallery_id=4&kind=photos',
                        'fixture-secret', ('application/json',), control,
                    ),
                    None, linking.LINKING_REQUEST_DEADLINE_SECONDS,
                    linking.LINKING_SOCKET_TIMEOUT_SECONDS,
                    phase='media', route='app_linking_media',
                )

        failure = format_safe_photos_failure(
            'media-fetch', 'refresh', raised.exception,
        )
        self.assertIn('transport_step=body', failure)
        self.assertNotIn('private.example', failure)
        self.assertNotIn('fixture-secret', failure)
        self.assertNotIn('private response body', failure)

    def test_post_response_currentness_guard_reports_safe_local_reason(self) -> None:
        """Name each local guard without attributing an HTTP refusal.

        Returns:
            None: Four currentness conditions remain fail-closed and redact all
                dynamic exception text from their bounded Photos summaries.
        """
        guard_cases = (
            'cancelled', 'session_closed', 'deadline', 'grant_expired',
        )
        for currentness_reason in guard_cases:
            # Fixed 100/200/600-second clock values make the boundary vectors
            # runner-independent; they do not define application timing policy.
            fake_clock = [100.0]
            deadline = 200.0 if currentness_reason == 'deadline' else None
            with mock.patch.object(
                linking, 'time', SimpleNamespace(
                    monotonic=lambda: fake_clock[0],
                    perf_counter=time.perf_counter,
                ),
            ):
                session = linking.LinkingSession(
                    'https://gallery.example/Galerie', 'pgla_' + 'A' * 43,
                    fake_clock[0] + 600.0, linking.GALLERIES_SCOPE,
                )
                self.addCleanup(session.close)
                cancelled = threading.Event()

                def finish_transport(
                    request_once: Callable[
                        [linking._LinkingTransportControl],
                        linking._NativeMediaResponse,
                    ],
                    transport_cancelled: threading.Event | None,
                    deadline_seconds: float,
                    inactivity_seconds: float,
                    *, phase: linking._LinkingDiagnosticPhase,
                    route: linking._LinkingDiagnosticRoute,
                    timings: linking.MediaReadTimings | None = None,
                    server_timing_out: list[
                        tuple[linking.PassiveServerTiming | None, float | None]
                    ] | None = None,
                ) -> linking._NativeMediaResponse:
                    """Return one safe media body after staging a fence state.

                    Args:
                        request_once (Callable[[linking._LinkingTransportControl], linking._NativeMediaResponse]): Native response reader is unused by
                            this controlled transport.
                        transport_cancelled (threading.Event | None): Current
                            read's cancellation signal.
                        deadline_seconds (float): Existing transport bound.
                        inactivity_seconds (float): Existing socket bound.
                        phase (linking._LinkingDiagnosticPhase): Fixed media
                            diagnostic phase.
                        route (linking._LinkingDiagnosticRoute): Fixed media
                            route.
                        timings (linking.MediaReadTimings | None): Optional
                            physical read metrics.
                        server_timing_out (list[tuple[linking.PassiveServerTiming | None, float | None]] | None): Optional passive timing output.

                    Returns:
                        linking._NativeMediaResponse: Valid-shaped response accepted by the media owner.
                    """
                    del request_once, deadline_seconds, inactivity_seconds
                    del phase, route, timings, server_timing_out
                    if currentness_reason == 'cancelled':
                        if transport_cancelled is not None:
                            transport_cancelled.set()
                    elif currentness_reason == 'session_closed':
                        session._closed = True
                    elif currentness_reason == 'deadline':
                        fake_clock[0] = deadline if deadline is not None else 0.0
                    else:
                        session._expires_at = fake_clock[0] - 1.0
                    return b'{}', 'application/json', None, False

                with self.subTest(currentness_reason=currentness_reason), mock.patch.object(
                    linking, '_run_linking_transport', side_effect=finish_transport,
                ) as transport:
                    with self.assertRaises(
                        linking.LinkingMediaNotCurrent,
                    ) as raised:
                        session._media_read(
                            4, 'photos', None, cancelled,
                            operation_deadline=deadline,
                        )
                    transport.assert_called_once()

                self.assertEqual(
                    currentness_reason,
                    raised.exception.currentness_reason,
                )
                self.assertFalse(isinstance(
                    raised.exception, linking.LinkingResponseError,
                ))
                summary = format_safe_photos_failure(
                    'media-fetch', 'refresh', raised.exception,
                )
                self.assertIn(
                    f'currentness={currentness_reason}', summary,
                )
                self.assertNotIn('http=', summary)
                self.assertNotIn('gallery.example', summary)
                self.assertTrue(summary.isprintable())
                if currentness_reason == 'cancelled':
                    raised.exception.args = (
                        'token=fixture-secret C:\\private\\photo.jpg',
                    )
                    summary = format_safe_photos_failure(
                        'media-fetch', 'refresh', raised.exception,
                    )
                    self.assertNotIn('fixture-secret', summary)
                    self.assertNotIn('C:\\private', summary)
                    self.assertIn('currentness=cancelled', summary)

        invalid_reason = 'token=fixture-secret C:\\private\\photo.jpg'
        with self.assertRaises(ValueError):
            linking.LinkingMediaNotCurrent(invalid_reason)
        mutated = linking.LinkingMediaNotCurrent('grant_expired')
        setattr(mutated, 'currentness_reason', invalid_reason)
        mutated.args = (invalid_reason,)
        summary = format_safe_photos_failure(
            'media-fetch', 'refresh', mutated,
        )
        self.assertNotIn(invalid_reason, summary)
        self.assertNotIn('fixture-secret', summary)
        self.assertNotIn('C:\\private', summary)
        self.assertNotIn('currentness=token', summary)

    def test_restricted_photo_transport_has_no_cache_validator(self) -> None:
        """Accept the explicit restricted lane only for an Admin media reader.

        Returns:
            None: No-store bytes carry no reusable validator or cache admission.
        """
        body = b'restricted photo bytes'
        headers = Message()
        headers['Content-Type'] = 'image/jpeg'
        headers['Content-Length'] = str(len(body))
        headers['Cache-Control'] = 'no-store'
        headers['X-PHP-Gallery-Media-Restricted'] = '1'
        query = 'page=app_linking_media&gallery_id=4&kind=photo&image_id=7'
        connection, _response = self._response(200, headers, body)
        with mock.patch.object(linking, '_linking_connection', return_value=connection):
            with self.assertRaises(linking.LinkingError):
                linking._native_media_read_once(
                    'https://gallery.example', query, 'fixture', ('image/jpeg',), mock.Mock(),
                )
            result = linking._native_media_read_once(
                'https://gallery.example', query, 'fixture', ('image/jpeg',), mock.Mock(),
                allow_restricted=True,
            )
        self.assertEqual((body, 'image/jpeg', None, False), result)

    def test_photo_binary_cache_opt_in_and_authorized_304(self) -> None:
        """Accept only the exact private photo cache negotiation contract.

        Returns:
            None: Valid 200 bytes and matching authorized 304 retain their
                strong ETag; an older no-store response remains uncached.
        """
        body = b'bounded test image bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        control = mock.Mock()
        headers = Message()
        headers['Content-Type'] = 'image/jpeg'
        headers['Content-Length'] = str(len(body))
        headers['ETag'] = etag
        headers['Cache-Control'] = 'private, max-age=60, must-revalidate'
        headers['X-PHP-Gallery-Photo-Cache'] = '1'
        headers['Vary'] = 'Authorization, X-PHP-Gallery-Photo-Cache'
        connection, _response = self._response(200, headers, body)
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            result = linking._native_media_read_once(
                'https://gallery.example',
                'page=app_linking_media&gallery_id=4&kind=photo&image_id=7',
                'opaque-test-bearer', ('image/jpeg',), control,
            )
        self.assertEqual((body, 'image/jpeg', etag, True), result)
        self.assertEqual('1', connection.request.call_args.kwargs['headers'][
            'X-PHP-Gallery-Photo-Cache'
        ])
        self.assertEqual(
            linking.LINKING_USER_AGENT,
            connection.request.call_args.kwargs['headers']['User-Agent'],
        )

        not_modified = Message()
        not_modified['ETag'] = etag
        not_modified['Cache-Control'] = 'private, max-age=60, must-revalidate'
        not_modified['X-PHP-Gallery-Photo-Cache'] = '1'
        not_modified['Vary'] = 'Authorization, X-PHP-Gallery-Photo-Cache'
        connection, _response = self._response(304, not_modified)
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            result = linking._native_media_read_once(
                'https://gallery.example',
                'page=app_linking_media&gallery_id=4&kind=photo&image_id=7',
                'opaque-test-bearer', ('image/jpeg',), control, etag,
            )
        self.assertEqual((None, '', etag, True), result)
        self.assertEqual(etag, connection.request.call_args.kwargs['headers']['If-None-Match'])

    def test_admin_preload_requires_exact_confirmed_wire_lane(self) -> None:
        """Bind private cache admission to the explicit Admin preload version.

        Returns:
            None: Exact 200 and authorized 304 responses cache; missing or
                duplicated confirmation fails closed.
        """
        body = b'private admin preload bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        query = 'page=app_linking_media&gallery_id=4&kind=photo&image_id=7'
        headers = Message()
        headers['Content-Type'] = 'image/jpeg'
        headers['Content-Length'] = str(len(body))
        headers['ETag'] = etag
        headers['Cache-Control'] = 'private, max-age=60, must-revalidate'
        headers['X-PHP-Gallery-Photo-Cache'] = '1'
        headers['X-PHP-Gallery-Preload'] = 'admin-v1'
        headers['Vary'] = (
            'Authorization, X-PHP-Gallery-Photo-Cache, X-PHP-Gallery-Preload'
        )
        connection, _response = self._response(200, headers, body)
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            result = linking._native_media_read_once(
                'https://gallery.example', query, 'opaque-test-bearer',
                ('image/jpeg',), mock.Mock(), admin_preload=True,
            )
        self.assertEqual((body, 'image/jpeg', etag, True), result)
        self.assertEqual(
            'admin-v1', connection.request.call_args.kwargs['headers'][
                'X-PHP-Gallery-Preload'
            ],
        )

        headers = Message()
        headers['ETag'] = etag
        headers['Cache-Control'] = 'private, max-age=60, must-revalidate'
        headers['X-PHP-Gallery-Photo-Cache'] = '1'
        headers['X-PHP-Gallery-Preload'] = 'admin-v1'
        headers['Vary'] = (
            'Authorization, X-PHP-Gallery-Photo-Cache, X-PHP-Gallery-Preload'
        )
        connection, _response = self._response(304, headers)
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            result = linking._native_media_read_once(
                'https://gallery.example', query, 'opaque-test-bearer',
                ('image/jpeg',), mock.Mock(), etag, admin_preload=True,
            )
        self.assertEqual((None, '', etag, True), result)

        for confirmation in (None, 'admin-v1, admin-v1'):
            invalid = Message()
            invalid['Content-Type'] = 'image/jpeg'
            invalid['Content-Length'] = str(len(body))
            invalid['ETag'] = etag
            invalid['Cache-Control'] = 'private, max-age=60, must-revalidate'
            invalid['X-PHP-Gallery-Photo-Cache'] = '1'
            invalid['Vary'] = (
                'Authorization, X-PHP-Gallery-Photo-Cache, '
                'X-PHP-Gallery-Preload'
            )
            if confirmation is not None:
                invalid['X-PHP-Gallery-Preload'] = confirmation
            connection, _response = self._response(200, invalid, body)
            with mock.patch.object(
                linking, '_linking_connection', return_value=connection,
            ), self.assertRaises(linking.LinkingError):
                linking._native_media_read_once(
                    'https://gallery.example', query, 'opaque-test-bearer',
                    ('image/jpeg',), mock.Mock(), admin_preload=True,
                )

    def test_policy_refusal_retains_only_finite_header_facts(self) -> None:
        """Explain rejected cache policy without retaining response headers.

        Returns:
            None: Missing, repeated and changed markers/directives become finite
                facts that survive safe error reconstruction without raw text.
        """
        query = 'page=app_linking_media&gallery_id=4&kind=photo&image_id=7'
        base = [
            ('Content-Type', 'image/jpeg'),
            ('Content-Length', '3'),
            ('ETag', '"' + hashlib.sha256(b'img').hexdigest() + '"'),
        ]
        cases = (
            (
                'missing', base,
                ('missing', 'missing', False, False, None,
                 False, False, False, 'missing', False, False),
            ),
            (
                'multiple', base + [
                    ('Cache-Control', 'private, max-age=60, must-revalidate'),
                    ('Cache-Control', 'private, max-age=60, must-revalidate'),
                    ('X-PHP-Gallery-Photo-Cache', '1'),
                    ('X-PHP-Gallery-Photo-Cache', '1'),
                    ('X-PHP-Gallery-Preload', 'admin-v1'),
                    ('X-PHP-Gallery-Preload', 'admin-v1'),
                    ('Vary', 'Authorization, X-PHP-Gallery-Photo-Cache'),
                ],
                ('multiple', 'multiple', True, True, None,
                 True, True, False, 'multiple', False, False),
            ),
            (
                'changed', base + [
                    (
                        'Cache-Control',
                        'private, max-age=61, must-revalidate, token=secret '
                        'https://private.example C:\\private\\image',
                    ),
                    ('X-PHP-Gallery-Photo-Cache', 'wrong token=secret'),
                    ('X-PHP-Gallery-Preload', 'wrong https://private.example'),
                    ('Vary', 'Accept, *'),
                ],
                ('different', 'different', True, True, 61,
                 False, False, True, 'different', False, False),
            ),
            (
                'encoding', base + [
                    ('Cache-Control', 'private, max-age=60, must-revalidate'),
                    ('X-PHP-Gallery-Photo-Cache', '1'),
                    ('X-PHP-Gallery-Preload', 'admin-v1'),
                    ('Vary', 'Authorization, X-PHP-Gallery-Photo-Cache, '
                     'X-PHP-Gallery-Preload'),
                    ('Content-Encoding', 'gzip'),
                ],
                ('expected', 'expected', True, True, 60,
                 True, True, False, 'expected', True, True),
            ),
            (
                'bounded-max-age', base + [
                    (
                        'Cache-Control',
                        'private, max-age=2147483648, must-revalidate',
                    ),
                    ('X-PHP-Gallery-Photo-Cache', '1'),
                    ('X-PHP-Gallery-Preload', 'admin-v1'),
                    ('Vary', 'Authorization, X-PHP-Gallery-Photo-Cache, '
                     'X-PHP-Gallery-Preload'),
                ],
                ('different', 'expected', True, True, None,
                 True, True, False, 'expected', True, False),
            ),
        )
        for name, header_rows, expected in cases:
            with self.subTest(case=name):
                headers = Message()
                for field, value in header_rows:
                    headers.add_header(field, value)
                connection, _response = self._response(200, headers, b'img')
                with mock.patch.object(
                    linking, '_linking_connection', return_value=connection,
                ), self.assertRaises(linking.LinkingResponseError) as raised:
                    linking._native_media_read_once(
                        'https://gallery.example', query, 'opaque-test-bearer',
                        ('image/jpeg',), mock.Mock(), admin_preload=True,
                    )

                error = raised.exception
                facts = error.media_policy_facts
                self.assertIsInstance(facts, linking.MediaPolicyFacts)
                self.assertEqual(
                    expected,
                    (
                        facts.cache_control, facts.cache_marker, facts.private,
                        facts.must_revalidate, facts.max_age_seconds,
                        facts.vary_auth, facts.vary_marker, facts.vary_wildcard,
                        facts.preload_marker, facts.vary_preload,
                        facts.encoding_present,
                    ),
                )
                self.assertEqual('response_policy', error.reason)
                copied = linking.LinkingResponseError(
                    error.phase, error.route, error.status,
                    error.content_kind, error.reason,
                    media_policy_facts=facts,
                )
                self.assertEqual(error.safe_summary, copied.safe_summary)
                self.assertIn('policy=cc:', error.safe_summary)
                formatted = format_safe_photos_failure(
                    'preload-fetch', 'preload', error,
                )
                self.assertIn(error.safe_summary, formatted)
                unsafe_facts = object.__new__(linking.MediaPolicyFacts)
                for field in (
                    'cache_control', 'cache_marker', 'private',
                    'must_revalidate', 'max_age_seconds', 'vary_auth',
                    'vary_marker', 'vary_wildcard', 'preload_marker',
                    'vary_preload', 'encoding_present',
                ):
                    object.__setattr__(unsafe_facts, field, getattr(facts, field))
                object.__setattr__(unsafe_facts, 'cache_control', 'token=secret')
                with self.assertRaises(ValueError):
                    linking.LinkingResponseError(
                        error.phase, error.route, error.status,
                        error.content_kind, error.reason,
                        media_policy_facts=unsafe_facts,
                    )
                error.media_policy_facts = unsafe_facts
                safe_after_mutation = format_safe_photos_failure(
                    'preload-fetch', 'preload', error,
                )
                self.assertNotIn('token=secret', safe_after_mutation)
                self.assertIn('reason=response_policy', safe_after_mutation)
                for secret in (
                    'token=secret', 'private.example', 'C:\\private',
                    'opaque-test-bearer',
                ):
                    self.assertNotIn(secret, error.safe_summary)
                    self.assertNotIn(secret, str(facts))

    def test_non_policy_refusal_has_no_media_policy_facts(self) -> None:
        """Limit header facts to explicit media response-policy refusals.

        Returns:
            None: An HTTP status refusal keeps its existing reason and does not
                acquire media cache-policy observations.
        """
        headers = Message()
        headers['Content-Type'] = 'text/html'
        headers['Cache-Control'] = 'token=private'
        connection, _response = self._response(503, headers)
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ), self.assertRaises(linking.LinkingResponseError) as raised:
            linking._native_media_read_once(
                'https://gallery.example',
                'page=app_linking_media&gallery_id=4&kind=photo&image_id=7',
                'opaque-test-bearer', ('image/jpeg',), mock.Mock(),
                admin_preload=True,
            )

        self.assertEqual('http_status', raised.exception.reason)
        self.assertIsNone(raised.exception.media_policy_facts)

    def test_retry_after_is_single_bounded_decimal(self) -> None:
        """Retain only one bounded decimal Retry-After from a no-store throttle.

        Returns:
            None: A valid delay is structured and safe; invalid or duplicate
                values are discarded without exposing a server message.
        """
        query = 'page=app_linking_media&gallery_id=4&kind=photo&image_id=7'
        for values, expected in (
            (('7',), 7), (('301',), None), (('7', '8'), None),
            (('Sun, 06 Nov'), None),
        ):
            headers = Message()
            headers['Content-Type'] = 'application/json'
            headers['Cache-Control'] = 'no-store'
            for value in values:
                headers.add_header('Retry-After', value)
            connection, _response = self._response(
                429, headers, b'{\"error\":\"temporarily_unavailable\"}',
            )
            with mock.patch.object(
                linking, '_linking_connection', return_value=connection,
            ), self.assertRaises(linking.LinkingMediaThrottled) as raised:
                linking._run_linking_transport(
                    lambda control: linking._native_media_read_once(
                        'https://gallery.example', query, 'opaque-test-bearer',
                        ('image/jpeg',), control, admin_preload=True,
                    ),
                    None, linking.LINKING_REQUEST_DEADLINE_SECONDS,
                    linking.LINKING_SOCKET_TIMEOUT_SECONDS,
                    phase='media', route='app_linking_media',
                )
            self.assertEqual(expected, raised.exception.retry_after_seconds)
            self.assertNotIn('temporarily_unavailable', str(raised.exception))
            self.assertEqual(
                'stage=media; route=app_linking_media; http=429; '
                'response_kind=json; reason=throttled',
                raised.exception.safe_summary,
            )

    def test_legacy_photo_response_remains_uncached(self) -> None:
        """Keep older no-store image servers usable without disk persistence.

        Returns:
            None: A valid legacy 200 returns bytes but has no cache admission.
        """
        body = b'bounded legacy image'
        headers = Message()
        headers['Content-Type'] = 'image/jpeg'
        headers['Content-Length'] = str(len(body))
        headers['ETag'] = '"' + hashlib.sha256(body).hexdigest() + '"'
        headers['Cache-Control'] = 'no-store'
        connection, _response = self._response(200, headers, body)
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            result = linking._native_media_read_once(
                'https://gallery.example',
                'page=app_linking_media&gallery_id=4&kind=photo&image_id=7',
                'opaque-test-bearer', ('image/jpeg',), mock.Mock(),
            )
        self.assertEqual((body, 'image/jpeg', headers['ETag'], False), result)

    def test_native_track_cache_requires_private_negotiation(self) -> None:
        """Admit complete audio bytes only after the private cache opt-in.

        Returns:
            None: Valid opt-in allows caching, while legacy no-store plays online.
        """
        body = b'bounded track bytes'
        etag = '"' + hashlib.sha256(body).hexdigest() + '"'
        for cacheable in (False, True):
            headers = Message()
            headers['Content-Type'] = 'audio/wav'
            headers['Content-Length'] = str(len(body))
            headers['ETag'] = etag
            headers['Cache-Control'] = (
                'private, no-cache' if cacheable else 'private, no-store, max-age=0'
            )
            if cacheable:
                headers['X-PHP-Gallery-Track-Cache'] = '1'
                headers['Vary'] = 'Authorization, X-PHP-Gallery-Track-Cache'
            connection, _response = self._response(200, headers, body)
            with self.subTest(cacheable=cacheable), mock.patch.object(
                linking, '_linking_connection', return_value=connection,
            ):
                result = linking._native_media_read_once(
                    'https://gallery.example',
                    'page=app_linking_media&gallery_id=4&kind=track&track_id=' + 'a' * 32,
                    'opaque-test-bearer', ('audio/wav',), mock.Mock(),
                )
                self.assertEqual((body, 'audio/wav', etag, cacheable), result)
                self.assertEqual('1', connection.request.call_args.kwargs['headers'][
                    'X-PHP-Gallery-Track-Cache'
                ])

    def test_native_media_page_distinguishes_authenticated_target_404(self) -> None:
        """Preserve only the exact private JSON target-unavailable response.

        Returns:
            None: Exact target 404 is typed; an unrelated 404 stays generic.
        """
        headers = Message()
        headers['Content-Type'] = 'application/json; charset=utf-8'
        headers['Cache-Control'] = 'no-store'
        connection, _response = self._response(
            404, headers, b'{"error":"target_unavailable"}',
        )
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            with self.assertRaises(linking.LinkingMediaUnavailable):
                linking._native_media_read_once(
                    'https://gallery.example',
                    'page=app_linking_media&gallery_id=4&kind=photos',
                    'opaque-test-bearer', ('application/json',), mock.Mock(),
                )
        headers['Cache-Control'] = 'public, max-age=30'
        connection, _response = self._response(
            404, headers, b'{"error":"target_unavailable"}',
        )
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            with self.assertRaises(linking.LinkingError) as raised:
                linking._native_media_read_once(
                    'https://gallery.example',
                    'page=app_linking_media&gallery_id=4&kind=photos',
                    'opaque-test-bearer', ('application/json',), mock.Mock(),
                )
        self.assertNotIsInstance(raised.exception, linking.LinkingMediaUnavailable)

    def test_native_media_revision_binds_gallery_and_hash(self) -> None:
        """Accept only the exact gallery and canonical aggregate hash.

        Returns:
            None: Malformed or cross-gallery revisions fail closed.
        """
        session = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 60, linking.GALLERIES_SCOPE,
        )
        good = {'gallery_id': 42, 'revision': 'a' * 64}
        for row in (good, {**good, 'gallery_id': 43},
                    {**good, 'revision': 'A' * 64}, {**good, 'extra': 1}):
            with self.subTest(row=row), mock.patch.object(
                session, '_media_read', return_value=(
                    json.dumps(row).encode(), 'application/json', None, False,
                ),
            ):
                if row == good:
                    self.assertEqual(good['revision'], session.media_revision(42))
                else:
                    with self.assertRaises(linking.LinkingError):
                        session.media_revision(42)

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
                'photos': [{'id': 100, 'title': 'Fixture', 'optional': True,
                            'latitude': 49.2, 'longitude': 16.6}],
                'next_after': None, 'optional': 'supported'}
        for page in (good, {**good, 'gallery_id': 8}, {**good, 'next_after': 100}):
            with self.subTest(page=page), mock.patch.object(
                session, '_media_read', return_value=(
                    json.dumps(page).encode(), 'application/json', None, False,
                ),
            ):
                if page == good:
                    started = time.monotonic()
                    photos = session.media_page(42, 99).photos
                    completed = time.monotonic()
                    self.assertEqual(len(photos), 1)
                    self.assertEqual(
                        linking.LinkedPhoto(100, 'Fixture', 49.2, 16.6),
                        dataclass_replace(
                            photos[0], thumbnail_verified_at=None,
                        ),
                    )
                    self.assertIsNotNone(photos[0].thumbnail_verified_at)
                    self.assertGreaterEqual(
                        photos[0].thumbnail_verified_at, started,
                    )
                    self.assertLessEqual(
                        photos[0].thumbnail_verified_at, completed,
                    )
                else:
                    with self.assertRaises(linking.LinkingError):
                        session.media_page(42, 99)
        for page_size in (linking.NATIVE_MEDIA_LEGACY_PAGE_SIZE, linking.NATIVE_MEDIA_PAGE_SIZE):
            photos = [{'id': index + 100, 'title': 'Fixture'} for index in range(page_size)]
            page = {**good, 'photos': photos, 'next_after': photos[-1]['id']}
            with self.subTest(page_size=page_size), mock.patch.object(
                session, '_media_read', return_value=(
                    json.dumps(page).encode(), 'application/json', None, False,
                ),
            ):
                result = session.media_page(42, 99)
                self.assertEqual(len(result.photos), page_size)
                self.assertEqual(result.next_after, photos[-1]['id'])
        for location in (
            {'latitude': 91.0, 'longitude': 16.6},
            {'latitude': 49.2, 'longitude': None},
            {'latitude': float('nan'), 'longitude': 16.6},
        ):
            page = {**good, 'photos': [{'id': 100, 'title': 'Fixture', **location}]}
            with self.subTest(location=location), mock.patch.object(
                session, '_media_read', return_value=(
                    json.dumps(page).encode(), 'application/json', None, False,
                ),
            ), self.assertRaises(linking.LinkingError):
                session.media_page(42, 99)
        body = b'fixture audio'
        track = linking.LinkedTrack(
            'a' * 32, 'Tone', 'audio/wav', len(body), hashlib.sha256(body).hexdigest(),
        )
        settings = {'enabled': True, 'autoplay': False, 'interval_seconds': 5,
                    'transition': 'medium', 'music': True, 'tracks': []}
        with mock.patch.object(session, '_media_read') as read:
            read.return_value = (json.dumps(settings).encode(), 'application/json', None, False)
            self.assertFalse(session.media_presentation(42).track_cache)
            settings['track_cache'] = True
            read.return_value = (json.dumps(settings).encode(), 'application/json', None, False)
            self.assertTrue(session.media_presentation(42).track_cache)
        legacy_playlist = linking.LinkedPresentation(
            True, False, 5, 'medium', True, (track,),
        )
        playlist = linking.LinkedPresentation(
            True, False, 5, 'medium', True, (track,), track_cache=True,
        )
        etag = '"' + track.sha256 + '"'
        with TemporaryDirectory() as directory, mock.patch.object(
            session, 'media_presentation', return_value=legacy_playlist,
        ) as presentation, mock.patch.object(
            linking.GalleryMediaCache, 'for_current_user',
            return_value=linking.GalleryMediaCache(Path(directory), linking.NATIVE_MEDIA_MAX_BYTES),
        ), mock.patch.object(
            session, '_media_read', return_value=(body, track.mime, etag, False),
        ) as read:
            self.assertEqual(body, session.media_track(42, track))
            read.assert_called_once_with(42, 'track', track.track_id, None)
            # The original no-store server never permits disk reuse.
            read.return_value = (b'different abc', track.mime, None, False)
            with self.assertRaises(linking.LinkingError):
                session.media_track(42, track)
            presentation.return_value = playlist
            read.return_value = (body, track.mime, etag, True)
            self.assertEqual(body, session.media_track(42, track))
            read.reset_mock()
            presentation.reset_mock()
            self.assertEqual(body, session.media_track(42, track))
            presentation.assert_called_once_with(42, None)
            read.assert_not_called()
            # The same issuer can downgrade and still offer the same playlist.
            presentation.return_value = legacy_playlist
            read.return_value = (body, track.mime, etag, False)
            with mock.patch.object(linking.GalleryMediaCache, 'read') as disk_read:
                self.assertEqual(body, session.media_track(42, track))
                disk_read.assert_not_called()
            read.assert_called_once_with(42, 'track', track.track_id, None)
            read.reset_mock()
            presentation.side_effect = linking.LinkingMediaUnavailable('Playlist refused.')
            with self.assertRaises(linking.LinkingMediaUnavailable):
                session.media_track(42, track)
            read.assert_not_called()


@unittest.skipIf(LinkingManager is None, 'Tkinter is unavailable')
class LinkingDeliveryTests(unittest.TestCase):
    """Exercise worker-event delivery without opening a Tk window."""

    def test_photos_create_diagnostic_keeps_safe_http_and_source_facts(self) -> None:
        """Retain the useful response cause without saved request contents.

        Returns:
            None: The diagnostic contains the owned journal site and validated
                HTTP fields, while omitting the key, title, URL and path.
        """
        with TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=linking.LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = linking.LinkedIdentity(7, 'admin')
            session.gallery_create_request.return_value = None
            issuer = 'https://gallery.example/photos'
            with self.assertRaises(linking.LinkingError):
                journal.create(session, issuer, 7, 'Private create title', None)

            response_error = linking.LinkingResponseError(
                'media', 'app_linking_gallery_create', 504, 'html',
                'http_status',
            )
            response_error.args = (
                'secret token=value body=private path=C:\\private\\file',
            )
            session.gallery_create_request.side_effect = response_error
            manager = object.__new__(LinkingManager)
            manager._diagnostic_callback = None
            manager._diagnostics_snapshot = ''
            manager._latest_diagnostic = ''
            manager._last_failure_diagnostic = ''
            manager._last_failure_utc = ''
            try:
                journal.reconcile(session, issuer, 7)
            except linking.LinkingResponseError as error:
                manager.record_photos_creation_failure('status', error)
            else:
                self.fail('The synthetic gallery status error was not raised.')
            summary = manager.diagnostics_snapshot()
            self.assertIn('operation=status', summary)
            self.assertIn('site=linked_create.py:reconcile:', summary)
            self.assertIn('http=504', summary)
            self.assertIn('response_kind=html', summary)
            self.assertIn('reason=http_status', summary)
            for secret in (
                'secret token=value', 'Private create title',
                'gallery.example', 'C:\\private\\file',
            ):
                self.assertNotIn(secret, summary)

    def test_linking_diagnostic_survives_ui_delivery_without_raw_details(self) -> None:
        """Retain safe HTTP facts and fixed unavailable status for the host.

        Returns:
            None: UI status, callback and getter receive only bounded summaries,
                and the latest linking diagnostic survives a later event.
        """
        owner = object()
        callback = mock.Mock()
        manager = object.__new__(LinkingManager)
        manager._events = queue.Queue()
        manager._owner = owner
        manager._direct_owner = owner
        manager._window = None
        manager._worker = None
        manager._session = None
        manager._parked_links = ()
        manager._handoff = None
        manager._attempt = None
        manager._polling = False
        manager._shutting_down = False
        manager._gallery_search_waiting = False
        manager._refresh_address_prompt = None
        manager._status = mock.Mock()
        manager._diagnostic_callback = callback
        manager._diagnostics_snapshot = ''
        error = linking.LinkingResponseError(
            'discovery', 'app_linking_discovery', 401, 'html', 'http_status',
        )
        manager._events.put((owner, 'response_error', error))

        manager._poll()

        expected = (
            'stage=discovery; route=app_linking_discovery; http=401; '
            'response_kind=html; reason=http_status'
        )
        snapshot = manager.diagnostics_snapshot()
        self.assertIn(f'latest={expected}', snapshot)
        self.assertIn(f'last_failure={expected}', snapshot)
        self.assertRegex(
            snapshot,
            r'last_failure_utc=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$',
        )
        callback.assert_called_once_with(expected)
        visible = manager._status.set.call_args.args[0]
        self.assertIn('HTTP 401', visible)
        self.assertNotIn('route=', visible)
        self.assertNotIn('body', manager.diagnostics_snapshot())

        manager._events.put((owner, 'unavailable', None))
        manager._poll()
        self.assertIn(
            'availability=unknown; http=none; response_kind=other; '
            'reason=request_unavailable',
            manager.diagnostics_snapshot(),
        )
        unavailable_error = linking.LinkingUnavailable(
            'The selected gallery does not offer browser linking.',
            availability='false', status=200, content_kind='json',
            reason='server_unavailable',
        )
        manager._events.put((owner, 'unavailable', unavailable_error))
        manager._poll()
        unavailable = (
            'stage=discovery; route=app_linking_discovery; '
            'availability=false; http=200; response_kind=json; '
            'reason=server_unavailable'
        )
        snapshot = manager.diagnostics_snapshot()
        self.assertIn(f'latest={unavailable}', snapshot)
        self.assertIn(f'last_failure={unavailable}', snapshot)
        manager._record_diagnostic(
            'stage=linking; route=app_linking_identity; '
            'availability=true; reason=connected',
            failure=False,
        )
        snapshot = manager.diagnostics_snapshot()
        self.assertIn('latest=stage=linking;', snapshot)
        self.assertIn(f'last_failure={unavailable}', snapshot)
        self.assertEqual(4, callback.call_count)

    def test_photos_gallery_editor_uses_current_site_and_browser_session(self) -> None:
        """Open only a bounded listed gallery on the live Photos site.

        Returns:
            None: The fixed editor route preserves a local subdirectory issuer,
                rejects stale sessions and invalid IDs, and exposes no token.
        """
        for issuer in (
            'http://localhost:8888/Galerie',
            'https://gallery.example/photos',
        ):
            with self.subTest(issuer=issuer):
                session = mock.Mock(spec=linking.LinkingSession)
                session.available.return_value = True
                session.can_list_galleries.return_value = True
                session.matches_issuer.return_value = True
                manager = object.__new__(LinkingManager)
                manager._shutting_down = False
                manager._session = session
                manager._session_issuer = issuer
                manager._current_issuer = issuer
                manager._session_user_id = 7
                gallery = linking.LinkedGallery(42, None, 'Listed gallery')
                with mock.patch(
                    'uploader.app_linking_ui.webbrowser.open',
                    return_value=True,
                ) as open_browser:
                    self.assertTrue(
                        manager.open_photos_gallery_editor(session, gallery),
                    )
                open_browser.assert_called_once_with(
                    issuer + '/index.php?page=admin_edit_gallery'
                    '&id=42&open_panel=1',
                )

        for gallery_id in (0, True, linking.LINKING_GALLERY_ID_MAX + 1):
            with self.subTest(gallery_id=gallery_id):
                session = mock.Mock(spec=linking.LinkingSession)
                session.available.return_value = True
                session.can_list_galleries.return_value = True
                session.matches_issuer.return_value = True
                manager = object.__new__(LinkingManager)
                manager._shutting_down = False
                manager._session = session
                manager._session_issuer = 'http://localhost/Galerie'
                manager._current_issuer = manager._session_issuer
                manager._session_user_id = 7
                with mock.patch(
                    'uploader.app_linking_ui.webbrowser.open',
                ) as open_browser:
                    self.assertFalse(manager.open_photos_gallery_editor(
                        session,
                        linking.LinkedGallery(gallery_id, None, 'Invalid'),
                    ))
                open_browser.assert_not_called()

        session = mock.Mock(spec=linking.LinkingSession)
        session.available.return_value = True
        session.can_list_galleries.return_value = True
        session.matches_issuer.return_value = True
        manager = object.__new__(LinkingManager)
        manager._shutting_down = False
        manager._session = mock.Mock(spec=linking.LinkingSession)
        manager._session_issuer = 'http://localhost/Galerie'
        manager._current_issuer = manager._session_issuer
        manager._session_user_id = 7
        with mock.patch(
            'uploader.app_linking_ui.webbrowser.open',
        ) as open_browser:
            self.assertFalse(manager.open_photos_gallery_editor(
                session, linking.LinkedGallery(42, None, 'Stale'),
            ))
            open_browser.assert_not_called()

    def test_photos_gallery_editor_reports_browser_failure(self) -> None:
        """Treat an unavailable browser as a safe refusal.

        Returns:
            None: Both a false browser result and an exception remain a simple
                false result without exposing URL or exception details.
        """
        session = mock.Mock(spec=linking.LinkingSession)
        session.available.return_value = True
        session.can_list_galleries.return_value = True
        session.matches_issuer.return_value = True
        manager = object.__new__(LinkingManager)
        manager._shutting_down = False
        manager._session = session
        manager._session_issuer = 'http://localhost/Galerie'
        manager._current_issuer = manager._session_issuer
        manager._session_user_id = 7
        gallery = linking.LinkedGallery(42, None, 'Listed gallery')
        with mock.patch(
            'uploader.app_linking_ui.webbrowser.open', return_value=False,
        ):
            self.assertFalse(manager.open_photos_gallery_editor(session, gallery))
        with mock.patch(
            'uploader.app_linking_ui.webbrowser.open',
            side_effect=OSError('private browser error'),
        ):
            self.assertFalse(manager.open_photos_gallery_editor(session, gallery))

    def test_address_dialog_accepts_copied_addresses(self) -> None:
        """Exercise cancellation, correction and submission from the prompt.

        Returns:
            None: Valid bare addresses infer HTTPS and start with their exact
                canonical issuer; ambiguous inputs are refused without an attempt.
        """
        directory = TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        known = 'https://previous.example/galerie'
        for action, value, issuer in (
            ('Cancel', 'https://gallery.example/Galerie/', ''),
            ('Link gallery', 'http://gallery.example/Galerie/',
             'https://gallery.example/Galerie'),
            ('Link gallery', 'gallery.example/Galerie/',
             'https://gallery.example/Galerie'),
            ('Link gallery', 'gallery.example',
             'https://gallery.example'),
            ('Link gallery', '  gallery.example  ',
             'https://gallery.example'),
            ('Link gallery', 'localhost/Galerie',
             'https://localhost/Galerie'),
            ('Link gallery', 'gallery.example:8443/Galerie/sub/',
             'https://gallery.example:8443/Galerie/sub'),
            ('Link gallery', 'https://gallery.example/Galerie/',
             'https://gallery.example/Galerie'),
            ('Link gallery', 'http://localhost:8888/galerie/',
             'http://localhost:8888/galerie'),
            ('Link gallery', 'relative/gallery', ''),
            ('Link gallery', '//gallery.example/Galerie', ''),
            ('Link gallery', 'gallery.example/Bad Gallery', ''),
            ('Link gallery', 'ftp://gallery.example/Galerie', ''),
            ('Link gallery', 'gallery.example:99999/Galerie', ''),
            ('Link gallery', 'user@gallery.example/Galerie', ''),
        ):
            with self.subTest(action=action, value=value):
                manager = object.__new__(LinkingManager)
                manager._root = mock.Mock()
                manager._worker = None
                manager._window = None
                manager._session = None
                manager._session_issuer = ''
                manager._current_issuer = known
                manager._offline_store = None
                manager._offline_binding = None
                manager._offline_path = Path(directory.name) / 'offline-link.json'
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
                    mock.patch('uploader.app_linking_ui.sys') as platform,
                ):
                    platform.platform = 'linux'
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
                        if issuer:
                            hint.configure.assert_called_with(
                                textvariable=manager._status,
                                style='Status.TLabel',
                            )
                            manager._start.assert_called_once_with(
                                issuer, direct=True,
                            )
                            self.assertEqual(issuer, manager.address())
                            if '://' not in value:
                                field.set.assert_called_with(issuer)
                        else:
                            hint.configure.assert_called_with(
                                textvariable='',
                                text='Enter a valid gallery address.',
                                style='Status.TLabel',
                            )
                            manager._start.assert_not_called()
                            self.assertEqual(known, manager.address())
                    else:
                        dialog.destroy.assert_called_once_with()
                        manager._start.assert_not_called()
                        self.assertEqual(known, manager.address())

    def test_refused_saved_rolling_link_only_reapproves_on_explicit_action(self) -> None:
        """Handle a definite refusal differently on startup and explicit Link.

        Returns:
            None: The old hint is dropped and startup never opens approval.
        """
        issuer = 'https://gallery.example/Galerie'
        binding = offline_linking.OfflineBinding(
            issuer, 7, linking.ROLLING_GALLERIES_SCOPE,
        )
        for explicit in (False, True):
            with (
                self.subTest(explicit=explicit),
                mock.patch('uploader.app_linking_ui.tk.StringVar',
                           side_effect=lambda *_a, **_kw: mock.Mock()),
                mock.patch('uploader.app_linking_ui.load_recent_gallery_addresses',
                           return_value=()),
                mock.patch('uploader.app_linking_ui.threading.Thread') as thread,
                mock.patch('uploader.app_linking_ui.discover_linking_capabilities',
                           return_value=frozenset({'rolling_access'})),
                mock.patch('uploader.app_linking_ui.rotate_saved_offline_access',
                           side_effect=linking.LinkingRefreshRejected('Refused.')) as rotate,
            ):
                thread.return_value.is_alive.return_value = False
                manager = LinkingManager(mock.Mock())
                manager._saved_offline_binding = mock.Mock(return_value=binding)
                store = mock.Mock()
                manager._native_offline_store = mock.Mock(return_value=store)
                manager._start = mock.Mock()
                self.assertTrue(manager.resume_saved_link(
                    issuer, approve_if_absent=explicit,
                ))
                thread.call_args.kwargs['target']()
                manager._poll()
                rotate.assert_called_once_with(
                    store, manager._offline_binding_path(binding), binding,
                    manager._cancel,
                )
                self.assertIsNone(manager._offline_binding)
                self.assertIsNone(manager._session)
                if explicit:
                    manager._start.assert_called_once_with(issuer, direct=True)
                else:
                    manager._start.assert_not_called()
                    manager.status.set.assert_called_with(
                        'Saved gallery access expired. Approve the link again.',
                    )

    def test_transient_restore_propagates_optional_batch_discovery(self) -> None:
        """Restore a transient grant with the freshly discovered capability.

        Returns:
            None: Present and absent public markers produce true and false flags.
        """
        if LinkingManager is None:
            self.skipTest('Tk support is unavailable.')
        issuer = 'https://gallery.example/Galerie'
        grant = session_resume.TransientGrant(
            issuer, 7, linking.GALLERIES_SCOPE,
            time.time() + TEST_RESTORE_CREDENTIAL_LIFETIME_SECONDS,
            'pgla_' + 'A' * 43,
        )
        for capabilities, expected in (
            (frozenset({
                'rolling_access', linking.THUMBNAIL_BATCH_CAPABILITY,
            }), True),
            (frozenset({'rolling_access'}), False),
        ):
            with (
                self.subTest(expected=expected),
                mock.patch('uploader.app_linking_ui.tk.StringVar',
                           side_effect=lambda *_a, **_kw: mock.Mock()),
                mock.patch('uploader.app_linking_ui.load_recent_gallery_addresses',
                           return_value=()),
                mock.patch(
                    'uploader.app_linking_ui.sys',
                    SimpleNamespace(platform='darwin'),
                ),
                mock.patch('uploader.app_linking_ui.load_transient',
                           return_value=grant),
                mock.patch('uploader.app_linking_ui.discover_linking_capabilities',
                           return_value=capabilities),
                mock.patch('uploader.app_linking_ui.LinkingSession') as factory,
                mock.patch('uploader.app_linking_ui.threading.Thread') as thread,
            ):
                session = mock.Mock()
                session.identity.return_value = linking.LinkedIdentity(7, 'admin')
                factory.return_value = session
                thread.return_value.is_alive.return_value = False
                manager = LinkingManager(mock.Mock())
                manager._saved_offline_binding = mock.Mock(return_value=None)
                manager._worker = None
                manager._refresh = mock.Mock()
                manager._schedule_poll = mock.Mock()
                manager._queue_initial_gallery_page = mock.Mock()
                self.assertTrue(manager.resume_saved_link(issuer))
                thread.call_args.kwargs['target']()
                self.assertEqual(
                    expected,
                    factory.call_args.kwargs['thumbnail_batch_supported'],
                )
                manager._queue_initial_gallery_page.assert_called_once()

    def test_offline_restore_propagates_optional_batch_discovery(self) -> None:
        """Restore an offline binding with its current discovery capability.

        Returns:
            None: Successful restore passes the exact present or absent flag.
        """
        if LinkingManager is None:
            self.skipTest('Tk support is unavailable.')
        issuer = 'https://gallery.example/Galerie'
        binding = offline_linking.OfflineBinding(
            issuer, 7, linking.ROLLING_GALLERIES_SCOPE,
        )
        access = mock.Mock()
        access.issuer = issuer
        access.token = 'pgla_' + 'A' * 43
        access.expires_at = (
            time.monotonic() + TEST_RESTORE_CREDENTIAL_LIFETIME_SECONDS
        )
        access.username = 'admin'
        access.scope = binding.scope
        for capabilities, expected in (
            (frozenset({
                'rolling_access', linking.THUMBNAIL_BATCH_CAPABILITY,
            }), True),
            (frozenset({'rolling_access'}), False),
        ):
            with (
                self.subTest(expected=expected),
                TemporaryDirectory() as offline_directory,
                mock.patch('uploader.app_linking_ui.tk.StringVar',
                           side_effect=lambda *_a, **_kw: mock.Mock()),
                mock.patch('uploader.app_linking_ui.load_recent_gallery_addresses',
                           return_value=()),
                mock.patch('uploader.app_linking_ui.discover_linking_capabilities',
                           return_value=capabilities),
                mock.patch('uploader.app_linking_ui.rotate_saved_offline_access',
                           return_value=access),
                mock.patch('uploader.app_linking_ui.LinkingSession') as factory,
                mock.patch('uploader.app_linking_ui.enable_rolling_renewal'),
                mock.patch('uploader.app_linking_ui.threading.Thread') as thread,
            ):
                session = mock.Mock()
                session.identity.return_value = linking.LinkedIdentity(7, 'admin')
                factory.return_value = session
                thread.return_value.is_alive.return_value = False
                manager = LinkingManager(mock.Mock())
                manager._offline_binding = binding
                manager._offline_store = mock.Mock()
                manager._offline_path = Path(offline_directory) / 'offline-link.json'
                manager._current_issuer = issuer
                manager._worker = None
                manager._refresh = mock.Mock()
                manager._schedule_poll = mock.Mock()
                manager._queue_initial_gallery_page = mock.Mock()
                manager._restore_offline(silent=True)
                thread.call_args.kwargs['target']()
                self.assertEqual(
                    expected,
                    factory.call_args.kwargs['thumbnail_batch_supported'],
                )
                manager._queue_initial_gallery_page.assert_called_once()

    def test_photos_watcher_key_stays_out_of_selected_upload_mode(self) -> None:
        """Use the exact issued key only for the contextual Watcher handoff.

        Returns:
            None: Verifies the selected gallery and revokes an unaccepted key.
        """
        issuer = 'https://gallery.example/Galerie'
        identity = linking.LinkedIdentity(7, 'admin')
        gallery = linking.LinkedGallery(9, None, 'Screenshot destination')
        key = 'synthetic watcher key'
        for accepted, keep_selection in (
            (True, False), (False, False), (True, True), (False, True),
        ):
            with (
                self.subTest(accepted=accepted, keep_selection=keep_selection),
                mock.patch('uploader.app_linking_ui.tk.StringVar'),
                mock.patch('uploader.app_linking_ui.load_recent_gallery_addresses',
                           return_value=()),
                mock.patch('uploader.app_linking_ui.threading.Thread') as thread,
            ):
                thread.return_value.is_alive.return_value = False
                commit = mock.Mock(return_value=True)
                start = mock.Mock(return_value=accepted)
                manager = LinkingManager(
                    mock.Mock(), commit_linked_target=commit,
                    saved_linked_selected=mock.Mock(return_value=True),
                )
                session = mock.Mock()
                session.approved_scope.return_value = linking.ROLLING_UPLOAD_SCOPE
                session.identity.return_value = identity
                session.gallery_page.return_value = linking.LinkedGalleryPage(
                    (gallery,), None,
                )
                session.open_watch_key.return_value = key
                manager._session = session
                manager._session_issuer = manager._current_issuer = issuer
                manager._session_user_id = identity.user_id
                previous = (
                    LinkedUploadTarget(session, issuer, identity.user_id,
                                       gallery.gallery_id, gallery.title)
                    if keep_selection else None
                )
                manager._active_upload_target = previous

                manager.start_photos_watcher(session, gallery, start, mock.Mock())
                thread.call_args.kwargs['target']()
                manager._gallery_search_waiting = True
                manager._window = mock.Mock()
                with mock.patch.object(manager, '_load_galleries') as search:
                    manager._run_gallery_search()
                    search.assert_not_called()
                manager._window = None
                manager._poll()

                session.gallery_page.assert_called_once_with(
                    gallery.gallery_id - 1, manager._cancel,
                )
                session.open_watch_key.assert_called_once_with(
                    gallery.gallery_id, cancelled=manager._cancel,
                )
                contextual = start.call_args.args[0]
                committed = commit.call_args.args[0]
                commit.assert_called_once()
                self.assertIsNone(committed.watch_key)
                self.assertEqual(key, contextual.watch_key)
                self.assertEqual(committed.binding(), contextual.binding())
                self.assertNotIn(key, repr(contextual))
                if accepted:
                    self.assertIs(committed, manager.selected_upload_target())
                    session.close_watch_key.assert_not_called()
                    manager.detach_photos_watcher_target(contextual)
                else:
                    thread.call_args.kwargs['target']()
                    session.close_watch_key.assert_called_once_with(gallery.gallery_id, key)
                self.assertIs(previous, manager.selected_upload_target())
                self.assertTrue(manager.linked_upload_mode_selected())
                self.assertIs(session, manager.photos_session())

    def test_photos_watcher_refusal_is_visible_and_search_waits_for_result(self) -> None:
        """Deliver a contextual refusal and preserve queued worker ownership.

        Returns:
            None: The Watcher receives the guard reason and a queued result
                prevents the background gallery search from taking ownership.
        """
        with (mock.patch('uploader.app_linking_ui.tk.StringVar'),
              mock.patch('uploader.app_linking_ui.load_recent_gallery_addresses',
                         return_value=())):
            manager = LinkingManager(mock.Mock())
            session = mock.Mock()
            gallery = linking.LinkedGallery(9, None, 'Screenshots')
            manager._session = session
            manager._session_issuer = manager._current_issuer = 'https://gallery.example'
            manager._target_change_refusal = mock.Mock(return_value='Finish pending work.')
            manager._commit_linked_target = mock.Mock(return_value=True)
            failure = mock.Mock()
            manager.start_photos_watcher(session, gallery, mock.Mock(), failure)
            failure.assert_called_once_with('Finish pending work.')

            manager._gallery_search_waiting = True
            manager._window = mock.Mock()
            manager._worker = mock.Mock()
            manager._worker.is_alive.return_value = False
            manager._load_galleries = mock.Mock()
            manager._events.put((object(), 'queued', None))
            manager._run_gallery_search()
            manager._load_galleries.assert_not_called()
            self.assertTrue(manager._gallery_search_waiting)
            manager._events.get_nowait()
            manager._run_gallery_search()
            manager._load_galleries.assert_called_once_with(None)
            self.assertFalse(manager._gallery_search_waiting)

    def test_admin_photos_approval_replaces_existing_rolling_binding(self) -> None:
        """Activate an approved Photos scope over a saved public rolling link.

        Returns:
            None: Verified upload mode and rolling scope agree on success.
                Busy work or unsuccessful publication preserves old authority.
        """
        identity = linking.LinkedIdentity(7, 'admin')
        for scenario, outcome in (
            ('photos', 'saved'), ('photos', 'vault_failed'),
            ('watcher', 'saved'), ('watcher', 'vault_failed'),
            ('watcher', 'mode_failed'), ('watcher', 'busy'),
            ('legacy', 'saved'), ('legacy', 'mode_failed'),
        ):
            with (
                self.subTest(scenario=scenario, outcome=outcome),
                TemporaryDirectory() as directory,
                mock.patch('uploader.app_linking_ui.tk.StringVar'),
                mock.patch('uploader.app_linking_ui.load_recent_gallery_addresses',
                           return_value=()),
                mock.patch('uploader.app_linking_ui.threading.Thread') as thread,
                mock.patch('uploader.app_linking_ui.LinkingAttempt') as attempt_factory,
                mock.patch('uploader.app_linking_ui.store_approved_refresh') as persist,
            ):
                legacy = scenario == 'legacy'
                selected = scenario != 'photos'
                issuer = ('https://gallery.example/Galerie' if legacy
                          else 'http://localhost:8888/Galerie')
                old_scope = (
                    linking.UPLOAD_OFFLINE_SCOPE if legacy
                    else linking.ROLLING_UPLOAD_SCOPE if selected
                    else linking.ROLLING_GALLERIES_SCOPE
                )
                read_scope = linking.READ_UPLOAD_SCOPE if selected else linking.READ_SCOPE
                new_scope = (
                    read_scope + ' galleries:create gallery:visibility'
                    + linking.ADMIN_MEDIA_SCOPE_SUFFIX + linking.ROLLING_SCOPE_SUFFIX
                )
                old_binding = offline_linking.OfflineBinding(
                    issuer, identity.user_id, old_scope,
                )
                new_binding = offline_linking.OfflineBinding(
                    issuer, identity.user_id, new_scope,
                )
                saved = outcome == 'saved'
                thread.return_value.is_alive.return_value = False
                commit = mock.Mock(return_value=outcome != 'mode_failed')
                refusal = mock.Mock(
                    side_effect=[None, 'Watcher is active.'] if outcome == 'busy'
                    else None,
                    return_value=None,
                )
                manager = LinkingManager(
                    mock.Mock(), target_change_refusal=refusal,
                    commit_linked_target=commit,
                )
                manager._offline_path = Path(directory) / 'offline-link.json'
                manager._recent_link_path = None
                manager._open_browser = mock.Mock()
                store = mock.MagicMock()
                original = b'synthetic old vault record'
                published = b'synthetic new vault record'
                store.read.side_effect = [original, published, published]
                manager._native_offline_store = mock.Mock(return_value=store)
                old = mock.Mock(spec=linking.LinkingSession)
                old.approved_scope.return_value = old_scope
                old.can_read_admin_media.return_value = False
                old.can_upload.return_value = selected
                manager._session = old
                manager._session_issuer = manager._current_issuer = issuer
                manager._session_user_id = identity.user_id
                if selected:
                    manager._active_upload_target = LinkedUploadTarget(
                        old, issuer, identity.user_id, 9, 'Selected upload gallery',
                    )
                path = manager._offline_binding_path(old_binding)
                new_path = manager._offline_binding_path(new_binding)
                offline_linking.save_offline_binding(path, old_binding)
                new = mock.Mock(spec=linking.LinkingSession)
                new.approved_scope.return_value = new_scope
                new.identity.return_value = identity
                new.gallery_page.return_value = linking.LinkedGalleryPage((
                    linking.LinkedGallery(9, None, 'Selected upload gallery'),
                ), None)
                attempt = attempt_factory.return_value
                attempt.wait_for_callback.return_value = 'approved'
                attempt.requests_offline_access.return_value = True
                attempt.exchange_offline_once.return_value = (
                    new, 'synthetic refresh', offline_linking.OFFLINE_REFRESH_MAX_SECONDS,
                )
                if outcome == 'vault_failed':
                    persist.side_effect = offline_linking.OfflineLinkingError('Vault failure.')

                manager.approve_admin_photos()
                thread.call_args.kwargs['target']()
                manager._poll()

                expected_binding = new_binding if saved else old_binding
                expected_path = new_path if saved else path
                self.assertEqual(
                    expected_binding,
                    offline_linking.load_offline_binding(expected_path, issuer),
                )
                if legacy:
                    self.assertFalse((path if saved else new_path).exists())
                expected_session = new if saved else old
                self.assertIs(expected_session, manager.photos_session())
                target = manager.selected_upload_target()
                if selected:
                    self.assertIsNotNone(target)
                    self.assertIs(expected_session, target.session)
                    self.assertEqual(9, target.gallery_id)
                    new.gallery_page.assert_called_once_with(8, manager._cancel)
                else:
                    self.assertIsNone(target)
                    commit.assert_not_called()
                if saved:
                    persist.assert_called_once_with(
                        store, issuer, identity.user_id, new_scope,
                        'synthetic refresh', mock.ANY,
                    )
                    if selected:
                        commit.assert_called_once_with(target)
                        self.assertEqual(new_scope, target.session.approved_scope())
                    old.disable_renewal.assert_called_once_with()
                    new.enable_renewal.assert_called_once()
                    manager.approve_admin_photos()
                    attempt_factory.assert_called_once()
                else:
                    old.disable_renewal.assert_not_called()
                    new.close.assert_called_once_with()
                    if outcome == 'busy':
                        persist.assert_not_called()
                        store.write.assert_not_called()
                        store.binding_transaction.assert_not_called()
                    else:
                        store.write.assert_called_once_with(issuer, identity.user_id, original)
                        self.assertEqual(
                            2 if outcome == 'mode_failed' else 1,
                            store.binding_transaction.call_count,
                        )
                    if outcome != 'mode_failed':
                        commit.assert_not_called()

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
        directory = TemporaryDirectory()
        self.addCleanup(directory.cleanup)
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
                manager._offline_path = Path(directory.name) / 'offline-link.json'
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
                attempt.requests_offline_access.return_value = False
                attempt.exchange_once.return_value = session
                thread = mock.Mock()
                thread.is_alive.return_value = False
                with mock.patch(
                    'uploader.app_linking_ui.LinkingAttempt', return_value=attempt,
                ) as factory, mock.patch(
                    'uploader.app_linking_ui.threading.Thread', return_value=thread,
                ) as thread_factory, mock.patch(
                    'uploader.app_linking_ui.webbrowser.open', return_value=True,
                ) as open_browser, mock.patch(
                    'uploader.app_linking_ui.sys',
                ) as platform:
                    platform.platform = 'linux'
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
                    attempt.exchange_once.assert_called_once_with()
                    attempt.exchange_offline_once.assert_not_called()
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
            '_visibility_button', '_create_button',
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

            with mock.patch.object(manager, 'resume_saved_link', return_value=False):
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

            with (
                mock.patch('uploader.app_linking_ui.threading.Thread') as thread,
                mock.patch('uploader.app_linking_ui.delete_transient'),
                mock.patch('uploader.app_linking_ui.load_offline_binding', return_value=None),
            ):
                thread.return_value.is_alive.return_value = False
                manager.unlink(second)
                thread.call_args.kwargs['target']()
                manager._poll()
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
        with (
            mock.patch('uploader.app_linking_ui.threading.Thread') as thread,
            mock.patch('uploader.app_linking_ui.delete_transient'),
            mock.patch('uploader.app_linking_ui.load_offline_binding', return_value=None),
        ):
            thread.return_value.is_alive.return_value = False
            manager.unlink()
            self.assertIs(target, manager.selected_upload_target())
            thread.call_args.kwargs['target']()
            manager._poll()
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
        """Stop the picker page read without interrupting the shared viewer.

        Returns:
            None: Cancels only the picker request and keeps the live link.
        """
        manager = object.__new__(LinkingManager)
        manager._session = mock.Mock()
        manager._cancel = threading.Event()
        manager._create_dispatch = None
        manager._gallery_cancel = threading.Event()
        manager._attempt = None
        manager._handoff = None
        manager._window = None
        manager._owner = object()
        session = manager._session
        manager._close_window()
        self.assertTrue(manager._gallery_cancel.is_set())
        session.cancel_pending.assert_not_called()
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


class PhotosDiagnosticFactsTests(unittest.TestCase):
    """Keep detailed decode and shortcut observations finite and secret-free."""

    def test_decode_refusal_reports_dimensions_and_both_byte_counts(self) -> None:
        """Retain the refused value and its limit without copying error text.

        Returns:
            None: Source dimensions and an over-limit body remain diagnosable.
        """
        # 8000 by 6000 represents the camera class rejected by the old guard.
        facts = PhotosDecodeFacts(
            'pixel-check', 'pixel-limit', 'JPEG',
            (8000, 6000), (8000, 6000), 4096,
            SAFE_PHOTOS_DECODE_PIXEL_MAX, (2048, 1536),
        )
        error = PhotosDecodeError(facts)
        error.args = ('token=fixture-secret C:\\private\\photo.jpg',)
        failure = format_safe_photos_failure('media-decode', 'detail', error)
        self.assertIn('reason=pixel-limit', failure)
        self.assertIn('source=8000x6000', failure)
        self.assertIn('max_pixels=36000000', failure)
        self.assertNotIn('fixture-secret', failure)
        self.assertNotIn('private', failure)
        rejected = dataclass_replace(
            facts, stage='source-open', reason='body-limit',
            body_bytes=SAFE_PHOTOS_DECODE_BODY_MAX_BYTES + 1,
        )
        summary = format_safe_photos_decode_facts(rejected)
        self.assertIsNotNone(summary)
        self.assertIn('body_bytes=16777217 max_bytes=16777216', summary)
        self.assertTrue(is_safe_photos_observation(summary))

    def test_mutated_decoder_fields_cannot_escape_the_safe_formatter(self) -> None:
        """Refuse malformed or mutated facts without replacing the real failure.

        Returns:
            None: Wrong primitive shapes and free text produce no facts output.
        """
        facts = PhotosDecodeFacts(
            'pixel-check', 'pixel-limit', 'PNG', (8000, 6000),
            (8000, 6000), 4096, SAFE_PHOTOS_DECODE_PIXEL_MAX, (2048, 1536),
        )
        for field, value in (
            ('stage', ['token=fixture-secret']),
            ('reason', {'token': 'fixture-secret'}),
            ('image_format', []),
            ('source_size', (True, 6000)),
            ('decoded_size', (float('inf'), 6000)),
            ('body_bytes', 'fixture-secret'),
            ('pixel_limit', SAFE_PHOTOS_DECODE_PIXEL_MAX + 1),
            ('output_size', (2048, -1)),
        ):
            with self.subTest(field=field):
                invalid = dataclass_replace(facts, **{field: value})
                self.assertIsNone(format_safe_photos_decode_facts(invalid))
                summary = format_safe_photos_failure(
                    'media-decode', 'detail', PhotosDecodeError(invalid),
                )
                self.assertIn('error_type=PhotosDecodeError', summary)
                self.assertNotIn('fixture-secret', summary)

    def test_shortcut_observations_never_record_arbitrary_typing(self) -> None:
        """Keep only the explicit F/F11 command and finite event-state facts.

        Returns:
            None: Ordinary keys, malformed states and text payloads are refused.
        """
        # Windows Tk reports enabled NumLock as Mod1, independently of typing.
        summary = format_safe_photos_shortcut(
            'f', 0x0008, 'canvas', True, False, True, 'toggled',
        )
        self.assertIsNotNone(summary)
        self.assertTrue(is_safe_photos_observation(summary))
        self.assertFalse(is_safe_photos_observation(summary + ' token=secret'))
        self.assertIsNone(format_safe_photos_shortcut(
            'password', 0, 'text-entry', True, False, False, 'text-input',
        ))
        self.assertIsNone(format_safe_photos_shortcut(
            [], 0, 'canvas', True, False, True, 'toggled',
        ))
        self.assertIsNone(format_safe_photos_shortcut(
            'F11', True, 'canvas', True, False, True, 'toggled',
        ))


if __name__ == '__main__':
    unittest.main()
