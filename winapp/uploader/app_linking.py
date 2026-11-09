# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/app_linking.py
# Module Type: Windows Client Transport
# Purpose: Own a scoped PKCE linking attempt and in-memory session.
# Responsibilities:
#   - Bind one exact installation issuer and a fresh state/verifier to an attempt.
#   - Push the authorization parameters once without following redirects.
#   - Accept only a bounded, local, exact callback without logging its contents.
#   - Exchange once and use or revoke an explicitly scoped in-memory bearer.
#   - Leave consent, UI, credential persistence and upload to their own owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Scoped linking transport without persistent credential side effects."""

import base64
import binascii
import hashlib
import hmac
import http.client
import ipaddress
import json
import logging
import math
import queue
import re
import secrets
import socket
import ssl
import sys
import threading
import time
from collections import OrderedDict, deque
from dataclasses import dataclass, field, replace as dataclass_replace
from typing import Callable, Iterable, Literal, TypeVar
from urllib.parse import SplitResult, parse_qsl, urlencode, urlsplit

from .models import JsonValue
from .server_discovery import _reject_nonfinite, _unique_object, discovery_url
from .gallery_media_cache import (
    GalleryMediaCache, PhotoCacheEntry, PhotoCacheReceipt,
    PHOTO_CACHE_FRESH_SECONDS,
)


CLIENT_ID = 'php-gallery-winapp'
# Purpose: Identify the native client on every direct PHP Gallery request.
# Type: str. Units: HTTP User-Agent product token. Scope: linking JSON and media.
# Consumers: _request_json_once, _native_media_read_once. Rationale: Hosting
# security filters require a truthful stable client identifier, not a release pin.
LINKING_USER_AGENT = 'PHPGalleryUploader'
# Purpose: Retain one idle sequential media socket briefly between GETs.
# Type: float. Units: seconds. Scope: one live LinkingSession media pool.
# Consumers: _MediaConnectionPool.borrow.
# Rationale: 15 seconds bounds idle resources; this is a conservative initial
# policy, not a measured hosting optimum. Server closure always takes priority.
MEDIA_CONNECTION_IDLE_SECONDS = 15.0

# Purpose: Recover one stale keep-alive socket before any response is received.
# Type: int. Units: additional GET attempts. Scope: one media transport deadline.
# Consumers: _native_media_read_once.
# Rationale: One fresh reconnect supports servers closing idle sockets without
# retrying rejected responses, incomplete bodies, uploads or mutations.
MEDIA_STALE_GET_RETRIES = 1
CALLBACK_PATH = '/oauth2redirect/php-gallery'
IDENTITY_SCOPE = 'link:identity'
MEDIA_SCOPE_SUFFIX = ' media:read'
ADMIN_MEDIA_SCOPE_SUFFIX = ' media:admin'
# Purpose: Identify servers supporting explicit private media retention.
# Type: str. Units: discovery capability. Scope: one linking installation.
# Consumers: LinkingAttempt and LinkingSession. Rationale: A capability marker
# negotiates wire behavior without changing OAuth consent or grant scopes.
ADMIN_PRELOAD_CAPABILITY = 'media:admin-preload-v1'
ADMIN_PRESENTATION_SCOPE_SUFFIX = ADMIN_MEDIA_SCOPE_SUFFIX + ' presentation:read'
# Purpose: Add deletion only after the existing explicit Admin media consent.
# Type: str. Units: ordered OAuth scope suffix. Scope: native Admin Photos.
# Consumers: ADMIN_MEDIA_SCOPES, LinkingAttempt.push_request.
# Rationale: Existing read grants remain read-only until fresh browser approval.
ADMIN_DELETE_SCOPE_SUFFIX = ' media:delete'
PRESENTATION_SCOPE_SUFFIX = ' media:read presentation:read'
# Purpose: Limit the memory and network work of a single native media request.
# Type: int. Units: bytes. Scope: one image or audio response.
# Consumers: _native_media_read_once, media_track. Rationale: 16 MiB matches
# the server's bounded display and audio policy.
NATIVE_MEDIA_MAX_BYTES = 16 * 1024 * 1024
# Purpose: Bound one exact, explicitly approved native deletion batch.
# Type: int. Units: photo IDs. Scope: one Admin gallery mutation.
# Consumers: LinkingSession.delete_media_photos. Rationale: Match the server cap.
NATIVE_MEDIA_DELETE_BATCH_MAX = 64
# Purpose: Bound native metadata decoding and gallery navigation.
# Type: int. Units: bytes. Scope: one photo page or presentation envelope.
# Consumers: _native_media_read_once. Rationale: 64 KiB fits the maximum
# playlist even with escaped Unicode titles.
NATIVE_MEDIA_JSON_BYTES = 64 * 1024
# Purpose: Limit descriptors in a response before creating UI rows.
# Type: int. Units: photos. Scope: one native gallery page.
# Consumers: media_page, _native_media_read_once. Rationale: 64 amortizes
# listing requests for continuous browsing while bounding decode and UI work.
NATIVE_MEDIA_PAGE_SIZE = 64

# Purpose: Bound recent first pages and private proof galleries in memory.
# Type: int. Units: galleries. Scope: one live linking session.
# Consumers: LinkingSession.media_page and recent_media_page.
# Rationale: Sixteen first pages retain at most 1024 descriptors. Private
# proofs use their own per-gallery row cap; all original proof ages remain.
MEDIA_RECENT_PAGE_LIMIT = 16
# Purpose: Bound RAM-only private proof rows while paginated albums are open.
# Type: int. Units: photo descriptors. Scope: one gallery per live session.
# Consumers: LinkingSession.media_page.
# Rationale: 1024 covers normal album navigation without retaining an album dump.
PRIVATE_PRELOAD_PROOF_ROW_LIMIT = 1024
# Purpose: Recognize continuation from older servers that ignore the page header.
# Type: int. Units: photos. Scope: one legacy native photo response.
# Consumers: media_page. Rationale: Eight is the original server page bound.
NATIVE_MEDIA_LEGACY_PAGE_SIZE = 8
# Purpose: Bound decoding of the larger native photo catalogue.
# Type: int. Units: bytes. Scope: one photo catalogue response.
# Consumers: _native_media_read_once. Rationale: 256 KiB covers 64 titles of
# up to 255 characters even when supplementary characters use surrogate escapes.
NATIVE_MEDIA_CATALOGUE_BYTES = 256 * 1024
# Purpose: Keep each optional metadata field usable in an inline card.
# Type: int. Units: characters. Scope: one detailed media text field.
# Consumers: _linked_media_details. Rationale: Match the server projection.
NATIVE_MEDIA_DETAIL_TEXT_CHARS = 512
# Purpose: Keep native music choice bounded by the server playlist projection.
# Type: int. Units: tracks. Scope: one native presentation.
# Consumers: media_presentation. Rationale: 32 is the server projection cap.
NATIVE_MEDIA_TRACK_LIMIT = 32
# Purpose: Bound one server-directed delay before retrying a photo request.
# Type: int. Units: seconds. Scope: one Retry-After accepted by native media.
# Consumers: _native_media_read_once and GalleryPreloadWorker. Rationale: Five
# minutes honors short server windows without allowing unbounded UI delay.
MEDIA_RETRY_AFTER_MAX_SECONDS = 300
# Purpose: Bound retries for one explicitly preloaded media rendition.
# Type: int. Units: attempts. Scope: one photo or thumbnail request.
# Consumers: GalleryPreloadWorker. Rationale: Three attempts tolerate transient
# throttling while preventing a persistent server limit from looping forever.
MEDIA_PRELOAD_RETRY_MAX_ATTEMPTS = 3
# Purpose: Recognize only the server's bounded slideshow duration presets.
# Type: tuple[int, ...]. Units: seconds. Scope: one display interval.
# Consumers: media_presentation. Rationale: The server sends 3, 5 or 10.
_NATIVE_INTERVAL_SECONDS = (3, 5, 10)
_NATIVE_IMAGE_TYPES = ('image/jpeg', 'image/png', 'image/gif', 'image/webp')
_NATIVE_AUDIO_TYPES = ('audio/mpeg', 'audio/wav', 'audio/x-wav')
_NativeMediaResponse = tuple[bytes | None, str, str | None, bool]
_TransportResult = TypeVar(
    '_TransportResult', dict[str, JsonValue], tuple[bytes, str],
    _NativeMediaResponse,
)
_NativeMediaKind = Literal[
    'photos', 'presentation', 'thumbnail', 'photo', 'track', 'info',
    'gallery_info', 'photo_info', 'revision',
]
LinkedMediaDetails = dict[str, str | int | float | bool | None]
GALLERIES_SCOPE = 'link:identity galleries:list'
UPLOAD_SCOPE = 'link:identity galleries:list gallery:upload'
GALLERY_SEARCH_SUFFIX = ' galleries:search'
GALLERY_SEARCH_SCOPES = (
    GALLERIES_SCOPE + GALLERY_SEARCH_SUFFIX, UPLOAD_SCOPE + GALLERY_SEARCH_SUFFIX,
)
READ_SCOPE = 'link:identity galleries:list gallery:read'
READ_UPLOAD_SCOPE = READ_SCOPE + ' gallery:upload'
CREATE_SCOPE = GALLERIES_SCOPE + ' galleries:create'
READ_CREATE_SCOPE = READ_SCOPE + ' galleries:create'
UPLOAD_CREATE_SCOPE = UPLOAD_SCOPE + ' galleries:create'
READ_UPLOAD_CREATE_SCOPE = READ_UPLOAD_SCOPE + ' galleries:create'
IDENTITY_OFFLINE_SCOPE = IDENTITY_SCOPE + ' offline_access'
GALLERIES_OFFLINE_SCOPE = GALLERIES_SCOPE + ' offline_access'
UPLOAD_OFFLINE_SCOPE = UPLOAD_SCOPE + ' offline_access'
ROLLING_SCOPE_SUFFIX = ' rolling_access offline_access'
ROLLING_GALLERIES_SCOPE = GALLERIES_SCOPE + ROLLING_SCOPE_SUFFIX
ROLLING_UPLOAD_SCOPE = UPLOAD_SCOPE + ROLLING_SCOPE_SUFFIX
ROLLING_GALLERY_SEARCH_SCOPES = (
    GALLERIES_SCOPE + GALLERY_SEARCH_SUFFIX + ROLLING_SCOPE_SUFFIX,
    UPLOAD_SCOPE + GALLERY_SEARCH_SUFFIX + ROLLING_SCOPE_SUFFIX,
)
ROLLING_SEARCH_PRESENTATION_SCOPES = tuple(
    scope + PRESENTATION_SCOPE_SUFFIX + ROLLING_SCOPE_SUFFIX
    for scope in GALLERY_SEARCH_SCOPES
)
ROLLING_PRESENTATION_SCOPES = (
    GALLERIES_SCOPE + PRESENTATION_SCOPE_SUFFIX + ROLLING_SCOPE_SUFFIX,
    UPLOAD_SCOPE + PRESENTATION_SCOPE_SUFFIX + ROLLING_SCOPE_SUFFIX,
    *ROLLING_SEARCH_PRESENTATION_SCOPES,
)
READ_OFFLINE_SCOPE = READ_SCOPE + ' offline_access'
READ_UPLOAD_OFFLINE_SCOPE = READ_UPLOAD_SCOPE + ' offline_access'
CREATE_OFFLINE_SCOPE = CREATE_SCOPE + ' offline_access'
READ_CREATE_OFFLINE_SCOPE = READ_CREATE_SCOPE + ' offline_access'
UPLOAD_CREATE_OFFLINE_SCOPE = UPLOAD_CREATE_SCOPE + ' offline_access'
READ_UPLOAD_CREATE_OFFLINE_SCOPE = READ_UPLOAD_CREATE_SCOPE + ' offline_access'
VISIBILITY_SCOPE = READ_SCOPE + ' gallery:visibility'
UPLOAD_VISIBILITY_SCOPE = READ_UPLOAD_SCOPE + ' gallery:visibility'
CREATE_VISIBILITY_SCOPE = READ_CREATE_SCOPE + ' gallery:visibility'
UPLOAD_CREATE_VISIBILITY_SCOPE = READ_UPLOAD_CREATE_SCOPE + ' gallery:visibility'
VISIBILITY_OFFLINE_SCOPE = VISIBILITY_SCOPE + ' offline_access'
UPLOAD_VISIBILITY_OFFLINE_SCOPE = UPLOAD_VISIBILITY_SCOPE + ' offline_access'
CREATE_VISIBILITY_OFFLINE_SCOPE = CREATE_VISIBILITY_SCOPE + ' offline_access'
UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE = UPLOAD_CREATE_VISIBILITY_SCOPE + ' offline_access'
VISIBILITY_SCOPES = (
    VISIBILITY_SCOPE, UPLOAD_VISIBILITY_SCOPE, CREATE_VISIBILITY_SCOPE,
    UPLOAD_CREATE_VISIBILITY_SCOPE, VISIBILITY_OFFLINE_SCOPE,
    UPLOAD_VISIBILITY_OFFLINE_SCOPE, CREATE_VISIBILITY_OFFLINE_SCOPE,
    UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
)
CODE_PREFIX = 'pglc_'
TOKEN_PREFIX = 'pgla_'
# Purpose: Generate a capture-specific key with the same entropy as linking.
# Type: int. Units: random bytes. Scope: one contextual Watcher capture.
# Consumers: LinkingSession.open_watch_key. Rationale: 32 bytes resist guessing.
WATCH_KEY_RANDOM_BYTES = 32
_WATCH_KEY_PATTERN = re.compile(r'pgw_[0-9a-f]{64}')

# Canonical exact bundles mirror the server registration. Keep the order stable
# for the append-only offline credential representation.
_ADMIN_MEDIA_BASES = (
    GALLERIES_SCOPE, UPLOAD_SCOPE, READ_SCOPE, READ_UPLOAD_SCOPE,
    CREATE_SCOPE, READ_CREATE_SCOPE, UPLOAD_CREATE_SCOPE, READ_UPLOAD_CREATE_SCOPE,
    VISIBILITY_SCOPE, UPLOAD_VISIBILITY_SCOPE,
    CREATE_VISIBILITY_SCOPE, UPLOAD_CREATE_VISIBILITY_SCOPE,
)
ADMIN_MEDIA_SCOPES = {
    base + search + media + renewal: base
    for base in _ADMIN_MEDIA_BASES
    for search in ('', GALLERY_SEARCH_SUFFIX)
    for media in (ADMIN_MEDIA_SCOPE_SUFFIX, ADMIN_PRESENTATION_SCOPE_SUFFIX)
    for renewal in ('', ROLLING_SCOPE_SUFFIX)
}
ADMIN_MEDIA_DELETE_SCOPES = {
    base + search + media + ADMIN_DELETE_SCOPE_SUFFIX + renewal: base
    for base in _ADMIN_MEDIA_BASES
    for search in ('', GALLERY_SEARCH_SUFFIX)
    for media in (ADMIN_MEDIA_SCOPE_SUFFIX, ADMIN_PRESENTATION_SCOPE_SUFFIX)
    for renewal in ('', ROLLING_SCOPE_SUFFIX)
}
ADMIN_MEDIA_SCOPES.update(ADMIN_MEDIA_DELETE_SCOPES)
ROLLING_ADMIN_MEDIA_SCOPES = tuple(
    scope for scope in ADMIN_MEDIA_SCOPES
    if scope.endswith(ROLLING_SCOPE_SUFFIX)
    and ADMIN_DELETE_SCOPE_SUFFIX not in scope
)
ROLLING_ADMIN_DELETE_SCOPES = tuple(
    scope for scope in ADMIN_MEDIA_DELETE_SCOPES
    if scope.endswith(ROLLING_SCOPE_SUFFIX)
)


# Purpose: Reject IPv4-compatible IPv6 issuers with ambiguous serialization.
# Type: IPv6Network. Units: network addresses. Scope: HTTPS issuer hosts.
# Consumers: _ipv6_issuer_supported. Rationale: native and browser output may
# encode the IPv4 suffix differently; unspecified and loopback remain usable.
IPV4_COMPATIBLE_NETWORK = ipaddress.IPv6Network('::/96')

# Purpose: Bound an issuer accepted from independently trusted configuration.
# Type: int. Units: ASCII bytes. Scope: one installation origin and mount.
# Consumers: _issuer_valid. Rationale: 512 matches the server issuer capacity.
ISSUER_MAX_BYTES = 512

# Purpose: Retain the server's textual DNS name limit.
# Type: int. Units: ASCII bytes. Scope: one issuer hostname.
# Consumers: _issuer_valid. Rationale: 253 is the DNS presentation limit.
ISSUER_DNS_MAX_BYTES = 253

# Purpose: Bound random state and PKCE verifier material.
# Type: int. Units: random bytes. Scope: each newly created attempt value.
# Consumers: LinkingAttempt. Rationale: 32 bytes provide 256 bits of entropy.
ATTEMPT_RANDOM_BYTES = 32

# Purpose: Bound the period in which a browser may return to this attempt.
# Type: int. Units: seconds. Scope: one loopback callback listener.
# Consumers: LinkingAttempt.wait_for_callback.
# Rationale: 300 seconds matches the server's pending-consent lifetime.
CALLBACK_LIFETIME_SECONDS = 300

# Purpose: Observe cancellation while waiting for a local connection.
# Type: float. Units: seconds. Scope: one loopback accept poll.
# Consumers: LinkingAttempt.wait_for_callback.
# Rationale: 0.25 seconds keeps cancellation responsive without a busy loop.
CALLBACK_ACCEPT_POLL_SECONDS = 0.25

# Purpose: Stop a slow local peer from holding the callback listener.
# Type: int. Units: seconds. Scope: one accepted callback connection.
# Consumers: _read_request.
# Rationale: 2 seconds allows a local browser while bounding incomplete headers.
CALLBACK_CLIENT_SECONDS = 2

# Purpose: Bound request-line and header bytes from a local callback peer.
# Type: int. Units: bytes. Scope: one callback request.
# Consumers: _read_request.
# Rationale: 8192 bytes accommodates the short callback without unbounded input.
CALLBACK_HEADER_MAX_BYTES = 8192

# Purpose: Bound rejected callback attempts before requiring a new link attempt.
# Type: int. Units: requests. Scope: one loopback callback listener.
# Consumers: LinkingAttempt.wait_for_callback.
# Rationale: 16 invalid requests limit local abuse while allowing browser noise.
CALLBACK_INVALID_LIMIT = 16

# Purpose: Bound unpadded 256-bit base64url values for this protocol.
# Type: int. Units: ASCII characters. Scope: state, code and verifier payloads.
# Consumers: _canonical_256bit.
# Rationale: 32 bytes encode to exactly 43 unpadded base64url characters.
BASE64URL_256_LENGTH = 43

# Purpose: Bound the decoded size of a server request handle.
# Type: int. Units: decoded bytes. Scope: one server request handle.
# Consumers: _canonical_request_handle. Rationale: the server's handle contains
# 128 random bits and its canonical Base32 representation has 26 characters.
REQUEST_HANDLE_RANDOM_BYTES = 16

# Purpose: Bound the canonical Base32 representation of a request handle.
# Type: int. Units: ASCII characters. Scope: one unpadded Base32 handle.
# Consumers: _canonical_request_handle. Rationale: 16 bytes require 26 symbols.
REQUEST_HANDLE_LENGTH = 26

# Purpose: Reject implausible lifetimes for a browser handoff.
# Type: int. Units: seconds. Scope: one pushed authorization request.
# Consumers: LinkingAttempt.push_request. Rationale: five minutes bounds the
# server-side browser approval window.
HANDOFF_MAX_LIFETIME_SECONDS = 300

# Purpose: Pin the public discovery envelope supported by this client.
# Type: int. Units: protocol version. Scope: public linking discovery.
# Consumers: LinkingAttempt.push_request. Rationale: only the current explicit
# discovery envelope is approved for browser-based linking.
LINKING_DISCOVERY_CONTRACT_VERSION = 1

# Purpose: Pin the linking protocol semantics supported by this client.
# Type: int. Units: protocol version. Scope: browser-approved linking.
# Consumers: LinkingAttempt.push_request. Rationale: unknown security semantics
# fail closed before a push is sent.
LINKING_PROTOCOL_VERSION = 1

# Purpose: Pin the server's handle-based browser handoff format.
# Type: int. Units: handoff version. Scope: browser-approved linking.
# Consumers: LinkingAttempt.push_request. Rationale: this known version admits
# the explicit browser-consent handoff.
BROWSER_HANDOFF_VERSION = 1

# Purpose: Bound waiting for another operation on one linked session.
# Type: float. Units: seconds. Scope: one local session request admission.
# Consumers: LinkingSession._request. Rationale: 20 seconds exceeds the small
# JSON request deadline while preventing an indefinitely blocked caller.
LINKING_SESSION_ADMISSION_TIMEOUT_SECONDS = 20.0

# Purpose: Bound the loopback listener's pending connection queue.
# Type: int. Units: connections. Scope: one callback listener.
# Consumers: LinkingAttempt. Rationale: one browser callback is sufficient.
CALLBACK_BACKLOG = 1

# Purpose: Bound inactive HTTPS socket operations for linking requests.
# Type: int. Units: seconds. Scope: each individual network operation.
# Consumers: _LinkingTransportControl. Rationale: 8 seconds allows hosting.
LINKING_SOCKET_TIMEOUT_SECONDS = 8

# Purpose: Resolve the standard port when an HTTPS issuer omits it.
# Type: int. Units: TCP port number. Scope: HTTPS linking connections.
# Consumers: _issuer_valid, linking_issuer, _linking_connection.
# Rationale: 443 is the standard HTTPS port and
# passing it explicitly avoids interpreting an IPv6 hostname as host:port.
LINKING_HTTPS_DEFAULT_PORT = 443

# Purpose: Bound one linking request including DNS, TLS and the response body.
# Type: int. Units: seconds. Scope: one native linking HTTPS operation.
# Consumers: _request_json. Rationale: 12 seconds allows shared hosting while
# ensuring an unresponsive server does not hold the caller indefinitely.
LINKING_REQUEST_DEADLINE_SECONDS = 12

# Purpose: Preserve the existing upload's tolerance for an inactive socket.
# Type: int. Units: seconds. Scope: one delegated multipart socket operation.
# Consumers: _request_json. Rationale: 180 seconds matches legacy upload.
LINKING_UPLOAD_INACTIVITY_TIMEOUT_SECONDS = 180

# Purpose: Allow slow image transfer before the whole-request deadline.
# Type: int. Units: bytes per second. Scope: one delegated multipart upload.
# Consumers: _upload_deadline_seconds. Rationale: 32768 B/s gives large images
# substantially more time than small metadata requests without an infinite wait.
LINKING_UPLOAD_MIN_RATE_BYTES_PER_SECOND = 32768

# Purpose: Allow DNS, TLS and final acknowledgement outside the transfer budget.
# Type: int. Units: seconds. Scope: one delegated multipart upload.
# Consumers: _upload_deadline_seconds. Rationale: 30 seconds admits setup and
# server processing without treating the transfer rate as a timeout by itself.
LINKING_UPLOAD_SETUP_GRACE_SECONDS = 30

# Purpose: Retain a useful minimum total time for small uploads.
# Type: int. Units: seconds. Scope: one delegated multipart upload.
# Consumers: _upload_deadline_seconds. Rationale: 180 seconds matches the old
# upload's inactive-socket timeout before applying the size-based budget.
LINKING_UPLOAD_MIN_DEADLINE_SECONDS = 180

# Purpose: Bound even the slowest or largest individual delegated transfer.
# Type: int. Units: seconds. Scope: one delegated multipart upload.
# Consumers: _upload_deadline_seconds. Rationale: 86400 seconds permits a day
# for large files without allowing one request to hold its worker forever.
LINKING_UPLOAD_MAX_DEADLINE_SECONDS = 86400

# Purpose: Observe caller cancellation during admission or an HTTPS request.
# Type: float. Units: seconds. Scope: gate and native transport waits.
# Consumers: _FairRequestGate.acquire, _run_linking_transport.
# Rationale: 0.1 seconds keeps cancellation
# responsive without running a busy wait.
LINKING_CANCELLATION_POLL_SECONDS = 0.1

# Purpose: Limit unresolved or otherwise stalled native HTTPS operations.
# Type: int. Units: transport workers. Scope: this application process.
# Consumers: _request_json. Rationale: one slot prevents unbounded workers
# when an operating-system DNS call cannot be interrupted.
LINKING_TRANSPORT_WORKER_LIMIT = 1

class _FairRequestGate:
    """Serialize existing request ownership in FIFO admission order.

    This replaces the existing one-slot primitive; it introduces no additional
    transport lane. A waiting Watcher/viewport call precedes the next preload
    call rather than relying on scheduler fairness of a native mutex.
    Cancelled views withdraw their tickets while an earlier owner is active.
    """

    def __init__(self) -> None:
        """Create one free serial admission gate.

        Returns:
            None: Initializes FIFO tickets and its condition variable.
        """
        self._condition = threading.Condition()
        self._waiters: deque[object] = deque()
        self._held = False

    def acquire(
        self, blocking: bool = True, timeout: float = -1, *,
        cancelled: threading.Event | None = None,
    ) -> bool:
        """Acquire the existing serial slot in waiting-call order.

        Args:
            blocking (bool): Whether admission may wait for earlier calls.
            timeout (float): Maximum wait seconds, or negative for no deadline.
            cancelled (threading.Event | None): Obsolete caller's cancellation;
                its ticket is withdrawn before it can delay a current view.
        Returns:
            bool: True for one admitted owner; False on nonblocking contention
                or timeout/cancellation, with its waiting ticket removed.
        """
        deadline = time.monotonic() + timeout if timeout >= 0 else None
        ticket = object()
        with self._condition:
            if cancelled is not None and cancelled.is_set():
                return False
            if not blocking and (self._held or self._waiters):
                return False
            self._waiters.append(ticket)
            try:
                while self._held or self._waiters[0] is not ticket:
                    if cancelled is not None and cancelled.is_set():
                        return False
                    remaining = (
                        None if deadline is None else deadline - time.monotonic()
                    )
                    if not blocking or (remaining is not None and remaining <= 0):
                        return False
                    wait_seconds = (
                        remaining if cancelled is None else
                        min(remaining, LINKING_CANCELLATION_POLL_SECONDS)
                        if remaining is not None else
                        LINKING_CANCELLATION_POLL_SECONDS
                    )
                    self._condition.wait(wait_seconds)
                if cancelled is not None and cancelled.is_set():
                    return False
                self._held = True
                return True
            finally:
                self._waiters.remove(ticket)
                self._condition.notify_all()

    def release(self) -> None:
        """Release exactly one existing owner and admit the next waiting call.

        Returns:
            None: Wakes waiters; raises ValueError for a duplicate release.
        """
        with self._condition:
            if not self._held:
                raise ValueError('Request gate released without an owner.')
            self._held = False
            self._condition.notify_all()

    def locked(self) -> bool:
        """Observe whether a request currently owns the serial slot.

        Returns:
            bool: True only for an admitted unfinished request.
        """
        with self._condition:
            return self._held


_LINKING_TRANSPORT_SLOTS = _FairRequestGate()

# Purpose: Limit memory consumed by a linking response body.
# Type: int. Units: bytes. Scope: one discovery, push, token, identity or
# revoke response.
# Consumers: _request_json. Rationale: 8192 bytes fits discovery and linking
# protocol data while keeping parsing memory bounded.
LINKING_RESPONSE_MAX_BYTES = 8192

# Purpose: Bound one authorized gallery page including escaped Unicode titles.
# Type: int. Units: bytes. Scope: one gallery-list JSON response.
# Consumers: _request_json_once. Rationale: 196608 bytes fits 50 titles of up
# to 255 characters even when each supplementary character uses JSON escapes.
LINKING_GALLERIES_RESPONSE_MAX_BYTES = 196608
# Purpose: Bound one optional complete gallery-tree response independently.
# Type: int. Units: response bytes. Scope: one tree-v1 JSON request only.
# Consumers: _request_json_once. Rationale: Match the server's four-MiB cap.
LINKING_GALLERY_TREE_RESPONSE_MAX_BYTES = 4 * 1024 * 1024

# Purpose: Bound ranked choices with their authorized parent titles.
# Type: int. Units: bytes. Scope: a native picker POST response.
# Consumers: _request_json_once. Rationale: 512 KiB fits 50 rows with up
# to five 255-character titles including JSON escapes and row fields.
LINKING_PICKER_RESPONSE_MAX_BYTES = 512 * 1024

# Purpose: Bound one linked inventory or upload JSON response.
# Type: int. Units: bytes. Scope: one delegated upload API response.
# Consumers: _request_json_once. Rationale: four MiB accommodates a bounded
# inventory of 5000 entries while preventing unbounded server responses.
LINKING_UPLOAD_RESPONSE_MAX_BYTES = 4194304

