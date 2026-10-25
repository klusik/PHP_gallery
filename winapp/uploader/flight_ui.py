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
import sqlite3
import sys
import threading
import time
from dataclasses import replace
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable, Protocol
import tkinter as tk
from tkinter import filedialog, messagebox, ttk

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
from uploader.flight_report_translations import tr
from uploader.flight_workflow import FinalizationWorker
from uploader.models import ImportJob, JsonValue
from uploader.state_store import JobStateStore
from uploader.state_store import atomic_write_json
from uploader.flight_simbrief import (
    DispatchPlan, fetch_dispatch, identity_query, import_dispatch, load_dispatch,
)
from uploader.flight_navdata import remember_plan
from uploader.ui_theme import GlassCard, icon_button, place_dialog
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
# Consumers: FlightUI.show_progress. Rationale: three columns wrap the six
# ordinary actions into two rows instead of forcing a very wide dialog.
PROGRESS_ACTION_COLUMNS = 3
# Keep identity help and validation readable without a desktop-sized dialog.
# Type: int; Units: pixels; Scope: SimBrief identity sheet.
# Consumers: prompt_simbrief_identity. Rationale: 360 px fits its single input.
SIMBRIEF_PROMPT_WRAP_PX = 360
# Show a normal pilot identity without forcing a very wide control.
# Type: int; Units: character columns; Scope: SimBrief identity entry.
# Consumers: prompt_simbrief_identity. Rationale: 40 columns leaves editing room.
SIMBRIEF_IDENTITY_COLUMNS = 40


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


