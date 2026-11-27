# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_linking_scope_controls.py
# Module Type: Linking permission contract regression
# Purpose: Keep Tk and Qt consent selections mapped to the same exact scopes.
# Responsibilities: Cover dependencies, optional scopes and unavailable choices.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Verify the shared, toolkit-free scope mapping used by linking controls."""

import sys
import unittest
from pathlib import Path

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.app_linking import (
    GALLERIES_OFFLINE_SCOPE, GALLERIES_SCOPE, IDENTITY_OFFLINE_SCOPE, READ_SCOPE,
    UPLOAD_SCOPE, LinkingError,
    requested_linking_scope,
)


CAPABILITIES = frozenset({
    'galleries:list', 'gallery:upload', 'gallery:read', 'galleries:create',
    'gallery:visibility', 'media:read', 'media:admin', 'presentation:read',
    'offline_access',
})


class LinkingScopeControlTests(unittest.TestCase):
    """Keep scope choices ordered, dependent and constrained by discovery."""

    def _scope(self, **choices: bool | frozenset[str]) -> str:
        """Map one partial set of test choices through the shared owner.

        Args:
            choices (bool | frozenset[str]): Each explicit test value overrides
                its corresponding Boolean default or capability set.
        Returns:
            str: Canonical scope accepted by the shared linking helper.
        """
        values = {
            'list_galleries': False, 'upload': False, 'read_details': False,
            'create_galleries': False, 'change_visibility': False,
            'read_media': False, 'remember_securely': False,
            'capabilities': CAPABILITIES, 'visibility_ready': True,
            'native_media_ready': True, 'offline_storage_ready': True,
        }
        values.update(choices)
        return requested_linking_scope(**values)

    def test_dependencies_and_optional_scopes_share_one_canonical_mapping(self) -> None:
        """Require listing/read dependencies and keep media temporary.

        Returns:
            None: Each UI selection maps to the same requested scope bundle.
        """
        self.assertEqual(self._scope(list_galleries=True), GALLERIES_SCOPE)
        self.assertEqual(
            self._scope(upload=True), UPLOAD_SCOPE,
        )
        self.assertEqual(
            self._scope(read_details=True), READ_SCOPE,
        )
        self.assertEqual(
            self._scope(create_galleries=True),
            GALLERIES_SCOPE + ' galleries:create',
        )
        self.assertEqual(
            self._scope(change_visibility=True),
            READ_SCOPE + ' gallery:visibility',
        )
        self.assertEqual(
            self._scope(read_media=True),
            GALLERIES_SCOPE + ' media:admin presentation:read',
        )
        self.assertEqual(
            self._scope(remember_securely=True),
            IDENTITY_OFFLINE_SCOPE,
        )
        self.assertEqual(
            self._scope(list_galleries=True, remember_securely=True),
            GALLERIES_OFFLINE_SCOPE,
        )

    def test_unavailable_scope_choices_fail_closed(self) -> None:
        """Reject choices whose capability or platform owner is absent.

        Returns:
            None: Unsupported selections never reach an authorization request.
        """
        with self.assertRaises(LinkingError):
            self._scope(upload=True, capabilities=frozenset({'galleries:list'}))
        with self.assertRaises(LinkingError):
            self._scope(
                read_media=True, remember_securely=True,
            )
        with self.assertRaises(LinkingError):
            self._scope(
                remember_securely=True, offline_storage_ready=False,
            )
