# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_import_history_reader.py
# Module Type: Import History Read Model Tests
# Purpose: Protect bounded history projection and asynchronous latest-wins reads.
# Responsibilities:
#   - Verify bounded summaries preserve legacy durable history ordering.
#   - Verify independent history and selected-detail request lanes.
#   - Keep read-worker results detached from persisted state.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise safe bounded import history and off-thread detail reads."""

import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import patch

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.import_history_reader import (
    ImportHistoryReadResult,
    ImportHistoryReader,
)
from uploader.import_ui_snapshot import (
    ImportHistorySummary,
    new_worker_generation,
)
from uploader.models import ImportItem, ImportJob
from uploader.state_store import (
    JobStateStore,
    atomic_write_json,
    default_upload_state,
)

# Purpose: Populate enough history to distinguish a bounded newest prefix.
# Type: int. Units: durable fixture jobs. Scope: read-model ordering test.
# Consumers: ImportHistoryReaderTests._write_history.
# Rationale: Twelve records leave several older jobs beyond the projected prefix.
HISTORY_FIXTURE_JOB_COUNT = 12

# Purpose: Require a visible history result smaller than its raw backing history.
# Type: int. Units: summary rows. Scope: bounded history test.
# Consumers: test_history_projection_is_bounded_and_newest_first.
# Rationale: Three rows prove newest-first truncation without a large fixture.
HISTORY_VISIBLE_LIMIT = 3

# Purpose: Give asynchronous reader tests a bounded completion window.
# Type: float. Units: seconds. Scope: portable background-reader regression.
# Consumers: event waits and result polling.
# Rationale: Five seconds accommodates hosted CI scheduling without open-ended waits.
READER_WAIT_BUDGET_SECONDS = 5.0

# Purpose: Yield between nonblocking result-pump checks in the fixture.
# Type: float. Units: seconds. Scope: test-only asynchronous result polling.
# Consumers: ImportHistoryReaderTests._await_results.
# Rationale: Ten milliseconds avoids a busy loop while keeping the check responsive.
RESULT_POLL_INTERVAL_SECONDS = 0.01

# Purpose: Keep selected detail projection smaller than its durable item list.
# Type: int. Units: visible item rows. Scope: historical detail result test.
# Consumers: test_reader_keeps_independent_latest_wins_lanes.
# Rationale: Two visible rows prove detail conversion honors the caller cap.
DETAIL_ROW_LIMIT = 2


class GatedJobStateStore(JobStateStore):
    """Pause one real history read to exercise deterministic request coalescing."""

    def __init__(
        self,
        path: Path,
        started: threading.Event,
        release: threading.Event,
    ) -> None:
        """Create a real store with a one-shot test scheduling gate.

        Args:
            path (Path): Temporary state path owned by the base store.
            started (threading.Event): Signaled when the first list read begins.
            release (threading.Event): Releases that read to the real store method.

        Returns:
            None: The test gate is ready before the reader thread starts.
        """
        super().__init__(path)
        self._started = started
        self._release = release
        self._gated = False

    def list_job_history(self, limit: int) -> list[ImportHistorySummary]:
        """Gate the first call, then delegate all history work to JobStateStore.

        Args:
            limit (int): Maximum number of history rows to return.

        Returns:
            list[ImportHistorySummary]: Real bounded summaries from the base owner.
        """
        if not self._gated:
            self._gated = True
            self._started.set()
            self._release.wait(READER_WAIT_BUDGET_SECONDS)
        return super().list_job_history(limit)


