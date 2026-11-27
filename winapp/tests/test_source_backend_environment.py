# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_source_backend_environment.py
# Module Type: Hosted WinApp source startup regression
# Purpose: Verify the real desktop entry point from clean dependency environments.
# Responsibilities: Observe selected backend readiness and retain version evidence
#   using disposable state and processes on GitHub Actions.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Exercise explicit WinApp source launches with the hosted dependency profile."""

import importlib.util
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import tempfile
import time
import unittest

try:
    import tkinter
except ImportError:
    tkinter = None

WINAPP = Path(__file__).resolve().parents[1]
if str(WINAPP) not in sys.path:
    sys.path.insert(0, str(WINAPP))

from qt_installer_tools import installed_window, request_window_close, wait_for_desktop_exit
from uploader.config import APP_PROFILE_NAME
from uploader.dependency_versions import dependency_versions, record_tk_patchlevel
from uploader.gui_backend import GUI_STARTUP_POLL_SECONDS, GUI_STARTUP_SECONDS, GuiBackend

SOURCE_PROFILE = os.environ.get("PHP_GALLERY_WINAPP_SOURCE_PROFILE")
# Purpose: Bound process closure after observing the actual source GUI.
# Type: float. Units: seconds. Scope: clean hosted source profile.
# Consumers: SourceBackendEnvironment._observe_backend.
# Rationale: Idle synthetic apps need only drain ordinary UI callbacks.
SOURCE_EXIT_SECONDS = 8.0
# Purpose: Give the normal launcher time to supervise its Qt child.
# Type: float. Units: seconds. Scope: one source startup.
# Consumers: SourceBackendEnvironment._observe_backend.
# Rationale: The upstream child deadline plus Python and log setup overhead.
SOURCE_LAUNCH_OVERHEAD_SECONDS = 5.0
SOURCE_READY_SECONDS = GUI_STARTUP_SECONDS + SOURCE_LAUNCH_OVERHEAD_SECONDS
CORE_VERSION_KEYS = ("pillow", "pystray", "reportlab", "pypdf", "polib")
QT_VERSION_KEYS = ("pyside6_essentials", "shiboken6")


@unittest.skipUnless(SOURCE_PROFILE in ("tkinter", "pyside6"),
                     "Source launch qualification is selected by the hosted dependency matrix.")
