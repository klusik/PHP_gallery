# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/desktop.py
# Module Type: Windows Desktop Controller
# Purpose: Coordinate native flight capture and desktop configuration lifecycle.
# Responsibilities:
#   - Own Qt capture, drain, recovery and finalization behavior.
#   - Persist desktop configuration through shared Watcher state and mode owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own desktop capture coordination and its saved preferences."""

from dataclasses import replace
from pathlib import Path
from typing import Callable
from uploader import update_helper
from uploader.config import (
    BYTES_PER_GIB,
    MAX_THUMBNAIL_WORKERS,
    MAX_UPLOAD_WORKERS,
)
from uploader.desktop_connection import (
    ConnectionActions,
    ConnectionTestResult,
    LinkedRecoveryActions,
    RuntimeSettings,
    RuntimeValues,
    open_saved_upload_session,
)
from uploader.diagnostics import redact_text
from uploader.flight_dispatch_owner import (
    capture_saved_dispatch_target,
    persist_saved_dispatch,
)
from uploader.flight_jobs import (
    FlightPreferences,
    ImportBindings,
    SessionPlan,
    WatcherContinuation,
    WorkspaceLease,
    connection_binding,
    pending_sessions,
    read_document,
    report_revisions,
)
from uploader.flight_navdata import remember_plan
from uploader.flight_recorder import FlightRecorder
from uploader.flight_report_translations import (
    recovery_text,
    tr,
)
from uploader.flight_simbrief import (
    DispatchPlan,
    fetch_dispatch,
    import_dispatch,
    load_dispatch,
)
from uploader.flight_workflow import (
    FINALIZATION_DRAIN_SECONDS,
    FinalizationWorker,
    WatchedReportTarget,
    create_local_report_worker,
)
from uploader.http_transport import (
    normalize_upload_url,
    post_json_resolved,
    revoke_upload_key,
)
from uploader.linked_upload import (
    LinkedUploadTarget,
    linked_target_binding,
    verify_linked_upload_target,
)
from uploader.localization import (
    current_language,
    gettext_noop,
)
from uploader.mode_admission import LEGACY_UPLOAD_STATES
from uploader.mode_coordinator import (
    CoordinatedWork,
    GuiModeOwner,
    ModeConfigPort,
    ModeCoordinatorError,
    ModeView,
)
from uploader.simconnect_time import UtcSyncManager
from uploader.state_store import (
    JobStateStore,
    accepted_work_owner,
    atomic_write_json,
)
from uploader.upload_mode import ModeRecord
from uploader.watcher.capture_session import (
    CaptureDecision,
    CaptureReview,
    CaptureStatus,
    RetainedQueueSummary,
    WatchFolderInspection,
    inspect_retained_queue,
    inspect_watch_folder,
    persist_retained_capture_closure,
    prepare_capture_recording,
    verify_unfrozen_capture,
    watcher_interrupt_available,
)
from uploader.watcher.profile import (
    WATCHER_ONCE_LOCK,
    WATCHER_PREFERENCE_TYPES,
    WatcherProfile,
    _local_entry,
    discard_capture_queues,
    discard_once_queues,
)
from uploader.watcher.queue import WatchQueueStore
from uploader.watcher.recovery import (
    WatchRecoveryRemote,
    read_recovery_preview,
)
from uploader.watcher.state import (
    AI_DETECTION_RANGE,
    AI_WORKER_MAX_LEASE_SECONDS,
    AI_WORKER_MIN_LEASE_SECONDS,
    AI_WORKER_MIN_POLL_SECONDS,
    CONFIG_DIR,
    ConfigStore,
    MIN_INTERVAL_SECONDS,
    MIN_INVENTORY_REFRESH_SECONDS,
    MIN_STABLE_SECONDS,
    STAGING_RETENTION_RANGE,
    STATE_PATH,
    SUPPORTED_SUFFIXES,
    WatcherConfig,
    ZIP_ENTRY_RANGE,
    ZIP_EXPANSION_RANGE,
    ZIP_FILE_RANGE,
    ZIP_TOTAL_RANGE,
    _ConfigModePort,
    capture_worker_config,
    probe_linked_upload_outcome,
    publish_linked_upload_observation,
    review_linked_upload_marker,
)
from uploader.watcher.workers.watcher import (
    WATCHER_CONTINUATION_ADMISSION_TIMEOUT_SECONDS,
    WatcherThread,
)
from uploader.windows_game_processes import msfs_running
from uploader.windows_startup import synchronize_startup
import logging
import math
import queue
import sqlite3
import threading
import time
import uuid
from . import runtime, state as watcher_state
from .workers import watcher as watcher_worker
from .update_policy import UPDATE_CHECKPOINT_TIMEOUT_SECONDS
from .workers.watcher import WATCHER_CONTINUATION_ADMISSION_TIMEOUT_SECONDS
from .event_policy import WATCHER_EVENT_BATCH_LIMIT
from .configuration import DesktopConfiguration

# Purpose: Bound unconsumed Watcher events while the desktop is repainting.
# Type: int. Units: events. Scope: one Qt capture owner.
# Consumers: DesktopCapture.events. Rationale: Four ordinary event batches
# give the producer room to complete a scan while preserving ordered results.
CAPTURE_EVENT_CAPACITY = WATCHER_EVENT_BATCH_LIMIT * 4

# Purpose: Observe the durable photo and recorder drain on its worker thread.
# Type: float. Units: seconds. Scope: one Qt finalization barrier.
# Consumers: DesktopCapture._wait_for_drain. Rationale: A tenth of a second
# matches the cadence of the stable Flight desktop drain.
CAPTURE_DRAIN_POLL_SECONDS = 0.1

