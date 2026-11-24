# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_ui.py
# Module Type: Flight GUI Controller
# Purpose: Connect watcher lifecycle to durable finalization and progress.
# Responsibilities:
#   - Adapt Tk actions to the shared flight services without blocking Tk.
#   - Show localized phase, task, elapsed and measured transfer feedback.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Compact live readings, saved-flight browsing and independent report actions."""

import queue
import re
import sqlite3
import sys
import threading
import time
from math import ceil
from dataclasses import dataclass, replace
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable, Literal, Protocol
import tkinter as tk
from tkinter import ttk
from uploader import ui_file_picker as filedialog
from uploader.ui_shell import EmbeddedWindow, active_panel, messagebox
from uploader.diagnostics import format_watch_recovery_diagnostic

from uploader.flight_jobs import (
    REPORT_LANGUAGES,
    FlightPreferences,
    ImportBindings,
    SessionPlan,
    SavedFlight,
    ReportRevision,
    ReportFileDetails,
    WorkspaceLease,
    connection_binding,
    pending_sessions,
    read_document,
    report_revisions,
    report_file_label,
    report_file_details,
    organize_reports,
    saved_flights,
)
from uploader.flight_recorder import FlightRecorder
from uploader.game_presence import GamePresence
from uploader.flight_workflow import FinalizationWorker
from uploader.models import ImportJob, JsonValue
from uploader.mode_coordinator import GuiModeOwner, ModeConfigPort
from uploader.state_store import JobStateStore
from uploader.state_store import atomic_write_json
from uploader.watch_queue import WatchQueueStore, WatchRecoveryError
from uploader.flight_simbrief import (
    DispatchPlan, fetch_dispatch, identity_query, import_dispatch, load_dispatch,
)
from uploader.flight_navdata import remember_plan
from uploader.ui_theme import Button, GlassCard, icon_button, place_dialog
from uploader.overlay import OverlayPanel, layout_actions
from uploader.simconnect_flight import (
    METRES_PER_FOOT,
    METRES_PER_SECOND_PER_FPM,
    METRES_PER_SECOND_PER_KNOT,
)

# Update time labels without polling at frame/render frequency.
# Type: int; Units: milliseconds; Scope: the Tk progress adapter.
# Consumers: FlightUI.tick.
# Rationale: 250 ms keeps progress responsive at modest idle CPU use.
PROGRESS_TICK_MS = 250
# Purpose: Retry temporarily unavailable local capture persistence quietly.
# Type: float; Units: seconds; Scope: one interrupted capture generation.
# Consumers: FlightUI._recover_capture, FlightUI.force_finish, FlightUI.tick.
# Rationale: five seconds avoids a hot disk-error loop while retaining the
# original durable guard until publication and readback actually succeed.
CAPTURE_RECOVERY_RETRY_SECONDS = 5.0
# Let background workers sleep between cancellation/deadline checks.
# Type: float; Units: seconds; Scope: finalization drain worker.
# Consumers: FlightUI.wait_for_workers.
# Rationale: 0.1 s gives prompt stop/revoke response without busy-waiting.
DRAIN_POLL_SECONDS = 0.1
# Keep the saved-flight browser narrow while leaving identity details below.
# Type: tuple[int, ...]. Units: pixels. Scope: report catalog columns.
# Consumers: FlightUI.show_reports. Rationale: six compact metadata columns
# retain creation seconds and page count beside a shrinking filename column.
REPORT_COLUMN_WIDTHS = (145, 60, 45, 145, 45, 110)
# Show a short history while the Treeview retains ordinary wheel scrolling.
# Type: int. Units: visible rows. Scope: report catalog window.
# Consumers: FlightUI.show_reports. Rationale: seven flights fit a small dialog.
REPORT_VISIBLE_ROWS = 7
# Purpose: Keep recovery actions inside a compact progress window.
# Type: int; Units: button columns; Scope: finalization progress actions.
# Consumers: FlightUI.refresh_progress_actions.
# Rationale: two compact columns leave room for translated recovery actions.
PROGRESS_ACTION_COLUMNS = 2
# Purpose: Let successful work dismiss itself after a readable completion notice.
# Type: int. Units: seconds. Scope: one successful visible progress generation.
# Consumers: FlightUI.refresh_autoclose. Rationale: three seconds matches the
# requested acknowledgement window; errors and pending work never start it.
SUCCESS_AUTOCLOSE_SECONDS = 3
# Purpose: Show one current edition per language without a large empty table.
# Type: int. Units: rows. Scope: progress task viewport.
# Consumers: FlightUI.show_progress. Rationale: four rows fit the four editions;
# additional saved flights remain reachable through the attached scrollbar.
PROGRESS_TASK_ROWS = 4
# Purpose: Preserve task/status readability as the overlay narrows.
# Type: tuple[int, int]. Units: pixels. Scope: progress task columns.
# Consumers: FlightUI.show_progress. Rationale: 150/180 px share a narrow panel.
PROGRESS_TASK_WIDTHS = (150, 180)
# Purpose: Let the task viewport shrink while preserving an identifiable status.
# Type: tuple[int, int]. Units: pixels. Scope: progress task column minimums.
# Consumers: FlightUI.show_progress. Rationale: 50/100 px leave the footer intact.
PROGRESS_TASK_MIN_WIDTHS = (50, 100)
# Keep identity help and validation readable without a desktop-sized dialog.
# Type: int; Units: pixels; Scope: SimBrief identity sheet.
# Consumers: prompt_simbrief_identity. Rationale: 360 px fits its single input.
SIMBRIEF_PROMPT_WRAP_PX = 360
# Show a normal pilot identity without forcing a very wide control.
# Type: int; Units: character columns; Scope: SimBrief identity entry.
# Consumers: prompt_simbrief_identity. Rationale: 40 columns leaves editing room.
SIMBRIEF_IDENTITY_COLUMNS = 40

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


