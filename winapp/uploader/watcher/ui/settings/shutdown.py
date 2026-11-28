# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/settings/shutdown.py
# Module Type: Windows Watcher Desktop Lifecycle UI
# Purpose: Own Watcher close, restart and tray lifecycle actions.
# Responsibilities:
#   - Preserve close and restart admission around accepted background work.
#   - Keep tray and desktop navigation behavior on the existing Tk owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own Watcher shutdown, restart and tray interactions."""

from uploader.watcher import runtime
from uploader.watcher import state as watcher_state
from uploader.localization import gettext
from ..support import TRAY_TOOLTIP_MAX_LENGTH
from ..event_delivery import WATCHER_EVENT_IDLE_POLL_MS
from uploader.mode_admission import LEGACY_UPLOAD_STATES
import logging
import multiprocessing
import webbrowser

class WatcherShutdownMixin:
    """Own this focused Watcher UI lifecycle responsibility."""

    def close(self, *, for_update: bool = False, restart: bool = False) -> None:
        """Coordinate shutdown while preserving update and import state.

        Args:
            for_update (bool): Terminate after a durable checkpoint, released
                upload writers and the external helper's readiness acknowledgement.
            restart (bool): Relaunch WinApp only after a safe ordinary shutdown.
        Returns:
            None: Retains the update indicator when shutdown is deferred or
                canceled, and clears it only before confirmed app teardown.
                Confirmed teardown also stops the simulator-presence observer
                and forgets any in-memory linked identity.
                A durably force-finished daemon request may remain referenced
                through bounded process exit; its lease is not released early.
                A clean quit checkpoints an active Watcher asynchronously and
                preserves only its exact profile-bound restart consent.
                Update shutdown requires the pre-handoff checkpoint; its existing
                immediate exit happens only after accepted writers have settled.
                Records the process ID and reason before intentional teardown.
        """
        self._save_ui_layout_now()
        if for_update:
            if (getattr(self, '_update_checkpoint_ready', False) is not True
                    or any(worker is not None and worker.is_alive()
                           for worker in self._update_checkpoint_workers)):
                self.write_log(
                    'Update shutdown refused: accepted work has not checkpointed.',
                    'error',
                )
                return
            self.exiting = True
            try:
                # Children belong to this app; the standalone update helper is
                # a separate subprocess and must survive this process exit.
                for process in multiprocessing.active_children():
                    try:
                        process.terminate()
                    except (OSError, ValueError):
                        pass
            finally:
                # Exit all threads together, including optional observers and
                # non-daemon work. Do not enter Python's thread/atexit joins.
                logging.info(
                    '[watcher-process] phase=teardown; reason=update_checkpointed; pid=%d',
                    watcher_state.os.getpid(),
                )
                watcher_state.os._exit(0)
        updates = getattr(self, "updates", None)
        if updates is not None:
            # A language restart can be retried from Settings. An active
            # updater keeps its own operation and close intent undisturbed.
            if restart and (updates.busy or updates.gate.active):
                runtime.messagebox.showwarning(
                    gettext("Restart WinApp"),
                    gettext("Wait for the update operation to finish before restarting."),
                    parent=self.root,
                )
                return
            if updates.request_close():
                return
        self._prune_force_finished_workers()
        force_shutdown = any(
            worker.is_alive() and worker._queue is not None
            and worker._queue.force_finished
            for worker in self._force_finished_workers
        ) or any(operation.is_alive()
                 for operation in self._force_finished_operations)
        if self.api_key_revoke_running:
            self.flights.show_progress()
            operation = self.flights.operation
            can_interrupt = (
                not self.flights.independent_operation()
                and self.worker is not None and self.worker.is_alive()
                and (operation is None or not operation.server_request_sent)
            )
            runtime.messagebox.showwarning(
                gettext("Finishing current work"),
                gettext("Choose Interrupt in the progress panel to pause uploads.")
                if can_interrupt else gettext("Wait for the current operation to finish."),
            )
            return
        if self.exiting:
            return
        if restart and force_shutdown:
            runtime.messagebox.showwarning(
                gettext("Restart WinApp"),
                gettext("Wait for the remaining work to finish before restarting."),
                parent=self.root,
            )
            return
        active_worker = self.worker
        active_capture = bool(
            active_worker is not None and active_worker.is_alive()
            and active_worker.initial_captured
            and not active_worker.stop_event.is_set()
            and not active_worker.drain_event.is_set()
            and getattr(active_worker, 'drain_only', False) is not True
        )
        ordinary_prepared = (
            getattr(self, '_ordinary_restart_prepared', False) is True
        )
        backend_settings = getattr(self, "_gui_backend_settings", None)
        if (restart and backend_settings is not None
                and backend_settings.qt_available
                and backend_settings.requested == "pyside6"
                and (self.background_work_active(include_watcher=not active_capture)
                     or self.flights.busy()
                     or (not active_capture and self.flights.is_alive())
                     or self.watcher_reset_blocked() or self._ordinary_restart_closing
                     or self._update_resume_inspecting or self._update_resume_preparing
                     or self._resume_retry_available)):
            runtime.messagebox.showwarning(
                gettext("Restart WinApp"),
                gettext("Finish the current work in Legacy Tk before switching to Qt."),
                parent=self.root,
            )
            return
        if restart and (
            type(self).watcher_reset_blocked(self)
            or self.flights.busy()
            or any(worker is not None and worker.is_alive() for worker in (
                self.manual_worker, self.preflight_worker, self.ai_worker,
                self.flights.dispatch_worker,
            ))
            or (not ordinary_prepared and not active_capture and (
                (active_worker is not None and active_worker.is_alive())
                or self.flights.is_alive()
            ))
        ):
            runtime.messagebox.showwarning(
                gettext("Restart WinApp"),
                gettext("Wait for the remaining work to finish before restarting."),
                parent=self.root,
            )
            return
        if (not for_update and not ordinary_prepared
                and active_capture
                and (restart or self.worker.linked_target is not None
                     or self.mode_owner.view().state in LEGACY_UPLOAD_STATES)):
            if getattr(self, '_ordinary_restart_closing', False) is True:
                return
            try:
                self.prepare_update_checkpoint(runtime.APP_VERSION)
            except (OSError, RuntimeError, ValueError):
                logging.warning('[watcher-resume] stage=close; reason=ordinary_checkpoint_refused')
                runtime.messagebox.showwarning(
                    gettext("Watcher is still running"),
                    gettext(
                        "The active Watcher could not be safely prepared for restart. "
                        "Keep the app open and finish or stop Watcher first."
                    ),
                )
                return
            self._ordinary_restart_closing = True
            self.watcher_activity_var.set('Watch: finishing')
            self.status_var.set(gettext('Saving the Watcher session before closing…'))
            self.refresh_watcher_header()
            logging.info(
                '[watcher-process] phase=checkpoint; reason=ordinary_quit; pid=%d',
                watcher_state.os.getpid(),
            )
            self._ordinary_restart_thread = watcher_state.threading.Thread(
                target=self._prepare_ordinary_restart_close,
                name='PHPGalleryOrdinaryRestartCheckpoint', daemon=True,
                kwargs={"restart": restart},
            )
            try:
                self._ordinary_restart_thread.start()
            except RuntimeError:
                self._ordinary_restart_closing = False
                self._ordinary_restart_thread = None
                self.write_log(
                    '[watcher-resume] stage=close; reason=checkpoint_worker_unavailable',
                    'warning',
                )
                self.cancel_update_checkpoint()
            return
        self.flights.stop()
        self.utc_sync.stop()
        if (not ordinary_prepared
                and getattr(self, '_ordinary_restart_resuming', False) is not True):
            self._discard_update_resume_intent(explicit=True)
        self.exiting = True
        self._cancel_event_ui_callbacks()
        self._set_watcher_recording(False)
        logging.info(
            '[watcher-process] phase=shutdown_requested; pid=%d; checkpoint_saved=%s',
            watcher_state.os.getpid(), ordinary_prepared,
        )
        self.write_log("Application shutdown requested. Stopping background work safely.", "system")

        if self.preflight_worker and self.preflight_worker.is_alive():
            self.preflight_worker.stop()
        if self.worker and self.worker.is_alive():
            self.worker.stop()
        if self.manual_worker and self.manual_worker.is_alive():
            self.manual_worker.stop()
        if self.ai_worker and self.ai_worker.is_alive():
            self.ai_worker.stop()
            self.ai_worker_stop_requested = True

        deadline = watcher_state.time.monotonic() + 5.0
        for worker in (
            self.flights, self.utc_sync, self.preflight_worker, self.worker,
            self.manual_worker, self.ai_worker, self.api_key_revoke_thread,
            getattr(self, '_update_resume_context_key_worker', None),
        ):
            if (force_shutdown and (
                    worker in self._force_finished_workers
                    or worker in self._force_finished_operations)):
                continue
            if worker is None or not worker.is_alive():
                continue
            remaining = max(0.0, deadline - watcher_state.time.monotonic())
            if remaining <= 0:
                break
            worker.join(timeout=remaining)

        still_running = [
            label
            for label, worker in (
                ("flight recording", self.flights),
                ("UTC synchronization", self.utc_sync),
                ("preflight", self.preflight_worker),
                ("watcher", self.worker),
                ("import", self.manual_worker),
                ("AI", self.ai_worker),
                ("API-key revocation", self.api_key_revoke_thread),
                ("Watcher key closure", getattr(
                    self, '_update_resume_context_key_worker', None,
                )),
            )
            if not (force_shutdown and (
                    worker in self._force_finished_workers
                    or worker in self._force_finished_operations))
            if worker is not None and worker.is_alive()
        ]
        if still_running:
            message = (
                "Shutdown wait expired for " + ", ".join(still_running) +
                ". In-flight network work may still be returning, and durable import state is preserved."
            )
            self.write_log(message, "warning")
            if restart:
                self.exiting = False
                self._sync_watcher_recording()
                self._schedule_activity_drawer_refresh()
                self._schedule_event_drain(WATCHER_EVENT_IDLE_POLL_MS)
                runtime.messagebox.showwarning(
                    gettext("Restart WinApp"),
                    gettext("Wait for the remaining work to finish before restarting."),
                    parent=self.root,
                )
                return
            if (not force_shutdown and self.tray_icon is not None
                    and runtime.messagebox.askyesno(
                gettext("Background work is still stopping"),
                message + gettext("\n\nKeep the application running in the tray instead of forcing it closed?"),
            )):
                self.exiting = False
                self.hide_to_tray()
                self._sync_watcher_recording()
                self._schedule_activity_drawer_refresh()
                self._schedule_event_drain(WATCHER_EVENT_IDLE_POLL_MS)
                logging.info(
                    '[watcher-process] phase=shutdown_deferred; reason=kept_in_tray; pid=%d',
                    watcher_state.os.getpid(),
                )
                return
        writer = getattr(self, "activity_writer", None)
        if writer is not None and not writer.close(max(0.0, deadline - watcher_state.time.monotonic())):
            logging.warning("Recent activity history did not finish flushing before exit.")
        self.set_update_available(None)
        self.stop_tray_icon()
        self.simulator_presence.stop()
        if not force_shutdown:
            self.linking_manager.close()
        history_reader = getattr(self, "_import_history_reader", None)
        if history_reader is not None:
            history_reader.close()
        self._cancel_event_ui_callbacks()
        reason = ('retained_transport' if force_shutdown else
                  'shutdown_wait_expired' if still_running else 'orderly_shutdown')
        logging.info(
            '[watcher-process] phase=teardown; reason=%s; pid=%d; workers_pending=%d',
            reason, watcher_state.os.getpid(), len(still_running),
        )
        self._restart_requested = restart
        self.root.destroy()

    def _prepare_ordinary_restart_close(self, *, restart: bool = False) -> None:
        """Persist clean restart consent after the active Watcher checkpoints.

        Args:
            restart (bool): Whether the Settings selection requested relaunch.

        Returns:
            None: Queues either a safe close event or a recoverable failure.
        """
        self._update_resume_cancelled = False
        self._update_resume_generation += 1
        stage = 'ordinary_checkpoint_wait'
        try:
            self.wait_for_update_checkpoint()
            stage = 'ordinary_checkpoint_publish'
            record = self._update_continuation
            lease = self.flights.lease
            if (record is None or lease is None
                    or record.mode not in ('linked', 'legacy')):
                raise ValueError('The Watcher checkpoint is incomplete.')
            prepared = watcher_state.replace(
                record, source_version=runtime.APP_VERSION, target_version=runtime.APP_VERSION,
                update_session=watcher_state.os.urandom(16).hex(), phase='prepared',
            )
            lease.verify()
            # Recovery must retain this exact nonce even if only the first
            # atomic replacement commits before the alternate write fails.
            self._update_continuation = prepared
            prepared.save(lease)
            prepared.save(lease, ordinary_restart=True)
            self.events.put({'kind': 'ordinary_restart_ready', 'restart': restart})
        except (OSError, RuntimeError, TimeoutError, ValueError):
            logging.warning('[watcher-resume] stage=close; reason=%s', stage)
            self.events.put({'kind': 'ordinary_restart_failed'})

    def open_config_folder(self) -> None:
        """
        Open the folder containing config, state, staging, and log files.

        Returns:
            None: Creates the profile directory and opens it in the platform shell.
        """
        watcher_state.CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        self.open_local_path(watcher_state.CONFIG_DIR)

    def open_local_path(self, path: watcher_state.Path) -> None:
        """Open one local file or folder with the platform shell when possible.

        Args:
            path (Path): Local file or directory the user chose to open.

        Returns:
            None: Opens the target or reports a shell failure in Activity.
        """
        target = watcher_state.Path(path)
        try:
            if hasattr(watcher_state.os, "startfile"):
                watcher_state.os.startfile(str(target))  # type: ignore[attr-defined]
            else:
                webbrowser.open(target.resolve().as_uri())
        except Exception as exc:  # noqa: BLE001
            self.write_log(f"Could not open {target}: {exc}", "warning")

    def open_log_file(self) -> None:
        """Open the rotating uploader log, creating it when necessary.

        Returns:
            None: Creates the log when absent and opens it in the platform shell.
        """
        watcher_state.CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        if not watcher_state.LOG_PATH.exists():
            watcher_state.LOG_PATH.touch()
        self.open_local_path(watcher_state.LOG_PATH)

    def open_watched_folder(self) -> None:
        """Open the currently configured watch folder in the platform shell.

        Returns:
            None: Opens an existing folder or shows a selection warning.
        """
        folder = watcher_state.Path(self.watched_folder_var.get().strip())
        if not folder.is_dir():
            runtime.messagebox.showwarning(gettext("Watch folder"), gettext("Choose an existing folder first."))
            return
        self.open_local_path(folder)

    def open_activity_tab(self) -> None:
        """Restore the main window and show durable Activity history.

        Returns:
            None: Brings the Activity view into the foreground.
        """
        self.restore_from_tray()
        try:
            self.select_tab("activity")
        except Exception:  # noqa: BLE001
            logging.debug("Could not select Activity tab.", exc_info=True)

    def tray_tooltip_text(self) -> str:
        """Return the update version and activity within Windows' tooltip limit.

        Returns:
            str: One-line current update and activity summary capped to the
                documented notification-area character budget.
        """
        watch = 1 if self.worker and self.worker.is_alive() else 0
        importing = 1 if self.manual_worker and self.manual_worker.is_alive() else 0
        ai = 1 if self.ai_worker and self.ai_worker.is_alive() and not self.ai_worker_stop_requested else 0
        view = type(self)._selected_import_view(self)
        if view is not None and view.latest is not None:
            failures = view.latest.counts.failed
        else:
            failures = self.current_job.counts()["failed"] if self.current_job is not None else 0
        update = (
            " | " + gettext("Update {version}").format(version=self.update_available_version)
            if self.update_available_version is not None
            else ""
        )
        summary = gettext(
            "PHP Gallery uploader{update} | watch {watch} | "
            "import {importing} | AI {ai} | failures {failures}"
        ).format(
            update=update, watch=watch, importing=importing, ai=ai, failures=failures,
        )
        return summary[:TRAY_TOOLTIP_MAX_LENGTH]

    def toggle_api_key_visibility(self) -> None:
        """Reveal or remask the API key only on explicit user action.

        Returns:
            None: Updates the entry's masked state to match the visibility control.
        """
        visible = not self.api_key_visible_var.get()
        self.api_key_visible_var.set(visible)
        self.api_key_entry.configure(show="" if visible else "*")
