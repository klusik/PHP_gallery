# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/ui/picker_behavior.py
# Module Type: Windows Client Interface Behavior
# Purpose: Own gallery selection and website address picker callbacks.
# Responsibilities:
#   - Query and present paged gallery choices for the current linking session.
#   - Manage recent addresses and explicit selection in the link dialog.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Address and gallery picker behavior for the linking manager."""

import threading
import unicodedata
from datetime import datetime, timezone
from typing import Callable
import tkinter as tk
from tkinter import ttk

from uploader.localization import gettext
from uploader.ui_shell import EmbeddedWindow, messagebox
from .. import (
    LINKING_GALLERY_QUERY_MAX_CHARS, LinkingResponseError, LinkingSession,
    LinkedGalleryPage, linking_issuer, linking_prompt_issuer,
)
from ...desktop_connection import (
    DesktopLinkedSite as _LinkedSite, find_live_link, live_link_sites,
)
from .dialog import (
    LINKING_ADDRESS_STATUS_WRAP, LINKING_DIALOG_WRAP_INSET,
    LINKING_PICKER_DELAY_MS, LINKING_PICKER_INDENT_LIMIT,
)
from .picker_ui import (
    _LinkAddressChoices, _gallery_label,
)
from ...state_store import load_recent_gallery_addresses, save_recent_gallery_addresses
from ...ui_theme import Button, GlassCard, icon_button, place_dialog

# A generation identity carries no session data; it only fences stale callbacks.
_LinkingOwner = object

