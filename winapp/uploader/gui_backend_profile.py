# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gui_backend_profile.py
# Module Type: Desktop profile admission service
# Purpose: Keep recoverable WinApp work with its qualified desktop owner.
# Responsibilities: Inspect accepted work without modifying any work document.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Evaluate whether a profile can enter Qt without orphaning existing work."""

from dataclasses import dataclass
import math
from pathlib import Path
import re
from typing import Literal

from .watcher.capture_session import verify_unfrozen_capture
from .flight_jobs import SessionPlan, WatcherContinuation, WorkspaceLease, read_document
from .models import (
    ImportJob, JsonValue, LEGACY_UPLOAD_UNCERTAIN_REASON,
    LINKED_UPLOAD_UNCERTAIN_REASON,
)
from .state_store import (
    JobStateStore,
    accepted_work_owner,
    linked_upload_state_path,
    load_upload_state_for_mutation,
    read_import_binding_entries,
)
from .watcher.queue import WatchQueueStore
from .watcher.profile import WATCHER_CONTINUATION_FILES, WatcherProfile, _local_entry

GuiProfileRefusal = Literal[
    'profile', 'import', 'watcher_profile', 'watcher_restart', 'flight_journal',
    'once_journal', 'upload_history',
]


@dataclass(frozen=True)
class GuiProfileWork:
    """Identify a refused launch or explicit recovery rows for the new owner.

    The refusal stage is a fixed diagnostic label safe for the support log.
    """

    blocked: bool
    recoverable_imports: tuple[str, ...] = ()
    recoverable_capture: bool = False
    restart: WatcherContinuation | None = None
    reset_pending: bool = False
    refusal_stage: GuiProfileRefusal | None = None


@dataclass(frozen=True)
class CaptureAdmission:
    """Carry read-only ownership proof for retained capture sessions."""

    blocked: bool = False
    pending: bool = False
    uncertain: frozenset[tuple[str, str]] = frozenset()


def _inspect_captures(
    root: Path, *, once: bool = False, binding: str | None = None, scope: str = '',
    admitted_markers: bool = False,
) -> CaptureAdmission:
    """Recognize retained Watcher work without opening a capture writer.

    Args:
        root (Path): Owned flight or one-scan workspace containing sessions.
        once (bool): Whether empty reusable one-scan journals are expected.
        binding (str | None): Verified current capture target supporting recovery.
        scope (str): Exact approved linked scope or empty for API-key sessions.
        admitted_markers (bool): The top-level ordinary restart markers were verified.
    Returns:
        CaptureAdmission: Refusal, retained work and exact uncertain head witnesses.
    Raises:
        OSError: Workspace evidence is redirected or unavailable.
        ValueError: An existing plan or journal cannot be safely interpreted.
    """
    pending_capture = False
    uncertain: set[tuple[str, str]] = set()
    if not _local_entry(root, directory=True):
        return CaptureAdmission()
    for entry in root.iterdir():
        if entry.is_symlink():
            return CaptureAdmission(blocked=True)
        if entry.name in WATCHER_CONTINUATION_FILES:
            if admitted_markers:
                continue
            return CaptureAdmission(blocked=True)
        if not entry.is_dir() or not _local_entry(entry, directory=True):
            continue
        if any(_local_entry(entry / name) for name in WATCHER_CONTINUATION_FILES):
            return CaptureAdmission(blocked=True)
        plan_file = entry / "completion.json"
        plan = SessionPlan.load(entry) if _local_entry(plan_file) else None
        meta_file = entry / "session.json"
        stopped = False
        if _local_entry(meta_file):
            meta = read_document(meta_file)
            stopped = meta.get("recording_ended") is True
            if not stopped and plan is None:
                return CaptureAdmission(blocked=True)
        manual = entry / "manual-report.json"
        if _local_entry(manual):
            report = read_document(manual)
            phase, revision = report.get('phase'), report.get('revision')
            # Local report schema 1 reserves a 32-digit UUID for each new edition.
            if phase not in ('completed', 'cancelled') and (
                not stopped or plan is None or phase not in ('analyzing', 'generating', 'error')
                or type(report.get('schema_version')) is not int or report['schema_version'] != 1
                or not isinstance(revision, str) or re.fullmatch(r'manual-[0-9a-f]{32}', revision) is None
            ):
                return CaptureAdmission(blocked=True)
        journal = entry / "watch-drain.json"
        exists = _local_entry(journal)
        if plan is not None and not plan.completed:
            if (once or not binding or plan.binding != binding
                    or not exists or plan.local_only or plan.abandoned):
                return CaptureAdmission(blocked=True)
            row = WatchQueueStore.read_journal(journal)
            folder, recursive = row.get('folder'), row.get('recursive')
            if not isinstance(folder, str) or not Path(folder).is_absolute() or type(recursive) is not bool:
                return CaptureAdmission(blocked=True)
            # A pre-scope journal can inherit the legacy API-key binding
            # proven by its plan. It cannot establish linked grant scope.
            if scope and row.get('schema_version', 1) == 1:
                return CaptureAdmission(blocked=True)
            queue = WatchQueueStore(journal, binding, Path(folder), recursive, scope=scope)
            queue.restore(read_only=True, for_close=True)
            if queue.data.get('order_verified') is not True:
                return CaptureAdmission(blocked=True)
            pending_capture = True
            head = queue.head()
            if head is not None and head.state == 'uncertain' and head.sha256:
                uncertain.add((head.source.path, head.sha256))
        elif exists:
            row = WatchQueueStore.read_journal(journal)
            pending = row.get('pending')
            if plan is not None and plan.abandoned and row.get('force_finished') is True:
                # A locally closed session deliberately keeps its pending list as evidence.
                continue
            # Before Force Finish, a completed local-only plan closed the stopped
            # flight. Its frozen schema-2 journal retained the original paths.
            # Keep reading those saved flights without treating them as resumable.
            if (not once and plan is not None and plan.abandoned and plan.local_only
                    and stopped and row.get('schema_version') == 2
                    and row.get('binding') == plan.binding
                    and row.get('session_id') == plan.session_id
                    and row.get('frozen') is True and row.get('completed') is False
                    and 'force_finished' not in row and 'force_finished_at' not in row
                    and isinstance(pending, list)
                    and all(isinstance(source, str) and Path(source).is_absolute() for source in pending)):
                continue
            if not isinstance(pending, list) or pending:
                return CaptureAdmission(blocked=True)
            if not once and plan is None and row.get("completed") is not True:
                return CaptureAdmission(blocked=True)
    return CaptureAdmission(pending=pending_capture, uncertain=frozenset(uncertain))


