# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/attempt.py
# Module Type: Windows Client Transport
# Purpose: Own the one-shot browser authorization attempt.
# Responsibilities:
#   - Keep PKCE state, callback acceptance and token exchange within one lifecycle owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own the one-shot browser authorization attempt."""

from typing import Literal
import base64
import hashlib
import secrets
import socket
import sys
import threading
import time
from urllib.parse import urlencode
from .core import (
    ADMIN_PRELOAD_CAPABILITY,
    ATTEMPT_RANDOM_BYTES,
    BROWSER_HANDOFF_VERSION,
    CALLBACK_ACCEPT_POLL_SECONDS,
    CALLBACK_BACKLOG,
    CALLBACK_INVALID_LIMIT,
    CALLBACK_LIFETIME_SECONDS,
    CALLBACK_PATH,
    CLIENT_ID,
    CREATE_OFFLINE_SCOPE,
    CREATE_SCOPE,
    CREATE_VISIBILITY_OFFLINE_SCOPE,
    CREATE_VISIBILITY_SCOPE,
    ExchangeParameters,
    GALLERIES_OFFLINE_SCOPE,
    GALLERIES_SCOPE,
    GALLERY_SEARCH_SCOPES,
    GALLERY_SEARCH_SUFFIX,
    GALLERY_TREE_CAPABILITY,
    HANDOFF_MAX_LIFETIME_SECONDS,
    IDENTITY_OFFLINE_SCOPE,
    IDENTITY_SCOPE,
    LINKING_DISCOVERY_CONTRACT_VERSION,
    LINKING_MAX_TOKEN_LIFETIME_SECONDS,
    LinkingError,
    LinkingUnavailable,
    OFFLINE_REFRESH_MAX_SECONDS,
    PRESENTATION_SCOPE_SUFFIX,
    PushedAuthorization,
    READ_CREATE_OFFLINE_SCOPE,
    READ_CREATE_SCOPE,
    READ_OFFLINE_SCOPE,
    READ_SCOPE,
    READ_UPLOAD_CREATE_OFFLINE_SCOPE,
    READ_UPLOAD_CREATE_SCOPE,
    READ_UPLOAD_OFFLINE_SCOPE,
    READ_UPLOAD_SCOPE,
    ROLLING_ADMIN_EDIT_SCOPES,
    ROLLING_ADMIN_MEDIA_SCOPES,
    ROLLING_GALLERIES_SCOPE,
    ROLLING_GALLERY_SEARCH_SCOPES,
    ROLLING_PRESENTATION_SCOPES,
    ROLLING_SCOPE_SUFFIX,
    ROLLING_UPLOAD_SCOPE,
    SLIDING_ACCESS_CAPABILITY,
    THUMBNAIL_BATCH_CAPABILITY,
    TOKEN_PREFIX,
    UPLOAD_CREATE_OFFLINE_SCOPE,
    UPLOAD_CREATE_SCOPE,
    UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
    UPLOAD_CREATE_VISIBILITY_SCOPE,
    UPLOAD_OFFLINE_SCOPE,
    UPLOAD_SCOPE,
    UPLOAD_VISIBILITY_OFFLINE_SCOPE,
    UPLOAD_VISIBILITY_SCOPE,
    VISIBILITY_OFFLINE_SCOPE,
    VISIBILITY_SCOPE,
    _LinkingAvailability,
    _LinkingDiagnosticContent,
    _callback_values,
    _canonical_256bit,
    _canonical_request_handle,
    _issuer_valid,
    _read_request,
    _reply,
    _scope_without_media,
    _valid_token,
    scope_base,
)
from .session import (
    LinkingSession,
)
from .transport import (
    _request_json,
)
from .discovery import (
    _discovery_admits_linking,
    _preferred_admin_media_scope,
)



