# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watcher_continuation.py
# Module Type: Windows Tooling Regression Test
# Purpose: Verify explicit and nonce-bound Watcher recovery across updates.
# Responsibilities:
#   - Exercise the continuation host without starting Tk or network requests.
#   - Prove resumed dispatch waits for durable one-use consumption.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise update continuation admission with isolated local state only."""

import importlib.machinery
import importlib.util
from collections import deque
from dataclasses import replace
import json
import queue
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from types import ModuleType, SimpleNamespace
from typing import Any, Callable
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.flight_jobs import SessionPlan, WatcherContinuation, WorkspaceLease
from uploader.app_linking import UPLOAD_OFFLINE_SCOPE, UPLOAD_SCOPE
from uploader.self_update import UpdateDiagnostic
from uploader.linked_upload import LinkedUploadTarget, linked_target_binding
from uploader.app_linking_ui import LinkingManager
from uploader.photos_workspace import (
    PHOTOS_DIAGNOSTIC_RECENT_LIMIT,
    PHOTOS_OBSERVATION_RECENT_LIMIT,
    PhotosWorkspace,
)
from uploader.update_ui import (
    UPDATE_DIAGNOSTIC_RECENT_LIMIT,
    UpdateController,
)

# Bound local event waits so a broken continuation cannot hang the hosted suite.
# Type: float; Units: seconds; Scope: one synthetic continuation test event.
# Consumers: wait_for_event_kind. Rationale: 3 seconds exposes a lost worker
# event without consuming the central suite's per-test timeout.
TEST_EVENT_WAIT_SECONDS = 3.0
# Bound joins for synthetic watcher threads after release, cancel or timeout.
# Type: float; Units: seconds; Scope: one Watcher continuation worker fixture.
# Consumers: WatcherContinuationTests. Rationale: worker actions are local and
# should settle promptly; a stuck worker must fail visibly instead of hanging.
TEST_WORKER_JOIN_SECONDS = 3.0
# Exercise timeout refusal without waiting for the production 15-second window.
# Type: float; Units: seconds; Scope: continuation gate timeout regression.
# Consumers: test_gate_timeout_retains_every_pending_source. Rationale: this
# patch changes only the test opportunity, not the production timeout policy.
TEST_GATE_TIMEOUT_SECONDS = 0.05
# Keep synthetic resume hosts inside the same short verification window.
# Type: float; Units: seconds; Scope: linked resume host fixtures.
# Consumers: linked target verification tests. Rationale: the host only
# schedules work inside this window; no fixture waits for its deadline.
TEST_RESUME_DEADLINE_SECONDS = 30.0


def load_main_module() -> ModuleType:
    """Import the .pyw host without constructing its desktop application.

    Returns:
        ModuleType: Main entry point exposing WatcherApp and WatcherThread.
    """
    loader = importlib.machinery.SourceFileLoader(
        "gallery_watcher_continuation_test",
        str(WINAPP_DIR / "gallery_watch_upload.pyw"),
    )
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        raise RuntimeError("Could not create the uploader source import spec.")
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


MAIN = load_main_module()


def initialize_event_pump_fixture(app: MAIN.WatcherApp) -> None:
    """Bind constructor-bypassed tests to the real bounded event dispatcher.

    Args:
        app (MAIN.WatcherApp): Host fixture for event delivery or diagnostics.

    Returns:
        None: Pump counters are initialized and production batch dispatch is bound.
    """
    app._drain_running = False
    app._drain_after_id = None
    app._event_drain_last_count = 0
    app._event_drain_total_count = 0
    app._event_drain_yield_count = 0
    app._event_drain_last_ms = 0.0
    app._event_drain_max_ms = 0.0
    app._activity_drawer_flush_count = 0
    app._drain_event_batch = MAIN.WatcherApp._drain_event_batch.__get__(
        app, MAIN.WatcherApp,
    )
    app.refresh_contextual_watcher_copy = mock.Mock()


def wait_for_event_kind(
    events: queue.Queue[Any], kind: str,
) -> dict[str, Any]:
    """Read until one structured worker event of the requested kind arrives.

    Args:
        events (queue.Queue[Any]): Thread-safe worker event queue.
        kind (str): Exact event kind to return.

    Returns:
        dict[str, Any]: Event with a ``kind`` field equal to the requested key.

    Raises:
        AssertionError: No matching event arrived within the named fixture bound.
    """
    deadline = time.monotonic() + TEST_EVENT_WAIT_SECONDS
    while time.monotonic() < deadline:
        try:
            event = events.get(timeout=max(0.01, deadline - time.monotonic()))
        except queue.Empty:
            break
        if isinstance(event, dict) and event.get("kind") == kind:
            return event
    raise AssertionError(f"Worker did not emit {kind!r} within the fixture bound.")


