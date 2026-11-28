# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/transport.py
# Module Type: Windows Client Transport
# Purpose: Implement bounded HTTP and local callback transport.
# Responsibilities:
#   - Keep connection ownership, deadline enforcement and response validation together.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Implement bounded HTTP and local callback transport."""

from typing import Callable
from typing import Iterable
from ..models import JsonValue
from typing import Literal
from ..passive_server_timing import PassiveServerTiming
from urllib.parse import SplitResult
from ..server_discovery import _reject_nonfinite
from ..server_discovery import _unique_object
import http.client
import json
from ..passive_server_timing import parse_server_timing
import queue
import re
import socket
import ssl
import threading
import time
from urllib.parse import urlsplit
from .core import (
    GALLERY_EDIT_RESPONSE_MAX_BYTES,
    LINKING_CANCELLATION_POLL_SECONDS,
    LINKING_GALLERIES_RESPONSE_MAX_BYTES,
    LINKING_GALLERY_ID_MAX,
    LINKING_GALLERY_TREE_RESPONSE_MAX_BYTES,
    LINKING_HTTPS_DEFAULT_PORT,
    LINKING_PICKER_RESPONSE_MAX_BYTES,
    LINKING_REQUEST_DEADLINE_SECONDS,
    LINKING_RESPONSE_MAX_BYTES,
    LINKING_SOCKET_TIMEOUT_SECONDS,
    LINKING_UPLOAD_INACTIVITY_TIMEOUT_SECONDS,
    LINKING_UPLOAD_MAX_DEADLINE_SECONDS,
    LINKING_UPLOAD_MIN_DEADLINE_SECONDS,
    LINKING_UPLOAD_MIN_RATE_BYTES_PER_SECOND,
    LINKING_UPLOAD_RESPONSE_MAX_BYTES,
    LINKING_UPLOAD_SETUP_GRACE_SECONDS,
    LINKING_USER_AGENT,
    LinkingError,
    LinkingGrantRejected,
    LinkingHttpsError,
    LinkingMediaThrottled,
    LinkingMediaUnavailable,
    LinkingRefreshRejected,
    LinkingResponseError,
    LinkingRevisionUnsupported,
    LinkingThumbnailProofChanged,
    MEDIA_CONNECTION_IDLE_SECONDS,
    MediaReadTimings,
    UPLOAD_PRESTORE_REFUSALS,
    _GALLERY_TREE_REVISION_PATTERN,
    _LINKING_TRANSPORT_SLOTS,
    _LINKING_TRANSPORT_STEPS,
    _LinkingDiagnosticContent,
    _LinkingDiagnosticPhase,
    _LinkingDiagnosticRoute,
    _LinkingTransportStep,
    _TransportResult,
    _WATCH_KEY_PATTERN,
    _issuer_valid,
    validated_retry_after,
)



