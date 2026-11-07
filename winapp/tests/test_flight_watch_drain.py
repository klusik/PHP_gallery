# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_flight_watch_drain.py
# Module Type: Regression Test
# Purpose: Verify durable Stop admission and safe recovery of accepted photographs.
# Responsibilities:
#   - Exercise the real .pyw watcher with isolated folders and upload-state files.
#   - Prevent unresolved photo queues from being treated as safe key revocation.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise the actual watcher queue without opening Tk or using a Gallery."""

import importlib.machinery
import importlib.util
import json
import queue
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from types import ModuleType
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.flight_workflow import FinalizationWorker


def load_main_module() -> ModuleType:
    """Import the .pyw entry point without running its desktop application.

    Returns:
        ModuleType: Module exposing WatcherThread, WatcherConfig,
            UploadState, and read_document.
    """
    loader = importlib.machinery.SourceFileLoader(
        "gallery_flight_watch_drain_test",
        str(WINAPP_DIR / "gallery_watch_upload.pyw"),
    )
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        raise RuntimeError("Could not create the uploader source import spec.")
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


MAIN: ModuleType


class WatchDrainTests(unittest.TestCase):
    """Keep accepted photograph work durable across Stop, errors and restart."""

    @classmethod
    def setUpClass(cls) -> None:
        """Load production source once without creating its Tk application.

        Returns:
            None: Stores the imported module for isolated watcher fixtures.
        """
        global MAIN
        MAIN = load_main_module()
        cls.main = MAIN

    def make_watcher(self, folder: Path, workspace: Path) -> "MAIN.WatcherThread":
        """Build a real WatcherThread over isolated durable state.

        Args:
            folder (Path): Temporary watched folder owned by this test.
            workspace (Path): Temporary session folder for its drain manifest.

        Returns:
            MAIN.WatcherThread: Unstarted watcher with network and native
                work replaced.
        """
        config = self.main.WatcherConfig(
            gallery_url="https://gallery.example.test",
            api_key="fixture-key",
            watched_folder=str(folder),
            watch_recursive=False,
            delete_uploaded_files=False,
            scan_interval_seconds=0.0,
        )
        watcher = object.__new__(self.main.WatcherThread)
        watcher.config = config
        watcher.linked_target = None
        watcher._linked_lease = None
        watcher.events = queue.Queue()
        watcher.stop_event = threading.Event()
        watcher.state = self.main.UploadState(workspace / "upload-state.json")
        watcher.stability = mock.Mock()
        watcher.stability.stable.return_value = True
        watcher.remote_inventory = mock.Mock()
        watcher.remote_inventory.has_hash.return_value = False
        watcher.remote_inventory.confirm_after_failure.return_value = False
        watcher.remote_skipped_paths = set()
        watcher.initial_paths = set()
        watcher.initial_captured = False
        watcher._admission_lock = threading.RLock()
        watcher.accepted_paths = set()
        watcher.drain_event = threading.Event()
        watcher.drain_only = False
        watcher.drain_manifest = None
        watcher.drain_requested_at = 0.0
        watcher.drain_error = ""
        watcher.simconnect_client = mock.Mock()
        watcher.simconnect_client.dll_resolution_message.return_value = (
            "Fixture SimConnect.dll unavailable"
        )
        watcher.simconnect_diagnostics = {}
        watcher.sim_camera_metadata_fields = mock.Mock(return_value={})
        watcher.upload_watched_file = mock.Mock(
            return_value={"uploaded": 1, "scanned": 1}
        )
        return watcher

    def test_cutoff_uses_saved_recursive_choice_after_config_changes(self) -> None:
        """Do not lose nested photos admitted by the original Stop cutoff.

        Returns:
            None: The resumed drain uploads its previously eligible nested file.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            watched = root / 'watch'
            watched.mkdir()
            nested = watched / 'nested'
            nested.mkdir()
            source = nested / 'photo.jpg'
            source.write_bytes(b'synthetic image')
            workspace = root / 'flight'
            workspace.mkdir()
            worker = self.make_watcher(watched, workspace)
            worker.config.watch_recursive = False
            worker._watch_folder_override = watched
            worker._watch_recursive_override = True
            worker.configure_drain(workspace, ())
            worker.drain_event.set()
            # The future cutoff makes this fixture-created file pre-Stop.
            worker.drain_requested_at = time.time() + 1.0
            worker.drain_started_monotonic = time.monotonic()
            worker._run_owned()
            worker.upload_watched_file.assert_called_once()
            self.assertEqual(source, worker.upload_watched_file.call_args.args[1])
            saved = json.loads((workspace / 'watch-drain.json').read_text())
            self.assertTrue(saved['frozen'])
            self.assertEqual([], saved['pending'])
            self.assertTrue(saved['recursive'])

    def test_prior_worker_skip_is_not_repeated_in_the_same_session(self) -> None:
        """Do not requery one photograph already handled by this watcher.

        Returns:
            None: The next scan retains the same per-worker decision.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            watched = root / 'watch'
            watched.mkdir()
            source = watched / 'photo.jpg'
            source.write_bytes(b'synthetic image')
            worker = self.make_watcher(watched, root)
            worker.remote_inventory.refresh.return_value = True
            worker.remote_inventory.has_hash.return_value = True
            worker.scan_once(watched, 'https://gallery.example')
            worker.scan_once(watched, 'https://gallery.example')
            worker.remote_inventory.refresh.assert_called_once()
            worker.upload_watched_file.assert_not_called()

    def test_existing_startup_files_are_never_admitted(self) -> None:
        """Preserve the rule that pre-existing photos are ignored on start.

        Returns:
            None: Existing files remain outside the accepted drain queue.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "watch"
            workspace = root / "session"
            folder.mkdir()
            workspace.mkdir()
            old_photo = folder / "existing.png"
            old_photo.write_bytes(b"old image")
            watcher = self.make_watcher(folder, workspace)
            watcher.configure_drain(workspace, {old_photo})

            with mock.patch.object(
                self.main, "iter_candidate_files", return_value=[old_photo]
            ):
                watcher.scan_once(folder, "https://gallery.example.test/upload")

            watcher.upload_watched_file.assert_not_called()
            self.assertEqual(set(), watcher.accepted_paths)
            saved = self.main.read_document(workspace / "watch-drain.json")
            self.assertEqual([], saved["pending"])

    def test_linked_scan_rechecks_changed_bytes_but_not_acknowledged_photo(self) -> None:
        """Use fresh linked inventory for a change without polling known bytes.

        Returns:
            None: Each stable path/digest pair is checked once by the same
                worker and stays out of its accepted queue once acknowledged.
        """
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            workspace = folder / "session"
            workspace.mkdir()
            path = folder / "photo.jpg"
            path.write_bytes(b"original")
            worker = self.make_watcher(folder, workspace)
            worker.configure_drain(workspace, ())
            worker.linked_target = mock.Mock()
            worker.state = mock.Mock()
            worker.state.has_unresolved_upload.return_value = False
            worker.state.has_uncertain_source.return_value = False
            worker.remote_inventory.has_hash.return_value = True

            worker.scan_once(folder, "https://gallery.example.test")
            worker.scan_once(folder, "https://gallery.example.test")
            worker.remote_inventory.refresh.assert_called_once()
            self.assertEqual(set(), worker.accepted_paths)

            path.write_bytes(b"changed")
            worker.scan_once(folder, "https://gallery.example.test")
            self.assertEqual(2, worker.remote_inventory.refresh.call_count)
            worker.upload_watched_file.assert_not_called()
            self.assertEqual([], self.main.read_document(
                workspace / "watch-drain.json"
            )["pending"])

    def test_failed_linked_inventory_stops_with_a_recoverable_queue(self) -> None:
        """Exit promptly when a linked gallery will not check the file.

        Returns:
            None: The worker leaves the accepted path and reason for Retry,
                with the original file and no attempted transmission.
        """
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            workspace = folder / "session"
            workspace.mkdir()
            path = folder / "photo.jpg"
            path.write_bytes(b"original")
            worker = self.make_watcher(folder, workspace)
            worker.configure_drain(workspace, ())
            worker.linked_target = mock.Mock()
            worker.linked_target.session.can_upload.return_value = True
            worker.state = mock.Mock()
            worker.state.has_unresolved_upload.return_value = False
            worker.state.has_uncertain_source.return_value = False
            worker.remote_inventory.refresh.return_value = False

            worker.scan_once(folder, "https://gallery.example.test")
            self.assertTrue(worker.stop_event.is_set())
            row = self.main.read_document(workspace / "watch-drain.json")
            self.assertEqual([str(path)], row["pending"])
            self.assertIn("Retry", row["error"])
            worker.upload_watched_file.assert_not_called()
            self.assertEqual(b"original", path.read_bytes())

    def test_persisted_cutoff_recovers_last_partial_file_after_restart(self) -> None:
        """Drain a file created before Stop even when stability completes later.

        Returns:
            None: Restart restores the cutoff and safely acknowledges the file.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "watch"
            workspace = root / "session"
            folder.mkdir()
            workspace.mkdir()
            partial = folder / "last-photo.png"
            partial.write_bytes(b"partial at stop")

            first = self.make_watcher(folder, workspace)
            first.configure_drain(workspace, set())
            first.request_drain()
            saved = self.main.read_document(workspace / "watch-drain.json")
            cutoff = saved.get("cutoff")
            self.assertIsInstance(cutoff, (int, float))
            self.assertGreater(float(cutoff), 0.0)
            self.assertFalse(saved["frozen"])

            restarted = self.make_watcher(folder, workspace)
            restarted.configure_drain(workspace, set(), resume=True)
            self.assertEqual(float(cutoff), restarted.drain_requested_at)
            restarted.stability.stable.side_effect = [False, True]

            with mock.patch.object(
                self.main, "iter_candidate_files", return_value=[partial]
            ):
                restarted.run()

            restarted.upload_watched_file.assert_called_once()
            final = self.main.read_document(workspace / "watch-drain.json")
            self.assertEqual([], final["pending"])
            self.assertTrue(final["frozen"])

    def test_app_resumes_empty_unfrozen_cutoff_and_finds_final_photo(self) -> None:
        """Resume a persisted Stop before its final cutoff scan completed.

        Returns:
            None: The last pre-cutoff photo is restored, admitted and uploaded.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "watch"
            workspace = root / "session"
            folder.mkdir()
            workspace.mkdir()
            initial_photo = folder / "startup.png"
            final_photo = folder / "last-copy.png"
            initial_photo.write_bytes(b"pre-existing startup photo")
            final_photo.write_bytes(b"last copy completed before Stop cutoff")
            cutoff = final_photo.stat().st_ctime
            self.main.atomic_write_json(workspace / "watch-drain.json", {
                "schema_version": 1,
                "folder": str(folder),
                "recursive": False,
                "initial": [str(initial_photo)],
                "pending": [],
                "frozen": False,
                "cutoff": cutoff,
                "checkpoint": cutoff,
                "error": "",
            })

            app = object.__new__(self.main.WatcherApp)
            app.config = self.main.WatcherConfig(
                gallery_url="https://gallery.example.test",
                api_key="fixture-key",
                watched_folder=str(folder),
                watch_recursive=False,
                delete_uploaded_files=False,
            )
            app.config._snapshot_token = object()
            app.config_store = mock.Mock()
            app.mode_owner = mock.Mock()
            app.events = queue.Queue()
            app.linking_manager = mock.Mock()
            app.linking_manager.linked_upload_mode_selected.return_value = False
            app.flights = mock.Mock()
            app.flights.pending.return_value = [mock.Mock(directory=workspace)]
            state_factory = self.main.UploadState

            with (
                mock.patch.object(
                    self.main.WatcherThread, "start"
                ) as start_worker,
                mock.patch.object(self.main, "SimConnectCameraClient") as simconnect,
                mock.patch.object(
                    self.main,
                    "UploadState",
                    side_effect=lambda: state_factory(
                        workspace / "upload-state.json"
                    ),
                ),
                mock.patch.object(
                    self.main, "FileStabilityTracker"
                ) as stability_factory,
                mock.patch.object(
                    self.main, "RemoteInventorySession"
                ) as inventory_factory,
                mock.patch.object(
                    self.main.WatcherThread,
                    "upload_watched_file",
                    return_value={"uploaded": 1, "scanned": 1},
                ) as upload_photo,
            ):
                simconnect.return_value.diagnostics_snapshot.return_value = {}
                stability_factory.return_value.stable.return_value = True
                inventory_factory.return_value.has_hash.return_value = False

                worker = app.resume_flight_photos(workspace)

                if worker is None:
                    self.fail("An unfrozen empty Stop queue must be resumed.")
                start_worker.assert_called_once_with()
                self.assertEqual(cutoff, worker.drain_requested_at)
                self.assertEqual({initial_photo}, worker.initial_paths)
                self.assertFalse(worker.drain_only)

                # start is mocked so run the real cutoff reconciliation inline.
                worker.run()

            upload_photo.assert_called_once()
            self.assertEqual(final_photo, upload_photo.call_args.args[1])
            self.assertNotIn(initial_photo, worker.accepted_paths)
            saved = self.main.read_document(workspace / "watch-drain.json")
            self.assertEqual([], saved["pending"])
            self.assertTrue(saved["frozen"])

    def test_file_created_after_stop_cutoff_is_excluded(self) -> None:
        """Exclude later folder arrivals while draining the frozen boundary.

        Returns:
            None: Only the pre-cutoff photo is uploaded and acknowledged.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "watch"
            workspace = root / "session"
            folder.mkdir()
            workspace.mkdir()
            before = folder / "before-stop.png"
            late = folder / "after-stop.png"
            before.write_bytes(b"accepted image")
            watcher = self.make_watcher(folder, workspace)
            watcher.configure_drain(workspace, set())

            def upload_then_create_late_file(
                upload_url: str,
                path: Path,
                metadata_fields: dict[str, str],
                file_hash: str,
                expected_size: int | None,
            ) -> dict[str, int]:
                """Request Stop during one accepted upload, then add a later file.

                Args:
                    upload_url (str): Fixture endpoint unused by the mock.
                    path (Path): Accepted photo currently being uploaded.
                    metadata_fields (dict[str, str]): Empty metadata fixture.
                    file_hash (str): Captured source digest supplied by the watcher.
                    expected_size (int | None): Captured source byte count.

                Returns:
                    dict[str, int]: Successful acknowledgement for the first file.
                """
                watcher.request_drain()
                late.write_bytes(b"created after stop")
                return {"uploaded": 1, "scanned": 1}

            watcher.upload_watched_file.side_effect = upload_then_create_late_file
            watcher.config.scan_interval_seconds = 0.0
            watcher.initial_captured = True

            watcher.run()

            self.assertEqual(1, watcher.upload_watched_file.call_count)
            self.assertEqual(before, watcher.upload_watched_file.call_args.args[1])
            final = self.main.read_document(workspace / "watch-drain.json")
            self.assertNotIn(str(late), final["pending"])
            self.assertFalse(final["pending"])

    def test_upload_failure_keeps_the_accepted_photo_durable(self) -> None:
        """Keep a post-dispatch uncertain photo queued without a blind retry.

        Returns:
            None: Pending identity, stop and uncertain result remain observable.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "watch"
            workspace = root / "session"
            folder.mkdir()
            workspace.mkdir()
            photo = folder / "retry.png"
            photo.write_bytes(b"retry image")
            watcher = self.make_watcher(folder, workspace)
            watcher.configure_drain(workspace, set())
            watcher.accepted_paths.add(photo)
            watcher.drain_only = True
            watcher.persist_drain()
            watcher.upload_watched_file.side_effect = RuntimeError(
                "fixture server rejection"
            )

            watcher.scan_once(folder, "https://gallery.example.test/upload")

            saved = self.main.read_document(workspace / "watch-drain.json")
            self.assertEqual([str(photo)], saved["pending"])
            watcher.upload_watched_file.assert_called_once()
            self.assertTrue(watcher.stop_event.is_set())
            self.assertIn("unconfirmed", watcher.drain_error)
            self.assertNotIn(str(photo), watcher.state.data["failures"])

    def test_resume_rejects_a_path_outside_the_bound_watch_folder(self) -> None:
        """Refuse a tampered durable queue that names another folder's file.

        Returns:
            None: No outside path becomes accepted for the resumed worker.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "watch"
            workspace = root / "session"
            outside = root / "other"
            folder.mkdir()
            workspace.mkdir()
            outside.mkdir()
            owned_photo = folder / "owned.png"
            outside_photo = outside / "foreign.png"
            owned_photo.write_bytes(b"owned")
            outside_photo.write_bytes(b"foreign")
            self.main.atomic_write_json(workspace / "watch-drain.json", {
                "schema_version": 1,
                "folder": str(folder),
                "recursive": False,
                "initial": [],
                "pending": [str(owned_photo), str(outside_photo)],
                "frozen": True,
                "cutoff": 1.0,
                "checkpoint": 1.0,
                "error": "",
            })
            watcher = self.make_watcher(folder, workspace)

            with self.assertRaises(ValueError):
                watcher.configure_drain(workspace, set(), resume=True)

            self.assertNotIn(outside_photo, watcher.accepted_paths)

    def test_unbound_upload_history_does_not_skip_another_gallery(self) -> None:
        """Do not treat old global history as evidence for this destination.

        Returns:
            None: The existing file still enters the current upload workflow.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "watch"
            workspace = root / "session"
            folder.mkdir()
            workspace.mkdir()
            photo = folder / "known.png"
            payload = b"known uploaded photo"
            photo.write_bytes(payload)
            digest = self.main.sha256_file(photo)
            watcher = self.make_watcher(folder, workspace)
            watcher.state.mark_uploaded(photo, digest, len(payload), {
                "uploaded": 1,
                "scanned": 1,
            })
            watcher.configure_drain(workspace, set())
            watcher.accepted_paths.add(photo)
            watcher.drain_only = True
            watcher.persist_drain()

            watcher.scan_once(folder, "https://gallery.example.test/upload")

            watcher.upload_watched_file.assert_called_once()
            self.assertEqual(set(), watcher.accepted_paths)
            saved = self.main.read_document(workspace / "watch-drain.json")
            self.assertEqual([], saved["pending"])

    def test_resume_reuses_the_exact_contextual_watcher_key(self) -> None:
        """Retain the original keyed target for an in-process drain retry.

        Returns:
            None: A changed linked session refuses recovery before any worker starts.
        """
        with tempfile.TemporaryDirectory() as directory:
            workspace = Path(directory)
            self.main.atomic_write_json(workspace / "watch-drain.json", {
                "folder": directory,
                "recursive": False,
                "pending": ["retained-photo"],
                "frozen": True,
            })
            session = mock.Mock()
            issuer = "https://gallery.example.test"
            target = self.main.LinkedUploadTarget(session, issuer, 7, 9, "Screenshots")
            contextual = self.main.LinkedUploadTarget(
                session, issuer, 7, 9, "Screenshots", "synthetic watcher key",
            )
            app = mock.Mock()
            app._contextual_watch_target = contextual
            app.capture_linked_target.return_value = target
            app.flights.pending.return_value = [mock.Mock(directory=workspace)]
            with (
                mock.patch.object(self.main, "capture_worker_config"),
                mock.patch.object(self.main, "WatcherThread") as worker,
            ):
                result = self.main.WatcherApp.resume_flight_photos(app, workspace)
                self.assertIs(worker.return_value, result)
                self.assertIs(contextual, worker.call_args.args[2])
                app.capture_linked_target.return_value = self.main.LinkedUploadTarget(
                    mock.Mock(), issuer, 7, 9, "Screenshots",
                )
                with self.assertRaisesRegex(ValueError, "gallery changed"):
                    self.main.WatcherApp.resume_flight_photos(app, workspace)
                worker.assert_called_once()

    def test_cancellation_preserves_unfinished_queue(self) -> None:
        """Keep a queued photo when the watcher stops before processing it.

        Returns:
            None: Cancellation never removes an unacknowledged path.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "watch"
            workspace = root / "session"
            folder.mkdir()
            workspace.mkdir()
            photo = folder / "cancelled.png"
            photo.write_bytes(b"cancelled upload")
            watcher = self.make_watcher(folder, workspace)
            watcher.configure_drain(workspace, set())
            watcher.accepted_paths.add(photo)
            watcher.drain_only = True
            watcher.persist_drain()
            watcher.stop_event.set()

            watcher.scan_once(folder, "https://gallery.example.test/upload")

            saved = self.main.read_document(workspace / "watch-drain.json")
            self.assertEqual([str(photo)], saved["pending"])

    def test_unresolved_queue_blocks_finalization_and_revoke(self) -> None:
        """Never call revoke or mark completion while a photo remains pending.

        Returns:
            None: The finalization worker records an error before revocation.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "watch"
            workspace = root / "session"
            folder.mkdir()
            workspace.mkdir()
            photo = folder / "pending.png"
            photo.write_bytes(b"pending upload")
            watcher = self.make_watcher(folder, workspace)
            watcher.configure_drain(workspace, set())
            watcher.accepted_paths.add(photo)
            watcher.drain_only = True
            watcher.persist_drain()
            revoke_calls: list[str] = []

            def wait_for_workers(
                cancel: threading.Event, deadline: float
            ) -> None:
                """Fail the finalization barrier when a queued photo remains.

                Args:
                    cancel (threading.Event): Finalization cancellation event.
                    deadline (float): Monotonic operation deadline.

                Returns:
                    None: Raises before the revoke callback when the queue is pending.

                Raises:
                    RuntimeError: A durable accepted photo is unresolved.
                """
                saved = self.main.read_document(
                    workspace / "watch-drain.json"
                )
                if saved["pending"]:
                    raise RuntimeError("Accepted photographs remain pending.")

            def submit_revoke() -> None:
                """Record whether the finalization worker reached server revoke.

                Returns:
                    None: Appends a fixture marker after the revoke callback.
                """
                revoke_calls.append("sent")

            operation = FinalizationWorker(
                [],
                "https://gallery.example.test/upload",
                "fixture-key",
                wait_for_workers,
                submit_revoke,
                True,
            )
            operation.run()

            self.assertEqual("error", operation.snapshot().phase)
            self.assertFalse(operation.server_request_sent)
            self.assertEqual([], revoke_calls)


if __name__ == "__main__":
    unittest.main()
