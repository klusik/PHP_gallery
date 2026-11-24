# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watcher_tree_reconciliation.py
# Module Type: Watcher UI Regression Tests
# Purpose: Protect incremental Treeview reconciliation and retained row behavior.
# Responsibilities:
#   - Verify Watcher tables update only changed rows and preserve widget state.
#   - Protect existing caps, projections, filter order, and job-selection callback.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise bounded Watcher Treeview reconciliation with a native-widget fake."""

import importlib.machinery
import importlib.util
import sys
import unittest
from collections import Counter
from dataclasses import replace
from pathlib import Path
from types import ModuleType, SimpleNamespace
from typing import Dict, Tuple, cast
from unittest.mock import Mock

# Structural path: tests live one directory below the WinApp import root.
WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.import_history_reader import ImportHistoryReadResult
from uploader.import_ui_snapshot import (
    ImportHistorySummary,
    ImportProgressSnapshot,
    new_worker_generation,
)
from uploader.models import ActivityEvent, ImportItem, ImportJob


# Purpose: Provide one over-cap item so both import views prove their row bound.
# Type: int.
# Units: fixture rows.
# Scope: manual-import Treeview cap regression.
# Consumers: WatcherTreeReconciliationTests.test_import_views_keep_projection_and_cap.
# Rationale: One extra row proves the visible prefix is truncated at the owner cap.
IMPORT_OVER_CAP_EXTRA_ROWS = 1

# Purpose: Provide enough synthetic events for the activity filter to exceed its cap.
# Type: int.
# Units: multiples of the activity drawer row cap.
# Scope: filtered activity row regression.
# Consumers: WatcherTreeReconciliationTests.test_activity_filter_keeps_order_cap_and_identity.
# Rationale: Three cap lengths leave more than one cap of alternating warnings.
ACTIVITY_FIXTURE_CAP_MULTIPLIER = 3

# Purpose: Place alternating warning events among the activity fixture.
# Type: int.
# Units: event indices per warning event.
# Scope: activity filter ordering regression.
# Consumers: WatcherTreeReconciliationTests.test_activity_filter_keeps_order_cap_and_identity.
# Rationale: Every other event matches warnings while the all filter stays interleaved.
ACTIVITY_WARNING_STRIDE = 2

# Purpose: Identify the first and second synthetic item rows in import projections.
# Type: int.
# Units: zero-based row index.
# Scope: import label and result fallback assertions.
# Consumers: WatcherTreeReconciliationTests.test_import_views_keep_projection_and_cap.
# Rationale: The first rows carry distinct server-result and local-reason fixtures.
FIRST_IMPORT_FIXTURE_INDEX = 0
SECOND_IMPORT_FIXTURE_INDEX = 1

# Purpose: Give the selected history row a stable place inside the retained prefix.
# Type: int.
# Units: zero-based job index.
# Scope: history selection and recovery-action regression.
# Consumers: WatcherTreeReconciliationTests.test_history_refresh_preserves_job_selection_and_recovery_action.
# Rationale: The selected job remains visible when a newer row enters the cap.
SELECTED_HISTORY_FIXTURE_INDEX = 10

# Purpose: Define recognizable server counts for item-detail fallback text.
# Type: tuple[int, int].
# Units: uploaded and scanned server records.
# Scope: synthetic import item server-result fixture.
# Consumers: WatcherTreeReconciliationTests.test_import_views_keep_projection_and_cap.
# Rationale: Distinct values verify both labels remain in the rendered detail.
SERVER_RESULT_FIXTURE_COUNTS = (3, 4)

# Purpose: Bound the mock job list with one row beyond its visible prefix.
# Type: int.
# Units: fixture jobs.
# Scope: newest-first history cap regression.
# Consumers: WatcherTreeReconciliationTests.test_history_refresh_preserves_job_selection_and_recovery_action.
# Rationale: One extra newest record proves the existing prefix limit is enforced.
HISTORY_OVER_CAP_EXTRA_ROWS = 1

# Purpose: Keep synthetic job dates inside Windows localtime's supported range.
# Type: float.
# Units: Unix seconds.
# Scope: synthetic import jobs in the Treeview regressions.
# Consumers: WatcherTreeReconciliationTests.make_job.
# Rationale: 2000-01-01 UTC leaves room for negative ordering offsets while
# avoiding Windows CRT rejection of dates before the Unix epoch.
JOB_FIXTURE_EPOCH_SECONDS = 946684800.0

# Purpose: Define the initial item state before any synthetic retry.
# Type: int.
# Units: attempts.
# Scope: synthetic import row fixture.
# Consumers: WatcherTreeReconciliationTests.test_import_views_keep_projection_and_cap.
# Rationale: A new item begins with no upload attempt recorded.
NO_ATTEMPTS = 0

