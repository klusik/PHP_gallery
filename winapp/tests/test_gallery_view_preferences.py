# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_view_preferences.py
# Module Type: Local Gallery Preference Regression Tests
# Purpose: Verify independent and atomic WinApp-only photo-card preferences.
# Responsibilities:
#   - Cover defaults, gallery/account isolation and immediate saved choices.
#   - Preserve existing choices when persistence fails or data is malformed.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Headless behavioral coverage for local gallery card presentation choices."""

import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock


WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import gallery_view_preferences as preferences


class GalleryViewPreferenceTests(unittest.TestCase):
    """Verify local view choices without a server, Tk window or live profile."""

    def test_absent_and_invalid_choices_keep_natural_default(self) -> None:
        """Keep previous presentation for absent preferences and invalid input.

        Returns:
            None: No local file is created by a passive read or invalid save.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "account.json"
            # IDs 1 and 2 represent distinct gallery identities in these tests.
            self.assertEqual("default", preferences.load_gallery_card_format(path, 1))
            self.assertEqual("default", preferences.normalize_card_format("unknown"))
            self.assertFalse(preferences.save_gallery_card_format(path, True, "1:1"))
            self.assertFalse(preferences.save_gallery_card_format(path, 1, "unknown"))
            self.assertFalse(path.exists())

    def test_choices_save_immediately_and_remain_gallery_specific(self) -> None:
        """Round-trip each advertised format while preserving another gallery.

        Returns:
            None: Saved choices survive a fresh read independently per gallery.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "account.json"
            self.assertTrue(preferences.save_gallery_card_format(path, 2, "4:5"))
            for value in preferences.PHOTO_CARD_FORMATS:
                with self.subTest(value=value):
                    self.assertTrue(preferences.save_gallery_card_format(path, 1, value))
                    self.assertEqual(value, preferences.load_gallery_card_format(path, 1))
                    self.assertEqual("4:5", preferences.load_gallery_card_format(path, 2))

    def test_same_gallery_id_is_isolated_by_identity_path(self) -> None:
        """Keep identical IDs independent across two verified account paths.

        Returns:
            None: Each account file retains its own choice and unrelated files.
        """
        with tempfile.TemporaryDirectory() as directory:
            first = Path(directory) / "issuer-account-a.view.json"
            second = Path(directory) / "issuer-account-b.view.json"
            tree = Path(directory) / "issuer-account-a.json"
            original = '{"version":2,"open":{"1":true}}'
            tree.write_text(original, encoding="utf-8")
            self.assertTrue(preferences.save_gallery_card_format(first, 1, "21:9"))
            self.assertTrue(preferences.save_gallery_card_format(second, 1, "9:16"))
            self.assertEqual("21:9", preferences.load_gallery_card_format(first, 1))
            self.assertEqual("9:16", preferences.load_gallery_card_format(second, 1))
            self.assertEqual(original, tree.read_text(encoding="utf-8"))

    def test_default_removes_only_its_gallery_override(self) -> None:
        """Reset one gallery without resetting its neighboring saved choices.

        Returns:
            None: The default is implicit and the other gallery remains saved.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "account.json"
            preferences.save_gallery_card_format(path, 1, "16:9")
            preferences.save_gallery_card_format(path, 2, "1:1")
            self.assertTrue(preferences.save_gallery_card_format(path, 1, "default"))
            payload = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual({"2": "1:1"}, payload["card_formats"])

    def test_corrupt_or_unknown_schema_is_not_overwritten(self) -> None:
        """Preserve unreadable preferences rather than silently losing peers.

        Returns:
            None: Reads fall back to default; writes refuse and preserve bytes.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "account.json"
            # Broken JSON and unsupported version are separate recovery cases.
            for original in ('{broken', '{"version":99,"card_formats":{}}'):
                with self.subTest(original=original):
                    path.write_text(original, encoding="utf-8")
                    self.assertEqual("default", preferences.load_gallery_card_format(path, 1))
                    self.assertFalse(preferences.save_gallery_card_format(path, 1, "1:1"))
                    self.assertEqual(original, path.read_text(encoding="utf-8"))

    def test_capacity_refuses_new_gallery_but_allows_existing_choice(self) -> None:
        """Retain existing state when the configured gallery capacity is full.

        Returns:
            None: Boundary admission refuses a new ID and permits a same-ID edit.
        """
        with tempfile.TemporaryDirectory() as directory, mock.patch.object(
            preferences, "GALLERY_VIEW_PREFERENCES_MAX_GALLERIES", 1,
        ):
            # A one-entry boundary exercises full-state admission without bulk IO.
            path = Path(directory) / "account.json"
            self.assertTrue(preferences.save_gallery_card_format(path, 1, "16:9"))
            self.assertFalse(preferences.save_gallery_card_format(path, 2, "4:3"))
            self.assertTrue(preferences.save_gallery_card_format(path, 1, "21:9"))
            self.assertEqual("21:9", preferences.load_gallery_card_format(path, 1))

    def test_failed_atomic_write_does_not_report_saved(self) -> None:
        """Report failed persistence while retaining the previous valid file.

        Returns:
            None: A failed atomic publication returns False and changes no bytes.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "account.json"
            preferences.save_gallery_card_format(path, 1, "4:3")
            original = path.read_bytes()
            with mock.patch.object(
                preferences, "atomic_write_json", side_effect=OSError("read only"),
            ):
                self.assertFalse(preferences.save_gallery_card_format(path, 1, "1:1"))
            self.assertEqual(original, path.read_bytes())
