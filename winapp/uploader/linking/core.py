# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/core.py
# Module Type: Windows Client Transport
# Purpose: Own linking constants, scopes, validation and error types.
# Responsibilities:
#   - Keep protocol policy and shared linking types free of client UI dependencies.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own linking constants, scopes, validation and error types."""

from ..gallery_media_cache import GalleryMediaCache
from ..models import JsonValue
from typing import Literal
from ..passive_server_timing import PassiveServerTiming
from typing import TypeVar
import base64
import binascii
from dataclasses import dataclass
from collections import deque
from ..server_discovery import discovery_url
from dataclasses import field
import hashlib
import hmac
import http.client
import ipaddress
import math
from urllib.parse import parse_qsl
from email.utils import parsedate_to_datetime
import re
import socket
import threading
import time
from urllib.parse import urlsplit
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
from email.utils import parsedate_to_datetime
from typing import Callable, Iterable, Literal, TypeVar
from urllib.parse import SplitResult, parse_qsl, urlencode, urlsplit

from ..thumbnail_batch_protocol import (
    INPUT_SLICE_BYTES, MAX_IMAGE_ID, PRODUCTION_MAX_IMAGE_BYTES, PRODUCTION_MAX_ITEMS,
    PRODUCTION_MAX_RESPONSE_BYTES,
    ProtocolError, ThumbnailBatchParser, ValidatedItem,
)
from ..server_discovery import _reject_nonfinite, _unique_object, discovery_url
from ..gallery_media_cache import (
    GalleryMediaCache, PhotoCacheEntry, PhotoCacheReceipt,
    PHOTO_CACHE_FRESH_SECONDS,
)
from ..linking_performance import (
    LinkingRoutePerformanceWindow, linking_route_category,
)
from ..passive_server_timing import (
    PassiveServerTiming, ServerTimingWindow, parse_server_timing,
)


CLIENT_ID = 'php-gallery-winapp'
# Purpose: Bound accepted inventory, upload and batch Retry-After hints.
# Type: int. Units: seconds. Scope: one validated inventory/upload/batch hint.
# Consumers: validated_retry_after and LinkingMediaThrottled.
# Rationale: One day preserves long server-directed batch/maintenance waits;
#   larger or ambiguous values are discarded instead of waiting indefinitely.
UPLOAD_RETRY_AFTER_MAX_SECONDS = 86400

# Purpose: Recognize fixed application refusals that precede original storage.
# Type: dict[int,frozenset[str]]. Units: HTTP statuses and exact error identifiers.
# Scope: delegated inventory/upload responses. Consumers: _request_json_once.
# Rationale: Generic proxy failures and post-store 503 replies cannot authorize replay.
UPLOAD_PRESTORE_REFUSALS = {
    400: frozenset({'invalid_request'}),
    403: frozenset({'feature_disabled', 'insufficient_scope'}),
    404: frozenset({'target_unavailable'}),
    405: frozenset({'invalid_request'}),
    411: frozenset({'length_required'}),
    413: frozenset({'request_too_large'}),
    415: frozenset({'unsupported_media_type'}),
    422: frozenset({'invalid_file'}),
    429: frozenset({'temporarily_unavailable'}),
    503: frozenset({'temporarily_unavailable'}),
}


def validated_retry_after(values: list[str], now: float | None = None) -> int | None:
    """Accept one bounded delta-seconds or HTTP-date Retry-After header.

    Args:
        values (list[str]): All Retry-After fields on the same response.
        now (float | None): Unix-seconds clock for dates, or None for current time.

    Returns:
        int | None: Positive delay in seconds, or None for absent/invalid hints.
    """
    if len(values) != 1:
        return None
    value = values[0].strip()
    try:
        if re.fullmatch(r'[0-9]+', value):
            delay = int(value)
        else:
            parsed = parsedate_to_datetime(value)
            if parsed.tzinfo is None:
                return None
            delay = math.ceil(parsed.timestamp() - (time.time() if now is None else now))
        return delay if 1 <= delay <= UPLOAD_RETRY_AFTER_MAX_SECONDS else None
    except (ValueError, TypeError, OverflowError):
        return None


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
# Purpose: Identify the optional framed thumbnail batch transport.
# Type: str. Units: discovery capability. Scope: one linking installation.
# Consumers: LinkingAttempt and LinkingSession. Rationale: Discovery negotiates
# the optional protocol without changing OAuth consent or media authorization.
THUMBNAIL_BATCH_CAPABILITY = 'media:thumbnail-batch-v1'
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

