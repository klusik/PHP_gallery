# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/imports/manual_upload.py
# Module Type: Windows Import Worker
# Purpose: Execute accepted manual imports through the shared durable upload pipeline.
# Responsibilities:
#   - Process one admitted import job while retaining its mode and pipeline leases.
#   - Persist item outcomes and bounded progress snapshots for the desktop.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own the background worker for accepted manual imports."""

import concurrent.futures
from dataclasses import replace
import copy
import logging
import os
import shutil
import tempfile
import threading
import time
import uuid
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from .. import http_transport
from ..http_transport import GalleryKeyRedirectError, LINKED_INVENTORY_BATCH_SIZE, LocalThumbnail
from ..flight_jobs import connection_binding
from ..import_ui_snapshot import ImportProgressSnapshot, new_worker_generation
from ..linked_upload import LinkedTargetLease, LinkedThumbnail, LinkedUploadTarget, UploadPipelineLease, capture_linked_source, verify_linked_source
from ..localization import gettext_noop
from ..mode_coordinator import GuiModeOwner, ModeConfigPort, ModeCoordinatorError
from ..import_commands import ImportEvents
from ..models import (
    ImportItem, ImportJob, ITEM_ACTIVE_STATES, LEGACY_UPLOAD_UNCERTAIN_REASON,
    LINKED_UPLOAD_UNCERTAIN_REASON, RECOVERABLE_ITEM_STATES, JsonValue,
)
from ..state_store import JobStateStore
from ..watcher.queue import WatchQueueStore, WatchSource, observe_watch_source
from ..watcher import runtime, state as watcher_state
from ..watcher.state import ConfigStore, WatcherConfig, capture_worker_config
from ..watcher.workers import thumbnails
from ..watcher.workers.watcher import WatcherThread, WATCHER_UPDATE_PAUSE_POLL_SECONDS
from .policy import WATCHER_IMPORT_TREE_ROW_LIMIT

