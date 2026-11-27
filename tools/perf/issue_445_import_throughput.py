# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_445_import_throughput.py
# Module Type: Hosted paired WinApp benchmark fixture
# Purpose: Compare durable synthetic Import throughput in the Tk reference and
#   native Qt Import controls.
# Responsibilities:
#   - Run isolated native UI children against exact selected source checkouts.
#   - Confirm complete durable jobs and terminal UI ownership on both toolkits.
#   - Retain paired timings, rates, confidence intervals and CSV evidence.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Measure bounded, paired synthetic Import throughput on hosted Windows."""

import argparse
import csv
import copy
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
from threading import Event, Thread
from tkinter import StringVar, Tk, ttk
from types import ModuleType
from typing import Callable, Protocol, TypedDict, cast

# Purpose: Admit benchmark code only on the hosted Windows runner.
# Type: str and str. Units: platform and environment values. Scope: module load.
# Consumers: every Import throughput child and paired parent.
# Rationale: Native Tk/Qt comparisons are meaningful only on the qualified host.
REQUIRED_PLATFORM = "win32"
REQUIRED_CI_ENVIRONMENT = "true"
if sys.platform != REQUIRED_PLATFORM or os.environ.get("GITHUB_ACTIONS") != REQUIRED_CI_ENVIRONMENT:
    raise RuntimeError("Issue 445 Import throughput requires hosted Windows CI.")

_source_parser = argparse.ArgumentParser(add_help=False, allow_abbrev=False)
_source_parser.add_argument("--source", type=Path)
_source_options, _source_unused = _source_parser.parse_known_args()
_mode_parser = argparse.ArgumentParser(add_help=False, allow_abbrev=False)
_mode_parser.add_argument("--child", action="store_true")
_mode_options, _mode_unused = _mode_parser.parse_known_args()
_renderer_parser = argparse.ArgumentParser(add_help=False, allow_abbrev=False)
_renderer_parser.add_argument("--renderer")
_renderer_options, _renderer_unused = _renderer_parser.parse_known_args()
_project_root = Path(__file__).resolve().parents[2]
_selected_source_root = (_source_options.source or _project_root).resolve()
_selected_winapp = str(_selected_source_root / "winapp")
_perf_tools = str(Path(__file__).resolve().parent)
for _path in (_perf_tools, _selected_winapp):
    if _path not in sys.path:
        sys.path.insert(0, _path)

# The selected --source is already first on sys.path before these application imports.
# The benchmark helper bootstraps the current checkout's source at module load,
# so children skip it and use only the selected --source application tree.
if not _mode_options.child:
    from issue_361_tk_benchmark import bootstrap_delta_ci, describe

from issue_361_tk_workloads import Recorder, load_watcher
from issue_434_tk_shell import Issue434ShellWorkload
from uploader.models import ImportItem, ImportJob, JsonValue
from uploader.state_store import JobStateStore

if _renderer_options.renderer != "tkinter":
    from PySide6.QtWidgets import QApplication
    from uploader.import_commands import (
        ImportActions, ImportApproval, ImportEvents, ImportSource, ImportWorkerPort,
        PreflightWorkerPort, import_approval,
    )
    from uploader.import_ui_snapshot import ImportProgressSnapshot
    from uploader.models import ImportPlan
    from uploader.qt_application import QtWindow
    from uploader.qt_theme import apply_qt_theme

