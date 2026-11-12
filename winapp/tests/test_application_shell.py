# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_application_shell.py
# Module Type: Windows Interface Regression Tests
# Purpose: Verify application menu ownership and reviewable support diagnostics.
# Responsibilities: Guard background shortcuts and preserve preview/copy/save
#   identity through actual Tk controls using synthetic report text only.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise application commands without connecting or starting any workers."""

import importlib.machinery
import importlib.util
from pathlib import Path
import sys
import tempfile
import tkinter as tk
from types import ModuleType, SimpleNamespace
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.ui_shell import EmbeddedWindow, initialize_shell


def load_launcher() -> ModuleType:
    """Import the compatibility launcher without entering its application loop.

    Returns:
        ModuleType: Source module exposing WatcherApp and presentation owners.
    """
    loader = importlib.machinery.SourceFileLoader(
        "application_shell_fixture", str(WINAPP_DIR / "gallery_watch_upload.pyw"),
    )
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        raise RuntimeError("Missing launcher import specification")
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


MAIN = load_launcher()


def descendants(widget: tk.Misc) -> list[tk.Misc]:
    """Collect actual descendants for behavioral control assertions.

    Args:
        widget (tk.Misc): Owning surface whose visible controls are examined.
    Returns:
        list[tk.Misc]: Descendant widgets including recursively nested controls.
    """
    output = []
    pending = list(widget.winfo_children())
    while pending:
        item = pending.pop()
        output.append(item)
        pending.extend(item.winfo_children())
    return output


class MenuCommandTests(unittest.TestCase):
    """Keep menu shortcuts from bypassing an active confirmation or panel."""

    def test_panel_and_shutdown_block_shortcuts(self) -> None:
        """Admit a command only while the root owns normal interaction.

        Returns:
            None: Active panels and application shutdown cannot dispatch work.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.root = mock.Mock()
        app.exiting = False
        command = mock.Mock()
        with mock.patch.object(MAIN, "active_panel", return_value=object()):
            self.assertEqual(app.menu_command(command), "break")
        command.assert_not_called()
        with mock.patch.object(MAIN, "active_panel", return_value=None):
            app.menu_command(command)
            command.assert_called_once_with()
            app.exiting = True
            app.menu_command(command)
        command.assert_called_once_with()


class ApplicationShellTests(unittest.TestCase):
    """Exercise real menu and support controls with an isolated Tk root."""

    def setUp(self) -> None:
        """Create a worker-free root or report an unavailable Tk display.

        Returns:
            None: Initializes only the presentation owner with synthetic reports.
        """
        try:
            self.root = tk.Tk()
        except tk.TclError as exc:
            self.skipTest(str(exc))
        initialize_shell(self.root)
        self.app = object.__new__(MAIN.WatcherApp)
        self.app.root = self.root
        self.app.exiting = False
        self.app.updates = SimpleNamespace(check=mock.Mock())
        self.app.select_tab = mock.Mock()
        self.app.support_report = mock.Mock(return_value="Synthetic report\nphase=ready")
        self.root.update()

    def tearDown(self) -> None:
        """Release the root and every owned presentation surface.

        Returns:
            None: Destroys menus and panels without touching application state.
        """
        if hasattr(self, "root"):
            self.root.destroy()

    def test_menu_routes_to_existing_view_owner(self) -> None:
        """Expose the conventional menu labels and preserve navigation ownership.

        Returns:
            None: Selecting Settings calls the existing tab command exactly once.
        """
        self.app.build_menu()
        menu = self.app.menu_bar
        self.assertEqual(
            [menu.entrycget(index, "label") for index in range(menu.index("end") + 1)],
            ["File", "Edit", "View", "Tools", "Help"],
        )
        view = self.root.nametowidget(menu.entrycget("View", "menu"))
        view.invoke("Settings")
        self.app.select_tab.assert_called_once_with("settings")
        panel = EmbeddedWindow(self.root)
        view.invoke("Photos")
        self.app.select_tab.assert_called_once_with("settings")
        panel.destroy()

    def test_preview_copy_and_save_use_the_displayed_snapshot(self) -> None:
        """Preserve the reviewed report until the user explicitly refreshes it.

        Returns:
            None: Clipboard and file match the preview; no regenerated data leaks in.
        """
        self.app.open_support_report()
        panel = self.app._support_panel
        controls = descendants(panel)
        buttons = {
            str(widget.cget("text")): widget
            for widget in controls if isinstance(widget, MAIN.ttk.Button)
        }
        preview = next(widget for widget in controls if isinstance(widget, tk.Text))
        expected = preview.get("1.0", "end-1c")
        self.assertEqual(expected, "Synthetic report\nphase=ready")
        self.assertEqual(preview.cget("state"), "disabled")
        self.app.support_report.return_value = "New unreviewed report"
        buttons["Copy report"].invoke()
        self.assertEqual(self.root.clipboard_get(), expected)
        with tempfile.TemporaryDirectory() as directory:
            destination = Path(directory) / "report.txt"
            with mock.patch.object(MAIN.filedialog, "asksaveasfilename", return_value=str(destination)):
                buttons["Save report…"].invoke()
            self.assertEqual(destination.read_text(encoding="utf-8"), expected)
        self.assertEqual(self.app.support_report.call_count, 1)
        self.assertFalse(any(isinstance(widget, tk.Toplevel) for widget in descendants(self.root)))
        buttons["Refresh report"].invoke()
        self.assertEqual(preview.get("1.0", "end-1c"), "New unreviewed report")