# Purpose: Bound JSON inventory input before it enters an HTTPS worker.
# Type: int. Units: bytes. Scope: one delegated inventory POST.
# Consumers: LinkingSession.inventory. Rationale: two MiB matches the server
# request limit and prevents a rejected body from consuming worker memory.
LINKING_INVENTORY_REQUEST_MAX_BYTES = 2097152

# Purpose: Bound candidate descriptors in one delegated inventory call.
# Type: int. Units: file descriptors. Scope: one inventory request.
# Consumers: LinkingSession.inventory. Rationale: 5000 matches the server's
# existing candidate cap; the encoded body is independently capped at two MiB.
LINKING_INVENTORY_CANDIDATE_MAX = 5000

# Purpose: Keep each delegated inventory request within the server match budget.
# Type: int. Units: unique candidates. Scope: one linked inventory HTTP request.
# Consumers: LinkingSession.inventory. Rationale: eight is the server's maximum
# verified positive matches per request, including the all-positive case.
LINKING_INVENTORY_BATCH_SIZE = 8

# Purpose: Avoid exhausting the server's positive-match read budget in a batch.
# Type: int. Units: declared candidate bytes. Scope: one linked inventory request.
# Consumers: LinkingSession.inventory. Rationale: 134217728 is the server's
# 128 MiB combined verification budget; oversized single candidates remain alone
# so a definitely unindexed file can still be uploaded.
LINKING_INVENTORY_BATCH_BYTES = 134217728

# Purpose: Bound an optional multi-request preload without partial publication.
# Type: int. Units: seconds. Scope: one linked inventory operation.
# Consumers: LinkingSession.inventory. Rationale: after 30 seconds no further
# batch begins and late replies are discarded. One admitted batch can continue
# through its 20-second session wait and 12-second HTTP deadline plus OS delays.
LINKING_INVENTORY_TOTAL_SECONDS = 30

# Purpose: Match the server's bounded single-file recovery scan.
# Type: int. Units: bytes. Scope: one explicit uncertain-upload observation.
# Consumers: LinkingSession.probe_uncertain_upload. Rationale: 67108864 is the
# server's 64 MiB maximum and avoids a request that cannot produce a match.
LINKING_RECOVERY_MAX_FILE_BYTES = 67108864

# Purpose: Bound the number of gallery rows retained from one server page.
# Type: int. Units: galleries. Scope: one in-memory page from this protocol.
# Consumers: LinkingSession.gallery_page. Rationale: 50 matches the server
# page limit and keeps memory and widget size independent of catalog size.
LINKING_GALLERY_PAGE_MAX = 50
# Purpose: Bound the optional complete tree before constructing client rows.
# Type: int. Units: gallery rows. Scope: one complete tree-v1 response.
# Consumers: _linked_gallery_tree. Rationale: Match the server's 10,000-row cap.
LINKING_GALLERY_TREE_MAX_ROWS = 10_000

# Purpose: Bound gallery identifiers to the server's signed PHP integer range.
# Type: int. Units: database identifier. Scope: gallery and pagination IDs.
# Consumers: LinkingSession.gallery_page, _request_json.
# Rationale: 9223372036854775807 is the largest 64-bit signed PHP integer.
LINKING_GALLERY_ID_MAX = 9223372036854775807
GALLERY_TREE_CAPABILITY = 'galleries:tree-v1'
_GALLERY_TREE_REVISION_PATTERN = re.compile(r'[a-f0-9]{64}')

# Purpose: Bound an unsigned gallery editor revision without losing precision.
# Type: int. Units: revision counter. Scope: one validated gallery detail.
# Consumers: LinkingSession.gallery_detail. Rationale: unsigned BIGINT maximum.
LINKING_GALLERY_REVISION_MAX = 18446744073709551615

# Purpose: Bound a gallery title to the server's persisted column width.
# Type: int. Units: Unicode code points. Scope: one returned gallery title.
# Consumers: LinkingSession.gallery_page. Rationale: galleries.title holds 255.
LINKING_GALLERY_TITLE_MAX_CHARS = 255

# Purpose: Bound submitted search text before sending it to the server.
# Type: int. Units: Unicode characters. Scope: one gallery picker query.
# Consumers: LinkingSession.gallery_picker_page and the native search field.
# Rationale: 120 matches the public query parser and authorized picker.
LINKING_GALLERY_QUERY_MAX_CHARS = 120

# Purpose: Bound received hierarchy information in compact choice rows.
# Type: int. Units: parent titles. Scope: one linked gallery picker result.
# Consumers: _linked_gallery_page.
# Rationale: four is the server's breadcrumb projection limit.
LINKING_GALLERY_ANCESTORS_MAX = 4

# Purpose: Reject impossible picker hierarchy depth before rendering.
# Type: int. Units: parent edges. Scope: one picker row in a complete catalog.
# Consumers: _linked_gallery_page. Rationale: 10000 matches the catalog cap.
LINKING_GALLERY_DEPTH_MAX = 10000

# Purpose: Match the server's UTF-8 byte limit for a requested gallery title.
# Type: int. Units: UTF-8 bytes. Scope: each explicit gallery creation.
# Consumers: LinkingSession.gallery_create_request, GalleryCreateJournal.
# Rationale: 255 bytes matches the server's bounded create admission policy.
LINKING_CREATE_TITLE_MAX_BYTES = 255

# Purpose: Limit how long a received bearer can be retained as locally usable.
# Type: int. Units: seconds. Scope: one scoped in-memory session.
# Consumers: LinkingAttempt.exchange_once.
# Rationale: 86400 seconds rejects implausible server lifetimes beyond one day.
LINKING_MAX_TOKEN_LIFETIME_SECONDS = 86400

# Purpose: Bound a returned Admin name to its existing database column width.
# Type: int. Units: Unicode characters. Scope: one identity response.
# Consumers: LinkingSession.identity. Rationale: users.username holds 190 chars.
LINKING_USERNAME_MAX_CHARS = 190


_LinkingDiagnosticPhase = Literal['discovery', 'linking', 'media']
_LinkingDiagnosticRoute = Literal[
    'app_linking_discovery', 'app_linking_push', 'app_linking_token',
    'app_linking_identity', 'app_linking_revoke', 'app_linking_galleries',
    'app_linking_gallery_tree',
    'app_linking_inventory', 'app_linking_upload', 'app_linking_gallery_detail',
    'app_linking_gallery_create', 'app_linking_gallery_create_status',
    'app_linking_gallery_create_abandon', 'app_linking_gallery_visibility',
    'app_linking_photo_visibility', 'app_linking_media_delete',
    'app_linking_media',
]
_LinkingDiagnosticContent = Literal['json', 'html', 'other']
_LinkingAvailability = Literal['true', 'false', 'unknown']
_LinkingUnavailableReason = Literal[
    'server_unavailable', 'scope_unavailable', 'request_unavailable',
]
_LinkingDiagnosticReason = Literal[
    'http_status', 'content_type', 'response_policy', 'response_size',
    'invalid_json', 'invalid_shape', 'dns_failure', 'connect_failure',
    'tls_failure', 'timeout', 'transport_failure', 'throttled',
]
_MediaPolicyState = Literal[
    'expected', 'missing', 'multiple', 'different',
]
_MediaPolicyMarkerState = Literal[
    'expected', 'missing', 'multiple', 'different', 'not_requested',
]

# Purpose: Bound numeric max-age values retained only in rejection diagnostics.
# Type: int. Units: seconds. Scope: one reported media Cache-Control value.
# Consumers: MediaPolicyFacts and _native_media_policy_facts.
# Rationale: Values beyond signed 32-bit seconds are reported as unknown; this
# display bound does not set cache TTL or authorization policy.
MEDIA_POLICY_FACT_MAX_AGE_SECONDS = 2_147_483_647


@dataclass(frozen=True)
class MediaPolicyFacts:
    """Hold only finite parsed facts from one rejected media response.

    Attributes:
        cache_control (_MediaPolicyState): Whether the response directive
            exactly matches the expected policy or is absent, repeated or different.
        cache_marker (_MediaPolicyMarkerState): Whether the requested cache marker
            is exact, absent, repeated, different or was not requested.
        private (bool): Whether a private directive name was present.
        must_revalidate (bool): Whether a revalidation directive name was present.
        max_age_seconds (int | None): One bounded parsed max-age, if unambiguous.
        vary_auth (bool): Whether Vary contains authorization.
        vary_marker (bool): Whether Vary contains the requested cache marker.
        vary_wildcard (bool): Whether Vary contains the wildcard token.
        preload_marker (_MediaPolicyMarkerState): Status of the requested preload marker.
        vary_preload (bool): Whether Vary contains the preload marker.
        encoding_present (bool): Whether Content-Encoding was supplied.
        binary_check (Literal['none', 'length', 'validator', 'framing', 'body_length', 'digest']):
            Fixed binary refusal stage without header values or media content.
    """

    cache_control: _MediaPolicyState
    cache_marker: _MediaPolicyMarkerState
    private: bool
    must_revalidate: bool
    max_age_seconds: int | None
    vary_auth: bool
    vary_marker: bool
    vary_wildcard: bool
    preload_marker: _MediaPolicyMarkerState
    vary_preload: bool
    encoding_present: bool
    binary_check: Literal[
        'none', 'length', 'validator', 'framing', 'body_length', 'digest',
    ] = 'none'

    def __post_init__(self) -> None:
        """Reject values outside the fixed enum, boolean and numeric fields.

        Returns:
            None: Invalid facts cannot enter an error or a safe summary.

        Raises:
            ValueError: A field is not one of its declared safe values.
        """
        states = ('expected', 'missing', 'multiple', 'different')
        marker_states = (*states, 'not_requested')
        if (type(self.cache_control) is not str
                or self.cache_control not in states
                or any(
                    type(value) is not str or value not in marker_states
                    for value in (self.cache_marker, self.preload_marker)
                )):
            raise ValueError('Invalid media policy diagnostic state.')
        if any(type(value) is not bool for value in (
            self.private, self.must_revalidate, self.vary_auth,
            self.vary_marker, self.vary_wildcard, self.vary_preload,
            self.encoding_present,
        )):
            raise ValueError('Invalid media policy diagnostic flag.')
        if (type(self.binary_check) is not str or self.binary_check not in (
            'none', 'length', 'validator', 'framing', 'body_length', 'digest',
        )):
            raise ValueError('Invalid media binary diagnostic stage.')
        if (self.max_age_seconds is not None and (
            type(self.max_age_seconds) is not int
            or not 0 <= self.max_age_seconds
            <= MEDIA_POLICY_FACT_MAX_AGE_SECONDS
        )):
            raise ValueError('Invalid media policy max-age diagnostic.')


@dataclass
class MediaReadTimings:
    """Accumulate numeric observations for one caller-owned image read.

    Attributes:
        source (Literal['unknown', 'disk', 'network', 'revalidated']): Accepted
            byte source, or unknown until the read passes normal policy.
        disk_read_ms (float): Cache lookup and verified disk-read milliseconds.
        disk_write_ms (float): Cache publication/revalidation milliseconds.
        gate_wait_ms (float): Session request-gate admission milliseconds.
        slot_wait_ms (float): Process transport-slot admission milliseconds.
        encoded_bytes (int): Encoded response-body bytes actually received.
        cache_stored (bool): Whether the owning cache confirmed publication.
        stored_bytes (int): Body bytes in that confirmed publication only.
        cache_inventory_scans (int): Whole-pool metadata traversals.
        cache_stat_calls (int): Accounted cache metadata observations.
        cache_sorts (int): Whole-pool in-memory sorts.
        cache_evictions (int): Confirmed ordinary removals for quota.
        cache_writes (int): Atomic file replacements.
        cache_flushes (int): Completed file flush/fsync operations.
        cache_hashes (int): Full-body SHA-256 integrity operations.
        cache_hash_bytes (int): Body bytes passed through those hashes.
        cache_reads (int): Full-body cache reads.
        cache_hash_ns (int): SHA-256 nanoseconds for bounded stored bodies.
        cache_inventory_ns (int): Recovery metadata traversal nanoseconds.
        cache_eviction_ns (int): Quota unlink nanoseconds.
        cache_write_ns (int): Header/body file-write nanoseconds.
        cache_flush_ns (int): File flush/fsync nanoseconds.
        cache_index_ns (int): SQLite open/recovery/publication nanoseconds,
            overlapping inventory time.
        cache_replace_ns (int): Atomic file replacement nanoseconds.
        jpeg_responses (int): Complete validated JPEG response bodies.
        png_responses (int): Complete validated PNG response bodies.
        gif_responses (int): Complete validated GIF response bodies.
        webp_responses (int): Complete validated WebP response bodies.
        renewal_ms (float): Credential-renewal check and work milliseconds.
        network_ms (float): Media transport admission, transfer and validation
            milliseconds; excludes the session gate and renewal work.
        network_reads (int): Media transport attempts, including 304 retries.
        http_requests (int): GET send attempts, including a stale-socket retry.
        connections (int): Newly connected TCP sockets.
        tls_handshakes (int): Attempted TLS handshakes on fresh sockets.
        connection_reuses (int): GETs borrowing a retained socket.
        context_ms (float): Certificate-verifying SSLContext setup milliseconds.
        dns_ms (float): Address resolution milliseconds.
        tcp_ms (float): TCP connection milliseconds.
        tls_ms (float): TLS wrapping and handshake milliseconds.
        ttfb_ms (float): GET send through response-header receipt milliseconds.
        body_ms (float): Response validation/body-read milliseconds.
        cache_state (Literal['miss', 'hit', 'revalidation', 'ineligible',
            'changed', 'expired']): Passive outcome of a cache-only lookup.

    This passive record contains no media, credentials or identities. Its
    counters never authorize cache reuse, transport admission or a retry.
    """

    source: Literal['unknown', 'disk', 'network', 'revalidated'] = 'unknown'
    disk_read_ms: float = 0.0
    disk_write_ms: float = 0.0
    gate_wait_ms: float = 0.0
    slot_wait_ms: float = 0.0
    encoded_bytes: int = 0
    cache_stored: bool = False
    stored_bytes: int = 0
    cache_inventory_scans: int = 0
    cache_stat_calls: int = 0
    cache_sorts: int = 0
    cache_evictions: int = 0
    cache_writes: int = 0
    cache_flushes: int = 0
    cache_hashes: int = 0
    cache_hash_bytes: int = 0
    cache_reads: int = 0
    cache_hash_ns: int = 0
    cache_inventory_ns: int = 0
    cache_eviction_ns: int = 0
    cache_write_ns: int = 0
    cache_flush_ns: int = 0
    cache_index_ns: int = 0
    cache_replace_ns: int = 0
    jpeg_responses: int = 0
    png_responses: int = 0
    gif_responses: int = 0
    webp_responses: int = 0
    renewal_ms: float = 0.0
    network_ms: float = 0.0
    network_reads: int = 0
    http_requests: int = 0
    connections: int = 0
    tls_handshakes: int = 0
    connection_reuses: int = 0
    context_ms: float = 0.0
    dns_ms: float = 0.0
    tcp_ms: float = 0.0
    tls_ms: float = 0.0
    ttfb_ms: float = 0.0
    body_ms: float = 0.0
    cache_state: Literal[
        'miss', 'hit', 'revalidation', 'ineligible', 'changed', 'expired',
    ] = 'miss'

    def observe_cache(
        self, cache: GalleryMediaCache,
        previous: dict[str, int] | None = None,
    ) -> dict[str, int]:
        """Accumulate performed cache work, including unsuccessful operations.

        Args:
            cache (GalleryMediaCache): This read's exact physical cache owner.
            previous (dict[str, int] | None): Previously counted fixed numeric
                fields from this instance, or None before its first observation.
        Returns:
            dict[str, int]: Current counters for a subsequent delta observation.
        """
        current = cache.operation_counts()
        for name, value in current.items():
            field_name = 'cache_' + name
            if hasattr(self, field_name):
                setattr(
                    self, field_name,
                    getattr(self, field_name) + value
                    - (previous.get(name, 0) if previous else 0),
                )
        return current


class LinkingError(RuntimeError):
    """A linking attempt cannot continue without exposing protocol secrets."""


class LinkingResponseError(LinkingError):
    """Carry a bounded response diagnosis without retaining response contents.

    Args:
        phase (_LinkingDiagnosticPhase): Fixed linking operation phase.
        route (_LinkingDiagnosticRoute): Fixed registered endpoint name.
        status (int | None): HTTP status, or None if no response arrived.
        content_kind (_LinkingDiagnosticContent): Coarse response media class.
        reason (_LinkingDiagnosticReason): Fixed protocol refusal category.
        media_policy_facts (MediaPolicyFacts | None): Optional finite facts
            attached only to a media response-policy refusal.
    """

    def __init__(
        self, phase: _LinkingDiagnosticPhase,
        route: _LinkingDiagnosticRoute, status: int | None,
        content_kind: _LinkingDiagnosticContent,
        reason: _LinkingDiagnosticReason,
        *, media_policy_facts: MediaPolicyFacts | None = None,
    ) -> None:
        """Store only validated diagnostic fields and a safe display string.

        Args:
            phase (_LinkingDiagnosticPhase): Fixed linking operation phase.
            route (_LinkingDiagnosticRoute): Fixed registered endpoint name.
            status (int | None): HTTP status, or None if no response arrived.
            content_kind (_LinkingDiagnosticContent): Coarse response media class.
            reason (_LinkingDiagnosticReason): Fixed protocol refusal category.
            media_policy_facts (MediaPolicyFacts | None): Optional allowlisted
                parsed facts from the rejected media response headers.

        Returns:
            None: Stores no response body, header value, URL or exception text.

        Raises:
            ValueError: One diagnostic field is outside its fixed allowlist.
        """
        if (phase not in ('discovery', 'linking', 'media')
                or route not in (
                    'app_linking_discovery', 'app_linking_push',
                    'app_linking_token', 'app_linking_identity',
                    'app_linking_revoke', 'app_linking_galleries',
                    'app_linking_gallery_tree',
                    'app_linking_inventory', 'app_linking_upload',
                    'app_linking_gallery_detail', 'app_linking_gallery_create',
                    'app_linking_gallery_create_status',
                    'app_linking_gallery_create_abandon',
                    'app_linking_gallery_visibility',
                    'app_linking_photo_visibility', 'app_linking_media_delete',
                    'app_linking_media',
                )
                or (status is not None and (
                    type(status) is not int or not 100 <= status <= 599
                ))
                or content_kind not in ('json', 'html', 'other')
                or reason not in (
                    'http_status', 'content_type', 'response_policy',
                    'response_size', 'invalid_json', 'invalid_shape',
                    'dns_failure', 'connect_failure', 'tls_failure', 'timeout',
                    'transport_failure', 'throttled',
                )
                or (media_policy_facts is not None and (
                    type(media_policy_facts) is not MediaPolicyFacts
                    or reason != 'response_policy'
                    or phase != 'media'
                ))
                ):
            raise ValueError('Invalid safe linking diagnostic.')
        self.phase = phase
        self.route = route
        self.status = status
        self.content_kind = content_kind
        self.reason = reason
        self.media_policy_facts = (
            None if media_policy_facts is None else MediaPolicyFacts(
                media_policy_facts.cache_control,
                media_policy_facts.cache_marker,
                media_policy_facts.private,
                media_policy_facts.must_revalidate,
                media_policy_facts.max_age_seconds,
                media_policy_facts.vary_auth,
                media_policy_facts.vary_marker,
                media_policy_facts.vary_wildcard,
                media_policy_facts.preload_marker,
                media_policy_facts.vary_preload,
                media_policy_facts.encoding_present,
                media_policy_facts.binary_check,
            )
        )
        status_text = 'none' if status is None else str(status)
        self.safe_summary = (
            f'stage={phase}; route={route}; http={status_text}; '
            f'response_kind={content_kind}; reason={reason}'
        )
        if self.media_policy_facts is not None:
            facts = self.media_policy_facts
            max_age = (
                'none' if facts.max_age_seconds is None
                else str(facts.max_age_seconds)
            )
            self.safe_summary += (
                f'; policy=cc:{facts.cache_control};cm:{facts.cache_marker}'
                f';p:{int(facts.private)};mr:{int(facts.must_revalidate)}'
                f';ma:{max_age};va:{int(facts.vary_auth)}'
                f';vm:{int(facts.vary_marker)};vw:{int(facts.vary_wildcard)}'
                f';pm:{facts.preload_marker};vp:{int(facts.vary_preload)}'
                f';enc:{int(facts.encoding_present)}'
            )
            if facts.binary_check != 'none':
                self.safe_summary += f';binary:{facts.binary_check}'
        super().__init__(self.safe_summary)


class LinkingHttpsError(LinkingError):
    """Report a TLS failure without returning raw certificate or socket errors."""


class LinkingMediaUnavailable(LinkingError):
    """Report an authenticated gallery media target that is unavailable."""


class LinkingRevisionUnsupported(LinkingError):
    """Report an older server that rejects the revision selector."""


class LinkingMediaThrottled(LinkingResponseError):
    """Report a validated media throttle and optional safe retry delay.

    Args:
        retry_after_seconds (int | None): Validated bounded server delay, if sent.
    """

    def __init__(self, retry_after_seconds: int | None = None) -> None:
        """Create a throttle failure without preserving raw server text.

        Args:
            retry_after_seconds (int | None): Bounded integer delay from one
                unambiguous Retry-After header, or None when unavailable.

        Returns:
            None: Stores the safe delay and fixed error message.
        """
        super().__init__(
            'media', 'app_linking_media', 429, 'json', 'throttled',
        )
        if (retry_after_seconds is not None
                and (type(retry_after_seconds) is not int
                     or not 1 <= retry_after_seconds <= MEDIA_RETRY_AFTER_MAX_SECONDS)):
            retry_after_seconds = None
        self.args = ('Photo requests are limited. Try again shortly.',)
        self.retry_after_seconds = retry_after_seconds


class LinkingGrantRejected(LinkingError):
    """Report a revoked or expired authenticated linking grant."""


class LinkingRefreshRejected(LinkingError):
    """Report a definite invalid-grant response to an exact refresh request."""


class LinkingUnavailable(LinkingError):
    """The selected installation does not advertise the requested linking flow."""

    def __init__(
        self, message: str = 'The linking request is unavailable.', *,
        availability: _LinkingAvailability = 'unknown',
        status: int | None = None,
        content_kind: _LinkingDiagnosticContent = 'other',
        reason: _LinkingUnavailableReason = 'request_unavailable',
    ) -> None:
        """Retain only fixed discovery facts for a safe diagnostic summary.

        Args:
            message (str): Fixed internal user-facing failure description.
            availability (_LinkingAvailability): Validated
                discovery availability, never inferred from a failed request.
            status (int | None): Validated HTTP status when a response was read.
            content_kind (_LinkingDiagnosticContent): Coarse response category.
            reason (_LinkingUnavailableReason): Fixed internal refusal class.

        Returns:
            None: Stores the original fixed message and a safe summary only.

        Raises:
            ValueError: Diagnostic fields are outside their fixed allowlists.
        """
        reasons = {
            'server_unavailable', 'scope_unavailable', 'request_unavailable',
        }
        if (availability not in ('true', 'false', 'unknown')
                or reason not in reasons
                or (status is not None
                    and (type(status) is not int or not 100 <= status <= 599))
                or content_kind not in ('json', 'html', 'other')):
            raise ValueError('Invalid safe linking availability diagnostic.')
        self.availability = availability
        self.status = status
        self.content_kind = content_kind
        self.reason = reason
        status_text = 'none' if status is None else str(status)
        self.safe_summary = (
            'stage=discovery; route=app_linking_discovery; '
            f'availability={availability}; http={status_text}; '
            f'response_kind={content_kind}; reason={reason}'
        )
        super().__init__(message)


class LinkingScheduledPublication(LinkingError):
    """Report a server-confirmed refusal before a scheduled gallery write."""


class LinkingRevisionConflict(LinkingError):
    """Report a server-confirmed stale revision before any gallery write."""


@dataclass(frozen=True, repr=False)
class PushedAuthorization:
    """Hold a short-lived nonauthorizing browser request handle.

    Args:
        request_handle (str): Server correlation handle, never user-visible.
        authorization_url (str): Exact HTTPS browser approval page.
        expires_at (float): Conservative monotonic browser handoff deadline.
    """

    request_handle: str = field(repr=False)
    authorization_url: str
    expires_at: float


@dataclass(frozen=True, repr=False)
class ExchangeParameters:
    """One-shot exchange input; its representation never displays secrets.

    Args:
        issuer (str): Previously trusted exact installation origin and mount.
        redirect_uri (str): Exact callback URI bound to the authorization.
        code (str): One-time authorization code; never log or persist it.
        verifier (str): PKCE verifier; never log or persist it.
        scope (str): Exact scope bundle approved for this attempt.
    """

    issuer: str
    redirect_uri: str
    code: str = field(repr=False)
    verifier: str = field(repr=False)
    scope: str


def scope_allows_admin_media(scope: str) -> bool:
    """Recognize exact Admin photo bundles with independent browser consent.

    Args:
        scope (str): Full current grant scope.

    Returns:
        bool: True for a registered temporary or rolling Admin photo grant.
    """
    return scope in ADMIN_MEDIA_SCOPES


def _scope_without_media(scope: str) -> str:
    """Remove one canonical transient media extension before scope matching.

    Args:
        scope (str): Exact scope proposed or returned by the server.

    Returns:
        str: Underlying bundle, or the original when no suffix is present.
    """
    if scope_allows_admin_media(scope):
        base = ADMIN_MEDIA_SCOPES[scope]
        return base
    if scope in ROLLING_PRESENTATION_SCOPES:
        suffix = PRESENTATION_SCOPE_SUFFIX + ROLLING_SCOPE_SUFFIX
        return scope[:-len(suffix)] + ROLLING_SCOPE_SUFFIX
    for suffix in (PRESENTATION_SCOPE_SUFFIX, MEDIA_SCOPE_SUFFIX):
        if scope.endswith(suffix):
            return scope.removesuffix(suffix)
    return scope


def scope_base(scope: str) -> str | None:
    """Resolve only a registered exact linking scope to its base permission.

    Args:
        scope (str): Full server-approved scope including optional offline access.

    Returns:
        str | None: Exact base scope, or None when the bundle is unregistered.
    """
    underlying = _scope_without_media(scope)
    if underlying != scope and scope not in ROLLING_PRESENTATION_SCOPES and (
        underlying == IDENTITY_SCOPE or underlying.endswith(' offline_access')
        or underlying in GALLERY_SEARCH_SCOPES
    ):
        return None
    return {
        IDENTITY_SCOPE: IDENTITY_SCOPE,
        GALLERIES_SCOPE: GALLERIES_SCOPE,
        UPLOAD_SCOPE: UPLOAD_SCOPE,
        GALLERY_SEARCH_SCOPES[0]: GALLERIES_SCOPE,
        GALLERY_SEARCH_SCOPES[1]: UPLOAD_SCOPE,
        IDENTITY_OFFLINE_SCOPE: IDENTITY_SCOPE,
        GALLERIES_OFFLINE_SCOPE: GALLERIES_SCOPE,
        UPLOAD_OFFLINE_SCOPE: UPLOAD_SCOPE,
        ROLLING_GALLERIES_SCOPE: GALLERIES_SCOPE,
        ROLLING_UPLOAD_SCOPE: UPLOAD_SCOPE,
        ROLLING_GALLERY_SEARCH_SCOPES[0]: GALLERIES_SCOPE,
        ROLLING_GALLERY_SEARCH_SCOPES[1]: UPLOAD_SCOPE,
        READ_SCOPE: GALLERIES_SCOPE,
        READ_OFFLINE_SCOPE: GALLERIES_SCOPE,
        READ_UPLOAD_SCOPE: UPLOAD_SCOPE,
        READ_UPLOAD_OFFLINE_SCOPE: UPLOAD_SCOPE,
        CREATE_SCOPE: GALLERIES_SCOPE,
        READ_CREATE_SCOPE: GALLERIES_SCOPE,
        UPLOAD_CREATE_SCOPE: UPLOAD_SCOPE,
        READ_UPLOAD_CREATE_SCOPE: UPLOAD_SCOPE,
        CREATE_OFFLINE_SCOPE: GALLERIES_SCOPE,
        READ_CREATE_OFFLINE_SCOPE: GALLERIES_SCOPE,
        UPLOAD_CREATE_OFFLINE_SCOPE: UPLOAD_SCOPE,
        READ_UPLOAD_CREATE_OFFLINE_SCOPE: UPLOAD_SCOPE,
        VISIBILITY_SCOPE: GALLERIES_SCOPE,
        UPLOAD_VISIBILITY_SCOPE: UPLOAD_SCOPE,
        CREATE_VISIBILITY_SCOPE: GALLERIES_SCOPE,
        UPLOAD_CREATE_VISIBILITY_SCOPE: UPLOAD_SCOPE,
        VISIBILITY_OFFLINE_SCOPE: GALLERIES_SCOPE,
        UPLOAD_VISIBILITY_OFFLINE_SCOPE: UPLOAD_SCOPE,
        CREATE_VISIBILITY_OFFLINE_SCOPE: GALLERIES_SCOPE,
        UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE: UPLOAD_SCOPE,
    }.get(underlying)


