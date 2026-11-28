# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/workers/watcher.py
# Module Type: Windows Watcher Worker
# Purpose: Observe a configured folder and drain its durable FIFO upload queue.
# Responsibilities:
#   - Coordinate Watcher scans, retry state, linked transport and recovery.
#   - Preserve one-use continuation admission and retained capture boundaries.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own the background Watcher that scans and uploads stable files."""

import hashlib
import json
import logging
import os
import re
import stat
import tempfile
import threading
import time
import uuid
from pathlib import Path
from typing import Any, Dict, Iterable, Optional, Tuple

from ... import http_transport
from ...http_transport import UploadPreparationError
from ...discovery import WatchDirectorySignal, discover_watch_sources
from ...flight_jobs import connection_binding, read_document
from ...http_transport import GalleryKeyRedirectError
from ...linking import (
    LINKING_GALLERY_ID_MAX,
    LinkingRefreshRejected,
    LinkingResponseError,
)
from ...localization import gettext_noop
from ...mode_admission import LEGACY_UPLOAD_STATES
from ...mode_coordinator import (
    CoordinatedWork,
    GuiModeOwner,
    ModeConfigPort,
    ModeCoordinatorError,
)
from ...simconnect_location import SimConnectCameraClient
from ...windows_game_processes import msfs_running
from ...watcher.queue import (
    WATCH_QUEUE_RECONCILE_SECONDS,
    WATCH_QUEUE_STABILITY_MIN_SECONDS,
    WatchQueueItem,
    WatchQueueStore,
    WatchRecoveryError,
    observe_watch_source,
)
from ...watcher.recovery import (
    PREVIEW_MAX_BYTES,
    WatchRecoveryCoordinator,
    WatchRecoveryRemote,
    WatchRecoverySnapshot,
)
from ...linked_upload import (
    LinkedTargetLease,
    LinkedThumbnail,
    LinkedUploadTarget,
    UploadPipelineLease,
    _FileSegment,
    capture_linked_source,
    delete_verified_linked_source,
    verify_linked_source,
)
# Purpose: Bound authorized gallery traversal used only for a recovery preview.
# Type: int. Units: pages. Scope: one recovery dialog's observation fallback.
# Consumers: WatcherThread.load_watch_recovery_remote.
# Rationale: An incomplete catalogue is reported unavailable instead of guessed latest.
WATCH_RECOVERY_PREVIEW_PAGE_LIMIT = 32
# Purpose: Stop admitting recovery preview pages after the elapsed budget.
# Type: float. Units: seconds. Scope: one recovery dialog's observation fallback.
# Consumers: WatcherThread.load_watch_recovery_remote.
# Rationale: One active media request retains its own session timeout; larger
#   traversals are reported unavailable instead of delaying explicit review.
WATCH_RECOVERY_PREVIEW_TIME_LIMIT_SECONDS = 15.0

from .. import runtime, state as watcher_state
from ..capture_session import CaptureEvents
from . import common as watch_common, thumbnails

# Poll the separate update-pause signal without changing Stop's cutoff fence.
# Type: float. Units: seconds. Scope: one watcher scan-interval wait.
# Consumers: WatcherThread._run_owned. Rationale: bounded responsiveness while
# keeping update pause independent from drain_event and stop_event.
WATCHER_UPDATE_PAUSE_POLL_SECONDS = 0.1
# Require the host to durably consume a one-use update checkpoint before a
# resumed watcher may begin its first scan or dispatch.
# Type: float; Units: seconds; Scope: one prepared Watcher continuation.
# Consumers: WatcherThread._run_owned. Rationale: 15 seconds covers ordinary
# Tk event handling while bounding a lost host acknowledgement.
WATCHER_CONTINUATION_ADMISSION_TIMEOUT_SECONDS = 15.0
# Structural Win32 FILE_ATTRIBUTE_REPARSE_POINT mask used by path checks.
# Type: int. Units: attribute bitmask. Scope: update continuation paths.
# Consumers: WatcherThread._validate_continuation_path. Rationale: older Python
# builds do not expose the stat module constant, so retain the documented ABI bit.
WATCHER_REPARSE_POINT_ATTRIBUTE = getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0x0400)




