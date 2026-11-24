# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_434_tk_workloads.py
# Module Type: Windows Native UI Workload Driver
# Purpose: Measure isolated Tk shell and media workloads for issue 434.
# Responsibilities:
#   - Run one deterministic workload in an isolated Tk process.
#   - Retain bounded timing, operation, resource and failure evidence.
#   - Keep application profiles and generated fixtures inside a private root.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Run one instrumented issue 434 Tk workload and write its evidence record."""

import argparse
import cProfile
import importlib
import importlib.util
import json
import math
import os
import platform
import sys
import tempfile
import threading
import time
import traceback
import tkinter as tk
from contextlib import contextmanager
from pathlib import Path
from types import ModuleType, TracebackType
from typing import Callable, Iterator, ParamSpec, Protocol, TypeAlias, TypeVar, cast

from issue_434_tk_presentation_probe import PresentationProbe

# The common workload binds the supplied production checkout at module scope.
import issue_361_tk_workloads
from uploader.windows_display import prepare_windows_display

try:
    import psutil
except ImportError:
    psutil = None

try:
    from PIL import ImageGrab, __version__ as pillow_version
except ImportError:
    ImageGrab = None
    pillow_version = None

# Purpose: Allow adapter setup and teardown around the user-selected duration.
# Type: int. Units: milliseconds. Scope: one child process watchdog.
# Consumers: hard watchdog deadline. Rationale: 30 seconds bounds both setup
# and orderly cleanup even for a 180-second long-session measurement.
LIFECYCLE_BUDGET_MS = 30000
# Purpose: Bound each retained exception message.
# Type: int. Units: characters. Scope: one output record.
# Consumers: callback exception handler. Rationale: 1000 characters retains
# useful failure detail without unbounded native error text.
FAILURE_TEXT_LIMIT = 1000
# Purpose: Bound retained traceback detail.
# Type: int. Units: characters. Scope: one output record.
# Consumers: failure diagnostics. Rationale: 12000 characters preserve nearby
# source frames while limiting evidence size.
FAILURE_TRACEBACK_LIMIT = 12000
# Purpose: Bound readiness polling cadence.
# Type: int. Units: milliseconds. Scope: startup and workload readiness.
# Consumers: readiness callback. Rationale: 50 ms yields to Tk and source
# workers without busy polling.
READY_POLL_MS = 50
# Purpose: Bound deterministic untimed workload warmup actions.
# Type: int. Units: actions. Scope: each mounted module.
# Consumers: warmup callback. Rationale: three calls prime a small fixed
# working set without consuming the measured duration.
WARMUP_STEPS = 3
# Purpose: Allow warmup-triggered background work to settle before sampling.
# Type: int. Units: milliseconds. Scope: one scenario startup.
# Consumers: measured phase startup. Rationale: 500 ms gives cooperative
# fixture workers a quiet period without extending long-session runtimes much.
WARMUP_SETTLE_MS = 500
# Purpose: Select one visible native window size for comparable scenarios.
# Type: tuple[int, int]. Units: logical pixels. Scope: Tk root window.
# Consumers: root geometry and display metadata. Rationale: 900x680 exposes
# multiple real rows while remaining within standard CI desktop dimensions.
VIEWPORT_SIZE = (900, 680)
# Purpose: Convert monotonic nanoseconds into milliseconds.
# Type: int. Units: nanoseconds per millisecond. Scope: emitted timing metrics.
# Consumers: timing aggregation. Rationale: exact SI conversion is 1,000,000.
NS_PER_MS = 1000000
# Purpose: Summarize independent Tk heartbeat tail latency.
# Type: tuple[tuple[str, float], ...]. Units: percentile fractions.
# Scope: event_loop_drift observations in one measured phase.
# Consumers: output metrics. Rationale: p50/p95/p99 and maximum expose both
# ordinary scheduling and rare stalls at the 16 ms native heartbeat cadence.
DRIFT_PERCENTILES = (("p50", 0.50), ("p95", 0.95), ("p99", 0.99))
# Purpose: Count workload steps crossing recognized interactive stall bands.
# Type: tuple[float, ...]. Units: milliseconds. Scope: measured action latency.
# Consumers: per-threshold workload_step metrics. Rationale: 16.7 ms represents
# one 60 Hz frame; increasing bands expose progressively longer visible stalls.
STALL_THRESHOLDS_MS = (16.7, 33.3, 50, 100, 250, 500)
# Purpose: Bound retained workload and sampled resource events.
# Type: int. Units: observations. Scope: one child evidence record.
# Consumers: raw event collection. Rationale: 20,000 observations retain
# useful distributions while preventing long sessions from growing artifacts.
RAW_EVENT_LIMIT = 20000
# Purpose: Retain the 180-second phase-2 callback and heartbeat trace.
# Type: int. Units: observations. Scope: issue 434 Recorder instance only.
# Consumers: runtime override of the imported issue 361 Recorder cap.
# Rationale: 250,000 samples cover a 180-second, 1 ms action cadence plus the
# independent 16 ms heartbeat while leaving issue 361 source policy unchanged.
PHASE2_RAW_EVENT_LIMIT = 250000
# Purpose: Poll process and Tk queue resource gauges during measurement.
# Type: int. Units: milliseconds. Scope: measured mainloop phase.
# Consumers: resource sampler. Rationale: 250 ms resolves sustained growth
# while keeping diagnostic sampling below one percent of event-loop cadence.
RESOURCE_SAMPLE_MS = 250

CounterValue: TypeAlias = int | float | str | bool | None
JsonValue: TypeAlias = (
    str | int | float | bool | None | list["JsonValue"] | dict[str, "JsonValue"]
)
JsonDecodeOption: TypeAlias = (
    type[json.JSONDecoder]
    | Callable[[str], JsonValue]
    | Callable[[dict[str, JsonValue]], JsonValue]
    | Callable[[list[tuple[str, JsonValue]]], JsonValue]
    | bool | None
)


class Workload(Protocol):
    """Describe the typed lifecycle contract supplied by one workload owner."""

    def ready(self) -> bool:
        """Return whether startup and any requested cache preparation settled.

        Returns:
            bool: True only when the workload can accept measured steps.
        """

    def step(self) -> None:
        """Deliver one production action or idle opportunity to Tk.

        Returns:
            None: One adapter action or idle opportunity is delivered.
        """

    def counters(self) -> dict[str, CounterValue]:
        """Return bounded scalar operation and resource counters.

        Returns:
            dict[str, CounterValue]: Safe scalar names and values.
        """

    def close(self) -> None:
        """Cancel owned work and release all Tk and image resources.

        Returns:
            None: The workload relinquishes its owned resources.
        """


