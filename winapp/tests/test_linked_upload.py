# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_linked_upload.py
# Module Type: Regression Test
# Purpose: Verify delegated upload stream and local producer isolation.
# Responsibilities:
#   - Prevent replay and detect changed image content.
#   - Check exclusive target leases and conservative recovery.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable linked upload tests without real network or Windows APIs."""

import hashlib
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.linking import LinkingError, LinkingSession
from uploader.flight_delivery import deliver_report, FlightDeliveryError
from uploader.linked_upload import (
    LinkedMultipart, LinkedTargetLease, LinkedUploadTarget, capture_linked_source,
    delete_verified_linked_source, verify_linked_source,
)


class LinkedUploadTests(unittest.TestCase):
    """Exercise one-shot transfer and cooperative cross-process ownership."""

    def test_linked_reports_reconcile_and_stream_only_to_the_captured_gallery(self) -> None:
        """Use the existing receipt owner with delegated rather than legacy keys.

        Returns:
            None: Verifies status-before-upload, exact PDF MIME bytes, target
                binding, one-shot replay refusal and same-gallery receipts.
        """
        with tempfile.TemporaryDirectory() as temporary:
            pdf = Path(temporary) / 'flight.pdf'
            content = b'%PDF-1.4\nsynthetic flight report\n%%EOF\n'
            pdf.write_bytes(content)
            digest = hashlib.sha256(content).hexdigest()
            session = LinkingSession.__new__(LinkingSession)
            session.can_upload = Mock(return_value=True)
            session.matches_issuer = Mock(return_value=True)
            session._request = Mock(side_effect=[
                {'ok': True, 'action': 'flight_report_status', 'gallery_id': 7,
                 'found': False, 'conflict': False, 'report': None},
                {'ok': True, 'action': 'flight_report', 'gallery_id': 7,
                 'found': True, 'conflict': False, 'report': {
                     'id': 'a' * 32, 'sha256': digest, 'size': len(content),
                     'filename': 'flight-report.pdf',
                 }},
            ])
            target = LinkedUploadTarget(session, 'https://gallery.test', 1, 7, 'Fixture')
            receipt = deliver_report('', '', pdf, 'session', 'en', 'revision',
                                     request=target.report_request)
            self.assertEqual(digest, receipt['sha256'])
            status, upload = session._request.call_args_list
            self.assertEqual('app_linking_inventory', status.args[0])
            self.assertIn(b'"gallery_id":7', status.kwargs['body'])
            self.assertEqual('app_linking_upload', upload.args[0])
            body = upload.kwargs['body']
            wire = b''.join(body)
            self.assertEqual(len(wire), upload.kwargs['content_length'])
            self.assertIn(content, wire)
            self.assertIn(b'name="report"', wire)
            self.assertNotIn(b'name="images[]"', wire)
            with self.assertRaises(LinkingError):
                b''.join(body)
            with self.assertRaises(FlightDeliveryError):
                target.report_request('https://legacy.test', 'legacy-secret')
            self.assertEqual(2, session._request.call_count)

    def test_linked_report_receipt_from_another_gallery_is_rejected(self) -> None:
        """Refuse a mismatched target even when its report envelope looks valid.

        Returns:
            None: A foreign-gallery acknowledgement never authorizes completion.
        """
        session = LinkingSession.__new__(LinkingSession)
        session.can_upload = Mock(return_value=True)
        session._request = Mock(return_value={
            'ok': True, 'action': 'flight_report_status', 'gallery_id': 8,
        })
        with self.assertRaises(LinkingError):
            session.flight_report_request(
                7, {'action': 'flight_report_status'}, None, None, None,
            )

    def test_linked_source_rejects_replacement_or_changed_bytes(self) -> None:
        """Recheck one captured source before a linked duplicate skip.

        Returns:
            None: Replacement and in-place modification both fail closed.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'source.jpg'
            source.write_bytes(b'original')
            digest = hashlib.sha256(b'original').hexdigest()
            snapshot = capture_linked_source(source, digest, 8)
            verify_linked_source(snapshot)
            replacement = root / 'replacement.jpg'
            replacement.write_bytes(b'original')
            replacement.replace(source)
            with self.assertRaises(LinkingError):
                verify_linked_source(snapshot)
            snapshot = capture_linked_source(source, digest, 8)
            source.write_bytes(b'modified')
            with self.assertRaises(LinkingError):
                verify_linked_source(snapshot)

    @unittest.skipUnless(os.name == 'nt', 'Same-handle disposition is Windows-only.')
    def test_acknowledged_source_is_deleted_through_its_verified_handle(self) -> None:
        """Delete an unchanged single-link source by its verified Windows handle.

        Returns:
            None: The captured path is absent only after its bytes are rechecked.
        """
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'source.jpg'
            contents = b'confirmed upload source'
            source.write_bytes(contents)
            snapshot = capture_linked_source(
                source, hashlib.sha256(contents).hexdigest(), len(contents),
            )
            delete_verified_linked_source(snapshot)
            self.assertFalse(source.exists())

    @unittest.skipUnless(os.name == 'nt', 'Same-handle disposition is Windows-only.')
    def test_verified_handle_deletion_refuses_a_replacement(self) -> None:
        """Keep replacement bytes after an earlier source was acknowledged.

        Returns:
            None: The held deletion handle rejects a changed file identity.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'source.jpg'
            source.write_bytes(b'original source')
            snapshot = capture_linked_source(
                source, hashlib.sha256(b'original source').hexdigest(), 15,
            )
            replacement = root / 'replacement.jpg'
            replacement.write_bytes(b'replacement bytes')
            replacement.replace(source)
            with self.assertRaises(LinkingError):
                delete_verified_linked_source(snapshot)
            self.assertEqual(b'replacement bytes', source.read_bytes())

    @unittest.skipUnless(os.name == 'nt', 'Same-handle disposition is Windows-only.')
    def test_verified_handle_deletion_retains_hardlinked_or_busy_source(self) -> None:
        """Refuse ambiguous links and a conflicting open source on Windows.

        Returns:
            None: Both the original bytes and any alias remain available.
        """
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'source.jpg'
            contents = b'confirmed bytes must be retained'
            source.write_bytes(contents)
            snapshot = capture_linked_source(
                source, hashlib.sha256(contents).hexdigest(), len(contents),
            )
            # A second hardlink violates the one-source-link deletion contract.
            alias = Path(directory) / 'alias.jpg'
            os.link(source, alias)
            with self.assertRaises(LinkingError):
                delete_verified_linked_source(snapshot)
            self.assertEqual(contents, source.read_bytes())
            self.assertEqual(contents, alias.read_bytes())
            alias.unlink()

            # A CRT read handle does not share DELETE access, so native delete
            # admission must fail without affecting the acknowledged bytes.
            with source.open('rb'):
                with self.assertRaises(OSError):
                    delete_verified_linked_source(snapshot)
            self.assertEqual(contents, source.read_bytes())

    def test_multipart_is_one_shot_and_declares_exact_length(self) -> None:
        """Stream the original once with a matching multipart content length.

        Returns:
            None: A second iteration is refused.
        """
        with tempfile.TemporaryDirectory() as directory:
            image = Path(directory) / 'image.jpg'
            contents = b'portable image fixture'
            image.write_bytes(contents)
            digest = hashlib.sha256(contents).hexdigest()
            body = LinkedMultipart(3, image, True, digest)
            encoded = b''.join(body)
            self.assertEqual(body.content_length, len(encoded))
            self.assertIn(contents, encoded)
            self.assertIn(b'name="gallery_id"', encoded)
            with self.assertRaises(LinkingError):
                list(body)

    def test_changed_file_is_never_sent_as_a_complete_body(self) -> None:
        """Detect content that changed after the preflight hash was captured.

        Returns:
            None: The request iterator fails before its closing boundary.
        """
        with tempfile.TemporaryDirectory() as directory:
            image = Path(directory) / 'image.jpg'
            image.write_bytes(b'original')
            digest = hashlib.sha256(b'other data').hexdigest()
            body = LinkedMultipart(3, image, False, digest)
            with self.assertRaises(LinkingError):
                list(body)

    def test_target_lease_refuses_competing_owner_and_preserves_replacement(self) -> None:
        """Prevent overlapping producers and avoid deleting a replaced lock.

        Returns:
            None: Another owner is refused until explicit recovery.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            binding = 'a' * 64
            first = LinkedTargetLease(root, binding)
            second = LinkedTargetLease(root, binding)
            first.acquire()
            with self.assertRaises(LinkingError):
                second.acquire()
            lock = root / 'linked-upload-state' / (binding + '.lock')
            try:
                lock.unlink()
            except PermissionError:
                first.release()
                self.skipTest('This filesystem disallows replacing an open lease.')
            try:
                lock.write_text('another owner', encoding='ascii')
            finally:
                first.release()
            self.assertEqual('another owner', lock.read_text(encoding='ascii'))
            with self.assertRaises(LinkingError):
                second.acquire()

    def test_older_lease_is_never_stolen(self) -> None:
        """Keep an older marker whose native ownership cannot be verified.

        Returns:
            None: Acquisition refuses the stale file without modifying it.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            binding = 'b' * 64
            state_dir = root / 'linked-upload-state'
            state_dir.mkdir()
            lock = state_dir / (binding + '.lock')
            marker = 'php-gallery-linked-lease-v1:' + 'c' * 32 + '\n'
            lock.write_bytes(marker.encode('ascii'))
            with self.assertRaises(LinkingError):
                LinkedTargetLease(root, binding).acquire()
            self.assertEqual(marker, lock.read_text(encoding='ascii'))
