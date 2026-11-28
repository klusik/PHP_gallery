# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/ui/dialog.py
# Module Type: Windows Client Interface
# Purpose: Own the linking dialog window construction and responsive layout.
# Responsibilities:
#   - Build the verified gallery linking window from the shared Tk components.
#   - Reflow existing actions and labels when the dialog width changes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Window construction and responsive layout for gallery linking."""

import tkinter as tk
from tkinter import ttk

from uploader.localization import gettext
from .. import linking_issuer
from .picker_ui import (
    LINKING_GALLERY_LABEL_MAX_CHARS, LINKING_PICKER_VISIBLE_ROWS,
    _GallerySuggestions, _LinkAddressChoices,
    _LinkingScrollFrame, _gallery_label,
)
from ...ui_layout import LINKING_ADDRESS_VISIBLE_ROWS
from ...ui_shell import EmbeddedWindow
from ...ui_theme import Button, GlassCard, icon_button

def _visibility_labels() -> dict[str, str]:
    """Map editable gallery visibility codes to translated choices.

    Returns:
        dict[str, str]: Server visibility codes and their visible captions.
    """
    return {
        "public": gettext("public"),
        "private": gettext("private"),
        "unpublished": gettext("unpublished"),
    }


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

# Purpose: Keep the gallery picker and its compact actions in view.
# Type: int. Units: Tk pixels. Scope: minimum gallery picker width.
# Consumers: LinkingManager.open. Rationale: 320 fits the selector and action row.
LINKING_PICKER_MIN_WIDTH = 320

# Purpose: Keep suggestions visible without making the picker a large window.
# Type: int. Units: rows. Scope: one native gallery suggestion list.
# Consumers: _GallerySuggestions. Rationale: six leaves the actions visible
# at narrow sizes and the list can scroll through the entire returned page.

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

class LinkingDialogMixin:
    """Build the dialog window and maintain its responsive layout."""

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
            self._status.set(gettext('Wait for the current gallery request to finish.'))
            return
        if not manage and site is None:
            self._status.set(gettext('Link the gallery to load its gallery list.'))
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
        window = EmbeddedWindow(parent)
        self._window = window
        title = gettext('Manage link') if manage else gettext('Choose gallery')
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
            card, text=issuer or gettext('Enter the address of your gallery.'),
            style='Muted.TLabel',
        )
        issuer_label.grid(row=1, column=0, sticky='ew', pady=(0, 6))
        explanation = ttk.Label(
            card,
            text=(gettext(
                "Choose permissions for this connection. Optional secure storage "
                "requires separate browser consent and permits renewal for up to "
                "30 days, with a seven-day inactivity limit. The saved API key "
                "remains available; server grants may remain until revoked."
            )),
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
            text=gettext('Allow listing galleries'),
        )
        self._upload_check = ttk.Checkbutton(
            self._scope_actions, variable=self._upload_var, command=self._scope_changed,
            text=gettext('Allow gallery uploads'),
        )
        self._read_check = ttk.Checkbutton(
            self._scope_actions, variable=self._read_var, command=self._scope_changed,
            text=gettext('Read gallery details'),
        )
        self._create_check = ttk.Checkbutton(
            self._scope_actions, variable=self._create_var,
            command=self._scope_changed, text=gettext('Allow creating galleries'),
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
            command=self._scope_changed, text=self._text('media_consent'),
        )
        self._offline_var = tk.BooleanVar(value=False)
        self._offline_check = ttk.Checkbutton(
            self._scope_actions, variable=self._offline_var,
            text=gettext('Remember securely'),
        )
        browse_explanation = ttk.Label(
            card, text=gettext('These permissions include private and unpublished galleries.'),
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
        self._start_button = Button(
            self._link_actions, text=self._text('linking_start'),
            command=lambda: self._start(issuer), style='Compact.TButton',
        )
        self._browser_button = Button(
            self._link_actions, text=self._text('linking_open_browser'),
            command=self._open_browser, style='Compact.TButton',
        )
        self._revoke_button = Button(
            self._link_actions, text=self._text('linking_disconnect_website'),
            command=self.unlink,
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
        self._gallery_select_button = Button(
            toolbar, text=self._text('linking_select_gallery'),
            command=self._select_gallery, style='Accent.Compact.TButton',
        )
        self._recovery_button = Button(
            self._gallery_actions, text=gettext('Check uncertain upload'),
            command=self._check_uncertain_upload, style='Compact.TButton',
        )
        self._review_button = Button(
            self._gallery_actions, text=self._text('linking_review_title'),
            command=self._review_uncertain_upload, style='Compact.TButton',
        )
        self._detail_button = Button(
            self._gallery_actions, text=gettext('Read selected details'),
            command=self._read_detail, style='Compact.TButton',
        )
        self._visibility_choice = ttk.Combobox(
            self._gallery_actions, state='readonly', width=15,
            values=tuple(_visibility_labels().values()),
        )
        self._visibility_button = Button(
            self._gallery_actions, text=self._text('linking_visibility_action'),
            command=self._change_visibility, style='Compact.TButton',
        )
        self._create_button = Button(
            self._gallery_actions, text=gettext('Create gallery'), command=self._create_gallery,
            style='Compact.TButton',
        )
        self._create_status_button = Button(
            self._gallery_actions, text=gettext('Check create status'),
            command=self._reconcile_gallery_create, style='Compact.TButton',
        )
        self._create_resume_button = Button(
            self._gallery_actions, text=gettext('Resume original creation'),
            command=self._resume_gallery_create, style='Compact.TButton',
        )
        self._create_abandon_button = Button(
            self._gallery_actions, text=gettext('Close unresolved creation'),
            command=self._abandon_gallery_create, style='Compact.TButton',
        )
        self._use_created_button = Button(
            self._gallery_actions, text=self._text('linking_use_created'),
            command=self._use_created_gallery, style='Compact.TButton',
        )
        self._ack_created_button = Button(
            self._gallery_actions, text=gettext('Dismiss result'),
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
        self._manage_button = Button(
            self._footer_actions, text=gettext('Manage gallery links'),
            command=self._open_devices, style='Compact.TButton',
        )
        self._restore_button = Button(
            self._footer_actions, text=gettext('Reconnect saved link'),
            command=self._restore_offline, style='Compact.TButton',
        )
        self._discard_button = Button(
            self._footer_actions, text=gettext('Forget saved link'),
            command=self._discard_offline, style='Compact.TButton',
        )
        self._close_button = Button(
            self._footer_actions, text=gettext('Close'), command=self._close_window,
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
