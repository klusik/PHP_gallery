# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/workspace/details.py
# Module Type: Windows Client UI mixin
# Purpose: Own a cohesive Photos workspace interaction domain.
# Responsibilities: Preserve PhotosWorkspace lifecycle and callback behavior.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Photos workspace behavior mixin."""

from .support import (
    Button,
    Callable,
    GalleryPreloadWorker,
    GlassCard,
    LinkedGallery,
    LinkedMediaDetails,
    LinkedPhoto,
    LinkingSession,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    PHOTOS_CACHE_REFRESH_MS,
    PHOTOS_CATALOG_POLL_MS,
    PHOTOS_INFO_CACHE_ENTRY_LIMIT,
    PHOTOS_INFO_CACHE_FRESH_SECONDS,
    PHOTOS_INFO_CARD_MAX_WIDTH,
    PHOTOS_INFO_GRID_RESERVE_WIDTH,
    PHOTOS_INFO_PANE_HEADER_INSET,
    PHOTOS_INFO_PANE_MIN_WIDTH,
    PHOTOS_INFO_PANE_ROW_INSET,
    PHOTOS_INFO_PANE_WIDTH,
    PHOTOS_INFO_RESTORE_DELAY_MS,
    PHOTOS_TREE_WIDTH,
    PreloadProgress,
    RaisedDialogSurface,
    _CacheRow,
    _log_safe_media_failure,
    attach_inline_tooltip,
    probe_gallery_cache_status,
    queue,
    threading,
    time,
    tk,
    ttk,
    vertical_scroll_delta,
)

