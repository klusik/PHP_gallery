# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_434_tk_shell.py
# Module Type: Windows Native UI Workload Driver
# Purpose: Measure bounded synthetic WinApp shell, Watcher, Activity and Import workloads.
# Responsibilities:
#   - Mount scenario-specific production Tk surfaces and deterministic interactions.
#   - Exercise real Watcher event draining and isolated durable import state.
#   - Report workload counters and clean up owned callbacks and workers.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Provide shell workloads for the Issue 434 native responsiveness runner."""

import copy
import hashlib
import os
import time
import tkinter as tk
from pathlib import Path
from types import SimpleNamespace
from typing import Protocol, TypedDict
from unittest import mock

from issue_361_tk_workloads import Recorder, load_watcher

from uploader.models import ActivityEvent, ImportItem, ImportJob
from uploader.state_store import atomic_write_json, default_upload_state
from uploader.ui_theme import Button

# Purpose: Bound import rows while covering the three requested dataset sizes.
# Type: tuple[int, ...]. Units: import items. Scope: one import scenario.
# Consumers: Issue434ShellWorkload._mount_import and counters.
# Rationale: 100, 1,000 and 5,000 distinguish small, normal and stress rendering.
IMPORT_DATASET_SIZES = (100, 1000, 5000)
# Purpose: Bound event production in one deterministic Watcher burst.
# Type: int. Units: queued events. Scope: one burst interaction.
# Consumers: Issue434ShellWorkload.step.
# Rationale: 256 events pressure the production FIFO while its own batch budget yields.
WATCHER_BURST_EVENTS = 256
# Purpose: Bound source activity history independent of the visible drawer limit.
# Type: int. Units: events. Scope: one shell scenario.
# Consumers: Issue434ShellWorkload._mount_activity.
# Rationale: 1,000 records exercise filtering and bounded Treeview reconciliation.
ACTIVITY_EVENT_COUNT = 1000
# Purpose: Bound durable job history independently of the visible history limit.
# Type: int. Units: jobs. Scope: one history scenario.
# Consumers: Issue434ShellWorkload._mount_history.
# Rationale: 1,000 synthetic job snapshots expose history refresh cost.
HISTORY_JOB_COUNT = 1000
# Purpose: Cap the largest requested durable history fixture.
# Type: int. Units: jobs. Scope: one isolated JobStateStore snapshot.
# Consumers: Issue434ShellWorkload._seed_activity.
# Rationale: 5,000 records bound serialization while covering the stated stress extent.
SYNTHETIC_HISTORY_LIMIT = 5000
# Purpose: Bound event draining to a small cooperative action per step.
# Type: int. Units: events. Scope: one deterministic interaction.
# Consumers: Issue434ShellWorkload.step.
# Rationale: 32 events provide steady pressure without unbounded fixture growth.
WATCHER_STEADY_EVENTS = 32
# Purpose: Keep background continuations alive across repeated runner samples.
# Type: int. Units: milliseconds. Scope: long-session scenario.
# Consumers: Issue434ShellWorkload._schedule_long_session.
# Rationale: 250 ms matches the existing Watcher monitor cadence.
LONG_SESSION_INTERVAL_MS = 250
# Purpose: Admit one minimum positive fixture multiplier from the protocol.
# Type: int. Units: scale units. Scope: one Issue 434 workload instance.
# Consumers: Issue434ShellWorkload.__init__.
# Rationale: A zero-sized workload cannot exercise a native scenario.
MIN_SCALE = 1
# Purpose: Pace synthetic production-side import outcomes without saturating storage.
# Type: float. Units: seconds per item. Scope: one synthetic ManualUploadThread.
# Consumers: Issue434ShellWorkload._start_synthetic_import_worker.
# Rationale: 50 ms keeps imports active across the standard measured window.
IMPORT_PROGRESS_INTERVAL_SECONDS = 0.05
# Purpose: Bound shutdown waiting for the synthetic import worker.
# Type: float. Units: seconds. Scope: one isolated shell workload teardown.
# Consumers: Issue434ShellWorkload.close.
# Rationale: The worker checks cancellation before each bounded synthetic item.
WORKER_SHUTDOWN_SECONDS = 1.0


