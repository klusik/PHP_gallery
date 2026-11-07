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

import threading
import time
from dataclasses import dataclass

from .app_linking import (
    MEDIA_PRELOAD_RETRY_MAX_ATTEMPTS, LinkedPhoto, LinkingError,
    LinkingMediaThrottled, LinkingSession,
)

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


@dataclass(frozen=True)
class PreloadProgress:
    """Describe one immutable, safe snapshot of a gallery preload run.

    Attributes:
        phase (str): Listing, transfer, final check or terminal phase.
        gallery_id (int): Exact physical gallery owned by the worker.
        total (int | None): Direct-photo count after complete listing.
        completed (int): Processed photos in the current operation.
        cached (int): Photos with both renditions verified locally at final check.
        skipped (int): Photos missing at least one rendition or ineligible.
        prepared_bytes (int): Sum of response bodies returned for cache misses.
            This is not a network-wire or complete local-footprint measurement.
        pinned (bool): Current or requested retained-gallery preference.
        reason (str): Fixed safe reason code, never a raw exception.
        cache_state (str): Final cloud, partial or local result, or unknown.
        retry_after_seconds (int | None): Remaining safe server delay while waiting.
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


class GalleryPreloadWorker(threading.Thread):
    """List and verify a gallery's eligible photo cache off the Tk thread."""

    def __init__(
        self, session: LinkingSession, gallery_id: int, *,
        pinned: bool | None = None, download: bool = True,
    ) -> None:
        """Prepare one background gallery retention operation.

        Args:
            session (LinkingSession): Exact current media-readable link owner.
            gallery_id (int): Positive physical gallery ID to preload.
            pinned (bool | None): Pin state to set, or None to preserve current.
            download (bool): Whether cache misses may use the media transport.

        Returns:
            None: Creates a daemon worker with a private cancellation event.
        """
        super().__init__(name='GalleryPreloadWorker', daemon=True)
        self._session = session
        self._gallery_id = gallery_id
        self._requested_pin = pinned
        self._download = download
        self._cancelled = threading.Event()
        self._snapshot_lock = threading.Lock()
        self._snapshot = PreloadProgress(
            'listing', gallery_id, None, 0, 0, 0, 0,
            bool(pinned), '',
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
        except LinkingMediaThrottled:
            self._finish('partial', 'throttled')
        except LinkingError:
            reason = (
                'session' if not self._session.can_read_media()
                else 'unavailable'
            )
            self._finish('partial' if self.snapshot().completed else 'error', reason)
        except (OSError, RuntimeError, ValueError):
            self._finish('partial' if self.snapshot().completed else 'error', 'storage')
        except Exception:  # noqa: BLE001
            self._finish(
                'partial' if self.snapshot().completed else 'error',
                'unavailable',
            )

    def _run_owned(self) -> None:
        """Perform the worker's ordered pin, listing, transfer and verify phases.

        Returns:
            None: Final state describes only cache bodies verified from disk.
        """
        if (type(self._gallery_id) is not int or self._gallery_id <= 0
                or not self._session.can_read_media()):
            self._finish('error', 'session')
            return
        if (self._download and self._session.can_read_admin_media()
                and self._session.supports_admin_preload()
                and not self._session.set_gallery_preload_intent(
                    self._gallery_id, True,
                )):
            self._finish('error', 'storage')
            return
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
        photos = self._list_photos()
        if photos is None:
            return
        total = len(photos)
        self._publish(
            'checking',
            total=total, pinned=pinned,
        )
        prepared_bytes = 0
        retained: dict[tuple[int, bool], bool] = {}
        complete_before_download: set[int] = set()
        for photo in photos:
            if self._cancelled.is_set():
                self._finish('cancelled', '', total=total, pinned=pinned)
                return
            if not self._eligible(photo):
                complete_before_download.add(photo.image_id)
                continue
            for thumbnail in (True, False):
                if self._cancelled.is_set():
                    self._finish('cancelled', '', total=total, pinned=pinned)
                    return
                retained[(photo.image_id, thumbnail)] = (
                    self._session.prepare_cached_photo(
                        self._gallery_id, photo, thumbnail=thumbnail,
                    )
                )
            if (retained[(photo.image_id, True)]
                    and retained[(photo.image_id, False)]):
                complete_before_download.add(photo.image_id)
            self._publish(
                'checking', total=total,
                completed=len(complete_before_download),
                pinned=pinned,
            )
        completed = len(complete_before_download)
        transfer_reason = ''
        if self._download:
            self._publish(
                'preloading', total=total, completed=completed,
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
                for thumbnail in (True, False):
                    if self._cancelled.is_set():
                        self._finish(
                            'cancelled', '', total=total, pinned=pinned,
                            completed=completed,
                            prepared_bytes=prepared_bytes,
                        )
                        return
                    if (retained.get((photo.image_id, thumbnail), False)
                            and not self._private_candidate(photo)):
                        continue
                    missing_before_fetch = not retained.get(
                        (photo.image_id, thumbnail), False,
                    )
                    for attempt in range(MEDIA_PRELOAD_RETRY_MAX_ATTEMPTS):
                        try:
                            body, _mime = self._session.media_photo(
                                self._gallery_id, photo.image_id, thumbnail,
                                self._cancelled,
                                cache_eligible=photo.cache_eligible,
                                thumbnail_etag=photo.thumbnail_etag,
                                thumbnail_verified_at=photo.thumbnail_verified_at,
                                preload_eligible=photo.preload_eligible,
                                preload_thumbnail_etag=photo.preload_thumbnail_etag,
                                preload_verified_at=photo.preload_verified_at,
                            )
                            if missing_before_fetch:
                                prepared_bytes += len(body)
                            break
                        except LinkingMediaThrottled as error:
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
                        except LinkingError:
                            transfer_reason = (
                                'session' if not self._session.can_read_media()
                                else 'unavailable'
                            )
                            break
                    if transfer_reason:
                        break
                if not transfer_reason:
                    completed += 1
                self._publish(
                    'preloading', total=total, completed=completed,
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
        verified, skipped, any_cached = self._verify_photos(photos)
        if self._cancelled.is_set():
            self._finish(
                'cancelled', '', total=total, pinned=pinned,
                completed=completed, cached=verified, skipped=skipped,
                prepared_bytes=prepared_bytes,
            )
            return
        complete = verified == total and skipped == 0
        state = 'local' if complete and total else 'partial' if any_cached else 'cloud'
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
            page = self._session.media_page(
                self._gallery_id, after_id, self._cancelled,
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
        """Check the descriptor and explicit local intent for private storage.

        Args:
            photo (LinkedPhoto): Current server-authorized Admin photo.

        Returns:
            bool: True only for an explicitly eligible photo and enabled intent.
        """
        return (photo.cache_eligible is not True
                and photo.preload_eligible is True
                and self._session.can_read_admin_media()
                and self._session.supports_admin_preload()
                and self._session.gallery_preload_intent(self._gallery_id))

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
        deadline = time.monotonic() + seconds
        while True:
            remaining = max(0, int(deadline - time.monotonic() + 0.999))
            self._publish(
                'waiting', total=total, completed=completed,
                retry_after_seconds=remaining,
            )
            if remaining <= 0:
                return not self._cancelled.is_set()
            if self._cancelled.wait(min(
                PRELOAD_RETRY_POLL_SECONDS, remaining,
            )):
                return False

    def _verify_photos(
        self, photos: list[LinkedPhoto],
    ) -> tuple[int, int, bool]:
        """Check every eligible rendition after writes and ordinary eviction.

        Args:
            photos (list[LinkedPhoto]): Complete ordered direct-photo catalogue.

        Returns:
            tuple[int, int, bool]: Fully verified photos, incomplete photos, and
                whether any eligible rendition exists locally.
        """
        verified = 0
        skipped = 0
        any_cached = False
        for photo in photos:
            if self._cancelled.is_set():
                return verified, skipped, any_cached
            if not self._eligible(photo):
                skipped += 1
                continue
            photo_cached = True
            for thumbnail in (True, False):
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
                completed=verified + skipped, cached=verified, skipped=skipped,
            )
        return verified, skipped, any_cached

    def _publish(
        self, phase: str, *, total: int | None = None,
        completed: int | None = None, cached: int | None = None,
        skipped: int | None = None, prepared_bytes: int | None = None,
        pinned: bool | None = None, reason: str | None = None,
        cache_state: str | None = None,
        retry_after_seconds: int | None = None,
    ) -> None:
        """Replace the immutable snapshot while retaining unspecified fields.

        Args:
            phase (str): Current bounded worker phase name.
            total (int | None): Photo work total, or None before listing ends.
            completed (int | None): Processed photo count if newly known.
            cached (int | None): Fully verified photo count if newly known.
            skipped (int | None): Missing or ineligible photo count.
            prepared_bytes (int | None): Sum of fetched missing-rendition bodies.
            pinned (bool | None): Effective local retention preference.
            reason (str | None): Fixed safe terminal reason code.
            cache_state (str | None): Final cloud, partial or local state.
            retry_after_seconds (int | None): Remaining retry countdown in seconds.

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
