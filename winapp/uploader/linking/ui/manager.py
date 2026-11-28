# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/ui/manager.py
# Module Type: Windows Client Interface
# Purpose: Present explicit browser-approved temporary or saved gallery links.
# Responsibilities:
#   - Keep a single bounded linking worker and request handle out of user flows.
#   - Open the verified browser approval page without exposing a manual code.
#   - Keep linked identity and secure optional storage separate from API keys.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Tk interface for browser-approved temporary or securely saved links."""

import os
import queue
import sys
import threading
import tkinter as tk
import traceback
import webbrowser
from dataclasses import replace
from datetime import datetime, timezone
from pathlib import Path
from tkinter import ttk
from uploader.localization import gettext
from uploader.ui_shell import EmbeddedWindow, messagebox, simpledialog
from typing import Callable
from .. import LINKING_RECOVERY_MAX_FILE_BYTES, LinkedGallery, LinkedGalleryDetail, LinkedGalleryPage, LinkedIdentity, LinkingAttempt, LinkingError, LinkingReconnectRequired, LinkingResponseError, LinkingRevisionConflict, LinkingScheduledPublication, LinkingSession, LinkingUnavailable, PushedAuthorization, discover_linking_capabilities, linking_issuer, requested_linking_scope
from ...linked_upload import (
    LinkedUploadTarget, linked_target_binding, verify_linked_upload_target,
)
from ...linked_create import (
    BoundCreateReceipt, CreateDispatch, CreateIntent, CreateJournalSnapshot,
    GalleryCreateJournal,
)
from ...credential_store import CredentialStoreUnavailable, NativeCredentialStore
from ...desktop_connection import DesktopLinkedSite as _LinkedSite, desktop_upload_vault, find_live_link, gallery_editor_url, live_link_sites, restore_desktop_transient_link, saved_desktop_gallery_binding, persist_browser_scope_upgrade
from ...flight_report_translations import tr
from ...offline_linking import OfflineBinding, saved_offline_hint_path
from ...session_resume import delete_transient
from ...state_store import (
    RECENT_GALLERY_ADDRESS_LIMIT,
    load_recent_gallery_addresses, save_recent_gallery_addresses,
)
from ...ui_theme import Button, GlassCard, icon_button, place_dialog


from . import dialog as _dialog
from .dialog import LinkingDialogMixin, _visibility_labels
from .polling import (
    LinkingPollingMixin, _PhotosUploadUpgrade, _PhotosWatchStart,
)
from .connection import LinkingConnectionMixin
from .picker_behavior import LinkingPickerBehaviorMixin
from .picker_ui import _gallery_label


