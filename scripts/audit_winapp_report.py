#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: scripts/audit_winapp_report.py
# Module Type: WinApp Audit Reporting Adapter
# Purpose: Capture structured per-test results from canonical WinApp unittest discovery.
# Responsibilities:
#   - Preserve unittest outcomes, reasons, durations and fixture errors in one report.
#   - Write a complete schema-versioned JSON report for the PHP audit owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Capture structured per-test results from the canonical WinApp unittest suite."""

import json
import os
import platform
import sys
import tempfile
import time
import unittest
from pathlib import Path
from types import TracebackType
from typing import TextIO, TypedDict


class SubtestReport(TypedDict):
    """Describe one reported subtest outcome and its concrete execution detail."""

    id: str
    status: str
    reason: str


class TestReport(TypedDict):
    """Describe one canonical unittest identity, outcome, duration and subtests."""

    id: str
    status: str
    duration_seconds: float
    reason: str | None
    subtests: list[SubtestReport]


class FixtureErrorReport(TypedDict):
    """Describe a class or module fixture failure outside ordinary test cases."""

    phase: str
    id: str
    reason: str


class FixtureSkipReport(TypedDict):
    """Describe a skipped class or module fixture and its factual reason."""

    phase: str
    id: str
    reason: str


class SuiteSettingsReport(TypedDict):
    """Record the fixed discovery directory, filename pattern and runner settings."""

    start_directory: str
    pattern: str
    runner: str
    verbosity: int


class RuntimeReport(TypedDict):
    """Describe the Python process and fixed standard unittest discovery settings."""

    python_version: str
    python_implementation: str
    platform: str
    executable: str
    suite_settings: SuiteSettingsReport


class CountsReport(TypedDict):
    """Count top-level outcomes, fixture failures and nested subtest outcomes."""

    total: int
    passed: int
    failed: int
    errors: int
    skipped: int
    expected_failures: int
    unexpected_successes: int
    subtest_failures: int
    subtest_errors: int
    subtest_skips: int
    fixture_errors: int
    fixture_skips: int


class AuditReport(TypedDict):
    """Describe the complete schema-versioned report consumed by the PHP audit."""

    schema_version: int
    successful: bool
    runtime: RuntimeReport
    counts: CountsReport
    tests: list[TestReport]
    fixture_errors: list[FixtureErrorReport]
    fixture_skips: list[FixtureSkipReport]


