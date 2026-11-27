# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/import_commands.py
# Module Type: Manual import application service
# Purpose: Validate explicit import admission and retry decisions.
# Responsibilities: Match the exact saved gallery and reviewed job, protecting
#   accepted originals and unknown request outcomes before worker startup.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Apply manual-import admission and recovery policy before dispatch."""

from dataclasses import dataclass, field
from pathlib import Path
from queue import Queue
import re
from typing import Callable, Mapping, Protocol

from .flight_jobs import ImportBindings
from .import_ui_snapshot import ImportProgressSnapshot, ImportWorkerGeneration
from .localization import gettext_noop
from .media import MediaCapability
from .models import (
    LEGACY_UPLOAD_UNCERTAIN_REASON, LINKED_UPLOAD_UNCERTAIN_REASON,
    RECOVERABLE_ITEM_STATES, ImportJob,
)
from .state_store import JobStateStore, accepted_work_owner

ImportApproval = tuple[str, object | None, str]
ImportEventPayload = dict[str, object]
ImportEvents = Queue[dict[str, str | ImportEventPayload] | tuple[str, str]]


@dataclass(frozen=True)
class ImportSource:
    """Identify the native picker's explicit files, folder or ZIP input."""

    files: tuple[Path, ...] = ()
    folder: Path | None = None
    archive: Path | None = None
    recursive: bool = False

    def kind(self) -> str:
        """Determine the exact source shape before launching discovery.

        Returns:
            str: One of files, folder or zip, for the shared preflight worker.
        Raises:
            ValueError: No source or multiple source kinds were supplied.
        """
        if sum((bool(self.files), self.folder is not None, self.archive is not None)) != 1:
            raise ValueError('Choose a source and complete preflight first.')
        return 'files' if self.files else 'folder' if self.folder is not None else 'zip'


class PreflightWorkerPort(Protocol):
    """Worker lifetime for shared discovery, archive staging and preflight."""

    def start(self) -> None:
        """Start independently of the GUI thread.

        Returns:
            None: Discovery owns its lifetime until the worker exits.
        """

    def stop(self) -> None:
        """Request cancellation at the shared discovery checkpoint.

        Returns:
            None: The worker keeps its lease until actual teardown.
        """

    def is_alive(self) -> bool:
        """Read the actual owned background lifetime.

        Returns:
            bool: Whether the worker has started and has yet to finish.
        """


class ImportWorkerPort(PreflightWorkerPort, Protocol):
    """Additional lifecycle signals exposed by the shared upload worker."""

    import_job_id: str
    import_generation: ImportWorkerGeneration

    def set_paused(self, paused: bool) -> None:
        """Gate new requests without writing back into the caller's event queue.

        Args:
            paused (bool): Whether the worker should stop accepting new requests.
        Returns:
            None: Changes submission only, leaving in-flight actions owned.
        """

    def latest_import_projection(self) -> ImportProgressSnapshot | None:
        """Read the newest full projection if intermediate rows were coalesced.

        Returns:
            ImportProgressSnapshot | None: Bounded full snapshot, or None before a save.
        """


@dataclass(frozen=True)
class ImportActions:
    """Bind native Import commands and current runtime review capabilities.

    Attributes:
        prepare (Callable[[ImportSource, ImportEvents], PreflightWorkerPort]):
            Create the shared source discovery worker.
        admit (Callable[[ImportJob, ImportApproval, bool, ImportEvents], ImportWorkerPort]):
            Admit the exact reviewed job and selected thumbnail mode.
        retry (Callable[[str, bool, ImportEvents], tuple[ImportWorkerPort | None, int]]):
            Prepare explicit recovery for one selected durable job.
        retire (Callable[[str], None]): Safely remove an unbound preflight draft.
        use_local_thumbnails (bool): Existing saved client-thumbnail preference.
        local_thumbnail_available (bool): Current process can create client
            derivatives; false requires the server choice.
        media_capabilities (Mapping[str, MediaCapability]): Current process's
            suffix decoder observations for preflight review text.
    """

    prepare: Callable[[ImportSource, ImportEvents], PreflightWorkerPort]
    admit: Callable[[ImportJob, ImportApproval, bool, ImportEvents], ImportWorkerPort]
    retry: Callable[[str, bool, ImportEvents], tuple[ImportWorkerPort | None, int]]
    retire: Callable[[str], None]
    use_local_thumbnails: bool
    local_thumbnail_available: bool = False
    media_capabilities: Mapping[str, MediaCapability] = field(default_factory=dict)


