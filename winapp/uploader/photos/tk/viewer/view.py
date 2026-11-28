# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/viewer/view.py
# Module Type: Windows Client UI mixin
# Purpose: Own a cohesive gallery viewer interaction domain.
# Responsibilities: Preserve the native viewer lifecycle and media callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native gallery viewer behavior mixin."""

from .support import (
    Image,
    ImageTk,
    Callable,
    CanvasGalleryGrid,
    GALLERY_TILE_BORDER_INSET,
    GalleryLoadProgress,
    LIGHTBOX_ZOOM_STEP,
    Literal,
    MEDIA_FULLSCREEN_COMMAND_MODIFIERS,
    MEDIA_FULLSCREEN_WINDOWS_COMMAND_MODIFIERS,
    MEDIA_GRID_GUTTER,
    MEDIA_MUSIC_PANEL_WIDTH,
    MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
    MEDIA_THUMBNAIL_GUI_SLICE_SECONDS,
    MEDIA_THUMBNAIL_GUI_SLICE_SIZE,
    MEDIA_THUMBNAIL_OVERSCAN_ROWS,
    MEDIA_THUMBNAIL_PLACEHOLDER,
    MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
    MEDIA_VIEWPORT_COALESCE_MS,
    MediaReadTimings,
    NATIVE_GALLERY_VIEWER_LOGGER,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    RaisedDialogSurface,
    _log_safe_photos_observation,
    ensure_component_theme,
    format_safe_photos_shortcut,
    gallery_card_dimensions,
    gallery_grid_theme,
    icon_button,
    logging,
    normalize_card_format,
    sys,
    time,
    tk,
    tr,
    ttk,
    vertical_scroll_delta,
)

