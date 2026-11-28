# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/ui/ports.py
# Module Type: Flight UI ports and data
# Purpose: Own narrow GUI, worker and linked-target contracts.
# Responsibilities: Define shared flight interface types and adapters.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Typed ports and data contracts for FlightUI."""

import queue
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Literal, Protocol
import tkinter as tk

from uploader.flight_jobs import SessionPlan
from uploader.game_presence import GamePresence
from uploader.flight_workflow import FinalizationWorker, WatchedReportTarget
from uploader.models import ImportJob, JsonValue
from uploader.mode_coordinator import GuiModeOwner, ModeConfigPort
from uploader.state_store import JobStateStore

SessionState = Literal["idle", "active", "working", "pending", "blocked"]

class RevocationTransport(Protocol):
    """Describe scoped revocation with a checkpoint before every HTTP attempt."""

    def __call__(
        self, upload_url: str, api_key: str, *,
        before_request: Callable[[], None] | None = None,
    ) -> dict[str, JsonValue]:
        """Revoke the captured key using its original admitted configuration.

        Args:
            upload_url (str): Canonical captured automation endpoint.
            api_key (str): Captured gallery-scoped credential, never logged.
            before_request (Callable[[], None] | None): Revalidate work before
                every route/retry request, or None for established direct callers.
        Returns:
            dict[str, JsonValue]: Positive ok/action=revoke result and optional
                message/reconciled_after_gateway_error fields.
        """
        ...

class ConnectionConfig(Protocol):
    """Expose only saved connection and simulator settings to the adapter."""

    gallery_url: str
    api_key: str
    simconnect_dll_path: str

class ControlledWorker(Protocol):
    """Describe a worker whose existing lifecycle admits cancellation and joins."""

    def is_alive(self) -> bool:
        """Report whether a worker can still act.

        Returns:
            bool: True while its thread/native connection is active.
        """
        ...

    def stop(self) -> None:
        """Stop admission using the worker's established cancellation policy.

        Returns:
            None: Requests a safe stop.
        """
        ...

class LinkedFlightTarget(Protocol):
    """Expose only the approved gallery identity needed by flight recording."""

    issuer: str
    gallery_id: int

    def binding(self) -> str:
        """Return this issuer, account and gallery's non-secret fingerprint.

        Returns:
            str: Stable SHA-256 target binding without bearer material.
        """
        ...

class LinkedFlightManager(Protocol):
    """Describe linked-target admission independently from legacy settings."""

    def linked_upload_mode_selected(self) -> bool:
        """Report the user's selected mode even after its session expires.

        Returns:
            bool: True while linked upload remains the selected mode.
        """
        ...

    def linked_upload_binding(self) -> str | None:
        """Identify the selected target without requiring a live session.

        Returns:
            str | None: Non-secret target binding, or None if not selected.
        """
        ...

    def selected_upload_target(self) -> LinkedFlightTarget | None:
        """Return a live approved target only while its session is usable.

        Returns:
            LinkedFlightTarget | None: Current approved target, or None.
        """
        ...

