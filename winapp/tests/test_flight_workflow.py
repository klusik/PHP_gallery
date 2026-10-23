# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_flight_workflow.py
# Module Type: Windows Tooling Regression Test
# Purpose: Verify finalization receipts, consent races and measured progress.
# Responsibilities:
#   - Exercise local report and remote delivery failure/retry boundaries.
#   - Guard revoke ordering, cancellation joins and local credential cleanup.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Regression coverage for recoverable Stop and Revoke finalization."""

import hashlib
import json
import tempfile
import threading
import unittest
from pathlib import Path
from types import ModuleType, SimpleNamespace
from unittest.mock import Mock, patch

import uploader.flight_workflow as workflow
from uploader.flight_jobs import SessionPlan, connection_binding, report_revisions


# Type: float; Units: seconds; Scope: worker synchronization regression tests.
# Consumers: event waits and bounded joins below.
# Rationale: tests should fail promptly if a worker does not reach a barrier.
EVENT_WAIT_SECONDS = 3.0
# Type: str; Units: report bytes; Scope: deterministic renderer stub.
# Consumers: _render_report and receipt checksum assertions.
# Rationale: small fixed payloads isolate orchestration from PDF implementation.
PDF_FIXTURE_PREFIX = b"%PDF-test-"


class FlightWorkflowTests(unittest.TestCase):
    """Verify finalization only acknowledges work after durable boundaries."""

    def test_abandon_retains_files_and_receipts_without_server_requests(self) -> None:
        """Abandon pending delivery while preserving evidence saved during stop.

        Returns:
            None: No analysis, rendering, delivery or revoke is attempted; the
                GUI must acknowledge local key persistence before completion.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(Path(temporary) / "flight", ("en", "cs"))
            plan.save()
            raw = plan.directory / "samples.jsonl"
            raw.write_bytes(b"retained raw data")
            pdf = plan.directory / "retained.pdf"
            pdf.write_bytes(b"%PDF-retained")
            # A predecessor may acknowledge EN after the new UI captured plan.
            updated = SessionPlan.load(plan.directory)
            updated.receipts["en"] = "a" * 64
            updated.save()
            revoke = Mock()
            forget = Mock()
            worker = workflow.FinalizationWorker(
                [plan], "https://deleted.test/api", "revoked-key", Mock(),
                revoke, False, forget=forget,
            )
            with patch.object(workflow, "analyze_session") as analyze:
                worker.run()
            saved = SessionPlan.load(plan.directory)
            self.assertTrue(saved.abandoned)
            self.assertTrue(saved.completed)
            self.assertEqual({"en": "a" * 64}, saved.receipts)
            self.assertEqual(b"retained raw data", raw.read_bytes())
            self.assertEqual(b"%PDF-retained", pdf.read_bytes())
            analyze.assert_not_called()
            revoke.assert_not_called()
            forget.assert_called_once_with()
            self.assertEqual("saving_key", worker.snapshot().phase)
            self.assertFalse(worker.server_request_sent)
            with patch.object(worker, "is_alive", return_value=True):
                self.assertFalse(worker.request_revoke())
            worker.confirm_local_cleanup()
            self.assertEqual("completed", worker.snapshot().phase)

    def test_abandon_empty_plan_and_stop_timeout_keep_cleanup_order(self) -> None:
        """Recover an obsolete saved key even when there are no pending flights.

        Returns:
            None: Empty plans reach local cleanup; timeout or cancellation never
                abandons pending work or queues credential removal.
        """
        forget = Mock()
        worker = workflow.FinalizationWorker(
            [], "https://deleted.test/api", "revoked-key", Mock(), Mock(),
            False, forget=forget,
        )
        worker.run()
        forget.assert_called_once_with()
        self.assertEqual("saving_key", worker.snapshot().phase)
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(Path(temporary) / "flight", ("en",))
            plan.save()
            before = (plan.directory / "completion.json").read_bytes()
            for error in (TimeoutError("still stopping"), InterruptedError("cancelled")):
                with self.subTest(error=type(error).__name__):
                    forget.reset_mock()
                    worker = workflow.FinalizationWorker(
                        [plan], "https://deleted.test/api", "revoked-key",
                        Mock(side_effect=error), Mock(), False, forget=forget,
                    )
                    worker.run()
                    forget.assert_not_called()
                    self.assertEqual(
                        "cancelled" if isinstance(error, InterruptedError) else "error",
                        worker.snapshot().phase,
                    )
                    self.assertEqual(before, (plan.directory / "completion.json").read_bytes())

    def test_saved_upload_preserves_old_plan_and_retries_with_replacement_key(self) -> None:
        """Retain acknowledged saved editions across an upload failure and retry.

        Returns:
            None: New credentials upload exact retained files, persist separate
                receipts and never render, drain, mutate the old plan or revoke.
        """
        import uploader.flight_delivery as delivery

        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(Path(temporary) / "flight", ("en", "cs"), local_only=True)
            plan.completed = True
            plan.save()
            original = (plan.directory / "completion.json").read_bytes()
            (plan.directory / "session.json").write_text(json.dumps({"recording_ended": True}), encoding="utf-8")
            reports = plan.directory / "reports" / "manual-test"
            reports.mkdir(parents=True)
            for language in plan.languages:
                (reports / (language + ".pdf")).write_bytes(b"%PDF-saved-" + language.encode("ascii"))
            revision = report_revisions(plan.directory)[0]
            drain = Mock()
            revoke = Mock()
            receipt = {"id": "a" * 32, "sha256": "b" * 64}
            first = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "replacement-key", drain,
                revoke, False, saved_revision=revision, target_gallery="Flights/A320",
            )
            with (
                patch.object(delivery, "saved_report_destination") as destination,
                patch.object(delivery, "deliver_report", side_effect=[receipt, OSError("offline")]),
                patch.object(workflow, "analyze_session") as analyze,
            ):
                first.run()
            self.assertEqual("error", first.snapshot().phase)
            destination.assert_called_once_with("https://gallery.test/api", "replacement-key", "Flights/A320")
            receipt_path = plan.directory / "upload-receipts.json"
            self.assertEqual(1, len(json.loads(receipt_path.read_text(encoding="utf-8"))["receipts"]))
            retry = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "replacement-key", drain,
                revoke, False, saved_revision=revision, target_gallery="Flights/A320",
            )
            with (
                patch.object(delivery, "saved_report_destination"),
                patch.object(delivery, "deliver_report", return_value=receipt) as upload,
            ):
                retry.run()
            self.assertEqual("completed", retry.snapshot().phase)
            self.assertEqual(2, upload.call_count)
            self.assertEqual(2, len(json.loads(receipt_path.read_text(encoding="utf-8"))["receipts"]))
            self.assertEqual(original, (plan.directory / "completion.json").read_bytes())
            drain.assert_not_called()
            revoke.assert_not_called()
            analyze.assert_not_called()
            cancelled = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "replacement-key", drain,
                revoke, False, saved_revision=revision, target_gallery="Flights/A320",
            )
            cancelled.cancel.set()
            with patch.object(delivery, "saved_report_destination") as destination:
                cancelled.run()
            self.assertEqual("cancelled", cancelled.snapshot().phase)
            destination.assert_not_called()
            self.assertEqual(original, (plan.directory / "completion.json").read_bytes())

    def _plan(
        self,
        directory: Path,
        languages: tuple[str, ...] = ("en",),
        reports_enabled: bool = True,
        local_only: bool = False,
    ) -> SessionPlan:
        """Create one pending durable task for a worker test.

        Args:
            directory (Path): Session directory owned by this test.
            languages (tuple[str, ...]): Required report editions.
            reports_enabled (bool): Whether a report must be rendered.
            local_only (bool): Whether delivery was explicitly declined.

        Returns:
            SessionPlan: Pending plan with the current connection fingerprint.
        """
        directory.mkdir(parents=True, exist_ok=True)
        (directory / "session.json").write_text(json.dumps({
            "recording_ended": True, "started_utc": "2026-10-18T09:46:05Z",
            "aircraft": "Fixture aircraft",
        }), encoding="utf-8")
        return SessionPlan(
            directory=directory,
            binding=connection_binding("https://gallery.test/api", "test-key"),
            languages=languages,
            reports_enabled=reports_enabled,
            local_only=local_only,
        )

    def _analysis(self, samples: bool = True, landings: bool = True) -> SimpleNamespace:
        """Create the smallest analysis shape consumed by the workflow.

        Args:
            samples (bool): Whether recorder samples exist.
            landings (bool): Whether verified landing evidence exists.

        Returns:
            SimpleNamespace: Analysis with explicit sample and landing lists.
        """
        completed = samples and landings
        payload = {
            "session_id": "fixture-session",
            "simulator": "MSFS 2024",
            "aircraft": "Fixture aircraft",
            "completed": completed,
            "duration_seconds": 120.0,
            "distance_nm": 0.0,
            "samples": [{"session_elapsed_seconds": 0.0}] if samples else [],
            "landings": [{"index": 1}] if landings else [],
            "warnings": [],
            "events": [],
        }
        return SimpleNamespace(
            samples=[object()] if samples else [],
            landings=[object()] if landings else [],
            completed=completed,
            to_payload=lambda: payload,
        )

    def _render_report(
        self,
        analysis: SimpleNamespace,
        language: str,
        destination: Path,
    ) -> Path:
        """Write deterministic renderer-stub bytes at the requested destination.

        Args:
            analysis (SimpleNamespace): Fixture with samples, landings,
                completed and to_payload fields; unused by this stub.
            language (str): Report language encoded in fixture bytes.
            destination (Path): Exact report path requested by the worker.

        Returns:
            Path: Created fixture report path.
        """
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(PDF_FIXTURE_PREFIX + language.encode("ascii"))
        return destination

    def _report_modules(self) -> tuple[ModuleType, ModuleType]:
        """Create importable delivery and report stubs for one test.

        Returns:
            tuple[ModuleType, ModuleType]: Delivery module and report module.
        """
        delivery = ModuleType("uploader.flight_delivery")
        delivery.check_report_capability = Mock(return_value=True)
        delivery.deliver_report = Mock()
        report = ModuleType("uploader.flight_report")
        report.REPORT_TEMPLATE_VERSION = "test"
        report.render_report = Mock(side_effect=self._render_report)
        return delivery, report

    def test_no_samples_completes_without_creating_blank_report(self) -> None:
        """Turn empty telemetry into photograph-only completion without a PDF.

        Returns:
            None: Assert no renderer call and no fabricated report.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(Path(temporary) / "flight-empty")
            delivery, report = self._report_modules()
            worker = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "test-key",
                lambda cancel, deadline: None, lambda: None, False,
            )
            with (
                patch.object(workflow, "analyze_session", return_value=self._analysis(False)),
                patch.dict("sys.modules", {
                    "uploader.flight_delivery": delivery,
                    "uploader.flight_report": report,
                }),
            ):
                worker.run()
            self.assertTrue(plan.completed)
            self.assertFalse(plan.reports_enabled)
            report.render_report.assert_not_called()
            delivery.deliver_report.assert_not_called()

    def test_partial_finish_generates_reports_before_revoke_without_landing_gate(self) -> None:
        """Finish a partial recording without inventing missing flight events.

        Returns:
            None: Incomplete analysis reaches the renderer unchanged, all
                required receipts are stored, then revoke is submitted without
                requiring an extra local-only decision or landing evidence.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(Path(temporary) / "flight-partial")
            delivery, report = self._report_modules()
            analysis = self._analysis(samples=True, landings=False)
            revoke = Mock(side_effect=lambda: self.assertTrue(plan.completed))
            worker = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "test-key",
                lambda cancel, deadline: None, revoke, True,
            )
            with (
                patch.object(
                    workflow, "analyze_session",
                    return_value=analysis,
                ),
                patch.dict("sys.modules", {
                    "uploader.flight_delivery": delivery,
                    "uploader.flight_report": report,
                }),
            ):
                worker.run()
            self.assertTrue(plan.completed)
            self.assertFalse(analysis.completed)
            self.assertEqual([], analysis.landings)
            self.assertEqual(len(plan.languages), report.render_report.call_count)
            self.assertEqual(len(plan.languages), delivery.deliver_report.call_count)
            self.assertTrue(all(call.args[0] is analysis for call in report.render_report.call_args_list))
            revoke.assert_called_once_with()
            self.assertEqual("saving_key", worker.snapshot().phase)

    def test_second_language_failure_keeps_first_durable_receipt(self) -> None:
        """Retain a confirmed first-language receipt when the next upload fails.

        Returns:
            None: Assert receipt persistence, incomplete retry state and no
                revocation while any required PDF remains unacknowledged.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(
                Path(temporary) / "flight-multilingual",
                languages=("en", "cs"),
            )
            delivery, report = self._report_modules()
            delivery.deliver_report.side_effect = [
                None,
                RuntimeError("Swedish/Czech delivery failure"),
            ]
            revoke = Mock()
            worker = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "test-key",
                lambda cancel, deadline: None, revoke, True,
            )
            with (
                patch.object(workflow, "analyze_session", return_value=self._analysis()),
                patch.dict("sys.modules", {
                    "uploader.flight_delivery": delivery,
                    "uploader.flight_report": report,
                }),
            ):
                worker.run()
                restored = SessionPlan.load(plan.directory)
            expected = hashlib.sha256(
                (PDF_FIXTURE_PREFIX + b"en")
            ).hexdigest()
            self.assertEqual(restored.receipts, {"en": expected})
            self.assertFalse(restored.completed)
            self.assertEqual(delivery.deliver_report.call_count, 2)
            self.assertEqual(worker.snapshot().phase, "error")
            revoke.assert_not_called()

    def test_revoke_waits_for_every_plan_and_cleanup_ack(self) -> None:
        """Submit key revocation only after plans and receipts are complete.

        Returns:
            None: Assert task barrier and no 100 percent before key cleanup.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            plans = [
                self._plan(root / "flight-a", reports_enabled=False),
                self._plan(root / "flight-b", reports_enabled=False),
            ]
            revoke = Mock(
                side_effect=lambda: self.assertTrue(
                    all(plan.completed for plan in plans)
                )
            )
            worker = workflow.FinalizationWorker(
                plans, "https://gallery.test/api", "test-key",
                lambda cancel, deadline: None, revoke, True,
            )
            with patch.object(
                workflow, "analyze_session",
                return_value=self._analysis(samples=False),
            ):
                worker.run()
            revoke.assert_called_once()
            self.assertEqual(worker.snapshot().phase, "saving_key")
            self.assertLess(worker.snapshot().done, worker.snapshot().total)
            worker.confirm_local_cleanup("key file remains")
            self.assertLess(worker.snapshot().done, worker.snapshot().total)
            worker.confirm_local_cleanup()
            self.assertEqual(worker.snapshot().done, worker.snapshot().total)
            self.assertEqual(worker.snapshot().phase, "completed")

    def test_stop_without_revoke_completes_work_and_never_calls_revoke(self) -> None:
        """Keep ordinary Stop separate from the optional revoke final step.

        Returns:
            None: Assert Stop drains and completes without key revocation.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(
                Path(temporary) / "flight-stop",
                reports_enabled=False,
            )
            revoke = Mock()
            worker = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "test-key",
                lambda cancel, deadline: None, revoke, False,
            )
            with patch.object(
                workflow, "analyze_session",
                return_value=self._analysis(samples=False),
            ):
                worker.run()
            self.assertTrue(plan.completed)
            revoke.assert_not_called()
            self.assertEqual(worker.snapshot().phase, "completed")

    def test_revoke_consent_can_join_then_withdraw_during_drain(self) -> None:
        """Withdraw the final revoke step while accepted work keeps running.

        Returns:
            None: Assert no drain cancellation and no revoke submission.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(
                Path(temporary) / "flight-join",
                reports_enabled=False,
            )
            entered = threading.Event()
            release = threading.Event()

            def wait_for_drain(
                cancel: threading.Event,
                deadline: float,
            ) -> None:
                """Hold the worker at its accepted-work drain barrier.

                Args:
                    cancel (threading.Event): Worker cancellation signal.
                    deadline (float): Monotonic deadline supplied by workflow.

                Returns:
                    None: Release allows queued task analysis to continue.
                """
                entered.set()
                if not release.wait(EVENT_WAIT_SECONDS):
                    raise TimeoutError("test drain barrier timed out")

            revoke = Mock()
            worker = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "test-key",
                wait_for_drain, revoke, False,
            )
            with patch.object(
                workflow, "analyze_session",
                return_value=self._analysis(samples=False),
            ):
                worker.start()
                self.assertTrue(entered.wait(EVENT_WAIT_SECONDS))
                self.assertTrue(worker.request_revoke())
                self.assertTrue(worker.cancel_revoke())
                self.assertFalse(worker.cancel.is_set())
                release.set()
                worker.join(EVENT_WAIT_SECONDS)
            self.assertFalse(worker.is_alive())
            self.assertTrue(plan.completed)
            revoke.assert_not_called()

    def test_local_cleanup_ack_during_revoke_is_not_overwritten(self) -> None:
        """Preserve fast GUI cleanup acknowledgement after revoke returns.

        Returns:
            None: Assert the worker remains completed at full progress.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(
                Path(temporary) / "flight-fast-cleanup",
                reports_enabled=False,
            )
            worker_holder: list[workflow.FinalizationWorker] = []

            def revoke_and_acknowledge() -> None:
                """Acknowledge durable local key cleanup inside the revoke call.

                Returns:
                    None: Marks cleanup complete before transport callback returns.
                """
                worker_holder[0].confirm_local_cleanup()

            worker = workflow.FinalizationWorker(
                [plan],
                "https://gallery.test/api",
                "test-key",
                lambda cancel, deadline: None,
                revoke_and_acknowledge,
                True,
            )
            worker_holder.append(worker)
            with patch.object(
                workflow, "analyze_session",
                return_value=self._analysis(samples=False),
            ):
                worker.run()
            progress = worker.snapshot()
            self.assertEqual(progress.phase, "completed")
            self.assertEqual(progress.done, progress.total)
            self.assertEqual(progress.error, "")

    def test_post_submission_cancel_and_progress_snapshot_do_not_deadlock(self) -> None:
        """Protect submitted revoke from consent reversal without locking the UI.

        Returns:
            None: Assert cancellation is refused and snapshots remain responsive.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(
                Path(temporary) / "flight-revoke",
                reports_enabled=False,
            )
            entered = threading.Event()
            release = threading.Event()

            def revoke_blocked() -> None:
                """Pause the simulated remote revoke after submission begins.

                Returns:
                    None: Release represents completion of the remote request.
                """
                entered.set()
                if not release.wait(EVENT_WAIT_SECONDS):
                    raise TimeoutError("test revoke barrier timed out")

            worker = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "test-key",
                lambda cancel, deadline: None, revoke_blocked, True,
            )
            with patch.object(
                workflow, "analyze_session",
                return_value=self._analysis(samples=False),
            ):
                worker.start()
                self.assertTrue(entered.wait(EVENT_WAIT_SECONDS))
                self.assertTrue(worker.server_request_sent)
                self.assertFalse(worker.cancel_revoke())
                snapshot_thread = threading.Thread(target=worker.snapshot)
                snapshot_thread.start()
                snapshot_thread.join(EVENT_WAIT_SECONDS)
                responsive = not snapshot_thread.is_alive()
                release.set()
                worker.join(EVENT_WAIT_SECONDS)
                snapshot_thread.join(EVENT_WAIT_SECONDS)
            self.assertTrue(responsive)
            self.assertFalse(worker.is_alive())
            self.assertEqual(worker.snapshot().phase, "saving_key")
            self.assertLess(worker.snapshot().done, worker.snapshot().total)

    def test_eta_uses_observed_transfer_rate_and_unknown_stays_unknown(self) -> None:
        """Estimate remaining upload time only from actual byte progress.

        Returns:
            None: Assert measured estimate and no estimate without throughput.
        """
        worker = workflow.FinalizationWorker(
            [], "https://gallery.test/api", "test-key",
            lambda cancel, deadline: None, lambda: None, False,
        )
        worker.update("uploading")
        worker._progress.phase_started = 10.0
        with patch("uploader.flight_workflow.time.monotonic", return_value=25.0):
            worker.transfer_progress(25, 100)
        self.assertEqual(worker.snapshot().eta_seconds, 45.0)
        with patch("uploader.flight_workflow.time.monotonic", return_value=30.0):
            worker.transfer_progress(0, 100)
        self.assertIsNone(worker.snapshot().eta_seconds)


    def test_local_regeneration_preserves_delivery_and_every_prior_pdf(self) -> None:
        """Re-render a stopped partial flight into distinct local revisions.

        Returns:
            None: Both editions are produced without drain/network/revoke;
                earlier PDFs, raw telemetry and completion bytes stay identical.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(Path(temporary) / "flight", ("en", "cs"), False)
            plan.receipts["en"] = "a" * 64
            plan.save()
            (plan.directory / "session.json").write_text(
                json.dumps({"recording_ended": True}), encoding="utf-8"
            )
            raw = plan.directory / "samples.jsonl"
            raw.write_text("raw telemetry fixture\n", encoding="utf-8")
            old = plan.directory / "reports" / "previous.pdf"
            old.parent.mkdir()
            old.write_bytes(b"previous verified PDF")
            before = (plan.directory / "completion.json").read_bytes()
            delivery, report = self._report_modules()
            wait, revoke = Mock(), Mock()
            for _attempt in range(2):  # Two deliberate independent render actions.
                worker = workflow.FinalizationWorker(
                    [plan], "", "", wait, revoke, False, local_reports=True,
                )
                with (
                    patch.object(workflow, "analyze_session", return_value=self._analysis(True, False)),
                    patch.dict("sys.modules", {
                        "uploader.flight_delivery": delivery,
                        "uploader.flight_report": report,
                    }),
                ):
                    worker.run()
                self.assertEqual("completed", worker.snapshot().phase)
                self.assertEqual(worker.snapshot().total, worker.snapshot().done)
                self.assertFalse(worker.request_revoke())
            self.assertEqual(0, len(list(old.parent.glob("manual-*"))))
            self.assertEqual(4, len(list(old.parent.glob("* - v*.pdf"))))
            self.assertEqual(b"previous verified PDF", old.read_bytes())
            self.assertEqual("raw telemetry fixture\n", raw.read_text(encoding="utf-8"))
            self.assertEqual(before, (plan.directory / "completion.json").read_bytes())
            self.assertFalse(plan.completed)
            wait.assert_not_called()
            revoke.assert_not_called()
            delivery.check_report_capability.assert_not_called()
            delivery.deliver_report.assert_not_called()

    def test_local_generation_refuses_active_or_empty_session(self) -> None:
        """Preserve live data and reject an empty stopped report explicitly.

        Returns:
            None: No renderer runs and no completion receipt changes; only a
                validated stopped session may retain a local failure document.
        """
        for ended in (False, True):
            with self.subTest(ended=ended), tempfile.TemporaryDirectory() as temporary:
                plan = self._plan(Path(temporary) / "flight")
                plan.save()
                (plan.directory / "session.json").write_text(
                    json.dumps({"recording_ended": ended}), encoding="utf-8"
                )
                before = (plan.directory / "completion.json").read_bytes()
                worker = workflow.FinalizationWorker(
                    [plan], "", "", Mock(), Mock(), False, local_reports=True,
                )
                with patch.object(workflow, "analyze_session", return_value=self._analysis(False)) as analyze:
                    worker.run()
                self.assertEqual("error", worker.snapshot().phase)
                self.assertEqual(before, (plan.directory / "completion.json").read_bytes())
                self.assertFalse((plan.directory / "reports").exists())
                if ended:
                    state = json.loads((plan.directory / "manual-report.json").read_text(encoding="utf-8"))
                    self.assertIn("No recorded telemetry", state["error"])
                else:
                    analyze.assert_not_called()
                    self.assertFalse((plan.directory / "manual-report.json").exists())

    def test_local_cancellation_keeps_completed_editions(self) -> None:
        """Cancel between report editions without removing a finished PDF.

        Returns:
            None: One rendered edition remains recoverable, cancellation is
                recorded, and remote completion remains pending.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(Path(temporary) / "flight", ("en", "cs"))
            plan.save()
            (plan.directory / "session.json").write_text(
                json.dumps({"recording_ended": True}), encoding="utf-8"
            )
            worker = workflow.FinalizationWorker(
                [plan], "", "", Mock(), Mock(), False, local_reports=True,
            )
            _delivery, report = self._report_modules()

            def render_then_cancel(
                analysis: SimpleNamespace, language: str, destination: Path
            ) -> Path:
                """Write the first edition and cancel before the next starts.

                Args:
                    analysis (SimpleNamespace): Normalized fixture analysis.
                    language (str): Current requested edition.
                    destination (Path): Unique revision PDF destination.

                Returns:
                    Path: The completed first edition, retained after cancellation.
                """
                result = self._render_report(analysis, language, destination)
                worker.cancel.set()
                return result

            report.render_report.side_effect = render_then_cancel
            with (
                patch.object(workflow, "analyze_session", return_value=self._analysis()),
                patch.dict("sys.modules", {"uploader.flight_report": report}),
            ):
                worker.run()
            self.assertEqual("cancelled", worker.snapshot().phase)
            self.assertEqual(1, len(list(plan.directory.glob("reports/* - v*.pdf"))))
            self.assertFalse(SessionPlan.load(plan.directory).completed)

    def test_local_reports_refuse_redirected_output_without_touching_target(self) -> None:
        """Reject a report-folder symlink before any local result is persisted.

        Returns:
            None: A redirected output remains untouched; systems without
                symlink privileges skip this filesystem-specific fixture.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            plan = self._plan(root / "flight")
            external = root / "external"
            external.mkdir()
            try:
                (plan.directory / "reports").symlink_to(external, target_is_directory=True)
            except OSError:
                self.skipTest("Symlink creation is unavailable on this runner.")
            worker = workflow.FinalizationWorker(
                [plan], "", "", Mock(), Mock(), False, local_reports=True,
            )
            worker.run()
            self.assertEqual("error", worker.snapshot().phase)
            self.assertIn("redirect", worker.snapshot().error)
            self.assertEqual([], list(external.iterdir()))
            self.assertFalse((plan.directory / "manual-report.json").exists())


if __name__ == "__main__":
    unittest.main()
