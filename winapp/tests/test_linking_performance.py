# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_linking_performance.py
# Module Type: Portable Python Regression Test
# Purpose: Verify bounded, identity-free route-level linking diagnostics.
# Responsibilities:
#   - Verify exact route/status/reason allowlists and unknown HTTP attempts.
#   - Verify monotonic retention, nearest-rank percentiles and concurrency.
#   - Reject unsafe route labels, response text and non-finite values.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable regression tests for passive linking HTTP route measurements."""

from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import sys
import threading
import time
import unittest
from typing import Callable
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.linking_performance import (
    LINKING_ROUTE_SAMPLE_LIMIT,
    LinkingRoutePerformanceWindow,
    linking_route_category,
)
from uploader.app_linking import (
    GALLERIES_SCOPE, LinkingResponseError, LinkingSession, MediaReadTimings,
)


class LinkingRoutePerformanceTests(unittest.TestCase):
    """Verify fixed-category, finite, client-session-owned route observations."""

    def test_route_categories_are_fixed_not_remote_urls(self) -> None:
        """Reject unknown labels and separate the tree and thumbnail protocols.

        Returns:
            None: Assert only allowlisted operation categories are returned.
        """
        self.assertEqual(linking_route_category('app_linking_galleries'), 'gallery_page')
        self.assertEqual(linking_route_category('app_linking_gallery_tree'), 'gallery_tree')
        self.assertEqual(
            linking_route_category('app_linking_thumbnail_batch'), 'thumbnail_batch',
        )
        self.assertIsNone(linking_route_category('app_linking_thumbnail_batch', 'thumbnail'))
        self.assertEqual(
            linking_route_category('app_linking_media', 'thumbnail'),
            'thumbnail_single',
        )
        self.assertEqual(
            linking_route_category('app_linking_media', 'photos'),
            'media_page',
        )
        self.assertIsNone(linking_route_category('https://example.test/a?token=1'))
        self.assertIsNone(linking_route_category('app_linking_media', 'image/42'))
        self.assertIsNone(linking_route_category('app_linking_identity', 'photos'))

    def test_status_reasons_and_percentiles_preserve_unknowns(self) -> None:
        """Keep actual status, safe reason and known request counts distinct.

        Returns:
            None: Assert nearest-rank metrics and request uncertainty.
        """
        window = LinkingRoutePerformanceWindow()
        for i, elapsed in enumerate((10., 20., 30., 40.)):
            self.assertTrue(window.observe(
                'gallery_tree', 200, 'ok', elapsed, 1,
                at_monotonic=float(i+100),
            ))
        self.assertTrue(window.observe(
            'gallery_tree', 502, 'http_status', 50., 1,
            at_monotonic=110.,
        ))
        self.assertTrue(window.observe(
            'gallery_tree', None, 'timeout', 5., None,
            at_monotonic=111.,
        ))
        result = window.snapshot(at_monotonic=112.)
        self.assertEqual(result.sampled_operations, 6)
        row, = result.routes
        self.assertEqual(row.route, 'gallery_tree')
        self.assertEqual(row.known_http_attempts, 5)
        self.assertEqual(row.unknown_attempt_operations, 1)
        self.assertEqual(dict(row.statuses), {'200': 4, '502': 1, 'none': 1})
        self.assertEqual(dict(row.reasons), {'ok': 4, 'http_status': 1, 'timeout': 1})
        self.assertEqual(row.elapsed_p50_ms, 20.)
        self.assertEqual(row.elapsed_p95_ms, 50.)
        self.assertEqual(row.elapsed_p99_ms, 50.)

    def test_reject_nonfinite_or_untrusted_input_without_retention(self) -> None:
        """Never retain dynamic messages or invalid numeric observation fields.

        Returns:
            None: Assert invalid records do not enter the window.
        """
        window = LinkingRoutePerformanceWindow()
        bad = [
            ('http://host/?private=1', 500, 'http_status', 1., 1),
            ('gallery_page', 200, 'token=secret', 1., 1),
            ('gallery_page', 700, 'ok', 1., 1),
            ('gallery_page', True, 'ok', 1., 1),
            ('gallery_page', None, 'ok', float('nan'), None),
            ('gallery_page', None, 'ok', -1., None),
            ('gallery_page', None, 'ok', 1., 3),
        ]
        for route, status, reason, elapsed, attempts in bad:
            self.assertFalse(window.observe(
                route, status, reason, elapsed, attempts,
                at_monotonic=10.,
            ))
        self.assertEqual(
            window.snapshot(at_monotonic=10.).sampled_operations, 0,
        )

    def test_fixed_capacity_and_monotonic_expiry(self) -> None:
        """Bound retained operations independent of session duration.

        Returns:
            None: Assert the hard sample cap and trailing five-minute window.
        """
        window = LinkingRoutePerformanceWindow()
        for i in range(LINKING_ROUTE_SAMPLE_LIMIT + 8):
            window.observe(
                'thumbnail_single', 200, 'ok', 1., 1,
                at_monotonic=float(i),
            )
        self.assertEqual(
            window.snapshot(at_monotonic=263.).sampled_operations, 256,
        )
        self.assertEqual(
            window.snapshot(at_monotonic=600.).sampled_operations, 0,
        )

    def test_concurrent_producers_and_safe_rendering(self) -> None:
        """Prevent unbounded producer history or arbitrary data in diagnostics.

        Returns:
            None: Assert capped thread-safe count and fixed-format output.
        """
        window = LinkingRoutePerformanceWindow()

        def produce(count: int) -> None:
            """Publish fixed source-owned observations concurrently.

            Args:
                count (int): Number of samples per worker.

            Returns:
                None: All operations are observed through the same lock.
            """
            for _ in range(count):
                self.assertTrue(window.observe(
                    'thumbnail_single', 304, 'ok', 3.5, 1,
                    at_monotonic=500.,
                ))

        with ThreadPoolExecutor(max_workers=4) as workers:
            list(workers.map(produce, (75, 75, 75, 75)))
        snapshot = window.snapshot(at_monotonic=501.)
        self.assertEqual(snapshot.sampled_operations, 256)
        self.assertEqual(snapshot.routes[0].known_http_attempts, 256)
        result = window.render_summary(at_monotonic=501.)
        self.assertIn('route=thumbnail_single', result)
        self.assertIn('statuses=304:256', result)
        self.assertNotIn('Authorization', result)
        self.assertNotIn('image_id', result)

    def test_session_json_routes_preserve_200_and_proxy_502(self) -> None:
        """Observe real session dispatch with independently stubbed HTTP only.

        Returns:
            None: Assert fixed route categories and actual refusal status.
        """
        session = LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 120., GALLERIES_SCOPE,
            gallery_tree_supported=True,
        )
        with mock.patch(
            'uploader.app_linking._request_json',
            return_value={'revision': 'a' * 64, 'unchanged': True},
        ) as sender:
            self.assertTrue(session._request('app_linking_gallery_tree'))
            sender.assert_called_once()
        failure = LinkingResponseError(
            'linking', 'app_linking_galleries', 502, 'html', 'http_status',
        )
        with mock.patch(
            'uploader.app_linking._request_json', side_effect=failure,
        ):
            with self.assertRaises(LinkingResponseError):
                session._request('app_linking_galleries')
        rows = {
            item.route: item for item in session._route_performance.snapshot().routes
        }
        self.assertEqual(dict(rows['gallery_tree'].statuses), {'200': 1})
        self.assertEqual(dict(rows['gallery_page'].statuses), {'502': 1})
        self.assertEqual(rows['gallery_tree'].known_http_attempts, 1)
        self.assertIn('http_status:1', session.route_performance_summary())

    def test_session_native_media_preserves_200_304_and_html_503(self) -> None:
        """Collect real media dispatch outcomes with no live network request.

        Returns:
            None: Assert exact status classes and no invented retry count.
        """
        session = LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 120., GALLERIES_SCOPE,
        )
        with mock.patch(
            'uploader.app_linking._run_linking_transport',
            return_value=(b'image', 'image/jpeg', None, False),
        ) as sender:
            session._media_read(12, 'thumbnail', 45, None)
            sender.assert_called_once()
        with mock.patch(
            'uploader.app_linking._run_linking_transport',
            return_value=(None, '', '"' + 'a' * 64 + '"', True),
        ):
            session._media_read(12, 'thumbnail', 45, None)
        failure = LinkingResponseError(
            'media', 'app_linking_media', 503, 'html', 'http_status',
        )
        with mock.patch(
            'uploader.app_linking._run_linking_transport', side_effect=failure,
        ):
            with self.assertRaises(LinkingResponseError):
                session._media_read(12, 'thumbnail', 45, None)
        row, = session._route_performance.snapshot().routes
        self.assertEqual(row.route, 'thumbnail_single')
        self.assertEqual(row.operations, 3)
        self.assertEqual(row.unknown_attempt_operations, 3)
        self.assertEqual(row.known_http_attempts, 0)
        self.assertEqual(dict(row.statuses), {
            '200': 1, '304': 1, '503': 1,
        })
        self.assertEqual(dict(row.reasons), {'http_status': 1, 'ok': 2})

    def test_media_timing_attempt_deltas_do_not_double_count(self) -> None:
        """Count only new sends in one real media owner when metrics are shared.

        Returns:
            None: Verify per-request deltas without changing retry admission.
        """
        session = LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 120., GALLERIES_SCOPE,
        )
        timings = MediaReadTimings(http_requests=7)

        def stub_transport(
            request_once: Callable[
                ..., tuple[bytes | None, str, str | None, bool],
            ],
            cancelled: threading.Event | None,
            deadline_seconds: float,
            inactivity_seconds: float,
            **options: str | MediaReadTimings | None,
        ) -> tuple[bytes, str, str | None, bool]:
            """Emulate two permitted physical HTTP send attempts.

            Args:
                request_once (Callable[..., tuple[bytes | None, str, str | None, bool]]):
                    Actual worker callback, never invoked in this stub.
                cancelled (threading.Event | None): Existing cancellation signal.
                deadline_seconds (float): Existing request deadline.
                inactivity_seconds (float): Existing idle socket budget.
                options (str | MediaReadTimings | None): Named transport phase,
                    route and real optional timing owner.

            Returns:
                tuple[bytes, str, str | None, bool]: Fake accepted media tuple.
            """
            observed = options.get('timings')
            if not isinstance(observed, MediaReadTimings):
                raise AssertionError('Missing media timing owner.')
            observed.http_requests += 2
            return b'image', 'image/jpeg', None, False

        with mock.patch(
            'uploader.app_linking._run_linking_transport',
            side_effect=stub_transport,
        ):
            session._media_read(12, 'thumbnail', 45, None, timings=timings)
        row, = session._route_performance.snapshot().routes
        self.assertEqual(row.known_http_attempts, 2)
        self.assertEqual(row.unknown_attempt_operations, 0)


if __name__ == '__main__':
    unittest.main()