class RecorderProbe(Protocol):
    """Describe the reused issue 361 timing and Tk operation recorder."""

    phase: str
    events: list[dict[str, str | int]]
    truncated: int
    operation_counts: dict[str, int]

    def install(self) -> None:
        """Install process-local Tk and image operation wrappers.

        Returns:
            None: Recorder wrappers are available for the child process.
        """

    def restore(self) -> None:
        """Restore wrapped process-global methods.

        Returns:
            None: Original Tk and image methods are restored.
        """

    def start_heartbeat(self, root: tk.Misc) -> None:
        """Start measured event-loop drift observations.

        Args:
            root (tk.Misc): Tk event-loop owner receiving the heartbeat.

        Returns:
            None: The heartbeat timer begins.
        """

    def stop_heartbeat(self) -> None:
        """Stop the owned Tk heartbeat callback.

        Returns:
            None: The owned heartbeat timer is cancelled.
        """


P = ParamSpec("P")
R = TypeVar("R")

STATE_WRITE_TIMINGS = (
    "accepted_work_owner_entry", "accepted_work_owner_hold", "upload_state_atomic_write",
)


class StoreOwner(Protocol):
    """Expose the selected upload-state path for a JobStateStore read."""

    path: Path


class StoreJobRecord(Protocol):
    """Describe the only ImportJob field exposed by strict read sampling."""

    created_at: float