class SourceBackendEnvironment(unittest.TestCase):
    """Qualify the full and core-only environments without any real profile state."""

    def test_declared_source_frontends_reach_readiness(self) -> None:
        """Start each installed frontend from the same clean source interpreter.

        Returns:
            None: Retains actual ready modes and exact dependency version facts.
        """
        self.assertNotEqual(sys.base_prefix, sys.prefix, "The test needs a fresh virtual environment.")
        versions = dependency_versions()
        for package in CORE_VERSION_KEYS:
            self.assertTrue(versions[package], f"Missing core package {package}.")
        self.assertTrue(versions["python"].startswith("3.14."))
        self.assertTrue(versions["tcl"])
        self.assertTrue(versions["tk"])
        if tkinter is None:
            self.fail("This hosted source environment requires Tkinter.")
        root = tkinter.Tk()
        try:
            root.withdraw()
            record_tk_patchlevel(versions, root)
        finally:
            root.destroy()
        self.assertTrue(versions["tk_patchlevel"])
        backends: tuple[GuiBackend, ...] = ("tkinter",)
        if SOURCE_PROFILE == "pyside6":
            for package in QT_VERSION_KEYS:
                self.assertTrue(versions[package], f"Missing GUI package {package}.")
            backends = ("tkinter", "pyside6")
        else:
            for package in ("PySide6", "shiboken6"):
                self.assertIsNone(importlib.util.find_spec(package))
            for package in QT_VERSION_KEYS:
                self.assertIsNone(versions[package])
        with tempfile.TemporaryDirectory(prefix="winapp-source-backend-") as folder:
            workspace = Path(folder).resolve()
            observed = {backend: self._observe_backend(workspace / backend, backend)
                        for backend in backends}
        evidence = {
            "source_profile": SOURCE_PROFILE,
            "dependency_versions": versions,
            "ready_seconds": {backend: result[0] for backend, result in observed.items()},
            "qt_runtime": observed["pyside6"][1] if "pyside6" in observed else None,
        }
        destination = os.environ.get("PHP_GALLERY_QT_ARTIFACT_DIR")
        if destination:
            path = Path(destination)
            path.mkdir(parents=True, exist_ok=True)
            (path / "source-dependencies.json").write_text(
                json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8",
            )
        print("WinApp source dependency versions: " + json.dumps(evidence, sort_keys=True), flush=True)

    def _observe_backend(
        self, profile_root: Path, backend: GuiBackend,
    ) -> tuple[float, str | None]:
        """Launch one production source entrypoint and await its GUI signal.

        Args:
            profile_root (Path): Exclusive application and local-data fixture root.
            backend (GuiBackend): Exact toolkit requested on the ordinary CLI.
        Returns:
            tuple[float, str | None]: Seconds until ready and the observed Qt
                runtime version, or None for the selected Tk backend.
        Raises:
            AssertionError: The expected GUI or the Windows orderly exit was absent.
        """
        profile_root.mkdir()
        local = profile_root / "local"
        config = profile_root / "fixture.json"
        config.write_text(json.dumps({"close_to_tray": False, "start_with_windows": False}), encoding="utf-8")
        updates = local / APP_PROFILE_NAME / "updates"
        updates.mkdir(parents=True)
        (updates / "state.json").write_text(json.dumps({
            "last_check": time.time(),
            "version": (WINAPP / "VERSION").read_text(encoding="utf-8").strip(),
            "available": None,
        }), encoding="utf-8")
        log = profile_root / APP_PROFILE_NAME / "watcher.log"
        output = profile_root / "process.log"
        child_environment = {**os.environ, "APPDATA": str(profile_root), "LOCALAPPDATA": str(local)}
        command = [sys.executable, str(WINAPP / "gallery_watch_upload.pyw"),
                   "--config", str(config), "--gui-backend", backend]
        ready = re.compile(rf"Desktop backend ready: {backend}; pid=(\d+)")
        expected_pid: int | None = None
        qt_version: str | None = None
        observations = ""
        started = time.monotonic()
        with output.open("wb") as diagnostics, subprocess.Popen(
            command, cwd=WINAPP, env=child_environment,
            start_new_session=os.name != "nt", stdout=diagnostics, stderr=subprocess.STDOUT,
        ) as process:
            try:
                while time.monotonic() - started < SOURCE_READY_SECONDS:
                    if log.is_file():
                        observations = log.read_text(encoding="utf-8", errors="replace")
                        match = ready.search(observations)
                        renderer = re.search(r"Qt ready: version=([^ ]+) platform=", observations)
                        if match is not None and (backend == "tkinter" or renderer is not None):
                            pid = int(match.group(1))
                            if os.name != "nt" or installed_window(pid) is not None:
                                expected_pid = pid
                                qt_version = renderer.group(1) if renderer is not None else None
                                break
                    if process.poll() not in (None, 0):
                        break
                    time.sleep(GUI_STARTUP_POLL_SECONDS)
                elapsed = time.monotonic() - started
                self.assertIsNotNone(
                    expected_pid, f"{backend} source GUI never became ready.\n{observations}\n"
                    + output.read_text(encoding="utf-8", errors="replace"),
                )
                if os.name == "nt":
                    self.assertTrue(request_window_close(expected_pid), "No window belonged to the ready GUI.")
                    deadline = time.monotonic() + SOURCE_EXIT_SECONDS
                    while time.monotonic() < deadline:
                        observations = log.read_text(encoding="utf-8", errors="replace")
                        if f"phase=mainloop_returned; pid={expected_pid}" in observations:
                            break
                        time.sleep(GUI_STARTUP_POLL_SECONDS)
                    else:
                        self.fail(f"{backend} did not complete its ordinary source shutdown.")
                    wait_for_desktop_exit(expected_pid)
                return elapsed, qt_version
            finally:
                if os.name == "nt":
                    if expected_pid is not None:
                        request_window_close(expected_pid)
                    if process.poll() is None:
                        process.kill()
                else:
                    # This isolated group owns only a blank source smoke profile.
                    try:
                        os.killpg(process.pid, signal.SIGTERM)
                    except ProcessLookupError:
                        pass
                process.wait(timeout=SOURCE_EXIT_SECONDS)