# Purpose: Bound Treeview child-ID reads per row reconciliation.
# Type: int.
# Units: Tk widget reads per refresh.
# Scope: one Watcher Treeview update.
# Consumers: WatcherTreeReconciliationTests.test_reconciliation_is_idempotent_and_updates_only_changed_rows.
# Rationale: Read the child-ID sequence once and compare projected values in Python.
TREE_CHILD_READS_PER_RECONCILIATION = 1

# Purpose: Select the first root row in Treeview order for fixture assertions.
# Type: int.
# Units: zero-based row index.
# Scope: visible-row order assertions.
# Consumers: WatcherTreeReconciliationTests.
# Rationale: Tk's first displayed row occupies index zero.
FIRST_TREE_ROW_INDEX = 0

# Purpose: Address the source-label column in the current job-history projection.
# Type: int.
# Units: zero-based cell index.
# Scope: job-history source update assertion.
# Consumers: WatcherTreeReconciliationTests.test_history_refresh_preserves_job_selection_and_recovery_action.
# Rationale: The current history projection renders source immediately after time.
HISTORY_SOURCE_COLUMN_INDEX = 1

# Purpose: Address level and message cells in the current activity projection.
# Type: tuple[int, int].
# Units: zero-based cell indices.
# Scope: filtered activity row assertions.
# Consumers: WatcherTreeReconciliationTests.test_activity_filter_keeps_order_cap_and_identity.
# Rationale: Preserve the current time/level/filename/operation/message column order.
ACTIVITY_LEVEL_COLUMN_INDEX = 1
ACTIVITY_MESSAGE_COLUMN_INDEX = 4

# Purpose: Define the unchanged then retried attempt values in the item fixture.
# Type: tuple[int, int].
# Units: upload attempts.
# Scope: synthetic item row update regression.
# Consumers: WatcherTreeReconciliationTests.test_import_views_keep_projection_and_cap.
# Rationale: The second value proves attempt changes update the existing row.
ATTEMPT_FIXTURE_COUNTS = (2, 3)

# Purpose: Define the duplicate event reference count in the identity fixture.
# Type: int.
# Units: occurrences of one object in the event list.
# Scope: activity event row identity regression.
# Consumers: WatcherTreeReconciliationTests.test_activity_filter_keeps_order_cap_and_identity.
# Rationale: Two references to the same object must produce distinct row IDs.
DUPLICATE_EVENT_OCCURRENCE_COUNT = 2

# Purpose: Address the final zero-based synthetic event in expected labels.
# Type: int.
# Units: event-index offset.
# Scope: activity event row order regression.
# Consumers: WatcherTreeReconciliationTests.test_activity_filter_keeps_order_cap_and_identity.
# Rationale: The final event index is one less than the event count.
ACTIVITY_LAST_INDEX_OFFSET = 1

# Purpose: Initialize the synthetic viewport at its complete content range.
# Type: tuple[float, float].
# Units: normalized view fractions.
# Scope: fake Treeview initial state.
# Consumers: FakeTreeview.__init__.
# Rationale: Tk reports zero through one before any user scrolling.
EMPTY_VIEW_FRACTIONS = (0.0, 1.0)

# Purpose: Define a non-edge view position for scroll-preservation checks.
# Type: tuple[float, float].
# Units: first and last visible fractions.
# Scope: fake Treeview viewport behavior.
# Consumers: WatcherTreeReconciliationTests scroll assertions.
# Rationale: Interior fractions expose accidental reset to either viewport edge.
TEST_VIEW_FRACTIONS = (0.25, 0.75)


