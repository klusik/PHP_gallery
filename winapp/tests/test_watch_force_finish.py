# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watch_force_finish.py
# Module Type: Integration Regression Test
# Purpose: Verify Force Finish through the real desktop and session owners.
# Responsibilities:
#   - Exercise operation cancellation and exact session queue publications.
#   - Retain active transport ownership and original files through late ACKs.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise Force Finish through the imported desktop owner and durable models."""

import importlib.machinery
import importlib.util
import queue
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from types import ModuleType, SimpleNamespace
from typing import Callable, Protocol, TypedDict, cast
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.flight_jobs import SessionPlan, WorkspaceLease
from uploader.flight_workflow import FinalizationWorker
from uploader.models import JsonValue

# Purpose: Bound fixture events without waiting on a production retry interval.
# Type: float. Units: seconds. Scope: one Force Finish operation in these tests.
# Consumers: _next_force_event and blocked-transport regressions.
# Rationale: Five seconds covers hosted filesystem publication without a test loop.
FORCE_FINISH_WAIT_SECONDS = 5.0


class ForceFinishPayload(TypedDict):
    """Describe the production event's actual worker and operation references."""

    worker: threading.Thread | None
    workers: list[threading.Thread]
    operation: FinalizationWorker
    error: str
    pending_transport: bool
    error_type: str
    cutoff: float
    plan_sessions: list[str]
    operation_generation: int
    operation_trigger: str


class ForceFinishEvent(TypedDict):
    """Describe one typed App event without pretending references are JSON."""

    kind: str
    payload: ForceFinishPayload


class RetainedStopPayload(TypedDict):
    """Describe the existing Stop publication callback event."""

    worker: threading.Thread
    error: str
    on_persisted: Callable[[bool], None] | None


class RetainedStopEvent(TypedDict):
    """Describe a retained-stop event carrying its real worker reference."""

    kind: str
    payload: RetainedStopPayload


class ForceFinishAppFixture(Protocol):
    """Describe initialized nonvisual App state used by production hooks."""

    worker: threading.Thread | None
    events: queue.Queue[object]
    flights: mock.Mock
    status_var: SimpleNamespace
    _set_watcher_recording: Callable[[bool], None]
    _cancel_ordinary_restart_for_action: Callable[[], None]
    _force_finished_workers: set[threading.Thread]
    _force_finished_operations: set[FinalizationWorker]
    _force_finish_callbacks: dict[int, list[Callable[[str], None]]]
    _force_finish_failure_type: str
    api_key_revoke_running: bool
    exiting: bool
    _event_drain_total_count: int
    _progress_generation: int
    _finish_resume_generation: int
    _finish_resume_attempted_sessions: set[str]
    _queue_recovery_finish_requested: bool
    _watch_queue_snapshot: dict[str, object]
    _watcher_queued_count: int | None
    _watcher_last_scan_at: float | None
    _watcher_queue_worker: threading.Thread | None


def load_main_module() -> ModuleType:
    """Import the actual desktop source without constructing its Tk root.

    Returns:
        ModuleType: Production WatcherApp, WatcherThread and session declarations.

    Raises:
        RuntimeError: The source loader cannot form a module specification.
    """
    loader = importlib.machinery.SourceFileLoader(
        'gallery_watch_force_finish_test',
        str(WINAPP_DIR / 'gallery_watch_upload.pyw'),
    )
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        raise RuntimeError('Could not create the uploader source import spec.')
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


