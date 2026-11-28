# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/capture_session.py
# Module Type: Desktop capture composition
# Purpose: Share durable flight startup and present Watcher commands.
# Responsibilities: Preserve the recorder and plan owners across toolkits.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Toolkit-independent capture admission and presentation contracts."""

from contextlib import ExitStack
from dataclasses import dataclass
from pathlib import Path
from queue import Queue
from threading import Event
from typing import Callable, Literal, Protocol

from ..discovery import inspect_folder_counts
from ..flight_jobs import (
    FlightPreferences, SessionPlan, WatcherContinuation, WorkspaceLease,
    pending_sessions,
)
from ..flight_recorder import FlightRecorder
from ..flight_simbrief import DispatchPlan
from ..flight_workflow import FinalizationWorker
from ..linked_upload import LinkedTargetLease, LinkedUploadTarget, UploadPipelineLease
from .queue import WatchQueueStore
from .recovery import WatchRecoveryRemote, WatchRecoverySnapshot

CaptureDecision = Literal['receipt', 'skip', 'approve', 'earlier', 'continue']
CaptureEvents = Queue[tuple[str, str] | dict[str, object]]


@dataclass(frozen=True)
class CaptureStatus:
    """Read-only scalar view of the live Watcher and recorder lifetimes."""

    phase: str = 'idle'
    alive: bool = False
    # Type: bool. Units: worker state. Scope: one Watcher worker thread.
    # Consumers: Qt shell monitor. Rationale: Match Tk's actual worker test,
    # without conflating UTC or finalizer ownership with Watcher activity.
    watcher_worker_active: bool = False
    # Type: bool. Units: command availability. Scope: current Watcher and
    # finalizer admission fence. Consumers: Qt Watcher Stop controls.
    # Rationale: Match Tk's worker-live and pre-server-request guard.
    interrupt_available: bool = False
    capturing: bool = False
    finishing: bool = False
    recording: bool = False
    samples: int = 0
    utc: str = ''
    utc_enabled: bool = False
    finalizer_identity: int = 0
    error: str = ''
    telemetry: tuple[float | None, float | None, float | None] | None = None
    dispatch_route: str = ''
    more_retained: bool = False
    can_checkpoint: bool = False
    restart_saved: bool = False
    resume_available: bool = False
    context_cleanup_failed: bool = False
    revocation_cleanup_pending: bool = False
    legacy_mode: bool = False
    linked_mode: bool = False
    # Type: tuple[str, int, int] | None. Units: issuer/account/gallery ID.
    # Scope: one currently capturing linked Watcher worker.
    # Consumers: Qt Photos recording marker. Rationale: Project only its
    # captured non-secret target; never infer it from the current selection.
    capture_target: tuple[str, int, int] | None = None
    abandon_cleanup_pending: bool = False
    force_finish_available: bool = False
    progress_done: int = 0
    progress_total: int = 0
    progress_detail: str = ''
    # Type: float. Units: monotonic seconds. Scope: one finalizer operation.
    # Consumers: Qt progress timing. Rationale: Copy real worker clocks only.
    progress_started: float = 0.0
    progress_phase_started: float = 0.0
    # Type: float | None. Units: seconds. Scope: current measured transfer phase.
    # Consumers: Qt progress timing. Rationale: Preserve the finalizer's observed ETA.
    eta_seconds: float | None = None
    rendering_languages: tuple[str, ...] = ()
    rendered_languages: tuple[str, ...] = ()
    # Type: bool. Units: startup phase. Scope: one active Watcher worker.
    # Consumers: QtCapture. Rationale: Keep the native busy state visible until
    # the existing initial directory snapshot is accepted by the worker.
    watcher_starting: bool = False


