# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_linked_upload_state.py
# Module Type: Regression Test
# Purpose: Preserve separate upload histories for each linked gallery target.
# Responsibilities:
#   - Refuse unsafe target-state path identities.
#   - Keep legacy and separately bound target histories independent.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable local-state checks without native Windows APIs."""

import tempfile
import unittest
from pathlib import Path

from uploader.state_store import (
    atomic_write_json,
    linked_upload_state_path,
    load_upload_state,
    merge_upload_sections,
)


class LinkedUploadStateTests(unittest.TestCase):
    """Keep linked target evidence distinct from unbound legacy history."""

    def test_history_is_separate_for_each_binding(self) -> None:
        """Leave old and other-gallery hashes unavailable to a new target.

        Returns:
            None: Each target sees only its own confirmed stored history.
        """
        with tempfile.TemporaryDirectory() as temporary:
            config_dir = Path(temporary)
            legacy = config_dir / 'upload_state.json'
            atomic_write_json(legacy, {'uploaded_hashes': {'legacy': {'ok': True}}})
            first = linked_upload_state_path(config_dir, 'a' * 64)
            second = linked_upload_state_path(config_dir, 'b' * 64)
            self.assertNotEqual(first, second)
            first_state = load_upload_state(first)
            self.assertEqual({}, first_state['uploaded_hashes'])
            confirmed = merge_upload_sections(first, {
                'uploaded_hashes': {'first': {'ok': True}},
                'uploaded_paths': {}, 'failures': {},
            }, {
                'uploaded_hashes': first_state['uploaded_hashes'],
                'uploaded_paths': first_state['uploaded_paths'],
                'failures': first_state['failures'],
            })
            self.assertEqual({'first': {'ok': True}}, confirmed['uploaded_hashes'])
            self.assertEqual({'first': {'ok': True}},
                             load_upload_state(first)['uploaded_hashes'])
            self.assertEqual({}, load_upload_state(second)['uploaded_hashes'])
            self.assertEqual({'legacy': {'ok': True}},
                             load_upload_state(legacy)['uploaded_hashes'])

    def test_invalid_binding_and_symlinks_are_rejected(self) -> None:
        """Reject path traversal and redirected linked-history destinations.

        Returns:
            None: Invalid bindings and an available symlink fail closed.
        """
        with tempfile.TemporaryDirectory() as temporary:
            config_dir = Path(temporary)
            for value in ('', '../other', 'A' * 64, 'a' * 63, 'g' * 64):
                with self.subTest(binding=value):
                    with self.assertRaises(ValueError):
                        linked_upload_state_path(config_dir, value)
            outside = config_dir / 'outside'
            outside.mkdir()
            try:
                (config_dir / 'linked-upload-state').symlink_to(
                    outside, target_is_directory=True,
                )
            except OSError:
                self.skipTest('Directory symlinks are unavailable on this host.')
            with self.assertRaises(ValueError):
                linked_upload_state_path(config_dir, 'a' * 64)


if __name__ == '__main__':
    unittest.main()
