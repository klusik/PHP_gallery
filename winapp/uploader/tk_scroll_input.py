# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/tk_scroll_input.py
# Module Type: Windows Client UI Support
# Purpose: Normalize native Tk wheel and high-resolution touchpad input.
# Responsibilities:
#   - Read packed Tk 9.1 touchpad deltas while retaining legacy wheel events.
#   - Normalize vertical wheel direction and preserve precise touchpad units.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Normalize native Tk scroll event packets for gallery controls."""

import tkinter as tk

# Purpose: Keep standard Windows wheel notches consistent with Tk's event API.
# Type: int. Units: delta units per wheel step. Scope: native wheel events.
# Consumers: vertical_scroll_delta.
# Rationale: Tk reports one conventional Windows notch as 120 units.
TK_WHEEL_DELTA_UNITS = 120


def precise_touchpad_deltas(
    widget: tk.Misc,
    event: tk.Event,
) -> tuple[int, int] | None:
    """Unpack Tk 9.1 touchpad deltas when that event is supported.

    Args:
        widget (tk.Misc): Tk widget owning the Tcl interpreter.
        event (tk.Event): Touchpad event whose ``delta`` stores packed axes.
    Returns:
        tuple[int, int] | None: Signed horizontal and vertical deltas, or None
            when the interpreter does not expose Tk's precise-delta helper.
    """
    try:
        result = widget.tk.call('tk::PreciseScrollDeltas', event.delta)
        deltas = widget.tk.splitlist(result)
        if len(deltas) != 2:
            return None
        return int(deltas[0]), int(deltas[1])
    except (tk.TclError, TypeError, ValueError, IndexError):
        return None


def vertical_scroll_delta(
    widget: tk.Misc,
    event: tk.Event,
    *,
    precise: bool = False,
) -> int:
    """Return a signed vertical delta from a wheel or touchpad event.

    Args:
        widget (tk.Misc): Tk widget used to query the Tcl utility procedure.
        event (tk.Event): Native ``MouseWheel`` or ``TouchpadScroll`` event.
        precise (bool): Whether this binding explicitly identifies a touchpad.
    Returns:
        int: Signed vertical delta; positive values represent upward motion.
    """
    if getattr(event, 'num', None) == 4:
        return TK_WHEEL_DELTA_UNITS
    if getattr(event, 'num', None) == 5:
        return -TK_WHEEL_DELTA_UNITS
    if precise:
        deltas = precise_touchpad_deltas(widget, event)
        return deltas[1] if deltas is not None else 0
    return int(getattr(event, 'delta', 0))
