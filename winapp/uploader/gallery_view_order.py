# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_view_order.py
# Module Type: Local Gallery Presentation Service
# Purpose: Reverse the complete native catalogue without a server preference.
# Responsibilities:
#   - Read and validate the full metadata sequence before newest-first display.
#   - Page that local snapshot and replace it on refresh, including new photos.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Per-viewer reverse metadata snapshots over the unchanged read-only API."""

import threading

from .app_linking import (
    NATIVE_MEDIA_PAGE_SIZE, LinkedMediaPage, LinkedPhoto, LinkingSession,
    LinkingMediaThrottled,
)

# Purpose: Bound a full reverse snapshot on the client before presenting it.
# Type: int. Units: photo descriptors. Scope: one selected gallery viewer.
# Consumers: GalleryViewOrder.page. Rationale: 100,000 matches the existing
# preload capacity; overflow fails visibly instead of showing a partial order.
VIEW_ORDER_MAX_PHOTOS = 100_000


class GalleryViewOrder:
    """Own a newest-first metadata snapshot for exactly one viewer/session."""

    def __init__(self, session: LinkingSession, gallery_id: int) -> None:
        """Bind local ordering to the existing authenticated metadata reader.

        Args:
            session (LinkingSession): Exact session used for every fresh read.
            gallery_id (int): Physical gallery displayed by this viewer.
        Returns:
            None: Starts without retained metadata or remote preference writes.
        """
        self._session = session
        self._gallery_id = gallery_id
        self._photos: tuple[LinkedPhoto, ...] = ()
        self._parent_id: int | None = None
        self._pending: list[LinkedPhoto] = []
        self._pending_cursor: int | None = None
        self._resuming = False

    def page(
        self, after_id: int | None, cancelled: threading.Event,
    ) -> LinkedMediaPage:
        """Read a reverse page, refreshing the complete snapshot at its start.

        Args:
            after_id (int | None): Last displayed photo, or None to refresh all.
            cancelled (threading.Event): Owning viewer or refresh cancellation.
        Returns:
            LinkedMediaPage: Reverse-ordered metadata with an exclusive local
                continuation; no binary downloads or server settings writes.
        Raises:
            ValueError: Cancelled, incomplete, oversized or invalid catalogue.
            LinkingMediaThrottled: Server quota wait retains the validated cursor.
        """
        if after_id is None:
            self._refresh(cancelled)
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

    def _refresh(self, cancelled: threading.Event) -> None:
        """Collect a complete reverse snapshot, resuming safe HTTP 429 waits.

        Args:
            cancelled (threading.Event): Captured viewer or refresh cancellation.
        Returns:
            None: Publishes only a complete bounded metadata snapshot.
        Raises:
            LinkingMediaThrottled: Keeps admitted rows/cursor for an explicit retry.
            ValueError: Invalid, oversized or cancelled reads discard partial state.
        """
        if not self._resuming:
            self._pending = []
            self._pending_cursor = None
        try:
            while not cancelled.is_set():
                cursor = self._pending_cursor
                page = self._session.media_page(
                    self._gallery_id, after_id=cursor, cancelled=cancelled,
                )
                if (page.gallery_id != self._gallery_id
                        or any(photo.image_id <= (cursor or 0) for photo in page.photos)
                        or any(left.image_id >= right.image_id
                               for left, right in zip(page.photos, page.photos[1:]))):
                    raise ValueError('Invalid gallery order snapshot.')
                self._pending.extend(page.photos)
                if len(self._pending) > VIEW_ORDER_MAX_PHOTOS:
                    raise ValueError('Gallery order snapshot exceeds its bound.')
                if page.next_after is None:
                    if cancelled.is_set():
                        break
                    self._photos = tuple(reversed(self._pending))
                    self._parent_id = page.parent_id
                    self._pending = []
                    self._pending_cursor = None
                    self._resuming = False
                    return
                if (not page.photos or page.next_after != page.photos[-1].image_id
                        or page.next_after <= (cursor or 0)):
                    raise ValueError('Gallery order cursor did not advance.')
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
        except Exception:
            self._pending = []
            self._pending_cursor = None
            self._resuming = False
            raise
