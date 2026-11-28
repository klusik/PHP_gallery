# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/imports_activity.py
# Module Type: Windows Client UI behavior
# Purpose: Own the imports activity interactions for WatcherApp.
# Responsibilities: Preserve the existing Watcher UI lifecycle and callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Watcher imports activity behavior owner."""

from uploader.watcher import runtime
from uploader.watcher import state as watcher_state
from uploader.localization import gettext, gettext_noop
from .support import (
    ActivityEvent,
    LATEST_ACTIVITY_PREVIEW_LENGTH,
    WATCHER_JOB_HISTORY_TREE_ROW_LIMIT,
    _ImportViewProjection,
    _TreeRowSnapshot,
    _publish_watcher_text,
    _publish_watcher_value,
    activity_filter_labels,
    activity_label,
    operational_activity_caption,
    selected_image_filetypes,
)
from typing import cast
from uploader.diagnostics import redact_text
from uploader.gallery_information import format_gallery_bytes
from uploader.import_commands import admit_reviewed_import
from uploader.import_commands import read_verified_import_retry
from uploader.import_commands import select_retry_sources
from uploader.import_history_reader import ImportHistoryReader
from uploader.import_summary import format_preflight_plan_summary
from uploader.import_ui_snapshot import new_worker_generation
from uploader.imports.manual_upload import create_import_worker
from uploader.imports.policy import WATCHER_IMPORT_TREE_ROW_LIMIT
from uploader.imports.preflight import ImportPreflightThread
from uploader.models import ImportPlan
from uploader.models import RECOVERABLE_ITEM_STATES
from uploader.ui_theme import Button
from uploader.ui_theme import GalleryIdentityLabel
from uploader.ui_theme import GlassCard
from uploader.ui_theme import icon_button
import logging

# Purpose: Bound routine presentation bursts while accepted Import work continues.
# Type: int; Units: milliseconds; Scope: one active manual-import GUI projection.
# Consumers: WatcherApp._flush_import_presentation, WatcherApp.drain_events.
# Rationale: 100 ms targets ten visual updates per second. Terminal progress
#   and explicit lifecycle actions publish immediately.
IMPORT_PRESENTATION_INTERVAL_MS = 100


