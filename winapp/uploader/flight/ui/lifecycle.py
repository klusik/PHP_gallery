# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/ui/lifecycle.py
# Module Type: Flight UI lifecycle behavior
# Purpose: Own the lifecycle operations of the existing FlightUI controller.
# Responsibilities: Preserve Tk callback and lifecycle behavior in a cohesive owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own lifecycle callbacks for the FlightUI controller."""

import queue
import re
import threading
import time
from dataclasses import replace
from pathlib import Path
from uploader.ui_shell import messagebox
from uploader.diagnostics import format_watch_recovery_diagnostic
from uploader.watcher.capture_session import prepare_capture_recording

from uploader.flight_jobs import FlightPreferences, SessionPlan, connection_binding, pending_sessions
from uploader.flight_recorder import FlightRecorder
from uploader.localization import gettext
from uploader.watcher.queue import WatchQueueStore
from uploader.flight_simbrief import load_dispatch

from .ports import RetainedWatchCapture, SessionState

from .policy import (
    CAPTURE_RECOVERY_RETRY_SECONDS,
)


class LifecycleMixin:
    """Provide lifecycle callbacks to the established FlightUI state owner."""

    def save_preferences(self) -> bool:
        """Persist feature choices and immediately gate active acquisition.

        Returns:
            bool: True after saving and pausing/resuming the recorder; False
                after rejected changes restore the previously saved controls.
        """
        if LifecycleMixin._watcher_reset_blocked(self):
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
                ui_language=self.preferences.ui_language,
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
                self.capture_text("flight_progress"),
                gettext(str(exc)),
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
        if LifecycleMixin._watcher_reset_blocked(self):
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
        if (LifecycleMixin._watcher_reset_blocked(self) or capture is None or self.busy()
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
        if (LifecycleMixin._watcher_reset_blocked(self)
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
        if (LifecycleMixin._watcher_reset_blocked(self)
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
        self.recorder, self.plan = prepare_capture_recording(
            self.directory, self.preferences,
            target_binding if target_binding is not None else self.binding(),
            gallery_identity, self.host.config.simconnect_dll_path,
            lambda level, message: self.host.events.put((level, message)),
            dispatch=self.dispatch, photos_only=photos_only, factory=FlightRecorder,
        )
        directory = self.plan.directory
        self.active_dispatch = self.dispatch
        self.active_dispatch_directory = directory
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

    def report_upload_operation(self) -> bool:
        """Identify an independent operation with an explicit upload step.

        Returns:
            bool: True for saved revision delivery or create-and-upload.
        """
        operation = self.operation
        return operation is not None and (
            operation.saved_upload is True
            or getattr(operation, "watched_target", None) is not None
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
        if LifecycleMixin._watcher_reset_blocked(self):
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
