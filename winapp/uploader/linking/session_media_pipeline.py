# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/session_media_pipeline.py
# Module Type: Windows Client Session Behavior
# Purpose: Own thumbnail publication, media download and presentation reads.
# Responsibilities:
#   - Keep one cohesive group of linked-session operations together.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own thumbnail publication, media download and presentation reads."""

from typing import Callable
from ..gallery_media_cache import GalleryMediaCache
from ..models import JsonValue
from typing import Literal
from ..thumbnail_batch_protocol import PRODUCTION_MAX_ITEMS
from ..gallery_media_cache import PhotoCacheReceipt
from ..thumbnail_batch_protocol import ValidatedItem
from dataclasses import field
import hashlib
import logging
import math
import re
import threading
import time
from .core import (
    LINKING_CANCELLATION_POLL_SECONDS,
    LINKING_REQUEST_DEADLINE_SECONDS,
    LINKING_SESSION_ADMISSION_TIMEOUT_SECONDS,
    LINKING_SOCKET_TIMEOUT_SECONDS,
    LinkingError,
    LinkingGrantRejected,
    LinkingMediaThrottled,
    LinkingMediaUnavailable,
    LinkingResponseError,
    LinkingThumbnailBatchDeferred,
    LinkingThumbnailBatchUnsupported,
    LinkingThumbnailProofChanged,
    MediaPolicyFacts,
    MediaReadTimings,
    NATIVE_MEDIA_MAX_BYTES,
    NATIVE_MEDIA_TRACK_LIMIT,
    PRIVATE_PRELOAD_PROOF_ROW_LIMIT,
    THUMBNAIL_BATCH_MAX_ATTEMPTS,
    THUMBNAIL_BATCH_OPERATION_SECONDS,
    THUMBNAIL_BATCH_RETRY_SECONDS,
    UPLOAD_RETRY_AFTER_MAX_SECONDS,
    _NATIVE_AUDIO_TYPES,
    _NATIVE_INTERVAL_SECONDS,
    _NativeMediaKind,
    _ThumbnailCooldownSource,
    scope_allows_admin_media,
    scope_allows_presentation,
)
from .models import (
    LegacyThumbnailPublication,
    LinkedPhoto,
    LinkedPresentation,
    LinkedTrack,
    ThumbnailBatchStats,
    ThumbnailBatchWitness,
    ThumbnailPublication,
    _native_media_id,
    _native_media_title,
    _observe_active_thumbnails,
    _observe_thumbnail_mime,
)
from .transport import (
    _LinkingTransportControl,
    _run_linking_transport,
)
from .media import (
    _native_media_json,
    _native_thumbnail_batch_read_once,
)



