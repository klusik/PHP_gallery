# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_tk_performance_recorder.py
# Module Type: Windows Native UI Regression Tests
# Purpose: Protect bounded timing collection and independent Tk heartbeat behavior.
# Responsibilities:
#   - Verify warmup and callback rows preserve measured evidence within the cap.
#   - Verify callback forwarding, exceptions and phase fences remain intact.
#   - Verify heartbeat deadlines and disabled instrumentation remain safe.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Headless regression checks for the P0 Tk workload recorder."""

import importlib.util
import sys
import unittest
from pathlib import Path
from types import ModuleType, SimpleNamespace
from unittest import mock


# Purpose: Keep the warmup fixture substantially larger than its test-only cap.
# Type: int. Units: timing samples. Scope: one Recorder regression test.
# Consumers: test_warmup_samples_do_not_consume_measured_raw_capacity.
# Rationale: One hundred excluded samples reproduce the former warmup overflow.
WARMUP_SAMPLE_COUNT = 100
# Purpose: Make raw-event boundary behavior cheap and exact in this test.
# Type: int. Units: timing samples. Scope: patched Recorder raw-event policy.
# Consumers: test_warmup_samples_do_not_consume_measured_raw_capacity.
# Rationale: Three samples expose both the retained boundary and first overflow.
RAW_LIMIT_TEST_CAP = 3
# Purpose: Supply a positive elapsed value without testing duration statistics.
# Type: int. Units: nanoseconds. Scope: capacity and disabled-mode fixtures.
# Consumers: raw-capacity and disabled-recorder tests.
# Rationale: One nanosecond keeps the fixture focused on admission rather than timing.
TEST_EVENT_DURATION_NS = 1
# Purpose: Model one delayed heartbeat dispatch after its expected deadline.
# Type: int. Units: nanoseconds. Scope: controlled heartbeat test clock.
# Consumers: test_heartbeat_uses_original_scheduler_and_reanchors_deadline.
# Rationale: Seven milliseconds is a visible deterministic lateness value.
HEARTBEAT_LATENESS_NS = 7_000_000
# Purpose: Provide a monotonic starting point independent of wall-clock time.
# Type: int. Units: nanoseconds. Scope: controlled heartbeat test clock.
# Consumers: test_heartbeat_uses_original_scheduler_and_reanchors_deadline.
# Rationale: A small positive origin makes deadline arithmetic explicit.
HEARTBEAT_START_NS = 100
# Purpose: Place the next heartbeat just after the prior dispatch.
# Type: int. Units: nanoseconds. Scope: controlled heartbeat test clock.
# Consumers: test_heartbeat_uses_original_scheduler_and_reanchors_deadline.
# Rationale: One hundred nanoseconds proves the deadline reanchors to dispatch.
HEARTBEAT_REANCHOR_OFFSET_NS = 100

# Purpose: Model a Tk timer delay in the callback forwarding fixture.
# Type: int. Units: milliseconds. Scope: one synthetic after callback.
# Consumers: combined callback timing tests.
# Rationale: Five milliseconds permits an exact dispatch-delay calculation.
CALLBACK_TIMER_MS = 5
# Purpose: Anchor the deterministic callback clock.
# Type: int. Units: nanoseconds. Scope: controlled Recorder callback tests.
# Consumers: combined timing, exception and phase-crossing tests.
# Rationale: One billion nanoseconds makes the fixed timer offset explicit.
CALLBACK_SCHEDULED_NS = 1_000_000_000
# Purpose: Model lateness beyond the requested timer delay.
# Type: int. Units: nanoseconds. Scope: controlled Recorder callback tests.
# Consumers: combined callback-row assertion.
# Rationale: 250,000 ns is retained in the dispatch_delay_ns field.
CALLBACK_DISPATCH_DELAY_NS = 250_000
# Purpose: Model deterministic callback execution duration.
# Type: int. Units: nanoseconds. Scope: controlled Recorder callback tests.
# Consumers: combined callback-row assertions.
# Rationale: 800,000 ns distinguishes elapsed callback time from dispatch delay.
CALLBACK_ELAPSED_NS = 800_000
# Purpose: Convert the millisecond timer policy into perf-counter units.
# Type: int. Units: nanoseconds per millisecond. Scope: callback clock fixtures.
# Consumers: combined callback timing tests.
# Rationale: The SI conversion is exactly one million nanoseconds.
TEST_NS_PER_MS = 1_000_000

