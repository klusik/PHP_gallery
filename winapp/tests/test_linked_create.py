# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_linked_create.py
# Module Type: Regression Test
# Purpose: Preserve uncertain gallery creation intents across client restarts.
# Responsibilities:
#   - Block another mutation after any unconfirmed response.
#   - Permit account-bound status and explicit same-key resume only.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable gallery-creation journal checks without Windows APIs."""

import tempfile
import base64
import json
import os
import threading
import time
import unittest
from dataclasses import replace
from pathlib import Path
from unittest import mock

from uploader.app_linking import (
    CREATE_SCOPE, LINKING_MAX_TOKEN_LIFETIME_SECONDS, TOKEN_PREFIX,
    LinkedGallery, LinkedIdentity, LinkingError, LinkingSession,
)
from uploader import linked_create
from uploader.linked_create import CreateDispatch, CreateIntent, GalleryCreateJournal
from uploader.linked_upload import LinkedTargetLease


class GalleryCreateJournalTests(unittest.TestCase):
    """Exercise the durable intent boundary with a fake linked session."""

    def test_stale_bound_receipt_cannot_acknowledge_another_operation(self) -> None:
        """Reject an old acknowledgement even for an identical gallery projection.

        Returns:
            None: The current key and receipt survive until their own acknowledgement.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.return_value = LinkedGallery(42, None, 'First')
            issuer = 'https://gallery.example'
            first = journal.create(session, issuer, 7, 'First', None)
            self.assertNotIn(journal._read()[0].operation_key, repr(first))
            journal.acknowledge(issuer, 7, first)
            second = journal.create(session, issuer, 7, 'First', None)
            self.assertEqual(first.gallery, second.gallery)
            self.assertNotEqual(first.intent_fingerprint, second.intent_fingerprint)
            pending = journal._path().read_bytes()
            receipt = journal._receipt_path().read_bytes()
            with self.assertRaises(LinkingError):
                journal.acknowledge(issuer, 7, first)
            apply = mock.Mock(return_value=True)
            with self.assertRaises(LinkingError):
                journal.apply_receipt(issuer, 7, first, apply)
            apply.assert_not_called()
            with self.assertRaises(LinkingError):
                journal.acknowledge(issuer, 7, second.gallery)
            self.assertEqual(pending, journal._path().read_bytes())
            self.assertEqual(receipt, journal._receipt_path().read_bytes())
            restarted = GalleryCreateJournal(Path(temporary))
            self.assertEqual(second, restarted.reconcile(session, issuer, 7))
            restarted.acknowledge(issuer, 7, second)
            self.assertIsNone(restarted._read())
            self.assertIsNone(restarted._read_receipt())

    def test_target_admission_retains_the_receipt_lease(self) -> None:
        """Prevent another process changing the receipt while it is applied.

        Returns:
            None: A competing lease cannot be acquired during local admission.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            journal = GalleryCreateJournal(root)
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.return_value = LinkedGallery(42, None, 'First')
            issuer = 'https://gallery.example'
            bound = journal.create(session, issuer, 7, 'First', None)

            def apply() -> bool:
                """Check competing local acquisition inside the target callback.

                Returns:
                    bool: True after the competing lease was refused.
                """
                contender = LinkedTargetLease(root, linked_create._CREATE_LEASE_BINDING)
                with self.assertRaises(LinkingError):
                    contender.acquire()
                return True

            session.gallery_create_request.reset_mock()
            self.assertTrue(journal.apply_receipt(issuer, 7, bound, apply))
            session.gallery_create_request.assert_not_called()

    def test_create_rechecks_the_live_account_before_publishing(self) -> None:
        """Reject a different or unavailable session account before any intent.

        Returns:
            None: No pending key, receipt or creation request is produced.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            for identity in (LinkedIdentity(8, 'other'), LinkingError('unavailable')):
                session.identity.side_effect = None
                if isinstance(identity, Exception):
                    session.identity.side_effect = identity
                else:
                    session.identity.return_value = identity
                with self.subTest(identity=type(identity).__name__):
                    with self.assertRaises(LinkingError):
                        journal.create(session, 'https://gallery.example', 7, 'First', None)
                    self.assertIsNone(journal._read())
                    self.assertIsNone(journal._read_receipt())
                    session.gallery_create_request.assert_not_called()

    def test_reconcile_refuses_a_different_live_account(self) -> None:
        """Do not accept another Admin's status for the saved operation key.

        Returns:
            None: The original intent and receipt survive without a status call.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.return_value = LinkedGallery(42, None, 'First')
            issuer = 'https://gallery.example'
            journal.create(session, issuer, 7, 'First', None)
            pending = journal._path().read_bytes()
            receipt = journal._receipt_path().read_bytes()
            session.gallery_create_request.reset_mock()
            session.identity.return_value = LinkedIdentity(8, 'other')
            with self.assertRaises(LinkingError):
                journal.reconcile(session, issuer, 7)
            session.gallery_create_request.assert_not_called()
            self.assertEqual(pending, journal._path().read_bytes())
            self.assertEqual(receipt, journal._receipt_path().read_bytes())

    def test_reconcile_pending_does_not_query_another_account(self) -> None:
        """Keep an unresolved key when the live session belongs elsewhere.

        Returns:
            None: Neither a status request nor a new receipt is produced.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.side_effect = LinkingError('lost reply')
            issuer = 'https://gallery.example'
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'First', None)
            original = journal._path().read_bytes()
            session.gallery_create_request.reset_mock()
            session.identity.return_value = LinkedIdentity(8, 'other')
            with self.assertRaises(LinkingError):
                journal.reconcile(session, issuer, 7)
            session.gallery_create_request.assert_not_called()
            self.assertEqual(original, journal._path().read_bytes())
            self.assertIsNone(journal._read_receipt())

    def test_incomplete_staging_never_becomes_pending(self) -> None:
        """Keep a failed partial stage away from the final operation key.

        Returns:
            None: No pending marker is published and an orphan cannot block create.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            journal._path().parent.mkdir(parents=True)
            intent = CreateIntent('https://gallery.example', 7, 'a' * 64, 'First', None)
            with mock.patch('uploader.linked_create.os.write',
                            side_effect=[2, OSError('interrupted')]):
                with self.assertRaises(LinkingError):
                    journal._publish(intent)
            self.assertIsNone(journal._read())
            orphan = journal._path().with_name(journal._path().name + '.interrupted.tmp')
            orphan.write_bytes(b'partial')
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            receipt = LinkedGallery(42, None, 'Second')
            session.gallery_create_request.return_value = receipt
            self.assertEqual(receipt, journal.create(session, intent.issuer, 7, 'Second', None).gallery)
            self.assertEqual(b'partial', orphan.read_bytes())
            session.gallery_create_request.assert_called_once()

    def test_pending_publication_never_replaces_existing_key(self) -> None:
        """Refuse another key when a complete pending intent already exists.

        Returns:
            None: The original bytes and account binding remain unchanged.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            journal._path().parent.mkdir(parents=True)
            first = CreateIntent('https://gallery.example', 7, 'a' * 64, 'First', None)
            second = CreateIntent('https://gallery.example', 7, 'b' * 64, 'Second', None)
            journal._publish(first)
            before = journal._path().read_bytes()
            with self.assertRaises(LinkingError):
                journal._publish(second)
            self.assertEqual(before, journal._path().read_bytes())
            self.assertEqual(first, journal._read()[0])

    def test_failed_post_publication_check_retains_original_key(self) -> None:
        """Keep the complete published key if readback is uncertain.

        Returns:
            None: No request is sent and the saved original can be resumed.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            with mock.patch.object(journal, '_read', side_effect=[
                None, LinkingError('readback unavailable'),
            ]):
                with self.assertRaises(LinkingError):
                    journal.create(session, 'https://gallery.example', 7, 'First', None)
            session.gallery_create_request.assert_not_called()
            intent = journal.pending_intent('https://gallery.example', 7)
            self.assertEqual('First', intent.title)
            with self.assertRaises(LinkingError):
                journal.create(session, intent.issuer, 7, 'Second', None)

    def test_uncertain_staging_cleanup_never_dispatches(self) -> None:
        """Preserve the published key if its staging link cannot be removed.

        Returns:
            None: No request is sent and the original intent remains readable.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            with mock.patch.object(journal, '_remove_file', return_value=False):
                with self.assertRaises(LinkingError):
                    journal.create(session, 'https://gallery.example', 7, 'First', None)
            session.gallery_create_request.assert_not_called()
            self.assertEqual('First', journal.pending_intent('https://gallery.example', 7).title)

    def test_abandon_requires_confirmed_tombstone_and_original_identity(self) -> None:
        """Keep the pending key on refusal, interruption or local cleanup failure.

        Returns:
            None: Only confirmed closure removes the exact original intent.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.side_effect = LinkingError('lost reply')
            issuer = 'https://gallery.example/Galerie'
            journal = GalleryCreateJournal(Path(temporary))
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'Original', 9)
            intent = journal.pending_intent(issuer, 7)
            session.gallery_create_request.reset_mock()
            session.identity.return_value = LinkedIdentity(8, 'other')
            with self.assertRaises(LinkingError):
                journal.abandon(session, intent)
            session.gallery_create_request.assert_not_called()
            session.identity.return_value = LinkedIdentity(7, 'admin')
            cancelled = CreateDispatch()
            cancelled.cancel()
            with self.assertRaises(LinkingError):
                journal.abandon(session, intent, cancelled)
            session.gallery_create_request.assert_not_called()
            with self.assertRaises(LinkingError):
                journal.abandon(session, replace(intent, parent_id=None))
            session.gallery_create_request.assert_not_called()
            with self.assertRaises(LinkingError):
                journal.abandon(session, intent)
            self.assertEqual(intent, journal.pending_intent(issuer, 7))
            session.gallery_create_request.side_effect = None
            session.gallery_create_request.return_value = None
            with mock.patch.object(journal, '_remove_file', return_value=False):
                with self.assertRaises(LinkingError):
                    journal.abandon(session, intent)
            self.assertEqual(intent, journal.pending_intent(issuer, 7))
            self.assertIsNone(journal.abandon(session, intent))
            args, kwargs = session.gallery_create_request.call_args
            self.assertEqual((intent.operation_key, 'Original', 9), args)
            self.assertTrue(kwargs['abandon'])
            self.assertIsNone(journal._read())

    def test_abandon_completed_result_keeps_receipt_for_acknowledgement(self) -> None:
        """Preserve a completed operation instead of claiming it was closed.

        Returns:
            None: The confirmed receipt continues to block new creation.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.side_effect = LinkingError('lost reply')
            journal = GalleryCreateJournal(Path(temporary))
            issuer = 'https://gallery.example/Galerie'
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'Original', None)
            intent = journal.pending_intent(issuer, 7)
            session.gallery_create_request.side_effect = None
            receipt = LinkedGallery(42, None, 'Original')
            session.gallery_create_request.return_value = receipt
            self.assertEqual(receipt, journal.abandon(session, intent).gallery)
            self.assertIsNotNone(journal._read())
            self.assertIsNotNone(journal._read_receipt())
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'Other', None)

    def test_resume_uses_original_key_and_rechecks_account(self) -> None:
        """Resume only the original intent after verifying the live account.

        Returns:
            None: Failures retain the intent and success persists its receipt.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.side_effect = LinkingError('lost reply')
            issuer = 'https://gallery.example/Galerie'
            journal = GalleryCreateJournal(Path(temporary))
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, ' Original ', 9)
            key = session.gallery_create_request.call_args.args[0]
            restarted = GalleryCreateJournal(Path(temporary))
            with self.assertRaises(LinkingError):
                restarted.pending_intent(issuer, 8)
            with self.assertRaises(LinkingError):
                restarted.pending_intent('https://other.example', 7)
            intent = restarted.pending_intent(issuer, 7)
            session.gallery_create_request.reset_mock()
            session.identity.return_value = LinkedIdentity(8, 'other')
            with self.assertRaises(LinkingError):
                restarted.resume(session, intent)
            session.gallery_create_request.assert_not_called()
            session.identity.return_value = LinkedIdentity(7, 'admin')
            with self.assertRaises(LinkingError):
                restarted.resume(session, replace(intent, title='Changed'))
            session.gallery_create_request.assert_not_called()
            cancelled = CreateDispatch()
            cancelled.cancel()
            with self.assertRaises(LinkingError):
                restarted.resume(session, intent, cancelled)
            session.gallery_create_request.assert_not_called()
            with self.assertRaises(LinkingError):
                restarted.resume(session, intent)
            self.assertEqual((key, ' Original ', 9),
                             session.gallery_create_request.call_args.args)
            self.assertEqual(intent, restarted.pending_intent(issuer, 7))
            session.gallery_create_request.side_effect = None
            receipt = LinkedGallery(42, 9, 'Original')
            session.gallery_create_request.return_value = receipt
            self.assertEqual(receipt, restarted.resume(session, intent).gallery)
            self.assertEqual((key, ' Original ', 9),
                             session.gallery_create_request.call_args.args)
            with self.assertRaises(LinkingError):
                restarted.resume(session, intent)
            with self.assertRaises(LinkingError):
                restarted.pending_intent(issuer, 7)
            with self.assertRaises(LinkingError):
                restarted.create(session, issuer, 7, 'Second', None)

    def test_unknown_blocks_new_keys_until_confirmed_status(self) -> None:
        """Keep an uncertain operation and receipt across restart and account changes.

        Returns:
            None: Only explicit receipt acknowledgement permits a fresh create.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.side_effect = LinkingError('lost reply')
            journal = GalleryCreateJournal(Path(temporary))
            issuer = 'https://gallery.example/Galerie'
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'First', None)
            key = session.gallery_create_request.call_args.args[0]
            self.assertEqual(64, len(key))
            restarted = GalleryCreateJournal(Path(temporary))
            with self.assertRaises(LinkingError):
                restarted.create(session, issuer, 7, 'Second', None)
            with self.assertRaises(LinkingError):
                restarted.reconcile(session, issuer, 8)
            self.assertEqual(1, session.gallery_create_request.call_count)
            session.gallery_create_request.side_effect = None
            session.gallery_create_request.return_value = None
            self.assertIsNone(restarted.reconcile(session, issuer, 7))
            self.assertEqual((key,), session.gallery_create_request.call_args.args)
            with self.assertRaises(LinkingError):
                restarted.create(session, issuer, 7, 'Second', None)
            receipt = LinkedGallery(42, None, 'First')
            session.gallery_create_request.return_value = receipt
            self.assertEqual(receipt, restarted.reconcile(session, issuer, 7).gallery)
            # Closing the dialog before consuming its worker event must not
            # lose the completed receipt or permit another operation.
            after_completion = GalleryCreateJournal(Path(temporary))
            with self.assertRaises(LinkingError):
                after_completion.create(session, issuer, 7, 'Second', None)
            session.gallery_create_request.reset_mock()
            bound = after_completion.reconcile(session, issuer, 7)
            self.assertEqual(receipt, bound.gallery)
            session.gallery_create_request.assert_called_once_with(key)
            with self.assertRaises(LinkingError):
                after_completion.acknowledge(issuer, 8, bound)
            after_completion.acknowledge(issuer, 7, bound)
            second_receipt = LinkedGallery(43, None, 'Second')
            session.gallery_create_request.return_value = second_receipt
            self.assertEqual(second_receipt, after_completion.create(session, issuer, 7, 'Second', None).gallery)
            self.assertNotEqual(key, session.gallery_create_request.call_args.args[0])

    def test_mismatched_receipt_preserves_original_intent(self) -> None:
        """Refuse a different title or parent for both create and status.

        Returns:
            None: Only a receipt matching PHP-normalized intent clears it.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            journal = GalleryCreateJournal(Path(temporary))
            issuer = 'https://gallery.example/Galerie'
            session.gallery_create_request.return_value = LinkedGallery(42, 9, 'Wrong')
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, ' \u00a0Title\u00a0 ', 9)
            session.gallery_create_request.return_value = LinkedGallery(42, None, '\u00a0Title\u00a0')
            with self.assertRaises(LinkingError):
                journal.reconcile(session, issuer, 7)
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'Another', None)
            receipt = LinkedGallery(42, 9, '\u00a0Title\u00a0')
            session.gallery_create_request.return_value = receipt
            bound = journal.reconcile(session, issuer, 7)
            self.assertEqual(receipt, bound.gallery)
            journal.acknowledge(issuer, 7, bound)

    def test_cancellation_before_and_after_dispatch(self) -> None:
        """Cancel before admission or retain a receipt after admitted work.

        Returns:
            None: Before admission no request is sent; after it a receipt survives.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            journal = GalleryCreateJournal(Path(temporary))
            issuer = 'https://gallery.example/Galerie'
            cancelled = CreateDispatch()
            cancelled.cancel()
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'Before', None, cancelled)
            session.gallery_create_request.assert_not_called()
            admitted = CreateDispatch()
            receipt = LinkedGallery(42, None, 'After')

            def close_after_dispatch(
                *_args: str | int | None, **_kwargs: threading.Event | None,
            ) -> LinkedGallery:
                """Simulate dialog closure after the request was admitted.

                Args:
                    *_args (str | int | None): Fake operation key, title and parent.
                    **_kwargs (threading.Event | None): Optional cancel signal.

                Returns:
                    LinkedGallery: Completed response arriving after closure.
                """
                admitted.cancel()
                return receipt

            session.gallery_create_request.side_effect = close_after_dispatch
            self.assertEqual(receipt, journal.create(session, issuer, 7, 'After', None, admitted).gallery)
            session.gallery_create_request.reset_mock()
            bound = GalleryCreateJournal(Path(temporary)).reconcile(session, issuer, 7)
            self.assertEqual(receipt, bound.gallery)
            self.assertEqual(1, session.gallery_create_request.call_count)
            self.assertEqual(1, len(session.gallery_create_request.call_args.args))
            journal.acknowledge(issuer, 7, bound)

    def test_interrupted_acknowledgement_retains_recoverable_receipt(self) -> None:
        """Keep the full intent in the receipt if pending removal completed first.

        Returns:
            None: A restart recovers the receipt and blocks any new create.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            receipt = LinkedGallery(42, None, 'Saved')
            session.gallery_create_request.return_value = receipt
            issuer = 'https://gallery.example/Galerie'
            journal = GalleryCreateJournal(Path(temporary))
            original_bound = journal.create(session, issuer, 7, 'Saved', None)
            self.assertEqual(receipt, original_bound.gallery)
            pending = journal._read()
            self.assertIsNotNone(pending)
            self.assertTrue(journal._remove_file(journal._path(), pending[1]))
            restarted = GalleryCreateJournal(Path(temporary))
            with self.assertRaises(LinkingError):
                restarted.create(session, issuer, 7, 'Second', None)
            session.gallery_create_request.reset_mock()
            bound = restarted.reconcile(session, issuer, 7)
            self.assertEqual(receipt, bound.gallery)
            self.assertEqual(original_bound, bound)
            self.assertEqual(1, session.gallery_create_request.call_count)
            self.assertEqual(1, len(session.gallery_create_request.call_args.args))
            restarted.acknowledge(issuer, 7, bound)

    def test_saved_receipt_requires_fresh_matching_server_status(self) -> None:
        """Keep local records when server access or gallery status changes.

        Returns:
            None: Unknown, changed and unavailable status cannot expose a target.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            receipt = LinkedGallery(42, None, 'Saved')
            session.gallery_create_request.return_value = receipt
            issuer = 'https://gallery.example/Galerie'
            journal = GalleryCreateJournal(Path(temporary))
            self.assertEqual(receipt, journal.create(session, issuer, 7, 'Saved', None).gallery)
            session.gallery_create_request.return_value = None
            self.assertIsNone(journal.reconcile(session, issuer, 7))
            session.gallery_create_request.return_value = LinkedGallery(43, None, 'Saved')
            with self.assertRaises(LinkingError):
                journal.reconcile(session, issuer, 7)
            session.gallery_create_request.side_effect = LinkingError('unavailable')
            with self.assertRaises(LinkingError):
                journal.reconcile(session, issuer, 7)
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'Second', None)
            self.assertIsNotNone(journal._read())
            self.assertIsNotNone(journal._read_receipt())

    def test_failed_receipt_persistence_retains_intent(self) -> None:
        """Never erase the operation key when completed receipt storage fails.

        Returns:
            None: Both an uncertain result and partial receipt block a new key.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.return_value = LinkedGallery(42, None, 'First')
            journal = GalleryCreateJournal(Path(temporary))
            issuer = 'https://gallery.example/Galerie'
            # Lease and intent flush succeed; the receipt flush fails.
            with mock.patch('uploader.linked_create.os.fsync',
                            side_effect=[None, None, OSError('receipt sync failed')]):
                with self.assertRaises(LinkingError):
                    journal.create(session, issuer, 7, 'First', None)
            self.assertIsNotNone(journal._read())
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'Second', None)
            self.assertEqual(1, session.gallery_create_request.call_count)
            session.gallery_create_request.reset_mock()
            self.assertEqual(LinkedGallery(42, None, 'First'),
                             journal.reconcile(session, issuer, 7).gallery)
            self.assertEqual(1, session.gallery_create_request.call_count)

    def test_receipt_staging_replacement_is_not_deleted(self) -> None:
        """Preserve a foreign staging replacement and the published receipt.

        Returns:
            None: Identity drift is refused without deleting the foreign file.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            receipt = LinkedGallery(42, None, 'First')
            session.gallery_create_request.return_value = receipt
            real_link = os.link
            replaced: list[Path] = []

            def replace_receipt_stage(source: Path, destination: Path) -> None:
                """Replace only receipt staging after exclusive publication.

                Args:
                    source (Path): Existing owned pending or receipt stage.
                    destination (Path): Final pending or receipt path.

                Returns:
                    None: Keeps a foreign file at the old receipt stage name.
                """
                real_link(source, destination)
                if destination == journal._receipt_path():
                    source.unlink()
                    source.write_bytes(b'foreign')
                    replaced.append(source)

            with mock.patch('uploader.linked_create.os.link', side_effect=replace_receipt_stage):
                with self.assertRaises(LinkingError):
                    journal.create(session, 'https://gallery.example', 7, 'First', None)
            self.assertEqual(1, len(replaced))
            self.assertEqual(b'foreign', replaced[0].read_bytes())
            self.assertIsNotNone(journal._read())
            self.assertEqual(receipt, journal._read_receipt()[1])
            session.gallery_create_request.reset_mock()
            self.assertEqual(receipt, journal.reconcile(session, 'https://gallery.example', 7).gallery)
            session.gallery_create_request.assert_called_once()

    def test_receipt_multiple_link_stage_is_not_published(self) -> None:
        """Refuse an extra staging hardlink before publishing the receipt.

        Returns:
            None: The unrelated link remains and no final receipt is created.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            journal._path().parent.mkdir(parents=True)
            intent = CreateIntent('https://gallery.example', 7, 'a' * 64, 'First', None)
            receipt = LinkedGallery(42, None, 'First')
            foreign = journal._path().parent / 'other-link'
            real_fsync, real_link = os.fsync, os.link

            def add_stage_link(descriptor: int) -> None:
                """Add one extra link to the owned stage during its flush.

                Args:
                    descriptor (int): Open stage file descriptor to flush.

                Returns:
                    None: Flushes the stage then creates the competing link.
                """
                real_fsync(descriptor)
                stage = next(journal._path().parent.glob('*.receipt.json.*.tmp'))
                real_link(stage, foreign)

            with mock.patch('uploader.linked_create.os.fsync', side_effect=add_stage_link):
                with self.assertRaises(LinkingError):
                    journal._publish_receipt(intent, receipt)
            self.assertIsNone(journal._read_receipt())
            self.assertTrue(foreign.is_file())

    def test_receipt_cleanup_uncertainty_keeps_both_records(self) -> None:
        """Retain a readable complete receipt if stage removal is uncertain.

        Returns:
            None: Restart reconciles the same key without a new create request.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            session.gallery_create_request.side_effect = LinkingError('lost reply')
            issuer = 'https://gallery.example'
            with self.assertRaises(LinkingError):
                journal.create(session, issuer, 7, 'First', None)
            intent = journal.pending_intent(issuer, 7)
            receipt = LinkedGallery(42, None, 'First')
            session.gallery_create_request.side_effect = None
            session.gallery_create_request.return_value = receipt
            with mock.patch.object(journal, '_remove_file', return_value=False):
                with self.assertRaises(LinkingError):
                    journal.reconcile(session, issuer, 7)
            self.assertEqual(intent, journal._read()[0])
            saved = journal._read_receipt()
            self.assertEqual(intent, saved[0])
            self.assertEqual(receipt, saved[1])
            session.gallery_create_request.reset_mock()
            restarted = GalleryCreateJournal(Path(temporary))
            self.assertEqual(receipt, restarted.reconcile(session, issuer, 7).gallery)
            session.gallery_create_request.assert_called_once_with(intent.operation_key)

    def test_existing_receipt_is_never_overwritten(self) -> None:
        """Keep the original receipt when another publication is attempted.

        Returns:
            None: The stored receipt bytes and pending key remain unchanged.
        """
        with tempfile.TemporaryDirectory() as temporary:
            journal = GalleryCreateJournal(Path(temporary))
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            receipt = LinkedGallery(42, None, 'First')
            session.gallery_create_request.return_value = receipt
            journal.create(session, 'https://gallery.example', 7, 'First', None)
            intent = journal._read()[0]
            before = journal._receipt_path().read_bytes()
            with self.assertRaises(LinkingError):
                journal._publish_receipt(intent, LinkedGallery(43, None, 'First'))
            self.assertEqual(before, journal._receipt_path().read_bytes())
            self.assertEqual(intent, journal._read()[0])

    def test_receipt_publish_failure_can_retry_status_without_resending(self) -> None:
        """Keep a valid intent if exclusive receipt publication fails.

        Returns:
            None: Read-only status can save a complete receipt on a later attempt.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            receipt = LinkedGallery(42, None, 'First')
            session.gallery_create_request.return_value = receipt
            journal = GalleryCreateJournal(Path(temporary))
            issuer = 'https://gallery.example/Galerie'
            real_link = os.link

            def fail_receipt(source: Path, destination: Path) -> None:
                """Fail only completed-receipt publication after pending succeeds.

                Args:
                    source (Path): Complete staging file for the publication.
                    destination (Path): Pending or completed journal destination.

                Returns:
                    None: Publishes pending or rejects the completed receipt.

                Raises:
                    OSError: The completed receipt cannot be published.
                """
                if destination == journal._receipt_path():
                    raise OSError('receipt publication failed')
                real_link(source, destination)

            with mock.patch('uploader.linked_create.os.link', side_effect=fail_receipt):
                with self.assertRaises(LinkingError):
                    journal.create(session, issuer, 7, 'First', None)
            self.assertIsNotNone(journal._read())
            session.gallery_create_request.reset_mock()
            self.assertEqual(receipt, journal.reconcile(session, issuer, 7).gallery)
            self.assertEqual(1, session.gallery_create_request.call_count)
            self.assertEqual(1, len(session.gallery_create_request.call_args.args))

    def test_failed_fsync_never_publishes_or_sends(self) -> None:
        """Keep an incomplete staging write away from the pending journal.

        Returns:
            None: The failed attempt does not send; a new explicit attempt can proceed.
        """
        with tempfile.TemporaryDirectory() as temporary:
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_create_gallery.return_value = True
            session.identity.return_value = LinkedIdentity(7, 'admin')
            journal = GalleryCreateJournal(Path(temporary))
            issuer = 'https://gallery.example/Galerie'
            # The first sync persists the lease; the second fails the intent.
            with mock.patch('uploader.linked_create.os.fsync',
                            side_effect=[None, OSError('journal sync failed')]):
                with self.assertRaises(LinkingError):
                    journal.create(session, issuer, 7, 'First', None)
            session.gallery_create_request.assert_not_called()
            self.assertIsNone(journal._read())
            session.gallery_create_request.return_value = LinkedGallery(42, None, 'Second')
            self.assertEqual(LinkedGallery(42, None, 'Second'),
                             journal.create(session, issuer, 7, 'Second', None).gallery)
            session.gallery_create_request.assert_called_once()