class ManualUploadThread(threading.Thread):
    """
    Background worker for manual bulk uploads.

    The worker keeps manual uploading separate from watch-folder polling while
    reusing captured configuration, endpoint normalization, API-key headers, and
    multipart upload function. Client-side thumbnail generation is optional and
    runs in a worker pool before each image is uploaded.
    """

    def __init__(
        self,
        config: watcher_state.WatcherConfig,
        paths: List[Path],
        client_thumbnails: bool,
        thumbnail_workers: int,
        upload_workers: int,
        events: "queue.Queue[Any]",
        job: Optional[ImportJob] = None,
        job_store: Optional[JobStateStore] = None,
        linked_target: Optional[LinkedUploadTarget] = None,
        gui_admission: Optional[Tuple[GuiModeOwner, ModeConfigPort]] = None,
    ) -> None:
        """
        Create a manual upload worker.

        Args:
            config (WatcherConfig): Shared connection configuration captured
                from the UI.
            paths (List[Path]): Image paths selected by the user for manual
                upload.
            client_thumbnails (bool): Whether to generate responsive thumbnails
                on.
            thumbnail_workers (int): Process count used for local thumbnail
                work.
            upload_workers (int): Thread count used for concurrent HTTP
                uploads.
            events (queue.Queue[Any]): Thread-safe queue used to send status
                messages to the GUI.
            job (Optional[ImportJob]): Worker-owned durable job copy used for
                progress and restart recovery.
            job_store (Optional[JobStateStore]): Shared state store for job and
                activity persistence.
            linked_target (Optional[LinkedUploadTarget]): Captured delegated
                gallery, or None for the saved gallery API-key connection.
            gui_admission (Optional[Tuple[GuiModeOwner, ModeConfigPort]]): GUI
                owner and exact immutable worker configuration port.

        Returns:
            None: Create a manual upload worker.
        """
        super().__init__(daemon=True)
        self.config = config
        self.linked_target = linked_target
        self._gui_admission = gui_admission
        self._gui_work: Optional[CoordinatedWork] = None
        self._linked_lease = (LinkedTargetLease(watcher_state.CONFIG_DIR, linked_target.binding())
                              if linked_target is not None else None)
        self.linked_state: Optional[watcher_state.LinkedUploadState] = None
        self.paths = paths
        self.client_thumbnails = client_thumbnails
        self.thumbnail_workers = watcher_state.resolve_thumbnail_worker_count(thumbnail_workers)
        # One delegated mutation at a time protects its durable write-ahead
        # marker and prevents same-target workers from racing recovery.
        self.upload_workers = (1 if linked_target is not None
                               else watcher_state.resolve_upload_worker_count(upload_workers))
        self.events = events
        self.stop_event = threading.Event()
        self._redirect_refused = threading.Event()
        self.pause_event = threading.Event()
        self.uploaded = 0
        self.failed = 0
        self.skipped_existing = 0
        self.completed_uploads = 0
        self.progress_lock = threading.Lock()
        self.remote_inventory = http_transport.RemoteInventorySession(
            config.inventory_refresh_seconds, self.emit, linked_target,
            self._redirect_checkpoint if linked_target is None else None,
        )
        self.job = job
        self.job_store = job_store
        self.job_lock = threading.RLock()
        self.import_generation = new_worker_generation()
        self.import_job_id = job.id if job is not None else ""
        self._progress_lock = threading.Lock()
        self._progress_snapshot: ImportProgressSnapshot | None = None
        self._progress_rows: dict[int, ImportItemView] = {}
        self._dirty_job_items: set[int] = set()
        self.item_by_path: Dict[str, ImportItem] = {}
        self._job_item_indices: dict[int, int] = {}
        if self.job is not None:
            for index, item in enumerate(self.job.items):
                self.item_by_path[os.path.normcase(os.path.abspath(item.path))] = item
                self._job_item_indices[id(item)] = index


    def _redirect_checkpoint(self) -> None:
        """Reject another manual request after an unsafe redirect was refused.

        Returns:
            None: This batch has not observed an unsafe redirect.

        Raises:
            GalleryKeyRedirectError: This batch must retain unfinished sources.
        """
        if self._redirect_refused.is_set():
            raise GalleryKeyRedirectError('Gallery API redirect was refused.')
        work = getattr(self, '_gui_work', None)
        if work is not None:
            work.checkpoint()

    def _stop_for_unsafe_redirect(self, path: Path, *, mode_error: bool = False) -> None:
        """Retain one ambiguous item and stop new requests after a refusal.

        Args:
            path (Path): Local source whose outcome must be reviewed.
            mode_error (bool): True when mode evidence rather than a redirect failed.

        Returns:
            None: Marks the item unresolved and signals the batch to stop.
        """
        self._redirect_refused.set()
        self.stop_event.set()
        with self.progress_lock:
            self.failed += 1
            self.completed_uploads += 1
        reason = ('Upload mode could not be verified' if mode_error
                  else 'Gallery API redirect was refused')
        uncertain = (LINKED_UPLOAD_UNCERTAIN_REASON if self.linked_target is not None
                     else LEGACY_UPLOAD_UNCERTAIN_REASON)
        self.update_item(
            path, 'failed_permanent', uncertain,
        )
        self.emit('error', reason + '; import sources are retained.')

    def stop(self) -> None:
        """
        Request the manual upload worker to stop after the current item.
        """
        self.stop_event.set()
        self.pause_event.clear()

    def pause(self) -> None:
        """Pause submission of new work while in-flight requests finish.

        Returns:
            None: Signals the pause and queues the worker's lifecycle transition.
        """
        self.set_paused(True)
        self.emit("info", gettext_noop("Manual import paused. In-flight requests may still finish."))
        self.emit_structured(
            "job_control", {"generation": self.import_generation, "paused": True},
        )

    def resume(self) -> None:
        """Resume a previously paused manual import.

        Returns:
            None: Signals the resume and queues the worker's lifecycle transition.
        """
        self.set_paused(False)
        self.emit("info", gettext_noop("Manual import resumed."))
        self.emit_structured(
            "job_control", {"generation": self.import_generation, "paused": False},
        )

    def set_paused(self, paused: bool) -> None:
        """Gate submission when the presentation already owns the pause feedback.

        Args:
            paused (bool): True to pause new work or false to allow the next head.
        Returns:
            None: Updates only the shared worker flag, without staging another event.
        """
        if paused:
            self.pause_event.set()
        else:
            self.pause_event.clear()

    def wait_if_paused(self) -> bool:
        """Wait while paused and return False when cancellation was requested."""
        while self.pause_event.is_set() and not self.stop_event.is_set():
            self.stop_event.wait(0.1)
        return not self.stop_event.is_set()

    def emit_structured(self, kind: str, payload: Dict[str, Any]) -> None:
        """Send one structured UI event without letting worker threads touch Tk widgets."""
        event = {"kind": kind, "payload": dict(payload)}
        if self.job is not None:
            event["job_id"] = self.job.id
        self.events.put(event)

    def item_for_path(self, path: Path) -> Optional[ImportItem]:
        """Return the durable item record associated with one local path."""
        return self.item_by_path.get(os.path.normcase(os.path.abspath(str(path))))

    def persist_job(self, *, complete_rows: bool = True) -> None:
        """Publish a bounded UI revision after the exact durable save succeeds.

        Args:
            complete_rows (bool): Include the complete visible prefix at start,
                cancellation and finish. Ordinary transitions send dirty rows.

        Returns:
            None: Queues a presentation revision after save and readback.

        Raises:
            OSError: The original durable job write or verification failed.
            ValueError: Job identity or durable item evidence could not be saved.
            RuntimeError: The accepted-work owner could not be acquired.
        """
        if self.job is None or self.job_store is None:
            return
        with self.job_lock:
            self.job_store.save_job(self.job)
            previous = self._progress_snapshot
            # The first revision seeds both detail views. Changes made by
            # multiple upload threads before this save belong to this revision.
            snapshot = ImportProgressSnapshot.from_job(
                self.job,
                self.import_generation,
                previous.revision + 1 if previous is not None else 1,
                WATCHER_IMPORT_TREE_ROW_LIMIT,
                None if complete_rows or previous is None else frozenset(
                    self._dirty_job_items,
                ),
            )
            with self._progress_lock:
                if snapshot.complete_rows:
                    self._progress_rows.clear()
                self._progress_rows.update(
                    (row.source_index, row) for row in snapshot.items
                )
                self._progress_snapshot = snapshot
            self._dirty_job_items.clear()
            self.emit_structured("job_progress", {"snapshot": snapshot})

    def latest_import_projection(self) -> ImportProgressSnapshot | None:
        """Recover the complete bounded projection after a GUI revision gap.

        Returns:
            ImportProgressSnapshot | None: Latest saved revision with its rows,
                or None before any save. No job lock, disk or Tk is involved.
        """
        with self._progress_lock:
            snapshot = self._progress_snapshot
            if snapshot is None or snapshot.complete_rows:
                return snapshot
            return replace(
                snapshot, items=tuple(self._progress_rows.values()), complete_rows=True,
            )

    def update_item(
        self,
        path: Path,
        state: str,
        reason: str = "",
        file_hash: str = "",
        server_result: dict[str, JsonValue] | None = None,
        increment_attempt: bool = False,
        count_bytes: bool = False,
    ) -> None:
        """Apply one durable item transition and save the enclosing job.

        Args:
            path (Path): Accepted local source whose existing item is changed.
            state (str): Requested import state as validated by the item model.
            reason (str): Optional presentation detail about the transition.
            file_hash (str): Optional verified source digest.
            server_result (dict[str, JsonValue] | None): Server response fields from
                which the allowed receipt summary is retained.
            increment_attempt (bool): Record a dispatched transport attempt.
            count_bytes (bool): Advance durable byte progress on confirmation.

        Returns:
            None: Saves the changed item or leaves an unknown path alone.

        Raises:
            OSError: The required save or readback failed.
            ValueError: The transition or durable identity is invalid.
            RuntimeError: Durable accepted-work ownership is unavailable.
        """
        item = self.item_for_path(path)
        if item is None or self.job is None:
            return
        with self.job_lock:
            previous_state = item.state
            item.transition(state, reason)
            self._dirty_job_items.add(self._job_item_indices[id(item)])
            if file_hash:
                item.sha256 = file_hash
            if increment_attempt:
                item.attempt_count += 1
            if server_result is not None:
                allowed = {"uploaded", "scanned", "filenames", "client_thumbnails"}
                item.server_result = {key: value for key, value in server_result.items() if key in allowed}
            if count_bytes and previous_state != "confirmed":
                self.job.bytes_sent = min(self.job.bytes_total, self.job.bytes_sent + max(0, item.size))
        self.persist_job(complete_rows=False)

    def mark_remaining_cancelled(self) -> None:
        """Persist cancellation for work that never reached a terminal state."""
        if self.job is None:
            return
        changed = False
        with self.job_lock:
            for item in self.job.items:
                if item.state in ITEM_ACTIVE_STATES:
                    item.transition("cancelled", "Import cancelled before this item completed.")
                    changed = True
        if changed:
            self.persist_job()

    def finalize_job(self) -> None:
        """Persist the terminal batch while retaining all original ZIP sources.

        Returns:
            None: Finishes the job without deleting source or staged media.
        """
        if self.job is None:
            return
        with self.job_lock:
            self.job.finished_at = time.time()
        self.persist_job()
        # A local confirmed/duplicate state does not attest a durable
        # server image-operation receipt or prove absent historical work.
        # Keep extracted originals even when every item looks successful.
        if self.job.staging_dir:
            self.emit(
                "warning",
                gettext_noop(
                    "Import staging sources retained; safe automatic deletion "
                    "requires server-bound operation receipts."
                ),
            )
        if (self.linked_target is None and self.job.delete_source_zip_after_success
                and self.job.source_zip):
            self.emit(
                "warning",
                gettext_noop(
                    "Legacy upload cannot verify the destination for ZIP cleanup; "
                    "the source ZIP is retained."
                ),
            )


    def emit(self, level: str, message: str) -> None:
        """
        Send a status message to the GUI and persistent log.

        Args:
            level (str): Logging level name such as info, warning, or error.
            message (str): Human-readable status message.

        Returns:
            None: Send a status message to the GUI and persistent log.
        """
        self.events.put((level, message))
        log_method = getattr(logging, level if level in {"debug", "info", "warning", "error"} else "info")
        log_method(message)

    def run(self) -> None:
        """Retain the target lease throughout one manual import worker.

        Returns:
            None: Releases transport ownership and reports this generation's
                actual lifecycle outcome. Incomplete work remains recoverable.
        """
        pipeline = UploadPipelineLease(watcher_state.CONFIG_DIR)
        completed = False
        try:
            pipeline.acquire()
            if self._gui_admission is not None:
                owner, port = self._gui_admission
                kind = 'linked' if self.linked_target is not None else 'legacy'
                self._gui_work = owner.acquire(kind, port)
                self.remote_inventory.before_request = self._redirect_checkpoint
            if self.linked_target is not None:
                if self._linked_lease is None:
                    raise ValueError('Linked gallery lease is unavailable.')
                self._linked_lease.acquire()
                self.linked_state = watcher_state.LinkedUploadState(self.linked_target)
            self._run_owned()
            completed = True
        except (OSError, ValueError, RuntimeError) as exc:
            self.emit('error', f'Import cannot acquire safe recovery state: {exc}')
        finally:
            pipeline.release()
            if self._gui_work is not None:
                self._gui_work.close()
            if self._linked_lease is not None:
                self._linked_lease.release()
            if self.import_job_id:
                last_saved = self._progress_snapshot
                self.emit_structured("job_completed", {
                    "generation": self.import_generation,
                    "cancelled": self.stop_event.is_set(),
                    "needs_review": (
                        not completed or last_saved is None or not last_saved.finished_at
                    ),
                })

    def _run_owned(self) -> None:
        """Run the selected manual upload mode under its captured owner.

        Returns:
            None: Records item outcomes without retrying ambiguous transfers.
        """
        upload_url = (self.linked_target.issuer if self.linked_target is not None
                      else http_transport.normalize_upload_url(self.config.gallery_url))
        if (not upload_url
                or (self.linked_target is None and not self.config.api_key.strip())
                or (self.linked_target is not None
                    and not self.linked_target.session.can_upload())):
            reason = ('Linked gallery session expired; reconnect before continuing.'
                      if self.linked_target is not None
                      else 'Gallery URL and API key are required.')
            self.emit('error', reason)
            if self.job is not None:
                for path in self.paths:
                    self.update_item(
                        path, 'failed_retryable' if self.linked_target is not None
                        else 'failed_permanent', reason,
                    )
                self.finalize_job()
            return
        if (self.linked_target is not None and (
                self.job is None or self.job_store is None
                or any(self.item_for_path(path) is None for path in self.paths))):
            self.emit('error', gettext_noop('Linked import requires a durable item recovery record.'))
            return
        if self.linked_state is not None and self.linked_state.has_unresolved_upload():
            self.emit('error', LINKED_UPLOAD_UNCERTAIN_REASON)
            return
        if not self.paths:
            self.emit("warning", gettext_noop("No manual upload files were selected."))
            if self.job is not None:
                self.finalize_job()
            return

        self._fifo_existing_job = self.job is not None and self.job.started_at > 0
        if self.job is not None:
            with self.job_lock:
                if self.job.started_at <= 0:
                    self.job.started_at = time.time()
                self.job.finished_at = 0.0
                for path in self.paths:
                    item = self.item_for_path(path)
                    if item is not None and item.state in RECOVERABLE_ITEM_STATES:
                        item.transition("queued")
            self.persist_job()

        self.emit("info", f"Manual upload started: {len(self.paths)} file(s).")
        self.emit("info", f"Upload endpoint: {upload_url}")
        self.emit("info", f"Remote inventory reconnect interval: {self.config.inventory_refresh_seconds:g} seconds.")

        try:
            self._run_fifo_import(upload_url)
        except (GalleryKeyRedirectError, ModeCoordinatorError) as exc:
            self._redirect_refused.set()
            self.stop_event.set()
            reason = ('Upload mode could not be verified' if isinstance(exc, ModeCoordinatorError)
                      else 'Gallery API redirect was refused')
            self.emit('error', reason + '; import sources are retained.')
        finally:
            if self.stop_event.is_set():
                self.mark_remaining_cancelled()
            self.finalize_job()

        if self.stop_event.is_set():
            self.emit("info", f"Manual upload stopped: uploaded={self.uploaded}, skipped_existing={self.skipped_existing}, failed={self.failed}.")
        else:
            self.emit("info", f"Manual upload finished: uploaded={self.uploaded}, skipped_existing={self.skipped_existing}, failed={self.failed}.")


    def _run_fifo_import(self, upload_url: str) -> None:
        """Use the same head-only consumer for imports, including staged ZIP images.

        The Import consumer uses a private config snapshot with watched-source
        deletion disabled; the saved preference remains available to Watcher.

        Args:
            upload_url (str): Captured legacy endpoint or linked issuer.

        Returns:
            None: Retains order, backoff and unknown results in the accepted job queue.

        Raises:
            OSError: Accepted source or durable job/queue evidence is unavailable.
            ValueError: Job identity or immutable source selection changed.
        """
        if self.job is None or self.job_store is None:
            raise ValueError('Import requires a durable accepted job.')
        binding = (self.linked_target.binding() if self.linked_target is not None
                   else connection_binding(upload_url, self.config.api_key.strip()))
        selected = tuple(item.path for item in self.job.items)
        directory = watcher_state.CONFIG_DIR / 'import-queues' / self.job.id
        journal = WatchQueueStore(
            directory / 'watch-drain.json', binding, watcher_state.CONFIG_DIR.absolute(), False,
            allowed_paths=selected,
            scope=(self.linked_target.session.approved_scope()
                   if self.linked_target is not None else ''),
        )
        if journal.path.exists():
            journal.restore()
        else:
            if self._fifo_existing_job and len(self.paths) > 1:
                raise ValueError('Earlier import has no durable FIFO order; repeat local preflight.')
            journal.initialize([])
            observations: List[WatchSource] = []
            for original in self.job.items:
                try:
                    observed = observe_watch_source(Path(original.path), time.time())
                except OSError:
                    observed = WatchSource(original.path, '', original.size,
                                           self.job.created_at, 0.0,
                                           time.time(), 'accepted_import_order')
                observations.append(observed)
            journal.reconcile(observations)
        # Import-selected originals are retained independently of the Watcher
        # preference. Keep the saved/shared config unchanged for later Watcher
        # sessions and give this consumer an Import-specific snapshot.
        consumer_config = replace(self.config, delete_uploaded_files=False)
        consumer = WatcherThread(consumer_config, self.events, self.linked_target,
                                 once_work=self._gui_work)
        consumer._queue = journal
        consumer._admission_lock = journal.lock
        consumer.state = self.linked_state
        consumer.remote_inventory = self.remote_inventory
        consumer.stop_event = self.stop_event
        consumer._client_thumbnail_preference = self.client_thumbnails
        consumer._expected_source_hashes = {item.path: item.sha256 for item in self.job.items}
        # The accepted static selection needs no second producer. This owning
        # worker already holds both native leases through consumer completion.
        consumer._producer_thread = threading.current_thread()
        self._fifo_consumer = consumer
        completed = {item.id for item in self.job.items
                     if item.state in ('confirmed', 'skipped_duplicate')}

        def dispatched() -> None:
            """Persist the current import attempt before its single HTTP request.

            Returns:
                None: Updates only the accepted head, retaining the shared queue marker.
            """
            item = journal.head()
            if item is None:
                raise ValueError('Import dispatch has no queue head.')
            self.update_item(Path(item.source.path), 'uploading',
                             file_hash=item.sha256, increment_attempt=True)

        def acknowledged(payload: Dict[str, JsonValue]) -> None:
            """Retain a validated live reply in the existing accepted import owner.

            Args:
                payload (Dict[str, JsonValue]): Validated upload counts, image IDs,
                    filenames, scanner evidence and optional thumbnail summaries.

            Returns:
                None: Publishes the job acknowledgement before advancing its FIFO.
            """
            item = journal.head()
            if item is None:
                raise ValueError('Import acknowledgement has no queue head.')
            self.update_item(Path(item.source.path), 'confirmed', file_hash=item.sha256,
                             server_result=payload, count_bytes=True)

        consumer._dispatch_observer = dispatched
        consumer._ack_observer = acknowledged
        try:
            while not self.stop_event.is_set():
                if not self.wait_if_paused():
                    break
                head = journal.head()
                if head is None:
                    break
                source_item = self.item_for_path(Path(head.source.path))
                if source_item is None:
                    raise ValueError('FIFO source is absent from the accepted import.')
                if (source_item.state in ('confirmed', 'skipped_duplicate')
                        and source_item.sha256
                        and (not head.sha256 or head.sha256 == source_item.sha256)):
                    # The accepted job acknowledgement was saved before its
                    # queue acknowledgement. Recover it locally, never by
                    # starting another transfer or treating inventory as proof.
                    journal.update(
                        head, state='acknowledged', sha256=source_item.sha256,
                        disposition=('uploaded' if source_item.state == 'confirmed'
                                     else 'duplicate'), reason='', next_retry_at=0.0,
                    )
                    continue
                if source_item.sha256 and head.sha256 and source_item.sha256 != head.sha256:
                    journal.update(head, state='blocked', phase='source',
                                   reason='Accepted import source bytes changed; review required.')
                # One head per iteration also honors Pause before B starts
                # inventory; the capture consumer's multi-head scan is not
                # needed for this static accepted selection.
                consumer._consume_head(Path(head.source.path).parent, upload_url)
                for item in journal.items():
                    original = self.item_for_path(Path(item.source.path))
                    if item.state != 'acknowledged' or original.id in completed:
                        continue
                    if original.state != 'confirmed':
                        self.update_item(Path(item.source.path), 'skipped_duplicate',
                                         'Confirmed content already exists on this target.',
                                         file_hash=item.sha256)
                        self.skipped_existing += 1
                    else:
                        self.uploaded += 1
                    self.completed_uploads += 1
                    completed.add(original.id)
                current = journal.head()
                if current is not None and current.state in (
                        'uncertain', 'auth_required', 'blocked'):
                    self.failed += 1
                    self.update_item(Path(current.source.path), 'failed_permanent',
                                     (LINKED_UPLOAD_UNCERTAIN_REASON if self.linked_target is not None
                                      else LEGACY_UPLOAD_UNCERTAIN_REASON)
                                     if current.state == 'uncertain' else current.reason,
                                     file_hash=current.sha256)
                    self.emit('error', current.reason)
                    break
                self.stop_event.wait(WATCHER_UPDATE_PAUSE_POLL_SECONDS)
        finally:
            if consumer._pending_transport is not None:
                consumer._pending_transport.wait()
            self._fifo_consumer = None

    def preload_remote_inventory(self, upload_url: str) -> None:
        """
        Ask the gallery which selected files are already present before a batch.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.

        Returns:
            None: Ask the gallery which selected files are already present
                before a batch.
        """
        candidates: List[Dict[str, Any]] = []
        for path in self.paths:
            if self.stop_event.is_set():
                return
            try:
                candidates.append(http_transport.inventory_candidate(path))
            except OSError as exc:
                self.emit("warning", f"Cannot include {path.name} in remote inventory preload: {exc}")
        if candidates:
            if self.linked_target is not None:
                for start in range(0, len(candidates), LINKED_INVENTORY_BATCH_SIZE):
                    chunk = candidates[start:start + LINKED_INVENTORY_BATCH_SIZE]
                    if not self.remote_inventory.refresh(
                        upload_url, self.config.api_key.strip(), chunk, force=True,
                    ):
                        self.emit('warning', gettext_noop('Linked inventory preload incomplete; individual files will be rechecked.'))
                        break
            else:
                self.remote_inventory.refresh(upload_url, self.config.api_key.strip(), candidates, force=True)

    def remote_skip_before_work(self, upload_url: str, path: Path, file_hash: str, size: int) -> bool:
        """
        Return whether an upload should be skipped because the gallery has it.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.
            path (Path): Local image path being considered.
            file_hash (str): SHA-256 hash of the local image.
            size (int): Local file size in bytes.

        Returns:
            bool: True when the remote gallery confirms the file already
                exists.
        """
        candidate = http_transport.inventory_candidate(path, file_hash, size)
        refreshed = self.remote_inventory.refresh(
            upload_url, self.config.api_key.strip(), [candidate],
            force=True,
        )
        if self.linked_target is not None and not refreshed:
            raise RuntimeError('Linked gallery inventory is unavailable.')
        if not refreshed or not self.remote_inventory.has_hash(file_hash):
            return False
        with self.progress_lock:
            self.skipped_existing += 1
            self.completed_uploads += 1
        self.update_item(path, "skipped_duplicate", "Gallery inventory already contains this content.", file_hash=file_hash)
        self.emit("info", f"Skipped already present on gallery: {path.name}")
        return True


    def run_with_server_thumbnails(self, upload_url: str) -> None:
        """
        Upload selected originals and ask PHP Gallery to create thumbnails.

        This mode keeps the upload executor structure used by the client-thumbnail
        pipeline, but the resolved network worker count is one. PHP Gallery already
        serializes mutations for one gallery, so concurrent requests would only hold
        extra PHP workers and can starve normal gallery page requests on shared hosting.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.

        Returns:
            None: Upload selected originals and ask PHP Gallery to create
                thumbnails.
        """
        self.emit("info", f"Uploading with {self.upload_workers} upload thread(s).")
        with concurrent.futures.ThreadPoolExecutor(max_workers=self.upload_workers) as executor:
            pending: Dict[concurrent.futures.Future[None], Path] = {}
            path_iterator = iter(enumerate(self.paths, start=1))
            queue_limit = max(1, self.upload_workers * 2)

            def submit_next() -> bool:
                """
                Submit one server-thumbnail upload when input remains.

                Returns:
                    bool: True when an upload task was queued.
                """
                if not self.wait_if_paused():
                    return False
                try:
                    index, path = next(path_iterator)
                except StopIteration:
                    return False
                self.update_item(path, "queued")
                future = executor.submit(self.upload_one, upload_url, path, index, [], None, True)
                pending[future] = path
                return True

            for _ in range(queue_limit):
                if not submit_next():
                    break

            while pending:
                if self.stop_event.is_set():
                    return
                done, _ = concurrent.futures.wait(pending.keys(), timeout=0.2, return_when=concurrent.futures.FIRST_COMPLETED)
                if not done:
                    continue
                for future in done:
                    pending.pop(future)
                    try:
                        future.result()
                    except Exception as exc:  # noqa: BLE001
                        with self.progress_lock:
                            self.failed += 1
                        self.emit("error", f"Manual upload worker crashed: {exc}")
                    if not self.stop_event.is_set():
                        submit_next()


    def run_with_client_thumbnails(self, upload_url: str) -> None:
        """
        Generate thumbnails in separate processes and upload in parallel threads.

        This is a producer-consumer pipeline tuned for large manual batches. CPU
        heavy resize and encode work runs in a ProcessPoolExecutor so Windows can
        schedule it across many CPU cores. Network-bound multipart uploads run in
        a separate ThreadPoolExecutor. Thumbnail generation, uploading, and temp
        file cleanup overlap, but both queues remain bounded so a 500-photo batch
        does not fill the disk with completed thumbnail sets waiting to upload.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.

        Returns:
            None: Generate thumbnails in separate processes and upload in
                parallel threads.
        """
        if not runtime.local_thumbnail_supported():
            self.emit("warning", gettext_noop("Local thumbnails are unavailable; falling back to server-side thumbnail generation."))
            self.run_with_server_thumbnails(upload_url)
            return

        temp_root = Path(tempfile.mkdtemp(prefix="php_gallery_thumbs_"))
        try:
            thumbnail_executor = concurrent.futures.ProcessPoolExecutor(max_workers=self.thumbnail_workers)
        except BaseException:
            shutil.rmtree(temp_root, ignore_errors=True)
            raise
        try:
            upload_executor = concurrent.futures.ThreadPoolExecutor(max_workers=self.upload_workers)
        except BaseException:
            try:
                thumbnail_executor.shutdown(wait=True, cancel_futures=True)
            finally:
                shutil.rmtree(temp_root, ignore_errors=True)
            raise
        pending_thumbnails: Dict[concurrent.futures.Future[List[LocalThumbnail]], Tuple[int, Path, str, str]] = {}
        pending_uploads: Dict[concurrent.futures.Future[None], Tuple[Path, Path]] = {}
        path_iterator = iter(enumerate(self.paths, start=1))
        thumbnail_queue_limit = max(1, self.thumbnail_workers * 2)
        upload_queue_limit = max(1, self.upload_workers * 2)

        def submit_next_thumbnail() -> bool:
            """
            Submit one thumbnail job to the process pool when input remains.

            Files already confirmed by the remote gallery are consumed here
            without starting local thumbnail generation. The loop continues until
            it either queues real work or exhausts the selected file list.

            Returns:
                bool: True when a thumbnail task was submitted.
            """
            while not self.stop_event.is_set():
                if not self.wait_if_paused():
                    return False
                try:
                    index, path = next(path_iterator)
                except StopIteration:
                    return False
                try:
                    self.update_item(path, "hashing")
                    file_hash = http_transport.sha256_file(path)
                    size = path.stat().st_size
                except OSError as exc:
                    with self.progress_lock:
                        self.failed += 1
                        self.completed_uploads += 1
                    self.update_item(path, "failed_permanent", f"Cannot read source file: {exc}")
                    self.emit("error", f"Cannot read {path.name}: {exc}")
                    continue
                try:
                    if (self.linked_target is None
                            and self.remote_skip_before_work(upload_url, path, file_hash, size)):
                        continue
                except RuntimeError as exc:
                    with self.progress_lock:
                        self.failed += 1
                        self.completed_uploads += 1
                    self.update_item(path, 'failed_retryable', str(exc))
                    self.emit('error', f'Linked inventory unavailable for {path.name}.')
                    self.stop_event.set()
                    return False
                client_upload_id = uuid.uuid4().hex
                self.update_item(path, "staged", file_hash=file_hash)
                if self.linked_target is not None:
                    future = thumbnail_executor.submit(
                        thumbnails.generate_linked_thumbnails, path, temp_root, client_upload_id, file_hash,
                    )
                else:
                    future = thumbnail_executor.submit(thumbnails.generate_local_thumbnails, path, temp_root, client_upload_id)
                pending_thumbnails[future] = (index, path, client_upload_id, file_hash)
                return True
            return False

        def submit_upload(
            path: Path,
            index: int,
            thumbnails: List[LocalThumbnail],
            client_upload_id: Optional[str],
            create_server_thumbnails: bool,
            cleanup_dir: Path,
            expected_sha256: str,
        ) -> None:
            """
            Submit one upload job to the upload thread pool.

            Args:
                path (Path): Original image path to upload.
                index (int): One-based item index for progress messages.
                thumbnails (List[LocalThumbnail]): Local thumbnail files to
                    include in the request.
                client_upload_id (Optional[str]): Request-local ID mapping
                    thumbnails to the.
                create_server_thumbnails (bool): Whether the server should
                    generate.
                cleanup_dir (Path): Temporary directory to delete after upload.
                expected_sha256 (str): Hash captured before thumbnail generation.

            Returns:
                None: Submit one upload job to the upload thread pool.
            """
            if not self.wait_if_paused():
                return
            self.update_item(path, "queued")
            future = upload_executor.submit(
                self.upload_one,
                upload_url,
                path,
                index,
                thumbnails,
                client_upload_id,
                create_server_thumbnails,
                expected_sha256 if self.linked_target is not None else '',
            )
            pending_uploads[future] = (path, cleanup_dir)

        def drain_completed_uploads(wait_for_one: bool) -> None:
            """
            Collect completed upload jobs and remove their temp directories.

            Args:
                wait_for_one (bool): When True, wait until at least one upload
                    has.

            Returns:
                None: Collect completed upload jobs and remove their temp
                    directories.
            """
            if not pending_uploads:
                return
            timeout = None if wait_for_one else 0
            done, _ = concurrent.futures.wait(
                pending_uploads.keys(),
                timeout=timeout,
                return_when=concurrent.futures.FIRST_COMPLETED,
            )
            for future in done:
                _path, cleanup_dir = pending_uploads.pop(future)
                try:
                    future.result()
                except Exception as exc:  # noqa: BLE001
                    with self.progress_lock:
                        self.failed += 1
                    self.emit("error", f"Manual upload worker crashed: {exc}")
                finally:
                    shutil.rmtree(cleanup_dir, ignore_errors=True)

        try:
            for _ in range(thumbnail_queue_limit):
                if not submit_next_thumbnail():
                    break

            self.emit(
                "info",
                f"Generating thumbnails with {self.thumbnail_workers} worker process(es) and uploading with {self.upload_workers} thread(s).",
            )

            while pending_thumbnails or pending_uploads:
                if self.stop_event.is_set():
                    return

                while len(pending_uploads) >= upload_queue_limit:
                    drain_completed_uploads(wait_for_one=True)
                    if self.stop_event.is_set():
                        return

                drain_completed_uploads(wait_for_one=False)

                if not pending_thumbnails:
                    drain_completed_uploads(wait_for_one=True)
                    continue

                done, _ = concurrent.futures.wait(
                    pending_thumbnails.keys(),
                    timeout=0.1,
                    return_when=concurrent.futures.FIRST_COMPLETED,
                )
                if not done:
                    continue

                for future in done:
                    index, path, client_upload_id, expected_sha256 = pending_thumbnails.pop(future)
                    thumbnail_dir = temp_root / client_upload_id
                    try:
                        thumbnails = future.result()
                        self.emit("info", f"Generated {len(thumbnails)} thumbnail file(s) for {path.name}.")
                        submit_upload(path, index, thumbnails, client_upload_id, False, thumbnail_dir, expected_sha256)
                    except Exception as exc:  # noqa: BLE001
                        if self.linked_target is not None:
                            self.update_item(path, 'failed_retryable', str(exc))
                            self.emit('error', f'Linked thumbnail generation failed for {path.name}.')
                            self.stop_event.set()
                            continue
                        self.emit("warning", f"Local thumbnails failed for {path.name}; asking server to create them: {exc}")
                        self.emit_structured("thumbnail_fallback", {"filename": path.name, "reason": str(exc)})
                        submit_upload(path, index, [], None, True, thumbnail_dir, expected_sha256)

                    if not self.stop_event.is_set() and len(pending_thumbnails) < thumbnail_queue_limit:
                        submit_next_thumbnail()

            drain_completed_uploads(wait_for_one=False)
        finally:
            try:
                thumbnail_executor.shutdown(wait=True, cancel_futures=True)
            finally:
                try:
                    upload_executor.shutdown(wait=True, cancel_futures=True)
                finally:
                    for _path, cleanup_dir in list(pending_uploads.values()):
                        shutil.rmtree(cleanup_dir, ignore_errors=True)
                    shutil.rmtree(temp_root, ignore_errors=True)


    def upload_one(
        self,
        upload_url: str,
        path: Path,
        index: int,
        thumbnails: List[LocalThumbnail],
        client_upload_id: Optional[str],
        create_server_thumbnails: bool,
        expected_sha256: str = '',
    ) -> None:
        """
        Upload one original, optionally with locally generated thumbnails.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.
            path (Path): Original image path to upload.
            index (int): One-based item index for status messages.
            thumbnails (List[LocalThumbnail]): Local thumbnail files to submit
                with the original.
            client_upload_id (Optional[str]): Request-local ID mapping
                derivatives to this original.
            create_server_thumbnails (bool): Whether PHP Gallery should
                generate thumbnails.
            expected_sha256 (str): Source hash before linked thumbnail work,
                or empty when no linked snapshot was generated.

        Returns:
            None: Records a confirmed, skipped or failed disposition; retains
                an unknown original excluded from dispatch after review.
        """
        if not self.wait_if_paused():
            return
        candidate: Optional[Dict[str, Any]] = None
        linked_send_started = False
        legacy_send_started = False
        try:
            self.update_item(path, "hashing")
            file_hash = http_transport.sha256_file(path)
            if self.linked_target is not None and expected_sha256 and file_hash != expected_sha256:
                raise ValueError('Source changed during linked thumbnail generation.')
            size = path.stat().st_size
            linked_source = (capture_linked_source(path, file_hash, size)
                             if self.linked_target is not None else None)
            candidate = http_transport.inventory_candidate(path, file_hash, size)
            if (self.linked_state is not None
                    and self.linked_state.has_unresolved_upload()):
                self.update_item(path, 'failed_permanent', LINKED_UPLOAD_UNCERTAIN_REASON)
                self.emit('error', LINKED_UPLOAD_UNCERTAIN_REASON)
                self.stop_event.set()
                return
            if (self.linked_state is not None
                    and self.linked_state.has_uncertain_source(path, file_hash)):
                with self.progress_lock:
                    self.failed += 1
                    self.completed_uploads += 1
                self.update_item(path, 'failed_permanent', LINKED_UPLOAD_UNCERTAIN_REASON)
                self.emit('warning', f'Kept {path.name}; its prior upload remains uncertain.')
                return
            inventory_ready = self.remote_inventory.refresh(
                upload_url, self.config.api_key.strip(), [candidate],
                force=True,
            )
            if self.linked_target is not None and not inventory_ready:
                raise RuntimeError('Linked gallery inventory is unavailable.')
            if inventory_ready and self.remote_inventory.has_hash(file_hash):
                if linked_source is not None:
                    verify_linked_source(linked_source)
                with self.progress_lock:
                    self.skipped_existing += 1
                    self.completed_uploads += 1
                self.update_item(path, "skipped_duplicate", "Gallery inventory already contains this content.", file_hash=file_hash)
                self.emit("info", f"Skipped already present on gallery: {path.name}")
                return

            if not self.wait_if_paused():
                return
            if self.linked_state is not None:
                # Share the write-ahead marker with watcher and future imports.
                self.linked_state.mark_uncertain(path, file_hash)
            self.update_item(
                path, 'failed_permanent' if self.linked_target is not None else 'uploading',
                LINKED_UPLOAD_UNCERTAIN_REASON if self.linked_target is not None else '',
                file_hash=file_hash, increment_attempt=True,
            )
            self.emit("info", f"Uploading {index}/{len(self.paths)}: {path.name}")
            if self.linked_target is not None:
                # The durable item marker precedes all network side effects.
                linked_send_started = True
                self._redirect_checkpoint()
                payload = self.linked_target.upload(
                    path, create_server_thumbnails, file_hash,
                    tuple(LinkedThumbnail(item.path, item.size, item.format)
                          for item in thumbnails), client_upload_id or '',
                )
                self.linked_state.mark_uploaded(path, file_hash, size, payload)
            else:
                legacy_send_started = True
                payload = http_transport.multipart_upload(
                    upload_url,
                    self.config.api_key.strip(),
                    path,
                    create_server_thumbnails,
                    thumbnails=thumbnails,
                    client_upload_id=client_upload_id,
                    before_request=self._redirect_checkpoint,
                    expected_sha256=file_hash, expected_size=size,
                    single_dispatch=True,
                    selected_endpoint=self.remote_inventory.resolved_endpoint,
                )
            with self.progress_lock:
                self.uploaded += int(payload.get("uploaded", 0) or 0)
                self.completed_uploads += 1
            self.update_item(path, "confirmed", file_hash=file_hash, server_result=payload, count_bytes=True)
            installed = 0
            failed_thumbnails = 0
            thumbnail_errors: List[str] = []
            client_result = payload.get("client_thumbnails")
            if isinstance(client_result, dict):
                installed = int(client_result.get("installed", 0) or 0)
                failed_thumbnails = int(client_result.get("failed", 0) or 0)
                raw_errors = client_result.get("errors")
                if isinstance(raw_errors, list):
                    thumbnail_errors = [str(item) for item in raw_errors[:3]]
            renamed = int(payload.get("renamed", 0) or 0)
            self.emit("info", f"Uploaded {path.name}: uploaded={payload.get('uploaded', 0)}, scanned={payload.get('scanned', 0)}, renamed={renamed}, client_thumbnails={installed}")
            rename_failures = payload.get("rename_failures")
            if isinstance(rename_failures, list) and rename_failures:
                self.emit("warning", f"Upload succeeded but automatic rename policy was not fully enforced for {path.name}: {'; '.join(str(item) for item in rename_failures[:3])}")
            if failed_thumbnails or thumbnail_errors:
                details = "; ".join(thumbnail_errors) if thumbnail_errors else "no detailed server message"
                self.emit("warning", f"Client thumbnails were partially rejected for {path.name}: failed={failed_thumbnails}; {details}. Server-side generation remains authoritative.")
        except (GalleryKeyRedirectError, ModeCoordinatorError) as exc:
            # A redirect can arrive after an original was accepted; never turn
            # that ambiguous result into an automatic retry or confirmation.
            self._stop_for_unsafe_redirect(path, mode_error=isinstance(exc, ModeCoordinatorError))
        except Exception as exc:  # noqa: BLE001
            if self.linked_target is not None:
                with self.progress_lock:
                    self.failed += 1
                    self.completed_uploads += 1
                if linked_send_started:
                    if candidate is not None and self.linked_state is not None:
                        try:
                            self.linked_state.mark_uncertain(
                                path, str(candidate.get('sha256', '')),
                            )
                        except (OSError, ValueError):
                            self.emit('error', gettext_noop('Linked recovery marker could not be refreshed.'))
                    self.update_item(path, 'failed_permanent', LINKED_UPLOAD_UNCERTAIN_REASON)
                    self.emit('error', LINKED_UPLOAD_UNCERTAIN_REASON)
                else:
                    self.update_item(path, 'failed_retryable', str(exc))
                    self.emit('error', f'Linked upload could not start: {exc}')
                self.stop_event.set()
                return
            if legacy_send_started:
                with self.progress_lock:
                    self.failed += 1
                    self.completed_uploads += 1
                self.update_item(path, 'failed_permanent', LEGACY_UPLOAD_UNCERTAIN_REASON)
                self.emit('error', LEGACY_UPLOAD_UNCERTAIN_REASON)
                self.stop_event.set()
                return
            try:
                if (
                    candidate is not None
                    and http_transport.upload_failure_needs_inventory_reconciliation(exc)
                    and self.remote_inventory.confirm_after_failure(upload_url, self.config.api_key.strip(), candidate)
                ):
                    with self.progress_lock:
                        self.skipped_existing += 1
                        self.completed_uploads += 1
                    file_hash = str(candidate.get("sha256", ""))
                    # This path is reached only before legacy HTTP dispatch.
                    # Matching gallery inventory is a duplicate-skip hint, not
                    # an immutable receipt for any particular upload attempt.
                    self.update_item(
                        path,
                        "skipped_duplicate",
                        "Matching content is already indexed; no upload was dispatched by this attempt.",
                        file_hash=file_hash,
                    )
                    self.emit("warning", f"Pre-dispatch operation failed for {path.name}; existing gallery inventory matches the content, so this attempt was skipped without an upload receipt. Original error: {exc}")
                    return
            except (GalleryKeyRedirectError, ModeCoordinatorError) as exc:
                self._stop_for_unsafe_redirect(path, mode_error=isinstance(exc, ModeCoordinatorError))
                return
            except Exception as confirm_exc:  # noqa: BLE001
                self.emit("warning", f"Could not confirm remote inventory after failure for {path.name}: {confirm_exc}")
            with self.progress_lock:
                self.failed += 1
                self.completed_uploads += 1
            message = str(exc)
            lower = message.lower()
            permanent = any(token in lower for token in ["api key", "authentication", "unauthorized", "forbidden", "unsupported", "invalid gallery", "http 400", "http 401", "http 403", "http 404", "http 422"])
            state = "failed_permanent" if permanent else "failed_retryable"
            self.update_item(path, state, message)
            self.emit("error", f"Manual upload failed for {path.name}: {exc}")


