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

    def set_update_available(self, version: Optional[str]) -> None:
        """Persist the visible version indicator, or clear it after a clean check.

        Args:
            version (Optional[str]): Offered release version, or None to clear.
        Returns:
            None: Updates the application's persistent header and tray state.
        """
        ...


UPDATE_CHECK_INTERVAL_SECONDS = 3600
# Purpose: Bound how many persisted state bytes are read before cache validation.
# Type: int. Units: bytes. Scope: one updater state file.
# Consumers: read_update_state.
# Rationale: Preserve the existing 16 KiB metadata cap against malformed local data.
UPDATE_STATE_MAX_BYTES = 16384


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
    """Read a bounded cache object without allowing oversized local state input.

    Args:
        state_path (Path): Path to the updater's persisted metadata object.
    Returns:
        dict[str, object]: JSON object whose known fields are `last_check` (number
            or numeric string), `version` (string), and `available` (None or a
            mapping with ReleaseAsset fields `version`, `name`, `download_url`,
            `size`, `sha256`, `asset_id`, and `release_id`). Additional fields,
            when present, contain JSON null, boolean, number, string, list, or map.
    """
    with state_path.open("rb") as stream:
        data = stream.read(UPDATE_STATE_MAX_BYTES + 1)
    if len(data) > UPDATE_STATE_MAX_BYTES:
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
        """Attach background phase scheduling to an existing Tk application.

        Args:
            app (UpdateApplication): Host UI, callbacks, and shutdown state owner.
            version (str): Current uploader version used for release validation.
        Returns:
            None: Initializes worker coordination and schedules Tk polling.
        """
        self.app = app
        self.root = app.root
        self.version = version
        self.gate = InstallGate()
        self.events: "queue.Queue[tuple[str, object]]" = queue.Queue()
        self.cancel = threading.Event()
        self.busy = False
        self.close_pending = False
        self.auto_install = False
        self.check_result_pending = False
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
        """Restore a verified cached offer, then check at startup and hourly.

        Returns:
            None: Restores or schedules updater state and its next cadence callback.
        """
        if self.app.exiting or self.close_pending:
            return
        if not self.cached_offer_loaded and not self.busy and not self.gate.active:
            self.restore_cached_offer()
        self.check(automatic=True)
        self.root.after(60000, self.automatic_check)

    def restore_cached_offer(self) -> None:
        """Restore only a cached offer that passes the current release validator.

        Returns:
            None: Publishes a verified current-version offer or clears stale state.
        """
        if self.busy or self.gate.active:
            return
        self.cached_offer_loaded = True
        try:
            payload = read_update_state(self.state_path)
        except (OSError, ValueError, TypeError):
            return
        if payload.get("version") != self.version:
            if payload.get("available") is not None:
                try:
                    atomic_write_json(self.state_path, {
                        "last_check": payload.get("last_check"),
                        "version": self.version,
                        "available": None,
                    })
                except OSError:
                    pass
            self.asset = None
            self.app.set_update_available(None)
            return
        available = payload.get("available")
        if available is None:
            self.asset = None
            self.app.set_update_available(None)
            return
        try:
            from uploader.self_update import validated_release_asset
            asset = validated_release_asset(available, self.version)
        except (ValueError, TypeError, KeyError, RuntimeError):
            self.asset = None
            self.app.set_update_available(None)
            return
        self.asset = asset
        self.app.set_update_available(asset.version)
        if self.window is not None:
            self._restore_notice_state()

    def request_close(self) -> bool:
        """Decide whether shutdown may proceed or must wait for updater work.

        Returns:
            bool: True when shutdown must wait or installation is gated; otherwise
                False so the caller can continue its remaining shutdown checks.
                This decision does not change the update indicator.
        """
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
        """Show a reusable, nonmodal notice without a grab or confirmation dialog.

        Returns:
            None: Raises the current notice or creates one sized to its current
                text and controls, including later background status changes.
        """
        import tkinter as tk
        from tkinter import ttk
        from uploader.ui_theme import place_dialog
        if self.window is not None and self.window.winfo_exists():
            self.window.deiconify()
            self.window.lift()
            return
        self.window = tk.Toplevel(self.root)
        self.window.withdraw()
        self.window.title("PHP Gallery Uploader updates")
        self.window.protocol("WM_DELETE_WINDOW", self.later)
        self.status = tk.StringVar(value="Checking for updates...")
        ttk.Label(
            self.window,
            textvariable=self.status,
            style="App.TLabel",
            justify="left",
            wraplength=470,
        ).pack(fill="x", padx=18, pady=14)
        self.progress = ttk.Progressbar(self.window, length=450, maximum=100)
        self.progress.pack(padx=18, pady=8)
        buttons = ttk.Frame(self.window, style="App.TFrame")
        buttons.pack(pady=12)
        self.action = ttk.Button(
            buttons,
            text="Update",
            command=self.download,
            state="disabled",
            style="Primary.TButton",
        )
        self.action.pack(side="left", padx=5)
        self.cancel_button = ttk.Button(buttons, text="Cancel download", command=self.cancel.set, state="disabled")
        self.cancel_button.pack(side="left", padx=5)
        ttk.Button(buttons, text="Later", command=self.later).pack(side="left", padx=5)
        self._restore_notice_state()
        place_dialog(self.window, self.root)
        # Center once, then let Tk fit the wrapped status and complete button
        # row when the asynchronous check replaces the short checking message.
        self.window.geometry("")
        self.window.deiconify()

    def _restore_notice_state(self) -> None:
        """Render persisted offer, installer, or installation-gate state in a new notice.

        Returns:
            None: Enables only the action supported by the restored coordinator state.
        """
        if self.status is None or self.action is None:
            return
        if self.gate.active:
            self.status.set("Preparing installation: stopping new work and waiting for active operations...")
            self.action.configure(state="disabled")
            return
        if self.busy:
            self.status.set("An update operation is still in progress.")
            self.action.configure(state="disabled")
            return
        if self.installer is not None:
            if getattr(sys, "frozen", False):
                self.status.set("Installer verified. Installation stops active work and closes the app after completion.")
                self.action.configure(state="normal", text="Update", command=self.install)
            else:
                self.status.set("Installer verified. Open installer starts manual installation; this source application remains open.")
                self.action.configure(state="normal", text="Open installer", command=self.install)
            return
        if self.asset is not None:
            self.status.set(self._offer_status())
            self.action.configure(state="normal", text="Update", command=self.download)

    def _offer_status(self) -> str:
        """Build the mode-specific text for a validated available release.

        Returns:
            str: User-facing offer status for frozen or source-mode operation.
        """
        if getattr(sys, "frozen", False):
            return (
                f"Version {self.asset.version} is available (installed: {self.version}). "
                "Update downloads and verifies Setup, safely stops work, closes this app "
                "and restarts it after installation. Windows may request administrator approval."
            )
        return (
            f"Version {self.asset.version} is available (current: {self.version}). "
            "Update downloads and verifies Setup for manual installation; this source "
            "folder remains intact."
        )

    def open_updates(self) -> None:
        """Open current updater state or start a manual check when idle.

        Returns:
            None: Displays existing progress/offer or schedules one manual check.
        """
        self.show()
        if (self.busy or self.asset is not None or self.installer is not None
                or self.gate.active):
            return
        self.check(automatic=False)

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
        """Throttle automatic checks and preserve cached offers until success.

        Args:
            automatic (bool): Whether the hourly cadence may suppress this check.
        Returns:
            None: Schedules a network check or leaves current state unchanged.
        """
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
        previous_installer = self.installer
        if self.window is not None:
            if self.asset is None and self.installer is None:
                self.action.configure(state="disabled", text="Update", command=self.download)
        def work() -> None:
            """Reserve the attempt and replace cache only after a successful check.

            Returns:
                None: Queues a validated offer/no-offer result after successful I/O.
            """
            from uploader.self_update import find_update, retire_download_session
            try:
                previous_state = read_update_state(self.state_path)
            except (OSError, ValueError, TypeError):
                previous_state = {}
            reserved_state = dict(previous_state)
            reserved_state["last_check"] = now
            atomic_write_json(self.state_path, reserved_state)
            asset = find_update(self.version)
            atomic_write_json(self.state_path, {"last_check": now, "version": self.version, "available": asdict(asset) if asset else None})
            if previous_installer is not None:
                try:
                    retire_download_session(previous_installer.parent, self.state_path.parent)
                except (OSError, RuntimeError) as exc:
                    # Updater-owned housekeeping cannot revoke a validated check;
                    # refusing unsafe cleanup preserves unknown bytes in the session.
                    self.events.put(("cleanup_warning", str(exc)))
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
        """Close admission, including UTC writes, before draining active work.

        Returns:
            None: Starts guarded drain or opens a source-mode installer.
        """
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
        for attr in (
            "flights", "utc_sync", "preflight_worker", "worker",
            "manual_worker", "ai_worker"
        ):
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
        """Apply queued phase transitions and app-wide indicators on Tk only.

        Returns:
            None: Applies available worker events and schedules the next poll.
        """
        if self.app.exiting:
            return
        while True:
            try:
                kind, value = self.events.get_nowait()
            except queue.Empty:
                break
            if kind == "checked":
                self.asset = value
                self.installer = None
                self.check_result_pending = self.busy
                self.app.set_update_available(value.version if value is not None else None)
                if value is not None:
                    self.show()
                    if not self.busy:
                        self._restore_notice_state()
                    self.app.write_log(self._offer_status(), "system")
                elif self.window is not None:
                    self.set_status("No newer Windows uploader installer is available.")
                    self.action.configure(state="disabled", text="Update", command=self.download)
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
                    self.action.configure(
                        state="disabled" if self.busy else "normal" if self.asset else "disabled"
                    )
                    self.cancel_button.configure(state="disabled")
            elif kind == "cleanup_warning":
                self.app.write_log("Previous installer cleanup refused: " + value, "warning")
            elif kind == "done":
                self.busy = False
                if self.check_result_pending:
                    self.check_result_pending = False
                    if self.asset is not None and not self.gate.active:
                        self._restore_notice_state()
                    elif self.window is not None:
                        self.action.configure(state="disabled", text="Update", command=self.download)
                elif self.installer is not None and not self.gate.active:
                    if self.window is not None:
                        label = "Update" if getattr(sys, "frozen", False) else "Open installer"
                        self.action.configure(
                            state="normal", text=label, command=self.install
                        )
                elif self.asset is not None and self.installer is None and not self.gate.active:
                    # Keep any preceding failure status while making the retained offer usable.
                    if self.window is not None:
                        self.action.configure(state="normal", text="Update", command=self.download)
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