class StateStoreProbe:
    """Measure isolated upload-state reads, writes and context duration.

    The probe observes only upload_state.json below the private child profile.
    It keeps separate bounded samples instead of consuming issue 361 Recorder
    capacity, and restores every process-global wrapper at teardown.
    """

    def __init__(self, state_module: ModuleType, private_root: Path) -> None:
        """Initialize counters and retain wrappers for the selected module.

        Args:
            state_module (ModuleType): Imported uploader.state_store module.
            private_root (Path): Private root containing all child profiles.

        Returns:
            None: The probe is ready to install source-neutral wrappers.
        """
        self.state_module = state_module
        self.private_root = private_root.resolve()
        # Hosted Windows can expose the same temporary profile through an
        # 8.3 alias. Keep both spellings without resolving each timed write.
        self._profile_roots = (private_root, self.private_root)
        self.counters: dict[str, int] = {
            "legacy_read_invocations_total": 0,
            "mutation_read_invocations_total": 0,
            "inspection_read_invocations_total": 0,
            "path_read_text_count": 0,
            "json_decode_count": 0, "json_decode_success_count": 0,
            "upload_state_read_invocations_total": 0,
            "history_job_rows_seen": 0,
            "history_recent_event_rows_seen": 0,
            "inspection_returned_job_rows": 0,
            "history_job_rows_last": 0, "history_recent_event_rows_last": 0,
        }
        self.phase = "setup"
        metric_names = (
            "upload_state_read", "upload_state_path_read", "upload_state_json_decode",
            "legacy_read", "mutation_read", "inspection_read", "upload_history_list",
            *STATE_WRITE_TIMINGS,
        )
        self.samples: dict[str, list[int]] = {
            f"{phase}_{metric}": []
            for phase in ("setup", "warmup", "measured", "teardown")
            for metric in metric_names
        }
        self.measured_thread_samples: dict[str, list[int]] = {
            f"{owner}_{metric}": []
            for owner in ("gui", "worker")
            for metric in metric_names
        }
        self.raw_events: list[dict[str, JsonValue]] = []
        self.dropped = 0
        self._active_path = threading.local()
        self._restore_actions: list[Callable[[], None]] = []
        self._installed = False

    def tracks(self, path: Path) -> bool:
        """Return whether a path is this child's private upload-state file.

        Args:
            path (Path): Candidate profile path supplied by a real owner.

        Returns:
            bool: True only for upload_state.json inside the private root.
        """
        if path.name != "upload_state.json":
            return False
        try:
            path.resolve().relative_to(self.private_root)
        except (OSError, ValueError):
            return False
        return True

    def _append(self, metric: str, elapsed_ns: int) -> None:
        """Retain one bounded duration sample without storing file contents.

        Args:
            metric (str): Fixed upload-state timing key.
            elapsed_ns (int): Nonnegative elapsed nanoseconds.

        Returns:
            None: The sample is retained or its overflow is counted.
        """
        elapsed = max(0, elapsed_ns)
        phase = self.phase if self.phase in ("setup", "warmup", "measured", "teardown") else "setup"
        values = self.samples[f"{phase}_{metric}"]
        if len(values) >= RAW_EVENT_LIMIT:
            self.dropped += 1
            return
        values.append(elapsed)
        current = threading.current_thread()
        gui = current is threading.main_thread()
        if phase == "measured":
            owner = "gui" if gui else "worker"
            self.measured_thread_samples[f"{owner}_{metric}"].append(elapsed)
        if len(self.raw_events) < RAW_EVENT_LIMIT:
            self.raw_events.append({
                "metric": metric, "phase": phase, "ns": elapsed,
                "thread_name": current.name,
                "thread_role": "tk-mainloop" if gui else "background-worker",
            })
        else:
            self.dropped += 1

    def _measure_read(
        self, label: str, path: Path, action: Callable[[], R],
    ) -> R:
        """Time one tracked store reader and expose its path to JSON probes.

        Args:
            label (str): Fixed read classification for this source owner.
            path (Path): State file path being read.
            action (Callable[[], R]): Original source operation.

        Returns:
            R: Exact value or exception behavior from the source operation.
        """
        previous = getattr(self._active_path, "value", None)
        self._active_path.value = str(path.resolve())
        started = time.perf_counter_ns()
        try:
            return action()
        finally:
            elapsed_ns = time.perf_counter_ns() - started
            self._append("upload_state_read", elapsed_ns)
            self._append(label, elapsed_ns)
            self.counters[label + "_invocations_total"] += 1
            self.counters["upload_state_read_invocations_total"] += 1
            if previous is None:
                del self._active_path.value
            else:
                self._active_path.value = previous

    def _record_history(self, document: JsonValue) -> None:
        """Aggregate row counts from a successfully decoded document only.

        Args:
            document (JsonValue): Decoded upload-state object from the owner.

        Returns:
            None: Only section cardinalities are retained; no values are emitted.
        """
        if not isinstance(document, dict):
            return
        jobs = document.get("jobs")
        events = document.get("recent_events")
        job_count = len(jobs) if isinstance(jobs, dict) else 0
        event_count = len(events) if isinstance(events, list) else 0
        self.counters["history_job_rows_last"] = job_count
        self.counters["history_recent_event_rows_last"] = event_count
        self.counters["history_job_rows_seen"] += job_count
        self.counters["history_recent_event_rows_seen"] += event_count

    def install(self) -> None:
        """Install scoped JSON and JobStateStore reader wrappers.

        Returns:
            None: Original functions remain available for exact restoration.
        """
        if self._installed:
            return
        module = self.state_module
        original_loads = module.json.loads

        def measured_loads(
            source: str | bytes | bytearray, **kwargs: JsonDecodeOption,
        ) -> JsonValue:
            """Measure JSON decoding only inside an active tracked state read.

            Args:
                source (str | bytes | bytearray): JSON text or encoded bytes.
                kwargs (JsonDecodeOption): Optional decoder class, hooks and
                    strictness forwarded unchanged to the JSON decoder.

            Returns:
                JsonValue: Exact decoded JSON value from the original decoder.
            """
            active_path = getattr(self._active_path, "value", None)
            if active_path is None:
                return cast(JsonValue, original_loads(source, **kwargs))
            started = time.perf_counter_ns()
            succeeded = False
            try:
                decoded = cast(JsonValue, original_loads(source, **kwargs))
                succeeded = True
                self._record_history(decoded)
                return decoded
            finally:
                self._append(
                    "upload_state_json_decode",
                    time.perf_counter_ns() - started,
                )
                self.counters["json_decode_count"] += 1
                if succeeded:
                    self.counters["json_decode_success_count"] += 1

        module.json.loads = measured_loads
        self._restore_actions.append(
            lambda: setattr(module.json, "loads", original_loads),
        )
        original_read_text = Path.read_text

        def measured_read_text(
            path: Path,
            encoding: str | None = None,
            errors: str | None = None,
            newline: str | None = None,
        ) -> str:
            """Time only Path.read_text calls for the exact tracked state path.

            Args:
                path (Path): File read by the source owner.
                encoding (str | None): File text encoding, or the host default.
                errors (str | None): Optional text decoder error handling policy.
                newline (str | None): Requested newline handling, or the default.

            Returns:
                str: Exact text returned by Path.read_text.
            """
            if not self.tracks(path):
                return original_read_text(path, encoding, errors, newline)
            started = time.perf_counter_ns()
            try:
                return original_read_text(path, encoding, errors, newline)
            finally:
                self._append(
                    "upload_state_path_read", time.perf_counter_ns() - started,
                )
                self.counters["path_read_text_count"] += 1

        Path.read_text = measured_read_text
        self._restore_actions.append(
            lambda: setattr(Path, "read_text", original_read_text),
        )
        original_legacy = module.load_upload_state

        def measured_legacy(path: Path, quarantine_bad: bool = True) -> JsonValue:
            """Measure the legacy reader and count history sections.

            Args:
                path (Path): State file admitted by the legacy reader.
                quarantine_bad (bool): Whether malformed state is quarantined.

            Returns:
                JsonValue: Exact normalized state returned by the original owner.
            """
            if not self.tracks(path):
                return cast(JsonValue, original_legacy(path, quarantine_bad))
            success_count = self.counters["json_decode_success_count"]
            result = cast(JsonValue, self._measure_read(
                "legacy_read", path,
                lambda: original_legacy(path, quarantine_bad),
            ))
            if success_count == self.counters["json_decode_success_count"]:
                self._record_history(result)
            return result

        module.load_upload_state = measured_legacy
        self._restore_actions.append(
            lambda: setattr(module, "load_upload_state", original_legacy),
        )
        original_mutation = module.load_upload_state_for_mutation

        def measured_mutation(path: Path) -> JsonValue:
            """Measure the safe mutation reader and count retained history.

            Args:
                path (Path): State file read before a mutation.

            Returns:
                JsonValue: Exact normalized history returned by the source.
            """
            if not self.tracks(path):
                return cast(JsonValue, original_mutation(path))
            success_count = self.counters["json_decode_success_count"]
            result = cast(JsonValue, self._measure_read(
                "mutation_read", path, lambda: original_mutation(path),
            ))
            if success_count == self.counters["json_decode_success_count"]:
                self._record_history(result)
            return result

        module.load_upload_state_for_mutation = measured_mutation
        self._restore_actions.append(
            lambda: setattr(module, "load_upload_state_for_mutation", original_mutation),
        )
        store_type = module.JobStateStore
        original_list_jobs = store_type.list_jobs

        def measured_list_jobs(store: StoreOwner) -> list[StoreJobRecord]:
            """Measure history loading through the store's actual list operation.

            Args:
                store (StoreOwner): Job state store with a private state path.

            Returns:
                list[StoreJobRecord]: Original ordered history records.
            """
            if not self.tracks(store.path):
                return original_list_jobs(store)
            started = time.perf_counter_ns()
            try:
                return original_list_jobs(store)
            finally:
                self._append("upload_history_list", time.perf_counter_ns() - started)

        store_type.list_jobs = measured_list_jobs
        self._restore_actions.append(
            lambda: setattr(store_type, "list_jobs", original_list_jobs),
        )
        original_bounded_history = getattr(store_type, "list_job_history", None)
        if original_bounded_history is not None:
            def measured_bounded_history(
                store: StoreOwner, limit: int,
            ) -> list[StoreJobRecord]:
                """Measure the newer bounded projection at the same history boundary.

                Args:
                    store (StoreOwner): Job store with a private captured profile.
                    limit (int): Requested newest visible job rows.

                Returns:
                    list[StoreJobRecord]: Ordered bounded history projections.
                """
                if not self.tracks(store.path):
                    return original_bounded_history(store, limit)
                started = time.perf_counter_ns()
                try:
                    return original_bounded_history(store, limit)
                finally:
                    self._append("upload_history_list", time.perf_counter_ns() - started)

            store_type.list_job_history = measured_bounded_history
            self._restore_actions.append(
                lambda: setattr(store_type, "list_job_history", original_bounded_history),
            )
        original_inspect = store_type.inspect_jobs_for_target_change

        def measured_inspect(store: StoreOwner) -> list[StoreJobRecord]:
            """Measure strict inspection reads without retaining job contents.

            Args:
                store (StoreOwner): JobStateStore instance whose path is inspected.

            Returns:
                list[StoreJobRecord]: Exact job rows returned by the source method.
            """
            operation = lambda: original_inspect(store)
            if not self.tracks(store.path):
                return operation()
            rows = self._measure_read(
                "inspection_read", store.path, operation,
            )
            self.counters["inspection_returned_job_rows"] += len(rows)
            return rows

        store_type.inspect_jobs_for_target_change = measured_inspect
        self._restore_actions.append(
            lambda: setattr(
                store_type, "inspect_jobs_for_target_change", original_inspect,
            ),
        )

        original_owner = module.accepted_work_owner

        @contextmanager
        def measured_accepted_work_owner(config_dir: Path) -> Iterator[None]:
            """Measure state-profile owner entry wait and held-context time.

            Args:
                config_dir (Path): Profile directory passed to the real lock owner.

            Returns:
                Iterator[None]: The original lock context and its exceptions are
                    preserved while entry and body-hold durations are sampled.
            """
            if not any(config_dir.is_relative_to(root) for root in self._profile_roots):
                with original_owner(config_dir):
                    yield
                return
            started = time.perf_counter_ns()
            entered = False
            try:
                with original_owner(config_dir):
                    hold_started = time.perf_counter_ns()
                    entered = True
                    self._append(
                        "accepted_work_owner_entry",
                        hold_started - started,
                    )
                    try:
                        yield
                    finally:
                        self._append(
                            "accepted_work_owner_hold",
                            time.perf_counter_ns() - hold_started,
                        )
            except BaseException:
                if not entered:
                    self._append(
                        "accepted_work_owner_entry",
                        time.perf_counter_ns() - started,
                    )
                raise

        module.accepted_work_owner = measured_accepted_work_owner
        self._restore_actions.append(
            lambda: setattr(module, "accepted_work_owner", original_owner),
        )

        original_atomic_write = module.atomic_write_json

        def measured_atomic_write_json(
            path: Path,
            payload: dict[str, JsonValue],
            *,
            require_sync: bool = False,
            compact: bool = False,
        ) -> None:
            """Time the real atomic write for this private upload-state file.

            Args:
                path (Path): Destination selected by the production store.
                payload (dict[str, JsonValue]): Complete state document passed
                    through without inspection or retention.
                require_sync (bool): Original durability requirement.
                compact (bool): Format requested by sources supporting compact JSON.

            Returns:
                None: The original writer result or exception is preserved.
            """
            # Older checkpoint sources do not define the optional format flag.
            options = {"require_sync": require_sync}
            if compact:
                options["compact"] = True
            if (path.name != "upload_state.json"
                    or not any(path.is_relative_to(root) for root in self._profile_roots)):
                original_atomic_write(path, payload, **options)
                return
            started = time.perf_counter_ns()
            try:
                original_atomic_write(path, payload, **options)
            finally:
                self._append(
                    "upload_state_atomic_write",
                    time.perf_counter_ns() - started,
                )

        module.atomic_write_json = measured_atomic_write_json
        self._restore_actions.append(
            lambda: setattr(module, "atomic_write_json", original_atomic_write),
        )
        self._installed = True

    def restore(self) -> None:
        """Restore every exact original function in reverse installation order.

        Returns:
            None: Process-global probes are removed after this child.
        """
        for restore_action in reversed(self._restore_actions):
            restore_action()
        self._restore_actions.clear()
        self._installed = False

    def metrics(self) -> dict[str, float | int]:
        """Return stable read/decode counters and aggregate duration summaries.

        Returns:
            dict[str, float | int]: Flat count, total, p50/p95/p99 and max metrics.
                Write timings omit percentile and maximum values without samples.
        """
        output: dict[str, float | int] = dict(self.counters)
        for sample_name, values_ns in self.samples.items():
            phase, name = sample_name.split("_", 1)
            ordered = sorted(values_ns)
            prefix = "" if phase == "measured" else phase + "_"
            output[prefix + name + "_count"] = len(ordered)
            output[prefix + name + "_total_ms"] = sum(ordered) / NS_PER_MS
            if not ordered and name in STATE_WRITE_TIMINGS:
                continue
            for label, fraction in DRIFT_PERCENTILES:
                index = max(0, min(
                    len(ordered) - 1, math.ceil(len(ordered) * fraction) - 1,
                ))
                output[prefix + name + "_" + label + "_ms"] = (
                    ordered[index] / NS_PER_MS if ordered else 0.0
                )
            output[prefix + name + "_max_ms"] = (
                ordered[-1] / NS_PER_MS if ordered else 0.0
            )
        output["upload_state_probe_dropped"] = self.dropped
        for name, samples in self.measured_thread_samples.items():
            output[name + "_count"] = len(samples)
            output[name + "_total_ms"] = sum(samples) / NS_PER_MS
        return output


