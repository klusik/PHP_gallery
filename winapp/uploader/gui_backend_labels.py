# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gui_backend_labels.py
# Module Type: Desktop presentation text
# Purpose: Present the same localized backend status in both frontends.
# Responsibilities: Format selection, pending restart and startup recovery copy.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Translate language-independent frontend identities only at presentation."""

from .gui_backend import GuiBackend, GuiFallback
from .localization import gettext


def backend_label(backend: GuiBackend) -> str:
    """Name one frontend consistently in Settings and startup notices.

    Args:
        backend (GuiBackend): Stable identifier shared by the CLI and preference.
    Returns:
        str: Localized display name for the toolkit.
    """
    if backend == "pyside6":
        return gettext("Qt / PySide6")
    return gettext("Legacy Tk")


def backend_status(effective: GuiBackend, requested: GuiBackend) -> str:
    """Present the active frontend and any pending change.

    Args:
        effective (GuiBackend): Frontend that owns this process.
        requested (GuiBackend): Persisted frontend for a subsequent launch.
    Returns:
        str: Localized status containing the requested name when it differs.
    """
    text = gettext("Active backend: {backend}").format(backend=backend_label(effective))
    if effective != requested:
        text += "\n" + gettext("Restart required for {backend}.").format(
            backend=backend_label(requested),
        )
    return text


def fallback_notice(reason: GuiFallback) -> str:
    """Turn a sanitized startup outcome into a localized operator message.

    Args:
        reason (GuiFallback): Non-secret refusal code from the launcher.
    Returns:
        str: Description of the fallback and available recovery action.
    """
    if reason == "dependency_missing":
        return gettext(
            "Qt dependencies could not load. WinApp opened with Legacy Tk. "
            "Install the optional Qt requirements for this Python and retry in Settings.",
        )
    if reason == "pending_work":
        return gettext(
            "WinApp opened with Legacy Tk to keep pending work available. "
            "Review Watcher, Activity and Flight before switching to Qt.",
        )
    if reason == "update_continuation":
        return gettext("WinApp opened with Legacy Tk to finish the update continuation.")
    return gettext(
        "Qt did not become ready. WinApp opened with Legacy Tk. "
        "Review the WinApp log and retry or cancel the change in Settings.",
    )