def watcher_interrupt_available(
    watcher_active: bool, server_request_sent: bool,
) -> bool:
    """Match Tk's Stop action to its live-worker and request boundary.

    Args:
        watcher_active (bool): Whether the actual Watcher worker is alive.
        server_request_sent (bool): Whether its finalizer crossed the upload
            request boundary.
    Returns:
        bool: True only while the existing Watcher Stop command is admissible.
    """
    return watcher_active and not server_request_sent


@dataclass(frozen=True)
class WatchFolderInspection:
    """Carry the selected folder and summary counts to the UI."""

    folder: str
    supported: int
    unsupported: int
    error: str = ''


@dataclass(frozen=True)
class RetainedQueueSummary:
    """Carry a bounded read-only count for one exact retained Watcher binding.

    Attributes:
        session_count (int): Matching unfinished flight sessions in stable order.
        photo_count (int | None): Unresolved entries in the oldest queue, or None
            when that session has no committed photo journal.
        head_name (str): Basename of the oldest queued source, without its path.
    """

    session_count: int
    photo_count: int | None
    head_name: str


def inspect_retained_queue(
    root: Path, binding: str, cancelled: Event,
) -> RetainedQueueSummary | None:
    """Read retained queue counts without repairing or resuming the journal.

    Args:
        root (Path): Existing profile flight workspace.
        binding (str): Exact saved legacy or linked upload fingerprint.
        cancelled (Event): Current UI request fence.
    Returns:
        RetainedQueueSummary | None: Bounded summary, or None after cancellation.
    Raises:
        OSError: A matching journal cannot be read safely.
        ValueError: Matching plan or journal metadata is inconclusive.
    """
    if cancelled.is_set():
        return None
    plans = pending_sessions(root, binding)
    if cancelled.is_set():
        return None
    if not plans:
        return RetainedQueueSummary(0, 0, '')
    plan = plans[0]
    journal_path = plan.directory / 'watch-drain.json'
    try:
        journal = WatchQueueStore.read_journal(journal_path)
    except FileNotFoundError:
        return None if cancelled.is_set() else RetainedQueueSummary(
            len(plans), None, '',
        )
    if cancelled.is_set():
        return None
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
        raise ValueError('Retained capture journal needs review.')
    head_name = Path(pending[0]).name if pending else ''
    if cancelled.is_set():
        return None
    return RetainedQueueSummary(len(plans), len(pending), head_name)


def inspect_watch_folder(
    folder: Path, supported_suffixes: set[str], recursive: bool,
    cancelled: Event,
) -> WatchFolderInspection | None:
    """Use shared discovery rules for a cancellable read-only folder summary.

    Args:
        folder (Path): Current configured Watcher directory.
        supported_suffixes (set[str]): Shared image suffix allowlist.
        recursive (bool): Whether the saved Watcher options include descendants.
        cancelled (Event): Read-lane fence checked before and after discovery.
    Returns:
        WatchFolderInspection | None: Count-only result, or no result after cancel.
    """
    if cancelled.is_set():
        return None
    try:
        if not folder.is_dir():
            return WatchFolderInspection(str(folder), 0, 0, 'missing')
        counts = inspect_folder_counts(
            folder, supported_suffixes, recursive, cancelled,
        )
    except (OSError, ValueError, RuntimeError):
        return WatchFolderInspection(str(folder), 0, 0, 'failed')
    if counts is None or cancelled.is_set():
        return None
    return WatchFolderInspection(
        str(folder), counts[0], counts[1],
    )


@dataclass(frozen=True)
class CaptureReview:
    """Carry one exact recovery decision witness and its enabled operations."""

    snapshot: WatchRecoverySnapshot
    can_receipt: bool
    can_approve: bool
    preview: bytes | None = None
    target_label: str = ''


