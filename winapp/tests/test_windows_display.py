# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_windows_display.py
# Module Type: Windows display bootstrap regression tests
# Purpose: Preserve DPI contexts and observe isolated Windows scroll rendering.
# Responsibilities:
#   - Exercise native DPI admission, refusal and unavailable APIs.
#   - Compare actual settled pixels of an isolated hosted Canvas widget grid.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Test the Windows display boundary without changing the runner's context."""

from types import SimpleNamespace
from pathlib import Path
import ctypes
from ctypes import wintypes
import json
import os
import sys
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import process_memory, windows_display


class WindowsDisplayTests(unittest.TestCase):
    """Keep source launches sharp while preserving a manifest's DPI context."""

    def test_process_memory_is_unknown_without_windows(self) -> None:
        """Avoid loading native Windows libraries on unsupported platforms.

        Returns:
            None: The unsupported observation is unknown rather than zero.
        """
        with mock.patch.object(process_memory, 'os', SimpleNamespace(name='posix')):
            self.assertIsNone(process_memory.current_process_ram_bytes())

    @unittest.skipUnless(os.name == 'nt', 'Requires the actual Windows process API.')
    def test_process_memory_reads_current_windows_working_set(self) -> None:
        """Read a positive working set through the actual current-process ABI.

        Returns:
            None: The supported native API reports a positive resident size.
        """
        observed = process_memory.current_process_ram_bytes()
        self.assertIsInstance(observed, int)
        self.assertGreater(observed, 0)

    def test_existing_awareness_is_preserved(self) -> None:
        """Leave a manifest or host-provided context untouched.

        Returns:
            None: An aware process never calls the native DPI setter.
        """
        native = SimpleNamespace(
            IsProcessDPIAware=mock.Mock(return_value=True),
            SetProcessDPIAware=mock.Mock(),
        )
        with mock.patch.object(windows_display, 'IS_WINDOWS', True), \
                mock.patch.object(
                    windows_display.ctypes, 'WinDLL', return_value=native,
                    create=True,
                ):
            self.assertTrue(windows_display.prepare_windows_display())
        native.SetProcessDPIAware.assert_not_called()

    def test_unaware_process_uses_native_startup_resolution(self) -> None:
        """Request startup system awareness only for an unaware process.

        Returns:
            None: Successful native admission is reported to the launcher.
        """
        native = SimpleNamespace(
            IsProcessDPIAware=mock.Mock(return_value=False),
            SetProcessDPIAware=mock.Mock(return_value=True),
        )
        with mock.patch.object(windows_display, 'IS_WINDOWS', True), \
                mock.patch.object(
                    windows_display.ctypes, 'WinDLL', return_value=native,
                    create=True,
                ):
            self.assertTrue(windows_display.prepare_windows_display())
        native.SetProcessDPIAware.assert_called_once_with()

    def test_native_refusal_is_nonfatal(self) -> None:
        """Keep the existing rendering path when the API refuses a late change.

        Returns:
            None: Failure does not escape into application startup.
        """
        native = SimpleNamespace(
            IsProcessDPIAware=mock.Mock(return_value=False),
            SetProcessDPIAware=mock.Mock(return_value=False),
        )
        with mock.patch.object(windows_display, 'IS_WINDOWS', True), \
                mock.patch.object(
                    windows_display.ctypes, 'WinDLL', return_value=native,
                    create=True,
                ):
            self.assertFalse(windows_display.prepare_windows_display())

    def test_other_platform_never_loads_windows_libraries(self) -> None:
        """Keep macOS and portable Tk independent of Windows DLL loading.

        Returns:
            None: The native loader is unused on other supported platforms.
        """
        with mock.patch.object(windows_display, 'IS_WINDOWS', False), \
                mock.patch.object(
                    windows_display.ctypes, 'WinDLL', create=True,
                ) as loader:
            self.assertFalse(windows_display.prepare_windows_display())
        loader.assert_not_called()

    def test_unavailable_native_api_keeps_toolkit_startup_usable(self) -> None:
        """Keep a restricted source runtime usable without display APIs.

        Returns:
            None: A native library failure returns the documented fallback.
        """
        with mock.patch.object(windows_display, 'IS_WINDOWS', True), \
                mock.patch.object(
                    windows_display.ctypes, 'WinDLL', side_effect=OSError,
                    create=True,
                ):
            self.assertFalse(windows_display.prepare_windows_display())


# Purpose: Bound one isolated visual fixture to a small hosted desktop area.
# Type: tuple[int, int]. Units: pixels. Scope: disposable Tk root.
# Consumers: WindowsCanvasRenderingTests.test_scroll_restores_rendered_tiles.
# Rationale: 640 by 400 shows multiple complete rows without a large display.
TEST_RENDER_WINDOW_SIZE = (640, 400)

