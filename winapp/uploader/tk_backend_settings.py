# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/tk_backend_settings.py
# Module Type: Tkinter settings view
# Purpose: Choose and restart into the requested desktop backend.
# Responsibilities: Present the effective frontend, persist user intent and
#   invoke the host's ordinary safe restart.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Render backend preferences with the existing Tk component library."""

from pathlib import Path
from tkinter import Event, Misc, StringVar, ttk
from typing import Callable

from .gui_backend import GUI_BACKENDS, LEGACY_GUI_BACKEND, GuiBackend
from .gui_backend import load_gui_backend, save_gui_backend
from .gui_backend_labels import backend_label, backend_status
from .localization import gettext
from .ui_shell import messagebox
from .ui_theme import Button


class TkBackendSettings(ttk.Frame):
    """Keep toolkit selection separate from the uploader's settings draft."""

    def __init__(
        self, parent: Misc, profile: Path, restart: Callable[[], None],
        wrap: int, qt_available: bool,
    ) -> None:
        """Build the backend picker inside the existing interface section.

        Args:
            parent (Misc): Settings card owning the controls and modal focus.
            profile (Path): Per-user owner of backend preference persistence.
            restart (Callable[[], None]): Existing coordinated restart action.
            wrap (int): Available label width in Tk logical pixels.
            qt_available (bool): Whether this build can launch its optional Qt
                dependency set.
        Returns:
            None: Presents the saved and effective choices.
        """
        super().__init__(parent, style="Card.TFrame")
        self.profile = profile
        self.restart = restart
        self.qt_available = qt_available
        self.requested = load_gui_backend(profile)
        ttk.Label(self, text=gettext("GUI backend")).grid(row=0, column=0, sticky="w")
        self.labels = tuple(backend_label(backend) for backend in GUI_BACKENDS)
        self.selection = StringVar(self)
        self.picker = ttk.Combobox(
            self, textvariable=self.selection, values=self.labels,
            state="readonly" if qt_available else "disabled",
        )
        self.picker.grid(row=0, column=1, sticky="ew", padx=(8, 0))
        self.picker.bind("<<ComboboxSelected>>", self.choose)
        self.summary = StringVar(self)
        ttk.Label(
            self, textvariable=self.summary, style="Muted.TLabel", wraplength=wrap,
        ).grid(row=1, column=0, columnspan=2, sticky="w", pady=(4, 0))
        self.pending = ttk.Frame(self, style="Card.TFrame")
        self.pending.grid(row=2, column=0, columnspan=2, sticky="w", pady=(4, 0))
        self.restart_button = Button(
            self.pending, text=gettext("Restart WinApp"), command=restart,
        )
        self.restart_button.pack(side="left")
        Button(
            self.pending, text=gettext("Cancel pending change"),
            command=lambda: self.save(LEGACY_GUI_BACKEND),
        ).pack(side="left", padx=(8, 0))
        self.columnconfigure(1, weight=1)
        self.refresh()

    def refresh(self) -> None:
        """Synchronize the chooser, status and pending-restart controls.

        Returns:
            None: Displays the persisted selection in a Legacy Tk-only environment.
        """
        self.selection.set(backend_label(self.requested))
        text = backend_status(LEGACY_GUI_BACKEND, self.requested)
        if not self.qt_available:
            text += "\n" + gettext("Qt is available in the source launcher with the optional Qt requirements.")
        self.summary.set(text)
        self.restart_button.configure(state="normal" if self.qt_available else "disabled")
        if self.requested != LEGACY_GUI_BACKEND:
            self.pending.grid()
        else:
            self.pending.grid_remove()

    def save(self, backend: GuiBackend) -> bool:
        """Save a selection and restore the prior view if publication fails.

        Args:
            backend (GuiBackend): Explicitly requested toolkit or rollback.
        Returns:
            bool: True when the explicit preference was saved.
        """
        try:
            save_gui_backend(self.profile, backend)
        except OSError:
            self.refresh()
            messagebox.showerror(
                gettext("Settings"), gettext("Could not save the GUI backend."),
                parent=self.winfo_toplevel(),
            )
            return False
        self.requested = backend
        self.refresh()
        return True

    def restart_with_qt(self) -> None:
        """Persist Qt as the explicit Photos action and request safe restart.

        Returns:
            None: Restarts only when Qt is available and its preference was saved.
        """
        if self.qt_available and self.save("pyside6"):
            self.restart()

    def choose(self, _event: Event) -> None:
        """Accept one valid combobox selection and offer immediate restart.

        Args:
            _event (Event): Tk selection notification from this chooser.
        Returns:
            None: Retains the pending choice when the operator picks Later.
        """
        index = self.picker.current()
        if not self.qt_available or index < 0:
            return
        backend = GUI_BACKENDS[index]
        if (self.save(backend) and backend != LEGACY_GUI_BACKEND
                and messagebox.askyesno(
                    gettext("Restart WinApp"),
                    gettext("The GUI backend was saved. Restart WinApp now to apply it?"),
                    parent=self.winfo_toplevel(),
                    yes_label=gettext("Restart now"),
                    no_label=gettext("Later"),
                    yes_shortcut="<Control-Return>",
                )):
            self.restart()