class CaptureCommands(Protocol):
    """Desktop commands presented by Qt without exposing a credential to widgets."""

    events: CaptureEvents

    def start(self) -> None:
        """Start the configured Watcher and Flight recorder with current authority.

        Returns:
            None: Starts admitted work or raises before new capture is active.
        """

    def start_contextual(self, target: LinkedUploadTarget) -> None:
        """Start Watcher with a registered temporary key for this Photos gallery.

        Args:
            target (LinkedUploadTarget): Currently verified selected target and key.
        Returns:
            None: Transfers the key to the admitted worker when startup succeeds.
        """

    def retry_context_cleanup(self) -> None:
        """Retry closure of the exact temporary key after its writers stopped.

        Returns:
            None: Clears the retained key only after verified closure.
        """

    def finish(self) -> None:
        """Finish the original queue, telemetry and eligible report delivery.

        Returns:
            None: Starts the common finalization worker.
        """

    def force_finish(self) -> bool:
        """Persist terminal retention for the exact active Finish plans.

        Returns:
            bool: True only after queue and plan abandonment read back.
        """

    def revoke_legacy(self) -> None:
        """Finish verified Watcher work and revoke its saved legacy key.

        Returns:
            None: Starts the shared finalizer after explicit UI confirmation.
        """

    def complete_legacy_revocation(self, operation: FinalizationWorker) -> bool:
        """Clear the saved key after this operation's positive server proof.

        Args:
            operation (FinalizationWorker): Exact worker from its revoke event.
        Returns:
            bool: True only after atomic local cleanup; False retains recovery state.
        """

    def abandon_capture(self) -> bool:
        """Stop and close the captured Watcher plans under their current mode.

        Returns:
            bool: True after terminal plan readback and any exact local key cleanup.
        """

    def complete_legacy_abandon(self) -> bool:
        """Retry only the saved local key cleanup after durable abandonment.

        Returns:
            bool: True after the exact captured key was removed from settings.
        """

    def interrupt(self) -> None:
        """Persist the retained cutoff and stop capture under the live leases.

        Returns:
            None: The exact original sources remain available for review.
        """

    def recover(self) -> None:
        """Acquire an existing unambiguous queue in review-only mode.

        Returns:
            None: The operator still controls any head decision or new transfer.
        """

    def close_retained(self) -> None:
        """Close the exact inactive capture locally with its sources retained.

        Returns:
            None: Reads back terminal journal and plan markers under their leases.
        """

    def reset_watcher(self) -> None:
        """Apply the explicitly authorized Watcher-only factory reset.

        Returns:
            None: Persists the same default profile and retains unrelated files.
        """

    def checkpoint_restart(self) -> None:
        """Pause the existing acquisition without creating a Finish cutoff.

        Returns:
            None: A verified same-version continuation is ready for relaunch.
        """

    def checkpoint_update(self, ticket: Path) -> None:
        """Bind the same active capture to a verified installer helper ticket.

        Args:
            ticket (Path): Exact prepared private installer ticket.
        Returns:
            None: Pauses the capture and binds its original identity before proceed.
        """

    def resume_checkpoint(self) -> None:
        """Continue the admitted original Watcher and flight recording.

        Returns:
            None: Opens paused writers only after the original consent is consumed.
        """

    def refresh(self) -> None:
        """Request a bounded local rescan without resetting the existing FIFO.

        Returns:
            None: Signals the current worker if it has a queue.
        """

    def set_preferences(self, preferences: FlightPreferences) -> None:
        """Change Flight preferences and the current acquisition gate.

        Args:
            preferences (FlightPreferences): Current user-selected recording,
                report and language choices.
        Returns:
            None: Applies the preference under the flight workspace owner.
        """

    def review(self) -> CaptureReview | None:
        """Read the actual queue head and validate decision availability.

        Returns:
            CaptureReview | None: Exact current review evidence or no live owner.
        """

    def review_remote(
        self, review: CaptureReview, cancelled: Event,
    ) -> WatchRecoveryRemote | None:
        """Read optional remote evidence under the original recovery target.

        Args:
            review (CaptureReview): Exact snapshot held by the visible dialog.
            cancelled (Event): Set when the dialog is dismissed or replaced.

        Returns:
            WatchRecoveryRemote | None: Authorized preview bytes and evidence
                kind, or None when the request is stale or unavailable.
        """

    def dispatch_identity(self) -> str:
        """Read the last successfully loaded SimBrief account identity.

        Returns:
            str: The saved pilot ID or username, or empty on first use.
        """

    def load_dispatch(self, identity: str) -> str:
        """Fetch an OFP for the current capture or the next flight.

        Args:
            identity (str): Explicit user-entered SimBrief pilot ID or username.
        Returns:
            str: The accepted planned airport pair, ready for display.
        """

    def inspect_watch_folder(self, cancelled: Event) -> WatchFolderInspection | None:
        """Inspect the configured source without touching Watcher state.

        Args:
            cancelled (Event): Current read-view cancellation fence.
        Returns:
            WatchFolderInspection | None: Counts only, or no result if cancelled.
        """

    def delete_uploaded_after_upload(self) -> bool:
        """Read the effective saved and Watcher-profile deletion preference.

        Returns:
            bool: True only when the current Watcher source policy deletes originals.
        """

    def set_utc_sync(self, enabled: bool) -> bool:
        """Persist and apply the Watcher UTC preference through its owner.

        Args:
            enabled (bool): Requested opt-in state.
        Returns:
            bool: The accepted saved preference.
        Raises:
            OSError: The current configuration owner could not save the choice.
            RuntimeError: Finalization or reset prevents a safe change.
        """

    def load_saved_dispatch(self, session: Path, identity: str) -> str:
        """Fetch and attach an OFP to one exact stopped saved flight.

        Args:
            session (Path): Selected completed session directory.
            identity (str): Explicit SimBrief pilot ID or username.
        Returns:
            str: Accepted planned airport pair for the saved report detail.
        """

    def clear_dispatch(self) -> None:
        """Detach the active or staged flight plan under its existing owner.

        Returns:
            None: Releases the selected route without rewriting saved reports.
        """

    def attach_dispatch(self, session: Path, source: Path) -> str:
        """Attach a selected archived OFP to a stopped saved flight.

        Args:
            session (Path): Exact selected saved flight directory.
            source (Path): User-selected local JSON or XML OFP.
        Returns:
            str: The accepted planned airport pair.
        """

    def report_target(self) -> tuple[str, str] | None:
        """Project an active Watcher or current verified upload destination.

        Returns:
            tuple[str, str] | None: Exact binding and display name, when
                available without restoring or starting a connection.
        """

    def prepare_report_upload(
        self, directory: Path, editions: tuple[tuple[str, str], ...], binding: str,
    ) -> tuple[FinalizationWorker, WorkspaceLease | None]:
        """Verify saved editions and return their worker plus lease ownership.

        Args:
            directory (Path): Exact approved stopped flight.
            editions (tuple[tuple[str, str], ...]): Selected revision and language IDs.
            binding (str): Destination shown in the approval dialog.
        Returns:
            tuple[FinalizationWorker, WorkspaceLease | None]: Unstarted report-only
                delivery worker and an independent lease to release after it ends;
                None means the active capture owner retains the lease.
        """

    def prepare_report_creation(self, directory: Path, binding: str) -> FinalizationWorker:
        """Bind a new PDF revision to the currently active Watcher destination.

        Args:
            directory (Path): Stopped flight whose archive owns the new revision.
            binding (str): Exact active target selected for local render and delivery.
        Returns:
            FinalizationWorker: Existing worker for creation and delivery as one action.
        """

    def reconcile_workers(self, *, retire_context: bool = True) -> None:
        """Stop source acquisition after the photograph owner has truly reached exit.

        Args:
            retire_context (bool): No dependent report or gallery writer still
                owns the stopped capture's temporary access.
        Returns:
            None: Keeps the recorder and UTC lifetime tied to the exact capture.
        """

    def decide(self, review: CaptureReview, decision: CaptureDecision) -> None:
        """Apply only the operator's confirmed decision to the reviewed head.

        Args:
            review (CaptureReview): Exact immutable snapshot last shown.
            decision (CaptureDecision): Explicit requested disposition.
        Returns:
            None: Persists a shared decision and wakes the corresponding worker.
        """

    def snapshot(self) -> CaptureStatus:
        """Project current activity without making any server or simulator call.

        Returns:
            CaptureStatus: Scalar desktop state and recent instrument sample.
        """

    def inspect_retained_queue(
        self, cancelled: Event,
    ) -> RetainedQueueSummary | None:
        """Read a retained queue summary for the current saved binding.

        Args:
            cancelled (Event): Current UI read-lane cancellation fence.
        Returns:
            RetainedQueueSummary | None: Count-only local metadata, or no result
                after cancellation.
        Raises:
            OSError: The exact saved journal could not be read safely.
            ValueError: The saved target or matching queue is inconclusive.
        """

    def close(self) -> None:
        """Release the session owner only after every writer has stopped.

        Returns:
            None: Releases the existing workspace lease when safe.
        """


