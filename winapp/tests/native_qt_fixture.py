# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/native_qt_fixture.py
# Module Type: Native Qt qualification fixture
# Purpose: Keep native Qt GUI cases on their own platform event-loop owner.
# Responsibilities: Reuse the canonical audit adapter and report its exact case outcomes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Run native Qt fixture classes in their own canonical audit child."""

import faulthandler
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from threading import enumerate as active_threads, main_thread
import time
from typing import Callable
import unittest

from scripts.audit_winapp_partitioned import validate_child_report
from scripts.audit_winapp_report import TestReport

# Purpose: Bound the independent Qt class within one canonical audit partition.
# Type: int. Units: seconds. Scope: one QApplication fixture process.
# Consumers: NativeQtTestCase._run_native_child.
# Rationale: 35 retains the established one-process cap under the parent partition.
NATIVE_QT_FIXTURE_SECONDS = 35
# Purpose: Bound the number of native scenes owned by one Windows process.
# Type: int. Units: exact tests. Scope: one Windows Qt fixture process.
# Consumers: NativeQtTestCase._run_native_group. Rationale: Two cohorts cover
# the observed 41-case class while retaining each 35 s process deadline and
# the canonical 90 s parent partition deadline.
NATIVE_QT_CASES_PER_DESKTOP_PROCESS = 24
# Purpose: Limit macOS native Qt scene ownership per process.
# Type: int. Units: exact tests. Scope: one macOS Qt fixture process.
# Consumers: NativeQtTestCase._run_native_group. Rationale: Three cohorts
# reduce the observed post-report stall exposure while preserving every case
# and both existing process deadlines.
NATIVE_QT_CASES_PER_DARWIN_PROCESS = 16
# Purpose: Identify a child that wrote its report but did not exit.
# Type: float. Units: seconds. Scope: native fixture shutdown observation.
# Consumers: _observe_native_shutdown. Rationale: Two seconds leaves time for
# a trace within the unchanged process deadline, including the observed macOS stall.
NATIVE_QT_SHUTDOWN_TRACE_SECONDS = 2.0
# Purpose: Bound Apple sampler startup and capture after its requested interval.
# Type: float. Units: seconds. Scope: one Darwin native child sample.
# Consumers: NativeQtTestCase._run_native_child. Rationale: Five seconds allows
# sampler attach overhead beyond its one-second stack collection while leaving
# time for the existing child deadline to observe orderly process exit.
NATIVE_QT_DARWIN_SAMPLE_TIMEOUT_SECONDS = 5.0
# Purpose: Bound one Apple process stack sample.
# Type: int. Units: seconds. Scope: Darwin native fixture diagnosis.
# Consumers: NativeQtTestCase._run_native_child.
# Rationale: A one-second sample leaves time to enforce the unchanged deadline.
NATIVE_QT_DARWIN_SAMPLE_SECONDS = 1
# Purpose: Bound retained Apple sample output per native child.
# Type: int. Units: bytes. Scope: one Darwin child diagnostic.
# Consumers: NativeQtTestCase._run_native_child.
# Rationale: A short process sample should retain enough stack detail without
# allowing diagnostic output to grow the fixture log without limit.
NATIVE_QT_DARWIN_SAMPLE_LOG_BYTES = 262144
# Purpose: Recheck child report and exit state during final shutdown diagnosis.
# Type: float. Units: seconds. Scope: Darwin native fixture child monitoring.
# Consumers: NativeQtTestCase._run_native_child. Rationale: 50 ms notices a
# late canonical report promptly without adding GUI work to the child.
NATIVE_QT_DARWIN_SAMPLE_POLL_SECONDS = 0.05


def _observe_native_shutdown() -> None:
    """Trace any child still alive after its native test cleanup completes.

    Returns:
        None: Reports remaining non-daemon thread types and schedules a traceback
            without changing normal exit or the hard parent deadline.
    """
    workers = tuple(type(thread).__name__ for thread in active_threads()
                    if thread is not main_thread() and not thread.daemon)
    print(f'Native Qt cleanup finished; remaining non-daemon threads: {workers!r}',
          file=sys.stderr, flush=True)
    faulthandler.dump_traceback_later(
        NATIVE_QT_SHUTDOWN_TRACE_SECONDS, file=sys.stderr,
    )


