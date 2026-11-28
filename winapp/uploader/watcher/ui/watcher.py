# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/watcher.py
# Module Type: Windows Client UI behavior
# Purpose: Own the watcher interactions for WatcherApp.
# Responsibilities: Preserve the existing Watcher UI lifecycle and callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Watcher watcher behavior owner."""

from uploader.watcher import runtime
from uploader.watcher import state as watcher_state
from uploader.localization import gettext, gettext_noop
from .support import (
    WATCH_QUEUE_STATUS_WRAP_PIXELS,
    WINDOW_SUMMARY_WRAP,
    _publish_watcher_text,
    _publish_watcher_value,
    parse_worker_choice,
    tr,
    utc_status_caption,
)
from .event_delivery import (
    WATCHER_EVENT_IDLE_POLL_MS,
)
from uploader.ai.dependencies import install_semantic_ai_dependencies_for_current_runtime
from uploader.ai.worker import create_ai_worker
from uploader.watcher.capture_session import persist_retained_capture_closure
from uploader.config import BYTES_PER_GIB
from uploader.config import ai_backend_labels
from uploader.diagnostics import format_watch_recovery_diagnostic
from uploader.discovery import discover_folder
from uploader.flight_jobs import FlightPreferences
from uploader.flight_jobs import SessionPlan
from uploader.flight_jobs import WatcherContinuation
from uploader.flight_jobs import WorkspaceLease
from uploader.flight_jobs import read_document
from uploader.flight_report_translations import recovery_text
from uploader.linked_upload import UploadPipelineLease
from uploader.mode_admission import LEGACY_UPLOAD_STATES
from uploader.models import RECOVERABLE_ITEM_STATES
from uploader.ui_shell import help_button
from uploader.ui_theme import Button
from uploader.ui_theme import GalleryIdentityLabel
from uploader.ui_theme import GlassCard
from uploader.ui_theme import METRIC_ICON_SIZE
from uploader.ui_theme import create_smooth_icon
from uploader.ui_theme import icon_button
from uploader.watcher.queue import WatchQueueStore
from uploader.watcher.queue import WatchRecoveryError
from uploader.watcher.recovery_ui import WatchRecoveryReviewDialog
from uploader.watcher.workers.watcher import WatcherThread
from uploader.watcher.workers.watcher import watch_recovery_owner_held
from uploader.watcher.profile import WATCHER_ONCE_LOCK
from uploader.watcher.profile import WATCHER_PREFERENCE_TYPES
from uploader.watcher.profile import WatcherProfile
from uploader.watcher.profile import discard_capture_queues
from uploader.watcher.profile import discard_once_queues
import logging

