# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/app_linking_ui.py
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
import time
import tkinter as tk
import traceback
import unicodedata
import webbrowser
from dataclasses import replace
from datetime import datetime, timezone
from pathlib import Path
from tkinter import messagebox, simpledialog, ttk
from typing import Callable, NamedTuple
from .app_linking import (
    GALLERIES_SCOPE, IDENTITY_SCOPE, READ_SCOPE, READ_UPLOAD_SCOPE, UPLOAD_SCOPE,
    LINKING_GALLERY_ID_MAX, LINKING_GALLERY_QUERY_MAX_CHARS,
    LINKING_RECOVERY_MAX_FILE_BYTES,
    ADMIN_PRELOAD_CAPABILITY,
    GALLERY_TREE_CAPABILITY,
    ADMIN_MEDIA_SCOPE_SUFFIX, ADMIN_PRESENTATION_SCOPE_SUFFIX,
    MEDIA_SCOPE_SUFFIX, PRESENTATION_SCOPE_SUFFIX, ROLLING_SCOPE_SUFFIX,
    LinkedGallery, LinkedGalleryDetail, LinkedGalleryPage,
    LinkedIdentity, LinkingAttempt, LinkingError, LinkingGrantRejected,
    LinkingHttpsError, LinkingRefreshRejected,
    LinkingResponseError, LinkingRevisionConflict,
    LinkingScheduledPublication, LinkingSession,
    LinkingUnavailable,
    PushedAuthorization,
    discover_linking_capabilities, linking_issuer,
)
from .linked_upload import (
    LinkedUploadTarget, linked_target_binding, verify_linked_upload_target,
)
from .linked_create import (
    BoundCreateReceipt, CreateDispatch, CreateIntent, CreateJournalSnapshot,
    GalleryCreateJournal,
)
from .credential_store import (
    KeychainCredentialStore, NativeCredentialStore, WindowsCredentialStore,
)
from .flight_report_translations import tr
from .offline_linking import (
    OfflineBinding, enable_rolling_renewal,
    load_offline_binding, offline_binding_path,
    remove_offline_binding, remove_saved_offline_link, revoke_saved_refresh,
    rotate_saved_offline_access,
    save_offline_binding,
    store_approved_refresh,
)
from .session_resume import (
    TransientGrant, delete_transient, load_transient, save_transient,
)
from .state_store import (
    RECENT_GALLERY_ADDRESS_LIMIT,
    load_recent_gallery_addresses, save_recent_gallery_addresses,
)
from .ui_theme import GlassCard, icon_button, place_dialog

# Purpose: Refresh the linking dialog without touching Tk from worker threads.
# Type: int. Units: milliseconds. Scope: one pending identity operation.
# Consumers: LinkingManager._poll. Rationale: 100 ms keeps feedback responsive.
LINKING_POLL_INTERVAL_MS = 100

# Purpose: Refresh a displayed local identity expiry without a busy loop.
# Type: int. Units: milliseconds. Scope: an open, idle linking dialog.
# Consumers: LinkingManager._poll. Rationale: one second bounds stale display.
LINKING_IDLE_POLL_INTERVAL_MS = 1000

# Purpose: Keep one server-provided gallery title compact in the selector.
# Type: int. Units: Unicode characters. Scope: one displayed title.
# Consumers: _gallery_label. Rationale: 80 characters fits compact dialogs;
# the stored server title and stable ID are not modified.
LINKING_GALLERY_LABEL_MAX_CHARS = 80

# Purpose: Reflow the linking action groups when three controls no longer fit.
# Type: int. Units: Tk pixels. Scope: linking dialog width.
# Consumers: LinkingManager._apply_dialog_layout.
# Rationale: 560 leaves room for three actions, padding and the scrollbar.
LINKING_DIALOG_COMPACT_WIDTH = 560

# Purpose: Stack action controls when even two columns would be too narrow.
# Type: int. Units: Tk pixels. Scope: linking dialog width.
# Consumers: LinkingManager._apply_dialog_layout.
# Rationale: 390 leaves room for two labeled actions and the scrollbar.
LINKING_DIALOG_SINGLE_COLUMN_WIDTH = 390

# Purpose: Account for both card edges and small label breathing room.
# Type: int. Units: Tk pixels. Scope: dynamic linking dialog label wrap.
# Consumers: LinkingManager._apply_dialog_layout.
# Rationale: 60 covers both card edges, the scrollbar and breathing room.
LINKING_DIALOG_WRAP_INSET = 60

# Purpose: Avoid invalid or unreadably small label wrap widths during startup.
# Type: int. Units: Tk pixels. Scope: linking dialog label wrap.
# Consumers: LinkingManager._apply_dialog_layout.
# Rationale: 80 keeps text rendering valid during transient size events.
LINKING_DIALOG_MIN_WRAP = 80

# Purpose: Set the initial wrap until Tk reports the real dialog dimensions.
# Type: int. Units: Tk pixels. Scope: initial linking dialog layout.
# Consumers: LinkingManager.open. Rationale: 560 is a compact desktop width.
LINKING_DIALOG_INITIAL_WIDTH = 560

# Purpose: Fit linking progress and errors inside the address dialog.
# Type: int. Units: Tk pixels. Scope: address-dialog status label.
# Consumers: LinkingManager.open_address_dialog.
# Rationale: 320 keeps messages readable in a compact window.
LINKING_ADDRESS_STATUS_WRAP = 320

# Purpose: Bound the fixed-field summary retained for one linking failure.
# Type: int. Units: Unicode characters. Scope: copied linking diagnostics.
# Consumers: LinkingManager._record_diagnostic. Rationale: 512 characters fit
# all fixed metadata fields without retaining arbitrary response contents.
LINKING_DIAGNOSTIC_MAX_CHARS = 512

# Purpose: Keep address groups visible with space for the dialog actions.
# Type: int. Units: rows. Scope: one gallery links dialog.
# Consumers: _LinkAddressChoices. Rationale: Six fits the compact dialog
# and the scrollbar provides access to the rest of the history.
LINKING_ADDRESS_VISIBLE_ROWS = 6

# Purpose: Keep the gallery picker and its compact actions in view.
# Type: int. Units: Tk pixels. Scope: minimum gallery picker width.
# Consumers: LinkingManager.open. Rationale: 320 fits the selector and action row.
LINKING_PICKER_MIN_WIDTH = 320

# Purpose: Keep suggestions visible without making the picker a large window.
# Type: int. Units: rows. Scope: one native gallery suggestion list.
# Consumers: _GallerySuggestions. Rationale: six leaves the actions visible
# at narrow sizes and the list can scroll through the entire returned page.
LINKING_PICKER_VISIBLE_ROWS = 6

# Purpose: Leave space for the title of a deeply nested match.
# Type: int. Units: hierarchy levels. Scope: visual suggestion indentation.
# Consumers: LinkingManager._display_gallery_page. Rationale: four fits the
# narrow picker while the selected row displays its parent titles.
LINKING_PICKER_INDENT_LIMIT = 4

# Purpose: Coalesce quick edits before querying the authenticated catalog.
# Type: int. Units: milliseconds. Scope: one native picker text field.
# Consumers: LinkingManager._gallery_search_changed.
# Rationale: 250 lets a word take shape without making the list feel delayed.
LINKING_PICKER_DELAY_MS = 250

# Purpose: Allow the visibility action after server readiness and consent.
# Type: bool. Units: enabled state. Scope: linking-dialog visibility controls.
# Consumers: LinkingManager. Rationale: Verified recovery and fresh readback
# support a revision-bound change to stored visibility.
LINKING_VISIBILITY_READY = True

# Purpose: Enable selection of an existing, freshly verified gallery.
# Type: bool. Units: enabled state. Scope: linked upload target activation.
# Consumers: LinkingManager. Rationale: Native mode and vault qualification,
# restart exclusion and disposable uploads support the local Windows profile.
LINKING_UPLOAD_TARGET_READY = True

# Purpose: Keep the created-gallery receipt shortcut separate from ordinary
# server-listed target selection. The receipt path needs independent fresh
# target verification and crash-safe post-publication reconciliation.
# Type: bool. Units: enabled state. Scope: created-gallery target activation.
# Consumers: LinkingManager. Rationale: The original receipt and independent
# live target checks now have disposable HTTPS and native publication evidence.
LINKING_CREATED_TARGET_READY = True

# Purpose: Preserve optional media and presentation scope selection.
# Type: bool. Units: enabled state. Scope: advanced linking permissions.
# Consumers: LinkingManager._requested_scope. Rationale: Older servers may
# still require these explicit scopes for legacy reader behavior.
LINKING_NATIVE_MEDIA_READY = True

# A generation is compared by identity; it carries no session data or fields.
_LinkingOwner = object


def _gallery_label(gallery: LinkedGallery) -> str:
    """Prepare a compact safe single-line label for a server-provided gallery.

    Args:
        gallery (LinkedGallery): Server-provided gallery identity and title.

    Returns:
        str: Bounded display title and stable numeric gallery ID.
    """
    title = ''.join(char if char.isprintable() else ' '
                    for char in gallery.title).strip() or '(Untitled)'
    if len(title) > LINKING_GALLERY_LABEL_MAX_CHARS:
        title = title[:LINKING_GALLERY_LABEL_MAX_CHARS] + '…'
    return f'{title} (#{gallery.gallery_id})'


