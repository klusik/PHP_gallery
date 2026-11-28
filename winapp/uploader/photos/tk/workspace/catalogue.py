# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/workspace/catalogue.py
# Module Type: Windows Client UI mixin
# Purpose: Own a cohesive Photos workspace interaction domain.
# Responsibilities: Preserve PhotosWorkspace lifecycle and callback behavior.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Photos workspace behavior mixin."""

from .support import (
    CataloguePerformanceWindow,
    FOLDER_DIVIDER_KEY_STEP,
    GALLERY_FOLDER_SHARE_BOUNDS,
    GALLERY_FOLDER_SHARE_SCALE,
    GalleryCatalogueSnapshot,
    GalleryThumbnailMemory,
    LINKING_GALLERY_ID_MAX,
    LinkedGallery,
    LinkedGalleryPage,
    LinkedGalleryTree,
    LinkedUploadTarget,
    LinkingMediaThrottled,
    LinkingResponseError,
    LinkingSession,
    PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT,
    PHOTOS_CATALOGUE_CACHE_BATCH_DELAY_MS,
    PHOTOS_CATALOGUE_CACHE_BATCH_SIZE,
    PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS,
    PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS,
    PHOTOS_CATALOGUE_RATE_MAX_RETRIES,
    PHOTOS_CATALOGUE_RATE_RETRY_DEFAULT_SECONDS,
    PHOTOS_CATALOG_POLL_MS,
    PHOTOS_DIAGNOSTIC_MAX_CHARS,
    PHOTOS_INITIAL_CANDIDATE_LIMIT,
    PHOTOS_RECORDING_DOT_SIZE,
    PHOTOS_TREE_MARKER_SIZE,
    PHOTO_CARD_FORMATS,
    PHOTOS_WORKSPACE_LOGGER,
    ThemeImage,
    _CatalogueCacheResult,
    _CataloguePageResult,
    _CatalogueTreeResult,
    _PALETTE,
    _log_safe_media_failure,
    create_smooth_icon,
    format_safe_photos_failure,
    is_safe_photos_observation,
    mount_native_gallery_view,
    normalize_card_format,
    queue,
    threading,
    time,
    tk,
)

