# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_support_report.py
# Module Type: Regression Test
# Purpose: Protect offline support report completeness and privacy filtering.
# Responsibilities:
#   - Verify bounded log-tail reading and explicit missing/error statuses.
#   - Verify credentials, URL tokens, coordinates and identity redaction.
#   - Verify report saving preserves the requested text.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise support report creation with temporary local logs only."""

import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.support_report import (
    SUPPORT_LOG_TAIL_MAX_BYTES,
    SUPPORT_REPORT_MAX_CHARS,
    build_support_report,
    save_support_report,
)


class SupportReportTests(unittest.TestCase):
    """Check complete reports retain useful facts without private values."""

    def test_report_is_complete_redacted_and_allowlisted(self) -> None:
        """Retain current details while filtering unsafe metadata and URLs.

        Returns:
            None: The report contains useful facts and no fixture secrets.
        """
        with tempfile.TemporaryDirectory() as directory:
            log_path = Path(directory) / "app.log"
            log_path.write_text(
                "phase=upload Authorization: Bearer top-secret\n"
                "Cookie: session=private-cookie\n"
                "url=https://user:pass@example.test/api?token=private#frag\n"
                "host=fixture-host user=alice@example.test "
                "C:\\Users\\alice\\Pictures\\secret.jpg\n"
                "latitude=50.1 longitude=14.4\n"
                "lat=50.1 lon=14.4 alt=1250\n"
                '{"token": "json-secret", "latitude": 12}\n',
                encoding="utf-8",
            )
            report = build_support_report(
                "current phase=finish\n"
                "request https://example.test/path?key=secret",
                {
                    "scan_interval_seconds": 30.5, "stable_seconds": 1.25,
                    "ai_vision_backend": float("inf"),
                    "password": "never-allowed",
                },
                {"app_version": "1.2.3", "runtime": "Windows",
                 "platform": "metadata-secret", "hostname": "not-allowlisted"},
                log_path,
                captured_at=datetime(2026, 1, 2, tzinfo=timezone.utc),
                secrets=(
                    "unlabeled-api-secret", "metadata-secret", "json-secret",
                ),
            )
        self.assertIn("Captured: 2026-01-02T00:00:00+00:00", report)
        self.assertIn("Current diagnostics", report)
        self.assertIn("phase=finish", report)
        self.assertIn("app_version: 1.2.3", report)
        self.assertNotIn("hostname:", report)
        self.assertNotIn("password:", report)
        self.assertIn("scan_interval_seconds: 30.5", report)
        self.assertIn("stable_seconds: 1.25", report)
        self.assertNotIn("ai_vision_backend:", report)
        self.assertIn("status: included", report)
        for secret in (
            "top-secret", "private", "frag", "private-cookie", "user:pass",
            "fixture-host",
            "alice@example.test", "secret.jpg", "never-allowed", "50.1",
            "json-secret", '"latitude": 12', "metadata-secret", "14.4",
            "1250",
        ):
            self.assertNotIn(secret, report)
        self.assertIn("https://example.test/path?[REDACTED]", report)
        self.assertIn("https://example.test/api?[REDACTED]", report)

    def test_jwt_and_percent_encoded_query_values_are_removed(self) -> None:
        """Remove JWT fields and encoded values from query and fragment text.

        Returns:
            None: No token segments survive URL or labeled-field filtering.
        """
        report = build_support_report(
            "jwt=eyJhbGciOiJIUzI1NiJ9.payload.signature\n"
            "url=https://gallery.example/api?"
            "jwt=eyJquery.jwt\n"
            "url2=https://gallery.example/route?"
            "%61ccess_token=%65yJpercent%2Dsecret#eyJfragment\n"
            "malformed=https://[invalid",
            {}, {},
        )
        for sensitive in (
            "eyJhbGciOiJIUzI1NiJ9", "payload.signature", "eyJquery.jwt",
            "%65yJpercent%2Dsecret", "eyJfragment",
        ):
            self.assertNotIn(sensitive, report)
        self.assertIn("malformed=[URL REDACTED]", report)

    def test_known_secret_is_removed_without_a_label(self) -> None:
        """Remove exact configured credential values wherever they appear.

        Returns:
            None: The secret does not survive in current diagnostics or log.
        """
        report = build_support_report(
            "diagnostic unlabeled-api-secret", {},
            {"platform": "unlabeled-api-secret"},
            secrets=iter(("unlabeled-api-secret",)),
        )
        self.assertNotIn("unlabeled-api-secret", report)

    def test_partial_first_line_is_dropped_after_tail_seek(self) -> None:
        """Drop a credential line cut by the byte-tail boundary.

        Returns:
            None: A partial line cannot leak a value whose field label was cut.
        """
        with tempfile.TemporaryDirectory() as directory:
            log_path = Path(directory) / "boundary.log"
            log_path.write_bytes(
                b"Authorization: Bearer " + b"partial-secret-" * 5000
                + b"\nphase=complete\n"
            )
            report = build_support_report("diagnostic", {}, {}, log_path)
        self.assertIn("leading_partial_line=dropped", report)
        self.assertIn("phase=complete", report)
        self.assertNotIn("partial-secret-", report)

    def test_unreadable_log_is_reported_without_error_text(self) -> None:
        """Report an I/O failure without including its path or OS message.

        Returns:
            None: The report names the status and omits private exception data.
        """
        with mock.patch.object(Path, "open", side_effect=PermissionError(
            "C:\\Users\\alice\\private.log",
        )):
            report = build_support_report(
                "diagnostic", {}, {}, Path("C:/Users/alice/private.log"),
            )
        self.assertIn("status: unreadable", report)
        self.assertNotIn("private.log", report)

    def test_log_read_is_limited_to_tail_and_marks_truncation(self) -> None:
        """Include only the bounded suffix and state that earlier bytes were cut.

        Returns:
            None: The report marks truncation and omits the discarded prefix.
        """
        with tempfile.TemporaryDirectory() as directory:
            log_path = Path(directory) / "large.log"
            log_path.write_bytes(
                b"discarded-prefix\n" + b"x" * SUPPORT_LOG_TAIL_MAX_BYTES
            )
            report = build_support_report("diagnostic", {}, {}, log_path)
        self.assertIn("truncated=yes", report)
        self.assertNotIn("discarded-prefix", report)
        self.assertIn("x" * 200, report)

    def test_report_cap_includes_its_truncation_marker(self) -> None:
        """Keep final report length within its declared character ceiling.

        Returns:
            None: Oversized diagnostic text receives an in-cap marker.
        """
        report = build_support_report("x" * (SUPPORT_REPORT_MAX_CHARS + 1), {}, {})
        self.assertEqual(len(report), SUPPORT_REPORT_MAX_CHARS)
        self.assertTrue(report.endswith("[REPORT TRUNCATED]"))

    def test_absent_log_is_reported_and_save_writes_report(self) -> None:
        """Describe an absent optional log and atomically save the report.

        Returns:
            None: Missing input is explicit and saved text is byte-for-byte.
        """
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            report = build_support_report(
                "diagnostic detail", {}, {}, base / "missing.log",
            )
            destination = base / "nested" / "support.txt"
            save_support_report(destination, report)
            self.assertEqual(report, destination.read_text(encoding="utf-8"))
        self.assertIn("status: missing", report)
        self.assertIn("diagnostic detail", report)