class AuditTextTestResult(unittest.TextTestResult):
    """Retain individual unittest outcomes and their factual execution details."""

    def __init__(self, stream: TextIO, descriptions: bool, verbosity: int) -> None:
        """Initialize text reporting with structured per-test and fixture records.

        Args:
            stream (TextIO): Private stream used by unittest's standard summary.
            descriptions (bool): Whether unittest includes test descriptions.
            verbosity (int): Standard unittest output verbosity.
        Returns:
            None: Initializes an empty structured result collection.
        """
        super().__init__(stream, descriptions, verbosity)
        self.records: list[TestReport] = []
        self.fixture_errors: list[FixtureErrorReport] = []
        self.fixture_skips: list[FixtureSkipReport] = []
        self._active: dict[int, TestReport] = {}
        self._started_at: dict[int, float] = {}
        self._subtest_event_counts: dict[int, int] = {}

    def startTest(self, test: unittest.case.TestCase) -> None:
        """Start timing and recording one canonical unittest test case.

        Args:
            test (unittest.case.TestCase): Test case about to execute.
        Returns:
            None: Registers the test before its setup and body run.
        """
        record: TestReport = {
            "id": test.id(),
            "status": "PENDING",
            "duration_seconds": 0.0,
            "reason": None,
            "subtests": [],
        }
        self.records.append(record)
        self._active[id(test)] = record
        self._started_at[id(test)] = time.perf_counter()
        super().startTest(test)

    def stopTest(self, test: unittest.case.TestCase) -> None:
        """Finalize elapsed time without inventing an absent outcome callback.

        Args:
            test (unittest.case.TestCase): Test case that has completed teardown.
        Returns:
            None: Closes timing while preserving explicit outcome evidence.
        """
        key = id(test)
        record = self._active.pop(key, None)
        started = self._started_at.pop(key, None)
        if record is not None and started is not None:
            record["duration_seconds"] = max(0.0, time.perf_counter() - started)
            if record["status"] == "PENDING":
                record["reason"] = "No terminal unittest outcome callback was received."
        super().stopTest(test)

    def addSuccess(self, test: unittest.case.TestCase) -> None:
        """Record a test case that completed without a reported failure.

        Args:
            test (unittest.case.TestCase): Successful test case.
        Returns:
            None: Marks the corresponding record as PASS.
        """
        self._set_test_outcome(test, "PASS", None)
        super().addSuccess(test)

    def addFailure(self, test: unittest.case.TestCase,
                   err: tuple[type[BaseException], BaseException, TracebackType | None]) -> None:
        """Record a test assertion failure and its formatted traceback.

        Args:
            test (unittest.case.TestCase): Test case with a failed assertion.
            err (tuple[type[BaseException], BaseException, TracebackType | None]): Assertion failure details.
        Returns:
            None: Marks the test as FAIL and preserves unittest bookkeeping.
        """
        self._set_test_outcome(test, "FAIL", self._exc_info_to_string(err, test))
        super().addFailure(test, err)

    def addError(self, test: unittest.case.TestCase | unittest.case._SubTest | unittest.suite._ErrorHolder,
                 err: tuple[type[BaseException], BaseException, TracebackType | None]) -> None:
        """Record a test error or an error raised by a class/module fixture.

        Args:
            test (unittest.case.TestCase | unittest.case._SubTest | unittest.suite._ErrorHolder):
                Test case, nested subtest, or unittest fixture holder.
            err (tuple[type[BaseException], BaseException, TracebackType | None]): Error details.
        Returns:
            None: Stores a test error or a separately identified fixture error.
        """
        reason = self._exc_info_to_string(err, test)
        record = self._active.get(id(test))
        if record is not None:
            self._set_test_outcome(test, "ERROR", reason)
        else:
            fixture_id = test.id()
            phase = fixture_id.partition(" ")[0]
            self.fixture_errors.append({"phase": phase, "id": fixture_id, "reason": reason})
        super().addError(test, err)

    def addSkip(self, test: unittest.case.TestCase | unittest.case._SubTest | unittest.suite._ErrorHolder,
                reason: str) -> None:
        """Record a skipped test and the reason supplied by unittest.

        Args:
            test (unittest.case.TestCase | unittest.case._SubTest | unittest.suite._ErrorHolder):
                Skipped test case, nested subtest, or unittest fixture holder.
            reason (str): Factual skip reason supplied to ``skipTest`` or a skip decorator.
        Returns:
            None: Marks the test as SKIP and preserves unittest bookkeeping.
        """
        record = self._active.get(id(test))
        if record is not None:
            self._set_test_outcome(test, "SKIP", reason)
        elif isinstance(test, unittest.case._SubTest):
            parent = test.test_case
            self._append_subtest(parent, test, "SKIP", reason)
            parent_record = self._active.get(id(parent))
            if parent_record is not None and parent_record["status"] == "PENDING":
                self._set_test_outcome(
                    parent,
                    "SKIP",
                    f"unittest did not report parent success after subtest skip: {reason}",
                )
        elif isinstance(test, unittest.case.TestCase):
            raise RuntimeError(f"Unpaired unittest skip callback for {test.id()}: {reason}")
        else:
            fixture_id = test.id()
            self.fixture_skips.append({
                "phase": fixture_id.partition(" ")[0],
                "id": fixture_id,
                "reason": reason,
            })
        super().addSkip(test, reason)

    def addExpectedFailure(self, test: unittest.case.TestCase,
                           err: tuple[type[BaseException], BaseException, TracebackType | None]) -> None:
        """Record an expected failure without relabeling it as a plain pass.

        Args:
            test (unittest.case.TestCase): Test case declared as expected to fail.
            err (tuple[type[BaseException], BaseException, TracebackType | None]): Expected failure details.
        Returns:
            None: Marks the distinct EXPECTED_FAILURE outcome.
        """
        self._set_test_outcome(test, "EXPECTED_FAILURE", self._exc_info_to_string(err, test))
        super().addExpectedFailure(test, err)

    def addUnexpectedSuccess(self, test: unittest.case.TestCase) -> None:
        """Record an expected-failure test that unexpectedly passed.

        Args:
            test (unittest.case.TestCase): Test case that violated its expected-failure contract.
        Returns:
            None: Marks the distinct failing UNEXPECTED_SUCCESS outcome.
        """
        self._set_test_outcome(test, "UNEXPECTED_SUCCESS", "Expected failure unexpectedly succeeded.")
        super().addUnexpectedSuccess(test)

    def addSubTest(self, test: unittest.case.TestCase, subtest: unittest.case._SubTest,
                   err: tuple[type[BaseException], BaseException, TracebackType | None] | None) -> None:
        """Record failed or skipped subtests while retaining standard failure handling.

        Args:
            test (unittest.case.TestCase): Parent test case containing the subtest.
            subtest (unittest.case._SubTest): Concrete subtest and its canonical description.
            err (tuple[type[BaseException], BaseException, TracebackType | None] | None): Subtest outcome, if any.
        Returns:
            None: Appends a subtest result and delegates failures to unittest.
        """
        if err is None:
            super().addSubTest(test, subtest, err)
            return
        exception_type = err[0]
        if issubclass(exception_type, unittest.case.SkipTest):
            status = "SKIP"
        elif issubclass(exception_type, test.failureException):
            status = "FAIL"
        else:
            status = "ERROR"
        reason = self._exc_info_to_string(err, subtest)
        self._append_subtest(test, subtest, status, reason)
        if status in ("FAIL", "ERROR"):
            self._set_test_outcome(test, status, reason)
        elif status == "SKIP":
            record = self._active.get(id(test))
            if record is not None and record["status"] == "PENDING":
                self._set_test_outcome(
                    test,
                    "SKIP",
                    f"unittest did not report parent success after subtest skip: {reason}",
                )
        if status == "SKIP":
            return
        super().addSubTest(test, subtest, err)

    def _append_subtest(self, test: unittest.case.TestCase, subtest: unittest.case._SubTest,
                        status: str, reason: str) -> None:
        """Append one uniquely numbered subtest event to its active parent.

        Args:
            test (unittest.case.TestCase): Parent test case for the event.
            subtest (unittest.case._SubTest): Subtest carrying its canonical description.
            status (str): Explicit subtest outcome such as FAIL, ERROR or SKIP.
            reason (str): Factual skip reason or formatted failure traceback.
        Returns:
            None: Adds the event without discarding repeated canonical subtest IDs.
        """
        record = self._active.get(id(test))
        if record is None:
            return
        parent_key = id(test)
        event_number = self._subtest_event_counts.get(parent_key, 0) + 1
        self._subtest_event_counts[parent_key] = event_number
        record["subtests"].append({
            "id": f"{subtest.id()} [event {event_number}]",
            "status": status,
            "reason": reason,
        })

    def _set_test_outcome(self, test: unittest.case.TestCase, status: str,
                          reason: str | None) -> None:
        """Merge an explicit unittest outcome without losing stronger evidence.

        Args:
            test (unittest.case.TestCase): Test case whose outcome was reported.
            status (str): Canonical report status for the event.
            reason (str | None): Factual explanation or traceback, if present.
        Returns:
            None: Retains the strongest status and all distinct adverse reasons.
        """
        record = self._active.get(id(test))
        if record is None:
            return
        priority = {
            "PENDING": 0,
            "PASS": 1,
            "SKIP": 2,
            "EXPECTED_FAILURE": 3,
            "UNEXPECTED_SUCCESS": 4,
            "FAIL": 5,
            "ERROR": 6,
        }
        current_status = record["status"]
        current_reason = record["reason"]
        if priority[status] > priority[current_status]:
            record["status"] = status
        if reason is None:
            return
        if current_reason is None:
            record["reason"] = reason
        elif reason not in current_reason:
            record["reason"] = f"{current_reason}\n{status}: {reason}"