class LinkingAttempt:
    """Own a single issuer-bound PKCE attempt and local callback listener."""

    def __init__(
        self, issuer: str, family: int = socket.AF_INET,
        scope: str = IDENTITY_SCOPE,
    ) -> None:
        """Bind a private ephemeral listener before constructing browser data.

        Args:
            issuer (str): Independently trusted exact installation origin and mount.
            family (int): socket.AF_INET or socket.AF_INET6 loopback family.
            scope (str): Exact explicitly requested transient or offline scope bundle.

        Returns:
            None: Creates an attempt owning its listener and in-memory secrets.

        Raises:
            LinkingError: Issuer, family or exclusive loopback bind is invalid.
        """
        if (not _issuer_valid(issuer, allow_local_http=True)
                or family not in (socket.AF_INET, socket.AF_INET6)
                or scope_base(scope) is None):
            raise LinkingError('Invalid trusted issuer or callback family.')
        self._lock = threading.Lock()
        self._cancelled = threading.Event()
        self._issuer = issuer
        self._scope = scope
        self._admin_preload_supported = False
        self._gallery_tree_supported = False
        self._thumbnail_batch_supported = False
        self._sliding_access_supported = False
        self._state = secrets.token_urlsafe(ATTEMPT_RANDOM_BYTES)
        self._verifier = secrets.token_urlsafe(ATTEMPT_RANDOM_BYTES)
        self._code = ''
        self._phase = 'ready'
        self._url_created = False
        self._deadline = time.monotonic() + CALLBACK_LIFETIME_SECONDS
        self._host = '127.0.0.1' if family == socket.AF_INET else '::1'
        listener = None
        try:
            listener = socket.socket(family, socket.SOCK_STREAM)
            if sys.platform == 'win32':
                if not hasattr(socket, 'SO_EXCLUSIVEADDRUSE'):
                    raise OSError('Exclusive listener support unavailable.')
                listener.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
            if family == socket.AF_INET6:
                listener.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
            listener.bind((self._host, 0))
            listener.listen(CALLBACK_BACKLOG)
            port = listener.getsockname()[1]
            self._listener = listener
            callback_host = self._host if family == socket.AF_INET else '[::1]'
            self._host_header = f'{callback_host}:{port}'
            self._redirect_uri = f'http://{self._host_header}{CALLBACK_PATH}'
        except OSError:
            if listener is not None:
                listener.close()
            self._state = ''
            self._verifier = ''
            raise LinkingError('Cannot open an exclusive loopback callback.') from None

    def push_request(
        self, *, prefer_upload: bool = False, prefer_rolling: bool = False,
        prefer_presentation: bool = False, prefer_admin_media: bool = False,
        require_admin_media: bool = False,
    ) -> PushedAuthorization:
        """Verify public support, then request one explicitly approved handoff.

        Args:
            prefer_upload (bool): Include temporary upload access in a basic
                listing request when this installation advertises it. The
                administrator sees the exact scope before approving.
                Basic links also request search when advertised.
            prefer_rolling (bool): Request renewal for a basic link when advertised.
            prefer_presentation (bool): Request explicit playback consent on a
                supported normal link. A temporary link retains search if offered.
            prefer_admin_media (bool): Request restricted Photos and the
                available gallery management capabilities in browser consent.
            require_admin_media (bool): Refuse a fallback to a legacy grant
                when explicitly upgrading an existing public Photos link.

        Returns:
            PushedAuthorization: Non-authorizing request handle, exact browser
                approval URL and conservative monotonic expiration deadline.

        Raises:
            LinkingError: An invalid, failed, expired, or cancelled request;
                uncertain or failed pushes must not be retried on this attempt.
        """
        with self._lock:
            if (self._phase != 'ready' or self._cancelled.is_set()
                    or self._url_created or time.monotonic() >= self._deadline):
                raise LinkingError('Linking attempt is no longer ready.')
            self._phase = 'pushing'
        try:
            if self._cancelled.is_set():
                raise LinkingError('Linking attempt was cancelled.')
            discovery = _request_json(
                self._issuer, 'app_linking_discovery', 'GET', None, None,
                cancelled=self._cancelled,
            )
            linking_data = discovery.get('app_linking')
            discovery_available = (
                linking_data.get('available')
                if isinstance(linking_data, dict) else None
            )
            contract_valid = (
                type(discovery.get('contract_version')) is int
                and discovery.get('contract_version')
                == LINKING_DISCOVERY_CONTRACT_VERSION
            )
            if contract_valid and discovery_available is False:
                raise LinkingUnavailable(
                    'The selected gallery does not offer browser linking.',
                    availability='false', status=200, content_kind='json',
                    reason='server_unavailable',
                )
            availability_diagnostic: _LinkingAvailability = (
                'true' if contract_valid and discovery_available is True
                else 'unknown'
            )
            diagnostic_status = 200 if contract_valid else None
            diagnostic_content: _LinkingDiagnosticContent = (
                'json' if contract_valid else 'other'
            )
            capabilities = (
                linking_data.get('capabilities', [])
                if isinstance(linking_data, dict) else []
            )
            self._gallery_tree_supported = (
                isinstance(capabilities, list)
                and GALLERY_TREE_CAPABILITY in capabilities
            )
            self._admin_preload_supported = (
                isinstance(capabilities, list)
                and ADMIN_PRELOAD_CAPABILITY in capabilities
            )
            self._sliding_access_supported = (
                isinstance(capabilities, list)
                and SLIDING_ACCESS_CAPABILITY in capabilities
            )
            self._thumbnail_batch_supported = (
                isinstance(capabilities, list)
                and THUMBNAIL_BATCH_CAPABILITY in capabilities
            )
            with self._lock:
                if self._cancelled.is_set() or time.monotonic() >= self._deadline:
                    raise LinkingError('Linking attempt is no longer ready.')
                if (prefer_upload and self._scope == GALLERIES_SCOPE
                        and _discovery_admits_linking(discovery, self._issuer, UPLOAD_SCOPE)):
                    self._scope = UPLOAD_SCOPE
                admin_scope = _preferred_admin_media_scope(
                    discovery, self._issuer, self._scope,
                    prefer_rolling, prefer_presentation,
                ) if prefer_admin_media or require_admin_media else None
                if require_admin_media and admin_scope is None:
                    raise LinkingUnavailable(
                        'Admin Photos are unavailable for this gallery.',
                        availability=availability_diagnostic,
                        status=diagnostic_status,
                        content_kind=diagnostic_content,
                        reason=(
                            'scope_unavailable'
                            if availability_diagnostic == 'true'
                            else 'request_unavailable'
                        ),
                    )
                search_scope = self._scope + GALLERY_SEARCH_SUFFIX
                if (admin_scope is None and search_scope in GALLERY_SEARCH_SCOPES
                        and _discovery_admits_linking(discovery, self._issuer, search_scope)):
                    self._scope = search_scope
                rolling_scope = self._scope + ROLLING_SCOPE_SUFFIX
                presentation_scope = self._scope + PRESENTATION_SCOPE_SUFFIX
                rolling_presentation_scope = presentation_scope + ROLLING_SCOPE_SUFFIX
                if admin_scope is not None:
                    self._scope = admin_scope
                elif (prefer_presentation and prefer_rolling
                        and rolling_presentation_scope in ROLLING_PRESENTATION_SCOPES
                        and _discovery_admits_linking(
                            discovery, self._issuer, rolling_presentation_scope,
                        )):
                    self._scope = rolling_presentation_scope
                elif (prefer_rolling and rolling_scope in (
                        ROLLING_GALLERIES_SCOPE, ROLLING_UPLOAD_SCOPE,
                        *ROLLING_GALLERY_SEARCH_SCOPES,
                ) and _discovery_admits_linking(discovery, self._issuer, rolling_scope)):
                    self._scope = rolling_scope
                elif (prefer_presentation and self._scope in (GALLERIES_SCOPE, UPLOAD_SCOPE)
                      and _discovery_admits_linking(
                          discovery, self._issuer, rolling_presentation_scope,
                      )):
                    self._scope = presentation_scope
                if not _discovery_admits_linking(discovery, self._issuer, self._scope):
                    raise LinkingUnavailable(
                        'Gallery does not support this linking request.',
                        availability=availability_diagnostic,
                        status=diagnostic_status,
                        content_kind=diagnostic_content,
                        reason=(
                            'scope_unavailable'
                            if availability_diagnostic == 'true'
                            else 'request_unavailable'
                        ),
                    )
                challenge = base64.urlsafe_b64encode(
                    hashlib.sha256(self._verifier.encode('ascii')).digest(),
                ).rstrip(b'=').decode('ascii')
                body = urlencode({
                    'response_type': 'code', 'client_id': CLIENT_ID,
                    'redirect_uri': self._redirect_uri, 'scope': self._scope,
                    'state': self._state, 'code_challenge': challenge,
                    'code_challenge_method': 'S256',
                }).encode('ascii')
            started = time.monotonic()
            data = _request_json(
                self._issuer, 'app_linking_push', 'POST', body, None,
                cancelled=self._cancelled,
            )
            request_handle = data.get('request_handle')
            authorization_url = data.get('start_url')
            lifetime = data.get('expires_in')
            expected_url = (
                self._issuer + '/index.php?page=app_linking_start'
                + '&request_handle=' + request_handle
                if isinstance(request_handle, str) else ''
            )
            if (type(data.get('handoff_version')) is not int
                    or data['handoff_version'] != BROWSER_HANDOFF_VERSION
                    or not isinstance(request_handle, str)
                    or not _canonical_request_handle(request_handle)
                    or authorization_url != expected_url
                    or type(lifetime) is not int
                    or lifetime <= 0 or lifetime > HANDOFF_MAX_LIFETIME_SECONDS):
                raise LinkingError('Linking response was not accepted.')
            expires_at = min(started + lifetime, self._deadline)
            with self._lock:
                if (self._cancelled.is_set() or self._phase != 'pushing'
                        or time.monotonic() >= expires_at):
                    raise LinkingError('Linking attempt is no longer ready.')
                self._phase = 'ready'
                self._url_created = True
                return PushedAuthorization(
                    request_handle, authorization_url,
                    expires_at,
                )
        except LinkingError:
            self.close()
            raise

    def wait_for_callback(self) -> Literal['approved', 'denied']:
        """Wait for one valid local callback without executing token exchange.

        Returns:
            Literal['approved', 'denied']: Valid explicit browser decision.

        Raises:
            LinkingError: The attempt expired, was cancelled or could not listen.
        """
        with self._lock:
            if (self._phase != 'ready' or self._cancelled.is_set()
                    or not self._url_created):
                raise LinkingError('Linking attempt is no longer ready.')
            self._phase = 'waiting'
        invalid = 0
        try:
            while not self._cancelled.is_set():
                remaining = self._deadline - time.monotonic()
                if remaining <= 0 or invalid >= CALLBACK_INVALID_LIMIT:
                    break
                try:
                    self._listener.settimeout(
                        min(remaining, CALLBACK_ACCEPT_POLL_SECONDS),
                    )
                    connection, peer = self._listener.accept()
                except socket.timeout:
                    continue
                except OSError:
                    break
                with connection:
                    request = _read_request(connection, self._deadline)
                    result = None
                    if (peer[0] == self._host and request is not None
                            and request[1].get('host') == self._host_header
                            and 'origin' not in request[1]):
                        result = _callback_values(
                            request[0], self._state, self._issuer,
                        )
                    with self._lock:
                        if (self._cancelled.is_set()
                                or time.monotonic() >= self._deadline):
                            result = None
                        elif result is not None:
                            self._phase, self._code = result
                            if self._phase == 'denied':
                                self._state = ''
                                self._verifier = ''
                    _reply(connection, result[0] if result is not None else None,
                           self._deadline)
                    if result is not None:
                        with self._lock:
                            if not self._cancelled.is_set():
                                return result[0]
                        break
                    invalid += 1
            with self._lock:
                if self._phase != 'closed':
                    self._phase = 'failed'
                self._state = ''
                self._verifier = ''
            raise LinkingError('Linking callback expired or was cancelled.')
        finally:
            self._listener.close()

    def take_exchange_parameters(self) -> ExchangeParameters:
        """Consume one approved callback for a single future exchange attempt.

        Returns:
            ExchangeParameters: Issuer, exact redirect, code and verifier;
                credentials remain caller-owned and must not be logged or saved.

        Raises:
            LinkingError: No approved, unconsumed callback is available.
        """
        with self._lock:
            if self._phase != 'approved' or self._cancelled.is_set():
                raise LinkingError('No approved callback is available.')
            result = ExchangeParameters(
                self._issuer, self._redirect_uri, self._code, self._verifier,
                self._scope,
            )
            self._phase = 'consumed'
            self._code = ''
            self._state = ''
            self._verifier = ''
            return result

    def requests_offline_access(self) -> bool:
        """Check the exact scope selected for browser consent.

        Returns:
            bool: True when the negotiated exchange returns a refresh token.
        """
        with self._lock:
            return self._scope.endswith(' offline_access')

    def exchange_once(self) -> 'LinkingSession':
        """Exchange a transient scope once without returning refresh material.

        Returns:
            LinkingSession: Validated transient bearer held in memory.

        Raises:
            LinkingError: The scope or single-use exchange is invalid.
        """
        if _scope_without_media(self._scope) not in (IDENTITY_SCOPE, GALLERIES_SCOPE, UPLOAD_SCOPE,
                               READ_SCOPE, READ_UPLOAD_SCOPE, CREATE_SCOPE,
                               READ_CREATE_SCOPE, UPLOAD_CREATE_SCOPE,
                               READ_UPLOAD_CREATE_SCOPE, VISIBILITY_SCOPE,
                               UPLOAD_VISIBILITY_SCOPE, CREATE_VISIBILITY_SCOPE,
                               UPLOAD_CREATE_VISIBILITY_SCOPE, *GALLERY_SEARCH_SCOPES):
            raise LinkingError('Offline scope requires explicit handling.')
        session, _refresh, _lifetime = self._exchange_approved(False)
        return session

    def exchange_offline_once(self) -> tuple['LinkingSession', str, int]:
        """Exchange an explicitly requested offline scope without retry.

        Returns:
            tuple[LinkingSession, str, int]: In-memory bearer, refresh token and
                current refresh lifetime; caller must verify identity before storage.

        Raises:
            LinkingError: The offline scope or single-use exchange is invalid.
        """
        if self._scope not in (IDENTITY_OFFLINE_SCOPE, GALLERIES_OFFLINE_SCOPE,
                               UPLOAD_OFFLINE_SCOPE, READ_OFFLINE_SCOPE,
                               READ_UPLOAD_OFFLINE_SCOPE, CREATE_OFFLINE_SCOPE,
                               READ_CREATE_OFFLINE_SCOPE, UPLOAD_CREATE_OFFLINE_SCOPE,
                               READ_UPLOAD_CREATE_OFFLINE_SCOPE,
                               VISIBILITY_OFFLINE_SCOPE,
                               UPLOAD_VISIBILITY_OFFLINE_SCOPE,
                               CREATE_VISIBILITY_OFFLINE_SCOPE,
                               UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
                               ROLLING_GALLERIES_SCOPE, ROLLING_UPLOAD_SCOPE,
                               *ROLLING_GALLERY_SEARCH_SCOPES,
                               *ROLLING_PRESENTATION_SCOPES,
                               *ROLLING_ADMIN_MEDIA_SCOPES,
                               *ROLLING_ADMIN_EDIT_SCOPES):
            raise LinkingError('Offline scope was not requested.')
        session, refresh, lifetime = self._exchange_approved(True)
        if refresh is None or lifetime is None:
            session.close()
            raise LinkingError('Offline response was not accepted.')
        return session, refresh, lifetime

    def _exchange_approved(
        self, offline: bool,
    ) -> tuple['LinkingSession', str | None, int | None]:
        """Perform one code exchange with an exact response shape.

        Args:
            offline (bool): Whether explicit offline fields must be present.

        Returns:
            tuple[LinkingSession, str | None, int | None]: Validated bearer and
                optional refresh credential and inactivity-bounded lifetime.

        Raises:
            LinkingError: The exchange, response or cancellation is invalid.
        """
        parameters = self.take_exchange_parameters()
        started = time.monotonic()
        body = urlencode({
            'grant_type': 'authorization_code', 'client_id': CLIENT_ID,
            'code': parameters.code, 'redirect_uri': parameters.redirect_uri,
            'code_verifier': parameters.verifier,
        }).encode('ascii')
        if self._cancelled.is_set():
            raise LinkingError('Linking attempt was cancelled.')
        data = _request_json(
            parameters.issuer, 'app_linking_token', 'POST', body, None,
            cancelled=self._cancelled,
        )
        token = data.get('access_token')
        lifetime = data.get('expires_in')
        refresh = data.get('refresh_token') if offline else None
        refresh_lifetime = data.get('refresh_expires_in') if offline else None
        expected = {'access_token', 'token_type', 'expires_in', 'scope'}
        if offline:
            expected |= {'refresh_token', 'refresh_expires_in'}
        if (set(data) != expected
                or not isinstance(token, str) or not token.startswith(TOKEN_PREFIX)
                or not _canonical_256bit(token[len(TOKEN_PREFIX):])
                or data.get('token_type') != 'Bearer'
                or data.get('scope') != parameters.scope
                or type(lifetime) is not int
                or lifetime <= 0 or lifetime > LINKING_MAX_TOKEN_LIFETIME_SECONDS
                or time.monotonic() >= started + lifetime):
            raise LinkingError('Linking response was not accepted.')
        if offline:
            # The exchange and rotation share the same token format and
            # inactivity limit owned by the linking protocol.
            if (not _valid_token(refresh, 'pglr_')
                    or type(refresh_lifetime) is not int
                    or not 0 < refresh_lifetime <= OFFLINE_REFRESH_MAX_SECONDS
                    or time.monotonic() >= started + refresh_lifetime):
                raise LinkingError('Offline response was not accepted.')
        with self._lock:
            if self._cancelled.is_set() or self._phase != 'consumed':
                raise LinkingError('Linking attempt was cancelled.')
            session = LinkingSession(
                parameters.issuer, token, started + lifetime, parameters.scope,
                admin_preload_supported=self._admin_preload_supported,
                gallery_tree_supported=self._gallery_tree_supported,
                thumbnail_batch_supported=self._thumbnail_batch_supported,
                sliding_access_supported=self._sliding_access_supported,
            )
            return session, refresh, refresh_lifetime

    def close(self) -> None:
        """Cancel this attempt and clear references to transient secrets.

        Returns:
            None: Closes the listener and prevents later callback consumption.
        """
        self._cancelled.set()
        with self._lock:
            self._phase = 'closed'
            self._state = ''
            self._verifier = ''
            self._code = ''
        self._listener.close()
