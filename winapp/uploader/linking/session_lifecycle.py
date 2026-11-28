# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/session_lifecycle.py
# Module Type: Windows Client Session Behavior
# Purpose: Own session authorization, renewal and request admission.
# Responsibilities:
#   - Keep one cohesive group of linked-session operations together.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own session authorization, renewal and request admission."""

from typing import Callable
from typing import Iterable
from ..models import JsonValue
from ..linking_performance import LinkingRoutePerformanceWindow
from typing import Literal
from collections import OrderedDict
from ..passive_server_timing import PassiveServerTiming
from ..passive_server_timing import ServerTimingWindow
from ..linking_performance import linking_route_category
import math
import threading
import time
from .core import (
    GALLERIES_SCOPE,
    IDENTITY_SCOPE,
    LINKING_KEEPALIVE_SECONDS,
    LINKING_MAX_TOKEN_LIFETIME_SECONDS,
    LINKING_SESSION_ADMISSION_TIMEOUT_SECONDS,
    LinkingError,
    LinkingGrantRejected,
    LinkingReconnectRequired,
    LinkingRefreshRejected,
    LinkingResponseError,
    ROLLING_SCOPE_SUFFIX,
    TOKEN_PREFIX,
    UPLOAD_SCOPE,
    _FairRequestGate,
    _ThumbnailCooldownSource,
    _canonical_256bit,
    _issuer_valid,
    scope_allows_admin_media,
    scope_allows_gallery_create,
    scope_allows_gallery_read,
    scope_allows_gallery_search,
    scope_allows_gallery_visibility,
    scope_base,
)
from .models import (
    LinkedGalleryDetail,
    LinkedGalleryEditSnapshot,
    LinkedMediaPage,
    LinkedPhoto,
)
from .transport import (
    _MediaConnectionPool,
    _request_json,
)