class DesktopCapture:
    """Own native capture commands using the existing recorder and FIFO worker."""

    def __init__(
        self, store: watcher_state.ConfigStore, saved: Callable[[], watcher_state.WatcherConfig], owner: GuiModeOwner,
        target: Callable[[], LinkedUploadTarget | None],
        reconnect: Callable[[], None] | None = None,
        persist_revoked_config: Callable[[watcher_state.WatcherConfig, CoordinatedWork], watcher_state.WatcherConfig] | None = None,
        persist_abandoned_config: Callable[[watcher_state.WatcherConfig, str], watcher_state.WatcherConfig] | None = None,
        persist_watcher_config: Callable[[watcher_state.WatcherConfig], watcher_state.WatcherConfig] | None = None,
    ) -> None:
        """Bind to the same saved configuration and mode owner as the other views.

        Args:
            store (ConfigStore): Existing owner of saved host settings.
            saved (Callable[[], WatcherConfig]): Exact current persisted snapshot.
            owner (GuiModeOwner): Shared admission for credentials and worker effects.
            target (Callable[[], LinkedUploadTarget | None]): Currently verified
                linked upload target when the saved mode is linked.
            reconnect (Callable[[], None] | None): Renew the same approved
                native grant when continuing a verified restart.
            persist_revoked_config (Callable[[WatcherConfig, CoordinatedWork], WatcherConfig] | None):
                Existing host transaction for the exact confirmed key cleanup.
            persist_abandoned_config (Callable[[WatcherConfig, str], WatcherConfig] | None):
                Existing host transaction for local cleanup after plan abandonment.
            persist_watcher_config (Callable[[WatcherConfig], WatcherConfig] | None):
                Existing host transaction for one shared Watcher preference.
        Returns:
            None: Creates an idle owner without opening any native connection.
        """
        self.store = store
        self.saved = saved
        self.owner = owner
        self.target = target
        self.reconnect = reconnect
        self.persist_revoked_config = persist_revoked_config
        self.persist_abandoned_config = persist_abandoned_config
        self.persist_watcher_config = persist_watcher_config
        self.events: CaptureEvents = queue.Queue(maxsize=CAPTURE_EVENT_CAPACITY)
        self.root = watcher_state.CONFIG_DIR / 'flights'
        self.lease: WorkspaceLease | None = None
        self.worker: WatcherThread | None = None
        self.recorder: FlightRecorder | None = None
        self.finalizer: FinalizationWorker | None = None
        self.utc = UtcSyncManager(self.emit)
        self.dispatch: DispatchPlan | None = None
        self.active_dispatch: DispatchPlan | None = None
        self.capture_closed = False
        self.more_retained = False
        self._worker_transition = threading.Lock()
        self.restart_record: WatcherContinuation | None = None
        self.restart_saved = False
        self.restart_ordinary = True
        self.reset_completed = False
        self.contextual_target: LinkedUploadTarget | None = None
        self._context_cleanup: threading.Thread | None = None
        self._context_cleanup_failed = False
        self._context_cleanup_due = False
        self._context_starting = False
        self._revocation_operation: FinalizationWorker | None = None
        self._abandon_config: watcher_state.WatcherConfig | None = None
        self._linked_abandon_session_path: Path | None = None
        profile = WatcherProfile.load(self.root)
        self._utc_sync_enabled = (
            profile.preferences['sync_utc_time'] if profile is not None
            else self.saved().sync_utc_time
        )

    def emit(self, level: str, message: str) -> None:
        """Send status to the current desktop and shared log.

        Args:
            level (str): Worker activity severity.
            message (str): Existing worker's operator-visible caption.
        Returns:
            None: Queues the redacted caption without invoking GUI widgets.
        """
        context = self.contextual_target
        secrets = [self.saved().api_key.strip()]
        if context is not None and context.watch_key is not None:
            secrets.append(context.watch_key)
        message = redact_text(message, secrets, redact_paths=False)
        self.events.put((level, message))
        logging.log(logging.WARNING if level == 'warning' else
                    logging.ERROR if level == 'error' else logging.INFO, message)

    def _connection(
        self,
    ) -> tuple[watcher_state.WatcherConfig, ModeConfigPort, LinkedUploadTarget | None, str]:
        """Capture exact saved credentials and Watcher-profile overrides.

        Returns:
            tuple[WatcherConfig, ModeConfigPort, LinkedUploadTarget | None, str]:
                Immutable Watcher configuration, mode port, bound target and
                its immutable destination fingerprint.
        """
        config = self.saved()
        shared = capture_worker_config(config)
        port = self.store.mode_port(shared)
        mode = self.owner.view()
        target = None
        if mode.state in LEGACY_UPLOAD_STATES:
            url, key = normalize_upload_url(config.gallery_url), config.api_key.strip()
            if not url or not key:
                raise ValueError('Gallery URL and API key are required before starting the watcher.')
            binding = connection_binding(url, key)
        elif mode.state == 'linked_active':
            target = self.target()
            if target is None or not target.session.can_upload():
                raise ValueError('Reconnect the selected gallery before continuing.')
            binding = target.binding()
        else:
            raise ValueError('The saved upload connection could not be verified.')
        context = self.contextual_target
        if context is not None:
            if (target is None or context.binding() != target.binding()
                    or context.session is not target.session):
                raise ValueError('Reconnect the selected gallery before continuing.')
            target = context
        profile = WatcherProfile.load(self.root)
        if profile is not None:
            if profile.pending or profile.binding != binding:
                raise ValueError('The saved Watcher configuration belongs to another connection.')
            shared = capture_worker_config(replace(config, **profile.preferences))
        return shared, port, target, binding

    def _workspace(self) -> WorkspaceLease:
        """Verify the exact flight workspace before a lifecycle command writes.

        Returns:
            WorkspaceLease: The current owner's verified lease.
        """
        if self.lease is None:
            self.lease = WorkspaceLease(self.root)
        self.lease.verify()
        return self.lease

    def start(self) -> None:
        """Start one durable capture after validating the connection and preferences.

        Returns:
            None: Owns the recorder, photo worker and optional UTC worker.
        """
        if self.snapshot().alive or self._context_cleanup_failed:
            raise RuntimeError('Wait for the current operation to finish.')
        self._workspace()
        config, port, target, binding = self._connection()
        if pending_sessions(self.root, binding):
            raise ValueError('Review pending upload')
        if not config.watched_folder or not Path(config.watched_folder).is_dir():
            raise ValueError('Choose a folder to watch before starting.')
        preferences = FlightPreferences.load(self.root)
        kind = 'linked' if target is not None else 'legacy'
        with self.owner.acquire(kind, port):
            worker = WatcherThread(config, self.events, target, gui_admission=(self.owner, port))
            recorder, plan = prepare_capture_recording(
                self.root, preferences, binding, (
                    target.issuer + ' #' + str(target.gallery_id)
                    if target is not None else normalize_upload_url(config.gallery_url)
                ), config.simconnect_dll_path, self.emit, dispatch=self.dispatch, factory=FlightRecorder,
                photos_only=target is not None and target.watch_key is not None and not msfs_running(),
            )
            try:
                worker.configure_drain(plan.directory, None)
                self.recorder, self.worker, self.finalizer = recorder, worker, None
                self.capture_closed = False
                self.reset_completed = False
                recorder.start()
                worker.start()
                self.active_dispatch, self.dispatch = self.dispatch, None
            except Exception:
                recorder.stop()
                if worker._linked_lease is not None and not worker.is_alive():
                    worker._linked_lease.release()
                raise
        self._utc_sync_enabled = config.sync_utc_time
        self.refresh_utc_sync()

    def refresh_utc_sync(self) -> None:
        """Bind the saved opt-in to this exact admitted Watcher worker.

        Returns:
            None: Stops old admission or starts the existing UTC manager.
        """
        worker = self.worker
        if (self._utc_sync_enabled and worker is not None and worker.is_alive()
                and not worker.stop_event.is_set() and not worker.drain_event.is_set()):
            try:
                self.utc.start(
                    worker.config.simconnect_dll_path,
                    lambda: worker.is_alive() and not worker.stop_event.is_set()
                    and not worker.drain_event.is_set(),
                )
            except RuntimeError:
                # The photo worker owns the capture after start; clock failure
                # cannot revoke that admitted work.
                self.emit('warning', gettext_noop('UTC sync stopped'))
        else:
            self.utc.stop()

    def set_utc_sync(self, enabled: bool) -> bool:
        """Save the UTC opt-in and refresh the existing active manager.

        Args:
            enabled (bool): Requested Watcher UTC correction preference.
        Returns:
            bool: Effective saved opt-in after persistence.
        Raises:
            OSError: The current Watcher preference owner could not save.
            RuntimeError: Finalization, reset or profile ownership blocks change.
        """
        if type(enabled) is not bool:
            raise ValueError('UTC synchronization choice is invalid.')
        state = self.snapshot()
        if state.finishing or self._revocation_operation is not None and self._revocation_operation.is_alive():
            raise RuntimeError('Wait for the current operation to finish.')
        previous = self._utc_sync_enabled
        if not enabled:
            self.utc.stop()
        try:
            profile = WatcherProfile.load(self.root)
            if profile is not None:
                if profile.pending:
                    raise RuntimeError('Watcher profile is unavailable.')
                values = dict(profile.preferences)
                values['sync_utc_time'] = enabled
                proposed_profile = replace(profile, preferences=values)
                if proposed_profile != profile:
                    proposed_profile.save(self._workspace())
            else:
                current = self.saved()
                if current.sync_utc_time != enabled:
                    if self.persist_watcher_config is None:
                        raise RuntimeError('Watcher settings owner is unavailable.')
                    self.persist_watcher_config(replace(current, sync_utc_time=enabled))
        except (OSError, RuntimeError, ValueError):
            if previous:
                self.refresh_utc_sync()
            raise
        self._utc_sync_enabled = enabled
        self.refresh_utc_sync()
        return self._utc_sync_enabled

    def start_contextual(self, target: LinkedUploadTarget) -> None:
        """Retain one exact Photos Watcher credential while its worker owns work.

        Args:
            target (LinkedUploadTarget): Current verified linked target and temporary key.
        Returns:
            None: Takes ownership after the ordinary capture start succeeds.
        """
        if self.contextual_target is not None or self.snapshot().alive or target.watch_key is None:
            raise RuntimeError('Wait for the current operation to finish.')
        self._context_starting = True
        self.contextual_target = target
        self._context_cleanup_due = False
        try:
            self.start()
        except BaseException:
            self.contextual_target = None
            raise
        finally:
            self._context_starting = False

    def retry_context_cleanup(self) -> None:
        """Close a stopped capture's exact key through its original live session.

        Returns:
            None: Releases only that in-memory credential after server confirmation.
        """
        if self.snapshot().alive:
            raise RuntimeError('Wait for the current operation to finish.')
        self._close_contextual_access()

    def _close_contextual_access(self) -> None:
        """Wait for server confirmation of the exact owned temporary key closure.

        Returns:
            None: Releases the successful generation or retains it for retry.
        """
        target = self.contextual_target
        if target is None or target.watch_key is None:
            return
        try:
            target.session.close_watch_key(target.gallery_id, target.watch_key)
        except (OSError, RuntimeError, ValueError):
            self._context_cleanup_failed = True
            raise ValueError(gettext_noop('Watcher access could not be closed. Retry.')) from None
        if self.contextual_target is target:
            self.contextual_target = None
        self._context_cleanup_failed = False
        self._context_cleanup_due = False

    def finish(self) -> None:
        """Stop new capture and finalize the exact saved queue and report plan.

        Returns:
            None: Starts the shared finalizer or retains a refused session.
        """
        self._finish(revoke=False)

    def revoke_legacy(self) -> None:
        """Start the existing Finish barrier with explicit legacy revocation.

        Returns:
            None: Starts shared draining and finalization, or raises before admission.

        Raises:
            ValueError: The selected mode or exact pending Watcher plans are unavailable.
            RuntimeError: Another finalizer or local cleanup still owns the capture.
        """
        self._finish(revoke=True)

    def _finish(self, *, revoke: bool) -> None:
        """Run Finish with the captured legacy revoke intent when requested.

        Args:
            revoke (bool): Explicitly confirmed legacy-key revocation request.
        Returns:
            None: Starts the existing finalizer after verifying its saved plans.
        Raises:
            ValueError: The selected mode or exact pending Watcher plans are unavailable.
            RuntimeError: Another finalizer or local cleanup still owns the capture.
        """
        if self._revocation_operation is not None and self._revocation_operation.local_cleanup_pending:
            raise RuntimeError('Confirmed revocation cleanup must finish first.')
        if self._abandon_config is not None:
            raise RuntimeError('Retry local key cleanup before starting another capture action.')
        if self._linked_abandon_session_path is not None:
            raise RuntimeError('Retry flight abandonment before starting another capture action.')
        if self.finalizer is not None and self.finalizer.is_alive():
            if revoke:
                raise RuntimeError('Wait for the current operation to finish.')
            return
        if self.finalizer is not None and self.finalizer.local_cleanup_pending:
            raise RuntimeError('Confirmed revocation cleanup must finish first.')
        if revoke and self.persist_revoked_config is None:
            raise RuntimeError('Local key cleanup is unavailable.')
        if revoke and self.owner.view().state not in LEGACY_UPLOAD_STATES:
            raise ValueError('Revoke is available only for the saved API-key connection.')
        if revoke and (self.worker is not None and self.worker._recovery_review_only):
            raise ValueError('Review pending uploads before revoking the API key.')
        self._workspace()
        config, port, target, binding = self._connection()
        if revoke and target is not None:
            raise ValueError('API-key revocation cannot use a linked gallery target.')
        worker = self.worker
        plans = pending_sessions(self.root, binding)
        if not plans and not revoke:
            raise ValueError('No unambiguous pending Watcher session is available.')
        if revoke and JobStateStore(watcher_state.STATE_PATH).recoverable_jobs():
            raise ValueError('Resolve accepted Import work before revoking the API key.')
        if not plans and any(
            active is not None and active.is_alive()
            for active in (worker, self.recorder, self.utc)
        ):
            raise ValueError('The active Watcher session could not be verified.')
        plan = plans[0] if plans else None
        if plan is not None and worker is not None and worker.is_alive():
            if (worker._queue is None or worker._queue.binding != binding
                    or worker.drain_manifest is None or worker.drain_manifest.parent != plan.directory):
                raise ValueError('Pending photographs belong to another gallery connection.')
            if worker._recovery_review_only:
                coordinator = worker.recovery_coordinator
                if coordinator is None or not coordinator.resume_pending_at_start():
                    raise ValueError('Review pending upload')
                worker.apply_recovery_decision()
            worker.request_drain()
        elif plan is not None:
            worker = watcher_worker.create_resumed_watch_worker(
                plan.directory, config, self.events, (self.owner, port), target,
                continue_retained=True,
            )
            self.worker = worker
        self.utc.stop()
        if self.recorder is not None:
            self.recorder.stop()
        self.capture_closed = False
        remote = any(item.reports_enabled and not item.local_only and not item.abandoned for item in plans)
        url = normalize_upload_url(config.gallery_url) if (remote or revoke) and target is None else ''
        key = config.api_key.strip() if (remote or revoke) and target is None else ''
        operation_box: dict[str, FinalizationWorker] = {}
        capture_drained = False

        def submit_revoke() -> None:
            """Submit this finalizer's exact authenticated legacy revoke request.

            Returns:
                None: Queues local cleanup only after positive server proof.
            Raises:
                RuntimeError: The exact ticket or positive response is unavailable.
            """
            operation = operation_box.get('operation')
            if (operation is None or operation is not self.finalizer
                    or operation._gui_work is None
                    or operation._gui_work.kind != 'legacy_revoke'
                    or not operation.wants_revoke or not operation.server_request_sent):
                raise RuntimeError('Revocation ownership is unavailable.')
            response = revoke_upload_key(
                operation.upload_url, operation.api_key,
                before_request=operation._gui_work.checkpoint,
            )
            if response.get('ok') is not True or response.get('action') != 'revoke':
                raise RuntimeError('Server revocation is unconfirmed.')
            operation.confirm_server_revocation()
            self._revocation_operation = operation
            self.events.put({
                'kind': 'api_key_revoked', 'payload': {'operation': operation},
            })

        def wait_for_capture(
            cancelled: threading.Event, deadline: float,
        ) -> None:
            """Drain exact Watcher plans or verify the explicit idle-key case.

            Args:
                cancelled (threading.Event): Finalizer's cancellation fence.
                deadline (float): Shared monotonic drain deadline.
            Returns:
                None: No unowned Watcher plan or writer remains before revoke.
            Raises:
                InterruptedError: The operation was cancelled before revocation.
                RuntimeError: Idle state or pending ownership changed.
                TimeoutError: Existing capture writers did not finish in time.
            """
            nonlocal capture_drained
            if plans and not capture_drained:
                self._wait_for_queues(
                    plans, worker, config, port, target, cancelled, deadline,
                )
                capture_drained = True
                return
            if cancelled.is_set():
                raise InterruptedError('API-key revocation was cancelled.')
            while any(
                active is not None and active.is_alive()
                for active in (self.worker, self.recorder, self.utc)
            ):
                if cancelled.is_set():
                    raise InterruptedError('API-key revocation was cancelled.')
                if time.monotonic() >= deadline:
                    raise TimeoutError('Watcher work is still finishing.')
                cancelled.wait(CAPTURE_DRAIN_POLL_SECONDS)
            if pending_sessions(self.root, binding):
                raise RuntimeError('New Watcher work appeared before API-key revocation.')

        operation = FinalizationWorker(
            plans, url, key,
            wait_for_capture,
            submit_revoke if revoke else lambda: None, revoke,
            emit=self.emit, linked_target=target if remote else None,
            gui_admission=(self.owner, port) if (remote or revoke) and target is None else None,
            require_gui_admission=(remote or revoke) and target is None,
            config_generation=config._snapshot_token if revoke else None,
        )
        operation_box['operation'] = operation
        self.finalizer = operation
        if revoke:
            self._revocation_operation = operation
        operation.start()

    def complete_legacy_revocation(self, operation: FinalizationWorker) -> bool:
        """Persist only this operation's positively confirmed key revocation.

        Args:
            operation (FinalizationWorker): Exact finalizer carried by its event.
        Returns:
            bool: True only after atomic config cleanup; False while retryable or refused.
        """
        if (operation is not self._revocation_operation
                or operation is not self.finalizer or operation.is_alive()
                or not operation.server_revocation_confirmed
                or self.persist_revoked_config is None):
            return False
        work: CoordinatedWork | None = None
        try:
            work = operation.revocation_work()
            work.checkpoint()
            config = self.saved()
            if (operation.config_generation is None
                    or operation.config_generation is not config._snapshot_token
                    or operation.api_key != config.api_key.strip()
                    or operation.upload_url != normalize_upload_url(config.gallery_url)
                    or self.owner.view().state not in LEGACY_UPLOAD_STATES):
                raise ValueError('Captured revocation configuration changed.')
            cleared = self.persist_revoked_config(replace(config, api_key=''), work)
            if cleared.api_key != '':
                raise RuntimeError('Saved API-key cleanup could not be verified.')
        except Exception:
            retryable = False
            if work is not None:
                try:
                    work.checkpoint()
                    retryable = True
                except RuntimeError:
                    pass
            operation.confirm_local_cleanup(
                'Server revocation confirmed; local key cleanup needs review.',
                retryable=retryable,
            )
            self.emit('error', gettext_noop(
                'Server revocation confirmed; local key cleanup needs review.',
            ))
            return False
        self._revocation_operation = None
        operation.confirm_local_cleanup()
        self.emit('success', gettext_noop(
            'API key revoked and removed from local configuration.',
        ))
        return True

    def abandon_capture(self) -> bool:
        """Stop the verified capture and retain all files before local closure.

        Returns:
            bool: True after durable abandonment and any atomic key cleanup; False
                when terminal work is saved but configuration cleanup needs retry.
        Raises:
            ValueError: Mode, accepted imports, or exact plan cannot be verified.
            RuntimeError: Existing finalization or another writer prevents closure.
            OSError: The queue and flight terminal markers cannot be persisted.
        """
        if self._abandon_config is not None:
            return self.complete_legacy_abandon()
        state = self.snapshot()
        if (state.finishing or state.revocation_cleanup_pending or self._context_starting
                or (self._context_cleanup is not None and self._context_cleanup.is_alive())):
            raise RuntimeError('Wait for the current operation to finish.')
        mode = self.owner.view()
        record = mode.record
        linked = bool(record is not None and record.mode == 'linked')
        config = None
        if linked:
            if (record is None or record.issuer is None
                    or record.account_id is None or record.gallery_id is None):
                raise ValueError('The saved upload connection could not be verified.')
            binding = linked_target_binding(record.issuer, record.account_id, record.gallery_id)
        else:
            if mode.state not in LEGACY_UPLOAD_STATES or self.persist_abandoned_config is None:
                raise ValueError('Local API-key abandonment requires the saved key connection.')
            config = self.saved()
            url = normalize_upload_url(config.gallery_url)
            key = config.api_key.strip()
            if not url or not key:
                raise ValueError('A saved API-key connection is required.')
            if JobStateStore(watcher_state.STATE_PATH).recoverable_jobs():
                raise ValueError('Resolve accepted Import work before abandoning this flight.')
            binding = connection_binding(url, key)
        lease = self._workspace()
        plans = pending_sessions(self.root, binding)
        pending_cohort = tuple(plans)
        worker = self.worker
        recorder = self.recorder
        retry_path = self._linked_abandon_session_path if linked else None
        live_recorder = recorder is not None and recorder.is_alive()
        live_worker = worker is not None and worker.is_alive()
        active_directory: Path | None = None
        if linked:
            if live_recorder and recorder is not None:
                active_directory = recorder.directory.resolve(strict=True)
            if live_worker and worker is not None:
                if (worker._queue is None or worker._queue.binding != binding
                        or worker.drain_manifest is None):
                    raise ValueError('The active linked flight could not be verified.')
                worker_directory = worker.drain_manifest.parent.resolve(strict=True)
                if active_directory is not None and active_directory != worker_directory:
                    raise ValueError('The active linked flight could not be verified.')
                active_directory = worker_directory
        if linked and retry_path is None and active_directory is not None:
            active_plans = [
                plan for plan in plans
                if plan.directory.resolve(strict=True) == active_directory
            ]
            if len(active_plans) != 1:
                raise ValueError('The active linked flight could not be verified.')
            plans = active_plans
            retry_path = active_directory
            self._linked_abandon_session_path = retry_path
        elif retry_path is not None:
            resolved_retry_path = retry_path.resolve(strict=True)
            if (retry_path.is_symlink()
                    or resolved_retry_path.parent != self.root.resolve(strict=True)):
                raise ValueError('The linked flight closure could not be verified.')
            if active_directory is not None and active_directory != resolved_retry_path:
                raise ValueError('The active linked flight changed during closure.')
            plans = [
                plan for plan in plans
                if plan.directory.resolve(strict=True) == resolved_retry_path
            ]
            if not plans:
                completed = SessionPlan.load(retry_path)
                if (completed.binding != binding or not completed.completed
                        or not completed.abandoned):
                    raise ValueError('The linked flight closure could not be verified.')
        if not plans and retry_path is None:
            raise ValueError('No unambiguous pending Watcher session is available.')
        if not linked and worker is not None and worker.is_alive():
            if (worker._queue is None or worker._queue.binding != binding
                    or worker.drain_manifest is None
                    or not any(worker.drain_manifest.parent == plan.directory for plan in plans)):
                raise ValueError('Pending photographs belong to another gallery connection.')
        if any(active is not None and active.is_alive()
               for active in (worker, recorder, self.utc)):
            self.interrupt()
            deadline = time.monotonic() + FINALIZATION_DRAIN_SECONDS
            while any(
                active is not None and active.is_alive()
                for active in (self.worker, self.recorder, self.utc)
            ):
                if time.monotonic() >= deadline:
                    raise TimeoutError('Workers are still stopping; local key retained.')
                time.sleep(CAPTURE_DRAIN_POLL_SECONDS)
        if self.restart_record is not None:
            record = self.restart_record
            if (not any(record.session_id == plan.session_id for plan in plans)
                    or WatcherContinuation.load(lease) != record
                    or self.restart_ordinary
                    and WatcherContinuation.load(lease, ordinary_restart=True) != record):
                raise ValueError('The restart checkpoint could not be verified.')
            cancelled = replace(record, phase='cancelled')
            cancelled.save(lease)
            if self.restart_ordinary:
                cancelled.save(lease, ordinary_restart=True)
            self.restart_record = None
            self.restart_saved = False
        cutoff = time.time()
        for plan in plans:
            persist_retained_capture_closure(
                watcher_state.CONFIG_DIR, lease, plan, linked, cutoff, lambda _step: None,
            )
        self._linked_abandon_session_path = None
        self.worker, self.recorder, self.finalizer = None, None, None
        self.capture_closed = True
        closed_ids = {plan.session_id for plan in plans}
        self.more_retained = any(
            plan.session_id not in closed_ids for plan in pending_cohort
        )
        self._context_cleanup_due = linked
        if config is None:
            self.emit('success', tr(current_language(), 'flight_abandoned'))
            return True
        self._abandon_config = config
        return self.complete_legacy_abandon()

    def complete_legacy_abandon(self) -> bool:
        """Remove only the captured key after its plan is durably abandoned.

        Returns:
            bool: True after the exact local-only cleanup is verified; False
                retains the captured config for a safe retry.
        """
        config = self._abandon_config
        if config is None or self.persist_abandoned_config is None:
            return False
        if self.snapshot().finishing:
            return False
        try:
            current = self.saved()
            binding = connection_binding(
                normalize_upload_url(config.gallery_url), config.api_key.strip(),
            )
            if (self.owner.view().state not in LEGACY_UPLOAD_STATES
                    or current._snapshot_token is not config._snapshot_token
                    or current.api_key.strip() != config.api_key.strip()
                    or normalize_upload_url(current.gallery_url)
                    != normalize_upload_url(config.gallery_url)):
                raise ValueError('Saved connection changed after flight abandonment.')
            cleared = self.persist_abandoned_config(replace(current, api_key=''), binding)
            if cleared.api_key != '':
                raise RuntimeError('Saved API-key cleanup could not be verified.')
        except Exception:
            self.emit(
                'error', recovery_text(current_language(), 'watch_recovery_close_failed'),
            )
            return False
        self._abandon_config = None
        self.emit('success', tr(current_language(), 'flight_abandoned'))
        return True

    def _wait_for_queues(
        self, plans: list[SessionPlan], first: WatcherThread | None,
        config: watcher_state.WatcherConfig, port: ModeConfigPort, target: LinkedUploadTarget | None,
        cancelled: threading.Event, deadline: float,
    ) -> None:
        """Drain each exact original session in stable order before reporting.

        Args:
            plans (list[SessionPlan]): Original binding-matched sessions in order.
            first (WatcherThread | None): The started first queue, when work remains.
            config (WatcherConfig): Immutable Watcher settings for any later queue.
            port (ModeConfigPort): Verified saved configuration admission.
            target (LinkedUploadTarget | None): Original delegated target or legacy mode.
            cancelled (threading.Event): Exact finalization cancellation fence.
            deadline (float): Shared absolute deadline covering the whole drain.
        Returns:
            None: Every present photo journal reached its original terminal boundary.
        """
        self._wait_for_drain(plans[0], first, cancelled, deadline)
        for plan in plans[1:]:
            with self._worker_transition:
                if cancelled.is_set() or time.monotonic() >= deadline:
                    raise InterruptedError('Pending work is retained.')
                worker = watcher_worker.create_resumed_watch_worker(
                    plan.directory, config, self.events, (self.owner, port), target,
                    continue_retained=True,
                )
                self.worker = worker
            self._wait_for_drain(plan, worker, cancelled, deadline)

    def _wait_for_drain(
        self, plan: SessionPlan, worker: WatcherThread | None,
        cancelled: threading.Event, deadline: float,
    ) -> None:
        """Verify the accepted cutoff before allowing dependent report delivery.

        Args:
            plan (SessionPlan): Exact durable session selected by Finish.
            worker (WatcherThread | None): Current or restored photograph owner.
            cancelled (threading.Event): Finalizer's explicit cancellation gate.
            deadline (float): Existing monotonic graceful drain deadline.
        Returns:
            None: Recorder and UTC stopped and the original photo queue is done.
        """
        while ((self.recorder is not None and self.recorder.is_alive())
               or self.utc.is_alive() or (worker is not None and worker.is_alive())):
            if cancelled.is_set():
                raise InterruptedError('Pending work is retained.')
            if worker is not None and worker._queue is not None:
                # In-memory witness only; the terminal cutoff is read from disk below.
                head = worker._queue.head()
                if head is not None and (head.state in ('uncertain', 'blocked', 'auth_required')
                                         or (head.state == 'retry_wait' and head.phase == 'source')):
                    raise RuntimeError('Review pending upload')
            if time.monotonic() >= deadline:
                raise TimeoutError('Pending work is retained.')
            cancelled.wait(CAPTURE_DRAIN_POLL_SECONDS)
        path = plan.directory / 'watch-drain.json'
        if not path.exists() and worker is None:
            return
        row = WatchQueueStore.read_journal(path)
        if (row.get('binding') != plan.binding or row.get('frozen') is not True
                or not row.get('cutoff') or row.get('pending') != []
                or row.get('schema_version') == 2 and row.get('completed') is not True):
            raise RuntimeError('The captured Watcher queue is missing; recovery is required.')

    def interrupt(self) -> None:
        """Retain the current queue and stop the recorder and UTC acquisition.

        Returns:
            None: Leaves live HTTP under the original worker until it exits.
        """
        if self.finalizer is not None and self.finalizer.is_alive():
            self.finalizer.cancel.set()
        with self._worker_transition:
            worker = self.worker
            if worker is not None and worker.is_alive():
                if not watcher_worker.watch_recovery_owner_held(worker):
                    raise RuntimeError('Wait for the current operation to finish.')
                worker.publish_retained_stop(worker.begin_retained_stop())
        self.utc.stop()
        if self.recorder is not None:
            self.recorder.stop()

    def force_finish(self) -> bool:
        """Terminalize the admitted Finish cohort while its writer keeps ownership.

        Returns:
            bool: True after queue and plan terminal markers read back.
        Raises:
            ValueError: The operation, plan or live worker identity is ambiguous.
            RuntimeError: The request is already beyond its safe Finish barrier.
            OSError: Terminal queue or plan state cannot be durably published.
        """
        operation = self.finalizer
        if operation is None:
            raise ValueError('There is no active Finish operation to release.')
        if self._revocation_operation is not None and self._revocation_operation.server_revocation_confirmed:
            raise RuntimeError('Confirmed key cleanup must finish before Force Finish.')
        if self._abandon_config is not None:
            raise RuntimeError('Local key cleanup must finish before Force Finish.')
        if self._linked_abandon_session_path is not None:
            raise RuntimeError('Retry flight abandonment before Force Finish.')
        if self._context_cleanup_failed:
            raise RuntimeError('Watcher access cleanup must finish before Force Finish.')
        plans = {plan.directory.resolve(): plan for plan in operation.plans}
        lease = self._workspace()
        mode = self.owner.view()
        linked = bool(mode.record is not None and mode.record.mode == 'linked')
        if linked:
            record = mode.record
            if (record is None or record.issuer is None
                    or record.account_id is None or record.gallery_id is None):
                raise ValueError('Force Finish target identity is unavailable.')
            binding = linked_target_binding(
                record.issuer, record.account_id, record.gallery_id,
            )
        elif mode.state in LEGACY_UPLOAD_STATES:
            config = self.saved()
            binding = connection_binding(
                normalize_upload_url(config.gallery_url), config.api_key.strip(),
            )
        else:
            raise ValueError('Force Finish upload mode is unavailable.')
        pending = {plan.directory.resolve(): plan for plan in pending_sessions(self.root, binding)}
        if (not plans or len(plans) != len(operation.plans) or plans != pending
                or any(path != lease.root.resolve() / plan.session_id
                       for path, plan in plans.items())):
            raise ValueError('Force Finish pending-work identity changed.')
        cutoff = float(getattr(operation, '_force_finish_cutoff', 0.0) or 0.0)
        if cutoff <= 0:
            cutoff = time.time()
            operation._force_finish_cutoff = cutoff
        queue_store = None
        active_path = None
        pipeline = None
        target_lease = None
        with self._worker_transition:
            worker = self.worker
            live_worker = worker if worker is not None and worker.is_alive() else None
            if live_worker is None:
                if not operation.cancel_drain_for_terminal_retention():
                    raise RuntimeError('Force Finish is available only during the capture drain.')
            else:
                with live_worker._force_finish_lease_lock:
                    manifest = live_worker.drain_manifest
                    active_path = manifest.parent.resolve() if manifest is not None else None
                    queue_store = live_worker._queue
                    if (active_path not in plans or queue_store is None
                            or queue_store.binding != binding
                            or live_worker._retained_stop_publishing.is_set()
                            or live_worker._force_finish_publishing.is_set()):
                        raise ValueError('Force Finish worker ownership changed.')
                    pipeline = live_worker._pipeline_lease
                    native_pipeline = getattr(pipeline, '_native', None)
                    if native_pipeline is None:
                        raise RuntimeError('The live upload pipeline lease is unavailable.')
                    native_pipeline.verify()
                    target_lease = live_worker._linked_lease
                    native_target = getattr(target_lease, '_native', None)
                    if linked and (native_target is None or target_lease._binding != binding):
                        raise RuntimeError('The live linked-target lease is unavailable.')
                    if native_target is not None:
                        native_target.verify()
                    if not operation.cancel_drain_for_terminal_retention():
                        raise RuntimeError('Force Finish is available only during the capture drain.')
                    live_worker._force_finish_publishing.set()
                    live_worker._force_finish_publication_done.clear()
                    live_worker._force_finish_operation_id = id(operation)
                    live_worker._force_finish_requested.set()
                    live_worker._force_stop_requested.set()
                    live_worker._keep_sources_after_stop = True
                    live_worker._producer_stop.set()
                    live_worker._producer_wake.set()
                    live_worker._diagnostic_phase = 'force_finish_persisting'
        try:
            self.utc.stop()
            recorder = self.recorder
            if recorder is not None and recorder.is_alive():
                recorder.stop()
                recorder.join(timeout=FINALIZATION_DRAIN_SECONDS)
                if recorder.is_alive():
                    raise TimeoutError('Flight recorder still owns the captured plan.')
            for path, plan in plans.items():
                if path == active_path and queue_store is not None:
                    lease.verify()
                    queue_store.force_finish(cutoff)
                    if (not queue_store.force_finished
                            and queue_store.data.get('completed') is not True):
                        raise OSError('Force-finished queue marker did not read back.')
                    plan.persist_abandoned(lease)
                else:
                    persist_retained_capture_closure(
                        watcher_state.CONFIG_DIR, lease, plan, linked, cutoff, lambda _step: None,
                        active_pipeline=pipeline, active_target=target_lease,
                    )
        finally:
            if live_worker is not None:
                with live_worker._force_finish_lease_lock:
                    live_worker._force_finish_publishing.clear()
                    live_worker._force_finish_publication_done.set()
        self.capture_closed = True
        self.more_retained = False
        self._context_cleanup_due = True
        if live_worker is not None:
            live_worker._diagnostic_phase = 'force_finished'
            live_worker.stop()
        pending_transport = bool(
            worker is not None and (
                worker._transport_active.is_set()
                or worker._pending_transport is not None
                and not worker._pending_transport.is_set()
            )
        )
        message_key = (
            'force_finish_transport_active' if pending_transport else 'force_finish_saved'
        )
        self.emit('success', recovery_text(current_language(), message_key))
        return True

    def recover(self) -> None:
        """Admit only the exact previous queue and hold every pending transfer.

        Returns:
            None: Starts review-only recovery, leaving decisions to the operator.
        """
        if self.worker is not None and self.worker.is_alive():
            return
        self._workspace()
        config, port, target, binding = self._connection()
        plans = pending_sessions(self.root, binding)
        if not plans:
            raise ValueError('No unambiguous pending Watcher session is available.')
        self.finalizer = None
        self.worker = watcher_worker.create_resumed_watch_worker(
            plans[0].directory, config, self.events, (self.owner, port), target,
            review_only=True,
        )

    def close_retained(self) -> None:
        """Close the exact inactive original session with retained local evidence.

        Returns:
            None: The same shared terminal journal and plan markers read back.
        """
        if self.snapshot().alive:
            raise RuntimeError('Wait for the current operation to finish.')
        lease = self._workspace()
        mode = self.owner.view()
        linked = mode.record is not None and mode.record.mode == 'linked'
        if linked:
            record = mode.record
            assert record is not None and record.issuer is not None
            assert record.account_id is not None and record.gallery_id is not None
            binding = linked_target_binding(record.issuer, record.account_id, record.gallery_id)
        elif mode.state in LEGACY_UPLOAD_STATES:
            config = self.saved()
            binding = connection_binding(normalize_upload_url(config.gallery_url), config.api_key)
        else:
            raise ValueError('The saved upload connection could not be verified.')
        plans = pending_sessions(self.root, binding)
        if not plans:
            raise ValueError('No unambiguous pending Watcher session is available.')
        if self.restart_record is not None:
            record = self.restart_record
            if (record.session_id != plans[0].session_id or WatcherContinuation.load(lease) != record
                    or self.restart_ordinary and WatcherContinuation.load(lease, ordinary_restart=True) != record):
                raise ValueError('The restart checkpoint could not be verified. Review pending uploads.')
            cancelled = replace(record, phase='cancelled')
            cancelled.save(lease)
            if self.restart_ordinary:
                cancelled.save(lease, ordinary_restart=True)
            self.restart_record = None
            self.restart_saved = False
        persist_retained_capture_closure(watcher_state.CONFIG_DIR, lease, plans[0], linked, time.time(), lambda _step: None)
        self.worker, self.recorder, self.finalizer = None, None, None
        self.capture_closed = True
        self.more_retained = len(plans) > 1
        self._context_cleanup_due = not self.more_retained

    def reset_watcher(self) -> None:
        """Reset only Watcher under its durable intent and writer barriers.

        Returns:
            None: The original factory defaults read back after owned cleanup.
        """
        if (self._revocation_operation is not None
                and self._revocation_operation.server_revocation_confirmed):
            raise RuntimeError('Confirmed revocation cleanup must finish first.')
        if self._abandon_config is not None:
            raise RuntimeError('Retry local key cleanup before resetting the Watcher.')
        if self._linked_abandon_session_path is not None:
            raise RuntimeError('Retry flight abandonment before resetting the Watcher.')
        self.reset_completed = False
        lease = self._workspace()
        values = watcher_state.WatcherConfig().to_dict()
        profile = WatcherProfile(True, '', {key: values[key] for key in WATCHER_PREFERENCE_TYPES})
        profile.save(lease)
        if self.finalizer is not None:
            self.finalizer.cancel.set()
        with self._worker_transition:
            worker = self.worker
            if worker is not None and worker.is_alive():
                worker.request_factory_reset_stop()
        self.utc.stop()
        if self.recorder is not None:
            self.recorder.stop()
        self._join_capture_writers()
        once = WorkspaceLease(watcher_state.CONFIG_DIR, lock_name=WATCHER_ONCE_LOCK)
        try:
            discard_once_queues(watcher_state.CONFIG_DIR)
        finally:
            once.release()
        discard_capture_queues(lease)
        replace(profile, pending=False).save(lease)
        self.worker, self.recorder, self.finalizer = None, None, None
        self.active_dispatch = None
        self.restart_record = None
        self.restart_saved = False
        self.capture_closed = False
        self.more_retained = False
        self.reset_completed = True
        self._context_cleanup_due = True
        self.emit('success', gettext_noop('Watcher reset complete. Choose a connection and folder to start.'))

    def checkpoint_restart(self) -> None:
        """Save ordinary restart consent after the current writers finish pausing.

        Returns:
            None: No live recorder or transfer remains after the marker pair verifies.
        """
        self._pause_for_continuation(runtime.APP_VERSION, uuid.uuid4().hex, ordinary=True)

    def checkpoint_update(self, ticket: Path) -> None:
        """Pause capture and bind its exact prepared installed-helper ticket.

        Args:
            ticket (Path): Fresh verified update ticket created by the native helper.
        Returns:
            None: The helper may proceed after capture and ticket both read back.
        """
        selected = update_helper.load_ticket(ticket)
        self._pause_for_continuation(selected.target_version, selected.session_id, ordinary=False)
        update_helper.bind_capture_resume(ticket, True)

    def _pause_for_continuation(self, version: str, nonce: str, *, ordinary: bool) -> None:
        """Wait for writer quiescence before publishing a verified checkpoint.

        Args:
            version (str): Same version for restart or exact installer target version.
            nonce (str): Existing restart consent or verified helper session ID.
            ordinary (bool): Publish matching ordinary restart evidence when true.
        Returns:
            None: The exact unfrozen capture and its one-use marker are ready.
        """
        worker = self.worker
        if worker is None or not self.snapshot().can_checkpoint or worker.drain_manifest is None:
            raise ValueError('The current Watcher must finish preparation or recovery first.')
        lease = self._workspace()
        _config, _port, target, binding = self._connection()
        plans = pending_sessions(self.root, binding)
        if len(plans) != 1 or plans[0].directory != worker.drain_manifest.parent:
            raise ValueError('The current Watcher session could not be verified.')
        record = WatcherContinuation(
            plans[0].session_id, binding, WatcherContinuation.profile_digest(lease.root),
            runtime.APP_VERSION, version, '', 'linked' if target is not None else 'legacy',
            target is not None and target.watch_key is not None, time.time(), 'preparing',
            approved_scope=target.session.approved_scope() if target is not None else '',
        )
        record.save(lease)
        worker.request_update_pause()
        self.utc.stop()
        if self.recorder is not None:
            self.recorder.stop()
        try:
            self._join_capture_writers()
            verify_unfrozen_capture(lease, record)
            if target is not None and (worker.state is None or worker.state.has_unresolved_upload()):
                raise ValueError('An upload is unconfirmed; update postponed.')
            self._close_contextual_access()
            prepared = replace(record, update_session=nonce, phase='prepared')
            prepared.save(lease)
            if ordinary:
                prepared.save(lease, ordinary_restart=True)
            self.restart_record = prepared
            self.restart_saved = ordinary
            self.restart_ordinary = ordinary
        except (OSError, RuntimeError, ValueError):
            self.more_retained = True
            # A refused checkpoint must leave the same unfrozen originals
            # available for explicit review rather than imply a safe restart.
            current = WatcherContinuation.load(lease)
            if (current is not None and current.session_id == record.session_id
                    and current.binding == record.binding and current.created == record.created):
                cancelled = replace(current, update_session=current.update_session or uuid.uuid4().hex, phase='cancelled')
                cancelled.save(lease)
                if ordinary:
                    cancelled.save(lease, ordinary_restart=True)
            raise

    def _join_capture_writers(self) -> None:
        """Wait under the existing checkpoint budget for actual writer termination.

        Returns:
            None: Watcher, recorder, UTC and finalization have released all work.
        Raises:
            TimeoutError: An admitted writer still owns its operation.
        """
        deadline = time.monotonic() + UPDATE_CHECKPOINT_TIMEOUT_SECONDS
        for active in (self.worker, self.recorder, self.utc, self.finalizer):
            if active is not None and active.is_alive():
                active.join(timeout=max(0.0, deadline - time.monotonic()))
        if self.snapshot().alive:
            raise TimeoutError('Current work is still finishing; update postponed.')

    def resume_checkpoint(self) -> None:
        """Resume the original recording after verifying the one-use restart consent.

        Returns:
            None: Reopens the exact original source boundary and recorder identity.
        """
        record = self.restart_record
        if record is None or self.snapshot().alive:
            raise ValueError('The saved Watcher checkpoint could not be verified. Review pending uploads.')
        lease = self._workspace()
        ordinary = self.restart_ordinary
        if (WatcherContinuation.load(lease) != record
                or ordinary and WatcherContinuation.load(lease, ordinary_restart=True) != record):
            raise ValueError('The restart checkpoint could not be verified. Review pending uploads.')
        plan, queue_owner = verify_unfrozen_capture(lease, record)
        if record.contextual and self.contextual_target is not None:
            self._close_contextual_access()
        if record.mode == 'linked' and self.target() is None and self.reconnect is not None:
            self.reconnect()
        config, port, target, binding = self._connection()
        if (binding != record.binding or (target is None) != (record.mode == 'legacy')
                or target is not None and target.session.approved_scope() != record.approved_scope):
            raise ValueError('The original upload target changed. Select it before resuming.')
        claimed = replace(record, phase='claimed')
        if ordinary:
            claimed.save(lease, ordinary_restart=True)
        claimed.save(lease)
        self.restart_record = claimed
        worker = None
        recorder = None
        consumed = replace(claimed, phase='consumed')
        try:
            if record.contextual and target is not None:
                key = target.session.open_watch_key(target.gallery_id)
                try:
                    target = replace(target, watch_key=key)
                except (RuntimeError, ValueError):
                    target.session.close_watch_key(target.gallery_id, key)
                    raise
                self.contextual_target = target
            worker = WatcherThread(
                config, self.events, target, gui_admission=(self.owner, port),
                watch_folder_override=queue_owner.folder, watch_recursive_override=queue_owner.recursive,
            )
            worker.configure_drain(plan.directory, (), resume=True, continuation=True)
            worker._continuation_ready = threading.Event()
            identity = (target.issuer + ' #' + str(target.gallery_id)
                        if target is not None else normalize_upload_url(config.gallery_url))
            recorder = FlightRecorder.from_session(plan.directory, identity, config.simconnect_dll_path, self.emit)
            if not FlightPreferences.load(lease.root).recording:
                recorder.pause()
            self.active_dispatch = load_dispatch(plan.directory)
            self.worker, self.recorder, self.finalizer = worker, recorder, None
            recorder.start()
            worker.start()
            if not worker._continuation_ready.wait(WATCHER_CONTINUATION_ADMISSION_TIMEOUT_SECONDS):
                raise ValueError('Watcher stopped before resume was confirmed. Review pending uploads before trying again.')
            if (WatcherContinuation.load(lease) != claimed
                    or ordinary and WatcherContinuation.load(lease, ordinary_restart=True) != claimed):
                raise ValueError('The restart checkpoint could not be verified. Review pending uploads.')
            if ordinary:
                consumed.save(lease, ordinary_restart=True)
            consumed.save(lease)
            if not worker.admit_continuation():
                raise ValueError('Watcher stopped before resume was confirmed. Review pending uploads before trying again.')
        except (OSError, RuntimeError, ValueError):
            # The consumer's gate remains closed. Restore only the exact
            # marker pair owned by this attempt so consent remains retryable.
            if worker is not None:
                worker.request_update_pause()
            if recorder is not None:
                recorder.stop()
            if worker is not None and not worker.is_alive() and worker._linked_lease is not None:
                worker._linked_lease.release()
            self._context_cleanup_due = record.contextual
            if (WatcherContinuation.load(lease) in (claimed, consumed)
                    and (not ordinary or WatcherContinuation.load(
                        lease, ordinary_restart=True,
                    ) in (claimed, consumed))):
                claimed.save(lease)
                if ordinary:
                    claimed.save(lease, ordinary_restart=True)
            raise
        self.restart_record = None
        self.restart_saved = False
        self.more_retained = False
        if config.sync_utc_time:
            self.utc.start(config.simconnect_dll_path, lambda: (
                worker.is_alive() and not worker.stop_event.is_set() and not worker.drain_event.is_set()
            ))

    def refresh(self) -> None:
        """Request a rescan from the original worker without changing the queue.

        Returns:
            None: Coalesces a rescan with existing local producer activity.
        """
        if self.worker is not None and self.worker.is_alive():
            self.worker.request_refresh()

    def set_preferences(self, preferences: FlightPreferences) -> None:
        """Save and apply Flight capture and report choices.

        Args:
            preferences (FlightPreferences): New recording, report and language
                preferences captured from the active desktop.
        Returns:
            None: Pauses or resumes the current recorder after preference save.
        """
        if self.finalizer is not None and self.finalizer.is_alive():
            raise RuntimeError('Wait for the current operation to finish.')
        lease = self._workspace()
        preferences.save(lease.root)
        recorder = self.recorder
        if recorder is not None and recorder.is_alive() and not recorder.stop_event.is_set():
            if preferences.recording:
                recorder.resume()
            else:
                recorder.pause()
            plan = SessionPlan.load(recorder.directory)
            plan.reports_enabled = preferences.reports
            plan.languages = preferences.languages
            plan.save()

    def review(self) -> CaptureReview | None:
        """Read the exact head and the shared coordinator's allowed decisions.

        Returns:
            CaptureReview | None: Original head and its decision availability.
        """
        worker = self.worker
        if worker is None or not watcher_worker.watch_recovery_owner_held(worker):
            return None
        owner = worker.recovery_coordinator
        snapshot = owner.snapshot() if owner is not None else None
        if owner is None or snapshot is None:
            return None
        try:
            preview, _digest = read_recovery_preview(snapshot)
        except (OSError, ValueError):
            preview = None
        target = worker.linked_target
        return CaptureReview(
            snapshot, owner.can_confirm_receipt(snapshot),
            preview is not None and owner.can_approve_photo_upload(snapshot), preview,
            target.title if target is not None else '',
        )

    def review_remote(
        self, review: CaptureReview, cancelled: threading.Event,
    ) -> WatchRecoveryRemote | None:
        """Expose optional remote evidence for one still-current recovery view.

        Args:
            review (CaptureReview): Pinned review previously read by this view.
            cancelled (threading.Event): Cancellation owned by the review dialog.

        Returns:
            WatchRecoveryRemote | None: Authorized comparison bytes and their
                kind, or None after cancellation, rebinding or head replacement.
        """
        worker = self.worker
        if (cancelled.is_set() or worker is None
                or not watcher_worker.watch_recovery_owner_held(worker)):
            return None
        coordinator = worker.recovery_coordinator
        if coordinator is None:
            return None
        witness = review.snapshot
        current = coordinator.snapshot()
        if current is None or current.decision_token != witness.decision_token:
            return None
        result = worker.load_watch_recovery_remote(witness, cancelled)
        if (result is None or cancelled.is_set() or self.worker is not worker
                or not watcher_worker.watch_recovery_owner_held(worker)):
            return None
        current = coordinator.snapshot()
        if current is None or current.decision_token != witness.decision_token:
            return None
        return result

    def dispatch_identity(self) -> str:
        """Read the exact shared SimBrief identity without fetching a plan.

        Returns:
            str: Remembered successful identity or an empty initial form.
        """
        try:
            value = read_document(self.root / 'simbrief-account.json').get('identity')
        except ValueError:
            return ''
        return value if isinstance(value, str) else ''

    def load_dispatch(self, identity: str) -> str:
        """Fetch and attach a plan to the originally selected capture.

        Args:
            identity (str): Explicit SimBrief pilot ID or username.
        Returns:
            str: Local route caption after saving or staging the exact plan.
        """
        self._workspace()
        captured_recorder = self.recorder
        recorder_was_alive = bool(
            captured_recorder is not None and captured_recorder.is_alive()
        )
        recorder = captured_recorder if recorder_was_alive else None
        finalizer = self.finalizer
        finalizer_was_alive = bool(finalizer is not None and finalizer.is_alive())
        plan = fetch_dispatch(identity)
        current_recorder = self.recorder
        current_recorder_alive = bool(
            current_recorder is not None and current_recorder.is_alive()
        )
        current_finalizer = self.finalizer
        if (current_recorder is not captured_recorder
                or current_recorder_alive != recorder_was_alive
                or current_finalizer is not finalizer
                or (current_finalizer is not None and current_finalizer.is_alive())
                != finalizer_was_alive):
            raise ValueError('Watcher capture changed while SimBrief was loading.')
        if finalizer_was_alive:
            raise ValueError('Wait for the current operation to finish.')
        if not recorder_was_alive:
            self.dispatch = plan
        else:
            if recorder is None or not recorder.is_alive() or recorder.stop_event.is_set():
                raise ValueError('Wait for the current operation to finish.')
            plan.save(recorder.directory)
            self.active_dispatch = plan
        atomic_write_json(self.root / 'simbrief-account.json', {'identity': identity.strip()})
        return self._dispatch_route(plan)

    def load_saved_dispatch(self, session: Path, identity: str) -> str:
        """Fetch and attach a SimBrief plan to one stopped saved session.

        Args:
            session (Path): Exact selected historical flight directory.
            identity (str): Explicit SimBrief pilot ID or username.
        Returns:
            str: Accepted route caption after the guarded local attachment.
        Raises:
            OSError: Provider or local persistence failed.
            RuntimeError: A finalizer or other workspace owner is active.
            ValueError: The selected session or identity is no longer valid.
        """
        lease = self._workspace()
        recorder = self.recorder
        active_session = (
            recorder.directory if recorder is not None and recorder.is_alive() else None
        )
        finalizer_active = self.finalizer is not None and self.finalizer.is_alive()
        target = capture_saved_dispatch_target(
            self.root, lease, session, active_session, finalizer_active,
        )
        plan = fetch_dispatch(identity)
        current_recorder = self.recorder
        if current_recorder is not recorder:
            raise ValueError('Watcher capture changed while SimBrief was loading.')
        current_session = (
            current_recorder.directory
            if current_recorder is not None and current_recorder.is_alive() else None
        )
        current_finalizer = self.finalizer
        persist_saved_dispatch(
            self.root, lease, target, identity, plan,
            active_session=current_session,
            finalizer_active=(
                current_finalizer is not None and current_finalizer.is_alive()
            ),
        )
        return self._dispatch_route(plan)

    def clear_dispatch(self) -> None:
        """Explicitly detach the current OFP without changing existing PDFs.

        Returns:
            None: Clears the active recorder's OFP and the staged local draft.
        """
        self._workspace()
        if self.finalizer is not None and self.finalizer.is_alive():
            raise ValueError('Wait for the current operation to finish.')
        if self.recorder is not None and self.recorder.is_alive():
            (self.recorder.directory / 'simbrief.json').unlink(missing_ok=True)
            self.active_dispatch = None
        self.dispatch = None

    def attach_dispatch(self, session: Path, source: Path) -> str:
        """Use the shared archived OFP reader for an exact completed recording.

        Args:
            session (Path): Selected existing flight directory under this profile.
            source (Path): Operator-selected JSON or XML OFP.
        Returns:
            str: Import success caption using the accepted planned airport pair.
        """
        self._workspace()
        if (session.parent != self.root or session.is_symlink()
                or session.resolve(strict=True).parent != self.root.resolve(strict=True)
                or read_document(session / 'session.json').get('recording_ended') is not True
                or self.recorder is not None and self.recorder.is_alive() and self.recorder.directory == session
                or self.finalizer is not None and self.finalizer.is_alive()):
            raise ValueError('Select a saved flight.')
        plan = import_dispatch(source)
        plan.save(session)
        return self._dispatch_route(plan)

    def _dispatch_route(self, plan: DispatchPlan) -> str:
        """Retain optional waypoint evidence and return the accepted route caption.

        Args:
            plan (DispatchPlan): Newly verified attached or staged provider plan.
        Returns:
            str: ICAO origin and destination for the current operation.
        """
        try:
            remember_plan(self.root, plan)
        except (OSError, sqlite3.Error):
            self.emit('warning', 'SimBrief navigation cache is unavailable.')
        return f'{plan.origin} → {plan.destination}'

    def _watched_report_target(self) -> WatchedReportTarget | None:
        """Capture the existing active upload authority for a saved report.

        Returns:
            WatchedReportTarget | None: Same live gallery and credentials as Watcher.
        """
        if self._linked_abandon_session_path is not None:
            return None
        worker = self.worker
        if (worker is None or not worker.is_alive() or not worker.initial_captured
                or worker.drain_only or worker.stop_event.is_set() or worker.drain_event.is_set()
                or worker._update_pause_requested() or worker._recovery_review_only
                or worker._force_finish_requested.is_set()):
            return None
        target = worker.linked_target
        if target is not None:
            if target.session.can_upload():
                return WatchedReportTarget(target.title or f'{target.issuer} #{target.gallery_id}', linked_target=target)
            return None
        endpoint, key = normalize_upload_url(worker.config.gallery_url), worker.config.api_key.strip()
        if not (endpoint and key and worker._gui_admission is not None):
            return None
        return WatchedReportTarget(endpoint, upload_url=endpoint, api_key=key, gui_admission=worker._gui_admission)

    def _report_capture_active(self) -> bool:
        """Refuse idle report routing while capture lifecycle work is active.

        Returns:
            bool: True while a Watcher, recorder, finalizer, UTC worker,
                contextual cleanup or connection transition could own state.
        """
        return bool(
            self._context_starting
            or self._context_cleanup_failed or self._abandon_config is not None
            or self._linked_abandon_session_path is not None
            or (self._revocation_operation is not None
                and self._revocation_operation.local_cleanup_pending)
            or any(
                worker is not None and worker.is_alive()
                for worker in (
                    self.worker, self.recorder, self.finalizer, self.utc,
                    self._context_cleanup, self._revocation_operation,
                )
            )
        )

    def _current_report_target(self) -> WatchedReportTarget | None:
        """Capture only the currently verified linked or legacy upload mode.

        Returns:
            WatchedReportTarget | None: Exact current upload authority without
                starting a connection or restoring a saved grant.
        Raises:
            ModeCoordinatorError: The current upload mode cannot be verified.
            OSError: The saved legacy connection cannot be read.
            RuntimeError: The selected linked grant is unavailable.
            ValueError: The current upload configuration is invalid.
        """
        mode = self.owner.view()
        if mode.state == 'linked_active':
            record = mode.record
            target = self.target()
            if (record is None or record.mode != 'linked' or target is None
                    or not target.session.available()
                    or not target.session.can_upload()
                    or not target.session.matches_issuer(target.issuer)
                    or record.issuer != target.issuer
                    or record.account_id != target.user_id
                    or record.gallery_id != target.gallery_id
                    or record.scope != target.session.approved_scope()
                    or target.watch_key is not None):
                return None
            return WatchedReportTarget(
                target.title or f'{target.issuer} #{target.gallery_id}',
                linked_target=target,
            )
        if mode.state in LEGACY_UPLOAD_STATES:
            config = capture_worker_config(self.saved())
            endpoint = normalize_upload_url(config.gallery_url)
            key = config.api_key.strip()
            if not endpoint or not key:
                return None
            port = self.store.mode_port(config)
            return WatchedReportTarget(
                endpoint, upload_url=endpoint, api_key=key,
                gui_admission=(self.owner, port),
            )
        return None

    def report_target(self) -> tuple[str, str] | None:
        """Project the active Watcher or verified idle destination for review.

        Returns:
            tuple[str, str] | None: Exact binding and display name, or None
                when mode evidence or active capture state is unavailable.
        """
        target = self._watched_report_target()
        if target is None and not self._report_capture_active():
            try:
                target = self._current_report_target()
            except (ModeCoordinatorError, OSError, RuntimeError, ValueError):
                return None
        return (target.binding(), target.label) if target is not None else None

    def inspect_watch_folder(
        self, cancelled: threading.Event,
    ) -> WatchFolderInspection | None:
        """Read configured Watcher options for a queue-independent summary.

        Args:
            cancelled (threading.Event): Current Qt read-lane cancellation fence.
        Returns:
            WatchFolderInspection | None: Count summary or no result after cancel.
        """
        config = self.saved()
        profile = WatcherProfile.load(self.root)
        if profile is not None:
            config = replace(config, **profile.preferences)
        if not config.watched_folder.strip():
            return WatchFolderInspection('', 0, 0, 'missing')
        return inspect_watch_folder(
            Path(config.watched_folder), watcher_state.SUPPORTED_SUFFIXES,
            config.watch_recursive, cancelled,
        )

    def delete_uploaded_after_upload(self) -> bool:
        """Read the effective source-deletion preference through its owner.

        Returns:
            bool: Current shared preference or verified Watcher-profile override.
        Raises:
            OSError: A present Watcher profile cannot be safely verified.
        """
        config = self.saved()
        profile = WatcherProfile.load(self.root)
        if profile is not None:
            config = replace(config, **profile.preferences)
        return config.delete_uploaded_files

    def prepare_report_upload(
        self, directory: Path, editions: tuple[tuple[str, str], ...], binding: str,
    ) -> tuple[FinalizationWorker, WorkspaceLease | None]:
        """Build a report-only worker under active or independent lease ownership.

        Args:
            directory (Path): Exact stopped flight selected in the catalogue.
            editions (tuple[tuple[str, str], ...]): Approved revision and language pairs.
            binding (str): Verified active destination shown to the operator.
        Returns:
            tuple[FinalizationWorker, WorkspaceLease | None]: Unstarted writer
                and its independently owned lease, or None when the active
                capture owner already retains the workspace lease.
        Raises:
            OSError: The stopped flight workspace cannot be leased or read.
            ValueError: Mode, target, session or selected edition changed.
            RuntimeError: Capture lifecycle work prevents independent admission.
        """
        active_target = self._watched_report_target()
        if active_target is not None:
            if active_target.binding() != binding:
                raise ValueError('The current Watcher destination changed.')
            plan, target = self._verified_active_report(directory, binding)
            lease = None
        else:
            if self._report_capture_active():
                raise RuntimeError('Wait for the current Watcher operation to finish.')
            target = self._current_report_target()
            if target is None or target.binding() != binding:
                raise ValueError('The selected report destination changed.')
            if self.lease is not None:
                self.lease.verify()
                self.lease.release()
                self.lease = None
            lease = WorkspaceLease(self.root)
            try:
                plan = self._verified_saved_report(directory, lease)
                current = self._current_report_target()
                if current is None or current.binding() != binding:
                    raise ValueError('The selected report destination changed.')
                target = current
            except BaseException:
                lease.release()
                raise

        try:
            revisions = {
                revision.report_id: revision
                for revision in report_revisions(directory)
            }
            chosen = {}
            for identifier, language in editions:
                revision = revisions.get(identifier)
                if revision is None or language not in revision.files:
                    raise ValueError('The selected report is unavailable.')
                subset = chosen.setdefault(identifier, replace(revision, files={}))
                subset.files[language] = revision.files[language]
            if not chosen:
                raise ValueError('The selected report is unavailable.')
            plan = replace(plan, languages=tuple(dict.fromkeys(
                language for revision in chosen.values()
                for language in revision.files
            )))
            worker = FinalizationWorker(
                [plan], '', '', lambda _cancel, _deadline: None,
                lambda: None, False, saved_revisions=tuple(chosen.values()),
                watched_target=target, emit=self.emit,
            )
            return worker, lease
        except BaseException:
            if lease is not None:
                lease.release()
            raise

    def prepare_report_creation(self, directory: Path, binding: str) -> FinalizationWorker:
        """Generate and deliver a new local edition through the active Watcher.

        Args:
            directory (Path): Exact stopped flight chosen in the native catalogue.
            binding (str): Expected verified active destination shown during approval.
        Returns:
            FinalizationWorker: Existing render-and-delivery worker with its destination.
        """
        plan, target = self._verified_active_report(directory, binding)
        if self.dispatch is not None:
            self.dispatch.save(directory)
        return create_local_report_worker(plan, watched_target=target, emit=self.emit)

    def _verified_active_report(self, directory: Path, binding: str) -> tuple[SessionPlan, WatchedReportTarget]:
        """Read the exact saved source under its already owned Watcher lease.

        Args:
            directory (Path): Selected local stopped flight under the current workspace.
            binding (str): Non-secret exact target shown to the operator.
        Returns:
            tuple[SessionPlan, WatchedReportTarget]: Validated plan and live destination.
        """
        target = self._watched_report_target()
        if target is None or target.binding() != binding or self.lease is None:
            raise ValueError('The current Watcher session could not be verified.')
        return self._verified_saved_report(directory, self.lease), target

    def _verified_saved_report(
        self, directory: Path, lease: WorkspaceLease,
    ) -> SessionPlan:
        """Verify one stopped saved session while holding its workspace lease.

        Args:
            directory (Path): Exact selected session below the flight workspace.
            lease (WorkspaceLease): Active or independently acquired workspace owner.
        Returns:
            SessionPlan: Validated immutable session plan for report-only delivery.
        Raises:
            OSError: The workspace or saved session cannot be read safely.
            ValueError: The selected path is not a confined stopped session.
        """
        lease.verify()
        if (directory.parent != self.root or directory.is_symlink()
                or directory.resolve(strict=True).parent != self.root.resolve(strict=True)
                or not _local_entry(directory / 'session.json')
                or not _local_entry(directory / 'completion.json')
                or read_document(directory / 'session.json').get('recording_ended') is not True):
            raise ValueError('Select a saved flight.')
        return SessionPlan.load(directory)

    def reconcile_workers(self, *, retire_context: bool = True) -> None:
        """Stop native acquisition when the photo worker has actually ended.

        Args:
            retire_context (bool): No dependent report or gallery writer owns
                the stopped worker's temporary access.
        Returns:
            None: Keeps the same session available for Finish or explicit recovery.
        """
        worker = self.worker
        if worker is not None and not worker.is_alive():
            if self.utc.is_alive():
                self.utc.stop()
            if self.recorder is not None and self.recorder.is_alive():
                self.recorder.stop()
            if self.finalizer is None and not self.restart_saved:
                self.more_retained = True
        if (self.finalizer is not None and not self.finalizer.is_alive()
                and self.finalizer.snapshot().phase == 'completed' and not self.more_retained):
            self._context_cleanup_due = True
        if (retire_context and not self._context_starting
                and self.contextual_target is not None and self._context_cleanup_due
                and not self._context_cleanup_failed and not self.snapshot().alive):
            cleanup = threading.Thread(target=self._retire_contextual_access, name='QtWatcherKeyClose', daemon=True)
            self._context_cleanup = cleanup
            try:
                cleanup.start()
            except RuntimeError:
                self._context_cleanup_failed = True

    def _retire_contextual_access(self) -> None:
        """Revoke stopped Watcher authority through the original network gate.

        Returns:
            None: Keeps a visible retry when the remote response is unconfirmed.
        """
        try:
            self._close_contextual_access()
        except ValueError:
            self.emit('warning', gettext_noop('Watcher access could not be closed. Retry.'))

    def decide(self, review: CaptureReview, decision: CaptureDecision) -> None:
        """Apply one confirmed action to the exact coordinator-owned head.

        Args:
            review (CaptureReview): Latest displayed immutable decision witness.
            decision (CaptureDecision): Operator-confirmed disposition or resume.
        Returns:
            None: Lets the original consumer observe the resulting durable state.
        """
        worker = self.worker
        if worker is None or not watcher_worker.watch_recovery_owner_held(worker):
            raise ValueError('The saved photo journal could not be read.')
        owner = worker.recovery_coordinator
        if owner is None:
            raise ValueError('The saved photo journal could not be read.')
        witness = review.snapshot
        if decision == 'receipt':
            owner.confirm_receipt(witness)
        elif decision == 'skip':
            owner.skip_head(witness)
        elif decision == 'approve':
            owner.approve_photo_upload(witness)
        elif decision == 'earlier':
            owner.review_earlier_blocker(witness)
        elif decision == 'continue':
            owner.resume_retained(witness)
        else:
            raise ValueError('Unknown Watcher review decision.')
        worker.apply_recovery_decision()

    def snapshot(self) -> CaptureStatus:
        """Read current worker, terminal and sensor state without I/O.

        Returns:
            CaptureStatus: Stable display values for the current repaint only.
        """
        worker, recorder, finalizer = self.worker, self.recorder, self.finalizer
        watching = worker is not None and worker.is_alive()
        recording = recorder is not None and recorder.is_alive()
        capture_target = None
        if (watching and not worker.stop_event.is_set() and not worker.drain_only
                and not worker.drain_event.is_set()
                and getattr(worker, 'initial_captured', True)):
            target = getattr(worker, 'linked_target', None)
            if isinstance(target, LinkedUploadTarget):
                # The live worker obtained this exact target through the shared
                # verifier. Project its identity without rerunning URL parsing.
                capture_target = target.issuer, target.user_id, target.gallery_id
        progress = finalizer.snapshot() if finalizer is not None else None
        closing_access = self._context_cleanup is not None and self._context_cleanup.is_alive()
        phase = progress.phase if progress is not None else (
            'watching' if watching else 'reset' if self.reset_completed else 'idle'
        )
        if self.capture_closed and not watching and not recording:
            phase = 'closed'
        sample = recorder.telemetry_snapshot() if recording else None
        dispatch = self.active_dispatch if recording else self.dispatch
        revocation_pending = bool(
            self._revocation_operation is not None
            and self._revocation_operation.server_revocation_confirmed
            and self._revocation_operation.snapshot().phase != 'completed'
        )
        abandon_pending = (
            self._abandon_config is not None
            or self._linked_abandon_session_path is not None
        )
        try:
            mode = self.owner.view()
            legacy_mode = mode.state in LEGACY_UPLOAD_STATES
            linked_mode = mode.record is not None and mode.record.mode == 'linked'
        except ModeCoordinatorError:
            legacy_mode = False
            linked_mode = False
        return CaptureStatus(
            phase=phase, alive=bool(watching or recording or self.utc.is_alive() or closing_access
                                    or (finalizer is not None and finalizer.is_alive())
                                    or revocation_pending or abandon_pending),
            watcher_worker_active=watching,
            watcher_starting=bool(
                watching and not getattr(worker, 'initial_captured', True)
                and not getattr(worker, 'drain_only', False)
                and not worker.stop_event.is_set() and not worker.drain_event.is_set()
            ),
            interrupt_available=watcher_interrupt_available(
                watching,
                finalizer is not None and finalizer.server_request_sent,
            ),
            capturing=bool(watching and not worker.drain_event.is_set()
                           and not worker.stop_event.is_set()),
            finishing=bool(finalizer is not None and finalizer.is_alive()),
            recording=bool(recording and not recorder.pause_event.is_set()
                           and not recorder.stop_event.is_set()),
            samples=int(recorder.diagnostics_snapshot()['sample_count']) if recorder is not None else 0,
            utc=self.utc.status(),
            utc_enabled=self._utc_sync_enabled,
            finalizer_identity=id(finalizer) if finalizer is not None else 0,
            error=(progress.error if progress is not None and progress.error
                   else worker.drain_error if worker is not None else ''),
            telemetry=(sample['indicated_airspeed_mps'], sample['altitude_m'],
                       sample['vertical_speed_mps']) if sample is not None else None,
            dispatch_route=f'{dispatch.origin} → {dispatch.destination}' if dispatch is not None else '',
            more_retained=self.more_retained,
            can_checkpoint=bool(watching and worker.initial_captured and not worker.drain_only
                                and not revocation_pending and not abandon_pending
                                and not worker._recovery_review_only and not worker._update_pause_requested()
                                and not worker.stop_event.is_set() and not worker.drain_event.is_set()
                                and not worker.drain_error and (progress is None or not finalizer.is_alive())),
            restart_saved=self.restart_saved,
            resume_available=self.restart_record is not None and not self.restart_saved,
            context_cleanup_failed=self._context_cleanup_failed,
            revocation_cleanup_pending=revocation_pending,
            legacy_mode=legacy_mode,
            linked_mode=linked_mode,
            capture_target=capture_target,
            abandon_cleanup_pending=abandon_pending,
            force_finish_available=bool(
                finalizer is not None and finalizer.is_alive()
                and not finalizer.server_request_sent
                and progress is not None
                and progress.phase == 'draining'
                and bool(finalizer.plans) and not finalizer.cancel.is_set()
                and not finalizer.abandonment
            ),
            progress_done=progress.done if progress is not None else 0,
            progress_total=progress.total if progress is not None else 0,
            progress_detail=progress.detail if progress is not None else '',
            progress_started=progress.started if progress is not None else 0.0,
            progress_phase_started=(
                progress.phase_started if progress is not None else 0.0
            ),
            eta_seconds=progress.eta_seconds if progress is not None else None,
            rendering_languages=progress.rendering_languages if progress is not None else (),
            rendered_languages=progress.rendered_languages if progress is not None else (),
        )

    def _retained_binding(self, mode: ModeView) -> str:
        """Fingerprint only the exact saved legacy or linked Watcher target.

        Args:
            mode (ModeView): Owner-issued saved upload-mode snapshot.
        Returns:
            str: Non-secret binding used by pending-session discovery.
        Raises:
            ValueError: The saved selected target cannot be verified locally.
        """
        record = mode.record
        if mode.state in LEGACY_UPLOAD_STATES:
            config = self.saved()
            url, key = normalize_upload_url(config.gallery_url), config.api_key.strip()
            if not url or not key:
                raise ValueError('The saved upload target could not be verified.')
            return connection_binding(
                url, key,
            )
        if (mode.state in ('linked_active', 'linked_disconnected')
                and record is not None and record.mode == 'linked'
                and isinstance(record.issuer, str)
                and type(record.account_id) is int
                and type(record.gallery_id) is int):
            return linked_target_binding(
                record.issuer, record.account_id, record.gallery_id,
            )
        raise ValueError('The saved upload target could not be verified.')

    def inspect_retained_queue(
        self, cancelled: threading.Event,
    ) -> RetainedQueueSummary | None:
        """Read retained queue counts using the current saved mode fingerprint.

        Args:
            cancelled (threading.Event): Current Qt read-lane cancellation fence.
        Returns:
            RetainedQueueSummary | None: Count-only local metadata, or no result
                after cancellation.
        Raises:
            OSError: The exact queue journal could not be read safely.
            ValueError: The saved binding or matching queue is inconclusive.
        """
        mode = self.owner.view()
        binding = self._retained_binding(mode)
        summary = inspect_retained_queue(self.root, binding, cancelled)
        if cancelled.is_set():
            return None
        current = self.owner.view()
        if (current.state != mode.state or current.record != mode.record
                or self._retained_binding(current) != binding):
            raise ValueError('The saved upload target changed during review.')
        return summary

    def close(self) -> None:
        """Release the workspace only after active writers and requests have ended.

        Returns:
            None: Safe to close or switch the desktop backend.
        """
        if self.snapshot().alive:
            raise RuntimeError('Wait for the current operation to finish.')
        if self.lease is not None:
            self.lease.release()
            self.lease = None
