# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/viewer/queue.py
# Module Type: Windows Client UI mixin
# Purpose: Own a cohesive gallery viewer interaction domain.
# Responsibilities: Preserve the native viewer lifecycle and media callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native gallery viewer behavior mixin."""

from .support import (
    Button,
    Callable,
    Image,
    GALLERY_TILE_BORDER_INSET,
    LINKING_CANCELLATION_POLL_SECONDS,
    LegacyThumbnailPublication,
    LinkedMediaPage,
    LinkedPhoto,
    LinkedPresentation,
    LinkingError,
    LinkingMediaThrottled,
    LinkingMediaUnavailable,
    LinkingResponseError,
    LinkingRevisionUnsupported,
    LinkingThumbnailBatchDeferred,
    LinkingThumbnailProofChanged,
    Literal,
    MEDIA_GRID_GUTTER,
    MEDIA_HTTP_BATCH_INITIAL_SIZE,
    MEDIA_IMAGE_MAX_PIXELS,
    MEDIA_QUEUE_ACTIVE_POLL_MS,
    MEDIA_REVISION_POLL_MS,
    MEDIA_THUMBNAIL_BATCH_SIZE,
    MEDIA_THUMBNAIL_DECODE_SIZE,
    MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS,
    MEDIA_THUMBNAIL_PLACEHOLDER,
    MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
    MEDIA_THUMBNAIL_SIZE,
    MEDIA_TILE_LABEL_MAX_CHARS,
    MediaReadTimings,
    NATIVE_MEDIA_LEGACY_PAGE_SIZE,
    NATIVE_MEDIA_MAX_BYTES,
    NATIVE_MEDIA_PAGE_SIZE,
    PhotosDecodeError,
    THUMBNAIL_BATCH_CHOICES,
    THUMBNAIL_BATCH_DEFAULT_SIZE,
    ThumbnailBatchStats,
    ThumbnailPublication,
    _HttpBatchCompletion,
    _ThumbnailResult,
    _WorkerPayload,
    _attach_tooltip,
    _log_safe_media_failure,
    detect_black_matte_crop,
    io,
    queue,
    replace,
    sys,
    threading,
    time,
    tk,
)