class _LinkingTransportControl:
    """Bound one HTTPS operation and own its interruptible socket."""

    def __init__(
        self, deadline: float, cancelled: threading.Event | None,
        inactivity_timeout: float = LINKING_SOCKET_TIMEOUT_SECONDS,
    ) -> None:
        """Prepare a deadline and optional caller cancellation signal.

        Args:
            deadline (float): Absolute monotonic request deadline.
            cancelled (threading.Event | None): Caller-owned cancellation event.
            inactivity_timeout (float): Maximum inactive socket seconds.

        Returns:
            None: Creates control state without starting network activity.
        """
        self._deadline = deadline
        self._cancelled = cancelled
        self._inactivity_timeout = inactivity_timeout
        self._stopped = threading.Event()
        self._lock = threading.Lock()
        self._socket: socket.socket | None = None
        self.timings: MediaReadTimings | None = None
        self.server_timing_out: list[
            tuple[PassiveServerTiming | None, float | None]
        ] | None = None
        self.server_header_ms: float | None = None
        self.request_started = False
        self._transport_step: _LinkingTransportStep = 'unknown'
        self.operation_finished = threading.Event()

    @property
    def transport_step(self) -> _LinkingTransportStep:
        """Return the current allowlisted network operation step.

        Returns:
            _LinkingTransportStep: Last observed step, or ``unknown`` before
                transport work starts.
        """
        with self._lock:
            return self._transport_step

    def set_transport_step(self, step: _LinkingTransportStep) -> None:
        """Record one finite transport step without retaining request data.

        Args:
            step (_LinkingTransportStep): Allowlisted operation being entered.

        Returns:
            None: Updates only the fixed diagnostic step label.

        Raises:
            ValueError: The requested diagnostic label is not allowlisted.
        """
        if step not in _LINKING_TRANSPORT_STEPS:
            raise ValueError('Invalid linking transport step.')
        with self._lock:
            self._transport_step = step

    def remaining(self) -> float:
        """Require an active request and return its remaining duration.

        Returns:
            float: Positive remaining time in seconds.

        Raises:
            LinkingError: The caller cancelled or the deadline elapsed.
        """
        remaining = self._deadline - time.monotonic()
        if (self._stopped.is_set() or remaining <= 0
                or (self._cancelled is not None and self._cancelled.is_set())):
            raise LinkingError('Linking request expired or was cancelled.')
        return remaining

    def set_socket(self, connection: socket.socket) -> None:
        """Track one socket so cancellation can interrupt its operation.

        Args:
            connection (socket.socket): Newly created TCP or wrapped TLS socket.

        Returns:
            None: Registers the socket only while the request remains active.

        Raises:
            LinkingError: The request already expired or was cancelled.
        """
        with self._lock:
            try:
                self.remaining()
            except LinkingError:
                connection.close()
                raise
            self._socket = connection

    def socket_timeout(self) -> float:
        """Keep socket inactivity and total-request deadlines independent.

        Returns:
            float: Positive socket timeout bounded by the remaining deadline.

        Raises:
            LinkingError: The operation expired or was cancelled.
        """
        return min(self._inactivity_timeout, self.remaining())

    def detach_socket(self, connection: socket.socket) -> bool:
        """Relinquish only this request's current live socket atomically.

        Args:
            connection (socket.socket): Exact socket offered to the idle pool.

        Returns:
            bool: True after ownership transfer; False when cancellation,
                deadline or a different socket prevents safe retention.
        """
        with self._lock:
            try:
                self.remaining()
            except LinkingError:
                return False
            if self._socket is not connection:
                return False
            self._socket = None
            return True

    def stop(self) -> None:
        """Stop future network work and interrupt an active socket best-effort.

        Returns:
            None: Marks this operation stopped and closes its tracked socket.
        """
        self._stopped.set()
        self.finish()

    def finish(self) -> None:
        """Release a completed operation's socket without cancelling its result.

        Returns:
            None: Closes the currently tracked socket, if one remains.
        """
        with self._lock:
            connection, self._socket = self._socket, None
        if connection is not None:
            try:
                connection.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            try:
                connection.close()
            except OSError:
                pass

    def connect(
        self, address: tuple[str, int], source_address: tuple[str, int] | None,
        *, loopback_only: bool = False,
    ) -> socket.socket:
        """Resolve and connect without starting late work after cancellation.

        Args:
            address (tuple[str, int]): Gallery server hostname and TCP port.
            source_address (tuple[str, int] | None): Optional local bind address.
            loopback_only (bool): Accept only the two numeric loopback peers.

        Returns:
            socket.socket: Connected socket tracked by this request.

        Raises:
            LinkingError: DNS returned after cancellation or the deadline.
            OSError: No resolved address could be connected.
        """
        self.remaining()
        self.set_transport_step('dns')
        started = time.perf_counter() if self.timings is not None else 0.0
        try:
            addresses = socket.getaddrinfo(
                address[0], address[1], 0, socket.SOCK_STREAM,
            )
        finally:
            if self.timings is not None:
                self.timings.dns_ms += (time.perf_counter() - started) * 1000.0
        self.remaining()
        last_error: OSError | None = None
        for family, kind, protocol, _canonical_name, target in addresses:
            self.remaining()
            if loopback_only and target[0] not in ('127.0.0.1', '::1'):
                continue
            self.set_transport_step('connect')
            connection = socket.socket(family, kind, protocol)
            try:
                self.set_socket(connection)
                connection.settimeout(self.socket_timeout())
                if source_address is not None:
                    connection.bind(source_address)
                started = time.perf_counter() if self.timings is not None else 0.0
                try:
                    connection.connect(target)
                    if self.timings is not None:
                        self.timings.connections += 1
                finally:
                    if self.timings is not None:
                        self.timings.tcp_ms += (
                            time.perf_counter() - started
                        ) * 1000.0
                self.remaining()
                return connection
            except OSError as error:
                last_error = error
                connection.close()
        if last_error is not None:
            raise last_error
        raise OSError('No usable gallery server address.')


class _DeadlineHTTPSConnection(http.client.HTTPSConnection):
    """Connect verified HTTPS under one caller-owned transport deadline."""

    def __init__(
        self, host: str, port: int | None, control: _LinkingTransportControl,
        context: ssl.SSLContext | None = None,
    ) -> None:
        """Prepare an HTTPS connection without resolving or contacting a host.

        Args:
            host (str): Validated canonical HTTPS hostname.
            port (int | None): Explicit HTTPS port or the standard default.
            control (_LinkingTransportControl): Deadline and socket owner.
            context (ssl.SSLContext | None): Session-owned verified context,
                or None for the original one-request transport.

        Returns:
            None: Creates an unconnected certificate-verifying HTTPS client.
        """
        super().__init__(
            host, port, timeout=LINKING_SOCKET_TIMEOUT_SECONDS,
            context=context if context is not None else ssl.create_default_context(),
        )
        self._linking_control = control

    def connect(self) -> None:
        """Resolve, connect and verify TLS while retaining cancellation control.

        Returns:
            None: Establishes the verified HTTPS connection.

        Raises:
            LinkingError: The request expired or was cancelled.
            OSError: DNS, TCP or TLS could not complete safely.
        """
        self.sock = self._linking_control.connect(
            (self.host, self.port), self.source_address,
        )
        self._linking_control.set_transport_step('tls')
        timings = self._linking_control.timings
        started = time.perf_counter() if timings is not None else 0.0
        try:
            self.sock = self._context.wrap_socket(
                self.sock, server_hostname=self.host, do_handshake_on_connect=False,
            )
            self._linking_control.set_socket(self.sock)
            self.sock.settimeout(self._linking_control.socket_timeout())
            if timings is not None:
                timings.tls_handshakes += 1
            self.sock.do_handshake()
        finally:
            if timings is not None:
                timings.tls_ms += (time.perf_counter() - started) * 1000.0
        self._linking_control.remaining()


