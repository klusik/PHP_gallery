# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_passive_server_timing.py
# Module Type: Portable Python Regression Test
# Purpose: Validate privacy-safe per-response PHP timing parsing and comparison.
# Responsibilities:
#   - Reject malformed, repeated, overlong and non-finite server headers.
#   - Preserve missing/unknown status and ambiguous multiple send semantics.
#   - Prove bounded monotonic window, nearest-rank data and thread safety.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable A2.5 strict PHP response-timing diagnostics tests."""

from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import sys
import time
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.passive_server_timing import (
    PassiveServerTiming, ServerTimingWindow, parse_server_timing,
    SERVER_TIMING_MAX_SAMPLES,
)
from uploader import app_linking as linking


HEADER = (
    'gallery-php;dur=12.000, '
    'gallery-sql;dur=4.500;desc="n=5;max_ms=2.500;failed=0;cap=0", '
    'gallery-auth;dur=2.000, gallery-tree;dur=6.000, '
    'gallery-hash;dur=0.500;desc="bytes=32768", '
    'gallery-media;dur=1.000'
)


class PassiveServerTimingTests(unittest.TestCase):
    """Ensure one header never becomes a raw server string or invented timing."""

    def test_accepts_existing_php_wire_contract(self) -> None:
        """Parse the exact authorized format without retaining any text.

        Returns:
            None: Assertions verify existing six metric fields and counts.
        """
        parsed = parse_server_timing([HEADER])
        self.assertIsNotNone(parsed)
        self.assertEqual(parsed.php_ms, 12.0)
        self.assertEqual(parsed.sql_ms, 4.5)
        self.assertEqual(parsed.sql_count, 5)
        self.assertEqual(parsed.hash_bytes, 32768)
        self.assertEqual(parsed.auth_ms, 2.0)

    def test_missing_repeated_or_untrusted_headers_are_ignored(self) -> None:
        """Reject arbitrary descriptions, injection and repeated headers.

        Returns:
            None: Tests assert no dynamic metadata enters the parser.
        """
        invalid = [
            [], [HEADER, HEADER], [HEADER + ' private-token'],
            [HEADER.replace('bytes=32768', 'bytes=99999999')],
            [HEADER.replace('n=5', 'n=999')],
            [HEADER.replace('failed=0', 'failed=6')],
            [HEADER.replace('php;dur=12.000', 'php;dur=nan')],
            [HEADER.replace('php;dur=12.000', 'php;dur=-1.000')],
            [HEADER.replace('gallery-php', 'other-php')],
            [HEADER + '\r\nX-Token: secret'], ['x' * 513],
        ]
        for headers in invalid:
            self.assertIsNone(parse_server_timing(headers))

    def test_same_response_delta_is_not_network_attribution(self) -> None:
        """Only a single physical attempt permits a comparable interval.

        Returns:
            None: Assertions verify exact request provenance and uncertainty.
        """
        parsed = parse_server_timing([HEADER])
        self.assertIsNotNone(parsed)
        window = ServerTimingWindow()
        window.observe('thumbnail_single', parsed, 42.0, 1, at_monotonic=10.)
        window.observe('thumbnail_single', parsed, 42.0, 2, at_monotonic=11.)
        window.observe('thumbnail_single', parsed, 11.0, 1, at_monotonic=12.)
        window.observe('thumbnail_single', None, 42.0, 1, at_monotonic=13.)
        summary = window.render_summary(at_monotonic=15.)
        self.assertIn('php_present=3 php_missing=1', summary)
        self.assertIn('unattributed_to_measured_php_samples=1', summary)
        self.assertIn('unattributed_to_measured_php_p95_ms=30.0', summary)

    def test_input_rejection_and_retention_bound(self) -> None:
        """Reject unsupported routing and non-finite send-to-headers values.

        Returns:
            None: Assertions maintain fixed-window cap and privacy.
        """
        window = ServerTimingWindow()
        parsed = parse_server_timing([HEADER])
        self.assertFalse(window.observe(
            'https://site/?bearer=secret', parsed, 10.0, 1,
            at_monotonic=10.,
        ))
        self.assertFalse(window.observe(
            'gallery_tree', parsed, float('inf'), 1, at_monotonic=10.,
        ))
        self.assertFalse(window.observe(
            'gallery_tree', parsed, 10.0, 12, at_monotonic=10.,
        ))
        for i in range(SERVER_TIMING_MAX_SAMPLES + 30):
            self.assertTrue(window.observe(
                'gallery_tree', parsed, 14., 1, at_monotonic=float(i),
            ))
        self.assertIn(
            f'samples={SERVER_TIMING_MAX_SAMPLES}',
            window.render_summary(at_monotonic=290.),
        )
        self.assertIn('samples=0', window.render_summary(at_monotonic=600.))

    def test_concurrent_producers_keep_fixed_numeric_output(self) -> None:
        """Bound observation history with interleaved real client workers.

        Returns:
            None: Assertions prove finite sample count and sanitized output.
        """
        window = ServerTimingWindow()
        timing = parse_server_timing([HEADER])

        def produce(amount: int) -> None:
            """Insert a bounded number of fixed, identity-free observations.

            Args:
                amount (int): Count for one independent producer thread.

            Returns:
                None: All observations pass the central bounded gate.
            """
            for _ in range(amount):
                self.assertTrue(window.observe(
                    'gallery_page', timing, 22., 1, at_monotonic=100.,
                ))

        with ThreadPoolExecutor(max_workers=4) as pool:
            list(pool.map(produce, (75, 75, 75, 75)))
        exported = window.render_summary(at_monotonic=101.)
        self.assertIn('samples=256', exported)
        self.assertNotIn('Authorization', exported)
        self.assertNotIn('private-token', exported)

    def test_actual_linking_session_pairs_validated_tree_response(self) -> None:
        """Join only one authenticated tree HTTP reply with its PHP timing.

        Returns:
            None: Session observes the exact numeric response or missing data.
        """
        session = linking.LinkingSession(
            'https://gallery.example', 'pgla_' + 'A' * 43,
            time.monotonic() + 120., linking.GALLERIES_SCOPE,
            gallery_tree_supported=True,
        )
        parsed = parse_server_timing([HEADER])
        valid_tree: dict[str, linking.JsonValue] = {
            'version': 1, 'revision': 'a' * 64,
            'unchanged': False, 'galleries': [],
        }
        with mock.patch.object(linking, '_request_json') as sender:
            sender.side_effect = lambda *_args, **kwargs: (
                kwargs['server_timing_out'].append((parsed, 20.0)),
                valid_tree,
            )[1]
            self.assertIsNotNone(session.gallery_tree())
        summary = session.server_timing_summary()
        self.assertIn('route=gallery_tree requests=1', summary)
        self.assertIn('php_present=1 php_missing=0', summary)
        self.assertIn('unattributed_to_measured_php_p95_ms=8.0', summary)


if __name__ == '__main__':
    unittest.main()
