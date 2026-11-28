# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/ui/dispatch.py
# Module Type: Flight UI dispatch behavior
# Purpose: Own the dispatch operations of the existing FlightUI controller.
# Responsibilities: Preserve Tk callback and lifecycle behavior in a cohesive owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own dispatch callbacks for the FlightUI controller."""

import queue
import sqlite3
import threading
from pathlib import Path
from typing import Literal
import tkinter as tk
from tkinter import ttk
from uploader import ui_file_picker as filedialog
from uploader.ui_shell import EmbeddedWindow, active_panel, messagebox

from uploader.flight_jobs import REPORT_LANGUAGES, read_document
from uploader.flight_report_translations import tr
from uploader.localization import DEFAULT_LANGUAGE
from uploader.state_store import atomic_write_json
from uploader.flight_simbrief import DispatchPlan, fetch_dispatch, identity_query, import_dispatch
from uploader.flight_navdata import remember_plan
from uploader.ui_theme import Button, GlassCard, icon_button, place_dialog
from uploader.simconnect_flight import (
    METRES_PER_FOOT,
    METRES_PER_SECOND_PER_FPM,
    METRES_PER_SECOND_PER_KNOT,
)

from .policy import (
    SIMBRIEF_IDENTITY_COLUMNS,
    SIMBRIEF_PROMPT_WRAP_PX,
)


class DispatchMixin:
    """Provide dispatch callbacks to the established FlightUI state owner."""

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
        self.host.events.put(("info", tr(DEFAULT_LANGUAGE, "simbrief_loading")))
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
            self.host.events.put(("info", tr(DEFAULT_LANGUAGE, status) + "\n"
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
            self.host.events.put(("info", tr(DEFAULT_LANGUAGE, "simbrief_loaded")))
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
        """Open the contextual sheet for automatic reports and PDF editions.

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
        actions = ttk.Frame(panel, style="Card.TFrame")
        actions.grid(row=4, column=0, columnspan=2, sticky="w", pady=(20, 0))
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
        ).grid(row=5, column=0, columnspan=2, sticky="ew", pady=(12, 0))
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