def report_suite(suite: unittest.TestSuite, stream: TextIO) -> AuditReport:
    """Run one supplied suite and return its complete structured test report.

    Args:
        suite (unittest.TestSuite): Suite created by the caller's standard discovery.
        stream (TextIO): Private stream for unittest's human-readable summary.
    Returns:
        AuditReport: Schema-1 runtime, counts, test outcomes, fixture errors and success state.
    """
    runner = unittest.TextTestRunner(stream=stream, verbosity=1, resultclass=AuditTextTestResult)
    result = runner.run(suite)
    tests = result.records
    ids = [record["id"] for record in tests]
    unique_ids = len(ids) == len(set(ids))
    subtest_ids_unique = all(
        len([item["id"] for item in record["subtests"]])
        == len(set(item["id"] for item in record["subtests"]))
        for record in tests
    )
    statuses = [str(record["status"]) for record in tests]
    subtests = [item for record in tests for item in record["subtests"]]
    counts: CountsReport = {
        "total": len(tests),
        "passed": statuses.count("PASS"),
        "failed": statuses.count("FAIL"),
        "errors": statuses.count("ERROR") + len(result.fixture_errors),
        "skipped": statuses.count("SKIP"),
        "expected_failures": statuses.count("EXPECTED_FAILURE"),
        "unexpected_successes": statuses.count("UNEXPECTED_SUCCESS"),
        "subtest_failures": sum(item["status"] == "FAIL" for item in subtests),
        "subtest_errors": sum(item["status"] == "ERROR" for item in subtests),
        "subtest_skips": sum(item["status"] == "SKIP" for item in subtests),
        "fixture_errors": len(result.fixture_errors),
        "fixture_skips": len(result.fixture_skips),
    }
    suite_settings: SuiteSettingsReport = {
        "start_directory": "winapp/tests",
        "pattern": "test_*.py",
        "runner": "unittest.TextTestRunner",
        "verbosity": 1,
    }
    runtime: RuntimeReport = {
        "python_version": platform.python_version(),
        "python_implementation": platform.python_implementation(),
        "platform": platform.platform(),
        "executable": sys.executable,
        "suite_settings": suite_settings,
    }
    recognized_statuses = {
        "PASS", "FAIL", "ERROR", "SKIP", "EXPECTED_FAILURE", "UNEXPECTED_SUCCESS",
    }
    complete_statuses = all(status in recognized_statuses for status in statuses)
    nonempty = bool(tests or result.fixture_errors or result.fixture_skips)
    successful = (
        result.wasSuccessful() and unique_ids and subtest_ids_unique
        and complete_statuses and nonempty
    )
    return {
        "schema_version": 1,
        "successful": successful,
        "runtime": runtime,
        "counts": counts,
        "tests": tests,
        "fixture_errors": result.fixture_errors,
        "fixture_skips": result.fixture_skips,
    }


