# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_photos_catalogue_performance.py
# Module Type: Portable Python Regression Test
# Purpose: Verify bounded Photos tree/cache/page timings and safe source labels.
# Responsibilities:
#   - Distinguish display-only cache from authoritative tree and paged paths.
#   - Prove deterministic percentile, expiry, pass reset and hard memory caps.
#   - Reject arbitrary fallback messages and nonfinite or oversized metrics.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable numeric-only regression tests for catalogue A2.3 diagnostics."""

from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import sys
import unittest


WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.photos_catalogue_performance import (
    CATALOGUE_PERFORMANCE_SAMPLE_LIMIT,
    CataloguePerformanceWindow,
)


class CataloguePerformanceWindowTests(unittest.TestCase):
    """Verify session-bounded catalogue provenance and real numeric phases."""

    def test_empty_window_never_claims_current_authorization(self) -> None:
        """Keep unknown fresh provenance explicit before any result.

        Returns:
            None: Assertions verify a correctly empty state.
        """
        snapshot = CataloguePerformanceWindow().snapshot(at_monotonic=5.)
        self.assertEqual(snapshot.events, 0)
        self.assertEqual(snapshot.cache_source, 'unknown')
        self.assertEqual(snapshot.transport_source, 'pending')
        self.assertEqual(snapshot.fallback_reason, 'none')
        self.assertEqual(snapshot.confirmed_rows, 0)

    def test_cache_display_never_claims_fresh_tree(self) -> None:
        """Distinguish verified local display from fresh server confirmation.

        Returns:
            None: Assertions preserve cached-display-only authority.
        """
        perf = CataloguePerformanceWindow()
        self.assertTrue(perf.observe('cache_disk', 5., rows=25, at_monotonic=20.))
        self.assertTrue(perf.observe('mount_cached', 1.5, rows=25, at_monotonic=21.))
        snapshot = perf.snapshot(at_monotonic=22.)
        self.assertEqual(snapshot.cache_source, 'disk')
        self.assertEqual(snapshot.transport_source, 'pending')
        self.assertEqual(snapshot.confirmed_rows, 0)
        self.assertEqual(snapshot.phases[0].stage, 'cache_disk')

    def test_tree_full_and_unchanged_have_separate_outcomes(self) -> None:
        """Never infer unchanged authorization from merely cached rows.

        Returns:
            None: Assertions distinguish accepted fresh tree modes.
        """
        perf = CataloguePerformanceWindow()
        perf.observe('cache_retained', rows=50, at_monotonic=10.)
        perf.observe('tree_unchanged', 8.25, rows=50, at_monotonic=11.)
        snapshot = perf.snapshot(at_monotonic=12.)
        self.assertEqual(snapshot.transport_source, 'tree-v1-unchanged')
        self.assertEqual(snapshot.confirmed_rows, 50)
        perf.begin_pass()
        self.assertEqual(
            perf.snapshot(at_monotonic=12.).transport_source, 'pending',
        )
        perf.observe('tree_full', 14., rows=120, at_monotonic=13.)
        snapshot = perf.snapshot(at_monotonic=14.)
        self.assertEqual(snapshot.transport_source, 'tree-v1-full')
        self.assertEqual(snapshot.confirmed_rows, 120)
        self.assertEqual(snapshot.events, 3)

    def test_paged_fallback_and_page_percentiles(self) -> None:
        """Retain fixed fallback explanation and actual page timings only.

        Returns:
            None: Assertions check pages, durations and missing measurements.
        """
        perf = CataloguePerformanceWindow()
        perf.observe(
            'paged_fallback', reason='capability_missing', at_monotonic=40.,
        )
        for index, duration in enumerate((10., 20., 30., 40.)):
            perf.observe(
                'page_ok', duration, rows=50, at_monotonic=41. + index,
            )
        perf.observe('page_error', None, at_monotonic=50.)
        result = perf.snapshot(at_monotonic=52.)
        self.assertEqual(result.transport_source, 'paged-fallback')
        self.assertEqual(result.fallback_reason, 'capability_missing')
        self.assertEqual(result.completed_pages, 4)
        self.assertEqual(result.confirmed_rows, 200)
        phase, = [entry for entry in result.phases if entry.stage == 'page_ok']
        self.assertEqual(phase.p50_ms, 20.)
        self.assertEqual(phase.p95_ms, 40.)
        self.assertEqual(phase.p99_ms, 40.)
        error, = [entry for entry in result.phases if entry.stage == 'page_error']
        self.assertIsNone(error.p95_ms)
        self.assertEqual(error.measured, 0)
        summary = perf.render_summary(at_monotonic=52.)
        self.assertIn('fallback_reason=capability_missing', summary)
        self.assertIn('p95_ms=unknown', summary)

    def test_unknown_optional_failure_is_not_named_as_server_404(self) -> None:
        """Avoid inventing the cause when an optional tree returns None.

        Returns:
            None: Assertions verify coarse fallback classification.
        """
        perf = CataloguePerformanceWindow()
        perf.observe(
            'paged_fallback', reason='optional_unavailable', at_monotonic=10.,
        )
        self.assertEqual(
            perf.snapshot(at_monotonic=11.).fallback_reason,
            'optional_unavailable',
        )
        perf.observe('page_throttled', None, at_monotonic=12.)
        self.assertEqual(perf.snapshot(at_monotonic=13.).completed_pages, 0)

    def test_reject_unsafe_labels_counts_and_nonfinite_durations(self) -> None:
        """Reject any data that could smuggle private text into summaries.

        Returns:
            None: Assertions keep the recorder empty after invalid inputs.
        """
        perf = CataloguePerformanceWindow()
        checks = [
            ('https://host/private', None, 0, 'none'),
            ('cache_disk', float('nan'), 0, 'none'),
            ('mount_fresh', float('inf'), 0, 'none'),
            ('tree_full', -1., 0, 'none'),
            ('page_ok', 2., -1, 'none'),
            ('page_ok', 2., 10_001, 'none'),
            ('paged_fallback', None, 0, 'token=abc'),
            ('tree_full', None, 0, 'optional_unavailable'),
        ]
        for stage, duration, rows, reason in checks:
            self.assertFalse(perf.observe(
                stage, duration, rows=rows, reason=reason, at_monotonic=25.,
            ))
        self.assertEqual(perf.snapshot(at_monotonic=26.).events, 0)

    def test_fixed_memory_cap_expiry_and_concurrent_producers(self) -> None:
        """Keep state bounded across rapid refreshes and independent workers.

        Returns:
            None: Assertions verify finite concurrency and monotonic expiry.
        """
        perf = CataloguePerformanceWindow()

        def send(count: int) -> None:
            """Publish known numeric phase samples from one test worker.

            Args:
                count (int): Number of fixed observations to publish.

            Returns:
                None: No sample exceeds validation or concurrency bounds.
            """
            for _ in range(count):
                self.assertTrue(perf.observe(
                    'mount_cached', 0.5, rows=16, at_monotonic=100.,
                ))

        with ThreadPoolExecutor(max_workers=4) as workers:
            list(workers.map(send, (90, 90, 90, 90)))
        self.assertEqual(
            perf.snapshot(at_monotonic=101.).events,
            CATALOGUE_PERFORMANCE_SAMPLE_LIMIT,
        )
        self.assertEqual(perf.snapshot(at_monotonic=401.).events, 0)

    def test_milestones_require_started_pass_and_are_first_once(self) -> None:
        """Record finite local duration without claiming compositor scan-out.

        Returns:
            None: First accepted milestone wins and missing stages stay unknown.
        """
        window = CataloguePerformanceWindow()
        self.assertFalse(window.mark_milestone(
            'fresh_rows_confirmed', at_monotonic=100.0,
        ))
        window.begin_pass(at_monotonic=100.0)
        self.assertTrue(window.mark_milestone(
            'cached_rows_mounted', at_monotonic=100.05,
        ))
        self.assertTrue(window.mark_milestone(
            'fresh_rows_confirmed', at_monotonic=100.1,
        ))
        self.assertFalse(window.mark_milestone(
            'fresh_rows_confirmed', at_monotonic=102.0,
        ))
        self.assertTrue(window.mark_milestone(
            'first_thumbnail_visible', at_monotonic=100.25,
        ))
        self.assertTrue(window.mark_milestone(
            'first_viewport_settled', at_monotonic=100.28,
        ))
        self.assertFalse(window.mark_milestone(
            'first_viewport_settled', at_monotonic=100.29,
        ))
        snapshot = window.snapshot(at_monotonic=100.3)
        self.assertEqual(snapshot.fresh_to_thumbnail_ms, 150.0)
        self.assertEqual(snapshot.fresh_to_viewport_settled_ms, 180.0)
        self.assertIn(
            'fresh_to_first_viewport_settled_ms=180.0',
            window.render_summary(at_monotonic=100.3),
        )
        self.assertEqual(
            dict(snapshot.milestones)['cached_rows_mounted'], 50.0,
        )
        self.assertIn(
            'gallery_view_mounted_ms=unknown',
            window.render_summary(at_monotonic=100.3),
        )

    def test_milestone_cannot_claim_negative_or_old_session_time(self) -> None:
        """Disallow stale timestamps, identifiers and overlong local intervals.

        Returns:
            None: Invalid stages never change the current pass milestones.
        """
        window = CataloguePerformanceWindow()
        window.begin_pass(at_monotonic=20.0)
        self.assertFalse(window.mark_milestone(
            'gallery/123', at_monotonic=20.1,
        ))
        self.assertFalse(window.mark_milestone(
            'first_thumbnail_visible', at_monotonic=19.9,
        ))
        self.assertFalse(window.mark_milestone(
            'first_thumbnail_visible', at_monotonic=650.0,
        ))
        self.assertFalse(window.mark_milestone(
            'first_thumbnail_visible', at_monotonic=float('nan'),
        ))
        self.assertEqual(window.snapshot(at_monotonic=21.).milestones, ())

    def test_refresh_resets_pass_without_erasing_bounded_history(self) -> None:
        """Fence stale initial milestones across manual session refreshes.

        Returns:
            None: Only current-pass first outcomes and deltas are displayed.
        """
        window = CataloguePerformanceWindow()
        window.begin_pass(at_monotonic=10.)
        window.observe('tree_full', 1., rows=1, at_monotonic=10.)
        window.mark_milestone('first_thumbnail_visible', at_monotonic=10.05)
        window.begin_pass(at_monotonic=100.)
        self.assertEqual(
            window.snapshot(at_monotonic=100.).transport_source, 'pending',
        )
        self.assertEqual(window.snapshot(at_monotonic=100.).milestones, ())
        self.assertIsNone(
            window.snapshot(at_monotonic=100.).fresh_to_viewport_settled_ms,
        )
        self.assertEqual(window.snapshot(at_monotonic=100.).events, 1)
        self.assertTrue(window.mark_milestone(
            'fresh_rows_confirmed', at_monotonic=100.02,
        ))
        self.assertEqual(
            dict(window.snapshot(at_monotonic=100.1).milestones),
            {'fresh_rows_confirmed': 20.0},
        )


if __name__ == '__main__':
    unittest.main()