class NativeGalleryViewMixin:
    """Own this cohesive native gallery viewer behavior domain."""

    def _text(self, key: str) -> str:
        """Return a translated viewer label for the current UI language.

        Args:
            key (str): Stable `linking_media_` translation key.

        Returns:
            str: Localized label with the translation module's English fallback.
        """
        try:
            current = self.language()
        except Exception:
            current = 'en'
        return tr(current, key)
    def _build_widgets(self) -> None:
        """Create the compact gallery and presentation controls.

        Returns:
            None: Widgets are installed in the returned child frame.
        """
        ensure_component_theme(self.window)
        shell = ttk.Frame(self.frame, padding=12, style='Card.TFrame')
        shell.pack(fill='both', expand=True)
        gallery_title = ''.join(
            char if char.isprintable() else ' '
            for char in self.gallery.title
        ).strip()[:160]
        if self._show_title:
            ttk.Label(
                shell,
                text=gallery_title or self._text('linking_media_title'),
                style='Section.TLabel',
            ).pack(anchor='w')
        self.status = ttk.Label(shell, text='')

        body = ttk.Frame(shell, style='Card.TFrame')
        body.pack(fill='both', expand=True)
        style = ttk.Style(self.window)
        surface = style.lookup('Card.TFrame', 'background') or '#253443'
        ink = style.lookup('TLabel', 'foreground') or '#F2F6FA'
        focus = style.lookup('Primary.TButton', 'background') or '#08AED0'
        right = ttk.Frame(body, style='Card.TFrame')
        right.pack(fill='both', expand=True)
        self.grid_canvas = tk.Canvas(
            right, highlightthickness=0, background=surface,
        )
        self.grid_scrollbar = ttk.Scrollbar(
            right, orient='vertical', command=self.grid_canvas.yview,
        )
        self.grid_canvas.configure(yscrollcommand=self._set_grid_scrollbar)
        self.grid_canvas.pack(side='left', fill='both', expand=True)
        self.grid_scrollbar.pack(side='right', fill='y')
        self.grid_canvas.bind('<Configure>', self._on_grid_resize)
        self.grid_canvas.bind('<Configure>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<Map>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<MouseWheel>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<Button-4>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<Button-5>', self._on_grid_viewport, add='+')
        self.grid_canvas.bind('<MouseWheel>', self._on_grid_wheel, add='+')
        self._bind_precise_scroll(self.grid_canvas)
        self.grid_canvas.bind('<Button-4>', self._on_grid_wheel, add='+')
        self.grid_canvas.bind('<Button-5>', self._on_grid_wheel, add='+')
        if self._canvas_grid_enabled:
            self.grid_canvas.configure(takefocus=True)
            self.grid_frame: ttk.Frame | None = None
            self.grid_window: int | None = None
            self._canvas_grid = CanvasGalleryGrid(
                self.grid_canvas,
                gallery_id=self.gallery.gallery_id,
                gutter=MEDIA_GRID_GUTTER,
                placeholder=MEDIA_THUMBNAIL_PLACEHOLDER,
                theme=gallery_grid_theme(self.grid_canvas),
                select=self.select_photo,
                range_select=self.select_photo_range,
                toggle=self._toggle_canvas_photo_selection,
                open_photo=self._open_grid_photo,
                key=self._tile_key,
                context=self._canvas_context,
                viewport=self._canvas_viewport_changed,
            )
        else:
            self.grid_frame = ttk.Frame(self.grid_canvas, style='Card.TFrame')
            self.grid_window = self.grid_canvas.create_window(
                (0, 0), window=self.grid_frame, anchor='nw',
            )
            self.grid_frame.bind('<Configure>', self._on_grid_configure)
            self.grid_frame.bind('<MouseWheel>', self._on_grid_wheel, add='+')
            self._bind_precise_scroll(self.grid_frame)
            self.grid_frame.bind('<Button-4>', self._on_grid_wheel, add='+')
            self.grid_frame.bind('<Button-5>', self._on_grid_wheel, add='+')
            for sequence in ('<Button-3>', '<Button-2>', '<Control-Button-1>'):
                self.grid_canvas.bind(sequence, self._on_empty_context, add='+')
                self.grid_frame.bind(sequence, self._on_empty_context, add='+')

        # Attach after the renderer bindings and native grid child. The
        # progress geometry listener must survive alongside grid Configure.
        self._load_progress = GalleryLoadProgress(self.grid_canvas, self._text)

        actions = ttk.Frame(shell, style='Card.TFrame')
        actions.pack(fill='x', pady=(10, 0))
        self.retry_button = icon_button(
            actions, 'refresh', self._retry_current,
            self._text('linking_media_retry'),
        )
        self.retry_button.configure(state='disabled')
        self.retry_button.pack(side='left', padx=(8, 0))
        self.retry_button.pack_forget()
        self.slide_button = icon_button(
            actions, 'play', self._toggle_slideshow,
            self._slideshow_tooltip,
        )
        self.slide_button.configure(state='disabled')
        self.slide_button.pack(side='left', padx=(8, 0))
        self.music_button = icon_button(
            actions,
            '♫',
            self._toggle_music_panel,
            self._text('linking_media_presentation'),
        )
        self.music_button.pack(side='left', padx=(8, 0))
    def _bind_window_keys(self) -> None:
        """Bind viewer navigation keys additively on the owning main window.

        Returns:
            None: Only handlers owned by this embedded viewer are registered.
        """
        key_callbacks: tuple[tuple[str, Callable[[tk.Event], str | None]], ...] = (
            ('<Left>', self._on_left),
            ('<Right>', self._on_right),
            ('<F11>', self._on_fullscreen),
            ('<f>', self._on_fullscreen),
            ('<F>', self._on_fullscreen),
            ('<plus>', self._on_zoom_in),
            ('<equal>', self._on_zoom_in),
            ('<minus>', self._on_zoom_out),
            ('<0>', self._on_fit),
        )
        for sequence, callback in key_callbacks:
            identifier = self.window.bind(sequence, callback, add='+')
            self._window_bindings.append((sequence, identifier))
    def _on_left(self, _event: tk.Event) -> str | None:
        """Move to the previous gallery photo when this view is mounted.

        Args:
            _event (tk.Event): Main-window Left key event.

        Returns:
            str | None: Tk break marker when this viewer consumes navigation.
        """
        if self._closed:
            return None
        if self._lightbox is not None and self._lightbox.visible:
            self._move_lightbox(-1)
            return 'break'
        return None
    def _on_right(self, _event: tk.Event) -> str | None:
        """Move to the next gallery photo when this view is mounted.

        Args:
            _event (tk.Event): Main-window Right key event.

        Returns:
            str | None: Tk break marker when this viewer consumes navigation.
        """
        if self._closed:
            return None
        if self._lightbox is not None and self._lightbox.visible:
            self._move_lightbox(1)
            return 'break'
        return None
    def _on_fullscreen(self, _event: tk.Event) -> str | None:
        """Toggle fullscreen for plain F or F11 while the detail is active.

        Args:
            _event (tk.Event): F, shifted F or F11 event with modifier state.

        Returns:
            str | None: Tk break marker for an unmodified fullscreen action;
                text-entry widgets and command combinations retain their keys.
        """
        lightbox = self._lightbox
        visible = lightbox is not None and lightbox.visible
        fullscreen_before = bool(
            lightbox is not None and lightbox._fullscreen,
        )
        widget = getattr(_event, 'widget', None)
        text_input = isinstance(widget, (
            tk.Entry, ttk.Entry, tk.Text, ttk.Combobox,
            tk.Spinbox, ttk.Spinbox,
        ))
        widget_category = (
            'text-entry' if text_input else
            'canvas' if isinstance(widget, tk.Canvas) else
            'control' if isinstance(widget, tk.Misc) else 'other'
        )
        state = getattr(_event, 'state', 0)
        if type(state) is not int:
            state = 0
        key = getattr(_event, 'keysym', '')
        if type(key) is not str:
            key = ''
        reason = 'inactive'
        result: str | None = None
        if visible and not text_input:
            modifiers = (
                MEDIA_FULLSCREEN_WINDOWS_COMMAND_MODIFIERS
                if sys.platform == 'win32'
                else MEDIA_FULLSCREEN_COMMAND_MODIFIERS
            )
            if sys.platform == 'darwin':
                modifiers |= 0x0010
            if state & modifiers:
                reason = 'command-modifier'
            else:
                assert lightbox is not None
                lightbox._toggle_fullscreen()
                reason = 'toggled'
                result = 'break'
        elif text_input:
            reason = 'text-input'
        summary = format_safe_photos_shortcut(
            key, state, widget_category, visible, fullscreen_before,
            bool(lightbox is not None and lightbox._fullscreen), reason,
        )
        _log_safe_photos_observation(
            summary, self._observation_callback,
        )
        return result
    def _on_zoom_in(self, _event: tk.Event) -> str | None:
        """Increase zoom with a plus or equals key.

        Args:
            _event (tk.Event): Main-window plus or equals key event.

        Returns:
            str | None: Tk break marker when a photo receives the shortcut.
        """
        if self._lightbox is None or not self._lightbox.visible:
            return None
        self._lightbox._change_zoom(LIGHTBOX_ZOOM_STEP)
        return 'break'
    def _on_zoom_out(self, _event: tk.Event) -> str | None:
        """Decrease zoom with the minus key.

        Args:
            _event (tk.Event): Main-window minus key event.

        Returns:
            str | None: Tk break marker when a photo receives the shortcut.
        """
        if self._lightbox is None or not self._lightbox.visible:
            return None
        self._lightbox._change_zoom(1 / LIGHTBOX_ZOOM_STEP)
        return 'break'
    def _on_fit(self, _event: tk.Event) -> str | None:
        """Reset active photo zoom without changing its crop mode.

        Args:
            _event (tk.Event): Main-window zero key event.

        Returns:
            str | None: Tk break marker when a photo receives the shortcut.
        """
        if self._lightbox is None or not self._lightbox.visible:
            return None
        return self._lightbox._fit_key(_event)
    def _on_context_click(self, event: tk.Event) -> None:
        """Dismiss music or map panels after clicks outside their anchor.

        Args:
            event (tk.Event): Main-window click event.

        Returns:
            None: Each active contextual panel keeps clicks within its surface.
        """
        self._close_music_outside(event)
        if self._lightbox is not None and self._lightbox.visible:
            self._lightbox._outside_location_click(event)
    def _on_grid_configure(self, _event: tk.Event) -> None:
        """Keep the scrollable photo grid's vertical extent current.

        Args:
            _event (tk.Event): Frame size event emitted after tile layout.

        Returns:
            None: The canvas scroll region follows the complete tile grid.
        """
        if getattr(self, '_canvas_grid', None) is not None:
            return
        bounds = self.grid_canvas.bbox('all')
        self.grid_canvas.configure(scrollregion=bounds)
        if bounds is not None and bounds[3] <= self.grid_canvas.winfo_height():
            self.grid_scrollbar.pack_forget()
        elif not self.grid_scrollbar.winfo_manager():
            self.grid_scrollbar.pack(side='right', fill='y')
        self._on_grid_viewport(_event)
    def _set_grid_scrollbar(self, first: str, last: str) -> None:
        """Update the scrollbar and admit thumbnails for its new viewport.

        Args:
            first (str): First visible canvas fraction from Tk.
            last (str): Last visible canvas fraction from Tk.
        Returns:
            None: Scroll position remains owned by the native canvas.
        """
        self.grid_scrollbar.set(first, last)
        self._on_grid_viewport(None)
    def _visible_photo_indices(self) -> tuple[int, ...]:
        """Return photo indices whose renderer bounds intersect the viewport.

        Returns:
            tuple[int, ...]: Visible indices eligible for thumbnail requests.
        """
        canvas_grid = getattr(self, '_canvas_grid', None)
        if canvas_grid is not None:
            return canvas_grid.visible_indices()
        top = self.grid_canvas.winfo_rooty() - self.grid_frame.winfo_rooty()
        bottom = top + self.grid_canvas.winfo_height()
        columns = max(1, self._grid_columns)
        row_count = (len(self._photos) + columns - 1) // columns
        first_row = max(
            0, min(row_count - 1,
                   self.grid_frame.grid_location(0, int(top))[1]),
        )
        last_row = max(
            0,
            min(
                row_count - 1,
                self.grid_frame.grid_location(0, max(0, int(bottom) - 1))[1],
            ),
        )
        first_index = first_row * columns
        last_index = min(
            len(self._tile_buttons), (last_row + 1) * columns,
        )
        return tuple(
            index for index in range(first_index, last_index)
            if (self._tile_buttons[index].winfo_y()
                + self._tile_buttons[index].winfo_height() > top
                and self._tile_buttons[index].winfo_y() < bottom)
        )
    def _on_grid_viewport(self, _event: tk.Event | None) -> None:
        """Coalesce viewport signals before updating thumbnail admission.

        Args:
            _event (tk.Event | None): Canvas event, or None from scrollbar updates.

        Returns:
            None: One owned timer applies the latest viewport after a short delay.
        """
        if self._closed:
            return
        self._viewport_event_count += 1
        if self._viewport_after_id is not None:
            return

        after_id: str | None = None

        def apply_viewport() -> None:
            """Apply only the timer instance still owned by this viewer.

            Returns:
                None: A cancelled timer cannot clear or run its replacement.
            """
            self._apply_grid_viewport(after_id)

        after_id = self.window.after(
            MEDIA_VIEWPORT_COALESCE_MS, apply_viewport,
        )
        self._viewport_after_id = after_id
    def _apply_grid_viewport(self, after_id: str | None) -> None:
        """Apply visible and one-row overscan thumbnail admission on Tk.

        Args:
            after_id (str | None): Exact callback identity captured at schedule.

        Returns:
            None: Stale callbacks are ignored; adjacent-row PhotoImages remain
                bounded and reusable during small scrolls.
        """
        if after_id is None or self._viewport_after_id != after_id:
            return
        self._viewport_after_id = None
        if self._closed or not self.grid_canvas.winfo_viewable():
            return
        started = time.perf_counter()
        self._viewport_update_count += 1
        if self._photos:
            visible = tuple(self._visible_photo_indices())
            visible_set = set(visible)
            self._visible_thumbnail_indices = visible_set
            indicator = getattr(self, '_load_progress', None)
            if indicator is not None and indicator.active and visible:
                completed = sum(
                    index < len(self._photo_images)
                    and self._photo_images[index] is not None
                    for index in visible_set
                )
                indicator.thumbnails(completed, len(visible_set))
            if visible_set != self._measured_visible:
                self._measured_visible = frozenset(visible_set)
                self._viewport_measure_epoch = self._viewport_performance.begin(
                    len(visible_set),
                )
            admitted: set[int] = set()
            if visible:
                columns = max(1, self._grid_columns)
                first_row = min(visible) // columns
                last_row = max(visible) // columns
                first_row = max(
                    0, first_row - MEDIA_THUMBNAIL_OVERSCAN_ROWS,
                )
                last_row = min(
                    (len(self._photos) - 1) // columns,
                    last_row + MEDIA_THUMBNAIL_OVERSCAN_ROWS,
                )
                admitted.update(range(
                    first_row * columns,
                    min(len(self._photos), (last_row + 1) * columns),
                ))
            self._admitted_thumbnail_indices = admitted
            canvas_grid = getattr(self, '_canvas_grid', None)
            if canvas_grid is not None:
                canvas_grid.reconcile(tuple(sorted(admitted)))
                # Reapply authoritative owner selection after slot reuse.
                self._render_photo_selection()
            self._thumbnail_pending = [
                index for index in self._thumbnail_pending if index in admitted
            ]
            for index in tuple(self._thumbnail_loaded - admitted):
                self._release_thumbnail_image(index)
            admitted_order = sorted(
                admitted, key=lambda index: (index not in visible_set, index),
            )
            self._thumbnail_retained_hit_count += len(
                self._thumbnail_loaded & visible_set,
            )
            self._thumbnail_disk_lane.retain(
                self.gallery.gallery_id, self._photos, tuple(admitted_order),
            )
            self._warm_thumbnail_pending = [
                item for item in self._warm_thumbnail_pending
                if (item[0] == self.gallery.gallery_id
                    and item[1] in admitted
                    and self._photos[item[1]] == item[2])
            ]
            for index in admitted_order:
                identity = (self.gallery.gallery_id, index, self._photos[index])
                if (index not in self._thumbnail_loaded
                        and identity not in self._warm_thumbnail_pending):
                    self._warm_thumbnail_pending.append(identity)
            self._warm_thumbnail_pending.sort(
                key=lambda item: (item[1] not in visible_set, item[1]),
            )
            self._thumbnail_pending.sort(
                key=lambda index: (index not in visible_set, index),
            )
            if self._worker_kind == 'thumbnail_batch':
                unfinished = tuple(
                    index for index in self._thumbnail_batch_indices
                    if index not in self._thumbnail_loaded
                )
                wanted = tuple(
                    index for index in admitted_order
                    if index in self._thumbnail_pending
                )
                if (any(index not in admitted for index in unfinished)
                        or wanted[:len(unfinished)] != unfinished):
                    self._thumbnail_cancelled.set()
            self._drain_warm_thumbnail_slice()
            if self._queue_thumbnail_proof_refresh():
                # Presented RAM images can make this viewport usable while
                # catalogue proof refresh remains pending. Hide loading feedback
                # without settling its separately fenced diagnostic epoch.
                self._observe_visible_viewport_settled()
                self._dispatch_pending_work()
                return
            bounds = self.grid_canvas.bbox('all')
            content_height = (
                self._grid_content_height if canvas_grid is not None
                else bounds[3] if bounds is not None else 0
            )
            scrollable = content_height > self.grid_canvas.winfo_height()
            viewport_not_filled = (
                bounds is not None or canvas_grid is not None
            ) and content_height <= self.grid_canvas.winfo_height()
            near_scroll_end = bool(
                scrollable and visible
                and max(visible) >= len(self._photos) - self._grid_columns * 2
            )
            if (self._next_after is not None and not self._loading_more
                    and visible and (viewport_not_filled or near_scroll_end)):
                # Admit the next metadata page before launching a thumbnail
                # batch that this same viewport turn would immediately cancel.
                self._load_more()
            self._dispatch_pending_work()
            self._observe_visible_viewport_settled()
        elapsed_ms = (time.perf_counter() - started) * 1000.0
        self._viewport_peak_update_ms = max(
            self._viewport_peak_update_ms, elapsed_ms,
        )
    def rearm_first_viewport_diagnostics(self) -> None:
        """Measure a new catalogue pass on this still-mounted Tk viewer.

        Returns:
            None: The next existing coalesced viewport pass can settle a new
                diagnostic epoch without creating a media request or widget.
        """
        if self._closed:
            return
        self._first_viewport_reported = False
        self._measured_visible = frozenset()
        self._viewport_measure_epoch = None
        self._on_grid_viewport(None)
    def _observe_visible_viewport_settled(self) -> None:
        """Measure only the current, fully resolved visible Tk tile band.

        Returns:
            None: An incomplete, stale, hidden or cancelled band remains
                pending; each settled band is sampled only once.
        """
        epoch = self._viewport_measure_epoch
        visible = self._visible_thumbnail_indices
        indicator = getattr(self, '_load_progress', None)
        if (indicator is not None and indicator.active and visible
                and not self._closed
                and self.frame.winfo_viewable()
                and self._viewport_after_id is None
                and visible <= self._admitted_thumbnail_indices
                and visible <= self._thumbnail_loaded
                and all(index < len(self._photo_images) for index in visible)):
            available = sum(
                self._photo_images[index] is not None for index in visible
            )
            if available:
                indicator.hide()
            elif indicator.phase not in ('error', 'waiting'):
                indicator.fail(self._text('linking_media_load_failed'))
                self._http_batch_retry_indices = tuple(sorted(visible))
                self.status.pack(anchor='w', pady=(4, 8))
                self.status.configure(
                    text=self._text('linking_media_load_failed'),
                )
                self.retry_button.configure(state='normal')
                self.retry_button.pack(side='left', padx=(8, 0))
                self._retry_kind = 'thumbnail'
        if (self._closed or epoch is None or not visible
                or self._viewport_after_id is not None
                or not self.frame.winfo_viewable()
                or self._refresh_pending
                or not visible <= self._admitted_thumbnail_indices
                or not visible <= self._thumbnail_loaded
                or any(index >= len(self._photo_images) for index in visible)):
            return
        available = sum(
            self._photo_images[index] is not None for index in visible
        )
        unavailable = len(visible) - available
        if not self._viewport_performance.settle(
            epoch, available, unavailable,
        ):
            return
        if not self._first_viewport_reported:
            self._first_viewport_reported = True
            callback = self.on_first_viewport_settled
            if callback is not None:
                try:
                    callback()
                except Exception:
                    # A diagnostic callback never affects genuine Tk rendering.
                    NATIVE_GALLERY_VIEWER_LOGGER.warning(
                        'First settled viewport diagnostic callback failed.',
                    )
    def _cancel_grid_viewport_callback(self) -> None:
        """Cancel the owned coalescing timer and fence a stale callback.

        Returns:
            None: A late callback cannot run viewport work after replacement.
        """
        after_id, self._viewport_after_id = self._viewport_after_id, None
        if after_id is None:
            return
        try:
            self.window.after_cancel(after_id)
        except tk.TclError:
            pass
    def _drain_warm_thumbnail_slice(self) -> None:
        """Attach a bounded group of warm cached thumbnails on Tk.

        Returns:
            None: At most four cache lookups/copies run before yielding; the
                remaining descriptors continue in an identity-fenced timer.
        """
        if self._closed or self._thumbnail_gui_after_id is not None:
            return
        started = time.perf_counter()
        count = 0
        while (self._warm_thumbnail_pending
               and count < MEDIA_THUMBNAIL_GUI_SLICE_SIZE
               and (count == 0 or time.perf_counter() - started
                    < MEDIA_THUMBNAIL_GUI_SLICE_SECONDS)):
            gallery_id, index, photo = self._warm_thumbnail_pending.pop(0)
            if (gallery_id == self.gallery.gallery_id
                    and index in self._admitted_thumbnail_indices
                    and index < len(self._photos)
                    and self._photos[index] == photo):
                if not self.session.can_read_media():
                    self.close()
                    return
                current_key = self._thumbnail_memory_key(photo)
                if (current_key is not None
                        and index < len(self._photo_images)
                        and self._photo_images[index] is not None):
                    count += 1
                    continue
                cached = self._cached_thumbnail(
                    photo, expected_key=current_key,
                )
                if cached is not None and not self.session.can_read_media():
                    cached.close()
                    self.close()
                    return
                if (cached is not None
                        and self._thumbnail_memory_key(photo) != current_key):
                    cached.close()
                    cached = None
                if cached is not None:
                    self._thumbnail_warm_cache_hit_count += 1
                    attach_started = time.perf_counter()
                    self._show_thumbnail(index, cached, remember=False)
                    self._thumbnail_tk_ms += (
                        time.perf_counter() - attach_started
                    ) * 1000.0
                elif (not self._refresh_pending
                      and self._worker_kind != 'refresh'
                      and index not in self._thumbnail_pending):
                    if not self._thumbnail_disk_lane.contains(
                        gallery_id, index, photo,
                    ):
                        self._thumbnail_disk_lane.submit(
                            gallery_id, index, photo,
                        )
                        self._schedule_queue_poll()
            count += 1
        self._thumbnail_pending.sort(
            key=lambda index: (
                index not in self._visible_thumbnail_indices, index,
            ),
        )
        if self._warm_thumbnail_pending:
            after_id: str | None = None

            def continue_slice() -> None:
                """Run only the still-owned warm-image continuation.

                Returns:
                    None: A replaced or cancelled timer cannot attach images.
                """
                if self._thumbnail_gui_after_id != after_id:
                    return
                self._thumbnail_gui_after_id = None
                self._drain_warm_thumbnail_slice()
                self._dispatch_pending_work()

            after_id = self.window.after(
                MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS, continue_slice,
            )
            self._thumbnail_gui_after_id = after_id
    def _cancel_thumbnail_gui_slice(self) -> None:
        """Cancel and release pending warm-image work owned by this viewer.

        Returns:
            None: Late slice callbacks fail their timer-identity check.
        """
        after_id, self._thumbnail_gui_after_id = (
            self._thumbnail_gui_after_id, None,
        )
        if after_id is not None:
            try:
                self.window.after_cancel(after_id)
            except tk.TclError:
                pass
        self._warm_thumbnail_pending.clear()
    def _release_thumbnail_image(self, index: int) -> None:
        """Release one Tk thumbnail reference outside its admitted row band.

        Args:
            index (int): Zero-based tile whose retained PhotoImage is released.

        Returns:
            None: Counters record actual image-reference eviction only.
        """
        if not 0 <= index < len(self._photo_images):
            self._thumbnail_loaded.discard(index)
            return
        # Tk images stay viewport-scoped; warm session rasters intentionally
        # recreate PhotoImages on readmission instead of retaining a second pool.
        canonical = self._canonical_thumbnail_images.pop(index, None)
        if canonical is not None:
            canonical.close()
        image = self._photo_images[index]
        if image is not None:
            self._thumbnail_image_release_count += 1
            self._photo_images[index] = None
        self._thumbnail_loaded.discard(index)
        if index < len(self._tile_buttons):
            self._tile_buttons[index].configure(
                image='', text=MEDIA_THUMBNAIL_PLACEHOLDER, compound='none',
                style='Placeholder.LinkedPhoto.TButton',
            )
    def _observe_thumbnail_read(
        self, timings: MediaReadTimings, decode_ms: float,
    ) -> None:
        """Record attempted I/O before GUI admission or cancellation fencing.

        Args:
            timings (MediaReadTimings): Source and cumulative read observations.
            decode_ms (float): Actual decoder work in milliseconds.

        Returns:
            None: Dropped results still contribute their performed work.
        """
        with self._thumbnail_metrics_lock:
            self._thumbnail_disk_ms += timings.disk_read_ms
            self._thumbnail_disk_write_ms += timings.disk_write_ms
            self._thumbnail_gate_wait_ms += timings.gate_wait_ms
            self._thumbnail_renewal_ms += timings.renewal_ms
            self._thumbnail_network_ms += timings.network_ms
            self._thumbnail_network_reads += timings.network_reads
            self._thumbnail_decode_ms += decode_ms
            for name in self._thumbnail_transport_totals:
                self._thumbnail_transport_totals[name] += getattr(timings, name)
            self._thumbnail_cache_outcomes[timings.cache_state] += 1
            if timings.source == 'disk':
                self._thumbnail_source_disk_count += 1
            elif timings.source == 'network':
                self._thumbnail_source_network_count += 1
            elif timings.source == 'revalidated':
                self._thumbnail_source_revalidated_count += 1
        self._thumbnail_performance.observe(timings, decode_ms)
    def _observe_detail_read(
        self, kind: Literal['detail', 'prefetch'], timings: MediaReadTimings,
    ) -> None:
        """Record numeric source and transport facts for one detail operation.

        Args:
            kind (Literal['detail', 'prefetch']): Fixed operation category.
            timings (MediaReadTimings): Caller-owned aggregate media facts.

        Returns:
            None: Source, HTTP request count and encoded bytes join the
                process-safe summary without retaining media or identities.
        """
        with self._thumbnail_metrics_lock:
            if timings.source in ('disk', 'network', 'revalidated'):
                self._detail_media_metrics[
                    f'{kind}_source_{timings.source}'
                ] += 1
            self._detail_media_metrics[f'{kind}_http_requests'] += (
                timings.http_requests
            )
            self._detail_media_metrics[f'{kind}_encoded_bytes'] += (
                timings.encoded_bytes
            )
    def render_diagnostics(self) -> str:
        """Return fixed numeric viewport, thumbnail and detail counters.

        Returns:
            str: Fixed numeric grid/detail sources, transport requests, byte
                counts, event and image totals plus cumulative timing without
                photo IDs, titles or request data.
        """
        retained = sum(
            image is not None for image in self._photo_images
        )
        memory_state = self._thumbnail_memory.metrics_snapshot()
        renderer = 'canvas' if self._canvas_grid_enabled else 'native'
        with self._thumbnail_metrics_lock:
            transport = ' '.join(
                f'{name}={value:.2f}'
                for name, value in self._thumbnail_transport_totals.items()
            )
            outcomes = ' '.join(
                f'cache_{name}={value}'
                for name, value in self._thumbnail_cache_outcomes.items()
            )
            detail_metrics = ' '.join(
                f'{name}={value}'
                for name, value in self._detail_media_metrics.items()
            )
            http_sizes = ','.join(
                f'{size}:{count}' for size, count in sorted(self._http_batch_sizes.items())
            ) or 'none'
            http_fallbacks = ','.join(
                f'{reason}:{count}' for reason, count
                in self._http_batch_fallback_reasons.items()
            )
        return (
            'Photos grid: '
            f'viewport_events={self._viewport_event_count} '
            f'viewport_updates={self._viewport_update_count} '
            f'tk_images_created={self._thumbnail_image_create_count} '
            f'tk_images_released={self._thumbnail_image_release_count} '
            f'warm_cache_hits={self._thumbnail_warm_cache_hit_count} '
            f'retained_hits={self._thumbnail_retained_hit_count} '
            f'local_misses={self._thumbnail_local_miss_count} '
            f'discarded={self._thumbnail_discarded_count} '
            f'cancelled_reads={self._thumbnail_cancelled_read_count} '
            f'disk_discarded={self._thumbnail_disk_lane.discarded_results} '
            f'renderer={renderer} '
            f'memory_entries={memory_state["entries"]} '
            f'memory_capacity_entries={MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT} '
            f'memory_bytes={memory_state["bytes"]} '
            f'memory_capacity_bytes={MEDIA_THUMBNAIL_CACHE_MAX_BYTES} '
            f'evictions={memory_state["evictions"]} '
            f'admission_refusals={memory_state["admission_refusals"]} '
            f'expired={memory_state["expirations"]} '
            f'validator_changes={memory_state["validator_changes"]} '
            f'proof_refreshes={self._thumbnail_proof_refresh_count} '
            f'batches={self._thumbnail_batch_count} '
            f'dispatch_groups={self._thumbnail_batch_count} '
            f'http_batches={self._http_batch_requests} '
            f'http_frames={self._http_batch_frames} '
            f'http_bytes={self._http_batch_bytes} '
            f'http_failures={self._http_batch_failures} '
            f'http_incomplete={self._http_batch_incomplete} '
            f'http_fallbacks={self._http_batch_fallbacks} '
            f'http_fallback_reasons={http_fallbacks} '
            f'http_first_frame_ms={self._http_batch_first_frame_ms:.2f} '
            f'http_sizes={http_sizes} '
            f'source_disk={self._thumbnail_source_disk_count} '
            f'source_network={self._thumbnail_source_network_count} '
            f'source_revalidated={self._thumbnail_source_revalidated_count} '
            f'disk_ms={self._thumbnail_disk_ms:.2f} '
            f'disk_write_ms={self._thumbnail_disk_write_ms:.2f} '
            f'gate_wait_ms={self._thumbnail_gate_wait_ms:.2f} '
            f'renewal_ms={self._thumbnail_renewal_ms:.2f} '
            f'network_ms={self._thumbnail_network_ms:.2f} '
            f'network_reads={self._thumbnail_network_reads} '
            f'{transport} {outcomes} {detail_metrics} '
            f'decode_ms={self._thumbnail_decode_ms:.2f} '
            f'queue_wait_ms={self._thumbnail_queue_wait_ms:.2f} '
            f'tk_ms={self._thumbnail_tk_ms:.2f} '
            f'peak_update_ms={self._viewport_peak_update_ms:.2f} '
            f'visible={len(self._visible_thumbnail_indices)} '
            f'admitted={len(self._admitted_thumbnail_indices)} '
            f'retained={retained} '
            f'visible_unsettled={len(self._visible_thumbnail_indices - self._thumbnail_loaded)} '
            f'pending_thumbnail={len(self._thumbnail_pending)} '
            f'pending_local={len(self._warm_thumbnail_pending)} '
            f'queued_gui_results={self._events.qsize()} '
            f'worker_active={int(self._worker is not None)}\n'
            f'{self._thumbnail_performance.render_summary()}\n'
            f'{self._thumbnail_batch_physical.render_summary()}\n'
            f'{self._viewport_performance.render_summary()}'
        )
    def _on_grid_resize(
        self, event: tk.Event, *, force: bool = False,
    ) -> None:
        """Resize the native grid or relayout the opt-in Canvas renderer.

        Args:
            event (tk.Event): Canvas configure event with current pixel dimensions.
            force (bool): Reapply native row geometry after a format change.

        Returns:
            None: The active renderer updates columns, extent, and viewport admission.
        """
        canvas_grid = getattr(self, '_canvas_grid', None)
        if canvas_grid is not None:
            self._layout_canvas_grid(
                event.width,
                int(getattr(event, 'height', self.grid_canvas.winfo_height())),
            )
            self._on_grid_viewport(event)
            return
        if event.width != self._native_grid_width:
            self.grid_canvas.itemconfigure(self.grid_window, width=event.width)
            self._native_grid_width = event.width
        tile_width, tile_height = self._card_dimensions()
        columns = max(1, event.width
                      // (tile_width + MEDIA_GRID_GUTTER
                          + 2 * GALLERY_TILE_BORDER_INSET))
        if columns == self._grid_columns and not force:
            return
        for column in range(getattr(self, '_grid_columns', 1)):
            self.grid_frame.columnconfigure(
                column, weight=0, uniform='', minsize=0,
            )
        column_minimum = tile_width + MEDIA_GRID_GUTTER + 2 * GALLERY_TILE_BORDER_INSET
        for column in range(columns):
            self.grid_frame.columnconfigure(
                column, weight=0, uniform='', minsize=column_minimum,
            )
        self._grid_columns = columns
        for row in range(self.grid_frame.grid_size()[1]):
            self.grid_frame.grid_rowconfigure(row, minsize=0)
        row_minimum = tile_height + MEDIA_GRID_GUTTER + 2 * GALLERY_TILE_BORDER_INSET
        for index, button in enumerate(self._tile_buttons):
            row = index // columns
            self.grid_frame.grid_rowconfigure(
                row, minsize=row_minimum,
            )
            button.grid_configure(
                row=row,
                column=index % columns,
                # Extra space stays outside the image and its selection rim.
                sticky='',
            )
        self._on_grid_viewport(None)
    def _card_dimensions(self) -> tuple[int, int]:
        """Return the selected aspect's bounded image dimensions.

        Returns:
            tuple[int, int]: Image width and height in pixels.
        """
        return gallery_card_dimensions(getattr(self, '_card_format', 'default'))
    def set_card_format(self, card_format: str) -> None:
        """Apply an allowlisted display aspect without fetching thumbnails.

        Args:
            card_format (str): One supported aspect key or ``default``.

        Returns:
            None: Existing tile widgets retain their selection and focus state.
        """
        normalized = normalize_card_format(card_format)
        if normalized != card_format or normalized == self._card_format:
            return
        self._card_format = normalized
        resize = tk.Event()
        resize.width = self.grid_canvas.winfo_width()
        self._on_grid_resize(resize, force=True)
        self._render_card_format_images()
    def _present_thumbnail(self, canonical: 'Image.Image') -> 'Image.Image':
        """Return an owned display raster cropped from a canonical preview.

        Args:
            canonical (Image.Image): Retained natural-aspect thumbnail raster.

        Returns:
            Image.Image: Independent raster matching the selected card aspect;
                Default returns an unchanged pixel copy.
        """
        width, height = self._card_dimensions()
        if getattr(self, '_card_format', 'default') == 'default':
            return canonical.copy()
        target = width / height
        source = canonical.width / canonical.height
        if source > target:
            crop_width = max(1, round(canonical.height * target))
            left = (canonical.width - crop_width) // 2
            cropped = canonical.crop((left, 0, left + crop_width, canonical.height))
        elif source < target:
            crop_height = max(1, round(canonical.width / target))
            top = (canonical.height - crop_height) // 2
            cropped = canonical.crop((0, top, canonical.width, top + crop_height))
        else:
            cropped = canonical.copy()
        try:
            return cropped.resize((width, height), Image.Resampling.LANCZOS)
        finally:
            cropped.close()
    def _render_card_format_images(self) -> None:
        """Crop retained canonical previews for the current display aspect.

        Returns:
            None: No media request is made and cache entries remain canonical.
        """
        if Image is None or ImageTk is None:
            return
        for index, canonical in tuple(self._canonical_thumbnail_images.items()):
            if index >= len(self._tile_buttons):
                continue
            presented = self._present_thumbnail(canonical)
            try:
                image = ImageTk.PhotoImage(presented, master=self.frame)
                self._thumbnail_image_create_count += 1
                if self._photo_images[index] is not None:
                    self._thumbnail_image_release_count += 1
                self._photo_images[index] = image
                self._tile_buttons[index].configure(
                    image=image, text='', compound='image',
                )
            finally:
                presented.close()
    def _on_grid_wheel(self, event: tk.Event) -> None:
        """Scroll the photo grid while the pointer is over its canvas.

        Args:
            event (tk.Event): Native wheel event carrying the signed delta.

        Returns:
            None: The canvas scrolls one platform-adjusted unit.
        """
        if self._closed or not self.grid_canvas.winfo_exists():
            return
        pointer = self.window.winfo_containing(event.x_root, event.y_root)
        while pointer is not None and pointer is not self.grid_canvas:
            pointer = getattr(pointer, 'master', None)
        if pointer is None:
            return
        delta = vertical_scroll_delta(self.window, event)
        if not delta:
            return
        direction = 1 if delta > 0 else -1
        self.grid_canvas.yview_scroll(-direction, 'units')
        self._on_grid_viewport(event)
    def _bind_precise_scroll(self, widget: tk.Misc) -> None:
        """Bind Tk's high-resolution event only when the interpreter supports it.

        Args:
            widget (tk.Misc): Grid widget receiving native touchpad gestures.
        Returns:
            None: Older Tk runtimes retain ordinary wheel support unchanged.
        """
        try:
            widget.bind(
                '<TouchpadScroll>', self._on_precise_grid_scroll, add='+',
            )
        except tk.TclError:
            return
    def _on_precise_grid_scroll(self, event: tk.Event) -> None:
        """Route an explicitly precise Tk event to the normal grid owner.

        Args:
            event (tk.Event): High-resolution TouchpadScroll event.
        Returns:
            None: The grid applies pixel-aware scrolling for its vertical axis.
        """
        pointer = self.window.winfo_containing(event.x_root, event.y_root)
        while pointer is not None and pointer is not self.grid_canvas:
            pointer = getattr(pointer, 'master', None)
        if pointer is None or self._closed:
            return
        delta = vertical_scroll_delta(self.window, event, precise=True)
        if delta:
            bounds = self.grid_canvas.bbox('all')
            if bounds is None:
                return
            content_height = (
                self._grid_content_height
                if getattr(self, '_canvas_grid', None) is not None
                else bounds[3] - bounds[1]
            )
            if content_height <= 0:
                return
            current = self.grid_canvas.yview()
            if not current:
                return
            self.grid_canvas.yview_moveto(current[0] - delta / content_height)
            self._on_grid_viewport(event)
    def _on_photo_context(self, index: int, event: tk.Event) -> str | None:
        """Dispatch a photo context action without activating its tile.

        Args:
            index (int): Zero-based photo tile associated with the event.
            event (tk.Event): Mouse or keyboard context event.
        Returns:
            str | None: Callback result, usually ``break`` after opening a menu.
        """
        if self._context_menu is None or not 0 <= index < len(self._photos):
            return None
        return self._context_menu(self._photos[index], event)
    def selected_photo_ids(self) -> tuple[int, ...]:
        """Return selected visible photo IDs in the current gallery order.

        Returns:
            tuple[int, ...]: Exact loaded IDs selected for an explicit action.
        """
        return tuple(
            photo.image_id for photo in self._photos
            if photo.image_id in self._selected_photo_ids
        )
    def focus_selection(self) -> None:
        """Return keyboard focus to the selection after its inspector closes.

        Returns:
            None: The current anchor or another selected tile receives focus;
                an empty selection leaves focus on the existing grid canvas.
                The selection and current album remain mounted.
        """
        index = self._selection_anchor_index
        if (index is None or not 0 <= index < len(self._photos)
                or self._photos[index].image_id not in self._selected_photo_ids):
            index = next((
                position for position, photo in enumerate(self._photos)
                if photo.image_id in self._selected_photo_ids
            ), None)
        if index is not None:
            self._focus_tile(index)
        else:
            self.grid_canvas.focus_set()
    def toggle_photo_selection(self, image_id: int) -> bool:
        """Toggle one currently loaded image in the explicit selection set.

        Args:
            image_id (int): Exact photo ID represented by a loaded tile.

        Returns:
            bool: True when selected after the operation.
        """
        index = next((index for index, photo in enumerate(self._photos)
                      if photo.image_id == image_id), None)
        if self._closed or index is None:
            return False
        if image_id in self._selected_photo_ids:
            self._selected_photo_ids.remove(image_id)
            selected = False
        else:
            if len(self._selected_photo_ids) >= NATIVE_MEDIA_DELETE_BATCH_MAX:
                self.status.pack(anchor='w', pady=(4, 8))
                self.status.configure(
                    text=self._text('linking_media_selection_limit').format(
                        limit=NATIVE_MEDIA_DELETE_BATCH_MAX,
                    ),
                )
                return False
            self._selected_photo_ids.add(image_id)
            selected = True
        self._selection_anchor_index = index
        self._focus_tile(index)
        self._render_photo_selection((index,))
        if self._on_photo_selected is not None:
            self._on_photo_selected(self._photos[index])
        return selected
    def select_photo(self, index: int) -> bool:
        """Focus one loaded photo and notify its metadata inspector.

        Args:
            index (int): Zero-based index in the current loaded gallery page.

        Returns:
            bool: True when a current photo became the single selection.
        """
        if self._closed or not 0 <= index < len(self._photos):
            return False
        previous = set(self._selected_photo_ids)
        photo = self._photos[index]
        self._selected_photo_ids = {photo.image_id}
        self._selection_anchor_index = index
        self._focus_tile(index)
        changed = tuple(
            position for position, item in enumerate(self._photos)
            if item.image_id in previous or item.image_id == photo.image_id
        )
        self._render_photo_selection(changed)
        if self._on_photo_selected is not None:
            self._on_photo_selected(photo)
        return True
    def select_photo_range(self, index: int) -> bool:
        """Select an anchored contiguous loaded range within the delete limit.

        Args:
            index (int): Zero-based target tile in the current page.

        Returns:
            bool: True when the complete anchored range was admitted.
        """
        if self._closed or not 0 <= index < len(self._photos):
            return False
        anchor = self._selection_anchor_index
        if anchor is None or not 0 <= anchor < len(self._photos):
            return self.select_photo(index)
        start, end = sorted((anchor, index))
        if end - start + 1 > NATIVE_MEDIA_DELETE_BATCH_MAX:
            self.status.pack(anchor='w', pady=(4, 8))
            self.status.configure(
                text=self._text('linking_media_selection_limit').format(
                    limit=NATIVE_MEDIA_DELETE_BATCH_MAX,
                ),
            )
            return False
        previous = set(self._selected_photo_ids)
        selected = {photo.image_id for photo in self._photos[start:end + 1]}
        self._selected_photo_ids = selected
        self._focus_tile(index)
        changed_ids = previous | selected
        self._render_photo_selection(tuple(
            position for position, photo in enumerate(self._photos)
            if photo.image_id in changed_ids
        ))
        photo = self._photos[index]
        if self._on_photo_selected is not None:
            self._on_photo_selected(photo)
        return True
    def _focus_tile(self, index: int) -> None:
        """Focus and scroll the target grid tile into the visible viewport.

        Args:
            index (int): Zero-based loaded tile to receive keyboard focus.

        Returns:
            None: The current grid owns both focus and scroll position.
        """
        if not 0 <= index < len(self._tile_buttons):
            return
        canvas_grid = getattr(self, '_canvas_grid', None)
        if canvas_grid is not None:
            canvas_grid.focus(index, force=True)
            return
        tile = self._tile_buttons[index]
        tile.focus_set()
        tile_y = tile.winfo_y()
        canvas_y = self.grid_canvas.canvasy(0)
        viewport = self.grid_canvas.winfo_height()
        if tile_y < canvas_y:
            self.grid_canvas.yview_moveto(
                max(0.0, tile_y / max(1, self.grid_frame.winfo_height())),
            )
        elif tile_y + tile.winfo_height() > canvas_y + viewport:
            bottom = tile_y + tile.winfo_height() - viewport
            self.grid_canvas.yview_moveto(
                max(0.0, bottom / max(1, self.grid_frame.winfo_height())),
            )
    def _tile_key(self, index: int, event: tk.Event) -> str | None:
        """Handle local tile navigation and explicit open/toggle commands.

        Args:
            index (int): Tile index that currently owns keyboard focus.
            event (tk.Event): Key event from the focused tile only.

        Returns:
            str | None: Tk break marker for handled tile navigation keys.
        """
        if not 0 <= index < len(self._photos):
            return None
        key = event.keysym
        if key in ('Return', 'KP_Enter'):
            self._open_grid_photo(index)
            return 'break'
        if key == 'space':
            self.toggle_photo_selection(self._photos[index].image_id)
            return 'break'
        canvas_grid = getattr(self, '_canvas_grid', None)
        target: int | None = None
        if canvas_grid is not None and key == 'Home':
            target = 0
        elif canvas_grid is not None and key == 'End':
            target = len(self._photos) - 1
        elif canvas_grid is not None and key in ('Prior', 'Next'):
            cell_height = (
                self._card_dimensions()[1] + MEDIA_GRID_GUTTER
                + 2 * GALLERY_TILE_BORDER_INSET
            )
            page_rows = max(
                1, self.grid_canvas.winfo_height() // max(1, cell_height),
            )
            page_delta = page_rows * max(1, self._grid_columns)
            target = index + (-page_delta if key == 'Prior' else page_delta)
        else:
            delta = {
                'Left': -1, 'Right': 1,
                'Up': -max(1, self._grid_columns),
                'Down': max(1, self._grid_columns),
            }.get(key)
            if delta is not None:
                target = index + delta
        if target is None:
            return None
        target = max(0, min(len(self._photos) - 1, target))
        # Tk ShiftMask extends the anchor; Control (or macOS Command) moves
        # focus without discarding the existing multi-selection.
        command_mask = 0x0008 if sys.platform == 'darwin' else 0x0004
        if event.state & 0x0001:
            self.select_photo_range(target)
        elif event.state & command_mask:
            self._focus_tile(target)
            if self._on_photo_selected is not None:
                self._on_photo_selected(self._photos[target])
        else:
            self.select_photo(target)
        return 'break'
    def _open_grid_photo(self, index: int) -> None:
        """Open a grid photo only after ensuring its selection is current.

        Args:
            index (int): Zero-based tile that the user explicitly opened.

        Returns:
            None: The existing foreground detail path owns full-size bytes.
        """
        if not 0 <= index < len(self._photos):
            return
        if self._photos[index].image_id not in self._selected_photo_ids:
            self.select_photo(index)
        self._select_photo(index)
    def clear_photo_selection(self, image_ids: tuple[int, ...]) -> None:
        """Clear only the IDs consumed by one confirmed deletion operation.

        Args:
            image_ids (tuple[int, ...]): Confirmed or explicitly reconciled IDs.

        Returns:
            None: Unrelated selections remain available for the next action.
        """
        targets = set(image_ids)
        self._selected_photo_ids.difference_update(targets)
        if (self._selection_anchor_index is not None
                and self._selection_anchor_index < len(self._photos)
                and self._photos[self._selection_anchor_index].image_id in targets):
            self._selection_anchor_index = None
        self._render_photo_selection(tuple(
            index for index, photo in enumerate(self._photos)
            if photo.image_id in targets
        ))
    def _render_photo_selection(
        self, indexes: tuple[int, ...] | None = None,
    ) -> None:
        """Apply one rounded primary outline without a separate focus ring.

        Args:
            indexes (tuple[int, ...] | None): Changed tile indexes, or all tiles.

        Returns:
            None: Tile identity and thumbnail bytes remain unchanged.
        """
        canvas_grid = getattr(self, '_canvas_grid', None)
        if canvas_grid is None:
            current = range(len(self._photos)) if indexes is None else indexes
        elif indexes is None:
            current = tuple(canvas_grid.active_indices)
        else:
            active = set(canvas_grid.active_indices)
            current = tuple(index for index in indexes if index in active)
        for index in current:
            if not 0 <= index < len(self._photos):
                continue
            photo = self._photos[index]
            if index >= len(self._tile_buttons):
                continue
            tile = self._tile_buttons[index]
            selected = photo.image_id in self._selected_photo_ids
            tile.state(['selected'] if selected else ['!selected'])
            tile.configure(style=(
                'Selected.LinkedPhoto.TButton' if selected else
                'LinkedPhoto.TButton' if self._photo_images[index] is not None else
                'Placeholder.LinkedPhoto.TButton'
            ))
    def _toggle_canvas_photo_selection(self, index: int) -> bool:
        """Toggle the selection for one Canvas proxy's loaded photo index.

        Args:
            index (int): Zero-based photo index supplied by the Canvas helper.

        Returns:
            bool: True when the exact loaded photo is selected after toggling.
        """
        if not 0 <= index < len(self._photos):
            return False
        return self.toggle_photo_selection(self._photos[index].image_id)
    def _canvas_context(
        self, index: int | None, event: tk.Event,
    ) -> str | None:
        """Dispatch Canvas context input to the photo or empty-grid owner.

        Args:
            index (int | None): Photo index under the pointer, or None for empty space.
            event (tk.Event): Native Canvas context event.

        Returns:
            str | None: Existing menu callback result, or None when unhandled.
        """
        if index is None:
            return self._on_empty_context(event)
        return self._on_photo_context(index, event)
    def _canvas_viewport_changed(self) -> None:
        """Queue the current Canvas viewport through the existing admission owner.

        Returns:
            None: The shared viewport coalescer retains thumbnail and paging policy.
        """
        self._on_grid_viewport(None)
    def _layout_canvas_grid(
        self, width: int | None = None, height: int | None = None,
    ) -> None:
        """Update Canvas geometry and scrolling from the helper's logical extent.

        Args:
            width (int | None): Current Canvas width, or None to query Tk.
            height (int | None): Current Canvas height, or None to query Tk.

        Returns:
            None: Columns, scrollregion, and scrollbar follow all loaded metadata.
        """
        canvas_grid = getattr(self, '_canvas_grid', None)
        if canvas_grid is None:
            return
        canvas_width = max(
            1, int(width if width is not None else self.grid_canvas.winfo_width()),
        )
        canvas_height = max(
            1, int(height if height is not None else self.grid_canvas.winfo_height()),
        )
        columns, content_height = canvas_grid.layout(
            canvas_width, canvas_height, self._card_dimensions(),
        )
        self._grid_columns = max(1, columns)
        self._grid_content_height = max(0, content_height)
        scrollbar_visible = bool(self.grid_scrollbar.winfo_manager())
        if self._grid_content_height <= canvas_height and scrollbar_visible:
            self.grid_scrollbar.pack_forget()
        elif self._grid_content_height > canvas_height and not scrollbar_visible:
            self.grid_scrollbar.pack(side='right', fill='y')
    def _on_empty_context(self, event: tk.Event) -> str | None:
        """Dispatch an empty-grid context action for child-gallery creation.

        Args:
            event (tk.Event): Mouse context event on unused grid space.
        Returns:
            str | None: Callback result, usually ``break`` after opening a menu.
        """
        if self._context_menu is None:
            return None
        return self._context_menu(None, event)
    def _toggle_music_panel(self) -> None:
        """Show the track selector in a compact panel beside its trigger.

        Returns:
            None: Track choice stays separate from the explicit play action.
        """
        if self._music_panel is not None:
            self._close_music_panel()
            return
        if self._closed:
            return
        padding = 8
        parent_width = self.frame.winfo_width()
        width = max(1, min(MEDIA_MUSIC_PANEL_WIDTH, parent_width))
        panel = RaisedDialogSurface(self.frame, padding=padding)
        self._music_panel = panel
        ttk.Label(
            panel,
            text=self._text('linking_media_presentation'),
            style='Muted.TLabel',
        ).pack(anchor='w', pady=(0, 4))
        if not self.session.can_read_presentation():
            feedback = 'linking_media_audio_consent'
        elif self._presentation is None or not self._presentation.music or not self._tracks:
            feedback = 'linking_media_audio_empty'
        elif sys.platform not in ('win32', 'darwin'):
            feedback = 'linking_media_audio_unavailable'
        else:
            feedback = None
        if feedback is not None:
            ttk.Label(
                panel,
                text=self._text(feedback),
                wraplength=max(1, width - 2 * padding),
            ).pack(anchor='w', fill='x')
        else:
            self.track_menu = ttk.Combobox(
                panel,
                values=tuple(track.title for track in self._tracks),
                state='readonly',
                width=32,
            )
            self.track_menu.pack(fill='x')
            self.track_menu.current(
                min(self._selected_track_index, len(self._tracks) - 1),
            )
            self.track_menu.bind('<<ComboboxSelected>>', self._on_track_selected)
            self.panel_track_button = icon_button(
                panel,
                'play',
                self._toggle_track,
                self._panel_track_tooltip,
            )
            self._refresh_audio_button()
            self.panel_track_button.pack(anchor='e', pady=(6, 0))
        panel.update_idletasks()
        x = self.music_button.winfo_rootx() - self.frame.winfo_rootx()
        y = self.music_button.winfo_rooty() - self.frame.winfo_rooty()
        parent_width = self.frame.winfo_width()
        parent_height = self.frame.winfo_height()
        height = min(panel.winfo_reqheight(), parent_height)
        gap = padding // 2
        below = y + self.music_button.winfo_height() + gap
        anchored_y = below if below + height <= parent_height else y - height - gap
        panel.place(
            x=max(0, min(parent_width - width, x)),
            y=max(0, min(parent_height - height, anchored_y)),
            width=width,
            height=height,
        )
        if self.track_menu is not None:
            self.track_menu.focus_set()
    def _close_music_panel(self) -> None:
        """Hide the anchored track chooser and clear its widget reference.

        Returns:
            None: The toolbar retains only its compact presentation trigger.
        """
        panel = self._music_panel
        self._music_panel = None
        self.track_menu = None
        self.panel_track_button = None
        if panel is not None:
            try:
                panel.destroy()
            except tk.TclError:
                pass