class WatchForceFinishTests(unittest.TestCase):
    """Keep explicit Force scoped to its captured flight and transport owners."""

    @classmethod
    def setUpClass(cls) -> None:
        """Load production declarations without starting Tk or a network client.

        Returns:
            None: Stores the imported production module for all isolated cases.
        """
        cls.main: ModuleType = load_main_module()

    def setUp(self) -> None:
        """Prepare isolated folders and replace only the native SDK boundary.

        Returns:
            None: Creates a temporary source, profile and flight-session owner.
        """
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.folder = self.root / 'photos'
        self.folder.mkdir()
        self.flight_workspace = self.root / 'flights'
        self.flight_workspace.mkdir()
        self.flight_lease = WorkspaceLease(self.flight_workspace)
        self.addCleanup(self.flight_lease.release)
        self.profile = self.root / 'profile'
        self.profile.mkdir()
        config_patch = mock.patch.object(self.main, 'CONFIG_DIR', self.profile)
        config_patch.start()
        self.addCleanup(config_patch.stop)
        native_patch = mock.patch.object(
            self.main, 'SimConnectCameraClient', return_value=mock.Mock(),
        )
        native_patch.start()
        self.addCleanup(native_patch.stop)

    def make_worker(
        self, session_name: str, *, delete_uploaded_files: bool = False,
        binding: str = 'b' * 64,
    ) -> threading.Thread:
        """Create a real linked WatcherThread with a private durable session.

        Args:
            session_name (str): Stable private flight-session directory name.
            delete_uploaded_files (bool): Enable the cleanup path for retention checks.
            binding (str): Unique destination identity for this worker fixture.

        Returns:
            threading.Thread: Unstarted production watcher with real queue and leases.
        """
        def approved_scope() -> str:
            """Return the upload scope captured by this synthetic target.

            Returns:
                str: Stable upload-only permission name.
            """
            return 'upload'

        def can_upload() -> bool:
            """Report the synthetic linked session as currently authorized.

            Returns:
                bool: True for the isolated target fixture.
            """
            return True

        def matches_issuer(issuer: str) -> bool:
            """Match only the one synthetic HTTPS origin.

            Args:
                issuer (str): Canonical origin checked by production admission.

            Returns:
                bool: True only for the fixture's exact issuer.
            """
            return issuer == 'https://gallery.example.test'

        target = SimpleNamespace(
            binding=lambda: binding,
            issuer='https://gallery.example.test',
            user_id=7 if binding == 'b' * 64 else 8,
            gallery_id=11 if binding == 'b' * 64 else 12,
            title='Synthetic linked target',
            watch_key='force-finish-' + binding[:8],
            session=SimpleNamespace(
                approved_scope=approved_scope,
                can_upload=can_upload,
                matches_issuer=matches_issuer,
            ),
        )
        worker = self.main.WatcherThread(
            self.main.WatcherConfig(
                gallery_url=target.issuer,
                api_key='',
                watched_folder=str(self.folder),
                watch_recursive=False,
                delete_uploaded_files=delete_uploaded_files,
                attach_sim_camera_metadata=False,
                stable_seconds=0.0,
            ),
            queue.Queue(),
            target,
        )
        session_directory = self.flight_workspace / session_name
        worker.configure_drain(session_directory, [])
        self.addCleanup(worker._linked_lease.release)
        worker.stability = mock.Mock()
        worker.stability.stable.return_value = True
        worker.remote_inventory = mock.Mock()
        worker.remote_inventory.refresh.return_value = True
        worker.remote_inventory.has_hash.return_value = False
        worker.remote_inventory.last_error = None
        return worker

    def make_plan(self, worker: threading.Thread) -> SessionPlan:
        """Persist the exact report plan whose directory owns the queue.

        Args:
            worker (threading.Thread): Production watcher with drain manifest.

        Returns:
            SessionPlan: Persisted owner used by the actual Force handler.
        """
        assert worker.drain_manifest is not None
        plan = self.main.SessionPlan(
            directory=worker.drain_manifest.parent,
            binding=worker._queue.binding,
            languages=(),
            reports_enabled=False,
        )
        plan.save()
        return plan

    def make_app(
        self, worker: threading.Thread | None, operation: FinalizationWorker,
        plans: list[SessionPlan],
    ) -> ForceFinishAppFixture:
        """Build the event/lifecycle state required by real App owner methods.

        Args:
            worker (threading.Thread | None): Current or unrelated passive watcher.
            operation (FinalizationWorker): Captured operation with its cancel event.
            plans (list[SessionPlan]): Operation-owned session plan collection.

        Returns:
            ForceFinishAppFixture: Constructor-bypassed App fixture with production hooks.
        """
        app = object.__new__(self.main.WatcherApp)
        app.worker = worker
        app.events = worker.events if worker is not None else queue.Queue()
        app.flights = mock.Mock()
        app.flights.operation = operation
        app.flights.lease = self.flight_lease
        app.flights.plan = None
        app.flights.plans = plans
        app.flights.resume_workers = []
        app.flights.stop = mock.Mock()
        app.flights.ui_language = SimpleNamespace(get=lambda: 'en')
        app.status_var = SimpleNamespace(set=lambda value: None)
        app._set_watcher_recording = lambda active: None
        app._force_finished_workers = set()
        app._force_finished_operations = set()
        app._force_finish_callbacks = {}
        app._force_finish_failure_type = ''
        app.api_key_revoke_running = True
        app.exiting = False
        app.refresh_watcher_action = lambda: None
        app.write_log = lambda message, tag='system': None
        app._event_drain_total_count = 0
        app._watch_queue_snapshot = {}
        app._watcher_queued_count = None
        app._watcher_last_scan_at = None
        app._watcher_queue_worker = None
        app.watch_queue_status_var = SimpleNamespace(set=lambda value: None)
        app.watch_last_scan_var = SimpleNamespace(set=lambda value: None)
        app.photos_workspace = None
        app.refresh_activity_monitor_state = lambda: None
        app.refresh_revoke_button_state = lambda: None
        app.refresh_watcher_header = lambda: None
        app._progress_generation = 0
        app._finish_resume_attempted_sessions = set()
        app._finish_resume_generation = 0
        app._queue_recovery_finish_requested = False
        app._cancel_ordinary_restart_for_action = lambda: None
        return app

    def operation(self, plans: list[SessionPlan]) -> FinalizationWorker:
        """Create a cancellable operation with the given captured plan set.

        Args:
            plans (list[SessionPlan]): Persisted plans currently owned by the operation.

        Returns:
            FinalizationWorker: Hashable production operation with cancellation state.
        """
        def wait_for_workers(cancel: threading.Event, deadline: float) -> None:
            """Provide a no-worker wait boundary for an unstarted test operation.

            Args:
                cancel (threading.Event): Cancellation state from finalization.
                deadline (float): Monotonic deadline for production worker draining.

            Returns:
                None: No app workers are attached to this isolated operation.
            """

        def revoke() -> None:
            """Represent the finalizer's unused revoke transport boundary.

            Returns:
                None: The fixture never starts the operation or sends a request.
            """

        return self.main.FinalizationWorker(
            plans, '', '', wait_for_workers, revoke, False,
        )

    def next_force_event(self, events: queue.Queue[object]) -> ForceFinishEvent:
        """Wait for and return the actual App Force result event.

        Args:
            events (queue.Queue[object]): Production event queue shared with App.

        Returns:
            ForceFinishEvent: Typed event with captured worker and operation owners.

        Raises:
            AssertionError: Force publication does not emit within the fixture bound.
        """
        deadline = time.monotonic() + FORCE_FINISH_WAIT_SECONDS
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                break
            try:
                event = events.get(timeout=remaining)
            except queue.Empty:
                break
            if (isinstance(event, dict)
                    and event.get('kind') == 'watch_force_finished'):
                return cast(ForceFinishEvent, event)
        raise AssertionError('Force Finish did not publish its owner event.')

    def next_retained_stop_event(
        self, events: queue.Queue[object],
    ) -> RetainedStopEvent:
        """Wait for the existing retained-stop publication result event.

        Args:
            events (queue.Queue[object]): Production event queue shared with App.

        Returns:
            RetainedStopEvent: Persisted Stop result and its callback.

        Raises:
            AssertionError: Stop publication does not emit within the fixture bound.
        """
        deadline = time.monotonic() + FORCE_FINISH_WAIT_SECONDS
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                break
            try:
                event = events.get(timeout=remaining)
            except queue.Empty:
                break
            if (isinstance(event, dict)
                    and event.get('kind') == 'watch_stop_retained'):
                return cast(RetainedStopEvent, event)
        raise AssertionError('Retained Stop did not publish its owner event.')

    def handle_force_event(
        self, app: ForceFinishAppFixture, event: ForceFinishEvent,
    ) -> None:
        """Run the actual event batch against a supplied Force result event.

        Args:
            app (ForceFinishAppFixture): Constructor-bypassed App fixture.
            event (ForceFinishEvent): Production Force result event.

        Returns:
            None: Applies the production operation-generation and callback checks.
        """
        app.events.put(event)
        self.main.WatcherApp._drain_event_batch(app)

    def test_empty_operation_force_finishes_current_empty_queue(self) -> None:
        """Close the visible watch session even when the operation has no plans.

        Returns:
            None: Empty session becomes terminal and its flight is cancelled durably.
        """
        worker = self.make_worker('empty-session')
        passive = self.make_worker(
            'unrelated-passive-session', binding='c' * 64,
        )
        passive_source = self.folder / 'passive.png'
        passive_source.write_bytes(b'passive original')
        passive._queue.reconcile([
            self.main.observe_watch_source(passive_source, time.time()),
        ])
        operation = self.operation([])
        app = self.make_app(worker, operation, [])
        app.flights.resume_workers = [passive]
        callback_results: list[str] = []

        self.assertTrue(self.main.WatcherApp.force_finish_flight(
            app, callback_results.append,
        ))
        event = self.next_force_event(app.events)
        self.assertEqual([worker], event['payload']['workers'])
        self.assertTrue(worker._queue.force_finished)
        self.assertTrue(worker.stop_event.is_set())
        self.assertFalse(passive._queue.force_finished)
        self.assertFalse(passive.stop_event.is_set())
        self.assertEqual(1, len(passive._queue.data['pending']))
        self.assertTrue(passive_source.exists())

        self.handle_force_event(app, event)
        self.assertTrue(operation.cancel.is_set())
        self.assertEqual(['persisted'], callback_results)

    def test_empty_finish_without_any_session_resolves_operation(self) -> None:
        """Allow an empty operation with no queue owner to finish cleanly.

        Returns:
            None: No unrelated session is synthesized or required for resolution.
        """
        operation = self.operation([])
        app = self.make_app(None, operation, [])
        callback_results: list[str] = []

        self.assertTrue(self.main.WatcherApp.force_finish_flight(
            app, callback_results.append,
        ))
        event = self.next_force_event(app.events)
        self.assertEqual([], event['payload']['workers'])
        self.handle_force_event(app, event)
        self.assertTrue(operation.cancel.is_set())
        self.assertEqual(['persisted'], callback_results)

    def test_completed_queue_stays_completed_while_parent_plan_is_abandoned(self) -> None:
        """Persist flight abandonment without downgrading a completed queue.

        Returns:
            None: SessionPlan is terminal and the real queue bytes remain unchanged.
        """
        worker = self.make_worker('completed-session')
        worker._queue.finish(time.time())
        worker._queue.reconcile([])
        self.assertTrue(worker._queue.complete(0.0))
        queue_bytes = worker.drain_manifest.read_bytes()
        plan = self.make_plan(worker)
        operation = self.operation([plan])
        app = self.make_app(worker, operation, [plan])

        self.assertTrue(self.main.WatcherApp.force_finish_flight(app, lambda result: None))
        event = self.next_force_event(app.events)

        self.assertFalse(event['payload']['error'])
        self.assertEqual(queue_bytes, worker.drain_manifest.read_bytes())
        saved = self.main.SessionPlan.load(plan.directory)
        self.assertTrue(saved.completed)
        self.assertTrue(saved.abandoned)
        self.assertFalse(worker._queue.force_finished)

    def test_late_ack_retains_source_and_real_leases_until_transport_returns(self) -> None:
        """Keep request ownership until late ACK, while Force retains the original.

        Returns:
            None: The admitted ACK is durable, source remains present and both leases end cleanly.
        """
        source = self.folder / 'late-ack.png'
        source.write_bytes(b'original linked source')
        worker = self.make_worker('active-session', delete_uploaded_files=True)
        worker._queue.reconcile([
            self.main.observe_watch_source(source, time.time()),
        ])
        plan = self.make_plan(worker)
        operation = self.operation([plan])
        app = self.make_app(worker, operation, [plan])
        entered = threading.Event()
        release = threading.Event()
        outcome: dict[str, JsonValue] = {
            'ok': True, 'success': True, 'gallery_id': 11,
            'uploaded': 1, 'scanned': 0, 'image_ids': [31],
            'filenames': [source.name], 'upload_evidence': 'fresh_insert_v1',
        }

        def hold_upload(
            url: str, path: Path, metadata: dict[str, str],
            file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Hold the actual marked request, then return its valid fresh receipt.

            Args:
                url (str): Captured gallery endpoint.
                path (Path): Exact admitted original source.
                metadata (dict[str, str]): Production camera metadata fields.
                file_hash (str): Hash of the bytes admitted to the queue.
                size (int): Size of the immutable source snapshot.

            Returns:
                dict[str, JsonValue]: Positive receipt for the linked gallery insert.
            """
            worker._mark_watch_dispatch()
            entered.set()
            try:
                if not release.wait(FORCE_FINISH_WAIT_SECONDS):
                    raise AssertionError(
                        'Fixture transport release was not signalled.',
                    )
                return outcome
            finally:
                worker._transport_active.clear()

        worker.upload_watched_file = hold_upload
        worker.start()
        self.addCleanup(worker.join, FORCE_FINISH_WAIT_SECONDS)
        self.addCleanup(worker.stop)
        self.addCleanup(release.set)
        self.assertTrue(entered.wait(FORCE_FINISH_WAIT_SECONDS))
        self.assertTrue(worker._transport_active.is_set())
        worker._pipeline_lease._native.verify()
        worker._linked_lease._native.verify()

        retained_entered = threading.Event()
        retained_release = threading.Event()
        self.addCleanup(retained_release.set)
        original_stop = worker._queue.stop_and_retain

        def delay_retained_stop(cutoff: float) -> None:
            """Hold the real Stop journal boundary while Force is requested.

            Args:
                cutoff (float): Captured Stop cutoff that predates Force.

            Returns:
                None: Delegates to the original owner after the controlled release.
            """
            retained_entered.set()
            if not retained_release.wait(FORCE_FINISH_WAIT_SECONDS):
                raise AssertionError('Fixture Stop publication was not released.')
            original_stop(cutoff)

        worker._queue.stop_and_retain = delay_retained_stop
        stop_results: list[bool] = []
        self.assertTrue(self.main.WatcherApp.stop_and_keep_watch_pending(
            app, stop_results.append,
        ))
        self.assertTrue(retained_entered.wait(FORCE_FINISH_WAIT_SECONDS))

        callback_results: list[str] = []
        self.assertTrue(self.main.WatcherApp.force_finish_flight(
            app, callback_results.append,
        ))
        self.assertTrue(self.main.WatcherApp.force_finish_flight(
            app, callback_results.append,
        ))
        event = self.next_force_event(app.events)
        self.assertTrue(event['payload']['pending_transport'])
        self.assertTrue(worker._queue.force_finished)
        self.assertTrue(source.exists())
        worker._pipeline_lease._native.verify()
        worker._linked_lease._native.verify()

        self.handle_force_event(app, event)
        self.assertEqual(['pending_transport', 'pending_transport'], callback_results)
        self.assertTrue(operation.cancel.is_set())
        self.assertFalse(app.api_key_revoke_running)

        app.updates = SimpleNamespace(request_close=lambda: False)
        app.flights.is_alive = lambda: False
        app.flights.independent_operation = lambda: False
        app.flights.show_progress = mock.Mock()
        app.utc_sync = mock.Mock()
        app.utc_sync.is_alive.return_value = True
        app.preflight_worker = None
        app.manual_worker = None
        app.ai_worker = None
        app.api_key_revoke_thread = None
        app.ai_worker_stop_requested = False
        app._save_ui_layout_now = mock.Mock()
        app._discard_update_resume_intent = mock.Mock()
        app._cancel_event_ui_callbacks = mock.Mock()
        app.set_update_available = mock.Mock()
        app.stop_tray_icon = mock.Mock()
        app.simulator_presence = mock.Mock()
        app.linking_manager = mock.Mock()
        app.activity_writer = SimpleNamespace(close=lambda timeout: True)
        app.root = mock.Mock()
        app.tray_icon = mock.Mock()
        app.mode_owner = mock.Mock()
        app._ordinary_restart_prepared = False
        app._ordinary_restart_closing = False
        app._prepare_ordinary_restart_close = mock.Mock()
        with mock.patch.object(worker, 'join') as join_worker, \
                mock.patch.object(self.main, 'messagebox') as close_dialogs:
            self.main.WatcherApp.close(app)
        join_worker.assert_not_called()
        app.utc_sync.join.assert_called_once()
        close_dialogs.askyesno.assert_not_called()
        close_dialogs.showwarning.assert_not_called()
        self.assertTrue(app.exiting)
        app.root.destroy.assert_called_once_with()
        app.linking_manager.close.assert_not_called()
        app._prepare_ordinary_restart_close.assert_not_called()

        replacement = self.operation([])
        app.flights.operation = replacement
        app.flights.independent_operation = lambda: True
        app.api_key_revoke_running = True
        with mock.patch.object(self.main, 'messagebox') as dialogs:
            self.main.WatcherApp.close(app)
        app.flights.show_progress.assert_called_once_with()
        dialogs.showwarning.assert_called_once()
        self.assertFalse(replacement.cancel.is_set())
        app.root.destroy.assert_called_once_with()

        retained_release.set()
        retained_event = self.next_retained_stop_event(app.events)
        callback = retained_event['payload']['on_persisted']
        assert callback is not None
        callback(not bool(retained_event['payload']['error']))
        self.assertEqual([True], stop_results)
        self.assertTrue(worker._queue.force_finished)
        release.set()
        worker.join(FORCE_FINISH_WAIT_SECONDS)
        self.assertFalse(worker.is_alive())
        self.assertEqual('acknowledged', worker._queue.items()[0].state)
        self.assertTrue(source.exists())
        self.assertFalse(worker._transport_active.is_set())
        self.assertIsNone(worker._pipeline_lease)

    def test_force_publication_failure_reports_failure_without_parent_abandonment(self) -> None:
        """Keep the flight plan active when its queue terminal marker cannot persist.

        Returns:
            None: Failure is surfaced and no parent completion is fabricated.
        """
        worker = self.make_worker('failed-session')
        plan = self.make_plan(worker)
        operation = self.operation([plan])
        app = self.make_app(worker, operation, [plan])

        def failed_force(cutoff: float) -> None:
            """Fail at the actual durable queue owner boundary.

            Args:
                cutoff (float): Captured explicit Force time.

            Returns:
                None: Never publishes the terminal journal marker.

            Raises:
                OSError: Models unavailable durable queue publication.
            """
            raise OSError('fixture durable storage failure')

        worker._queue.force_finish = failed_force
        callback_results: list[str] = []
        self.assertTrue(self.main.WatcherApp.force_finish_flight(
            app, callback_results.append,
        ))
        event = self.next_force_event(app.events)

        self.assertTrue(event['payload']['error'])
        self.handle_force_event(app, event)
        self.assertEqual(['failed'], callback_results)
        self.assertFalse(operation.cancel.is_set())
        self.assertFalse(self.main.SessionPlan.load(plan.directory).abandoned)
        self.assertFalse(worker._queue.force_finished)
        self.assertTrue(worker._force_stop_requested.is_set())
        self.assertTrue(worker._force_finish_publication_done.is_set())

    def test_optional_snapshot_failure_cannot_lose_durable_force_callback(self) -> None:
        """Deliver Force result after durable proof despite optional UI refresh failure.

        Returns:
            None: Pending original remains and the matching logical Finish guard clears.
        """
        source = self.folder / 'durable-pending.png'
        source.write_bytes(b'original pending source')
        worker = self.make_worker('snapshot-failure-session')
        worker._queue.reconcile([
            self.main.observe_watch_source(source, time.time()),
        ])
        operation = self.operation([])
        app = self.make_app(worker, operation, [])
        callback_results: list[str] = []

        def fail_optional_snapshot() -> None:
            """Simulate a read-only UI snapshot failing after durable completion.

            Returns:
                None: Raises to prove callback delivery is independent of refresh.

            Raises:
                RuntimeError: The fixture's optional presentation failure.
            """
            raise RuntimeError('fixture snapshot emission failure')

        worker.emit_queue_snapshot = fail_optional_snapshot
        self.assertTrue(self.main.WatcherApp.force_finish_flight(
            app, callback_results.append,
        ))
        event = self.next_force_event(app.events)

        self.handle_force_event(app, event)
        self.assertTrue(worker._queue.force_finished)
        self.assertEqual([str(source)], worker._queue.data['pending'])
        self.assertTrue(source.exists())
        self.assertTrue(operation.cancel.is_set())
        self.assertFalse(app.api_key_revoke_running)
        self.assertEqual(['persisted'], callback_results)

    def test_stale_force_event_does_not_cancel_replacement_operation(self) -> None:
        """Ignore an old operation result for the currently selected flight.

        Returns:
            None: Event delivery cannot cancel or restart the replacement operation.
        """
        worker = self.make_worker('stale-event-session')
        plan = self.make_plan(worker)
        old_operation = self.operation([plan])
        app = self.make_app(worker, old_operation, [plan])
        old_results: list[str] = []
        self.assertTrue(self.main.WatcherApp.force_finish_flight(
            app, old_results.append,
        ))
        event = self.next_force_event(app.events)
        replacement = self.operation([])
        replacement.flight_generation = event['payload']['operation_generation'] + 1
        app.flights.operation = replacement

        self.handle_force_event(app, event)

        self.assertFalse(replacement.cancel.is_set())
        self.assertTrue(worker._queue.force_finished)
        self.assertEqual([], old_results)
