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
import tempfile
import threading
import unittest
from pathlib import Path
from types import ModuleType, SimpleNamespace
from unittest.mock import Mock, patch

import uploader.flight_workflow as workflow
from uploader.flight_jobs import SessionPlan, connection_binding


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

    def test_missing_landing_requires_explicit_local_only_choice(self) -> None:
        """Keep partial flight evidence until the user chooses local completion.

        Returns:
            None: Assert error first, then local PDF without remote delivery.
        """
        with tempfile.TemporaryDirectory() as temporary:
            plan = self._plan(Path(temporary) / "flight-partial")
            delivery, report = self._report_modules()
            worker = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "test-key",
                lambda cancel, deadline: None, lambda: None, False,
            )
            with (
                patch.object(
                    workflow, "analyze_session",
                    return_value=self._analysis(samples=True, landings=False),
                ),
                patch.dict("sys.modules", {
                    "uploader.flight_delivery": delivery,
                    "uploader.flight_report": report,
                }),
            ):
                worker.run()
                self.assertFalse(plan.completed)
                self.assertIn("No verified landing", plan.error)
                plan.local_only = True
                plan.reports_enabled = False
                plan.error = ""
                plan.save()
                retry_worker = workflow.FinalizationWorker(
                    [plan], "https://gallery.test/api", "test-key",
                    lambda cancel, deadline: None, lambda: None, False,
                )
                retry_worker.run()
            self.assertTrue(plan.completed)
            report.render_report.assert_not_called()
            delivery.check_report_capability.assert_not_called()
            delivery.deliver_report.assert_not_called()

    def test_second_language_failure_keeps_first_durable_receipt(self) -> None:
        """Retain a confirmed first-language receipt when the next upload fails.

        Returns:
            None: Assert receipt persistence and incomplete retry state.
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
            worker = workflow.FinalizationWorker(
                [plan], "https://gallery.test/api", "test-key",
                lambda cancel, deadline: None, lambda: None, False,
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


if __name__ == "__main__":
    unittest.main()
