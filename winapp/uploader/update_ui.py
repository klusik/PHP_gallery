# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Coordinate nonmodal self-update UI and safe background-work drain.
# Responsibilities:
#   - Keep update scheduling separate from gallery credentials and saved settings.
#   - Stop new work before installer handoff and wait without blocking Tk.
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/update_ui.py
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Tk update coordinator; network and installer mechanics have separate owners."""

import json
import math
import multiprocessing
import os
from pathlib import Path
import queue
from dataclasses import asdict
import sys
import threading
import time
from typing import Callable, Iterable, Optional, Protocol, TYPE_CHECKING

from uploader.state_store import atomic_write_json
from uploader.self_update import ReleaseAsset

if TYPE_CHECKING:
    import tkinter as tk


class UpdateApplication(Protocol):
    """Minimal host interface, with worker fields inspected only for shutdown."""
    root: "tk.Tk"
    exiting: bool
    tray_thread: Optional[threading.Thread]
    ai_worker_stop_requested: bool

    def write_log(self, message: str, level: str = "info") -> None:
        """Append a user-visible status message."""
        ...

    def stop_tray_icon(self) -> None:
        """Remove the tray icon before complete process shutdown."""
        ...

    def open_local_path(self, path: Path) -> None:
        """Open an explicitly selected installer using the Windows shell."""
        ...

    def close(self) -> None:
        """Run ordinary application shutdown after updater cancellation."""
        ...


UPDATE_CHECK_INTERVAL_SECONDS = 3600


class InstallGate:
    """Deterministic admission barrier and bounded drain state, independent of Tk."""

    def __init__(self, timeout: float = 120.0) -> None:
        """Initialize a closed-over deadline policy without desktop dependencies."""
        self.timeout = timeout
        self.active = False
        self.deadline = 0.0

    def begin(self, now: float) -> None:
        """Close admission before any worker receives a stop request."""
        self.active = True
        self.deadline = now + self.timeout

    def poll(self, now: float, blockers: Iterable[str]) -> str:
        """Return waiting/ready/timeout; an expired drain reopens admission."""
        if not self.active:
            return "idle"
        if not list(blockers):
            return "ready"
        if now >= self.deadline:
            self.cancel()
            return "timeout"
        return "waiting"

    def cancel(self) -> None:
        """Refuse installation without closing the usable application."""
        self.active = False
        self.deadline = 0.0


def read_update_state(state_path: Path) -> dict[str, object]:
    """Read a bounded cache object without allowing oversized local state input."""
    with state_path.open("rb") as stream:
        data = stream.read(16385)
    if len(data) > 16384:
        raise ValueError("Update state exceeds its size limit.")
    payload = json.loads(data)
    if not isinstance(payload, dict):
        raise ValueError("Invalid update state.")
    return payload


def check_due(state_path: Path, now: float) -> bool:
    """Only automatic checks are throttled; malformed or nonfinite state recovers."""
    try:
        previous = float(read_update_state(state_path)["last_check"])
        return not math.isfinite(previous) or now < previous or now - previous >= UPDATE_CHECK_INTERVAL_SECONDS
    except (OSError, ValueError, TypeError, KeyError):
        return True


class UpdateController:
    """Own all Tk-facing transitions; workers communicate exclusively by queue."""

    def __init__(self, app: UpdateApplication, version: str) -> None:
        """Attach background phase scheduling to an existing Tk application."""
        self.app = app
        self.root = app.root
        self.version = version
        self.gate = InstallGate()
        self.events: "queue.Queue[tuple[str, object]]" = queue.Queue()
        self.cancel = threading.Event()
        self.busy = False
        self.close_pending = False
        self.auto_install = False
        self.asset: Optional[ReleaseAsset] = None
        self.installer: Optional[Path] = None
        self.window: Optional["tk.Toplevel"] = None
        self.worker: Optional[threading.Thread] = None
        local = Path(os.environ.get("LOCALAPPDATA", str(Path.home() / "AppData" / "Local")))
        self.state_path = local / "PHPGalleryUploader" / "updates" / "state.json"
        self.root.after(200, self.poll)
        self.cached_offer_loaded = False
        self.root.after(1500, self.automatic_check)

    def automatic_check(self) -> None:
        """Check once hourly while the application stays open as well as at startup."""
        if self.app.exiting or self.close_pending:
            return
        self.check(automatic=True)
        self.root.after(60000, self.automatic_check)

    def request_close(self) -> bool:
        """Delay ordinary shutdown until updater work ends or refuse during handoff."""
        if self.gate.active:
            self.show()
            return True
        if self.busy:
            self.cancel.set()
            self.close_pending = True
            self.set_status("Closing after the update operation stops...")
            return True
        return False

    def blocked(self) -> bool:
        """Called by every operation that can start work or change connection state."""
        if not self.gate.active:
            return False
        self.app.write_log("Update is preparing installation; new work is paused.", "system")
        return True

    def show(self) -> None:
        """Show a reusable, nonmodal notice without a grab or confirmation dialog."""
        import tkinter as tk
        from tkinter import ttk
        if self.window is not None and self.window.winfo_exists():
            self.window.deiconify()
            self.window.lift()
            return
        self.window = tk.Toplevel(self.root)
        self.window.title("PHP Gallery Uploader updates")
        self.window.protocol("WM_DELETE_WINDOW", self.later)
        self.status = tk.StringVar(value="Checking for updates...")
        ttk.Label(self.window, textvariable=self.status, wraplength=470).pack(padx=18, pady=14)
        self.progress = ttk.Progressbar(self.window, length=450, maximum=100)
        self.progress.pack(padx=18, pady=8)
        buttons = ttk.Frame(self.window)
        buttons.pack(pady=12)
        self.action = ttk.Button(buttons, text="Update", command=self.download, state="disabled")
        self.action.pack(side="left", padx=5)
        self.cancel_button = ttk.Button(buttons, text="Cancel download", command=self.cancel.set, state="disabled")
        self.cancel_button.pack(side="left", padx=5)
        ttk.Button(buttons, text="Later", command=self.later).pack(side="left", padx=5)

    def later(self) -> None:
        """Hide the notice; a running download remains cancellable when reopened."""
        if self.window is not None:
            self.window.withdraw()

    def set_status(self, message: str) -> None:
        """Update existing notice and keep a durable diagnostic in the ordinary log."""
        if self.window is not None:
            self.status.set(message)
        self.app.write_log(message, "system")

    def run_worker(self, operation: Callable[[], None]) -> None:
        """Run one updater phase off Tk, with no GUI calls from the worker."""
        self.busy = True
        def run() -> None:
            """Report every phase outcome through the UI queue."""
            try:
                operation()
            except Exception as exc:
                self.events.put(("error", str(exc)))
            finally:
                self.events.put(("done", None))
        self.worker = threading.Thread(target=run, name="PHPGalleryUpdate", daemon=True)
        self.worker.start()

    def check(self, automatic: bool = False) -> None:
        """Reserve an hourly check before network I/O, independent of credential config."""
        if self.app.exiting or self.close_pending or self.gate.active:
            return
        if not automatic:
            self.show()
        if self.busy:
            return
        now = time.time()
        if automatic and not check_due(self.state_path, now):
            if self.cached_offer_loaded:
                return
            self.cached_offer_loaded = True
            try:
                payload = read_update_state(self.state_path)
                if payload.get("version") == self.version and payload.get("available"):
                    from uploader.self_update import validated_release_asset
                    self.events.put(("checked", validated_release_asset(payload["available"], self.version)))
            except (OSError, ValueError, TypeError, KeyError, RuntimeError):
                pass
            return
        self.asset = None
        previous_installer = self.installer
        self.installer = None
        if self.window is not None:
            self.action.configure(state="disabled", text="Update", command=self.download)
        def work() -> None:
            """Reserve the hourly attempt and persist a reusable verified offer."""
            from uploader.self_update import find_update, retire_download_session
            if previous_installer is not None:
                retire_download_session(previous_installer.parent, self.state_path.parent)
            atomic_write_json(self.state_path, {"last_check": now})
            asset = find_update(self.version)
            atomic_write_json(self.state_path, {"last_check": now, "version": self.version, "available": asdict(asset) if asset else None})
            self.events.put(("checked", asset))
        self.run_worker(work)

    def download(self) -> None:
        """Download while upload workers remain active; installation is a later phase."""
        if self.busy or self.asset is None or self.gate.active:
            return
        self.cancel.clear()
        self.action.configure(state="disabled")
        self.cancel_button.configure(state="normal")
        self.set_status("Downloading installer...")
        asset = self.asset
        def work() -> None:
            """Own a local download session and remove it after cancellation/failure."""
            from uploader.self_update import (download_update, verify_installer, create_download_session,
                                              cleanup_old_download_sessions, retire_download_session, UpdateCancelled)
            cleanup_old_download_sessions(self.state_path.parent, time.time())
            directory = create_download_session(self.state_path.parent)
            def progress(done: int, total: int) -> None:
                """Queue actual streamed bytes without touching Tk."""
                self.events.put(("progress", (done, total)))
            try:
                path = download_update(asset, directory, progress, self.cancel)
                self.events.put(("verifying", None))
                verify_installer(path, asset)
                if self.cancel.is_set():
                    raise UpdateCancelled("Update download canceled before installation.")
                self.events.put(("downloaded", path))
            except Exception:
                retire_download_session(directory, self.state_path.parent)
                raise
        self.run_worker(work)

    def blockers(self) -> list[str]:
        """Include pending callbacks, all work threads and thumbnail child processes."""
        result = []
        for attr in ("connection_test_running", "api_key_revoke_running", "semantic_ai_install_running"):
            if getattr(self.app, attr, False):
                result.append(attr)
        ignored = (threading.main_thread(), getattr(self.app, "tray_thread", None), self.worker)
        for thread in threading.enumerate():
            if thread not in ignored and thread.is_alive():
                result.append(thread.name)
        for process in multiprocessing.active_children():
            if process.is_alive():
                result.append(process.name)
        return result

    def install(self) -> None:
        """Close admission synchronously, then let existing operations finish safely."""
        if self.busy or self.installer is None or self.asset is None or self.gate.active:
            return
        from uploader.update_helper import installed_identity
        try:
            installed_identity()
        except (OSError, RuntimeError) as exc:
            self.set_status(str(exc) + " Open the downloaded installer manually to install this application.")
            self.app.open_local_path(self.installer)
            return
        self.gate.begin(time.monotonic())
        self.action.configure(state="disabled")
        self.set_status("Preparing installation: stopping new work and waiting for active operations...")
        for attr in ("preflight_worker", "worker", "manual_worker", "ai_worker"):
            worker = getattr(self.app, attr, None)
            if worker is not None and worker.is_alive():
                worker.stop()
        self.app.ai_worker_stop_requested = True
        self.root.after(100, self.drain)

    def drain(self) -> None:
        """Poll instead of joining on Tk; timeout leaves the application open."""
        if not self.gate.active or self.app.exiting:
            return
        blockers = self.blockers()
        state = self.gate.poll(time.monotonic(), blockers)
        if state == "waiting":
            self.status.set("Waiting for active work to finish: " + ", ".join(blockers))
            self.root.after(200, self.drain)
        elif state == "timeout":
            self.set_status("Installation refused: active operations did not finish in time. The app remains open; retry when work has stopped.")
            self.action.configure(state="normal")
        elif state == "ready":
            self.set_status("Preparing the external update helper...")
            self.run_worker(self.handoff)

    def handoff(self) -> None:
        """Require the external helper's readiness acknowledgment before app exit."""
        from uploader.update_helper import prepare_helper, launch_helper
        ticket = prepare_helper(self.installer, self.asset.version, self.asset.sha256, self.asset.size)
        launch_helper(ticket)
        if self.installer is not None:
            from uploader.self_update import retire_download_session
            try:
                retire_download_session(self.installer.parent, self.state_path.parent)
            except (OSError, RuntimeError):
                # A refused cleanup never revokes an already-ready helper handoff.
                pass
        self.events.put(("handoff_ready", None))

    def poll(self) -> None:
        """Apply queued phase transitions on Tk only."""
        if self.app.exiting:
            return
        while True:
            try:
                kind, value = self.events.get_nowait()
            except queue.Empty:
                break
            if kind == "checked":
                self.asset = value
                if value is not None:
                    self.show()
                    if getattr(sys, "frozen", False):
                        self.set_status(f"Version {value.version} is available (installed: {self.version}). Update downloads and verifies Setup, safely stops work, closes this app and restarts it after installation. Windows may request administrator approval.")
                    else:
                        self.set_status(f"Version {value.version} is available (current: {self.version}). Update downloads and verifies Setup for manual installation; this source folder remains intact.")
                    self.action.configure(state="normal")
                elif self.window is not None:
                    self.set_status("No newer Windows uploader installer is available.")
            elif kind == "progress":
                done, total = value
                self.progress["value"] = 100 * done / total if total else 0
                self.status.set(f"Downloading installer: {done:,} / {total:,} bytes")
            elif kind == "verifying":
                self.cancel_button.configure(state="disabled")
                self.set_status("Verifying installer size and SHA-256...")
            elif kind == "downloaded":
                if self.cancel.is_set() or self.close_pending:
                    from uploader.self_update import retire_download_session
                    try:
                        retire_download_session(value.parent, self.state_path.parent)
                    except (OSError, RuntimeError) as exc:
                        self.app.write_log("Update cleanup refused: " + str(exc), "warning")
                    self.auto_install = False
                    self.set_status("Update canceled before installation.")
                    self.action.configure(state="normal", text="Update", command=self.download)
                    self.cancel_button.configure(state="disabled")
                    continue
                self.installer = value
                if getattr(sys, "frozen", False):
                    self.set_status("Installer verified. Installation stops active work, waits for completion, and closes the app. Windows may request administrator approval.")
                    self.auto_install = True
                else:
                    self.set_status("Installer verified. Open installer starts manual installation; this source application and source folder remain open and intact.")
                    self.action.configure(text="Open installer", command=self.install, state="normal")
                self.cancel_button.configure(state="disabled")
            elif kind == "error":
                self.gate.cancel()
                self.set_status("Update stopped: " + value)
                if self.window is not None:
                    self.action.configure(state="normal" if self.asset else "disabled")
                    self.cancel_button.configure(state="disabled")
            elif kind == "done":
                self.busy = False
                if self.close_pending:
                    self.close_pending = False
                    self.app.close()
                    return
                if self.auto_install and not self.cancel.is_set():
                    self.auto_install = False
                    self.install()
            elif kind == "handoff_ready":
                self.app.exiting = True
                self.app.stop_tray_icon()
                self.root.destroy()
                return
        self.root.after(200, self.poll)
