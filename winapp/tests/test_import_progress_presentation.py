# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_import_progress_presentation.py
# Module Type: WinApp regression test
# Purpose: Verify the import save-to-presentation boundary and retained table updates.
# Responsibilities:
#   - Exercise the worker's saved revisions and parallel dirty-row handoff.
#   - Exercise generation fencing, coalescing, and visible import table ownership.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Regressions for the durable manual-import progress presentation."""

import copy
import queue
import threading
import unittest
from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
from pathlib import Path
from unittest import mock

from test_watcher_tree_reconciliation import FakeTreeview, MAIN, TEST_VIEW_FRACTIONS
from uploader.import_ui_snapshot import ImportProgressSnapshot, new_worker_generation
from uploader.models import ImportItem, ImportJob

# Purpose: Synchronize two independent real worker mutations before persistence.
# Type: int; Units: participants; Scope: parallel dirty-row regression.
# Consumers: test_two_mutations_before_the_first_save_share_a_revision.
# Rationale: Two upload threads expose a lost dirty item if queues are replaced.
PARALLEL_ITEM_COUNT = 2

# Purpose: Fail the regression promptly if the parallel fixture cannot rendezvous.
# Type: float; Units: seconds; Scope: one mutation rendezvous or future result.
# Consumers: test_two_mutations_before_the_first_save_share_a_revision.
# Rationale: Five seconds tolerate scheduling on hosted Windows and Linux runners.
WORKER_SYNC_SECONDS = 5.0


