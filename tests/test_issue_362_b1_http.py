# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tests/test_issue_362_b1_http.py
# Module Type: Portable HTTP Transport Contract Regression
# Purpose: Validate bounded MIME, Retry-After and B1.2 fallback decisions.
# Responsibilities:
#   - Reuse B1.1 parsers without real network or third-party dependencies.
#   - Refuse ambiguous HTTP headers and unsafe fallback on denial or 429.
#   - Exercise deterministic cancellation and mixed-status decision rules.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Pure HTTP contract tests runnable on Windows and Linux Python 3.14."""

import unittest
from datetime import datetime, timezone

from tools.perf.issue_362_b1_http import (
    FRAMED_CONTENT_TYPE,
    HTTP_BODY_LIMIT,
    fallback_decision,
    make_parser,
    parse_retry_after,
    validate_content_length,
)
from tools.perf.issue_362_b1_protocol import (
    FramedParser,
    MultipartParser,
    ProtocolError,
)


class B1HTTPPolicyTests(unittest.TestCase):
    """Keep experimental B1.2 admission independent of network availability."""

    def test_content_type_and_parser_reuse(self) -> None:
        """Admit only one negotiated v1 MIME type and reuse B1.1 parsers.

        Returns:
            None: None.
        """
        framed = make_parser("framed", [FRAMED_CONTENT_TYPE], 8)
        mixed = make_parser(
            "multipart", ["multipart/mixed; boundary=example_123"], 8
        )
        self.assertIsInstance(framed, FramedParser)
        self.assertIsInstance(mixed, MultipartParser)
        for kind, values in (
            ("framed", []),
            ("framed", ["text/html"]),
            ("framed", [FRAMED_CONTENT_TYPE, FRAMED_CONTENT_TYPE]),
            ("framed", ["application/octet-stream"]),
            ("multipart", ["multipart/mixed"]),
            ("multipart", ["multipart/mixed; boundary=bad\r\ninjection"]),
            ("multipart", ["text/plain"]),
        ):
            with self.subTest(kind=kind, values=values):
                with self.assertRaises(ProtocolError):
                    make_parser(kind, values, 8)

    def test_content_length_preflight(self) -> None:
        """Refuse duplicated, malformed, negative or oversized HTTP lengths.

        Returns:
            None: None.
        """
        self.assertIsNone(validate_content_length([]))
        self.assertEqual(validate_content_length(["0"]), 0)
        self.assertEqual(
            validate_content_length([str(HTTP_BODY_LIMIT)]),
            HTTP_BODY_LIMIT,
        )
        for length in (
            ["1", "1"], ["-1"], ["bad"], [" 1"], ["1, 2"],
            [str(HTTP_BODY_LIMIT + 1)],
        ):
            with self.subTest(length=length):
                with self.assertRaises(ProtocolError):
                    validate_content_length(length)

    def test_retry_after_delta_date_and_absence(self) -> None:
        """Distinguish missing, malformed, date, negative and bounded delay.

        Returns:
            None: None.
        """
        reference = datetime(2026, 11, 11, 12, 0, 0, tzinfo=timezone.utc)
        self.assertEqual(parse_retry_after("2", reference), 2)
        self.assertEqual(parse_retry_after("1000", reference), 120)
        self.assertEqual(
            parse_retry_after("Wed, 11 Nov 2026 12:00:20 GMT", reference),
            20,
        )
        for value in (None, "", "bad", "-5", "1.5"):
            with self.subTest(value=value):
                self.assertIsNone(parse_retry_after(value, reference))
        self.assertIsNone(
            parse_retry_after("Wed, 11 Nov 2026 11:59:59 GMT", reference)
        )

    def test_auth_rate_and_unsupported_route_never_bypassed(self) -> None:
        """Require explicit independent authority for optional-route fallback.

        Returns:
            None: None.
        """
        for code, expected in (
            (401, "stop_authorization"),
            (403, "stop_authorization"),
            (429, "stop_rate_limited"),
            (502, "bounded_retry_only"),
            (503, "bounded_retry_only"),
            (404, "stop_unverified_route"),
            (405, "stop_unverified_route"),
        ):
            with self.subTest(code=code):
                self.assertEqual(
                    fallback_decision(code, "http_" + str(code)),
                    expected,
                )
        self.assertEqual(
            fallback_decision(
                404, "http_404", optional_route_missing=True,
                single_authorized=False,
            ),
            "stop_unverified_route",
        )
        self.assertEqual(
            fallback_decision(
                404, "http_404", optional_route_missing=True,
                single_authorized=True,
            ),
            "single_after_capability_check",
        )
        self.assertEqual(
            fallback_decision(
                403, "http_403", optional_route_missing=True,
                single_authorized=True,
            ),
            "stop_authorization",
        )
        self.assertEqual(
            fallback_decision(
                429, "http_429", optional_route_missing=True,
                single_authorized=True,
            ),
            "stop_rate_limited",
        )

    def test_corruption_batch_reduction_and_cancellation(self) -> None:
        """Do not amplify failures into unbounded single-request storms.

        Returns:
            None: None.
        """
        self.assertEqual(
            fallback_decision(200, "protocol_invalid", batch_count=32),
            "reduce_batch_after_investigation",
        )
        self.assertEqual(
            fallback_decision(200, "protocol_invalid", batch_count=8),
            "stop_invalid_batch",
        )
        self.assertEqual(
            fallback_decision(503, "http_503", batch_count=32),
            "bounded_retry_only",
        )
        self.assertEqual(
            fallback_decision(200, None, cancelled=True),
            "stop_cancelled",
        )
        self.assertEqual(
            fallback_decision(None, "transport_timeout"),
            "bounded_retry_only",
        )


if __name__ == "__main__":
    unittest.main()
