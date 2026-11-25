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
import json
from pathlib import Path
import sys
import tempfile
import time
import tkinter as tk
from tkinter import font as tkfont, ttk
from types import ModuleType, SimpleNamespace
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.ui_shell import EmbeddedWindow, initialize_shell
from uploader.app_header import CompactAppHeader
from uploader.ui_theme import Button, configure_theme
from test_ui_shell import SHELL_VISUAL_DIR, SHELL_VISUAL_SIZES

# Purpose: Make Settings scrollable independent of hosted display font metrics.
# Type: int. Units: fixture rows. Scope: real notebook switch journey.
# Consumers: ApplicationShellTests.test_switching_keeps_retained_navigation.
# Rationale: 48 entry rows exceed both test heights while keeping setup short.
SETTINGS_FIXTURE_ROWS = 48

# Purpose: Measure multiple retained transitions at both window widths.
# Type: int. Units: triplets of selections. Scope: one fixture width.
# Consumers: ApplicationShellTests.test_switching_keeps_retained_navigation.
# Rationale: 12 cycles exposes repeat scheduling while keeping CI bounded.
SETTINGS_SWITCH_CYCLES = 12


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
        width, height = MAIN.WATCHER_WINDOW_MINIMUM
        self.root.geometry(f"{width}x{height}")
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

    def test_switching_keeps_retained_navigation(self) -> None:
        """Drive real notebook switching with a populated, retained scroll page.

        Returns:
            None: Hosted software timings, retained controls and scroll offset
                demonstrate responsive work without panel rebuilding or pulse replay.
        """
        configure_theme(self.root)
        app = self.app
        del app.select_tab  # Use the actual WatcherApp route for this journey.
        app.notebook = ttk.Notebook(self.root, style='App.TNotebook')
        app.notebook.pack(fill='both', expand=True)
        pages = {
            'photos': ttk.Frame(app.notebook),
            'watch': ttk.Frame(app.notebook),
            'settings': MAIN.ScrollableFrame(app.notebook),
        }
        settings = pages['settings']
        for row in range(SETTINGS_FIXTURE_ROWS):
            ttk.Entry(settings.content).grid(row=row, column=0)
        for key, page in pages.items():
            app.notebook.add(page, text=key)
        app.tab_indices = {key: app.notebook.index(page) for key, page in pages.items()}
        app.navigation_buttons = {key: Button(self.root) for key in pages}
        app._watcher_recording_active = True
        app._render_watcher_recording = mock.Mock()
        app._import_presentation_after = 0.0
        app._flush_import_presentation = mock.Mock()
        app._gallery_link_refresh = mock.Mock()
        app.notebook.bind('<<NotebookTabChanged>>', app.refresh_navigation)
        samples: dict[int, list[float]] = {}
        for width, height in SHELL_VISUAL_SIZES:
            self.root.geometry(f'{width}x{height}')
            app.select_tab('settings')
            self.root.update()
            settings.canvas.yview_moveto(0.5)  # Midpoint proves the page retains scroll.
            self.root.update()
            offset = settings.canvas.yview()[0]
            self.assertGreater(offset, 0)
            samples[width] = []
            for page in ('watch', 'photos', 'settings') * SETTINGS_SWITCH_CYCLES:
                started = time.perf_counter()
                app.select_tab(page)
                self.root.update_idletasks()
                self.assertTrue(pages[page].winfo_viewable(), page)
                samples[width].append((time.perf_counter() - started) * 1000)
                self.root.update()
            self.assertAlmostEqual(offset, settings.canvas.yview()[0])

        with (
            mock.patch.object(app.navigation_buttons['settings'], 'configure') as button,
            mock.patch.object(settings.canvas, 'configure') as canvas,
            mock.patch.object(settings.canvas, 'itemconfigure') as item,
            mock.patch.object(settings.scrollbar, 'set') as scrollbar,
        ):
            app._gallery_link_refresh.reset_mock()
            app.refresh_navigation()
            settings._sync_scrollregion()
            settings._sync_width(SimpleNamespace(width=settings.canvas.winfo_width()))
            settings._set_scrollbar(*settings._scrollbar_range)
            for operation in (button, canvas, item, scrollbar, app._gallery_link_refresh):
                operation.assert_not_called()
        app._render_watcher_recording.assert_not_called()
        app._flush_import_presentation.assert_not_called()
        if sys.platform == 'win32':
            SHELL_VISUAL_DIR.mkdir(parents=True, exist_ok=True)
            evidence = {
                'tk_patchlevel': self.root.tk.call('info', 'patchlevel'),
                'body_font': tkfont.nametofont('TkDefaultFont', root=self.root).actual(),
                'selection_to_mapped_idle_ms': samples,
            }
            (SHELL_VISUAL_DIR / 'panel-switches.json').write_text(
                json.dumps(evidence, indent=2), encoding='utf-8',
            )

    def test_header_ignores_height_only_updates(self) -> None:
        """Keep the compact header breakpoint stable when only its height changes.

        Returns:
            None: Repeated geometry observations with the same widths produce
                no scheduling, while changed width admits a new layout.
        """
        configure_theme(self.root)
        header = CompactAppHeader(self.root, MAIN.APP_VERSION)
        header.pack(fill='x')
        self.root.update()
        width = header.winfo_width()
        event = SimpleNamespace(widget=header, width=width)
        header._on_configure(event)
        with mock.patch.object(header, 'request_layout') as layout:
            header._on_configure(event)
            layout.assert_not_called()
            header._on_configure(SimpleNamespace(widget=header, width=width - 1))
            layout.assert_called_once_with()

    def test_watcher_lifecycle_keeps_the_header_action_geometry(self) -> None:
        """Run the real header projection through Watcher start and finish.

        Returns:
            None: The Stop action, metrics and link controls retain their
                geometry through lifecycle and Gallery/Watcher changes.
        """
        configure_theme(self.root)
        app = self.app
        outer = ttk.Frame(self.root)
        outer.pack(fill='both', expand=True)
        outer.columnconfigure(1, weight=1)
        app.flights = SimpleNamespace(ui_language=tk.StringVar(self.root, 'en'))
        app.linking_manager = SimpleNamespace(
            linked_address=tk.StringVar(self.root, 'https://gallery.example.test'),
            status=tk.StringVar(self.root, ''),
        )
        app.gallery_title_var = tk.StringVar(self.root, 'Synthetic photos')
        app.watcher_activity_var = tk.StringVar(self.root, 'Watch: stopped')
        app.watcher_header_count_var = tk.StringVar(self.root, '–')
        app.watcher_header_time_var = tk.StringVar(self.root, '–:–')
        app._watcher_queued_count = None
        app.worker = None
        app.notebook = mock.Mock()
        app.notebook.index.return_value = 0
        app.tab_indices = {'photos': 0, 'watch': 1}
        header = app.build_app_header(outer)
        link_bar = header.links.winfo_children()[0]
        address = next(widget for widget in link_bar.winfo_children()
                       if isinstance(widget, ttk.Label))
        stop = app._watcher_header_stop_button
        dummy_worker = SimpleNamespace(
            is_alive=lambda: True, stop_event=mock.Mock(is_set=lambda: False),
            drain_only=False, drain_event=mock.Mock(is_set=lambda: False),
            _update_pause_requested=lambda: False,
        )
        for width, height in SHELL_VISUAL_SIZES:
            self.root.geometry(f'{width}x{height}')
            self.root.update()
            expected_width = header.actions.winfo_reqwidth()
            expected_height = header.winfo_height()
            for phase in ('starting', 'running', 'finishing', 'stopped'):
                with self.subTest(width=width, phase=phase):
                    app.watcher_activity_var.set(f'Watch: {phase}')
                    app.worker = dummy_worker if phase == 'running' else None
                    app._watcher_queued_count = 12 if app.worker is not None else None
                    app._watcher_last_scan_at = time.time() if app.worker is not None else None
                    for bright in (False, True):
                        app._watcher_recording_pulse = bright
                        app.refresh_watcher_header()
                        self.root.update()
                        self.assertEqual(expected_width, header.actions.winfo_reqwidth())
                        self.assertEqual(expected_height, header.winfo_height())
                        self.assertEqual(phase == 'running', bool(stop.winfo_ismapped()))
            address_origin = (address.winfo_rootx(), address.winfo_rooty())
            expected_link_width = link_bar.winfo_reqwidth()
            with mock.patch.object(address, 'configure', wraps=address.configure) as display:
                for selected in (1, 0):
                    app.notebook.index.return_value = selected
                    app._gallery_link_refresh()
                    self.root.update()
                    self.assertEqual(expected_link_width, link_bar.winfo_reqwidth())
                    self.assertEqual(expected_height, header.winfo_height())
                    self.assertEqual(address_origin, (address.winfo_rootx(), address.winfo_rooty()))
                display.assert_not_called()

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
