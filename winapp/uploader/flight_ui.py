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
from uploader.watcher.capture_session import prepare_capture_recording

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
from uploader.flight_report_translations import recovery_text, tr
from uploader.localization import DEFAULT_LANGUAGE, current_language, gettext
from uploader.flight_workflow import FinalizationWorker, WatchedReportTarget, create_local_report_worker
from uploader.models import ImportJob, JsonValue
from uploader.mode_coordinator import GuiModeOwner, ModeConfigPort
from uploader.state_store import JobStateStore
from uploader.state_store import atomic_write_json
from uploader.watcher.queue import WatchQueueStore, WatchRecoveryError
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

from .flight.ui.policy import (
    CAPTURE_RECOVERY_RETRY_SECONDS,
    DRAIN_POLL_SECONDS,
    PROGRESS_ACTION_COLUMNS,
    PROGRESS_TASK_MIN_WIDTHS,
    PROGRESS_TASK_ROWS,
    PROGRESS_TASK_WIDTHS,
    PROGRESS_TICK_MS,
    REPORT_COLUMN_WIDTHS,
    REPORT_NAME_WIDTHS,
    REPORT_TARGET_WIDTH,
    REPORT_VISIBLE_ROWS,
    SIMBRIEF_IDENTITY_COLUMNS,
    SIMBRIEF_PROMPT_WRAP_PX,
    SUCCESS_AUTOCLOSE_SECONDS,
)
from .flight.ui.ports import (
    ConnectionConfig,
    ControlledWorker,
    FlightHost,
    LinkedFlightManager,
    LinkedFlightTarget,
    RetainedWatchCapture,
    RevocationTransport,
    SessionState,
)
from .flight.ui.dispatch import DispatchMixin
from .flight.ui.reports import ReportsMixin
from .flight.ui.lifecycle import LifecycleMixin
from .flight.ui.progress import ProgressMixin


class FlightUI(DispatchMixin, ReportsMixin, LifecycleMixin, ProgressMixin):
    'Own Tk controls while services own persistence, analysis and network work.'

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
        self.report_upload_button: ttk.Button | None = None
        self.report_menu: tk.Menu | None = None
        self.report_flights_only = tk.BooleanVar(host.root, value=False)
        self.report_target_var = tk.StringVar(host.root, value="")
        self.report_target_hint = tk.StringVar(host.root, value="")
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
        self.ui_language = tk.StringVar(value=current_language())
        self.host.root._flight_ui_language = self.ui_language
        self.refresh_dispatch_status()
        self._last_terminal = ""
        self._progress_generation = 0
        host.root.after(PROGRESS_TICK_MS, self.tick)


    __all__ = [
"FlightUI",
"CAPTURE_RECOVERY_RETRY_SECONDS",
"ConnectionConfig",
"ControlledWorker",
"DRAIN_POLL_SECONDS",
"FlightHost",
"LinkedFlightManager",
"LinkedFlightTarget",
"PROGRESS_ACTION_COLUMNS",
"PROGRESS_TASK_MIN_WIDTHS",
"PROGRESS_TASK_ROWS",
"PROGRESS_TASK_WIDTHS",
"PROGRESS_TICK_MS",
"REPORT_COLUMN_WIDTHS",
"REPORT_NAME_WIDTHS",
"REPORT_TARGET_WIDTH",
"REPORT_VISIBLE_ROWS",
"RetainedWatchCapture",
"RevocationTransport",
"SIMBRIEF_IDENTITY_COLUMNS",
"SIMBRIEF_PROMPT_WRAP_PX",
"SUCCESS_AUTOCLOSE_SECONDS",
"SessionState",
    ]