class FlightUI:
    """Own Tk controls while services own persistence, analysis and network work."""

    def __init__(
        self,
        host: FlightHost,
        directory: Path,
        normalize: Callable[[str], str],
        revoke: RevocationTransport,
        resume_photos: Callable[[Path], threading.Thread | None],
    ) -> None:
        """Restore preferences and exclusive workspace ownership before actions.

        Args:
            host (FlightHost): Existing Tk app and worker lifecycle owner.
            directory (Path): Per-user private flight workspace.
            normalize (Callable[[str], str]): Existing canonical endpoint policy.
            revoke (RevocationTransport): Existing captured URL/key transport
                with request checkpoints and a positive revocation response.
            resume_photos (Callable[[Path], threading.Thread | None]): Restart
                only pending photographs from one durable session queue.

        Returns:
            None: Creates no simulator connection and starts no worker.
        """
        self.host = host
        self.directory = directory
        self.normalize = normalize
        self.revoke_transport = revoke
        self.resume_photos = resume_photos
        self.import_bindings = ImportBindings(directory)
        self.operation: FinalizationWorker | None = None
        self.automatic_operation: FinalizationWorker | None = None
        self.abandon_previous: FinalizationWorker | None = None
        self.recorder: FlightRecorder | None = None
        self.plan: SessionPlan | None = None
        self.dispatch: DispatchPlan | None = None
        self.dispatch_results: queue.Queue[tuple[str, DispatchPlan | None, Path | None, str]] = queue.Queue()
        self.dispatch_worker: threading.Thread | None = None
        self.dispatch_cancelled = False
        self.active_dispatch: DispatchPlan | None = None
        self.active_dispatch_directory: Path | None = None
        self.dispatch_loading = False
        self.dispatch_loading_target: Path | None = None
        self.simbrief_button_var = tk.StringVar(value="")
        self.simbrief_status_var = tk.StringVar(value="")
        self.report_sort_column = "started"
        self.report_sort_descending = True
        self.dialog: OverlayPanel | None = None
        self.settings_dialog: EmbeddedWindow | None = None
        self.report_dialog: EmbeddedWindow | None = None
        self.report_tree: ttk.Treeview | None = None
        self.report_rows: dict[str, SavedFlight] = {}
        self.report_files: dict[str, tuple[str, ReportRevision, str]] = {}
        self.report_versions: dict[str, tuple[str, ReportRevision]] = {}
        self.report_group_files: dict[str, list[str]] = {}
        self.report_file_sort_keys: dict[str, ReportFileDetails] = {}
        self.report_button: ttk.Button | None = None
        self.record_button: ttk.Checkbutton | None = None
        self._contextual_record_caption: tuple[ttk.Checkbutton, str] | None = None
        self.progress_actions: ttk.Frame | None = None
        self.progress_buttons: dict[str, ttk.Button] = {}
        self.progress_detail_label: ttk.Label | None = None
        self.bar: ttk.Progressbar | None = None
        self.activity_bar: ttk.Progressbar | None = None
        self.task_tree: ttk.Treeview | None = None
        self._contextual_dialog_caption: tuple[OverlayPanel, str] | None = None
        self._contextual_task_heading: tuple[ttk.Treeview, str] | None = None
        self.task_frame: ttk.Frame | None = None
        self._animation_running = False
        self._preparing_progress = False
        self._progress_local_mode = False
        self._force_finish_pending = False
        self._capture_recovery_results: queue.Queue[
            tuple[int, FinalizationWorker | None, str, tuple[SessionPlan, ...] | None]
        ] = queue.Queue()
        self._capture_recovery_worker: threading.Thread | None = None
        self._capture_recovery_restore_attempted = False
        self._capture_recovery_restore_pending: object | None = None
        self._capture_recovery_retry_at: float | None = None
        self._retained_capture_results: queue.Queue[
            tuple[int, str, RetainedWatchCapture | None, str]
        ] = queue.Queue()
        self._inspecting_retained_capture: tuple[int, str] | None = None
        self._retained_capture: RetainedWatchCapture | None = None
        self._retained_capture_closing = False
        self.phase_var = tk.StringVar(value="")
        self.detail_var = tk.StringVar(value="")
        self.time_var = tk.StringVar(value="")
        self.report_detail_var = tk.StringVar(value="")
        self.telemetry_var = tk.StringVar(value="IAS — kt · ALT — ft · V/S — fpm")
        self.lease: WorkspaceLease | None = None
        self.load_error = ""
        self._recovery_state: tuple[str, SessionState] | None = None
        self.manual_job_id: str | None = None
        self.resume_workers: list[threading.Thread] = []
        try:
            self.lease = WorkspaceLease(directory)
            self.preferences = FlightPreferences.load(directory)
        except (OSError, ValueError, RuntimeError) as exc:
            self.load_error = str(exc)
            self.preferences = FlightPreferences(recording=False)
        self.record_var = tk.BooleanVar(value=self.preferences.recording)
        self.report_var = tk.BooleanVar(value=self.preferences.reports)
        self.language_vars = {
            code: tk.BooleanVar(value=code in self.preferences.languages)
            for code in ("en", "cs", "de", "sv")
        }
        self.ui_language = tk.StringVar(value=self.preferences.ui_language)
        self.host.root._flight_ui_language = self.ui_language
        self.refresh_dispatch_status()
        self._last_terminal = ""
        self._progress_generation = 0
        host.root.after(PROGRESS_TICK_MS, self.tick)

    def text(self, key: str) -> str:
        """Translate shared controls using the cached game/flight context.

        Args:
            key (str): Shared flight translation catalog key, including
                abandonment controls, confirmation, phase and completion.

        Returns:
            str: Label in the selected UI language; abandonment uses game
                wording whenever no flight context is detected.
        """
        return self.host.simulator_presence.text(key, self.ui_language.get())

    def capture_text(self, key: str) -> str:
        """Choose neutral capture wording outside a detected flight context.

        Args:
            key (str): Capture control or progress title translation key.

        Returns:
            str: Flight-specific wording for flight contexts and neutral
                activity wording otherwise; saved report names are untouched.
        """
        if not self.host.simulator_presence.flight_detected:
            key = {
                "record_flight": "record_session",
                "flight_progress": "session_progress",
                "flight_settings": "session_settings",
            }.get(key, key)
        return self.text(key)

    def refresh_contextual_labels(self) -> None:
        """Update contextual captions only after their widget or text changes.

        Returns:
            None: Refreshes the record control, progress title and optional
                task heading while preserving preferences and worker state.
        """
        record_button = self.record_button
        if record_button is not None:
            record_caption = self.capture_text("record_flight")
            previous_record = self._contextual_record_caption
            if (previous_record is None or previous_record[0] is not record_button
                    or previous_record[1] != record_caption):
                record_button.configure(text=record_caption)
                self._contextual_record_caption = (record_button, record_caption)

        dialog = self.dialog
        if dialog is not None and dialog.winfo_exists():
            title = self.capture_text("flight_progress")
            previous_dialog = self._contextual_dialog_caption
            if (previous_dialog is None or previous_dialog[0] is not dialog
                    or previous_dialog[1] != title):
                dialog.title(title)
                self._contextual_dialog_caption = (dialog, title)

            task_tree = self.task_tree
            if task_tree is not None:
                previous_heading = self._contextual_task_heading
                if (previous_heading is None or previous_heading[0] is not task_tree
                        or previous_heading[1] != title):
                    task_tree.heading("#0", text=title)
                    self._contextual_task_heading = (task_tree, title)

    def dialog_owner(self) -> tk.Misc:
        """Find the active panel for a user-invoked related dialog.

        Returns:
            tk.Misc: Active embedded panel, focused root widget, or main root.
        """
        panel = active_panel(self.host.root)
        if panel is not None:
            return panel
        focused = self.host.root.focus_get()
        return focused if focused is not None else self.host.root

    def prompt_simbrief_identity(self, identity: str) -> str | None:
        """Ask for one validated identity in the application's themed dialog.

        Args:
            identity (str): Previously successful identity used as an editable draft.
        Returns:
            str | None: Validated draft after Load, or None after cancellation.
        """
        owner = self.dialog_owner()
        previous_grab, previous_focus = owner.grab_current(), owner.focus_get()
        dialog = EmbeddedWindow(owner)
        dialog.withdraw()
        dialog.title("SimBrief")
        dialog.resizable(False, False)
        panel = GlassCard(dialog, padding=(24, 20))
        panel.pack(fill="both", expand=True, padx=12, pady=12)
        ttk.Label(panel, text=self.text("simbrief_identity"),
                  style="Section.TLabel").pack(anchor="w")
        ttk.Label(panel, text=self.text("simbrief_identity_hint"),
                  style="Muted.TLabel", wraplength=SIMBRIEF_PROMPT_WRAP_PX).pack(anchor="w", pady=(8, 12))
        draft = tk.StringVar(value=identity)
        entry = ttk.Entry(panel, textvariable=draft, width=SIMBRIEF_IDENTITY_COLUMNS)
        entry.pack(fill="x")
        error = tk.StringVar(value="")
        ttk.Label(panel, textvariable=error, style="Muted.TLabel",
                  wraplength=SIMBRIEF_PROMPT_WRAP_PX).pack(anchor="w", pady=(6, 0))
        result = None

        def close() -> None:
            """Dismiss the draft and restore the invoking window's focus/grab.

            Returns:
                None: Releases this modal dialog without starting any fetch.
            """
            dialog.destroy()
            if previous_grab is not None and previous_grab.winfo_exists():
                previous_grab.grab_set()
            if previous_focus is not None and previous_focus.winfo_exists():
                previous_focus.focus_set()

        def submit() -> None:
            """Validate the single draft before admitting a provider request.

            Returns:
                None: Retains invalid input inline or returns an accepted draft.
            """
            nonlocal result
            value = draft.get().strip()
            try:
                identity_query(value)
            except ValueError:
                error.set(self.text("simbrief_identity_invalid"))
                entry.focus_set()
                return
            result = value
            close()

        actions = ttk.Frame(panel, style="Card.TFrame")
        actions.pack(fill="x", pady=(14, 0))
        Button(actions, text=self.text("simbrief_cancel"), command=close).pack(side="left")
        Button(actions, text=self.text("simbrief_load"), command=submit,
                   style="Primary.TButton").pack(side="right", padx=(12, 0))
        dialog.protocol("WM_DELETE_WINDOW", close)
        dialog.bind("<Escape>", lambda _event: close())
        dialog.bind("<Return>", lambda _event: submit())
        place_dialog(dialog, owner)
        dialog.deiconify()
        dialog.grab_set()
        entry.selection_range(0, "end")
        entry.focus_set()
        dialog.wait_window()
        return result

    def refresh_dispatch_status(self) -> None:
        """Describe only the staged or current session's accepted dispatch plan.

        Returns:
            None: Publishes changed dispatch text using the latest session state.
        """
        plan = self.dispatch
        if (
            plan is None
            and self.active_dispatch is not None
            and self.recorder is not None
            and self.active_dispatch_directory == self.recorder.directory
            and self.recorder.is_alive()
        ):
            plan = self.active_dispatch
        loading = self.dispatch_loading and (
            self.dispatch_loading_target is None
            or (
                self.recorder is not None
                and self.dispatch_loading_target == self.recorder.directory
            )
        )
        self._publish_readout("simbrief_button_var", self.text(
            "simbrief_loading_short" if loading else
            "simbrief_unload" if plan is not None else "simbrief_load"
        ))
        prefix = self.text(
            "simbrief_next_flight" if self.dispatch is not None else "simbrief_ready"
        ) + ": "
        self._publish_readout(
            "simbrief_status_var",
            prefix + plan.origin + " → " + plan.destination if plan is not None else "",
        )

    def _publish_readout(
        self,
        name: Literal["simbrief_button_var", "simbrief_status_var", "telemetry_var"],
        value: str,
    ) -> None:
        """Keep periodic Flight readouts quiet while their displayed text is stable.

        Args:
            name (Literal["simbrief_button_var", "simbrief_status_var", "telemetry_var"]):
                Flight-owned Tk variable retaining the readout.
            value (str): Current localized text or rounded telemetry values.

        Returns:
            None: Sets the variable only on a new rendered value or variable.
        """
        variable = getattr(self, name)
        published = getattr(self, "_published_readouts", None)
        if published is None:
            published: dict[str, tuple[tk.StringVar, str]] = {}
            self._published_readouts = published
        previous = published.get(name)
        if previous is not None and previous[0] is variable and previous[1] == value:
            return
        variable.set(value)
        published[name] = (variable, value)

    def toggle_simbrief(self) -> None:
        """Load optional dispatch or explicitly detach the displayed flight plan.

        Returns:
            None: Clears staged/current dispatch and its route, or opens loading.
                Historical sessions and existing PDFs remain unchanged. A disk
                failure retains the accepted plan and reports the error.
        """
        if self.load_error or self.lease is None or self.busy():
            return
        if self.dispatch_loading:
            return
        current = (
            self.recorder is not None
            and self.recorder.is_alive()
            and self.active_dispatch_directory == self.recorder.directory
            and self.active_dispatch is not None
        )
        if self.dispatch is None and not current:
            self.load_simbrief()
            return
        if current:
            try:
                (self.recorder.directory / "simbrief.json").unlink(missing_ok=True)
            except OSError as exc:
                messagebox.showerror("SimBrief", str(exc), parent=self.dialog_owner())
                return
            self.active_dispatch = None
            self.active_dispatch_directory = None
        self.dispatch = None
        self.refresh_dispatch_status()

    def load_simbrief(self, change_identity: bool = False, saved: bool = False) -> None:
        """Fetch an optional OFP without blocking Tk or changing recorder state.

        Args:
            change_identity (bool): Prompt for a replacement pilot identity.
            saved (bool): Attach to the selected stopped historical flight.
        Returns:
            None: Starts one bounded worker; success is committed on the Tk tick.
        """
        if self.load_error or self.lease is None or self.busy():
            return
        if self.dispatch_worker is not None and self.dispatch_worker.is_alive():
            return
        target = None
        if saved:
            row = self.selected_saved_flight()
            if row is None:
                return
            if not row.recording_ended:
                messagebox.showinfo(
                    "SimBrief",
                    self.text("finish_recording_first"),
                    parent=self.dialog_owner(),
                )
                return
            target = row.directory
            if self.recorder is not None and self.recorder.is_alive() and self.recorder.directory == target:
                messagebox.showinfo(
                    "SimBrief",
                    self.text("finish_recording_first"),
                    parent=self.dialog_owner(),
                )
                return
        elif self.recorder is not None and self.recorder.is_alive():
            target = self.recorder.directory
        identity = ""
        try:
            identity = str(read_document(self.directory / "simbrief-account.json").get("identity", ""))
        except ValueError:
            pass
        if not identity or change_identity:
            entered = self.prompt_simbrief_identity(identity)
            if entered is None:
                return
            identity = entered.strip()
        if self.dispatch_cancelled:
            # The prior worker is no longer alive at this admission point.
            # Discard its cancelled results before accepting a fresh explicit
            # load after Finish/Exit; never revive an earlier flight's response.
            while not self.dispatch_results.empty():
                try:
                    self.dispatch_results.get_nowait()
                except queue.Empty:
                    break
            self.dispatch_cancelled = False
        self.host.events.put(("info", self.text("simbrief_loading")))
        self.dispatch_loading = True
        self.dispatch_loading_target = target
        self.refresh_dispatch_status()

        def fetch() -> None:
            """Return provider results to Tk without accessing widgets or disk.

            Returns:
                None: Enqueues a normalized plan or a safe provider failure.
            """
            try:
                plan = fetch_dispatch(identity)
                self.dispatch_results.put((identity, plan, target, ""))
            except (OSError, ValueError) as exc:
                self.dispatch_results.put((identity, None, target, str(exc)))

        self.dispatch_worker = threading.Thread(target=fetch, name="SimBrief", daemon=True)
        self.dispatch_worker.start()

    def accept_simbrief(self) -> None:
        """Commit completed explicit imports on Tk with a session identity check.

        Returns:
            None: Saves identities only after success and applies the OFP to
                its captured explicit target without another modal confirmation.
        """
        try:
            identity, plan, target, error = self.dispatch_results.get_nowait()
        except queue.Empty:
            return
        self.dispatch_loading = False
        self.refresh_dispatch_status()
        if self.dispatch_cancelled:
            return
        if plan is None:
            messagebox.showerror("SimBrief", error, parent=self.dialog_owner())
            return
        try:
            atomic_write_json(self.directory / "simbrief-account.json", {"identity": identity})
            if self.busy():
                messagebox.showinfo(
                    "SimBrief",
                    self.text("simbrief_retry"),
                    parent=self.dialog_owner(),
                )
                return
            active = self.recorder is not None and self.recorder.is_alive()
            # A response belongs only to the session captured at admission.
            if target is not None:
                if active and self.recorder.directory != target:
                    messagebox.showinfo(
                        "SimBrief",
                        self.text("simbrief_retry"),
                        parent=self.dialog_owner(),
                    )
                    return
                plan.save(target)
                if self.recorder is not None and self.recorder.directory == target:
                    self.active_dispatch = plan
                    self.active_dispatch_directory = target
            elif active:
                messagebox.showinfo(
                    "SimBrief",
                    self.text("simbrief_retry"),
                    parent=self.dialog_owner(),
                )
                return
            else:
                self.dispatch = plan
            self.cache_dispatch(plan)
            self.refresh_dispatch_status()
            if self.report_dialog is not None and self.report_dialog.winfo_exists():
                self.select_report()
            status = "simbrief_staged_loaded" if target is None else "simbrief_loaded"
            self.host.events.put(("info", self.text(status) + "\n"
                + plan.origin + " → " + plan.destination))
        except (OSError, ValueError) as exc:
            messagebox.showerror("SimBrief", str(exc), parent=self.dialog_owner())

    def attach_ofp_file(self) -> None:
        """Attach an archived machine-readable OFP to a stopped saved flight.

        Returns:
            None: Explicit file selection saves optional dispatch data without
                another confirmation; old PDFs remain and loaded state updates.
        """
        row = self.selected_saved_flight()
        if row is None or self.busy() or self.lease is None or self.load_error:
            return
        if not row.recording_ended:
            messagebox.showinfo(
                "SimBrief",
                self.text("finish_recording_first"),
                parent=self.dialog_owner(),
            )
            return
        if self.recorder and self.recorder.is_alive() and self.recorder.directory == row.directory:
            messagebox.showinfo(
                "SimBrief",
                self.text("finish_recording_first"),
                parent=self.dialog_owner(),
            )
            return
        filename = filedialog.askopenfilename(parent=self.dialog_owner(),
            title=self.text("simbrief_file"), filetypes=[("SimBrief OFP", "*.json *.xml")])
        if not filename:
            return
        try:
            plan = import_dispatch(Path(filename))
            plan.save(row.directory)
            if self.recorder is not None and self.recorder.directory == row.directory:
                self.active_dispatch = plan
                self.active_dispatch_directory = row.directory
            self.cache_dispatch(plan)
            self.refresh_dispatch_status()
            self.select_report()
            self.host.events.put(("info", self.text("simbrief_loaded")))
        except (OSError, ValueError) as exc:
            messagebox.showerror("SimBrief", str(exc), parent=self.dialog_owner())

    def cache_dispatch(self, plan: DispatchPlan) -> None:
        """Retain waypoint evidence without making cache availability mandatory.

        Args:
            plan (DispatchPlan): Valid OFP already attached or staged by the user.
        Returns:
            None: Cache errors are warnings; the original session plan survives.
        """
        try:
            remember_plan(self.directory, plan)
        except (OSError, sqlite3.Error) as exc:
            self.host.events.put(("warning", "SimBrief navigation cache: " + str(exc)))

    def controls(self, parent: tk.Misc) -> None:
        """Place recording, compact live readings and report access in one row.

        Args:
            parent (tk.Misc): Existing watcher card with rows through six.

        Returns:
            None: Report preferences remain inside the feature settings sheet.
        """
        frame = ttk.Frame(parent, style="Card.TFrame")
        frame.grid(row=7, column=0, sticky="ew", pady=(4, 0))
        record_caption = self.capture_text("record_flight")
        record_button = ttk.Checkbutton(
            frame, text=record_caption, variable=self.record_var,
            command=self.save_preferences,
        )
        self.record_button = record_button
        self._contextual_record_caption = (record_button, record_caption)
        record_button.grid(row=0, column=0, sticky="w")
        icon_button(
            frame, "settings", self.open_settings,
            self.capture_text("flight_settings"),
        ).grid(row=0, column=1, padx=(6, 0))
        frame.columnconfigure(2, weight=1)
        ttk.Label(
            frame, textvariable=self.telemetry_var, style="Muted.TLabel",
            anchor="e",
        ).grid(row=0, column=2, sticky="ew", padx=(10, 6))
        icon_button(
            frame, "PDF", self.show_reports, self.text("saved_reports"),
        ).grid(row=0, column=3, sticky="e")

    def open_settings(self) -> None:
        """Open one contextual sheet for automatic reports and language choices.

        Returns:
            None: Reuses the existing sheet; closing it starts no flight work.
        """
        if self.settings_dialog is not None and self.settings_dialog.winfo_exists():
            self.settings_dialog.lift()
            self.settings_dialog.focus_set()
            return
        owner = self.dialog_owner()
        dialog = EmbeddedWindow(owner)
        dialog.withdraw()
        self.settings_dialog = dialog
        dialog.title(self.capture_text("flight_settings"))
        dialog.protocol('WM_DELETE_WINDOW', dialog.destroy)
        panel = GlassCard(dialog, padding=(12, 8))
        panel.pack(fill="both", expand=True, padx=12, pady=12)
        panel.columnconfigure(0, weight=1)
        ttk.Label(
            panel, text=self.capture_text("flight_settings"),
            style="Section.TLabel",
        ).grid(row=0, column=0, sticky="w")
        icon_button(
            panel, "close", dialog.destroy, self.text("hide"),
        ).grid(row=0, column=1, sticky="e", padx=(24, 0))
        ttk.Checkbutton(
            panel, text=self.text("auto_report"), variable=self.report_var,
            command=self.save_preferences,
        ).grid(row=1, column=0, columnspan=2, sticky="w", pady=(18, 10))
        ttk.Label(
            panel, text=self.text("report_languages"), style="Muted.TLabel",
        ).grid(row=2, column=0, sticky="w", pady=(6, 4))
        editions = ttk.Frame(panel, style="Card.TFrame")
        editions.grid(row=3, column=0, columnspan=2, sticky="w")
        for code, variable in self.language_vars.items():
            ttk.Checkbutton(
                editions, text=code.upper(), variable=variable,
                command=self.save_preferences,
            ).pack(side="left", padx=(0, 8))
        ttk.Label(
            panel, text=self.text("ui_language"), style="Muted.TLabel",
        ).grid(row=4, column=0, sticky="w", pady=(16, 4))
        language = ttk.Frame(panel, style="Card.TFrame")
        language.grid(row=5, column=0, columnspan=2, sticky="w")
        for code in ("en", "cs", "de", "sv"):
            ttk.Radiobutton(
                language, text=code.upper(), value=code,
                variable=self.ui_language, command=self.save_preferences,
                style="Language.TRadiobutton", takefocus=True,
            ).pack(side="left", padx=(0, 6))
        actions = ttk.Frame(panel, style="Card.TFrame")
        actions.grid(row=6, column=0, columnspan=2, sticky="w", pady=(20, 0))
        icon_button(
            actions, "≡", self.show_progress, self.capture_text("flight_progress"),
        ).pack(side="left")
        icon_button(
            actions, "↗", self.open_reports, self.text("open_reports"),
        ).pack(side="left", padx=(6, 0))
        Button(
            actions, text=self.text("saved_reports"), command=self.show_reports,
        ).pack(side="left", padx=(10, 0))
        Button(
            panel, text=self.text("finish_pdf"), command=self.finish_report,
        ).grid(row=7, column=0, columnspan=2, sticky="ew", pady=(12, 0))
        dialog.bind("<Escape>", lambda _event: dialog.destroy())
        dialog.deiconify()
        dialog.focus_set()

    def refresh_telemetry(self) -> None:
        """Update one compact instrument line from cached recorder values.

        Returns:
            None: Shows IAS in knots, altitude in feet and V/S in feet/minute;
                missing or stale telemetry uses dashes without native queries.
        """
        values = self.recorder.telemetry_snapshot() if self.recorder else None
        readings = values or {}
        ias = readings.get("indicated_airspeed_mps")
        altitude = readings.get("altitude_m")
        vertical = readings.get("vertical_speed_mps")
        ias_text = (
            f"{ias / METRES_PER_SECOND_PER_KNOT:.0f}" if ias is not None else "—"
        )
        altitude_text = (
            f"{altitude / METRES_PER_FOOT:,.0f}" if altitude is not None else "—"
        )
        vertical_text = (
            f"{vertical / METRES_PER_SECOND_PER_FPM:+,.0f}"
            if vertical is not None else "—"
        )
        self._publish_readout(
            "telemetry_var",
            f"IAS {ias_text} kt · ALT {altitude_text} ft · V/S {vertical_text} fpm",
        )

    def finish_report(self, *, revoke: bool = False) -> None:
        """Finish the active Watcher session and its available flight reports.

        Args:
            revoke (bool): Explicit primary mission Finish requests saved-key
                revocation; report/PDF controls and ordinary Stop leave false.

        Returns:
            None: Uses a loaded OFP and all editions for explicit flight
                completion. Explicit saved-key mission Finish revokes its key after
                accepted work and fenced local cleanup; ordinary Stop retains
                that key. A contextual Watcher key
                is released only after the shared operation completes.
        """
        if self.busy():
            self._consume_trigger_reason("explicit_finish")
            self.show_progress()
            return
        languages = REPORT_LANGUAGES
        self._prepare_progress_view()
        try:
            plans = self.pending()
            current = next((
                plan for plan in plans
                if self.plan is not None and plan.directory == self.plan.directory
            ), None)
            target = current or (plans[0] if len(plans) == 1 else None)
            if target is not None:
                self.attach_loaded_dispatch(target.directory)
            for plan in plans:
                plan.languages = languages
                plan.reports_enabled = True
                plan.save()
            self.begin(
                revoke and not self.host.linking_manager.linked_upload_mode_selected(),
                automatic=True,
            )
        except (OSError, ValueError) as exc:
            self._consume_trigger_reason("explicit_finish")
            self._dismiss_progress_preparation()
            messagebox.showerror(self.text("finish_pdf"), str(exc), parent=self.dialog_owner())

    def attach_loaded_dispatch(self, directory: Path) -> None:
        """Use an accepted in-memory OFP for an explicitly finished/rendered flight.

        Args:
            directory (Path): Current/sole pending or selected historical session.
        Returns:
            None: Saves the staged plan, or the matching active plan. With no
                loaded plan, existing session evidence remains untouched.
        """
        plan = self.dispatch
        if plan is None and self.active_dispatch_directory == directory:
            plan = self.active_dispatch
        if plan is not None:
            plan.save(directory)

    def show_reports(self) -> None:
        """Show saved flights, local PDFs and explicit independent report actions.

        Returns:
            None: Opens a passive local catalog; no recording or upload starts.
        """
        if self.report_dialog is not None and self.report_dialog.winfo_exists():
            self.report_dialog.deiconify()
            self.report_dialog.lift()
            self.refresh_reports()
            return
        owner = self.dialog_owner()
        self.report_dialog = EmbeddedWindow(owner)
        self.report_dialog.withdraw()
        self.report_dialog.title(self.text("saved_reports"))
        self.report_dialog.transient(self.host.root)
        panel = GlassCard(self.report_dialog, padding=(16, 12))
        panel.pack(fill="both", expand=True, padx=10, pady=10)
        panel.columnconfigure(0, weight=1)
        panel.rowconfigure(1, weight=1)
        report_footer = ttk.Frame(panel, style="Card.TFrame")
        report_footer.grid(row=3, column=0, sticky="ew", pady=(8, 0))
        hint = ttk.Label(
            panel, text=self.text("report_actions_hint"), wraplength=620,
            style="Muted.TLabel",
        )
        hint.grid(row=0, column=0, sticky='ew', pady=(0, 10))
        viewport = ttk.Frame(panel)
        viewport.grid(row=1, column=0, sticky='nsew')
        viewport.columnconfigure(0, weight=1)
        viewport.rowconfigure(0, weight=1)
        columns = ("started", "samples", "pdfs", "created", "pages", "state")
        self.report_tree = ttk.Treeview(
            viewport, columns=columns, show="tree headings", height=REPORT_VISIBLE_ROWS,
            selectmode="extended",
        )
        self.report_tree.heading("#0", text=self.text("saved_reports"))
        self.report_tree.heading("#0", command=lambda: self.sort_reports("#0"))
        self.report_tree.column("#0", width=300, minwidth=170)
        for name, width in zip(columns, REPORT_COLUMN_WIDTHS, strict=True):
            self.report_tree.heading(name, text=self.text("column_" + name),
                                     command=lambda column=name: self.sort_reports(column))
            self.report_tree.column(name, width=width, minwidth=40)
        self.report_tree.grid(row=0, column=0, sticky='nsew')
        vertical = ttk.Scrollbar(viewport, command=self.report_tree.yview)
        vertical.grid(row=0, column=1, sticky='ns')
        horizontal = ttk.Scrollbar(viewport, orient='horizontal', command=self.report_tree.xview)
        horizontal.grid(row=1, column=0, sticky='ew')
        self.report_tree.configure(yscrollcommand=vertical.set, xscrollcommand=horizontal.set)
        self.report_tree.bind("<<TreeviewSelect>>", lambda _event: self.select_report())
        self.report_tree.bind("<Double-1>", lambda _event: self.open_selected_pdf())
        detail_label = ttk.Label(
            panel, textvariable=self.report_detail_var, wraplength=620,
            style="Muted.TLabel",
        )
        detail_label.grid(row=2, column=0, sticky='ew', pady=(8, 4))
        panel.bind('<Configure>', lambda event: (
            hint.configure(wraplength=max(1, event.width - 16)),
            detail_label.configure(wraplength=max(1, event.width - 16)),
        ))
        actions = ttk.Frame(report_footer, style="Card.TFrame")
        actions.pack(fill="x", pady=(8, 0))
        actions.columnconfigure(0, weight=1)
        actions.columnconfigure(1, weight=1)
        actions.bind('<Configure>', lambda _event: layout_actions(
            actions, [button for button in actions.winfo_children() if isinstance(button, ttk.Button)],
        ))
        Button(
            actions, text=self.text("finish_pdf"), command=self.finish_report,
        ).grid(row=0, column=0, sticky="ew", padx=(0, 6), pady=(0, 4))
        self.report_button = Button(
            actions, text=self.text("create_local_pdf"), command=self.create_local_pdf,
        )
        self.report_button.grid(row=0, column=1, sticky="ew", pady=(0, 4))
        Button(actions, text=self.text("simbrief_load"),
            command=lambda: self.load_simbrief(saved=True),
        ).grid(row=2, column=0, sticky="ew", padx=(0, 6), pady=(6, 0))
        Button(actions, text=self.text("simbrief_file"), command=self.attach_ofp_file,
        ).grid(row=2, column=1, sticky="ew", pady=(6, 0))
        Button(
            actions, text=self.text("open_reports"), command=self.open_selected_reports,
        ).grid(row=1, column=0, sticky="ew", padx=(0, 6))
        Button(
            actions, text=self.text("open_pdf"), command=self.open_selected_pdf,
        ).grid(row=1, column=1, sticky="ew")
        tools = ttk.Frame(report_footer, style="Card.TFrame")
        tools.pack(fill="x", pady=(8, 0))
        tools.bind('<Configure>', lambda _event: layout_actions(
            tools, [button for button in tools.winfo_children() if isinstance(button, ttk.Button)],
        ))
        Button(
            tools, text=self.text("refresh_reports"), command=self.refresh_reports,
        ).grid(row=0, column=0, sticky='ew')
        Button(
            tools, text=self.text("open_app_log"), command=self.host.open_log_file,
        ).grid(row=0, column=1, sticky='ew')
        Button(
            tools, text=self.text("upload_saved_pdf"), command=self.upload_saved_pdf,
        ).grid(row=1, column=0, sticky='ew')
        self.refresh_reports()
        self.report_dialog.deiconify()

    def sort_reports(self, column: str) -> None:
        """Toggle a column's direction and reorder existing catalog rows.

        Args:
            column (str): Tree column identity: #0, started, samples, pdfs,
                created, pages or state.
        Returns:
            None: Preserves open groups and selection without rereading files.
        """
        if column == self.report_sort_column:
            self.report_sort_descending = not self.report_sort_descending
        else:
            self.report_sort_column = column
            self.report_sort_descending = column in {"started", "created"}
        self.apply_report_sort()

    def report_sort_key(self, identity: str) -> tuple[str | int, str]:
        """Prepare typed time, count or text keys from the passive catalog.

        Args:
            identity (str): Cached flight, version group or retained PDF row.
        Returns:
            tuple[str | int, str]: Comparable value for the chosen column and
                stable row identity; each column uses one consistent value type.
        """
        column = self.report_sort_column
        if identity in self.report_files:
            session, _revision, _language = self.report_files[identity]
            row = self.report_rows[session]
            details = self.report_file_sort_keys.get(identity)
            if column == "pages":
                return (details.pages if details and details.pages is not None else -1, identity)
            stamp = details.created_utc if details and column == "created" else row.started_utc
            if column in {"samples", "pdfs"}:
                return (0, identity)
            text = str(self.report_tree.item(identity, "text"))
        else:
            version = self.report_versions.get(identity)
            row = self.report_rows[version[0] if version else identity]
            stamp = row.started_utc
            children = self.report_group_files.get(identity, [])
            if column in {"samples", "pdfs"}:
                count = (0 if version else row.sample_count) if column == "samples" else (
                    len(children) if version else row.report_count
                )
                return (count, identity)
            if column == "pages":
                return (sum(self.report_file_sort_keys[child].pages or 0
                    for child in children if child in self.report_file_sort_keys), identity)
            if column == "created":
                stamp = max((self.report_file_sort_keys[child].created_utc
                    for child in children if child in self.report_file_sort_keys), default="")
            text = "v" + str(version[1].number) if version else row.aircraft
        if column in {"started", "created"}:
            try:
                parsed = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
                if parsed.tzinfo is None:
                    parsed = parsed.replace(tzinfo=timezone.utc)
                stamp = parsed.astimezone(timezone.utc).isoformat()
            except ValueError:
                stamp = ""
            return (stamp, identity)
        if column == "state":
            text = str(self.report_tree.set(identity, "state"))
        return (text.casefold(), identity)

    def apply_report_sort(self) -> None:
        """Sort flights/files by the selected column and versions newest first.

        Returns:
            None: Moves existing items without losing expansion/selection;
                version groups always use descending numeric version order.
        """
        tree = self.report_tree
        if tree is None or not tree.winfo_exists():
            return
        parents = ("",) + tuple(tree.get_children()) + tuple(self.report_versions)
        for parent in parents:
            children = tree.get_children(parent)
            if children and all(child in self.report_versions for child in children):
                ordered = sorted(children, key=lambda child: (
                    self.report_versions[child][1].number or 0, child,
                ), reverse=True)
            else:
                ordered = sorted(children, key=self.report_sort_key,
                                 reverse=self.report_sort_descending)
            for position, identity in enumerate(ordered):
                tree.move(identity, parent, position)
        for column in ("#0", "started", "samples", "pdfs", "created", "pages", "state"):
            title = self.text("saved_reports" if column == "#0" else "column_" + column)
            if column == self.report_sort_column:
                title += " ↓" if self.report_sort_descending else " ↑"
            tree.heading(column, text=title)

    def refresh_reports(self) -> None:
        """Refresh checkpoint rows without reading or analyzing raw sample files.

        Returns:
            None: Keeps selection when possible and exposes corrupt-session errors.
        """
        tree = self.report_tree
        if tree is None or not tree.winfo_exists():
            return
        selected = tree.selection()
        previous_versions = set(self.report_versions)
        expanded = {
            identity for identity in (*self.report_rows, *self.report_versions)
            if tree.exists(identity) and tree.item(identity, "open")
        }
        try:
            self.report_rows = {
                row.directory.name: row for row in saved_flights(self.directory)
            }
        except (OSError, ValueError) as exc:
            self.report_detail_var.set(str(exc))
            self.host.events.put(("warning", f"[flight-report] Cannot read saved flights: {exc}"))
            return
        for identity in tree.get_children():
            tree.delete(identity)
        self.report_files = {}
        self.report_versions = {}
        self.report_group_files = {}
        self.report_file_sort_keys = {}
        for identity, row in self.report_rows.items():
            live = bool(
                self.recorder and self.recorder.is_alive()
                and self.recorder.directory == row.directory
            )
            state = "recording" if live else (
                "saved" if row.recording_ended else "unfinished"
            )
            if row.error:
                state = "error"
            if row.completed and not row.error:
                state = "completed"
            count = (
                self.recorder.diagnostics_snapshot()["sample_count"]
                if live else row.sample_count
            )
            try:
                stamp = datetime.fromisoformat(row.started_utc.replace("Z", "+00:00"))
                if stamp.tzinfo is None:
                    stamp = stamp.replace(tzinfo=timezone.utc)
                started = stamp.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            except ValueError:
                started = row.started_utc
            tree.insert("", "end", iid=identity, text=row.aircraft or "—", open=identity in expanded, values=(
                started, count,
                row.report_count, "", "", self.text("report_state_" + state),
            ))
            self.report_group_files[identity] = []
            try:
                revisions = report_revisions(row.directory)
                multiple_versions = len(revisions) > 1
                for revision in sorted(revisions,
                        key=lambda item: item.number or 0, reverse=True):
                    parent = identity
                    if multiple_versions:
                        parent = "version:" + identity + ":" + revision.report_id
                        self.report_versions[parent] = (identity, revision)
                        self.report_group_files[parent] = []
                        tree.insert(identity, "end", iid=parent,
                            text="v" + str(revision.number),
                            open=parent in expanded or (
                                parent not in previous_versions and any(
                                    "pdf:" + identity + ":" + revision.report_id + ":" + code
                                    in selected for code in revision.files
                                )
                            ),
                            values=("", "", len(revision.files), "", "",
                                    self.text("report_version")))
                    for language in revision.files:
                        child = "pdf:" + identity + ":" + revision.report_id + ":" + language
                        self.report_files[child] = (identity, revision, language)
                        self.report_group_files[identity].append(child)
                        if multiple_versions:
                            self.report_group_files[parent].append(child)
                        filename = report_file_label(row.directory, revision, language)
                        version = filename.rsplit(" - ", 1)[-1].removesuffix(".pdf")
                        details = report_file_details(revision.files[language])
                        self.report_file_sort_keys[child] = details
                        created = datetime.fromisoformat(
                            details.created_utc,
                        ).astimezone().strftime("%Y-%m-%d %H:%M:%S")
                        if details.estimated_time:
                            created += " *"
                        label = language.upper() + " · " + filename
                        if not multiple_versions:
                            label = version + " · " + label
                        tree.insert(
                            parent, "end", iid=child,
                            text=label,
                            values=(
                                "", "", "", created,
                                details.pages if details.pages is not None else "?",
                                "PDF",
                            ),
                        )
                    if multiple_versions:
                        details = [self.report_file_sort_keys[child]
                                   for child in self.report_group_files[parent]]
                        pages = (sum(item.pages for item in details)
                                 if all(item.pages is not None for item in details) else "?")
                        latest = max(details, key=lambda item: item.created_utc, default=None)
                        created = ""
                        if latest is not None:
                            created = datetime.fromisoformat(
                                latest.created_utc,
                            ).astimezone().strftime("%Y-%m-%d %H:%M:%S")
                            if latest.estimated_time:
                                created += " *"
                        tree.item(parent, values=("", "", len(details), created,
                            pages, self.text("report_version")))
            except (OSError, ValueError) as exc:
                self.report_detail_var.set(str(exc))
        self.apply_report_sort()
        selection = tuple(identity for identity in selected if tree.exists(identity))
        if not selection:
            selection = tuple(tree.get_children())[:1]
        if selection:
            tree.selection_set(selection)
        self.select_report()

    def selected_saved_flight(self) -> SavedFlight | None:
        """Resolve catalog selection or the last local job after its window closes.

        Returns:
            SavedFlight | None: Selected cached row, the last local job's row
                for retry/open actions, or None when no saved flight is selected.
        """
        tree = self.report_tree
        if tree is not None and tree.winfo_exists():
            selected = tree.selection()
            if selected:
                identity = selected[0]
                if identity in self.report_files:
                    identity = self.report_files[identity][0]
                elif identity in self.report_versions:
                    identity = self.report_versions[identity][0]
                return self.report_rows.get(identity)
        if (
            self.independent_operation()
            and self.operation.plans
        ):
            return self.report_rows.get(self.operation.plans[0].session_id)
        return None

    def select_report(self) -> None:
        """Show the selected session identity and retained recovery explanation.

        Returns:
            None: Updates the local create/re-create label without starting work.
        """
        row = self.selected_saved_flight()
        detail = row.directory.name + (": " + row.error if row.error else "") if row else self.text("select_saved_flight")
        if row is not None:
            dispatch = load_dispatch(row.directory)
            if dispatch is not None:
                detail += (
                    "\n" + self.text("simbrief_ready") + ": "
                    + dispatch.origin + " → " + dispatch.destination
                )
            else:
                detail += "\n" + self.text("simbrief_not_attached")
            if self.dispatch is not None and dispatch != self.dispatch:
                detail += (
                    "\n" + self.text("simbrief_available_for_report") + ": "
                    + self.dispatch.origin + " → " + self.dispatch.destination
                )
        self.report_detail_var.set(detail)
        if self.report_button is not None and self.report_button.winfo_exists():
            self.report_button.configure(
                text=self.text(
                    "regenerate_pdf" if row and row.report_count else "create_local_pdf"
                ),
                state="normal" if row is not None and not self.busy() else "disabled",
            )

    def create_local_pdf(self) -> None:
        """Create or re-create selected local PDFs without changing delivery state.

        Returns:
            None: Starts a worker for one stopped session and all four editions;
                an active recorder must first use Finish flight and create PDF.
                An already loaded OFP is attached to this explicitly selected
                flight before rendering without a second confirmation.
        """
        self._consume_trigger_reason("local_report")
        trigger_reason = "local_report"
        if self.busy():
            self.show_progress()
            return
        row = self.selected_saved_flight()
        if row is None:
            messagebox.showinfo(
                self.text("saved_reports"),
                self.text("select_saved_flight"),
                parent=self.dialog_owner(),
            )
            return
        if self.load_error or self.lease is None:
            messagebox.showerror(
                self.text("saved_reports"),
                self.load_error or "Flight workspace unavailable.",
                parent=self.dialog_owner(),
            )
            return
        if (
            self.recorder and self.recorder.is_alive()
            and self.recorder.directory == row.directory
        ) or not row.recording_ended:
            messagebox.showinfo(
                self.text("saved_reports"),
                self.text("finish_recording_first"),
                parent=self.dialog_owner(),
            )
            return
        languages = REPORT_LANGUAGES
        try:
            plan = replace(SessionPlan.load(row.directory), languages=languages)
            self.attach_loaded_dispatch(row.directory)
            # Photo Finish is durable intent, not a five-minute retry budget.
            # Its worker can remain in rate-limit/offline wait while local
            # capture reconciliation continues under the original cutoff.
            self._progress_generation += 1
            self.operation = FinalizationWorker(
                [plan], "", "", lambda _cancel, _deadline: None,
                lambda: None, False, local_reports=True,
                emit=lambda level, message: self.host.events.put((level, message)),
            )
            self.operation.flight_generation = self._progress_generation
            self.operation.trigger_reason = trigger_reason
            self.automatic_operation = self.operation
            self._last_terminal = ""
            self.operation.start()
            self.select_report()
            self.show_progress()
        except (OSError, ValueError, RuntimeError) as exc:
            messagebox.showerror(self.text("saved_reports"), str(exc), parent=self.dialog_owner())

    def upload_saved_pdf(self) -> None:
        """Confirm and upload a retained revision with the current saved key.

        Returns:
            None: Starts independent delivery after showing session, editions
                and destination; requires a tested saved gallery connection.
        """
        if self.busy():
            self.show_progress()
            return
        row = self.selected_saved_flight()
        if row is None:
            messagebox.showinfo(
                self.text("saved_reports"),
                self.text("select_saved_flight"),
                parent=self.dialog_owner(),
            )
            return
        selected = self.report_tree.selection() if self.report_tree is not None else ()
        files = [self.report_files[identity] for identity in selected if identity in self.report_files]
        if not files:
            messagebox.showinfo(
                self.text("saved_reports"),
                self.text("select_pdf_files"),
                parent=self.dialog_owner(),
            )
            return
        if len({identity for identity, _revision, _language in files}) != 1:
            messagebox.showinfo(
                self.text("saved_reports"),
                self.text("single_flight_selection"),
                parent=self.dialog_owner(),
            )
            return
        row = self.report_rows[files[0][0]]
        groups: dict[str, ReportRevision] = {}
        for _identity, revision, language in files:
            group = groups.setdefault(revision.report_id, ReportRevision(revision.report_id, {}, revision.modified_utc))
            group.files[language] = revision.files[language]
        revisions = tuple(groups.values())
        self.start_saved_upload(row, revisions[0], revisions[1:])

    def retry_saved_upload(self) -> None:
        """Retry the captured PDF revision independently of catalog selection.

        Returns:
            None: Reconfirms the original revision with the current destination
                and key; an unavailable catalog row requires refreshing history.
        """
        operation = self.operation
        if operation is None or not operation.saved_upload or operation.saved_revision is None:
            return
        row = self.report_rows.get(operation.plans[0].session_id)
        if row is None:
            self.show_reports()
            return
        self.start_saved_upload(row, operation.saved_revision, operation.saved_revisions[1:])

    def start_saved_upload(
        self, row: SavedFlight, revision: ReportRevision,
        additional: tuple[ReportRevision, ...] = (),
    ) -> None:
        """Confirm a concrete retained revision before independent delivery.

        Args:
            row (SavedFlight): Captured stopped-flight catalog entry.
            revision (ReportRevision): Exact report identity and edition paths
                chosen for the initial action or retained from a failed upload.
            additional (tuple[ReportRevision, ...]): Further explicitly selected
                revision/edition subsets from the same saved flight.

        Returns:
            None: Starts delivery with current saved credentials after explicit
                target confirmation; another flight remains active.
        """
        if self.busy():
            self.show_progress()
            return
        if self.host.linking_manager.linked_upload_mode_selected():
            messagebox.showinfo(
                self.text("saved_reports"),
                self.text("linked_legacy_action_unavailable"),
                parent=self.dialog_owner(),
            )
            return
        if self.load_error or self.lease is None:
            messagebox.showerror(
                self.text("saved_reports"),
                self.load_error or "Flight workspace unavailable.",
                parent=self.dialog_owner(),
            )
            return
        gallery = self.host.gallery_label_var.get().removeprefix("Target gallery: ")
        if not self.host.config.api_key or not gallery or gallery == "unknown":
            messagebox.showinfo(
                self.text("saved_reports"),
                self.text("test_saved_connection"),
                parent=self.dialog_owner(),
            )
            return
        if not row.recording_ended or (
            self.recorder and self.recorder.is_alive() and self.recorder.directory == row.directory
        ):
            messagebox.showinfo(
                self.text("saved_reports"),
                self.text("finish_recording_first"),
                parent=self.dialog_owner(),
            )
            return
        detail = (
            self.text("confirm_saved_upload") + "\n\n" + gallery + "\n"
            + row.directory.name + "\n" + revision.modified_utc + "\n"
            + "\n".join(pdf.name for item in (revision,) + additional for pdf in item.files.values())
        )
        if not messagebox.askyesno(
            self.text("upload_saved_pdf"),
            detail,
            parent=self.dialog_owner(),
        ):
            return
        try:
            languages = tuple(dict.fromkeys(code for item in (revision,) + additional for code in item.files))
            plan = replace(SessionPlan.load(row.directory), languages=languages)
            owner, port, upload_url, api_key = self.host.capture_flight_admission()
            self._progress_generation += 1
            self.operation = FinalizationWorker(
                [plan], upload_url, api_key, lambda _cancel, _deadline: None,
                lambda: None, False, saved_revision=revision,
                saved_revisions=(revision,) + additional,
                target_gallery=gallery,
                emit=lambda level, message: self.host.events.put((level, message)),
                gui_admission=(owner, port), require_gui_admission=True,
            )
            self.operation.flight_generation = self._progress_generation
            self._consume_trigger_reason("saved_report_upload")
            self.operation.trigger_reason = "saved_report_upload"
            self._last_terminal = ""
            self.operation.start()
            self.show_progress()
        except (OSError, ValueError, RuntimeError) as exc:
            messagebox.showerror(self.text("saved_reports"), str(exc), parent=self.dialog_owner())

    def open_selected_pdf(self) -> None:
        """Open explicitly selected retained PDFs in the system's default viewer.

        Returns:
            None: Opens only confined existing PDF files; parent rows simply
                expand in the tree and do not launch an unrelated file.
        """
        tree = self.report_tree
        if tree is None or not tree.winfo_exists():
            return
        for identity in tree.selection():
            item = self.report_files.get(identity)
            if item is None:
                continue
            session_id, revision, language = item
            row = self.report_rows[session_id]
            pdf = revision.files[language]
            if (
                pdf.is_symlink() or pdf.parent.is_symlink() or not pdf.is_file()
                or not pdf.resolve().is_relative_to((row.directory / "reports").resolve())
            ):
                messagebox.showerror(
                    self.text("saved_reports"),
                    "PDF unavailable; refresh the list.",
                    parent=self.dialog_owner(),
                )
                return
            self.host.open_local_path(pdf)

    def open_selected_reports(self) -> None:
        """Open PDFs for the selected saved flight inside its owned directory.

        Returns:
            None: Opens the session report directory, or its session if absent.
        """
        row = self.selected_saved_flight()
        if row is None:
            return
        self.open_pdf_folder(row.directory)

    def open_pdf_folder(self, directory: Path) -> None:
        """Open a confined PDF-only directory after explicit layout organization.

        Args:
            directory (Path): Captured session from selection or progress owner.

        Returns:
            None: Opens reports rather than raw JSON storage; no move runs while
                another PDF operation owns the workspace.
        """
        reports = directory / "reports"
        if self.busy():
            self.show_progress()
            return
        if (
            directory.is_symlink()
            or directory.resolve().parent != self.directory.resolve()
            or reports.is_symlink()
            or not reports.resolve().is_relative_to(directory.resolve())
        ):
            messagebox.showerror(
                self.text("saved_reports"),
                "Unsafe report directory.",
                parent=self.dialog_owner(),
            )
            return
        try:
            if not report_revisions(directory):
                messagebox.showinfo(
                    self.text("saved_reports"),
                    self.text("no_saved_pdf"),
                    parent=self.dialog_owner(),
                )
                return
            organize_reports(directory)
            self.refresh_reports()
            self.host.open_local_path(reports)
        except (OSError, ValueError) as exc:
            messagebox.showerror(self.text("saved_reports"), str(exc), parent=self.dialog_owner())

    def save_preferences(self) -> bool:
        """Persist feature choices and immediately gate active acquisition.

        Returns:
            bool: True after saving and pausing/resuming the recorder; False
                after rejected changes restore the previously saved controls.
        """
        if FlightUI._watcher_reset_blocked(self):
            self.restore_preferences()
            return False
        if self.busy() and not self.independent_operation():
            self.restore_preferences()
            self.show_progress()
            return False
        try:
            preferences = FlightPreferences(
                recording=self.record_var.get(),
                reports=self.report_var.get(),
                languages=tuple(
                    code for code, variable in self.language_vars.items()
                    if variable.get()
                ),
                ui_language=self.ui_language.get(),
            )
            language_changed = preferences.ui_language != self.preferences.ui_language
            preferences.save(self.directory)
            self.preferences = preferences
            self.refresh_dispatch_status()
            if language_changed:
                # These controls own their published captions on the host.
                self.host.refresh_connection_controls()
            if (
                self.plan is not None and self.recorder is not None
                and self.recorder.is_alive()
            ):
                # Honor capture opt-out before any report-plan write can fail.
                if preferences.recording:
                    self.recorder.resume()
                else:
                    self.recorder.pause()
                self.plan.reports_enabled = preferences.reports
                self.plan.languages = preferences.languages
                self.plan.save()
            return True
        except (OSError, ValueError) as exc:
            self.restore_preferences()
            messagebox.showerror(
                self.capture_text("flight_progress"),
                str(exc),
                parent=self.dialog_owner(),
            )
            return False

    def restore_preferences(self) -> None:
        """Restore saved switch states when a local preference change is denied.

        Returns:
            None: The controls again represent the last accepted preferences.
        """
        self.record_var.set(self.preferences.recording)
        self.report_var.set(self.preferences.reports)
        self.ui_language.set(self.preferences.ui_language)
        self.refresh_dispatch_status()
        for code, variable in self.language_vars.items():
            variable.set(code in self.preferences.languages)

    def binding(self) -> str:
        """Fingerprint the selected target without falling back on expiry.

        Returns:
            str: Non-secret linked target or legacy endpoint/key binding.

        Raises:
            ValueError: Linked mode is selected without an owned binding.
        """
        if self.host.linking_manager.linked_upload_mode_selected():
            binding = self.host.linking_manager.linked_upload_binding()
            if not isinstance(binding, str) or re.fullmatch(r"[0-9a-f]{64}", binding) is None:
                raise ValueError("The linked gallery target is unavailable.")
            return binding
        return connection_binding(
            self.normalize(self.host.config.gallery_url),
            self.host.config.api_key,
        )

    def pending(self, *, binding: str | None = None) -> list[SessionPlan]:
        """Read recoverable work for a live or verified saved target.

        Args:
            binding (str | None): Exact saved target fingerprint supplied by
                the mode owner, or None to use the current connection.

        Returns:
            list[SessionPlan]: Pending connection-bound sessions.

        Raises:
            ValueError: Existing state cannot be safely interpreted.
        """
        if self.load_error:
            raise ValueError(self.load_error)
        return pending_sessions(
            self.directory, self.binding() if binding is None else binding,
        )

    def session_state(self) -> SessionState:
        """Project the Watcher lifecycle from workers and the owned queue.

        Returns:
            SessionState: Active capture, finishing work, interrupted work,
                blocked recovery evidence or retained transport, or an idle
                workspace. A force-finished transport remains blocked until its
                lease releases; durable admissions read the queue again.
        """
        if self.busy() or self.host.api_key_revoke_running:
            return "working"
        watcher = self.host.worker
        queue_store = getattr(watcher, "_queue", None)
        durably_force_finished = (
            watcher is not None
            and getattr(queue_store, "force_finished", False) is True
        )
        if watcher is not None and watcher.is_alive() and not durably_force_finished:
            return "active"
        if durably_force_finished:
            transport_active = getattr(watcher, "_transport_active", None)
            pending_transport = getattr(watcher, "_pending_transport", None)
            transport_owned = bool(
                callable(getattr(transport_active, "is_set", None))
                and transport_active.is_set()
            ) or bool(
                pending_transport is not None
                and callable(getattr(pending_transport, "is_set", None))
                and not pending_transport.is_set()
            )
            if transport_owned:
                return "blocked"
        try:
            binding = self.binding()
        except ValueError:
            return "blocked"
        if self._recovery_state is None or self._recovery_state[0] != binding:
            try:
                state: SessionState = "pending" if self.pending(binding=binding) else "idle"
            except (OSError, ValueError):
                # A malformed queue must not become an idle workspace.
                state = "blocked"
            self._recovery_state = (binding, state)
        return self._recovery_state[1]

    def _consume_trigger_reason(self, fallback: str) -> str:
        """Consume one App-provided Finish trigger for worker diagnostics.

        Args:
            fallback (str): Local action reason when the App supplied no trigger.
        Returns:
            str: Stable reason label attached to the new finalization worker.
        """
        supplied = getattr(self.host, "_next_finish_trigger_reason", "")
        if not isinstance(supplied, str) or not supplied:
            return fallback
        self.host._next_finish_trigger_reason = ""
        return supplied

    def _watcher_reset_blocked(self) -> bool:
        """Project the host's Watcher admission fence into capture-only actions.

        Returns:
            bool: True when the running host owns a pending factory reset.
        """
        blocked = getattr(self.host, 'watcher_reset_blocked', None)
        return callable(blocked) and blocked() is True

    def show_recovery(self) -> None:
        """Show pending Watcher progress and its explicit actions.

        Returns:
            None: Opens the recovery view and inspects retained work without
                modifying its journal or starting a transfer.
        """
        if FlightUI._watcher_reset_blocked(self):
            return
        operation = self.operation
        if (not self.busy() and operation is not None and (
                self.independent_operation()
                or operation.snapshot().phase == "completed"
                or (not operation.is_alive() and not operation.server_request_sent
                    and not operation.wants_revoke and not operation.abandonment
                    and operation.snapshot().phase in {"error", "cancelled"}))):
            self.operation = None
        self._recovery_state = None
        self.show_progress()
        if self.operation is None:
            self._inspect_retained_capture()

    def _inspect_retained_capture(self) -> None:
        """Read the next retained capture's local evidence off the Tk thread.

        Returns:
            None: Schedules a passive summary, or reports unavailable local
                evidence while retaining the existing recovery guards.
        """
        watcher = self.host.worker
        if (self._retained_capture_closing
                or (watcher is not None and watcher.is_alive())):
            return
        self.time_var.set(self.text('watch_recovery_inspecting'))
        try:
            binding = self.binding()
        except ValueError as exc:
            self._inspecting_retained_capture = None
            self._retained_capture = None
            self.host.events.put(('warning', format_watch_recovery_diagnostic(
                'inspect', 'saved_binding_unverified', error=exc,
            )))
            self.time_var.set(self.text('watch_recovery_local_unavailable'))
            self.refresh_progress_actions()
            return
        generation = self._progress_generation
        request = (generation, binding)
        if self._inspecting_retained_capture == request:
            return
        self._retained_capture = None
        self.refresh_progress_actions()

        def inspect() -> None:
            """Collect only journal metadata for the next matching session.

            Returns:
                None: Queues the displayed count and head, or a read failure.
            """
            capture = None
            failure = ''
            reason = 'completion_plan_read_failed'
            try:
                plans = self.pending(binding=binding)
                if plans:
                    reason = 'journal_read_failed'
                    plan = plans[0]
                    path = plan.directory / 'watch-drain.json'
                    try:
                        journal = WatchQueueStore.read_journal(path)
                    except FileNotFoundError:
                        capture = RetainedWatchCapture(plan, len(plans), None, '')
                    else:
                        version = journal.get('schema_version', 1)
                        pending = journal.get('pending')
                        if (type(version) is not int or version not in (1, 2)
                                or journal.get('binding', binding) != binding
                                or (version == 2 and (
                                    journal.get('binding') != binding
                                    or journal.get('session_id') != plan.session_id
                                ))
                                or not isinstance(pending, list)
                                or any(not isinstance(path, str) for path in pending)):
                            reason = 'journal_metadata_unverified'
                            raise ValueError('Retained capture journal needs review.')
                        head = Path(pending[0]).name if pending else ''
                        capture = RetainedWatchCapture(plan, len(plans), len(pending), head)
            except Exception as exc:  # noqa: BLE001
                failure = format_watch_recovery_diagnostic('inspect', reason, error=exc)
            self._retained_capture_results.put((generation, binding, capture, failure))

        self._inspecting_retained_capture = request
        try:
            threading.Thread(
                target=inspect, name='PHPGalleryRetainedCapture', daemon=True,
            ).start()
        except RuntimeError as exc:
            self._inspecting_retained_capture = None
            self.time_var.set(self.text('watch_recovery_local_unavailable'))
            self.host.events.put(('warning', format_watch_recovery_diagnostic(
                'inspect', 'reader_start_failed', error=exc,
            )))

    def _accept_retained_capture(self) -> None:
        """Show a passive summary only for the current recovery view.

        Returns:
            None: Updates local counts and enables closure for the exact plan.
        """
        request = getattr(self, '_inspecting_retained_capture', None)
        if request is None:
            return
        try:
            generation, binding, capture, failure = (
                self._retained_capture_results.get_nowait()
            )
        except queue.Empty:
            return
        if request != (generation, binding):
            return
        self._inspecting_retained_capture = None
        if self.operation is not None or self._progress_generation != generation:
            return
        watcher = self.host.worker
        if watcher is not None and watcher.is_alive():
            return
        try:
            if self.binding() != binding:
                return
        except ValueError:
            return
        self._retained_capture = capture
        if failure:
            detail = self.text('watch_recovery_local_unavailable')
        elif capture is None:
            reason = 'no_pending_plan'
            detail = self.text('watch_recovery_no_session')
        elif capture.photo_count is None:
            reason = 'completion_plan_without_photo_journal'
            detail = self.text('watch_recovery_no_journal').format(
                sessions=capture.session_count,
            )
        else:
            reason = 'retained_queue_read'
            detail = self.text('watch_recovery_counts').format(
                sessions=capture.session_count, photos=capture.photo_count,
            )
            if capture.head_name:
                detail += '\n' + self.text('watch_recovery_first').format(
                    filename=capture.head_name,
                )
        if failure:
            self.host.events.put(('warning', failure))
        else:
            self.host.events.put(('info', format_watch_recovery_diagnostic(
                'inspect', reason, sessions=capture.session_count if capture else 0,
                pending=capture.photo_count if capture else None,
            )))
        self.time_var.set(detail)
        self.refresh_progress_actions()

    def close_retained_capture(self) -> None:
        """Confirm local closure of the capture displayed in the recovery view.

        Returns:
            None: Uses the same durable closure markers as Force Finish while
                keeping original sources, upload evidence and local reports.
        """
        capture = self._retained_capture
        if (FlightUI._watcher_reset_blocked(self) or capture is None or self.busy()
                or self._capture_recovery_restore_pending):
            return
        if not messagebox.askyesno(
            self.text('watch_recovery_close'), self.text('watch_recovery_confirm_close'),
            parent=self.dialog_owner(), default='no',
        ):
            return
        if (self._retained_capture is not capture or self.busy()
                or self._capture_recovery_restore_pending):
            return
        self._retained_capture_closing = True
        self.detail_var.set(self.text('force_finish_saving'))
        self.refresh_progress_actions()

        def settled(persisted: bool) -> None:
            """Release recovery controls after the matching local write.

            Args:
                persisted (bool): Original capture closure read back successfully.
            Returns:
                None: Shows the next available action or leaves closure retryable.
            """
            self._retained_capture_closing = False
            self._recovery_state = None
            if persisted:
                self._retained_capture = None
                self._capture_recovery_retry_at = None
                self._progress_generation += 1
                if self.dialog is not None and self.dialog.winfo_exists():
                    self.dialog.withdraw()
            detail = self.text(
                'watch_recovery_closed' if persisted else 'watch_recovery_close_failed',
            )
            self.detail_var.set(detail)
            self.host.status_var.set(detail)
            self.refresh_progress_actions()

        self.host.close_retained_watch(capture.plan, settled)

    def _recover_capture(self, *, requested: bool = False) -> None:
        """Resolve interrupted capture without exposing repair decisions to users.

        Args:
            requested (bool): Explicit Finish failed before creating a worker;
                permit retention of its still-running capture producer.
        Returns:
            None: Reads only the captured binding off Tk. The normal event owner
                attempts same-target restoration, then durable local retention.
                Independent PDF work and submitted revocation are untouched.
        """
        if (FlightUI._watcher_reset_blocked(self)
                or self.independent_operation() or self._force_finish_pending
                or getattr(self, '_retained_capture_closing', False)
                or getattr(self, '_force_stop_pending', False)
                or self._capture_recovery_worker is not None
                or self._capture_recovery_restore_pending
                or (self._capture_recovery_retry_at is not None
                    and time.monotonic() < self._capture_recovery_retry_at)):
            return
        operation = self.operation
        watcher = self.host.worker
        if (not requested and operation is None and watcher is not None
                and watcher.is_alive()
                and not getattr(watcher, '_recovery_review_only', False)):
            return
        if (operation is not None and (
                operation.server_request_sent or operation.wants_revoke
                or operation.cancel.is_set() or operation.is_alive())):
            return
        try:
            binding = self.binding()
        except ValueError:
            self.detail_var.set(self.text('capture_recovery_unavailable'))
            return
        generation = self._progress_generation
        self._capture_recovery_retry_at = None
        directory = self.directory
        results = self._capture_recovery_results
        self.detail_var.set(self.text('capture_recovering'))

        def inspect() -> None:
            """Read exact-target pending plans without touching native widgets.

            Returns:
                None: Enqueues owned plans or an unreadable-state marker.
            """
            try:
                plans = tuple(pending_sessions(directory, binding))
            except (OSError, ValueError, RuntimeError):
                plans = None
            results.put((generation, operation, binding, plans))

        worker = threading.Thread(
            target=inspect, name='PHPGalleryCaptureRecovery', daemon=True,
        )
        self._capture_recovery_worker = worker
        try:
            worker.start()
        except RuntimeError:
            self._capture_recovery_worker = None
            self._capture_recovery_retry_at = time.monotonic() + CAPTURE_RECOVERY_RETRY_SECONDS
            self.detail_var.set(self.text('capture_recovery_unavailable'))

    def _accept_capture_recovery(self) -> None:
        """Apply background recovery only to its original operation and binding.

        Returns:
            None: Restores the same approved target once, or leaves the
                original session paused for an explicit Continue.
        """
        try:
            generation, operation, binding, plans = self._capture_recovery_results.get_nowait()
        except queue.Empty:
            return
        self._capture_recovery_worker = None
        if (FlightUI._watcher_reset_blocked(self)
                or generation != self._progress_generation or self.operation is not operation
                or getattr(self, '_retained_capture_closing', False)
                or self._capture_recovery_restore_pending):
            return
        try:
            current_binding = self.binding()
        except ValueError:
            return
        if current_binding != binding:
            return
        if plans is None:
            self._capture_recovery_retry_at = time.monotonic() + CAPTURE_RECOVERY_RETRY_SECONDS
            self.detail_var.set(self.text('capture_recovery_unavailable'))
            self.host.status_var.set(self.text('capture_recovery_unavailable'))
            return
        if not plans and operation is None:
            self._recovery_state = (binding, 'idle')
            if self.dialog is not None and self.dialog.winfo_exists():
                self.dialog.withdraw()
            return
        restore = getattr(self.host, 'restore_capture_upload_target', None)
        if callable(restore) and not self._capture_recovery_restore_attempted:
            self._capture_recovery_restore_attempted = True
            self._restore_pending_capture(binding, explicit=False)
            return
        self.detail_var.set(self.text('capture_recovery_paused'))

    def _restore_pending_capture(self, binding: str, *, explicit: bool) -> None:
        """Restore the same target before admitting automatic or explicit recovery.

        Args:
            binding (str): Original verified local upload binding.
            explicit (bool): The user pressed Continue and may release a saved
                interruption or follow the existing reconnect flow.
        Returns:
            None: Starts only the currently owned recovery after verification.
        """
        request = object()
        self._capture_recovery_restore_pending = request
        generation = self._progress_generation
        operation = self.operation
        if explicit:
            message = self.text('capture_recovering')
            self.detail_var.set(message)
            self.host.status_var.set(message)
            self.refresh_progress_actions()

        def restored(available: bool) -> None:
            """Apply a completed verification only to the owning recovery.

            Args:
                available (bool): The exact original upload gallery is ready.
            Returns:
                None: Continues the same work or leaves it available for review.
            """
            if self._capture_recovery_restore_pending is not request:
                return
            self._capture_recovery_restore_pending = None
            if (generation != self._progress_generation
                    or self.operation is not operation
                    or getattr(self.host, 'exiting', False) is True):
                return
            try:
                if self.binding() != binding:
                    return
            except ValueError:
                return
            if available:
                if explicit:
                    self.begin(
                        automatic=True, explicit_retry=True, continue_pending=True,
                    )
                else:
                    self.begin(automatic=True, explicit_retry=True)
            elif explicit:
                message = self.text('watch_recovery_reconnect_failed')
                self.detail_var.set(message)
                self.host.status_var.set(message)
                self.refresh_progress_actions()
                self.host.reconnect_upload_gallery()
            else:
                self.detail_var.set(self.text('capture_recovery_paused'))

        try:
            self.host.restore_capture_upload_target(binding, restored)
        except (OSError, ValueError, RuntimeError):
            restored(False)

    def can_start(self) -> bool:
        """Refuse a new flight while existing accepted work needs completion.

        Returns:
            bool: True when current connection work can be admitted.
        """
        if self.busy() or self.host.api_key_revoke_running:
            self.show_progress()
            return False
        try:
            pending = self.pending()
        except (OSError, ValueError) as exc:
            self.host.events.put(("warning", str(exc)))
            self.show_recovery()
            return False
        if pending:
            self.show_recovery()
            return False
        return True

    def start_session(self, *, photos_only: bool = False) -> Path:
        """Prepare automatic acquisition before the photo watcher becomes active.

        Returns:
            Path: Shared session directory for the photograph queue and recorder.
                An opted-out recorder waits without opening SimConnect, allowing
                the recording switch to admit acquisition later in this session.

        Args:
            photos_only (bool): Start a contextual game capture with reports
                disabled in its initial plan.

        Raises:
            ValueError: Exclusive ownership or preferences cannot be saved.
        """
        if self.load_error or self.lease is None:
            raise ValueError(self.load_error or "Flight workspace unavailable.")
        linked = self.host.linking_manager.linked_upload_mode_selected()
        target = self.host.linking_manager.selected_upload_target() if linked else None
        if linked and target is None:
            raise ValueError(self.text("linked_target_unavailable"))
        if target is not None:
            target_binding = target.binding()
            if (not isinstance(target_binding, str)
                    or re.fullmatch(r"[0-9a-f]{64}", target_binding) is None
                    or target_binding != self.host.linking_manager.linked_upload_binding()):
                raise ValueError(self.text("linked_target_unavailable"))
        else:
            target_binding = None
        if not self.save_preferences():
            raise ValueError("Flight preferences must be saved before starting.")
        gallery_identity = (
            target.issuer + " #" + str(target.gallery_id)
            if target is not None else self.normalize(self.host.config.gallery_url)
        )
        self.recorder = FlightRecorder(
            self.directory, gallery_identity,
            self.host.config.simconnect_dll_path,
            lambda level, message: self.host.events.put((level, message)),
        )
        if not self.preferences.recording:
            self.recorder.pause()
        directory = self.recorder.directory
        directory.mkdir(parents=True, exist_ok=True)
        if self.dispatch is not None:
            self.dispatch.save(directory)
        self.active_dispatch = self.dispatch
        self.active_dispatch_directory = directory
        self.plan = SessionPlan(
            directory=directory,
            binding=target_binding if target_binding is not None else self.binding(),
            languages=self.preferences.languages,
            reports_enabled=(
                not photos_only and self.preferences.reports and self.preferences.recording
            ),
            local_only=False,
        )
        self.plan.save()
        self._recovery_state = None
        # A new capture fences delayed recovery even for the same gallery.
        self._progress_generation += 1
        self._capture_recovery_restore_attempted = False
        self._capture_recovery_restore_pending = None
        self._capture_recovery_retry_at = None
        self.recorder.start()
        self.dispatch = None
        self.refresh_dispatch_status()
        return directory

    def resume_session(
        self, plan: SessionPlan, *, recorder: FlightRecorder | None = None,
    ) -> Path:
        """Continue the sole caller-authorized incomplete flight session.

        The caller must have claimed a valid one-use update-continuation
        envelope. This method independently verifies workspace ownership and
        the current exact binding; it is not a general old-session start path.

        Args:
            plan (SessionPlan): Session directory selected by the verified
                continuation owner. Its disk plan is reloaded before use.
            recorder (FlightRecorder | None): Optional idle recorder already
                restored off the Tk thread; None performs repair-path restore
                synchronously for explicit nonstartup callers.

        Returns:
            Path: Existing session directory whose recorder has been started.

        Raises:
            ValueError: The envelope target is stale, ambiguous or unsafe, or
                the session cannot be restored without creating new work.
            RuntimeError: The exclusive workspace lease is unavailable.
        """
        if (self.load_error or self.lease is None
                or self.busy() or self.is_alive()
                or self.dispatch_loading
                or any(worker.is_alive() for worker in self.resume_workers)
                or self.host.api_key_revoke_running):
            raise ValueError("Flight session continuation is unavailable.")
        self.lease.verify()
        current_binding = self.binding()
        pending = self.pending(binding=current_binding)
        if (len(pending) != 1 or pending[0].directory != plan.directory
                or plan.directory.parent.resolve() != self.directory.resolve()):
            raise ValueError("Flight session continuation no longer matches.")
        restored_plan = SessionPlan.load(plan.directory)
        if (restored_plan.directory != pending[0].directory
                or restored_plan.binding != current_binding
                or restored_plan.completed or restored_plan.abandoned):
            raise ValueError("Flight session continuation needs review.")
        self.lease.verify()
        linked = self.host.linking_manager.linked_upload_mode_selected()
        target = self.host.linking_manager.selected_upload_target() if linked else None
        if linked and target is None:
            raise ValueError(self.text("linked_target_unavailable"))
        if target is not None:
            target_binding = target.binding()
            if (target_binding != current_binding
                    or target_binding != self.host.linking_manager.linked_upload_binding()):
                raise ValueError(self.text("linked_target_unavailable"))
            gallery_identity = target.issuer + " #" + str(target.gallery_id)
        else:
            if restored_plan.local_only:
                raise ValueError(self.text("linked_target_unavailable"))
            gallery_identity = self.normalize(self.host.config.gallery_url)
        if recorder is None:
            recorder = FlightRecorder.from_session(
                restored_plan.directory, gallery_identity,
                self.host.config.simconnect_dll_path,
                lambda level, message: self.host.events.put((level, message)),
            )
        elif (not isinstance(recorder, FlightRecorder)
              or recorder.directory != restored_plan.directory
              or recorder.session_id != restored_plan.session_id
              or recorder.gallery_identity != gallery_identity
              or recorder.ident is not None or recorder.is_alive()
              or recorder.stop_event.is_set()):
            raise ValueError("Prepared flight recorder does not match this continuation.")
        if not self.preferences.recording:
            recorder.pause()
        dispatch = load_dispatch(restored_plan.directory)
        self.lease.verify()
        self.plan = restored_plan
        self.recorder = recorder
        self.active_dispatch = dispatch
        self.active_dispatch_directory = restored_plan.directory
        try:
            recorder.start()
        except RuntimeError:
            self.plan = None
            self.recorder = None
            self.active_dispatch = None
            self.active_dispatch_directory = None
            raise ValueError("Flight recorder could not resume safely.") from None
        self._recovery_state = None
        # Resumption replaces the active capture owner, including same-target
        # restoration callbacks which started before this recorder was admitted.
        self._progress_generation += 1
        self._capture_recovery_restore_attempted = False
        self._capture_recovery_restore_pending = None
        self._capture_recovery_retry_at = None
        self.refresh_dispatch_status()
        return restored_plan.directory

    def busy(self) -> bool:
        """Report lifecycle admission independently of live-thread existence.

        Returns:
            bool: True while rendering, finalization or local capture closure
                owns admission, including server response and local persistence.
        """
        return bool(
            getattr(self, '_retained_capture_closing', False)
            or (self.operation is not None and self.operation.snapshot().phase
                not in {"completed", "error", "cancelled"})
        )

    def independent_operation(self) -> bool:
        """Identify a saved-PDF task that does not own the current flight.

        Returns:
            bool: True for local rendering or explicit saved-report delivery.
        """
        operation = self.operation
        return operation is not None and (
            operation.local_reports is True
            or operation.saved_upload is True
        )

    def pending_revoke_intent(self) -> bool:
        """Retain only this process's confirmed, unsubmitted revoke decision.

        Returns:
            bool: True while consent exists for ordinary finalization; False
                after cancellation, submission, restart or independent PDF work.
        """
        if self.host.linking_manager.linked_upload_mode_selected():
            return False
        operation = self.operation
        return bool(
            operation is not None and not self.independent_operation()
            and operation.wants_revoke is True
            and operation.server_request_sent is False
            and operation.api_key == self.host.config.api_key
            and operation.upload_url
            == self.normalize(self.host.config.gallery_url)
        )

    def retry_finalization(self) -> None:
        """Retry failed accepted work with its still-confirmed revoke intention.

        Returns:
            None: Reuses in-memory consent; never restores consent from disk.
                Failed local abandonment requires its explicit confirmation again.
        """
        if self.operation is not None and self.operation.server_revocation_confirmed:
            if self.operation.local_cleanup_pending:
                self.host.complete_legacy_revocation(self.operation)
            else:
                self.show_progress()
            return
        if self.busy() or self.host.api_key_revoke_running:
            return
        if self.operation is not None and self.operation.abandonment is True:
            self.abandon()
            return
        if self.automatic_completion():
            self.begin(
                self.pending_revoke_intent(), automatic=True, explicit_retry=True,
            )
        else:
            self.begin(self.pending_revoke_intent())

    def continue_watch(self) -> None:
        """Continue the original upload queue with its saved authority.

        Returns:
            None: Reviews a blocked live head, verifies the original linked
                gallery, or starts same-target upload recovery when ready.
                A retained owner or unverified binding is reported in Activity.
        """
        if FlightUI._watcher_reset_blocked(self):
            return
        watcher = self.host.worker
        if watcher is not None and watcher.is_alive():
            if getattr(watcher, '_recovery_review_only', False) is True:
                self.host.events.put(('info', format_watch_recovery_diagnostic(
                    'continue', 'opening_head_review',
                )))
                self.force_continue_watch()
            elif getattr(getattr(watcher, '_queue', None), 'force_finished', False):
                self.host.events.put(('warning', format_watch_recovery_diagnostic(
                    'continue', 'original_transport_still_owned',
                )))
                message = self.text('force_finish_transport_active')
                self.detail_var.set(message)
                self.host.status_var.set(message)
            return
        if (self.busy() or getattr(self, '_force_stop_pending', False)
                or self._capture_recovery_restore_pending):
            reason = ('target_verification_running' if self._capture_recovery_restore_pending
                      else 'local_operation_running')
            self.host.events.put(('info', format_watch_recovery_diagnostic('continue', reason)))
            return
        linking = self.host.linking_manager
        if (linking.linked_upload_mode_selected()
                and linking.selected_upload_target() is None):
            self.show_recovery()
            try:
                binding = self.binding()
            except ValueError as exc:
                message = self.text('watch_recovery_local_unavailable')
                self.detail_var.set(message)
                self.host.status_var.set(message)
                self.host.events.put(('warning', format_watch_recovery_diagnostic(
                    'continue', 'saved_binding_unverified', error=exc,
                )))
                return
            self._restore_pending_capture(binding, explicit=True)
            return
        self.begin(automatic=True, explicit_retry=True, continue_pending=True)

    def is_alive(self) -> bool:
        """Include recorder work in ordinary shutdown/update worker ownership.

        Returns:
            bool: True while recording, finalization or SimBrief has a live thread.
        """
        return bool(
            (self.recorder and self.recorder.is_alive())
            or (self.operation and self.operation.is_alive())
            or (self.dispatch_worker and self.dispatch_worker.is_alive())
        )

    def stop(self) -> None:
        """Preserve unfinished tasks while stopping recording for Exit/update.

        Returns:
            None: Cancels pending SimBrief admission, sensor and independent PDF work;
                ordinary accepted finalization retains its existing drain.
        """
        self.dispatch_cancelled = True
        if self.recorder is not None:
            self.recorder.stop()
        if self.independent_operation():
            self.operation.cancel.set()

    def stop_for_watcher_reset(self) -> None:
        """Stop capture-specific work without canceling independent report tasks.

        Returns:
            None: Declines new continuation and stops the active capture workers.
        """
        self._capture_recovery_restore_pending = None
        self._capture_recovery_retry_at = None
        if self.recorder is not None and self.recorder.is_alive():
            self.recorder.stop()
            self.dispatch_cancelled = True
        if self.operation is not None and not self.independent_operation():
            self.operation.cancel.set()
            if self.dialog is not None and self.dialog.winfo_exists():
                self.dialog.withdraw()

    def watcher_reset_writers_active(self) -> bool:
        """Check for outstanding Watcher-owned plan writers before reset.

        Returns:
            bool: True until capture publishers, recorder and finalization end.
        """
        operation = None if self.independent_operation() else self.operation
        return (
            self._retained_capture_closing
            or self._force_finish_pending
            or getattr(self, '_force_stop_pending', False)
            or any(worker is not None and worker.is_alive() for worker in (
                operation, self.recorder, self.abandon_previous,
                self.dispatch_worker if self.dispatch_cancelled else None,
            ))
        )

    def complete_watcher_reset(self) -> None:
        """Clear active capture controls after local reset is verified.

        Returns:
            None: A new capture starts with fresh progress and recording defaults.
        """
        self.plan = None
        self.recorder = None
        self.active_dispatch = None
        self.active_dispatch_directory = None
        self.resume_workers = []
        self._recovery_state = None
        self._inspecting_retained_capture = None
        self._retained_capture = None
        self._last_terminal = ''
        if not self.independent_operation() and (
            self.operation is None or not self.operation.local_cleanup_pending
        ):
            self.operation = None
            self.automatic_operation = None
            self._preparing_progress = False
            self._progress_generation += 1
            if self.dialog is not None and self.dialog.winfo_exists():
                self.dialog.withdraw()
        self.preferences = replace(
            self.preferences, recording=FlightPreferences().recording,
        )
        self.record_var.set(self.preferences.recording)
        self.refresh_dispatch_status()

    def join(self, timeout: float | None = None) -> None:
        """Join sensor and independent PDF shutdown through the existing drain.

        Args:
            timeout (float | None): Remaining existing shutdown budget.

        Returns:
            None: Returns after sensor/PDF shutdown or budget expiry.
        """
        started = time.monotonic()
        if self.recorder is not None and self.recorder.ident is not None:
            self.recorder.join(timeout=timeout)
        if (
            self.independent_operation() and self.operation is not None
            and self.operation.ident is not None
        ):
            remaining = (
                max(0.0, timeout - (time.monotonic() - started))
                if timeout is not None else None
            )
            self.operation.join(timeout=remaining)
        if self.dispatch_worker is not None and self.dispatch_worker.ident is not None:
            remaining = max(0.0, timeout - (time.monotonic() - started)) if timeout is not None else None
            self.dispatch_worker.join(timeout=remaining)

    def begin(
        self, revoke: bool = False, *, automatic: bool = False,
        photos_only: bool = False, explicit_retry: bool = False,
        continue_pending: bool = False,
    ) -> None:
        """Start drained Stop or an explicitly authorized captured-key Finish.

        Args:
            revoke (bool): Whether this explicit action should finally revoke.
            automatic (bool): Finish already authorizes the captured operation;
                skip confirmation and close its views only after verified success.
            photos_only (bool): Persist report suppression for a contextual
                game capture before draining, including later recovery.
            explicit_retry (bool): Preserve the existing captured Finish intent
                when the user deliberately retries a terminal automatic attempt.
            continue_pending (bool): Treat this as an explicit Continue of the
                original Watcher queue, including a saved interruption.

        Returns:
            None: Starts admitted work and progress. Linked-mode revocation is
                separate; an active ordinary Stop cannot gain revoke authority.
                Explicit Continue records the phase of stopped or admitted work.
        """
        if FlightUI._watcher_reset_blocked(self):
            return
        trigger_reason = self._consume_trigger_reason(
            "watch_recovery_continue" if continue_pending else
            "explicit_retry" if explicit_retry else
            "automatic_finish" if automatic else "explicit_finish",
        )
        linked = self.host.linking_manager.linked_upload_mode_selected()
        if self.host.api_key_revoke_running and not self.busy():
            self.show_progress()
            return
        if revoke and linked:
            messagebox.showinfo(
                self.capture_text("flight_progress"),
                self.text("revoke_temporarily_unavailable"),
                parent=self.dialog_owner(),
            )
            return
        if self.load_error:
            if continue_pending:
                self.host.events.put(('warning', format_watch_recovery_diagnostic(
                    'continue', 'flight_workspace_unavailable',
                )))
            messagebox.showerror(
                self.capture_text("flight_progress"),
                self.load_error,
                parent=self.dialog_owner(),
            )
            return
        if (automatic and not explicit_retry and not self.independent_operation()
                and self.operation is not None
                and (self.operation.abandonment
                     or self.operation.snapshot().phase in {"error", "cancelled"})):
            self.show_progress()
            return
        if self.busy() and self.independent_operation():
            self.show_progress()
            return
        if (
            self.busy() and self.operation is not None
            and self.operation.abandonment is True
        ):
            self.show_progress()
            return
        if automatic and self.busy():
            self.automatic_operation = self.operation
        if (
            self.busy() and self.operation is not None
            and self.operation.wants_revoke
        ):
            self.show_progress()
            return
        continuing_revoke = bool(
            self.pending_revoke_intent() and self.operation is not None
            and self.operation.snapshot().phase == "error"
        )
        if revoke and not automatic and not continuing_revoke:
            if not messagebox.askyesno(
                self.text("finish_revoke"), self.text("confirm_revoke"),
                parent=self.dialog_owner(),
            ):
                return
        if self.busy():
            # An ordinary Stop owns a legacy ticket. It cannot acquire revoke
            # authority by changing a flag while that ticket remains admitted.
            # Finish can be selected explicitly after its drain has completed.
            self.show_progress()
            return
        self._prepare_progress_view()
        recovery_step = 'completion_plan_read'
        try:
            plans = self.pending()
            if (automatic and not revoke and not self.independent_operation()
                    and not plans):
                self.detail_var.set(self.text("automatic_finish_no_work"))
                self.host.status_var.set(self.text("automatic_finish_no_work"))
                if continue_pending:
                    self.host.events.put(('info', format_watch_recovery_diagnostic(
                        'continue', 'no_pending_plan', sessions=0,
                    )))
                self._dismiss_progress_preparation()
                return
            recovery_step = 'target_admission'
            linked_target = (
                self.host.linking_manager.selected_upload_target() if linked else None
            )
            if linked and any(not plan.local_only for plan in plans) and (
                linked_target is None
                or any(plan.binding != linked_target.binding() for plan in plans)
            ):
                raise ValueError(self.text("linked_target_unavailable"))
            photo_worker = self.host.worker
            watching = photo_worker is not None and photo_worker.is_alive()
            if (linked or continue_pending) and not watching:
                # Continue restores one session under the shared pipeline.
                # Older profiles can have more than one pending flight.
                plans = plans[:1]
            if photos_only:
                if not linked:
                    raise ValueError("A linked Watcher capture is required.")
                for plan in plans:
                    if plan.reports_enabled:
                        plan.reports_enabled = False
                        plan.save()
            remote_reports = any(
                plan.reports_enabled and not plan.local_only and not plan.abandoned
                for plan in plans
            )
            gui_admission = None
            upload_url = ''
            api_key = ''
            if (remote_reports and not linked) or revoke:
                owner, port, upload_url, api_key = self.host.capture_flight_admission()
                binding = connection_binding(upload_url, api_key)
                if any(plan.binding != binding for plan in plans):
                    raise ValueError('Flight work belongs to a different gallery connection.')
                gui_admission = (owner, port)
            self.host.api_key_revoke_running = True
            worker = self.host.manual_worker
            self.manual_job_id = (
                self.host.current_job.id
                if worker is not None and worker.is_alive()
                and self.host.current_job is not None else None
            )
            self.host.utc_sync.stop()
            self.stop()
            if revoke:
                self.stop_other_workers()
            if watching:
                drain = getattr(photo_worker, "request_drain", None)
                if not callable(drain):
                    raise RuntimeError("Watcher cannot safely drain accepted files.")
                drain()
            self.resume_workers = []
            recovery_step = 'queue_admission'
            if not watching:
                for plan in plans:
                    if continue_pending:
                        resumed = self.host.resume_flight_photos(
                            plan.directory, continue_retained=True,
                        )
                        if resumed is not None:
                            self.host.worker = resumed
                    else:
                        resumed = self.resume_photos(plan.directory)
                        if resumed is not None:
                            self.resume_workers.append(resumed)
            recovery_step = 'finalization_start'
            self._progress_generation += 1
            self.operation = FinalizationWorker(
                plans, upload_url, api_key, self.wait_for_workers,
                self.submit_revoke, revoke,
                emit=lambda level, message: self.host.events.put((level, message)),
                gui_admission=gui_admission,
                require_gui_admission=(remote_reports and not linked) or revoke,
                linked_target=linked_target if remote_reports else None,
                config_generation=(
                    getattr(self.host.config, '_snapshot_token', None)
                    if revoke else None
                ),
            )
            self.operation.flight_generation = self._progress_generation
            self.operation.trigger_reason = trigger_reason
            if automatic:
                self.automatic_operation = self.operation
                if self.report_dialog is not None and self.report_dialog.winfo_exists():
                    self.report_dialog.withdraw()
            self.host.api_key_revoke_thread = self.operation
            self.operation.update("draining")
            self.operation.start()
            if continue_pending:
                self.host.events.put(('info', format_watch_recovery_diagnostic(
                    'continue', 'finalization_started', sessions=len(plans),
                )))
            self._preparing_progress = False
            self._last_terminal = ""
            self.show_progress()
            self.host.refresh_connection_controls()
        except (OSError, ValueError, RuntimeError) as exc:
            self.host.api_key_revoke_running = False
            self._dismiss_progress_preparation()
            if continue_pending:
                self.show_recovery()
                message = self.text('watch_recovery_resume_failed')
                self.detail_var.set(message)
                self.host.status_var.set(message)
                reason = exc.reason if isinstance(exc, WatchRecoveryError) else f'{recovery_step}_failed'
                self.host.events.put(('warning', format_watch_recovery_diagnostic(
                    'continue', reason, error=exc,
                )))
            elif not revoke and not self.independent_operation():
                self._recover_capture(requested=True)
            else:
                messagebox.showerror(
                    self.capture_text("flight_progress"), str(exc),
                    parent=self.dialog_owner(),
                )

    def stop_other_workers(self) -> None:
        """Stop other authenticated producers only for an explicit Revoke.

        Returns:
            None: Accepted manual imports drain, while new AI leases stop.
        """
        for worker in (self.host.preflight_worker, self.host.ai_worker):
            if worker is not None and worker.is_alive():
                worker.stop()
        self.host.ai_worker_stop_requested = True
        manual = self.host.manual_worker
        if manual is not None and manual.is_alive():
            resume = getattr(manual, "resume", None)
            if callable(resume):
                resume()

    def wait_for_workers(
        self, cancel: threading.Event, deadline: float
    ) -> None:
        """Wait off Tk for current workers and verify accepted photo/import work.

        Args:
            cancel (threading.Event): Explicit whole-operation cancellation.
            deadline (float): Monotonic graceful-drain deadline.

        Returns:
            None: No active or unresolved accepted work remains.

        Raises:
            RuntimeError: A worker failed or accepted files remain unfinished.
            TimeoutError: Work did not drain before its bounded deadline.
        """
        while True:
            if cancel.is_set():
                raise InterruptedError("Pending work is retained.")
            wants_revoke = bool(
                self.operation is not None and self.operation.wants_revoke
            )
            blockers = (
                self.host.api_key_request_blockers() if wants_revoke else []
            )
            watched = self.host.worker
            operation = self.operation
            plan_queues = {
                plan.directory / "watch-drain.json"
                for plan in operation.plans
            } if operation is not None else set()
            matched_queue_paths: set[Path] = set()

            def requires_queue_drain(worker: threading.Thread) -> bool:
                """Check whether an exact owned queue lacks final cutoff proof.

                Args:
                    worker (threading.Thread): Watcher with a durable manifest.
                Returns:
                    bool: True only when the worker matches a captured plan and
                        its queue has not durably completed the frozen cutoff.
                """
                manifest = getattr(worker, "drain_manifest", None)
                if manifest is None or operation is None:
                    return False
                queue_path = Path(manifest)
                if queue_path not in plan_queues:
                    return False
                matched_queue_paths.add(queue_path)
                if not queue_path.exists():
                    return True
                queue_row = read_document(queue_path)
                return not (
                    queue_row.get("frozen") is True
                    and bool(queue_row.get("cutoff"))
                    and queue_row.get("completed") is True
                    and not queue_row.get("pending")
                )

            watcher_blocks_finish = (
                watched is not None and requires_queue_drain(watched)
            )
            resume_extras = [
                worker for worker in self.resume_workers
                if worker.is_alive() and requires_queue_drain(worker)
            ]
            extras = [self.recorder, self.host.utc_sync, *resume_extras]
            if watcher_blocks_finish:
                extras.append(watched)
            if any(worker is not None and worker.is_alive() for worker in extras):
                blockers.append("flight or accepted photo workers")
            if wants_revoke and self.host.semantic_ai_install_running:
                blockers.append("dependency installation")
            attention_detail = ""
            if self.operation is not None and blockers:
                photo_count = 0
                photo_details: list[str] = []
                for plan in self.operation.plans:
                    path = plan.directory / "watch-drain.json"
                    if path.exists() and not plan.abandoned:
                        row = read_document(path)
                        pending = row.get("pending", [])
                        if not isinstance(pending, list):
                            raise ValueError("Pending photograph queue is malformed.")
                        photo_count += len(pending)
                        head = next((item for item in row.get('items', [])
                                     if isinstance(item, dict)
                                     and item.get('state') not in {
                                         'acknowledged', 'skipped'
                                     }), None)
                        if head is not None:
                            source = head.get('source', {})
                            name = Path(str(source.get('path', ''))).name
                            detail = (f'#{head.get("sequence", "?")} {name}: '
                                      f'{head.get("state", "waiting")}')
                            retry = head.get('next_retry_at')
                            if type(retry) in (int, float) and retry > 0:
                                detail += ' · ' + self.text("watch_retry_at").format(
                                    timestamp=time.strftime(
                                        '%Y-%m-%d %H:%M:%S', time.localtime(retry),
                                    ),
                                )
                            if head.get('reason'):
                                detail += ' · ' + str(head['reason'])
                            if (head.get('state') in {
                                    'uncertain', 'blocked', 'missing', 'auth_required'
                            } or (head.get('state') == 'retry_wait'
                                  and head.get('phase') == 'source')):
                                attention_detail = detail
                            progress = row.get('last_change_at')
                            if type(progress) in (int, float) and progress > 0:
                                detail += ' · progress ' + time.strftime(
                                    '%H:%M:%S', time.localtime(progress),
                                )
                            photo_details.append(detail)
                self.operation.update(
                    "draining",
                    str(photo_count) + " photo(s); "
                    + "; ".join([*photo_details, *blockers]),
                )
                if attention_detail:
                    self.operation.update(
                        "error", self.text("watch_needs_attention") + ": "
                        + attention_detail,
                    )
                    raise RuntimeError(
                        self.text("watch_needs_attention") + ": " + attention_detail
                    )
            if not blockers:
                break
            if time.monotonic() >= deadline:
                raise TimeoutError("Workers did not drain: " + "; ".join(blockers))
            cancel.wait(DRAIN_POLL_SECONDS)
        operation = self.operation
        if any(not queue_path.exists() for queue_path in matched_queue_paths):
            raise RuntimeError("The captured Watcher queue is missing; recovery is required.")
        for plan in operation.plans if operation is not None else self.pending():
            path = plan.directory / "watch-drain.json"
            if path.exists():
                row = read_document(path)
                pending = row.get("pending")
                if not isinstance(pending, list) or not isinstance(row.get("frozen"), bool):
                    raise ValueError("Pending photograph queue is malformed.")
                if (pending or not row["frozen"]
                        or row.get('schema_version') == 2 and row.get('completed') is not True):
                    raise RuntimeError(
                        str(row.get("error") or "")
                        or self.text('capture_recovery_paused')
                    )
        for job_id, binding in self.import_bindings.entries().items():
            if not wants_revoke or binding != self.binding():
                continue
            job = self.host.job_store.get_job(job_id)
            if job is None:
                raise RuntimeError("Accepted import state is unavailable.")
            counts = job.counts()
            if counts["failed"] or counts["cancelled"] or counts["active"]:
                raise RuntimeError(
                    "Accepted import files remain unresolved. Retry the import."
                )
            self.import_bindings.resolve(job_id)

    def submit_revoke(self) -> None:
        """Revoke only this worker's admitted key and queue its exact cleanup.

        Returns:
            None: Positive server confirmation queues an operation-bound event;
                the worker retains its work ticket through GUI persistence.

        Raises:
            RuntimeError: This is not the exact submitted GUI revoke operation,
                or the response does not positively confirm the requested action.
        """
        operation = self.operation
        if (operation is None or threading.current_thread() is not operation
                or operation._gui_work is None
                or operation._gui_work.kind != 'legacy_revoke'
                or not operation.wants_revoke or not operation.server_request_sent):
            raise RuntimeError('Revocation ownership is unavailable.')
        response = self.revoke_transport(
            operation.upload_url, operation.api_key,
            before_request=operation._gui_work.checkpoint,
        )
        if response.get('ok') is not True or response.get('action') != 'revoke':
            raise RuntimeError('Server revocation is unconfirmed.')
        operation.confirm_server_revocation()
        self.host.events.put({
            'kind': 'api_key_revoked', 'payload': {'operation': operation},
        })

    def revoked(self, error: str = "") -> None:
        """Acknowledge GUI-owned local credential persistence.

        Args:
            error (str): Empty on success; otherwise local recovery explanation.

        Returns:
            None: Updates the final phase and permits 100 percent only on success.
        """
        if self.operation is not None:
            self.operation.confirm_local_cleanup(error)

    def automatic_completion(self) -> bool:
        """Identify the exact one-click Finish or local PDF operation.

        Returns:
            bool: True only while the current worker is the captured Finish or
                local-render operation; unrelated upload workers do not inherit
                its automatic completion UI mode.
        """
        return self.operation is not None and self.operation is self.automatic_operation

    def _prepare_progress_view(self) -> None:
        """Paint a populated busy view before loading accepted finalization work.

        Returns:
            None: Shows the preparation phase without implying measured progress.
        """
        if getattr(self, "_preparing_progress", False) is True:
            return
        self._progress_generation += 1
        self._force_finish_pending = False
        self._preparing_progress = True
        self.phase_var.set(self.text("phase_preparing"))
        self.detail_var.set(self.text("finish_preparing_detail"))
        self.time_var.set("")
        self.show_progress()
        if self.activity_bar is not None:
            self.activity_bar.start(PROGRESS_TICK_MS)
            self._animation_running = True
        if self.bar is not None:
            self.bar.grid_remove()
        if self.dialog is not None:
            # Paint the phase, body and activity before any local history read.
            # update_idletasks excludes user input and cannot duplicate Finish.
            self.dialog.update_idletasks()

    def _dismiss_progress_preparation(self) -> None:
        """Remove preparation feedback when admission fails before a worker starts.

        Returns:
            None: Stops the busy cue and leaves the existing error dialog in charge.
        """
        self._preparing_progress = False
        if self.activity_bar is not None:
            self.activity_bar.stop()
            self._animation_running = False
        if self.dialog is not None:
            self.dialog.withdraw()

    def show_progress(self) -> None:
        """Open or restore the one shared nonmodal progress view.

        Returns:
            None: Shows pending or active uploads and independent PDF controls
                without changing upload or revocation intent.
        """
        local_mode = self.independent_operation()
        saved_upload = bool(
            self.operation is not None
            and self.operation.saved_upload is True
        )
        abandoning = bool(
            self.operation is not None and self.operation.abandonment is True
        )
        mode = "abandon" if abandoning else "upload" if saved_upload else local_mode
        preparing = getattr(self, "_preparing_progress", False) is True
        if self.operation is None and not preparing:
            self.phase_var.set(self.text("review_pending_uploads"))
            self.detail_var.set(self.text('capture_recovery_paused'))
            self.time_var.set("")
        elif self.operation is not None and not preparing:
            progress = self.operation.snapshot()
            self.phase_var.set(self.text(
                "phase_preparing" if progress.phase == "idle"
                else "phase_" + progress.phase,
            ))
            self.detail_var.set(progress.detail or self.text("finish_preparing_detail"))
        if self.dialog is not None and self.dialog.winfo_exists():
            if self._progress_local_mode == mode:
                self.refresh_tasks()
                self.refresh_progress_actions()
                self.dialog.deiconify()
                self.dialog.lift()
                if self.operation is not None and self.operation.snapshot().phase == 'completed':
                    self.keep_progress_open()
                return
            self.dialog.destroy()
            self._animation_running = False
        self._progress_local_mode = mode
        # Background progress must not become a child of an unrelated prompt.
        owner = self.host.root
        progress_title = self.capture_text("flight_progress")
        dialog = OverlayPanel(owner, progress_title)
        self.dialog = dialog
        self._contextual_dialog_caption = (dialog, progress_title)
        frame = dialog.body
        frame.columnconfigure(0, weight=1)
        frame.rowconfigure(3, weight=1)
        ttk.Label(
            frame, textvariable=self.phase_var, style="Section.TLabel"
        ).grid(row=0, column=0, sticky="ew")
        self.bar = ttk.Progressbar(frame, maximum=100, length=440)
        # Compact gutters preserve room for every report edition on short hosts.
        self.bar.grid(row=1, column=0, sticky="ew", pady=(8, 4))
        self.activity_bar = ttk.Progressbar(
            frame, mode="indeterminate", length=440
        )
        self.activity_bar.grid(row=2, column=0, sticky="ew", pady=(0, 4))
        self.task_frame = ttk.Frame(frame)
        self.task_frame.grid(row=3, column=0, sticky="nsew", pady=(0, 4))
        self.task_frame.columnconfigure(0, weight=1)
        self.task_frame.rowconfigure(0, weight=1)
        task_tree = ttk.Treeview(
            self.task_frame, columns=("state",), show="tree headings", height=PROGRESS_TASK_ROWS
        )
        task_tree.heading("#0", text=progress_title)
        self.task_tree = task_tree
        self._contextual_task_heading = (task_tree, progress_title)
        self.task_tree.heading("state", text=self.text("phase_idle"))
        for column, width, minimum in zip(
            ('#0', 'state'), PROGRESS_TASK_WIDTHS, PROGRESS_TASK_MIN_WIDTHS, strict=True,
        ):
            self.task_tree.column(column, width=width, minwidth=minimum)
        self.task_tree.grid(row=0, column=0, sticky="nsew")
        task_scroll = ttk.Scrollbar(
            self.task_frame, orient="vertical", command=self.task_tree.yview,
        )
        task_scroll.grid(row=0, column=1, sticky="ns")
        self.task_tree.configure(yscrollcommand=task_scroll.set)
        self.progress_detail_label = ttk.Label(
            frame, textvariable=self.detail_var, wraplength=520
        )
        self.progress_detail_label.grid(row=4, column=0, sticky="ew")
        timing_label = ttk.Label(frame, textvariable=self.time_var, wraplength=520)
        timing_label.grid(row=5, column=0, sticky="ew", pady=4)
        frame.bind('<Configure>', lambda event: (
            self.progress_detail_label.configure(wraplength=max(1, event.width - 32)),
            timing_label.configure(wraplength=max(1, event.width - 32)),
        ))
        self.completion_var = tk.StringVar(self.host.root, value='')
        self.autoclose_frame = ttk.Frame(self.dialog.footer)
        self.autoclose_frame.pack(fill='x')
        ttk.Label(
            self.autoclose_frame, textvariable=self.completion_var,
            style='Muted.TLabel',
        ).pack(side='left')
        if local_mode:
            Button(
                self.autoclose_frame, text=self.text('keep_open'),
                command=self.keep_progress_open, style='Compact.TButton',
            ).pack(side='right')
        self.autoclose_frame.pack_forget()
        actions = ttk.Frame(self.dialog.footer)
        actions.pack(fill="x", pady=(4, 0))
        self.progress_actions = actions
        actions.bind('<Configure>', lambda _event: self.refresh_progress_actions())
        ordinary_actions = (
            ("watch_recovery_resume_pending", self.continue_watch),
            ("watch_recovery_close", self.close_retained_capture),
            ("force_stop_watch", self.force_stop_watch),
        )
        local_actions = (
            ("retry", self.retry_saved_upload if saved_upload else self.create_local_pdf),
            ("cancel_upload" if saved_upload else "cancel_pdf", self.cancel_local_pdf),
            ("open_reports", self.open_reports),
            ("hide", self.dialog.withdraw),
        )
        abandon_actions: tuple[tuple[str, Callable[[], None]], ...] = ()
        choices = (
            abandon_actions if abandoning else
            local_actions if local_mode else ordinary_actions
        )
        self.progress_buttons = {
            label: Button(
                actions, text=self.text(label), command=command,
                style=("Accent.Compact.TButton"
                       if label in {"retry", "watch_recovery_resume_pending"}
                       else "Compact.TButton"),
            )
            for label, command in choices
        }
        self.refresh_tasks()
        self.refresh_progress_actions()
        self.dialog.protocol("WM_DELETE_WINDOW", self.dialog.withdraw)
        self.dialog.bind("<Escape>", lambda _event: self.dialog.withdraw())
        self.dialog.deiconify()
        if preparing:
            self.dialog.update_idletasks()

    def keep_progress_open(self) -> None:
        """Cancel only the current presentation countdown.

        Returns:
            None: Keeps completed results visible without touching worker state.
        """
        self._autoclose_cancelled = True
        self._autoclose_operation = self.operation
        self._autoclose_deadline = None
        if hasattr(self, 'autoclose_frame'):
            self.autoclose_frame.pack_forget()

    def refresh_autoclose(self) -> None:
        """Dismiss successful progress after its visible three-second notice.

        Returns:
            None: Errors, cancellation, hidden views and replacement operations
                never inherit an earlier operation's countdown. Capture status
                works without a mapped repair-action row.
        """
        operation = self.operation
        if getattr(self, '_autoclose_operation', None) is not operation:
            self._autoclose_operation = operation
            self._autoclose_deadline = None
            self._autoclose_cancelled = False
        dialog = self.dialog
        if (operation is None or dialog is None or not dialog.winfo_exists()
                or not hasattr(self, 'autoclose_frame')):
            return
        if (operation.snapshot().phase != 'completed'
                or self._autoclose_cancelled or not dialog.winfo_ismapped()):
            self.autoclose_frame.pack_forget()
            return
        now = time.monotonic()
        if self._autoclose_deadline is None:
            self._autoclose_deadline = now + SUCCESS_AUTOCLOSE_SECONDS
        remaining = max(0, ceil(self._autoclose_deadline - now))
        if remaining == 0:
            self.keep_progress_open()
            dialog.withdraw()
            return
        self.completion_var.set(self.text('progress_autoclose').format(seconds=remaining))
        # Capture has no action row. Only independent PDF controls supply a
        # mapped sibling which Tk can use as a pack-order anchor.
        if self.progress_actions.winfo_manager() == 'pack':
            self.autoclose_frame.pack(fill='x', before=self.progress_actions)
        else:
            self.autoclose_frame.pack(fill='x')

    def refresh_progress_actions(self) -> None:
        """Show only actions usable for the current progress or recovery state.

        Returns:
            None: Shows Continue and Interrupt only when applicable to capture.
                Independent report operations keep their own controls.
        """
        actions = self.progress_actions
        if actions is None or not actions.winfo_exists():
            return
        operation = self.operation
        phase = operation.snapshot().phase if operation is not None else None
        running = phase not in {None, "completed", "error", "cancelled"}
        choices: list[str] = []
        preparing = getattr(self, "_preparing_progress", False) is True
        if self.independent_operation():
            if not preparing:
                if running:
                    choices.append("cancel_upload" if operation.saved_upload else "cancel_pdf")
                elif phase != "completed":
                    choices.append("retry")
                choices.append("open_reports")
            choices.append("hide")
        elif not preparing and (operation is None or not operation.abandonment):
            watcher = self.host.worker
            if watcher is not None and watcher.is_alive():
                if getattr(watcher, '_recovery_review_only', False) is True:
                    choices.append("watch_recovery_resume_pending")
                if operation is None or not operation.server_request_sent:
                    choices.append("force_stop_watch")
            elif (not running and not self._capture_recovery_restore_pending
                  and self.session_state() in {"pending", "blocked"}):
                choices.append("watch_recovery_resume_pending")
                if self.operation is None and self._retained_capture is not None:
                    choices.append("watch_recovery_close")
        choices = [label for label in choices if label in self.progress_buttons]
        for label, button in self.progress_buttons.items():
            if label not in choices:
                button.grid_remove()
        if not choices:
            actions.pack_forget()
            return
        if actions.winfo_manager() != 'pack':
            actions.pack(fill='x', pady=(4, 0))
        for label in choices:
            button = self.progress_buttons[label]
            button.configure(text=self.text(label))
        layout_actions(
            actions, [self.progress_buttons[label] for label in choices],
            PROGRESS_ACTION_COLUMNS,
        )

    def force_continue_watch(self) -> None:
        """Open the host's exact-head review without retrying an upload.

        Returns:
            None: Delegates review to the Watcher-owned recovery coordinator.
        """
        review = getattr(self.host, "open_watch_recovery_review", None)
        if callable(review):
            review()

    def force_finish(self) -> None:
        """Persist retained capture completion through the App owner.

        Returns:
            None: Retries unavailable persistence automatically; successful
                durable publication resolves logical Finish without claiming
                that a live HTTP request was canceled.
        """
        if (FlightUI._watcher_reset_blocked(self)
                or getattr(self, "_force_finish_pending", False)):
            return
        publish = getattr(self.host, "force_finish_flight", None)
        if not callable(publish):
            self.detail_var.set(self.text("force_finish_unavailable"))
            return
        operation = self.operation
        generation = getattr(self, "_progress_generation", 0)
        self._force_finish_pending = True
        self.detail_var.set(self.text("force_finish_saving"))
        self.refresh_progress_actions()

        def resolved(result: str) -> None:
            """Apply the host's durable result only to this progress generation.

            Args:
                result (str): Host result: persisted, pending_transport, or failed.
            Returns:
                None: Resolves or preserves Finish according to durable publication.
            """
            if (getattr(self, "_progress_generation", 0) != generation
                    or self.operation is not operation):
                return
            self._force_finish_pending = False
            if result in {"persisted", "pending_transport"}:
                if operation is not None and not operation.cancel.is_set():
                    operation.cancel.set()
                if self.operation is operation:
                    self.operation = None
                if self.automatic_operation is operation:
                    self.automatic_operation = None
                self._preparing_progress = False
                self._recovery_state = None
                self._capture_recovery_retry_at = None
                self._progress_generation += 1
                self._last_terminal = "force_finished"
                key = ("force_finish_transport_active"
                       if result == "pending_transport"
                       else "force_finish_saved")
                self.detail_var.set(self.text(key))
                if self.dialog is not None and self.dialog.winfo_exists():
                    self.dialog.withdraw()
                self.host.status_var.set(self.text(key))
                return
            self.detail_var.set(self.text("force_finish_failed"))
            self._capture_recovery_retry_at = time.monotonic() + CAPTURE_RECOVERY_RETRY_SECONDS
            self.refresh_progress_actions()

        try:
            queued = publish(on_result=resolved)
        except Exception:  # noqa: BLE001
            queued = False
        if queued is not True:
            self._force_finish_pending = False
            self._capture_recovery_retry_at = time.monotonic() + CAPTURE_RECOVERY_RETRY_SECONDS
            self.detail_var.set(self.text("force_finish_failed"))
            self.refresh_progress_actions()


    def force_stop_watch(self) -> None:
        """Request retained Stop and cancel Finish only after durable readback.

        Returns:
            None: Leaves the waiter active until the app confirms retention.
        """
        if (FlightUI._watcher_reset_blocked(self)
                or getattr(self, "_force_stop_pending", False)
                or self.operation is not None and self.operation.server_request_sent):
            return
        stop = getattr(self.host, "stop_and_keep_watch_pending", None)
        if not callable(stop):
            self.detail_var.set(self.text("watch_stop_not_persisted"))
            return
        self._force_stop_pending = True
        self.detail_var.set(self.text("watch_stop_stopping"))

        def persisted(success: bool) -> None:
            """Apply the host's readback result on its Tk event owner.

            Args:
                success (bool): Whether durable retained-stop publication passed.
            Returns:
                None: Cancels only the finalization waiter after verified persistence.
            """
            self._force_stop_pending = False
            if not success:
                self.detail_var.set(self.text("watch_stop_not_persisted"))
                return
            self.detail_var.set(self.text("watch_finish_retained"))
            if self.operation is not None and not self.operation.cancel.is_set():
                self.operation.cancel.set()

        if stop(on_persisted=persisted) is not True:
            self._force_stop_pending = False
            self.detail_var.set(self.text("watch_stop_not_persisted"))

    def cancel_local_pdf(self) -> None:
        """Cancel queued PDF work and drain running renders, retaining results.

        Returns:
            None: Signals local generation or saved upload; never changes
                ordinary finalization or its revoke consent.
        """
        if self.independent_operation():
            self.operation.cancel.set()

    def open_reports(self) -> None:
        """Open the current report directory without touching remote state.

        Returns:
            None: Opens the latest session's report folder or flight workspace.
        """
        plan = (
            self.operation.plans[-1]
            if self.operation is not None and self.operation.plans else self.plan
        )
        if plan is None:
            self.show_reports()
            return
        self.open_pdf_folder(plan.directory)

    def refresh_tasks(self) -> None:
        """Display only current rendered editions or explicitly selected PDF files.

        Returns:
            None: Independent upload progress distinguishes repeated languages
                across revisions; old receipts never qualify newly selected PDFs.
        """
        tree = self.task_tree
        operation = self.operation
        if tree is None or not tree.winfo_exists():
            return
        if getattr(self, "_preparing_progress", False) is True:
            self.task_frame.grid_remove()
            return
        if operation is None or (
            not self.independent_operation()
            and operation.snapshot().phase == "draining"
        ):
            self.task_frame.grid_remove()
            return
        self.task_frame.grid()
        progress = operation.snapshot()
        rows: list[tuple[str, str, str]] = []
        if operation.saved_upload is True:
            editions = [(revision, language, pdf)
                        for revision in operation.saved_revisions
                        for language, pdf in revision.files.items()]
            for index, (revision, language, pdf) in enumerate(editions):
                state = self.text("phase_completed" if progress.done >= index + 2 else "phase_idle")
                if progress.done == index + 1 and progress.phase == "uploading":
                    state = self.text("phase_uploading")
                rows.append((revision.report_id + "-" + language, pdf.name, state))
        else:
            for plan in operation.plans:
                if not plan.reports_enabled and operation.local_reports is not True:
                    rows.append((plan.session_id, self.capture_text("record_flight"),
                                 self.text("phase_completed" if plan.completed else "phase_draining")))
                    continue
                current = progress.render_session == plan.session_id
                for language in plan.languages:
                    state = self.text("phase_idle")
                    if current and language in progress.rendered_languages:
                        state = self.text("report_state_local" if operation.local_reports
                                          else "phase_ready")
                    if (current and progress.phase == "generating"
                            and language in progress.rendering_languages):
                        state = self.text("phase_generating")
                    if (current and progress.phase == "uploading"
                            and progress.detail == language.upper()):
                        state = self.text("phase_uploading")
                    complete = language in plan.receipts or (plan.completed and plan.local_only)
                    if operation.local_reports is not True and complete:
                        state = self.text("phase_completed")
                    rows.append((plan.session_id + "-" + language, language.upper(), state))
        identities = {identity for identity, _label, _state in rows}
        for identity, label, state in rows:
            if tree.exists(identity):
                tree.item(identity, text=label, values=(state,))
            else:
                tree.insert("", "end", iid=identity, text=label, values=(state,))
        for identity in tree.get_children():
            if identity not in identities:
                tree.delete(identity)

    def cancel_revocation(self) -> None:
        """Withdraw final revoke consent without discarding accepted report work.

        Returns:
            None: Already stopped workers remain stopped; progress stays visible.
        """
        if self.operation is not None and self.operation.cancel_revoke():
            self.detail_var.set(self.text("revoke_cancelled"))

    def keep_local(self) -> None:
        """Explicitly resolve pending remote delivery while retaining artifacts.

        Returns:
            None: Persists local retention and resumes local finalization, or
                refuses a still-pending revocation without changing the plan.
        """
        revoke = self.pending_revoke_intent()
        if revoke and self.host.linking_manager.linked_upload_mode_selected():
            messagebox.showinfo(
                self.capture_text('flight_progress'),
                self.text('revoke_temporarily_unavailable'),
                parent=self.dialog_owner(),
            )
            return
        automatic = self.automatic_completion()
        if self.busy() or not messagebox.askyesno(
            self.text("keep_local_revoke" if revoke else "keep_local"),
            self.text("confirm_local_revoke" if revoke else "confirm_local"),
            parent=self.dialog_owner(),
        ):
            return
        try:
            for plan in self.pending():
                plan.local_only = True
                # Retain requested PDFs even for partial/diverted recordings;
                # the renderer preserves their incomplete analysis explicitly.
                plan.save()
            if automatic:
                self.begin(revoke, automatic=True)
            else:
                self.begin(revoke)
        except (OSError, ValueError) as exc:
            messagebox.showerror(
                self.capture_text("flight_progress"),
                str(exc),
                parent=self.dialog_owner(),
            )

    def abandon(self) -> None:
        """Close the captured session after stopping its admitted producers.

        Returns:
            None: Confirmation starts bounded asynchronous shutdown and keeps
                original files. Only the legacy action clears a saved API key.
                Submitted revocation and independent PDF work must settle first.
        """
        if FlightUI._watcher_reset_blocked(self):
            return
        linked = self.host.linking_manager.linked_upload_mode_selected()
        if self.busy() and (
            self.independent_operation()
            or (self.operation is not None and self.operation.abandonment is True)
        ):
            self.show_progress()
            return
        if not messagebox.askyesno(
            self.text("keep_watched_files" if linked else "abandon"),
            self.text("confirm_keep_watched_files" if linked else "confirm_abandon"),
            parent=self.dialog_owner(),
        ):
            return
        try:
            plans = self.pending()
            if linked and not plans:
                return
            previous = self.operation
            if previous is not None:
                if not previous.cancel_revoke() and (
                    previous.is_alive() or self.busy()
                ):
                    self.show_progress()
                    return
                previous.cancel.set()
            self.abandon_previous = previous
            self.host.api_key_revoke_running = True
            self.host.utc_sync.stop()
            self.stop()
            workers = [self.host.worker, *self.resume_workers]
            if not linked:
                workers.extend((
                    self.host.manual_worker, self.host.preflight_worker,
                    self.host.ai_worker,
                ))
                self.host.ai_worker_stop_requested = True
            for worker in workers:
                if worker is not None and worker.is_alive():
                    worker.stop()
            self._progress_generation += 1
            self.operation = FinalizationWorker(
                plans, "" if linked else self.normalize(self.host.config.gallery_url),
                "" if linked else self.host.config.api_key, self.wait_for_abandonment,
                self.submit_revoke, False, forget=None if linked else self.submit_forget,
                emit=lambda level, message: self.host.events.put((level, message)),
                abandon_local=linked,
            )
            self.operation.flight_generation = self._progress_generation
            self._consume_trigger_reason("abandon")
            self.operation.trigger_reason = "abandon"
            self.automatic_operation = self.operation
            self.host.api_key_revoke_thread = self.operation
            self._last_terminal = ""
            self.operation.start()
            self.show_progress()
            self.host.refresh_connection_controls()
        except (OSError, ValueError, RuntimeError) as exc:
            self.host.api_key_revoke_running = False
            messagebox.showerror(
                self.capture_text("flight_progress"),
                str(exc),
                parent=self.dialog_owner(),
            )

    def wait_for_abandonment(
        self, cancel: threading.Event, deadline: float
    ) -> None:
        """Wait for stopped producers without requiring successful delivery.

        Args:
            cancel (threading.Event): Cancellation preserving pending records.
            deadline (float): Existing finalization budget as a monotonic instant.
        Returns:
            None: All previous workers and authenticated requests have settled.
        Raises:
            InterruptedError: Cancellation retains the key and unresolved work.
            TimeoutError: A worker did not stop before the existing deadline.
        """
        local_only = self.operation is not None and self.operation.forget is None
        while True:
            if cancel.is_set():
                raise InterruptedError("Abandonment cancelled; local key retained.")
            workers = (
                self.abandon_previous, self.host.worker, self.recorder, self.dispatch_worker,
                self.host.utc_sync, *self.resume_workers,
            )
            other_work = not local_only and (
                self.host.api_key_request_blockers() or self.host.semantic_ai_install_running
            )
            if not other_work and not any(
                worker is not None and worker.is_alive() for worker in workers
            ):
                return
            if time.monotonic() >= deadline:
                raise TimeoutError("Workers are still stopping; local key retained.")
            cancel.wait(DRAIN_POLL_SECONDS)

    def submit_forget(self) -> None:
        """Queue captured local cleanup after the durable abandonment decision.

        Returns:
            None: Tk owns key persistence; no authentication request is sent.
        """
        operation = self.operation
        if operation is not None:
            self.host.events.put({
                "kind": "flight_abandoned",
                "payload": {"binding": connection_binding(
                    operation.upload_url, operation.api_key
                )},
            })

    def forgotten(self, binding: str) -> None:
        """Acknowledge local abandonment only for the captured connection.

        Args:
            binding (str): Non-secret endpoint/key fingerprint from the worker.
        Returns:
            None: Resolves matching imports and acknowledges durable key cleanup;
                failures stay visible with the key retained for retry.
        """
        operation = self.operation
        if (
            operation is None or not operation.abandonment
            or operation.snapshot().phase != "saving_key"
            or binding != connection_binding(operation.upload_url, operation.api_key)
        ):
            return
        try:
            if binding != self.binding():
                raise ValueError("Saved connection changed before abandonment cleanup.")
            for job_id, owner in self.import_bindings.entries().items():
                if owner == binding:
                    self.import_bindings.resolve(job_id)
            self.host.forget_flight_connection(binding)
        except Exception as exc:
            operation.confirm_local_cleanup(str(exc))
            return
        operation.confirm_local_cleanup()

    def tick(self) -> None:
        """Refresh phase/time feedback without calling native/network operations.

        Returns:
            None: Reports a recovered operation's terminal result and schedules
                the next bounded Tk update while the app is open.
        """
        self._accept_capture_recovery()
        self._accept_retained_capture()
        if (self._capture_recovery_retry_at is not None
                and time.monotonic() >= self._capture_recovery_retry_at):
            self._recover_capture()
        operation = (None if getattr(self, "_preparing_progress", False) is True
                     else self.operation)
        watcher = self.host.worker
        if (watcher is not None and not watcher.is_alive()
                and self.recorder is not None and self.recorder.is_alive()):
            self.recorder.stop()
            self.host.utc_sync.stop()
        self.refresh_telemetry()
        self.accept_simbrief()
        self.refresh_dispatch_status()
        resume_finish = getattr(self.host, 'resume_pending_finish', None)
        if callable(resume_finish):
            resume_finish()
        if operation is not None:
            progress = operation.snapshot()
            terminal = progress.phase in {"completed", "error", "cancelled"}
            now = progress.phase_started if terminal else time.monotonic()
            needs_attention = (
                progress.phase == "error"
                and self.text("watch_needs_attention") in progress.detail
            )
            self.phase_var.set(self.text(
                "watch_needs_attention" if needs_attention else
                "report_cancelled" if self.independent_operation()
                and progress.phase == "cancelled" else "phase_" + progress.phase
            ))
            detail = progress.detail or progress.error
            if getattr(self, "_force_finish_pending", False):
                detail = self.text("force_finish_saving")
            if detail.startswith("pdf_dependency_missing:"):
                module = detail.split(":", 1)[1]
                detail = self.text("pdf_dependency_missing").format(module=module)
                if not getattr(sys, "frozen", False):
                    # Match the active source interpreter instead of a generic
                    # python command that may select a different installation.
                    executable = Path(sys.executable)
                    if executable.name.lower() == "pythonw.exe":
                        executable = executable.with_name("python.exe")
                    requirements = Path(__file__).resolve().parents[1] / "requirements.txt"
                    detail += f'\n"{executable}" -m pip install --user -r "{requirements}"'
            detail += f"  ({progress.done}/{progress.total})"
            if progress.bytes_total:
                detail += f"  {progress.bytes_done}/{progress.bytes_total} B"
            self.detail_var.set(detail)
            elapsed = max(0, int(now - progress.started))
            phase_elapsed = max(0, int(now - progress.phase_started))
            timing = (
                f'{self.text("elapsed")}: {elapsed}s  ·  '
                f'{self.text("phase_elapsed")}: {phase_elapsed}s'
            )
            if progress.eta_seconds is None and not terminal:
                timing += "  ·  " + self.text("estimating")
            elif progress.eta_seconds is not None and not terminal:
                timing += (
                    "  ·  " + self.text("transfer_remaining") + ": "
                    + str(int(progress.eta_seconds)) + "s"
                )
            self.time_var.set(timing)
            if self.bar is not None and self.bar.winfo_exists():
                if not self.bar.winfo_manager():
                    self.bar.grid()
                self.bar.configure(mode="determinate")
                self.bar["value"] = (
                    progress.done * 100 / progress.total if progress.total else 0
                )
            if self.activity_bar is not None and self.activity_bar.winfo_exists():
                if terminal:
                    self.activity_bar.stop()
                    self.activity_bar.grid_remove()
                    self._animation_running = False
                elif not self._animation_running:
                    # Animation means an unknown-duration unit is active, not
                    # a fabricated completion percentage or countdown.
                    self.activity_bar.grid()
                    self.activity_bar.start(PROGRESS_TICK_MS)
                    self._animation_running = True
            self.refresh_tasks()
            if terminal and self._last_terminal != progress.phase:
                self._last_terminal = progress.phase
                if getattr(operation, 'trigger_reason', '') == 'watch_recovery_continue':
                    self.host.events.put((
                        'warning' if progress.phase == 'error' else 'info',
                        format_watch_recovery_diagnostic(
                            'finalization', f'recovered_operation_{progress.phase}',
                        ),
                    ))
                if not self.independent_operation():
                    self._recovery_state = None
                    pending_cleanup = (
                        isinstance(operation, FinalizationWorker)
                        and operation.local_cleanup_pending
                    )
                    self.host.api_key_revoke_running = pending_cleanup
                    self.host.api_key_revoke_thread = operation if pending_cleanup else None
                    self.host.status_var.set(self.text(
                        "watched_files_kept"
                        if (progress.phase == "completed"
                            and operation.abandonment and operation.forget is None)
                        else "phase_" + progress.phase
                    ))
                    self.host.watcher_activity_var.set(
                        self.text("phase_" + progress.phase),
                    )
                    release_watcher = getattr(self.host, "release_contextual_watcher", None)
                    if progress.phase == "completed" and release_watcher is not None:
                        release_watcher()
                self.refresh_reports()
                self.host.refresh_connection_controls()
                if (progress.phase == 'error' and not self.independent_operation()
                        and not operation.abandonment and not operation.wants_revoke):
                    self._recover_capture()
        self.refresh_progress_actions()
        self.refresh_autoclose()
        self.host.root.after(PROGRESS_TICK_MS, self.tick)
