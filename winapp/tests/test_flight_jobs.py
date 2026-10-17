# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_flight_jobs.py
# Module Type: Windows Tooling Regression Test
# Purpose: Verify fail-closed durable flight task recovery and key binding.
# Responsibilities:
#   - Exercise preference and completion manifests across restart boundaries.
#   - Guard delivery receipts, unfinished sessions and workspace ownership.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Regression coverage for durable flight plans and connection ownership."""

import json
import tempfile
import unittest
from pathlib import Path

from uploader.flight_jobs import (
    FlightPreferences,
    ImportBindings,
    SessionPlan,
    WorkspaceLease,
    connection_binding,
    pending_sessions,
)


# Type: str; Units: hexadecimal SHA-256 characters; Scope: test receipts.
# Consumers: SessionPlan fixtures and recovery assertions.
# Rationale: valid fixed digests isolate manifest validation from PDF work.
RECEIPT_DIGEST = "a" * 64
OTHER_RECEIPT_DIGEST = "b" * 64


class FlightJobsTests(unittest.TestCase):
    """Verify manifests recover only when every identity check is sound."""

    def _plan(
        self,
        directory: Path,
        binding: str,
        languages: tuple[str, ...] = ("en", "cs"),
        reports_enabled: bool = True,
        completed: bool = False,
        local_only: bool = False,
        abandoned: bool = False,
        receipts: dict[str, str] | None = None,
    ) -> SessionPlan:
        """Create and durably save one representative flight plan.

        Args:
            self (FlightJobsTests): Test case creating the plan.
            directory (Path): Session folder owned by the fixture.
            binding (str): Secret-free endpoint/key fingerprint.
            languages (tuple[str, ...]): Required report editions.
            reports_enabled (bool): Whether reports are required.
            completed (bool): Whether all durable work has completed.
            local_only (bool): Whether uploads were explicitly skipped.
            abandoned (bool): Whether analysis was explicitly abandoned.
            receipts (dict[str, str] | None): Confirmed language checksums.

        Returns:
            SessionPlan: Persisted plan ready for recovery assertions.
        """
        directory.mkdir(parents=True, exist_ok=True)
        plan = SessionPlan(
            directory=directory,
            binding=binding,
            languages=languages,
            reports_enabled=reports_enabled,
            completed=completed,
            local_only=local_only,
            abandoned=abandoned,
            receipts={} if receipts is None else dict(receipts),
        )
        plan.save()
        return plan

    def test_connection_binding_is_stable_and_credential_free(self) -> None:
        """Bind exact endpoint and key without exposing either as the digest.

        Args:
            self (FlightJobsTests): Test case instance running this check.

        Returns:
            None: Assert stable identity and sensitivity to either input.
        """
        first = connection_binding("https://gallery.test/api", "secret-one")
        same = connection_binding("https://gallery.test/api", "secret-one")
        changed_key = connection_binding("https://gallery.test/api", "secret-two")
        changed_url = connection_binding("https://other.test/api", "secret-one")
        self.assertEqual(first, same)
        self.assertNotEqual(first, changed_key)
        self.assertNotEqual(first, changed_url)
        self.assertNotIn("secret-one", first)

    def test_preferences_roundtrip_and_strict_boolean_restore(self) -> None:
        """Persist only recognized preferences and reject truthy string flags.

        Args:
            self (FlightJobsTests): Test case instance running this check.

        Returns:
            None: Assert stable language selection and strict boolean handling.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            preferences = FlightPreferences(
                recording=False,
                reports=True,
                languages=("cs", "sv"),
                ui_language="de",
            )
            preferences.save(root)
            loaded = FlightPreferences.load(root)
            self.assertFalse(loaded.recording)
            self.assertTrue(loaded.reports)
            self.assertEqual(loaded.languages, ("cs", "sv"))
            self.assertEqual(loaded.ui_language, "de")

            path = root / "preferences.json"
            row = json.loads(path.read_text(encoding="utf-8"))
            row["recording"] = "true"
            path.write_text(json.dumps(row), encoding="utf-8")
            self.assertFalse(FlightPreferences.load(root).recording)

    def test_corrupt_preferences_fail_closed(self) -> None:
        """Refuse malformed durable settings rather than guessing user choices.

        Args:
            self (FlightJobsTests): Test case instance running this check.

        Returns:
            None: Assert invalid JSON and malformed language containers fail.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            path = root / "preferences.json"
            path.write_text("{", encoding="utf-8")
            with self.assertRaises(ValueError):
                FlightPreferences.load(root)
            path.write_text('{"languages": "en"}', encoding="utf-8")
            with self.assertRaises(ValueError):
                FlightPreferences.load(root)

    def test_completed_remote_restore_requires_every_receipt(self) -> None:
        """Require a validated receipt for each remotely completed language.

        Args:
            self (FlightJobsTests): Test case instance running this check.

        Returns:
            None: Restore a valid plan and reject omitted or malformed receipts.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            binding = connection_binding("https://gallery.test/api", "key")
            directory = root / "flight-one"
            plan = self._plan(
                directory,
                binding,
                completed=True,
                receipts={"en": RECEIPT_DIGEST, "cs": OTHER_RECEIPT_DIGEST},
            )
            restored = SessionPlan.load(directory)
            self.assertEqual(restored.binding, plan.binding)
            self.assertEqual(restored.receipts, plan.receipts)

            plan.receipts.pop("cs")
            plan.save()
            with self.assertRaises(ValueError):
                SessionPlan.load(directory)

            plan.receipts["cs"] = "not-a-sha256"
            plan.save()
            with self.assertRaises(ValueError):
                SessionPlan.load(directory)

    def test_completed_plan_with_pending_photo_drain_is_rejected(self) -> None:
        """Reject completed state while a durable photograph queue is pending.

        Args:
            self (FlightJobsTests): Test case instance running this check.

        Returns:
            None: Assert flight completion cannot hide accepted photo work.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / "flight-photo"
            binding = connection_binding("https://gallery.test/api", "key")
            self._plan(
                directory,
                binding,
                completed=True,
                receipts={"en": RECEIPT_DIGEST, "cs": OTHER_RECEIPT_DIGEST},
            )
            (directory / "watch-drain.json").write_text(
                '{"pending": true}', encoding="utf-8"
            )
            with self.assertRaises(ValueError):
                SessionPlan.load(directory)

    def test_pending_sessions_are_connection_scoped_and_corruption_blocks(self) -> None:
        """Recover only matching unfinished tasks and fail closed on corruption.

        Args:
            self (FlightJobsTests): Test case instance running this check.

        Returns:
            None: Assert stable ordering, connection filtering and corruption.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            binding = connection_binding("https://gallery.test/api", "key-one")
            other = connection_binding("https://gallery.test/api", "key-two")
            matching = self._plan(root / "flight-b", binding)
            self._plan(root / "flight-a", other)
            found = pending_sessions(root, binding)
            self.assertEqual([plan.session_id for plan in found], [matching.session_id])

            corrupt = root / "flight-corrupt"
            corrupt.mkdir()
            (corrupt / "completion.json").write_text("{", encoding="utf-8")
            with self.assertRaises(ValueError):
                pending_sessions(root, binding)

    def test_local_only_and_abandonment_restore_as_explicit_choices(self) -> None:
        """Restore local-only and abandoned states without remote receipts.

        Args:
            self (FlightJobsTests): Test case instance running this check.

        Returns:
            None: Assert decisions remain durable and leave no pending work.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            binding = connection_binding("https://gallery.test/api", "key")
            local = self._plan(
                root / "flight-local",
                binding,
                completed=True,
                local_only=True,
            )
            abandoned = self._plan(
                root / "flight-abandoned",
                binding,
                reports_enabled=False,
                completed=True,
                abandoned=True,
            )
            self.assertTrue(SessionPlan.load(local.directory).local_only)
            self.assertTrue(SessionPlan.load(abandoned.directory).abandoned)
            self.assertEqual(pending_sessions(root, binding), [])

    def test_manual_job_bindings_reject_another_connection(self) -> None:
        """Keep accepted upload jobs attached to their original gallery key.

        Args:
            self (FlightJobsTests): Test case instance running this check.

        Returns:
            None: Assert idempotent binding, mismatch refusal and scoped resolve.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            ledger = ImportBindings(root)
            first = connection_binding("https://gallery.test/api", "key-one")
            second = connection_binding("https://gallery.test/api", "key-two")
            ledger.bind("job-1", first)
            ledger.bind("job-2", second)
            ledger.bind("job-1", first)
            with self.assertRaises(ValueError):
                ledger.bind("job-1", second)
            ledger.resolve("job-1")
            self.assertEqual(ledger.entries(), {"job-2": second})

    def test_workspace_lease_is_exclusive_and_releasable(self) -> None:
        """Ensure one live process owns a workspace until release.

        Args:
            self (FlightJobsTests): Test case instance running this check.

        Returns:
            None: Assert conflicting acquisition fails and later acquisition works.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            first = WorkspaceLease(root)
            try:
                with self.assertRaises(RuntimeError):
                    WorkspaceLease(root)
            finally:
                first.release()
                first.release()
            second = WorkspaceLease(root)
            second.release()


if __name__ == "__main__":
    unittest.main()