class WatcherPageMixin:
    """Own this cohesive WatcherApp UI behavior domain."""

    def open_watcher_rail_context(self, event: "tk.Event") -> str | None:
        """Open the same selected-gallery card beside the Watcher rail button.

        Args:
            event (tk.Event): Secondary pointer action on the Watcher trigger.

        Returns:
            str | None: Tk break for a handled Photos context, otherwise None.
        """
        if self.notebook.index("current") == self.tab_indices["photos"]:
            return self.photos_workspace.open_selected_watcher_context(event)
        return None


    def build_watch_tab(self, parent: "tk.Misc") -> None:
        """
        Build controls dedicated to watch-folder uploading.

        Args:
            parent (tk.Misc): Tkinter frame that receives the controls.

        Returns:
            None: Adds a wrapping gallery name with hierarchy help, offline
                abandonment, folder, UTC and flight controls, retaining
                SimBrief widgets for contextual visibility.
        """
        parent.columnconfigure(0, weight=1)
        hero = GlassCard(parent, padding=(14, 8))
        hero.grid(row=0, column=0, sticky="ew", padx=6, pady=(4, 4))
        hero.columnconfigure(0, weight=1)
        target = runtime.ttk.Frame(hero, style="Card.TFrame")
        target.grid(row=0, column=0, sticky="ew")
        target.columnconfigure(0, weight=1)
        GalleryIdentityLabel(
            target, self.watcher_gallery_label_var, self.watcher_gallery_title_var,
        ).grid(
            row=0, column=0, sticky="ew"
        )
        icon_button(
            target, "settings", self.open_connection_dialog,
            gettext("Upload via API key"),
        ).grid(row=0, column=1, padx=(8, 0))
        connection = runtime.ttk.Frame(hero, style="Card.TFrame")
        connection.grid(row=0, column=1, sticky="e", padx=(16, 0))
        runtime.ttk.Label(
            connection, textvariable=self.watcher_connection_status_var,
            style="Muted.TLabel",
        ).grid(row=0, column=0)
        self.watcher_capture_label = runtime.ttk.Label(
            connection, text=gettext("● REC"), style="Watcher.Active.TLabel",
        )
        self.watcher_capture_label.grid(row=0, column=1, padx=(8, 0))
        self.watcher_capture_label.grid_remove()
        flight_actions = runtime.ttk.Frame(hero, style="Card.TFrame")
        flight_actions.grid(row=1, column=0, columnspan=2, sticky="w", pady=(6, 0))
        self.finish_flight_button = Button(
            flight_actions, text=self.flights.text("finish_flight"),
            command=self.flight_action,
        )
        self.finish_flight_button.grid(row=0, column=0, sticky="w")
        self.simbrief_button = Button(
            flight_actions, textvariable=self.flights.simbrief_button_var,
            command=self.flights.toggle_simbrief,
        )
        self.simbrief_button.grid(row=0, column=1, padx=(6, 0))
        self.simbrief_settings_button = icon_button(
            flight_actions, "settings",
            lambda: self.flights.load_simbrief(change_identity=True),
            self.flights.text("simbrief_identity"),
        )
        self.simbrief_settings_button.grid(row=0, column=2, padx=(4, 0))
        self.abandon_flight_button = Button(
            flight_actions, text=self.flights.text("abandon"),
            command=self.abandon_flight,
        )
        self.abandon_flight_button.grid(row=0, column=3, padx=(6, 0))
        self.simbrief_status_label = runtime.ttk.Label(
            flight_actions, textvariable=self.flights.simbrief_status_var,
            style="Muted.TLabel",
        )
        self.simbrief_status_label.grid(
            row=1, column=1, sticky="w", pady=(4, 0)
        )

        card = GlassCard(parent, padding=(14, 10))
        card.grid(row=1, column=0, sticky="ew", padx=6, pady=4)
        card.columnconfigure(0, weight=1)
        heading = runtime.ttk.Frame(card, style="Card.TFrame")
        heading.grid(row=0, column=0, sticky="ew")
        heading.columnconfigure(4, weight=1)
        runtime.ttk.Label(heading, text=gettext("WATCH FOLDER"), style="Eyebrow.TLabel").grid(
            row=0, column=0, sticky="w"
        )
        Button(
            heading, text=gettext("Choose folder…"), command=self.browse_folder,
            style="Compact.TButton",
        ).grid(row=0, column=1, padx=(8, 0))
        icon_button(
            heading,
            "settings",
            self.open_watcher_settings,
            gettext("Watcher settings"),
        ).grid(row=0, column=2, padx=(4, 0))
        help_button(
            heading,
            gettext("Watcher behavior"),
            gettext("Files already present at startup are ignored. New files wait until their size and modified time remain unchanged. Subfolders are excluded unless enabled in Watcher settings."),
        ).grid(row=0, column=3, padx=(4, 0))
        runtime.ttk.Label(
            card, textvariable=self.watch_folder_display_var,
            style="Muted.TLabel", wraplength=WINDOW_SUMMARY_WRAP,
        ).grid(row=1, column=0, sticky="w", pady=(1, 4))
        startup_status = runtime.ttk.Frame(card, style="Card.TFrame")
        startup_status.grid(row=2, column=0, sticky="ew", pady=(1, 3))
        startup_status.columnconfigure(0, weight=1)
        runtime.ttk.Label(
            startup_status, textvariable=self.status_var, style="Status.TLabel",
            wraplength=WINDOW_SUMMARY_WRAP,
        ).grid(row=0, column=0, sticky="w")
        self.watch_start_progress = runtime.ttk.Progressbar(
            startup_status, mode="indeterminate",
        )
        self.watch_start_progress.grid(
            row=1, column=0, sticky="ew", pady=(4, 4),
        )
        self.watch_start_progress.grid_remove()
        state = runtime.ttk.Frame(card, style="Card.TFrame")
        state.grid(row=3, column=0, sticky="ew", pady=(0, 5))
        runtime.ttk.Label(state, textvariable=self.watch_ignored_var, style="Muted.TLabel").pack(side="left")
        runtime.ttk.Label(state, textvariable=self.watch_last_scan_var, style="Muted.TLabel").pack(side="left", padx=(18, 0))
        actions = runtime.ttk.Frame(card, style="Card.TFrame")
        actions.grid(row=4, column=0, sticky="ew")
        self.watcher_action_button = Button(
            actions,
            text=gettext("▶  Start Watcher"),
            command=self.toggle_watcher,
            style="Primary.TButton",
            takefocus=True,
        )
        self.watcher_action_button.pack(side="left")
        icon_button(
            actions, '↻', self.refresh_upload_queue,
            tr(self.flights.ui_language.get(), 'watch_queue_refresh_hint'),
        ).pack(side='left', padx=(8, 0))
        Button(
            actions,
            text=recovery_text(
                self.flights.ui_language.get(), 'watch_inspection_title',
            ),
            command=self.dry_run_watch_folder,
        ).pack(side='left', padx=(8, 0))
        runtime.ttk.Label(
            card, textvariable=self.watch_queue_status_var, style='Muted.TLabel',
            wraplength=WATCH_QUEUE_STATUS_WRAP_PIXELS, justify='left',
        ).grid(row=6, column=0, sticky='w', pady=(4, 0))
        self.watch_recovery_action_button = Button(
            actions, text=recovery_text(self.flights.ui_language.get(), 'force_stop_watch'),
            command=self.flights.force_stop_watch,
        )
        utc = runtime.ttk.Frame(card, style="Card.TFrame")
        self.watcher_utc_row = utc
        utc.grid(row=5, column=0, sticky="w", pady=(6, 0))
        runtime.ttk.Checkbutton(
            utc,
            text=gettext("Sync UTC time"),
            variable=self.sync_utc_time_var,
            command=self.toggle_utc_sync,
        ).pack(side="left")
        help_button(
            utc,
            gettext("UTC sync"),
            gettext("Keeps Flight Simulator in sync with real-world UTC while the watcher runs. Off by default. Your choice is remembered."),
        ).pack(side="left", padx=(2, 0))
        runtime.ttk.Label(
            utc, textvariable=self.utc_sync_status_var, style="Muted.TLabel",
        ).pack(side="left", padx=(14, 0))
        self.watch_delete_warning = runtime.ttk.Label(
            card,
            text="",
            style="Warning.TLabel",
            wraplength=760,
        )
        self.watch_delete_warning.grid(row=8, column=0, sticky="ew", pady=(14, 0))
        reset_icon = create_smooth_icon(heading, 'refresh', METRIC_ICON_SIZE)
        self.watcher_reset_button = Button(
            heading, text=gettext('Watcher factory reset…'), image=reset_icon or '',
            compound='left', style='Compact.TButton', takefocus=True,
            command=self.request_watcher_reset,
        )
        self.watcher_reset_button.reset_icon = reset_icon
        self.watcher_reset_button.grid(row=0, column=5, sticky='e', padx=(8, 0))
        self.refresh_watch_delete_warning()
        self.flights.controls(card)


    def _saved_watcher_config(self) -> watcher_state.WatcherConfig:
        """Read effective Watcher preferences over the shared uploader config.

        Returns:
            WatcherConfig: Dedicated Watcher fields after a reset, or the
                existing shared configuration before a reset.
        """
        profile = getattr(self, '_watcher_profile', None)
        if isinstance(profile, WatcherProfile):
            return watcher_state.replace(self.config, **profile.preferences)
        return self.config


    def _save_watcher_preferences(
        self, config: watcher_state.WatcherConfig, *, first_setup: bool = True,
    ) -> None:
        """Write Watcher settings to their current persistence owner.

        Args:
            config (WatcherConfig): Current form values or one changed preference.
            first_setup (bool): Permit creating a missing legacy settings file
                for an explicit folder or settings commit.
        Returns:
            None: Saves and verifies only the selected configuration owner.
        Raises:
            OSError: The reset, shared settings owner or profile prevents a save.
        """
        if type(self).watcher_reset_blocked(self):
            raise OSError('Complete the Watcher factory reset first.')
        profile = getattr(self, '_watcher_profile', None)
        if not isinstance(profile, WatcherProfile):
            self.config = self._save_owned_config(config, first_setup=first_setup)
            return
        lease = self.flights.lease
        if lease is None:
            raise OSError('Watcher profile is unavailable.')
        values = config.to_dict()
        proposed = watcher_state.replace(profile, preferences={
            key: values[key] for key in WATCHER_PREFERENCE_TYPES
        })
        if proposed != profile:
            proposed.save(lease)
            self._watcher_profile = proposed


    def _watcher_connection_selected(self) -> bool:
        """Check whether the current shared destination was selected for Watcher.

        Returns:
            bool: True for older profiles or an exact post-reset binding.
        """
        if type(self).watcher_reset_blocked(self):
            return False
        profile = getattr(self, '_watcher_profile', None)
        if not isinstance(profile, WatcherProfile):
            return True
        try:
            return bool(profile.binding and self.flights.binding() == profile.binding)
        except (ValueError, watcher_state.ModeCoordinatorError):
            return False


    def _remember_watcher_connection(self, binding: str) -> None:
        """Save an explicit choice of already verified upload authority.

        Args:
            binding (str): Exact non-secret fingerprint of the selected gallery.
        Returns:
            None: Records the choice for the post-reset Watcher profile.
        Raises:
            OSError: The reset or lost profile ownership prevents selection.
        """
        if type(self).watcher_reset_blocked(self):
            raise OSError('Complete the Watcher factory reset first.')
        profile = getattr(self, '_watcher_profile', None)
        if not isinstance(profile, WatcherProfile) or profile.binding == binding:
            return
        lease = self.flights.lease
        if lease is None:
            raise OSError('Watcher profile is unavailable.')
        proposed = watcher_state.replace(profile, binding=binding)
        proposed.save(lease)
        self._watcher_profile = proposed


    def connect_watcher(self) -> None:
        """Ask to use the current upload connection for the reset Watcher.

        Returns:
            None: Opens connection setup or explicitly remembers its exact binding.
        """
        if type(self).watcher_reset_blocked(self) or self.updates.blocked():
            return
        if self.linking_manager.linked_upload_mode_selected():
            target = self.linking_manager.selected_upload_target()
            if target is None:
                self.reconnect_upload_gallery()
                return
            binding = target.binding()
            question = 'Use this upload gallery for Watcher?\n\n' + target.title
        else:
            if not watcher_state.normalize_upload_url(self.config.gallery_url) or not self.config.api_key.strip():
                self.open_connection_dialog()
                return
            binding = self.flights.binding()
            question = 'Use the saved gallery API key for Watcher?'
        if not runtime.messagebox.askyesno(gettext('Connect Watcher'), question, parent=self.root):
            return
        try:
            if binding != self.flights.binding():
                raise OSError('The upload gallery changed.')
            type(self)._remember_watcher_connection(self, binding)
        except (OSError, ValueError, watcher_state.ModeCoordinatorError):
            self.write_log(format_watch_recovery_diagnostic(
                'factory_reset', 'new_connection_unconfirmed',
            ), 'warning')
            runtime.messagebox.showwarning(
                gettext('Connect Watcher'), gettext('The Watcher connection could not be saved. Try again.'),
                parent=self.root,
            )
            return
        self.status_var.set(gettext('Watcher gallery selected.'))
        self.refresh_watcher_action()


    def request_watcher_reset(self) -> None:
        """Require explicit confirmation before forgetting any Watcher state.

        Returns:
            None: Cancel leaves the current capture and settings untouched.
        """
        if self._watcher_reset_running or self.exiting:
            return
        if not runtime.messagebox.askyesno(
            gettext('Watcher factory reset'),
            gettext(
                "Stop Watcher and permanently forget its upload gallery, watched "
                "folder, queued work, and restart history? Watcher preferences "
                "will return to their defaults.\n\n"
                "You will keep your screenshots, local reports, logs, and other "
                "tabs' settings. An upload already sent may still reach the gallery."
            ),
            default='no', parent=self.watcher_reset_button,
        ):
            return
        self._begin_watcher_reset()


    def _begin_watcher_reset(self) -> None:
        """Persist confirmed local reset intent before stopping capture.

        Returns:
            None: Starts release of Watcher writers or logs why intent failed.
        """
        if self._watcher_reset_running or self.exiting:
            return
        if self.updates.blocked():
            self.write_log(format_watch_recovery_diagnostic(
                'factory_reset', 'update_in_progress',
            ), 'warning')
            self.status_var.set(gettext('Complete the app update before resetting Watcher.'))
            return
        worker = self.worker
        self.write_log(
            format_watch_recovery_diagnostic('factory_reset', 'confirmed')
            + f'; worker_running={str(bool(worker and worker.is_alive())).lower()}',
        )
        lease = self.flights.lease
        values = watcher_state.WatcherConfig().to_dict()
        profile = WatcherProfile(True, '', {
            key: values[key] for key in WATCHER_PREFERENCE_TYPES
        })
        try:
            if lease is None:
                raise OSError('Watcher workspace lease is unavailable.')
            profile.save(lease)
        except (OSError, ValueError, RuntimeError) as exc:
            # A failed readback is not proof that the pending write rolled back.
            self._watcher_profile_invalid = True
            self.write_log(format_watch_recovery_diagnostic(
                'factory_reset', 'reset_intent_write_unconfirmed', error=exc,
            ), 'error')
            self.status_var.set(gettext('Watcher reset could not be saved. Retry factory reset.'))
            self.refresh_watcher_action()
            return
        self._watcher_profile = profile
        self._watcher_profile_invalid = False
        self._watcher_reset_running = True
        self._watcher_reset_waiting = ''
        self._update_resume_cancelled = True
        self._update_resume_generation += 1
        self._resume_continuation = None
        self._resume_retry_available = False
        self._finish_resume_generation += 1
        self._queue_recovery_finish_requested = False
        self._set_watch_start_activity(False)
        self._set_watcher_recording(False)
        self.utc_sync.stop()
        self.flights.stop_for_watcher_reset()
        self.watch_queue_status_var.set(gettext('Stopping Watcher for factory reset…'))
        self.refresh_watcher_action()
        self._continue_watcher_reset()


    def _continue_watcher_reset(self) -> None:
        """Wait for Watcher-owned writers before removing any durable queue.

        Returns:
            None: Polls without blocking Tk, then schedules the offline reset.
        """
        if not self._watcher_reset_running or self.exiting:
            return
        flights = self.flights
        live = []
        for worker in (self.worker, *flights.resume_workers, *self._force_finished_workers):
            if isinstance(worker, WatcherThread) and worker.is_alive():
                worker.request_factory_reset_stop()
                live.append(worker)
        if live:
            has_transport = any(
                worker._transport_active.is_set()
                or worker._pending_transport is not None
                and not worker._pending_transport.is_set() for worker in live
            )
            waiting = (
                'waiting_for_dispatched_upload' if has_transport
                else 'waiting_for_watcher_worker'
            )
        elif flights.watcher_reset_writers_active() or any(
            operation.is_alive() for operation in self._force_finished_operations
        ):
            waiting = 'capture_finalization_or_recorder'
        elif self._queue_recovery_pending or self._force_finish_callbacks:
            waiting = 'queue_recovery_publication'
        elif (self._ordinary_restart_thread is not None
              and self._ordinary_restart_thread.is_alive()):
            waiting = 'restart_checkpoint'
        else:
            waiting = ''
        if waiting:
            if waiting != self._watcher_reset_waiting:
                self._watcher_reset_waiting = waiting
                self.write_log(format_watch_recovery_diagnostic('factory_reset', waiting))
                self.watch_queue_status_var.set(
                    gettext(
                        "Reset pending. Waiting for Watcher work to stop. "
                        "You can exit and reopen WinApp to retry an interrupted reset."
                    ),
                )
            self.root.after(WATCHER_EVENT_IDLE_POLL_MS, self._continue_watcher_reset)
            return
        profile = self._watcher_profile
        lease = flights.lease
        self.watch_queue_status_var.set(gettext('Restoring Watcher defaults…'))
        if profile is None or lease is None:
            self._complete_watcher_reset(None, format_watch_recovery_diagnostic(
                'factory_reset', 'workspace_unavailable',
            ))
            return

        def publish() -> None:
            """Remove only Watcher journals and verify the default profile.

            Returns:
                None: Delivers the verified result or a bounded failure to Tk.
            """
            completed = None
            step = 'once_queue_lease'
            try:
                once = WorkspaceLease(watcher_state.CONFIG_DIR, lock_name=WATCHER_ONCE_LOCK)
                try:
                    step = 'once_queue_cleanup'
                    once_count = discard_once_queues(watcher_state.CONFIG_DIR)
                finally:
                    once.release()
                step = 'capture_queue_cleanup'
                closed, removed = discard_capture_queues(lease)
                step = 'default_profile_readback'
                candidate = watcher_state.replace(profile, pending=False)
                candidate.save(lease)
                completed = candidate
                settings = candidate.preferences
                diagnostic = format_watch_recovery_diagnostic(
                    'factory_reset', 'completed', sessions=closed, pending=0,
                ) + (
                    '; watcher=stopped; connection=unset; folder=unset'
                    f'; journals_removed={removed}; once_journals_removed={once_count}'
                    f'; scan_interval_seconds={settings["scan_interval_seconds"]}'
                    f'; stable_seconds={settings["stable_seconds"]}'
                    f'; recursive={str(settings["watch_recursive"]).lower()}'
                    f'; simulator_location={str(settings["attach_sim_camera_metadata"]).lower()}'
                    f'; delete_after_upload={str(settings["delete_uploaded_files"]).lower()}'
                    f'; utc_sync={str(settings["sync_utc_time"]).lower()}'
                    f'; record_activity={str(FlightPreferences().recording).lower()}'
                )
            except (OSError, ValueError, RuntimeError) as exc:
                diagnostic = format_watch_recovery_diagnostic(
                    'factory_reset', f'{step}_failed', error=exc,
                ) + '; reset_pending=true'
            self.events.put({'kind': 'watcher_factory_reset', 'payload': {
                'profile': completed, 'diagnostic': diagnostic,
            }})

        try:
            self._watcher_reset_thread = watcher_state.threading.Thread(
                target=publish, name='PHPGalleryWatcherFactoryReset', daemon=True,
            )
            self._watcher_reset_thread.start()
        except RuntimeError as exc:
            self._complete_watcher_reset(None, format_watch_recovery_diagnostic(
                'factory_reset', 'publisher_start_failed', error=exc,
            ))


    def _complete_watcher_reset(
        self, profile: WatcherProfile | None, diagnostic: str,
    ) -> None:
        """Publish the reset outcome and re-render Watcher-only controls.

        Args:
            profile (WatcherProfile | None): Verified defaults, or None on failure.
            diagnostic (str): Safe stage, outcome and outcome-specific evidence.
        Returns:
            None: Applied settings or a visible retry with admission still fenced.
        """
        self._watcher_reset_running = False
        self._watcher_reset_thread = None
        self._watcher_reset_latest = diagnostic
        self.write_log(diagnostic, 'success' if profile is not None else 'error')
        if profile is None:
            self.watch_queue_status_var.set(
                gettext('Watcher reset could not finish. Retry factory reset or see Support diagnostics.'),
            )
            self.refresh_watcher_action()
            return
        self._watcher_profile = profile
        self.worker = None
        self._update_continuation = None
        self._update_resume_preparing = False
        self._update_resume_inspecting = False
        self._ordinary_restart_prepared = False
        self._ordinary_restart_closing = False
        self._finish_resume_checked = None
        self._finish_resume_inspecting = False
        self._finish_resume_attempted_sessions.clear()
        self._watcher_key_closing = False
        self._contextual_watch_is_flight = False
        for target in (self._contextual_watch_target, self._update_resume_context_target):
            if isinstance(target, watcher_state.LinkedUploadTarget):
                self.linking_manager.discard_photos_watcher_target(target)
        self._contextual_watch_target = None
        self._update_resume_context_target = None
        self.flights.complete_watcher_reset()
        settings = profile.preferences
        self.watched_folder_var.set(settings['watched_folder'])
        self.interval_var.set(str(settings['scan_interval_seconds']))
        self.stable_var.set(str(settings['stable_seconds']))
        self.watch_recursive_var.set(settings['watch_recursive'])
        self.attach_sim_camera_metadata_var.set(settings['attach_sim_camera_metadata'])
        self.delete_uploaded_files_var.set(settings['delete_uploaded_files'])
        self.sync_utc_time_var.set(settings['sync_utc_time'])
        self.watch_ignored_var.set(gettext('Ignored at startup: -'))
        self.watch_last_scan_var.set(gettext('Last scan: -'))
        self.watch_queue_status_var.set('')
        self.watcher_activity_var.set('Watch: stopped')
        self.status_var.set(gettext('Watcher reset complete. Choose a connection and folder to start.'))
        self.refresh_watch_delete_warning()
        self.refresh_activity_monitor_state()
        self.refresh_connection_controls()


    def current_watcher_config(self) -> watcher_state.WatcherConfig:
        """Read the watcher settings without selecting a connection.

        Returns:
            WatcherConfig: Saved settings with the current Watcher fields.

        Raises:
            ValueError: The scan interval or file stability value is invalid.
        """
        try:
            interval = max(
                watcher_state.MIN_INTERVAL_SECONDS,
                float(self.interval_var.get().strip() or watcher_state.DEFAULT_INTERVAL_SECONDS),
            )
            stable = max(
                watcher_state.MIN_STABLE_SECONDS,
                float(self.stable_var.get().strip() or watcher_state.DEFAULT_STABLE_SECONDS),
            )
        except ValueError as exc:
            raise ValueError(gettext_noop("Enter a valid scan interval and file stability time.")) from exc
        return watcher_state.replace(
            type(self)._saved_watcher_config(self),
            watched_folder=self.watched_folder_var.get().strip(),
            scan_interval_seconds=interval,
            stable_seconds=stable,
            attach_sim_camera_metadata=bool(self.attach_sim_camera_metadata_var.get()),
            delete_uploaded_files=bool(self.delete_uploaded_files_var.get()),
            watch_recursive=bool(self.watch_recursive_var.get()),
            sync_utc_time=bool(self.sync_utc_time_var.get()),
        )


    def current_config(self) -> watcher_state.WatcherConfig:
        """
        Read and validate the current UI values.

        Returns:
            WatcherConfig: WatcherConfig built from the current form state.

        Raises:
            ValueError: Raised when numeric settings cannot be parsed.
        """
        # Reset-isolated Watcher settings never reenter the shared config.
        isolated = isinstance(getattr(self, '_watcher_profile', None), WatcherProfile)
        watcher = self.config if isolated else self.current_watcher_config()
        try:
            thumbnail_workers = parse_worker_choice(self.manual_thumbnail_workers_var.get())
            upload_workers = parse_worker_choice(self.manual_upload_workers_var.get())
            inventory_refresh = max(
                watcher_state.MIN_INVENTORY_REFRESH_SECONDS, float(self.inventory_refresh_var.get().strip() or watcher_state.DEFAULT_INVENTORY_REFRESH_SECONDS),
            )
            archive_max_entries = watcher_state.clamp_int(
                int(self.archive_max_entries_var.get().strip() or watcher_state.REDESIGN_CONFIG_DEFAULTS["archive_max_entries"]),
                *watcher_state.ZIP_ENTRY_RANGE,
            )
            archive_max_total = int(max(watcher_state.ZIP_TOTAL_RANGE[0], min(
                watcher_state.ZIP_TOTAL_RANGE[1], float(self.archive_max_total_gib_var.get().strip() or 16.0),
            )) * BYTES_PER_GIB)
            archive_max_file = int(max(watcher_state.ZIP_FILE_RANGE[0], min(
                watcher_state.ZIP_FILE_RANGE[1], float(self.archive_max_file_gib_var.get().strip() or 2.0),
            )) * BYTES_PER_GIB)
            archive_max_ratio = max(watcher_state.ZIP_EXPANSION_RANGE[0], min(
                watcher_state.ZIP_EXPANSION_RANGE[1], float(self.archive_max_ratio_var.get().strip() or 250.0),
            ))
            staging_cleanup_hours = max(watcher_state.STAGING_RETENTION_RANGE[0], min(
                watcher_state.STAGING_RETENTION_RANGE[1], float(self.staging_cleanup_hours_var.get().strip() or 24.0),
            ))
            ai_worker_poll = max(watcher_state.AI_WORKER_MIN_POLL_SECONDS, float(self.ai_worker_poll_var.get().strip() or watcher_state.DEFAULT_AI_WORKER_POLL_SECONDS))
            ai_worker_lease = watcher_state.clamp_int(
                int(self.ai_worker_lease_var.get().strip() or watcher_state.DEFAULT_AI_WORKER_LEASE_SECONDS),
                watcher_state.AI_WORKER_MIN_LEASE_SECONDS, watcher_state.AI_WORKER_MAX_LEASE_SECONDS,
            )
            ai_transformers_detection_threshold = max(watcher_state.AI_DETECTION_RANGE[0], min(
                watcher_state.AI_DETECTION_RANGE[1],
                float(self.ai_transformers_detection_threshold_var.get().strip() or watcher_state.AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT),
            ))
        except ValueError as exc:
            raise ValueError(gettext_noop(
                "Timing, worker, archive-safety, and AI numeric settings must contain valid numbers."
            )) from exc

        gallery_url = self.gallery_url_var.get().strip()
        if gallery_url:
            gallery_url = watcher_state.normalize_upload_url(gallery_url)
            if not gallery_url:
                raise ValueError(gettext_noop("Gallery URL must use HTTP or HTTPS and contain a valid host."))

        return watcher_state.replace(
            watcher,
            schema_version=watcher_state.CONFIG_SCHEMA_VERSION,
            gallery_url=gallery_url,
            api_key=self.api_key_var.get().strip(),
            create_thumbnails=bool(self.create_thumbnails_var.get()),
            simconnect_dll_path=self.simconnect_dll_path_var.get().strip(),
            sync_utc_time=watcher.sync_utc_time if isolated else self.sync_utc_time_var.get(),
            manual_thumbnail_mode="local" if self.manual_local_thumbnails_var.get() else "server",
            manual_thumbnail_workers=thumbnail_workers,
            manual_upload_workers=upload_workers,
            inventory_refresh_seconds=inventory_refresh,
            archive_max_entries=archive_max_entries,
            archive_max_uncompressed_bytes=archive_max_total,
            archive_max_file_bytes=archive_max_file,
            archive_max_compression_ratio=archive_max_ratio,
            staging_cleanup_age_hours=staging_cleanup_hours,
            close_to_tray=bool(self.close_to_tray_var.get()),
            start_with_windows=bool(self.start_with_windows_var.get()),
            experimental_canvas_gallery_renderer=(
                self.experimental_canvas_gallery_renderer_var.get() is True
            ),
            activity_history_limit=self.config.activity_history_limit,
            ai_worker_enabled=bool(self.ai_worker_enabled_var.get()),
            ai_worker_poll_seconds=ai_worker_poll,
            ai_worker_lease_seconds=ai_worker_lease,
            ai_model_name=self.ai_model_name_var.get().strip() or watcher_state.AI_MODEL_NAME_DEFAULT,
            ai_model_version=self.ai_model_version_var.get().strip() or watcher_state.AI_MODEL_VERSION_DEFAULT,
            ai_external_command=self.ai_external_command_var.get().strip(),
            ai_vision_backend=next(
                (code for code, label in ai_backend_labels().items()
                 if label == self.ai_vision_backend_var.get()),
                watcher_state.normalize_ai_vision_backend(self.ai_vision_backend_var.get()),
            ),
            ai_transformers_caption_model=self.ai_transformers_caption_model_var.get().strip() or watcher_state.AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT,
            ai_transformers_detector_model=self.ai_transformers_detector_model_var.get().strip() or watcher_state.AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT,
            ai_transformers_object_labels=self.ai_transformers_object_labels_var.get().strip() or watcher_state.AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT,
            ai_transformers_detection_threshold=ai_transformers_detection_threshold,
            ai_ollama_url=self.ai_ollama_url_var.get().strip() or watcher_state.AI_OLLAMA_URL_DEFAULT,
            ai_ollama_model=self.ai_ollama_model_var.get().strip() or watcher_state.AI_OLLAMA_MODEL_DEFAULT,
            ai_semantic_prompt=self.ai_semantic_prompt_var.get().strip() or watcher_state.AI_SEMANTIC_PROMPT_DEFAULT,
        )


    def update_watch_folder_display(self, *_trace_info: str) -> None:
        """Refresh the read-only folder label as its edit field changes.

        Args:
            *_trace_info (str): Tk variable name, index, and write operation.

        Returns:
            None: Shows the selected path or a concise empty-state label.
        """
        folder = self.watched_folder_var.get().strip()
        self.watch_folder_display_var.set(folder or gettext("No folder selected"))


    def browse_folder(self, *, parent: Optional["tk.Misc"] = None) -> None:
        """
        Open a native directory picker for the stopped Watcher.

        Args:
            parent (Optional[tk.Misc]): Settings dialog for an unsaved edit,
                or None to save the folder directly from the main window.

        Returns:
            None: Updates the folder after a successful pick and any required
                save. Cancel or a failed save leaves the existing folder.
        """
        if (type(self).watcher_reset_blocked(self)
                or getattr(self, "updates", None) is not None and self.updates.blocked()):
            return
        if self.worker is not None and self.worker.is_alive():
            runtime.messagebox.showinfo(
                gettext("Watch folder"), gettext("Stop Watcher before changing its folder."),
                parent=parent or self.root,
            )
            return
        selected = runtime.filedialog.askdirectory(
            initialdir=self.watched_folder_var.get() or str(watcher_state.Path.home()),
            parent=parent or self.root, mustexist=True,
        )
        if not selected:
            return
        if parent is None:
            try:
                saved = type(self)._saved_watcher_config(self)
                type(self)._save_watcher_preferences(
                    self, watcher_state.replace(saved, watched_folder=selected),
                )
            except (OSError, watcher_state.ModeCoordinatorError) as exc:
                runtime.messagebox.showerror(gettext("Watch folder"), str(exc), parent=self.root)
                return
        self.watched_folder_var.set(selected)
        self.watch_ignored_var.set(gettext("Ignored at startup: not scanned yet"))
        self.watch_last_scan_var.set(gettext("Last scan: -"))


    def browse_simconnect_dll(self) -> None:
        """
        Open a file picker for the optional SimConnect client DLL.

        Returns:
            None: Sets the draft override when a file is selected.
        """
        selected = runtime.filedialog.askopenfilename(
            title=gettext("Select SimConnect.dll"),
            initialdir=str(watcher_state.Path(self.simconnect_dll_path_var.get()).parent) if self.simconnect_dll_path_var.get().strip() else str(watcher_state.Path.home()),
            filetypes=[(gettext("SimConnect DLL"), "SimConnect.dll"), (gettext("DLL files"), "*.dll"), (gettext("All files"), "*.*")],
        )
        if selected:
            self.simconnect_dll_path_var.set(selected)


    def contextual_watcher_is_flight(self) -> bool:
        """Latch observed simulator evidence for the current contextual capture.

        Returns:
            bool: True after simulator detection or any retained flight sample,
                including after the simulator closes.
        """
        if self._contextual_watch_is_flight:
            return True
        if self.simbrief_available():
            self._contextual_watch_is_flight = True
        else:
            recorder = self.flights.recorder
            samples = (
                recorder.diagnostics_snapshot().get("sample_count")
                if recorder is not None else None
            )
            self._contextual_watch_is_flight = type(samples) is int and samples > 0
        return self._contextual_watch_is_flight


    def _set_watch_start_activity(self, active: bool, message: str = "") -> None:
        """Paint explicit startup feedback without admitting screenshot work.

        Args:
            active (bool): Whether linking or the initial folder scan is pending.
            message (str): Current preparation phase, or empty to retain status.

        Returns:
            None: Shows activity and prevents repeated Start actions until ready.
        """
        self._watch_start_pending = active
        if message:
            self.status_var.set(message)
        progress = getattr(self, "watch_start_progress", None)
        if progress is not None:
            if active:
                progress.grid()
                progress.start(watcher_state.WATCHER_START_ACTIVITY_MS)
            else:
                progress.stop()
                progress.grid_remove()
        button = getattr(self, "watcher_action_button", None)
        if button is not None:
            caption = gettext("Starting Watcher…") if active else "▶  Start Watcher"
            state = "disabled" if active else "normal"
            _publish_watcher_value(
                self, "watch_action", button, (caption, state),
                lambda: button.configure(text=caption, state=state),
            )
        self.refresh_watcher_header()
        if active:
            # Paint the phase before synchronous local ownership checks. The
            # potentially long folder scan itself runs on the watcher thread.
            self.root.update_idletasks()


    def _watch_start_failed(self, message: str) -> None:
        """End the original Photos startup request with its safe refusal.

        Args:
            message (str): Fixed user-facing refusal supplied by the link owner.

        Returns:
            None: Restores the idle action and leaves the reason in Watcher.
        """
        self._set_watch_start_activity(False, message)


    def open_gallery_watcher(
        self, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Start Watcher for the selected gallery while retaining the Photos view.

        Args:
            session (LinkingSession): Current explicitly approved Photos link.
            gallery (LinkedGallery): Physical gallery selected for screenshots.

        Returns:
            None: Requests temporary Watcher authority without changing tabs;
                retained captures open their recovery view with visible status.
        """
        if (type(self).watcher_reset_blocked(self) or self.updates.blocked()
                or getattr(self, "_watch_start_pending", False) is True):
            return
        self._set_watch_start_activity(True, "Checking the Watcher connection…")
        state = self.flights.session_state()
        if state == "working":
            self._set_watch_start_activity(False)
            self.flights.show_progress()
            return
        if state in ("pending", "blocked"):
            self.write_log(format_watch_recovery_diagnostic(
                'photos_watcher', 'retained_capture_pending' if state == 'pending'
                else 'local_capture_state_unverified',
            ), 'info' if state == 'pending' else 'warning')
            self._set_watch_start_activity(
                False, self.flights.text('capture_recovery_paused'),
            )
            self.flights.show_recovery()
            return
        if state != "idle":
            self._set_watch_start_activity(False)
            return
        if self._contextual_watch_target is not None:
            self._set_watch_start_activity(False)
            self.release_contextual_watcher()
            self.status_var.set(gettext("Closing previous Watcher access. Try again when it finishes."))
            return
        folder = self.watched_folder_var.get().strip()
        try:
            ready = bool(folder) and watcher_state.Path(folder).is_dir()
        except OSError:
            ready = False
        if not ready:
            self.browse_folder()
            folder = self.watched_folder_var.get().strip()
            try:
                if not folder or not watcher_state.Path(folder).is_dir():
                    self._set_watch_start_activity(False)
                    return
            except OSError:
                self._set_watch_start_activity(False)
                return
        self.status_var.set(gettext("Preparing the Watcher connection…"))
        generation = getattr(self, '_update_resume_generation', 0)

        def allowed() -> bool:
            """Identify the same capture request after asynchronous approval.

            Returns:
                bool: False if a reset or another capture action invalidated it.
            """
            return (
                not type(self).watcher_reset_blocked(self)
                and getattr(self, '_update_resume_generation', 0) == generation
            )

        self.linking_manager.start_photos_watcher(
            session, gallery, self._start_contextual_watcher,
            lambda message: self._watch_start_failed(message) if allowed() else None,
            still_requested=allowed,
        )


    def _start_contextual_watcher(self, target: watcher_state.LinkedUploadTarget) -> bool:
        """Start the existing Watcher with one freshly issued temporary key.

        Args:
            target (LinkedUploadTarget): Verified gallery and in-memory Watcher key.

        Returns:
            bool: True when the accepted capture owns the temporary credential.
        """
        try:
            self.start(contextual_target=target)
        except Exception:  # noqa: BLE001
            self._watch_start_failed("Watcher could not start.")
        return self._contextual_watch_target is target


    def release_contextual_watcher(self) -> None:
        """Revoke one temporary key after the existing finalization completed.

        Returns:
            None: Starts bounded cleanup or preserves unfinished capture authority.
        """
        target = getattr(self, "_contextual_watch_target", None)
        if (not isinstance(target, watcher_state.LinkedUploadTarget) or target.watch_key is None
                or self._watcher_key_closing
                or self.flights.session_state() != "idle"):
            return
        key = target.watch_key
        self._watcher_key_closing = True

        def revoke() -> None:
            """Revoke the exact captured credential off the Tk thread.

            Returns:
                None: Queues a confirmation or retryable failure without the key text.
            """
            try:
                target.session.close_watch_key(target.gallery_id, key)
                closed = True
            except Exception:  # noqa: BLE001
                closed = False
            self.events.put({
                "kind": "photos_watch_key_closed",
                "payload": {"target": target, "closed": closed},
            })

        try:
            watcher_state.threading.Thread(target=revoke, name="PhotosWatcherKeyClose", daemon=True).start()
        except RuntimeError:
            self._watcher_key_closing = False
        self.refresh_watcher_action()


    def start(self, *, contextual_target: watcher_state.LinkedUploadTarget | None = None) -> None:
        """Show startup activity before preparing the shared Watcher worker.

        Args:
            contextual_target (LinkedUploadTarget | None): Exact temporary
                Photos authority, or None for ordinary Watcher startup.

        Returns:
            None: Accepts startup once; REC waits for the initial folder boundary.
        """
        if (type(self).watcher_reset_blocked(self)
                or getattr(self, '_ordinary_restart_closing', False) is True):
            return
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if (getattr(self, "_watch_start_pending", False) is True
                and contextual_target is None):
            return
        if self.worker is not None and self.worker.is_alive():
            self.write_log("Watcher is already running.")
            return
        if contextual_target is None and not type(self)._watcher_connection_selected(self):
            self.connect_watcher()
            return
        self._set_watch_start_activity(True, "Checking the Watcher settings…")
        try:
            type(self)._start_watcher(self, contextual_target=contextual_target)
        finally:
            if self.worker is None or not self.worker.is_alive():
                self._set_watch_start_activity(False)


    def _start_watcher(self, *, contextual_target: watcher_state.LinkedUploadTarget | None = None) -> None:
        """
        Start the watcher worker using the current form values.

        Files already present at start are intentionally counted and ignored.

        Args:
            contextual_target (LinkedUploadTarget | None): Verified ephemeral
                authority for this Photos capture, or None for ordinary Watcher.

        Returns:
            None: Starts watching and admits UTC work only when selected.
        """
        if (type(self).watcher_reset_blocked(self)
                or getattr(self, '_ordinary_restart_closing', False) is True):
            return
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.api_key_revoke_running:
            self.flights.show_progress()
            return
        if self.worker and self.worker.is_alive():
            self.write_log("Watcher is already running.")
            return

        selected = self.watched_folder_var.get().strip()
        folder = watcher_state.Path(selected)
        try:
            has_folder = bool(selected) and folder.is_dir()
        except OSError:
            has_folder = False
        if not has_folder:
            runtime.messagebox.showinfo(
                gettext("Watch folder"), gettext("Choose a folder to watch before starting."),
                parent=self.root,
            )
            return
        if not self.flights.can_start():
            return

        try:
            linked_target = self.capture_linked_target()
            if contextual_target is not None:
                if (linked_target is None or contextual_target.watch_key is None
                        or linked_target.session is not contextual_target.session
                        or linked_target.binding() != contextual_target.binding()):
                    raise ValueError('The Watcher gallery changed before capture.')
                linked_target = contextual_target
            elif isinstance(getattr(self, "_contextual_watch_target", None),
                            watcher_state.LinkedUploadTarget):
                self.release_contextual_watcher()
                return
            isolated = isinstance(getattr(self, '_watcher_profile', None), WatcherProfile)
            config = (self.current_watcher_config()
                      if linked_target is not None or isolated else self.current_config())
            if linked_target is not None and (
                (self.manual_worker and self.manual_worker.is_alive())
                or (self.preflight_worker and self.preflight_worker.is_alive())
            ):
                raise ValueError('Wait for the linked import or preflight to finish first.')
            if not self.connection_change_allowed(config):
                return
            if isolated:
                type(self)._save_watcher_preferences(self, config)
                if contextual_target is not None:
                    type(self)._remember_watcher_connection(self, contextual_target.binding())
                config = type(self)._saved_watcher_config(self)
            else:
                config = self._save_owned_config(config)
                self.config = config
        except Exception as exc:  # noqa: BLE001
            runtime.messagebox.showerror(gettext("Configuration error"), str(exc))
            return

        if (linked_target is None
                and (not watcher_state.normalize_upload_url(config.gallery_url) or not config.api_key.strip())):
            runtime.messagebox.showerror(gettext("Connection"), gettext("Gallery URL and API key are required before starting the watcher."))
            return

        self._set_watch_start_activity(True, "Reading existing screenshots…")
        self.watch_ignored_var.set(gettext("Ignored at startup: checking existing files…"))
        self.watch_last_scan_var.set(gettext("Last scan: preparing"))
        self.utc_sync.stop()
        try:
            captured = watcher_state.capture_worker_config(config)
            config_port = self.config_store.mode_port(
                watcher_state.capture_worker_config(self.config) if isolated else captured,
            )
            self.worker = WatcherThread(
                captured, self.events, linked_target,
                gui_admission=(self.mode_owner, config_port),
            )
            contextual_flight = contextual_target is not None and self.simbrief_available()
            directory = (
                self.flights.start_session(photos_only=True)
                if contextual_target is not None and not contextual_flight
                else self.flights.start_session()
            )
            self.worker.configure_drain(directory, None)
            self.worker.start()
        except (OSError, ValueError, RuntimeError) as exc:
            if self.worker is not None and self.worker._linked_lease is not None:
                self.worker._linked_lease.release()
            self.flights.stop()
            self._watch_start_failed("Watcher could not prepare its local queue. Check the error and retry.")
            runtime.messagebox.showerror(gettext("Watcher state"), str(exc))
            self.worker = None
            return
        if contextual_target is not None:
            self._contextual_watch_target = contextual_target
            self._contextual_watch_is_flight = contextual_flight
        self.refresh_utc_sync()
        self.watcher_activity_var.set("Watch: starting")
        self.write_log("Watcher preparation started.", "system")
        self.refresh_activity_monitor_state()
        self.refresh_revoke_button_state()


    def stop(self) -> None:
        """Stop observation and finish accepted photographs and flight reports.

        Returns:
            None: Routes ordinary and contextual captures through shared drain.
        """
        if (type(self).watcher_reset_blocked(self)
                or getattr(self, '_ordinary_restart_closing', False) is True):
            return
        self._cancel_ordinary_restart_for_action()
        self._resume_retry_available = False
        self._set_watcher_recording(False)
        if not isinstance(getattr(self, "_contextual_watch_target", None), watcher_state.LinkedUploadTarget):
            self.flights.begin(False)
        elif self.contextual_watcher_is_flight():
            self.flights.finish_report()
        else:
            self.flights.begin(False, photos_only=True)


    def _cancel_ordinary_restart_intent(self) -> None:
        """Revoke ordinary restart consent without changing retained queue data.

        Returns:
            None: Marks only this profile's alternate restart record cancelled.
        """
        # Like finalization admission, this bounded local metadata cancellation
        # completes before returning to Tk: a queued resume callback must never
        # observe still-active consent after the user has pressed Stop.
        try:
            lease = self.flights.lease
            if lease is None:
                return
            record = WatcherContinuation.load(lease, ordinary_restart=True)
            if record is not None and record.phase in (
                    'preparing', 'prepared', 'claimed'):
                watcher_state.replace(record, phase='cancelled').save(
                    lease, ordinary_restart=True,
                )
        except (OSError, ValueError, RuntimeError):
            self.write_log(
                '[watcher-resume] stage=stop; reason=ordinary_consent_cancel_failed',
                'warning',
            )


    def _cancel_ordinary_restart_for_action(self) -> None:
        """Cancel both restart records before an explicit Stop, Finish or unlink.

        Returns:
            None: Revokes ordinary consent while leaving session data intact.
        """
        self._update_resume_cancelled = True
        self._update_resume_generation += 1
        self._discard_update_resume_intent(explicit=True)
        self._resume_continuation = None
        self._resume_retry_available = False
        try:
            lease = self.flights.lease
            ordinary = (WatcherContinuation.load(lease, ordinary_restart=True)
                        if lease is not None else None)
        except (OSError, ValueError, RuntimeError):
            ordinary = None
        if ordinary is not None and ordinary.phase in (
                'preparing', 'prepared', 'claimed'):
            self._discard_update_resume_intent(explicit=True)
        self._cancel_ordinary_restart_intent()


    def resume_flight_photos(
        self, directory: watcher_state.Path, *, review_only: bool = False,
        continue_retained: bool = False,
    ) -> Optional[WatcherThread]:
        """Resume only unfinished photographs from the bound session queue.

        Args:
            directory (Path): Owned, connection-matching flight session folder.
            review_only (bool): Hold network admission until explicit review action.
            continue_retained (bool): Release a retained stop for safe work
                after the user's explicit Continue action.

        Returns:
            Optional[WatcherThread]: Started recoverable drain worker, or None
                when no queue exists or its durable session was force-finished.

        Raises:
            WatchRecoveryError: A durable queue or its binding cannot be
                interpreted safely.
        """
        if not type(self)._watcher_connection_selected(self):
            return None
        path = directory / "watch-drain.json"
        try:
            row = WatchQueueStore.read_journal(path)
        except FileNotFoundError:
            return None
        except (OSError, ValueError) as exc:
            raise WatchRecoveryError(
                'journal_read_failed', 'The saved photo journal could not be read.',
            ) from exc
        pending, frozen = row.get("pending"), row.get("frozen")
        if not isinstance(pending, list) or not isinstance(frozen, bool):
            raise WatchRecoveryError(
                'journal_malformed', 'Pending photograph queue is malformed.',
            )
        if row.get('force_finished') is True:
            if continue_retained:
                # The journal may have closed just before a crash prevented
                # the parent plan write. Keep that plan in explicit recovery.
                raise WatchRecoveryError(
                    'local_close_incomplete', 'Finish closing the retained capture locally.',
                )
            return None
        if not pending and frozen and (row.get('schema_version') == 1
                                      or row.get('completed') is True):
            return None
        if row.get('initial_captured', True) is not True:
            raise WatchRecoveryError(
                'initial_scan_incomplete', 'The original startup scan was interrupted.',
            )
        # A persisted Stop can precede the final scan while a new screenshot
        # is still being copied. Reconcile that cutoff even with no queued path.
        shared = watcher_state.capture_worker_config(self.config)
        config = (watcher_state.capture_worker_config(type(self)._saved_watcher_config(self))
                  if isinstance(getattr(self, '_watcher_profile', None), WatcherProfile)
                  else shared)
        config_port = self.config_store.mode_port(shared)
        folder, recursive = row.get("folder"), row.get("recursive")
        if not isinstance(folder, str) or not isinstance(recursive, bool):
            raise WatchRecoveryError(
                'folder_boundary_invalid', 'The original Watcher folder could not be verified.',
            )
        drain_folder = watcher_state.Path(folder)
        if not drain_folder.is_absolute():
            raise WatchRecoveryError(
                'folder_boundary_invalid', 'The original Watcher folder could not be verified.',
            )
        linked_target = self.capture_linked_target()
        contextual_target = getattr(self, "_contextual_watch_target", None)
        if isinstance(contextual_target, watcher_state.LinkedUploadTarget):
            if (linked_target is None or contextual_target.session is not linked_target.session
                    or contextual_target.binding() != linked_target.binding()):
                raise WatchRecoveryError(
                    'contextual_target_changed', 'The accepted Watcher gallery changed before recovery.',
                )
            linked_target = contextual_target
        if (linked_target is not None and row.get('schema_version') == 2
                and row.get('scope', '') != linked_target.session.approved_scope()):
            raise WatchRecoveryError(
                'approval_scope_changed', 'Reconnect with the original Watcher upload permissions.',
            )
        # Resume only a queue admitted under the current exact connection.
        if not any(plan.directory.resolve() == directory.resolve()
                   for plan in self.flights.pending()):
            raise WatchRecoveryError(
                'binding_changed', 'Pending photographs belong to another gallery connection.',
            )
        worker = WatcherThread(
            config, self.events, linked_target,
            gui_admission=(self.mode_owner, config_port),
            watch_folder_override=drain_folder,
            watch_recursive_override=recursive,
        )
        try:
            worker.configure_drain(directory, (), resume=True)
            worker._recovery_review_only = review_only
            worker._continue_retained = continue_retained
            worker.request_drain()
            worker.start()
        except Exception:
            if worker._linked_lease is not None:
                worker._linked_lease.release()
            raise
        return worker


    def emit_utc_activity(self, level: str, message: str) -> None:
        """Queue UTC activity without accessing Tk from a native worker.

        Args:
            level (str): Existing activity severity, info or warning.
            message (str): Bounded clock state/correction diagnostic.

        Returns:
            None: Sends the message to the existing log/activity consumer.
        """
        self.events.put((level, message))
        getattr(logging, level)(message)


    def toggle_utc_sync(self) -> None:
        """Persist just the checkbox and apply it to the active watcher.

        Returns:
            None: Keeps unsaved connection/settings edits out of persistence.
        """
        saved = type(self)._saved_watcher_config(self)
        if self.exiting or self.updates.blocked() or type(self).watcher_reset_blocked(self):
            self.sync_utc_time_var.set(saved.sync_utc_time)
            return
        enabled = self.sync_utc_time_var.get()
        # Revoke writes immediately, before even attempting a disk save.
        if not enabled:
            self.utc_sync.stop()
        try:
            type(self)._save_watcher_preferences(
                self, watcher_state.replace(saved, sync_utc_time=enabled), first_setup=False,
            )
        except (OSError, watcher_state.ModeCoordinatorError) as exc:
            self.sync_utc_time_var.set(saved.sync_utc_time)
            self.refresh_utc_sync()
            runtime.messagebox.showerror(gettext("UTC synchronization"), str(exc))
            return
        self.refresh_utc_sync()


    def refresh_utc_sync(self) -> None:
        """Bind UTC admission to this exact watcher, including tray starts.

        Returns:
            None: Starts selected work or cancels all prior UTC admission.
        """
        worker = self.worker
        if (
            type(self)._saved_watcher_config(self).sync_utc_time and not self.exiting
            and not type(self).watcher_reset_blocked(self)
            and worker is not None and worker.is_alive()
            and not worker.stop_event.is_set()
        ):
            self.utc_sync.start(
                worker.config.simconnect_dll_path,
                lambda: worker.is_alive() and not worker.stop_event.is_set(),
            )
        else:
            self.utc_sync.stop()
        self.refresh_contextual_watcher_copy()


    def refresh_contextual_watcher_copy(self) -> None:
        """Hide simulator-only controls outside a detected flight context.

        Returns:
            None: Synchronizes the contextual row and UTC status when their
                rendered values change, preserving the saved preference.
        """
        flight_detected = self.simulator_presence.flight_detected
        self.flights.refresh_contextual_labels()
        row = self.watcher_utc_row
        _publish_watcher_value(
            self, "watch_utc_visible", row, flight_detected,
            row.grid if flight_detected else row.grid_remove,
        )
        status = self.utc_sync.status()
        if (not flight_detected
                and status.startswith("UTC sync awaiting simulator:")):
            status = ""
        _publish_watcher_text(self, "utc_sync_status_var", utc_status_caption(status))


    def start_manual_upload(self) -> None:
        """
        Start the explicitly reviewed import plan.


        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.api_key_revoke_running:
            runtime.messagebox.showwarning(gettext("Connection"), gettext("Wait for API-key revocation to finish before starting manual import."))
            return
        if self.manual_worker and self.manual_worker.is_alive():
            self.write_log("Manual import is already running.")
            return
        if self.preflight_worker and self.preflight_worker.is_alive():
            runtime.messagebox.showwarning(gettext("Import"), gettext("Preflight is still running. Review the result before starting the import."))
            return
        pending_detail = getattr(self, "_import_detail_request", None)
        if pending_detail is not None:
            return
        if self.current_job is None:
            runtime.messagebox.showwarning(gettext("Import"), gettext("Choose a source and complete preflight first."))
            return

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
            runtime.messagebox.showerror(gettext("Connection"), gettext("Gallery URL and API key are required before starting an import."))
            return

        paths = [
            watcher_state.Path(item.path)
            for item in self.current_job.items
            if item.state in RECOVERABLE_ITEM_STATES and watcher_state.Path(item.path).is_file()
        ]
        if not paths:
            runtime.messagebox.showinfo(gettext("Import"), gettext("This job has no queued or recoverable files to upload."))
            self.render_preflight_plan(self.manual_plan) if self.manual_plan is not None else None
            return
        self.launch_manual_job(self.current_job, paths, config, linked_target)


    def stop_manual_upload(self) -> None:
        """
        Request cancellation of the current manual import.

        Returns:
            None: Signals cancellation and refreshes the visible controls.
        """
        if self.manual_worker and self.manual_worker.is_alive():
            self.manual_worker.stop()
            self.manual_status_var.set(gettext("Cancelling after in-flight requests"))
            self.import_activity_var.set("Import: cancelling")
            self.cancel_import_button.configure(state="disabled")
            self.write_log("Manual import cancellation requested.", "warning")
            self.record_activity(
                "warning", gettext_noop("Manual import cancellation requested."),
                "import", job_id=self.current_job.id if self.current_job else "",
            )
        self.refresh_revoke_button_state()


    def repair_semantic_ai_dependencies(self) -> None:
        """
        Install optional in-process AI packages without freezing the tray UI.

        PyTorch and Transformers are large packages and may also cause model
        downloads later. Installation is therefore explicit and confirmed.

        Returns:
            None: Starts confirmed installation or shows the current result.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if getattr(runtime.sys, "frozen", False):
            _ok, output = install_semantic_ai_dependencies_for_current_runtime()
            runtime.messagebox.showinfo(gettext("Local AI module"), gettext(output))
            return
        if self.semantic_ai_install_running:
            self.write_log("Optional local AI module dependency installation is already running.")
            return
        if not runtime.messagebox.askyesno(
            gettext("Install local AI module"),
            gettext(
                "This installs large optional Python packages such as PyTorch and Transformers into the current Python runtime. "
                "Using those backends can later download large model files. Continue?"
            ),
        ):
            return

        self.semantic_ai_install_running = True
        if self.semantic_ai_install_button is not None:
            self.semantic_ai_install_button.configure(state="disabled")
        self.write_log("Installing optional local AI module dependencies into the current Python runtime...")

        def installer() -> None:
            """Run pip installation away from the Tkinter event loop.

            Returns:
                None: Publishes the installation result to the UI event queue.
            """
            ok, output = install_semantic_ai_dependencies_for_current_runtime()
            safe_output = output.strip() if output else "pip produced no output"
            if ok:
                self.events.put(("info", "Optional local AI module dependency installation finished successfully. Restart the app before running the Transformers backend for the first time."))
            else:
                self.events.put(("error", "Optional local AI module dependency installation finished with errors: " + safe_output[:4000]))

        watcher_state.threading.Thread(target=installer, daemon=True).start()


    def start_ai_worker(self) -> None:
        """
        Start the optional AI metadata worker using current shared connection fields.


        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.linking_manager.linked_upload_mode_selected():
            runtime.messagebox.showwarning(
                gettext("AI metadata worker"),
                gettext(
                    "Linked gallery upload does not grant AI access. Switch "
                    "explicitly to the saved API-key connection to use this worker."
                ),
            )
            return
        if self.api_key_revoke_running:
            runtime.messagebox.showwarning(gettext("Connection"), gettext("Wait for API-key revocation to finish before starting the AI metadata worker."))
            return
        if self.ai_worker and self.ai_worker.is_alive():
            self.write_log("AI metadata worker is already running.")
            return

        try:
            config = self.current_config()
            if not self.connection_change_allowed(config):
                return
            config = self._save_owned_config(config)
            self.config = config
        except Exception as exc:  # noqa: BLE001
            runtime.messagebox.showerror(gettext("Configuration error"), str(exc))
            return

        if not config.ai_worker_enabled:
            runtime.messagebox.showwarning(gettext("AI metadata worker"), gettext("Enable the AI metadata worker before starting it."))
            return
        if not watcher_state.normalize_upload_url(config.gallery_url) or not config.api_key.strip():
            runtime.messagebox.showerror(gettext("Connection"), gettext("Gallery URL and API key are required before starting the AI worker."))
            return

        self.ai_worker_stop_requested = False
        self.ai_worker = create_ai_worker(self.config_store, config, self.mode_owner, self.events)
        self.ai_worker.start()
        self.ai_status_var.set(gettext("AI metadata worker running"))
        self.ai_activity_var.set("AI: running")
        self.write_log("AI metadata worker started.", "success")
        self.record_activity("success", gettext_noop("AI metadata worker started."), "ai")
        self.refresh_activity_monitor_state()
        self.refresh_revoke_button_state()


    def stop_ai_worker(self) -> None:
        """
        Request the optional AI metadata worker to stop after current work is safely reported.


        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        if self.ai_worker and self.ai_worker.is_alive():
            self.ai_worker.stop()
            self.ai_worker_stop_requested = True
            self.ai_status_var.set(gettext("AI metadata worker stopping"))
            self.ai_activity_var.set("AI: stopping")
        else:
            self.ai_activity_var.set("AI: stopped")
        self.refresh_activity_monitor_state()
        self.refresh_revoke_button_state()


    def refresh_watch_delete_warning(self) -> None:
        """Explain the active source-deletion policy in the Watcher card.

        Returns:
            None: Explains exact-byte deletion only when the preference is on.
        """
        if not hasattr(self, "watch_delete_warning"):
            return
        if self.delete_uploaded_files_var.get():
            self.watch_delete_warning.grid()
            self.watch_delete_warning.configure(
                text=gettext("Uploaded photos will be deleted from this PC."),
                style="Muted.TLabel",
            )
        else:
            self.watch_delete_warning.grid_remove()


    def resume_pending_finish(self) -> None:
        """Recover durable screenshot Finish intent after verified startup binding.

        Returns:
            None: Checks unfinished Finish sessions once per binding, keeping
                explicit interruptions paused until the user continues them.
        """
        if (not type(self)._watcher_connection_selected(self)
                or self.exiting or self.updates.blocked() or self.flights.busy()
                or self.worker is not None and self.worker.is_alive()
                or getattr(self, '_finish_resume_inspecting', False)):
            return
        try:
            mode = self.mode_owner.view()
            if (mode.state not in LEGACY_UPLOAD_STATES
                    and mode.state != 'linked_active'):
                return
            if (mode.state == 'linked_active'
                    and self.linking_manager.selected_upload_target() is None):
                return
            binding = self.flights.binding()
        except (ValueError, watcher_state.ModeCoordinatorError):
            return
        if getattr(self, '_finish_resume_checked', None) == binding:
            return
        self._finish_resume_checked = binding
        self._finish_resume_inspecting = True
        self._finish_resume_generation += 1
        generation = self._finish_resume_generation

        def inspect() -> None:
            """Read only same-target Finish evidence without starting an HTTP request.

            Returns:
                None: Queues exact-session continuation or a bounded discovery failure.
            """
            try:
                plans = self.flights.pending(binding=binding)
                eligible = []
                for plan in plans:
                    manifest = plan.directory / 'watch-drain.json'
                    if not manifest.exists():
                        continue
                    row = read_document(manifest)
                    if (row.get('schema_version') == 2
                            and row.get('binding') == binding
                            and row.get('force_finished') is not True
                            and row.get('retained_stop') is not True
                            and type(row.get('cutoff')) in (int, float)
                            and row['cutoff'] > 0 and row.get('completed') is not True):
                        eligible.append(plan.session_id)
                self.events.put({'kind': 'watch_finish_resume_ready', 'payload': {
                    'binding': binding, 'sessions': eligible,
                    'unambiguous': len(plans) == 1,
                    'generation': generation,
                }})
            except (OSError, ValueError, RuntimeError):
                self.events.put({'kind': 'watch_finish_resume_failed', 'payload': {
                    'binding': binding,
                    'generation': generation,
                }})

        watcher_state.threading.Thread(target=inspect, name='PHPGalleryFinishRecovery', daemon=True).start()


    def refresh_upload_queue(self) -> None:
        """Rescan the original queue and safely wake or replace its stopped owner.

        Returns:
            None: Coalesces same-session scans, or shows passive saved recovery
                when a restarted Watcher has no in-memory queue owner.
        """
        if not type(self)._watcher_connection_selected(self):
            return
        original = self.worker
        if original is None or original.drain_manifest is None:
            state = self.flights.session_state()
            if state in ('pending', 'blocked'):
                self.write_log(format_watch_recovery_diagnostic(
                    'refresh', 'inspecting_saved_capture',
                ), 'info')
                self.flights.show_recovery()
            else:
                self.write_log(
                    format_watch_recovery_diagnostic(
                        'refresh', 'operation_in_progress' if state == 'working'
                        else 'no_saved_queue_for_current_binding',
                    ), 'info',
                )
            return
        if original._queue is not None and original._queue.force_finished:
            self.write_log(
                'Force-finished session remains retained; refresh cannot reopen it.',
                'warning',
            )
            return
        if original.is_alive():
            original.request_refresh()
            return
        for worker in self.flights.resume_workers:
            if worker.is_alive() and worker.drain_manifest == original.drain_manifest:
                worker.request_refresh()
                return
        if self._queue_recovery_pending or self.flights.busy() or self.updates.blocked():
            self.write_log('Queue refresh merged with existing recovery/finalization.', 'info')
            return
        pending = original._pending_transport
        if pending is not None and not pending.is_set():
            self.write_log('Original transport still owns the queue; refresh cannot replace it.', 'warning')
            return
        self._queue_recovery_pending = True
        self.write_log('Queue refresh is checking the original session owner.', 'info')
        shared = watcher_state.capture_worker_config(self.config)
        config = (watcher_state.capture_worker_config(type(self)._saved_watcher_config(self))
                  if isinstance(getattr(self, '_watcher_profile', None), WatcherProfile)
                  else shared)
        admission = (self.mode_owner, self.config_store.mode_port(shared))
        target = original.linked_target
        if target is not None:
            selected = self.linking_manager.selected_upload_target()
            if (selected is not None and selected.binding() == original._queue.binding
                    and selected.session.approved_scope() == original._queue.scope):
                target = selected
        directory = original.drain_manifest.parent
        folder = original._queue.folder
        recursive = original._queue.recursive

        def prepare() -> None:
            """Prepare same-session recovery off Tk while holding original target locks.

            Returns:
                None: Queues a verified replacement or a safe failure for the UI owner.
            """
            replacement = None
            try:
                replacement = WatcherThread(
                    config, self.events, target, gui_admission=admission,
                    watch_folder_override=folder, watch_recursive_override=recursive,
                )
                replacement.configure_drain(directory, (), resume=True)
                if replacement._queue.force_finished:
                    raise ValueError('A closed Watcher queue cannot be refreshed.')
                self.events.put({'kind': 'watch_queue_recovered', 'payload': {
                    'original': original, 'worker': replacement,
                }})
            except (OSError, ValueError, RuntimeError):
                if replacement is not None and replacement._linked_lease is not None:
                    replacement._linked_lease.release()
                self.events.put({'kind': 'watch_queue_recovery_failed', 'payload': {
                    'original': original,
                }})

        watcher_state.threading.Thread(target=prepare, name='PHPGalleryQueueRecovery', daemon=True).start()


    def close_retained_watch(
        self, plan: SessionPlan, on_result: Callable[[bool], None],
    ) -> None:
        """Close an inactive Watcher capture using its original local ownership.

        Args:
            plan (SessionPlan): Exact unfinished session confirmed in recovery.
            on_result (Callable[[bool], None]): Tk callback receiving whether
                durable journal and plan closure were confirmed.
        Returns:
            None: Keeps files and receipts while closing the matching local
                session and logs the phase of any refused or failed closure.
        """
        if (type(self).watcher_reset_blocked(self) or self.exiting
                or self.updates.blocked() or self._queue_recovery_pending
                or self.background_work_active() or self.flights.is_alive()
                or (self.worker is not None and self.worker.is_alive())
                or any(worker.is_alive() for worker in self.flights.resume_workers)):
            self.write_log(format_watch_recovery_diagnostic(
                'local_close', 'work_or_shutdown_in_progress',
            ), 'warning')
            on_result(False)
            return
        lease = self.flights.lease
        workspace = self.flights.directory
        try:
            if (lease is None or plan.completed or plan.directory.parent != workspace
                    or self.flights.binding() != plan.binding):
                self.write_log(format_watch_recovery_diagnostic(
                    'local_close', 'retained_plan_not_current',
                ), 'warning')
                on_result(False)
                return
        except ValueError as exc:
            self.write_log(format_watch_recovery_diagnostic(
                'local_close', 'saved_binding_unverified', error=exc,
            ), 'warning')
            on_result(False)
            return
        linked = self.linking_manager.linked_upload_mode_selected()
        cutoff = watcher_state.time.time()
        self._finish_resume_generation += 1
        self._cancel_ordinary_restart_for_action()

        def close() -> None:
            """Publish the original capture closure under existing writer fences.

            Returns:
                None: Queues the verified close result for the Tk event owner.
            """
            error_type = ''
            step = 'pipeline_lease'
            diagnostic = ''

            def observe(stage: str) -> None:
                """Keep the exact failure stage for existing recovery diagnostics.

                Args:
                    stage (str): Fixed closure service step.
                Returns:
                    None: Updates only the running close's diagnostic state.
                """
                nonlocal step
                step = stage

            try:
                persist_retained_capture_closure(watcher_state.CONFIG_DIR, lease, plan, linked, cutoff, observe)
            except Exception as exc:  # noqa: BLE001
                error_type = type(exc).__name__
                diagnostic = format_watch_recovery_diagnostic(
                    'local_close', f'{step}_failed', error=exc,
                )
            self.events.put({
                'kind': 'retained_watch_closed',
                'payload': {
                    'plan': plan, 'error_type': error_type, 'on_result': on_result,
                    'diagnostic': diagnostic,
                },
            })

        try:
            watcher_state.threading.Thread(
                target=close, name='PHPGalleryCloseRetainedWatch', daemon=True,
            ).start()
        except RuntimeError as exc:
            self.write_log(format_watch_recovery_diagnostic(
                'local_close', 'publisher_start_failed', error=exc,
            ), 'warning')
            on_result(False)


    def dry_run_watch_folder(self) -> None:
        """Inspect a selected watch folder on a background thread.

        Returns:
            None: Queues separate findings without changing Watcher state.
        """
        if type(self).watcher_reset_blocked(self):
            return
        selected = self.watched_folder_var.get().strip()
        folder = watcher_state.Path(selected)
        if not selected or not folder.is_dir():
            runtime.messagebox.showwarning(
                recovery_text(self.flights.ui_language.get(), 'watch_inspection_title'),
                recovery_text(
                    self.flights.ui_language.get(), 'watch_inspection_missing',
                ).format(folder=selected),
            )
            return

        recursive = bool(self.watch_recursive_var.get())

        def inspect() -> None:
            """Collect read-only findings without touching the active queue.

            Returns:
                None: Publishes findings or a bounded inspection error.
            """
            try:
                result = discover_folder(folder, watcher_state.SUPPORTED_SUFFIXES, recursive)
                self.events.put({
                    'kind': 'watch_folder_inspection_complete',
                    'payload': {
                        'folder': str(folder), 'supported': len(result.items),
                        'unsupported': sum(result.unsupported.values()),
                        'error': '',
                    },
                })
            except (OSError, ValueError, RuntimeError):
                self.events.put({
                    'kind': 'watch_folder_inspection_complete',
                    'payload': {
                        'folder': str(folder), 'supported': 0,
                        'unsupported': 0,
                        'error': 'failed',
                    },
                })

        watcher_state.threading.Thread(
            target=inspect, name='PHPGalleryFolderInspection', daemon=True,
        ).start()


    def open_watch_recovery_review(self) -> None:
        """Open recovery for the worker that still owns the exact session.

        Returns:
            None: Presents an immutable queue head only when its leases are held.
        """
        worker = self.worker
        coordinator = (getattr(worker, 'recovery_coordinator', None)
                       if worker is not None else None)
        if worker is not None and not self._watch_recovery_owner_held(worker):
            # run() releases both leases before a dead worker can be reviewed.
            coordinator = None
        if coordinator is None:
            if worker is not None and worker.is_alive():
                self.root.after(
                    WATCHER_EVENT_IDLE_POLL_MS,
                    lambda: self._open_recovery_when_ready(worker),
                )
                return
            directory = (worker.drain_manifest.parent
                         if worker is not None and worker.drain_manifest is not None
                         else None)
            if directory is None:
                try:
                    plans = self.flights.pending(binding=self.flights.binding())
                except (OSError, ValueError, RuntimeError):
                    plans = []
                if len(plans) == 1:
                    directory = plans[0].directory
            if directory is None:
                self.write_log('No unambiguous pending Watcher session is available.', 'warning')
                return
            try:
                resumed = self.resume_flight_photos(directory, review_only=True)
            except (OSError, ValueError, RuntimeError) as exc:
                self.write_log('Pending Watcher session needs review: ' + str(exc), 'warning')
                return
            if resumed is None:
                self.write_log('No pending uploads remain in the original session.', 'info')
                return
            self.worker = resumed
            self.write_log('Resumed the exact pending Watcher session for review.', 'info')
            self.root.after(
                WATCHER_EVENT_IDLE_POLL_MS,
                lambda: self._open_recovery_when_ready(resumed),
            )
            return
        self._show_watch_recovery_dialog(worker, coordinator)


    def _open_recovery_when_ready(self, worker: WatcherThread) -> None:
        """Wait for restored worker ownership before presenting its review.

        Args:
            worker (WatcherThread): Exact resumed session requiring review.

        Returns:
            None: Opens review only after the worker has its shared lease.
        """
        if self.worker is not worker or self.exiting:
            return
        coordinator = worker.recovery_coordinator
        if coordinator is None or not self._watch_recovery_owner_held(worker):
            if worker.is_alive():
                self.root.after(
                    WATCHER_EVENT_IDLE_POLL_MS,
                    lambda: self._open_recovery_when_ready(worker),
                )
            return
        self._show_watch_recovery_dialog(worker, coordinator)


    @staticmethod
    def _watch_recovery_owner_held(worker: WatcherThread) -> bool:
        """Check whether the worker still owns every required recovery lease.

        Args:
            worker (WatcherThread): Current worker associated with a coordinator.

        Returns:
            bool: True while its pipeline and any linked target lock remain held.
        """
        return watch_recovery_owner_held(worker)


    def _show_watch_recovery_dialog(
        self, worker: WatcherThread, coordinator: WatchRecoveryCoordinator,
    ) -> None:
        """Present a recovery dialog for one exact worker-owned queue.

        Args:
            worker (WatcherThread): Session currently owning both local leases.
            coordinator (WatchRecoveryCoordinator): Lease-bound decision service.

        Returns:
            None: Opens the localized dialog and binds its wake callback.
        """
        target = worker.linked_target
        target_label = ('Linked gallery' if target is not None else 'Configured gallery')
        if target is not None:
            target_label = str(target.title)
        language = self.flights.ui_language.get()
        self._watch_recovery_dialog = WatchRecoveryReviewDialog(
            self.root, coordinator, language, target_label,
            on_resolved=lambda: self._watch_recovery_resolved(worker),
            remote_loader=worker.load_watch_recovery_remote,
        )


    def _watch_recovery_resolved(self, worker: WatcherThread) -> None:
        """Refresh queue state and wake only the reviewed original consumer.

        Args:
            worker (WatcherThread): Exact session owner shown in the dialog.

        Returns:
            None: Updates compact status and wakes its FIFO consumer.
        """
        if self.worker is not worker or worker._queue is None:
            return
        if not worker.is_alive() or worker.stop_event.is_set():
            return
        worker.apply_recovery_decision()
        if (not worker._recovery_review_only
                and getattr(self, '_queue_recovery_finish_requested', False)):
            self._queue_recovery_finish_requested = False
            self._next_finish_trigger_reason = 'recovery_resolved_finish'
            self.flights.begin(
                False, automatic=True,
                photos_only=(worker.linked_target is not None
                             and not self.contextual_watcher_is_flight()),
            )


    def stop_and_keep_watch_pending(
        self, on_persisted: Callable[[bool], None] | None = None,
    ) -> bool:
        """Persist cutoff and cancellation while retaining any live transport.

        Args:
            on_persisted (Callable[[bool], None] | None): UI callback receiving
                the durable readback result after background publication.

        Returns:
            bool: True when publication was scheduled for the active session.
        """
        worker = self.worker
        if worker is None or worker._queue is None:
            if on_persisted is not None:
                on_persisted(False)
            return False
        if (not worker.is_alive()
                or not self._watch_recovery_owner_held(worker)):
            if on_persisted is not None:
                on_persisted(False)
            return False
        if worker._retained_stop_publishing.is_set():
            return True
        cutoff = worker.begin_retained_stop()
        self._set_watcher_recording(False)
        self.status_var.set(recovery_text(
            self.flights.ui_language.get(), 'watch_stop_stopping',
        ))

        def persist_stop() -> None:
            """Persist retained cutoff without blocking Tk or releasing transport.

            Returns:
                None: Reports durable retention outcome to the UI event owner.
            """
            try:
                worker.publish_retained_stop(cutoff)
                payload = {'worker': worker, 'error': ''}
            except Exception:  # noqa: BLE001
                payload = {
                    'worker': worker,
                    'error': 'Pending uploads could not be retained safely.',
                }
            payload['on_persisted'] = on_persisted
            self.events.put({
                'kind': 'watch_stop_retained', 'payload': payload,
            })

        try:
            watcher_state.threading.Thread(
                target=persist_stop, name='PHPGalleryRetainWatcherStop', daemon=True,
            ).start()
        except RuntimeError:
            worker._retained_stop_publishing.clear()
            worker._retained_stop_publication_done.set()
            if on_persisted is not None:
                on_persisted(False)
            return False
        return True


    def force_finish_flight(
        self, on_result: Callable[[str], None],
    ) -> bool:
        """Persist captured Finish abandonment while retaining transport ownership.

        Args:
            on_result (Callable[[str], None]): Tk callback receiving persisted,
                pending_transport or failed after exact durable session readback.

        Returns:
            bool: True when this exact operation's abandonment was scheduled;
                False only when the finalization operation is unavailable.
        """
        operation = self.flights.operation
        if operation is None:
            on_result('failed')
            return False
        self._prune_force_finished_workers()
        cutoff = float(getattr(operation, '_force_finish_cutoff', 0.0) or 0.0)
        if cutoff <= 0:
            cutoff = watcher_state.time.time()
            operation._force_finish_cutoff = cutoff
        plans = list(operation.plans)
        current_plan = getattr(self.flights, 'plan', None)
        if current_plan is not None and all(
                plan.directory != current_plan.directory for plan in plans):
            plans.append(current_plan)
        unique_plans = {
            plan.directory.resolve(): plan for plan in plans
        }
        plan_rows = list(unique_plans.values())
        flight_lease = self.flights.lease
        current_worker = self.worker
        resume_workers = [
            worker for worker in self.flights.resume_workers
            if worker is not None and worker.drain_manifest is not None
            and worker.drain_manifest.parent.resolve() in unique_plans
        ]
        capture_workers = list(dict.fromkeys(
            worker for worker in (current_worker, *resume_workers)
            if worker is not None
        ))
        workers_by_directory = {
            worker.drain_manifest.parent.resolve(): worker
            for worker in capture_workers
            if worker.drain_manifest is not None and worker._queue is not None
        }
        session_workers = [
            worker for worker in capture_workers
            if worker.drain_manifest is not None and worker._queue is not None
        ]
        publishing_workers = [
            worker for worker in capture_workers
            if worker._force_finish_publishing.is_set()
        ]
        callbacks = self._force_finish_callbacks.setdefault(id(operation), [])
        if publishing_workers:
            if all(worker._force_finish_operation_id == id(operation)
                   for worker in publishing_workers):
                callbacks.append(on_result)
                return True
            self._force_finish_callbacks.pop(id(operation), None)
            on_result('failed')
            return False
        callbacks.append(on_result)
        for worker in capture_workers:
            with worker._force_finish_lease_lock:
                worker._force_finish_publishing.set()
                worker._force_finish_publication_done.clear()
                worker._force_finish_operation_id = id(operation)
                worker._force_finish_requested.set()
                worker._force_stop_requested.set()
                worker._keep_sources_after_stop = True
                worker._producer_stop.set()
                worker._producer_wake.set()
                worker._diagnostic_phase = 'force_finish_persisting'
        if capture_workers:
            self._set_watcher_recording(False)
        self._queue_recovery_finish_requested = False
        self._finish_resume_generation += 1
        self._cancel_ordinary_restart_for_action()
        self.flights.stop()
        self.status_var.set(recovery_text(
            self.flights.ui_language.get(), 'force_finish_saving',
        ))

        def persist_force_finish() -> None:
            """Publish queue and parent flight abandonment off Tk.

            Returns:
                None: Acquires missing writer fences, then reports after every
                    captured queue and parent plan verifies.
            """
            error = ''
            error_type = ''
            temporary_pipeline: UploadPipelineLease | None = None
            temporary_linked: list[watcher_state.LinkedTargetLease] = []
            try:
                queue_targets: Dict[watcher_state.Path, tuple[str, str]] = {}
                queue_rows: Dict[watcher_state.Path, Dict[str, JsonValue]] = {}
                for worker in session_workers:
                    if worker.drain_manifest is None or worker._queue is None:
                        continue
                    queue_path = worker.drain_manifest.parent.resolve()
                    queue_targets[queue_path] = (
                        worker._queue.binding, worker._queue.scope,
                    )
                handled_queues: set[watcher_state.Path] = set()
                for plan in plan_rows:
                    manifest = plan.directory / 'watch-drain.json'
                    queue_path = plan.directory.resolve()
                    if manifest.exists() and queue_path not in queue_targets:
                        row = read_document(manifest)
                        folder = row.get('folder')
                        recursive = row.get('recursive')
                        scope = row.get('scope', '')
                        if (row.get('binding') != plan.binding
                                or not isinstance(folder, str)
                                or not watcher_state.Path(folder).is_absolute()
                                or not isinstance(recursive, bool)
                                or not isinstance(scope, str)):
                            raise ValueError('Force Finish queue identity changed.')
                        queue_rows[queue_path] = row
                        queue_targets[queue_path] = (plan.binding, scope)
                if queue_targets:
                    pipeline_held = False
                    for worker in capture_workers:
                        native = getattr(
                            getattr(worker, '_pipeline_lease', None),
                            '_native', None,
                        )
                        if native is not None:
                            native.verify()
                            pipeline_held = True
                    if not pipeline_held:
                        temporary_pipeline = UploadPipelineLease(watcher_state.CONFIG_DIR)
                        temporary_pipeline.acquire()
                    held_linked: set[str] = set()
                    for worker in capture_workers:
                        if worker.linked_target is None:
                            continue
                        native = getattr(
                            getattr(worker, '_linked_lease', None),
                            '_native', None,
                        )
                        if native is not None:
                            native.verify()
                            held_linked.add(worker.linked_target.binding())
                    for binding, scope in queue_targets.values():
                        if scope and binding not in held_linked:
                            lease = watcher_state.LinkedTargetLease(watcher_state.CONFIG_DIR, binding)
                            lease.acquire()
                            temporary_linked.append(lease)
                            held_linked.add(binding)
                for worker in session_workers:
                    manifest = worker.drain_manifest
                    store = worker._queue
                    if manifest is None or store is None:
                        continue
                    store.force_finish(cutoff)
                    if (not store.force_finished
                            and store.data.get('completed') is not True):
                        raise OSError('Force-finished queue marker did not read back.')
                    handled_queues.add(manifest.parent.resolve())
                for plan in plan_rows:
                    manifest = plan.directory / 'watch-drain.json'
                    queue_path = plan.directory.resolve()
                    if manifest.exists() and queue_path not in handled_queues:
                        owner = workers_by_directory.get(queue_path)
                        if owner is not None:
                            store = owner._queue
                        else:
                            row = queue_rows[queue_path]
                            store = WatchQueueStore(
                                manifest, plan.binding, watcher_state.Path(str(row['folder'])),
                                row['recursive'] is True,
                                scope=str(row.get('scope', '')),
                            )
                            store.restore()
                        store.force_finish(cutoff)
                        if (not store.force_finished
                                and store.data.get('completed') is not True):
                            raise OSError('Force-finished queue marker did not read back.')
                        handled_queues.add(queue_path)
                for directory in {
                    worker.drain_manifest.parent.resolve()
                    for worker in session_workers
                    if worker.drain_manifest is not None
                }:
                    if any(plan.directory.resolve() == directory
                           for plan in plan_rows):
                        continue
                    completion = directory / 'completion.json'
                    if not completion.exists():
                        continue
                    plan = SessionPlan.load(directory)
                    worker = workers_by_directory.get(directory)
                    if (worker is None or worker._queue is None
                            or plan.binding != worker._queue.binding):
                        raise ValueError('Force Finish flight identity changed.')
                    if flight_lease is None:
                        raise ValueError('Force Finish flight workspace is not owned.')
                    plan.persist_abandoned(flight_lease)
                for plan in plan_rows:
                    if flight_lease is None:
                        raise ValueError('Force Finish flight workspace is not owned.')
                    plan.persist_abandoned(flight_lease)
            except Exception as exc:  # noqa: BLE001
                error = 'Force Finish could not be durably recorded.'
                error_type = type(exc).__name__
            finally:
                for lease in reversed(temporary_linked):
                    try:
                        lease.release()
                    except Exception as exc:  # noqa: BLE001
                        error = 'Force Finish could not release its recovery fence safely.'
                        error_type = type(exc).__name__
                if temporary_pipeline is not None:
                    try:
                        temporary_pipeline.release()
                    except Exception as exc:  # noqa: BLE001
                        error = 'Force Finish could not release its recovery fence safely.'
                        error_type = type(exc).__name__
                for worker in capture_workers:
                    with worker._force_finish_lease_lock:
                        worker._force_finish_publishing.clear()
                        worker._force_finish_publication_done.set()
            pending = any(
                worker._transport_active.is_set()
                or worker._pending_transport is not None
                and not worker._pending_transport.is_set()
                for worker in capture_workers
            )
            if not error:
                for worker in capture_workers:
                    worker._diagnostic_phase = 'force_finished'
                    worker.stop()
            self.events.put({
                'kind': 'watch_force_finished',
                'payload': {
                    'worker': capture_workers[0] if capture_workers else None,
                    'workers': capture_workers, 'operation': operation,
                    'error': error, 'pending_transport': pending,
                    'error_type': error_type,
                    'cutoff': cutoff,
                    'plan_sessions': [plan.session_id for plan in plan_rows],
                    'operation_generation': getattr(
                        operation, 'flight_generation', id(operation),
                    ),
                    'operation_trigger': getattr(
                        operation, 'trigger_reason', 'unknown',
                    ),
                },
            })

        try:
            watcher_state.threading.Thread(
                target=persist_force_finish,
                name='PHPGalleryForceFinish', daemon=True,
            ).start()
        except RuntimeError:
            for worker in capture_workers:
                with worker._force_finish_lease_lock:
                    worker._force_finish_publishing.clear()
                    worker._force_finish_publication_done.set()
            failed_callbacks = self._force_finish_callbacks.pop(
                id(operation), [],
            )
            for callback in failed_callbacks:
                callback('failed')
            return False
        return True
