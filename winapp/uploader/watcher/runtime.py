# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/runtime.py
# Module Type: Windows Runtime Support
# Purpose: Own Watcher runtime paths and optional desktop/image dependencies.
# Responsibilities:
#   - Resolve application assets and requirement files from the bundle location.
#   - Guard Tk, Pillow and tray imports for stripped or headless runtimes.
#   - Recheck optional support after the operator installs missing packages.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own Watcher runtime paths and optional platform dependencies."""

import importlib
import os
from pathlib import Path
from typing import Tuple
import subprocess
import sys

from ..gui_backend import load_gui_backend
from ..localization import gettext, gettext_noop
from .state import CONFIG_DIR

APP_DIR = Path(__file__).resolve().parents[2]
APP_VERSION = (APP_DIR / "VERSION").read_text(encoding="utf-8").strip()
REQUIREMENTS_PATH = APP_DIR / "requirements.txt"
CORE_REQUIREMENTS_PATH = APP_DIR / "requirements-core.txt"
ASSETS_DIR = APP_DIR / "assets"
TRAY_ICON_PNG_PATH = ASSETS_DIR / "tray-icon.png"
TRAY_ICON_ICO_PATH = ASSETS_DIR / "tray-icon.ico"

try:
    import tkinter as tk
    from tkinter import filedialog, messagebox, ttk
except ImportError:  # pragma: no cover
    tk = None
    filedialog = None
    messagebox = None
    ttk = None

if tk is not None:
    from .. import ui_file_picker as filedialog
    # Preserve the application's embedded restart/confirmation dialogs. The
    # native tkinter messagebox lacks the app's custom multi-choice panels.
    from ..ui_shell import messagebox

try:
    from PIL import Image, ImageDraw, ImageOps, ImageStat
except ImportError:  # pragma: no cover
    Image = None
    ImageDraw = None
    ImageOps = None
    ImageStat = None

# Pystray's Linux Xorg backend opens an X connection during import. A bare
# shell and the portable CLI continue to work with common packages present.
if sys.platform == "linux" and not (
    os.environ.get("DISPLAY") or os.environ.get("PYSTRAY_BACKEND")
):
    pystray = None
else:
    try:
        import pystray
    except ImportError:  # pragma: no cover
        pystray = None


def ensure_optional_support() -> None:
    """Refresh optional Pillow and tray support after dependency installation.

    Returns:
        None: Module-owned capability handles reflect the current interpreter.

    Raises:
        ImportError: An installed Pillow module cannot be loaded into this process.
    """
    global Image, ImageDraw, ImageOps, ImageStat, pystray
    Image = importlib.import_module("PIL.Image")
    ImageDraw = importlib.import_module("PIL.ImageDraw")
    ImageOps = importlib.import_module("PIL.ImageOps")
    ImageStat = importlib.import_module("PIL.ImageStat")
    if sys.platform == "linux" and not (
        os.environ.get("DISPLAY") or os.environ.get("PYSTRAY_BACKEND")
    ):
        pystray = None
    else:
        pystray = importlib.import_module("pystray")


def local_thumbnail_supported() -> bool:
    """Return whether Pillow is available for local thumbnail generation.

    Returns:
        bool: True when the runtime has imported Pillow image and transform APIs.
    """
    return Image is not None and ImageOps is not None

def thumbnail_runtime_status(*, for_display: bool = False) -> str:
    """
    Build a clear status line for the Python runtime used by this process.

    Windows can have multiple Python installations and Microsoft Store aliases.
    The app must report the exact executable currently running the GUI because
    optional helper packages have to be installed into this same interpreter.

    Args:
        for_display (bool): Translate the Settings/AI caption when true.

    Returns:
        str: Runtime and Pillow availability in stable diagnostic or UI text.
    """
    translate = gettext if for_display else gettext_noop
    executable = sys.executable or translate(gettext_noop("unknown Python executable"))
    version = sys.version.split()[0]
    tray_status = translate(
        gettext_noop("tray available") if pystray is not None else gettext_noop("tray unavailable"),
    )
    if local_thumbnail_supported():
        pillow_version = getattr(Image, "__version__", "installed")
        return translate(gettext_noop(
            "Client-side thumbnails available. Python {version}: {executable}. "
            "Pillow: {pillow_version}; {tray_status}."
        )).format(
            version=version, executable=executable,
            pillow_version=pillow_version, tray_status=tray_status,
        )
    return translate(gettext_noop(
        "Client-side thumbnails unavailable for this Python runtime: "
        "{executable}. Python {version}; {tray_status}."
    )).format(executable=executable, version=version, tray_status=tray_status)


def install_dependencies_for_current_runtime() -> Tuple[bool, str]:
    """
    Install or repair winapp dependencies for the exact Python interpreter.

    Use the frontend selected in this profile and the same constraints as the
    standard source installer. Repair targets the interpreter running WinApp.

    Returns:
        Tuple[bool, str]: Tuple containing success flag and command output
            text.
    """
    if getattr(sys, "frozen", False):
        available = Image is not None and pystray is not None
        return available, (
            gettext_noop("Required dependencies are bundled with this application.")
            if available else gettext_noop("Bundled dependencies are unavailable. Reinstall PHP Gallery Uploader.")
        )
    requirements = (
        REQUIREMENTS_PATH if load_gui_backend(CONFIG_DIR) == "pyside6"
        else CORE_REQUIREMENTS_PATH
    )
    if not requirements.is_file():
        return False, f"Missing WinApp source dependencies file: {requirements.name}"
    command = [sys.executable, "-m", "pip", "install"]
    if sys.prefix == sys.base_prefix:
        command.append("--user")
    command.extend(["-r", str(requirements)])

    try:
        completed = subprocess.run(
            command,
            check=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
    except Exception as exc:  # noqa: BLE001
        return False, str(exc)

    output = completed.stdout.strip() if completed.stdout else "pip produced no output"
    if completed.returncode == 0:
        try:
            ensure_optional_support()
        except Exception as exc:  # noqa: BLE001
            return False, f"pip finished, but required packages still cannot be imported by this process: {exc}\n{output}"
    return completed.returncode == 0, output