class NativeGalleryQueueMixin:
    """Own this cohesive native gallery viewer behavior domain."""

    def _close_music_on_escape(self, _event: tk.Event) -> str:
        """Close the music panel when Escape is pressed inside it.

        Args:
            _event (tk.Event): Key event from the contextual track selector.

        Returns:
            str: Tk break marker after closing the panel.
        """
        self._close_music_panel()
        self.music_button.focus_set()
        return 'break'
    def _close_music_outside(self, event: tk.Event) -> None:
        """Dismiss music choices when a different viewer control is clicked.

        Args:
            event (tk.Event): Click event received by the parent window.

        Returns:
            None: The panel closes unless its trigger was clicked again.
        """
        panel = self._music_panel
        if panel is None or event.widget is self.music_button:
            return
        widget = event.widget
        while widget is not None:
            if widget is panel:
                return
            widget = getattr(widget, 'master', None)
        self._close_music_panel()
    def _on_track_selected(self, _event: tk.Event) -> None:
        """Remember a track selected in the anchored music chooser.

        Args:
            _event (tk.Event): Combobox selection event from the track panel.

        Returns:
            None: Playback remains a separate explicit toolbar action.
        """
        if self.track_menu is not None:
            self._selected_track_index = self.track_menu.current()
    def _queue_load_wait(
        self, generation: int, seconds: int | None, automatic: bool,
    ) -> None:
        """Coalesce a real worker wait without occupying a photograph result slot.

        Args:
            generation (int): Captured worker generation owning this wait.
            seconds (int | None): Confirmed retry delay; None is unknown and
                automatic zero reports that an observed cooldown has ended.
            automatic (bool): Whether the existing owner schedules the retry.
        Returns:
            None: The existing Tk drain consumes only the latest live notice.
        """
        indicator = getattr(self, '_load_progress', None)
        if indicator is None or not indicator.active:
            return
        with self._load_progress_lock:
            if (not self._closed and not self._cancelled.is_set()
                    and generation == self._generation):
                self._pending_load_wait = (generation, seconds, automatic)
    def _launch(
        self,
        kind: str,
        operation: Callable[[], _WorkerPayload],
        *,
        error_payload: _WorkerPayload = None,
        operation_cancelled: threading.Event | None = None,
    ) -> bool:
        """Run one operation and return its result through the Tk queue.

        Args:
            kind (str): Event type interpreted by the Tk polling callback.
            operation (Callable[[], _WorkerPayload]): Worker-only media work.
            error_payload (_WorkerPayload): Optional identity attached to failure.
            operation_cancelled (threading.Event | None): Per-operation
                supersession signal; a cancelled operation publishes no error.

        Returns:
            bool: True when a daemon worker was started; otherwise False.
        """
        if (self._closed or self._cancelled.is_set()
                or (operation_cancelled is not None
                    and operation_cancelled.is_set())):
            return False
        if not self.session.can_read_media():
            self.close()
            return False
        if self._worker is not None:
            self.status.configure(text=self._text('linking_media_loading'))
            return False
        self._generation += 1
        generation = self._generation
        def run() -> None:
            """Execute the supplied operation and publish its result.

            Returns:
                None: A result or failure marker enters the owned queue.
            """
            try:
                result = operation()
                if (self._cancelled.is_set()
                        or (operation_cancelled is not None
                            and operation_cancelled.is_set())):
                    self._release_worker_result(result, kind)
                    return
                self._events.put((generation, kind, result))
            except LinkingRevisionUnsupported:
                if (not self._cancelled.is_set()
                        and (operation_cancelled is None
                             or not operation_cancelled.is_set())):
                    self._events.put((generation, kind + '_unsupported', error_payload))
            except LinkingMediaUnavailable as exc:
                if (self._cancelled.is_set()
                        or (operation_cancelled is not None
                            and operation_cancelled.is_set())):
                    return
                _log_safe_media_failure(
                    'media-fetch', kind, exc,
                    diagnostic_callback=self._diagnostic_callback,
                )
                self._events.put((generation, kind + '_unavailable', error_payload))
            except LinkingMediaThrottled as exc:
                if (self._cancelled.is_set()
                        or (operation_cancelled is not None
                            and operation_cancelled.is_set())):
                    return
                indicator = getattr(self, '_load_progress', None)
                if (indicator is not None and indicator.active
                        and kind in (
                            'page', 'more', 'slide_more', 'refresh', 'thumbnail',
                        )):
                    self._queue_load_wait(
                        generation, exc.retry_after_seconds, False,
                    )
                self._events.put((generation, kind + '_throttled', error_payload))
            except Exception as exc:
                if (self._cancelled.is_set()
                        or (operation_cancelled is not None
                            and operation_cancelled.is_set())):
                    return
                if (kind == 'presentation'
                        and isinstance(exc, LinkingResponseError)
                        and exc.phase == 'media'
                        and exc.route == 'app_linking_media'
                        and exc.status == 404):
                    # Optional presentation settings may be absent on older
                    # galleries. Their absence does not make the photos fail.
                    self._events.put((
                        generation, 'presentation_unavailable', error_payload,
                    ))
                    return
                phase = (
                    "page-fetch"
                    if kind in ("page", "more", "slide_more")
                    else "media-decode"
                    if isinstance(exc, PhotosDecodeError)
                    else "media-fetch"
                )
                _log_safe_media_failure(
                    phase, kind, exc,
                    diagnostic_callback=self._diagnostic_callback,
                )
                failure_payload = error_payload
                if (kind == 'detail'
                        and isinstance(exc, PhotosDecodeError)
                        and isinstance(error_payload, tuple)
                        and len(error_payload) == 5):
                    decode_reason = (
                        exc.facts.reason
                        if exc.facts.reason in (
                            'decode-failed', 'pixel-limit', 'body-limit',
                        ) else 'decode-failed'
                    )
                    failure_payload = (*error_payload, decode_reason)
                self._events.put((generation, kind + '_error', failure_payload))

        self._worker = threading.Thread(
            target=run,
            name='linked-gallery-' + kind,
            daemon=True,
        )
        self._worker_kind = kind
        self._worker.start()
        self._schedule_queue_poll()
        return True
    def _schedule_revision_check(self) -> None:
        """Schedule one bounded background check for the selected viewer.

        Returns:
            None: Hidden viewers defer requests until a later timer tick.
        """
        if not self._closed and self._revision_timer is None:
            self._revision_timer = self.window.after(
                MEDIA_REVISION_POLL_MS, self._check_revision,
            )
    def _check_revision(self) -> None:
        """Probe the visible Admin album without disturbing active work.

        Returns:
            None: Unsupported servers stop probes for this viewer lifetime.
        """
        self._revision_timer = None
        if self._closed:
            return
        if (self.frame.winfo_viewable()
                and not (self._lightbox is not None
                         and self._lightbox.visible)
                and self._queue_thumbnail_proof_refresh()):
            self._dispatch_pending_work()
            self._schedule_revision_check()
            return
        if (self._revision_supported and self.session.can_read_admin_media()
                and self.frame.winfo_viewable()
                and not (self._lightbox is not None
                         and self._lightbox.visible)
                and self._worker is None):
            self._launch(
                'revision', lambda: self.session.media_revision(
                    self.gallery.gallery_id, cancelled=self._cancelled,
                ),
            )
        self._schedule_revision_check()
    def _queue_thumbnail_proof_refresh(self) -> bool:
        """Renew expired thumbnail metadata once before retrying cached bytes.

        Returns:
            bool: True while a required catalogue refresh is queued or running;
                False for fresh or unsupported descriptors, revoked access, or
                the bounded retry interval after an unsuccessful refresh.
        """
        if self._closed or not self.session.can_read_media():
            return False
        if self._refresh_pending or self._worker_kind == 'refresh':
            return True
        now = time.monotonic()
        if (self._thumbnail_proof_refresh_attempt_at is not None
                and now - self._thumbnail_proof_refresh_attempt_at
                < MEDIA_REVISION_POLL_MS / 1000.0):
            return False
        expired = False
        for index in self._admitted_thumbnail_indices:
            if not 0 <= index < len(self._photos):
                continue
            photo = self._photos[index]
            verified_at = (
                photo.thumbnail_verified_at
                if photo.cache_eligible is True
                else photo.preload_verified_at
                if photo.cache_eligible is False
                and photo.preload_eligible is True and photo.nsfw is False
                else None
            )
            validator = (
                photo.thumbnail_etag if photo.cache_eligible is True
                else photo.preload_thumbnail_etag
            )
            if (validator is not None and verified_at is not None
                    and now - verified_at
                    >= MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS):
                if (photo.cache_eligible is False
                        and self.session.private_preload_cache_identity(
                            self.gallery.gallery_id, photo, thumbnail=True,
                        ) is not None):
                    continue
                expired = True
                break
        if not expired:
            return False
        # Reuse the existing bounded page reconciliation and refusal handling.
        # A failed metadata read cannot extend authority or create a busy retry
        # loop: ordinary authorized media reads remain the compatibility path
        # until the next existing revision interval permits another attempt.
        self._thumbnail_proof_refresh_attempt_at = now
        self._thumbnail_proof_refresh_count += 1
        self._thumbnail_disk_lane.invalidate()
        if self._worker_kind == 'thumbnail_batch':
            self._thumbnail_cancelled.set()
            self._generation += 1
        self._refresh_pending = True
        return True
    def refresh_photos(self) -> None:
        """Refresh loaded photo metadata without replacing the viewer.

        Returns:
            None: A busy viewer queues one refresh after its current work.
        """
        if self._closed:
            return
        self._cancel_thumbnail_resume()
        self._thumbnail_disk_lane.invalidate()
        if self._worker_kind == 'thumbnail_batch':
            self._thumbnail_cancelled.set()
            self._generation += 1
        self._refresh_pending = True
        self._refresh_manual_pending = True
        self._dispatch_pending_work()
    def _start_refresh(self) -> None:
        """Read enough pages to cover the currently loaded album extent.

        Returns:
            None: The serialized worker returns a validated metadata snapshot;
                a new photo selection can cancel this background operation.
        """
        self._cancel_thumbnail_resume()
        # A complete old snapshot admits one additional native page so an
        # upload after an exactly full final page is actually displayed.
        target_count = len(self._photos) + (
            NATIVE_MEDIA_PAGE_SIZE if self._next_after is None else 0
        )
        operation_cancelled = threading.Event()
        self._active_media_cancelled = operation_cancelled

        def read() -> tuple[LinkedMediaPage, ...]:
            """Fetch successive bounded pages through the loaded extent.

            Returns:
                tuple[LinkedMediaPage, ...]: Validated ordered pages.
            """
            pages: list[LinkedMediaPage] = []
            cursor: int | None = None
            count = 0
            # Valid continuations contain at least one legacy-sized page.
            page_limit = max(1, (
                target_count + NATIVE_MEDIA_LEGACY_PAGE_SIZE - 1
            ) // NATIVE_MEDIA_LEGACY_PAGE_SIZE)
            for _page_number in range(page_limit):
                page = self._read_photo_page(
                    after_id=cursor,
                    cancelled=operation_cancelled,
                )
                pages.append(page)
                count += len(page.photos)
                next_cursor = page.next_after
                if next_cursor is None or count >= target_count:
                    return tuple(pages)
                if (not page.photos or (cursor is not None
                        and (next_cursor == cursor or (
                            getattr(self, '_view_order', None) is None
                            and next_cursor < cursor)))):
                    raise ValueError('Gallery refresh cursor did not advance.')
                cursor = next_cursor
            raise ValueError('Gallery refresh exceeded its loaded page extent.')
        self._refresh_pending = False
        self._refresh_foreground = self._refresh_manual_pending
        self._refresh_manual_pending = False
        if not self._launch(
            'refresh', read, operation_cancelled=operation_cancelled,
        ) and self._active_media_cancelled is operation_cancelled:
            self._active_media_cancelled = None
    def _accept_refresh(self, pages: tuple[LinkedMediaPage, ...]) -> None:
        """Reconcile refreshed metadata while preserving view and photo identity.

        Args:
            pages (tuple[LinkedMediaPage, ...]): Ordered pages through loaded extent.

        Returns:
            None: Equal metadata keeps the UI untouched; reordered data restores
                focused photo identity when the Canvas still owns keyboard focus.
        """
        self._cancel_thumbnail_resume()
        # An accepted authoritative snapshot permits a new network attempt.
        self._http_thumbnail_failure_latched = False
        for index in self._http_batch_retry_indices:
            self._thumbnail_loaded.discard(index)
        self._http_batch_retry_indices = ()
        photos = tuple(photo for page in pages for photo in page.photos)
        self._selected_photo_ids.intersection_update(
            photo.image_id for photo in photos
        )
        comparable = lambda photo: replace(
            photo,
            # A page without a content validator cannot prove an old
            # PhotoImage unchanged. Fresh metadata must replace that tile.
            thumbnail_verified_at=(
                photo.thumbnail_verified_at
                if photo.thumbnail_etag is None else None
            ),
            preload_verified_at=None,
        )
        if (tuple(map(comparable, photos)) == tuple(map(comparable, self._photos))
                and pages[-1].next_after == self._next_after):
            # Keep fresh server proofs even when the visual rows are unchanged.
            # Retaining the old descriptor age forced needless binary requests.
            self._photos = photos
            # Manual refresh interrupted warm admission even when metadata
            # stayed equal. Restore its work without rebuilding any tile.
            self._on_grid_viewport(None)
            return
        same_ordered_ids = bool(
            photos and len(photos) == len(self._photos)
            # Page/prefetch navigation intents remain owned by full reconciliation.
            and not self._select_first_after_page
            and not self._wrap_after_metadata
            and not self._prefetch_edge_direction
            and tuple(photo.image_id for photo in photos)
            == tuple(photo.image_id for photo in self._photos)
            and len(self._tile_buttons) == len(self._photos)
            and len(self._photo_images) == len(self._photos)
            and len(self._tile_tooltips) == len(self._photos)
            and len(self._tile_titles) == len(self._photos)
        )
        self._cancel_grid_viewport_callback()
        self._cancel_thumbnail_gui_slice()
        self._thumbnail_disk_lane.invalidate()
        if same_ordered_ids:
            previous_photos = self._photos
            previous_images = tuple(self._photo_images)
            self._photos = photos
            self._next_after = pages[-1].next_after
            self._loading_more = False
            self._page_request_mode = 'next'
            self._empty_page = False
            self._thumbnail_index = 0
            self._thumbnail_loaded.clear()
            self._thumbnail_pending.clear()
            self._thumbnail_cancelled.clear()
            self.retry_button.configure(state='disabled')
            self.retry_button.pack_forget()
            self.status.pack_forget()
            self.status.configure(text='')
            changed_indexes: list[int] = []
            for index, photo in enumerate(photos):
                retain_image = (
                    previous_images[index] is not None
                    and comparable(previous_photos[index]) == comparable(photo)
                )
                if retain_image:
                    self._thumbnail_loaded.add(index)
                else:
                    self._release_thumbnail_image(index)
                    changed_indexes.append(index)
                title = ''.join(
                    char if char.isprintable() else ' '
                    for char in photo.title
                ).strip()
                full_label = (
                    title[:MEDIA_TILE_LABEL_MAX_CHARS] or self._text('linking_media_open')
                )
                if (self._tile_titles[index] != full_label
                        or not retain_image):
                    self._tile_titles[index] = full_label
                    self._tile_tooltips[index].set(full_label)
            self._render_photo_selection(tuple(changed_indexes))
            self.rearm_first_viewport_diagnostics()
            if self.on_photos_changed is not None:
                self.on_photos_changed()
            return
        anchor_id = (
            self._photos[self._selection_anchor_index].image_id
            if self._selection_anchor_index is not None
            and 0 <= self._selection_anchor_index < len(self._photos)
            else None
        )
        scroll = self.grid_canvas.yview()[0]
        retained = {
            photo.image_id: (comparable(photo), self._photo_images[index])
            for index, photo in enumerate(self._photos)
            if index < len(self._photo_images) and self._photo_images[index] is not None
        }
        previous_images = tuple(
            image for image in self._photo_images if image is not None
        )
        selected = (self._photos[self._slide_index].image_id
                    if self._lightbox is not None and self._photos
                    and self._slide_index < len(self._photos) else None)
        canvas_grid = getattr(self, '_canvas_grid', None)
        focused_photo_id: int | None = None
        focused_old_index: int | None = None
        if (canvas_grid is not None
                and self.grid_canvas.focus_get() is self.grid_canvas):
            focused_old_index = canvas_grid.focused_index
            if (focused_old_index is not None
                    and 0 <= focused_old_index < len(self._photos)):
                focused_photo_id = self._photos[focused_old_index].image_id
        if canvas_grid is not None:
            canvas_grid.reset_catalogue()
        else:
            for tile in self._tile_buttons:
                tile.destroy()
        self._tile_buttons.clear()
        self._photo_images.clear()
        self._tile_tooltips.clear()
        self._tile_titles.clear()
        self._photos = ()
        self._thumbnail_loaded.clear()
        self._thumbnail_pending.clear()
        self._visible_thumbnail_indices.clear()
        self._admitted_thumbnail_indices.clear()
        self._page_request_mode = 'initial'
        self._reconciling = True
        try:
            for page in pages:
                self._accept_page(page, self._presentation)
        finally:
            self._reconciling = False
        self._selection_anchor_index = next((
            index for index, photo in enumerate(self._photos)
            if photo.image_id == anchor_id
        ), None)
        for index, photo in enumerate(self._photos):
            old = retained.get(photo.image_id)
            if old is not None and old[0] == comparable(photo):
                self._photo_images[index] = old[1]
                self._tile_buttons[index].configure(
                    image=old[1], text='', compound='image',
                    style='LinkedPhoto.TButton',
                )
                self._thumbnail_loaded.add(index)
                if index in self._thumbnail_pending:
                    self._thumbnail_pending.remove(index)
        retained_image_ids = {
            id(image) for image in self._photo_images if image is not None
        }
        self._thumbnail_image_release_count += sum(
            1 for image in previous_images
            if id(image) not in retained_image_ids
        )
        self._render_photo_selection()
        if (canvas_grid is not None and focused_photo_id is not None
                and self.grid_canvas.focus_get() is self.grid_canvas
                and self._photos):
            restored_focus = next(
                (index for index, photo in enumerate(self._photos)
                 if photo.image_id == focused_photo_id),
                None,
            )
            if restored_focus is None:
                restored_focus = min(
                    focused_old_index or 0, len(self._photos) - 1,
                )
            canvas_grid.focus(restored_focus, ensure_visible=False)
        if selected is not None:
            index = next((i for i, photo in enumerate(self._photos)
                          if photo.image_id == selected), None)
            if index is not None:
                self._slide_index = index
            elif self._lightbox is not None:
                self._lightbox.dismiss()
        self.grid_canvas.after_idle(lambda: self.grid_canvas.yview_moveto(scroll))
        self._on_grid_viewport(None)
        if self.on_photos_changed is not None:
            self.on_photos_changed()
    def _schedule_queue_poll(self, delay_ms: int = MEDIA_QUEUE_ACTIVE_POLL_MS) -> None:
        """Schedule delivery while work or an already prepared result exists.

        Args:
            delay_ms (int): Bounded wait before the next queue drain in ms.

        Returns:
            None: One bounded timer checks worker or ready in-memory results.
        """
        if (self._after_id is None and not self._closed
                and (self._worker is not None
                     or not self._events.empty()
                     or self._thumbnail_disk_lane.busy())):
            self._after_id = self.window.after(delay_ms, self._poll_queue)
    def _cancel_thumbnail_resume(self) -> None:
        """Revoke the one pending local-cooldown wakeup for this viewer.

        Returns:
            None: Any queued callback becomes stale and releases its owner data.
        """
        self._thumbnail_resume_serial += 1
        if self._thumbnail_resume_after_id is not None:
            try:
                self.window.after_cancel(self._thumbnail_resume_after_id)
            except tk.TclError:
                pass
        self._thumbnail_resume_after_id = None
    def _schedule_thumbnail_resume(
        self, delay_seconds: int,
        operation_finished: threading.Event | None,
    ) -> None:
        """Resume unsettled visible thumbnails after the current cooldown.

        Args:
            delay_seconds (int): Nonnegative cooldown remainder from the session
                owner, in seconds; zero means its delay has already elapsed.
            operation_finished (threading.Event | None): Prior transport worker
                completion signal that must be set before another request.

        Returns:
            None: One viewer/session/gallery-bound Tk wakeup resumes current
                admitted work through normal foreground-first dispatch.
        """
        if type(delay_seconds) is not int or delay_seconds < 0:
            return
        self._cancel_thumbnail_resume()
        serial = self._thumbnail_resume_serial
        gallery_id = self.gallery.gallery_id
        session = self.session
        deadline = time.monotonic() + delay_seconds

        def resume() -> None:
            """Check the existing cooldown and wake only its live viewer.

            Returns:
                None: A ready owner dispatches normally; stale owners are dropped.
            """
            if serial != self._thumbnail_resume_serial:
                return
            self._thumbnail_resume_after_id = None
            if (self._closed or self.session is not session
                    or self.gallery.gallery_id != gallery_id
                    or self._cancelled.is_set()):
                self._cancel_thumbnail_resume()
                return
            if not self.session.can_read_media():
                self._cancel_thumbnail_resume()
                self.close()
                return
            if self._worker_kind == 'thumbnail_batch':
                remaining = LINKING_CANCELLATION_POLL_SECONDS
                delay_ms = max(
                    MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
                    int(remaining * 1000),
                )
                self._thumbnail_resume_after_id = self.window.after(
                    delay_ms, resume,
                )
                return
            if (operation_finished is not None
                    and not operation_finished.is_set()):
                remaining = max(0.0, deadline - time.monotonic())
                if remaining <= 0:
                    remaining = LINKING_CANCELLATION_POLL_SECONDS
                # The session constant is seconds; Tk's after() accepts ms.
                delay_ms = max(
                    MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
                    int(remaining * 1000),
                )
                self._thumbnail_resume_after_id = self.window.after(
                    delay_ms, resume,
                )
                return
            # A viewport change can cancel the completed batch just before its
            # completion is drained. Keep that fence until prior I/O is over,
            # then recompute work from the current admitted rows.
            if self._thumbnail_cancelled.is_set():
                self._thumbnail_cancelled.clear()
            for index in self._http_batch_retry_indices:
                self._thumbnail_loaded.discard(index)
                if (index in self._admitted_thumbnail_indices
                        and index not in self._thumbnail_pending):
                    self._thumbnail_pending.append(index)
            self._http_batch_retry_indices = ()
            self._thumbnail_resume_serial += 1
            if (not self._media_throttled
                    and not self._http_thumbnail_failure_latched
                    and self._retry_kind == 'thumbnail'):
                self.retry_button.configure(state='disabled')
                self.retry_button.pack_forget()
                self.status.pack_forget()
            indicator = getattr(self, '_load_progress', None)
            if indicator is not None:
                indicator.resume()
            self._dispatch_pending_work()

        # Cooldown seconds come from the already validated session result.
        # Convert seconds to the millisecond unit required by Tk after().
        delay_ms = max(
            MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
            delay_seconds * 1000,
        )
        self._thumbnail_resume_after_id = self.window.after(delay_ms, resume)
    def _read_photo_page(
        self, after_id: int | None = None,
        cancelled: threading.Event | None = None,
    ) -> LinkedMediaPage:
        """Read metadata in this viewer's persistent local presentation order.

        Args:
            after_id (int | None): Exclusive display cursor, or fresh first page.
            cancelled (threading.Event | None): Refresh signal or viewer lifetime.
        Returns:
            LinkedMediaPage: Existing ascending page or a full-snapshot reverse page.
        """
        indicator = getattr(self, '_load_progress', None)
        if indicator is not None and indicator.active:
            with self._load_progress_lock:
                if not self._closed and not self._cancelled.is_set():
                    # Starting a metadata read changes phase, not real progress.
                    self._pending_load_progress = (self._generation, 0)
        order = getattr(self, '_view_order', None)
        if order is not None:
            generation = self._generation
            operation_cancelled = cancelled or self._cancelled

            def report_progress(count: int) -> None:
                """Coalesce confirmed counts without occupying a photo-result slot.

                Args:
                    count (int): Cumulative accepted metadata row count.
                Returns:
                    None: Only the latest live-generation count awaits Tk delivery.
                """
                indicator = getattr(self, '_load_progress', None)
                if indicator is None or not indicator.active:
                    return
                with self._load_progress_lock:
                    if not self._closed and not operation_cancelled.is_set():
                        self._pending_load_progress = (generation, count)

            def report_retrying() -> None:
                """Publish the existing safe retry notice without blocking.

                Returns:
                    None: The existing Tk drain receives an unknown-delay retry.
                """
                indicator = getattr(self, '_load_progress', None)
                if (self._closed or operation_cancelled.is_set()
                        or indicator is None or not indicator.active):
                    return
                self._queue_load_wait(generation, None, True)

            return order.page(
                after_id, operation_cancelled,
                progress=report_progress, retrying=report_retrying,
            )
        if after_id is None:
            return self.session.media_page(
                self.gallery.gallery_id, cancelled=cancelled,
            )
        return self.session.media_page(
            self.gallery.gallery_id, after_id=after_id, cancelled=cancelled,
        )
    def _load_first_page(self) -> None:
        """Show recent session metadata and refresh it through the same owner.

        Returns:
            None: Recent rows can render immediately while a fresh server page
                follows; binary authorization and descriptor ages are unchanged.
                Otherwise the first page precedes optional presentation settings.
        """
        self._page_request_cursor = None
        self._page_request_mode = 'initial'
        order = getattr(self, '_view_order', None)
        cached_order = order.cached_page() if order is not None else None
        if (isinstance(cached_order, LinkedMediaPage)
                and not cached_order.cover_only
                and self.session.can_read_media()
                and not self._closed and self._worker is None
                and self._events.empty()):
            self._events.put_nowait((
                self._generation, 'page_recent', (cached_order, None),
            ))
            self._schedule_queue_poll(
                MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
            )
            return
        recent = getattr(self.session, 'recent_media_page', None)
        page = (recent(self.gallery.gallery_id)
                if callable(recent) and order is None else None)
        if (isinstance(page, LinkedMediaPage) and not page.cover_only and not self._closed
                and self._worker is None and self._events.empty()):
            # A cover descriptor cannot imply a complete one-photo album.
            # Deliver full pages after mounting, so implicit-preview callbacks and cache
            # owners are installed before they observe the reused page.
            self._generation += 1
            self._events.put_nowait((
                self._generation, 'page_recent', (page, None),
            ))
            self._schedule_queue_poll(MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS)
            return

        def operation() -> tuple[LinkedMediaPage, LinkedPresentation | None]:
            """Fetch one authorized page for the existing page event.

            Returns:
                tuple[LinkedMediaPage, LinkedPresentation | None]: Page and
                    no presentation until its later owned request.
            """
            page = self._read_photo_page(
                cancelled=self._cancelled,
            )
            return page, None

        self._launch('page', operation)
    def _load_more(
        self, *, automatic: bool = False, select_first: bool = False,
    ) -> None:
        """Request the next server page using its exclusive continuation.

        Args:
            automatic (bool): Whether the active slideshow advanced past its page.
            select_first (bool): Whether to open the first photo after fetching.

        Returns:
            None: Appends ordered metadata or stops an interrupted slide.
        """
        after_id = self._next_after
        if after_id is None:
            return
        self._prefetch_direction = 1
        self._select_first_after_page = select_first or automatic
        if self._worker is not None:
            self._pending_page_request = (after_id, automatic)
            self._thumbnail_cancelled.set()
            return

        self._start_page_after(after_id, automatic=automatic)
    def _start_page_after(
        self,
        after_id: int | None,
        *,
        automatic: bool = False,
    ) -> None:
        """Start one serialized server-page request after its captured cursor.

        Args:
            after_id (int | None): Start cursor for the requested server page.
            automatic (bool): Whether the slideshow owns this page advance.
        Returns:
            None: The page event updates the visible grid on Tk's thread.
        """

        if self._worker is not None:
            self._pending_page_request = (after_id, automatic)
            return
        self._page_request_cursor = after_id
        self._page_request_mode = 'next'

        def operation() -> LinkedMediaPage:
            """Fetch the page after the current exclusive cursor.

            Returns:
                LinkedMediaPage: Validated page from the linked session.
            """
            return self._read_photo_page(
                after_id=after_id,
                cancelled=self._cancelled,
            )

        if self._launch('slide_more' if automatic else 'more', operation):
            self._loading_more = True
        elif automatic:
            self._stop_slideshow()
    def _accept_page(
        self,
        page: LinkedMediaPage,
        presentation: LinkedPresentation | None,
        *, continuing_slides: bool = False,
    ) -> None:
        """Validate and append one ordered page to the continuous photo grid.

        Args:
            page (LinkedMediaPage): Validated server page for this gallery.
            presentation (LinkedPresentation | None): Optional scoped settings.
            continuing_slides (bool): Keep active playback when crossing a page.

        Returns:
            None: Metadata appends immediately; visible thumbnail work is queued.
        """
        if (not isinstance(page, LinkedMediaPage)
                or (presentation is not None
                    and not isinstance(presentation, LinkedPresentation))
                or page.gallery_id != self.gallery.gallery_id
                or len(page.photos) > NATIVE_MEDIA_PAGE_SIZE
                or any(type(photo.image_id) is not int or photo.image_id <= 0
                       for photo in page.photos)
                or (page.next_after is not None
                    and (type(page.next_after) is not int
                         or page.next_after <= 0))):
            raise ValueError('Invalid media page')
        self._cancel_thumbnail_resume()
        if self._page_request_mode == 'initial':
            self._http_batch_retry_indices = ()
            if not getattr(self, '_reconciling', False):
                self._selected_photo_ids.clear()
                self._selection_anchor_index = None
            self._photos = ()
        self._page_request_mode = 'next'
        old_count = len(self._photos)
        canvas_grid = getattr(self, '_canvas_grid', None)
        photos = page.photos
        self._photos += photos
        self._next_after = page.next_after
        self._loading_more = False
        tracks = tuple(presentation.tracks) if presentation is not None else ()
        if self._audio_index is not None and (
            presentation is None or not presentation.music or tracks != self._tracks
        ):
            self._stop_audio()
            self._close_music_panel()
        self._presentation = presentation
        self._tracks = tracks
        self._refresh_audio_button()
        self._empty_page = not bool(self._photos)
        indicator = getattr(self, '_load_progress', None)
        if indicator is not None and indicator.active:
            if self._photos:
                indicator.metadata(len(self._photos))
            else:
                indicator.hide()
        if old_count == 0:
            if canvas_grid is not None:
                canvas_grid.reset_catalogue()
            # Rebuilt descriptors invalidate the prior visible-band measurement.
            self._measured_visible = frozenset()
            self._viewport_measure_epoch = None
            for canonical in self._canonical_thumbnail_images.values():
                canonical.close()
            self._canonical_thumbnail_images.clear()
            self._tile_buttons.clear()
            self._photo_images.clear()
            self._tile_tooltips.clear()
            self._tile_titles.clear()
        self._photo_images.extend([None] * len(photos))
        for index in range(old_count, len(self._photos)):
            photo = self._photos[index]
            title = ''.join(char if char.isprintable() else ' '
                            for char in photo.title).strip()
            full_label = title[:MEDIA_TILE_LABEL_MAX_CHARS] or self._text('linking_media_open')
            if canvas_grid is not None:
                tile = canvas_grid.append(index, photo.image_id, full_label)
                self._tile_buttons.append(tile)
                self._tile_tooltips.append(tile.tooltip)
                self._tile_titles.append(full_label)
            else:
                tile = Button(
                    self.grid_frame,
                    text=MEDIA_THUMBNAIL_PLACEHOLDER,
                    image='',
                    compound='none',
                    width=-1,
                    style='Placeholder.LinkedPhoto.TButton',
                    command=lambda selected=index: self.select_photo(selected),
                    takefocus=True,
                )
                row = index // max(1, getattr(self, '_grid_columns', 1))
                tile_height = self._card_dimensions()[1]
                self.grid_frame.grid_rowconfigure(
                    row, minsize=(tile_height + MEDIA_GRID_GUTTER
                                  + 2 * GALLERY_TILE_BORDER_INSET),
                )
                tile.grid(
                    row=row,
                    column=index % max(1, getattr(self, '_grid_columns', 1)),
                    # Keep unused row and column space outside the image rim.
                    sticky='',
                    padx=MEDIA_GRID_GUTTER // 2,
                    pady=MEDIA_GRID_GUTTER // 2,
                )
                self._tile_buttons.append(tile)
                tile.bind('<MouseWheel>', self._on_grid_wheel, add='+')
                self._bind_precise_scroll(tile)
                tile.bind('<Button-4>', self._on_grid_wheel, add='+')
                tile.bind('<Button-5>', self._on_grid_wheel, add='+')
                tile.bind(
                    '<Button-1>',
                    lambda _event, selected=index: (
                        self.select_photo(selected), 'break',
                    )[1],
                )
                tile.bind(
                    '<Double-Button-1>',
                    lambda _event, selected=index: (
                        self._open_grid_photo(selected), 'break',
                    )[1],
                )
                tile.bind(
                    '<Shift-Button-1>',
                    lambda _event, selected=index: (
                        self.select_photo_range(selected), 'break',
                    )[1],
                )
                tile.bind(
                    '<Return>',
                    lambda _event, selected=index: (
                        self._open_grid_photo(selected), 'break',
                    )[1],
                )
                for sequence in (
                    '<Left>', '<Right>', '<Up>', '<Down>', '<space>', '<KP_Enter>',
                ):
                    tile.bind(
                        sequence,
                        lambda event, selected=index: self._tile_key(
                            selected, event,
                        ),
                    )
                context_sequences = ('<Button-3>', '<Button-2>')
                if sys.platform == 'darwin':
                    context_sequences += ('<Control-Button-1>',)
                for sequence in context_sequences:
                    tile.bind(
                        sequence,
                        lambda event, selected=index: self._on_photo_context(
                            selected, event,
                        ),
                        add='+',
                    )
                if sys.platform != 'darwin':
                    tile.bind(
                        '<Control-Button-1>',
                        lambda _event, selected=index: (
                            self.toggle_photo_selection(
                                self._photos[selected].image_id,
                            ), 'break',
                        )[1] if selected < len(self._photos) else 'break',
                        add='+',
                    )
                elif sys.platform == 'darwin':
                    tile.bind(
                        '<Command-Button-1>',
                        lambda _event, selected=index: (
                            self.toggle_photo_selection(
                                self._photos[selected].image_id,
                            ), 'break',
                        )[1] if selected < len(self._photos) else 'break',
                        add='+',
                    )
                tile.bind(
                    '<Shift-F10>',
                    lambda event, selected=index: self._on_photo_context(
                        selected, event,
                    ),
                    add='+',
                )
                tooltip = tk.StringVar(master=self.frame, value=full_label)
                self._tile_tooltips.append(tooltip)
                self._tile_titles.append(full_label)
                _attach_tooltip(tile, tooltip)
        if canvas_grid is not None:
            self._layout_canvas_grid()
        self._render_photo_selection()
        self.retry_button.configure(state='disabled')
        self.retry_button.pack_forget()
        can_slideshow = bool(self._photos) or self._slideshow_running
        self.slide_button.configure(state='normal' if can_slideshow else 'disabled')
        if not self._photos:
            self._stop_slideshow()
            self._refresh_empty_page_status()
            self._notify_preview_result(False)
            return
        self._empty_page = False
        self.status.pack_forget()
        self.status.configure(text='')
        self._thumbnail_index = 0
        if old_count == 0:
            self._thumbnail_loaded.clear()
            self._thumbnail_pending.clear()
        self._thumbnail_cancelled.clear()
        if self._select_first_after_page:
            self._select_first_after_page = False
            if old_count < len(self._photos):
                self._select_photo(old_count, keep_slideshow=continuing_slides)
            elif self._photos and self._next_after is None:
                self._select_photo(0, keep_slideshow=continuing_slides)
        if not self._reconciling:
            self._on_grid_viewport(None)
        if self._wrap_after_metadata and self._next_after is None:
            self._wrap_after_metadata = 0
            self._select_photo(len(self._photos) - 1)
        elif self._wrap_after_metadata and self._next_after is not None:
            self._load_more()
        if (self._prefetch_edge_direction
                and (self._lightbox is None or not self._lightbox.visible)):
            self._prefetch_edge_direction = 0
        if self._prefetch_edge_direction == 1 and photos:
            first_new = self._photos[old_count]
            if self._cached_detail(first_new.image_id) is None:
                self._pending_prefetch_ids.append(first_new.image_id)
            self._prefetch_edge_direction = 0
        elif self._prefetch_edge_direction == -1:
            if self._next_after is not None:
                self._load_more()
            else:
                last_photo = self._photos[-1]
                if self._cached_detail(last_photo.image_id) is None:
                    self._pending_prefetch_ids.append(last_photo.image_id)
                self._prefetch_edge_direction = 0
        if (self._slideshow_running and not self._slideshow_music_attempted
                and self._audio_index is None
                and self._presentation is not None
                and self._presentation.music and self._tracks):
            self._slideshow_music_attempted = True
            self._toggle_track()
            self._slideshow_started_music = self._audio_index is not None
        self._notify_preview_result(True)
    def _notify_preview_result(self, has_photos: bool | None) -> None:
        """Report the implicit initial candidate once on Tk's thread.

        Args:
            has_photos (bool | None): True for photos, False for empty or
                unavailable, or None for a temporary first-page failure.

        Returns:
            None: Explicit gallery choices have no callback to invoke.
        """
        if not self._preview_result_pending:
            return
        self._preview_result_pending = False
        callback = self._preview_result
        self._preview_result = None
        if callback is not None:
            callback(has_photos)
    def set_folder_shelf_visible(self, visible: bool) -> None:
        """Hide empty-gallery feedback while visible child folders are shown.

        Args:
            visible (bool): Whether the workspace currently shows child cards.

        Returns:
            None: Loading and error feedback remain visible in every state.
        """
        self._folder_shelf_visible = bool(visible)
        self._refresh_empty_page_status()
    def _refresh_empty_page_status(self) -> None:
        """Show empty-gallery feedback only when the folder shelf is hidden.

        Returns:
            None: Status visibility reflects empty media and visible children.
        """
        if not self._empty_page:
            return
        if self._folder_shelf_visible:
            self.status.pack_forget()
            return
        self.status.configure(text=self._text('linking_media_empty'))
        self.status.pack(anchor='w', pady=(4, 8))
    def _prepare_thumbnail(self, body: bytes) -> 'Image.Image':
        """Reduce oversized JPEG variants before preparing the bounded tile.

        Args:
            body (bytes): Complete authorized image response.

        Returns:
            Image.Image: Independent RGB raster at the thumbnail pixel bound.
                Decoder subsampling avoids full camera-raster allocation when
                the server supplies a larger fallback as its thumbnail.

        Raises:
            ValueError: Pillow is unavailable, body is oversized, or source
                dimensions exceed the decoded-image policy.
            OSError: The encoded image cannot be opened or decoded safely.
        """
        if Image is None or len(body) > NATIVE_MEDIA_MAX_BYTES:
            raise ValueError('Image support unavailable or response too large')
        image: 'Image.Image | None' = None
        try:
            with Image.open(io.BytesIO(body)) as source:
                if source.width * source.height > MEDIA_IMAGE_MAX_PIXELS:
                    raise ValueError('Image dimensions exceed policy')
                if (source.format in ('JPEG', 'MPO')
                        and (source.width > MEDIA_THUMBNAIL_DECODE_SIZE[0]
                             or source.height > MEDIA_THUMBNAIL_DECODE_SIZE[1])):
                    source.draft('RGB', MEDIA_THUMBNAIL_DECODE_SIZE)
                image = source.convert('RGB')
            crop = detect_black_matte_crop(image)
            if crop != (0, 0, image.width, image.height):
                cropped = image.crop(crop)
                image.close()
                image = cropped
            image.thumbnail(MEDIA_THUMBNAIL_SIZE, Image.Resampling.LANCZOS)
            result = image
            image = None
            return result
        finally:
            if image is not None:
                image.close()
    def _load_thumbnails(
        self,
        photos: tuple[LinkedPhoto, ...],
        index: int,
        timings: MediaReadTimings | None = None,
        gallery_id: int | None = None,
    ) -> tuple[int, 'Image.Image | None', float]:
        """Fetch and downsize one viewport-admitted album thumbnail.

        Args:
            photos (tuple[LinkedPhoto, ...]): Ordered photos in the album so far.
            index (int): Zero-based photo index requested by the queue.
            timings (MediaReadTimings | None): Optional per-read transport
                timing record, or None for a direct compatibility call.
            gallery_id (int | None): Captured gallery identity, or None to use
                the viewer's gallery for a direct compatibility call.

        Returns:
            tuple[int, Image.Image | None, float]: Photo index, bounded RGB
                image and direct thumbnail decode duration in milliseconds.
        """
        if self._thumbnail_cancelled.is_set() or self._cancelled.is_set():
            return index, None, 0.0
        photo = photos[index]
        try:
            body, _mime = self.session.media_photo(
                gallery_id if gallery_id is not None else self.gallery.gallery_id,
                photo.image_id,
                thumbnail=True,
                cancelled=self._thumbnail_cancelled,
                cache_eligible=getattr(photo, 'cache_eligible', None),
                thumbnail_etag=getattr(photo, 'thumbnail_etag', None),
                thumbnail_verified_at=getattr(
                    photo, 'thumbnail_verified_at', None,
                ),
                preload_eligible=getattr(photo, 'preload_eligible', None),
                preload_thumbnail_etag=getattr(
                    photo, 'preload_thumbnail_etag', None,
                ),
                preload_verified_at=getattr(photo, 'preload_verified_at', None),
                timings=timings,
            )
        except LinkingMediaThrottled:
            raise
        except Exception as exc:
            if not self._thumbnail_cancelled.is_set() and not self._cancelled.is_set():
                _log_safe_media_failure(
                    "media-fetch", "thumbnail", exc,
                    diagnostic_callback=self._diagnostic_callback,
                )
            return index, None, 0.0
        if self._thumbnail_cancelled.is_set() or self._cancelled.is_set():
            return index, None, 0.0
        decode_started = time.perf_counter()
        try:
            image = self._prepare_thumbnail(body)
            return index, image, (time.perf_counter() - decode_started) * 1000.0
        except Exception as exc:
            if not self._thumbnail_cancelled.is_set() and not self._cancelled.is_set():
                _log_safe_media_failure(
                    "media-decode", "thumbnail", exc,
                    diagnostic_callback=self._diagnostic_callback,
                )
            return index, None, (time.perf_counter() - decode_started) * 1000.0
    def _run_http_thumbnail_batch(
        self, generation: int, gallery_id: int, indices: tuple[int, ...],
        photos: tuple[LinkedPhoto, ...], cancelled: threading.Event,
    ) -> None:
        """Stream one session batch into the bounded Tk result queue.

        Args:
            generation (int): Viewer generation captured before dispatch.
            gallery_id (int): Physical gallery owning the captured rows.
            indices (tuple[int, ...]): Ordered admitted tile indices.
            photos (tuple[LinkedPhoto, ...]): Matching immutable descriptors.
            cancelled (threading.Event): Captured viewer cancellation signal.

        Returns:
            None: Queues validated rasters and records one completion summary.
        """
        physical = MediaReadTimings()
        stats = ThumbnailBatchStats()
        started = time.monotonic()
        completed: set[int] = set()
        lookup = {photo.image_id: index for index, photo in zip(indices, photos)}
        outcome = 'ok'
        physical_complete = True
        decode_failures = 0
        deferred_seconds: int | None = None
        deferred_source: Literal[
            'transient', 'server_retry_after', 'unknown',
        ] = 'unknown'
        prior_operation_finished: threading.Event | None = None

        def publish(
            publication: ThumbnailPublication | LegacyThumbnailPublication,
            timings: MediaReadTimings, check_active: Callable[[], None],
        ) -> None:
            """Decode one result without Tk and wait safely for queue space.

            Args:
                publication (ThumbnailPublication | LegacyThumbnailPublication):
                    Session-owned proof or explicitly transient legacy result.
                timings (MediaReadTimings): Item-only cache observations.
                check_active (Callable[[], None]): Worker deadline/cancel fence.

            Returns:
                None: Transfers the raster to the GUI queue or closes it.
            """
            nonlocal decode_failures
            index = lookup[publication.photo.image_id]
            image: 'Image.Image | None' = None
            decode_ms = 0.0
            try:
                check_active()
                if publication.body is not None:
                    decode_started = time.perf_counter()
                    try:
                        image = self._prepare_thumbnail(publication.body)
                    except Exception as error:  # noqa: BLE001
                        decode_failures += 1
                        _log_safe_media_failure(
                            'media-decode', 'thumbnail', error,
                            diagnostic_callback=self._diagnostic_callback,
                        )
                    finally:
                        decode_ms = (time.perf_counter() - decode_started) * 1000.0
                check_active()
                result = _ThumbnailResult(
                    gallery_id, index, publication.photo, image, timings,
                    decode_ms, time.perf_counter(), publication,
                )
                while True:
                    check_active()
                    with self._thumbnail_result_lock:
                        if cancelled.is_set() or self._cancelled.is_set() or self._closed:
                            raise LinkingError('Thumbnail publication was cancelled.')
                        try:
                            self._events.put_nowait((generation, 'thumbnail_batch_item', result))
                        except queue.Full:
                            pass
                        else:
                            completed.add(index)
                            image = None
                            break
                    if cancelled.wait(LINKING_CANCELLATION_POLL_SECONDS):
                        raise LinkingError('Thumbnail publication was cancelled.')
            finally:
                self._observe_thumbnail_read(timings, decode_ms)
                if image is not None:
                    image.close()

        def retry_wait(seconds: int) -> None:
            """Forward an existing internal retry wait to the owning Tk drain.

            Args:
                seconds (int): Positive actual cooldown, or zero when it ended.
            Returns:
                None: A cancelled batch cannot publish a current wait notice.
            """
            if not cancelled.is_set():
                self._queue_load_wait(generation, seconds, True)

        try:
            self.session.media_thumbnails(
                gallery_id, photos, publish, cancelled,
                timings=physical, stats=stats,
                retry_wait=retry_wait,
            )
        except LinkingMediaThrottled as error:
            deferred_seconds = error.retry_after_seconds
            prior_operation_finished = error.operation_finished
            outcome = 'throttled'
        except LinkingThumbnailBatchDeferred as error:
            deferred_seconds = error.retry_after_seconds
            prior_operation_finished = error.operation_finished
            if physical.http_requests > 0:
                physical_complete = bool(
                    error.operation_finished is not None
                    and error.operation_finished.is_set()
                )
            deferred_source = (
                'unknown'
                if (error.cooldown_source == 'transient'
                    and error.operation_finished is None)
                else error.cooldown_source
            )
            outcome = (
                'deferred_transient'
                if deferred_source == 'transient'
                else 'deferred_wait'
            )
        except LinkingThumbnailProofChanged:
            outcome = 'proof_changed'
        except LinkingError as error:
            outcome = 'error'
            finished = getattr(error, 'operation_finished', None)
            physical_complete = finished is None or finished.is_set()
            if not cancelled.is_set() and not self._cancelled.is_set():
                _log_safe_media_failure(
                    'media-fetch', 'thumbnail', error,
                    diagnostic_callback=self._diagnostic_callback,
                )
        except Exception as error:  # noqa: BLE001
            outcome = 'error'
            if not cancelled.is_set() and not self._cancelled.is_set():
                _log_safe_media_failure(
                    'media-fetch', 'thumbnail', error,
                    diagnostic_callback=self._diagnostic_callback,
                )
        finally:
            if cancelled.is_set() or self._cancelled.is_set() or self._closed:
                outcome = 'cancelled'
            elapsed = time.monotonic() - started
            completion = _HttpBatchCompletion(
                generation, outcome, tuple(index for index in indices
                                           if index not in completed),
                stats, physical, elapsed, physical_complete, decode_failures,
                deferred_seconds, deferred_source, prior_operation_finished,
            )
            with self._thumbnail_result_lock:
                self._http_batch_completion = completion
    def _load_next_thumbnail(self, limit: int | None = None) -> None:
        """Fetch a bounded visible-first batch on the serialized worker.

        Args:
            limit (int | None): Remaining initial priority budget when one
                applies. None uses the current transport limit.

        Returns:
            None: One worker serially publishes up to the batch limit.
        """
        if (self._media_throttled or self._http_thumbnail_failure_latched
                or self._thumbnail_resume_after_id is not None
                or not self._thumbnail_pending
                or bool(self._warm_thumbnail_pending)
                or not self.grid_canvas.winfo_viewable()
                or self._thumbnail_cancelled.is_set() or self._closed
                or self._worker is not None
                or self._pending_detail_index is not None
                or self._pending_page_request is not None):
            return
        if not self.session.can_read_media():
            self.close()
            return
        use_http_batch = self.session.supports_thumbnail_batch() is True
        if use_http_batch:
            first = self._thumbnail_pending[0]
            try:
                use_http_batch = self.session.thumbnail_batch_preferred(
                    self.gallery.gallery_id, self._photos[first],
                ) is True
            except LinkingError:
                now = time.monotonic()
                if (self._thumbnail_proof_refresh_attempt_at is None
                        or now - self._thumbnail_proof_refresh_attempt_at
                        >= MEDIA_REVISION_POLL_MS / 1000.0):
                    self._thumbnail_proof_refresh_attempt_at = now
                    self._thumbnail_proof_refresh_count += 1
                    self._refresh_pending = True
                else:
                    self._thumbnail_pending.remove(first)
                    self._thumbnail_loaded.add(first)
                self._schedule_queue_poll()
                return
        # Keep the in-flight index queued until it resolves. Configure events
        # can otherwise enqueue the same visible tile again during a download.
        size_limit = MEDIA_THUMBNAIL_BATCH_SIZE
        if use_http_batch:
            size_limit = MEDIA_HTTP_BATCH_INITIAL_SIZE
            if self._http_thumbnail_group_healthy:
                read_size = self._thumbnail_batch_size
                size_limit = (
                    read_size() if read_size is not None
                    else THUMBNAIL_BATCH_DEFAULT_SIZE
                )
                if type(size_limit) is not int or size_limit not in THUMBNAIL_BATCH_CHOICES:
                    size_limit = THUMBNAIL_BATCH_DEFAULT_SIZE
        if limit is not None:
            size_limit = min(limit, size_limit)
        selected = self._thumbnail_pending[:max(0, size_limit)]
        if use_http_batch:
            eligible: list[int] = []
            for index in selected:
                try:
                    if self.session.thumbnail_batch_preferred(
                        self.gallery.gallery_id, self._photos[index],
                    ) is not True:
                        break
                except LinkingError:
                    break
                eligible.append(index)
            selected = eligible
        indices = tuple(selected)
        if not indices:
            return
        self._generation += 1
        generation = self._generation
        photos = self._photos
        gallery_id = self.gallery.gallery_id
        descriptors = tuple(photos[index] for index in indices)
        thumbnail_cancelled = self._thumbnail_cancelled
        session_cancelled = self._cancelled
        result_lock = self._thumbnail_result_lock

        if use_http_batch:
            self._thumbnail_batch_count += 1
            self._thumbnail_batch_indices = indices
            self._worker = threading.Thread(
                target=lambda: self._run_http_thumbnail_batch(
                    generation, gallery_id, indices, descriptors,
                    thumbnail_cancelled,
                ), name='linked-gallery-thumbnail-batch', daemon=True,
            )
            self._worker_kind = 'thumbnail_batch'
            self._worker.start()
            self._schedule_queue_poll()
            return

        def run_batch() -> None:
            """Read and publish each captured thumbnail in serial order.

            Returns:
                None: Bounded image results enter the queue with captured identity.
            """
            try:
                for index, descriptor in zip(indices, descriptors):
                    if (thumbnail_cancelled.is_set()
                            or session_cancelled.is_set()):
                        break
                    timings = MediaReadTimings()
                    decode_ms = 0.0
                    try:
                        photo_index, image, decode_ms = self._load_thumbnails(
                            photos, index, timings, gallery_id,
                        )
                    finally:
                        self._observe_thumbnail_read(timings, decode_ms)
                    if thumbnail_cancelled.is_set() or session_cancelled.is_set():
                        with self._thumbnail_metrics_lock:
                            self._thumbnail_cancelled_read_count += 1
                        if image is not None:
                            image.close()
                        break
                    result = _ThumbnailResult(
                        gallery_id, photo_index, descriptor, image, timings,
                        decode_ms, time.perf_counter(),
                    )
                    with result_lock:
                        if thumbnail_cancelled.is_set() or session_cancelled.is_set():
                            with self._thumbnail_metrics_lock:
                                self._thumbnail_cancelled_read_count += 1
                            if image is not None:
                                image.close()
                            break
                        try:
                            self._events.put_nowait((
                                generation, 'thumbnail_batch_item', result,
                            ))
                        except queue.Full:
                            if image is not None:
                                image.close()
                            break
                    if thumbnail_cancelled.is_set():
                        break
            except LinkingMediaThrottled:
                throttled_index = index
                with result_lock:
                    if (not session_cancelled.is_set()
                            and not thumbnail_cancelled.is_set()):
                        # Throttling replaces an item in the same batch, so
                        # its control marker fits the existing result bound.
                        self._events.put_nowait((
                            generation, 'thumbnail_batch_throttled',
                            throttled_index,
                        ))

        self._thumbnail_batch_count += 1
        self._thumbnail_batch_indices = indices
        self._worker = threading.Thread(
            target=run_batch,
            name='linked-gallery-thumbnail-batch',
            daemon=True,
        )
        self._worker_kind = 'thumbnail_batch'
        self._worker.start()
        self._schedule_queue_poll()
