# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/windows_display.py
# Module Type: Windows presentation bootstrap
# Purpose: Establish native Windows display scaling before Tk creates windows.
# Responsibilities: Respect manifest awareness and avoid startup bitmap scaling.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Prepare the process display context without changing Windows preferences."""

import ctypes
from ctypes import wintypes
import sys


IS_WINDOWS = sys.platform == 'win32'

# Windows ABI flag: load the platform API only from the protected system folder.
_LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x800


def prepare_windows_display() -> bool:
    """Keep native text at the startup display's resolution before creating Tk.

    Returns:
        bool: True when the process is already DPI aware or system awareness
            was enabled. False on other platforms or when the native API is
            unavailable; the app keeps its existing toolkit rendering.
    """
    if not IS_WINDOWS:
        return False
    try:
        user32 = ctypes.WinDLL(
            'user32', use_last_error=True,
            winmode=_LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        user32.IsProcessDPIAware.argtypes = []
        user32.IsProcessDPIAware.restype = wintypes.BOOL
        if user32.IsProcessDPIAware():
            # Preserve an installer's stronger manifest-provided context.
            return True
        user32.SetProcessDPIAware.argtypes = []
        user32.SetProcessDPIAware.restype = wintypes.BOOL
        # Tk 8.6 owns point-to-pixel conversion at startup. System awareness
        # avoids bitmap virtualization without claiming per-monitor reflow for
        # the app's fixed-pixel layouts. Never change a user's display/ClearType
        # preferences. Owner: this bootstrap; permanent toolkit fallback keeps
        # non-Windows and restricted source launches usable.
        return bool(user32.SetProcessDPIAware())
    except (AttributeError, OSError):
        return False