# Purpose: Keep both renderer arms on the Issue 434 ordinary Import extent.
# Type: int. Units: items. Scope: one paired sample.
# Consumers: both child workloads and durable completion validation.
# Rationale: 100 rows exercise production views while keeping each child short.
ITEM_COUNT = 100
# Purpose: Cap synthetic item admission at the Issue 434 input cadence.
# Type: float. Units: minimum seconds between item admissions. Scope: both workers.
# Consumers: _paced_synthetic_import.
# Rationale: 50 ms preserves the established cadence while durable writes naturally backpressure.
ITEM_INTERVAL_SECONDS = 0.05
# Purpose: Bound one independently launched native UI child.
# Type: int. Units: seconds. Scope: one renderer arm.
# Consumers: paired subprocess timeout.
# Rationale: 90 seconds matches the existing paired benchmark child ceiling.
CHILD_TIMEOUT_SECONDS = 90
# Purpose: Require independent paired observations for uncertainty estimation.
# Type: int. Units: pairs. Scope: one Import comparison.
# Consumers: run_import_comparison.
# Rationale: Twelve matches the warm 2,000-photo audit's independent group count.
REQUIRED_GROUPS = 12
# Purpose: Pin the exact immutable Tk control source.
# Type: str. Units: Git SHA-1 characters. Scope: Issue 445 Import comparison.
# Consumers: API validation and child source identity checks.
# Rationale: This is the reviewed Tk reference used by the paired audit.
REFERENCE_SHA = "b63d6d5e5bee014ab3750080833f49dcc27e9a34"
# Purpose: Express the documented Import throughput loss guard.
# Type: float. Units: fraction. Scope: Qt candidate versus Tk reference.
# Consumers: audit summary; parent owns final guard classification.
# Rationale: The existing ten-percent throughput guard applies to Import.
THROUGHPUT_LOSS_GUARD = 0.10
# Purpose: Bound cooperative native event-loop polling overhead.
# Type: float. Units: seconds. Scope: one child observer.
# Consumers: Tk and Qt event-loop pumps.
# Rationale: Five milliseconds observes feedback without busy polling.
POLL_INTERVAL_SECONDS = 0.005
# Purpose: Fail a live Import only after confirmed native progress stalls.
# Type: int. Units: seconds without progress. Scope: one renderer worker.
# Consumers: Tk and Qt child observers.
# Rationale: Twenty seconds allows slow durable transition/UI publication while
#   the unchanged 90-second parent timeout remains the hard process bound.
NO_PROGRESS_TIMEOUT_SECONDS = 20
# Purpose: Seed independent deterministic paired job identities.
# Type: int. Units: seed offset. Scope: one comparison matrix.
# Consumers: run_import_comparison.
# Rationale: This nonzero range avoids overlapping other benchmark fixtures.
PAIR_SEED_BASE = 445_000
# Purpose: Match the common native desktop shell footprint for the Qt child.
# Type: tuple[int, int]. Units: logical pixels. Scope: one Qt child window.
# Consumers: _run_qt.
# Rationale: The established desktop dimensions leave the Import workspace readable.
QT_WINDOW_SIZE = (920, 620)
# Purpose: Bound teardown waiting for a fixture-owned worker.
# Type: float. Units: seconds. Scope: one child cleanup.
# Consumers: _run_qt and Tk shell teardown.
# Rationale: The worker checks cancellation between bounded paced items.
WORKER_JOIN_SECONDS = 1.0
# Purpose: Retain the production shell's normal bounded history extent.
# Type: int. Units: history entries. Scope: one Qt benchmark profile.
# Consumers: QtWindow constructor.
# Rationale: 500 matches the desktop shell's ordinary activity history capacity.
UI_HISTORY_LIMIT = 500
# Purpose: Use one worker for each synthetic upload pipeline stage.
# Type: int. Units: worker threads. Scope: one connection-free import worker.
# Consumers: both ManualUploadThread constructors.
# Rationale: A single worker preserves deterministic item order and equal pacing.
SYNTHETIC_WORKER_COUNT = 1
# Purpose: Limit child stdout/stderr retained for one sample.
# Type: int. Units: bytes per stream. Scope: one subprocess arm.
# Consumers: _bound_log_file.
# Rationale: Four KiB retains actionable tail diagnostics without unbounded artifacts.
CHILD_LOG_LIMIT_BYTES = 4096
# Purpose: Bound worker failure details copied into child JSON.
# Type: int. Units: characters. Scope: one child report.
# Consumers: _child.
# Rationale: One thousand characters preserves a useful exception summary.
FAILURE_TEXT_LIMIT = 1000
# Purpose: Supply the shared synthetic job's fixed creation time.
# Type: float. Units: Unix seconds. Scope: each paired synthetic job.
# Consumers: _make_job and the Issue 434 Tk shell-equivalent rows.
# Rationale: Stable creation metadata keeps the normalized job identity deterministic.
FIXTURE_CREATED_AT = 1_800_000_000.0
# Purpose: Validate provider-supplied exact commit identifiers.
# Type: int. Units: hexadecimal characters. Scope: one source checkout identity.
# Consumers: _verify_source_path and run_import_comparison.
# Rationale: Repository exact commit identifiers are SHA-1 values.
GIT_SHA_LENGTH = 40
# Purpose: Match the Issue 434 synthetic row size progression.
# Type: int. Units: bytes. Scope: each generated import item.
# Consumers: _make_job and _validate_durable_job.
# Rationale: The established fixture starts at 1 KiB and adds the row index.
SYNTHETIC_ITEM_BASE_SIZE_BYTES = 1024


class ManualWorker(Protocol):
    """Describe the worker capabilities exercised by both native surfaces."""

    paths: list[Path]
    stop_event: Event
    import_generation: object
    import_job_id: str

    def start(self) -> None:
        """Start the owned production worker thread.

        Returns:
            None: The existing worker thread begins execution.
        """

    def is_alive(self) -> bool:
        """Report actual worker ownership lifetime.

        Returns:
            bool: True while the worker thread owns active work.
        """

    def join(self, timeout: float | None = None) -> None:
        """Wait for worker teardown up to a bounded deadline.

        Args:
            timeout (float | None): Maximum wait duration in seconds.
        Returns:
            None: The worker has stopped or the deadline expired.
        """

    def stop(self) -> None:
        """Request cooperative worker cancellation.

        Returns:
            None: The worker observes its normal cancellation checkpoint.
        """

    def persist_job(self, *, complete_rows: bool = True) -> None:
        """Persist and publish the worker's current durable job revision.

        Args:
            complete_rows (bool): Whether to publish a full row projection.
        Returns:
            None: Durable state and UI revision are published by the worker.
        """

    def update_item(
        self, path: Path, state: str, reason: str = "", file_hash: str = "",
        server_result: dict[str, JsonValue] | None = None,
        increment_attempt: bool = False, count_bytes: bool = False,
    ) -> None:
        """Persist one production item transition.

        Args:
            path (Path): Selected accepted source path.
            state (str): Production ImportItem transition state.
            reason (str): Optional transition explanation.
            file_hash (str): Confirmed source digest when available.
            server_result (dict[str, JsonValue] | None): Safe retained receipt fields.
            increment_attempt (bool): Whether one dispatch attempt is recorded.
            count_bytes (bool): Whether confirmed bytes advance the durable total.
        Returns:
            None: The enclosing job is saved and its UI projection is published.
        """

    def finalize_job(self) -> None:
        """Persist the terminal durable job state.

        Returns:
            None: The terminal job snapshot is durably saved.
        """

    def emit(self, level: str, message: str) -> None:
        """Publish a production worker event through its assigned mailbox.

        Args:
            level (str): Production event severity.
            message (str): Bounded synthetic status text.
        Returns:
            None: The assigned native event queue receives the event.
        """

    def emit_structured(self, kind: str, payload: dict[str, object]) -> None:
        """Publish a production structured lifecycle event.

        Args:
            kind (str): Existing lifecycle event kind.
            payload (dict[str, object]): Existing lifecycle event fields.
        Returns:
            None: The event is placed on the worker's native queue.
        """

    def latest_import_projection(self) -> "ImportProgressSnapshot | None":
        """Return the latest candidate UI projection after its durable save.

        Returns:
            ImportProgressSnapshot | None: Latest saved snapshot if available.
        """

    def run(self) -> None:
        """Run the worker's overridden connection-free fixture workload.

        Returns:
            None: The worker owns its complete paced synthetic lifecycle.
        """


