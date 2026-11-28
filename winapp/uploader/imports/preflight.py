# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/imports/preflight.py
# Module Type: Windows Import Worker
# Purpose: Validate manual import selections and construct a bounded upload plan.
# Responsibilities:
#   - Inspect selected files and ZIP archives before durable job admission.
#   - Resolve exact connection and local source evidence for one import plan.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own import preflight and its background planning worker."""

import json
import logging
import os
import threading
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib import parse

from .. import http_transport
from ..discovery import (
    ArchiveLimits,
    discover_folder,
    discover_selected_files,
    stage_zip_archive,
)
from ..flight_jobs import connection_binding
from ..mode_coordinator import GuiModeOwner, ModeConfigPort
from ..linked_upload import LinkedTargetLease, LinkedUploadTarget, UploadPipelineLease
from ..localization import gettext_noop
from ..media import capability_notes, detect_suffix_capabilities, probe_file
from ..models import ImportItem, ImportJob, ImportPlan, LINKED_UPLOAD_UNCERTAIN_REASON
from ..state_store import JobStateStore
from ..watcher.queue import observe_watch_source
from ..watcher import runtime, state as watcher_state
from ..http_transport import sha256_file

class ImportPreflightThread(threading.Thread):
    """
    Background discovery and duplicate/capability preflight for manual imports.

    The thread performs potentially expensive ZIP inspection, hashing, decoder
    probes, and remote inventory checks without blocking Tkinter. It never
    uploads image content. The user must explicitly start the prepared job.
    """

    def __init__(
        self,
        config: watcher_state.WatcherConfig,
        source_kind: str,
        source_paths: List[Path],
        source_folder: Optional[Path],
        source_zip: Optional[Path],
        recursive: bool,
        delete_source_zip_after_success: bool,
        events: "queue.Queue[Any]",
        job_store: JobStateStore,
        linked_target: Optional[LinkedUploadTarget] = None,
        gui_admission: Optional[Tuple[GuiModeOwner, ModeConfigPort]] = None,
    ) -> None:
        """
        Create one preflight worker for the selected source.

        Args:
            config (WatcherConfig): Connection and archive-safety settings.
            source_kind (str): files, folder, or zip.
            source_paths (List[Path]): Explicit file selection for files mode.
            source_folder (Optional[Path]): Selected folder for folder mode.
            source_zip (Optional[Path]): Selected ZIP archive for zip mode.
            recursive (bool): Whether folder discovery includes subfolders.
            delete_source_zip_after_success (bool): Whether a successful ZIP
                import may delete its original archive.
            events (queue.Queue[Any]): Thread-safe UI event queue.
            job_store (JobStateStore): Durable job history store.
            linked_target (Optional[LinkedUploadTarget]): Explicitly selected
                delegated gallery, or None for the legacy API-key connection.
            gui_admission (Optional[Tuple[GuiModeOwner, ModeConfigPort]]): GUI
                owner and exact immutable preflight configuration port.

        Returns:
            None: Create one preflight worker for the selected source.
        """
        super().__init__(daemon=True)
        self.config = config
        self.source_kind = source_kind
        self.source_paths = list(source_paths)
        self.source_folder = source_folder
        self.source_zip = source_zip
        self.recursive = recursive
        self.delete_source_zip_after_success = delete_source_zip_after_success
        self.events = events
        self.job_store = job_store
        self.linked_target = linked_target
        self._gui_admission = gui_admission
        self._gui_work: Optional[CoordinatedWork] = None
        self._preflight_owner = object()
        self._linked_lease = (LinkedTargetLease(watcher_state.CONFIG_DIR, linked_target.binding())
                              if linked_target is not None else None)
        self.stop_event = threading.Event()

    def stop(self) -> None:
        """Request cancellation of discovery before it publishes a plan."""
        self.stop_event.set()

    def emit(self, kind: str, payload: Dict[str, Any]) -> None:
        """Send one structured preflight event to the Tkinter thread.

        Args:
            kind (str): Preflight event name identifying the payload variant.
            payload (Dict[str, Any]): Event fields such as job_id, message,
                current and total counts, filename, or the completed ImportPlan.

        Returns:
            None: Enqueues the event with its captured linked destination.
        """
        event_payload = dict(payload)
        # Opaque generations and owner tickets never contain credentials.
        event_payload['config_generation'] = self.config._snapshot_token
        event_payload['preflight_owner'] = self._preflight_owner
        event_payload['linked_binding'] = (
            self.linked_target.binding() if self.linked_target is not None else None
        )
        event_payload['preflight_binding'] = (
            self.linked_target.binding() if self.linked_target is not None else
            connection_binding(http_transport.normalize_upload_url(self.config.gallery_url), self.config.api_key)
        )
        self.events.put({"kind": kind, "payload": event_payload})

    def run(self) -> None:
        """Build a preflight plan and publish current gallery metadata.

        Returns:
            None: Saves the plan and emits connection metadata or a warning;
                no source media is uploaded.
        """
        pipeline = UploadPipelineLease(watcher_state.CONFIG_DIR)
        try:
            pipeline.acquire()
            if self._gui_admission is not None:
                owner, port = self._gui_admission
                kind = 'linked' if self.linked_target is not None else 'legacy'
                self._gui_work = owner.acquire(kind, port)
            if self._linked_lease is not None:
                self._linked_lease.acquire()
            self._run_owned()
        except (OSError, ValueError, RuntimeError) as exc:
            self.emit('preflight_failed', {'message': str(exc)})
        finally:
            pipeline.release()
            if self._gui_work is not None:
                self._gui_work.close()
            if self._linked_lease is not None:
                self._linked_lease.release()

    def _run_owned(self) -> None:
        """Inspect sources while holding the target lease.

        Returns:
            None: Emits a plan excluding retained unknown sources, or a
                diagnostic when the gallery still needs review.
        """
        upload_url = (self.linked_target.issuer if self.linked_target is not None
                      else http_transport.normalize_upload_url(self.config.gallery_url))
        parsed = parse.urlparse(upload_url) if upload_url else None
        target_label = parsed.netloc if parsed and parsed.netloc else "Gallery connection not configured"
        if parsed and parsed.path and parsed.path not in {"", "/"}:
            target_label += parsed.path
        if self.linked_target is not None:
            target_label = f'{self.linked_target.title} (#{self.linked_target.gallery_id})'

        if self.source_kind == "files":
            source_label = f"{len(self.source_paths)} selected file(s)"
        elif self.source_kind == "folder":
            source_label = str(self.source_folder or "")
        else:
            source_label = str(self.source_zip or "")
        job = ImportJob.create(self.source_kind, source_label, target_label)
        self.emit("preflight_started", {"job_id": job.id, "source_label": source_label})

        try:
            linked_state = (watcher_state.LinkedUploadState(self.linked_target)
                            if self.linked_target is not None else None)
        except (OSError, ValueError):
            self.emit('preflight_failed', {
                'message': gettext_noop('Linked gallery recovery state is unavailable.'),
                'job_id': job.id,
            })
            return
        if linked_state is not None and linked_state.has_unresolved_upload():
            self.emit('preflight_failed', {
                'message': LINKED_UPLOAD_UNCERTAIN_REASON, 'job_id': job.id,
            })
            return

        limits = ArchiveLimits(
            max_entries=self.config.archive_max_entries,
            max_uncompressed_bytes=self.config.archive_max_uncompressed_bytes,
            max_file_bytes=self.config.archive_max_file_bytes,
            max_compression_ratio=self.config.archive_max_compression_ratio,
        )
        try:
            if self.source_kind == "files":
                discovery = discover_selected_files(self.source_paths, watcher_state.SUPPORTED_SUFFIXES)
            elif self.source_kind == "folder":
                if self.source_folder is None:
                    raise ValueError("No source folder was selected.")
                discovery = discover_folder(self.source_folder, watcher_state.SUPPORTED_SUFFIXES, self.recursive)
            elif self.source_kind == "zip":
                if self.source_zip is None:
                    raise ValueError("No ZIP archive was selected.")
                discovery = stage_zip_archive(self.source_zip, watcher_state.STAGING_ROOT, job.id, watcher_state.SUPPORTED_SUFFIXES, limits)
                job.staging_dir = discovery.staging_dir
                job.source_zip = str(self.source_zip)
                job.delete_source_zip_after_success = (
                    self.delete_source_zip_after_success and self.linked_target is None
                )
            else:
                raise ValueError("Unknown import source kind: " + self.source_kind)
        except Exception as exc:  # noqa: BLE001
            self.emit("preflight_failed", {"message": str(exc), "job_id": job.id})
            return

        if self.stop_event.is_set():
            # A cancelled preflight cannot prove staged sources were never
            # accepted by an older or concurrent uploader. Keep the bytes.
            self.emit("preflight_cancelled", {
                "job_id": job.id,
                "staging_dir": discovery.staging_dir,
            })
            return

        def chronological_source(item: ImportItem) -> Tuple[float, str]:
            """Choose documented capture evidence for a new local admission batch.

            Args:
                item (ImportItem): Discovered source with a path and original label.

            Returns:
                Tuple[float, str]: Capture/birth/observation clock and deterministic path tie.
            """
            try:
                observed = observe_watch_source(Path(item.path), time.time())
                return observed.captured_at, os.path.normcase(item.path)
            except OSError:
                # Local validation below reports an unreadable unaccepted input;
                # this sentinel never permits a queued item to overtake a head.
                return float('inf'), os.path.normcase(item.path)

        discovery.items.sort(key=chronological_source)
        generic_capabilities = detect_suffix_capabilities(watcher_state.SUPPORTED_SUFFIXES, runtime.Image)
        notes = capability_notes(generic_capabilities)
        valid_items: List[ImportItem] = []
        local_duplicates = 0
        retained_unknown = 0
        candidates: List[Dict[str, Any]] = []
        item_by_hash: Dict[str, List[ImportItem]] = {}
        total = max(1, len(discovery.items))
        for index, item in enumerate(discovery.items, start=1):
            if self.stop_event.is_set():
                # A cancelled preflight cannot prove staged sources were never
                # accepted by an older or concurrent uploader. Keep the bytes.
                self.emit("preflight_cancelled", {
                    "job_id": job.id,
                    "staging_dir": discovery.staging_dir,
                })
                return
            path = Path(item.path)
            self.emit("preflight_progress", {"current": index, "total": total, "filename": path.name})
            try:
                item.transition("hashing")
                item.sha256 = sha256_file(path)
                item.size = int(path.stat().st_size)
            except OSError as exc:
                bucket = "unreadable source"
                discovery.unsupported[bucket] = discovery.unsupported.get(bucket, 0) + 1
                discovery.warnings.append(f"{item.source_label}: cannot be read: {exc}")
                continue
            if (linked_state is not None
                    and linked_state.has_uncertain_source(path, item.sha256)):
                # Keep this outside the newly accepted job. Marking an
                # unbound item terminal would fail its first binding admission.
                retained_unknown += 1
                discovery.warnings.append(
                    f'Kept {item.source_label}; its prior upload remains uncertain.',
                )
                continue

            suffix = path.suffix.lower()
            if suffix in {".heic", ".heif", ".dng"}:
                capability = probe_file(path, watcher_state.SUPPORTED_SUFFIXES, runtime.Image)
            else:
                capability = generic_capabilities.get(suffix)
            if capability is None or not capability.local_previewable:
                item.local_decode = "server_only"
            else:
                item.local_decode = "local"

            item.transition("queued")
            candidate = http_transport.inventory_candidate(path, item.sha256, item.size)
            candidates.append(candidate)
            item_by_hash.setdefault(item.sha256, []).append(item)
            valid_items.append(item)

        job.items = valid_items
        job.bytes_total = sum(max(0, item.size) for item in valid_items)
        job.skipped = (sum(discovery.unsupported.values())
                       + len(discovery.unsafe_entries) + retained_unknown)

        remote_duplicates = 0
        gallery_label = target_label
        # This preview is deliberately local: checking B before A has a durable
        # disposition would start B's transfer workflow. The shared FIFO
        # consumer performs each current head's authoritative inventory later.
        discovery.warnings.append(
            'Remote duplicates and authorization are checked for each FIFO head during import.',
        )

        plan = ImportPlan(
            job=job,
            unsupported=discovery.unsupported,
            unsafe_entries=discovery.unsafe_entries,
            duplicate_local=local_duplicates,
            duplicate_remote=remote_duplicates,
            capability_notes=notes,
            warnings=discovery.warnings,
        )
        self.job_store.save_job(job)
        self.emit("preflight_complete", {"plan": plan, "gallery_label": gallery_label})
