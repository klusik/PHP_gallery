# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/ui/polling.py
# Module Type: Windows Client Interface Behavior
# Purpose: Own event polling and linked-site keepalive callbacks.
# Responsibilities:
#   - Apply queued worker outcomes only on the Tk owner thread.
#   - Schedule bounded polling and refresh live linked-site activity.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Tk-owner polling behavior for gallery linking."""

import queue
import threading
import time
from typing import Callable, NamedTuple

from uploader.localization import gettext
from uploader.ui_shell import messagebox
from .. import (
    LINKING_KEEPALIVE_SECONDS, LinkedGallery, LinkedGalleryDetail,
    LinkedGalleryPage, LinkedIdentity, LinkingResponseError, LinkingUnavailable,
    PushedAuthorization,
)
from ...linked_create import BoundCreateReceipt, CreateIntent
from ...linked_upload import LinkedUploadTarget
from ...desktop_connection import (
    DesktopLinkedSite as _LinkedSite, verify_live_gallery_activity,
)
from . import dialog as _dialog
from .dialog import (
    LINKING_POLL_INTERVAL_MS, LINKING_IDLE_POLL_INTERVAL_MS,
    LINKING_CREATED_TARGET_READY, LINKING_UPLOAD_TARGET_READY,
    _visibility_labels,
)
from .picker_ui import _gallery_label
from ...offline_linking import OfflineBinding

class _PhotosUploadUpgrade(NamedTuple):
    """Carry verified replacement authority for the existing upload target.

    Attributes:
        previous (LinkedUploadTarget): Exact selected target at consent start.
        verified (LinkedUploadTarget): Same gallery verified under the new scope.
        identity (LinkedIdentity): Admin identity verified under the new scope.
        activate (Callable[[Callable[[], bool]], None]): Publish renewal and
            the callback's exact mode together on the Tk owner.
    """

    previous: LinkedUploadTarget
    verified: LinkedUploadTarget
    identity: LinkedIdentity
    activate: Callable[[Callable[[], bool]], None]

class _PhotosWatchStart(NamedTuple):
    """Carry a verified upload target and its temporary capture authority.

    Attributes:
        target (LinkedUploadTarget): Verified gallery and repr-hidden Watcher key.
        start (Callable[[LinkedUploadTarget], bool]): Start the admitted Watcher.
        failure (Callable[[str], None]): Report a refusal on the Watcher screen.
        still_requested (Callable[[], bool] | None): Tk-side request validity
            check before publishing the selected gallery.
    """

    target: LinkedUploadTarget
    start: Callable[[LinkedUploadTarget], bool]
    failure: Callable[[str], None]
    still_requested: Callable[[], bool] | None = None

