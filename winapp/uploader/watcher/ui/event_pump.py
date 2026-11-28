# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/event_pump.py
# Module Type: Windows Tk Event Scheduler
# Purpose: Own one bounded Tk callback slot for Watcher worker event delivery.
# Responsibilities:
#   - Schedule one UI-thread tick and reject stale timer callbacks.
#   - Cancel pending delivery before the Tk owner is destroyed.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own the single Tk callback slot for Watcher event delivery."""

from typing import Callable, Protocol

from uploader.watcher import runtime


class TkAfterRoot(Protocol):
    """Describe the Tk scheduling operations required by the event pump."""

    def after(self, delay_ms: int, callback: Callable[[], None]) -> str:
        """Schedule a callback on the root's Tk event thread.

        Args:
            delay_ms (int): Delay before the callback, in Tk milliseconds.
            callback (Callable[[], None]): Bounded event-pump tick.
        Returns:
            str: Tk identity for cancellation and stale-call checks.
        """
        ...

    def after_cancel(self, callback_id: str) -> None:
        """Cancel one pending callback by its Tk identity.

        Args:
            callback_id (str): Identity returned by `after`.
        Returns:
            None: Removes the callback if Tk still owns it.
        """
        ...


class TkEventPump:
    """Own one scheduled callback and dispatch only its current generation."""

    def __init__(self, root: TkAfterRoot) -> None:
        """Bind the event pump to its Tk owner without retaining the app host.

        Args:
            root (TkAfterRoot): Tk root that schedules and cancels callbacks.
        Returns:
            None: Creates an idle scheduler without queueing any callback.
        """
        self.root = root
        self.after_id: str | None = None

    def schedule(self, delay_ms: int, on_tick: Callable[[], None]) -> None:
        """Schedule a tick unless a callback already owns the event slot.

        Args:
            delay_ms (int): Existing Watcher cadence in Tk milliseconds.
            on_tick (Callable[[], None]): One bounded worker-event delivery tick.
        Returns:
            None: Stores one timer identity or leaves the existing one intact.
        """
        if self.after_id is not None:
            return
        callback_id: str | None = None

        def dispatch() -> None:
            """Release only this timer and dispatch its current generation.

            Returns:
                None: Ignores a stale callback or runs one bounded UI tick.
            """
            if callback_id != self.after_id:
                return
            self.after_id = None
            on_tick()

        callback_id = self.root.after(delay_ms, dispatch)
        self.after_id = callback_id

    def cancel(self) -> None:
        """Withdraw the pending event tick before the root is destroyed.

        Returns:
            None: Clears the owned identity before best-effort Tk cancellation.
        """
        callback_id = self.after_id
        self.after_id = None
        if callback_id is None:
            return
        try:
            self.root.after_cancel(callback_id)
        except runtime.tk.TclError:
            pass