class LinkingManager(LinkingDialogMixin, LinkingPollingMixin, LinkingConnectionMixin, LinkingPickerBehaviorMixin):
    """Own live gallery links, the selected site's view and one linking worker."""

    _address_prompt: tuple[EmbeddedWindow, ttk.Entry, ttk.Button] | None = None
    _refresh_address_prompt: Callable[[], None] | None = None
    _website_request_owner: object | None = None
    _parked_links: tuple[_LinkedSite, ...] = ()
    _recent_links: tuple[str, ...] = ()
    _recent_link_path: Path | None = None
    _saved_upload_identity: Callable[[], tuple[str, str] | None] | None = None
    _saved_api_address: Callable[[], str] | None = None
    _browser_needs_launch = False
    _gallery_query = ''
    _gallery_browse_page: LinkedGalleryPage | None = None
    _gallery_catalog_limited = False
    _gallery_search_job: str | None = None
    _gallery_search_waiting = False
    _gallery_search_revision: object | None = None
    _gallery_search_cancel: threading.Event | None = None

    def __init__(
        self, root: tk.Tk,
        target_change_refusal: Callable[[str | None], str | None] | None = None,
        target_changed: Callable[[], None] | None = None,
        recovery_probe: Callable[[LinkedUploadTarget, int], tuple[str, str]] | None = None,
        recovery_publish: Callable[[LinkedUploadTarget, str, Callable[[], None]], bool]
        | None = None,
        saved_linked_selected: Callable[[], bool] | None = None,
        commit_linked_target: Callable[[LinkedUploadTarget], bool] | None = None,
        commit_saved_api_key: Callable[[], bool] | None = None,
        language: Callable[[], str] | None = None,
        recovery_review: Callable[[LinkedUploadTarget, str | None], str | None]
        | None = None,
        upload_workspace_ready: Callable[[], bool] | None = None,
        saved_upload_identity: Callable[[], tuple[str, str] | None] | None = None,
        diagnostic_callback: Callable[[str], None] | None = None,
        saved_api_address: Callable[[], str] | None = None,
    ) -> None:
        """Create an idle manager bound to the application's Tk thread.

        Args:
            root (tk.Tk): Main application window and timer owner.
            target_change_refusal (Callable[[str | None], str | None] | None):
                Optional host guard returning a reason to refuse a proposed
                binding or the saved API-key mode, or None to allow it.
            target_changed (Callable[[], None] | None): Optional host callback
                refreshing connection controls after an explicit selection.
            recovery_probe (Callable[[LinkedUploadTarget, int], tuple[str, str]] | None):
                Host observation of one journaled hash and user-supplied size.
            recovery_publish (Callable[[LinkedUploadTarget, str, Callable[[], None]], bool] | None):
                Host guard publishing only against the unchanged journal.
            saved_linked_selected (Callable[[], bool] | None): Current verified
                persistent mode preference; unavailable evidence counts as linked.
            commit_linked_target (Callable[[LinkedUploadTarget], bool] | None):
                Explicit mode publication and activation after server verification.
            commit_saved_api_key (Callable[[], bool] | None): Explicit persistent
                return to the original manually provisioned API-key mode.
            language (Callable[[], str] | None): Delayed current UI-language
                lookup, defaulting to English when omitted.
            recovery_review (Callable[[LinkedUploadTarget, str | None], str | None] | None):
                Reads an unknown marker or records review for its exact value.
            upload_workspace_ready (Callable[[], bool] | None): Host check for
                a supported local profile before starting target verification.
            saved_upload_identity (Callable[[], tuple[str, str] | None] | None):
                Verified saved issuer and non-secret target binding for recovery
                without a live session, or None if no saved target is available.
            diagnostic_callback (Callable[[str], None] | None): Optional host
                receiver for the latest fixed-field linking diagnostic summary.
            saved_api_address (Callable[[], str] | None): Saved API endpoint
                for the address prompt, or empty when no key is configured.

        Returns:
            None: Initializes one app-wide linking owner.
        """
        self._root = root
        self._target_change_refusal = target_change_refusal
        self._target_changed = target_changed
        self._recovery_probe = recovery_probe
        self._recovery_publish = recovery_publish
        self._recovery_review = recovery_review
        self._upload_workspace_ready = upload_workspace_ready
        self._saved_upload_identity = saved_upload_identity
        self._saved_api_address = saved_api_address
        self._diagnostic_callback = diagnostic_callback
        self._diagnostics_snapshot = ''
        self._latest_diagnostic = ''
        self._last_failure_diagnostic = ''
        self._last_failure_utc = ''
        self._saved_linked_selected = saved_linked_selected
        self._commit_linked_target = commit_linked_target
        self._commit_saved_api_key = commit_saved_api_key
        self._language = language if language is not None else (lambda: 'en')
        self._status = tk.StringVar(root)
        self._linked_address = tk.StringVar(root)
        self._gallery_status = tk.StringVar(root, value='No gallery selected.')
        self._window: EmbeddedWindow | None = None
        self._worker: threading.Thread | None = None
        self._attempt: LinkingAttempt | None = None
        self._session: LinkingSession | None = None
        self._session_issuer = ''
        self._reconnect_issuers: set[str] = set()
        self._keepalive_worker: threading.Thread | None = None
        self._keepalive_next = 0.0
        self._watch_target: LinkedUploadTarget | None = None
        self._session_user_id = 0
        self._owner: object | None = None
        self._direct_owner: object | None = None
        self._cancel = threading.Event()
        self._gallery_cancel: threading.Event | None = None
        self._events: queue.Queue[
            tuple[object, str, PushedAuthorization
                  | tuple[LinkingSession, LinkedIdentity, str]
                  | tuple[LinkedUploadTarget, str, str]
                  | tuple[str, frozenset[str]] | tuple[str, bool]
                  | tuple[OfflineBinding, NativeCredentialStore]
                  | tuple[LinkedUploadTarget, LinkingSession, str, int,
                          LinkedGallery, str, threading.Event]
                  | tuple[BoundCreateReceipt, LinkingSession, str, int]
                  | tuple[BoundCreateReceipt, LinkedUploadTarget,
                          LinkingSession, str, int, str, threading.Event]
                  | tuple[object, LinkedGalleryPage | None]
                  | LinkedGalleryPage | LinkedGalleryDetail | LinkedGallery | CreateIntent
                  | BoundCreateReceipt | OfflineBinding | _PhotosUploadUpgrade
                  | _PhotosWatchStart | _LinkedSite | LinkingResponseError | LinkingUnavailable
                  | Callable[[str], None] | str | None]
        ] = queue.Queue()
        self._handoff: PushedAuthorization | None = None
        self._current_issuer = ''
        self._capabilities: frozenset[str] = frozenset()
        self._gallery_page: LinkedGalleryPage | None = None
        self._detail_snapshot: tuple[LinkedGalleryDetail, LinkingSession, str, int] | None = None
        self._selected_gallery: LinkedGallery | None = None
        self._created_gallery: tuple[BoundCreateReceipt, LinkingSession, str, int] | None = None
        self._active_upload_target: LinkedUploadTarget | None = None
        self._temporary_watch_selection: (
            tuple[LinkedUploadTarget, LinkedUploadTarget] | None
        ) = None
        self._polling = False
        self._shutting_down = False
        self._offline_store: NativeCredentialStore | None = None
        self._offline_binding: OfflineBinding | None = None
        profile = (Path(os.environ.get('APPDATA', str(Path.home())))
                   if sys.platform == 'win32' else Path.home())
        self._offline_path = profile / 'PHPGalleryUploader' / 'offline-link.json'
        self._recent_link_path = self._offline_path.with_name('recent-gallery-links.json')
        self._recent_links = load_recent_gallery_addresses(self._recent_link_path)
        self._create_journal = GalleryCreateJournal(self._offline_path.parent)
        self._create_dispatch: CreateDispatch | None = None

    def diagnostics_snapshot(self) -> str:
        """Return latest and retained failure summaries for host diagnostics.

        Returns:
            str: Bounded fixed-field latest outcome and last failure with its
                UTC timestamp, or empty before the first diagnostic outcome.
        """
        latest = getattr(self, '_latest_diagnostic', '')
        failure = getattr(self, '_last_failure_diagnostic', '')
        failed_at = getattr(self, '_last_failure_utc', '')
        if not latest and not failure:
            return ''
        latest_text = latest or 'none'
        failure_text = failure or 'none'
        failed_at_text = failed_at or 'none'
        return (
            f'latest={latest_text}; last_failure={failure_text}; '
            f'last_failure_utc={failed_at_text}'
        )

    def _record_diagnostic(self, summary: str, *, failure: bool) -> None:
        """Publish one safe summary to the snapshot and optional host callback.

        Args:
            summary (str): Fixed local diagnostic string from a validated
                response error, server-unavailable result or success.
            failure (bool): Whether this outcome should replace the retained
                last-failure fields.

        Returns:
            None: Updates bounded summaries and notifies the optional host.
        """
        if (not isinstance(summary, str)
                or len(summary) > _dialog.LINKING_DIAGNOSTIC_MAX_CHARS):
            return
        self._diagnostics_snapshot = summary
        self._latest_diagnostic = summary
        if failure:
            self._last_failure_diagnostic = summary
            self._last_failure_utc = datetime.now(timezone.utc).isoformat(
                timespec='seconds',
            )
        callback = getattr(self, '_diagnostic_callback', None)
        if callable(callback):
            try:
                callback(summary)
            except Exception:  # noqa: BLE001
                pass

    def _text(self, key: str) -> str:
        """Translate one linking label using the current UI language.

        Args:
            key (str): Existing linking translation key.

        Returns:
            str: Localized label, with English fallback if lookup fails.
        """
        try:
            language = self._language()
        except Exception:  # noqa: BLE001
            language = 'en'
        if not isinstance(language, str):
            language = 'en'
        return tr(language, key)

    @property
    def status(self) -> tk.StringVar:
        """Expose short linking progress and errors to the main window.

        Returns:
            tk.StringVar: Tk-thread status shared by the linking surfaces.
        """
        return self._status

    @property
    def linked_address(self) -> tk.StringVar:
        """Expose the verified installation independently of upload selection.

        Returns:
            tk.StringVar: Exact live installation URL, or empty while unlinked.
        """
        return self._linked_address

    def address(self) -> str:
        """Return the installation already selected in the linking workspace.

        Returns:
            str: Live, entered or verified saved issuer, or empty if unknown.
        """
        address = self._session_issuer or self._current_issuer
        if address:
            return address
        identity = self._saved_upload_identity() if self._saved_upload_identity else None
        return identity[0] if identity is not None else ''

    def _native_offline_store(self) -> NativeCredentialStore | None:
        """Probe the current user's native vault for approved refresh storage.

        Returns:
            NativeCredentialStore | None: Available platform vault, if any.
        """
        return desktop_upload_vault(self._offline_path.parent)

    def _saved_offline_binding(self, issuer: str) -> OfflineBinding | None:
        """Find the exact rolling or compatible legacy Windows restart hint.

        Args:
            issuer (str): Independently chosen canonical installation issuer.

        Returns:
            OfflineBinding | None: Matching local metadata, or None if absent.

        Raises:
            RuntimeError: The selected hint is malformed or unsafe to read.
        """
        return saved_desktop_gallery_binding(self._offline_path, issuer)

    def _offline_binding_path(self, binding: OfflineBinding) -> Path:
        """Resolve the storage generation for an exact saved link.

        Args:
            binding (OfflineBinding): Validated issuer, scope and Admin hint.

        Returns:
            Path: Dedicated rolling hint or the original Windows hint.
        """
        return saved_offline_hint_path(self._offline_path, binding.issuer, binding.scope)

    def resume_saved_link(
        self, issuer: str, *, approve_if_absent: bool = False,
    ) -> bool:
        """Try an existing native vault grant before considering browser approval.

        Args:
            issuer (str): Exact issuer in the saved recent-address list.
            approve_if_absent (bool): Start browser approval when a saved
                approval is absent, expired, inaccessible before rotation or
                definitively refused.

        Returns:
            bool: True when this action handles the link or conveys failure;
                false when the caller should start ordinary browser approval.
        """
        canonical = linking_issuer(issuer)
        if not canonical or (self._worker is not None and self._worker.is_alive()):
            return False
        if self._session is not None and self._session_issuer == canonical:
            return False
        try:
            binding = self._saved_offline_binding(canonical)
        except RuntimeError:
            self._status.set(gettext('The securely saved gallery connection is unavailable.'))
            return True
        if binding is not None:
            store = self._native_offline_store()
            if store is None:
                if approve_if_absent:
                    if self._session is None:
                        self._current_issuer = canonical
                    self._start(canonical, direct=True, use_native_storage=False)
                else:
                    self._status.set(
                        gettext('The securely saved gallery connection is unavailable.'),
                    )
                return True
            if self._session is None:
                self._current_issuer = canonical
            self._offline_store = store
            self._offline_binding = binding
            self._restore_offline(silent=True, approve_if_expired=approve_if_absent)
            return self._worker is not None
        if sys.platform != 'darwin':
            return False
        if self._session is None:
            self._current_issuer = canonical
        owner = object()
        cancel = threading.Event()
        self._owner = self._direct_owner = owner
        self._website_request_owner = owner
        self._cancel = cancel
        self._status.set(gettext('Checking the saved gallery connection…'))

        def worker() -> None:
            """Verify the original unexpired grant before exposing it to Photos.

            Returns:
                None: Queues a verified site or a generic recovery event.
            """
            session: LinkingSession | None = None
            try:
                restored = restore_desktop_transient_link(canonical, cancel)
                if restored is None:
                    self._events.put((
                        owner, 'resume_absent',
                        (canonical, approve_if_absent),
                    ))
                    return
                session, identity = restored
                connected_session = session
                self._events.put((
                    owner, 'resumed',
                    (connected_session, identity, canonical),
                ))
                session = None
                self._queue_initial_gallery_page(owner, connected_session, cancel)
            except LinkingReconnectRequired:
                self._events.put((
                    owner, 'resume_expired',
                    (canonical, approve_if_absent),
                ))
            except CredentialStoreUnavailable:
                if not cancel.is_set():
                    self._events.put((
                        owner, 'saved_storage_unavailable', (canonical, approve_if_absent),
                    ))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'resume_error', None))
            finally:
                if session is not None:
                    session.close()

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryTransientResume', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            return False
        self._schedule_poll()
        return True

    def resume_recent_links(self) -> None:
        """Try the most recently saved link without opening browser windows.

        Returns:
            None: Starts the first still-unexpired vault-backed recent grant.
        """
        if sys.platform not in ('darwin', 'win32'):
            return
        identity = self._saved_upload_identity() if self._saved_upload_identity else None
        issuer = self._recent_links[0] if self._recent_links else (
            identity[0] if identity is not None else ''
        )
        if issuer:
            self.resume_saved_link(issuer)





    def approve_admin_photos(self) -> None:
        """Open fresh browser consent for Admin Photos on the current site.

        Returns:
            None: Starts approval while preserving the current live link.
        """
        session = self.photos_session()
        if session is not None and (not session.can_read_admin_media()
                or not getattr(session, 'can_edit_gallery_metadata', lambda: False)()):
            self._start(self._session_issuer, direct=True, replace_photos=True)

    def start_photos_watcher(
        self, session: LinkingSession, gallery: LinkedGallery,
        start: Callable[[LinkedUploadTarget], bool],
        failure: Callable[[str], None],
        *, still_requested: Callable[[], bool] | None = None,
    ) -> None:
        """Verify the selected Photos gallery and request temporary authority.

        Args:
            session (LinkingSession): Exact live Photos link selected by the user.
            gallery (LinkedGallery): Exact contextual physical gallery.
            start (Callable[[LinkedUploadTarget], bool]): Host starts the
                admitted Watcher with its in-memory credential.
            failure (Callable[[str], None]): Show an actionable refusal on
                the Watcher screen.
            still_requested (Callable[[], bool] | None): Recheck on Tk that
                capture intent remains current before committing its target.

        Returns:
            None: Starts one worker or leaves existing capture state unchanged.
        """
        if (session is not self.photos_session() or not session.can_upload()
                or self._commit_linked_target is None
                or self._worker is not None and self._worker.is_alive()):
            message = gettext('Gallery access is unavailable or another connection operation is running. Try again.')
            self._status.set(message)
            failure(message)
            return
        issuer, account = self._session_issuer, self._session_user_id
        refusal = self._target_refusal(
            linked_target_binding(issuer, account, gallery.gallery_id),
        )
        if refusal is not None:
            self._status.set(refusal)
            failure(refusal)
            return
        owner, cancel = object(), threading.Event()
        self._owner = self._direct_owner = owner
        self._cancel = cancel
        self._status.set(gettext('Preparing the Watcher connection…'))

        def worker() -> None:
            """Keep gallery verification and key issuance off the Tk thread.

            Returns:
                None: Queues only verified temporary authority or a generic error.
            """
            key = None
            try:
                target = verify_linked_upload_target(
                    session, issuer, account, session.approved_scope(),
                    gallery.gallery_id, cancel,
                )
                key = session.open_watch_key(target.gallery_id, cancelled=cancel)
                target = replace(target, watch_key=key)
                if not cancel.is_set():
                    self._events.put((owner, 'photos_watch_start', _PhotosWatchStart(
                        target, start, failure, still_requested,
                    )))
                    key = None
            except Exception:  # noqa: BLE001
                if not cancel.is_set():
                    self._events.put((owner, 'photos_watch_failed', failure))
            finally:
                if key is not None:
                    try:
                        session.close_watch_key(gallery.gallery_id, key)
                    except LinkingError:
                        pass

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryPhotosWatcher', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            message = gettext('Could not prepare the Watcher connection. Try again.')
            self._status.set(message)
            failure(message)
            return
        self._schedule_poll()

    def _apply_photos_watch_start(self, request: _PhotosWatchStart) -> bool:
        """Admit and start the original verified Watcher request on Tk.

        Args:
            request (_PhotosWatchStart): Worker-verified gallery and temporary key.

        Returns:
            bool: True once the host owns the key for accepted Watcher work.
        """
        target = request.target
        commit = self._commit_linked_target
        if (self._cancel.is_set()
                or (request.still_requested is not None and not request.still_requested())
                or target.session is not self.photos_session()
                or self._session_issuer != target.issuer
                or self._session_user_id != target.user_id or commit is None):
            return False
        refusal = self._target_refusal(target.binding())
        if refusal is not None:
            self._status.set(refusal)
            return False
        previous = self.selected_upload_target()
        keep_selection = previous is not None and previous.binding() == target.binding()
        selected = (
            previous if keep_selection and previous.session is target.session
            else replace(target, watch_key=None)
        )
        try:
            if not commit(selected):
                return False
            self._active_upload_target = selected
            if not keep_selection:
                self._temporary_watch_selection = (target, selected)
            self._notify_target_changed()
            if request.start(target):
                self._watch_target = target
                return True
        except Exception:  # noqa: BLE001
            pass
        self.detach_photos_watcher_target(target)
        return False

    def detach_photos_watcher_target(self, target: LinkedUploadTarget) -> None:
        """Forget only the upload selection introduced for this exact capture.

        Args:
            target (LinkedUploadTarget): Original ephemeral Watcher target.

        Returns:
            None: Detaches its temporary selection without changing linked mode
                or the browser-approved site session.
        """
        if self._watch_target is target:
            self._watch_target = None
        owned = self._temporary_watch_selection
        if owned is None or owned[0] is not target:
            return
        self._temporary_watch_selection = None
        if self._active_upload_target is owned[1]:
            self._active_upload_target = None
            try:
                self._notify_target_changed()
            except Exception:  # noqa: BLE001
                pass

    def discard_photos_watcher_target(self, target: LinkedUploadTarget) -> None:
        """Forget an ephemeral Watcher connection and retire its server key.

        Args:
            target (LinkedUploadTarget): Exact old Watcher authority.
        Returns:
            None: Detaches locally and schedules remote cleanup if reachable.
        """
        self.detach_photos_watcher_target(target)
        self._discard_photos_watch_key(target)

    @staticmethod
    def _discard_photos_watch_key(target: LinkedUploadTarget) -> None:
        """Revoke an unused key without waiting on the Tk thread.

        Args:
            target (LinkedUploadTarget): Abandoned issued Watcher credential.

        Returns:
            None: Schedules best-effort server cleanup of that exact key.
        """
        def revoke() -> None:
            """Close the unused credential under its original link.

            Returns:
                None: Server expiry fences unavailable cleanup.
            """
            if target.watch_key is not None:
                try:
                    target.session.close_watch_key(target.gallery_id, target.watch_key)
                except LinkingError:
                    pass

        try:
            threading.Thread(target=revoke, name='PHPGalleryUnusedWatchKey', daemon=True).start()
        except RuntimeError:
            pass

    def create_photos_gallery(
        self, session: LinkingSession, title: str, parent_id: int | None,
        dispatch: CreateDispatch | None = None,
    ) -> BoundCreateReceipt:
        """Finish an explicit Photos creation and consume its verified receipt.

        Args:
            session (LinkingSession): Live link captured when the user submitted.
            title (str): User-entered physical gallery title.
            parent_id (int | None): Exact parent gallery ID, or root.
            dispatch (CreateDispatch | None): Optional contextual cancellation gate.

        Returns:
            BoundCreateReceipt: Authoritative completed gallery and intent binding.
                Local cleanup failure does not undo confirmed server success.

        Raises:
            LinkingError: The session, input or result could not be verified.
        """
        issuer, account = self._session_issuer, self._session_user_id
        if session is not self.photos_session() or not session.can_create_gallery():
            raise LinkingError('Photos gallery creation is unavailable.')
        journal = self._create_journal.for_request(issuer, account, title, parent_id)
        receipt = journal.create_or_resume(
            session, issuer, account, title, parent_id, dispatch,
        )
        if ((dispatch is not None and dispatch.cancelled.is_set())
                or session is not self.photos_session()):
            # A closed or replaced context may not present this result. Keep
            # its original key so submitting that same draft can recover it.
            return receipt
        try:
            journal.acknowledge(issuer, account, receipt)
        except Exception as error:
            # The server result is confirmed. Retain exact local evidence for
            # recovery without turning successful creation into a UI failure.
            self.record_photos_creation_failure('acknowledge', error)
        return receipt

    def inspect_photos_gallery_creation(self) -> CreateJournalSnapshot:
        """Read key-free saved creation state for the Photos workspace.

        Returns:
            CreateJournalSnapshot: Validated empty, pending or completed state.

        Raises:
            LinkingError: The journal records are unsafe or inconsistent.
        """
        return self._create_journal.inspect()

    def reconcile_photos_gallery_creation(
        self, session: LinkingSession, snapshot: CreateJournalSnapshot,
    ) -> BoundCreateReceipt | None:
        """Check a matching saved operation without issuing another mutation.

        Args:
            session (LinkingSession): Exact current live Photos session.
            snapshot (CreateJournalSnapshot): Key-free state captured by the
                profile journal before this status request.

        Returns:
            BoundCreateReceipt | None: Current confirmed gallery, or None while
                the original operation remains unknown.

        Raises:
            LinkingError: The snapshot, live identity or current grant changed.
        """
        if (snapshot.state not in ('pending', 'completed')
                or not isinstance(snapshot.issuer, str)
                or type(snapshot.account_id) is not int
                or snapshot.account_id <= 0
                or not isinstance(snapshot.title, str)
                or not isinstance(snapshot.intent_fingerprint, str)
                or session is not self.photos_session()
                or not session.can_create_gallery()
                or self._session_issuer != snapshot.issuer
                or self._session_user_id != snapshot.account_id
                or not session.matches_issuer(snapshot.issuer)
                or session.identity().user_id != snapshot.account_id):
            raise LinkingError('Saved gallery creation is not current.')
        journal = self._create_journal.for_request(
            snapshot.issuer, snapshot.account_id, snapshot.title, snapshot.parent_id,
        )
        return journal.reconcile(
            session, snapshot.issuer, snapshot.account_id,
            expected_intent_fingerprint=snapshot.intent_fingerprint,
        )

    def acknowledge_prior_photos_gallery(
        self, snapshot: CreateJournalSnapshot, session: LinkingSession,
        issuer: str, account_id: int,
    ) -> None:
        """Clear a prior completion only after explicit Photos confirmation.

        Args:
            snapshot (CreateJournalSnapshot): Exact key-free completed state
                presented to the user.
            session (LinkingSession): Current live create-authorized session.
            issuer (str): Current canonical issuer captured with the new draft.
            account_id (int): Current verified Admin account identifier.

        Returns:
            None: Removes only the prior matching local completion record.

        Raises:
            LinkingError: Authority changed or the completion record no longer
                matches the explicit confirmation.
        """
        if (snapshot.state != 'completed' or snapshot.receipt is None
                or not isinstance(snapshot.issuer, str)
                or type(snapshot.account_id) is not int
                or snapshot.account_id <= 0
                or not isinstance(snapshot.title, str)
                or snapshot.intent_fingerprint != snapshot.receipt.intent_fingerprint
                or session is not self.photos_session()
                or self._session_issuer != issuer
                or self._current_issuer != issuer
                or self._session_user_id != account_id
                or not session.available() or not session.can_create_gallery()
                or not session.matches_issuer(issuer)
                or session.identity().user_id != account_id):
            raise LinkingError('The current gallery connection changed.')
        journal = self._create_journal.for_request(
            snapshot.issuer, snapshot.account_id, snapshot.title, snapshot.parent_id,
        )
        journal.acknowledge(
            snapshot.issuer, snapshot.account_id, snapshot.receipt,
        )

    def record_photos_creation_failure(
        self, operation: str, error: BaseException,
    ) -> None:
        """Retain fixed safe facts about one Photos create failure.

        Args:
            operation (str): Fixed Photos journal operation label.
            error (BaseException): Failure whose raw message is omitted; only
                owned source locations and validated response facts are retained.

        Returns:
            None: A bounded diagnostic summary is retained for support.
        """
        allowed_operations = {'inspect', 'status', 'acknowledge', 'create'}
        safe_operation = operation if operation in allowed_operations else 'create'
        error_type = type(error).__name__
        if not error_type.isascii() or not error_type.isidentifier():
            error_type = 'Exception'
        site = 'unknown'
        for frame in reversed(traceback.extract_tb(error.__traceback__)):
            basename = Path(frame.filename).name
            if (basename in (
                    'manager.py', 'dialog.py', 'connection.py', 'polling.py',
                    'picker_behavior.py', 'linked_create.py', 'core.py',
                    'transport.py', 'attempt.py', 'session.py',
                ) and frame.name.isidentifier() and frame.lineno > 0):
                site = f'{basename}:{frame.name}:{frame.lineno}'
                break
        response_summary = ''
        if isinstance(error, LinkingResponseError):
            try:
                validated = LinkingResponseError(
                    error.phase, error.route, error.status,
                    error.content_kind, error.reason,
                    media_policy_facts=error.media_policy_facts,
                )
            except (AttributeError, TypeError, ValueError):
                try:
                    validated = LinkingResponseError(
                        error.phase, error.route, error.status,
                        error.content_kind, error.reason,
                    )
                except (AttributeError, TypeError, ValueError):
                    validated = None
            if validated is not None:
                response_summary = '; ' + validated.safe_summary
        self._record_diagnostic(
            f'stage=photos_create; operation={safe_operation}; '
            f'error_type={error_type}; site={site}{response_summary}',
            failure=True,
        )

    def acknowledge_photos_gallery(
        self, session: LinkingSession, receipt: BoundCreateReceipt,
    ) -> None:
        """Consume an exact Photos receipt for compatibility callers.

        Args:
            session (LinkingSession): Live link that completed the creation.
            receipt (BoundCreateReceipt): Exact completed gallery and intent binding.

        Returns:
            None: Removes the matching local receipt.

        Raises:
            LinkingError: The live account or saved receipt did not match.
        """
        issuer, account = self._session_issuer, self._session_user_id
        if session is not self.photos_session() or session.identity().user_id != account:
            raise LinkingError('Photos gallery receipt is unavailable.')
        journal = self._create_journal.for_request(
            issuer, account, receipt.gallery.title, receipt.gallery.parent_id,
        )
        journal.acknowledge(issuer, account, receipt)

    def photos_session(self) -> LinkingSession | None:
        """Return the exact live current link that can list gallery photos.

        Returns:
            LinkingSession | None: Current available listing session, or None
                when no current listing grant is available.
        """
        session = self._session
        if (self._shutting_down or session is None
                or self._session_issuer != self._current_issuer
                or not session.available() or not session.can_list_galleries()):
            return None
        return session

    def resume_upload_session(
        self, issuer: str, account_id: int,
    ) -> LinkingSession | None:
        """Select an already restored exact account without requesting consent.

        Args:
            issuer (str): Trusted saved upload issuer from the mode owner.
            account_id (int): Original verified Admin identity to preserve.

        Returns:
            LinkingSession | None: Existing live listing/upload approval for
                the same site/account, or None while absent, expired or revoked.
                This selection neither publishes upload mode nor opens a key.
        """
        if self._shutting_down:
            return None
        for site in self._live_links():
            session = site.session
            if (site.issuer == issuer and site.user_id == account_id
                    and session.available() and session.can_list_galleries()
                    and session.can_upload()):
                if session is not self.photos_session():
                    self._show_link(site)
                return session
        return None

    def activate_verified_resume_target(
        self, target: LinkedUploadTarget, binding: str, approved_scope: str,
        *, verify_committed: Callable[[], bool] | None = None,
    ) -> bool:
        """Publish a fresh server result only for the saved continuation target.

        Args:
            target (LinkedUploadTarget): Exact gallery/account freshly verified
                by the continuation worker using the restored live grant.
            binding (str): Original target fingerprint from the claimed update.
            approved_scope (str): Exact original scope captured before updating.
            verify_committed (Callable[[], bool] | None): Readback of the exact
                mode already published by the host's guarded background worker;
                None retains the established admission/publication path.

        Returns:
            bool: True after the existing target guard and mode owner accept
                this same saved target; False preserves the prior selection.
        """
        identity = getattr(self, '_saved_upload_identity', None)
        commit = self._commit_linked_target
        try:
            saved = identity() if identity is not None else None
            if (self._shutting_down or commit is None
                    or saved != (target.issuer, binding)
                    or target.binding() != binding
                    or target.session is not self.photos_session()
                    or target.issuer != self._session_issuer
                    or target.user_id != self._session_user_id
                    or not target.session.can_upload()
                    or target.session.approved_scope() != approved_scope):
                return False
            if verify_committed is None:
                if self._target_refusal(binding) is not None or not commit(target):
                    return False
            elif not verify_committed():
                return False
        except Exception:
            return False
        self._active_upload_target = target
        self._notify_target_changed()
        return True

    def photos_site_identity(
        self, session: LinkingSession,
    ) -> tuple[str, int] | None:
        """Return the current canonical site and verified Photos account.

        Args:
            session (LinkingSession): Exact session supplied by Photos.

        Returns:
            tuple[str, int] | None: Issuer and positive account ID when the
                session remains current, or None when it is stale or unavailable.
        """
        if (session is not self.photos_session()
                or session is not self._session or not session.available()
                or self._session_user_id <= 0
                or not session.matches_issuer(self._session_issuer)):
            return None
        return self._session_issuer, self._session_user_id

    def open_photos_gallery_editor(
        self, session: LinkingSession, gallery: LinkedGallery,
    ) -> bool:
        """Open the selected gallery editor using the browser's own session.

        Args:
            session (LinkingSession): Exact live Photos session captured by
                the gallery context.
            gallery (LinkedGallery): Exact server-listed gallery to edit.

        Returns:
            bool: True when the fixed same-site editor URL opens; False for a
                stale session, invalid target or unavailable browser.
        """
        identity = self.photos_site_identity(session)
        if identity is None:
            return False
        issuer, _account_id = identity
        url = gallery_editor_url(session, issuer, gallery.gallery_id)
        if url is None:
            return False
        try:
            return bool(webbrowser.open(url))
        except Exception:  # noqa: BLE001
            return False





    def _live_links(self) -> tuple[_LinkedSite, ...]:
        """Project the selected and parked sessions that are still available.

        Returns:
            tuple[_LinkedSite, ...]: The selected live site followed by other
                live sites, each bound to its issuer, session and Admin ID.
        """
        selected = (
            _LinkedSite(self._session_issuer, self._session, self._session_user_id)
            if self._session is not None else None
        )
        return live_link_sites(selected, self._parked_links)

    def _live_link(self, issuer: str) -> _LinkedSite | None:
        """Find a still-live approval for the exact chosen installation.

        Args:
            issuer (str): Canonical issuer or empty when the field is invalid.

        Returns:
            _LinkedSite | None: Matching live session with its Admin ID, or None.
        """
        return find_live_link(self._live_links(), issuer)

    def _park_current_link(self) -> None:
        """Keep the selected live approval available while leaving its view.

        Returns:
            None: Preserves the exact in-memory session and account for switching.
        """
        session = self._session
        if session is not None:
            site = _LinkedSite(self._session_issuer, session, self._session_user_id)
            self._parked_links = (site, *self._parked_links)

    def _show_link(self, site: _LinkedSite) -> None:
        """Select a verified live site independently of the upload target.

        Args:
            site (_LinkedSite): Exact live session and approving account.

        Returns:
            None: Publishes the selected link and resets the previous gallery view.
        """
        if self._session is not site.session:
            self._park_current_link()
        self._parked_links = tuple(
            link for link in self._parked_links if link.issuer != site.issuer
        )
        self._reconnect_issuers.discard(site.issuer)
        self._session = site.session
        self._session_issuer = self._current_issuer = site.issuer
        self._session_user_id = site.user_id
        self._reset_gallery_choices()
        self._gallery_status.set(
            gettext('Loading galleries…') if site.session.can_list_galleries()
            else gettext('This link does not include the gallery list.'),
        )
        self._status.set('')
        self._remember_link(site.issuer)
        self._linked_address.set(site.issuer)

    def _remember_link(self, issuer: str) -> None:
        """Move an explicitly used installation to the front of recent addresses.

        Args:
            issuer (str): Exact previously verified installation.

        Returns:
            None: Records the address locally when profile storage is available.
        """
        addresses = (issuer, *(link for link in self._recent_links if link != issuer))
        self._save_recent_links(addresses[:RECENT_GALLERY_ADDRESS_LIMIT])

    def _save_recent_links(self, addresses: tuple[str, ...]) -> bool:
        """Publish a complete address-only history update.

        Args:
            addresses (tuple[str, ...]): Unique canonical issuers in recency order.

        Returns:
            bool: True if the current history is verified or saved. False shows
                a local storage error and retains the previous list.
        """
        if addresses == self._recent_links:
            return True
        try:
            if self._recent_link_path is not None:
                save_recent_gallery_addresses(self._recent_link_path, addresses)
        except (OSError, ValueError):
            self._status.set(self._text('linking_address_history_error'))
            return False
        self._recent_links = addresses
        return True

    def _clear_session_display(self) -> None:
        """Remove the live link and its list from the Tk workspace.

        Returns:
            None: Clears the link projection and browsing selection.
        """
        self._session = None
        self._session_issuer = ''
        self._session_user_id = 0
        self._reset_gallery_choices()
        self._linked_address.set('')

    def _reset_gallery_choices(self) -> None:
        """Clear all gallery options owned by the previous site view.

        Returns:
            None: Cancels stale reads and clears their selection and search.
        """
        self._cancel_gallery_search()
        self._gallery_query = ''
        self._gallery_browse_page = None
        self._gallery_catalog_limited = False
        self._gallery_page = None
        self._detail_snapshot = None
        self._selected_gallery = None
        if self._window is not None:
            self._gallery_choice.query.set('')
        self._display_gallery_page()

    def _listing_scope_selected(self, issuer: str) -> bool:
        """Default new links to listing consent and reflect existing grants.

        Args:
            issuer (str): Canonical issuer displayed by the linking dialog.

        Returns:
            bool: True for a new connection or an existing listing grant for
                this issuer; other existing sessions remain unselected.
        """
        if self._session is None:
            return True
        return (self._session_issuer == issuer
                and self._session.can_list_galleries())

    def _upload_scope_selected(self, issuer: str) -> bool:
        """Reflect an existing upload grant, never preselecting new authority.

        Args:
            issuer (str): Canonical issuer displayed by the linking dialog.

        Returns:
            bool: True only for a live upload-scoped session on this issuer.
        """
        return (self._session is not None and self._session_issuer == issuer
                and self._session.can_upload())

    def _read_scope_selected(self, issuer: str) -> bool:
        """Reflect only an already approved read scope for the current issuer.

        Args:
            issuer (str): Canonical issuer displayed by the linking dialog.

        Returns:
            bool: True only for a live session explicitly permitting detail.
        """
        return (self._session is not None and self._session_issuer == issuer
                and self._session.can_read_gallery_detail())

    def _scope_changed(self) -> None:
        """Keep explicit write and read consents dependent on listing.

        Returns:
            None: Enables listing for dependent scopes and keeps media access temporary.
        """
        if not _dialog.LINKING_VISIBILITY_READY:
            self._visibility_var.set(False)
        elif self._visibility_var.get():
            self._read_var.set(True)
        media_var = getattr(self, '_media_var', None)
        media = bool(media_var is not None and media_var.get())
        if media:
            self._offline_var.set(False)
        if media or self._upload_var.get() or self._read_var.get() or self._create_var.get():
            self._browse_var.set(True)
        if media_var is not None:
            self._refresh(self._current_issuer)

    def _inspect_capabilities(self, issuer: str) -> None:
        """Read the explicit installation's public capability list on open.

        Args:
            issuer (str): Canonical HTTPS origin and mount shown in the dialog.

        Returns:
            None: Starts a read without sending credentials or opening a browser.
        """
        if self._worker is not None and self._worker.is_alive():
            return
        owner, cancel = object(), threading.Event()
        self._owner, self._cancel = owner, cancel
        self._status.set(self._text('linking_requesting'))

        def worker() -> None:
            """Deliver the read result only to the original dialog owner.

            Returns:
                None: Queues the exact issuer and verified capability names.
            """
            capabilities = discover_linking_capabilities(issuer, cancel)
            self._events.put((owner, 'capabilities', (issuer, capabilities)))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryLinkDiscovery', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._status.set(self._text('linking_server_unavailable'))
            return
        self._schedule_poll()

    def selected_upload_target(self) -> LinkedUploadTarget | None:
        """Capture the currently approved gallery without persisting its token.

        Returns:
            LinkedUploadTarget | None: Live scoped target for the selected
                issuer and Admin, or None if access or selection is unavailable.
        """
        target = self._active_upload_target
        if (target is None or not target.session.can_upload()
                or not target.session.matches_issuer(target.issuer)):
            return None
        return target

    def linked_upload_mode_selected(self) -> bool:
        """Retain explicit linked mode even when its token expires.

        Returns:
            bool: True until the user explicitly returns to the saved API key.
        """
        saved_mode = getattr(self, '_saved_linked_selected', None)
        return (self._active_upload_target is not None
                or (saved_mode is not None and saved_mode()))

    def linked_upload_binding(self) -> str | None:
        """Read the selected account/gallery binding without using a token.

        Returns:
            str | None: Current or verified saved destination fingerprint, or
                None without a confirmed linked selection.
        """
        if self._active_upload_target is not None:
            return self._active_upload_target.binding()
        identity = self._saved_upload_identity() if self._saved_upload_identity else None
        return identity[1] if identity is not None else None

    def _target_refusal(self, binding: str | None) -> str | None:
        """Consult the host's pending-work guard before replacing a target.

        Args:
            binding (str | None): Proposed non-secret target fingerprint, or
                None for the saved API-key connection.

        Returns:
            str | None: User-facing blocker, or None if the host admits the change.
        """
        check = self._target_change_refusal
        return check(binding) if check is not None else None

    def _notify_target_changed(self) -> None:
        """Refresh the owning application's target presentation when present.

        Returns:
            None: Calls the optional UI-thread callback after a target choice.
        """
        if self._target_changed is not None:
            self._target_changed()



    def _save_photos_upgrade(
        self, store: NativeCredentialStore, binding: OfflineBinding,
        previous_scope: str | None, refresh: str, remaining: int,
        commit: Callable[[], bool] | None = None,
    ) -> None:
        """Replace the saved Photos authority and optionally publish upload mode.

        Args:
            store (NativeCredentialStore): Current user's native credential store.
            binding (OfflineBinding): Exact newly approved Admin Photos scope.
            previous_scope (str | None): Exact current scope before approval.
            refresh (str): New, verified rolling refresh credential.
            remaining (int): Conservative remaining refresh lifetime in seconds.
            commit (Callable[[], bool] | None): Host publication for a verified
                upload target, or None for a Photos-only connection.

        Returns:
            None: The new credential and any requested upload mode were accepted.

        Raises:
            LinkingError: Persistence or mode publication failed. Existing
                credential and hint are restored if local storage permits.
            RuntimeError: Native storage or binding-hint publication failed.
        """
        persist_browser_scope_upgrade(
            self._offline_path, store, binding, previous_scope,
            refresh, remaining, commit,
        )

    def _activate_photos_upload_upgrade(self, upgrade: _PhotosUploadUpgrade) -> None:
        """Activate the new grant only after admitting the same upload gallery.

        Args:
            upgrade (_PhotosUploadUpgrade): Verified new target and previous selection.

        Returns:
            None: Publishes the exact mode or leaves the previous target selected.
        """
        previous, target, identity, activate = upgrade
        commit = self._commit_linked_target
        if (self._active_upload_target is not previous
                or self._session_issuer != target.issuer
                or self._current_issuer != target.issuer
                or self._session_user_id != identity.user_id
                or self._cancel.is_set() or commit is None):
            target.session.close()
            self._status.set(gettext('The selected gallery changed during approval.'))
            return
        refusal = self._target_refusal(target.binding())
        if refusal is not None:
            target.session.close()
            self._status.set(refusal)
            return
        try:
            activate(lambda: commit(target))
        except Exception:  # noqa: BLE001
            target.session.close()
            self._status.set(gettext('The approved Photos connection could not be saved safely.'))
            return
        self._active_upload_target = target
        self._show_link(_LinkedSite(target.issuer, target.session, identity.user_id))
        self._dismiss_address_prompt()
        self._root.lift()
        self._notify_target_changed()

    def _requested_scope(self) -> str:
        """Build the exact scope chosen in the advanced access workspace.

        Returns:
            str: Temporary or explicitly selected offline scope bundle.

        Raises:
            LinkingError: A chosen capability or secure storage is unavailable.
        """
        media_var = getattr(self, '_media_var', None)
        return requested_linking_scope(
            list_galleries=self._browse_var.get(),
            upload=self._upload_var.get(),
            read_details=self._read_var.get(),
            create_galleries=self._create_var.get(),
            change_visibility=self._visibility_var.get(),
            read_media=bool(media_var is not None and media_var.get()),
            remember_securely=self._offline_var.get(),
            capabilities=frozenset(self._capabilities),
            visibility_ready=_dialog.LINKING_VISIBILITY_READY,
            native_media_ready=_dialog.LINKING_NATIVE_MEDIA_READY,
            offline_storage_ready=(
                self._offline_store is not None and self._offline_binding is None
            ),
        )

    def _create_gallery(self) -> None:
        """Ask for a title and explicit parent before starting one mutation.

        Returns:
            None: Starts one journalled create or leaves the gallery unchanged.
        """
        session = self._session
        if (session is None or self._window is None
                or self._session_issuer != self._current_issuer
                or not session.can_create_gallery()
                or self._worker is not None and self._worker.is_alive()):
            return
        title = simpledialog.askstring(
            gettext('Create gallery'), gettext('Gallery title:'), parent=self._window,
        )
        if title is None:
            return
        parent_id = None
        page, index = self._gallery_page, self._gallery_choice.current()
        if page is not None and 0 <= index < len(page.galleries):
            parent = page.galleries[index]
            use_parent = messagebox.askyesnocancel(
                gettext('Create gallery'),
                gettext('Create inside {gallery}? Choose No to create at the root.').format(
                    gallery=_gallery_label(parent),
                ),
                parent=self._window,
            )
            if use_parent is None:
                return
            if use_parent:
                parent_id = parent.gallery_id
        self._start_gallery_create(session, title, parent_id)

    def _reconcile_gallery_create(self) -> None:
        """Explicitly request only the saved operation's read-only status.

        Returns:
            None: Starts a status check without sending another create request.
        """
        session = self._session
        if (session is None or self._session_issuer != self._current_issuer
                or not session.can_create_gallery()
                or self._worker is not None and self._worker.is_alive()):
            return
        self._start_gallery_create(session, None, None)

    def _start_gallery_create(
        self, session: LinkingSession, title: str | None, parent_id: int | None,
        resume_intent: CreateIntent | None = None,
        abandon_intent: CreateIntent | None = None,
    ) -> None:
        """Run one creation, same-key action or read-only status check.

        Args:
            session (LinkingSession): Current explicitly authorized session.
            title (str | None): New title, or None for status, resume or closure.
            parent_id (int | None): Chosen parent ID for creation, or root.
            resume_intent (CreateIntent | None): Original reviewed request for
                an explicit same-key resume, or None for the other actions.
            abandon_intent (CreateIntent | None): Original reviewed request for
                explicit server-side closure, or None for the other actions.

        Returns:
            None: Queues a validated receipt, closure or unresolved result.
        """
        owner, issuer, account = object(), self._session_issuer, self._session_user_id
        dispatch = (CreateDispatch() if title is not None or resume_intent is not None
                    or abandon_intent is not None else None)
        self._create_dispatch = dispatch
        self._owner = owner
        self._gallery_status.set(gettext('Checking gallery creation…'))

        def worker() -> None:
            """Perform the single journalled action without accessing Tk.

            Returns:
                None: Queues a receipt or an unresolved status for the dialog.
            """
            try:
                if abandon_intent is not None:
                    bound = self._create_journal.abandon(session, abandon_intent, dispatch)
                    self._events.put((owner, 'create_completed' if bound
                                      else 'create_abandoned',
                                      (bound, session, issuer, account) if bound else None))
                    return
                if resume_intent is not None:
                    bound = self._create_journal.resume(session, resume_intent, dispatch)
                elif title is None:
                    bound = self._create_journal.reconcile(session, issuer, account)
                else:
                    journal = self._create_journal.for_request(
                        issuer, account, title, parent_id,
                    )
                    bound = journal.create_or_resume(
                        session, issuer, account, title, parent_id, dispatch,
                    )
                self._events.put((owner, 'create_completed' if bound else 'create_unknown',
                                  (bound, session, issuer, account) if bound else None))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'create_failed' if title is not None
                                  else 'create_unknown', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryCreate', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._gallery_status.set(gettext('Could not start gallery creation.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _resume_gallery_create(self) -> None:
        """Load the original intent before asking to resume that exact request.

        Returns:
            None: Queues a reviewable intent or an unavailable status.
        """
        self._preview_gallery_create(False)

    def _abandon_gallery_create(self) -> None:
        """Review the unresolved request before explicitly closing its key.

        Returns:
            None: Starts a local preview without closing or deleting anything.
        """
        self._preview_gallery_create(True)

    def _preview_gallery_create(self, abandon: bool) -> None:
        """Load one saved request for explicit resume or closure confirmation.

        Args:
            abandon (bool): True to prepare closure, false to prepare resume.

        Returns:
            None: Queues a reviewable intent or an unavailable status.
        """
        session = self._session
        if (session is None or self._session_issuer != self._current_issuer
                or not session.can_create_gallery()
                or self._worker is not None and self._worker.is_alive()):
            return
        owner, issuer, account = object(), self._session_issuer, self._session_user_id
        self._owner = owner
        self._gallery_status.set(gettext('Reading the original creation request…'))

        def worker() -> None:
            """Read only the validated profile-bound intent off the Tk thread.

            Returns:
                None: Queues the original intent or a generic refusal.
            """
            try:
                intent = self._create_journal.pending_intent(issuer, account)
                self._events.put((owner, 'create_abandon_preview' if abandon
                                  else 'create_resume_preview', intent))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'create_resume_unavailable', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryResumePreview', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._gallery_status.set(gettext('Could not read the original creation request.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _acknowledge_created_gallery(self) -> None:
        """Optionally discard the displayed completed creation receipt.

        Returns:
            None: Starts local cleanup for the current verified account.
        """
        created = self._created_gallery
        if (created is None or self._session is not created[1]
                or self._session_issuer != created[2] or self._current_issuer != created[2]
                or self._session_user_id != created[3]
                or not created[1].available()
                or self._worker is not None and self._worker.is_alive()):
            return
        bound, _session, issuer, account = created
        self._detail_snapshot = None
        owner = object()
        self._owner = owner
        self._gallery_status.set(gettext('Acknowledging the saved creation receipt…'))

        def worker() -> None:
            """Clear only the completed receipt explicitly acknowledged here.

            Returns:
                None: Queues success or a bounded refusal for the dialog.
            """
            try:
                journal = self._create_journal.for_request(
                    issuer, account, bound.gallery.title, bound.gallery.parent_id,
                )
                journal.acknowledge(issuer, account, bound)
                self._events.put((owner, 'create_acknowledged', None))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'create_ack_error', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryCreateAcknowledge', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._gallery_status.set(gettext('Could not acknowledge the creation receipt.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()



    def _review_uncertain_upload(self) -> None:
        """Ask the operator to keep the unknown source and continue other work.

        Returns:
            None: Records review of the exact marker only after confirmation;
                a stale choice, cancellation or failed write changes nothing.
        """
        target, review = self.selected_upload_target(), self._recovery_review
        window = self._window
        if (target is None or review is None or window is None
                or self._worker is not None and self._worker.is_alive()):
            return
        marker = review(target, None)
        if marker is None:
            self._gallery_status.set(self._text('linking_review_unavailable'))
            return
        if not messagebox.askyesno(
            self._text('linking_review_title'),
            self._text('linking_review_prompt').format(gallery=target.title),
            parent=window, default=messagebox.NO,
        ):
            return
        if self._window is not window:
            return
        if (self.selected_upload_target() is not target
                or review(target, marker) != marker):
            self._gallery_status.set(self._text('linking_review_unavailable'))
            return
        self._gallery_status.set(self._text('linking_review_done'))

    def _check_uncertain_upload(self) -> None:
        """Start one explicit observation of an unresolved linked upload.

        Returns:
            None: Starts a bounded worker, or leaves the marker untouched.
        """
        target, probe = self.selected_upload_target(), self._recovery_probe
        if (target is None or probe is None or self._recovery_publish is None
                or self._session is not target.session
                or self._session_issuer != target.issuer
                or self._session_user_id != target.user_id
                or self._worker is not None and self._worker.is_alive()):
            return
        size = simpledialog.askinteger(
            gettext('Check uncertain upload'),
            gettext(
                "Enter the original file size in bytes. No local file is opened. "
                "A match only reports observed bytes and does not confirm an upload."
            ),
            parent=self._window, minvalue=1, maxvalue=LINKING_RECOVERY_MAX_FILE_BYTES,
        )
        if (size is None or self._window is None
                or self.selected_upload_target() is not target
                or self._session is not target.session
                or self._session_issuer != target.issuer
                or self._session_user_id != target.user_id):
            return
        owner = object()
        self._owner = owner
        self._gallery_status.set(gettext('Checking the uncertain upload once…'))

        def worker() -> None:
            """Observe the journaled hash without accessing Tk or its source.

            Returns:
                None: Queues an observation or a bounded failure.
            """
            try:
                result, file_hash = probe(target, size)
                self._events.put((owner, 'recovery_observed', (target, result, file_hash)))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'recovery_error', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryRecoveryObservation', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._gallery_status.set(gettext('Could not start the upload observation.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _read_detail(self) -> None:
        """Request only the currently selected gallery's approved detail.

        Returns:
            None: Starts a bounded worker or ignores an unavailable selection.
        """
        session, page = self._session, self._gallery_page
        if (session is None or page is None or self._session_issuer != self._current_issuer
                or not session.can_read_gallery_detail()
                or self._worker is not None and self._worker.is_alive()):
            return
        index = self._gallery_choice.current()
        if index < 0 or index >= len(page.galleries):
            return
        gallery_id = page.galleries[index].gallery_id
        self._detail_snapshot = None
        owner, cancel = object(), threading.Event()
        self._owner, self._gallery_cancel = owner, cancel
        self._gallery_status.set(gettext('Reading the selected gallery details…'))

        def worker() -> None:
            """Read one selected gallery without touching Tk widgets.

            Returns:
                None: Queues a validated detail or a generic failure marker.
            """
            try:
                detail = session.gallery_detail(gallery_id, cancelled=cancel)
                self._events.put((owner, 'detail', detail))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'detail_error', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryLinkedDetail', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._gallery_status.set(gettext('Could not start the gallery detail request.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()


    def _change_visibility(self) -> None:
        """Confirm and submit one change using the current live detail.

        Returns:
            None: Starts one worker or leaves the prior selection unchanged.
        """
        snapshot = self._detail_snapshot
        if not _dialog.LINKING_VISIBILITY_READY:
            return
        session, page = self._session, self._gallery_page
        selected = self._gallery_choice.current()
        labels = _visibility_labels()
        selected_label = self._visibility_choice.get()
        target = next(
            (code for code, label in labels.items() if label == selected_label),
            "",
        )
        if (snapshot is None or session is not snapshot[1]
                or self._session_issuer != self._current_issuer
                or self._current_issuer != snapshot[2]
                or self._session_user_id != snapshot[3]
                or page is None or not 0 <= selected < len(page.galleries)
                or page.galleries[selected].gallery_id != snapshot[0].gallery_id
                or not session.can_change_gallery_visibility()
                or self._worker is not None and self._worker.is_alive()
                or target not in ('public', 'private', 'unpublished')):
            return
        detail = snapshot[0]
        previous = 'unpublished' if detail.visibility == 'draft' else detail.visibility
        if target == previous:
            self._gallery_status.set(gettext('Choose a different visibility.'))
            return
        if not messagebox.askyesno(
            self._text('linking_visibility_action'),
            self._text('linking_visibility_confirm').format(
                gallery=detail.gallery_id,
                previous=labels.get(previous, gettext(previous)),
                target=labels[target],
            ),
            parent=self._window, default=messagebox.NO,
        ):
            return
        if (self._window is None or self._detail_snapshot is not snapshot
                or self._session is not session or not session.available()
                or self._gallery_choice.current() != selected):
            return
        self._detail_snapshot = None
        self._visibility_choice.set('')
        owner, cancel = object(), threading.Event()
        self._owner, self._gallery_cancel = owner, cancel
        self._gallery_status.set(self._text('linking_visibility_saving'))

        def worker() -> None:
            """Send one mutation and reload a confirmed revision conflict.

            Returns:
                None: Queues verified detail, conflict readback or an uncertain
                    result without retrying the mutation.
            """
            try:
                result = session.change_gallery_visibility(detail, target, cancel)
                self._events.put((owner, 'visibility_done', result))
            except LinkingScheduledPublication:
                self._events.put((owner, 'visibility_scheduled', None))
            except LinkingRevisionConflict:
                try:
                    current = session.gallery_detail(detail.gallery_id, cancel)
                except LinkingError:
                    current = None
                self._events.put((owner, 'visibility_conflict', current))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'visibility_unknown', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryVisibility', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._gallery_status.set(gettext('Could not start the visibility request; read details again.'))
            self._refresh(self._current_issuer)
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _use_created_gallery(self) -> None:
        """Revalidate a created gallery before selecting it as upload target.

        Returns:
            None: Starts a fresh status read for the same live upload grant,
                or displays why the target cannot be selected.
        """
        if not (_dialog.LINKING_UPLOAD_TARGET_READY and _dialog.LINKING_CREATED_TARGET_READY):
            self._gallery_status.set(
                gettext(
                    "Linked upload selection is temporarily unavailable. "
                    "The current connection remains selected."
                ),
            )
            return
        if not self._check_upload_workspace():
            return
        created = self._created_gallery
        if created is None or (self._worker is not None and self._worker.is_alive()):
            return
        bound, session, issuer, account = created
        if (self._session is not session or self._session_issuer != issuer
                or self._current_issuer != issuer or self._session_user_id != account
                or getattr(self, '_commit_linked_target', None) is None):
            self._gallery_status.set(gettext('Reconnect and verify the created gallery first.'))
            return
        try:
            if (not session.can_upload() or not session.can_create_gallery()
                    or not session.matches_issuer(issuer)):
                raise LinkingError('Gallery session is unavailable.')
            scope = session.approved_scope()
        except Exception:
            self._gallery_status.set(gettext('Reconnect and verify the created gallery first.'))
            return
        owner = object()
        cancel = threading.Event()
        self._owner = owner
        self._gallery_cancel = cancel
        self._gallery_status.set(gettext('Verifying the created gallery before selection…'))

        def worker() -> None:
            """Verify the original receipt and the independent current gallery.

            Returns:
                None: Queues only one owner-bound, freshly verified result.
            """
            try:
                journal = self._create_journal.for_request(
                    issuer, account, bound.gallery.title, bound.gallery.parent_id,
                )
                current = journal.reconcile(session, issuer, account)
                if cancel.is_set() or current != bound:
                    raise LinkingError('Gallery creation status changed.')
                target = verify_linked_upload_target(
                    session, issuer, account, scope, bound.gallery.gallery_id,
                    cancel,
                )
                if cancel.is_set() or target.gallery_id != current.gallery.gallery_id:
                    raise LinkingError('Created gallery target changed.')
                self._events.put((
                    owner, 'create_selection_verified',
                    (current, target, session, issuer, account, scope, cancel),
                ))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'create_selection_error', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryCreateSelection', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._gallery_status.set(gettext('Could not verify the created gallery.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _apply_created_gallery(
        self, bound: BoundCreateReceipt, target: LinkedUploadTarget,
        session: LinkingSession, issuer: str, account: int, scope: str,
        cancelled: threading.Event,
    ) -> None:
        """Apply a freshly checked receipt through the host target-change guard.

        Args:
            bound (BoundCreateReceipt): Exact original create-intent receipt.
            target (LinkedUploadTarget): Independently verified live gallery.
            session (LinkingSession): Original browser-approved upload grant.
            issuer (str): Exact installation captured before remote verification.
            account (int): Server-confirmed original Admin account ID.
            scope (str): Exact approved upload scope captured by the worker.
            cancelled (threading.Event): Original worker cancellation token.

        Returns:
            None: Changes the target only for the still-current upload session.
        """
        if not (_dialog.LINKING_UPLOAD_TARGET_READY and _dialog.LINKING_CREATED_TARGET_READY):
            self._gallery_status.set(
                gettext(
                    "Linked upload selection is temporarily unavailable. "
                    "The current connection remains selected."
                ),
            )
            return
        created = self._created_gallery
        commit = getattr(self, '_commit_linked_target', None)
        if (created is None or created[0] != bound
                or type(cancelled) is not threading.Event
                or self._gallery_cancel is not cancelled or cancelled.is_set()
                or created[1] is not session or created[2] != issuer
                or created[3] != account or commit is None
                or self._session is not session or self._session_issuer != issuer
                or self._current_issuer != issuer or self._session_user_id != account
                or target.session is not session or target.issuer != issuer
                or target.user_id != account
                or target.gallery_id != bound.gallery.gallery_id):
            self._gallery_status.set(gettext('The created gallery verification is no longer current.'))
            return
        try:
            if (not session.can_upload() or not session.can_create_gallery()
                    or not session.matches_issuer(issuer)
                    or session.approved_scope() != scope):
                raise LinkingError('Created gallery session changed.')
        except Exception:
            self._gallery_status.set(gettext('The created gallery verification is no longer current.'))
            return

        previous_target = self._active_upload_target
        previous_gallery = self._selected_gallery
        publication_attempted = False

        def apply() -> bool:
            """Admit the same local target while its receipt lease remains held.

            Returns:
                bool: True only when the current host permits the target change.
            """
            nonlocal publication_attempted
            if (not _dialog.LINKING_UPLOAD_TARGET_READY or not _dialog.LINKING_CREATED_TARGET_READY
                    or self._created_gallery is not created or self._session is not session
                    or self._session_issuer != issuer or self._current_issuer != issuer
                    or self._session_user_id != account or not session.can_upload()
                    or not session.matches_issuer(issuer)
                    or self._gallery_cancel is not cancelled or cancelled.is_set()
                    or session.approved_scope() != scope
                    or self._target_refusal(target.binding()) is not None):
                return False
            # The receipt lease protects the original creation; mode publication
            # still belongs to the host and its exact coordinator-issued view.
            publication_attempted = True
            if not commit(target):
                return False
            gallery = LinkedGallery(
                target.gallery_id, bound.gallery.parent_id, target.title,
            )
            self._active_upload_target = target
            self._selected_gallery = gallery
            self._gallery_status.set(
                gettext('Linked upload target: ') + _gallery_label(gallery)
                + gettext('. The saved API key remains unchanged.'),
            )
            try:
                self._notify_target_changed()
            except Exception:
                self._gallery_status.set(
                    gettext(
                        "Upload mode was activated, but the display did not refresh. "
                        "Reopen the connection view to verify the target."
                    ),
                )
            return True

        try:
            journal = self._create_journal.for_request(
                issuer, account, bound.gallery.title, bound.gallery.parent_id,
            )
            accepted = journal.apply_receipt(
                issuer, account, bound, apply,
            )
        except Exception:  # noqa: BLE001
            accepted = False
        if accepted:
            return
        if publication_attempted:
            # A failed publish may have changed one persistent owner. Never
            # advertise the previous destination or silently restore a key.
            self._active_upload_target = None
            self._selected_gallery = None
            try:
                self._notify_target_changed()
            except Exception:  # noqa: BLE001
                # A closed or broken Tk view must not undo the target rollback.
                pass
            self._gallery_status.set(
                gettext(
                    "Linked upload mode is unconfirmed. Reconnect and verify the "
                    "saved mode before attempting any upload."
                ),
            )
            return
        # Retain the previous target even if the host cannot repaint.
        self._active_upload_target = previous_target
        self._selected_gallery = previous_gallery
        self._gallery_status.set(
            gettext(
                "Finish or resolve existing upload work and verify the original "
                "creation receipt before changing target."
            ),
        )

    def _apply_verified_upload_target(
        self, target: LinkedUploadTarget, session: LinkingSession,
        issuer: str, account: int, gallery: LinkedGallery, scope: str,
        cancelled: threading.Event,
    ) -> bool:
        """Commit only the fresh server result still selected on Tk.

        The background verifier checks current identity and the exact gallery.
        This event handoff rechecks session, page and selected row before the
        existing host authorizes durable mode publication and activation.

        Args:
            target (LinkedUploadTarget): Fresh server-verified gallery target.
            session (LinkingSession): Original scoped session owner.
            issuer (str): Exact original canonical gallery issuer.
            account (int): Original verified Admin account identifier.
            gallery (LinkedGallery): Original user-selected gallery row.
            scope (str): Exact upload grant verified by the background worker.
            cancelled (threading.Event): Original worker cancellation token.

        Returns:
            bool: True after publication and display refresh. False leaves
                the picker open for a refusal, uncertainty or display failure.
        """
        page = self._gallery_page
        index = self._gallery_choice.current()
        commit = getattr(self, '_commit_linked_target', None)
        try:
            same_scope = (session.can_upload() and session.matches_issuer(issuer)
                          and session.approved_scope() == scope)
        except Exception:
            same_scope = False
        if not same_scope:
            # A changed grant also invalidates any previous local target.
            # Never infer that the saved API key is the fallback destination.
            self._active_upload_target = None
            self._selected_gallery = None
            try:
                self._notify_target_changed()
            except Exception:  # noqa: BLE001
                pass
            self._gallery_status.set(
                gettext(
                    "Linked upload permission changed; reconnect and verify the "
                    "saved mode before uploading."
                ),
            )
            return False
        if (not _dialog.LINKING_UPLOAD_TARGET_READY
                or type(cancelled) is not threading.Event
                or self._gallery_cancel is not cancelled or cancelled.is_set()
                or commit is None or self._session is not session
                or self._session_issuer != issuer or self._current_issuer != issuer
                or self._session_user_id != account
                or target.session is not session or target.issuer != issuer
                or target.user_id != account or target.gallery_id != gallery.gallery_id
                or page is None or not 0 <= index < len(page.galleries)
                or page.galleries[index] is not gallery):
            self._gallery_status.set(
                gettext(
                    "The gallery selection changed during verification. "
                    "The previous upload target remains unchanged."
                ),
            )
            return False
        refusal = self._target_refusal(target.binding())
        if refusal is not None:
            self._gallery_status.set(refusal)
            return False
        try:
            committed = commit(target)
        except Exception:  # noqa: BLE001
            committed = False
        if not committed:
            # The host cannot distinguish a refusal from partial publication.
            # Discard a prior local target even if its session is still live.
            self._active_upload_target = None
            self._selected_gallery = None
            try:
                self._notify_target_changed()
            except Exception:  # noqa: BLE001
                pass
            self._gallery_status.set(
                gettext(
                    "Linked upload mode is unconfirmed. Reconnect and verify the "
                    "saved mode before attempting any upload."
                ),
            )
            return False
        self._active_upload_target = target
        self._selected_gallery = gallery
        self._gallery_status.set(
            gettext('Upload gallery: ') + _gallery_label(gallery),
        )
        try:
            self._notify_target_changed()
        except Exception:
            self._gallery_status.set(
                gettext(
                    "Upload mode was activated, but the display did not refresh. "
                    "Reopen the connection view to verify the target."
                ),
            )
            return False
        return True

    def _check_upload_workspace(self) -> bool:
        """Explain unsupported linked storage before contacting the server.

        Returns:
            bool: True if the host's local profile check admits selection or
                no optional host check was supplied.
        """
        check = getattr(self, '_upload_workspace_ready', None)
        if check is None or check():
            return True
        self._gallery_status.set(self._text('linking_upload_workspace'))
        return False

    def _select_gallery(self) -> None:
        """Remember or explicitly select one server-provided gallery.

        Returns:
            None: Selects an approved upload target or a listing-only preference;
                a refused or unsupported target is not activated.
        """
        if (self._session is None or self._session_issuer != self._current_issuer
                or not self._session.can_list_galleries() or self._gallery_page is None):
            return
        selected_index = self._gallery_choice.current()
        if selected_index < 0 or selected_index >= len(self._gallery_page.galleries):
            return
        gallery = self._gallery_page.galleries[selected_index]
        if self._session.can_upload():
            if not _dialog.LINKING_UPLOAD_TARGET_READY:
                self._gallery_status.set(
                    gettext(
                        "Linked upload selection is temporarily unavailable. "
                        "The current connection remains selected."
                    ),
                )
                return
            if not self._check_upload_workspace():
                return
            if getattr(self, '_commit_linked_target', None) is None:
                self._gallery_status.set(
                    gettext('The application cannot safely commit a linked upload mode.'),
                )
                return
            if self._worker is not None and self._worker.is_alive():
                self._gallery_status.set(
                    gettext('Wait for the previous gallery verification to finish.'),
                )
                return
            session = self._session
            issuer = self._session_issuer
            account = self._session_user_id
            scope = session.approved_scope()
            owner = object()
            cancel = threading.Event()
            self._owner = owner
            self._gallery_cancel = cancel
            self._gallery_status.set(
                gettext('Verifying the administrator and selected gallery…'),
            )

            def worker() -> None:
                """Verify a remote target without blocking the Tk thread.

                Returns:
                    None: Queues the exact result or a generic refusal.
                """
                try:
                    target = verify_linked_upload_target(
                        session, issuer, account, scope, gallery.gallery_id, cancel,
                    )
                    self._events.put((
                        owner, 'upload_target_verified',
                        (target, session, issuer, account, gallery, scope, cancel),
                    ))
                except Exception:
                    self._events.put((owner, 'upload_target_error', None))

            self._worker = threading.Thread(
                target=worker, name='PHPGalleryTargetVerification', daemon=True,
            )
            try:
                self._worker.start()
            except RuntimeError:
                self._worker = None
                self._gallery_status.set(gettext('Could not start gallery verification.'))
                return
            self._refresh(self._current_issuer)
            self._schedule_poll()
            return
        else:
            self._selected_gallery = gallery
            self._gallery_status.set(self._text('linking_upload_approval_needed'))
        self._refresh(self._current_issuer)

    def use_saved_api_key(self) -> str | None:
        """Explicitly return to the preserved API-key connection when safe.

        Returns:
            str | None: Reason the mode could not change, or None if the saved
                API-key mode is already selected or has just been activated.
        """
        if not self.linked_upload_mode_selected():
            return None
        refusal = self._target_refusal(None)
        if refusal is not None:
            return refusal
        commit = getattr(self, '_commit_saved_api_key', None)
        if commit is None or not commit():
            return 'Saved API-key mode could not be verified.'
        self._active_upload_target = None
        self._notify_target_changed()
        self._refresh(self._current_issuer)
        return None







    def _close_window(self) -> None:
        """Cancel this dialog's work and leave the shared session live.

        Returns:
            None: Closes the dialog while leaving a confirmed identity in memory.
        """
        self._abandon_link_attempt()
        self._cancel_gallery_search()
        self._detail_snapshot = None
        if self._create_dispatch is not None:
            self._create_dispatch.cancel()
        if self._gallery_cancel is not None:
            self._gallery_cancel.set()
        if self._window is not None:
            parent = self._window.master
            self._window.destroy()
            self._window = None
            if isinstance(parent, (tk.Toplevel, EmbeddedWindow)) and parent.winfo_exists():
                parent.grab_set()

    def close(self) -> None:
        """Stop linking work and discard in-memory sessions at shutdown.

        Returns:
            None: Releases the selected and parked approvals from memory.
        """
        self._shutting_down = True
        self._dismiss_address_prompt()
        self._close_window()
        if self._session is not None:
            self._session.close()
            self._clear_session_display()
        for site in self._parked_links:
            site.session.close()
        self._parked_links = ()
