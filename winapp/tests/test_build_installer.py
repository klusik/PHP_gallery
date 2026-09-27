# Project: PHP Gallery
# Module Type: Regression Test
# Purpose: Verify isolated Windows installer build behavior.
# Responsibilities:
#   - Protect cleanup and atomic publication on successful and failed builds.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/tests/test_build_installer.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
"""Verify installer publication and cleanup without downloading build tools."""

import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock


SPEC = importlib.util.spec_from_file_location(
    "winapp_build_installer", Path(__file__).resolve().parents[1] / "build_installer.py"
)
BUILD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILD)


class InstallerBuildTests(unittest.TestCase):
    """A failed tool must never publish a partial installer or retain staging."""

    def exercise_build(self, fail_at=None):
        with tempfile.TemporaryDirectory(prefix="installer tests ") as directory:
            source = Path(directory)
            (source / "VERSION").write_text("0.1.0\n", encoding="utf-8")
            dist = source / "dist"
            dist.mkdir()
            output = dist / "PHPGalleryUploader-0.1.0-Setup.exe"
            output.write_bytes(b"previous successful installer")
            calls = []

            def fake_run(command, **kwargs):
                calls.append(command)
                work = Path(kwargs["cwd"])
                self.assertEqual(source, work.parent)
                self.assertEqual(str(work / "temp"), kwargs["env"]["TEMP"])
                self.assertNotIn("PYTHONPATH", kwargs["env"])
                self.assertTrue(kwargs["check"])
                if len(calls) == fail_at:
                    raise subprocess.CalledProcessError(1, command)
                if "PyInstaller" in command:
                    self.assertIn("--onefile", command)
                    app = work / "app" / BUILD.APP_EXE
                    app.parent.mkdir()
                    app.write_bytes(b"standalone app")
                if command[0] == "ISCC.exe":
                    self.assertIn("/DAppVersion=0.1.0", command)
                    installer = work / "installer" / output.name
                    installer.parent.mkdir()
                    installer.write_bytes(b"complete installer")

            with mock.patch.object(BUILD.subprocess, "run", side_effect=fake_run):
                if fail_at:
                    with self.assertRaises(subprocess.CalledProcessError):
                        BUILD.build_installer("ISCC.exe", source)
                    self.assertEqual(b"previous successful installer", output.read_bytes())
                else:
                    self.assertEqual(output, BUILD.build_installer("ISCC.exe", source))
                    self.assertEqual(b"complete installer", output.read_bytes())
            self.assertEqual([], list(source.glob(".build-*")))
            self.assertEqual([output], list(dist.iterdir()))

    def test_success_publishes_only_installer_and_cleans_staging(self):
        self.exercise_build()

    def test_each_tool_failure_cleans_staging_and_preserves_previous_installer(self):
        for step in range(1, 7):
            with self.subTest(step=step):
                self.exercise_build(fail_at=step)

    def test_missing_explicit_compiler_does_not_silently_use_another(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(RuntimeError, "does not exist"):
                BUILD.find_iscc(str(Path(directory) / "missing.exe"))

    def test_invalid_version_stops_before_running_tools(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory)
            (source / "VERSION").write_text("0.1.0/invalid", encoding="utf-8")
            with mock.patch.object(BUILD.subprocess, "run") as run:
                with self.assertRaisesRegex(RuntimeError, "VERSION"):
                    BUILD.build_installer("ISCC.exe", source)
                run.assert_not_called()
            self.assertEqual([], list(source.glob(".build-*")))


if __name__ == "__main__":
    unittest.main()