class ImportActivityMixin:
    """Own this cohesive WatcherApp UI behavior domain."""

    def build_manual_tab(self, parent: "tk.Misc") -> None:
        """
        Build controls dedicated to manual bulk uploading.

        Keep the chosen upload target and its optional API-key editor together.
        The site link and its gallery picker live in the main header.

        Args:
            parent (tk.Misc): Tk container receiving the import controls.

        Returns:
            None: Builds manual import with a wrapping gallery name, hierarchy
                help and offline flight-abandonment controls.
        """
        parent.columnconfigure(0, weight=1)
        card = GlassCard(parent, padding=(14, 10))
        card.grid(row=0, column=0, sticky="ew", padx=6, pady=4)
        card.columnconfigure(0, weight=1)
        target = runtime.ttk.Frame(card, style="Card.TFrame")
        target.grid(row=0, column=0, sticky="ew")
        target.columnconfigure(0, weight=1)
        identity = runtime.ttk.Frame(target, style="Card.TFrame")
        identity.grid(row=0, column=0, sticky="ew")
        identity.columnconfigure(0, weight=1)
        GalleryIdentityLabel(
            identity, self.gallery_label_var, self.gallery_title_var,
        ).grid(
            row=0, column=0, sticky="ew"
        )
        icon_button(
            identity, "settings", self.open_connection_dialog,
            gettext("Upload via API key"),
        ).grid(row=0, column=1, padx=(8, 0))
        runtime.ttk.Label(target, textvariable=self.connection_status_var, style="Muted.TLabel").grid(
            row=2, column=0, sticky="w", pady=(2, 0)
        )
        self.import_revoke_button = Button(
            target,
            text=self.flights.text("finish_flight"),
            command=self.flight_action,
        )
        self.import_revoke_button.grid(row=1, column=0, sticky="w", pady=(6, 0))
        self.import_abandon_button = Button(
            target, text=self.flights.text("abandon"),
            command=self.abandon_flight,
        )
        self.import_abandon_button.grid(row=1, column=1, padx=(6, 0), pady=(6, 0))

        runtime.ttk.Label(card, text=gettext("CHOOSE SOURCE"), style="Eyebrow.TLabel").grid(
            row=2, column=0, sticky="w", pady=(17, 5)
        )
        toolbar = runtime.ttk.Frame(card, style="Card.TFrame")
        toolbar.grid(row=3, column=0, sticky="ew")
        icon_button(toolbar, "upload", self.select_manual_files, gettext("Choose image files")).pack(side="left")
        runtime.ttk.Label(toolbar, text=gettext("Files"), style="Muted.TLabel").pack(side="left", padx=(2, 10))
        icon_button(toolbar, "add", self.select_manual_folder, gettext("Choose a folder")).pack(side="left")
        runtime.ttk.Label(toolbar, text=gettext("Folder"), style="Muted.TLabel").pack(side="left", padx=(2, 10))
        icon_button(toolbar, "info", self.select_manual_zip, gettext("Choose a ZIP archive")).pack(side="left")
        runtime.ttk.Label(toolbar, text=gettext("ZIP"), style="Muted.TLabel").pack(side="left", padx=(2, 10))
        icon_button(toolbar, "close", self.clear_manual_files, gettext("Clear the selected source")).pack(side="right")
        runtime.ttk.Label(card, textvariable=self.manual_selection_var, style="Body.TLabel", wraplength=800).grid(
            row=4, column=0, sticky="w", pady=(9, 0)
        )

        runtime.ttk.Label(card, text=gettext("REVIEW"), style="Eyebrow.TLabel").grid(
            row=5, column=0, sticky="w", pady=(16, 5)
        )
        runtime.ttk.Label(
            card,
            textvariable=self.manual_preflight_var,
            style="Body.TLabel",
            wraplength=820,
            justify="left",
        ).grid(row=6, column=0, sticky="w")
        self.preflight_progress = runtime.ttk.Progressbar(card, mode="indeterminate")
        self.preflight_progress.grid(row=7, column=0, sticky="ew", pady=(7, 0))
        self.preflight_progress.grid_remove()
        self.manual_progress = runtime.ttk.Progressbar(
            card,
            variable=self.manual_progress_var,
            maximum=100.0,
            mode="determinate",
        )
        self.manual_progress.grid(row=8, column=0, sticky="ew", pady=(10, 4))
        runtime.ttk.Label(card, textvariable=self.manual_progress_text_var, style="Muted.TLabel").grid(
            row=9, column=0, sticky="w"
        )
        actions = runtime.ttk.Frame(card, style="Card.TFrame")
        actions.grid(row=10, column=0, sticky="ew", pady=(11, 0))
        self.start_import_button = Button(
            actions,
            text=gettext("▶  Start import"),
            command=self.start_manual_upload,
            state="disabled",
            style="Primary.TButton",
            takefocus=True,
        )
        self.start_import_button.pack(side="left")
        self.pause_import_button = icon_button(
            actions,
            "pause",
            self.pause_manual_upload,
            gettext("Pause import after active requests finish"),
        )
        self.pause_import_button.state(["disabled"])
        self.pause_import_button.pack(side="left", padx=(7, 0))
        self.resume_import_button = icon_button(
            actions,
            "play",
            self.resume_manual_upload,
            gettext("Resume the paused import"),
        )
        self.resume_import_button.state(["disabled"])
        self.resume_import_button.pack(side="left", padx=(5, 0))
        self.cancel_import_button = icon_button(
            actions,
            "close",
            self.stop_manual_upload,
            gettext("Cancel the active import"),
        )
        self.cancel_import_button.state(["disabled"])
        self.cancel_import_button.pack(side="left", padx=(5, 0))
        self.retry_failed_button = Button(actions, text=gettext("Retry failed"), command=self.retry_failed_import, state="disabled")
        self.retry_failed_button.pack(side="left", padx=(7, 0))
        runtime.ttk.Label(actions, textvariable=self.manual_status_var, style="Muted.TLabel").pack(side="right")

        advanced_toggle = runtime.ttk.Checkbutton(
            card,
            text=gettext("Import options and item details"),
            variable=self.import_details_visible_var,
            command=self.toggle_import_details,
        )
        advanced_toggle.grid(row=11, column=0, sticky="w", pady=(12, 0))
        self.import_details_frame = runtime.ttk.Frame(card, style="Card.TFrame")
        self.import_details_frame.columnconfigure(0, weight=1)
        runtime.ttk.Checkbutton(
            self.import_details_frame,
            text=gettext("Include subfolders"),
            variable=self.manual_folder_recursive_var,
            command=self.rebuild_manual_preflight,
        ).grid(row=0, column=0, sticky="w", pady=3)
        runtime.ttk.Label(
            self.import_details_frame,
            text=gettext("Original ZIP is kept for manual verification."),
            style="Muted.TLabel",
        ).grid(row=1, column=0, sticky="w", pady=3)
        runtime.ttk.Radiobutton(
            self.import_details_frame,
            text=gettext("Server creates thumbnails"),
            variable=self.manual_local_thumbnails_var,
            value=False,
        ).grid(row=2, column=0, sticky="w", pady=3)
        self.thumbnail_check = runtime.ttk.Radiobutton(
            self.import_details_frame,
            text=gettext("This PC creates thumbnails when supported"),
            variable=self.manual_local_thumbnails_var,
            value=True,
        )
        self.thumbnail_check.grid(row=3, column=0, sticky="w", pady=3)
        self.import_item_tree = runtime.ttk.Treeview(
            self.import_details_frame,
            columns=("file", "size", "decode", "state", "reason"),
            show="headings",
            height=6,
        )
        for column, title, width in [
            ("file", gettext("File"), 190),
            ("size", gettext("Size"), 70),
            ("decode", gettext("Media"), 85),
            ("state", gettext("Review"), 95),
            ("reason", gettext("Detail"), 290),
        ]:
            self.import_item_tree.heading(column, text=title)
            self.import_item_tree.column(column, width=width, stretch=column in {"file", "reason"})
        self.import_item_tree.grid(row=4, column=0, sticky="ew", pady=(9, 0))
        self.import_details_frame.grid(row=12, column=0, sticky="ew", pady=(6, 0))
        self.import_details_frame.grid_remove()
        self.refresh_thumbnail_controls()
        self.refresh_revoke_button_state()


    def toggle_import_details(self) -> None:
        """Show or hide detailed import options and per-item preflight rows.

        Returns:
            None: Keeps advanced controls collapsed until explicitly requested.
        """
        if self.import_details_visible_var.get():
            self.import_details_frame.grid()
        else:
            self.import_details_frame.grid_remove()


    def build_activity_tab(self, parent: "tk.Misc") -> None:
        """Build the full event, job-history, retry, and diagnostics workspace.

        Args:
            parent (tk.Misc): Scrollable page receiving the activity controls.

        Returns:
            None: Creates persistent job and operational event views.
        """
        parent.columnconfigure(0, weight=1)
        current = GlassCard(parent, padding=(14, 10))
        current.grid(row=1, column=0, sticky="ew", padx=6, pady=4)
        current.columnconfigure(0, weight=1)
        runtime.ttk.Label(
            current, text=gettext("Current import"), style="Section.TLabel",
        ).grid(row=0, column=0, sticky="w", pady=(0, 6))
        runtime.ttk.Label(current, textvariable=self.activity_job_summary_var, wraplength=800).grid(row=1, column=0, sticky="w", pady=(0, 8))
        activity_actions = runtime.ttk.Frame(current, style="Card.TFrame")
        activity_actions.grid(row=2, column=0, sticky="ew", pady=(0, 8))
        Button(activity_actions, text=gettext("Open Import"), command=lambda: self.select_tab("import")).pack(side="left")
        Button(activity_actions, text=gettext("Retry failed"), command=self.retry_failed_import).pack(side="left", padx=8)
        self.activity_item_tree = runtime.ttk.Treeview(current, columns=("file", "state", "attempts", "detail"), show="headings", height=7)
        for column, title, width in [
            ("file", gettext("File"), 175),
            ("state", gettext("State"), 105),
            ("attempts", gettext("Tries"), 65),
            ("detail", gettext("Result / error"), 330),
        ]:
            self.activity_item_tree.heading(column, text=title)
            self.activity_item_tree.column(column, width=width, stretch=column in {"file", "detail"})
        self.activity_item_tree.grid(row=3, column=0, sticky="ew")

        history = GlassCard(parent, padding=(14, 10))
        history.grid(row=2, column=0, sticky="ew", padx=6, pady=4)
        history.columnconfigure(0, weight=1)
        runtime.ttk.Label(
            history, text=gettext("Job history"), style="Section.TLabel",
        ).grid(row=0, column=0, sticky="w", pady=(0, 8))
        self.job_history_tree = runtime.ttk.Treeview(history, columns=("created", "source", "target", "status"), show="headings", height=10)
        for column, title, width in [
            ("created", gettext("Created"), 125),
            ("source", gettext("Source"), 190),
            ("target", gettext("Target"), 140),
            ("status", gettext("Result"), 270),
        ]:
            self.job_history_tree.heading(column, text=title)
            self.job_history_tree.column(column, width=width, stretch=column in {"source", "status"})
        self.job_history_tree.grid(row=1, column=0, sticky="ew")
        self.job_history_tree.bind("<<TreeviewSelect>>", self.select_history_job)
        self.refresh_job_history()

        events = GlassCard(parent, padding=(14, 10))
        events.grid(row=3, column=0, sticky="ew", padx=6, pady=4)
        events.columnconfigure(0, weight=1)
        runtime.ttk.Label(
            events, text=gettext("Recent events"), style="Section.TLabel",
        ).grid(row=0, column=0, sticky="w", pady=(0, 8))
        controls = runtime.ttk.Frame(events, style="Card.TFrame")
        controls.grid(row=1, column=0, sticky="ew", pady=(0, 8))
        runtime.ttk.Label(controls, text=gettext("Show")).pack(side="left")
        activity_filter = runtime.ttk.Combobox(
            controls,
            textvariable=self.activity_filter_var,
            values=tuple(activity_filter_labels().values()),
            width=12,
            state="readonly",
        )
        activity_filter.pack(side="left", padx=(6, 10))
        activity_filter.bind(
            "<<ComboboxSelected>>",
            lambda _event: self.refresh_activity_drawer(),
        )
        Button(controls, text=gettext("Retry failed"), command=self.retry_failed_import).pack(side="left")
        Button(controls, text=gettext("Support diagnostics…"), command=self.open_support_report).pack(side="right")
        event_table = runtime.ttk.Frame(events, style="Card.TFrame")
        event_table.grid(row=2, column=0, sticky="ew")
        event_table.columnconfigure(0, weight=1)
        event_table.rowconfigure(0, weight=1)
        self.activity_drawer = runtime.ttk.Treeview(
            event_table,
            columns=("time", "level", "filename", "operation", "message"),
            show="headings",
            height=9,
            yscrollcommand=lambda first, last: event_scroll_y.set(first, last),
            xscrollcommand=lambda first, last: event_scroll_x.set(first, last),
        )
        for column, title, width in [
            ("time", gettext("Time"), 75),
            ("level", gettext("Status"), 75),
            ("filename", gettext("File"), 125),
            ("operation", gettext("Type"), 85),
            ("message", gettext("Event"), 390),
        ]:
            self.activity_drawer.heading(column, text=title)
            self.activity_drawer.column(
                column,
                width=width,
                stretch=column in {"filename", "message"},
            )
        self.activity_drawer.grid(row=0, column=0, sticky="nsew")
        event_scroll_y = runtime.ttk.Scrollbar(event_table, orient="vertical", command=self.activity_drawer.yview)
        event_scroll_y.grid(row=0, column=1, sticky="ns")
        event_scroll_x = runtime.ttk.Scrollbar(event_table, orient="horizontal", command=self.activity_drawer.xview)
        event_scroll_x.grid(row=1, column=0, sticky="ew")
        self.refresh_activity_drawer()

        diagnostics = GlassCard(parent, padding=(14, 10))
        diagnostics.grid(row=4, column=0, sticky="ew", padx=6, pady=(4, 8))
        diagnostics.columnconfigure(0, weight=1)
        runtime.ttk.Label(
            diagnostics, text=gettext("Live diagnostics log"), style="Section.TLabel",
        ).grid(row=0, column=0, sticky="w", pady=(0, 8))
        diagnostic_actions = runtime.ttk.Frame(diagnostics, style="Card.TFrame")
        diagnostic_actions.grid(row=1, column=0, sticky="ew", pady=(0, 8))
        Button(diagnostic_actions, text=gettext("Open app folder"), command=self.open_config_folder).pack(side="left")
        Button(diagnostic_actions, text=gettext("Open log file"), command=self.open_log_file).pack(side="left", padx=8)
        Button(diagnostic_actions, text=gettext("Support diagnostics…"), command=self.open_support_report).pack(side="left")
        Button(diagnostic_actions, text=gettext("Copy report"), command=self.copy_diagnostics).pack(side="left", padx=8)
        self.log_text = runtime.tk.Text(
            diagnostics, height=9, wrap="word", relief="flat", padx=8, pady=6,
            font="TkFixedFont",
            background="#182330", foreground="#F2F6FA",
            insertbackground="#F2F6FA", selectbackground="#365768",
        )
        self.log_text.grid(row=2, column=0, sticky="ew")
        self.log_text.configure(state="disabled")
        self.configure_log_tags()


    def select_manual_files(self) -> None:
        """
        Open a file picker and add supported images to the manual upload list.

        Returns:
            None: Starts preflight for the selected files when admitted.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        selected = runtime.filedialog.askopenfilenames(
            title=gettext("Choose files to import"),
            initialdir=self.watched_folder_var.get() or str(watcher_state.Path.home()),
            filetypes=selected_image_filetypes() + [(gettext("All files"), "*.*")],
        )
        if not selected:
            return
        self.manual_paths = [watcher_state.Path(value) for value in selected]
        self.manual_folder = None
        self.manual_zip = None
        self.manual_selection_var.set(gettext("Files: {manual_path_count} selected").format(
            manual_path_count=len(self.manual_paths),
        ))
        self.rebuild_manual_preflight()


    def select_manual_folder(self) -> None:
        """Choose one folder for explicit manual import discovery.

        Returns:
            None: Starts preflight for the selected folder when admitted.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        selected = runtime.filedialog.askdirectory(initialdir=self.watched_folder_var.get() or str(watcher_state.Path.home()), title=gettext("Choose folder to import"))
        if not selected:
            return
        self.manual_paths = []
        self.manual_folder = watcher_state.Path(selected)
        self.manual_zip = None
        self.manual_selection_var.set(gettext("Folder: ") + str(self.manual_folder))
        self.rebuild_manual_preflight()


    def select_manual_zip(self) -> None:
        """Choose one ZIP archive for bounded staging and preflight.

        Returns:
            None: Starts preflight for the selected archive when admitted.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        selected = runtime.filedialog.askopenfilename(
            title=gettext("Choose ZIP archive to import"),
            initialdir=self.watched_folder_var.get() or str(watcher_state.Path.home()),
            filetypes=[(gettext("ZIP archives"), "*.zip"), (gettext("All files"), "*.*")],
        )
        if not selected:
            return
        self.manual_paths = []
        self.manual_folder = None
        self.manual_zip = watcher_state.Path(selected)
        self.manual_selection_var.set(gettext("ZIP: ") + str(self.manual_zip))
        self.rebuild_manual_preflight()


    def clear_manual_files(self) -> None:
        """
        Clear the manual upload selection.

        Returns:
            None: Clears the selection unless an update blocks changes.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.preflight_worker and self.preflight_worker.is_alive():
            self.preflight_worker.stop()
            runtime.messagebox.showwarning(
                gettext("Import"), gettext("The previous preflight is stopping. Clear the selection again after it finishes."),
            )
            return
        if not self._discard_unstarted_import_preflight():
            return
        self.manual_paths = []
        self.manual_folder = None
        self.manual_zip = None
        self.manual_plan = None
        self._preflight_approved = None
        self.current_job = None
        self._import_detail_request = None
        self.manual_selection_var.set(gettext("Choose files, a folder, or a ZIP archive"))
        self.manual_preflight_var.set(gettext("Select a source to build an import summary. Nothing uploads until you press Start import."))
        self.manual_progress_var.set(0.0)
        self.manual_progress_text_var.set(gettext("No active import"))
        self.refresh_manual_file_label()
        self.refresh_import_item_tree(None)
        self.start_import_button.configure(state="disabled")


    def rebuild_manual_preflight(self) -> None:
        """Start a non-uploading preflight pass for the selected source.

        Returns:
            None: Starts a worker when the selected connection is ready, or
                displays the reason the preflight could not start.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.api_key_revoke_running:
            runtime.messagebox.showwarning(gettext("Connection"), gettext("Wait for API-key revocation to finish before starting import preflight."))
            return
        if self.manual_worker and self.manual_worker.is_alive():
            return
        if self.preflight_worker and self.preflight_worker.is_alive():
            self.preflight_worker.stop()
            runtime.messagebox.showwarning(
                gettext('Import'), gettext('The previous preflight is stopping. Start preflight again when it has finished.'),
            )
            return
        if not self.manual_paths and self.manual_folder is None and self.manual_zip is None:
            return
        try:
            config = self.current_config()
            linked_target = self.capture_linked_target()
            if linked_target is None and config.to_dict() != self.config.to_dict():
                raise ValueError('Save the current settings before starting import preflight.')
            config = watcher_state.capture_worker_config(config)
            config_port = self.config_store.mode_port(config)
            if linked_target is not None and self.worker and self.worker.is_alive():
                raise ValueError('Stop the linked watcher before starting import preflight.')
        except ValueError as exc:
            runtime.messagebox.showerror(gettext("Invalid settings"), str(exc))
            return
        if not self._discard_unstarted_import_preflight():
            return
        self.manual_plan = None
        self._preflight_approved = None
        self.current_job = None
        self._import_detail_request = None
        self.start_import_button.configure(state="disabled")
        self.retry_failed_button.configure(state="disabled")
        self.manual_preflight_var.set(gettext("Inspecting source, hashing files, checking media capabilities, and comparing remote inventory..."))
        self.preflight_progress.grid()
        self.preflight_progress.start(12)
        source_kind = "files" if self.manual_paths else ("folder" if self.manual_folder is not None else "zip")
        self.preflight_worker = ImportPreflightThread(
            config,
            source_kind,
            self.manual_paths,
            self.manual_folder,
            self.manual_zip,
            bool(self.manual_folder_recursive_var.get()),
            bool(self.delete_source_zip_var.get()),
            self.events,
            self.job_store,
            linked_target,
            (self.mode_owner, config_port),
        )
        self.preflight_worker.start()
        self.manual_status_var.set(gettext("Preflight running"))
        self.import_activity_var.set("Import: preflight")
        self.refresh_revoke_button_state()


    def format_bytes(self, value: int) -> str:
        """Format one byte count for compact UI summaries.

        Args:
            value (int): Observed size in bytes from the existing UI owner.
        Returns:
            str: Shared compact size caption used by both desktop frontends.
        """
        return format_gallery_bytes(value)


    def render_preflight_plan(self, plan: ImportPlan) -> None:
        """Render a compact import summary and enable Start import when useful.

        Args:
            plan (ImportPlan): Preflight job, duplicate counts and warnings.

        Returns:
            None: Updates the summary, item tree and import controls.
        """
        job = plan.job
        ready = sum(1 for item in job.items if item.state in RECOVERABLE_ITEM_STATES)
        self.manual_preflight_var.set(format_preflight_plan_summary(
            plan,
            use_local_thumbnails=bool(self.manual_local_thumbnails_var.get()),
            local_thumbnail_available=runtime.local_thumbnail_supported(),
            media_capabilities=self.media_capabilities,
        ))
        self.refresh_import_item_tree(job)
        self.start_import_button.configure(state="normal" if ready > 0 else "disabled")
        self.retry_failed_button.configure(state="normal" if any(item.state in RECOVERABLE_ITEM_STATES or item.state == "failed_permanent" for item in job.items) else "disabled")
        self.activity_job_summary_var.set(self.describe_job(job))


    def _reconcile_tree_rows(
        self,
        tree: runtime.ttk.Treeview,
        rows: List[Tuple[str, Tuple[str | int | float, ...]]],
        retained_activity_events: Tuple[ActivityEvent, ...] = (),
    ) -> None:
        """Apply ordered row changes without rebuilding retained Treeview items.

        Args:
            tree (ttk.Treeview): Watcher-owned table whose rows this owner controls.
            rows (List[Tuple[str, Tuple[str | int | float, ...]]]): Ordered
                stable item IDs paired with their displayed cell values.
            retained_activity_events (Tuple[ActivityEvent, ...]): Bounded
                event objects whose visible IIDs must remain non-recyclable.
                Defaults to an empty tuple for non-Activity tables.

        Returns:
            None: Adds, updates, removes and reorders only the required rows.

        Raises:
            ValueError: The desired rows contain a duplicate stable item ID.
        """
        desired_order = [item_id for item_id, _values in rows]
        desired_rows = {item_id: values for item_id, values in rows}
        if len(desired_order) != len(desired_rows):
            raise ValueError("Treeview row identities must be unique.")

        snapshot = cast(
            watcher_state.Optional[_TreeRowSnapshot],
            getattr(tree, "_watcher_row_snapshot", None),
        )
        current_order = list(tree.get_children(""))
        if snapshot is None:
            snapshot = _TreeRowSnapshot(
                rows={}, order=current_order.copy(),
            )
            setattr(tree, "_watcher_row_snapshot", snapshot)

        selected_before = tuple(tree.selection())
        focused_before = tree.focus()
        vertical_view = tuple(float(value) for value in tree.yview())
        horizontal_view = tuple(float(value) for value in tree.xview())
        desired_ids = set(desired_order)
        current_ids = set(current_order)
        structure_changed = False

        for item_id in current_order.copy():
            if item_id not in desired_ids:
                tree.delete(item_id)
                current_order.remove(item_id)
                current_ids.remove(item_id)
                structure_changed = True

        for index, (item_id, values) in enumerate(rows):
            inserted = item_id not in current_ids
            if inserted:
                tree.insert("", index, iid=item_id, values=values)
                current_order.insert(index, item_id)
                current_ids.add(item_id)
                structure_changed = True
            elif current_order[index] != item_id:
                tree.move(item_id, "", index)
                current_order.remove(item_id)
                current_order.insert(index, item_id)
                structure_changed = True

            if not inserted and snapshot.rows.get(item_id) != values:
                tree.item(item_id, values=values)

        snapshot.rows = desired_rows
        snapshot.order = desired_order
        snapshot.retained_activity_events = retained_activity_events

        surviving_selection = tuple(
            item_id for item_id in selected_before
            if item_id in desired_ids
        )
        selected_after = tuple(tree.selection())
        if set(selected_after) != set(surviving_selection):
            if surviving_selection:
                tree.selection_set(*surviving_selection)
            elif selected_after:
                tree.selection_remove(*selected_after)

        if focused_before in desired_ids:
            if tree.focus() != focused_before:
                tree.focus(focused_before)
        elif focused_before and tree.focus():
            tree.focus("")

        if structure_changed:
            if vertical_view:
                tree.yview_moveto(vertical_view[0])
            if horizontal_view:
                tree.xview_moveto(horizontal_view[0])


    def refresh_import_item_tree(self, job: watcher_state.Optional[ImportJob]) -> None:
        """Reconcile preflight and selected-job item details from one snapshot.

        Args:
            job (Optional[ImportJob]): Current job to render, or None to clear
                both manual-import detail tables.

        Returns:
            None: The bounded item views match the current job snapshot.
        """
        if not hasattr(self, "import_item_tree"):
            return
        if hasattr(self, "_presented_import_views"):
            self._presented_import_views.clear()
        self._history_import_view = None
        items = (
            job.items[:WATCHER_IMPORT_TREE_ROW_LIMIT]
            if job is not None else []
        )
        item_rows: List[Tuple[str, Tuple[str | int | float, ...]]] = []
        activity_rows: List[Tuple[str, Tuple[str | int | float, ...]]] = []
        for item in items:
            filename = watcher_state.Path(item.source_label).name or item.source_label
            item_rows.append((
                item.id,
                (
                    filename,
                    self.format_bytes(item.size),
                    item.local_decode,
                    item.state,
                    item.reason,
                ),
            ))
            detail = item.reason
            if not detail and item.server_result:
                detail = (
                    f"uploaded={item.server_result.get('uploaded', 0)}, "
                    f"scanned={item.server_result.get('scanned', 0)}"
                )
            activity_rows.append((
                "activity-" + item.id,
                (filename, item.state, item.attempt_count, detail),
            ))
        self._reconcile_tree_rows(self.import_item_tree, item_rows)
        if hasattr(self, "activity_item_tree"):
            self._reconcile_tree_rows(self.activity_item_tree, activity_rows)


    def _change_import_rows(
        self,
        tree: "ttk.Treeview",
        rows: Iterable[tuple[str, tuple[str | int | float, ...]]],
    ) -> None:
        """Change values of known rows while retaining their position and focus.

        Args:
            tree (ttk.Treeview): Detail or history table with a reconciled prefix.
            rows (Iterable[tuple[str, tuple[str | int | float, ...]]]): Stable
                item IDs and displayed values for changed rows only.

        Returns:
            None: Writes only changed retained cells and never alters selection.
        """
        snapshot = getattr(tree, "_watcher_row_snapshot", None)
        if not isinstance(snapshot, _TreeRowSnapshot):
            return
        for item_id, values in rows:
            if item_id in snapshot.rows and snapshot.rows[item_id] != values:
                tree.item(item_id, values=values)
                snapshot.rows[item_id] = values


    def _selected_import_view(self) -> _ImportViewProjection | None:
        """Return the visible job's projection, preferring its live generation.

        Returns:
            _ImportViewProjection | None: A matching live or historical view.
        """
        job = getattr(self, "current_job", None)
        if job is None:
            return None
        for view in (
            getattr(self, "_live_import_view", None),
            getattr(self, "_history_import_view", None),
        ):
            if view is not None and view.latest is not None:
                if view.latest.history.job_id == job.id:
                    return view
        return None


    def _accept_import_progress(self, snapshot: ImportProgressSnapshot) -> None:
        """Merge a saved worker revision while leaving durable ownership alone.

        Args:
            snapshot (ImportProgressSnapshot): Immutable post-save projection.

        Returns:
            None: Fences the generation and recovers any missing visible prefix
                from the worker's in-memory post-save projection.
        """
        worker = self.manual_worker
        if (worker is None or snapshot.generation != worker.import_generation
                or snapshot.history.job_id != worker.import_job_id):
            return
        view = getattr(self, "_live_import_view", None)
        previous = view.latest if view is not None else None
        if previous is None or previous.generation != snapshot.generation:
            view = _ImportViewProjection()
            self._live_import_view = view
            previous = None
        if previous is not None and snapshot.revision <= previous.revision:
            return
        if not snapshot.complete_rows and (
            previous is None or snapshot.revision != previous.revision + 1
        ):
            complete = worker.latest_import_projection()
            if complete is None:
                return
            snapshot = complete
        view.update(snapshot)
        job_id = snapshot.history.job_id
        history_rows = getattr(self, "_import_history_rows", {})
        history_row = history_rows.get(job_id)
        if history_row is not None and history_row.status != snapshot.history.status:
            row = watcher_state.replace(snapshot.history, created_label=history_row.created_label)
            history_rows[job_id] = row
            self._import_history_dirty[job_id] = row
        if snapshot.finished_at:
            self._flush_import_presentation(force=True)


    def _render_import_view(
        self, view: _ImportViewProjection, pane: ImportDetailPane,
    ) -> bool:
        """Publish one visible tab's retained cells and current progress text.

        Args:
            view (_ImportViewProjection): Selected job's cached rows and counters.
            pane (ImportDetailPane): Currently visible Import or Activity tab.

        Returns:
            bool: True when this presentation consumed a new revision or view.
        """
        snapshot = view.latest
        if snapshot is None:
            return False
        switched = self._presented_import_views.get(pane) is not view
        if not switched and view.presented_revisions.get(pane) == snapshot.revision:
            return False
        full = switched or pane in view.full_panes
        dirty = view.import_dirty if pane == "import" else view.activity_dirty
        sources = view.rows.values() if full else dirty.values()
        if pane == "import":
            tree = self.import_item_tree
            rows = [
                (row.item_id, row.item_values(self.format_bytes(row.size_bytes)))
                for row in sources
            ]
            if view is self._live_import_view:
                self._render_active_import_progress(snapshot)
        else:
            tree = self.activity_item_tree
            rows = [("activity-" + row.item_id, row.activity_values()) for row in sources]
            summary = self._describe_import_progress(snapshot)
            if self.activity_job_summary_var.get() != summary:
                self.activity_job_summary_var.set(summary)
        if full:
            self._reconcile_tree_rows(tree, rows)
            view.full_panes.discard(pane)
        else:
            self._change_import_rows(tree, rows)
        dirty.clear()
        view.presented_revisions[pane] = snapshot.revision
        self._presented_import_views[pane] = view
        return True


    def _render_active_import_progress(self, snapshot: ImportProgressSnapshot) -> None:
        """Refresh progress counters and throughput without reading the job file.

        Args:
            snapshot (ImportProgressSnapshot): Newest saved revision for Import.

        Returns:
            None: Publishes the displayed progress amount and status only.
        """
        counts = snapshot.counts
        terminal = counts.uploaded + counts.duplicates + counts.failed
        terminal += counts.cancelled + counts.skipped
        total = max(1, snapshot.item_total + snapshot.skipped_total)
        percent = min(100.0, terminal * 100.0 / total)
        if self.manual_progress_var.get() != percent:
            self.manual_progress_var.set(percent)
        elapsed = max(0.001, watcher_state.time.time() - snapshot.started_at) if snapshot.started_at else 0.0
        rate = snapshot.bytes_sent / elapsed if elapsed else 0.0
        remaining = max(0, snapshot.bytes_total - snapshot.bytes_sent)
        eta = remaining / rate if rate > 0 else 0.0
        rate_text = f"{self.format_bytes(int(rate))}/s" if rate > 0 else gettext("rate pending")
        eta_text = (
            gettext("ETA {seconds}s").format(seconds=int(eta))
            if eta > 0 else gettext("ETA pending")
        )
        text = gettext(
            "{complete}/{total} terminal | {sent} / {size} | {rate} | {eta}"
        ).format(
            complete=terminal, total=total, sent=self.format_bytes(snapshot.bytes_sent),
            size=self.format_bytes(snapshot.bytes_total), rate=rate_text, eta=eta_text,
        )
        if self.manual_progress_text_var.get() != text:
            self.manual_progress_text_var.set(text)


    def _describe_import_progress(
        self, snapshot: ImportProgressSnapshot, *, for_display: bool = True,
    ) -> str:
        """Describe the selected job from copied counters and labels.

        Args:
            snapshot (ImportProgressSnapshot): Saved counters and display labels.
            for_display (bool): Use the active locale for UI summaries.

        Returns:
            str: Activity status and, for active jobs, measurable throughput.
        """
        translate = gettext if for_display else gettext_noop
        history = snapshot.history
        counts = snapshot.counts
        progress = (
            f"{self.format_bytes(snapshot.bytes_sent)} / "
            f"{self.format_bytes(snapshot.bytes_total)}"
        )
        if snapshot.started_at and not snapshot.finished_at:
            elapsed = max(0.001, watcher_state.time.time() - snapshot.started_at)
            rate = snapshot.bytes_sent / elapsed
            if rate > 0:
                remaining = max(0, snapshot.bytes_total - snapshot.bytes_sent)
                progress += translate(gettext_noop(" at {rate}/s, ETA {seconds}s")).format(
                    rate=self.format_bytes(int(rate)), seconds=int(remaining / rate),
                )
        summary = gettext_noop(
            "{kind}: {source} | target {gallery} | "
            "confirmed {confirmed}, duplicates {duplicates}, failed {failed}, "
            "cancelled {cancelled}, active {active} | {progress}"
        )
        return translate(summary).format(
            kind=translate(history.source_kind), source=history.source_label,
            gallery=history.target_gallery_label, confirmed=counts.uploaded,
            duplicates=counts.duplicates, failed=counts.failed,
            cancelled=counts.cancelled, active=counts.active, progress=progress,
        )


    def _flush_import_presentation(self, *, force: bool = False) -> None:
        """Coalesce ordinary projections while giving a newly visible pane its state.

        Args:
            force (bool): Publish immediately on visibility or a terminal transition.

        Returns:
            None: Applies current visible rows, counters and history without I/O.
        """
        if getattr(self, "window_hidden_to_tray", False) is True:
            return
        now = watcher_state.time.monotonic()
        if not force and now < self._import_presentation_after:
            return
        view = self._selected_import_view()
        rendered = False
        if getattr(self, "_import_tab_selected", False):
            if view is not None:
                rendered = self._render_import_view(view, "import")
        elif getattr(self, "_activity_tab_selected", False):
            if view is not None:
                rendered = self._render_import_view(view, "activity")
            rendered = self._render_import_history() or rendered
        if rendered:
            self._import_presentation_after = now + IMPORT_PRESENTATION_INTERVAL_MS / 1000.0
            self._import_presentation_flushes += 1


    def _finish_import_presentation(
        self, job_id: str, *, cancelled: bool, needs_review: bool,
    ) -> None:
        """Publish the current worker's terminal outcome immediately.

        Args:
            job_id (str): Durable job of the already-fenced worker.
            cancelled (bool): Whether this logical run was cancelled.
            needs_review (bool): Whether required completion evidence was
                unavailable or the worker stopped on a recovery/state error.

        Returns:
            None: Updates lifecycle controls and schedules a separate durable
                read for later explicit actions on the selected job.
        """
        self._flush_import_presentation(force=True)
        view = self._live_import_view
        snapshot = view.latest if view is not None else None
        failed = snapshot.counts.failed if snapshot is not None else 0
        cancelled_items = snapshot.counts.cancelled if snapshot is not None else 0
        status = gettext("Import cancelled") if cancelled else gettext("Import finished")
        if needs_review:
            status = gettext("Import needs review")
        self.manual_status_var.set(status)
        self.import_activity_var.set(
            "Import: needs review" if needs_review
            else "Import: failed items" if failed else "Import: idle",
        )
        for button in (
            self.pause_import_button, self.resume_import_button, self.cancel_import_button,
        ):
            button.configure(state="disabled")
        if self.current_job is not None and self.current_job.id == job_id:
            self.start_import_button.configure(state="disabled")
            if not needs_review and not failed and not cancelled_items:
                if self.manual_progress_var.get() != 100.0:
                    self.manual_progress_var.set(100.0)
            self.retry_failed_button.configure(
                state="normal" if failed or cancelled_items or needs_review else "disabled",
            )
            if self._import_detail_request is None:
                self._request_import_detail(job_id, purpose="finished")
        self.refresh_job_history()


    def describe_job(self, job: ImportJob, *, for_display: bool = True) -> str:
        """Return an import summary with stable counters and source identity.

        Args:
            job (ImportJob): Selected durable import job.
            for_display (bool): Translate presentation text for the Activity view.

        Returns:
            str: Compact localized UI text or source-language diagnostics.
        """
        translate = gettext if for_display else gettext_noop
        counts = job.counts()
        progress = f"{self.format_bytes(job.bytes_sent)} / {self.format_bytes(job.bytes_total)}"
        if job.started_at and not job.finished_at:
            elapsed = max(0.001, watcher_state.time.time() - job.started_at)
            rate = job.bytes_sent / elapsed
            if rate > 0:
                remaining = max(0, job.bytes_total - job.bytes_sent)
                rate_message = gettext_noop(" at {rate}/s, ETA {seconds}s")
                progress += translate(rate_message).format(
                    rate=self.format_bytes(int(rate)), seconds=int(remaining / rate),
                )
        summary = gettext_noop(
            "{kind}: {source} | target {gallery} | "
            "confirmed {confirmed}, duplicates {duplicates}, failed {failed}, "
            "cancelled {cancelled}, active {active} | {progress}"
        )
        return translate(summary).format(
            kind=translate(job.source_kind), source=job.source_label,
            gallery=job.target_gallery_label, confirmed=counts["uploaded"],
            duplicates=counts["duplicates"], failed=counts["failed"],
            cancelled=counts["cancelled"], active=counts["active"], progress=progress,
        )


    def refresh_manual_file_label(self) -> None:
        """
        Refresh the visible manual selection count.

        Returns:
            None: Publishes the current source without changing the job.
        """
        if self.manual_paths:
            self.manual_selection_var.set(gettext("Files: {manual_path_count} selected").format(
                manual_path_count=len(self.manual_paths),
            ))
        elif self.manual_folder is not None:
            self.manual_selection_var.set(gettext("Folder: ") + str(self.manual_folder))
        elif self.manual_zip is not None:
            self.manual_selection_var.set(gettext("ZIP: ") + str(self.manual_zip))
        else:
            self.manual_selection_var.set(gettext("Choose files, a folder, or a ZIP archive"))


    def launch_manual_job(
        self, job: ImportJob, paths: List[watcher_state.Path], config: WatcherConfig,
        linked_target: watcher_state.Optional[LinkedUploadTarget] = None,
    ) -> None:
        """Bind and launch accepted manual-import work for the saved connection.

        Args:
            job (ImportJob): Durable job containing accepted source items.
            paths (List[Path]): Recoverable originals selected for this attempt.
            config (WatcherConfig): Saved connection and upload settings snapshot.
            linked_target (Optional[LinkedUploadTarget]): Captured delegated
                destination, or None for explicitly selected API-key mode.

        Returns:
            None: Starts the worker after binding, or reports a recovery error.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if getattr(self, "api_key_revoke_running", False):
            self.write_log(
                "Manual import cannot start while accepted work is finishing.",
                "warning",
            )
            return
        if not paths:
            return
        if (self.linking_manager.linked_upload_mode_selected()
                != (linked_target is not None)):
            runtime.messagebox.showerror(gettext('Import recovery'), gettext('The selected gallery connection changed.'))
            return
        if (linked_target is not None and (
                self.linking_manager.linked_upload_binding() != linked_target.binding()
                or self.linking_manager.selected_upload_target() is not linked_target)):
            runtime.messagebox.showerror(gettext('Import recovery'), gettext('Reconnect the selected linked gallery.'))
            return
        if self.flights.session_state() != "idle":
            runtime.messagebox.showinfo(
                gettext("Import"), gettext("Finish the current Watcher session before starting an import."),
                parent=self.root,
            )
            return
        use_local_thumbnails = bool(self.manual_local_thumbnails_var.get()) and runtime.local_thumbnail_supported()
        if self.manual_local_thumbnails_var.get() and not runtime.local_thumbnail_supported():
            self.write_log("Local thumbnail generation is unavailable. Server thumbnail generation will be used.", "warning")
            use_local_thumbnails = False
        try:
            binding = (linked_target.binding() if linked_target is not None
                       else self.flights.binding())
            admit_reviewed_import(
                self.job_store, self.flights.import_bindings, job, binding=binding,
                generation=config._snapshot_token,
                approval=getattr(self, '_preflight_approved', None),
            )
        except (OSError, ValueError, RuntimeError) as exc:
            runtime.messagebox.showerror(gettext("Import recovery"), str(exc))
            return
        self.current_job = job
        self._live_import_view = None
        self._history_import_view = None
        self._import_detail_request = None
        self._import_presentation_after = 0.0
        self.manual_worker = create_import_worker(
            self.config_store, config, self.mode_owner, self.job_store, job,
            paths, linked_target, use_local_thumbnails, self.events,
        )
        self.manual_worker.start()
        self.manual_status_var.set(gettext("Import running"))
        self.import_activity_var.set("Import: running")
        self.start_import_button.configure(state="disabled")
        self.pause_import_button.configure(state="normal")
        self.resume_import_button.configure(state="disabled")
        self.cancel_import_button.configure(state="normal")
        self.retry_failed_button.configure(state="disabled")
        self.write_log(f"Manual import job started with {len(paths)} file(s).", "success")
        self.record_activity(
            "success", gettext_noop("Manual import started with {count} file(s)."),
            "import", job_id=job.id, fields={"count": str(len(paths))},
        )
        self.refresh_activity_monitor_state()
        self.refresh_revoke_button_state()


    def _discard_unstarted_import_preflight(self) -> bool:
        """Retire an unbound review plan before new source selection.

        Returns:
            bool: True when the plan can be replaced. Admitted jobs stay in
                recovery, even while their independent review copy is older.
        """
        plan = self.manual_plan
        worker = getattr(self, "manual_worker", None)
        if (plan is None or plan.job.started_at > 0
                or getattr(worker, "import_job_id", None) == plan.job.id):
            return True
        try:
            self.flights.import_bindings.discard_unbound_preflight(
                self.job_store, plan.job.id,
            )
        except (OSError, ValueError, RuntimeError) as exc:
            runtime.messagebox.showwarning(gettext("Import recovery"), str(exc))
            return False
        return True


    def pause_manual_upload(self) -> None:
        """Pause new import work while allowing in-flight requests to finish.

        Returns:
            None: Pauses a live worker and updates its controls.
        """
        if self.manual_worker and self.manual_worker.is_alive():
            self.manual_worker.pause()
            self.manual_status_var.set(gettext("Import paused"))
            self.import_activity_var.set("Import: paused")
            self.pause_import_button.configure(state="disabled")
            self.resume_import_button.configure(state="normal")


    def resume_manual_upload(self) -> None:
        """Resume a paused manual import.

        Returns:
            None: Resumes a live worker and updates its controls when admitted.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.manual_worker and self.manual_worker.is_alive():
            self.manual_worker.resume()
            self.manual_status_var.set(gettext("Import running"))
            self.import_activity_var.set("Import: running")
            self.pause_import_button.configure(state="normal")
            self.resume_import_button.configure(state="disabled")


    def retry_failed_import(self) -> None:
        """Explicitly retry failed/cancelled or crash-interrupted items in the selected job.

        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.api_key_revoke_running:
            runtime.messagebox.showwarning(gettext("Connection"), gettext("Wait for API-key revocation to finish before retrying an import."))
            return
        if self.manual_worker and self.manual_worker.is_alive():
            runtime.messagebox.showwarning(gettext("Retry import"), gettext("Wait for the current import to stop before retrying failed items."))
            return
        pending_detail = getattr(self, "_import_detail_request", None)
        if pending_detail is not None and pending_detail[2] == "selected":
            return
        if self.current_job is None:
            runtime.messagebox.showwarning(gettext("Retry import"), gettext("Select a job in Activity first."))
            return
        history_view = getattr(self, "_history_import_view", None)
        if (history_view is not None and history_view.latest is not None
                and history_view.latest.history.job_id == self.current_job.id
                and getattr(self, "_activity_tab_selected", False)
                and self.current_job.id not in self.job_history_tree.selection()):
            runtime.messagebox.showwarning(gettext("Retry import"), gettext("Select a job in Activity first."))
            return
        try:
            proposed = self.current_config()
            if proposed.to_dict() != self.config.to_dict():
                raise ValueError('Save the current settings and run preflight again.')
            target = self.capture_linked_target()
            binding = target.binding() if target is not None else self.flights.binding()
            # Only a fresh durable row with its original binding may be retried.
            refreshed = read_verified_import_retry(
                self.job_store, self.flights.import_bindings, self.current_job.id,
                binding=binding, generation=self.config._snapshot_token,
                approval=getattr(self, '_preflight_approved', None),
            )
        except (OSError, ValueError, RuntimeError) as exc:
            runtime.messagebox.showwarning(gettext('Retry import'), gettext(str(exc)))
            return
        try:
            retry = select_retry_sources(self.job_store, refreshed)
        except (OSError, ValueError, RuntimeError) as exc:
            runtime.messagebox.showwarning(gettext("Import review"), str(exc))
            return
        if retry.uncertain:
            runtime.messagebox.showwarning(
                gettext('Import review'),
                gettext(
                    "{uncertain} upload(s) have an uncertain result. "
                    "Review the gallery manually; they were not retried."
                ).format(
                    uncertain=retry.uncertain,
                ),
            )
        if not retry.paths:
            runtime.messagebox.showinfo(gettext("Retry import"), gettext("No failed or recoverable local files are available for retry."))
            self.current_job = refreshed
            self._live_import_view = None
            self._import_detail_request = None
            self.refresh_import_item_tree(refreshed)
            self.refresh_job_history()
            return
        refreshed.finished_at = 0.0
        try:
            config = self.current_config()
            linked_target = self.capture_linked_target()
            if not self.connection_change_allowed(config):
                return
            config = self._save_owned_config(config)
            self.config = config
        except Exception as exc:  # noqa: BLE001
            runtime.messagebox.showerror(gettext("Configuration error"), str(exc))
            return
        if (linked_target is None
                and (not watcher_state.normalize_upload_url(config.gallery_url) or not config.api_key.strip())):
            runtime.messagebox.showerror(gettext("Connection"), gettext("Gallery URL and API key are required before retrying."))
            return
        self.current_job = refreshed
        self.launch_manual_job(refreshed, list(retry.paths), config, linked_target)


    def offer_recoverable_jobs(self) -> None:
        """Offer the newest unfinished import for explicit recovery after restart.

        Returns:
            None: Opens Import only after explicit recovery consent.
        """
        if self.exiting or self.current_job is not None:
            return
        jobs = self.job_store.recoverable_jobs()
        if not jobs:
            return
        job = jobs[0]
        counts = job.counts()
        answer = runtime.messagebox.askyesno(
            gettext("Recover unfinished import"),
            gettext(
                "An unfinished import from {value} contains "
                "{value2} recoverable item(s). Open it for review and resume/retry?"
            ).format(
                value=watcher_state.time.strftime('%Y-%m-%d %H:%M', watcher_state.time.localtime(job.created_at)),
                value2=counts['active'] + counts['failed'] + counts['cancelled'],
            ),
        )
        if not answer:
            self.record_activity(
                "warning", gettext_noop("Recoverable import remains saved for later review."),
                "import", job_id=job.id,
            )
            return
        self.current_job = job
        self._import_detail_request = None
        self.manual_plan = ImportPlan(job=job)
        self.manual_paths = [watcher_state.Path(item.path) for item in job.items if watcher_state.Path(item.path).is_file()]
        self.manual_folder = None
        self.manual_zip = watcher_state.Path(job.source_zip) if job.source_zip else None
        self.manual_selection_var.set(gettext("Recovered import: ") + job.source_label)
        self.render_preflight_plan(self.manual_plan)
        self.select_tab("import")
        self.write_log("Recovered unfinished import for explicit review. No upload was started automatically.", "warning")


    def refresh_job_history(self) -> None:
        """Request the newest durable import rows from the background reader.

        Returns:
            None: The event pump applies the newest result if Activity is visible.
        """
        if not hasattr(self, "job_history_tree"):
            return
        if getattr(self, "_import_history_requested", False):
            # Deliver the in-flight list even under repeated refreshes and
            # then take one follow-up read for any intervening state changes.
            self._recheck_import_history = True
            return
        self._get_import_history_reader().request_history(
            WATCHER_JOB_HISTORY_TREE_ROW_LIMIT,
        )
        self._import_history_requested = True


    def _get_import_history_reader(self) -> ImportHistoryReader:
        """Return the application's single bounded read-only history worker.

        Returns:
            ImportHistoryReader: Owner of background history and detail reads.
        """
        reader = getattr(self, "_import_history_reader", None)
        if reader is None:
            reader = ImportHistoryReader(self.job_store)
            self._import_history_reader = reader
        return reader


    def _render_import_history(self) -> bool:
        """Publish a bounded history listing or just its changed progress rows.

        Returns:
            bool: True when the visible Activity table consumed a projection.
        """
        if not self._import_history_rebuild and not self._import_history_dirty:
            return False
        records = (
            self._import_history_rows if self._import_history_rebuild
            else self._import_history_dirty
        )
        rows = [(row.job_id, row.row_values(row.created_label)) for row in records.values()]
        if self._import_history_rebuild:
            self._reconcile_tree_rows(self.job_history_tree, rows)
        else:
            self._change_import_rows(self.job_history_tree, rows)
        self._import_history_rebuild = False
        self._import_history_dirty.clear()
        return True


    def select_history_job(self, _event: "tk.Event | None" = None) -> None:
        """Load one visible history selection for inspection or explicit retry.

        Args:
            _event (tk.Event | None): Optional event from the history Treeview.

        Returns:
            None: Schedules an off-Tk detail read fenced to the selected row.
        """
        if not hasattr(self, "job_history_tree"):
            return
        selection = self.job_history_tree.selection()
        if selection:
            self._request_import_detail(selection[0], purpose="selected")
        elif self._import_detail_request is not None and self._import_detail_request[2] == "selected":
            self._import_detail_request = None


    def _request_import_detail(
        self, job_id: str, *, purpose: Literal["selected", "finished"],
    ) -> None:
        """Request the independent durable model and its bounded presentation.

        Args:
            job_id (str): Durable job ID owned by the selection or finished batch.
            purpose (Literal["selected", "finished"]): Whether to change
                selection or refresh the selected model after completion.

        Returns:
            None: Retains the ticket alongside the current selection intent.
        """
        ticket = self._get_import_history_reader().request_job(
            job_id, WATCHER_IMPORT_TREE_ROW_LIMIT, new_worker_generation(),
        )
        self._import_detail_request = (ticket, job_id, purpose)


    def _receive_import_history(self) -> None:
        """Apply available bounded history results on Tk without blocking it.

        Returns:
            None: Accepts the latest list or explicitly selected durable job.
        """
        reader = getattr(self, "_import_history_reader", None)
        if reader is None:
            return
        for result in reader.poll_ready():
            if result.kind == "history":
                self._import_history_requested = False
                if getattr(self, "_recheck_import_history", False):
                    self._recheck_import_history = False
                    self.refresh_job_history()
                if result.error is not None:
                    self.write_log("Import history could not be read.", "warning")
                    continue
                rows = {row.job_id: row for row in result.history}
                live = getattr(self, "_live_import_view", None)
                if live is not None and live.latest is not None and not live.latest.finished_at:
                    current_row = live.latest.history
                    if current_row.job_id in rows:
                        rows[current_row.job_id] = watcher_state.replace(
                            current_row, created_label=rows[current_row.job_id].created_label,
                        )
                if list(rows.items()) != list(self._import_history_rows.items()):
                    self._import_history_rows = rows
                    self._import_history_dirty.clear()
                    self._import_history_rebuild = True
            else:
                self._accept_import_detail(result)


    def _accept_import_detail(self, result: ImportHistoryReadResult) -> None:
        """Adopt an independently read job only for its current request.

        Args:
            result (ImportHistoryReadResult): Detail outcome with a frozen
                projection and, on success, an exclusively transferred model.

        Returns:
            None: Preserves the existing retry admission and selected row fence.
        """
        pending = self._import_detail_request
        if pending is None or result.ticket != pending[0]:
            return
        self._import_detail_request = None
        _, job_id, purpose = pending
        if purpose == "selected":
            selected = self.job_history_tree.selection()
            if not selected or selected[0] != job_id:
                return
        elif self.current_job is None or self.current_job.id != job_id:
            return
        job = result.job
        snapshot = result.snapshot
        if job is None or snapshot is None or job.id != job_id:
            self.write_log("Selected import history could not be read.", "warning")
            if purpose == "selected":
                self.job_history_tree.selection_remove(job_id)
            return
        self.current_job = job
        if purpose == "finished":
            if self.manual_plan is not None and self.manual_plan.job.id == job.id:
                self.manual_plan = watcher_state.replace(self.manual_plan, job=job)
            return
        self.manual_plan = ImportPlan(job=job)
        history_view = _ImportViewProjection()
        history_view.update(snapshot)
        self._history_import_view = history_view
        self.retry_failed_button.configure(
            state="normal" if snapshot.retry_available else "disabled",
        )
        self._flush_import_presentation(force=True)


    def record_activity(
        self, level: str, message: str, operation: str = "system",
        filename: str = "", job_id: str = "",
        *, fields: dict[str, str] | None = None,
    ) -> None:
        """Display a redacted event and enqueue its bounded history off Tk.

        Args:
            level (str): Event severity such as info, success, warning, or error.
            message (str): Stable diagnostic detail or source template.
            operation (str): Owning activity category.
            filename (str): Optional local filename associated with the event.
            job_id (str): Optional durable import job identifier.
            fields (dict[str, str] | None): Named values for a presentation
                template. Each value is redacted before persistence.

        Returns:
            None: Retains and submits every event in order. The latest footer
                and visible drawer are synchronized through their GUI owners.
        """
        secret = self.api_key_var.get().strip() if hasattr(self, "api_key_var") else ""
        message_id = message if fields else ""
        if fields:
            message = message.format_map(fields)
        # The queue marker has already selected the structured operation.
        if operation == "simconnect" and message.startswith("[simconnect]"):
            message = message[len("[simconnect]"):].lstrip()
        safe_message = redact_text(message, [secret], redact_paths=False)
        event = ActivityEvent(
            level=level, message=safe_message, operation=operation,
            filename=filename, job_id=job_id,
            message_id=redact_text(message_id, [secret], redact_paths=False),
            message_fields={
                key: redact_text(value, [secret], redact_paths=False)
                for key, value in (fields or {}).items()
            },
        )
        self._pending_activity_footer = event
        if getattr(self, "_drain_running", False) is not True:
            self._flush_activity_footer()
        self.activity_events.append(event)
        limit = max(50, int(getattr(self.config, "activity_history_limit", 500) or 500))
        if len(self.activity_events) > limit:
            del self.activity_events[:-limit]
        try:
            if not self.activity_writer.submit(event):
                logging.warning("Recent activity history is closing; event remains in the log.")
        except Exception:  # noqa: BLE001
            logging.error("Could not queue recent activity history.")
        self._schedule_activity_drawer_refresh()


    def _flush_activity_footer(self) -> None:
        """Publish the newest Activity preview after a batch or window restoration.

        Returns:
            None: Formats just the newest preview, retaining its event
                for a window currently hidden to the system tray.
        """
        pending = getattr(self, "_pending_activity_footer", None)
        if pending is None or getattr(self, "window_hidden_to_tray", False) is True:
            return
        one_line_message = " ".join(type(self).activity_message(pending).split())
        text = f"{activity_label(pending.operation, title=True)} · {one_line_message}"
        style = {
            "warning": "Footer.Warning.TLabel",
            "error": "Footer.Error.TLabel",
        }.get(pending.level.lower(), "Footer.TLabel")
        _publish_watcher_text(self, "latest_activity_var", text[:LATEST_ACTIVITY_PREVIEW_LENGTH])
        label = self.latest_activity_label
        if label is not None:
            _publish_watcher_value(
                self, "latest_activity_style", label, style,
                lambda: label.configure(style=style),
            )
        self._pending_activity_footer = None


    @staticmethod
    def activity_message(event: ActivityEvent) -> str:
        """Localize only the displayed Activity message.

        Args:
            event (ActivityEvent): Stable event with redacted text and fields.

        Returns:
            str: Text in the active locale, or the stored diagnostic detail
                when a legacy or damaged row has no usable translation.
        """
        message_id = event.message_id or event.message
        translated = gettext(message_id)
        if translated == message_id:
            return operational_activity_caption(event)
        # Only format catalog text. Historical messages and externally supplied
        # details can contain braces and are kept as ordinary data.
        try:
            fields = event.message_fields
            if "detail" in fields:
                fields = {**fields, "detail": gettext(fields["detail"])}
            return translated.format_map(fields) if fields else translated
        except (KeyError, ValueError):
            return event.message


    def _schedule_activity_drawer_refresh(self) -> None:
        """Coalesce drawer rendering while activity state and persistence stay current.

        Returns:
            None: Schedules one idle render while Activity is visible or
                retains a dirty flag for its next visibility change.
        """
        if self.exiting or not hasattr(self, "activity_drawer"):
            return
        self._activity_drawer_dirty = True
        if (
            getattr(self, "_activity_tab_selected", True) is False
            or getattr(self, "window_hidden_to_tray", False) is True
        ):
            return
        if self._activity_render_after_id is not None:
            return
        callback_id: watcher_state.Optional[str] = None

        def flush() -> None:
            """Dispatch the drawer refresh with its captured timer identity.

            Returns:
                None: Delegates identity validation to the owning method.
            """
            self._flush_activity_drawer_refresh(callback_id)

        callback_id = self.root.after_idle(flush)
        self._activity_render_after_id = callback_id


    def _flush_activity_drawer_refresh(
        self, callback_id: watcher_state.Optional[str],
    ) -> None:
        """Render the latest activity rows once after queued events settle.

        Args:
            callback_id (Optional[str]): Identity captured by the scheduled
                idle callback so a stale callback cannot clear a replacement.

        Returns:
            None: Clears the owned callback and refreshes the current filter
                only while Activity remains visible.
        """
        if callback_id != self._activity_render_after_id:
            return
        self._activity_render_after_id = None
        if (
            not self.exiting and getattr(self, "_activity_tab_selected", True) is not False
            and getattr(self, "window_hidden_to_tray", False) is not True
        ):
            self.refresh_activity_drawer()