class LinkingSessionLifecycleMixin:
    """Provide session authorization, renewal and request admission."""

    def __init__(
        self, issuer: str, token: str, expires_at: float,
        scope: str = IDENTITY_SCOPE, *, admin_preload_supported: bool = False,
        gallery_tree_supported: bool = False,
        thumbnail_batch_supported: bool = False,
        sliding_access_supported: bool = False,
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
            thumbnail_batch_supported (bool): Whether discovery advertised the
                optional framed thumbnail batch protocol.
            sliding_access_supported (bool): Whether discovery promises the
                day-v1 rolling access deadline after real authenticated traffic.

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
                or type(sliding_access_supported) is not bool
                or type(thumbnail_batch_supported) is not bool
                or not math.isfinite(expires_at)
                or expires_at <= time.monotonic()):
            raise LinkingError('Invalid linking session.')
        self._lock = threading.Lock()
        self._request_gate = _FairRequestGate()
        self._route_performance = LinkingRoutePerformanceWindow()
        self._server_timing_window = ServerTimingWindow()
        self._upload_pipeline_waiting = threading.Event()
        self._media_connection_pool = _MediaConnectionPool()
        self._cancelled = threading.Event()
        self._pending_gallery_cancels: set[threading.Event] = set()
        self._visibility_snapshot: LinkedGalleryDetail | None = None
        self._gallery_edit_snapshot: LinkedGalleryEditSnapshot | None = None
        self._recent_media_pages: OrderedDict[
            int, tuple[float, LinkedMediaPage]
        ] = OrderedDict()
        # Complete newest-first display snapshots are metadata only. Their
        # original per-photo proof timestamps remain unchanged and are checked
        # by media_photo before any cached binary can be reused.
        self._recent_media_orders: OrderedDict[
            int, tuple[float, tuple[LinkedPhoto, ...]]
        ] = OrderedDict()
        # The most recent authorized public page supersedes previous
        # thumbnail descriptions, including removed rows and unproven bytes.
        self._public_media_descriptors: OrderedDict[
            int, dict[int, tuple[str | None, float | None]]
        ] = OrderedDict()
        # A no-validator original fallback becomes reusable within one
        # authorized metadata epoch only after binary revalidation succeeds.
        self._public_unverified_confirmations: OrderedDict[
            tuple[int, int], tuple[float, str]
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
        self._cover_page_supported: bool | None = None
        self._gallery_tree_supported = gallery_tree_supported
        self._thumbnail_batch_supported = thumbnail_batch_supported
        self._sliding_access_supported = (
            sliding_access_supported and scope.endswith(ROLLING_SCOPE_SUFFIX)
        )
        self._last_authenticated_at = time.monotonic()
        self._thumbnail_batch_disabled_reason = ''
        self._thumbnail_batch_cooldown_until = 0.0
        self._thumbnail_batch_cooldown_source: _ThumbnailCooldownSource = 'unknown'
        self._thumbnail_batch_cooldown_operation_finished: threading.Event | None = None
        self._token = token
        self._expires_at = expires_at
        self._busy = False
        self._active_route = ''
        self._renew: Callable[[threading.Event], tuple[str, float]] | None = None
        self._closed = False

    def route_performance_summary(self) -> str:
        """Return bounded route/status diagnostics for the active Photos link.

        Returns:
            str: Fixed numeric counters without request URLs or identities.
        """
        return self._route_performance.render_summary()

    def server_timing_summary(self) -> str:
        """Return paired safe PHP/client timing without a request identifier.

        Returns:
            str: Fixed route and numeric PHP observations or explicit missing.
        """
        return self._server_timing_window.render_summary()

    def _observe_route_performance(
        self, route: str | None, status: int | None, reason: str,
        elapsed_ms: float, attempts: int | None,
    ) -> None:
        """Keep any observer failure independent from a real HTTP outcome.

        Args:
            route (str | None): Fixed safe route category, or None if unknown.
            status (int | None): Observed HTTP status, or unknown.
            reason (str): Fixed diagnostic outcome code.
            elapsed_ms (float): Measured local operation owner duration.
            attempts (int | None): Known physical sends, or unknown.

        Returns:
            None: Failure to record cannot replace application behavior.
        """
        if route is None:
            return
        try:
            self._route_performance.observe(
                route, status, reason, elapsed_ms, attempts,
            )
        except Exception:
            # Passive diagnostics must not alter network/error semantics.
            pass

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
        except (LinkingRefreshRejected, LinkingReconnectRequired):
            self.close()
            raise
        with self._lock:
            if self._closed:
                raise LinkingError('Linking session was cancelled.')
            self._token = token
            self._expires_at = expires_at
            self._private_preload_generation += 1
            self._recent_media_pages.clear()
            self._recent_media_orders.clear()
            self._public_media_descriptors.clear()
            self._public_unverified_confirmations.clear()
            self._private_preload_proofs.clear()

    def _access_used(self, started: float) -> None:
        """Slide only negotiated rolling access after a real authenticated reply.

        Args:
            started (float): Monotonic request start, conservatively before the
                server renewed its access and refresh inactivity deadlines.
        Returns:
            None: Cached bytes, failed requests and closed sessions grant no time.
        """
        with self._lock:
            if not self._closed:
                self._last_authenticated_at = max(self._last_authenticated_at, started)
                if self._sliding_access_supported:
                    self._expires_at = max(
                        self._expires_at, started + LINKING_MAX_TOKEN_LIFETIME_SECONDS,
                    )

    def keepalive_due(self) -> bool:
        """Check whether a rolling link needs a read-only idle activity check.

        Returns:
            bool: True after five minutes without an authenticated response;
                this local check sends nothing and never revives a closed grant.
        """
        with self._lock:
            return (not self._closed and self._renew is not None
                    and time.monotonic() - self._last_authenticated_at
                    >= LINKING_KEEPALIVE_SECONDS)

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
            'app_linking_media_delete', 'app_linking_gallery_metadata',
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
            if (route in ('app_linking_media_delete', 'app_linking_gallery_metadata')
                    and not scope_allows_admin_media(self._scope)):
                raise LinkingError('Photo deletion requires an Admin Photos link.')
            self._busy = True
            self._active_route = route
            token = self._token
        watch_options = {'watch_key': watch_key} if watch_key is not None else {}
        diagnostic_started = time.monotonic()
        diagnostic_status: int | None = None
        diagnostic_reason = 'unknown'
        diagnostic_attempts: int | None = None
        php_response: list[
            tuple[PassiveServerTiming | None, float | None]
        ] = []
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
                    server_timing_out=php_response,
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
                           'app_linking_media_delete', 'app_linking_gallery_metadata'):
                data = _request_json(
                    self._issuer, route, 'POST', body, token,
                    cancelled=(gallery_cancel if route in (
                        'app_linking_inventory', 'app_linking_gallery_create',
                        'app_linking_gallery_visibility', 'app_linking_media_delete', 'app_linking_gallery_metadata',
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
                    **({'server_timing_out': php_response}
                       if route == 'app_linking_galleries' else {}),
                )
            else:
                data = _request_json(
                    self._issuer, route, 'GET', None, token,
                    cancelled=self._cancelled, after_id=after_id,
                    **({'server_timing_out': php_response}
                       if route == 'app_linking_galleries' else {}),
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
                diagnostic_status = (
                    409 if '__client_visibility_conflict' in data else 200
                )
                diagnostic_reason = (
                    'http_status' if diagnostic_status == 409 else 'ok'
                )
                diagnostic_attempts = 1
            if route != 'app_linking_revoke':
                self._access_used(diagnostic_started)
            if diagnostic_status == 200 and php_response:
                self._server_timing_window.observe(
                    linking_route_category(route),
                    php_response[-1][0], php_response[-1][1], 1,
                )
            return data
        except LinkingResponseError as error:
            diagnostic_status = error.status
            diagnostic_reason = error.reason
            diagnostic_attempts = 1 if error.request_started else 0
            raise
        except LinkingGrantRejected as error:
            diagnostic_reason = 'grant_rejected'
            diagnostic_attempts = 1 if error.request_started else 0
            with self._lock:
                self._private_preload_generation += 1
                self._private_preload_proofs.clear()
                # Never reuse a known rejected bearer. Renewable links may
                # rotate on the next request; this request is not replayed.
                self._expires_at = time.monotonic()
            raise
        finally:
            self._observe_route_performance(
                linking_route_category(route), diagnostic_status,
                diagnostic_reason,
                (time.monotonic() - diagnostic_started) * 1000.0,
                diagnostic_attempts,
            )
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
            # A logical Photos operation may be between requests while no
            # route is active. Do not cancel its owner during an active upload.
            if gallery_only or self._active_route in (
                '', 'app_linking_media', 'app_linking_thumbnail_batch',
            ):
                for pending in self._pending_gallery_cancels:
                    pending.set()
            if self._busy and (not gallery_only
                               or self._active_route in (
                                   'app_linking_galleries',
                                   'app_linking_gallery_tree',
                                   'app_linking_gallery_detail',
                               )):
                self._cancelled.set()