class _DeadlineLoopbackConnection(http.client.HTTPConnection):
    """Keep localhost HTTP on loopback with the normal linking deadline."""

    def __init__(self, port: int, control: _LinkingTransportControl) -> None:
        """Prepare a direct HTTP connection for the literal localhost host.

        Args:
            port (int): Exact configured local gallery port.
            control (_LinkingTransportControl): Deadline and socket owner.

        Returns:
            None: Creates an unconnected local HTTP client.
        """
        super().__init__('localhost', port, timeout=LINKING_SOCKET_TIMEOUT_SECONDS)
        self._linking_control = control

    def connect(self) -> None:
        """Connect to a loopback peer before any HTTP request can be sent.

        Returns:
            None: Registers the connected socket with the HTTP client.

        Raises:
            LinkingError: The deadline elapsed or the request was cancelled.
            OSError: No loopback peer is reachable at the selected port.
        """
        self.sock = self._linking_control.connect(
            (self.host, self.port), self.source_address, loopback_only=True,
        )


def _linking_connection(
    issuer: SplitResult, control: _LinkingTransportControl,
    *, context: ssl.SSLContext | None = None,
) -> http.client.HTTPConnection:
    """Choose the direct transport for a previously validated issuer.

    Args:
        issuer (SplitResult): Exact parsed scheme, authority and mount.
        control (_LinkingTransportControl): Deadline and socket owner.
        context (ssl.SSLContext | None): Verified media-session TLS context,
            or None to construct a one-request context.

    Returns:
        http.client.HTTPConnection: Verified HTTPS or confined localhost HTTP.

    Raises:
        LinkingError: The parsed issuer has no permitted transport.
    """
    if issuer.scheme == 'https' and issuer.hostname is not None:
        options = {'context': context} if context is not None else {}
        return _DeadlineHTTPSConnection(
            issuer.hostname, issuer.port or LINKING_HTTPS_DEFAULT_PORT, control,
            **options,
        )
    if issuer.scheme == 'http' and issuer.hostname == 'localhost':
        return _DeadlineLoopbackConnection(
            issuer.port or http.client.HTTP_PORT, control,
        )
    raise LinkingError('Invalid trusted issuer.')


class _MediaConnectionPool:
    """Own one idle verified HTTP/1.1 media connection for one exact session."""

    def __init__(self) -> None:
        """Create an empty socket lease and lazy certificate-verifying context.

        Returns:
            None: No DNS, TLS or network work starts during session creation.
        """
        self._lock = threading.Lock()
        self._connection: http.client.HTTPConnection | None = None
        self._issuer: SplitResult | None = None
        self._idle_since = 0.0
        self._epoch = 0
        self._closed = False
        self._context: ssl.SSLContext | None = None

    def borrow(
        self, issuer: SplitResult, control: _LinkingTransportControl,
    ) -> tuple[http.client.HTTPConnection, bool, int]:
        """Transfer the idle socket to the current request or create a client.

        Args:
            issuer (SplitResult): Exact validated installation origin and mount.
            control (_LinkingTransportControl): New exclusive socket owner.

        Returns:
            tuple[http.client.HTTPConnection, bool, int]: Connection, whether
                its live socket was reused, and revocable pool epoch.

        Raises:
            LinkingError: This session pool has permanently closed.
        """
        with self._lock:
            if self._closed:
                raise LinkingError('Gallery media session is closed.')
            connection, self._connection = self._connection, None
            epoch = self._epoch
            if connection is not None:
                if (self._issuer == issuer and connection.sock is not None
                        and 0 <= time.monotonic() - self._idle_since
                        < MEDIA_CONNECTION_IDLE_SECONDS):
                    control.set_socket(connection.sock)
                    connection.sock.settimeout(control.socket_timeout())
                    connection._linking_control = control
                    if control.timings is not None:
                        control.timings.connection_reuses += 1
                    return connection, True, epoch
                connection.close()
            if issuer.scheme == 'https' and self._context is None:
                started = time.perf_counter()
                try:
                    self._context = ssl.create_default_context()
                finally:
                    if control.timings is not None:
                        control.timings.context_ms += (
                            time.perf_counter() - started
                        ) * 1000.0
            control.remaining()
            return _linking_connection(
                issuer, control, context=self._context,
            ), False, epoch

    def release(
        self, issuer: SplitResult, connection: http.client.HTTPConnection,
        control: _LinkingTransportControl, epoch: int,
    ) -> bool:
        """Retain one completely consumed connection after detaching its owner.

        Args:
            issuer (SplitResult): Exact installation owning this connection.
            connection (http.client.HTTPConnection): Fully validated GET client.
            control (_LinkingTransportControl): Request relinquishing the socket.
            epoch (int): Pool lease captured before this request began.

        Returns:
            bool: True when retained; False when the caller must close it.
        """
        with self._lock:
            if (self._closed or epoch != self._epoch
                    or self._connection is not None or connection.sock is None
                    or not control.detach_socket(connection.sock)):
                return False
            self._connection = connection
            self._issuer = issuer
            self._idle_since = time.monotonic()
            return True

    def discard(self, *, close: bool = False) -> None:
        """Revoke older leases and close retained sockets after uncertainty.

        Args:
            close (bool): Permanently close the pool when the session ends.

        Returns:
            None: Late request completion cannot republish an obsolete lease.
        """
        with self._lock:
            self._epoch += 1
            self._closed = self._closed or close
            connection, self._connection = self._connection, None
        if connection is not None:
            connection.close()


