# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/ui/connection.py
# Module Type: Windows Client Interface Behavior
# Purpose: Own linking connection lifecycle, renewal and unlink callbacks.
# Responsibilities:
#   - Start and reconcile browser-approved linking and offline renewal work.
#   - Keep upload target changes bound to the currently verified session.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Connection lifecycle behavior for the gallery linking manager."""

import sys
import threading
import time
import webbrowser
from typing import Callable

from uploader.localization import gettext
from uploader.ui_shell import messagebox
from .. import (
    GALLERIES_SCOPE, ROLLING_SCOPE_SUFFIX, LinkingAttempt, LinkingError, LinkingHttpsError,
    LinkingResponseError, LinkingUnavailable, LinkingRefreshRejected,
    LinkingReconnectRequired, LinkingScheduledPublication, LinkingSession,
    scope_base,
)
from ...credential_store import CredentialStoreUnavailable, NativeCredentialStore
from ...desktop_connection import (
    DesktopLinkedSite as _LinkedSite, desktop_upload_vault,
    persist_browser_gallery_grant, remember_desktop_transient_link,
    revoke_saved_gallery_grant,
)
from ...linked_upload import LinkedUploadTarget, verify_linked_upload_target
from . import dialog as _dialog
from ..core import LINKING_KEEPALIVE_SECONDS
from .polling import _PhotosUploadUpgrade
from ...offline_linking import (
    OfflineBinding, discard_uncertain_refresh, enable_rolling_renewal, load_offline_binding,
    remove_saved_offline_link, restore_saved_offline_session,
    revoke_saved_refresh, save_offline_binding, store_approved_refresh,
)
from ...session_resume import delete_transient