class LinkingSessionMediaPipelineMixin:
    """Provide thumbnail publication, media download and presentation reads."""

    def thumbnail_batch_disabled_reason(self) -> Literal[
        'available', 'capability_missing', 'unsupported', 'wire_fault',
    ]:
        """Return a fixed optional-route availability reason for this session.

        Returns:
            Literal['available', 'capability_missing', 'unsupported', 'wire_fault']:
                Negotiated capability or the exact safe disabled category.
        """
        with self._lock:
            if self._thumbnail_batch_disabled_reason == 'unsupported':
                return 'unsupported'
            if self._thumbnail_batch_disabled_reason == 'wire_fault':
                return 'wire_fault'
            return ('available' if self._thumbnail_batch_supported
                    else 'capability_missing')

    def _thumbnail_batch_attempt(
        self, gallery_id: int, witnesses: tuple[ThumbnailBatchWitness, ...],
        publish: Callable[[ThumbnailPublication | LegacyThumbnailPublication,
                           MediaReadTimings, Callable[[], None]], None],
        cancel: threading.Event, deadline: float, timings: MediaReadTimings,
        stats: ThumbnailBatchStats, operation_started: float,
        frame_count: list[int], exceptional: list[ThumbnailBatchWitness],
        item_metrics: dict[int, MediaReadTimings],
        active_photos: Callable[[tuple[int, ...]], None] | None = None,
    ) -> None:
        """Run one homogeneous group on the existing serialized worker.

        Args:
            gallery_id (int): Exact physical gallery.
            witnesses (tuple[ThumbnailBatchWitness, ...]): Same-policy ordered rows.
            publish (Callable[[ThumbnailPublication | LegacyThumbnailPublication, MediaReadTimings, Callable[[], None]], None]):
                Worker-safe recipient and active-operation checker.
            cancel (threading.Event): Registered caller cancellation.
            deadline (float): Absolute whole-operation monotonic deadline.
            timings (MediaReadTimings): Physical request and gate observations.
            stats (ThumbnailBatchStats): Caller-owned bounded counters.
            operation_started (float): Monotonic start for first-frame timing.
            frame_count (list[int]): Mutable one-element received-frame count.
            exceptional (list[ThumbnailBatchWitness]): Nonbinary rows needing GET.
            item_metrics (dict[int, MediaReadTimings]): Candidate ID to the
                existing per-item cache lookup and publication observations.
            active_photos (Callable[[tuple[int, ...]], None] | None): Optional
                informational observer for this exact physical framed group.

        Returns:
            None: Validated ordinary frames are delivered during the request.

        Raises:
            LinkingError: Admission, authorization or transport was refused.
        """
        with self._lock:
            self._pending_gallery_cancels.add(cancel)
        admitted = False
        started = time.perf_counter()
        try:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise LinkingError('Thumbnail batch deadline expired.')
            admitted = self._request_gate.acquire(
                timeout=min(remaining, LINKING_SESSION_ADMISSION_TIMEOUT_SECONDS),
                cancelled=cancel,
            )
            timings.gate_wait_ms += (time.perf_counter() - started) * 1000.0
            if not admitted:
                raise LinkingError('Linking session is busy or cancelled.')
            renewal_started = time.perf_counter()
            try:
                self._renew_if_expired(cancel)
            finally:
                timings.renewal_ms += (time.perf_counter() - renewal_started) * 1000.0
            if any(not self._thumbnail_batch_witness_current(row, cancel)
                   for row in witnesses):
                raise LinkingThumbnailProofChanged(
                    'Gallery thumbnail proof is unavailable.',
                )
            with self._lock:
                if (self._closed or self._busy or not self._token or cancel.is_set()
                        or time.monotonic() >= self._expires_at
                        or not self._thumbnail_batch_supported):
                    raise LinkingError('Gallery media permission is unavailable.')
                self._busy = True
                self._active_route = 'app_linking_thumbnail_batch'
                token = self._token
            route_started = time.monotonic()
            prior_requests = timings.http_requests
            prior_ttfb = timings.ttfb_ms
            status: int | None = None
            reason = 'unknown'
            try:
                def request(control: _LinkingTransportControl) -> dict[str, JsonValue]:
                    """Stream one group on its admitted transport worker.

                    Args:
                        control (_LinkingTransportControl): Deadline and socket owner.

                    Returns:
                        dict[str, JsonValue]: Nonempty transport completion sentinel.
                    """
                    active_ids = (tuple(row.photo.image_id for row in witnesses)
                                  if active_photos is not None else ())

                    def receive(item: ValidatedItem) -> None:
                        """Validate one complete frame before delivering its result.

                        Args:
                            item (ValidatedItem): Ordered parser-validated frame.

                        Returns:
                            None: Publishes or defers the exact indexed candidate.
                        """
                        nonlocal active_ids
                        control.remaining()
                        frame_count[0] += 1
                        stats.wire_items += 1
                        if item.status != 'ok':
                            stats.wire_failures += 1
                            if item.status == 'unavailable':
                                stats.wire_unavailable += 1
                            elif item.status == 'oversize':
                                stats.wire_oversize += 1
                        if stats.first_frame_ms is None:
                            stats.first_frame_ms = (time.monotonic() - operation_started) * 1000.0
                        witness = witnesses[item.request_index]
                        item_timings = item_metrics[witness.photo.image_id]
                        try:
                            result = self.finish_thumbnail_batch_item(
                                witness, item, cancel, timings=item_timings,
                                check_active=lambda: control.remaining() and None,
                            )
                        except LinkingError:
                            control.remaining()
                            raise LinkingThumbnailProofChanged(
                                'Gallery thumbnail proof is unavailable.',
                            ) from None
                        if active_ids:
                            active_ids = tuple(identity for identity in active_ids
                                               if identity != witness.photo.image_id)
                        if item.status != 'ok':
                            _observe_active_thumbnails(active_photos, active_ids)
                            exceptional.append(witness)
                            return
                        publish_started = time.perf_counter()
                        try:
                            _observe_thumbnail_mime(item_timings, result.mime)
                            publish(result, item_timings,
                                    lambda: control.remaining() and None)
                            stats.returned += 1
                            stats.delivered += 1
                        finally:
                            stats.publish_ms += (time.perf_counter() - publish_started) * 1000.0

                    _observe_active_thumbnails(active_photos, active_ids)
                    try:
                        _native_thumbnail_batch_read_once(
                            self._issuer, token,
                            tuple(str(row.photo.image_id) for row in witnesses),
                            gallery_id, control, receive,
                            admin_preload=witnesses[0].kind == 'private', timings=timings,
                        )
                    finally:
                        _observe_active_thumbnails(active_photos, ())
                    return {'completed': True}

                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise LinkingError('Thumbnail batch deadline expired.')
                _run_linking_transport(
                    request, cancel, min(remaining, LINKING_REQUEST_DEADLINE_SECONDS),
                    LINKING_SOCKET_TIMEOUT_SECONDS, phase='media',
                    route='app_linking_thumbnail_batch', timings=timings,
                )
                status, reason = 200, 'ok'
                self._access_used(route_started)
            except LinkingResponseError as error:
                status, reason = error.status, error.reason
                raise
            except LinkingThumbnailProofChanged:
                reason = 'proof_changed'
                raise
            except LinkingMediaUnavailable:
                status, reason = 404, 'target_unavailable'
                raise
            except LinkingGrantRejected:
                reason = 'grant_rejected'
                with self._lock:
                    self._expires_at = time.monotonic()
                    self._private_preload_generation += 1
                    self._private_preload_proofs.clear()
                self._media_connection_pool.discard()
                raise
            finally:
                attempts = timings.http_requests - prior_requests
                if attempts:
                    stats.batch_sizes[len(witnesses)] = (
                        stats.batch_sizes.get(len(witnesses), 0) + attempts
                    )
                if status == 200:
                    self._server_timing_window.observe(
                        'thumbnail_batch', timings.server_timing,
                        timings.ttfb_ms - prior_ttfb, attempts,
                    )
                self._observe_route_performance(
                    'thumbnail_batch', status, reason,
                    (time.monotonic() - route_started) * 1000.0, attempts,
                )
                with self._lock:
                    self._busy = False
                    self._active_route = ''
                    if not self._closed:
                        self._cancelled.clear()
        finally:
            if admitted:
                self._request_gate.release()
            with self._lock:
                self._pending_gallery_cancels.discard(cancel)

    def _remember_thumbnail_batch_cooldown(
        self, delay_seconds: int | float, source: _ThumbnailCooldownSource,
        operation_finished: threading.Event | None,
    ) -> None:
        """Retain the cause and completion signal for the longest pause.

        Args:
            delay_seconds (int | float): Existing server hint or fixed transient
                cooldown duration in seconds.
            source (_ThumbnailCooldownSource): Closed cause category for the
                candidate deadline.
            operation_finished (threading.Event | None): Prior request's actual
                worker completion signal, when that request is observable.

        Returns:
            None: The longest current deadline and matching evidence remain.
        """
        if (type(delay_seconds) not in (int, float)
                or not math.isfinite(delay_seconds) or delay_seconds <= 0
                or source not in ('transient', 'server_retry_after', 'unknown')):
            return
        candidate_until = time.monotonic() + delay_seconds
        with self._lock:
            if candidate_until >= self._thumbnail_batch_cooldown_until:
                self._thumbnail_batch_cooldown_until = candidate_until
                self._thumbnail_batch_cooldown_source = source
                self._thumbnail_batch_cooldown_operation_finished = operation_finished

    def media_thumbnails(
        self, gallery_id: int, photos: tuple[LinkedPhoto, ...],
        publish: Callable[[ThumbnailPublication | LegacyThumbnailPublication,
                           MediaReadTimings, Callable[[], None]], None],
        cancelled: threading.Event | None = None, *,
        timings: MediaReadTimings | None = None,
        stats: ThumbnailBatchStats | None = None,
        retry_wait: Callable[[int], None] | None = None,
        active_photos: Callable[[tuple[int, ...]], None] | None = None,
    ) -> None:
        """Read a bounded current photo group with one serialized transport.

        Args:
            gallery_id (int): Exact physical gallery containing all candidates.
            photos (tuple[LinkedPhoto, ...]): Ordered unique current descriptors.
            publish (Callable[[ThumbnailPublication | LegacyThumbnailPublication, MediaReadTimings, Callable[[], None]], None]):
                Worker-safe result receiver with an active-operation checker.
            cancelled (threading.Event | None): Optional viewer cancellation.
            timings (MediaReadTimings | None): Optional aggregate physical metrics.
            stats (ThumbnailBatchStats | None): Optional fresh per-call counters;
                do not reuse an instance across operations.
            retry_wait (Callable[[int], None] | None): Optional worker-only
                observer for an already-owned automatic cooldown. Positive
                validated whole seconds announce its wait; zero means that
                notified cooldown elapsed and retry is proceeding. Observer
                failures are ignored and do not affect request policy.
            active_photos (Callable[[tuple[int, ...]], None] | None): Optional
                informational observer for an exact active framed group, one
                legacy GET, or a verified disk hit being handed to decode.
                Empty tuples clear activity. Observer failures are ignored.

        Returns:
            None: Authorized hits and complete network results were delivered.

        Raises:
            LinkingError: A target, proof, deadline or transport was refused.
        """
        if (not _native_media_id(gallery_id) or type(photos) is not tuple
                or not 1 <= len(photos) <= PRODUCTION_MAX_ITEMS
                or any(not isinstance(photo, LinkedPhoto)
                       or not _native_media_id(photo.image_id) for photo in photos)
                or len({photo.image_id for photo in photos}) != len(photos)):
            raise LinkingError('Invalid thumbnail batch request.')
        cancel = cancelled if cancelled is not None else threading.Event()
        operation_cancel = threading.Event()
        physical = timings if timings is not None else MediaReadTimings()
        counters = stats if stats is not None else ThumbnailBatchStats()
        counters.requested = len(photos)
        started = time.monotonic()
        deadline = started + THUMBNAIL_BATCH_OPERATION_SECONDS

        def check_active() -> None:
            """Refuse a cancelled, expired or closed group operation.

            Returns:
                None: The current session and whole-operation deadline remain live.
            """
            if (cancel.is_set() or operation_cancel.is_set()
                    or time.monotonic() >= deadline or not self.available()):
                raise LinkingError('Thumbnail batch operation is unavailable.')

        def deliver(
            result: ThumbnailPublication | LegacyThumbnailPublication,
            item_timings: MediaReadTimings,
        ) -> None:
            """Deliver one result after the caller-owned operation fence.

            Args:
                result (ThumbnailPublication | LegacyThumbnailPublication):
                    Independently authorized or transient result.
                item_timings (MediaReadTimings): Per-item disk and cache metrics.

            Returns:
                None: One callback completes within the operation deadline.
            """
            check_active()
            if not self.thumbnail_publication_current(result, cancel):
                raise LinkingThumbnailProofChanged('Gallery thumbnail proof is unavailable.')
            began = time.perf_counter()
            try:
                publish(result, item_timings, check_active)
                counters.delivered += 1
                if result.body is not None:
                    counters.returned += 1
            finally:
                counters.publish_ms += (time.perf_counter() - began) * 1000.0

        def wait_cooldown(*, wait_for_retry: bool = False) -> None:
            """Honor the existing cooldown without owning either transport gate.

            Args:
                wait_for_retry (bool): Wait interruptibly for this operation's
                    one authorized automatic retry; other calls defer.

            Returns:
                None: The cooldown elapsed within this operation's deadline.

            Raises:
                LinkingError: The operation was cancelled or must be deferred.
            """
            notified_wait = False

            def notify_wait(seconds: int) -> None:
                """Notify the optional observer without owning retry policy.

                Args:
                    seconds (int): Positive validated delay, or zero on normal
                        completion of an already-notified automatic wait.
                Returns:
                    None: Observer exceptions cannot alter transport behavior.
                """
                if retry_wait is None:
                    return
                try:
                    retry_wait(seconds)
                except Exception:
                    pass

            while True:
                check_active()
                with self._lock:
                    until = self._thumbnail_batch_cooldown_until
                    source = self._thumbnail_batch_cooldown_source
                    operation_finished = (
                        self._thumbnail_batch_cooldown_operation_finished
                    )
                delay = until - time.monotonic()
                if delay <= 0:
                    if notified_wait:
                        notify_wait(0)
                    return
                if not wait_for_retry or delay >= deadline - time.monotonic():
                    raise LinkingThumbnailBatchDeferred(
                        min(UPLOAD_RETRY_AFTER_MAX_SECONDS, max(1, math.ceil(delay))),
                        cooldown_source=source,
                        operation_finished=operation_finished,
                    )
                if not notified_wait:
                    notify_wait(max(1, math.ceil(delay)))
                    notified_wait = True
                if cancel.wait(min(delay, LINKING_CANCELLATION_POLL_SECONDS)):
                    raise LinkingError('Thumbnail batch operation was cancelled.')

        def legacy(
            photo: LinkedPhoto, witness: ThumbnailBatchWitness | None,
            item_timings: MediaReadTimings,
        ) -> None:
            """Read one original media route without granting cache permission.

            Args:
                photo (LinkedPhoto): Exact captured current descriptor.
                witness (ThumbnailBatchWitness | None): Optional established proof.
                item_timings (MediaReadTimings): Existing item lookup observations.

            Returns:
                None: One authorized GET body is delivered transiently.
            """
            check_active()
            wait_cooldown()
            if witness is not None and not self._thumbnail_batch_witness_current(witness, cancel):
                raise LinkingThumbnailProofChanged('Gallery thumbnail proof is unavailable.')
            generation_out: list[int] = []
            try:
                body, mime, etag, _cacheable = self._media_read(
                    gallery_id, 'thumbnail', photo.image_id, cancel,
                    admin_preload=bool(witness is not None and witness.kind == 'private'),
                    timings=physical, proof_generation_out=generation_out,
                    operation_deadline=deadline,
                    **({'active_photos': active_photos} if active_photos is not None else {}),
                )
            except LinkingMediaThrottled as error:
                if error.retry_after_seconds is not None:
                    self._remember_thumbnail_batch_cooldown(
                        error.retry_after_seconds, 'server_retry_after',
                        error.operation_finished,
                    )
                raise
            except LinkingMediaUnavailable:
                check_active()
                if len(generation_out) != 1:
                    raise LinkingError('Gallery thumbnail proof is unavailable.')
                result = LegacyThumbnailPublication(
                    self, self._issuer, gallery_id, photo, 'unavailable',
                    None, None, None, witness, generation_out[0],
                )
                deliver(result, item_timings)
                return
            check_active()
            if body is None:
                raise LinkingError('Gallery thumbnail response is unavailable.')
            if len(generation_out) != 1:
                raise LinkingError('Gallery thumbnail proof is unavailable.')
            if witness is not None:
                expected = (witness.public_row[0] if witness.public_row is not None
                            else witness.private_identity[1]
                            if witness.private_identity is not None else None)
                if expected is not None and etag != expected:
                    self._invalidate_thumbnail_batch_witness(witness)
                    raise LinkingThumbnailProofChanged('Gallery thumbnail descriptor requires refresh.')
            result = LegacyThumbnailPublication(
                self, self._issuer, gallery_id, photo, 'ok', body, mime, etag,
                witness, generation_out[0],
            )
            item_timings.source = 'network'
            _observe_thumbnail_mime(item_timings, mime)
            deliver(result, item_timings)

        with self._lock:
            self._pending_gallery_cancels.add(operation_cancel)
        deferred = False
        try:
            candidates: list[tuple[LinkedPhoto, ThumbnailBatchWitness | None]] = []
            item_metrics: dict[int, MediaReadTimings] = {}
            for photo in photos:
                check_active()
                if (self.can_read_admin_media()
                        and (photo.nsfw is True or (
                            photo.cache_eligible is False
                            and photo.preload_eligible is False))):
                    candidates.append((photo, None))
                    item_metrics[photo.image_id] = MediaReadTimings()
                    continue
                try:
                    witness = self.prepare_thumbnail_batch_item(gallery_id, photo, cancel)
                except LinkingError:
                    check_active()
                    raise LinkingThumbnailProofChanged(
                        'Gallery thumbnail proof is unavailable.',
                    ) from None
                item_timings = MediaReadTimings()
                item_metrics[photo.image_id] = item_timings
                cached = self.cached_media_photo(
                    gallery_id, photo, cancelled=cancel, timings=item_timings,
                )
                if cached is None:
                    candidates.append((photo, witness))
                    continue
                check_active()
                body, mime = cached
                expected = (witness.public_row[0] if witness.public_row is not None
                            else witness.private_identity[1]
                            if witness.private_identity is not None else None)
                result = ThumbnailPublication(
                    witness, photo, 'ok', body, mime, expected, expected is not None,
                )
                _observe_thumbnail_mime(item_timings, mime)
                _observe_active_thumbnails(active_photos, (photo.image_id,))
                try:
                    deliver(result, item_timings)
                finally:
                    _observe_active_thumbnails(active_photos, ())
                counters.cache_hits += 1
            while candidates:
                check_active()
                wait_cooldown()
                photo, first_witness = candidates[0]
                if first_witness is None:
                    candidates.pop(0)
                    counters.fallback_restricted += 1
                    legacy(photo, None, item_metrics[photo.image_id])
                    continue
                kind = first_witness.kind
                group_list: list[ThumbnailBatchWitness] = []
                while (candidates and candidates[0][1] is not None
                       and candidates[0][1].kind == kind):
                    candidate = candidates.pop(0)[1]
                    if candidate is not None:
                        group_list.append(candidate)
                group = tuple(group_list)
                if not self.supports_thumbnail_batch():
                    for witness in group:
                        with self._lock:
                            reason = self._thumbnail_batch_disabled_reason
                        if reason == 'wire_fault':
                            counters.fallback_wire_disabled += 1
                        elif reason == 'unsupported':
                            counters.fallback_unsupported += 1
                        else:
                            counters.fallback_capability_missing += 1
                        legacy(witness.photo, witness,
                               item_metrics[witness.photo.image_id])
                    continue
                frames = [0]
                exceptional: list[ThumbnailBatchWitness] = []
                for attempt in range(THUMBNAIL_BATCH_MAX_ATTEMPTS):
                    try:
                        check_active()
                        self._thumbnail_batch_attempt(
                            gallery_id, group, publish, cancel, deadline, physical,
                            counters, started, frames, exceptional, item_metrics,
                            active_photos=active_photos,
                        )
                        break
                    except LinkingThumbnailBatchUnsupported:
                        with self._lock:
                            self._thumbnail_batch_supported = False
                            self._thumbnail_batch_disabled_reason = 'unsupported'
                        for witness in group:
                            counters.fallback_unsupported += 1
                            legacy(witness.photo, witness,
                                   item_metrics[witness.photo.image_id])
                        break
                    except LinkingMediaThrottled as error:
                        if error.retry_after_seconds is not None:
                            self._remember_thumbnail_batch_cooldown(
                                error.retry_after_seconds, 'server_retry_after',
                                error.operation_finished,
                            )
                        raise
                    except LinkingResponseError as error:
                        if error.status == 200:
                            with self._lock:
                                self._thumbnail_batch_supported = False
                                self._thumbnail_batch_disabled_reason = 'wire_fault'
                            raise
                        transient = (error.status in (502, 503) or (
                            error.status is None and error.reason in (
                                'timeout', 'connect_failure', 'dns_failure',
                                'transport_failure',
                            )))
                        if transient:
                            self._remember_thumbnail_batch_cooldown(
                                THUMBNAIL_BATCH_RETRY_SECONDS, 'transient',
                                error.operation_finished,
                            )
                        if (not transient or frames[0] or attempt or cancel.is_set()
                                or operation_cancel.is_set()):
                            raise
                        if deadline - time.monotonic() <= THUMBNAIL_BATCH_RETRY_SECONDS:
                            raise
                        wait_cooldown(wait_for_retry=True)
                for witness in exceptional:
                    counters.fallback_exceptional += 1
                    legacy(witness.photo, witness,
                           item_metrics[witness.photo.image_id])
        except LinkingThumbnailBatchDeferred:
            deferred = True
            counters.failed = 0
            raise
        except Exception:
            counters.operation_failures += 1
            raise
        finally:
            if not deferred:
                counters.failed = counters.requested - counters.returned
            with self._lock:
                self._pending_gallery_cancels.discard(operation_cancel)

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
        if (thumbnail and (not admin_media or photo.cache_eligible is True)
                and not self._public_thumbnail_descriptor_current(
                    gallery_id, photo.image_id, photo.thumbnail_etag,
                    photo.thumbnail_verified_at,
                )):
            raise LinkingError('Gallery thumbnail descriptor is no longer current.')
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
                gallery_id, photo.image_id, cache, entry, thumbnail,
                photo.thumbnail_etag, photo.thumbnail_verified_at, cancelled,
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
        if (thumbnail and (not admin_media or cache_eligible is True)
                and not self._public_thumbnail_descriptor_current(
                    gallery_id, image_id, thumbnail_etag,
                    thumbnail_verified_at,
                )):
            raise LinkingError('Gallery thumbnail descriptor is no longer current.')
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
                gallery_id, image_id, cache, entry, thumbnail,
                thumbnail_etag, thumbnail_verified_at, cancelled,
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
            logging.getLogger('uploader.app_linking').info(
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
        if (thumbnail and (not admin_media or cache_eligible is True)
                and not self._public_thumbnail_descriptor_current(
                    gallery_id, image_id, thumbnail_etag,
                    thumbnail_verified_at,
                )):
            raise LinkingError('Gallery thumbnail descriptor is no longer current.')
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
        if (not private_mode and thumbnail and cacheable
                and thumbnail_etag is None
                and thumbnail_verified_at is not None and etag is not None):
            with self._lock:
                latest = self._public_media_descriptors.get(gallery_id)
                if (not self._closed and (
                    latest is None or latest.get(image_id)
                    == (None, thumbnail_verified_at)
                )):
                    self._public_unverified_confirmations[
                        (gallery_id, image_id)
                    ] = (thumbnail_verified_at, etag)
                    self._public_unverified_confirmations.move_to_end(
                        (gallery_id, image_id),
                    )
                    while (len(self._public_unverified_confirmations)
                           > PRIVATE_PRELOAD_PROOF_ROW_LIMIT):
                        self._public_unverified_confirmations.popitem(
                            last=False,
                        )
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
