# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/import_ui_snapshot.py
# Module Type: Windows Tooling Presentation Model
# Purpose: Project durable import jobs into immutable, revisioned UI snapshots.
# Responsibilities:
#   - Copy progress counters and bounded stable-row projections from a saved job.
#   - Bind snapshots to an opaque worker generation and ordered revision number.
#   - Keep worker-to-UI payloads independent of mutable import model instances.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Build immutable presentation snapshots for durable manual-import jobs."""

from dataclasses import dataclass
import time
from pathlib import Path
from typing import NewType
from uuid import UUID, uuid4

from .models import ImportItem, ImportJob

ImportWorkerGeneration = NewType("ImportWorkerGeneration", UUID)


def new_worker_generation() -> ImportWorkerGeneration:
    """Create a process-local opaque token for one import worker lifetime.

    Returns:
        ImportWorkerGeneration: Unique identity used to fence worker snapshots.
    """
    return ImportWorkerGeneration(uuid4())


@dataclass(frozen=True, slots=True)
class ImportItemView:
    """Copy the visible import row fields without retaining its mutable model.

    Args:
        source_index (int): Stable position in the accepted job item ordering.
        item_id (str): Stable durable item identifier used as the Treeview IID.
        filename (str): Display filename derived from the source label.
        size_bytes (int): Source size in bytes for caller-owned formatting.
        local_decode (str): Local decode capability label shown in Import.
        state (str): Current item status shown in both import tables.
        reason (str): Current review, skip or failure detail.
        attempt_count (int): Durable upload attempt count shown in Activity.
        activity_detail (str): Reason or compact server receipt detail.
    """

    source_index: int
    item_id: str
    filename: str
    size_bytes: int
    local_decode: str
    state: str
    reason: str
    attempt_count: int
    activity_detail: str

    def item_values(self, size_label: str) -> tuple[str | int | float, ...]:
        """Return Import Treeview values in their established column order.

        Args:
            size_label (str): Byte count formatted by the existing UI owner.

        Returns:
            tuple[str | int | float, ...]: Filename, size, decode, state and
                reason values for the stable item row.
        """
        return (
            self.filename,
            size_label,
            self.local_decode,
            self.state,
            self.reason,
        )

    def activity_values(self) -> tuple[str | int | float, ...]:
        """Return item Activity values in their established column order.

        Returns:
            tuple[str | int | float, ...]: Filename, state, attempts and detail.
        """
        return (
            self.filename,
            self.state,
            self.attempt_count,
            self.activity_detail,
        )


@dataclass(frozen=True, slots=True)
class ImportProgressCounts:
    """Store the status counts consumed by progress and history surfaces.

    Args:
        uploaded (int): Items confirmed by the server.
        duplicates (int): Items already present in the target gallery.
        failed (int): Retryable and permanent failed items.
        cancelled (int): Items cancelled before completion.
        active (int): Items in a nonterminal import state.
        skipped (int): Items excluded during preflight.
    """

    uploaded: int
    duplicates: int
    failed: int
    cancelled: int
    active: int
    skipped: int

    def history_status(self) -> str:
        """Format the existing compact history-row status summary.

        Returns:
            str: Counts in the current history table's established order.
        """
        return (
            f"uploaded {self.uploaded}, duplicate {self.duplicates}, "
            f"failed {self.failed}, cancelled {self.cancelled}, "
            f"active {self.active}"
        )


@dataclass(frozen=True, slots=True)
class ImportHistorySummary:
    """Copy the fields used by one stable import-history row.

    Args:
        job_id (str): Stable durable job identifier and Treeview IID.
        source_kind (str): files, folder or zip source classification.
        created_at (float): Unix timestamp formatted by the UI owner.
        source_label (str): Human-readable source description.
        target_gallery_label (str): Non-secret target gallery description.
        status (str): Compact uploaded, duplicate, failed, cancelled and active counts.
        created_label (str): Optional local-time cell prepared off the Tk thread.
    """

    job_id: str
    source_kind: str
    created_at: float
    source_label: str
    target_gallery_label: str
    status: str
    created_label: str = ""

    def row_values(
        self, created_label: str | None = None,
    ) -> tuple[str | int | float, ...]:
        """Return history values in their established column order.

        Args:
            created_label (str | None): Optional existing UI timestamp label;
                None uses the off-thread label when available.

        Returns:
            tuple[str | int | float, ...]: Created, source, target and status.
        """
        return (
            self.created_label if created_label is None else created_label,
            self.source_label,
            self.target_gallery_label,
            self.status,
        )


