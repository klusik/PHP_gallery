# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_workflow.py
# Module Type: Flight Finalization Service
# Purpose: Generate reports, finalize delivery or explicitly abandon a flight.
# Responsibilities:
#   - Run recoverable analysis, report and delivery tasks off the GUI thread.
#   - Expose measured progress without inventing a completion deadline.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Cancellable local PDF revisions and connection-bound Stop/Revoke reports."""

import hashlib
import json
import threading
import time
import uuid
from dataclasses import dataclass, replace
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

from uploader.flight_data import FlightAnalysis, analyze_session
from uploader.flight_render import render_report_variants
from uploader.flight_jobs import (
    REPORT_LANGUAGES, ReportRevision, SessionPlan, connection_binding,
    read_document, report_revisions,
    allocate_report_revision,
)
from uploader.models import JsonValue
from uploader.state_store import atomic_write_json

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
    render_session: str = ""
    rendering_languages: tuple[str, ...] = ()
    rendered_languages: tuple[str, ...] = ()


class FinalizationWorker(threading.Thread):
    """Render/upload PDFs, finalize delivery or retain an abandoned flight."""

    def __init__(
        self,
        plans: list[SessionPlan],
        upload_url: str,
        api_key: str,
        wait_for_workers: Callable[[threading.Event, float], None],
        revoke: Callable[[], None],
        wants_revoke: bool,
        local_reports: bool = False,
        emit: Callable[[str, str], None] | None = None,
        saved_revision: ReportRevision | None = None,
        target_gallery: str = "",
        saved_revisions: tuple[ReportRevision, ...] = (),
        forget: Callable[[], None] | None = None,
    ) -> None:
        """Capture connection-bound finalization or one independent local source.

        Args:
            plans (list[SessionPlan]): Pending sessions for the captured key,
                or one historical session for independent PDF generation/upload.
            upload_url (str): Saved canonical endpoint, empty in local mode.
            api_key (str): Saved key held in memory, empty in local mode.
            wait_for_workers (Callable[[threading.Event, float], None]): Drain
                accepted workers or raise before its monotonic deadline;
                ignored in local mode.
            revoke (Callable[[], None]): Send revocation and queue its GUI result;
                never called in local mode.
            wants_revoke (bool): Explicit Finish click or direct Revoke
                confirmation authorizing this app session's final barrier.
            local_reports (bool): Render one stopped session locally without
                draining workers, uploading, changing receipts or revoking.
            emit (Callable[[str, str], None] | None): Optional thread-safe
                activity sink receiving severity and phase/session details.
            saved_revision (ReportRevision | None): Retained edition files to
                upload independently; None uses ordinary finish/local rendering.
            target_gallery (str): Confirmed gallery folder for saved-PDF upload.
            saved_revisions (tuple[ReportRevision, ...]): Explicit edition
                selections across retained revisions of the same session;
                when empty, saved_revision supplies the single-revision input.
            forget (Callable[[], None] | None): Queue local credential cleanup
                after explicit abandonment; None uses ordinary finalization.
                Abandonment stops workers without requiring delivery or revoke.

        Returns:
            None: Initializes an idle, cancellable finalization thread.

        Raises:
            ValueError: An independent PDF task has multiple sessions, requests
                revocation, mixes modes or lacks a confirmed upload destination.
                Abandonment cannot be combined with another completion mode.
        """
        super().__init__(name="FlightFinalization", daemon=True)
        self.plans = plans
        self.upload_url = upload_url
        self.api_key = api_key
        self.wait_for_workers = wait_for_workers
        self.revoke = revoke
        self.wants_revoke = wants_revoke
        self.local_reports = local_reports
        self.forget = forget
        self.abandonment = forget is not None
        self.emit = emit
        self.saved_revisions = saved_revisions or ((saved_revision,) if saved_revision else ())
        self.saved_revision = self.saved_revisions[0] if self.saved_revisions else None
        self.saved_upload = bool(self.saved_revisions)
        self.target_gallery = target_gallery
        self.local_result: dict[str, JsonValue] | None = None
        if self.abandonment and (local_reports or self.saved_upload or wants_revoke):
            raise ValueError("Abandonment requires local cleanup without delivery or revoke.")
        if local_reports and (len(plans) != 1 or wants_revoke):
            raise ValueError("Local PDF creation requires one session and no revoke.")
        if self.saved_upload and (
            len(plans) != 1 or wants_revoke or local_reports or not target_gallery
        ):
            raise ValueError("Saved PDF upload requires one session and a confirmed gallery.")
        self.cancel = threading.Event()
        self._lock = threading.RLock()
        now = time.monotonic()
        # Drain + analysis and two tasks per selected edition + final endpoint.
        tasks = 1 + sum(
            1 + (len(plan.languages) * 2 if plan.reports_enabled else 0)
            for plan in plans
        ) + int(wants_revoke)
        if local_reports:
            tasks = 1 + len(plans[0].languages)
        if self.saved_upload:
            tasks = 1 + sum(len(item.files) for item in self.saved_revisions)
        if self.abandonment:
            # One stop barrier, one durable decision per plan, one key cleanup.
            tasks = 2 + len(plans)
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
            changed = phase != self._progress.phase or detail != self._progress.detail
            if phase != self._progress.phase:
                self._progress.phase_started = time.monotonic()
                self._progress.bytes_done = 0
                self._progress.bytes_total = 0
                self._progress.eta_seconds = None
            self._progress.phase = phase
            self._progress.detail = detail
            if advance:
                self._progress.done += 1
        if self.emit is not None and (changed or advance):
            level = "error" if phase == "error" else "info"
            sessions = ",".join(plan.session_id for plan in self.plans)
            try:
                self.emit(
                    level,
                    f"[flight-report] session={sessions}; phase={phase}; {detail}",
                )
            except Exception:
                pass

    def request_revoke(self) -> bool:
        """Join an existing Stop with a newly confirmed final revoke step.

        Returns:
            bool: True when attached before operation completion; False for
                independent PDF tasks, abandonment or closed admission.
        """
        with self._lock:
            if (
                self.local_reports or self.saved_upload or self.abandonment
                or not self.is_alive()
                or self._progress.phase in {"completed", "error", "cancelled", "saving_key"}
            ):
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
        """Refuse new dependent work after explicit operation cancellation.

        Returns:
            None: The operation may proceed.

        Raises:
            InterruptedError: Cancellation retains all durable work for retry.
        """
        if self.cancel.is_set():
            raise InterruptedError(
                "Local PDF creation cancelled; saved reports retained."
                if self.local_reports else
                "Saved PDF upload cancelled; acknowledged reports retained."
                if self.saved_upload else
                "Abandonment cancelled; local key retained."
                if self.abandonment else
                "Revocation cancelled; pending work retained."
            )

    def run(self) -> None:
        """Complete durable tasks or abandon pending delivery locally.

        Returns:
            None: Success, failure or cancellation is retained in progress/state.
                Abandonment retains files and awaits GUI credential persistence.
        """
        try:
            if self.forget is not None:
                self.update("draining")
                self.wait_for_workers(
                    self.cancel, time.monotonic() + FINALIZATION_DRAIN_SECONDS
                )
                self.check_cancelled()
                self.update("abandoning", advance=True)
                for pending in self.plans:
                    # The cancelled predecessor may have saved a receipt while
                    # stopping. Reload after it exits; never overwrite that work.
                    plan = SessionPlan.load(pending.directory)
                    if not plan.completed:
                        plan.abandoned = True
                        plan.completed = True
                        plan.error = "Explicitly abandoned; local files retained."
                        plan.save()
                    self.update("abandoning", plan.session_id, advance=True)
                # Publish before queuing the event: a fast Tk acknowledgement
                # must never be overwritten with an unfinished cleanup phase.
                self.update("saving_key")
                self.forget()
                return
            if self.local_reports:
                self.generate_local_reports()
                return
            if self.saved_upload:
                self.upload_saved_reports()
                return
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
            self.save_local_result("cancelled", str(exc))
            self.update("cancelled", str(exc))
        except Exception as exc:
            detail = str(exc)
            if isinstance(exc, ModuleNotFoundError) and exc.name in {
                "reportlab", "pypdf", "PIL",
            }:
                # Source launches can use a different interpreter from the
                # installer. Keep recovery tied to that executable and retain
                # recorded data instead of treating missing packages as damage.
                detail = "pdf_dependency_missing:" + str(exc.name)
            self.save_local_result("error", f"{type(exc).__name__}: {detail}")
            with self._lock:
                self.update("error", detail)
                self._progress.error = detail

    def save_local_result(self, phase: str, error: str = "") -> None:
        """Persist local render progress separately from remote delivery state.

        Args:
            phase (str): Local generation phase or terminal result.
            error (str): Factual failure detail, empty after success.

        Returns:
            None: Updates manual-report.json only after source validation;
                persistence failure is logged without hiding the original error.

        Raises:
            OSError: Nonterminal progress cannot be written to durable storage.
            ValueError: Nonterminal progress cannot be serialized safely.
        """
        if self.local_result is None:
            return
        self.local_result["phase"] = phase
        self.local_result["error"] = error
        try:
            atomic_write_json(
                self.plans[0].directory / "manual-report.json", self.local_result
            )
        except (OSError, ValueError) as exc:
            if phase not in {"error", "cancelled"}:
                raise
            if self.emit is not None:
                try:
                    self.emit("error", f"[flight-report] Cannot save render result: {exc}")
                except Exception:
                    pass

    def render_editions(
        self, plan: SessionPlan, analysis: FlightAnalysis, edition: ReportRevision,
    ) -> dict[str, Path]:
        """Render a reserved language set concurrently with parent-owned progress.

        Args:
            plan (SessionPlan): Exact session owning this render batch.
            analysis (FlightAnalysis): Immutable serializable flight analysis.
            edition (ReportRevision): Parent-reserved language paths and identity.
        Returns:
            dict[str, Path]: All successful editions in requested order after the
                process barrier; local completed files are journaled as they finish.
        Raises:
            Exception: Rendering, persistence or cancellation failed after the
                coordinator drained children and retained completed files.
        """
        completed: dict[str, Path] = {}
        with self._lock:
            self._progress.render_session = plan.session_id
            self._progress.rendering_languages = ()
            self._progress.rendered_languages = ()
        self.save_local_result("generating")

        def started(language: str) -> None:
            """Publish one admitted child on the parent worker thread.

            Args:
                language (str): Supported language whose render was submitted.
            Returns:
                None: Adds the language to concurrent progress without Tk access.
            """
            with self._lock:
                self._progress.rendering_languages += (language,)
                detail = ", ".join(code.upper() for code in self._progress.rendering_languages)
            self.update("generating", detail)

        def finished(language: str, pdf: Path) -> None:
            """Journal an atomic completed PDF even while other children finish.

            Args:
                language (str): Supported completed language.
                pdf (Path): Verified PDF at its reserved destination.
            Returns:
                None: Updates only parent-owned local persistence and progress.
            """
            completed[language] = pdf
            with self._lock:
                self._progress.rendering_languages = tuple(
                    code for code in self._progress.rendering_languages
                    if code != language
                )
                self._progress.rendered_languages = tuple(
                    code for code in edition.files if code in completed
                )
            if self.local_result is not None:
                directory = plan.directory
                self.local_result["files"] = [
                    str(completed[code].relative_to(directory))
                    for code in edition.files if code in completed
                ]
                self.save_local_result("generating")
            self.update("generating", language.upper(), advance=True)

        return render_report_variants(
            analysis, edition.files, self.cancel, started, finished,
        )

    def generate_local_reports(self) -> None:
        """Render a fresh local revision from a stopped immutable raw session.

        Returns:
            None: Creates selected PDFs as a fresh readable flat revision,
                preserving raw data, earlier PDFs and every delivery receipt.

        Raises:
            ValueError: Source paths, languages or session state are unsafe.
            RuntimeError: No recorded samples exist in this stopped session.
            InterruptedError: Cancellation retains already rendered editions.
            OSError: Analysis or local rendering cannot read/write its files.
        """
        plan = self.plans[0]
        directory = plan.directory
        if directory.is_symlink() or directory.resolve().parent != directory.parent.resolve():
            raise ValueError("Flight session cannot redirect outside its workspace.")
        for name in (
            "session.json", "samples.jsonl", "reports", "manual-report.json"
        ):
            path = directory / name
            if path.is_symlink() or not path.resolve().is_relative_to(directory.resolve()):
                raise ValueError(f"Flight report source cannot redirect: {name}")
        meta = read_document(directory / "session.json")
        if meta.get("recording_ended") is not True:
            raise ValueError("Finish recording before creating a local PDF.")
        if not plan.languages or any(
            language not in REPORT_LANGUAGES for language in plan.languages
        ):
            raise ValueError("Select at least one supported PDF language.")
        revision = "manual-" + uuid.uuid4().hex
        output = directory / "reports"
        self.local_result = {
            "schema_version": 1,  # First independent local-render result format.
            "started_utc": datetime.now(timezone.utc).isoformat(),
            "revision": revision,
            "languages": list(plan.languages),
            "files": [],
            "phase": "analyzing",
            "error": "",
        }
        self.save_local_result("analyzing")
        self.update("analyzing", plan.session_id)
        self.check_cancelled()
        analysis = analyze_session(directory)
        if not analysis.samples:
            raise RuntimeError("No recorded telemetry; no empty PDF was created.")
        self.update("analyzing", plan.session_id, advance=True)
        edition = allocate_report_revision(
            directory, plan.session_id + "-" + revision, plan.languages,
        )
        self.render_editions(plan, analysis, edition)
        self.check_cancelled()
        self.save_local_result("completed")
        self.update("completed", str(output))

    def upload_saved_reports(self) -> None:
        """Upload an explicitly selected retained revision using the current key.

        Returns:
            None: Saves separate checksum receipts after server reconciliation;
                never renders, changes the original plan or revokes any key.

        Raises:
            ValueError: Selected paths or the confirmed gallery changed.
            RuntimeError: The gallery does not support report delivery.
            InterruptedError: Cancellation retains acknowledged editions.
            OSError: Local receipts or the network are unavailable.
        """
        from uploader.flight_delivery import deliver_report, saved_report_destination

        plan = self.plans[0]
        if not self.saved_revisions:
            raise ValueError("Select a retained PDF revision.")
        self.check_cancelled()
        meta = read_document(plan.directory / "session.json")
        if meta.get("recording_ended") is not True:
            raise ValueError("Finish recording before uploading saved PDFs.")
        catalog = {item.report_id: item for item in report_revisions(plan.directory)}
        for revision in self.saved_revisions:
            current = catalog.get(revision.report_id)
            if current is None or not revision.files or any(
                current.files.get(language) != path
                for language, path in revision.files.items()
            ):
                raise ValueError("Saved PDF selection changed; refresh the report list.")
        self.update("uploading", self.target_gallery)
        saved_report_destination(self.upload_url, self.api_key, self.target_gallery)
        self.update("uploading", self.target_gallery, advance=True)
        receipt_path = plan.directory / "upload-receipts.json"
        temporary_receipt = receipt_path.with_name(receipt_path.name + ".tmp")
        if receipt_path.is_symlink() or temporary_receipt.is_symlink():
            raise ValueError("Saved PDF receipts cannot redirect outside their session.")
        state = read_document(receipt_path) if receipt_path.exists() else {}
        receipts = state.get("receipts", {})
        if not isinstance(receipts, dict):
            raise ValueError("Saved PDF upload receipts need recovery.")
        binding = connection_binding(self.upload_url, self.api_key)
        editions = [
            (revision, language, pdf) for revision in self.saved_revisions
            for language, pdf in revision.files.items()
        ]
        for revision, language, pdf in editions:
            self.check_cancelled()
            self.update("uploading", pdf.name + "; report_id=" + revision.report_id)
            receipt = deliver_report(
                self.upload_url, self.api_key, pdf, plan.session_id, language,
                revision.report_id, self.transfer_progress,
            )
            receipts[binding + ":" + revision.report_id + ":" + language] = {
                "gallery_folder": self.target_gallery,
                "report_id": revision.report_id,
                "language": language,
                "sha256": receipt["sha256"],
                "id": receipt["id"],
            }
            atomic_write_json(receipt_path, {"receipts": receipts})
            self.update("uploading", pdf.name + "; acknowledged id=" + receipt["id"]
                        + "; sha256=" + receipt["sha256"], advance=True)
        self.update("completed", str(len(editions)) + " PDF(s) acknowledged by " + self.target_gallery)

    def finish_plan(self, plan: SessionPlan) -> None:
        """Analyze and acknowledge all required editions of a single flight.

        Args:
            plan (SessionPlan): Connection-bound pending session task manifest.

        Returns:
            None: Completed flag is written only after required receipts exist.

        Raises:
            RuntimeError: Required delivery capability is unavailable.
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
        # An explicitly finished airborne-start, diversion or partial recording
        # still gets a report. Its analysis keeps incomplete/unknown evidence;
        # finalization completion never fabricates takeoff or landing facts.
        from uploader.flight_delivery import (
            check_report_capability,
            deliver_report,
        )
        from uploader.flight_report import (
            REPORT_TEMPLATE_VERSION,
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
        edition = allocate_report_revision(plan.directory, revision, plan.languages)
        outputs = self.render_editions(plan, analysis, edition)
        self.check_cancelled()
        for language in plan.languages:
            self.check_cancelled()
            pdf = outputs[language]
            checksum = hashlib.sha256(pdf.read_bytes()).hexdigest()
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
        """Acknowledge credential persistence after revoke or local abandonment.

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
