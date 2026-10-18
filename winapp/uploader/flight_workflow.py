# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_workflow.py
# Module Type: Flight Finalization Service
# Purpose: Drain accepted work and deliver flight reports before key revocation.
# Responsibilities:
#   - Run recoverable analysis, report and delivery tasks off the GUI thread.
#   - Expose measured progress without inventing a completion deadline.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""One cancellable, connection-bound finalization path for Stop and Revoke."""

import hashlib
import json
import threading
import time
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Callable

from uploader.flight_data import analyze_session
from uploader.flight_jobs import SessionPlan

# Let graceful requests finish while retaining state on slow/disconnected hosts.
# Type: float; Units: seconds; Scope: one finalization operation.
# Consumers: FinalizationWorker and the GUI's worker-drain callback.
# Rationale: five minutes covers normal file stabilization and bounded API waits.
FINALIZATION_DRAIN_SECONDS = 300.0


@dataclass
class FlightProgress:
    """Describe completed tasks and observed transfer time for the GUI."""

    phase: str = "idle"
    detail: str = ""
    done: int = 0
    total: int = 0
    started: float = 0.0
    phase_started: float = 0.0
    bytes_done: int = 0
    bytes_total: int = 0
    eta_seconds: float | None = None
    error: str = ""
    can_cancel: bool = True