class WatcherEntrypoint(Protocol):
    """Describe selected `.pyw` exports used to construct the production worker."""

    ManualUploadThread: Callable[..., ManualWorker]

    def WatcherConfig(self) -> object:
        """Return a blank local configuration for the synthetic worker.

        Returns:
            object{inventory_refresh_seconds: float}: Local WatcherConfig with
                the default inventory period and empty connection settings.
        """


class TkImportShell(Issue434ShellWorkload):
    """Reuse the real Issue 434 shell while deferring Import worker admission."""

    def _start_synthetic_import_worker(self) -> None:
        """Defer the worker until the common timing anchor is established.

        Returns:
            None: The shared worker starts after the shell is mapped.
        """

    def start_paired_import(self, started_at: list[float | None]) -> ManualWorker:
        """Start the shared production worker after the Tk view is mapped.

        Args:
            started_at (list[float | None]): Mutable timestamp set at thread start.
        Returns:
            ManualWorker: The actual worker owned by the native Tk application.
        """
        worker_job = copy.deepcopy(cast(ImportJob, self.job))
        module = cast(WatcherEntrypoint, self.module)
        worker = module.ManualUploadThread(
            self.host.config, [Path(item.path) for item in worker_job.items],
            False, SYNTHETIC_WORKER_COUNT, SYNTHETIC_WORKER_COUNT,
            self.host.events, worker_job, self.store,
        )
        self._observe_import_progress()
        setattr(worker, "run", lambda: _paced_synthetic_import(worker, worker_job, self.seed))
        self.import_worker = worker
        self.host.manual_worker = worker
        _start_at_owned_thread(worker, started_at)
        worker.start()
        return worker


def _make_job(root: Path, seed: int) -> ImportJob:
    """Create the Qt job matching the real Issue 434 Tk shell's exact rows.

    Args:
        root (Path): Private profile subdirectory used only for synthetic labels.
        seed (int): Independent paired sample seed.
    Returns:
        ImportJob: Production job with the same IDs, paths, sizes and hashes.
    """
    items = [
        ImportItem(
            id=f"issue434-item-{seed}-{index:05d}",
            path=str(root / f"synthetic-{index:05d}.jpg"),
            source_label=f"synthetic-{index:05d}.jpg",
            size=SYNTHETIC_ITEM_BASE_SIZE_BYTES + index,
            sha256=hashlib.sha256(f"{seed}:{index}".encode("ascii")).hexdigest(),
            state="discovered", local_decode="local",
        )
        for index in range(ITEM_COUNT)
    ]
    return ImportJob(
        id=f"issue434-import-{seed}", source_kind="folder",
        source_label="Synthetic benchmark folder", target_gallery_label="Synthetic gallery",
        items=items, bytes_total=sum(item.size for item in items),
        created_at=FIXTURE_CREATED_AT,
    )


def _paced_synthetic_import(worker: ManualWorker, job: ImportJob, seed: int) -> None:
    """Drive identical durable transitions from the production worker owner.

    Args:
        worker (ManualWorker): Exact selected `ManualUploadThread` instance.
        job (ImportJob): Worker-owned copy of the deterministic accepted job.
        seed (int): Paired identity used for each confirmed content digest.
    Returns:
        None: The job is durably finalized and a terminal event is enqueued.
    """
    job.started_at = time.time()
    worker.persist_job()
    worker.emit("info", f"Manual upload started: {len(worker.paths)} file(s).")
    next_admission = time.monotonic()
    for index, path in enumerate(worker.paths):
        admission_delay = next_admission - time.monotonic()
        if admission_delay > 0 and worker.stop_event.wait(admission_delay):
            break
        if worker.stop_event.is_set():
            break
        next_admission = time.monotonic() + ITEM_INTERVAL_SECONDS
        digest = hashlib.sha256(f"{seed}:{index}".encode("ascii")).hexdigest()
        worker.update_item(path, "uploading", increment_attempt=True)
        worker.update_item(path, "confirmed", file_hash=digest, count_bytes=True)
    if not worker.stop_event.is_set():
        worker.finalize_job()
        worker.emit_structured(
            "job_completed", {"generation": worker.import_generation},
        )
        worker.emit("info", "Synthetic import finished.")


