# Project: PHP Gallery
# Module Type: Regression Test
# Purpose: Verify structured per-test reporting for canonical WinApp unittest discovery.
# Responsibilities:
#   - Cover individual unittest outcomes, subtests and fixture errors.
#   - Protect report completeness, uniqueness, counts and native unittest success semantics.
#   - Retain selected-child failure reasons before a timeout can discard its report.
# File: winapp/tests/test_audit_reporting.py
# Repository: https://github.com/klusik/PHP_gallery
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Verify schema-versioned WinApp audit reports without running the production suite."""

import copy
import io
import sys
import unittest
from unittest import mock
from pathlib import Path

project_root = Path(__file__).resolve().parents[2]
if str(project_root) not in sys.path:
    sys.path.insert(0, str(project_root))

from scripts.audit_winapp_report import AuditReport, report_suite
from scripts.audit_winapp_report import discover_canonical_suite, flatten_suite, main
from scripts.audit_winapp_partitioned import merge_reports, partition_test_ids
from scripts.audit_winapp_partitioned import validate_child_report


class AuditReportingTests(unittest.TestCase):
    """Protect structured WinApp unittest outcomes and report accounting."""

    def test_selected_child_progress_is_flushed_without_changing_reports(self) -> None:
        """Emit per-test progress only for selected child execution.

        Returns:
            None: Default reporting stays quiet and both reports retain counts.
        """
        class SampleCase(unittest.TestCase):
            """Supply one deterministic test to both reporting modes."""

            def test_sample(self) -> None:
                """Complete one selected child case successfully.

                Returns:
                    None: The result owner observes one successful case.
                """
                self.assertTrue(True)

        normal_stream = io.StringIO()
        selected_stream = io.StringIO()
        normal = report_suite(
            unittest.defaultTestLoader.loadTestsFromTestCase(SampleCase),
            normal_stream,
        )
        selected = report_suite(
            unittest.defaultTestLoader.loadTestsFromTestCase(SampleCase),
            selected_stream, partition_child=True,
        )
        test_id = normal['tests'][0]['id']
        self.assertNotIn('START ', normal_stream.getvalue())
        self.assertNotIn('END ', normal_stream.getvalue())
        self.assertIn(f'START {test_id}\n', selected_stream.getvalue())
        self.assertRegex(selected_stream.getvalue(),
                         r'END .*\.test_sample PASS [0-9]+\.[0-9]{3}s\n')
        self.assertEqual(normal['counts'], selected['counts'])
        self.assertTrue(normal['successful'])
        self.assertTrue(selected['successful'])

    def test_selected_child_progress_flushes_failure_and_error_reasons(self) -> None:
        """Retain adverse reasons before unittest's final summary is printed.

        Returns:
            None: Selected-child progress includes full failure and error
                details even when unittest's trailing traceback output is absent.
        """
        class FailingCase(unittest.TestCase):
            """Supply one assertion failure and one test error."""

            def test_expected_mismatch(self) -> None:
                """Fail with one fixed reason safe in audit diagnostics.

                Returns:
                    None: The reporter captures the assertion without raising
                        out of the unittest runner.
                """
                self.fail('controlled failure reason')

            def test_unexpected_runtime_error(self) -> None:
                """Raise one fixed exception for selected-child diagnostics.

                Returns:
                    None: The report classifies the callback as an error.
                """
                raise RuntimeError('controlled error reason')

        stream = io.StringIO()
        with mock.patch.object(unittest.TextTestResult, 'printErrors'):
            report = report_suite(
                unittest.defaultTestLoader.loadTestsFromTestCase(FailingCase),
                stream, partition_child=True,
            )
        output = stream.getvalue()
        self.assertFalse(report['successful'])
        self.assertEqual(1, report['counts']['failed'])
        self.assertEqual(1, report['counts']['errors'])
        self.assertRegex(
            output,
            r'END .*\.test_expected_mismatch FAIL [0-9]+\.[0-9]{3}s\n'
            r'Traceback \(most recent call last\):\n',
        )
        self.assertIn('AssertionError: controlled failure reason', output)
        self.assertRegex(
            output,
            r'END .*\.test_unexpected_runtime_error ERROR [0-9]+\.[0-9]{3}s\n'
            r'Traceback \(most recent call last\):\n',
        )
        self.assertIn('RuntimeError: controlled error reason', output)

    def test_native_diagnostic_is_explicit_and_preserves_full_discovery(self) -> None:
        """Bind diagnostic reports to their exact selected native fixture.

        Returns:
            None: Defaults keep full discovery, the native flag records its
                narrower pattern, and unrecognized selectors are refused.
        """
        with mock.patch.object(unittest.defaultTestLoader, 'discover') as discover:
            discover_canonical_suite()
            self.assertEqual('test_*.py', discover.call_args.kwargs['pattern'])
            discover_canonical_suite(native_only=True)
            self.assertEqual(
                'test_native_gallery_viewer.py',
                discover.call_args.kwargs['pattern'],
            )
        # An intentionally empty supplied suite records selection metadata;
        # it still cannot become successful evidence without any actual test.
        report = report_suite(
            unittest.TestSuite(), io.StringIO(), native_only=True,
        )
        self.assertFalse(report['successful'])
        self.assertEqual(
            'test_native_gallery_viewer.py',
            report['runtime']['suite_settings']['pattern'],
        )
        self.assertEqual(2, main(['unused-output.json', '--other-selection']))
        self.assertEqual(
            2,
            main(['unused-output.json', '--native-only', '--native-only']),
        )
        self.assertEqual(2, main(['unused-output.json', '--test-ids-file=']))
        with self.assertRaises(TypeError):
            flatten_suite(unittest.TestSuite([object()]))

    def test_partition_assignment_keeps_classes_whole_and_is_deterministic(self) -> None:
        """Balance all canonical IDs without splitting test classes.

        Returns:
            None: Exact union, disjointness, class ownership and stable order hold.
        """
        test_ids = [
            'pkg.alpha.Alpha.test_one', 'pkg.alpha.Alpha.test_two',
            'pkg.beta.Beta.test_one', 'pkg.beta.Beta.test_two',
            'pkg.gamma.Gamma.test_one', 'pkg.delta.Delta.test_one',
            'pkg.epsilon.Epsilon.test_one',
        ]
        first = partition_test_ids(test_ids, 2)
        second = partition_test_ids(test_ids, 2)
        self.assertEqual(first, second)
        self.assertEqual(set(test_ids), set(first[0]) | set(first[1]))
        self.assertFalse(set(first[0]) & set(first[1]))
        for class_name in ('Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'):
            matching = [index for index, shard in enumerate(first)
                        if any(f'.{class_name}.' in test_id for test_id in shard)]
            self.assertEqual(1, len(matching))
        fallback = partition_test_ids(test_ids, 4)
        fallback_ids = [test_id for shard in fallback for test_id in shard]
        self.assertEqual(set(test_ids), set(fallback_ids))
        self.assertEqual(len(fallback_ids), len(set(fallback_ids)))
        self.assertEqual(4, len(fallback))
        for class_name in ('Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'):
            matching = [index for index, shard in enumerate(fallback)
                        if any(f'.{class_name}.' in test_id for test_id in shard)]
            self.assertEqual(1, len(matching))
        full_fallback = partition_test_ids(test_ids, 5)
        self.assertEqual(set(test_ids), {
            test_id for shard in full_fallback for test_id in shard
        })
        self.assertEqual(5, len(full_fallback))
        for class_name in ('Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'):
            matching = [index for index, shard in enumerate(full_fallback)
                        if any(f'.{class_name}.' in test_id for test_id in shard)]
            self.assertEqual(1, len(matching))

    def test_partition_isolates_slow_canonical_groups(self) -> None:
        """Keep the viewer module and all measured classes isolated.

        Returns:
            None: Viewer, both cache cohorts, preload benchmark and complete
                remainder occupy separate shards with exact disjoint coverage.
        """
        viewer_ids = [
            'test_native_gallery_viewer.NativeGalleryViewerTests.test_one',
            'test_native_gallery_viewer.NativeGalleryViewerTests.test_two',
            'test_native_gallery_viewer.OtherViewerTests.test_three',
        ]
        small_benchmark_ids = [
            'test_gallery_read_linking.ThumbnailCacheSmallBenchmarkTests.test_four',
        ]
        large_benchmark_ids = [
            'test_gallery_read_linking.ThumbnailCacheLargeBenchmarkTests.test_five',
        ]
        preload_benchmark_ids = [
            'test_gallery_preload.GalleryPreloadWorkerTests.test_six',
            'test_gallery_preload.GalleryPreloadWorkerTests.test_seven',
        ]
        complement_ids = ['test_upload.UploadTests.test_eight']
        canonical_ids = (
            viewer_ids + small_benchmark_ids + large_benchmark_ids
            + preload_benchmark_ids + complement_ids
        )

        shards = partition_test_ids(canonical_ids, 5)

        self.assertEqual(set(viewer_ids), set(shards[0]))
        self.assertEqual(set(small_benchmark_ids), set(shards[1]))
        self.assertEqual(set(large_benchmark_ids), set(shards[2]))
        self.assertEqual(set(preload_benchmark_ids), set(shards[3]))
        self.assertEqual(set(complement_ids), set(shards[4]))
        self.assertEqual(
            set(canonical_ids),
            set(shards[0]) | set(shards[1]) | set(shards[2])
            | set(shards[3]) | set(shards[4]),
        )
        self.assertFalse(set(shards[0]) & set(shards[1]))
        self.assertFalse(set(shards[0]) & set(shards[2]))
        self.assertFalse(set(shards[0]) & set(shards[3]))
        self.assertFalse(set(shards[0]) & set(shards[4]))
        self.assertFalse(set(shards[1]) & set(shards[2]))
        self.assertFalse(set(shards[1]) & set(shards[3]))
        self.assertFalse(set(shards[1]) & set(shards[4]))
        self.assertFalse(set(shards[2]) & set(shards[3]))
        self.assertFalse(set(shards[2]) & set(shards[4]))
        self.assertFalse(set(shards[3]) & set(shards[4]))

    def test_partition_merge_rejects_missing_duplicate_and_unsuccessful_children(self) -> None:
        """Prevent incomplete, duplicated or failed child evidence from passing.

        Returns:
            None: Five real successful reports with the exact ID union qualify.
        """
        class AlphaCase(unittest.TestCase):
            """Provide one passing class for coordinator evidence tests."""

            def test_alpha(self) -> None:
                """Pass one synthetic selected-ID test.

                Returns:
                    None: The synthetic assertion completes successfully.
                """

        class BetaCase(unittest.TestCase):
            """Provide a second passing class for coordinator evidence tests."""

            def test_beta(self) -> None:
                """Pass one synthetic selected-ID test.

                Returns:
                    None: The synthetic assertion completes successfully.
                """

        class GammaCase(unittest.TestCase):
            """Provide a third passing class for coordinator evidence tests."""

            def test_gamma(self) -> None:
                """Pass one synthetic selected-ID test.

                Returns:
                    None: The synthetic assertion completes successfully.
                """

        class DeltaCase(unittest.TestCase):
            """Provide a fourth passing class for coordinator evidence tests."""

            def test_delta(self) -> None:
                """Pass one synthetic selected-ID test.

                Returns:
                    None: The synthetic assertion completes successfully.
                """

        class EpsilonCase(unittest.TestCase):
            """Provide a fifth passing class for coordinator evidence tests."""

            def test_epsilon(self) -> None:
                """Pass one synthetic selected-ID test.

                Returns:
                    None: The synthetic assertion completes successfully.
                """

        reports = [
            report_suite(
                unittest.defaultTestLoader.loadTestsFromTestCase(case_type),
                io.StringIO(), partition_child=True,
            )
            for case_type in (AlphaCase, BetaCase, GammaCase, DeltaCase, EpsilonCase)
        ]
        expected_ids = [test['id'] for report in reports for test in report['tests']]
        self.assertTrue(all(validate_child_report(report) for report in reports))
        bool_schema_version = copy.deepcopy(reports[0])
        bool_schema_version['schema_version'] = True
        self.assertFalse(validate_child_report(bool_schema_version))
        bool_verbosity = copy.deepcopy(reports[0])
        bool_verbosity['runtime']['suite_settings']['verbosity'] = True
        self.assertFalse(validate_child_report(bool_verbosity))
        malformed = copy.deepcopy(reports[0])
        malformed['tests'][0]['duration_seconds'] = float('nan')
        self.assertFalse(validate_child_report(malformed))
        contradictory = copy.deepcopy(reports[0])
        contradictory['tests'][0]['subtests'] = [{
            'id': 'child-failure', 'status': 'FAIL', 'reason': 'Controlled failure.',
        }]
        contradictory['counts']['subtest_failures'] = 1
        self.assertFalse(validate_child_report(contradictory))
        processes = [
            {
                'partition': index, 'test_count': 1, 'exit_code': 0,
                'timed_out': False, 'duration_seconds': 0.1,
                'report_present': True, 'report_valid': True,
                'report_successful': True,
                'coverage_complete': True,
                'expected_test_ids': [report['tests'][0]['id']],
                'observed_test_ids': [report['tests'][0]['id']],
            }
            for index, report in enumerate(reports, start=1)
        ]
        merged, successful = merge_reports(reports, processes, expected_ids, 0.5)
        self.assertTrue(successful)
        self.assertEqual(5, merged['counts']['total'])
        self.assertEqual(
            'test_*.py',
            merged['runtime']['suite_settings']['pattern'],
        )
        self.assertNotIn(
            'partition_selection', merged['runtime']['suite_settings'],
        )
        self.assertTrue(all(
            report['runtime']['suite_settings']['partition_selection']
            == 'selected-id-subset'
            for report in reports
        ))

        missing, complete = merge_reports(
            [reports[0], None, reports[2], reports[3], reports[4]],
            [processes[0], {
                **processes[1], 'exit_code': None, 'timed_out': True,
                'report_present': False, 'report_valid': False,
                'report_successful': None,
                'coverage_complete': False, 'observed_test_ids': [],
            }, processes[2], processes[3], processes[4]],
            expected_ids, 0.5,
        )
        self.assertFalse(complete)
        self.assertFalse(missing['partition_proof']['complete'])
        self.assertNotIn(
            'partition_selection', missing['runtime']['suite_settings'],
        )

        duplicate_report = dict(reports[1])
        duplicate_report['tests'] = list(reports[0]['tests'])
        duplicate_report['counts'] = dict(reports[0]['counts'])
        duplicate_processes = [dict(process) for process in processes]
        duplicate_processes[1]['observed_test_ids'] = expected_ids[:1]
        duplicate_processes[1]['coverage_complete'] = False
        duplicate, duplicate_success = merge_reports(
            [reports[0], duplicate_report, reports[2], reports[3], reports[4]],
            duplicate_processes,
            expected_ids, 0.5,
        )
        self.assertFalse(duplicate_success)
        self.assertNotIn(
            'partition_selection', duplicate['runtime']['suite_settings'],
        )
        self.assertFalse(duplicate_success)
        self.assertFalse(duplicate['partition_proof']['complete'])
        self.assertFalse(duplicate['partition_proof']['exact_union'])
        self.assertEqual(
            [expected_ids[0], expected_ids[0], expected_ids[2], expected_ids[3],
             expected_ids[4]],
            [test['id'] for test in duplicate['tests']],
        )
        self.assertEqual(5, duplicate['counts']['total'])

        failed_report = copy.deepcopy(reports[0])
        failed_report['successful'] = False
        failed_report['tests'][0]['status'] = 'FAIL'
        failed_report['tests'][0]['reason'] = 'Controlled assertion failure.'
        failed_report['counts']['passed'] = 0
        failed_report['counts']['failed'] = 1
        self.assertTrue(validate_child_report(failed_report))
        failed_processes = [dict(process) for process in processes]
        failed_processes[0]['exit_code'] = 1
        failed_processes[0]['report_successful'] = False
        failed, complete = merge_reports(
            [failed_report, reports[1], reports[2], reports[3], reports[4]],
            failed_processes, expected_ids, 0.5,
        )
        self.assertFalse(complete)
        self.assertFalse(failed['successful'])

        failed_exit_processes = [dict(process) for process in processes]
        failed_exit_processes[0]['exit_code'] = 9
        failed_exit, complete = merge_reports(
            reports, failed_exit_processes, expected_ids, 0.5,
        )
        self.assertFalse(complete)
        self.assertFalse(failed_exit['successful'])

    def report_case(self, case_type: type[unittest.TestCase]) -> AuditReport:
        """Run one local fixture case through the public structured report seam.

        Args:
            case_type (type[unittest.TestCase]): Locally defined synthetic test class.
        Returns:
            AuditReport: Complete report produced from that one class suite.
        """
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(case_type)
        return report_suite(suite, io.StringIO())

    def test_success_and_runtime_are_reported(self) -> None:
        """Record a successful test by its canonical identity and runtime profile.

        Returns:
            None: The assertions verify the success record and runtime metadata.
        """
        class PassingCase(unittest.TestCase):
            """Supply one successful synthetic test case."""

            def test_pass(self) -> None:
                """Complete without an error.

                Returns:
                    None: The synthetic test succeeds without a value.
                """
                self.assertTrue(True)

        report = self.report_case(PassingCase)
        expected_case = PassingCase("test_pass")
        test = report["tests"][0]
        self.assertEqual(report["schema_version"], 1)
        self.assertTrue(report["successful"])
        self.assertEqual(test["id"], expected_case.id())
        self.assertEqual(test["status"], "PASS")
        self.assertIsNone(test["reason"])
        self.assertGreaterEqual(test["duration_seconds"], 0.0)
        self.assertEqual(report["counts"]["total"], 1)
        self.assertEqual(report["counts"]["passed"], 1)
        self.assertIn("python_version", report["runtime"])
        self.assertIn("python_implementation", report["runtime"])
        self.assertIn("platform", report["runtime"])
        self.assertIn("executable", report["runtime"])

    def test_skip_reason_is_preserved(self) -> None:
        """Preserve the factual reason attached to a normal unittest skip.

        Returns:
            None: The assertions verify named skip status and reason.
        """
        class SkippedCase(unittest.TestCase):
            """Supply one deliberately skipped synthetic test case."""

            def test_skip(self) -> None:
                """Skip with a stable explanatory reason.

                Returns:
                    None: The synthetic test reports a skip instead of returning.
                """
                self.skipTest("controlled skip reason")

        report = self.report_case(SkippedCase)
        self.assertTrue(report["successful"])
        self.assertEqual(report["tests"][0]["status"], "SKIP")
        self.assertEqual(report["tests"][0]["reason"], "controlled skip reason")
        self.assertEqual(report["counts"]["skipped"], 1)

    def test_failure_and_error_have_distinct_outcomes(self) -> None:
        """Separate assertion failures from unexpected exceptions and keep details.

        Returns:
            None: The assertions verify distinct failing and error records.
        """
        class FailedCase(unittest.TestCase):
            """Supply one assertion failure."""

            def test_failure(self) -> None:
                """Raise a regular unittest assertion failure.

                Returns:
                    None: The test fails by raising an assertion exception.
                """
                self.fail("controlled assertion failure")

        class ErroredCase(unittest.TestCase):
            """Supply one unexpected test exception."""

            def test_error(self) -> None:
                """Raise an unexpected exception during the test body.

                Returns:
                    None: The test errors by raising a value exception.
                """
                raise ValueError("controlled test error")

        failure = self.report_case(FailedCase)
        error = self.report_case(ErroredCase)
        self.assertFalse(failure["successful"])
        self.assertEqual(failure["tests"][0]["status"], "FAIL")
        self.assertIn("controlled assertion failure", failure["tests"][0]["reason"])
        self.assertFalse(error["successful"])
        self.assertEqual(error["tests"][0]["status"], "ERROR")
        self.assertIn("controlled test error", error["tests"][0]["reason"])

    def test_subtest_failure_and_skip_are_individual(self) -> None:
        """Report subtest assertion and skip outcomes under their parent test.

        Returns:
            None: The assertions verify individual nested outcomes and counts.
        """
        class SubtestCase(unittest.TestCase):
            """Supply one failing and one skipped subtest."""

            def test_subtests(self) -> None:
                """Exercise distinct failure and skip outcomes in one test.

                Returns:
                    None: The subtests emit their outcomes through unittest callbacks.
                """
                with self.subTest("failure"):
                    self.fail("controlled subtest failure")
                with self.subTest("skip"):
                    self.skipTest("controlled subtest skip")

        report = self.report_case(SubtestCase)
        parent = report["tests"][0]
        self.assertFalse(report["successful"])
        self.assertEqual(parent["status"], "FAIL")
        self.assertEqual([item["status"] for item in parent["subtests"]], ["FAIL", "SKIP"])
        self.assertIn("controlled subtest failure", parent["subtests"][0]["reason"])
        self.assertIn("controlled subtest skip", parent["subtests"][1]["reason"])
        self.assertEqual(report["counts"]["subtest_failures"], 1)
        self.assertEqual(report["counts"]["subtest_skips"], 1)

    def test_class_setup_error_is_separate_fixture_evidence(self) -> None:
        """Retain class setup failures even when no ordinary test starts.

        Returns:
            None: The assertions verify named setup fixture evidence.
        """
        class SetupErrorCase(unittest.TestCase):
            """Supply a failing class-level setup fixture."""

            @classmethod
            def setUpClass(cls) -> None:
                """Raise a controlled error before test methods run.

                Returns:
                    None: Class setup fails before the test body is reached.
                """
                raise RuntimeError("controlled class setup error")

            def test_unreached(self) -> None:
                """Remain unexecuted when class setup fails.

                Returns:
                    None: This body is unreachable after class setup failure.
                """
                self.fail("class setup should prevent this test")

        report = self.report_case(SetupErrorCase)
        self.assertFalse(report["successful"])
        self.assertEqual(report["tests"], [])
        self.assertEqual(report["counts"]["total"], 0)
        self.assertEqual(report["counts"]["fixture_errors"], 1)
        self.assertEqual(report["fixture_errors"][0]["phase"], "setUpClass")
        self.assertIn("controlled class setup error", report["fixture_errors"][0]["reason"])

    def test_teardown_error_is_not_a_pass(self) -> None:
        """Turn a test teardown exception into an explicit ERROR outcome.

        Returns:
            None: The assertions verify teardown failure supersedes body success.
        """
        class TeardownErrorCase(unittest.TestCase):
            """Supply a passing body followed by a failing teardown."""

            def test_body(self) -> None:
                """Complete the body before teardown raises.

                Returns:
                    None: The body succeeds before teardown emits an error.
                """
                self.assertTrue(True)

            def tearDown(self) -> None:
                """Raise a controlled teardown exception.

                Returns:
                    None: Teardown always raises RuntimeError for the report.
                """
                raise RuntimeError("controlled teardown error")

        report = self.report_case(TeardownErrorCase)
        self.assertFalse(report["successful"])
        self.assertEqual(report["tests"][0]["status"], "ERROR")
        self.assertIn("controlled teardown error", report["tests"][0]["reason"])

    def test_teardown_skip_does_not_erase_body_failure(self) -> None:
        """Keep an assertion failure when teardown later reports a skip.

        Returns:
            None: The assertions verify status precedence and both factual reasons.
        """
        class FailureThenSkipCase(unittest.TestCase):
            """Supply a failing body followed by a skipped teardown."""

            def test_body(self) -> None:
                """Fail before teardown reports its independent skip.

                Returns:
                    None: The body emits the primary assertion failure.
                """
                self.fail("controlled body failure")

            def tearDown(self) -> None:
                """Report a skip after the body has already failed.

                Returns:
                    None: Teardown emits the later lower-priority skip outcome.
                """
                self.skipTest("controlled teardown skip")

        report = self.report_case(FailureThenSkipCase)
        test = report["tests"][0]
        self.assertFalse(report["successful"])
        self.assertEqual(test["status"], "FAIL")
        self.assertIn("controlled body failure", test["reason"])
        self.assertIn("controlled teardown skip", test["reason"])

    def test_subtest_error_remains_stronger_than_later_failure(self) -> None:
        """Retain an earlier subtest error and both reasons after a later failure.

        Returns:
            None: The assertions verify severity ordering and reason preservation.
        """
        class ErrorThenFailureCase(unittest.TestCase):
            """Supply an errored subtest followed by an assertion failure."""

            def test_subtests(self) -> None:
                """Emit an unexpected error and then a separate assertion failure.

                Returns:
                    None: Both nested outcomes reach the structured result callback.
                """
                with self.subTest("error child"):
                    raise ValueError("controlled subtest error")
                with self.subTest("failure child"):
                    self.fail("controlled later subtest failure")

        report = self.report_case(ErrorThenFailureCase)
        test = report["tests"][0]
        self.assertFalse(report["successful"])
        self.assertEqual(test["status"], "ERROR")
        self.assertEqual([item["status"] for item in test["subtests"]], ["ERROR", "FAIL"])
        self.assertIn("controlled subtest error", test["reason"])
        self.assertIn("controlled later subtest failure", test["reason"])

    def test_expected_failure_and_unexpected_success_are_explicit(self) -> None:
        """Keep expected failures and unexpected successes distinct from PASS.

        Returns:
            None: The assertions verify both distinct expected-failure outcomes.
        """
        class ExpectedFailureCase(unittest.TestCase):
            """Supply one expected failure accepted by unittest semantics."""

            @unittest.expectedFailure
            def test_expected_failure(self) -> None:
                """Fail in the way this fixture explicitly expects.

                Returns:
                    None: The expected assertion failure is reported distinctly.
                """
                self.fail("controlled expected failure")

        class UnexpectedSuccessCase(unittest.TestCase):
            """Supply one test that violates its expected-failure marker."""

            @unittest.expectedFailure
            def test_unexpected_success(self) -> None:
                """Pass despite the expected-failure marker.

                Returns:
                    None: The unexpected success is reported distinctly.
                """
                self.assertTrue(True)

        expected = self.report_case(ExpectedFailureCase)
        unexpected = self.report_case(UnexpectedSuccessCase)
        self.assertTrue(expected["successful"])
        self.assertEqual(expected["tests"][0]["status"], "EXPECTED_FAILURE")
        self.assertEqual(expected["counts"]["expected_failures"], 1)
        self.assertFalse(unexpected["successful"])
        self.assertEqual(unexpected["tests"][0]["status"], "UNEXPECTED_SUCCESS")
        self.assertEqual(unexpected["counts"]["unexpected_successes"], 1)

    def test_class_teardown_error_is_a_fixture_error(self) -> None:
        """Preserve class teardown errors independently of passing test bodies.

        Returns:
            None: The assertions verify separate class teardown evidence.
        """
        class TeardownClassErrorCase(unittest.TestCase):
            """Supply a passing test followed by a failing class teardown."""

            def test_body(self) -> None:
                """Complete normally before class teardown executes.

                Returns:
                    None: The body succeeds before the fixture error is emitted.
                """
                self.assertTrue(True)

            @classmethod
            def tearDownClass(cls) -> None:
                """Raise a controlled class teardown exception.

                Returns:
                    None: Class teardown always raises RuntimeError for the report.
                """
                raise RuntimeError("controlled class teardown error")

        report = self.report_case(TeardownClassErrorCase)
        self.assertFalse(report["successful"])
        self.assertEqual(report["tests"][0]["status"], "PASS")
        self.assertEqual(report["fixture_errors"][0]["phase"], "tearDownClass")
        self.assertEqual(report["counts"]["errors"], 1)

    def test_duplicate_canonical_identity_cannot_report_success(self) -> None:
        """Reject a complete-looking suite whose cases share one canonical ID.

        Returns:
            None: The assertions verify duplicate identity makes success false.
        """
        class DuplicateIdentityCase(unittest.TestCase):
            """Supply passing tests that intentionally collide by identity."""

            def id(self) -> str:
                """Return the same identity for both synthetic instances.

                Returns:
                    str: The deliberately duplicated canonical identity.
                """
                return "synthetic.duplicate"

            def test_duplicate(self) -> None:
                """Complete successfully while exposing the duplicate identity.

                Returns:
                    None: The synthetic test succeeds with a colliding ID.
                """
                self.assertTrue(True)

        suite = unittest.TestSuite([
            DuplicateIdentityCase("test_duplicate"),
            DuplicateIdentityCase("test_duplicate"),
        ])
        report = report_suite(suite, io.StringIO())
        self.assertFalse(report["successful"])
        self.assertEqual(report["counts"]["total"], 2)
        self.assertEqual([test["id"] for test in report["tests"]], ["synthetic.duplicate"] * 2)

    def test_empty_suite_is_not_successful(self) -> None:
        """Reject an empty discovery result as an unsuccessful adapter report.

        Returns:
            None: The assertion verifies that empty discovery cannot qualify.
        """
        report = report_suite(unittest.TestSuite(), io.StringIO())
        self.assertFalse(report["successful"])
        self.assertEqual(report["counts"]["total"], 0)
        self.assertEqual(report["tests"], [])

    def test_fixture_skip_is_named_separately(self) -> None:
        """Retain a class setup skip as fixture evidence with its reason.

        Returns:
            None: The assertions verify named fixture skip accounting.
        """
        class SetupSkipCase(unittest.TestCase):
            """Supply a deliberately skipped class setup fixture."""

            @classmethod
            def setUpClass(cls) -> None:
                """Skip class execution before any test case starts.

                Returns:
                    None: Setup reports a controlled fixture skip.
                """
                raise unittest.SkipTest("controlled fixture skip")

            def test_unreached(self) -> None:
                """Remain unexecuted after class setup skips.

                Returns:
                    None: This body is unreachable after fixture skip.
                """
                self.fail("class setup skip should prevent this test")

        report = self.report_case(SetupSkipCase)
        self.assertTrue(report["successful"])
        self.assertEqual(report["tests"], [])
        self.assertEqual(report["counts"]["fixture_skips"], 1)
        self.assertEqual(report["fixture_skips"][0]["phase"], "setUpClass")
        self.assertIn("controlled fixture skip", report["fixture_skips"][0]["reason"])

    def test_skipped_subtest_has_named_child_and_parent_skip(self) -> None:
        """Retain a subtest skip while avoiding an inferred parent pass.

        Returns:
            None: The assertions verify both child evidence and parent semantics.
        """
        class SubtestSkipCase(unittest.TestCase):
            """Supply one subtest that terminates the parent outcome as skipped."""

            def test_subtest_skip(self) -> None:
                """Skip one nested test operation.

                Returns:
                    None: The unittest skip callback records nested evidence.
                """
                with self.subTest("controlled child"):
                    self.skipTest("controlled subtest skip")

        report = self.report_case(SubtestSkipCase)
        parent = report["tests"][0]
        self.assertTrue(report["successful"])
        self.assertEqual(parent["status"], "SKIP")
        self.assertIn("did not report parent success", parent["reason"])
        self.assertEqual(parent["subtests"][0]["status"], "SKIP")
        self.assertIn("controlled subtest skip", parent["subtests"][0]["reason"])
        self.assertEqual(report["counts"]["subtest_skips"], 1)

    def test_repeated_subtest_identity_keeps_every_event(self) -> None:
        """Keep repeated canonical subtest labels by adding event ordinals.

        Returns:
            None: The assertions verify distinct identities for repeated outcomes.
        """
        class RepeatedSubtestCase(unittest.TestCase):
            """Supply identical failing subtest descriptions twice."""

            def test_repeated(self) -> None:
                """Emit two failures with the same original subtest identity.

                Returns:
                    None: Both events are retained as distinct report rows.
                """
                for _ in range(2):
                    with self.subTest("same child"):
                        self.fail("repeated subtest failure")

        report = self.report_case(RepeatedSubtestCase)
        children = report["tests"][0]["subtests"]
        self.assertEqual(len(children), 2)
        self.assertNotEqual(children[0]["id"], children[1]["id"])


if __name__ == "__main__":
    unittest.main()
