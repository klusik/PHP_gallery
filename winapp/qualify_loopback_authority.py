# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/qualify_loopback_authority.py
# Module Type: Hosted Qualification Fixture
# Purpose: Simulate the public linking protocol on a disposable local authority.
# Responsibilities:
#   - Validate real PKCE pushes, one-use refresh rotation, identity and target reads.
#   - Deliver fixture consent only to the exact installed loopback callback.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Test-only local authority shared by source and installed qualification."""

import base64
import hashlib
import http.client
import http.server
import json
import re
import socketserver
import threading
import time
import urllib.parse
from uuid import uuid4

from uploader import app_linking
from uploader.models import JsonValue

# Purpose: Bound one form body accepted by the disposable protocol authority.
# Type: int. Units: bytes. Scope: one local push or token request.
# Consumers: LoopbackLinkingAuthority.Handler._form. Rationale: Link forms are
# small; the bound prevents an accidental unbounded hosted fixture read.
FIXTURE_FORM_MAX_BYTES = 8192
# Purpose: Keep the synthetic access grant live through identity and target reads.
# Type: int. Units: seconds. Scope: one disposable linked session.
# Consumers: LoopbackLinkingAuthority.Handler.do_POST. Rationale: The hosted
# protocol exchange is immediate and must not rely on a long-lived credential.
FIXTURE_ACCESS_LIFETIME_SECONDS = 600
# Purpose: Bound the synthetic refresh grant without persisting it in this test.
# Type: int. Units: seconds. Scope: one disposable rolling session.
# Consumers: LoopbackLinkingAuthority.Handler.do_POST. Rationale: The session
# contract requires a positive bounded refresh lifetime for offline exchange.
FIXTURE_REFRESH_LIFETIME_SECONDS = 3600
# Purpose: Identify the only synthetic Admin and upload target in this authority.
# Type: int. Units: protocol IDs. Scope: one isolated loopback test authority.
# Consumers: Handler.do_GET and LoopbackLinkedSwitchFixtureTests.
# Rationale: Fixed positive IDs make target preservation assertions deterministic.
FIXTURE_ADMIN_ID = 73
FIXTURE_GALLERY_ID = 41
# Purpose: Bound local callback response reads and authority shutdown joins.
# Type: float. Units: seconds. Scope: one hosted fixture operation.
# Consumers: grant_consent and close. Rationale: local fixture calls should
# terminate quickly when protocol behavior regresses.
FIXTURE_OPERATION_TIMEOUT_SECONDS = 2.0
# Purpose: Bound the local callback response body consumed by the fixture.
# Type: int. Units: bytes. Scope: one synthetic approved callback.
# Consumers: LoopbackLinkingAuthority.grant_consent. Rationale: The callback
# page is fixed small HTML and its body is not used as authority evidence.
FIXTURE_CALLBACK_RESPONSE_MAX_BYTES = 4096
# Purpose: Bound the consent callback wait in the synthetic hosted journey.
# Type: float. Units: seconds. Scope: one approved callback delivery.
# Consumers: LoopbackLinkedSwitchFixtureTests. Rationale: Keep protocol
# regressions finite while allowing loaded hosted runners to schedule the peer.
FIXTURE_CALLBACK_WAIT_SECONDS = 10.0


class _LiteralLoopbackServer(http.server.ThreadingHTTPServer):
    """Bind the fixture's numeric loopback address without reverse DNS."""

    def server_bind(self) -> None:
        """Bind the normal TCP listener and record its literal local endpoint.

        Returns:
            None: The socket keeps the standard bind and address semantics.
                Server metadata uses the numeric address from that bind.
        """
        socketserver.TCPServer.server_bind(self)
        self.server_name, self.server_port = self.server_address[:2]