# Purpose: Exercise a scrollable grid with multiple offscreen rows.
# Type: tuple[int, int]. Units: button columns and rows. Scope: one fixture.
# Consumers: WindowsCanvasRenderingTests.test_scroll_restores_rendered_tiles.
# Rationale: Six columns and twelve rows exceed the fixture viewport.
TEST_RENDER_GRID_SIZE = (6, 12)

# Purpose: Give each generated raster a predictable visible interior.
# Type: tuple[int, int]. Units: pixels. Scope: one test PhotoImage.
# Consumers: WindowsCanvasRenderingTests.test_scroll_restores_rendered_tiles.
# Rationale: 80 by 48 fits six columns while producing recognizable pixels.
TEST_RENDER_IMAGE_SIZE = (80, 48)


@unittest.skipUnless(
    sys.platform == 'win32' and os.environ.get('GITHUB_ACTIONS') == 'true',
    'Requires an isolated hosted Windows desktop for screen capture.',
)
class WindowsCanvasRenderingTests(unittest.TestCase):
    """Observe actual desktop pixels of a scrolled Tk embedded-widget grid."""

    def test_scroll_restores_rendered_tiles(self) -> None:
        """Compare screen pixels after scrolling a real Tk grid away and back.

        Returns:
            None: A visible desktop restores the original viewport pixels, or
                an unavailable desktop is reported as an explicit skip.
        """
        try:
            import tkinter as tk
            from tkinter import ttk
            from PIL import Image, ImageChops, ImageGrab, ImageTk
        except ImportError as exc:
            self.skipTest(f'Render dependencies unavailable: {exc}')

        try:
            root = tk.Tk()
        except tk.TclError as exc:
            self.skipTest(f'Windows desktop unavailable: {exc}')
        self.addCleanup(root.destroy)
        root.title('Isolated thumbnail scroll rendering fixture')
        width, height = TEST_RENDER_WINDOW_SIZE
        root.geometry(f'{width}x{height}+0+0')
        canvas = tk.Canvas(root, background='#203040', highlightthickness=0)
        canvas.pack(fill='both', expand=True)
        frame = ttk.Frame(canvas)
        window = canvas.create_window((0, 0), window=frame, anchor='nw')
        images = []
        buttons = []
        columns, rows = TEST_RENDER_GRID_SIZE
        for row in range(rows):
            for column in range(columns):
                # Distinct per-row colours make a stale row visibly different.
                colour = (30 + row * 15, 30 + column * 30, 190 - row * 10)
                raster = Image.new('RGB', TEST_RENDER_IMAGE_SIZE, colour)
                photo = ImageTk.PhotoImage(raster, master=root)
                raster.close()
                images.append(photo)
                button = ttk.Button(frame, image=photo)
                button.grid(row=row, column=column, padx=2, pady=2)
                buttons.append(button)
        root.update()
        canvas.itemconfigure(window, width=canvas.winfo_width())
        canvas.configure(scrollregion=canvas.bbox('all'))
        root.update()

        left = canvas.winfo_rootx()
        top = canvas.winfo_rooty()
        bounds = (
            left, top, left + canvas.winfo_width(),
            top + canvas.winfo_height(),
        )
        if (not root.winfo_viewable() or left < 0 or top < 0
                or bounds[2] > root.winfo_screenwidth()
                or bounds[3] > root.winfo_screenheight()):
            self.skipTest('An unobstructed desktop viewport is unavailable.')
        # A pointer outside the captured rectangle prevents native hover
        # painting from being confused with stale scroll pixels. Restore the
        # hosted runner's original pointer position when this fixture ends.
        user32 = ctypes.WinDLL('user32', use_last_error=True)
        original_pointer = wintypes.POINT()
        if not user32.GetCursorPos(ctypes.byref(original_pointer)):
            self.skipTest('Desktop pointer position is unavailable.')
        self.addCleanup(
            user32.SetCursorPos, original_pointer.x, original_pointer.y,
        )
        # The final screen pixel is a structural boundary in Tk coordinates;
        # placing the pointer there avoids mixing DPI-scaled Tk and Win32
        # coordinates when deciding whether it is outside the fixture.
        outside = (root.winfo_screenwidth() - 1, root.winfo_screenheight() - 1)
        if (bounds[0] <= outside[0] < bounds[2]
                and bounds[1] <= outside[1] < bounds[3]):
            self.skipTest('No desktop corner exists outside the viewport.')
        root.event_generate(
            '<Motion>', warp=True,
            x=outside[0] - root.winfo_rootx(),
            y=outside[1] - root.winfo_rooty(),
        )
        root.focus_force()
        root.update()
        pointer = root.winfo_pointerxy()
        pointer_inside = (
            bounds[0] <= pointer[0] < bounds[2]
            and bounds[1] <= pointer[1] < bounds[3]
        )
        containing = root.winfo_containing(*pointer)
        focus_owned = root.focus_get() is root
        if not pointer_inside and focus_owned:
            # Establish a neutral baseline after proving pointer and focus
            # ownership. Never normalize state during the scroll comparison.
            for button in buttons:
                button.state(('!active', '!pressed', '!focus'))
            root.update()
        active_indices = [
            index for index, button in enumerate(buttons)
            if button.instate(('active',))
        ]
        pressed_indices = [
            index for index, button in enumerate(buttons)
            if button.instate(('pressed',))
        ]
        focused_indices = [
            index for index, button in enumerate(buttons)
            if button.instate(('focus',))
        ]
        if (pointer_inside or not focus_owned or active_indices
                or pressed_indices or focused_indices):
            evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
            evidence.mkdir(parents=True, exist_ok=True)
            (evidence / 'scroll-render-prestate.json').write_text(
                json.dumps({
                    'pointer_x': pointer[0], 'pointer_y': pointer[1],
                    'canvas_bounds': bounds,
                    'pointer_inside_canvas': pointer_inside,
                    'containing_is_button': containing in buttons,
                    'root_has_focus': focus_owned,
                    'active_indices': active_indices,
                    'pressed_indices': pressed_indices,
                    'focused_indices': focused_indices,
                }, indent=2) + '\n', encoding='utf-8',
            )
            self.fail('The isolated fixture input state could not be stabilized.')
        try:
            before = ImageGrab.grab(bbox=bounds).convert('RGB')
        except OSError as exc:
            self.skipTest(f'Desktop capture unavailable: {exc}')
        self.addCleanup(before.close)
        # The first image colour verifies actual fixture pixels, not a blank or
        # occluded capture. A screenshot without them cannot test repainting.
        first_colour = (30, 30, 190)
        if first_colour not in before.getdata():
            self.skipTest('Desktop capture does not expose the fixture pixels.')

        canvas.yview_moveto(1.0)
        root.update()
        scrolled = ImageGrab.grab(bbox=bounds).convert('RGB')
        self.addCleanup(scrolled.close)
        canvas.yview_moveto(0.0)
        root.update()
        restored = ImageGrab.grab(bbox=bounds).convert('RGB')
        self.addCleanup(restored.close)
        scrolled_diff = ImageChops.difference(before, scrolled)
        restored_diff = ImageChops.difference(before, restored)
        self.addCleanup(scrolled_diff.close)
        self.addCleanup(restored_diff.close)
        scrolled_bounds = scrolled_diff.getbbox()
        restored_bounds = restored_diff.getbbox()
        active_buttons = sum(button.instate(('active',)) for button in buttons)
        pressed_buttons = sum(button.instate(('pressed',)) for button in buttons)
        focused_buttons = sum(button.instate(('focus',)) for button in buttons)
        if (scrolled_bounds is None or restored_bounds is not None
                or active_buttons or pressed_buttons or focused_buttons):
            # These files contain only synthetic fixture pixels and geometry.
            evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
            evidence.mkdir(parents=True, exist_ok=True)
            before.save(evidence / 'scroll-render-before.png')
            scrolled.save(evidence / 'scroll-render-scrolled.png')
            restored.save(evidence / 'scroll-render-restored.png')
            restored_diff.save(evidence / 'scroll-render-diff.png')
            (evidence / 'scroll-render-measurement.json').write_text(
                json.dumps({
                    'canvas_width': canvas.winfo_width(),
                    'canvas_height': canvas.winfo_height(),
                    'scroll_difference_bounds': scrolled_bounds,
                    'return_difference_bounds': restored_bounds,
                    'active_buttons': active_buttons,
                    'pressed_buttons': pressed_buttons,
                    'focused_buttons': focused_buttons,
                }, indent=2) + '\n', encoding='utf-8',
            )
        self.assertEqual(
            (0, 0, 0), (active_buttons, pressed_buttons, focused_buttons),
            'A thumbnail button acquired hover, press, or focus state.',
        )
        self.assertIsNotNone(
            scrolled_bounds, 'The captured viewport did not visibly scroll.',
        )
        self.assertIsNone(
            restored_bounds,
            'Desktop pixels differ after returning to the original scroll position.',
        )
