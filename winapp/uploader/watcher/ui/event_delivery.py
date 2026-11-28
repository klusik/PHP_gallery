# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/event_delivery.py
# Module Type: Windows Client UI behavior
# Purpose: Own the event delivery interactions for WatcherApp.
# Responsibilities: Preserve the existing Watcher UI lifecycle and callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Watcher event delivery behavior owner."""

from typing import Dict, Optional

from uploader.watcher import runtime
from uploader.watcher import state as watcher_state
from uploader.localization import gettext, gettext_noop
from ..event_policy import (
    WATCHER_EVENT_BATCH_BUDGET_MS,
    WATCHER_EVENT_BATCH_LIMIT,
    WATCHER_EVENT_CONTINUATION_MS,
    WATCHER_EVENT_IDLE_POLL_MS,
)
from .support import (
    ActivityEvent,
    WATCHER_ACTIVITY_DRAWER_ROW_LIMIT,
    WINDOW_SUMMARY_WRAP,
    _publish_watcher_text,
    _publish_watcher_value,
    activity_filter_labels,
    activity_label,
    tr,
    watch_queue_state_caption,
)
from .imports_activity import (
    IMPORT_PRESENTATION_INTERVAL_MS,
)
from uploader.diagnostics import format_watch_recovery_diagnostic
from uploader.diagnostics import redact_text
from uploader.flight_jobs import SessionPlan
from uploader.flight_jobs import WatcherContinuation
from uploader.flight_jobs import connection_binding
from uploader.flight_recorder import FlightRecorder
from uploader.flight_report_translations import recovery_text
from uploader.flight_workflow import FinalizationWorker
from uploader.gui_backend import DEFAULT_GUI_BACKEND
from uploader.import_commands import import_approval
from uploader.import_ui_snapshot import ImportProgressSnapshot
from uploader.models import ImportPlan, JsonValue
from uploader.support_report import build_support_report
from uploader.support_report import save_support_report
from uploader.ui_shell import EmbeddedWindow
from uploader.ui_theme import Button
from uploader.ui_theme import METRIC_ICON_SIZE
from uploader.ui_theme import _PALETTE
from uploader.ui_theme import create_smooth_icon
from uploader.watcher.workers.watcher import WatcherThread
from uploader.watcher.profile import WatcherProfile
from uploader.watcher.update_policy import UPDATE_CONTINUATION_LINK_SECONDS
from uploader.watcher.update_policy import UPDATE_CONTINUATION_POLL_MS
from uploader.watcher.update_policy import UPDATE_CONTINUATION_TARGET_RETRY_MS
import logging
import platform
import queue




