# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/qt/link_management.py
# Module Type: Qt Photos domain mixin
# Purpose: Keep one cohesive Photos interaction domain outside the widget facade.
# Responsibilities: Preserve the QtPhotos lifecycle while grouping its callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Qt Photos behavior mixin declarations."""

from .support import (
    ActionButton,
    Callable,
    CredentialStoreUnavailable,
    DESKTOP_DIALOG_MARGIN,
    DESKTOP_DIALOG_SIZE,
    DesktopLinkedSite,
    Event,
    GALLERIES_SCOPE,
    LINKING_ADDRESS_VISIBLE_ROWS,
    LinkedPhotos,
    LinkingAttempt,
    LinkingError,
    LinkingProgress,
    LinkingReconnectRequired,
    LinkingRefreshRejected,
    LinkingResponseError,
    LinkingSession,
    LinkingUnavailable,
    LiveSiteCatalogue,
    ModeRecord,
    OfflineBinding,
    OfflineRefreshPending,
    PhotoCatalogue,
    PhotosLoadResult,
    QT_PHOTOS_LOGGER,
    PhotosUploadUpgrade,
    QCheckBox,
    QDialog,
    QHBoxLayout,
    QLabel,
    QLineEdit,
    QMessageBox,
    QModelIndex,
    QSignalBlocker,
    QSortFilterProxyModel,
    QTreeView,
    QTreeWidget,
    QTreeWidgetItem,
    QVBoxLayout,
    QWidget,
    Qt,
    RECENT_GALLERY_ADDRESS_LIMIT,
    ReadCancelled,
    SavedLinkRecovery,
    UPLOAD_SCOPE,
    UnlinkedSite,
    delete_transient,
    desktop_upload_vault,
    discover_linking_capabilities,
    enable_rolling_renewal,
    find_live_link,
    forget_browser_upload_grant,
    gettext,
    gettext_noop,
    linking_issuer,
    linking_prompt_issuer,
    live_link_sites,
    load_offline_binding,
    load_photos_tree_preferences,
    load_transient,
    logging,
    open_saved_upload_session,
    persist_browser_gallery_grant,
    persist_browser_scope_upgrade,
    persist_browser_upload_grant,
    photos_account_tree_path,
    read_gallery_tree,
    remember_desktop_transient_link,
    remove_offline_binding,
    replace,
    requested_linking_scope,
    restore_desktop_gallery_link,
    revoke_saved_gallery_grant,
    save_offline_binding,
    save_recent_gallery_addresses,
    saved_desktop_gallery_binding,
    saved_offline_hint_path,
    scope_base,
    store_approved_refresh,
    sys,
    time,
    tr,
    uuid4,
    verify_linked_upload_target,
    webbrowser,
)

