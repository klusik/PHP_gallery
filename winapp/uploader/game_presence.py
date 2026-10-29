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
from uploader.windows_game_processes import current_user_game_processes
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

    def __init__(self, dll_path: Callable[[], str], profile: Path) -> None:
        """Prepare an idle observer using the current saved DLL override.

        Args:
            dll_path (Callable[[], str]): Return the saved SimConnect DLL path;
                an empty string uses the existing runtime discovery policy.
            profile (Path): Uploader profile with optional game-contexts.json.
        Returns:
            None: Initializes an unavailable cache without native work.
        """
        super().__init__(name="GamePresence", daemon=True)
        self.dll_path = dll_path
        self.profile = profile
        self.available = False
        self.context: GameContext | None = None
        self.cancelled = threading.Event()

    def probe(self) -> bool:
        """Open and close one native handle without requesting simulator data.

        Returns:
            bool: True only for a successful open with a nonempty handle;
                False on unsupported hosts or unavailable/broken runtimes.
        """
        if os.name != "nt":
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
            catalog = GameCatalog(self.profile)
            while not self.cancelled.is_set():
                available = self.probe()
                context = None if available else catalog.detect(
                    current_user_game_processes(catalog.process_names)
                )
                # Publish one context snapshot so a Tk refresh never combines
                # a new presence result with the previous game's phrases.
                self.context = (
                    GameContext("simconnect", "Flight Simulator", "flight")
                    if available else context
                )
                self.available = available
                self.cancelled.wait(PRESENCE_POLL_SECONDS)
        finally:
            self.available = False
            self.context = None

    def action_label(self, action: str, language: str) -> str:
        """Read one cached localized label without native or filesystem work.

        Args:
            action (str): Existing begin or finish action selected by saved key.
            language (str): Active interface language code, en/cs/de/sv.
        Returns:
            str: Contextual label, or the ordinary general game label when
                detection is unavailable, unknown or ambiguous.
        """
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
