# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/import_history_reader.py
# Module Type: Windows Tooling Read Model Worker
# Purpose: Read import history and selected jobs off the Tk thread.
# Responsibilities:
#   - Coalesce history and detail requests in independent latest-wins lanes.
#   - Publish immutable bounded projections without calling Tk from the worker.
#   - Transfer selected durable jobs exclusively for existing review/retry flows.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Provide a bounded latest-wins background reader for import UI data."""

from dataclasses import dataclass
from threading import Condition, Thread
from typing import Literal

from .import_ui_snapshot import (
    ImportHistorySummary,
    ImportProgressSnapshot,
    ImportWorkerGeneration,
)
from .models import ImportJob
from .state_store import JobStateStore

ReadKind = Literal["history", "detail"]


def read_import_detail(
    store: JobStateStore, job_id: str, item_row_limit: int,
    generation: ImportWorkerGeneration, revision: int,
) -> tuple[ImportJob | None, ImportProgressSnapshot | None]:
    """Read and project one exact durable job for either desktop frontend.

    Args:
        store (JobStateStore): Existing safe state reader for this profile.
        job_id (str): Exact selected durable import job identity.
        item_row_limit (int): Caller-owned view cap for accepted item rows.
        generation (ImportWorkerGeneration): Opaque selection identity.
        revision (int): Current request revision for this generation.
    Returns:
        tuple[ImportJob | None, ImportProgressSnapshot | None]: Independent
            job copy and immutable bounded detail, or two None values if absent.
    """
    job = store.get_job(job_id)
    if job is None:
        return None, None
    return job, ImportProgressSnapshot.from_job(job, generation, revision, item_row_limit)


@dataclass(frozen=True, slots=True)
class ImportHistoryReadResult:
    """Carry one latest history or detail read back to the event-pump owner.

    Args:
        kind (ReadKind): Independent history-list or selected-detail lane.
        ticket (int): Monotonic reader request identity within this instance.
        history (tuple[ImportHistorySummary, ...]): Bounded newest-first history rows.
        snapshot (ImportProgressSnapshot | None): Frozen detail projection when found.
        job (ImportJob | None): Exclusive read copy transferred for existing review.
        error (str | None): Read failure summary, or None on success.
    """

    kind: ReadKind
    ticket: int
    history: tuple[ImportHistorySummary, ...] = ()
    snapshot: ImportProgressSnapshot | None = None
    job: ImportJob | None = None
    error: str | None = None


@dataclass(frozen=True, slots=True)
class _HistoryRequest:
    """Represent one bounded history-list request in the reader queue."""

    ticket: int
    limit: int
    kind: Literal["history"] = "history"


@dataclass(frozen=True, slots=True)
class _DetailRequest:
    """Represent one exact selected-job request in the reader queue."""

    ticket: int
    job_id: str
    item_row_limit: int
    generation: ImportWorkerGeneration
    kind: Literal["detail"] = "detail"