MountWorkload: TypeAlias = Callable[
    [tk.Tk, Path, Path, str, int, int, tuple[int, int] | None, RecorderProbe],
    Workload,
]

SHELL_SCENARIOS = (
    "idle", "dashboard", "watcher", "watcher-burst", "tray", "activity",
    "import", "history", "mixed", "import-gui", "long-session",
)
MEDIA_SCENARIOS = (
    "photos", "lightbox", "lightbox-rapid", "mixed", "import-gui",
    "long-session",
)


class WorkloadAdapter:
    """Own mounted shell and media modules through one consistent lifecycle."""

    def __init__(self, modules: list[tuple[str, Workload]]) -> None:
        """Retain mounted workloads in deterministic shell/media order.

        Args:
            modules (list[tuple[str, Workload]]): Kind and mounted workload pairs;
                workloads expose ready, step, counters, and close callables.

        Returns:
            None: The adapter owns calls to every supplied workload.
        """
        self.modules = modules

    def ready(self) -> bool:
        """Return whether every mounted workload reached its ready state.

        Returns:
            bool: True only when each module reports readiness.
        """
        return all(module.ready() for _, module in self.modules)

    def step(self) -> None:
        """Deliver one scheduled production action to each mounted workload.

        Returns:
            None: Each module receives one action opportunity.
        """
        for _, module in self.modules:
            module.step()

    def counters(self) -> dict[str, CounterValue]:
        """Merge module counters under stable shell and media prefixes.

        Returns:
            dict[str, CounterValue]: Flat scalar counters named by their owner.
        """
        merged: dict[str, CounterValue] = {}
        for prefix, module in self.modules:
            for name, value in module.counters().items():
                merged[f"{prefix}_{name}"] = value
        return merged

    def stop_background_input(self) -> None:
        """Stop synthetic producers before sampling and tearing down the child.

        Returns:
            None: Mounted workloads with background input stop admitting it.
        """
        for _, module in self.modules:
            stop = getattr(module, "stop_background_input", None)
            if stop is not None:
                stop()

    def close(self) -> None:
        """Close mounted workloads in reverse ownership order.

        Returns:
            None: Every adapter gets its teardown opportunity.
        """
        for _, module in reversed(self.modules):
            module.close()


