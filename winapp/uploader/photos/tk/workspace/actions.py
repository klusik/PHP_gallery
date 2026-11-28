# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/workspace/actions.py
# Module Type: Windows Client UI mixin
# Purpose: Own a cohesive Photos workspace interaction domain.
# Responsibilities: Preserve PhotosWorkspace lifecycle and callback behavior.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Photos workspace behavior mixin."""

from .support import (
    BoundCreateReceipt,
    Button,
    Callable,
    CreateDispatch,
    GalleryMediaCache,
    GalleryThumbnailMemory,
    GlassCard,
    LINKING_CREATE_TITLE_MAX_BYTES,
    LinkedGalleryDetail,
    LinkedGalleryEditSnapshot,
    LinkedMediaDetails,
    LinkedMediaGalleryInfo,
    LinkedPhoto,
    LinkingGalleryEditFailure,
    LinkingSession,
    NATIVE_MEDIA_MAX_BYTES,
    PHOTOS_CACHE_PAGE_LIMIT,
    PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS,
    PHOTOS_CATALOG_POLL_MS,
    PHOTOS_GALLERY_EDITOR_COLUMNS,
    PHOTOS_GALLERY_EDITOR_ROWS,
    PHOTOS_HEADING_STACK_WIDTH,
    PHOTOS_INFO_CARD_MAX_HEIGHT,
    PHOTOS_INFO_CARD_MAX_WIDTH,
    PHOTOS_STORAGE_ACTIVE_POLL_MS,
    PHOTOS_STORAGE_BAR_HEIGHT,
    PHOTOS_STORAGE_BAR_WIDTH,
    PHOTOS_STORAGE_REFRESH_MS,
    PHOTOS_TREE_WIDTH,
    THUMBNAIL_CACHE_LIMIT_CHOICES_GIB,
    THUMBNAIL_DISK_DEFAULT_BYTES,
    _InfoGroup,
    _PALETTE,
    _StorageObservation,
    attach_inline_tooltip,
    create_smooth_icon,
    current_process_ram_bytes,
    gallery_edit_text_valid,
    gettext,
    photo_information_groups,
    queue,
    replace,
    set_thumbnail_cache_limit_gib,
    threading,
    thumbnail_quality_caption,
    tk,
    ttk,
    vertical_scroll_delta,
)