class ImportProgressPresentationTests(unittest.TestCase):
    """Drive production worker transitions and Tk projection with synthetic rows."""

    def setUp(self) -> None:
        """Give the worker and GUI separate copies of the accepted fixture job.

        Returns:
            None: Worker and event queue are ready with a deterministic store.
        """
        self.job = ImportJob(id="import", source_kind="files", source_label="Photos",
                             target_gallery_label="Test gallery")
        for name in ("a", "b"):
            self.job.items.append(ImportItem(
                id=name, path=name + ".jpg", source_label=name + ".jpg",
                state="queued",
            ))
        self.paths = [Path(item.path) for item in self.job.items]
        self.store = mock.Mock(spec=MAIN.JobStateStore)
        self.events: queue.Queue[dict[str, object]] = queue.Queue()
        self.worker = MAIN.ManualUploadThread(
            MAIN.WatcherConfig(), self.paths, False, 1, 1, self.events,
            copy.deepcopy(self.job), self.store,
        )

    def _snapshot(self) -> ImportProgressSnapshot:
        """Take the next production event and verify its stable job identity.

        Returns:
            ImportProgressSnapshot: Immutable post-save payload.
        """
        event = self.events.get_nowait()
        self.assertEqual(self.job.id, event["job_id"])
        self.assertEqual("job_progress", event["kind"])
        payload = event["payload"]
        self.assertIsInstance(payload, dict)
        snapshot = payload["snapshot"]
        self.assertIsInstance(snapshot, ImportProgressSnapshot)
        return snapshot

    def _app(self) -> "MAIN.WatcherApp":
        """Create the real event and progress presenter with isolated fake Tk cells.

        Returns:
            WatcherApp: Constructor-bypassed owner with a complete projection state.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.current_job = self.job
        app.manual_worker = self.worker
        app.job_store = self.store
        app.events = self.events
        app.exiting = False
        app._event_drain_last_count = 0
        app._event_drain_total_count = 0
        app.window_hidden_to_tray = False
        app._import_tab_selected = True
        app._activity_tab_selected = False
        app._import_presentation_after = 0.0
        app._import_presentation_flushes = 0
        app._presented_import_views = {}
        app._live_import_view = None
        app._history_import_view = None
        app._import_history_rows = {}
        app._import_history_dirty = {}
        app._import_history_rebuild = False
        app._import_detail_request = None
        app.import_item_tree = FakeTreeview()
        app.activity_item_tree = FakeTreeview()
        app.manual_progress_var = mock.Mock()
        app.manual_progress_text_var = mock.Mock()
        app.activity_job_summary_var = mock.Mock()
        return app

    def test_failed_save_never_publishes_the_unverified_item(self) -> None:
        """Keep the saved revision until another verified write includes the item.

        Returns:
            None: A failed save emits no event and retains the previous row data.
        """
        self.worker.persist_job()
        saved = self._snapshot()
        self.store.save_job.side_effect = OSError("Synthetic save failure")

        with self.assertRaises(OSError):
            self.worker.update_item(self.paths[0], "hashing")

        self.assertTrue(self.events.empty())
        self.assertIs(saved, self.worker.latest_import_projection())
        self.assertEqual("queued", saved.items[0].state)
        self.store.save_job.side_effect = None
        self.worker.persist_job(complete_rows=False)
        recovered = self._snapshot()
        self.assertEqual(saved.revision + 1, recovered.revision)
        self.assertEqual(("a",), tuple(row.item_id for row in recovered.items))
        self.assertEqual("hashing", recovered.items[0].state)

    def test_two_mutations_before_the_first_save_share_a_revision(self) -> None:
        """Include both changed rows in the first save after concurrent mutation.

        Returns:
            None: Both save operations still run and no accepted row change is lost.
        """
        self.worker.persist_job()
        first = self._snapshot()
        barrier = threading.Barrier(PARALLEL_ITEM_COUNT)
        persist = self.worker.persist_job

        def wait_then_save(*, complete_rows: bool) -> None:
            """Place both mutation calls immediately before their required saves.

            Args:
                complete_rows (bool): Whether the worker requested a full projection.

            Returns:
                None: Both callers proceed with the real production save boundary.
            """
            barrier.wait(WORKER_SYNC_SECONDS)
            persist(complete_rows=complete_rows)

        with mock.patch.object(self.worker, "persist_job", side_effect=wait_then_save):
            with ThreadPoolExecutor(max_workers=PARALLEL_ITEM_COUNT) as pool:
                futures = [
                    pool.submit(self.worker.update_item, path, "hashing")
                    for path in self.paths
                ]
                for future in futures:
                    future.result(timeout=WORKER_SYNC_SECONDS)

        changes = self._snapshot()
        duplicate_save = self._snapshot()
        self.assertEqual(first.revision + 1, changes.revision)
        self.assertEqual(changes.revision + 1, duplicate_save.revision)
        self.assertEqual(("a", "b"), tuple(row.item_id for row in changes.items))
        self.assertEqual((), duplicate_save.items)
        self.assertEqual(first.revision + PARALLEL_ITEM_COUNT, self.store.save_job.call_count)
        self.assertEqual(["queued", "queued"], [item.state for item in self.job.items])

    def test_event_drain_coalesces_visible_rows_and_catches_up_hidden_activity(self) -> None:
        """Apply the latest merged cells while preserving selection and scroll.

        Returns:
            None: Regular progress reads no durable job or history on Tk.
        """
        app = self._app()
        self.worker.persist_job()
        while app._drain_event_batch():
            pass
        app._flush_import_presentation(force=True)
        tree = app.import_item_tree
        tree.selection_set("a")
        tree.focus("a")
        tree.vertical_view = TEST_VIEW_FRACTIONS
        tree.horizontal_view = TEST_VIEW_FRACTIONS
        self.worker.update_item(self.paths[1], "hashing")
        self.worker.update_item(self.paths[1], "uploading", increment_attempt=True)
        while app._drain_event_batch():
            pass

        with mock.patch.object(
            MAIN.time, "monotonic", return_value=app._import_presentation_after - 0.001,
        ):
            app._flush_import_presentation()
        self.assertEqual([], tree.item_update_ids)
        app._flush_import_presentation(force=True)

        self.assertEqual(["b"], tree.item_update_ids)
        self.assertEqual(("a",), tree.selection())
        self.assertEqual("a", tree.focus())
        self.assertEqual(TEST_VIEW_FRACTIONS, tree.yview())
        self.assertEqual(TEST_VIEW_FRACTIONS, tree.xview())
        self.assertFalse(app.activity_item_tree.rows)
        app._import_tab_selected = False
        app._activity_tab_selected = True
        app._flush_import_presentation(force=True)
        self.assertEqual(
            ("b.jpg", "uploading", 1, ""),
            app.activity_item_tree.item("activity-b", "values"),
        )
        self.store.get_job.assert_not_called()
        self.store.list_jobs.assert_not_called()
        self.store.list_job_history.assert_not_called()

    def test_missing_revision_repairs_rows_without_accepting_an_old_generation(self) -> None:
        """Restore a complete bounded prefix from the real worker's saved state.

        Returns:
            None: Wrong-generation and late revisions cannot hide repaired rows.
        """
        app = self._app()
        self.worker.persist_job()
        first = self._snapshot()
        app._accept_import_progress(first)
        self.worker.update_item(self.paths[0], "hashing")
        missing = self._snapshot()
        self.worker.update_item(self.paths[1], "uploading")
        newest = self._snapshot()

        app._accept_import_progress(replace(newest, generation=new_worker_generation()))
        self.assertIs(first, app._live_import_view.latest)
        app._accept_import_progress(newest)
        app._accept_import_progress(missing)

        self.assertTrue(app._live_import_view.latest.complete_rows)
        self.assertEqual(newest.revision, app._live_import_view.latest.revision)
        self.assertEqual("hashing", app._live_import_view.rows[0].state)
        self.assertEqual("uploading", app._live_import_view.rows[1].state)
        self.store.get_job.assert_not_called()

    def test_cancelled_batch_updates_controls_before_its_background_read(self) -> None:
        """Show cancellation for the saved generation while the pane is hidden.

        Returns:
            None: A completion read is requested without reading saved data on Tk.
        """
        app = self._app()
        app.window_hidden_to_tray = True
        app.manual_status_var = mock.Mock()
        app.import_activity_var = mock.Mock()
        for name in ("start", "pause", "resume", "cancel", "retry_failed"):
            attribute = name + "_import_button" if name != "retry_failed" else "retry_failed_button"
            setattr(app, attribute, mock.Mock())
        app._request_import_detail = mock.Mock()
        app.refresh_job_history = mock.Mock()
        self.worker.persist_job()
        self.worker.mark_remaining_cancelled()
        self.worker.finalize_job()
        self.worker.emit_structured("job_completed", {
            "generation": self.worker.import_generation,
            "cancelled": True,
            "needs_review": False,
        })
        while app._drain_event_batch():
            pass

        self.assertFalse(app.import_item_tree.rows)
        app.manual_status_var.set.assert_called_once_with("Import cancelled")
        app.cancel_import_button.configure.assert_called_once_with(state="disabled")
        app.retry_failed_button.configure.assert_called_once_with(state="normal")
        app._request_import_detail.assert_called_once_with(self.job.id, purpose="finished")
        self.store.get_job.assert_not_called()
        app.window_hidden_to_tray = False
        app._flush_import_presentation(force=True)
        self.assertEqual("cancelled", app._live_import_view.rows[0].state)

    def test_clearing_a_review_copy_respects_accepted_import_ownership(self) -> None:
        """Avoid discarding an admitted job when the copied plan predates dispatch.

        Returns:
            None: An unbound draft still uses strict retirement and reports errors.
        """
        app = self._app()
        app.manual_plan = MAIN.ImportPlan(job=self.job)
        app.flights = mock.Mock()
        discard = app.flights.import_bindings.discard_unbound_preflight
        self.assertTrue(app._discard_unstarted_import_preflight())
        discard.assert_not_called()

        app.manual_worker = None
        discard.side_effect = OSError("Unconfirmed draft retirement")
        with mock.patch.object(MAIN.messagebox, "showwarning") as warn:
            self.assertFalse(app._discard_unstarted_import_preflight())
        warn.assert_called_once_with("Import recovery", "Unconfirmed draft retirement")
