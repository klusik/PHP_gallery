# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/ui/progress.py
# Module Type: Flight UI progress behavior
# Purpose: Own the progress operations of the existing FlightUI controller.
# Responsibilities: Preserve Tk callback and lifecycle behavior in a cohesive owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own progress callbacks for the FlightUI controller."""

import sys
import threading
import time
from math import ceil
from pathlib import Path
import tkinter as tk
from tkinter import ttk
from uploader.ui_shell import messagebox
from uploader.diagnostics import format_watch_recovery_diagnostic

from uploader.flight_jobs import connection_binding, read_document
from uploader.flight_report_translations import recovery_text, tr
from uploader.localization import current_language
from uploader.flight_workflow import FinalizationWorker
from uploader.watcher.queue import WatchRecoveryError
from uploader.ui_theme import Button
from uploader.overlay import OverlayPanel, layout_actions

from .lifecycle import LifecycleMixin


from .policy import (
    CAPTURE_RECOVERY_RETRY_SECONDS,
    DRAIN_POLL_SECONDS,
    PROGRESS_ACTION_COLUMNS,
    PROGRESS_TASK_MIN_WIDTHS,
    PROGRESS_TASK_ROWS,
    PROGRESS_TASK_WIDTHS,
    PROGRESS_TICK_MS,
    SUCCESS_AUTOCLOSE_SECONDS,
)


class ProgressMixin:
    """Provide progress callbacks to the established FlightUI state owner."""

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
        if LifecycleMixin._watcher_reset_blocked(self):
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
            attention_is_uncertain = False
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
                                detail += ' · ' + recovery_text(
                                    current_language(), "watch_retry_at",
                                ).format(
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
                                attention_is_uncertain = head.get('state') == 'uncertain'
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
                    heading = (
                        recovery_text(current_language(), "watch_needs_attention")
                        if attention_is_uncertain
                        else tr(current_language(), "phase_error")
                    )
                    self.operation.update(
                        "error", heading + ": " + attention_detail,
                    )
                    raise RuntimeError(heading + ": " + attention_detail)
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
        saved_upload = self.report_upload_operation()
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
            ("cancel_pdf", self.cancel_local_pdf),
            ("cancel_upload", self.cancel_local_pdf),
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
        # A rounded monotonic deadline can appear fractionally beyond the
        # full interval. Keep the displayed countdown within that interval.
        remaining = min(
            SUCCESS_AUTOCLOSE_SECONDS,
            max(0, ceil(self._autoclose_deadline - now)),
        )
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
                    choices.append(
                        "cancel_upload" if operation.saved_upload or (
                            self.report_upload_operation() and operation.saved_revision is not None
                        ) else "cancel_pdf"
                    )
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
        if (LifecycleMixin._watcher_reset_blocked(self)
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
        if (LifecycleMixin._watcher_reset_blocked(self)
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
        if self.report_upload_operation() and operation.saved_revisions:
            # Create-and-upload completes analysis and its initial render set
            # before the independent destination/edition delivery counters.
            offset = 1 + len(operation.plans[0].languages) if operation.local_reports else 0
            delivered = progress.done - offset
            editions = [(revision, language, pdf)
                        for revision in operation.saved_revisions
                        for language, pdf in revision.files.items()]
            for index, (revision, language, pdf) in enumerate(editions):
                state = self.text("phase_completed" if delivered >= index + 2 else "report_state_local")
                if delivered == index + 1 and progress.phase == "uploading":
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
        if LifecycleMixin._watcher_reset_blocked(self):
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
        self.refresh_report_actions()
        if operation is not None:
            progress = operation.snapshot()
            terminal = progress.phase in {"completed", "error", "cancelled"}
            now = progress.phase_started if terminal else time.monotonic()
            unknown_result_heading = recovery_text(
                current_language(), "watch_needs_attention",
            )
            needs_attention = (
                progress.phase == "error"
                and unknown_result_heading in progress.detail
            )
            self.phase_var.set(
                unknown_result_heading if needs_attention else self.text(
                    "report_cancelled" if self.independent_operation()
                    and progress.phase == "cancelled" else "phase_" + progress.phase
                ),
            )
            detail = progress.detail or progress.error
            if (progress.phase == "error" and operation.local_reports is True
                    and self.report_upload_operation() and operation.saved_revision is not None):
                detail = self.text("report_upload_local_recovery").format(detail=detail)
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
                    requirements = Path(__file__).resolve().parents[3] / "requirements-core.txt"
                    user_install = " --user" if sys.prefix == sys.base_prefix else ""
                    detail += f'\n"{executable}" -m pip install{user_install} -r "{requirements}"'
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