# Purpose: Bound complete ordered photo snapshots retained for immediate returns.
# Type: int. Units: galleries. Scope: one live LinkingSession.
# Consumers: LinkingSession._recent_media_orders.
# Rationale: Four galleries cover recent browsing without retaining a workspace dump.
MEDIA_RECENT_ORDER_GALLERY_LIMIT = 4
# Purpose: Bound all complete ordered photo rows retained in one session.
# Type: int. Units: photo descriptors. Scope: one live LinkingSession.
# Consumers: LinkingSession.cache_recent_media_order.
# Rationale: Ten thousand rows bound metadata while covering common gallery returns.
MEDIA_RECENT_ORDER_PHOTO_LIMIT = 10_000
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
_MediaCurrentnessReason = Literal[
    'cancelled', 'session_closed', 'deadline', 'grant_expired',
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
# Purpose: Add metadata authority only after explicit browser approval.
# Type: str. Units: ordered scope suffix. Scope: Admin Photos bundles.
# Consumers: registration and preferred linking scope selection.
# Rationale: gallery:edit keeps existing grants and consent unchanged.
GALLERY_EDIT_SCOPE_SUFFIX = ' gallery:edit'
ADMIN_GALLERY_EDIT_SCOPES = {
    (scope.removesuffix(ROLLING_SCOPE_SUFFIX) + GALLERY_EDIT_SCOPE_SUFFIX
     + (ROLLING_SCOPE_SUFFIX if scope.endswith(ROLLING_SCOPE_SUFFIX) else '')): base
    for scope, base in ADMIN_MEDIA_SCOPES.items()
}
ADMIN_MEDIA_SCOPES.update(ADMIN_GALLERY_EDIT_SCOPES)
ROLLING_ADMIN_MEDIA_SCOPES = tuple(
    scope for scope in ADMIN_MEDIA_SCOPES
    if scope.endswith(ROLLING_SCOPE_SUFFIX)
    and ADMIN_DELETE_SCOPE_SUFFIX not in scope
    and GALLERY_EDIT_SCOPE_SUFFIX not in scope
)
ROLLING_ADMIN_DELETE_SCOPES = tuple(
    scope for scope in ADMIN_MEDIA_DELETE_SCOPES
    if scope.endswith(ROLLING_SCOPE_SUFFIX)
)
# Append these after both historical groups in persisted vault records.
ROLLING_ADMIN_EDIT_SCOPES = tuple(
    scope for scope in ADMIN_GALLERY_EDIT_SCOPES
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
# Consumers: _issuer_valid, linking_issuer, _connection.
# Rationale: 443 is the standard HTTPS port and
# passing it explicitly avoids interpreting an IPv6 hostname as host:port.
LINKING_HTTPS_DEFAULT_PORT = 443

# Purpose: Bound one linking request including DNS, TLS and the response body.
# Type: int. Units: seconds. Scope: one native linking HTTPS operation.
# Consumers: _request_json. Rationale: 12 seconds allows shared hosting while
# ensuring an unresponsive server does not hold the caller indefinitely.
LINKING_REQUEST_DEADLINE_SECONDS = 12

# Purpose: Bound an entire thumbnail group, including one session-gate wait.
# Type: float. Units: seconds. Scope: one media_thumbnails call.
# Consumers: LinkingSession.media_thumbnails. Rationale: Compose the existing
# 20-second admission and 12-second transport budgets without a new longer cap.
THUMBNAIL_BATCH_OPERATION_SECONDS = (
    LINKING_SESSION_ADMISSION_TIMEOUT_SECONDS + LINKING_REQUEST_DEADLINE_SECONDS
)

# Purpose: Space a single automatic retry of a transient batch transport fault.
# Type: float. Units: seconds. Scope: one thumbnail batch session cooldown.
# Consumers: LinkingSession.media_thumbnails. Rationale: One second prevents
# immediate retry storms while staying inside the existing operation budget.
THUMBNAIL_BATCH_RETRY_SECONDS = 1.0

# Purpose: Bound automatic replay after a transient pre-frame transport failure.
# Type: int. Units: physical attempts. Scope: one homogeneous thumbnail group.
# Consumers: LinkingSession.media_thumbnails. Rationale: One initial request
# plus one retry prevents indefinite replay and never retries delivered frames.
THUMBNAIL_BATCH_MAX_ATTEMPTS = 2

# Purpose: Carry the existing pause cause without inventing an HTTP response.
# Type: Literal values. Units: cooldown cause. Scope: deferred thumbnail reads.
# Consumers: LinkingThumbnailBatchDeferred and native viewer completion.
# Rationale: Keep a local transient pause distinct from a server Retry-After.
_ThumbnailCooldownSource = Literal[
    'transient', 'server_retry_after', 'unknown',
]

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

# Purpose: Preserve complete database TEXT values in a bounded metadata response.
# Type: int. Units: bytes. Scope: one linked gallery edit response.
# Consumers: _request_json_once. Rationale: 512 KiB holds a 65,535-byte TEXT
# value even with six-byte JSON escapes and the canonical revision envelope.
GALLERY_EDIT_RESPONSE_MAX_BYTES = 512 * 1024

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

# Purpose: Negotiate sliding access without assuming support on older servers.
# Type: str. Units: capability identifier. Scope: one trusted discovery result.
# Consumers: LinkingAttempt, LinkingManager and LinkingSession.
# Rationale: day-v1 promises a 24-hour access deadline on authenticated API use.
SLIDING_ACCESS_CAPABILITY = 'rolling_access:day-v1'

# Purpose: Keep an idle live capture connected without rotating its bearer.
# Type: int. Units: seconds. Scope: one rolling session's read-only keepalive.
# Consumers: LinkingSession.keepalive_due and LinkingManager._poll.
# Rationale: 300 seconds bounds stale revocation status and refreshes the server
# only after five minutes without real API use; normal browsing adds no traffic.
LINKING_KEEPALIVE_SECONDS = 300


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
    'app_linking_gallery_metadata',
    'app_linking_media', 'app_linking_thumbnail_batch',
]
_LinkingDiagnosticContent = Literal['json', 'html', 'other']
_LinkingTransportStep = Literal[
    'unknown', 'slot', 'dns', 'connect', 'tls', 'request', 'headers', 'body',
]
_LINKING_TRANSPORT_STEPS: tuple[_LinkingTransportStep, ...] = (
    'unknown', 'slot', 'dns', 'connect', 'tls', 'request', 'headers', 'body',
)
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
        http_requests (int): HTTP send attempts, including a stale-socket retry.
        connections (int): Newly connected TCP sockets.
        tls_handshakes (int): Attempted TLS handshakes on fresh sockets.
        connection_reuses (int): GETs borrowing a retained socket.
        context_ms (float): Certificate-verifying SSLContext setup milliseconds.
        dns_ms (float): Address resolution milliseconds.
        tcp_ms (float): TCP connection milliseconds.
        tls_ms (float): TLS wrapping and handshake milliseconds.
        ttfb_ms (float): HTTP send through response-header receipt milliseconds.
        body_ms (float): Response validation/body-read milliseconds.
        cache_state (Literal['miss', 'hit', 'revalidation', 'ineligible',
            'changed', 'expired']): Passive outcome of a cache-only lookup.
        server_timing (PassiveServerTiming | None): Strictly parsed numeric
            opt-in PHP metrics from this accepted response, or None.

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
    server_timing: PassiveServerTiming | None = None

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

    # A generic error after response validation has no no-send attestation.
    # The actual transport replaces this only with its own dispatch evidence.
    request_started: bool = True
    operation_finished: threading.Event | None = None
    definitely_rejected: bool = False
    retry_after_seconds: int | None = None


class LinkingResponseError(LinkingError):
    """Carry a bounded response diagnosis without retaining response contents.

    Args:
        phase (_LinkingDiagnosticPhase): Fixed linking operation phase.
        route (_LinkingDiagnosticRoute): Fixed registered endpoint name.
        status (int | None): HTTP status, or None if no response arrived.
        content_kind (_LinkingDiagnosticContent): Coarse response media class.
        reason (_LinkingDiagnosticReason): Fixed protocol refusal category.
        transport_step (_LinkingTransportStep): Optional finite transport
            operation step for a typed timeout, without request data.
        media_policy_facts (MediaPolicyFacts | None): Optional finite facts
            attached only to a media response-policy refusal.
    """

    def __init__(
        self, phase: _LinkingDiagnosticPhase,
        route: _LinkingDiagnosticRoute, status: int | None,
        content_kind: _LinkingDiagnosticContent,
        reason: _LinkingDiagnosticReason,
        *, media_policy_facts: MediaPolicyFacts | None = None,
        transport_step: _LinkingTransportStep = 'unknown',
        retry_after_seconds: int | None = None,
        request_started: bool = True,
        definitely_rejected: bool = False,
        operation_finished: threading.Event | None = None,
    ) -> None:
        """Store only validated diagnostic fields and a safe display string.

        Args:
            phase (_LinkingDiagnosticPhase): Fixed linking operation phase.
            route (_LinkingDiagnosticRoute): Fixed registered endpoint name.
            status (int | None): HTTP status, or None if no response arrived.
            content_kind (_LinkingDiagnosticContent): Coarse response media class.
            reason (_LinkingDiagnosticReason): Fixed protocol refusal category.
            transport_step (_LinkingTransportStep): Optional allowlisted
                transport operation step; unknown preserves prior summaries.
            media_policy_facts (MediaPolicyFacts | None): Optional allowlisted
                parsed facts from the rejected media response headers.
            retry_after_seconds (int | None): Validated response delay, in seconds.
            request_started (bool): Whether HTTP dispatch may have begun.
            definitely_rejected (bool): Exact known server refusal before storage.
            operation_finished (threading.Event | None): Actual transport completion
                event, or None when this diagnosis has no asynchronous worker.

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
                    'app_linking_gallery_metadata',
                    'app_linking_media', 'app_linking_thumbnail_batch',
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
                or transport_step not in _LINKING_TRANSPORT_STEPS
                or (transport_step != 'unknown' and reason != 'timeout')
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
        self.transport_step = transport_step
        self.retry_after_seconds = retry_after_seconds
        self.request_started = request_started
        self.definitely_rejected = definitely_rejected
        self.operation_finished = operation_finished
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
        if transport_step != 'unknown':
            self.safe_summary += f'; transport_step={transport_step}'
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


class LinkingMediaNotCurrent(LinkingError):
    """Report a media response rejected by the post-response currentness fence.

    Args:
        currentness_reason (_MediaCurrentnessReason): Fixed local guard that
            rejected the otherwise accepted media response. The post-response
            guard reports the first matching state in a stable priority order;
            it does not claim which condition occurred first in time.
    """

    def __init__(self, currentness_reason: _MediaCurrentnessReason) -> None:
        """Store only a finite local rejection cause and fixed safe message.

        Args:
            currentness_reason (_MediaCurrentnessReason): Cancel, session close,
                operation deadline or grant expiry observed after transport.
                The first matching guard in the stable priority order is kept.

        Returns:
            None: No HTTP response status or remote failure is implied.

        Raises:
            ValueError: The reason is outside the local currentness allowlist.
        """
        if (type(currentness_reason) is not str
                or currentness_reason not in (
                    'cancelled', 'session_closed', 'deadline', 'grant_expired',
                )):
            raise ValueError('Invalid media currentness reason.')
        self.currentness_reason = currentness_reason
        super().__init__('Gallery media is no longer current.')


class LinkingThumbnailProofChanged(LinkingError):
    """Report that a captured thumbnail proof no longer authorizes publication."""


class LinkingThumbnailBatchDeferred(LinkingError):
    """Report a local cooldown without inventing an HTTP response.

    Args:
        retry_after_seconds (int): Remaining bounded cooldown in seconds.
        cooldown_source (_ThumbnailCooldownSource): Cause of the stored cooldown.
        operation_finished (threading.Event | None): Prior transport completion,
            when the cooldown came from an observed network operation.
    """

    def __init__(
        self, retry_after_seconds: int, *,
        cooldown_source: _ThumbnailCooldownSource = 'unknown',
        operation_finished: threading.Event | None = None,
    ) -> None:
        """Store the validated remaining delay and a fixed safe message.

        Args:
            retry_after_seconds (int): Positive remaining whole seconds within
                the existing maximum accepted server retry interval.
            cooldown_source (_ThumbnailCooldownSource): Closed cause category
                associated with the deadline that currently wins.
            operation_finished (threading.Event | None): Completion signal for
                the preceding physical request, if one caused this cooldown.

        Returns:
            None: No HTTP status or remote response is implied.

        Raises:
            ValueError: The delay is outside the existing bounded policy.
        """
        if (type(retry_after_seconds) is not int
                or not 1 <= retry_after_seconds <= UPLOAD_RETRY_AFTER_MAX_SECONDS):
            raise ValueError('Invalid thumbnail cooldown delay.')
        if cooldown_source not in (
            'transient', 'server_retry_after', 'unknown',
        ):
            raise ValueError('Invalid thumbnail cooldown source.')
        self.retry_after_seconds = retry_after_seconds
        self.cooldown_source = cooldown_source
        self.operation_finished = operation_finished
        super().__init__('Thumbnail requests are deferred until cooldown ends.')


class LinkingThumbnailBatchUnsupported(LinkingResponseError):
    """Report the explicit optional-route capability refusal."""

    def __init__(self) -> None:
        """Create the fixed, non-sensitive unsupported-route diagnosis.

        Returns:
            None: Records only the fixed status and endpoint category.
        """
        super().__init__(
            'media', 'app_linking_thumbnail_batch', 404, 'json', 'http_status',
        )


class LinkingRevisionUnsupported(LinkingError):
    """Report an older server that rejects the revision selector."""


class LinkingMediaThrottled(LinkingResponseError):
    """Report a validated media throttle and optional safe retry delay.

    Args:
        retry_after_seconds (int | None): Validated bounded server delay, if sent.
    """

    def __init__(
        self, retry_after_seconds: int | None = None, *,
        route: Literal['app_linking_media', 'app_linking_thumbnail_batch'] = 'app_linking_media',
    ) -> None:
        """Create a throttle failure without preserving raw server text.

        Args:
            retry_after_seconds (int | None): Bounded integer delay from one
                unambiguous Retry-After header, or None when unavailable.
            route (Literal['app_linking_media', 'app_linking_thumbnail_batch']):
                Exact media endpoint that returned the throttle.

        Returns:
            None: Stores the safe delay and fixed error message.
        """
        if route not in ('app_linking_media', 'app_linking_thumbnail_batch'):
            raise ValueError('Invalid media throttle route.')
        super().__init__('media', route, 429, 'json', 'throttled')
        # Keep the legacy single-photo limit unchanged; framed batches can
        # retain the already validated full-day hint through their one request.
        retry_limit = (
            UPLOAD_RETRY_AFTER_MAX_SECONDS
            if route == 'app_linking_thumbnail_batch'
            else MEDIA_RETRY_AFTER_MAX_SECONDS
        )
        if (retry_after_seconds is not None
                and (type(retry_after_seconds) is not int
                     or not 1 <= retry_after_seconds <= retry_limit)):
            retry_after_seconds = None
        self.args = ('Photo requests are limited. Try again shortly.',)
        self.retry_after_seconds = retry_after_seconds


class LinkingGrantRejected(LinkingError):
    """Report a revoked or expired authenticated linking grant."""

    status = 401


class LinkingReconnectRequired(LinkingError):
    """Require fresh browser approval after an unconfirmed credential rotation."""


class LinkingRefreshRejected(LinkingError):
    """Report a definite invalid-grant response to an exact refresh request."""

    status = 400


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


def requested_linking_scope(
    *, list_galleries: bool, upload: bool, read_details: bool,
    create_galleries: bool, change_visibility: bool, read_media: bool,
    remember_securely: bool, capabilities: frozenset[str],
    visibility_ready: bool, native_media_ready: bool,
    offline_storage_ready: bool,
) -> str:
    """Build the exact consent scope represented by the linking controls.

    Args:
        list_galleries (bool): Request access to the gallery listing.
        upload (bool): Request gallery-scoped upload permission.
        read_details (bool): Request gallery detail access.
        create_galleries (bool): Request child-gallery creation permission.
        change_visibility (bool): Request gallery visibility mutation permission.
        read_media (bool): Request temporary approved native media access.
        remember_securely (bool): Request optional native refresh storage.
        capabilities (frozenset[str]): Exact current discovery capability set.
        visibility_ready (bool): Whether the client enables visibility changes.
        native_media_ready (bool): Whether the client enables native media reads.
        offline_storage_ready (bool): Whether native storage and scope are available.
    Returns:
        str: Canonically ordered scope bundle for one browser approval request.
    Raises:
        LinkingError: A selected permission is unavailable or its dependency is absent.
    """
    list_galleries = bool(
        list_galleries or upload or read_details or create_galleries
        or change_visibility or read_media
    )
    read_details = bool(read_details or change_visibility)
    if upload and 'gallery:upload' not in capabilities:
        raise LinkingError('Gallery uploads are unavailable.')
    if list_galleries and 'galleries:list' not in capabilities:
        raise LinkingError('Gallery listing is unavailable.')
    if read_details and 'gallery:read' not in capabilities:
        raise LinkingError('Gallery details are unavailable.')
    if create_galleries and 'galleries:create' not in capabilities:
        raise LinkingError('Gallery creation is unavailable.')
    if change_visibility and (
        not visibility_ready or 'gallery:visibility' not in capabilities
    ):
        raise LinkingError('Gallery visibility changes are unavailable.')
    if read_media and (
        not native_media_ready or 'media:read' not in capabilities
        or remember_securely
    ):
        raise LinkingError('Gallery media is unavailable.')
    if remember_securely and (
        not offline_storage_ready or 'offline_access' not in capabilities
    ):
        raise LinkingError('Secure offline storage is unavailable.')
    scope = (
        READ_UPLOAD_SCOPE if read_details and upload else
        READ_SCOPE if read_details else
        UPLOAD_SCOPE if upload else
        GALLERIES_SCOPE if list_galleries else IDENTITY_SCOPE
    )
    if create_galleries:
        scope += ' galleries:create'
    if change_visibility:
        scope += ' gallery:visibility'
    if read_media:
        if 'media:admin' in capabilities:
            scope += (
                ADMIN_PRESENTATION_SCOPE_SUFFIX
                if 'presentation:read' in capabilities
                else ADMIN_MEDIA_SCOPE_SUFFIX
            )
        else:
            scope += (
                PRESENTATION_SCOPE_SUFFIX
                if 'presentation:read' in capabilities else MEDIA_SCOPE_SUFFIX
            )
    if remember_securely:
        scope += ' offline_access'
    if scope_base(scope) is None:
        raise LinkingError('The selected gallery permissions are unavailable.')
    return scope


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


def linking_prompt_issuer(value: str) -> str:
    """Resolve the website typed in either desktop authorization dialog.

    Args:
        value (str): User-entered address or unambiguous hostname and mount.
    Returns:
        str: Canonical issuer, or empty when the prompt should retain the draft.
    """
    value = value.strip()
    issuer = linking_issuer(value)
    if issuer:
        return issuer
    if value.lower().startswith('http://'):
        return linking_issuer('https://' + value[len('http://'):])
    if not value or value.startswith('//') or '://' in value:
        return ''
    authority = value.split('/', 1)[0]
    looks_like_host = (
        '.' in authority or ':' in authority or authority.startswith('[')
        or authority.lower() == 'localhost'
    )
    # A local-looking path must never become a different remote installation.
    if not looks_like_host or any(character.isspace() for character in value):
        return ''
    return linking_issuer('https://' + value)


# Purpose: Bound a refresh credential by the server's inactivity policy.
# Type: int. Units: seconds. Scope: one current refresh generation.
# Consumers: store_approved_refresh, _validated_rotation.
# Rationale: 604800 matches the server inactivity policy for either family.
OFFLINE_REFRESH_MAX_SECONDS = 604800


def _valid_token(value: object, prefix: str) -> bool:
    """Check a canonical 256-bit credential with its exact token prefix.

    Args:
        value (uploader.models.JsonValue | object): Untrusted decoded JSON
            value or another runtime value; only a canonical token string is
            accepted.
        prefix (str): Required protocol prefix for this credential kind.

    Returns:
        bool: True only for the required prefix and canonical random payload.
    """
    return (isinstance(value, str) and value.startswith(prefix)
            and _canonical_256bit(value[len(prefix):]))


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
