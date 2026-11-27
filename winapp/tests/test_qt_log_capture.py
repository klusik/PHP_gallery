# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_qt_log_capture.py
# Module Type: Qt logging regression
# Purpose: Verify Qt runtime messages enter the shared sanitized Python log.
# Responsibilities: Cover real Qt warnings, severity/category, redaction and restoration.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Verify bounded Qt messages use Python logging and restore Qt handlers."""

import logging
from logging.handlers import RotatingFileHandler
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.support_report import read_support_log

try:
    from PySide6.QtCore import QMessageLogContext, QtMsgType, qInstallMessageHandler, qWarning
    from uploader.qt_application import _qt_log_capture
except (ImportError, OSError):
    if os.environ.get("PHP_GALLERY_REQUIRE_QT") == "1":
        raise
    QT_LOG_AVAILABLE = False
else:
    QT_LOG_AVAILABLE = True


@unittest.skipUnless(QT_LOG_AVAILABLE, "Optional Qt dependencies are absent.")
class QtLogCaptureTests(unittest.TestCase):
    """Check native Qt logging is redacted and scoped to the runtime."""

    def test_warning_is_logged_once_redacted_and_previous_handler_restored(self) -> None:
        """Route one real Qt warning and restore the previous handler on failure.

        Returns:
            None: Verifies the real log file and support tail, with no duplicate sink.
        """
        previous_calls: list[str] = []

        def previous_handler(
            _message_type: QtMsgType, _context: QMessageLogContext, message: str,
        ) -> None:
            """Capture any message forwarded to the predecessor handler.

            Args:
                _message_type (QtMsgType): Native Qt severity value.
                _context (QMessageLogContext): Native Qt category context.
                message (str): Original diagnostic string.
            Returns:
                None: Retains the call for the forwarding assertion.
            """
            previous_calls.append(message)

        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "watcher.log"
            logger = logging.getLogger("qt")
            handler = RotatingFileHandler(path, encoding="utf-8")
            handler.setFormatter(logging.Formatter("%(levelname)s %(message)s"))
            old_handler = qInstallMessageHandler(previous_handler)
            try:
                with (
                    mock.patch.object(logger, 'handlers', [handler]),
                    mock.patch.object(logger, 'propagate', False),
                ):
                    with _qt_log_capture():
                        qWarning("fixture /private/profile/secret.txt\npassword=fixture-secret")
                    self.assertEqual([], previous_calls)
                    qWarning("normal exit restored")
                    with self.assertRaisesRegex(RuntimeError, "fixture failure"):
                        with _qt_log_capture():
                            raise RuntimeError("fixture failure")
                    qWarning("failure restored")
            finally:
                qInstallMessageHandler(old_handler)
                handler.close()
            self.assertEqual(["normal exit restored", "failure restored"], previous_calls)
            lines = path.read_text(encoding="utf-8").splitlines()
            self.assertEqual(1, len(lines))
            self.assertIn("WARNING Qt [default]: fixture", lines[0])
            self.assertIn("[LOCAL PATH]", lines[0])
            self.assertNotIn("secret.txt", lines[0])
            self.assertNotIn("fixture-secret", lines[0])
            inclusion, tail = read_support_log(path)
            self.assertIn("included", inclusion)
            self.assertIn("Qt [default]: fixture", tail)


if __name__ == "__main__":
    unittest.main()
