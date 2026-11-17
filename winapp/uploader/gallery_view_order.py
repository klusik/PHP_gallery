# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_view_order.py
# Module Type: Local Gallery Presentation Service
# Purpose: Reuse fresh session metadata for immediate newest-first display.
# Responsibilities:
#   - Seed immediate display only from a fresh complete same-session metadata snapshot.
#   - Refresh every page before replacing the complete newest-first order.
#   - Retain validated cursors only for safe, retryable metadata-read failures.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Reuse complete session metadata before a fresh newest-first refresh."""

from collections.abc import Callable
import threading
import time

from .app_linking import (
    NATIVE_MEDIA_PAGE_SIZE, LinkedMediaPage, LinkedPhoto, LinkingSession,
    LinkingMediaThrottled, LinkingResponseError,
)

# Purpose: Bound a full reverse snapshot on the client before presenting it.
# Type: int. Units: photo descriptors. Scope: one selected gallery viewer.
# Consumers: GalleryViewOrder.page. Rationale: 100,000 matches the existing
# preload capacity; overflow fails visibly instead of showing a partial order.
VIEW_ORDER_MAX_PHOTOS = 100_000

# Purpose: Retry a transient read timeout at response headers once per full refresh.
# Type: int. Units: retry attempts. Scope: one GalleryViewOrder._refresh call.
# Consumers: GalleryViewOrder._refresh. Rationale: Recover one stalled read page
# without changing configured request timeouts or the original snapshot age.
HEADER_TIMEOUT_AUTO_RETRIES_PER_REFRESH = 1


