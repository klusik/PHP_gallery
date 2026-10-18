# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_flight_ui.py
# Module Type: Windows Tooling Regression Test
# Purpose: Verify flight GUI adapter lifecycle using host fakes without Tk windows.
# Responsibilities:
#   - Guard Stop/Revoke worker ownership, asynchronous dispatch and drain scope.
#   - Exercise late Revoke joining, fail-closed admission and terminal cleanup.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Behavior tests for the flight Tk adapter without constructing a Tk root."""

import queue
import tempfile
import threading
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import uploader.flight_ui as flight_ui
import uploader.flight_workflow as flight_workflow
from uploader.flight_jobs import FlightPreferences, SessionPlan, connection_binding


# Type: float; Units: seconds; Scope: flight UI worker race tests.
# Consumers: event synchronization and thread joins below.
# Rationale: a broken background barrier must fail without a long suite hang.
EVENT_WAIT_SECONDS = 3.0


class FlightUITests(unittest.TestCase):
    """Exercise adapter behavior through unbound methods and fake app hosts."""

    def _host(self) -> SimpleNamespace:
        """Create an app-shaped host with workers but no native Tk objects.

        Returns:
            SimpleNamespace: Fake host with saved config and observable workers.
        """
        return SimpleNamespace(
            root=SimpleNamespace(after=Mock()),
            config=SimpleNamespace(
                gallery_url="https://gallery.test/api",
                api_key="test-key",
                simconnect_dll_path="",
            ),
            events=queue.Queue(),
            worker=None,
            manual_worker=None,
            preflight_worker=None,
            ai_worker=None,
            utc_sync=Mock(),
            current_job=None,
            job_store=Mock(),
            connection_test_running=False,
            semantic_ai_install_running=False,
            ai_worker_stop_requested=False,
            api_key_revoke_running=False,
            api_key_revoke_thread=None,
            status_var=Mock(),
            watcher_activity_var=Mock(),
            api_key_request_blockers=Mock(return_value=[]),
            refresh_connection_controls=Mock(),
            open_local_path=Mock(),
        )

    def _ui(self, host: SimpleNamespace) -> flight_ui.FlightUI:
        """Create a controller without invoking Tk construction.

        Args:
            host (SimpleNamespace): Fake existing uploader host.

        Returns:
            FlightUI: Uninitialized Tk adapter with nonvisual dependencies set.
        """
        ui = flight_ui.FlightUI.__new__(flight_ui.FlightUI)
        host.utc_sync.is_alive.return_value = False
        ui.host = host
        ui.directory = Path(tempfile.gettempdir()) / "flight-ui-test"
        ui.normalize = lambda value: value
        ui.revoke_transport = Mock(return_value={"message": "revoked"})
        ui.resume_photos = Mock(return_value=None)
        ui.import_bindings = SimpleNamespace(entries=lambda: {})
        ui.operation = None
        ui.text = lambda key: key
        ui.recorder = None
        ui.plan = None
        ui.dialog = None
        ui.bar = None
        ui.lease = None
        ui.load_error = ""
        ui.preferences = FlightPreferences()
        ui.record_var = Mock()
        ui.record_var.get.return_value = True
        ui.report_var = Mock()
        ui.report_var.get.return_value = True
        ui.ui_language = Mock()
        ui.ui_language.get.return_value = "en"
        ui.language_vars = {code: Mock() for code in ("en", "cs", "de", "sv")}
        for variable in ui.language_vars.values():
            variable.get.return_value = True
        ui.manual_job_id = None
        ui.resume_workers = []
        ui.phase_var = Mock()
        ui.detail_var = Mock()
        ui.time_var = Mock()
        ui._last_terminal = ""
        ui.activity_bar = None
        ui.task_tree = None
        ui._animation_running = False
        ui.pending = Mock(return_value=[])
        ui.binding = Mock(return_value=connection_binding(
            "https://gallery.test/api", "test-key"
        ))
        ui.busy = Mock(return_value=False)
        ui.stop = Mock()
        ui.wait_for_workers = Mock()
        ui.submit_revoke = Mock()
        ui.show_progress = Mock()
        ui.refresh_tasks = flight_ui.FlightUI.refresh_tasks.__get__(
            ui, flight_ui.FlightUI
        )
        return ui

    def test_watcher_automatically_arms_one_shared_session(self) -> None:
        """Arm recording at watcher start, including a dormant opted-out worker.

        Returns:
            None: Assert one durable photo/flight directory and no extra action.
        """
        for recording in (True, False):
            with self.subTest(recording=recording), tempfile.TemporaryDirectory() as tmp:
                host = self._host()
                ui = self._ui(host)
                ui.directory = Path(tmp)
                ui.lease = object()
                ui.preferences = FlightPreferences(recording=recording)
                ui.save_preferences = Mock(return_value=True)
                recorder = Mock(directory=Path(tmp) / "shared-session")
                with patch.object(flight_ui, "FlightRecorder", return_value=recorder):
                    directory = flight_ui.FlightUI.start_session(ui)
                self.assertEqual(directory, recorder.directory)
                self.assertEqual(ui.plan.directory, directory)
                self.assertEqual(ui.plan.reports_enabled, recording)
                self.assertTrue((directory / "completion.json").exists())
                recorder.start.assert_called_once_with()
                if recording:
                    recorder.pause.assert_not_called()
                else:
                    recorder.pause.assert_called_once_with()
                    self.assertEqual(
                        [entry[0] for entry in recorder.method_calls],
                        ["pause", "start"],
                    )

    def test_recording_switch_pauses_and_resumes_without_stopping_photos(self) -> None:
        """Apply saved recording choices to the active shared flight session.

        Returns:
            None: Assert durable preferences, recorder gating and photo isolation.
        """
        with tempfile.TemporaryDirectory() as tmp:
            host = self._host()
            host.worker = Mock()
            ui = self._ui(host)
            ui.directory = Path(tmp)
            ui.recorder = Mock()
            ui.plan = SessionPlan(
                Path(tmp) / "shared-session", ui.binding(),
                ("en", "cs", "de", "sv"), True,
            )
            ui.plan.directory.mkdir()
            ui.record_var.get.return_value = False
            self.assertTrue(flight_ui.FlightUI.save_preferences(ui))
            self.assertFalse(FlightPreferences.load(ui.directory).recording)
            ui.recorder.pause.assert_called_once_with()
            self.assertTrue(ui.plan.reports_enabled)
            ui.record_var.get.return_value = True
            self.assertTrue(flight_ui.FlightUI.save_preferences(ui))
            self.assertTrue(FlightPreferences.load(ui.directory).recording)
            ui.recorder.resume.assert_called_once_with()
            host.worker.stop.assert_not_called()
            host.worker.request_drain.assert_not_called()

    def test_invalid_preferences_restore_switches_without_touching_recorder(self) -> None:
        """Reject reports with no selected language before applying acquisition.

        Returns:
            None: Assert controls return to saved settings and workers stay intact.
        """
        ui = self._ui(self._host())
        ui.recorder = Mock()
        ui.record_var.get.return_value = False
        for variable in ui.language_vars.values():
            variable.get.return_value = False
        with patch.object(flight_ui.messagebox, "showerror"):
            self.assertFalse(flight_ui.FlightUI.save_preferences(ui))
        ui.record_var.set.assert_called_once_with(True)
        for variable in ui.language_vars.values():
            variable.set.assert_called_once_with(True)
        ui.recorder.pause.assert_not_called()
        ui.recorder.resume.assert_not_called()

    def test_finalization_rejects_switch_changes_and_keeps_progress_available(self) -> None:
        """Keep accepted finalization immutable while restoring rejected controls.

        Returns:
            None: Assert no new recorder action during active finalization.
        """
        ui = self._ui(self._host())
        ui.busy.return_value = True
        ui.recorder = Mock()
        self.assertFalse(flight_ui.FlightUI.save_preferences(ui))
        ui.record_var.set.assert_called_once_with(True)
        ui.show_progress.assert_called_once_with()
        ui.recorder.pause.assert_not_called()

    def test_start_session_fails_closed_when_preferences_do_not_save(self) -> None:
        """Require durable preferences before starting any recorder or queue.

        Returns:
            None: Assert a failed save admits no session work.
        """
        host = self._host()
        ui = self._ui(host)
        ui.load_error = ""
        ui.lease = object()
        ui.save_preferences = Mock(return_value=False)
        with self.assertRaises(ValueError):
            flight_ui.FlightUI.start_session(ui)
        ui.save_preferences.assert_called_once_with()
        self.assertIsNone(ui.recorder)
        self.assertIsNone(ui.plan)

    def test_corrupt_recovery_state_refuses_new_session(self) -> None:
        """Fail closed when a durable plan cannot be safely recovered.

        Returns:
            None: Assert admission is denied and a warning is queued.
        """
        host = self._host()
        ui = self._ui(host)
        ui.pending.side_effect = ValueError("flight state needs recovery")
        admitted = flight_ui.FlightUI.can_start(ui)
        self.assertFalse(admitted)
        self.assertEqual(host.events.get_nowait()[0], "warning")

    def test_stop_stops_watcher_and_utc_but_leaves_ai_and_manual_alone(self) -> None:
        """Keep ordinary Stop within watcher and accepted-photo ownership.

        Returns:
            None: Assert recorder/watcher Stop while AI/import workers continue.
        """
        host = self._host()
        ui = self._ui(host)
        watcher = Mock()
        watcher.is_alive.return_value = True
        host.worker = watcher
        host.utc_sync.is_alive.return_value = True
        host.manual_worker = Mock()
        host.manual_worker.is_alive.return_value = True
        host.ai_worker = Mock()
        host.ai_worker.is_alive.return_value = True
        host.preflight_worker = Mock()
        host.preflight_worker.is_alive.return_value = True
        operation = SimpleNamespace(wants_revoke=False, start=Mock())
        with patch.object(
            flight_ui, "FinalizationWorker", return_value=operation
        ) as worker_factory:
            flight_ui.FlightUI.begin(ui, revoke=False)
        host.utc_sync.stop.assert_called_once()
        ui.stop.assert_called_once()
        watcher.request_drain.assert_called_once()
        host.ai_worker.stop.assert_not_called()
        host.preflight_worker.stop.assert_not_called()
        host.manual_worker.stop.assert_not_called()
        host.manual_worker.resume.assert_not_called()
        self.assertFalse(worker_factory.call_args.args[-1])
        operation.start.assert_called_once()
        ui.wait_for_workers.assert_not_called()
        ui.submit_revoke.assert_not_called()

    def test_revoke_stops_other_producers_and_dispatches_off_tk(self) -> None:
        """Stop authenticated producers before starting the background barrier.

        Returns:
            None: Assert revoke joins the worker path without synchronous network.
        """
        host = self._host()
        ui = self._ui(host)
        watcher = Mock()
        watcher.is_alive.return_value = True
        watcher.request_drain = Mock()
        host.worker = watcher
        host.manual_worker = Mock()
        host.manual_worker.is_alive.return_value = True
        host.ai_worker = Mock()
        host.ai_worker.is_alive.return_value = True
        host.preflight_worker = Mock()
        host.preflight_worker.is_alive.return_value = True
        operation = SimpleNamespace(wants_revoke=True, start=Mock())
        with (
            patch.object(
                flight_ui.messagebox, "askyesno", return_value=True
            ),
            patch.object(
                flight_ui, "FinalizationWorker", return_value=operation
            ) as worker_factory,
        ):
            flight_ui.FlightUI.begin(ui, revoke=True)
        host.ai_worker.stop.assert_called_once()
        host.preflight_worker.stop.assert_called_once()
        host.manual_worker.resume.assert_called_once()
        self.assertTrue(host.ai_worker_stop_requested)
        self.assertTrue(worker_factory.call_args.args[-1])
        self.assertIs(worker_factory.call_args.args[3], ui.wait_for_workers)
        operation.start.assert_called_once()
        ui.wait_for_workers.assert_not_called()
        ui.submit_revoke.assert_not_called()
        ui.revoke_transport.assert_not_called()

    def test_revoke_wait_adapter_observes_all_authenticated_blockers(self) -> None:
        """Wait for current auth blockers only when final revoke is requested.

        Returns:
            None: Assert Revoke waits and ordinary Stop omits auth blockers.
        """
        host = self._host()
        ui = self._ui(host)
        ui.operation = SimpleNamespace(
            wants_revoke=True,
            plans=[],
            update=Mock(),
        )
        host.api_key_request_blockers.side_effect = [["manual upload"], []]
        cancel = threading.Event()
        flight_ui.FlightUI.wait_for_workers(
            ui,
            cancel,
            time.monotonic() + flight_workflow.FINALIZATION_DRAIN_SECONDS,
        )
        self.assertEqual(host.api_key_request_blockers.call_count, 2)

        host.api_key_request_blockers.reset_mock()
        ui.operation.wants_revoke = False
        flight_ui.FlightUI.wait_for_workers(
            ui,
            cancel,
            time.monotonic() + flight_workflow.FINALIZATION_DRAIN_SECONDS,
        )
        host.api_key_request_blockers.assert_not_called()

    def test_late_revoke_join_runs_a_second_final_wait(self) -> None:
        """Recheck auth workers when Revoke joins an already draining Stop.

        Returns:
            None: Assert the late consent path re-enters the adapter drain.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / "flight-late-revoke"
            directory.mkdir()
            plan = SessionPlan(
                directory=directory,
                binding=connection_binding("https://gallery.test/api", "test-key"),
                languages=(),
                reports_enabled=False,
            )
            host = self._host()
            ui = self._ui(host)
            ui.pending = Mock(return_value=[plan])
            ui.wait_for_workers = flight_ui.FlightUI.wait_for_workers.__get__(
                ui, flight_ui.FlightUI
            )
            entered = threading.Event()
            release = threading.Event()

            def delayed_analysis(path: Path) -> SimpleNamespace:
                """Hold analysis so explicit Revoke joins after the first drain.

                Args:
                    path (Path): Session directory supplied by finalization.

                Returns:
                    SimpleNamespace: Empty analysis after the join is registered.
                """
                entered.set()
                if not release.wait(EVENT_WAIT_SECONDS):
                    raise TimeoutError("late-join analysis timed out")
                return SimpleNamespace(samples=[], landings=[])

            revoke = Mock()
            worker = flight_workflow.FinalizationWorker(
                [plan],
                "https://gallery.test/api",
                "test-key",
                ui.wait_for_workers,
                revoke,
                False,
            )
            ui.operation = worker
            with patch.object(
                flight_workflow, "analyze_session", side_effect=delayed_analysis
            ):
                worker.start()
                self.assertTrue(entered.wait(EVENT_WAIT_SECONDS))
                self.assertTrue(worker.request_revoke())
                release.set()
                worker.join(EVENT_WAIT_SECONDS)
            self.assertFalse(worker.is_alive())
            self.assertTrue(plan.completed)
            revoke.assert_called_once()
            self.assertGreaterEqual(
                host.api_key_request_blockers.call_count,
                1,
            )

    def test_unknown_eta_uses_activity_animation_without_percent_guess(self) -> None:
        """Show unknown ETA and animate only the indeterminate activity bar.

        Returns:
            None: Assert unknown duration never becomes a percentage estimate.
        """
        host = self._host()
        ui = self._ui(host)
        activity_bar = Mock()
        activity_bar.winfo_exists.return_value = True
        ui.activity_bar = activity_bar
        now = time.monotonic()
        progress = SimpleNamespace(
            phase="uploading",
            detail="",
            error="",
            done=1,
            total=4,
            bytes_done=12,
            bytes_total=0,
            started=now - 8,
            phase_started=now - 3,
            eta_seconds=None,
        )
        ui.operation = SimpleNamespace(snapshot=lambda: progress)
        with patch.object(flight_ui.time, "monotonic", return_value=now):
            flight_ui.FlightUI.tick(ui)
        activity_bar.start.assert_called_once()
        self.assertTrue(ui._animation_running)
        timing = ui.time_var.set.call_args.args[0]
        self.assertIn("estimating", timing)
        self.assertEqual(ui.bar, None)
        host.root.after.assert_called_once()

    def test_terminal_cleanup_updates_existing_host_controls(self) -> None:
        """Clear adapter ownership flags only after its worker is terminal.

        Returns:
            None: Assert terminal progress refreshes existing connection UI.
        """
        host = self._host()
        ui = self._ui(host)
        activity_bar = Mock()
        activity_bar.winfo_exists.return_value = True
        ui.activity_bar = activity_bar
        ui._animation_running = True
        ui.operation = SimpleNamespace(
            snapshot=lambda: SimpleNamespace(
                phase="completed",
                detail="",
                error="",
                done=2,
                total=2,
                bytes_done=0,
                bytes_total=0,
                started=time.monotonic(),
                phase_started=time.monotonic(),
                eta_seconds=None,
            )
        )
        host.api_key_revoke_running = True
        host.api_key_revoke_thread = object()
        flight_ui.FlightUI.tick(ui)
        activity_bar.stop.assert_called_once()
        self.assertFalse(ui._animation_running)
        self.assertFalse(host.api_key_revoke_running)
        self.assertIsNone(host.api_key_revoke_thread)
        host.refresh_connection_controls.assert_called_once()
        host.root.after.assert_called_once()


if __name__ == "__main__":
    unittest.main()
