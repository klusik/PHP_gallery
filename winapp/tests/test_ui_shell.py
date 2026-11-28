# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_ui_shell.py
# Module Type: Windows Tk Regression Test
# Purpose: Verify embedded panel ownership and modal prompt outcomes.
# Responsibilities: Exercise stacking, focus restoration, prompts and flight
#   saved-report actions on the shared embedded host.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Regression coverage for the root-hosted application panel stack."""

import os
import sys
import tempfile
import tkinter as tk
from tkinter import ttk
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock
from PIL import ImageGrab

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

import uploader.flight_ui as flight_ui

from uploader.linking import LinkedGallery, LinkedGalleryPage, LinkingSession
from uploader.linking.ui import LinkingManager
from uploader.ui_shell import (
    EmbeddedWindow, active_panel, initialize_shell, messagebox, simpledialog,
)
from uploader import ui_theme
from uploader.ui_theme import Button, DialogSurface, configure_theme
from uploader.update_ui import UpdateController

# Purpose: Release synchronous prompts if a regression loses their response.
# Type: int. Units: milliseconds. Scope: one isolated shell test.
# Consumers: EmbeddedShellTests.setUp. Rationale: Five seconds permits native
# runner scheduling while ensuring a failed Escape cannot hang the whole suite.
SHELL_TEST_DEADLINE_MS = 5000

# Purpose: Wait for native layout/focus without entering nested update loops.
# Type: int. Units: milliseconds. Scope: one scheduled keyboard interaction.
# Consumers: EmbeddedShellTests.press_entry_key. Rationale: 20 ms yields to Tk
# mapping events while the unchanged test watchdog retains its hard deadline.
SHELL_KEY_POLL_MS = 20

# Purpose: Exercise prompts at the supported minimum application dimensions.
# Type: str. Units: Tk geometry pixels. Scope: one shell regression root.
# Consumers: EmbeddedShellTests.setUp. Rationale: 840x500 matches the launcher
# minimum; Tk's tiny default root can leave the entry unmapped below the text.
SHELL_TEST_GEOMETRY = '840x500'

# Purpose: Exercise both a typical application width and its compact minimum.
# Type: tuple[tuple[int, int], ...]. Units: Tk pixels. Scope: modal visual cases.
# Consumers: EmbeddedShellTests. Rationale: 1024 by 640 fits the hosted desktop;
# 840 by 500 exercises the launcher minimum and its footer.
SHELL_VISUAL_SIZES = ((1024, 640), (840, 500))

# Purpose: Retain hosted native visual evidence with the central audit reports.
# Type: Path. Units: repository-relative directory. Scope: Windows shell fixture.
# Consumers: EmbeddedShellTests.capture_root.
# Rationale: Use the existing workflow's uploaded evidence tree.
SHELL_VISUAL_DIR = WINAPP_DIR.parent / 'cache' / 'test-audit' / 'winapp-shell'


