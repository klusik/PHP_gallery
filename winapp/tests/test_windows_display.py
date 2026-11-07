# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_windows_display.py
# Module Type: Windows display bootstrap regression tests
# Purpose: Preserve preexisting DPI contexts and non-Windows startup behavior.
# Responsibilities: Exercise native admission, refusal and unavailable APIs.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Test the Windows display boundary without changing the runner's context."""

from types import SimpleNamespace
from pathlib import Path
import sys
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import windows_display


class WindowsDisplayTests(unittest.TestCase):
    """Keep source launches sharp while preserving a manifest's DPI context."""

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
