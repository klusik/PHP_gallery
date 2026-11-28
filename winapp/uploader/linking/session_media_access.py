# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/session_media_access.py
# Module Type: Windows Client Session Behavior
# Purpose: Own media permissions, cache access and read operations.
# Responsibilities:
#   - Keep one cohesive group of linked-session operations together.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own media permissions, cache access and read operations."""

from typing import Callable
from ..gallery_media_cache import GalleryMediaCache
from ..gallery_media_cache import PHOTO_CACHE_FRESH_SECONDS
from ..thumbnail_batch_protocol import PRODUCTION_MAX_IMAGE_BYTES
from ..gallery_media_cache import PhotoCacheEntry
from ..gallery_media_cache import PhotoCacheReceipt
from ..thumbnail_batch_protocol import ValidatedItem
from dataclasses import replace as dataclass_replace
import hashlib
import json
from ..linking_performance import linking_route_category
import math
import re
import threading
import time
from urllib.parse import urlencode
from .core import (
    GALLERIES_SCOPE,
    LINKING_REQUEST_DEADLINE_SECONDS,
    LINKING_SESSION_ADMISSION_TIMEOUT_SECONDS,
    LINKING_SOCKET_TIMEOUT_SECONDS,
    LinkedMediaDetails,
    LinkingError,
    LinkingGrantRejected,
    LinkingMediaNotCurrent,
    LinkingMediaUnavailable,
    LinkingResponseError,
    LinkingRevisionUnsupported,
    MEDIA_RECENT_ORDER_GALLERY_LIMIT,
    MEDIA_RECENT_ORDER_PHOTO_LIMIT,
    MEDIA_RECENT_PAGE_LIMIT,
    MediaReadTimings,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    NATIVE_MEDIA_DETAIL_TEXT_CHARS,
    NATIVE_MEDIA_LEGACY_PAGE_SIZE,
    NATIVE_MEDIA_MAX_BYTES,
    NATIVE_MEDIA_PAGE_SIZE,
    PRIVATE_PRELOAD_PROOF_ROW_LIMIT,
    UPLOAD_SCOPE,
    _MediaCurrentnessReason,
    _NATIVE_AUDIO_TYPES,
    _NATIVE_IMAGE_TYPES,
    _NativeMediaKind,
    _NativeMediaResponse,
    scope_allows_admin_media,
    scope_allows_presentation,
    scope_base,
)
from .models import (
    LegacyThumbnailPublication,
    LinkedMediaGalleryInfo,
    LinkedMediaPage,
    LinkedPhoto,
    ThumbnailBatchWitness,
    ThumbnailPublication,
    _linked_admin_photo,
    _linked_media_details,
    _linked_media_gallery_inventory,
    _linked_thumbnail_etag,
    _native_media_id,
    _native_media_title,
    _observe_active_thumbnails,
)
from .media import (
    _native_media_json,
    _native_media_read_once,
)
from .transport import (
    _run_linking_transport,
)