def _load_application() -> ModuleType:
    """Load the actual Watcher entry point without starting its Tk mainloop.

    Returns:
        ModuleType: Imported compatibility application module.

    Raises:
        ImportError: The entry point cannot be loaded from the test checkout.
    """
    source_path = WINAPP_DIR / "gallery_watch_upload.pyw"
    loader = importlib.machinery.SourceFileLoader(
        "gallery_watch_upload_tree_test",
        str(source_path),
    )
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None or spec.loader is None:
        raise ImportError(f"Unable to load Watcher source from {source_path}.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


MAIN = _load_application()


class FakeTreeview:
    """Model only the ordered row and viewport operations used by Watcher."""

    def __init__(self) -> None:
        """Initialize an empty row store and observable Treeview operations.

        Returns:
            None: The fake table is ready for a reconciliation assertion.
        """
        self.rows: Dict[str, Tuple[str | int | float, ...]] = {}
        self.order: list[str] = []
        self.selected: tuple[str, ...] = ()
        self.focused = ""
        self.vertical_view = EMPTY_VIEW_FRACTIONS
        self.horizontal_view = EMPTY_VIEW_FRACTIONS
        self.calls: Counter[str] = Counter()
        self.item_update_ids: list[str] = []

    def get_children(self, item: str = "") -> tuple[str, ...]:
        """Return current top-level row IDs.

        Args:
            item (str): Parent item; empty selects the root rows.

        Returns:
            tuple[str, ...]: Current root rows in display order.
        """
        self.calls["get_children"] += 1
        return tuple(self.order)

    def insert(
        self,
        parent: str,
        index: int | str,
        iid: str,
        values: tuple[str | int | float, ...],
    ) -> str:
        """Insert one row at the requested root position.

        Args:
            parent (str): Parent item, empty for a root row.
            index (int | str): Zero-based position or the end marker.
            iid (str): Stable item identifier.
            values (tuple[str | int | float, ...]): Displayed cell values.

        Returns:
            str: The inserted stable item identifier.
        """
        self.calls["insert"] += 1
        position = len(self.order) if index == "end" else int(index)
        self.order.insert(position, iid)
        self.rows[iid] = values
        return iid

    def delete(self, *item_ids: str) -> None:
        """Remove the requested rows and their widget state.

        Args:
            item_ids (str): One or more root row identifiers.

        Returns:
            None: Rows, selection entries, and focus for removed IDs are cleared.
        """
        self.calls["delete"] += 1
        for item_id in item_ids:
            self.order.remove(item_id)
            del self.rows[item_id]
        self.selected = tuple(
            item_id for item_id in self.selected if item_id in self.rows
        )
        if self.focused not in self.rows:
            self.focused = ""

    def item(
        self,
        item_id: str,
        option: str | None = None,
        **options: tuple[str | int | float, ...],
    ) -> tuple[str | int | float, ...] | dict[str, tuple[str | int | float, ...]]:
        """Read one row's values or apply the reconciler's value update.

        Args:
            item_id (str): Stable row identifier.
            option (str | None): Optional value field requested by a reader.
            options (tuple[str | int | float, ...]): Named Treeview values option.

        Returns:
            tuple[str | int | float, ...] | dict[str, tuple[str | int | float, ...]]:
                The requested values or updated item mapping.
        """
        if "values" in options:
            self.calls["item_update"] += 1
            self.item_update_ids.append(item_id)
            self.rows[item_id] = options["values"]
        if option == "values":
            return self.rows[item_id]
        return {"values": self.rows[item_id]}

    def move(self, item_id: str, parent: str, index: int) -> None:
        """Move one existing row within root display order.

        Args:
            item_id (str): Stable item identifier.
            parent (str): Parent item, empty for a root row.
            index (int): Zero-based destination position.

        Returns:
            None: The row occupies its requested position.
        """
        self.calls["move"] += 1
        self.order.remove(item_id)
        self.order.insert(index, item_id)

    def selection(self) -> tuple[str, ...]:
        """Return selected row IDs in widget order.

        Returns:
            tuple[str, ...]: Current selected item identities.
        """
        return self.selected

    def selection_set(self, *item_ids: str) -> None:
        """Select the supplied rows.

        Args:
            item_ids (str): One or more row identifiers to select.

        Returns:
            None: The fake records the same selection state as Tk.
        """
        self.calls["selection_set"] += 1
        self.selected = tuple(item_ids)

    def selection_remove(self, *item_ids: str) -> None:
        """Remove selection from the supplied rows.

        Args:
            item_ids (str): One or more row identifiers to deselect.

        Returns:
            None: The fake retains any other selected rows.
        """
        self.calls["selection_remove"] += 1
        removed = set(item_ids)
        self.selected = tuple(
            item_id for item_id in self.selected if item_id not in removed
        )

    def focus(self, item_id: str | None = None) -> str:
        """Return or set the focused row.

        Args:
            item_id (str | None): Optional row to focus.

        Returns:
            str: Focused row identifier or an empty string.
        """
        if item_id is not None:
            self.focused = item_id
        return self.focused

    def yview(self) -> tuple[float, float]:
        """Return the current vertical view fractions.

        Returns:
            tuple[float, float]: First and last visible content fractions.
        """
        return self.vertical_view

    def xview(self) -> tuple[float, float]:
        """Return the current horizontal view fractions.

        Returns:
            tuple[float, float]: First and last visible content fractions.
        """
        return self.horizontal_view

    def yview_moveto(self, fraction: float) -> None:
        """Restore the first vertical view fraction.

        Args:
            fraction (float): First visible content fraction.

        Returns:
            None: The fake viewport retains its prior span.
        """
        self.calls["yview_moveto"] += 1
        span = self.vertical_view[1] - self.vertical_view[0]
        self.vertical_view = (fraction, min(EMPTY_VIEW_FRACTIONS[1], fraction + span))

    def xview_moveto(self, fraction: float) -> None:
        """Restore the first horizontal view fraction.

        Args:
            fraction (float): First visible content fraction.

        Returns:
            None: The fake viewport retains its prior span.
        """
        self.calls["xview_moveto"] += 1
        span = self.horizontal_view[1] - self.horizontal_view[0]
        self.horizontal_view = (fraction, min(EMPTY_VIEW_FRACTIONS[1], fraction + span))


class WatcherTreeReconciliationTests(unittest.TestCase):
    """Verify idempotent row updates and existing Watcher view contracts."""

    def setUp(self) -> None:
        """Create a constructor-bypassed app with four isolated fake tables.

        Returns:
            None: All row writers are ready without a display or worker.
        """
        self.app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        self.app.import_item_tree = FakeTreeview()
        self.app.activity_item_tree = FakeTreeview()
        self.app.job_history_tree = FakeTreeview()
        self.app.activity_drawer = FakeTreeview()
        self.app.format_bytes = MAIN.WatcherApp.format_bytes.__get__(
            self.app,
            MAIN.WatcherApp,
        )

    def make_job(
        self,
        job_id: str,
        created_offset_seconds: float,
        items: list[ImportItem] | None = None,
    ) -> ImportJob:
        """Build one durable import job for the history and detail views.

        Args:
            job_id (str): Stable job identity.
            created_offset_seconds (float): Seconds added to the fixed supported
                fixture epoch to retain synthetic newest-first ordering.
            items (list[ImportItem] | None): Optional item rows for the job;
                defaults to None to create an empty item list.

        Returns:
            ImportJob: Synthetic job using the production domain model.
        """
        return ImportJob(
            id=job_id,
            source_kind="files",
            source_label=job_id,
            target_gallery_label="Synthetic gallery",
            items=items if items is not None else [],
            created_at=JOB_FIXTURE_EPOCH_SECONDS + created_offset_seconds,
        )

    def _history_rows(
        self, jobs: list[ImportJob], revision: int,
    ) -> tuple[ImportHistorySummary, ...]:
        """Create immutable history summaries through the real projection model.

        Args:
            jobs (list[ImportJob]): Ordered durable jobs to project.
            revision (int): Synthetic reader revision for the fixture snapshot.

        Returns:
            tuple[ImportHistorySummary, ...]: Stable rows in the supplied order.
        """
        summaries: list[ImportHistorySummary] = []
        for job in jobs:
            snapshot = ImportProgressSnapshot.from_job(
                job,
                new_worker_generation(),
                revision,
                MAIN.WATCHER_IMPORT_TREE_ROW_LIMIT,
            )
            summaries.append(replace(
                snapshot.history,
                created_label=f"created-{job.created_at}",
            ))
        return tuple(summaries)

    def _prepare_async_history_host(self, reader: SimpleNamespace) -> None:
        """Initialize the projection lifecycle fields for constructor bypass.

        Args:
            reader (SimpleNamespace): Deterministic reader double for the event pump.

        Returns:
            None: History selection and immediate presentation can be exercised.
        """
        self.app._import_presentation_after = 0.0
        self.app._import_presentation_flushes = 0
        self.app._presented_import_views = {}
        self.app._history_import_view = None
        self.app._live_import_view = None
        self.app._import_history_rows = {}
        self.app._import_history_dirty = {}
        self.app._import_history_rebuild = False
        self.app._import_history_requested = False
        self.app._recheck_import_history = False
        self.app._import_detail_request = None
        self.app._import_history_reader = reader
        self.app.current_job = None
        self.app.manual_plan = None
        self.app._activity_tab_selected = True
        self.app._import_tab_selected = False
        self.app.activity_job_summary_var = Mock()
        self.app.retry_failed_button = Mock()

    def test_history_refresh_preserves_job_selection_and_recovery_action(self) -> None:
        """Preserve history viewport and adopt only a fenced detail result.

        Returns:
            None: Async history keeps row state and selection enables explicit retry.
        """
        jobs = [
            self.make_job(f"job-{index}", float(-index))
            for index in range(
                MAIN.WATCHER_JOB_HISTORY_TREE_ROW_LIMIT
                + HISTORY_OVER_CAP_EXTRA_ROWS
            )
        ]
        selected_job = jobs[SELECTED_HISTORY_FIXTURE_INDEX]
        selected_job.items.append(ImportItem(
            id="retry-item",
            path="retry.jpg",
            source_label="retry.jpg",
            state="failed_retryable",
        ))
        newest_job = self.make_job("newest-job", float(len(jobs)))
        reader = SimpleNamespace(
            request_history=Mock(side_effect=(1, 2)),
            request_job=Mock(return_value=3),
            poll_ready=Mock(side_effect=(
                (ImportHistoryReadResult(
                    kind="history", ticket=1,
                    history=self._history_rows(
                        jobs[:MAIN.WATCHER_JOB_HISTORY_TREE_ROW_LIMIT], 1,
                    ),
                ),),
            )),
        )
        self._prepare_async_history_host(reader)

        MAIN.WatcherApp.refresh_job_history(self.app)
        MAIN.WatcherApp._receive_import_history(self.app)
        MAIN.WatcherApp._flush_import_presentation(self.app, force=True)

        history = self.app.job_history_tree
        selected_id = selected_job.id
        history.selection_set(selected_id)
        history.focus(selected_id)
        history.vertical_view = TEST_VIEW_FRACTIONS
        history.horizontal_view = TEST_VIEW_FRACTIONS
        selected_job.source_label = "Updated selected source"
        history.calls.clear()

        latest_history_result = ImportHistoryReadResult(
            kind="history", ticket=2,
            history=self._history_rows(
                [newest_job, *jobs][
                    :MAIN.WATCHER_JOB_HISTORY_TREE_ROW_LIMIT
                ],
                2,
            ),
        )
        detail_result = ImportHistoryReadResult(
            kind="detail", ticket=3, job=selected_job,
            snapshot=ImportProgressSnapshot.from_job(
                selected_job, new_worker_generation(), 3,
                MAIN.WATCHER_IMPORT_TREE_ROW_LIMIT,
            ),
        )
        reader.poll_ready.side_effect = (
            (latest_history_result,), (detail_result,),
        )

        MAIN.WatcherApp.refresh_job_history(self.app)
        MAIN.WatcherApp._receive_import_history(self.app)
        MAIN.WatcherApp._flush_import_presentation(self.app, force=True)

        self.assertEqual(
            MAIN.WATCHER_JOB_HISTORY_TREE_ROW_LIMIT,
            len(history.get_children("")),
        )
        self.assertEqual(
            "newest-job",
            history.get_children("")[FIRST_TREE_ROW_INDEX],
        )
        self.assertIn(selected_id, history.get_children(""))
        self.assertEqual(
            "Updated selected source",
            history.item(selected_id, "values")[HISTORY_SOURCE_COLUMN_INDEX],
        )
        self.assertFalse(history.calls["selection_set"])
        self.assertEqual((selected_id,), history.selection())
        self.assertEqual(selected_id, history.focus())
        self.assertEqual(TEST_VIEW_FRACTIONS, history.yview())
        self.assertEqual(TEST_VIEW_FRACTIONS, history.xview())

        MAIN.WatcherApp.select_history_job(self.app)
        self.assertEqual((3, selected_id, "selected"), self.app._import_detail_request)
        MAIN.WatcherApp._receive_import_history(self.app)
        MAIN.WatcherApp._flush_import_presentation(self.app, force=True)

        self.assertIs(selected_job, self.app.current_job)
        self.assertIs(selected_job, self.app.manual_plan.job)
        self.assertIn(
            "target Synthetic gallery",
            self.app.activity_job_summary_var.set.call_args.args[0],
        )
        self.app.retry_failed_button.configure.assert_called_once_with(
            state="normal",
        )

    def test_stale_detail_ticket_cannot_replace_current_selection(self) -> None:
        """Keep the selected job from the newest detail ticket only.

        Returns:
            None: A stale earlier result is ignored before the current result lands.
        """
        stale_job = self.make_job("stale-job", 0.0)
        current_job = self.make_job("current-job", 1.0)
        current_generation = new_worker_generation()
        current_snapshot = ImportProgressSnapshot.from_job(
            current_job, current_generation, 22,
            MAIN.WATCHER_IMPORT_TREE_ROW_LIMIT,
        )
        reader = SimpleNamespace(
            request_history=Mock(),
            request_job=Mock(return_value=22),
            poll_ready=Mock(return_value=(
                ImportHistoryReadResult(
                    kind="detail", ticket=21, job=stale_job,
                    snapshot=ImportProgressSnapshot.from_job(
                        stale_job, new_worker_generation(), 21,
                        MAIN.WATCHER_IMPORT_TREE_ROW_LIMIT,
                    ),
                ),
                ImportHistoryReadResult(
                    kind="detail", ticket=22, job=current_job,
                    snapshot=current_snapshot,
                ),
            )),
        )
        self._prepare_async_history_host(reader)
        self.app.job_history_tree.selection_set(current_job.id)
        self.app._import_detail_request = (22, current_job.id, "selected")

        MAIN.WatcherApp._receive_import_history(self.app)
        MAIN.WatcherApp._flush_import_presentation(self.app, force=True)

        self.assertIs(current_job, self.app.current_job)
        self.assertIs(current_job, self.app.manual_plan.job)
        self.assertEqual("current-job", self.app._history_import_view.latest.history.job_id)
        self.assertIsNone(self.app._import_detail_request)

    def test_repeated_history_refresh_keeps_active_result_and_one_followup(self) -> None:
        """Coalesce repeated list refreshes without discarding the active result.

        Returns:
            None: The first list applies and exactly one follow-up request is issued.
        """
        first_job = self.make_job("first-history-job", 0.0)
        second_job = self.make_job("second-history-job", 1.0)
        reader = SimpleNamespace(
            request_history=Mock(side_effect=(31, 32)),
            request_job=Mock(),
            poll_ready=Mock(side_effect=(
                (ImportHistoryReadResult(
                    kind="history", ticket=31,
                    history=self._history_rows([first_job], 31),
                ),),
                (ImportHistoryReadResult(
                    kind="history", ticket=32,
                    history=self._history_rows(
                        [second_job, first_job], 32,
                    ),
                ),),
            )),
        )
        self._prepare_async_history_host(reader)

        MAIN.WatcherApp.refresh_job_history(self.app)
        MAIN.WatcherApp.refresh_job_history(self.app)
        self.assertEqual(1, reader.request_history.call_count)
        self.assertTrue(self.app._recheck_import_history)

        MAIN.WatcherApp._receive_import_history(self.app)
        self.assertEqual(2, reader.request_history.call_count)
        self.assertTrue(self.app._import_history_requested)
        self.assertFalse(self.app._recheck_import_history)
        MAIN.WatcherApp._flush_import_presentation(self.app, force=True)
        self.assertEqual(
            ("first-history-job",),
            self.app.job_history_tree.get_children(""),
        )

        MAIN.WatcherApp._receive_import_history(self.app)
        MAIN.WatcherApp._flush_import_presentation(self.app, force=True)

        self.assertEqual(
            ("second-history-job", "first-history-job"),
            self.app.job_history_tree.get_children(""),
        )
        self.assertEqual(2, reader.request_history.call_count)
        self.assertFalse(self.app._import_history_requested)

    def test_reconciliation_is_idempotent_and_updates_only_changed_rows(
        self,
    ) -> None:
        """Skip Tk mutations for equal rows and update a changed value in place.

        Returns:
            None: Stable IDs and existing viewport state survive both refreshes.
        """
        tree = FakeTreeview()
        initial_rows = [
            ("first", ("First row",)),
            ("second", ("Second row",)),
        ]
        MAIN.WatcherApp._reconcile_tree_rows(self.app, tree, initial_rows)
        self.assertFalse(tree.calls["item_update"])
        tree.selection_set("second")
        tree.focus("first")
        tree.vertical_view = TEST_VIEW_FRACTIONS
        tree.horizontal_view = TEST_VIEW_FRACTIONS
        tree.calls.clear()
        tree.item_update_ids.clear()

        MAIN.WatcherApp._reconcile_tree_rows(self.app, tree, initial_rows)

        self.assertFalse(tree.calls["insert"])
        self.assertFalse(tree.calls["delete"])
        self.assertFalse(tree.calls["item_update"])
        self.assertFalse(tree.calls["move"])
        self.assertEqual(TREE_CHILD_READS_PER_RECONCILIATION, tree.calls["get_children"])
        self.assertFalse(tree.calls["selection_set"])
        self.assertEqual(("second",), tree.selection())
        self.assertEqual("first", tree.focus())
        self.assertEqual(TEST_VIEW_FRACTIONS, tree.yview())
        self.assertEqual(TEST_VIEW_FRACTIONS, tree.xview())

        tree.calls.clear()
        changed_rows = [
            ("first", ("First row",)),
            ("second", ("Updated row",)),
        ]
        MAIN.WatcherApp._reconcile_tree_rows(self.app, tree, changed_rows)

        self.assertEqual(["second"], tree.item_update_ids)
        self.assertEqual(
            TREE_CHILD_READS_PER_RECONCILIATION,
            tree.calls["get_children"],
        )
        self.assertEqual(("Updated row",), tree.item("second", "values"))
        self.assertFalse(tree.calls["insert"])
        self.assertFalse(tree.calls["delete"])
        self.assertFalse(tree.calls["move"])
        self.assertEqual(("second",), tree.selection())
        self.assertEqual("first", tree.focus())

    def test_reconciliation_inserts_deletes_reorders_and_restores_view(self) -> None:
        """Apply structural changes while retaining surviving widget state.

        Returns:
            None: Order, selection, focus, and scroll match the requested rows.
        """
        tree = FakeTreeview()
        initial_rows = [
            ("alpha", ("Alpha",)),
            ("beta", ("Beta",)),
            ("gamma", ("Gamma",)),
        ]
        MAIN.WatcherApp._reconcile_tree_rows(self.app, tree, initial_rows)
        tree.selection_set("beta")
        tree.focus("alpha")
        tree.vertical_view = TEST_VIEW_FRACTIONS
        tree.horizontal_view = TEST_VIEW_FRACTIONS
        tree.calls.clear()

        desired_rows = [
            ("gamma", ("Gamma",)),
            ("beta", ("Beta",)),
            ("delta", ("Delta",)),
        ]
        MAIN.WatcherApp._reconcile_tree_rows(self.app, tree, desired_rows)

        self.assertEqual(
            ("gamma", "beta", "delta"),
            tree.get_children(""),
        )
        self.assertTrue(tree.calls["insert"])
        self.assertTrue(tree.calls["delete"])
        self.assertTrue(tree.calls["move"])
        self.assertFalse(tree.calls["selection_set"])
        self.assertEqual(("beta",), tree.selection())
        self.assertFalse(tree.focus())
        self.assertEqual(TEST_VIEW_FRACTIONS, tree.yview())
        self.assertEqual(TEST_VIEW_FRACTIONS, tree.xview())

    def test_import_views_keep_projection_and_cap(self) -> None:
        """Retain item labels, server-result fallback and the original row cap.

        Returns:
            None: Both import views show the bounded exact domain projection.
        """
        items: list[ImportItem] = []
        total_rows = (
            MAIN.WATCHER_IMPORT_TREE_ROW_LIMIT + IMPORT_OVER_CAP_EXTRA_ROWS
        )
        for index in range(total_rows):
            items.append(ImportItem(
                id=f"item-{index}",
                path=f"synthetic-{index}.jpg",
                source_label=f"synthetic-{index}.jpg",
                size=index,
                state="confirmed" if index == FIRST_IMPORT_FIXTURE_INDEX else "discovered",
                attempt_count=(
                    ATTEMPT_FIXTURE_COUNTS[0]
                    if index == FIRST_IMPORT_FIXTURE_INDEX else NO_ATTEMPTS
                ),
                reason="Needs review" if index == SECOND_IMPORT_FIXTURE_INDEX else "",
                server_result=(
                    {
                        "uploaded": SERVER_RESULT_FIXTURE_COUNTS[0],
                        "scanned": SERVER_RESULT_FIXTURE_COUNTS[1],
                    }
                    if index == FIRST_IMPORT_FIXTURE_INDEX else {}
                ),
                local_decode="local",
            ))
        job = self.make_job("job-import", float(total_rows), items)

        MAIN.WatcherApp.refresh_import_item_tree(self.app, job)

        self.assertEqual(
            MAIN.WATCHER_IMPORT_TREE_ROW_LIMIT,
            len(self.app.import_item_tree.get_children("")),
        )
        self.assertEqual(
            MAIN.WATCHER_IMPORT_TREE_ROW_LIMIT,
            len(self.app.activity_item_tree.get_children("")),
        )
        self.assertEqual(
            (
                    f"synthetic-{FIRST_IMPORT_FIXTURE_INDEX}.jpg",
                "0 B", "local", "confirmed", ""
            ),
            self.app.import_item_tree.item(f"item-{FIRST_IMPORT_FIXTURE_INDEX}", "values"),
        )
        self.assertEqual(
            (
                f"synthetic-{FIRST_IMPORT_FIXTURE_INDEX}.jpg",
                "confirmed",
                ATTEMPT_FIXTURE_COUNTS[0],
                f"uploaded={SERVER_RESULT_FIXTURE_COUNTS[0]}, "
                f"scanned={SERVER_RESULT_FIXTURE_COUNTS[1]}",
            ),
            self.app.activity_item_tree.item(f"activity-item-{FIRST_IMPORT_FIXTURE_INDEX}", "values"),
        )
        self.assertEqual(
            (
                f"synthetic-{SECOND_IMPORT_FIXTURE_INDEX}.jpg",
                "discovered",
                NO_ATTEMPTS,
                "Needs review",
            ),
            self.app.activity_item_tree.item(f"activity-item-{SECOND_IMPORT_FIXTURE_INDEX}", "values"),
        )
        self.assertNotIn(
            f"item-{total_rows - ACTIVITY_LAST_INDEX_OFFSET}",
            self.app.import_item_tree.get_children(""),
        )

        self.app.import_item_tree.calls.clear()
        self.app.import_item_tree.item_update_ids.clear()
        self.app.activity_item_tree.calls.clear()
        self.app.activity_item_tree.item_update_ids.clear()
        items[FIRST_IMPORT_FIXTURE_INDEX].state = "failed_retryable"
        items[FIRST_IMPORT_FIXTURE_INDEX].attempt_count = ATTEMPT_FIXTURE_COUNTS[1]
        items[FIRST_IMPORT_FIXTURE_INDEX].reason = "Synthetic retry"
        MAIN.WatcherApp.refresh_import_item_tree(self.app, job)

        self.assertEqual(
            [f"item-{FIRST_IMPORT_FIXTURE_INDEX}"],
            self.app.import_item_tree.item_update_ids,
        )
        self.assertEqual(
            [f"activity-item-{FIRST_IMPORT_FIXTURE_INDEX}"],
            self.app.activity_item_tree.item_update_ids,
        )
        self.assertFalse(self.app.import_item_tree.calls["insert"])
        self.assertFalse(self.app.import_item_tree.calls["delete"])
        self.assertFalse(self.app.activity_item_tree.calls["insert"])
        self.assertFalse(self.app.activity_item_tree.calls["delete"])

        MAIN.WatcherApp.refresh_import_item_tree(self.app, None)

        self.assertFalse(self.app.import_item_tree.get_children(""))
        self.assertFalse(self.app.activity_item_tree.get_children(""))

    def test_activity_filter_keeps_order_cap_and_identity(self) -> None:
        """Preserve filtered order, stable event rows, and surviving selection.

        Returns:
            None: Filtered snapshots retain the latest bounded rows in order.
        """
        events: list[ActivityEvent] = []
        event_total = (
            MAIN.WATCHER_ACTIVITY_DRAWER_ROW_LIMIT
            * ACTIVITY_FIXTURE_CAP_MULTIPLIER
        )
        for index in range(event_total):
            level = (
                "warning" if index % ACTIVITY_WARNING_STRIDE == 0 else "success"
            )
            events.append(ActivityEvent(
                level=level,
                message=f"event-{index}",
                operation="synthetic",
                timestamp=float(index),
            ))
        events.append(events[len(events) - ACTIVITY_LAST_INDEX_OFFSET])
        self.app.activity_events = events
        self.app._activity_render_after_id = None
        self.app._activity_drawer_flush_count = NO_ATTEMPTS
        filter_var = Mock()
        filter_var.get.return_value = "warnings"
        self.app.activity_filter_var = filter_var

        MAIN.WatcherApp.refresh_activity_drawer(self.app)

        drawer = self.app.activity_drawer
        warning_rows = drawer.get_children("")
        self.assertEqual(
            MAIN.WATCHER_ACTIVITY_DRAWER_ROW_LIMIT,
            len(warning_rows),
        )
        warning_snapshot = cast(
            MAIN._TreeRowSnapshot,
            getattr(drawer, "_watcher_row_snapshot"),
        )
        self.assertEqual(
            MAIN.WATCHER_ACTIVITY_DRAWER_ROW_LIMIT,
            len(warning_snapshot.retained_activity_events),
        )
        self.assertIs(
            events[event_total - ACTIVITY_WARNING_STRIDE],
            warning_snapshot.retained_activity_events[FIRST_TREE_ROW_INDEX],
        )
        first_values = drawer.item(warning_rows[FIRST_TREE_ROW_INDEX], "values")
        self.assertEqual(
            f"event-{event_total - ACTIVITY_WARNING_STRIDE}",
            first_values[ACTIVITY_MESSAGE_COLUMN_INDEX],
        )
        self.assertEqual(
            "warning",
            first_values[ACTIVITY_LEVEL_COLUMN_INDEX],
        )
        selected_id = warning_rows[FIRST_TREE_ROW_INDEX]
        drawer.selection_set(selected_id)
        drawer.focus(selected_id)
        drawer.vertical_view = TEST_VIEW_FRACTIONS
        drawer.horizontal_view = TEST_VIEW_FRACTIONS
        filter_var.get.return_value = "all"

        MAIN.WatcherApp.refresh_activity_drawer(self.app)

        all_rows = drawer.get_children("")
        self.assertEqual(
            MAIN.WATCHER_ACTIVITY_DRAWER_ROW_LIMIT,
            len(all_rows),
        )
        all_snapshot = cast(
            MAIN._TreeRowSnapshot,
            getattr(drawer, "_watcher_row_snapshot"),
        )
        expected_event_references = tuple(reversed(
            self.app.activity_events[
                -MAIN.WATCHER_ACTIVITY_DRAWER_ROW_LIMIT:
            ],
        ))
        self.assertTrue(all(
            expected is retained
            for expected, retained in zip(
                expected_event_references,
                all_snapshot.retained_activity_events,
            )
        ))
        latest_event_label = (
            f"event-{event_total - ACTIVITY_LAST_INDEX_OFFSET}"
        )
        self.assertEqual(
            latest_event_label,
            drawer.item(
                all_rows[FIRST_TREE_ROW_INDEX],
                "values",
            )[ACTIVITY_MESSAGE_COLUMN_INDEX],
        )
        duplicate_ids = [
            item_id for item_id in all_rows
            if drawer.item(item_id, "values")[ACTIVITY_MESSAGE_COLUMN_INDEX] == latest_event_label
        ]
        self.assertEqual(
            DUPLICATE_EVENT_OCCURRENCE_COUNT,
            len(duplicate_ids),
        )
        self.assertEqual(
            len(duplicate_ids),
            len(set(duplicate_ids)),
        )
        self.assertIn(selected_id, all_rows)
        self.assertEqual((selected_id,), drawer.selection())
        self.assertEqual(selected_id, drawer.focus())
        self.assertEqual(TEST_VIEW_FRACTIONS, drawer.yview())
        self.assertEqual(TEST_VIEW_FRACTIONS, drawer.xview())


if __name__ == "__main__":
    unittest.main()
