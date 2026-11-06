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
import sys
import tempfile
import unittest
from pathlib import Path

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.app_linking import LinkingError
from uploader.linked_upload import (
    LinkedMultipart, LinkedTargetLease, capture_linked_source, verify_linked_source,
)


class LinkedUploadTests(unittest.TestCase):
    """Exercise one-shot transfer and cooperative cross-process ownership."""

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