class WatcherContinuationTests(unittest.TestCase):
    """Keep update resume bound to one durable session and confirmed nonce."""

    def setUp(self) -> None:
        """Create one real lease, plan, checkpoint and retained photo queue.

        Returns:
            None: All fixture paths live in a fresh temporary workspace.
        """
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        root = Path(self.temporary.name)
        self.flight_root = root / "flights"
        self.flight_root.mkdir()
        self.session_id = "c" * 32
        self.session_directory = self.flight_root / self.session_id
        self.session_directory.mkdir()
        self.lease = WorkspaceLease(self.flight_root)
        self.addCleanup(self.lease.release)
        self.watch_folder = root / "screenshots"
        self.watch_folder.mkdir()
        self.historical_photo = self.watch_folder / "existing.png"
        self.pending_photo = self.watch_folder / "accepted.png"
        self.historical_photo.write_bytes(b"pre-update historical screenshot")
        self.pending_photo.write_bytes(b"accepted before the update")
        self.binding = "a" * 64
        self.plan = SessionPlan(
            directory=self.session_directory,
            binding=self.binding,
            languages=(),
            reports_enabled=False,
        )
        self.plan.save()
        MAIN.atomic_write_json(self.session_directory / "watch-drain.json", {
            "schema_version": 1,
            "folder": str(self.watch_folder),
            "recursive": False,
            "initial": [str(self.historical_photo)],
            "pending": [str(self.pending_photo)],
            "initial_captured": True,
            "frozen": False,
            "cutoff": 0,
            "checkpoint": time.time(),
            "error": "",
        })
        self.record = WatcherContinuation(
            session_id=self.session_id,
            binding=self.binding,
            profile_hash=WatcherContinuation.profile_digest(self.flight_root),
            source_version="1.0.0",
            target_version=MAIN.APP_VERSION,
            update_session="d" * 32,
            mode="legacy",
            contextual=False,
            created=time.time(),
            phase="claimed",
        )
        self.record.save(self.lease)

    def make_watcher(self) -> tuple[MAIN.WatcherThread, queue.Queue[Any], list[Path]]:
        """Create a real continuation-mode worker with only transport seams mocked.

        Returns:
            tuple[MAIN.WatcherThread, queue.Queue[Any], list[Path]]: Unstarted
                worker, its event queue and paths successfully dispatched.
        """
        config = MAIN.WatcherConfig(
            gallery_url="https://gallery.example.test",
            api_key="fixture-api-key",
            watched_folder=str(self.watch_folder),
            watch_recursive=False,
            delete_uploaded_files=False,
            scan_interval_seconds=0.0,
            stable_seconds=0.0,
        )
        events: queue.Queue[Any] = queue.Queue()
        with mock.patch.object(MAIN, "FileStabilityTracker") as stability_factory, \
                mock.patch.object(MAIN, "RemoteInventorySession") as inventory_factory, \
                mock.patch.object(MAIN, "SimConnectCameraClient") as simconnect_factory:
            simconnect_factory.return_value.diagnostics_snapshot.return_value = {}
            worker = MAIN.WatcherThread(config, events)
        worker.stability = stability_factory.return_value
        worker.stability.stable.return_value = True
        worker.remote_inventory = inventory_factory.return_value
        worker.remote_inventory.refresh.return_value = True
        worker.remote_inventory.has_hash.return_value = False
        worker.sim_camera_metadata_fields = mock.Mock(return_value={})
        dispatched: list[Path] = []

        def upload(
            _upload_url: str, path: Path, _metadata_fields: dict[str, str],
            _file_hash: str, _expected_size: int,
        ) -> dict[str, int]:
            """Record a synthetic accepted upload and stop after two sources.

            Args:
                _upload_url (str): Fixed fixture endpoint.
                path (Path): Immutable screenshot selected for dispatch.
                _metadata_fields (dict[str, str]): Captured image metadata.
                _file_hash (str): SHA-256 captured before request admission.
                _expected_size (int): Captured source size in bytes.

            Returns:
                dict[str, int]: Minimal acknowledged upload response.
            """
            dispatched.append(path)
            if len(dispatched) == 2:
                worker.stop_event.set()
            return {"uploaded": 1, "scanned": 1}

        worker.upload_watched_file = mock.Mock(side_effect=upload)
        worker.configure_drain(
            self.session_directory, (), resume=True, continuation=True,
        )
        return worker, events, dispatched

    def test_host_preparation_restores_native_slash_spelling(self) -> None:
        """Prepare the original worker and recorder from equivalent Windows paths.

        Returns:
            None: Forward-slash manifest spelling restores the exact folder,
                persisted queue and original recorder identity.
        """
        gallery_url = "https://gallery.example.test"
        api_key = "fixture-api-key"
        binding = MAIN.connection_binding(
            MAIN.normalize_upload_url(gallery_url), api_key,
        )
        plan = replace(self.plan, binding=binding)
        plan.save()
        record = replace(
            self.record, binding=binding, mode="legacy", phase="prepared",
        )
        record.save(self.lease)
        manifest_path = self.session_directory / "watch-drain.json"
        row = MAIN.read_document(manifest_path)
        row["folder"] = str(self.watch_folder).replace("\\", "/")
        MAIN.atomic_write_json(manifest_path, row)
        config = MAIN.WatcherConfig(
            gallery_url=gallery_url,
            api_key=api_key,
            watched_folder=str(self.watch_folder),
            watch_recursive=False,
            delete_uploaded_files=False,
        )
        config._snapshot_token = object()
        identity = MAIN.normalize_upload_url(gallery_url)
        saved_recorder = MAIN.FlightRecorder(
            self.flight_root, identity, config.simconnect_dll_path,
            lambda _level, _message: None, _session_id=self.session_id,
        )
        saved_recorder._checkpoint()

        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = record
        app._update_resume_preparing = False
        app._update_resume_context_target = None
        app.exiting = False
        app.worker = None
        app.config = config
        app.config_store = SimpleNamespace(
            mode_port=mock.Mock(return_value=mock.Mock()),
        )
        app.mode_owner = mock.Mock()
        app.events = queue.Queue()
        app.flights = SimpleNamespace(
            lease=self.lease, directory=self.flight_root,
            binding=mock.Mock(return_value=binding),
        )
        app._set_watch_start_activity = mock.Mock()

        def inline_thread(
            *, target: Callable[[], None], name: str, daemon: bool,
        ) -> SimpleNamespace:
            """Run saved-session preparation synchronously for this fixture.

            Args:
                target (Callable[[], None]): Host preparation callback.
                name (str): Fixed background worker name from the host.
                daemon (bool): Host daemon-thread setting.

            Returns:
                SimpleNamespace: Thread-shaped object whose start runs callback.
            """
            return SimpleNamespace(start=target)

        with mock.patch.object(MAIN, "FileStabilityTracker"), \
                mock.patch.object(MAIN, "RemoteInventorySession"), \
                mock.patch.object(MAIN, "SimConnectCameraClient") as simconnect_factory, \
                mock.patch.object(MAIN.threading, "Thread", side_effect=inline_thread):
            simconnect_factory.return_value.diagnostics_snapshot.return_value = {}
            self.assertTrue(MAIN.WatcherApp._prepare_update_resume(app, None))

        event = wait_for_event_kind(app.events, "watcher_resume_prepared")
        worker = event["payload"]["worker"]
        recorder = event["payload"]["recorder"]
        self.assertEqual(self.watch_folder, worker._watch_folder_override)
        self.assertTrue(worker.initial_captured)
        self.assertEqual({self.historical_photo}, worker.initial_paths)
        self.assertEqual({self.pending_photo}, worker.accepted_paths)
        self.assertEqual(self.session_id, recorder.session_id)
        self.assertEqual(self.session_directory, recorder.directory)
        self.assertEqual(
            [str(self.historical_photo)],
            MAIN.read_document(manifest_path)["initial"],
        )

    def test_host_preparation_failure_diagnostic_omits_exception_text(self) -> None:
        """Report the failed preparation operation without private exception data.

        Returns:
            None: Fixed stage, operation and exception class are queued without
                the exception message or local path.
        """
        gallery_url = "https://gallery.example.test"
        api_key = "fixture-api-key"
        config = MAIN.WatcherConfig(
            gallery_url=gallery_url,
            api_key=api_key,
            watched_folder=str(self.watch_folder),
        )
        config._snapshot_token = object()
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = self.record
        app._update_resume_preparing = False
        app._update_resume_context_target = None
        app.exiting = False
        app.worker = None
        app.config = config
        app.config_store = SimpleNamespace(
            mode_port=mock.Mock(return_value=mock.Mock()),
        )
        app.mode_owner = mock.Mock()
        app.events = queue.Queue()
        app.flights = SimpleNamespace(
            lease=self.lease, directory=self.flight_root,
            binding=mock.Mock(return_value=self.binding),
        )
        app._set_watch_start_activity = mock.Mock()
        secret = "secret credential C:\\private\\watch-drain.json"

        def inline_thread(
            *, target: Callable[[], None], name: str, daemon: bool,
        ) -> SimpleNamespace:
            """Run the failed preparation callback synchronously.

            Args:
                target (Callable[[], None]): Host preparation callback.
                name (str): Fixed background worker name from the host.
                daemon (bool): Host daemon-thread setting.

            Returns:
                SimpleNamespace: Thread-shaped object whose start runs callback.
            """
            return SimpleNamespace(start=target)

        with mock.patch.object(MAIN.threading, "Thread", side_effect=inline_thread), \
                mock.patch.object(MAIN, "read_document", side_effect=RuntimeError(secret)):
            self.assertTrue(MAIN.WatcherApp._prepare_update_resume(app, None))

        diagnostic = app.events.get_nowait()
        failure = app.events.get_nowait()
        self.assertEqual("warning", diagnostic[0])
        self.assertIn("stage=resume_prepare", diagnostic[1])
        self.assertIn("operation=read_watch_drain", diagnostic[1])
        self.assertIn("error_type=RuntimeError", diagnostic[1])
        self.assertNotIn(secret, diagnostic[1])
        self.assertNotIn("private\\watch-drain.json", diagnostic[1])
        self.assertEqual("watcher_resume_failed", failure["kind"])
        self.assertEqual(
            "saved_session_needs_review", failure["payload"]["reason"],
        )

    def make_host(self, worker: MAIN.WatcherThread) -> MAIN.WatcherApp:
        """Build only the host fields used by durable resume completion.

        Args:
            worker (MAIN.WatcherThread): Continuation worker waiting at its gate.

        Returns:
            MAIN.WatcherApp: Constructor-bypassed host with a real workspace lease.
        """
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = self.record
        app._update_resume_preparing = True
        app._resume_retry_available = True
        app._update_resume_status = "stage=resume; reason=waiting_for_worker"
        app._update_resume_context_target = None
        app._update_resume_context_key_closing = False
        app.exiting = False
        app.flights = SimpleNamespace(lease=self.lease, stop=mock.Mock())
        app.worker = worker
        app._set_watch_start_activity = mock.Mock()
        app.write_log = mock.Mock()
        initialize_event_pump_fixture(app)
        return app

    def test_dispatch_waits_for_consumed_marker_and_keeps_original_boundary(self) -> None:
        """Do not scan until the same session's one-use marker is consumed.

        Returns:
            None: Accepted and post-restart screenshots upload after durable
                consumption; the original startup exclusion stays excluded.
        """
        worker, events, dispatched = self.make_watcher()
        worker.start()
        wait_for_event_kind(events, "watcher_start_ready")
        worker.upload_watched_file.assert_not_called()
        before = MAIN.read_document(self.session_directory / "watch-drain.json")
        self.assertEqual([str(self.pending_photo)], before["pending"])
        self.assertEqual([str(self.historical_photo)], before["initial"])

        new_photo = self.watch_folder / "after-restart.png"
        new_photo.write_bytes(b"created while continuation awaited consent")
        app = self.make_host(worker)
        self.assertTrue(MAIN.WatcherApp._complete_update_resume(app))
        self.assertEqual("consumed", WatcherContinuation.load(self.lease).phase)
        worker.join(TEST_WORKER_JOIN_SECONDS)

        self.assertFalse(worker.is_alive())
        self.assertCountEqual([self.pending_photo, new_photo], dispatched)
        self.assertNotIn(self.historical_photo, dispatched)
        final_queue = MAIN.read_document(self.session_directory / "watch-drain.json")
        self.assertEqual([], final_queue["pending"])
        self.assertEqual([str(self.historical_photo)], final_queue["initial"])
        self.assertEqual(self.session_id, SessionPlan.load(self.session_directory).session_id)

    def test_gate_timeout_and_stop_retain_queue_without_dispatch(self) -> None:
        """A lost host acknowledgement or explicit stop cannot send a photo.

        Returns:
            None: Timeout records a safe reason and stop exits; both retain the
                exact accepted pending path without freezing its boundary.
        """
        with mock.patch.object(
            MAIN, "WATCHER_CONTINUATION_ADMISSION_TIMEOUT_SECONDS",
            TEST_GATE_TIMEOUT_SECONDS,
        ):
            worker, events, dispatched = self.make_watcher()
            worker.start()
            wait_for_event_kind(events, "watcher_start_ready")
            wait_for_event_kind(events, "watcher_start_finished")
            worker.join(TEST_WORKER_JOIN_SECONDS)
        self.assertEqual([], dispatched)
        self.assertIn("confirmation timed out", worker.drain_error)
        timed_out = MAIN.read_document(self.session_directory / "watch-drain.json")
        self.assertEqual([str(self.pending_photo)], timed_out["pending"])
        self.assertFalse(timed_out["frozen"])

        timed_out["error"] = ""
        MAIN.atomic_write_json(
            self.session_directory / "watch-drain.json", timed_out,
        )
        self.record.save(self.lease)
        stopped_worker, stopped_events, stopped_dispatch = self.make_watcher()
        stopped_worker.start()
        wait_for_event_kind(stopped_events, "watcher_start_ready")
        stopped_worker.stop()
        wait_for_event_kind(stopped_events, "watcher_start_finished")
        stopped_worker.join(TEST_WORKER_JOIN_SECONDS)
        self.assertEqual([], stopped_dispatch)
        retained = MAIN.read_document(self.session_directory / "watch-drain.json")
        self.assertEqual([str(self.pending_photo)], retained["pending"])
        self.assertFalse(retained["frozen"])

    def test_ordinary_watcher_has_no_update_admission_gate(self) -> None:
        """Keep the new continuation barrier out of ordinary Watcher starts.

        Returns:
            None: A newly constructed normal watcher has no gate to wait for or
                release, so its existing startup contract remains unchanged.
        """
        config = MAIN.WatcherConfig(
            gallery_url="https://gallery.example.test",
            api_key="fixture-api-key",
            watched_folder=str(self.watch_folder),
            watch_recursive=False,
            delete_uploaded_files=False,
        )
        with mock.patch.object(MAIN, "FileStabilityTracker"), \
                mock.patch.object(MAIN, "RemoteInventorySession"), \
                mock.patch.object(MAIN, "SimConnectCameraClient") as simconnect:
            simconnect.return_value.diagnostics_snapshot.return_value = {}
            worker = MAIN.WatcherThread(config, queue.Queue())
        self.assertIsNone(worker._continuation_gate)
        worker.admit_continuation()
        self.assertIsNone(worker._continuation_gate)

    def test_failed_consumption_save_pauses_before_first_dispatch(self) -> None:
        """A marker write failure blocks the gate and retains accepted work.

        Returns:
            None: No photo is sent when the consumed phase cannot be saved.
        """
        worker, events, dispatched = self.make_watcher()
        worker.start()
        wait_for_event_kind(events, "watcher_start_ready")
        app = self.make_host(worker)
        with mock.patch.object(MAIN.WatcherApp, "_update_resume_failed") as failed, \
                mock.patch.object(
                    WatcherContinuation, "save",
                    side_effect=OSError("fixture storage refusal"),
                ):
            self.assertFalse(MAIN.WatcherApp._complete_update_resume(app))
        failed.assert_called_once_with("continuation_consumption_unverified")
        wait_for_event_kind(events, "watcher_start_finished")
        worker.join(TEST_WORKER_JOIN_SECONDS)
        self.assertEqual([], dispatched)
        self.assertEqual("claimed", WatcherContinuation.load(self.lease).phase)
        retained = MAIN.read_document(self.session_directory / "watch-drain.json")
        self.assertEqual([str(self.pending_photo)], retained["pending"])

    def test_stop_during_consumption_save_keeps_claimed_marker_and_closed_gate(self) -> None:
        """A timeout during durable consumption cannot strand consumed state.

        Returns:
            None: The claimed marker is restored while no source is dispatched;
                the timed-out queue remains explicitly marked for review.
        """
        worker, events, dispatched = self.make_watcher()
        worker.start()
        wait_for_event_kind(events, "watcher_start_ready")
        app = self.make_host(worker)
        original_save = WatcherContinuation.save

        def stop_after_consumed_save(
            record: WatcherContinuation, lease: WorkspaceLease,
        ) -> None:
            """Set the worker timeout immediately after consumed is durable.

            Args:
                record (WatcherContinuation): Marker being persisted.
                lease (WorkspaceLease): Exact test workspace lease.

            Returns:
                None: Writes the real marker and then blocks safe admission.
            """
            original_save(record, lease)
            if record.phase == "consumed":
                worker.drain_error = (
                    "Update resume confirmation timed out; pending photographs are retained."
                )
                worker.stop()

        with mock.patch.object(
            WatcherContinuation, "save", autospec=True,
            side_effect=stop_after_consumed_save,
        ):
            self.assertFalse(MAIN.WatcherApp._complete_update_resume(app))
        self.assertEqual("claimed", WatcherContinuation.load(self.lease).phase)
        self.assertFalse(worker._continuation_gate.is_set())
        self.assertEqual(
            "stage=resume; reason=saved_session_needs_review",
            app._update_resume_status,
        )
        wait_for_event_kind(events, "watcher_start_finished")
        worker.join(TEST_WORKER_JOIN_SECONDS)
        self.assertEqual([], dispatched)
        retained = MAIN.read_document(self.session_directory / "watch-drain.json")
        self.assertIn("confirmation timed out", retained["error"])
        self.assertEqual([str(self.pending_photo)], retained["pending"])

    def test_stale_prepared_result_closes_its_owned_context_key(self) -> None:
        """A stale async result releases both queue and temporary key owners.

        Returns:
            None: A stale worker cannot start and the staged key closes exactly
                once before its temporary target selection is detached.
        """
        session = mock.Mock()
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        target = LinkedUploadTarget(
            session, "https://gallery.example.test", 19, 73,
            "Fixture gallery", "temporary-watch-key",
        )
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = self.record
        app._update_resume_context_target = target
        app._update_resume_context_key_closing = False
        app.exiting = False
        app.events = queue.Queue()
        app.linking_manager = mock.Mock()
        target_lease = mock.Mock()
        worker = SimpleNamespace(
            _linked_lease=target_lease,
            start=mock.Mock(),
            stop=mock.Mock(),
        )
        recorder = mock.Mock()

        def inline_thread(
            *, target: Callable[[], None], name: str, daemon: bool,
        ) -> SimpleNamespace:
            """Run the isolated close worker inline for deterministic evidence.

            Args:
                target (Callable[[], None]): Close worker callback.
                name (str): Fixed background worker name.
                daemon (bool): Daemon-thread policy from the host.

            Returns:
                SimpleNamespace: Thread-shaped object with an inline start.
            """
            return SimpleNamespace(start=target)

        with mock.patch.object(MAIN.threading, "Thread", side_effect=inline_thread):
            MAIN.WatcherApp._start_prepared_update_resume(
                app, replace(self.record, update_session="e" * 32),
                worker, recorder, target,
            )
            MAIN.WatcherApp._finish_update_resume_context_key_close(
                app, target, True,
            )

        target_lease.release.assert_called_once_with()
        worker.start.assert_not_called()
        session.close_watch_key.assert_called_once_with(73, "temporary-watch-key")
        self.assertIsNone(app._update_resume_context_target)
        app.linking_manager.detach_photos_watcher_target.assert_called_once_with(target)

    def test_failed_resume_closes_transferred_context_key_once(self) -> None:
        """Async preparation failure revokes the key accepted by the host.

        Returns:
            None: The exact staged key is closed off the UI path and its
                temporary target is detached only after confirmed closure.
        """
        session = mock.Mock()
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        target = LinkedUploadTarget(
            session, "https://gallery.example.test", 19, 73,
            "Fixture gallery", "temporary-watch-key",
        )
        app = object.__new__(MAIN.WatcherApp)
        app.exiting = False
        app._resume_continuation = self.record
        app._update_resume_preparing = True
        app._update_resume_context_target = target
        app._update_resume_context_key_closing = False
        app.events = queue.Queue()
        app.linking_manager = mock.Mock()
        app.status_var = mock.Mock()
        app._set_watch_start_activity = mock.Mock()
        app.write_log = mock.Mock()

        def inline_thread(
            *, target: Callable[[], None], name: str, daemon: bool,
        ) -> SimpleNamespace:
            """Provide deterministic completion for one local revoke worker.

            Args:
                target (Callable[[], None]): Worker callback supplied by host.
                name (str): Fixed background worker name.
                daemon (bool): Daemon-thread policy from the host.

            Returns:
                SimpleNamespace: Object whose start runs the worker inline.
            """
            return SimpleNamespace(start=target)

        with mock.patch.object(MAIN.threading, "Thread", side_effect=inline_thread):
            MAIN.WatcherApp._update_resume_failed(
                app, "saved_session_needs_review",
            )
            MAIN.WatcherApp._close_update_resume_context_key(app)

        session.close_watch_key.assert_called_once_with(73, "temporary-watch-key")
        event = wait_for_event_kind(app.events, "update_resume_context_key_closed")
        self.assertIs(target, event["payload"]["target"])
        MAIN.WatcherApp._finish_update_resume_context_key_close(
            app, target, event["payload"]["closed"],
        )
        self.assertIsNone(app._update_resume_context_target)
        app.linking_manager.detach_photos_watcher_target.assert_called_once_with(target)

    def test_unconfirmed_old_key_cannot_be_replaced_by_resume_retry(self) -> None:
        """A retry cannot overwrite a staged key before close confirmation.

        Returns:
            None: The existing owner remains attached and no new async
                preparation is accepted while revocation is outstanding.
        """
        old_session = mock.Mock()
        old_session.can_upload.return_value = True
        old_session.matches_issuer.return_value = True
        old_target = LinkedUploadTarget(
            old_session, "https://gallery.example.test", 19, 73,
            "Fixture gallery", "old-temporary-key",
        )
        new_session = mock.Mock()
        new_session.can_upload.return_value = True
        new_session.matches_issuer.return_value = True
        new_session.approved_scope.return_value = UPLOAD_SCOPE
        new_target = LinkedUploadTarget(
            new_session, "https://gallery.example.test", 19, 73,
            "Fixture gallery", "new-temporary-key",
        )
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = replace(
            self.record, binding=old_target.binding(), mode="linked",
            contextual=True, approved_scope=UPLOAD_SCOPE,
        )
        app._update_resume_context_target = old_target
        app._update_resume_context_key_closing = False
        app._update_resume_preparing = False
        app._resume_retry_available = False
        app.exiting = False
        app.linking_manager = mock.Mock()
        app.status_var = mock.Mock()
        app.refresh_watcher_action = mock.Mock()
        app.events = queue.Queue()

        def inline_thread(
            *, target: Callable[[], None], name: str, daemon: bool,
        ) -> SimpleNamespace:
            """Run the close request inline without external I/O.

            Args:
                target (Callable[[], None]): Close worker callback.
                name (str): Fixed background worker name.
                daemon (bool): Daemon-thread policy from the host.

            Returns:
                SimpleNamespace: Thread-shaped inline worker.
            """
            return SimpleNamespace(start=target)

        with mock.patch.object(MAIN.threading, "Thread", side_effect=inline_thread):
            MAIN.WatcherApp._accept_update_resume_target(app, new_target)

        self.assertIs(old_target, app._update_resume_context_target)
        self.assertTrue(app._update_resume_context_key_closing)
        old_session.close_watch_key.assert_called_once_with(73, "old-temporary-key")
        app.linking_manager.start_photos_watcher.assert_not_called()
        new_session.close_watch_key.assert_not_called()

    def test_context_key_transfers_only_after_consumed_gate_admission(self) -> None:
        """Keep the staged key available to cleanup until admission succeeds.

        Returns:
            None: A successful one-use marker and gate transfer the same key
                into the active contextual Watcher owner.
        """
        session = mock.Mock()
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        target = LinkedUploadTarget(
            session, "https://gallery.example.test", 19, 73,
            "Fixture gallery", "temporary-watch-key",
        )
        record = replace(
            self.record, binding=target.binding(), mode="linked",
            contextual=True, approved_scope=UPLOAD_SCOPE, phase="claimed",
        )
        record.save(self.lease)
        worker, _events, _dispatched = self.make_watcher()
        worker.linked_target = target
        original_admit = worker.admit_continuation
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = record
        app._update_resume_context_target = target
        app._update_resume_context_key_closing = False
        app._update_resume_preparing = True
        app._resume_retry_available = True
        app.worker = worker
        app.flights = SimpleNamespace(
            lease=self.lease, stop=mock.Mock(),
            plan=SimpleNamespace(reports_enabled=True),
        )
        app.write_log = mock.Mock()

        def admit_after_asserting_ownership() -> bool:
            """Assert staged ownership persists up to gate admission.

            Returns:
                bool: The underlying Watcher gate admission result.
            """
            self.assertIs(target, app._update_resume_context_target)
            return original_admit()

        worker.admit_continuation = admit_after_asserting_ownership

        self.assertTrue(MAIN.WatcherApp._complete_update_resume(app))
        self.assertTrue(worker._continuation_gate.is_set())
        self.assertIs(target, app._contextual_watch_target)
        self.assertIsNone(app._update_resume_context_target)
        self.assertTrue(app._contextual_watch_is_flight)

    def test_finished_resume_worker_clears_preparation_for_recovery(self) -> None:
        """A terminal worker event does not leave Resume permanently busy.

        Returns:
            None: The host clears its preparation reservation and retains a
                reviewable recovery state after the worker exits before REC.
        """
        worker, _events, _dispatched = self.make_watcher()
        app = self.make_host(worker)
        app.events = queue.Queue()
        app.events.put({
            "kind": "watcher_start_finished", "payload": {"worker": worker},
        })
        app.refresh_revoke_button_state = mock.Mock()
        app.refresh_activity_monitor_state = mock.Mock()
        app.refresh_watcher_action = mock.Mock()
        app._set_watch_start_activity = mock.Mock()
        app.status_var = mock.Mock()
        app.write_log = mock.Mock()
        app.utc_sync = SimpleNamespace(status=lambda: "idle")
        app.utc_sync_status_var = mock.Mock()
        app.root = SimpleNamespace(after=mock.Mock())
        worker.drain_error = "Update resume confirmation timed out; pending photographs are retained."
        worker.initial_captured = True
        worker.stop_event.set()

        MAIN.WatcherApp.drain_events(app)

        self.assertFalse(app._update_resume_preparing)
        self.assertIsNone(app._resume_continuation)
        self.assertEqual(
            "stage=resume; reason=saved_session_needs_review",
            app._update_resume_status,
        )
        self.assertIn(
            "needs review", app._set_watch_start_activity.call_args.args[1],
        )

    def test_explicit_quit_cancels_nonce_without_discarding_photo_history(self) -> None:
        """Quit revokes automatic continuation but keeps the same session data.

        Returns:
            None: The nonce phase becomes cancelled and the pending queue remains.
        """
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = self.record
        app._update_continuation = None
        app._resume_retry_available = True
        app.flights = SimpleNamespace(lease=self.lease)
        app.write_log = mock.Mock()
        MAIN.WatcherApp._discard_update_resume_intent(app)
        self.assertEqual("cancelled", WatcherContinuation.load(self.lease).phase)
        self.assertFalse(app._resume_retry_available)
        retained = MAIN.read_document(self.session_directory / "watch-drain.json")
        self.assertEqual([str(self.pending_photo)], retained["pending"])
        marker = json.loads(
            (self.flight_root / "watcher-continuation.json").read_text(
                encoding="utf-8",
            ),
        )
        self.assertNotIn("api_key", marker)
        self.assertNotIn("watch_key", marker)

    def test_quit_after_failed_resume_cancels_only_the_offered_intent(self) -> None:
        """Quit cancels an offered retry without touching retained files.

        Returns:
            None: Offered current-profile intent is cancelled; ordinary startup
                without an offered retry leaves the marker unchanged.
        """
        gallery_url = "https://gallery.example.test"
        api_key = "fixture-api-key"
        binding = MAIN.connection_binding(
            MAIN.normalize_upload_url(gallery_url), api_key,
        )
        offered = replace(
            self.record, binding=binding, mode="legacy", phase="prepared",
        )
        offered.save(self.lease)
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = None
        app._update_continuation = None
        app._resume_retry_available = True
        app._after_update_nonce = offered.update_session
        app.flights = SimpleNamespace(lease=self.lease)
        app.mode_owner = SimpleNamespace(
            view=mock.Mock(return_value=SimpleNamespace(
                state="legacy", record=None,
            )),
        )
        app.config = SimpleNamespace(gallery_url=gallery_url, api_key=api_key)
        app.write_log = mock.Mock()
        MAIN.WatcherApp._discard_update_resume_intent(app)

        self.assertEqual("cancelled", WatcherContinuation.load(self.lease).phase)
        self.assertTrue(self.pending_photo.is_file())
        retained = MAIN.read_document(self.session_directory / "watch-drain.json")
        self.assertEqual([str(self.pending_photo)], retained["pending"])

        offered.save(self.lease)
        ordinary_startup = object.__new__(MAIN.WatcherApp)
        ordinary_startup._resume_continuation = None
        ordinary_startup._update_continuation = None
        ordinary_startup._resume_retry_available = False
        ordinary_startup.flights = SimpleNamespace(lease=self.lease)
        ordinary_startup._close_update_resume_context_key = mock.Mock()
        MAIN.WatcherApp._discard_update_resume_intent(ordinary_startup)
        self.assertEqual("prepared", WatcherContinuation.load(self.lease).phase)

    def test_inspection_rejects_wrong_nonce_profile_and_consumed_marker(self) -> None:
        """Only the current prepared nonce and this profile can offer resume.

        Returns:
            None: Wrong restart nonce, changed profile and already consumed
                records produce fixed safe failure events, never a resume offer.
        """
        cases = (
            (self.record, "e" * 32),
            (replace(self.record, phase="consumed"), self.record.update_session),
        )
        for record, requested_nonce in cases:
            with self.subTest(phase=record.phase, nonce=requested_nonce[:1]):
                record.save(self.lease)
                app = object.__new__(MAIN.WatcherApp)
                app._update_resume_attempted = False
                app._gui_mode_ready = True
                app._after_update_nonce = requested_nonce
                app.flights = SimpleNamespace(lease=self.lease)
                app.events = queue.Queue()
                MAIN.WatcherApp._inspect_update_continuation(app)
                event = wait_for_event_kind(app.events, "watcher_resume_failed")
                self.assertEqual(
                    "saved_update_state_unverified", event["payload"]["reason"],
                )

        changed_profile = self.record.payload()
        changed_profile["profile_hash"] = "b" * 64
        MAIN.atomic_write_json(
            self.flight_root / "watcher-continuation.json", changed_profile,
        )
        app = object.__new__(MAIN.WatcherApp)
        app._update_resume_attempted = False
        app._gui_mode_ready = True
        app._after_update_nonce = self.record.update_session
        app.flights = SimpleNamespace(lease=self.lease)
        app.events = queue.Queue()
        MAIN.WatcherApp._inspect_update_continuation(app)
        event = wait_for_event_kind(app.events, "watcher_resume_failed")
        self.assertEqual("saved_update_state_unverified", event["payload"]["reason"])

    def test_stale_prepared_worker_event_releases_without_starting_uploads(self) -> None:
        """A delayed result for an earlier claim cannot start its prepared worker.

        Returns:
            None: Stale async preparation is discarded and its owned linked
                lease is released without adopting a plan or dispatching a photo.
        """
        stale = replace(self.record, update_session="e" * 32)
        target_lease = mock.Mock()
        worker = SimpleNamespace(
            _linked_lease=target_lease,
            start=mock.Mock(),
            stop=mock.Mock(),
        )
        recorder = mock.Mock()
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = self.record
        app.exiting = False
        app.flights = mock.Mock()
        MAIN.WatcherApp._start_prepared_update_resume(
            app, stale, worker, recorder, None,
        )
        target_lease.release.assert_called_once_with()
        worker.start.assert_not_called()
        app.flights.resume_session.assert_not_called()

    def test_parser_accepts_only_an_exclusive_canonical_update_nonce(self) -> None:
        """Startup restart metadata has one fixed nonce shape and mode.

        Returns:
            None: A canonical nonce is parsed; malformed and mixed modes exit.
        """
        nonce = "f" * 32
        self.assertEqual(nonce, MAIN.parse_args(["--after-update", nonce]).after_update)
        with self.assertRaises(SystemExit):
            MAIN.parse_args(["--after-update", "not-a-nonce"])
        with self.assertRaises(SystemExit):
            MAIN.parse_args(["--once", "--after-update", nonce])

    def test_legacy_bridge_waits_for_explicit_resume_but_nonce_is_automatic(self) -> None:
        """Only the old-helper bridge needs a user confirmation after restart.

        Returns:
            None: A legacy candidate exposes retry without starting it; a
                current nonce-bound update begins its verified resume path.
        """
        def make_event_host(automatic: bool) -> MAIN.WatcherApp:
            """Create the small event-drain surface used by this policy check.

            Args:
                automatic (bool): Whether the updater supplied its exact nonce.

            Returns:
                MAIN.WatcherApp: Constructor-bypassed host with only the
                    continuation event and drain tail dependencies.
            """
            app = object.__new__(MAIN.WatcherApp)
            app.exiting = False
            app.events = queue.Queue()
            app.events.put({
                "kind": "watcher_resume_available",
                "payload": {"continuation": self.record, "automatic": automatic},
            })
            app._resume_continuation = None
            app._resume_retry_available = False
            app._continue_update_resume = mock.Mock()
            app.status_var = mock.Mock()
            app.refresh_watcher_action = mock.Mock()
            app.utc_sync = SimpleNamespace(status=lambda: "idle")
            app.utc_sync_status_var = mock.Mock()
            app.refresh_activity_monitor_state = mock.Mock()
            app.refresh_revoke_button_state = mock.Mock()
            app.root = SimpleNamespace(after=mock.Mock())
            initialize_event_pump_fixture(app)
            return app

        legacy_host = make_event_host(False)
        MAIN.WatcherApp.drain_events(legacy_host)
        legacy_host._continue_update_resume.assert_not_called()
        self.assertTrue(legacy_host._resume_retry_available)
        self.assertIn(
            "older version", legacy_host.status_var.set.call_args.args[0],
        )
        legacy_host.flights = SimpleNamespace(lease=self.lease)
        self.assertTrue(MAIN.WatcherApp._retry_update_continuation(legacy_host))
        legacy_host._continue_update_resume.assert_called_once_with()

        automatic_host = make_event_host(True)
        MAIN.WatcherApp.drain_events(automatic_host)
        automatic_host._continue_update_resume.assert_called_once_with()

    def test_linked_resume_refuses_a_changed_approved_scope_before_verification(self) -> None:
        """A live account with a different consent scope cannot resume the target.

        Returns:
            None: Scope drift stops before browser/key prompts or target requests.
        """
        issuer = "https://gallery.example.test/Galerie"
        account_id = 19
        gallery_id = 73
        binding = linked_target_binding(issuer, account_id, gallery_id)
        linked_record = replace(
            self.record, binding=binding, mode="linked",
            approved_scope=UPLOAD_SCOPE,
        )
        self.assertEqual(
            linked_record,
            WatcherContinuation.from_payload(
                linked_record.payload(), self.flight_root,
            ),
        )
        live_session = mock.Mock()
        live_session.approved_scope.return_value = UPLOAD_OFFLINE_SCOPE
        mode_record = SimpleNamespace(
            mode="linked", issuer=issuer, account_id=account_id,
            gallery_id=gallery_id, scope=UPLOAD_SCOPE,
        )
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = linked_record
        app._update_resume_preparing = False
        app._update_checkpoint_workers = ()
        app._update_resume_deadline = (
            time.monotonic() + TEST_RESUME_DEADLINE_SECONDS
        )
        app.exiting = False
        app.mode_owner = SimpleNamespace(
            view=mock.Mock(return_value=SimpleNamespace(
                state="linked_active", record=mode_record,
            )),
        )
        app.linking_manager = mock.Mock()
        app.linking_manager.resume_upload_session.return_value = live_session
        app.root = SimpleNamespace(after=mock.Mock())
        app._update_resume_failed = mock.Mock()
        app.events = queue.Queue()

        with mock.patch.object(MAIN.threading, "Thread") as thread_factory:
            MAIN.WatcherApp._continue_update_resume(app)

        app.linking_manager.resume_upload_session.assert_called_once_with(
            issuer, account_id,
        )
        live_session.approved_scope.assert_called_once_with()
        app._update_resume_failed.assert_called_once_with("saved_target_changed")
        thread_factory.assert_not_called()
        app.linking_manager.start_photos_watcher.assert_not_called()

    def test_linked_resume_verifies_and_queues_the_exact_saved_target(self) -> None:
        """Reverify the saved issuer, account, scope and gallery off the UI path.

        Returns:
            None: The exact freshly verified target is queued for host adoption.
        """
        issuer = "https://gallery.example.test/Galerie"
        account_id = 19
        gallery_id = 73
        binding = linked_target_binding(issuer, account_id, gallery_id)
        record = replace(
            self.record, binding=binding, mode="linked",
            approved_scope=UPLOAD_SCOPE,
        )
        session = mock.Mock()
        session.approved_scope.return_value = UPLOAD_SCOPE
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        target = LinkedUploadTarget(
            session, issuer, account_id, gallery_id,
            "Fixture gallery",
        )
        mode_record = SimpleNamespace(
            mode="linked", issuer=issuer, account_id=account_id,
            gallery_id=gallery_id, scope=UPLOAD_SCOPE,
        )
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = record
        app._update_resume_preparing = False
        app._update_checkpoint_workers = ()
        app._update_resume_deadline = (
            time.monotonic() + TEST_RESUME_DEADLINE_SECONDS
        )
        app.exiting = False
        app.mode_owner = SimpleNamespace(
            view=mock.Mock(return_value=SimpleNamespace(
                state="linked_active", record=mode_record,
            )),
        )
        app.linking_manager = mock.Mock()
        app.linking_manager.resume_upload_session.return_value = session
        app.events = queue.Queue()

        def inline_thread(
            *, target: Callable[[], None], name: str, daemon: bool,
        ) -> SimpleNamespace:
            """Run only the verifier callback synchronously for this test.

            Args:
                target (Callable[[], None]): Verification worker callback.
                name (str): Fixed background worker name from the host.
                daemon (bool): Host daemon-thread setting.

            Returns:
                SimpleNamespace: Thread-shaped object whose start runs callback.
            """
            return SimpleNamespace(start=target)

        with mock.patch.object(MAIN.threading, "Thread", side_effect=inline_thread), \
                mock.patch.object(
                    MAIN, "verify_linked_upload_target", return_value=target,
                ) as verify_target:
            MAIN.WatcherApp._continue_update_resume(app)

        app.linking_manager.resume_upload_session.assert_called_once_with(
            issuer, account_id,
        )
        verify_target.assert_called_once_with(
            session, issuer, account_id, UPLOAD_SCOPE, gallery_id,
        )
        event = wait_for_event_kind(app.events, "watcher_resume_target")
        self.assertIs(target, event["payload"]["target"])
        self.assertTrue(app._update_resume_preparing)

    def test_linked_resume_verifier_diagnostic_excludes_exception_message(self) -> None:
        """Keep verifier failure useful without recording remote or secret text.

        Returns:
            None: Fixed operation and exception class are queued without message.
        """
        issuer = "https://gallery.example.test/Galerie"
        account_id = 19
        gallery_id = 73
        binding = linked_target_binding(issuer, account_id, gallery_id)
        record = replace(
            self.record, binding=binding, mode="linked",
            approved_scope=UPLOAD_SCOPE,
        )
        session = mock.Mock()
        session.approved_scope.return_value = UPLOAD_SCOPE
        mode_record = SimpleNamespace(
            mode="linked", issuer=issuer, account_id=account_id,
            gallery_id=gallery_id, scope=UPLOAD_SCOPE,
        )
        app = object.__new__(MAIN.WatcherApp)
        app._resume_continuation = record
        app._update_resume_preparing = False
        app._update_checkpoint_workers = ()
        app._update_resume_deadline = (
            time.monotonic() + TEST_RESUME_DEADLINE_SECONDS
        )
        app.exiting = False
        app.mode_owner = SimpleNamespace(
            view=mock.Mock(return_value=SimpleNamespace(
                state="linked_active", record=mode_record,
            )),
        )
        app.linking_manager = mock.Mock()
        app.linking_manager.resume_upload_session.return_value = session
        app.events = queue.Queue()

        def inline_thread(
            *, target: Callable[[], None], name: str, daemon: bool,
        ) -> SimpleNamespace:
            """Run the isolated verifier callback synchronously.

            Args:
                target (Callable[[], None]): Verification worker callback.
                name (str): Fixed background worker name from the host.
                daemon (bool): Host daemon-thread setting.

            Returns:
                SimpleNamespace: Thread-shaped object whose start runs callback.
            """
            return SimpleNamespace(start=target)

        with mock.patch.object(MAIN.threading, "Thread", side_effect=inline_thread), \
                mock.patch.object(
                    MAIN, "verify_linked_upload_target",
                    side_effect=RuntimeError("secret token and gallery URL"),
                ) as verify_target:
            MAIN.WatcherApp._continue_update_resume(app)

        verify_target.assert_called_once_with(
            session, issuer, account_id, UPLOAD_SCOPE, gallery_id,
        )
        safe_event = app.events.get_nowait()
        failure_event = app.events.get_nowait()
        self.assertEqual("warning", safe_event[0])
        self.assertIn("operation=verify_linked_upload_target", safe_event[1])
        self.assertIn("error_type=RuntimeError", safe_event[1])
        self.assertNotIn("secret token", safe_event[1])
        self.assertNotIn(issuer, safe_event[1])
        self.assertEqual("watcher_resume_failed", failure_event["kind"])
        self.assertEqual(
            "saved_gallery_access_unverified",
            failure_event["payload"]["reason"],
        )

    def test_linked_manager_uses_only_the_same_restored_account_and_target(self) -> None:
        """Keep resume inside current approved account and saved target guards.

        Returns:
            None: Existing exact-account approval is reused; another issuer,
                account, binding or scope cannot publish an upload target.
        """
        issuer = "https://gallery.example.test/Galerie"
        account_id = 19
        gallery_id = 73
        binding = linked_target_binding(issuer, account_id, gallery_id)
        session = mock.Mock()
        session.available.return_value = True
        session.can_list_galleries.return_value = True
        session.can_upload.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        site = SimpleNamespace(
            issuer=issuer, user_id=account_id, session=session,
        )
        manager = object.__new__(LinkingManager)
        manager._shutting_down = False
        manager._session = session
        manager._session_issuer = issuer
        manager._session_user_id = account_id
        manager._current_issuer = issuer
        manager._live_links = mock.Mock(return_value=[site])
        manager._show_link = mock.Mock()
        manager._saved_upload_identity = mock.Mock(return_value=(issuer, binding))
        manager._commit_linked_target = mock.Mock(return_value=True)
        manager._target_refusal = mock.Mock(return_value=None)
        manager._notify_target_changed = mock.Mock()
        target = SimpleNamespace(
            session=session, issuer=issuer, user_id=account_id,
            gallery_id=gallery_id, binding=lambda: binding,
        )

        self.assertIs(session, manager.resume_upload_session(issuer, account_id))
        self.assertIsNone(manager.resume_upload_session(issuer, account_id + 1))
        self.assertIsNone(manager.resume_upload_session(issuer + "/other", account_id))
        manager._show_link.assert_not_called()
        self.assertTrue(manager.activate_verified_resume_target(
            target, binding, UPLOAD_SCOPE,
        ))
        manager._commit_linked_target.assert_called_once_with(target)
        manager._saved_upload_identity.return_value = (issuer, "b" * 64)
        self.assertFalse(manager.activate_verified_resume_target(
            target, binding, UPLOAD_SCOPE,
        ))
        manager._saved_upload_identity.return_value = (issuer, binding)
        self.assertFalse(manager.activate_verified_resume_target(
            target, binding, UPLOAD_OFFLINE_SCOPE,
        ))
        self.assertEqual(1, manager._commit_linked_target.call_count)

    def test_diagnostics_keep_safe_browser_runtime_and_update_context(self) -> None:
        """Copy safe updater, rendering and host context without API secrets.

        Returns:
            None: Browser, retained updater failure, numeric rendering facts,
                runtime and Tk summaries are copied with redacted credentials.
                A failed render-counter getter preserves the media failure.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.root = mock.Mock()
        app.root.tk.call.return_value = "8.6.15"
        app.root.clipboard_clear = mock.Mock()
        app.root.clipboard_append = mock.Mock()
        app.current_job = None
        app.media_capabilities = {}
        app.activity_events = []
        app.worker = None
        app.flights = SimpleNamespace(recorder=None)
        app.linking_manager = SimpleNamespace(
            diagnostics_snapshot=lambda: "stage=discovery; reason=server_unavailable",
        )
        updater = UpdateController.__new__(UpdateController)
        updater._diagnostic_latest = None
        updater._diagnostic_last_failure = None
        updater._diagnostic_recent = deque(
            maxlen=UPDATE_DIAGNOSTIC_RECENT_LIMIT,
        )
        updater._retain_diagnostic(UpdateDiagnostic(
            phase='download', stage='http_open', endpoint='installer_asset',
            host='github.com', outcome='failed', elapsed_ms=1200,
            http_status=504, category='http', error_type='HTTPError',
            source='self_update._open',
        ))
        updater._retain_diagnostic(UpdateDiagnostic(
            phase='discovery', stage='metadata_read', endpoint='release_manifest',
            host='github.com', outcome='succeeded', elapsed_ms=42,
            source='self_update._json',
        ))
        app.updates = updater
        photos = PhotosWorkspace.__new__(PhotosWorkspace)
        photos._photo_diagnostic_lock = threading.Lock()
        photos._photo_diagnostic_latest = None
        photos._photo_diagnostic_recent = deque(
            maxlen=PHOTOS_DIAGNOSTIC_RECENT_LIMIT,
        )
        photos._photo_observation_recent = deque(
            maxlen=PHOTOS_OBSERVATION_RECENT_LIMIT,
        )
        photos._diagnostic_callback = None
        photos._generation = 1
        photos._record_photo_diagnostic(
            1,
            'phase=media-fetch kind=thumbnail http=504 reason=http_status',
        )
        photos._record_photo_observation(
            1,
            'shortcut key=f state=0x8 widget=canvas visible=1 '
            'fullscreen=0->1 reason=toggled',
        )
        render_summary = (
            'Photos grid: viewport_events=20 viewport_updates=2 '
            'tk_images_created=8 tk_images_released=0 warm_cache_hits=8 '
            'batches=2 source_disk=4 source_network=4 source_revalidated=0 '
            'disk_ms=2.00 disk_write_ms=1.00 gate_wait_ms=0.50 '
            'renewal_ms=0.25 network_ms=15.00 network_reads=4 '
            'decode_ms=3.00 queue_wait_ms=1.00 tk_ms=2.00 '
            'peak_update_ms=3.25 visible=8 admitted=12 retained=8'
        )
        photos._viewer = SimpleNamespace(
            _gallery_viewer_controller=SimpleNamespace(
                render_diagnostics=lambda: render_summary,
            ),
        )
        app.photos_workspace = photos
        initialize_event_pump_fixture(app)
        app._update_resume_status = "stage=resume; reason=continuation_unverified"
        app.gallery_url_var = SimpleNamespace(get=lambda: "https://gallery.example.test")
        api_secret = "fixture-secret-key"
        app.api_key_var = SimpleNamespace(get=lambda: api_secret)
        app.connection_status_var = SimpleNamespace(get=lambda: "Connected")
        app.connection_detail_var = SimpleNamespace(get=lambda: "Ready")
        app.watcher_activity_var = SimpleNamespace(get=lambda: "Watch: stopped")
        app.utc_sync = SimpleNamespace(status=lambda: "UTC sync: unavailable")
        app.import_activity_var = SimpleNamespace(get=lambda: "Import: idle")
        app.ai_activity_var = SimpleNamespace(get=lambda: "AI: stopped")
        app.write_log = mock.Mock()
        MAIN.WatcherApp.copy_diagnostics(app)
        copied = app.root.clipboard_append.call_args.args[0]
        for expected in (
            "Browser linking: stage=discovery; reason=server_unavailable",
            "Updater diagnostics:\nLatest: [updater] phase=discovery",
            "Last failure: [updater] phase=download",
            "http_status=504",
            "Photos diagnostics:\nLatest failure: phase=media-fetch",
            "http=504 reason=http_status",
            "Recent observations:",
            "shortcut key=f state=0x8",
            render_summary,
            "Update continuation: stage=resume; reason=continuation_unverified",
            "Runtime: source",
            "Tk: 8.6.15",
            "Pillow:",
        ):
            self.assertIn(expected, copied)
        self.assertNotIn(api_secret, copied)
        photos._viewer._gallery_viewer_controller.render_diagnostics = mock.Mock(
            side_effect=ValueError('token=private-render-fixture'),
        )
        with self.assertLogs('uploader.photos_workspace', level='WARNING') as logs:
            snapshot = photos.diagnostics_snapshot()
        self.assertIn('Latest failure: phase=media-fetch', snapshot)
        self.assertIn('error_type=ValueError', '\n'.join(logs.output))
        self.assertNotIn('private-render-fixture', '\n'.join(logs.output))

