# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_preload.py
# Module Type: Windows Client Background Worker
# Purpose: Preload and verify eligible photo renditions without blocking Tk.
# Responsibilities:
#   - List one selected gallery with a fixed photo-count work boundary.
#   - Reuse the linking session's verified cache and retention policy.
#   - Publish immutable bounded progress without UI calls or raw errors.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Bounded background worker for retaining one linked gallery locally."""

import logging
import json
import threading
import time
from dataclasses import dataclass
from typing import Callable, Literal

from .app_linking import (
    MEDIA_PRELOAD_RETRY_MAX_ATTEMPTS, LinkedPhoto, LinkingError,
    LinkingMediaThrottled, LinkingSession, MediaReadTimings,
)
from .gallery_media_cache import PhotoCacheReceipt
from .diagnostics import format_safe_photos_failure

_PreloadDiagnosticStage = Literal[
    'listing', 'cache', 'fetch', 'verify', 'storage', 'setup',
]

# Purpose: Bound a complete direct-photo preload and its final verification.
# Type: int. Units: direct photo descriptors. Scope: one worker run.
# Consumers: GalleryPreloadWorker._list_photos. Rationale: 100,000 admits
# large galleries while bounding descriptor memory and two-rendition work.
PRELOAD_MAX_PHOTOS = 100_000
# Purpose: Space cancelable waits while showing server-directed delay progress.
# Type: float. Units: seconds. Scope: one Retry-After countdown.
# Consumers: GalleryPreloadWorker._wait_before_retry. Rationale: One-second
# ticks keep cancellation responsive and countdown text easy to read.
PRELOAD_RETRY_POLL_SECONDS = 1.0
# Purpose: Keep thumbnail preparation responsive while foreground work waits.
# Type: float. Units: seconds. Scope: one foreground-yield poll.
# Consumers: GalleryPreloadWorker._wait_for_foreground. Rationale: 0.1 seconds
# promptly admits viewer work while bounding idle wakeups during a long fetch.
PRELOAD_FOREGROUND_POLL_SECONDS = 0.1


@dataclass(frozen=True)
class PreloadProgress:
    """Describe one immutable, safe snapshot of a gallery preload run.

    Attributes:
        phase (str): Listing, transfer, final check or terminal phase.
        gallery_id (int): Exact physical gallery owned by the worker.
        total (int | None): Direct-photo count after complete listing.
        completed (int): Processed photos in the current operation.
        cached (int): Photos with every scoped rendition confirmed stored during
            transfer, reconciled against disk at the final verification.
        skipped (int): Photos missing a scoped rendition or ineligible.
        prepared_bytes (int): Sum of response bodies returned for cache misses.
            This is not a network-wire or complete local-footprint measurement.
        pinned (bool): Current or requested retained-gallery preference.
        reason (str): Fixed safe reason code, never a raw exception.
        cache_state (str): Final cloud, partial or local result, or unknown.
        retry_after_seconds (int | None): Remaining safe server delay while waiting.
        thumbnails_only (bool): Whether this run covers thumbnail renditions only.
        current_hits (int): Current authorized disk hits accepted during this run.
        stored (int): Thumbnail publications confirmed by the cache owner.
        stored_bytes (int): Body bytes in those confirmed publications only.
        wait_reason (str): Fixed foreground reason without user identities.
    """

    phase: str
    gallery_id: int
    total: int | None
    completed: int
    cached: int
    skipped: int
    prepared_bytes: int
    pinned: bool
    reason: str
    cache_state: str = 'unknown'
    retry_after_seconds: int | None = None
    thumbnails_only: bool = False
    current_hits: int = 0
    stored: int = 0
    stored_bytes: int = 0
    wait_reason: str = ''


