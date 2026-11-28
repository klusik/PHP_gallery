# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/qt/media.py
# Module Type: Qt Photos domain mixin
# Purpose: Keep one cohesive Photos interaction domain outside the widget facade.
# Responsibilities: Preserve the QtPhotos lifecycle while grouping its callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Qt Photos behavior mixin declarations."""

from .support import (
    Callable,
    DesktopLinkedSite,
    Event,
    GALLERY_VIEWPORT_HISTORY,
    GalleryViewport,
    LINKING_KEEPALIVE_SECONDS,
    LinkedGallery,
    LinkedPhoto,
    LinkedPhotos,
    LinkingError,
    LinkingProgress,
    LinkingThumbnailProofChanged,
    LiveSiteCatalogue,
    MEDIA_HTTP_BATCH_INITIAL_SIZE,
    MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
    MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS,
    OfflineBinding,
    Path,
    PhotoCatalogue,
    PhotoFrame,
    PhotoRow,
    PhotosLoadResult,
    PhotosUploadUpgrade,
    QT_PHOTOS_LOGGER,
    QRectF,
    QTreeWidgetItem,
    QUrl,
    Qt,
    SavedLinkRecovery,
    ThumbnailActivity,
    ThumbnailEvent,
    ThumbnailGroup,
    ThumbnailProofPage,
    UI_LAYOUT_INFO_WIDTH_BOUNDS,
    UI_LAYOUT_VIEWER_MINIMUM_WIDTH,
    VIEWPORT_TEXTURE_LIMIT,
    enable_rolling_renewal,
    gettext,
    load_gallery_card_format,
    load_gallery_folder_split,
    load_gallery_photo_order,
    load_photos_tree_preferences,
    logging,
    persist_browser_scope_upgrade,
    photos_account_tree_path,
    qt_icon,
    read_gallery_photos,
    read_gallery_tree,
    replace,
    save_gallery_photo_order,
    save_photos_tree_preferences,
    saved_desktop_gallery_binding,
    saved_offline_hint_path,
    stream_revalidated_thumbnails,
    stream_thumbnails,
    time,
    tr,
    verify_live_gallery_activity,
)

