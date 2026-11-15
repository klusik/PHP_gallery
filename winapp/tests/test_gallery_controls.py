# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_controls.py
# Module Type: Gallery Controls Regression Tests
# Purpose: Verify persistent local ordering and revision-protected metadata edits.
# Responsibilities:
#   - Cover whole-catalogue reverse paging, refresh insertion and cancellation.
#   - Preserve independent local choices and existing grant authority.
#   - Exercise complete fresh text, single-field writes and unknown outcomes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Behavioral gallery-control coverage without Tk, a real account or network."""

import base64
import json
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest import mock

from uploader import app_linking as linking
from uploader import gallery_view_preferences as preferences
from uploader.gallery_view_order import GalleryViewOrder


class GalleryControlTests(unittest.TestCase):
    """Exercise real local preference and client metadata owners."""

    def _session(self, *, editing: bool = True) -> linking.LinkingSession:
        """Create a protocol-valid session with explicit fixture authority.

        Args:
            editing (bool): Whether this new approval includes gallery:edit.
        Returns:
            linking.LinkingSession: Session automatically closed after the test.
        """
        scope = linking.VISIBILITY_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX
        if editing:
            scope += linking.GALLERY_EDIT_SCOPE_SUFFIX
        token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b'g' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')
        session = linking.LinkingSession(
            'https://gallery.example.test', token,
            time.monotonic() + linking.LINKING_MAX_TOKEN_LIFETIME_SECONDS, scope,
        )
        self.addCleanup(session.close)
        return session

    def _snapshot(self, **changes: str | bool) -> dict[str, linking.JsonValue]:
        """Build a complete canonical response with deliberate long text.

        Args:
            **changes (str | bool): Field replacements such as title or revision.
        Returns:
            dict[str, linking.JsonValue]: Exact gallery envelope for physical ID 7.
        """
        row = {
            'id': 7, 'title': 'Current server title', 'description': 'Full text\n' * 100,
            'revision': '9', 'visibility': 'public', 'listed': True, 'nsfw': False,
        }
        row.update(changes)
        return {'gallery': row}

    def test_order_and_formats_survive_each_others_writes_and_restart(self) -> None:
        """Persist an order rule independently of formats, galleries and accounts.

        Returns:
            None: New reader instances observe both choices without cross-talk.
        """
        with tempfile.TemporaryDirectory() as directory:
            first = Path(directory) / 'first-account.json'
            second = Path(directory) / 'second-account.json'
            self.assertTrue(preferences.save_gallery_card_format(first, 7, '21:9'))
            self.assertTrue(preferences.save_gallery_photo_order(first, 7, 'newest'))
            self.assertTrue(preferences.save_gallery_card_format(first, 8, '1:1'))
            self.assertEqual('newest', preferences.load_gallery_photo_order(first, 7))
            self.assertEqual('oldest', preferences.load_gallery_photo_order(first, 8))
            self.assertEqual('oldest', preferences.load_gallery_photo_order(second, 7))
            self.assertEqual('21:9', preferences.load_gallery_card_format(first, 7))
            self.assertTrue(preferences.save_gallery_photo_order(first, 7, 'oldest'))
            self.assertEqual('21:9', preferences.load_gallery_card_format(first, 7))
            first.write_text('{broken', encoding='utf-8')
            self.assertFalse(preferences.save_gallery_photo_order(first, 7, 'newest'))
            self.assertEqual('{broken', first.read_text(encoding='utf-8'))

    def test_reverse_spans_server_pages_and_inserts_new_photos_at_the_front(self) -> None:
        """Apply the persistent rule across page boundaries and later uploads.

        Returns:
            None: The last server page is displayed first and refresh inserts ahead.
        """
        # Two extra rows exercise a real native-page boundary in both directions.
        ids = list(range(1, linking.NATIVE_MEDIA_PAGE_SIZE + 3))
        session = mock.create_autospec(linking.LinkingSession, instance=True)

        def page(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Read one ascending fixture page without any mutation endpoint.

            Args:
                gallery_id (int): Expected physical gallery identity.
                after_id (int | None): Exclusive ascending transport cursor.
                cancelled (threading.Event | None): Captured viewer cancellation.
            Returns:
                linking.LinkedMediaPage: Bounded ascending descriptors.
            """
            self.assertEqual(7, gallery_id)
            rows = [value for value in ids if value > (after_id or 0)]
            selected = rows[:linking.NATIVE_MEDIA_PAGE_SIZE]
            return linking.LinkedMediaPage(
                7, None, tuple(linking.LinkedPhoto(value, str(value)) for value in selected),
                selected[-1] if len(rows) > len(selected) else None,
            )

        session.media_page.side_effect = page
        order = GalleryViewOrder(session, 7)
        cancel = threading.Event()
        first = order.page(None, cancel)
        second = order.page(first.next_after, cancel)
        self.assertEqual(list(reversed(ids)), [
            photo.image_id for photo in (*first.photos, *second.photos)
        ])
        self.assertIsNone(second.next_after)
        ids.append(ids[-1] + 1)
        updated = order.page(None, cancel)
        self.assertEqual(ids[-1], updated.photos[0].image_id)
        session.save_gallery_edit.assert_not_called()
        cancel.set()
        with self.assertRaises(ValueError):
            order.page(None, cancel)

    def test_reverse_resumes_after_a_quota_wait_without_restarting_the_scan(self) -> None:
        """Keep valid read-only progress across an existing server HTTP 429 window.

        Returns:
            None: Retry resumes the saved cursor and publishes only the full order.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        first = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(1, 'One'),), 1,
        )
        last = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(2, 'Two'),), None,
        )
        session.media_page.side_effect = [first, linking.LinkingMediaThrottled(), last]
        order = GalleryViewOrder(session, 7)
        cancel = threading.Event()
        with self.assertRaises(linking.LinkingMediaThrottled):
            order.page(None, cancel)
        self.assertEqual((), order._photos)
        completed = order.page(None, cancel)
        self.assertEqual([2, 1], [photo.image_id for photo in completed.photos])
        self.assertEqual([None, 1, 1], [
            call.kwargs['after_id'] for call in session.media_page.call_args_list
        ])

    def test_reverse_rejects_broken_continuations_without_partial_success(self) -> None:
        """Refuse a repeated remote cursor rather than publishing a partial order.

        Returns:
            None: A malformed catalogue cannot loop or masquerade as newest-first.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        session.media_page.return_value = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(2, 'Two'),), 1,
        )
        with self.assertRaises(ValueError):
            GalleryViewOrder(session, 7).page(None, threading.Event())
        self.assertEqual(1, session.media_page.call_count)

    def test_existing_admin_visibility_grants_never_gain_metadata_writes(self) -> None:
        """Require new explicit approval before reading an edit snapshot.

        Returns:
            None: Old grants fail before touching the transport.
        """
        session = self._session(editing=False)
        with mock.patch.object(session, '_request') as request:
            self.assertFalse(session.can_edit_gallery_metadata())
            with self.assertRaises(linking.LinkingError):
                session.read_gallery_edit(7)
        request.assert_not_called()

    def test_fresh_complete_description_and_single_field_revision_save(self) -> None:
        """Use full server text and preserve all peer settings during a rename.

        Returns:
            None: A single-field request uses the reviewed revision and fresh title.
        """
        session = self._session()
        with mock.patch.object(session, '_request', side_effect=[
            self._snapshot(), self._snapshot(title='Renamed', revision='10'),
        ]) as request:
            snapshot = session.read_gallery_edit(7)
            self.assertGreater(len(snapshot.description), linking.NATIVE_MEDIA_DETAIL_TEXT_CHARS)
            updated = session.save_gallery_edit(snapshot, 'title', 'Renamed')
        self.assertEqual('Renamed', updated.title)
        self.assertEqual(snapshot.description, updated.description)
        body = json.loads(request.call_args.kwargs['body'])
        self.assertEqual({
            'action': 'gallery_edit', 'gallery_id': 7,
            'expected_revision': '9', 'field': 'title', 'value': 'Renamed',
        }, body)

    def test_conflict_and_lost_response_consume_snapshot_and_never_replay(self) -> None:
        """Keep stale and uncertain edits single-use even when the user retries.

        Returns:
            None: Each write is dispatched once and a later attempt needs a fresh read.
        """
        for outcome in (
            {'__client_visibility_conflict': 'revision_conflict'},
            linking.LinkingError('Fixture lost response'),
        ):
            with self.subTest(outcome=type(outcome).__name__):
                session = self._session()
                with mock.patch.object(session, '_request', side_effect=[
                    self._snapshot(), outcome,
                ]) as request:
                    snapshot = session.read_gallery_edit(7)
                    with self.assertRaises(linking.LinkingError):
                        session.save_gallery_edit(snapshot, 'description', 'Draft')
                    with self.assertRaises(linking.LinkingError):
                        session.save_gallery_edit(snapshot, 'description', 'Draft')
                self.assertEqual(2, request.call_count)

    def test_mismatched_peer_values_and_stale_snapshot_are_rejected(self) -> None:
        """Reject unrelated changes in an acknowledgement and superseded baselines.

        Returns:
            None: A mismatched receipt never becomes a successful UI save.
        """
        session = self._session()
        with mock.patch.object(session, '_request', side_effect=[
            self._snapshot(), self._snapshot(revision='10'),
            self._snapshot(title='Renamed', revision='11', listed=False),
        ]) as request:
            old = session.read_gallery_edit(7)
            current = session.read_gallery_edit(7)
            with self.assertRaises(linking.LinkingError):
                session.save_gallery_edit(old, 'title', 'Renamed')
            with self.assertRaises(linking.LinkingError):
                session.save_gallery_edit(current, 'title', 'Renamed')
        self.assertEqual(3, request.call_count)

    def test_listing_override_cannot_be_changed_while_not_public(self) -> None:
        """Keep private and unpublished listing automatically off in the UI.

        Returns:
            None: The persistent override cannot be changed through a stale
                nonpublic snapshot or dispatched without public visibility.
        """
        for visibility in ('private', 'unpublished'):
            session = self._session()
            with mock.patch.object(session, '_request', return_value=self._snapshot(
                visibility=visibility,
            )) as request:
                snapshot = session.read_gallery_edit(7)
                with self.assertRaises(linking.LinkingError):
                    session.save_gallery_edit(snapshot, 'listed', False)
                request.assert_called_once()

    def test_text_validation_retains_full_description_and_rejects_invalid_titles(self) -> None:
        """Validate fields before dispatch while allowing empty/multiline descriptions.

        Returns:
            None: Invalid titles and oversized descriptions cannot consume a revision.
        """
        self.assertTrue(linking.gallery_edit_text_valid('description', ''))
        self.assertTrue(linking.gallery_edit_text_valid('description', 'Line one\nLine two'))
        self.assertFalse(linking.gallery_edit_text_valid('title', ' '))
        self.assertFalse(linking.gallery_edit_text_valid('title', 'Line\nbreak'))
        self.assertFalse(linking.gallery_edit_text_valid(
            'description', 'x' * (linking.GALLERY_EDIT_DESCRIPTION_MAX_BYTES + 1),
        ))
