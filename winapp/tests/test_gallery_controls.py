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
from uploader import gallery_view_order as order_module
from uploader import gallery_view_preferences as preferences
from uploader.gallery_view_order import GalleryViewOrder


# Purpose: Bound waits for one simulated blocked media response in concurrency tests.
# Type: float. Units: seconds. Scope: one deterministic GalleryViewOrder test.
# Consumers: test_cached_order_is_available_during_slow_header_timeout.
# Rationale: A bounded event wait prevents a broken worker from hanging CI.
SLOW_MEDIA_RESPONSE_TEST_TIMEOUT_SECONDS = 2.0

# Purpose: Move the stale-cache test beyond floating-point boundary rounding.
# Type: float. Units: seconds. Scope: one PHOTO_CACHE_FRESH_SECONDS test.
# Consumers: test_session_order_cache_is_fresh_bounded_and_auth_scoped.
# Rationale: A full extra second guarantees age is strictly beyond the TTL.
CACHE_EXPIRY_TEST_MARGIN_SECONDS = 1.0

# Purpose: Bound waits while a large metadata scan is deliberately held.
# Type: float. Units: seconds. Scope: one progress observer test; actual value
# is five seconds so a stalled worker fails promptly without hanging CI.
# Consumers: test_order_progress_reports_validated_counts_during_large_scans.
# Rationale: Event waits must fail promptly if the worker stops publishing.
ORDER_PROGRESS_TEST_TIMEOUT_SECONDS = 5.0


