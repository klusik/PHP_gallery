# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/viewer/session.py
# Module Type: Windows Client UI mixin
# Purpose: Own a cohesive gallery viewer interaction domain.
# Responsibilities: Preserve the native viewer lifecycle and media callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native gallery viewer behavior mixin."""

from .support import (
    ImageTk,
    LinkingError,
    MEDIA_AUDIO_POLL_MS,
    MEDIA_AUDIO_REAP_SECONDS,
    MEDIA_MCI_MODE_CAPACITY,
    MEDIA_SLIDESHOW_DEFAULT_SECONDS,
    MEDIA_SLIDESHOW_INTERVALS,
    MciSender,
    MediaReadTimings,
    NATIVE_MEDIA_MAX_BYTES,
    PhotoLightbox,
    ctypes,
    hashlib,
    load_winmm,
    os,
    queue,
    send_mci,
    signal,
    subprocess,
    sys,
    tempfile,
    threading,
    tk,
    uuid,
)

class NativeGallerySessionMixin:
    """Own this cohesive native gallery viewer behavior domain."""

    def _load_detail(self, index: int) -> bool:
        """Request a selected original and queue its constrained rendering.

        Args:
            index (int): Zero-based index in the visible photo page.

        Returns:
            bool: True when the detail is shown, started or queued; False
                when the viewer, index or live media permission is unavailable.
        """
        if self._closed or index < 0 or index >= len(self._photos):
            return False
        if not self.session.can_read_media():
            self.close()
            return False
        photo = self._photos[index]
        active_media_kind = self._worker_kind
        active_media_cancelled = self._active_media_cancelled
        same_active_media = bool(
            active_media_kind in ('detail', 'prefetch')
            and self._active_media_photo_id == photo.image_id
            and active_media_cancelled is not None
            and not active_media_cancelled.is_set()
        )
        same_active_detail = same_active_media and active_media_kind == 'detail'
        same_active_prefetch = (
            same_active_media and active_media_kind == 'prefetch'
        )
        if active_media_kind == 'thumbnail_batch':
            self._thumbnail_cancelled.set()
            self._generation += 1
        elif (active_media_kind in ('detail', 'prefetch', 'refresh')
              and not same_active_media
              and active_media_cancelled is not None):
            self._active_media_cancelled.set()
            self._generation += 1
            if active_media_kind == 'refresh':
                # Keep the requested refresh, but the user's newer selection
                # now leads it and its bounded adjacent-image preparation.
                self._refresh_pending = True
                self._refresh_manual_pending = False
        self._cancel_thumbnail_gui_slice()
        self._pending_detail_index = None
        self._pending_prefetch_ids.clear()
        if not same_active_detail:
            self._detail_serial += 1
        detail_serial = self._detail_serial
        self._slide_index = index
        self._ensure_lightbox()
        self._lightbox.present(
            ''.join(char if char.isprintable() else ' '
                    for char in photo.title).strip() or self._text('linking_media_open'),
            self._detail_position(index),
            can_previous=(index > 0 or bool(self._photos)),
            can_next=(index + 1 < len(self._photos)
                      or self._next_after is not None or bool(self._photos)),
        )
        self._bind_lightbox_shortcuts(self._lightbox._overlay)
        self.retry_button.configure(state='disabled')
        if same_active_detail:
            return True
        cached = (
            None if same_active_prefetch
            else self._cached_detail(photo.image_id)
        )
        if cached is not None:
            with self._thumbnail_metrics_lock:
                self._detail_media_metrics['detail_ram_hits'] += 1
            self._show_detail(
                detail_serial, photo.image_id, cached.copy(), None,
                self._cached_detail_encoded(photo.image_id),
            )
            self._dispatch_pending_work()
            return True
        if self._worker is not None:
            self._pending_detail_index = index
            return True

        operation_cancelled = threading.Event()
        self._active_media_cancelled = operation_cancelled
        self._active_media_photo_id = photo.image_id

        def operation() -> tuple[
            int, int, 'Image.Image', 'Image.Image | None', bytes | None,
        ]:
            """Fetch and normalize the selected image or safe preview.

            Returns:
                tuple[int, int, Image.Image, Image.Image | None, bytes | None]:
                    Selection, photo ID, display/cache rasters and original body.
            """
            timings = MediaReadTimings()
            try:
                image, body = self._read_prepared_detail(
                    photo, timings, cancelled=operation_cancelled,
                )
            finally:
                self._observe_detail_read('detail', timings)
            if operation_cancelled.is_set():
                image.close()
                raise LinkingError('Selected photo request was superseded.')
            private_descriptor = (
                photo.cache_eligible is not True
                and (self.session.can_read_admin_media()
                     or photo.cache_eligible is False
                     or photo.preload_eligible is not None)
            )
            cached_image: 'Image.Image | None' = image.copy()
            if (private_descriptor and timings.source != 'disk'
                    and not timings.cache_stored):
                cached_image.close()
                cached_image = None
            return detail_serial, photo.image_id, image, cached_image, body

        started = self._launch(
            'detail', operation,
            error_payload=(detail_serial, photo.image_id, None, None, None),
            operation_cancelled=operation_cancelled,
        )
        if not started and self._active_media_cancelled is operation_cancelled:
            self._active_media_cancelled = None
            self._active_media_photo_id = None
        return started
    def _ensure_lightbox(self) -> PhotoLightbox:
        """Create the shared photo overlay and its playlist toolbar actions.

        Returns:
            PhotoLightbox: Active overlay fenced to this viewer's live session.
        """
        if self._lightbox is None:
            session = self.session
            self._lightbox = PhotoLightbox(
                self.window,
                lambda key: self._text(f'linking_media_{key}'),
                self._move_lightbox,
                self._close_lightbox,
                self._main_content,
                can_publish=lambda: (
                    not self._closed and self.session is session and session.can_read_media()
                ),
                on_photo_resolved=self._on_lightbox_photo_resolved,
            )
            self._lightbox_track_buttons = (
                self._lightbox.add_action(
                    '♫‹', lambda: self._move_track(-1),
                    self._text('linking_media_previous'),
                ),
                self._lightbox.add_action(
                    '▶', self._toggle_audio_pause,
                    self._lightbox_track_tooltip,
                ),
                self._lightbox.add_action(
                    '♫›', lambda: self._move_track(1),
                    self._text('linking_media_next'),
                ),
            )
            self._bind_lightbox_shortcuts(self._lightbox._overlay)
            self._refresh_audio_button()
        return self._lightbox
    def _bind_lightbox_shortcuts(self, overlay: tk.Misc) -> None:
        """Route fullscreen keys through one tag on active overlay descendants.

        Args:
            overlay (tk.Misc): Existing overlay tree owned by PhotoLightbox.

        Returns:
            None: One local bindtag handles shortcuts before default button logic.
        """
        retained_widgets: list[tk.Misc] = []
        for widget in self._lightbox_key_widgets:
            try:
                if widget.winfo_exists():
                    retained_widgets.append(widget)
            except tk.TclError:
                pass
        self._lightbox_key_widgets = retained_widgets
        pending = [overlay]
        while pending:
            widget = pending.pop()
            try:
                tags = widget.bindtags()
                if self._lightbox_key_tag not in tags:
                    widget.bindtags((self._lightbox_key_tag, *tags))
                    self._lightbox_key_widgets.append(widget)
                pending.extend(widget.winfo_children())
            except tk.TclError:
                continue
        if not self._lightbox_key_bindings:
            for sequence in ('<KeyPress-f>', '<KeyPress-F>', '<KeyPress-F11>'):
                binding = self.window.bind_class(
                    self._lightbox_key_tag, sequence,
                    self._on_fullscreen, add='+',
                )
                if binding is not None:
                    self._lightbox_key_bindings.append((sequence, binding))
    def _unbind_lightbox_shortcuts(self) -> None:
        """Remove this viewer's lightbox bindtag from its retained widgets.

        Returns:
            None: No fullscreen callback or bindtag outlives its viewer.
        """
        for sequence, binding in self._lightbox_key_bindings:
            try:
                self.window.unbind_class(self._lightbox_key_tag, sequence)
                self.window.deletecommand(binding)
            except tk.TclError:
                pass
        self._lightbox_key_bindings.clear()
        for widget in self._lightbox_key_widgets:
            try:
                widget.bindtags(tuple(
                    tag for tag in widget.bindtags()
                    if tag != self._lightbox_key_tag
                ))
            except tk.TclError:
                pass
        self._lightbox_key_widgets.clear()
    def _show_detail(
        self,
        detail_serial: int,
        image_id: int,
        display_image: 'Image.Image | None',
        cached_image: 'Image.Image | None',
        encoded: bytes | None,
        decode_reason: str | None = None,
    ) -> None:
        """Render one normalized detail image on Tk's owning thread.

        Args:
            detail_serial (int): Selection generation captured by the request.
            image_id (int): Exact gallery photo represented by the data.
            display_image (Image.Image | None): Worker-prepared RGB image
                transferred to the lightbox, or None after failure.
            cached_image (Image.Image | None): Independent prepared copy retained
                only when the worker response is fresh and usable.
            encoded (bytes | None): Complete source body retained for HDR rendering.
            decode_reason (str | None): Fixed decoder outcome for a refused source.

        Returns:
            None: The lightbox owns the rendered image reference.
        """
        if detail_serial != self._detail_serial:
            if display_image is not None:
                display_image.close()
            if cached_image is not None:
                cached_image.close()
            return
        if cached_image is not None:
            if encoded is not None:
                self._remember_detail(image_id, cached_image, encoded)
            else:
                cached_image.close()
        if display_image is None or ImageTk is None:
            if display_image is not None:
                display_image.close()
            if self._lightbox is not None:
                error_key = (
                    'linking_media_decode_error'
                    if decode_reason in (
                        'decode-failed', 'pixel-limit', 'body-limit',
                    ) else 'linking_media_image_error'
                )
                self._lightbox.show_error(self._text(error_key))
            return
        photo = self._photos[self._slide_index]
        if photo.image_id != image_id:
            display_image.close()
            return
        latitude = getattr(photo, 'latitude', None)
        longitude = getattr(photo, 'longitude', None)
        location = (
            (float(latitude), float(longitude))
            if (type(latitude) in (int, float) and type(longitude) in (int, float))
            else None
        )
        self._lightbox.show_prepared_image(
            display_image, location=location, encoded=encoded,
        )
        self._queue_adjacent_prefetch(self._slide_index)
    def _on_lightbox_photo_resolved(self, displayed: bool) -> None:
        """Begin a slide interval after the current photo or error is visible.

        Args:
            displayed (bool): True when the Lightbox attached the current photo.

        Returns:
            None: Advances a running slideshow, or stops when its sole
                photo could not be displayed.
        """
        if self._closed or not self._slideshow_running or self._slideshow_id is not None:
            return
        if displayed or len(self._photos) > 1:
            self._schedule_slide()
        else:
            self._stop_slideshow()
    def _detail_position(self, index: int) -> str:
        """Return the visible ordinal across the accumulated album sequence.

        Args:
            index (int): Zero-based photo index on the active page.

        Returns:
            str: Album-wide ordinal without internal server identifiers.
        """
        return f'{index + 1} / {len(self._photos)}'
    def _move_lightbox(self, delta: int) -> None:
        """Navigate one photo from the lightbox's directional controls.

        Args:
            delta (int): Directional step, either negative or positive one.

        Returns:
            None: The viewer starts the corresponding foreground detail read.
        """
        self._prefetch_direction = 1 if delta > 0 else -1
        target = self._slide_index + delta
        if 0 <= target < len(self._photos):
            self._select_photo(target)
        elif delta < 0:
            if self._worker_kind in ('page', 'more', 'slide_more'):
                self._select_first_after_page = False
                self._wrap_after_metadata = -1
                return
            if self._next_after is None:
                self._select_photo(len(self._photos) - 1)
            else:
                self._wrap_after_metadata = -1
                self._load_more()
        elif self._next_after is not None:
            if self._worker_kind in ('page', 'more', 'slide_more'):
                self._select_first_after_page = True
            else:
                self._load_more(select_first=True)
        elif self._photos:
            self._select_photo(0)
    def _close_lightbox(self) -> None:
        """Dismiss the detail panel without closing its gallery viewer.

        Returns:
            None: Grid focus and interrupted viewport admission resume in the
                parent window; active detail/prefetch reads lose their slot.
        """
        self._detail_serial += 1
        if (self._worker_kind in ('detail', 'prefetch')
                and self._active_media_cancelled is not None):
            self._active_media_cancelled.set()
            self._generation += 1
        self._pending_detail_index = None
        self._pending_prefetch_ids.clear()
        if self._prefetch_edge_direction:
            self._pending_page_request = None
        self._select_first_after_page = False
        self._wrap_after_metadata = 0
        self._prefetch_edge_direction = 0
        self._stop_slideshow()
        if (not self._closed
                and 0 <= self._slide_index < len(self._tile_buttons)):
            self._tile_buttons[self._slide_index].focus_set()
        if not self._closed:
            self._on_grid_viewport(None)
    def _on_escape(self, _event: tk.Event) -> str | None:
        """Route Escape through contextual panels while keeping Photos mounted.

        Args:
            _event (tk.Event): Key event received by the viewer toplevel.

        Returns:
            str | None: Break marker when a contextual surface consumes Escape.
        """
        if self._music_panel is not None:
            self._close_music_panel()
            self.music_button.focus_set()
            return 'break'
        if self._lightbox is not None and self._lightbox.visible:
            if not self._lightbox.escape():
                self._lightbox.dismiss()
            return 'break'
        return None
    def _retry_current(self) -> None:
        """Retry the throttled or failed current media operation.

        Returns:
            None: The user action resumes one selected foreground request.
        """
        indicator = getattr(self, '_load_progress', None)
        if indicator is not None:
            indicator.rearm()
        self.retry_button.configure(state='disabled')
        self._media_throttled = False
        self._http_thumbnail_failure_latched = False
        self._thumbnail_cancelled.clear()
        self.status.pack_forget()
        if self._retry_kind == 'detail' and self._photos:
            self._load_detail(self._slide_index)
        elif self._retry_kind == 'thumbnail':
            for index in self._http_batch_retry_indices:
                self._thumbnail_loaded.discard(index)
                if (index in self._admitted_thumbnail_indices
                        and index not in self._thumbnail_pending):
                    self._thumbnail_pending.append(index)
            self._http_batch_retry_indices = ()
            self._dispatch_pending_work()
        elif self._page_request_mode == 'initial':
            self._load_first_page()
        else:
            self._start_page_after(self._page_request_cursor)
    def _toggle_slideshow(self) -> None:
        """Start or stop the user-requested slideshow timer.

        Returns:
            None: The timer advances photos only while explicitly started.
        """
        if self._slideshow_running:
            self._stop_slideshow()
            return
        if not self._photos:
            return
        if not self.session.can_read_media():
            self.close()
            return
        self._slideshow_running = True
        self._slideshow_music_attempted = self._audio_index is not None
        self._slideshow_started_music = False
        self._ensure_lightbox()
        if not self._lightbox.visible and self._photos:
            self._slide_index = -1
        self._lightbox.enter_fullscreen()
        if self._slide_index + 1 >= len(self._photos) and self._next_after is None:
            self._slide_index = -1
        self.slide_button.configure(text='Ⅱ')
        self._slideshow_tooltip.set(self._text('linking_media_slideshow_stop'))
        self._advance_slide()
        if (self._audio_index is None and self._presentation is not None
                and self._presentation.music
                and self._tracks):
            self._slideshow_music_attempted = True
            self._toggle_track()
            self._slideshow_started_music = self._audio_index is not None
    def _stop_slideshow(self) -> None:
        """Stop slideshow advancement and cancel its one pending Tk timer.

        Returns:
            None: Timer and slideshow-owned music stop; prior background music stays.
        """
        self._slideshow_running = False
        if self._slideshow_id is not None:
            try:
                self.window.after_cancel(self._slideshow_id)
            except tk.TclError:
                pass
            self._slideshow_id = None
        if self._slideshow_started_music:
            self._slideshow_started_music = False
            self._stop_audio()
        if not self._closed:
            self.slide_button.configure(
                text='▶',
            )
            self._slideshow_tooltip.set(
                self._text('linking_media_slideshow_start'),
            )
    def _slideshow_delay_ms(self) -> int:
        """Return the authorized interval or the established five-second pace.

        Returns:
            int: Delay in milliseconds for one manually started slide.
        """
        raw = (self._presentation.interval_seconds
               if self._presentation is not None and self._presentation.enabled
               else MEDIA_SLIDESHOW_DEFAULT_SECONDS)
        seconds = (raw if type(raw) is int and raw in MEDIA_SLIDESHOW_INTERVALS
                   else MEDIA_SLIDESHOW_DEFAULT_SECONDS)
        return seconds * 1000
    def _schedule_slide(self) -> None:
        """Schedule the next manual slideshow advance.

        Returns:
            None: Tk advances one image after the server-provided interval.
        """
        if self._closed or not self._slideshow_running:
            return
        self._slideshow_id = self.window.after(
            self._slideshow_delay_ms(),
            self._advance_slide,
        )
    def _advance_slide(self) -> None:
        """Show the next photo, wrapping continuously while still active.

        Returns:
            None: Explicit playback loops until the user stops it or closes it.
        """
        self._slideshow_id = None
        if self._closed or not self._slideshow_running or not self._photos:
            self._stop_slideshow()
            return
        next_index = self._slide_index + 1
        self._prefetch_direction = 1
        if next_index >= len(self._photos):
            if self._next_after is None:
                self._select_photo(0, keep_slideshow=True)
            else:
                self._load_more(automatic=True)
            return
        if not self._load_detail(next_index):
            self._stop_slideshow()
    def _toggle_track(self) -> None:
        """Start an explicitly selected playlist position or stop playback.

        Returns:
            None: Queues the selected track only after an explicit Play.
        """
        if self._audio_index is not None:
            self._stop_audio()
            return
        if (not self._tracks or not self.session.can_read_presentation()
                or self._presentation is None or not self._presentation.music):
            return
        if sys.platform not in ('win32', 'darwin'):
            self._audio_feedback('linking_media_audio_unavailable')
            return
        selected = (
            self.track_menu.current() if self.track_menu is not None
            else self._selected_track_index
        )
        if not 0 <= selected < len(self._tracks):
            return
        self._selected_track_index = selected
        self._audio_index = selected
        self._audio_pending = selected
        self._audio_cancelled = threading.Event()
        self._refresh_audio_button()
        self._dispatch_audio_prefetch()
    def _move_track(self, delta: int) -> None:
        """Select an adjacent track and resume playback when it was active.

        Args:
            delta (int): Playlist step; negative is previous and positive next.
        Returns:
            None: The bounded playlist wraps at either end.
        """
        if not self._tracks:
            return
        was_active = self._audio_index is not None
        if was_active:
            self._stop_audio()
        self._selected_track_index = (
            self._selected_track_index + delta
        ) % len(self._tracks)
        if self.track_menu is not None:
            self.track_menu.current(self._selected_track_index)
        self._refresh_audio_button()
        if was_active:
            self._toggle_track()
    def _toggle_audio_pause(self) -> None:
        """Pause/resume the current track or start its selected position.

        Returns:
            None: Native playback position is preserved across Pause and Resume.
        """
        if self._audio_index is None:
            self._toggle_track()
            return
        self._set_audio_paused(not self._audio_paused)
    def _set_audio_paused(self, paused: bool) -> None:
        """Apply a requested pause state to the currently owned audio backend.

        Args:
            paused (bool): Whether native playback should hold its position.
        Returns:
            None: Polling stops while paused and resumes with the player.
        """
        if self._audio_index is None or self._audio_paused == paused:
            return
        self._audio_paused = paused
        try:
            self._apply_audio_pause()
            if paused:
                if self._audio_poll_id is not None:
                    self.window.after_cancel(self._audio_poll_id)
                    self._audio_poll_id = None
            elif self._audio_process is not None or self._audio_alias is not None:
                self._audio_poll_id = self.window.after(
                    MEDIA_AUDIO_POLL_MS, self._poll_audio,
                )
        except (OSError, tk.TclError):
            self._audio_failed()
            return
        self._refresh_audio_button()
    def _apply_audio_pause(self) -> None:
        """Send Pause or Resume to the owned MCI player or afplay process.

        Returns:
            None: The current player holds or resumes its exact position.
        """
        process = self._audio_process
        alias = self._audio_alias
        if process is not None:
            process.send_signal(signal.SIGSTOP if self._audio_paused else signal.SIGCONT)
        elif alias is not None and self._mci is not None:
            command = 'pause' if self._audio_paused else 'resume'
            self._mci_command(self._mci, f'{command} {alias}')
    def _dispatch_audio_prefetch(self) -> bool:
        """Dispatch only the explicit selection or its immediate successor.

        Returns:
            bool: True when a track request starts; False when no current track
                is pending, access is cancelled or another worker must finish.
        """
        index = self._audio_pending
        cancelled = self._audio_cancelled
        if (self._worker_kind == 'thumbnail_batch' and index is not None
                and cancelled is not None and not cancelled.is_set()):
            self._thumbnail_cancelled.set()
            self._generation += 1
        if (self._worker is not None or index is None or cancelled is None
                or cancelled.is_set() or self._closed):
            return False
        track = self._tracks[index]

        def operation() -> tuple[threading.Event, int, bytes | None]:
            """Fetch and independently check the selected track's exact bytes.

            Returns:
                tuple[threading.Event, int, bytes | None]: Owner, playlist index
                    and verified bytes, or no bytes after cancellation.
            """
            if cancelled.is_set():
                return cancelled, index, None
            body = self.session.media_track(
                self.gallery.gallery_id, track, cancelled=cancelled,
            )
            if (len(body) > NATIVE_MEDIA_MAX_BYTES
                    or len(body) != track.size
                    or hashlib.sha256(body).hexdigest() != track.sha256):
                raise ValueError('Track integrity check failed')
            return cancelled, index, None if cancelled.is_set() else body

        if not self._launch(
            'audio_track', operation, error_payload=(cancelled, index, None),
        ):
            return False
        self._audio_pending = None
        return True
    def _accept_audio_track(
        self, cancelled: threading.Event, index: int, body: bytes | None,
    ) -> None:
        """Prepare one current or next track and start only the current track.

        Args:
            cancelled (threading.Event): Cancellation owner captured at Play.
            index (int): Position of this verified track in the current playlist.
            body (bytes | None): Verified track bytes, or None on failure.

        Returns:
            None: Current-track failure stops playback. Successor failure
                is surfaced when the current track finishes.
        """
        if cancelled is not self._audio_cancelled or cancelled.is_set():
            return
        if not self.session.can_read_presentation():
            self._audio_failed()
            return
        current = index == self._audio_index
        if body is not None:
            track = self._tracks[index]
            suffix = '.mp3' if track.mime == 'audio/mpeg' else '.wav'
            try:
                with tempfile.NamedTemporaryFile(
                    prefix='php-gallery-track-', suffix=suffix, delete=False,
                ) as stream:
                    self._audio_prepared = (index, stream.name)
                    stream.write(body)
            except (OSError, ValueError):
                self._discard_prepared_audio()
            else:
                if current:
                    self._start_prepared_audio()
                return
        if current:
            self._audio_failed()
        else:
            self._audio_successor_failed = True
    def _start_prepared_audio(self) -> None:
        """Transfer an already prepared file to the native audio player.

        Returns:
            None: Starts or pauses the track and requests one next track.
        """
        prepared = self._audio_prepared
        if prepared is None or prepared[0] != self._audio_index:
            return
        index, path = prepared
        self._audio_prepared = None
        self._audio_path = path
        try:
            if sys.platform == 'darwin':
                self._audio_process = subprocess.Popen(
                    ['/usr/bin/afplay', path], stdin=subprocess.DEVNULL,
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                )
            else:
                if '"' in path:
                    raise ValueError('Unsupported temporary path')
                alias = 'pglink' + uuid.uuid4().hex
                mci = self._load_winmm()
                media_type = 'mpegvideo' if path.endswith('.mp3') else 'waveaudio'
                self._mci_command(mci, f'open "{path}" type {media_type} alias {alias}')
                self._audio_alias = alias
                self._mci_command(mci, f'play {alias}')
            self._selected_track_index = index
            self._refresh_audio_button()
            if self._audio_paused:
                self._apply_audio_pause()
            else:
                self._audio_poll_id = self.window.after(
                    MEDIA_AUDIO_POLL_MS, self._poll_audio,
                )
            next_index = index + 1
            if next_index < len(self._tracks):
                self._audio_pending = next_index
        except (OSError, ValueError, tk.TclError):
            self._audio_failed()
    def _poll_audio(self) -> None:
        """Observe native playback without blocking or querying an unrelated player.

        Returns:
            None: Advances to prepared audio on completion or stops on failure.
        """
        self._audio_poll_id = None
        index = self._audio_index
        if index is None or self._audio_paused:
            return
        if not self.session.can_read_presentation():
            self._audio_failed()
            return
        try:
            if self._audio_process is not None:
                result = self._audio_process.poll()
                if result not in (None, 0):
                    raise OSError('Native audio player failed')
                playing = result is None
            elif self._audio_alias is not None and self._mci is not None:
                mode = ctypes.create_unicode_buffer(MEDIA_MCI_MODE_CAPACITY)
                output = ctypes.cast(mode, ctypes.c_wchar_p)
                if self._mci(f'status {self._audio_alias} mode', output, len(mode), None):
                    raise OSError('Could not read native audio state')
                state = mode.value.lower()
                if state not in ('playing', 'seeking', 'stopped'):
                    raise OSError('Unexpected native audio state')
                playing = state != 'stopped'
            else:
                raise OSError('Native audio player is unavailable')
            if playing:
                self._audio_poll_id = self.window.after(MEDIA_AUDIO_POLL_MS, self._poll_audio)
                return
            if self._audio_successor_failed:
                self._audio_failed()
                return
            self._release_audio_output()
            next_index = index + 1
            if next_index >= len(self._tracks):
                self._stop_audio()
                return
            self._audio_index = next_index
            self._start_prepared_audio()
            self._dispatch_pending_work()
        except (OSError, tk.TclError):
            self._audio_failed()
    def _refresh_audio_button(self) -> None:
        """Refresh track selection and fullscreen Play/Pause availability.

        Returns:
            None: Updates the chooser and compact lightbox track controls.
        """
        active = self._audio_index is not None
        available = bool(
            self._tracks and self._presentation is not None
            and self._presentation.music
            and self.session.can_read_presentation()
        )
        try:
            if self.track_menu is not None:
                self.track_menu.configure(state='disabled' if active else 'readonly')
                self.track_menu.current(
                    min(self._selected_track_index, len(self._tracks) - 1),
                )
            if self.panel_track_button is not None:
                self.panel_track_button.configure(
                    text='■' if active else '▶',
                    state='normal',
                )
                self._panel_track_tooltip.set(self._text(
                    'linking_media_stop' if active else 'linking_media_play',
                ))
            if self._lightbox_track_buttons:
                tooltip_key = (
                    'linking_media_resume'
                    if active and self._audio_paused else
                    'linking_media_pause' if active else 'linking_media_play'
                )
                self._lightbox_track_tooltip.set(self._text(tooltip_key))
                self._lightbox_track_buttons[0].configure(
                    state='normal' if available else 'disabled',
                )
                self._lightbox_track_buttons[1].configure(
                    text='▶' if self._audio_paused or not active else 'Ⅱ',
                    state='normal' if available else 'disabled',
                )
                self._lightbox_track_buttons[2].configure(
                    state='normal' if available else 'disabled',
                )
        except tk.TclError:
            pass
    def _audio_feedback(self, key: str) -> None:
        """Show localized playback feedback in the viewer status surface.

        Args:
            key (str): Localized playback status key.

        Returns:
            None: The message is visible even after photos have loaded.
        """
        self.status.pack(anchor='w', pady=(4, 8))
        self.status.configure(text=self._text(key))
    def _audio_failed(self) -> None:
        """Cancel failed playback and surface the localized failure.

        Returns:
            None: Native output and prepared playback files are released.
        """
        self._stop_audio()
        self._audio_feedback('linking_media_audio_error')
    def _load_winmm(self) -> MciSender:
        """Load winmm from the OS-reported System32 directory only.

        Returns:
            MciSender: Typed Unicode MCI command function.

        Raises:
            OSError: The system directory or native library cannot be resolved.
        """
        self._winmm, mci = load_winmm()
        self._mci = mci
        return mci
    def _mci_command(self, mci: MciSender, command: str) -> None:
        """Send one bounded command to the current Windows MCI device.

        Args:
            mci (MciSender): Windows winmm MCI command function.
            command (str): Locally constructed command and owned alias.

        Returns:
            None: Raises OSError if winmm rejects the command.
        """
        send_mci(mci, command)
    def _release_audio_output(self) -> None:
        """Close only this viewer's native player and unlink its current file.

        Returns:
            None: The next prepared track stays available for natural handoff.
        """
        alias, self._audio_alias = self._audio_alias, None
        if alias is not None and self._mci is not None:
            for command in (f'stop {alias}', f'close {alias}'):
                try:
                    self._mci(command, None, 0, None)
                except OSError:
                    pass
        self._mci = None
        self._winmm = None
        process, self._audio_process = self._audio_process, None
        if process is not None and process.poll() is None:
            try:
                process.kill()
                process.wait(timeout=MEDIA_AUDIO_REAP_SECONDS)
            except (OSError, subprocess.TimeoutExpired):
                pass
        path, self._audio_path = self._audio_path, None
        if path is not None:
            try:
                os.unlink(path)
            except OSError:
                pass
    def _stop_audio(self) -> None:
        """Cancel one playlist run and discard its current and next files.

        Returns:
            None: Late worker results cannot restart playback.
        """
        if self._audio_cancelled is not None:
            self._audio_cancelled.set()
            self._audio_cancelled = None
        self._audio_index = None
        self._audio_paused = False
        self._audio_pending = None
        self._audio_successor_failed = False
        if self._audio_poll_id is not None:
            try:
                self.window.after_cancel(self._audio_poll_id)
            except tk.TclError:
                pass
            self._audio_poll_id = None
        self._release_audio_output()
        self._discard_prepared_audio()
        self._refresh_audio_button()
    def _discard_prepared_audio(self) -> None:
        """Unlink the owned successor or a file left by a failed write.

        Returns:
            None: Releases only the file owned by the preparation slot.
        """
        prepared, self._audio_prepared = self._audio_prepared, None
        if prepared is not None:
            try:
                os.unlink(prepared[1])
            except OSError:
                pass
    def close(self, *, destroy_frame: bool = True) -> None:
        """Cancel owned work, stop playback, and optionally destroy the frame.

        Args:
            destroy_frame (bool): Whether this call should destroy its child frame.

        Returns:
            None: Work stops and Tk-owned cycles release on the owner thread.
        """
        if self._closed:
            return
        self._closed = True
        self._cancel_thumbnail_resume()
        self._cancelled.set()
        if self._active_media_cancelled is not None:
            self._active_media_cancelled.set()
        self._active_media_photo_id = None
        self._thumbnail_disk_lane.invalidate(close=True)
        self._thumbnail_cancelled.set()
        self._cancel_grid_viewport_callback()
        self._cancel_thumbnail_gui_slice()
        if self._revision_timer is not None:
            try:
                self.window.after_cancel(self._revision_timer)
            except tk.TclError:
                pass
            self._revision_timer = None
        self._generation += 1
        if self._after_id is not None:
            try:
                self.window.after_cancel(self._after_id)
            except tk.TclError:
                pass
        self._stop_slideshow()
        self._stop_audio()
        self._close_music_panel()
        progress_lock = getattr(self, '_load_progress_lock', None)
        if progress_lock is not None:
            with progress_lock:
                self._pending_load_progress = None
                self._pending_load_wait = None
        indicator = getattr(self, '_load_progress', None)
        if indicator is not None:
            indicator.close()
        if self._lightbox is not None:
            self._unbind_lightbox_shortcuts()
            self._lightbox.dismiss()
            self._lightbox.close()
        self._photos = ()
        self._view_order = None
        self._tracks = ()
        self._presentation = None
        self._pending_detail_index = None
        self._pending_page_request = None
        self._pending_prefetch_ids.clear()
        for _image_id, (_fetched_at, image, _encoded, _size, _proof) in (
            self._detail_cache.items()
        ):
            image.close()
        self._detail_cache.clear()
        self._detail_cache_bytes = 0
        canvas_grid = getattr(self, '_canvas_grid', None)
        if canvas_grid is not None:
            canvas_grid.close()
        if self._owns_thumbnail_memory:
            self._thumbnail_memory.clear()
        for canonical in self._canonical_thumbnail_images.values():
            canonical.close()
        self._canonical_thumbnail_images.clear()
        self._thumbnail_image_release_count += sum(
            image is not None for image in self._photo_images
        )
        self._photo_images.clear()
        self._thumbnail_loaded.clear()
        self._thumbnail_pending.clear()
        self._visible_thumbnail_indices.clear()
        self._admitted_thumbnail_indices.clear()
        self._tile_tooltips.clear()
        self._tile_titles.clear()
        self._tile_buttons.clear()
        with self._thumbnail_result_lock:
            while not self._events.empty():
                try:
                    _generation, kind, result = self._events.get_nowait()
                    self._release_worker_result(result, kind)
                except queue.Empty:
                    break
        for sequence, identifier in self._window_bindings:
            if identifier is not None:
                try:
                    self.window.unbind(sequence, identifier)
                except tk.TclError:
                    pass
        self._window_bindings.clear()
        if destroy_frame:
            try:
                self.frame.destroy()
            except tk.TclError:
                pass
        # A cancelled worker may still hold a reference to this controller.
        # Release its Tcl variables while the Tk owner still controls teardown.
        if getattr(self.frame, '_gallery_viewer_controller', None) is self:
            del self.frame._gallery_viewer_controller
        self._context_menu = None
        self._lightbox = None
        del self._panel_track_tooltip
        del self._slideshow_tooltip
        del self._lightbox_track_tooltip
        self._lightbox_track_buttons = ()
    def _on_destroy(self, event: tk.Event) -> None:
        """Release viewer resources when its own child frame is destroyed.

        Args:
            event (tk.Event): Tk destruction event for this frame or a child.

        Returns:
            None: Cleanup starts only for this viewer frame's destroy event.
        """
        if event.widget is self.frame and not self._closed:
            self.close(destroy_frame=False)