def _inspect_import_queue(
    profile: Path, job: ImportJob, binding: str, scope: str,
) -> frozenset[tuple[str, str]] | None:
    """Verify an import's original selection and queue without normalization.

    Args:
        profile (Path): Current profile owned by desktop admission.
        job (ImportJob): Fresh durable job with its recorded binding verified.
        binding (str): Verification of the exact currently selected connection.
        scope (str): Original approved linked scope or empty for API-key uploads.
    Returns:
        frozenset[tuple[str, str]] | None: Exact uncertain source/digest
            witnesses, possibly empty. None means there is no verified owner.
    Raises:
        OSError: Journal or its path could not be read safely.
        ValueError: Queue contains invalid identity, scope or FIFO evidence.
    """
    if re.fullmatch(r'[0-9a-f]{32}', job.id) is None:
        return None
    queue_root = profile / 'import-queues'
    directory = queue_root / job.id
    path = directory / 'watch-drain.json'
    if (not _local_entry(queue_root, directory=True)
            or not _local_entry(directory, directory=True) or not _local_entry(path)):
        # An interruption before the queue exists can preserve exact order
        # only if this is a one-item selection whose transfer never began.
        if len(job.items) == 1 and not job.items[0].attempt_count:
            return frozenset()
        return None
    queue = WatchQueueStore(
        path, binding, profile.absolute(), False, scope=scope,
        allowed_paths=tuple(item.path for item in job.items),
    )
    queue.restore(read_only=True)
    if (queue.data.get('order_verified') is not True or queue.data.get('recovery_intent')
            or queue.data.get('approved_upload')):
        return None
    original_by_path = {item.path: item for item in job.items}
    uncertain: set[tuple[str, str]] = set()
    for item in queue.items():
        if item.state != 'uncertain':
            continue
        original = original_by_path.get(item.source.path)
        if original is None or not item.sha256 or original.sha256 != item.sha256:
            return None
        uncertain.add((item.source.path, item.sha256))
    for original in job.items:
        if original.reason in (LEGACY_UPLOAD_UNCERTAIN_REASON, LINKED_UPLOAD_UNCERTAIN_REASON):
            if (original.path, original.sha256) not in uncertain:
                return None
    return frozenset(uncertain)