class _GallerySuggestions(ttk.Frame):
    """Show a focused search field above its selectable gallery results."""

    def __init__(
        self, parent: tk.Misc, query: str, clear_label: str,
        on_query: Callable[[str, str, str], None],
        on_selection: Callable[[tk.Event], None],
        activate: Callable[[], None],
    ) -> None:
        """Create the compact entry and the independent result selection.

        Args:
            parent (tk.Misc): Gallery picker card owning both controls.
            query (str): Current filter text restored from this linked session.
            clear_label (str): Accessible label for clearing the search.
            on_query (Callable[[str, str, str], None]): Tk variable trace receiving its
                name, index and operation after a query edit.
            on_selection (Callable[[tk.Event], None]): Selection event handler.
            activate (Callable[[], None]): Explicit action for the selected result.

        Returns:
            None: Installs keyboard navigation with no default destination.
        """
        super().__init__(parent, style='Card.TFrame')
        self.columnconfigure(0, weight=1)
        self.query = tk.StringVar(self, value=query)
        self.entry = ttk.Entry(self, textvariable=self.query)
        self.entry.grid(row=0, column=0, sticky='ew', padx=(0, 4))
        self.clear = icon_button(self, 'close', lambda: self.query.set(''), clear_label)
        self.clear.grid(row=0, column=1, sticky='e')
        self.results = ttk.Treeview(
            self, show='tree', selectmode='browse', height=LINKING_PICKER_VISIBLE_ROWS,
        )
        self.results.grid(row=1, column=0, sticky='ew', pady=(5, 0))
        scrollbar = ttk.Scrollbar(self, orient='vertical', command=self.results.yview)
        scrollbar.grid(row=1, column=1, sticky='ns', pady=(5, 0))
        self.results.configure(yscrollcommand=scrollbar.set)
        self.results.bind('<<TreeviewSelect>>', on_selection)
        self.entry.bind('<Down>', self._focus_results)
        self.entry.bind('<Return>', self._focus_results)
        self.results.bind('<Return>', lambda _event: activate())
        trace = self.query.trace_add('write', on_query)
        self.bind('<Destroy>', lambda event: self.query.trace_remove('write', trace)
                  if event.widget is self else None, add='+')

    def current(self) -> int:
        """Read the selected row's index in the displayed page.

        Returns:
            int: Zero-based result index, or -1 until a result is selected.
        """
        rows = self.results.selection()
        return int(rows[0]) if rows else -1

    def set_rows(self, labels: tuple[str, ...]) -> None:
        """Replace the results and clear any previous destination selection.

        Args:
            labels (tuple[str, ...]): Display labels in the server or page order.

        Returns:
            None: The user chooses a current row before acting on it.
        """
        self.results.delete(*self.results.get_children())
        for index, label in enumerate(labels):
            self.results.insert('', 'end', iid=str(index), text=label)

    def enable(self, *, input_ready: bool, results_ready: bool) -> None:
        """Leave typing available while the previous results are loading.

        Args:
            input_ready (bool): The current link allows listing galleries.
            results_ready (bool): Current results may be selected for an action.

        Returns:
            None: Synchronizes entry and selection independently.
        """
        self.entry.state(['!disabled'] if input_ready else ['disabled'])
        self.clear.state(['!disabled'] if input_ready else ['disabled'])
        self.results.state(['!disabled'] if results_ready else ['disabled'])

    def _focus_results(self, _event: tk.Event) -> str:
        """Move from the query to the top suggestion without activating it.

        Args:
            _event (tk.Event): Down or Return key press in the entry.

        Returns:
            str: Stops default key handling after moving the focus if possible.
        """
        rows = self.results.get_children()
        if rows and not self.results.instate(['disabled']):
            self.results.selection_set(rows[0])
            self.results.focus(rows[0])
            self.results.focus_set()
        return 'break'


class _LinkAddressChoices(ttk.Frame):
    """Show exact installation addresses grouped by current availability."""

    def __init__(
        self, parent: tk.Misc, linked_label: str, recent_label: str,
        on_select: Callable[[str], None],
    ) -> None:
        """Create a compact, keyboard-selectable address list.

        Args:
            parent (tk.Misc): Address card owning the list.
            linked_label (str): Heading for live, approved links.
            recent_label (str): Heading for saved addresses needing approval.
            on_select (Callable[[str], None]): Copy the selected issuer to the field.

        Returns:
            None: The owner can render or hide the list as addresses change.
        """
        super().__init__(parent, style='Card.TFrame')
        self.columnconfigure(0, weight=1)
        self.results = ttk.Treeview(self, show='tree', selectmode='browse')
        self.results.grid(row=0, column=0, sticky='ew')
        scroll = ttk.Scrollbar(self, orient='vertical', command=self.results.yview)
        scroll.grid(row=0, column=1, sticky='ns')
        self.results.configure(yscrollcommand=scroll.set)
        self.results.bind(
            '<<TreeviewSelect>>',
            lambda _event: on_select(issuer)
            if (not self.results.instate(['disabled'])
                and (issuer := self.selected_address())) else None,
        )
        self._headings = (('linked', linked_label), ('recent', recent_label))
        self._projection: tuple[tuple[str, ...], tuple[str, ...], str] | None = None

    def selected_address(self) -> str:
        """Return only a selected installation row.

        Returns:
            str: Exact issuer, or an empty string for a group or no selection.
        """
        selected = self.results.selection()
        return selected[0] if selected and self.results.parent(selected[0]) else ''

    def render(
        self, linked: tuple[str, ...], recent: tuple[str, ...], current: str,
    ) -> bool:
        """Refresh the visible groups when live links or recent addresses change.

        Args:
            linked (tuple[str, ...]): Currently usable installation addresses.
            recent (tuple[str, ...]): Remaining recently used installation addresses.
            current (str): Installation currently selected for browsing.

        Returns:
            bool: True when the dialog needs to fit the updated list.
        """
        projection = (linked, recent, current)
        if projection == self._projection:
            return False
        self._projection = projection
        self.results.delete(*self.results.get_children())
        rows = 0
        for (group, label), addresses in zip(self._headings, (linked, recent)):
            if not addresses:
                continue
            self.results.insert('', 'end', iid=group, text=label, open=True)
            rows += 1
            for address in addresses:
                label = '✓ ' + address if address == current else address
                self.results.insert(group, 'end', iid=address, text=label)
                rows += 1
        self.results.configure(height=max(1, min(rows, LINKING_ADDRESS_VISIBLE_ROWS)))
        return True


class _LinkingScrollFrame(ttk.Frame):
    """Keep the link dialog usable when its content exceeds the viewport."""

    def __init__(self, parent: tk.Toplevel) -> None:
        """Create a vertical viewport with a visible themed scrollbar.

        Args:
            parent (tk.Toplevel): Linking window owning this scroll container.

        Returns:
            None: Installs the canvas and its width-matched content frame.
        """
        super().__init__(parent, style='Card.TFrame')
        self.canvas = tk.Canvas(
            self, highlightthickness=0, borderwidth=0,
            background=parent.cget('background'),
        )
        self.scrollbar = ttk.Scrollbar(self, orient='vertical', command=self.canvas.yview)
        self.content = ttk.Frame(self.canvas, padding=16, style='Card.TFrame')
        self._content_id = self.canvas.create_window((0, 0), window=self.content, anchor='nw')
        self.canvas.configure(yscrollcommand=self.scrollbar.set)
        self.scrollbar.pack(side='right', fill='y')
        self.canvas.pack(side='left', fill='both', expand=True)
        self.content.bind('<Configure>', self._sync_region, add='+')
        self.canvas.bind('<Configure>', self._sync_width, add='+')
        parent.bind('<MouseWheel>', self._on_wheel, add='+')

    def _sync_region(self, event: tk.Event) -> None:
        """Update scrollable bounds when content is resized.

        Args:
            event (tk.Event): Content geometry event from Tk.

        Returns:
            None: Publishes the current canvas scroll region.
        """
        self.canvas.configure(scrollregion=self.canvas.bbox('all'))

    def _sync_width(self, event: tk.Event) -> None:
        """Match the content width to the canvas viewport.

        Args:
            event (tk.Event): Canvas resize event with its current width.

        Returns:
            None: Prevents horizontal overflow while permitting vertical scroll.
        """
        self.canvas.itemconfigure(self._content_id, width=max(1, int(event.width)))

    def _on_wheel(self, event: tk.Event) -> str | None:
        """Scroll the dialog without stealing native list or entry gestures.

        Args:
            event (tk.Event): Tk mouse-wheel event originating in the dialog.

        Returns:
            str | None: Break after scrolling, or None for native widgets.
        """
        if isinstance(event.widget, (tk.Text, tk.Listbox, ttk.Treeview, ttk.Combobox)):
            return None
        widget = event.widget
        while widget is not None and widget is not self:
            widget = getattr(widget, 'master', None)
        if widget is not self or not event.delta:
            return None
        self.canvas.yview_scroll(-1 if event.delta > 0 else 1, 'units')
        return 'break'

    def ensure_visible(self, event: tk.Event) -> None:
        """Reveal a focused control when keyboard navigation reaches it.

        Args:
            event (tk.Event): Focus event whose widget belongs to this content.

        Returns:
            None: Scrolls only when the focused control lies outside the viewport.
        """
        self.canvas.update_idletasks()
        region = self.canvas.bbox('all')
        if region is None:
            return
        height = max(1, region[3] - region[1])
        top = event.widget.winfo_rooty() - self.canvas.winfo_rooty()
        bottom = top + event.widget.winfo_height()
        viewport = self.canvas.winfo_height()
        offset = self.canvas.canvasy(0)
        if top < 0:
            self.canvas.yview_moveto(max(0.0, (offset + top) / height))
        elif bottom > viewport:
            self.canvas.yview_moveto(min(1.0, (offset + bottom - viewport) / height))