class ImportHistoryReaderTests(unittest.TestCase):
    """Keep history projection bounded and detail reads off the UI caller."""

    def _write_history(self, path: Path) -> ImportJob:
        """Write synthetic durable jobs and return the exact selected job.

        Args:
            path (Path): Temporary upload-state document path.

        Returns:
            ImportJob: The newest job, containing four visible source items.
        """
        state = default_upload_state()
        jobs: dict[str, dict[str, object]] = {}
        selected: ImportJob | None = None
        for index in range(HISTORY_FIXTURE_JOB_COUNT):
            job = ImportJob(
                id=f"history-{index:02d}",
                source_kind="folder",
                source_label=f"Synthetic history {index}",
                target_gallery_label="Synthetic gallery",
                created_at=float(index + 1),
            )
            if index == HISTORY_FIXTURE_JOB_COUNT - 1:
                job.items = [
                    ImportItem(
                        id=f"detail-{item_index}",
                        path=f"synthetic/{item_index}.jpg",
                        source_label=f"synthetic/{item_index}.jpg",
                        size=1024 + item_index,
                        state="discovered",
                    )
                    for item_index in range(4)
                ]
                job.bytes_total = sum(item.size for item in job.items)
                selected = job
            jobs[job.id] = job.to_dict()
        state["jobs"] = jobs
        atomic_write_json(path, state)
        if selected is None:
            raise AssertionError("The selected history fixture was not created.")
        return selected

    def _await_results(
        self, reader: ImportHistoryReader,
    ) -> dict[str, ImportHistoryReadResult]:
        """Poll both independent result lanes until present or the bound expires.

        Args:
            reader (ImportHistoryReader): Reader whose event-pump results are polled.

        Returns:
            dict[str, ImportHistoryReadResult]: Latest result by lane, or an
                incomplete map at timeout.
        """
        deadline = time.monotonic() + READER_WAIT_BUDGET_SECONDS
        received: dict[str, ImportHistoryReadResult] = {}
        while time.monotonic() < deadline and len(received) < 2:
            for result in reader.poll_ready():
                received[result.kind] = result
            if len(received) < 2:
                threading.Event().wait(RESULT_POLL_INTERVAL_SECONDS)
        return received

    def test_history_projection_is_bounded_and_newest_first(self) -> None:
        """Return only the newest raw rows without constructing every ImportJob.

        Returns:
            None: The summary list is bounded, correctly sorted and count-shaped.
        """
        with tempfile.TemporaryDirectory(prefix="phpgallery-history-read-") as directory:
            path = Path(directory) / "upload_state.json"
            selected = self._write_history(path)
            store = JobStateStore(path)
            with patch.object(
                ImportJob,
                "from_dict",
                side_effect=AssertionError("History projection hydrated an ImportJob."),
            ):
                summaries = store.list_job_history(HISTORY_VISIBLE_LIMIT)

        self.assertEqual(HISTORY_VISIBLE_LIMIT, len(summaries))
        self.assertEqual(
            [selected.id, "history-10", "history-09"],
            [summary.job_id for summary in summaries],
        )
        self.assertEqual(selected.created_at, summaries[0].created_at)
        self.assertEqual(
            time.strftime("%Y-%m-%d %H:%M", time.localtime(selected.created_at)),
            summaries[0].created_label,
        )
        self.assertEqual("folder", summaries[0].source_kind)
        self.assertEqual("Synthetic history 11", summaries[0].source_label)
        self.assertEqual(
            "uploaded 0, duplicate 0, failed 0, cancelled 0, active 4",
            summaries[0].status,
        )

    def test_reader_keeps_independent_latest_wins_lanes(self) -> None:
        """Discard stale history while retaining the independent detail selection.

        Returns:
            None: Each lane publishes only its newest ticket and detail stays bounded.
        """
        with tempfile.TemporaryDirectory(prefix="phpgallery-reader-lanes-") as directory:
            path = Path(directory) / "upload_state.json"
            selected = self._write_history(path)
            first_read_started = threading.Event()
            release_first_read = threading.Event()
            store = GatedJobStateStore(
                path, first_read_started, release_first_read,
            )
            reader = ImportHistoryReader(store)
            self.addCleanup(reader.close)
            self.addCleanup(release_first_read.set)

            self.assertFalse(reader.has_work())
            stale_history_ticket = reader.request_history(HISTORY_VISIBLE_LIMIT)
            self.assertTrue(reader.has_work())
            self.assertTrue(
                first_read_started.wait(READER_WAIT_BUDGET_SECONDS),
                "The background history read did not start.",
            )
            latest_history_ticket = reader.request_history(HISTORY_VISIBLE_LIMIT - 1)
            detail_generation = new_worker_generation()
            detail_ticket = reader.request_job(
                selected.id, DETAIL_ROW_LIMIT, detail_generation,
            )
            release_first_read.set()
            results = self._await_results(reader)
            history_result = results.get("history")
            detail_result = results.get("detail")
            self.assertIsNotNone(history_result)
            self.assertIsNotNone(detail_result)
            self.assertGreater(latest_history_ticket, stale_history_ticket)
            self.assertEqual(latest_history_ticket, history_result.ticket)
            self.assertEqual(detail_ticket, detail_result.ticket)
            self.assertEqual(
                HISTORY_VISIBLE_LIMIT - 1, len(history_result.history),
            )
            self.assertEqual(detail_generation, detail_result.snapshot.generation)
            self.assertTrue(detail_result.snapshot.complete_rows)
            self.assertEqual(DETAIL_ROW_LIMIT, len(detail_result.snapshot.items))
            self.assertEqual(selected.id, detail_result.job.id)
            self.assertEqual("folder", detail_result.snapshot.source_kind)

            # The transferred job belongs to the UI caller, not the stored read.
            detail_result.job.source_label = "UI-owned copy mutation"
            durable = store.get_job(selected.id)
            self.assertEqual(selected.source_label, durable.source_label)
            self.assertFalse(reader.has_work())
            reader.close()
            self.assertTrue(reader.wait_closed(READER_WAIT_BUDGET_SECONDS))

    def test_history_projection_preserves_legacy_model_defaults(self) -> None:
        """Normalize legacy display fields and invalid item states like models.

        Returns:
            None: Raw legacy rows retain ImportJob and ImportItem display defaults.
        """
        with tempfile.TemporaryDirectory(prefix="phpgallery-legacy-history-") as directory:
            path = Path(directory) / "upload_state.json"
            state = default_upload_state()
            state["jobs"] = {
                "legacy-row": {
                    "id": "legacy-row",
                    "created_at": 25.0,
                    "items": [
                        {"state": "confirmed"},
                        {"state": "unknown-legacy-state"},
                    ],
                },
            }
            atomic_write_json(path, state)

            summary = JobStateStore(path).list_job_history(1)[0]

        self.assertEqual("files", summary.source_kind)
        self.assertEqual("", summary.source_label)
        self.assertEqual("", summary.target_gallery_label)
        self.assertEqual(
            "uploaded 1, duplicate 0, failed 1, cancelled 0, active 0",
            summary.status,
        )


if __name__ == "__main__":
    unittest.main()
