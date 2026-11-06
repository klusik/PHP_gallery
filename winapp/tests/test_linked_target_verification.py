# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_linked_target_verification.py
# Module Type: Regression Test
# Purpose: Verify a selected linked target without Windows or network APIs.
# Responsibilities:
#   - Recheck the exact session identity and one bounded gallery page.
#   - Refuse cancellation, changed scope, account or gallery evidence.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise fresh linked-target verification with isolated session doubles."""

import base64
import threading
import time
import unittest
from unittest.mock import Mock

from uploader.app_linking import (
    LinkedGallery, LinkedGalleryPage, LinkedIdentity, LinkingError,
    LinkingSession, TOKEN_PREFIX, UPLOAD_SCOPE,
)
from uploader.linked_upload import verify_linked_upload_target


# Purpose: Keep an isolated fake bearer live for the scope accessor fixture.
# Type: float. Units: seconds. Scope: one non-network session.
# Consumers: test_scope_accessor_never_returns_a_closed_session.
# Rationale: 60 seconds allows the assertion to run without a timing race.
LINKED_TARGET_FIXTURE_SESSION_SECONDS = 60.0


class LinkedTargetVerificationTests(unittest.TestCase):
    """Check explicit target and session binding without real HTTP."""

    def setUp(self) -> None:
        """Prepare a single live upload-scoped simulated session.

        Returns:
            None: Creates isolated identity and page responses.
        """
        self.session = Mock(spec=LinkingSession)
        self.session.matches_issuer.return_value = True
        self.session.can_upload.return_value = True
        self.session.approved_scope.return_value = UPLOAD_SCOPE
        self.session.identity.return_value = LinkedIdentity(7, 'admin')
        self.session.gallery_page.return_value = LinkedGalleryPage(
            (LinkedGallery(12, None, 'Current gallery'),), None,
        )

    def test_scope_accessor_never_returns_a_closed_session(self) -> None:
        """Expose only the approved scope and refuse after local closure.

        Returns:
            None: The bearer remains private and closed access is refused.
        """
        token = TOKEN_PREFIX + base64.urlsafe_b64encode(b'x' * 32).rstrip(b'=').decode('ascii')
        session = LinkingSession(
            'https://gallery.example', token,
            time.monotonic() + LINKED_TARGET_FIXTURE_SESSION_SECONDS, UPLOAD_SCOPE,
        )
        self.assertEqual(UPLOAD_SCOPE, session.approved_scope())
        session.close()
        with self.assertRaises(LinkingError):
            session.approved_scope()

    def test_one_page_between_two_identity_reads(self) -> None:
        """Use a direct exclusive cursor and retain the fresh server title.

        Returns:
            None: Checks the exact returned target and bounded request count.
        """
        target = verify_linked_upload_target(
            self.session, 'https://gallery.example', 7, UPLOAD_SCOPE, 12,
        )
        self.assertIs(self.session, target.session)
        self.assertEqual((7, 12, 'Current gallery'),
                         (target.user_id, target.gallery_id, target.title))
        self.assertEqual(2, self.session.identity.call_count)
        self.session.gallery_page.assert_called_once_with(11, None)
        calls = [call[0] for call in self.session.mock_calls
                 if call[0] in ('identity', 'gallery_page')]
        self.assertEqual(['identity', 'gallery_page', 'identity'], calls)

    def test_first_gallery_uses_first_page(self) -> None:
        """Request the first page when the selected ID has no positive predecessor.

        Returns:
            None: Checks ID one without sending an invalid zero cursor.
        """
        self.session.gallery_page.return_value = LinkedGalleryPage(
            (LinkedGallery(1, None, 'First'),), None,
        )
        verify_linked_upload_target(
            self.session, 'https://gallery.example', 7, UPLOAD_SCOPE, 1,
        )
        self.session.gallery_page.assert_called_once_with(None, None)

    def test_missing_or_changed_evidence_never_returns_target(self) -> None:
        """Refuse changed account, scope, issuer and missing exact gallery.

        Returns:
            None: Each independent changed session or response is refused.
        """
        cases = ('first_identity', 'second_identity', 'scope', 'issuer',
                 'upload', 'missing_gallery')
        for case in cases:
            with self.subTest(case=case):
                self.setUp()
                if case == 'first_identity':
                    self.session.identity.return_value = LinkedIdentity(8, 'other')
                elif case == 'second_identity':
                    self.session.identity.side_effect = [
                        LinkedIdentity(7, 'admin'), LinkedIdentity(8, 'other'),
                    ]
                elif case == 'scope':
                    self.session.approved_scope.return_value = 'link:identity'
                elif case == 'issuer':
                    self.session.matches_issuer.return_value = False
                elif case == 'upload':
                    self.session.can_upload.return_value = False
                else:
                    self.session.gallery_page.return_value = LinkedGalleryPage(
                        (LinkedGallery(13, None, 'Other'),), None,
                    )
                with self.assertRaises(LinkingError):
                    verify_linked_upload_target(
                        self.session, 'https://gallery.example', 7, UPLOAD_SCOPE, 12,
                    )

    def test_cancel_before_or_during_any_read_is_not_published(self) -> None:
        """Discard responses when cancellation occurs before or during reads.

        Returns:
            None: Checks both identity reads and the gallery-page boundary.
        """
        for stage in ('before', 'identity_one', 'page', 'identity_two'):
            with self.subTest(stage=stage):
                self.setUp()
                cancelled = threading.Event()
                if stage == 'before':
                    cancelled.set()
                elif stage == 'identity_one':
                    self.session.identity.side_effect = lambda: (
                        cancelled.set() or LinkedIdentity(7, 'admin')
                    )
                elif stage == 'page':
                    self.session.gallery_page.side_effect = lambda _cursor, _cancel: (
                        cancelled.set() or LinkedGalleryPage(
                            (LinkedGallery(12, None, 'Current gallery'),), None,
                        )
                    )
                else:
                    def identity() -> LinkedIdentity:
                        """Cancel only while the second identity read completes.

                        Returns:
                            LinkedIdentity: Same account seen on both reads.
                        """
                        if self.session.identity.call_count == 2:
                            cancelled.set()
                        return LinkedIdentity(7, 'admin')

                    self.session.identity.side_effect = identity
                with self.assertRaises(LinkingError):
                    verify_linked_upload_target(
                        self.session, 'https://gallery.example', 7,
                        UPLOAD_SCOPE, 12, cancelled,
                    )