def _start_at_owned_thread(worker: ManualWorker, started_at: list[float | None]) -> None:
    """Record the common start boundary immediately before the actual thread.

    Args:
        worker (ManualWorker): Production worker whose start method is wrapped.
        started_at (list[float | None]): Monotonic timestamp for timing and stall detection.
    Returns:
        None: The original production Thread.start begins after timestamping.
    """
    original_start = worker.start

    def mark_start() -> None:
        """Timestamp and invoke the original worker start method.

        Returns:
            None: The production worker thread begins execution.
        """
        started_at[0] = time.perf_counter()
        original_start()

    setattr(worker, "start", mark_start)


def _normalized_job_digest(job: ImportJob) -> str:
    """Hash observed durable identities, state, attempt counts and byte totals.

    Args:
        job (ImportJob): Readback job loaded from the durable state store.
    Returns:
        str: Stable SHA-256 digest excluding process-private absolute paths and times.
    """
    rows = [
        {
            "id": item.id,
            "path_name": Path(item.path).name,
            "source_label": item.source_label,
            "size": item.size,
            "sha256": item.sha256,
            "state": item.state,
            "attempt_count": item.attempt_count,
        }
        for item in job.items
    ]
    normalized: JsonValue = {
        "job_id": job.id,
        "bytes_total": job.bytes_total,
        "bytes_sent": job.bytes_sent,
        "rows": rows,
    }
    encoded = json.dumps(normalized, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def _verify_origins(source_root: Path, main_module: ModuleType) -> None:
    """Reject any production import resolved outside the selected checkout.

    Args:
        source_root (Path): Exact source root passed to the child.
        main_module (ModuleType): Selected `.pyw` entrypoint returned by loader.
    Returns:
        None: All loaded uploader modules and the application entrypoint are local.
    Raises:
        RuntimeError: One imported source resolves outside the selected checkout.
    """
    expected = (source_root / "winapp" / "uploader").resolve()
    for name, module in tuple(sys.modules.items()):
        if name != "uploader" and not name.startswith("uploader."):
            continue
        origin = getattr(module, "__file__", None)
        if origin is None:
            continue
        try:
            Path(origin).resolve().relative_to(expected)
        except ValueError as error:
            raise RuntimeError(f"Wrong production module origin: {name}") from error
    try:
        Path(main_module.__file__ or "").resolve().relative_to(source_root.resolve())
    except ValueError as error:
        raise RuntimeError("The loaded Watcher entrypoint is outside --source.") from error


def _run_tk(source_root: Path, root: Path, seed: int) -> dict[str, JsonValue]:
    """Run deferred Issue 434 Tk Import and wait for terminal UI ownership.

    Args:
        source_root (Path): Immutable Tk reference checkout.
        root (Path): Private profile and durable state root.
        seed (int): Deterministic identity shared with the Qt arm.
    Returns:
        dict[str, JsonValue]: Raw durable result and observed native UI projection.
    Raises:
        RuntimeError: A row, terminal control state or owner barrier is incomplete.
    """
    window = Tk()
    workload = TkImportShell(
        window, source_root, root, "import", seed, ITEM_COUNT, None, Recorder(False),
    )
    window.geometry(f"{QT_WINDOW_SIZE[0]}x{QT_WINDOW_SIZE[1]}")
    started_at: list[float | None] = [None]
    last_progress = 0.0
    try:
        window.update()
        worker = workload.start_paired_import(started_at)
        start = started_at[0]
        if start is None:
            raise RuntimeError("Tk Import did not start its owned worker.")
        last_progress_at = start
        while True:
            window.update()
            now = time.perf_counter()
            progress = float(workload.host.manual_progress_var.get())
            if progress > last_progress:
                last_progress = progress
                last_progress_at = now
            if now - last_progress_at >= NO_PROGRESS_TIMEOUT_SECONDS:
                raise RuntimeError(
                    "Tk Import made no native confirmed-progress advance within "
                    f"{NO_PROGRESS_TIMEOUT_SECONDS} seconds. "
                    f"Started={started_at[0] is not None}, alive={worker.is_alive()}, "
                    f"pending={workload.host.events.qsize()}, progress={progress}, "
                    f"status={workload.host.manual_status_var.get()!r}.",
                )
            terminal_status = workload.host.manual_status_var.get()
            finished_text = workload.module.gettext("Import finished")
            if (not worker.is_alive() and workload.host.events.empty()
                    and terminal_status == finished_text and progress == 100.0):
                end = time.perf_counter()
                break
            time.sleep(POLL_INTERVAL_SECONDS)
        job = workload.store.get_job(workload.job.id)
        if job is None:
            raise RuntimeError("Tk Import job is absent from durable readback.")
        _validate_durable_job(job)
        if workload.host.manual_worker is not worker or worker.is_alive():
            raise RuntimeError("Tk application did not release its exact worker owner.")
        if workload.host.events.qsize() != 0:
            raise RuntimeError("Tk application still owns queued Import events.")
        if len(workload.host.import_item_tree.get_children()) != ITEM_COUNT:
            raise RuntimeError("Tk Import table did not display all durable rows.")
        terminal = workload.host.manual_progress_text_var.get()
        if not terminal:
            raise RuntimeError("Tk Import omitted terminal native progress feedback.")
        elapsed = end - start
        return _child_result("tkinter", job, elapsed, terminal, ITEM_COUNT)
    finally:
        workload.close()
        window.destroy()


def _run_qt(source_root: Path, root: Path, seed: int) -> dict[str, JsonValue]:
    """Run the real QtWindow/QtImport with the same durable worker fixture.

    Args:
        source_root (Path): Candidate checkout containing Qt Import surfaces.
        root (Path): Private profile and durable state root.
        seed (int): Deterministic identity shared with the Tk arm.
    Returns:
        dict[str, JsonValue]: Durable result and native Qt application projection.
    Raises:
        RuntimeError: The Qt panel or full application did not release ownership.
    """
    profile = root / "profile"
    profile.mkdir(parents=True, exist_ok=True)
    state_store = JobStateStore(profile / "upload_state.json")
    job = _make_job(root, seed)
    state_store.save_job(job)
    module = cast(WatcherEntrypoint, load_watcher(source_root))
    worker_holder: list[ManualWorker] = []
    started_at: list[float | None] = [None]

    def prepare(source: ImportSource, events: ImportEvents) -> PreflightWorkerPort:
        """Fail closed because the exact reviewed fixture plan is already mounted.

        Args:
            source (ImportSource): Native source selection that is not expected.
            events (ImportEvents): Native preflight event mailbox.
        Returns:
            PreflightWorkerPort: Never returns in this prepared-plan fixture.
        Raises:
            RuntimeError: An unexpected native preflight was initiated.
        """
        raise RuntimeError("Prepared synthetic Import must not launch preflight.")

    def admit(
        selected_job: ImportJob, approval: ImportApproval, local_thumbnails: bool,
        events: ImportEvents,
    ) -> ImportWorkerPort:
        """Return a production ManualUploadThread for the reviewed synthetic job.

        Args:
            selected_job (ImportJob): Exact job displayed in QtImport review.
            approval (ImportApproval): Current review proof consumed by UI admission.
            local_thumbnails (bool): Disabled synthetic thumbnail preference.
            events (ImportEvents): Native QtImport event mailbox.
        Returns:
            ImportWorkerPort: Unstarted production upload worker implementation.
        """
        worker = module.ManualUploadThread(
            module.WatcherConfig(), [Path(item.path) for item in selected_job.items],
            False, SYNTHETIC_WORKER_COUNT, SYNTHETIC_WORKER_COUNT,
            events, selected_job, state_store,
        )
        setattr(worker, "run", lambda: _paced_synthetic_import(worker, selected_job, seed))
        _start_at_owned_thread(worker, started_at)
        worker_holder.append(worker)
        return cast(ImportWorkerPort, worker)

    def retry(
        job_id: str, local_thumbnails: bool, events: ImportEvents,
    ) -> tuple[ImportWorkerPort | None, int]:
        """Reject retry because this child owns only one fresh synthetic job.

        Args:
            job_id (str): History job identity that must not be retried.
            local_thumbnails (bool): Disabled preference retained by the UI.
            events (ImportEvents): Native mailbox that must not receive retry events.
        Returns:
            tuple[ImportWorkerPort | None, int]: Never returns in this one-shot fixture.
        Raises:
            RuntimeError: The native UI attempts a retry outside this workload.
        """
        raise RuntimeError("Synthetic Import retry is outside this fixture.")

    actions = ImportActions(
        prepare=prepare, admit=admit, retry=retry,
        retire=lambda _job_id: None, use_local_thumbnails=False,
    )
    app = QApplication.instance() or QApplication([])
    apply_qt_theme(app)
    window = QtWindow(
        profile, "benchmark", "", UI_HISTORY_LIMIT, False, import_actions=actions,
    )
    window.resize(*QT_WINDOW_SIZE)
    panel = window.import_flow
    if panel is None:
        window.exit_window()
        raise RuntimeError("The Qt application did not mount native QtImport.")
    terminal: list[str] = []
    plan = ImportPlan(job)
    panel._plan = plan
    panel._approval = import_approval(job.id, object(), "a" * 64)
    panel._show_plan(plan)
    panel.finished.connect(lambda: terminal.append(panel.status.text()))
    window.show_page("import")
    window.show()
    app.processEvents()
    last_progress_at = time.perf_counter()
    panel.start()
    last_progress = 0
    try:
        while True:
            app.processEvents()
            worker = worker_holder[0] if worker_holder else None
            now = time.perf_counter()
            progress = panel.progress.value()
            start = started_at[0]
            if start is not None and start > last_progress_at:
                last_progress_at = start
            if progress > last_progress:
                last_progress = progress
                last_progress_at = now
            if now - last_progress_at >= NO_PROGRESS_TIMEOUT_SECONDS:
                raise RuntimeError(
                    "Qt Import made no native confirmed-progress advance within "
                    f"{NO_PROGRESS_TIMEOUT_SECONDS} seconds. "
                    f"Started={started_at[0] is not None}, busy={panel.busy()}, "
                    f"pending={panel._events.qsize()}, status={panel.status.text()!r}, "
                    f"progress={progress}, "
                    f"operator_rows={window.operator.import_items.rowCount()}.",
                )
            if (terminal and worker is not None and not worker.is_alive()
                    and panel._worker is None and panel._upload is None
                    and panel._events.empty() and progress == ITEM_COUNT
                    and window.operator.import_items.rowCount() == ITEM_COUNT):
                end = time.perf_counter()
                break
            time.sleep(POLL_INTERVAL_SECONDS)
        saved = state_store.get_job(job.id)
        if saved is None:
            raise RuntimeError("Qt Import job is absent from durable readback.")
        _validate_durable_job(saved)
        if len(panel._review_rows) != ITEM_COUNT:
            raise RuntimeError("QtImport did not project all durable item rows.")
        receiver_rows = window.operator.import_items.rowCount()
        if receiver_rows != ITEM_COUNT:
            raise RuntimeError("Qt application operator receiver omitted Import item rows.")
        if window.import_flow is not panel or worker_holder[0].is_alive():
            raise RuntimeError("Qt application did not release its exact worker owner.")
        if not terminal or "finished" not in terminal[-1].lower():
            raise RuntimeError("Qt Import omitted terminal native application feedback.")
        start = started_at[0]
        if start is None:
            raise RuntimeError("Qt worker start timestamp was not observed.")
        return _child_result(
            "pyside6", saved, end - start,
            terminal[-1], receiver_rows,
        )
    finally:
        for worker in worker_holder:
            if worker.is_alive():
                worker.stop()
                worker.join(WORKER_JOIN_SECONDS)
        panel._observe()
        app.processEvents()
        window.exit_window()


def _validate_durable_job(job: ImportJob) -> None:
    """Require exact accepted rows, attempts, confirmation hashes and bytes.

    Args:
        job (ImportJob): Complete durable store readback for the fixture.
    Returns:
        None: All 100 rows have exact confirmed terminal states.
    Raises:
        RuntimeError: Any row, job completion or byte accounting differs.
    """
    if len(job.items) != ITEM_COUNT or job.started_at <= 0 or job.finished_at <= 0:
        raise RuntimeError("Durable Import job is incomplete or lacks finish time.")
    if job.bytes_sent != job.bytes_total:
        raise RuntimeError("Durable Import byte confirmation is incomplete.")
    for index, item in enumerate(job.items):
        expected_id = f"issue434-item-{job.id.removeprefix('issue434-import-')}-{index:05d}"
        expected_hash = hashlib.sha256(
            f"{job.id.removeprefix('issue434-import-')}:{index}".encode("ascii"),
        ).hexdigest()
        if (item.id != expected_id or item.state != "confirmed"
                or item.attempt_count != SYNTHETIC_WORKER_COUNT
                or item.sha256 != expected_hash
                or item.size != SYNTHETIC_ITEM_BASE_SIZE_BYTES + index
                or item.last_update <= 0):
            raise RuntimeError("A durable Import row differs from the exact fixture.")


def _child_result(
    renderer: str, job: ImportJob, elapsed: float, terminal: str,
    ui_rows: int,
) -> dict[str, JsonValue]:
    """Serialize normalized durable evidence and the native UI ownership result.

    Args:
        renderer (str): Native UI toolkit label.
        job (ImportJob): Exact durable job readback.
        elapsed (float): Actual worker start through UI and ownership release.
        terminal (str): Terminal native feedback observed on the application.
        ui_rows (int): Exact durable rows visible in the active Import surface.
    Returns:
        dict[str, JsonValue]: Bounded raw child evidence.
    """
    return {
        "renderer": renderer, "elapsed_seconds": elapsed,
        "confirmed_items": sum(item.state == "confirmed" for item in job.items),
        "item_count": len(job.items), "finished_at": job.finished_at,
        "bytes_sent": job.bytes_sent, "bytes_total": job.bytes_total,
        "durable_timestamps": [item.last_update for item in job.items],
        "terminal_feedback": terminal, "ui_rows": ui_rows,
        "ui_owner_released": True, "fixture_digest": _normalized_job_digest(job),
    }


def _bound_log_file(path: Path) -> None:
    """Retain only the bounded final diagnostics from one child stream.

    Args:
        path (Path): Captured stdout or stderr artifact.
    Returns:
        None: The artifact is absent or no larger than its retention limit.
    """
    if not path.exists() or path.stat().st_size <= CHILD_LOG_LIMIT_BYTES:
        return
    with path.open("rb") as stream:
        stream.seek(-CHILD_LOG_LIMIT_BYTES, os.SEEK_END)
        tail = stream.read(CHILD_LOG_LIMIT_BYTES)
    path.write_bytes(tail)


def _log_tail(path: Path) -> str:
    """Read one already-bounded child diagnostic stream as replacement-safe text.

    Args:
        path (Path): Captured stdout or stderr artifact.
    Returns:
        str: At most the configured final diagnostic bytes as decoded text.
    """
    if not path.exists():
        return ""
    return path.read_text(encoding="utf-8", errors="replace")[-FAILURE_TEXT_LIMIT:]


def _verify_source_path(source_root: Path, source_sha: str, renderer: str) -> None:
    """Require the requested source identity and renderer-specific checkout.

    Args:
        source_root (Path): Selected exact production checkout.
        source_sha (str): Claimed exact source SHA from the parent audit.
        renderer (str): Renderer selecting the immutable control or candidate.
    Returns:
        None: The configured source identity is admitted for this child.
    Raises:
        RuntimeError: The child source identity is malformed or misassigned.
    """
    if (len(source_sha) != GIT_SHA_LENGTH
            or any(character not in "0123456789abcdef" for character in source_sha)):
        raise RuntimeError("A lowercase exact Git SHA is required for the child.")
    if renderer == "tkinter" and source_sha != REFERENCE_SHA:
        raise RuntimeError("Tk child source does not match the immutable reference.")
    if not (source_root / "winapp" / "gallery_watch_upload.pyw").is_file():
        raise RuntimeError("The selected --source has no production Watcher entrypoint.")


def _child(args: argparse.Namespace) -> int:
    """Execute one source-selected native UI child and retain its JSON evidence.

    Args:
        args (argparse.Namespace): Exact renderer, source, SHA, seed and paths.
    Returns:
        int: Zero only after exact durable and native UI validation succeeds.
    """
    source_root = args.source.resolve()
    result: dict[str, JsonValue]
    try:
        _verify_source_path(source_root, args.source_sha, args.renderer)
        module = load_watcher(source_root)
        result = (_run_tk(source_root, args.private, args.seed)
                  if args.renderer == "tkinter"
                  else _run_qt(source_root, args.private, args.seed))
        _verify_origins(source_root, module)
        result.update({
            "status": "PASS", "source_root": str(source_root),
            "source_sha": args.source_sha, "seed": args.seed,
            "wall_seconds": time.perf_counter() - args.started,
        })
    except Exception as error:
        result = {
            "status": "FAIL",
            "failure": f"{type(error).__name__}: {str(error)[:FAILURE_TEXT_LIMIT]}",
            "source_sha": args.source_sha, "seed": args.seed,
        }
    args.output.write_text(
        json.dumps(result, allow_nan=False, ensure_ascii=False) + "\n", encoding="utf-8",
    )
    return 0 if result["status"] == "PASS" else 1


def run_import_comparison(
    reference: Path, candidate_root: Path, candidate_sha: str,
    baseline_sha: str, output: Path, groups: int,
) -> dict[str, JsonValue]:
    """Run 12 alternating independent pairs and write comparison JSON/CSV.

    Args:
        reference (Path): Extracted immutable Tk reference checkout.
        candidate_root (Path): Exact candidate checkout containing QtImport.
        candidate_sha (str): Candidate source Git SHA.
        baseline_sha (str): Immutable Tk reference Git SHA.
        output (Path): Existing paired evidence directory.
        groups (int): Requested independent paired groups.
    Returns:
        dict[str, JsonValue]: Rates, paired percent delta, CI and raw pair records.
    Raises:
        RuntimeError: Hosted admission or exact child evidence is incomplete.
    """
    if sys.platform != REQUIRED_PLATFORM or os.environ.get("GITHUB_ACTIONS") != REQUIRED_CI_ENVIRONMENT:
        raise RuntimeError("Issue 445 Import comparison requires hosted Windows CI.")
    if groups != REQUIRED_GROUPS or baseline_sha != REFERENCE_SHA:
        raise RuntimeError("Import comparison requires the reviewed 12-group reference.")
    if (len(candidate_sha) != GIT_SHA_LENGTH
            or any(character not in "0123456789abcdef" for character in candidate_sha)
            or not reference.is_dir() or not candidate_root.is_dir()):
        raise RuntimeError("Import comparison source identity or checkout is invalid.")
    if candidate_root.resolve() != _project_root.resolve():
        raise RuntimeError("Import fixture must load from the exact candidate checkout.")
    output.mkdir(parents=True, exist_ok=True)
    raw: list[dict[str, JsonValue]] = []
    for pair in range(groups):
        seed = PAIR_SEED_BASE + pair
        order = ("tkinter", "pyside6") if pair % 2 == 0 else ("pyside6", "tkinter")
        arms = {
            "tkinter": (reference, baseline_sha),
            "pyside6": (candidate_root, candidate_sha),
        }
        sample: dict[str, JsonValue] = {"pair": pair + 1, "seed": seed, "order": list(order)}
        for renderer in order:
            source, source_sha = arms[renderer]
            child_output = output / f"import-{pair + 1:02d}-{renderer}.json"
            stdout_path = output / f"import-{pair + 1:02d}-{renderer}.stdout.txt"
            stderr_path = output / f"import-{pair + 1:02d}-{renderer}.stderr.txt"
            with tempfile.TemporaryDirectory(
                prefix=f"issue445-import-{pair:02d}-{renderer}-",
            ) as private_name:
                command = [
                    sys.executable, str(Path(__file__).resolve()), "--child",
                    "--renderer", renderer, "--source", str(source),
                    "--source-sha", source_sha, "--seed", str(seed),
                    "--private", private_name, "--output", str(child_output),
                ]
                try:
                    with stdout_path.open("wb") as stdout_stream, stderr_path.open("wb") as stderr_stream:
                        completed = subprocess.run(
                            command, check=False, stdout=stdout_stream, stderr=stderr_stream,
                            timeout=CHILD_TIMEOUT_SECONDS,
                        )
                except subprocess.TimeoutExpired as error:
                    _bound_log_file(stdout_path)
                    _bound_log_file(stderr_path)
                    raise RuntimeError(
                        f"Import child exceeded {CHILD_TIMEOUT_SECONDS}-second deadline. "
                        + _log_tail(stderr_path) + _log_tail(stdout_path),
                    ) from error
                _bound_log_file(stdout_path)
                _bound_log_file(stderr_path)
                if not child_output.is_file():
                    raise RuntimeError(
                        "Import child produced no JSON report. "
                        + _log_tail(stderr_path) + _log_tail(stdout_path),
                    )
                data_value = json.loads(child_output.read_text(encoding="utf-8"))
            if not isinstance(data_value, dict):
                raise RuntimeError("Import child report is not a JSON object.")
            data = cast(dict[str, JsonValue], data_value)
            if completed.returncode or data.get("status") != "PASS":
                raise RuntimeError(
                    str(data.get("failure", "Import child failed."))[:FAILURE_TEXT_LIMIT]
                    + _log_tail(stderr_path) + _log_tail(stdout_path),
                )
            if data.get("source_sha") != source_sha or data.get("renderer") != renderer:
                raise RuntimeError("Import child source or renderer identity differs from its request.")
            prior_digest = sample.get("fixture_digest")
            if prior_digest is not None and data.get("fixture_digest") != prior_digest:
                raise RuntimeError("Observed durable paired fixture identities differ.")
            sample[renderer] = data
            sample["fixture_digest"] = data["fixture_digest"]
        tk_data = cast(dict[str, JsonValue], sample["tkinter"])
        qt_data = cast(dict[str, JsonValue], sample["pyside6"])
        tk_seconds = cast(float, tk_data["elapsed_seconds"])
        qt_seconds = cast(float, qt_data["elapsed_seconds"])
        tk_rate = ITEM_COUNT / tk_seconds
        qt_rate = ITEM_COUNT / qt_seconds
        sample["rates_per_second"] = {"tkinter": tk_rate, "pyside6": qt_rate}
        sample["percent_delta"] = (qt_rate / tk_rate - 1) * 100
        raw.append(sample)
    rates_tk = [ITEM_COUNT / cast(float, cast(dict[str, JsonValue], row["tkinter"])["elapsed_seconds"])
                for row in raw]
    rates_qt = [ITEM_COUNT / cast(float, cast(dict[str, JsonValue], row["pyside6"])["elapsed_seconds"])
                for row in raw]
    deltas = [(qt / tk - 1) * 100 for tk, qt in zip(rates_tk, rates_qt, strict=True)]
    interval = bootstrap_delta_ci(deltas, f"issue445/import/{baseline_sha}/{candidate_sha}")
    summary: dict[str, JsonValue] = {
        "status": "PASS", "candidate_sha": candidate_sha,
        "baseline_sha": baseline_sha, "item_count": ITEM_COUNT,
        "group_count": groups,
        "fixture_digest": raw[0]["fixture_digest"],
        "renderers": {"tkinter": describe(rates_tk), "pyside6": describe(rates_qt)},
        "paired": {
            "percent_delta": sum(deltas) / len(deltas),
            "confidence_95_percent": interval, "deltas_percent": deltas,
        },
        "guard": {"throughput_loss_fraction": THROUGHPUT_LOSS_GUARD,
                  "classification": "review separately from confidence interval"},
        "samples": raw,
    }
    (output / "import-throughput.json").write_text(
        json.dumps(summary, indent=2, allow_nan=False) + "\n", encoding="utf-8",
    )
    with (output / "import-throughput.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=(
            "pair", "seed", "fixture_digest", "tk_seconds", "qt_seconds",
            "tk_confirmed", "qt_confirmed", "tk_bytes", "qt_bytes",
            "tk_items_per_second", "qt_items_per_second", "percent_delta", "order",
        ))
        writer.writeheader()
        for row in raw:
            tk_data = cast(dict[str, JsonValue], row["tkinter"])
            qt_data = cast(dict[str, JsonValue], row["pyside6"])
            rates = cast(dict[str, JsonValue], row["rates_per_second"])
            writer.writerow({
                "pair": row["pair"], "seed": row["seed"],
                "fixture_digest": row["fixture_digest"],
                "tk_seconds": tk_data["elapsed_seconds"],
                "qt_seconds": qt_data["elapsed_seconds"],
                "tk_confirmed": tk_data["confirmed_items"],
                "qt_confirmed": qt_data["confirmed_items"],
                "tk_bytes": tk_data["bytes_sent"], "qt_bytes": qt_data["bytes_sent"],
                "tk_items_per_second": rates["tkinter"],
                "qt_items_per_second": rates["pyside6"],
                "percent_delta": row["percent_delta"],
                "order": ">".join(cast(list[str], row["order"])),
            })
    return summary


def main() -> int:
    """Dispatch one source-bound child; comparison is called by paired audit code.

    Returns:
        int: Zero only when child evidence passes all exact checks.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--child", action="store_true")
    parser.add_argument("--renderer", choices=("tkinter", "pyside6"))
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--seed", type=int, default=PAIR_SEED_BASE)
    parser.add_argument("--private", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if not args.child or args.renderer is None:
        parser.error("This fixture is launched as one child by run_import_comparison().")
    args.private.mkdir(parents=True, exist_ok=True)
    args.started = time.perf_counter()
    return _child(args)


if __name__ == "__main__":
    raise SystemExit(main())
