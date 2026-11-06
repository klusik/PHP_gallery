# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_import_destination_admission.py
# Module Type: Recovery Safety Regression
# Purpose: Refuse linked target changes when accepted import work is ambiguous.
# Responsibilities:
#   - Inspect durable job history without repairing or normalizing unknown data.
#   - Preserve exact accepted-job bindings during read-only target admission.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable import destination-admission tests; no server or legacy changes."""

import json
import tempfile
import threading
import unittest
from unittest import mock
from pathlib import Path

from uploader.flight_jobs import ImportBindings
from uploader.models import ImportItem, ImportJob
from uploader.state_store import JobStateStore, atomic_write_json, default_upload_state


class ImportDestinationAdmissionTests(unittest.TestCase):
    """Prevent orphaned or stale work from authorizing another gallery."""

    def setUp(self) -> None:
        """Prepare one disposable complete state owner with two target fingerprints.

        Returns:
            None: Empty temporary state and unrelated approved target bindings.
        """
        temporary = tempfile.TemporaryDirectory(prefix='phpgallery-import-admission-')
        self.addCleanup(temporary.cleanup)
        self.path = Path(temporary.name) / 'upload_state.json'
        self.store = JobStateStore(self.path)
        self.first = 'a' * 64
        self.second = 'b' * 64

    def _job(self, state: str) -> ImportJob:
        """Make one persisted job with an exact item lifecycle state.

        Args:
            state (str): Accepted import item state to be tested.

        Returns:
            ImportJob: New source record with no real image or upload.
        """
        job = ImportJob.create('files', 'photo.jpg', 'Display label only')
        job.items = [ImportItem('item-1', '/unused/photo.jpg', 'photo.jpg', state=state)]
        return job

    def _persist(self, *jobs: ImportJob) -> None:
        """Write full valid state without starting a worker.

        Args:
            *jobs (ImportJob): Complete accepted source histories to preserve.

        Returns:
            None: Creates a versioned state file in the disposable directory.
        """
        document = default_upload_state()
        document['jobs'] = {job.id: job.to_dict() for job in jobs}
        atomic_write_json(self.path, document)

    def test_inflight_job_blocks_different_target_but_accepts_exact_binding(self) -> None:
        """A submitted upload must retain its original gallery across restart.

        Returns:
            None: Only its proven original target may be selected.
        """
        job = self._job('uploading')
        self._persist(job)
        self.assertFalse(self.store.admitted_imports_match_target(
            {job.id: self.first}, self.second,
        ))
        self.assertTrue(self.store.admitted_imports_match_target(
            {job.id: self.first}, self.first,
        ))

    def test_orphaned_job_never_infers_destination_from_display_label(self) -> None:
        """A missing separate import binding is not an implicit legacy target.

        Returns:
            None: Any unresolved job without exact binding blocks admission.
        """
        job = self._job('failed_retryable')
        self._persist(job)
        self.assertFalse(self.store.admitted_imports_match_target({}, self.first))
        self.assertFalse(self.store.admitted_imports_match_target({}, self.second))

    def test_unbound_preflight_does_not_block_target_selection(self) -> None:
        """Retain an undispatched preview while still protecting accepted work.

        Returns:
            None: An unbound preview can be left behind; a bound import cannot.
        """
        preview = self._job('queued')
        accepted = self._job('queued')
        self._persist(preview, accepted)
        before = self.path.read_bytes()
        bindings = {accepted.id: self.first}
        self.assertTrue(self.store.admitted_imports_match_target(bindings, self.first))
        self.assertFalse(self.store.admitted_imports_match_target(bindings, self.second))
        self.assertEqual(before, self.path.read_bytes())

        # A malformed attempt count must not be rounded down into a preview.
        document = default_upload_state()
        row = preview.to_dict()
        row['items'][0]['attempt_count'] = 0.5
        document['jobs'] = {preview.id: row}
        atomic_write_json(self.path, document)
        with self.assertRaises(ValueError):
            self.store.admitted_imports_match_target({}, self.second)

    def test_failed_and_cancelled_jobs_remain_bound(self) -> None:
        """Failed, uncertain and cancelled work cannot migrate destinations.

        Returns:
            None: Every unfinished lifecycle state blocks cross-target change.
        """
        for state in ('queued', 'failed_permanent', 'cancelled'):
            with self.subTest(state=state):
                job = self._job(state)
                self._persist(job)
                self.assertFalse(self.store.admitted_imports_match_target(
                    {job.id: self.first}, self.second,
                ))

    def test_orphaned_ledger_record_blocks_even_when_job_file_is_missing(self) -> None:
        """Unknown missing job history cannot silently release its binding.

        Returns:
            None: Durable ledger alone still prevents target change.
        """
        self.assertFalse(self.store.admitted_imports_match_target(
            {'missing-job': self.first}, self.second,
        ))

    def test_completed_job_does_not_erase_original_binding(self) -> None:
        """A confirmed job remains auditable after a permitted target choice.

        Returns:
            None: Admission causes no history write, resolution or deletion.
        """
        job = self._job('confirmed')
        self._persist(job)
        before = self.path.read_bytes()
        original = {job.id: self.first}
        self.assertTrue(self.store.admitted_imports_match_target(
            original, self.second,
        ))
        self.assertEqual(before, self.path.read_bytes())
        self.assertEqual({job.id: self.first}, original)

    def test_corrupt_or_unknown_job_state_is_not_repaired(self) -> None:
        """An invalid history cannot be normalized into an empty work queue.

        Returns:
            None: No quarantine, overwrite or implicit success is recorded.
        """
        self.path.write_text('{not valid JSON', encoding='utf-8')
        before = self.path.read_bytes()
        with self.assertRaises(ValueError):
            self.store.admitted_imports_match_target({}, self.first)
        self.assertEqual(before, self.path.read_bytes())
        self.assertEqual([self.path], list(self.path.parent.glob('*')))

    def test_unknown_item_state_refuses_target_change(self) -> None:
        """A malformed item state cannot be decoded as an unrelated success.

        Returns:
            None: Recovery requires manual inspection of the original bytes.
        """
        job = self._job('queued')
        self._persist(job)
        document = default_upload_state()
        item = job.to_dict()
        item['items'][0]['state'] = 'unknown-future-status'
        document['jobs'] = {job.id: item}
        atomic_write_json(self.path, document)
        with self.assertRaises(ValueError):
            self.store.admitted_imports_match_target({job.id: self.first}, self.second)

    def test_duplicate_json_job_section_cannot_hide_pending_work(self) -> None:
        """Refuse a last-key-wins document that discards an accepted job.

        Returns:
            None: Duplicate JSON sections are rejected without rewriting history.
        """
        job = self._job('uploading')
        document = default_upload_state()
        document['jobs'] = {job.id: job.to_dict()}
        encoded = json.dumps(document)
        tampered = encoded.replace(
            '"recent_events": []', '"jobs": {}, "recent_events": []', 1,
        )
        self.assertNotEqual(encoded, tampered)
        self.path.write_text(tampered, encoding='utf-8')
        with self.assertRaises(ValueError):
            self.store.admitted_imports_match_target({}, self.second)
        self.assertEqual(tampered, self.path.read_text(encoding='utf-8'))

    def test_nonfinite_json_history_cannot_authorize_mode_change(self) -> None:
        """Refuse non-JSON NaN even when it is outside the job collection.

        Returns:
            None: An invalid full document never proves an empty work queue.
        """
        document = default_upload_state()
        document['uploaded_hashes'] = {'unknown': float('nan')}
        atomic_write_json(self.path, document)
        with self.assertRaises(ValueError):
            self.store.admitted_imports_match_target({}, self.second)

    def test_overflowed_json_exponent_cannot_authorize_target_change(self) -> None:
        """Treat an exponent that decodes to infinity as invalid evidence.

        Returns:
            None: The existing file is retained without authorizing a target.
        """
        document = json.dumps(default_upload_state())
        original = '"uploaded_hashes": {}'
        self.assertIn(original, document)
        tampered = document.replace(
            original, '"uploaded_hashes": {"unexpected": 1e999}', 1,
        )
        self.path.write_text(tampered, encoding='utf-8')
        with self.assertRaises(ValueError):
            self.store.admitted_imports_match_target({}, self.second)
        self.assertEqual(tampered, self.path.read_text(encoding='utf-8'))

    def test_binding_ledger_rejects_duplicate_and_nonfinite_evidence(self) -> None:
        """Ambiguous JSON must not discard the prior accepted target.

        Returns:
            None: The exact malformed bytes remain untouched after rejection.
        """
        job = self._job('queued')
        job.id = 'accepted'
        self._persist(job)
        ledger = ImportBindings(self.path.parent / 'flights')
        ledger.bind(job.id, self.first)
        original = ledger.path.read_text(encoding='utf-8')
        for tampered in (
            original.replace('"jobs": {', '"jobs": {}, "jobs": {', 1),
            original.replace('"schema_version": 1', '"schema_version": NaN', 1),
            original.replace('"schema_version": 1', '"schema_version": 1e999', 1),
            original.replace('"schema_version": 1', '"schema_version": true', 1),
            original.replace('"schema_version": 1', '"schema_version": 2', 1),
        ):
            with self.subTest(tampered=tampered[:100]):
                self.assertNotEqual(original, tampered)
                ledger.path.write_text(tampered, encoding='utf-8')
                with self.assertRaises(ValueError):
                    ledger.entries()
                self.assertEqual(tampered, ledger.path.read_text(encoding='utf-8'))

    def test_uncertain_upload_requires_same_attempt_acknowledged_reply(self) -> None:
        """Clear a write-ahead marker only with matching live upload evidence.

        Returns:
            None: A valid same-attempt server reply confirms; weak or malformed
                receipts leave the prior uncertain job bytes unchanged.
        """
        job = self._job('failed_permanent')
        job.items[0].attempt_count = 1
        job.items[0].reason = (
            'Linked upload outcome may be uncertain; review the gallery '
            'before retrying.'
        )
        job.items[0].sha256 = 'a' * 64
        self._persist(job)
        original = self.path.read_bytes()
        store = JobStateStore(self.path)
        confirmed = store.get_job(job.id)
        confirmed.items[0].transition('confirmed')
        confirmed.items[0].server_result = {'uploaded': 1, 'scanned': 1}
        store.save_job(confirmed)
        saved = store.get_job(job.id)
        self.assertEqual('confirmed', saved.items[0].state)
        self.assertEqual(1, saved.items[0].attempt_count)

        invalid_receipts = (
            {},
            {'sha256': 'a' * 64},
            {'existing': [{'sha256': 'a' * 64}]},
            {'uploaded': 1, 'scanned': -1},
            {'uploaded': True, 'scanned': 1},
            {'uploaded': 1, 'scanned': True},
        )
        for receipt in invalid_receipts:
            with self.subTest(receipt=receipt):
                self.path.write_bytes(original)
                candidate = store.get_job(job.id)
                candidate.items[0].transition('confirmed')
                candidate.items[0].server_result = receipt
                with self.assertRaises(ValueError):
                    store.save_job(candidate)
                self.assertEqual(original, self.path.read_bytes())

        self.path.write_bytes(original)
        retried = store.get_job(job.id)
        retried.items[0].transition('confirmed')
        retried.items[0].attempt_count = 2
        retried.items[0].server_result = {'uploaded': 1, 'scanned': 1}
        with self.assertRaises(ValueError):
            store.save_job(retried)
        self.assertEqual(original, self.path.read_bytes())

    def test_binding_ledger_refuses_nonfile_and_oversized_evidence(self) -> None:
        """Unknown replacement objects cannot represent an empty binding map.

        Returns:
            None: No evidence is removed, quarantined or normalized.
        """
        ledger = ImportBindings(self.path.parent)
        ledger.path.mkdir()
        with self.assertRaises(ValueError):
            ledger.entries()
        ledger.path.rmdir()
        ledger.path.write_bytes(b' ' * (16 * 1024 * 1024 + 1))
        with self.assertRaises(ValueError):
            ledger.entries()
        self.assertEqual(16 * 1024 * 1024 + 1, ledger.path.stat().st_size)

    def test_atomic_writer_uses_independent_stages_during_overlap(self) -> None:
        """Never let a second state writer truncate the first staged bytes.

        Returns:
            None: Interleaved writers use unique O_EXCL stages and remove them.
        """
        entered = threading.Event()
        released = threading.Event()
        stages: list[Path] = []
        failures: list[Exception] = []
        original_replace = Path.replace

        def staged_replace(stage: Path, destination: Path) -> Path:
            """Pause one publication while another owner publishes.

            Args:
                stage (Path): Independently created temporary file.
                destination (Path): Shared state destination to replace.

            Returns:
                Path: The replaced destination when publication succeeds.
            """
            stages.append(stage)
            if len(stages) == 1:
                entered.set()
                if not released.wait(10):
                    raise AssertionError('First writer was not released.')
            return original_replace(stage, destination)

        def write_value(value: str) -> None:
            """Record any escaped writer exception for the test owner.

            Args:
                value (str): Independent writer's JSON value.

            Returns:
                None: Error details are recorded in the test-owned list.
            """
            try:
                atomic_write_json(self.path, {'writer': value})
            except Exception as exc:
                failures.append(exc)

        first = threading.Thread(target=write_value, args=('first',), daemon=True)
        second = threading.Thread(target=write_value, args=('second',), daemon=True)
        with mock.patch.object(Path, 'replace', new=staged_replace):
            first.start()
            try:
                self.assertTrue(entered.wait(10))
                second.start()
                second.join(10)
            finally:
                released.set()
                first.join(10)
                if second.ident is not None:
                    second.join(10)
        self.assertFalse(first.is_alive())
        self.assertFalse(second.is_alive())
        self.assertEqual([], failures)
        self.assertEqual(2, len(stages))
        self.assertNotEqual(stages[0], stages[1])
        self.assertEqual({'writer': 'first'}, json.loads(self.path.read_text()))
        self.assertEqual([self.path], list(self.path.parent.iterdir()))

    def test_mixed_bindings_reject_when_any_unfinished_job_is_foreign(self) -> None:
        """One safe matching import cannot mask another pending destination.

        Returns:
            None: Foreign accepted work retains its separately bound target.
        """
        first = self._job('queued')
        other = self._job('uploading')
        self._persist(first, other)
        self.assertFalse(self.store.admitted_imports_match_target({
            first.id: self.first, other.id: self.second,
        }, self.first))


if __name__ == '__main__':
    unittest.main()
