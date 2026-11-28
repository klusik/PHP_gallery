# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/ui/picker_ui.py
# Module Type: Windows Client Interface Components
# Purpose: Render focused gallery and address pickers for link dialogs.
# Responsibilities:
#   - Keep gallery search and address selection controls reusable.
#   - Provide a keyboard-accessible scroll container for compact dialogs.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Reusable Tk controls owned by the gallery linking interface."""

import tkinter as tk
from tkinter import ttk
from typing import Callable

from .. import LinkedGallery
from ...ui_layout import LINKING_ADDRESS_VISIBLE_ROWS
from ...ui_shell import EmbeddedWindow
from ...ui_theme import icon_button

# Purpose: Bound the gallery text shown in one picker row.
# Type: int. Units: characters. Scope: one linked gallery picker label.
# Consumers: _gallery_label. Rationale: Prevent server titles from stretching the dialog.
LINKING_GALLERY_LABEL_MAX_CHARS = 80

# Purpose: Bound initial gallery suggestions in the link picker.
# Type: int. Units: rows. Scope: one gallery search result page.
# Consumers: _GallerySuggestions. Rationale: Keep the picker compact while retaining choice.
LINKING_PICKER_VISIBLE_ROWS = 6

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

    def __init__(self, parent: EmbeddedWindow) -> None:
        """Create a vertical viewport with a visible themed scrollbar.

        Args:
            parent (EmbeddedWindow): Linking panel owning this scroll container.

        Returns:
            None: Installs the canvas and its width-matched content frame.
        """
        super().__init__(parent, style='Card.TFrame')
        # EmbeddedWindow is a themed frame. The frame's style owns its color;
        # looking up a classic Tk widget option aborts construction after the header.
        card_background = ttk.Style(self).lookup('Card.TFrame', 'background')
        self.canvas = tk.Canvas(
            self, highlightthickness=0, borderwidth=0,
            background=card_background,
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