def _header_timeout() -> linking.LinkingResponseError:
    """Build the exact sanitized media response-header timeout eligible for retry.

    Returns:
        linking.LinkingResponseError: Response-free dispatched media read timeout.
    """
    return linking.LinkingResponseError(
        'media', 'app_linking_media', None, 'other', 'timeout',
        transport_step='headers',
    )


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

    def test_order_progress_reports_validated_counts_during_large_scans(self) -> None:
        """Publish incremental validated counts while large scans are held.

        Returns:
            None: Counts arrive page by page and the complete snapshot still
                publishes in newest-first order after the worker is released.
        """
        # These fixture boundaries exercise medium, multi-page, and large albums.
        for total in (500, 2000, 5000):
            with self.subTest(total=total):
                session = mock.create_autospec(
                    linking.LinkingSession, instance=True,
                )
                entered = threading.Event()
                release = threading.Event()
                counts: list[int] = []

                def read_page(
                    gallery_id: int, after_id: int | None = None,
                    cancelled: threading.Event | None = None,
                ) -> linking.LinkedMediaPage:
                    """Return one bounded ascending slice of the fixture album.

                    Args:
                        gallery_id (int): Physical gallery requested by the order.
                        after_id (int | None): Exclusive ascending page cursor.
                        cancelled (threading.Event | None): Current cancellation.
                    Returns:
                        linking.LinkedMediaPage: One ordered metadata page.
                    """
                    self.assertEqual(7, gallery_id)
                    start = (after_id or 0) + 1
                    stop = min(total, start + linking.NATIVE_MEDIA_PAGE_SIZE - 1)
                    ids = range(start, stop + 1)
                    next_after = stop if stop < total else None
                    return linking.LinkedMediaPage(
                        7, None,
                        tuple(linking.LinkedPhoto(value, str(value)) for value in ids),
                        next_after,
                    )

                def report(count: int) -> None:
                    """Record progress and hold the worker after its first page.

                    Args:
                        count (int): Validated cumulative descriptor count.
                    Returns:
                        None: The test controls release from its owner thread.
                    """
                    counts.append(count)
                    if len(counts) == 1:
                        entered.set()
                        release.wait(ORDER_PROGRESS_TEST_TIMEOUT_SECONDS)

                session.media_page.side_effect = read_page
                order = GalleryViewOrder(session, 7)
                outcomes: list[linking.LinkedMediaPage] = []
                failures: list[BaseException] = []

                def refresh() -> None:
                    """Run the scan away from the test assertion thread.

                    Returns:
                        None: Captures the snapshot or worker exception for checks.
                    """
                    try:
                        outcomes.append(order.page(
                            None, threading.Event(), progress=report,
                        ))
                    except BaseException as error:
                        failures.append(error)

                worker = threading.Thread(target=refresh, daemon=True)
                worker.start()
                try:
                    self.assertTrue(entered.wait(ORDER_PROGRESS_TEST_TIMEOUT_SECONDS))
                    self.assertEqual(
                        [min(total, linking.NATIVE_MEDIA_PAGE_SIZE)], counts,
                    )
                finally:
                    release.set()
                    worker.join(ORDER_PROGRESS_TEST_TIMEOUT_SECONDS)
                self.assertFalse(worker.is_alive())
                self.assertEqual([], failures)
                self.assertEqual(1, len(outcomes))
                self.assertEqual(total, counts[-1])
                self.assertEqual(
                    sorted(counts), counts,
                    'Validated cumulative progress must never move backward.',
                )
                first_page_size = min(total, linking.NATIVE_MEDIA_PAGE_SIZE)
                self.assertEqual(first_page_size, len(outcomes[0].photos))
                self.assertEqual(total, len(order._photos))
                self.assertEqual(
                    list(range(total, 0, -1)),
                    [photo.image_id for photo in order._photos],
                )
                self.assertEqual(
                    list(range(total, total - first_page_size, -1)),
                    [photo.image_id for photo in outcomes[0].photos],
                )

    def test_order_progress_skips_unvalidated_pages_and_survives_resume(self) -> None:
        """Report only valid pages and keep counts when a read safely resumes.

        Returns:
            None: Invalid cursors publish nothing and quota resumption preserves
                the earlier validated count without replaying prior rows.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        first = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(1, 'One'),), 1,
        )
        malformed = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(3, 'Three'),), 2,
        )
        session.media_page.side_effect = [first, linking.LinkingMediaThrottled(), malformed]
        order = GalleryViewOrder(session, 7)
        counts: list[int] = []
        with self.assertRaises(linking.LinkingMediaThrottled):
            order.page(None, threading.Event(), progress=counts.append)
        self.assertEqual([1], counts)
        self.assertEqual([1], [photo.image_id for photo in order._pending])
        with self.assertRaises(ValueError):
            order.page(None, threading.Event(), progress=counts.append)
        self.assertEqual([1], counts)
        self.assertEqual([], order._pending)

    def test_retrying_callback_runs_only_before_automatic_retry(self) -> None:
        """Notify the observer only when the owner performs its safe retry.

        Returns:
            None: Manual resumption and non-retryable errors emit no retry event.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        session.media_page.side_effect = [
            _header_timeout(),
            linking.LinkedMediaPage(7, None, (), None),
        ]
        order = GalleryViewOrder(session, 7)
        retries: list[None] = []
        order.page(None, threading.Event(), retrying=lambda: retries.append(None))
        self.assertEqual([None], retries)

        denied = mock.create_autospec(linking.LinkingSession, instance=True)
        denied.can_read_media.return_value = False
        denied.media_page.side_effect = _header_timeout()
        with self.assertRaises(linking.LinkingResponseError):
            GalleryViewOrder(denied, 7).page(
                None, threading.Event(), retrying=lambda: retries.append(None),
            )
        self.assertEqual([None], retries)

    def test_observer_exceptions_do_not_change_read_outcomes(self) -> None:
        """Ignore observer failures while preserving a valid retry and snapshot.

        Returns:
            None: A raising progress observer cannot fail a read and a raising
                retry observer cannot suppress the existing safe retry.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        session.media_page.side_effect = [
            _header_timeout(),
            linking.LinkedMediaPage(
                7, None, (linking.LinkedPhoto(4, 'Four'),), None,
            ),
        ]

        def fail_progress(count: int) -> None:
            """Raise to verify progress observers cannot alter read results.

            Args:
                count (int): Validated count supplied by the order owner.
            Returns:
                None: No value is returned; this fixture always raises.
            Raises:
                RuntimeError: Deliberate observer failure.
            """
            raise RuntimeError(f'Observer failed at {count}.')

        def fail_retrying() -> None:
            """Raise to verify retry observers cannot suppress a safe retry.

            Returns:
                None: No value is returned; this fixture always raises.
            Raises:
                RuntimeError: Deliberate observer failure.
            """
            raise RuntimeError('Retry observer failed.')

        completed = GalleryViewOrder(session, 7).page(
            None, threading.Event(), progress=fail_progress,
            retrying=fail_retrying,
        )
        self.assertEqual([4], [photo.image_id for photo in completed.photos])
        self.assertEqual(2, session.media_page.call_count)

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

    def test_unfinished_transport_blocks_retry_until_manual_resume(self) -> None:
        """Keep the current cursor while the transport still owns completion.

        Returns:
            None: Manual Retry resumes after the original response worker finishes.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        first = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(1, 'Oldest'),), 1,
        )
        last = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(2, 'Newest'),), None,
        )
        operation_finished = threading.Event()
        unfinished_timeout = linking.LinkingResponseError(
            'media', 'app_linking_media', None, 'other', 'timeout',
            transport_step='headers',
            operation_finished=operation_finished,
        )
        session.media_page.side_effect = [
            first, unfinished_timeout, last,
        ]
        order = GalleryViewOrder(session, 7)
        cancel = threading.Event()
        with self.assertRaises(linking.LinkingResponseError):
            order.page(None, cancel)
        self.assertEqual([None, 1], [
            call.kwargs['after_id'] for call in session.media_page.call_args_list
        ])
        self.assertEqual([1], [photo.image_id for photo in order._pending])
        self.assertEqual(1, order._pending_cursor)
        self.assertTrue(order._resuming)
        operation_finished.set()
        completed = order.page(None, cancel)
        self.assertEqual([2, 1], [
            photo.image_id for photo in completed.photos
        ])
        self.assertEqual([None, 1, 1], [
            call.kwargs['after_id'] for call in session.media_page.call_args_list
        ])

    def test_header_timeout_after_session_authority_loss_is_not_retried(self) -> None:
        """Discard partial rows rather than retry after current authority is lost.

        Returns:
            None: The media read stops without a transport retry.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        session.can_read_media.return_value = False
        first = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(1, 'One'),), 1,
        )
        session.media_page.side_effect = [first, _header_timeout()]
        order = GalleryViewOrder(session, 7)
        with self.assertRaises(linking.LinkingResponseError):
            order.page(None, threading.Event())
        self.assertEqual([None, 1], [
            call.kwargs['after_id'] for call in session.media_page.call_args_list
        ])
        self.assertEqual([], order._pending)
        self.assertIsNone(order._pending_cursor)
        self.assertFalse(order._resuming)
        self.assertIsNone(order._refresh_started_at)

    def test_reverse_retries_one_header_timeout_and_keeps_page_cursor(self) -> None:
        """Retry one response-header timeout at the exact validated cursor.

        Returns:
            None: One failed page is retried and the complete reverse order is published.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        first = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(1, 'Oldest'),), 1,
        )
        last = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(2, 'Newest'),), None,
        )
        session.media_page.side_effect = [first, _header_timeout(), last]
        order = GalleryViewOrder(session, 7)
        with mock.patch.object(
            order_module.time, 'monotonic', side_effect=[123.0],
        ):
            completed = order.page(None, threading.Event())
        self.assertEqual([2, 1], [
            photo.image_id for photo in completed.photos
        ])
        self.assertEqual([None, 1, 1], [
            call.kwargs['after_id'] for call in session.media_page.call_args_list
        ])
        session.cache_recent_media_order.assert_called_once_with(
            7, completed.photos, 123.0,
        )

    def test_second_header_timeout_preserves_cursor_for_manual_retry(self) -> None:
        """Bound automatic retries to one per refresh and retain original age.

        Returns:
            None: The next user Retry resumes the exact pending media cursor.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        first = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(1, 'Oldest'),), 1,
        )
        last = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(2, 'Newest'),), None,
        )
        session.media_page.side_effect = [
            first, _header_timeout(), _header_timeout(), last,
        ]
        order = GalleryViewOrder(session, 7)
        cancel = threading.Event()
        with mock.patch.object(
            order_module.time, 'monotonic', return_value=234.0,
        ):
            with self.assertRaises(linking.LinkingResponseError):
                order.page(None, cancel)
            self.assertEqual([1], [photo.image_id for photo in order._pending])
            self.assertEqual(1, order._pending_cursor)
            self.assertTrue(order._resuming)
            self.assertEqual(234.0, order._refresh_started_at)
            completed = order.page(None, cancel)
        self.assertEqual([2, 1], [
            photo.image_id for photo in completed.photos
        ])
        self.assertEqual([None, 1, 1, 1], [
            call.kwargs['after_id'] for call in session.media_page.call_args_list
        ])
        session.cache_recent_media_order.assert_called_once_with(
            7, completed.photos, 234.0,
        )

    def test_auth_and_malformed_pages_clear_preserved_prefix(self) -> None:
        """Clear an interrupted prefix for authority or catalogue validation errors.

        Returns:
            None: HTTP refusal and malformed paging remain non-retryable.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        first = linking.LinkedMediaPage(
            7, None, (linking.LinkedPhoto(1, 'One'),), 1,
        )
        refused = linking.LinkingResponseError(
            'media', 'app_linking_media', 401, 'json', 'http_status',
        )
        session.media_page.side_effect = [first, refused]
        order = GalleryViewOrder(session, 7)
        with self.assertRaises(linking.LinkingResponseError):
            order.page(None, threading.Event())
        self.assertEqual([], order._pending)
        self.assertIsNone(order._pending_cursor)
        self.assertFalse(order._resuming)
        self.assertIsNone(order._refresh_started_at)
        self.assertEqual([None, 1], [
            call.kwargs['after_id'] for call in session.media_page.call_args_list
        ])

        malformed_session = mock.create_autospec(
            linking.LinkingSession, instance=True,
        )
        malformed_session.media_page.side_effect = [
            first,
            linking.LinkedMediaPage(
                7, None, (linking.LinkedPhoto(3, 'Three'),), 2,
            ),
        ]
        malformed_order = GalleryViewOrder(malformed_session, 7)
        with self.assertRaises(ValueError):
            malformed_order.page(None, threading.Event())
        self.assertEqual([], malformed_order._pending)
        self.assertIsNone(malformed_order._pending_cursor)
        self.assertFalse(malformed_order._resuming)
        self.assertIsNone(malformed_order._refresh_started_at)

    def test_header_timeout_after_cancellation_is_not_retried(self) -> None:
        """Stop after cancellation even if the pending request reports a timeout.

        Returns:
            None: A cancelled viewer generation gets no automatic transport retry.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        cancel = threading.Event()

        def cancel_then_timeout(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Set cancellation and emulate a raced response-header timeout.

            Args:
                gallery_id (int): Expected physical gallery identity.
                after_id (int | None): First page has no continuation.
                cancelled (threading.Event | None): Captured viewer cancellation.
            Returns:
                linking.LinkedMediaPage: Unused because the transport times out.
            Raises:
                linking.LinkingResponseError: Raced response-header timeout.
            """
            self.assertEqual(7, gallery_id)
            self.assertIsNone(after_id)
            self.assertIs(cancelled, cancel)
            cancel.set()
            raise _header_timeout()

        session.media_page.side_effect = cancel_then_timeout
        order = GalleryViewOrder(session, 7)
        with self.assertRaises(linking.LinkingResponseError):
            order.page(None, cancel)
        self.assertEqual(1, session.media_page.call_count)
        self.assertEqual([], order._pending)
        self.assertIsNone(order._pending_cursor)
        self.assertFalse(order._resuming)
        self.assertIsNone(order._refresh_started_at)

    def test_cancelled_order_read_clears_a_validated_prefix(self) -> None:
        """Discard partial display state when the viewer cancels a page read.

        Returns:
            None: A later viewer generation cannot reuse the cancelled prefix.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        cancel = threading.Event()

        def page_then_cancel(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Return one valid page then cancel before the next continuation.

            Args:
                gallery_id (int): Expected physical gallery identity.
                after_id (int | None): Exclusive cursor supplied by the order.
                cancelled (threading.Event | None): Viewer operation cancellation.
            Returns:
                linking.LinkedMediaPage: One valid page whose continuation is not used.
            """
            self.assertEqual(7, gallery_id)
            self.assertIsNone(after_id)
            self.assertIs(cancelled, cancel)
            cancel.set()
            return linking.LinkedMediaPage(
                7, None, (linking.LinkedPhoto(1, 'One'),), 1,
            )

        session.media_page.side_effect = page_then_cancel
        order = GalleryViewOrder(session, 7)
        progress: list[int] = []
        with self.assertRaises(ValueError):
            order.page(None, cancel, progress=progress.append)
        self.assertEqual([], progress)
        self.assertEqual([None], [
            call.kwargs['after_id'] for call in session.media_page.call_args_list
        ])
        self.assertEqual([], order._pending)
        self.assertIsNone(order._pending_cursor)
        self.assertFalse(order._resuming)
        self.assertIsNone(order._refresh_started_at)

    def test_cached_order_is_available_during_slow_header_timeout(self) -> None:
        """Keep a fresh display snapshot available while headers stall then time out.

        Returns:
            None: A background refresh failure does not block the cached first page.
        """
        session = mock.create_autospec(linking.LinkingSession, instance=True)
        cached_rows = (
            linking.LinkedPhoto(3, 'Newest'),
            linking.LinkedPhoto(2, 'Middle'),
            linking.LinkedPhoto(1, 'Oldest'),
        )
        session.recent_media_order.return_value = cached_rows
        entered = threading.Event()
        release = threading.Event()
        worker_errors: list[BaseException] = []

        def slow_header_page(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Block the simulated first media response at its headers.

            Args:
                gallery_id (int): Expected gallery identity.
                after_id (int | None): First page has no continuation.
                cancelled (threading.Event | None): Viewer cancellation token.
            Returns:
                linking.LinkedMediaPage: Unused because this response times out.
            Raises:
                linking.LinkingResponseError: Simulated response-header timeout.
            """
            self.assertEqual(7, gallery_id)
            self.assertIsNone(after_id)
            entered.set()
            release.wait()
            raise linking.LinkingResponseError(
                'media', 'app_linking_media', None, 'other', 'timeout',
                transport_step='headers',
            )

        def refresh() -> None:
            """Attempt an ordinary authoritative refresh in a worker thread.

            Returns:
                None: Captures the expected timeout for assertions on the owner thread.
            """
            try:
                order.page(None, threading.Event())
            except linking.LinkingResponseError as error:
                worker_errors.append(error)

        session.media_page.side_effect = slow_header_page
        order = GalleryViewOrder(session, 7)
        worker = threading.Thread(target=refresh, daemon=True)
        worker.start()
        try:
            self.assertTrue(entered.wait(SLOW_MEDIA_RESPONSE_TEST_TIMEOUT_SECONDS))
            cached_page = order.cached_page()
            self.assertIsNotNone(cached_page)
            self.assertEqual([3, 2, 1], [
                photo.image_id for photo in cached_page.photos
            ])
        finally:
            release.set()
            worker.join(SLOW_MEDIA_RESPONSE_TEST_TIMEOUT_SECONDS)
        self.assertFalse(worker.is_alive())
        self.assertEqual(1, len(worker_errors))
        self.assertEqual('headers', worker_errors[0].transport_step)
        self.assertEqual(2, session.media_page.call_count)

    def test_session_order_cache_is_fresh_bounded_and_auth_scoped(self) -> None:
        """Retain only fresh complete rows in the existing live session.

        Returns:
            None: Expired metadata and refused server authority cannot be reused.
        """
        session = self._session(editing=False)
        verified_at = time.monotonic()
        rows = (
            linking.LinkedPhoto(
                3, 'Newest', thumbnail_etag='"' + 'a' * 64 + '"',
                thumbnail_verified_at=verified_at,
            ),
            linking.LinkedPhoto(2, 'Older'),
        )
        self.assertTrue(session.cache_recent_media_order(
            7, rows, verified_at,
        ))
        self.assertEqual(rows, session.recent_media_order(7))
        self.assertEqual(
            {}, session._public_media_descriptors,
            'Display metadata must not create thumbnail proof rows.',
        )
        self.assertEqual({}, session._private_preload_proofs)

        self.assertFalse(session.cache_recent_media_order(
            8, rows, verified_at - linking.PHOTO_CACHE_FRESH_SECONDS,
        ))
        order = GalleryViewOrder(session, 7)
        self.assertIsNotNone(order.cached_page())
        with mock.patch.object(
            linking.time, 'monotonic',
            return_value=(
                verified_at + linking.PHOTO_CACHE_FRESH_SECONDS
                + CACHE_EXPIRY_TEST_MARGIN_SECONDS
            ),
        ):
            self.assertIsNone(session.recent_media_order(7))
            self.assertIsNone(order.cached_page())

        self.assertTrue(session.cache_recent_media_order(
            7, rows, time.monotonic(),
        ))
        order = GalleryViewOrder(session, 7)
        self.assertIsNotNone(order.cached_page())
        session.close()
        self.assertIsNone(order.cached_page())

        auth_session = self._session(editing=False)
        auth_now = time.monotonic()
        self.assertTrue(auth_session.cache_recent_media_order(
            7, rows, auth_now,
        ))
        auth_order = GalleryViewOrder(auth_session, 7)
        self.assertIsNotNone(auth_order.cached_page())
        with mock.patch.object(
            auth_session, '_media_read',
            side_effect=linking.LinkingMediaUnavailable(),
        ):
            with self.assertRaises(linking.LinkingMediaUnavailable):
                auth_session.media_page(7)
        self.assertIsNone(auth_order.cached_page())

        renewal_session = self._session(editing=False)
        renewal_now = time.monotonic()
        self.assertTrue(renewal_session.cache_recent_media_order(
            7, rows, renewal_now,
        ))
        renewed_token = linking.TOKEN_PREFIX + base64.urlsafe_b64encode(
            b'h' * linking.ATTEMPT_RANDOM_BYTES,
        ).rstrip(b'=').decode('ascii')

        def renew_session(
            cancelled: threading.Event,
        ) -> tuple[str, float]:
            """Return a fresh test bearer through the existing session owner.

            Args:
                cancelled (threading.Event): Session-level cancellation state.
            Returns:
                tuple[str, float]: New bearer and monotonic deadline.
            """
            self.assertFalse(cancelled.is_set())
            return (
                renewed_token,
                time.monotonic() + linking.LINKING_MAX_TOKEN_LIFETIME_SECONDS,
            )

        renewal_session._renew = renew_session
        renewal_session._expires_at = renewal_now - 1.0
        renewal_session._renew_if_expired(None)
        self.assertIsNone(renewal_session.recent_media_order(7))

    def test_session_order_cache_evicts_oldest_and_clears_on_close(self) -> None:
        """Enforce the per-session gallery and row bounds and close cleanup.

        Returns:
            None: The cache retains at most its named gallery limit until close.
        """
        session = self._session(editing=False)
        current = time.monotonic()
        for gallery_id in range(1, linking.MEDIA_RECENT_ORDER_GALLERY_LIMIT + 1):
            self.assertTrue(session.cache_recent_media_order(
                gallery_id, (), current,
            ))
        self.assertIsNotNone(session.recent_media_order(1))
        self.assertTrue(session.cache_recent_media_order(
            linking.MEDIA_RECENT_ORDER_GALLERY_LIMIT + 1, (), current,
        ))
        # The access above moved gallery 1 to MRU; inserting gallery 5 evicts 2.
        self.assertIsNotNone(session.recent_media_order(1))
        self.assertIsNone(session.recent_media_order(2))
        self.assertIsNotNone(session.recent_media_order(
            linking.MEDIA_RECENT_ORDER_GALLERY_LIMIT + 1,
        ))
        large_rows = tuple(
            linking.LinkedPhoto(image_id, str(image_id))
            for image_id in range(
                linking.MEDIA_RECENT_ORDER_PHOTO_LIMIT, 0, -1,
            )
        )
        self.assertTrue(session.cache_recent_media_order(
            100, large_rows, current,
        ))
        self.assertEqual(
            linking.MEDIA_RECENT_ORDER_PHOTO_LIMIT,
            len(session.recent_media_order(100)),
        )
        self.assertTrue(session.cache_recent_media_order(
            101, (linking.LinkedPhoto(101, 'Newer'),), current,
        ))
        self.assertIsNone(session.recent_media_order(100))
        self.assertEqual(
            101, session.recent_media_order(101)[0].image_id,
        )
        session.close()
        self.assertIsNone(session.recent_media_order(101))

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

    def test_lost_acknowledgement_is_resolved_by_authorized_read_without_replay(self) -> None:
        """Recognize a committed rename after its reply was lost.

        Returns:
            None: One POST and the automatic read complete the original intent.
        """
        session = self._session()
        with mock.patch.object(session, '_request', side_effect=[
            self._snapshot(), linking.LinkingError('Lost acknowledgement'),
            self._snapshot(title='Renamed', revision='10'),
        ]) as request:
            result = session.apply_gallery_edit(7, 'title', 'Renamed')
        self.assertEqual('Renamed', result.title)
        actions = [json.loads(call.kwargs['body'])['action']
                   for call in request.call_args_list]
        self.assertEqual(['gallery_edit_read', 'gallery_edit', 'gallery_edit_read'], actions)

    def test_definite_conflict_rebases_only_the_requested_field_automatically(self) -> None:
        """Use a refreshed revision after a definite pre-mutation refusal.

        Returns:
            None: A changed peer description survives the bounded rename retry.
        """
        session = self._session()
        with mock.patch.object(session, '_request', side_effect=[
            self._snapshot(), {'__client_visibility_conflict': 'revision_conflict'},
            self._snapshot(description='New web description', revision='10'),
            self._snapshot(title='Renamed', description='New web description', revision='11'),
        ]) as request:
            result = session.apply_gallery_edit(7, 'title', 'Renamed')
        writes = [json.loads(call.kwargs['body']) for call in request.call_args_list
                  if json.loads(call.kwargs['body'])['action'] == 'gallery_edit']
        self.assertEqual(['9', '10'], [row['expected_revision'] for row in writes])
        self.assertEqual('New web description', result.description)
        self.assertEqual('Renamed', result.title)
        self.assertTrue(all(row['field'] == 'title' for row in writes))

    def test_unknown_write_cannot_overwrite_a_later_revision(self) -> None:
        """Keep another writer's state after an ambiguous response.

        Returns:
            None: Only one POST occurs and fresh state accompanies the retained draft.
        """
        session = self._session()
        with mock.patch.object(session, '_request', side_effect=[
            self._snapshot(), linking.LinkingError('Unknown response'),
            self._snapshot(title='Later web title', revision='11'),
        ]) as request:
            with self.assertRaises(linking.LinkingGalleryEditFailure) as failure:
                session.apply_gallery_edit(7, 'title', 'Renamed')
        self.assertEqual(3, request.call_count)
        self.assertEqual('Later web title', failure.exception.snapshot.title)
        self.assertTrue(failure.exception.conflict)

    def test_delayed_first_commit_is_fenced_by_the_unchanged_revision(self) -> None:
        """Allow an identical-baseline attempt without applying the rename twice.

        Returns:
            None: The late original commit makes the second POST conflict, then
                automatic readback confirms the desired state without another write.
        """
        session = self._session()
        with mock.patch.object(session, '_request', side_effect=[
            self._snapshot(), linking.LinkingError('Original still running'),
            self._snapshot(), {'__client_visibility_conflict': 'revision_conflict'},
            self._snapshot(title='Renamed', revision='10'),
        ]) as request:
            result = session.apply_gallery_edit(7, 'title', 'Renamed')
        writes = [json.loads(call.kwargs['body']) for call in request.call_args_list
                  if json.loads(call.kwargs['body'])['action'] == 'gallery_edit']
        self.assertEqual(['9', '9'], [row['expected_revision'] for row in writes])
        self.assertEqual('10', result.revision)

    def test_refused_authority_and_cancelled_reconciliation_never_retry_writes(self) -> None:
        """Stop bounded recovery on grant refusal or context cancellation.

        Returns:
            None: Refusal preserves the last observed state; cancellation makes
                no request and malformed input cannot start a passive read.
        """
        session = self._session()
        with mock.patch.object(session, '_request', side_effect=[
            self._snapshot(), linking.LinkingGrantRejected(), linking.LinkingGrantRejected(),
        ]) as request:
            with self.assertRaises(linking.LinkingGalleryEditFailure):
                session.apply_gallery_edit(7, 'title', 'Renamed')
        self.assertEqual(3, request.call_count)
        cancelled = threading.Event()
        cancelled.set()
        with mock.patch.object(session, '_request') as request:
            with self.assertRaises(linking.LinkingError):
                session.apply_gallery_edit(7, 'title', 'Renamed', cancelled)
        request.assert_not_called()