class LoopbackLinkingAuthority:
    """Serve a disposable identity and upload target on literal localhost."""

    def __init__(self, *, admin_photos_edit: bool = False, mount: str = 'Galerie') -> None:
        """Start the authority on an ephemeral IPv4 loopback port.

        Args:
            admin_photos_edit (bool): Advertise the advanced rolling read-only
                Photos scope with gallery metadata editing when explicitly set.
            mount (str): Exact installation path component in this fixture URL.

        Returns:
            None: Creates a disposable server with one exact consent code.
        """
        self.admin_photos_edit = admin_photos_edit
        self.mount = mount
        self.scope = ''
        self.challenge = ''
        self.redirect_uri = ''
        self.state = ''
        self.code = self._token(app_linking.CODE_PREFIX, 0)
        self.access_token = self._token(app_linking.TOKEN_PREFIX, 32)
        self.refresh_token = self._token('pglr_', 64)
        self.token_exchange_count = 0
        self.refresh_exchange_count = 0
        authority = self

        class Handler(http.server.BaseHTTPRequestHandler):
            """Implement the minimum real protocol used by the linking client."""

            protocol_version = 'HTTP/1.1'

            def _reply(self, body: dict[str, JsonValue], status: int = 200) -> None:
                """Write one bounded JSON response with explicit framing.

                Args:
                    body (dict[str, JsonValue]): Synthetic protocol response.
                    status (int): Expected protocol success status.
                Returns:
                    None: Sends a no-store UTF-8 JSON object.
                """
                payload = json.dumps(body, separators=(',', ':')).encode('utf-8')
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Cache-Control', 'no-store')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def _form(self) -> dict[str, list[str]]:
                """Read one bounded form body for a push or token exchange.

                Returns:
                    dict[str, list[str]]: Parsed URL-encoded form fields.
                """
                size = int(self.headers.get('Content-Length', '0'))
                if size > FIXTURE_FORM_MAX_BYTES:
                    raise ValueError('Fixture request exceeds its bound.')
                return urllib.parse.parse_qs(
                    self.rfile.read(size).decode('ascii'), strict_parsing=True,
                )

            def do_GET(self) -> None:
                """Serve discovery, identity, or the one authorized gallery.

                Returns:
                    None: Writes a protocol response or rejects an unknown route.
                """
                parsed = urllib.parse.urlsplit(self.path)
                if parsed.path != f'/{authority.mount}/index.php':
                    self.send_error(404)
                    return
                route = urllib.parse.parse_qs(parsed.query).get('page', [''])[0]
                if route == 'app_linking_discovery':
                    issuer = authority.issuer
                    capabilities = [
                        'linking', 'galleries:list', 'gallery:upload',
                        'rolling_access',
                    ]
                    if authority.admin_photos_edit:
                        capabilities.extend((
                            'gallery:read', 'gallery:visibility', 'galleries:search',
                            'media:admin', 'presentation:read', 'presentation:rolling',
                            'gallery:edit',
                        ))
                    self._reply({
                        'contract_version': app_linking.LINKING_DISCOVERY_CONTRACT_VERSION,
                        'app_linking': {
                            'available': True,
                            'protocol_version': app_linking.LINKING_PROTOCOL_VERSION,
                            'browser_handoff_version': app_linking.BROWSER_HANDOFF_VERSION,
                            'issuer': issuer,
                            'capabilities': capabilities,
                        },
                    })
                    return
                if self.headers.get('Authorization') != 'Bearer ' + authority.access_token:
                    self.send_error(401)
                    return
                if route == 'app_linking_identity':
                    self._reply({
                        'scope': authority.scope,
                        'user': {
                            'id': FIXTURE_ADMIN_ID,
                            'username': 'fixture-admin',
                        },
                    })
                    return
                if route == 'app_linking_galleries':
                    self._reply({
                        'galleries': [{'id': FIXTURE_GALLERY_ID, 'parent_id': None,
                                       'title': 'Qualification target'}],
                        'next_after': None,
                    })
                    return
                self.send_error(404)

            def do_POST(self) -> None:
                """Validate one pushed PKCE request or consume its code once.

                Returns:
                    None: Sends the versioned handoff or exact token response.
                """
                parsed = urllib.parse.urlsplit(self.path)
                if parsed.path != f'/{authority.mount}/index.php':
                    self.send_error(404)
                    return
                route = urllib.parse.parse_qs(parsed.query).get('page', [''])[0]
                try:
                    fields = self._form()
                except (UnicodeError, ValueError):
                    self.send_error(400)
                    return
                if route == 'app_linking_push':
                    if (set(fields) != {
                            'response_type', 'client_id', 'redirect_uri', 'scope',
                            'state', 'code_challenge', 'code_challenge_method',
                    } or fields['response_type'] != ['code']
                            or fields['code_challenge_method'] != ['S256']):
                        self.send_error(400)
                        return
                    authority.redirect_uri = fields['redirect_uri'][0]
                    authority.scope = fields['scope'][0]
                    authority.state = fields['state'][0]
                    authority.challenge = fields['code_challenge'][0]
                    self._reply({
                        'handoff_version': app_linking.BROWSER_HANDOFF_VERSION,
                        'request_handle': base64.b32encode(
                            b'h' * app_linking.REQUEST_HANDLE_RANDOM_BYTES,
                        ).rstrip(b'=').decode('ascii'),
                        'start_url': authority.issuer
                        + '/index.php?page=app_linking_start&request_handle='
                        + base64.b32encode(
                            b'h' * app_linking.REQUEST_HANDLE_RANDOM_BYTES,
                        ).rstrip(b'=').decode('ascii'),
                        'expires_in': app_linking.HANDOFF_MAX_LIFETIME_SECONDS,
                    }, status=201)
                    return
                if route == 'app_linking_token':
                    grant_type = fields.get('grant_type', [''])[0]
                    if grant_type == 'authorization_code':
                        verifier = fields.get('code_verifier', [''])[0]
                        computed = base64.urlsafe_b64encode(
                            hashlib.sha256(verifier.encode('ascii')).digest(),
                        ).rstrip(b'=').decode('ascii')
                        valid = (
                            authority.token_exchange_count == 0
                            and fields.get('client_id') == [app_linking.CLIENT_ID]
                            and fields.get('code') == [authority.code]
                            and fields.get('redirect_uri') == [authority.redirect_uri]
                            and computed == authority.challenge
                        )
                        if not valid:
                            self.send_error(400)
                            return
                        authority.token_exchange_count += 1
                    elif grant_type == 'refresh_token':
                        valid = (
                            fields.get('client_id') == [app_linking.CLIENT_ID]
                            and fields.get('refresh_token') == [authority.refresh_token]
                        )
                        if not valid:
                            self.send_error(400)
                            return
                        authority.refresh_exchange_count += 1
                        authority.access_token = authority._token(
                            app_linking.TOKEN_PREFIX, authority.refresh_exchange_count + 100,
                        )
                        authority.refresh_token = authority._token(
                            'pglr_', authority.refresh_exchange_count + 150,
                        )
                    else:
                        self.send_error(400)
                        return
                    self._reply({
                        'access_token': authority.access_token,
                        'token_type': 'Bearer',
                        'expires_in': FIXTURE_ACCESS_LIFETIME_SECONDS,
                        'scope': authority.scope,
                        'refresh_token': authority.refresh_token,
                        'refresh_expires_in': FIXTURE_REFRESH_LIFETIME_SECONDS,
                    })
                    return
                self.send_error(404)

            def log_message(self, _format: str, *_args: str | int | float | None) -> None:
                """Suppress fixture request data from hosted logs.

                Args:
                    _format (str): Standard-library request log template.
                    *_args (str | int | float | None): Request-log arguments.
                Returns:
                    None: Emits no request paths, headers, or credential values.
                """
                return

        self.server = _LiteralLoopbackServer(('127.0.0.1', 0), Handler)
        self.server.daemon_threads = True
        self.issuer = f'http://localhost:{self.server.server_port}/{self.mount}'
        self.thread = threading.Thread(
            target=self.server.serve_forever,
            name='LoopbackLinkingAuthority', daemon=True,
        )
        self.thread.start()

    def _token(self, prefix: str, seed: int) -> str:
        """Build one deterministic canonical credential for fixture rotation.

        Args:
            prefix (str): Protocol prefix for access or refresh material.
            seed (int): First byte of a unique 32-byte synthetic value.
        Returns:
            str: Canonical URL-safe test token that never leaves local memory.
        """
        raw = bytes((seed + index) % 256 for index in range(32))
        return prefix + base64.urlsafe_b64encode(raw).rstrip(b'=').decode('ascii')

    def grant_consent(self) -> None:
        """Send the fixture-controlled approved callback to the attempt.

        Returns:
            None: Delivers the exact state, issuer and one-use code locally.
        """
        callback = urllib.parse.urlsplit(self.redirect_uri)
        query = urllib.parse.urlencode({
            'code': self.code, 'state': self.state, 'iss': self.issuer,
        })
        connection = http.client.HTTPConnection(
            callback.hostname, callback.port,
            timeout=FIXTURE_OPERATION_TIMEOUT_SECONDS,
        )
        try:
            connection.request(
                'GET', callback.path + '?' + query,
                headers={'Host': callback.netloc},
            )
            response = connection.getresponse()
            response.read(FIXTURE_CALLBACK_RESPONSE_MAX_BYTES)
            if response.status != 200:
                raise RuntimeError('The fixture callback was not accepted.')
        finally:
            connection.close()

    def close(self) -> None:
        """Stop and release the disposable loopback authority.

        Returns:
            None: Joins its only server thread and closes its listener.
        """
        self.server.shutdown()
        self.thread.join(timeout=FIXTURE_OPERATION_TIMEOUT_SECONDS)
        self.server.server_close()
