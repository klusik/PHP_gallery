# Project: PHP Gallery
# Module Type: Regression Test
# Purpose: Verify frozen update smoke dispatch before application initialization.
# Responsibilities:
#   - Protect the diagnostic boundary from configuration, logging and GUI startup.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/tests/test_self_update_smoke.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
"""Verify diagnostic dispatch before configuration, logging, or GUI imports."""

from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest import mock


class FrozenSmokeDispatchTests(unittest.TestCase):
    """The diagnostic invokes the actual backend interface without update actions."""

    def exercise_dispatch(self, actual_version: str | Exception) -> int:
        """Execute only the launcher prefix with an observed version backend."""
        launcher = Path(__file__).resolve().parents[1] / "gallery_watch_upload.pyw"
        prefix = launcher.read_text(encoding="utf-8").split("\nimport logging", 1)[0]
        backend = mock.Mock()
        if isinstance(actual_version, Exception):
            backend.version.side_effect = actual_version
        else:
            backend.version.return_value = actual_version
        helper = types.ModuleType("uploader.update_helper")
        factory = mock.Mock(return_value=backend)
        helper.WindowsBackend = factory
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "VERSION").write_text("0.3.2", encoding="utf-8")
            namespace = {"__name__": "__main__", "__file__": str(root / "launcher.pyw")}
            with mock.patch.dict(sys.modules, {"uploader.update_helper": helper}), \
                    mock.patch.object(sys, "argv", ["helper.exe", "--self-update-smoke"]), \
                    mock.patch.object(sys, "executable", str(root / "helper.exe")), \
                    mock.patch.object(sys, "stderr", None), \
                    mock.patch("multiprocessing.freeze_support"), \
                    mock.patch("logging.basicConfig") as logging_setup:
                with self.assertRaises(SystemExit) as result:
                    exec(compile(prefix, str(launcher), "exec"), namespace)
                factory.assert_called_once_with()
                backend.version.assert_called_once_with(str(root / "helper.exe"))
                self.assertEqual([mock.call.version(str(root / "helper.exe"))], backend.mock_calls)
                logging_setup.assert_not_called()
                self.assertNotIn("ConfigStore", namespace)
                self.assertNotIn("tk", namespace)
                return result.exception.code

    def test_matching_version_exits_successfully(self) -> None:
        """A matching PE version finishes without any application initialization."""
        self.assertEqual(0, self.exercise_dispatch("0.3.2"))

    def test_mismatch_and_native_failure_exit_nonzero(self) -> None:
        """Both mismatched metadata and loader failure stop packaging."""
        for result in ("0.3.1", OSError("Native version library unavailable")):
            with self.subTest(result=result):
                self.assertEqual(1, self.exercise_dispatch(result))


if __name__ == "__main__":
    unittest.main()
