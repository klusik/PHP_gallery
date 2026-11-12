# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_photos_performance.py
# Module Type: Portable Python Regression Test
# Purpose: Verify identity-free bounded WinApp thumbnail performance sampling.
# Responsibilities:
#   - Test monotonic expiry, capacity and fixed outcome vocabulary.
#   - Assert real HTTP attempt accounting and nearest-rank percentiles.
#   - Verify concurrent observations and non-finite/unsafe value rejection.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable regression tests for A2 thumbnail timing statistics."""

from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
from pathlib import Path
import sys
import unittest

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.app_linking import MediaReadTimings
from uploader.photos_performance import (
    THUMBNAIL_PERFORMANCE_SAMPLE_LIMIT,
    ThumbnailBatchPhysicalWindow,
    ThumbnailPerformanceWindow,
)


class ThumbnailPerformanceWindowTests(unittest.TestCase):
    """Validate only numerical, bounded, credential-free read observations."""

    def test_batch_physical_window_counts_attempts_without_tile_samples(self) -> None:
        """Keep multiple-send aggregate timing out of single-send percentiles.

        Returns:
            None: One physical operation records its two actual sends once.
        """
        window = ThumbnailBatchPhysicalWindow()
        self.assertTrue(window.observe(MediaReadTimings(
            http_requests=2, encoded_bytes=100, ttfb_ms=15.0, network_ms=25.0,
        )))
        summary = window.render_summary()
        self.assertIn('route=thumbnail_batch', summary)
        self.assertIn('operations=1', summary)
        self.assertIn('http_attempts=2', summary)
        self.assertIn('multiple_http_samples=1', summary)
        self.assertIn('ttfb_p95_ms=unknown', summary)
        self.assertTrue(window.observe(MediaReadTimings(
            http_requests=1, encoded_bytes=100, ttfb_ms=12.0, network_ms=20.0,
        )))
        summary = window.render_summary()
        self.assertIn('single_http_samples=1', summary)
        self.assertIn('ttfb_median_ms=12.0', summary)
        self.assertIn('ttfb_p95_ms=unknown', summary)

    def test_empty_window_has_explicitly_unknown_percentiles(self) -> None:
        """Do not fabricate timing distributions before a genuine observation.

        Returns:
            None: Assertions verify a correctly empty window.
        """
        metrics = ThumbnailPerformanceWindow().snapshot(at_monotonic=50.0)
        self.assertEqual(metrics['sampled_reads'], 0)
        self.assertEqual(metrics['http_attempts'], 0)
        self.assertEqual(metrics['route'], 'thumbnail_single')
        self.assertIsNone(metrics['ttfb_p95_ms'])
        self.assertEqual(metrics['window_seconds'], 300)

    def test_single_http_percentiles_and_disk_reads_are_distinct(self) -> None:
        """Count actual HTTP sends separately from cache-only read attempts.

        Returns:
            None: Assertions check source counts and percentile ranks.
        """
        window = ThumbnailPerformanceWindow()
        for index, delay in enumerate((10.0, 20.0, 30.0, 40.0)):
            timing = MediaReadTimings(
                source='network', http_requests=1, encoded_bytes=100,
                ttfb_ms=delay, network_ms=delay + 2.0,
                gate_wait_ms=float(index),
            )
            self.assertTrue(window.observe(timing, 1.0, at_monotonic=10 + index))
        disk = MediaReadTimings(source='disk', http_requests=0)
        self.assertTrue(window.observe(disk, 0.25, at_monotonic=15.0))
        metrics = window.snapshot(at_monotonic=15.0)
        self.assertEqual(metrics['sampled_reads'], 5)
        self.assertEqual(metrics['http_attempts'], 4)
        self.assertEqual(metrics['source_disk'], 1)
        self.assertEqual(metrics['single_http_samples'], 4)
        self.assertEqual(metrics['ttfb_p50_ms'], 20.0)
        self.assertEqual(metrics['ttfb_p95_ms'], 40.0)
        self.assertEqual(metrics['ttfb_p99_ms'], 40.0)
        self.assertEqual(metrics['network_p50_ms'], 22.0)

    def test_retries_are_counted_but_excluded_from_per_request_ttfb(self) -> None:
        """Never present aggregated two-send TTFB as one HTTP-request sample.

        Returns:
            None: Assertions keep physical sends and TTFB samples distinct.
        """
        window = ThumbnailPerformanceWindow()
        timing = MediaReadTimings(
            source='revalidated', http_requests=2,
            ttfb_ms=150.0, network_ms=200.0,
        )
        self.assertTrue(window.observe(timing, 0.1, at_monotonic=10.0))
        metrics = window.snapshot(at_monotonic=11.0)
        self.assertEqual(metrics['http_attempts'], 2)
        self.assertEqual(metrics['multiple_http_samples'], 1)
        self.assertEqual(metrics['single_http_samples'], 0)
        self.assertIsNone(metrics['ttfb_p50_ms'])

    def test_expiry_and_fixed_capacity(self) -> None:
        """Retain at most 256 recent observations, then expire old ones.

        Returns:
            None: Assertions verify hard sample capacity and monotonic expiry.
        """
        window = ThumbnailPerformanceWindow()
        read = MediaReadTimings(source='disk')
        for index in range(THUMBNAIL_PERFORMANCE_SAMPLE_LIMIT + 9):
            window.observe(read, 0.5, at_monotonic=float(index))
        self.assertEqual(
            window.snapshot(at_monotonic=264.0)['sampled_reads'],
            THUMBNAIL_PERFORMANCE_SAMPLE_LIMIT,
        )
        self.assertEqual(
            window.snapshot(at_monotonic=600.0)['sampled_reads'], 0,
        )

    def test_refuses_untrusted_values_before_retention(self) -> None:
        """Reject arbitrary labels, non-finite phases and invalid counters.

        Returns:
            None: Assertions exclude every malformed test observation.
        """
        window = ThumbnailPerformanceWindow()
        valid = MediaReadTimings(source='network', http_requests=1)
        self.assertFalse(window.observe(
            replace(valid, source='user@example.com'), 1.0,
            at_monotonic=20.0,
        ))
        self.assertFalse(window.observe(
            replace(valid, ttfb_ms=float('nan')), 1.0,
            at_monotonic=20.0,
        ))
        self.assertFalse(window.observe(
            replace(valid, http_requests=-1), 1.0,
            at_monotonic=20.0,
        ))
        self.assertFalse(window.observe(
            valid, float('inf'), at_monotonic=20.0,
        ))
        self.assertEqual(window.snapshot(at_monotonic=20.0)['sampled_reads'], 0)

    def test_concurrent_producers_are_bounded_and_export_is_safe(self) -> None:
        """Concurrent cache/network recorders cannot grow or leak identities.

        Returns:
            None: Assertions prove bounded count and private-free output.
        """
        window = ThumbnailPerformanceWindow()
        timing = MediaReadTimings(
            source='network', http_requests=1, ttfb_ms=2.5,
        )

        def produce(count: int) -> None:
            """Generate bounded numeric samples from one parallel worker.

            Args:
                count (int): Fixed number of samples for this worker.

            Returns:
                None: Assertions verify every generated sample was admitted.
            """
            for _ in range(count):
                self.assertTrue(window.observe(
                    timing, 0.1, at_monotonic=100.0,
                ))

        with ThreadPoolExecutor(max_workers=4) as workers:
            list(workers.map(produce, (90, 90, 90, 90)))
        metrics = window.snapshot(at_monotonic=101.0)
        self.assertEqual(metrics['sampled_reads'], 256)
        self.assertEqual(metrics['http_attempts'], 256)
        summary = window.render_summary(at_monotonic=101.0)
        self.assertIn('route=thumbnail_single', summary)
        self.assertIn('ttfb_p95_ms=2.5', summary)
        self.assertNotIn('gallery_id', summary)
        self.assertNotIn('Authorization', summary)


if __name__ == '__main__':
    unittest.main()