class WatcherThread(threading.Thread):
    """
    Background worker that scans, uploads, and reports status events.

    The worker uses polling rather than OS-specific filesystem notifications.
    Polling is less elegant, but it is robust on Windows network folders, synced
    folders, camera import folders, and other locations where notification APIs
    can behave inconsistently.
    """

    def __init__(
        self, config: watcher_state.WatcherConfig, events: "queue.Queue[Tuple[str, str]]",
        linked_target: Optional[LinkedUploadTarget] = None,
        once_work: Optional[CoordinatedWork] = None,
        gui_admission: Optional[Tuple[GuiModeOwner, ModeConfigPort]] = None,
        watch_folder_override: Optional[Path] = None,
        watch_recursive_override: Optional[bool] = None,
    ) -> None:
        """
        Create a watcher worker.

        Args:
            config (WatcherConfig): Runtime configuration captured when the
                worker starts.
            events (queue.Queue[Tuple[str, str]]): Thread-safe queue used to
                send status messages to the GUI.
            linked_target (Optional[LinkedUploadTarget]): Captured delegated
                destination, or None for the existing API-key workflow.
            once_work (Optional[CoordinatedWork]): Admitted once-mode work to
                recheck before each request; None for ordinary GUI workers.
            gui_admission (Optional[Tuple[GuiModeOwner, ModeConfigPort]]): GUI
                owner and port for this exact captured worker configuration.
            watch_folder_override (Optional[Path]): Previously admitted drain
                folder, or None for the captured configuration folder.
            watch_recursive_override (Optional[bool]): Previously admitted drain
                recursion choice, or None for the captured setting.

        Returns:
            None: Create a watcher worker.
        """
        super().__init__(daemon=True)
        self.config = config
        self.linked_target = linked_target
        self._once_work = once_work
        self._gui_admission = gui_admission
        self._watch_folder_override = watch_folder_override
        self._watch_recursive_override = watch_recursive_override
        self.events = events
        self.stop_event = threading.Event()
        self.update_pause_event = threading.Event()
        self._linked_lease = (LinkedTargetLease(watcher_state.CONFIG_DIR, linked_target.binding())
                              if linked_target is not None else None)
        # New legacy work never reads or rewrites the old unbound history.
        self.state: Optional[watcher_state.LinkedUploadState] = None
        self.stability = watch_common.FileStabilityTracker(config.stable_seconds)
        self.remote_inventory = http_transport.RemoteInventorySession(
            config.inventory_refresh_seconds, self.emit, linked_target,
            once_work.checkpoint if once_work is not None else None,
        )
        self.remote_skipped_paths: Set[Tuple[Path, str]] = set()
        self._legacy_failed_paths: Dict[Tuple[Path, str], Tuple[int, float]] = {}
        self.initial_paths: Set[Path] = set()
        self._admission_lock = threading.RLock()
        self.initial_captured = False
        self.accepted_paths: Set[Path] = set()
        self.drain_event = threading.Event()
        self.drain_only = False
        self.drain_manifest: Optional[Path] = None
        self.drain_requested_at = 0.0
        self.drain_started_monotonic = 0.0
        self.drain_error = ""
        self._continuation_gate: threading.Event | None = None
        self._diagnostic_phase = 'startup'
        self._initialize_queue_runtime()
        self.simconnect_client = SimConnectCameraClient(config.simconnect_dll_path, event_sink=self.emit)
        # Publish a complete snapshot by assignment; the UI never probes SimConnect.
        self.simconnect_diagnostics = self.simconnect_client.diagnostics_snapshot()

    def _initialize_queue_runtime(self) -> None:
        """Create complete local producer/consumer coordination without disk access.

        Returns:
            None: Initializes fresh events, queue ownership and transfer observers.
        """
        self._queue: WatchQueueStore | None = None
        self._producer_thread: threading.Thread | None = None
        self._producer_stop = threading.Event()
        self._producer_wake = threading.Event()
        self._consumer_wake = threading.Event()
        self._scan_lock = threading.Lock()
        self._producer_error = ''
        self._refresh_pending = False
        self._pending_transport: threading.Event | None = None
        self._transport_active = threading.Event()
        self._active_queue_item: WatchQueueItem | None = None
        self._watch_dispatch_started = False
        self._dispatch_observer: Optional[Callable[[], None]] = None
        self._ack_observer: Optional[Callable[[Dict[str, JsonValue]], None]] = None
        self._last_upload_payload: Dict[str, JsonValue] = {}
        self._client_thumbnail_preference = True
        self._initial_hash_attempted: Set[str] = set()
        self._expected_source_hashes: Dict[str, str] = {}
        self._queue_directory_override: Optional[Path] = None
        self._recovery_review_only = False
        self._continue_retained = False
        self._force_stop_requested = threading.Event()
        self._force_finish_requested = threading.Event()
        self._force_finish_publishing = threading.Event()
        self._force_finish_publication_done = threading.Event()
        self._force_finish_publication_done.set()
        self._force_finish_cutoff = 0.0
        self._force_finish_operation_id = 0
        self._retained_stop_publishing = threading.Event()
        self._retained_stop_publication_done = threading.Event()
        self._retained_stop_publication_done.set()
        self._retained_stop_cutoff = 0.0
        self._keep_sources_after_stop = False
        self._recovery_gate = threading.RLock()
        self._force_finish_lease_lock = threading.RLock()
        self._pipeline_lease: UploadPipelineLease | None = None
        self.recovery_coordinator: WatchRecoveryCoordinator | None = None

    def stop(self) -> None:
        """Request cancellation while retaining the durable photograph queue.

        Returns:
            None: Stops observation without acknowledging unfinished files.
        """
        with self._admission_lock:
            self.stop_event.set()

    def request_factory_reset_stop(self) -> None:
        """Stop capture and prevent new dispatch or late source deletion.

        Returns:
            None: Wakes both loops while an already admitted transport keeps
                its owner until the actual request ends.
        """
        self._keep_sources_after_stop = True
        self._force_stop_requested.set()
        self.stop_event.set()
        self._producer_stop.set()
        self._producer_wake.set()
        self._consumer_wake.set()

    def request_update_pause(self) -> None:
        """Finish the current photo operation and pause before another path.

        Returns:
            None: Requests a resumable pause without setting Stop cutoff,
                freezing the queue or interrupting an in-flight acknowledgement.
        """
        pause_event = getattr(self, 'update_pause_event', None)
        if pause_event is not None:
            lock = getattr(self, '_admission_lock', None)
            if lock is None:
                pause_event.set()
            else:
                with lock:
                    pause_event.set()

    def admit_continuation(self) -> bool:
        """Release a resumed watcher only after its nonce is durably consumed.

        Returns:
            bool: True when a continuation gate opened; False when Stop,
                drain or update pause already prevents safe admission. An
                ordinary watcher without a gate is admitted unchanged.
        """
        gate = getattr(self, '_continuation_gate', None)
        if gate is None:
            return True
        with self._admission_lock:
            if gate.is_set():
                return True
            if (self.stop_event.is_set() or self.drain_event.is_set()
                    or self._update_pause_requested()):
                return False
            gate.set()
        return True

    def _update_pause_requested(self) -> bool:
        """Read the independent update pause without changing Stop state.

        Returns:
            bool: True when the worker must leave its current scan boundary.
                Missing state is treated as unpaused for older isolated fixtures.
        """
        pause_event = getattr(self, 'update_pause_event', None)
        return pause_event is not None and pause_event.is_set()

    @staticmethod
    def _validate_continuation_path(
        value: str, folder: Path, recursive: bool,
    ) -> Path:
        """Validate one persisted path against the original scan boundary.

        Args:
            value (str): Absolute path recorded by the original watcher.
            folder (Path): Exact original watched folder.
            recursive (bool): Original recursion setting.

        Returns:
            Path: Original lexical path after proving it resolves within scope.

        Raises:
            ValueError: The path is relative, redirected, nested out of scope
                or cannot be verified through its existing components.
        """
        candidate = Path(value)
        try:
            if not candidate.is_absolute():
                raise ValueError("Continuation path is not absolute.")
            root = folder.resolve(strict=True)
            if (folder.is_symlink()
                    or not folder.is_dir()
                    or getattr(folder.lstat(), 'st_file_attributes', 0)
                    & WATCHER_REPARSE_POINT_ATTRIBUTE):
                raise ValueError("Continuation folder is redirected.")
            resolved = candidate.resolve(strict=False)
            if (resolved == root or not resolved.is_relative_to(root)
                    or (not recursive and resolved.parent != root)):
                raise ValueError("Continuation path escaped its original folder.")
            # Resolve containment canonically, but walk the persisted lexical
            # spelling. Windows may expand an 8.3 ancestor in `resolve()`.
            lexical_root = Path(os.path.abspath(folder))
            cursor = Path(os.path.abspath(candidate))
            if cursor != lexical_root and lexical_root not in cursor.parents:
                raise ValueError("Continuation path escaped its original folder.")
            while True:
                try:
                    info = cursor.lstat()
                except FileNotFoundError:
                    pass
                else:
                    if (stat.S_ISLNK(info.st_mode)
                            or getattr(info, 'st_file_attributes', 0)
                            & WATCHER_REPARSE_POINT_ATTRIBUTE):
                        raise ValueError("Continuation path is redirected.")
                if cursor == lexical_root:
                    break
                cursor = cursor.parent
            return candidate
        except (OSError, RuntimeError):
            raise ValueError("Continuation path could not be verified.") from None

    def configure_drain(
        self, directory: Path, initial: Iterable[Path] | None,
        resume: bool = False, *, continuation: bool = False,
    ) -> None:
        """Persist photograph admission before starting the watcher thread.

        Args:
            directory (Path): Connection-bound flight session workspace.
            initial (Iterable[Path] | None): Captured startup exclusions, or
                None to capture the initial folder on the worker before REC.
            resume (bool): Restore the original accepted session without
                recapturing exclusions or inventing a Finish cutoff.
            continuation (bool): Restore an exact unfinished update continuation
                while retaining its unfrozen original admission boundary.

        Returns:
            None: Binds a durable queue without scanning historical files.

        Raises:
            ValueError: Resume state, folder, recursion or source paths do not
                match the original captured boundary.
        """
        if continuation and not resume:
            raise ValueError('Update continuation requires an existing drain manifest.')
        continuation_row: Dict[str, Any] | None = None
        if continuation:
            manifest = directory / "watch-drain.json"
            continuation_row = read_document(manifest)
            captured_folder = (
                getattr(self, '_watch_folder_override', None)
                or Path(self.config.watched_folder)
            )
            recursive_override = getattr(self, '_watch_recursive_override', None)
            captured_recursive = (
                self.config.watch_recursive
                if recursive_override is None else recursive_override
            )
            stored_folder = continuation_row.get('folder')
            # Windows Path equality normalizes slash spelling but keeps this
            # exact lexical boundary; canonical containment and reparse checks
            # below remain independent and unchanged.
            same_folder = (
                isinstance(stored_folder, str)
                and Path(stored_folder) == Path(captured_folder)
            )
            cutoff = continuation_row.get('cutoff')
            initial_values = continuation_row.get('initial')
            pending_values = continuation_row.get('pending')
            if (type(continuation_row.get('schema_version')) is not int
                    or continuation_row.get('schema_version') not in (1, 2)
                    or not same_folder
                    or type(captured_recursive) is not bool
                    or continuation_row.get('recursive') is not captured_recursive
                    or continuation_row.get('initial_captured') is not True
                    or continuation_row.get('frozen') is not False
                    or type(cutoff) not in (int, float) or cutoff != 0
                    or continuation_row.get('error') != ''):
                raise ValueError('Update continuation no longer matches its original boundary.')
            if (not isinstance(initial_values, list)
                    or any(not isinstance(value, str) for value in initial_values)
                    or not isinstance(pending_values, list)
                    or any(not isinstance(value, str) for value in pending_values)
                    or (continuation_row['schema_version'] == 1 and (
                        len(set(initial_values)) != len(initial_values)
                        or len(set(pending_values)) != len(pending_values)
                        or set(initial_values).intersection(pending_values)))):
                raise ValueError('Update continuation path history needs review.')
            for value in (*initial_values, *pending_values):
                self._validate_continuation_path(
                    value, captured_folder, captured_recursive,
                )
        if self.linked_target is not None:
            if self._linked_lease is None:
                raise ValueError('Linked upload lease is unavailable.')
            self._linked_lease.acquire()
            try:
                state = watcher_state.LinkedUploadState(self.linked_target)
                if resume and not state.preexisting:
                    raise ValueError('Linked upload history is missing; review before recovery.')
                if not resume:
                    # Record destination-owned history before accepting a file.
                    state.save()
                self.state = state
            except Exception:
                self._linked_lease.release()
                raise
        self.drain_manifest = directory / "watch-drain.json"
        folder = (getattr(self, '_watch_folder_override', None)
                  or Path(self.config.watched_folder)).absolute()
        recursive = getattr(self, '_watch_recursive_override', None)
        if recursive is None:
            recursive = self.config.watch_recursive
        binding = (self.linked_target.binding() if self.linked_target is not None
                   else connection_binding(http_transport.normalize_upload_url(self.config.gallery_url),
                                           self.config.api_key.strip()))
        self._queue = WatchQueueStore(
            self.drain_manifest, binding, folder, recursive,
            scope=(self.linked_target.session.approved_scope()
                   if self.linked_target is not None else ''),
        )
        if resume:
            self._queue.restore()
        else:
            observations = (None if initial is None else [
                observe_watch_source(path, time.time()) for path in initial
            ])
            self._queue.initialize(observations)
        self._admission_lock = self._queue.lock
        self.initial_paths = {Path(value) for value in self._queue.data['initial']}
        self.initial_captured = self._queue.data['initial_captured'] is True
        self._continuation_gate = threading.Event() if continuation else None
        self.drain_requested_at = float(self._queue.data['cutoff'])
        self.drain_only = self.drain_requested_at > 0
        if self.drain_only:
            self.drain_event.set()
            self.drain_started_monotonic = time.monotonic()
        self.persist_drain()

    def persist_drain(self) -> None:
        """Retain current queue evidence without reordering or inventing a cutoff.

        Returns:
            None: Refreshes the compatibility count and retains current failures.
        """
        journal = getattr(self, '_queue', None)
        if journal is not None:
            with journal.lock:
                self.accepted_paths = {Path(item.source.path) for item in journal.items()
                                       if item.state not in ('acknowledged', 'skipped')}
                if self.drain_error:
                    journal.record_error(self.drain_error)

    def acknowledge_photo(self, path: Path, disposition: str = 'uploaded') -> None:
        """Acknowledge only the current head after durable positive disposition.

        Args:
            path (Path): Exact source path belonging to the current queue head.
            disposition (str): Uploaded or duplicate positive disposition.

        Returns:
            None: Persists acknowledgement before exposing the following item.

        Raises:
            ValueError: The path is not the oldest unresolved generation.
            OSError: Durable acknowledgement cannot be confirmed.
        """
        journal = self._queue
        item = journal.head() if journal is not None else None
        if item is None or item.source.path != str(path):
            raise ValueError('Only the oldest accepted photograph can be acknowledged.')
        journal.update(item, state='acknowledged', reason='', next_retry_at=0.0,
                       disposition=disposition)
        self.persist_drain()
        self.emit_queue_snapshot()
        self._producer_wake.set()

    def emit_queue_snapshot(self) -> None:
        """Publish exact session, scan, intent and dispatch state without Tk access.

        Returns:
            None: Queues counts, flight/queue identities, cutoff, last scan,
                durable head phase, current transport identity and retained
                progress diagnostics.
        """
        journal = getattr(self, '_queue', None)
        if journal is None:
            return
        with journal.lock:
            self.persist_drain()
            items = journal.items()
            head = journal.head()
            detail = {
                'worker': self, 'queued_count': len(self.accepted_paths),
                'acknowledged_count': sum(
                    item.state == 'acknowledged' for item in items
                ),
                'skipped_count': sum(item.state == 'skipped' for item in items),
                'session_id': journal.data['session_id'],
                'flight_session_id': (
                    self.drain_manifest.parent.name
                    if self.drain_manifest is not None else ''
                ),
                'target_binding': journal.binding,
                'finish_intent': bool(journal.data.get('cutoff')),
                'force_finished': journal.force_finished,
                'force_finished_at': journal.force_finished_at,
                'intent': (
                    'force_finished' if journal.force_finished else
                    'finish' if journal.data.get('cutoff') else 'capture'
                ),
                'snapshot_at': time.time(),
                'cutoff': journal.data['cutoff'],
                'last_scan_generation': journal.scan_generation,
                'completed': journal.data.get('completed') is True,
                'head_id': head.item_id if head is not None else '',
                'head_sequence': head.sequence if head is not None else 0,
                'head_file': Path(head.source.path).name if head is not None else '',
                'head_phase': head.phase if head is not None else '',
                'state': ('blocked' if self._producer_error
                          or journal.data.get('order_verified') is not True
                          else head.state if head is not None else 'idle'),
                'reason': (self._producer_error or str(journal.data.get('error') or '')
                           or (head.reason if head is not None else '')),
                'retry_at': head.next_retry_at if head is not None else 0.0,
                'last_scan': journal.last_scan_at,
                'last_progress': journal.data['last_change_at'],
                'last_failure': journal.data['last_failure'],
                'producer_running': bool(self._producer_thread is not None
                                         and self._producer_thread.is_alive()),
                'dispatch_active': bool(
                    self._transport_active.is_set()
                    or self._pending_transport is not None
                    and not self._pending_transport.is_set()
                ),
                'active_item_id': (
                    self._active_queue_item.item_id
                    if self._active_queue_item is not None else ''
                ),
                'active_item_sequence': (
                    self._active_queue_item.sequence
                    if self._active_queue_item is not None else 0
                ),
                'force_finish_pending': self._force_finish_requested.is_set(),
                'consumer_phase': self._diagnostic_phase,
            }
        self.events.put({'kind': 'watch_queue', 'payload': detail})

    def request_drain(self) -> None:
        """Persist one user Finish cutoff while allowing active acknowledgement.

        Returns:
            None: Wakes local reconciliation and consumer without cancelling HTTP.
        """
        with self._admission_lock:
            if not self.drain_event.is_set():
                self.drain_requested_at = time.time()
                self.drain_started_monotonic = time.monotonic()
                if self._queue is None:
                    raise RuntimeError('Watcher has no durable capture journal.')
                self._queue.finish(self.drain_requested_at)
                self.drain_event.set()
                self.drain_only = True
        self._producer_wake.set()
        self._consumer_wake.set()

    def request_refresh(self) -> None:
        """Coalesce a manual rescan without changing retry or transport ownership.

        Returns:
            None: Schedules discovery only for a nonterminal session; never starts
                Finish or reopens a force-finished queue.
        """
        if self._queue is not None and self._queue.force_finished:
            self.emit('warning', gettext_noop('Force-finished queue is retained and cannot be refreshed.'))
            return
        with self._admission_lock:
            if self._refresh_pending:
                self.emit('info', gettext_noop('Queue refresh merged with the pending request.'))
                return
            self._refresh_pending = True
        self.emit('info', gettext_noop('Queue refresh requested; FIFO and retry deadlines retained.'))
        if self.is_alive() and self._queue is not None:
            self._ensure_capture_producer(self._queue.folder)
        self._producer_wake.set()
        self._consumer_wake.set()

    def begin_retained_stop(self) -> float:
        """Fence new photograph admission before publishing a retained cutoff.

        Returns:
            float: First operator cutoff, reused on publication retry.
        """
        if not self._retained_stop_cutoff:
            self._retained_stop_cutoff = time.time()
        self._recovery_review_only = True
        self._force_stop_requested.set()
        self._retained_stop_publishing.set()
        self._retained_stop_publication_done.clear()
        self._keep_sources_after_stop = True
        self._producer_stop.set()
        self._producer_wake.set()
        return self._retained_stop_cutoff

    def publish_retained_stop(self, cutoff: float) -> None:
        """Persist the cutoff and request stop while keeping live HTTP ownership.

        Args:
            cutoff (float): Exact timestamp admitted by begin_retained_stop.
        Returns:
            None: Stops this worker only after durable queue retention.
        """
        try:
            if self._queue is None:
                raise RuntimeError('Watcher has no durable capture journal.')
            self._queue.stop_and_retain(cutoff)
            self.stop()
        finally:
            self._retained_stop_publishing.clear()
            self._retained_stop_publication_done.set()

    def apply_recovery_decision(self) -> None:
        """Wake only the current owner after the shared coordinator saves a decision.

        Returns:
            None: Preserves review-only state until the durable stop flag clears.
        """
        if self._queue is None or not self.is_alive() or self.stop_event.is_set():
            return
        resume_stopped_producer = self._force_stop_requested.is_set()
        self._recovery_review_only = bool(
            self._queue.data.get('retained_stop') is True
            or self._retained_stop_publishing.is_set()
        )
        if not self._retained_stop_publishing.is_set() and not self._recovery_review_only:
            self._force_stop_requested.clear()
            self._retained_stop_cutoff = 0.0
            if resume_stopped_producer:
                self._producer_stop.clear()
                self._producer_wake.set()
        self.emit_queue_snapshot()
        if not self._recovery_review_only:
            self._consumer_wake.set()

    def _reconcile_watch_queue(self, folder: Path) -> int:
        """Persist a complete local scan independently of every network wait.

        Args:
            folder (Path): Original captured source directory.

        Returns:
            int: Newly accepted source generations in this successful scan.

        Raises:
            OSError: Local discovery or durable publication fails.
            ValueError: The original journal or source boundary cannot be verified.
        """
        with self._scan_lock:
            if self._force_stop_requested.is_set():
                return 0
            recursive = getattr(self, '_watch_recursive_override', None)
            if recursive is None:
                recursive = self.config.watch_recursive
            observations = discover_watch_sources(folder, watcher_state.SUPPORTED_SUFFIXES, recursive)
            if self._force_stop_requested.is_set():
                return 0
            if self._queue is None:
                # --once owns a separate resumable queue; it intentionally has
                # no startup exclusions. GUI capture always configures a session.
                binding = connection_binding(
                    http_transport.normalize_upload_url(self.config.gallery_url),
                    self.config.api_key.strip(),
                )
                directory = (self._queue_directory_override
                             or watcher_state.CONFIG_DIR / 'once-queues' / binding)
                self._queue = WatchQueueStore(
                    directory / 'watch-drain.json', binding, folder.absolute(), recursive,
                )
                if self._queue.path.exists():
                    self._queue.restore()
                else:
                    self._queue.initialize([])
                self._admission_lock = self._queue.lock
            added = self._queue.reconcile(observations)
            self._queue.record_error('')
            self._producer_error = ''
            self.persist_drain()
            if self._refresh_pending:
                self._refresh_pending = False
                self.emit('info', f'Queue refresh completed: accepted={added}; '
                          f'pending={len(self.accepted_paths)}. Active transport retained.')
            self.emit_queue_snapshot()
            self._consumer_wake.set()
            return added

    def _produce_photographs(self, folder: Path) -> None:
        """Reconcile notifications and missed events throughout upload/backoff.

        Args:
            folder (Path): Original captured source directory.

        Returns:
            None: Stops only with this owner; failures preserve sources and retry discovery.
        """
        try:
            signal = WatchDirectorySignal(folder, self._queue.recursive)
        except (OSError, AttributeError):
            # The local producer owns permanent portable/Win32-unavailable
            # support through reconciliation; no event is its sole evidence.
            signal = None
        next_scan = 0.0
        try:
            while not self._producer_stop.is_set():
                requested = self._producer_wake.is_set()
                if (requested or signal is not None and signal.changed()
                        or time.monotonic() >= next_scan):
                    self._producer_wake.clear()
                    try:
                        self._reconcile_watch_queue(folder)
                    except (OSError, ValueError, RuntimeError):
                        previous_error = self._producer_error
                        self._producer_error = (
                            'Local queue discovery or persistence failed; sources retained.'
                        )
                        if previous_error != self._producer_error:
                            self.emit('error', self._producer_error)
                        self.events.put({'kind': 'watch_queue', 'payload': {
                            'worker': self, 'reason': self._producer_error,
                            'state': 'blocked', 'producer_running': True,
                        }})
                    next_scan = time.monotonic() + WATCH_QUEUE_RECONCILE_SECONDS
                self._producer_wake.wait(WATCHER_UPDATE_PAUSE_POLL_SECONDS)
        except Exception:  # noqa: BLE001
            self._producer_error = 'Capture producer stopped; automatic recovery is pending.'
            self.emit('error', self._producer_error)
        finally:
            if signal is not None:
                signal.close()

    def _ensure_capture_producer(self, folder: Path) -> None:
        """Start only a missing local observer while preserving the HTTP consumer.

        Args:
            folder (Path): Original journal-bound capture directory.

        Returns:
            None: Retains one producer across automatic and manual recovery races.
        """
        with self._admission_lock:
            if (self._producer_stop.is_set()
                    or self._producer_thread is not None and self._producer_thread.is_alive()):
                return
            self._producer_thread = threading.Thread(
                target=self._produce_photographs, args=(folder,),
                name='PHPGalleryCaptureProducer', daemon=True,
            )
            self._producer_thread.start()

    def emit(self, level: str, message: str) -> None:
        """
        Send a message to the UI queue and the persistent log file.

        Args:
            level (str): Logging level name such as info, warning, or error.
            message (str): Human-readable status message.

        Returns:
            None: Send a message to the UI queue and the persistent log file.
        """
        self.events.put((level, message))
        log_method = getattr(logging, level if level in {"debug", "info", "warning", "error"} else "info")
        log_method(message)

    def _initialize_recovery_coordinator(
        self, pipeline: UploadPipelineLease,
    ) -> None:
        """Bind recovery decisions to this worker's existing upload leases.

        Args:
            pipeline (UploadPipelineLease): Held pipeline fence owned by run().

        Returns:
            None: Installs recovery callbacks for the restored exact queue.
        """
        journal = self._queue
        if journal is None:
            return

        def receipt_lookup(item: WatchQueueItem) -> bool:
            """Prove exact queue acknowledgement from persisted response history.

            Args:
                item (WatchQueueItem): Candidate exact queue head.

            Returns:
                bool: True only for a matching local success response.
            """
            return self._has_exact_saved_watch_receipt(item)

        def linked_review(item: WatchQueueItem, disposition: str) -> None:
            """Review only the marker owned by this exact queue generation.

            Args:
                item (WatchQueueItem): Exact reviewed queue head.
                disposition (str): Receipt, skip, prior review or one-photo approval.

            Returns:
                None: Retains the linked uncertainty as reviewed evidence.
            """
            if self.linked_target is None:
                return
            state = self.state
            if state is None:
                return
            if disposition == 'approved_photo_upload':
                intent = journal.data.get('recovery_intent')
                if (not isinstance(intent, dict)
                        or intent.get('item_id') != item.item_id
                        or intent.get('source_path') != item.source.path
                        or intent.get('head_sha256') != item.sha256
                        or not isinstance(intent.get('approval_token'), str)):
                    raise ValueError('Approved linked upload intent is unavailable.')
                state.approve_uncertain_repeat(
                    Path(item.source.path), item.sha256, intent['approval_token'],
                )
                return
            failure = state.data['failures'].get(item.source.path)
            if (not isinstance(failure, dict)
                    or failure.get('uncertain') is not True
                    or failure.get('sha256') != item.sha256):
                return
            if failure.get('reviewed') is True:
                return
            if disposition == 'confirmed_by_receipt' and not receipt_lookup(item):
                raise ValueError('Exact durable upload receipt is unavailable.')
            _, marker = state.recovery_marker()
            state.review_uncertainty(marker)

        def earlier_blocker_details(
            item: WatchQueueItem,
        ) -> tuple[str, str, str]:
            """Return exact prior uncertain marker identity without changing it.

            Args:
                item (WatchQueueItem): Current head blocked before dispatch.

            Returns:
                tuple[str, str, str]: Marker digest, prior path and attempted hash.
            """
            state = self.state
            if state is None:
                raise ValueError('Earlier linked upload history is unavailable.')
            intent = journal.data.get('recovery_intent')
            if isinstance(intent, dict) and intent.get('item_id') == item.item_id:
                prior_path = intent.get('blocker_path')
                prior_hash = intent.get('blocker_sha256')
                prior_marker = intent.get('blocker_marker')
                failure = state.data['failures'].get(prior_path)
                if (isinstance(prior_path, str) and isinstance(prior_hash, str)
                        and isinstance(prior_marker, str)
                        and isinstance(failure, dict)
                        and failure.get('uncertain') is True
                        and failure.get('sha256') == prior_hash):
                    original = dict(failure)
                    original.pop('reviewed', None)
                    encoded = json.dumps(
                        [prior_path, original], ensure_ascii=True, sort_keys=True,
                        separators=(',', ':'),
                    ).encode('ascii')
                    if hashlib.sha256(encoded).hexdigest() == prior_marker:
                        return prior_marker, prior_path, prior_hash
            marker_hash, marker = state.recovery_marker()
            entry = next(((path, value) for path, value in state.data['failures'].items()
                          if isinstance(value, dict)
                          and value.get('uncertain') is True
                          and value.get('reviewed') is not True
                          and value.get('sha256') == marker_hash), None)
            if entry is None:
                raise ValueError('Earlier linked upload identity is unavailable.')
            return marker, entry[0], marker_hash

        def earlier_blocker_review(item: WatchQueueItem, marker: str) -> None:
            """Review the existing older linked uncertainty under its lease.

            Args:
                item (WatchQueueItem): Never-dispatched current head B.
                marker (str): Exact earlier uncertainty marker shown to the user.

            Returns:
                None: Marks only the existing earlier uncertainty as reviewed.
            """
            state = self.state
            if state is None:
                raise ValueError('Earlier linked upload history is unavailable.')
            intent = journal.data.get('recovery_intent')
            if not isinstance(intent, dict):
                raise ValueError('Earlier linked review intent is unavailable.')
            prior_path = intent.get('blocker_path')
            prior_hash = intent.get('blocker_sha256')
            expected_marker = intent.get('blocker_marker')
            if not isinstance(prior_path, str) or not isinstance(prior_hash, str):
                raise ValueError('Earlier linked review identity is unavailable.')
            if expected_marker != marker:
                raise ValueError('Earlier linked review marker changed.')
            failure = state.data['failures'].get(prior_path)
            if (not isinstance(failure, dict) or failure.get('uncertain') is not True
                    or failure.get('sha256') != prior_hash):
                raise ValueError('Earlier linked upload marker changed.')
            if failure.get('reviewed') is True:
                reviewed_entry = dict(failure)
                reviewed_entry.pop('reviewed', None)
                encoded = json.dumps(
                    [prior_path, reviewed_entry], ensure_ascii=True, sort_keys=True,
                    separators=(',', ':'),
                ).encode('ascii')
                if hashlib.sha256(encoded).hexdigest() != marker:
                    raise ValueError('Earlier linked upload marker changed.')
                return
            _, current_marker = state.recovery_marker()
            if current_marker != marker:
                raise ValueError('Earlier linked upload marker changed.')
            state.review_uncertainty(marker)

        mode = 'linked' if self.linked_target is not None else 'legacy'

        def current_mode() -> str:
            """Read the live GUI mode and reject unavailable/stale ownership.

            Returns:
                str: Current linked mode, either verified API-key mode as
                    legacy, or unavailable on mismatch.
            """
            admission = getattr(self, '_gui_admission', None)
            if admission is None:
                return mode
            try:
                state = admission[0].view().state
            except (ModeCoordinatorError, RuntimeError):
                return 'unavailable'
            if state in LEGACY_UPLOAD_STATES:
                return 'legacy'
            if state == 'linked_active':
                return 'linked'
            return 'unavailable'

        def current_binding() -> str:
            """Checkpoint current worker admission before recovery decisions.

            Returns:
                str: Exact admitted binding, or unavailable when config changed.
            """
            if self._once_work is not None:
                try:
                    self._once_work.checkpoint()
                except (ModeCoordinatorError, ValueError, RuntimeError):
                    return 'unavailable'
            return journal.binding if current_mode() == mode else 'unavailable'

        self.recovery_coordinator = WatchRecoveryCoordinator(
            journal, pipeline, self._linked_lease, receipt_lookup,
            lambda: (self._pending_transport is not None
                     and not self._pending_transport.is_set()),
            current_binding, current_mode, linked_review,
            earlier_blocker_details=earlier_blocker_details,
            earlier_blocker_review=earlier_blocker_review,
            decision_gate=self._recovery_gate,
        )
        self._watch_mode_reader = current_mode
        self._watch_binding_reader = current_binding

    def _has_exact_saved_watch_receipt(self, item: WatchQueueItem) -> bool:
        """Prove a queue-bound server insertion from durable linked history.

        Args:
            item (WatchQueueItem): Exact item whose dispatch may have completed.

        Returns:
            bool: True only for its validated fresh-insert response and image ID.
        """
        state = self.state
        target = self.linked_target
        if state is None or target is None or not item.sha256:
            return False
        record = state.data['uploaded_paths'].get(item.source.path)
        response = record.get('response', {}) if isinstance(record, dict) else {}
        image_ids = response.get('image_ids') if isinstance(response, dict) else None
        filenames = response.get('filenames') if isinstance(response, dict) else None
        return bool(
            isinstance(record, dict)
            and isinstance(response, dict)
            and record.get('sha256') == item.sha256
            and response.get('queue_item_id') == item.item_id
            and response.get('ok') is True
            and response.get('upload_evidence') == 'fresh_insert_v1'
            and type(response.get('gallery_id')) is int
            and response.get('gallery_id') == target.gallery_id
            and type(response.get('uploaded')) is int
            and response.get('uploaded') == 1
            and type(response.get('scanned')) is int
            and response.get('scanned') >= 0
            and isinstance(image_ids, list) and len(image_ids) == 1
            and type(image_ids[0]) is int
            and 0 < image_ids[0] <= LINKING_GALLERY_ID_MAX
            and isinstance(filenames, list) and len(filenames) == 1
            and isinstance(filenames[0], str) and bool(filenames[0])
        )

    def load_watch_recovery_remote(
        self, snapshot: WatchRecoverySnapshot,
        cancelled: threading.Event | None = None,
    ) -> WatchRecoveryRemote | None:
        """Read the last exact checkable receipt image or a catalogue observation.

        Args:
            snapshot (WatchRecoverySnapshot): Head and authority captured for review.
            cancelled (threading.Event | None): Optional dialog-owned read fence.

        Returns:
            WatchRecoveryRemote | None: Authorized bounded bytes and their display
                label, or None for a lost binding, incomplete catalogue or absent
                image. The bytes grant no recovery decision authority.
        """
        target = self.linked_target
        if target is None:
            return None

        def current() -> bool:
            """Check that the same target still authorizes the optional read.

            Returns:
                bool: Current uncancelled target matches the reviewed binding.
            """
            return (
                (cancelled is None or not cancelled.is_set())
                and self.linked_target is target
                and target.binding() == snapshot.target_binding
                and target.session.can_upload()
            )

        if not current():
            return None
        items = self._queue.items() if self._queue is not None else []
        confirmed = next((item for item in reversed(items)
                          if item.state == 'acknowledged'
                          and item.sequence == snapshot.last_confirmed_sequence), None)
        photo_id: int | None = None
        evidence_kind: Literal['receipt', 'observation'] = 'observation'
        if confirmed is not None and self.state is not None:
            record = self.state.data['uploaded_paths'].get(confirmed.source.path, {})
            response = record.get('response', {}) if isinstance(record, dict) else {}
            image_ids = response.get('image_ids') if isinstance(response, dict) else None
            if (self._has_exact_saved_watch_receipt(confirmed)
                    and isinstance(image_ids, list) and len(image_ids) == 1):
                photo_id = image_ids[0]
                evidence_kind = 'receipt'
        if photo_id is None:
            started = time.monotonic()
            after_id: int | None = None
            last_photo = None
            for _page_index in range(WATCH_RECOVERY_PREVIEW_PAGE_LIMIT):
                if (time.monotonic() - started
                        >= WATCH_RECOVERY_PREVIEW_TIME_LIMIT_SECONDS or not current()):
                    return None
                page = target.session.media_page(
                    target.gallery_id, after_id, cancelled=cancelled,
                )
                if page.photos:
                    last_photo = page.photos[-1]
                after_id = page.next_after
                if after_id is None:
                    break
            else:
                return None
            if last_photo is None:
                return None
            photo_id = last_photo.image_id
        if not current():
            return None
        body, _mime = target.session.media_photo(
            target.gallery_id, photo_id, True, cancelled=cancelled,
            cache_eligible=False, preload_eligible=False,
        )
        if not current() or len(body) > PREVIEW_MAX_BYTES:
            return None
        return body, evidence_kind

    def run(self) -> None:
        """Run the watcher while retaining its target lease through shutdown.

        Returns:
            None: Reports unsafe redirects, retains pending work and releases
                an acquired lease after the worker has stopped.
        """
        gui_work: Optional[CoordinatedWork] = None
        pipeline = UploadPipelineLease(watcher_state.CONFIG_DIR)
        self._pipeline_lease = pipeline
        try:
            pipeline.acquire()
            gui_admission = getattr(self, '_gui_admission', None)
            if gui_admission is not None:
                owner, port = gui_admission
                kind = 'linked' if self.linked_target is not None else 'legacy'
                gui_work = owner.acquire(kind, port)
                self._once_work = gui_work
                self.remote_inventory.before_request = gui_work.checkpoint
            self._run_owned()
        except (GalleryKeyRedirectError, ModeCoordinatorError) as exc:
            self.drain_error = ('Gallery API redirect was refused; pending photographs are retained.'
                                if isinstance(exc, GalleryKeyRedirectError) else
                                'Upload mode could not be verified; pending photographs are retained.')
            self.stop_event.set()
            try:
                self.persist_drain()
            finally:
                self.emit('error', self.drain_error)
        except Exception as exc:  # noqa: BLE001
            self.drain_error = self._unexpected_error_summary(exc)
            self.stop_event.set()
            try:
                self.persist_drain()
            except Exception:  # noqa: BLE001
                self.drain_error += ' Recovery state could not be persisted.'
            finally:
                self.emit('error', self.drain_error)
        finally:
            self._retained_stop_publication_done.wait()
            while True:
                with self._force_finish_lease_lock:
                    if self._force_finish_publication_done.is_set():
                        pipeline.release()
                        self._pipeline_lease = None
                        try:
                            if gui_work is not None:
                                gui_work.close()
                        finally:
                            try:
                                if self._linked_lease is not None:
                                    self._linked_lease.release()
                            finally:
                                self.events.put({
                                    "kind": "watcher_start_finished",
                                    "payload": {"worker": self},
                                })
                        break
                self._force_finish_publication_done.wait()

    def _unexpected_error_summary(self, error: BaseException) -> str:
        """Describe an unexpected worker error without its sensitive message.

        Args:
            error (BaseException): Exception caught at the watcher owner boundary.

        Returns:
            str: Fixed phase, safe class name and last local source location.
        """
        phase = getattr(self, '_diagnostic_phase', 'worker')
        if phase not in {
            'startup', 'initial_scan', 'scan', 'candidate', 'inventory',
            'upload', 'acknowledgement', 'worker',
        }:
            phase = 'worker'
        error_type = type(error).__name__
        if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,63}', error_type) is None:
            error_type = 'Exception'
        source = 'unknown'
        current = error.__traceback__
        while current is not None:
            code = current.tb_frame.f_code
            source_path = Path(code.co_filename).as_posix().lower()
            legacy_source = Path(code.co_filename).name == 'gallery_watch_upload.pyw'
            worker_source = (
                source_path == 'uploader/watcher/workers/watcher.py'
                or source_path.endswith('/uploader/watcher/workers/watcher.py')
            )
            if ((legacy_source or worker_source)
                    and current.tb_frame.f_locals.get('self') is self):
                function = code.co_name
                if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,63}', function):
                    source = (
                        f'gallery_watch_upload.pyw:WatcherThread.{function}:'
                        f'{current.tb_lineno}'
                    )
            current = current.tb_next
        return (
            f'Watcher failed phase={phase} error_type={error_type} '
            f'source={source}; pending photographs are retained.'
        )

    def _run_owned(self) -> None:
        """Observe sources or drain one recoverable queue until its terminal state.

        Returns:
            None: Pending/error/force-finished state survives restart; a durable
                force-finish marker prevents recovery or automatic replay.
        """
        folder = (getattr(self, '_watch_folder_override', None)
                  or Path(self.config.watched_folder))
        if self._queue is not None and self._queue.data.get('initial_captured') is True:
            self._queue.restore()
            self.drain_requested_at = float(self._queue.data['cutoff'])
            self.drain_only = self.drain_requested_at > 0
            if self.drain_only:
                self.drain_event.set()
        if self._queue is not None and self._queue.force_finished:
            self._force_finish_requested.set()
            self._force_stop_requested.set()
            self._producer_stop.set()
            self.stop_event.set()
            self._diagnostic_phase = 'force_finished'
            self.emit_queue_snapshot()
            self.emit('warning', gettext_noop('Watcher session was explicitly force-finished; pending sources remain retained.'))
            return
        pipeline = self._pipeline_lease
        if pipeline is not None and self._queue is not None:
            self._initialize_recovery_coordinator(pipeline)
            if self.recovery_coordinator is not None:
                self.recovery_coordinator.repair_interrupted_decision()
            if self._queue.data.get('retained_stop') is True:
                self._recovery_review_only = not (
                    self._continue_retained and not self._recovery_review_only
                    and self.recovery_coordinator is not None
                    and self.recovery_coordinator.resume_pending_at_start()
                )
        self._diagnostic_phase = 'startup'
        recursive_override = getattr(self, '_watch_recursive_override', None)
        recursive = (self.config.watch_recursive if recursive_override is None
                     else recursive_override)
        if self.linked_target is not None and self.state is None:
            self.drain_error = 'Linked upload recovery state is unavailable.'
            self.emit('error', self.drain_error)
            return
        upload_url = (self.linked_target.issuer if self.linked_target is not None
                      else http_transport.normalize_upload_url(self.config.gallery_url))
        if (not folder.is_dir() or not upload_url
                or (self.linked_target is None and not self.config.api_key.strip())):
            self.drain_error = "Readable watch folder and saved connection required."
            self.persist_drain()
            self.emit("error", self.drain_error)
            return
        if not self.initial_captured:
            if self._update_pause_requested():
                self.persist_drain()
                return
            try:
                self._diagnostic_phase = 'initial_scan'
                observations = discover_watch_sources(folder, watcher_state.SUPPORTED_SUFFIXES, recursive)
                existing = {Path(value.path) for value in observations}
                with self._admission_lock:
                    self._queue.capture_initial(observations)
                    self.initial_paths = existing
                    self.initial_captured = True
                    # Stop during startup admits no new screenshots. The
                    # complete initial snapshot must never become its queue.
                    if self.drain_event.is_set() or self.stop_event.is_set():
                        self.drain_only = True
                    self.persist_drain()
            except (OSError, ValueError, RuntimeError):
                self.drain_error = "Could not read the screenshot folder. Check access and retry."
                self.stop_event.set()
                self.persist_drain()
                self.emit("error", self.drain_error)
                return
        if not self.drain_only and not self.stop_event.is_set():
            self.events.put({
                "kind": "watcher_start_ready",
                "payload": {"worker": self, "ignored": len(self.initial_paths)},
            })
        gate = getattr(self, '_continuation_gate', None)
        if gate is not None:
            self._diagnostic_phase = 'continuation_admission'
            ready = getattr(self, '_continuation_ready', None)
            if ready is not None:
                ready.set()
            deadline = (
                time.monotonic()
                + WATCHER_CONTINUATION_ADMISSION_TIMEOUT_SECONDS
            )
            while not gate.is_set():
                if (self.stop_event.is_set() or self.drain_event.is_set()
                        or self._update_pause_requested()):
                    self.persist_drain()
                    return
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    with self._admission_lock:
                        if gate.is_set():
                            continue
                        self.drain_error = (
                            'Update resume confirmation timed out; pending '
                            'photographs are retained.'
                        )
                        self.stop_event.set()
                    self.persist_drain()
                    self.emit('error', self.drain_error)
                    return
                gate.wait(min(
                    remaining, WATCHER_UPDATE_PAUSE_POLL_SECONDS,
                ))
            if (self.stop_event.is_set() or self.drain_event.is_set()
                    or self._update_pause_requested()):
                self.persist_drain()
                return
        self.emit("info", f"Watching {folder}")
        self.emit("info", f"Upload endpoint: {upload_url}")
        self.emit(
            "info",
            f"Remote inventory reconnect interval: {self.config.inventory_refresh_seconds:g} seconds.",
        )
        if self.config.attach_sim_camera_metadata:
            self.emit(
                "info",
                "[simconnect] Flight Simulator location metadata enabled. "
                + self.simconnect_client.dll_resolution_message(),
            )
        else:
            self.emit(
                "info",
                "[simconnect] Flight Simulator location metadata disabled.",
            )
        self.emit(
            "info",
            f"Ignoring {len(self.initial_paths)} existing image file(s).",
        )
        if self.stop_event.is_set() or self._update_pause_requested():
            self.persist_drain()
            return
        if not self._force_stop_requested.is_set():
            self._producer_stop.clear()
        self._ensure_capture_producer(folder)
        try:
            while (not self.stop_event.is_set()
                   and not self._update_pause_requested()):
                self._diagnostic_phase = 'scan'
                self._ensure_capture_producer(folder)
                try:
                    if self._producer_error and (
                            self._pending_transport is None or self._pending_transport.is_set()):
                        # The same owner may recover a failed fsync/readback;
                        # reloading preserves original admission and unknowns.
                        self._queue.restore()
                        self._producer_wake.set()
                    if not self._producer_error:
                        self.scan_once(folder, upload_url)
                        if self._queue.head() is None and not self.drain_event.is_set():
                            self._warm_initial_fingerprint()
                except (ModeCoordinatorError, GalleryKeyRedirectError):
                    raise
                except (OSError, ValueError, RuntimeError):
                    if not self._producer_error:
                        self.emit('error', gettext_noop('Queue persistence needs recovery; sources retained.'))
                    self._producer_error = 'Queue persistence needs recovery; sources retained.'
                try:
                    if (self.drain_event.is_set() and not self._producer_error
                            and self._queue.complete(max(
                                WATCH_QUEUE_STABILITY_MIN_SECONDS, self.config.stable_seconds,
                            ))):
                        break
                except (OSError, ValueError):
                    self._producer_error = 'Final queue acknowledgement could not be persisted.'
                    self.emit('error', self._producer_error)
                self._consumer_wake.wait(WATCH_QUEUE_RECONCILE_SECONDS)
                self._consumer_wake.clear()
        finally:
            self._producer_stop.set()
            self._producer_wake.set()
            self._producer_thread.join()
            # A caller deadline does not attest termination of DNS/socket work.
            # Keep this worker's leases until the actual admitted request ends.
            pending = self._pending_transport
            if pending is not None:
                pending.wait()
        self.persist_drain()
        self._diagnostic_phase = 'completed' if self._queue.data['completed'] else 'stopped'
        self.emit_queue_snapshot()
        self.emit('info', gettext_noop('Watcher stopped; pending photographs are retained.'))

    def scan_once(self, folder: Path, upload_url: str) -> None:
        """Reconcile direct callers, then advance heads until the first unresolved item.

        Args:
            folder (Path): Original source folder, already observed by a live producer.
            upload_url (str): Captured legacy endpoint or linked issuer.

        Returns:
            None: Preserves strict FIFO across success, local waits and all failures.

        Raises:
            OSError: Accepted queue evidence cannot be durably published.
            ValueError: Source, target or queue ownership cannot be verified.
            ModeCoordinatorError: Current upload-mode evidence changed.
            GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
        """
        if self._producer_thread is None:
            self._reconcile_watch_queue(folder)
        while not self.stop_event.is_set() and not self._update_pause_requested():
            before = self._queue.head()
            if before is None:
                return
            while (self._recovery_review_only and not self.stop_event.is_set()
                   and not self._update_pause_requested()):
                self._consumer_wake.wait(WATCHER_UPDATE_PAUSE_POLL_SECONDS)
                self._consumer_wake.clear()
            self._consume_head(folder, upload_url)
            after = self._queue.head()
            if after is not None and after.item_id == before.item_id:
                return

    def _consume_head(self, folder: Path, upload_url: str) -> None:
        """Serialize queue advancement against one explicit recovery decision.

        Args:
            folder (Path): Original capture folder for direct --once reconciliation.
            upload_url (str): Captured legacy upload endpoint or linked issuer.

        Returns:
            None: Advances one head while excluding concurrent review mutation.
        """
        with self._recovery_gate:
            self._consume_head_under_recovery_gate(folder, upload_url)

    def _consume_head_under_recovery_gate(
        self, folder: Path, upload_url: str,
    ) -> None:
        """Advance only the oldest durable source generation through its pipeline.

        Args:
            folder (Path): Original capture folder for direct --once reconciliation.
            upload_url (str): Captured legacy upload endpoint or linked issuer.

        Returns:
            None: Resolves one head or retains its exact blocking/retry state;
                a pending force-finish intent prevents any new dispatch.

        Raises:
            OSError: Durable queue evidence cannot be published.
            ValueError: Source/queue ownership cannot be established.
            ModeCoordinatorError: Current upload-mode evidence changed.
            GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
        """
        journal = self._queue
        if journal is None or journal.data.get('order_verified') is not True:
            self.drain_error = 'Legacy queue has no provable FIFO order; review required.'
            self.emit_queue_snapshot()
            return
        if self._force_finish_requested.is_set() or journal.force_finished:
            return
        if journal.data.get('retained_stop') is True:
            self._recovery_review_only = True
            return
        item = journal.head()
        if item is None:
            return
        approved_upload = journal.approved_upload_matches(
            item, 'linked' if self.linked_target is not None else 'legacy',
        )
        if self._pending_transport is not None and not self._pending_transport.is_set():
            return
        path = Path(item.source.path)
        if item.state == 'uncertain':
            # A locally saved live acknowledgement can survive a subsequent
            # queue-publication failure. A hash-only inventory match cannot.
            if self._has_exact_saved_watch_receipt(item):
                self.acknowledge_photo(path)
            return
        if item.state == 'auth_required':
            if self.linked_target is None or not self.linked_target.session.can_upload():
                return
            # Only a usable replacement session with the journal's exact
            # verified binding/scope can recover a rejected authorization.
            item = journal.update(item, state='observed', reason='')
        if item.state == 'blocked':
            return
        if time.time() < item.next_retry_at:
            return
        if self.stop_event.is_set() or self._update_pause_requested():
            return
        self._active_queue_item = item
        self._watch_dispatch_started = False
        acknowledged = False
        try:
            self._diagnostic_phase = 'candidate'
            if not path.is_file():
                journal.defer(item, 'Oldest photograph is missing; restore its source.', 'source')
                return
            stable = self.stability.stable(path)
            changed_at = item.last_source_change or item.source.first_seen
            if (not stable and time.time() - changed_at >= max(
                    WATCH_QUEUE_STABILITY_MIN_SECONDS, self.config.stable_seconds)
                    and observe_watch_source(path, time.time()).signature == item.source.signature):
                # A restart discards the in-memory stability tracker, but the
                # unchanged durable observation still proves its quiet interval.
                stable = True
            if not stable:
                if item.state != 'stabilizing':
                    journal.update(item, state='stabilizing', phase='stability', reason='')
                return
            if (approved_upload and observe_watch_source(
                    path, time.time()).signature != item.source.signature):
                journal.update(item, state='blocked', phase='source',
                               reason='Approved source generation changed; review required.')
                return
            file_hash = http_transport.sha256_file(path)
            if approved_upload and file_hash != item.sha256:
                journal.update(item, state='blocked', phase='source',
                               reason='Approved source bytes changed; review required.')
                return
            expected = self._expected_source_hashes.get(str(path))
            if expected and expected != file_hash:
                journal.update(item, state='blocked', phase='source',
                               reason='Accepted import source bytes changed; review required.')
                return
            size = path.stat().st_size
            source_snapshot = capture_linked_source(path, file_hash, size)
            if approved_upload:
                if observe_watch_source(path, time.time()).signature != item.source.signature:
                    journal.update(item, state='blocked', phase='source',
                                   reason='Approved source generation changed; review required.')
                    return
                verify_linked_source(source_snapshot)
            item = journal.update(item, sha256=file_hash, state='preparing', phase='hash')
            self._active_queue_item = item
            approved_upload = journal.approved_upload_matches(
                item, 'linked' if self.linked_target is not None else 'legacy',
            )
            if self.linked_target is not None and self.state.has_unresolved_upload():
                journal.update(item, state='blocked', phase='prior_upload_review',
                               reason='Earlier linked upload has an unknown result; review required.')
                return
            if (self.linked_target is not None
                    and self.state.has_uncertain_source(path, file_hash)
                    and not approved_upload):
                journal.update(item, state='uncertain', phase='recovery',
                               reason='This source has an unknown earlier upload; review required.')
                return
            confirmed_hash = any(
                other.state == 'acknowledged' and other.sha256 == file_hash
                for other in journal.items()
            )
            if self.linked_target is not None:
                confirmed_hash = confirmed_hash or self.state.already_uploaded_hash(file_hash)
            if str(path) in journal.data['initial']:
                initial_hash = journal.data.get('initial_hashes', {}).get(str(path))
                if initial_hash is None:
                    journal.update(item, state='blocked', phase='source',
                                   reason='Changed historical source has no original fingerprint; review required.')
                    return
                confirmed_hash = confirmed_hash or initial_hash == file_hash
            if confirmed_hash:
                self.acknowledge_photo(path, 'duplicate')
                self.emit('info', f'Skipped confirmed bytes on this target: {path.name}')
                return
            if not item.inventory_ready:
                self._diagnostic_phase = 'inventory'
                item = journal.update(item, state='inventory', phase='inventory')
                self._active_queue_item = item
                ready = self.remote_inventory.refresh(
                    upload_url, self.config.api_key.strip(),
                    [http_transport.inventory_candidate(path, file_hash, size)], force=True,
                )
                if not ready:
                    failure = self.remote_inventory.last_error
                    self._handle_watch_failure(item, failure, 'inventory')
                    return
                verify_linked_source(source_snapshot)
                if self.remote_inventory.has_hash(file_hash):
                    self.acknowledge_photo(path, 'duplicate')
                    self.emit('info', f'Skipped already present on gallery: {path.name}')
                    return
                item = journal.update(item, inventory_ready=True, state='preparing',
                                      phase='preparation', reason='')
                self._active_queue_item = item
            self._diagnostic_phase = 'upload'
            self.emit('info', f'Uploading {path.name} '
                      f'[item={item.item_id}; seq={item.sequence}]')
            metadata = self.sim_camera_metadata_fields(path)
            payload = self.upload_watched_file(
                upload_url, path, metadata, file_hash, size,
            )
            self._transport_active.clear()
            self._last_upload_payload = payload
            if self._once_work is not None:
                self._once_work.checkpoint()
            self._diagnostic_phase = 'acknowledgement'
            if self.linked_target is not None:
                self.state.mark_uploaded(path, file_hash, size, {
                    **payload, 'queue_item_id': item.item_id,
                })
            if self._ack_observer is not None:
                self._ack_observer(payload)
            # Persist this exact generation before B can do any network work.
            # Replacement bytes at the same path remain separately queued.
            self.acknowledge_photo(path)
            self.remote_skipped_paths.add((path, file_hash))
            acknowledged = True
            self.emit('info', f'Uploaded {path.name}: '
                      f'uploaded={payload.get("uploaded", 0)}, '
                      f'scanned={payload.get("scanned", 0)}, '
                      f'renamed={payload.get("renamed", 0)}')
            if (self.config.delete_uploaded_files
                    and not self._keep_sources_after_stop
                    and journal.data.get('retained_stop') is not True):
                self.delete_uploaded_file(path, payload, source_snapshot)
        except (ModeCoordinatorError, GalleryKeyRedirectError):
            raise
        except Exception as failure:  # noqa: BLE001
            if acknowledged:
                # Optional cleanup/logging cannot undo a confirmed disposition.
                self.emit('warning', gettext_noop('Confirmed upload retained; optional cleanup failed.'))
            else:
                self._handle_watch_failure(item, failure, 'upload' if
                                           self._watch_dispatch_started else 'preparation')
        finally:
            self._transport_active.clear()
            self._active_queue_item = None
            self.emit_queue_snapshot()

    def _warm_initial_fingerprint(self) -> None:
        """Hash one historical exclusion only while the upload consumer is idle.

        Discovery remains independent while this optional local read runs. No
        old image enters a transfer pipeline. Unknown original bytes require
        review if that same path later changes; they never authorize reupload.

        Returns:
            None: Retains one unchanged baseline digest or leaves it explicitly unknown.
        """
        for raw in self._queue.data.get('initial_sources', []):
            value = raw['path']
            if (value in self._initial_hash_attempted
                    or value in self._queue.data['initial_hashes']):
                continue
            self._initial_hash_attempted.add(value)
            path = Path(value)
            try:
                self._diagnostic_phase = 'initial_fingerprint'
                before = observe_watch_source(path, time.time())
                if before.signature != raw['signature']:
                    return
                digest = http_transport.sha256_file(path)
                if observe_watch_source(path, time.time()).signature == before.signature:
                    self._queue.remember_initial_hash(before, digest)
            except (OSError, ValueError):
                pass  # Optional baseline evidence never accepts or drops a photograph.
            return

    def _mark_watch_dispatch(self) -> None:
        """Write ahead immediately before the prepared head's HTTP dispatch.

        Returns:
            None: Records possible mutation before HTTP bytes, after local preparation.

        Raises:
            ValueError: The current head or immutable source generation changed.
            OSError: Required durable write-ahead evidence cannot be saved.
        """
        item = self._active_queue_item
        if item is None or not item.sha256:
            raise ValueError('Upload has no owned FIFO head.')
        if self._force_stop_requested.is_set():
            raise UploadPreparationError(
                'Stop was requested before this upload began.'
            )
        approval = None
        mode_reader = getattr(self, '_watch_mode_reader', None)
        binding_reader = getattr(self, '_watch_binding_reader', None)
        current_mode = (mode_reader() if callable(mode_reader)
                        else 'linked' if self.linked_target is not None else 'legacy')
        current_binding = binding_reader() if callable(binding_reader) else self._queue.binding
        pending_approval = self._queue.approved_upload_matches(item)
        if pending_approval:
            if (current_binding != self._queue.binding
                    or not self._queue.approved_upload_matches(item, current_mode)):
                raise ValueError('Approved photo target changed before dispatch.')
            approval = self._queue.begin_approved_upload_dispatch(item, current_mode)
        else:
            self._queue.update(item, state='dispatching', phase='upload', reason='')
        self._watch_dispatch_started = True
        if self.linked_target is not None:
            if approval is None:
                self.state.mark_uncertain(Path(item.source.path), item.sha256)
            else:
                token = approval.get('token')
                if not isinstance(token, str):
                    raise ValueError('Consumed photo approval token is malformed.')
                self.state.mark_uncertain(
                    Path(item.source.path), item.sha256,
                    user_confirmed_repeat=True, approval_token=token,
                )
        if self._dispatch_observer is not None:
            self._dispatch_observer()
        self._transport_active.set()

    def _handle_watch_failure(
        self, item: WatchQueueItem, failure: Exception | None, phase: str,
    ) -> None:
        """Classify head failure without stopping capture or guessing upload outcome.

        Args:
            item (WatchQueueItem): Exact head which owns the failing workflow.
            failure (Exception | None): Structured transport/local failure, if available.
            phase (str): Inventory, preparation or upload stage reached by this item.

        Returns:
            None: Persists retry, authentication or uncertainty and keeps B blocked.
        """
        journal = self._queue
        current = journal.head()
        if current is None or current.item_id != item.item_id:
            return
        status = getattr(failure, 'status', None)
        retry_after = getattr(failure, 'retry_after_seconds', None)
        finished = getattr(failure, 'operation_finished', None)
        status = status if type(status) is int and 100 <= status <= 599 else None
        retry_after = retry_after if type(retry_after) is int and retry_after > 0 else None
        diagnosis = (' ' + failure.safe_summary
                     if isinstance(failure, LinkingResponseError) else '')
        finished = finished if isinstance(finished, threading.Event) else None
        sent = getattr(failure, 'request_started', True) is not False
        rejected = getattr(failure, 'definitely_rejected', False) is True
        if isinstance(finished, threading.Event):
            self._pending_transport = finished
        safe = phase != 'upload' or not self._watch_dispatch_started or rejected or not sent
        if not safe:
            journal.update(current, state='uncertain', phase=phase, http_status=status,
                           reason='Upload result is unknown; review required. Later items blocked.'
                           + diagnosis)
        else:
            if finished is not None and not finished.is_set():
                journal.update(current, state='retry_wait', phase=phase,
                               reason='Waiting for the original transport owner to finish.')
                self.emit_queue_snapshot()
                # Only the consumer waits; discovery keeps journaling new photos.
                finished.wait()
            if self.linked_target is not None and self._watch_dispatch_started:
                self.state.release_rejected_attempt(Path(item.source.path), item.sha256)
            if (isinstance(failure, LinkingRefreshRejected)
                    or self.linked_target is not None
                    and not self.linked_target.session.can_upload()
                    or status in (401, 403)
                    and (self.linked_target is None
                         or not self.linked_target.session.can_upload())):
                journal.update(current, state='auth_required', phase=phase,
                               http_status=status, reason='Upload authorization requires attention.'
                               + diagnosis)
            elif status is not None and 400 <= status < 500 and status not in (401, 429):
                journal.update(current, state='blocked', phase=phase, http_status=status,
                               reason='Server refused this photograph; review required.' + diagnosis)
            else:
                journal.defer(current,
                              ('Rate limit; waiting to retry the same photograph.' if status == 429
                               else 'Temporary failure; retrying the same photograph automatically.')
                              + diagnosis,
                              phase, status, retry_after)
        state = journal.head()
        self.emit('warning', f'Upload queue: item={state.item_id}; seq={state.sequence}; '
                  f'phase={phase}; http={status}; state={state.state}; '
                  f'retry_at={state.next_retry_at:.3f}; {state.reason}')

    def upload_watched_file(
        self, upload_url: str, path: Path, metadata_fields: Dict[str, str],
        file_hash: str = '',
        expected_size: Optional[int] = None,
    ) -> Dict[str, Any]:
        """
        Upload one stable watched file with local thumbnails when available.

        Synchronous server-side generation of all responsive variants can keep one
        PHP worker busy long enough for a shared-hosting proxy to return 502 even
        though the original was stored successfully. Raster files that Pillow can
        decode therefore use the same client-thumbnail protocol as manual import.
        HEIC/HEIF/DNG and local decode failures fall back to the authoritative PHP
        thumbnail pipeline.

        Args:
            upload_url (str): Normalized gallery automation endpoint.
            path (Path): Stable watched source file.
            metadata_fields (Dict[str, str]): Optional multipart metadata
                fields.
            file_hash (str): Captured source SHA-256, or empty for an older
                direct caller without a candidate.
            expected_size (Optional[int]): Captured byte length, or None for
                an older direct caller without a candidate.

        Returns:
            Dict[str, Any]: Upload response with ok, uploaded/scanned counts
                and optional filename, rename and metadata result fields.

        Raises:
            ModeCoordinatorError: Once-mode evidence changed before a request.
            GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
        """
        create_server_thumbnails = bool(self.config.create_thumbnails)
        local_suffixes = {".jpg", ".jpeg", ".png", ".gif", ".webp"}
        request_options = (
            {'before_request': self._once_work.checkpoint}
            if self._once_work is not None else {}
        )
        if self.linked_target is not None:
            if self._once_work is not None:
                self._once_work.checkpoint()
            if (not create_server_thumbnails or not self._client_thumbnail_preference
                    or not runtime.local_thumbnail_supported()
                    or path.suffix.lower() not in local_suffixes):
                return self.linked_target.upload(
                    path, create_server_thumbnails, file_hash, metadata=metadata_fields, before_dispatch=self._mark_watch_dispatch,
                )
            client_upload_id = uuid.uuid4().hex
            with tempfile.TemporaryDirectory(
                    prefix="php-gallery-watch-thumbs-", ignore_cleanup_errors=True) as directory:
                linked_thumbnails = thumbnails.generate_linked_thumbnails(
                    path, Path(directory), client_upload_id, file_hash,
                )
                if http_transport.sha256_file(path) != file_hash:
                    raise ValueError('Source changed during linked thumbnail generation.')
                converted = tuple(
                    LinkedThumbnail(item.path, item.size, item.format)
                    for item in linked_thumbnails
                )
                if self._once_work is not None:
                    self._once_work.checkpoint()
                return self.linked_target.upload(
                    path, False, file_hash, converted, client_upload_id, metadata_fields,
                    before_dispatch=self._mark_watch_dispatch,
                )
        if (not create_server_thumbnails or not self._client_thumbnail_preference
                or not runtime.local_thumbnail_supported() or path.suffix.lower() not in local_suffixes):
            return http_transport.multipart_upload(
                upload_url,
                self.config.api_key.strip(),
                path,
                create_server_thumbnails,
                metadata_fields=metadata_fields,
                **({"expected_sha256": file_hash, "expected_size": expected_size,
                    "single_dispatch": True,
                    "selected_endpoint": self.remote_inventory.resolved_endpoint}
                       if file_hash else {}),
                before_dispatch=self._mark_watch_dispatch,
                **request_options,
            )

        client_upload_id = uuid.uuid4().hex
        with tempfile.TemporaryDirectory(
                prefix="php-gallery-watch-thumbs-", ignore_cleanup_errors=True) as temp_dir_name:
            temp_root = Path(temp_dir_name)
            upload_attempted = False
            try:
                local_thumbnails = thumbnails.generate_local_thumbnails(
                    path, temp_root, client_upload_id,
                )
                self.emit("info", f"Generated {len(local_thumbnails)} local thumbnail file(s) for {path.name}; server-side batch generation skipped.")
                upload_attempted = True
                return http_transport.multipart_upload(
                    upload_url,
                    self.config.api_key.strip(),
                    path,
                    False,
                    thumbnails=local_thumbnails,
                    client_upload_id=client_upload_id,
                    metadata_fields=metadata_fields,
                    **({"expected_sha256": file_hash, "expected_size": expected_size,
                    "single_dispatch": True,
                    "selected_endpoint": self.remote_inventory.resolved_endpoint}
                       if file_hash else {}),
                    before_dispatch=self._mark_watch_dispatch,
                **request_options,
                )
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                raise
            except Exception as exc:  # noqa: BLE001
                if upload_attempted:
                    raise
                self.emit("warning", f"Local thumbnails failed for {path.name}; falling back to server generation: {exc}")
                return http_transport.multipart_upload(
                    upload_url,
                    self.config.api_key.strip(),
                    path,
                    True,
                    metadata_fields=metadata_fields,
                    **({"expected_sha256": file_hash, "expected_size": expected_size,
                    "single_dispatch": True,
                    "selected_endpoint": self.remote_inventory.resolved_endpoint}
                       if file_hash else {}),
                    before_dispatch=self._mark_watch_dispatch,
                **request_options,
                )

    def sim_camera_metadata_fields(self, path: Path) -> Dict[str, str]:
        """
        Return Flight Simulator camera metadata fields for one watched upload.

        The historical method/field names also cover aircraft-position results.
        Acquire coordinates only while MSFS 2020/2024 is running, publish the
        completed diagnostic snapshot, and log the actual provider source. Any host/API
        exception returns no extra form fields so optional GPS cannot block
        thumbnails, uploading, retry state or confirmed-source deletion.

        Args:
            path (Path): Local image path about to be uploaded.

        Returns:
            Dict[str, str]: Multipart form fields, or an empty dictionary when
                unavailable.
        """
        if not self.config.attach_sim_camera_metadata or not msfs_running():
            return {}

        try:
            location, message = self.simconnect_client.current_location()
            self.simconnect_diagnostics = self.simconnect_client.diagnostics_snapshot()
            if location is None:
                self.emit("warning", f"[simconnect] Simulator location unavailable for {path.name}: {message} Upload will continue without simulator location.")
                return {}

            fields = location.upload_fields()
            self.emit(
                "info",
                (
                    f"[simconnect] Attached Flight Simulator location for {path.name}: "
                    f"source={location.source}, lat={location.latitude:.7f}, lng={location.longitude:.7f}, "
                    f"alt={location.altitude:.2f} ft."
                ),
            )
            return fields
        except Exception as exc:  # noqa: BLE001
            # Optional host integration must never turn a valid photo into an upload failure.
            self.simconnect_diagnostics = {"last_result": "failed", "reason": str(exc), "connection": "unknown"}
            self.emit("warning", f"[simconnect] Simulator location acquisition failed for {path.name}: {exc}. Upload will continue without simulator location.")
            return {}

    def delete_uploaded_file(
        self, path: Path, payload: Dict[str, Any],
        snapshot: _FileSegment | None = None,
    ) -> None:
        """Delete only a Windows source matching exact upload evidence.

        Args:
            path (Path): Local image path that was submitted.
            payload (Dict[str, Any]): Gallery response with ok/uploaded and
                optional metadata, validated by the upload owner.
            snapshot (_FileSegment | None): Captured source identity and digest;
                None for direct callers without a verified candidate.

        Returns:
            None: Deletes the exact positively uploaded source, or retains it
                with a warning when safe same-handle deletion is unavailable.
        """
        if (payload.get('ok') is not True or type(payload.get('uploaded')) is not int
                or payload.get('uploaded') != 1 or snapshot is None
                or snapshot.path != path):
            self.emit(
                'warning',
                f'Kept {path.name}: upload confirmation or source identity was unavailable.',
            )
            return
        try:
            delete_verified_linked_source(snapshot)
        except Exception:  # noqa: BLE001
            self.emit(
                'warning',
                f'Kept {path.name}: the confirmed source could not be safely deleted.',
            )
            return
        self.emit('info', f'Deleted confirmed upload source: {path.name}')