class LinkingPickerBehaviorMixin:
    """Own address selection and gallery search callbacks."""

    def _gallery_changed(self, event: tk.Event) -> None:
        """Discard the previous detail when the selected gallery changes.

        Args:
            event (tk.Event): Selection event from the gallery suggestion list.

        Returns:
            None: Closes the prior media viewer and requires fresh detail for
                the new selection.
        """
        self._detail_snapshot = None
        self._visibility_choice.set('')
        page, index = self._gallery_page, self._gallery_choice.current()
        if page is not None and 0 <= index < len(page.galleries):
            gallery = page.galleries[index]
            if gallery.ancestors:
                crumbs = tuple(''.join(char if char.isprintable() else ' '
                                       for char in title).strip()
                               for title in gallery.ancestors)
                prefix = '… › ' if gallery.depth > len(crumbs) else ''
                self._gallery_status.set(prefix + ' › '.join((*crumbs, _gallery_label(gallery))))
            elif self._session is not None and self._session.can_upload():
                self._gallery_status.set('')
        self._refresh(self._current_issuer)

    def open_address_dialog(self, *, manage: bool = False, prefill: str = '') -> None:
        """Choose an installation and offer a fresh request for a stalled link.

        Args:
            manage (bool): Open access management after address selection.
            prefill (str): Exact issuer suggested by a recovery action, or
                empty to use the live link, saved API address or history.

        Returns:
            None: Shows the prompt, or leaves an active request or dialog open.
        """
        if self._address_prompt is not None:
            self._address_prompt[0].lift()
            return
        if (self._worker is not None and self._worker.is_alive()
                and (manage or not self._owns_website_request())):
            if self._handoff is None:
                self._status.set(gettext('A gallery request is already in progress.'))
            return

        owner = self._window if self._window is not None and self._window.winfo_exists() else self._root
        dialog = EmbeddedWindow(owner)
        title = gettext('Manage link') if manage else self._text('linking_connect_website')
        dialog.title(title)
        dialog.resizable(True, False)
        card = GlassCard(dialog, padding=(16, 12))
        card.pack(fill='both', expand=True)
        ttk.Label(
            card, text=self._text('linking_website_address'), style='Section.TLabel',
        ).pack(anchor='w')
        recent = self._recent_links
        saved_api_issuer = ''
        if (not prefill and not self._session_issuer and not manage
                and self._saved_api_address is not None
                and not self.linked_upload_mode_selected()):
            saved_api_issuer = linking_issuer(self._saved_api_address())
        address = tk.StringVar(
            dialog, value=(prefill or self._session_issuer or saved_api_issuer
                           or self._current_issuer
                           or (recent[0] if recent else self.address())),
        )
        entry = ttk.Entry(card, textvariable=address)
        entry.pack(fill='x', pady=(6, 0))
        choices = _LinkAddressChoices(
            card, self._text('linking_live_addresses'),
            self._text('linking_recent_addresses'), address.set,
        )
        # Reserve room for multiline feedback while keeping the actions still.
        hint = ttk.Label(
            card, text=self._text('linking_website_address_hint') + '\n\n',
            style='Muted.TLabel', wraplength=LINKING_ADDRESS_STATUS_WRAP,
            justify='left', anchor='w',
        )
        hint.pack(fill='x', pady=(4, 0))
        submitted = False
        min_width = LINKING_ADDRESS_STATUS_WRAP + LINKING_DIALOG_WRAP_INSET

        def submit() -> None:
            """Start or replace the website approval for the validated address.

            Returns:
                None: Shows a correction, opens management or requests a fresh
                    approval when this prompt or an earlier attempt is waiting.
            """
            nonlocal submitted
            if confirm.instate(['disabled']):
                return
            value = address.get().strip()
            issuer = linking_prompt_issuer(value)
            if not issuer:
                hint.configure(
                    textvariable='', text=self._text('linking_website_address_invalid'),
                    style='Status.TLabel',
                )
                entry.focus_set()
                return
            if not linking_issuer(value):
                address.set(issuer)
            if manage:
                self._dismiss_address_prompt()
                self.open(self._root, issuer, manage=True)
                return
            hint.configure(textvariable=self._status, style='Status.TLabel')
            self.link(issuer, restart=submitted or self._owns_website_request())
            submitted = True
            if self._address_prompt is not None:
                refresh()

        def cancel() -> None:
            """Dismiss the prompt and cancel its pending approval or renewal.

            Returns:
                None: Discards the prompt and stops its pending local work.
            """
            if submitted and self._owns_website_request():
                self._abandon_link_attempt()
                self._status.set(gettext('Gallery linking cancelled.'))
            self._dismiss_address_prompt()

        def unlink_choice() -> None:
            """Request revocation for the exact live address in the field.

            Returns:
                None: Updates the list or displays the upload guard's refusal.
            """
            hint.configure(textvariable=self._status, style='Status.TLabel')
            self.unlink(linking_issuer(address.get()))
            refresh()

        def forget_choice() -> None:
            """Remove the selected recent address from this profile.

            Returns:
                None: Clears the field after a successful local removal.
            """
            issuer = linking_issuer(address.get())
            if issuer not in self._recent_links or self._live_link(issuer) is not None:
                return
            hint.configure(textvariable=self._status, style='Status.TLabel')
            if self._save_recent_links(tuple(link for link in self._recent_links if link != issuer)):
                if self._current_issuer == issuer and self._session is None:
                    self._current_issuer = ''
                self._status.set(self._text('linking_address_removed'))
                address.set('')
            refresh()

        def refresh() -> None:
            """Project live links, local history and the currently valid actions.

            Returns:
                None: Fits changed groups and projects the current browser action
                    or the availability of a new request.
            """
            live = tuple(site.issuer for site in self._live_links())
            recent = tuple(issuer for issuer in self._recent_links if issuer not in live)
            if choices.render(live, recent, self._session_issuer):
                if live or recent:
                    choices.pack(fill='x', pady=(8, 0), after=entry)
                else:
                    choices.pack_forget()
                dialog.update_idletasks()
                dialog.minsize(min_width, dialog.winfo_reqheight())
            issuer = linking_issuer(address.get())
            own_request = self._owns_website_request()
            if own_request and not manage and not submitted:
                hint.configure(textvariable=self._status, style='Status.TLabel')
            busy = ((self._worker is not None and self._worker.is_alive())
                    or ((own_request or submitted) and not self._events.empty()))
            entry.state(['disabled'] if busy else ['!disabled'])
            choices.results.state(['disabled'] if busy else ['!disabled'])
            confirm.state(['disabled'] if busy and (manage or not own_request)
                          else ['!disabled'])
            label = title
            if not manage and issuer in live and not busy:
                label = self._text('linking_open_saved_link')
            elif not manage and (own_request or submitted):
                label = self._text('linking_connect_again')
            confirm.configure(text=label)
            disconnectable = (issuer in live or issuer in self._reconnect_issuers
                              or (self._offline_binding is not None
                                  and self._offline_binding.issuer == issuer))
            unlink_button.state(['!disabled'] if disconnectable and not busy else ['disabled'])
            forget_button.state(['!disabled'] if issuer in recent and not busy else ['disabled'])
            for button, visible in ((unlink_button, disconnectable),
                                    (forget_button, issuer in recent)):
                if visible:
                    button.pack(side='left')
                else:
                    button.pack_forget()

        actions = ttk.Frame(card, style='Card.TFrame')
        actions.pack(side='bottom', fill='x', pady=(12, 0))
        unlink_button = icon_button(
            actions, '⏻', unlink_choice, self._text('linking_unlink_address'),
        )
        forget_button = icon_button(
            actions, 'close', forget_choice, self._text('linking_forget_address'),
        )
        confirm = Button(
            actions, text=title, command=submit, style='Accent.Compact.TButton',
        )
        confirm.pack(side='right')
        Button(actions, text=gettext('Cancel'), command=cancel, style='Compact.TButton').pack(
            side='right', padx=(0, 8),
        )
        dialog.bind('<Return>', lambda _event: submit())
        dialog.bind('<Escape>', lambda _event: cancel())
        dialog.protocol('WM_DELETE_WINDOW', cancel)
        address_trace = address.trace_add('write', lambda *_info: refresh())
        dialog.bind('<Destroy>', lambda event: address.trace_remove('write', address_trace)
                    if event.widget is dialog else None, add='+')
        self._address_prompt = (dialog, entry, confirm)
        self._refresh_address_prompt = refresh
        refresh()
        place_dialog(dialog, self._root)
        dialog.grab_set()
        entry.focus_set()

    def _dismiss_address_prompt(self) -> None:
        """Close the address prompt after cancellation or verified approval.

        Returns:
            None: Releases the dialog and its grab if still displayed.
        """
        prompt = self._address_prompt
        if prompt is not None:
            self._address_prompt = None
            self._refresh_address_prompt = None
            prompt[0].destroy()

    def _owns_website_request(self) -> bool:
        """Identify requests that the address chooser is allowed to replace.

        Returns:
            bool: True for the current website approval or saved sign-in check.
                Gallery operations and permission upgrades have different owners.
        """
        return (self._website_request_owner is not None
                and self._website_request_owner is self._owner)

    def link(self, address: str, *, restart: bool = False) -> None:
        """Select a live site, connect to it or replace its unfinished approval.

        Args:
            address (str): Entered or previously selected website address.
            restart (bool): Discard the previous request and make a new browser
                approval without retrying the saved sign-in first.

        Returns:
            None: Shows a live site, starts or queues approval, or reports refusal.
        """
        running = self._worker is not None and self._worker.is_alive()
        may_replace = restart and self._owns_website_request()
        if not may_replace and (running or (restart and not self._events.empty())):
            self._status.set(gettext('A gallery request is already in progress.'))
            return
        issuer = linking_issuer(address)
        if not issuer:
            self._status.set(self._text('linking_website_address_invalid'))
            return
        self._refresh(self._current_issuer)
        if not restart and issuer in self._reconnect_issuers:
            self._remove_saved_link(issuer, reconnect=True)
            return
        site = self._live_link(issuer)
        if site is not None:
            if running:
                self._status.set(gettext('A gallery request is already in progress.'))
                return
            if self._session is not site.session:
                self._show_link(site)
            else:
                self._status.set('')
                self._remember_link(issuer)
            self._dismiss_address_prompt()
            if site.session.can_list_galleries():
                if self._gallery_page is None:
                    self._load_galleries(None)
            else:
                self._status.set(gettext('Open Manage link to change the approved access.'))
            return
        if restart:
            # Fence old events before replacing the listener. The worker may
            # still be returning from HTTP or finishing an approved vault write.
            self._abandon_link_attempt()
            owner = object()
            self._owner = self._direct_owner = self._website_request_owner = owner
            self._events.put((owner, 'restart_website_link', issuer))
            self._status.set(self._text('linking_restarting'))
            self._schedule_poll()
            return
        if self.resume_saved_link(issuer, approve_if_absent=True):
            return
        if self._session is None:
            self._current_issuer = issuer
            self._capabilities = frozenset()
        self._start(issuer, direct=True)

    def _display_gallery_page(self) -> None:
        """Render the cached server page if a gallery picker is open.

        Returns:
            None: Updates the choices without selecting an upload destination.
        """
        if self._window is not None:
            page = self._gallery_page
            labels = []
            for gallery in page.galleries if page is not None else ():
                depth = max(gallery.depth, int(gallery.parent_id is not None))
                indent = ' ' * min(depth, LINKING_PICKER_INDENT_LIMIT)
                marker = '… ' if depth > LINKING_PICKER_INDENT_LIMIT else ''
                labels.append(indent + marker + _gallery_label(gallery))
            self._gallery_choice.set_rows(tuple(labels))

    def _catalog_search_ready(self) -> bool:
        """Check whether the picker can search the current approved catalog.

        Returns:
            bool: True for a live search grant within the server's catalog budget.
        """
        return (self._session is not None and self._session.can_search_galleries() is True
                and not self._gallery_catalog_limited)

    def _cancel_gallery_search(self) -> None:
        """Retire a deferred search and reject any late result from its worker.

        Returns:
            None: Cancels only this picker read and its current query timer.
        """
        if self._gallery_search_job is not None:
            self._root.after_cancel(self._gallery_search_job)
            self._gallery_search_job = None
        if self._gallery_search_cancel is not None:
            self._gallery_search_cancel.set()
            self._gallery_search_cancel = None
        self._gallery_search_waiting = False
        self._gallery_search_revision = object()

    def _gallery_search_changed(self, *_trace_info: str) -> None:
        """Clear the prior choice as soon as the search text changes.

        Args:
            *_trace_info (str): Tk variable name, index and write operation.

        Returns:
            None: Schedules only the latest query or filters an older page.
        """
        if self._window is None or self._session is None:
            return
        query = ' '.join(self._gallery_choice.query.get().split())
        if query == self._gallery_query:
            return
        self._gallery_query = query
        self._detail_snapshot = None
        self._cancel_gallery_search()
        if not self._catalog_search_ready():
            if self._gallery_browse_page is not None:
                self._present_gallery_page(self._gallery_browse_page)
            self._refresh(self._current_issuer)
            return
        self._gallery_page = None
        self._display_gallery_page()
        if len(query) > LINKING_GALLERY_QUERY_MAX_CHARS:
            self._gallery_status.set(
                self._text('linking_gallery_query_too_long').format(
                    limit=LINKING_GALLERY_QUERY_MAX_CHARS,
                ),
            )
        else:
            self._gallery_status.set(self._text('linking_gallery_searching'))
            self._gallery_search_waiting = True
            self._gallery_search_job = self._root.after(
                LINKING_PICKER_DELAY_MS, self._run_gallery_search,
            )
        self._refresh(self._current_issuer)

    def _run_gallery_search(self) -> None:
        """Dispatch a settled query once the shared linking worker is idle.

        Returns:
            None: Defers to the result poll if another operation still owns the worker.
        """
        self._gallery_search_job = None
        if (self._gallery_search_waiting and self._window is not None
                and (self._worker is None or not self._worker.is_alive())
                and self._events.empty()):
            self._gallery_search_waiting = False
            self._load_galleries(None)

    def _present_gallery_page(self, page: LinkedGalleryPage) -> None:
        """Display current server results or filter the available legacy page.

        Args:
            page (LinkedGalleryPage): Verified gallery choices and their cursor.

        Returns:
            None: Replaces the old selection and describes an empty or limited result.
        """
        self._detail_snapshot = None
        if page.limited:
            self._gallery_catalog_limited = True
        legacy = not self._catalog_search_ready()
        if legacy:
            self._gallery_browse_page = page
        query = self._gallery_query
        visible = page
        if legacy and query:
            needle = self._legacy_gallery_text(query)
            rows = tuple(gallery for gallery in page.galleries
                         if needle in self._legacy_gallery_text(gallery.title))
            visible = LinkedGalleryPage(rows, page.next_after)
        self._gallery_page = visible
        self._display_gallery_page()
        if legacy and query:
            message = self._text('linking_gallery_search_page_only')
            if not visible.galleries:
                message = self._text('linking_gallery_search_empty') + ' ' + message
        elif not visible.galleries:
            message = self._text('linking_gallery_search_empty' if query
                                 else 'linking_gallery_page_empty')
        elif self._session is not None and not self._session.can_upload():
            message = self._text('linking_upload_approval_needed')
        else:
            message = ''
        self._gallery_status.set(message)

    @staticmethod
    def _legacy_gallery_text(value: str) -> str:
        """Fold visible title text for older servers with basic listing only.

        Args:
            value (str): Local filter text or one already authorized title.

        Returns:
            str: Case- and accent-insensitive text for a literal page filter.
        """
        return ''.join(char for char in unicodedata.normalize('NFD', value.casefold())
                       if not unicodedata.category(char).startswith('M'))

    @staticmethod
    def _fetch_picker_page(
        session: LinkingSession, query: str, after_id: int | None,
        cancelled: threading.Event,
    ) -> LinkedGalleryPage:
        """Fetch one full-catalog result page or preserve basic browsing at its limit.

        Args:
            session (LinkingSession): Exact live gallery search grant.
            query (str): Current search or empty to browse.
            after_id (int | None): Last ID from the same query's preceding page.
            cancelled (threading.Event): Operation-owned cancellation signal.

        Returns:
            LinkedGalleryPage: Ranked results, or an explicitly marked basic page.
        """
        page = session.gallery_picker_page(query, after_id, cancelled=cancelled)
        if not page.limited:
            return page
        basic = session.gallery_page(cancelled=cancelled)
        return LinkedGalleryPage(basic.galleries, basic.next_after, limited=True)

    def _queue_initial_gallery_page(
        self, owner: _LinkingOwner, session: LinkingSession,
        cancelled: threading.Event,
    ) -> None:
        """Read one initial page for a listing-capable approved session.

        Args:
            owner (_LinkingOwner): Dialog generation identity owning the result.
            session (LinkingSession): Verified in-memory gallery session.
            cancelled (threading.Event): Existing link or reconnect cancellation.

        Returns:
            None: Queues one validated page or a generic list failure.
        """
        if cancelled.is_set():
            return
        try:
            if not session.can_list_galleries():
                return
            page = (self._fetch_picker_page(session, '', None, cancelled)
                    if session.can_search_galleries() is True
                    else session.gallery_page(None, cancelled=cancelled))
        except LinkingResponseError as error:
            if not cancelled.is_set():
                self._events.put((owner, 'response_error', error))
            return
        except Exception:  # noqa: BLE001
            if not cancelled.is_set():
                self._events.put((owner, 'galleries_error', None))
            return
        if not cancelled.is_set():
            self._events.put((owner, 'galleries', page))

    def _load_galleries(self, after_id: int | None) -> None:
        """Request the next page or run the current approved search.

        Args:
            after_id (int | None): Server continuation ID for this query or
                listing, or None for the first page.

        Returns:
            None: Starts a bounded background read or ignores an unavailable link.
        """
        session = self._session
        if (session is None or self._session_issuer != self._current_issuer
                or not session.can_list_galleries()
                or self._worker is not None and self._worker.is_alive()):
            return
        self._detail_snapshot = None
        search = self._catalog_search_ready()
        query = self._gallery_query
        if search and len(query) > LINKING_GALLERY_QUERY_MAX_CHARS:
            return
        revision = self._gallery_search_revision
        owner = object()
        self._owner = owner
        gallery_cancel = threading.Event()
        self._gallery_cancel = gallery_cancel
        if search:
            self._gallery_search_cancel = gallery_cancel
        self._direct_owner = owner if self._window is None else None
        self._gallery_status.set(self._text('linking_gallery_searching') if query
                                 else gettext('Loading the current gallery page…'))

        def worker() -> None:
            """Fetch one server-authorized page without accessing Tk widgets.

            Returns:
                None: Queues a bounded page or a generic failure marker.
            """
            try:
                if search:
                    page = self._fetch_picker_page(session, query, after_id, gallery_cancel)
                    self._events.put((owner, 'picker_page', (revision, page)))
                else:
                    page = session.gallery_page(after_id, cancelled=gallery_cancel)
                    self._events.put((owner, 'galleries', page))
            except LinkingResponseError as error:
                if not gallery_cancel.is_set():
                    self._events.put((owner, 'response_error', error))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'picker_page', (revision, None))
                                 if search else (owner, 'galleries_error', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryLinkedGalleries', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._gallery_status.set(gettext('Could not start the gallery request.'))
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _next_galleries(self) -> None:
        """Load the next server page using only the server-returned cursor.

        Returns:
            None: Starts a page request when a validated continuation exists.
        """
        if self._gallery_page is not None and self._gallery_page.next_after is not None:
            self._load_galleries(self._gallery_page.next_after)