def scope_allows_gallery_search(scope: str) -> bool:
    """Check the registered bundles granting metadata search.

    Args:
        scope (str): Exact scope held by the linked session.

    Returns:
        bool: True when the grant explicitly includes metadata search.
    """
    if scope_allows_admin_media(scope):
        return GALLERY_SEARCH_SUFFIX + ADMIN_MEDIA_SCOPE_SUFFIX in scope
    return scope in (
        *GALLERY_SEARCH_SCOPES, *ROLLING_GALLERY_SEARCH_SCOPES,
        *ROLLING_SEARCH_PRESENTATION_SCOPES,
    )


def scope_allows_presentation(scope: str) -> bool:
    """Check for an exact temporary or rolling presentation grant.

    Args:
        scope (str): Exact scope held by the linked session.

    Returns:
        bool: True when the grant explicitly includes presentation and music.
    """
    if scope_allows_admin_media(scope):
        return ADMIN_PRESENTATION_SCOPE_SUFFIX in scope
    if scope in ROLLING_PRESENTATION_SCOPES:
        return True
    base = _scope_without_media(scope)
    return (
        scope == base + PRESENTATION_SCOPE_SUFFIX
        and base not in (IDENTITY_SCOPE, *GALLERY_SEARCH_SCOPES)
        and not base.endswith(' offline_access')
        and _scope_without_media(base) == base
        and scope_base(base) is not None
    )


def scope_allows_gallery_read(scope: str) -> bool:
    """Recognize only explicitly approved gallery-detail scope bundles.

    Args:
        scope (str): Exact server-approved transient or offline scope.

    Returns:
        bool: True only when gallery detail was explicitly consented.
    """
    scope = _scope_without_media(scope)
    return scope in (READ_SCOPE, READ_UPLOAD_SCOPE, READ_OFFLINE_SCOPE,
                     READ_UPLOAD_OFFLINE_SCOPE, READ_CREATE_SCOPE,
                     READ_UPLOAD_CREATE_SCOPE, READ_CREATE_OFFLINE_SCOPE,
                     READ_UPLOAD_CREATE_OFFLINE_SCOPE) or scope in VISIBILITY_SCOPES


def scope_allows_gallery_create(scope: str) -> bool:
    """Recognize only explicitly approved gallery-creation scope bundles.

    Args:
        scope (str): Exact server-approved transient or offline scope.

    Returns:
        bool: True only when gallery creation was explicitly consented.
    """
    scope = _scope_without_media(scope)
    return scope in (CREATE_SCOPE, READ_CREATE_SCOPE, UPLOAD_CREATE_SCOPE,
                     READ_UPLOAD_CREATE_SCOPE, CREATE_OFFLINE_SCOPE,
                     READ_CREATE_OFFLINE_SCOPE, UPLOAD_CREATE_OFFLINE_SCOPE,
                     READ_UPLOAD_CREATE_OFFLINE_SCOPE,
                     CREATE_VISIBILITY_SCOPE, UPLOAD_CREATE_VISIBILITY_SCOPE,
                     CREATE_VISIBILITY_OFFLINE_SCOPE,
                     UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE)


def scope_allows_gallery_visibility(scope: str) -> bool:
    """Recognize only exact bundles explicitly permitting visibility changes.

    Args:
        scope (str): Exact server-approved transient or offline scope.

    Returns:
        bool: True only for an approved visibility bundle.
    """
    return _scope_without_media(scope) in VISIBILITY_SCOPES


def _ipv6_issuer_supported(address: ipaddress.IPv6Address) -> bool:
    """Reject IPv6 forms whose IPv4 suffix has ambiguous URL serialization.

    Args:
        address (ipaddress.IPv6Address): Parsed IPv6 issuer address.

    Returns:
        bool: False for IPv4-mapped or deprecated IPv4-compatible addresses,
            while preserving the unspecified and loopback IPv6 addresses.
    """
    return (address.ipv4_mapped is None
            and (address not in IPV4_COMPATIBLE_NETWORK
                 or address in (ipaddress.IPv6Address('::'),
                                ipaddress.IPv6Address('::1'))))


def _issuer_valid(issuer: str, *, allow_local_http: bool = True) -> bool:
    """Match the server's canonical issuer syntax without establishing trust.

    Args:
        issuer (str): Independently selected origin and installation mount.
        allow_local_http (bool): Allow the direct localhost issuer syntax.

    Returns:
        bool: True only when the configured issuer uses accepted syntax.
    """
    try:
        if len(issuer.encode('ascii')) > ISSUER_MAX_BYTES:
            return False
        parsed = urlsplit(issuer)
        local_http = (allow_local_http and parsed.scheme == 'http'
                      and parsed.hostname == 'localhost')
        if ((parsed.scheme != 'https' and not local_http)
                or not parsed.netloc or parsed.query
                or parsed.fragment or parsed.username is not None
                or parsed.password is not None
                or issuer != f'{parsed.scheme}://{parsed.netloc}{parsed.path}'):
            return False
        if not re.fullmatch(r'(?:/[A-Za-z0-9._~-]+)*', parsed.path):
            return False
        if any(segment in ('.', '..') for segment in parsed.path.split('/')):
            return False
        authority = parsed.netloc
        if authority.startswith('['):
            match = re.fullmatch(r'\[([0-9a-f:.]+)\](?::([1-9][0-9]{0,4}))?', authority)
            if not match:
                return False
            address = ipaddress.IPv6Address(match.group(1))
            if (not _ipv6_issuer_supported(address)
                    or address.compressed != match.group(1)):
                return False
            port_text = match.group(2)
        else:
            match = re.fullmatch(
                r'([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?'
                r'(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)'
                r'(?::([1-9][0-9]{0,4}))?', authority,
            )
            if not match or len(match.group(1)) > ISSUER_DNS_MAX_BYTES:
                return False
            host = match.group(1)
            final_label = host.rsplit('.', 1)[-1]
            if re.fullmatch(r'(?:[0-9]+|0x[0-9a-f]*)', final_label):
                # Browsers interpret numeric final labels as IPv4, including
                # shortened, octal or hexadecimal forms. Keep only the exact
                # four-part decimal spelling accepted by both transports.
                try:
                    if str(ipaddress.IPv4Address(host)) != host:
                        return False
                except ipaddress.AddressValueError:
                    return False
            port_text = match.group(2)
        # The default port is omitted from the shared canonical issuer;
        # TCP's 16-bit port field remains a protocol boundary for other ports.
        default_port = http.client.HTTP_PORT if local_http else LINKING_HTTPS_DEFAULT_PORT
        return (port_text is None
                or int(port_text) != default_port and int(port_text) <= 65535)
    except (UnicodeError, ValueError):
        return False


def linking_issuer(value: str) -> str:
    """Derive a canonical HTTPS or direct localhost HTTP issuer.

    Args:
        value (str): User-entered gallery root or supported upload endpoint.

    Returns:
        str: Validated canonical origin and mount, or an empty string
            when the address cannot be represented without ambiguous changes.
    """
    url = discovery_url(value, allow_local_http=True)
    if not url:
        return ''
    parsed = urlsplit(url)
    suffix = '/index.php'
    if not parsed.path.endswith(suffix):
        return ''
    authority = parsed.netloc.lower()
    if authority.startswith('['):
        closing = authority.find(']')
        try:
            address = ipaddress.IPv6Address(authority[1:closing])
        except ValueError:
            return ''
        # IPv4-bearing IPv6 can have different native and URL spellings.
        if not _ipv6_issuer_supported(address):
            return ''
        authority = '[' + address.compressed + ']' + authority[closing + 1:]
    default_port = (http.client.HTTP_PORT if parsed.scheme == 'http'
                    else LINKING_HTTPS_DEFAULT_PORT)
    default_suffix = f':{default_port}'
    if parsed.port == default_port and authority.endswith(default_suffix):
        authority = authority[:-len(default_suffix)]
    issuer = f'{parsed.scheme}://{authority}' + parsed.path[:-len(suffix)]
    return issuer if _issuer_valid(issuer) else ''


def _canonical_256bit(value: str) -> bool:
    """Validate one canonical unpadded 32-byte base64url value.

    Args:
        value (str): Candidate ASCII base64url value.

    Returns:
        bool: True only for an exact canonical encoding of 32 bytes.
    """
    if (len(value) != BASE64URL_256_LENGTH
            or re.fullmatch(r'[A-Za-z0-9_-]+', value) is None):
        return False
    try:
        decoded = base64.b64decode(value + '=', altchars=b'-_', validate=True)
    except (binascii.Error, ValueError, UnicodeError):
        return False
    encoded = base64.urlsafe_b64encode(decoded).rstrip(b'=').decode('ascii')
    return hmac.compare_digest(value, encoded)


def _canonical_request_handle(value: str) -> bool:
    """Validate the exact unpadded Base32 encoding of a 128-bit request handle.

    Args:
        value (str): Candidate case-sensitive ASCII handle received over HTTPS.

    Returns:
        bool: True only for the canonical uppercase encoding of 16 bytes.
    """
    if (len(value) != REQUEST_HANDLE_LENGTH
            or re.fullmatch(r'[A-Z2-7]+', value) is None):
        return False
    try:
        # Base32 encodes 16 bytes in 26 symbols plus six padding characters.
        decoded = base64.b32decode(value + '=' * 6, casefold=False)
    except (binascii.Error, ValueError):
        return False
    encoded = base64.b32encode(decoded).rstrip(b'=').decode('ascii')
    return (len(decoded) == REQUEST_HANDLE_RANDOM_BYTES
            and hmac.compare_digest(value, encoded))


def _discovery_admits_linking(
    data: dict[str, JsonValue], issuer: str, scope: str,
) -> bool:
    """Check the exact public capability envelope before sending a push.

    Args:
        data (dict[str, JsonValue]): Public discovery with integer
            contract_version and an app_linking object containing available,
            protocol_version, browser_handoff_version, issuer and the list
            of supported capability names.
        issuer (str): Canonical issuer selected for this attempt.
        scope (str): Exact registered scope bundle requested by the user.

    Returns:
        bool: True only for supported versions, issuer and requested scopes.
    """
    linking = data.get('app_linking')
    if (type(data.get('contract_version')) is not int
            or data['contract_version'] != LINKING_DISCOVERY_CONTRACT_VERSION
            or not isinstance(linking, dict)
            or linking.get('available') is not True
            or type(linking.get('protocol_version')) is not int
            or linking['protocol_version'] != LINKING_PROTOCOL_VERSION
            or type(linking.get('browser_handoff_version')) is not int
            or linking['browser_handoff_version'] != BROWSER_HANDOFF_VERSION
            or linking.get('issuer') != issuer):
        return False
    capabilities = linking.get('capabilities')
    if (not isinstance(capabilities, list)
            or any(not isinstance(value, str) for value in capabilities)):
        return False
    granted = set(capabilities)
    required = set(scope.split()) - {IDENTITY_SCOPE}
    if scope.endswith(ROLLING_SCOPE_SUFFIX):
        # Rolling has its own transport policy. Direct localhost need not
        # advertise the legacy HTTPS-only offline capability.
        required.remove('offline_access')
    if (scope in ROLLING_PRESENTATION_SCOPES
            or (scope in (*ROLLING_ADMIN_MEDIA_SCOPES, *ROLLING_ADMIN_DELETE_SCOPES)
                and scope_allows_presentation(scope))):
        required.add('presentation:rolling')
    return 'linking' in granted and required.issubset(granted)


def _preferred_admin_media_scope(
    discovery: dict[str, JsonValue], issuer: str, base: str,
    rolling: bool, presentation: bool,
) -> str | None:
    """Select the advertised normal Photos link for explicit browser consent.

    Args:
        discovery (dict[str, JsonValue]): Current installation capabilities.
        issuer (str): Exact trusted installation issuer.
        base (str): Basic gallery listing bundle with optional approved upload request.
        rolling (bool): Whether the current client can securely store renewal.
        presentation (bool): Whether native presentation support is preferred.

    Returns:
        str | None: Canonical Admin Photos bundle, or None for an older server.
    """
    if base not in (GALLERIES_SCOPE, UPLOAD_SCOPE):
        return None
    read = READ_UPLOAD_SCOPE if base == UPLOAD_SCOPE else READ_SCOPE
    create = UPLOAD_CREATE_SCOPE if base == UPLOAD_SCOPE else CREATE_SCOPE
    for candidate in (
        read + ' galleries:create gallery:visibility',
        read + ' gallery:visibility',
        read + ' galleries:create',
        read, create, base,
    ):
        for renewal in (ROLLING_SCOPE_SUFFIX, '') if rolling else ('',):
            for media in (
                ADMIN_PRESENTATION_SCOPE_SUFFIX, ADMIN_MEDIA_SCOPE_SUFFIX,
            ) if presentation else (ADMIN_MEDIA_SCOPE_SUFFIX,):
                for search in (GALLERY_SEARCH_SUFFIX, ''):
                    scope = candidate + search + media + renewal
                    if (scope_allows_admin_media(scope)
                            and _discovery_admits_linking(discovery, issuer, scope)):
                        return scope
    return None


def discover_linking_capabilities(
    issuer: str, cancelled: threading.Event | None = None,
) -> frozenset[str]:
    """Inspect capabilities for the dialog without starting authorization.

    Args:
        issuer (str): Exact canonical HTTPS installation selected by the user.
        cancelled (threading.Event | None): Dialog cancellation for the read.

    Returns:
        frozenset[str]: Announced capabilities from the supported exact contract,
            or an empty set for an absent, unavailable or incompatible server.
    """
    try:
        data = _request_json(
            issuer, 'app_linking_discovery', 'GET', None, None, cancelled=cancelled,
        )
        if _discovery_admits_linking(data, issuer, IDENTITY_SCOPE):
            return frozenset(data['app_linking']['capabilities'])
    except LinkingError:
        pass
    return frozenset()


def _callback_values(
    target: str, expected_state: str, expected_issuer: str,
) -> tuple[Literal['approved', 'denied'], str] | None:
    """Validate the exact local callback and retain no rejected input.

    Args:
        target (str): Raw ASCII origin-form request target from the local peer.
        expected_state (str): Fresh canonical state bound to this attempt.
        expected_issuer (str): Previously trusted exact installation issuer and mount.

    Returns:
        tuple[Literal['approved', 'denied'], str] | None: Approved outcome and
            code, denied outcome and empty string, or None for invalid input.
    """
    if not target.startswith(CALLBACK_PATH + '?') or '#' in target:
        return None
    query = target[len(CALLBACK_PATH) + 1:]
    if re.search(r'%(?![0-9A-Fa-f]{2})', query):
        return None
    try:
        pairs = parse_qsl(
            query, keep_blank_values=True, strict_parsing=True,
            encoding='utf-8', errors='strict', max_num_fields=3,
        )
    except (UnicodeError, ValueError):
        return None
    if len(pairs) != 3 or len({key for key, _ in pairs}) != 3:
        return None
    values = dict(pairs)
    if set(values) not in ({'code', 'state', 'iss'}, {'error', 'state', 'iss'}):
        return None
    state, issuer = values['state'], values['iss']
    try:
        issuer.encode('ascii')
    except UnicodeError:
        return None
    if (not _canonical_256bit(state)
            or not hmac.compare_digest(state, expected_state)
            or not hmac.compare_digest(issuer, expected_issuer)):
        return None
    if 'error' in values:
        return ('denied', '') if values['error'] == 'access_denied' else None
    code = values['code']
    if (not code.startswith(CODE_PREFIX)
            or not _canonical_256bit(code[len(CODE_PREFIX):])):
        return None
    return 'approved', code


def _read_request(
    connection: socket.socket, deadline: float,
) -> tuple[str, dict[str, str]] | None:
    """Read one bounded HTTP header block without reflecting local input.

    Args:
        connection (socket.socket): Connected peer on the loopback listener.
        deadline (float): Absolute monotonic deadline for the whole attempt.

    Returns:
        tuple[str, dict[str, str]] | None: ASCII request target and unique
            lowercase headers, or None for an incomplete or invalid request.
    """
    until = min(deadline, time.monotonic() + CALLBACK_CLIENT_SECONDS)
    data = bytearray()
    try:
        while b'\r\n\r\n' not in data:
            remaining = until - time.monotonic()
            if remaining <= 0 or len(data) >= CALLBACK_HEADER_MAX_BYTES:
                return None
            connection.settimeout(remaining)
            # Read one extra byte only to detect the configured size boundary.
            chunk = connection.recv(CALLBACK_HEADER_MAX_BYTES + 1 - len(data))
            if not chunk:
                return None
            data.extend(chunk)
        if len(data) > CALLBACK_HEADER_MAX_BYTES:
            return None
        header, extra = bytes(data).split(b'\r\n\r\n', 1)
        if extra:
            return None
        lines = header.decode('ascii').split('\r\n')
        method, target, version = lines[0].split(' ')
        if method != 'GET' or version != 'HTTP/1.1':
            return None
        headers: dict[str, str] = {}
        for line in lines[1:]:
            if ':' not in line or line[:1] in (' ', '\t'):
                return None
            name, value = line.split(':', 1)
            if re.fullmatch(r"[!#$%&'*+.^_`|~0-9A-Za-z-]+", name) is None:
                return None
            if any(ord(char) < 32 and char != '\t' or ord(char) == 127
                   for char in value):
                return None
            name = name.lower()
            if name in headers:
                return None
            headers[name] = value.strip()
        if ('transfer-encoding' in headers
                or headers.get('content-length', '0') != '0'):
            return None
        return target, headers
    except (OSError, UnicodeError, ValueError):
        return None


_CALLBACK_PAGE_STYLE = b"""
:root {
    color-scheme: dark;
    --canvas: #131b26;
    --surface: #223344;
    --panel: #253443;
    --ink: #f2f6fa;
    --muted: #aabccb;
    --line: #3d5264;
    --success: #46d68c;
}
@media (prefers-color-scheme: light) {
    :root {
        color-scheme: light;
        --canvas: #eef3f6;
        --surface: #dbe9ee;
        --panel: #fff;
        --ink: #182330;
        --muted: #405665;
        --line: #b9cad3;
        --success: #14764f;
    }
}
body {
    box-sizing: border-box;
    min-height: 100vh;
    display: grid;
    place-items: center;
    margin: 0;
    padding: 1rem;
    color: var(--ink);
    background: radial-gradient(ellipse at top, var(--surface), var(--canvas) 72%);
    font: 1rem/1.5 system-ui, sans-serif;
}
main {
    box-sizing: border-box;
    width: min(100%, 24rem);
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: start;
    gap: .8rem;
    padding: 1rem;
    background: var(--panel);
    background: color-mix(in srgb, var(--panel) 42%, transparent);
    border: 1px solid var(--line);
    border-radius: 8px;
    backdrop-filter: blur(12px);
}
.mark { color: var(--muted); font-size: 1.35rem; }
.approved .mark { color: var(--success); }
.brand { color: var(--muted); font-size: .8rem; }
h1 { margin: .15rem 0; font-size: 1.1rem; line-height: 1.3; }
p { margin: 0; color: var(--muted); }
"""
_CALLBACK_STYLE_HASH = base64.b64encode(
    hashlib.sha256(_CALLBACK_PAGE_STYLE).digest(),
)
_CALLBACK_PAGE_COPY = {
    'approved': ('Approval received', 'Return to WinApp to continue.', '✓'),
    'denied': ('Link cancelled', 'You can return to WinApp.', '×'),
    None: ('Link request rejected', 'Start linking again from WinApp.', '!'),
}


def _reply(
    connection: socket.socket, outcome: Literal['approved', 'denied'] | None,
    deadline: float,
) -> None:
    """Render a fixed local confirmation without reflecting callback data.

    Args:
        connection (socket.socket): Connected peer receiving the response.
        outcome (Literal['approved', 'denied'] | None): Verified browser
            decision, or None when the callback failed validation.
        deadline (float): Absolute monotonic deadline for the whole attempt.

    Returns:
        None: Writes a small no-store response or ignores a disconnected peer.
    """
    title, detail, mark = _CALLBACK_PAGE_COPY[outcome]
    page_class = 'approved' if outcome == 'approved' else 'notice'
    body = (
        b'<!doctype html><html lang="en"><head><meta charset="utf-8">'
        b'<meta name="viewport" content="width=device-width,initial-scale=1">'
        + f'<title>{title} · PHP Gallery</title>'.encode('utf-8')
        + b'<style>' + _CALLBACK_PAGE_STYLE + b'</style></head><body>'
        + (
            f'<main class="{page_class}"><span class="mark" aria-hidden="true">'
            f'{mark}</span><div><span class="brand">PHP Gallery</span>'
            f'<h1>{title}</h1><p>{detail}</p></div></main></body></html>'
        ).encode('utf-8')
    )
    status = b'200 OK' if outcome is not None else b'400 Bad Request'
    headers = (
        b'HTTP/1.1 ' + status + b'\r\n'
        b'Content-Type: text/html; charset=utf-8\r\n'
        b'Cache-Control: no-store\r\n'
        b'Referrer-Policy: no-referrer\r\n'
        b'X-Content-Type-Options: nosniff\r\n'
        b"Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'none'; "
        b"frame-ancestors 'none'; style-src 'sha256-" + _CALLBACK_STYLE_HASH + b"'\r\n"
        b'Connection: close\r\n'
        b'Content-Length: ' + str(len(body)).encode('ascii') + b'\r\n\r\n'
    )
    try:
        remaining = min(CALLBACK_CLIENT_SECONDS, deadline - time.monotonic())
        if remaining <= 0:
            return
        connection.settimeout(remaining)
        connection.sendall(headers + body)
    except OSError:
        pass


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
        'app_linking_media_delete',
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
                'app_linking_media_delete',
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
        route=route,
    )


