# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/dependency_versions.py
# Module Type: Build and Qualification Dependency Evidence
# Purpose: Report exact Python, Tcl/Tk and relevant package versions.
# Responsibilities:
#   - Collect package metadata without importing optional GUI stacks.
#   - Read Tcl safely and allow existing Tk roots to report the loaded patch.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Collect bounded dependency version evidence without importing Qt."""

import importlib.metadata
import platform
import threading

try:
    import tkinter
except ImportError:
    tkinter = None


DEPENDENCY_DISTRIBUTIONS = {
    "pillow": "Pillow",
    "pystray": "pystray",
    "reportlab": "reportlab",
    "pypdf": "pypdf",
    "polib": "polib",
    "pyside6_essentials": "PySide6-Essentials",
    "shiboken6": "Shiboken6",
    "pyinstaller": "PyInstaller",
    "altgraph": "altgraph",
    "packaging": "packaging",
    "pyinstaller_hooks_contrib": "pyinstaller-hooks-contrib",
    "pefile": "pefile",
    "pywin32_ctypes": "pywin32-ctypes",
    "psutil": "psutil",
    "charset_normalizer": "charset-normalizer",
    "six": "six",
    "setuptools": "setuptools",
    "pyobjc_core": "pyobjc-core",
    "pyobjc_framework_cocoa": "pyobjc-framework-Cocoa",
    "pyobjc_framework_quartz": "pyobjc-framework-Quartz",
}


def dependency_versions() -> dict[str, str | None]:
    """Return Python, Tcl/Tk and selected installed distribution versions.

    Returns:
        dict[str, str | None]: Flat lower_snake keys for interpreter and
            distribution versions. Missing optional distributions and runtime
            versions unavailable to this process are represented by None.
            ``tk_patchlevel`` remains None until an existing Tk root is passed
            to ``record_tk_patchlevel``.
    """
    versions: dict[str, str | None] = {
        "python": platform.python_version(),
        "python_implementation": platform.python_implementation(),
        "tcl": None,
        "tk": format(tkinter.TkVersion, ".1f") if tkinter is not None else None,
        "tk_patchlevel": None,
    }
    if tkinter is not None and threading.current_thread() is threading.main_thread():
        try:
            interpreter = tkinter.Tcl()
            versions["tcl"] = str(interpreter.eval("info patchlevel"))
        except (tkinter.TclError, OSError):
            pass
    for key, distribution in DEPENDENCY_DISTRIBUTIONS.items():
        try:
            versions[key] = importlib.metadata.version(distribution)
        except (importlib.metadata.PackageNotFoundError, OSError):
            versions[key] = None
    return versions


def record_tk_patchlevel(
    versions: dict[str, str | None], root: "tkinter.Misc",
) -> None:
    """Record Tk's loaded patch level from an existing native root.

    Args:
        versions (dict[str, str | None]): Version map returned by
            ``dependency_versions``.
        root (tkinter.Misc): Existing Tk widget whose interpreter has loaded Tk.
    Returns:
        None: Updates ``tk_patchlevel`` when the Tk package reports its version.
    """
    try:
        patchlevel = str(root.tk.call("package", "provide", "Tk"))
    except tkinter.TclError:
        return
    if patchlevel:
        versions["tk_patchlevel"] = patchlevel