def prepare_capture_recording(
    workspace: Path, preferences: FlightPreferences, binding: str,
    gallery: str, dll_path: str, emit: Callable[[str, str], None], *,
    dispatch: DispatchPlan | None = None, photos_only: bool = False,
    factory: Callable[[Path, str, str, Callable[[str, str], None]], FlightRecorder] = FlightRecorder,
) -> tuple[FlightRecorder, SessionPlan]:
    """Prepare the same recorder and durable session plan for each desktop.

    Args:
        workspace (Path): Flight workspace already leased by the caller.
        preferences (FlightPreferences): Already selected recording/report choices.
        binding (str): Exact saved gallery or API-key destination fingerprint.
        gallery (str): Non-secret identity recorded in the session metadata.
        dll_path (str): User-selected SimConnect DLL or empty for normal resolution.
        emit (Callable[[str, str], None]): Thread-safe status sink.
        dispatch (DispatchPlan | None): Current explicitly loaded SimBrief plan.
        photos_only (bool): Suppress reports for this capture.
        factory (Callable[[Path, str, str, Callable[[str, str], None]], FlightRecorder]):
            Recorder accepting the workspace, gallery, DLL and event sink.
    Returns:
        tuple[FlightRecorder, SessionPlan]: Prepared idle recorder and exact
            durably stored plan. The desktop starts both workers after admission.
    """
    recorder = factory(workspace, gallery, dll_path, emit)
    if not preferences.recording:
        recorder.pause()
    directory = recorder.directory
    directory.mkdir(parents=True, exist_ok=True)
    if dispatch is not None:
        dispatch.save(directory)
    plan = SessionPlan(
        directory=directory, binding=binding, languages=preferences.languages,
        reports_enabled=not photos_only and preferences.reports and preferences.recording,
        local_only=False,
    )
    plan.save()
    return recorder, plan