def _upload_deadline_seconds(content_length: int) -> int:
    """Calculate a finite total upload budget based on its exact size.

    Args:
        content_length (int): Complete multipart body length in bytes.

    Returns:
        int: Whole seconds between the configured minimum and hard maximum.

    Raises:
        LinkingError: The body length is not a positive integer.
    """
    if type(content_length) is not int or content_length <= 0:
        raise LinkingError('Invalid gallery upload size.')
    transfer_seconds = (
        content_length + LINKING_UPLOAD_MIN_RATE_BYTES_PER_SECOND - 1
    ) // LINKING_UPLOAD_MIN_RATE_BYTES_PER_SECOND
    return min(
        LINKING_UPLOAD_MAX_DEADLINE_SECONDS,
        max(LINKING_UPLOAD_MIN_DEADLINE_SECONDS,
            transfer_seconds + LINKING_UPLOAD_SETUP_GRACE_SECONDS),
    )


def _request_json(
    issuer: str,
    route: Literal[
        'app_linking_discovery', 'app_linking_push', 'app_linking_token',
        'app_linking_identity', 'app_linking_revoke', 'app_linking_galleries',
        'app_linking_gallery_tree',
        'app_linking_inventory', 'app_linking_upload', 'app_linking_gallery_detail',
        'app_linking_gallery_create', 'app_linking_gallery_create_status',
        'app_linking_gallery_create_abandon',
        'app_linking_gallery_visibility', 'app_linking_photo_visibility',
        'app_linking_media_delete', 'app_linking_gallery_metadata',
    ],
    method: Literal['GET', 'POST'],
    body: bytes | Iterable[bytes] | None,
    bearer: str | None,
    cancelled: threading.Event | None = None,
    after_id: int | None = None,
    multipart_boundary: str | None = None,
    content_length: int | None = None,
    gallery_id: int | None = None,
    watch_key: str | None = None,
    tree_revision: str | None = None,
    server_timing_out: list[
        tuple[PassiveServerTiming | None, float | None]
    ] | None = None,
) -> dict[str, JsonValue]:
    """Bound one HTTPS request, including DNS, and validate its JSON.

    Args:
        issuer (str): Independently trusted canonical installation origin and mount.
        route (str): Fixed public discovery, linking, gallery, upload, or
            creation server route.
        method (Literal['GET', 'POST']): Registered request method.
        body (bytes | Iterable[bytes] | None): Bounded JSON/form, streamed
            multipart chunks, an empty revocation body, or no body.
        bearer (str | None): In-memory token for authenticated routes, if any.
        cancelled (threading.Event | None): Optional caller cancellation event.
        after_id (int | None): Exclusive positive gallery ID cursor, if any.
        multipart_boundary (str | None): Validated generated upload boundary.
        content_length (int | None): Exact multipart body length in bytes.
        gallery_id (int | None): Positive selected ID for gallery detail.
        watch_key (str | None): Supplemental key for a linked Watcher request.
        tree_revision (str | None): Exact optional tree-v1 SHA-256 revision.
        server_timing_out (list[tuple[PassiveServerTiming | None, float | None]] | None):
            Optional private numeric response-timing receiver for safe GETs.

    Returns:
        dict[str, JsonValue]: Strict, unique-key decoded JSON object.

    Raises:
        LinkingError: Busy, cancelled, expired or unsafe transport or response.
    """
    if (not _issuer_valid(issuer)
            or (route == 'app_linking_discovery' and (
                method != 'GET' or body is not None or bearer is not None
                or after_id is not None or gallery_id is not None))
            or (gallery_id is not None and (
                route != 'app_linking_gallery_detail'
                or type(gallery_id) is not int or gallery_id <= 0
                or gallery_id > LINKING_GALLERY_ID_MAX))
            or (route == 'app_linking_gallery_detail' and (
                gallery_id is None or method != 'GET' or body is not None
                or after_id is not None))
            or (route in (
                'app_linking_gallery_visibility', 'app_linking_photo_visibility',
                'app_linking_media_delete', 'app_linking_gallery_metadata',
            ) and (
                method != 'POST' or not isinstance(body, bytes)
                or gallery_id is not None or after_id is not None))
            or (route == 'app_linking_gallery_create_abandon' and (
                method != 'POST' or not isinstance(body, bytes)
                or gallery_id is not None or after_id is not None))
            or (route == 'app_linking_galleries' and method == 'POST' and (
                not isinstance(body, bytes) or after_id is not None or bearer is None))
            or (route == 'app_linking_gallery_tree' and (
                method != 'GET' or body is not None or after_id is not None
                or gallery_id is not None or bearer is None or watch_key is not None
                or (tree_revision is not None and (
                    not isinstance(tree_revision, str)
                    or _GALLERY_TREE_REVISION_PATTERN.fullmatch(tree_revision) is None
                ))))
            or (route != 'app_linking_gallery_tree' and tree_revision is not None)
            or (after_id is not None
            and (route != 'app_linking_galleries' or type(after_id) is not int
                 or after_id <= 0 or after_id > LINKING_GALLERY_ID_MAX))
            or (route == 'app_linking_upload' and (
                not isinstance(multipart_boundary, str)
                or re.fullmatch(r'[A-Za-z0-9]{16,70}', multipart_boundary) is None
                or type(content_length) is not int or content_length <= 0))
            or (watch_key is not None and (
                route not in ('app_linking_inventory', 'app_linking_upload')
                or method != 'POST' or bearer is None
                or not isinstance(watch_key, str)
                or _WATCH_KEY_PATTERN.fullmatch(watch_key) is None))
            or (route != 'app_linking_upload' and (
                multipart_boundary is not None or content_length is not None))):
        raise LinkingError('Invalid trusted issuer.')
    deadline_seconds = (
        _upload_deadline_seconds(content_length)
        if route == 'app_linking_upload' and content_length is not None
        else LINKING_REQUEST_DEADLINE_SECONDS
    )
    inactivity_seconds = (
        LINKING_UPLOAD_INACTIVITY_TIMEOUT_SECONDS
        if route == 'app_linking_upload' else LINKING_SOCKET_TIMEOUT_SECONDS
    )
    def request_once(control: _LinkingTransportControl) -> dict[str, JsonValue]:
        """Send the already validated JSON route using the shared deadline.

        Args:
            control (_LinkingTransportControl): Worker deadline and socket owner.

        Returns:
            dict[str, JsonValue]: The route's strict JSON response object.
        """
        if route == 'app_linking_gallery_tree':
            return _request_json_once(
                issuer, route, method, body, bearer, control,
                tree_revision=tree_revision,
            )
        if watch_key is not None:
            return _request_json_once(
                issuer, route, method, body, bearer, control,
                multipart_boundary=multipart_boundary,
                content_length=content_length, watch_key=watch_key,
            )
        if gallery_id is not None:
            return _request_json_once(
                issuer, route, method, body, bearer, control, gallery_id=gallery_id,
            )
        if multipart_boundary is not None:
            return _request_json_once(
                issuer, route, method, body, bearer, control, after_id,
                multipart_boundary, content_length,
            )
        if after_id is None:
            return _request_json_once(issuer, route, method, body, bearer, control)
        return _request_json_once(issuer, route, method, body, bearer, control, after_id)

    return _run_linking_transport(
        request_once, cancelled, deadline_seconds, inactivity_seconds,
        phase=('discovery' if route == 'app_linking_discovery' else 'linking'),
        route=route, server_timing_out=server_timing_out,
    )


