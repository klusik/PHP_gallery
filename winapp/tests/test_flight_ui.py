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
from uploader.flight_jobs import (
    FlightPreferences, SavedFlight, SessionPlan, connection_binding,
)


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
            root=SimpleNamespace(after=Mock(), focus_get=Mock(return_value=None)),
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
            open_log_file=Mock(),
            gallery_label_var=Mock(get=Mock(return_value="Target gallery: Test gallery")),
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
        ui.automatic_operation = None
        ui.text = lambda key: key
        ui.recorder = None
        ui.plan = None
        ui.dispatch = None
        ui.dispatch_results = queue.Queue()
        ui.dispatch_worker = None
        ui.dispatch_cancelled = False
        ui.active_dispatch = None
        ui.active_dispatch_directory = None
        ui.dispatch_loading = False
        ui.dispatch_loading_target = None
        ui.simbrief_button_var = Mock()
        ui.simbrief_status_var = Mock()
        ui.report_sort_column = "started"
        ui.report_sort_descending = True
        ui.dialog = None
        ui.report_dialog = None
        ui.report_tree = None
        ui.report_rows = {}
        ui.report_files = {}
        ui.report_file_sort_keys = {}
        ui.report_button = None
        ui.keep_local_button = None
        ui.report_detail_var = Mock()
        ui.telemetry_var = Mock()
        ui._progress_local_mode = False
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

    def test_keep_local_preserves_confirmed_revoke_without_second_confirmation(self) -> None:
        """Carry the failed workflow's existing consent through local completion.

        Returns:
            None: Local completion resumes Revoke, while its confirmation states
                that the key will be removed and no second consent prompt appears.
        """
        ui = self._ui(self._host())
        worker = flight_workflow.FinalizationWorker(
            [], ui.host.config.gallery_url, ui.host.config.api_key,
            Mock(), Mock(), True,
        )
        worker.update("error", "delivery failed")
        ui.operation = worker
        ui.begin = Mock()
        with patch.object(flight_ui.messagebox, "askyesno", return_value=True) as confirm:
            ui.keep_local()
        ui.begin.assert_called_once_with(True)
        self.assertEqual("confirm_local_revoke", confirm.call_args.args[1])
        ui.begin = flight_ui.FlightUI.begin.__get__(ui, flight_ui.FlightUI)
        with (
            patch.object(flight_ui.messagebox, "askyesno") as confirm,
            patch.object(flight_ui, "FinalizationWorker") as factory,
        ):
            ui.begin(True)
        confirm.assert_not_called()
        self.assertTrue(factory.call_args.args[-1])

    def test_retry_never_restores_cancelled_or_another_keys_revoke_consent(self) -> None:
        """Bind retained consent to the same process, endpoint and credential.

        Returns:
            None: Retry retains existing consent, but cancellation or a changed
                key causes ordinary completion with no implicit revocation.
        """
        ui = self._ui(self._host())
        worker = flight_workflow.FinalizationWorker(
            [], ui.host.config.gallery_url, ui.host.config.api_key,
            Mock(), Mock(), True,
        )
        worker.update("error", "delivery failed")
        ui.operation = worker
        ui.begin = Mock()
        ui.retry_finalization()
        ui.begin.assert_called_with(True)
        worker.cancel_revoke()
        ui.retry_finalization()
        ui.begin.assert_called_with(False)
        worker.wants_revoke = True
        ui.host.config.api_key = "replacement-key"
        ui.retry_finalization()
        ui.begin.assert_called_with(False)

    def test_automatic_finish_admits_revoke_without_modal_confirmation(self) -> None:
        """Treat the Finish click as authorization for the final revoke barrier.

        Returns:
            None: A fresh worker starts without a confirmation dialog and owns
                automatic completion; ordinary connection controls remain gated.
        """
        ui = self._ui(self._host())
        operation = Mock(local_reports=False, saved_upload=False)
        with patch.object(flight_ui.messagebox, "askyesno") as confirm, patch.object(
            flight_ui, "FinalizationWorker", return_value=operation,
        ) as factory:
            ui.begin(True, automatic=True)
        confirm.assert_not_called()
        self.assertTrue(factory.call_args.args[5])
        operation.start.assert_called_once_with()
        self.assertIs(operation, ui.automatic_operation)
        self.assertTrue(ui.host.api_key_revoke_running)

    def test_automatic_progress_closes_only_after_successful_terminal_state(self) -> None:
        """Keep recovery visible while removing successful transient windows.

        Returns:
            None: Saving-key, failed and cancelled operations never hide their
                progress view; completed cleanup hides it without a dialog.
        """
        # These four lifecycle boundaries separate acknowledgement from success.
        for phase in ("saving_key", "error", "cancelled", "completed"):
            with self.subTest(phase=phase):
                ui = self._ui(self._host())
                now = time.monotonic()
                ui.operation = SimpleNamespace(
                    local_reports=True, saved_upload=False,
                    snapshot=lambda: SimpleNamespace(
                        phase=phase, detail="", error="", done=1, total=1,
                        bytes_done=0, bytes_total=0, started=now,
                        phase_started=now, eta_seconds=None,
                    ),
                )
                ui.automatic_operation = ui.operation
                ui.dialog = Mock()
                ui.refresh_tasks = Mock()
                ui.refresh_reports = Mock()
                ui.tick()
                if phase == "completed":
                    ui.dialog.withdraw.assert_called_once_with()
                else:
                    ui.dialog.withdraw.assert_not_called()

    def test_saved_pdf_upload_uses_current_key_without_stopping_live_flight(self) -> None:
        """Upload an old local revision with a new explicitly confirmed key.

        Returns:
            None: Captures destination and exact files without rewriting the
                old binding or stopping recorder, watcher, UTC or revoking.
        """
        with tempfile.TemporaryDirectory() as temporary:
            ui = self._ui(self._host())
            ui.lease = object()
            directory = Path(temporary) / "old-flight"
            directory.mkdir()
            plan = SessionPlan(directory, "a" * 64, ("en",), False, local_only=True)
            plan.save()
            original = (directory / "completion.json").read_bytes()
            revision = flight_ui.ReportRevision("manual-test", {"en": directory / "en.pdf"})
            ui.report_tree = Mock()
            ui.report_tree.selection.return_value = ("selected-pdf",)
            ui.report_files = {"selected-pdf": (directory.name, revision, "en")}
            ui.report_rows = {directory.name: SavedFlight(directory, recording_ended=True)}
            ui.recorder = Mock(directory=Path(temporary) / "current-flight")
            with (
                patch.object(flight_ui.messagebox, "askyesno", return_value=True),
                patch.object(flight_ui, "FinalizationWorker") as factory,
            ):
                ui.upload_saved_pdf()
            self.assertEqual("test-key", factory.call_args.args[2])
            self.assertEqual(revision.files, factory.call_args.kwargs["saved_revision"].files)
            self.assertEqual("Test gallery", factory.call_args.kwargs["target_gallery"])
            self.assertFalse(factory.call_args.args[-1])
            ui.stop.assert_not_called()
            ui.host.utc_sync.stop.assert_not_called()
            ui.revoke_transport.assert_not_called()
            self.assertEqual(original, (directory / "completion.json").read_bytes())

    def test_upload_selects_exact_files_across_multiple_revisions(self) -> None:
        """Keep PDF edition selection separate from the flight's retained set.

        Returns:
            None: Two selected EN files from different versions upload together;
                an unselected CS edition is never added implicitly.
        """
        ui = self._ui(self._host())
        row = SavedFlight(Path("flight"), recording_ended=True)
        first = flight_ui.ReportRevision("first", {"en": Path("one-en.pdf"), "cs": Path("one-cs.pdf")})
        second = flight_ui.ReportRevision("second", {"en": Path("two-en.pdf")})
        ui.report_rows = {"flight": row}
        ui.report_files = {"one": ("flight", first, "en"), "two": ("flight", second, "en")}
        ui.report_tree = Mock()
        ui.report_tree.selection.return_value = ("one", "two")
        ui.start_saved_upload = Mock()
        ui.upload_saved_pdf()
        selected_row, selected, extra = ui.start_saved_upload.call_args.args
        self.assertIs(row, selected_row)
        self.assertEqual({"en": Path("one-en.pdf")}, selected.files)
        self.assertEqual({"en": Path("two-en.pdf")}, extra[0].files)

    def test_selected_pdf_opens_in_default_viewer(self) -> None:
        """Open the selected PDF itself rather than a log or another session.

        Returns:
            None: The existing desktop owner receives exactly the chosen file.
        """
        with tempfile.TemporaryDirectory() as temporary:
            ui = self._ui(self._host())
            directory = Path(temporary) / "flight"
            pdf = directory / "reports" / "Flight EN v01.pdf"
            pdf.parent.mkdir(parents=True)
            pdf.write_bytes(b"%PDF-test")
            revision = flight_ui.ReportRevision("one", {"en": pdf})
            ui.report_rows = {"flight": SavedFlight(directory, recording_ended=True)}
            ui.report_files = {"selected": ("flight", revision, "en")}
            ui.report_tree = Mock()
            ui.report_tree.selection.return_value = ("selected",)
            ui.open_selected_pdf()
            ui.host.open_local_path.assert_called_once_with(pdf)

    def test_saved_upload_retry_uses_captured_revision_after_selection_changes(self) -> None:
        """Keep a failed upload's Retry tied to the exact originally chosen PDFs.

        Returns:
            None: Retry ignores a different currently selected history row.
        """
        ui = self._ui(self._host())
        plan = SessionPlan(Path("original"), ui.binding(), ("en",), False)
        row = SavedFlight(plan.directory, recording_ended=True)
        revision = flight_ui.ReportRevision("original-revision", {"en": Path("original.pdf")})
        ui.report_rows = {"original": row}
        ui.selected_saved_flight = Mock(return_value=SavedFlight(Path("another-flight")))
        ui.operation = SimpleNamespace(saved_upload=True, saved_revision=revision, saved_revisions=(revision,), plans=[plan])
        ui.start_saved_upload = Mock()
        ui.retry_saved_upload()
        ui.start_saved_upload.assert_called_once_with(row, revision, ())
        ui.selected_saved_flight.assert_not_called()

    def test_report_progress_discards_rows_from_previous_revision(self) -> None:
        """Show only current edition tasks when a progress dialog is reused.

        Returns:
            None: Stale language rows are removed while the selected revision's
                completed uploads remain visible.
        """
        ui = self._ui(self._host())
        plan = SessionPlan(Path("original"), ui.binding(), ("en",), False)
        ui.operation = SimpleNamespace(
            local_reports=False, saved_upload=True, plans=[plan],
            saved_revisions=(flight_ui.ReportRevision("original", {"en": Path("original-en.pdf")}),),
            snapshot=lambda: SimpleNamespace(done=2, detail="", phase="completed"),
        )
        ui.task_tree = Mock()
        ui.task_tree.get_children.return_value = ("original-en", "original-cs")
        ui.refresh_tasks()
        ui.task_tree.delete.assert_called_once_with("original-cs")

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
        operation = SimpleNamespace(wants_revoke=False, local_reports=False, saved_upload=False, start=Mock())
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
        operation = SimpleNamespace(wants_revoke=True, local_reports=False, saved_upload=False, start=Mock())
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
            local_reports=False,
            saved_upload=False,
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
        ui.operation = SimpleNamespace(snapshot=lambda: progress, local_reports=False, saved_upload=False)
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
            local_reports=False,
            saved_upload=False,
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


    def test_one_click_finish_uses_loaded_ofp_and_enables_reports_without_questions(self) -> None:
        """Finish with four PDFs and revoke intent after one explicit click.

        Returns:
            None: Loaded OFP is saved for the sole pending flight before worker
                admission, all editions are enabled and revoke is requested
                without a confirmation; saved automatic preferences stay intact.
        """
        from uploader.flight_simbrief import DispatchPlan, load_dispatch

        with tempfile.TemporaryDirectory() as temporary:
            ui = self._ui(self._host())
            directory = Path(temporary) / "flight"
            directory.mkdir()
            ui.preferences.reports = False
            plan = SessionPlan(directory, ui.binding(), ("en",), False)
            ui.pending.return_value = [plan]
            ui.dispatch = DispatchPlan("RKSS", "RJTT", "DCT", "A320", "120",
                "5000", "kgs", "2610", "2026-10-18")
            ui.begin = Mock()
            with patch.object(flight_ui.messagebox, "askyesno") as confirm:
                ui.finish_report()
            saved = SessionPlan.load(directory)
            self.assertTrue(saved.reports_enabled)
            self.assertEqual(("en", "cs", "de", "sv"), saved.languages)
            ui.begin.assert_called_once_with(True, automatic=True)
            confirm.assert_not_called()
            self.assertEqual(ui.dispatch, load_dispatch(directory))
            ui.revoke_transport.assert_not_called()
            self.assertEqual("test-key", ui.host.config.api_key)
            self.assertFalse(ui.preferences.reports)

    def test_compact_telemetry_converts_units_and_clears_missing_values(self) -> None:
        """Format one cached line without initiating any simulator acquisition.

        Returns:
            None: IAS, feet and signed vertical speed convert correctly; absent
                snapshots replace every value with a dash.
        """
        ui = self._ui(self._host())
        ui.recorder = Mock()
        ui.recorder.telemetry_snapshot.return_value = {
            "indicated_airspeed_mps": 100 * flight_ui.METRES_PER_SECOND_PER_KNOT,
            "altitude_m": 10000 * flight_ui.METRES_PER_FOOT,
            "vertical_speed_mps": -500 * flight_ui.METRES_PER_SECOND_PER_FPM,
        }
        ui.refresh_telemetry()
        self.assertEqual(
            "IAS 100 kt · ALT 10,000 ft · V/S -500 fpm",
            ui.telemetry_var.set.call_args.args[0],
        )
        ui.recorder.telemetry_snapshot.return_value = None
        ui.refresh_telemetry()
        self.assertEqual(
            "IAS — kt · ALT — ft · V/S — fpm",
            ui.telemetry_var.set.call_args.args[0],
        )

    def test_local_pdf_does_not_block_recording_pause_preferences(self) -> None:
        """Allow active capture controls while an older local report renders.

        Returns:
            None: A local render keeps its captured languages while changing
                recording preferences still pauses the independent recorder.
        """
        with tempfile.TemporaryDirectory() as temporary:
            ui = self._ui(self._host())
            ui.directory = Path(temporary)
            ui.operation = SimpleNamespace(local_reports=True, saved_upload=False)
            ui.busy.return_value = True
            ui.recorder = Mock()
            ui.plan = SessionPlan(
                Path(temporary) / "current", ui.binding(), ("en",), True
            )
            ui.record_var.get.return_value = False
            self.assertTrue(ui.save_preferences())
            ui.recorder.pause.assert_called_once()
            ui.show_progress.assert_not_called()

    def test_local_report_retry_survives_closed_catalog_window(self) -> None:
        """Keep the last local report available after its history window closes.

        Returns:
            None: Retry/open resolve the captured session without querying a
                destroyed Tk Treeview or switching to another active flight.
        """
        ui = self._ui(self._host())
        plan = SessionPlan(Path("saved-flight"), ui.binding(), ("en",), True)
        row = SavedFlight(plan.directory, recording_ended=True)
        ui.report_rows = {plan.session_id: row}
        ui.report_tree = Mock()
        ui.report_tree.winfo_exists.return_value = False
        ui.operation = SimpleNamespace(local_reports=True, saved_upload=False, plans=[plan])
        self.assertIs(row, ui.selected_saved_flight())
        ui.report_tree.selection.assert_not_called()

    def test_local_pdf_action_never_stops_current_photos_or_reveals_key(self) -> None:
        """Render an older flight with empty transport credentials off Tk.

        Returns:
            None: The saved plan is copied, receipts remain unchanged, and no
                current recorder, watcher, UTC or revoke action is requested.
        """
        with tempfile.TemporaryDirectory() as temporary:
            ui = self._ui(self._host())
            ui.lease = object()
            directory = Path(temporary) / "older-flight"
            directory.mkdir()
            plan = SessionPlan(directory, ui.binding(), ("en",), False)
            plan.save()
            original = (directory / "completion.json").read_bytes()
            ui.report_tree = Mock()
            ui.report_tree.selection.return_value = (directory.name,)
            ui.report_rows = {
                directory.name: SavedFlight(directory, recording_ended=True)
            }
            ui.recorder = Mock(directory=Path(temporary) / "current-flight")
            ui.select_report = Mock()
            operation = Mock(local_reports=True)
            with patch.object(flight_ui, "FinalizationWorker", return_value=operation) as factory:
                ui.create_local_pdf()
            self.assertEqual(("", ""), factory.call_args.args[1:3])
            self.assertTrue(factory.call_args.kwargs["local_reports"])
            self.assertEqual(original, (directory / "completion.json").read_bytes())
            operation.start.assert_called_once()
            ui.stop.assert_not_called()
            ui.host.utc_sync.stop.assert_not_called()
            ui.revoke_transport.assert_not_called()

    def test_local_pdf_completion_does_not_replace_live_watcher_status(self) -> None:
        """Keep report completion independent of running photo/recording state.

        Returns:
            None: Local completion leaves watcher status and credential flags
                untouched while refreshing the passive report catalog.
        """
        ui = self._ui(self._host())
        now = time.monotonic()
        ui.operation = SimpleNamespace(
            local_reports=True,
            saved_upload=False,
            snapshot=lambda: SimpleNamespace(
                phase="completed", detail="saved", error="", done=2, total=2,
                bytes_done=0, bytes_total=0, started=now, phase_started=now,
                eta_seconds=None,
            ),
        )
        ui.host.api_key_revoke_running = True
        ui.refresh_tasks = Mock()
        ui.refresh_reports = Mock()
        ui.tick()
        self.assertTrue(ui.host.api_key_revoke_running)
        ui.host.watcher_activity_var.set.assert_not_called()
        ui.host.status_var.set.assert_not_called()
        ui.refresh_reports.assert_called_once()
        ui.operation.local_reports = False
        ui.operation.saved_upload = True
        ui._last_terminal = ""
        ui.tick()
        self.assertTrue(ui.host.api_key_revoke_running)
        ui.host.watcher_activity_var.set.assert_not_called()
        ui.host.status_var.set.assert_not_called()

    def test_regeneration_uses_loaded_ofp_without_questions_before_worker_reads_session(self) -> None:
        """Generate four local editions using available OFP without extra dialogs.

        Returns:
            None: A loaded plan is stored before rendering starts, absence of
                one retains previous evidence, and neither path confirms or
                sends a network revoke. Each worker gets all four languages.
        """
        from uploader.flight_simbrief import DispatchPlan, load_dispatch

        for loaded in (True, False):
            with self.subTest(loaded=loaded), tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                ui = self._ui(self._host())
                ui.lease = object()
                SessionPlan(directory, ui.binding(), ("en",), False).save()
                previous = DispatchPlan("LKPR", "EDDF", "OLD", "A320", "100",
                    "5000", "kgs", "2610", "2026-10-18")
                previous.save(directory)
                staged = DispatchPlan("RKSS", "RJTT", "NEW", "A320", "120",
                    "6000", "kgs", "2610", "2026-10-18")
                ui.dispatch = staged if loaded else None
                ui.selected_saved_flight = Mock(return_value=SavedFlight(
                    directory, aircraft="A320", recording_ended=True,
                ))
                ui.select_report = Mock()
                seen = []
                operation = Mock(local_reports=True)
                operation.start.side_effect = lambda: seen.append(load_dispatch(directory))
                with patch.object(flight_ui.messagebox, "askyesnocancel") as confirm, patch.object(
                    flight_ui, "FinalizationWorker", return_value=operation,
                ) as factory:
                    ui.create_local_pdf()
                confirm.assert_not_called()
                self.assertIs(ui.dispatch, staged if loaded else None)
                self.assertEqual([staged if loaded else previous], seen)
                self.assertEqual(staged if loaded else previous, load_dispatch(directory))
                self.assertEqual(("en", "cs", "de", "sv"), factory.call_args.args[0][0].languages)
                self.assertIs(operation, ui.automatic_operation)
                ui.revoke_transport.assert_not_called()


    def test_dispatch_failure_does_not_save_identity(self) -> None:
        """Leave initial account setup pending when the provider rejects a fetch.

        Returns:
            None: No account or session file is written after an OFP failure.
        """
        ui = self._ui(self._host())
        ui.dispatch_results.put(("123", None, None, "No OFP"))
        with patch.object(flight_ui, "atomic_write_json") as save, patch.object(
            flight_ui.messagebox, "showerror"
        ):
            ui.accept_simbrief()
            save.assert_not_called()
            self.assertIsNone(ui.dispatch)

    def test_new_explicit_dispatch_load_discards_cancelled_previous_results(self) -> None:
        """Permit another flight's OFP load after one-click Finish stops imports.

        Returns:
            None: A finished provider's cancelled response is discarded before
                starting the new fetch, so old evidence cannot be revived.
        """
        ui = self._ui(self._host())
        ui.lease = object()
        ui.dispatch_cancelled = True
        ui.dispatch_results.put(("previous", None, None, "cancelled response"))
        with patch.object(flight_ui, "read_document", return_value={"identity": "123"}), patch.object(
            flight_ui.threading, "Thread",
        ) as factory:
            ui.load_simbrief()
        self.assertFalse(ui.dispatch_cancelled)
        self.assertTrue(ui.dispatch_results.empty())
        factory.return_value.start.assert_called_once_with()

    def test_late_dispatch_cannot_cross_active_sessions(self) -> None:
        """Reject a result admitted for a different flight before any attachment.

        Returns:
            None: The old and new session files remain unchanged.
        """
        from uploader.flight_simbrief import DispatchPlan

        ui = self._ui(self._host())
        plan = DispatchPlan("LKPR", "EDDF", "DCT", "A320", "120", "5000", "kgs", "2610", "2026-10-18")
        ui.dispatch_results.put(("123", plan, ui.directory / "old", ""))
        ui.recorder = SimpleNamespace(directory=ui.directory / "new", is_alive=lambda: True)
        with patch.object(flight_ui, "atomic_write_json"), patch.object(
            plan, "save"
        ) as attach, patch.object(flight_ui.messagebox, "showinfo"):
            ui.accept_simbrief()
            attach.assert_not_called()
            self.assertIsNone(ui.dispatch)

    def test_historical_dispatch_uses_its_explicit_target_without_confirmation(self) -> None:
        """Accept a fetched OFP for the historical target selected at admission.

        Returns:
            None: The explicit fetch saves only its captured historical target,
                without a second route confirmation or success message window.
        """
        from uploader.flight_simbrief import DispatchPlan

        ui = self._ui(self._host())
        plan = DispatchPlan("LKPR", "EDDF", "DCT", "A320", "120", "5000", "kgs", "2610", "2026-10-18")
        ui.dispatch_results.put(("123", plan, ui.directory / "old", ""))
        with patch.object(flight_ui, "atomic_write_json"), patch.object(
            plan, "save"
        ) as attach, patch.object(flight_ui.messagebox, "askyesno") as confirm, patch.object(
            flight_ui.messagebox, "showinfo"
        ) as info:
            ui.accept_simbrief()
            confirm.assert_not_called()
            info.assert_not_called()
            attach.assert_called_once_with(ui.directory / "old")


    def test_dispatch_indicator_follows_session_and_keeps_old_plan_on_failure(self) -> None:
        """Show a loaded route only for its staged or matching current session.

        Returns:
            None: Failed reloading preserves accepted evidence; another session
                cannot inherit the previous session's loaded indicator.
        """
        from uploader.flight_simbrief import DispatchPlan

        ui = self._ui(self._host())
        plan = DispatchPlan("LKPR", "EDDF", "DCT", "A320", "120", "5000", "kgs", "2610", "2026-10-18")
        ui.dispatch = plan
        ui.refresh_dispatch_status()
        ui.simbrief_button_var.set.assert_called_with("simbrief_ready")
        ui.simbrief_status_var.set.assert_called_with("simbrief_next_flight: LKPR → EDDF")
        ui.dispatch_results.put(("123", None, None, "offline"))
        with patch.object(flight_ui.messagebox, "showerror"):
            ui.accept_simbrief()
        self.assertIs(plan, ui.dispatch)
        ui.dispatch = None
        ui.active_dispatch = plan
        ui.active_dispatch_directory = ui.directory / "old"
        ui.recorder = SimpleNamespace(directory=ui.directory / "new")
        ui.refresh_dispatch_status()
        ui.simbrief_button_var.set.assert_called_with("simbrief_load")
        ui.simbrief_status_var.set.assert_called_with("")

    def test_sorting_uses_utc_time_and_numeric_counts_without_rebuilding_tree(self) -> None:
        """Sort actual flight times and counts while preserving native tree state.

        Returns:
            None: Header toggles reorder existing items without deleting,
                collapsing or resetting the user's multi-selection.
        """
        ui = self._ui(self._host())
        ui.report_rows = {
            "a": SavedFlight(ui.directory / "a", "2026-10-18T12:00:00+02:00", "Zulu", 20),
            "b": SavedFlight(ui.directory / "b", "2026-10-18T11:00:00Z", "alpha", 3),
        }
        ui.report_tree = Mock()
        ui.report_tree.winfo_exists.return_value = True
        ui.report_tree.get_children.side_effect = lambda parent="": ("a", "b") if not parent else ()
        ui.apply_report_sort()
        self.assertEqual(["b", "a"], [call.args[0] for call in ui.report_tree.move.call_args_list])
        ui.report_tree.move.reset_mock()
        ui.sort_reports("started")
        self.assertEqual(["a", "b"], [call.args[0] for call in ui.report_tree.move.call_args_list])
        ui.report_tree.move.reset_mock()
        ui.sort_reports("samples")
        self.assertEqual(["b", "a"], [call.args[0] for call in ui.report_tree.move.call_args_list])
        ui.report_tree.move.reset_mock()
        ui.sort_reports("#0")
        self.assertEqual(["b", "a"], [call.args[0] for call in ui.report_tree.move.call_args_list])
        ui.report_tree.delete.assert_not_called()
        ui.report_tree.selection_set.assert_not_called()
        ui.report_tree.item.assert_not_called()

    def test_dialog_is_placed_near_its_invoking_window(self) -> None:
        """Center secondary windows over their owner on a large desktop.

        Returns:
            None: Requested geometry remains beside a far-right parent window.
        """
        owner = Mock()
        parent = owner.winfo_toplevel.return_value
        parent.winfo_rootx.return_value = 3000
        parent.winfo_rooty.return_value = 1000
        parent.winfo_width.return_value = 1000
        parent.winfo_height.return_value = 600
        dialog = Mock()
        dialog.winfo_reqwidth.return_value = 400
        dialog.winfo_reqheight.return_value = 200
        flight_ui.place_dialog(dialog, owner)
        dialog.transient.assert_called_once_with(parent)
        dialog.geometry.assert_called_once_with("400x200+3300+1200")
        parent.winfo_rootx.return_value = -1000
        dialog.winfo_screenwidth.return_value = 1920
        dialog.geometry.reset_mock()
        flight_ui.place_dialog(dialog, owner)
        dialog.geometry.assert_called_once_with("400x200-2220+1200")


if __name__ == "__main__":
    unittest.main()