class ImportHistoryReader:
    """Own one background thread and two bounded latest-wins read lanes.

    Each lane retains at most one pending request and one unread result. One
    request may be active while newer requests replace pending work. A result
    is published only while its ticket remains newest for that lane.
    """

    def __init__(self, store: JobStateStore) -> None:
        """Start an isolated reader thread for the supplied safe store owner.

        Args:
            store (JobStateStore): Existing state owner used for read-only calls.

        Returns:
            None: The history and detail lanes are ready for requests.
        """
        self._store = store
        self._condition = Condition()
        self._pending_history: _HistoryRequest | None = None
        self._pending_detail: _DetailRequest | None = None
        self._latest_tickets: dict[ReadKind, int] = {
            "history": 0,
            "detail": 0,
        }
        self._results: dict[ReadKind, ImportHistoryReadResult] = {}
        self._next_ticket = 0
        self._closed = False
        self._active = False
        self._thread = Thread(
            target=self._read_loop,
            name="PHPGalleryImportHistoryReader",
            daemon=True,
        )
        self._thread.start()

    def request_history(self, limit: int) -> int:
        """Request a bounded newest-first history list off the Tk thread.

        Args:
            limit (int): Maximum number of summary rows to return.

        Returns:
            int: Ticket used by the caller to reject stale event-pump results.

        Raises:
            ValueError: The limit is negative or the reader is closed.
        """
        if limit < 0:
            raise ValueError("Import history limit cannot be negative.")
        with self._condition:
            self._ensure_open()
            ticket = self._new_ticket("history")
            self._pending_history = _HistoryRequest(ticket, limit)
            self._condition.notify()
            return ticket

    def request_job(
        self,
        job_id: str,
        item_row_limit: int,
        generation: ImportWorkerGeneration,
    ) -> int:
        """Request exact historical detail and a frozen bounded projection.

        The returned job is an independent read object transferred to the UI
        owner for compatibility with existing review and retry planning. The
        reader does not mutate it and drops its own references after publish.

        Args:
            job_id (str): Exact durable job ID selected by the user.
            item_row_limit (int): Caller-owned cap for visible item projections.
            generation (ImportWorkerGeneration): Selection generation to fence detail.

        Returns:
            int: Ticket used by the caller to discard stale selection results.

        Raises:
            ValueError: Inputs are invalid or the reader is closed.
        """
        if not job_id:
            raise ValueError("Import job ID cannot be empty.")
        if item_row_limit < 0:
            raise ValueError("Import item row cap cannot be negative.")
        with self._condition:
            self._ensure_open()
            ticket = self._new_ticket("detail")
            self._pending_detail = _DetailRequest(
                ticket, job_id, item_row_limit, generation,
            )
            self._condition.notify()
            return ticket

    def poll_ready(self) -> tuple[ImportHistoryReadResult, ...]:
        """Take currently published lane results without blocking the caller.

        Returns:
            tuple[ImportHistoryReadResult, ...]: Available latest results sorted
                by ticket; the caller owns any transferred detail job objects.
        """
        with self._condition:
            available = tuple(sorted(
                self._results.values(), key=lambda result: result.ticket,
            ))
            self._results.clear()
            return available

    def has_work(self) -> bool:
        """Report pending, active or unread work for event-pump cadence.

        Returns:
            bool: True while a request or published lane result remains.
        """
        with self._condition:
            return bool(
                not self._closed
                and (
                    self._active
                    or self._pending_history is not None
                    or self._pending_detail is not None
                    or self._results
                )
            )

    def invalidate_detail(self) -> int:
        """Fence pending or active detail reads after selection is cleared.

        Returns:
            int: New detail ticket; older in-flight results will be discarded.

        Raises:
            ValueError: The reader is closed.
        """
        with self._condition:
            self._ensure_open()
            ticket = self._new_ticket("detail")
            self._pending_detail = None
            return ticket

    def close(self) -> None:
        """Discard pending work and request non-blocking reader shutdown.

        Returns:
            None: An active safe read may finish, but no later result is published.
        """
        with self._condition:
            if self._closed:
                return
            self._closed = True
            self._pending_history = None
            self._pending_detail = None
            self._results.clear()
            self._condition.notify_all()

    def wait_closed(self, timeout_seconds: float) -> bool:
        """Wait for the daemon reader to release any active state read.

        Args:
            timeout_seconds (float): Caller-owned upper bound for teardown wait.

        Returns:
            bool: True when the background reader thread has exited.
        """
        self._thread.join(timeout_seconds)
        return not self._thread.is_alive()

    def _new_ticket(self, kind: ReadKind) -> int:
        """Advance a request ticket and invalidate an unread result in its lane.

        Args:
            kind (ReadKind): Lane whose newest request is being replaced.

        Returns:
            int: New monotonic reader ticket.
        """
        self._next_ticket += 1
        ticket = self._next_ticket
        self._latest_tickets[kind] = ticket
        self._results.pop(kind, None)
        return ticket

    def _ensure_open(self) -> None:
        """Reject new requests after shutdown begins.

        Returns:
            None: Returns only while the reader accepts work.

        Raises:
            ValueError: The reader has been closed.
        """
        if self._closed:
            raise ValueError("Import history reader is closed.")

    def _read_loop(self) -> None:
        """Consume at most one active request and publish only current tickets.

        Returns:
            None: Exits after close or continues while the lanes have work.
        """
        while True:
            with self._condition:
                while (not self._closed and self._pending_history is None
                       and self._pending_detail is None):
                    self._condition.wait()
                if self._closed:
                    return
                request = self._take_next_request()
                self._active = True
            self._perform_and_publish(request)

    def _perform_and_publish(
        self, request: _HistoryRequest | _DetailRequest,
    ) -> None:
        """Run one request and release worker-owned model references afterward.

        Args:
            request (_HistoryRequest | _DetailRequest): One active lane request.

        Returns:
            None: A current result is published, or a stale result is discarded.
        """
        result = self._read_request(request)
        with self._condition:
            self._active = False
            if (not self._closed
                    and request.ticket == self._latest_tickets[request.kind]):
                self._results[request.kind] = result

    def _take_next_request(self) -> _HistoryRequest | _DetailRequest:
        """Remove the oldest pending request while the condition is held.

        Returns:
            _HistoryRequest | _DetailRequest: One request to perform off-thread.
        """
        history = self._pending_history
        detail = self._pending_detail
        if history is not None and (detail is None or history.ticket < detail.ticket):
            self._pending_history = None
            return history
        if detail is not None:
            self._pending_detail = None
            return detail
        raise RuntimeError("Reader woke without pending work.")

    def _read_request(
        self, request: _HistoryRequest | _DetailRequest,
    ) -> ImportHistoryReadResult:
        """Read one request and convert failures to a value for the UI pump.

        Args:
            request (_HistoryRequest | _DetailRequest): Work selected by the reader.

        Returns:
            ImportHistoryReadResult: Successful projection or typed text failure.
        """
        try:
            if isinstance(request, _HistoryRequest):
                history = tuple(self._store.list_job_history(request.limit))
                return ImportHistoryReadResult(
                    kind="history", ticket=request.ticket, history=history,
                )
            job, snapshot = read_import_detail(
                self._store, request.job_id, request.item_row_limit,
                request.generation, request.ticket,
            )
            return ImportHistoryReadResult(
                kind="detail", ticket=request.ticket, snapshot=snapshot, job=job,
            )
        except Exception as error:
            return ImportHistoryReadResult(
                kind=request.kind,
                ticket=request.ticket,
                error=f"{type(error).__name__}: {error}",
            )