def watch_recovery_owner_held(worker: WatcherThread) -> bool:
    """Check whether a worker retains every lease required for recovery review.

    Args:
        worker (WatcherThread): Current worker associated with a recovery owner.

    Returns:
        bool: True while its pipeline and any linked target lock remain held.
    """
    pipeline = getattr(worker, '_pipeline_lease', None)
    linked = getattr(worker, '_linked_lease', None)
    return bool(
        pipeline is not None and pipeline._native is not None
        and (linked is None or linked._native is not None)
    )

def create_resumed_watch_worker(
    directory: Path, config: watcher_state.WatcherConfig, events: CaptureEvents,
    admission: tuple[GuiModeOwner, ModeConfigPort], target: LinkedUploadTarget | None,
    *, review_only: bool = False, continue_retained: bool = False,
) -> WatcherThread | None:
    """Restore a photo journal through the existing capture worker.

    Args:
        directory (Path): Exact matching session already selected by the
            desktop under its flight workspace owner.
        config (WatcherConfig): Frozen Watcher settings and current endpoint.
        events (CaptureEvents): Desktop lifecycle and status mailbox.
        admission (tuple[GuiModeOwner, ModeConfigPort]): Current GUI mode and
            its exact immutable configuration port.
        target (LinkedUploadTarget | None): Exact saved linked target or
            None when the verified connection uses an API key.
        review_only (bool): Withhold new dispatch pending explicit review.
        continue_retained (bool): Explicitly release a verified retained stop.
    Returns:
        WatcherThread | None: Started existing owner or no unfinished photo work.
    Raises:
        WatchRecoveryError: The retained folder, journal or target is unsafe.
    """
    try:
        row = WatchQueueStore.read_journal(directory / 'watch-drain.json')
    except FileNotFoundError:
        return None
    except (OSError, ValueError) as exc:
        raise WatchRecoveryError(
            'journal_read_failed', 'The saved photo journal could not be read.',
        ) from exc
    pending, frozen = row.get('pending'), row.get('frozen')
    if not isinstance(pending, list) or not isinstance(frozen, bool):
        raise WatchRecoveryError('journal_malformed', 'Pending photograph queue is malformed.')
    if row.get('force_finished') is True:
        if continue_retained:
            raise WatchRecoveryError(
                'local_close_incomplete', 'Finish closing the retained capture locally.',
            )
        return None
    if not pending and frozen and (row.get('schema_version') == 1 or row.get('completed') is True):
        return None
    if row.get('initial_captured', True) is not True:
        raise WatchRecoveryError('initial_scan_incomplete', 'The original startup scan was interrupted.')
    folder, recursive = row.get('folder'), row.get('recursive')
    if not isinstance(folder, str) or not Path(folder).is_absolute() or not isinstance(recursive, bool):
        raise WatchRecoveryError(
            'folder_boundary_invalid', 'The original Watcher folder could not be verified.',
        )
    if (target is not None and row.get('schema_version') == 2
            and row.get('scope', '') != target.session.approved_scope()):
        raise WatchRecoveryError(
            'approval_scope_changed', 'Reconnect with the original Watcher upload permissions.',
        )
    worker = WatcherThread(
        config, events, target, gui_admission=admission,
        watch_folder_override=Path(folder), watch_recursive_override=recursive,
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