class LinkingPollingMixin:
    """Apply linked worker outcomes and keep active galleries available."""

    def _schedule_poll(self, delay_ms: int = _dialog.LINKING_POLL_INTERVAL_MS) -> None:
        """Schedule one queue poll on the Tk thread while work is pending.

        Args:
            delay_ms (int): Delay in milliseconds before the next queue poll.

        Returns:
            None: Keeps at most one polling timer active.
        """
        if not self._polling and not self._shutting_down:
            self._polling = True
            self._root.after(delay_ms, self._poll)

    def _poll(self) -> None:
        """Deliver worker events without exposing their contents to logs.

        Returns:
            None: Closes stale sessions, runs queued approvals and updates the
                selected site and address list.
        """
        self._polling = False
        while True:
            try:
                owner, kind, payload = self._events.get_nowait()
            except queue.Empty:
                break
            if kind == 'keepalive_error' and isinstance(payload, _LinkedSite):
                if (self._session is not payload.session
                        and not any(site.session is payload.session
                                    for site in self._parked_links)):
                    continue
                if not payload.session.available():
                    self._reconnect_issuers.add(payload.issuer)
                    self._status.set(self._text('linking_reconnect_needed'))
                    self._notify_target_changed()
                else:
                    self._status.set(self._text('connection_retry'))
                continue
            if (owner is not self._owner
                    or self._window is None and owner is not self._direct_owner):
                if (kind in ('connected', 'resumed')
                        and isinstance(payload, tuple)):
                    payload[0].close()
                elif isinstance(payload, _PhotosUploadUpgrade):
                    payload.verified.session.close()
                elif isinstance(payload, _PhotosWatchStart):
                    self._discard_photos_watch_key(payload.target)
                continue
            if kind == 'restart_website_link' and isinstance(payload, str):
                if self._worker is not None and self._worker.is_alive():
                    self._events.put((owner, kind, payload))
                    break
                if self._session is None:
                    self._current_issuer = payload
                    self._capabilities = frozenset()
                self._start(payload, direct=True, restart=True)
            elif kind == 'capabilities' and isinstance(payload, tuple):
                issuer, capabilities = payload
                if issuer == self._current_issuer and isinstance(capabilities, frozenset):
                    self._capabilities = capabilities
                    if 'galleries:list' not in capabilities:
                        self._browse_var.set(False)
                    self._status.set('' if 'linking' in capabilities
                                     else self._text('linking_server_unavailable'))
            elif kind == 'handoff' and isinstance(payload, PushedAuthorization):
                self._handoff = payload
                self._browser_needs_launch = True
                self._open_browser()
            elif kind == 'offline_saved' and isinstance(payload, tuple):
                self._offline_binding, self._offline_store = payload
            elif kind == 'offline_discarded' and isinstance(payload, OfflineBinding):
                for site in self._live_links():
                    if site.issuer == payload.issuer and site.user_id == payload.account_id:
                        site.session.disable_renewal()
                self._offline_binding = None
                self._status.set(gettext('Saved local credential removed; the current session and server grant may still exist.'))
            elif kind == 'photos_upload_upgrade' and isinstance(payload, _PhotosUploadUpgrade):
                self._activate_photos_upload_upgrade(payload)
                self._clear_handoff()
            elif kind == 'photos_watch_start' and isinstance(payload, _PhotosWatchStart):
                try:
                    accepted = self._apply_photos_watch_start(payload)
                except Exception:  # noqa: BLE001
                    accepted = False
                if accepted:
                    self._status.set('')
                else:
                    self._discard_photos_watch_key(payload.target)
                    message = self._status.get()
                    if not message or message == 'Preparing the Watcher connection…':
                        message = gettext('Could not start the linked Watcher. Check the Watcher settings and try again.')
                    self._status.set(message)
                    payload.failure(message)
            elif kind == 'photos_watch_failed' and callable(payload):
                message = gettext('Could not verify the gallery or prepare Watcher access. Check the connection and try again.')
                self._status.set(message)
                payload(message)
            elif kind == 'connected' and isinstance(payload, tuple):
                session, identity, issuer = payload
                self._record_diagnostic(
                    'stage=linking; route=app_linking_identity; '
                    'availability=true; reason=connected',
                    failure=False,
                )
                self._show_link(_LinkedSite(issuer, session, identity.user_id))
                self._dismiss_address_prompt()
                self._website_request_owner = None
                if self._window is None:
                    self._root.lift()
                self._clear_handoff()
            elif kind == 'resumed' and isinstance(payload, tuple):
                session, identity, issuer = payload
                self._record_diagnostic(
                    'stage=linking; route=app_linking_identity; '
                    'availability=true; reason=connected',
                    failure=False,
                )
                self._show_link(_LinkedSite(issuer, session, identity.user_id))
                self._dismiss_address_prompt()
                self._website_request_owner = None
                self._clear_handoff()
            elif kind == 'resume_error':
                self._status.set(gettext('Could not verify the saved link. Try again.'))
            elif kind == 'saved_storage_unavailable' and isinstance(payload, tuple):
                issuer, approve = payload
                if approve:
                    if self._worker is not None and self._worker.is_alive():
                        self._events.put((owner, kind, payload))
                        break
                    # Native read failed before starting a refresh. Keep the
                    # saved grant and allow a separate temporary approval.
                    self._start(issuer, direct=True, use_native_storage=False)
                else:
                    self._status.set(gettext('Could not verify the saved link. Try again.'))
            elif (kind in ('resume_absent', 'resume_expired', 'offline_expired')
                    and isinstance(payload, tuple)):
                issuer, approve = payload
                if kind == 'offline_expired':
                    self._offline_binding = None
                if approve:
                    if self._worker is not None and self._worker.is_alive():
                        self._events.put((owner, kind, payload))
                        break
                    self._start(issuer, direct=True)
                elif kind != 'resume_absent':
                    self._status.set(gettext('Saved gallery access expired. Approve the link again.'))
                else:
                    self._status.set('')
            elif kind == 'galleries' and isinstance(payload, LinkedGalleryPage):
                self._status.set('')
                if (not self._gallery_query or not self._catalog_search_ready()
                        or payload.limited):
                    self._present_gallery_page(payload)
            elif kind == 'picker_page' and isinstance(payload, tuple):
                revision, page = payload
                if revision is not self._gallery_search_revision:
                    continue
                if isinstance(page, LinkedGalleryPage):
                    self._present_gallery_page(page)
                else:
                    self._gallery_page = None
                    self._display_gallery_page()
                    self._gallery_status.set(self._text('linking_gallery_search_failed'))
            elif kind == 'recovery_observed' and isinstance(payload, tuple):
                target, result, file_hash = payload
                if (self.selected_upload_target() is not target
                        or self._session is not target.session
                        or self._session_issuer != target.issuer
                        or self._session_user_id != target.user_id
                        or self._recovery_publish is None):
                    self._gallery_status.set(gettext('The upload observation is no longer current.'))
                else:
                    message = self._text(
                        'linking_recovery_observed' if result == 'present'
                        else 'linking_recovery_unknown',
                    )
                    if not self._recovery_publish(
                        target, file_hash, lambda: self._gallery_status.set(message),
                    ):
                        self._gallery_status.set(gettext('The upload observation is no longer current.'))
            elif kind == 'recovery_error':
                self._gallery_status.set(
                    gettext('Could not verify the uncertain upload. It remains unresolved.'),
                )
            elif kind == 'upload_target_verified' and isinstance(payload, tuple):
                if (len(payload) == 7 and isinstance(payload[0], LinkedUploadTarget)
                        and isinstance(payload[4], LinkedGallery)
                        and isinstance(payload[5], str)
                        and isinstance(payload[6], threading.Event)
                        and self._apply_verified_upload_target(*payload)):
                    self._close_window()
            elif kind == 'upload_target_error':
                self._gallery_status.set(
                    gettext(
                        "The selected gallery could not be verified. "
                        "The previous upload target is unchanged."
                    ),
                )
            elif kind == 'create_completed' and isinstance(payload, tuple):
                bound, session, issuer, account = payload
                if (isinstance(bound, BoundCreateReceipt)
                        and self._session is session
                        and self._session_issuer == issuer == self._current_issuer
                        and self._session_user_id == account and session.available()):
                    self._created_gallery = (bound, session, issuer, account)
                    upload_allowed = session.can_upload()
                    if (upload_allowed and _dialog.LINKING_UPLOAD_TARGET_READY
                            and _dialog.LINKING_CREATED_TARGET_READY):
                        next_action = self._text('linking_created_next')
                    elif upload_allowed:
                        next_action = ('Linked upload selection is temporarily unavailable. '
                                       'You can create another gallery.')
                    else:
                        next_action = 'You can create another gallery.'
                    self._gallery_status.set(
                        gettext('Created ') + _gallery_label(bound.gallery) + '. ' + next_action,
                    )
                else:
                    self._gallery_status.set(
                        gettext('The creation receipt was saved; reconnect and check its status.'),
                    )
            elif kind == 'create_acknowledged':
                self._created_gallery = None
                self._gallery_status.set(gettext('Creation acknowledged. You may create another gallery.'))
            elif kind == 'create_ack_error':
                self._gallery_status.set(gettext('The creation receipt could not be acknowledged safely.'))
            elif kind == 'create_selection_verified' and isinstance(payload, tuple):
                if (len(payload) == 7 and isinstance(payload[0], BoundCreateReceipt)
                        and isinstance(payload[1], LinkedUploadTarget)
                        and isinstance(payload[6], threading.Event)):
                    self._apply_created_gallery(*payload)
            elif kind == 'create_selection_error':
                self._gallery_status.set(
                    gettext('The created gallery is unavailable or its access could not be verified.'),
                )
            elif kind == 'create_unknown':
                self._gallery_status.set(
                    gettext(
                        "Creation is unconfirmed. Do not create it again. Reconnect "
                        "to the same account and check status. Explicitly Resume "
                        "original creation, or review the gallery and storage and "
                        "use Close unresolved creation."
                    ),
                )
            elif kind == 'create_failed':
                self._gallery_status.set(
                    gettext(
                        "Could not create the gallery. Check the connection or try "
                        "another name. Using the same name resumes its saved request."
                    ),
                )
            elif kind in ('create_resume_preview', 'create_abandon_preview') and isinstance(payload, CreateIntent):
                session = self._session
                if (session is not None and self._session_issuer == payload.issuer
                        and self._current_issuer == payload.issuer
                        and self._session_user_id == payload.account_id
                        and session.can_create_gallery()):
                    title = ''.join(c if c.isprintable() else ' ' for c in payload.title)
                    parent = ('root' if payload.parent_id is None
                              else f'gallery #{payload.parent_id}')
                    abandoning = kind == 'create_abandon_preview'
                    if abandoning:
                        prompt = (
                            'Close the original request for "' + title + '" in '
                            + parent + '? The original key will be permanently '
                            'closed, but a gallery or files may already exist '
                            'or old work may still be finishing. Verify the '
                            'original work has stopped and inspect the gallery '
                            'and storage before creating another.'
                        )
                    else:
                        prompt = (
                            'Resume the original request for "' + title + '" in '
                            + parent + '? This may create the gallery if the original '
                            'request did not reach the server.'
                        )
                    confirmed = messagebox.askyesno(
                        gettext('Close unresolved creation') if abandoning
                        else gettext('Resume original creation'), prompt, parent=self._window,
                    )
                    if (confirmed and self._window is not None
                            and self._owner is owner and self._session is session
                            and self._session_issuer == payload.issuer
                            and self._session_user_id == payload.account_id
                            and session.can_create_gallery()):
                        if abandoning:
                            self._start_gallery_create(
                                session, None, None, abandon_intent=payload,
                            )
                        else:
                            self._start_gallery_create(session, None, None, payload)
                    elif self._window is not None and self._owner is owner:
                        self._gallery_status.set(
                            gettext('The original creation remains unresolved.'),
                        )
            elif kind == 'create_resume_unavailable':
                self._gallery_status.set(
                    gettext(
                        "No unresolved creation is available for this account. "
                        "Check the creation status before continuing."
                    ),
                )
            elif kind == 'create_abandoned':
                self._gallery_status.set(
                    gettext(
                        "The original key is closed. A gallery or files may already "
                        "exist or old work may still be finishing. Verify it has "
                        "stopped and inspect the gallery and storage before creating another."
                    ),
                )
            elif (kind in ('detail', 'visibility_done', 'visibility_conflict')
                  and isinstance(payload, LinkedGalleryDetail)):
                selected = self._gallery_choice.current()
                if (self._gallery_page is not None and 0 <= selected < len(self._gallery_page.galleries)
                        and self._gallery_page.galleries[selected].gallery_id == payload.gallery_id
                        and self._session is not None):
                    self._detail_snapshot = (
                        payload, self._session, self._session_issuer,
                        self._session_user_id,
                    )
                    self._visibility_choice.set('')
                    title = ''.join(c if c.isprintable() else ' ' for c in payload.title)
                    prefix = (
                        self._text('linking_visibility_saved') + ' '
                        if kind == 'visibility_done' else
                        self._text('linking_visibility_conflict') + ' '
                        if kind == 'visibility_conflict' else ''
                    )
                    self._gallery_status.set(
                        prefix + gettext(
                            "Gallery #{number}: {title} · Revision {revision}. "
                            "Saved visibility: {visibility}; access: {access}; "
                            "listing: {listing}. Inherited access is not shown."
                        ).format(
                            number=payload.gallery_id, title=title,
                            revision=payload.revision,
                            visibility=_visibility_labels().get(
                                payload.visibility, gettext(payload.visibility),
                            ),
                            access={
                                "normal": gettext("Standard access"),
                                "password": gettext("Password protected"),
                            }.get(payload.access_mode, payload.access_mode),
                            listing={
                                "listed": gettext("Listed"),
                                "unlisted": gettext("Unlisted"),
                            }.get(payload.access_listing, payload.access_listing),
                        ),
                    )
            elif kind == 'detail_error':
                self._detail_snapshot = None
                self._gallery_status.set(
                    gettext(
                        "Gallery detail is unsupported or unavailable. Existing "
                        "connections and saved API-key upload remain unchanged."
                    ),
                )
            elif kind == 'visibility_unknown':
                self._gallery_status.set(self._text('linking_visibility_unknown'))
            elif kind == 'visibility_scheduled':
                self._gallery_status.set(self._text('linking_visibility_schedule'))
            elif kind == 'visibility_conflict':
                self._gallery_status.set(self._text('linking_visibility_conflict_read'))
            elif kind == 'offline_unavailable':
                self._status.set(
                    gettext('Could not reach the saved gallery renewal service. Try again when it is available.'),
                )
            elif kind == 'offline_error':
                issuer, approve = (payload if isinstance(payload, tuple)
                                   else (self._current_issuer, False))
                self._reconnect_issuers.add(issuer)
                self._status.set(self._text('linking_reconnect_needed'))
                if approve:
                    if self._worker is not None and self._worker.is_alive():
                        self._events.put((owner, kind, payload))
                        break
                    self._remove_saved_link(issuer, reconnect=True)
            elif kind == 'saved_link_removed' and isinstance(payload, tuple):
                issuer, reconnect = payload
                self._offline_binding = None
                self._reconnect_issuers.discard(issuer)
                if reconnect:
                    if self._worker is not None and self._worker.is_alive():
                        self._events.put((owner, kind, payload))
                        break
                    self._start(issuer, direct=True)
                else:
                    self._status.set(self._text('linking_unlinked_locally'))
            elif kind == 'saved_link_remove_error':
                self._status.set(self._text('linking_local_remove_failed'))
            elif kind == 'unlink_cleanup_error':
                self._status.set(
                    gettext(
                        "The saved secure reconnect could not be removed. "
                        "The gallery remains connected."
                    ),
                )
            elif kind == 'unlink_vault_cleared' and isinstance(payload, tuple):
                site, revoked = payload
                self._continue_unlink(site, revoked)
            elif kind == 'galleries_error':
                self._gallery_page = None
                self._display_gallery_page()
                self._status.set(gettext('Could not load galleries.'))
                self._gallery_status.set(
                    gettext(
                        "Could not load the current gallery list. Access may have "
                        "expired or been revoked; refresh after reconnecting."
                    ),
                )
            elif kind == 'response_error' and isinstance(
                payload, LinkingResponseError,
            ):
                self._clear_handoff()
                summary = payload.safe_summary
                self._record_diagnostic(summary, failure=True)
                if payload.reason == 'dns_failure':
                    message = gettext('Could not resolve the gallery address (DNS).')
                elif payload.reason == 'connect_failure':
                    message = gettext('Could not connect to the gallery service.')
                elif payload.reason == 'tls_failure':
                    message = gettext('Could not establish secure HTTPS to the gallery.')
                elif payload.reason == 'timeout':
                    message = gettext('The gallery request timed out.')
                elif (payload.status is not None
                      and payload.content_kind == 'html'):
                    message = gettext(
                        'The gallery returned HTTP {status} with an HTML response.',
                    ).format(status=payload.status)
                elif payload.status is not None:
                    message = gettext('The gallery returned HTTP {status}.').format(
                        status=payload.status,
                    )
                else:
                    message = gettext('The linking response could not be accepted.')
                self._status.set(message)
            elif kind == 'denied':
                self._clear_handoff()
                self._status.set(gettext('The gallery did not approve this link.'))
            elif kind == 'exchanging':
                self._clear_handoff()
                self._status.set(gettext('Checking the approved identity…'))
            elif kind == 'revoked':
                self._status.set(gettext('Gallery unlinked.'))
            elif kind == 'revoke_error':
                self._status.set(
                    gettext('Unlinked locally. Check Manage link to confirm server revocation.'),
                )
            elif kind in (
                'unavailable', 'https_failed', 'request_failed',
                'callback_failed', 'exchange_failed', 'save_failed',
            ):
                self._clear_handoff()
                if kind == 'unavailable':
                    if isinstance(payload, LinkingUnavailable):
                        self._record_diagnostic(
                            payload.safe_summary, failure=True,
                        )
                    else:
                        self._record_diagnostic(
                            'stage=discovery; route=app_linking_discovery; '
                            'availability=unknown; http=none; '
                            'response_kind=other; reason=request_unavailable',
                            failure=True,
                        )
                elif kind in ('request_failed', 'https_failed'):
                    self._record_diagnostic(
                        'stage=linking; route=app_linking_identity; http=none; '
                        'response_kind=other; reason=transport_failure',
                        failure=True,
                    )
                elif kind == 'callback_failed':
                    self._record_diagnostic(
                        'stage=callback; route=loopback; reason=approval_not_received',
                        failure=True,
                    )
                messages = {
                    'unavailable': self._text('linking_website_address_unavailable'),
                    'https_failed': (
                        'Could not establish secure HTTPS. '
                        'Check the address, port and certificate.'
                    ),
                    'request_failed': (
                        'Could not reach the linking service. '
                        'Check the gallery address and path.'
                    ),
                    'callback_failed': self._text('linking_browser_approval_failed'),
                    'exchange_failed': 'Could not verify the approved connection. Try connecting again.',
                    'save_failed': (
                        'The browser approved the link, but secure storage failed.'
                    ),
                }
                self._status.set(messages[kind])
            else:
                self._clear_handoff()
                self._status.set(
                    gettext(
                        "Linking could not be verified; an older server may not "
                        "support it. Saved API-key upload remains available. "
                        "A server grant may exist; review gallery links."
                    ),
                )
        if self._handoff is not None and time.monotonic() >= self._handoff.expires_at:
            self._clear_handoff()
            self._cancel.set()
            self._owner = None
            if self._attempt is not None:
                self._attempt.close()
            self._status.set(self._text('linking_expired'))
        if self._window is not None or self._session is not None or self._parked_links:
            self._refresh(self._current_issuer)
        if self._refresh_address_prompt is not None:
            self._refresh_address_prompt()
        if (self._gallery_search_waiting and self._gallery_search_job is None
                and self._events.empty()):
            self._run_gallery_search()
        self._keep_links_alive()
        # Check the queue after observing worker termination: the worker may
        # have posted its final event just after the drain above and exited.
        if ((self._worker is not None and self._worker.is_alive())
                or not self._events.empty()):
            self._schedule_poll()
        else:
            if self._session is not None or self._parked_links:
                self._schedule_poll(_dialog.LINKING_IDLE_POLL_INTERVAL_MS)

    def _keep_links_alive(self) -> None:
        """Check idle live links through the existing read-only identity route.

        Returns:
            None: Starts at most one worker per five-minute round. Real API use
                suppresses identity keepalive; capture keys receive their own
                check. No failed mutation is replayed.
        """
        if (self._shutting_down or time.monotonic() < self._keepalive_next
                or self._keepalive_worker is not None
                and self._keepalive_worker.is_alive()):
            return
        watch_target = self._watch_target
        sites = tuple(site for site in self._live_links()
                      if site.session.keepalive_due() is True or (watch_target is not None
                          and site.session is watch_target.session))
        if not sites:
            return
        self._keepalive_next = time.monotonic() + LINKING_KEEPALIVE_SECONDS

        def worker() -> None:
            """Renew idle server deadlines without touching Tk or uploads.

            Returns:
                None: Queues a recoverable connection error for the UI owner.
            """
            for site in sites:
                try:
                    if watch_target is not None and site.session is watch_target.session:
                        watch_target.inventory([])
                    else:
                        verify_live_gallery_activity(site)
                except (RuntimeError, OSError):
                    self._events.put((site.session, 'keepalive_error', site))

        self._keepalive_worker = threading.Thread(
            target=worker, name='PHPGalleryConnectionKeepalive', daemon=True,
        )
        try:
            self._keepalive_worker.start()
        except RuntimeError:
            self._keepalive_worker = None