class ShellWorkload(Protocol):
    """Describe the lifecycle and observable counters required by the runner."""

    def ready(self) -> bool:
        """Report whether the mounted workload can accept measurement.

        Returns:
            bool: True after the Tk root and required scenario controls are mapped.
        """

    def step(self) -> None:
        """Perform one deterministic interaction or event delivery.

        Returns:
            None: The workload advances by one bounded action.
        """

    def counters(self) -> dict[str, int | float | str | bool | None]:
        """Return bounded workload counts and explicit coverage markers.

        Returns:
            dict[str, int | float | str | bool | None]: Scalar measurements only.
        """

    def close(self) -> None:
        """Cancel workload-owned callbacks and release isolated state.

        Returns:
            None: No workload-owned Tk callback remains scheduled.
        """


class ProgressProbeEvent(TypedDict, total=False):
    """Describe only the routing and timing fields read by the queue probe."""

    kind: str
    _issue_434_progress_enqueue_ns: int


class Issue434ShellWorkload:
    """Own one Issue 434 shell scenario, its production host and fixture state."""

    def __init__(
        self,
        root: tk.Tk,
        source_root: Path,
        private_root: Path,
        scenario: str,
        seed: int,
        scale: int,
        raster_size: tuple[int, int] | None,
        recorder: Recorder,
    ) -> None:
        """Mount a named bounded workload against the supplied Tk root.

        Args:
            root (tk.Tk): Native Tk root owned by the isolated scenario process.
            source_root (Path): Checkout containing the production WinApp source.
            private_root (Path): Process-private directory for durable synthetic state.
            scenario (str): Issue 434 scenario identifier.
            seed (int): Deterministic synthetic data seed.
            scale (int): Protocol workload multiplier, clamped to a positive value.
            raster_size (tuple[int, int] | None): Optional runner-selected raster size.
            recorder (Recorder): Shared bounded interaction and callback recorder.

        Returns:
            None: Production surfaces and synthetic records are ready for mapping.
        """
        self.root = root
        self.source_root = source_root
        self.private_root = private_root
        self.scenario = scenario
        self.seed = seed
        self.scale = max(MIN_SCALE, scale)
        self.raster_size = raster_size
        self.recorder = recorder
        self.module = load_watcher(source_root)
        self.host = None
        self.store = None
        self.job = None
        self.durable_jobs = 0
        self.steps = 0
        self.produced = 0
        self.persisted_snapshots = 0
        self.confirmed_import_items = 0
        self.drained = 0
        self.queue_peak = 0
        self.refreshes = 0
        self.callback_id: str | None = None
        self.worker_errors: list[str] = []
        self.import_worker = None
        self.dashboard_tabs = ("watch", "activity", "settings", "photos")
        self.profile_globals: dict[str, Path] = {}
        self._ready = False
        self._closed = False
        self._mount()

    def _mount(self) -> None:
        """Build real production shell controls and scenario-specific fixture data.

        Returns:
            None: The chosen scenario is mounted under the provided root.
        """
        self.private_root.mkdir(parents=True, exist_ok=True)
        self._mount_application()
        if self.scenario in ("import", "import-gui", "long-session"):
            self._mount_import()
        if self.scenario in ("history", "activity"):
            self._mount_import()
            self._seed_activity()
        if self.scenario in ("import", "import-gui", "long-session"):
            self._start_synthetic_import_worker()
            self.host.select_tab("import")
        elif self.scenario in ("activity", "history"):
            self.host.select_tab("activity")
        elif self.scenario not in ("mixed", "idle"):
            self.host.select_tab("watch")
        if self.scenario in ("dashboard", "idle"):
            self._mount_dashboard_controls()
        if self.scenario == "tray":
            self._mount_tray_proxy()
        if self.scenario in ("long-session", "tray"):
            self._schedule_long_session()
        self.root.update_idletasks()

    def _mount_application(self) -> None:
        """Construct the real WatcherApp against the supplied isolated Tk root.

        Returns:
            None: Production panels, refresh methods and periodic event drain
                are active with configuration and state paths rooted privately.
        """
        profile = self.private_root / "profile"
        private_config = profile / "PHPGalleryUploader"
        private_config.mkdir(parents=True, exist_ok=True)
        self.profile_globals = {
            name: getattr(self.module, name)
            for name in ("CONFIG_DIR", "CONFIG_PATH", "STATE_PATH", "LOG_PATH", "STAGING_ROOT")
        }
        self.module.CONFIG_DIR = private_config
        self.module.CONFIG_PATH = private_config / "config.json"
        self.module.STATE_PATH = private_config / "upload_state.json"
        self.module.LOG_PATH = private_config / "watcher.log"
        self.module.STAGING_ROOT = private_config / "staging"
        tk_bindings = dict(vars(self.module.tk))
        tk_bindings["Tk"] = lambda: self.root
        tk_namespace = SimpleNamespace(**tk_bindings)
        with (
            mock.patch.object(self.module, "tk", tk_namespace),
            mock.patch.object(self.module, "synchronize_startup", return_value=None),
            mock.patch.object(self.module.GamePresence, "start", lambda _presence: None),
            mock.patch.object(
                self.module.WatcherApp, "start_tray_icon", lambda _app: None,
            ),
            mock.patch.dict(os.environ, {"APPDATA": str(profile)}),
        ):
            self.host = self.module.WatcherApp(
                config_store=self.module.ConfigStore(self.module.CONFIG_PATH),
                start_minimized=False,
            )
        self.store = self.host.job_store
        self.host._schedule_event_drain(0)

    def _mount_import(self) -> None:
        """Attach a deterministic real ImportJob to production import controls.

        Returns:
            None: The isolated production JobStateStore and real import tables
                contain the selected bounded item extent.
        """

        scenario_default = {
            "import-gui": 1000,
            "mixed": 500,
            "long-session": 500,
            "watcher": 100,
            "watcher-burst": 100,
            "tray": 100,
            "history": 100,
            "activity": 100,
        }.get(self.scenario, 1000)
        size = (
            self.scale if self.scenario == "import" and self.scale in IMPORT_DATASET_SIZES
            else scenario_default
        )
        job = ImportJob(
            id=f"issue434-{self.scenario}-{self.seed}",
            source_kind="folder", source_label="Synthetic benchmark folder",
            target_gallery_label="Synthetic gallery",
            items=[
                ImportItem(
                    id=f"issue434-item-{self.seed}-{index:05d}",
                    path=str(self.private_root / f"synthetic-{index:05d}.jpg"),
                    source_label=f"synthetic-{index:05d}.jpg",
                    size=1024 + index,
                    sha256=hashlib.sha256(
                        f"{self.seed}:{index}".encode("ascii"),
                    ).hexdigest(),
                    state="discovered",
                    local_decode="local",
                )
                for index in range(size)
            ],
            bytes_total=sum(1024 + index for index in range(size)),
            created_at=1_800_000_000.0,
        )
        app = self.host
        self.store = app.job_store
        app.current_job = job
        self.job = job
        self.store.save_job(job)
        self.durable_jobs = 1
        app.refresh_import_item_tree(job)
        app.refresh_job_history()
        app.refresh_activity_drawer()
        self.refreshes += 3

    def _seed_activity(self) -> None:
        """Persist deterministic synthetic Activity records through the real store.

        Returns:
            None: The Activity source and durable job history are populated.
        """

        app = self.host
        activity_count = (
            self.scale if self.scale in (100, 1000, 5000)
            else ACTIVITY_EVENT_COUNT
        )
        app.activity_events = [
            ActivityEvent(
                "warning" if index % 5 == 0 else "info",
                f"Synthetic issue 434 event {self.seed}-{index}",
                operation="import", filename=f"source-{index:05d}.jpg",
                timestamp=1_800_000_000.0 + index,
            )
            for index in range(activity_count)
        ]
        if self.store is not None:
            history_count = min(
                self.scale if self.scale in (100, 1000, 5000) else HISTORY_JOB_COUNT,
                SYNTHETIC_HISTORY_LIMIT,
            )
            snapshot = default_upload_state()
            history_jobs = {}
            for index in range(1, history_count):
                job = self.module.ImportJob(
                    id=f"issue434-history-{self.seed}-{index:05d}",
                    source_kind="folder",
                    source_label=f"Synthetic history {index}",
                    target_gallery_label="Synthetic gallery",
                    created_at=1_800_000_000.0 + index,
                )
                history_jobs[job.id] = job.to_dict()
            snapshot["jobs"] = {self.job.id: self.job.to_dict(), **history_jobs}
            atomic_write_json(self.store.path, snapshot)
            self.durable_jobs = history_count
            self.store.append_events(app.activity_events)
            self.store.list_jobs()
        app.refresh_job_history()
        app.refresh_activity_drawer()
        self.refreshes += 2

    def _start_synthetic_import_worker(self) -> None:
        """Run production ManualUploadThread persistence without file or network I/O.

        Returns:
            None: A real worker thread advances synthetic ImportJob outcomes and
                publishes actual structured progress events to the Tk queue.
        """
        worker_job = copy.deepcopy(self.job)
        worker = self.module.ManualUploadThread(
            self.host.config,
            [Path(item.path) for item in worker_job.items],
            False, 1, 1, self.host.events, worker_job, self.store,
        )
        self._observe_import_progress()

        def synthetic_run() -> None:
            """Persist synthetic item transitions through the worker's real owner.

            Returns:
                None: The worker stops at completion or its owned cancellation event.
            """
            try:
                worker_job.started_at = time.time()
                worker.persist_job()
                self.persisted_snapshots += 1
                worker.emit("info", f"Synthetic import started: {len(worker.paths)} items.")
                for index, path in enumerate(worker.paths):
                    if worker.stop_event.is_set():
                        break
                    digest = hashlib.sha256(
                        f"{self.seed}:{index}".encode("ascii"),
                    ).hexdigest()
                    worker.update_item(
                        path, "uploading", increment_attempt=True,
                    )
                    self.persisted_snapshots += 1
                    worker.update_item(
                        path, "confirmed", file_hash=digest, count_bytes=True,
                    )
                    self.persisted_snapshots += 1
                    self.confirmed_import_items += 1
                    if worker.stop_event.wait(IMPORT_PROGRESS_INTERVAL_SECONDS):
                        break
                if not worker.stop_event.is_set():
                    worker_job.finished_at = time.time()
                    worker.persist_job()
                    self.persisted_snapshots += 1
                    worker.emit("info", "Synthetic import finished.")
            except Exception as error:
                self.worker_errors.append(f"{type(error).__name__}: {error}")

        worker.run = synthetic_run
        self.import_worker = worker
        self.host.manual_worker = worker
        worker.start()

    def _observe_import_progress(self) -> None:
        """Time equivalent worker handoff and software publication on every ref.

        Returns:
            None: Queue and progress label probes are scoped to this fixture.
        """
        events = self.host.events
        enqueue = events.put
        dequeue = events.get_nowait
        set_progress_text = self.host.manual_progress_text_var.set

        def timed_enqueue(
            event: tuple[str, str] | ProgressProbeEvent,
            block: bool = True,
            timeout: float | None = None,
        ) -> None:
            """Timestamp a synthetic import's original progress event before enqueue.

            Args:
                event (tuple[str, str] | ProgressProbeEvent): Original severity
                    and text pair or structured event with an optional kind.
                block (bool): Original queue admission behavior.
                timeout (float | None): Original queue put timeout in seconds.

            Returns:
                None: Enqueues the same event on its production route.
            """
            if isinstance(event, dict) and event.get("kind") == "job_progress":
                event["_issue_434_progress_enqueue_ns"] = time.perf_counter_ns()
            enqueue(event, block, timeout)

        def timed_dequeue() -> tuple[str, str] | ProgressProbeEvent:
            """Measure time until Tk takes a progress event from its FIFO.

            Returns:
                tuple[str, str] | ProgressProbeEvent: Original event with the
                    benchmark marker removed.

            Raises:
                queue.Empty: The original FIFO has no event.
            """
            event = dequeue()
            if isinstance(event, dict):
                published = event.pop("_issue_434_progress_enqueue_ns", None)
                if isinstance(published, int):
                    self.recorder.add(
                        "import_progress_queue_handoff", time.perf_counter_ns() - published,
                    )
            return event

        def timed_progress_text(value: str) -> None:
            """Observe completed publications of the same Tk progress text.

            Args:
                value (str): New value requested by the normal progress presenter.

            Returns:
                None: Writes through to the original string variable.
            """
            started = time.perf_counter_ns()
            set_progress_text(value)
            self.recorder.add("import_progress_text_publication", time.perf_counter_ns() - started)

        events.put = timed_enqueue
        events.get_nowait = timed_dequeue
        self.host.manual_progress_text_var.set = timed_progress_text

    def _mount_dashboard_controls(self) -> None:
        """Expose real Watcher status and Activity controls for idle/dashboard runs.

        Returns:
            None: Dashboard controls are visible without starting external work.
        """
        app = self.host
        app.update_monitor_state("idle", "Synthetic idle monitor state")
        self.root.title("PHP Gallery synthetic Watcher dashboard")

    def _mount_tray_proxy(self) -> None:
        """Create a portable minimize/background proxy for shell continuation.

        Returns:
            None: Root withdrawal can be measured; native shell tray coverage is marked.
        """

        Button(
            self.root, text="Minimize to background", command=self.root.withdraw,
            style="Photos.Icon.TButton",
        ).pack()
        Button(
            self.root, text="Restore dashboard", command=self.root.deiconify,
            style="Photos.Icon.TButton",
        ).pack()

    def _schedule_long_session(self) -> None:
        """Schedule bounded recurring Watcher queue production until close.

        Returns:
            None: One owned callback keeps background work active across samples.
        """
        def deliver() -> None:
            """Queue one steady synthetic event batch and reschedule while owned.

            Returns:
                None: The queue advances and one continuation is retained.
            """
            if self._closed:
                return
            self.callback_id = None
            self._produce_watcher_events(WATCHER_STEADY_EVENTS)
            self.callback_id = self.root.after(
                LONG_SESSION_INTERVAL_MS, deliver,
            )

        self.callback_id = self.root.after(LONG_SESSION_INTERVAL_MS, deliver)

    def _produce_watcher_events(self, count: int) -> None:
        """Queue synthetic scan, queue-progress and log events for WatcherApp.

        Args:
            count (int): Number of bounded event records to enqueue.

        Returns:
            None: Events enter the production FIFO and are consumed by its timer.
        """
        if count < 1:
            return
        host = self.host
        timestamp = time.time()
        host.events.put({
            "kind": "watch_scan",
            "payload": {"worker": host.worker, "timestamp": timestamp},
        })
        if count > 1:
            host.events.put({
                "kind": "watch_queue",
                "payload": {
                    "worker": host.worker,
                    "state": "capturing",
                    "queued_count": self.produced + count,
                    "head_sequence": self.produced + 1,
                    "head_file": f"synthetic-{self.produced + 1:05d}.jpg",
                    "last_scan": timestamp,
                    "last_progress": timestamp,
                },
            })
        for index in range(max(0, count - 2)):
            level = "warning" if index % 17 == 0 else "info"
            host.events.put((
                level,
                f"Synthetic Watcher {level} event {self.produced + index + 1}",
            ))
        self.produced += count
        self.queue_peak = max(self.queue_peak, host.events.qsize())

    def ready(self) -> bool:
        """Report when the native root and required shell controls are mapped.

        Returns:
            bool: True after Tk reports a mapped root with a nonzero client area.
        """
        self.root.update_idletasks()
        self._ready = bool(
            self.root.winfo_exists() and self.root.winfo_width() > 1
            and self.root.winfo_height() > 1 and self.root.winfo_ismapped()
        )
        return self._ready

    def step(self) -> None:
        """Advance one deterministic interaction according to the scenario.

        Returns:
            None: One bounded state transition or delivery has been performed.
        """
        self.steps += 1
        if self.scenario in ("watcher", "mixed", "tray", "long-session"):
            self._produce_watcher_events(WATCHER_STEADY_EVENTS)
            if self.scenario == "mixed":
                self.host.select_tab("photos")
            if self.scenario == "tray":
                self.root.withdraw() if self.steps % 2 else self.root.deiconify()
        elif self.scenario == "watcher-burst":
            self._produce_watcher_events(WATCHER_BURST_EVENTS)
        elif self.scenario in ("activity", "history"):
            filters = ("all", "warnings", "failed")
            self.host.activity_filter_var.set(filters[(self.steps - 1) % len(filters)])
            if self.scenario == "activity":
                self._produce_watcher_events(WATCHER_STEADY_EVENTS)
            else:
                self.host.refresh_job_history()
            self.host.refresh_activity_drawer()
            self.refreshes += 1
        elif self.scenario in ("import", "import-gui"):
            if self.scenario == "import-gui":
                self.host.select_tab(
                    "photos" if self.steps % 2 else "import",
                )
        elif self.scenario in ("idle", "dashboard"):
            if self.scenario == "dashboard":
                self.host.select_tab(
                    self.dashboard_tabs[(self.steps - 1) % len(self.dashboard_tabs)],
                )
                if self.steps % 4 == 0:
                    self.host.update_monitor_state(
                        "idle", f"Synthetic status {self.steps}",
                    )
            # Idle intentionally has no user interaction or event production.

    def counters(self) -> dict[str, int | float | str | bool | None]:
        """Return bounded scalar counts and explicit workload coverage notes.

        Returns:
            dict[str, int | float | str | bool | None]: Runner-facing counters.
        """
        pending = self.host.events.qsize() if self.host is not None else 0
        self.queue_peak = max(self.queue_peak, pending)
        self.drained = self.host._event_drain_total_count
        worker_alive = bool(
            self.import_worker is not None and self.import_worker.is_alive()
        )
        operations_met = {
            "idle": self._ready,
            "dashboard": self.steps > 0,
            "watcher": self.produced > 0 and self.drained > 0,
            "watcher-burst": self.produced >= WATCHER_BURST_EVENTS and self.drained > 0,
            "tray": self.produced > 0 and self.drained > 0,
            "activity": self.refreshes > 0,
            "import": self.persisted_snapshots > 1,
            "history": self.durable_jobs > 1 and self.refreshes > 0,
            "mixed": self.steps > 0 and self.produced > 0,
            "import-gui": self.steps > 0 and self.persisted_snapshots > 1,
            "long-session": self.steps > 0 and self.persisted_snapshots > 1 and self.produced > 0,
        }.get(self.scenario, False)
        operations_met = operations_met and not self.worker_errors
        return {
            "scenario": self.scenario,
            "steps": self.steps,
            "events_produced": self.produced,
            "persisted_job_snapshots": self.persisted_snapshots,
            "confirmed_import_items": self.confirmed_import_items,
            "events_drained": self.drained,
            "events_pending": pending,
            "event_queue_peak": self.queue_peak,
            "event_order": "producer FIFO sequence consumed by production event drain",
            "activity_source_rows": len(getattr(self.host, "activity_events", [])),
            "durable_jobs": self.durable_jobs,
            "import_rows": len(self.job.items) if self.job is not None else 0,
            "refreshes": self.refreshes,
            "ready": self._ready,
            "import_worker_alive": worker_alive,
            "import_worker_error_count": len(self.worker_errors),
            "import_worker_last_error": self.worker_errors[-1] if self.worker_errors else None,
            "required_operations_met": operations_met,
            "event_drain_yields": self.host._event_drain_yield_count,
            "event_drain_max_ms": self.host._event_drain_max_ms,
            "tray_native_available": False if self.scenario == "tray" else None,
            "tray_coverage": "window withdrawal proxy; native tray thread disabled" if self.scenario == "tray" else None,
            "network_access": False,
            "filesystem_fixture_only": True,
            "progress_route": "ManualUploadThread.persist_job -> WatcherApp.drain_events",
            "event_throughput_basis": "drained events divided by runner measured duration",
            "persist_throughput_basis": "persisted_job_snapshots divided by runner measured duration",
            "native_tray_started": False,
        }

    def stop_background_input(self) -> None:
        """Stop admitting synthetic import work at the measured boundary.

        Returns:
            None: One already-started durable transition can complete normally.
        """
        if self.import_worker is not None:
            self.import_worker.stop_event.set()

    def close(self) -> None:
        """Cancel owned recurring callbacks and production event-drain timers.

        Returns:
            None: Workload callbacks are cancelled and no worker is left running.
        """
        if self._closed:
            return
        self._closed = True
        cleanup_errors: list[str] = []
        if self.callback_id is not None:
            try:
                self.root.after_cancel(self.callback_id)
            except tk.TclError:
                pass
            self.callback_id = None
        if self.host is not None:
            self.host.exiting = True
            reader = getattr(self.host, "_import_history_reader", None)
            if reader is not None:
                # Stop new history reads before the import worker's final
                # durable transition competes for the private state owner.
                reader.close()
            try:
                self.host._cancel_event_ui_callbacks()
            except (tk.TclError, AttributeError):
                pass
            for attribute in (
                "_ui_layout_save_after", "_watcher_recording_after",
            ):
                callback_id = getattr(self.host, attribute, None)
                if callback_id is not None:
                    try:
                        self.root.after_cancel(callback_id)
                    except tk.TclError:
                        pass
                    setattr(self.host, attribute, None)
            if self.import_worker is not None and self.import_worker.is_alive():
                self.stop_background_input()
                self.import_worker.join(WORKER_SHUTDOWN_SECONDS)
                if self.import_worker.is_alive():
                    cleanup_errors.append("Synthetic import worker exceeded teardown deadline.")
            if reader is not None:
                if not reader.wait_closed(WORKER_SHUTDOWN_SECONDS):
                    cleanup_errors.append("Import history reader exceeded teardown deadline.")
            writer = getattr(self.host, "activity_writer", None)
            if writer is not None and not writer.close(WORKER_SHUTDOWN_SECONDS):
                cleanup_errors.append("Activity writer exceeded teardown deadline.")
            presence = getattr(self.host, "simulator_presence", None)
            if presence is not None:
                presence.stop()
            linking = getattr(self.host, "linking_manager", None)
            try:
                if linking is not None:
                    linking.close()
            finally:
                # This fixture stays in one Python process for its JSON write,
                # while the standalone application releases the lock at exit.
                lease = self.host.flights.lease
                if lease is not None:
                    lease.release()
        for name, path in self.profile_globals.items():
            setattr(self.module, name, path)
        if cleanup_errors:
            raise RuntimeError(" ".join(cleanup_errors))


def mount(
    root: tk.Tk,
    source_root: Path,
    private_root: Path,
    scenario: str,
    seed: int,
    scale: int,
    raster_size: tuple[int, int] | None,
    recorder: Recorder,
) -> ShellWorkload:
    """Create one Issue 434 P0 shell workload for the isolated runner.

    Args:
        root (tk.Tk): Native root owned by one scenario process.
        source_root (Path): Checkout containing WinApp production modules.
        private_root (Path): Process-private synthetic state directory.
        scenario (str): Scenario identifier from the frozen Issue 434 protocol.
        seed (int): Deterministic synthetic data seed.
        scale (int): Positive workload multiplier selected by the protocol.
        raster_size (tuple[int, int] | None): Optional raster profile supplied by runner.
        recorder (Recorder): Shared bounded performance recorder.

    Returns:
        ShellWorkload: Mounted workload with ready, step, counters and close methods.
    """
    return Issue434ShellWorkload(
        root, source_root, private_root, scenario, seed, scale, raster_size, recorder,
    )