def persist_retained_capture_closure(
    profile: Path, lease: WorkspaceLease, plan: SessionPlan, linked: bool,
    cutoff: float, step: Callable[[str], None], *,
    active_pipeline: UploadPipelineLease | None = None,
    active_target: LinkedTargetLease | None = None,
) -> None:
    """Close one inactive reviewed capture under its original durable owners.

    Args:
        profile (Path): Existing uploader profile containing the pipeline lock.
        lease (WorkspaceLease): Exact stopped flight workspace and authoring lease.
        plan (SessionPlan): Original inspected and explicitly selected plan.
        linked (bool): Whether the saved local mode uses an exact linked target.
        cutoff (float): Unix timestamp of the explicit local closure.
        step (Callable[[str], None]): Receives the current fixed diagnostic stage.
        active_pipeline (UploadPipelineLease | None): Original live writer's
            pipeline lease held in its Force Finish publication fence, if any.
        active_target (LinkedTargetLease | None): Original live writer's lease
            for this exact linked binding, if held by the same publication fence.
    Returns:
        None: The original journal and plan have durable local terminal markers.
    """
    with ExitStack() as ownership:
        step('pipeline_lease')
        if active_pipeline is None:
            pipeline = UploadPipelineLease(profile)
            pipeline.acquire()
            ownership.callback(pipeline.release)
        else:
            if active_pipeline._native is None:
                raise RuntimeError('Active upload pipeline ownership is unavailable.')
            active_pipeline._native.verify()
        if linked:
            step('target_lease')
            if active_target is None:
                target = LinkedTargetLease(profile, plan.binding)
                target.acquire()
                ownership.callback(target.release)
            else:
                if active_target._native is None or active_target._binding != plan.binding:
                    raise RuntimeError('Active linked target ownership is unavailable.')
                active_target._native.verify()
        step('workspace_and_plan')
        lease.verify()
        if (plan.directory.resolve(strict=True) != lease.root.resolve(strict=True) / plan.session_id
                or SessionPlan.load(plan.directory) != plan):
            raise ValueError('Retained flight plan changed during review.')
        step('journal_read')
        path = plan.directory / 'watch-drain.json'
        try:
            row = WatchQueueStore.read_journal(path)
        except FileNotFoundError:
            # The recorder plan may be created before the first photo journal.
            pass
        else:
            step('journal_validate')
            folder, recursive, scope = row.get('folder'), row.get('recursive'), row.get('scope', '')
            if (not isinstance(folder, str) or not Path(folder).is_absolute()
                    or type(recursive) is not bool or not isinstance(scope, str)
                    or (row.get('schema_version') == 2 and bool(scope) != linked)):
                raise ValueError('Retained capture boundary is unavailable.')
            journal = WatchQueueStore(path, plan.binding, Path(folder), recursive, scope=scope)
            journal.restore(for_close=True)
            step('journal_terminal_write')
            journal.force_finish(cutoff)
        step('plan_terminal_write')
        plan.persist_abandoned(lease)
        step('lease_release')