class FlightUI:
    """Own Tk controls while services own persistence, analysis and network work."""

    def __init__(
        self,
        host: FlightHost,
        directory: Path,
        normalize: Callable[[str], str],
        revoke: Callable[[str, str], dict[str, JsonValue]],
        resume_photos: Callable[[Path], threading.Thread | None],
    ) -> None:
        """Restore preferences and exclusive workspace ownership before actions.

        Args:
            host (FlightHost): Existing Tk app and worker lifecycle owner.
            directory (Path): Per-user private flight workspace.
            normalize (Callable[[str], str]): Existing canonical endpoint policy.
            revoke (Callable[[str, str], dict[str, JsonValue]]): Existing
                URL/key revocation transport returning the JSON response.
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
        self.dialog: tk.Toplevel | None = None
        self.settings_dialog: tk.Toplevel | None = None
        self.report_dialog: tk.Toplevel | None = None
        self.report_tree: ttk.Treeview | None = None
        self.report_rows: dict[str, SavedFlight] = {}
        self.report_files: dict[str, tuple[str, ReportRevision, str]] = {}
        self.report_versions: dict[str, tuple[str, ReportRevision]] = {}
        self.report_group_files: dict[str, list[str]] = {}
        self.report_file_sort_keys: dict[str, ReportFileDetails] = {}
        self.report_button: ttk.Button | None = None
        self.keep_local_button: ttk.Button | None = None
        self.bar: ttk.Progressbar | None = None
        self.activity_bar: ttk.Progressbar | None = None
        self.task_tree: ttk.Treeview | None = None
        self._animation_running = False
        self._progress_local_mode = False
        self.phase_var = tk.StringVar(value="")
        self.detail_var = tk.StringVar(value="")
        self.time_var = tk.StringVar(value="")
        self.report_detail_var = tk.StringVar(value="")
        self.telemetry_var = tk.StringVar(value="IAS — kt · ALT — ft · V/S — fpm")
        self.lease: WorkspaceLease | None = None
        self.load_error = ""
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
        self.refresh_dispatch_status()
        self._last_terminal = ""
        host.root.after(PROGRESS_TICK_MS, self.tick)

    def text(self, key: str) -> str:
        """Translate one new flight-specific control/status label.

        Args:
            key (str): Shared flight translation catalog key.

        Returns:
            str: Label in the selected UI language.
        """
        return tr(self.ui_language.get(), key)

    def dialog_owner(self) -> tk.Misc:
        """Find the invoking window so related dialogs stay together.

        Returns:
            tk.Misc: Focused application toplevel, or the main window.
        """
        focused = self.host.root.focus_get()
        return focused.winfo_toplevel() if focused is not None else self.host.root

    def prompt_simbrief_identity(self, identity: str) -> str | None:
        """Ask for one validated identity in the application's themed dialog.

        Args:
            identity (str): Previously successful identity used as an editable draft.
        Returns:
            str | None: Validated draft after Load, or None after cancellation.
        """
        owner = self.dialog_owner()
        previous_grab, previous_focus = owner.grab_current(), owner.focus_get()
        dialog = tk.Toplevel(owner)
        dialog.withdraw()
        dialog.title("SimBrief")
        dialog.configure(background="#131B26")
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
        ttk.Button(actions, text=self.text("simbrief_cancel"), command=close).pack(side="left")
        ttk.Button(actions, text=self.text("simbrief_load"), command=submit,
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
            None: Updates shared controls without disk, simulator or network work.
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
        self.simbrief_button_var.set(self.text(
            "simbrief_loading_short" if loading else
            "simbrief_unload" if plan is not None else "simbrief_load"
        ))
        prefix = self.text(
            "simbrief_next_flight" if self.dispatch is not None else "simbrief_ready"
        ) + ": "
        self.simbrief_status_var.set(
            prefix + plan.origin + " → " + plan.destination if plan is not None else ""
        )

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
        ttk.Checkbutton(
            frame, text=self.text("record_flight"), variable=self.record_var,
            command=self.save_preferences,
        ).grid(row=0, column=0, sticky="w")
        icon_button(
            frame, "settings", self.open_settings, self.text("flight_settings"),
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
        dialog = tk.Toplevel(owner)
        dialog.withdraw()
        self.settings_dialog = dialog
        dialog.title(self.text("flight_settings"))
        dialog.transient(self.host.root)
        dialog.configure(background="#131B26")
        dialog.resizable(False, False)
        panel = GlassCard(dialog, padding=(24, 20))
        panel.pack(fill="both", expand=True, padx=12, pady=12)
        ttk.Label(
            panel, text=self.text("flight_settings"), style="Section.TLabel",
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
            actions, "≡", self.show_progress, self.text("flight_progress"),
        ).pack(side="left")
        icon_button(
            actions, "↗", self.open_reports, self.text("open_reports"),
        ).pack(side="left", padx=(6, 0))
        ttk.Button(
            actions, text=self.text("saved_reports"), command=self.show_reports,
        ).pack(side="left", padx=(10, 0))
        ttk.Button(
            panel, text=self.text("finish_pdf"), command=self.finish_report,
        ).grid(row=7, column=0, columnspan=2, sticky="ew", pady=(12, 0))
        dialog.bind("<Escape>", lambda _event: dialog.destroy())
        place_dialog(dialog, owner)
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
        self.telemetry_var.set(
            f"IAS {ias_text} kt · ALT {altitude_text} ft · V/S {vertical_text} fpm"
        )

    def finish_report(self) -> None:
        """Finish the flight, its PDFs and revocation without modal confirmation.

        Returns:
            None: Uses loaded OFP for the current or sole pending flight,
                enables all four report editions for this operation,
                then drains accepted work and revokes after report delivery.
                Successful progress closes after durable credential cleanup.
        """
        if self.busy():
            self.begin(True, automatic=True)
            return
        languages = REPORT_LANGUAGES
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
            self.begin(True, automatic=True)
        except (OSError, ValueError) as exc:
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
        self.report_dialog = tk.Toplevel(owner)
        self.report_dialog.withdraw()
        self.report_dialog.title(self.text("saved_reports"))
        self.report_dialog.transient(self.host.root)
        panel = GlassCard(self.report_dialog, padding=(16, 12))
        panel.pack(fill="both", expand=True, padx=10, pady=10)
        ttk.Label(
            panel, text=self.text("report_actions_hint"), wraplength=620,
            style="Muted.TLabel",
        ).pack(anchor="w", pady=(0, 10))
        columns = ("started", "samples", "pdfs", "created", "pages", "state")
        self.report_tree = ttk.Treeview(
            panel, columns=columns, show="tree headings", height=REPORT_VISIBLE_ROWS,
            selectmode="extended",
        )
        self.report_tree.heading("#0", text=self.text("saved_reports"))
        self.report_tree.heading("#0", command=lambda: self.sort_reports("#0"))
        self.report_tree.column("#0", width=300, minwidth=170)
        for name, width in zip(columns, REPORT_COLUMN_WIDTHS, strict=True):
            self.report_tree.heading(name, text=self.text("column_" + name),
                                     command=lambda column=name: self.sort_reports(column))
            self.report_tree.column(name, width=width, minwidth=40)
        self.report_tree.pack(fill="both", expand=True)
        self.report_tree.bind("<<TreeviewSelect>>", lambda _event: self.select_report())
        self.report_tree.bind("<Double-1>", lambda _event: self.open_selected_pdf())
        ttk.Label(
            panel, textvariable=self.report_detail_var, wraplength=620,
            style="Muted.TLabel",
        ).pack(anchor="w", fill="x", pady=(8, 4))
        actions = ttk.Frame(panel, style="Card.TFrame")
        actions.pack(fill="x", pady=(8, 0))
        actions.columnconfigure(0, weight=1)
        actions.columnconfigure(1, weight=1)
        ttk.Button(
            actions, text=self.text("finish_pdf"), command=self.finish_report,
        ).grid(row=0, column=0, sticky="ew", padx=(0, 6), pady=(0, 4))
        self.report_button = ttk.Button(
            actions, text=self.text("create_local_pdf"), command=self.create_local_pdf,
        )
        self.report_button.grid(row=0, column=1, sticky="ew", pady=(0, 4))
        ttk.Button(actions, text=self.text("simbrief_load"),
            command=lambda: self.load_simbrief(saved=True),
        ).grid(row=2, column=0, sticky="ew", padx=(0, 6), pady=(6, 0))
        ttk.Button(actions, text=self.text("simbrief_file"), command=self.attach_ofp_file,
        ).grid(row=2, column=1, sticky="ew", pady=(6, 0))
        ttk.Button(
            actions, text=self.text("open_reports"), command=self.open_selected_reports,
        ).grid(row=1, column=0, sticky="ew", padx=(0, 6))
        ttk.Button(
            actions, text=self.text("open_pdf"), command=self.open_selected_pdf,
        ).grid(row=1, column=1, sticky="ew")
        tools = ttk.Frame(panel, style="Card.TFrame")
        tools.pack(fill="x", pady=(8, 0))
        ttk.Button(
            tools, text=self.text("refresh_reports"), command=self.refresh_reports,
        ).pack(side="left", padx=(0, 6))
        ttk.Button(
            tools, text=self.text("open_app_log"), command=self.host.open_log_file,
        ).pack(side="left")
        ttk.Button(
            tools, text=self.text("upload_saved_pdf"), command=self.upload_saved_pdf,
        ).pack(side="left", padx=(6, 0))
        self.refresh_reports()
        place_dialog(self.report_dialog, owner)
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
            self.operation = FinalizationWorker(
                [plan], "", "", lambda _cancel, _deadline: None,
                lambda: None, False, local_reports=True,
                emit=lambda level, message: self.host.events.put((level, message)),
            )
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
            self.operation = FinalizationWorker(
                [plan], self.normalize(self.host.config.gallery_url),
                self.host.config.api_key, lambda _cancel, _deadline: None,
                lambda: None, False, saved_revision=revision,
                saved_revisions=(revision,) + additional,
                target_gallery=gallery,
                emit=lambda level, message: self.host.events.put((level, message)),
            )
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
            preferences.save(self.directory)
            self.preferences = preferences
            self.refresh_dispatch_status()
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
                self.text("flight_progress"),
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
        """Fingerprint the current saved connection.

        Returns:
            str: Non-secret endpoint/key binding.
        """
        return connection_binding(
            self.normalize(self.host.config.gallery_url),
            self.host.config.api_key,
        )

    def pending(self) -> list[SessionPlan]:
        """Read recoverable work using the current saved key only.

        Returns:
            list[SessionPlan]: Pending connection-bound sessions.

        Raises:
            ValueError: Existing state cannot be safely interpreted.
        """
        if self.load_error:
            raise ValueError(self.load_error)
        return pending_sessions(self.directory, self.binding())

    def can_start(self) -> bool:
        """Refuse a new flight while existing accepted work needs completion.

        Returns:
            bool: True when current connection work can be admitted.
        """
        if self.busy():
            self.show_progress()
            return False
        try:
            pending = self.pending()
        except ValueError as exc:
            self.host.events.put(("warning", str(exc)))
            return False
        if pending:
            self.show_progress()
            messagebox.showinfo(
                self.text("flight_progress"), self.text("finish_pending"),
                parent=self.dialog_owner(),
            )
            return False
        return True

    def start_session(self) -> Path:
        """Prepare automatic acquisition before the photo watcher becomes active.

        Returns:
            Path: Shared session directory for the photograph queue and recorder.
                An opted-out recorder waits without opening SimConnect, allowing
                the recording switch to admit acquisition later in this session.

        Raises:
            ValueError: Exclusive ownership or preferences cannot be saved.
        """
        if self.load_error or self.lease is None:
            raise ValueError(self.load_error or "Flight workspace unavailable.")
        if not self.save_preferences():
            raise ValueError("Flight preferences must be saved before starting.")
        self.recorder = FlightRecorder(
            self.directory, self.normalize(self.host.config.gallery_url),
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
            binding=self.binding(),
            languages=self.preferences.languages,
            reports_enabled=self.preferences.reports and self.preferences.recording,
        )
        self.plan.save()
        self.recorder.start()
        self.dispatch = None
        self.refresh_dispatch_status()
        return directory

    def busy(self) -> bool:
        """Report lifecycle admission independently of live-thread existence.

        Returns:
            bool: True while local rendering or finalization owns admission,
                including server response and local credential persistence.
        """
        return self.operation is not None and self.operation.snapshot().phase not in {
            "completed", "error", "cancelled"
        }

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
        if self.operation is not None and self.operation.abandonment is True:
            self.abandon()
            return
        if self.automatic_completion():
            self.begin(self.pending_revoke_intent(), automatic=True)
        else:
            self.begin(self.pending_revoke_intent())

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

    def begin(self, revoke: bool = False, *, automatic: bool = False) -> None:
        """Start or join Stop/Revoke, honoring the one-click Finish action.

        Args:
            revoke (bool): Whether this explicit action should finally revoke.
            automatic (bool): Finish already authorizes the captured operation;
                skip confirmation and close its views only after verified success.

        Returns:
            None: Shows progress and runs expensive tasks on a worker;
                active abandonment cannot be upgraded to remote revocation.
        """
        if self.load_error:
            messagebox.showerror(
                self.text("flight_progress"),
                self.load_error,
                parent=self.dialog_owner(),
            )
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
            if revoke and self.operation is not None:
                self.stop_other_workers()
                self.operation.request_revoke()
            self.show_progress()
            return
        try:
            plans = self.pending()
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
            photo_worker = self.host.worker
            if photo_worker is not None and photo_worker.is_alive():
                drain = getattr(photo_worker, "request_drain", None)
                if not callable(drain):
                    raise RuntimeError("Watcher cannot safely drain accepted files.")
                drain()
            self.resume_workers = []
            if photo_worker is None or not photo_worker.is_alive():
                for plan in plans:
                    resumed = self.resume_photos(plan.directory)
                    if resumed is not None:
                        self.resume_workers.append(resumed)
            self.operation = FinalizationWorker(
                plans, self.normalize(self.host.config.gallery_url),
                self.host.config.api_key, self.wait_for_workers,
                self.submit_revoke, revoke,
                emit=lambda level, message: self.host.events.put((level, message)),
            )
            if automatic:
                self.automatic_operation = self.operation
                if self.report_dialog is not None and self.report_dialog.winfo_exists():
                    self.report_dialog.withdraw()
            self.host.api_key_revoke_thread = self.operation
            self.operation.start()
            self._last_terminal = ""
            self.show_progress()
            self.host.refresh_connection_controls()
        except (OSError, ValueError, RuntimeError) as exc:
            self.host.api_key_revoke_running = False
            messagebox.showerror(
                self.text("flight_progress"),
                str(exc),
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
            extras = [
                self.host.worker, self.recorder, self.host.utc_sync,
                *self.resume_workers
            ]
            if any(worker is not None and worker.is_alive() for worker in extras):
                blockers.append("flight or accepted photo workers")
            if wants_revoke and self.host.semantic_ai_install_running:
                blockers.append("dependency installation")
            if self.operation is not None and blockers:
                photo_count = 0
                for plan in self.operation.plans:
                    path = plan.directory / "watch-drain.json"
                    if path.exists() and not plan.abandoned:
                        pending = read_document(path).get("pending", [])
                        if not isinstance(pending, list):
                            raise ValueError("Pending photograph queue is malformed.")
                        photo_count += len(pending)
                self.operation.update(
                    "draining",
                    str(photo_count) + " photo(s); " + "; ".join(blockers),
                )
            if not blockers:
                break
            if time.monotonic() >= deadline:
                raise TimeoutError("Workers did not drain: " + "; ".join(blockers))
            cancel.wait(DRAIN_POLL_SECONDS)
        watched = self.host.worker
        if watched is not None and getattr(watched, "drain_manifest", None) is None:
            raise RuntimeError("Watcher has no durable queue; recovery required.")
        for plan in self.pending():
            path = plan.directory / "watch-drain.json"
            if path.exists() and read_document(path).get("pending"):
                raise RuntimeError(
                    "Accepted photographs remain pending. Retry before revoking."
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
        """Queue the existing transport's result without touching Tk.

        Returns:
            None: GUI event owns durable local credential clearing.

        Raises:
            Exception: Transport error leaves the key and pending state retained.
        """
        if self.operation is None:
            raise RuntimeError("No explicitly confirmed revoke operation.")
        result = self.revoke_transport(
            self.operation.upload_url, self.operation.api_key
        )
        self.host.events.put({
            "kind": "api_key_revoked",
            "payload": {"message": str(result.get("message", "API key revoked."))},
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

    def show_progress(self) -> None:
        """Open or restore the one shared nonmodal progress view.

        Returns:
            None: Does not start/restart tasks or imply new revoke consent.
                Local abandonment exposes only retry, retained files and hide.
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
        if self.dialog is not None and self.dialog.winfo_exists():
            if self._progress_local_mode == mode:
                self.dialog.deiconify()
                self.dialog.lift()
                return
            self.dialog.destroy()
            self._animation_running = False
        self._progress_local_mode = mode
        self.keep_local_button = None
        if self.operation is None:
            self.phase_var.set(self.text("phase_idle"))
            try:
                self.detail_var.set("; ".join(
                    plan.session_id + (": " + plan.error if plan.error else "")
                    for plan in self.pending()
                ) or self.text("finish_pending"))
            except ValueError as exc:
                self.detail_var.set(str(exc))
        owner = self.dialog_owner()
        self.dialog = tk.Toplevel(owner)
        self.dialog.withdraw()
        self.dialog.title(self.text("flight_progress"))
        self.dialog.transient(self.host.root)
        frame = ttk.Frame(self.dialog, padding=18)
        frame.pack(fill="both", expand=True)
        ttk.Label(
            frame, textvariable=self.phase_var, style="Section.TLabel"
        ).pack(anchor="w")
        self.bar = ttk.Progressbar(frame, maximum=100, length=440)
        self.bar.pack(fill="x", pady=(12, 8))
        self.activity_bar = ttk.Progressbar(
            frame, mode="indeterminate", length=440
        )
        self.activity_bar.pack(fill="x", pady=(0, 8))
        self.task_tree = ttk.Treeview(
            frame, columns=("state",), show="tree headings", height=6
        )
        self.task_tree.heading("#0", text=self.text("flight_progress"))
        self.task_tree.heading("state", text=self.text("phase_idle"))
        self.task_tree.column("#0", width=250)
        self.task_tree.column("state", width=230)
        self.task_tree.pack(fill="both", expand=True, pady=(4, 8))
        ttk.Label(
            frame, textvariable=self.detail_var, wraplength=520
        ).pack(anchor="w", fill="x")
        ttk.Label(frame, textvariable=self.time_var).pack(anchor="w", pady=8)
        actions = ttk.Frame(frame)
        actions.pack(fill="x", pady=(8, 0))
        ordinary_actions = (
            ("retry", self.retry_finalization),
            ("keep_local", self.keep_local),
            ("abandon", self.abandon),
            ("cancel_revoke", self.cancel_revocation),
            ("open_reports", self.open_reports),
            ("hide", self.dialog.withdraw),
        )
        local_actions = (
            ("retry", self.retry_saved_upload if saved_upload else self.create_local_pdf),
            ("cancel_upload" if saved_upload else "cancel_pdf", self.cancel_local_pdf),
            ("open_reports", self.open_reports),
            ("hide", self.dialog.withdraw),
        )
        abandon_actions = (
            ("retry", self.retry_finalization),
            ("open_reports", self.open_reports),
            ("hide", self.dialog.withdraw),
        )
        choices = (
            abandon_actions if abandoning else
            local_actions if local_mode else ordinary_actions
        )
        for index, (label, command) in enumerate(choices):
            button = ttk.Button(
                actions, text=self.text(label), command=command
            )
            column = index % PROGRESS_ACTION_COLUMNS
            actions.columnconfigure(column, weight=1)
            button.grid(
                row=index // PROGRESS_ACTION_COLUMNS, column=column,
                sticky="ew", padx=(0, 4), pady=(0, 4),
            )
            if label == "keep_local":
                self.keep_local_button = button
        self.dialog.protocol("WM_DELETE_WINDOW", self.dialog.withdraw)
        place_dialog(self.dialog, owner)
        self.dialog.deiconify()

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
        if tree is None or not tree.winfo_exists() or operation is None:
            return
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
                    rows.append((plan.session_id, self.text("record_flight"),
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
            None: Persists local retention and resumes finalization, including
                the same connection's already-confirmed unsubmitted revocation.
        """
        revoke = self.pending_revoke_intent()
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
                self.text("flight_progress"),
                str(exc),
                parent=self.dialog_owner(),
            )

    def abandon(self) -> None:
        """Stop the captured flight and forget its local key without a server.

        Returns:
            None: Confirmation starts bounded asynchronous worker shutdown,
                retains files and abandons pending delivery before local cleanup.
                Submitted revocation and independent PDF work must settle first.
        """
        if self.busy() and (
            self.independent_operation()
            or (self.operation is not None and self.operation.abandonment is True)
        ):
            self.show_progress()
            return
        if not messagebox.askyesno(
            self.text("abandon"), self.text("confirm_abandon"),
            parent=self.dialog_owner(),
        ):
            return
        try:
            plans = self.pending()
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
            for worker in (
                self.host.worker, self.host.manual_worker,
                self.host.preflight_worker, self.host.ai_worker,
                *self.resume_workers,
            ):
                if worker is not None and worker.is_alive():
                    worker.stop()
            self.host.ai_worker_stop_requested = True
            self.operation = FinalizationWorker(
                plans, self.normalize(self.host.config.gallery_url),
                self.host.config.api_key, self.wait_for_abandonment,
                self.submit_revoke, False, forget=self.submit_forget,
                emit=lambda level, message: self.host.events.put((level, message)),
            )
            self.automatic_operation = self.operation
            self.host.api_key_revoke_thread = self.operation
            self._last_terminal = ""
            self.operation.start()
            self.show_progress()
            self.host.refresh_connection_controls()
        except (OSError, ValueError, RuntimeError) as exc:
            self.host.api_key_revoke_running = False
            messagebox.showerror(
                self.text("flight_progress"),
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
        while True:
            if cancel.is_set():
                raise InterruptedError("Abandonment cancelled; local key retained.")
            workers = (
                self.abandon_previous, self.recorder, self.dispatch_worker,
                self.host.utc_sync, *self.resume_workers,
            )
            if not self.host.api_key_request_blockers() and not any(
                worker is not None and worker.is_alive() for worker in workers
            ) and not self.host.semantic_ai_install_running:
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
            None: Schedules the next bounded Tk update while the app is open.
        """
        operation = self.operation
        self.refresh_telemetry()
        self.accept_simbrief()
        self.refresh_dispatch_status()
        if self.keep_local_button is not None and self.keep_local_button.winfo_exists():
            self.keep_local_button.configure(text=self.text(
                "keep_local_revoke" if self.pending_revoke_intent() else "keep_local"
            ))
        if operation is not None:
            progress = operation.snapshot()
            terminal = progress.phase in {"completed", "error", "cancelled"}
            now = progress.phase_started if terminal else time.monotonic()
            self.phase_var.set(self.text(
                "report_cancelled" if self.independent_operation()
                and progress.phase == "cancelled" else "phase_" + progress.phase
            ))
            detail = progress.detail or progress.error
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
                self.bar.configure(mode="determinate")
                self.bar["value"] = (
                    progress.done * 100 / progress.total if progress.total else 0
                )
            if self.activity_bar is not None and self.activity_bar.winfo_exists():
                if terminal:
                    self.activity_bar.stop()
                    self._animation_running = False
                elif not self._animation_running:
                    # Animation means an unknown-duration unit is active, not
                    # a fabricated completion percentage or countdown.
                    self.activity_bar.start(PROGRESS_TICK_MS)
                    self._animation_running = True
            self.refresh_tasks()
            if terminal and self._last_terminal != progress.phase:
                self._last_terminal = progress.phase
                if not self.independent_operation():
                    self.host.api_key_revoke_running = False
                    self.host.api_key_revoke_thread = None
                    self.host.status_var.set(self.text("phase_" + progress.phase))
                    self.host.watcher_activity_var.set(
                        self.text("phase_" + progress.phase)
                    )
                self.refresh_reports()
                self.host.refresh_connection_controls()
                if (
                    progress.phase == "completed" and self.automatic_completion()
                    and self.dialog is not None and self.dialog.winfo_exists()
                ):
                    self.dialog.withdraw()
        self.host.root.after(PROGRESS_TICK_MS, self.tick)