class LinkingSessionMediaAccessMixin:
    """Provide media permissions, cache access and read operations."""

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
        operation_deadline: float | None = None,
        cover_only: bool = False,
        active_photos: Callable[[tuple[int, ...]], None] | None = None,
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
            operation_deadline (float | None): Optional absolute monotonic
                deadline shared with a larger operation, or None for the
                existing independent admission and transport limits.
            cover_only (bool): Request one first-page cover descriptor without
                changing the route, authorization or legacy-server fallback.
            active_photos (Callable[[tuple[int, ...]], None] | None): Optional
                informational observer for the exact active thumbnail GET. It
                receives the singleton ID immediately around transport only;
                observer errors never affect authorization or delivery.

        Returns:
            _NativeMediaResponse: Body/304 status, media type, validator, and
                negotiated cache permission.

        Raises:
            LinkingMediaNotCurrent: The accepted response lost its local
                currentness fence before returning to the caller.
            LinkingError: Invalid target, expired grant, unavailable media or unsafe transfer.
        """
        if not _native_media_id(gallery_id):
            raise LinkingError('Invalid gallery media target.')
        if (type(cover_only) is not bool
                or cover_only and (kind != 'photos' or selector is not None)):
            raise LinkingError('Invalid gallery cover selector.')
        if (operation_deadline is not None and (
                type(operation_deadline) not in (int, float)
                or not math.isfinite(operation_deadline))):
            raise LinkingError('Invalid gallery media deadline.')
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
                admission_timeout = LINKING_SESSION_ADMISSION_TIMEOUT_SECONDS
                if operation_deadline is not None:
                    admission_timeout = min(
                        admission_timeout, operation_deadline - time.monotonic(),
                    )
                    if admission_timeout <= 0:
                        raise LinkingError('Gallery media deadline expired.')
                admitted = self._request_gate.acquire(
                    timeout=admission_timeout,
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
                if (operation_deadline is not None
                        and time.monotonic() >= operation_deadline):
                    raise LinkingError('Gallery media deadline expired.')
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
                    diagnostic_started = time.monotonic()
                    diagnostic_status: int | None = None
                    diagnostic_reason = 'unknown'
                    diagnostic_attempts: int | None = None
                    prior_attempts = (
                        timings.http_requests if timings is not None else 0
                    )
                    prior_ttfb_ms = (
                        timings.ttfb_ms if timings is not None else 0.0
                    )
                    if timings is not None:
                        timings.network_reads += 1
                    try:
                        transport_timeout = float(LINKING_REQUEST_DEADLINE_SECONDS)
                        if operation_deadline is not None:
                            transport_timeout = min(
                                transport_timeout,
                                operation_deadline - time.monotonic(),
                            )
                            if transport_timeout <= 0:
                                raise LinkingError('Gallery media deadline expired.')
                        active_ids = ((selector,) if active_photos is not None
                                      and kind == 'thumbnail' and type(selector) is int else ())
                        if active_ids:
                            _observe_active_thumbnails(active_photos, active_ids)
                        try:
                            result = _run_linking_transport(
                                lambda control: _native_media_read_once(
                                    self._issuer, encoded, token, accepted, control,
                                    if_none_match, allow_restricted=admin_media,
                                    admin_preload=admin_preload,
                                    pool=self._media_connection_pool,
                                    timings=timings,
                                    **({'cover_only': True} if cover_only else {}),
                                ), cancel, transport_timeout,
                                LINKING_SOCKET_TIMEOUT_SECONDS,
                                phase='media', route='app_linking_media',
                                timings=timings,
                            )
                        finally:
                            if active_ids:
                                _observe_active_thumbnails(active_photos, ())
                        diagnostic_status = 304 if result[0] is None else 200
                        diagnostic_reason = 'ok'
                        self._access_used(diagnostic_started)
                    except LinkingResponseError as error:
                        diagnostic_status = error.status
                        diagnostic_reason = error.reason
                        raise
                    except LinkingGrantRejected:
                        diagnostic_reason = 'grant_rejected'
                        raise
                    except LinkingMediaUnavailable:
                        diagnostic_reason = 'target_unavailable'
                        raise
                    except LinkingRevisionUnsupported:
                        diagnostic_reason = 'revision_unsupported'
                        raise
                    finally:
                        if timings is not None:
                            diagnostic_attempts = (
                                timings.http_requests - prior_attempts
                            )
                        # Compare only a genuinely accepted media response
                        # using these exact physical send and TTFB deltas.
                        if (timings is not None
                                and diagnostic_status in (200, 304)):
                            self._server_timing_window.observe(
                                linking_route_category('app_linking_media', kind),
                                timings.server_timing,
                                timings.ttfb_ms - prior_ttfb_ms,
                                diagnostic_attempts,
                            )
                        # An unmetered pooled GET can retry a stale socket.
                        # Keep its physical send count unknown, not guessed.
                        self._observe_route_performance(
                            linking_route_category('app_linking_media', kind),
                            diagnostic_status, diagnostic_reason,
                            (time.monotonic() - diagnostic_started) * 1000.0,
                            diagnostic_attempts,
                        )
                        if timings is not None:
                            timings.network_ms += (
                                time.perf_counter() - transport_started
                            ) * 1000.0
                    with self._lock:
                        # Session closure wins when close also signals cancel.
                        currentness_reason: _MediaCurrentnessReason | None = None
                        if self._closed:
                            currentness_reason = 'session_closed'
                        elif cancel.is_set():
                            currentness_reason = 'cancelled'
                        elif (operation_deadline is not None
                              and time.monotonic() >= operation_deadline):
                            currentness_reason = 'deadline'
                        elif time.monotonic() >= self._expires_at:
                            currentness_reason = 'grant_expired'
                        if currentness_reason is not None:
                            raise LinkingMediaNotCurrent(currentness_reason)
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
        *, timings: MediaReadTimings | None = None, cover_only: bool = False,
    ) -> LinkedMediaPage:
        """Read a bounded page of currently permitted direct gallery photos.

        Args:
            gallery_id (int): Selected physical gallery ID.
            after_id (int | None): Previous exclusive cursor or None for first page.
            cancelled (threading.Event | None): Current viewer cancellation.
            timings (MediaReadTimings | None): Optional metadata-only counters.
            cover_only (bool): Request just the first authorized photo for a
                folder cover. Older servers can return their ordinary bounded
                first page; its normal permission and validator checks remain.

        Returns:
            LinkedMediaPage: Bound parent, ordered images and optional continuation.

        Raises:
            LinkingError: The response, target or permission is unavailable.
        """
        if (type(cover_only) is not bool
                or cover_only and after_id is not None):
            raise LinkingError('Invalid gallery cover page.')
        proof_generation: list[int] = []
        options: dict[str, MediaReadTimings | list[int] | bool] = (
            {'timings': timings} if timings is not None else {}
        )
        with self._lock:
            request_cover = cover_only and self._cover_page_supported is not False
        if request_cover:
            options['cover_only'] = True
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
                    self._recent_media_orders.pop(gallery_id, None)
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
                thumbnail_verified_at=(
                    verified_at if not scope_allows_admin_media(self._scope)
                    or photo.cache_eligible is True else None
                ),
                preload_verified_at=(
                    verified_at if photo.preload_thumbnail_etag is not None else None
                ),
            ))
            previous = image_id
        if (next_after is not None and (type(next_after) is not int
                or next_after != previous
                or len(photos) not in (NATIVE_MEDIA_LEGACY_PAGE_SIZE, NATIVE_MEDIA_PAGE_SIZE))):
            raise LinkingError('Gallery photo cursor was not accepted.')
        if request_cover:
            supported = page.get('cover_page') is True
            if supported and (len(photos) > 1 or next_after is not None):
                raise LinkingError('Gallery cover page was not accepted.')
            with self._lock:
                self._cover_page_supported = supported
            if not supported and photos:
                # Permanent support for older approved servers: an unknown
                # header selects their small legacy page without validator
                # proofs. Retry this passive read once using the established
                # catalogue header, then use that lane for this session. Never
                # degrade automatic private-thumbnail reuse to binary reloads.
                return self.media_page(gallery_id, cancelled=cancelled, timings=timings)
        result = LinkedMediaPage(gallery_id, parent, tuple(photos), next_after,
                                 cover_only=request_cover and page.get('cover_page') is True)
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
            if not self._closed:
                # A first page starts a new catalogue epoch. Older IDs absent
                # from that page must not reauthorize local photo bytes.
                current = (
                    {} if after_id is None
                    else dict(self._public_media_descriptors.get(gallery_id, {}))
                )
                if after_id is None:
                    for key in tuple(self._public_unverified_confirmations):
                        if key[0] == gallery_id:
                            del self._public_unverified_confirmations[key]
                for prior_id in tuple(current):
                    if (prior_id > (after_id or 0)
                            and (next_after is None or prior_id <= previous)):
                        current.pop(prior_id, None)
                for photo in photos:
                    if (not scope_allows_admin_media(self._scope)
                            or photo.cache_eligible is True):
                        current[photo.image_id] = (
                            photo.thumbnail_etag, photo.thumbnail_verified_at,
                        )
                while len(current) > PRIVATE_PRELOAD_PROOF_ROW_LIMIT:
                    current.pop(next(iter(current)))
                self._public_media_descriptors[gallery_id] = current
                self._public_media_descriptors.move_to_end(gallery_id)
                while len(self._public_media_descriptors) > MEDIA_RECENT_PAGE_LIMIT:
                    removed_gallery, _rows = self._public_media_descriptors.popitem(
                        last=False,
                    )
                    for key in tuple(self._public_unverified_confirmations):
                        if key[0] == removed_gallery:
                            del self._public_unverified_confirmations[key]
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

    def cache_recent_media_order(
        self, gallery_id: int, photos: tuple[LinkedPhoto, ...],
        validated_at: float,
    ) -> bool:
        """Retain one complete display order within the existing metadata freshness window.

        Args:
            gallery_id (int): Exact physical gallery represented by the rows.
            photos (tuple[LinkedPhoto, ...]): Complete newest-first validated rows.
            validated_at (float): Monotonic start time of the full page read.

        Returns:
            bool: True when the live session retained the bounded snapshot;
                False when it is stale, oversized, malformed or the session ended.
                This cache never creates or renews thumbnail authorization.
        """
        if (not _native_media_id(gallery_id) or not isinstance(photos, tuple)
                or len(photos) > MEDIA_RECENT_ORDER_PHOTO_LIMIT
                or type(validated_at) not in (int, float)
                or not math.isfinite(validated_at)):
            return False
        if any(
            not isinstance(photo, LinkedPhoto)
            or type(photo.image_id) is not int
            or not _native_media_id(photo.image_id)
            or not isinstance(photo.title, str)
            or not _native_media_title(photo.title)
            for photo in photos
        ):
            return False
        if any(
            left.image_id <= right.image_id
            for left, right in zip(photos, photos[1:])
        ):
            return False
        now = time.monotonic()
        if not 0 <= now - validated_at < PHOTO_CACHE_FRESH_SECONDS:
            return False
        if not self.can_read_media():
            return False
        with self._lock:
            now = time.monotonic()
            if (self._closed or now >= self._expires_at
                    or not 0 <= now - validated_at < PHOTO_CACHE_FRESH_SECONDS):
                return False
            self._recent_media_orders.pop(gallery_id, None)
            for old_gallery_id, (stored_at, _rows) in tuple(
                self._recent_media_orders.items()
            ):
                if not 0 <= now - stored_at < PHOTO_CACHE_FRESH_SECONDS:
                    self._recent_media_orders.pop(old_gallery_id, None)
            retained_rows = sum(
                len(rows) for _stored_at, rows in self._recent_media_orders.values()
            )
            while (
                self._recent_media_orders
                and (
                    len(self._recent_media_orders) >= MEDIA_RECENT_ORDER_GALLERY_LIMIT
                    or retained_rows + len(photos) > MEDIA_RECENT_ORDER_PHOTO_LIMIT
                )
            ):
                _old_gallery_id, (_stored_at, old_rows) = (
                    self._recent_media_orders.popitem(last=False)
                )
                retained_rows -= len(old_rows)
            self._recent_media_orders[gallery_id] = (validated_at, photos)
            return True

    def recent_media_order(
        self, gallery_id: int,
    ) -> tuple[LinkedPhoto, ...] | None:
        """Return fresh metadata for a recently completed newest-first listing.

        Args:
            gallery_id (int): Exact physical gallery requested by the viewer.

        Returns:
            tuple[LinkedPhoto, ...] | None: Complete display-only order for the
                same live session, or None when unavailable/stale. Returned
                descriptor proof timestamps are unchanged; this lookup never
                authorizes binary media or renews a proof.
        """
        if not _native_media_id(gallery_id) or not self.can_read_media():
            return None
        now = time.monotonic()
        with self._lock:
            entry = self._recent_media_orders.get(gallery_id)
            if self._closed or now >= self._expires_at or entry is None:
                return None
            stored_at, photos = entry
            if not 0 <= now - stored_at < PHOTO_CACHE_FRESH_SECONDS:
                self._recent_media_orders.pop(gallery_id, None)
                return None
            self._recent_media_orders.move_to_end(gallery_id)
            return photos

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

    def _public_thumbnail_descriptor_current(
        self, gallery_id: int, image_id: int,
        thumbnail_etag: str | None,
        thumbnail_verified_at: float | None,
    ) -> bool:
        """Refuse an obsolete public thumbnail descriptor after a fresh page.

        Args:
            gallery_id (int): Gallery whose latest authorized page was received.
            image_id (int): Direct-photo identity supplied by the caller.
            thumbnail_etag (str | None): Optional page content validator.
            thumbnail_verified_at (float | None): Exact page observation epoch.

        Returns:
            bool: Whether this descriptor matches the latest known page row;
                sessions without any page retain the original HTTP-only path.
        """
        with self._lock:
            latest = self._public_media_descriptors.get(gallery_id)
            if latest is None:
                return not self._closed
            current = latest.get(image_id)
            if current is None:
                # A refreshed page no longer contains this image.
                return False
            current_etag, current_verified_at = current
            if thumbnail_etag is not None:
                # The latest authorized page independently confirmed these
                # exact content bytes. Do not invalidate an unchanged photo
                # merely because its newer page has a different receipt time.
                return thumbnail_etag == current_etag
            # Without a strong validator, only the exact page epoch can
            # authorize a cached image or the subsequent binary recheck.
            return (current_etag is None
                    and thumbnail_verified_at == current_verified_at)

    def _public_cached_entry_current(
        self, gallery_id: int, image_id: int,
        cache: GalleryMediaCache, entry: PhotoCacheEntry,
        thumbnail: bool, thumbnail_etag: str | None,
        thumbnail_verified_at: float | None,
        cancelled: threading.Event | None,
    ) -> bool:
        """Apply the shared public disk freshness and live-session fence.

        Args:
            gallery_id (int): Exact gallery whose descriptor was refreshed.
            image_id (int): Direct photo whose fallback was revalidated.
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
        if (thumbnail and thumbnail_etag is None
                and thumbnail_verified_at is not None):
            # An unproven page cannot silently renew a previously cached
            # original. One real HTTP 200/304 must confirm its exact body.
            with self._lock:
                confirmation = self._public_unverified_confirmations.get(
                    (gallery_id, image_id),
                )
            if confirmation != (thumbnail_verified_at, entry.etag):
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

    def prepare_thumbnail_batch_item(
        self, gallery_id: int, photo: LinkedPhoto,
        cancelled: threading.Event | None = None,
    ) -> ThumbnailBatchWitness:
        """Capture a currently registered public or private thumbnail proof.

        Args:
            gallery_id (int): Physical gallery owning the direct photo.
            photo (LinkedPhoto): Exact catalogue descriptor for the candidate.
            cancelled (threading.Event | None): Optional viewer cancellation.

        Returns:
            ThumbnailBatchWitness: Immutable issuer, row and account evidence.

        Raises:
            LinkingError: The candidate lacks current independent batch proof.
        """
        if (not _native_media_id(gallery_id) or not isinstance(photo, LinkedPhoto)
                or not _native_media_id(photo.image_id)
                or (cancelled is not None and cancelled.is_set())):
            raise LinkingError('Gallery thumbnail proof is unavailable.')
        private = self.can_read_admin_media() and photo.cache_eligible is not True
        identity = (
            self.private_preload_cache_identity(gallery_id, photo, thumbnail=True)
            if private else None
        )
        if private and identity is None:
            # Restricted and unproven private rows retain their existing GET
            # owner; a digest alone cannot establish their current authority.
            raise LinkingError('Gallery thumbnail proof is unavailable.')
        with self._lock:
            rows = self._public_media_descriptors.get(gallery_id)
            row = rows.get(photo.image_id) if rows is not None else None
            witness = ThumbnailBatchWitness(
                self, self._issuer, gallery_id, photo,
                'private' if private else 'public',
                None if private else row, identity, self._private_preload_generation,
            )
        if not self._thumbnail_batch_witness_current(witness, cancelled):
            raise LinkingError('Gallery thumbnail proof is unavailable.')
        return witness

    def _thumbnail_batch_witness_current(
        self, witness: ThumbnailBatchWitness,
        cancelled: threading.Event | None,
    ) -> bool:
        """Recheck the exact captured row and live session without network work.

        Args:
            witness (ThumbnailBatchWitness): Previously captured row authority.
            cancelled (threading.Event | None): Optional viewer cancellation.

        Returns:
            bool: True only while the same issuer, row and account remain live.
        """
        if cancelled is not None and cancelled.is_set():
            return False
        now = time.monotonic()
        photo = witness.photo
        with self._lock:
            if (witness.owner is not self
                    or self._closed or not self._token or now >= self._expires_at
                    or self._issuer != witness.issuer
                    or scope_base(self._scope) not in (GALLERIES_SCOPE, UPLOAD_SCOPE)
                    or (cancelled is not None and cancelled.is_set())):
                return False
            if witness.kind == 'public':
                row = witness.public_row
                rows = self._public_media_descriptors.get(witness.gallery_id)
                return bool(
                    row is not None and rows is not None
                    and (photo.cache_eligible is True
                         or (photo.cache_eligible is None
                             and not scope_allows_admin_media(self._scope)))
                    and rows.get(photo.image_id) == row
                    and row == (photo.thumbnail_etag, photo.thumbnail_verified_at)
                    and row[1] is not None
                    and 0 <= now - row[1] < PHOTO_CACHE_FRESH_SECONDS
                )
            identity = witness.private_identity
            proof = self._private_preload_proofs.get(witness.gallery_id)
            row = proof[2].get(photo.image_id) if proof is not None else None
            return bool(
                witness.kind == 'private' and identity is not None
                and photo.nsfw is False and photo.cache_eligible is False
                and photo.preload_eligible is True
                and self._admin_preload_supported
                and scope_allows_admin_media(self._scope)
                and self._private_preload_generation == witness.generation
                and proof is not None and proof[0] == identity[0]
                and self._bound_user_id == identity[0] and row is not None
                and row[:3] == identity[1:]
                and photo.preload_thumbnail_etag == identity[1]
                and photo.preload_verified_at is not None
                and photo.preload_verified_at <= identity[2]
                and 0 <= now - identity[2] < PHOTO_CACHE_FRESH_SECONDS
            )

    def thumbnail_publication_current(
        self, publication: ThumbnailPublication | LegacyThumbnailPublication,
        cancelled: threading.Event | None = None,
    ) -> bool:
        """Recheck a published item's exact proof before later GUI reuse.

        Args:
            publication (ThumbnailPublication | LegacyThumbnailPublication):
                Completed immutable batch or transient single item.
            cancelled (threading.Event | None): Optional viewer cancellation.

        Returns:
            bool: True only while its captured authorization remains current.
        """
        if isinstance(publication, LegacyThumbnailPublication):
            if (publication.owner is not self or publication.issuer != self._issuer
                    or publication.ram_eligible is not False):
                return False
            if publication.witness is not None and (
                    publication.witness.owner is not self
                    or publication.witness.issuer != publication.issuer
                    or publication.witness.gallery_id != publication.gallery_id
                    or publication.witness.photo != publication.photo):
                return False
            with self._lock:
                live = (not self._closed and bool(self._token)
                        and time.monotonic() < self._expires_at
                        and scope_base(self._scope) in (GALLERIES_SCOPE, UPLOAD_SCOPE)
                        and (publication.witness is not None
                             or scope_allows_admin_media(self._scope))
                        and self._private_preload_generation == publication.generation
                        and (cancelled is None or not cancelled.is_set()))
            return bool(live and (publication.witness is None
                        or self._thumbnail_batch_witness_current(
                            publication.witness, cancelled,
                        )))
        return (isinstance(publication, ThumbnailPublication)
                and publication.photo == publication.witness.photo
                and self._thumbnail_batch_witness_current(publication.witness, cancelled))

    def _invalidate_thumbnail_batch_witness(
        self, witness: ThumbnailBatchWitness,
    ) -> None:
        """Invalidate only the exact captured row after a validator refusal.

        Args:
            witness (ThumbnailBatchWitness): Earlier independently captured row.

        Returns:
            None: A newer replacement row remains untouched.
        """
        with self._lock:
            if witness.owner is not self:
                return
            if witness.kind == 'public':
                rows = self._public_media_descriptors.get(witness.gallery_id)
                if rows is not None and rows.get(witness.photo.image_id) == witness.public_row:
                    rows.pop(witness.photo.image_id, None)
                    self._public_unverified_confirmations.pop(
                        (witness.gallery_id, witness.photo.image_id), None,
                    )
            else:
                proof = self._private_preload_proofs.get(witness.gallery_id)
                row = proof[2].get(witness.photo.image_id) if proof else None
                if (proof is not None and witness.private_identity is not None
                        and proof[0] == witness.private_identity[0]
                        and row is not None and row[:3] == witness.private_identity[1:]
                        and self._private_preload_generation == witness.generation):
                    proof[2].pop(witness.photo.image_id, None)
                    self._private_preload_generation += 1
                    self._private_preload_proof_epoch += 1

    def finish_thumbnail_batch_item(
        self, witness: ThumbnailBatchWitness, item: ValidatedItem,
        cancelled: threading.Event | None = None, *,
        timings: MediaReadTimings | None = None,
        check_active: Callable[[], None] | None = None,
    ) -> ThumbnailPublication:
        """Apply current authority and cache policy to one verified wire item.

        Args:
            witness (ThumbnailBatchWitness): Proof captured before batch admission.
            item (ValidatedItem): Production parser's complete validated item.
            cancelled (threading.Event | None): Optional viewer cancellation.
            timings (MediaReadTimings | None): Optional cache-write observations.
            check_active (Callable[[], None] | None): Optional worker deadline
                and cancellation checker, called around cache publication.

        Returns:
            ThumbnailPublication: Independently authorized result and RAM policy.

        Raises:
            LinkingError: Identity, digest, validator or current proof changed.
        """
        if check_active is not None:
            check_active()
        if (not isinstance(witness, ThumbnailBatchWitness)
                or not isinstance(item, ValidatedItem)
                or item.image_id != str(witness.photo.image_id)
                or item.status not in ('ok', 'unavailable', 'oversize')
                or not self._thumbnail_batch_witness_current(witness, cancelled)):
            raise LinkingError('Gallery thumbnail proof is unavailable.')
        expected = (witness.public_row[0] if witness.public_row is not None
                    else witness.private_identity[1]
                    if witness.private_identity is not None else None)
        if item.status == 'ok':
            if (not item.data or len(item.data) > PRODUCTION_MAX_IMAGE_BYTES
                    or item.mime not in ('image/jpeg', 'image/webp')
                    or item.sha256 != hashlib.sha256(item.data).hexdigest()
                    or (item.etag is not None
                        and item.etag != '"' + item.sha256 + '"')):
                raise LinkingError('Gallery thumbnail response is invalid.')
            if expected is not None and item.etag != expected:
                self._invalidate_thumbnail_batch_witness(witness)
                raise LinkingError('Gallery thumbnail descriptor requires refresh.')
        elif (item.data or item.sha256 is not None or item.etag is not None
              or item.mime != 'application/octet-stream'):
            raise LinkingError('Gallery thumbnail response is invalid.')
        ram_eligible = item.status == 'ok' and expected is not None
        if ram_eligible:
            if not self._thumbnail_batch_witness_current(witness, cancelled):
                raise LinkingError('Gallery thumbnail proof is unavailable.')
            started = time.perf_counter()
            cache: GalleryMediaCache | None = None
            cache_observed: dict[str, int] | None = None
            try:
                cache = GalleryMediaCache.for_current_user(
                    NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
                )
                if timings is not None:
                    cache_observed = cache.operation_counts()
                if witness.private_identity is not None:
                    key = cache.private_key(
                        witness.issuer, witness.private_identity[0],
                        witness.gallery_id, witness.photo.image_id, True,
                    )
                    gallery_key = (cache.gallery_key(witness.issuer, witness.gallery_id)
                                   if self._private_preload_intent_user_id(
                                       witness.gallery_id,
                                   ) is not None else None)
                else:
                    key = cache.key(witness.issuer, witness.gallery_id,
                                    witness.photo.image_id, True)
                    gallery_key = cache.gallery_key(witness.issuer, witness.gallery_id)
                if not self._thumbnail_batch_witness_current(witness, cancelled):
                    raise LinkingError('Gallery thumbnail proof is unavailable.')
                if check_active is not None:
                    check_active()
                stored = cache.write(key, item.data, item.mime, expected,
                                     gallery_key=gallery_key)
                if timings is not None:
                    timings.cache_stored = stored
                    timings.stored_bytes = len(item.data) if stored else 0
            except LinkingError:
                raise
            except (OSError, RuntimeError, ValueError):
                pass
            finally:
                if timings is not None:
                    timings.disk_write_ms += (time.perf_counter() - started) * 1000.0
                    if cache is not None and cache_observed is not None:
                        timings.observe_cache(cache, cache_observed)
        if check_active is not None:
            check_active()
        if not self._thumbnail_batch_witness_current(witness, cancelled):
            raise LinkingError('Gallery thumbnail proof is unavailable.')
        if timings is not None:
            timings.source = 'network'
        return ThumbnailPublication(
            witness, witness.photo, item.status,
            item.data if item.status == 'ok' else None,
            item.mime if item.status == 'ok' else None, item.etag, ram_eligible,
        )

    def supports_thumbnail_batch(self) -> bool:
        """Check whether this live session negotiated the optional batch route.

        Returns:
            bool: True only while discovery and this session permit batch reads.
        """
        with self._lock:
            return bool(not self._closed and self._token
                        and self._thumbnail_batch_supported
                        and (self._renew is not None
                             or time.monotonic() < self._expires_at))

    def thumbnail_batch_preferred(self, gallery_id: int, photo: LinkedPhoto) -> bool:
        """Select batching only for current provable thumbnail representations.

        Args:
            gallery_id (int): Physical gallery owning the direct photo.
            photo (LinkedPhoto): Current catalogue descriptor.

        Returns:
            bool: False for public unverified originals that retain the existing
                single-read confirmation/cache path; True for batch candidates.

        Raises:
            LinkingError: A batch-eligible descriptor has no current proof.
        """
        if (self.can_read_admin_media() and (
                photo.nsfw is True or (photo.cache_eligible is False
                                       and photo.preload_eligible is False))):
            return True
        witness = self.prepare_thumbnail_batch_item(gallery_id, photo)
        return witness.kind != 'public' or witness.public_row[0] is not None