def _run_linking_transport(
    request_once: Callable[[_LinkingTransportControl], _TransportResult],
    cancelled: threading.Event | None, deadline_seconds: float,
    inactivity_seconds: float, *,
    phase: _LinkingDiagnosticPhase = 'linking',
    route: _LinkingDiagnosticRoute = 'app_linking_discovery',
    timings: MediaReadTimings | None = None,
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

    Returns:
        _TransportResult: Strict JSON object or bounded media bytes and MIME.

    Raises:
        LinkingError: Cancelled, expired or unsafe operation.
        LinkingResponseError: A transport or protocol failure with safe metadata.
    """
    control = _LinkingTransportControl(
        time.monotonic() + deadline_seconds, cancelled, inactivity_seconds,
    )
    result: queue.Queue[
        _TransportResult | LinkingHttpsError | LinkingMediaUnavailable
        | LinkingResponseError | LinkingRevisionUnsupported
        | LinkingGrantRejected | LinkingRefreshRejected | None
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

        def worker() -> None:
            """Perform one request and retain the slot until network work ends.

            Returns:
                None: Publishes a response or a sanitized transport failure.
            """
            outcome: (
                _TransportResult | LinkingHttpsError | LinkingMediaUnavailable
                | LinkingResponseError | LinkingRevisionUnsupported
                | LinkingGrantRejected | LinkingRefreshRejected | None
            )
            try:
                control.remaining()
                outcome = request_once(control)
            except LinkingMediaThrottled as error:
                outcome = LinkingMediaThrottled(
                    error.retry_after_seconds,
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
            if isinstance(data, LinkingMediaUnavailable):
                raise data
            if isinstance(data, (LinkingGrantRejected, LinkingRefreshRejected)):
                raise data
            if data is None:
                raise LinkingError('Linking request did not complete safely.')
            return data
    finally:
        control.stop()
        if slot_acquired and not worker_started:
            release_slot_once()


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
        'app_linking_media_delete',
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
                   'app_linking_media_delete'):
        headers['Content-Type'] = 'application/json; charset=utf-8'
    elif route == 'app_linking_upload':
        headers['Content-Type'] = 'multipart/form-data; boundary=' + str(multipart_boundary)
        headers['Content-Length'] = str(content_length)
    control.remaining()
    connection = _linking_connection(parsed, control)
    try:
        # The direct transport never follows redirects.
        connection.request(method, path, body=body, headers=headers)
        with connection.getresponse() as response:
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
                                             'app_linking_photo_visibility')
                                   and response.status == 409)
            if response.status != expected_status and not visibility_conflict:
                raise LinkingResponseError(
                    diagnostic_phase, route, response.status, content_kind,
                    'http_status',
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
            max_bytes = (LINKING_GALLERY_TREE_RESPONSE_MAX_BYTES
                         if route == 'app_linking_gallery_tree' else
                         LINKING_PICKER_RESPONSE_MAX_BYTES
                         if route == 'app_linking_galleries' and method == 'POST' else
                         LINKING_GALLERIES_RESPONSE_MAX_BYTES
                         if route == 'app_linking_galleries' else
                         LINKING_UPLOAD_RESPONSE_MAX_BYTES
                         if route in ('app_linking_inventory', 'app_linking_upload')
                         else LINKING_RESPONSE_MAX_BYTES)
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
        if (route in ('app_linking_gallery_visibility', 'app_linking_photo_visibility')
                and '__client_visibility_conflict' in data):
            raise LinkingError('Linking response was not accepted.')
        return data
    finally:
        connection.close()


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
                               *ROLLING_ADMIN_MEDIA_SCOPES):
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
            # Share the dedicated refresh policy/format owner without a module
            # import cycle: credential_store itself depends on this module.
            from .offline_linking import OFFLINE_REFRESH_MAX_SECONDS, _valid_token
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


@dataclass(frozen=True)
class LinkedIdentity:
    """Minimal current Admin identity returned by a linked installation.

    Args:
        user_id (int): Positive identifier of the current Admin.
        username (str): Current Admin username returned by the server.
    """

    user_id: int
    username: str


@dataclass(frozen=True)
class TransientSessionSnapshot:
    """Carry an already-approved bearer to an OS vault without repr exposure.

    Attributes:
        issuer (str): Exact installation issuer and mount.
        scope (str): Exact browser-approved scope bundle.
        token (str): Original bearer, excluded from object representation.
        expires_at (float): Original grant deadline as Unix seconds.
    """

    issuer: str
    scope: str
    token: str = field(repr=False)
    expires_at: float


@dataclass(frozen=True)
class LinkedGallery:
    """One current, server-provided physical gallery without filesystem paths.

    Args:
        gallery_id (int): Stable positive gallery identifier.
        parent_id (int | None): Parent gallery ID, or None for a root.
        title (str): Server-provided title for display.
        depth (int): Picker depth from the authorized parent chain.
        ancestors (tuple[str, ...]): Nearest authorized parent titles, oldest first.
    """

    gallery_id: int
    parent_id: int | None
    title: str
    depth: int = 0
    ancestors: tuple[str, ...] = ()


@dataclass(frozen=True)
class LinkedGalleryDetail:
    """Hold one scoped gallery's explicit stored, non-secret properties.

    Args:
        gallery_id (int): Stable positive ID of the selected physical gallery.
        title (str): Current gallery display title.
        revision (str): Canonical positive decimal editor revision.
        visibility (str): Explicit stored visibility value.
        access_mode (str): Explicit stored access mode, not inherited access.
        access_listing (str): Explicit stored listing setting.
    """

    gallery_id: int
    title: str
    revision: str
    visibility: str
    access_mode: str
    access_listing: str


@dataclass(frozen=True)
class LinkedGalleryPage:
    """One bounded gallery page and its optional exclusive continuation.

    Args:
        galleries (tuple[LinkedGallery, ...]): Ordered server-authorized rows.
        next_after (int | None): Last ID for the next page, or None at the end.
        limited (bool): The server could not search its full catalog safely.
    """

    galleries: tuple[LinkedGallery, ...]
    next_after: int | None
    limited: bool = False


def _linked_gallery_page(
    data: dict[str, JsonValue], after_id: int | None, *, picker: bool = False,
) -> LinkedGalleryPage:
    """Validate an ordered listing or a ranked choice response without coercion.

    Args:
        data (dict[str, JsonValue]): JSON with galleries containing ID, parent
            ID and title, plus depth and parent titles for picker rows. The
            next_after value is the last returned ID or null. A limited picker
            response contains an empty list, null cursor and limited true.
        after_id (int | None): Previously validated exclusive continuation ID.
        picker (bool): Expect the enhanced ancestry and ranked ordering.

    Returns:
        LinkedGalleryPage: Bounded gallery identities and continuation.

    Raises:
        LinkingError: The response fields, rows or cursor are inconsistent.
    """
    if (picker and set(data) == {'galleries', 'next_after', 'limited'}
            and data['galleries'] == [] and data['next_after'] is None
            and data['limited'] is True):
        return LinkedGalleryPage((), None, limited=True)
    entries, next_after = data.get('galleries'), data.get('next_after')
    if (set(data) != {'galleries', 'next_after'} or not isinstance(entries, list)
            or len(entries) > LINKING_GALLERY_PAGE_MAX):
        raise LinkingError('Gallery response was not accepted.')
    galleries: list[LinkedGallery] = []
    previous = after_id or 0
    seen = {previous}
    fields = {'id', 'parent_id', 'title'}
    if picker:
        fields.update(('depth', 'ancestors'))
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != fields:
            raise LinkingError('Gallery response was not accepted.')
        gallery_id, parent_id, title = entry['id'], entry['parent_id'], entry['title']
        if (type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                or gallery_id in seen or (not picker and gallery_id <= previous)
                or (parent_id is not None and (type(parent_id) is not int
                    or not 0 < parent_id <= LINKING_GALLERY_ID_MAX))
                or not isinstance(title, str)
                or len(title) > LINKING_GALLERY_TITLE_MAX_CHARS
                or any(0xd800 <= ord(char) <= 0xdfff for char in title)):
            raise LinkingError('Gallery response was not accepted.')
        depth, ancestors = entry.get('depth', 0), entry.get('ancestors', [])
        if (type(depth) is not int or not 0 <= depth <= LINKING_GALLERY_DEPTH_MAX
                or not isinstance(ancestors, list)
                or len(ancestors) != min(depth, LINKING_GALLERY_ANCESTORS_MAX)
                or any(not isinstance(parent, str)
                       or len(parent) > LINKING_GALLERY_TITLE_MAX_CHARS
                       or any(0xd800 <= ord(char) <= 0xdfff for char in parent)
                       for parent in ancestors)):
            raise LinkingError('Gallery response was not accepted.')
        galleries.append(LinkedGallery(gallery_id, parent_id, title, depth, tuple(ancestors)))
        seen.add(gallery_id)
        previous = gallery_id
    if (next_after is not None and (type(next_after) is not int
            or len(galleries) != LINKING_GALLERY_PAGE_MAX or next_after != previous)):
        raise LinkingError('Gallery response was not accepted.')
    return LinkedGalleryPage(tuple(galleries), next_after)


@dataclass(frozen=True)
class LinkedGalleryTree:
    """Hold one validated complete gallery tree and its server revision.

    Args:
        galleries (tuple[LinkedGallery, ...]): Complete ID-ordered rows, empty
            for an empty catalogue or an unchanged response backed by a matching
            local snapshot.
        revision (str): Lowercase SHA-256 revision from the server.
        unchanged (bool): Whether the server confirmed the requested revision.
    """

    galleries: tuple[LinkedGallery, ...]
    revision: str
    unchanged: bool = False


def _linked_gallery_tree(
    data: dict[str, JsonValue], requested_revision: str | None,
) -> LinkedGalleryTree:
    """Validate a complete tree-v1 envelope without coercing any member.

    Args:
        data (dict[str, JsonValue]): Strict JSON envelope from the optional route.
        requested_revision (str | None): Exact revision sent with the request.

    Returns:
        LinkedGalleryTree: Complete validated rows or matching unchanged proof.

    Raises:
        LinkingError: The envelope, revision, rows or ordering is invalid.
    """
    revision = data.get('revision')
    unchanged = data.get('unchanged')
    if (type(data.get('version')) is not int or data.get('version') != 1
            or not isinstance(revision, str)
            or _GALLERY_TREE_REVISION_PATTERN.fullmatch(revision) is None
            or type(unchanged) is not bool):
        raise LinkingError('Gallery tree response was not accepted.')
    if unchanged:
        if (set(data) != {'version', 'revision', 'unchanged'}
                or requested_revision is None
                or revision != requested_revision):
            raise LinkingError('Gallery tree response was not accepted.')
        return LinkedGalleryTree((), revision, True)
    rows = data.get('galleries')
    if (set(data) != {'version', 'revision', 'unchanged', 'galleries'}
            or not isinstance(rows, list)
            or len(rows) > LINKING_GALLERY_TREE_MAX_ROWS):
        raise LinkingError('Gallery tree response was not accepted.')
    galleries: list[LinkedGallery] = []
    previous = 0
    for row in rows:
        if not isinstance(row, dict) or set(row) != {'id', 'parent_id', 'title'}:
            raise LinkingError('Gallery tree response was not accepted.')
        gallery_id, parent_id, title = (
            row['id'], row['parent_id'], row['title'],
        )
        if (type(gallery_id) is not int
                or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                or gallery_id <= previous
                or (parent_id is not None and (
                    type(parent_id) is not int
                    or not 0 < parent_id <= LINKING_GALLERY_ID_MAX
                ))
                or not isinstance(title, str)
                or len(title) > LINKING_GALLERY_TITLE_MAX_CHARS
                or any(0xd800 <= ord(char) <= 0xdfff for char in title)):
            raise LinkingError('Gallery tree response was not accepted.')
        galleries.append(LinkedGallery(gallery_id, parent_id, title))
        previous = gallery_id
    return LinkedGalleryTree(tuple(galleries), revision)


@dataclass(frozen=True)
class LinkedPhoto:
    """Represent one permitted direct photo without a filesystem path.

    Args:
        image_id (int): Stable positive server image identity.
        title (str): Bounded display name returned by the server.
        latitude (float | None): Authorized map latitude, or None if unavailable.
        longitude (float | None): Authorized map longitude, or None if unavailable.
        visibility (str | None): Stored Admin visibility, if explicitly authorized.
        nsfw (bool | None): Stored NSFW Guard state, if explicitly authorized.
        size (int | None): Indexed original file size in bytes, if known.
        cache_eligible (bool | None): Current independent public admission, if supplied.
        thumbnail_etag (str | None): Current validator for an existing public thumbnail.
        thumbnail_verified_at (float | None): Monotonic time of its authorized page read.
        preload_eligible (bool | None): Current non-NSFW Admin preload admission.
        preload_thumbnail_etag (str | None): Strong Admin-preload thumbnail proof.
        preload_verified_at (float | None): Monotonic time of Admin preload metadata.
    """

    image_id: int
    title: str
    latitude: float | None = None
    longitude: float | None = None
    visibility: str | None = None
    nsfw: bool | None = None
    size: int | None = None
    cache_eligible: bool | None = None
    thumbnail_etag: str | None = None
    thumbnail_verified_at: float | None = None
    preload_eligible: bool | None = None
    preload_thumbnail_etag: str | None = None
    preload_verified_at: float | None = None


def _linked_thumbnail_etag(value: JsonValue) -> str | None:
    """Validate the optional public thumbnail proof from a catalogue row.

    Args:
        value (JsonValue): Decoded optional validator from the server.

    Returns:
        str | None: Strong validator or None when no proof was supplied.

    Raises:
        LinkingError: A non-null proof was malformed.
    """
    if value is None:
        return None
    if not isinstance(value, str) or re.fullmatch(r'"[a-f0-9]{64}"', value) is None:
        raise LinkingError('Gallery thumbnail validator was not accepted.')
    return value


def _linked_admin_photo(row: dict[str, JsonValue]) -> LinkedPhoto:
    """Validate an Admin photo projection before exposing it to the viewer.

    Args:
        row (dict[str, JsonValue]): Strict decoded descriptor with stored settings.

    Returns:
        LinkedPhoto: Bounded metadata and explicit public cache admission.

    Raises:
        LinkingError: The photo identity or stored settings were malformed.
    """
    image_id, title = row.get('id'), row.get('title')
    visibility, nsfw, size = row.get('visibility'), row.get('nsfw'), row.get('size')
    cache_eligible = row.get('cache_eligible')
    thumbnail_etag = _linked_thumbnail_etag(row.get('thumbnail_etag'))
    preload_eligible = row.get('preload_eligible')
    preload_thumbnail_etag = _linked_thumbnail_etag(
        row.get('preload_thumbnail_etag'),
    )
    if (type(image_id) is not int or not _native_media_id(image_id)
            or not isinstance(title, str) or not _native_media_title(title)
            or not isinstance(visibility, str) or visibility not in ('draft', 'private', 'public')
            or type(nsfw) is not bool or type(cache_eligible) is not bool
            or ('preload_eligible' in row
                and type(preload_eligible) is not bool)
            or (size is not None and (type(size) is not int or size < 0))
            or (cache_eligible and (nsfw or visibility != 'public'))
            or (thumbnail_etag is not None and not cache_eligible)
            or (preload_eligible is True and nsfw)
            or (preload_thumbnail_etag is not None
                and preload_eligible is not True)):
        raise LinkingError('Gallery photo settings were not accepted.')
    return LinkedPhoto(
        image_id, title, visibility=visibility, nsfw=nsfw,
        size=size, cache_eligible=cache_eligible, thumbnail_etag=thumbnail_etag,
        preload_eligible=preload_eligible,
        preload_thumbnail_etag=preload_thumbnail_etag,
    )


@dataclass(frozen=True)
class LinkedMediaGalleryInfo:
    """Represent the current direct original inventory of a physical gallery.

    Args:
        gallery_id (int): Exact gallery ID from the request.
        photo_count (int): Number of direct indexed photos.
        file_count (int): Number of direct indexed original photo files.
        size (int): Total indexed original bytes.
    """

    gallery_id: int
    photo_count: int
    file_count: int
    size: int


def _linked_media_gallery_inventory(
    data: dict[str, JsonValue], gallery_id: int,
) -> LinkedMediaGalleryInfo:
    """Validate the shared inventory fields in an Admin gallery response.

    Args:
        data (dict[str, JsonValue]): Gallery ID, direct photo/file counts and
            original byte size, with optional detailed fields.
        gallery_id (int): Exact requested physical gallery ID.

    Returns:
        LinkedMediaGalleryInfo: Bound direct inventory counts and size.

    Raises:
        LinkingError: The response names a different gallery or invalid counts.
    """
    photos, files, size = (data.get(key) for key in ('photo_count', 'file_count', 'size'))
    if (type(data.get('gallery_id')) is not int or data['gallery_id'] != gallery_id
            or type(photos) is not int or photos < 0
            or type(files) is not int or files < 0
            or type(size) is not int or size < 0):
        raise LinkingError('Gallery inventory was not accepted.')
    return LinkedMediaGalleryInfo(gallery_id, photos, files, size)


def _linked_media_details(
    data: dict[str, JsonValue], fields: tuple[str, ...],
) -> LinkedMediaDetails:
    """Project bounded scalar information from an exact Admin response.

    Args:
        data (dict[str, JsonValue]): Parsed metadata object with allowed text,
            image geometry, EXIF, location or gallery attributes.
        fields (tuple[str, ...]): Explicit optional fields for this entity type.

    Returns:
        LinkedMediaDetails: Present bounded scalars keyed by their wire names.
    """
    details: LinkedMediaDetails = {}
    for key in fields:
        value = data.get(key)
        if isinstance(value, str) and len(value) <= NATIVE_MEDIA_DETAIL_TEXT_CHARS:
            details[key] = ''.join(char if char.isprintable() else ' ' for char in value)
        elif type(value) in (int, bool) or (type(value) is float and math.isfinite(value)):
            details[key] = value
    return details


@dataclass(frozen=True)
class LinkedMediaPage:
    """Hold one page of current authorized photo descriptors.

    Args:
        gallery_id (int): Requested gallery identity.
        parent_id (int | None): Public hierarchy parent, or None at root.
        photos (tuple[LinkedPhoto, ...]): Increasing positive photo IDs.
        next_after (int | None): Exclusive continuation or None at the end.
    """

    gallery_id: int
    parent_id: int | None
    photos: tuple[LinkedPhoto, ...]
    next_after: int | None


@dataclass(frozen=True)
class LinkedTrack:
    """Represent one permitted track without its storage path.

    Args:
        track_id (str): Opaque gallery-specific identifier.
        title (str): Bounded display name.
        mime (str): Supported native audio media type.
        size (int): Expected bytes in the complete track.
        sha256 (str): Expected lowercase digest of those bytes.
    """

    track_id: str
    title: str
    mime: str
    size: int
    sha256: str


@dataclass(frozen=True)
class LinkedPresentation:
    """Hold server-selected playback preferences and current visible music.

    Args:
        enabled (bool): Whether the server permits presentation in this gallery.
        autoplay (bool): Whether a user-started presentation advances itself.
        interval_seconds (int): Server preset duration per photo.
        transition (str): Server's slow, medium or fast transition preset.
        music (bool): Whether the presentation permits gallery music.
        tracks (tuple[LinkedTrack, ...]): Ordered bounded playable tracks.
        track_cache (bool): Whether the current server permits opted-in track reuse.
    """

    enabled: bool
    autoplay: bool
    interval_seconds: int
    transition: str
    music: bool
    tracks: tuple[LinkedTrack, ...]
    track_cache: bool = False


def _native_media_id(value: JsonValue) -> bool:
    """Validate one positive, non-boolean server media or gallery ID.

    Args:
        value (JsonValue): Candidate identifier from a local or wire selector.

    Returns:
        bool: True for an integer in the supported server ID range.
    """
    return type(value) is int and 0 < value <= LINKING_GALLERY_ID_MAX


def _native_media_title(value: JsonValue) -> bool:
    """Accept bounded text that can be rendered without control characters.

    Args:
        value (JsonValue): Candidate photo or track name from the server.

    Returns:
        bool: True for valid bounded single-line display text.
    """
    return (isinstance(value, str) and len(value) <= LINKING_GALLERY_TITLE_MAX_CHARS
            and not any(ord(c) < 32 or ord(c) == 127 or 0xd800 <= ord(c) <= 0xdfff
                        for c in value))


def _native_media_policy_facts(
    headers: http.client.HTTPMessage, cache_header: str | None,
    cache_policy: str, admin_preload: bool, *,
    binary_check: Literal[
        'none', 'length', 'validator', 'framing', 'body_length', 'digest',
    ] = 'none',
) -> MediaPolicyFacts:
    """Reduce media headers and refusal stages to finite policy observations.

    Args:
        headers (http.client.HTTPMessage): Parsed response header collection.
        cache_header (str | None): Requested cache marker name, if any.
        cache_policy (str): Exact cache directive required by the request.
        admin_preload (bool): Whether the explicit preload marker was requested.
        binary_check (Literal['none', 'length', 'validator', 'framing', 'body_length', 'digest']):
            Fixed binary refusal stage, or none for cache-header observations.

    Returns:
        MediaPolicyFacts: Validated allowlisted facts with no retained header
            strings, values, URLs or response content.
    """
    cache_controls = headers.get_all('Cache-Control', [])
    if not cache_controls:
        cache_control: _MediaPolicyState = 'missing'
    elif len(cache_controls) > 1:
        cache_control = 'multiple'
    elif cache_policy and cache_controls[0].strip().lower() == cache_policy:
        cache_control = 'expected'
    else:
        cache_control = 'different'

    cache_markers = (
        [] if cache_header is None
        else headers.get_all(cache_header, [])
    )
    if cache_header is None:
        cache_marker: _MediaPolicyMarkerState = 'not_requested'
    elif not cache_markers:
        cache_marker = 'missing'
    elif len(cache_markers) > 1:
        cache_marker = 'multiple'
    elif cache_markers[0] == '1':
        cache_marker = 'expected'
    else:
        cache_marker = 'different'

    directives = [
        part.strip().lower()
        for value in cache_controls
        for part in value.split(',')
    ]
    directive_names = [
        directive.partition('=')[0].strip()
        for directive in directives
    ]
    max_age_values: list[int] = []
    max_age_directives = [
        directive.partition('=')[2].strip()
        for directive, name in zip(directives, directive_names)
        if name == 'max-age'
    ]
    if (len(max_age_directives) == 1
            and re.fullmatch(r'[0-9]{1,10}', max_age_directives[0]) is not None):
        parsed_max_age = int(max_age_directives[0])
        if parsed_max_age <= MEDIA_POLICY_FACT_MAX_AGE_SECONDS:
            max_age_values.append(parsed_max_age)

    vary_tokens = {
        part.strip().lower()
        for value in headers.get_all('Vary', [])
        for part in value.split(',')
    }
    preload_markers = (
        headers.get_all('X-PHP-Gallery-Preload', [])
        if admin_preload else []
    )
    if not admin_preload:
        preload_marker: _MediaPolicyMarkerState = 'not_requested'
    elif not preload_markers:
        preload_marker = 'missing'
    elif len(preload_markers) > 1:
        preload_marker = 'multiple'
    elif preload_markers[0] == 'admin-v1':
        preload_marker = 'expected'
    else:
        preload_marker = 'different'

    return MediaPolicyFacts(
        cache_control=cache_control,
        cache_marker=cache_marker,
        private='private' in directive_names,
        must_revalidate='must-revalidate' in directive_names,
        max_age_seconds=max_age_values[0] if max_age_values else None,
        vary_auth='authorization' in vary_tokens,
        vary_marker=(
            cache_header is not None and cache_header.lower() in vary_tokens
        ),
        vary_wildcard='*' in vary_tokens,
        preload_marker=preload_marker,
        vary_preload='x-php-gallery-preload' in vary_tokens,
        encoding_present=bool(headers.get_all('Content-Encoding', [])),
        binary_check=binary_check,
    )


def _private_media_cache_control_matches(
    values: list[str], *, photo_request: bool, track_request: bool,
) -> bool:
    """Match equivalent forms of the negotiated private cache policy.

    Args:
        values (list[str]): All Cache-Control field values from one response.
        photo_request (bool): Whether the request is for photo bytes.
        track_request (bool): Whether the request is for audio bytes.

    Returns:
        bool: True only for the complete private policy with allowed repeats.

    Photo directives may be split, reordered or repeated equivalently. For
    repeated max-age fields RFC 9111 permits use of the first origin value.
    Admission still requires an explicit 60-second value and every value to
    meet the existing 60-second local freshness cap. Audio accepts only equivalent
    `private, no-cache` directives.
    """
    flags: set[str] = set()
    ages: list[str] = []
    for header in values:
        if not isinstance(header, str) or not header:
            return False
        for field in header.split(','):
            directive = field.strip()
            if not directive:
                return False
            name, separator, parameter = directive.partition('=')
            name = name.strip().lower()
            parameter = parameter.strip()
            if name in ('private', 'must-revalidate', 'no-cache'):
                if separator:
                    return False
                flags.add(name)
                continue
            if name != 'max-age' or not separator or not photo_request:
                return False
            if re.fullmatch(r'[0-9]+', parameter) is None:
                return False
            ages.append(parameter.lstrip('0') or '0')

    if photo_request and not track_request:
        required = {'private', 'must-revalidate'}
        minimum_age = str(PHOTO_CACHE_FRESH_SECONDS)
        return (
            flags == required
            and bool(ages)
            and minimum_age in ages
            and all(
                len(age) > len(minimum_age)
                or (len(age) == len(minimum_age) and age >= minimum_age)
                for age in ages
            )
        )
    if track_request and not photo_request:
        return flags == {'private', 'no-cache'} and not ages
    return False


def _native_media_read_once(
    issuer: str, query: str, bearer: str, accepted: tuple[str, ...],
    control: _LinkingTransportControl, if_none_match: str | None = None,
    *, allow_restricted: bool = False, admin_preload: bool = False,
    pool: _MediaConnectionPool | None = None,
    timings: MediaReadTimings | None = None,
) -> _NativeMediaResponse:
    """Read one current media projection from the exact scoped installation.

    Args:
        issuer (str): Independently verified canonical installation origin and mount.
        query (str): Encoded fixed media route and validated resource selectors.
        bearer (str): Current opaque token sent only in the authorization header.
        accepted (tuple[str, ...]): Exact expected JSON or binary media types.
        control (_LinkingTransportControl): Absolute worker deadline and socket owner.
        if_none_match (str | None): Previously verified strong photo ETag for
            one conditional photo or thumbnail revalidation.
        allow_restricted (bool): Permit the explicit no-store Admin photo lane.
        admin_preload (bool): Request the versioned Admin private-cache lane.
        pool (_MediaConnectionPool | None): Session's sequential idle socket,
            or None for the original one-request behavior.
        timings (MediaReadTimings | None): Caller-owned aggregate phase metrics.

    Returns:
        _NativeMediaResponse: Body or authorized not-modified marker, media type,
            strong validator, and whether the server opted into disk caching.

    Raises:
        LinkingError: A redirect, unsafe header, length or digest was received.
        OSError: The network or TLS connection failed.
        http.client.HTTPException: HTTP framing or body was incomplete.
    """
    parsed = urlsplit(issuer)
    control.timings = timings
    if pool is not None:
        connection, reused, pool_epoch = pool.borrow(parsed, control)
    else:
        connection = _linking_connection(parsed, control)
        reused, pool_epoch = False, 0
    reusable = False
    response: http.client.HTTPResponse | None = None
    body_started = 0.0

    def read_encoded(limit: int) -> bytes:
        """Count consumed encoded bodies even when policy later rejects them.

        Args:
            limit (int): Maximum response bytes requested by the existing parser.
        Returns:
            bytes: Encoded bytes from the owned HTTP response.
        """
        assert response is not None
        try:
            payload = response.read(limit)
        except http.client.IncompleteRead as error:
            if timings is not None:
                timings.encoded_bytes += len(error.partial)
            raise
        if timings is not None:
            timings.encoded_bytes += len(payload)
        return payload

    try:
        control.remaining()
        headers = {
            'Accept': ', '.join(accepted),
            'Authorization': 'Bearer ' + bearer,
            'User-Agent': LINKING_USER_AGENT,
        }
        photo_request = any(mime in _NATIVE_IMAGE_TYPES for mime in accepted)
        track_request = any(mime in _NATIVE_AUDIO_TYPES for mime in accepted)
        catalogue_request = '&kind=photos' in query
        cache_header = None
        cache_policy = ''
        if photo_request:
            cache_header = 'X-PHP-Gallery-Photo-Cache'
            cache_policy = (
                f'private, max-age={PHOTO_CACHE_FRESH_SECONDS}, must-revalidate'
            )
        elif track_request:
            cache_header = 'X-PHP-Gallery-Track-Cache'
            cache_policy = 'private, no-cache'
        if cache_header is not None:
            headers[cache_header] = '1'
        if catalogue_request:
            headers['X-PHP-Gallery-Photo-Page'] = str(NATIVE_MEDIA_PAGE_SIZE)
        if admin_preload:
            headers['X-PHP-Gallery-Preload'] = 'admin-v1'
        if if_none_match is not None:
            if not photo_request or re.fullmatch(r'"[a-f0-9]{64}"', if_none_match) is None:
                raise LinkingError('Invalid gallery photo validator.')
            headers['If-None-Match'] = if_none_match
        for attempt in range(MEDIA_STALE_GET_RETRIES + 1):
            control.remaining()
            try:
                # Resolve/connect/TLS before measuring the request/header
                # phase; connection setup therefore has separate observations.
                if connection.sock is None:
                    connection.connect()
                started = time.perf_counter() if timings is not None else 0.0
                if timings is not None:
                    timings.http_requests += 1
                try:
                    connection.request(
                        'GET', parsed.path + '/index.php?' + query,
                        headers=headers,
                    )
                    response = connection.getresponse()
                finally:
                    if timings is not None:
                        timings.ttfb_ms += (time.perf_counter() - started) * 1000.0
                break
            except (
                http.client.RemoteDisconnected, BrokenPipeError,
                ConnectionResetError, ConnectionAbortedError,
            ):
                # Only a borrowed stale socket may be retried, and only before
                # a response exists. No response/body/auth failure is replayed.
                if (not reused or pool is None
                        or attempt >= MEDIA_STALE_GET_RETRIES):
                    raise
                connection.close()
                control.finish()
                control.remaining()
                connection, reused, pool_epoch = pool.borrow(parsed, control)
        if response is None:
            raise LinkingError('Gallery media response is unavailable.')
        body_started = time.perf_counter() if timings is not None else 0.0
        with response:
            types = response.headers.get_all('Content-Type', [])
            media_type = types[0].split(';', 1)[0].strip().lower() if len(types) == 1 else ''
            content_kind = _response_content_kind(types)
            cache_controls = response.headers.get_all('Cache-Control', [])
            vary_values = response.headers.get_all('Vary', [])
            vary_tokens = {
                part.strip().lower()
                for value in vary_values for part in value.split(',')
            }
            cacheable = bool(
                cache_header is not None
                and response.headers.get_all(cache_header, []) == ['1']
                and _private_media_cache_control_matches(
                    cache_controls,
                    photo_request=photo_request,
                    track_request=track_request,
                )
                and '*' not in vary_tokens
                and {'authorization', cache_header.lower()}.issubset(vary_tokens)
                and (not admin_preload or (
                    response.headers.get_all('X-PHP-Gallery-Preload') == ['admin-v1']
                    and 'x-php-gallery-preload' in vary_tokens
                ))
            )
            directives = {part.strip().lower()
                          for value in cache_controls
                          for part in value.split(',')}
            if response.headers.get('Content-Encoding') is not None:
                policy_facts = _native_media_policy_facts(
                    response.headers, cache_header, cache_policy, admin_preload,
                )
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'response_policy',
                    media_policy_facts=policy_facts,
                )
            if response.status == 401:
                if content_kind != 'json':
                    raise LinkingResponseError(
                        'media', 'app_linking_media', response.status,
                        content_kind, 'http_status',
                    )
                payload = read_encoded(NATIVE_MEDIA_JSON_BYTES + 1)
                if (len(types) == 1 and media_type == 'application/json'
                        and cache_controls == ['no-store']
                        and len(payload) <= NATIVE_MEDIA_JSON_BYTES
                        and _native_media_json(payload) == {'error': 'invalid_token'}):
                    raise LinkingGrantRejected('The linking grant was rejected.')
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'http_status',
                )
            if response.status == 304:
                etags = response.headers.get_all('ETag', [])
                if (if_none_match is None or len(etags) != 1
                        or etags[0] != if_none_match
                        or not (cacheable or (
                            not admin_preload and 'no-store' in directives
                        ))):
                    policy_facts = _native_media_policy_facts(
                        response.headers, cache_header, cache_policy,
                        admin_preload,
                    )
                    raise LinkingResponseError(
                        'media', 'app_linking_media', response.status,
                        content_kind, 'response_policy',
                        media_policy_facts=policy_facts,
                    )
                control.remaining()
                # HTTP 304 has no body. Finalize its HTTPResponse state before
                # allowing the connection's next sequential request.
                if read_encoded(1):
                    raise LinkingError('Unexpected not-modified response body.')
                reusable = bool(
                    response.version == 11 and not response.will_close
                    and response.isclosed()
                    and not response.headers.get_all('Transfer-Encoding', [])
                    and response.headers.get_all('Content-Length', [])
                    in ([], ['0'])
                )
                return None, '', etags[0], cacheable
            if response.status == 400 and '&kind=revision' in query:
                payload = read_encoded(NATIVE_MEDIA_JSON_BYTES + 1)
                if (len(types) == 1 and media_type == 'application/json'
                        and cache_controls == ['no-store']
                        and len(payload) <= NATIVE_MEDIA_JSON_BYTES
                        and _native_media_json(payload) == {'error': 'invalid_request'}):
                    raise LinkingRevisionUnsupported('Gallery revision is unsupported.')
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'http_status',
                )
            if response.status == 404 and catalogue_request:
                payload = read_encoded(NATIVE_MEDIA_JSON_BYTES + 1)
                if (len(types) == 1 and media_type == 'application/json'
                        and cache_controls == ['no-store']
                        and len(payload) <= NATIVE_MEDIA_JSON_BYTES):
                    try:
                        error_data = _native_media_json(payload)
                    except LinkingError:
                        error_data = {}
                    if error_data == {'error': 'target_unavailable'}:
                        raise LinkingMediaUnavailable(
                            'Photos are unavailable for this gallery.',
                        )
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'http_status',
                )
            if response.status == 429:
                payload = read_encoded(NATIVE_MEDIA_JSON_BYTES + 1)
                if (len(types) == 1 and media_type == 'application/json'
                        and cache_controls == ['no-store']
                        and len(payload) <= NATIVE_MEDIA_JSON_BYTES):
                    try:
                        error_data = _native_media_json(payload)
                    except LinkingError:
                        error_data = {}
                    if error_data == {'error': 'temporarily_unavailable'}:
                        retry_values = response.headers.get_all('Retry-After', [])
                        retry_after = None
                        if (len(retry_values) == 1
                                and re.fullmatch(r'[1-9][0-9]{0,2}', retry_values[0])
                                is not None):
                            parsed_retry = int(retry_values[0])
                            if parsed_retry <= MEDIA_RETRY_AFTER_MAX_SECONDS:
                                retry_after = parsed_retry
                        raise LinkingMediaThrottled(retry_after)
                raise LinkingError('Gallery media response was not accepted.')
            if (response.status != 200 or media_type not in accepted
                    or not (cacheable or 'no-store' in directives)):
                reason = (
                    'http_status' if response.status != 200
                    else 'content_type' if media_type not in accepted
                    else 'response_policy'
                )
                policy_facts = (
                    _native_media_policy_facts(
                        response.headers, cache_header, cache_policy,
                        admin_preload,
                    )
                    if reason == 'response_policy' else None
                )
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, reason,
                    media_policy_facts=policy_facts,
                )
            if admin_preload and not cacheable and 'no-store' not in directives:
                policy_facts = _native_media_policy_facts(
                    response.headers, cache_header, cache_policy, admin_preload,
                )
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'response_policy',
                    media_policy_facts=policy_facts,
                )
            if media_type == 'application/json':
                max_bytes = (
                    NATIVE_MEDIA_CATALOGUE_BYTES if catalogue_request else NATIVE_MEDIA_JSON_BYTES
                )
                payload = read_encoded(max_bytes + 1)
                if len(payload) > max_bytes:
                    raise LinkingError('Gallery media metadata is too large.')
                etag, cacheable = None, False
            else:
                lengths = response.headers.get_all('Content-Length', [])
                etags = response.headers.get_all('ETag', [])
                restricted = (
                    allow_restricted and photo_request and cache_controls == ['no-store']
                    and response.headers.get_all('X-PHP-Gallery-Media-Restricted', []) == ['1']
                    and not response.headers.get_all('X-PHP-Gallery-Photo-Cache', [])
                    and not etags
                )
                has_validator = (
                    len(etags) == 1 and re.fullmatch(r'"[a-f0-9]{64}"', etags[0]) is not None
                )
                valid_length = (
                    len(lengths) == 1
                    and re.fullmatch(r'[1-9][0-9]{0,7}', lengths[0]) is not None
                    and int(lengths[0]) <= NATIVE_MEDIA_MAX_BYTES
                )
                invalid_framing = (
                    response.headers.get('Transfer-Encoding') is not None
                )
                if (not valid_length or not (restricted or has_validator)
                        or invalid_framing):
                    binary_check = (
                        'length' if not valid_length else
                        'framing' if invalid_framing else 'validator'
                    )
                    raise LinkingResponseError(
                        'media', 'app_linking_media', response.status,
                        content_kind, 'response_policy',
                        media_policy_facts=_native_media_policy_facts(
                            response.headers, cache_header, cache_policy,
                            admin_preload, binary_check=binary_check,
                        ),
                    )
                length = int(lengths[0])
                payload = read_encoded(length)
                if (len(payload) != length
                        or (has_validator
                            and hashlib.sha256(payload).hexdigest() != etags[0][1:-1])):
                    raise LinkingResponseError(
                        'media', 'app_linking_media', response.status,
                        content_kind, 'response_policy',
                        media_policy_facts=_native_media_policy_facts(
                            response.headers, cache_header, cache_policy,
                            admin_preload,
                            binary_check=(
                                'body_length' if len(payload) != length
                                else 'digest'
                            ),
                        ),
                    )
                etag = etags[0] if has_validator else None
            if timings is not None:
                mime_counter = {
                    'image/jpeg': 'jpeg_responses', 'image/png': 'png_responses',
                    'image/gif': 'gif_responses', 'image/webp': 'webp_responses',
                }.get(media_type)
                if mime_counter is not None:
                    setattr(timings, mime_counter, getattr(timings, mime_counter) + 1)
            control.remaining()
            reusable = bool(
                response.version == 11 and not response.will_close
                and response.isclosed()
                and not response.headers.get_all('Transfer-Encoding', [])
                and response.headers.get_all('Content-Length', [])
                == [str(len(payload))]
            )
            return payload, media_type, etag, cacheable
    finally:
        if timings is not None and response is not None:
            timings.body_ms += (time.perf_counter() - body_started) * 1000.0
        retained = bool(
            reusable and pool is not None
            and pool.release(parsed, connection, control, pool_epoch)
        )
        if not retained:
            connection.close()


def _native_media_json(payload: bytes) -> dict[str, JsonValue]:
    """Decode strict, already bounded media metadata for typed validation.

    Args:
        payload (bytes): Complete native JSON body in UTF-8.

    Returns:
        dict[str, JsonValue]: Photo page or presentation fields before validation.

    Raises:
        LinkingError: The response is not one strict JSON object.
    """
    try:
        data = json.loads(payload.decode('utf-8'), object_pairs_hook=_unique_object,
                          parse_constant=_reject_nonfinite)
    except (UnicodeError, ValueError, RecursionError):
        raise LinkingError('Gallery media metadata was not accepted.') from None
    if not isinstance(data, dict):
        raise LinkingError('Gallery media metadata was not accepted.')
    return data


class LinkingSession:
    """Hold one scoped bearer in memory and serialize its operations."""

    def __init__(
        self, issuer: str, token: str, expires_at: float,
        scope: str = IDENTITY_SCOPE, *, admin_preload_supported: bool = False,
        gallery_tree_supported: bool = False,
    ) -> None:
        """Create a transient session from a validated token response.

        Args:
            issuer (str): Previously trusted exact installation issuer and mount.
            token (str): Canonical scoped bearer; never log or persist it.
            expires_at (float): Conservative monotonic local expiry deadline.
            scope (str): Exact approved transient or offline scope bundle.
            admin_preload_supported (bool): Whether discovery advertised the
                explicit versioned private media protocol.
            gallery_tree_supported (bool): Whether discovery advertised the
                optional complete catalogue tree protocol.

        Returns:
            None: Owns one token solely for the life of this object.

        Raises:
            LinkingError: Issuer, bearer or expiry is invalid.
        """
        if (not _issuer_valid(issuer, allow_local_http=True)
                or not token.startswith(TOKEN_PREFIX)
                or not _canonical_256bit(token[len(TOKEN_PREFIX):])
                or scope_base(scope) is None
                or type(admin_preload_supported) is not bool
                or type(gallery_tree_supported) is not bool
                or not math.isfinite(expires_at)
                or expires_at <= time.monotonic()):
            raise LinkingError('Invalid linking session.')
        self._lock = threading.Lock()
        self._request_gate = _FairRequestGate()
        self._media_connection_pool = _MediaConnectionPool()
        self._cancelled = threading.Event()
        self._pending_gallery_cancels: set[threading.Event] = set()
        self._visibility_snapshot: LinkedGalleryDetail | None = None
        self._recent_media_pages: OrderedDict[
            int, tuple[float, LinkedMediaPage]
        ] = OrderedDict()
        # Page authority covers automatic thumbnails. The separate final flag
        # confirms saved full-media intent; thumbnails cannot grant that intent.
        # Each row retains its source cursor so fullscreen can refresh a whole
        # authorization page without re-fetching the downloaded image bodies.
        self._private_preload_proofs: dict[
            int, tuple[
                int, float, dict[int, tuple[str, float, int, int | None]], bool,
            ]
        ] = {}
        self._private_preload_generation = 0
        self._private_preload_proof_epoch = 0
        self._bound_user_id: int | None = None
        self._issuer = issuer
        self._scope = scope
        self._admin_preload_supported = admin_preload_supported
        self._gallery_tree_supported = gallery_tree_supported
        self._token = token
        self._expires_at = expires_at
        self._busy = False
        self._active_route = ''
        self._renew: Callable[[threading.Event], tuple[str, float]] | None = None
        self._closed = False

    def enable_renewal(
        self, renew: Callable[[threading.Event], tuple[str, float]],
    ) -> None:
        """Attach renewal after the approved account binding is persisted.

        Args:
            renew (Callable[[threading.Event], tuple[str, float]]): Callback
                returning the same account's verified bearer and monotonic expiry.

        Returns:
            None: Subsequent operations may renew an expired bearer.
        """
        with self._lock:
            if not self._closed and self._scope.endswith(ROLLING_SCOPE_SUFFIX):
                self._renew = renew

    def disable_renewal(self) -> None:
        """Forget renewal after the saved local credential is removed.

        Returns:
            None: The current bearer retains only its original lifetime.
        """
        with self._lock:
            self._renew = None

    def _renew_if_expired(self, cancelled: threading.Event | None) -> None:
        """Renew under request admission before sending any protected operation.

        Args:
            cancelled (threading.Event | None): Optional operation cancellation.

        Returns:
            None: The request may proceed to normal scope and deadline checks.

        Raises:
            LinkingError: Rotation or cancellation prevented a usable bearer.
        """
        with self._lock:
            renew = self._renew
            if (self._closed or renew is None
                    or time.monotonic() < self._expires_at):
                return
            if cancelled is not None and cancelled.is_set():
                raise LinkingError('Gallery request was cancelled.')
        try:
            self._media_connection_pool.discard()
            token, expires_at = renew(self._cancelled)
        except LinkingRefreshRejected:
            self.close()
            raise
        with self._lock:
            if self._closed:
                raise LinkingError('Linking session was cancelled.')
            self._token = token
            self._expires_at = expires_at
            self._private_preload_generation += 1
            self._recent_media_pages.clear()
            self._private_preload_proofs.clear()

    def available(self) -> bool:
        """Check the local deadline without claiming remote authorization.

        Returns:
            bool: True while the bearer is live or the link can renew;
                the server may already have revoked the underlying grant.
        """
        with self._lock:
            return (not self._closed and bool(self._token)
                    and (self._renew is not None or time.monotonic() < self._expires_at))

    def _request(
        self,
        route: Literal[
            'app_linking_identity', 'app_linking_revoke',
            'app_linking_galleries', 'app_linking_gallery_tree',
            'app_linking_inventory',
            'app_linking_upload', 'app_linking_gallery_detail',
            'app_linking_gallery_create', 'app_linking_gallery_create_status',
            'app_linking_gallery_create_abandon',
            'app_linking_gallery_visibility', 'app_linking_photo_visibility',
            'app_linking_media_delete',
        ],
        after_id: int | None = None,
        body: bytes | Iterable[bytes] | None = None,
        multipart_boundary: str | None = None,
        content_length: int | None = None,
        gallery_cancel: threading.Event | None = None,
        gallery_id: int | None = None,
        watch_key: str | None = None,
        tree_revision: str | None = None,
    ) -> dict[str, JsonValue]:
        """Serialize one authenticated operation without retrying it.

        Args:
            route (str): Fixed scoped identity, gallery or upload operation.
            after_id (int | None): Exclusive gallery page cursor, if present.
            body (bytes | Iterable[bytes] | None): JSON or multipart request body.
            multipart_boundary (str | None): Boundary for a multipart upload.
            content_length (int | None): Exact multipart size in bytes.
            gallery_cancel (threading.Event | None): Dialog-owned cancellation
                for a listing, detail read or create request, if applicable.
            gallery_id (int | None): Exact selected gallery ID for detail or deletion.
            watch_key (str | None): Supplemental key for capture traffic only.
            tree_revision (str | None): Optional exact tree-v1 revision.

        Returns:
            dict[str, JsonValue]: Strict decoded server response object.

        Raises:
            LinkingError: Closed, busy, expired identity, cancelled or failure.
        """
        if route in (
            'app_linking_galleries', 'app_linking_gallery_tree',
            'app_linking_gallery_detail',
        ) and gallery_cancel is None:
            gallery_cancel = threading.Event()
        if gallery_cancel is not None:
            with self._lock:
                self._pending_gallery_cancels.add(gallery_cancel)
        try:
            if not self._request_gate.acquire(
                timeout=LINKING_SESSION_ADMISSION_TIMEOUT_SECONDS,
                cancelled=gallery_cancel,
            ):
                raise LinkingError('Linking session is busy; try again later.')
            try:
                if route != 'app_linking_revoke':
                    self._renew_if_expired(gallery_cancel)
                return self._request_owned(
                    route, after_id, body, multipart_boundary, content_length,
                    gallery_cancel, gallery_id, watch_key, tree_revision,
                )
            finally:
                self._request_gate.release()
        finally:
            if gallery_cancel is not None:
                with self._lock:
                    self._pending_gallery_cancels.discard(gallery_cancel)

    def _request_owned(
        self, route: str, after_id: int | None,
        body: bytes | Iterable[bytes] | None,
        multipart_boundary: str | None, content_length: int | None,
        gallery_cancel: threading.Event | None, gallery_id: int | None,
        watch_key: str | None, tree_revision: str | None,
    ) -> dict[str, JsonValue]:
        """Perform one authenticated operation while holding session admission.

        Args:
            route (str): Fixed scoped server operation.
            after_id (int | None): Exclusive gallery page cursor, if present.
            body (bytes | Iterable[bytes] | None): JSON or multipart body.
            multipart_boundary (str | None): Generated multipart boundary.
            content_length (int | None): Exact multipart body size in bytes.
            gallery_cancel (threading.Event | None): Dialog operation cancellation
                signal, independent of an active upload.
            gallery_id (int | None): Selected gallery ID for detail or deletion.
            watch_key (str | None): Supplemental key for capture traffic only.
            tree_revision (str | None): Optional exact complete-tree revision.

        Returns:
            dict[str, JsonValue]: Strict decoded server response object.

        Raises:
            LinkingError: The session or its server operation is unavailable.
        """
        with self._lock:
            if (self._closed or self._busy or not self._token
                    or (gallery_cancel is not None and gallery_cancel.is_set())
                    or (route != 'app_linking_revoke'
                        and time.monotonic() >= self._expires_at)):
                raise LinkingError('Linking session is unavailable.')
            if (route in ('app_linking_galleries', 'app_linking_gallery_tree')
                    and scope_base(self._scope) not in (GALLERIES_SCOPE, UPLOAD_SCOPE)):
                raise LinkingError('Gallery listing was not approved.')
            if (route == 'app_linking_galleries' and body is not None
                    and not scope_allows_gallery_search(self._scope)):
                raise LinkingError('Gallery search was not approved.')
            if (route == 'app_linking_gallery_detail'
                    and not scope_allows_gallery_read(self._scope)):
                raise LinkingError('Gallery detail was not approved.')
            if (route in ('app_linking_inventory', 'app_linking_upload')
                    and scope_base(self._scope) != UPLOAD_SCOPE):
                raise LinkingError('Gallery upload was not approved.')
            if (route in ('app_linking_gallery_create',
                          'app_linking_gallery_create_status',
                          'app_linking_gallery_create_abandon')
                    and not scope_allows_gallery_create(self._scope)):
                raise LinkingError('Gallery creation was not approved.')
            if (route == 'app_linking_gallery_visibility'
                    and not scope_allows_gallery_visibility(self._scope)):
                raise LinkingError('Gallery visibility was not approved.')
            if (route == 'app_linking_photo_visibility'
                    and not (scope_allows_admin_media(self._scope)
                             and scope_allows_gallery_visibility(self._scope))):
                raise LinkingError('Photo visibility was not approved.')
            if (route == 'app_linking_media_delete'
                    and not scope_allows_admin_media(self._scope)):
                raise LinkingError('Photo deletion requires an Admin Photos link.')
            self._busy = True
            self._active_route = route
            token = self._token
        watch_options = {'watch_key': watch_key} if watch_key is not None else {}
        try:
            if route == 'app_linking_gallery_detail':
                data = _request_json(
                    self._issuer, route, 'GET', None, token,
                    cancelled=self._cancelled, gallery_id=gallery_id,
                )
            elif route == 'app_linking_upload':
                data = _request_json(
                    self._issuer, route, 'POST', body, token,
                    cancelled=self._cancelled,
                    multipart_boundary=multipart_boundary,
                    content_length=content_length,
                    **watch_options,
                )
            elif route == 'app_linking_gallery_tree':
                data = _request_json(
                    self._issuer, route, 'GET', None, token,
                    cancelled=(gallery_cancel if gallery_cancel is not None
                               else self._cancelled),
                    tree_revision=tree_revision,
                )
            elif route == 'app_linking_galleries' and body is not None:
                data = _request_json(
                    self._issuer, route, 'POST', body, token,
                    cancelled=gallery_cancel if gallery_cancel is not None else self._cancelled,
                )
            elif route in ('app_linking_inventory', 'app_linking_gallery_create',
                           'app_linking_gallery_create_status',
                           'app_linking_gallery_create_abandon',
                           'app_linking_gallery_visibility', 'app_linking_photo_visibility',
                           'app_linking_media_delete'):
                data = _request_json(
                    self._issuer, route, 'POST', body, token,
                    cancelled=(gallery_cancel if route in (
                        'app_linking_inventory', 'app_linking_gallery_create',
                        'app_linking_gallery_visibility', 'app_linking_media_delete',
                        'app_linking_gallery_create_abandon', 'app_linking_photo_visibility')
                               and gallery_cancel is not None else self._cancelled),
                    **watch_options,
                )
            elif after_id is None:
                data = _request_json(
                    self._issuer, route,
                    'POST' if route == 'app_linking_revoke' else 'GET',
                    b'' if route == 'app_linking_revoke' else None, token,
                    cancelled=self._cancelled,
                )
            else:
                data = _request_json(
                    self._issuer, route, 'GET', None, token,
                    cancelled=self._cancelled, after_id=after_id,
                )
            with self._lock:
                if self._closed:
                    raise LinkingError('Linking session was cancelled.')
                if route == 'app_linking_revoke':
                    if data != {}:
                        raise LinkingError('Revocation response was not accepted.')
                    self._token = ''
                    self._closed = True
                    self._media_connection_pool.discard(close=True)
                return data
        except LinkingGrantRejected:
            with self._lock:
                self._private_preload_generation += 1
                self._private_preload_proofs.clear()
                # Never reuse a known rejected bearer. Renewable links may
                # rotate on the next request; this request is not replayed.
                self._expires_at = time.monotonic()
            raise
        finally:
            with self._lock:
                self._busy = False
                self._active_route = ''
                if not self._closed:
                    self._cancelled.clear()

    def cancel_pending(self, gallery_only: bool = False) -> None:
        """Cancel an active local request without revoking the server grant.

        Args:
            gallery_only (bool): Limit cancellation to the dialog's listing read.

        Returns:
            None: Signals an in-flight operation, leaving an open session usable.
        """
        with self._lock:
            if gallery_only or self._active_route == 'app_linking_media':
                for pending in self._pending_gallery_cancels:
                    pending.set()
            if self._busy and (not gallery_only
                               or self._active_route in (
                                   'app_linking_galleries',
                                   'app_linking_gallery_tree',
                                   'app_linking_gallery_detail',
                               )):
                self._cancelled.set()

    def can_read_media(self) -> bool:
        """Check whether this live listing link can request photo routes.

        Returns:
            bool: True for an unexpired gallery-listing grant. The server
                applies current photo visibility policy to every request.
        """
        return self.can_list_galleries()

    def can_read_admin_media(self) -> bool:
        """Check the local live grant for explicitly approved Admin photos.

        Returns:
            bool: True while the Admin photo permission is available locally.
        """
        return self.available() and scope_allows_admin_media(self._scope)

    def can_reuse_cached_media(self) -> bool:
        """Check the current bearer before reusing same-session decoded pixels.

        Returns:
            bool: True only for an unexpired live media grant. A renewable but
                expired session must complete its normal server path first.
        """
        with self._lock:
            return bool(
                not self._closed and self._token
                and time.monotonic() < self._expires_at
                and scope_base(self._scope) in (GALLERIES_SCOPE, UPLOAD_SCOPE)
            )

    def supports_admin_preload(self) -> bool:
        """Check whether discovery negotiated the explicit private cache lane.

        Returns:
            bool: True only when this session belongs to a capable Admin server.
        """
        with self._lock:
            return (not self._closed and self._admin_preload_supported
                    and scope_allows_admin_media(self._scope))

    def can_delete_admin_media(self) -> bool:
        """Check whether the current Admin Photos link can request deletion.

        Returns:
            bool: True while the Admin Photos grant is available locally.
                The server rechecks the live grant and selected photos.
        """
        return self.can_read_admin_media()

    def delete_media_photos(
        self, gallery_id: int, image_ids: tuple[int, ...],
        cancelled: threading.Event | None = None,
    ) -> tuple[int, ...]:
        """Delete one exact bounded photo set without replaying uncertain writes.

        Args:
            gallery_id (int): Exact selected physical gallery ID.
            image_ids (tuple[int, ...]): Unique positive direct-photo IDs.
            cancelled (threading.Event | None): Owning Photos operation cancellation.

        Returns:
            tuple[int, ...]: IDs confirmed by the server in submitted order.

        Raises:
            LinkingError: Scope, inputs, transport or exact acknowledgement failed.
        """
        if (not _native_media_id(gallery_id) or not image_ids
                or len(image_ids) > NATIVE_MEDIA_DELETE_BATCH_MAX
                or any(not _native_media_id(image_id) for image_id in image_ids)
                or len(set(image_ids)) != len(image_ids)):
            raise LinkingError('Invalid photo deletion selection.')
        if not self.can_delete_admin_media():
            raise LinkingError('Photo deletion requires an Admin Photos link.')
        body = json.dumps({
            'action': 'delete', 'gallery_id': gallery_id,
            'image_ids': list(image_ids),
        }, separators=(',', ':'), allow_nan=False).encode('utf-8')
        result = self._request(
            'app_linking_media_delete', body=body,
            gallery_cancel=cancelled,
        )
        deleted = result.get('deleted_ids')
        if (set(result) != {'deleted_ids'} or not isinstance(deleted, list)
                or any(type(item) is not int for item in deleted)
                or tuple(deleted) != image_ids):
            raise LinkingError('Photo deletion acknowledgement was not accepted.')
        return tuple(deleted)

    def can_change_photo_visibility(self) -> bool:
        """Check whether Admin photo and visibility consents are both live.

        Returns:
            bool: True when both required permissions are locally available.
        """
        return self.can_read_admin_media() and self.can_change_gallery_visibility()

    def can_read_presentation(self) -> bool:
        """Check the separately approved presentation and music permission.

        Returns:
            bool: True for a locally live grant containing both read scopes.
        """
        return self.available() and scope_allows_presentation(self._scope)

    def _media_read(
        self, gallery_id: int, kind: _NativeMediaKind,
        selector: int | str | None, cancelled: threading.Event | None,
        if_none_match: str | None = None, *, admin_preload: bool = False,
        timings: MediaReadTimings | None = None,
        proof_generation_out: list[int] | None = None,
    ) -> _NativeMediaResponse:
        """Serialize one exact media read under the shared session and HTTPS gates.

        Args:
            gallery_id (int): Selected physical gallery ID.
            kind (_NativeMediaKind): Page, settings, information, photo, thumbnail or track.
            selector (int | str | None): Cursor, photo ID, track ID, or no selector.
            cancelled (threading.Event | None): Current viewer action cancellation.
            if_none_match (str | None): Previously verified strong image validator.
            admin_preload (bool): Request the explicit versioned private-media lane.
            timings (MediaReadTimings | None): Optional caller-owned numeric
                timing record, without changing admission or response policy.
            proof_generation_out (list[int] | None): Optional private witness
                sink for the authorization generation after preflight renewal.
                The caller supplies an empty list and receives one integer.

        Returns:
            _NativeMediaResponse: Body/304 status, media type, validator, and
                negotiated cache permission.

        Raises:
            LinkingError: Invalid target, expired grant, unavailable media or unsafe transfer.
        """
        if not _native_media_id(gallery_id):
            raise LinkingError('Invalid gallery media target.')
        if (type(admin_preload) is not bool
                or (admin_preload and (kind not in ('photo', 'thumbnail')
                    or not scope_allows_admin_media(self._scope)))):
            raise LinkingError('Gallery media permission is unavailable.')
        query: dict[str, str] = {
            'page': 'app_linking_media', 'gallery_id': str(gallery_id), 'kind': kind,
        }
        if kind == 'photos' and selector is not None and _native_media_id(selector):
            query['after'] = str(selector)
        elif kind in ('photo', 'thumbnail', 'photo_info') and _native_media_id(selector):
            query['image_id'] = str(selector)
        elif kind == 'track' and isinstance(selector, str) and re.fullmatch(r'[a-f0-9]{32}', selector):
            query['track_id'] = selector
        elif not (kind in ('photos', 'presentation', 'info', 'gallery_info', 'revision')
                  and selector is None):
            raise LinkingError('Invalid gallery media selector.')
        encoded = urlencode(query)
        accepted = (_NATIVE_AUDIO_TYPES if kind == 'track' else _NATIVE_IMAGE_TYPES
                    if kind in ('photo', 'thumbnail') else ('application/json',))
        cancel = cancelled if cancelled is not None else threading.Event()
        with self._lock:
            self._pending_gallery_cancels.add(cancel)
        try:
            gate_started = time.perf_counter() if timings is not None else 0.0
            try:
                admitted = self._request_gate.acquire(
                    timeout=LINKING_SESSION_ADMISSION_TIMEOUT_SECONDS,
                    cancelled=cancel,
                )
            finally:
                if timings is not None:
                    # Convert the monotonic seconds to diagnostic milliseconds.
                    timings.gate_wait_ms += (
                        time.perf_counter() - gate_started
                    ) * 1000.0
            if not admitted:
                raise LinkingError('Linking session is busy; try again later.')
            try:
                renewal_started = (
                    time.perf_counter() if timings is not None else 0.0
                )
                try:
                    self._renew_if_expired(cancel)
                finally:
                    if timings is not None:
                        timings.renewal_ms += (
                            time.perf_counter() - renewal_started
                        ) * 1000.0
                with self._lock:
                    if proof_generation_out is not None:
                        proof_generation_out.append(
                            self._private_preload_generation,
                        )
                    photo_route_allowed = (
                        scope_base(self._scope) in (GALLERIES_SCOPE, UPLOAD_SCOPE)
                    )
                    admin_media = scope_allows_admin_media(self._scope)
                    route_allowed = photo_route_allowed
                    if kind in ('presentation', 'track'):
                        route_allowed = scope_allows_presentation(self._scope)
                    elif kind in ('info', 'gallery_info', 'photo_info', 'revision'):
                        route_allowed = admin_media
                    if (self._closed or self._busy or not self._token or cancel.is_set()
                            or time.monotonic() >= self._expires_at or not route_allowed
                            or (if_none_match is not None
                                and kind not in ('photo', 'thumbnail'))):
                        raise LinkingError('Gallery media permission is unavailable.')
                    self._busy = True
                    self._active_route = 'app_linking_media'
                    token = self._token
                try:
                    transport_started = (
                        time.perf_counter() if timings is not None else 0.0
                    )
                    if timings is not None:
                        timings.network_reads += 1
                    try:
                        result = _run_linking_transport(
                            lambda control: _native_media_read_once(
                                self._issuer, encoded, token, accepted, control,
                                if_none_match, allow_restricted=admin_media,
                                admin_preload=admin_preload,
                                pool=self._media_connection_pool,
                                timings=timings,
                            ), cancel, LINKING_REQUEST_DEADLINE_SECONDS,
                            LINKING_SOCKET_TIMEOUT_SECONDS,
                            phase='media', route='app_linking_media',
                            timings=timings,
                        )
                    finally:
                        if timings is not None:
                            timings.network_ms += (
                                time.perf_counter() - transport_started
                            ) * 1000.0
                    with self._lock:
                        if (cancel.is_set() or self._closed
                                or time.monotonic() >= self._expires_at):
                            raise LinkingError('Gallery media is no longer current.')
                    return result
                except LinkingGrantRejected:
                    self._media_connection_pool.discard()
                    with self._lock:
                        self._expires_at = time.monotonic()
                        self._private_preload_generation += 1
                        self._private_preload_proofs.clear()
                    raise
                except Exception:
                    self._media_connection_pool.discard()
                    raise
                finally:
                    with self._lock:
                        self._busy = False
                        self._active_route = ''
                        if not self._closed:
                            self._cancelled.clear()
            finally:
                self._request_gate.release()
        finally:
            with self._lock:
                self._pending_gallery_cancels.discard(cancel)

    def media_revision(
        self, gallery_id: int, cancelled: threading.Event | None = None,
    ) -> str:
        """Read the server's compact direct-photo aggregate revision.

        Args:
            gallery_id (int): Exact selected physical gallery ID.
            cancelled (threading.Event | None): Viewer lifetime cancellation.

        Returns:
            str: Validated lowercase SHA-256 aggregate revision.

        Raises:
            LinkingError: The endpoint, scope or response is unavailable.
        """
        payload, _mime, _etag, _cacheable = self._media_read(
            gallery_id, 'revision', None, cancelled,
        )
        if payload is None:
            raise LinkingError('Gallery revision was not accepted.')
        row = _native_media_json(payload)
        revision = row.get('revision')
        if (set(row) != {'gallery_id', 'revision'}
                or type(row.get('gallery_id')) is not int
                or row['gallery_id'] != gallery_id
                or not isinstance(revision, str)
                or re.fullmatch(r'[a-f0-9]{64}', revision) is None):
            raise LinkingError('Gallery revision was not accepted.')
        return revision

    def media_page(
        self, gallery_id: int, after_id: int | None = None,
        cancelled: threading.Event | None = None,
        *, timings: MediaReadTimings | None = None,
    ) -> LinkedMediaPage:
        """Read a bounded page of currently permitted direct gallery photos.

        Args:
            gallery_id (int): Selected physical gallery ID.
            after_id (int | None): Previous exclusive cursor or None for first page.
            cancelled (threading.Event | None): Current viewer cancellation.
            timings (MediaReadTimings | None): Optional metadata-only counters.

        Returns:
            LinkedMediaPage: Bound parent, ordered images and optional continuation.

        Raises:
            LinkingError: The response, target or permission is unavailable.
        """
        proof_generation: list[int] = []
        options: dict[str, MediaReadTimings | list[int]] = (
            {'timings': timings} if timings is not None else {}
        )
        if (scope_allows_admin_media(self._scope)
                and self._admin_preload_supported):
            options['proof_generation_out'] = proof_generation
        try:
            payload, _mime, _etag, _cacheable = self._media_read(
                gallery_id, 'photos', after_id, cancelled, **options,
            )
        except (LinkingMediaUnavailable, LinkingResponseError) as error:
            if (isinstance(error, LinkingMediaUnavailable)
                    or (error.phase == 'media'
                        and error.route == 'app_linking_media'
                        and error.status in (401, 403, 404))):
                # A known catalogue refusal supersedes a previous page proof;
                # a failed refresh cannot keep authorizing cached private bytes.
                with self._lock:
                    self._private_preload_generation += 1
                    self._private_preload_proofs.pop(gallery_id, None)
                    self._recent_media_pages.pop(gallery_id, None)
            raise
        if payload is None:
            raise LinkingError('Gallery photo page was not accepted.')
        # A missing transport witness cannot establish private reuse authority.
        request_generation = (
            proof_generation[0] if len(proof_generation) == 1 else -1
        )
        page = _native_media_json(payload)
        parent, rows, next_after = page.get('parent_id'), page.get('photos'), page.get('next_after')
        if (type(page.get('gallery_id')) is not int or page['gallery_id'] != gallery_id
                or 'parent_id' not in page or 'next_after' not in page
                or (parent is not None and (not _native_media_id(parent) or parent == gallery_id))
                or not isinstance(rows, list) or len(rows) > NATIVE_MEDIA_PAGE_SIZE):
            raise LinkingError('Gallery photo page was not accepted.')
        photos: list[LinkedPhoto] = []
        verified_at = time.monotonic()
        previous = after_id or 0
        for row in rows:
            if not isinstance(row, dict):
                raise LinkingError('Gallery photo row was not accepted.')
            image_id, title = row.get('id'), row.get('title')
            latitude, longitude = row.get('latitude'), row.get('longitude')
            has_location = latitude is not None or longitude is not None
            # Geographic bounds reject extreme integers before float conversion.
            valid_location = (
                isinstance(latitude, (int, float))
                and not isinstance(latitude, bool)
                and isinstance(longitude, (int, float))
                and not isinstance(longitude, bool)
                and -90.0 <= latitude <= 90.0
                and -180.0 <= longitude <= 180.0
                and math.isfinite(latitude)
                and math.isfinite(longitude)
            )
            if (type(image_id) is not int or not _native_media_id(image_id) or image_id <= previous
                    or not isinstance(title, str) or not _native_media_title(title)
                    or (has_location and not valid_location)):
                raise LinkingError('Gallery photo row was not accepted.')
            photo = (
                _linked_admin_photo(row) if scope_allows_admin_media(self._scope)
                else LinkedPhoto(
                    image_id, title,
                    thumbnail_etag=_linked_thumbnail_etag(row.get('thumbnail_etag')),
                )
            )
            photos.append(dataclass_replace(
                photo,
                latitude=float(latitude) if valid_location else None,
                longitude=float(longitude) if valid_location else None,
                thumbnail_verified_at=verified_at if photo.thumbnail_etag is not None else None,
                preload_verified_at=(
                    verified_at if photo.preload_thumbnail_etag is not None else None
                ),
            ))
            previous = image_id
        if (next_after is not None and (type(next_after) is not int
                or next_after != previous
                or len(photos) not in (NATIVE_MEDIA_LEGACY_PAGE_SIZE, NATIVE_MEDIA_PAGE_SIZE))):
            raise LinkingError('Gallery photo cursor was not accepted.')
        result = LinkedMediaPage(gallery_id, parent, tuple(photos), next_after)
        private_user_id: int | None = None
        private_full_opt_in = False
        if (scope_allows_admin_media(self._scope)
                and self._admin_preload_supported):
            try:
                private_user_id = self._verified_user_id()
                private_full_opt_in = self.gallery_preload_intent(gallery_id)
            except LinkingError:
                private_user_id = None
        with self._lock:
            if request_generation != self._private_preload_generation:
                private_user_id = None
            if private_user_id is None:
                self._private_preload_proofs.pop(gallery_id, None)
            else:
                self._private_preload_proof_epoch += 1
                page_proof_epoch = self._private_preload_proof_epoch
                existing = self._private_preload_proofs.get(gallery_id)
                proof_rows = (
                    dict(existing[2]) if existing is not None
                    and existing[0] == private_user_id
                    and after_id is not None else {}
                )
                # A first page starts a new listing; do not retain authority
                # for deleted rows absent from its refreshed catalogue.
                # A continuation also supersedes removed IDs within its
                # covered cursor range, including an empty final page.
                for prior_id in tuple(proof_rows):
                    if (prior_id > (after_id or 0)
                            and (next_after is None or prior_id <= previous)):
                        proof_rows.pop(prior_id, None)
                proof_rows = {
                    **proof_rows,
                    **{
                        photo.image_id: (
                            photo.preload_thumbnail_etag, verified_at,
                            page_proof_epoch, after_id,
                        )
                        for photo in photos
                        if photo.nsfw is False
                        and photo.preload_eligible is True
                        and photo.preload_thumbnail_etag is not None
                        and photo.preload_verified_at == verified_at
                    },
                }
                while len(proof_rows) > PRIVATE_PRELOAD_PROOF_ROW_LIMIT:
                    proof_rows.pop(next(iter(proof_rows)))
                self._private_preload_proofs[gallery_id] = (
                    private_user_id, verified_at, proof_rows, private_full_opt_in,
                )
            while len(self._private_preload_proofs) > MEDIA_RECENT_PAGE_LIMIT:
                oldest_gallery_id = next(iter(self._private_preload_proofs))
                del self._private_preload_proofs[oldest_gallery_id]
        if after_id is None:
            with self._lock:
                if not self._closed:
                    self._recent_media_pages[gallery_id] = (verified_at, result)
                    self._recent_media_pages.move_to_end(gallery_id)
                    while len(self._recent_media_pages) > MEDIA_RECENT_PAGE_LIMIT:
                        self._recent_media_pages.popitem(last=False)
        return result

    def recent_media_page(self, gallery_id: int) -> LinkedMediaPage | None:
        """Reuse recent album metadata while a fresh background read follows.

        Args:
            gallery_id (int): Exact selected gallery within this live session.

        Returns:
            LinkedMediaPage | None: Immutable previously authorized first page,
                or None after expiry, renewal, closure or a missing snapshot.
                Does not renew descriptor proofs or authorize any binary read.
                All server-returned rows retain their original visibility.
        """
        if not _native_media_id(gallery_id) or not self.can_read_media():
            return None
        with self._lock:
            now = time.monotonic()
            entry = self._recent_media_pages.get(gallery_id)
            if (self._closed or now >= self._expires_at or entry is None):
                return None
            stored_at, page = entry
            if not 0 <= now - stored_at < PHOTO_CACHE_FRESH_SECONDS:
                self._recent_media_pages.pop(gallery_id, None)
                return None
            self._recent_media_pages.move_to_end(gallery_id)
            return page

    def media_gallery_info(
        self, gallery_id: int, cancelled: threading.Event | None = None,
    ) -> LinkedMediaGalleryInfo:
        """Read the direct indexed original inventory with current Admin consent.

        Args:
            gallery_id (int): Exact selected physical gallery ID.
            cancelled (threading.Event | None): Current viewer cancellation.

        Returns:
            LinkedMediaGalleryInfo: Validated direct original counts and bytes.

        Raises:
            LinkingError: The target, permission or inventory is unavailable.
        """
        payload, _mime, _etag, _cacheable = self._media_read(
            gallery_id, 'info', None, cancelled,
        )
        if payload is None:
            raise LinkingError('Gallery inventory was not accepted.')
        return _linked_media_gallery_inventory(_native_media_json(payload), gallery_id)

    def media_gallery_details(
        self, gallery_id: int, *, cancelled: threading.Event | None = None,
    ) -> LinkedMediaDetails:
        """Read descriptive and inventory information for one Admin gallery.

        Args:
            gallery_id (int): Exact selected physical gallery ID.
            cancelled (threading.Event | None): Current information card owner.

        Returns:
            LinkedMediaDetails: Gallery ID, direct counts and size, and optional
                title, parent, child count, settings and dates.

        Raises:
            LinkingError: The extended response or current permission is unavailable.
        """
        payload, _mime, _etag, _cacheable = self._media_read(
            gallery_id, 'gallery_info', None, cancelled,
        )
        if payload is None:
            raise LinkingError('Gallery details were not accepted.')
        data = _native_media_json(payload)
        info = _linked_media_gallery_inventory(data, gallery_id)
        fields = _linked_media_details(data, (
            'title', 'parent_id', 'child_count', 'description', 'visibility',
            'nsfw', 'listed', 'created_at', 'updated_at', 'jpeg_quality', 'webp_quality',
        ))
        return fields | {
            'gallery_id': info.gallery_id, 'photo_count': info.photo_count,
            'file_count': info.file_count, 'size': info.size,
        }

    def media_photo_info(
        self, gallery_id: int, image_id: int, *, cancelled: threading.Event | None = None,
    ) -> LinkedMediaDetails:
        """Read indexed photo metadata without downloading the image.

        Args:
            gallery_id (int): Exact physical gallery owning the photo.
            image_id (int): Exact selected direct image ID.
            cancelled (threading.Event | None): Current information card owner.

        Returns:
            LinkedMediaDetails: Bound gallery and image IDs, title, and optional
                file, visibility, EXIF, derivative quality and GPS fields.

        Raises:
            LinkingError: The selected photo or its metadata is unavailable.
        """
        payload, _mime, _etag, _cacheable = self._media_read(
            gallery_id, 'photo_info', image_id, cancelled,
        )
        if payload is None:
            raise LinkingError('Photo details were not accepted.')
        data = _native_media_json(payload)
        title = data.get('title')
        if (type(data.get('gallery_id')) is not int or data['gallery_id'] != gallery_id
                or type(data.get('image_id')) is not int or data['image_id'] != image_id
                or not isinstance(title, str) or not title
                or len(title) > NATIVE_MEDIA_DETAIL_TEXT_CHARS):
            raise LinkingError('Photo details were not accepted.')
        fields = _linked_media_details(data, (
            'title', 'filename', 'description', 'size', 'mime_type', 'width', 'height',
            'display_width', 'display_height', 'exif_orientation', 'visibility',
            'nsfw', 'created_at', 'modified_at', 'exif_taken_at', 'exif_camera_make',
            'exif_camera_model', 'exif_lens_model', 'exif_focal_length',
            'exif_aperture', 'exif_exposure_time', 'exif_iso', 'gps_lat', 'gps_lng',
            'gps_altitude', 'jpeg_quality', 'webp_quality',
        ))
        return fields | {'gallery_id': gallery_id, 'image_id': image_id}

    def has_cached_photo(
        self, gallery_id: int, photo: LinkedPhoto, *, thumbnail: bool = False,
    ) -> bool:
        """Inspect verified locally stored photo bytes without network activity.

        Args:
            gallery_id (int): Current physical gallery containing the descriptor.
            photo (LinkedPhoto): Current authorized descriptor for a direct photo.
            thumbnail (bool): Inspect the thumbnail instead of the full rendition.

        Returns:
            bool: True for an intact eligible disk entry. An available thumbnail
                proof must match the entry's strong validator. Automatic Admin
                thumbnails use the ordinary pool without a full-media opt-in.
        """
        admin_media = scope_allows_admin_media(self._scope)
        private_opt_in = (
            admin_media and self._admin_preload_supported
            and photo.cache_eligible is not True
            and photo.preload_eligible is True
            and self.gallery_preload_intent(gallery_id)
        )
        private_mode = private_opt_in or (
            admin_media and self._admin_preload_supported and thumbnail
            and photo.cache_eligible is False and photo.preload_eligible is True
            and photo.nsfw is False
        )
        if (not _native_media_id(gallery_id) or not _native_media_id(photo.image_id)
                or not self.can_read_media()
                or (photo.cache_eligible is False and not private_mode)
                or (admin_media and photo.cache_eligible is not True
                    and not private_mode)):
            return False
        try:
            cache = GalleryMediaCache.for_current_user(
                NATIVE_MEDIA_MAX_BYTES, thumbnails=thumbnail,
            )
            key = (cache.private_key(
                self._issuer, self._verified_user_id(), gallery_id,
                photo.image_id, thumbnail,
            ) if private_mode else cache.key(
                self._issuer, gallery_id, photo.image_id, thumbnail,
            ))
            gallery_key = (
                None if private_mode and thumbnail and not private_opt_in
                else cache.gallery_key(self._issuer, gallery_id)
            )
            return cache.contains(
                key, (photo.preload_thumbnail_etag if private_mode else photo.thumbnail_etag)
                if thumbnail else None,
                gallery_key=gallery_key,
            )
        except (OSError, RuntimeError, ValueError):
            return False

    def gallery_pinned(self, gallery_id: int) -> bool:
        """Read one gallery's local retained-cache preference.

        Args:
            gallery_id (int): Exact physical gallery whose preference is read.

        Returns:
            bool: True when the current media-readable link has a retained pin.
                False for an absent pin, invalid target or unavailable cache.
                This preference grants no remote or offline access.
        """
        if not _native_media_id(gallery_id) or not self.can_read_media():
            return False
        try:
            cache = GalleryMediaCache.for_current_user(NATIVE_MEDIA_MAX_BYTES)
            return cache.is_gallery_pinned(self._issuer, gallery_id)
        except (OSError, RuntimeError, ValueError):
            return False

    def set_gallery_pinned(self, gallery_id: int, pinned: bool) -> bool:
        """Change one gallery's local cache retention preference.

        Args:
            gallery_id (int): Exact physical gallery whose preference changes.
            pinned (bool): Whether verified cache bytes should be retained.

        Returns:
            bool: True when local persistence or unpin conversion succeeds.
                False when validation, storage or conversion fails. This
                preference grants no remote or offline access.
        """
        if (not _native_media_id(gallery_id) or type(pinned) is not bool
                or not self.can_read_media()):
            return False
        try:
            cache = GalleryMediaCache.for_current_user(NATIVE_MEDIA_MAX_BYTES)
            return cache.set_gallery_pinned(self._issuer, gallery_id, pinned)
        except (OSError, RuntimeError, ValueError):
            return False

    def gallery_preload_intent(self, gallery_id: int) -> bool:
        """Read explicit private-media reuse intent for this verified account.

        Args:
            gallery_id (int): Exact physical gallery whose local intent is read.

        Returns:
            bool: True for the current verified account's intact marker only.
                This preference grants no remote or offline access.
        """
        if (not _native_media_id(gallery_id) or not self.can_read_admin_media()
                or not self.supports_admin_preload()):
            return False
        try:
            cache = GalleryMediaCache.for_current_user(NATIVE_MEDIA_MAX_BYTES)
            return cache.has_private_preload_intent(
                self._issuer, self._verified_user_id(), gallery_id,
            )
        except (OSError, RuntimeError, ValueError, LinkingError):
            return False

    def set_gallery_preload_intent(
        self, gallery_id: int, enabled: bool,
    ) -> bool:
        """Persist or clear private reuse intent for the verified account.

        Args:
            gallery_id (int): Exact physical gallery whose local intent changes.
            enabled (bool): Whether later viewers may revalidate stored private bytes.

        Returns:
            bool: True when the account-bound local marker is confirmed changed.
                This preference grants no remote or offline access.
        """
        if (not _native_media_id(gallery_id) or type(enabled) is not bool
                or not self.can_read_admin_media()
                or not self.supports_admin_preload()):
            return False
        with self._lock:
            self._private_preload_generation += 1
            self._private_preload_proofs.pop(gallery_id, None)
        try:
            cache = GalleryMediaCache.for_current_user(NATIVE_MEDIA_MAX_BYTES)
            changed = cache.set_private_preload_intent(
                self._issuer, self._verified_user_id(), gallery_id, enabled,
            )
            return changed
        except (OSError, RuntimeError, ValueError, LinkingError):
            return False

    def prepare_cached_photo(
        self, gallery_id: int, photo: LinkedPhoto, *, thumbnail: bool = False,
        receipt: PhotoCacheReceipt | None = None,
    ) -> bool:
        """Verify one local photo body without remote revalidation.

        Args:
            gallery_id (int): Current physical gallery containing the descriptor.
            photo (LinkedPhoto): Current authorized direct-photo descriptor.
            thumbnail (bool): Verify the thumbnail instead of full rendition.
            receipt (PhotoCacheReceipt | None): Same-operation verified file
                witness; unchanged identity avoids another body hash.

        Returns:
            bool: True for a hash-valid locally retained eligible body. This
                local result is not current server authorization or availability.
                Automatic thumbnails need no saved full-media opt-in.
                False for a miss, ineligible descriptor or failed retention.
        """
        admin_media = scope_allows_admin_media(self._scope)
        private_opt_in = (
            admin_media and self._admin_preload_supported
            and photo.cache_eligible is not True
            and photo.preload_eligible is True
            and self.gallery_preload_intent(gallery_id)
        )
        private_mode = private_opt_in or (
            admin_media and self._admin_preload_supported and thumbnail
            and photo.cache_eligible is False and photo.preload_eligible is True
            and photo.nsfw is False
        )
        if (not _native_media_id(gallery_id) or not _native_media_id(photo.image_id)
                or not self.can_read_media()
                or (photo.cache_eligible is False and not private_mode)
                or (admin_media and photo.cache_eligible is not True
                    and not private_mode)):
            return False
        try:
            cache = GalleryMediaCache.for_current_user(
                NATIVE_MEDIA_MAX_BYTES, thumbnails=thumbnail,
            )
            key = (cache.private_key(
                self._issuer, self._verified_user_id(), gallery_id,
                photo.image_id, thumbnail,
            ) if private_mode else cache.key(
                self._issuer, gallery_id, photo.image_id, thumbnail,
            ))
            gallery_key = (
                None if private_mode and thumbnail and not private_opt_in
                else cache.gallery_key(self._issuer, gallery_id)
            )
            expected_etag = (
                photo.preload_thumbnail_etag if private_mode
                else photo.thumbnail_etag
            )
            if receipt is not None:
                return (
                    (not thumbnail or expected_etag is None
                     or receipt.etag == expected_etag)
                    and cache.receipt_current(
                        receipt, key=key, gallery_key=gallery_key,
                    )
                )
            entry = cache.read(key, gallery_key=gallery_key)
            if entry is None:
                return False
            expected_etag = (
                photo.preload_thumbnail_etag if private_mode
                else photo.thumbnail_etag
            )
            return not thumbnail or expected_etag is None or entry.etag == expected_etag
        except (OSError, RuntimeError, ValueError):
            return False

    def _public_cached_entry_current(
        self, cache: GalleryMediaCache, entry: PhotoCacheEntry,
        thumbnail: bool, thumbnail_etag: str | None,
        thumbnail_verified_at: float | None,
        cancelled: threading.Event | None,
    ) -> bool:
        """Apply the shared public disk freshness and live-session fence.

        Args:
            cache (GalleryMediaCache): Verified local media cache owner.
            entry (PhotoCacheEntry): Hash-validated stored body and validator.
            thumbnail (bool): Whether catalogue thumbnail proofs apply.
            thumbnail_etag (str | None): Exact authorized descriptor validator.
            thumbnail_verified_at (float | None): Monotonic proof receipt time.
            cancelled (threading.Event | None): Current read cancellation.

        Returns:
            bool: True only for current authorized public bytes. This helper
                never admits private entries or starts renewal/network work.
        """
        if thumbnail and thumbnail_etag is not None and entry.etag != thumbnail_etag:
            return False
        descriptor_current = (
            thumbnail and thumbnail_etag is not None
            and thumbnail_verified_at is not None
            and 0 <= time.monotonic() - thumbnail_verified_at
            < PHOTO_CACHE_FRESH_SECONDS
        )
        if not descriptor_current and not cache.is_fresh(entry):
            return False
        with self._lock:
            return (
                not self._closed and time.monotonic() < self._expires_at
                and (cancelled is None or not cancelled.is_set())
            )

    def _private_preload_reuse_user_id(
        self, gallery_id: int, image_id: int,
        cache_eligible: bool | None, preload_eligible: bool | None,
        preload_thumbnail_etag: str | None,
        preload_verified_at: float | None,
        cancelled: threading.Event | None = None,
        *, thumbnail: bool = False,
    ) -> int | None:
        """Use a current session proof for the same photo and strong validator.

        Args:
            gallery_id (int): Current gallery owning the photo descriptor.
            image_id (int): Exact photo identity in the current server page.
            cache_eligible (bool | None): Independent public admission fact.
            preload_eligible (bool | None): Current server admission fact.
            preload_thumbnail_etag (str | None): Exact strong page validator.
            preload_verified_at (float | None): Descriptor's original page time;
                a later real session proof may supersede this observation.
            cancelled (threading.Event | None): Optional operation cancellation.
            thumbnail (bool): Allow ordinary thumbnail reuse without a saved
                full-media opt-in; full images still require that opt-in.

        Returns:
            int | None: Bound account ID only when the exact strong thumbnail
                ledger proof is fresh, full-media intent is present when needed,
                and the same unexpired Admin session still owns that account.
                Caller timestamps never refresh or create ledger authority.
        """
        if (not _native_media_id(gallery_id)
                or not _native_media_id(image_id)
                or cache_eligible is not False
                or preload_eligible is not True
                or type(thumbnail) is not bool
                or not isinstance(preload_thumbnail_etag, str)
                or re.fullmatch(r'"[a-f0-9]{64}"', preload_thumbnail_etag) is None
                or type(preload_verified_at) not in (int, float)
                or (cancelled is not None and cancelled.is_set())
                or not self.can_reuse_cached_media()
                or not self.can_read_admin_media()
                or not self.supports_admin_preload()):
            return None
        now = time.monotonic()
        if not 0 <= preload_verified_at <= now:
            return None
        with self._lock:
            proof = self._private_preload_proofs.get(gallery_id)
            photo_proof = (
                proof[2].get(image_id) if proof is not None else None
            )
            if (self._closed or proof is None or photo_proof is None
                    or (not thumbnail and not proof[3])
                    or photo_proof[0] != preload_thumbnail_etag
                    or photo_proof[1] < preload_verified_at
                    or not 0 <= now - photo_proof[1]
                    < PHOTO_CACHE_FRESH_SECONDS):
                return None
            user_id = proof[0]
            if user_id != self._bound_user_id:
                return None
        if (cancelled is not None and cancelled.is_set()) or not (
            self.can_reuse_cached_media() and self.can_read_admin_media()
        ):
            return None
        return user_id

    def _invalidate_private_preload_photo_proof(
        self, gallery_id: int, image_id: int,
    ) -> None:
        """Forget one private proof after the server rejects cache admission.

        Args:
            gallery_id (int): Gallery owning the rejected media response.
            image_id (int): Exact photo whose current policy changed.

        Returns:
            None: A later fresh page must establish this photo's proof again.
        """
        with self._lock:
            proof = self._private_preload_proofs.get(gallery_id)
            if proof is None:
                return
            # A queued binary request may carry an older descriptor than the
            # current page. Its known refusal must still block this photo's
            # cached bytes until another authenticated page confirms policy.
            if image_id in proof[2]:
                self._private_preload_generation += 1
                self._private_preload_proof_epoch += 1
                proof[2].pop(image_id, None)

    def private_preload_user_id(
        self, gallery_id: int, photo: LinkedPhoto, *, thumbnail: bool = False,
    ) -> int | None:
        """Return the account for a current RAM-registered Admin proof.

        Args:
            gallery_id (int): Exact gallery that supplied the page descriptor.
            photo (LinkedPhoto): Current server descriptor from that page.
            thumbnail (bool): Select automatic thumbnail authority rather than
                the saved full-media preload opt-in.

        Returns:
            int | None: Bound account ID when the exact non-NSFW proof is fresh
                and the selected rendition's storage policy permits reuse.
        """
        if photo.nsfw is not False:
            return None
        return self._private_preload_reuse_user_id(
            gallery_id, photo.image_id, photo.cache_eligible,
            photo.preload_eligible,
            photo.preload_thumbnail_etag, photo.preload_verified_at,
            thumbnail=thumbnail,
        )

    def private_preload_cache_identity(
        self, gallery_id: int, photo: LinkedPhoto, *, thumbnail: bool = False,
    ) -> tuple[int, str, float, int] | None:
        """Return the exact private descriptor identity for session RAM keys.

        Args:
            gallery_id (int): Exact gallery that supplied the descriptor.
            photo (LinkedPhoto): Non-NSFW Admin descriptor whose validator must
                still match the current session ledger.
            thumbnail (bool): Select automatic thumbnail authority; full
                prepared images continue to require saved preload intent.

        Returns:
            tuple[int, str, float, int] | None: Verified account, strong
                validator, original metadata time and row proof epoch, or None
                after revocation, policy change, intent change or expiry.
        """
        user_id = self.private_preload_user_id(
            gallery_id, photo, thumbnail=thumbnail,
        )
        if user_id is None:
            return None
        with self._lock:
            proof = self._private_preload_proofs.get(gallery_id)
            row = proof[2].get(photo.image_id) if proof is not None else None
            if (self._closed or proof is None or proof[0] != user_id
                    or (not thumbnail and not proof[3])
                    or proof[0] != self._bound_user_id
                    or time.monotonic() >= self._expires_at
                    or row is None
                    or row[0] != photo.preload_thumbnail_etag
                    or photo.preload_verified_at is None
                    or row[1] < photo.preload_verified_at
                    or not 0 <= time.monotonic() - row[1]
                    < PHOTO_CACHE_FRESH_SECONDS):
                return None
            return (user_id, row[0], row[1], row[2])

    def _private_preload_intent_user_id(
        self, gallery_id: int,
    ) -> int | None:
        """Return a recently confirmed account intent for server transport.

        Args:
            gallery_id (int): Gallery whose saved intent was verified by a page.

        Returns:
            int | None: Same live Admin account while that confirmation is
                recent, or None when a page must confirm it again.
        """
        if (not _native_media_id(gallery_id)
                or not self.can_reuse_cached_media()
                or not self.can_read_admin_media()
                or not self.supports_admin_preload()):
            return None
        with self._lock:
            proof = self._private_preload_proofs.get(gallery_id)
            if (self._closed or proof is None
                    or proof[0] != self._bound_user_id
                    or not proof[3]
                    or not 0 <= time.monotonic() - proof[1]
                    < PHOTO_CACHE_FRESH_SECONDS):
                return None
            return proof[0]

    def _preloaded_snapshot_user_id(
        self, gallery_id: int, image_id: int,
        cancelled: threading.Event | None = None,
    ) -> int | None:
        """Authorize a downloaded full copy using the current live catalogue.

        Args:
            gallery_id (int): Exact gallery containing the downloaded photo.
            image_id (int): Photo whose current server admission is required.
            cancelled (threading.Event | None): Owning viewer cancellation.

        Returns:
            int | None: Same verified account with saved full-media intent and
                a fresh non-NSFW catalogue row, or None when authorization needs
                renewal. This authorizes the saved version, not HTTP freshness
                or equality to the server's current full rendition.
        """
        if (not _native_media_id(gallery_id)
                or not _native_media_id(image_id)
                or (cancelled is not None and cancelled.is_set())
                or not self.can_reuse_cached_media()
                or not self.can_read_admin_media()
                or not self.supports_admin_preload()):
            return None
        with self._lock:
            now = time.monotonic()
            proof = self._private_preload_proofs.get(gallery_id)
            row = proof[2].get(image_id) if proof is not None else None
            # Purpose: Bound access rechecks for locally downloaded full images.
            # Type: int. Units: seconds. Scope: one live catalogue row.
            # Consumers: downloaded full-copy authorization. Rationale: reuse
            # the existing 60-second PHOTO_CACHE_FRESH_SECONDS access window;
            # the downloaded body's age does not renew or extend that window.
            if (self._closed or now >= self._expires_at
                    or proof is None or not proof[3]
                    or proof[0] != self._bound_user_id
                    or row is None
                    or not 0 <= now - row[1] < PHOTO_CACHE_FRESH_SECONDS
                    or (cancelled is not None and cancelled.is_set())):
                return None
            return proof[0]

    def _refresh_preloaded_snapshot_user_id(
        self, gallery_id: int, image_id: int, user_id: int,
        cancelled: threading.Event | None,
        *, timings: MediaReadTimings | None = None,
    ) -> int | None:
        """Refresh page authorization before displaying an older downloaded copy.

        Args:
            gallery_id (int): Exact gallery containing the saved full image.
            image_id (int): Target photo whose catalogue permission expired.
            user_id (int): Verified account owning the already-read cache file.
            cancelled (threading.Event | None): Owning fullscreen operation.
            timings (MediaReadTimings | None): Optional metadata request metrics.

        Returns:
            int | None: The same account after a fresh authenticated page
                confirms the target and saved intent, or None for a missing or
                newly ineligible row. No full image is requested here.

        Raises:
            LinkingError: Catalogue refresh, session renewal or cancellation
                failed; the stored copy is never an offline fallback.
        """
        current = self._preloaded_snapshot_user_id(
            gallery_id, image_id, cancelled,
        )
        if current == user_id:
            return current
        with self._lock:
            if (self._closed or self._bound_user_id != user_id
                    or (cancelled is not None and cancelled.is_set())):
                return None
            proof = self._private_preload_proofs.get(gallery_id)
            row = (
                proof[2].get(image_id)
                if proof is not None and proof[0] == user_id else None
            )
            # Preserve the original page cursor before media_page replaces old
            # rows. If a bounded ledger evicted this row, an exclusive cursor
            # just below its ID starts a new page at that photo and its peers.
            after_id = row[3] if row is not None else (image_id - 1 or None)
        options = {'timings': timings} if timings is not None else {}
        self.media_page(gallery_id, after_id, cancelled, **options)
        current = self._preloaded_snapshot_user_id(
            gallery_id, image_id, cancelled,
        )
        return current if current == user_id else None

    def cached_media_photo(
        self, gallery_id: int, photo: LinkedPhoto, *, thumbnail: bool = True,
        cancelled: threading.Event | None = None,
        timings: MediaReadTimings | None = None,
        use_preloaded_snapshot: bool = False,
    ) -> tuple[bytes, str] | None:
        """Return an authorized verified disk hit without starting network work.

        Args:
            gallery_id (int): Current gallery that owns the photo descriptor.
            photo (LinkedPhoto): Current authorized direct-photo descriptor.
            thumbnail (bool): Whether to look up the thumbnail rendition.
            cancelled (threading.Event | None): Optional viewer cancellation.
            timings (MediaReadTimings | None): Optional caller-owned read metrics.
            use_preloaded_snapshot (bool): For full images, accept the saved
                downloaded version under fresh live catalogue authorization,
                independently of its HTTP response age. Never refreshes metadata.

        Returns:
            tuple[bytes, str] | None: Verified body and MIME for an authorized
                local hit, or None when network authorization/revalidation would
                be required. Snapshot mode returns the downloaded version.

        Raises:
            LinkingError: The gallery, descriptor or session is not authorized.
        """
        if (type(thumbnail) is not bool
                or type(use_preloaded_snapshot) is not bool):
            raise LinkingError('Invalid gallery photo rendition.')
        if (not _native_media_id(gallery_id)
                or not _native_media_id(photo.image_id)
                or (cancelled is not None and cancelled.is_set())
                or not self.can_read_media()):
            raise LinkingError('Gallery photo permission is unavailable.')
        admin_media = scope_allows_admin_media(self._scope)
        snapshot_mode = use_preloaded_snapshot and not thumbnail
        private_user_id: int | None = None
        if admin_media and photo.nsfw is False:
            private_user_id = (
                self._preloaded_snapshot_user_id(
                    gallery_id, photo.image_id, cancelled,
                ) if snapshot_mode else self._private_preload_reuse_user_id(
                    gallery_id, photo.image_id, photo.cache_eligible,
                    photo.preload_eligible, photo.preload_thumbnail_etag,
                    photo.preload_verified_at, cancelled,
                    thumbnail=thumbnail,
                )
            )
        private_mode = private_user_id is not None
        cache_allowed = (
            photo.cache_eligible is True
            or (photo.cache_eligible is None and not admin_media)
            or private_mode
        )
        if not cache_allowed:
            if timings is not None:
                timings.cache_state = 'ineligible'
            return None
        cache: GalleryMediaCache | None = None
        entry = None
        started = time.perf_counter() if timings is not None else 0.0
        try:
            cache = GalleryMediaCache.for_current_user(
                NATIVE_MEDIA_MAX_BYTES, thumbnails=thumbnail,
            )
            key = (cache.private_key(
                self._issuer, private_user_id, gallery_id,
                photo.image_id, thumbnail,
            ) if private_mode else cache.key(
                self._issuer, gallery_id, photo.image_id, thumbnail,
            ))
            gallery_key = (
                None if private_mode and thumbnail
                and self._private_preload_intent_user_id(gallery_id) is None
                else cache.gallery_key(self._issuer, gallery_id)
            )
            entry = cache.read(key, gallery_key=gallery_key)
        except (OSError, RuntimeError, ValueError):
            cache = None
            entry = None
        finally:
            if timings is not None:
                timings.disk_read_ms += (time.perf_counter() - started) * 1000.0
        if entry is None or cache is None:
            if timings is not None:
                timings.cache_state = 'miss'
            return None
        private_current = bool(
            private_mode
            and (entry.etag == photo.preload_thumbnail_etag if thumbnail
                 else snapshot_mode or cache.is_fresh(entry))
            and (self._preloaded_snapshot_user_id(
                gallery_id, photo.image_id, cancelled,
            ) if snapshot_mode else self._private_preload_reuse_user_id(
                gallery_id, photo.image_id, photo.cache_eligible,
                photo.preload_eligible, photo.preload_thumbnail_etag,
                photo.preload_verified_at, cancelled,
                thumbnail=thumbnail,
            )) == private_user_id
        )
        public_current = (
            not private_mode and self._public_cached_entry_current(
                cache, entry, thumbnail, photo.thumbnail_etag,
                photo.thumbnail_verified_at, cancelled,
            )
        )
        if not (private_current or public_current):
            if timings is not None:
                timings.cache_state = (
                    'changed' if thumbnail and photo.thumbnail_etag is not None
                    and photo.thumbnail_etag != entry.etag
                    else 'expired' if time.monotonic() >= self._expires_at
                    else 'revalidation'
                )
            return None
        if timings is not None:
            timings.source = 'disk'
            timings.cache_state = 'hit'
        return entry.body, entry.mime

    def media_photo(
        self, gallery_id: int, image_id: int, thumbnail: bool,
        cancelled: threading.Event | None = None, *,
        cache_eligible: bool | None = None, thumbnail_etag: str | None = None,
        thumbnail_verified_at: float | None = None,
        preload_eligible: bool | None = None,
        preload_thumbnail_etag: str | None = None,
        preload_verified_at: float | None = None,
        timings: MediaReadTimings | None = None,
        cache_receipts: list[PhotoCacheReceipt] | None = None,
        use_preloaded_snapshot: bool = False,
    ) -> tuple[bytes, str]:
        """Read one allowed image, retrying only an exact refused 304 safely.

        Args:
            gallery_id (int): Selected gallery ID to which the image must belong.
            image_id (int): Exact server photo ID.
            thumbnail (bool): True for the existing small rendition.
            cancelled (threading.Event | None): Current viewer cancellation.
            cache_eligible (bool | None): Current independent public admission
                from the authorized descriptor, or None when unknown.
            thumbnail_etag (str | None): Exact current authorized thumbnail
                proof from the gallery page, or None for normal freshness checks.
            thumbnail_verified_at (float | None): Local monotonic time at which
                that exact authorized catalogue page was read.
            preload_eligible (bool | None): Current non-NSFW Admin admission.
            preload_thumbnail_etag (str | None): Current strong Admin thumbnail proof.
            preload_verified_at (float | None): Monotonic time of that proof.
            timings (MediaReadTimings | None): Optional fresh per-read numeric
                observation record; accepted source is recorded only after the
                existing authorization, cache and cancellation checks pass.
            cache_receipts (list[PhotoCacheReceipt] | None): Optional operation
                sink for confirmed read/write identities without retaining bodies.
            use_preloaded_snapshot (bool): Display the downloaded full version
                without revalidating its body age. Requires saved account intent
                and live catalogue permission, refreshing metadata when needed.
                Normal reads and explicit preload keep HTTP freshness checks.

        Returns:
            tuple[bytes, str]: Authorized raster bytes and MIME; local copies
                are hash verified. Snapshot mode may return an older downloaded
                version; normal reads never return bytes from a rejected
                not-modified response.

        Raises:
            LinkingError: The image, permission, file or transport is unavailable.
            LinkingResponseError: A refused conditional read is not eligible
                for the one unconditional refresh or that refresh also fails.
        """
        if timings is not None:
            timings.source = 'unknown'
            timings.cache_stored = False
            timings.stored_bytes = 0
        if (type(thumbnail) is not bool
                or type(use_preloaded_snapshot) is not bool):
            raise LinkingError('Invalid gallery photo rendition.')
        if (not _native_media_id(gallery_id) or not _native_media_id(image_id)
                or (cancelled is not None and cancelled.is_set())
                or not self.can_read_media()):
            raise LinkingError('Gallery photo permission is unavailable.')
        admin_media = scope_allows_admin_media(self._scope)
        # Negotiate the server's verified binary representation independently
        # from local persistence. First viewing must use the same supported
        # media protocol as preload without requiring a saved preload marker.
        # Ordinary account-bound thumbnails are cached automatically; retained
        # gallery files and full images still require saved local preload intent.
        admin_transfer = (
            admin_media and self._admin_preload_supported
            and cache_eligible is False and preload_eligible is True
        )
        private_reuse_user_id = (
            self._private_preload_reuse_user_id(
                gallery_id, image_id, cache_eligible, preload_eligible,
                preload_thumbnail_etag, preload_verified_at, cancelled,
                thumbnail=thumbnail,
            ) if admin_media else None
        )
        private_intent_user_id: int | None = None
        private_cache_user_id: int | None = None
        private_intent_generation: int | None = None
        if (admin_media and self._admin_preload_supported
                and cache_eligible is False and preload_eligible is True):
            with self._lock:
                private_intent_generation = self._private_preload_generation
            private_intent_user_id = self._private_preload_intent_user_id(
                gallery_id,
            )
            if private_intent_user_id is None and (
                not thumbnail or private_reuse_user_id is None
            ):
                try:
                    if self.gallery_preload_intent(gallery_id):
                        private_intent_user_id = self._verified_user_id()
                except LinkingError:
                    private_intent_user_id = None
            private_cache_user_id = private_intent_user_id
            if thumbnail and private_cache_user_id is None:
                try:
                    private_cache_user_id = (
                        private_reuse_user_id if private_reuse_user_id is not None
                        else self._verified_user_id()
                    )
                except LinkingError:
                    # Unknown account means transient display, never a guessed
                    # cache namespace. The binary route still enforces its grant.
                    private_cache_user_id = None
        private_mode = (
            admin_media and self._admin_preload_supported
            and private_cache_user_id is not None
        )
        private_user_id = (
            private_reuse_user_id if private_reuse_user_id is not None
            else private_cache_user_id
        )
        cache_allowed = (cache_eligible is True or private_mode
                         or (cache_eligible is None and not admin_media))
        cache: GalleryMediaCache | None = None
        key = ''
        gallery_key: str | None = None
        entry = None
        cache_observed: dict[str, int] | None = None
        disk_started = (
            time.perf_counter()
            if timings is not None and cache_allowed else 0.0
        )
        try:
            if cache_allowed:
                cache = GalleryMediaCache.for_current_user(
                    NATIVE_MEDIA_MAX_BYTES, thumbnails=thumbnail,
                )
                if timings is not None:
                    cache_observed = cache.operation_counts()
                key = (cache.private_key(
                    self._issuer, private_user_id, gallery_id,
                    image_id, thumbnail,
                ) if private_mode else GalleryMediaCache.key(
                    self._issuer, gallery_id, image_id, thumbnail,
                ))
                gallery_key = (
                    None if private_mode and thumbnail
                    and private_intent_user_id is None
                    else cache.gallery_key(self._issuer, gallery_id)
                )
                entry = cache.read(key, gallery_key=gallery_key)
        except (OSError, RuntimeError, ValueError):
            cache = None
            entry = None
        finally:
            if timings is not None and cache_allowed:
                timings.disk_read_ms += (
                    time.perf_counter() - disk_started
                ) * 1000.0
                if cache is not None:
                    cache_observed = timings.observe_cache(cache, cache_observed)
        if timings is not None:
            timings.cache_state = (
                'ineligible' if not cache_allowed
                else 'miss' if entry is None
                else 'changed' if thumbnail and thumbnail_etag is not None
                and entry.etag != thumbnail_etag else 'revalidation'
            )
        private_reuse_user_id = (
            private_reuse_user_id if private_mode else None
        )
        if (private_user_id is not None and cache is not None):
            key = cache.private_key(
                self._issuer, private_user_id, gallery_id,
                image_id, thumbnail,
            )
        public_current = (
            not private_mode and cache is not None and entry is not None
            and self._public_cached_entry_current(
                cache, entry, thumbnail, thumbnail_etag,
                thumbnail_verified_at, cancelled,
            )
        )
        private_current = (
            private_reuse_user_id is not None and cache is not None
            and entry is not None
            and (entry.etag == preload_thumbnail_etag if thumbnail
                 else cache.is_fresh(entry))
            and self._private_preload_reuse_user_id(
                gallery_id, image_id, cache_eligible, preload_eligible,
                preload_thumbnail_etag, preload_verified_at, cancelled,
                thumbnail=thumbnail,
            ) == private_reuse_user_id
        )
        if (use_preloaded_snapshot and not thumbnail and private_mode
                and private_user_id is not None
                and cache is not None and entry is not None):
            # A preloaded full image is a downloaded artifact, not a claim that
            # its old HTTP response is fresh. Recheck access with small catalogue
            # pages, then use the hash-verified local body. Explicit Preload uses
            # the default mode above and still refreshes the actual full content.
            private_current = (
                self._refresh_preloaded_snapshot_user_id(
                    gallery_id, image_id, private_user_id, cancelled,
                    timings=timings,
                ) == private_user_id
            )
        if (cache is not None and entry is not None
                and (public_current or private_current)):
            if timings is not None:
                timings.source = 'disk'
                timings.cache_state = 'hit'
            if cache_receipts is not None:
                cache_receipts.append(PhotoCacheReceipt(
                    entry.path, entry.file_info, entry.etag, entry.mime,
                ))
            return entry.body, entry.mime

        kind: _NativeMediaKind = 'thumbnail' if thumbnail else 'photo'
        read_options: dict[str, bool | MediaReadTimings] = (
            {'admin_preload': True} if admin_transfer else {}
        )
        if timings is not None:
            read_options['timings'] = timings
        # Share one unconditional read budget with the legacy no-store 304 lane.
        unconditional_requested = False
        try:
            response = self._media_read(
                gallery_id, kind, image_id, cancelled,
                entry.etag if entry is not None else None,
                **read_options,
            )
        except LinkingMediaUnavailable:
            if private_mode:
                self._invalidate_private_preload_photo_proof(
                    gallery_id, image_id,
                )
            raise
        except LinkingResponseError as error:
            if (private_mode and error.phase == 'media'
                    and error.route == 'app_linking_media'
                    and error.status in (401, 403, 404)):
                self._invalidate_private_preload_photo_proof(
                    gallery_id, image_id,
                )
            facts = error.media_policy_facts
            if not (
                entry is not None and entry.etag is not None
                and error.phase == 'media'
                and error.route == 'app_linking_media'
                and error.status == 304
                and error.reason == 'response_policy'
                and type(facts) is MediaPolicyFacts
                and not facts.encoding_present
            ):
                raise
            if cancelled is not None and cancelled.is_set():
                raise
            logging.getLogger(__name__).info(
                'Conditional media 304 was refused by response policy; '
                'requesting one unconditional refresh.',
            )
            unconditional_requested = True
            response = self._media_read(
                gallery_id, kind, image_id, cancelled,
                **read_options,
            )
        body, mime, etag, cacheable = response
        revalidated = False
        if body is None:
            if entry is None or unconditional_requested:
                raise LinkingError('Gallery photo revalidation was not accepted.')
            if cacheable and etag == entry.etag:
                body, mime = entry.body, entry.mime
                revalidated = True
            else:
                # Older servers may answer a conditional read with no-store.
                # Fetch once without the validator to keep those servers usable.
                unconditional_requested = True
                body, mime, etag, cacheable = self._media_read(
                    gallery_id, kind, image_id, cancelled,
                    **read_options,
                )
                if body is None:
                    raise LinkingError('Gallery photo refresh was not accepted.')
        if private_mode and not cacheable:
            self._invalidate_private_preload_photo_proof(
                gallery_id, image_id,
            )
        if private_mode and private_intent_generation is not None:
            with self._lock:
                intent_still_current = (
                    not self._closed
                    and self._private_preload_generation
                    == private_intent_generation
                )
            if not intent_still_current:
                cacheable = False
        if (cancelled is not None and cancelled.is_set()) or not self.can_read_media():
            raise LinkingError('Gallery photo permission is unavailable.')
        if cache is not None and cacheable and etag is not None:
            write_started = time.perf_counter() if timings is not None else 0.0
            try:
                receipt_options = (
                    {'receipts': cache_receipts}
                    if cache_receipts is not None else {}
                )
                if entry is not None and etag == entry.etag and mime == entry.mime:
                    stored = cache.revalidate(
                        key, entry, gallery_key=gallery_key, **receipt_options,
                    )
                else:
                    stored = cache.write(
                        key, body, mime, etag, gallery_key=gallery_key,
                        **receipt_options,
                    )
                if timings is not None:
                    timings.cache_stored = stored
                    timings.stored_bytes = len(body) if stored else 0
            finally:
                if timings is not None:
                    timings.disk_write_ms += (
                        time.perf_counter() - write_started
                    ) * 1000.0
                    timings.observe_cache(cache, cache_observed)
        if timings is not None:
            timings.source = 'revalidated' if revalidated else 'network'
        return body, mime

    def media_presentation(
        self, gallery_id: int, cancelled: threading.Event | None = None,
    ) -> LinkedPresentation:
        """Load effective slideshow presets and the current allowed playlist.

        Args:
            gallery_id (int): Selected physical gallery ID.
            cancelled (threading.Event | None): Current viewer cancellation.

        Returns:
            LinkedPresentation: Validated settings and bounded playable tracks.

        Raises:
            LinkingError: The permission, projection or transport is unavailable.
        """
        payload, _mime, _etag, _cacheable = self._media_read(
            gallery_id, 'presentation', None, cancelled,
        )
        if payload is None:
            raise LinkingError('Gallery presentation was not accepted.')
        data = _native_media_json(payload)
        enabled, autoplay, music = (data.get(key) for key in ('enabled', 'autoplay', 'music'))
        interval, transition, rows = (data.get(key) for key in ('interval_seconds', 'transition', 'tracks'))
        track_cache = data.get('track_cache', False)
        if (type(enabled) is not bool or type(autoplay) is not bool or type(music) is not bool
                or type(track_cache) is not bool
                or type(interval) is not int or interval not in _NATIVE_INTERVAL_SECONDS
                or transition not in ('slow', 'medium', 'fast')
                or not isinstance(rows, list) or len(rows) > NATIVE_MEDIA_TRACK_LIMIT
                or (bool(rows) and (not enabled or not music))):
            raise LinkingError('Gallery presentation was not accepted.')
        tracks: list[LinkedTrack] = []
        seen: set[str] = set()
        for row in rows:
            if not isinstance(row, dict):
                raise LinkingError('Gallery audio row was not accepted.')
            key, title, mime, size, digest = (row.get(field) for field in ('id', 'title', 'mime', 'size', 'sha256'))
            if (not isinstance(key, str) or re.fullmatch(r'[a-f0-9]{32}', key) is None or key in seen
                    or not isinstance(title, str) or not _native_media_title(title)
                    or mime not in _NATIVE_AUDIO_TYPES or type(size) is not int
                    or not 0 < size <= NATIVE_MEDIA_MAX_BYTES
                    or not isinstance(digest, str) or re.fullmatch(r'[a-f0-9]{64}', digest) is None):
                raise LinkingError('Gallery audio row was not accepted.')
            seen.add(key)
            tracks.append(LinkedTrack(key, title, mime, size, digest))
        return LinkedPresentation(
            enabled, autoplay, interval, transition, music, tuple(tracks), track_cache,
        )

    def media_track(
        self, gallery_id: int, track: LinkedTrack,
        cancelled: threading.Event | None = None,
    ) -> bytes:
        """Read one authorized audio track, using verified disk bytes when available.

        Args:
            gallery_id (int): Selected physical gallery ID.
            track (LinkedTrack): Validated displayed track selected for playback.
            cancelled (threading.Event | None): Current viewer cancellation.

        Returns:
            bytes: Verified bounded track bytes for playback.

        Raises:
            LinkingError: Track identity, current playlist, access or transfer failed.
        """
        if not isinstance(track, LinkedTrack):
            raise LinkingError('Invalid gallery audio selection.')
        playlist = self.media_presentation(gallery_id, cancelled)
        if not any(
            row.track_id == track.track_id and row.sha256 == track.sha256
            and row.size == track.size and row.mime == track.mime
            for row in playlist.tracks
        ):
            raise LinkingError('Gallery audio changed; reload the playlist.')
        etag = '"' + track.sha256 + '"'
        cache = None
        entry = None
        key = ''
        if playlist.track_cache:
            try:
                cache = GalleryMediaCache.for_current_user(NATIVE_MEDIA_MAX_BYTES)
                key = cache.track_key(self._issuer, gallery_id, track.track_id, track.sha256)
                gallery_key = cache.gallery_key(self._issuer, gallery_id)
                entry = cache.read(key, gallery_key=gallery_key)
            except (OSError, RuntimeError, ValueError):
                cache = None
        if (entry is not None and entry.etag == etag
                and entry.mime == track.mime and len(entry.body) == track.size):
            with self._lock:
                if (self._closed or not self._token or time.monotonic() >= self._expires_at
                        or not scope_allows_presentation(self._scope)
                        or (cancelled is not None and cancelled.is_set())):
                    raise LinkingError('Gallery audio permission is unavailable.')
                return entry.body
        payload, mime, received_etag, cacheable = self._media_read(
            gallery_id, 'track', track.track_id, cancelled,
        )
        if payload is None:
            raise LinkingError('Gallery audio was not accepted.')
        if (mime != track.mime or len(payload) != track.size
                or hashlib.sha256(payload).hexdigest() != track.sha256):
            raise LinkingError('Gallery audio changed; reload the playlist.')
        if cache is not None and cacheable and received_etag == etag:
            cache.write(key, payload, mime, etag, gallery_key=gallery_key)
        return payload

    def can_list_galleries(self) -> bool:
        """Check the locally retained scope without claiming remote access.

        Returns:
            bool: True for a locally available or renewable listing approval.
        """
        with self._lock:
            return (not self._closed and bool(self._token)
                    and scope_base(self._scope) in (GALLERIES_SCOPE, UPLOAD_SCOPE)
                    and (self._renew is not None or time.monotonic() < self._expires_at))

    def can_search_galleries(self) -> bool:
        """Check the explicit metadata-search approval for this temporary link.

        Returns:
            bool: True while the current grant can search gallery metadata.
        """
        return scope_allows_gallery_search(self._scope) and self.available()

    def can_read_gallery_detail(self) -> bool:
        """Check locally held explicit gallery-detail consent and renewal.

        Returns:
            bool: True for an available or renewable read-scoped session.
        """
        return scope_allows_gallery_read(self._scope) and self.available()

    def can_change_gallery_visibility(self) -> bool:
        """Check explicit visibility consent without asserting server access.

        Returns:
            bool: True for an available or renewable visibility-scoped session.
        """
        return scope_allows_gallery_visibility(self._scope) and self.available()

    def can_upload(self) -> bool:
        """Check locally held upload scope without claiming server permission.

        Returns:
            bool: True only while the explicit upload bundle is held locally.
        """
        with self._lock:
            return (not self._closed and bool(self._token)
                    and scope_base(self._scope) == UPLOAD_SCOPE
                    and (self._renew is not None or time.monotonic() < self._expires_at))

    def approved_scope(self) -> str:
        """Read the exact locally held scope without exposing its bearer.

        Returns:
            str: Exact approved scope while the local session remains live.

        Raises:
            LinkingError: The local session is closed or expired.
        """
        with self._lock:
            if (self._closed or not self._token
                    or (self._renew is None and time.monotonic() >= self._expires_at)):
                raise LinkingError('Linking session is unavailable.')
            return self._scope

    def transient_snapshot(self) -> TransientSessionSnapshot:
        """Return a live non-offline bearer with its unchanged original expiry.

        Returns:
            TransientSessionSnapshot: Exact grant suitable for OS-vault storage.

        Raises:
            LinkingError: The session is closed, expired or uses offline scope.
        """
        with self._lock:
            remaining = self._expires_at - time.monotonic()
            if (self._closed or not self._token or remaining <= 0
                    or self._scope.endswith(' offline_access')
                    or remaining > LINKING_MAX_TOKEN_LIFETIME_SECONDS):
                raise LinkingError('Transient session is unavailable.')
            return TransientSessionSnapshot(
                self._issuer, self._scope, self._token, time.time() + remaining,
            )

    def can_create_gallery(self) -> bool:
        """Check locally held create scope without asserting server access.

        Returns:
            bool: True for an available or renewable creation-scoped session.
        """
        return scope_allows_gallery_create(self._scope) and self.available()

    def gallery_create_request(
        self, operation_key: str, title: str | None = None,
        parent_id: int | None = None, *, cancelled: threading.Event | None = None,
        abandon: bool = False,
    ) -> LinkedGallery | None:
        """Send one create, status or explicit closure request without retrying.

        Args:
            operation_key (str): Durable 64-character lowercase operation key.
            title (str | None): Exact create title, or None to read status only.
            parent_id (int | None): Optional positive parent for a create request.
            cancelled (threading.Event | None): Optional create cancellation signal.
            abandon (bool): Explicitly close this original operation instead of
                creating it; requires the original title and parent.

        Returns:
            LinkedGallery | None: Validated completed receipt, None for unknown
                read-only status or an explicitly confirmed abandoned operation.

        Raises:
            LinkingError: Input, permission, transport or receipt is invalid.
        """
        if (type(abandon) is not bool or not isinstance(operation_key, str)
                or re.fullmatch(r'[0-9a-f]{64}', operation_key) is None
                or (parent_id is not None and (type(parent_id) is not int
                    or not 0 < parent_id <= LINKING_GALLERY_ID_MAX))):
            raise LinkingError('Invalid gallery creation request.')
        if title is None:
            if parent_id is not None or abandon:
                raise LinkingError('Invalid gallery creation status request.')
            route = 'app_linking_gallery_create_status'
            payload = {'operation_key': operation_key}
        else:
            try:
                valid = (isinstance(title, str) and bool(title.strip(' \t\n\r\x00\x0b'))
                         and len(title.encode('utf-8')) <= LINKING_CREATE_TITLE_MAX_BYTES
                         and not any(ord(c) < 32 or ord(c) == 127 for c in title))
            except UnicodeError:
                valid = False
            if not valid:
                raise LinkingError('Invalid gallery title.')
            route = ('app_linking_gallery_create_abandon' if abandon
                     else 'app_linking_gallery_create')
            payload = {'operation_key': operation_key, 'title': title,
                       'parent_id': parent_id}
        body = json.dumps(payload, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
        data = self._request(route, body=body, gallery_cancel=cancelled)
        if abandon and data == {'state': 'abandoned'}:
            if not self.can_create_gallery():
                raise LinkingError('Gallery creation session expired.')
            return None
        if data == {'state': 'unknown'} and title is None:
            return None
        row = data.get('gallery')
        if (set(data) != {'state', 'gallery'} or data['state'] != 'completed'
                or not isinstance(row, dict) or set(row) != {'id', 'title', 'parent_id'}):
            raise LinkingError('Gallery creation receipt was not accepted.')
        gallery_id, result_title, result_parent = row['id'], row['title'], row['parent_id']
        try:
            valid_title = (isinstance(result_title, str)
                           and bool(result_title.strip(' \t\n\r\x00\x0b'))
                           and len(result_title.encode('utf-8'))
                           <= LINKING_CREATE_TITLE_MAX_BYTES
                           and not any(ord(c) < 32 or ord(c) == 127
                                       for c in result_title))
        except UnicodeError:
            valid_title = False
        if (type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                or (result_parent is not None and (type(result_parent) is not int
                    or not 0 < result_parent <= LINKING_GALLERY_ID_MAX))
                or not valid_title):
            raise LinkingError('Gallery creation receipt was not accepted.')
        if not self.can_create_gallery():
            raise LinkingError('Gallery creation session expired.')
        return LinkedGallery(gallery_id, result_parent, result_title)

    def matches_issuer(self, issuer: str) -> bool:
        """Compare a proposed local destination with this exact session issuer.

        Args:
            issuer (str): Canonical installation origin and mount.

        Returns:
            bool: True only while this locally held session matches the issuer.
        """
        with self._lock:
            return (not self._closed and self._issuer == issuer and bool(self._token)
                    and (self._renew is not None or time.monotonic() < self._expires_at))

    def open_watch_key(
        self, gallery_id: int, *, cancelled: threading.Event | None = None,
    ) -> str:
        """Register one fresh in-memory credential for the selected capture.

        Args:
            gallery_id (int): Exact physical upload gallery ID.
            cancelled (threading.Event | None): Current start action cancellation.

        Returns:
            str: Opaque capture key sent only with this run's linked requests.

        Raises:
            LinkingError: The capture credential could not be verified.
        """
        if not _native_media_id(gallery_id) or not self.can_upload():
            raise LinkingError('Watcher access is unavailable.')
        key = 'pgw_' + secrets.token_hex(WATCH_KEY_RANDOM_BYTES)
        try:
            self._change_watch_key('watch_open', gallery_id, key, cancelled)
        except LinkingError:
            # The reply may have been lost after registration. A scoped close
            # cannot remove a newer capture's key, even after a lost reply.
            try:
                self.close_watch_key(gallery_id, key)
            except LinkingError:
                pass
            raise
        return key

    def close_watch_key(
        self, gallery_id: int, key: str, *, cancelled: threading.Event | None = None,
    ) -> None:
        """Revoke the exact temporary key without closing the site link.

        Args:
            gallery_id (int): Physical gallery that admitted the capture.
            key (str): Opaque key returned when the same capture started.
            cancelled (threading.Event | None): Optional cleanup cancellation.

        Returns:
            None: The server confirmed closure of this key generation.

        Raises:
            LinkingError: The close request could not be confirmed.
        """
        self._change_watch_key('watch_close', gallery_id, key, cancelled)

    def _change_watch_key(
        self, action: Literal['watch_open', 'watch_close'], gallery_id: int,
        key: str, cancelled: threading.Event | None,
    ) -> None:
        """Send a hash-only lifecycle request on the current upload grant.

        Args:
            action (Literal['watch_open', 'watch_close']): Exact lifecycle action.
            gallery_id (int): Physical gallery owned by this capture.
            key (str): Exact raw key whose hash is registered or closed.
            cancelled (threading.Event | None): Operation cancellation signal.

        Returns:
            None: The server acknowledged the same action and gallery.

        Raises:
            LinkingError: Input, live upload scope or reply was refused.
        """
        if (not _native_media_id(gallery_id) or not isinstance(key, str)
                or _WATCH_KEY_PATTERN.fullmatch(key) is None):
            raise LinkingError('Invalid Watcher credential.')
        body = json.dumps({
            'action': action, 'gallery_id': gallery_id,
            'key_hash': hashlib.sha256(key.encode('ascii')).hexdigest(),
        }, separators=(',', ':')).encode('ascii')
        data = self._request('app_linking_inventory', body=body, gallery_cancel=cancelled)
        if (data != {'ok': True, 'action': action, 'gallery_id': gallery_id}
                or data.get('ok') is not True or type(data.get('gallery_id')) is not int):
            raise LinkingError('Watcher credential change was not confirmed.')

    def inventory(
        self, gallery_id: int, files: list[dict[str, JsonValue]], *,
        watch_key: str | None = None,
    ) -> dict[str, JsonValue]:
        """Read verified inventory in bounded, all-or-nothing request batches.

        Args:
            gallery_id (int): Stable positive server gallery identifier.
            files (list[dict[str, JsonValue]]): Candidate objects containing
                client_id, filename, nonnegative size and lowercase SHA-256.
            watch_key (str | None): Supplemental key for a contextual capture.

        Returns:
            dict[str, JsonValue]: Combined validated snapshots for this gallery.
                Individual rows still require a fresh check before skipping work.

        Raises:
            LinkingError: Scope, target, candidates, response or session fails.
        """
        if (type(gallery_id) is not int or gallery_id <= 0
                or gallery_id > LINKING_GALLERY_ID_MAX
                or len(files) > LINKING_INVENTORY_CANDIDATE_MAX):
            raise LinkingError('Invalid gallery inventory request.')
        candidates: dict[str, tuple[str, str, int]] = {}
        for entry in files:
            if not isinstance(entry, dict) or set(entry) != {
                'client_id', 'filename', 'size', 'sha256',
            }:
                raise LinkingError('Invalid gallery inventory request.')
            client_id, filename = entry['client_id'], entry['filename']
            size, file_hash = entry['size'], entry['sha256']
            if (not isinstance(client_id, str)
                    or re.fullmatch(r'[A-Za-z0-9_-]{1,80}', client_id) is None
                    or not isinstance(filename, str)
                    or not filename or len(filename) > 255
                    or '/' in filename or '\\' in filename
                    or type(size) is not int or size < 0
                    or not isinstance(file_hash, str)
                    or re.fullmatch(r'[0-9a-f]{64}', file_hash) is None):
                raise LinkingError('Invalid gallery inventory request.')
            previous = candidates.get(file_hash)
            if previous is not None and previous[2] != size:
                raise LinkingError('Invalid gallery inventory request.')
            # The server normalizes names and retains the last descriptor for
            # each hash; filenames are display hints, never filesystem paths.
            normalized_name = filename.strip(' \t\n\r\x00\x0b') or file_hash
            candidates[file_hash] = (client_id, normalized_name, size)
        try:
            body = json.dumps({
                'action': 'inventory', 'gallery_id': gallery_id,
                'files': files, 'deep_check': False,
            }, separators=(',', ':'), ensure_ascii=True,
                allow_nan=False).encode('ascii')
        except (TypeError, ValueError, UnicodeError):
            raise LinkingError('Invalid gallery inventory request.') from None
        if len(body) > LINKING_INVENTORY_REQUEST_MAX_BYTES:
            raise LinkingError('Gallery inventory request is too large.')
        unique = list(candidates.items())
        batches: list[list[tuple[str, tuple[str, str, int]]]] = []
        current: list[tuple[str, tuple[str, str, int]]] = []
        current_bytes = 0
        for candidate in unique:
            size = candidate[1][2]
            if current and (len(current) >= LINKING_INVENTORY_BATCH_SIZE
                            or current_bytes + size > LINKING_INVENTORY_BATCH_BYTES):
                batches.append(current)
                current, current_bytes = [], 0
            current.append(candidate)
            current_bytes += size
        if current:
            batches.append(current)
        if not batches:
            batches = [[]]
        deadline = time.monotonic() + LINKING_INVENTORY_TOTAL_SECONDS
        combined: list[JsonValue] = []
        data: dict[str, JsonValue] = {}
        watch_options = {'watch_key': watch_key} if watch_key is not None else {}
        for batch in batches:
            if time.monotonic() >= deadline:
                raise LinkingError('Gallery inventory did not complete safely.')
            expected = dict(batch)
            batch_files = [
                {'sha256': digest, 'client_id': fields[0],
                 'filename': fields[1], 'size': fields[2]}
                for digest, fields in batch
            ]
            batch_body = json.dumps({
                'action': 'inventory', 'gallery_id': gallery_id,
                'files': batch_files, 'deep_check': False,
            }, separators=(',', ':'), ensure_ascii=True, allow_nan=False).encode('ascii')
            data = self._request('app_linking_inventory', body=batch_body, **watch_options)
            if time.monotonic() >= deadline:
                raise LinkingError('Gallery inventory did not complete safely.')
            existing = data.get('existing')
            if (set(data) != {'ok', 'action', 'gallery_id', 'gallery_title', 'checked',
                              'matched', 'existing', 'deep_check_applied', 'fingerprint',
                              'server_time', 'inventory_evidence'}
                    or data.get('ok') is not True or data.get('action') != 'inventory'
                    or type(data.get('gallery_id')) is not int
                    or data['gallery_id'] != gallery_id
                    or data.get('inventory_evidence') != 'verified_file_v1'
                    or not isinstance(data.get('gallery_title'), str)
                    or type(data.get('checked')) is not int
                    or data['checked'] != len(expected)
                    or not isinstance(existing, list) or len(existing) > len(expected)
                    or type(data.get('matched')) is not int
                    or data['matched'] != len(existing)
                    or data.get('deep_check_applied') is not False
                    or not isinstance(data.get('fingerprint'), str)
                    or not isinstance(data.get('server_time'), str)):
                raise LinkingError('Gallery inventory response was not accepted.')
            seen: set[str] = set()
            for row in existing:
                if (not isinstance(row, dict)
                        or set(row) != {'client_id', 'filename', 'size', 'sha256',
                                       'remote_filename', 'remote_relative_path',
                                       'remote_image_id', 'matched_by'}):
                    raise LinkingError('Gallery inventory response was not accepted.')
                digest = row['sha256']
                remote = row['remote_filename']
                if (not isinstance(digest, str) or digest not in expected or digest in seen
                        or (row['client_id'], row['filename'], row['size']) != expected[digest]
                        or type(row['size']) is not int
                        or not isinstance(remote, str) or not remote
                        or '/' in remote or '\\' in remote
                        or row['remote_relative_path'] != remote
                        or type(row['remote_image_id']) is not int
                        or not 0 < row['remote_image_id'] <= LINKING_GALLERY_ID_MAX
                        or row['matched_by'] != 'checksum_sha256'):
                    raise LinkingError('Gallery inventory response was not accepted.')
                seen.add(digest)
            combined.extend(existing)
        return {**data, 'checked': len(candidates), 'matched': len(combined),
                'existing': combined}

    def probe_uncertain_upload(self, gallery_id: int, file_hash: str, size: int) -> str:
        """Observe matching bytes once without deciding an upload outcome.

        Args:
            gallery_id (int): Current authorized gallery identifier.
            file_hash (str): Lowercase SHA-256 read from an uncertain journal.
            size (int): User-supplied original size in bytes.

        Returns:
            str: Either present or unknown; neither confirms an upload outcome.

        Raises:
            LinkingError: The request, response or live scope is unavailable.
        """
        if (type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                or not isinstance(file_hash, str)
                or re.fullmatch(r'[0-9a-f]{64}', file_hash) is None
                or type(size) is not int or not 0 < size <= LINKING_RECOVERY_MAX_FILE_BYTES):
            raise LinkingError('Invalid recovery observation.')
        body = json.dumps({
            'action': 'inventory', 'gallery_id': gallery_id,
            'files': [{'client_id': file_hash, 'filename': 'recovery.jpg',
                       'size': size, 'sha256': file_hash}],
            'deep_check': True,
        }, separators=(',', ':'), ensure_ascii=True).encode('ascii')
        data = self._request('app_linking_inventory', body=body)
        if (set(data) != {'ok', 'action', 'gallery_id', 'result'}
                or data['ok'] is not True or data['action'] != 'recovery'
                or type(data['gallery_id']) is not int or data['gallery_id'] != gallery_id
                or data['result'] not in ('present', 'unknown')):
            raise LinkingError('Recovery observation was not accepted.')
        return str(data['result'])

    def upload_multipart(
        self, gallery_id: int, body: Iterable[bytes], boundary: str,
        content_length: int, *, watch_key: str | None = None,
    ) -> dict[str, JsonValue]:
        """Send one explicitly selected gallery's upload without retrying it.

        Args:
            gallery_id (int): Stable positive selected gallery identifier.
            body (Iterable[bytes]): One-shot multipart bytes and file chunks.
            boundary (str): Generated ASCII multipart delimiter.
            content_length (int): Exact complete request body size in bytes.
            watch_key (str | None): Supplemental key for a contextual capture.

        Returns:
            dict[str, JsonValue]: Server acknowledgement for exactly one image.

        Raises:
            LinkingError: Target, scope, transport or acknowledgement fails;
                a failed response may be ambiguous and must not be auto-retried.
        """
        if (type(gallery_id) is not int or gallery_id <= 0
                or gallery_id > LINKING_GALLERY_ID_MAX):
            raise LinkingError('Invalid gallery upload request.')
        watch_options = {'watch_key': watch_key} if watch_key is not None else {}
        data = self._request(
            'app_linking_upload', body=body, multipart_boundary=boundary,
            content_length=content_length, **watch_options,
        )
        image_ids, filenames = data.get('image_ids'), data.get('filenames')
        if (data.get('ok') is not True or type(data.get('gallery_id')) is not int
                or data['gallery_id'] != gallery_id
                or data.get('upload_evidence') != 'fresh_insert_v1'
                or type(data.get('uploaded')) is not int
                or data['uploaded'] != 1
                or not isinstance(image_ids, list) or len(image_ids) != 1
                or type(image_ids[0]) is not int
                or not 0 < image_ids[0] <= LINKING_GALLERY_ID_MAX
                or not isinstance(filenames, list) or len(filenames) != 1
                or not isinstance(filenames[0], str) or not filenames[0]
                or type(data.get('scanned')) is not int or data['scanned'] < 0):
            raise LinkingError('Gallery upload was not confirmed.')
        for key in ('renamed', 'thumbnails', 'thumbnail_failed'):
            if key in data and (type(data[key]) is not int or data[key] < 0):
                raise LinkingError('Gallery upload was not confirmed.')
        for key in ('rename_warnings', 'rename_failures', 'thumbnail_errors',
                    'post_upload_errors'):
            if key in data and (not isinstance(data[key], list)
                                or any(not isinstance(item, str) for item in data[key])):
                raise LinkingError('Gallery upload was not confirmed.')
        camera = data.get('sim_camera_metadata')
        if 'sim_camera_metadata' in data and (not isinstance(camera, dict)
                or any(type(camera.get(key)) is not int or camera[key] < 0
                       for key in ('attached', 'skipped'))
                or not isinstance(camera.get('error'), str)):
            raise LinkingError('Gallery upload was not confirmed.')
        thumbnails = data.get('client_thumbnails')
        if 'client_thumbnails' in data and (not isinstance(thumbnails, dict)
                or any(type(thumbnails.get(key)) is not int or thumbnails[key] < 0
                       for key in ('installed', 'skipped', 'failed'))
                or not isinstance(thumbnails.get('errors'), list)
                or any(not isinstance(item, str) for item in thumbnails['errors'])):
            raise LinkingError('Gallery upload was not confirmed.')
        return data

    def gallery_page(
        self, after_id: int | None = None,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryPage:
        """Read and validate one fresh, bounded server-owned gallery page.

        Args:
            after_id (int | None): Exclusive positive ID from the prior page.
            cancelled (threading.Event | None): Dialog-owned cancellation
                registered before the background worker starts.

        Returns:
            LinkedGalleryPage: Ordered current rows and optional next cursor.

        Raises:
            LinkingError: Scope, cursor, response or current session is invalid.
        """
        if (after_id is not None and (type(after_id) is not int or after_id <= 0
                                      or after_id > LINKING_GALLERY_ID_MAX)):
            raise LinkingError('Invalid gallery page cursor.')
        data = self._request('app_linking_galleries', after_id, gallery_cancel=cancelled)
        page = _linked_gallery_page(data, after_id)
        if not self.available():
            raise LinkingError('Linking session is unavailable.')
        return page

    def supports_gallery_tree(self) -> bool:
        """Check whether discovery negotiated the optional full-tree route.

        Returns:
            bool: True only for a live session with the exact tree-v1 capability.
        """
        with self._lock:
            return (not self._closed and self._gallery_tree_supported
                    and bool(self._token)
                    and (self._renew is not None
                         or time.monotonic() < self._expires_at))

    def gallery_tree(
        self, revision: str | None = None,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryTree | None:
        """Read the optional complete tree or return None for safe fallback.

        Args:
            revision (str | None): Validated cached server revision, if any.
            cancelled (threading.Event | None): Workspace-owned cancellation.

        Returns:
            LinkedGalleryTree | None: Complete tree, unchanged confirmation, or
                None when the optional endpoint is unsupported/unavailable.

        Raises:
            LinkingError: The current grant, scope, session or cancellation failed.
        """
        if (not self.available()
                or (cancelled is not None and cancelled.is_set())):
            raise LinkingError('Linking session is unavailable.')
        if not self.supports_gallery_tree():
            return None
        if (revision is not None
                and (not isinstance(revision, str)
                     or _GALLERY_TREE_REVISION_PATTERN.fullmatch(revision) is None)):
            return None
        try:
            data = self._request(
                'app_linking_gallery_tree', gallery_cancel=cancelled,
                tree_revision=revision,
            )
        except LinkingResponseError as error:
            if error.status in (401, 403, 429) or error.reason == 'throttled':
                raise
            logging.getLogger(__name__).info(
                '[photos] catalogue tree fallback; %s', error.safe_summary,
            )
            return None
        try:
            tree = _linked_gallery_tree(data, revision)
        except LinkingError:
            logging.getLogger(__name__).info(
                '[photos] catalogue tree fallback; reason=invalid_shape',
            )
            return None
        if not self.available():
            raise LinkingError('Linking session is unavailable.')
        return tree

    def gallery_picker_page(
        self, query: str, after_id: int | None = None,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryPage:
        """Search the approved catalog or browse a page in tree order.

        Args:
            query (str): Bounded literal search expression, or empty to browse.
            after_id (int | None): Last gallery from the same query's prior page.
            cancelled (threading.Event | None): Cancellation for this choice read.

        Returns:
            LinkedGalleryPage: Ranked matches with parent titles and pagination.

        Raises:
            LinkingError: Invalid search, expired approval or unsafe reply.
        """
        if (not isinstance(query, str) or len(query) > LINKING_GALLERY_QUERY_MAX_CHARS
                or any(ord(char) < 32 or ord(char) == 127
                       or 0xd800 <= ord(char) <= 0xdfff for char in query)
                or (after_id is not None and (type(after_id) is not int
                    or not 0 < after_id <= LINKING_GALLERY_ID_MAX))):
            raise LinkingError('Invalid gallery search.')
        body = urlencode({'query': query, 'after': after_id or ''}).encode('ascii')
        data = self._request('app_linking_galleries', body=body, gallery_cancel=cancelled)
        page = _linked_gallery_page(data, after_id, picker=True)
        if not self.available():
            raise LinkingError('Linking session is unavailable.')
        return page

    def gallery_detail(
        self, gallery_id: int, cancelled: threading.Event | None = None,
    ) -> LinkedGalleryDetail:
        """Read exact stored properties for one explicitly selected gallery.

        Args:
            gallery_id (int): Positive ID selected from a fresh server page.
            cancelled (threading.Event | None): Optional dialog cancellation.

        Returns:
            LinkedGalleryDetail: Validated fields for exactly the selected ID.

        Raises:
            LinkingError: Scope, target, transport or response is unavailable.
        """
        if (type(gallery_id) is not int or gallery_id <= 0
                or gallery_id > LINKING_GALLERY_ID_MAX):
            raise LinkingError('Invalid gallery detail target.')
        with self._lock:
            self._visibility_snapshot = None
        data = self._request(
            'app_linking_gallery_detail', gallery_cancel=cancelled,
            gallery_id=gallery_id,
        )
        row = data.get('gallery')
        if (set(data) != {'gallery'} or not isinstance(row, dict)
                or set(row) != {'id', 'title', 'revision', 'visibility',
                               'access_mode', 'access_listing'}):
            raise LinkingError('Gallery detail was not accepted.')
        title, revision = row['title'], row['revision']
        if (type(row['id']) is not int or row['id'] != gallery_id
                or not isinstance(title, str) or len(title) > LINKING_GALLERY_TITLE_MAX_CHARS
                or any(0xd800 <= ord(char) <= 0xdfff for char in title)
                or not isinstance(revision, str)
                or re.fullmatch(r'[1-9][0-9]{0,19}', revision) is None
                or int(revision) > LINKING_GALLERY_REVISION_MAX
                or row['visibility'] not in ('draft', 'unpublished', 'public', 'private')
                or row['access_mode'] not in ('normal', 'password')
                or row['access_listing'] not in ('listed', 'unlisted')):
            raise LinkingError('Gallery detail was not accepted.')
        detail = LinkedGalleryDetail(
            gallery_id, title, revision, row['visibility'], row['access_mode'],
            row['access_listing'],
        )
        with self._lock:
            if self._closed or time.monotonic() >= self._expires_at:
                raise LinkingError('Linking session is unavailable.')
            self._visibility_snapshot = detail
        return detail

    def change_photo_visibility(
        self, gallery_id: int, photo: LinkedPhoto, visibility: str,
        cancelled: threading.Event | None = None,
    ) -> LinkedPhoto:
        """Submit one scoped change against the photo's observed visibility.

        Args:
            gallery_id (int): Exact physical gallery currently displaying the photo.
            photo (LinkedPhoto): Descriptor containing the observed stored visibility.
            visibility (str): Explicit public, private or draft target.
            cancelled (threading.Event | None): Current viewer cancellation.

        Returns:
            LinkedPhoto: Fresh descriptor confirmed by the server after the write.

        Raises:
            LinkingError: The change was rejected or its outcome is uncertain.
            LinkingRevisionConflict: The prior visibility or target changed.
        """
        values = ('draft', 'private', 'public')
        if (not _native_media_id(gallery_id) or not isinstance(photo, LinkedPhoto)
                or not _native_media_id(photo.image_id) or photo.visibility not in values
                or visibility not in values or visibility == photo.visibility):
            raise LinkingError('Invalid photo visibility request.')
        body = json.dumps({
            'gallery_id': gallery_id, 'image_id': photo.image_id,
            'expected_visibility': photo.visibility, 'visibility': visibility,
        }, separators=(',', ':'), ensure_ascii=True).encode('ascii')
        data = self._request('app_linking_photo_visibility', body=body, gallery_cancel=cancelled)
        if data == {'__client_visibility_conflict': 'revision_conflict'}:
            raise LinkingRevisionConflict('Photo visibility changed.')
        row = data.get('photo')
        if set(data) != {'photo'} or not isinstance(row, dict):
            raise LinkingError('Photo visibility change was not confirmed.')
        updated = _linked_admin_photo(row)
        if updated.image_id != photo.image_id or updated.visibility != visibility:
            raise LinkingError('Photo visibility change was not confirmed.')
        return updated

    def change_gallery_visibility(
        self, detail: LinkedGalleryDetail, visibility: str,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryDetail:
        """Submit one visibility change against a fresh, single-use detail.

        Args:
            detail (LinkedGalleryDetail): Most recent detail from this session.
            visibility (str): Explicit public, private or unpublished target.
            cancelled (threading.Event | None): Dialog cancellation signal.

        Returns:
            LinkedGalleryDetail: Fresh detail agreeing with the acknowledged
                gallery, revision, visibility and access fields.

        Raises:
            LinkingError: Rejected or uncertain operation; do not retry it.
        """
        if not isinstance(detail, LinkedGalleryDetail) or visibility not in (
            'public', 'private', 'unpublished',
        ):
            raise LinkingError('Invalid visibility request.')
        previous = 'unpublished' if detail.visibility == 'draft' else detail.visibility
        with self._lock:
            if (self._visibility_snapshot is not detail or self._closed
                    or time.monotonic() >= self._expires_at
                    or not scope_allows_gallery_visibility(self._scope)
                    or visibility == previous
                    or int(detail.revision) >= LINKING_GALLERY_REVISION_MAX
                    or (cancelled is not None and cancelled.is_set())):
                raise LinkingError('Fresh visibility detail is unavailable.')
            # Consume before dispatch: even a lost reply cannot replay this edit.
            self._visibility_snapshot = None
        body = json.dumps({
            'gallery_id': detail.gallery_id,
            'expected_revision': detail.revision,
            'visibility': visibility,
        }, separators=(',', ':'), ensure_ascii=True).encode('ascii')
        data = self._request(
            'app_linking_gallery_visibility', body=body,
            gallery_cancel=cancelled,
        )
        if data == {'__client_visibility_conflict': 'scheduled_publication_active'}:
            raise LinkingScheduledPublication('Scheduled publication is active.')
        if data == {'__client_visibility_conflict': 'revision_conflict'}:
            raise LinkingRevisionConflict('Gallery revision changed.')
        row = data.get('gallery')
        accepted_visibility = (('unpublished', 'draft')
                               if visibility == 'unpublished' else (visibility,))
        if (set(data) != {'gallery'} or not isinstance(row, dict)
                or set(row) != {'id', 'revision', 'visibility',
                                'access_mode', 'access_listing'}
                or type(row['id']) is not int or row['id'] != detail.gallery_id
                or row['revision'] != str(int(detail.revision) + 1)
                or row['visibility'] not in accepted_visibility
                or row['access_mode'] != detail.access_mode
                or row['access_listing'] != detail.access_listing):
            raise LinkingError('Visibility change was not confirmed.')
        current = self.gallery_detail(detail.gallery_id, cancelled)
        if (current.revision != row['revision'] or current.visibility != row['visibility']
                or current.access_mode != row['access_mode']
                or current.access_listing != row['access_listing']
                or (cancelled is not None and cancelled.is_set())):
            with self._lock:
                if self._visibility_snapshot is current:
                    self._visibility_snapshot = None
            raise LinkingError('Visibility change was not confirmed by fresh detail.')
        return current

    def identity(self) -> LinkedIdentity:
        """Read and validate the live identity of this scoped session.

        Returns:
            LinkedIdentity: Positive user ID and bounded current username.

        Raises:
            LinkingError: The operation fails or the identity is malformed.
        """
        data = self._request('app_linking_identity')
        user = data.get('user')
        if (set(data) != {'user', 'scope'} or data.get('scope') != self._scope
                or not isinstance(user, dict) or set(user) != {'id', 'username'}):
            raise LinkingError('Identity response was not accepted.')
        user_id, username = user['id'], user['username']
        if (type(user_id) is not int or user_id <= 0
                or not isinstance(username, str) or not username
                or len(username) > LINKING_USERNAME_MAX_CHARS
                or any(ord(char) < 32 or ord(char) == 127 for char in username)):
            raise LinkingError('Identity response was not accepted.')
        with self._lock:
            if self._closed or time.monotonic() >= self._expires_at:
                raise LinkingError('Linking session is unavailable.')
            if (self._bound_user_id is not None
                    and self._bound_user_id != user_id):
                raise LinkingError('Linked account identity changed.')
            self._bound_user_id = user_id
            return LinkedIdentity(user_id, username)

    def _verified_user_id(self) -> int:
        """Return the account identity already bound to this live session.

        Returns:
            int: Positive identity verified by the installation's identity route.

        Raises:
            LinkingError: Admin access is unavailable or identity is not verified.
        """
        with self._lock:
            bound = self._bound_user_id
            valid = (not self._closed
                     and (self._renew is not None
                          or time.monotonic() < self._expires_at)
                     and scope_allows_admin_media(self._scope))
        if not valid:
            raise LinkingError('Admin media permission is unavailable.')
        if bound is None:
            raise LinkingError('Admin account identity is not verified.')
        return bound

    def revoke(self) -> None:
        """Request revocation and discard the token after a valid acknowledgement.

        Returns:
            None: The trusted server accepted revocation or token absence.

        Raises:
            LinkingError: The response is missing, ambiguous or invalid.
        """
        self._request('app_linking_revoke')

    def close(self) -> None:
        """Forget the local token without asserting remote revocation.

        Returns:
            None: Prevents further use and discards the owned token reference.
        """
        self._cancelled.set()
        self._media_connection_pool.discard(close=True)
        with self._lock:
            for pending in self._pending_gallery_cancels:
                pending.set()
            self._closed = True
            self._token = ''
            self._private_preload_generation += 1
            self._recent_media_pages.clear()
            self._private_preload_proofs.clear()
