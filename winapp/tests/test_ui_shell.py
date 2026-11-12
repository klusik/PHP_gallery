# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_ui_shell.py
# Module Type: Windows Tk Regression Test
# Purpose: Verify embedded panel ownership and modal prompt outcomes.
# Responsibilities: Exercise stacking, focus restoration and synchronous prompts.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Regression coverage for the root-hosted application panel stack."""

import sys
import tkinter as tk
from tkinter import ttk
import unittest
from pathlib import Path
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.ui_shell import (
    EmbeddedWindow, active_panel, initialize_shell, messagebox, simpledialog,
)

# Purpose: Release synchronous prompts if a regression loses their response.
# Type: int. Units: milliseconds. Scope: one isolated shell test.
# Consumers: EmbeddedShellTests.setUp. Rationale: Five seconds permits native
# runner scheduling while ensuring a failed Escape cannot hang the whole suite.
SHELL_TEST_DEADLINE_MS = 5000


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
        initialize_shell(self.root)
        self.root.update()
        self.prompt_timed_out = False
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
            self.root.destroy()
            self.assertFalse(self.prompt_timed_out, 'Dialog response exceeded deadline')

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
        self.assertTrue(str(panel._header).startswith(str(owner) + '.'))
        self.assertTrue(str(panel).startswith(str(owner) + '.'))
        self.assertFalse(any(isinstance(widget, tk.Toplevel) for widget in self.root.winfo_children()))

    def test_escape_from_entry_cancels_without_an_input_sentinel_collision(self) -> None:
        """Route descendant Escape through the panel and preserve arbitrary text.

        Returns:
            None: Escape cancels; a user string matching an internal token survives.
        """
        def cancel_entry() -> None:
            """Cancel the focused input from its actual child-widget binding.

            Returns:
                None: Generates Escape after focus and bind tags are installed.
            """
            panel = active_panel(self.root)
            panel._scope_keyboard()
            self.root.focus_force()
            entry = next(
                widget for widget in panel.winfo_children()[0].winfo_children()
                if isinstance(widget, ttk.Entry)
            )
            entry.focus_set()
            entry.event_generate('<Escape>')

        self.root.after_idle(cancel_entry)
        self.assertIsNone(simpledialog.askstring('Input', 'Value', parent=self.root))

        def submit_value() -> None:
            """Submit the literal input token using the real entry binding.

            Returns:
                None: Accepts the initial value through Return rather than close.
            """
            panel = active_panel(self.root)
            entry = next(
                widget for widget in panel.winfo_children()[0].winfo_children()
                if isinstance(widget, ttk.Entry)
            )
            self.root.focus_force()
            entry.focus_set()
            entry.event_generate('<Return>')

        self.root.after_idle(submit_value)
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