class PhotosWorkspaceDetailsMixin:
    """Own one cohesive group of Photos workspace behavior."""

    def _bind_context_clicks(self) -> None:
        """Place a dismiss binding before child widget bindtags.

        Returns:
            None: Clicks outside the card dismiss it before child handlers run.
        """
        top = self.winfo_toplevel()
        self._context_click_binding = top.bind_class(
            self._context_bindtag, "<ButtonPress>",
            self._context_outside, add="+",
        )
        self._context_focus_binding = top.bind_class(
            self._context_bindtag, "<FocusOut>",
            self._context_focus_out, add="+",
        )

        def tag_widget(widget: tk.Misc) -> None:
            """Place context dismissal before a mapped widget's own actions.

            Args:
                widget (tk.Misc): Current or newly mapped widget in the window.

            Returns:
                None: The widget receives at most one temporary context tag.
            """
            if self._context_card is None or not isinstance(widget, tk.Misc):
                return
            self._tag_context_widget(widget)

        pending: list[tk.Misc] = [top]
        while pending:
            widget = pending.pop()
            if widget.winfo_viewable():
                tag_widget(widget)
                pending.extend(widget.winfo_children())
        binding = top.bind("<Map>", lambda event: tag_widget(event.widget), add="+")
        self._context_bindings.append(("<Map>", binding))
    def _tag_context_widget(self, widget: tk.Misc) -> None:
        """Place context dismissal before one widget's default event handlers.

        Args:
            widget (tk.Misc): Existing mapped control or active-card child.

        Returns:
            None: The widget observes outside presses and focus changes first.
        """
        try:
            tags = widget.bindtags()
            if self._context_bindtag not in tags:
                widget.bindtags((self._context_bindtag, *tags))
                self._context_tagged_widgets.append(widget)
        except tk.TclError:
            return
    def _context_focus_out(self, _event: tk.Event) -> None:
        """Check after focus transitions whether the card lost focus.

        Args:
            _event (tk.Event): Focus change delivered by the owning toplevel.

        Returns:
            None: Focus moving between controls inside the card keeps it open.
        """
        if (self._context_card is not None
                and self._context_focus_check_id is None):
            self._context_focus_check_id = self.after_idle(
                self._check_context_focus,
            )
    def _check_context_focus(self) -> None:
        """Dismiss the card when focus settles outside its descendants.

        Returns:
            None: External keyboard focus closes the current context card.
        """
        self._context_focus_check_id = None
        card = self._context_card
        focused = self.focus_get()
        if card is not None and not (
            focused is card
            or focused is not None
            and str(focused).startswith(str(card) + '.')
        ):
            self._dismiss_context(restore=False)
    def _open_context(
        self, event: tk.Event, *, surface: tk.Misc | None = None,
    ) -> RaisedDialogSurface:
        """Anchor a compact context to its trigger on the visible owner.

        Args:
            event (tk.Event): Trigger widget and screen coordinates.
            surface (tk.Misc | None): Optional visible parent for a global
                trigger, or None to place the card inside Photos.

        Returns:
            RaisedDialogSurface: Current contextual card above the gallery.
        """
        self._dismiss_context(restore=False)
        parent = surface if surface is not None else self
        card = RaisedDialogSurface(parent, padding=8, takefocus=True)
        self._context_card, self._context_origin = card, event.widget
        self._context_surface = parent
        self._context_xy = (
            event.x_root - parent.winfo_rootx(), event.y_root - parent.winfo_rooty(),
        )
        self._bind_context_clicks()
        for sequence, callback in (
            ("<Escape>", self._context_escape),
            ("<FocusOut>", self._context_focus_out),
        ):
            binding = self.winfo_toplevel().bind(sequence, callback, add="+")
            self._context_bindings.append((sequence, binding))
        if parent is not self:
            binding = self.winfo_toplevel().bind(
                '<Configure>', lambda _event: self._place_context(card), add='+',
            )
            self._context_bindings.append(('<Configure>', binding))
        card.bind("<Configure>", lambda _event: self._place_context(card), add="+")
        card.bind("<Map>", lambda _event: card.focus_set(), add="+")
        self._place_context(card)
        self._tag_context_widget(card)
        return card
    def _context_escape(self, _event: tk.Event) -> str:
        """Close the inline surface before other Escape actions.

        Args:
            _event (tk.Event): Main-window Escape event.

        Returns:
            str: Tk break marker after restoring focus.
        """
        self._dismiss_context()
        return "break"
    def _place_context(self, card: GlassCard | None) -> None:
        """Clamp the current card within its visible surface after changes.

        Args:
            card (GlassCard | None): The current surface, if one exists.

        Returns:
            None: The panel stays visible at desktop and narrow window widths.
        """
        if card is None or card is not self._context_card:
            return
        x, y = self._context_xy
        surface = self._context_surface
        width = max(card.winfo_reqwidth(), card.winfo_width())
        height = max(card.winfo_reqheight(), card.winfo_height())
        if self._context_origin is self._storage_button:
            # The header and Photos occupy different branches of the window.
            # Re-read the trigger after every layout rather than retaining the
            # screen coordinates captured before a resize or a text update.
            y = (self._storage_button.winfo_rooty()
                 + self._storage_button.winfo_height()
                 - surface.winfo_rooty())
            pending = list(card.winfo_children())
            while pending:
                child = pending.pop()
                if child.winfo_ismapped():
                    width = max(
                        width, child.winfo_rootx() + child.winfo_width()
                        - card.winfo_rootx(),
                    )
                pending.extend(child.winfo_children())
        top = self.winfo_toplevel()
        right = min(
            surface.winfo_width(),
            top.winfo_rootx() + top.winfo_width() - surface.winfo_rootx(),
        )
        bottom = min(
            surface.winfo_height(),
            top.winfo_rooty() + top.winfo_height() - surface.winfo_rooty(),
        )
        if self._context_origin is self._storage_button:
            # Align the right edge with the visible client/surface intersection.
            x = right - width
        card.place(
            x=max(0, min(x, right - width)),
            y=max(0, min(y, bottom - height)),
            # Coordinates above use the surface's exterior root origin.
            # Tk's default inside mode adds its internal border/padding again.
            bordermode='outside',
        )
        card.lift()
    def _context_content(self, card: GlassCard, title: str) -> None:
        """Replace the contents of a card with its current heading or status.

        Args:
            card (GlassCard): Current contextual card.
            title (str): Localized heading or status message.

        Returns:
            None: A wrapped heading and keyboard focus remain inside the surface.
        """
        card.focus_set()
        for child in card.winfo_children():
            child.destroy()
        ttk.Label(
            card, text=title, style="Card.TLabel",
            wraplength=min(PHOTOS_TREE_WIDTH, self.winfo_width()),
        ).pack(fill="x")
        self._tag_context_widget(card.winfo_children()[-1])
    def _context_action(
        self, card: GlassCard, text: str, command: Callable[[], None], *,
        destructive: bool = False,
    ) -> ttk.Button:
        """Add a compact themed action to the active card.

        Args:
            card (GlassCard): Contextual surface receiving the action.
            text (str): Localized action label.
            command (Callable[[], None]): Action invoked on the Tk thread.
            destructive (bool): Use the theme danger text for a permanent action.

        Returns:
            ttk.Button: Focusable action control.
        """
        button = Button(
            card, text=text, command=command,
            style=(
                'Danger.Action.Photos.Icon.TButton' if destructive
                else 'Action.Photos.Icon.TButton'
            ),
        )
        button.pack(fill="x", pady=(4, 0))
        self._tag_context_widget(button)
        return button
    def _run_context(
        self, card: GlassCard, operation: Callable[[], Callable[[], None]],
        failure: Callable[[], None], *, survive_view_change: bool = False,
    ) -> None:
        """Run one Photos context operation asynchronously on its exact session.

        Args:
            card (GlassCard): Contextual surface that initiated the work.
            operation (Callable[[], Callable[[], None]]): Worker returning a Tk
                callback for its validated result.
            failure (Callable[[], None]): Tk callback for an unavailable result.
            survive_view_change (bool): Keep terminal reconciliation bound to the
                captured session when another gallery is selected meanwhile.

        Returns:
            None: Admits at most one concurrent context operation.
        """
        if self._context_busy or self._session_provider() is not self._session:
            return
        self._context_content(card, self._text("working"))
        self._place_context(card)
        self._context_busy = True
        generation = None if survive_view_change else self._generation

        def work() -> None:
            """Queue the completion without touching Tk from the worker.

            Returns:
                None: Exactly one success or failure reaches the workspace queue.
            """
            try:
                completion = operation()
            except Exception:
                completion = failure
            self._context_results.put((generation, completion))

        worker = threading.Thread(target=work, name="photos-context", daemon=True)
        try:
            worker.start()
        except RuntimeError:
            self._context_busy = False
            failure()
            return
        self._schedule_poll()
    def _toggle_photo_selection(self, gallery_id: int, image_id: int) -> None:
        """Toggle one visible photo selection without opening its lightbox.

        Args:
            gallery_id (int): Gallery owning the current tile.
            image_id (int): Exact photo ID represented by that tile.

        Returns:
            None: Selection remains local to the current viewer instance.
        """
        controller = getattr(
            self._viewer, '_gallery_viewer_controller', None,
        )
        if (controller is None or gallery_id != self._selected_gallery_id
                or self._session is None):
            return
        controller.toggle_photo_selection(image_id)
        self._dismiss_context()
    def _confirm_photo_delete(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        image_ids: tuple[int, ...],
    ) -> None:
        """Show one anchored confirmation for the exact permanent photo set.

        Args:
            card (GlassCard): Contextual card anchored at the clicked tile.
            session (LinkingSession): Exact session whose delete scope is required.
            gallery_id (int): Exact gallery for the captured image IDs.
            image_ids (tuple[int, ...]): Unique exact IDs to delete after consent.

        Returns:
            None: The destructive request starts only from the final button.
        """
        if (not image_ids or len(image_ids) > NATIVE_MEDIA_DELETE_BATCH_MAX
                or self._session is not session
                or self._selected_gallery_id != gallery_id
                or not session.can_delete_admin_media()):
            return
        gallery = self._galleries.get(gallery_id)
        gallery_name = ' '.join(
            ''.join(char if char.isprintable() else ' ' for char in gallery.title).split()
        ) if gallery is not None else str(gallery_id)
        self._context_content(
            card, self._text('delete_confirm').format(
                count=len(image_ids), gallery=gallery_name,
            ),
        )
        self._context_action(
            card, self._text('cancel'), self._dismiss_context,
        )
        self._context_action(
            card, self._text('delete_permanently'),
            lambda: self._delete_photos(
                card, session, gallery_id, image_ids,
            ),
            destructive=True,
        )
        self._place_context(card)
    def _delete_photos(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        image_ids: tuple[int, ...],
    ) -> None:
        """Send one confirmed batch and reconcile its exact terminal outcome.

        Args:
            card (GlassCard): Confirmation card that initiated deletion.
            session (LinkingSession): Exact live site session captured by Photos.
            gallery_id (int): Exact physical gallery ID.
            image_ids (tuple[int, ...]): Exact confirmed photo IDs, in gallery order.

        Returns:
            None: The single server action is never automatically replayed.
        """
        def operation() -> Callable[[], None]:
            """Perform the one-shot deletion away from the Tk thread.

            Returns:
                Callable[[], None]: UI reconciliation for the captured operation.
            """
            deleted = session.delete_media_photos(gallery_id, image_ids)

            def complete() -> None:
                """Refresh only the still-mounted owner of the deleted gallery.

                Returns:
                    None: Confirmed IDs leave selection and metadata reloads in place.
                """
                if (self._session is not session
                        or self._session_provider() is not session):
                    return
                self._invalidate_photo_info(gallery_id, deleted)
                self._thumbnail_memory.remove_photos(gallery_id, deleted)
                controller = getattr(
                    self._viewer, '_gallery_viewer_controller', None,
                )
                if (self._selected_gallery_id == gallery_id and controller is not None):
                    controller.clear_photo_selection(deleted)
                    controller.refresh_photos()
                    controller.status.pack(anchor='w', pady=(4, 8))
                    controller.status.configure(text=self._text('delete_complete'))
                if self._context_card is card:
                    self._dismiss_context()

            return complete

        def failure() -> None:
            """Surface an ambiguous outcome without inviting request replay.

            Returns:
                None: The current gallery reloads to inspect server state.
            """
            def report() -> None:
                """Show uncertainty and request a passive current-gallery refresh.

                Returns:
                    None: The user must inspect the reloaded gallery before acting.
                """
                if self._session is not session:
                    return
                controller = getattr(
                    self._viewer, '_gallery_viewer_controller', None,
                )
                if (self._selected_gallery_id == gallery_id and controller is not None):
                    controller.status.pack(anchor='w', pady=(4, 8))
                    controller.status.configure(text=self._text('delete_uncertain'))
                    controller.refresh_photos()
                if self._context_card is card:
                    self._context_content(card, self._text('delete_uncertain'))
                    self._place_context(card)
            report()

        self._run_context(
            card, operation, failure, survive_view_change=True,
        )
    def _tree_context(self, event: tk.Event) -> str | None:
        """Open a gallery-row menu or the blank tree's root creation action.

        Args:
            event (tk.Event): Secondary pointer press in the gallery tree.

        Returns:
            str | None: Tk break for a handled Photos context.
        """
        item = self.tree.identify_row(event.y)
        return self._gallery_context(
            int(item) if item else None, event, allow_root=True,
        )
    def _viewer_context(
        self, gallery_id: int, photo: LinkedPhoto | None, event: tk.Event,
    ) -> str | None:
        """Dispatch an exact photo or empty-grid context.

        Args:
            gallery_id (int): Gallery owning the currently mounted grid.
            photo (LinkedPhoto | None): Exact clicked photo, or empty space.
            event (tk.Event): Secondary pointer press in the grid.

        Returns:
            str | None: Tk break for a handled Photos context.
        """
        session = self._session
        if session is None or self._selected_gallery_id != gallery_id:
            return None
        if photo is None:
            return self._gallery_context(gallery_id, event, creation_only=True)
        controller = getattr(
            self._viewer, '_gallery_viewer_controller', None,
        )
        selected_ids = (
            controller.selected_photo_ids() if controller is not None else ()
        )
        card = self._open_context(event)
        can_create = self._manager is not None and session.can_create_gallery()
        self._context_content(card, self._text("photo_menu"))
        if controller is not None:
            is_selected = photo.image_id in selected_ids
            self._context_action(
                card,
                self._text('deselect_photo' if is_selected else 'select_photo'),
                lambda: self._toggle_photo_selection(
                    gallery_id, photo.image_id,
                ),
            )
        if session.can_delete_admin_media():
            delete_ids = (
                selected_ids
                if photo.image_id in selected_ids else (photo.image_id,)
            )
            if len(delete_ids) <= NATIVE_MEDIA_DELETE_BATCH_MAX:
                delete_label = (
                    self._text('delete_selected').format(count=len(delete_ids))
                    if photo.image_id in selected_ids
                    else self._text('delete_photo')
                )
                self._context_action(
                    card, delete_label,
                    lambda: self._confirm_photo_delete(
                        card, session, gallery_id, delete_ids,
                    ),
                )
        self._context_action(
            card, self._text("photo_info"),
            lambda: self._photo_info(card, photo),
        )
        if can_create:
            self._add_gallery_creation_action(card, session, gallery_id)
        if photo.visibility is not None and session.can_change_photo_visibility():
            target = "private" if photo.visibility == "public" else "public"
            self._context_action(
                card, "👁 " + self._text("visibility_" + target),
                lambda: self._write_photo_visibility(
                    card, session, gallery_id, photo, target,
                ),
            )
        elif self._manager is not None and not session.can_read_admin_media():
            self._context_action(
                card, self._text("approve_admin"), self._approve_admin,
            )
        self._place_context(card)
        return "break"
    def _context_group(self, card: GlassCard, key: str) -> None:
        """Separate compact action categories with a quiet single-line heading.

        Args:
            card (GlassCard): Current trigger-anchored gallery menu.
            key (str): Localized category key within the Photos catalogue.
        Returns:
            None: Adds a small muted label without nesting another menu or card.
        """
        ttk.Label(card, text=self._text(key), style='Muted.TLabel').pack(
            fill='x', pady=(4, 0),
        )
    def _gallery_context(
        self, gallery_id: int | None, event: tk.Event, *,
        creation_only: bool = False, allow_root: bool = False,
    ) -> str | None:
        """Open the exact gallery's actions, or root creation on blank tree.

        Args:
            gallery_id (int | None): Target gallery ID, or web root.
            event (tk.Event): Pointer event anchoring the contextual card.
            creation_only (bool): Whether the trigger was blank photo-grid space.
            allow_root (bool): Whether a tree-row menu also offers root creation.

        Returns:
            str | None: Tk break for a handled Photos context.
        """
        session = self._session
        if session is None:
            return None
        can_create = self._manager is not None and session.can_create_gallery()
        card = self._open_context(event)
        title = (
            "root_menu" if gallery_id is None
            else "subgallery_menu" if creation_only else "gallery_menu"
        )
        heading = self._text(title)
        if not can_create:
            heading += "\n" + self._text("create_requires_approval")
        self._context_content(card, heading)
        if gallery_id is not None and not creation_only:
            self._context_group(card, 'menu_information')
            self._gallery_info(card, session, gallery_id)
            if session.can_change_gallery_visibility():
                self._context_action(
                    card, "👁 " + self._text("visibility"),
                    lambda: self._gallery_visibility(card, session, gallery_id),
                )
            if self._manager is not None and not session.can_read_admin_media():
                self._context_action(
                    card, self._text("approve_admin"), self._approve_admin,
                )
        gallery = self._galleries.get(gallery_id) if gallery_id is not None else None
        if gallery is not None:
            if not creation_only and session.can_read_media():
                self._context_group(card, 'menu_local_cache')
                self._add_cache_actions(card, session, gallery)
            editor = getattr(self._manager, 'open_photos_gallery_editor', None)
            host_actions = (
                self._start_watcher is not None and session.can_upload()
                or self._simbrief_action is not None
                and self._simbrief_available is not None and self._simbrief_available()
            )
            if not creation_only and (callable(editor) or host_actions):
                self._context_group(card, 'menu_tools')
            if not creation_only and self._manager is not None:
                open_editor = getattr(
                    self._manager, 'open_photos_gallery_editor', None,
                )
                if callable(open_editor):
                    self._context_action(
                        card, self._text('edit_gallery'),
                        lambda: self._launch_gallery_editor(
                            card, session, gallery,
                        ),
                    )
            self._add_host_gallery_actions(card, session, gallery)
        self._context_group(card, 'menu_create')
        self._add_gallery_creation_action(card, session, gallery_id)
        if gallery_id is not None and allow_root:
            self._add_gallery_creation_action(card, session, None)
        self._place_context(card)
        return "break"
    def _launch_gallery_editor(
        self, card: GlassCard, session: LinkingSession,
        gallery: LinkedGallery,
    ) -> None:
        """Open only the current listed gallery in its authenticated browser.

        Args:
            card (GlassCard): Gallery context containing the action.
            session (LinkingSession): Exact live session captured by the menu.
            gallery (LinkedGallery): Exact gallery row captured by the menu.

        Returns:
            None: Opens the editor or reports a localized safe failure in Photos.
        """
        open_editor = getattr(
            self._manager, 'open_photos_gallery_editor', None,
        )
        if (self._context_card is not card or self._session is not session
                or self._session_provider() is not session
                or self._galleries.get(gallery.gallery_id) is not gallery
                or not callable(open_editor)):
            self._dismiss_context()
            return
        try:
            opened = open_editor(session, gallery)
        except Exception:  # noqa: BLE001
            opened = False
        if opened:
            self._dismiss_context()
            return
        self.tree_status.configure(text=self._text('edit_gallery_failed'))
        self._context_content(card, self._text('edit_gallery_failed'))
        self._place_context(card)
    def selected_watcher_gallery(
        self,
    ) -> tuple[LinkingSession, LinkedGallery] | None:
        """Return the selected gallery for the current linked session.

        Returns:
            tuple[LinkingSession, LinkedGallery] | None: Exact current session
                and selected physical gallery, or None when selection is stale.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if (session is None or gallery_id is None
                or gallery_id not in self._fresh_catalogue_ids
                or self._session_provider() is not session):
            return None
        gallery = self._galleries.get(gallery_id)
        return (session, gallery) if gallery is not None else None
    def open_selected_watcher_context(self, event: tk.Event) -> str | None:
        """Open Watcher actions from an external rail using the shared card.

        Args:
            event (tk.Event): Pointer event from the Watcher rail trigger.

        Returns:
            str | None: Tk break for a handled selected-gallery context.
        """
        current = self.selected_watcher_gallery()
        if current is None or not current[0].can_upload():
            return None
        session, gallery = current
        card = self._open_context(event)
        self._context_content(card, self._text("watcher_menu"))
        if self._start_watcher is not None:
            self._context_action(
                card, self._text("start_watcher"),
                lambda: self._launch_watcher(card, session, gallery),
            )
        if (self._simbrief_action is not None
                and self._simbrief_available is not None
                and self._simbrief_available()):
            self._context_action(
                card, self._text("simbrief"),
                lambda: self._launch_simbrief(card),
            )
        self._place_context(card)
        return "break"
    def _add_host_gallery_actions(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Add host-owned gallery actions when their callbacks are available.

        Args:
            card (GlassCard): Exact gallery's active context card.
            session (LinkingSession): Exact live session for this gallery.
            gallery (LinkedGallery): Physical row represented by this action.

        Returns:
            None: Only explicitly authorized, configured host actions appear.
        """
        if self._start_watcher is not None and session.can_upload():
            self._context_action(
                card, self._text("start_watcher"),
                lambda: self._launch_watcher(card, session, gallery),
            )
        if (self._simbrief_action is not None
                and self._simbrief_available is not None
                and self._simbrief_available()):
            self._context_action(
                card, self._text("simbrief"),
                lambda: self._launch_simbrief(card),
            )
    def _launch_watcher(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Start the exact current gallery or explain its refusal in Photos.

        Args:
            card (GlassCard): Context card owning the action.
            session (LinkingSession): Exact session captured at opening.
            gallery (LinkedGallery): Exact selected physical gallery.

        Returns:
            None: A current target reaches the host; an unavailable target
                keeps an actionable explanation in the originating card.
        """
        if card is not self._context_card:
            return
        if (self._start_watcher is None or self._session is not session
                or self._session_provider() is not session
                or not session.available() or not session.can_upload()
                or self._galleries.get(gallery.gallery_id) != gallery
                or gallery.gallery_id not in self._fresh_catalogue_ids):
            self.tree_status.configure(text=self._text("watcher_unavailable"))
            self._context_status(card, "watcher_unavailable")
            return
        self._dismiss_context()
        self._start_watcher(session, gallery)
    def _launch_simbrief(self, card: GlassCard) -> None:
        """Dismiss the card and invoke the current host SimBrief action.

        Args:
            card (GlassCard): Context card owning the action.

        Returns:
            None: The host controls the simulator panel lifecycle.
        """
        self._dismiss_context()
        if (self._simbrief_action is not None
                and self._simbrief_available is not None
                and self._simbrief_available()):
            self._simbrief_action()
    def _approve_admin(self) -> None:
        """Start the existing browser approval flow from the context card.

        Returns:
            None: The current selection remains until the session changes.
        """
        self._dismiss_context()
        if self._manager is not None:
            self._manager.approve_admin_photos()
    def _context_status(self, card: GlassCard, key: str) -> None:
        """Show the localized outcome while the originating card remains open.

        Args:
            card (GlassCard): Originating contextual surface.
            key (str): Localized status suffix.

        Returns:
            None: A dismissed card is never reopened by a worker.
        """
        if card is self._context_card:
            self._context_content(card, self._text(key))
            self._place_context(card)
    def _photo_info(self, card: GlassCard, photo: LinkedPhoto) -> None:
        """Show authorized photo metadata, reusing the bounded session cache.

        Args:
            card (GlassCard): Photo context surface.
            photo (LinkedPhoto): Exact photo row already authorized for the grid.

        Returns:
            None: Displays server details or safe local fallback information.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if session is None or gallery_id is None:
            return
        cached = self._cached_photo_info(session, gallery_id, photo.image_id)
        if cached is not None:
            self._show_details(
                card, self._text("photo_info"), cached,
                session=session, fallback=False,
            )
            return
        cancel = threading.Event()
        self._probe_cancel = cancel
        diagnostic_generation = self._generation

        def read() -> Callable[[], None]:
            """Fetch server metadata or build a local descriptor fallback.

            Returns:
                Callable[[], None]: Tk callback rendering validated photo data.
            """
            details = self._read_photo_info(
                session, gallery_id, photo, cancel, diagnostic_generation,
            )
            fields = details or {
                "title": photo.title,
                "size": photo.size,
                "visibility": photo.visibility,
                "nsfw": photo.nsfw,
            }
            return lambda: self._show_context_photo_info(
                card, session, gallery_id, photo, fields, details,
            )

        self._run_context(
            card, read,
            lambda: self._show_details(
                card, self._text("photo_info"), {
                    "title": photo.title,
                    "size": photo.size,
                }, session=session, fallback=True,
            ),
        )
    def _read_photo_info(
        self, session: LinkingSession, gallery_id: int, photo: LinkedPhoto,
        cancel: threading.Event, diagnostic_generation: int,
    ) -> LinkedMediaDetails | None:
        """Read metadata once through the shared authorized transport owner.

        Args:
            session (LinkingSession): Captured session for this worker.
            gallery_id (int): Exact gallery owning the descriptor.
            photo (LinkedPhoto): Exact photo whose metadata is requested.
            cancel (threading.Event): Consumer-owned cancellation signal.
            diagnostic_generation (int): Captured workspace session generation.

        Returns:
            LinkedMediaDetails | None: Validated scalar server fields, or None
                for an older unsupported server, cancellation or safe failure.
        """
        method = getattr(session, "media_photo_info", None)
        if not callable(method) or cancel.is_set():
            return None
        try:
            return method(gallery_id, photo.image_id, cancelled=cancel)
        except Exception as error:
            if not cancel.is_set():
                _log_safe_media_failure(
                    "media-fetch", "photo_info", error,
                    diagnostic_callback=lambda summary: (
                        self._record_photo_diagnostic(
                            diagnostic_generation, summary,
                        )
                    ),
                )
            return None
    def _show_context_photo_info(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        photo: LinkedPhoto, fields: LinkedMediaDetails,
        details: LinkedMediaDetails | None,
    ) -> None:
        """Cache validated context metadata and render its existing card view.

        Args:
            card (GlassCard): Context surface that initiated the information read.
            session (LinkingSession): Captured authorization scope.
            gallery_id (int): Exact gallery owning the photo.
            photo (LinkedPhoto): Exact selected photo descriptor.
            fields (LinkedMediaDetails): Validated response or safe local fallback.
            details (LinkedMediaDetails | None): Server response when available.

        Returns:
            None: Cache and widget work occur together on the Tk thread.
        """
        if (card is not self._context_card or session is not self._session
                or self._session_provider() is not session
                or self._selected_gallery_id != gallery_id):
            return
        if details is not None:
            self._remember_photo_info(
                session, gallery_id, photo.image_id, details,
            )
        self._show_details(
            card, self._text("photo_info"), fields,
            session=session, fallback=details is None,
        )
    def _inspect_photo(self, photo: LinkedPhoto) -> None:
        """Show a selected photo's safe summary and coalesce its metadata read.

        Args:
            photo (LinkedPhoto): Exact descriptor selected in the current viewer.

        Returns:
            None: At most one metadata worker runs with only the latest pending
                photo retained for the active session and gallery.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if session is None or gallery_id is None:
            self._clear_photo_inspector()
            return
        self._inspector_generation += 1
        generation = self._inspector_generation
        if self._inspector_cancel is not None:
            self._inspector_cancel.set()
        self._inspector_pending = None
        self._inspector_summary(photo, self._text("working"))
        cached = self._cached_photo_info(session, gallery_id, photo.image_id)
        if cached is not None:
            self._render_photo_inspector(
                photo, cached, session=session, fallback=False,
            )
            return
        self._inspector_pending = (session, gallery_id, photo, generation)
        self._start_photo_info_worker()
    def _inspector_summary(self, photo: LinkedPhoto, status: str) -> None:
        """Render the immediate safe title, size and metadata-read status.

        Args:
            photo (LinkedPhoto): Current selected gallery descriptor.
            status (str): Localized status shown below the safe descriptor.

        Returns:
            None: No media bytes or worker result are required for this summary.
        """
        self._show_photo_inspector_pane()
        title = " ".join(
            "".join(char if char.isprintable() else " " for char in photo.title)
            .split()
        )
        self._photo_inspector_heading.configure(
            text=title or self._text("photo_info"),
        )
        self._photo_inspector_summary.configure(
            text=(self._text("field_size") + ": "
                  + (self._format_bytes(photo.size)
                     if type(photo.size) is int
                     else self._text("unknown"))
                  + ("\n" + status if status else "")),
        )
        for child in self._photo_inspector_content.winfo_children():
            child.destroy()
    def _start_photo_info_worker(self) -> None:
        """Start the latest queued metadata request when the single slot is free.

        Returns:
            None: One daemon reader publishes a result for Tk-thread fencing.
        """
        if (self._destroyed or self._inspector_worker is not None
                or self._inspector_pending is None):
            return
        session, gallery_id, photo, generation = self._inspector_pending
        self._inspector_pending = None
        cancel = threading.Event()
        self._inspector_cancel = cancel
        diagnostic_generation = self._generation

        def read() -> None:
            """Fetch one authorized metadata response without touching Tk.

            Returns:
                None: The exact descriptor and optional response reach the Tk queue.
            """
            details = self._read_photo_info(
                session, gallery_id, photo, cancel, diagnostic_generation,
            )
            self._inspector_results.put((
                generation, session, gallery_id, photo, details,
            ))

        self._inspector_worker = threading.Thread(
            target=read, name="photos-inspector", daemon=True,
        )
        try:
            self._inspector_worker.start()
        except RuntimeError:
            self._inspector_worker = None
            self._inspector_cancel = None
            self._render_photo_inspector(
                photo, None, session=session, fallback=True,
            )
            return
        self._schedule_poll()
    def _apply_photo_info_results(self) -> None:
        """Apply current metadata results and release the single reader slot.

        Returns:
            None: Stale selections are discarded and only the latest pending
                descriptor can start the next request.
        """
        while True:
            try:
                generation, session, gallery_id, photo, details = (
                    self._inspector_results.get_nowait()
                )
            except queue.Empty:
                break
            if self._inspector_cancel is not None:
                self._inspector_cancel = None
            current_request = (
                generation == self._inspector_generation
                and gallery_id == self._selected_gallery_id
            )
            if (session is self._session
                    and self._session_provider() is session):
                if details is not None and current_request:
                    self._remember_photo_info(
                        session, gallery_id, photo.image_id, details,
                    )
                if current_request:
                    self._render_photo_inspector(
                        photo, details, session=session,
                        fallback=details is None,
                    )
        if (self._inspector_worker is not None
                and not self._inspector_worker.is_alive()):
            self._inspector_worker = None
            self._inspector_cancel = None
        self._start_photo_info_worker()
    def _cached_photo_info(
        self, session: LinkingSession, gallery_id: int, image_id: int,
    ) -> LinkedMediaDetails | None:
        """Return and promote one entry in the current session's metadata LRU.

        Args:
            session (LinkingSession): Exact live authorization scope.
            gallery_id (int): Exact gallery owning the photo.
            image_id (int): Exact photo identifier.

        Returns:
            LinkedMediaDetails | None: A fresh shallow response copy for the
                exact live session, or no currently authorized cached row.
        """
        if (session is not self._session
                or self._session_provider() is not session
                or not session.can_read_media()):
            return None
        key = (id(session), gallery_id, image_id)
        cached = self._photo_info_cache.get(key)
        if cached is None:
            return None
        verified_at, details = cached
        age = time.monotonic() - verified_at
        if not 0 <= age < PHOTOS_INFO_CACHE_FRESH_SECONDS:
            self._photo_info_cache.pop(key, None)
            return None
        self._photo_info_cache.move_to_end(key)
        return dict(details)
    def _remember_photo_info(
        self, session: LinkingSession, gallery_id: int, image_id: int,
        details: LinkedMediaDetails,
    ) -> None:
        """Retain a shallow copy of validated metadata within the session LRU.

        Args:
            session (LinkingSession): Exact session that authorized the read.
            gallery_id (int): Exact gallery owning the photo.
            image_id (int): Exact photo identifier.
            details (LinkedMediaDetails): Validated scalar server metadata.

        Returns:
            None: Current results receive a bounded freshness time; stale
                sessions cannot populate the LRU.
        """
        if (session is not self._session
                or self._session_provider() is not session
                or not session.can_read_media()):
            return
        key = (id(session), gallery_id, image_id)
        self._photo_info_cache[key] = (time.monotonic(), dict(details))
        self._photo_info_cache.move_to_end(key)
        while len(self._photo_info_cache) > PHOTOS_INFO_CACHE_ENTRY_LIMIT:
            self._photo_info_cache.popitem(last=False)
    def _dismiss_photo_inspector(self) -> None:
        """Close the information pane while preserving the gallery and selection.

        Returns:
            None: Pending metadata is fenced, grid width is restored and keyboard
                focus returns to the selected photo without reloading the album.
        """
        self._clear_photo_inspector()
        if self._viewer is not None:
            self._viewer._gallery_viewer_controller.focus_selection()
    def _clear_photo_inspector(self) -> None:
        """Cancel pending metadata work and clear the visible inspector.

        Returns:
            None: A late read cannot repaint a replaced gallery or session.
        """
        self._inspector_generation += 1
        if self._info_width_restore_after is not None:
            self.after_cancel(self._info_width_restore_after)
            self._info_width_restore_after = None
        if self._inspector_cancel is not None:
            self._inspector_cancel.set()
            self._inspector_cancel = None
        self._inspector_pending = None
        self._photo_inspector_heading.configure(text=self._text("photo_info"))
        self._photo_inspector_summary.configure(
            text=self._text("select_photo_hint"),
        )
        for child in self._photo_inspector_content.winfo_children():
            child.destroy()
        if self._photo_inspector_visible:
            self.panes.forget(self.info_pane)
            self._photo_inspector_visible = False
    def _show_photo_inspector_pane(self) -> None:
        """Show the optional metadata pane after a tile becomes selected.

        Returns:
            None: The grid keeps its full width until photo details are requested.
        """
        if self._photo_inspector_visible or self._destroyed:
            return
        available = self.panes.winfo_width() - self.tree_pane.winfo_width()
        # Share narrow remaining space rather than imposing an unsupported
        # Tk panedwindow "minsize" option or covering the photo grid.
        preferred = min(PHOTOS_INFO_PANE_WIDTH, max(
            PHOTOS_INFO_PANE_MIN_WIDTH,
            available - PHOTOS_INFO_GRID_RESERVE_WIDTH,
        ))
        width = min(preferred, max(1, available // 2))
        self.info_pane.configure(width=width)
        self.panes.add(self.info_pane, weight=0)
        self._photo_inspector_visible = True
        self._info_width_restored = False
        self.after_idle(self._restore_info_width)
        self._info_width_restore_after = self.after(
            PHOTOS_INFO_RESTORE_DELAY_MS, self._settle_info_width,
        )
    def _settle_info_width(self) -> None:
        """Restore the desired width once after native pane layout settles.

        Returns:
            None: Clears its owned timer and respects teardown, hiding and any
                newer user drag through the existing restoration guards.
        """
        self._info_width_restore_after = None
        self._restore_info_width()
    def _render_photo_inspector(
        self, photo: LinkedPhoto, fields: LinkedMediaDetails | None, *,
        session: LinkingSession, fallback: bool,
    ) -> None:
        """Render shared authorized metadata groups in the right-side pane.

        Args:
            photo (LinkedPhoto): Descriptor for the captured selected photo.
            fields (LinkedMediaDetails | None): Server response, if available.
            session (LinkingSession): Authorization scope captured for the read.
            fallback (bool): Whether to show the local-descriptor note.

        Returns:
            None: Only groups allowed by the current session are displayed.
        """
        if session is not self._session:
            return
        current = fields or {
            "title": photo.title,
            "size": photo.size,
            "visibility": photo.visibility,
            "nsfw": photo.nsfw,
        }
        self._inspector_summary(
            photo,
            self._text("details_fallback") if fallback
            else self._text("photo_info_ready"),
        )
        groups = self._photo_info_groups(current, session)
        for heading, rows in groups:
            rows = [(key, value) for key, value in rows if value is not None]
            if not rows:
                continue
            ttk.Label(
                self._photo_inspector_content,
                text=self._text(heading.removeprefix("photos_workspace_")),
                style="Muted.TLabel",
            ).pack(anchor="w", pady=(6, 1))
            self._bind_photo_inspector_scroll(
                self._photo_inspector_content.winfo_children()[-1],
            )
            for key, value in rows:
                text = self._text(key.removeprefix("photos_workspace_"))
                rendered = self._format_detail_value(key, value)
                ttk.Label(
                    self._photo_inspector_content,
                    text=text + ": " + rendered,
                    style="Card.TLabel",
                    wraplength=(
                        PHOTOS_INFO_PANE_WIDTH - PHOTOS_INFO_PANE_ROW_INSET
                    ),
                    justify="left",
                ).pack(fill="x", anchor="w")
                self._bind_photo_inspector_scroll(
                    self._photo_inspector_content.winfo_children()[-1],
                )
        self._photo_inspector_content.update_idletasks()
        self._photo_inspector_canvas.configure(
            scrollregion=self._photo_inspector_canvas.bbox("all"),
        )
    def _resize_photo_inspector_content(self, _event: tk.Event) -> None:
        """Keep the inspector scrollregion aligned with its current rows.

        Args:
            _event (tk.Event): Content-frame geometry notification.

        Returns:
            None: The scrollbar follows the complete grouped metadata content.
        """
        self._photo_inspector_canvas.configure(
            scrollregion=self._photo_inspector_canvas.bbox("all"),
        )
    def _bind_photo_inspector_scroll(self, widget: tk.Misc) -> None:
        """Route wheel input from inspector labels to its metadata viewport.

        Args:
            widget (tk.Misc): Inspector content widget beneath the pointer.

        Returns:
            None: Wheel and touchpad motion scroll only the information pane.
        """
        widget.bind(
            "<MouseWheel>", self._scroll_photo_inspector, add="+",
        )
        widget.bind("<Button-4>", self._scroll_photo_inspector, add="+")
        widget.bind("<Button-5>", self._scroll_photo_inspector, add="+")
        try:
            widget.bind(
                "<TouchpadScroll>",
                lambda event: self._scroll_photo_inspector(
                    event, precise=True,
                ),
                add="+",
            )
        except tk.TclError:
            pass
    def _scroll_photo_inspector(
        self, event: tk.Event, *, precise: bool = False,
    ) -> None:
        """Scroll the metadata pane using platform-adjusted wheel deltas.

        Args:
            event (tk.Event): Native mouse wheel or touchpad event.
            precise (bool): Whether the event contains pixel-level delta data.

        Returns:
            None: The inspector canvas alone consumes the scroll distance.
        """
        delta = vertical_scroll_delta(self, event, precise=precise)
        if precise:
            bounds = self._photo_inspector_canvas.bbox("all")
            if bounds is not None:
                height = bounds[3] - bounds[1]
                if height > 0 and delta:
                    self._photo_inspector_canvas.yview_moveto(max(
                        0.0, min(
                            1.0,
                            self._photo_inspector_canvas.yview()[0]
                            - delta / height,
                        ),
                    ))
        elif delta:
            self._photo_inspector_canvas.yview_scroll(
                -1 if delta > 0 else 1, "units",
            )
    def _resize_photo_inspector_viewport(self, event: tk.Event) -> None:
        """Match the wrapped inspector content to the visible canvas width.

        Args:
            event (tk.Event): Canvas width after pane resizing.

        Returns:
            None: Labels wrap to fit the current pane width.
        """
        self._photo_inspector_canvas.itemconfigure(
            self._photo_inspector_window, width=event.width,
        )
        self._photo_inspector_heading.configure(
            wraplength=max(
                1, event.width - PHOTOS_INFO_PANE_HEADER_INSET
                - self._photo_inspector_close.winfo_reqwidth(),
            ),
        )
        self._photo_inspector_summary.configure(
            wraplength=max(1, event.width - PHOTOS_INFO_PANE_HEADER_INSET),
        )
        for child in self._photo_inspector_content.winfo_children():
            if isinstance(child, ttk.Label):
                child.configure(wraplength=max(
                    1, event.width - PHOTOS_INFO_PANE_ROW_INSET,
                ))
    def _open_cache_context(self) -> None:
        """Open the selected gallery's existing anchored action panel.

        Returns:
            None: Exposes cache status, preload and retention beside the trigger.
        """
        gallery_id = self._selected_gallery_id
        if gallery_id is not None:
            self._gallery_context(gallery_id, self._context_event(self._cache_button))
    def _cache_description(self, gallery_id: int) -> str:
        """Describe observed local availability without claiming offline access.

        Args:
            gallery_id (int): Exact gallery represented by the status.

        Returns:
            str: Localized availability, optional observed count and retention.
        """
        state = self._cache_state.get(gallery_id, 'unknown')
        message = self._text('cache_' + state)
        counts = self._cache_counts.get(gallery_id)
        if counts is not None:
            cached, total, complete = counts
            message += ' · ' + self._text(
                'cache_count' if complete else 'cache_loaded_count',
            ).format(cached=cached, total=total)
        if self._gallery_pins.get(gallery_id) is True:
            message += ' · ' + self._text('keep_preloaded')
        return message
    def _refresh_cache_widgets(self, gallery_id: int) -> None:
        """Refresh existing cache controls after a background observation.

        Args:
            gallery_id (int): Exact current site's gallery whose result changed.

        Returns:
            None: Updates the selected heading and any surviving cache menu.
        """
        if self._destroyed:
            return
        if self._selected_gallery_id == gallery_id:
            self._cache_button.configure(text=self._cache_glyph(gallery_id))
            self._cache_tooltip.set(
                self._cache_description(gallery_id) + '\n' + self._text('cache_legend'),
            )
        if self._cache_context_gallery != gallery_id:
            return
        button = self._cache_pin_button
        if button is None or not button.winfo_exists():
            return
        if self._cache_context_status is not None:
            self._cache_context_status.set(self._cache_description(gallery_id))
        active = self._preload_worker is not None and self._preload_worker.is_alive()
        known = gallery_id in self._gallery_pins
        if not active and self._cache_pin_var is not None:
            self._cache_pin_var.set(self._gallery_pins.get(gallery_id, False))
        button.state(['!disabled'] if known and not active else ['disabled'])
    def _cache_snapshot_changed(
        self, gallery_id: int, photos: tuple[LinkedPhoto, ...], complete: bool,
    ) -> None:
        """Coalesce authorized viewer snapshots for local-only cache checks.

        Args:
            gallery_id (int): Exact gallery owned by the notifying viewer.
            photos (tuple[LinkedPhoto, ...]): Already authorized loaded descriptors.
            complete (bool): Whether this snapshot reaches the catalogue's end.

        Returns:
            None: Updates section visibility and queues the latest cache snapshot
                per gallery without network reads.
        """
        if self._destroyed or gallery_id not in self._galleries:
            return
        if gallery_id == self._selected_gallery_id:
            presence = bool(photos) if photos or complete else None
            if presence != self._photos_present:
                self._photos_present = presence
                self._sync_subgalleries()
        self._request_storage_refresh()
        self._cache_pending[gallery_id] = (photos, complete)
        if self._cache_after is None:
            self._cache_after = self.after(
                PHOTOS_CACHE_REFRESH_MS, self._start_cache_probe,
            )
        self._schedule_poll()
    def _start_cache_probe(self) -> None:
        """Inspect one bounded batch of local headers and pin preferences.

        Returns:
            None: Cancels the pending delayed invocation before an immediate
                probe; one daemon reader publishes current-generation results.
        """
        after_id = self._cache_after
        self._cache_after = None
        if after_id is not None:
            # Catalogue and cache-result paths can request this probe before
            # the coalesced timer fires. Retain no orphan Tcl callback when
            # that immediate request replaces it or the workspace is closed.
            self.after_cancel(after_id)
        session = self._session
        if (self._destroyed or session is None or self._cache_worker is not None
                or not self._cache_pending):
            return
        requests, self._cache_pending = self._cache_pending, {}
        generation, cancel = self._generation, self._cancel

        def read() -> None:
            """Read local metadata without loading another server catalogue.

            Returns:
                None: Publishes state/count/completeness/pin rows for this batch.
            """
            rows: dict[int, _CacheRow] = {}
            for gallery_id, (photos, complete) in requests.items():
                row = probe_gallery_cache_status(
                    session, gallery_id, photos, complete, cancel,
                )
                if row is None:
                    return
                rows[gallery_id] = row
            self._cache_results.put((generation, rows))

        self._cache_worker = threading.Thread(
            target=read, name='GalleryCacheStatus', daemon=True,
        )
        self._cache_worker.start()
        self._schedule_poll()
    def _poll_cache_work(self) -> None:
        """Apply local cache observations and explicit preload progress on Tk.

        Returns:
            None: Rejects stale sessions and starts at most one next local probe.
        """
        while True:
            try:
                generation, rows = self._cache_results.get_nowait()
            except queue.Empty:
                break
            if (generation != self._generation
                    or self._session_provider() is not self._session):
                continue
            changed: list[int] = []
            for gallery_id, (state, cached, total, complete, pinned) in rows.items():
                if gallery_id not in self._galleries:
                    continue
                if state is not None:
                    prior = self._cache_counts.get(gallery_id)
                    retained_complete = (
                        self._gallery_pins.get(gallery_id) is True
                        and self._cache_state.get(gallery_id) == 'local'
                        and prior is not None and prior[2]
                        and not complete and total > 0 and cached == total
                    )
                    if not retained_complete:
                        self._cache_state[gallery_id] = state
                        self._cache_counts[gallery_id] = (cached, total, complete)
                if pinned is not None:
                    self._gallery_pins[gallery_id] = pinned
                else:
                    self._gallery_pins.pop(gallery_id, None)
                changed.append(gallery_id)
                self._refresh_cache_widgets(gallery_id)
            if changed:
                self._render_tree(changed)
        if self._cache_worker is not None and not self._cache_worker.is_alive():
            self._cache_worker = None
        if self._cache_pending and self._cache_after is None:
            self._start_cache_probe()
        worker = self._preload_worker
        if worker is None or self._preload_generation != self._generation:
            return
        progress = worker.snapshot()
        if progress == self._preload_last:
            self._show_preload_progress(progress)
            return
        self._preload_last = progress
        self._show_preload_progress(progress)
        if progress.phase in ('complete', 'partial', 'cancelled', 'error'):
            gallery_id = progress.gallery_id
            if progress.cache_state != 'unknown' and not progress.thumbnails_only:
                self._cache_state[gallery_id] = progress.cache_state
                self._cache_counts[gallery_id] = (
                    progress.cached, progress.total or 0, True,
                )
            self._cache_pending.setdefault(gallery_id, (None, False))
            if gallery_id in self._galleries:
                self._render_tree([gallery_id])
                self._refresh_cache_widgets(gallery_id)
    def _add_cache_actions(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Add explicit preload and retention to the existing gallery menu.

        Args:
            card (GlassCard): Exact gallery's current action card.
            session (LinkingSession): Current authorized Photos session.
            gallery (LinkedGallery): Exact listed physical gallery.

        Returns:
            None: Shows observed status and a pending-safe local preference.
        """
        gallery_id = gallery.gallery_id
        self._cache_context_gallery = gallery_id
        self._cache_context_status = tk.StringVar(
            card, self._cache_description(gallery_id),
        )
        ttk.Label(
            card, textvariable=self._cache_context_status, style='Muted.TLabel',
            wraplength=PHOTOS_INFO_CARD_MAX_WIDTH,
        ).pack(anchor='w', fill='x', pady=(4, 2))
        active = self._preload_worker is not None and self._preload_worker.is_alive()
        if active:
            self._context_action(card, self._text('preload_cancel'), self._cancel_preload)
        else:
            self._context_action(
                card, self._text('preload'),
                lambda: self._launch_preload(card, session, gallery),
            )
            self._context_action(
                card, self._text('preload_thumbnails'),
                lambda: self._launch_preload(
                    card, session, gallery, thumbnails_only=True,
                ),
            )
        self._cache_pin_var = tk.BooleanVar(
            card, self._gallery_pins.get(gallery_id, False),
        )
        self._cache_pin_button = ttk.Checkbutton(
            card, text=self._text('keep_preloaded'), style='Card.TCheckbutton',
            variable=self._cache_pin_var,
            command=lambda: self._launch_preload(
                card, session, gallery,
                pinned=self._cache_pin_var.get(),
            ),
        )
        self._cache_pin_button.pack(anchor='w', pady=(4, 2))
        attach_inline_tooltip(
            self._cache_pin_button, self._text('keep_preloaded_hint'),
            self.winfo_toplevel(),
        )
        self._cache_pending.setdefault(gallery_id, (None, False))
        self._start_cache_probe()
        self._refresh_cache_widgets(gallery_id)
    def _launch_preload(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
        *, pinned: bool | None = None, thumbnails_only: bool = False,
    ) -> None:
        """Start one explicit, cancellable preload for the captured gallery.

        Args:
            card (GlassCard): Current menu containing the activated action.
            session (LinkingSession): Exact session captured at menu opening.
            gallery (LinkedGallery): Exact listed target of this action.
            pinned (bool | None): Set retention, or None to preserve it.
            thumbnails_only (bool): Explicitly fetch only direct thumbnails,
                preserving the original full-preload completion semantics.

        Returns:
            None: Starts work off Tk with a fresh, visible operation status;
                disabling retention performs no download.
        """
        if (self._context_card is not card or self._session is not session
                or self._session_provider() is not session
                or self._galleries.get(gallery.gallery_id) is not gallery):
            return
        if self._preload_worker is not None and self._preload_worker.is_alive():
            self._preload_strip.grid(
                row=self._heading_preload_row,
                column=0, columnspan=3, sticky='ew',
            )
            return
        diagnostic_generation = self._generation
        self._update_preload_priority()
        self._preload_worker = GalleryPreloadWorker(
            session, gallery.gallery_id, pinned=pinned,
            download=pinned is not False,
            diagnostic_callback=lambda summary: self._record_preload_diagnostic(
                session, summary,
            ),
            thumbnails_only=thumbnails_only,
            yield_to_foreground=self._preload_foreground.is_set,
            foreground_reason=(
                lambda: self._preload_foreground_reason
            ),
        )
        self._preload_generation = diagnostic_generation
        self._preload_last = None
        self._preload_status_dismissed = False
        self._preload_unpin = pinned is False
        self._preload_text.set(self._text('preload_listing'))
        self._preload_strip.grid(
            row=self._heading_preload_row,
            column=0, columnspan=3, sticky='ew', pady=(4, 0),
        )
        self._preload_bar.configure(mode='indeterminate')
        self._preload_bar.grid()
        self._preload_bar.start(PHOTOS_CATALOG_POLL_MS)
        self._set_preload_activity(True)
        self._preload_cancel.state(['!disabled'])
        self._preload_worker.start()
        self._dismiss_context()
        self._schedule_poll()
    def _update_preload_priority(self) -> None:
        """Publish foreground demand to preload without worker-side Tk reads.

        Returns:
            None: The background worker sees only a thread-safe Event. An
                already admitted bounded HTTP read may finish before yielding.
        """
        controller = getattr(self._viewer, '_gallery_viewer_controller', None)
        demanded = bool(self._upload_pipeline_waiting or
            (self._worker is not None and self._worker.is_alive())
            or self._has_pending_page or self._context_busy
            or (self._inspector_worker is not None
                and self._inspector_worker.is_alive())
            or self._inspector_pending is not None
        )
        reason = 'metadata' if demanded else ''
        if controller is not None and not controller._closed:
            urgent = bool(
                (controller._worker is not None
                 and controller._worker.is_alive()
                 and controller._worker_kind in (
                     'detail', 'prefetch', 'page', 'more', 'slide_more',
                     'audio', 'presentation',
                 ))
                or controller._pending_detail_index is not None
                or controller._pending_page_request is not None
                or controller._audio_pending is not None
            )
            if urgent:
                demanded = True
                reason = 'browsing'
        # Explicit preload shares serial admission with viewport and Watcher.
        # Speculative RAM/disk/overscan queues have no transport claim and must
        # not keep this event set indefinitely.
        self._preload_foreground_reason = reason
        if demanded:
            self._preload_foreground.set()
        else:
            self._preload_foreground.clear()
    def set_upload_pipeline_waiting(self, waiting: bool) -> None:
        """Suspend automatic media work while upload retry or review owns priority.

        Args:
            waiting (bool): Current queue backpressure snapshot from its worker.

        Returns:
            None: Yields preload/prefetch without disabling explicit Photos controls.
        """
        self._upload_pipeline_waiting = waiting
        if self._session is not None:
            self._session.set_upload_pipeline_waiting(waiting)
        self._update_preload_priority()
    def _show_preload_progress(self, progress: PreloadProgress) -> None:
        """Show truthful transfer counts and terminal reasons in the heading.

        Args:
            progress (PreloadProgress): Latest immutable worker snapshot.

        Returns:
            None: Keeps active work/cancellation visible across navigation;
                terminal results stay scoped to the exact selected gallery
                until explicitly dismissed for this preload operation.
        """
        terminal = progress.phase in ('complete', 'partial', 'cancelled', 'error')
        previous_storage_witness = self._storage_preload_witness
        storage_witness = (
            progress.gallery_id, progress.cached,
            progress.stored, progress.stored_bytes,
            progress.phase,
        )
        storage_progress_changed = bool(
            previous_storage_witness is not None
            and previous_storage_witness[:4] != storage_witness[:4]
        )
        new_terminal_snapshot = bool(
            terminal and (previous_storage_witness is None
                          or previous_storage_witness != storage_witness)
        )
        self._storage_preload_witness = storage_witness
        if storage_progress_changed or new_terminal_snapshot:
            self._request_storage_refresh()
        different_gallery = self._selected_gallery_id != progress.gallery_id
        if terminal and (different_gallery or self._preload_status_dismissed):
            self._preload_strip.grid_remove()
            self._set_preload_activity(False)
            return
        self._preload_strip.grid(
            row=self._heading_preload_row,
            column=0, columnspan=3, sticky='ew', pady=(4, 0),
        )
        gallery = self._galleries.get(progress.gallery_id)
        title = gallery.title if gallery is not None else ''
        phase = self._text(
            'preload_unpinned' if self._preload_unpin and progress.phase == 'complete'
            else 'preload_' + progress.phase,
        )
        if progress.phase == 'yielding' and progress.wait_reason == 'metadata':
            phase = self._text('preload_wait_metadata')
        if progress.phase == 'waiting' and progress.retry_after_seconds is not None:
            phase += ' · ' + self._text('preload_retry_countdown').format(
                seconds=progress.retry_after_seconds,
            )
        if progress.total is not None:
            if progress.thumbnails_only and not terminal:
                phase += ' · ' + self._text('preload_thumbnail_counts').format(
                    done=progress.completed, total=progress.total,
                    hits=progress.current_hits, stored=progress.stored,
                )
            else:
                phase += ' · ' + self._text('preload_counts').format(
                    done=progress.completed, total=progress.total,
                    cached=progress.cached, skipped=progress.skipped,
                )
        if progress.reason:
            phase += ' · ' + self._text('preload_reason_' + progress.reason)
        self._preload_text.set(
            (title + ': ' if different_gallery and title else '') + phase,
        )
        self._set_preload_activity(not terminal)
        self._preload_cancel_hint.set(self._text(
            'preload_close_status' if terminal else 'preload_cancel',
        ))
        if terminal:
            self._preload_bar.stop()
            self._preload_bar.grid_remove()
            self._preload_cancel.state(['!disabled'])
        elif progress.total is None:
            self._preload_bar.configure(mode='indeterminate')
            self._preload_bar.start(PHOTOS_CATALOG_POLL_MS)
        else:
            self._preload_bar.stop()
            self._preload_bar.grid()
            self._preload_bar.configure(
                mode='determinate', maximum=max(1, progress.total or 1),
                value=progress.completed,
            )