def create_import_worker(
    config_store: ConfigStore, config: WatcherConfig, owner: GuiModeOwner,
    job_store: JobStateStore, job: ImportJob, paths: list[Path],
    linked_target: LinkedUploadTarget | None, use_local_thumbnails: bool,
    events: ImportEvents,
) -> ManualUploadThread:
    """Construct the same upload worker from either desktop's admitted job.

    Args:
        config_store (ConfigStore): Existing owner of the exact saved config.
        config (WatcherConfig): Saved selection rechecked for this operation.
        owner (GuiModeOwner): Desktop mode admission owner.
        job_store (JobStateStore): Existing durable Import job owner.
        job (ImportJob): Already admitted neutral job to copy for the worker.
        paths (list[Path]): Verified original inputs for the selected attempt.
        linked_target (LinkedUploadTarget | None): Delegated target or legacy mode.
        use_local_thumbnails (bool): Accepted preference after runtime checks.
        events (ImportEvents): Presentation destination of worker status and
            structured lifecycle events.
    Returns:
        ManualUploadThread: Unstarted existing worker with immutable settings.
    """
    captured = capture_worker_config(config)
    return ManualUploadThread(
        captured, list(paths), use_local_thumbnails, config.manual_thumbnail_workers,
        config.manual_upload_workers, events,
        # The worker owns mutations. The UI can hold an independent review copy.
        job=copy.deepcopy(job), job_store=job_store, linked_target=linked_target,
        gui_admission=(owner, config_store.mode_port(captured)),
    )