class FlightHost(Protocol):
    """Describe the existing app properties consumed by this controller."""

    root: tk.Tk
    config: ConnectionConfig
    events: queue.Queue[object]
    worker: ControlledWorker | None
    manual_worker: ControlledWorker | None
    preflight_worker: ControlledWorker | None
    ai_worker: ControlledWorker | None
    utc_sync: ControlledWorker
    current_job: ImportJob | None
    job_store: JobStateStore
    connection_test_running: bool
    semantic_ai_install_running: bool
    ai_worker_stop_requested: bool
    api_key_revoke_running: bool
    api_key_revoke_thread: threading.Thread | None
    status_var: tk.StringVar
    watcher_activity_var: tk.StringVar
    gallery_label_var: tk.StringVar
    simulator_presence: GamePresence
    linking_manager: LinkedFlightManager

    def capture_flight_admission(
        self,
    ) -> tuple[GuiModeOwner, ModeConfigPort, str, str]:
        """Capture the exact saved legacy connection for a flight worker.

        Returns:
            tuple[GuiModeOwner, ModeConfigPort, str, str]: Shared owner, sealed
                config port, canonical endpoint and associated API key.
        """
        ...

    def watched_report_target(self) -> WatchedReportTarget | None:
        """Capture the active Watcher's exact report destination.

        Returns:
            WatchedReportTarget | None: Active gallery authority, or None
                while the Watcher is stopped, preparing or draining.
        """
        ...

    def complete_legacy_revocation(self, operation: FinalizationWorker) -> None:
        """Acknowledge only the captured operation's server-confirmed cleanup.

        Args:
            operation (FinalizationWorker): Exact worker retaining revoke work
                and the captured endpoint/key/config-generation identity.
        Returns:
            None: Performs the atomic sole-work to configuration-save handoff.
        """
        ...

    def api_key_request_blockers(self) -> list[str]:
        """List existing authenticated workers without adding this coordinator.

        Returns:
            list[str]: Concise active-worker descriptions.
        """
        ...

    def refresh_connection_controls(self) -> None:
        """Refresh existing connection controls on the Tk thread.

        Returns:
            None: Refreshes the visible saved-connection state.
        """
        ...

    def reconnect_upload_gallery(self) -> None:
        """Open browser linking or the existing gallery picker for recovery.

        Returns:
            None: Offers the next explicit step to restore linked uploads.
        """
        ...

    def restore_capture_upload_target(
        self, binding: str, on_result: Callable[[bool], None],
    ) -> None:
        """Verify the retained capture's original upload authority.

        Args:
            binding (str): Fingerprint of the capture's original destination.
            on_result (Callable[[bool], None]): Tk callback receiving whether
                the same destination is ready to use.
        Returns:
            None: Publishes the result through the existing connection owner.
        """
        ...

    def watcher_reset_blocked(self) -> bool:
        """Report whether Watcher-only actions are fenced by a factory reset.

        Returns:
            bool: True while the local reset is pending or unverified.
        """
        ...

    def close_retained_watch(
        self, plan: SessionPlan, on_result: Callable[[bool], None],
    ) -> None:
        """Close one explicitly selected retained capture locally.

        Args:
            plan (SessionPlan): Matching unfinished plan the user reviewed.
            on_result (Callable[[bool], None]): Tk callback receiving whether
                the journal and plan closure were durably confirmed.
        Returns:
            None: Schedules or safely refuses the confirmed local change.
        """
        ...

    def resume_flight_photos(
        self, directory: Path, *, continue_retained: bool = False,
    ) -> threading.Thread | None:
        """Restore the exact worker for an explicitly continued queue.

        Args:
            directory (Path): Owned flight session workspace.
            continue_retained (bool): Release a saved interruption for safe work.
        Returns:
            threading.Thread | None: Original queue's new worker, or None when
                it has no unfinished photograph work.
        """
        ...

    def forget_flight_connection(self, binding: str) -> None:
        """Durably remove only the explicitly abandoned saved connection's key.

        Args:
            binding (str): Captured endpoint/key fingerprint, never a raw key.
        Returns:
            None: Clears the local key and connection display without networking.
        Raises:
            OSError: The updated configuration cannot be persisted.
            ValueError: The saved connection changed before cleanup.
        """
        ...

    def open_local_path(self, path: Path) -> None:
        """Open a local artifact folder through the existing desktop owner.

        Args:
            path (Path): Owned session/report directory to show.

        Returns:
            None: Starts the platform's ordinary open action.
        """
        ...

    def open_log_file(self) -> None:
        """Open the existing application log through its desktop owner.

        Returns:
            None: Shows the existing rotating log; starts no watcher instance.
        """
        ...

@dataclass(frozen=True)
class RetainedWatchCapture:
    """Describe the next local capture offered for explicit recovery."""

    plan: SessionPlan
    session_count: int
    photo_count: int | None
    head_name: str