def _standalone_linked_failure_is_valid(source: str, failure: JsonValue) -> bool:
    """Recognize one unreviewed linked uncertainty row without granting retry.

    Args:
        source (str): Original source-name key in the target's failure ledger.
        failure (JsonValue): Serialized failure row from the safe target ledger.
    Returns:
        bool: True only for the known absolute-path uncertainty marker shape.
    """
    if not Path(source).is_absolute() or not isinstance(failure, dict):
        return False
    if set(failure).difference({
        'sha256', 'uncertain', 'last_error', 'last_failed_at', 'approval_token',
    }):
        return False
    digest = failure.get('sha256')
    if (failure.get('uncertain') is not True or not isinstance(digest, str)
            or re.fullmatch(r'[a-f0-9]{64}', digest) is None):
        return False
    if 'last_error' in failure and not isinstance(failure['last_error'], str):
        return False
    if 'last_failed_at' in failure:
        failed_at = failure['last_failed_at']
        if type(failed_at) not in (int, float):
            return False
        try:
            if not math.isfinite(float(failed_at)) or failed_at < 0:
                return False
        except (OverflowError, ValueError):
            return False
    token = failure.get('approval_token')
    if 'approval_token' in failure and (
        not isinstance(token, str) or re.fullmatch(r'[a-f0-9]{32}', token) is None
    ):
        return False
    return True


