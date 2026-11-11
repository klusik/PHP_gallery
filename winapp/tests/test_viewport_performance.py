# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_viewport_performance.py
# Module Type: Portable Python Regression Test
# Purpose: Prove bounded first and subsequent visible viewport completion.
# Responsibilities:
#   - Reject incomplete, stale and duplicate viewport outcomes.
#   - Keep unavailable placeholders distinct from genuine Tk image successes.
#   - Validate monotonic timings, sample expiry, percentiles and safe export.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable deterministic tests for identity-free A2.6 viewport measurements."""

from pathlib import Path
import sys
import unittest


WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.viewport_performance import (
    VIEWPORT_PERFORMANCE_SAMPLE_LIMIT,
    ViewportPerformanceWindow,
)


class ViewportPerformanceWindowTests(unittest.TestCase):
    """Verify that only actual complete visible bands enter the observation."""

    def test_empty_and_incomplete_bands_are_not_successes(self) -> None:
        """Keep empty viewports and missing tiles explicitly unmeasured.

        Returns:
            None: Assertions verify the scoped numeric diagnostic contract.
        """
        window = ViewportPerformanceWindow()
        self.assertEqual(window.snapshot(at_monotonic=1.).current_state, 'empty')
        empty_epoch = window.begin(0, at_monotonic=2.)
        self.assertIsNotNone(empty_epoch)
        self.assertFalse(window.settle(empty_epoch, 0, 0, at_monotonic=2.1))
        epoch = window.begin(4, at_monotonic=3.)
        self.assertFalse(window.settle(epoch, 3, 0, at_monotonic=3.1))
        snapshot = window.snapshot(at_monotonic=3.2)
        self.assertEqual(snapshot.current_state, 'pending')
        self.assertEqual(snapshot.current_visible, 4)
        self.assertEqual(snapshot.settled, 0)
        self.assertIsNone(snapshot.p95_ms)

    def test_terminal_placeholders_do_not_masquerade_as_images(self) -> None:
        """Record unavailable tiles only once the complete band is resolved.

        Returns:
            None: Assertions verify the scoped numeric diagnostic contract.
        """
        window = ViewportPerformanceWindow()
        epoch = window.begin(4, at_monotonic=10.)
        self.assertTrue(window.settle(epoch, 3, 1, at_monotonic=10.125))
        self.assertFalse(window.settle(epoch, 4, 0, at_monotonic=10.130))
        result = window.snapshot(at_monotonic=10.2)
        self.assertEqual(result.current_state, 'settled')
        self.assertEqual(result.current_available, 3)
        self.assertEqual(result.current_unavailable, 1)
        self.assertEqual(result.unavailable_samples, 1)
        self.assertEqual(result.p50_ms, 125.)
        safe = window.render_summary(at_monotonic=10.2)
        self.assertIn('current_unavailable=1', safe)
        self.assertNotIn('photo_id', safe)
        self.assertNotIn('Authorization', safe)

    def test_superseded_viewport_cannot_publish_stale_completion(self) -> None:
        """Reject old viewport outcomes after a real scroll or replacement.

        Returns:
            None: Assertions verify the scoped numeric diagnostic contract.
        """
        window = ViewportPerformanceWindow()
        previous = window.begin(5, at_monotonic=20.)
        current = window.begin(2, at_monotonic=20.04)
        self.assertFalse(window.settle(previous, 5, 0, at_monotonic=20.05))
        self.assertTrue(window.settle(current, 2, 0, at_monotonic=20.10))
        result = window.snapshot(at_monotonic=20.11)
        self.assertEqual(result.superseded_lifetime, 1)
        self.assertEqual(result.settled, 1)
        self.assertEqual(result.current_visible, 2)
        self.assertEqual(result.current_available, 2)

    def test_nearest_rank_and_window_expiry(self) -> None:
        """Bound percentiles to observed durations and current window only.

        Returns:
            None: Assertions verify the scoped numeric diagnostic contract.
        """
        window = ViewportPerformanceWindow()
        for index, ms in enumerate((10, 20, 30, 40, 50)):
            start = float(index * 2 + 1)
            epoch = window.begin(2, at_monotonic=start)
            self.assertTrue(window.settle(
                epoch, 2, 0, at_monotonic=start + ms / 1000.,
            ))
        result = window.snapshot(at_monotonic=10.)
        self.assertEqual(result.p50_ms, 30.)
        self.assertEqual(result.p95_ms, 50.)
        self.assertEqual(result.p99_ms, 50.)
        expired = window.snapshot(at_monotonic=400.)
        self.assertEqual(expired.settled, 0)
        self.assertIsNone(expired.p95_ms)

    def test_sample_cap_does_not_expand_with_scroll_activity(self) -> None:
        """Keep only 256 completions even after many fast viewport changes.

        Returns:
            None: Assertions verify the scoped numeric diagnostic contract.
        """
        window = ViewportPerformanceWindow()
        for index in range(VIEWPORT_PERFORMANCE_SAMPLE_LIMIT + 30):
            start = 1.0 + index / 10.
            epoch = window.begin(1, at_monotonic=start)
            self.assertTrue(window.settle(
                epoch, 1, 0, at_monotonic=start + .001,
            ))
        snapshot = window.snapshot(at_monotonic=31.)
        self.assertEqual(snapshot.settled, VIEWPORT_PERFORMANCE_SAMPLE_LIMIT)
        self.assertEqual(snapshot.unavailable_samples, 0)

    def test_rejects_invalid_numbers_and_reversed_monotonic_time(self) -> None:
        """Never export forged durations, booleans or impossible tile totals.

        Returns:
            None: Assertions verify the scoped numeric diagnostic contract.
        """
        window = ViewportPerformanceWindow()
        self.assertIsNone(window.begin(True, at_monotonic=1.))
        self.assertIsNone(window.begin(-1, at_monotonic=1.))
        self.assertIsNone(window.begin(10001, at_monotonic=1.))
        self.assertIsNone(window.begin(1, at_monotonic=float('nan')))
        epoch = window.begin(2, at_monotonic=5.)
        self.assertFalse(window.settle(epoch, 2, 0, at_monotonic=4.))
        self.assertFalse(window.settle(epoch, True, 1, at_monotonic=6.))
        self.assertFalse(window.settle(epoch, 1, -1, at_monotonic=6.))
        self.assertFalse(window.settle(epoch, 2, 1, at_monotonic=6.))
        self.assertFalse(window.settle(epoch, 2, 0, at_monotonic=606.))
        self.assertTrue(window.settle(epoch, 1, 1, at_monotonic=5.5))
        with self.assertRaises(ValueError):
            window.snapshot(at_monotonic=float('inf'))


if __name__ == '__main__':
    unittest.main()
