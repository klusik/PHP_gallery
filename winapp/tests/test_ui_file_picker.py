# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_ui_file_picker.py
# Module Type: Windows Tk Regression Test
# Purpose: Verify embedded file-picker directory browsing and selection adapters.
# Responsibilities: Cover filters, truncation, errors and picker dispatch.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Regression coverage for root-hosted file browsing."""

import sys
import tkinter as tk
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest import mock
from tkinter import ttk
from typing import Callable

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import ui_file_picker
from uploader.ui_shell import active_panel

# Purpose: Bound a real Tk picker fixture if an expected interaction stalls.
# Type: int; Units: milliseconds; Scope: one picker test.
# Consumers: EmbeddedPickerInteractionTests watchdog.
# Rationale: 8000 ms allows hosted Windows directory scans without hanging CI.
_PICKER_TEST_TIMEOUT_MS = 8000

# Purpose: Retry test interaction after asynchronous directory scans.
# Type: int; Units: milliseconds; Scope: one picker test callback.
# Consumers: EmbeddedPickerInteractionTests interaction driver.
# Rationale: 30 ms checks promptly without a busy event loop.
_PICKER_TEST_POLL_MS = 30


def _descendants(widget: tk.Misc) -> list[tk.Misc]:
    """Collect live descendants for interacting with the actual picker controls.

    Args:
        widget (tk.Misc): Root of the widget subtree to inspect.
    Returns:
        list[tk.Misc]: Descendants in Tk child order.
    """
    children = widget.winfo_children()
    return children + [nested for child in children for nested in _descendants(child)]


def _button(widget: tk.Misc, text: str) -> ttk.Button:
    """Find a named button in an embedded panel.

    Args:
        widget (tk.Misc): Panel containing the desired control.
        text (str): Exact displayed button text.
    Returns:
        ttk.Button: Matching button for invocation by the fixture.
    """
    return next(child for child in _descendants(widget)
                if isinstance(child, ttk.Button) and child.cget('text') == text)


class EmbeddedFilePickerTests(unittest.TestCase):
    """Verify listing and public file-picker return contracts."""

    def test_listing_preserves_directories_and_filters_files(self) -> None:
        """Include navigable folders and only files matching the filter.

        Returns:
            None: Asserts sorted rows, case-insensitive filters and no error.
        """
        with TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'folder').mkdir()
            (root / 'IMAGE.JPG').touch()
            (root / 'note.txt').touch()
            rows, truncated, error = ui_file_picker._scan_directory(root, ('*.jpg',))
            self.assertEqual(rows, [('folder', True), ('IMAGE.JPG', False)])
            self.assertFalse(truncated)
            self.assertEqual(error, '')
            rows, truncated, error = ui_file_picker._scan_directory(root, (), True)
            self.assertEqual(rows, [('folder', True)])
            self.assertFalse(truncated)
            self.assertEqual(error, '')

    def test_listing_reports_truncation_and_inaccessible_path(self) -> None:
        """Expose capped listings and failed directory access to the user.

        Returns:
            None: Confirms truncation and a useful missing-directory error.
        """
        with TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'one').touch()
            (root / 'two').touch()
            with mock.patch.object(ui_file_picker, '_DIRECTORY_ENTRY_LIMIT', 1):
                rows, truncated, error = ui_file_picker._scan_directory(root, ())
            self.assertEqual(len(rows), 1)
            self.assertTrue(truncated)
            self.assertEqual(error, '')
            rows, truncated, error = ui_file_picker._scan_directory(root / 'missing', ())
            self.assertEqual(rows, [])
            self.assertFalse(truncated)
            self.assertTrue(error)

    def test_adapters_preserve_multiple_result_and_options(self) -> None:
        """Forward picker options and preserve the multi-selection tuple.

        Returns:
            None: Confirms the public adapter delegates without losing values.
        """
        with mock.patch.object(ui_file_picker, '_choose', return_value=('a', 'b')) as choose:
            result = ui_file_picker.askopenfilenames(title='Images', initialdir='folder')
        self.assertEqual(result, ('a', 'b'))
        choose.assert_called_once_with('multiple', title='Images', initialdir='folder')

    def test_close_returns_empty_multiple_selection(self) -> None:
        """Cancel an embedded picker without opening another Tk window.

        Returns:
            None: Verifies the modal result and root-only panel ownership.
        """
        try:
            root = tk.Tk()
        except tk.TclError as exc:
            self.skipTest(str(exc))
        try:
            from uploader.ui_shell import active_panel
            root.after_idle(lambda: active_panel(root)._close())
            self.assertEqual(ui_file_picker.askopenfilenames(parent=root), ())
            self.assertFalse(any(isinstance(child, tk.Toplevel) for child in root.winfo_children()))
        finally:
            root.destroy()


