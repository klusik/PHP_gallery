# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_runtime_check.py
# Module Type: Regression Test
# Purpose: Refuse incorrect installer/frozen Python runtimes before publication.
# Responsibilities:
#   - Verify version/architecture reports and pre-GUI frozen runtime dispatch.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Deterministic interpreter attestation, independent of the CI host's Python."""

import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.runtime_check import validate_runtime_info


class RuntimeCheckTests(unittest.TestCase):
    """Prevent runner defaults or a wrong frozen interpreter passing unnoticed."""

    def test_only_x64_cpython_314_patch_versions_are_accepted(self) -> None:
        """Accept patch upgrades while refusing 3.13, 3.15, PyPy and x86.

        Returns:
            None: Asserts supported and unsupported concrete reports.
        """
        for version, bits, implementation, accepted in (
            ("3.14.0", 64, "CPython", True),
            ("3.14.99", 64, "CPython", True),
            ("3.13.7", 64, "CPython", False),
            ("3.15.0", 64, "CPython", False),
            ("3.14.1", 32, "CPython", False),
            ("3.14.1", 64, "PyPy", False),
            ("3.14.0rc1", 64, "CPython", False),
        ):
            with self.subTest(
                version=version, bits=bits, implementation=implementation
            ):
                report = {
                    "python_version": version,
                    "pointer_bits": bits,
                    "implementation": implementation,
                }
                if accepted:
                    self.assertIsNone(validate_runtime_info(report))
                else:
                    with self.assertRaises(RuntimeError):
                        validate_runtime_info(report)

    def test_frozen_report_precedes_configuration_logging_and_gui(self) -> None:
        """Write actual runtime info through the early diagnostic entrypoint.

        Returns:
            None: Asserts successful report dispatch without app startup.
        """
        launcher = WINAPP_DIR / "gallery_watch_upload.pyw"
        prefix = launcher.read_text(encoding="utf-8").split("\nimport logging", 1)[0]
        expected = {
            "python_version": "3.14.4",
            "implementation": "CPython",
            "pointer_bits": 64,
        }
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / "runtime.json"
            namespace = {"__name__": "__main__", "__file__": str(launcher)}
            with (
                mock.patch.object(
                    sys, "argv", ["app.exe", "--runtime-info", str(report)]
                ),
                mock.patch("multiprocessing.freeze_support"),
                mock.patch(
                    "uploader.runtime_check.runtime_info", return_value=expected
                ),
                self.assertRaises(SystemExit) as stopped,
            ):
                exec(compile(prefix, str(launcher), "exec"), namespace)
            self.assertEqual(0, stopped.exception.code)
            self.assertEqual(expected, json.loads(report.read_text(encoding="utf-8")))
            self.assertNotIn("ConfigStore", namespace)
            self.assertNotIn("tk", namespace)

    def test_wrong_frozen_interpreter_exits_before_gui_without_report(self) -> None:
        """Fail a wrong embedded interpreter through the controlled early exit.

        Returns:
            None: Asserts no report, configuration or GUI is initialized.
        """
        launcher = WINAPP_DIR / "gallery_watch_upload.pyw"
        prefix = launcher.read_text(encoding="utf-8").split("\nimport logging", 1)[0]
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / "runtime.json"
            namespace = {"__name__": "__main__", "__file__": str(launcher)}
            with (
                mock.patch.object(
                    sys, "argv", ["app.exe", "--runtime-info", str(report)]
                ),
                mock.patch.object(sys, "stderr", None),
                mock.patch("multiprocessing.freeze_support"),
                mock.patch(
                    "uploader.runtime_check.runtime_info",
                    side_effect=RuntimeError("Wrong frozen interpreter"),
                ),
                self.assertRaises(SystemExit) as stopped,
            ):
                exec(compile(prefix, str(launcher), "exec"), namespace)
            self.assertEqual(1, stopped.exception.code)
            self.assertFalse(report.exists())
            self.assertNotIn("ConfigStore", namespace)
            self.assertNotIn("tk", namespace)


if __name__ == "__main__":
    unittest.main()