class PhotosWorkspaceCatalogueMixin:
    """Own one cohesive group of Photos workspace behavior."""

    def _accept_page(
        self, page: LinkedGalleryPage | None, *,
        throttled: bool = False, retry_after_seconds: int | None = None,
        cursor: int | None = None,
    ) -> None:
        """Reconcile and persist one page before requesting its continuation.

        Args:
            page (LinkedGalleryPage | None): Validated response or failure marker.
            throttled (bool): Actual catalogue HTTP 429 response marker.
            retry_after_seconds (int | None): Validated server backoff seconds.
            cursor (int | None): Original failing request cursor, if any.

        Returns:
            None: Confirmed metadata updates the known tree immediately; only
                the last successful page removes absent cached rows.
        """
        if page is None:
            if (throttled and self._schedule_catalogue_throttled_page(
                    cursor, retry_after_seconds)):
                return
            self._cancel_catalogue_continuation()
            self._set_catalogue_loading(False)
            self.tree_status.configure(text=self._text("error"))
            self.viewer_status.configure(text=self._text("error"))
            self.retry_button.pack(side="right")
            return
        apply_started = time.perf_counter()
        self.retry_button.pack_forget()
        self._catalogue_rate_retries = 0
        page_gallery_ids: list[int] = []
        changed_ids: list[int] = []
        for gallery in page.galleries:
            prior = self._galleries.get(gallery.gallery_id)
            if (prior is not None and prior.parent_id is not None
                    and prior.parent_id != gallery.parent_id):
                prior_children = self._children_by_parent.get(prior.parent_id)
                if prior_children is not None:
                    prior_children.discard(gallery.gallery_id)
            self._galleries[gallery.gallery_id] = gallery
            page_gallery_ids.append(gallery.gallery_id)
            if (prior != gallery
                    or not self.tree.exists(str(gallery.gallery_id))):
                changed_ids.append(gallery.gallery_id)
            self._fresh_catalogue_ids.add(gallery.gallery_id)
            if gallery.parent_id is not None:
                self._children_by_parent.setdefault(
                    gallery.parent_id, set(),
                ).add(gallery.gallery_id)
        self._persist_selected_gallery_if_fresh(page_gallery_ids)
        if changed_ids:
            self._render_tree(changed_ids)
        if page_gallery_ids and any(
            self.tree.exists(str(gallery_id)) for gallery_id in page_gallery_ids
        ):
            self._catalogue_performance.mark_milestone(
                'fresh_rows_confirmed',
            )
        if self._selected_gallery_id in self._fresh_catalogue_ids:
            self.add_child_button.configure(state="normal")
            self._rearm_current_viewport_after_fresh_catalogue()
        for gallery_id in changed_ids:
            self._cache_pending.setdefault(gallery_id, (None, False))
        self._start_cache_probe()
        self._sync_subgalleries()
        self._pages_this_pass += 1
        self._next_after = page.next_after
        if page.next_after is not None:
            self._remember_catalogue_progress()
            self._restore_or_choose_gallery(complete=False)
            self._set_catalogue_loading(True)
            if not self._catalogue_paused_for_preview:
                self._continue_catalogue()
        else:
            self._cancel_cached_catalogue_mount()
            self._prune_cached_catalogue_rows()
            self._discard_unconfirmed_selection()
            self._restore_or_choose_gallery(complete=True)
            self._set_catalogue_loading(False)
            if not self._galleries:
                self.tree_status.configure(text=self._text("empty"))
                self.viewer_status.configure(text=self._text("empty"))
            else:
                self.tree_status.configure(text="")
            complete_snapshot = GalleryCatalogueSnapshot(
                tuple(sorted(
                    self._galleries.values(),
                    key=lambda gallery: gallery.gallery_id,
                )),
            )
            self._catalogue_cache_snapshot = complete_snapshot
            self._queue_catalogue_snapshot_save(complete_snapshot)
        self._catalogue_performance.observe(
            'page_apply', (time.perf_counter() - apply_started) * 1000.0,
            rows=len(page.galleries),
        )
    def _first_gallery(self) -> LinkedGallery | None:
        """Choose an untried leaf gallery, falling back to an orphan row.

        Returns:
            LinkedGallery | None: Lowest-ID leaf, then any untried row.
        """
        ordered = sorted(
            (gallery for gallery_id, gallery in self._galleries.items()
             if gallery_id in self._fresh_catalogue_ids),
            key=lambda item: item.gallery_id,
        )
        parents = {gallery.parent_id for gallery in ordered}
        for gallery in ordered:
            if (gallery.gallery_id not in parents
                    and gallery.gallery_id not in self._implicit_attempted):
                return gallery
        for gallery in ordered:
            if gallery.gallery_id not in self._implicit_attempted:
                return gallery
        return None
    def _ensure_initial_gallery(self) -> None:
        """Mount the next leaf candidate for the implicit first preview.

        Returns:
            None: Only a few candidates are probed before leaving the tree usable.
        """
        if (not self._implicit_pending or self._selected_gallery_id is not None
                or self._initial_attempt_count >= PHOTOS_INITIAL_CANDIDATE_LIMIT):
            return
        gallery = self._first_gallery()
        if gallery is None:
            return
        self._catalogue_paused_for_preview = True
        self._initial_attempt_count += 1
        self._implicit_attempted.add(gallery.gallery_id)
        self._select_gallery(gallery, implicit=True)
    def _on_implicit_preview(
        self, gallery_id: int, has_photos: bool | None,
    ) -> None:
        """Advance the implicit choice if its first preview has no photos.

        Args:
            gallery_id (int): Gallery identity associated with the callback.
            has_photos (bool | None): Whether photos exist; None means a
                temporary failure that should preserve this selection.

        Returns:
            None: Explicit user choices are never replaced by this fallback.
        """
        if (not self._implicit_pending
                or self._selected_gallery_id != gallery_id):
            return
        if has_photos is True:
            self._implicit_pending = False
        elif has_photos is None:
            self._implicit_pending = False
        else:
            self._selected_gallery_id = None
            self._ensure_initial_gallery()
            if self._selected_gallery_id is None:
                self._implicit_pending = False
                self._selected_gallery_id = gallery_id
        if not self._implicit_pending and self._next_after is not None:
            self._catalogue_paused_for_preview = False
            self._continue_catalogue()
    def _render_tree(self, page_gallery_ids: list[int]) -> None:
        """Render ordered rows and attach newly resolvable orphans in one slice.

        Args:
            page_gallery_ids (list[int]): IDs introduced or refreshed by the
                current validated catalogue page.

        Returns:
            None: Ascending new root IDs append using one batch-local tail;
                interleaved IDs retain the ordered lookup. Existing item state
                remains intact as later pages arrive.
        """
        self._rendering_tree = True
        candidates = set(page_gallery_ids)
        root_tail_loaded = False
        root_tail_id: int | None = None
        for gallery_id in page_gallery_ids:
            gallery = self._galleries[gallery_id]
            item_id = str(gallery_id)
            title = gallery.title or self._text("untitled")
            marked = item_id in self._recording_rows
            target = self._watcher_target
            hollow = marked and target is not None and target.gallery_id != gallery_id
            marker = self._tree_marker(gallery_id, marked, hollow)
            label = title if marker is not None else self._cache_glyph(gallery_id) + "  " + title
            image = marker
            if image is None and marked:
                image = self._recording_image(hollow)
            if self.tree.exists(item_id):
                self.tree.item(
                    item_id,
                    text=label,
                    image=image or "",
                )
            else:
                if not root_tail_loaded:
                    # The tree keeps root siblings ordered by numeric ID. Read
                    # them once per render call; repeated reads copy and scan
                    # an ever-growing Tk child tuple during ascending mounts.
                    root_children = self.tree.get_children("")
                    if root_children:
                        root_tail_id = int(root_children[-1])
                    root_tail_loaded = True
                root_index: int | str
                if root_tail_id is None or gallery_id > root_tail_id:
                    root_index = "end"
                    root_tail_id = gallery_id
                else:
                    # Refreshes can introduce lower IDs; retain the general
                    # ordered lookup for those interleaved insertions.
                    root_index = self._ordered_index("", gallery_id)
                self.tree.insert(
                    "",
                    root_index,
                    iid=str(gallery_id),
                    text=label,
                    image=image or "",
                    open=self._tree_state.get(gallery_id, self._expand_new_nodes),
                )
            candidates.update(self._children_by_parent.get(gallery_id, ()))
        for gallery_id in sorted(candidates):
            self._attach_gallery(gallery_id)
        if self._selected_gallery_id is not None:
            selected = str(self._selected_gallery_id)
            if self.tree.exists(selected):
                self.tree.selection_set(selected)
        self._rendering_tree = False
        self._update_recording_rows()
    def _ordered_index(self, parent_id: str, gallery_id: int) -> int:
        """Find the insertion index that orders siblings by gallery ID.

        Args:
            parent_id (str): Tree item ID, or empty string for the root.
            gallery_id (int): ID of the row being inserted or moved.

        Returns:
            int: Count of existing siblings with a smaller ID.
        """
        index = 0
        for sibling in self.tree.get_children(parent_id):
            if int(sibling) > gallery_id:
                break
            index += 1
        return index
    def _attach_gallery(self, gallery_id: int) -> None:
        """Place a known row under its available, cycle-safe parent.

        Args:
            gallery_id (int): Current gallery row to attach or keep at root.

        Returns:
            None: Missing parents and malformed cycles remain reachable.
                Moving the selected gallery or its ancestor reveals the new
                path so the active row stays visible after reconciliation.
        """
        gallery = self._galleries[gallery_id]
        item_id = str(gallery_id)
        parent_id = gallery.parent_id
        parent_item = (
            str(parent_id)
            if (parent_id is not None and parent_id in self._galleries
                and self.tree.exists(str(parent_id)))
            else ""
        )
        ancestor = parent_item
        while ancestor:
            if ancestor == item_id:
                parent_item = ""
                break
            ancestor = self.tree.parent(ancestor)
        if self.tree.parent(item_id) == parent_item:
            return
        self.tree.move(
            item_id,
            parent_item,
            self._ordered_index(parent_item, gallery_id),
        )
        selected = self._selected_gallery_id
        if selected is not None and self.tree.exists(str(selected)):
            ancestor = str(selected)
            while ancestor:
                if ancestor == item_id:
                    self.tree.see(str(selected))
                    break
                ancestor = self.tree.parent(ancestor)
    def _continue_catalogue(self) -> None:
        """Continue immediately or schedule one paced post-cap page.

        Returns:
            None: One bounded timer continues the current exact session/cursor.
        """
        if (self._destroyed or self._session is None
                or self._next_after is None
                or self._catalogue_paused_for_preview):
            return
        self.load_more_button.pack_forget()
        if self._pages_this_pass >= PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT:
            if self._catalogue_after is not None:
                return
            self._set_catalogue_loading(True)
            session = self._session
            generation = self._catalogue_generation
            cancel = self._catalogue_cancel
            cursor = self._next_after
            after_id: str | None = None

            def continue_page() -> None:
                """Request the captured next page after validating its owner.

                Returns:
                    None: Stale session, cursor, preview or teardown cancels it.
                """
                if self._catalogue_after != after_id:
                    return
                self._catalogue_after = None
                if (self._destroyed or self._catalogue_generation != generation
                        or self._session is not session
                        or self._session_provider() is not session
                        or self._catalogue_cancel is not cancel or cancel.is_set()
                        or self._next_after != cursor
                        or self._catalogue_paused_for_preview):
                    return
                self._request_page(cursor)

            after_id = self.after(
                PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS, continue_page,
            )
            self._catalogue_after = after_id
            return
        self._request_page(self._next_after)
    def _schedule_catalogue_throttled_page(
        self, cursor: int | None, retry_after_seconds: int | None,
    ) -> bool:
        """Resume the captured page only after the server's rate budget resets.

        Args:
            cursor (int | None): Exclusive cursor of the refused gallery page.
            retry_after_seconds (int | None): Safe server-supplied retry delay.

        Returns:
            bool: True if one generation-fenced timer was created; otherwise
                retain the manual error/retry control without a retry storm.
        """
        if (self._destroyed or self._session is None
                or self._catalogue_rate_retries
                >= PHOTOS_CATALOGUE_RATE_MAX_RETRIES):
            return False
        delay = (retry_after_seconds
                 if type(retry_after_seconds) is int
                 else PHOTOS_CATALOGUE_RATE_RETRY_DEFAULT_SECONDS)
        if not 1 <= delay <= PHOTOS_CATALOGUE_RATE_RETRY_DEFAULT_SECONDS:
            # Never retry before an unreasonably large server instruction.
            return False
        self._cancel_catalogue_continuation()
        session = self._session
        generation = self._catalogue_generation
        cancel = self._catalogue_cancel
        self._catalogue_rate_retries += 1
        after_id: str | None = None

        def retry_page() -> None:
            """Dispatch only when the captured session, timer and page persist.

            Returns:
                None: Stale or cancelled timers have no network side effects.
            """
            if self._catalogue_after != after_id:
                return
            self._catalogue_after = None
            if (self._destroyed or self._catalogue_generation != generation
                    or self._session is not session
                    or self._session_provider() is not session
                    or self._catalogue_cancel is not cancel or cancel.is_set()
                    or self._next_after != cursor
                    or self._catalogue_paused_for_preview):
                return
            self._request_page(cursor)

        after_id = self.after(delay * 1000, retry_page)
        self._catalogue_after = after_id
        self._set_catalogue_loading(True)
        return True
    def _cancel_catalogue_continuation(self) -> None:
        """Cancel the one pending post-cap page timer, if present.

        Returns:
            None: Repeated cancellation is harmless and stale callbacks fail
                their session/cursor checks.
        """
        after_id = self._catalogue_after
        self._catalogue_after = None
        if after_id is None:
            return
        try:
            self.after_cancel(after_id)
        except tk.TclError:
            pass
    def _load_more_catalogue(self) -> None:
        """Resume the paced continuation from the current server cursor.

        Returns:
            None: Existing burst limits and request cadence remain unchanged.
        """
        self._continue_catalogue()
    def _all_items(self) -> list[str]:
        """Return every current tree item in parent-before-child order.

        Returns:
            list[str]: Tree item identifiers for the current in-memory catalog.
        """
        result: list[str] = []
        pending = list(self.tree.get_children())
        while pending:
            item = pending.pop()
            result.append(item)
            pending.extend(self.tree.get_children(item))
        return result
    def _set_all_open(self, opened: bool) -> None:
        """Expand or collapse all currently loaded gallery branches.

        Args:
            opened (bool): True to expand every loaded node, false to collapse.

        Returns:
            None: Tree expansion is purely local and makes no server request.
        """
        self._expand_new_nodes = opened
        for item in self._all_items():
            self.tree.item(item, open=opened)
        self._save_expansion_state()
        self._update_recording_rows()
    def set_watcher_recording(
        self, target: LinkedUploadTarget | None, pulse: bool,
    ) -> None:
        """Display the active Watcher target in this exact linked account.

        Args:
            target (LinkedUploadTarget | None): Active linked target, or None.
            pulse (bool): Whether the current animation phase is bright.

        Returns:
            None: Only previously and currently marked tree rows are updated.
        """
        self._watcher_target = target
        self._watcher_pulse = pulse
        self._update_recording_rows()
    def _recording_image(self, hollow: bool) -> ThemeImage:
        """Return a small red indicator for the current pulse phase.

        Args:
            hollow (bool): Draw an outline for an obscured descendant.

        Returns:
            ThemeImage: Retained Pillow/Tk image for this phase and marker shape.
        """
        key = (hollow, self._watcher_pulse)
        image = self._recording_images.get(key)
        if image is None:
            image = create_smooth_icon(
                self, "recording",
                (PHOTOS_RECORDING_DOT_SIZE, PHOTOS_RECORDING_DOT_SIZE),
                hollow=hollow, pulse=self._watcher_pulse,
            )
            if image is None:
                image = tk.PhotoImage(
                    master=self, width=PHOTOS_RECORDING_DOT_SIZE,
                    height=PHOTOS_RECORDING_DOT_SIZE,
                )
                color = _PALETTE[
                    'recording' if self._watcher_pulse else 'recording_dim'
                ]
                center = PHOTOS_RECORDING_DOT_SIZE // 2
                for y in range(PHOTOS_RECORDING_DOT_SIZE):
                    for x in range(PHOTOS_RECORDING_DOT_SIZE):
                        distance = (x - center) ** 2 + (y - center) ** 2
                        if distance <= center ** 2 and (
                            not hollow or distance >= 7
                        ):
                            image.put(color, (x, y))
            self._recording_images[key] = image
        return image
    def _tree_marker(
        self, gallery_id: int, recording: bool, hollow: bool,
    ) -> ThemeImage | None:
        """Return the composed cache, pin and Watcher marker for one row.

        Args:
            gallery_id (int): Gallery represented by the tree row.
            recording (bool): Whether this exact row is recording.
            hollow (bool): Whether the row hides a recording descendant.

        Returns:
            ThemeImage | None: Retained combined image, or None without Pillow.
        """
        status = self._cache_state.get(gallery_id, "unknown")
        pinned = self._gallery_pins.get(gallery_id) is True
        key = (status, pinned, recording, hollow, self._watcher_pulse)
        image = self._tree_marker_images.get(key)
        if image is None:
            image = create_smooth_icon(
                self, status,
                (PHOTOS_TREE_MARKER_SIZE, PHOTOS_TREE_MARKER_SIZE),
                pinned=pinned,
                recording=recording, hollow=hollow, pulse=self._watcher_pulse,
            )
            if image is not None:
                self._tree_marker_images[key] = image
        return image
    def _on_tree_expansion(self, _event: tk.Event) -> None:
        """Update the hidden-target indicator after Tk changes expansion.

        Args:
            _event (tk.Event): Tree open or close notification.

        Returns:
            None: Deferred work observes Tk's completed expansion change.
        """
        self.after_idle(self._save_expansion_state)
        self.after_idle(self._update_recording_rows)
    def _save_expansion_state(self) -> None:
        """Persist loaded rows while retaining states for later catalogue pages.

        Returns:
            None: The host saves the merged account-scoped preferences.
        """
        session = self._session
        if self._destroyed or session is None or self._save_tree_state is None:
            return
        for item in self._all_items():
            self._tree_state[int(item)] = bool(self.tree.item(item, "open"))
        self._save_tree_state(session, dict(self._tree_state))
    def _update_recording_rows(self) -> None:
        """Update markers for the exact target and collapsed hiding ancestors.

        Returns:
            None: Unrelated tree rows and selection remain untouched.
        """
        if self._destroyed:
            return
        target = self._watcher_target
        manager = self._manager
        matching = (
            target is not None and self._session is not None
            and manager is not None
            and manager.photos_site_identity(self._session)
                == (target.issuer, target.user_id)
        )
        markers: dict[str, bool] = {}
        if matching and target is not None:
            item = str(target.gallery_id)
            if self.tree.exists(item):
                markers[item] = False
                parent = self.tree.parent(item)
                while parent:
                    if not self.tree.item(parent, 'open'):
                        markers[parent] = True
                    parent = self.tree.parent(parent)
        for item in self._recording_rows | set(markers):
            if self.tree.exists(item):
                gallery_id = int(item)
                is_marked = item in markers
                image = self._tree_marker(
                    gallery_id, is_marked, markers.get(item, False),
                )
                title = self._galleries.get(gallery_id)
                text = (
                    (title.title or self._text("untitled"))
                    if title is not None else str(self.tree.item(item, "text"))
                )
                if image is None:
                    text = self._cache_glyph(gallery_id) + "  " + text
                    if is_marked:
                        image = self._recording_image(markers[item])
                self.tree.item(item, image=image or "", text=text)
        self._recording_rows = set(markers)
        selected_target = matching and target is not None and (
            self._selected_gallery_id == target.gallery_id
        )
        self._recording_heading.configure(
            image=self._recording_image(False) if selected_target else '',
        )
    def _refresh_catalogue(self, *, keep_context: bool = False) -> None:
        """Restart server reconciliation while retaining the known visible tree.

        Args:
            keep_context (bool): Retain a confirmed edit's completion surface
                while canonical server folder paths are refreshed.
        Returns:
            None: Selection, expansion and the photo viewer survive. Obsolete
                catalogue responses are cancelled and fenced independently of
                media and context work; fresh reads update names and parents.
        """
        if (self._destroyed or self._session is None
                or self._session_provider() is not self._session):
            return
        if not keep_context:
            self._dismiss_context(restore=False)
        self._cancel_catalogue_continuation()
        self._cancel_cached_catalogue_mount()
        self._catalogue_cancel.set()
        self._catalogue_cancel = threading.Event()
        self._catalogue_generation += 1
        self._catalogue_performance.begin_pass()
        self._catalogue_cache_ids.update(self._galleries)
        self._fresh_catalogue_ids.clear()
        self._complete_catalogue_tree = None
        self._pages_this_pass = 0
        self._next_after = None
        self._pending_cursor = None
        self._has_pending_page = False
        self._catalogue_paused_for_preview = False
        self._implicit_pending = False
        self.add_child_button.configure(state="disabled")
        self.retry_button.pack_forget()
        self._set_catalogue_loading(True)
        self._request_page(None)
    def _retry_catalogue(self) -> None:
        """Retry the current failed page through its last cursor.

        Returns:
            None: The existing session and page boundary remain authoritative.
        """
        if self._session is None:
            return
        self._cancel_catalogue_continuation()
        self.retry_button.pack_forget()
        self._set_catalogue_loading(True)
        self._request_page(self._next_after)
    def _on_gallery_selected(self, _event: tk.Event) -> None:
        """Mount the selected gallery in the embedded viewer pane.

        Args:
            _event (tk.Event): Tree selection event owned by Tk.

        Returns:
            None: Same-session selection replaces the previous viewer frame.
        """
        if self._rendering_tree:
            return
        selection = self.tree.selection()
        if not selection:
            return
        gallery = self._galleries.get(int(selection[0]))
        if (gallery is not None
                and gallery.gallery_id != self._selected_gallery_id):
            self._dismiss_context(restore=False)
            self._select_gallery(gallery)
    def _rearm_current_viewport_after_fresh_catalogue(self) -> None:
        """Requalify a retained viewer only after its gallery is freshly known.

        Returns:
            None: The native viewport samples the current pass on Tk without
                adding transport work or treating cached tree rows as grants.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        generation = self._catalogue_generation
        if (self._destroyed or session is None
                or self._session_provider() is not session
                or self._catalogue_cancel.is_set()
                or gallery_id is None
                or gallery_id not in self._fresh_catalogue_ids
                or self._viewer is None
                or self._viewer_viewport_pass_generation == generation):
            return
        controller = self._viewer._gallery_viewer_controller
        controller.on_first_thumbnail_presented = lambda: (
            self._record_first_visible_thumbnail(session, generation, gallery_id)
        )
        controller.on_first_viewport_settled = lambda: (
            self._record_first_viewport_settled(session, generation, gallery_id)
        )
        self._viewer_viewport_pass_generation = generation
        controller.rearm_first_viewport_diagnostics()
    def _record_first_visible_thumbnail(
        self, session: LinkingSession, generation: int, gallery_id: int,
    ) -> None:
        """Accept only the current viewer's truly mounted first image.

        Args:
            session (LinkingSession): Current media authorization owner.
            generation (int): Exact catalogue generation of this viewer.
            gallery_id (int): Current selected gallery for stale-result fencing.

        Returns:
            None: Stores only the first monotonic elapsed millisecond value.
        """
        if (not self._destroyed and self._session is session
                and self._session_provider() is session
                and self._catalogue_generation == generation
                and self._selected_gallery_id == gallery_id
                and not self._catalogue_cancel.is_set()):
            self._catalogue_performance.mark_milestone(
                'first_thumbnail_visible',
            )
    def _record_first_viewport_settled(
        self, session: LinkingSession, generation: int, gallery_id: int,
    ) -> None:
        """Accept the current viewer's first fully resolved visible tile band.

        Args:
            session (LinkingSession): Exact active media authorization owner.
            generation (int): Captured catalogue generation for stale fencing.
            gallery_id (int): Gallery selected when this viewer was mounted.

        Returns:
            None: Stores one current-pass milestone, not a compositor paint.
        """
        if (not self._destroyed and self._session is session
                and self._session_provider() is session
                and self._catalogue_generation == generation
                and self._selected_gallery_id == gallery_id
                and not self._catalogue_cancel.is_set()):
            self._catalogue_performance.mark_milestone(
                'first_viewport_settled',
            )
    def _select_gallery(
        self, gallery: LinkedGallery, *, implicit: bool = False,
        restore: bool = False,
    ) -> None:
        """Mount one exact gallery descriptor against the active session.

        Args:
            gallery (LinkedGallery): Fresh or saved display descriptor selected
                in the tree; media performs its own current authorization.
            implicit (bool): Whether this is an automatic first-preview attempt.
            restore (bool): Whether this restores a saved preference, without
                treating cached metadata as confirmation or a new user choice.

        Returns:
            None: The previous bounded viewer is replaced; its safe failure
                facts flow to the current-session Photos snapshot.
        """
        session = self._session
        if session is None:
            return
        self._selected_gallery_id = gallery.gallery_id
        self._photos_present = None
        self._restore_card_format(session, gallery.gallery_id)
        self._restore_photo_order(session, gallery.gallery_id)
        if not implicit and not restore:
            self._user_selected_gallery = True
            self._selection_saved = False
            self._persist_selected_gallery_if_fresh((gallery.gallery_id,))
        self.add_child_button.configure(
            state=("normal" if gallery.gallery_id in self._fresh_catalogue_ids
                   else "disabled"),
        )
        title = ''.join(char if char.isprintable() else ' ' for char in gallery.title)
        self._gallery_title.set(title.strip() or self._text("untitled"))
        self._update_recording_rows()
        if not implicit:
            self._implicit_pending = False
            if self._catalogue_paused_for_preview:
                self._catalogue_paused_for_preview = False
                if self._next_after is not None:
                    self._continue_catalogue()
        self.tree.selection_set(str(gallery.gallery_id))
        if not implicit:
            self.tree.see(str(gallery.gallery_id))
        diagnostic_generation = self._generation
        catalogue_generation = self._catalogue_generation
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._clear_photo_inspector()
        self.viewer_status.pack_forget()
        try:
            self._viewer = mount_native_gallery_view(
                self.viewer_pane,
                session,
                gallery,
                self._language,
                show_title=False,
                **({'newest_first': True} if self._photo_order_overrides.get(
                    gallery.gallery_id, 'oldest',
                ) == 'newest' else {}),
                thumbnail_memory=self._thumbnail_memory,
                canvas_grid_enabled=self._canvas_grid_enabled,
                thumbnail_batch_size=self._thumbnail_batch_size,
                context_menu=lambda photo, event: self._viewer_context(
                    gallery.gallery_id, photo, event,
                ),
                diagnostic_callback=lambda summary: (
                    self._record_photo_diagnostic(
                        diagnostic_generation, summary,
                    )
                ),
                photo_selected=self._inspect_photo,
                observation_callback=lambda summary: (
                    self._record_photo_observation(
                        diagnostic_generation, summary,
                    )
                ),
                preview_result=(
                    lambda has_photos: self._on_implicit_preview(
                        gallery.gallery_id,
                        has_photos,
                    )
                ) if implicit else None,
            )
            controller = self._viewer._gallery_viewer_controller
            controller.on_first_thumbnail_presented = lambda: (
                self._record_first_visible_thumbnail(
                    session, catalogue_generation, gallery.gallery_id,
                )
            )
            controller.on_first_viewport_settled = lambda: (
                self._record_first_viewport_settled(
                    session, catalogue_generation, gallery.gallery_id,
                )
            )
            self._viewer_viewport_pass_generation = catalogue_generation
            controller.set_card_format(self._card_format_overrides[gallery.gallery_id])
            controller.on_photos_changed = lambda: self._photos_changed(
                gallery.gallery_id,
            )
            controller.on_cache_changed = lambda photos, complete: (
                self._cache_snapshot_changed(gallery.gallery_id, photos, complete)
            )
            self._viewer.pack(fill="both", expand=True)
            self._catalogue_performance.mark_milestone(
                'gallery_view_mounted',
            )
        except tk.TclError:
            self.viewer_status.configure(text=self._text("error"))
            self.viewer_status.pack(fill="x", padx=8, pady=8)
            if implicit:
                self._on_implicit_preview(gallery.gallery_id, False)
        self._sync_subgalleries()
        self._refresh_cache_widgets(gallery.gallery_id)
        if self._preload_last is not None:
            self._show_preload_progress(self._preload_last)
    def _restore_photo_order(self, session: LinkingSession, gallery_id: int) -> None:
        """Restore this gallery's order on every navigation without remote writes.

        Args:
            session (LinkingSession): Exact verified site/account session.
            gallery_id (int): Physical gallery whose local order is restored.
        Returns:
            None: Updates the compact direction control and persistence warning.
        """
        overrides = getattr(self, '_photo_order_overrides', None)
        if overrides is None:
            self._photo_order_overrides = overrides = {}
        if gallery_id not in overrides:
            reader = getattr(self, '_load_photo_order', None)
            try:
                value = reader(session, gallery_id) if reader is not None else 'oldest'
            except (OSError, TypeError, ValueError):
                value = 'oldest'
            overrides[gallery_id] = 'newest' if value == 'newest' else 'oldest'
        button = getattr(self, '_photo_order_button', None)
        if button is not None:
            newest = overrides[gallery_id] == 'newest'
            unsaved = gallery_id in self._photo_order_unsaved
            button.configure(
                state='normal', text=('↓' if newest else '↑') + (' !' if unsaved else ''),
                style='Photos.Icon.TButton',
            )
            self._photo_order_hint.set(self._text(
                'order_unsaved' if unsaved else 'order_newest' if newest else 'order_oldest',
            ))
    def _toggle_photo_order(self) -> None:
        """Persist and apply the opposite local order to the selected gallery.

        Returns:
            None: Remounts one ordered viewer; future refreshes retain the rule.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        gallery = self._galleries.get(gallery_id)
        if session is None or gallery is None or self._session_provider() is not session:
            return
        value = ('oldest' if self._photo_order_overrides.get(gallery_id) == 'newest'
                 else 'newest')
        self._photo_order_overrides[gallery_id] = value
        saved = False
        if self._save_photo_order is not None:
            try:
                saved = self._save_photo_order(session, gallery_id, value) is True
            except (OSError, TypeError, ValueError):
                pass
        if saved:
            self._photo_order_unsaved.discard(gallery_id)
        else:
            self._photo_order_unsaved.add(gallery_id)
        self._select_gallery(gallery)
    def _restore_card_format(
        self, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Restore the gallery choice without issuing a write on navigation.

        Args:
            session (LinkingSession): Current Photos account/session identity.
            gallery_id (int): Gallery whose local preview format is selected.
        Returns:
            None: The compact selector reflects saved or session-local intent.
        """
        if gallery_id not in self._card_format_overrides:
            value = "default"
            if self._load_card_format is not None:
                try:
                    value = self._load_card_format(session, gallery_id)
                except (OSError, TypeError, ValueError):
                    pass
            self._card_format_overrides[gallery_id] = normalize_card_format(value)
        key = self._card_format_overrides[gallery_id]
        for format_key, button in self._card_format_buttons.items():
            selected = format_key == key
            artwork = self._card_format_images[(format_key, selected)]
            button.configure(
                state="normal", image=artwork if artwork is not None else "",
                style=("Selected.Format.Photos.Icon.TButton" if selected
                       else "Format.Photos.Icon.TButton"),
            )
        unsaved = gallery_id in self._card_format_unsaved
        self._card_format_notice.set("!" if unsaved else "")
        self._card_format_hint.set(self._text(
            "card_format_unsaved" if unsaved else "card_format_hint",
        ))
    def _activate_card_format(self, key: str) -> None:
        """Apply a visible format choice to the currently selected gallery.

        Args:
            key (str): Supported local photo-card format selected by the user.

        Returns:
            None: A current session and gallery receive the immediate choice.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if session is not None and gallery_id is not None:
            self._choose_card_format(session, gallery_id, key)
    def _choose_card_format(
        self, session: LinkingSession, gallery_id: int, key: str,
    ) -> None:
        """Apply one photo-card format and save only its local gallery choice.

        Args:
            session (LinkingSession): Exact session captured when opening choices.
            gallery_id (int): Exact gallery captured by the format action.
            key (str): Supported key activated in the visible format strip.
        Returns:
            None: Existing thumbnails reflow immediately; persistence failure
                leaves the displayed intent and a compact visible warning.
        """
        if (session is not self._session or session is not self._session_provider()
                or gallery_id != self._selected_gallery_id
                or key not in PHOTO_CARD_FORMATS):
            return
        self._card_format_overrides[gallery_id] = key
        if self._viewer is not None:
            self._viewer._gallery_viewer_controller.set_card_format(key)
        saved = False
        if self._save_card_format is not None:
            try:
                saved = self._save_card_format(session, gallery_id, key) is True
            except (OSError, TypeError, ValueError):
                pass
        if saved:
            self._card_format_unsaved.discard(gallery_id)
        else:
            self._card_format_unsaved.add(gallery_id)
        self._restore_card_format(session, gallery_id)
    def _choose_folder_view(self, key: str = 'default', *, order: bool = False) -> None:
        """Apply independent local folder presentation without server mutations.

        Args:
            key (str): Selected ratio, ignored when toggling record order.
            order (bool): Toggle oldest/newest child-gallery records when True.
        Returns:
            None: Reuses covers, persists only local intent and reports save failure.
        """
        session, gallery_id = self._session, self._selected_gallery_id
        if (session is None or gallery_id is None
                or self._session_provider() is not session):
            return
        current_format, current_order = self._folder_views.get(
            gallery_id, ('default', 'oldest'),
        )
        value = ('oldest' if current_order == 'newest' else 'newest') if order else key
        if not order and value not in PHOTO_CARD_FORMATS:
            return
        self._folder_views[gallery_id] = (
            current_format if order else value, value if order else current_order,
        )
        writer = self._save_folder_order if order else self._save_folder_format
        saved = False
        if writer is not None:
            try:
                saved = writer(session, gallery_id, value) is True
            except (OSError, TypeError, ValueError):
                pass
        identity = (gallery_id, 'order' if order else 'format')
        if saved:
            self._folder_unsaved.discard(identity)
        else:
            self._folder_unsaved.add(identity)
        self._sync_subgalleries()
    def _cancel_folder_resize(self) -> None:
        """Release a drag before hiding its section or changing identity.

        Returns:
            None: Stale release events cannot persist into another gallery.
        """
        self._folder_drag = None
        if self.grab_current() is self._photo_separator:
            self._photo_separator.grab_release()
    def _start_folder_resize(self, event: tk.Event) -> str:
        """Capture one mixed gallery and the pointer's offset within its line.

        Args:
            event (tk.Event): Mouse press on the visible divider or its line.
        Returns:
            str: break prevents unrelated selection or binding actions.
        """
        session, gallery_id = self._session, self._selected_gallery_id
        if (session is not None and gallery_id is not None
                and session is self._session_provider()
                and self._folders_visible and self._photos_present is not False):
            self._folder_drag = (
                session, gallery_id,
                event.y_root - self._photo_separator.winfo_rooty(),
            )
            self._photo_separator.focus_set()
            self._photo_separator.grab_set()
        return 'break'
    def _move_folder_resize(self, event: tk.Event) -> str:
        """Resize the shelf under the pointer without writing during motion.

        Args:
            event (tk.Event): Captured mouse motion, including outside the line.
        Returns:
            str: break keeps the drag scoped to this gallery divider.
        """
        drag = self._folder_drag
        if drag is None:
            return 'break'
        session, gallery_id, offset = drag
        if (session is not self._session_provider() or session is not self._session
                or gallery_id != self._selected_gallery_id
                or not self._folders_visible or self._photos_present is False):
            self._cancel_folder_resize()
            return 'break'
        height = event.y_root - offset - self._folder_shelf.winfo_rooty()
        share = round(height * GALLERY_FOLDER_SHARE_SCALE
                      / max(1, self.viewer_pane.winfo_height()))
        share = max(GALLERY_FOLDER_SHARE_BOUNDS[0],
                    min(share, GALLERY_FOLDER_SHARE_BOUNDS[1]))
        self._folder_splits[gallery_id] = share
        self._folder_shelf.set_height_share(share)
        return 'break'
    def _finish_folder_resize(self, event: tk.Event) -> str:
        """Commit a captured drag to the local gallery preference on release.

        Args:
            event (tk.Event): Mouse release from the divider's local grab.
        Returns:
            str: break ends only this drag; online gallery state is untouched.
        """
        drag = self._folder_drag
        if drag is not None:
            self._move_folder_resize(event)
            if self._folder_drag is not None:
                self._persist_folder_split(drag[0], drag[1])
        self._cancel_folder_resize()
        return 'break'
    def _persist_folder_split(self, session: LinkingSession, gallery_id: int) -> None:
        """Persist the current divider only for its still-selected identity.

        Args:
            session (LinkingSession): Exact live session that began this action.
            gallery_id (int): Physical gallery selected when the action began.
        Returns:
            None: A failed local write leaves the view usable and marks its hint.
        """
        if (session is not self._session or session is not self._session_provider()
                or gallery_id != self._selected_gallery_id):
            return
        saved = False
        if self._save_folder_split is not None:
            try:
                saved = self._save_folder_split(
                    session, gallery_id, self._folder_splits.get(gallery_id),
                ) is True
            except (OSError, TypeError, ValueError):
                pass
        if saved:
            self._folder_split_unsaved.discard(gallery_id)
        else:
            self._folder_split_unsaved.add(gallery_id)
        self._sync_subgalleries()
    def _key_folder_resize(self, event: tk.Event) -> str:
        """Move the focused divider by arrows or restore automatic with Home.

        Args:
            event (tk.Event): Up, Down or Home key on the section divider.
        Returns:
            str: break prevents the same key from navigating the photo grid.
        """
        session, gallery_id = self._session, self._selected_gallery_id
        if (session is None or gallery_id is None
                or session is not self._session_provider()
                or not self._folders_visible or self._photos_present is False):
            return 'break'
        share = None
        if event.keysym != 'Home':
            current = self._folder_splits.get(gallery_id)
            if current is None:
                current = round(self._folder_shelf.winfo_height()
                                * GALLERY_FOLDER_SHARE_SCALE
                                / max(1, self.viewer_pane.winfo_height()))
            # Adjust the saved intent, not a height temporarily clamped by a
            # smaller window. Down must never decrease the preferred share.
            step = -FOLDER_DIVIDER_KEY_STEP if event.keysym == 'Up' else FOLDER_DIVIDER_KEY_STEP
            share = max(GALLERY_FOLDER_SHARE_BOUNDS[0],
                        min(current + step, GALLERY_FOLDER_SHARE_BOUNDS[1]))
        self._folder_splits[gallery_id] = share
        self._folder_shelf.set_height_share(share)
        self._persist_folder_split(session, gallery_id)
        return 'break'
    def _sync_subgalleries(self) -> None:
        """Project wrapped folders and place each section's controls beside it.

        Returns:
            None: Absent or hidden folders have no ratio/order strip. Photos
                alone retain the compact heading; mixed photos get a lower strip.
        """
        parent_id = self._selected_gallery_id
        selected = self._galleries.get(parent_id) if parent_id is not None else None
        if selected is not None:
            title = ''.join(
                char if char.isprintable() else ' ' for char in selected.title
            ).strip() or self._text('untitled')
            if self._gallery_title.get() != title:
                self._gallery_title.set(title)
        if parent_id is not None and parent_id not in self._folder_views:
            values = []
            for reader, default in (
                (self._load_folder_format, 'default'), (self._load_folder_order, 'oldest'),
            ):
                try:
                    values.append(reader(self._session, parent_id) if reader else default)
                except (OSError, TypeError, ValueError):
                    values.append(default)
            self._folder_views[parent_id] = (
                normalize_card_format(values[0]),
                'newest' if values[1] == 'newest' else 'oldest',
            )
        card_format, order = self._folder_views.get(parent_id, ('default', 'oldest'))
        children = tuple(
            self._galleries[child_id]
            for child_id in sorted(
                self._children_by_parent.get(parent_id, ()), reverse=order == 'newest',
            ) if child_id != parent_id
        )
        if children:
            self._subgallery_switch.pack(side='right')
        else:
            self._subgallery_switch.pack_forget()
        visible = bool(children) and self._show_subgalleries.get()
        has_photos = self._photos_present is not False
        if self._folder_drag is not None and (
            self._folder_drag[:2] != (self._session, parent_id)
            or not visible or not has_photos
        ):
            self._cancel_folder_resize()
        if parent_id is not None and parent_id not in self._folder_splits:
            share = None
            if self._load_folder_split is not None and self._session is not None:
                try:
                    share = self._load_folder_split(self._session, parent_id)
                except (OSError, TypeError, ValueError):
                    pass
            self._folder_splits[parent_id] = (
                share if type(share) is int
                and GALLERY_FOLDER_SHARE_BOUNDS[0] <= share
                <= GALLERY_FOLDER_SHARE_BOUNDS[1] else None
            )
        self._folders_visible = visible
        self._folder_shelf.set_height_share(self._folder_splits.get(parent_id))
        self._folder_split_hint.set(self._text(
            'folder_split_unsaved' if parent_id in self._folder_split_unsaved
            else 'folder_split_hint',
        ))
        self._folder_shelf.set_view(card_format, has_photos)
        self._folder_shelf.show_children(self._session, parent_id, children)
        if self._viewer is not None:
            self._viewer._gallery_viewer_controller.set_folder_shelf_visible(visible)
        for key, button in self._folder_format_buttons.items():
            active = key == card_format
            button.configure(
                image=self._card_format_images[(key, active)] or '',
                style=('Selected.Format.Photos.Icon.TButton' if active
                       else 'Format.Photos.Icon.TButton'),
            )
        unsaved_format = (parent_id, 'format') in self._folder_unsaved
        unsaved_order = (parent_id, 'order') in self._folder_unsaved
        self._folder_notice.set('!' if unsaved_format else '')
        self._folder_format_hint.set(self._text(
            'folder_format_unsaved' if unsaved_format else 'folder_format_hint',
        ))
        self._folder_order_button.configure(
            text=('↓' if order == 'newest' else '↑') + (' !' if unsaved_order else ''),
        )
        self._folder_order_hint.set(self._text(
            'folder_order_unsaved' if unsaved_order else
            'folder_order_newest' if order == 'newest' else 'folder_order_oldest',
        ))
        layout = (visible, has_photos)
        if getattr(self, '_section_layout', None) != layout:
            self._section_layout = layout
            self._card_format_controls.grid_forget()
            self._card_format_controls.pack_forget()
            self._folder_format_controls.grid_forget()
            self._photo_separator.pack_forget()
            self._folder_shelf.pack_forget()
            if visible:
                self._folder_shelf.pack(fill='x', padx=8, after=self._gallery_heading)
                if has_photos:
                    self._photo_separator.pack(
                        fill='x', padx=8, pady=(2, 0), after=self._folder_shelf,
                    )
                    self._card_format_controls.pack(
                        anchor='w', padx=8, after=self._photo_separator,
                    )
            if self._viewer is not None:
                if visible and not has_photos:
                    self._viewer.pack_forget()
                elif not self._viewer.winfo_manager():
                    self._viewer.pack(fill='both', expand=True)
        event = tk.Event()
        event.width = self._gallery_heading.winfo_width()
        self._on_heading_resize(event)
    def _open_subgallery(self, gallery_id: int) -> None:
        """Open a folder card using the existing tree navigation path.

        Args:
            gallery_id (int): Exact server identity represented by the card.

        Returns:
            None: A current direct child can replace the selected gallery.
        """
        gallery = self._galleries.get(gallery_id)
        if (gallery is not None and gallery.parent_id == self._selected_gallery_id
                and gallery_id != self._selected_gallery_id):
            self._dismiss_context(restore=False)
            self._select_gallery(gallery)
    def _dismiss_context(self, *, restore: bool = True) -> None:
        """Close the inline card and release the owning window's bindings.

        Args:
            restore (bool): Whether to return focus to the surviving trigger.

        Returns:
            None: Other workspace controls remain available.
        """
        if self._gallery_edit_wait_id is not None:
            self.after_cancel(self._gallery_edit_wait_id)
            self._gallery_edit_wait_id = None
        dispatch = self._create_dispatch
        self._create_dispatch = None
        if dispatch is not None:
            dispatch.cancel()
        if self._probe_cancel is not None:
            self._probe_cancel.set()
            self._probe_cancel = None
        if self._context_focus_check_id is not None:
            try:
                self.after_cancel(self._context_focus_check_id)
            except tk.TclError:
                pass
            self._context_focus_check_id = None
        card, origin = self._context_card, self._context_origin
        self._context_card = self._context_origin = None
        for sequence, binding in self._context_bindings:
            try:
                self.winfo_toplevel().unbind(sequence, binding)
            except tk.TclError:
                pass
        self._context_bindings.clear()
        top = self.winfo_toplevel()
        for widget in self._context_tagged_widgets:
            try:
                tags = widget.bindtags()
                if self._context_bindtag in tags:
                    widget.bindtags(tuple(
                        tag for tag in tags if tag != self._context_bindtag
                    ))
            except tk.TclError:
                pass
        self._context_tagged_widgets.clear()
        if self._context_click_binding is not None:
            try:
                top.unbind_class(self._context_bindtag, "<ButtonPress>")
                top._root().deletecommand(self._context_click_binding)
            except tk.TclError:
                pass
            self._context_click_binding = None
        if self._context_focus_binding is not None:
            try:
                top.unbind_class(self._context_bindtag, "<FocusOut>")
                top._root().deletecommand(self._context_focus_binding)
            except tk.TclError:
                pass
            self._context_focus_binding = None
        if card is not None:
            card.destroy()
        if restore and origin is not None and origin.winfo_exists():
            origin.focus_set()
    def _context_outside(self, event: tk.Event) -> None:
        """Dismiss on a press outside the current contextual card.

        Args:
            event (tk.Event): Main-window pointer press and target widget.

        Returns:
            None: The original press can continue to its target.
        """
        if getattr(event, "num", None) in (4, 5):
            return  # X11 represents wheel motion as button events.
        card = self._context_card
        if card is not None and not (
            event.widget is card or str(event.widget).startswith(str(card) + '.')
        ):
            focused = self.focus_get()
            self._dismiss_context(restore=(
                focused is card or str(focused).startswith(str(card) + '.')
            ))

    def _photos_changed(self, gallery_id: int) -> None:
        """Invalidate stale cache coverage after a changed photo snapshot.

        Args:
            gallery_id (int): Gallery owned by the current viewer.

        Returns:
            None: Only the corresponding tree row is updated when needed.
        """
        self._invalidate_photo_info(gallery_id)
        if gallery_id == self._selected_gallery_id:
            self._clear_photo_inspector()
        if gallery_id in self._cache_state:
            del self._cache_state[gallery_id]
            self._cache_counts.pop(gallery_id, None)
            self._render_tree([gallery_id])
            self._refresh_cache_widgets(gallery_id)

    def _refresh_photos(self) -> None:
        """Refresh the selected gallery through its current viewer owner.

        Returns:
            None: The viewer preserves its selection and cache while folder
                covers refresh in the background without clearing current pixels.
        """
        self._folder_shelf.refresh_covers()
        if self._selected_gallery_id is not None:
            self._invalidate_photo_info(self._selected_gallery_id)
            self._clear_photo_inspector()
        if self._viewer is not None:
            controller = getattr(self._viewer, '_gallery_viewer_controller', None)
            if controller is not None:
                controller.refresh_photos()

    def refresh_session(self) -> None:
        """Switch to the current live gallery session and reload its tree.

        Returns:
            None: Releases stale reads, decoded media, contextual actions and old
                preload results; safe failure history resets only when the live
                session changes.
        """
        if self._destroyed:
            return
        session = self._session_provider()
        if session is self._session:
            return
        self._dismiss_context(restore=False)
        self._cancel_catalogue_continuation()
        with self._photo_diagnostic_lock:
            self._session = session
        if session is not None:
            session.set_upload_pipeline_waiting(self._upload_pipeline_waiting)
        self._photo_order_overrides.clear()
        self._photo_order_unsaved.clear()
        self._card_format_overrides.clear()
        self._card_format_unsaved.clear()
        self._folder_views.clear()
        self._folder_unsaved.clear()
        self._cancel_folder_resize()
        self._folder_splits.clear()
        self._folder_split_unsaved.clear()
        self._photos_present = None
        self._tree_state = (
            self._load_tree_state(session)
            if session is not None and self._load_tree_state is not None else {}
        )
        try:
            preferred_gallery = (
                self._load_selected_gallery(session)
                if session is not None and self._load_selected_gallery is not None
                else None
            )
        except (OSError, TypeError, ValueError):
            preferred_gallery = None
        self._preferred_gallery_id = (
            preferred_gallery if type(preferred_gallery) is int
            and 0 < preferred_gallery <= LINKING_GALLERY_ID_MAX else None
        )
        self._user_selected_gallery = False
        self._selection_saved = False
        self._cache_state.clear()
        self._gallery_pins.clear()
        self._cache_counts.clear()
        self._cache_pending.clear()
        self._photo_info_cache.clear()
        self._clear_photo_inspector()
        if self._preload_worker is not None:
            self._preload_worker.stop()
        self._preload_last = None
        self._preload_status_dismissed = False
        self._preload_strip.grid_remove()
        self._preload_bar.stop()
        self._set_preload_activity(False)
        self._generation += 1
        self._catalogue_generation += 1
        self._catalogue_cancel.set()
        self._catalogue_cancel = threading.Event()
        self._catalogue_performance = CataloguePerformanceWindow()
        self._clear_photo_diagnostics()
        self._cancel.set()
        self._cancel = threading.Event()
        self._clear_catalogue()
        self._catalogue_cache = None
        if session is not None and self._catalogue_cache_factory is not None:
            try:
                self._catalogue_cache = self._catalogue_cache_factory(session)
            except (OSError, TypeError, ValueError):
                self._catalogue_cache = None
        self._thumbnail_memory.clear()
        self._thumbnail_memory = GalleryThumbnailMemory()
        self._pages_this_pass = 0
        self._catalogue_rate_retries = 0
        self.load_more_button.pack_forget()
        self._pending_cursor = None
        self._has_pending_page = False
        if session is None:
            self.panes.grid_remove()
            self.connect_view.grid()
            return
        self.connect_view.grid_remove()
        self.panes.grid()
        self._set_catalogue_loading(True)
        self.viewer_status.configure(text=self._text("loading"))
        self._catalogue_performance.begin_pass()
        self._request_page(None)

    def _clear_catalogue(self) -> None:
        """Clear the previous catalogue, selection and embedded viewer.

        Returns:
            None: Old site data and image references are released immediately.
        """
        self._cancel_catalogue_continuation()
        self._cancel_cached_catalogue_mount()
        self._clear_photo_inspector()
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._galleries.clear()
        self._children_by_parent.clear()
        self._selected_gallery_id = None
        self.add_child_button.configure(state="disabled")
        for button in self._card_format_buttons.values():
            button.configure(state="disabled")
        self._card_format_notice.set("")
        order_button = getattr(self, '_photo_order_button', None)
        if order_button is not None:
            order_button.configure(state='disabled')
        self._gallery_title.set("")
        self._recording_heading.configure(image="")
        self._recording_rows.clear()
        self._sync_subgalleries()
        self._next_after = None
        self._catalogue_cache_snapshot = None
        self._catalogue_cache_ids.clear()
        self._fresh_catalogue_ids.clear()
        self._cached_mount_rows = ()
        self._cached_mount_index = 0
        self._complete_catalogue_tree = None
        self._implicit_pending = False
        self._implicit_attempted.clear()
        self._initial_attempt_count = 0
        self._catalogue_paused_for_preview = False
        self.tree.delete(*self.tree.get_children())
        self.tree_status.configure(text="")
        self._set_catalogue_loading(False)
        self.viewer_status.configure(text=self._text("select_gallery"))
        self.viewer_status.pack(fill="x", padx=8, pady=8)

    def _set_catalogue_loading(self, loading: bool) -> None:
        """Show localized catalogue status and animate the optional spinner.

        Args:
            loading (bool): Whether this session has an unfinished catalogue.

        Returns:
            None: Catalogue status and spinner timer share one loading state.
        """
        if not loading:
            self._cancel_catalogue_spinner()
        if self._destroyed:
            self._catalogue_loading = loading
            return
        if self._catalogue_loading == loading:
            return
        self._catalogue_loading = loading
        self.tree_status.configure(
            text=self._text("loading") if loading else "",
        )
        if loading and self._catalogue_spinner_frames:
            self._catalogue_spinner_label.configure(
                image=self._catalogue_spinner_frames[
                    self._catalogue_spinner_index
                ],
            )
            self._catalogue_spinner_label.grid()
            self._schedule_catalogue_spinner()
        else:
            self._catalogue_spinner_label.grid_remove()

    def _schedule_catalogue_spinner(self) -> None:
        """Schedule one identity-fenced step for the pre-rendered spinner.

        Returns:
            None: At most one active spinner callback is owned by this workspace.
        """
        if (self._destroyed or not self._catalogue_loading
                or not self._catalogue_spinner_frames
                or self._catalogue_spinner_after_id is not None):
            return
        after_id: str | None = None

        def advance() -> None:
            """Advance only the spinner timer that is still current.

            Returns:
                None: Delegates identity and lifecycle checks to the owner.
            """
            self._advance_catalogue_spinner(after_id)

        after_id = self.after(
            PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS, advance,
        )
        self._catalogue_spinner_after_id = after_id

    def _advance_catalogue_spinner(self, after_id: str | None) -> None:
        """Display the next retained frame and schedule one later frame.

        Args:
            after_id (str | None): Identity captured by the current Tk timer.

        Returns:
            None: Stale callbacks do not clear or update replacement animations.
        """
        if (after_id is None
                or after_id != self._catalogue_spinner_after_id):
            return
        self._catalogue_spinner_after_id = None
        if (self._destroyed or not self._catalogue_loading
                or not self._catalogue_spinner_frames):
            return
        self._catalogue_spinner_index = (
            self._catalogue_spinner_index + 1
        ) % len(self._catalogue_spinner_frames)
        self._catalogue_spinner_label.configure(
            image=self._catalogue_spinner_frames[
                self._catalogue_spinner_index
            ],
        )
        self._schedule_catalogue_spinner()

    def _cancel_catalogue_spinner(self) -> None:
        """Cancel the single scheduled catalogue-spinner frame callback.

        Returns:
            None: Clears its identity before best-effort Tk cancellation.
        """
        after_id = self._catalogue_spinner_after_id
        self._catalogue_spinner_after_id = None
        if after_id is None:
            return
        try:
            self.after_cancel(after_id)
        except tk.TclError:
            pass

    def _clear_photo_diagnostics(self) -> None:
        """Forget failures and observations when the live session changes.

        Returns:
            None: No prior-session trace remains in the copied snapshot.
        """
        with self._photo_diagnostic_lock:
            self._photo_diagnostic_latest = None
            self._photo_diagnostic_recent.clear()
            self._photo_observation_recent.clear()

    def _record_photo_observation(
        self, generation: int, summary: str,
    ) -> None:
        """Retain a safe non-failure Photos trace for current-session copying.

        Args:
            generation (int): Viewer/session generation that produced the trace.
            summary (str): Allowlisted shortcut or image-decoder observation.

        Returns:
            None: Observations stay separate from Latest failure and stale
                generations cannot enter the copied support snapshot.
        """
        if not is_safe_photos_observation(summary):
            return
        with self._photo_diagnostic_lock:
            if generation != self._generation:
                return
            self._photo_observation_recent.append(summary)

    def _record_preload_diagnostic(
        self, session: LinkingSession, summary: str,
    ) -> None:
        """Retain one safe preload fact only for its exact live session.

        Args:
            session (LinkingSession): Session captured by the preload worker.
            summary (str): Fixed safe line from the Photos diagnostic formatter.

        Returns:
            None: Same-session failures survive album changes; stale accounts
                and unsafe strings are refused.
        """
        if (type(summary) is not str
                or len(summary) > PHOTOS_DIAGNOSTIC_MAX_CHARS
                or not summary.isprintable()):
            return
        with self._photo_diagnostic_lock:
            if session is not self._session:
                return
            self._photo_diagnostic_latest = summary
            self._photo_diagnostic_recent.append(summary)
        self._notify_diagnostic_callback(summary)

    def _record_photo_diagnostic(
        self, generation: int, summary: str,
    ) -> None:
        """Retain one already-sanitized failure from the current generation.

        Args:
            generation (int): Viewer/session generation that produced the event.
            summary (str): Fixed safe line from the Photos diagnostic formatter.

        Returns:
            None: Current-session failures enter a bounded copyable snapshot.
        """
        if (type(summary) is not str
                or len(summary) > PHOTOS_DIAGNOSTIC_MAX_CHARS
                or not summary.isprintable()):
            return
        with self._photo_diagnostic_lock:
            if generation != self._generation:
                return
            self._photo_diagnostic_latest = summary
            self._photo_diagnostic_recent.append(summary)
        self._notify_diagnostic_callback(summary)

    def _notify_diagnostic_callback(self, summary: str) -> None:
        """Forward one retained safe line to the optional thread-safe host.

        Args:
            summary (str): Already validated current-session diagnostic line.

        Returns:
            None: Callback failures cannot replace or erase the local snapshot.
        """
        callback = getattr(self, '_diagnostic_callback', None)
        if not callable(callback):
            return
        try:
            callback(summary)
        except Exception:
            PHOTOS_WORKSPACE_LOGGER.warning(
                'Photos diagnostic host callback failed.',
            )

    def diagnostics_snapshot(self) -> str:
        """Return safe Photos failures, observations and live render counters.

        Returns:
            str: Latest safe failure, bounded failure history, allowlisted
                observations and numeric counters when a grid is mounted.
                A fixed message identifies a session without recorded failures.
        """
        with self._photo_diagnostic_lock:
            latest = self._photo_diagnostic_latest
            recent = tuple(self._photo_diagnostic_recent)
            observations = tuple(self._photo_observation_recent)
        if latest is None:
            lines = ['No Photos failure recorded for the current session.']
        else:
            lines = [
                'Latest failure: ' + latest,
                'Recent failures:',
                *('  ' + entry for entry in recent),
            ]
        if observations:
            lines.extend((
                'Recent observations:',
                *('  ' + entry for entry in observations),
            ))
        session = getattr(self, '_session', None)
        session_summary = getattr(
            session, 'route_performance_summary', None,
        )
        if callable(session_summary):
            try:
                lines.append(session_summary())
            except Exception:
                # A passive diagnostic failure cannot mask Photos errors.
                PHOTOS_WORKSPACE_LOGGER.warning(
                    'Photos route performance counters unavailable.',
                )
        server_summary = getattr(
            session, 'server_timing_summary', None,
        )
        if callable(server_summary):
            try:
                lines.append(server_summary())
            except Exception:
                # Passive diagnostics cannot mask the original gallery state.
                PHOTOS_WORKSPACE_LOGGER.warning(
                    'Photos PHP response timing unavailable.',
                )
        catalogue_performance = getattr(self, '_catalogue_performance', None)
        catalogue_summary = getattr(
            catalogue_performance, 'render_summary', None,
        )
        if callable(catalogue_summary):
            try:
                lines.append(catalogue_summary())
            except Exception:
                # An optional diagnostic renderer must not mask Photos state.
                PHOTOS_WORKSPACE_LOGGER.warning(
                    'Photos catalogue timing counters unavailable.',
                )
        controller = getattr(
            getattr(self, '_viewer', None), '_gallery_viewer_controller', None,
        )
        render_diagnostics = getattr(controller, 'render_diagnostics', None)
        if callable(render_diagnostics):
            try:
                lines.append(render_diagnostics())
            except Exception as error:
                # A diagnostic-only failure must not hide the original cause.
                PHOTOS_WORKSPACE_LOGGER.warning(
                    'Photos render counters unavailable: %s',
                    format_safe_photos_failure('media-apply', 'other', error),
                )
        return '\n'.join(lines)

    def _request_page(self, after_id: int | None) -> None:
        """Queue one server catalogue page on the current session.

        Args:
            after_id (int | None): Exclusive cursor from the prior page.

        Returns:
            None: At most one request occupies the session transport; safe
                catalogue failures remain available in the Photos snapshot.
        """
        if self._session is None or self._destroyed:
            return
        if self._worker is not None:
            self._pending_cursor = after_id
            self._has_pending_page = True
            return
        session = self._session
        generation = self._catalogue_generation
        diagnostic_generation = self._generation
        cancel = self._catalogue_cancel
        catalogue_cache = self._catalogue_cache
        retained_snapshot = self._catalogue_cache_snapshot

        def fetch() -> None:
            """Load optional display cache, then one fresh tree or page.

            Returns:
                None: Every result is generation-fenced by the Tk polling owner.
            """
            cached = retained_snapshot if after_id is None else None
            fallback_reason = 'none'
            if after_id is None:
                source = 'retained' if cached is not None else (
                    'disabled' if catalogue_cache is None else 'miss'
                )
                load_ms: float | None = None
                if cached is None and catalogue_cache is not None:
                    cache_started = time.perf_counter()
                    try:
                        cached = catalogue_cache.load(cancel)
                    finally:
                        load_ms = (
                            time.perf_counter() - cache_started
                        ) * 1000.0
                    if cached is not None:
                        source = 'disk'
                if not cancel.is_set():
                    self._events.put(_CatalogueCacheResult(
                        generation, cached, source, load_ms,
                    ))
            if cancel.is_set():
                return
            tree_reader = getattr(session, 'gallery_tree', None)
            tree_support = getattr(session, 'supports_gallery_tree', None)
            can_tree = (after_id is None and tree_reader is not None
                        and tree_support is not None and tree_support())
            if can_tree:
                tree_started = time.perf_counter()
                try:
                    tree = tree_reader(
                        cached.revision if cached is not None else None,
                        cancelled=cancel,
                    )
                except Exception as exc:
                    tree_ms = (
                        time.perf_counter() - tree_started
                    ) * 1000.0
                    if (isinstance(exc, LinkingResponseError)
                            and exc.status == 429 and not cancel.is_set()):
                        self._events.put(_CataloguePageResult(
                            generation, None, True,
                            exc.retry_after_seconds, after_id,
                            tree_ms, 'tree_error',
                        ))
                        return
                    if (not cancel.is_set()
                            and not isinstance(exc, LinkingMediaThrottled)):
                        _log_safe_media_failure(
                            'page-fetch', 'catalogue', exc,
                            diagnostic_callback=lambda summary: (
                                self._record_photo_diagnostic(
                                    diagnostic_generation, summary,
                                )
                            ),
                        )
                    if not cancel.is_set():
                        self._events.put(_CataloguePageResult(
                            generation, None, request_ms=tree_ms,
                            fallback_reason='tree_error',
                        ))
                    return
                if cancel.is_set():
                    return
                if tree is not None:
                    self._events.put(_CatalogueTreeResult(
                        generation, tree,
                        (time.perf_counter() - tree_started) * 1000.0,
                    ))
                    return
                fallback_reason = 'optional_unavailable'
            elif after_id is None:
                fallback_reason = 'capability_missing'
            page_started = time.perf_counter()
            try:
                page = session.gallery_page(after_id, cancelled=cancel)
            except Exception as exc:
                page_ms = (time.perf_counter() - page_started) * 1000.0
                if (isinstance(exc, LinkingResponseError)
                        and exc.status == 429 and not cancel.is_set()):
                    self._events.put(_CataloguePageResult(
                        generation, None, True,
                        exc.retry_after_seconds, after_id,
                        page_ms, fallback_reason,
                    ))
                    return
                if (not cancel.is_set()
                        and not isinstance(exc, LinkingMediaThrottled)):
                    _log_safe_media_failure(
                        'page-fetch', 'catalogue', exc,
                        diagnostic_callback=lambda summary: (
                            self._record_photo_diagnostic(
                                diagnostic_generation, summary,
                            )
                        ),
                    )
                page = None
            if not cancel.is_set():
                self._events.put(_CataloguePageResult(
                    generation, page,
                    request_ms=(time.perf_counter() - page_started) * 1000.0,
                    fallback_reason=fallback_reason,
                ))

        self._worker = threading.Thread(
            target=fetch,
            name="photos-catalogue-page",
            daemon=True,
        )
        self._worker.start()
        self._schedule_poll()

    def _schedule_poll(self) -> None:
        """Schedule one nonblocking result poll for active catalogue work.

        Returns:
            None: A single timer services this workspace's worker queue.
        """
        if self._poll_id is None and not self._destroyed:
            self._poll_id = self.after(PHOTOS_CATALOG_POLL_MS, self._poll_events)

    def _poll_events(self) -> None:
        """Apply context and catalogue results on the Tk thread.

        Returns:
            None: Current results apply before pending catalogue work advances.
        """
        self._poll_id = None
        self._update_preload_priority()
        self._poll_cache_work()
        while True:
            try:
                generation, completion = self._context_results.get_nowait()
            except queue.Empty:
                break
            self._context_busy = False
            if ((generation is None or generation == self._generation)
                    and self._session_provider() is self._session):
                completion()
        self._apply_photo_info_results()
        while True:
            try:
                result = self._events.get_nowait()
            except queue.Empty:
                break
            if (result.generation == self._catalogue_generation
                    and self._session is not None
                    and self._session_provider() is self._session
                    and not self._catalogue_cancel.is_set()):
                if isinstance(result, _CatalogueCacheResult):
                    stage = 'cache_' + result.source
                    self._catalogue_performance.observe(
                        stage, result.read_ms,
                        rows=(len(result.snapshot.galleries)
                              if result.snapshot is not None else 0),
                    )
                    self._accept_cached_catalogue(result.snapshot)
                elif isinstance(result, _CatalogueTreeResult):
                    self._accept_complete_catalogue(
                        result.tree, request_ms=result.request_ms,
                    )
                else:
                    if result.fallback_reason == 'tree_error':
                        self._catalogue_performance.observe(
                            'tree_throttled' if result.throttled
                            else 'tree_error', result.request_ms,
                        )
                    else:
                        if result.fallback_reason != 'none':
                            self._catalogue_performance.observe(
                                'paged_fallback', reason=result.fallback_reason,
                            )
                        self._catalogue_performance.observe(
                            'page_throttled' if result.throttled
                            else 'page_error' if result.page is None
                            else 'page_ok', result.request_ms,
                            rows=(len(result.page.galleries)
                                  if result.page is not None else 0),
                        )
                    self._accept_page(
                        result.page, throttled=result.throttled,
                        retry_after_seconds=result.retry_after_seconds,
                        cursor=result.cursor,
                    )
        if self._worker is not None and not self._worker.is_alive():
            if not self._events.empty():
                self._schedule_poll()
                return
            self._worker = None
        if self._worker is None and self._has_pending_page:
            cursor = self._pending_cursor
            self._pending_cursor = None
            self._has_pending_page = False
            self._request_page(cursor)
        self._update_preload_priority()
        if (self._worker is not None or self._has_pending_page or self._context_busy
                or self._cache_worker is not None or self._cache_pending
                or self._inspector_worker is not None
                or self._inspector_pending is not None
                or (self._preload_worker is not None
                    and self._preload_worker.is_alive())):
            self._schedule_poll()
