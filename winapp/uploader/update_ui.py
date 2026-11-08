# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Coordinate nonmodal self-update UI and external installer handoff.
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
import os
from collections import deque
from pathlib import Path
import queue
from dataclasses import asdict
import sys
import subprocess
import threading
import time
from typing import Callable, Optional, Protocol, TYPE_CHECKING, cast

from uploader.state_store import atomic_write_json
from uploader.self_update import (
    ReleaseAsset,
    UpdateCancelled,
    UpdateDiagnostic,
    UpdateError,
    capture_update_diagnostics,
    format_update_diagnostic,
    report_update_diagnostic,
)

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

    def close(self, *, for_update: bool = False) -> None:
        """Close the application, immediately after verified update handoff.

        Args:
            for_update (bool): Terminate the app after helper readiness when True.
        Returns:
            None: Closes normally or exits the process for installation.
        """
        ...

    def set_update_available(self, version: Optional[str]) -> None:
        """Persist the visible version indicator, or clear it after a clean check.

        Args:
            version (Optional[str]): Offered release version, or None to clear.
        Returns:
            None: Updates the application's persistent header and tray state.
        """
        ...

    def prepare_update_checkpoint(self, target_version: str) -> None:
        """Pause new dispatch without interrupting a returning upload.

        Args:
            target_version (str): Verified installer version selected by the user.

        Returns:
            None: Captures existing work and requests a safe pause on Tk.
        """
        ...

    def wait_for_update_checkpoint(self) -> None:
        """Wait off Tk for acknowledged work and durable continuation state.

        Returns:
            None: Existing workers released ownership and state is durable.
        """
        ...

    def bind_update_checkpoint(self, ticket: Path) -> None:
        """Bind the saved continuation to the helper that owns this update.

        Args:
            ticket (Path): Already validated private helper ticket.

        Returns:
            None: Publishes the helper nonce before installation can proceed.
        """
        ...

    def cancel_update_checkpoint(self) -> None:
        """Resume the same safe paused capture after a handoff failure.

        Returns:
            None: Restores work or leaves its concrete recovery reason visible.
        """
        ...


UPDATE_CHECK_INTERVAL_SECONDS = 3600
# Purpose: Retain enough recent updater phase boundaries to locate a failure.
# Type: int. Units: diagnostic events. Scope: one UpdateController lifetime.
# Consumers: UpdateController._diagnostic_recent.
# Rationale: Keep copied support data useful while bounding memory and output.
UPDATE_DIAGNOSTIC_RECENT_LIMIT = 24
# Purpose: Bound how many persisted state bytes are read before cache validation.
# Type: int. Units: bytes. Scope: one updater state file.
# Consumers: read_update_state.
# Rationale: Preserve the existing 16 KiB metadata cap against malformed local data.
UPDATE_STATE_MAX_BYTES = 16384