class QtPhotosMediaMixin:
    """Own one cohesive set of Qt Photos widget behaviors."""

    def _receive_catalogue(self, result: PhotosLoadResult) -> None:
        """Apply one current worker-owned tree or photo catalogue.

        Args:
            result (PhotosLoadResult): Current authorization, catalogue or progress result.
        Returns:
            None: Mounts authorized rows, exact startup recovery or retains the
                live viewer when only its tree labels or parent paths changed.
        """
        if isinstance(result, PhotosUploadUpgrade):
            self._finish_admin_upload_upgrade(result)
            return
        if isinstance(result, SavedLinkRecovery):
            if self._startup_browse:
                self._startup_saved_mode = result.saved
                self.link_button.setText(gettext('Reapprove saved link'))
                self.status.setText(
                    tr(self.language, 'linking_saved_refresh_pending'),
                )
                self._sync_connection_state()
            return
        if isinstance(result, LinkingProgress):
            if self._authorization_active or self._startup_browse:
                if result.failed:
                    self._authorization_failure = result.caption
                self.status.setText(result.caption)
                self._sync_authorization_dialog()
                self._sync_connection_state()
            return
        switching_site = False
        if isinstance(result, LiveSiteCatalogue):
            site = result.site
            pending = self._pending_live_site
            if (pending is None or pending.session is not site.session
                    or pending.issuer != site.issuer or pending.user_id != site.user_id
                    or not self._contains_live_site(site)):
                return
            self._pending_live_site = None
            switching_site = True
            result = LinkedPhotos(
                site.session, site.issuer, site.user_id,
                result.galleries, result.tree_state, owns_session=False,
            )
        if isinstance(result, PhotoCatalogue):
            if self._session is None or not self._session.available():
                return
            self._install_catalogue(result.rows)
            self._queue_gallery_cache_probe(result.rows)
            self._gallery_has_photos = bool(result.rows)
            self._sync_folder_section()
            notice = self._catalogue_notice
            self._catalogue_notice = None
            if notice is not None and notice[0] == self._gallery_id:
                self.status_message(notice[1])
            return
        same_tree_owner = (
            not result.owns_session and result.session is self._session
            and self._identity == (result.issuer, result.user_id) and result.session.available()
        )
        same_writer_owner = False
        if (not result.owns_session and not same_tree_owner
                and not switching_site and self.connections is not None):
            try:
                writer_target = self.connections.active()
            except RuntimeError:
                writer_target = None
            same_writer_owner = bool(
                writer_target is not None and writer_target.session is result.session
                and writer_target.issuer == result.issuer
                and writer_target.user_id == result.user_id
            )
        current_gallery = self._gallery_id if same_tree_owner else None
        if (not result.owns_session and not same_tree_owner and not switching_site
                and not same_writer_owner):
            return
        if self._startup_browse:
            self._startup_browse = False
            self.status.clear()
        if self._authorization_active:
            self._authorization_active = False
            self._attempt = None
            self._link_cancel_button.hide()
            self.status.clear()
        if self._selecting_saved_target:
            self._selecting_saved_target = False
            self.work_changed.emit(False)
        if isinstance(result, LinkedPhotos):
            self._close_authorization_dialog()
        replacing_account = not same_tree_owner and (
            self._identity == (result.issuer, result.user_id)
            or any(
                site.session is not result.session
                and (site.issuer, site.user_id) == (result.issuer, result.user_id)
                for site in self._parked_links
            )
        )
        writer_session = None
        if replacing_account and self.connections is not None:
            try:
                writer = self.connections.active()
                if writer is not None:
                    writer_session = writer.session
            except RuntimeError:
                # An in-flight transfer still owns its grant even when mode
                # inspection cannot establish a new upload target.
                pass
        if self._session is not None and self._session is not result.session:
            same_account = self._identity == (result.issuer, result.user_id)
            if (not self._can_switch_photos_site()
                    or same_account and not self._can_disconnect()):
                if result.owns_session:
                    result.session.close()
                self.link_button.setEnabled(True)
                self.progress.hide()
                return
            current = DesktopLinkedSite(
                self._identity[0], self._session, self._identity[1],
            ) if self._identity is not None else None
            if current is not None and same_account and current.session is not writer_session:
                current.session.close()
            elif current is not None and self._contains_live_site(current):
                self._park_current_link()
            else:
                self._session.close()
            self._gallery_id = None
            self._retire_preload()
            self._install_catalogue(())
            self._viewports.clear()
            self._dismiss_information()
            self._photo_info_cache.clear()
        elif self._local_photos_active:
            self._install_catalogue(())
            self._viewports.clear()
            self._dismiss_information()
        retained_sites: list[DesktopLinkedSite] = []
        for site in self._parked_links:
            if site.session is result.session:
                continue
            if (site.issuer == result.issuer and site.user_id == result.user_id
                    and site.session is not writer_session
                    and not self.transfer_busy()):
                site.session.close()
                continue
            retained_sites.append(site)
        self._parked_links = tuple(retained_sites)
        next_identity = (result.issuer, result.user_id)
        if self._identity != next_identity:
            self._cache_states.clear()
            self._gallery_pins.clear()
        if self._startup_saved_mode is not None:
            self._startup_saved_mode = None
            self.link_button.setText(tr(self.language, 'linking_start'))
        self._session = result.session
        self._session.set_upload_pipeline_waiting(self._pipeline_waiting)
        self._identity = result.issuer, result.user_id
        if not same_tree_owner:
            self.connection_changed.emit()
        tree_path = photos_account_tree_path(self.profile, result.issuer, result.user_id)
        self._tree_path = tree_path
        if not same_tree_owner:
            self._opened_galleries = dict(result.tree_state.opened)
        self._view_path = tree_path.with_name(tree_path.stem + ".view.json")
        tree = self._gallery_tree
        tree.blockSignals(True)
        tree.clear()
        items = {row.gallery_id: QTreeWidgetItem([row.title]) for row in result.galleries}
        children: dict[int, list[LinkedGallery]] = {}
        # Nested Qt items are rooted only when every ancestor is ordered above
        # its child. A missing or malformed ancestry remains visible at root.
        visited: set[int] = set()
        for row in result.galleries:
            item = items[row.gallery_id]
            item.setData(0, Qt.ItemDataRole.UserRole, str(row.gallery_id))
            if row.parent_id is not None and row.parent_id in visited:
                items[row.parent_id].addChild(item)
                children.setdefault(row.parent_id, []).append(row)
            else:
                tree.addTopLevelItem(item)
            visited.add(row.gallery_id)
        for gallery_id, item in items.items():
            item.setExpanded(self._opened_galleries.get(gallery_id, False))
        current_item = items.get(current_gallery)
        preferred = current_item or items.get(result.tree_state.selected)
        item = preferred or tree.topLevelItem(0)
        if item is not None:
            ancestor = item.parent()
            while ancestor is not None:
                ancestor.setExpanded(True)
                ancestor = ancestor.parent()
            tree.setCurrentItem(item)
        tree.blockSignals(False)
        self._gallery_items = items
        self._gallery_children = {
            parent: tuple(sorted(rows, key=lambda row: row.gallery_id))
            for parent, rows in children.items()
        }
        for gallery_id in items:
            self._refresh_tree_marker(gallery_id)
        tree.setVisible(bool(items))
        self._gallery_panel.setVisible(bool(items) and not self.detailOpen)
        self._local_photos_active = False
        self._sync_connection_state()
        if not same_tree_owner:
            self.restore_tree_width(self._preferred_tree_width)
        self.link_button.setEnabled(True)
        if current_item is not None:
            self._gallery_title.setText(current_item.text(0))
            self._sync_folder_section()
            if result.revalidate_gallery == current_gallery:
                self.refresh()
        elif item is not None:
            self._select_gallery(int(item.data(0, Qt.ItemDataRole.UserRole)))
        else:
            self._gallery_id = None
            self._gallery_title.clear()
            self.playback.bind(None, None)
            self._gallery_options.setIcon(self._gallery_state_icon(None, show_capture=False))
            self._gallery_options.setVisible(self._identity is not None)
            self._install_catalogue(())
            self.status.setText(gettext("No galleries found."))
        self._sync_capture_markers()
        if result.owns_session:
            self._remember_recent_address(result.issuer)
    def restore_tree_width(self, width: int) -> None:
        """Restore the shared Photos tree geometry when a gallery is linked.

        Args:
            width (int): Saved width of the hierarchy in logical pixels.
        Returns:
            None: Applies the split or retains it while the tree is hidden.
        """
        self._preferred_tree_width = width
        if not self._gallery_panel.isHidden():
            self._apply_pane_widths(width)
    def tree_width(self) -> int:
        """Return the current hierarchy width for shared layout persistence.

        Returns:
            int: Current logical width, zero if the hierarchy is hidden.
        """
        return self._split.sizes()[0]
    def restore_inspector_width(self, width: int) -> None:
        """Restore the common Photos inspector width when metadata opens.

        Args:
            width (int): Saved right pane width in logical pixels.
        Returns:
            None: Retains the preferred width or applies it within available space.
        """
        minimum, maximum = UI_LAYOUT_INFO_WIDTH_BOUNDS
        self._inspector_width = min(maximum, max(minimum, width))
        if not self._inspector_panel.isHidden():
            self._apply_pane_widths(self.tree_width())
    def _apply_pane_widths(self, tree: int) -> None:
        """Reserve space for the gallery before sizing the right inspector.

        Args:
            tree (int): Requested visible tree width in logical pixels, zero when hidden.
        Returns:
            None: Sets native handle limits and restores the preferred split where it fits.
        """
        inspector_visible = not self._inspector_panel.isHidden()
        # One handle divides each adjacent pair of visible panes.
        handles = int(not self._gallery_panel.isHidden()) + int(inspector_visible)
        pane_width = (
            self._split.contentsRect().width() - handles * self._split.handleWidth()
        )
        remaining = pane_width - tree
        inspector = 0
        if inspector_visible:
            minimum, maximum = UI_LAYOUT_INFO_WIDTH_BOUNDS
            natural_minimum = self._inspector_panel.minimumSizeHint().width()
            maximum = min(maximum, max(
                natural_minimum, remaining - UI_LAYOUT_VIEWER_MINIMUM_WIDTH,
            ))
            self._inspector_panel.setMinimumWidth(min(minimum, maximum))
            self._inspector_panel.setMaximumWidth(maximum)
            inspector = min(self._inspector_width, maximum)
        sizes = [tree, max(0, remaining - inspector), inspector]
        if self._split.sizes() != sizes:
            self._split.setSizes(sizes)
    def inspector_width(self) -> int:
        """Read the visible inspector width without overwriting hidden preferences.

        Returns:
            int: Logical right pane width, or zero while the pane is hidden.
        """
        return self._split.sizes()[2]
    @property
    def preferred_inspector_width(self) -> int:
        """Return the user-controlled width independently of temporary geometry.

        Returns:
            int: Logical inspector width to retain in the shared profile.
        """
        return self._inspector_width
    def _remember_pane_widths(self, _position: int, index: int) -> None:
        """Publish user splitter movement through the common layout owner.

        Args:
            _position (int): Native leading edge of the moved handle.
            index (int): Index of the pane immediately following that handle.
        Returns:
            None: Retains the moved pane preference for subsequent openings.
        """
        following = self._split.widget(index)
        if following is self._center_panel:
            self.restore_tree_width(self.tree_width())
        elif following is self._inspector_panel:
            minimum, maximum = UI_LAYOUT_INFO_WIDTH_BOUNDS
            self._inspector_width = min(maximum, max(minimum, self.inspector_width()))
        self.splitter_moved.emit()
    def _finish_admin_upload_upgrade(self, upgrade: PhotosUploadUpgrade) -> None:
        """Commit a same-target Admin renewal on the Qt owner thread.

        Args:
            upgrade (PhotosUploadUpgrade): Verified grant and captured owner snapshots.
        Returns:
            None: Mounts the committed Photos grant or retains the previous view.
        """
        connections = self.connections
        target, session = upgrade.target, upgrade.session
        if (connections is None or connections.commit_scope_upgrade is None
                or self._session is not upgrade.previous_session
                or self._identity != (upgrade.binding.issuer, upgrade.binding.account_id)
                or self.busy() or self.transfer_busy()
                or not session.available() or not session.can_upload()
                or not session.can_read_admin_media()
                or not session.matches_issuer(upgrade.binding.issuer)
                or session.approved_scope() != upgrade.binding.scope
                or (target.issuer, target.user_id, target.gallery_id)
                != (upgrade.binding.issuer, upgrade.binding.account_id,
                    upgrade.previous_target.gallery_id)
                or target.session is not session):
            session.close()
            self.status_message('The selected gallery changed during approval.')
            return
        try:
            old_binding = OfflineBinding(
                upgrade.binding.issuer, upgrade.binding.account_id,
                upgrade.previous_scope,
            )
            if (connections.saved() != upgrade.previous_mode
                    or connections.active() is not upgrade.previous_target
                    or saved_desktop_gallery_binding(
                        self.profile / 'offline-link.json', upgrade.binding.issuer,
                    ) != old_binding):
                raise LinkingError('Saved gallery credential changed.')
            hint = saved_offline_hint_path(
                self.profile / 'offline-link.json', upgrade.binding.issuer,
                upgrade.binding.scope,
            )
            remaining = int(upgrade.refresh_deadline - time.monotonic())
            if remaining <= 0:
                raise LinkingError('The approved Photos connection expired.')
            enable_rolling_renewal(session, upgrade.store, hint, upgrade.binding)
            persist_browser_scope_upgrade(
                self.profile / 'offline-link.json', upgrade.store, upgrade.binding,
                upgrade.previous_scope, upgrade.refresh, remaining,
                commit=lambda: connections.commit_scope_upgrade(
                    upgrade.previous_mode, upgrade.previous_target, target,
                ),
            )
        except Exception:
            session.close()
            self.status_message('The approved Photos connection could not be saved safely.')
            return
        old_target_session = upgrade.previous_target.session
        old_target_session.disable_renewal()
        if upgrade.previous_session is not old_target_session:
            upgrade.previous_session.disable_renewal()
        self._receive_catalogue(LinkedPhotos(
            session, upgrade.binding.issuer, upgrade.binding.account_id,
            upgrade.galleries, upgrade.tree_state, owns_session=False,
        ))
    def _catalogue_finished(self, failure: str) -> None:
        """Expose a failed catalogue read without copying transport details.

        Args:
            failure (str): Sanitized exception class, or empty on success.
        Returns:
            None: Restores retry and connection affordances on failure.
        """
        restoring, self._startup_browse = self._startup_browse, False
        authorization_failure, self._authorization_failure = self._authorization_failure, ''
        if self._authorization_active:
            self._authorization_active = False
            self._attempt = None
            self._link_cancel_button.hide()
        if self._selecting_saved_target:
            self._selecting_saved_target = False
            self.work_changed.emit(False)
        if failure:
            self._pending_live_site = None
            self.progress.hide()
            self.link_button.setEnabled(True)
            if self._catalogue_notice is not None and self._catalogue_notice[0] == self._gallery_id:
                self.status_message(self._catalogue_notice[1])
                self._catalogue_notice = None
                return
            self._catalogue_notice = None
            if restoring:
                self._startup_saved_mode = None
                self.link_button.setText(tr(self.language, 'linking_start'))
                self.status.setText(gettext('Could not verify the saved link. Try again.'))
            else:
                self.status.setText(
                    authorization_failure or gettext("Gallery connection is unavailable."),
                )
            self._sync_authorization_dialog()
        elif restoring and self._startup_saved_mode is None:
            self.status.clear()
        elif self._session is not None and self._gallery_id is None:
            self.progress.hide()
        self._sync_connection_state()
    def _choose_gallery(self, item: QTreeWidgetItem | None, _prior: QTreeWidgetItem | None) -> None:
        """Switch to one physical gallery from the verified tree.

        Args:
            item (QTreeWidgetItem | None): Newly selected linked gallery row.
            _prior (QTreeWidgetItem | None): Earlier navigation selection.
        Returns:
            None: Invalidates old reads and requests fresh selected metadata.
        """
        if item is None or self._session is None or not self._session.available():
            return
        gallery_id = int(item.data(0, Qt.ItemDataRole.UserRole))
        if self._tree_path is not None:
            save_photos_tree_preferences(self._tree_path, selected=gallery_id)
        self._select_gallery(gallery_id)
    def _expand_galleries(self) -> None:
        """Expand the complete currently verified gallery hierarchy.

        Returns:
            None: Existing per-account expansion callbacks persist row changes.
        """
        self._gallery_tree.expandAll()
    def _gallery_tree_collapse_all(self) -> None:
        """Collapse the complete currently verified gallery hierarchy.

        Returns:
            None: Existing per-account expansion callbacks persist row changes.
        """
        self._gallery_tree.collapseAll()
    def refresh_gallery_tree(self, *, revalidate_gallery: int | None = None) -> None:
        """Reload the current authorized tree without replacing its grant.

        Args:
            revalidate_gallery (int | None): Exact gallery whose access field changed,
                or None when the request only updates the visible tree.
        Returns:
            None: Publishes only the live session's validated gallery rows.
        """
        session, identity, path = self._session, self._identity, self._tree_path
        if (session is None or identity is None or path is None
                or not session.available()):
            self.status_message("Gallery connection is unavailable.")
            return
        if self._load.busy() and revalidate_gallery is None:
            self.status_message("A gallery request is already in progress.")
            return
        issuer, account = identity
        self._load.submit(lambda cancel, publish: publish(LinkedPhotos(
            session, issuer, account, read_gallery_tree(session, cancel),
            load_photos_tree_preferences(path), owns_session=False,
            revalidate_gallery=revalidate_gallery,
        )))
    def _select_gallery(self, gallery_id: int) -> None:
        """Mount a fresh authorized gallery and its existing local choices.

        Args:
            gallery_id (int): Physical gallery ID in the current confirmed tree.
        Returns:
            None: Reloads the verified catalogue with the saved display format.
        """
        if gallery_id != self._gallery_id:
            self._remember_viewport()
            self._catalogue_notice = None
            self._dismiss_information()
            self._gallery_has_photos = None
        self._gallery_id = gallery_id
        item = self._gallery_tree.currentItem()
        self._gallery_title.setText(
            item.text(0) if item is not None
            and int(item.data(0, Qt.ItemDataRole.UserRole)) == gallery_id else "",
        )
        self._gallery_options.show()
        self._refresh_tree_marker(gallery_id)
        if self._view_path is not None:
            self._newest = load_gallery_photo_order(self._view_path, gallery_id) == "newest"
            self._format = load_gallery_card_format(self._view_path, gallery_id)
            self._sync_format_actions()
            self._sync_order_action()
            self.formatChanged.emit()
            folder_key = self._view_path, gallery_id
            if folder_key != self._folder_split_loaded:
                self._folder_split_loaded = folder_key
                self._folder_format = load_gallery_card_format(self._view_path, gallery_id, folders=True)
                self._folder_newest = load_gallery_photo_order(self._view_path, gallery_id, folders=True) == 'newest'
                self._folder_split_share = load_gallery_folder_split(self._view_path, gallery_id)
                self._folder_modes_unsaved.clear()
                self._folder_split.handle(1).setToolTip(self._text('folder_split_hint'))
        self._sync_folder_section()
        self._sync_capture_markers()
        self.refresh()
    def _sync_order_action(self) -> None:
        """Present chronological order as the compact direction action.

        Returns:
            None: Updates the direction glyph and its accessible description.
        """
        caption = tr(self.language, "order_newest" if self._newest else "order_oldest")
        self._order_button.setText("↓" if self._newest else "↑")
        self._order_button.setAccessibleName(caption)
        self._order_button.setToolTip(caption)
    def _set_gallery_expanded(self, item: QTreeWidgetItem, expanded: bool) -> None:
        """Save explicit expansion through the same per-account tree owner.

        Args:
            item (QTreeWidgetItem): Current authorized parent gallery row.
            expanded (bool): User's explicit expansion choice.
        Returns:
            None: Updates local layout preferences for the exact live account.
        """
        if self._tree_path is None or self._session is None or not self._session.available():
            return
        gallery_id = int(item.data(0, Qt.ItemDataRole.UserRole))
        self._opened_galleries[gallery_id] = expanded
        save_photos_tree_preferences(self._tree_path, opened=self._opened_galleries)
        self._sync_capture_markers()
    def refresh(self) -> None:
        """Replace stale photo metadata while keeping the same gallery binding.

        Returns:
            None: Cancels old pixels before reading the selected catalogue.
        """
        session, gallery = self._session, self._gallery_id
        if session is None or gallery is None:
            self._install_catalogue(self._tree_order)
            return
        for key in tuple(self._photo_info_cache):
            if key[0] == gallery:
                del self._photo_info_cache[key]
        if self._rendered_gallery == gallery:
            self._remember_viewport()
        self._gallery_has_photos = None
        self._install_catalogue(())
        self._sync_folder_section()
        self.status.setText(gettext("Loading photos…"))
        self._start_progress()
        self._load.submit(lambda cancel, publish: publish(
            PhotoCatalogue(read_gallery_photos(session, gallery, cancel)),
        ))
        self.playback.bind(session, gallery)
        self._update_preload_priority()
    def _remember_viewport(self) -> None:
        """Remember the last displayed viewport before refresh or navigation.

        Returns:
            None: Retains only recent gallery positions inside this authorization.
        """
        gallery = self._rendered_gallery
        first = self._requested[0]
        if gallery is None or not 0 <= first < len(self.model.rows):
            return
        self._viewports[gallery] = GalleryViewport(
            self.model.rows[first].identity, self._focused, self._anchor,
            frozenset(self.model.selected),
        )
        self._viewports.move_to_end(gallery)
        if len(self._viewports) > GALLERY_VIEWPORT_HISTORY:
            self._viewports.popitem(last=False)
    def _start_progress(self) -> None:
        """Show indeterminate progress while fetching catalogue metadata.

        Returns:
            None: Keeps photo controls usable while information is pending.
        """
        self.progress.setRange(0, 0)
        self.progress.show()
    def _install_catalogue(self, rows: tuple[PhotoRow, ...]) -> None:
        """Replace the view's identity generation before publishing any new pixels.

        Args:
            rows (tuple[PhotoRow, ...]): Newest-first rows, or an empty replacement.
        Returns:
            None: Resets state and queues the first visible thumbnail window.
        """
        self._cancel_thumbnail_group()
        self._dismiss_photo_context()
        self._dismiss_information()
        self.dismissPhoto()
        self._revision += 1
        self._healthy = False
        self._attempted.clear()
        self._anchor = None
        self._focused = None
        retired = tuple(self._tiles.values())
        self._tiles.clear()
        self._retained.clear()
        self._retained_bytes = 0
        self._wanted = ()
        self._tree_order = rows
        self._rendered_gallery = self._gallery_id if rows else None
        viewport = self._viewports.get(self._gallery_id) if rows else None
        self.model.replace(
            rows if self._newest else rows[::-1],
            selection=viewport.selection if viewport is not None else None,
        )
        for key in retired:
            self.textures.discard(key)
        self.progress.hide()
        self.status.setText(gettext("No photos found.") if not rows and self._local_photos_active else "")
        if rows:
            if viewport is None:
                self.scrollTo.emit(0)
            else:
                visible = self.model.index_by_id
                self._anchor = viewport.anchor if viewport.anchor in visible else None
                first = visible.get(viewport.first, 0)
                focus = visible.get(viewport.focus, first)
                self._focused = self.model.rows[focus].identity
                self.restoreScroll.emit(first, focus)
            self._viewport_timer.start()
    def toggle_order(self) -> None:
        """Switch chronological presentation while retaining selected photo IDs.

        Returns:
            None: Reorders logical rows and restores the selected position.
        """
        self.dismissPhoto()
        self._newest = not self._newest
        if self._view_path is not None and self._gallery_id is not None:
            save_gallery_photo_order(
                self._view_path, self._gallery_id, "newest" if self._newest else "oldest",
            )
        self._sync_order_action()
        rows = self._tree_order
        self.model.replace(rows if self._newest else rows[::-1], preserve=True)
        self.scrollTo.emit(self.model.index_by_id.get(self._anchor, 0))
        self._viewport_timer.start()
    def _apply_viewport(self) -> None:
        """Retain only visible textures and schedule the next bounded group.

        Returns:
            None: Prioritizes current rows and cancels obsolete transport.
        """
        if not self._photos_active or self.detailOpen:
            return
        begin, end = self._requested
        rows = self.model.rows[begin:min(end, begin + VIEWPORT_TEXTURE_LIMIT)]
        self._wanted = tuple(row.identity for row in rows)
        wanted = set(self._wanted)
        self._attempted.intersection_update(wanted)
        departing = self._tiles.keys() - wanted
        # Clear the live QML sources before removing their provider slots.
        self.model.update_textures(dict.fromkeys(departing, ""))
        for identity in departing:
            self.textures.discard(self._tiles.pop(identity))
        texture_changes: dict[int, str] = {}
        for row in rows:
            if row.identity not in self._tiles:
                cached = self._cached_frame(row)
                if cached is not None:
                    texture_changes[row.identity] = self._store_thumbnail(cached)
        self.model.update_textures(texture_changes)
        if self._group and not wanted.intersection(self._group):
            self._cancel_thumbnail_group()
        self.model.set_loading(self._loading_thumbnails.intersection(wanted).difference(self._tiles))
        self._start_group()
    def _cancel_thumbnail_group(self) -> None:
        """Cancel thumbnail ownership and retire the group's loading indicators.

        Returns:
            None: Replaced and inactive work no longer drives scene animation.
        """
        self._thumbnails.cancel()
        self._group = ()
        self._renewing_thumbnail_proofs = False
        self._thumbnail_proof_changed = False
        self._loading_thumbnails.clear()
        self.model.set_loading(set())
    def _start_group(self, *, renew_proofs: bool = False) -> None:
        """Admit one initial or healthy steady group through the shared transport.

        Args:
            renew_proofs (bool): Refresh authorized pages for one stale visible group.
        Returns:
            None: Maintains one thumbnail lane and an independent detail lane.
        """
        if not self._photos_active or self._group or self.detailOpen:
            return
        limit = (self._batch_limit if self._healthy and not renew_proofs
                 else MEDIA_HTTP_BATCH_INITIAL_SIZE)
        wanted = tuple(
            identity for identity in self._wanted
            if identity not in self._tiles and identity not in self._attempted
        )[:limit]
        if not wanted:
            self.progress.hide()
            return
        self._group = wanted
        rows = tuple(self.model.rows[self.model.index_by_id[identity]] for identity in wanted)
        session, gallery = self._session, self._gallery_id
        self._renewing_thumbnail_proofs = renew_proofs
        self._thumbnail_proof_changed = False
        if renew_proofs and session is not None and gallery is not None:
            self._thumbnails.submit(lambda cancel, publish: stream_revalidated_thumbnails(
                rows, session, gallery, cancel, publish,
            ))
        else:
            self._thumbnails.submit(lambda cancel, publish: stream_thumbnails(
                rows, session, gallery, cancel, publish,
            ))
        self._update_thumbnail_progress()
    def _receive_thumbnail(self, value: ThumbnailEvent) -> None:
        """Update visible loading indicators and admit still-authorized pixels.

        Args:
            value (ThumbnailEvent): Renewed page, decoded frame, read activity
                or health from the current serialized lane.
        Returns:
            None: Progressively publishes only current authorized pixels.
        """
        if isinstance(value, ThumbnailGroup):
            self._healthy = value.healthy
            return
        if isinstance(value, ThumbnailProofPage):
            self._apply_thumbnail_proof_page(value)
            return
        if isinstance(value, ThumbnailActivity):
            self._loading_thumbnails = set(value.identities).intersection(self._group)
            self.model.set_loading(self._loading_thumbnails.intersection(self._wanted).difference(self._tiles))
            return
        identity = value.row.identity
        self._loading_thumbnails.discard(identity)
        if identity in self.model.loading_ids:
            self.model.set_loading(self.model.loading_ids.difference((identity,)))
        if identity not in self._wanted or identity not in self._group:
            return
        if value.publication is not None and (
            self._session is None or not self._session.thumbnail_publication_current(value.publication)
        ):
            self._thumbnail_proof_changed = True
            return
        self._retain_frame(value)
        self.model.texture(identity, self._store_thumbnail(value))
        self._update_thumbnail_progress()
    def _apply_thumbnail_proof_page(self, result: ThumbnailProofPage) -> None:
        """Reconcile renewed rows and revoke obsolete pixels through the model.

        Args:
            result (ThumbnailProofPage): Authorized page from the active gallery read lane.
        Returns:
            None: Updates the existing catalogue slots without resetting scroll or selection.
        """
        page = result.page
        session = self._session
        if (page.gallery_id != self._rendered_gallery or session is None
                or not session.can_read_media()):
            return
        photos = {photo.image_id: photo for photo in page.photos}
        replacements: dict[int, PhotoRow] = {}
        retired: set[int] = set()
        for row in self.model.rows:
            identity = row.identity
            if (not isinstance(row.source, LinkedPhoto)
                    or identity <= (result.after_id or 0)
                    or (page.next_after is not None and identity > page.next_after)):
                continue
            photo = photos.get(identity)
            if photo is None:
                retired.add(identity)
                continue
            replacements[identity] = PhotoRow(identity, photo.title, photo)
            # A newly confirmed strong validator can keep the displayed tile.
            # It cannot renew the authority on a separately retained frame.
            if (not (photo.thumbnail_etag or photo.preload_thumbnail_etag)
                    or replace(row.source,
                               thumbnail_verified_at=photo.thumbnail_verified_at,
                               preload_verified_at=photo.preload_verified_at) != photo):
                retired.add(identity)
        self.model.update_textures(dict.fromkeys(retired, ''))
        for identity in retired:
            key = self._tiles.pop(identity, None)
            if key is not None:
                self.textures.discard(key)
        for identity in retired.union(replacements):
            cached = self._retained.pop(identity, None)
            if cached is not None:
                self._retained_bytes -= cached[2]
        if self._inspected_photo is not None and self._inspected_photo.image_id in retired:
            self._dismiss_information()
        for key in tuple(self._photo_info_cache):
            if key[0] == page.gallery_id and key[1] in retired:
                del self._photo_info_cache[key]
        self.model.renew_rows(replacements)
        if replacements:
            self._tree_order = self.model.rows if self._newest else self.model.rows[::-1]
        self._attempted.difference_update(retired.intersection(replacements))
        # A verified disappearance is terminal for this visit to the viewport.
        self._attempted.update(retired.difference(replacements).intersection(self._wanted))
    def _store_thumbnail(self, frame: PhotoFrame) -> str:
        """Prepare a viewport raster for publication without re-decoding.

        Args:
            frame (PhotoFrame): Current decoded and verified gallery result.
        Returns:
            str: Owned provider URL ready for the exact current model role.
        """
        identity = frame.row.identity
        key = f"tile/{self._revision}/{identity}"
        self._tiles[identity] = key
        return self.textures.store(key, frame.image)
    def _retain_frame(self, frame: PhotoFrame) -> None:
        """Retain only permitted decoded thumbnails within the common RAM cap.

        Args:
            frame (PhotoFrame): Current local preview or verified remote image.
        Returns:
            None: Local or independently admitted frames can warm the viewport.
        """
        publication = frame.publication
        if not isinstance(frame.row.source, Path) and (
            publication is None or not publication.ram_eligible
        ):
            return
        identity = frame.row.identity
        previous = self._retained.pop(identity, None)
        if previous is not None:
            self._retained_bytes -= previous[2]
        size = frame.image.sizeInBytes()
        self._retained[identity] = (frame, time.monotonic(), size)
        self._retained_bytes += size
        while (len(self._retained) > MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT
                or self._retained_bytes > MEDIA_THUMBNAIL_CACHE_MAX_BYTES):
            _evicted, (_frame, _stored, removed) = self._retained.popitem(last=False)
            self._retained_bytes -= removed
    def _cached_frame(self, row: PhotoRow) -> PhotoFrame | None:
        """Recheck proof before reusing a frame that left the scene.

        Args:
            row (PhotoRow): Exact current row from the active catalogue.
        Returns:
            PhotoFrame | None: Retained pixels or a miss after stale proof,
                different identity or unchanged cache age expires.
        """
        cached = self._retained.get(row.identity)
        if cached is None:
            return None
        frame, stored, size = cached
        publication = frame.publication
        current = frame.row == row and time.monotonic() - stored < MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS
        if publication is not None:
            current = current and self._session is not None and self._session.thumbnail_publication_current(publication)
        if not current:
            self._retained.pop(row.identity)
            self._retained_bytes -= size
            return None
        self._retained.move_to_end(row.identity)
        return frame
    def _group_finished(self, failure: str) -> None:
        """Advance the viewport or renew its stale thumbnail evidence once.

        Args:
            failure (str): Sanitized failure class, or empty on completion.
        Returns:
            None: Keeps departed rows eligible and bounds failed visible reads.
        """
        missing = set(self._group).intersection(self._wanted).difference(self._tiles)
        proof_changed = (failure == LinkingThumbnailProofChanged.__name__
                         or self._thumbnail_proof_changed)
        already_renewed = self._renewing_thumbnail_proofs
        self._group = ()
        self._renewing_thumbnail_proofs = False
        self._thumbnail_proof_changed = False
        self._loading_thumbnails.clear()
        self.model.set_loading(set())
        if proof_changed and not missing:
            self._start_group()
            return
        session = self._session
        if (proof_changed and not already_renewed and session is not None
                and self._gallery_id is not None and session.can_read_media()):
            self._healthy = False
            QT_PHOTOS_LOGGER.info('Photos renewing thumbnail proofs for visible rows.')
            self._start_group(renew_proofs=True)
            return
        # A group can finish after some of its original rows have left the
        # viewport. They need a new attempt when they enter the view again.
        self._attempted.update(missing)
        if failure or proof_changed:
            self._healthy = False
            self.progress.hide()
            QT_PHOTOS_LOGGER.warning(
                'Photos thumbnail group stopped: failure=%s; visible_missing=%d',
                failure or LinkingThumbnailProofChanged.__name__, len(missing),
            )
            self.status.setText(gettext("Some photos could not be loaded. Use Refresh to retry."))
            self.check_link()
            return
        self._start_group()
    def _update_thumbnail_progress(self) -> None:
        """Count exact currently published visible thumbnails.

        Returns:
            None: Updates the fixed progress strip without covering photos.
        """
        total = len(self._wanted)
        self.progress.setRange(0, total)
        # The viewport retires departed slots before launching another group.
        self.progress.setValue(len(self._tiles))
        self.progress.show()
    def _receive_detail(self, frame: PhotoFrame) -> None:
        """Install a decoded frame only if it belongs to the current selection.

        Args:
            frame (PhotoFrame): Current authorized original or local preview.
        Returns:
            None: Switches the retained URL and metadata without rescaling.
        """
        index = self._detail_index
        if index is None or frame.row != self.model.rows[index]:
            return
        if self._session is not None and not self._session.available():
            return
        self._revision += 1
        key = f"detail/{self._revision}"
        self._detail_url = self.textures.store(key, frame.image)
        self._detail_keys = (*self._detail_keys, key)
        self._detail_title = frame.row.title
        self._detail_display_index = index
        self._detail_hdr_source = frame.hdr_source
        crop = frame.content_crop
        self._detail_crop_rect = None
        if crop is not None:
            left, top, right, bottom = crop
            self._detail_crop_rect = QRectF(
                left, top, right - left, bottom - top,
            )
        if self._detail_crop_rect is not None:
            self._detail_content_crop_enabled = True
            self._hdr_restore_pending = False
            self._hdr_surface.clear()
        else:
            self._detail_content_crop_enabled = False
            self._hdr_restore_pending = False
            self._hdr_surface.present(self._detail_hdr_source, self._hdr_image_rect)
        self._sync_content_crop_action()
        self.detailChanged.emit()
        # Only a newly accepted picture resets the viewport's transform.
        self.zoomFitRequested.emit()
        # Keep the previous provider frame until the next URL is accepted.
        for retired in self._detail_keys[:-2]:
            self.textures.discard(retired)
        self._detail_keys = self._detail_keys[-2:]
    def _detail_finished(self, failure: str) -> None:
        """Expose the requested detail's error independently of the old frame.

        Args:
            failure (str): Sanitized decode or transport outcome.
        Returns:
            None: Hides progress and retains the last valid current frame.
        """
        self.progress.hide()
        self._detail_feedback = gettext("Photo could not be loaded.") if failure else ''
        self._detail_failed = bool(failure)
        self.detailChanged.emit()
        if failure:
            self.check_link()
        if self._playing and self.detailOpen:
            if failure and len(self.model.rows) == 1:
                self.stop_slideshow()
            else:
                self._slideshow.start()
    def check_link(self) -> None:
        """Retire expired authority and schedule bounded approved keepalive work.

        Returns:
            None: Clears all dependent pixels and selection if access is over.
        """
        self.playback.check_authority()
        if self._session is not None and not self._session.available():
            if self._tree_order or self.detailOpen:
                self._install_catalogue(())
            if self._attempt is None or not self._load.busy():
                self.disconnect()
            self.status.setText(gettext("Gallery connection is unavailable."))
        self._schedule_keepalive()
    def _schedule_keepalive(self) -> None:
        """Verify idle session identity on one cancellable lane.

        Returns:
            None: Starts at most one idle round per shared interval and gives
                active capture and Photos authorization precedence.
        """
        now = time.monotonic()
        if (self._authorization_active or self._startup_browse or self._pipeline_waiting
                or self._keepalive.busy() or now < self._keepalive_after):
            return
        due = tuple(site for site in self._live_sites() if site.session.keepalive_due() is True)
        if not due:
            return
        self._keepalive_after = now + LINKING_KEEPALIVE_SECONDS

        def verify(cancelled: Event, publish: Callable[[DesktopLinkedSite], None]) -> None:
            """Check only the already approved account on a read-only route.

            Args:
                cancelled (Event): Current owner lifetime for the idle round.
                publish (Callable[[DesktopLinkedSite], None]): Reports a failed
                    check for the still-exact in-memory site.
            Returns:
                None: Ends after all admitted sites or cancellation.
            """
            for site in due:
                if cancelled.is_set():
                    return
                if site.session.available():
                    try:
                        verify_live_gallery_activity(site)
                    except (RuntimeError, OSError):
                        publish(site)

        self._keepalive.submit(verify)
    def _keepalive_problem(self, site: DesktopLinkedSite) -> None:
        """Report a failed health check only for its current grant.

        Args:
            site (DesktopLinkedSite): Exact original site and account checked.
        Returns:
            None: Withdraws an expired current site or shows the safe retry caption.
        """
        if site.session is not self._session or self._identity != (site.issuer, site.user_id):
            self._live_sites()
            return
        if site.session.available():
            self.status.setText(tr(self.language, 'linking_connection_retry'))
        else:
            self.check_link()
            self.status.setText(tr(self.language, 'linking_reconnect_needed'))
    def toggle_slideshow(self) -> None:
        """Toggle the manual slideshow from the grid or the current detail.

        Returns:
            None: Playback starts from an exact photo after explicit operator
                input and keeps one outstanding detail read.
        """
        if self._playing:
            self.stop_slideshow()
            return
        if not self.model.rows:
            return
        self._playing = True
        if not self._fullscreen:
            self.toggle_fullscreen()
        stop_caption = tr(self.language, "linking_media_slideshow_stop")
        self._slideshow_button.setText("")
        self._slideshow_button.setIcon(qt_icon("pause"))
        self._slideshow_button.setAccessibleName(stop_caption)
        self._slideshow_button.setToolTip(stop_caption)
        index = self._detail_index
        if index is None:
            index = self.model.index_by_id.get(self._anchor, 0)
        self.openPhoto(index)
        self._slideshow_owns_music = self.playback.start_slideshow_music()
    def _presentation_interval(self, seconds: int) -> None:
        """Apply the gallery's allowed interval to explicit slideshow playback.

        Args:
            seconds (int): Validated presentation interval in seconds.
        Returns:
            None: Updates the active timer and permits newly authorized music.
        """
        # QTimer stores milliseconds, while the server's presets are seconds.
        self._slideshow.setInterval(seconds * 1000)
        if self._playing and not self._slideshow_owns_music:
            self._slideshow_owns_music = self.playback.start_slideshow_music()
    def stop_slideshow(self) -> None:
        """Stop the slideshow timer while keeping the currently shown detail.

        Returns:
            None: No automatic selection survives a page or session change.
        """
        self._slideshow.stop()
        if not self._playing:
            return
        self._playing = False
        if self._slideshow_owns_music:
            self._slideshow_owns_music = False
            self.playback.stop()
        start_caption = tr(self.language, "linking_media_slideshow_start")
        self._slideshow_button.setText("")
        self._slideshow_button.setIcon(qt_icon("play"))
        self._slideshow_button.setAccessibleName(start_caption)
        self._slideshow_button.setToolTip(start_caption)
    def _advance_slideshow(self) -> None:
        """Advance after the displayed frame's paced wait with stable wrapping.

        Returns:
            None: Reads one next frame or does nothing after cancellation.
        """
        if self._playing and self._detail_index is not None and self.model.rows:
            self.openPhoto((self._detail_index + 1) % len(self.model.rows))
    def shutdown(self) -> None:
        """Cancel all view work before the single Qt application exits.

        Returns:
            None: Releases ephemeral grants, retires the scene while its
                bindings are still owned and stops result publication.
        """
        self._dismiss_photo_context()
        self._capture_pulse_timer.stop()
        if self._gallery_picker is not None:
            self._gallery_picker.reject()
        parked = self._parked_links
        self._parked_links = ()
        self.disconnect()
        for site in parked:
            if site.session is not self._session:
                site.session.close()
        self._viewport_timer.stop()
        self._preload_timer.stop()
        self.playback.shutdown()
        self._folder_shelf.shutdown()
        for lane in (
            self._load, self._cache_probe, self._thumbnails, self._details, self._maintenance,
            self._information, self._inspector_read, self._keepalive, self._scope_discovery,
        ):
            lane.close()
        self.quick.setSource(QUrl())