class QtPhotosLinkMixin:
    """Own one cohesive set of Qt Photos widget behaviors."""

    def _publish_saved_browse(
        self, issuer: str, cancelled: Event, publish: Callable[[
            LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress
        ], None],
    ) -> bool:
        """Transfer a restored gallery session to the current Photos generation.

        Args:
            issuer (str): Exact canonical address chosen by profile or operator.
            cancelled (Event): Current lifetime of the owning Photos read.
            publish (Callable[[LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress], None]):
                Queued receiver for the verified browsing session and tree.
        Returns:
            bool: True when a verified grant was published, false when absent.
        Raises:
            RuntimeError: Secure storage or gallery verification failed.
            ReadCancelled: The operation was replaced before publication.
        """
        site = restore_desktop_gallery_link(self.profile, issuer, cancelled)
        if site is None:
            return False
        try:
            galleries = read_gallery_tree(site.session, cancelled)
            path = photos_account_tree_path(self.profile, site.issuer, site.user_id)
            tree_state = load_photos_tree_preferences(path)
            if cancelled.is_set():
                raise ReadCancelled
            publish(LinkedPhotos(site.session, site.issuer, site.user_id, galleries, tree_state))
        except Exception:
            site.session.close()
            raise
        return True
    def _live_sites(self) -> tuple[DesktopLinkedSite, ...]:
        """Project available grants and retire expired parked sessions.

        Returns:
            tuple[DesktopLinkedSite, ...]: Current Photos grant followed by
                reusable parked grants that remain available.
        """
        selected = None
        if self._session is not None and self._identity is not None:
            selected = DesktopLinkedSite(
                self._identity[0], self._session, self._identity[1],
            )
        sites = live_link_sites(selected, self._parked_links)
        retained = tuple(
            parked for parked in self._parked_links
            if any(site.session is parked.session for site in sites)
        )
        expired = tuple(
            parked for parked in self._parked_links
            if not any(site.session is parked.session for site in retained)
        )
        self._parked_links = retained
        for site in expired:
            site.session.close()
        return sites
    def _park_current_link(self) -> None:
        """Retain the current exact grant while leaving its gallery view.

        Returns:
            None: Keeps only the grant identity and session, never its old view.
        """
        if self._session is None or self._identity is None:
            return
        current = DesktopLinkedSite(
            self._identity[0], self._session, self._identity[1],
        )
        if not self._contains_live_site(current):
            return
        self._parked_links = (
            current,
            *(site for site in self._parked_links if site.session is not current.session),
        )
    def _contains_live_site(self, site: DesktopLinkedSite) -> bool:
        """Match a projected live grant by issuer, session identity, and account.

        Args:
            site (DesktopLinkedSite): Exact grant candidate to validate.
        Returns:
            bool: True only while that exact grant remains selected or parked.
        """
        return any(
            candidate.issuer == site.issuer
            and candidate.session is site.session
            and candidate.user_id == site.user_id
            for candidate in self._live_sites()
        )
    def _select_live_site(self, site: DesktopLinkedSite) -> None:
        """Reload and mount one already approved site's tree on the read lane.

        Args:
            site (DesktopLinkedSite): Exact live grant chosen by the operator.
        Returns:
            None: Leaves the old site visible until the selected tree is verified.
        """
        if site.session is self._session and self._identity == (site.issuer, site.user_id):
            return
        if (self._authorization_active or not self._contains_live_site(site)
                or not self._can_switch_photos_site()):
            return
        self._pending_live_site = site
        self._start_progress()
        self.status.setText(gettext('Loading galleries…'))
        self._sync_connection_state()

        def load_tree(
            cancelled: Event,
            publish: Callable[
                [LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress], None
            ],
        ) -> None:
            """Read one parked grant's current tree and account preferences.

            Args:
                cancelled (Event): Cancellation event for this lane generation.
                publish (Callable[[LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress], None]):
                    GUI-thread delivery callback owned by the current read lane.
            Returns:
                None: Publishes only the selected grant's exact tree snapshot.
            """
            if cancelled.is_set():
                raise ReadCancelled
            galleries = (
                read_gallery_tree(site.session, cancelled)
                if site.session.can_list_galleries() else ()
            )
            path = photos_account_tree_path(self.profile, site.issuer, site.user_id)
            tree_state = load_photos_tree_preferences(path)
            if cancelled.is_set():
                raise ReadCancelled
            publish(LiveSiteCatalogue(site, galleries, tree_state))

        self._load.submit(load_tree)
    def link_uploads(self) -> None:
        """Request explicit upload and available Admin Photos permission.

        Returns:
            None: Opens browser consent and leaves target selection to the operator.
        """
        self._authorize(True)
    def approve_admin(self) -> None:
        """Request explicit Admin media consent for the currently linked site.

        Returns:
            None: Preserves existing Photos access until a replacement is verified.
        """
        session, identity = self._session, self._identity
        if session is not None and identity is not None:
            self._authorize(
                session.can_upload(), admin_previous=identity,
            )
    def reconnect_uploads(self) -> None:
        """Reconnect the exact previously selected gallery and Admin account.

        Returns:
            None: Verifies the saved target through renewed browser consent.
        """
        try:
            saved = self.connections.saved() if self.connections is not None else None
            if saved is not None and saved.mode == 'linked':
                self._authorize(True, saved)
            else:
                self.status_message('Gallery connection is unavailable.')
        except RuntimeError:
            self.status_message('Gallery connection is unavailable.')
    def reapprove_uploads(self) -> None:
        """Request fresh browser consent for the exact selected saved upload mode.

        Returns:
            None: Replacement requires explicit consent for the same account,
                gallery and approved scope.
        """
        connections = self.connections
        if connections is None or not self._can_disconnect():
            return
        try:
            saved = connections.saved()
            if saved is not None and saved.mode == 'linked':
                self._authorize(True, saved, reapprove=True)
                return
        except RuntimeError:
            pass
        self.status_message('The securely saved gallery connection is unavailable.')
    def _reapprove_startup_saved(self) -> None:
        """Reapprove only the exact linked mode retained by failed startup restore.

        Returns:
            None: Starts explicit browser consent for the captured saved binding.
        """
        saved = self._startup_saved_mode
        if (saved is None or saved.mode != 'linked' or self.busy()
                or self._authorization_active or self._startup_browse):
            return
        try:
            current = self.connections.saved() if self.connections is not None else None
        except RuntimeError:
            current = None
        if current != saved:
            self._startup_saved_mode = None
            self.link_button.setText(tr(self.language, 'linking_start'))
            self.status_message('The securely saved gallery connection is unavailable.')
            return
        self._authorize(True, saved, reapprove=True)
    def forget_uploads(self) -> None:
        """Remove the selected offline credential through its existing native owner.

        Returns:
            None: Matches the stable local-only removal contract after confirmation.
        """
        connections = self.connections
        if connections is None or not self._can_disconnect():
            return
        try:
            saved = connections.saved()
        except RuntimeError:
            saved = None
        if saved is None or saved.mode != 'linked' or not saved.scope or not saved.scope.endswith(' offline_access'):
            self.status_message('The securely saved gallery connection is unavailable.')
            return
        answer = QMessageBox.question(
            self, gettext('Forget saved link'), gettext(
                'Remove the saved local credential? The current session remains active. '
                'This does not revoke the server grant. Manage gallery links to review or revoke it.',
            ), QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No,
            QMessageBox.StandardButton.No,
        )
        if answer != QMessageBox.StandardButton.Yes or not self._can_disconnect():
            return
        active = connections.active()

        def remove(cancelled: Event) -> None:
            """Remove only the mode that was still selected after confirmation.

            Args:
                cancelled (Event): Lifetime of the explicit local removal.
            Returns:
                None: Any current same-scope bearer stays usable until expiry.
            """
            if cancelled.is_set() or connections.saved() != saved:
                raise ReadCancelled
            forget_browser_upload_grant(self.profile, saved)
            if (active is not None and active.issuer == saved.issuer
                    and active.user_id == saved.account_id
                    and active.session.approved_scope() == saved.scope):
                active.session.disable_renewal()

        self._run_gallery_action(remove, gettext_noop(
            'Saved local credential removed; the current session and server grant may still exist.',
        ))
    def unlink(self) -> None:
        """Withdraw the selected gallery approval through its existing owner.

        Returns:
            None: Removes only the current grant and keeps its saved destination
                eligible for the normal verified reconnect and recovery workflow.
        """
        session, identity = self._session, self._identity
        if session is None or identity is None or not self._can_disconnect():
            return
        if self._authorization_active or self._startup_browse:
            self.status_message('Wait for the current gallery request to finish.')
            return
        site = DesktopLinkedSite(identity[0], session, identity[1])
        scope = session.approved_scope()
        self._keepalive.cancel()
        self.status_message('Removing the saved connection…')
        self.link_action_reported.emit(self.status.text())

        def remove(cancelled: Event) -> UnlinkedSite:
            """Remove only this exact binding and request its one server revocation.

            Args:
                cancelled (Event): Lifetime of the guarded unlink operation.
            Returns:
                UnlinkedSite: Exact local unlink and its sanitized server outcome.
            """
            if cancelled.is_set():
                raise ReadCancelled
            if sys.platform == 'darwin':
                transient = load_transient(site.issuer)
                if (transient is not None and transient.account_id == site.user_id
                        and transient.scope == scope):
                    delete_transient(site.issuer)
            binding = saved_desktop_gallery_binding(self.profile / 'offline-link.json', site.issuer)
            revoked = None
            if binding is not None and binding.account_id == site.user_id and binding.scope == scope:
                store = desktop_upload_vault(self.profile)
                if store is None:
                    raise LinkingError('Secure storage is unavailable.')
                revoked = revoke_saved_gallery_grant(
                    self.profile, site.issuer, site.user_id, binding, store,
                )
            site.session.disable_renewal()
            if revoked is None:
                try:
                    site.session.revoke()
                except (RuntimeError, OSError):
                    revoked = False
                else:
                    revoked = True
            return UnlinkedSite(site, revoked)

        self._unlink_pending = True
        self._run_gallery_action(remove, '', failure_caption=gettext_noop(
            'The saved secure reconnect could not be removed. The gallery remains connected.',
        ))
    def _authorize(
        self, uploads: bool, saved: ModeRecord | None = None, *,
        admin_previous: tuple[str, int] | None = None,
        reapprove: bool = False,
        address_override: str | None = None,
        requested_scope: str | None = None,
    ) -> None:
        """Obtain an explicit scoped grant without admitting background transfers.

        Args:
            uploads (bool): Request upload permission in addition to browsing.
            saved (ModeRecord | None): Original target when explicitly reconnecting.
            admin_previous (tuple[str, int] | None): Current verified site and
                account for a separately approved Admin grant.
            reapprove (bool): Request a new browser handoff for the exact saved scope.
            address_override (str | None): Validated address submitted by the
                persistent Photos authorization dialog.
            requested_scope (str | None): Exact scope chosen in the management
                controls, never a persisted upload-target transition.
        Returns:
            None: Opens approval and mounts a verified live gallery catalogue.
        """
        if uploads or saved is not None or admin_previous is not None:
            if not self._can_disconnect():
                return
        elif not self._can_switch_photos_site():
            return
        if requested_scope is not None and (
            saved is not None or admin_previous is not None or self._session is not None
            or scope_base(requested_scope) is None
        ):
            self.status_message('Gallery connection is unavailable.')
            return
        if address_override is None and (saved is None or admin_previous is not None):
            self._open_authorization_dialog(uploads, saved, admin_previous, reapprove)
            return
        if admin_previous is not None:
            address = admin_previous[0]
        elif address_override is not None:
            address = address_override
        elif saved is None:
            address = self.gallery_address
        else:
            address = saved.issuer or ''
        current, current_identity = self._session, self._identity
        admin_mode_snapshot = None
        admin_target_snapshot = None
        admin_binding_snapshot = None
        admin_store = None
        admin_upgrade_target = None
        if admin_previous is not None:
            if (current is None or current_identity != admin_previous
                    or not current.available()
                    or not current.matches_issuer(admin_previous[0])):
                self.status_message('Gallery connection is unavailable.')
                return
            if self.connections is not None:
                try:
                    admin_mode_snapshot = self.connections.saved()
                    admin_target_snapshot = self.connections.active()
                except RuntimeError:
                    admin_mode_snapshot = None
                    admin_target_snapshot = None
                    mode_snapshot_readable = False
                else:
                    mode_snapshot_readable = True
                current_scope = current.approved_scope()
                same_account_mode = bool(
                    admin_mode_snapshot is not None
                    and admin_mode_snapshot.mode == 'linked'
                    and (admin_mode_snapshot.issuer, admin_mode_snapshot.account_id)
                    == admin_previous
                )
                same_account_target = bool(
                    admin_target_snapshot is not None
                    and (admin_target_snapshot.issuer, admin_target_snapshot.user_id)
                    == admin_previous
                )
                exact_target_mode = bool(
                    same_account_mode and same_account_target
                    and admin_mode_snapshot is not None
                    and admin_target_snapshot is not None
                    and admin_mode_snapshot.gallery_id == admin_target_snapshot.gallery_id
                    and admin_mode_snapshot.scope
                    == admin_target_snapshot.session.approved_scope()
                    and admin_target_snapshot.session.available()
                    and admin_target_snapshot.session.can_upload()
                    and admin_target_snapshot.session.matches_issuer(admin_previous[0])
                )
                if (mode_snapshot_readable
                        and (exact_target_mode or not same_account_mode and not same_account_target)
                        and (exact_target_mode or not current.can_upload())
                        and (exact_target_mode or current_scope.endswith(' offline_access'))):
                    try:
                        binding = saved_desktop_gallery_binding(
                            self.profile / 'offline-link.json', admin_previous[0],
                        )
                        store = desktop_upload_vault(self.profile)
                    except (OSError, RuntimeError, ValueError):
                        binding = None
                        store = None
                    expected_scope = (
                        admin_mode_snapshot.scope if exact_target_mode
                        and admin_mode_snapshot is not None else current_scope
                    )
                    if (store is not None and binding is not None
                            and (binding.issuer, binding.account_id, binding.scope)
                            == (*admin_previous, expected_scope)):
                        # Reuse only the exact rolling grant backing this view or target.
                        admin_store = store
                        admin_binding_snapshot = binding
                        if exact_target_mode:
                            admin_upgrade_target = admin_target_snapshot
        fresh_browse = (
            requested_scope is None and saved is None
            and admin_previous is None and not uploads
        )
        reusable = None
        if (admin_previous is None and saved is not None and not reapprove and current is not None
                and self._identity == (saved.issuer, saved.account_id)
                and current.available() and current.can_upload()
                and current.approved_scope() == saved.scope):
            reusable = current
        preserve_current = admin_previous is not None or saved is None or reusable is not None
        if not preserve_current:
            self.disconnect()
        if self._attempt is not None:
            self._attempt.close()
        attempt = None
        try:
            issuer = linking_issuer(address)
            if fresh_browse and issuer:
                live_site = find_live_link(self._live_sites(), issuer)
                if live_site is not None:
                    self._close_authorization_dialog()
                    self._select_live_site(live_site)
                    self._remember_recent_address(issuer)
                    return
            if requested_scope is not None:
                scope = requested_scope
            elif admin_previous is not None:
                scope = (
                    UPLOAD_SCOPE if uploads or admin_upgrade_target is not None
                    else GALLERIES_SCOPE
                )
            elif saved is not None:
                scope = saved.scope
            else:
                scope = UPLOAD_SCOPE if uploads else GALLERIES_SCOPE
            if scope is None:
                raise LinkingError('Saved gallery access is unavailable.')
            if reusable is None and (
                admin_previous is not None or reapprove
                or not (saved is not None and scope.endswith(' offline_access'))
            ):
                attempt = LinkingAttempt(issuer, scope=scope)
        except LinkingError:
            if self._authorization_status is not None:
                self._authorization_status.setText(gettext("Gallery connection is unavailable."))
                self._authorization_status.show()
            else:
                self.status.setText(gettext("Gallery connection is unavailable."))
            return
        self._pending_live_site = None
        self._startup_browse = False
        self._attempt = attempt
        self._authorization_active = True
        self._authorization_failure = ''
        self._selecting_saved_target = saved is not None
        self._link_cancel_button.setVisible(
            self._authorization_dialog is None and not self._selecting_saved_target,
        )
        self.link_button.setEnabled(False)
        saved_link_prompt = gettext("Checking the securely saved connection…")
        self.status.setText(
            tr(self.language, 'linking_requesting') if attempt is not None
            else saved_link_prompt
        )
        self._sync_authorization_dialog()
        self.progress.hide()
        self._sync_connection_state()
        if self._selecting_saved_target:
            self.work_changed.emit(True)

        def authorize(
            cancelled: Event,
            publish: Callable[[PhotosLoadResult], None],
        ) -> None:
            """Exchange an explicit browser grant and verify its exact scope.

            Args:
                cancelled (Event): Current linking operation cancellation.
                publish (Callable[[PhotosLoadResult], None]): Queued receiver for
                    approved grants, authorized catalogues and progress.
            Returns:
                None: Transfers a live authorized grant or closes it on failure.
            """
            session = reusable
            saved_storage_unavailable = False
            step = 'restore'
            try:
                store = admin_store
                refresh = None
                refresh_deadline = None
                if attempt is not None:
                    if fresh_browse:
                        try:
                            if self._publish_saved_browse(issuer, cancelled, publish):
                                return
                        except CredentialStoreUnavailable:
                            # Explicit Connect may continue with browser consent
                            # when a saved native grant cannot be read safely.
                            saved_storage_unavailable = True
                        except (LinkingReconnectRequired, LinkingRefreshRejected):
                            # An explicit Connect may ask the user for fresh consent.
                            pass
                    if fresh_browse or (
                        uploads and self.connections is not None
                        and (reapprove or (saved is None and admin_previous is None))
                    ) or (requested_scope is not None
                          and 'offline_access' in requested_scope.split()):
                        store = (
                            None if saved_storage_unavailable
                            else desktop_upload_vault(self.profile)
                        )
                    if reapprove and scope.endswith(' offline_access') and store is None:
                        raise LinkingError('The securely saved gallery connection is unavailable.')
                    if (requested_scope is not None
                            and 'offline_access' in requested_scope.split()
                            and store is None):
                        raise LinkingError('Secure offline storage is unavailable.')
                    step = 'request'
                    request = attempt.push_request(
                        prefer_admin_media=(
                            admin_previous is not None
                            or saved is None and requested_scope is None
                        ),
                        require_admin_media=admin_previous is not None,
                        prefer_rolling=(
                            store is not None and (
                                admin_upgrade_target is not None
                                or saved is None and requested_scope is None
                            )
                        ),
                        prefer_presentation=(
                            saved is None and requested_scope is None
                        ),
                    )
                    if cancelled.is_set():
                        raise ReadCancelled
                    step = 'browser'
                    if not webbrowser.open(request.authorization_url):
                        raise LinkingError("Browser could not open the approval page.")
                    publish(LinkingProgress(tr(self.language, 'linking_approve_browser')))
                    step = 'callback'
                    if attempt.wait_for_callback() != "approved" or cancelled.is_set():
                        raise LinkingError("Browser access was not approved.")
                    step = 'exchange'
                    publish(LinkingProgress(gettext('Checking the approved identity…')))
                    if attempt.requests_offline_access():
                        exchange_started = time.monotonic()
                        session, refresh, lifetime = attempt.exchange_offline_once()
                        refresh_deadline = exchange_started + lifetime
                    else:
                        session = attempt.exchange_once()
                elif session is None:
                    assert saved is not None
                    session = open_saved_upload_session(self.profile, saved, cancelled)
                step = 'identity'
                if requested_scope is None:
                    request_upload = uploads or admin_upgrade_target is not None
                    if (request_upload and not session.can_upload()
                            or not request_upload and (
                                not session.can_read_media() or session.can_upload()
                            )):
                        raise LinkingError('Gallery connection is unavailable.')
                else:
                    requested_tokens = set(requested_scope.split())
                    approved_scope = session.approved_scope()
                    approved_tokens = set(approved_scope.split())
                    if (
                        not requested_tokens.issubset(approved_tokens)
                        or approved_tokens - requested_tokens - {'galleries:search'}
                        or scope_base(approved_scope) != scope_base(requested_scope)
                        or not session.matches_issuer(issuer)
                    ):
                        raise LinkingError('Gallery connection is unavailable.')
                    selected_permissions = (
                        ('galleries:list', session.can_list_galleries()),
                        ('gallery:upload', session.can_upload()),
                        ('gallery:read', session.can_read_gallery_detail()),
                        ('galleries:create', session.can_create_gallery()),
                        ('gallery:visibility', session.can_change_gallery_visibility()),
                    )
                    if any(
                        token in requested_tokens and not admitted
                        for token, admitted in selected_permissions
                    ):
                        raise LinkingError('Gallery connection is unavailable.')
                    if (('media:read' in requested_tokens or 'media:admin' in requested_tokens)
                            and not session.can_read_media()):
                        raise LinkingError('Gallery connection is unavailable.')
                identity = session.identity()
                if admin_previous is not None and (
                    not session.can_read_admin_media() or identity.user_id != admin_previous[1]
                ):
                    raise LinkingError('Admin photo access is unavailable.')
                target = None
                selected = None
                if admin_upgrade_target is not None:
                    if (self.connections is None or admin_mode_snapshot is None
                            or admin_binding_snapshot is None or admin_store is None
                            or admin_upgrade_target.issuer != issuer
                            or admin_upgrade_target.user_id != identity.user_id
                            or admin_mode_snapshot.gallery_id != admin_upgrade_target.gallery_id
                            or admin_mode_snapshot.scope != admin_binding_snapshot.scope
                            or session.approved_scope() == admin_binding_snapshot.scope
                            or not session.can_upload()
                            or not session.can_read_admin_media()):
                        raise LinkingError('Admin photo access is unavailable.')
                    target = verify_linked_upload_target(
                        session, issuer, identity.user_id, session.approved_scope(),
                        admin_upgrade_target.gallery_id, cancelled,
                    )
                elif saved is not None:
                    if (self.connections is None or saved.account_id != identity.user_id
                            or saved.gallery_id is None or saved.scope != session.approved_scope()):
                        raise LinkingError('Gallery connection is unavailable.')
                    target = verify_linked_upload_target(
                        session, issuer, identity.user_id, session.approved_scope(),
                        saved.gallery_id, cancelled,
                    )
                    if cancelled.is_set() or self.connections.saved() != saved:
                        raise ReadCancelled
                step = 'catalogue'
                publish(LinkingProgress(gettext('Loading galleries…')))
                galleries = (
                    read_gallery_tree(session, cancelled)
                    if session.can_list_galleries() else ()
                )
                if (admin_upgrade_target is not None and refresh is not None
                        and refresh_deadline is not None and admin_store is not None):
                    remaining = int(refresh_deadline - time.monotonic())
                    if cancelled.is_set() or remaining <= 0 or target is None:
                        raise ReadCancelled
                    binding = OfflineBinding(issuer, identity.user_id, session.approved_scope())
                    if (self.connections is None
                            or self.connections.saved() != admin_mode_snapshot
                            or self.connections.active() is not admin_upgrade_target
                            or saved_desktop_gallery_binding(
                                self.profile / 'offline-link.json', issuer,
                            ) != admin_binding_snapshot):
                        raise LinkingError('Saved gallery credential changed.')
                    tree_state = load_photos_tree_preferences(
                        photos_account_tree_path(self.profile, issuer, identity.user_id),
                    )
                    publish(PhotosUploadUpgrade(
                        session, current, target, admin_mode_snapshot,
                        admin_upgrade_target, binding, admin_store,
                        admin_binding_snapshot.scope, refresh, refresh_deadline,
                        galleries, tree_state,
                    ))
                    session = None
                    return
                if refresh is not None and refresh_deadline is not None and store is not None:
                    step = 'storage'
                    remaining = int(refresh_deadline - time.monotonic())
                    if requested_scope is not None:
                        if cancelled.is_set() or remaining <= 0 or not session.available():
                            raise ReadCancelled
                        approved_scope = session.approved_scope()
                        binding = OfflineBinding(issuer, identity.user_id, approved_scope)
                        path = saved_offline_hint_path(
                            self.profile / 'offline-link.json', issuer, approved_scope,
                        )
                        with store.binding_transaction(issuer, identity.user_id):
                            if (cancelled.is_set()
                                    or load_offline_binding(path, issuer) is not None
                                    or store.read(issuer, identity.user_id) is not None):
                                raise LinkingError('Saved gallery credential changed.')
                            save_offline_binding(path, binding)
                            try:
                                store_approved_refresh(
                                    store, issuer, identity.user_id, approved_scope,
                                    refresh, remaining,
                                )
                            except Exception:
                                remove_offline_binding(path, binding)
                                raise
                    elif fresh_browse:
                        persist_browser_gallery_grant(
                            self.profile, store, issuer, identity, session,
                            refresh, remaining, cancelled,
                        )
                    elif (saved is None and admin_upgrade_target is None and current is not None
                          and current_identity == (issuer, identity.user_id)
                          and not current.can_upload()
                          and current.approved_scope().endswith(' offline_access')):
                        if admin_previous is not None:
                            if (admin_store is None or admin_binding_snapshot is None
                                    or self.connections is None
                                    or self.connections.saved() != admin_mode_snapshot
                                    or self.connections.active() != admin_target_snapshot
                                    or saved_desktop_gallery_binding(
                                        self.profile / 'offline-link.json', issuer,
                                    ) != admin_binding_snapshot):
                                raise LinkingError('Saved gallery credential changed.')
                        if cancelled.is_set() or remaining <= 0 or not current.available():
                            raise ReadCancelled
                        prior_mode = self.connections.saved() if self.connections is not None else None
                        approved_scope = session.approved_scope()
                        if (prior_mode is not None and prior_mode.mode == 'linked'
                                and (prior_mode.issuer, prior_mode.account_id) == current_identity
                                and prior_mode.scope != approved_scope):
                            raise LinkingError('Reconnect with the original Watcher upload permissions.')
                        binding = OfflineBinding(issuer, identity.user_id, approved_scope)
                        persist_browser_scope_upgrade(
                            self.profile / 'offline-link.json', store, binding,
                            current.approved_scope(),
                            refresh, remaining,
                        )
                        current.disable_renewal()
                        hint = saved_offline_hint_path(
                            self.profile / 'offline-link.json', issuer, approved_scope,
                        )
                        enable_rolling_renewal(session, store, hint, binding)
                    else:
                        persist_browser_upload_grant(
                            self.profile, store, issuer, identity, session, refresh,
                            remaining, cancelled, previous_mode=saved if reapprove else None,
                        )
                elif (attempt is not None
                      and (not fresh_browse or store is not None)):
                    remember_desktop_transient_link(session, identity.user_id)
                if target is not None and admin_upgrade_target is None:
                    assert self.connections is not None
                    if cancelled.is_set() or self.connections.saved() != saved:
                        raise ReadCancelled
                    self.connections.select(target)
                    selected = target.gallery_id
                path = photos_account_tree_path(self.profile, issuer, identity.user_id)
                tree_state = load_photos_tree_preferences(path)
                if selected is not None:
                    tree_state = replace(tree_state, selected=selected)
                publish(LinkedPhotos(
                    session, issuer, identity.user_id, galleries, tree_state,
                    owns_session=reusable is None,
                ))
                session = None
            except ReadCancelled:
                raise
            except Exception as error:
                if not cancelled.is_set():
                    caption = self._authorization_failure_caption(step, error)
                    publish(LinkingProgress(caption, failed=True))
                raise
            finally:
                if attempt is not None:
                    attempt.close()
                if session is not None and session is not reusable:
                    session.close()

        self._load.submit(authorize)
    def _authorization_failure_caption(self, step: str, error: Exception) -> str:
        """Report a linking failure without logging the address or credentials.

        Args:
            step (str): Fixed phase owned by the current authorization worker.
            error (Exception): Worker failure; only bounded protocol facts or its
                class are admitted to the log.
        Returns:
            str: Localized failure caption for the current dialog or Photos status.
        """
        logger = QT_PHOTOS_LOGGER
        if isinstance(error, (LinkingResponseError, LinkingUnavailable)):
            logger.warning('Qt gallery linking failed: step=%s; %s', step, error.safe_summary)
            if isinstance(error, LinkingUnavailable):
                return tr(self.language, 'linking_website_address_unavailable')
            transport_message = {
                'dns_failure': gettext_noop('Could not resolve the gallery address (DNS).'),
                'connect_failure': gettext_noop('Could not connect to the gallery service.'),
                'tls_failure': gettext_noop('Could not establish secure HTTPS to the gallery.'),
                'timeout': gettext_noop('The gallery request timed out.'),
            }.get(error.reason)
            if transport_message:
                return gettext(transport_message)
        else:
            logger.warning('Qt gallery linking failed: step=%s; reason=%s',
                           step, type(error).__name__)
        if step == 'callback':
            return tr(self.language, 'linking_browser_approval_failed')
        message = {
            'request': gettext_noop('Could not connect to the gallery service.'),
            'browser': gettext_noop('Could not open the browser. Try connecting again.'),
            'exchange': gettext_noop('The linking response could not be accepted.'),
            'identity': gettext_noop('The linking response could not be accepted.'),
            'catalogue': gettext_noop('Could not load galleries.'),
            'storage': gettext_noop('The approved Photos connection could not be saved safely.'),
        }.get(step, gettext_noop('Gallery connection is unavailable.'))
        return gettext(message)
    def _open_authorization_dialog(
        self, uploads: bool, saved: ModeRecord | None,
        admin_previous: tuple[str, int] | None, reapprove: bool,
    ) -> None:
        """Present the persistent address and approval controls for Photos.

        Args:
            uploads (bool): Whether the submitted grant requests upload scope.
            saved (ModeRecord | None): Existing saved target, when reconnecting.
            admin_previous (tuple[str, int] | None): Existing Admin identity.
            reapprove (bool): Whether the existing scope requires a new grant.
        Returns:
            None: Opens the modal address form without blocking Qt's event loop.
        """
        if self._authorization_dialog is not None:
            self._authorization_dialog.raise_()
            self._authorization_dialog.activateWindow()
            return
        if self._startup_browse:
            self._startup_browse = False
            self._load.cancel()
            self.status.clear()
        dialog = QDialog(self)
        dialog.setObjectName('photosAuthorizationDialog')
        dialog.setWindowTitle(tr(self.language, 'linking_connect_website'))
        dialog.setWindowModality(Qt.WindowModality.WindowModal)
        dialog.setProperty('shellCard', True)
        layout = QVBoxLayout(dialog)
        title = QLabel(tr(self.language, 'linking_website_address'))
        title.setObjectName('heading')
        layout.addWidget(title)
        address = QLineEdit(self.gallery_address)
        address.setObjectName('photosAuthorizationAddress')
        address.setPlaceholderText(tr(self.language, 'linking_website_address'))
        active_issuer = self._identity[0] if self._identity is not None else ''
        initial_address = active_issuer or self.gallery_address or (
            self._recent_gallery_addresses[0]
            if self._recent_gallery_addresses else ''
        )
        address.setText(initial_address)
        if saved is not None or admin_previous is not None:
            address.setReadOnly(True)
            address.setText(
                admin_previous[0] if admin_previous is not None
                else saved.issuer if saved is not None else ''
            )
        layout.addWidget(address)
        recent = QTreeWidget(dialog)
        recent.setObjectName('photosAuthorizationRecent')
        recent.setAccessibleName(tr(self.language, 'linking_website_address'))
        recent.setHeaderHidden(True)
        recent.setUniformRowHeights(True)
        recent.setHorizontalScrollBarPolicy(Qt.ScrollBarPolicy.ScrollBarAlwaysOff)
        layout.addWidget(recent)
        status = QLabel(tr(self.language, 'linking_website_address_hint'))
        status.setObjectName('photosAuthorizationStatus')
        status.setWordWrap(True)
        layout.addWidget(status)
        layout.addStretch()

        def submit_address() -> None:
            """Validate the visible address before starting authorization.

            Returns:
                None: Retains the dialog on invalid input or starts the request.
            """
            issuer = linking_prompt_issuer(address.text())
            if not issuer:
                status.setText(tr(self.language, 'linking_website_address_invalid'))
                address.setFocus()
                return
            address.setText(issuer)
            submit.setProperty('authorizationSubmitted', True)
            self._sync_authorization_history_actions(issuer)
            self.gallery_address = issuer
            self._authorize(
                uploads, saved, admin_previous=admin_previous,
                reapprove=reapprove, address_override=issuer,
            )

        buttons = QHBoxLayout()
        forget = ActionButton(
            tr(self.language, 'linking_forget_address'),
            self._forget_authorization_recent_address,
            icon='close', icon_only=True,
        )
        forget.setObjectName('photosAuthorizationForget')
        buttons.addWidget(forget)
        buttons.addStretch()
        cancel = ActionButton(gettext('Cancel'), self.cancel_authorization)
        cancel.setObjectName('photosAuthorizationCancel')
        buttons.addWidget(cancel)
        submit = ActionButton(
            tr(self.language, 'linking_connect_website'), submit_address, primary=True,
        )
        submit.setObjectName('photosAuthorizationSubmit')
        submit.setDefault(True)
        submit.setProperty('mayReplaceWebsiteRequest', saved is None and admin_previous is None and not uploads)
        submit.setProperty('authorizationSubmitted', False)
        buttons.addWidget(submit)
        layout.addLayout(buttons)
        self._authorization_dialog = dialog
        self._authorization_address = address
        self._authorization_status = status
        self._authorization_submit = submit
        self._authorization_recent = recent
        self._authorization_forget = forget
        self._render_authorization_addresses()
        recent.currentItemChanged.connect(self._select_authorization_recent_address)
        address.textChanged.connect(self._sync_authorization_history_actions)
        self._sync_authorization_history_actions(address.text())
        owner = self.window()
        dialog.resize(
            min(DESKTOP_DIALOG_SIZE[0], max(1, owner.width() - 2 * DESKTOP_DIALOG_MARGIN)),
            min(DESKTOP_DIALOG_SIZE[1], max(1, owner.height() - 2 * DESKTOP_DIALOG_MARGIN)),
        )
        dialog.move(owner.mapToGlobal(owner.rect().center()) - dialog.rect().center())
        dialog.finished.connect(self._authorization_dialog_closed)
        dialog.open()
        self._sync_connection_state()
        address.setFocus()
    def _sync_authorization_dialog(self) -> None:
        """Mirror the active authorization phase inside its modal surface.

        Returns:
            None: Freezes the address and permits replacement only for browsing.
        """
        dialog = self._authorization_dialog
        address = self._authorization_address
        status = self._authorization_status
        submit = self._authorization_submit
        if dialog is None or status is None or address is None or submit is None:
            return
        if self.status.text():
            status.setText(self.status.text())
        address.setEnabled(not self._authorization_active)
        submit.setEnabled(
            not self._authorization_active
            or bool(submit.property('mayReplaceWebsiteRequest')),
        )
        self._sync_authorization_history_actions(address.text())
    def _render_authorization_addresses(self) -> None:
        """Show active and recent addresses as distinct, compact inline groups.

        Returns:
            None: Keeps the prefilled address as a keyboard starting point.
                Only user input marks a history row selected. Bound addresses
                remain read-only.
        """
        tree = self._authorization_recent
        address = self._authorization_address
        if tree is None or address is None:
            return
        live = () if address.isReadOnly() else tuple(site.issuer for site in self._live_sites())
        current = self._identity[0] if self._identity is not None else ''
        recent = tuple(issuer for issuer in self._recent_gallery_addresses
                       if issuer not in live and not (address.isReadOnly() and issuer == current))
        count = 0
        with QSignalBlocker(tree):
            tree.clear()
            for group_name, issuers in (
                ('linking_live_addresses', live), ('linking_recent_addresses', recent),
            ):
                if not issuers:
                    continue
                group = QTreeWidgetItem(tree, [tr(self.language, group_name)])
                group.setFlags(group.flags() & ~Qt.ItemFlag.ItemIsSelectable)
                for issuer in issuers:
                    item = QTreeWidgetItem(group, [('✓ ' + issuer) if issuer == current else issuer])
                    item.setData(0, Qt.ItemDataRole.UserRole, issuer)
                    if issuer == address.text():
                        tree.setCurrentItem(item)
                group.setExpanded(True)
                count += len(issuers) + 1  # The group label occupies one row.
            tree.clearSelection()
        tree.setVisible(count > 0)
        if count:
            row_height = max(tree.fontMetrics().height(), tree.sizeHintForRow(0))
            tree.setFixedHeight(min(count, LINKING_ADDRESS_VISIBLE_ROWS) * row_height + 2 * tree.frameWidth())
    def _select_authorization_recent_address(
        self, current: QTreeWidgetItem | None, _previous: QTreeWidgetItem | None,
    ) -> None:
        """Prefill the address field from the shared local recent list.

        Args:
            current (QTreeWidgetItem | None): Newly selected address or group.
            _previous (QTreeWidgetItem | None): Former selection emitted by Qt.
        Returns:
            None: Copies only an admitted history value into the address field.
        """
        address = self._authorization_address
        issuer = current.data(0, Qt.ItemDataRole.UserRole) if current is not None else None
        if (not isinstance(issuer, str) or not issuer or address is None
                or address.isReadOnly() or self._authorization_active
                or (issuer not in self._recent_gallery_addresses
                    and find_live_link(self._live_sites(), issuer) is None)):
            return
        address.setText(issuer)
    def _sync_authorization_history_actions(self, text: str) -> None:
        """Update the live-site action and local history removal state.

        Args:
            text (str): Current field text emitted by QLineEdit.
        Returns:
            None: Keeps the submit caption and removal affordance aligned with
                the visible address without changing any grant or target.
        """
        recent = self._authorization_recent
        forget = self._authorization_forget
        address = self._authorization_address
        submit = self._authorization_submit
        if recent is None or forget is None or address is None or submit is None:
            return
        issuer = linking_issuer(text)
        live_issuers = {site.issuer for site in self._live_sites()}
        recent.setEnabled(not self._authorization_active and not address.isReadOnly())
        caption = 'linking_connect_website'
        if self._authorization_active:
            caption = 'linking_connect_again'
        elif (bool(submit.property('mayReplaceWebsiteRequest'))
              and issuer in live_issuers):
            caption = 'linking_open_saved_link'
        elif bool(submit.property('authorizationSubmitted')):
            caption = 'linking_connect_again'
        submit.setText(tr(self.language, caption))
        forget.setVisible(
            bool(issuer and issuer in self._recent_gallery_addresses
                 and issuer not in live_issuers),
        )
        forget.setEnabled(not self._authorization_active)
    def _forget_authorization_recent_address(self) -> None:
        """Remove the selected inactive address from shared profile history.

        Returns:
            None: Removes only the local non-secret history entry; grants remain.
        """
        recent = self._authorization_recent
        address = self._authorization_address
        status = self._authorization_status
        if recent is None or address is None or status is None:
            return
        issuer = linking_issuer(address.text())
        live_issuers = {site.issuer for site in self._live_sites()}
        if (not issuer or issuer not in self._recent_gallery_addresses
                or issuer in live_issuers or self._authorization_active):
            return
        remaining = tuple(
            value for value in self._recent_gallery_addresses if value != issuer
        )
        try:
            save_recent_gallery_addresses(
                self._recent_gallery_address_path, remaining,
            )
        except (OSError, ValueError):
            self.status.setText(tr(self.language, 'linking_address_history_error'))
            status.setText(self.status.text())
            return
        self._recent_gallery_addresses = remaining
        address.clear()
        self._render_authorization_addresses()
        self.status.setText(tr(self.language, 'linking_address_removed'))
        status.setText(self.status.text())
        self._sync_authorization_history_actions(address.text())
    def _remember_recent_address(self, issuer: str) -> None:
        """Move a verified site to the front of the bounded shared history.

        Args:
            issuer (str): Exact canonical issuer from an accepted Photos session.
        Returns:
            None: Records only non-secret address history; failure preserves old data.
        """
        if not issuer or linking_issuer(issuer + '/index.php') != issuer:
            return
        updated = (issuer, *(value for value in self._recent_gallery_addresses
                             if value != issuer))
        addresses = updated[:RECENT_GALLERY_ADDRESS_LIMIT]
        if addresses == self._recent_gallery_addresses:
            return
        try:
            save_recent_gallery_addresses(
                self._recent_gallery_address_path, addresses,
            )
        except (OSError, ValueError):
            self.status.setText(tr(self.language, 'linking_address_history_error'))
            return
        self._recent_gallery_addresses = addresses
    def _authorization_dialog_closed(self, result: int) -> None:
        """Cancel pending authorization when the native modal is dismissed.

        Args:
            result (int): Native QDialog completion code.
        Returns:
            None: Clears dialog references after fencing active work.
        """
        dialog = self._authorization_dialog
        self._authorization_dialog = None
        self._authorization_address = None
        self._authorization_status = None
        self._authorization_submit = None
        self._authorization_recent = None
        self._authorization_forget = None
        if dialog is not None:
            dialog.deleteLater()
        if self._authorization_active:
            self.cancel_authorization()
        else:
            self._sync_connection_state()
    def _close_authorization_dialog(self) -> None:
        """Close a completed Photos authorization prompt safely.

        Returns:
            None: Dismisses the modal and releases its stored widget references.
        """
        dialog = self._authorization_dialog
        if dialog is not None:
            dialog.done(0)
    def cancel_authorization(self) -> None:
        """Cancel only the active link request while retaining prior approvals.

        Returns:
            None: Fences pending Photos results and leaves any current grant live.
        """
        if self._selecting_saved_target:
            return
        if not self._authorization_active:
            self._close_authorization_dialog()
            return
        self._authorization_active = False
        self._load.cancel()
        attempt, self._attempt = self._attempt, None
        if attempt is not None:
            attempt.close()
        self.link_button.setEnabled(True)
        self._link_cancel_button.hide()
        self.progress.hide()
        self.status.setText(gettext('Gallery linking cancelled.'))
        self._close_authorization_dialog()
        self._sync_connection_state()
    def status_message(self, message: str) -> None:
        """Display one localized gallery result without copying remote details.

        Args:
            message (str): Fixed local status or a structured sanitized caption.
        Returns:
            None: Updates the workspace, modal and picker status owners.
        """
        caption = gettext(message)
        self.status.setText(caption)
        if self._authorization_status is not None:
            self._authorization_status.setText(caption)
        if self._gallery_picker_feedback is not None:
            self._gallery_picker_feedback.setText(caption)
    def _text(self, key: str) -> str:
        """Use the shared localized Photos action and metadata catalogue.

        Args:
            key (str): Photos translation key without its namespace.
        Returns:
            str: Label in the active interface language.
        """
        return tr(self.language, f'photos_workspace_{key}')
    def busy(self) -> bool:
        """Identify a gallery operation that must settle before replacing its session.

        Returns:
            bool: True while the gallery action owner can still publish a result.
        """
        return (
            self._maintenance_busy or self._selecting_saved_target
            or self._gallery_editor is not None and self._gallery_editor.busy()
        )
    def _can_disconnect(self) -> bool:
        """Check grant ownership before browsing replaces the current connection.

        Returns:
            bool: Closing this grant cannot withdraw an accepted request.
        """
        if self.busy() or self.transfer_busy():
            self.status_message('Wait for the current operation to finish.')
            return False
        return True
    def _can_switch_photos_site(self) -> bool:
        """Guard view replacement while leaving the independent upload target alone.

        Returns:
            bool: True when no Photos mutation owns the current gallery view.
        """
        if self.busy():
            self.status_message('Wait for the current operation to finish.')
            return False
        return True
    def select_saved_key(self) -> None:
        """Request the existing explicit return to the saved API-key mode.

        Returns:
            None: Keeps the browsing grant separate from the newly selected mode.
        """
        connections = self.connections
        if connections is None or not self._can_disconnect():
            return
        caption = gettext('Use saved API key')
        if QMessageBox.question(self, caption, caption) == QMessageBox.StandardButton.Yes:
            self._run_gallery_action(
                lambda _cancel: connections.legacy(), gettext_noop('Saved API-key connection selected.'),
            )
    def open_gallery_picker(self) -> None:
        """Offer the verified site's gallery list from the global header.

        Returns:
            None: Opens a scoped searchable chooser using the current tree model.
        """
        session, identity = self._session, self._identity
        if session is None or identity is None or not session.can_list_galleries():
            self.status_message('Link the gallery to load its gallery list.')
            return
        if self._gallery_picker is not None:
            self._gallery_picker.raise_()
            self._gallery_picker.activateWindow()
            return
        dialog = QDialog(self.window())
        dialog.setObjectName('linkedGalleryPicker')
        dialog.setAttribute(Qt.WidgetAttribute.WA_DeleteOnClose)
        dialog.setWindowModality(Qt.WindowModality.WindowModal)
        picker_token = uuid4()
        title = gettext('Choose gallery')
        dialog.setWindowTitle(title)
        layout = QVBoxLayout(dialog)
        layout.addWidget(QLabel(identity[0]))
        search_label = tr(self.language, 'linking_gallery_search')
        search_row = QHBoxLayout()
        search = QLineEdit()
        search.setObjectName('linkedGallerySearch')
        search.setPlaceholderText(search_label)
        search.setAccessibleName(search_label)
        search_row.addWidget(search, stretch=1)
        search_row.addWidget(ActionButton(
            tr(self.language, 'linking_clear_gallery_search'), search.clear,
            icon='close', icon_only=True,
        ))
        layout.addLayout(search_row)
        model = QSortFilterProxyModel(dialog)
        model.setSourceModel(self._gallery_tree.model())
        model.setRecursiveFilteringEnabled(True)
        model.setFilterCaseSensitivity(Qt.CaseSensitivity.CaseInsensitive)
        tree = QTreeView()
        tree.setObjectName('linkedGalleryChoices')
        tree.setAccessibleName(title)
        tree.setModel(model)
        tree.setHeaderHidden(True)
        tree.expandAll()
        tree.setCurrentIndex(model.mapFromSource(self._gallery_tree.currentIndex()))
        layout.addWidget(tree, stretch=1)
        feedback = QLabel()
        feedback.setWordWrap(True)
        feedback.setObjectName('status')
        layout.addWidget(feedback)
        recovery = self.connections.recovery if self.connections is not None else None
        recovery_target = self._current_linked_recovery_target(session, identity)
        recovery_row = QWidget(dialog)
        recovery_layout = QHBoxLayout(recovery_row)
        recovery_layout.setContentsMargins(0, 0, 0, 0)
        check_recovery = ActionButton(
            gettext('Check uncertain upload'),
            lambda: self._check_linked_recovery(
                dialog, session, identity, recovery_target, recovery, picker_token,
            ),
        )
        check_recovery.setObjectName('linkedRecoveryCheck')
        review_recovery = ActionButton(
            tr(self.language, 'linking_review_title'),
            lambda: self._review_linked_recovery(
                dialog, session, identity, recovery_target, recovery,
            ),
        )
        review_recovery.setObjectName('linkedRecoveryReview')
        recovery_layout.addWidget(check_recovery)
        recovery_layout.addWidget(review_recovery)
        recovery_layout.addStretch()
        recovery_available = recovery_target is not None and recovery is not None
        recovery_row.setVisible(recovery_available)
        layout.addWidget(recovery_row)

        def recovery_work_changed(active: bool) -> None:
            """Keep recovery actions inside the exact current writer fence.

            Args:
                active (bool): Whether a Photos operation currently owns work.
            Returns:
                None: Stale or busy target actions cannot be newly admitted.
            """
            allowed = bool(
                not active and not self.transfer_busy()
                and recovery_target is not None
                and self._current_linked_recovery_target(session, identity)
                is recovery_target
            )
            check_recovery.setEnabled(allowed)
            review_recovery.setEnabled(allowed)

        recovery_work_changed(self.busy())
        footer = QHBoxLayout()
        can_upload = session.can_upload()
        caption = tr(self.language, 'linking_use_for_upload' if can_upload else 'linking_select_gallery')

        def choose() -> None:
            """Apply the exact gallery only while its original site owns the tree.

            Returns:
                None: Selects the Photos gallery or queues one verified upload target.
            """
            selected = tree.currentIndex().data(Qt.ItemDataRole.UserRole)
            if (not selected or self._session is not session or self._identity != identity
                    or not session.available()):
                return
            gallery = int(selected)
            if gallery not in self._gallery_items:
                return
            if can_upload:
                self.select_upload_gallery(gallery, confirm=False)
            else:
                self._gallery_tree.setCurrentItem(self._gallery_items[gallery])
                feedback.setText(tr(self.language, 'linking_upload_approval_needed'))

        select = ActionButton(caption, choose, primary=True)
        select.setObjectName('linkedGalleryChoose')
        select.setEnabled(tree.currentIndex().isValid())

        def selection_changed(index: QModelIndex, _previous: QModelIndex) -> None:
            """Enable actions only after a currently filtered row is selected.

            Args:
                index (QModelIndex): New selection in the shared model projection.
                _previous (QModelIndex): Previous selection, unused.
            Returns:
                None: Updates just the modal's primary action.
            """
            select.setEnabled(index.isValid() and not self.busy())

        def work_changed(active: bool) -> None:
            """Hold repeating selection while exact upload admission is pending.

            Args:
                active (bool): Whether a guarded Photos operation is still active.
            Returns:
                None: Reflects work ownership in the current dialog's action.
            """
            select.setEnabled(not active and tree.currentIndex().isValid())

        def closed(_result: int) -> None:
            """Release the dialog-owned status and operation observer.

            Args:
                _result (int): Native close code, unused.
            Returns:
                None: Pending owner work may finish safely after its view closes.
            """
            self.work_changed.disconnect(work_changed)
            self.work_changed.disconnect(recovery_work_changed)
            self._gallery_picker = None
            if self._gallery_picker_token == picker_token:
                self._gallery_picker_token = None
            self._gallery_picker_feedback = None

        tree.selectionModel().currentChanged.connect(selection_changed)
        tree.doubleClicked.connect(lambda _index: choose())
        search.textChanged.connect(model.setFilterFixedString)
        search.returnPressed.connect(tree.setFocus)
        footer.addWidget(select)
        footer.addStretch()
        footer.addWidget(ActionButton(gettext('Close'), dialog.reject))
        layout.addLayout(footer)
        self._gallery_picker = dialog
        self._gallery_picker_token = picker_token
        self._gallery_picker_feedback = feedback
        self.work_changed.connect(work_changed)
        self.work_changed.connect(recovery_work_changed)
        dialog.finished.connect(closed)
        dialog.open()
        search.setFocus(Qt.FocusReason.ShortcutFocusReason)

    def linked_gallery_address(self) -> str:
        """Return the verified link issuer without exposing account credentials.

        Returns:
            str: Current canonical issuer or empty when no gallery is linked.
        """
        return self._identity[0] if self._identity is not None else ""

    def manage_link(self) -> None:
        """Open the scoped grant controls for the exact current gallery issuer.

        Returns:
            None: Discovery is read-only; selected grants change only after an
                explicit browser authorization and the existing validation lane.
        """
        if self._scope_dialog is not None:
            self._scope_dialog.raise_()
            self._scope_dialog.activateWindow()
            return
        if self._authorization_active or not self._can_disconnect():
            return
        issuer = self._identity[0] if self._identity is not None else ''
        try:
            saved = self.connections.saved() if self.connections is not None else None
            if not issuer and saved is not None and saved.mode == 'linked':
                issuer = saved.issuer or ''
        except RuntimeError:
            self.status_message('Gallery connection is unavailable.')
            return
        issuer = linking_issuer(issuer or self.gallery_address)
        if not issuer:
            self.link()
            return
        try:
            active_target = self.connections.active() if self.connections is not None else None
            saved = self.connections.saved() if self.connections is not None else None
        except RuntimeError:
            self.status_message('Gallery connection is unavailable.')
            return
        try:
            store = desktop_upload_vault(self.profile)
            binding = saved_desktop_gallery_binding(
                self.profile / 'offline-link.json', issuer,
            )
        except (OSError, RuntimeError, ValueError):
            store = None
            binding = None
        self._scope_issuer = issuer
        self._scope_capabilities = frozenset()
        self._scope_saved_mode = saved
        self._scope_active_target = active_target
        self._scope_offline_storage_ready = store is not None and binding is None
        self._scope_offline_store_available = store is not None
        self._scope_offline_binding = binding
        self._scope_live_session = self._session
        self._scope_live_identity = self._identity
        self._build_scope_dialog()
        if self._scope_live_session is not None:
            self._populate_scope_checks(self._scope_live_session)
            self._scope_status.clear()
            self._sync_scope_controls()
            return
        self._scope_status.setText(tr(self.language, 'linking_requesting'))

        def inspect(
            cancelled: Event,
            publish: Callable[[tuple[str, frozenset[str]]], None],
        ) -> None:
            """Read the issuer's public linking capabilities without credentials.

            Args:
                cancelled (Event): Exact management-dialog discovery lifetime.
                publish (Callable[[tuple[str, frozenset[str]]], None]): Current
                    generation's GUI-thread result sink.
            Returns:
                None: Publishes only the validated issuer and bounded names.
            """
            capabilities = discover_linking_capabilities(issuer, cancelled)
            if cancelled.is_set():
                raise ReadCancelled
            publish((issuer, capabilities))

        self._scope_discovery.submit(inspect)

    def _build_scope_dialog(self) -> None:
        """Build the scope, secure-storage and grant-management controls.

        Returns:
            None: Shows only permissions represented by the shared linking owner.
        """
        dialog = QDialog(self.window())
        dialog.setObjectName('photosLinkManagementDialog')
        dialog.setWindowTitle(gettext('Manage link'))
        dialog.setWindowModality(Qt.WindowModality.WindowModal)
        dialog.setProperty('shellCard', True)
        dialog.setAttribute(Qt.WidgetAttribute.WA_DeleteOnClose)
        layout = QVBoxLayout(dialog)
        heading = QLabel(gettext('Manage link'))
        heading.setObjectName('heading')
        layout.addWidget(heading)
        issuer = QLabel(self._scope_issuer)
        issuer.setObjectName('photosLinkManagementIssuer')
        issuer.setTextFormat(Qt.TextFormat.PlainText)
        issuer.setWordWrap(True)
        layout.addWidget(issuer)
        explanation = QLabel(gettext(
            'Choose permissions for this connection. Optional secure storage '
            'requires separate browser consent and permits renewal for up to '
            '30 days, with a seven-day inactivity limit. The saved API key '
            'remains available; server grants may remain until revoked.',
        ))
        explanation.setObjectName('photosLinkManagementExplanation')
        explanation.setWordWrap(True)
        layout.addWidget(explanation)
        self._scope_checks = {}
        for key, text in (
            ('browse', gettext('Allow listing galleries')),
            ('upload', gettext('Allow gallery uploads')),
            ('read', gettext('Read gallery details')),
            ('create', gettext('Allow creating galleries')),
            ('visibility', tr(self.language, 'linking_visibility_action')),
            ('media', tr(self.language, 'linking_media_consent')),
            ('remember', gettext('Remember securely')),
        ):
            control = QCheckBox(text)
            control.setObjectName(f'photosLinkScope{key.title()}')
            control.setEnabled(False)
            control.toggled.connect(self._scope_controls_changed)
            layout.addWidget(control)
            self._scope_checks[key] = control
        self._scope_status = QLabel()
        self._scope_status.setObjectName('photosLinkManagementStatus')
        self._scope_status.setWordWrap(True)
        layout.addWidget(self._scope_status)
        actions = QHBoxLayout()
        self._scope_update_button = ActionButton(
            tr(self.language, 'linking_start'),
            self._submit_scope_request, primary=True,
        )
        self._scope_update_button.setObjectName('photosLinkScopeUpdate')
        actions.addWidget(self._scope_update_button)
        self._scope_disconnect_button = ActionButton(
            gettext('Disconnect'), self._disconnect_managed_link,
        )
        self._scope_disconnect_button.setObjectName('photosLinkDisconnect')
        actions.addWidget(self._scope_disconnect_button)
        actions.addStretch()
        layout.addLayout(actions)
        secondary = QHBoxLayout()
        self._scope_reconnect_button = ActionButton(
            gettext('Reconnect saved link'), self._reconnect_managed_link,
        )
        self._scope_reconnect_button.setObjectName('photosLinkReconnectSaved')
        secondary.addWidget(self._scope_reconnect_button)
        self._scope_forget_button = ActionButton(
            gettext('Forget saved link'), self._forget_managed_link,
        )
        self._scope_forget_button.setObjectName('photosLinkForgetSaved')
        secondary.addWidget(self._scope_forget_button)
        manage_browser = ActionButton(
            gettext('Manage gallery links'), self._open_managed_browser_grants,
        )
        manage_browser.setObjectName('photosLinkBrowserGrants')
        secondary.addWidget(manage_browser)
        secondary.addStretch()
        secondary.addWidget(ActionButton(gettext('Close'), dialog.reject))
        layout.addLayout(secondary)
        self._scope_dialog = dialog
        dialog.finished.connect(self._scope_dialog_finished)
        self._sync_scope_controls()
        dialog.show()

    def _populate_scope_checks(self, session: LinkingSession) -> None:
        """Reflect the exact active grant in read-only consent controls.

        Args:
            session (LinkingSession): Current live grant shown by the dialog.
        Returns:
            None: Never infers server permission from the saved upload target.
        """
        if not session.available():
            return
        grants = {
            'browse': session.can_list_galleries(),
            'upload': session.can_upload(),
            'read': session.can_read_gallery_detail(),
            'create': session.can_create_gallery(),
            'visibility': session.can_change_gallery_visibility(),
            'media': session.can_read_media(),
            'remember': 'offline_access' in session.approved_scope().split(),
        }
        for key, granted in grants.items():
            control = self._scope_checks[key]
            blocker = QSignalBlocker(control)
            control.setChecked(granted)
            del blocker

    def _receive_scope_capabilities(
        self, result: tuple[str, frozenset[str]],
    ) -> None:
        """Apply discovery only to the issuer and dialog that requested it.

        Args:
            result (tuple[str, frozenset[str]]): Canonical issuer and public
                capability names returned by the linking transport.
        Returns:
            None: Unsupported controls stay disabled and no authority is added.
        """
        issuer, capabilities = result
        if (self._scope_dialog is None or issuer != self._scope_issuer
                or self._session is not None or not self._scope_dialog.isVisible()):
            return
        self._scope_capabilities = capabilities
        browse = self._scope_checks.get('browse')
        if browse is not None and 'galleries:list' not in capabilities:
            browse.setChecked(False)
        media = self._scope_checks.get('media')
        if media is not None:
            media.setText(tr(
                self.language,
                'linking_admin_media_consent' if 'media:admin' in capabilities
                else 'linking_media_consent',
            ))
        if capabilities:
            self._scope_status.clear()
        else:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
        self._sync_scope_controls()

    def _scope_discovery_finished(self, failure: str) -> None:
        """Report a fixed discovery failure and refresh gated controls.

        Args:
            failure (str): Safe exception class from the bounded read lane.
        Returns:
            None: No discovery error text or remote response is displayed.
        """
        if self._scope_dialog is None:
            return
        if failure:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
        self._sync_scope_controls()

    def _scope_controls_changed(self, _checked: bool = False) -> None:
        """Apply Tk's permission dependencies before enabling submission.

        Args:
            _checked (bool): Toggle state supplied by Qt; current state is read
                from the owned controls.
        Returns:
            None: Dependent write/read scopes imply listing and visibility implies read.
        """
        if self._scope_live_session is None:
            dependent = any(
                self._scope_checks[key].isChecked()
                for key in ('upload', 'read', 'create', 'visibility', 'media')
            )
            if dependent:
                blocker = QSignalBlocker(self._scope_checks['browse'])
                self._scope_checks['browse'].setChecked(True)
                del blocker
            if self._scope_checks['visibility'].isChecked():
                blocker = QSignalBlocker(self._scope_checks['read'])
                self._scope_checks['read'].setChecked(True)
                del blocker
            if self._scope_checks['media'].isChecked():
                blocker = QSignalBlocker(self._scope_checks['remember'])
                self._scope_checks['remember'].setChecked(False)
                del blocker
        self._sync_scope_controls()

    def _scope_selection_matches(self) -> bool:
        """Match the mode and live target captured by this management dialog.

        Returns:
            bool: True only while both shared connection snapshots are unchanged.
        """
        if self.connections is None:
            return (
                self._scope_saved_mode is None
                and self._scope_active_target is None
            )
        try:
            return (
                self.connections.saved() == self._scope_saved_mode
                and self.connections.active() == self._scope_active_target
            )
        except RuntimeError:
            return False

    def _scope_binding_matches(self) -> bool:
        """Re-read the exact captured offline binding before local removal.

        Returns:
            bool: True only when the same hinted account/scope still exists.
        """
        binding = self._scope_offline_binding
        if binding is None or not self._scope_offline_store_available:
            return False
        try:
            current = saved_desktop_gallery_binding(
                self.profile / 'offline-link.json', self._scope_issuer,
            )
        except (OSError, RuntimeError, ValueError):
            return False
        return current == binding

    def _sync_scope_controls(self) -> None:
        """Gate each visible consent and action against live owner state.

        Returns:
            None: Current grants stay read-only until the live grant is disconnected.
        """
        if self._scope_dialog is None:
            return
        active = self._scope_live_session is not None
        selection_matches = self._scope_selection_matches()
        capabilities = self._scope_capabilities
        supported = {
            'browse': 'galleries:list' in capabilities,
            'upload': 'gallery:upload' in capabilities,
            'read': 'gallery:read' in capabilities,
            'create': 'galleries:create' in capabilities,
            'visibility': 'gallery:visibility' in capabilities,
            'media': 'media:read' in capabilities,
            'remember': ('offline_access' in capabilities
                         and self._scope_offline_storage_ready),
        }
        if active:
            same_live = (
                selection_matches
                and self._session is self._scope_live_session
                and self._identity == self._scope_live_identity
                and self._session is not None and self._session.available()
            )
            supported = {key: same_live for key in supported}
            disconnect_ready = same_live
        else:
            disconnect_ready = False
        for key, control in self._scope_checks.items():
            control.setEnabled(not active and supported[key])
        request_ready = bool(
            not active and selection_matches and capabilities
            and not self._scope_discovery.busy()
            and not self._authorization_active and not self.busy()
            and not self.transfer_busy()
        )
        if request_ready:
            try:
                self._current_requested_scope()
            except LinkingError:
                request_ready = False
        self._scope_update_button.setEnabled(request_ready)
        self._scope_disconnect_button.setEnabled(disconnect_ready)
        saved = self._scope_saved_mode
        same_saved_link = bool(
            selection_matches and saved is not None and saved.mode == 'linked'
            and saved.issuer == self._scope_issuer
        )
        binding = self._scope_offline_binding
        saved_offline = bool(
            same_saved_link and saved is not None and saved.scope
            and saved.scope.endswith(' offline_access')
            and self._scope_offline_store_available
            and binding is not None
            and (binding.issuer, binding.account_id, binding.scope)
            == (saved.issuer, saved.account_id, saved.scope)
            and self._scope_binding_matches()
        )
        self._scope_reconnect_button.setVisible(True)
        self._scope_reconnect_button.setEnabled(saved_offline and not active)
        self._scope_forget_button.setVisible(True)
        self._scope_forget_button.setEnabled(saved_offline and not active)

    def _current_requested_scope(self) -> str:
        """Resolve the current controls through the shared Tk/Qt scope mapper.

        Returns:
            str: Exact ordered scope for the explicit browser request.
        Raises:
            LinkingError: Selection, capabilities or native storage are unavailable.
        """
        return requested_linking_scope(
            list_galleries=self._scope_checks['browse'].isChecked(),
            upload=self._scope_checks['upload'].isChecked(),
            read_details=self._scope_checks['read'].isChecked(),
            create_galleries=self._scope_checks['create'].isChecked(),
            change_visibility=self._scope_checks['visibility'].isChecked(),
            read_media=self._scope_checks['media'].isChecked(),
            remember_securely=self._scope_checks['remember'].isChecked(),
            capabilities=self._scope_capabilities,
            visibility_ready=True,
            native_media_ready=True,
            offline_storage_ready=self._scope_offline_storage_ready,
        )

    def _submit_scope_request(self) -> None:
        """Start a new request only after the captured profile/target fences hold.

        Returns:
            None: Browser approval alone cannot select or replace the upload target.
        """
        dialog = self._scope_dialog
        if (dialog is None or self._session is not None
                or not self._can_disconnect()):
            return
        try:
            if not self._scope_selection_matches():
                raise RuntimeError('Connection selection changed.')
            scope = self._current_requested_scope()
        except (OSError, RuntimeError, ValueError, LinkingError):
            self._scope_status.setText(gettext(
                'Gallery connection is unavailable.',
            ))
            return
        issuer = linking_issuer(self._scope_issuer)
        if issuer != self._scope_issuer:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        self._scope_dialog = None
        self._scope_discovery.cancel()
        dialog.accept()
        uploads = 'gallery:upload' in scope.split()
        self._authorize(
            uploads, address_override=issuer, requested_scope=scope,
        )

    def _disconnect_managed_link(self) -> None:
        """Disconnect only the exact live grant shown in the dialog.

        Returns:
            None: Existing target, capture, and revocation guards remain authoritative.
        """
        if (self._scope_dialog is None or self._session is not self._scope_live_session
                or self._identity != self._scope_live_identity
                or not self._scope_selection_matches()
                or self._session is None or not self._session.available()
                or not self._can_disconnect()):
            return
        self._scope_dialog.accept()
        self._scope_dialog = None
        self.unlink()

    def _reconnect_managed_link(self) -> None:
        """Reconnect only the saved linked account captured when opened.

        Returns:
            None: Existing saved-target and scope verification remains in `_authorize`.
        """
        if self.connections is None or self._scope_saved_mode is None:
            return
        if not self._scope_selection_matches():
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        if not self._scope_binding_matches():
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        try:
            if self.connections.saved() != self._scope_saved_mode:
                self._scope_status.setText(gettext('Gallery connection is unavailable.'))
                return
        except RuntimeError:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        saved = self._scope_saved_mode
        if saved.mode != 'linked' or saved.issuer != self._scope_issuer:
            return
        captured_saved = saved
        if self._scope_dialog is not None:
            self._scope_dialog.accept()
            self._scope_dialog = None
        self._authorize(True, captured_saved)

    def _forget_managed_link(self) -> None:
        """Forget only the matching saved link through the existing confirmation.

        Returns:
            None: Native-vault removal and its writer fence remain in `forget_uploads`.
        """
        if self.connections is None or self._scope_saved_mode is None:
            return
        if (not self._scope_selection_matches()
                or not self._scope_binding_matches()):
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        try:
            if self.connections.saved() != self._scope_saved_mode:
                self._scope_status.setText(gettext('Gallery connection is unavailable.'))
                return
        except RuntimeError:
            self._scope_status.setText(gettext('Gallery connection is unavailable.'))
            return
        if self._scope_dialog is not None:
            self._scope_dialog.accept()
            self._scope_dialog = None
        self.forget_uploads()

    def _open_managed_browser_grants(self) -> None:
        """Open only the exact validated installation's grant-management page.

        Returns:
            None: No user-entered URL or unvalidated remote redirect is followed.
        """
        issuer = linking_issuer(self._scope_issuer + '/index.php')
        if issuer != self._scope_issuer:
            return
        try:
            if not webbrowser.open(issuer + '/index.php?page=app_linking_devices'):
                self._scope_status.setText(gettext('Could not open the gallery in your browser.'))
        except (OSError, RuntimeError):
            self._scope_status.setText(gettext('Could not open the gallery in your browser.'))

    def _scope_dialog_finished(self, _result: int) -> None:
        """Fence capability reads when the management dialog is dismissed.

        Args:
            _result (int): Native modal result; no grant state is inferred from it.
        Returns:
            None: Clears only this dialog's view-owned discovery generation.
        """
        self._scope_discovery.cancel()
        self._scope_dialog = None
        self._scope_checks.clear()
        self._scope_status = None
        self._scope_update_button = None
        self._scope_disconnect_button = None
        self._scope_reconnect_button = None
        self._scope_forget_button = None
        self._scope_issuer = ''
        self._scope_capabilities = frozenset()
        self._scope_saved_mode = None
        self._scope_active_target = None
        self._scope_offline_storage_ready = False
        self._scope_offline_store_available = False
        self._scope_offline_binding = None
        self._scope_live_session = None
        self._scope_live_identity = None

    def _sync_connection_state(self) -> None:
        """Show the single connect card or the active Photos workspace.

        Returns:
            None: Offline actions stay in shell menus while local or linked
                photo surfaces retain their existing owner and session.
        """
        active = self._identity is not None or self._local_photos_active
        connecting = self._authorization_active or (
            self._identity is None and not self.link_button.isEnabled()
        )
        detail = self._lightbox_layout_restore is not None
        self._connect_card_outer.setVisible(not active and not detail)
        self._connect_card.setVisible(not active and not detail)
        self._center_toolbar.setVisible(active and not detail)
        self._split.setVisible(active or detail)
        self._gallery_panel.setVisible(
            self._identity is not None
            and self._gallery_tree.topLevelItemCount() > 0 and not detail,
        )
        offline_prompt = gettext("Link a gallery or open local photos.")
        self.status.setVisible(
            self._authorization_dialog is None
            and (connecting or active
             or (bool(self.status.text()) and self.status.text() != offline_prompt))
            and not detail,
        )
        self._sync_folder_section()

    def resume_saved_browse(self) -> None:
        """Restore the most recent verified Photos grant after native readiness.

        Returns:
            None: Requests at most one saved browsing grant on the read lane.
                Reopening Photos leaves upload admission with its explicit owner.
        """
        if (sys.platform not in ('win32', 'darwin') or self._session is not None
                or self._local_photos_active or self._load.busy()
                or self._authorization_dialog is not None):
            return
        recent = self._recent_gallery_addresses
        if self.connections is None and not recent:
            return
        self._startup_browse = True
        self._startup_saved_mode = None
        self.link_button.setText(tr(self.language, 'linking_start'))

        def restore(
            cancelled: Event,
            publish: Callable[[
                LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress
                | SavedLinkRecovery
            ], None],
        ) -> None:
            """Resolve the stored issuer and restore it without browser consent.

            Args:
                cancelled (Event): Lifetime of this startup read.
                publish (Callable[[LinkedPhotos | LiveSiteCatalogue | PhotoCatalogue | LinkingProgress | SavedLinkRecovery], None]):
                    Current UI owner's catalogue sink.
            Returns:
                None: Mounts a verified account or publishes exact saved recovery.
            """
            try:
                saved = self.connections.saved() if self.connections is not None else None
            except RuntimeError:
                saved = None
            issuer = saved.issuer if saved is not None and saved.mode == 'linked' else None
            if not issuer and recent:
                issuer = recent[0]
            if issuer:
                publish(LinkingProgress(gettext('Checking the securely saved connection…')))
                try:
                    self._publish_saved_browse(issuer, cancelled, publish)
                except OfflineRefreshPending:
                    if saved is None or saved.mode != 'linked':
                        raise
                    if cancelled.is_set():
                        return
                    binding = saved_desktop_gallery_binding(
                        self.profile / 'offline-link.json', issuer,
                    )
                    if (binding is None or binding.issuer != saved.issuer
                            or binding.account_id != saved.account_id
                            or binding.scope != saved.scope):
                        raise
                    QT_PHOTOS_LOGGER.warning(
                        'Qt saved-link startup recovery offered: '
                        'phase=restore; reason=OfflineRefreshPending',
                    )
                    publish(SavedLinkRecovery(saved))

        self._load.submit(restore)