class FinalizationWorker(threading.Thread):
    """Execute accepted tasks before an explicitly requested irreversible revoke."""

    def __init__(
        self,
        plans: list[SessionPlan],
        upload_url: str,
        api_key: str,
        wait_for_workers: Callable[[threading.Event, float], None],
        revoke: Callable[[], None],
        wants_revoke: bool,
    ) -> None:
        """Capture the current connection and required durable plans.

        Args:
            plans (list[SessionPlan]): Pending sessions for the captured key only.
            upload_url (str): Saved canonical upload endpoint.
            api_key (str): Saved API key held in memory only.
            wait_for_workers (Callable[[threading.Event, float], None]): Drain
                accepted workers or raise before its monotonic deadline.
            revoke (Callable[[], None]): Send revocation and queue its GUI result.
            wants_revoke (bool): Explicit confirmation for this app session.

        Returns:
            None: Initializes an idle, cancellable finalization thread.
        """
        super().__init__(name="FlightFinalization", daemon=True)
        self.plans = plans
        self.upload_url = upload_url
        self.api_key = api_key
        self.wait_for_workers = wait_for_workers
        self.revoke = revoke
        self.wants_revoke = wants_revoke
        self.cancel = threading.Event()
        self._lock = threading.RLock()
        now = time.monotonic()
        # Drain + analysis and two tasks per selected edition + final endpoint.
        tasks = 1 + sum(
            1 + (len(plan.languages) * 2 if plan.reports_enabled else 0)
            for plan in plans
        ) + int(wants_revoke)
        self._progress = FlightProgress(
            total=tasks, started=now, phase_started=now
        )
        self.server_request_sent = False

    def snapshot(self) -> FlightProgress:
        """Copy progress safely without allowing Tk to read worker-owned state.

        Returns:
            FlightProgress: An immutable-by-convention copy of current counters.
        """
        with self._lock:
            return replace(self._progress)

    def update(self, phase: str, detail: str = "", advance: bool = False) -> None:
        """Publish a phase transition and optionally acknowledge one task.

        Args:
            phase (str): Stable localized phase key.
            detail (str): Human-readable current unit or response information.
            advance (bool): Whether a required task has completed successfully.

        Returns:
            None: Progress snapshot is replaced under the lifecycle lock.
        """
        with self._lock:
            if phase != self._progress.phase:
                self._progress.phase_started = time.monotonic()
                self._progress.bytes_done = 0
                self._progress.bytes_total = 0
                self._progress.eta_seconds = None
            self._progress.phase = phase
            self._progress.detail = detail
            if advance:
                self._progress.done += 1

    def request_revoke(self) -> bool:
        """Join an existing Stop with a newly confirmed final revoke step.

        Returns:
            bool: True when attached before operation completion, otherwise False.
        """
        with self._lock:
            if not self.is_alive() or self._progress.phase in {
                "completed", "error", "cancelled", "saving_key"
            }:
                return False
            if not self.wants_revoke:
                self.wants_revoke = True
                self._progress.total += 1
            return True

    def cancel_revoke(self) -> bool:
        """Withdraw irreversible consent while accepted work still completes.

        Returns:
            bool: False after server submission; True when consent was withdrawn.
        """
        with self._lock:
            if self.server_request_sent:
                return False
            if self.wants_revoke:
                self._progress.total -= 1
            self.wants_revoke = False
            return True

    def transfer_progress(self, done: int, total: int) -> None:
        """Publish actual bytes and a transfer-only remaining-time estimate.

        Args:
            done (int): Acknowledged or transmitted bytes reported by transport.
            total (int): Known total bytes for the current PDF transfer.

        Returns:
            None: Unknown duration remains None until observed throughput exists.
        """
        with self._lock:
            elapsed = time.monotonic() - self._progress.phase_started
            self._progress.bytes_done = max(0, done)
            self._progress.bytes_total = max(0, total)
            self._progress.eta_seconds = (
                (total - done) * elapsed / done
                if done > 0 and total > done and elapsed > 0 else None
            )

    def check_cancelled(self) -> None:
        """Refuse new dependent work after revocation consent is cancelled.

        Returns:
            None: The operation may proceed.

        Raises:
            InterruptedError: Cancellation retains all durable work for retry.
        """
        if self.cancel.is_set():
            raise InterruptedError("Revocation cancelled; pending work retained.")

    def run(self) -> None:
        """Complete durable tasks and submit revoke only through the final barrier.

        Returns:
            None: Success, failure or cancellation is retained in progress/state.
        """
        try:
            self.update("draining")
            self.wait_for_workers(
                self.cancel, time.monotonic() + FINALIZATION_DRAIN_SECONDS
            )
            self.check_cancelled()
            self.update("analyzing", advance=True)
            for plan in self.plans:
                self.check_cancelled()
                self.update("analyzing", plan.session_id)
                try:
                    self.finish_plan(plan)
                except Exception as exc:
                    plan.error = str(exc)
                    plan.save()
                    raise
            # A confirmed Revoke can join while an ordinary Stop renders PDFs.
            # Hold the admission lock from final intent validation to submission.
            while True:
                with self._lock:
                    intent = self.wants_revoke
                if intent:
                    self.wait_for_workers(
                        self.cancel,
                        time.monotonic() + FINALIZATION_DRAIN_SECONDS,
                    )
                with self._lock:
                    self.check_cancelled()
                    if intent != self.wants_revoke:
                        continue
                    if intent:
                        self.server_request_sent = True
                        self._progress.can_cancel = False
                        self.update("revoking")
                        submit = True
                    else:
                        self.update("completed")
                        self._progress.done = self._progress.total
                        submit = False
                    break
            if submit:
                # Native/network waits never hold the snapshot/Tk polling lock.
                self.revoke()
                with self._lock:
                    # A fast GUI acknowledgement may already have completed
                    # durable cleanup while the transport callback returned.
                    if self._progress.phase == "revoking":
                        self.update("saving_key")
        except InterruptedError as exc:
            self.update("cancelled", str(exc))
        except Exception as exc:
            with self._lock:
                self.update("error", str(exc))
                self._progress.error = str(exc)

    def finish_plan(self, plan: SessionPlan) -> None:
        """Analyze and acknowledge all required editions of a single flight.

        Args:
            plan (SessionPlan): Connection-bound pending session task manifest.

        Returns:
            None: Completed flag is written only after required receipts exist.

        Raises:
            RuntimeError: Required landing evidence or delivery capability fails.
        """
        analysis = analyze_session(plan.directory)
        self.update("analyzing", plan.session_id, advance=True)
        if not plan.reports_enabled or not analysis.samples or plan.abandoned:
            # No simulator/disabled recording is normal photograph-only use.
            # Abandonment is an explicit GUI decision, never inferred from error.
            if not analysis.samples:
                plan.reports_enabled = False
            plan.completed = True
            plan.error = (
                "No telemetry; no flight PDF generated."
                if not analysis.samples else ""
            )
            plan.save()
            return
        if not analysis.landings or not analysis.completed:
            raise RuntimeError(
                "No verified landing. Recording is retained; choose local-only "
                "completion or retry after reviewing this partial flight."
            )
        from uploader.flight_delivery import (
            check_report_capability,
            deliver_report,
        )
        from uploader.flight_report import (
            REPORT_TEMPLATE_VERSION,
            render_report,
        )
        if not plan.local_only and not check_report_capability(
            self.upload_url, self.api_key
        ):
            raise RuntimeError(
                "Gallery does not support flight_reports_v1. Update the gallery "
                "or explicitly keep this session locally before revoking."
            )
        analysis_digest = hashlib.sha256(
            json.dumps(
                analysis.to_payload(), sort_keys=True, ensure_ascii=False,
                allow_nan=False,
            ).encode("utf-8")
        ).hexdigest()
        revision = (
            plan.session_id + "-" + str(REPORT_TEMPLATE_VERSION)
            + "-" + analysis_digest[:16]
        )
        for language in plan.languages:
            self.check_cancelled()
            destination = (
                plan.directory / "reports"
                / (revision + "-" + language + ".pdf")
            )
            self.update("generating", language.upper())
            destination.parent.mkdir(parents=True, exist_ok=True)
            pdf = render_report(analysis, language, destination)
            checksum = hashlib.sha256(pdf.read_bytes()).hexdigest()
            self.update("generating", language.upper(), advance=True)
            self.check_cancelled()
            self.update("uploading", language.upper())
            if not plan.local_only and plan.receipts.get(language) != checksum:
                deliver_report(
                    self.upload_url,
                    self.api_key,
                    pdf,
                    plan.session_id,
                    language,
                    revision,
                    self.transfer_progress,
                )
                # Transport returns only after receipt/checksum reconciliation.
                plan.receipts[language] = checksum
                plan.save()
            self.update("uploading", language.upper(), advance=True)
        plan.completed = True
        plan.error = ""
        plan.save()

    def confirm_local_cleanup(self, error: str = "") -> None:
        """Acknowledge GUI-owned credential persistence after server revocation.

        Args:
            error (str): Empty after durable cleanup; otherwise recovery reason.

        Returns:
            None: Reaches 100 percent only after the local key is cleared safely.
        """
        with self._lock:
            self.update("error" if error else "completed", error)
            self._progress.error = error
            if not error:
                self._progress.done = self._progress.total