class PhotosWorkspaceActionsMixin:
    """Own one cohesive group of Photos workspace behavior."""

    def _set_preload_activity(self, active: bool) -> None:
        """Animate the existing smooth ring independently of photo progress.

        Args:
            active (bool): Whether listing, checks, waiting or transfer runs.
        Returns:
            None: Maintains one small animation timer and cancels it at rest.
        """
        if not active:
            if self._preload_activity_after is not None:
                self.after_cancel(self._preload_activity_after)
                self._preload_activity_after = None
            try:
                self._preload_activity.configure(image='', text='')
            except tk.TclError:
                # A frame Destroy callback can follow destruction of its label.
                pass
        elif self._preload_activity_after is None:
            self._animate_preload_activity()
    def _animate_preload_activity(self) -> None:
        """Advance pre-rendered activity artwork without cache or Pillow work.

        Returns:
            None: Schedules one bounded smooth-ring frame while work is active.
        """
        self._preload_activity_after = None
        if self._destroyed:
            return
        if self._catalogue_spinner_frames:
            self._preload_activity_index = (
                self._preload_activity_index + 1
            ) % len(self._catalogue_spinner_frames)
            self._preload_activity.configure(
                image=self._catalogue_spinner_frames[self._preload_activity_index],
            )
        else:
            self._preload_activity.configure(text='…')
        self._preload_activity_after = self.after(
            PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS, self._animate_preload_activity,
        )
    @staticmethod
    def _storage_bytes(value: int) -> str:
        """Format physical storage with consistent binary units.

        Args:
            value (int): Nonnegative observed physical bytes.
        Returns:
            str: Compact B/KiB/MiB/GiB text using powers of 1024.
        """
        scaled = float(value)
        for unit in ('B', 'KiB', 'MiB', 'GiB'):
            if scaled < 1024 or unit == 'GiB':
                return f'{scaled:.0f} {unit}' if unit == 'B' else f'{scaled:.1f} {unit}'
            scaled /= 1024
        return ''
    def _resize_storage(self, event: tk.Event) -> None:
        """Wrap storage text in a narrow viewer without moving other controls.

        Args:
            event (tk.Event): Storage heading's current mapped width.
        Returns:
            None: Updates text only when the layout mode changes.
        """
        narrow = (
            self._storage_parent is None
            and event.width < PHOTOS_HEADING_STACK_WIDTH
        )
        if narrow != self._storage_narrow:
            self._storage_narrow = narrow
            self._show_storage_snapshot()
    def _show_storage_snapshot(self) -> None:
        """Show total thumbnail count and bytes across both measured pools.

        Returns:
            None: Incomplete totals remain unknown; the detail keeps retained
                bytes separate from the ordinary thumbnail quota.
        """
        count, used, _limit, ready, error = self._storage_snapshot
        loading = not ready and not error
        if not loading:
            if self._storage_activity_after is not None:
                self.after_cancel(self._storage_activity_after)
                self._storage_activity_after = None
            self._storage_button.configure(image=self._storage_icon or '')
        elif self._storage_activity_after is None:
            self._animate_storage_activity()
        _, kept_count, kept_bytes, _, _other_bytes, kept_ready = (
            self._retained_storage_snapshot
        )
        total_count = (
            f'{count + kept_count:,}'
            if ready and count is not None and kept_ready and kept_count is not None
            else '—'
        )
        total_bytes = (
            self._storage_bytes(used + kept_bytes)
            if ready and used is not None and kept_ready and kept_bytes is not None
            else '—'
        )
        self._storage_text.set(
            '—' if total_count == '—' or total_bytes == '—' else
            ('' if self._storage_icon is not None else '▧ ')
            + total_count + ' · ' + total_bytes,
        )
        self._storage_hint.set(
            self._text('storage_scope_hint')
            + ('\n' + self._text('storage_unavailable') if error else ''),
        )
    def _animate_storage_activity(self) -> None:
        """Reuse prepared ring frames while initial storage accounting waits.

        Returns:
            None: Keeps one cheap Tk timer until a measured or unavailable
                snapshot arrives; no I/O or Pillow rendering occurs here.
        """
        self._storage_activity_after = None
        if (self._destroyed or self._storage_snapshot[3]
                or self._storage_snapshot[4]
                or not self._catalogue_spinner_frames):
            return
        self._storage_activity_index = (
            self._storage_activity_index + 1
        ) % len(self._catalogue_spinner_frames)
        self._storage_button.configure(
            image=self._catalogue_spinner_frames[self._storage_activity_index],
            compound='left',
        )
        self._storage_activity_after = self.after(
            PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS, self._animate_storage_activity,
        )
    def _request_storage_refresh(self) -> None:
        """Coalesce a live cache event into the existing storage observer.

        Returns:
            None: At most one owner worker remains active; a running observer
                gets a short continuation until its result and follow-up arrive.
        """
        if self._destroyed:
            return
        self._storage_refresh_requested = True
        if self._storage_refresh_waiting:
            return
        self._storage_refresh_waiting = True
        if self._storage_after is not None:
            self.after_cancel(self._storage_after)
            self._storage_after = None
        worker_active = (
            self._storage_worker is not None
            and self._storage_worker.is_alive()
        )
        delay = (
            PHOTOS_STORAGE_ACTIVE_POLL_MS if worker_active else 0
        )
        self._storage_after = self.after(delay, self._poll_storage)
    def _poll_storage(self) -> None:
        """Coalesce off-Tk accounting/RAM observations into a safe snapshot.

        Returns:
            None: Retains at most one worker and one result; teardown cancels
                recovery and the next timer. No scans or hashes run on Tk.
        """
        self._storage_after = None
        if self._destroyed:
            return
        try:
            snapshot, retained, ram, complete = self._storage_results.get_nowait()
            self._storage_snapshot = snapshot
            if retained[5] or not self._retained_storage_snapshot[5]:
                self._retained_storage_snapshot = retained
            if complete:
                self._storage_ram = ram
            self._show_storage_snapshot()
        except queue.Empty:
            pass
        worker_finished = (
            self._storage_worker is not None
            and not self._storage_worker.is_alive()
        )
        if worker_finished:
            self._storage_worker = None
            if (self._storage_worker_requested
                    and not self._storage_refresh_requested):
                self._storage_worker_requested = False
                self._storage_refresh_waiting = False
                self._storage_after = self.after(
                    PHOTOS_STORAGE_REFRESH_MS, self._poll_storage,
                )
                return
            self._storage_worker_requested = False
        if self._storage_worker is None:
            cancel = self._storage_cancel
            requested = self._storage_refresh_requested
            self._storage_refresh_requested = False

            def observe() -> None:
                """Read physical accounting without touching linked media.

                Returns:
                    None: Publishes one immutable storage/RAM observation.
                """
                try:
                    cache = GalleryMediaCache.for_current_user(
                        NATIVE_MEDIA_MAX_BYTES, thumbnails=True,
                    )
                except Exception:  # noqa: BLE001
                    cache = None
                try:
                    snapshot = (cache.thumbnail_storage_snapshot(cancel)
                                if cache is not None else
                                (None, None, None, False, 'unavailable'))
                except Exception:  # noqa: BLE001
                    snapshot = (None, None, None, False, 'unavailable')
                partial = (
                    snapshot,
                    (None, None, None, None, None, False),
                    None,
                    False,
                )
                if not cancel.is_set():
                    publish_result(partial)
                try:
                    retained = (cache.retained_storage_snapshot(cancel)
                                if cache is not None else
                                (None, None, None, None, None, False))
                except Exception:  # noqa: BLE001
                    retained = (None, None, None, None, None, False)
                try:
                    ram = current_process_ram_bytes()
                except Exception:  # noqa: BLE001
                    ram = None
                if not cancel.is_set():
                    publish_result((snapshot, retained, ram, True))

            def publish_result(
                result: _StorageObservation,
            ) -> None:
                """Replace a stale queued observation with the latest phase.

                Args:
                    result (_StorageObservation): Ordinary and retained snapshots,
                        process RAM bytes and completion of retained/RAM work.

                Returns:
                    None: The bounded queue keeps only the newest observation.
                """
                if cancel.is_set():
                    return
                try:
                    self._storage_results.put_nowait(result)
                    return
                except queue.Full:
                    pass
                try:
                    self._storage_results.get_nowait()
                except queue.Empty:
                    pass
                try:
                    self._storage_results.put_nowait(result)
                except queue.Full:
                    pass

            self._storage_worker = threading.Thread(
                target=observe, name='photos-storage-observation', daemon=True,
            )
            self._storage_worker_requested = requested
            self._storage_worker.start()
        polling_interval = (
            PHOTOS_STORAGE_ACTIVE_POLL_MS
            if self._storage_refresh_waiting
            else PHOTOS_STORAGE_REFRESH_MS
        )
        self._storage_after = self.after(
            polling_interval, self._poll_storage,
        )
    def _open_storage_context(self) -> None:
        """Open anchored physical storage detail and shared quota settings.

        Returns:
            None: Reuses glass context/focus/Escape owners. RAM describes this
                process only; unavailable per-process VRAM stays unknown.
        """
        card = self._open_context(
            self._context_event(self._storage_button),
            surface=self._storage_surface,
        )
        self._context_content(card, self._text('storage_scope'))
        count, used, limit, ready, _error = self._storage_snapshot
        galleries, thumbs, thumb_bytes, others, other_bytes, kept_ready = (
            self._retained_storage_snapshot
        )
        ordinary_ready = (
            ready and count is not None and used is not None and limit is not None
        )
        kept_complete = kept_ready and all(value is not None for value in (
            galleries, thumbs, thumb_bytes, others, other_bytes,
        ))
        ordinary_hint = (
            self._text('storage_ordinary_detail').format(
                count=f'{count:,}', used=self._storage_bytes(used),
                limit=self._storage_bytes(limit),
            ) if ordinary_ready else self._text('storage_ordinary_unknown')
        )
        kept_hint = (
            self._text('storage_keep_detail').format(
                galleries=f'{galleries:,}', thumbs=f'{thumbs:,}',
                thumb_bytes=self._storage_bytes(thumb_bytes),
                others=f'{others:,}', other_bytes=self._storage_bytes(other_bytes),
            ) if kept_complete else self._text('storage_keep_unknown')
        )
        ordinary_value = (
            f'{count:,} · {self._storage_bytes(used)} / {self._storage_bytes(limit)}'
            if ordinary_ready else '—'
        )
        kept_value = (
            f'{galleries:,} · {thumbs:,} · {self._storage_bytes(thumb_bytes)}'
            f'  /  {others:,} · {self._storage_bytes(other_bytes)}'
            if kept_complete else '—'
        )
        ram = '—' if self._storage_ram is None else self._storage_bytes(self._storage_ram)
        rows = (
            ('local', '▧', ordinary_value, ordinary_hint),
            ('pin', '⌖', kept_value, kept_hint),
            ('list', '≡', ram, self._text('storage_ram').format(value=ram)),
            ('info', 'ⓘ', '—', self._text('storage_vram')),
        )
        for icon, fallback, value, hint in rows:
            row = ttk.Frame(card, style='Card.TFrame')
            row.pack(anchor='w', fill='x', pady=(3, 0))
            artwork = create_smooth_icon(row, icon, (16, 16), tone='primary')
            mark = ttk.Label(
                row, image=artwork or '', text='' if artwork else fallback,
                style='Card.TLabel',
            )
            mark.image = artwork
            mark.pack(side='left', padx=(0, 5))
            label = ttk.Label(row, text=value, style='Card.TLabel')
            label.pack(side='left')
            hint_var = tk.StringVar(row, hint)
            row.storage_hint = hint_var
            for widget in (mark, label):
                attach_inline_tooltip(widget, hint_var, self._storage_surface or self.viewer_pane)
            if icon == 'local' and ordinary_ready and limit > 0:
                # A small quota proportion is visual only; accounting remains in
                # the storage observer and retained files remain outside the limit.
                bar = tk.Canvas(
                    row, width=PHOTOS_STORAGE_BAR_WIDTH,
                    height=PHOTOS_STORAGE_BAR_HEIGHT, highlightthickness=0,
                    background=_PALETTE['line'],
                )
                bar.pack(side='left', padx=(6, 0))
                bar.create_rectangle(
                    0, 0, round(PHOTOS_STORAGE_BAR_WIDTH * min(1.0, used / limit)),
                    PHOTOS_STORAGE_BAR_HEIGHT,
                    fill=_PALETTE['primary'], outline='',
                )
                attach_inline_tooltip(bar, hint_var, self._storage_surface or self.viewer_pane)
        quota_row = ttk.Frame(card, style='Card.TFrame')
        quota_row.pack(anchor='w', fill='x', pady=(6, 0))
        quota_icon = create_smooth_icon(quota_row, 'settings', (16, 16), tone='primary')
        quota_label = ttk.Label(
            quota_row, image=quota_icon or '', text='' if quota_icon else '⚙',
            style='Card.TLabel',
        )
        quota_label.image = quota_icon
        quota_label.pack(side='left', padx=(0, 5))
        quota_hint = tk.StringVar(quota_row, self._text('storage_limit'))
        quota_row.storage_hint = quota_hint
        attach_inline_tooltip(quota_label, quota_hint, self._storage_surface or self.viewer_pane)
        quota = tk.StringVar(card)
        current_limit = self._storage_snapshot[2]
        quota.set(str((current_limit or THUMBNAIL_DISK_DEFAULT_BYTES) // 1024**3))
        selector = ttk.Combobox(
            quota_row, textvariable=quota, state='readonly', width=4,
            values=[str(value) for value in THUMBNAIL_CACHE_LIMIT_CHOICES_GIB],
        )
        selector.pack(side='left')
        ttk.Label(quota_row, text=gettext('GiB'), style='Muted.TLabel').pack(side='left', padx=(4, 0))
        attach_inline_tooltip(selector, quota_hint, self._storage_surface or self.viewer_pane)
        status = ttk.Label(card, text='', style='Muted.TLabel')
        selector.bind(
            '<<ComboboxSelected>>',
            lambda _event: self._save_storage_limit(quota, selector, status),
        )
        selector.bind(
            '<Return>',
            lambda _event: self._save_storage_limit(quota, selector, status),
        )
        self._tag_context_widget(selector)
        self._place_context(card)
    def _save_storage_limit(
        self, quota: tk.StringVar, selector: ttk.Combobox, status: ttk.Label,
    ) -> None:
        """Persist one selected quota off Tk while preventing competing writes.

        Args:
            quota (tk.StringVar): Selected ordinary thumbnail limit in GiB.
            selector (ttk.Combobox): Read-only selector disabled during the write.
            status (ttk.Label): Inline error destination in the originating card.
        Returns:
            None: Restores the previous selection on failure and refreshes the
                observation after a successful configuration write.
        """
        previous = (self._storage_snapshot[2] or THUMBNAIL_DISK_DEFAULT_BYTES) // 1024**3
        try:
            value = int(quota.get())
        except ValueError:
            quota.set(str(previous))
            return
        if value not in THUMBNAIL_CACHE_LIMIT_CHOICES_GIB or self._context_busy:
            quota.set(str(previous))
            return
        if value == previous:
            return
        selector.configure(state='disabled')
        status.configure(text='')
        status.pack_forget()
        self._context_busy = True

        def finish(success: bool) -> None:
            """Reconcile the saved quota and restore the still-visible control.

            Args:
                success (bool): Whether the configuration owner saved the value.
            Returns:
                None: Publishes a fresh observation or an inline failure.
            """
            if success:
                count, used, _limit, ready, error = self._storage_snapshot
                self._storage_snapshot = (count, used, value * 1024**3, ready, error)
                self._request_storage_refresh()
            if selector.winfo_exists():
                if not success:
                    quota.set(str(previous))
                    status.configure(text=self._text('storage_save_failed'))
                    status.pack(anchor='w')
                selector.configure(state='readonly')

        def save() -> None:
            """Write one quota on a worker and deliver its result to Tk.

            Returns:
                None: Enqueues exactly one success or failure callback.
            """
            try:
                success = set_thumbnail_cache_limit_gib(value)
            except Exception:
                success = False
            self._context_results.put((None, lambda: finish(success)))

        try:
            threading.Thread(target=save, name='photos-storage-quota', daemon=True).start()
        except RuntimeError:
            self._context_busy = False
            finish(False)
            return
        self._schedule_poll()
    def _cancel_preload(self) -> None:
        """Cancel new reads, or dismiss a completed preload status strip.

        Returns:
            None: Keeps any explicitly pinned bytes and existing cache contents;
                acknowledges an inactive operation's result across polling and
                navigation until an explicit new preload starts.
        """
        if self._preload_worker is not None and self._preload_worker.is_alive():
            self._preload_worker.stop()
            self._preload_text.set(self._text('preload_cancelling'))
            self._preload_cancel.state(['disabled'])
        else:
            self._preload_status_dismissed = True
            self._preload_strip.grid_remove()
            self._set_preload_activity(False)
            self._preload_last = None
    def _cache_glyph(self, gallery_id: int) -> str:
        """Return the icon for the latest local coverage check.

        Args:
            gallery_id (int): Exact gallery being displayed.

        Returns:
            str: Cloud, partial/local circle, or dotted circle when unchecked;
                a pin marks a locally retained gallery.
        """
        glyph = {"cloud": "☁", "local": "●", "partial": "◐"}.get(
            self._cache_state.get(gallery_id, "unknown"), "◌",
        )
        return ('PIN ' if self._gallery_pins.get(gallery_id) is True else '') + glyph
    def _open_title_editor(self, event: tk.Event | None = None) -> str:
        """Open a server-synchronized title editor beside the visible heading.

        Args:
            event (tk.Event | None): Pointer/keyboard activation or pencil action.
        Returns:
            str: Tk break after opening an anchored editor for the exact gallery.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if session is not None and gallery_id is not None:
            anchor = self._context_event(self._gallery_title_label)
            card = self._open_context(anchor)
            self._begin_gallery_edit(card, session, gallery_id, 'title')
        return 'break'
    def _begin_gallery_edit(
        self, card: GlassCard, session: LinkingSession, gallery_id: int, field: str,
    ) -> None:
        """Fetch complete current server text before presenting an editable value.

        Args:
            card (GlassCard): Exact anchored editing surface.
            session (LinkingSession): Captured verified site/account session.
            gallery_id (int): Exact gallery whose metadata is edited.
            field (str): title or description selected by the pencil action.
        Returns:
            None: Shows fresh text/revision or an explicit approval/read failure.
        """
        if (card is not self._context_card or session is not self._session
                or session is not self._session_provider()):
            return
        self._gallery_edit_wait_id = None
        if self._context_busy:
            # Opening another editor during a preceding worker's completion
            # must yield to Tk, not lose the request or wait on a GUI lock.
            self._gallery_edit_wait_id = self.after(
                PHOTOS_CATALOG_POLL_MS,
                lambda: self._begin_gallery_edit(card, session, gallery_id, field),
            )
            return
        if not getattr(session, 'can_edit_gallery_metadata', lambda: False)():
            self._context_content(card, self._text('edit_requires_approval'))
            if self._manager is not None:
                self._context_action(card, self._text('approve_edit'), self._approve_admin)
            self._place_context(card)
            return
        cancel = self._cancel

        def read() -> Callable[[], None]:
            """Fetch a fresh one-use revision and full untruncated text.

            Returns:
                Callable[[], None]: Tk callback presenting only that server snapshot.
            """
            snapshot = session.read_gallery_edit(gallery_id, cancelled=cancel)
            return lambda: self._show_gallery_editor(card, session, snapshot, field)

        self._run_context(card, read, lambda: self._context_status(card, 'info_unavailable'))
    def _apply_gallery_edit_snapshot(self, snapshot: LinkedGalleryEditSnapshot) -> None:
        """Refresh local labels from a confirmed server read without remounting.

        Args:
            snapshot (LinkedGalleryEditSnapshot): Fresh canonical metadata snapshot.
        Returns:
            None: Updates the exact tree row, selected heading and catalogue cache.
        """
        gallery = self._galleries.get(snapshot.gallery_id)
        if gallery is None:
            return
        gallery = replace(gallery, title=snapshot.title)
        self._galleries[snapshot.gallery_id] = gallery
        self._render_tree([snapshot.gallery_id])
        if self._selected_gallery_id == snapshot.gallery_id:
            self._gallery_title.set(snapshot.title or self._text('untitled'))
            if self._viewer is not None:
                self._viewer._gallery_viewer_controller.gallery = gallery
        self._remember_catalogue_progress()
    def _show_gallery_editor(
        self, card: GlassCard, session: LinkingSession,
        snapshot: LinkedGalleryEditSnapshot, field: str, *,
        draft: str | None = None, failure: str | None = None,
    ) -> None:
        """Render an editable draft with inline feedback and no reload gate.

        Args:
            card (GlassCard): Current trigger-anchored editing surface.
            session (LinkingSession): Exact session that read this snapshot.
            snapshot (LinkedGalleryEditSnapshot): Full authoritative baseline.
            field (str): title or description being edited.
            draft (str | None): Retained user text after failure; None uses server text.
            failure (str | None): Optional localized inline failure key.
        Returns:
            None: Save and Cancel stay usable after automatic reconciliation.
        """
        if (card is not self._context_card or session is not self._session
                or session is not self._session_provider()):
            return
        if failure is None:
            self._apply_gallery_edit_snapshot(snapshot)
        self._context_content(card, self._text('edit_' + field))
        value = getattr(snapshot, field) if draft is None else draft
        width = max(1, min(PHOTOS_INFO_CARD_MAX_WIDTH, self.winfo_width() - 40))
        if field == 'description':
            editor = tk.Text(
                card, height=PHOTOS_GALLERY_EDITOR_ROWS,
                width=PHOTOS_GALLERY_EDITOR_COLUMNS, wrap='word', undo=True,
                background=_PALETTE['surface_alt'], foreground=_PALETTE['ink'],
                insertbackground=_PALETTE['ink'], relief='flat',
            )
            editor.insert('1.0', value)
            read_value = lambda: editor.get('1.0', 'end-1c')
        else:
            variable = tk.StringVar(card, value=value)
            editor = ttk.Entry(
                card, textvariable=variable, width=PHOTOS_GALLERY_EDITOR_COLUMNS,
            )
            editor._gallery_title_variable = variable
            read_value = variable.get
        editor.pack(fill='x', pady=(4, 4))
        self._tag_context_widget(editor)
        status = ttk.Label(
            card, text=self._text(failure) if failure else self._text('edit_online'),
            style='Muted.TLabel', wraplength=width,
        )
        status.pack(fill='x')
        self._tag_context_widget(status)
        save = lambda: self._submit_gallery_edit(
            card, session, snapshot, field, read_value(),
        )
        self._context_action(card, self._text('edit_confirm'), save)
        if field == 'title':
            editor.bind('<Return>', lambda _event: (save(), 'break')[1])
        else:
            editor.bind('<Control-Return>', lambda _event: (save(), 'break')[1])
        self._context_action(card, self._text('cancel'), self._dismiss_context)
        self._place_context(card)
        editor.focus_set()
    def _submit_gallery_edit(
        self, card: GlassCard, session: LinkingSession,
        snapshot: LinkedGalleryEditSnapshot, field: str, value: str,
    ) -> None:
        """Apply one explicit text intent and reconcile completion on a worker.

        Args:
            card (GlassCard): Exact editor containing the submitted draft.
            session (LinkingSession): Captured current site/account session.
            snapshot (LinkedGalleryEditSnapshot): Baseline displayed by this editor.
            field (str): title or description selected by the user.
            value (str): Complete submitted text, including description line breaks.
        Returns:
            None: Completes in place or retains an editable draft with inline feedback.
        """
        if (card is not self._context_card or session is not self._session
                or session is not self._session_provider()):
            return
        if not gallery_edit_text_valid(field, value):
            self._show_gallery_editor(
                card, session, snapshot, field, draft=value, failure='edit_invalid',
            )
            return
        cancel = self._cancel

        def write() -> Callable[[], None]:
            """Submit the desired field and automatically check its saved state.

            Returns:
                Callable[[], None]: Completion or an editable retained draft.
            """
            try:
                updated = session.apply_gallery_edit(
                    snapshot.gallery_id, field, value, cancelled=cancel,
                )
            except LinkingGalleryEditFailure as error:
                current = error.snapshot or snapshot
                failure = 'edit_conflict' if error.conflict else 'edit_uncertain'
                return lambda: self._show_gallery_editor(
                    card, session, current, field, draft=value, failure=failure,
                )
            except Exception:
                return lambda: self._show_gallery_editor(
                    card, session, snapshot, field, draft=value, failure='edit_uncertain',
                )
            return lambda: self._gallery_edit_saved(card, session, updated)

        self._run_context(
            card, write, lambda: self._show_gallery_editor(
                card, session, snapshot, field, draft=value, failure='edit_uncertain',
            ),
        )
    def _save_gallery_switch(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        field: str, value: bool,
    ) -> None:
        """Apply and reconcile a direct information-card switch automatically.

        Args:
            card (GlassCard): Current information card.
            session (LinkingSession): Exact current site/account session.
            gallery_id (int): Exact gallery whose setting was toggled.
            field (str): nsfw or listed server-owned boolean setting.
            value (bool): Explicit desired state shown by the switch.
        Returns:
            None: Preserves peer settings and revision checks without a reload action.
        """
        if (card is not self._context_card or session is not self._session
                or session is not self._session_provider()):
            return
        cancel = self._cancel

        def write() -> Callable[[], None]:
            """Apply the explicit boolean through the shared reconciliation owner.

            Returns:
                Callable[[], None]: Tk callback for canonical completion.
            """
            snapshot = session.apply_gallery_edit(
                gallery_id, field, value, cancelled=cancel,
            )
            return lambda: self._gallery_switch_saved(card, session, snapshot)

        self._run_context(
            card, write, lambda: self._gallery_switch_unconfirmed(card, session, gallery_id),
        )
    def _gallery_switch_saved(
        self, card: GlassCard, session: LinkingSession,
        snapshot: LinkedGalleryEditSnapshot,
    ) -> None:
        """Return a confirmed direct switch to fresh gallery information in place.

        Args:
            card (GlassCard): Originating information surface.
            session (LinkingSession): Exact current site/account session.
            snapshot (LinkedGalleryEditSnapshot): Canonical confirmed saved values.
        Returns:
            None: Applies confirmed labels and reloads read-only information.
        """
        if (card is not self._context_card or session is not self._session
                or session is not self._session_provider()):
            return
        self._apply_gallery_edit_snapshot(snapshot)
        self._fetch_gallery_info(card, session, snapshot.gallery_id)
    def _gallery_switch_unconfirmed(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Restore current information automatically with inline failure feedback.

        Args:
            card (GlassCard): Originating information surface.
            session (LinkingSession): Captured site/account session.
            gallery_id (int): Gallery whose result must be checked.
        Returns:
            None: Fetches observed settings without another user action or write.
        """
        if (card is not self._context_card or session is not self._session
                or session is not self._session_provider()):
            return
        self._fetch_gallery_info(
            card, session, gallery_id, notice='edit_uncertain',
        )
    def _gallery_edit_saved(
        self, card: GlassCard, session: LinkingSession,
        snapshot: LinkedGalleryEditSnapshot,
    ) -> None:
        """Apply confirmed values and close the completed inline editor.

        Args:
            card (GlassCard): Originating edit surface.
            session (LinkingSession): Captured site/account session.
            snapshot (LinkedGalleryEditSnapshot): Canonical confirmed stored values.
        Returns:
            None: Updates labels/catalogue without another confirmation or reload.
        """
        if (card is not self._context_card or session is not self._session
                or session is not self._session_provider()):
            return
        previous = self._galleries.get(snapshot.gallery_id)
        title_changed = previous is not None and previous.title != snapshot.title
        self._apply_gallery_edit_snapshot(snapshot)
        if title_changed:
            self._refresh_catalogue(keep_context=True)
        self._dismiss_context()
    def _gallery_info(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Add an explicit action for fetching gallery information.

        Args:
            card (GlassCard): Current gallery context surface.
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Gallery whose direct photos should be checked.

        Returns:
            None: Server metadata is requested only after activation.
        """
        self._context_action(
            card, "i " + self._text("gallery_info"),
            lambda: self._fetch_gallery_info(card, session, gallery_id),
        )
    def _fetch_gallery_info(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        *, notice: str | None = None,
    ) -> None:
        """Fetch gallery details and the existing local cache coverage on demand.

        Args:
            card (GlassCard): Context card that initiated the request.
            session (LinkingSession): Exact current linked session.
            gallery_id (int): Physical gallery identity.
            notice (str | None): Optional inline translation key after reconciliation.

        Returns:
            None: A detail card replaces the menu when the worker completes.
        """
        cancel = threading.Event()
        self._probe_cancel = cancel

        def read() -> Callable[[], None]:
            """Read validated detail fields and fallback inventory metadata.

            Returns:
                Callable[[], None]: Tk callback rendering this gallery's result.
            """
            details: LinkedMediaDetails | None = None
            method = getattr(session, "media_gallery_details", None)
            if callable(method):
                try:
                    details = method(gallery_id, cancelled=cancel)
                except Exception:
                    details = None
            try:
                info, state, visible = self._inspect_gallery_cache(
                    session, gallery_id, cancel,
                    include_admin_inventory=details is None,
                )
            except Exception:
                info, state, visible = None, "unknown", None
            return lambda: self._show_gallery_details(
                card, gallery_id, details, info, state, visible, notice=notice,
            )

        self._run_context(
            card, read,
            lambda: self._show_gallery_details(
                card, gallery_id, None, None, "unknown", None, notice=notice,
            ),
        )
    def _inspect_gallery_cache(
        self, session: LinkingSession, gallery_id: int, cancel: threading.Event,
        *, include_admin_inventory: bool = True,
    ) -> tuple[LinkedMediaGalleryInfo | None, str, int]:
        """Read direct metadata and inspect cache entries without media downloads.

        Args:
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Gallery whose authorized photos are inspected.
            cancel (threading.Event): Context dismissal and session change signal.
            include_admin_inventory (bool): Whether to use the legacy counts API.

        Returns:
            tuple[LinkedMediaGalleryInfo | None, str, int]: Optional Admin
                inventory, observed cache status and visible photo count.
        """
        info = (
            session.media_gallery_info(gallery_id, cancelled=cancel)
            if include_admin_inventory and session.can_read_admin_media()
            else None
        )
        after = None
        any_cached = False
        all_cached = True
        seen = 0
        for _page_index in range(PHOTOS_CACHE_PAGE_LIMIT):
            if cancel.is_set():
                break
            page = session.media_page(gallery_id, after, cancelled=cancel)
            for photo in page.photos:
                if cancel.is_set():
                    break
                detail = session.has_cached_photo(gallery_id, photo)
                thumb = session.has_cached_photo(gallery_id, photo, thumbnail=True)
                any_cached |= detail or thumb
                all_cached &= detail and thumb
                seen += 1
            after = page.next_after
            if after is None:
                break
        complete = (
            not cancel.is_set() and after is None
            and (info is None or seen == info.photo_count)
        )
        state = (
            "local" if complete and seen and all_cached
            else "partial" if any_cached
            else "cloud" if complete else "unknown"
        )
        return info, state, seen
    def _show_gallery_details(
        self, card: GlassCard, gallery_id: int,
        details: LinkedMediaDetails | None,
        info: LinkedMediaGalleryInfo | None, state: str,
        visible_count: int | None,
        *, notice: str | None = None,
    ) -> None:
        """Render grouped gallery metadata with cache and legacy fallback.

        Args:
            card (GlassCard): Context card that requested the information.
            gallery_id (int): Exact gallery represented by the card.
            details (LinkedMediaDetails | None): Optional current server fields.
            info (LinkedMediaGalleryInfo | None): Legacy Admin inventory.
            state (str): Observed local-cache coverage state.
            visible_count (int | None): Visible count, or None on read error.
            notice (str | None): Optional localized inline operation feedback.

        Returns:
            None: The active card shows available fields and cache status.
        """
        if card is not self._context_card:
            return
        fields: LinkedMediaDetails = dict(details or {})
        if info is not None:
            fields.setdefault("photo_count", info.photo_count)
            fields.setdefault("file_count", info.file_count)
            fields.setdefault("size", info.size)
        if visible_count is not None:
            fields.setdefault("photo_count", visible_count)
        self._cache_state[gallery_id] = state
        self._cache_counts.pop(gallery_id, None)
        self._refresh_cache_widgets(gallery_id)
        if gallery_id in self._galleries:
            self._render_tree([gallery_id])
        groups = [
            ("photos_workspace_group_inventory", [
                ("photos_workspace_field_photo_count", fields.get("photo_count")),
                ("photos_workspace_field_file_count", fields.get("file_count")),
                ("photos_workspace_field_size", fields.get("size")),
            ]),
            ("photos_workspace_group_gallery", [
                ("photos_workspace_field_title", fields.get("title")),
                ("photos_workspace_field_description", fields.get("description")),
                ("photos_workspace_field_child_count", fields.get("child_count")),
                ("photos_workspace_field_created_at", fields.get("created_at")),
                ("photos_workspace_field_updated_at", fields.get("updated_at")),
            ]),
            ("photos_workspace_group_access", [
                ("photos_workspace_field_visibility", fields.get("visibility")),
                ("photos_workspace_field_nsfw", fields.get("nsfw")),
                ("photos_workspace_field_listed",
                 fields.get("visibility") == "public" and fields.get("listed") is True),
                ("photos_workspace_field_hide_listing",
                 not fields["listed"] if fields.get("visibility") == "public"
                 and type(fields.get("listed")) is bool else None),
            ]),
            ("photos_workspace_group_thumbnail", [
                ("photos_workspace_field_thumbnail_settings",
                 thumbnail_quality_caption(fields)),
            ]),
        ]
        suffix = (self._text("cache_status") + ": "
                  + self._text("cache_" + state))
        if details is None:
            suffix += "\n" + self._text("details_fallback")
        if notice is not None:
            suffix = self._text(notice) + "\n" + suffix
        if self._probe_cancel is not None:
            self._probe_cancel = None
        session = self._session
        actions: dict[str, Callable[[], None]] = {}
        switches: dict[str, tuple[bool, Callable[[bool], None]]] = {}
        if session is not None:
            for field in ('title', 'description'):
                actions['photos_workspace_field_' + field] = (
                    lambda field=field: self._begin_gallery_edit(
                        card, session, gallery_id, field,
                    )
                )
            if session.can_change_gallery_visibility():
                actions['photos_workspace_field_visibility'] = (
                    lambda: self._gallery_visibility(card, session, gallery_id)
                )
            if getattr(session, 'can_edit_gallery_metadata', lambda: False)():
                if type(fields.get('nsfw')) is bool:
                    switches['photos_workspace_field_nsfw'] = (
                        fields['nsfw'], lambda value: self._save_gallery_switch(
                            card, session, gallery_id, 'nsfw', value,
                        ),
                    )
                if (fields.get('visibility') == 'public'
                        and type(fields.get('listed')) is bool):
                    switches['photos_workspace_field_hide_listing'] = (
                        not fields['listed'],
                        lambda value: self._save_gallery_switch(
                            card, session, gallery_id, 'listed', not value,
                        ),
                    )
        self._render_info_card(
            card, self._text("gallery_info"), groups,
            suffix=suffix, actions=actions, switches=switches,
        )
    def _show_details(
        self, card: GlassCard, title: str, fields: LinkedMediaDetails, *,
        session: LinkingSession, fallback: bool,
    ) -> None:
        """Render grouped photo metadata while protecting location information.

        Args:
            card (GlassCard): Context card that requested the information.
            title (str): Localized card title.
            fields (LinkedMediaDetails): Bounded scalar metadata from the server.
            session (LinkingSession): Current permission scope for sensitive rows.
            fallback (bool): Whether local descriptor fields were used.

        Returns:
            None: Only fields approved for the live scope are displayed.
        """
        if card is not self._context_card:
            return
        if self._probe_cancel is not None:
            self._probe_cancel = None
        groups = self._photo_info_groups(fields, session)
        self._render_info_card(
            card, title, groups,
            suffix=(self._text("details_fallback") if fallback else ""),
        )
    def _photo_info_groups(
        self, fields: LinkedMediaDetails, session: LinkingSession,
    ) -> list[_InfoGroup]:
        """Prepare the same authorized metadata groups for both Photos views.

        Args:
            fields (LinkedMediaDetails): Bounded scalar metadata response.
            session (LinkingSession): Scope controlling sensitive location rows.

        Returns:
            list[_InfoGroup]: Ordered localized group keys and typed field rows.
        """
        return photo_information_groups(
            fields, include_location=session.can_read_admin_media(),
        )
    def _render_info_card(
        self, card: GlassCard, title: str, groups: list[_InfoGroup], *,
        suffix: str = "",
        actions: dict[str, Callable[[], None]] | None = None,
        switches: dict[str, tuple[bool, Callable[[bool], None]]] | None = None,
    ) -> None:
        """Replace a context menu with compact, wrapped metadata rows.

        Args:
            card (GlassCard): Active anchored information surface.
            title (str): Localized heading.
            groups (list[_InfoGroup]): Ordered localized headings and rows.
            suffix (str): Optional cache state or compatibility note.
            actions (dict[str, Callable[[], None]] | None): Field keys mapped to
                explicit edit actions; None keeps all rows read-only.
            switches (dict[str, tuple[bool, Callable[[bool], None]]] | None):
                Field keys mapped to current booleans and explicit save actions.

        Returns:
            None: Rows wrap to fit the current Photos workspace width.
        """
        if card is not self._context_card:
            return
        for child in card.winfo_children():
            child.destroy()
        width = max(160, min(
            PHOTOS_INFO_CARD_MAX_WIDTH - 40, self.winfo_width() - 60,
        ))
        body = ttk.Frame(card, style="Card.TFrame")
        canvas = tk.Canvas(
            body, highlightthickness=0, borderwidth=0,
            background=ttk.Style().lookup("Card.TLabel", "background"),
        )
        scrollbar = ttk.Scrollbar(body, orient="vertical", command=canvas.yview)
        canvas.configure(yscrollcommand=scrollbar.set)
        body.pack(fill="both", expand=True)
        canvas.pack(side="left", fill="both", expand=True)
        scrollbar.pack(side="right", fill="y")
        content = ttk.Frame(canvas, style="Card.TFrame", padding=(2, 1))
        content_id = canvas.create_window(
            (0, 0), window=content, anchor="nw", width=width,
        )
        for widget in (body, canvas, scrollbar, content):
            self._tag_context_widget(widget)

        def scroll_detail(event: tk.Event, *, precise: bool = False) -> None:
            """Scroll the metadata view by native wheel or touchpad input.

            Args:
                event (tk.Event): Native Tk wheel/precise event.
                precise (bool): Whether this is Tk's pixel delta event.

            Returns:
                None: Wheel motion affects only this card's metadata viewport.
            """
            delta = vertical_scroll_delta(self, event, precise=precise)
            if precise:
                bounds = canvas.bbox("all")
                if bounds is None:
                    return
                content_height = bounds[3] - bounds[1]
                if content_height > 0 and delta:
                    current = canvas.yview()[0]
                    canvas.yview_moveto(max(
                        0.0, min(1.0, current - delta / content_height),
                    ))
            elif delta:
                canvas.yview_scroll(-1 if delta > 0 else 1, "units")

        def bind_scroll(widget: tk.Misc) -> None:
            """Bind scroll input directly to each metadata row.

            Args:
                widget (tk.Misc): Row or canvas receiving pointer events.

            Returns:
                None: Tk 9 precise input is optional on older supported runtimes.
            """
            widget.bind("<MouseWheel>", scroll_detail, add="+")
            widget.bind("<Button-4>", scroll_detail, add="+")
            widget.bind("<Button-5>", scroll_detail, add="+")
            try:
                widget.bind(
                    "<TouchpadScroll>",
                    lambda event: scroll_detail(event, precise=True), add="+",
                )
            except tk.TclError:
                pass

        bind_scroll(canvas)
        ttk.Label(
            content, text=title, style="Card.TLabel", wraplength=width,
        ).pack(fill="x")
        self._tag_context_widget(content.winfo_children()[-1])
        bind_scroll(content.winfo_children()[-1])
        for heading, rows in groups:
            rows = [(key, value) for key, value in rows if value is not None]
            if not rows:
                continue
            ttk.Label(
                content,
                text=self._text(heading.removeprefix("photos_workspace_")),
                style="Muted.TLabel",
            ).pack(anchor="w", pady=(6, 1))
            self._tag_context_widget(content.winfo_children()[-1])
            bind_scroll(content.winfo_children()[-1])
            for key, value in rows:
                label = self._text(key.removeprefix("photos_workspace_"))
                rendered = self._format_detail_value(key, value)
                if switches and key in switches:
                    state, command = switches[key]
                    variable = tk.BooleanVar(card, value=state)
                    control = ttk.Checkbutton(
                        content, text=label, style='Card.TCheckbutton',
                        variable=variable,
                        command=lambda var=variable, save=command: save(var.get()),
                    )
                    control._gallery_setting_variable = variable
                    control.pack(fill='x', anchor='w')
                    self._tag_context_widget(control)
                    bind_scroll(control)
                elif actions and key in actions:
                    row = ttk.Frame(content, style='Card.TFrame')
                    row.pack(fill='x', anchor='w')
                    text = ttk.Label(
                        row, text=label + ': ' + rendered, style='Card.TLabel',
                        wraplength=max(1, width - 32), justify='left',
                    )
                    text.pack(side='left', fill='x', expand=True)
                    edit = Button(
                        row, text='▾' if key.endswith('_visibility') else '✎',
                        style='Action.Photos.Icon.TButton', width=-1,
                        command=actions[key], takefocus=True,
                    )
                    edit.pack(side='right')
                    attach_inline_tooltip(edit, label, card)
                    for widget in (row, text, edit):
                        self._tag_context_widget(widget)
                        bind_scroll(widget)
                else:
                    ttk.Label(
                        content, text=label + ": " + rendered,
                        style="Card.TLabel", wraplength=width,
                        justify="left",
                    ).pack(fill="x", anchor="w")
                    self._tag_context_widget(content.winfo_children()[-1])
                    bind_scroll(content.winfo_children()[-1])
        if suffix:
            ttk.Label(
                content, text=suffix, style="Muted.TLabel",
                wraplength=width, justify="left",
            ).pack(fill="x", pady=(6, 0))
            self._tag_context_widget(content.winfo_children()[-1])
            bind_scroll(content.winfo_children()[-1])
        content.update_idletasks()
        def resize_content(_event: tk.Event) -> None:
            """Update scroll bounds after metadata rows change.

            Args:
                _event (tk.Event): Content frame geometry notification.

            Returns:
                None: The scrollbar follows the measured content height.
            """
            canvas.configure(scrollregion=canvas.bbox("all"))

        def resize_canvas(event: tk.Event) -> None:
            """Keep the content width matched to the viewport.

            Args:
                event (tk.Event): Canvas geometry notification.

            Returns:
                None: Wrapped values use the available narrow or wide width.
            """
            canvas.itemconfigure(content_id, width=event.width)

        content.bind("<Configure>", resize_content, add="+")
        canvas.bind("<Configure>", resize_canvas, add="+")
        content_height = content.winfo_reqheight() + 6
        maximum_height = min(
            PHOTOS_INFO_CARD_MAX_HEIGHT - 20,
            max(100, self.winfo_height() - 24),
            content_height,
        )
        body.configure(height=maximum_height, width=width + 22)
        body.pack_propagate(False)
        card.update_idletasks()
        card.focus_set()
        self._place_context(card)
    def _format_detail_value(self, key: str, value: str | int | float | bool) -> str:
        """Format validated scalar metadata for a readable localized row.

        Args:
            key (str): Stable metadata field key.
            value (str | int | float | bool): Validated scalar from the server.

        Returns:
            str: Safe readable value with byte sizes and visibility localized.
        """
        if key.endswith("_size") or key == "photos_workspace_field_size":
            if type(value) is int:
                return self._format_bytes(value)
        if isinstance(value, bool):
            return self._text("yes" if value else "no")
        if key.endswith("_gps_altitude") and isinstance(value, (int, float)):
            return f"{value:g} m"
        if key.endswith("_visibility"):
            visibility = "unpublished" if value == "draft" else str(value)
            return self._text("visibility_state_" + visibility)
        return "".join(char if char.isprintable() else " " for char in str(value))
    def _gallery_visibility(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Read the stored gallery state before showing explicit eye actions.

        Args:
            card (GlassCard): Originating gallery context card.
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Selected physical gallery ID.

        Returns:
            None: Shows choices only after a validated revision is available.
        """
        cancel = self._cancel

        def read() -> Callable[[], None]:
            """Read the current one-use gallery visibility snapshot.

            Returns:
                Callable[[], None]: Tk callback displaying the fresh choices.
            """
            detail = session.gallery_detail(gallery_id, cancelled=cancel)
            return lambda: self._show_gallery_visibility(card, session, detail)

        self._run_context(
            card, read, lambda: self._context_status(card, "info_unavailable"),
        )
    def _show_gallery_visibility(
        self, card: GlassCard, session: LinkingSession, detail: LinkedGalleryDetail,
    ) -> None:
        """Offer exact gallery visibility choices for a verified revision.

        Args:
            card (GlassCard): Originating gallery context surface.
            session (LinkingSession): Session that read the snapshot.
            detail (LinkedGalleryDetail): Exact revision and stored visibility.

        Returns:
            None: Available explicit actions replace the loading heading.
        """
        if card is not self._context_card:
            return
        current = "unpublished" if detail.visibility == "draft" else detail.visibility
        self._context_content(
            card, "👁 " + self._text("visibility_state_" + current),
        )
        cancel = self._cancel
        for choice in ("public", "private", "unpublished"):
            if choice == current:
                continue
            self._context_action(
                card, self._text("visibility_" + choice),
                lambda choice=choice: self._run_context(
                    card,
                    lambda: self._change_gallery(session, detail, choice, cancel, card),
                    lambda: self._visibility_done(card, detail.gallery_id, False),
                ),
            )
        self._place_context(card)
    def _change_gallery(
        self, session: LinkingSession, detail: LinkedGalleryDetail, choice: str,
        cancel: threading.Event, card: GlassCard,
    ) -> Callable[[], None]:
        """Submit a gallery visibility edit once against the reviewed state.

        Args:
            session (LinkingSession): Current linked session.
            detail (LinkedGalleryDetail): Previously presented exact snapshot.
            choice (str): Explicit target visibility.
            cancel (threading.Event): Workspace session lifetime signal.
            card (GlassCard): Originating card for the result.

        Returns:
            Callable[[], None]: Tk callback for a confirmed result.
        """
        session.change_gallery_visibility(detail, choice, cancelled=cancel)
        return lambda: self._visibility_done(card, detail.gallery_id, True)
    def _write_photo_visibility(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        photo: LinkedPhoto, target: str,
    ) -> None:
        """Submit the selected photo's explicit visibility action.

        Args:
            card (GlassCard): Originating photo context surface.
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Direct owner of the clicked photo.
            photo (LinkedPhoto): Authorized photo row containing its current state.
            target (str): Explicit public or private target.

        Returns:
            None: One request runs asynchronously against the exact photo.
        """
        cancel = self._cancel

        def write() -> Callable[[], None]:
            """Request one revision-checked photo visibility change.

            Returns:
                Callable[[], None]: Tk callback for a confirmed result.
            """
            session.change_photo_visibility(gallery_id, photo, target, cancelled=cancel)
            return lambda: self._visibility_done(card, gallery_id, True)

        self._run_context(
            card, write, lambda: self._visibility_done(card, gallery_id, False),
        )
    def _visibility_done(self, card: GlassCard, gallery_id: int, saved: bool) -> None:
        """Refresh authoritative media after either a confirmed or uncertain edit.

        Args:
            card (GlassCard): Originating contextual surface.
            gallery_id (int): Exact owner of the submitted visibility action.
            saved (bool): Whether the server acknowledged and verified the edit.

        Returns:
            None: Keeps the gallery selection and avoids replaying the write.
        """
        previous_cache = self._cache_state
        self._cache_state = {}
        self._render_tree(list(previous_cache))
        self._thumbnail_memory.clear()
        self._thumbnail_memory = GalleryThumbnailMemory()
        self._folder_shelf.show_children(None, None, ())
        gallery = self._galleries.get(gallery_id)
        if gallery is not None and self._selected_gallery_id == gallery_id:
            if card is self._context_card:
                self._context_origin = self.tree
            self._select_gallery(gallery)
        else:
            self._sync_subgalleries()
        self._context_status(card, "saved" if saved else "visibility_uncertain")
    def _creation_form(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str = "", error: bool = False,
    ) -> None:
        """Collect or retain a gallery draft in its chosen location.

        Args:
            card (GlassCard): Current context surface for the action.
            session (LinkingSession): Exact active session with create consent.
            parent_id (int | None): Clicked parent gallery, or web root.
            title (str): Draft retained after a failed request; empty initially.
            error (bool): Show an ordinary inline creation error when true.

        Returns:
            None: Shows the editable name and one Create action.
        """
        if card is not self._context_card:
            return
        key = "create_root" if parent_id is None else "create_child"
        self._context_content(card, self._text(key))
        if parent_id is not None:
            parent = self._galleries.get(parent_id)
            if parent is None:
                self._creation_context_changed(card)
                return
            ttk.Label(
                card, text=parent.title, style="Card.TLabel",
                wraplength=PHOTOS_TREE_WIDTH,
            ).pack(fill="x")
        entry = ttk.Entry(card)
        entry.insert(0, title)
        entry.pack(fill="x", pady=(4, 0))
        if error:
            ttk.Label(
                card, text=self._text("create_failed"), style="Muted.TLabel",
                wraplength=PHOTOS_TREE_WIDTH,
            ).pack(fill="x", pady=(4, 0))
        action = self._context_action(
            card, self._text("create"),
            lambda: self._create_gallery(card, session, parent_id, entry.get().strip()),
        )
        entry.bind("<Return>", lambda _event: action.invoke())
        self._place_context(card)
        entry.focus_set()
    def _create_gallery(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str,
    ) -> None:
        """Submit one gallery draft with automatic same-request recovery.

        Args:
            card (GlassCard): Originating name form.
            session (LinkingSession): Exact verified session at submission.
            parent_id (int | None): Chosen parent ID, or root.
            title (str): Entered physical gallery title.

        Returns:
            None: Starts creation while keeping authority and cancellation gates.
        """
        manager = self._manager
        if not title or manager is None or self._context_busy:
            return
        try:
            valid_title = (
                bool(title.strip(' \t\n\r\x00\x0b'))
                and len(title.encode('utf-8')) <= LINKING_CREATE_TITLE_MAX_BYTES
                and not any(ord(char) < 32 or ord(char) == 127 for char in title)
            )
        except UnicodeError:
            valid_title = False
        if not valid_title:
            if card is self._context_card:
                self._context_content(card, self._text('create_invalid_title'))
                self._place_context(card)
            return
        identity = manager.photos_site_identity(session)
        if (identity is None or not self._creation_context_is_current(
                card, session, parent_id, identity)):
            self._creation_context_changed(card)
            return
        dispatch = CreateDispatch()
        self._create_dispatch = dispatch
        self._run_photos_gallery_create(
            card, session, parent_id, title, dispatch,
        )
    def _creation_context_is_current(
        self, card: GlassCard, session: LinkingSession,
        parent_id: int | None, identity: tuple[str, int],
    ) -> bool:
        """Recheck the exact context, live account and direct parent on Tk.

        Args:
            card (GlassCard): Context card captured for the submitted draft.
            session (LinkingSession): Exact live session captured by Photos.
            parent_id (int | None): Chosen direct parent, or the web root.
            identity (tuple[str, int]): Canonical issuer and verified account.

        Returns:
            bool: True while the same card/session and parent remain available.
        """
        manager = self._manager
        if (manager is None or card is not self._context_card
                or self._session is not session
                or self._session_provider() is not session
                or not session.available() or not session.can_create_gallery()
                or manager.photos_site_identity(session) != identity):
            return False
        if parent_id is None:
            return True
        parent = self._galleries.get(parent_id)
        return (parent is not None and parent.gallery_id == parent_id
                and self.tree.exists(str(parent_id)))
    def _run_photos_gallery_create(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str, dispatch: CreateDispatch,
    ) -> None:
        """Create or recover the submitted gallery without intermediate prompts.

        Args:
            card (GlassCard): Originating current Photos context.
            session (LinkingSession): Exact current live session.
            parent_id (int | None): Captured direct parent or web root.
            title (str): User-entered physical gallery title.
            dispatch (CreateDispatch): Cancellation gate tied to this context.

        Returns:
            None: A confirmed gallery or the retained editable draft reaches Tk.
        """
        manager = self._manager
        if manager is None:
            self._finish_photos_create_dispatch(dispatch)
            return

        def create() -> Callable[[], None]:
            """Run the journal owner without touching Tk.

            Returns:
                Callable[[], None]: Completion for the receipt or retained draft.
            """
            try:
                receipt = manager.create_photos_gallery(
                    session, title, parent_id, dispatch,
                )
            except Exception as error:
                manager.record_photos_creation_failure('create', error)
                return lambda: self._finish_photos_create_recovery(
                    card, dispatch, session, parent_id, title,
                )
            return lambda: self._finish_photos_create_success(
                card, session, receipt, dispatch,
            )

        self._run_context(
            card, create,
            lambda: self._finish_photos_create_recovery(
                card, dispatch, session, parent_id, title,
            ),
        )
    def _finish_photos_create_dispatch(self, dispatch: CreateDispatch) -> None:
        """Release a finished context's cancellation gate on the Tk thread.

        Args:
            dispatch (CreateDispatch): Gate created for one Photos create flow.

        Returns:
            None: A later context cannot cancel an already finished request.
        """
        if self._create_dispatch is dispatch:
            self._create_dispatch = None
    def _finish_photos_create_success(
        self, card: GlassCard, session: LinkingSession,
        receipt: BoundCreateReceipt, dispatch: CreateDispatch,
    ) -> None:
        """Clear the gate and present the newly confirmed gallery.

        Args:
            card (GlassCard): Originating context card, if still open.
            session (LinkingSession): Session that completed the request.
            receipt (BoundCreateReceipt): Freshly confirmed gallery receipt.
            dispatch (CreateDispatch): Gate owned by this completed operation.

        Returns:
            None: The result is reconciled through the existing Photos tree.
        """
        self._finish_photos_create_dispatch(dispatch)
        self._creation_completed(card, session, receipt)
    def _finish_photos_create_recovery(
        self, card: GlassCard, dispatch: CreateDispatch,
        session: LinkingSession, parent_id: int | None, title: str,
    ) -> None:
        """Clear a finished gate and restore the editable draft.

        Args:
            card (GlassCard): Originating context, if it remains mounted.
            dispatch (CreateDispatch): Gate owned by this finished operation.
            session (LinkingSession): Session captured for the create draft.
            parent_id (int | None): Captured direct parent or web root.
            title (str): Captured current user-entered title.

        Returns:
            None: Retry uses the same request evidence through the journal owner.
        """
        self._finish_photos_create_dispatch(dispatch)
        if self._session is session and self._session_provider() is session:
            self._creation_form(card, session, parent_id, title, error=True)
        else:
            self._creation_context_changed(card)
    def _creation_context_changed(self, card: GlassCard) -> None:
        """Refuse a stale Photos card without starting or acknowledging work.

        Args:
            card (GlassCard): Previously captured context card.

        Returns:
            None: A current card receives the localized stale-context message.
        """
        if card is self._context_card:
            self._context_content(card, self._text('create_context_changed'))
            self._place_context(card)
    def _creation_completed(
        self, card: GlassCard, session: LinkingSession,
        receipt: BoundCreateReceipt,
    ) -> None:
        """Admit the confirmed gallery without a user acknowledgement step.

        Args:
            card (GlassCard): Originating context, if still open.
            session (LinkingSession): Exact link that completed the creation.
            receipt (BoundCreateReceipt): Validated gallery and original intent.

        Returns:
            None: A current receipt makes its gallery immediately actionable.
                The first root becomes active; existing selection is preserved.
                Stale session results cannot change the catalogue.
        """
        if (self._session is not session
                or self._session_provider() is not session
                or not session.available()):
            self._creation_context_changed(card)
            return
        gallery = receipt.gallery
        had_selection = self._selected_gallery_id is not None
        self._galleries[gallery.gallery_id] = gallery
        # Creation and recovered status receipts are fresh server responses,
        # just like catalogue pages. Admit this exact row before rendering or
        # selecting it; a display-only cached row must still remain blocked.
        self._fresh_catalogue_ids.add(gallery.gallery_id)
        if gallery.parent_id is not None:
            self._children_by_parent.setdefault(
                gallery.parent_id, set(),
            ).add(gallery.gallery_id)
            parent = str(gallery.parent_id)
            if self.tree.exists(parent):
                self.tree.item(parent, open=True)
        self._render_tree([gallery.gallery_id])
        self._sync_subgalleries()
        self._remember_catalogue_progress()
        if not had_selection and gallery.parent_id is None:
            self.tree_status.configure(text="")
            self._select_gallery(gallery)
        if card is self._context_card:
            self._context_content(
                card,
                self._text('created').format(title=gallery.title),
            )
            self._context_action(
                card, self._text('close'), self._dismiss_context,
            ).focus_set()
            self._place_context(card)
    def _on_destroy(self, event: tk.Event) -> None:
        """Cancel page work and release the embedded viewer on destruction.

        Args:
            event (tk.Event): Widget destruction event for this frame or child.

        Returns:
            None: Cleanup runs only when the workspace itself is destroyed.
        """
        if event.widget is not self or self._destroyed:
            return
        self._destroyed = True
        self._storage_cancel.set()
        if self._storage_activity_after is not None:
            self.after_cancel(self._storage_activity_after)
            self._storage_activity_after = None
        if self._storage_after is not None:
            self.after_cancel(self._storage_after)
            self._storage_after = None
        self._set_preload_activity(False)
        self._dismiss_context(restore=False)
        self._cancel_catalogue_continuation()
        self._cancel_cached_catalogue_mount()
        self._set_catalogue_loading(False)
        self._generation += 1
        self._inspector_generation += 1
        if self._inspector_cancel is not None:
            self._inspector_cancel.set()
            self._inspector_cancel = None
        self._inspector_pending = None
        self._cancel.set()
        self._catalogue_cancel.set()
        if self._preload_worker is not None:
            self._preload_worker.stop()
        if self._cache_after is not None:
            try:
                self.after_cancel(self._cache_after)
            except tk.TclError:
                pass
        if self._tree_width_save_after is not None:
            try:
                self.after_cancel(self._tree_width_save_after)
            except tk.TclError:
                pass
        if self._info_width_save_after is not None:
            try:
                self.after_cancel(self._info_width_save_after)
            except tk.TclError:
                pass
        if self._info_width_restore_after is not None:
            try:
                self.after_cancel(self._info_width_restore_after)
            except tk.TclError:
                pass
            self._info_width_restore_after = None
        if self._poll_id is not None:
            try:
                self.after_cancel(self._poll_id)
            except tk.TclError:
                pass
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._galleries.clear()
        self._thumbnail_memory.clear()
        if self._storage_parent is not None:
            try:
                self._storage_heading.destroy()
            except tk.TclError:
                pass
    def _invalidate_photo_info(
        self, gallery_id: int, image_ids: tuple[int, ...] | None = None,
    ) -> None:
        """Remove stale metadata after a confirmed mutation or gallery refresh.

        Args:
            gallery_id (int): Exact gallery whose metadata may have changed.
            image_ids (tuple[int, ...] | None): Exact changed photo IDs, or None
                to invalidate every cached photo in that gallery.

        Returns:
            None: Unrelated current-session metadata remains reusable.
        """
        targets = set(image_ids) if image_ids is not None else None
        for key in tuple(self._photo_info_cache):
            _session_id, cached_gallery, image_id = key
            if cached_gallery == gallery_id and (
                targets is None or image_id in targets
            ):
                self._photo_info_cache.pop(key, None)