class NativeQtTestCase(unittest.TestCase):
    """Preserve test IDs while isolating native ownership from Tk tests."""

    _native_report: dict[str, TestReport] | None = None

    @classmethod
    def delegate_native_cases(cls) -> bool:
        """Run the fixture's exact native case set outside the current process.

        Returns:
            bool: True in the parent after collecting real results. The native
                child returns False and initializes its own QApplication.
        """
        if os.environ.get('PHP_GALLERY_NATIVE_QT_FIXTURES') == '1':
            cls.addClassCleanup(_observe_native_shutdown)
            return False
        cls._native_report = cls._run_native_group()
        return True

    @classmethod
    def _run_native_group(cls) -> dict[str, TestReport]:
        """Use the canonical adapter for the class's exact native test inventory.

        Returns:
            dict[str, TestReport]: Validated exact outcomes indexed by test ID.
        Raises:
            AssertionError: A child crashed, exceeded its time budget or reported
                incomplete coverage.
        """
        ids = [case.id() for case in unittest.defaultTestLoader.loadTestsFromTestCase(cls)]
        if sys.platform == 'darwin':
            cohort_size = NATIVE_QT_CASES_PER_DARWIN_PROCESS
        elif sys.platform == 'win32':
            cohort_size = NATIVE_QT_CASES_PER_DESKTOP_PROCESS
        else:
            return cls._run_native_child(ids)
        if len(ids) <= cohort_size:
            return cls._run_native_child(ids)
        reports: dict[str, TestReport] = {}
        for start in range(0, len(ids), cohort_size):
            cases = ids[start:start + cohort_size]
            reports.update(cls._run_native_child(cases))
        return reports

    @classmethod
    def _run_native_child(cls, ids: list[str]) -> dict[str, TestReport]:
        """Run one Qt-owned process with the selected canonical IDs.

        Args:
            ids (list[str]): Original uninterrupted segment of class case IDs.
        Returns:
            dict[str, TestReport]: Complete exact outcomes indexed by test ID.
        Raises:
            AssertionError: The child failed its process or evidence contract.
        """
        environment = dict(os.environ, PHP_GALLERY_NATIVE_QT_FIXTURES='1')
        root = Path(__file__).resolve().parents[2]
        with tempfile.TemporaryDirectory() as folder, tempfile.TemporaryFile(mode='w+b') as log:
            ids_path = Path(folder) / 'ids.json'
            result_path = Path(folder) / 'results.json'
            sample_path = Path(folder) / 'native-qt.sample.txt'
            ids_path.write_text(json.dumps(ids), encoding='utf-8')
            child = None
            timed_out = False
            sample_error = None
            # A native helper can inherit the output handle. The temporary
            # stream is independent of both pipe EOF and the report directory.
            try:
                child = subprocess.Popen(
                    [sys.executable, '-m', 'scripts.audit_winapp_report', str(result_path),
                     f'--test-ids-file={ids_path}'],
                    cwd=root, env=environment, stdout=log, stderr=subprocess.STDOUT,
                )
                deadline = time.monotonic() + NATIVE_QT_FIXTURE_SECONDS
                if sys.platform == 'darwin':
                    report_observed_at = None
                    sampled = False
                    while child.poll() is None and time.monotonic() < deadline:
                        observed_at = time.monotonic()
                        if result_path.is_file() and report_observed_at is None:
                            report_observed_at = observed_at
                        if (not sampled and report_observed_at is not None
                                and observed_at - report_observed_at
                                >= NATIVE_QT_SHUTDOWN_TRACE_SECONDS):
                            sampled = True
                            remaining = max(0.0, deadline - time.monotonic())
                            try:
                                subprocess.run(
                                    ['/usr/bin/sample', str(child.pid),
                                     str(NATIVE_QT_DARWIN_SAMPLE_SECONDS),
                                     '-file', str(sample_path)],
                                    timeout=min(
                                        NATIVE_QT_DARWIN_SAMPLE_TIMEOUT_SECONDS,
                                        remaining,
                                    ),
                                    check=True, stdout=subprocess.DEVNULL,
                                    stderr=subprocess.DEVNULL,
                                )
                            except (OSError, subprocess.SubprocessError) as error:
                                sample_error = type(error).__name__
                            if not sample_path.is_file() and sample_error is None:
                                sample_error = 'OutputMissing'
                            continue
                        remaining = max(0.0, deadline - time.monotonic())
                        time.sleep(min(
                            NATIVE_QT_DARWIN_SAMPLE_POLL_SECONDS,
                            max(0.0, remaining),
                        ))
                if child.poll() is None:
                    try:
                        child.wait(timeout=max(0.0, deadline - time.monotonic()))
                    except subprocess.TimeoutExpired:
                        timed_out = True
            finally:
                if child is not None and child.poll() is None:
                    child.kill()
                    child.wait()
            log.seek(0)
            output = log.read().decode('utf-8', errors='replace')
            if sample_path.is_file():
                with sample_path.open('rb') as sample_log:
                    output += '\n' + sample_log.read(NATIVE_QT_DARWIN_SAMPLE_LOG_BYTES).decode(
                        'utf-8', errors='replace',
                    )
                    if sample_log.read(1):
                        output += '\n[Apple sample output truncated]\n'
            if sample_error is not None:
                output += f'\n[Apple sample unavailable: {sample_error}]\n'
            if child is None or timed_out:
                last_case = 'no case started'
                own_cases = set(ids)
                finished: set[str] = set()
                case_seconds = 0.0
                for line in output.splitlines():
                    fields = line.split()
                    if len(fields) >= 2 and fields[0] in ('START', 'END') and fields[1] in own_cases:
                        last_case = f'{fields[0]} {fields[1]}'
                        if fields[0] == 'END' and fields[1] not in finished:
                            finished.add(fields[1])
                            if len(fields) >= 4:
                                try:
                                    case_seconds += float(fields[3].removesuffix('s'))
                                except ValueError:
                                    pass  # A truncated progress line still identifies the case.
                report_timing = (
                    f'{max(0.0, time.time() - result_path.stat().st_mtime):.2f}s before the timeout'
                    if result_path.is_file() else 'not written'
                )
                _earlier, marker, shutdown = output.rpartition('Native Qt cleanup finished;')
                if marker:
                    print(marker + shutdown, file=sys.stderr, end='')
                raise AssertionError(
                    f'Native Qt fixture exceeded {NATIVE_QT_FIXTURE_SECONDS} seconds '
                    f'with last observed case {last_case}. '
                    f'Completed {len(finished)}/{len(ids)} in {case_seconds:.2f}s of case time; '
                    f'report {report_timing}.',
                )
            print(output, file=sys.stderr, end='')
            if child.returncode not in (0, 1) or not result_path.is_file():
                raise AssertionError(f'Native Qt fixture process exited with {child.returncode}.')
            report = json.loads(result_path.read_text(encoding='utf-8'))
            if not validate_child_report(report) or [row['id'] for row in report['tests']] != ids:
                raise AssertionError('Native Qt fixture process did not report the exact test inventory.')
            return {row['id']: row for row in report['tests']}

    def _callTestMethod(self, method: Callable[[], None]) -> None:
        """Keep native results visible to the central partition coordinator.

        Args:
            method (Callable[[], None]): Current canonical test method.
        Returns:
            None: Executes the native child case or records its exact outcome.
        """
        if self._native_report is None:
            super()._callTestMethod(method)
            return
        row = self._native_report[self.id()]
        if row['status'] == 'SKIP':
            self.skipTest(row['reason'] or 'Native Qt fixture skipped.')
        if row['status'] != 'PASS':
            self.fail(row['reason'] or row['status'])
