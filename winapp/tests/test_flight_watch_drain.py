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
                scan_interval_seconds=0.0,
            )
            app.events = queue.Queue()
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
            ) -> dict[str, int]:
                """Request Stop during one accepted upload, then add a later file.

                Args:
                    upload_url (str): Fixture endpoint unused by the mock.
                    path (Path): Accepted photo currently being uploaded.
                    metadata_fields (dict[str, str]): Empty metadata fixture.

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
        """Keep a failed accepted photo queued for later recovery.

        Returns:
            None: Failure state and pending identity both remain on disk.
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
            self.assertIn(str(photo), watcher.state.data["failures"])

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

    def test_upload_history_acknowledges_queue_without_reupload(self) -> None:
        """Use real persisted upload history as durable remote evidence.

        Returns:
            None: Matching prior history clears the queue without a network call.
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

            watcher.upload_watched_file.assert_not_called()
            self.assertEqual(set(), watcher.accepted_paths)
            saved = self.main.read_document(workspace / "watch-drain.json")
            self.assertEqual([], saved["pending"])

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