def _run_linking_transport(
    request_once: Callable[[_LinkingTransportControl], _TransportResult],
    cancelled: threading.Event | None, deadline_seconds: float,
    inactivity_seconds: float, *,
    phase: _LinkingDiagnosticPhase = 'linking',
    route: _LinkingDiagnosticRoute = 'app_linking_discovery',
    timings: MediaReadTimings | None = None,
    server_timing_out: list[
        tuple[PassiveServerTiming | None, float | None]
    ] | None = None,
) -> _TransportResult:
    """Admit one request within its total deadline and bound blocked DNS.

    Args:
        request_once (Callable[[_LinkingTransportControl], _TransportResult]):
            Validated operation returning a JSON object or bytes and media type.
        cancelled (threading.Event | None): Optional cancellation of this call.
        deadline_seconds (float): Total request deadline in seconds, including
            admission wait for the single transport worker slot.
        inactivity_seconds (float): Maximum inactive socket time in seconds.
        phase (_LinkingDiagnosticPhase): Fixed phase for safe diagnostics.
        route (_LinkingDiagnosticRoute): Fixed endpoint for safe diagnostics.
        timings (MediaReadTimings | None): Optional media admission counters.
        server_timing_out (list[tuple[PassiveServerTiming | None, float | None]] | None):
            Optional numeric response and send-to-header receiver for JSON GET.

    Returns:
        _TransportResult: Strict JSON object or bounded media bytes and MIME.

    Raises:
        LinkingError: Cancelled, expired or unsafe operation.
        LinkingResponseError: A transport or protocol failure with safe metadata.
    """
    control = _LinkingTransportControl(
        time.monotonic() + deadline_seconds, cancelled, inactivity_seconds,
    )
    control.server_timing_out = server_timing_out
    result: queue.Queue[
        _TransportResult | LinkingHttpsError | LinkingMediaUnavailable
        | LinkingResponseError | LinkingRevisionUnsupported
        | LinkingGrantRejected | LinkingRefreshRejected
        | LinkingThumbnailProofChanged | None
    ] = queue.Queue(maxsize=1)
    slot_acquired = False
    worker_started = False
    slot_release_lock = threading.Lock()
    slot_released = False

    def release_slot_once() -> None:
        """Release the admitted worker slot at most once across both owners.

        Returns:
            None: The bounded semaphore receives exactly one release.
        """
        nonlocal slot_released
        with slot_release_lock:
            if slot_released:
                return
            slot_released = True
        _LINKING_TRANSPORT_SLOTS.release()

    try:
        control.set_transport_step('slot')
        admission_started = time.perf_counter()
        try:
            while not slot_acquired:
                remaining = control.remaining()
                slot_acquired = _LINKING_TRANSPORT_SLOTS.acquire(
                    timeout=min(remaining, LINKING_CANCELLATION_POLL_SECONDS),
                )
        finally:
            if timings is not None:
                timings.slot_wait_ms += (
                    time.perf_counter() - admission_started
                ) * 1000.0
        # Cancellation or deadline may race a successful slot acquisition.
        control.remaining()
        # The worker has not entered a socket operation yet. Its concrete
        # transport hook will label the first observed network step.
        control.set_transport_step('unknown')

        def worker() -> None:
            """Perform one request and retain the slot until network work ends.

            Returns:
                None: Publishes a response or a sanitized transport failure.
            """
            outcome: (
                _TransportResult | LinkingHttpsError | LinkingMediaUnavailable
                | LinkingResponseError | LinkingRevisionUnsupported
                | LinkingGrantRejected | LinkingRefreshRejected
                | LinkingThumbnailProofChanged | None
            )
            try:
                control.remaining()
                outcome = request_once(control)
            except LinkingMediaThrottled as error:
                outcome = LinkingMediaThrottled(
                    error.retry_after_seconds, route=error.route,
                )
            except LinkingResponseError as error:
                outcome = error
            except ssl.SSLError:
                outcome = LinkingResponseError(
                    phase, route, None, 'other', 'tls_failure',
                )
            except socket.gaierror:
                outcome = LinkingResponseError(
                    phase, route, None, 'other', 'dns_failure',
                )
            except (socket.timeout, TimeoutError):
                outcome = LinkingResponseError(
                    phase, route, None, 'other', 'timeout',
                    transport_step=control.transport_step,
                )
            except OSError:
                outcome = LinkingResponseError(
                    phase, route, None, 'other', 'connect_failure',
                )
            except http.client.HTTPException:
                outcome = LinkingResponseError(
                    phase, route, None, 'other', 'transport_failure',
                )
            except LinkingRevisionUnsupported:
                outcome = LinkingRevisionUnsupported(
                    'Gallery revision is unsupported.',
                )
            except LinkingThumbnailProofChanged:
                outcome = LinkingThumbnailProofChanged(
                    'Gallery thumbnail proof is unavailable.',
                )
            except LinkingMediaUnavailable:
                outcome = LinkingMediaUnavailable(
                    'Photos are unavailable for this gallery.',
                )
            except LinkingGrantRejected:
                outcome = LinkingGrantRejected('The linking grant was rejected.')
            except LinkingRefreshRejected:
                outcome = LinkingRefreshRejected(
                    'The saved gallery approval expired or was revoked.',
                )
            except Exception:  # noqa: BLE001
                outcome = None
            finally:
                control.finish()
                release_slot_once()
                control.operation_finished.set()
            if isinstance(outcome, LinkingError):
                outcome.request_started = control.request_started
                outcome.operation_finished = control.operation_finished
                if isinstance(outcome, (LinkingGrantRejected, LinkingRefreshRejected)):
                    outcome.definitely_rejected = True
            result.put_nowait(outcome)

        try:
            thread = threading.Thread(
                target=worker, name='PHPGalleryLinkTransport', daemon=True,
            )
            thread.start()
            worker_started = True
        except RuntimeError:
            raise LinkingError('Linking transport could not start.') from None

        while True:
            remaining = control.remaining()
            try:
                data = result.get(timeout=min(
                    remaining, LINKING_CANCELLATION_POLL_SECONDS,
                ))
            except queue.Empty:
                continue
            # A response racing cancellation must never restore authority.
            control.remaining()
            if isinstance(data, LinkingHttpsError):
                raise data
            if isinstance(data, LinkingMediaThrottled):
                raise data
            if isinstance(data, LinkingResponseError):
                raise data
            if isinstance(data, LinkingRevisionUnsupported):
                raise data
            if isinstance(data, LinkingThumbnailProofChanged):
                raise data
            if isinstance(data, LinkingMediaUnavailable):
                raise data
            if isinstance(data, (LinkingGrantRejected, LinkingRefreshRejected)):
                raise data
            if data is None:
                raise LinkingError('Linking request did not complete safely.')
            return data
    except LinkingError as failure:
        if isinstance(failure, (LinkingResponseError, LinkingGrantRejected,
                                LinkingRefreshRejected, LinkingMediaUnavailable,
                                LinkingRevisionUnsupported, LinkingHttpsError,
                                LinkingThumbnailProofChanged)):
            raise
        raise LinkingResponseError(
            phase, route, None, 'other', 'timeout',
            transport_step=control.transport_step,
            request_started=control.request_started,
            operation_finished=control.operation_finished,
        ) from None
    finally:
        control.stop()
        if slot_acquired and not worker_started:
            release_slot_once()
        if not worker_started:
            control.operation_finished.set()