class EmbeddedShellTests(unittest.TestCase):
    """Exercise real Tk panel widgets without creating secondary windows."""

    def setUp(self) -> None:
        """Create a root or skip when the runner has no graphical display.

        Returns:
            None: Creates the root and initializes the panel host.
        """
        try:
            self.root = tk.Tk()
        except tk.TclError as exc:
            self.skipTest(str(exc))
        self.root.geometry(SHELL_TEST_GEOMETRY)
        initialize_shell(self.root)
        self.root.update()
        self.prompt_timed_out = False
        self.callback_errors = []
        self.root.report_callback_exception = lambda *_args: self.callback_errors.append(str(_args[1]))
        self.key_after_id = None
        self.key_observation = 'no keyboard request'
        self.deadline = self.root.after(SHELL_TEST_DEADLINE_MS, self.expire_prompt)

    def expire_prompt(self) -> None:
        """Release a lost dialog result while retaining a failing test outcome.

        Returns:
            None: Marks the failure and destroys any currently waiting panel.
        """
        self.prompt_timed_out = True
        panel = active_panel(self.root)
        if panel is not None:
            panel.destroy()

    def tearDown(self) -> None:
        """Destroy the test root and its owned panel widgets.

        Returns:
            None: Releases the Tk interpreter after each test.
        """
        if hasattr(self, 'root'):
            self.root.after_cancel(self.deadline)
            if self.key_after_id is not None:
                self.root.after_cancel(self.key_after_id)
            # Break the fixture callback's cycle with this test case so the
            # Tcl interpreter can be released on its owning thread.
            del self.root.report_callback_exception
            self.root.destroy()
            del self.root
            self.assertEqual(
                (self.prompt_timed_out, self.callback_errors), (False, []),
                'Dialog deadline or Tk callback failure: ' + self.key_observation,
            )

    def test_stack_and_modal_grab_restore(self) -> None:
        """Restore the parent panel after closing a nested grabbed panel.

        Returns:
            None: Verifies stacking, grab restoration and root-only ownership.
        """
        first = EmbeddedWindow(self.root)
        first.grab_set()
        second = EmbeddedWindow(first)
        self.assertIs(active_panel(self.root), second)
        self.assertIs(second.grab_current(), second)
        self.assertIs(second.winfo_toplevel(), self.root)
        second.destroy()
        self.assertIs(active_panel(self.root), first)
        self.assertIs(first.grab_current(), first)
        first.destroy()
        self.assertIsNone(active_panel(self.root))

    def test_linked_gallery_panels_render_themed_body(self) -> None:
        """Map the gallery picker and link-management controls on the embedded host.

        Returns:
            None: Verifies both native bodies and a visible, unselected gallery row.
        """
        issuer = 'https://gallery.example.test'
        with (
            mock.patch('uploader.linking.ui.manager.load_recent_gallery_addresses', return_value=()),
            mock.patch.object(LinkingManager, '_native_offline_store', return_value=None),
        ):
            manager = LinkingManager(self.root)
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_list_galleries.return_value = True
            session.can_create_gallery.return_value = False
            session.can_read_gallery_detail.return_value = False
            session.can_change_gallery_visibility.return_value = False
            session.can_upload.return_value = False
            manager._session = session
            manager._session_issuer = issuer
            manager._session_user_id = 1  # The approved synthetic Admin.
            gallery = LinkedGallery(7, None, 'Fixture gallery')
            manager._gallery_page = LinkedGalleryPage((gallery,), None)
            try:
                manager.open(self.root, issuer)
                self.root.update()
                self.assertIs(active_panel(self.root), manager._window)
                choice = manager._gallery_choice
                self.assertTrue(choice.entry.winfo_viewable())
                self.assertTrue(choice.results.winfo_viewable())
                self.assertEqual(('0',), choice.results.get_children())
                self.assertIn('Fixture gallery', choice.results.item('0', 'text'))
                self.assertFalse(choice.results.selection())

                manager._close_window()
                manager.open(self.root, issuer, manage=True)
                self.root.update()
                self.assertIs(active_panel(self.root), manager._window)
                canvas = manager._scroll.canvas
                self.assertTrue(canvas.winfo_viewable())
                background = ttk.Style(canvas).lookup('Card.TFrame', 'background')
                self.assertEqual(canvas.cget('background'), background)
                self.assertTrue(manager._browse_check.winfo_viewable())
            finally:
                manager.close()

    def capture_root(
        self, name: str, sample: tuple[int, int] = (8, 8),
    ) -> tuple[int, int, int]:
        """Retain a screenshot and sample its unobstructed fixture background.

        Args:
            name (str): Windows evidence image stem.
            sample (tuple[int, int]): Client pixel to measure, defaulting to a
                point just inside its upper-left corner.
        Returns:
            tuple[int, int, int]: Captured RGB sample.
        """
        self.root.update()
        x, y = self.root.winfo_rootx(), self.root.winfo_rooty()
        bounds = (x, y, x + self.root.winfo_width(), y + self.root.winfo_height())
        SHELL_VISUAL_DIR.mkdir(parents=True, exist_ok=True)
        with ImageGrab.grab(bbox=bounds).convert('RGB') as image:
            image.save(SHELL_VISUAL_DIR / f'{name}.png')
            return image.getpixel(sample)

    def test_centered_panel_keeps_margins_without_compositing(self) -> None:
        """Keep the centered panel usable on the portable noncomposited path.

        Returns:
            None: A resize keeps all edges inside the root and closing restores
                the invoking focus without introducing another native window.
        """
        trigger = ttk.Entry(self.root)
        trigger.pack()
        self.root.update()
        trigger.focus_force()
        with mock.patch.object(ui_theme, '_load_scrim_user32', return_value=None):
            panel = EmbeddedWindow(trigger)
        self.addCleanup(panel.destroy)
        self.assertFalse(self.root._embedded_scrim.winfo_manager())
        self.assertEqual("RaisedFallback.TFrame", panel._surface.cget('style'))
        for width, height in SHELL_VISUAL_SIZES:
            self.root.geometry(f"{width}x{height}")
            self.root.update()
            surface = panel._surface
            self.assertGreater(surface.winfo_x(), 0)
            self.assertGreater(surface.winfo_y(), 0)
            self.assertAlmostEqual(self.root.winfo_width() / 2,
                                   surface.winfo_x() + surface.winfo_width() / 2, delta=1)
            self.assertAlmostEqual(self.root.winfo_height() / 2,
                                   surface.winfo_y() + surface.winfo_height() / 2, delta=1)
        self.assertIs(self.root.grab_current(), panel._surface)
        panel.destroy()
        self.root.update()
        self.assertIs(self.root.focus_get(), trigger)

    @unittest.skipUnless(sys.platform == 'win32', 'Native child alpha requires Windows.')
    def test_updater_stays_centered_and_dims_the_existing_client(self) -> None:
        """Exercise updater rendering and ordered phase changes with no downloads.

        Returns:
            None: Desktop and narrow screenshots show both the window dim and
                the distinct card shadow. Later restores the background.
        """
        configure_theme(self.root)
        # A uniform fixture surface makes actual alpha measurable at the edge.
        self.root.configure(background='#6088A8')
        self.root.lift()
        host = SimpleNamespace(root=self.root, exiting=False)
        with (
            tempfile.TemporaryDirectory() as directory,
            mock.patch.dict(os.environ, {"LOCALAPPDATA": directory}),
            mock.patch.object(self.root, 'after'),
        ):
            controller = UpdateController(host, '1.7.1')
        for width, height in SHELL_VISUAL_SIZES:
            with self.subTest(width=width):
                self.root.geometry(f'{width}x{height}+0+0')
                normal = self.capture_root(f'{width}-client')
                self.assertEqual((96, 136, 168), normal)
                controller.show()
                self.root.update()
                panel = controller.window
                self.assertIsNotNone(panel)
                self.assertTrue(self.root._embedded_scrim.composited)
                self.assertIs(self.root.grab_current(), panel._surface)
                self.assertGreater(panel._surface.winfo_x(), 0)
                self.assertGreater(panel._surface.winfo_y(), 0)
                dimmed = self.capture_root(f'{width}-updater-check')
                scrim = ui_theme._hex_rgb(ui_theme._PALETTE['scrim'])
                self.assertLess(sum(scrim), sum(dimmed))
                self.assertLess(sum(dimmed), sum(normal))
                surface = panel._surface
                shadows = surface._shadow_layers
                self.assertTrue(shadows and all(layer.winfo_ismapped() for layer in shadows))
                # Sample two pixels outside the right edge, away from corners.
                shadow_at = (
                    surface.winfo_x() + surface.winfo_width() + 2,
                    surface.winfo_y() + surface.winfo_height() // 2,
                )
                shaded = self.capture_root(f'{width}-updater-shadow', shadow_at)
                self.assertLess(sum(shaded), sum(dimmed))
                with (
                    mock.patch.object(self.root, 'after'),
                    mock.patch.object(
                        controller, '_present_download_progress',
                        wraps=controller._present_download_progress,
                    ) as publish,
                ):
                    for done in (10, 20, 30):
                        controller.events.put(('progress', (done, 100)))
                    controller.poll()
                    publish.assert_called_once_with(30, 100)
                self.assertEqual(30, float(controller.progress['value']))
                self.capture_root(f'{width}-updater-download')
                body = controller.progress.master
                status = next(widget for widget in body.winfo_children()
                              if isinstance(widget, ttk.Label))
                style = ttk.Style(self.root)
                self.assertEqual(style.lookup(body.cget('style'), 'background'),
                                 style.lookup(status.cget('style'), 'background'))
                controller.later()
                self.assertEqual(normal, self.capture_root(f'{width}-restored'))
                self.assertTrue(all(not layer.winfo_ismapped() for layer in shadows))

    def test_flight_saved_reports_render_actions_in_embedded_footer(self) -> None:
        """Build the actual report controls inside an embedded application host.

        Returns:
            None: Saved-report footer actions mount on the root-owned flight
                panel without relying on native dialog-only attributes.
        """
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        host = SimpleNamespace(
            root=self.root,
            simulator_presence=SimpleNamespace(text=lambda key, _locale: key),
            open_log_file=mock.Mock(),
        )
        with mock.patch.object(self.root, "after"):
            controller = flight_ui.FlightUI(
                host, Path(directory.name), str, mock.Mock(), mock.Mock(),
            )
        self.assertIsNotNone(controller.lease, controller.load_error)
        self.addCleanup(controller.lease.release)
        controller.refresh_reports = mock.Mock()

        controller.show_reports()
        self.root.update()

        self.assertIsInstance(controller.report_dialog, EmbeddedWindow)
        self.assertIs(active_panel(self.root), controller.report_dialog)
        self.assertIsNotNone(controller.report_tree)
        self.assertEqual(1, controller.refresh_reports.call_count)
        self.assertEqual("create_local_pdf", controller.report_button.cget("text"))
        self.assertEqual("report_create_upload", controller.report_upload_button.cget("text"))
        self.assertTrue(controller.report_button.winfo_viewable())
        self.assertTrue(controller.report_upload_button.winfo_viewable())
        menu = controller.report_menu
        labels = {
            menu.entrycget(index, "label")
            for index in range(menu.index("end") + 1)
            if menu.type(index) == "command"
        }
        self.assertIn("finish_pdf", labels)
        self.assertIn("open_app_log", labels)
        controller.report_dialog.destroy()

    def test_confirmation_close_cancels(self) -> None:
        """Return the safe negative result when a confirmation is closed.

        Returns:
            None: Verifies the synchronous prompt returns after close.
        """
        self.root.after_idle(lambda: active_panel(self.root)._close())
        self.assertFalse(messagebox.askyesno('Confirm', 'Continue?', parent=self.root))
        self.assertIsNone(active_panel(self.root))

    def test_heading_close_is_inside_the_modal_grab(self) -> None:
        """Keep the close control reachable while background input is blocked.

        Returns:
            None: The real grab encompasses both heading and content controls.
        """
        panel = EmbeddedWindow(self.root)
        owner = self.root.grab_current()
        self.assertIsInstance(panel, DialogSurface)
        self.assertIsInstance(panel._surface, DialogSurface)
        self.assertTrue(any(isinstance(widget, Button)
                            for widget in panel._header.winfo_children()))
        self.assertTrue(str(panel._header).startswith(str(owner) + '.'))
        self.assertTrue(str(panel).startswith(str(owner) + '.'))
        self.assertFalse(any(isinstance(widget, tk.Toplevel) for widget in self.root.winfo_children()))

    def press_entry_key(self, sequence: str) -> None:
        """Deliver one key after native mapping and focus events can settle.

        Args:
            sequence (str): Tk Escape or Return key sequence for the real entry.
        Returns:
            None: Polls the mapped target and queues its actual key binding.
        """
        panel = active_panel(self.root)
        if panel is None:
            self.key_after_id = self.root.after(SHELL_KEY_POLL_MS, lambda: self.press_entry_key(sequence))
            return
        entry = next(
            widget for widget in panel.winfo_children()[0].winfo_children()
            if isinstance(widget, ttk.Entry)
        )
        self.key_observation = (
            f"mapped={entry.winfo_ismapped()} "
            f"geometry={self.root.winfo_geometry()} "
            f"focus={self.root.focus_get()}"
        )
        if not entry.winfo_ismapped():
            self.key_after_id = self.root.after(SHELL_KEY_POLL_MS, lambda: self.press_entry_key(sequence))
            return
        entry.focus_force()

        def deliver() -> None:
            """Send the key only after the observed focus reaches the entry.

            Returns:
                None: Waits without reentrant update calls, then sends one key.
            """
            if self.root.focus_get() is not entry:
                self.key_observation = f"mapped=1 focus={self.root.focus_get()}"
                self.key_after_id = self.root.after(SHELL_KEY_POLL_MS, deliver)
                return
            self.assertTrue(entry.winfo_ismapped())
            self.key_after_id = None
            entry.event_generate(sequence, when='tail')

        self.key_after_id = self.root.after(SHELL_KEY_POLL_MS, deliver)

    def test_escape_from_entry_cancels(self) -> None:
        """Route descendant Escape through the active panel's close protocol.

        Returns:
            None: Cancellation returns without waiting for the fixture watchdog.
        """
        self.root.after_idle(lambda: self.press_entry_key('<Escape>'))
        self.assertIsNone(simpledialog.askstring('Input', 'Value', parent=self.root))

    def test_return_preserves_a_string_matching_an_internal_token(self) -> None:
        """Accept arbitrary input through Return without a sentinel collision.

        Returns:
            None: The actual entry key binding preserves the submitted text.
        """
        self.root.after_idle(lambda: self.press_entry_key('<Return>'))
        self.assertEqual(
            simpledialog.askstring('Input', 'Value', parent=self.root, initialvalue='__cancel__'),
            '__cancel__',
        )

    def test_destroying_a_prompt_owner_releases_the_wait(self) -> None:
        """Fail closed when an owner vanishes during an embedded decision.

        Returns:
            None: A destroyed panel returns cancellation without leaving a wait.
        """
        self.root.after_idle(lambda: active_panel(self.root).destroy())
        self.assertIsNone(messagebox.askyesnocancel('Confirm', 'Continue?', parent=self.root))
        self.assertIsNone(active_panel(self.root))

    def test_covered_progress_does_not_steal_a_decision(self) -> None:
        """Keep refreshes and unrelated asynchronous panels below active input.

        Returns:
            None: A nested decision keeps its grab until explicitly dismissed.
        """
        progress = EmbeddedWindow(self.root)
        decision = EmbeddedWindow(progress)
        progress.deiconify()
        progress.lift()
        progress.grab_set()
        unrelated = EmbeddedWindow(self.root)
        unrelated.grab_set()
        self.assertIs(active_panel(self.root), decision)
        self.assertIs(decision.grab_current(), decision)
        self.assertIsNone(unrelated._owner_panel)
        unrelated.destroy()
        self.assertIs(decision.grab_current(), decision)
        decision.destroy()
        self.assertIs(active_panel(self.root), progress)

    def test_unrelated_panel_waits_until_the_active_surface_closes(self) -> None:
        """Reveal queued root-owned work without assigning it to a random prompt.

        Returns:
            None: Closing the original surface preserves and admits queued work.
        """
        original = EmbeddedWindow(self.root)
        queued = EmbeddedWindow(self.root)
        self.assertIs(active_panel(self.root), original)
        self.assertTrue(queued._hidden)
        original.destroy()
        self.assertIs(active_panel(self.root), queued)
        self.assertIs(queued.grab_current(), queued)

    def test_dynamic_controls_receive_keyboard_scope(self) -> None:
        """Scope controls mapped after the panel's first idle build.

        Returns:
            None: A late-created entry cannot omit the panel traversal bind tag.
        """
        panel = EmbeddedWindow(self.root)
        self.root.update()
        late_entry = ttk.Entry(panel)
        late_entry.pack()
        self.root.update()
        self.assertIn('EmbeddedPanelKeys', late_entry.bindtags())

    def test_synchronous_question_services_other_tk_callbacks(self) -> None:
        """Keep unrelated event callbacks alive while a decision awaits input.

        Returns:
            None: The nested event loop handles a queued observation before close.
        """
        observations = []
        self.root.after_idle(lambda: observations.append('worker observation'))
        self.root.after_idle(lambda: active_panel(self.root)._close())
        self.assertFalse(messagebox.askyesno('Confirm', 'Continue?', parent=self.root))
        self.assertEqual(observations, ['worker observation'])

    def test_tray_hidden_root_defers_input_grab_until_restored(self) -> None:
        """Retain a panel in a hidden root without grabbing invisible controls.

        Returns:
            None: Restoring the main window makes its active panel interactive.
        """
        self.root.withdraw()
        panel = EmbeddedWindow(self.root)
        self.assertIsNone(self.root.grab_current())
        self.root.deiconify()
        self.root.update()
        self.assertIs(panel.grab_current(), panel)

    def test_routine_notice_keeps_the_active_input_and_grab(self) -> None:
        """Present delayed worker notices without covering a decision panel.

        Returns:
            None: Notices return immediately and preserve the actual focused entry.
        """
        panel = EmbeddedWindow(self.root)
        entry = ttk.Entry(panel)
        entry.pack()
        self.root.update()
        self.root.focus_force()
        entry.focus_set()
        self.root.update()
        self.assertIs(self.root.focus_get(), entry)
        with mock.patch('uploader.ui_shell._prompt', side_effect=AssertionError('Notice cannot wait')):
            self.assertEqual(messagebox.showinfo('Complete', 'Worker completed', parent=self.root), 'ok')
        self.assertIs(active_panel(self.root), panel)
        self.assertIs(panel.grab_current(), panel)
        self.assertIs(self.root.focus_get(), entry)
        self.assertEqual(self.root._embedded_notices, [('Complete', 'Worker completed')])
        panel.destroy()
        self.assertIsNone(active_panel(self.root))
        self.assertTrue(self.root._embedded_notice_widget.winfo_exists())
