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
from importlib.metadata import PackageNotFoundError
import hashlib
from pathlib import Path
import shutil
import struct
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock


SPEC = importlib.util.spec_from_file_location(
    "winapp_build_installer", Path(__file__).resolve().parents[1] / "build_installer.py"
)
BUILD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILD)


class InstallerBuildTests(unittest.TestCase):
    """A failed tool must never publish a partial installer or retain staging."""

    def exercise_build(self, fail_at=None, use_installed_dependencies=False):
        """Simulate tool output while checking staging and atomic publication.

        ``fail_at`` injects a subprocess failure at the numbered tool step.
        The installed-dependencies mode must skip pip and venv while retaining
        the same cleanup and previous-installer preservation guarantees.
        """
        with tempfile.TemporaryDirectory(prefix="installer tests ") as directory:
            source = Path(directory)
            (source / "VERSION").write_text("0.1.0\n", encoding="utf-8")
            shutil.copyfile(BUILD.WINAPP_DIR / "SimConnect.dll", source / "SimConnect.dll")
            dist = source / "dist"
            dist.mkdir()
            output = dist / "PHPGalleryUploader-0.1.0-Setup.exe"
            output.write_bytes(b"previous successful installer")
            calls = []

            def fake_run(command, **kwargs):
                """Emulate build artifacts and validate each subprocess contract.

                No build tools run and no packages are installed. Create only
                the EXE/installer files the orchestrator expects in its staging
                tree, or raise the explicitly requested step failure.
                """
                calls.append(command)
                work = Path(kwargs["cwd"])
                self.assertEqual(source, work.parent)
                self.assertEqual(str(work / "temp"), kwargs["env"]["TEMP"])
                self.assertNotIn("PYTHONPATH", kwargs["env"])
                if use_installed_dependencies:
                    self.assertNotIn("PYTHONNOUSERSITE", kwargs["env"])
                else:
                    self.assertEqual("1", kwargs["env"]["PYTHONNOUSERSITE"])
                self.assertTrue(kwargs["check"])
                if len(calls) == fail_at:
                    raise subprocess.CalledProcessError(1, command)
                if "PyInstaller" in command:
                    self.assertIn("--onefile", command)
                    binary = command[command.index("--add-binary") + 1]
                    self.assertTrue(binary.endswith("runtime/simconnect"))
                    app = work / "app" / BUILD.APP_EXE
                    app.parent.mkdir()
                    app.write_bytes(b"standalone app")
                if BUILD.SIMCONNECT_ARCHIVE_PATH in command:
                    self.assertIn("CArchiveReader", command[2])
                    self.assertEqual(hashlib.sha256((source / "SimConnect.dll").read_bytes()).hexdigest(), command[-1])
                if command[0] == "ISCC.exe":
                    self.assertIn("/DAppVersion=0.1.0", command)
                    self.assertIn(f"/DSourceRoot={source}", command)
                    self.assertTrue((source / "SimConnect.dll").is_file())
                    installer = work / "installer" / output.name
                    installer.parent.mkdir()
                    installer.write_bytes(b"complete installer")

            with mock.patch.object(BUILD.subprocess, "run", side_effect=fake_run):
                if fail_at:
                    with self.assertRaises(subprocess.CalledProcessError):
                        BUILD.build_installer("ISCC.exe", source, use_installed_dependencies)
                    self.assertEqual(b"previous successful installer", output.read_bytes())
                else:
                    self.assertEqual(output, BUILD.build_installer("ISCC.exe", source, use_installed_dependencies))
                    self.assertEqual(b"complete installer", output.read_bytes())
            self.assertEqual([], list(source.glob(".build-*")))
            self.assertEqual([output], list(dist.iterdir()))
            if use_installed_dependencies:
                self.assertFalse(any("venv" in command or "pip" in command for command in calls))
                self.assertEqual(BUILD.sys.executable, calls[0][0])
                self.assertEqual(BUILD.installed_dependencies_code(), calls[0][2])

    def test_success_publishes_only_installer_and_cleans_staging(self):
        """Publish only the completed installer and discard all temporary files."""
        self.exercise_build()

    def test_each_tool_failure_cleans_staging_and_preserves_previous_installer(self):
        """Failure at any build step must retain the previous published artifact."""
        for step in range(1, 8):
            with self.subTest(step=step):
                self.exercise_build(fail_at=step)

    def test_existing_dependencies_build_never_installs_packages(self):
        """Reusing a checked interpreter must avoid both pip and venv commands."""
        with mock.patch.dict(BUILD.os.environ, {"PYTHONNOUSERSITE": "1"}):
            self.exercise_build(use_installed_dependencies=True)

    def test_existing_dependency_preflight_failure_stops_before_pyinstaller(self):
        """A dependency refusal cleans staging and preserves an older installer."""
        self.exercise_build(fail_at=1, use_installed_dependencies=True)

    def test_installed_dependency_preflight_rejects_missing_and_old_packages(self):
        """Enforce package bounds without installing packages or requiring Tkinter.

        Linux audit runners need no GUI runtime for these negative cases. Stub
        only the initial Tkinter import; package refusal must happen before Tcl
        creation or imports of the actual installer build dependencies.
        """
        tkinter_stub = types.ModuleType("tkinter")
        for versions in (
            {"PyInstaller": "6.18.0", "Pillow": "12.2.0", "pystray": "0.19.5"},
            {"PyInstaller": "7.0.0", "Pillow": "12.2.0", "pystray": "0.19.5"},
            {"PyInstaller": "6.19.0", "Pillow": "9.0.0", "pystray": "0.19.5"},
            {"PyInstaller": "6.19.0", "Pillow": "12.2.0", "pystray": "0.19.4"},
        ):
            with self.subTest(versions=versions), mock.patch(
                "importlib.metadata.version", side_effect=versions.__getitem__
            ), mock.patch.dict(sys.modules, {"tkinter": tkinter_stub}):
                with self.assertRaises(SystemExit):
                    exec(BUILD.installed_dependencies_code(), {})
        with mock.patch("importlib.metadata.version", side_effect=PackageNotFoundError("pystray")), mock.patch.dict(
            sys.modules, {"tkinter": tkinter_stub}
        ):
            with self.assertRaises(PackageNotFoundError):
                exec(BUILD.installed_dependencies_code(), {})

    def test_missing_explicit_compiler_does_not_silently_use_another(self):
        """Honor an explicit compiler selection by refusing an unavailable path."""
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(RuntimeError, "does not exist"):
                BUILD.find_iscc(str(Path(directory) / "missing.exe"))

    def test_invalid_version_stops_before_running_tools(self):
        """Reject malformed versions before tools or staging can change output."""
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory)
            (source / "VERSION").write_text("0.1.0/invalid", encoding="utf-8")
            with mock.patch.object(BUILD.subprocess, "run") as run:
                with self.assertRaisesRegex(RuntimeError, "VERSION"):
                    BUILD.build_installer("ISCC.exe", source)
                run.assert_not_called()
            self.assertEqual([], list(source.glob(".build-*")))

    def test_bundled_runtime_is_x64_and_has_transport_and_camera_exports(self):
        """The tracked binary must support both aircraft and 2024 camera paths."""
        self.assertEqual(
            hashlib.sha256((BUILD.WINAPP_DIR / "SimConnect.dll").read_bytes()).hexdigest(),
            BUILD.validate_simconnect_runtime(BUILD.WINAPP_DIR / "SimConnect.dll"),
        )

    def test_installer_distributes_the_single_exe_with_embedded_runtime(self):
        """Keep the installer payload to the EXE containing its bundled runtime."""
        script = (BUILD.WINAPP_DIR / "installer.iss").read_text(encoding="utf-8")
        entries = [line.strip() for line in script.splitlines() if line.strip().startswith("Source:")]
        self.assertEqual(['Source: "{#AppExe}"; DestDir: "{app}"; Flags: ignoreversion'], entries)
        self.assertNotIn("SimConnect.dll", script)

    def test_installer_stops_only_target_uploader_before_replacing_files(self):
        """Enforce shutdown for normal/silent updates without name-only killing.

        The PrepareToInstall refusal precedes file writes, covers tray and both
        onefile processes, and keeps unknown process identity a blocking state.
        """
        script = (BUILD.WINAPP_DIR / "installer.iss").read_text(encoding="utf-8")
        for required in (
            "CloseApplications=force", "CloseApplicationsFilter=PHPGalleryUploader.exe",
            "RestartApplications=no", "function PrepareToInstall(var NeedsRestart: Boolean): String;",
            "TargetPath := ExpandFileName(ExpandConstant('{app}\\PHPGalleryUploader.exe'))",
            "CompareText(ExpandFileName(ProcessPath), TargetPath) = 0",
            "VarIsNull(Process.ExecutablePath)", "VarIsEmpty(Process.ExecutablePath)",
            "Process.Terminate(0)", "InspectUploaderProcesses(Services, TargetPath, True)",
            "InspectUploaderProcesses(Services, TargetPath, False) = 0",
            "for Attempt := 0 to 30 do", "Sleep(100)",
            "Result := CustomMessage('UploaderShutdownFailed')",
            "english.UploaderShutdownFailed=", "czech.UploaderShutdownFailed=",
        ):
            self.assertIn(required, script)
        self.assertNotIn("taskkill", script.lower())
        self.assertNotIn("GetExceptionMessage", script)
        self.assertNotIn("WizardSilent", script)

    def test_invalid_runtime_stops_before_build_tools(self):
        """Reject damaged, wrong-architecture, or incomplete DLLs before staging."""
        original = (BUILD.WINAPP_DIR / "SimConnect.dll").read_bytes()
        wrong_architecture = bytearray(original)
        pe = struct.unpack_from("<I", original, 60)[0]
        struct.pack_into("<H", wrong_architecture, pe + 4, 0x14C)
        missing_export = original.replace(b"SimConnect_CameraGet\0", b"SimConnect_CameraBad\0")
        for data in (b"invalid PE", wrong_architecture, missing_export):
            with self.subTest(data_length=len(data)), tempfile.TemporaryDirectory() as directory:
                source = Path(directory)
                (source / "VERSION").write_text("0.1.0", encoding="utf-8")
                (source / "SimConnect.dll").write_bytes(data)
                with mock.patch.object(BUILD.subprocess, "run") as run:
                    with self.assertRaisesRegex(RuntimeError, "Invalid bundled SimConnect"):
                        BUILD.build_installer("ISCC.exe", source)
                    run.assert_not_called()
                self.assertEqual([], list(source.glob(".build-*")))

    def test_archive_verification_checks_the_actual_binary_payload(self):
        """Verify extracted bytes and reject absent entries or substituted DLLs.

        A mock archive reader keeps this a deterministic unit test; both slash
        conventions accepted by real Windows archives use the same digest check.
        """
        payload = b"expected DLL payload"
        digest = hashlib.sha256(payload).hexdigest()
        reader_module = types.ModuleType("PyInstaller.archive.readers")
        reader = mock.Mock()
        reader_module.CArchiveReader = mock.Mock(return_value=reader)
        modules = {
            "PyInstaller": types.ModuleType("PyInstaller"),
            "PyInstaller.archive": types.ModuleType("PyInstaller.archive"),
            "PyInstaller.archive.readers": reader_module,
        }
        for entries, actual, succeeds in (
            ({BUILD.SIMCONNECT_ARCHIVE_PATH: ()}, payload, True),
            ({BUILD.SIMCONNECT_ARCHIVE_PATH.replace("/", "\\"): ()}, payload, True),
            ({}, payload, False),
            ({BUILD.SIMCONNECT_ARCHIVE_PATH: ()}, b"wrong DLL", False),
        ):
            with self.subTest(entries=entries, succeeds=succeeds):
                reader.toc = entries
                reader.extract.return_value = actual
                with mock.patch.dict(sys.modules, modules), mock.patch.object(
                    sys, "argv", ["verify", "app.exe", BUILD.SIMCONNECT_ARCHIVE_PATH, digest]
                ):
                    if succeeds:
                        exec(BUILD.archive_verification_code(), {})
                    else:
                        with self.assertRaises(SystemExit):
                            exec(BUILD.archive_verification_code(), {})


if __name__ == "__main__":
    unittest.main()
