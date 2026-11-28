# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/qt/actions.py
# Module Type: Qt Photos domain mixin
# Purpose: Keep one cohesive Photos interaction domain outside the widget facade.
# Responsibilities: Preserve the QtPhotos lifecycle while grouping its callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Qt Photos behavior mixin declarations."""

from .support import (
    ActionButton,
    Callable,
    CreatedGallery,
    Event,
    FOLDER_KEY_STEP,
    GALLERY_FOLDER_SHARE_SCALE,
    GalleryActionResult,
    GalleryCreateJournal,
    GalleryPreloadWorker,
    INSPECTOR_PREVIEW_PIXELS,
    InformationGroup,
    LINKING_RECOVERY_MAX_FILE_BYTES,
    LinkedPhoto,
    LinkedPhotos,
    LinkedRecoveryActions,
    LinkedRecoveryObservation,
    LinkedUploadTarget,
    LinkingSession,
    MediaInformation,
    MediaInformationFailure,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    PALETTE,
    PHOTOS_INFO_CACHE_ENTRY_LIMIT,
    PHOTOS_INFO_CACHE_FRESH_SECONDS,
    PHOTOS_TREE_WIDTH,
    PHOTO_CONTEXT_PADDING,
    PhotoFrame,
    PhotoRow,
    QT_PHOTOS_LOGGER,
    QApplication,
    QContextMenuEvent,
    QDialog,
    QEvent,
    QInputDialog,
    QKeyEvent,
    QLabel,
    QLayout,
    QMenu,
    QMessageBox,
    QObject,
    QPlainTextEdit,
    QPoint,
    QResizeEvent,
    QTimer,
    QTreeWidgetItem,
    QVBoxLayout,
    QWidget,
    Qt,
    QtGalleryEditor,
    RaisedSurface,
    ReadCancelled,
    UUID,
    UnlinkedSite,
    escape,
    field,
    format_gallery_bytes,
    format_safe_photos_failure,
    gallery_editor_url,
    gettext,
    gettext_noop,
    load_photos_tree_preferences,
    logging,
    photo_information_groups,
    read_gallery_tree,
    read_photo,
    replace,
    thumbnail_quality_caption,
    time,
    tr,
    verify_linked_upload_target,
    webbrowser,
)

