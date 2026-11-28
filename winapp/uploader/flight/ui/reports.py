# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/ui/reports.py
# Module Type: Flight UI reports behavior
# Purpose: Own the reports operations of the existing FlightUI controller.
# Responsibilities: Preserve Tk callback and lifecycle behavior in a cohesive owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own reports callbacks for the FlightUI controller."""

from dataclasses import replace
from datetime import datetime, timezone
from pathlib import Path
import tkinter as tk
from tkinter import ttk
from uploader.ui_shell import EmbeddedWindow, messagebox

from uploader.flight_jobs import SessionPlan, SavedFlight, ReportRevision, report_revisions, report_file_label, report_file_details, organize_reports, saved_flights
from uploader.localization import gettext
from uploader.flight_workflow import FinalizationWorker, WatchedReportTarget, create_local_report_worker
from uploader.flight_simbrief import load_dispatch
from uploader.ui_theme import Button, GlassCard, icon_button

from .policy import (
    REPORT_COLUMN_WIDTHS,
    REPORT_NAME_WIDTHS,
    REPORT_TARGET_WIDTH,
    REPORT_VISIBLE_ROWS,
)


class ReportsMixin:
    """Provide reports callbacks to the established FlightUI state owner."""

    def show_reports(self) -> None:
        """Show saved flights and direct local and watched-gallery PDF actions.

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
        tools = ttk.Frame(panel, style="Card.TFrame")
        tools.grid(row=0, column=0, sticky="ew", pady=(0, 8))
        tools.columnconfigure(0, weight=1)
        ttk.Checkbutton(
            tools, text=self.text("report_flights_only"),
            variable=self.report_flights_only, command=self.refresh_reports,
            style="Card.TCheckbutton",
        ).grid(row=0, column=0, sticky="w")
        Button(
            tools, text=self.text("report_folder"),
            command=self.open_selected_reports, style="Compact.TButton",
        ).grid(row=0, column=1, padx=(6, 4))
        menu = tk.Menu(tools, tearoff=False)
        self.report_menu = menu
        for label, command in (
            ("upload_saved_pdf", self.upload_saved_pdf),
            ("simbrief_load", lambda: self.load_simbrief(saved=True)),
            ("simbrief_file", self.attach_ofp_file),
            ("refresh_reports", self.refresh_reports),
            ("open_app_log", self.host.open_log_file),
        ):
            menu.add_command(label=self.text(label), command=command)
        menu.add_separator()
        menu.add_command(label=self.text("finish_pdf"), command=self.finish_report)
        more = icon_button(
            tools, "…", lambda: self.show_report_menu(menu, more),
            self.text("report_more_actions"),
        )
        more.grid(row=0, column=2)
        viewport = ttk.Frame(panel)
        viewport.grid(row=1, column=0, sticky='nsew')
        viewport.columnconfigure(0, weight=1)
        viewport.rowconfigure(0, weight=1)
        columns = ("started", "samples", "pdfs", "created", "pages", "state")
        self.report_tree = ttk.Treeview(
            viewport, columns=columns, show="tree headings", height=REPORT_VISIBLE_ROWS,
            selectmode="extended",
        )
        self.report_tree.heading("#0", command=lambda: self.sort_reports("#0"))
        name_minimum, name_preferred = REPORT_NAME_WIDTHS
        self.report_tree.column(
            "#0", width=name_preferred, minwidth=name_minimum, stretch=False,
        )
        self.report_tree.configure(
            displaycolumns=("started", "pdfs", "state", "created", "pages", "samples"),
        )
        for name, width in zip(columns, REPORT_COLUMN_WIDTHS, strict=True):
            self.report_tree.heading(name, text=self.text("column_" + name),
                                     command=lambda column=name: self.sort_reports(column))
            self.report_tree.column(name, width=width, minwidth=40, stretch=False)
        self.report_tree.grid(row=0, column=0, sticky='nsew')
        vertical = ttk.Scrollbar(viewport, command=self.report_tree.yview)
        vertical.grid(row=0, column=1, sticky='ns')
        horizontal = ttk.Scrollbar(viewport, orient='horizontal', command=self.report_tree.xview)
        horizontal.grid(row=1, column=0, sticky='ew')
        self.report_tree.configure(yscrollcommand=vertical.set, xscrollcommand=horizontal.set)
        self.report_tree.bind("<Configure>", lambda event: self.layout_report_columns(event.width))
        self.report_tree.bind("<<TreeviewSelect>>", lambda _event: self.select_report())
        self.report_tree.bind("<Double-1>", lambda _event: self.open_selected_pdf())
        self.report_tree.bind("<Return>", lambda _event: self.open_selected_pdf())
        detail_label = ttk.Label(
            panel, textvariable=self.report_detail_var, wraplength=620,
            style="Muted.TLabel",
        )
        detail_label.grid(row=2, column=0, sticky='ew', pady=(8, 4))
        panel.bind("<Configure>", lambda event: detail_label.configure(
            wraplength=max(1, event.width - 32),
        ))
        footer = ttk.Frame(panel, style="Card.TFrame")
        footer.grid(row=3, column=0, sticky="ew", pady=(7, 0))
        footer.columnconfigure(0, weight=1)
        ttk.Separator(footer).grid(row=0, column=0, columnspan=2, sticky="ew")
        target = ttk.Frame(footer, style="Card.TFrame")
        target.grid(row=1, column=0, sticky="ew", pady=(8, 0))
        target_label = ttk.Label(
            target, textvariable=self.report_target_var, style="Section.TLabel",
        )
        target_label.pack(anchor="w")
        target_hint = ttk.Label(
            target, textvariable=self.report_target_hint, style="Muted.TLabel",
        )
        target_hint.pack(anchor="w")
        actions = ttk.Frame(footer, style="Card.TFrame")
        self.report_button = Button(
            actions, text=self.text("create_local_pdf"),
            command=self.create_local_pdf,
        )
        self.report_upload_button = Button(
            actions, text=self.text("report_create_upload"),
            command=self.create_and_upload_pdf, style="Primary.TButton",
        )
        buttons = [self.report_button, self.report_upload_button]
        for column, button in enumerate(buttons):
            button.grid(row=0, column=column, padx=4, pady=2, sticky="ew")
        footer.bind("<Configure>", lambda _event: self.layout_report_footer(
            footer, actions, target_label, target_hint, buttons,
        ))
        actions.grid(row=2, column=0, columnspan=2, sticky="ew", pady=(8, 0))
        self.refresh_reports()
        self.report_dialog.deiconify()

    def layout_report_columns(self, width: int) -> None:
        """Keep identifying columns together and let details scroll if needed.

        Args:
            width (int): Allocated Treeview width in Tk pixels.
        Returns:
            None: Fits the full metadata set when the minimum name width
                allows it. Smaller widths prioritize the flight and PDF count.
        """
        minimum, preferred = REPORT_NAME_WIDTHS
        started, _samples, pdfs, *_details = REPORT_COLUMN_WIDTHS
        # Two pixels per edge leave room for the native Treeview border.
        available = max(0, width - 4)
        metadata = sum(REPORT_COLUMN_WIDTHS)
        if available >= minimum + metadata:
            name_width = available - metadata
        else:
            name_width = max(minimum, min(preferred, available - started - pdfs))
        if self.report_tree.column("#0", "width") != name_width:
            self.report_tree.column("#0", width=name_width)

    def layout_report_footer(
        self, footer: ttk.Frame, actions: ttk.Frame, target_label: ttk.Label,
        target_hint: ttk.Label, buttons: list[ttk.Button],
    ) -> None:
        """Keep the watched gallery and both direct actions readable at any width.

        Args:
            footer (ttk.Frame): Full catalog footer with the target and actions.
            actions (ttk.Frame): Grid container for the two direct buttons.
            target_label (ttk.Label): Active gallery name, which may wrap.
            target_hint (ttk.Label): Short explanation of the destination state.
            buttons (list[ttk.Button]): Local create and online create buttons
                in display order.
        Returns:
            None: Places the actions beside the target or on a row below it.
        """
        width = footer.winfo_width()
        # Each button has four pixels of side padding on both edges.
        action_width = sum(button.winfo_reqwidth() + 8 for button in buttons)
        inline = width >= REPORT_TARGET_WIDTH + action_width
        stack = width < action_width
        actions.grid(
            row=1 if inline else 2, column=1 if inline else 0,
            columnspan=1 if inline else 2, sticky="ew" if stack else "e",
            pady=(8, 0),
        )
        actions.columnconfigure(0, weight=int(stack))
        for index, button in enumerate(buttons):
            button.grid(
                row=index if stack else 0, column=0 if stack else index,
                sticky="ew", padx=4, pady=2,
            )
        wrap = max(1, width - action_width if inline else width)
        target_label.configure(wraplength=wrap)
        target_hint.configure(wraplength=wrap)

    def show_report_menu(self, menu: tk.Menu, trigger: ttk.Button) -> None:
        """Open secondary report actions immediately below their anchor.

        Args:
            menu (tk.Menu): Catalog-owned action menu with upload first.
            trigger (ttk.Button): Ellipsis button opening the menu.
        Returns:
            None: Updates current upload eligibility and posts the menu.
        """
        self.refresh_report_actions()
        try:
            menu.tk_popup(
                trigger.winfo_rootx(),
                trigger.winfo_rooty() + trigger.winfo_height(),
            )
        finally:
            menu.grab_release()

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
            text = "v" + str(version[1].number) if version else (
                row.aircraft or row.gallery_identity
            )
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
            title = self.text("report_catalog_entry" if column == "#0" else "column_" + column)
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
            if self.report_flights_only.get() and not (count or row.report_count):
                continue
            try:
                stamp = datetime.fromisoformat(row.started_utc.replace("Z", "+00:00"))
                if stamp.tzinfo is None:
                    stamp = stamp.replace(tzinfo=timezone.utc)
                started = stamp.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            except ValueError:
                started = row.started_utc
            label = row.aircraft or row.gallery_identity or self.text("report_gallery_session")
            tree.insert("", "end", iid=identity, text=label, open=identity in expanded, values=(
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
                            text="v" + str(revision.number) + " · "
                                + ", ".join(code.upper() for code in revision.files),
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
                tree.item(selection[0], open=True)
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
            return None
        if (
            self.independent_operation()
            and self.operation.plans
        ):
            return self.report_rows.get(self.operation.plans[0].session_id)
        return None

    def select_report(self) -> None:
        """Show the selected session identity and retained recovery explanation.

        Returns:
            None: Updates the selected-record detail and action availability.
        """
        row = self.selected_saved_flight()
        detail = self.text("report_empty") if row is None else (
            row.error or self.text("report_open_hint")
        )
        if row is not None:
            dispatch = load_dispatch(row.directory)
            if dispatch is not None:
                detail += (
                    "  ·  " + self.text("simbrief_ready") + ": "
                    + dispatch.origin + " → " + dispatch.destination
                )
            if self.dispatch is not None and dispatch != self.dispatch:
                detail += (
                    "\n" + self.text("simbrief_available_for_report") + ": "
                    + self.dispatch.origin + " → " + self.dispatch.destination
                )
        self.report_detail_var.set(detail)
        self.refresh_report_actions()

    def refresh_report_actions(self) -> None:
        """Reflect the Watcher's live target and the selected row's admission.

        Returns:
            None: Updates visible controls without reading files or contacting
                either the selected flight's original gallery or the Watcher.
        """
        upload = self.report_upload_button
        if upload is None or not upload.winfo_exists():
            return
        target = self.host.watched_report_target()
        label = target.label if target is not None else self.text("report_watcher_off")
        hint = self.text(
            "report_watched_gallery" if target is not None else "report_start_watcher",
        )
        if self.report_target_var.get() != label:
            self.report_target_var.set(label)
        if self.report_target_hint.get() != hint:
            self.report_target_hint.set(hint)
        row = self.selected_saved_flight()
        ready = bool(
            row is not None and row.recording_ended and not self.busy()
            and self.lease is not None and not self.load_error
        )
        for button, enabled in ((self.report_button, ready), (upload, ready and target is not None)):
            state = "normal" if enabled else "disabled"
            if str(button.cget("state")) != state:
                button.configure(state=state)
        menu_state = "normal" if ready and target is not None and row.report_count else "disabled"
        if str(self.report_menu.entrycget(0, "state")) != menu_state:
            self.report_menu.entryconfigure(0, state=menu_state)

    def create_and_upload_pdf(self) -> None:
        """Generate a fresh revision and send it to the active Watcher's gallery.

        Returns:
            None: Captures the currently watched target before local rendering.
        """
        target = self.host.watched_report_target()
        if target is None:
            self.refresh_report_actions()
            return
        self.create_local_pdf(target=target)

    def create_local_pdf(
        self, *, target: WatchedReportTarget | None = None,
        row: SavedFlight | None = None,
    ) -> None:
        """Generate a fresh local revision with optional independent delivery.

        Args:
            target (WatchedReportTarget | None): Captured Watcher destination
                for a subsequent upload, or None for local-only creation.
            row (SavedFlight | None): Original stopped flight for a retry, or
                None to use the current catalog selection.
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
        row = row if row is not None else self.selected_saved_flight()
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
                self.load_error or gettext("Flight workspace unavailable."),
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
        try:
            plan = SessionPlan.load(row.directory)
            self.attach_loaded_dispatch(row.directory)
            # Photo Finish is durable intent, not a five-minute retry budget.
            # Its worker can remain in rate-limit/offline wait while local
            # capture reconciliation continues under the original cutoff.
            self._progress_generation += 1
            self.operation = create_local_report_worker(
                plan, watched_target=target,
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
        """Offer retained revisions or confirm explicitly selected PDF editions.

        Returns:
            None: Opens revision selection or starts confirmed delivery to
                the captured Watcher's gallery without regenerating files.
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
        target = self.host.watched_report_target()
        if target is None:
            self.refresh_report_actions()
            return
        selected = self.report_tree.selection() if self.report_tree is not None else ()
        groups: dict[str, ReportRevision] = {}
        sessions: set[str] = set()
        for identity in selected:
            if identity in self.report_versions:
                session, revision = self.report_versions[identity]
                files = revision.files
            elif identity in self.report_files:
                session, revision, language = self.report_files[identity]
                files = {language: revision.files[language]}
            else:
                continue
            sessions.add(session)
            group = groups.setdefault(revision.report_id, replace(revision, files={}))
            group.files.update(files)
        if len(sessions) > 1:
            messagebox.showinfo(
                self.text("saved_reports"),
                self.text("single_flight_selection"),
                parent=self.dialog_owner(),
            )
            return
        if groups:
            revisions = tuple(groups.values())
            self.start_saved_upload(
                self.report_rows[next(iter(sessions))],
                revisions[0], revisions[1:], target=target,
            )
            return
        self.choose_report_revisions(row, target)

    def choose_report_revisions(
        self, row: SavedFlight, target: WatchedReportTarget,
    ) -> None:
        """Select one or more revisions of one stopped flight for direct upload.

        Args:
            row (SavedFlight): Catalog session whose retained PDFs are offered.
            target (WatchedReportTarget): Active Watcher and destination shown
                in this picker and revalidated on submission.
        Returns:
            None: Opens an embedded picker, or explains unavailable revisions.
        """
        owner = self.dialog_owner()
        try:
            revisions = sorted(
                report_revisions(row.directory),
                key=lambda item: item.number or 0, reverse=True,
            )
        except (OSError, ValueError) as exc:
            messagebox.showerror(self.text("saved_reports"), str(exc), parent=owner)
            return
        if not revisions:
            messagebox.showinfo(
                self.text("saved_reports"), self.text("no_saved_pdf"), parent=owner,
            )
            return
        dialog = EmbeddedWindow(owner)
        dialog.title(self.text("upload_saved_pdf"))
        panel = GlassCard(dialog, padding=(16, 12))
        panel.pack(fill="both", expand=True, padx=10, pady=10)
        ttk.Label(panel, text=target.label, style="Section.TLabel").pack(anchor="w")
        hint = ttk.Label(
            panel, text=self.text("report_choose_revisions"), style="Muted.TLabel",
        )
        hint.pack(fill="x", pady=(4, 8))
        panel.bind("<Configure>", lambda event: hint.configure(
            wraplength=max(1, event.width - 32),
        ))
        viewport = ttk.Frame(panel)
        viewport.pack(fill="both", expand=True)
        viewport.columnconfigure(0, weight=1)
        viewport.rowconfigure(0, weight=1)
        tree = ttk.Treeview(
            viewport, columns=("created", "languages"),
            show="tree headings", selectmode="extended", height=REPORT_VISIBLE_ROWS,
        )
        tree.heading("#0", text=self.text("report_version"))
        tree.heading("created", text=self.text("column_created"))
        tree.heading("languages", text=self.text("report_languages"))
        for column, width in zip(("#0", "created", "languages"), (97, 140, 137), strict=True):
            tree.column(column, width=width, minwidth=40)
        tree.grid(row=0, column=0, sticky="nsew")
        scroll = ttk.Scrollbar(viewport, orient="vertical", command=tree.yview)
        scroll.grid(row=0, column=1, sticky="ns")
        tree.configure(yscrollcommand=scroll.set)
        by_id = {revision.report_id: revision for revision in revisions}
        for revision in revisions:
            details = [
                self.report_file_sort_keys.get(
                    f"pdf:{row.directory.name}:{revision.report_id}:{language}",
                ) or report_file_details(pdf)
                for language, pdf in revision.files.items()
            ]
            latest = max(details, key=lambda item: item.created_utc)
            created = datetime.fromisoformat(latest.created_utc).astimezone()
            stamp = created.strftime("%Y-%m-%d %H:%M:%S")
            tree.insert(
                "", "end", iid=revision.report_id, text="v" + str(revision.number),
                values=(stamp + (" *" if latest.estimated_time else ""),
                        ", ".join(code.upper() for code in revision.files)),
            )
        tree.selection_set(revisions[0].report_id)

        def submit() -> None:
            """Start the exact edition groups selected in this picker.

            Returns:
                None: Closes the picker and admits the selected revisions.
            """
            picked = tuple(by_id[identity] for identity in tree.selection())
            if picked:
                dialog.destroy()
                self.start_saved_upload(
                    row, picked[0], picked[1:], target=target, confirmed=True,
                )

        action = Button(
            panel, text=self.text("report_upload_selected"),
            command=submit, style="Primary.TButton",
        )
        action.pack(anchor="e", pady=(8, 0))
        tree.bind("<<TreeviewSelect>>", lambda _event: action.configure(
            state="normal" if tree.selection() else "disabled",
        ))

    def retry_saved_upload(self) -> None:
        """Retry the captured PDF revision independently of catalog selection.

        Returns:
            None: Reconfirms the original revision with the current destination
                and key; an unavailable catalog row requires refreshing history.
        """
        operation = self.operation
        if operation is None or not self.report_upload_operation():
            return
        row = self.report_rows.get(operation.plans[0].session_id)
        if row is None:
            self.show_reports()
            return
        target = getattr(operation, "watched_target", None)
        if operation.saved_revision is not None:
            self.start_saved_upload(
                row, operation.saved_revision, operation.saved_revisions[1:],
                target=target, confirmed=target is not None,
            )
        elif target is not None:
            current = self.confirm_watched_report_target(target)
            if current is not None:
                self.create_local_pdf(target=current, row=row)

    def start_saved_upload(
        self, row: SavedFlight, revision: ReportRevision,
        additional: tuple[ReportRevision, ...] = (), *,
        target: WatchedReportTarget | None = None, confirmed: bool = False,
    ) -> None:
        """Confirm a concrete retained revision before independent delivery.

        Args:
            row (SavedFlight): Captured stopped-flight catalog entry.
            revision (ReportRevision): Exact report identity and edition paths
                chosen for the initial action or retained from a failed upload.
            additional (tuple[ReportRevision, ...]): Further explicitly selected
                revision/edition subsets from the same saved flight.
            target (WatchedReportTarget | None): Destination shown before
                submission, or None to capture the current Watcher.
            confirmed (bool): The destination and selection were acknowledged in
                the picker or the retry of this same captured upload.

        Returns:
            None: Starts delivery with the running Watcher's authority after
                target confirmation; another flight remains active.
        """
        if self.busy():
            self.show_progress()
            return
        if self.load_error or self.lease is None:
            messagebox.showerror(
                self.text("saved_reports"),
                self.load_error or gettext("Flight workspace unavailable."),
                parent=self.dialog_owner(),
            )
            return
        captured = self.confirm_watched_report_target(target)
        if captured is None:
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
            self.text("confirm_saved_upload") + "\n\n" + captured.label + "\n"
            + "\n".join(pdf.name for item in (revision,) + additional for pdf in item.files.values())
        )
        if not confirmed and not messagebox.askyesno(
            self.text("upload_saved_pdf"),
            detail,
            parent=self.dialog_owner(),
        ):
            return
        try:
            languages = tuple(dict.fromkeys(code for item in (revision,) + additional for code in item.files))
            plan = replace(SessionPlan.load(row.directory), languages=languages)
            self._progress_generation += 1
            self.operation = FinalizationWorker(
                [plan], "", "", lambda _cancel, _deadline: None,
                lambda: None, False, saved_revision=revision,
                saved_revisions=(revision,) + additional,
                watched_target=captured,
                emit=lambda level, message: self.host.events.put((level, message)),
            )
            self.operation.flight_generation = self._progress_generation
            self._consume_trigger_reason("saved_report_upload")
            self.operation.trigger_reason = "saved_report_upload"
            self._last_terminal = ""
            self.operation.start()
            self.refresh_report_actions()
            self.show_progress()
        except (OSError, ValueError, RuntimeError) as exc:
            messagebox.showerror(self.text("saved_reports"), str(exc), parent=self.dialog_owner())

    def confirm_watched_report_target(
        self, expected: WatchedReportTarget | None,
    ) -> WatchedReportTarget | None:
        """Validate that an acknowledged upload still names the watched gallery.

        Args:
            expected (WatchedReportTarget | None): Previously shown target,
                or None when capturing an initial destination.
        Returns:
            WatchedReportTarget | None: Current authority for the same gallery,
                or None after explaining why the transfer cannot start.
        """
        target = self.host.watched_report_target()
        if target is not None and (expected is None or expected.binding() == target.binding()):
            return target
        messagebox.showinfo(
            self.text("saved_reports"),
            self.text("report_target_changed" if expected else "report_start_watcher"),
            parent=self.dialog_owner(),
        )
        return None

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
                    gettext("PDF unavailable; refresh the list."),
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
                gettext("Unsafe report directory."),
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
