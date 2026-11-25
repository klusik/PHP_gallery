# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/overlay.py
# Module Type: Tk Presentation
# Purpose: Keep contextual flight panels inside the existing application window.
# Responsibilities: Fit overlays to the host and preserve visible close/actions.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Window-contained panels with a fixed header and action footer."""

from typing import Callable
import tkinter as tk
from tkinter import ttk

from uploader.localization import gettext
from uploader.ui_theme import (
    Button, RaisedDialogSurface, hide_inline_tooltip, icon_button,
)
from uploader.flight_report_translations import tr

# Purpose: Leave a visible edge of the owning workspace around a panel.
# Type: int. Units: logical pixels. Scope: in-window panel placement.
# Consumers: OverlayPanel.fit. Rationale: 12 px separates the context from its host.
OVERLAY_MARGIN_PX = 12
# Purpose: Keep ordinary progress readable without spanning a desktop.
# Type: int. Units: logical pixels. Scope: default overlay width.
# Consumers: OverlayPanel. Rationale: 760 px fits translated two-column actions.
OVERLAY_WIDTH_PX = 760
# Purpose: Bound preferred panel height while leaving short windows usable.
# Type: int. Units: logical pixels. Scope: default overlay height.
# Consumers: OverlayPanel. Rationale: 580 px fits progress and recovery actions.
OVERLAY_HEIGHT_PX = 580
# Purpose: Share compact action rows without clipping translated labels.
# Type: int. Units: columns. Scope: contextual overlay action groups.
# Consumers: layout_actions. Rationale: at most two columns preserve hierarchy;
# wider labels span both columns without forcing short labels onto separate rows.
OVERLAY_ACTION_COLUMNS = 2


