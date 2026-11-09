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
import os
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
from pathlib import Path
from uploader.app_linking import UPLOAD_SCOPE

from uploader.flight_jobs import (
    FlightPreferences,
    ImportBindings,
    SessionPlan,
    WorkspaceLease,
    WatcherContinuation,
    WatcherContinuationError,
    WATCHER_CONTINUATION_LIFETIME_SECONDS,
    connection_binding,
    pending_sessions,
    saved_flights,
    report_revisions,
    organize_reports,
    allocate_report_revision,
    report_file_details,
)
from uploader.models import ImportItem, ImportJob
from uploader.state_store import JobStateStore


# Type: str; Units: hexadecimal SHA-256 characters; Scope: test receipts.
# Consumers: SessionPlan fixtures and recovery assertions.
# Rationale: valid fixed digests isolate manifest validation from PDF work.
RECEIPT_DIGEST = "a" * 64
OTHER_RECEIPT_DIGEST = "b" * 64


class FlightJobsTests(unittest.TestCase):
    """Verify manifests recover only when every identity check is sound."""

    def test_report_organization_preserves_bytes_identity_and_restarts_safely(self) -> None:
        """Flatten automatic/manual editions with private metadata and stable IDs.

        Returns:
            None: Repeated organization and a resumed catalog journal preserve
                all PDF bytes and completion state; new editions use flat names.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / "flight"
            reports = directory / "reports"
            manual = reports / "manual-old"
            manual.mkdir(parents=True)
            (directory / "session.json").write_text(json.dumps({
                "started_utc": "2026-10-18T09:46:05Z", "aircraft": "Fenix A320",
            }), encoding="utf-8")
            first = reports / "auto-en.pdf"
            second = manual / "cs.pdf"
            first.write_bytes(b"first exact PDF")
            second.write_bytes(b"second exact PDF")
            first.with_suffix(".pdf.manifest.json").write_text('{"receipt":"unchanged"}', encoding="utf-8")
            organize_reports(directory)
            organize_reports(directory)
            revisions = {item.report_id: item for item in report_revisions(directory)}
            self.assertEqual(b"first exact PDF", revisions["auto"].files["en"].read_bytes())
            self.assertEqual(b"second exact PDF", revisions["flight-manual-old"].files["cs"].read_bytes())
            self.assertFalse(manual.exists())
            self.assertEqual([], list(reports.glob("*.json")))
            self.assertTrue((reports / ".metadata" / (revisions["auto"].files["en"].name + ".manifest.json")).is_file())
            newest = allocate_report_revision(directory, "new-revision", ("en", "cs"))
            self.assertTrue(all(pdf.parent == reports and "Fenix A320" in pdf.name for pdf in newest.files.values()))
            self.assertEqual(newest.files, allocate_report_revision(directory, "new-revision", ("en", "cs")).files)
            # Simulate interruption after the journal but before one PDF rename.
            moved = revisions["auto"].files["en"]
            moved.replace(first)
            organize_reports(directory)
            self.assertEqual(b"first exact PDF", moved.read_bytes())

    def test_report_revisions_keep_language_sets_and_stable_manual_identity(self) -> None:
        """Catalog automatic and manual editions separately for explicit upload.

        Returns:
            None: Revision selection never mixes languages across generations
                and manual identities retain the originating session prefix.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / "flight"
            reports = directory / "reports"
            manual = reports / "manual-test"
            manual.mkdir(parents=True)
            (reports / "auto-en.pdf").write_bytes(b"automatic")
            (manual / "cs.pdf").write_bytes(b"manual")
            rows = {row.report_id: row.files for row in report_revisions(directory)}
            self.assertEqual({"en": reports / "auto-en.pdf"}, rows["auto"])
            self.assertEqual({"cs": manual / "cs.pdf"}, rows["flight-manual-test"])

    def test_catalog_exposes_reserved_numbers_independently_of_file_time(self) -> None:
        """Keep stable version numbers when an older PDF gets a newer mtime.

        Returns:
            None: Passive catalog reads preserve reserved numbers, exact PDF
                bytes and paths while retaining their legacy mtime list order.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / "flight"
            directory.mkdir()
            (directory / "session.json").write_text(json.dumps({
                "started_utc": "2026-10-18T09:46:05Z", "aircraft": "A320",
            }), encoding="utf-8")
            first = allocate_report_revision(directory, "first", ("en",))
            second = allocate_report_revision(directory, "second", ("en",))
            first.files["en"].write_bytes(b"first PDF")
            second.files["en"].write_bytes(b"second PDF")
            # Distinct fixed POSIX mtimes deliberately reverse revision order.
            os.utime(first.files["en"], (200, 200))
            os.utime(second.files["en"], (100, 100))
            rows = report_revisions(directory)
            self.assertEqual(["first", "second"], [row.report_id for row in rows])
            self.assertEqual([1, 2], [row.number for row in rows])
            self.assertEqual(first.files, rows[0].files)
            self.assertEqual(second.files, rows[1].files)
            self.assertEqual(b"first PDF", first.files["en"].read_bytes())
            self.assertEqual(b"second PDF", second.files["en"].read_bytes())

    def test_catalog_rejects_invalid_display_numbers_without_modifying_pdfs(self) -> None:
        """Refuse malformed numeric versions before history tries to sort them.

        Returns:
            None: Missing, Boolean, text and nonpositive numbers raise a
                recoverable ValueError while preserving the retained PDF.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / "flight"
            directory.mkdir()
            (directory / "session.json").write_text(json.dumps({
                "started_utc": "2026-10-18T09:46:05Z", "aircraft": "A320",
            }), encoding="utf-8")
            revision = allocate_report_revision(directory, "first", ("en",))
            revision.files["en"].write_bytes(b"retained PDF")
            catalog = directory / "reports" / ".metadata" / "catalog.json"
            payload = json.loads(catalog.read_text(encoding="utf-8"))
            # Boundary vectors are invalid display identities, never defaults.
            for number in (None, True, "2", 0, -1):
                with self.subTest(number=number):
                    if number is None:
                        payload["revisions"]["first"].pop("number")
                    else:
                        payload["revisions"]["first"]["number"] = number
                    catalog.write_text(json.dumps(payload), encoding="utf-8")
                    with self.assertRaises(ValueError):
                        report_revisions(directory)
                    self.assertEqual(b"retained PDF", revision.files["en"].read_bytes())

    def test_revision_catalog_refuses_redirected_pdf_files(self) -> None:
        """Reject a retained edition redirected outside its session directory.

        Returns:
            None: File symlinks are refused; runners lacking symlink privileges
                report this filesystem-specific case as skipped.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            reports = root / "flight" / "reports"
            reports.mkdir(parents=True)
            external = root / "external.pdf"
            external.write_bytes(b"external PDF")
            try:
                (reports / "auto-en.pdf").symlink_to(external)
            except OSError:
                self.skipTest("Symlink creation is unavailable on this runner.")
            with self.assertRaises(ValueError):
                report_revisions(reports.parent)

    def test_saved_catalog_keeps_other_keys_and_corrupt_rows_visible(self) -> None:
        """List local artifacts without conflating them with pending delivery.

        Returns:
            None: Historical-key flights and old/manual PDFs remain discoverable;
                corrupt sessions have an explicit error without hiding good rows.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            binding = connection_binding("https://gallery.test/api", "old-key")
            plan = self._plan(root / "good", binding, reports_enabled=False)
            (plan.directory / "session.json").write_text(json.dumps({
                "started_utc": "2026-10-18T10:00:00+00:00",
                "sample_count": 123,
                "aircraft": "Fenix A320",
                "recording_ended": True,
            }), encoding="utf-8")
            reports = plan.directory / "reports"
            manual = reports / "manual-fixture"
            manual.mkdir(parents=True)
            (reports / "old.pdf").write_bytes(b"old PDF")
            (manual / "cs.pdf").write_bytes(b"local PDF")
            corrupt = root / "corrupt"
            corrupt.mkdir()
            (corrupt / "session.json").write_text("{broken", encoding="utf-8")
            rows = saved_flights(root)
            self.assertEqual(2, len(rows))
            good = next(row for row in rows if row.directory.name == "good")
            bad = next(row for row in rows if row.directory.name == "corrupt")
            self.assertEqual(123, good.sample_count)
            self.assertEqual(2, good.report_count)
            self.assertTrue(good.recording_ended)
            self.assertIn("ValueError", bad.error)
            self.assertEqual(binding, SessionPlan.load(plan.directory).binding)

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

        Returns:
            None: Assert persisted-job admission, idempotent binding and refusal
                to change a job's original connection.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            ledger = ImportBindings(root / "flights")
            store = JobStateStore(root / "upload_state.json")
            first_job = ImportJob.create("files", "first", "Gallery")
            second_job = ImportJob.create("files", "second", "Gallery")
            first_job.items = [ImportItem(
                "first-item", "/unused/first.jpg", "first.jpg",
            )]
            second_job.items = [ImportItem(
                "second-item", "/unused/second.jpg", "second.jpg",
            )]
            store.save_job(first_job)
            store.save_job(second_job)
            first = connection_binding("https://gallery.test/api", "key-one")
            second = connection_binding("https://gallery.test/api", "key-two")
            ledger.bind(first_job.id, first)
            ledger.bind(second_job.id, second)
            ledger.bind(first_job.id, first)
            with self.assertRaises(ValueError):
                ledger.bind(first_job.id, second)
            with self.assertRaises(ValueError):
                ledger.resolve(first_job.id)
            first_job.items[0].transition("skipped_duplicate")
            first_job.finished_at = 1.0
            store.save_job(first_job)
            ledger.resolve(first_job.id)
            self.assertEqual(ledger.entries(), {
                first_job.id: first, second_job.id: second,
            })

    def test_workspace_lease_is_exclusive_and_releasable(self) -> None:
        """Ensure one live process owns a workspace until release.

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

    def test_watcher_continuation_save_load_and_strict_payload(self) -> None:
        """Round-trip one bounded update continuation under its workspace lease.

        Returns:
            None: Exact non-secret fields survive, while duplicate keys,
                non-finite timestamps, wrong profiles and missing nonces fail.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            lease = WorkspaceLease(root)
            try:
                record = WatcherContinuation(
                    session_id="c" * 32,
                    binding="a" * 64,
                    profile_hash=WatcherContinuation.profile_digest(root),
                    source_version="1.0.0",
                    target_version="1.0.1",
                    update_session="d" * 32,
                    mode="linked",
                    contextual=True,
                    approved_scope=UPLOAD_SCOPE,
                    created=time.time(),
                    phase="prepared",
                )
                record.save(lease)
                self.assertEqual(record, WatcherContinuation.load(lease))
                with patch(
                    "uploader.flight_jobs.time.time",
                    return_value=(record.created
                                  + WATCHER_CONTINUATION_LIFETIME_SECONDS + 1.0),
                ), self.assertRaises(WatcherContinuationError) as expired:
                    WatcherContinuation.load(lease)
                self.assertEqual("expired", expired.exception.reason)
                self.assertNotIn(str(root), str(expired.exception))

                payload = record.payload()
                with self.assertRaises(WatcherContinuationError) as profile:
                    WatcherContinuation.from_payload(
                        {**payload, "profile_hash": "b" * 64}, root,
                    )
                self.assertEqual("profile_mismatch", profile.exception.reason)
                self.assertNotIn(str(root), str(profile.exception))
                with self.assertRaises(ValueError):
                    WatcherContinuation.from_payload(
                        {**payload, "update_session": ""}, root,
                    )
                for field, invalid in (
                    ("binding", "not-a-digest"),
                    ("source_version", "v1.0.0"),
                    ("target_version", "1.0"),
                    ("mode", "unknown"),
                    ("legacy_source_pid", True),
                ):
                    with self.subTest(field=field), self.assertRaises(ValueError):
                        WatcherContinuation.from_payload(
                            {**payload, field: invalid}, root,
                        )

                path = root / "watcher-continuation.json"
                serialized = json.dumps(payload, separators=(",", ":"))
                path.write_text(
                    serialized[:-1] + ',"phase":"cancelled"}',
                    encoding="utf-8",
                )
                with self.assertRaises(WatcherContinuationError) as malformed:
                    WatcherContinuation.load(lease)
                self.assertEqual("malformed_json", malformed.exception.reason)

                path.write_text(
                    serialized.replace('"created":' + str(record.created),
                                       '"created":NaN'),
                    encoding="utf-8",
                )
                with self.assertRaises(ValueError):
                    WatcherContinuation.load(lease)
            finally:
                lease.release()

    def test_ordinary_restart_checkpoint_is_independent_and_profile_bound(self) -> None:
        """Isolate ordinary restart intent from the updater nonce file.

        Returns:
            None: Both fixed files round-trip their phases independently,
                reject a mismatched profile, and retain no credentials.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            lease = WorkspaceLease(root)
            try:
                updater = WatcherContinuation(
                    session_id="c" * 32,
                    binding="a" * 64,
                    profile_hash=WatcherContinuation.profile_digest(root),
                    source_version="1.0.0",
                    target_version="1.0.1",
                    update_session="d" * 32,
                    mode="legacy",
                    contextual=False,
                    created=time.time(),
                    phase="cancelled",
                )
                ordinary = WatcherContinuation(
                    session_id="e" * 32,
                    binding="f" * 64,
                    profile_hash=WatcherContinuation.profile_digest(root),
                    source_version="1.0.0",
                    target_version="1.0.0",
                    update_session="9" * 32,
                    mode="linked",
                    contextual=True,
                    created=time.time(),
                    phase="consumed",
                    approved_scope=UPLOAD_SCOPE,
                )
                updater.save(lease)
                ordinary.save(lease, ordinary_restart=True)
                self.assertEqual(updater, WatcherContinuation.load(lease))
                self.assertEqual(
                    ordinary,
                    WatcherContinuation.load(lease, ordinary_restart=True),
                )
                self.assertEqual("d" * 32, WatcherContinuation.load(lease).update_session)
                self.assertEqual(
                    "9" * 32,
                    WatcherContinuation.load(
                        lease, ordinary_restart=True,
                    ).update_session,
                )
                ordinary_path = root / "watcher-restart.json"
                encoded = ordinary_path.read_text(encoding="utf-8")
                self.assertNotIn("api_key", encoded.lower())
                self.assertNotIn("credential", encoded.lower())
                ordinary_path.write_text(
                    json.dumps({
                        **ordinary.payload(), "profile_hash": "b" * 64,
                    }),
                    encoding="utf-8",
                )
                with self.assertRaises(WatcherContinuationError) as profile:
                    WatcherContinuation.load(lease, ordinary_restart=True)
                self.assertEqual("profile_mismatch", profile.exception.reason)
                self.assertNotIn(str(root), str(profile.exception))
                self.assertEqual(updater, WatcherContinuation.load(lease))
            finally:
                lease.release()

    def test_watcher_continuation_refuses_reparse_checkpoint(self) -> None:
        """Do not follow a checkpoint redirected outside the owned workspace.

        Returns:
            None: A symlinked continuation document is rejected without reading
                its external target.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            outside = root.parent / (root.name + "-outside.json")
            outside.write_text("{}", encoding="utf-8")
            self.addCleanup(lambda: outside.unlink(missing_ok=True))
            lease = WorkspaceLease(root)
            try:
                path = root / "watcher-continuation.json"
                try:
                    path.symlink_to(outside)
                except (OSError, NotImplementedError):
                    self.skipTest("The current account cannot create a checkpoint symlink")
                with self.assertRaises(WatcherContinuationError) as redirected:
                    WatcherContinuation.load(lease)
                self.assertEqual("redirected", redirected.exception.reason)
            finally:
                lease.release()

    def test_workspace_lease_excludes_an_independent_python_process(self) -> None:
        """Verify native workspace-byte exclusion across independent PIDs.

        Returns:
            None: A second Python process is denied until its owner releases.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            probe = (
                "import sys\n"
                "from pathlib import Path\n"
                "from uploader.flight_jobs import WorkspaceLease\n"
                "try:\n"
                "    lease = WorkspaceLease(Path(sys.argv[1]))\n"
                "except RuntimeError:\n"
                "    print('blocked')\n"
                "else:\n"
                "    lease.release()\n"
                "    print('acquired')\n"
            )
            command = [sys.executable, "-c", probe, str(root)]
            cwd = Path(__file__).resolve().parents[1]
            owner = WorkspaceLease(root)
            try:
                blocked = subprocess.run(
                    command, cwd=cwd, capture_output=True, text=True,
                    check=False, timeout=10,
                )
                self.assertEqual(0, blocked.returncode, blocked.stderr)
                self.assertEqual("blocked", blocked.stdout.strip())
            finally:
                owner.release()
            acquired = subprocess.run(
                command, cwd=cwd, capture_output=True, text=True,
                check=False, timeout=10,
            )
            self.assertEqual(0, acquired.returncode, acquired.stderr)
            self.assertEqual("acquired", acquired.stdout.strip())

    def test_missing_pdf_reader_keeps_existing_report_metadata_usable(self) -> None:
        """Retain saved PDF access when source Python lacks optional inspection.

        Returns:
            None: Missing pypdf yields unknown pages, preserving bytes and time.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            report = directory / "flight-en.pdf"
            report.write_bytes(b"retained original PDF")
            metadata = directory / ".metadata"
            metadata.mkdir()
            (metadata / (report.name + ".manifest.json")).write_text(
                '{"created_utc":"2026-10-18T09:46:05+00:00"}', encoding="utf-8",
            )
            with patch.dict("sys.modules", {"pypdf": None, "reportlab": None}):
                details = report_file_details(report)
            self.assertIsNone(details.pages)
            self.assertEqual("2026-10-18T09:46:05+00:00", details.created_utc)
            self.assertFalse(details.estimated_time)
            self.assertEqual(b"retained original PDF", report.read_bytes())


    def test_new_dispatch_names_preserve_older_report_paths_and_bytes(self) -> None:
        """Add current OFP identity only to newly allocated PDF generations.

        Returns:
            None: Flight number/ICAO naming preserves leading zeroes, while
                updated plans never rename or overwrite previous editions.
        """
        from uploader.flight_simbrief import DispatchPlan

        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            (directory / "session.json").write_text(json.dumps({
                "started_utc": "2026-10-18T09:46:05Z", "aircraft": "A320",
            }), encoding="utf-8")
            first = allocate_report_revision(directory, "first", ("en",))
            original = first.files["en"]
            original.write_bytes(b"retained original PDF")
            plan = DispatchPlan("LKPR", "EDDF", "DCT", "A320", "120", "5000",
                "kgs", "2610", "2026-10-18", flight_number="CY0734")
            plan.save(directory)
            second = allocate_report_revision(directory, "second", ("en",))
            self.assertIn("CY0734 - LKPR-EDDF", second.files["en"].name)
            self.assertNotEqual(original, second.files["en"])
            organize_reports(directory)
            self.assertEqual(b"retained original PDF", original.read_bytes())
            self.assertEqual(original, allocate_report_revision(directory, "first", ("en",)).files["en"])


if __name__ == "__main__":
    unittest.main()