class EmbeddedPickerInteractionTests(unittest.TestCase):
    """Drive real embedded picker controls through the Tk event loop."""

    def setUp(self) -> None:
        """Create a graphical root or skip on a displayless runner.

        Returns:
            None: Stores an initialized Tk root for the fixture.
        """
        try:
            self.root = tk.Tk()
        except tk.TclError as exc:
            self.skipTest(str(exc))
        self.root.update()

    def tearDown(self) -> None:
        """Release the root after each interaction test.

        Returns:
            None: Destroys the root if the watchdog has not already done so.
        """
        try:
            self.root.destroy()
        except tk.TclError:
            pass

    def _run_picker(self, action: Callable[[], str | tuple[str, ...]], step: Callable[[], bool]) -> str | tuple[str, ...]:
        """Run scheduled interactions with a bounded watchdog and error capture.

        Args:
            action (Callable[[], str | tuple[str, ...]]): Synchronous picker call.
            step (Callable[[], bool]): Interaction returning True once finished.
        Returns:
            str | tuple[str, ...]: Picker result after the scheduled interaction.
        """
        errors: list[Exception] = []
        expired = [False]

        def drive() -> None:
            """Advance the fixture until its target interaction is complete.

            Returns:
                None: Reschedules pending work or closes on an assertion error.
            """
            try:
                if not step():
                    self.root.after(_PICKER_TEST_POLL_MS, drive)
            except Exception as exc:
                errors.append(exc)
                panel = active_panel(self.root)
                if panel is not None:
                    panel._close()

        def timeout() -> None:
            """Cancel all panels when the fixture exceeds its time budget.

            Returns:
                None: Prevents an unresponsive picker from hanging the suite.
            """
            expired[0] = True
            self.root.destroy()

        timer = self.root.after(_PICKER_TEST_TIMEOUT_MS, timeout)
        self.root.after_idle(drive)
        result = action()
        if not expired[0]:
            self.root.after_cancel(timer)
        self.assertFalse(expired[0], 'Embedded picker interaction timed out')
        if errors:
            raise errors[0]
        return result

    def test_typed_navigation_and_multiple_selection(self) -> None:
        """Navigate by typed path and return both selected existing files.

        Returns:
            None: Verifies stale rows disappear and paths come from the new folder.
        """
        with TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / 'target'
            target.mkdir()
            (root / 'old.txt').touch()
            (target / 'one.txt').touch()
            (target / 'two.txt').touch()
            phase = [0]

            def interact() -> bool:
                """Navigate once, then select the loaded target directory rows.

                Returns:
                    bool: True after both rows have been submitted.
                """
                panel = active_panel(self.root)
                if panel is None:
                    return False
                widgets = _descendants(panel)
                path_entry = next(
                    item for item in widgets
                    if isinstance(item, ttk.Entry)
                    and any(
                        isinstance(sibling, ttk.Button)
                        and sibling.cget('text') == 'Go'
                        for sibling in item.master.winfo_children()
                    )
                )
                listing = next(item for item in widgets if isinstance(item, tk.Listbox))
                if phase[0] == 0:
                    path_entry.delete(0, 'end')
                    path_entry.insert(0, str(target))
                    self.assertEqual(listing.size(), 0)
                    _button(panel, 'Go').invoke()
                    phase[0] = 1
                    return False
                if listing.size() != 2:
                    return False
                listing.selection_set(0, 1)
                _button(panel, 'Select').invoke()
                return True

            result = self._run_picker(
                lambda: ui_file_picker.askopenfilenames(parent=self.root, initialdir=root),
                interact,
            )
            self.assertEqual(set(result), {str(target / 'one.txt'), str(target / 'two.txt')})

    def test_reject_overwrite_then_cancel(self) -> None:
        """Keep the existing file intact after No and picker cancellation.

        Returns:
            None: Verifies the real nested confirmation and cancellation result.
        """
        with TemporaryDirectory() as directory:
            target = Path(directory) / 'report.txt'
            target.write_text('retained', encoding='utf-8')
            phase = [0]

            def interact() -> bool:
                """Reject the nested overwrite prompt, then cancel the picker.

                Returns:
                    bool: True after cancelling the original picker.
                """
                panel = active_panel(self.root)
                if panel is None:
                    return False
                if phase[0] == 0:
                    phase[0] = 1
                    self.root.after_idle(interact)
                    _button(panel, 'Select').invoke()
                    return False
                if phase[0] == 1:
                    _button(panel, 'No').invoke()
                    phase[0] = 2
                    return True
                _button(panel, 'Cancel').invoke()
                return True

            result = self._run_picker(
                lambda: ui_file_picker.asksaveasfilename(
                    parent=self.root, initialdir=directory, initialfile=target.name,
                ),
                interact,
            )
            self.assertEqual(result, '')
            self.assertEqual(target.read_text(encoding='utf-8'), 'retained')