class QtPhotosActionsMixin:
    """Own one cohesive set of Qt Photos widget behaviors."""

    def _current_linked_recovery_target(
        self, session: LinkingSession, identity: tuple[str, int],
    ) -> LinkedUploadTarget | None:
        """Return only the exact upload target bound to this live picker.

        Args:
            session (LinkingSession): Live Photos approval represented by the picker.
            identity (tuple[str, int]): Exact canonical issuer and Admin account.
        Returns:
            LinkedUploadTarget | None: Current same-session target with recovery
                authority, or None when mode/session evidence is unavailable.
        """
        connections = self.connections
        if connections is None or connections.recovery is None:
            return None
        try:
            target = connections.active()
        except (RuntimeError, ValueError):
            return None
        if (target is None or target.session is not session
                or (target.issuer, target.user_id) != identity
                or self._session is not session or self._identity != identity
                or not session.available() or not session.can_upload()):
            return None
        return target
    def _check_linked_recovery(
        self, dialog: QDialog, session: LinkingSession,
        identity: tuple[str, int], target: LinkedUploadTarget | None,
        recovery: LinkedRecoveryActions | None, picker_token: UUID,
    ) -> None:
        """Observe one explicitly sized uncertain upload without opening files.

        Args:
            dialog (QDialog): Current chooser that owns the action and feedback.
            session (LinkingSession): Exact live Photos approval at admission.
            identity (tuple[str, int]): Canonical issuer and verified Admin ID.
            target (LinkedUploadTarget | None): Exact target captured by the chooser.
            recovery (LinkedRecoveryActions | None): Shared journal and lease owner.
            picker_token (UUID): Opaque identity of the exact chooser instance.
        Returns:
            None: Queues one bounded observation or leaves the journal untouched.
        """
        if (target is None or recovery is None or self._gallery_picker is not dialog
                or self._current_linked_recovery_target(session, identity) is not target
                or not self._can_disconnect()):
            return
        size, accepted = QInputDialog.getInt(
            dialog, gettext('Check uncertain upload'),
            gettext(
                'Enter the original file size in bytes. No local file is opened. '
                'A match only reports observed bytes and does not confirm an upload.'
            ),
            value=1, minValue=1, maxValue=LINKING_RECOVERY_MAX_FILE_BYTES,
        )
        picker_current = (
            self._gallery_picker is dialog
            and self._current_linked_recovery_target(session, identity) is target
        )
        can_continue = picker_current and self._can_disconnect()
        if not accepted:
            return
        if (not can_continue or type(size) is not int
                or not 0 < size <= LINKING_RECOVERY_MAX_FILE_BYTES):
            return
        self._maintenance_busy = True
        self._action_success = ''
        self._action_failure = gettext(
            'Could not verify the uncertain upload. It remains unresolved.',
        )
        self._refresh_after_action = False
        self._refresh_photos_after_action = None
        self.work_changed.emit(True)
        self.status_message(gettext('Checking the uncertain upload once…'))

        def observe(
            cancelled: Event,
            publish: Callable[
                [str | CreatedGallery | UnlinkedSite | LinkedRecoveryObservation], None,
            ],
        ) -> None:
            """Use the shared no-file-open observer away from Qt's event thread.

            Args:
                cancelled (Event): Exact maintenance-lane cancellation fence.
                publish (Callable[[str | CreatedGallery | UnlinkedSite | LinkedRecoveryObservation], None]):
                    Queued result sink owned by the current Qt lane.
            Returns:
                None: Publishes only a validated owner outcome and marker identity.
            Raises:
                ReadCancelled: The view operation was withdrawn before completion.
                ValueError: The shared recovery owner returned an invalid result.
            """
            if cancelled.is_set():
                raise ReadCancelled
            outcome, marker = recovery.probe(target, size)
            if (outcome not in ('present', 'unknown')
                    or type(marker) is not str or not marker):
                raise ValueError('The recovery owner returned an invalid observation.')
            if cancelled.is_set():
                raise ReadCancelled
            publish(LinkedRecoveryObservation(
                picker_token, target, outcome, marker,
            ))

        self._maintenance.submit(observe)
        self._update_preload_priority()
    def _review_linked_recovery(
        self, dialog: QDialog, session: LinkingSession,
        identity: tuple[str, int], target: LinkedUploadTarget | None,
        recovery: LinkedRecoveryActions | None,
    ) -> None:
        """Record explicit review for only the exact retained marker.

        Args:
            dialog (QDialog): Current chooser that owns the user confirmation.
            session (LinkingSession): Exact live Photos approval at review time.
            identity (tuple[str, int]): Canonical issuer and verified Admin ID.
            target (LinkedUploadTarget | None): Exact target captured by the chooser.
            recovery (LinkedRecoveryActions | None): Shared journal and lease owner.
        Returns:
            None: A default-No prompt records only confirmed review of the same marker.
        """
        if (target is None or recovery is None or self._gallery_picker is not dialog
                or self._current_linked_recovery_target(session, identity) is not target
                or not self._can_disconnect()):
            return
        unavailable = tr(self.language, 'linking_review_unavailable')
        try:
            marker = recovery.review(target, None)
        except Exception:
            marker = None
        if marker is None:
            self.status_message(unavailable)
            return
        caption = tr(self.language, 'linking_review_title')
        prompt = tr(self.language, 'linking_review_prompt').format(gallery=target.title)
        answer = QMessageBox.question(
            dialog, caption, prompt,
            QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No,
            QMessageBox.StandardButton.No,
        )
        picker_current = (
            self._gallery_picker is dialog
            and self._current_linked_recovery_target(session, identity) is target
        )
        can_continue = picker_current and self._can_disconnect()
        if answer != QMessageBox.StandardButton.Yes:
            return
        if not can_continue:
            self.status_message(unavailable)
            return
        try:
            reviewed = recovery.review(target, marker) == marker
        except Exception:
            reviewed = False
        if (self._gallery_picker is not dialog
                or self._current_linked_recovery_target(session, identity) is not target
                or not reviewed):
            self.status_message(unavailable)
            return
        self.status_message(tr(self.language, 'linking_review_done'))
    def select_upload_gallery(self, gallery: int | None = None, *, confirm: bool = True) -> None:
        """Verify a specifically approved gallery before publishing its mode.

        Args:
            gallery (int | None): Exact row chosen in the scoped picker, or the
                active Photos gallery for its existing current-gallery command.
            confirm (bool): Ask for confirmation when no picker action approved it.
        Returns:
            None: Selects the real linked target without starting any transfer.
        """
        connections, session, identity = self.connections, self._session, self._identity
        gallery = self._gallery_id if gallery is None else gallery
        if (connections is None or session is None or gallery is None or identity is None
                or not session.can_upload() or not self._can_disconnect()):
            self.status_message('Gallery connection is unavailable.')
            return
        title = gettext('Select upload gallery')
        if confirm and QMessageBox.question(
            self, title, f'{title} #{gallery}?',
        ) != QMessageBox.StandardButton.Yes:
            return
        self.status_message('Verifying the administrator and selected gallery…')

        def select(cancel: Event) -> None:
            """Verify live account, scope and exact selected remote destination.

            Args:
                cancel (Event): Outstanding operation lifetime fence.
            Returns:
                None: Publishes the mode only after the shared verification barrier.
            """
            target = verify_linked_upload_target(session, *identity, session.approved_scope(), gallery, cancel)
            if not cancel.is_set():
                connections.select(target)

        self._run_gallery_action(select, gettext_noop('Watcher gallery selected.'))
    def create_gallery(self, *, parent_id: int | None = None, choose_parent: bool = True) -> None:
        """Create an explicitly named root or child through the durable journal.

        Args:
            parent_id (int | None): Exact contextual parent, or None for root.
            choose_parent (bool): Offer the generic Settings/connection action's
                root/current selection instead of the contextual target.
        Returns:
            None: Presents a verified result or retains its original intent for retry.
        """
        session, identity = self._session, self._identity
        if session is None or identity is None or not self._can_disconnect():
            self.status_message('Gallery connection is unavailable.')
            return
        if not session.can_create_gallery():
            self.approve_admin()
            return
        caption = gettext('Create gallery')
        if choose_parent:
            parent_id = self._gallery_id
        if choose_parent and parent_id is not None:
            choices = (gettext('Root gallery'), f'{gettext("Parent gallery")} #{parent_id}')
            choice, accepted = QInputDialog.getItem(self, caption, gettext('Parent gallery'), choices, editable=False)
            if not accepted:
                return
            if choice == choices[0]:
                parent_id = None
        retained = self._create_draft
        initial = retained[2] if retained is not None and retained[:2] == (session, parent_id) else ''
        title, accepted = QInputDialog.getText(self, caption, gettext('Gallery title:'), text=initial)
        if (not accepted or not title.strip() or session is not self._session
                or parent_id is not None and not self._owns_gallery(session, parent_id)):
            return
        title = title.strip()
        self._create_draft = session, parent_id, title

        def create(cancel: Event) -> CreatedGallery:
            """Run the exact-request owner and consume only a verified receipt.

            Args:
                cancel (Event): Lifecycle cancellation checked before dispatch.
            Returns:
                CreatedGallery: Verified receipt linked to the exact live session.
            """
            if cancel.is_set():
                raise ReadCancelled
            journal = GalleryCreateJournal(self.profile).for_request(*identity, title, parent_id)
            receipt = journal.create_or_resume(session, *identity, title, parent_id)
            if not cancel.is_set():
                try:
                    journal.acknowledge(*identity, receipt)
                except (OSError, RuntimeError, ValueError):
                    # The authoritative receipt is already recorded by the journal.
                    pass
            return CreatedGallery(session, receipt)

        self._run_gallery_action(
            create, gettext_noop('Gallery created.'), failure_caption=self._text('create_failed'),
        )
    def edit_gallery(
        self, gallery_id: int | None = None, *, field: str | None = None, trigger: QWidget | None = None,
    ) -> None:
        """Edit one explicitly selected gallery field with its shared revision checks.

        Args:
            gallery_id (int | None): Exact contextual gallery, or the current selection.
            field (str | None): Direct title action, or show the explicit field selector.
            trigger (QWidget | None): Visible trigger to use as the dialog anchor.
        Returns:
            None: Publishes only the requested field and refreshes the catalogue.
        """
        session = self._session
        gallery = self._gallery_id if gallery_id is None else gallery_id
        if session is None or gallery is None or not self._owns_gallery(session, gallery):
            self.status_message('Gallery connection is unavailable.')
            return
        if self.busy():
            self.status_message('Wait for the current operation to finish.')
            return
        if field == 'title' and not session.can_edit_gallery_metadata():
            self.approve_admin()
            return
        fields: dict[str, str] = {}
        if session.can_edit_gallery_metadata():
            fields.update({
                gettext('Title'): 'title', gettext('Description'): 'description',
                gettext('NSFW'): 'nsfw', gettext('Listed'): 'listed',
            })
        if session.can_change_gallery_visibility():
            fields[gettext('Visibility')] = 'visibility'
        if not fields:
            self.status_message('Gallery connection is unavailable.')
            return
        if field is None:
            caption, accepted = QInputDialog.getItem(
                self, gettext('Edit gallery'), f'{gettext("Gallery")} #{gallery}',
                list(fields), editable=False,
            )
            if not accepted or not self._owns_gallery(session, gallery):
                return
            field = fields[caption]
        else:
            caption = next((name for name, key in fields.items() if key == field), '')
            if not caption:
                return
        transfer_busy = (
            (self._gallery_rename_busy or self.transfer_busy)
            if field == 'title' else self.transfer_busy
        )
        if self.busy() or transfer_busy():
            self.status_message('Wait for the current operation to finish.')
            return
        if self._gallery_editor is not None:
            self._gallery_editor.reject()
        editor = QtGalleryEditor(
            self, session, gallery, field, caption, self.language,
            lambda: (self._owns_gallery(session, gallery) and not self._maintenance_busy
                     and not self._selecting_saved_target and not transfer_busy()),
        )
        self._gallery_editor = editor
        editor.writing_changed.connect(self.work_changed.emit)
        editor.changed.connect(lambda: self._gallery_edit_saved(gallery, field))

        def forget(_result: int) -> None:
            """Drop the dialog reference after its native lifecycle completes.

            Args:
                _result (int): Qt dialog result already handled by the editor.
            Returns:
                None: Only clears the editor that emitted the result.
            """
            if self._gallery_editor is editor:
                self._gallery_editor = None

        editor.finished.connect(forget)
        anchor = trigger or self._gallery_options
        editor.present(anchor.mapToGlobal(QPoint(0, anchor.height())))
    def _gallery_edit_saved(self, gallery: int, field: str) -> None:
        """Refresh authorized gallery metadata after a verified field change.

        Args:
            gallery (int): Exact gallery whose field was saved.
            field (str): Validated field owned by the native editor.
        Returns:
            None: Preserves layout for text changes and revalidates media after access changes.
        """
        revalidate = gallery if field in ('visibility', 'nsfw', 'listed') else None
        if revalidate is not None and revalidate == self._gallery_id:
            self._remember_viewport()
            self._install_catalogue(())
        self.status.setText(gettext('Gallery changes saved.'))
        self.refresh_gallery_tree(revalidate_gallery=revalidate)
    def _populate_gallery_menu(self) -> None:
        """Show the actions available for the live gallery and current grant.

        Returns:
            None: Rebuilds the native menu before either mouse or keyboard use.
        """
        self._gallery_menu.clear()
        if self._live_sites_menu is not None:
            self._live_sites_menu.deleteLater()
            self._live_sites_menu = None
        sites = self._live_sites()
        if sites:
            site_menu = QMenu(
                tr(self.language, 'linking_live_addresses'), self._gallery_menu,
            )
            self._live_sites_menu = site_menu
            self._gallery_menu.addMenu(site_menu)
            duplicate_issuers = {
                site.issuer for site in sites
                if sum(candidate.issuer == site.issuer for candidate in sites) > 1
            }
            for site in sites:
                label = (
                    f'{site.issuer} ({site.user_id})'
                    if site.issuer in duplicate_issuers else site.issuer
                )
                action = site_menu.addAction(label)
                action.setCheckable(True)
                action.setChecked(
                    site.session is self._session
                    and self._identity == (site.issuer, site.user_id),
                )
                action.triggered.connect(
                    lambda _checked=False, selected=site: self._select_live_site(selected),
                )
            self._gallery_menu.addAction(
                tr(self.language, 'linking_connect_website'), self.link,
            )
            if self._gallery_id is not None:
                self._gallery_menu.addSeparator()
        if self._gallery_id is not None:
            self._fill_gallery_menu(self._gallery_menu, self._gallery_id)
    def _fill_gallery_menu(self, menu: QMenu, gallery: int) -> None:
        """Offer the existing scoped actions for the exact current or child gallery.

        Args:
            menu (QMenu): Native header or contextual menu to populate.
            gallery (int): Exact authorized row that owns these commands.
        Returns:
            None: Commands are bound to the grant and target shown by the menu.
        """
        session = self._session
        if session is None or not self._owns_gallery(session, gallery):
            return
        self._active_gallery_menu = menu
        self._simbrief_menu_entry = None
        menu.addAction(self._text('gallery_info'), lambda: self._request_information(session, gallery))
        if self.start_contextual is not None and self.connections is not None and session.can_upload():
            menu.addAction(self._text('start_watcher'), lambda: self._start_contextual_watcher(session, gallery))
        if not session.can_read_admin_media() or not session.can_edit_gallery_metadata():
            menu.addAction(self._text('approve_admin'), self.approve_admin)
        if session.can_read_media():
            menu.addSeparator()
            if self._preload_worker is not None and self._preload_worker.is_alive():
                menu.addAction(self._text('preload_cancel'), self._stop_preload)
            else:
                menu.addAction(self._text('preload'), lambda: self._start_preload(session, gallery))
                menu.addAction(
                    self._text('preload_thumbnails'),
                    lambda: self._start_preload(session, gallery, thumbnails_only=True),
                )
                pinned = menu.addAction(self._text('keep_preloaded'))
                pinned.setCheckable(True)
                pinned.setChecked(session.gallery_pinned(gallery))
                pinned.setToolTip(self._text('keep_preloaded_hint'))
                pinned.triggered.connect(lambda keep: self._start_preload(session, gallery, pinned=keep))
        if session.can_create_gallery():
            menu.addAction(self._text('create_child'), lambda: self.create_gallery(
                parent_id=gallery, choose_parent=False,
            ) if self._owns_gallery(session, gallery) else None)
        else:
            menu.addAction(self._text('create_child'), self.approve_admin)
        if session.can_edit_gallery_metadata():
            for caption, field in (
                (self._text('edit_title'), 'title'), (self._text('edit_description'), 'description'),
                (gettext('NSFW'), 'nsfw'), (gettext('Listed'), 'listed'),
            ):
                menu.addAction(caption, lambda _checked=False, key=field: self.edit_gallery(gallery, field=key)
                               if self._owns_gallery(session, gallery) else None)
        if session.can_change_gallery_visibility():
            menu.addAction(self._text('visibility'), lambda: self.edit_gallery(gallery, field='visibility')
                           if self._owns_gallery(session, gallery) else None)
        menu.addAction(self._text('edit_gallery'), lambda: self._open_web_gallery_editor(session, gallery))
        if self.simbrief_action is not None and self.simbrief_available is not None:
            self._simbrief_menu_entry = menu.addAction(
                self._text('simbrief'), lambda: self._open_context_simbrief(session, gallery),
            )
            self.update_simbrief_context(self.simbrief_available())
            if not self._gallery_context_demand:
                self._gallery_context_demand = True
                self._sync_gallery_context_demand()
    def _sync_gallery_context_demand(self) -> None:
        """Combine grid and menu visibility requests for simulator observation.

        Returns:
            None: Keeps presence polling demanded until both contexts end.
        """
        demanded = self._gallery_context_demand or self._simbrief_grid_demand
        if self._gallery_presence_demand != demanded:
            self._gallery_presence_demand = demanded
            self.gallery_context_changed.emit(demanded)
    def update_simbrief_context(self, available: bool) -> None:
        """Toggle the host SimBrief action as its cached simulator status changes.

        Args:
            available (bool): The shared GamePresence observer detects a simulator.
        Returns:
            None: Refreshes the quick action and any open gallery menu.
        """
        if self._simbrief_menu_entry is not None:
            self._simbrief_menu_entry.setVisible(available)
        self._sync_simbrief_grid_action()
    def set_simbrief_route(self, route: str) -> None:
        """Show the capture owner's current bound SimBrief route in Photos.

        Args:
            route (str): Current CaptureStatus route, or empty when detached.
        Returns:
            None: Updates the plain-text success caption and grid visibility.
        """
        if self._simbrief_route == route:
            return
        self._simbrief_route = route
        caption = (
            tr(self.language, 'simbrief_ready') + ' · ' + route
            if route else ''
        )
        self._simbrief_status.setText(caption)
        self._simbrief_status.setAccessibleName(caption)
        self._simbrief_status.setToolTip(caption)
        self._sync_simbrief_grid_action()
    def _sync_simbrief_grid_action(self) -> None:
        """Show the quick action only for the current eligible linked photo grid.

        Returns:
            None: Demands cached simulator presence while the linked gallery grid
                is visible, or releases that demand when its context ends.
        """
        session, gallery = self._session, self._gallery_id
        available = self.simbrief_available
        capture_matches = (
            self._capture_target is None
            or (self._identity is not None
                and self._capture_target == (*self._identity, gallery))
        )
        eligible = bool(
            self._photos_active and not self._local_photos_active
            and self.isVisible() and self._lightbox_layout_restore is None
            and session is not None
            and gallery is not None and session.can_upload()
            and self._owns_gallery(session, gallery) and capture_matches
            and available is not None
            and self.load_simbrief_action is not None
        )
        action_available = bool(
            eligible and available is not None and available(),
        )
        self._load_simbrief_button.setVisible(action_available)
        has_route = bool(eligible and self._simbrief_route)
        self._simbrief_status.setVisible(has_route)
        self._simbrief_row.setVisible(action_available or has_route)
        if self._simbrief_grid_demand != eligible:
            self._simbrief_grid_demand = eligible
            self._sync_gallery_context_demand()
    def _load_header_simbrief(self) -> None:
        """Load a SimBrief plan through the current gallery's capture owner.

        Returns:
            None: Revalidates the displayed grant before delegating the load.
        """
        self._sync_simbrief_grid_action()
        action = self.load_simbrief_action
        if action is not None and self._load_simbrief_button.isVisible():
            action()
    def _open_context_simbrief(self, session: LinkingSession, gallery: int) -> None:
        """Delegate the current menu action to the existing Flight prompt.

        Args:
            session (LinkingSession): Exact displayed gallery's current grant.
            gallery (int): Exact gallery supplying the explicit context action.
        Returns:
            None: Opens the operator prompt only with current simulator and gallery ownership.
        """
        action, available = self.simbrief_action, self.simbrief_available
        if (action is not None and available is not None and available()
                and self.isVisible() and self._owns_gallery(session, gallery)):
            action()
    def _end_gallery_context(self, menu: QMenu) -> None:
        """Release the current menu and retire visibility demand after activation.

        Args:
            menu (QMenu): Exact menu that is closing or releasing its actions.
        Returns:
            None: Allows an explicit native menu selection to complete that event first.
        """
        if menu is self._active_gallery_menu:
            self._active_gallery_menu = None
            self._simbrief_menu_entry = None
            QTimer.singleShot(0, self, self._clear_gallery_context_demand)
    def _clear_gallery_context_demand(self) -> None:
        """Suspend optional simulator polling once its gallery context is gone.

        Returns:
            None: A replacement menu in the same event loop keeps its visibility demand.
        """
        if self._active_gallery_menu is None and self._gallery_context_demand:
            self._gallery_context_demand = False
            self._sync_gallery_context_demand()
    def _open_web_gallery_editor(self, session: LinkingSession, gallery: int) -> None:
        """Open the reference browser editor using only the approved installation.

        Args:
            session (LinkingSession): Exact live site session from the menu.
            gallery (int): Exact current or contextual linked gallery.
        Returns:
            None: The browser owns its own authentication and receives no gallery credentials.
        """
        if self._identity is None or not self._owns_gallery(session, gallery):
            return
        url = gallery_editor_url(session, self._identity[0], gallery)
        if url is None:
            return
        try:
            opened = webbrowser.open(url)
        except Exception:
            opened = False
        if not opened:
            self.status_message(self._text('edit_gallery_failed'))
    def _owns_gallery(self, session: LinkingSession, gallery: int) -> bool:
        """Validate contextual actions against the exact live gallery catalogue.

        Args:
            session (LinkingSession): Grant that supplied the action's target.
            gallery (int): Exact linked gallery ID from the active view.
        Returns:
            bool: True while that grant and gallery are still displayed in the tree.
        """
        return (session is self._session and session.available()
                and (gallery == self._gallery_id or gallery in self._gallery_items))
    def _open_folder(self, gallery: int) -> None:
        """Navigate an authorized child through the existing tree selection handler.

        Args:
            gallery (int): Exact direct child selected on a folder card.
        Returns:
            None: Expands the parent and selects the child through normal navigation.
        """
        item = self._gallery_items.get(gallery)
        if item is not None and self._session is not None and self._session.available():
            parent = item.parent()
            if parent is not None:
                parent.setExpanded(True)
            self._gallery_tree.setCurrentItem(item)
            self._gallery_tree.scrollToItem(item)
    def _folder_context_menu(self, gallery: int, anchor: QPoint) -> None:
        """Anchor a real gallery menu on an authorized child-folder card.

        Args:
            gallery (int): Child gallery under the pointer.
            anchor (QPoint): Global logical pointer position.
        Returns:
            None: Presents an ephemeral menu without changing the current gallery.
        """
        if gallery not in self._gallery_items:
            return
        menu = QMenu(self._folder_shelf)
        menu.aboutToHide.connect(lambda: self._end_gallery_context(menu))
        menu.aboutToHide.connect(menu.deleteLater)
        self._fill_gallery_menu(menu, gallery)
        if menu.isEmpty():
            menu.deleteLater()
        else:
            menu.popup(anchor)
    def _tree_context_menu(self, pos: QPoint) -> None:
        """Expose gallery or root creation commands at the tree pointer.

        Args:
            pos (QPoint): Viewport-relative location of the context request.
        Returns:
            None: Opens only the corresponding native scoped menu.
        """
        item = self._gallery_tree.itemAt(pos)
        anchor = self._gallery_tree.viewport().mapToGlobal(pos)
        if item is not None:
            self._folder_context_menu(int(item.data(0, Qt.ItemDataRole.UserRole)), anchor)
        elif self._session is not None and self._session.can_create_gallery():
            menu = QMenu(self._gallery_tree)
            menu.aboutToHide.connect(menu.deleteLater)
            menu.addAction(self._text('create_root'), lambda: self.create_gallery(choose_parent=False))
            menu.popup(anchor)
    def _start_contextual_watcher(self, session: LinkingSession, gallery: int) -> None:
        """Verify one exact photo destination and start its scoped Watcher.

        Args:
            session (LinkingSession): Existing browser-approved Photos session.
            gallery (int): Exact physical gallery shown in the current menu.
        Returns:
            None: Starts work after explicit consent through the shared queue owner.
        """
        start, connections, identity = self.start_contextual, self.connections, self._identity
        if (start is None or connections is None or identity is None
                or not self._owns_gallery(session, gallery)
                or not session.can_upload() or not self._can_disconnect()):
            return
        caption = self._text('start_watcher')
        if QMessageBox.question(self, caption, f'{caption} #{gallery}?') != QMessageBox.StandardButton.Yes:
            return
        if not self._owns_gallery(session, gallery):
            return

        def launch(cancelled: Event) -> None:
            """Transfer the registered key only after exact target admission.

            Args:
                cancelled (Event): Current explicit gallery action lifetime.
            Returns:
                None: The capture owns the key or the unused key is retired.
            """
            target = verify_linked_upload_target(session, *identity, session.approved_scope(), gallery, cancelled)
            key = session.open_watch_key(gallery, cancelled=cancelled)
            try:
                contextual = replace(target, watch_key=key)
                if cancelled.is_set():
                    raise ReadCancelled
                connections.select(target)
                start(contextual)
                key = None
            finally:
                if key is not None:
                    session.close_watch_key(gallery, key)

        self._run_gallery_action(launch, gettext_noop('Watcher preparation started.'))
    def _start_preload(
        self, session: LinkingSession, gallery: int, *,
        pinned: bool | None = None, thumbnails_only: bool = False,
    ) -> None:
        """Start one Photos cache operation through the established worker.

        Args:
            session (LinkingSession): Current media grant for the selected gallery.
            gallery (int): Exact physical gallery selected when opening the menu.
            pinned (bool | None): Optional explicit retention change.
            thumbnails_only (bool): Limit reads and accounting to thumbnails.
        Returns:
            None: Displays the current operation or leaves its existing owner active.
        """
        if (not self._owns_gallery(session, gallery)
                or not session.can_read_media() or self.busy()
                or (self._preload_worker is not None and self._preload_worker.is_alive())):
            return
        self._preload_session = session
        self._preload_unpin = pinned is False
        worker = GalleryPreloadWorker(
            session, gallery, pinned=pinned, download=pinned is not False,
            thumbnails_only=thumbnails_only,
            yield_to_foreground=self._preload_foreground.is_set,
            foreground_reason=lambda: self._preload_wait_reason,
        )
        self._preload_worker = worker
        self._update_preload_priority()
        self._preload_stop.setEnabled(True)
        worker.start()
        self._preload_timer.start()
        self._poll_preload()
    def _stop_preload(self) -> None:
        """Cancel a live preload or dismiss its completed status.

        Returns:
            None: Respects the current worker's cancellation and finish order.
        """
        worker = self._preload_worker
        if worker is None or not worker.is_alive():
            self._preload_row.hide()
        else:
            worker.stop()
            self._preload_stop.setEnabled(False)
    def _retire_preload(self) -> None:
        """Withdraw cache work and status for the former media grant.

        Returns:
            None: The original worker cooperatively releases its transport.
        """
        if self._preload_worker is not None:
            self._preload_worker.stop()
        self._preload_session = None
        self._preload_row.hide()
    def set_upload_pipeline_waiting(self, waiting: bool) -> None:
        """Give the same live upload pipeline priority over gallery preloading.

        Args:
            waiting (bool): Current pending Watcher queue or recovery status.
        Returns:
            None: Updates the media transport's existing priority fence.
        """
        self._pipeline_waiting = waiting
        for site in self._live_sites():
            site.session.set_upload_pipeline_waiting(waiting)
        self._update_preload_priority()
    def _update_preload_priority(self) -> None:
        """Publish foreground demand without allowing worker-side widget reads.

        Returns:
            None: Only explicit foreground requests preempt the cache worker.
        """
        detail = self._details.busy()
        busy = (
            detail or self._load.busy() or self._information.busy()
            or self._inspector_read.busy() or self.busy()
        )
        self._preload_wait_reason = 'browsing' if detail else 'metadata' if busy else ''
        if self._pipeline_waiting or busy:
            self._preload_foreground.set()
        else:
            self._preload_foreground.clear()
    def _poll_preload(self) -> None:
        """Display the shared retained-cache snapshot for its authorized session.

        Returns:
            None: Stops observation when the operation has completely settled.
        """
        worker = self._preload_worker
        if worker is None:
            self._preload_timer.stop()
            return
        active = worker.is_alive()
        if not active:
            self._preload_timer.stop()
        if self._preload_session is not self._session or self._session is None:
            return
        self._update_preload_priority()
        status = worker.snapshot()
        if (not active and status.phase in ('complete', 'partial', 'cancelled', 'error')
                and status.gallery_id in self._gallery_items):
            if status.pinned in (True, False):
                self._gallery_pins[status.gallery_id] = status.pinned
            if status.cache_state != 'unknown' and not status.thumbnails_only:
                self._cache_states[status.gallery_id] = status.cache_state
            self._refresh_tree_marker(status.gallery_id)
        if not active and status.gallery_id != self._gallery_id:
            self._preload_row.hide()
            return
        phase = 'unpinned' if self._preload_unpin and status.phase == 'complete' else status.phase
        if status.phase == 'yielding' and status.wait_reason == 'metadata':
            phase = 'wait_metadata'
        parts = [self._text('preload_' + phase)]
        if status.retry_after_seconds is not None:
            parts.append(self._text('preload_retry_countdown').format(seconds=status.retry_after_seconds))
        if status.total is not None:
            if status.thumbnails_only and active:
                parts.append(self._text('preload_thumbnail_counts').format(
                    done=status.completed, total=status.total, hits=status.current_hits, stored=status.stored,
                ))
            else:
                parts.append(self._text('preload_counts').format(
                    done=status.completed, total=status.total, cached=status.cached, skipped=status.skipped,
                ))
        if status.reason:
            parts.append(self._text('preload_reason_' + status.reason))
        self._preload_label.setText(' · '.join(parts))
        self._preload_row.show()
        if not active:
            self._preload_stop.setEnabled(True)
        self._preload_stop.setText(self._text('preload_cancel' if active else 'preload_close_status'))
    def _open_photo_context(
        self, title: str, anchor: QPoint, actions: list[tuple[str, Callable[[], None]]],
        valid: Callable[[], bool], *, destructive_caption: str | None = None,
    ) -> None:
        """Mount the reference compact contextual card within Photos.

        Args:
            title (str): Localized heading for this target.
            anchor (QPoint): Trigger position in the visible Quick scene.
            actions (list[tuple[str, Callable[[], None]]]): Ordered visible captions and explicit commands.
            valid (Callable[[], bool]): Current exact target and grant check before any action.
            destructive_caption (str | None): Permanent action styled with the danger color.
                Confirmation initially focuses its safe action when supplied.
        Returns:
            None: Clamps an inline surface and gives it native keyboard focus.
        """
        self._dismiss_photo_context()
        card = RaisedSurface(self, 'photosPhotoContext')
        card.setAccessibleName(title)
        card.setFocusPolicy(Qt.FocusPolicy.StrongFocus)
        card.setStyleSheet(
            'QFrame#photosPhotoContext QPushButton {'
            'text-align: left; background: transparent; padding: 4px 5px; '
            'border: 1px solid transparent; border-radius: 3px; '
            '} QFrame#photosPhotoContext QPushButton:hover {'
            f'background: {PALETTE["surface_alt"]}; '
            '} QFrame#photosPhotoContext QPushButton:focus {'
            f'border-color: {PALETTE["focus"]}; '
            '} QFrame#photosPhotoContext QPushButton[destructive="true"] {'
            f'color: {PALETTE["danger"]}; }}'
        )
        layout = QVBoxLayout(card)
        inset = PHOTO_CONTEXT_PADDING
        layout.setContentsMargins(inset, inset, inset, inset)
        layout.setSizeConstraint(QLayout.SizeConstraint.SetFixedSize)
        layout.setSpacing(0)
        heading = QLabel(title)
        heading.setTextFormat(Qt.TextFormat.PlainText)
        if destructive_caption is not None:
            heading.setWordWrap(True)
            heading.setFixedWidth(min(PHOTOS_TREE_WIDTH, max(1, self.width() - inset * 2)))
        layout.addWidget(heading)

        def invoke(action: Callable[[], None]) -> None:
            """Dispatch the requested action only while its target still owns the card.

            Args:
                action (Callable[[], None]): Explicitly selected Photos command.
            Returns:
                None: Restores grid focus and refuses stale actions.
            """
            allowed = valid()
            self._dismiss_photo_context(restore_focus=True)
            if allowed:
                action()

        focus: QWidget = card
        for caption, action in actions:
            button = ActionButton(caption, lambda command=action: invoke(command))
            if caption == destructive_caption:
                button.setProperty('destructive', True)
            elif destructive_caption is not None and focus is card:
                focus = button
            layout.addWidget(button)
        self._photo_context = card
        self._photo_context_anchor = anchor
        card.ensurePolished()
        card.adjustSize()
        self._position_photo_context()
        card.show()
        card.raise_()
        focus.setFocus(Qt.FocusReason.PopupFocusReason)
        application = QApplication.instance()
        if isinstance(application, QApplication):
            application.installEventFilter(self)
            application.focusChanged.connect(self._check_photo_context_focus)
    def _position_photo_context(self) -> None:
        """Keep the contextual card within the visible Photos workspace.

        Returns:
            None: Clamps the scene's logical trigger before any native painting.
        """
        card = self._photo_context
        if card is not None:
            point = self.quick.mapTo(self, self._photo_context_anchor)
            card.move(
                max(0, min(point.x(), self.width() - card.width())),
                max(0, min(point.y(), self.height() - card.height())),
            )
    def _check_photo_context_focus(self, _old: QWidget | None, current: QWidget | None) -> None:
        """Close the contextual card when native focus leaves its actions.

        Args:
            _old (QWidget | None): Previous focused widget, if any.
            current (QWidget | None): New focused widget, if any.
        Returns:
            None: The operator's newly focused control retains its focus.
        """
        card = self._photo_context
        if card is not None and (current is None or (
            current is not card and not card.isAncestorOf(current)
        )):
            self._dismiss_photo_context()
    def _dismiss_photo_context(self, *, restore_focus: bool = False) -> None:
        """Retire the contextual surface and its temporary event connections.

        Args:
            restore_focus (bool): Return keyboard focus to Photos after explicit dismissal.
        Returns:
            None: Releases only the contextual widget, keeping selection and textures.
        """
        card = self._photo_context
        if card is None:
            return
        self._photo_context = None
        application = QApplication.instance()
        if isinstance(application, QApplication):
            application.removeEventFilter(self)
            application.focusChanged.disconnect(self._check_photo_context_focus)
        card.hide()
        card.deleteLater()
        if restore_focus:
            self.quick.setFocus(Qt.FocusReason.PopupFocusReason)
    def _confirm_photo_delete(
        self, session: LinkingSession, gallery: int, image_ids: tuple[int, ...], anchor: QPoint,
    ) -> None:
        """Confirm the exact permanent deletion, then send it at most once.

        Args:
            session (LinkingSession): Current scoped gallery connection.
            gallery (int): Exact physical owner of the selected photo IDs.
            image_ids (tuple[int, ...]): Selected direct photo IDs in visible order.
            anchor (QPoint): Original selected tile position in the Quick scene.
        Returns:
            None: Submits only after confirmation and refreshes either outcome.
        """
        def current() -> bool:
            """Recheck the authorized photo set before executing an inline action.

            Returns:
                bool: The same gallery still owns all IDs and permits deletion.
            """
            return (
                self.isVisible() and not self.detailOpen
                and gallery == self._rendered_gallery == self._gallery_id
                and self._owns_gallery(session, gallery) and session.can_delete_admin_media()
                and 0 < len(image_ids) <= NATIVE_MEDIA_DELETE_BATCH_MAX
                and all(image_id in self.model.index_by_id for image_id in image_ids)
                and self._can_disconnect()
            )

        if not current():
            return
        item = self._gallery_tree.currentItem()
        name = item.text(0) if item is not None else str(gallery)
        name = ' '.join(''.join(char if char.isprintable() else ' ' for char in name).split())

        def remove() -> None:
            """Dispatch one irreversible delete through the existing mutation owner.

            Returns:
                None: Refreshes the precise gallery once the response settles.
            """
            self._run_gallery_action(
                lambda cancelled: session.delete_media_photos(gallery, image_ids, cancelled),
                self._text('delete_complete'),
                refresh_photos=(gallery, self._text('delete_uncertain')),
            )

        caption = self._text('delete_permanently')
        self._open_photo_context(
            self._text('delete_confirm').format(count=len(image_ids), gallery=name), anchor,
            [(self._text('cancel'), lambda: None), (caption, remove)], current,
            destructive_caption=caption,
        )
    def _change_photo_visibility(
        self, session: LinkingSession, gallery: int, photo: LinkedPhoto, target: str,
    ) -> None:
        """Send one explicitly selected visibility change with its observed state.

        Args:
            session (LinkingSession): Active Admin Photos session.
            gallery (int): Exact physical owner of the displayed photo.
            photo (LinkedPhoto): Current descriptor with prior visibility.
            target (str): Explicit target visibility chosen from its action menu.
        Returns:
            None: Requests a passive refresh after the one-shot write settles.
        """
        index = self.model.index_by_id.get(photo.image_id)
        if (session is not self._session or gallery != self._rendered_gallery
                or index is None or self.model.rows[index].source is not photo
                or not session.can_change_photo_visibility()):
            return
        self._run_gallery_action(
            lambda cancel: session.change_photo_visibility(gallery, photo, target, cancel),
            self._text('saved'), refresh_photos=(gallery, self._text('visibility_uncertain')),
        )
    def _request_information(
        self, session: LinkingSession, gallery: int, photo_id: int | None = None,
    ) -> None:
        """Read metadata and, for the inspector, a bounded authorized thumbnail.

        Args:
            session (LinkingSession): Current exact media session.
            gallery (int): Physical gallery whose details are requested.
            photo_id (int | None): Exact selected direct photo, or None for gallery details.
        Returns:
            None: Displays current metadata and schedules any outstanding
                passive metadata or preview reads independently.
        """
        if not self._owns_gallery(session, gallery) or photo_id is not None and gallery != self._gallery_id:
            return
        in_inspector = photo_id is not None and not self.detailOpen
        row: PhotoRow | None = None
        photo: LinkedPhoto | None = None
        if in_inspector and photo_id is not None:
            index = self.model.index_by_id.get(photo_id)
            row = self.model.rows[index] if index is not None else None
            if row is None or not isinstance(row.source, LinkedPhoto):
                return
            photo = row.source
        self._dismiss_information(keep_pane=in_inspector)
        if row is not None and photo is not None:
            self._inspected_photo = photo
            self._set_inspector_summary(photo, self._text('working'))
            self._inspector_preview.present(None, self._text('working'))
            if self._inspector_panel.isHidden():
                self._inspector_panel.reveal()
                self._split.refresh()
                self.restore_inspector_width(self._inspector_width)
            self._inspector_read.submit(lambda cancelled, publish: publish(
                read_photo(row, session, gallery, cancelled, thumbnail=True,
                           thumbnail_size=INSPECTOR_PREVIEW_PIXELS),
            ))
            self._update_preload_priority()
            key = (gallery, photo.image_id, session.can_read_admin_media())
            cached = self._photo_info_cache.get(key)
            if cached is not None:
                stored, result = cached
                if (result.session is session and session.can_read_media()
                        and 0 <= time.monotonic() - stored < PHOTOS_INFO_CACHE_FRESH_SECONDS):
                    self._photo_info_cache.move_to_end(key)
                    self._show_information(result, remember=False)
                    return
                self._photo_info_cache.pop(key)

        def read(
            cancelled: Event,
            publish: Callable[[MediaInformation | MediaInformationFailure], None],
        ) -> None:
            """Read the authorized catalogue's exact media information.

            Args:
                cancelled (Event): Current menu or detail lifetime.
                publish (Callable[[MediaInformation | MediaInformationFailure], None]):
                    Queued receiver for current metadata or a sanitized failure.
            Returns:
                None: Publishes validated fields or a privacy-filtered diagnostic.
            """
            try:
                if photo_id is None:
                    fields = session.media_gallery_details(gallery, cancelled=cancelled)
                else:
                    fields = session.media_photo_info(gallery, photo_id, cancelled=cancelled)
            except Exception as error:
                if not cancelled.is_set():
                    kind = 'catalogue' if photo_id is None else 'photo_info'
                    summary = format_safe_photos_failure('media-fetch', kind, error)
                    publish(MediaInformationFailure(summary))
                raise
            publish(MediaInformation(session, gallery, photo_id, fields, session.can_read_admin_media()))

        if not in_inspector:
            self.status_message(self._text('working'))
        self._information.submit(read)
        self._update_preload_priority()
    def _show_inspector_preview(self, frame: PhotoFrame) -> None:
        """Publish a preview only while its exact selection and grant are current.

        Args:
            frame (PhotoFrame): Bounded raster delivered by the current preview lane.
        Returns:
            None: Updates the preview without modifying the grid's retained textures.
        """
        session, gallery = self._session, self._gallery_id
        if (self._inspected_photo is frame.row.source and session is not None
                and gallery is not None and self._owns_gallery(session, gallery)
                and session.can_read_media()):
            self._inspector_preview.present(frame.image)
    def _inspector_preview_finished(self, _failure: str) -> None:
        """Complete the inspector preview independently of its metadata.

        Args:
            _failure (str): Sanitized read outcome from the preview lane.
        Returns:
            None: Shows an unavailable status if no current raster was published.
        """
        if self._inspected_photo is not None and self._inspector_preview.image.isNull():
            self._inspector_preview.present(None, self._text('preview_unavailable'))
        self._update_preload_priority()
    def _information_for_detail(self) -> None:
        """Request metadata for the exact displayed Lightbox rendition.

        Returns:
            None: Skips local previews and any detail that has already closed.
        """
        index, session, gallery = self._detail_display_index, self._session, self._rendered_gallery
        if (index is not None and 0 <= index < len(self.model.rows)
                and session is not None and gallery is not None and self._owns_gallery(session, gallery)):
            photo = self.model.rows[index].source
            if isinstance(photo, LinkedPhoto):
                self._request_information(session, gallery, photo.image_id)
    def _show_information(
        self, result: MediaInformation | MediaInformationFailure, *, remember: bool = True,
    ) -> None:
        """Display current authorized metadata as selectable details.

        Args:
            result (MediaInformation | MediaInformationFailure): Current authorized
                metadata or the operation's privacy-filtered diagnostic.
            remember (bool): Retain a newly accepted inspector result. Reuse
                never extends its original validation interval.
        Returns:
            None: Shows current metadata or retains its failure in Activity.
        """
        if isinstance(result, MediaInformationFailure):
            QT_PHOTOS_LOGGER.warning('Photos failure %s', result.summary)
            self.diagnostic_reported.emit(result.summary)
            return
        if (not self._owns_gallery(result.session, result.gallery_id)
                or result.photo_id is not None and self._gallery_id != result.gallery_id):
            return
        fields = result.fields
        if not isinstance(fields, dict):
            self._information_finished('InvalidMetadata')
            return
        groups: list[InformationGroup]
        if result.photo_id is None:
            title = self._text('gallery_info')
            groups = [
                ('photos_workspace_group_inventory', [
                    ('photos_workspace_field_photo_count', fields.get('photo_count')),
                    ('photos_workspace_field_file_count', fields.get('file_count')),
                    ('photos_workspace_field_size', fields.get('size')),
                ]),
                ('photos_workspace_group_gallery', [
                    (f'photos_workspace_field_{key}', fields.get(key))
                    for key in ('title', 'description', 'child_count', 'created_at', 'updated_at')
                ]),
                ('photos_workspace_group_access', [
                    (f'photos_workspace_field_{key}', fields.get(key))
                    for key in ('visibility', 'nsfw', 'listed')
                ]),
                ('photos_workspace_group_thumbnail', [
                    ('photos_workspace_field_thumbnail_settings', thumbnail_quality_caption(fields)),
                ]),
            ]
        else:
            title = self._text('photo_info')
            if not result.session.can_read_media():
                return
            location_allowed = result.session.can_read_admin_media()
            groups = photo_information_groups(
                fields, include_location=result.include_location and location_allowed,
            )
        if result.photo_id is not None and not self.detailOpen:
            photo = self._inspected_photo
            if photo is not None and photo.image_id == result.photo_id:
                if remember and result.include_location == location_allowed:
                    key = (result.gallery_id, result.photo_id, result.include_location)
                    scoped_fields = {name: value for name, value in fields.items()
                                     if location_allowed or not name.startswith('gps_')}
                    self._photo_info_cache[key] = (
                        time.monotonic(), replace(result, fields=scoped_fields),
                    )
                    self._photo_info_cache.move_to_end(key)
                    if len(self._photo_info_cache) > PHOTOS_INFO_CACHE_ENTRY_LIMIT:
                        self._photo_info_cache.popitem(last=False)
                self._set_inspector_summary(
                    photo, self._text('photo_info_ready'),
                    size_in_details=fields.get('size') is not None,
                )
                self._set_inspector_metadata(groups)
            return
        dialog = QDialog(self)
        dialog.setWindowTitle(title)
        layout = QVBoxLayout(dialog)
        details = QPlainTextEdit(self._information_rows(groups))
        details.setReadOnly(True)
        details.setAccessibleName(title)
        layout.addWidget(details)
        layout.addWidget(ActionButton(self._text('close'), dialog.close))
        self._information_dialog = dialog
        dialog.open()
    def _set_inspector_summary(
        self, photo: LinkedPhoto, status: str, *, size_in_details: bool = False,
    ) -> None:
        """Show the immediate descriptor and the current metadata read status.

        Args:
            photo (LinkedPhoto): Exact original authorized descriptor.
            status (str): Localized working, ready or partial-data notice.
            size_in_details (bool): Whether the structured rows show the original size.
        Returns:
            None: Shows status and retains the descriptor size until details include it.
        """
        title = ' '.join(''.join(char if char.isprintable() else ' ' for char in photo.title).split())
        title = title or self._text('photo_info')
        self._inspector_heading.setAccessibleName(title)
        self._inspector_heading.setToolTip(f'<span>{escape(title)}</span>')
        self._layout_inspector_heading()
        if not size_in_details:
            size = (self._format_information('photos_workspace_field_size', photo.size)
                    if photo.size is not None else self._text('unknown'))
            status = f"{self._text('field_size')}: {size}\n{status}"
        self._inspector_summary.setText(status)
    def _layout_inspector_heading(self) -> None:
        """Fit the file title into the header while retaining its full name.

        Returns:
            None: Shortens only the visible caption using the current font and width.
        """
        label = self._inspector_heading
        label.setText(label.fontMetrics().elidedText(
            label.accessibleName(), Qt.TextElideMode.ElideMiddle,
            label.contentsRect().width(),
        ))
    def _set_inspector_metadata(self, groups: list[InformationGroup]) -> None:
        """Localize the authorized fields for the compact inspector document.

        Args:
            groups (list[InformationGroup]): Localized group keys and validated fields.
        Returns:
            None: Displays grouped metadata and expandable names for this photo.
        """
        self._inspector.set_groups([
            (tr(self.language, heading), [
                (key, tr(self.language, key), self._format_information(key, value))
                for key, value in rows if value is not None
            ])
            for heading, rows in groups
        ])
    def _information_rows(self, groups: list[InformationGroup]) -> str:
        """Format the same ordered scoped groups as the existing Tk inspector.

        Args:
            groups (list[InformationGroup]): Localized heading and validated nullable fields.
        Returns:
            str: Selectable plain text with only populated groups and fields.
        """
        content: list[str] = []
        for heading, rows in groups:
            values = [(key, value) for key, value in rows if value is not None]
            if values:
                content.append(tr(self.language, heading))
                content.extend(
                    f'{tr(self.language, key)}: {self._format_information(key, value)}'
                    for key, value in values
                )
                content.append('')
        return '\n'.join(content)
    def _format_information(self, key: str, value: str | int | float | bool) -> str:
        """Format bounded media metadata for localized plain text display.

        Args:
            key (str): Stable shared metadata field key.
            value (str | int | float | bool): Validated value in the active grant.
        Returns:
            str: Shared size caption, localized access state or printable text.
        """
        if isinstance(value, bool):
            return self._text('yes' if value else 'no')
        if key.endswith('_size') and isinstance(value, int):
            return format_gallery_bytes(value)
        if key.endswith('_gps_altitude') and isinstance(value, (int, float)):
            return f'{value:g} m'
        if key.endswith('_visibility'):
            visibility = 'unpublished' if value == 'draft' else str(value)
            return self._text('visibility_state_' + visibility)
        return ''.join(char if char.isprintable() else ' ' for char in str(value))
    def _information_finished(self, failure: str) -> None:
        """Keep the authorized detail or show a safe lookup failure.

        Args:
            failure (str): Empty after success or a sanitized exception category.
        Returns:
            None: Updates the existing Photos status.
        """
        photo = self._inspected_photo
        if photo is not None and not self._inspector_panel.isHidden():
            if failure:
                self._set_inspector_summary(
                    photo, self._text('details_fallback'),
                    size_in_details=photo.size is not None,
                )
                groups = photo_information_groups({
                    'title': photo.title, 'size': photo.size,
                    'visibility': photo.visibility, 'nsfw': photo.nsfw,
                }, include_location=False)
                self._set_inspector_metadata(groups)
            return
        self.status_message(self._text('info_unavailable') if failure else '')
    def _dismiss_information(self, *, keep_pane: bool = False) -> None:
        """Withdraw stale metadata and previews before navigation or shutdown.

        Args:
            keep_pane (bool): Whether a new request keeps the current split geometry.
        Returns:
            None: Cancels both reads and clears the pane or previous detail dialog.
        """
        self._information.cancel()
        self._inspector_read.cancel()
        self._inspected_photo = None
        if not keep_pane and not self._inspector_panel.isHidden():
            self._inspector_panel.hide()
            self.restore_tree_width(self._preferred_tree_width)
        self._inspector_summary.clear()
        self._inspector_preview.present(None)
        self._inspector.set_groups([])
        if self._information_dialog is not None:
            self._information_dialog.close()
            self._information_dialog.deleteLater()
            self._information_dialog = None
    def _close_inspector(self) -> None:
        """Dismiss metadata and return keyboard focus to the selected photo.

        Returns:
            None: Leaves the current selection, viewport and textures in place.
        """
        self._dismiss_information()
        self.quick.setFocus(Qt.FocusReason.OtherFocusReason)
    def _run_gallery_action(
        self, command: Callable[[Event], GalleryActionResult], success: str, *,
        refresh_tree: bool = False, refresh_photos: tuple[int, str] | None = None,
        failure_caption: str = '',
    ) -> None:
        """Start one exclusive explicit action and preserve the active grant until it ends.

        Args:
            command (Callable[[Event], GalleryActionResult]): Current verified
                operation. Only a creation receipt changes the tree directly.
            success (str): Localized-ready caption for a confirmed result.
            refresh_tree (bool): Refresh the gallery hierarchy after confirmed mutation.
            refresh_photos (tuple[int, str] | None): Exact gallery ID and
                uncertainty caption for a one-shot photo mutation.
            failure_caption (str): Scoped localized failure, or the default connection message.
        Returns:
            None: Queues a result or presents an error without replaying a request.
        """
        if not self._can_disconnect():
            return
        self._maintenance_busy = True
        self._action_success = success
        self._action_failure = failure_caption
        self._refresh_after_action = refresh_tree
        self._refresh_photos_after_action = refresh_photos
        self.work_changed.emit(True)

        def run(
            cancelled: Event, publish: Callable[[str | CreatedGallery | UnlinkedSite], None],
        ) -> None:
            """Forward only a confirmed result from the shared owner.

            Args:
                cancelled (Event): Exact action lifetime.
                publish (Callable[[str | CreatedGallery | UnlinkedSite], None]): Scoped result sink.
            Returns:
                None: Emits a caption on completion.
            """
            confirmed = command(cancelled)
            publish(confirmed if isinstance(confirmed, (CreatedGallery, UnlinkedSite)) else success)

        self._maintenance.submit(run)
        self._update_preload_priority()
    def _receive_maintenance(
        self, result: str | CreatedGallery | UnlinkedSite | LinkedRecoveryObservation,
    ) -> None:
        """Present confirmed work and admit a newly verified tree member in place.

        Args:
            result (str | CreatedGallery | UnlinkedSite | LinkedRecoveryObservation):
                Safe status, authorized creation receipt, recovery observation
                or confirmed outcome for the exact disconnected site.
        Returns:
            None: Keeps the original selection except when the new root is the first one.
        """
        if isinstance(result, str):
            self.status_message(result)
            return
        if isinstance(result, LinkedRecoveryObservation):
            if self._gallery_picker_token != result.picker_token:
                return
            connections = self.connections
            recovery = connections.recovery if connections is not None else None
            current = bool(
                recovery is not None
                and self._current_linked_recovery_target(
                    result.target.session,
                    (result.target.issuer, result.target.user_id),
                ) is result.target
            )
            if current and recovery is not None:
                try:
                    current = recovery.is_current(result.target, result.marker)
                except Exception:
                    current = False
            if not current:
                self.status_message(gettext('The upload observation is no longer current.'))
                return
            self.status_message(tr(
                self.language,
                'linking_recovery_observed'
                if result.outcome == 'present'
                else 'linking_recovery_unknown',
            ))
            return
        if isinstance(result, UnlinkedSite):
            if self._session is result.site.session:
                self._detach_current_site()
            if result.revoked:
                self.status_message(gettext_noop('Gallery unlinked.'))
            else:
                self.status_message(gettext_noop(
                    'Unlinked locally. Check Manage link to confirm server revocation.',
                ))
            self.link_action_reported.emit(self.status.text())
            self._sync_connection_state()
            return
        if self._session is not result.session or not result.session.available():
            return
        gallery = result.receipt.gallery
        self._create_draft = None
        item = self._gallery_items.get(gallery.gallery_id)
        parent = self._gallery_items.get(gallery.parent_id) if gallery.parent_id is not None else None
        if item is None:
            item = QTreeWidgetItem([gallery.title])
            item.setData(0, Qt.ItemDataRole.UserRole, str(gallery.gallery_id))
            if parent is None:
                self._gallery_tree.addTopLevelItem(item)
            else:
                parent.addChild(item)
            self._gallery_items[gallery.gallery_id] = item
            self._refresh_tree_marker(gallery.gallery_id)
        else:
            item.setText(0, gallery.title)
        if parent is not None and gallery.parent_id is not None:
            parent.setExpanded(True)
            existing = self._gallery_children.get(gallery.parent_id, ())
            siblings = tuple(row for row in existing if row.gallery_id != gallery.gallery_id) + (gallery,)
            self._gallery_children[gallery.parent_id] = tuple(sorted(siblings, key=lambda row: row.gallery_id))
        self._gallery_tree.show()
        self._sync_connection_state()
        if self._gallery_id is None and gallery.parent_id is None:
            self._gallery_tree.setCurrentItem(item)
        self.status_message(self._text('created').format(title=gallery.title))
    def _maintenance_finished(self, failure: str) -> None:
        """Reopen local commands after the durable operation has settled.

        Args:
            failure (str): Safe exception category or empty after verified success.
        Returns:
            None: Refreshes current gallery rows when the mutation was confirmed.
        """
        self._maintenance_busy = False
        unlinking, self._unlink_pending = self._unlink_pending, False
        self.work_changed.emit(False)
        photo_refresh = self._refresh_photos_after_action
        self._refresh_photos_after_action = None
        if photo_refresh is not None:
            gallery, uncertainty = photo_refresh
            notice = uncertainty if failure else self._action_success
            if gallery == self._gallery_id:
                self._catalogue_notice = gallery, notice
                self.refresh()
            else:
                self.status_message(notice)
            return
        if failure:
            self.status_message(self._action_failure or 'Gallery connection is unavailable.')
            if unlinking:
                self.link_action_reported.emit(self.status.text())
            return
        session, identity = self._session, self._identity
        if self._refresh_after_action and session is not None and identity is not None and self._tree_path is not None:
            path = self._tree_path
            issuer, account = identity
            self._load.submit(lambda cancel, publish: publish(LinkedPhotos(
                session, issuer, account, read_gallery_tree(session, cancel),
                load_photos_tree_preferences(path), owns_session=False,
            )))
    def _detach_current_site(self) -> None:
        """Clear the current authorized view after local release or explicit unlink.

        Returns:
            None: Withdraws only the matching catalogue, media and in-memory session.
        """
        if self._gallery_editor is not None:
            self._gallery_editor.reject()
        self._pending_live_site = None
        self._startup_browse = False
        self._create_draft = None
        self._authorization_active = False
        self._link_cancel_button.hide()
        self._load.cancel()
        self._dismiss_information()
        self._photo_info_cache.clear()
        self._retire_preload()
        self.playback.bind(None, None)
        if self._attempt is not None:
            self._attempt.close()
            self._attempt = None
        if self._session is not None:
            self._session.close()
        had_connection = self._identity is not None
        self._session = None
        self._gallery_id = None
        self._identity = None
        self._cache_states.clear()
        self._gallery_pins.clear()
        self._gallery_title.clear()
        if had_connection:
            self.connection_changed.emit()
        self._rendered_gallery = None
        self._tree_path = None
        self._opened_galleries.clear()
        self._gallery_items.clear()
        self._gallery_children.clear()
        self._sync_capture_markers()
        self._folder_split_loaded = None
        self._folder_modes_unsaved.clear()
        self._gallery_has_photos = None
        self._viewports.clear()
        self._view_path = None
        self._catalogue_notice = None
        self._gallery_tree.blockSignals(True)
        self._gallery_tree.clear()
        self._gallery_tree.blockSignals(False)
        self._local_photos_active = False
        self._format = 'default'
        self._format_unsaved.clear()
        self._sync_connection_state()
        self._gallery_tree.hide()
        self._gallery_panel.hide()
        self._gallery_options.setIcon(self._gallery_state_icon(None, show_capture=False))
        self._gallery_options.setVisible(bool(self._parked_links))
        self.link_button.setEnabled(True)
        self._install_catalogue(())
        self.status.setText(gettext("Link a gallery or open local photos."))
        self._sync_connection_state()
        self.formatChanged.emit()