def import_approval(job_id: str, generation: object, binding: str) -> ImportApproval:
    """Capture a preflight proof without accepting a malformed destination.

    Args:
        job_id (str): Persisted plan identifier received from its worker.
        generation (object{}): Exact opaque config token, compared by identity.
        binding (str): Worker-proven destination fingerprint.
    Returns:
        ImportApproval: Current proof to recheck at worker admission.
    Raises:
        ValueError: The job, generation or binding cannot be verified.
    """
    if not job_id or generation is None or re.fullmatch(r'[0-9a-f]{64}', binding) is None:
        raise ValueError('Preflight needs to be run again')
    return (job_id, generation, binding)


@dataclass(frozen=True)
class ImportRetry:
    """Carry the retryable originals and the number of unknown results left behind."""

    paths: tuple[Path, ...]
    uncertain: int


def admit_reviewed_import(
    store: JobStateStore, bindings: ImportBindings, job: ImportJob, *,
    binding: str, generation: object | None, approval: ImportApproval | None,
) -> None:
    """Commit the existing job and exact binding after explicit operator review.

    Args:
        store (JobStateStore): State owner that holds the persisted preflight.
        bindings (ImportBindings): Accepted-work ledger from the same profile.
        job (ImportJob): Current independently owned job to admit.
        binding (str): Fingerprint of the captured current upload connection.
        generation (object{} | None): Opaque current config token or no loaded config.
        approval (ImportApproval | None): Reviewed job ID, config generation and
            gallery binding, or None for an already dispatched durable retry.
    Returns:
        None: The binding and job have both been published and verified.
    Raises:
        ValueError: The review is stale or the durable evidence cannot be used.
        OSError: The admission evidence could not be read or written.
        RuntimeError: The accepted-work lock or readback could not be verified.
    """
    entries = bindings.entries()
    if job.started_at <= 0:
        if approval != (job.id, generation, binding):
            raise ValueError('Configuration changed or preflight is unverified; run it again.')
    elif (entries.get(job.id) != binding
          or any(item.state == 'skipped_duplicate' for item in job.items)):
        raise ValueError('This import needs a new preflight before recovery.')
    bindings.admit_job(store, job, binding)


def select_retry_sources(store: JobStateStore, job: ImportJob) -> ImportRetry:
    """Select explicit retry paths after verification of the original binding.

    Args:
        store (JobStateStore): Existing job ledger for this verified job.
        job (ImportJob): Freshly read job, privately owned by the retry command.
    Returns:
        ImportRetry: Available originals and the unknown outcomes excluded from retry.
    Raises:
        OSError: Updated missing-source evidence could not be persisted.
        ValueError: An item transition or the resulting evidence is invalid.
        RuntimeError: Saving the updated job could not be confirmed.
    """
    paths: list[Path] = []
    missing = 0
    uncertain = 0
    for item in job.items:
        if item.state not in RECOVERABLE_ITEM_STATES and item.state != 'failed_permanent':
            continue
        if item.reason in (LINKED_UPLOAD_UNCERTAIN_REASON, LEGACY_UPLOAD_UNCERTAIN_REASON):
            uncertain += 1
            continue
        path = Path(item.path)
        if not path.is_file():
            item.transition('failed_permanent', 'Local source is no longer available for retry.')
            missing += 1
            continue
        if item.state == 'failed_permanent':
            item.transition('failed_retryable', 'Explicit user retry requested.')
        paths.append(path)
    if missing:
        store.save_job(job)
    return ImportRetry(tuple(paths), uncertain)


def read_verified_import_retry(
    store: JobStateStore, bindings: ImportBindings, job_id: str, *,
    binding: str, generation: object | None, approval: ImportApproval | None,
) -> ImportJob:
    """Read retry evidence for this exact selected job and current connection.

    Args:
        store (JobStateStore): Durable ledger owner in the admitted desktop profile.
        bindings (ImportBindings): Original target binding ledger for this profile.
        job_id (str): Stable selected history identifier.
        binding (str): Current connection fingerprint.
        generation (object{} | None): Exact opaque config token or no loaded config.
        approval (ImportApproval | None): Current reviewed unstarted plan proof.
    Returns:
        ImportJob: Independently read job with verified original target evidence.
    Raises:
        OSError: Existing accepted evidence is unavailable.
        ValueError: Selection, duplicate evidence or original target was unverified.
        RuntimeError: The accepted-work owner could not be verified.
    """
    with accepted_work_owner(store.path.parent):
        jobs = store.inspect_jobs_for_target_change()
        job = next((row for row in jobs if row.id == job_id), None)
        if job is None:
            raise ValueError('Accepted import history is missing; review it.')
        entries = bindings.entries()
    if any(item.state == 'skipped_duplicate' for item in job.items):
        raise ValueError(gettext_noop(
            'Earlier duplicate results need a new preflight. Original sources were retained.',
        ))
    if job.started_at <= 0:
        if approval != (job.id, generation, binding):
            raise ValueError('This import needs a new preflight before recovery.')
    elif entries.get(job.id) != binding:
        raise ValueError('This import has no verified current gallery binding.')
    return job