# Structural path: this file is under winapp/tests, two parents below the repository.
REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
WORKLOAD_PATH = (
    REPOSITORY_ROOT / "tools" / "perf" / "issue_361_tk_workloads.py"
)
WORKLOAD_MODULE_NAME = "_issue_361_tk_workloads_test"


def load_workload_module() -> ModuleType:
    """Load the benchmark module with its stable name registered first.

    Returns:
        ModuleType: The actual P0 workload module with production Recorder code.

    Raises:
        ImportError: The workload file is missing or cannot provide a loader.
    """
    spec = importlib.util.spec_from_file_location(
        WORKLOAD_MODULE_NAME,
        WORKLOAD_PATH,
    )
    if spec is None or spec.loader is None:
        raise ImportError(f"Cannot load workload module at {WORKLOAD_PATH}.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


WORKLOAD = load_workload_module()


class TkPerformanceRecorderTests(unittest.TestCase):
    """Exercise Recorder boundaries without creating a Tk interpreter."""

    def test_warmup_samples_do_not_consume_measured_raw_capacity(self) -> None:
        """Ignore warmup and stale completions while retaining measured overflow.

        Returns:
            None: Only measured-phase samples occupy the bounded raw-event list.
        """
        recorder = WORKLOAD.Recorder(enabled=True)
        with mock.patch.object(
            WORKLOAD,
            "RAW_EVENT_LIMIT",
            RAW_LIMIT_TEST_CAP,
        ):
            for sample_index in range(WARMUP_SAMPLE_COUNT):
                recorder.add("callback", TEST_EVENT_DURATION_NS, "warmup")
            self.assertEqual([], recorder.events)
            self.assertEqual(0, recorder.truncated)

            recorder.phase = "measured"
            for sample_index in range(RAW_LIMIT_TEST_CAP):
                recorder.add("callback", TEST_EVENT_DURATION_NS)
            recorder.add("callback", TEST_EVENT_DURATION_NS)
            # A warmup callback completion can arrive after measurement starts.
            recorder.add("callback", TEST_EVENT_DURATION_NS, "warmup")

        self.assertEqual(RAW_LIMIT_TEST_CAP, len(recorder.events))
        self.assertEqual(1, recorder.truncated)
        self.assertTrue(all(
            event["phase"] == "measured" for event in recorder.events
        ))

    def test_after_callback_records_one_complete_row_and_forwards_arguments(self) -> None:
        """Preserve exact callback arguments and consolidate timing details.

        Returns:
            None: One event contains callback duration, name and dispatch delay.
        """
        root = mock.Mock()
        scheduler = mock.Mock(return_value="timer-id")
        with mock.patch.object(WORKLOAD.tk.Misc, "after", scheduler):
            recorder = WORKLOAD.Recorder(enabled=True)
            recorder.install()
            try:
                recorder.phase = "measured"
                timer_ns = CALLBACK_TIMER_MS * TEST_NS_PER_MS
                started_ns = (
                    CALLBACK_SCHEDULED_NS + timer_ns
                    + CALLBACK_DISPATCH_DELAY_NS
                )
                clock = mock.Mock(side_effect=(
                    CALLBACK_SCHEDULED_NS,
                    started_ns,
                    started_ns + CALLBACK_ELAPSED_NS,
                ))
                with mock.patch.object(
                    WORKLOAD,
                    "time",
                    SimpleNamespace(perf_counter_ns=clock),
                ):
                    callback_id = WORKLOAD.tk.Misc.after(
                        root,
                        CALLBACK_TIMER_MS,
                        str.join,
                        "|",
                        ("left", "right"),
                    )
                    self.assertEqual("timer-id", callback_id)
                    callback = scheduler.call_args.args[2]
                    forwarded_args = scheduler.call_args.args[3:]
                    self.assertEqual(("|", ("left", "right")), forwarded_args)
                    result = callback(*forwarded_args)

                self.assertEqual("left|right", result)
                self.assertEqual([{
                    "metric": "callback",
                    "ns": CALLBACK_ELAPSED_NS,
                    "phase": "measured",
                    "callback_name": "str.join",
                    "dispatch_delay_ns": CALLBACK_DISPATCH_DELAY_NS,
                }], recorder.events)
                self.assertEqual(0, recorder.truncated)
            finally:
                recorder.restore()

    def test_after_callback_exception_is_recorded_and_propagated(self) -> None:
        """Retain callback timing while propagating the original failure.

        Returns:
            None: The built-in conversion error escapes with one complete event.
        """
        root = mock.Mock()
        scheduler = mock.Mock(return_value="timer-id")
        with mock.patch.object(WORKLOAD.tk.Misc, "after", scheduler):
            recorder = WORKLOAD.Recorder(enabled=True)
            recorder.install()
            try:
                recorder.phase = "measured"
                timer_ns = CALLBACK_TIMER_MS * TEST_NS_PER_MS
                started_ns = (
                    CALLBACK_SCHEDULED_NS + timer_ns
                    + CALLBACK_DISPATCH_DELAY_NS
                )
                clock = mock.Mock(side_effect=(
                    CALLBACK_SCHEDULED_NS,
                    started_ns,
                    started_ns + CALLBACK_ELAPSED_NS,
                ))
                with mock.patch.object(
                    WORKLOAD,
                    "time",
                    SimpleNamespace(perf_counter_ns=clock),
                ):
                    WORKLOAD.tk.Misc.after(
                        root,
                        CALLBACK_TIMER_MS,
                        int,
                        "not-an-integer",
                    )
                    callback = scheduler.call_args.args[2]
                    forwarded_args = scheduler.call_args.args[3:]
                    self.assertEqual(("not-an-integer",), forwarded_args)
                    with self.assertRaises(ValueError):
                        callback(*forwarded_args)

                self.assertEqual([{
                    "metric": "callback",
                    "ns": CALLBACK_ELAPSED_NS,
                    "phase": "measured",
                    "callback_name": "int",
                    "dispatch_delay_ns": CALLBACK_DISPATCH_DELAY_NS,
                }], recorder.events)
                self.assertEqual(0, recorder.truncated)
            finally:
                recorder.restore()

    def test_after_callback_phase_crossing_is_excluded(self) -> None:
        """Exclude a warmup callback that completes during measurement.

        Returns:
            None: A callback scheduled in warmup adds no measured raw record.
        """
        root = mock.Mock()
        scheduler = mock.Mock(return_value="timer-id")
        with mock.patch.object(WORKLOAD.tk.Misc, "after", scheduler):
            recorder = WORKLOAD.Recorder(enabled=True)
            recorder.install()
            try:
                timer_ns = CALLBACK_TIMER_MS * TEST_NS_PER_MS
                started_ns = (
                    CALLBACK_SCHEDULED_NS + timer_ns
                    + CALLBACK_DISPATCH_DELAY_NS
                )
                clock = mock.Mock(side_effect=(
                    CALLBACK_SCHEDULED_NS,
                    started_ns,
                    started_ns + CALLBACK_ELAPSED_NS,
                ))
                with mock.patch.object(
                    WORKLOAD,
                    "time",
                    SimpleNamespace(perf_counter_ns=clock),
                ):
                    WORKLOAD.tk.Misc.after(
                        root,
                        CALLBACK_TIMER_MS,
                        str.join,
                        "|",
                        ("left", "right"),
                    )
                    callback = scheduler.call_args.args[2]
                    forwarded_args = scheduler.call_args.args[3:]
                    recorder.phase = "measured"
                    self.assertEqual("left|right", callback(*forwarded_args))

                self.assertEqual([], recorder.events)
                self.assertEqual(0, recorder.truncated)
            finally:
                recorder.restore()

    def test_heartbeat_uses_original_scheduler_and_reanchors_deadline(self) -> None:
        """Measure deadline lateness independently and cancel exact timer IDs.

        Returns:
            None: Heartbeat samples exclude wrapper callbacks and old lateness.
        """
        recorder = WORKLOAD.Recorder(enabled=True)
        recorder.install()
        original_after = recorder._original_after
        root = mock.Mock()
        scheduler = mock.Mock(
            side_effect=("heartbeat-1", "heartbeat-2", "heartbeat-3")
        )
        recorder._original_after = scheduler
        interval_ns = WORKLOAD.HEARTBEAT_INTERVAL_NS
        expected_deadline_ns = HEARTBEAT_START_NS + interval_ns
        dispatch_ns = expected_deadline_ns + HEARTBEAT_LATENESS_NS
        clock = mock.Mock(side_effect=(
            HEARTBEAT_START_NS,
            dispatch_ns,
            dispatch_ns + HEARTBEAT_REANCHOR_OFFSET_NS,
            dispatch_ns + HEARTBEAT_REANCHOR_OFFSET_NS,
        ))
        try:
            with mock.patch.object(
                WORKLOAD,
                "time",
                SimpleNamespace(perf_counter_ns=clock),
            ):
                wrapped_after = WORKLOAD.tk.Misc.after
                with mock.patch.object(
                    WORKLOAD.tk.Misc,
                    "after",
                    wraps=wrapped_after,
                ) as wrapped_after_spy:
                    recorder.start_heartbeat(root)
                    self.assertEqual(1, scheduler.call_count)
                    self.assertEqual(0, wrapped_after_spy.call_count)

                    recorder.phase = "measured"
                    scheduled_callback = scheduler.call_args.args[2]
                    scheduled_callback()

                    self.assertEqual(2, scheduler.call_count)
                    self.assertEqual(0, wrapped_after_spy.call_count)
                    self.assertEqual([{
                        "metric": "event_loop_drift",
                        "ns": HEARTBEAT_LATENESS_NS,
                        "phase": "measured",
                    }], recorder.events)
                    self.assertEqual(
                        dispatch_ns + HEARTBEAT_REANCHOR_OFFSET_NS + interval_ns,
                        recorder._heartbeat_deadline_ns,
                    )

                recorder.stop_heartbeat()
                root.after_cancel.assert_called_once_with("heartbeat-2")
                recorder.start_heartbeat(root)
        finally:
            # Restore uses this captured Tk method, not the fake scheduler.
            recorder._original_after = original_after
            recorder.restore()

        self.assertEqual([
            mock.call("heartbeat-2"),
            mock.call("heartbeat-3"),
        ], root.after_cancel.call_args_list)

    def test_disabled_recorder_installs_no_probe_or_samples(self) -> None:
        """Keep disabled instrumentation free of scheduler and counter changes.

        Returns:
            None: No heartbeat, raw timing event or operation count is produced.
        """
        original_after = WORKLOAD.tk.Misc.after
        recorder = WORKLOAD.Recorder(enabled=False)
        root = mock.Mock()
        recorder.install()
        try:
            recorder.start_heartbeat(root)
            recorder.add("callback", TEST_EVENT_DURATION_NS, "measured")
            self.assertIs(original_after, WORKLOAD.tk.Misc.after)
            root.after_cancel.assert_not_called()
            self.assertEqual([], recorder.events)
            self.assertEqual(0, recorder.truncated)
            self.assertFalse(any(recorder.operation_counts.values()))
        finally:
            recorder.restore()


if __name__ == "__main__":
    unittest.main()
