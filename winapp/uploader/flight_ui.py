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
import threading
import time
from dataclasses import replace
from datetime import datetime
from pathlib import Path
from typing import Callable, Protocol
import tkinter as tk
from tkinter import messagebox, ttk

from uploader.flight_jobs import (
    FlightPreferences,
    ImportBindings,
    SessionPlan,
    SavedFlight,
    ReportRevision,
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
from uploader.ui_theme import GlassCard, icon_button
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
REPORT_COLUMN_WIDTHS = (110, 50, 40, 145, 45, 75)
# Show a short history while the Treeview retains ordinary wheel scrolling.
# Type: int. Units: visible rows. Scope: report catalog window.
# Consumers: FlightUI.show_reports. Rationale: seven flights fit a small dialog.
REPORT_VISIBLE_ROWS = 7
# Purpose: Keep recovery actions inside a compact progress window.
# Type: int; Units: button columns; Scope: finalization progress actions.
# Consumers: FlightUI.show_progress. Rationale: three columns wrap the six
# ordinary actions into two rows instead of forcing a very wide dialog.
PROGRESS_ACTION_COLUMNS = 3


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
        self.recorder: FlightRecorder | None = None
        self.plan: SessionPlan | None = None
        self.dialog: tk.Toplevel | None = None
        self.settings_dialog: tk.Toplevel | None = None
        self.report_dialog: tk.Toplevel | None = None
        self.report_tree: ttk.Treeview | None = None
        self.report_rows: dict[str, SavedFlight] = {}
        self.report_files: dict[str, tuple[str, ReportRevision, str]] = {}
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
        dialog = tk.Toplevel(self.host.root)
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
        dialog.update_idletasks()
        # Center this small sheet inside its owning app rather than the desktop.
        x = max(0, self.host.root.winfo_rootx()
                + (self.host.root.winfo_width() - dialog.winfo_reqwidth()) // 2)
        y = max(0, self.host.root.winfo_rooty()
                + (self.host.root.winfo_height() - dialog.winfo_reqheight()) // 2)
        dialog.geometry(f"+{x}+{y}")
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
        """Explicitly finish pending flight PDFs while keeping the gallery key.

        Returns:
            None: Confirms watcher/recording stop, enables the selected report
                editions for this operation, then uses ordinary Stop delivery.
        """
        if self.busy():
            self.show_progress()
            return
        languages = tuple(
            code for code, variable in self.language_vars.items() if variable.get()
        )
        if not languages:
            messagebox.showerror(self.text("finish_pdf"), self.text("choose_languages"))
            return
        try:
            plans = self.pending()
            if not plans:
                messagebox.showinfo(self.text("finish_pdf"), self.text("no_pending_flights"))
                return
            if not messagebox.askyesno(self.text("finish_pdf"), self.text("confirm_finish_pdf")):
                return
            for plan in plans:
                plan.languages = languages
                plan.reports_enabled = True
                plan.save()
            self.begin(False)
        except (OSError, ValueError) as exc:
            messagebox.showerror(self.text("finish_pdf"), str(exc))

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
        self.report_dialog = tk.Toplevel(self.host.root)
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
        self.report_tree.column("#0", width=300, minwidth=170)
        for name, width in zip(columns, REPORT_COLUMN_WIDTHS, strict=True):
            self.report_tree.heading(name, text=self.text("column_" + name))
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

    def refresh_reports(self) -> None:
        """Refresh checkpoint rows without reading or analyzing raw sample files.

        Returns:
            None: Keeps selection when possible and exposes corrupt-session errors.
        """
        tree = self.report_tree
        if tree is None or not tree.winfo_exists():
            return
        selected = tree.selection()
        expanded = {identity for identity in self.report_rows if tree.exists(identity) and tree.item(identity, "open")}
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
            tree.insert("", "end", iid=identity, text=row.aircraft or "—", open=identity in expanded, values=(
                row.started_utc.replace("T", " ")[:16], count,
                row.report_count, "", "", self.text("report_state_" + state),
            ))
            try:
                for revision in report_revisions(row.directory):
                    for language in revision.files:
                        child = "pdf:" + identity + ":" + revision.report_id + ":" + language
                        self.report_files[child] = (identity, revision, language)
                        filename = report_file_label(row.directory, revision, language)
                        version = filename.rsplit(" - ", 1)[-1].removesuffix(".pdf")
                        details = report_file_details(revision.files[language])
                        created = datetime.fromisoformat(
                            details.created_utc,
                        ).astimezone().strftime("%Y-%m-%d %H:%M:%S")
                        if details.estimated_time:
                            created += " *"
                        tree.insert(
                            identity, "end", iid=child,
                            text=version + " · " + language.upper() + " · " + filename,
                            values=(
                                "", "", "", created,
                                details.pages if details.pages is not None else "?",
                                "PDF",
                            ),
                        )
            except (OSError, ValueError) as exc:
                self.report_detail_var.set(str(exc))
        identity = (
            selected[0] if selected and tree.exists(selected[0])
            else next(iter(self.report_rows), "")
        )
        if identity:
            tree.selection_set(identity)
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
        self.report_detail_var.set(
            row.directory.name + (": " + row.error if row.error else "")
            if row else self.text("select_saved_flight")
        )
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
            None: Starts a worker for one stopped session and selected editions;
                an active recorder must first use Finish flight and create PDF.
        """
        if self.busy():
            self.show_progress()
            return
        row = self.selected_saved_flight()
        if row is None:
            messagebox.showinfo(self.text("saved_reports"), self.text("select_saved_flight"))
            return
        if self.load_error or self.lease is None:
            messagebox.showerror(self.text("saved_reports"), self.load_error or "Flight workspace unavailable.")
            return
        if (
            self.recorder and self.recorder.is_alive()
            and self.recorder.directory == row.directory
        ) or not row.recording_ended:
            messagebox.showinfo(self.text("saved_reports"), self.text("finish_recording_first"))
            return
        languages = tuple(
            code for code, variable in self.language_vars.items() if variable.get()
        )
        if not languages:
            messagebox.showerror(self.text("saved_reports"), self.text("choose_languages"))
            return
        try:
            plan = replace(SessionPlan.load(row.directory), languages=languages)
            self.operation = FinalizationWorker(
                [plan], "", "", lambda _cancel, _deadline: None,
                lambda: None, False, local_reports=True,
                emit=lambda level, message: self.host.events.put((level, message)),
            )
            self._last_terminal = ""
            self.operation.start()
            self.select_report()
            self.show_progress()
        except (OSError, ValueError, RuntimeError) as exc:
            messagebox.showerror(self.text("saved_reports"), str(exc))

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
            messagebox.showinfo(self.text("saved_reports"), self.text("select_saved_flight"))
            return
        selected = self.report_tree.selection() if self.report_tree is not None else ()
        files = [self.report_files[identity] for identity in selected if identity in self.report_files]
        if not files:
            messagebox.showinfo(self.text("saved_reports"), self.text("select_pdf_files"))
            return
        if len({identity for identity, _revision, _language in files}) != 1:
            messagebox.showinfo(self.text("saved_reports"), self.text("single_flight_selection"))
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
            messagebox.showerror(self.text("saved_reports"), self.load_error or "Flight workspace unavailable.")
            return
        gallery = self.host.gallery_label_var.get().removeprefix("Target gallery: ")
        if not self.host.config.api_key or not gallery or gallery == "unknown":
            messagebox.showinfo(self.text("saved_reports"), self.text("test_saved_connection"))
            return
        if not row.recording_ended or (
            self.recorder and self.recorder.is_alive() and self.recorder.directory == row.directory
        ):
            messagebox.showinfo(self.text("saved_reports"), self.text("finish_recording_first"))
            return
        detail = (
            self.text("confirm_saved_upload") + "\n\n" + gallery + "\n"
            + row.directory.name + "\n" + revision.modified_utc + "\n"
            + "\n".join(pdf.name for item in (revision,) + additional for pdf in item.files.values())
        )
        if not messagebox.askyesno(self.text("upload_saved_pdf"), detail):
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
            messagebox.showerror(self.text("saved_reports"), str(exc))

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
                messagebox.showerror(self.text("saved_reports"), "PDF unavailable; refresh the list.")
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
            messagebox.showerror(self.text("saved_reports"), "Unsafe report directory.")
            return
        try:
            if not report_revisions(directory):
                messagebox.showinfo(self.text("saved_reports"), self.text("no_saved_pdf"))
                return
            organize_reports(directory)
            self.refresh_reports()
            self.host.open_local_path(reports)
        except (OSError, ValueError) as exc:
            messagebox.showerror(self.text("saved_reports"), str(exc))

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
            messagebox.showerror(self.text("flight_progress"), str(exc))
            return False

    def restore_preferences(self) -> None:
        """Restore saved switch states when a local preference change is denied.

        Returns:
            None: The controls again represent the last accepted preferences.
        """
        self.record_var.set(self.preferences.recording)
        self.report_var.set(self.preferences.reports)
        self.ui_language.set(self.preferences.ui_language)
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
                self.text("flight_progress"), self.text("finish_pending")
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
        self.plan = SessionPlan(
            directory=directory,
            binding=self.binding(),
            languages=self.preferences.languages,
            reports_enabled=self.preferences.reports and self.preferences.recording,
        )
        self.plan.save()
        self.recorder.start()
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
        """
        self.begin(self.pending_revoke_intent())

    def is_alive(self) -> bool:
        """Include recorder work in ordinary shutdown/update worker ownership.

        Returns:
            bool: True while recording or finalization owns a live thread.
        """
        return bool(
            (self.recorder and self.recorder.is_alive())
            or (self.operation and self.operation.is_alive())
        )

    def stop(self) -> None:
        """Preserve unfinished tasks while stopping recording for Exit/update.

        Returns:
            None: Requests sensor stop and independent PDF cancellation;
                ordinary accepted finalization retains its existing drain.
        """
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

    def begin(self, revoke: bool = False) -> None:
        """Start or join one confirmed Stop/Revoke operation on the Tk thread.

        Args:
            revoke (bool): Whether this explicit action should finally revoke.

        Returns:
            None: Shows progress and runs expensive tasks on a worker.
        """
        if self.load_error:
            messagebox.showerror(self.text("flight_progress"), self.load_error)
            return
        if self.busy() and self.independent_operation():
            self.show_progress()
            return
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
        if revoke and not continuing_revoke and not messagebox.askyesno(
            self.text("finish_revoke"), self.text("confirm_revoke")
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
            self.host.api_key_revoke_thread = self.operation
            self.operation.start()
            self._last_terminal = ""
            self.show_progress()
            self.host.refresh_connection_controls()
        except (OSError, ValueError, RuntimeError) as exc:
            self.host.api_key_revoke_running = False
            messagebox.showerror(self.text("flight_progress"), str(exc))

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

    def show_progress(self) -> None:
        """Open or restore the one shared nonmodal progress view.

        Returns:
            None: Does not start/restart tasks or imply new revoke consent.
        """
        local_mode = self.independent_operation()
        saved_upload = bool(
            self.operation is not None
            and self.operation.saved_upload is True
        )
        mode = "upload" if saved_upload else local_mode
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
        self.dialog = tk.Toplevel(self.host.root)
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
        choices = (
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

    def cancel_local_pdf(self) -> None:
        """Cancel independent PDF work between editions, retaining finished work.

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
                for index, language in enumerate(plan.languages):
                    if operation.local_reports is True:
                        state = self.text("report_state_local" if progress.done >= index + 2 else "phase_idle")
                    else:
                        complete = language in plan.receipts or (plan.completed and plan.local_only)
                        state = self.text("phase_completed" if complete else "phase_idle")
                    if progress.detail == language.upper():
                        state = self.text("phase_" + progress.phase)
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
        if self.busy() or not messagebox.askyesno(
            self.text("keep_local_revoke" if revoke else "keep_local"),
            self.text("confirm_local_revoke" if revoke else "confirm_local"),
        ):
            return
        try:
            for plan in self.pending():
                plan.local_only = True
                # A partial flight can be retained without a fabricated landing.
                from uploader.flight_data import analyze_session
                analysis = analyze_session(plan.directory)
                if not analysis.landings or not analysis.completed:
                    plan.reports_enabled = False
                plan.save()
            self.begin(revoke)
        except (OSError, ValueError) as exc:
            messagebox.showerror(self.text("flight_progress"), str(exc))

    def abandon(self) -> None:
        """Explicitly leave unuploaded tasks retained as abandoned local records.

        Returns:
            None: No files are deleted and no server revocation is requested.
        """
        if self.busy() or not messagebox.askyesno(
            self.text("abandon"), self.text("confirm_abandon")
        ):
            return
        try:
            for plan in self.pending():
                plan.abandoned = True
                plan.completed = True
                plan.error = "Explicitly abandoned; local files retained."
                plan.save()
            for job_id, binding in self.import_bindings.entries().items():
                if binding == self.binding():
                    self.import_bindings.resolve(job_id)
            self.detail_var.set(self.text("revoke_cancelled"))
        except (OSError, ValueError) as exc:
            messagebox.showerror(self.text("flight_progress"), str(exc))

    def tick(self) -> None:
        """Refresh phase/time feedback without calling native/network operations.

        Returns:
            None: Schedules the next bounded Tk update while the app is open.
        """
        operation = self.operation
        self.refresh_telemetry()
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
        self.host.root.after(PROGRESS_TICK_MS, self.tick)
