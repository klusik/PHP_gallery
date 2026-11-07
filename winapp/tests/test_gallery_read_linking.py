# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_read_linking.py
# Module Type: Regression Test
# Purpose: Verify explicit gallery-read scope and bounded detail parsing.
# Responsibilities:
#   - Preserve existing scope authority and offline credential indices.
#   - Reject malformed, unauthorized or mismatched gallery detail responses.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise gallery-detail authorization without network or native APIs."""

import base64
import time
import unittest
from unittest.mock import patch

from uploader import app_linking as linking
from uploader import offline_linking as offline


def _token() -> str:
    """Construct one deterministic canonical fixture access token.

    Returns:
        str: Test bearer with a valid protocol prefix and 256-bit payload.
    """
    return linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
        b'a' * linking.ATTEMPT_RANDOM_BYTES,
    ).rstrip(b'=').decode('ascii')


class GalleryReadLinkingTests(unittest.TestCase):
    """Check explicit gallery detail permissions and exact response shape."""

    def setUp(self) -> None:
        """Prepare one selected gallery and its non-secret detail response.

        Returns:
            None: Fixture issuer, target and response are ready.
        """
        self.issuer = 'https://gallery.example/Galerie'
        self.response = {'gallery': {
            'id': 42, 'title': 'Selected gallery', 'revision': '7',
            'visibility': 'private', 'access_mode': 'password',
            'access_listing': 'unlisted',
        }}

    def _session(self, scope: str) -> linking.LinkingSession:
        """Create a bounded local session for one exact scope fixture.

        Args:
            scope (str): Registered transient or offline scope bundle.

        Returns:
            linking.LinkingSession: Session closed automatically after the test.
        """
        session = linking.LinkingSession(self.issuer, _token(), time.monotonic() + 120, scope)
        self.addCleanup(session.close)
        return session

    def test_old_scopes_never_gain_gallery_detail(self) -> None:
        """Keep all six existing grants unchanged while adding read scopes.

        Returns:
            None: Old sessions reject detail before attempting any request.
        """
        for scope in (linking.IDENTITY_SCOPE, linking.GALLERIES_SCOPE,
                      linking.UPLOAD_SCOPE, linking.IDENTITY_OFFLINE_SCOPE,
                      linking.GALLERIES_OFFLINE_SCOPE, linking.UPLOAD_OFFLINE_SCOPE):
            with self.subTest(scope=scope):
                session = self._session(scope)
                self.assertFalse(session.can_read_gallery_detail())
                with patch.object(linking, '_request_json') as request:
                    with self.assertRaises(linking.LinkingError):
                        session.gallery_detail(42)
                    request.assert_not_called()

    def test_exact_read_scopes_request_selected_id(self) -> None:
        """Read only the named gallery and preserve exact optional upload scope.

        Returns:
            None: Four new bundles return the strict non-secret projection.
        """
        for scope in (linking.READ_SCOPE, linking.READ_UPLOAD_SCOPE,
                      linking.READ_OFFLINE_SCOPE, linking.READ_UPLOAD_OFFLINE_SCOPE):
            with self.subTest(scope=scope):
                session = self._session(scope)
                self.assertTrue(session.can_read_gallery_detail())
                self.assertTrue(session.can_list_galleries())
                self.assertEqual(scope in (linking.READ_UPLOAD_SCOPE,
                                           linking.READ_UPLOAD_OFFLINE_SCOPE),
                                 session.can_upload())
                with patch.object(linking, '_request_json', return_value=self.response) as send:
                    detail = session.gallery_detail(42)
                self.assertEqual(42, detail.gallery_id)
                self.assertEqual('7', detail.revision)
                self.assertEqual('password', detail.access_mode)
                self.assertEqual('app_linking_gallery_detail', send.call_args.args[1])
                self.assertEqual(42, send.call_args.kwargs['gallery_id'])
                self.assertNotIn('folder', repr(detail))

    def test_transport_keeps_detail_id_out_of_bearer_and_other_routes(self) -> None:
        """Pass only the selected positive ID to the fixed detail endpoint.

        Returns:
            None: No invalid ID or unrelated route reaches the HTTPS worker.
        """
        session = self._session(linking.READ_SCOPE)
        with patch.object(linking, '_request_json_once', return_value=self.response) as send:
            detail = session.gallery_detail(42)
        self.assertEqual(42, detail.gallery_id)
        self.assertEqual('app_linking_gallery_detail', send.call_args.args[1])
        self.assertEqual(42, send.call_args.kwargs['gallery_id'])
        for invalid in (0, -1, True, linking.LINKING_GALLERY_ID_MAX + 1):
            with self.subTest(invalid=invalid):
                with patch.object(linking, '_request_json_once') as send:
                    with self.assertRaises(linking.LinkingError):
                        session.gallery_detail(invalid)
                    send.assert_not_called()
        with patch.object(linking, '_request_json_once') as send:
            with self.assertRaises(linking.LinkingError):
                linking._request_json(
                    self.issuer, 'app_linking_identity', 'GET', None, _token(),
                    gallery_id=42,
                )
            send.assert_not_called()

    def test_malformed_or_extra_fields_are_refused(self) -> None:
        """Refuse mismatched IDs, bad revision and unexpected path fields.

        Returns:
            None: No malformed server response yields a detail object.
        """
        session = self._session(linking.READ_SCOPE)
        row = self.response['gallery']
        cases = (
            {'gallery': {**row, 'id': 43}},
            {'gallery': {**row, 'id': True}},
            {'gallery': {**row, 'revision': '00'}},
            {'gallery': {**row, 'revision': str(linking.LINKING_GALLERY_REVISION_MAX + 1)}},
            {'gallery': {**row, 'access_mode': 'inherited'}},
            {'gallery': {**row, 'folder_path': '/private/path'}},
            {'gallery': row, 'extra': True},
        )
        for response in cases:
            with self.subTest(response=response):
                with patch.object(linking, '_request_json', return_value=response):
                    with self.assertRaises(linking.LinkingError):
                        session.gallery_detail(42)

    def test_refresh_record_indices_are_append_only(self) -> None:
        """Keep the three existing serialized scope indices unchanged.

        Returns:
            None: Old payloads decode unchanged and new scopes append only.
        """
        self.assertEqual((linking.IDENTITY_OFFLINE_SCOPE, linking.GALLERIES_OFFLINE_SCOPE,
                          linking.UPLOAD_OFFLINE_SCOPE), offline._OFFLINE_SCOPES[:3])
        self.assertEqual((linking.READ_OFFLINE_SCOPE, linking.READ_UPLOAD_OFFLINE_SCOPE),
                         offline._OFFLINE_SCOPES[3:5])
        appended_scopes = (
            linking.CREATE_OFFLINE_SCOPE,
            linking.READ_CREATE_OFFLINE_SCOPE,
            linking.UPLOAD_CREATE_OFFLINE_SCOPE,
            linking.READ_UPLOAD_CREATE_OFFLINE_SCOPE,
            linking.VISIBILITY_OFFLINE_SCOPE,
            linking.UPLOAD_VISIBILITY_OFFLINE_SCOPE,
            linking.CREATE_VISIBILITY_OFFLINE_SCOPE,
            linking.UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
        )
        self.assertEqual(
            appended_scopes,
            offline._OFFLINE_SCOPES[5:5 + len(appended_scopes)],
        )
        self.assertEqual(len(offline._OFFLINE_SCOPES), len(set(offline._OFFLINE_SCOPES)))


if __name__ == '__main__':
    unittest.main()
