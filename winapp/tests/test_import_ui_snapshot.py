# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_import_ui_snapshot.py
# Module Type: Import Presentation Model Tests
# Purpose: Protect immutable worker progress snapshots and stable row projections.
# Responsibilities:
#   - Verify snapshots detach from mutable import jobs and items.
#   - Preserve bounded ordering, worker fencing and current Treeview value shapes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise immutable import progress snapshots using portable domain models."""

import sys
import unittest
from pathlib import Path

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.import_ui_snapshot import (
    ImportProgressSnapshot,
    new_worker_generation,
)
from uploader.models import ImportItem, ImportJob

# Purpose: Supply four rows so full and sparse projection behavior are distinct.
# Type: int. Units: import items. Scope: snapshot projection fixture.
# Consumers: ImportUiSnapshotTests._job.
# Rationale: Four rows allow a two-row cap and nonadjacent dirty indices.
ITEM_FIXTURE_COUNT = 4

# Purpose: Keep bounded projection tests at a prefix shorter than the fixture.
# Type: int. Units: visible rows. Scope: one snapshot projection assertion.
# Consumers: ImportUiSnapshotTests.test_full_projection_is_bounded_and_ordered.
# Rationale: Two visible rows prove the caller-supplied cap truncates the fixture.
PROJECTION_ROW_LIMIT = 2

# Purpose: Address a later row in the stable source ordering.
# Type: int. Units: zero-based item index. Scope: sparse projection assertion.
# Consumers: ImportUiSnapshotTests.test_dirty_projection_keeps_source_order.
# Rationale: Index three distinguishes the sparse set from the bounded prefix.
LAST_FIXTURE_INDEX = 3

# Purpose: Give the dirty projection an index beyond the accepted item list.
# Type: int. Units: zero-based item index. Scope: sparse projection boundary.
# Consumers: ImportUiSnapshotTests.test_dirty_projection_keeps_source_order.
# Rationale: The invalid index must not create a row outside the source ordering.
OUT_OF_RANGE_ITEM_INDEX = ITEM_FIXTURE_COUNT

# Purpose: Make server receipt detail visibly distinguish uploaded and scanned counts.
# Type: tuple[int, int]. Units: server records. Scope: one item detail fixture.
# Consumers: ImportUiSnapshotTests.test_treeview_value_shapes_match_current_contract.
# Rationale: Different values prove both receipt fields and their order survive.
SERVER_RECEIPT_COUNTS = (3, 4)