def _response_content_kind(
    content_types: list[str],
) -> _LinkingDiagnosticContent:
    """Reduce Content-Type metadata to a non-sensitive diagnostic category.

    Args:
        content_types (list[str]): All Content-Type fields returned by HTTP.

    Returns:
        _LinkingDiagnosticContent: JSON, HTML or other without retaining a value.
    """
    if len(content_types) != 1:
        return 'other'
    media_type = content_types[0].split(';', 1)[0].strip().lower()
    if media_type == 'application/json' or media_type.endswith('+json'):
        return 'json'
    if media_type in ('text/html', 'application/xhtml+xml'):
        return 'html'
    return 'other'


def _request_json_once(
    issuer: str,
    route: Literal[
        'app_linking_discovery', 'app_linking_push', 'app_linking_token',
        'app_linking_identity', 'app_linking_revoke', 'app_linking_galleries',
        'app_linking_gallery_tree',
        'app_linking_inventory', 'app_linking_upload', 'app_linking_gallery_detail',
        'app_linking_gallery_create', 'app_linking_gallery_create_status',
        'app_linking_gallery_create_abandon',
        'app_linking_gallery_visibility', 'app_linking_photo_visibility',
        'app_linking_media_delete', 'app_linking_gallery_metadata',
    ],
    method: Literal['GET', 'POST'],
    body: bytes | Iterable[bytes] | None,
    bearer: str | None,
    control: _LinkingTransportControl,
    after_id: int | None = None,
    multipart_boundary: str | None = None,
    content_length: int | None = None,
    gallery_id: int | None = None,
    watch_key: str | None = None,
    tree_revision: str | None = None,
) -> dict[str, JsonValue]:
    """Execute one non-redirecting request on the bounded worker.

    Args:
        issuer (str): Previously validated canonical installation origin and mount.
        route (str): Fixed public discovery, linking, gallery, upload, or
            creation server route.
        method (Literal['GET', 'POST']): Registered request method.
        body (bytes | Iterable[bytes] | None): Encoded JSON/form, streamed
            multipart chunks, empty revoke body, or no body.
        bearer (str | None): In-memory identity token, if required.
        control (_LinkingTransportControl): Absolute deadline and socket owner.
        after_id (int | None): Validated exclusive gallery ID cursor, if any.
        multipart_boundary (str | None): Generated multipart boundary, if used.
        content_length (int | None): Exact multipart body size in bytes.
        gallery_id (int | None): Positive selected ID for gallery detail.
        watch_key (str | None): Validated temporary Watcher key, if applicable.
        tree_revision (str | None): Validated optional complete-tree revision.

    Returns:
        dict[str, JsonValue]: Strict JSON object returned by the selected route.

    Raises:
        LinkingError: The response, deadline or cancellation was unsafe.
        OSError: DNS, connection or TLS failed.
        ValueError: The response did not contain valid bounded JSON.
        http.client.HTTPException: The server sent an invalid HTTP response.
    """
    parsed = urlsplit(issuer)
    wire_route = 'app_linking_galleries' if route == 'app_linking_gallery_tree' else (
        'app_linking_media' if route in (
            'app_linking_photo_visibility', 'app_linking_media_delete',
            'app_linking_gallery_metadata',
        ) else route
    )
    path = parsed.path + '/index.php?page=' + wire_route
    if route == 'app_linking_gallery_tree':
        if (method != 'GET' or body is not None or after_id is not None
                or (tree_revision is not None and (
                    _GALLERY_TREE_REVISION_PATTERN.fullmatch(tree_revision) is None
                ))):
            raise LinkingError('Invalid trusted issuer.')
        path += '&tree=1'
        if tree_revision is not None:
            path += '&revision=' + tree_revision
    if after_id is not None:
        path += '&after=' + str(after_id)
    if gallery_id is not None and route == 'app_linking_gallery_detail':
        path += '&gallery_id=' + str(gallery_id)
    headers = {
        'Accept': 'application/json',
        'User-Agent': LINKING_USER_AGENT,
    }
    if bearer is not None:
        headers['Authorization'] = 'Bearer ' + bearer
    if watch_key is not None:
        headers['X-PHP-Gallery-Watch-Key'] = watch_key
    if route in ('app_linking_push', 'app_linking_token') or (
        route == 'app_linking_galleries' and method == 'POST'
    ):
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    elif route in ('app_linking_inventory', 'app_linking_gallery_create',
                   'app_linking_gallery_create_status',
                   'app_linking_gallery_create_abandon',
                   'app_linking_gallery_visibility', 'app_linking_photo_visibility',
                   'app_linking_media_delete', 'app_linking_gallery_metadata'):
        headers['Content-Type'] = 'application/json; charset=utf-8'
    elif route == 'app_linking_upload':
        headers['Content-Type'] = 'multipart/form-data; boundary=' + str(multipart_boundary)
        headers['Content-Length'] = str(content_length)
    control.remaining()
    connection = _linking_connection(parsed, control)
    try:
        # The direct transport never follows redirects.
        connection.connect()
        dispatch = getattr(body, 'before_dispatch', None)
        if route == 'app_linking_upload' and dispatch is not None:
            dispatch()
        control.remaining()
        control.request_started = True
        control.set_transport_step('request')
        header_started = (
            time.perf_counter() if control.server_timing_out is not None else 0.0
        )
        connection.request(method, path, body=body, headers=headers)
        control.set_transport_step('headers')
        with connection.getresponse() as response:
            if control.server_timing_out is not None:
                control.server_header_ms = (
                    time.perf_counter() - header_started
                ) * 1000.0
            content_types = response.headers.get_all('Content-Type', [])
            cache_headers = response.headers.get_all('Cache-Control', [])
            access_rejected = (
                route not in ('app_linking_push', 'app_linking_token',
                              'app_linking_discovery') and response.status == 401
            )
            refresh_rejected = (
                route == 'app_linking_token' and method == 'POST'
                and bearer is None and response.status == 400
                and isinstance(body, bytes)
                and body.startswith(b'grant_type=refresh_token&')
            )
            if ((access_rejected or refresh_rejected) and len(content_types) == 1
                    and content_types[0].split(';', 1)[0].strip().lower()
                    == 'application/json'
                    and len(cache_headers) == 1
                    and 'no-store' in {
                        part.strip().lower()
                        for part in cache_headers[0].split(',')
                    }
                    and response.headers.get('Content-Encoding') is None):
                control.set_transport_step('body')
                payload = response.read(LINKING_RESPONSE_MAX_BYTES + 1)
                if len(payload) <= LINKING_RESPONSE_MAX_BYTES:
                    try:
                        rejection = json.loads(
                            payload.decode('utf-8'),
                            object_pairs_hook=_unique_object,
                            parse_constant=_reject_nonfinite,
                        )
                    except (UnicodeError, ValueError, RecursionError):
                        rejection = None
                    if access_rejected and rejection == {'error': 'invalid_token'}:
                        raise LinkingGrantRejected(
                            'The linking grant was rejected.',
                        )
                    lengths = response.headers.get_all('Content-Length', [])
                    if (refresh_rejected and rejection == {'error': 'invalid_grant'}
                            and (not lengths or lengths == [str(len(payload))])):
                        raise LinkingRefreshRejected(
                            'The saved gallery approval expired or was revoked.',
                        )
            expected_status = 201 if route == 'app_linking_push' else 200
            diagnostic_phase: _LinkingDiagnosticPhase = (
                'discovery' if route == 'app_linking_discovery' else 'linking'
            )
            content_kind = _response_content_kind(content_types)
            exact_json = (
                len(content_types) == 1
                and content_types[0].split(';', 1)[0].strip().lower()
                == 'application/json'
            )
            cache_directives = {
                directive.strip().lower()
                for header in response.headers.get_all('Cache-Control', [])
                for directive in header.split(',')
            }
            visibility_conflict = (route in ('app_linking_gallery_visibility',
                                             'app_linking_photo_visibility',
                                             'app_linking_gallery_metadata')
                                   and response.status == 409)
            if response.status != expected_status and not visibility_conflict:
                # Only this exact no-store application refusal attests that
                # admission stopped before image storage. A proxy 429/503 does
                # not establish that an earlier upload did not commit.
                retry_after = validated_retry_after(
                    response.headers.get_all('Retry-After', []),
                )
                rejected_before_store = False
                if (route in ('app_linking_inventory', 'app_linking_upload')
                        and response.status in UPLOAD_PRESTORE_REFUSALS
                        and (response.status != 503
                             or response.headers.get_all('X-PHP-Gallery-Upload-Phase', [])
                             == ['rejected-before-store-v1']) and exact_json
                        and 'no-store' in cache_directives
                        and response.headers.get('Content-Encoding') is None):
                    control.set_transport_step('body')
                    raw_refusal = response.read(LINKING_RESPONSE_MAX_BYTES + 1)
                    if len(raw_refusal) <= LINKING_RESPONSE_MAX_BYTES:
                        try:
                            refused = json.loads(
                                raw_refusal, object_pairs_hook=_unique_object,
                                parse_constant=_reject_nonfinite,
                            )
                            rejected_before_store = any(
                                refused == {'error': value}
                                for value in UPLOAD_PRESTORE_REFUSALS[response.status]
                            )
                        except (ValueError, UnicodeError, RecursionError):
                            pass
                raise LinkingResponseError(
                    diagnostic_phase, route, response.status, content_kind,
                    'http_status',
                    retry_after_seconds=retry_after,
                    definitely_rejected=rejected_before_store,
                )
            if not exact_json:
                raise LinkingResponseError(
                    diagnostic_phase, route, response.status, content_kind,
                    'content_type',
                )
            if ('no-store' not in cache_directives
                    or response.headers.get('Content-Encoding') is not None):
                raise LinkingResponseError(
                    diagnostic_phase, route, response.status, content_kind,
                    'response_policy',
                )
            # One extra byte distinguishes the exact configured size boundary.
            max_bytes = (GALLERY_EDIT_RESPONSE_MAX_BYTES
                         if route == 'app_linking_gallery_metadata' else
                         LINKING_GALLERY_TREE_RESPONSE_MAX_BYTES
                         if route == 'app_linking_gallery_tree' else
                         LINKING_PICKER_RESPONSE_MAX_BYTES
                         if route == 'app_linking_galleries' and method == 'POST' else
                         LINKING_GALLERIES_RESPONSE_MAX_BYTES
                         if route == 'app_linking_galleries' else
                         LINKING_UPLOAD_RESPONSE_MAX_BYTES
                         if route in ('app_linking_inventory', 'app_linking_upload')
                         else LINKING_RESPONSE_MAX_BYTES)
            control.set_transport_step('body')
            payload = response.read(max_bytes + 1)
        control.remaining()
        if len(payload) > max_bytes:
            raise LinkingResponseError(
                diagnostic_phase, route, response.status, content_kind,
                'response_size',
            )
        try:
            data = json.loads(
                payload.decode('utf-8'), object_pairs_hook=_unique_object,
                parse_constant=_reject_nonfinite,
            )
        except (UnicodeError, ValueError, RecursionError):
            raise LinkingResponseError(
                diagnostic_phase, route, response.status, content_kind,
                'invalid_json',
            ) from None
        if not isinstance(data, dict):
            raise LinkingResponseError(
                diagnostic_phase, route, response.status, content_kind,
                'invalid_shape',
            )
        if visibility_conflict:
            if (data != {'error': 'revision_conflict'}
                    and not (route == 'app_linking_gallery_visibility'
                             and data == {'error': 'scheduled_publication_active'})):
                raise LinkingError('Linking response was not accepted.')
            # This local marker is only created for an exact pre-write 409.
            return {'__client_visibility_conflict': data['error']}
        if (route in ('app_linking_gallery_visibility', 'app_linking_photo_visibility',
                      'app_linking_gallery_metadata')
                and '__client_visibility_conflict' in data):
            raise LinkingError('Linking response was not accepted.')
        if (control.server_timing_out is not None
                and method == 'GET' and route in (
                    'app_linking_gallery_tree', 'app_linking_galleries',
                )):
            control.server_timing_out.append((
                parse_server_timing(
                    response.headers.get_all('Server-Timing', []),
                ),
                control.server_header_ms,
            ))
        return data
    finally:
        connection.close()
