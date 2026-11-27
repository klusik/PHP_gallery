# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/app_header.py
# Module Type: Native Application Header
# Purpose: Keep branding, the linked site and live controls in a compact header.
# Responsibilities:
#   - Share one row on wide windows and wrap measured groups on narrow windows.
#   - Preserve the version and live actions while hiding decorative text first.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Responsive native header geometry without account or transport policy."""

import tkinter as tk

from uploader.localization import gettext
from uploader.ui_layout import HEADER_GROUP_GAP, HEADER_ROW_GAP, HEADER_WORKSPACE_GAP
from tkinter import ttk


class CompactAppHeader(ttk.Frame):
    """Arrange identity, linking controls and live actions using measured widths."""

    def __init__(self, parent: tk.Misc, version: str) -> None:
        """Create stable hosts for the shell's existing controls.

        Args:
            parent (tk.Misc): The main window's outer themed surface.
            version (str): Current independent WinApp version shown at every size.
        Returns:
            None: Branding is ready; the host fills links and actions normally.
        """
        super().__init__(parent, style='App.TFrame')
        self._after_id: str | None = None
        self._closed = False
        self._mode: tuple[str, bool] | None = None
        self._widths: dict[tk.Misc, tuple[int, int]] = {}
        self.columnconfigure(1, weight=1)
        self.identity = ttk.Frame(self, style='App.TFrame')
        self.brand = ttk.Label(self.identity, text='PHP Gallery', style='Brand.TLabel')
        self.tagline = ttk.Label(
            self.identity, text=gettext('Your gallery on autopilot'), style='App.Eyebrow.TLabel',
        )
        self.version = ttk.Label(
            self.identity, text='· ' + version, style='App.Eyebrow.TLabel',
        )
        self.brand.grid(row=0, column=0, sticky='w')
        self.tagline.grid(row=0, column=1, sticky='w', padx=(HEADER_GROUP_GAP, 0))
        self.version.grid(row=0, column=2, sticky='w', padx=(HEADER_GROUP_GAP, 0))
        self.links = ttk.Frame(self, style='App.TFrame')
        self.links.columnconfigure(0, weight=1)
        self.actions = ttk.Frame(self, style='App.TFrame')
        self.identity.grid(row=0, column=0, sticky='w')
        self.links.grid(row=0, column=1, sticky='ew', padx=HEADER_GROUP_GAP)
        self.actions.grid(row=0, column=2, sticky='e')
        for group in (self, self.links, self.actions):
            group.bind('<Configure>', self._on_configure, add='+')
        self.bind('<Destroy>', self._on_destroy, add='+')

    def request_layout(self) -> None:
        """Coalesce live link/action changes into one idle geometry update.

        Returns:
            None: No polling or account operation is started by layout changes.
        """
        if not self._closed and self._after_id is None:
            self._after_id = self.after_idle(self._layout)

    def _on_configure(self, event: tk.Event) -> None:
        """Schedule reflow only when available or requested width changes.

        Args:
            event (tk.Event): Native geometry event from an owned header group.
        Returns:
            None: Height-only updates cannot cause a new layout pass.
        """
        widget = event.widget
        widths = (event.width, widget.winfo_reqwidth())
        if widths != self._widths.get(widget):
            self._widths[widget] = widths
            self.request_layout()

    def _layout(self) -> None:
        """Choose the smallest fitting row arrangement without width feedback.

        Returns:
            None: Live controls and version stay visible; only the decorative
                tagline yields before the link group moves onto another row.
        """
        self._after_id = None
        width = self.winfo_width()
        if self._closed or width <= 1:
            return
        compact = self.brand.winfo_reqwidth() + self.version.winfo_reqwidth() + HEADER_GROUP_GAP
        # Hidden labels still expose their natural request. Measure the full
        # identity directly so hiding the tagline cannot toggle the breakpoint.
        full = compact + self.tagline.winfo_reqwidth() + HEADER_GROUP_GAP
        links = self.links.winfo_reqwidth()
        actions = self.actions.winfo_reqwidth()
        inline_gap = HEADER_GROUP_GAP * 2
        if full + links + actions + inline_gap <= width:
            mode, tagline = 'inline', True
        elif compact + links + actions + inline_gap <= width:
            mode, tagline = 'inline', False
        elif compact + actions + HEADER_GROUP_GAP <= width:
            mode, tagline = 'links-below', full + actions + HEADER_GROUP_GAP <= width
        else:
            mode, tagline = 'stacked', full <= width
        if (mode, tagline) == self._mode:
            return
        self._mode = (mode, tagline)
        if tagline:
            self.tagline.grid()
        else:
            self.tagline.grid_remove()
        self.identity.grid_configure(columnspan=3 if mode == 'stacked' else 1)
        self.actions.grid_configure(
            row=1 if mode == 'stacked' else 0,
            column=0 if mode == 'stacked' else 2,
            columnspan=3 if mode == 'stacked' else 1,
            pady=(HEADER_ROW_GAP, 0) if mode == 'stacked' else 0,
        )
        self.links.grid_configure(
            row=0 if mode == 'inline' else 1 if mode == 'links-below' else 2,
            column=1 if mode == 'inline' else 0,
            columnspan=1 if mode == 'inline' else 3,
            padx=HEADER_GROUP_GAP if mode == 'inline' else 0,
            pady=0 if mode == 'inline' else (HEADER_ROW_GAP, 0),
        )

    def _on_destroy(self, event: tk.Event) -> None:
        """Release only this header's pending idle callback on destruction.

        Args:
            event (tk.Event): Native destruction event for this frame or a child.
        Returns:
            None: Other shell callbacks and linking traces retain their owners.
        """
        if event.widget is not self:
            return
        self._closed = True
        if self._after_id is not None:
            self.after_cancel(self._after_id)
            self._after_id = None