def load_module(path: Path, name: str) -> ModuleType:
    """Load one workload adapter directly from the supplied source checkout.

    Args:
        path (Path): Adapter source file.
        name (str): Private module name for this child process.

    Returns:
        ModuleType: Executed adapter module.

    Raises:
        RuntimeError: The adapter cannot be imported from its source path.
    """
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Cannot load workload adapter: {path.name}")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    """Parse the fixed child-process command line contract.

    Args:
        argv (list[str] | None): Optional arguments without executable name.

    Returns:
        argparse.Namespace: Validated workload identity and runtime settings.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True)
    parser.add_argument("--source-id", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--scenario", choices=sorted(set(SHELL_SCENARIOS + MEDIA_SCENARIOS)), required=True)
    parser.add_argument("--cache", choices=("cold", "warm"), required=True)
    parser.add_argument("--trial", type=int, required=True)
    parser.add_argument("--seed", type=int, required=True)
    parser.add_argument("--scale", type=int, required=True)
    parser.add_argument("--duration-ms", type=int, required=True)
    parser.add_argument("--interval-ms", type=int, required=True)
    parser.add_argument("--raster-width", type=int)
    parser.add_argument("--raster-height", type=int)
    parser.add_argument("--instrumentation", choices=("on", "off"), default="on")
    parser.add_argument("--profile", action="store_true")
    args = parser.parse_args(argv)
    if not Path(args.source).is_absolute() or not Path(args.source).is_dir():
        parser.error("--source must be an absolute application root directory")
    if not Path(args.output).is_absolute():
        parser.error("--output must be an absolute path")
    if args.trial < 0 or args.seed < 0 or args.scale < 1:
        parser.error("trial and seed must be nonnegative; scale must be positive")
    if args.duration_ms < 1 or args.interval_ms < 1:
        parser.error("duration and interval must be positive milliseconds")
    if (args.raster_width is None) != (args.raster_height is None):
        parser.error("raster width and height must be supplied together")
    if args.raster_width is not None and min(args.raster_width, args.raster_height) < 1:
        parser.error("raster dimensions must be positive")
    return args


def run(args: argparse.Namespace) -> dict[str, JsonValue]:
    """Run a real Tk mainloop and return one identity-bound evidence record.

    Args:
        args (argparse.Namespace): Validated child command-line settings.

    Returns:
        dict[str, JsonValue]: Schema version 1 metrics, operations, metadata and failures.
    """
    source = Path(args.source).resolve()
    private = tempfile.TemporaryDirectory(prefix="issue-434-tk-")
    private_root = Path(private.name)
    profile_names = ("APPDATA", "LOCALAPPDATA", "USERPROFILE")
    saved_profile = {key: os.environ.get(key) for key in profile_names}
    for key in profile_names:
        profile = private_root / "profile" / key.lower()
        profile.mkdir(parents=True, exist_ok=True)
        os.environ[key] = str(profile)

    status = "FAIL"
    failure: str | None = None
    callback_errors: list[str] = []
    raw_events: list[dict[str, JsonValue]] = []
    missing_metrics: list[str] = []
    metrics: dict[str, float | int] = {}
    operations: dict[str, CounterValue] = {}
    starting_counters: dict[str, CounterValue] = {}
    metadata: dict[str, JsonValue] = {
        "python": platform.python_version(), "platform": sys.platform,
        "cache_start_state": "empty-process" if args.cache == "cold" else "three-step-warmup",
        "fixture_identity": f"issue-434:{args.scenario}:{args.seed}:{args.scale}",
    }
    root: tk.Tk | None = None
    workload: WorkloadAdapter | None = None
    mounted: list[tuple[str, Workload]] = []
    recorder: RecorderProbe | None = None
    recorder_module: ModuleType | None = None
    state_probe: StateStoreProbe | None = None
    presentation = PresentationProbe() if args.instrumentation == "on" else None
    shell_module: ModuleType | None = None
    media_module: ModuleType | None = None
    watchdog: threading.Timer | None = None
    partial_errors: list[str] = []
    resource_samples: list[dict[str, int]] = []
    raw_dropped = [0]
    started_ns = time.perf_counter_ns()
    measured_started = [0]
    measured_end = [0]
    count = [0]
    cpu_start = [0]
    cpu_end = [0]
    warmup_remaining = [WARMUP_STEPS]
    profiler: cProfile.Profile | None = None

    def on_tk_exception(
        exception_type: type[BaseException], error: BaseException,
        tb: TracebackType | None,
    ) -> None:
        """Retain bounded Tk callback failures and stop the measured loop.

        Args:
            exception_type (type[BaseException]): Raised exception class.
            error (BaseException): Exception raised by a Tk callback.
            tb (TracebackType | None): Traceback provided by Tk's exception hook.

        Returns:
            None: The error is retained and the root loop is stopped.
        """
        callback_errors.append(
            f"{exception_type.__name__}: {str(error)[:FAILURE_TEXT_LIMIT]}",
        )
        metadata["callback_traceback"] = "".join(
            traceback.format_exception(exception_type, error, tb),
        )[-FAILURE_TRACEBACK_LIMIT:]
        if root is not None:
            root.quit()

    def finish() -> None:
        """Stop timing and quit after the requested measured duration.

        Returns:
            None: The final operation timestamp is captured before teardown.
        """
        measured_end[0] = time.perf_counter_ns()
        if workload is not None:
            workload.stop_background_input()
        if root is not None:
            root.quit()

    def deliver() -> None:
        """Deliver a single action and schedule the next measured interval.

        Returns:
            None: One real adapter step is recorded per timer dispatch.
        """
        if workload is None or root is None:
            return
        now = time.perf_counter_ns()
        if now - measured_started[0] >= args.duration_ms * NS_PER_MS:
            finish()
            return
        action_start = now
        workload.step()
        completed = time.perf_counter_ns()
        count[0] += 1
        if args.instrumentation == "on":
            if len(raw_events) < RAW_EVENT_LIMIT:
                raw_events.append({
                    "metric": "workload_step", "phase": "measured",
                    "ns": completed - action_start, "index": count[0],
                })
            else:
                raw_dropped[0] += 1
        root.after(args.interval_ms, deliver)

    def sample_resources() -> None:
        """Sample working set, thread count and scheduled Tk callbacks.

        Returns:
            None: One bounded sample is kept and the next sample is scheduled.
        """
        if root is None or measured_started[0] == 0:
            return
        try:
            if psutil is None:
                raise ImportError("Optional psutil is unavailable")
            process = psutil.Process()
            pending = root.tk.call("after", "info")
            if len(resource_samples) < RAW_EVENT_LIMIT:
                resource_samples.append({
                    "elapsed_ms": int((time.perf_counter_ns() - measured_started[0]) / NS_PER_MS),
                    "rss_bytes": int(process.memory_info().rss),
                    "thread_count": int(process.num_threads()),
                    "scheduled_callbacks": len(pending),
                })
            else:
                raw_dropped[0] += 1
        except (ImportError, AttributeError, OSError, tk.TclError):
            missing_metrics.append("periodic_process_resources")
        if measured_started[0] and not measured_end[0]:
            root.after(RESOURCE_SAMPLE_MS, sample_resources)

    def perform_warmup() -> None:
        """Deliver untimed warmup actions and wait for their background work.

        Returns:
            None: The measured phase begins after warmup and its settle interval.
        """
        if workload is None or root is None:
            return
        if state_probe is not None:
            state_probe.phase = "warmup"
        if warmup_remaining[0] > 0:
            workload.step()
            warmup_remaining[0] -= 1
            root.after(args.interval_ms, perform_warmup)
            return
        root.after(WARMUP_SETTLE_MS, begin_measurement)

    def hard_watchdog() -> None:
        """Write a bounded timeout record if setup or native Tk hangs.

        Returns:
            None: The child exits after persisting BLOCKED identity evidence.
        """
        fallback = {
            "schema_version": 1, "status": "BLOCKED",
            "source": args.source_id, "scenario": args.scenario,
            "cache": args.cache, "trial": args.trial, "seed": args.seed,
            "scale": args.scale,
            "raster_size": ([args.raster_width, args.raster_height]
                            if args.raster_width is not None else None),
            "duration_ms": args.duration_ms, "interval_ms": args.interval_ms,
            "instrumentation": args.instrumentation,
            "metrics": {}, "operations": {}, "metadata": metadata,
            "raw_events_truncated": (
                raw_dropped[0] + (recorder.truncated if recorder is not None else 0)
            ),
            "raw_events": raw_events, "missing_metrics": [
                "scenario_elapsed_ms", "event_loop_drift_p95_ms",
                "process_cpu_ms", "process_peak_rss_bytes",
            ],
            "failure": "Tk workload lifecycle watchdog expired.",
        }
        target = Path(args.output)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(fallback, indent=2) + "\n", encoding="utf-8")
        os._exit(1)

    try:
        # Bind the isolated profile before loading the scenario adapters or
        # launching the application. Module imports do not open profile state.
        shell_needed = args.scenario in SHELL_SCENARIOS
        media_needed = args.scenario in MEDIA_SCENARIOS
        harness_root = Path(__file__).resolve().parent
        harness_path = str(harness_root)
        if harness_path not in sys.path:
            sys.path.insert(0, harness_path)
        watchdog = threading.Timer(
            (args.duration_ms + LIFECYCLE_BUDGET_MS) / 1000,
            hard_watchdog,
        )
        watchdog.daemon = True
        watchdog.start()
        winapp_path = str(source / "winapp")
        if winapp_path not in sys.path:
            sys.path.insert(0, winapp_path)
        state_module = importlib.import_module("uploader.state_store")
        state_probe = StateStoreProbe(state_module, private_root)
        state_probe.install()
        shell_module = load_module(
            harness_root / "issue_434_tk_shell.py",
            "issue_434_shell_child",
        ) if shell_needed else None
        media_module = load_module(
            harness_root / "issue_434_tk_media.py",
            "issue_434_media_child",
        ) if media_needed else None
        prepare_windows_display()
        root = tk.Tk()
        root.title(f"Issue 434: {args.scenario}")
        root.geometry(f"{VIEWPORT_SIZE[0]}x{VIEWPORT_SIZE[1]}")
        root.report_callback_exception = on_tk_exception
        recorder_module = load_module(
            harness_root / "issue_361_tk_workloads.py",
            "issue_361_recorder_child",
        )
        recorder_module.RAW_EVENT_LIMIT = PHASE2_RAW_EVENT_LIMIT
        recorder = recorder_module.Recorder(args.instrumentation == "on")
        recorder.install()
        if presentation is not None:
            presentation.install()
        raster = ((args.raster_width, args.raster_height)
                  if args.raster_width is not None else None)
        if shell_module is not None:
            mounted.append(("shell", shell_module.mount(
                root, source, private_root, args.scenario, args.seed,
                args.scale, raster, recorder,
            )))
        if media_module is not None:
            mounted.append(("media", media_module.mount(
                root, source, private_root, args.scenario, args.seed,
                args.scale, raster, recorder,
            )))
        workload = WorkloadAdapter(mounted)
        metadata.update({
            "tcl": str(root.tk.call("info", "patchlevel")),
            "tk": str(root.tk.call("set", "tk_patchLevel")),
            "tk_windowing_system": str(root.tk.call("tk", "windowingsystem")),
            "display": {
                "width": root.winfo_screenwidth(),
                "height": root.winfo_screenheight(),
                "dpi": root.winfo_fpixels("1i"),
            },
            "virtual_dimensions": root.winfo_screenwidth(),
            "virtual_dimensions_height": root.winfo_screenheight(),
            "adapter_modules": [
                {"kind": kind, "module": module.__name__}
                for kind, module in (("shell", shell_module), ("media", media_module))
                if module is not None
            ],
        })

        def begin_when_ready() -> None:
            """Wait for adapters, then start warmup and measured UI delivery.

            Returns:
                None: Timed events begin only after adapter readiness.
            """
            nonlocal profiler
            if not workload.ready():
                root.after(READY_POLL_MS, begin_when_ready)
                return
            if args.profile:
                profiler = cProfile.Profile()
                profiler.enable()
            root.after(0, perform_warmup)

        def begin_measurement() -> None:
            """Start measurement after one interval of mapped-window warmup.

            Returns:
                None: Recorder phase and duration timers begin together.
            """
            if profiler is not None:
                profiler.disable()
                profile_path = Path(args.output).with_suffix(".profile")
                profiler.dump_stats(str(profile_path))
                metadata["profile_path"] = str(profile_path)
            if workload is not None:
                starting_counters.update(workload.counters())
            if state_probe is not None:
                state_probe.phase = "measured"
            if presentation is not None:
                presentation.begin_measurement()
            recorder.phase = "measured"
            measured_started[0] = time.perf_counter_ns()
            cpu_start[0] = time.process_time_ns()
            recorder.start_heartbeat(root)
            root.after(RESOURCE_SAMPLE_MS, sample_resources)
            root.after(0, deliver)
            root.after(args.duration_ms, finish)

        root.after(0, begin_when_ready)
        root.mainloop()
        cpu_end[0] = time.process_time_ns()
        if presentation is not None:
            presentation.end_measurement()
            metrics.update(presentation.metrics())
        if state_probe is not None:
            state_probe.phase = "teardown"
        recorder.stop_heartbeat()
        recorder.phase = "warmup"
        if workload is not None:
            operations.update(workload.counters())
        operations["workload_steps"] = count[0]
        operations.update(recorder.operation_counts)
        if measured_started[0]:
            end = measured_end[0] or time.perf_counter_ns()
            metrics["scenario_elapsed_ms"] = round(
                (end - measured_started[0]) / NS_PER_MS, 3,
            )
            if args.scenario in ("import", "import-gui", "long-session"):
                key = "shell_confirmed_import_items"
                first = starting_counters.get(key)
                last = operations.get(key)
                if type(first) is int and type(last) is int and metrics["scenario_elapsed_ms"]:
                    # Convert the measured wall window from milliseconds to seconds.
                    metrics["import_items_per_second"] = (
                        (last - first) * 1000 / metrics["scenario_elapsed_ms"]
                    )
            if args.scenario in ("watcher", "watcher-burst", "mixed", "tray", "long-session"):
                # Both durations and rates use measured-window counters.
                for key, rate in (
                    ("shell_events_produced", "watcher_input_events_per_second"),
                    ("shell_events_drained", "watcher_events_per_second"),
                ):
                    first = starting_counters.get(key)
                    last = operations.get(key)
                    if type(first) is int and type(last) is int and metrics["scenario_elapsed_ms"]:
                        metrics[rate] = (last - first) * 1000 / metrics["scenario_elapsed_ms"]
            if args.instrumentation == "on":
                metrics["process_cpu_ms"] = round(
                    (cpu_end[0] - cpu_start[0]) / 1_000_000, 3,
                )
                measured_wall_ms = max(1, end - measured_started[0]) / NS_PER_MS
                metrics["process_cpu_fraction_of_one_core"] = round(
                    metrics["process_cpu_ms"] / measured_wall_ms, 6,
                )
                samples = sorted(int(event["ns"]) for event in raw_events)
                metrics["workload_step_count"] = len(samples)
                metrics["workload_step_total_ms"] = round(sum(samples) / NS_PER_MS, 3)
                for label, fraction in DRIFT_PERCENTILES:
                    if samples:
                        index = max(0, min(len(samples) - 1,
                                           math.ceil(len(samples) * fraction) - 1))
                        metrics[f"workload_step_{label}_ms"] = round(
                            samples[index] / NS_PER_MS, 6,
                        )
                if samples:
                    metrics["workload_step_max_ms"] = round(
                        samples[-1] / NS_PER_MS, 6,
                    )
                for threshold_ms in STALL_THRESHOLDS_MS:
                    metric_key = f"workload_step_over_{str(threshold_ms).replace('.', '_')}ms_count"
                    metrics[metric_key] = sum(
                        1 for sample in samples
                        if sample >= threshold_ms * NS_PER_MS
                    )
                drift = sorted(
                    int(event["ns"]) for event in recorder.events
                    if event.get("metric") == "event_loop_drift"
                    and event.get("phase") == "measured"
                )
                if drift:
                    for label, fraction in DRIFT_PERCENTILES:
                        index = max(0, min(len(drift) - 1,
                                           math.ceil(len(drift) * fraction) - 1))
                        metrics[f"event_loop_drift_{label}_ms"] = round(
                            drift[index] / NS_PER_MS, 6,
                        )
                    metrics["event_loop_drift_max_ms"] = round(
                        drift[-1] / NS_PER_MS, 6,
                    )
                else:
                    missing_metrics.extend((
                        "event_loop_drift_p50_ms", "event_loop_drift_p95_ms",
                        "event_loop_drift_p99_ms", "event_loop_drift_max_ms",
                    ))
                raw_events.extend(dict(event) for event in recorder.events)
                for event in raw_events:
                    if "thread_role" not in event:
                        metric = str(event.get("metric", ""))
                        event["thread_role"] = (
                            "background-worker" if metric.endswith("_worker")
                            else "tk-mainloop"
                        )
                recorder_names = sorted({
                    str(event.get("metric")) for event in recorder.events
                    if event.get("phase") == "measured"
                })
                for metric_name in recorder_names:
                    observations = sorted(
                        int(event["ns"]) for event in recorder.events
                        if event.get("metric") == metric_name
                        and event.get("phase") == "measured"
                    )
                    safe_name = metric_name.replace(":", "_").replace(".", "_")
                    metrics[f"{safe_name}_count"] = len(observations)
                    metrics[f"{safe_name}_total_ms"] = round(
                        sum(observations) / NS_PER_MS, 6,
                    )
                    for label, fraction in DRIFT_PERCENTILES:
                        if observations:
                            index = max(0, min(len(observations) - 1,
                                               math.ceil(len(observations) * fraction) - 1))
                            metrics[f"{safe_name}_{label}_ms"] = round(
                                observations[index] / NS_PER_MS, 6,
                            )
                    if observations:
                        metrics[f"{safe_name}_max_ms"] = round(
                            observations[-1] / NS_PER_MS, 6,
                        )
                for key, value in recorder.operation_counts.items():
                    metrics[f"{key}_count"] = value
            else:
                missing_metrics.extend((
                    "process_cpu_ms", "workload_step_p95_ms",
                    "event_loop_drift_p50_ms", "event_loop_drift_p95_ms",
                    "event_loop_drift_p99_ms", "event_loop_drift_max_ms",
                ))
        else:
            missing_metrics.extend(("scenario_elapsed_ms", "process_cpu_ms"))
        status = "PASS" if measured_end[0] and not callback_errors else "FAIL"
        if callback_errors:
            status = "FAIL"
            failure = callback_errors[0]
        try:
            if pillow_version is None:
                raise ImportError("Optional Pillow is unavailable")
            metadata["pillow"] = pillow_version
        except ImportError:
            metadata["pillow"] = None
            missing_metrics.append("pillow_version")
        try:
            if psutil is None:
                raise ImportError("Optional psutil is unavailable")
            memory = psutil.Process().memory_info()
            peak_rss = getattr(memory, "peak_wset", None)
            if peak_rss is None:
                missing_metrics.append("process_peak_rss_bytes")
            else:
                metrics["process_peak_rss_bytes"] = int(peak_rss)
            metrics["process_rss_end_bytes"] = int(memory.rss)
            metrics["process_rss_sample_peak_bytes"] = max(
                (sample["rss_bytes"] for sample in resource_samples),
                default=int(memory.rss),
            )
            ordered_rss = sorted(sample["rss_bytes"] for sample in resource_samples)
            middle = len(ordered_rss) // 2
            metrics["process_steady_rss_bytes"] = (
                ordered_rss[middle] if ordered_rss else int(memory.rss)
            )
            metrics["process_thread_sample_peak"] = max(
                (sample["thread_count"] for sample in resource_samples), default=1,
            )
            metrics["tk_scheduled_callbacks_sample_peak"] = max(
                (sample["scheduled_callbacks"] for sample in resource_samples),
                default=0,
            )
            metadata["psutil"] = psutil.__version__
        except (ImportError, AttributeError, OSError):
            missing_metrics.append("process_peak_rss_bytes")
        metadata["resource_samples"] = resource_samples
        metadata["raw_events_dropped"] = raw_dropped[0]
        metadata["raw_events_truncated"] = recorder.truncated
        metadata["warmup_steps"] = WARMUP_STEPS
        metadata["warmup_settle_ms"] = WARMUP_SETTLE_MS
        metadata["unmeasured_signals"] = [
            "state_store_read_ms and state_store_write_ms: store owners do not "
            "expose a shared instrumentation hook yet",
            "native_presentation_ms: Tk dispatch does not prove physical display",
            "worker_thread_names: Recorder distinguishes worker versus GUI resize "
            "paths, but does not preserve concrete thread names",
        ]
        metadata["cache_priming_semantics"] = (
            "three untimed adapter step calls in this process; cache residency "
            "is adapter-owned and is not asserted by the child"
        )
        metadata["recorder_raw_event_limit"] = PHASE2_RAW_EVENT_LIMIT
        metadata["process_cpu_fraction_semantics"] = (
            "measured process CPU seconds divided by measured wall seconds; "
            "values above one are possible with multiple active threads"
        )
        if raw_dropped[0] or recorder.truncated:
            missing_metrics.append("raw_events_complete")
        if recorder.truncated:
            status = "FAIL"
            failure = (
                f"Phase-2 Recorder capacity exceeded by {recorder.truncated} samples; "
                "increase PHASE2_RAW_EVENT_LIMIT before qualification."
            )
        if raw_dropped[0]:
            status = "FAIL"
            failure = f"Child raw-event capacity exceeded by {raw_dropped[0]} samples."
        if args.profile and root is not None:
            try:
                if ImageGrab is None:
                    raise RuntimeError("Optional Pillow capture is unavailable")
                left = root.winfo_rootx()
                top = root.winfo_rooty()
                right = left + root.winfo_width()
                bottom = top + root.winfo_height()
                screenshot_path = Path(args.output).with_suffix(".png")
                ImageGrab.grab(bbox=(left, top, right, bottom)).save(screenshot_path)
                metadata["screenshot_path"] = str(screenshot_path)
            except (OSError, RuntimeError, tk.TclError):
                metadata["screenshot_path"] = "unavailable"
        recorder.restore()
    except Exception as error:
        status = "BLOCKED" if root is None else "FAIL"
        failure = f"{type(error).__name__}: {str(error)[:FAILURE_TEXT_LIMIT]}"
        metadata["failure_traceback"] = traceback.format_exc()[-FAILURE_TRACEBACK_LIMIT:]
    finally:
        if watchdog is not None:
            watchdog.cancel()
        if presentation is not None:
            presentation.restore()
        if recorder is not None:
            try:
                recorder.stop_heartbeat()
                recorder.restore()
            except Exception as error:
                partial_errors.append(
                    f"recorder {type(error).__name__}: {str(error)[:FAILURE_TEXT_LIMIT]}",
                )
        for _, partial_workload in reversed(mounted):
            try:
                partial_workload.close()
            except Exception as error:
                partial_errors.append(
                    f"workload {type(error).__name__}: {str(error)[:FAILURE_TEXT_LIMIT]}",
                )
        if root is not None:
            try:
                root.destroy()
            except tk.TclError:
                pass
        if state_probe is not None:
            state_probe.phase = "teardown"
            try:
                state_probe.restore()
            except Exception as error:
                partial_errors.append(
                    f"state-store probe {type(error).__name__}: "
                    f"{str(error)[:FAILURE_TEXT_LIMIT]}",
                )
        for key, value in saved_profile.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value
        try:
            private.cleanup()
        except OSError as error:
            partial_errors.append(
                f"private profile cleanup {type(error).__name__}: "
                f"{str(error)[:FAILURE_TEXT_LIMIT]}",
            )
        if partial_errors:
            status = "FAIL"
            metadata["teardown_failures"] = list(partial_errors)
            if failure is None:
                failure = " ".join(partial_errors)[:FAILURE_TEXT_LIMIT]
    if state_probe is not None:
        metrics.update(state_probe.metrics())
        raw_events.extend(state_probe.raw_events)
        if state_probe.dropped:
            status = "FAIL"
            failure = (
                f"State-store probe overflowed by {state_probe.dropped} samples; "
                "increase the independent phase-2 sample capacity before qualification."
            )
            missing_metrics.append("state_store_probe_complete")
    return {
        "schema_version": 1, "status": status, "source": args.source_id,
        "scenario": args.scenario, "cache": args.cache, "trial": args.trial,
        "seed": args.seed, "scale": args.scale,
        "raster_size": ([args.raster_width, args.raster_height]
                        if args.raster_width is not None else None),
        "duration_ms": args.duration_ms, "interval_ms": args.interval_ms,
        "instrumentation": args.instrumentation, "metrics": metrics,
        "operations": operations, "metadata": metadata,
        "raw_events": raw_events, "missing_metrics": sorted(set(missing_metrics)),
        "raw_events_truncated": (
            raw_dropped[0] + (recorder.truncated if recorder else 0)
            + (state_probe.dropped if state_probe else 0)
        ),
        "failure": failure,
    }


def main(argv: list[str] | None = None) -> int:
    """Run one child workload and persist its JSON record.

    Args:
        argv (list[str] | None): Optional command line without executable name.

    Returns:
        int: Zero when the scenario completed successfully, otherwise one.
    """
    args = parse_args(argv)
    target = Path(args.output)
    target.parent.mkdir(parents=True, exist_ok=True)
    result = run(args)
    target.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(f"{result['status']} {args.scenario} trial={args.trial}")
    return 0 if result["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
