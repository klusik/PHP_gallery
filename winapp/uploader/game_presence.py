# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/game_presence.py
# Module Type: Game Presence Observer
# Purpose: Select contextual action wording using SimConnect then user processes.
# Responsibilities:
#   - Probe native connection availability off the Tk thread.
#   - Close every probe without requesting data or changing simulator state.
#   - Cache optional game context while leaving all action behavior unchanged.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Observe games independently of optional recording, watching and UTC sync."""

import ctypes
import os
import threading
from pathlib import Path
from typing import Callable

from uploader.flight_report_translations import tr
from uploader.game_context import GameCatalog, GameContext
from uploader.windows_game_processes import current_user_game_processes, msfs_running
from uploader.simconnect_location import (
    SimConnectLocationClient,
    simconnect_hresult_failed,
)

# Purpose: Refresh action wording after the simulator starts or exits.
# Type: float; Units: seconds; Scope: desktop simulator-presence observer.
# Consumers: GamePresence.run.
# Rationale: 3 seconds keeps idle native work low with prompt label changes.
PRESENCE_POLL_SECONDS = 3.0


class GamePresence(threading.Thread):
    """Cache optional game wording with SimConnect taking absolute precedence."""

    def __init__(
        self, dll_path: Callable[[], str], profile: Path, *,
        active_by_default: bool = True,
    ) -> None:
        """Prepare an idle observer using the current saved DLL override.

        Args:
            dll_path (Callable[[], str]): Return the saved SimConnect DLL path;
                an empty string uses the existing runtime discovery policy.
            profile (Path): Uploader profile with optional game-contexts.json.
            active_by_default (bool): Preserve always-observing Tk behavior, or
                require explicit visible-context demand from another frontend.
        Returns:
            None: Initializes an unavailable cache without native work.
        """
        super().__init__(name="GamePresence", daemon=True)
        self.dll_path = dll_path
        self.profile = profile
        self.available = False
        self.context: GameContext | None = None
        self.cancelled = threading.Event()
        self._active = threading.Event()
        if active_by_default:
            self._active.set()
        self._wake = threading.Event()
        self._context_lock = threading.Lock()

    def probe(self) -> bool:
        """Probe only a running MSFS 2020/2024 without requesting simulator data.

        Returns:
            bool: True only for a successful open with a nonempty handle;
                False without a supported process or usable native runtime.
        """
        if os.name != "nt" or not msfs_running():
            return False
        try:
            path, _tried = SimConnectLocationClient(
                dll_path=self.dll_path()
            ).resolve_dll_path()
            if path is None:
                return False
            dll = ctypes.WinDLL(str(path))
            dll.SimConnect_Open.argtypes = [
                ctypes.POINTER(ctypes.c_void_p), ctypes.c_char_p,
                ctypes.c_void_p, ctypes.c_uint32,
                ctypes.c_void_p, ctypes.c_uint32,
            ]
            dll.SimConnect_Open.restype = ctypes.c_long
            dll.SimConnect_Close.argtypes = [ctypes.c_void_p]
            dll.SimConnect_Close.restype = ctypes.c_long
            handle = ctypes.c_void_p()
            try:
                # These zeroes select a polling connection without a window,
                # event handle or alternative SimConnect configuration index.
                result = dll.SimConnect_Open(
                    ctypes.byref(handle), b"PHP Gallery presence",
                    None, 0, None, 0,
                )
                return not simconnect_hresult_failed(result) and bool(handle.value)
            finally:
                if handle.value:
                    dll.SimConnect_Close(handle)
        except (OSError, AttributeError, ValueError):
            # General wording permanently supports ordinary games and PCs
            # without SimConnect. This observer never gates existing actions.
            return False

    def run(self) -> None:
        """Refresh SimConnect first, then optional current-user game presence.

        Returns:
            None: Loads offline rules once, refreshes cached wording without
                Tk or network access, and clears state after cancellation.
        """
        try:
            catalog: GameCatalog | None = None
            while not self.cancelled.is_set():
                self._wake.clear()
                if self.cancelled.is_set():
                    break
                if self._active.is_set():
                    if catalog is None:
                        catalog = GameCatalog(self.profile)
                    available = self.probe()
                    if self._active.is_set():
                        context = None if available else catalog.detect(
                            current_user_game_processes(catalog.process_names)
                        )
                    else:
                        context = None
                        available = False
                    if self._active.is_set():
                        self._publish_context(
                            GameContext("simconnect", "Flight Simulator", "flight")
                            if available else context,
                            available,
                        )
                else:
                    self._publish_context(None, False)
                self._wake.wait(PRESENCE_POLL_SECONDS if self._active.is_set() else None)
        finally:
            self._publish_context(None, False)

    def _publish_context(self, context: GameContext | None, available: bool) -> None:
        """Publish one consistent context without crossing into a toolkit.

        Args:
            context (GameContext | None): Current detected game or no context.
            available (bool): Whether SimConnect presence took precedence.
        Returns:
            None: Readers observe the matching context and source flag together.
        """
        with self._context_lock:
            if not self._active.is_set() or self.cancelled.is_set():
                context, available = None, False
            self.context = context
            self.available = available

    def set_active(self, active: bool) -> None:
        """Demand or suspend observer work from a frontend visibility owner.

        Args:
            active (bool): True only while its interactive context is visible.
        Returns:
            None: Wakes the observer and clears stale context immediately on hide.
        """
        if self._active.is_set() is active:
            return
        if active:
            self._active.set()
        else:
            self._active.clear()
            self._publish_context(None, False)
        self._wake.set()

    def action_label(self, action: str, language: str) -> str:
        """Read one cached localized label without native or filesystem work.

        Args:
            action (str): Existing begin or finish action selected by saved key.
            language (str): Active interface language code, en/cs/de/sv.
        Returns:
            str: Contextual label, or the ordinary general game label when
                detection is unavailable, unknown or ambiguous.
        """
        with self._context_lock:
            context = self.context
        return context.label(action, language) if context else tr(
            language, action + "_game"
        )

    @property
    def flight_detected(self) -> bool:
        """Read whether SimConnect or the process fallback identified a flight.

        Returns:
            bool: True only for a cached flight context; unknown, ambiguous
                and ordinary game contexts return False without native work.
        """
        with self._context_lock:
            context = self.context
        return context is not None and context.activity == "flight"

    def text(self, key: str, language: str) -> str:
        """Translate shared abandonment text for the cached activity context.

        Args:
            key (str): Translation key; abandonment control, confirmation,
                phase and completion keys have game-specific equivalents.
            language (str): Active interface language code, en/cs/de/sv.
        Returns:
            str: Game or flight abandonment text; all unrelated keys retain
                their original translation and English fallback.
        """
        if not self.flight_detected:
            key = {
                "abandon": "abandon_game",
                "confirm_abandon": "confirm_abandon_game",
                "phase_abandoning": "phase_abandoning_game",
                "flight_abandoned": "game_abandoned",
            }.get(key, key)
        return tr(language, key)

    def stop(self) -> None:
        """Wake the observer for application teardown without blocking Tk.

        Returns:
            None: Requests cancellation of this optional daemon observer.
        """
        self.cancelled.set()
        self._wake.set()