def layout_actions(
    frame: ttk.Frame, buttons: list[ttk.Button],
    maximum_columns: int = OVERLAY_ACTION_COLUMNS,
) -> None:
    """Fit complete action labels to the current footer width.

    Args:
        frame (ttk.Frame): Action group's fixed-footer container.
        buttons (list[ttk.Button]): Currently visible actions in display order.
        maximum_columns (int): Maximum compact column count, normally two.
    Returns:
        None: Gives long labels a full row while short labels share a row.
    """
    columns = max(1, maximum_columns)
    cell_width = max(1, frame.winfo_width() // columns)
    for column in range(maximum_columns):
        frame.columnconfigure(column, weight=1, uniform='overlay-actions')
    row, column = 0, 0
    for button in buttons:
        # Each button has four-pixel side spacing, hence eight pixels per cell.
        required = button.winfo_reqwidth() + 8
        span = min(columns, max(1, (required + cell_width - 1) // cell_width))
        if column + span > columns:
            row, column = row + 1, 0
        button.grid(row=row, column=column, columnspan=span,
                    sticky='ew', padx=4, pady=2)
        column += span
        if column == columns:
            row, column = row + 1, 0


class OverlayPanel(RaisedDialogSurface):
    """Present one contextual surface without creating an OS popup window."""

    def __init__(
        self, owner: tk.Misc, title: str, *,
        width: int = OVERLAY_WIDTH_PX, height: int = OVERLAY_HEIGHT_PX,
    ) -> None:
        """Build a title, flexible body and persistent action footer.

        Args:
            owner (tk.Misc): Invoking application widget; its toplevel owns placement.
            title (str): Localized title with a close action.
            width (int): Preferred width clamped to the host's visible width.
            height (int): Preferred height clamped to the host's visible height.
        Returns:
            None: Creates a hidden panel with no native toplevel or size grip.
        """
        self.owner = owner.winfo_toplevel()
        super().__init__(self.owner)
        self._preferred = (width, height)
        self._visible = False
        self._close: Callable[[], None] = self.withdraw
        self._previous_focus = self.owner.focus_get()
        self.columnconfigure(0, weight=1)
        self.rowconfigure(1, weight=1)
        header = ttk.Frame(self, padding=(16, 6))
        header.grid(row=0, column=0, sticky='ew')
        header.columnconfigure(0, weight=1)
        self._title = ttk.Label(header, text=title, style='Section.TLabel')
        self._title.grid(row=0, column=0, sticky='w')
        language = getattr(self.owner, '_flight_ui_language', None)
        close_label = tr(language.get() if isinstance(language, tk.StringVar) else 'en', 'hide')
        self.close_button = icon_button(header, 'close', lambda: self._close(), close_label)
        self.close_button.grid(row=0, column=1, sticky='e')
        self.body = ttk.Frame(self, padding=(16, 4))
        self.body.grid(row=1, column=0, sticky='nsew')
        self.footer = ttk.Frame(self, padding=(16, 4))
        self.footer.grid(row=2, column=0, sticky='ew')
        self._configure_binding = self.owner.bind('<Configure>', self._resize, add='+')
        self._escape_binding = self.owner.bind('<Escape>', self._escape, add='+')

    def title(self, value: str) -> None:
        """Update the in-window title.

        Args:
            value (str): Current localized panel title.
        Returns:
            None: Updates the visible heading.
        """
        self._title.configure(text=value)

    def protocol(self, name: str, callback: Callable[[], None]) -> None:
        """Assign the close action without changing host window protocols.

        Args:
            name (str): WM_DELETE_WINDOW for compatibility with existing owners.
            callback (Callable[[], None]): Existing presentation-only close action.
        Returns:
            None: The close icon and Escape share the same callback.
        """
        if name == 'WM_DELETE_WINDOW':
            self._close = callback

    def _resize(self, event: tk.Event) -> None:
        """Follow only host geometry changes.

        Args:
            event (tk.Event): Host or descendant Configure event.
        Returns:
            None: Fits a visible panel without a recursive geometry negotiation.
        """
        if event.widget is self.owner and self._visible:
            self.fit()

    def fit(self) -> None:
        """Clamp the panel rectangle to the existing main window.

        Returns:
            None: All panel edges remain inside the host; no user resizing is needed.
        """
        width, height = self._preferred
        self.place(
            relx=0.5, rely=0.5, anchor='center',
            width=max(1, min(width, self.owner.winfo_width() - 2 * OVERLAY_MARGIN_PX)),
            height=max(1, min(height, self.owner.winfo_height() - 2 * OVERLAY_MARGIN_PX)),
        )

    def deiconify(self) -> None:
        """Show this overlay inside its current host.

        Returns:
            None: Raises the panel without opening or resizing a native window.
        """
        hide_inline_tooltip(self.owner)
        self._visible = True
        self.fit()
        self.lift()
        self.close_button.focus_set()

    def withdraw(self) -> None:
        """Hide only the panel, retaining all background work and state.

        Returns:
            None: Restores the invoking focus when it still exists.
        """
        self._visible = False
        self.place_forget()
        if self._previous_focus is not None and self._previous_focus.winfo_exists():
            self._previous_focus.focus_set()

    def _escape(self, event: tk.Event) -> str | None:
        """Dismiss the top visible overlay on Escape.

        Args:
            event (tk.Event): Escape key event in the owning application.
        Returns:
            str | None: Break for the top visible overlay; None for other contexts.
        """
        panels = [child for child in self.owner.winfo_children()
                  if isinstance(child, OverlayPanel) and child._visible]
        if self._visible and panels and panels[-1] is self:
            self._close()
            return 'break'
        return None

    def destroy(self) -> None:
        """Remove panel bindings before releasing its native child widgets.

        Returns:
            None: Leaves host close/resize/key ownership intact.
        """
        self.owner.unbind('<Configure>', self._configure_binding)
        self.owner.unbind('<Escape>', self._escape_binding)
        self.withdraw()
        super().destroy()


def _message(
    title: str, message: str, parent: tk.Misc, question: bool,
) -> bool:
    """Present a readable in-window message using the existing consent flow.

    Args:
        title (str): Localized operation title.
        message (str): Complete existing explanation or failure text.
        parent (tk.Misc): Invoking application widget.
        question (bool): Whether an explicit affirmative answer is required.
    Returns:
        bool: True only after an affirmative action; close/Escape returns false.
    """
    panel = OverlayPanel(parent, title)
    accepted = tk.BooleanVar(parent, value=False)
    text = tk.Text(panel.body, wrap='word', relief='flat', borderwidth=0,
                   background=ttk.Style(parent).lookup('TFrame', 'background'),
                   foreground=ttk.Style(parent).lookup('TLabel', 'foreground'))
    text.insert('1.0', message)
    text.configure(state='disabled')
    scroll = ttk.Scrollbar(panel.body, command=text.yview)
    scroll.pack(side='right', fill='y')
    text.configure(yscrollcommand=scroll.set)
    text.pack(fill='both', expand=True)

    def confirm() -> None:
        """Record the existing affirmative consent and dismiss the message.

        Returns:
            None: Releases the wait only after recording an affirmative answer.
        """
        accepted.set(True)
        panel.destroy()

    # Existing flight preferences own the language; default labels remain English
    # for standalone message callers without that controller.
    selected_language = getattr(parent.winfo_toplevel(), '_flight_ui_language', None)
    language = selected_language.get() if isinstance(selected_language, tk.StringVar) else 'en'
    Button(panel.footer, text=tr(language, 'yes') if question else gettext('OK'),
               command=confirm, style='Accent.Compact.TButton').pack(side='right')
    if question:
        Button(panel.footer, text=tr(language, 'cancel'),
                   command=panel.destroy).pack(side='right', padx=8)
    panel.protocol('WM_DELETE_WINDOW', panel.destroy)
    panel.deiconify()
    previous_grab = parent.grab_current()
    panel.grab_set()
    parent.wait_window(panel)
    if previous_grab is not None and previous_grab.winfo_exists():
        previous_grab.grab_set()
    return accepted.get()


def askyesno(title: str, message: str, *, parent: tk.Misc) -> bool:
    """Request the existing explicit consent in a host-contained panel.

    Args:
        title (str): Existing localized question title.
        message (str): Existing action/safety explanation.
        parent (tk.Misc): Invoking application widget.
    Returns:
        bool: True on explicit consent; false on Cancel, close or Escape.
    """
    return _message(title, message, parent, True)


def showinfo(title: str, message: str, *, parent: tk.Misc) -> None:
    """Show a complete explanation in the existing main window.

    Args:
        title (str): Existing localized message title.
        message (str): Complete existing message text.
        parent (tk.Misc): Invoking application widget.
    Returns:
        None: Waits for dismissal without creating a popup toplevel.
    """
    _message(title, message, parent, False)


def showerror(title: str, message: str, *, parent: tk.Misc) -> None:
    """Keep failures visible until the user dismisses their explanation.

    Args:
        title (str): Existing localized failure title.
        message (str): Complete existing failure and recovery explanation.
        parent (tk.Misc): Invoking application widget.
    Returns:
        None: Preserves the error until explicit dismissal.
    """
    _message(title, message, parent, False)