def inspect_gui_profile_work(
    profile: Path, *, legacy_import_binding: str | None = None,
    capture_binding: str | None = None, capture_scope: str = '',
    restart_version: str | None = None,
    installer_restart: tuple[str, str] | None = None,
    linked_history_recovery: bool = False,
) -> GuiProfileWork:
    """Open Qt only when each accepted operation has its qualified UI owner.

    Args:
        profile (Path): Profile already admitted by the desktop instance owner.
        legacy_import_binding (str | None): Verified exact Windows API-key
            binding whose worker supports explicit Import recovery, or None.
        capture_binding (str | None): Exact current target with native capture recovery.
        capture_scope (str): Exact linked scope or empty for an API key.
        restart_version (str | None): Version supporting ordinary restart consent.
        installer_restart (tuple[str, str] | None): Verified helper nonce and
            its exact installed target version, or None for an ordinary launch.
        linked_history_recovery (bool): Admit at most one safe standalone linked
            uncertainty marker per target ledger for native review-only UI.
    Returns:
        GuiProfileWork: Read-only refusal or stable explicitly recoverable job IDs.
            Unknown or incompatible work retains its qualified owner and records
            a fixed refusal stage suitable for diagnostics.
    """
    stage: GuiProfileRefusal = 'profile'
    if type(linked_history_recovery) is not bool:
        return GuiProfileWork(True, refusal_stage=stage)
    restart = None
    try:
        flights = profile / "flights"
        _local_entry(flights, directory=True)
        with accepted_work_owner(profile):
            stage = 'import'
            state = profile / "upload_state.json"
            jobs = JobStateStore(state).inspect_jobs_for_target_change()
            bindings = read_import_binding_entries(flights / "import-bindings.json")
            if set(bindings).difference(job.id for job in jobs):
                return GuiProfileWork(True, refusal_stage=stage)
            recoverable = []
            import_uncertain: set[tuple[str, str]] = set()
            import_binding = capture_binding if capture_scope else legacy_import_binding
            for job in jobs:
                has_unknown = any(
                    item.reason in (LEGACY_UPLOAD_UNCERTAIN_REASON, LINKED_UPLOAD_UNCERTAIN_REASON)
                    for item in job.items
                )
                if not job.recoverable() and job.finished_at and not has_unknown:
                    continue
                if not import_binding or bindings.get(job.id) != import_binding or not job.started_at:
                    return GuiProfileWork(True, refusal_stage=stage)
                verified = _inspect_import_queue(profile, job, import_binding, capture_scope)
                if verified is None:
                    return GuiProfileWork(True, refusal_stage=stage)
                import_uncertain.update(verified)
                recoverable.append(job)
            stage = 'watcher_profile'
            watcher = WatcherProfile.load(flights)
            reset_pending = watcher is not None and watcher.pending
            if reset_pending and restart_version is None:
                return GuiProfileWork(True, refusal_stage=stage)
            compatible_binding = capture_binding if watcher is None or watcher.binding == capture_binding else None
            admitted_markers = False
            # The dedicated reset owner retires the earlier markers itself.
            # Requiring their active format here could strand an admitted reset.
            stage = 'watcher_restart'
            markers_present = not reset_pending and any(
                _local_entry(flights / name) for name in WATCHER_CONTINUATION_FILES
            )
            if markers_present:
                lease = WorkspaceLease(flights)
                try:
                    primary = WatcherContinuation.load(lease)
                    ordinary = WatcherContinuation.load(lease, ordinary_restart=True)
                    terminal = ('cancelled', 'consumed')
                    paired_terminal = (
                        primary is not None and ordinary is not None
                        and primary.phase in terminal and ordinary.phase in terminal
                    )
                    solo_terminal = (
                        (primary is not None and primary.phase in terminal and ordinary is None)
                        or (primary is None and ordinary is not None and ordinary.phase in terminal)
                    )
                    if paired_terminal or solo_terminal:
                        admitted_markers = True
                    elif (restart_version is not None and compatible_binding is not None
                            and primary is not None and not primary.legacy_source_pid
                            and primary.phase in ('prepared', 'claimed')
                            and primary.binding == compatible_binding and primary.approved_scope == capture_scope):
                        ordinary_match = (installer_restart is None and primary == ordinary
                                          and primary.source_version == primary.target_version == restart_version)
                        installer_match = (installer_restart == (primary.update_session, primary.target_version)
                                           and restart_version in (primary.source_version, primary.target_version)
                                           and (ordinary is None or ordinary.phase in terminal))
                        if ordinary_match or installer_match:
                            verify_unfrozen_capture(lease, primary)
                            restart, admitted_markers = primary, True
                finally:
                    lease.release()
                if not admitted_markers:
                    return GuiProfileWork(True, refusal_stage=stage)
            stage = 'flight_journal'
            capture = CaptureAdmission() if reset_pending else _inspect_captures(
                flights, binding=compatible_binding, scope=capture_scope, admitted_markers=admitted_markers,
            )
            if capture.blocked:
                return GuiProfileWork(True, refusal_stage=stage)
            stage = 'once_journal'
            if not reset_pending and _inspect_captures(profile / "once-queues", once=True).blocked:
                return GuiProfileWork(True, refusal_stage=stage)
            stage = 'upload_history'
            capture_history = (
                linked_upload_state_path(profile, compatible_binding)
                if compatible_binding and capture.pending and capture_scope else state
            )
            import_history = (
                linked_upload_state_path(profile, import_binding)
                if import_binding and capture_scope else state
            )
            histories = [state] if _local_entry(state) else []
            linked_bindings: dict[Path, str] = {}
            linked = profile / "linked-upload-state"
            if _local_entry(linked, directory=True):
                for entry in linked.iterdir():
                    if entry.suffix != '.json':
                        continue
                    target_binding = entry.stem
                    if re.fullmatch(r'[a-f0-9]{64}', target_binding) is None:
                        return GuiProfileWork(True, refusal_stage=stage)
                    history = linked_upload_state_path(profile, target_binding)
                    if history != entry:
                        return GuiProfileWork(True, refusal_stage=stage)
                    histories.append(history)
                    linked_bindings[history] = target_binding
            standalone_counts: dict[str, int] = {}
            for history in histories:
                failures = load_upload_state_for_mutation(history)["failures"]
                for source, failure in failures.items():
                    if not isinstance(failure, dict):
                        return GuiProfileWork(True, refusal_stage=stage)
                    if history in linked_bindings and (
                        ('uncertain' in failure and type(failure['uncertain']) is not bool)
                        or ('reviewed' in failure and (
                            failure['reviewed'] is not True
                            or failure.get('uncertain') is not True
                        ))
                    ):
                        return GuiProfileWork(True, refusal_stage=stage)
                    if failure.get('uncertain') is not True or failure.get('reviewed') is True:
                        continue
                    target_binding = linked_bindings.get(history)
                    if target_binding is not None:
                        standalone_counts[target_binding] = (
                            standalone_counts.get(target_binding, 0) + 1
                        )
                        if standalone_counts[target_binding] > 1:
                            return GuiProfileWork(True, refusal_stage=stage)
                    digest = failure.get('sha256')
                    if (not isinstance(source, str) or not isinstance(digest, str)
                            or re.fullmatch(r'[a-f0-9]{64}', digest) is None):
                        return GuiProfileWork(True, refusal_stage=stage)
                    witness = source, digest
                    if history == capture_history and witness in capture.uncertain:
                        continue
                    if history == import_history and witness in import_uncertain:
                        continue
                    if (not linked_history_recovery or target_binding is None
                            or not _standalone_linked_failure_is_valid(source, failure)):
                        return GuiProfileWork(True, refusal_stage=stage)
                    if ((target_binding == compatible_binding and capture.pending)
                            or (restart is not None and target_binding == restart.binding)):
                        return GuiProfileWork(True, refusal_stage=stage)
    except (OSError, ValueError, RuntimeError, TypeError, RecursionError):
        return GuiProfileWork(True, refusal_stage=stage)
    recoverable.sort(key=lambda job: job.created_at, reverse=True)
    return GuiProfileWork(False, tuple(job.id for job in recoverable), capture.pending, restart, reset_pending)