class ImportUiSnapshotTests(unittest.TestCase):
    """Keep snapshots immutable, bounded and compatible with current UI rows."""

    def _job(self) -> ImportJob:
        """Create a synthetic job with distinct states and stable source order.

        Returns:
            ImportJob: Four in-memory items with no file or network dependency.
        """
        items = [
            ImportItem(
                id=f"item-{index}",
                path=f"synthetic/{index}.jpg",
                source_label=f"synthetic/{index}.jpg",
                size=1024 + index,
                state=("confirmed" if index == 0 else "discovered"),
                local_decode="local",
            )
            for index in range(ITEM_FIXTURE_COUNT)
        ]
        items[0].server_result = {
            "uploaded": SERVER_RECEIPT_COUNTS[0],
            "scanned": SERVER_RECEIPT_COUNTS[1],
        }
        items[1].reason = "Needs review"
        job = ImportJob(
            id="snapshot-job",
            source_kind="folder",
            source_label="Synthetic folder",
            target_gallery_label="Synthetic gallery",
            items=items,
            bytes_total=sum(item.size for item in items),
            bytes_sent=items[0].size,
            started_at=100.0,
            created_at=50.0,
        )
        return job

    def test_treeview_value_shapes_match_current_contract(self) -> None:
        """Retain exact item, detail and history cell order and receipt text.

        Returns:
            None: Stable row identities and visible field tuples match the UI.
        """
        job = self._job()
        snapshot = ImportProgressSnapshot.from_job(
            job, new_worker_generation(), 1, ITEM_FIXTURE_COUNT,
        )
        receipt_row = snapshot.items[0]
        reason_row = snapshot.items[1]

        self.assertEqual("item-0", receipt_row.item_id)
        self.assertEqual(
            (
                "0.jpg", "1.0 KiB", "local", "confirmed", "",
            ),
            receipt_row.item_values("1.0 KiB"),
        )
        self.assertEqual(
            ("0.jpg", "confirmed", 0, "uploaded=3, scanned=4"),
            receipt_row.activity_values(),
        )
        self.assertEqual(
            ("1.jpg", "discovered", 0, "Needs review"),
            reason_row.activity_values(),
        )
        self.assertEqual(
            (
                "2026-01-01 00:00", "Synthetic folder", "Synthetic gallery",
                "uploaded 1, duplicate 0, failed 0, cancelled 0, active 3",
            ),
            snapshot.history.row_values("2026-01-01 00:00"),
        )
        self.assertEqual("snapshot-job", snapshot.history.job_id)
        self.assertEqual("folder", snapshot.source_kind)
        self.assertEqual("folder", snapshot.history.source_kind)
        self.assertTrue(snapshot.retry_available)

    def test_snapshot_is_detached_from_mutable_source_models(self) -> None:
        """Keep captured progress and row values after source mutation.

        Returns:
            None: The immutable snapshot remains a view of its original revision.
        """
        job = self._job()
        generation = new_worker_generation()
        snapshot = ImportProgressSnapshot.from_job(
            job, generation, 7, ITEM_FIXTURE_COUNT,
        )
        original_values = snapshot.items[0].activity_values()
        original_history = snapshot.history.row_values("created")

        job.bytes_sent += 500
        job.source_label = "Changed source"
        job.items[0].state = "failed_retryable"
        job.items[0].reason = "Changed after snapshot"
        job.items[0].server_result["uploaded"] = 99

        self.assertEqual(1024, snapshot.bytes_sent)
        self.assertEqual("Synthetic folder", snapshot.history.source_label)
        self.assertEqual(original_history, snapshot.history.row_values("created"))
        self.assertEqual("confirmed", snapshot.items[0].state)
        self.assertEqual(original_values, snapshot.items[0].activity_values())
        with self.assertRaises(AttributeError):
            snapshot.bytes_sent = 1

    def test_full_projection_is_bounded_and_ordered(self) -> None:
        """Project only the caller-owned prefix while retaining source indices.

        Returns:
            None: A complete bounded projection contains exactly the stable prefix.
        """
        snapshot = ImportProgressSnapshot.from_job(
            self._job(), new_worker_generation(), 2, PROJECTION_ROW_LIMIT,
        )

        self.assertTrue(snapshot.complete_rows)
        self.assertEqual(PROJECTION_ROW_LIMIT, snapshot.item_row_limit)
        self.assertEqual(ITEM_FIXTURE_COUNT, snapshot.item_total)
        self.assertEqual([0, 1], [row.source_index for row in snapshot.items])
        self.assertEqual(["item-0", "item-1"], [row.item_id for row in snapshot.items])
        self.assertEqual(0, snapshot.skipped_total)

    def test_dirty_projection_keeps_source_order(self) -> None:
        """Sort sparse dirty indices and discard indices outside the row cap.

        Returns:
            None: Only valid changed rows appear in their accepted source order.
        """
        dirty_indices = frozenset((LAST_FIXTURE_INDEX, 1, OUT_OF_RANGE_ITEM_INDEX))
        snapshot = ImportProgressSnapshot.from_job(
            self._job(), new_worker_generation(), 3, ITEM_FIXTURE_COUNT,
            dirty_item_indices=dirty_indices,
        )

        self.assertFalse(snapshot.complete_rows)
        self.assertEqual(
            [1, LAST_FIXTURE_INDEX],
            [row.source_index for row in snapshot.items],
        )
        self.assertEqual(["item-1", "item-3"], [row.item_id for row in snapshot.items])

    def test_generation_and_revision_fence_worker_snapshots(self) -> None:
        """Give each worker lifetime a distinct token and ordered revisions.

        Returns:
            None: Snapshots expose worker identity and the caller's revision order.
        """
        first_generation = new_worker_generation()
        second_generation = new_worker_generation()
        first = ImportProgressSnapshot.from_job(
            self._job(), first_generation, 1, PROJECTION_ROW_LIMIT,
        )
        second = ImportProgressSnapshot.from_job(
            self._job(), first_generation, 2, PROJECTION_ROW_LIMIT,
        )
        restarted = ImportProgressSnapshot.from_job(
            self._job(), second_generation, 1, PROJECTION_ROW_LIMIT,
        )

        self.assertNotEqual(first.generation, restarted.generation)
        self.assertEqual(first.generation, second.generation)
        self.assertLess(first.revision, second.revision)
        self.assertEqual(1, restarted.revision)

    def test_retry_eligibility_matches_terminal_and_failed_states(self) -> None:
        """Enable retry for active/recoverable work and permanent failures only.

        Returns:
            None: Terminal confirmed jobs disable retry while failed work enables it.
        """
        job = self._job()
        for item in job.items:
            item.state = "confirmed"
        complete = ImportProgressSnapshot.from_job(
            job, new_worker_generation(), 1, ITEM_FIXTURE_COUNT,
        )
        self.assertFalse(complete.retry_available)

        job.items[-1].state = "failed_permanent"
        failed = ImportProgressSnapshot.from_job(
            job, new_worker_generation(), 1, ITEM_FIXTURE_COUNT,
        )
        self.assertTrue(failed.retry_available)


if __name__ == "__main__":
    unittest.main()