@dataclass(frozen=True, slots=True)
class ImportProgressSnapshot:
    """Bind copied progress fields and row projections to one worker revision.

    Args:
        generation (ImportWorkerGeneration): Opaque worker-lifetime identity.
        revision (int): Monotonically assigned revision within that generation.
        source_kind (str): files, folder or zip source classification.
        counts (ImportProgressCounts): Immutable state counts from the saved job.
        bytes_total (int): Accepted source byte total.
        bytes_sent (int): Durably confirmed source bytes.
        started_at (float): First dispatch timestamp retained by the job.
        finished_at (float): Terminal completion timestamp, or zero while active.
        item_total (int): Accepted ImportItem count before row projection.
        skipped_total (int): Number of items excluded during preflight.
        item_row_limit (int): UI-owned maximum accepted-item rows in the projection.
        items (tuple[ImportItemView, ...]): Ordered full or dirty item projections.
        complete_rows (bool): Whether items contains all rows through the cap.
        retry_available (bool): Whether an item is in an explicitly retryable state.
        history (ImportHistorySummary): Cheap summary for this job's history row.
    """

    generation: ImportWorkerGeneration
    revision: int
    source_kind: str
    counts: ImportProgressCounts
    bytes_total: int
    bytes_sent: int
    started_at: float
    finished_at: float
    item_total: int
    skipped_total: int
    item_row_limit: int
    items: tuple[ImportItemView, ...]
    complete_rows: bool
    retry_available: bool
    history: ImportHistorySummary

    @classmethod
    def from_job(
        cls,
        job: ImportJob,
        generation: ImportWorkerGeneration,
        revision: int,
        item_row_limit: int,
        dirty_item_indices: frozenset[int] | None = None,
    ) -> "ImportProgressSnapshot":
        """Copy one already-persisted job while its mutation lock is held.

        The caller assigns the next revision and invokes this only after the
        corresponding durable save succeeds. No mutable job or item reference
        is retained by the returned snapshot.

        Args:
            job (ImportJob): Mutable job protected by the worker's mutation lock.
            generation (ImportWorkerGeneration): Opaque token for this worker.
            revision (int): Caller-assigned nonnegative revision in this generation.
            item_row_limit (int): Existing UI owner cap for visible item rows.
            dirty_item_indices (frozenset[int] | None): Optional accepted-item
                indices to project; None requests the complete bounded prefix.

        Returns:
            ImportProgressSnapshot: Immutable copied counters, summary and rows.

        Raises:
            ValueError: Revision or item-row cap is negative.
        """
        if revision < 0:
            raise ValueError("Import snapshot revision cannot be negative.")
        if item_row_limit < 0:
            raise ValueError("Import item row cap cannot be negative.")

        raw_counts = job.counts()
        counts = ImportProgressCounts(
            uploaded=raw_counts["uploaded"],
            duplicates=raw_counts["duplicates"],
            failed=raw_counts["failed"],
            cancelled=raw_counts["cancelled"],
            active=raw_counts["active"],
            skipped=raw_counts["skipped"],
        )
        retry_available = bool(
            counts.active or counts.failed or counts.cancelled
        )
        bounded_end = min(len(job.items), item_row_limit)
        if dirty_item_indices is None:
            selected_indices = range(bounded_end)
            complete_rows = True
        else:
            selected_indices = sorted(
                index for index in dirty_item_indices
                if 0 <= index < bounded_end
            )
            complete_rows = False

        item_views = tuple(
            cls._item_view(index, job.items[index])
            for index in selected_indices
        )
        history = ImportHistorySummary(
            job_id=job.id,
            source_kind=job.source_kind,
            created_at=job.created_at,
            source_label=job.source_label,
            target_gallery_label=job.target_gallery_label,
            status=counts.history_status(),
        )
        return cls(
            generation=generation,
            revision=revision,
            source_kind=job.source_kind,
            counts=counts,
            bytes_total=job.bytes_total,
            bytes_sent=job.bytes_sent,
            started_at=job.started_at,
            finished_at=job.finished_at,
            item_total=len(job.items),
            skipped_total=job.skipped,
            item_row_limit=item_row_limit,
            items=item_views,
            complete_rows=complete_rows,
            retry_available=retry_available,
            history=history,
        )

    @staticmethod
    def _item_view(source_index: int, item: ImportItem) -> ImportItemView:
        """Copy visible fields from one item without retaining the item object.

        Args:
            source_index (int): Position in the durable job's accepted item list.
            item (ImportItem): Mutable item whose visible values are copied.

        Returns:
            ImportItemView: Immutable row values and source position.
        """
        filename = Path(item.source_label).name or item.source_label
        detail = item.reason
        if not detail and item.server_result:
            detail = (
                f"uploaded={item.server_result.get('uploaded', 0)}, "
                f"scanned={item.server_result.get('scanned', 0)}"
            )
        return ImportItemView(
            source_index=source_index,
            item_id=item.id,
            filename=filename,
            size_bytes=item.size,
            local_decode=item.local_decode,
            state=item.state,
            reason=item.reason,
            attempt_count=item.attempt_count,
            activity_detail=detail,
        )