def discover_canonical_suite() -> unittest.TestSuite:
    """Discover only the repository's standard WinApp ``test_*.py`` suite.

    Returns:
        unittest.TestSuite: Standard-library discovery result for ``winapp/tests``.
    """
    project_root = Path(__file__).resolve().parents[1]
    test_directory = project_root / "winapp" / "tests"
    return unittest.defaultTestLoader.discover(str(test_directory), pattern="test_*.py")


def write_complete_report(output_path: Path, report: AuditReport) -> None:
    """Atomically write one complete UTF-8 JSON report to the requested file.

    Args:
        output_path (Path): Destination JSON path selected by the audit owner.
        report (AuditReport): Fully completed schema-1 report.
    Returns:
        None: Replaces the destination only after the complete JSON is flushed.
    """
    output_path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path: Path | None = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w", encoding="utf-8", newline="\n", dir=output_path.parent,
            prefix=f".{output_path.name}.", suffix=".tmp", delete=False,
        ) as temporary_file:
            temporary_path = Path(temporary_file.name)
            json.dump(report, temporary_file, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
            temporary_file.write("\n")
            temporary_file.flush()
            os.fsync(temporary_file.fileno())
        os.replace(temporary_path, output_path)
    except BaseException:
        if temporary_path is not None:
            temporary_path.unlink(missing_ok=True)
        raise


def main(arguments: list[str]) -> int:
    """Run canonical discovery and write its report using one output-path argument.

    Args:
        arguments (list[str]): Command-line arguments excluding the program name.
    Returns:
        int: Zero for a native successful suite, one for reported failures, or two for adapter errors.
    """
    if len(arguments) != 1:
        return 2
    output_path = Path(arguments[0]).expanduser().resolve()
    if output_path.exists():
        return 2
    try:
        suite = discover_canonical_suite()
        report = report_suite(suite, sys.stderr)
        write_complete_report(output_path, report)
    except Exception as error:
        print(f"WinApp report adapter failed: {type(error).__name__}: {error}", file=sys.stderr)
        return 2
    return 0 if report["successful"] is True else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