class GalleryCreateTransportTests(unittest.TestCase):
    """Validate exact creation and status wire shapes without network traffic."""

    def test_abandon_requires_exact_server_confirmation(self) -> None:
        """Distinguish a tombstone from unknown or a completed receipt.

        Returns:
            None: Only the exact closure response is accepted as abandoned.
        """
        token = TOKEN_PREFIX + base64.urlsafe_b64encode(b'x' * 32).rstrip(b'=').decode()
        session = LinkingSession('https://gallery.example', token,
                                 time.monotonic() + LINKING_MAX_TOKEN_LIFETIME_SECONDS,
                                 CREATE_SCOPE)
        key = 'a' * 64
        with mock.patch.object(session, '_request') as request:
            request.return_value = {'state': 'abandoned'}
            self.assertIsNone(session.gallery_create_request(key, 'New', None, abandon=True))
            self.assertEqual('app_linking_gallery_create_abandon', request.call_args.args[0])
            self.assertEqual({'operation_key': key, 'title': 'New', 'parent_id': None},
                             json.loads(request.call_args.kwargs['body']))
            for response in ({'state': 'unknown'}, {'state': 'abandoned', 'extra': True}):
                request.return_value = response
                with self.assertRaises(LinkingError):
                    session.gallery_create_request(key, 'New', None, abandon=True)
            request.return_value = {'state': 'completed', 'gallery': {
                'id': 42, 'title': 'New', 'parent_id': None,
            }}
            self.assertEqual(LinkedGallery(42, None, 'New'),
                             session.gallery_create_request(key, 'New', None, abandon=True))

    def test_explicit_scope_and_validated_status(self) -> None:
        """Use JSON once and reject a malformed completed result.

        Returns:
            None: Status unknown remains distinct from a completed receipt.
        """
        token = TOKEN_PREFIX + base64.urlsafe_b64encode(b'x' * 32).rstrip(b'=').decode()
        session = LinkingSession('https://gallery.example', token,
                                 time.monotonic() + LINKING_MAX_TOKEN_LIFETIME_SECONDS,
                                 CREATE_SCOPE)
        key = 'a' * 64
        with mock.patch.object(session, '_request') as request:
            request.return_value = {'state': 'unknown'}
            self.assertIsNone(session.gallery_create_request(key))
            self.assertEqual('app_linking_gallery_create_status', request.call_args.args[0])
            self.assertEqual({'operation_key': key},
                             json.loads(request.call_args.kwargs['body']))
            request.return_value = {'state': 'completed', 'gallery': {
                'id': 42, 'title': 'New', 'parent_id': None,
            }}
            self.assertEqual(LinkedGallery(42, None, 'New'),
                             session.gallery_create_request(key, 'New', None))
            self.assertEqual({'operation_key': key, 'title': 'New', 'parent_id': None},
                             json.loads(request.call_args.kwargs['body']))
            request.return_value['gallery']['id'] = True
            with self.assertRaises(LinkingError):
                session.gallery_create_request(key)
            for title in ('', ' ', 'bad\n', '\ud800', 'é' * 128):
                with self.subTest(title=repr(title)):
                    request.return_value = {'state': 'completed', 'gallery': {
                        'id': 42, 'title': title, 'parent_id': None,
                    }}
                    with self.assertRaises(LinkingError):
                        session.gallery_create_request(key)

    def test_cancelled_create_never_dispatches_transport(self) -> None:
        """A cancellation observed before transport admission prevents a send.

        Returns:
            None: The cancelled create leaves the HTTPS transport unused.
        """
        token = TOKEN_PREFIX + base64.urlsafe_b64encode(b'x' * 32).rstrip(b'=').decode()
        session = LinkingSession('https://gallery.example', token,
                                 time.monotonic() + LINKING_MAX_TOKEN_LIFETIME_SECONDS,
                                 CREATE_SCOPE)
        cancelled = threading.Event()
        cancelled.set()
        with mock.patch('uploader.app_linking._request_json') as request:
            with self.assertRaises(LinkingError):
                session.gallery_create_request('a' * 64, 'New', None,
                                               cancelled=cancelled)
            request.assert_not_called()


if __name__ == '__main__':
    unittest.main()