class GalleryPreloadWorker(threading.Thread):
    """List and verify a gallery's eligible photo cache off the Tk thread."""

    def __init__(
        self, session: LinkingSession, gallery_id: int, *,
        pinned: bool | None = None, download: bool = True,
        diagnostic_callback: Callable[[str], None] | None = None,
        thumbnails_only: bool = False,
        yield_to_foreground: Callable[[], bool] | None = None,
        foreground_reason: Callable[[], str] | None = None,
    ) -> None:
        """Prepare one background gallery retention operation.

        Args:
            session (LinkingSession): Exact current media-readable link owner.
            gallery_id (int): Positive physical gallery ID to preload.
            pinned (bool | None): Pin state to set, or None to preserve current.
            download (bool): Whether cache misses may use the media transport.
            diagnostic_callback (Callable[[str], None] | None): Optional owner
                retaining safe failure facts in copied Photos diagnostics.
            thumbnails_only (bool): Limit work and completion to thumbnails.
            yield_to_foreground (Callable[[], bool] | None): Optional predicate
                that is true while foreground media work is waiting.
            foreground_reason (Callable[[], str] | None): Thread-safe fixed
                reason for urgent work, separate from speculative disk queues.

        Returns:
            None: Creates a daemon worker with a private cancellation event.
        """
        super().__init__(name='GalleryPreloadWorker', daemon=True)
        self._session = session
        self._gallery_id = gallery_id
        self._requested_pin = pinned
        self._download = download
        self._diagnostic_callback = diagnostic_callback
        self._thumbnails_only = thumbnails_only
        self._yield_to_foreground = yield_to_foreground
        self._foreground_reason = foreground_reason
        self._metadata_timings = MediaReadTimings()
        self._thumbnail_timings = MediaReadTimings()
        self._phase_ms: dict[str, float] = {
            'listing_ms': 0.0, 'transfer_ms': 0.0, 'verification_ms': 0.0,
            'foreground_wait_ms': 0.0, 'retry_wait_ms': 0.0,
        }
        self._metrics_started = time.perf_counter()
        self._cache_hits = 0
        self._cache_misses = 0
        self._failure_summary: str | None = None
        self._diagnostic_stage: _PreloadDiagnosticStage = 'setup'
        self._outcome_logged = False
        self._cancelled = threading.Event()
        self._snapshot_lock = threading.Lock()
        self._snapshot = PreloadProgress(
            'listing', gallery_id, None, 0, 0, 0, 0,
            bool(pinned), '',
            thumbnails_only=thumbnails_only,
        )

    def stop(self) -> None:
        """Cancel the current page or rendition operation cooperatively.

        Returns:
            None: Sets the worker's private cancellation event.
        """
        self._cancelled.set()

    def snapshot(self) -> PreloadProgress:
        """Return the latest immutable progress without waiting on I/O.

        Returns:
            PreloadProgress: Current safe state with no raw paths or errors.
        """
        with self._snapshot_lock:
            return self._snapshot

    def run(self) -> None:
        """Run listing, cache preparation and final truth verification.

        Returns:
            None: Publishes a terminal immutable phase and safe reason code.
        """
        try:
            self._run_owned()
        except LinkingMediaThrottled as error:
            self._record_failure(error)
            self._finish('partial', 'throttled')
        except LinkingError as error:
            self._record_failure(error)
            reason = (
                'session' if not self._session.can_read_media()
                else 'unavailable'
            )
            self._finish('partial' if self.snapshot().completed else 'error', reason)
        except (OSError, RuntimeError, ValueError) as error:
            self._record_failure(error)
            self._finish('partial' if self.snapshot().completed else 'error', 'storage')
        except Exception as error:  # noqa: BLE001
            self._record_failure(error)
            self._finish(
                'partial' if self.snapshot().completed else 'error',
                'unavailable',
            )
        finally:
            logging.getLogger(__name__).info(
                'Photos preload measurements %s',
                json.dumps(self.metrics_snapshot(), sort_keys=True),
            )

    def metrics_snapshot(self) -> dict[str, int | float]:
        """Return separate cumulative phase/transport observations for this run.

        Returns:
            dict[str, int | float]: Fixed numeric phase durations, hit/miss
                counts and metadata/thumbnail request/connection/byte counters.
                Nested transport durations overlap and are not wall-time sums.
        """
        result: dict[str, int | float] = dict(self._phase_ms)
        result['wall_ms'] = (time.perf_counter() - self._metrics_started) * 1000.0
        result['hits'] = self._cache_hits
        result['misses'] = self._cache_misses
        for prefix, record in (
            ('metadata', self._metadata_timings),
            ('thumbnail', self._thumbnail_timings),
        ):
            for field, value in vars(record).items():
                if type(value) in (int, float):
                    result[prefix + '_' + field] = value
        return result

    def _run_owned(self) -> None:
        """Perform the worker's ordered pin, listing, transfer and verify phases.

        Returns:
            None: Explicit downloads reuse only current authorized renditions;
                stale files use normal server revalidation. During transfer,
                stored photos require both confirmed cached renditions. Final
                coverage reconciles those observations against verified disk data.
        """
        if (type(self._gallery_id) is not int or self._gallery_id <= 0
                or not self._session.can_read_media()):
            self._finish('error', 'session')
            return
        self._diagnostic_stage = 'storage'
        if (self._download and self._session.can_read_admin_media()
                and self._session.supports_admin_preload()
                and not self._session.set_gallery_preload_intent(
                    self._gallery_id, True,
                )):
            self._finish('error', 'storage')
            return
        self._diagnostic_stage = 'storage'
        pinned = self._session.gallery_pinned(self._gallery_id)
        if self._requested_pin is not None:
            pinned = self._requested_pin
            if not self._session.set_gallery_pinned(self._gallery_id, pinned):
                self._finish('error', 'storage', pinned=pinned)
                return
            if not pinned and not self._download:
                self._finish('complete', '', pinned=False)
                return
        self._publish('listing', pinned=pinned)
        if self._cancelled.is_set():
            self._finish('cancelled', '', pinned=pinned)
            return
        self._diagnostic_stage = 'listing'
        listing_started = time.perf_counter()
        try:
            photos = self._list_photos()
        finally:
            self._phase_ms['listing_ms'] += (
                time.perf_counter() - listing_started
            ) * 1000.0
        if photos is None:
            return
        if self._thumbnails_only:
            self._run_thumbnail_photos(photos, pinned)
            return
        total = len(photos)
        self._publish(
            'checking',
            total=total, pinned=pinned,
        )
        prepared_bytes = 0
        retained: dict[tuple[int, bool], bool] = {}
        complete_before_download: set[int] = set()
        confirmed_cached: set[int] = set()
        self._diagnostic_stage = 'cache'
        # Existing bytes do not make an explicit full preload current. In a
        # download run, an old full response must reach normal ETag revalidation
        # instead of being skipped merely because its hash-valid file exists.
        for photo in photos:
            if self._cancelled.is_set():
                self._finish('cancelled', '', total=total, pinned=pinned)
                return
            if not self._wait_for_foreground(
                total, len(complete_before_download), pinned, prepared_bytes,
            ):
                return
            if not self._eligible(photo):
                complete_before_download.add(photo.image_id)
                continue
            for thumbnail in ((True,) if self._thumbnails_only else (True, False)):
                if self._cancelled.is_set():
                    self._finish('cancelled', '', total=total, pinned=pinned)
                    return
                retained[(photo.image_id, thumbnail)] = (
                    self._session.cached_media_photo(
                        self._gallery_id, photo, thumbnail=thumbnail,
                        cancelled=self._cancelled,
                    ) is not None if self._download else
                    self._session.prepare_cached_photo(
                        self._gallery_id, photo, thumbnail=thumbnail,
                    )
                )
            if (retained[(photo.image_id, True)] and (
                    self._thumbnails_only or retained[(photo.image_id, False)]
            )):
                complete_before_download.add(photo.image_id)
                confirmed_cached.add(photo.image_id)
            self._publish(
                'checking', total=total,
                completed=len(complete_before_download),
                cached=len(confirmed_cached),
                pinned=pinned,
            )
        completed = len(complete_before_download)
        transfer_reason = ''
        if self._download:
            self._publish(
                'preloading', total=total, completed=completed,
                cached=len(confirmed_cached),
                pinned=pinned,
            )
            for photo in photos:
                if self._cancelled.is_set():
                    self._finish(
                        'cancelled', '', total=total, pinned=pinned,
                        completed=completed, prepared_bytes=prepared_bytes,
                    )
                    return
                if photo.image_id in complete_before_download:
                    continue
                if not self._eligible(photo):
                    completed += 1
                    self._publish(
                        'preloading', total=total, completed=completed,
                        pinned=pinned, prepared_bytes=prepared_bytes,
                    )
                    continue
                for thumbnail in (
                    (True,) if self._thumbnails_only else (True, False)
                ):
                    if self._cancelled.is_set():
                        self._finish(
                            'cancelled', '', total=total, pinned=pinned,
                            completed=completed,
                            prepared_bytes=prepared_bytes,
                        )
                        return
                    if retained.get((photo.image_id, thumbnail), False):
                        continue
                    missing_before_fetch = not retained.get(
                        (photo.image_id, thumbnail), False,
                    )
                    self._diagnostic_stage = 'fetch'
                    for attempt in range(MEDIA_PRELOAD_RETRY_MAX_ATTEMPTS):
                        if not self._wait_for_foreground(
                            total, completed, pinned, prepared_bytes,
                        ):
                            return
                        try:
                            timings = MediaReadTimings()
                            body, _mime = self._session.media_photo(
                                self._gallery_id, photo.image_id, thumbnail,
                                self._cancelled,
                                cache_eligible=photo.cache_eligible,
                                thumbnail_etag=photo.thumbnail_etag,
                                thumbnail_verified_at=photo.thumbnail_verified_at,
                                preload_eligible=photo.preload_eligible,
                                preload_thumbnail_etag=photo.preload_thumbnail_etag,
                                preload_verified_at=photo.preload_verified_at,
                                timings=timings,
                            )
                            retained[(photo.image_id, thumbnail)] = (
                                timings.source == 'disk' or timings.cache_stored
                            )
                            if missing_before_fetch:
                                prepared_bytes += len(body)
                            break
                        except LinkingMediaThrottled as error:
                            self._record_failure(error)
                            delay = error.retry_after_seconds
                            if (delay is None
                                    or attempt + 1 >= MEDIA_PRELOAD_RETRY_MAX_ATTEMPTS):
                                transfer_reason = 'throttled'
                                break
                            if not self._wait_before_retry(delay, total, completed):
                                self._finish(
                                    'cancelled', '', total=total, pinned=pinned,
                                    completed=completed,
                                    prepared_bytes=prepared_bytes,
                                )
                                return
                        except LinkingError as error:
                            self._record_failure(error)
                            transfer_reason = (
                                'session' if not self._session.can_read_media()
                                else 'unavailable'
                            )
                            break
                    if transfer_reason:
                        break
                if not transfer_reason:
                    completed += 1
                    if (retained.get((photo.image_id, True), False)
                            and retained.get((photo.image_id, False), False)):
                        confirmed_cached.add(photo.image_id)
                self._publish(
                    'preloading', total=total, completed=completed,
                    cached=len(confirmed_cached),
                    pinned=pinned, prepared_bytes=prepared_bytes,
                )
                if transfer_reason:
                    break
        if self._cancelled.is_set():
            self._finish(
                'cancelled', '', total=total, pinned=pinned,
                completed=completed, prepared_bytes=prepared_bytes,
            )
            return
        self._publish(
            'checking', total=total, pinned=pinned,
            completed=completed, prepared_bytes=prepared_bytes,
        )
        self._diagnostic_stage = 'verify'
        verified, skipped, any_cached = self._verify_photos(
            photos, thumbnails_only=self._thumbnails_only,
        )
        if self._cancelled.is_set():
            self._finish(
                'cancelled', '', total=total, pinned=pinned,
                completed=completed, cached=verified, skipped=skipped,
                prepared_bytes=prepared_bytes,
            )
            return
        complete = verified == total and skipped == 0
        state = (
            'partial' if self._thumbnails_only and complete and total
            else 'local' if complete and total
            else 'partial' if any_cached else 'cloud'
        )
        ineligible = sum(not self._eligible(photo) for photo in photos)
        reason = transfer_reason or (
            '' if complete else 'no_store'
            if verified + ineligible == total else 'unavailable'
        )
        terminal_phase = (
            'complete' if complete and not transfer_reason
            else 'partial' if any_cached else 'error'
        )
        self._finish(
            terminal_phase,
            reason, total=total,
            completed=total, cached=verified, skipped=skipped,
            prepared_bytes=prepared_bytes, pinned=pinned, cache_state=state,
        )

    def _list_photos(self) -> list[LinkedPhoto] | None:
        """Read every direct-photo page while enforcing cursor and size bounds.

        Returns:
            list[LinkedPhoto] | None: Ordered descriptors, or None after a
                terminal cancellation, oversized result or malformed response.
        """
        photos: list[LinkedPhoto] = []
        after_id = None
        while True:
            if self._cancelled.is_set():
                self._finish('cancelled', '')
                return None
            if not self._wait_for_foreground(
                None, len(photos), self.snapshot().pinned, 0,
            ):
                return None
            self._publish('listing', completed=len(photos))
            options = (
                {'timings': self._metadata_timings}
                if self._thumbnails_only else {}
            )
            page = self._session.media_page(
                self._gallery_id, after_id, self._cancelled, **options,
            )
            if page.gallery_id != self._gallery_id:
                raise LinkingError('Gallery photo page was not accepted.')
            previous = photos[-1].image_id if photos else 0
            for photo in page.photos:
                if photo.image_id <= previous:
                    raise LinkingError('Gallery photo page was not accepted.')
                previous = photo.image_id
            photos.extend(page.photos)
            if len(photos) > PRELOAD_MAX_PHOTOS:
                self._finish('partial', 'too_large', total=len(photos))
                return None
            if page.next_after is None:
                return photos
            if not page.photos or page.next_after != photos[-1].image_id:
                raise LinkingError('Gallery photo cursor was not accepted.')
            after_id = page.next_after
            self._publish('listing', completed=len(photos))

    def _eligible(self, photo: LinkedPhoto) -> bool:
        """Apply current public or explicit Admin-preload cache admission.

        Args:
            photo (LinkedPhoto): Current descriptor from the verified page.

        Returns:
            bool: True only when its independent cache eligibility permits storage.
        """
        if photo.cache_eligible is True:
            return True
        if self._session.can_read_admin_media():
            return self._private_candidate(photo)
        return photo.cache_eligible is not False

    def _private_candidate(self, photo: LinkedPhoto) -> bool:
        """Check eligibility for automatic thumbnails or saved full preload.

        Args:
            photo (LinkedPhoto): Current server-authorized Admin photo.

        Returns:
            bool: True for an eligible non-NSFW thumbnail or a photo covered by
                saved full-media intent; rendition checks still own persistence.
        """
        return (photo.cache_eligible is not True
                and photo.preload_eligible is True
                and self._session.can_read_admin_media()
                and self._session.supports_admin_preload()
                and (photo.nsfw is False
                     or self._session.gallery_preload_intent(self._gallery_id)))

    def _run_thumbnail_photos(
        self, photos: list[LinkedPhoto], pinned: bool,
    ) -> None:
        """Process each thumbnail once and verify its exact publication witness.

        Args:
            photos (list[LinkedPhoto]): Complete ordered direct-photo listing.
            pinned (bool): Current retained-gallery preference.
        Returns:
            None: Publishes confirmed writes and final identity-verified coverage;
                downloaded bytes alone never count as stored thumbnails.
        """
        total = len(photos)
        receipts: dict[int, PhotoCacheReceipt] = {}
        completed = stored = stored_bytes = prepared_bytes = hits = 0
        reason = ''
        self._publish('preloading', total=total, completed=0, pinned=pinned)
        started = time.perf_counter()
        try:
            for photo in photos:
                if not self._wait_for_foreground(
                    total, completed, pinned, prepared_bytes,
                ):
                    return
                if not self._eligible(photo):
                    completed += 1
                    self._publish('preloading', completed=completed)
                    continue
                self._diagnostic_stage = 'fetch'
                witnesses: list[PhotoCacheReceipt] = []
                for attempt in range(MEDIA_PRELOAD_RETRY_MAX_ATTEMPTS):
                    if not self._wait_for_foreground(
                        total, completed, pinned, prepared_bytes,
                    ):
                        return
                    if self._cancelled.is_set():
                        self._finish('cancelled', '', completed=completed)
                        return
                    self._publish('preloading', wait_reason='')
                    try:
                        if not self._download:
                            self._diagnostic_stage = 'cache'
                            self._session.prepare_cached_photo(
                                self._gallery_id, photo, thumbnail=True,
                            )
                            break
                        body, _mime = self._session.media_photo(
                            self._gallery_id, photo.image_id, True,
                            self._cancelled,
                            cache_eligible=photo.cache_eligible,
                            thumbnail_etag=photo.thumbnail_etag,
                            thumbnail_verified_at=photo.thumbnail_verified_at,
                            preload_eligible=photo.preload_eligible,
                            preload_thumbnail_etag=photo.preload_thumbnail_etag,
                            preload_verified_at=photo.preload_verified_at,
                            timings=self._thumbnail_timings,
                            cache_receipts=witnesses,
                        )
                        if self._thumbnail_timings.source == 'disk':
                            hits += 1
                            self._cache_hits += 1
                        else:
                            prepared_bytes += len(body)
                            self._cache_misses += 1
                        if self._thumbnail_timings.cache_stored:
                            stored += 1
                            stored_bytes += self._thumbnail_timings.stored_bytes
                        if witnesses:
                            receipts[photo.image_id] = witnesses[-1]
                        break
                    except LinkingMediaThrottled as error:
                        self._record_failure(error)
                        delay = error.retry_after_seconds
                        if (delay is None
                                or attempt + 1 >= MEDIA_PRELOAD_RETRY_MAX_ATTEMPTS):
                            reason = 'throttled'
                            break
                        if not self._wait_before_retry(delay, total, completed):
                            self._finish('cancelled', '', completed=completed)
                            return
                    except LinkingError as error:
                        self._record_failure(error)
                        reason = (
                            'session' if not self._session.can_read_media()
                            else 'unavailable'
                        )
                        break
                if reason:
                    break
                completed += 1
                self._publish(
                    'preloading', completed=completed, current_hits=hits,
                    stored=stored, stored_bytes=stored_bytes,
                    prepared_bytes=prepared_bytes,
                )
        finally:
            self._phase_ms['transfer_ms'] += (
                time.perf_counter() - started
            ) * 1000.0
        if self._cancelled.is_set():
            self._finish('cancelled', '', completed=completed)
            return
        self._diagnostic_stage = 'verify'
        self._publish('verifying', completed=completed)
        verified = skipped = 0
        started = time.perf_counter()
        try:
            for photo in photos:
                if self._cancelled.is_set():
                    self._finish('cancelled', '', cached=verified, skipped=skipped)
                    return
                options = (
                    {'receipt': receipts[photo.image_id]}
                    if photo.image_id in receipts else {}
                )
                if (self._eligible(photo)
                        and (not self._download or bool(options))
                        and self._session.prepare_cached_photo(
                            self._gallery_id, photo, thumbnail=True, **options,
                        )):
                    verified += 1
                else:
                    skipped += 1
                self._publish('verifying', cached=verified, skipped=skipped)
        finally:
            self._phase_ms['verification_ms'] += (
                time.perf_counter() - started
            ) * 1000.0
        complete = verified == total and skipped == 0 and not reason
        ineligible = sum(not self._eligible(photo) for photo in photos)
        self._finish(
            'complete' if complete else 'partial' if verified else 'error',
            reason or ('' if complete else 'no_store'
                       if verified + ineligible == total else 'unavailable'),
            total=total, completed=completed, cached=verified, skipped=skipped,
            cache_state='partial' if verified else 'cloud',
        )

    def _record_failure(self, error: Exception) -> None:
        """Log and retain each distinct consecutive preload failure summary.

        Args:
            error (Exception): Caught operation error whose raw message and
                request data must never enter diagnostics.

        Returns:
            None: Distinct safe facts reach the log and copied Photos snapshot.
        """
        summary = format_safe_photos_failure(
            f'preload-{self._diagnostic_stage}', 'preload', error,
        )
        if summary == self._failure_summary:
            return
        logging.getLogger(__name__).warning(
            'Photos failure %s', summary,
        )
        self._failure_summary = summary
        if self._diagnostic_callback is not None:
            try:
                self._diagnostic_callback(summary)
            except Exception:
                logging.getLogger(__name__).warning(
                    'Photos preload diagnostic retention callback failed.',
                )

    def _wait_before_retry(
        self, seconds: int, total: int, completed: int,
    ) -> bool:
        """Wait for one bounded server delay while publishing cancelable progress.

        Args:
            seconds (int): Validated server retry delay in seconds.
            total (int): Complete listed photo count.
            completed (int): Photos processed before the throttled rendition.

        Returns:
            bool: True when the delay elapsed; False when cancelled.
        """
        started = time.perf_counter()
        deadline = time.monotonic() + seconds
        while True:
            remaining = max(0, int(deadline - time.monotonic() + 0.999))
            self._publish(
                'waiting', total=total, completed=completed,
                retry_after_seconds=remaining,
            )
            if remaining <= 0:
                self._phase_ms['retry_wait_ms'] += (
                    time.perf_counter() - started
                ) * 1000.0
                return not self._cancelled.is_set()
            if self._cancelled.wait(min(
                PRELOAD_RETRY_POLL_SECONDS, remaining,
            )):
                self._phase_ms['retry_wait_ms'] += (
                    time.perf_counter() - started
                ) * 1000.0
                return False

    def _wait_for_foreground(
        self, total: int | None, completed: int, pinned: bool,
        prepared_bytes: int,
    ) -> bool:
        """Yield cancellably while a foreground media request is waiting.

        Args:
            total (int | None): Complete direct-photo count, or None while
                the direct catalogue is still being listed.
            completed (int): Photos processed before this rendition.
            pinned (bool): Effective gallery retention preference.
            prepared_bytes (int): Bytes fetched before yielding.

        Returns:
            bool: True when background work may continue, or False if cancelled.
        """
        predicate = self._yield_to_foreground
        started = time.perf_counter()
        while (predicate is not None and predicate()
               and not self._cancelled.is_set()):
            self._publish(
                'yielding', total=total, completed=completed, pinned=pinned,
                prepared_bytes=prepared_bytes,
                wait_reason=(self._foreground_reason()
                             if self._foreground_reason is not None else 'browsing'),
            )
            if self._cancelled.wait(PRELOAD_FOREGROUND_POLL_SECONDS):
                break
        self._phase_ms['foreground_wait_ms'] += (
            time.perf_counter() - started
        ) * 1000.0
        if self._cancelled.is_set():
            self._finish(
                'cancelled', '', total=total, pinned=pinned,
                completed=completed, prepared_bytes=prepared_bytes,
            )
            return False
        return True

    def _verify_photos(
        self, photos: list[LinkedPhoto], *, thumbnails_only: bool = False,
    ) -> tuple[int, int, bool]:
        """Check every eligible rendition after writes and ordinary eviction.

        Args:
            photos (list[LinkedPhoto]): Complete ordered direct-photo catalogue.
            thumbnails_only (bool): Verify only the thumbnail rendition when true.

        Returns:
            tuple[int, int, bool]: Fully verified photos, incomplete photos, and
                whether any eligible rendition exists locally. Displayed progress
                keeps earlier confirmed writes until this final count is known.
        """
        verified = 0
        skipped = 0
        any_cached = False
        confirmed_before_check = self.snapshot().cached
        for photo in photos:
            if self._cancelled.is_set():
                return verified, skipped, any_cached
            if not self._eligible(photo):
                skipped += 1
                continue
            photo_cached = True
            renditions = (True,) if thumbnails_only else (True, False)
            for thumbnail in renditions:
                if self._cancelled.is_set():
                    return verified, skipped, any_cached
                rendition_cached = self._session.prepare_cached_photo(
                    self._gallery_id, photo, thumbnail=thumbnail,
                )
                any_cached = any_cached or rendition_cached
                photo_cached = photo_cached and rendition_cached
            if photo_cached:
                verified += 1
            else:
                skipped += 1
            self._publish(
                'checking', total=len(photos),
                completed=verified + skipped,
                cached=max(confirmed_before_check, verified), skipped=skipped,
            )
        return verified, skipped, any_cached

    def _publish(
        self, phase: str, *, total: int | None = None,
        completed: int | None = None, cached: int | None = None,
        skipped: int | None = None, prepared_bytes: int | None = None,
        pinned: bool | None = None, reason: str | None = None,
        cache_state: str | None = None,
        retry_after_seconds: int | None = None,
        current_hits: int | None = None, stored: int | None = None,
        stored_bytes: int | None = None, wait_reason: str | None = None,
    ) -> None:
        """Replace the immutable snapshot while retaining unspecified fields.

        Args:
            phase (str): Current bounded worker phase name.
            total (int | None): Photo work total, or None before listing ends.
            completed (int | None): Processed photo count if newly known.
            cached (int | None): Cache-confirmed scoped photo count if newly known;
                final verification replaces it with current verified coverage.
            skipped (int | None): Missing scoped rendition or ineligible count.
            prepared_bytes (int | None): Sum of fetched missing-rendition bodies.
            pinned (bool | None): Effective local retention preference.
            reason (str | None): Fixed safe terminal reason code.
            cache_state (str | None): Final cloud, partial or local state.
            retry_after_seconds (int | None): Remaining retry countdown in seconds.
            current_hits (int | None): Accepted current authorized disk hits.
            stored (int | None): Cache-confirmed thumbnail publications.
            stored_bytes (int | None): Body bytes in confirmed publications.
            wait_reason (str | None): Fixed urgent foreground reason.

        Returns:
            None: Publishes one atomic snapshot without a progress queue.
        """
        with self._snapshot_lock:
            current = self._snapshot
            self._snapshot = PreloadProgress(
                phase, current.gallery_id,
                current.total if total is None else total,
                current.completed if completed is None else completed,
                current.cached if cached is None else cached,
                current.skipped if skipped is None else skipped,
                current.prepared_bytes if prepared_bytes is None else prepared_bytes,
                current.pinned if pinned is None else pinned,
                current.reason if reason is None else reason,
                current.cache_state if cache_state is None else cache_state,
                retry_after_seconds,
                current.thumbnails_only,
                current.current_hits if current_hits is None else current_hits,
                current.stored if stored is None else stored,
                current.stored_bytes if stored_bytes is None else stored_bytes,
                current.wait_reason if wait_reason is None else wait_reason,
            )

    def _finish(
        self, phase: str, reason: str, *, total: int | None = None,
        completed: int | None = None, cached: int | None = None,
        skipped: int | None = None, prepared_bytes: int | None = None,
        pinned: bool | None = None, cache_state: str | None = None,
    ) -> None:
        """Publish one fixed safe terminal reason and progress snapshot.

        Args:
            phase (str): Terminal phase such as complete, partial or error.
            reason (str): Fixed safe reason code without exception details.
            total (int | None): Known photo total, if available.
            completed (int | None): Processed photo count, if available.
            cached (int | None): Final verified local count, if available.
            skipped (int | None): Ineligible or missing count, if available.
            prepared_bytes (int | None): Bytes fetched during this run.
            pinned (bool | None): Effective retained preference, if known.
            cache_state (str | None): Final canonical cache state, if checked.

        Returns:
            None: Replaces the latest snapshot exactly once per terminal path.
        """
        self._publish(
            phase, total=total, completed=completed, cached=cached,
            skipped=skipped, prepared_bytes=prepared_bytes, pinned=pinned,
            reason=reason, cache_state=cache_state,
        )
        if self._outcome_logged:
            return
        progress = self.snapshot()
        total_text = 'unknown' if progress.total is None else str(progress.total)
        outcome = (
            f'preload_phase={progress.phase}; total={total_text}; '
            f'completed={progress.completed}; cached={progress.cached}; '
            f'skipped={progress.skipped}; '
            f'scope={"thumbnails" if progress.thumbnails_only else "all"}; '
            f'reason={progress.reason or "none"}'
        )
        summary = (
            f'{self._failure_summary}; {outcome}'
            if self._failure_summary is not None else outcome
        )
        logger = logging.getLogger(__name__)
        if progress.phase in ('complete', 'cancelled'):
            logger.info('Photos preload outcome %s', summary)
        else:
            logger.warning('Photos preload outcome %s', summary)
        should_retain = (
            self._failure_summary is not None
            or progress.phase in ('partial', 'error')
        )
        if should_retain and self._diagnostic_callback is not None:
            try:
                self._diagnostic_callback(summary)
            except Exception:
                logging.getLogger(__name__).warning(
                    'Photos preload diagnostic retention callback failed.',
                )
        self._outcome_logged = True