class InstallGate:
    """Pause new operations only while preparing the external helper."""

    def __init__(self) -> None:
        """Initialize open admission without a worker-completion deadline.

        Returns:
            None: Starts with new work permitted.
        """
        self.active = False

    def begin(self) -> None:
        """Pause new work while the verified installer is handed off.

        Returns:
            None: Marks helper preparation active.
        """
        self.active = True

    def cancel(self) -> None:
        """Restore admission when helper preparation fails.

        Returns:
            None: Leaves the application available for another attempt.
        """
        self.active = False


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
        self._diagnostic_phase = 'update'
        self._diagnostic_latest: UpdateDiagnostic | None = None
        self._diagnostic_last_failure: UpdateDiagnostic | None = None
        self._diagnostic_recent: deque[UpdateDiagnostic] = deque(
            maxlen=UPDATE_DIAGNOSTIC_RECENT_LIMIT,
        )
        local = Path(os.environ.get("LOCALAPPDATA", str(Path.home() / "AppData" / "Local")))
        self.state_path = local / "PHPGalleryUploader" / "updates" / "state.json"
        try:
            previous = read_update_state(self.state_path).get('version', '')
            self.previous_version = previous if isinstance(previous, str) else ''
        except (OSError, ValueError, TypeError):
            self.previous_version = ''
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
                self.status.set(
                    "Installer verified.\n"
                    "Update closes, installs and restarts the app."
                )
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
                "Update downloads and verifies Setup, closes this app "
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

    def _queue_diagnostic(
        self, phase: str, stage: str, outcome: str, *,
        category: str | None = None, error: BaseException | None = None,
        source: str,
    ) -> None:
        """Queue one safe diagnostic for a failure observed on the Tk thread.

        Args:
            phase (str): Fixed updater operation label.
            stage (str): Fixed owner step label.
            outcome (str): Fixed result such as failed or cancelled.
            category (str | None): Fixed safe category, when known.
            error (BaseException | None): Exception classified without text.
            source (str): Fixed owner method label.

        Returns:
            None: The ordinary poller receives an immutable safe event.
        """
        started = time.monotonic()
        with capture_update_diagnostics(
            lambda diagnostic: self.events.put(('diagnostic', diagnostic)),
        ):
            report_update_diagnostic(
                phase, stage, 'local', 'local', outcome,
                category=category, error=error, source=source, started=started,
            )

    def _retain_diagnostic(self, diagnostic: UpdateDiagnostic) -> None:
        """Keep bounded safe updater evidence for the copy-diagnostics action.

        Args:
            diagnostic (UpdateDiagnostic): Already sanitized immutable phase data.

        Returns:
            None: Latest, last failed, and recent events are retained in memory.
        """
        recent = getattr(self, '_diagnostic_recent', None)
        if not isinstance(recent, deque):
            recent = deque(maxlen=UPDATE_DIAGNOSTIC_RECENT_LIMIT)
            self._diagnostic_recent = recent
        self._diagnostic_latest = diagnostic
        recent.append(diagnostic)
        if diagnostic.outcome == 'failed':
            self._diagnostic_last_failure = diagnostic

    def diagnostics_snapshot(self) -> str:
        """Return bounded updater history without URLs, paths, or exception text.

        Returns:
            str: Latest event, last failure, and recent sanitized phase events;
                an empty-history message when no updater event has occurred.
        """
        latest = getattr(self, '_diagnostic_latest', None)
        if latest is None:
            return 'No updater diagnostic event recorded.'
        last_failure = getattr(self, '_diagnostic_last_failure', None)
        recent = tuple(getattr(self, '_diagnostic_recent', ()))
        lines = ['Latest: ' + format_update_diagnostic(latest)]
        if last_failure is not None:
            lines.append(
                'Last failure: ' + format_update_diagnostic(last_failure),
            )
        lines.append('Recent phases:')
        lines.extend(
            '  ' + format_update_diagnostic(item) for item in recent
        )
        return '\n'.join(lines)

    def run_worker(self, operation: Callable[[], None]) -> None:
        """Run one updater phase off Tk and queue safe diagnostics.

        Args:
            operation (Callable[[], None]): Existing updater operation executed
                by a daemon worker without direct Tk calls.

        Returns:
            None: Queues bounded diagnostics, a safe status result and completion.
        """
        self.busy = True
        def run() -> None:
            """Capture one operation's safe diagnostics before its error is lost.

            Returns:
                None: Queues all events for processing by the Tk poller.
            """
            phase = getattr(self, '_diagnostic_phase', 'update')
            started = time.monotonic()
            with capture_update_diagnostics(
                lambda diagnostic: self.events.put(('diagnostic', diagnostic)),
            ):
                report_update_diagnostic(
                    phase, 'worker_operation', 'local', 'local', 'started',
                    source='update_ui.UpdateController.run_worker',
                    started=started,
                )
                try:
                    operation()
                except Exception as exc:
                    cancelled = isinstance(exc, UpdateCancelled)
                    report_update_diagnostic(
                        phase, 'worker_operation', 'local', 'local',
                        'cancelled' if cancelled else 'failed',
                        category=(
                            'cancelled' if cancelled else
                            'transport_failure' if phase in {'discovery', 'download'}
                            and type(exc) not in {UpdateError, UpdateCancelled} else None
                        ),
                        error=exc,
                        source='update_ui.UpdateController.run_worker',
                        started=started,
                    )
                    message = (
                        str(exc) if type(exc) in {UpdateError, UpdateCancelled}
                        else 'Update operation failed. See diagnostics.'
                    )
                    self.events.put(('error', message))
                else:
                    report_update_diagnostic(
                        phase, 'worker_operation', 'local', 'local', 'succeeded',
                        source='update_ui.UpdateController.run_worker',
                        started=started,
                    )
                finally:
                    self.events.put(('done', None))
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
        self._diagnostic_phase = 'discovery'
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
                    self._queue_diagnostic(
                        'cleanup', 'previous_installer_cleanup', 'failed',
                        category='cleanup_refused', error=exc,
                        source='update_ui.UpdateController.check',
                    )
                    self.events.put(("cleanup_warning", None))
            self.events.put(("checked", asset))
        self.run_worker(work)

    def download(self) -> None:
        """Download in a worker while upload workers remain active.

        Returns:
            None: Schedules the download and verification phase, or leaves the
                current offer unchanged when another operation owns the gate.
        """
        if self.busy or self.asset is None or self.gate.active:
            return
        self.cancel.clear()
        self.action.configure(state="disabled")
        self.cancel_button.configure(state="normal")
        self.set_status("Downloading installer...")
        self._diagnostic_phase = 'download'
        asset = self.asset
        def work() -> None:
            """Own a local download session and retire it after failure.

            Returns:
                None: Queues a verified installer or re-raises the primary
                    download failure after best-effort safe cleanup.
            """
            from uploader.self_update import (download_update, verify_installer, create_download_session,
                                              cleanup_old_download_sessions, retire_download_session, UpdateCancelled)
            cleanup_old_download_sessions(self.state_path.parent, time.time())
            directory = create_download_session(self.state_path.parent)
            def progress(done: int, total: int) -> None:
                """Queue actual streamed byte progress without touching Tk.

                Args:
                    done (int): Bytes written and hashed so far.
                    total (int): Published installer size in bytes.

                Returns:
                    None: Appends a progress event for the Tk poller.
                """
                self.events.put(("progress", (done, total)))
            try:
                path = download_update(asset, directory, progress, self.cancel)
                self.events.put(("verifying", None))
                verify_installer(path, asset)
                if self.cancel.is_set():
                    raise UpdateCancelled("Update download canceled before installation.")
                self.events.put(("downloaded", path))
            except Exception:
                try:
                    retire_download_session(directory, self.state_path.parent)
                except (OSError, RuntimeError) as cleanup_error:
                    self._queue_diagnostic(
                        'cleanup', 'download_session_cleanup', 'failed',
                        category='cleanup_refused', error=cleanup_error,
                        source='update_ui.UpdateController.download',
                    )
                raise
        self.run_worker(work)

    def install(self) -> None:
        """Prepare accepted work before handing off the verified installer.

        Returns:
            None: Starts the bounded checkpoint/helper handoff, postpones
                unsafe work, or opens a source-mode installer manually.
        """
        if self.busy or self.installer is None or self.asset is None or self.gate.active:
            return
        from uploader.update_helper import installed_identity
        try:
            installed_identity()
        except (OSError, RuntimeError) as exc:
            self._queue_diagnostic(
                'handoff', 'installed_identity', 'failed',
                category='checkpoint_refused', error=exc,
                source='update_ui.UpdateController.install',
            )
            self.set_status(
                "The installed application could not be verified. "
                "Open the downloaded installer manually to install this application."
            )
            self.app.open_local_path(self.installer)
            return
        self.gate.begin()
        self.action.configure(state="disabled")
        try:
            self.app.prepare_update_checkpoint(self.asset.version)
        except (OSError, ValueError, RuntimeError) as exc:
            self._queue_diagnostic(
                'handoff', 'install_gate', 'failed',
                category='checkpoint_refused', error=exc,
                source='update_ui.UpdateController.install',
            )
            self.gate.cancel()
            try:
                # The app hook may have durably prepared a continuation before
                # a later checkpoint step refused; always request its recovery.
                self.app.cancel_update_checkpoint()
            except (OSError, ValueError, RuntimeError) as cancel_error:
                self._queue_diagnostic(
                    'handoff', 'install_gate', 'failed',
                    category='checkpoint_refused', error=cancel_error,
                    source='update_ui.UpdateController.install',
                )
                self.app.write_log(
                    "Update continuation cancellation needs review.", "warning",
                )
            self.set_status(
                "Update postponed: current work could not be safely checkpointed. "
                "The application remains open; see diagnostics.",
            )
            self.action.configure(state="normal")
            return
        self.set_status("Finishing current uploads and saving Watcher state…")
        self._diagnostic_phase = 'handoff'
        self.run_worker(self.handoff)

    def handoff(self) -> None:
        """Checkpoint accepted work before the helper may install or exit.

        Returns:
            None: Queues helper readiness only after a durable exact-session
                checkpoint and its helper binding; errors retain the app.
        """
        from uploader.update_helper import prepare_helper, launch_helper
        self._run_handoff_step(
            'checkpoint_wait', self.app.wait_for_update_checkpoint,
        )
        ticket = cast(
            Path,
            self._run_handoff_step(
                'helper_prepare',
                lambda: prepare_helper(
                    self.installer, self.asset.version, self.asset.sha256,
                    self.asset.size,
                ),
            ),
        )
        self._run_handoff_step(
            'helper_bind', lambda: self.app.bind_update_checkpoint(ticket),
        )
        self._run_handoff_step('helper_launch', lambda: launch_helper(ticket))
        if self.installer is not None:
            from uploader.self_update import retire_download_session
            try:
                retire_download_session(self.installer.parent, self.state_path.parent)
            except (OSError, RuntimeError) as exc:
                # A refused cleanup never revokes an already-ready helper handoff.
                self._queue_diagnostic(
                    'cleanup', 'download_session_cleanup', 'failed',
                    category='cleanup_refused', error=exc,
                    source='update_ui.UpdateController.handoff',
                )
        self.events.put(("handoff_ready", None))

    def _run_handoff_step(
        self, stage: str,
        operation: Callable[[], Path | subprocess.Popen[bytes] | None],
    ) -> Path | subprocess.Popen[bytes] | None:
        """Run one fixed helper handoff step with measured safe diagnostics.

        Args:
            stage (str): Fixed handoff stage from the controller's allowlist.
            operation (Callable[[], Path | subprocess.Popen[bytes] | None]):
                Existing step callback; preparation returns its ticket path,
                launch returns a byte-stream subprocess, and wait/bind return
                None.

        Returns:
            Path | subprocess.Popen[bytes] | None: The callback's actual result,
                preserving its original return value.

        Raises:
            Exception: Re-raises the operation failure after queuing safe facts.
        """
        started = time.monotonic()
        report_update_diagnostic(
            'handoff', stage, 'local', 'local', 'started',
            source='update_ui.UpdateController.handoff', started=started,
        )
        try:
            result = operation()
        except Exception as exc:
            report_update_diagnostic(
                'handoff', stage, 'local', 'local', 'failed', error=exc,
                source='update_ui.UpdateController.handoff', started=started,
            )
            raise
        report_update_diagnostic(
            'handoff', stage, 'local', 'local', 'succeeded',
            source='update_ui.UpdateController.handoff', started=started,
        )
        return result

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
                        self._queue_diagnostic(
                            'cleanup', 'download_session_cleanup', 'failed',
                            category='cleanup_refused', error=exc,
                            source='update_ui.UpdateController.poll',
                        )
                        self.app.write_log(
                            "Update cleanup refused; see updater diagnostics.",
                            "warning",
                        )
                    self.auto_install = False
                    self.set_status("Update canceled before installation.")
                    self.action.configure(state="normal", text="Update", command=self.download)
                    self.cancel_button.configure(state="disabled")
                    continue
                self.installer = value
                if getattr(sys, "frozen", False):
                    self.set_status(
                        "Installer verified.\n"
                        "Update closes, installs and restarts the app.\n"
                        "Windows may request administrator approval."
                    )
                    self.auto_install = True
                else:
                    self.set_status("Installer verified. Open installer starts manual installation; this source application and source folder remain open and intact.")
                    self.action.configure(text="Open installer", command=self.install, state="normal")
                self.cancel_button.configure(state="disabled")
            elif kind == "error":
                self.gate.cancel()
                try:
                    self.app.cancel_update_checkpoint()
                except (OSError, ValueError, RuntimeError) as exc:
                    self._queue_diagnostic(
                        'cleanup', 'checkpoint_cancel', 'failed',
                        category='checkpoint_refused', error=exc,
                        source='update_ui.UpdateController.poll',
                    )
                    self.app.write_log(
                        "Update checkpoint cleanup needs review; see updater diagnostics.",
                        "warning",
                    )
                self.set_status("Update stopped: " + value)
                if self.window is not None:
                    self.action.configure(
                        state="disabled" if self.busy else "normal" if self.asset else "disabled"
                    )
                    self.cancel_button.configure(state="disabled")
            elif kind == "cleanup_warning":
                self.app.write_log(
                    "Previous installer cleanup refused; see updater diagnostics.",
                    "warning",
                )
            elif kind == 'diagnostic' and isinstance(value, UpdateDiagnostic):
                self._retain_diagnostic(value)
                level = 'warning' if value.outcome in {'failed', 'cancelled'} else 'info'
                self.app.write_log(format_update_diagnostic(value), level)
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
                self.app.close(for_update=True)
                return
        self.root.after(200, self.poll)
