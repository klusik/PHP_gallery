# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/workspace/cache.py
# Module Type: Windows Client UI mixin
# Purpose: Own cached Photos catalogue reconciliation and bounded publication.
# Responsibilities: Reconcile snapshots and publish pages in the existing workspace.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Photos catalogue cache behavior mixin."""

from .support import (
    GalleryCatalogueSnapshot,
    LinkedGalleryTree,
    PHOTOS_CATALOGUE_CACHE_BATCH_DELAY_MS,
    PHOTOS_CATALOGUE_CACHE_BATCH_SIZE,
    logging,
    time,
    tk,
)

class PhotosWorkspaceCacheMixin:
    """Own bounded cached catalogue reconciliation on the Photos workspace."""

    def _schedule_cached_catalogue_batch(self) -> None:
        """Schedule the next cached or complete row slice on one owned timer.

        Returns:
            None: A single delayed callback advances the cached display.
        """
        if self._destroyed or self._cached_mount_after is not None:
            return
        after_id: str | None = None
        generation = self._catalogue_generation
        session = self._session
        cancel = self._catalogue_cancel

        def mount() -> None:
            """Advance only the cached batch callback still owned here.

            Returns:
                None: Replaced or cancelled callbacks cannot advance the tree.
            """
            if (after_id is None or self._cached_mount_after != after_id
                    or self._catalogue_generation != generation
                    or self._session is not session
                    or self._session_provider() is not session
                    or self._catalogue_cancel is not cancel or cancel.is_set()):
                return
            self._mount_cached_catalogue_batch()

        after_id = self.after(
            PHOTOS_CATALOGUE_CACHE_BATCH_DELAY_MS, mount,
        )
        self._cached_mount_after = after_id

    def _cancel_cached_catalogue_mount(self) -> None:
        """Cancel a pending cached-row batch and release its remaining rows.

        Returns:
            None: Late callbacks fail their identity check after cancellation.
        """
        after_id = self._cached_mount_after
        self._cached_mount_after = None
        if after_id is None:
            return
        try:
            self.after_cancel(after_id)
        except tk.TclError:
            pass

    def _accept_complete_catalogue(
        self, tree: LinkedGalleryTree, *,
        request_ms: float | None = None,
    ) -> None:
        """Stage a fresh full tree for bounded, difference-only UI updates.

        Args:
            tree (LinkedGalleryTree): Validated complete tree-v1 response.
            request_ms (float | None): Actual measured transport operation,
                or None when this method is called without a worker result.

        Returns:
            None: Cached display survives until the complete tree is mounted.
        """
        cached = self._catalogue_cache_snapshot
        if tree.unchanged:
            if (cached is None or cached.revision != tree.revision):
                self._catalogue_performance.observe('tree_error', request_ms)
                self._request_page(None)
                return
            galleries = cached.galleries
        else:
            galleries = tree.galleries
        self._catalogue_performance.observe(
            'tree_unchanged' if tree.unchanged else 'tree_full',
            request_ms, rows=len(galleries),
        )
        self._cancel_cached_catalogue_mount()
        self.retry_button.pack_forget()
        self._catalogue_cache_ids.update(self._galleries)
        self._fresh_catalogue_ids.clear()
        self._complete_catalogue_tree = tree
        self._cached_mount_rows = galleries
        self._cached_mount_index = 0
        # The response is already complete even while Tk mounts its rows in
        # slices. Save it now so quitting during display retains the full tree.
        self._catalogue_cache_snapshot = GalleryCatalogueSnapshot(
            galleries, tree.revision,
        )
        if not tree.unchanged:
            self._queue_catalogue_snapshot_save(
                self._catalogue_cache_snapshot,
            )
        self._set_catalogue_loading(True)
        self._mount_complete_catalogue_batch()

    def _mount_complete_catalogue_batch(self) -> None:
        """Confirm a bounded full-tree slice and render only changed rows.

        Returns:
            None: Unchanged mounted rows keep their widgets, expansion and focus.
        """
        tree = self._complete_catalogue_tree
        if tree is None or self._destroyed or self._catalogue_cancel.is_set():
            return
        mount_started = time.perf_counter()
        stop = min(
            len(self._cached_mount_rows),
            self._cached_mount_index + PHOTOS_CATALOGUE_CACHE_BATCH_SIZE,
        )
        changed: list[int] = []
        confirmed: list[int] = []
        while self._cached_mount_index < stop:
            gallery = self._cached_mount_rows[self._cached_mount_index]
            self._cached_mount_index += 1
            gallery_id = gallery.gallery_id
            prior = self._galleries.get(gallery_id)
            if (prior is not None and prior.parent_id is not None
                    and prior.parent_id != gallery.parent_id):
                children = self._children_by_parent.get(prior.parent_id)
                if children is not None:
                    children.discard(gallery_id)
            self._galleries[gallery_id] = gallery
            self._fresh_catalogue_ids.add(gallery_id)
            confirmed.append(gallery_id)
            if gallery.parent_id is not None:
                self._children_by_parent.setdefault(
                    gallery.parent_id, set(),
                ).add(gallery_id)
            if prior != gallery or not self.tree.exists(str(gallery_id)):
                changed.append(gallery_id)
        self._persist_selected_gallery_if_fresh(confirmed)
        if changed:
            self._render_tree(changed)
            for gallery_id in changed:
                self._cache_pending.setdefault(gallery_id, (None, False))
            self._start_cache_probe()
            self._sync_subgalleries()
        if confirmed and any(
            self.tree.exists(str(gallery_id)) for gallery_id in confirmed
        ):
            self._catalogue_performance.mark_milestone(
                'fresh_rows_confirmed',
            )
        if self._selected_gallery_id in self._fresh_catalogue_ids:
            self.add_child_button.configure(state="normal")
            self._rearm_current_viewport_after_fresh_catalogue()
        if self._cached_mount_index < len(self._cached_mount_rows):
            self._restore_or_choose_gallery(complete=False)
            self._schedule_cached_catalogue_batch()
            self._catalogue_performance.observe(
                'mount_fresh', (time.perf_counter() - mount_started) * 1000.0,
                rows=len(confirmed),
            )
            return
        self._complete_catalogue_tree = None
        self._prune_cached_catalogue_rows()
        self._discard_unconfirmed_selection()
        self._pages_this_pass = 1
        self._next_after = None
        self._has_pending_page = False
        self._pending_cursor = None
        self._set_catalogue_loading(False)
        if not self._cached_mount_rows:
            self.tree_status.configure(text=self._text("empty"))
            self.viewer_status.configure(text=self._text("empty"))
        else:
            self.tree_status.configure(text="")
        self._restore_or_choose_gallery(complete=True)
        self._catalogue_performance.observe(
            'mount_fresh', (time.perf_counter() - mount_started) * 1000.0,
            rows=len(confirmed),
        )

    def _persist_selected_gallery_if_fresh(
        self, fresh_ids: tuple[int, ...] | list[int],
    ) -> None:
        """Save a user choice only after its ID appears in a fresh response.

        Args:
            fresh_ids (tuple[int, ...] | list[int]): IDs confirmed by the
                current full tree or one validated current page.

        Returns:
            None: Cached-only selections never become persisted preferences.
        """
        selected_id = self._selected_gallery_id
        session = self._session
        if (not self._user_selected_gallery or self._selection_saved
                or selected_id is None or selected_id not in fresh_ids
                or selected_id not in self._fresh_catalogue_ids
                or session is None or self._save_selected_gallery is None):
            return
        try:
            self._save_selected_gallery(session, selected_id)
        except (OSError, TypeError, ValueError):
            logging.debug("Photos selection preference could not be saved.")
            return
        self._selection_saved = True

    def _restore_or_choose_gallery(self, *, complete: bool) -> None:
        """Restore a fresh saved row or choose the established default.

        Args:
            complete (bool): Whether all current server rows are known.

        Returns:
            None: User selection wins; absent saved IDs fall back only at finish.
        """
        if self._user_selected_gallery or self._selected_gallery_id is not None:
            return
        preferred_id = self._preferred_gallery_id
        if (preferred_id is not None
                and preferred_id in self._fresh_catalogue_ids):
            preferred = self._galleries.get(preferred_id)
            if preferred is not None:
                self._select_gallery(preferred, restore=True)
                return
        if preferred_id is not None and complete:
            self._preferred_gallery_id = None
        if self._preferred_gallery_id is None and self._fresh_catalogue_ids:
            self._implicit_pending = True
            self._ensure_initial_gallery()

    def _prune_cached_catalogue_rows(self) -> None:
        """Remove cached-only IDs after a successful complete page pass.

        Returns:
            None: Failed or partial page sequences retain the prior complete tree.
        """
        stale_ids = self._catalogue_cache_ids - self._fresh_catalogue_ids
        for fresh_id in self._fresh_catalogue_ids:
            item_id = str(fresh_id)
            if not self.tree.exists(item_id):
                continue
            parent_id = self.tree.parent(item_id)
            if parent_id and int(parent_id) in stale_ids:
                self.tree.move(
                    item_id, "", self._ordered_index("", fresh_id),
                )
        for stale_id in stale_ids:
            item_id = str(stale_id)
            if self.tree.exists(item_id):
                self.tree.delete(item_id)
            self._galleries.pop(stale_id, None)
        if stale_ids:
            self._children_by_parent = {}
            for gallery in self._galleries.values():
                if gallery.parent_id is not None:
                    self._children_by_parent.setdefault(
                        gallery.parent_id, set(),
                    ).add(gallery.gallery_id)
            self._sync_subgalleries()

    def _discard_unconfirmed_selection(self) -> None:
        """Release a cached-only selection after a complete fresh catalogue.

        Returns:
            None: A stale viewer cannot outlive confirmation of its gallery ID.
        """
        selected_id = self._selected_gallery_id
        if (selected_id is None or selected_id in self._fresh_catalogue_ids):
            return
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._clear_photo_inspector()
        self._selected_gallery_id = None
        self._user_selected_gallery = False
        self._selection_saved = False
        self.add_child_button.configure(state="disabled")
        self._gallery_title.set("")
        self.viewer_status.configure(text=self._text("select_gallery"))
        self.viewer_status.pack(fill="x", padx=8, pady=8)
        self.tree.selection_remove(*self.tree.selection())

    def _queue_catalogue_snapshot_save(
        self, snapshot: GalleryCatalogueSnapshot,
    ) -> None:
        """Serialize the latest known structure through one worker slot.

        Args:
            snapshot (GalleryCatalogueSnapshot): Last-known display tree;
                only a complete tree-v1 result carries a server revision.

        Returns:
            None: At most one writer runs and one newest snapshot waits behind
                it. Already accepted local metadata finishes saving on exit.
        """
        cache = self._catalogue_cache
        session = self._session
        if (cache is None or session is None or self._destroyed
                or self._cancel.is_set()):
            return
        self._catalogue_cache_writer.submit(cache, snapshot)

    def _remember_catalogue_progress(self) -> None:
        """Persist confirmed additions without dropping previously known rows.

        Returns:
            None: Every successful page or creation updates the atomic cache.
                Unmounted cached rows survive; partial data has no tree revision
                and cannot authorize actions or prove remote removal.
        """
        snapshot = self._catalogue_cache_snapshot
        known = {
            gallery.gallery_id: gallery
            for gallery in snapshot.galleries
        } if snapshot is not None else {}
        known.update(
            (gallery_id, gallery)
            for gallery_id, gallery in self._galleries.items()
            # During full-tree mounting, visible old rows may already be absent
            # from the confirmed response. Only fresh rows can extend/replace
            # that snapshot; unmounted new descriptors must keep their titles.
            if gallery_id in self._fresh_catalogue_ids
        )
        self._catalogue_cache_snapshot = GalleryCatalogueSnapshot(tuple(
            known[gallery_id] for gallery_id in sorted(known)
        ))
        self._queue_catalogue_snapshot_save(self._catalogue_cache_snapshot)

    def _accept_cached_catalogue(
        self, snapshot: GalleryCatalogueSnapshot | None,
    ) -> None:
        """Queue bounded display of the last-known tree without authority.

        Args:
            snapshot (GalleryCatalogueSnapshot | None): Integrity-checked
                account-and-scope-bound cached metadata, or a cache miss.

        Returns:
            None: Cached rows remain display-only until fresh reads confirm them.
        """
        if snapshot is None or self._destroyed:
            return
        self._catalogue_cache_snapshot = snapshot
        self._catalogue_cache_ids = {
            gallery.gallery_id for gallery in snapshot.galleries
        }
        self._cached_mount_rows = snapshot.galleries
        self._cached_mount_index = 0
        self._mount_cached_catalogue_batch()

    def _mount_cached_catalogue_batch(self) -> None:
        """Mount one bounded batch of cached or freshly confirmed tree rows.

        Returns:
            None: Fresh rows win over cache and remaining rows yield to Tk.
        """
        self._cached_mount_after = None
        if self._complete_catalogue_tree is not None:
            self._mount_complete_catalogue_batch()
            return
        if (self._destroyed or self._catalogue_cache_snapshot is None
                or self._cached_mount_index >= len(self._cached_mount_rows)):
            return
        mount_started = time.perf_counter()
        mount_from = self._cached_mount_index
        stop = min(
            len(self._cached_mount_rows),
            self._cached_mount_index + PHOTOS_CATALOGUE_CACHE_BATCH_SIZE,
        )
        changed: list[int] = []
        while self._cached_mount_index < stop:
            gallery = self._cached_mount_rows[self._cached_mount_index]
            self._cached_mount_index += 1
            gallery_id = gallery.gallery_id
            if gallery_id in self._fresh_catalogue_ids:
                continue
            self._galleries[gallery_id] = gallery
            changed.append(gallery_id)
            if gallery.parent_id is not None:
                self._children_by_parent.setdefault(
                    gallery.parent_id, set(),
                ).add(gallery_id)
        if changed:
            self._render_tree(changed)
            self._catalogue_performance.mark_milestone(
                'cached_rows_mounted',
            )
            for gallery_id in changed:
                self._cache_pending.setdefault(gallery_id, (None, False))
            self._start_cache_probe()
            self._sync_subgalleries()
            preferred_id = self._preferred_gallery_id
            if (self._selected_gallery_id is None
                    and not self._user_selected_gallery
                    and preferred_id in changed):
                preferred = self._galleries.get(preferred_id)
                if preferred is not None:
                    self._select_gallery(preferred, restore=True)
        if self._cached_mount_index < len(self._cached_mount_rows):
            self._schedule_cached_catalogue_batch()
        self._catalogue_performance.observe(
            'mount_cached', (time.perf_counter() - mount_started) * 1000.0,
            rows=stop - mount_from,
        )
