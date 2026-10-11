# Project: PHP Gallery
# Module Type: Regression Test
# Purpose: Verify structured per-test reporting for canonical WinApp unittest discovery.
# Responsibilities:
#   - Cover individual unittest outcomes, subtests and fixture errors.
#   - Protect report completeness, uniqueness, counts and native unittest success semantics.
# File: winapp/tests/test_audit_reporting.py
# Repository: https://github.com/klusik/PHP_gallery
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Verify schema-versioned WinApp audit reports without running the production suite."""

import io
import sys
import unittest
from pathlib import Path

project_root = Path(__file__).resolve().parents[2]
if str(project_root) not in sys.path:
    sys.path.insert(0, str(project_root))

from scripts.audit_winapp_report import AuditReport, report_suite


class AuditReportingTests(unittest.TestCase):
    """Protect structured WinApp unittest outcomes and report accounting."""

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