class GalleryViewOrder:
    """Own a complete newest-first display snapshot for one viewer/session."""

    def __init__(self, session: LinkingSession, gallery_id: int) -> None:
        """Bind local ordering to the existing authenticated metadata reader.

        Args:
            session (LinkingSession): Exact session used for every fresh read.
            gallery_id (int): Physical gallery displayed by this viewer.
        Returns:
            None: Seeds only from fresh session metadata; performs no network work
                and does not authorize or renew any binary media proof.
        """
        self._session = session
        self._gallery_id = gallery_id
        self._photos: tuple[LinkedPhoto, ...] = ()
        self._parent_id: int | None = None
        self._has_cached_snapshot = False
        self._refresh_started_at: float | None = None
        cached = session.recent_media_order(gallery_id)
        if isinstance(cached, tuple) and len(cached) <= VIEW_ORDER_MAX_PHOTOS:
            if all(isinstance(photo, LinkedPhoto) for photo in cached) and all(
                left.image_id > right.image_id
                for left, right in zip(cached, cached[1:])
            ):
                self._photos = cached
                self._has_cached_snapshot = True
        self._pending: list[LinkedPhoto] = []
        self._pending_cursor: int | None = None
        self._resuming = False

    def _is_retryable_header_timeout(
        self, error: LinkingResponseError, cancelled: threading.Event,
    ) -> bool:
        """Identify a read-only media timeout that occurred while awaiting headers.

        Args:
            error (LinkingResponseError): Sanitized transport failure from the
                existing media-page request owner.
            cancelled (threading.Event): Current viewer operation cancellation.
        Returns:
            bool: True only for a live, dispatched, response-free media GET
                timed out at its header phase; no permission or malformed
                response is retryable through this predicate.
        """
        return (
            error.phase == 'media'
            and error.route == 'app_linking_media'
            and error.status is None
            and error.reason == 'timeout'
            and error.transport_step == 'headers'
            and error.request_started is True
            and error.definitely_rejected is False
            and not cancelled.is_set()
            and self._session.can_read_media()
        )

    def page(
        self, after_id: int | None, cancelled: threading.Event, *,
        progress: Callable[[int], None] | None = None,
        retrying: Callable[[], None] | None = None,
    ) -> LinkedMediaPage:
        """Read a reverse page, refreshing the complete snapshot at its start.

        Args:
            after_id (int | None): Last displayed photo, or None to refresh all.
            cancelled (threading.Event): Owning viewer or refresh cancellation.
            progress (Callable[[int], None] | None): Optional worker callback
                receiving the cumulative validated photo count after each page.
                Observer exceptions are ignored and never alter read outcomes.
            retrying (Callable[[], None] | None): Optional worker callback
                called immediately before an eligible automatic header-timeout
                retry. Observer exceptions are ignored.
        Returns:
            LinkedMediaPage: Reverse-ordered metadata with an exclusive local
                continuation; no binary downloads or server settings writes.
        Raises:
            ValueError: Cancelled, incomplete, oversized or invalid catalogue.
            LinkingMediaThrottled: Server quota wait retains the validated cursor.
            LinkingResponseError: At most one classified header timeout is
                retried per refresh after transport completion; a safely
                classified timeout otherwise preserves the cursor for manual
                Retry without resetting the original snapshot age.
        """
        if after_id is None:
            self._refresh(cancelled, progress=progress, retrying=retrying)
        if cancelled.is_set():
            raise ValueError("Gallery order read was cancelled.")
        start = 0
        if after_id is not None:
            start = next((index + 1 for index, photo in enumerate(self._photos)
                          if photo.image_id == after_id), -1)
            if start < 0:
                raise ValueError("Gallery order cursor is no longer current.")
        # Preserve the existing native per-page descriptor budget.
        rows = self._photos[start:start + NATIVE_MEDIA_PAGE_SIZE]
        next_after = rows[-1].image_id if start + len(rows) < len(self._photos) else None
        return LinkedMediaPage(self._gallery_id, self._parent_id, rows, next_after)

    def cached_page(self) -> LinkedMediaPage | None:
        """Build an immediate first page from a fresh session display snapshot.

        Returns:
            LinkedMediaPage | None: Newest-first cached display metadata, or
                None if this order has no current-session snapshot. The caller
                must still run page(None) for authoritative fresh metadata.
        """
        if not self._has_cached_snapshot:
            return None
        recent_order = self._session.recent_media_order(self._gallery_id)
        if recent_order is None or recent_order is not self._photos:
            self._has_cached_snapshot = False
            return None
        rows = self._photos[:NATIVE_MEDIA_PAGE_SIZE]
        next_after = (
            rows[-1].image_id
            if len(rows) < len(self._photos) and rows else None
        )
        return LinkedMediaPage(
            self._gallery_id, self._parent_id, rows, next_after,
        )

    def _refresh(
        self, cancelled: threading.Event, *,
        progress: Callable[[int], None] | None = None,
        retrying: Callable[[], None] | None = None,
    ) -> None:
        """Collect a complete reverse snapshot with bounded safe read resumption.

        Args:
            cancelled (threading.Event): Captured viewer or refresh cancellation.
            progress (Callable[[int], None] | None): Optional worker callback
                receiving validated cumulative photo counts. Observer exceptions
                are ignored and never alter read outcomes.
            retrying (Callable[[], None] | None): Optional worker callback
                called immediately before an eligible automatic header-timeout
                retry. Observer exceptions are ignored.
        Returns:
            None: Publishes only a complete bounded metadata snapshot.
        Raises:
            LinkingMediaThrottled: Keeps admitted rows/cursor for an explicit retry.
            LinkingResponseError: At most one exact header timeout is retried
                after transport completion; safely classified timeouts preserve
                the cursor for manual Retry when that retry is unavailable.
            ValueError: Invalid, oversized or cancelled reads discard partial state.
        """
        if not self._resuming:
            self._pending = []
            self._pending_cursor = None
            self._refresh_started_at = time.monotonic()
        automatic_retries = 0
        try:
            while not cancelled.is_set():
                cursor = self._pending_cursor
                try:
                    page = self._session.media_page(
                        self._gallery_id, after_id=cursor, cancelled=cancelled,
                    )
                except LinkingResponseError as error:
                    # Do not retry while a captured transport still owns the session request.
                    if (
                        automatic_retries
                        < HEADER_TIMEOUT_AUTO_RETRIES_PER_REFRESH
                        and (
                            error.operation_finished is None
                            or error.operation_finished.is_set()
                        )
                        and self._is_retryable_header_timeout(error, cancelled)
                    ):
                        automatic_retries += 1
                        if retrying is not None:
                            try:
                                retrying()
                            except Exception:
                                pass
                        continue
                    raise
                if (page.gallery_id != self._gallery_id
                        or any(photo.image_id <= (cursor or 0) for photo in page.photos)
                        or any(left.image_id >= right.image_id
                               for left, right in zip(page.photos, page.photos[1:]))):
                    raise ValueError('Invalid gallery order snapshot.')
                pending_count = len(self._pending) + len(page.photos)
                if pending_count > VIEW_ORDER_MAX_PHOTOS:
                    raise ValueError('Gallery order snapshot exceeds its bound.')
                if (page.next_after is not None
                        and (not page.photos
                             or page.next_after != page.photos[-1].image_id
                             or page.next_after <= (cursor or 0))):
                    raise ValueError('Gallery order cursor did not advance.')
                if cancelled.is_set():
                    break
                self._pending.extend(page.photos)
                if progress is not None:
                    try:
                        progress(len(self._pending))
                    except Exception:
                        pass
                if page.next_after is None:
                    if cancelled.is_set():
                        break
                    self._photos = tuple(reversed(self._pending))
                    self._parent_id = page.parent_id
                    stored = (
                        self._session.cache_recent_media_order(
                            self._gallery_id, self._photos,
                            self._refresh_started_at,
                        )
                        if self._refresh_started_at is not None else False
                    )
                    self._has_cached_snapshot = stored is True
                    self._pending = []
                    self._pending_cursor = None
                    self._resuming = False
                    self._refresh_started_at = None
                    return
                self._pending_cursor = page.next_after
                self._resuming = True
            raise ValueError('Gallery order read was cancelled.')
        except LinkingMediaThrottled:
            # This is read-only metadata, not an uncertain mutation. Retain
            # the last validated cursor so large albums can cross quota windows
            # without restarting from their oldest page. Native retry timing
            # and user Retry remain with the existing viewer/transport owner.
            self._resuming = True
            raise
        except LinkingResponseError as error:
            if self._is_retryable_header_timeout(error, cancelled):
                # Keep only the last fully validated prefix/cursor. The caller
                # can use the existing manual Retry without restarting prior pages.
                self._resuming = True
                raise
            self._pending = []
            self._pending_cursor = None
            self._resuming = False
            self._refresh_started_at = None
            raise
        except Exception:
            self._pending = []
            self._pending_cursor = None
            self._resuming = False
            self._refresh_started_at = None
            raise
