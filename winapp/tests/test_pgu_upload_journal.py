# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_pgu_upload_journal.py
# Module Type: Client Journal Regression Test
# Purpose: Exercise target-bound legacy write-ahead acknowledgement behavior.
# Responsibilities:
#   - Retain uncertain uploads and refuse concurrent or changed ownership.
#   - Preserve accepted records without storing endpoint or credential text.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Source fixtures for the pgu journal; no network or Windows API is used."""

import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from uploader.flight_jobs import connection_binding
from uploader.pgu_upload_journal import (
    PGU_JOURNAL_DIRECTORY, PguJournalError, PguUploadJournal,
)


class PguUploadJournalTests(unittest.TestCase):
    """Check exclusive per-target state without a real Gallery server."""

    def setUp(self) -> None:
        """Create a private temporary profile for one fixture.

        Returns:
            None: Sets up two independent, non-secret target fingerprints.
        """
        self.temporary = tempfile.TemporaryDirectory(prefix='pgu-journal-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.key = 'pgu_private_fixture'
        self.url = 'https://gallery.example.test/Galerie/api/upload'
        self.binding = connection_binding(self.url, self.key)
        self.other = connection_binding('https://other.example.test/upload', self.key)
        self.journal = PguUploadJournal(self.root, self.binding)

    def test_uncertain_and_busy_attempts_never_admit_a_second_request(self) -> None:
        """Keep a pending record after close and isolate a different target.

        Returns:
            None: Busy and uncertain same-target attempts are refused.
        """
        attempt = self.journal.begin('a' * 64, 12)
        with self.assertRaises(PguJournalError):
            self.journal.begin('b' * 64, 14)
        other = PguUploadJournal(self.root, self.other).begin('b' * 64, 14)
        other.close()
        attempt.close()
        pending = self.journal.inspect()
        self.assertIsNotNone(pending)
        self.assertEqual('pending', pending.state)
        with self.assertRaises(PguJournalError):
            self.journal.begin('b' * 64, 14)

    def test_accepted_record_is_retained_before_the_next_pending(self) -> None:
        """Archive an acknowledged record before another source is admitted.

        Returns:
            None: Accepted history remains and a new slot becomes pending.
        """
        attempt = self.journal.begin('a' * 64, 12)
        attempt.accept({'ok': True, 'uploaded': 1})
        attempt.close()
        accepted = self.journal.inspect()
        self.assertIsNotNone(accepted)
        self.assertEqual('accepted', accepted.state)
        second = self.journal.begin('b' * 64, 14)
        second.close()
        self.assertEqual('pending', self.journal.inspect().state)
        directory = self.root / PGU_JOURNAL_DIRECTORY
        archives = list(directory.glob('*.accepted.json'))
        self.assertEqual(1, len(archives))
        self.assertEqual('accepted', json.loads(archives[0].read_text())['state'])
        for path in directory.iterdir():
            self.assertNotIn(self.key, path.name)
            self.assertNotIn(self.url, path.name)
            if path.suffix == '.json':
                self.assertNotIn(self.key, path.read_text())
                self.assertNotIn(self.url, path.read_text())

    def test_acknowledged_same_content_can_be_explicitly_sent_again(self) -> None:
        """Do not make historical accepted state a permanent duplicate ban.

        Returns:
            None: The subsequent explicit attempt receives a different nonce.
        """
        first = self.journal.begin('7' * 64, 12)
        first.accept({'ok': True, 'uploaded': 1})
        first.close()
        previous = self.journal.inspect()
        with self.assertRaises(PguJournalError):
            self.journal.begin('7' * 64, 12)
        second = self.journal.begin_explicit_repeat('7' * 64, 12)
        second.close()
        current = self.journal.inspect()
        self.assertEqual('accepted', previous.state)
        self.assertEqual('pending', current.state)
        self.assertNotEqual(previous.nonce, current.nonce)

    def test_archived_acknowledgement_blocks_automatic_repeat(self) -> None:
        """Find an older accepted content key without scanning receipt history.

        Returns:
            None: The old key is refused after a different acknowledgement.
        """
        first = self.journal.begin('5' * 64, 7)
        first.accept({'ok': True, 'uploaded': 1})
        first.close()
        second = self.journal.begin('6' * 64, 8)
        second.accept({'ok': True, 'uploaded': 1})
        second.close()
        with self.assertRaises(PguJournalError):
            self.journal.begin('5' * 64, 7)
        self.assertEqual('accepted', self.journal.inspect().state)

    def test_explicit_pending_review_preserves_identity_and_never_replays(self) -> None:
        """Resolve an uncertain intent without claiming a successful upload.

        Returns:
            None: A reviewed record remains and its content is not auto-retried.
        """
        attempt = self.journal.begin('8' * 64, 6)
        attempt.close()
        pending = self.journal.inspect()
        reviewed = self.journal.resolve_pending(pending)
        self.assertEqual('reviewed', reviewed.state)
        self.assertEqual(pending.nonce, reviewed.nonce)
        with self.assertRaises(PguJournalError):
            self.journal.resolve_pending(pending)
        with self.assertRaises(PguJournalError):
            self.journal.begin('8' * 64, 6)
        different = self.journal.begin('9' * 64, 7)
        different.close()
        self.assertEqual('pending', self.journal.inspect().state)

    def test_invalid_acknowledgement_leaves_pending(self) -> None:
        """Refuse malformed, partial and non-integer acknowledgements.

        Returns:
            None: Every rejected response leaves the original intent pending.
        """
        attempt = self.journal.begin('c' * 64, 9)
        for response in ({'ok': True, 'uploaded': 0},
                         {'ok': True, 'uploaded': True},
                         {'ok': False, 'uploaded': 1}):
            with self.assertRaises(PguJournalError):
                attempt.accept(response)
        attempt.close()
        self.assertEqual('pending', self.journal.inspect().state)

    def test_uncertain_acceptance_retains_exclusive_lease(self) -> None:
        """Block future workers when the final lease readback fails.

        Returns:
            None: The retained lock prevents an automatic next attempt.
        """
        attempt = self.journal.begin('d' * 64, 10)
        original = attempt._check_lease
        checks = [0]

        def fail_after_publication() -> None:
            """Fail only the second acceptance lease observation.

            Returns:
                None: The first observation delegates to the real lease.

            Raises:
                PguJournalError: The simulated final readback is unavailable.
            """
            checks[0] += 1
            if checks[0] == 2:
                raise PguJournalError('Fixture readback unavailable.')
            original()

        with mock.patch.object(attempt, '_check_lease', side_effect=fail_after_publication):
            with self.assertRaises(PguJournalError):
                attempt.accept({'ok': True, 'uploaded': 1})
        attempt.close()
        with self.assertRaises(PguJournalError):
            self.journal.begin('e' * 64, 11)
        with self.assertRaises(PguJournalError):
            self.journal.inspect()

    def test_bad_binding_or_redirected_slot_fails_closed(self) -> None:
        """Reject invalid bindings and a final symlink without following it.

        Returns:
            None: Invalid or redirected local evidence does not admit work.
        """
        with self.assertRaises(PguJournalError):
            PguUploadJournal(self.root, '../secret')
        directory = self.root / PGU_JOURNAL_DIRECTORY
        directory.mkdir()
        destination = self.root / 'outside.json'
        destination.write_text('unchanged')
        slot = directory / (self.binding + '.json')
        try:
            slot.symlink_to(destination)
        except (OSError, NotImplementedError):
            self.skipTest('Symbolic links are unavailable in this fixture.')
        with self.assertRaises(PguJournalError):
            self.journal.begin('f' * 64, 5)
        self.assertEqual('unchanged', destination.read_text())

    def test_missing_slot_or_witness_requires_review(self) -> None:
        """Never treat loss of just one previously written record as first use.

        Returns:
            None: Both independent single-file loss scenarios block admission.
        """
        for missing in ('slot', 'witness'):
            with self.subTest(missing=missing), tempfile.TemporaryDirectory(
                    prefix='pgu-journal-loss-') as temporary:
                root = Path(temporary)
                journal = PguUploadJournal(root, self.binding)
                attempt = journal.begin('1' * 64, 3)
                attempt.accept({'ok': True, 'uploaded': 1})
                attempt.close()
                suffix = '.json' if missing == 'slot' else '.started.json'
                (root / PGU_JOURNAL_DIRECTORY / (self.binding + suffix)).unlink()
                with self.assertRaises(PguJournalError):
                    journal.inspect()
                with self.assertRaises(PguJournalError):
                    journal.begin('2' * 64, 4)

    def test_duplicate_or_malformed_record_cannot_admit_a_request(self) -> None:
        """Keep a corrupt owned slot from becoming a first-use state.

        Returns:
            None: Duplicate keys and malformed data block new attempts.
        """
        attempt = self.journal.begin('3' * 64, 8)
        attempt.close()
        slot = self.root / PGU_JOURNAL_DIRECTORY / (self.binding + '.json')
        for content in (b'{"version":1,"version":1}', b'not-json'):
            slot.write_bytes(content)
            with self.assertRaises(PguJournalError):
                self.journal.begin('4' * 64, 9)