class _LinkedSite(NamedTuple):
    """Keep one verified live link and its exact approving account in memory."""

    issuer: str
    session: LinkingSession
    user_id: int


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
    """

    target: LinkedUploadTarget
    start: Callable[[LinkedUploadTarget], bool]
    failure: Callable[[str], None]


class LinkingManager:
    """Own live gallery links, the selected site's view and one linking worker."""

    # The prompt is created on demand and its widgets belong to one instance.
    _address_prompt: tuple[tk.Toplevel, ttk.Entry, ttk.Button] | None = None
    _refresh_address_prompt: Callable[[], None] | None = None
    _parked_links: tuple[_LinkedSite, ...] = ()
    _recent_links: tuple[str, ...] = ()
    _recent_link_path: Path | None = None
    _saved_upload_identity: Callable[[], tuple[str, str] | None] | None = None
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
        self._window: tk.Toplevel | None = None
        self._worker: threading.Thread | None = None
        self._attempt: LinkingAttempt | None = None
        self._session: LinkingSession | None = None
        self._session_issuer = ''
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
                  | _PhotosWatchStart | LinkingResponseError | LinkingUnavailable
                  | Callable[[str], None] | None]
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
                or len(summary) > LINKING_DIAGNOSTIC_MAX_CHARS):
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
        try:
            if sys.platform == 'darwin':
                store = KeychainCredentialStore(self._offline_path.parent)
            elif sys.platform == 'win32':
                store = WindowsCredentialStore()
            else:
                return None
            return store if store.is_available() else None
        except (RuntimeError, OSError):
            return None

    def _saved_offline_binding(self, issuer: str) -> OfflineBinding | None:
        """Find the exact rolling or compatible legacy Windows restart hint.

        Args:
            issuer (str): Independently chosen canonical installation issuer.

        Returns:
            OfflineBinding | None: Matching local metadata, or None if absent.

        Raises:
            RuntimeError: The selected hint is malformed or unsafe to read.
        """
        path = offline_binding_path(self._offline_path, issuer)
        binding = load_offline_binding(path, issuer)
        if binding is not None:
            if not binding.scope.endswith(ROLLING_SCOPE_SUFFIX):
                raise LinkingError('Saved gallery binding is invalid.')
            return binding
        if sys.platform == 'win32' and issuer.startswith('https://'):
            # The old global hint can legitimately belong to another issuer.
            try:
                return load_offline_binding(self._offline_path, issuer)
            except RuntimeError:
                return None
        return None

    def _offline_binding_path(self, binding: OfflineBinding) -> Path:
        """Resolve the storage generation for an exact saved link.

        Args:
            binding (OfflineBinding): Validated issuer, scope and Admin hint.

        Returns:
            Path: Dedicated rolling hint or the original Windows hint.
        """
        if binding.scope.endswith(ROLLING_SCOPE_SUFFIX):
            return offline_binding_path(self._offline_path, binding.issuer)
        return self._offline_path

    def resume_saved_link(
        self, issuer: str, *, approve_if_absent: bool = False,
    ) -> bool:
        """Try an existing native vault grant before considering browser approval.

        Args:
            issuer (str): Exact issuer in the saved recent-address list.
            approve_if_absent (bool): Start browser approval when a saved
                approval is absent, expired or definitively refused.

        Returns:
            bool: True when a saved grant starts verification; false otherwise.
        """
        canonical = linking_issuer(issuer)
        if not canonical or (self._worker is not None and self._worker.is_alive()):
            return False
        if self._session is not None and self._session_issuer == canonical:
            return False
        try:
            binding = self._saved_offline_binding(canonical)
        except RuntimeError:
            self._status.set('The securely saved gallery connection is unavailable.')
            return True
        if binding is not None:
            store = self._native_offline_store()
            if store is None:
                self._status.set('The securely saved gallery connection is unavailable.')
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
        self._cancel = cancel
        self._status.set('Checking the saved gallery connection…')

        def worker() -> None:
            """Verify the original unexpired grant before exposing it to Photos.

            Returns:
                None: Queues a verified site or a generic recovery event.
            """
            session: LinkingSession | None = None
            try:
                grant = load_transient(canonical)
                if grant is None:
                    self._events.put((
                        owner, 'resume_absent',
                        (canonical, approve_if_absent),
                    ))
                    return
                if ('galleries:list' not in grant.scope.split()
                        or 'offline_access' in grant.scope.split()):
                    self._events.put((
                        owner, 'resume_absent',
                        (canonical, approve_if_absent),
                    ))
                    return
                remaining = grant.expires_at - time.time()
                if remaining <= 0:
                    delete_transient(canonical)
                    self._events.put((
                        owner, 'resume_expired',
                        (canonical, approve_if_absent),
                    ))
                    return
                try:
                    capabilities = discover_linking_capabilities(
                        canonical, cancel,
                    )
                except Exception:
                    if cancel.is_set():
                        raise
                    capabilities = frozenset()
                session = LinkingSession(
                    canonical, grant.token, time.monotonic() + remaining,
                    grant.scope,
                    gallery_tree_supported=(
                        GALLERY_TREE_CAPABILITY in capabilities
                    ),
                )
                identity = session.identity()
                if identity.user_id != grant.account_id:
                    delete_transient(canonical)
                    self._events.put((
                        owner, 'resume_expired',
                        (canonical, approve_if_absent),
                    ))
                    return
                if cancel.is_set() or not session.can_list_galleries():
                    raise LinkingError('Saved gallery access is unavailable.')
                connected_session = session
                self._events.put((
                    owner, 'resumed',
                    (connected_session, identity, canonical),
                ))
                session = None
                self._queue_initial_gallery_page(owner, connected_session, cancel)
            except LinkingGrantRejected:
                try:
                    delete_transient(canonical)
                    self._events.put((
                        owner, 'resume_expired',
                        (canonical, approve_if_absent),
                    ))
                except Exception:  # noqa: BLE001
                    self._events.put((owner, 'resume_error', None))
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

    def open_address_dialog(self, *, manage: bool = False, prefill: str = '') -> None:
        """Choose a live or recent gallery, or paste a new address.

        Args:
            manage (bool): Open access management after address selection.
            prefill (str): Exact issuer suggested by a recovery action, or
                empty to use the current or most recent address.

        Returns:
            None: Shows the prompt, or leaves an active request or dialog open.
        """
        if self._window is not None:
            self._window.lift()
            return
        if self._address_prompt is not None:
            self._address_prompt[0].lift()
            return
        if self._worker is not None and self._worker.is_alive():
            if self._handoff is None:
                self._status.set('A gallery request is already in progress.')
            return

        dialog = tk.Toplevel(self._root)
        title = 'Manage link' if manage else 'Link gallery'
        dialog.title(title)
        dialog.resizable(True, False)
        card = GlassCard(dialog, padding=(16, 12))
        card.pack(fill='both', expand=True)
        ttk.Label(card, text='Gallery URL', style='Section.TLabel').pack(anchor='w')
        recent = self._recent_links
        address = tk.StringVar(
            dialog, value=(prefill or self._session_issuer or self._current_issuer
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
            card, text='Paste the gallery address from your browser.\n\n',
            style='Muted.TLabel', wraplength=LINKING_ADDRESS_STATUS_WRAP,
            justify='left', anchor='w',
        )
        hint.pack(fill='x', pady=(4, 0))
        link_worker: threading.Thread | None = None
        min_width = LINKING_ADDRESS_STATUS_WRAP + LINKING_DIALOG_WRAP_INSET

        def submit() -> None:
            """Default bare gallery hosts to HTTPS before strict validation.

            Returns:
                None: Shows a correction, starts linking, or opens management.
            """
            nonlocal link_worker
            if confirm.instate(['disabled']):
                return
            value = address.get().strip()
            issuer = linking_issuer(value)
            if not issuer and value.lower().startswith('http://'):
                issuer = linking_issuer('https://' + value[len('http://'):])
                if issuer:
                    address.set(issuer)
            elif not issuer and value and not value.startswith('//'):
                authority = value.split('/', 1)[0]
                looks_like_host = (
                    '.' in authority or ':' in authority
                    or authority.startswith('[')
                    or authority.lower() == 'localhost'
                )
                # Require a recognizable authority so `photos/2026` is not
                # silently reinterpreted as a host and installation mount.
                if (looks_like_host and '://' not in value
                        and not any(character.isspace() for character in value)):
                    issuer = linking_issuer('https://' + value)
                    if issuer:
                        address.set(issuer)
            if not issuer:
                hint.configure(
                    textvariable='', text='Enter a valid gallery address.',
                    style='Status.TLabel',
                )
                entry.focus_set()
                return
            if manage:
                self._dismiss_address_prompt()
                self.open(self._root, issuer, manage=True)
                return
            hint.configure(textvariable=self._status, style='Status.TLabel')
            previous_worker = self._worker
            self.link(issuer)
            if self._worker is not None and self._worker is not previous_worker:
                link_worker = self._worker
            if self._address_prompt is not None:
                refresh()

        def cancel() -> None:
            """Dismiss the prompt and cancel any attempt started from it.

            Returns:
                None: Discards the prompt and stops its pending local work.
            """
            if (link_worker is not None and self._worker is link_worker
                    and confirm.instate(['disabled'])):
                self._close_window()
                self._status.set('Gallery linking cancelled.')
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
                None: Fits changed groups and keeps busy controls synchronized.
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
            busy = self._worker is not None and (
                self._worker.is_alive()
                or self._worker is link_worker and not self._events.empty()
            )
            entry.state(['disabled'] if busy else ['!disabled'])
            choices.results.state(['disabled'] if busy else ['!disabled'])
            confirm.state(['disabled'] if busy else ['!disabled'])
            confirm.configure(text=self._text('linking_open_saved_link')
                              if not manage and issuer in live else title)
            unlink_button.state(['!disabled'] if issuer in live and not busy else ['disabled'])
            forget_button.state(['!disabled'] if issuer in recent and not busy else ['disabled'])
            for button, visible in ((unlink_button, issuer in live),
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
        confirm = ttk.Button(
            actions, text=title, command=submit, style='Accent.Compact.TButton',
        )
        confirm.pack(side='right')
        ttk.Button(actions, text='Cancel', command=cancel, style='Compact.TButton').pack(
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

    def link(self, address: str) -> None:
        """Select an available link or start browser approval for a new one.

        Args:
            address (str): Entered or previously selected gallery address.

        Returns:
            None: Shows a live site, starts one approval or reports a refusal.
        """
        if self._worker is not None and self._worker.is_alive():
            self._status.set('A gallery request is already in progress.')
            return
        issuer = linking_issuer(address)
        if not issuer:
            self._status.set('Enter a valid address in Gallery URL.')
            return
        self._refresh(self._current_issuer)
        site = self._live_link(issuer)
        if site is not None:
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
                self._status.set('Open Manage link to change the approved access.')
            return
        if self.resume_saved_link(issuer, approve_if_absent=True):
            self._dismiss_address_prompt()
            return
        if self._session is None:
            self._current_issuer = issuer
            self._capabilities = frozenset()
        self._start(issuer, direct=True)

    def approve_admin_photos(self) -> None:
        """Open fresh browser consent for Admin Photos on the current site.

        Returns:
            None: Starts approval while preserving the current live link.
        """
        session = self.photos_session()
        if session is not None and not session.can_read_admin_media():
            self._start(self._session_issuer, direct=True, replace_photos=True)

    def start_photos_watcher(
        self, session: LinkingSession, gallery: LinkedGallery,
        start: Callable[[LinkedUploadTarget], bool],
        failure: Callable[[str], None],
    ) -> None:
        """Verify the selected Photos gallery and request temporary authority.

        Args:
            session (LinkingSession): Exact live Photos link selected by the user.
            gallery (LinkedGallery): Exact contextual physical gallery.
            start (Callable[[LinkedUploadTarget], bool]): Host starts the
                admitted Watcher with its in-memory credential.
            failure (Callable[[str], None]): Show an actionable refusal on
                the Watcher screen.

        Returns:
            None: Starts one worker or leaves existing capture state unchanged.
        """
        if (session is not self.photos_session() or not session.can_upload()
                or self._commit_linked_target is None
                or self._worker is not None and self._worker.is_alive()):
            message = 'Gallery access is unavailable or another connection operation is running. Try again.'
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
        self._status.set('Preparing the Watcher connection…')

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
                    self._events.put((owner, 'photos_watch_start', _PhotosWatchStart(target, start, failure)))
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
            message = 'Could not prepare the Watcher connection. Try again.'
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
        if (self._cancel.is_set() or target.session is not self.photos_session()
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
        """Journal an explicit Photos creation without applying an upload target.

        Args:
            session (LinkingSession): Live link captured when the user submitted.
            title (str): User-entered physical gallery title.
            parent_id (int | None): Exact parent gallery ID, or root.
            dispatch (CreateDispatch | None): Optional contextual cancellation gate.

        Returns:
            BoundCreateReceipt: Authoritative completed gallery and intent binding.

        Raises:
            LinkingError: The session, input or result could not be verified.
        """
        issuer, account = self._session_issuer, self._session_user_id
        if session is not self.photos_session() or not session.can_create_gallery():
            raise LinkingError('Photos gallery creation is unavailable.')
        return self._create_journal.create(
            session, issuer, account, title, parent_id, dispatch,
        )

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
                or not isinstance(snapshot.intent_fingerprint, str)
                or session is not self.photos_session()
                or not session.can_create_gallery()
                or self._session_issuer != snapshot.issuer
                or self._session_user_id != snapshot.account_id
                or not session.matches_issuer(snapshot.issuer)
                or session.identity().user_id != snapshot.account_id):
            raise LinkingError('Saved gallery creation is not current.')
        return self._create_journal.reconcile(
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
                or snapshot.intent_fingerprint != snapshot.receipt.intent_fingerprint
                or session is not self.photos_session()
                or self._session_issuer != issuer
                or self._current_issuer != issuer
                or self._session_user_id != account_id
                or not session.available() or not session.can_create_gallery()
                or not session.matches_issuer(issuer)
                or session.identity().user_id != account_id):
            raise LinkingError('The current gallery connection changed.')
        self._create_journal.acknowledge(
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
                    'app_linking_ui.py', 'linked_create.py', 'app_linking.py',
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
        """Clear the completed Photos receipt after explicit acknowledgement.

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
        self._create_journal.acknowledge(issuer, account, receipt)

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
    ) -> bool:
        """Publish a fresh server result only for the saved continuation target.

        Args:
            target (LinkedUploadTarget): Exact gallery/account freshly verified
                by the continuation worker using the restored live grant.
            binding (str): Original target fingerprint from the claimed update.
            approved_scope (str): Exact original scope captured before updating.

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
                    or target.session.approved_scope() != approved_scope
                    or self._target_refusal(binding) is not None):
                return False
            if not commit(target):
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
        canonical_issuer = linking_issuer(issuer + '/index.php')
        gallery_id = gallery.gallery_id
        if (canonical_issuer != issuer
                or not session.matches_issuer(canonical_issuer)
                or type(gallery_id) is not int
                or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX):
            return False
        url = (
            f'{canonical_issuer}/index.php?page=admin_edit_gallery'
            f'&id={gallery_id}&open_panel=1'
        )
        try:
            return bool(webbrowser.open(url))
        except Exception:  # noqa: BLE001
            return False

    def open(self, parent: tk.Misc, address: str, *, manage: bool = False) -> None:
        """Open the gallery picker or the separate access management surface.

        Args:
            parent (tk.Misc): Owning app window.
            address (str): Existing or explicitly entered gallery address.
            manage (bool): Show permission and connection management controls
                instead of gallery operations.

        Returns:
            None: Opens the requested workspace or asks for its address first.
        """
        if self._window is not None:
            self._window.lift()
            return
        issuer = linking_issuer(address)
        if manage and not issuer:
            self.open_address_dialog(manage=True)
            return
        self._refresh(self._current_issuer)
        site = self._live_link(issuer)
        if (self._worker is not None and self._worker.is_alive()
                and (manage or site is None or self._session is not site.session)):
            self._status.set('Wait for the current gallery request to finish.')
            return
        if not manage and site is None:
            self._status.set('Link the gallery to load its gallery list.')
            return
        if site is not None and self._session is not site.session:
            self._show_link(site)
        elif self._session is not None and self._session_issuer != issuer:
            self._park_current_link()
            self._clear_session_display()
        self._current_issuer = issuer
        self._capabilities = frozenset()
        self._detail_snapshot = None
        self._offline_store = None
        self._offline_binding = None
        self._offline_store = self._native_offline_store()
        if self._offline_store is not None:
            try:
                self._offline_binding = self._saved_offline_binding(issuer)
            except RuntimeError:
                self._offline_store = None
        window = tk.Toplevel(parent)
        self._window = window
        title = 'Manage link' if manage else 'Choose gallery'
        window.title(title)
        window.transient(parent)
        window.resizable(True, True)
        window.columnconfigure(0, weight=1)
        window.rowconfigure(0 if manage else 1, weight=1)
        self._scroll = _LinkingScrollFrame(window)
        if manage:
            self._scroll.grid(row=0, column=0, sticky='nsew')
        card = self._scroll.content
        picker = card if manage else GlassCard(window, padding=(12, 10))
        card.columnconfigure(0, weight=1)
        picker.columnconfigure(0, weight=1)
        ttk.Label(
            card, text=title if manage else self._text('linking_gallery_tools'),
            style='Section.TLabel',
        ).grid(
            row=0, column=0, sticky='w', pady=(0, 6),
        )
        issuer_label = ttk.Label(
            card, text=issuer or 'Enter the address of your gallery.',
            style='Muted.TLabel',
        )
        issuer_label.grid(row=1, column=0, sticky='ew', pady=(0, 6))
        explanation = ttk.Label(
            card,
            text=('Choose permissions for this connection. Optional secure storage '
                  'requires separate browser consent and permits renewal for up to '
                  '30 days, with a seven-day inactivity limit. The saved API key '
                  'remains available; server grants may remain until revoked.'),
            style='Muted.TLabel',
        )
        self._scope_actions = ttk.Frame(card, style='Card.TFrame')
        self._browse_var = tk.BooleanVar(value=self._listing_scope_selected(issuer))
        self._upload_var = tk.BooleanVar(value=self._upload_scope_selected(issuer))
        self._read_var = tk.BooleanVar(value=self._read_scope_selected(issuer))
        self._create_var = tk.BooleanVar(
            value=(self._session is not None and self._session_issuer == issuer
                   and self._session.can_create_gallery()),
        )
        self._browse_check = ttk.Checkbutton(
            self._scope_actions, variable=self._browse_var, command=self._scope_changed,
            text='Allow listing galleries',
        )
        self._upload_check = ttk.Checkbutton(
            self._scope_actions, variable=self._upload_var, command=self._scope_changed,
            text='Allow gallery uploads',
        )
        self._read_check = ttk.Checkbutton(
            self._scope_actions, variable=self._read_var, command=self._scope_changed,
            text='Read gallery details',
        )
        self._create_check = ttk.Checkbutton(
            self._scope_actions, variable=self._create_var,
            command=self._scope_changed, text='Allow creating galleries',
        )
        self._visibility_var = tk.BooleanVar(
            value=(LINKING_VISIBILITY_READY and self._session is not None
                   and self._session_issuer == issuer
                   and self._session.can_change_gallery_visibility()),
        )
        self._visibility_check = ttk.Checkbutton(
            self._scope_actions, variable=self._visibility_var,
            command=self._scope_changed,
            text=self._text('linking_visibility_action'),
        )
        self._media_var = tk.BooleanVar(value=False)
        self._media_check = ttk.Checkbutton(
            self._scope_actions, variable=self._media_var,
            command=self._scope_changed, text=self._text('linking_media_consent'),
        )
        self._offline_var = tk.BooleanVar(value=False)
        self._offline_check = ttk.Checkbutton(
            self._scope_actions, variable=self._offline_var,
            text='Remember securely',
        )
        browse_explanation = ttk.Label(
            card, text='These permissions include private and unpublished galleries.',
            style='Muted.TLabel',
        )
        status_label = ttk.Label(card, textvariable=self._status)
        self._link_actions = ttk.Frame(card, style='Card.TFrame')
        if manage:
            explanation.grid(row=2, column=0, sticky='ew', pady=(0, 8))
            self._scope_actions.grid(row=3, column=0, sticky='ew')
            browse_explanation.grid(row=4, column=0, sticky='ew')
            status_label.grid(row=5, column=0, sticky='ew', pady=(0, 6))
            self._link_actions.grid(row=6, column=0, sticky='ew', pady=(8, 0))
        self._start_button = ttk.Button(
            self._link_actions, text=self._text('linking_start'),
            command=lambda: self._start(issuer), style='Compact.TButton',
        )
        self._browser_button = ttk.Button(
            self._link_actions, text=self._text('linking_open_browser'),
            command=self._open_browser, style='Compact.TButton',
        )
        self._revoke_button = ttk.Button(
            self._link_actions, text='Unlink gallery', command=self.unlink,
            style='Compact.TButton',
        )
        gallery_label = ttk.Label(
            picker, text=self._text('linking_gallery_search'),
            style='Section.TLabel',
        )
        self._gallery_choice = _GallerySuggestions(
            picker, self._gallery_query, self._text('linking_clear_gallery_search'),
            self._gallery_search_changed, self._gallery_changed, self._select_gallery,
        )
        toolbar = ttk.Frame(picker, style='Card.TFrame')
        toolbar.columnconfigure(3, weight=1)
        self._gallery_actions = ttk.Frame(card, style='Card.TFrame')
        self._gallery_refresh_button = icon_button(
            toolbar, 'refresh', lambda: self._load_galleries(None),
            self._text('linking_refresh_galleries'),
        )
        self._gallery_next_button = icon_button(
            toolbar, '›', self._next_galleries, self._text('linking_next_page'),
        )
        self._gallery_select_button = ttk.Button(
            toolbar, text=self._text('linking_select_gallery'),
            command=self._select_gallery, style='Accent.Compact.TButton',
        )
        self._recovery_button = ttk.Button(
            self._gallery_actions, text='Check uncertain upload',
            command=self._check_uncertain_upload, style='Compact.TButton',
        )
        self._review_button = ttk.Button(
            self._gallery_actions, text=self._text('linking_review_title'),
            command=self._review_uncertain_upload, style='Compact.TButton',
        )
        self._detail_button = ttk.Button(
            self._gallery_actions, text='Read selected details',
            command=self._read_detail, style='Compact.TButton',
        )
        self._visibility_choice = ttk.Combobox(
            self._gallery_actions, state='readonly', width=15,
            values=('public', 'private', 'unpublished'),
        )
        self._visibility_button = ttk.Button(
            self._gallery_actions, text=self._text('linking_visibility_action'),
            command=self._change_visibility, style='Compact.TButton',
        )
        self._create_button = ttk.Button(
            self._gallery_actions, text='Create gallery', command=self._create_gallery,
            style='Compact.TButton',
        )
        self._create_status_button = ttk.Button(
            self._gallery_actions, text='Check create status',
            command=self._reconcile_gallery_create, style='Compact.TButton',
        )
        self._create_resume_button = ttk.Button(
            self._gallery_actions, text='Resume original creation',
            command=self._resume_gallery_create, style='Compact.TButton',
        )
        self._create_abandon_button = ttk.Button(
            self._gallery_actions, text='Close unresolved creation',
            command=self._abandon_gallery_create, style='Compact.TButton',
        )
        self._use_created_button = ttk.Button(
            self._gallery_actions, text=self._text('linking_use_created'),
            command=self._use_created_gallery, style='Compact.TButton',
        )
        self._ack_created_button = ttk.Button(
            self._gallery_actions, text='Acknowledge creation',
            command=self._acknowledge_created_gallery, style='Compact.TButton',
        )
        gallery_status_label = ttk.Label(
            picker, textvariable=self._gallery_status, style='Muted.TLabel',
        )
        if not manage:
            def toggle_gallery_tools() -> None:
                """Reveal the optional gallery actions under the fixed picker.

                Returns:
                    None: Expands or folds the scrolling tools in place.
                """
                if self._scroll.winfo_manager():
                    self._scroll.grid_remove()
                else:
                    self._scroll.grid(row=1, column=0, sticky='nsew')
                window.geometry('')

            picker.grid(row=0, column=0, sticky='ew')
            gallery_label.grid(row=0, column=0, sticky='w', pady=(0, 4))
            self._gallery_choice.grid(row=1, column=0, sticky='ew')
            toolbar.grid(row=2, column=0, sticky='ew', pady=(5, 0))
            self._gallery_refresh_button.grid(row=0, column=0)
            self._gallery_next_button.grid(row=0, column=1, padx=(2, 0))
            icon_button(
                toolbar, '⋯', toggle_gallery_tools,
                self._text('linking_gallery_tools'),
            ).grid(row=0, column=2, padx=(2, 0))
            self._gallery_select_button.grid(row=0, column=3, sticky='e')
            gallery_status_label.grid(row=3, column=0, sticky='ew', pady=(4, 0))
            self._gallery_actions.grid(row=7, column=0, sticky='ew', pady=(4, 0))
            self._display_gallery_page()
        self._footer_actions = ttk.Frame(card, style='Card.TFrame')
        if manage:
            self._footer_actions.grid(row=12, column=0, sticky='ew', pady=(10, 0))
        self._manage_button = ttk.Button(
            self._footer_actions, text='Manage gallery links',
            command=self._open_devices, style='Compact.TButton',
        )
        self._restore_button = ttk.Button(
            self._footer_actions, text='Reconnect saved link',
            command=self._restore_offline, style='Compact.TButton',
        )
        self._discard_button = ttk.Button(
            self._footer_actions, text='Forget saved link',
            command=self._discard_offline, style='Compact.TButton',
        )
        self._close_button = ttk.Button(
            self._footer_actions, text='Close', command=self._close_window,
            style='Compact.TButton',
        )
        self._wrapping_labels = (
            issuer_label, explanation, browse_explanation, status_label,
            gallery_label, gallery_status_label,
        )
        for control in (
            self._browse_check, self._upload_check, self._read_check,
            self._create_check, self._visibility_check, self._media_check,
            self._offline_check,
            self._start_button, self._browser_button,
            self._revoke_button, self._recovery_button,
            self._review_button, self._detail_button,
            self._visibility_choice, self._visibility_button,
            self._create_button, self._create_status_button, self._create_resume_button,
            self._create_abandon_button,
            self._use_created_button, self._ack_created_button,
            self._manage_button, self._restore_button, self._discard_button,
            self._close_button,
        ):
            control.bind('<FocusIn>', self._scroll.ensure_visible, add='+')
        self._dialog_layout_columns = 0
        self._dialog_wrap_width = 0
        self._manage = manage
        self._apply_dialog_layout(LINKING_DIALOG_INITIAL_WIDTH)
        window.protocol('WM_DELETE_WINDOW', self._close_window)
        window.bind('<Escape>', lambda _event: self._close_window())
        window.bind('<Configure>', self._on_dialog_configure, add='+')
        if not manage:
            window.update_idletasks()
            window.minsize(LINKING_PICKER_MIN_WIDTH, picker.winfo_reqheight())
            self._gallery_choice.entry.focus_set()
        window.grab_set()
        self._refresh(issuer)
        if manage and issuer and self._session is None:
            self._inspect_capabilities(issuer)
        elif not manage and self._gallery_page is None:
            self._load_galleries(None)

    def _on_dialog_configure(self, event: tk.Event) -> None:
        """Reflow the active dialog after its own width changes.

        Args:
            event (tk.Event): Tk configure event with widget and width fields.

        Returns:
            None: Ignores child events and applies the window's current width.
        """
        if event.widget is self._window and event.width > 1:
            self._apply_dialog_layout(int(event.width))

    def _apply_dialog_layout(self, width: int) -> None:
        """Wrap labels and reflow controls within the available dialog width.

        Args:
            width (int): Current top-level dialog width in Tk pixels.

        Returns:
            None: Updates wrapping and uses one, two or three action columns.
        """
        wrap_width = max(LINKING_DIALOG_MIN_WRAP, width - LINKING_DIALOG_WRAP_INSET)
        if wrap_width != self._dialog_wrap_width:
            self._dialog_wrap_width = wrap_width
            for label in self._wrapping_labels:
                label.configure(wraplength=wrap_width)
        if width < LINKING_DIALOG_SINGLE_COLUMN_WIDTH:
            columns = 1
        elif width < LINKING_DIALOG_COMPACT_WIDTH:
            columns = 2
        else:
            columns = 3
        if columns == self._dialog_layout_columns:
            return
        self._dialog_layout_columns = columns
        self._layout_action_group(
            self._scope_actions, (self._browse_check, self._upload_check,
                                  self._read_check, self._create_check,
                                  self._visibility_check, self._media_check,
                                  self._offline_check),
            columns,
        )
        self._layout_action_group(
            self._link_actions,
            (self._start_button, self._browser_button, self._revoke_button),
            columns,
        )
        self._layout_action_group(
            self._gallery_actions,
            (self._recovery_button,
             self._review_button, self._detail_button,
             self._visibility_choice, self._visibility_button,
             self._create_button,
             self._create_status_button, self._create_resume_button,
             self._create_abandon_button,
             self._use_created_button,
             self._ack_created_button),
            columns,
        )
        footer = (self._manage_button, self._restore_button, self._discard_button,
                  self._close_button) if self._manage else (self._close_button,)
        self._layout_action_group(self._footer_actions, footer, columns)

    def _layout_action_group(
        self, frame: ttk.Frame,
        actions: tuple[ttk.Button | ttk.Checkbutton | ttk.Combobox, ...], columns: int,
    ) -> None:
        """Place existing action widgets into responsive rows without rebinding.

        Args:
            frame (ttk.Frame): Existing container for this group of controls.
            actions (tuple[ttk.Button | ttk.Checkbutton | ttk.Combobox, ...]): Ordered action
                and permission widgets to place.
            columns (int): Maximum number of action columns at this width.

        Returns:
            None: Repositions the same widgets while retaining focus and commands.
        """
        used_columns = min(columns, len(actions))
        for column in range(3):
            frame.columnconfigure(column, weight=1 if column < used_columns else 0)
        for index, button in enumerate(actions):
            row, column = divmod(index, used_columns)
            # A lone final button owns the whole row instead of a clipped cell.
            last_alone = (index == len(actions) - 1 and column == 0
                          and row > 0 and used_columns > 1)
            span = used_columns if last_alone else 1
            sticky = 'e' if column == used_columns - 1 and column > 0 else 'w'
            button.grid(row=row, column=column, columnspan=span, sticky=sticky,
                        pady=(0, 4))

    def _live_links(self) -> tuple[_LinkedSite, ...]:
        """Project the selected and parked sessions that are still available.

        Returns:
            tuple[_LinkedSite, ...]: The selected live site followed by other
                live sites, each bound to its issuer, session and Admin ID.
        """
        parked = tuple(site for site in self._parked_links if site.session.available())
        if self._session is None or not self._session.available():
            return parked
        return (_LinkedSite(self._session_issuer, self._session, self._session_user_id), *parked)

    def _live_link(self, issuer: str) -> _LinkedSite | None:
        """Find a still-live approval for the exact chosen installation.

        Args:
            issuer (str): Canonical issuer or empty when the field is invalid.

        Returns:
            _LinkedSite | None: Matching live session with its Admin ID, or None.
        """
        return next((site for site in self._live_links() if site.issuer == issuer), None)

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
        self._session = site.session
        self._session_issuer = self._current_issuer = site.issuer
        self._session_user_id = site.user_id
        self._reset_gallery_choices()
        self._gallery_status.set(
            'Loading galleries…' if site.session.can_list_galleries()
            else 'This link does not include the gallery list.',
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
        if not LINKING_VISIBILITY_READY:
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
            self._gallery_status.set('Gallery link expired.')
            self._status.set('Gallery link expired.')
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
            ['!disabled'] if (LINKING_VISIBILITY_READY and can_request
                              and 'gallery:visibility' in capabilities) else ['disabled'],
        )
        self._media_check.configure(text=self._text(
            'linking_admin_media_consent' if 'media:admin' in capabilities
            else 'linking_media_consent',
        ))
        self._media_check.state(
            ['!disabled'] if (LINKING_NATIVE_MEDIA_READY and can_request
                              and 'media:read' in capabilities) else ['disabled'],
        )
        can_create = (can_list and self._session is not None
                      and self._session.can_create_gallery())
        created = self._created_gallery
        unacknowledged = (created is not None and self._session is created[1]
                          and self._session_issuer == issuer == created[2]
                          and self._session_user_id == created[3])
        self._create_button.state(
            ['!disabled'] if can_create and not unacknowledged else ['disabled'],
        )
        self._create_status_button.state(['!disabled'] if can_create else ['disabled'])
        self._create_resume_button.state(
            ['!disabled'] if can_create and not unacknowledged else ['disabled'],
        )
        self._create_abandon_button.state(
            ['!disabled'] if can_create and not unacknowledged else ['disabled'],
        )
        can_use_created = (LINKING_UPLOAD_TARGET_READY and LINKING_CREATED_TARGET_READY
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
        primary_ready = can_select and (LINKING_UPLOAD_TARGET_READY or not upload_grant)
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
        can_change = (LINKING_VISIBILITY_READY and can_read and snapshot is not None
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
            ['!disabled'] if self._handoff is not None else ['disabled'],
        )
        self._revoke_button.state(
            ['!disabled'] if self._session is not None and not busy
            else ['disabled'],
        )

    def _start(
        self, issuer: str, *, direct: bool = False, replace_photos: bool = False,
    ) -> None:
        """Start one browser request while retaining any other live site.

        Args:
            issuer (str): Already displayed and validated installation issuer.
            direct (bool): Request listing from the main action, with renewal and
                upload and presentation when both ends support those capabilities.
            replace_photos (bool): Request a new Admin Photos grant for the
                current site without closing its existing approved session.

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
                self._status.set(refusal or 'Linked upload mode cannot be verified.')
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
                or (LINKING_UPLOAD_TARGET_READY
                    and self._upload_workspace_ready is not None
                    and self._upload_workspace_ready())
            )
            attempt = LinkingAttempt(issuer, scope=scope)
        except Exception:  # noqa: BLE001
            self._status.set('Could not start a secure linking attempt.')
            return
        owner = object()
        cancel = threading.Event()
        offline_store = None if direct else self._offline_store
        self._owner, self._attempt, self._cancel = owner, attempt, cancel
        self._direct_owner = owner if direct else None
        self._handoff = None
        self._status.set(self._text('linking_requesting'))

        def worker() -> None:
            """Request, wait, and validate identity without accessing Tk.

            Returns:
                None: Queues bounded results and closes the attempt.
            """
            session = None
            failure = 'request_failed'
            try:
                store = self._native_offline_store() if direct else offline_store
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
                    if (offline or sys.platform != 'darwin'
                            or not approved_session.can_list_galleries()):
                        return
                    try:
                        snapshot = approved_session.transient_snapshot()
                        save_transient(TransientGrant(
                            snapshot.issuer, identity.user_id, snapshot.scope,
                            snapshot.expires_at, snapshot.token,
                        ))
                    except Exception:  # noqa: BLE001
                        pass

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
                        self._save_photos_upgrade(
                            store, binding, previous_scope, refresh, remaining,
                        )
                        previous.disable_renewal()
                        self._events.put((owner, 'offline_saved', (binding, store)))
                    else:
                        with store.binding_transaction(issuer, identity.user_id):
                            # A new hint survives interrupted vault persistence.
                            save_offline_binding(path, binding)
                            self._events.put((owner, 'offline_saved', (binding, store)))
                            store_approved_refresh(
                                store, issuer, identity.user_id, approved_scope,
                                refresh, remaining,
                            )
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
            self._status.set('Could not start a secure linking attempt.')
            return
        self._refresh(self._session_issuer if self._session is not None else issuer)
        self._schedule_poll()

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
            RuntimeError: Persistence or mode publication failed. Existing
                credential and hint are restored if local storage permits.
        """
        issuer, account = binding.issuer, binding.account_id
        path = self._offline_binding_path(binding)
        preceding = (
            OfflineBinding(issuer, account, previous_scope)
            if previous_scope is not None and previous_scope.endswith(' offline_access')
            else None
        )
        prior_path = self._offline_binding_path(preceding) if preceding is not None else path
        # Earlier Windows grants kept their hint at the shared legacy path.
        migrating = prior_path != path

        def restore(original: bytes | None) -> None:
            """Restore the preceding local generation under the binding lock.

            Args:
                original (bytes | None): Prior bound vault record, if present.

            Returns:
                None: Restores the prior record and hint or removes the new ones.
            """
            try:
                if original is not None:
                    store.write(issuer, account, original)
                else:
                    store.delete(issuer, account)
            finally:
                if migrating and preceding is not None:
                    if load_offline_binding(prior_path, issuer) is None:
                        save_offline_binding(prior_path, preceding)
                    remove_offline_binding(path, binding)
                elif preceding is not None:
                    save_offline_binding(path, preceding, replaces=binding)
                else:
                    remove_offline_binding(path, binding)

        with store.binding_transaction(issuer, account):
            original = store.read(issuer, account)
            if ((original is None) != (preceding is None)
                    or (migrating and load_offline_binding(prior_path, issuer) != preceding)):
                raise LinkingError('Saved gallery credential changed.')
            save_offline_binding(path, binding, replaces=None if migrating else preceding)
            try:
                store_approved_refresh(store, issuer, account, binding.scope, refresh, remaining)
                published = store.read(issuer, account)
                if published is None:
                    raise LinkingError('Saved gallery credential could not be verified.')
                if migrating and preceding is not None:
                    remove_offline_binding(prior_path, preceding)
            except Exception:
                restore(original)
                raise
        if commit is None:
            return
        try:
            # Windows mode admission takes the same user-wide metadata lock.
            if not commit():
                raise LinkingError('Linked upload mode could not be verified.')
        except Exception:
            with store.binding_transaction(issuer, account):
                if (load_offline_binding(path, issuer) == binding
                        and store.read(issuer, account) == published
                        and (not migrating or not prior_path.exists())):
                    restore(original)
            raise

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
            self._status.set('The selected gallery changed during approval.')
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
            self._status.set('The approved Photos connection could not be saved safely.')
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
        scope = (READ_UPLOAD_SCOPE if self._read_var.get() and self._upload_var.get() else
                 READ_SCOPE if self._read_var.get() else
                 UPLOAD_SCOPE if self._upload_var.get() else
                 GALLERIES_SCOPE if self._browse_var.get() else IDENTITY_SCOPE)
        if self._create_var.get():
            scope += ' galleries:create'
        if self._visibility_var.get():
            if not LINKING_VISIBILITY_READY:
                raise LinkingError('Visibility changes are unavailable.')
            if not self._read_var.get() or not self._browse_var.get():
                raise LinkingError('Visibility requires gallery read access.')
            scope += ' gallery:visibility'
        media_var = getattr(self, '_media_var', None)
        if media_var is not None and media_var.get():
            if (not LINKING_NATIVE_MEDIA_READY or 'media:read' not in self._capabilities
                    or scope == IDENTITY_SCOPE or self._offline_var.get()):
                raise LinkingError('Gallery media is unavailable.')
            if 'media:admin' in self._capabilities:
                scope += (ADMIN_PRESENTATION_SCOPE_SUFFIX
                          if 'presentation:read' in self._capabilities else ADMIN_MEDIA_SCOPE_SUFFIX)
            else:
                scope += (PRESENTATION_SCOPE_SUFFIX
                          if 'presentation:read' in self._capabilities else MEDIA_SCOPE_SUFFIX)
        if self._offline_var.get():
            if self._offline_store is None or self._offline_binding is not None:
                raise LinkingError('Secure offline storage is unavailable.')
            scope += ' offline_access'
        return scope

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

    def _schedule_poll(self, delay_ms: int = LINKING_POLL_INTERVAL_MS) -> None:
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
            None: Closes stale sessions and updates the selected site and address list.
        """
        self._polling = False
        while True:
            try:
                owner, kind, payload = self._events.get_nowait()
            except queue.Empty:
                break
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
            if kind == 'capabilities' and isinstance(payload, tuple):
                issuer, capabilities = payload
                if issuer == self._current_issuer and isinstance(capabilities, frozenset):
                    self._capabilities = capabilities
                    if 'galleries:list' not in capabilities:
                        self._browse_var.set(False)
                    self._status.set('' if 'linking' in capabilities
                                     else self._text('linking_server_unavailable'))
            elif kind == 'handoff' and isinstance(payload, PushedAuthorization):
                self._handoff = payload
                self._open_browser()
            elif kind == 'offline_saved' and isinstance(payload, tuple):
                self._offline_binding, self._offline_store = payload
            elif kind == 'offline_discarded' and isinstance(payload, OfflineBinding):
                for site in self._live_links():
                    if site.issuer == payload.issuer and site.user_id == payload.account_id:
                        site.session.disable_renewal()
                self._offline_binding = None
                self._status.set('Saved local credential removed; the current session and server grant may still exist.')
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
                        message = 'Could not start the linked Watcher. Check the Watcher settings and try again.'
                    self._status.set(message)
                    payload.failure(message)
            elif kind == 'photos_watch_failed' and callable(payload):
                message = 'Could not verify the gallery or prepare Watcher access. Check the connection and try again.'
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
                self._clear_handoff()
            elif kind == 'resume_error':
                self._status.set('Could not verify the saved link. Try again.')
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
                    self._status.set('Saved gallery access expired. Approve the link again.')
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
                    self._gallery_status.set('The upload observation is no longer current.')
                else:
                    message = (
                        'Matching bytes were observed. This does not confirm the '
                        'original upload or its registration. Review the gallery; '
                        'the upload remains unresolved.'
                        if result == 'present' else
                        'The observation is unknown and does not prove absence. '
                        'Review the gallery; the upload remains unresolved.'
                    )
                    if not self._recovery_publish(
                        target, file_hash, lambda: self._gallery_status.set(message),
                    ):
                        self._gallery_status.set('The upload observation is no longer current.')
            elif kind == 'recovery_error':
                self._gallery_status.set(
                    'Could not verify the uncertain upload. It remains unresolved.',
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
                    'The selected gallery could not be verified. '
                    'The previous upload target is unchanged.',
                )
            elif kind == 'create_completed' and isinstance(payload, tuple):
                bound, session, issuer, account = payload
                if (isinstance(bound, BoundCreateReceipt)
                        and self._session is session
                        and self._session_issuer == issuer == self._current_issuer
                        and self._session_user_id == account and session.available()):
                    self._created_gallery = (bound, session, issuer, account)
                    upload_allowed = session.can_upload()
                    if (upload_allowed and LINKING_UPLOAD_TARGET_READY
                            and LINKING_CREATED_TARGET_READY):
                        next_action = self._text('linking_created_next')
                    elif upload_allowed:
                        next_action = ('Linked upload selection is temporarily unavailable. '
                                       'Acknowledge the creation to continue.')
                    else:
                        next_action = 'Acknowledge the creation to continue.'
                    self._gallery_status.set(
                        'Created ' + _gallery_label(bound.gallery) + '. ' + next_action,
                    )
                else:
                    self._gallery_status.set(
                        'The creation receipt was saved; reconnect and check its status.',
                    )
            elif kind == 'create_acknowledged':
                self._created_gallery = None
                self._gallery_status.set('Creation acknowledged. You may create another gallery.')
            elif kind == 'create_ack_error':
                self._gallery_status.set('The creation receipt could not be acknowledged safely.')
            elif kind == 'create_selection_verified' and isinstance(payload, tuple):
                if (len(payload) == 7 and isinstance(payload[0], BoundCreateReceipt)
                        and isinstance(payload[1], LinkedUploadTarget)
                        and isinstance(payload[6], threading.Event)):
                    self._apply_created_gallery(*payload)
            elif kind == 'create_selection_error':
                self._gallery_status.set(
                    'The created gallery is unavailable or its access could not be verified.',
                )
            elif kind == 'create_unknown':
                self._gallery_status.set(
                    'Creation is unconfirmed. Do not create it again. Reconnect '
                    'to the same account and check status. Explicitly Resume '
                    'original creation, or review the gallery and storage and '
                    'use Close unresolved creation.',
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
                        'Close unresolved creation' if abandoning
                        else 'Resume original creation', prompt, parent=self._window,
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
                            'The original creation remains unresolved.',
                        )
            elif kind == 'create_resume_unavailable':
                self._gallery_status.set(
                    'No unresolved creation is available for this account. '
                    'Check the creation status before continuing.',
                )
            elif kind == 'create_abandoned':
                self._gallery_status.set(
                    'The original key is closed. A gallery or files may already '
                    'exist or old work may still be finishing. Verify it has '
                    'stopped and inspect the gallery and storage before creating another.',
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
                        f'{prefix}Gallery #{payload.gallery_id}: {title} · Revision {payload.revision}. '
                        f'Saved visibility: {payload.visibility}; access: {payload.access_mode}; '
                        f'listing: {payload.access_listing}. Inherited access is not shown.',
                    )
            elif kind == 'detail_error':
                self._detail_snapshot = None
                self._gallery_status.set(
                    'Gallery detail is unsupported or unavailable. Existing '
                    'connections and saved API-key upload remain unchanged.',
                )
            elif kind == 'visibility_unknown':
                self._gallery_status.set(self._text('linking_visibility_unknown'))
            elif kind == 'visibility_scheduled':
                self._gallery_status.set(self._text('linking_visibility_schedule'))
            elif kind == 'visibility_conflict':
                self._gallery_status.set(self._text('linking_visibility_conflict_read'))
            elif kind == 'offline_unavailable':
                self._status.set(
                    'Could not reach the saved gallery renewal service. Try again when it is available.',
                )
            elif kind == 'offline_error':
                self._status.set(
                    'Saved link could not be verified. Do not retry an uncertain '
                    'refresh; forget it locally and review gallery links before '
                    'authorizing again.',
                )
            elif kind == 'unlink_cleanup_error':
                self._status.set(
                    'The saved secure reconnect could not be removed. '
                    'The gallery remains connected.',
                )
            elif kind == 'unlink_vault_cleared' and isinstance(payload, tuple):
                site, revoked = payload
                self._continue_unlink(site, revoked)
            elif kind == 'galleries_error':
                self._gallery_page = None
                self._display_gallery_page()
                self._status.set('Could not load galleries.')
                self._gallery_status.set(
                    'Could not load the current gallery list. Access may have '
                    'expired or been revoked; refresh after reconnecting.',
                )
            elif kind == 'response_error' and isinstance(
                payload, LinkingResponseError,
            ):
                self._clear_handoff()
                summary = payload.safe_summary
                self._record_diagnostic(summary, failure=True)
                if payload.reason == 'dns_failure':
                    message = 'Could not resolve the gallery address (DNS).'
                elif payload.reason == 'connect_failure':
                    message = 'Could not connect to the gallery service.'
                elif payload.reason == 'tls_failure':
                    message = 'Could not establish secure HTTPS to the gallery.'
                elif payload.reason == 'timeout':
                    message = 'The gallery request timed out.'
                elif (payload.status is not None
                      and payload.content_kind == 'html'):
                    message = (
                        f'The gallery returned HTTP {payload.status} '
                        'with an HTML response.'
                    )
                elif payload.status is not None:
                    message = f'The gallery returned HTTP {payload.status}.'
                else:
                    message = 'The linking response could not be accepted.'
                self._status.set(message)
            elif kind == 'denied':
                self._clear_handoff()
                self._status.set('The gallery did not approve this link.')
            elif kind == 'exchanging':
                self._clear_handoff()
                self._status.set('Checking the approved identity…')
            elif kind == 'revoked':
                self._status.set('Gallery unlinked.')
            elif kind == 'revoke_error':
                self._status.set(
                    'Unlinked locally. Check Manage link to confirm server revocation.',
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
                messages = {
                    'unavailable': (
                        'Browser linking is unavailable at this gallery address.'
                    ),
                    'https_failed': (
                        'Could not establish secure HTTPS. '
                        'Check the address, port and certificate.'
                    ),
                    'request_failed': (
                        'Could not reach the linking service. '
                        'Check the gallery address and path.'
                    ),
                    'callback_failed': 'Browser approval expired or could not return to the app. Try Link gallery again.',
                    'exchange_failed': 'Could not verify the approved connection. Try Link gallery again.',
                    'save_failed': (
                        'The browser approved the link, but secure storage failed.'
                    ),
                }
                self._status.set(messages[kind])
            else:
                self._clear_handoff()
                self._status.set(
                    'Linking could not be verified; an older server may not '
                    'support it. Saved API-key upload remains available. '
                    'A server grant may exist; review gallery links.',
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
        # Check the queue after observing worker termination: the worker may
        # have posted its final event just after the drain above and exited.
        if ((self._worker is not None and self._worker.is_alive())
                or not self._events.empty()):
            self._schedule_poll()
        else:
            if self._session is not None or self._parked_links:
                self._schedule_poll(LINKING_IDLE_POLL_INTERVAL_MS)

    def _restore_offline(
        self, *, silent: bool = False, approve_if_expired: bool = False,
    ) -> None:
        """Rotate one approved saved link and publish its verified site.

        Args:
            silent (bool): Keep startup restoration from raising the main window.
            approve_if_expired (bool): Continue an explicit Link action with
                browser approval after definite rejection and local removal.

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
        self._status.set('Checking the securely saved connection…')

        def worker() -> None:
            """Rotate and verify the same identity without touching Tk.

            Returns:
                None: Queues a verified session or generic recovery notice.
            """
            session = None
            try:
                # A startup with no network must leave the refresh fence alone.
                required = ('rolling_access' if binding.scope.endswith(
                    ROLLING_SCOPE_SUFFIX,
                ) else 'offline_access')
                capabilities = discover_linking_capabilities(binding.issuer, cancel)
                if required not in capabilities:
                    self._events.put((owner, 'offline_unavailable', None))
                    return
                access = rotate_saved_offline_access(store, path, binding, cancel)
                session = LinkingSession(
                    access.issuer, access.token, access.expires_at, access.scope,
                    admin_preload_supported=(
                        ADMIN_PRELOAD_CAPABILITY in capabilities
                    ),
                    gallery_tree_supported=(
                        GALLERY_TREE_CAPABILITY in capabilities
                    ),
                )
                identity = session.identity()
                if (identity.user_id != binding.account_id
                        or identity.username != access.username or cancel.is_set()):
                    raise LinkingError('Offline identity is unavailable.')
                enable_rolling_renewal(session, store, path, binding)
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
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'offline_error', None))
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
            self._status.set('Could not start the secure reconnect.')
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
            'Forget saved link',
            'Remove the saved local credential? The current session remains active. '
            'This does not revoke the server grant. Manage gallery links to '
            'review or revoke it.',
            parent=self._window,
        ):
            return
        path = self._offline_binding_path(binding)
        owner = object()
        self._owner = owner
        self._status.set('Removing the saved local link…')

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
            self._status.set('Could not start local removal.')
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

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
            'Create gallery', 'Gallery title:', parent=self._window,
        )
        if title is None:
            return
        parent_id = None
        page, index = self._gallery_page, self._gallery_choice.current()
        if page is not None and 0 <= index < len(page.galleries):
            parent = page.galleries[index]
            use_parent = messagebox.askyesnocancel(
                'Create gallery',
                'Create inside ' + _gallery_label(parent)
                + '? Choose No to create at the root.', parent=self._window,
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
        self._gallery_status.set('Checking gallery creation…')

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
                    bound = self._create_journal.create(
                        session, issuer, account, title, parent_id, dispatch,
                    )
                self._events.put((owner, 'create_completed' if bound else 'create_unknown',
                                  (bound, session, issuer, account) if bound else None))
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'create_unknown', None))

        self._worker = threading.Thread(
            target=worker, name='PHPGalleryCreate', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._gallery_status.set('Could not start gallery creation.')
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
        self._gallery_status.set('Reading the original creation request…')

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
            self._gallery_status.set('Could not read the original creation request.')
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

    def _acknowledge_created_gallery(self) -> None:
        """Acknowledge a completed receipt before permitting another create.

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
        self._gallery_status.set('Acknowledging the saved creation receipt…')

        def worker() -> None:
            """Clear only the completed receipt explicitly acknowledged here.

            Returns:
                None: Queues success or a bounded refusal for the dialog.
            """
            try:
                self._create_journal.acknowledge(issuer, account, bound)
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
            self._gallery_status.set('Could not acknowledge the creation receipt.')
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

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
                                 else 'Loading the current gallery page…')

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
            self._gallery_status.set('Could not start the gallery request.')
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
            'Check uncertain upload',
            'Enter the original file size in bytes. No local file is opened. '
            'A match only reports observed bytes and does not confirm an upload.',
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
        self._gallery_status.set('Checking the uncertain upload once…')

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
            self._gallery_status.set('Could not start the upload observation.')
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
        self._gallery_status.set('Reading the selected gallery details…')

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
            self._gallery_status.set('Could not start the gallery detail request.')
            return
        self._refresh(self._current_issuer)
        self._schedule_poll()

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

    def _change_visibility(self) -> None:
        """Confirm and submit one change using the current live detail.

        Returns:
            None: Starts one worker or leaves the prior selection unchanged.
        """
        snapshot = self._detail_snapshot
        if not LINKING_VISIBILITY_READY:
            return
        session, page = self._session, self._gallery_page
        selected = self._gallery_choice.current()
        target = self._visibility_choice.get()
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
            self._gallery_status.set('Choose a different visibility.')
            return
        if not messagebox.askyesno(
            self._text('linking_visibility_action'),
            self._text('linking_visibility_confirm').format(
                gallery=detail.gallery_id, previous=detail.visibility, target=target,
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
            self._gallery_status.set('Could not start the visibility request; read details again.')
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
        if not (LINKING_UPLOAD_TARGET_READY and LINKING_CREATED_TARGET_READY):
            self._gallery_status.set(
                'Linked upload selection is temporarily unavailable. '
                'The current connection remains selected.',
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
            self._gallery_status.set('Reconnect and verify the created gallery first.')
            return
        try:
            if (not session.can_upload() or not session.can_create_gallery()
                    or not session.matches_issuer(issuer)):
                raise LinkingError('Gallery session is unavailable.')
            scope = session.approved_scope()
        except Exception:
            self._gallery_status.set('Reconnect and verify the created gallery first.')
            return
        owner = object()
        cancel = threading.Event()
        self._owner = owner
        self._gallery_cancel = cancel
        self._gallery_status.set('Verifying the created gallery before selection…')

        def worker() -> None:
            """Verify the original receipt and the independent current gallery.

            Returns:
                None: Queues only one owner-bound, freshly verified result.
            """
            try:
                current = self._create_journal.reconcile(session, issuer, account)
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
            self._gallery_status.set('Could not verify the created gallery.')
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
        if not (LINKING_UPLOAD_TARGET_READY and LINKING_CREATED_TARGET_READY):
            self._gallery_status.set(
                'Linked upload selection is temporarily unavailable. '
                'The current connection remains selected.',
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
            self._gallery_status.set('The created gallery verification is no longer current.')
            return
        try:
            if (not session.can_upload() or not session.can_create_gallery()
                    or not session.matches_issuer(issuer)
                    or session.approved_scope() != scope):
                raise LinkingError('Created gallery session changed.')
        except Exception:
            self._gallery_status.set('The created gallery verification is no longer current.')
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
            if (not LINKING_UPLOAD_TARGET_READY or not LINKING_CREATED_TARGET_READY
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
                'Linked upload target: ' + _gallery_label(gallery)
                + '. The saved API key remains unchanged.',
            )
            try:
                self._notify_target_changed()
            except Exception:
                self._gallery_status.set(
                    'Upload mode was activated, but the display did not refresh. '
                    'Reopen the connection view to verify the target.',
                )
            return True

        try:
            accepted = self._create_journal.apply_receipt(
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
                'Linked upload mode is unconfirmed. Reconnect and verify the '
                'saved mode before attempting any upload.',
            )
            return
        # Retain the previous target even if the host cannot repaint.
        self._active_upload_target = previous_target
        self._selected_gallery = previous_gallery
        self._gallery_status.set(
            'Finish or resolve existing upload work and verify the original '
            'creation receipt before changing target.',
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
                'Linked upload permission changed; reconnect and verify the '
                'saved mode before uploading.',
            )
            return False
        if (not LINKING_UPLOAD_TARGET_READY
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
                'The gallery selection changed during verification. '
                'The previous upload target remains unchanged.',
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
                'Linked upload mode is unconfirmed. Reconnect and verify the '
                'saved mode before attempting any upload.',
            )
            return False
        self._active_upload_target = target
        self._selected_gallery = gallery
        self._gallery_status.set(
            'Upload gallery: ' + _gallery_label(gallery),
        )
        try:
            self._notify_target_changed()
        except Exception:
            self._gallery_status.set(
                'Upload mode was activated, but the display did not refresh. '
                'Reopen the connection view to verify the target.',
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
            if not LINKING_UPLOAD_TARGET_READY:
                self._gallery_status.set(
                    'Linked upload selection is temporarily unavailable. '
                    'The current connection remains selected.',
                )
                return
            if not self._check_upload_workspace():
                return
            if getattr(self, '_commit_linked_target', None) is None:
                self._gallery_status.set(
                    'The application cannot safely commit a linked upload mode.',
                )
                return
            if self._worker is not None and self._worker.is_alive():
                self._gallery_status.set(
                    'Wait for the previous gallery verification to finish.',
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
                'Verifying the administrator and selected gallery…',
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
                self._gallery_status.set('Could not start gallery verification.')
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

    def _open_browser(self) -> None:
        """Open or retry the exact browser approval page for a live handle.

        Returns:
            None: Reports the approval step or permits retry after browser failure.
        """
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
            self._status.set('Could not open the browser. Try Link gallery again.')
            if self._window is None:
                self._clear_handoff()
                self._cancel.set()
                self._owner = None
                if self._attempt is not None:
                    self._attempt.close()
            return
        self._status.set(self._text('linking_approve_browser'))

    def _open_devices(self) -> None:
        """Open the fixed gallery page for reviewing and revoking grants.

        Returns:
            None: Opens only the previously validated installation.
        """
        issuer = self._session_issuer or self._current_issuer
        if not issuer:
            self._status.set('Enter a valid gallery address first.')
            return
        try:
            opened = webbrowser.open(
                issuer + '/index.php?page=app_linking_devices',
            )
        except Exception:  # noqa: BLE001
            opened = False
        if not opened:
            self._status.set('Could not open the gallery in your browser.')

    def unlink(self, issuer: str | None = None) -> None:
        """Disconnect one selected live link and request server revocation.

        Args:
            issuer (str | None): Exact live address, or None for the current site.

        Returns:
            None: Starts one revocation or reports why the link cannot close.
                The site remains available in recent addresses.
        """
        site = self._live_link(self._session_issuer if issuer is None else issuer)
        if site is None:
            return
        session = site.session
        if self._worker is not None and self._worker.is_alive():
            self._status.set('Wait for the current gallery request to finish.')
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
        self._status.set('Removing the saved connection…')

        def cleanup_worker() -> None:
            """Remove the matching vault grant and revoke its latest credential.

            Returns:
                None: Queues local cleanup and any refresh revocation outcome.
            """
            try:
                if sys.platform == 'darwin':
                    delete_transient(site.issuer)
                binding = self._saved_offline_binding(site.issuer)
                record = None
                if binding is not None and binding.account_id == site.user_id:
                    store = self._native_offline_store()
                    if store is None:
                        raise LinkingError('Secure storage is unavailable.')
                    record = remove_saved_offline_link(
                        store, self._offline_binding_path(binding), binding,
                        capture_refresh=True,
                    )
            except Exception:  # noqa: BLE001
                self._events.put((owner, 'unlink_cleanup_error', None))
                return
            revoked = None
            if record is not None and binding is not None:
                try:
                    revoke_saved_refresh(binding, record)
                    revoked = True
                except (RuntimeError, OSError):
                    revoked = False
            self._events.put((owner, 'unlink_vault_cleared', (site, revoked)))

        self._worker = threading.Thread(
            target=cleanup_worker, name='PHPGalleryUnlinkVaultCleanup', daemon=True,
        )
        try:
            self._worker.start()
        except RuntimeError:
            self._worker = None
            self._status.set('Could not start secure unlink cleanup.')
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
        self._status.set('Unlinking gallery…')
        if revoked is not None:
            session.close()
            self._status.set(
                'Gallery unlinked.' if revoked else
                'Unlinked locally. Check Manage link to confirm server revocation.',
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
            self._status.set('Unlinked locally. Check Manage link to revoke access.')
        else:
            self._refresh(self._current_issuer)
            self._schedule_poll()
        if disconnects_upload:
            self._notify_target_changed()

    def _clear_handoff(self) -> None:
        """Forget the private request handle without claiming memory erasure.

        Returns:
            None: Clears the manager's reference to the handoff correlation handle.
        """
        self._handoff = None

    def _close_window(self) -> None:
        """Cancel this dialog's work and leave the shared session live.

        Returns:
            None: Closes the dialog while leaving a confirmed identity in memory.
        """
        self._cancel.set()
        self._cancel_gallery_search()
        self._detail_snapshot = None
        if self._create_dispatch is not None:
            self._create_dispatch.cancel()
        if self._gallery_cancel is not None:
            self._gallery_cancel.set()
        if self._attempt is not None:
            self._attempt.close()
            self._attempt = None
        self._clear_handoff()
        self._owner = None
        self._direct_owner = None
        if self._window is not None:
            parent = self._window.master
            self._window.destroy()
            self._window = None
            if isinstance(parent, tk.Toplevel) and parent.winfo_exists():
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