class EventDeliveryMixin:
    """Own this cohesive WatcherApp UI behavior domain."""

    def _cancel_event_ui_callbacks(self) -> None:
        """Cancel owned event and activity callbacks before Tk shutdown.

        Returns:
            None: Cancels event delivery and the separate coalesced drawer render.
        """
        event_pump = getattr(self, "_event_pump", None)
        if event_pump is not None:
            event_pump.cancel()
        callback_id = getattr(self, "_activity_render_after_id", None)
        if callback_id is not None:
            try:
                self.root.after_cancel(callback_id)
            except runtime.tk.TclError:
                pass
            self._activity_render_after_id = None


    def refresh_activity_drawer(self) -> None:
        """Reconcile the recent events shown by the selected Activity filter.

        Returns:
            None: Cancels a pending coalesced render and updates bounded
                newest-first rows when the panel is visible.
        """
        callback_id = self._activity_render_after_id
        self._activity_render_after_id = None
        if callback_id is not None:
            try:
                self.root.after_cancel(callback_id)
            except runtime.tk.TclError:
                pass
        if not hasattr(self, "activity_drawer"):
            return
        if (
            getattr(self, "_activity_tab_selected", True) is False
            or getattr(self, "window_hidden_to_tray", False) is True
        ):
            self._activity_drawer_dirty = True
            return
        self._activity_drawer_flush_count += 1
        caption = self.activity_filter_var.get().strip().casefold()
        selected = next(
            (key for key, label in activity_filter_labels().items()
             if label.casefold() == caption),
            "all",
        )

        def visible(event: ActivityEvent) -> bool:
            """Return whether one event matches the selected Activity filter.

            Args:
                event (ActivityEvent): Persisted event under consideration.

            Returns:
                bool: True when the existing filter policy includes the event.
            """
            level = event.level.lower()
            message = event.message.lower()
            if selected == "all":
                return True
            if selected == "warnings":
                return level == "warning"
            if selected == "failed":
                return level == "error" or "failed" in message
            if selected == "succeeded":
                return (
                    level == "success" or "uploaded" in message
                    or "finished" in message or "stored" in message
                )
            if selected == "skipped":
                return "skip" in message or "duplicate" in message
            if selected == "active":
                return any(token in message for token in (
                    "started", "running", "uploading", "preflight",
                    "paused", "stopping",
                ))
            return True

        occurrences: Dict[int, int] = {}
        identified: List[Tuple[ActivityEvent, str]] = []
        for event in self.activity_events:
            identity = id(event)
            occurrence = occurrences.get(identity, 0)
            occurrences[identity] = occurrence + 1
            if visible(event):
                identified.append((event, f"event-{identity}-{occurrence}"))

        displayed_events = list(reversed(
            identified[-WATCHER_ACTIVITY_DRAWER_ROW_LIMIT:],
        ))
        rows: List[Tuple[str, Tuple[str | int | float, ...]]] = []
        for event, item_id in displayed_events:
            stamp = watcher_state.time.strftime(
                "%H:%M:%S", watcher_state.time.localtime(event.timestamp),
            )
            filename = watcher_state.Path(event.filename).name if event.filename else ""
            rows.append((
                item_id,
                (stamp, activity_label(event.level), filename, activity_label(event.operation),
                 type(self).activity_message(event)),
            ))
        self._reconcile_tree_rows(
            self.activity_drawer,
            rows,
            tuple(event for event, _item_id in displayed_events),
        )
        self._activity_drawer_dirty = False


    def _watch_diagnostic_snapshot(self) -> Dict[str, JsonValue]:
        """Read current Watcher identity, intent, scan and transport evidence.

        Returns:
            Dict[str, JsonValue]: Credential-free queue/session IDs, cutoff,
                latest scan, current producer/consumer, admitted transport,
                current head retry deadline, and separate retained failure.
        """
        worker = self.worker
        if worker is None or worker._queue is None:
            return {}
        journal = worker._queue
        with journal.lock:
            items = journal.items()
            head = journal.head()
            active = worker._active_queue_item
            transport_active = (
                worker._transport_active.is_set()
                or worker._pending_transport is not None
                and not worker._pending_transport.is_set()
            )
            return {
                'worker_id': id(worker),
                'flight_session_id': (
                    worker.drain_manifest.parent.name
                    if worker.drain_manifest is not None else ''
                ),
                'queue_session_id': str(journal.data.get('session_id', '')),
                'target_binding': journal.binding,
                'intent': (
                    'force_finished' if journal.force_finished else
                    'finish' if journal.data.get('cutoff') else 'capture'
                ),
                'cutoff': journal.data.get('cutoff', 0.0),
                'force_finished': journal.force_finished,
                'force_finished_at': journal.force_finished_at,
                'completed': journal.data.get('completed') is True,
                'pending_count': sum(
                    item.state not in ('acknowledged', 'skipped') for item in items
                ),
                'acknowledged_count': sum(
                    item.state == 'acknowledged' for item in items
                ),
                'skipped_count': sum(item.state == 'skipped' for item in items),
                'head_id': head.item_id if head is not None else '',
                'head_sequence': head.sequence if head is not None else 0,
                'head_state': head.state if head is not None else 'idle',
                'head_file': watcher_state.Path(head.source.path).name if head is not None else '',
                'last_scan': journal.last_scan_at,
                'last_scan_generation': journal.scan_generation,
                'last_progress': journal.data.get('last_change_at', 0.0),
                'last_failure': str(journal.data.get('last_failure', '')),
                'retry_at': head.next_retry_at if head is not None else 0.0,
                'producer_running': bool(
                    worker._producer_thread is not None
                    and worker._producer_thread.is_alive()
                ),
                'worker_running': worker.is_alive(),
                'dispatch_active': transport_active,
                'active_item_id': active.item_id if active is not None else '',
                'active_item_sequence': active.sequence if active is not None else 0,
                'force_finish_pending': worker._force_finish_requested.is_set(),
                'force_finish_publishing': worker._force_finish_publishing.is_set(),
                'consumer_phase': worker._diagnostic_phase,
                'reason': head.reason if head is not None else journal.data.get('error', ''),
            }


    def diagnostic_snapshot(self) -> str:
        """Collect linking, updater, runtime, Watcher and Finish observations.

        Returns:
            str: Redacted snapshot with live session, scan, dispatch, operation
                generation, trigger fields and retained recovery outcomes.
                Collection performs no filesystem reads or network requests.
        """
        view = type(self)._selected_import_view(self)
        if view is not None and view.latest is not None:
            job_summary = self._describe_import_progress(view.latest, for_display=False)
        else:
            job_summary = self.describe_job(
                self.current_job, for_display=False,
            ) if self.current_job is not None else "No selected job"
        capability_summary = ", ".join(
            f"{suffix}={'local' if capability.local_previewable else 'server-only'}"
            for suffix, capability in sorted(self.media_capabilities.items())
            if suffix in {".jpg", ".png", ".heic", ".heif", ".dng"}
        )
        recent = "\n".join(
            f"{watcher_state.time.strftime('%Y-%m-%d %H:%M:%S', watcher_state.time.localtime(event.timestamp))} [{event.level}/{event.operation}] {event.message}"
            for event in self.activity_events[-30:]
        )
        simconnect = getattr(self.worker, "simconnect_diagnostics", {"connection": "not queried", "last_result": "not requested"})
        updater_snapshot = getattr(
            getattr(self, 'updates', None), 'diagnostics_snapshot', None,
        )
        updater_diagnostics = (
            updater_snapshot() if callable(updater_snapshot)
            else 'No updater diagnostic event recorded.'
        )
        photos_snapshot = getattr(
            getattr(self, 'photos_workspace', None), 'diagnostics_snapshot', None,
        )
        photos_diagnostics = (
            photos_snapshot() if callable(photos_snapshot)
            else 'No Photos failure recorded for the current session.'
        )
        operation = self.flights.operation
        operation_progress = operation.snapshot() if operation is not None else None
        finish_diagnostics: Dict[str, JsonValue] = {
            'generation': getattr(operation, 'flight_generation', None),
            'trigger': getattr(operation, 'trigger_reason', 'none'),
            'phase': operation_progress.phase if operation_progress is not None else 'idle',
            'error': operation_progress.error if operation_progress is not None else '',
            'sessions': [plan.session_id for plan in operation.plans]
            if operation is not None else [],
            'worker_running': bool(operation is not None and operation.is_alive()),
            'automatic_retry_guard': len(self._finish_resume_attempted_sessions),
            'force_finish_failure_type': self._force_finish_failure_type,
        }
        recorder = self.flights.recorder
        flight_progress = (
            recorder.diagnostics_snapshot() if recorder is not None
            else {"running": False, "sample_count": 0}
        )
        connection = getattr(self, "_connection_diagnostic", {})
        connection_status = connection.get("status", "")
        connection_detail = connection.get("detail", "")
        requested_backend = getattr(
            getattr(self, "_gui_backend_settings", None), "requested", DEFAULT_GUI_BACKEND,
        )
        raw = "\n".join(
            [
                f"App: {watcher_state.APP_DISPLAY_NAME}",
                f"App version: {runtime.APP_VERSION}",
                f"Python: {runtime.sys.version.split()[0]}",
                f"Runtime: {'installed' if getattr(runtime.sys, 'frozen', False) else 'source'}",
                f"Executable: {watcher_state.Path(runtime.sys.executable).name}",
                f"Tk: {self.root.tk.call('info', 'patchlevel')}",
                f"GUI backend: effective=tkinter requested={requested_backend}",
                "Pillow: " + (
                    str(getattr(runtime.Image, '__version__', 'installed'))
                    if runtime.Image is not None else 'unavailable'
                ),
                f"OS: {watcher_state.os.name}",
                f"Gallery URL: {self.gallery_url_var.get().strip()}",
                f"API key configured: {bool(self.api_key_var.get().strip())}",
                f"Connection: {connection_status} | {connection_detail}",
                "Browser linking: " + (
                    self.linking_manager.diagnostics_snapshot()
                    or "No linking outcome recorded yet."
                ),
                "Updater diagnostics:\n" + updater_diagnostics,
                "Photos diagnostics:\n" + photos_diagnostics,
                f"Update continuation: {self._update_resume_status}",
                "Watcher recovery: latest="
                + (getattr(self, '_watch_recovery_latest', '') or 'none')
                + " | last_failure="
                + (getattr(self, '_watch_recovery_last_failure', '') or 'none'),
                "Watcher factory reset: "
                + (getattr(self, '_watcher_reset_latest', '') or 'none'),
                f"Watcher: {self.watcher_activity_var.get()}",
                'Upload queue: ' + watcher_state.json.dumps(
                    self._watch_diagnostic_snapshot(), ensure_ascii=False,
                ),
                'Finish operation: ' + watcher_state.json.dumps(
                    finish_diagnostics, ensure_ascii=False,
                ),
                f"UTC synchronization: {self.utc_sync.status()}",
                f"Import: {self.import_activity_var.get()} | {job_summary}",
                f"AI: {self.ai_activity_var.get()}",
                f"Media capabilities: {capability_summary}",
                "Event-loop observations: "
                f"last_batch_events={self._event_drain_last_count} "
                f"total_events={self._event_drain_total_count} "
                f"budget_yields={self._event_drain_yield_count} "
                f"last_batch_ms={self._event_drain_last_ms:.2f} "
                f"max_batch_ms={self._event_drain_max_ms:.2f} "
                f"activity_drawer_flushes={self._activity_drawer_flush_count}",
                f"Config path: {watcher_state.CONFIG_PATH}",
                f"State path: {watcher_state.STATE_PATH}",
                f"Log path: {watcher_state.LOG_PATH}",
                "SimConnect (last acquisition; connections close after each request):",
                watcher_state.json.dumps(simconnect, indent=2, ensure_ascii=False),
                "Flight recorder (independent aircraft telemetry):",
                watcher_state.json.dumps(flight_progress, indent=2, ensure_ascii=False),
                "Recent activity:",
                recent,
            ]
        )
        return redact_text(raw, [self.api_key_var.get().strip()], redact_paths=True)


    def support_report(self) -> str:
        """Build the local support report from current owners and a bounded log.

        Returns:
            str: Privacy-filtered report with runtime, settings and log status.
        """
        config = self.config
        settings = {
            key: value for key, value in config.to_dict().items()
            if type(value) in (str, int, float, bool) or value is None
        }
        return build_support_report(
            type(self).diagnostic_snapshot(self), settings,
            {
                "app_version": runtime.APP_VERSION,
                "python_version": runtime.sys.version.split()[0],
                "platform": platform.system() + " " + platform.release(),
                "machine": platform.machine(),
                "runtime": "installed" if getattr(runtime.sys, "frozen", False) else "source",
                "tk": str(self.root.tk.call("info", "patchlevel")),
                "pillow": str(getattr(runtime.Image, "__version__", "unavailable")),
            }, watcher_state.LOG_PATH, secrets=(self.api_key_var.get().strip(),),
        )


    def copy_diagnostics(self) -> None:
        """Copy the complete redacted support report after explicit selection.

        Returns:
            None: Replaces the clipboard and records the local copy action.
        """
        safe = type(self).support_report(self)
        self.root.clipboard_clear()
        self.root.clipboard_append(safe)
        self.write_log("Redacted diagnostic details copied to clipboard.", "system")


    def open_support_report(self) -> None:
        """Present a reviewable support report in the main application window.

        Returns:
            None: Opens a read-only preview with refresh, copy and local save.
        """
        existing = getattr(self, "_support_panel", None)
        if existing is not None and existing.winfo_exists():
            existing.deiconify()
            return
        panel = EmbeddedWindow(self.root)
        panel.title(gettext("Support diagnostics"))
        self._support_panel = panel
        panel.columnconfigure(0, weight=1)
        panel.rowconfigure(2, weight=1)
        description = runtime.ttk.Label(
            panel,
            text=gettext(
                "Review this report before sharing. Credentials, local paths "
                "and URL query values are filtered. Files and photographs are not "
                "attached. Nothing is sent automatically."
            ),
            wraplength=WINDOW_SUMMARY_WRAP, padding=(16, 12),
        )
        description.grid(row=0, column=0, sticky="ew")
        actions = runtime.ttk.Frame(panel, padding=(16, 0, 16, 10))
        actions.grid(row=1, column=0, sticky="ew")
        body = runtime.ttk.Frame(panel, padding=(16, 0, 16, 0))
        body.grid(row=2, column=0, sticky="nsew")
        body.columnconfigure(0, weight=1)
        body.rowconfigure(0, weight=1)
        preview = runtime.tk.Text(
            body, wrap="word", font="TkFixedFont", relief="flat",
            background=_PALETTE["surface"], foreground=_PALETTE["ink"],
            selectbackground=_PALETTE["primary_pressed"], padx=12, pady=10,
        )
        scrollbar = runtime.ttk.Scrollbar(body, orient="vertical", command=preview.yview)
        preview.configure(yscrollcommand=scrollbar.set)
        preview.grid(row=0, column=0, sticky="nsew")
        scrollbar.grid(row=0, column=1, sticky="ns")
        status = runtime.tk.StringVar(master=panel)
        runtime.ttk.Label(panel, textvariable=status, padding=(16, 10)).grid(
            row=3, column=0, sticky="ew",
        )
        report = ""

        def refresh() -> None:
            """Replace the preview with a fresh passive diagnostic snapshot.

            Returns:
                None: Updates report text and collection status without HTTP.
            """
            nonlocal report
            try:
                report = self.support_report()
            except (OSError, ValueError, RuntimeError, runtime.tk.TclError) as exc:
                status.set(gettext("Report collection failed: ") + type(exc).__name__)
                return
            preview.configure(state="normal")
            preview.delete("1.0", "end")
            preview.insert("1.0", report)
            preview.configure(state="disabled")
            status.set(gettext("Report ready · {report_count:,} characters · collected locally").format(
                report_count=len(report),
            ))

        def copy_report() -> None:
            """Copy exactly the report currently displayed for review.

            Returns:
                None: Replaces clipboard contents only after a report exists.
            """
            if report:
                self.root.clipboard_clear()
                self.root.clipboard_append(report)
                status.set(gettext("Displayed report copied to clipboard."))

        def save_report() -> None:
            """Save the displayed report to an explicitly selected destination.

            Returns:
                None: Saves UTF-8 text or retains the preview on cancel/failure.
            """
            if not report:
                return
            selected = runtime.filedialog.asksaveasfilename(
                parent=panel, title=gettext("Save support report"), defaultextension=".txt",
                initialdir=str(watcher_state.CONFIG_DIR),
                initialfile="PHP-Gallery-support-" + watcher_state.time.strftime("%Y%m%d-%H%M%S") + ".txt",
                filetypes=((gettext("Text files"), "*.txt"), (gettext("All files"), "*.*")),
            )
            if not selected:
                return
            try:
                save_support_report(watcher_state.Path(selected), report)
            except OSError as exc:
                status.set(gettext("Could not save report: ") + type(exc).__name__)
            else:
                status.set(gettext("Displayed report saved locally. Nothing was sent."))

        Button(actions, text=gettext("Refresh report"), command=refresh).pack(side="left")
        Button(actions, text=gettext("Copy report"), command=copy_report).pack(side="left", padx=8)
        Button(actions, text=gettext("Save report…"), command=save_report, style="Primary.TButton").pack(side="left")
        Button(actions, text=gettext("Close"), command=panel.destroy).pack(side="right")
        panel.grab_set()
        preview.focus_set()
        refresh()


    def refresh_watcher_header(self) -> None:
        """Show compact Watcher state, queue size and last scan artwork.

        Returns:
            None: Publishes changed icon, queue, time and tooltip values to
                the retained header groups.
        """
        status_icon = getattr(self, "_watcher_header_status_icon", None)
        count_var = getattr(self, "watcher_header_count_var", None)
        time_var = getattr(self, "watcher_header_time_var", None)
        if status_icon is None or count_var is None or time_var is None:
            return
        language = self.flights.ui_language.get()
        gallery_name = " ".join(self.gallery_title_var.get().split())
        queued = (
            str(self._watcher_queued_count)
            if self._watcher_queued_count is not None
            else "–"
        )
        state = self.watcher_activity_var.get().removeprefix("Watch: ")
        if getattr(self, "_watch_start_pending", False) is True:
            state = "starting"
        activity_key = {
            "running": "running", "starting": "starting",
            "resuming": "starting", "finishing": "finishing",
            "uploads pending": "pending", "stopped": "stopped",
        }.get(state, "stopped")
        activity = tr(language, "watcher_header_" + activity_key)
        worker = getattr(self, "worker", None)
        worker_active = bool(
            worker is not None and worker.is_alive()
            and not worker.stop_event.is_set()
            and not worker.drain_only and not worker.drain_event.is_set()
            and not worker._update_pause_requested()
        )
        preparing = activity_key in {"starting", "finishing", "pending"}
        if preparing:
            tone = "warning"
            hollow = True
            pulse = False
        elif worker_active:
            tone = "ink"
            hollow = False
            pulse = bool(getattr(self, "_watcher_recording_pulse", False))
        else:
            tone = "muted"
            hollow = True
            pulse = False
        cache_key = f"{tone}:{int(hollow)}:{int(pulse)}"
        icon_cache = getattr(self, "_watcher_header_icon_cache", None)
        icon = None
        if isinstance(icon_cache, dict):
            icon = icon_cache.get(cache_key)
            if icon is None:
                icon = create_smooth_icon(
                    status_icon,
                    "recording",
                    METRIC_ICON_SIZE,
                    tone=tone,
                    hollow=hollow,
                    pulse=pulse,
                )
                if icon is not None:
                    icon_cache[cache_key] = icon
        if icon is not None:
            _publish_watcher_value(
                self, "watch_header_artwork", status_icon, (cache_key, str(icon)),
                lambda: status_icon.configure(image=icon),
            )
            self._watcher_header_status_image = icon
        else:
            # The theme owns permanent optional-Pillow support for source-mode
            # repair. Native glyphs retain status meaning in that environment;
            # normal installed/source runs use the supersampled artwork above.
            foreground = _PALETTE["recording" if tone == "ink" else tone]
            glyph = "◌" if hollow else "●"
            _publish_watcher_value(
                self, "watch_header_artwork", status_icon, (glyph, foreground),
                lambda: status_icon.configure(image="", text=glyph, foreground=foreground),
            )
        _publish_watcher_text(self, "watcher_header_count_var", queued)
        scanned_at = getattr(self, "_watcher_last_scan_at", None)
        scan_clock = (
            watcher_state.time.strftime("%H:%M", watcher_state.time.localtime(scanned_at))
            if scanned_at is not None else "–:–"
        )
        _publish_watcher_text(self, "watcher_header_time_var", scan_clock)
        stop_button = getattr(self, "_watcher_header_stop_button", None)
        if stop_button is not None:
            show_stop = worker_active and not preparing
            if show_stop:
                _publish_watcher_value(
                    self, "watch_header_stop_enabled", stop_button, True,
                    lambda: stop_button.configure(state="normal"),
                )
            _publish_watcher_value(
                self, "watch_header_stop_visible", stop_button, show_stop,
                (lambda: stop_button.pack(side="left", padx=(8, 0)))
                if show_stop else stop_button.pack_forget,
            )
        stop_hint = getattr(self, "_watcher_header_stop_hint", None)
        if stop_hint is not None:
            _publish_watcher_text(
                self, "_watcher_header_stop_hint", tr(language, "watcher_header_stop"),
            )
        hint = getattr(self, "_watcher_header_hint", None)
        if hint is not None:
            queue_caption = tr(language, "watcher_header_queue")
            scan_caption = tr(language, "watcher_header_last_scan")
            missing_gallery = tr(
                language, "watcher_header_gallery_unavailable",
            )
            photos_caption = tr(language, "photos_pane_title")
            _publish_watcher_text(
                self, "_watcher_header_hint",
                f"{gallery_name or missing_gallery} · {activity}\n"
                f"{queue_caption}: {queued} {photos_caption}\n"
                f"{scan_caption}: {scan_clock}"
            )


    def refresh_activity_monitor_state(self) -> None:
        """
        Refresh independent watcher, import, AI, connection, and tray indicators.

        Returns:
            None: Synchronizes worker captions, indicators and the tray title
                with the current worker and session state.
        """
        watcher_running = bool(self.worker and self.worker.is_alive())
        if self.worker is not getattr(self, "_watcher_queue_worker", None):
            self._watcher_queued_count = None
            self._watcher_last_scan_at = None
            self._watcher_queue_worker = self.worker
        self.refresh_watcher_action()
        manual_running = bool(self.manual_worker and self.manual_worker.is_alive())
        ai_running = bool(self.ai_worker and self.ai_worker.is_alive() and not self.ai_worker_stop_requested)
        preflight_running = bool(self.preflight_worker and self.preflight_worker.is_alive())

        state = self.flights.session_state()
        if state == "working" and not self.flights.independent_operation():
            watcher_caption = "Watch: finishing"
        elif watcher_running:
            watcher_caption = "Watch: running"
        elif state == "pending":
            watcher_caption = "Watch: uploads pending"
        else:
            watcher_caption = "Watch: stopped"
        # Lifecycle and recovery handlers also publish these three variables.
        # Read their current captions so a poll cannot hide a newer transition.
        if self.watcher_activity_var.get() != watcher_caption:
            self.watcher_activity_var.set(watcher_caption)
        self.refresh_watcher_header()
        import_caption = self.import_activity_var.get()
        if preflight_running:
            next_import_caption = "Import: preflight"
        elif manual_running:
            next_import_caption = (
                "Import: paused" if self.manual_worker and self.manual_worker.pause_event.is_set()
                else "Import: running"
            )
        else:
            next_import_caption = (
                import_caption if import_caption.startswith("Import: failed") else "Import: idle"
            )
        if import_caption != next_import_caption:
            self.import_activity_var.set(next_import_caption)
        if ai_running:
            ai_caption = "AI: running"
        elif self.ai_worker_stop_requested and self.ai_worker and self.ai_worker.is_alive():
            ai_caption = "AI: stopping"
        else:
            ai_caption = "AI: stopped"
        if self.ai_activity_var.get() != ai_caption:
            self.ai_activity_var.set(ai_caption)

        active = watcher_running or manual_running or ai_running or preflight_running
        self.update_monitor_state("green" if active else "disabled", gettext("Background activity is running.") if active else gettext("No background worker is active."))
        if self.tray_icon is not None:
            try:
                title = self.tray_tooltip_text()
                if self.tray_icon.title != title:
                    self.tray_icon.title = title
            except Exception:  # noqa: BLE001
                logging.debug("Could not refresh tray tooltip.", exc_info=True)


    def _schedule_event_drain(self, delay_ms: int) -> None:
        """Schedule the single owned event-pump callback when active.

        Args:
            delay_ms (int): Delay before the next event batch, in milliseconds.

        Returns:
            None: Delegates the cadence to the composed Tk event pump.
        """
        if self.exiting or self._drain_running:
            return
        self._event_pump.schedule(delay_ms, self.drain_events)


    def drain_events(self) -> None:
        """
        Run one cooperative batch of worker events and schedule its continuation.

        Returns:
            None: Preserves FIFO handling, publishes the latest batch footer
                and schedules one later batch.
        """
        if self.exiting or self._drain_running:
            return
        self._event_pump.cancel()
        self._drain_running = True
        budget_exhausted = False
        batch_started = watcher_state.time.perf_counter()
        try:
            budget_exhausted = self._drain_event_batch()
            if not self.exiting:
                if hasattr(self, "_import_presentation_after"):
                    self._receive_import_history()
                    self._flush_import_presentation()
                self._flush_activity_footer()
                type(self)._flush_visible_log(self)
                self.refresh_contextual_watcher_copy()
                self.refresh_activity_monitor_state()
                self.refresh_revoke_button_state()
        finally:
            elapsed_ms = (watcher_state.time.perf_counter() - batch_started) * 1000.0
            self._event_drain_last_ms = elapsed_ms
            self._event_drain_max_ms = max(
                self._event_drain_max_ms, elapsed_ms,
            )
            if budget_exhausted:
                self._event_drain_yield_count += 1
            self._drain_running = False
            if not self.exiting:
                delay_ms = (
                    WATCHER_EVENT_CONTINUATION_MS
                    if budget_exhausted else WATCHER_EVENT_IDLE_POLL_MS
                )
                reader = getattr(self, "_import_history_reader", None)
                worker = getattr(self, "manual_worker", None)
                if ((reader is not None and reader.has_work())
                        or (worker is not None and worker.is_alive())):
                    delay_ms = min(delay_ms, IMPORT_PRESENTATION_INTERVAL_MS)
                self._schedule_event_drain(delay_ms)


    def _drain_event_batch(self) -> bool:
        """Apply complete queued events until the cooperative batch budget ends.

        Returns:
            bool: True when the event count or time budget ended the batch;
                False when the queue was observed empty or shutdown began
                between complete events.
        """
        batch_started = watcher_state.time.perf_counter()
        processed_events = 0
        self._event_drain_last_count = 0
        while True:
            if self.exiting:
                return False
            elapsed_ms = (watcher_state.time.perf_counter() - batch_started) * 1000.0
            if (processed_events >= WATCHER_EVENT_BATCH_LIMIT
                    or elapsed_ms >= WATCHER_EVENT_BATCH_BUDGET_MS):
                return True
            try:
                event = self.events.get_nowait()
            except queue.Empty:
                break
            processed_events += 1
            self._event_drain_last_count += 1
            self._event_drain_total_count += 1

            if isinstance(event, dict):
                kind = str(event.get("kind", "") or "")
                payload = event.get("payload", {}) if isinstance(event.get("payload", {}), dict) else {}
                if kind == 'watch_folder_inspection_complete':
                    language = self.flights.ui_language.get()
                    error_message = str(payload.get('error', '') or '')
                    if error_message:
                        runtime.messagebox.showwarning(
                            recovery_text(language, 'watch_inspection_title'),
                            recovery_text(
                                language, 'watch_inspection_failed',
                            ).format(reason=recovery_text(
                                language, 'watch_inspection_failure_reason',
                            )),
                        )
                    else:
                        runtime.messagebox.showinfo(
                            recovery_text(language, 'watch_inspection_title'),
                            recovery_text(language, 'watch_inspection_result').format(
                                folder=payload.get('folder', ''),
                                supported=int(payload.get('supported', 0)),
                                unsupported=int(payload.get('unsupported', 0)),
                            ),
                        )
                    continue
                if kind == 'watch_stop_retained':
                    if payload.get('worker') is not self.worker:
                        continue
                    callback = payload.get('on_persisted')
                    if callable(callback):
                        callback(not bool(payload.get('error')))
                    if payload.get('error'):
                        self._force_finish_failure_type = str(
                            payload.get('error_type', 'PersistenceError'),
                        )
                        self.status_var.set(str(payload['error']))
                        self.write_log(
                            str(payload['error']) + ' error_type='
                            + self._force_finish_failure_type,
                            'error',
                        )
                    else:
                        pending = payload['worker']._pending_transport
                        if pending is not None and not pending.is_set():
                            self.status_var.set(
                                recovery_text(
                                    self.flights.ui_language.get(),
                                    'watch_stop_transport_active',
                                ),
                            )
                        else:
                            self.status_var.set(
                                recovery_text(
                                    self.flights.ui_language.get(),
                                    'watch_finish_retained',
                                ),
                            )
                            self.write_log(
                                'Watcher stopped; queue retained for recovery.', 'warning',
                            )
                    self.refresh_watcher_action()
                    continue
                if kind == 'watch_force_finished':
                    worker = payload.get('worker')
                    workers = payload.get('workers', [])
                    operation = payload.get('operation')
                    callbacks = self._force_finish_callbacks.pop(
                        id(operation), [],
                    ) if operation is not None else []
                    current_operation = self.flights.operation is operation
                    if not current_operation:
                        try:
                            self.write_log(
                                'Force Finish completed for a stale operation generation; '
                                'the newer UI state was left untouched.', 'warning',
                            )
                        except Exception:  # noqa: BLE001
                            pass
                        continue
                    if payload.get('error'):
                        try:
                            self.status_var.set(str(payload['error']))
                            self.write_log(str(payload['error']), 'error')
                        finally:
                            for callback in callbacks:
                                try:
                                    callback('failed')
                                except Exception:  # noqa: BLE001
                                    pass
                        continue
                    for owned_worker in workers:
                        if owned_worker.is_alive():
                            self._force_finished_workers.add(owned_worker)
                    if operation.is_alive():
                        self._force_finished_operations.add(operation)
                    self._force_finish_failure_type = ''
                    abandoned_sessions = set(payload.get('plan_sessions', []))
                    current_plan = getattr(self.flights, 'plan', None)
                    if (current_plan is not None
                            and current_plan.session_id in abandoned_sessions):
                        recorder = self.flights.recorder
                        if (recorder is not None
                                and recorder.directory == current_plan.directory):
                            try:
                                recorder.stop()
                            except Exception:  # noqa: BLE001
                                pass
                            self.flights.recorder = None
                        self.flights.plan = None
                    operation.cancel.set()
                    self.api_key_revoke_running = False
                    if payload.get('pending_transport') is True:
                        result = 'pending_transport'
                        log_message = (
                            'Force Finish was saved; the admitted HTTP request '
                            'still owns its transport lease and result remains retained.'
                        )
                    else:
                        result = 'persisted'
                        log_message = (
                            'Force Finish was saved; pending source files and upload '
                            'history remain retained.'
                        )
                    try:
                        self.write_log(log_message, 'warning')
                    except Exception:  # noqa: BLE001
                        pass
                    try:
                        for owned_worker in workers:
                            owned_worker.emit_queue_snapshot()
                    except Exception:  # noqa: BLE001
                        pass
                    for callback in callbacks:
                        try:
                            callback(result)
                        except Exception:  # noqa: BLE001
                            try:
                                self.write_log(
                                    'Force Finish UI notification failed after durable publication.',
                                    'error',
                                )
                            except Exception:  # noqa: BLE001
                                pass
                    try:
                        self.refresh_watcher_action()
                    except Exception:  # noqa: BLE001
                        pass
                    continue
                if (kind.startswith('watcher_resume_')
                        and 'generation' in payload
                        and payload.get('generation') != getattr(
                            self, '_update_resume_generation', 0)):
                    if kind == 'watcher_resume_prepared':
                        worker = payload.get('worker')
                        if (isinstance(worker, WatcherThread)
                                and worker._linked_lease is not None):
                            worker._linked_lease.release()
                        target = payload.get('target')
                        if (isinstance(target, watcher_state.LinkedUploadTarget)
                                and target is getattr(
                                    self, '_update_resume_context_target', None,
                                )):
                            self._close_update_resume_context_key()
                    continue
                if kind == 'watcher_factory_reset':
                    profile = payload.get('profile')
                    self._complete_watcher_reset(
                        profile if isinstance(profile, WatcherProfile) else None,
                        payload['diagnostic'],
                    )
                    continue
                if kind == 'gui_mode_initialized':
                    self._gui_mode_ready = payload.get('verified') is True
                    if self._gui_mode_ready:
                        self._inspect_update_continuation()
                    else:
                        self._update_resume_status = 'stage=resume; reason=mode_unverified'
                    continue
                if kind == 'ordinary_restart_ready':
                    if type(self).watcher_reset_blocked(self):
                        self._ordinary_restart_closing = False
                        self._ordinary_restart_thread = None
                        continue
                    self._ordinary_restart_prepared = True
                    self._ordinary_restart_closing = False
                    if event.get('restart') is True:
                        self.close(restart=True)
                    else:
                        self.close()
                    continue
                if kind == 'ordinary_restart_failed':
                    self._ordinary_restart_closing = False
                    self._ordinary_restart_thread = None
                    if type(self).watcher_reset_blocked(self):
                        continue
                    self._cancel_ordinary_restart_intent()
                    self.cancel_update_checkpoint()
                    runtime.messagebox.showwarning(
                        gettext('Watcher remains open'),
                        gettext(
                            "The active Watcher could not be safely checkpointed. "
                            "Its queue is retained; wait for recovery, then stop or retry."
                        ),
                    )
                    continue
                if kind == 'watcher_resume_available':
                    self._update_resume_inspecting = False
                    if (self.exiting or getattr(
                            self, '_update_resume_cancelled', False)):
                        continue
                    record = payload.get('continuation')
                    if isinstance(record, WatcherContinuation):
                        self._resume_continuation = record
                        self._ordinary_restart_resuming = (
                            payload.get('ordinary_restart') is True
                        )
                        self._resume_retry_available = True
                        self._update_resume_deadline = (
                            watcher_state.time.monotonic() + UPDATE_CONTINUATION_LINK_SECONDS
                        )
                        self._update_resume_status = 'stage=resume; reason=verifying_saved_target'
                        if payload.get('automatic') is True:
                            self._continue_update_resume()
                        else:
                            # Old versions never persisted whether a pending
                            # session was active at update time. Offer explicit
                            # continuation without inventing startup consent.
                            self._update_resume_status = 'stage=resume; reason=legacy_confirmation_required'
                            self.status_var.set(
                                gettext(
                                    "An older version left unfinished Watcher work. "
                                    "Resume Watcher continues the original session."
                                ),
                            )
                            self.refresh_watcher_action()
                    continue
                if kind == 'watcher_resume_inspection_no_offer':
                    self._update_resume_inspecting = False
                    continue
                if kind == 'watcher_resume_failed':
                    self._update_resume_inspecting = False
                    if (self.exiting or getattr(
                            self, '_update_resume_cancelled', False)):
                        continue
                    self._update_resume_failed(
                        str(payload.get('reason', 'continuation_unverified')),
                        stage=str(payload.get('stage', 'resume')),
                    )
                    continue
                if kind == 'watcher_resume_inspection_retry':
                    self._update_resume_inspecting = False
                    if (self.exiting or getattr(
                            self, '_update_resume_cancelled', False)):
                        continue
                    if watcher_state.time.monotonic() >= self._update_resume_deadline:
                        self._update_resume_failed(
                            'checkpoint_read_unavailable',
                            stage=str(payload.get('stage', 'checkpoint_read')),
                        )
                        continue
                    self._update_resume_attempted = False
                    self._update_resume_status = (
                        'stage=' + str(payload.get('stage', 'checkpoint_read'))
                        + '; reason=temporary_checkpoint_read_failure'
                    )
                    self.root.after(
                        UPDATE_CONTINUATION_POLL_MS,
                        self._inspect_update_continuation,
                    )
                    continue
                if kind == 'watcher_resume_retry':
                    if (self._resume_continuation is None or self.exiting
                            or getattr(self, '_update_resume_cancelled', False)
                            or not self._update_resume_preparing):
                        continue
                    self._update_resume_preparing = False
                    if watcher_state.time.monotonic() >= self._update_resume_deadline:
                        self._update_resume_failed(
                            'saved_gallery_access_unavailable',
                            stage='target_verification',
                        )
                        continue
                    self._update_resume_status = (
                        'stage=target_verification; '
                        'reason=temporary_target_verification_failure'
                    )
                    self.status_var.set(
                        gettext(
                            "The original gallery is temporarily unavailable. "
                            "Retrying safely…"
                        ),
                    )
                    self.root.after(
                        UPDATE_CONTINUATION_TARGET_RETRY_MS,
                        self._continue_update_resume,
                    )
                    continue
                if kind == 'watcher_resume_target':
                    if (self.exiting or getattr(
                            self, '_update_resume_cancelled', False)):
                        continue
                    target = payload.get('target')
                    if isinstance(target, watcher_state.LinkedUploadTarget):
                        self._accept_update_resume_target(target)
                    continue
                if kind == 'watcher_resume_prepared':
                    record, worker, recorder, target = (
                        payload.get('continuation'), payload.get('worker'),
                        payload.get('recorder'), payload.get('target'),
                    )
                    if (isinstance(record, WatcherContinuation)
                            and isinstance(worker, WatcherThread)
                            and isinstance(recorder, FlightRecorder)
                            and (target is None or isinstance(target, watcher_state.LinkedUploadTarget))):
                        self._start_prepared_update_resume(record, worker, recorder, target)
                    continue
                if kind in ('watch_finish_resume_ready', 'watch_finish_resume_failed'):
                    self._finish_resume_inspecting = False
                    if payload.get('generation') != self._finish_resume_generation:
                        continue
                    if kind == 'watch_finish_resume_failed':
                        self.write_log(
                            'Pending Finish discovery failed; no automatic retry was started.',
                            'warning',
                        )
                        continue
                    sessions = payload.get('sessions')
                    if (isinstance(sessions, list) and len(sessions) == 1
                            and isinstance(sessions[0], str)
                            and sessions[0] not in self._finish_resume_attempted_sessions
                            and payload.get('unambiguous') is True
                            and self.flights.binding() == payload.get('binding')
                            and not self.flights.busy()
                            and not (self.worker is not None and self.worker.is_alive())):
                        session_id = sessions[0]
                        self._finish_resume_attempted_sessions.add(session_id)
                        self._next_finish_trigger_reason = 'startup_resume'
                        self.write_log(
                            f'Resuming original Finish once: session={session_id}; '
                            'trigger=startup_resume.', 'info',
                        )
                        # Disk capture consent never invents legacy Revoke consent.
                        self.flights.begin(False, automatic=True)
                    continue
                if kind == 'watch_queue_recovery_failed':
                    self._queue_recovery_pending = False
                    self.write_log('Queue refresh blocked: original state or ownership needs review.', 'warning')
                    if self._queue_recovery_finish_requested:
                        self._queue_recovery_finish_requested = False
                        self.status_var.set(
                            gettext(
                                "Finish was not started because queue recovery failed. "
                                "Review the retained session and retry explicitly."
                            ),
                        )
                    continue
                if kind == 'watch_queue_recovered':
                    self._queue_recovery_pending = False
                    replacement = payload['worker']
                    if (self.worker is not payload['original'] or self.exiting
                            or type(self).watcher_reset_blocked(self)):
                        if replacement._linked_lease is not None:
                            replacement._linked_lease.release()
                        continue
                    self.worker = replacement
                    replacement.start()
                    self.write_log('Queue refresh resumed the same session; FIFO retained.', 'info')
                    if self._queue_recovery_finish_requested:
                        self._queue_recovery_finish_requested = False
                        self._next_finish_trigger_reason = 'queue_recovered_finish'
                        self.flights.begin(False, automatic=True,
                                           photos_only=(self.worker.linked_target is not None
                                                       and not self.contextual_watcher_is_flight()))
                    self.refresh_watcher_action()
                    continue
                if kind in ("watcher_start_ready", "watcher_start_finished"):
                    finished_worker = payload.get('worker')
                    if (kind == 'watcher_start_finished'
                            and finished_worker in self._force_finished_workers):
                        self._retire_force_finished_worker(finished_worker)
                    if payload.get("worker") is not self.worker:
                        continue
                    if kind == "watcher_start_ready":
                        self._set_watch_start_activity(False)
                        self.watch_ignored_var.set(
                            gettext("Ignored at startup: {value}").format(value=payload['ignored'])
                        )
                        if (self.worker.is_alive()
                                and not self.worker.stop_event.is_set()
                                and not self.worker.drain_event.is_set()):
                            if not self._complete_update_resume():
                                continue
                            self.status_var.set(gettext("Running"))
                            self.watcher_activity_var.set("Watch: running")
                            self._set_watcher_recording(True, self.worker.linked_target)
                            self.write_log("Watcher started.", "success")
                    elif (getattr(self, '_resume_continuation', None) is not None
                            and getattr(self, '_update_resume_preparing', False)):
                        reason = (
                            'saved_session_needs_review'
                            if self.worker.drain_error
                            else 'continuation_worker_stopped'
                        )
                        self._update_resume_failed(reason)
                    elif (self.worker.drain_error
                            or not self.worker.initial_captured
                            or getattr(self, "_watch_start_pending", False) is True):
                        self._set_watch_start_activity(
                            False, self.worker.drain_error
                            or "Watcher stopped before capture began.",
                        )
                    elif (kind == 'watcher_start_finished'
                          and self.worker._queue is not None
                          and self.worker._queue.force_finished):
                        self.status_var.set(recovery_text(
                            self.flights.ui_language.get(), 'force_finish_saved',
                        ))
                    elif (kind == 'watcher_start_finished'
                          and self.worker._queue is not None
                          and self.worker._queue.data.get('retained_stop') is True):
                        self.status_var.set(
                            recovery_text(
                                self.flights.ui_language.get(),
                                'watch_finish_retained',
                            ),
                        )
                    self.refresh_watcher_action()
                    continue
                if 'preflight_owner' in payload:
                    worker = self.preflight_worker
                    if worker is None or payload['preflight_owner'] is not worker._preflight_owner:
                        continue
                    if (payload.get('config_generation') is None
                            or payload['config_generation'] is not self.config._snapshot_token
                            or (not payload.get('linked_binding') and (
                                watcher_state.normalize_upload_url(self.gallery_url_var.get())
                                != watcher_state.normalize_upload_url(self.config.gallery_url)
                                or self.api_key_var.get().strip() != self.config.api_key.strip()
                            ))):
                        if kind in ('preflight_complete', 'preflight_failed', 'preflight_cancelled'):
                            self.preflight_progress.stop()
                            self.preflight_progress.grid_remove()
                            self.preflight_worker = None
                            self._preflight_approved = None
                            self.manual_status_var.set(gettext('Preflight needs to be run again'))
                            self.manual_preflight_var.set(
                                gettext('Configuration changed; run preflight again. Sources were retained.'),
                            )
                        continue
                if ('linked_binding' in payload and payload['linked_binding']
                        != self.linking_manager.linked_upload_binding()):
                    continue
                job_id = str(event.get("job_id", payload.get("job_id", "")) or "")
                if kind == "preflight_started":
                    self.manual_status_var.set(gettext("Preflight running"))
                    self.import_activity_var.set("Import: preflight")
                elif kind == "preflight_progress":
                    current = int(payload.get("current", 0) or 0)
                    total = max(1, int(payload.get("total", 1) or 1))
                    filename = str(payload.get("filename", "") or "")
                    self.manual_preflight_var.set(gettext("Inspecting {current}/{total}: {filename}").format(
                        current=current,
                        total=total,
                        filename=filename,
                    ))
                elif kind == "preflight_complete":
                    plan = payload.get("plan")
                    self.preflight_progress.stop()
                    self.preflight_progress.grid_remove()
                    self.preflight_worker = None
                    if isinstance(plan, ImportPlan):
                        binding = payload.get('preflight_binding')
                        try:
                            self._preflight_approved = import_approval(
                                plan.job.id, payload['config_generation'],
                                binding if isinstance(binding, str) else '',
                            )
                        except ValueError:
                            self._preflight_approved = None
                            self.manual_status_var.set(gettext('Preflight needs to be run again'))
                            continue
                        self.manual_plan = plan
                        self.current_job = plan.job
                        self._import_detail_request = None
                        self.render_preflight_plan(plan)
                        self.refresh_job_history()
                        self.write_log("Import preflight completed. Review the summary before starting.", "success")
                        self.record_activity(
                            "success", gettext_noop(
                                "Import preflight completed and is awaiting explicit Start import."
                            ),
                            "import", job_id=plan.job.id,
                        )
                    self.manual_status_var.set(gettext("Ready for review"))
                elif kind == "preflight_failed":
                    self.preflight_progress.stop()
                    self.preflight_progress.grid_remove()
                    self.preflight_worker = None
                    message = str(payload.get("message", "Preflight failed") or "Preflight failed")
                    self.manual_preflight_var.set(gettext("Preflight failed: ") + gettext(message))
                    self.manual_status_var.set(gettext("Preflight failed"))
                    self.import_activity_var.set("Import: failed preflight")
                    self.write_log("Import preflight failed: " + message, "error")
                    self.record_activity(
                        "error", gettext_noop("Import preflight failed: {detail}"),
                        "import", job_id=job_id, fields={"detail": message},
                    )
                elif kind == "preflight_cancelled":
                    self.preflight_progress.stop()
                    self.preflight_progress.grid_remove()
                    self.preflight_worker = None
                    self.manual_status_var.set(gettext("Preflight cancelled"))
                    self.import_activity_var.set("Import: idle")
                elif kind in ("watch_scan", "watch_queue"):
                    if payload.get('worker') is self.worker and kind == 'watch_queue':
                        self._watch_queue_snapshot = {
                            key: value for key, value in payload.items() if key != 'worker'
                        }
                        state = str(payload.get('state', 'idle'))
                        count = int(payload.get('queued_count', self._watcher_queued_count or 0))
                        filename = str(payload.get('head_file', ''))
                        retry_at = float(payload.get('retry_at', 0) or 0)
                        acknowledgements = int(payload.get('acknowledged_count', 0) or 0)
                        skipped = int(payload.get('skipped_count', 0) or 0)
                        progress_at = float(payload.get('last_progress', 0) or 0)
                        self.watch_queue_status_var.set(
                            f'{count} · #{payload.get("head_sequence", 0)} {filename} · '
                            + watch_queue_state_caption(state)
                            + (' · ' + watcher_state.time.strftime('%H:%M:%S', watcher_state.time.localtime(retry_at))
                               if retry_at else '')
                            + ' · ' + gettext('{confirmed} confirmed / {skipped} skipped').format(
                                confirmed=acknowledgements, skipped=skipped,
                            )
                            + (gettext(' · progress ') + watcher_state.time.strftime('%H:%M:%S', watcher_state.time.localtime(progress_at))
                               if progress_at else '')
                            + (' · ' + gettext('See Support diagnostics for details.')
                               if payload.get('reason') else '')
                        )
                        if payload.get('last_scan'):
                            self._watcher_last_scan_at = float(payload['last_scan'])
                        workspace = getattr(self, 'photos_workspace', None)
                        if workspace is not None:
                            workspace.set_upload_pipeline_waiting(
                                count > 0
                                or state in ('retry_wait', 'uncertain', 'auth_required', 'blocked')
                                or bool(payload.get('reason')),
                            )
                    if payload.get("worker") is self.worker:
                        if self._watcher_queue_worker is not self.worker:
                            self._watcher_queued_count = None
                            self._watcher_last_scan_at = None
                        self._watcher_queue_worker = self.worker
                        count = payload.get("queued_count")
                        if type(count) is int and count >= 0:
                            self._watcher_queued_count = count
                            self.refresh_activity_monitor_state()
                    if kind == "watch_scan":
                        if payload.get("worker") is self.worker:
                            timestamp = float(payload.get("timestamp", watcher_state.time.time()) or watcher_state.time.time())
                            self._watcher_last_scan_at = timestamp
                            self.watch_last_scan_var.set(gettext("Last successful scan: ") + watcher_state.time.strftime("%Y-%m-%d %H:%M:%S", watcher_state.time.localtime(timestamp)))
                            self.refresh_watcher_header()
                elif kind in {"connection_ready", "connection_warning", "connection_error"}:
                    explicit = bool(payload.get("explicit_test", False))
                    if explicit:
                        self.connection_test_running = False
                    generation = payload.get('config_generation')
                    if (generation is None
                            or generation is not self.config._snapshot_token
                            or (not payload.get('linked_binding') and (
                                self.linking_manager.linked_upload_mode_selected()
                                or self.linking_manager.selected_upload_target()
                                is not None
                                or watcher_state.normalize_upload_url(
                                    self.gallery_url_var.get(),
                                )
                                != watcher_state.normalize_upload_url(self.config.gallery_url)
                                or self.api_key_var.get().strip() != self.config.api_key.strip()
                            ))):
                        if explicit:
                            self.refresh_connection_controls()
                        continue
                    if kind == "connection_ready":
                        label = str(payload.get("gallery_label", "gallery") or "gallery")
                        resolved_url = str(payload.get("normalized_url", "") or "")
                        self.gallery_label_var.set(gettext("Target gallery: ") + label)
                        self.gallery_title_var.set(
                            str(payload.get("gallery_title", "") or "").strip()
                        )
                        type(self)._publish_connection_text(self, "status", gettext_noop("Connected"))
                        type(self)._publish_connection_text(
                            self, "detail",
                            gettext_noop("Authenticated endpoint available: {endpoint}"),
                            endpoint=resolved_url,
                        )
                        if explicit:
                            saved_url = self.config.gallery_url.strip()
                            saved_key = self.config.api_key.strip()
                            current_url = self.gallery_url_var.get().strip()
                            current_key = self.api_key_var.get().strip()
                            if (
                                resolved_url
                                and resolved_url != saved_url
                                and current_url == saved_url
                                and current_key == saved_key
                                and not getattr(self, "api_key_revoke_running", False)
                            ):
                                resolved_config = watcher_state.replace(self.config, gallery_url=resolved_url)
                                try:
                                    resolved_config = self._save_owned_config(resolved_config)
                                    self.config = resolved_config
                                    self.gallery_url_var.set(resolved_url)
                                    self.write_log("Working Gallery API route detected and saved: " + resolved_url, "system")
                                except Exception as exc:  # noqa: BLE001
                                    self.write_log("Connection succeeded, but saving the working API route failed: " + str(exc), "warning")
                            self.write_log("Connection test succeeded for gallery " + label + ".", "success")
                            self.record_activity(
                                "success", gettext_noop(
                                    "Connection test succeeded for gallery {gallery}."
                                ),
                                "system", fields={"gallery": label},
                            )
                        if (not payload.get('linked_binding')
                                and not self.api_key_revoke_running):
                            saved_url = watcher_state.normalize_upload_url(
                                self.config.gallery_url,
                            )
                            saved_key = self.config.api_key.strip()
                            if (saved_url and saved_key
                                    and watcher_state.normalize_upload_url(
                                        self.gallery_url_var.get(),
                                    ) == saved_url
                                    and self.api_key_var.get().strip() == saved_key):
                                self._verified_legacy_gallery = (
                                    self.config._snapshot_token,
                                    connection_binding(saved_url, saved_key),
                                    label,
                                    str(payload.get('gallery_title', '') or '').strip(),
                                )
                            else:
                                self._verified_legacy_gallery = None
                    elif kind == "connection_warning":
                        type(self)._publish_connection_text(
                            self, "status", gettext_noop("Connection warning"),
                        )
                        type(self)._publish_connection_text(
                            self, "detail", str(payload.get("message") or gettext_noop(
                                "Remote preflight unavailable",
                            )),
                        )
                    else:
                        message = str(payload.get("message", "Connection failed") or "Connection failed")
                        auth_error = any(token in message.lower() for token in ("api key", "invalid or revoked", "unauthorized", "forbidden", "http 401", "http 403"))
                        type(self)._publish_connection_text(
                            self, "status", gettext_noop("Authentication error")
                            if auth_error else gettext_noop("Connection failed"),
                        )
                        type(self)._publish_connection_text(self, "detail", message)
                        self.write_log("Connection test failed: " + message, "error")
                        self.record_activity(
                            "error", gettext_noop("Connection test failed: {detail}"),
                            "system", fields={"detail": message},
                        )
                elif kind == "flight_abandoned":
                    self.flights.forgotten(str(payload.get("binding", "")))
                elif kind == "photos_watch_key_closed":
                    if payload.get("target") is self._contextual_watch_target:
                        self._watcher_key_closing = False
                        if payload.get("closed") is True:
                            target = self._contextual_watch_target
                            self._contextual_watch_target = None
                            self._contextual_watch_is_flight = False
                            self.linking_manager.detach_photos_watcher_target(target)
                        else:
                            self.status_var.set(gettext("Watcher access could not be closed. Retry."))
                        self.refresh_watcher_action()
                elif kind == 'update_resume_context_key_closed':
                    target = payload.get('target')
                    if isinstance(target, watcher_state.LinkedUploadTarget):
                        self._finish_update_resume_context_key_close(
                            target, payload.get('closed') is True,
                        )
                elif kind == "saved_linked_mode_ready":
                    self.linked_target_changed()
                    self.write_log(format_watch_recovery_diagnostic(
                        'startup', 'saved_linked_mode_waiting_for_live_target',
                    ), 'info')
                elif kind == 'capture_target_restored':
                    target = payload.get('target')
                    restored = False
                    generation = getattr(self, '_update_resume_generation', 0)
                    try:
                        if (isinstance(target, watcher_state.LinkedUploadTarget)
                                and payload.get('generation', generation) == generation
                                and type(self)._watcher_connection_selected(self)
                                and self.mode_owner.view().record == payload.get('record')
                                and target.binding() == payload.get('binding')):
                            restored = self.linking_manager.activate_verified_resume_target(
                                target, target.binding(), payload['scope'],
                                verify_committed=lambda: (
                                    self.mode_owner.view().record == payload.get('record')
                                ),
                            )
                    except (OSError, ValueError, RuntimeError):
                        restored = False
                    diagnostic = payload.get('diagnostic')
                    if not diagnostic:
                        diagnostic = format_watch_recovery_diagnostic(
                            'target',
                            'original_gallery_restored' if restored else 'verified_result_no_longer_current',
                        )
                    self.write_log(diagnostic, 'info' if restored else 'warning')
                    callback = payload.get('on_result')
                    if callable(callback):
                        callback(restored)
                elif kind == 'retained_watch_closed':
                    plan = payload.get('plan')
                    error_type = payload.get('error_type')
                    persisted = isinstance(plan, SessionPlan) and error_type == ''
                    if persisted:
                        worker = self.worker
                        if (worker is not None and not worker.is_alive()
                                and worker.drain_manifest is not None
                                and worker.drain_manifest.parent == plan.directory):
                            self.worker = None
                        current_plan = self.flights.plan
                        if (current_plan is not None
                                and current_plan.directory == plan.directory):
                            self.flights.plan = None
                            self.flights.recorder = None
                        self.write_log(format_watch_recovery_diagnostic(
                            'local_close', 'retained_capture_closed',
                        ), 'info')
                    else:
                        diagnostic = payload.get('diagnostic')
                        self.write_log(diagnostic or format_watch_recovery_diagnostic(
                            'local_close', 'publication_unconfirmed',
                        ), 'warning')
                    callback = payload.get('on_result')
                    if callable(callback):
                        callback(persisted)
                    if persisted:
                        state = self.flights.session_state()
                        self.watcher_activity_var.set(
                            'Watch: uploads pending' if state in ('pending', 'blocked')
                            else 'Watch: stopped',
                        )
                    self.refresh_connection_controls()
                    self.refresh_watcher_action()
                elif kind == "linking_resume_startup":
                    payload = event.get("payload", {})
                    issuer = payload.get("issuer") if isinstance(payload, dict) else None
                    if isinstance(issuer, str) and issuer:
                        self.linking_manager.resume_saved_link(issuer)
                    else:
                        self.linking_manager.resume_recent_links()
                elif kind == "api_key_revoked":
                    operation = payload.get('operation')
                    if isinstance(operation, FinalizationWorker):
                        self.complete_legacy_revocation(operation)
                elif kind == "api_key_revoke_error":
                    self.api_key_revoke_running = False
                    self.api_key_revoke_thread = None
                    message = str(payload.get("message", "API-key revocation failed.") or "API-key revocation failed.")
                    type(self)._publish_connection_text(self, "status", gettext_noop("Revocation failed"))
                    type(self)._publish_connection_text(self, "detail", message)
                    self.write_log("API-key revocation failed: " + message, "error")
                    self.record_activity(
                        "error", gettext_noop("API-key revocation failed: {detail}"),
                        "system", fields={"detail": message},
                    )
                    self.refresh_connection_controls()
                    runtime.messagebox.showerror(gettext("Revoke API key failed"), message)
                elif kind == "job_progress":
                    snapshot = payload.get("snapshot")
                    if isinstance(snapshot, ImportProgressSnapshot) and job_id == snapshot.history.job_id:
                        self._accept_import_progress(snapshot)
                    # A saved presentation revision has no connection side
                    # effects. Refresh shared controls once at the drain boundary.
                    continue
                elif kind in ("job_control", "job_completed"):
                    worker = self.manual_worker
                    if (worker is None or worker.import_job_id != job_id
                            or worker.import_generation != payload.get("generation")):
                        continue
                    if kind == "job_completed":
                        self._finish_import_presentation(
                            job_id, cancelled=bool(payload.get("cancelled", False)),
                            needs_review=bool(payload.get("needs_review", False)),
                        )
                        continue
                    self._flush_import_presentation(force=True)
                    paused = bool(payload.get("paused", False))
                    self.manual_status_var.set(gettext("Import paused") if paused else gettext("Import running"))
                    self.import_activity_var.set("Import: paused" if paused else "Import: running")
                    self.pause_import_button.configure(state="disabled" if paused else "normal")
                    self.resume_import_button.configure(state="normal" if paused else "disabled")
                elif kind == "thumbnail_fallback":
                    filename = str(payload.get("filename", "") or "")
                    reason = str(payload.get("reason", "") or "")
                    message = f"Local thumbnail fallback for {filename}: {reason}. Server generation remains authoritative."
                    self.write_log(message, "warning")
                    self.record_activity("warning", message, "import", filename=filename, job_id=job_id)
                self.refresh_revoke_button_state()
                self.refresh_activity_monitor_state()
                continue

            if not isinstance(event, tuple) or len(event) != 2:
                self.write_log("Ignored malformed worker event.", "warning")
                continue
            level, message = str(event[0]), str(event[1])
            log_level = self.classify_log_level(level, message)
            operation = "system"
            watch_recovery = message.startswith('[watcher-recovery] ')
            if watch_recovery:
                operation = 'watcher'
            elif message.startswith("[simconnect]"):
                operation = "simconnect"
            elif message.startswith(("Manual ", "Uploading ", "Uploaded ", "Skipped already present", "Generated ", "Local thumbnails")):
                operation = "import" if (self.manual_worker and self.manual_worker.is_alive()) or message.startswith("Manual ") else "watcher"
            elif message.startswith(("Watching ", "Watcher ", "Ignoring ", "Flight Simulator")):
                operation = "watcher"
            elif message.startswith("AI metadata"):
                operation = "ai"
            # Classify the original queue text before removing its routing marker.
            display_message = message[len("[simconnect]"):].lstrip() if operation == "simconnect" else message
            self.write_log(
                display_message if watch_recovery else f"{level.upper()}: {display_message}",
                log_level,
            )
            self.record_activity(log_level if log_level in {"success", "warning", "error"} else level, message, operation, job_id=self.current_job.id if operation == "import" and self.current_job else "")

            if message.startswith("Manual upload started"):
                self.manual_status_var.set(gettext("Import running"))
                self.import_activity_var.set("Import: running")
            elif message.startswith("Watcher stopped"):
                self.status_var.set(gettext("Stopped"))
                self.watcher_activity_var.set("Watch: stopped")
            elif message.startswith("Watching "):
                self.status_var.set(gettext("Running"))
                self.watcher_activity_var.set("Watch: running")
                self.watch_last_scan_var.set(gettext("Last scan: ") + watcher_state.time.strftime("%Y-%m-%d %H:%M:%S"))
            elif message.startswith("AI metadata worker claimed job"):
                self.ai_status_var.set(gettext("AI metadata worker is processing a job"))
                self.ai_activity_var.set("AI: working")
            elif message.startswith("AI metadata stored"):
                self.ai_status_var.set(gettext("AI metadata worker running"))
                self.ai_activity_var.set("AI: running")
            elif message.startswith("AI metadata worker stopped"):
                self.ai_worker_stop_requested = False
                self.ai_status_var.set(gettext("AI metadata worker stopped"))
                self.ai_activity_var.set("AI: stopped")
            elif message.startswith("AI metadata worker started"):
                self.ai_worker_stop_requested = False
                self.ai_status_var.set(gettext("AI metadata worker running"))
                self.ai_activity_var.set("AI: running")
            if message.startswith("Optional local AI module dependency installation finished"):
                self.semantic_ai_install_running = False
                if self.semantic_ai_install_button is not None:
                    self.semantic_ai_install_button.configure(state="normal")

        return False


    def _retire_force_finished_worker(self, worker: WatcherThread) -> None:
        """Retain a force-finished worker reference until its thread exits.

        Args:
            worker (WatcherThread): Detached worker whose transport lease may
                still be waiting for its real request owner.

        Returns:
            None: Removes the App reference only after actual thread termination.
        """
        if worker.is_alive():
            try:
                self.root.after(
                    WATCHER_EVENT_IDLE_POLL_MS,
                    lambda: self._retire_force_finished_worker(worker),
                )
            except runtime.tk.TclError:
                pass
            return
        self._force_finished_workers.discard(worker)


    def _prune_force_finished_workers(self) -> None:
        """Drop detached workers only after their actual thread has exited.

        Returns:
            None: Keeps every live transport owner strongly referenced.
        """
        self._force_finished_workers = {
            worker for worker in self._force_finished_workers if worker.is_alive()
        }
        self._force_finished_operations = {
            operation for operation in self._force_finished_operations
            if operation.is_alive()
        }


    def configure_log_tags(self) -> None:
        """Configure readable semantic log colors on the dark diagnostics panel.

        Returns:
            None: Registers each level's body and prefix styles once.
        """
        if self.log_tags_ready:
            return
        self.log_text.tag_configure("timestamp", foreground="#AABCCB")
        self.log_text.tag_configure("system", foreground="#D2DFE9")
        self.log_text.tag_configure("success", foreground="#46D68C")
        self.log_text.tag_configure("warning", foreground="#F2C581")
        self.log_text.tag_configure("error", foreground="#FF91A2")
        self.log_text.tag_configure("debug", foreground="#95A9BA")
        # Keep compact bold prefixes in the same installed family as the UI.
        family = str(self.root.tk.call(
            "font", "actual", "TkDefaultFont", "-family",
        ))
        prefix_font = (family, 9, "bold")
        self.log_text.tag_configure(
            "prefix_system", foreground="#D2DFE9", font=prefix_font,
        )
        self.log_text.tag_configure(
            "prefix_success", foreground="#46D68C", font=prefix_font,
        )
        self.log_text.tag_configure(
            "prefix_warning", foreground="#F2C581", font=prefix_font,
        )
        self.log_text.tag_configure(
            "prefix_error", foreground="#FF91A2", font=prefix_font,
        )
        self.log_text.tag_configure(
            "prefix_debug", foreground="#95A9BA", font=prefix_font,
        )
        self.log_tags_ready = True


    def update_monitor_state(self, state: str, detail: str) -> None:
        """
        Update the small monitoring light and its text labels.

        Args:
            state (str): One of disabled, green, or red.
            detail (str): Short human-readable explanation.

        Returns:
            None: Synchronizes changed labels and retains the monitor oval on
                its current Canvas.
        """
        state = state if state in {"disabled", "green", "red"} else "red"
        self.monitor_state = state
        self.monitor_detail = detail
        palette = {
            "disabled": ("#c0c0c0", "#8a8a8a", "Monitoring disabled"),
            "green": ("#4caf50", "#2e7d32", "Monitoring active"),
            "red": ("#ef5350", "#b71c1c", "Monitoring error"),
        }
        fill, outline, label = palette[state]
        _publish_watcher_text(self, "monitor_state_var", label)
        _publish_watcher_text(self, "monitor_detail_var", detail)
        canvas = self.monitor_light
        previous = getattr(self, "_watcher_monitor_oval", None)
        if previous is None or previous[0] is not canvas:
            item = canvas.create_oval(2, 2, 14, 14, fill=fill, outline=outline, width=1)
            self._watcher_monitor_oval = (canvas, item, state)
        elif previous[2] != state:
            canvas.itemconfigure(previous[1], fill=fill, outline=outline)
            self._watcher_monitor_oval = (canvas, previous[1], state)


    def classify_log_level(self, level: str, message: str) -> str:
        """
        Convert watcher events into readable log colors.

        Args:
            level (str): Original worker severity.
            message (str): Event text.

        Returns:
            str: Tk text tag name.
        """
        lower = message.lower()
        if level == "error":
            return "error"
        if level == "warning":
            return "warning"
        if any(lower.startswith(prefix) for prefix in ["watching ", "upload endpoint:", "watcher started", "configuration saved", "manual upload started", "manual upload worker started", "manual upload finished", "ai metadata worker started", "ai metadata stored"]):
            return "success"
        if lower.startswith("uploaded ") or lower.startswith("skipped duplicate content") or lower.startswith("generated "):
            return "success"
        if lower.startswith("manual upload stopped") or lower.startswith("watcher stopped") or lower.startswith("ai metadata worker stopped"):
            return "system"
        return "system"


    def write_log(self, message: str, tag: str = "system") -> None:
        """
        Append one secret-safe record to the durable log and Activity tail.

        Args:
            message (str): Message text to append.
            tag (str): Log color tag name.

        Returns:
            None: Retains the latest Watcher recovery outcome and failure
                alongside the bounded visible log.
        """
        secret = self.api_key_var.get().strip() if hasattr(self, "api_key_var") else ""
        safe_message = redact_text(message, [secret], redact_paths=False)
        if safe_message.startswith('[watcher-recovery] '):
            self._watch_recovery_latest = safe_message
            if tag in ('warning', 'error'):
                self._watch_recovery_last_failure = safe_message
        logging.info("UI: %s", redact_text(safe_message, [secret], redact_paths=False))
        if not hasattr(self, "log_text"):
            return
        self._pending_log_lines.append((watcher_state.time.strftime("%H:%M:%S"), safe_message, tag))
        if not self._drain_running:
            self._flush_visible_log()


    def _flush_visible_log(self) -> None:
        """Append the retained diagnostic tail in one visible Tk operation.

        Returns:
            None: Applies the bounded recent lines in FIFO order on Activity.
                The rotating log still receives every record immediately.
        """
        pending = getattr(self, "_pending_log_lines", None)
        if (not pending or not getattr(self, "_activity_tab_selected", False)
                or getattr(self, "window_hidden_to_tray", False)):
            return
        text = self.log_text
        tags = frozenset(text.tag_names())
        fragments: list[str | tuple[str, ...]] = []
        line_count = self._displayed_log_lines
        for stamp, message, tag in pending:
            fragments.extend((
                f"[{stamp}] ", ("timestamp",),
                message + "\n", (tag if tag in tags else "system",),
            ))
            line_count += message.count("\n") + 1
        text.configure(state="normal")
        try:
            text.insert("end", *fragments)
            # The inline tail shares the existing clamped Activity history
            # limit. Open log file remains the source for earlier lines.
            overflow = max(0, line_count - self.job_store.history_limit)
            if overflow:
                text.delete("1.0", f"1.0 + {overflow} lines")
            self._displayed_log_lines = line_count - overflow
            pending.clear()
            text.see("end")
        finally:
            text.configure(state="disabled")


    def run(self) -> None:
        """
        Run the Tkinter event loop.

        Returns:
            None: Runs Tk's event loop until the window closes.
        """
        self.root.mainloop()