def verify_unfrozen_capture(
    lease: WorkspaceLease, record: WatcherContinuation,
) -> tuple[SessionPlan, WatchQueueStore]:
    """Verify exact restart evidence without consuming consent or opening a writer.

    Args:
        lease (WorkspaceLease): Current native flight owner.
        record (WatcherContinuation): Exact selected restart marker.
    Returns:
        tuple[SessionPlan, WatchQueueStore]: Original plan and ordered read-only queue.
    Raises:
        ValueError: The capture is terminal, ambiguous, or needs explicit review.
    """
    lease.verify()
    directory = lease.root / record.session_id
    if directory.is_symlink() or directory.resolve(strict=True).parent != lease.root.resolve(strict=True):
        raise ValueError('The accepted session could not be verified.')
    plan = SessionPlan.load(directory)
    path = directory / 'watch-drain.json'
    row = WatchQueueStore.read_journal(path)
    folder, recursive = row.get('folder'), row.get('recursive')
    if (plan.binding != record.binding or plan.completed or plan.abandoned or plan.local_only
            or not isinstance(folder, str) or not Path(folder).is_absolute()
            or type(recursive) is not bool or row.get('frozen') is not False
            or row.get('cutoff') != 0 or row.get('error')):
        raise ValueError('The Watcher queue needs review before updating.')
    queue = WatchQueueStore(path, plan.binding, Path(folder), recursive, scope=record.approved_scope)
    queue.restore(read_only=True)
    if (queue.force_finished or queue.data.get('retained_stop') is True
            or queue.data.get('order_verified') is not True
            or queue.data.get('recovery_intent') or queue.data.get('approved_upload')
            or any(item.state in ('uncertain', 'blocked', 'auth_required') for item in queue.items())):
        raise ValueError('The Watcher queue needs review before updating.')
    return plan, queue