class LinkingConnectionMixin:
    """Own renewal, target readiness and disconnect worker callbacks."""

    def _refresh(self, issuer: str) -> None:
        """Retire expired links and update controls for the selected identity.

        Args:
            issuer (str): Canonical issuer displayed by the current window.

        Returns:
            None: Clears expired access and enables only current operations.
        """
        parked = []
        for site in self._parked_links:
            if site.session.available():
                parked.append(site)
            else:
                site.session.close()
                if (self._active_upload_target is not None
                        and self._active_upload_target.session is site.session):
                    self._notify_target_changed()
        self._parked_links = tuple(parked)
        if self._session is not None and not self._session.available():
            session = self._session
            session.close()
            self._clear_session_display()
            self._gallery_status.set(gettext('Gallery link expired.'))
            self._status.set(gettext('Gallery link expired.'))
            if (self._active_upload_target is not None
                    and self._active_upload_target.session is session):
                self._notify_target_changed()
        if self._window is None:
            return
        busy = self._worker is not None and self._worker.is_alive()
        capabilities = self._capabilities
        can_request = not busy and self._session is None and 'linking' in capabilities
        list_grant = (self._session is not None and self._session_issuer == issuer
                      and self._session.can_list_galleries())
        can_list = list_grant and not busy
        self._browse_check.state(
            ['!disabled'] if can_request and 'galleries:list' in capabilities else ['disabled'],
        )
        self._upload_check.state(
            ['!disabled'] if can_request and 'gallery:upload' in capabilities else ['disabled'],
        )
        self._read_check.state(
            ['!disabled'] if can_request and 'gallery:read' in capabilities else ['disabled'],
        )
        self._create_check.state(
            ['!disabled'] if can_request and 'galleries:create' in capabilities else ['disabled'],
        )
        self._visibility_check.state(
            ['!disabled'] if (_dialog.LINKING_VISIBILITY_READY and can_request
                              and 'gallery:visibility' in capabilities) else ['disabled'],
        )
        self._media_check.configure(text=self._text(
            'linking_admin_media_consent' if 'media:admin' in capabilities
            else 'media_consent',
        ))
        self._media_check.state(
            ['!disabled'] if (_dialog.LINKING_NATIVE_MEDIA_READY and can_request
                              and 'media:read' in capabilities) else ['disabled'],
        )
        can_create = (can_list and self._session is not None
                      and self._session.can_create_gallery())
        created = self._created_gallery
        self._create_button.state(
            ['!disabled'] if can_create else ['disabled'],
        )
        self._create_status_button.state(['!disabled'] if can_create else ['disabled'])
        self._create_resume_button.state(
            ['!disabled'] if can_create else ['disabled'],
        )
        self._create_abandon_button.state(
            ['!disabled'] if can_create else ['disabled'],
        )
        can_use_created = (_dialog.LINKING_UPLOAD_TARGET_READY and _dialog.LINKING_CREATED_TARGET_READY
                           and not busy and created is not None
                           and self._session is created[1]
                           and self._session_issuer == issuer == created[2]
                           and self._session_user_id == created[3]
                           and created[1].can_upload())
        self._use_created_button.state(
            ['!disabled'] if can_use_created else ['disabled'],
        )
        can_ack_created = (not busy and created is not None
                           and self._session is created[1]
                           and self._session_issuer == issuer == created[2]
                           and self._session_user_id == created[3]
                           and created[1].available())
        self._ack_created_button.state(
            ['!disabled'] if can_ack_created else ['disabled'],
        )
        self._offline_check.state(
            ['disabled'] if (not can_request or sys.platform != 'win32'
                             or 'offline_access' not in capabilities
                             or self._media_var.get()
                             or self._offline_store is None
                             or self._offline_binding is not None)
            else ['!disabled'],
        )
        self._restore_button.state(
            ['!disabled'] if (not busy and self._session is None
                              and self._offline_store is not None
                              and self._offline_binding is not None)
            else ['disabled'],
        )
        self._discard_button.state(
            ['!disabled'] if (not busy and self._offline_store is not None
                              and self._offline_binding is not None)
            else ['disabled'],
        )
        self._gallery_refresh_button.state(['!disabled'] if can_list else ['disabled'])
        self._gallery_next_button.state(
            ['!disabled'] if (can_list and self._gallery_page is not None
                              and self._gallery_page.next_after is not None)
            else ['disabled'],
        )
        upload_grant = self._session is not None and self._session.can_upload()
        select_label = self._text(
            'linking_use_for_upload' if upload_grant else 'linking_select_gallery',
        )
        self._gallery_select_button.configure(text='✓ ' + select_label)
        selected = self._gallery_choice.current()
        can_select = (can_list and self._gallery_page is not None
                      and 0 <= selected < len(self._gallery_page.galleries))
        primary_ready = can_select and (_dialog.LINKING_UPLOAD_TARGET_READY or not upload_grant)
        self._gallery_select_button.state(
            ['!disabled'] if primary_ready else ['disabled'],
        )
        self._recovery_button.state(
            ['!disabled'] if (not busy and self.selected_upload_target() is not None
                              and self._session is self._active_upload_target.session
                              and self._session_issuer == self._active_upload_target.issuer
                              and self._session_user_id == self._active_upload_target.user_id
                              and self._recovery_probe is not None
                              and self._recovery_publish is not None)
            else ['disabled'],
        )
        self._review_button.state(
            ['!disabled'] if (not busy and self._recovery_review is not None
                              and self.selected_upload_target() is not None
                              and self._session is self._active_upload_target.session)
            else ['disabled'],
        )
        can_read = (can_list and self._session is not None
                    and self._session.can_read_gallery_detail()
                    and self._gallery_page is not None
                    and bool(self._gallery_page.galleries))
        self._detail_button.state(['!disabled'] if can_read else ['disabled'])
        snapshot = self._detail_snapshot
        can_change = (_dialog.LINKING_VISIBILITY_READY and can_read and snapshot is not None
                      and self._session is snapshot[1]
                      and self._session_issuer == issuer == snapshot[2]
                      and self._session_user_id == snapshot[3]
                      and self._session.can_change_gallery_visibility()
                      and self._gallery_page is not None
                      and 0 <= selected < len(self._gallery_page.galleries)
                      and self._gallery_page.galleries[selected].gallery_id
                      == snapshot[0].gallery_id)
        self._visibility_choice.state(
            ['!disabled', 'readonly'] if can_change else ['disabled'],
        )
        self._visibility_button.state(
            ['!disabled'] if can_change else ['disabled'],
        )
        self._gallery_choice.enable(input_ready=list_grant, results_ready=can_list)
        self._start_button.state(
            ['disabled'] if not can_request or not issuer
            else ['!disabled'],
        )
        self._browser_button.state(
            ['!disabled'] if self._browser_needs_launch else ['disabled'],
        )
        self._revoke_button.state(
            ['!disabled'] if not busy and (self._session is not None
                                          or self._offline_binding is not None)
            else ['disabled'],
        )

    def _start(
        self, issuer: str, *, direct: bool = False, replace_photos: bool = False,
        restart: bool = False, use_native_storage: bool = True,
    ) -> None:
        """Start one browser request while retaining any other live site.

        Args:
            issuer (str): Already displayed and validated installation issuer.
            direct (bool): Request listing from the main action, with renewal and
                upload and presentation when both ends support those capabilities.
            replace_photos (bool): Request a new Admin Photos grant for the
                current site without closing its existing approved session.
            restart (bool): Allow fresh approval to replace a saved credential
                for the exact verified issuer, Admin and scope.
            use_native_storage (bool): Allow direct consent to retain a grant in
                the native vault. False uses only the live browser approval.

        Returns:
            None: Starts the worker or shows a generic local failure.
        """
        if (not issuer or (self._session is not None
                           and (not direct or (
                               self._session_issuer == issuer and not replace_photos
                           )))
                or self._worker is not None and self._worker.is_alive()):
            return
        upload_target = self._active_upload_target if replace_photos else None
        if upload_target is not None and (
            upload_target.issuer != issuer or upload_target.user_id != self._session_user_id
        ):
            upload_target = None
        if upload_target is not None:
            refusal = self._target_refusal(upload_target.binding())
            if refusal is not None or self._commit_linked_target is None:
                self._status.set(refusal or gettext('Linked upload mode cannot be verified.'))
                return
        try:
            scope = GALLERIES_SCOPE if direct else self._requested_scope()
            previous = self._session if replace_photos else None
            previous_account = (
                self._session_user_id if previous is not None else None
            )
            previous_scope = (
                previous.approved_scope() if previous is not None else None
            )
            upload_scope = (
                upload_target.session.approved_scope()
                if upload_target is not None else None
            )
            prefer_upload = direct and (
                (previous is not None and previous.can_upload())
                or (_dialog.LINKING_UPLOAD_TARGET_READY
                    and self._upload_workspace_ready is not None
                    and self._upload_workspace_ready())
            )
            attempt = LinkingAttempt(issuer, scope=scope)
        except Exception:  # noqa: BLE001
            self._status.set(gettext('Could not start a secure linking attempt.'))
            return
        owner = object()
        cancel = threading.Event()
        offline_store = None if direct else self._offline_store
        self._owner, self._attempt, self._cancel = owner, attempt, cancel
        self._direct_owner = owner if direct else None
        if direct and not replace_photos:
            self._website_request_owner = owner
        self._clear_handoff()
        self._status.set(self._text('linking_requesting'))

        def worker() -> None:
            """Request, wait, and validate identity without accessing Tk.

            Returns:
                None: Queues bounded results and closes the attempt.
            """
            session = None
            rolling_enabled = False
            failure = 'request_failed'
            try:
                store = offline_store
                if direct and use_native_storage:
                    store = self._native_offline_store()
                if direct and store is not None:
                    handoff = attempt.push_request(
                        prefer_upload=prefer_upload, prefer_rolling=True,
                        prefer_presentation=True, prefer_admin_media=True,
                        require_admin_media=replace_photos,
                    )
                else:
                    handoff = attempt.push_request(
                        prefer_upload=prefer_upload, prefer_presentation=direct,
                        prefer_admin_media=direct, require_admin_media=replace_photos,
                    )
                if cancel.is_set():
                    return
                self._events.put((owner, 'handoff', handoff))
                failure = 'callback_failed'
                if attempt.wait_for_callback() != 'approved':
                    self._events.put((owner, 'denied', None))
                    return
                self._events.put((owner, 'exchanging', None))
                failure = 'exchange_failed'
                offline = (attempt.requests_offline_access() if direct
                           else scope.endswith(' offline_access'))
                if offline:
                    exchange_started = time.monotonic()
                    session, refresh, refresh_lifetime = attempt.exchange_offline_once()
                else:
                    session = attempt.exchange_once()
                    refresh, refresh_lifetime = None, None
                identity = session.identity()
                if previous_account is not None and identity.user_id != previous_account:
                    raise LinkingError('Admin Photos approval used a different account.')
                if cancel.is_set():
                    return
                approved_session = session

                def remember_transient() -> None:
                    """Remember this approved temporary listing in macOS Keychain.

                    Returns:
                        None: Keeps the live grant available if caching fails.
                    """
                    if not offline and use_native_storage and (not direct or store is not None):
                        remember_desktop_transient_link(approved_session, identity.user_id)

                if upload_target is not None and (
                    offline or upload_scope is None
                    or not upload_scope.endswith(' offline_access')
                ):
                    approved_scope = session.approved_scope()
                    verified = verify_linked_upload_target(
                        session, issuer, identity.user_id, approved_scope,
                        upload_target.gallery_id, cancel,
                    )

                    def activate(commit: Callable[[], bool]) -> None:
                        """Publish selected-target authority on the Tk owner.

                        Args:
                            commit (Callable[[], bool]): Exact host mode publication.

                        Returns:
                            None: The upload scope and optional renewal are committed.
                        """
                        if offline:
                            if store is None or refresh is None or refresh_lifetime is None:
                                raise LinkingError('Secure offline storage is unavailable.')
                            binding = OfflineBinding(issuer, identity.user_id, approved_scope)
                            remaining = int(exchange_started + refresh_lifetime - time.monotonic())
                            preceding_scope = previous_scope
                            if (preceding_scope is None
                                    or not preceding_scope.endswith(' offline_access')):
                                preceding_scope = upload_scope
                            self._save_photos_upgrade(
                                store, binding, preceding_scope, refresh, remaining, commit,
                            )
                            path = self._offline_binding_path(binding)
                            enable_rolling_renewal(approved_session, store, path, binding)
                            self._offline_binding, self._offline_store = binding, store
                            if previous is not None:
                                previous.disable_renewal()
                            if upload_target.session is not previous:
                                upload_target.session.disable_renewal()
                        elif not commit():
                            raise LinkingError('Linked upload mode could not be verified.')
                        remember_transient()

                    self._events.put((owner, 'photos_upload_upgrade', _PhotosUploadUpgrade(
                        upload_target, verified, identity, activate,
                    )))
                    session = None
                    return
                remember_transient()
                if offline:
                    failure = 'save_failed'
                    if store is None or refresh is None or refresh_lifetime is None:
                        raise LinkingError('Secure offline storage is unavailable.')
                    approved_scope = session.approved_scope() if direct else scope
                    binding = OfflineBinding(issuer, identity.user_id, approved_scope)
                    path = self._offline_binding_path(binding)
                    remaining = int(exchange_started + refresh_lifetime - time.monotonic())
                    if cancel.is_set() or remaining <= 0 or not session.available():
                        raise LinkingError('Offline connection is unavailable.')
                    if previous is not None:
                        if not session.can_upload() and previous.can_upload():
                            raise LinkingError(
                                'A read-only grant cannot replace the saved upload grant.',
                            )
                        self._save_photos_upgrade(
                            store, binding, previous_scope, refresh, remaining,
                        )
                        previous.disable_renewal()
                        self._events.put((owner, 'offline_saved', (binding, store)))
                    elif (not session.can_upload()
                          and approved_scope.endswith(ROLLING_SCOPE_SUFFIX)
                          and scope_base(approved_scope) == GALLERIES_SCOPE):
                        binding = persist_browser_gallery_grant(
                            self._offline_path.parent, store, issuer, identity,
                            session, refresh, remaining, cancel,
                        )
                        rolling_enabled = True
                        self._events.put((owner, 'offline_saved', (binding, store)))
                    else:
                        with store.binding_transaction(issuer, identity.user_id):
                            if (restart
                                    and load_offline_binding(path, issuer) == binding):
                                # Fresh consent for this exact binding can replace
                                # a refresh whose previous outcome was uncertain.
                                discard_uncertain_refresh(store, issuer, identity.user_id)
                            else:
                                # A new hint survives interrupted vault persistence.
                                save_offline_binding(path, binding)
                            self._events.put((owner, 'offline_saved', (binding, store)))
                            store_approved_refresh(
                                store, issuer, identity.user_id, approved_scope,
                                refresh, remaining,
                            )
                    if not rolling_enabled:
                        enable_rolling_renewal(session, store, path, binding)
                if cancel.is_set():
                    return
                connected_session = session
                self._events.put((owner, 'connected', (connected_session, identity, issuer)))
                session = None
                self._queue_initial_gallery_page(owner, connected_session, cancel)
            except LinkingResponseError as error:
                if not cancel.is_set():
                    self._events.put((owner, 'response_error', error))
            except LinkingHttpsError:
                if not cancel.is_set():
                    self._events.put((owner, 'https_failed', None))
            except LinkingUnavailable as error:
                if not cancel.is_set():
                    self._events.put((owner, 'unavailable', error))
            except Exception:  # noqa: BLE001
                if not cancel.is_set():
                    self._events.put((owner, failure, None))
            finally:
                if session is not None:
                    session.close()
                attempt.close()

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryIdentityLink', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            attempt.close()
            self._worker = None
            self._status.set(gettext('Could not start a secure linking attempt.'))
            return
        self._refresh(self._session_issuer if self._session is not None else issuer)
        self._schedule_poll()

    def _remove_saved_link(self, issuer: str, *, reconnect: bool) -> None:
        """Detach an expired saved link without depending on a live bearer.

        Args:
            issuer (str): Exact canonical address selected by the user.
            reconnect (bool): Continue the explicit Connect action with fresh
                browser consent after the local credential is removed.
        Returns:
            None: Preserves recent addresses, pending originals and destinations;
                remote revocation is best effort and never blocks local recovery.
        """
        if self._worker is not None and self._worker.is_alive():
            return
        for site in self._live_links():
            if site.issuer == issuer:
                site.session.close()
        if self._session_issuer == issuer:
            if self._session is not None:
                self._session.close()
            self._clear_session_display()
        self._parked_links = tuple(site for site in self._parked_links
                                   if site.issuer != issuer)
        target = self._active_upload_target
        if target is not None and target.issuer == issuer:
            target.session.close()
            self._active_upload_target = None
            self._notify_target_changed()
        owner = object()
        self._owner = self._direct_owner = owner
        if reconnect:
            self._website_request_owner = owner
        self._current_issuer = issuer
        self._status.set(self._text('linking_reconnecting' if reconnect
                                   else 'linking_disconnecting'))

        def worker() -> None:
            """Remove the exact local binding before requesting fresh approval.

            Returns:
                None: Reports verified local removal independently of the server.
            """
            try:
                binding = self._saved_offline_binding(issuer)
                if binding is not None:
                    store = self._native_offline_store()
                    if store is None:
                        raise LinkingError('Secure storage is unavailable.')
                    record = remove_saved_offline_link(
                        store, self._offline_binding_path(binding), binding,
                        capture_refresh=True,
                    )
                    # Reauthorization must not wait on an unreachable server.
                    # A separate best-effort revocation owns only this old token.
                    if record is not None:
                        def revoke() -> None:
                            """Try closing the old grant without blocking reconnect.

                            Returns:
                                None: Server failure leaves only its old grant.
                            """
                            try:
                                revoke_saved_refresh(binding, record)
                            except (RuntimeError, OSError):
                                pass
                        try:
                            threading.Thread(
                                target=revoke, name='PHPGalleryOldLinkRevoke', daemon=True,
                            ).start()
                        except RuntimeError:
                            pass
                if sys.platform == 'darwin':
                    delete_transient(issuer)
                self._events.put((owner, 'saved_link_removed', (issuer, reconnect)))
            except (RuntimeError, OSError):
                self._events.put((owner, 'saved_link_remove_error', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryReconnectCleanup', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._status.set(self._text('linking_local_remove_failed'))
            return
        self._schedule_poll()

    def _restore_offline(
        self, *, silent: bool = False, approve_if_expired: bool = False,
    ) -> None:
        """Rotate one approved saved link and publish its verified site.

        Args:
            silent (bool): Keep startup restoration from raising the main window.
            approve_if_expired (bool): Continue an explicit Link action with
                browser approval after definite rejection or a failed native read
                before rotation.

        Returns:
            None: Starts a fenced background reconnect when available.
        """
        binding, store = self._offline_binding, self._offline_store
        if (binding is None or store is None
                or (not silent and binding.issuer != self._current_issuer)
                or self._worker is not None and self._worker.is_alive()):
            return
        path = self._offline_binding_path(binding)
        owner = object()
        cancel = threading.Event()
        self._owner, self._cancel = owner, cancel
        if self._window is None:
            self._direct_owner = owner
        if silent:
            self._website_request_owner = owner
        self._status.set(gettext('Checking the securely saved connection…'))

        def worker() -> None:
            """Rotate and verify the same identity without touching Tk.

            Returns:
                None: Queues a verified session or generic recovery notice.
            """
            session = None
            try:
                restored = restore_saved_offline_session(store, path, binding, cancel)
                if restored is None:
                    self._events.put((owner, 'offline_unavailable', None))
                    return
                session, identity = restored
                connected_session = session
                self._events.put((
                    owner, 'resumed' if silent else 'connected',
                    (connected_session, identity, binding.issuer),
                ))
                session = None
                self._queue_initial_gallery_page(owner, connected_session, cancel)
            except LinkingRefreshRejected:
                self._events.put((
                    owner, 'offline_expired', (binding.issuer, approve_if_expired),
                ))
            except CredentialStoreUnavailable:
                if not cancel.is_set():
                    self._events.put((
                        owner, 'saved_storage_unavailable', (binding.issuer, approve_if_expired),
                    ))
            except Exception:  # noqa: BLE001
                self._events.put((
                    owner, 'offline_error', (binding.issuer, approve_if_expired),
                ))
            finally:
                if session is not None:
                    session.close()

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryOfflineReconnect', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._status.set(gettext('Could not start the secure reconnect.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _discard_offline(self) -> None:
        """Explicitly forget one local credential without claiming revocation.

        Returns:
            None: Starts local cleanup after an explicit user confirmation.
        """
        binding, store = self._offline_binding, self._offline_store
        if (binding is None or store is None
                or self._worker is not None and self._worker.is_alive()):
            return
        if not messagebox.askyesno(
            gettext('Forget saved link'),
            gettext(
                "Remove the saved local credential? The current session remains active. "
                "This does not revoke the server grant. Manage gallery links to "
                "review or revoke it."
            ),
            parent=self._window,
        ):
            return
        path = self._offline_binding_path(binding)
        owner = object()
        self._owner = owner
        self._status.set(gettext('Removing the saved local link…'))

        def worker() -> None:
            """Discard only the selected local binding and credential.

            Returns:
                None: Queues success or an explicit recovery notice.
            """
            try:
                remove_saved_offline_link(store, path, binding)
                self._events.put((owner, 'offline_discarded', binding))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'offline_error', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryOfflineForget', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._status.set(gettext('Could not start local removal.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _open_browser(self) -> None:
        """Open the exact unlaunched browser approval page for a live handle.

        Returns:
            None: Reports approval progress or keeps the live prompt retryable
                after an unsuccessful launch. Leaves an opened request alone.
        """
        if not self._browser_needs_launch:
            return
        handoff = self._handoff
        if handoff is None or time.monotonic() >= handoff.expires_at:
            self._clear_handoff()
            self._status.set(self._text('linking_expired'))
            self._cancel.set()
            self._owner = None
            if self._attempt is not None:
                self._attempt.close()
            return
        try:
            opened = webbrowser.open(handoff.authorization_url)
        except Exception:  # noqa: BLE001
            opened = False
        if not opened:
            if self._window is None and self._address_prompt is None:
                self._status.set(gettext('Could not open the browser. Try connecting again.'))
                self._clear_handoff()
                self._cancel.set()
                self._owner = None
                if self._attempt is not None:
                    self._attempt.close()
            else:
                self._status.set(self._text(
                    'linking_address_browser_retry' if self._address_prompt is not None
                    else 'linking_browser_retry',
                ))
            return
        # The browser claim is one-use. A second tab can invalidate consent.
        self._browser_needs_launch = False
        self._status.set(self._text('linking_approve_browser'))

    def _open_devices(self) -> None:
        """Open the fixed gallery page for reviewing and revoking grants.

        Returns:
            None: Opens only the previously validated installation.
        """
        issuer = self._session_issuer or self._current_issuer
        if not issuer:
            self._status.set(gettext('Enter a valid gallery address first.'))
            return
        try:
            opened = webbrowser.open(
                issuer + '/index.php?page=app_linking_devices',
            )
        except Exception:  # noqa: BLE001
            opened = False
        if not opened:
            self._status.set(gettext('Could not open the gallery in your browser.'))

    def unlink(self, issuer: str | None = None) -> None:
        """Disconnect one selected live link and request server revocation.

        Args:
            issuer (str | None): Exact live address, or None for the current site.

        Returns:
            None: Starts one revocation or reports why the link cannot close.
                The site remains available in recent addresses.
        """
        address = (self._session_issuer or self._current_issuer) if issuer is None else issuer
        site = self._live_link(address)
        if site is None:
            if address:
                self._remove_saved_link(address, reconnect=False)
            return
        session = site.session
        if self._worker is not None and self._worker.is_alive():
            self._status.set(gettext('Wait for the current gallery request to finish.'))
            return
        target = self._active_upload_target
        disconnects_upload = target is not None and target.session is session
        if disconnects_upload:
            # Disconnecting the link leaves its saved destination available
            # for reconnect and recovery, including with an unfinished queue.
            refusal = self._target_refusal(target.binding())
            if refusal is not None:
                self._status.set(refusal)
                return
        owner = object()
        self._owner = owner
        self._direct_owner = owner if self._window is None else None
        self._status.set(gettext('Removing the saved connection…'))

        def cleanup_worker() -> None:
            """Remove the matching vault grant and revoke its latest credential.

            Returns:
                None: Queues local cleanup and any refresh revocation outcome.
            """
            try:
                if sys.platform == 'darwin':
                    delete_transient(site.issuer)
                binding = self._saved_offline_binding(site.issuer)
                revoked = None
                if binding is not None and binding.account_id == site.user_id:
                    store = self._native_offline_store()
                    if store is None:
                        raise LinkingError('Secure storage is unavailable.')
                    revoked = revoke_saved_gallery_grant(
                        self._offline_path.parent, site.issuer, site.user_id,
                        binding, store,
                    )
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'unlink_cleanup_error', None))
                return
            self._events.put((owner, 'unlink_vault_cleared', (site, revoked)))

        self._worker = threading.Thread(
            target=cleanup_worker, name='PHPGalleryUnlinkVaultCleanup', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._status.set(gettext('Could not start secure unlink cleanup.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _continue_unlink(self, site: _LinkedSite, revoked: bool | None) -> None:
        """Detach one link after its matching vault item was removed.

        Args:
            site (_LinkedSite): Exact live site whose local vault item was removed.
            revoked (bool | None): Refresh revocation result, or None when
                the remaining access bearer still needs a revoke request.

        Returns:
            None: Reports the revoke outcome or starts access revocation.
        """
        owner = self._owner
        if owner is None:
            return
        session = site.session
        if (self._offline_binding is not None
                and self._offline_binding.issuer == site.issuer
                and self._offline_binding.account_id == site.user_id):
            self._offline_binding = None
        target = self._active_upload_target
        disconnects_upload = target is not None and target.session is session
        if disconnects_upload:
            self._active_upload_target = None
        if self._session is session:
            self._clear_session_display()
            self._gallery_status.set('')
        else:
            self._parked_links = tuple(link for link in self._parked_links
                                       if link.session is not session)
        self._status.set(gettext('Unlinking gallery…'))
        if revoked is not None:
            session.close()
            self._status.set(
                gettext('Gallery unlinked.') if revoked else
                gettext('Unlinked locally. Check Manage link to confirm server revocation.'),
            )
            if disconnects_upload:
                self._notify_target_changed()
            return

        def worker() -> None:
            """Send revocation once and clear the local bearer.

            Returns:
                None: Queues an acknowledgement or an uncertain outcome.
            """
            try:
                session.revoke()
                self._events.put((owner, 'revoked', None))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'revoke_error', None))
            finally:
                session.close()

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryIdentityRevoke', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            session.close()
            self._worker = None
            self._status.set(gettext('Unlinked locally. Check Manage link to revoke access.'))
        else:
            self._refresh(self._current_issuer)
            self._schedule_poll()
        if disconnects_upload:
            self._notify_target_changed()

    def _clear_handoff(self) -> None:
        """Forget the private request handle without claiming memory erasure.

        Returns:
            None: Clears the correlation handle and its pending browser launch.
        """
        self._handoff = None
        self._browser_needs_launch = False

    def _abandon_link_attempt(self) -> None:
        """Close the current approval and reject its queued worker results.

        Returns:
            None: Discards in-flight approval state and leaves verified links
                and upload selection available.
        """
        self._cancel.set()
        if self._attempt is not None:
            self._attempt.close()
            self._attempt = None
        self._clear_handoff()
        self._owner = None
        self._direct_owner = None
        self._website_request_owner = None
