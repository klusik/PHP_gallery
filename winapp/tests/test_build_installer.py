# Project: PHP Gallery
# Module Type: Regression Test
# Purpose: Verify isolated Windows installer build behavior.
# Responsibilities:
#   - Protect cleanup and atomic publication on successful and failed builds.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/tests/test_build_installer.py
#
# Author: Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
"""Verify installer publication and cleanup without downloading build tools."""

import importlib.util
from importlib.metadata import PackageNotFoundError
import hashlib
import json
from pathlib import Path
import shutil
import struct
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

SPEC = importlib.util.spec_from_file_location(
    "winapp_build_installer", Path(__file__).resolve().parents[1] / "build_installer.py"
)
BUILD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILD)


class InstallerBuildTests(unittest.TestCase):
    """A failed tool must never publish a partial installer or retain staging."""

    def test_requirements_keep_core_independent_and_build_complete(self) -> None:
        """Keep Qt out of core while constraining every standard build path.

        Returns:
            None: The shared core, Qt, benchmark and build requirement graphs
                remain separate and resolve through the qualified constraints.
        """
        requirements = {
            name: (WINAPP_DIR / name).read_text(encoding="utf-8")
            for name in (
                "requirements.txt", "requirements-core.txt", "requirements-qt.txt",
                "requirements-benchmark.txt", "requirements-build.txt", "constraints.txt",
            )
        }
        self.assertIn("-r requirements-core.txt", requirements["requirements.txt"])
        self.assertIn("-r requirements-qt.txt", requirements["requirements.txt"])
        self.assertIn("-c constraints.txt", requirements["requirements-core.txt"])
        self.assertIn("-c constraints.txt", requirements["requirements-qt.txt"])
        self.assertNotIn("PySide6", requirements["requirements-core.txt"])
        self.assertIn(
            "--only-binary=PySide6-Essentials,shiboken6",
            requirements["requirements-qt.txt"],
        )
        self.assertIn("-c constraints.txt", requirements["requirements-benchmark.txt"])
        self.assertIn("-r requirements.txt", requirements["requirements-build.txt"])
        self.assertRegex(requirements["requirements-build.txt"], r"(?m)^PyInstaller==6\.22\.3$")

    def exercise_build(self, fail_at: int | None = None, use_installed_dependencies: bool = False,
                       fail_publication_at: int | None = None, previous_artifacts: bool = True) -> list[list[str]]:
        """Simulate tool output while checking staging and atomic publication.

        ``fail_at`` injects a subprocess failure at the numbered tool step.
        The installed-dependencies mode must skip pip installs and venv while
        retaining consistency checks and previous-installer preservation.
        ``fail_publication_at`` refuses either final file replacement; failures
        must restore existing artifacts or leave a fresh build unpublished.

        Args:
            fail_at (int | None): Tool step to refuse, or None for successful tools.
            use_installed_dependencies (bool): Exercise the build without pip or venv.
            fail_publication_at (int | None): Replacement to refuse, or None to allow both.
            previous_artifacts (bool): Seed a previous installer and matching metadata.

        Returns:
            list[list[str]]: Recorded subprocess argument lists for ordering checks.
        """
        with tempfile.TemporaryDirectory(prefix="installer tests ") as directory:
            source = Path(directory) / "winapp"
            source.mkdir()
            (source.parent / "LICENSE").write_text("App license\n", encoding="utf-8")
            (source / "QT-LICENSE.txt").write_text("Qt notice\n", encoding="utf-8")
            (source / "VERSION").write_text("0.1.0\n", encoding="utf-8")
            shutil.copyfile(BUILD.WINAPP_DIR / "SimConnect.dll", source / "SimConnect.dll")
            dist = source / "dist" / "0.1.0"
            dist.mkdir(parents=True)
            output = dist / "PHPGalleryUploader-0.1.0-Setup.exe"
            metadata = dist / "winapp-update.json"
            previous_metadata = json.dumps({"assets": [{
                "name": output.name, "version": "0.1.0",
                "size": len(b"previous successful installer"),
                "sha256": hashlib.sha256(b"previous successful installer").hexdigest(),
            }]})
            if previous_artifacts:
                output.write_bytes(b"previous successful installer")
                metadata.write_text(previous_metadata, encoding="utf-8")
            unrelated = dist / "notes.txt"
            unrelated.write_bytes(b"unrelated release notes")
            legacy = dist.parent / output.name
            legacy.write_bytes(b"historical dist-root installer")
            older = dist.parent / "0.0.9"
            older.mkdir()
            (older / "winapp-update.json").write_bytes(b"older metadata")
            (older / "PHPGalleryUploader-0.0.9-Setup.exe").write_bytes(b"older installer")
            calls = []

            def fake_run(
                command: list[str],
                **kwargs: Path | dict[str, str] | bool | int,
            ) -> None:
                """Emulate build artifacts and validate each subprocess contract.

                No build tools run and no packages are installed. Create only
                the EXE/installer files the orchestrator expects in its staging
                tree, or raise the explicitly requested step failure.

                Args:
                    command (list[str]): Ordered executable and arguments.
                    kwargs (Path | dict[str, str] | bool | int): cwd Path,
                        env dict[str, str], check bool and optional timeout
                        int; passed to subprocess.run.

                Returns:
                    None: Creates only simulated staged build output.
                """
                calls.append(command)
                work = Path(kwargs["cwd"])
                # Windows may spell the same Temp directory as an 8.3 alias.
                # Compare real filesystem identity, not path presentation.
                self.assertTrue(source.samefile(work.parent))
                self.assertEqual(str(work / "temp"), kwargs["env"]["TEMP"])
                self.assertNotIn("PYTHONPATH", kwargs["env"])
                if use_installed_dependencies:
                    self.assertNotIn("PYTHONNOUSERSITE", kwargs["env"])
                else:
                    self.assertEqual("1", kwargs["env"]["PYTHONNOUSERSITE"])
                self.assertTrue(kwargs["check"])
                if len(calls) == fail_at:
                    raise subprocess.CalledProcessError(1, command)
                if Path(command[1]).name == "qt_installer_tools.py":
                    Path(command[-1]).mkdir()
                if "PyInstaller" in command:
                    self.assertEqual("uploader.spec", Path(command[-1]).name)
                    self.assertEqual(str(work / "version-info.txt"), kwargs["env"]["PHP_GALLERY_VERSION_RESOURCE"])
                    spec = (BUILD.WINAPP_DIR / "uploader.spec").read_text(encoding="utf-8")
                    for required in ('"tkinter"', '"uploader.qt_application"', 'collect_all("reportlab")',
                                     '"runtime/simconnect"', 'exclude_binaries=True', 'bundle = COLLECT('):
                        self.assertIn(required, spec)
                    bundle = work / "app" / Path(BUILD.APP_EXE).stem
                    app = bundle / BUILD.APP_EXE
                    runtime = bundle / "_internal"
                    qml = runtime / "uploader" / "resources" / "qml" / "Photos.qml"
                    qml.parent.mkdir(parents=True)
                    qml.write_text("fixture scene", encoding="utf-8")
                    for library in (
                        f"python{sys.version_info.major}{sys.version_info.minor}.dll",
                        "qwindows.dll", "qjpeg.dll", "qwebp.dll", "Qt6Core.dll", "Qt6Quick.dll",
                    ):
                        (runtime / library).write_bytes(b"fixture library")
                    app.write_bytes(b"standalone app")
                    simconnect = runtime / BUILD.SIMCONNECT_BUNDLE_PATH
                    simconnect.parent.mkdir(parents=True)
                    shutil.copyfile(source / "SimConnect.dll", simconnect)
                if "--runtime-info" in command:
                    Path(command[-1]).write_text(json.dumps({
                        "python_version": "3.14.4",
                        "implementation": "CPython",
                        "pointer_bits": 64,
                    }), encoding="utf-8")
                if "--self-update-smoke" in command:
                    self.assertEqual(work / "helper-smoke" / "helper.exe", Path(command[0]))
                    self.assertEqual(b"standalone app", Path(command[0]).read_bytes())
                    runtime = Path(command[0]).parent / "_internal"
                    self.assertTrue((runtime / "Qt6Core.dll").is_file())
                    self.assertEqual("1", kwargs["env"]["PYINSTALLER_RESET_ENVIRONMENT"])
                    self.assertEqual(60, kwargs["timeout"])
                    self.assertEqual(str(work / "smoke-profile"), kwargs["env"]["APPDATA"])
                    self.assertEqual(str(work / "smoke-local-profile"), kwargs["env"]["LOCALAPPDATA"])
                    self.assertFalse(any(call[0] == "ISCC.exe" for call in calls))
                if command[0] == "ISCC.exe":
                    self.assertTrue(any("--self-update-smoke" in call for call in calls))
                    self.assertEqual(1, sum("PyInstaller" in call for call in calls))
                    self.assertIn("/DAppVersion=0.1.0", command)
                    source_roots = [
                        Path(argument[len("/DSourceRoot="):])
                        for argument in command if argument.startswith("/DSourceRoot=")
                    ]
                    self.assertEqual(1, len(source_roots))
                    # The 8.3 and long-name spellings must resolve to the
                    # same real directory, not merely have identical text.
                    self.assertTrue(source_roots[0].samefile(source))
                    bundle = next(Path(argument[len("/DAppBundle="):])
                                  for argument in command if argument.startswith("/DAppBundle="))
                    self.assertTrue((bundle / BUILD.APP_EXE).is_file())
                    self.assertTrue((bundle / "_internal" / "Qt6Core.dll").is_file())
                    self.assertTrue((source / "SimConnect.dll").is_file())
                    license_page = next(Path(argument[len("/DInstallerLicense="):])
                                        for argument in command if argument.startswith("/DInstallerLicense="))
                    self.assertEqual("App license\n\nQt notice\n", license_page.read_text(encoding="utf-8-sig"))
                    installer = work / "installer" / output.name
                    installer.parent.mkdir()
                    installer.write_bytes(b"complete installer")

            def fake_popen(command: list[str], *, cwd: Path, env: dict[str, str]) -> mock.MagicMock:
                """Record a frozen smoke without starting a real executable.

                Args:
                    command (list[str]): Staged EXE and smoke mode arguments.
                    cwd (Path): Disposable build working directory.
                    env (dict[str, str]): Isolated smoke environment.
                Returns:
                    mock.MagicMock: Completed subprocess context with zero wait status.
                """
                fake_run(command, cwd=cwd, env=env, check=True,
                         timeout=BUILD.FROZEN_RUNTIME_TIMEOUT_SECONDS)
                process = mock.MagicMock()
                process.__enter__.return_value = process
                process.wait.return_value = 0
                return process

            replace = BUILD.os.replace
            publication_calls = []

            def fake_replace(staged: Path, target: Path) -> None:
                """Refuse one publication step without changing the old file.

                Args:
                    staged (Path): Completed staged artifact or rollback copy.
                    target (Path): Final artifact destination.

                Returns:
                    None: Replace the file or raise the injected OSError.
                """
                publication_calls.append(target)
                if len(publication_calls) == fail_publication_at:
                    raise OSError("simulated publication failure")
                replace(staged, target)

            with (
                mock.patch.object(BUILD.subprocess, "run", side_effect=fake_run),
                mock.patch.object(BUILD.subprocess, "Popen", side_effect=fake_popen),
                mock.patch.object(BUILD.os, "replace", side_effect=fake_replace),
                mock.patch.object(BUILD, "runtime_info", return_value={
                    "python_version": "3.14.4",
                    "implementation": "CPython",
                    "pointer_bits": 64,
                }),
            ):
                if fail_at:
                    with self.assertRaises(subprocess.CalledProcessError):
                        BUILD.build_installer("ISCC.exe", source, use_installed_dependencies)
                elif fail_publication_at:
                    with self.assertRaisesRegex(OSError, "publication failure"):
                        BUILD.build_installer("ISCC.exe", source, use_installed_dependencies)
                else:
                    self.assertTrue(output.samefile(
                        BUILD.build_installer("ISCC.exe", source, use_installed_dependencies)
                    ))
                    self.assertEqual(b"complete installer", output.read_bytes())
                    self.assertEqual({"assets": [{
                        "name": output.name, "version": "0.1.0",
                        "size": output.stat().st_size,
                        "sha256": hashlib.sha256(output.read_bytes()).hexdigest(),
                    }]}, json.loads(metadata.read_text(encoding="utf-8")))
            if fail_at or fail_publication_at:
                if previous_artifacts:
                    self.assertEqual(b"previous successful installer", output.read_bytes())
                    self.assertEqual(previous_metadata, metadata.read_text(encoding="utf-8"))
                else:
                    self.assertFalse(output.exists())
                    self.assertFalse(metadata.exists())
            self.assertEqual([], list(source.glob(".build-*")))
            expected = {unrelated}
            if previous_artifacts or not (fail_at or fail_publication_at):
                expected.update({output, metadata})
            self.assertEqual(expected, set(dist.iterdir()))
            self.assertEqual(b"unrelated release notes", unrelated.read_bytes())
            self.assertEqual(b"historical dist-root installer", legacy.read_bytes())
            self.assertEqual(b"older metadata", (older / "winapp-update.json").read_bytes())
            self.assertEqual(b"older installer", (older / "PHPGalleryUploader-0.0.9-Setup.exe").read_bytes())
            if use_installed_dependencies:
                self.assertFalse(any("venv" in command or "install" in command for command in calls))
                self.assertEqual([BUILD.sys.executable, "-m", "pip", "check"], calls[0])
                if len(calls) > 1:
                    self.assertEqual(BUILD.sys.executable, calls[1][0])
                    self.assertEqual(BUILD.installed_dependencies_code(), calls[1][2])
            return calls

    def test_success_publishes_installer_beside_matching_metadata(self) -> None:
        """Publish both files in their version folder and discard build staging.

        Returns:
            None: Assert replacement, matching metadata and cleanup.
        """
        self.exercise_build()

    def test_first_build_publishes_installer_beside_matching_metadata(self) -> None:
        """Create a matching release pair without requiring previous artifacts.

        Returns:
            None: Assert first-publication metadata and installer placement.
        """
        self.exercise_build(previous_artifacts=False)

    def test_publication_failure_preserves_previous_release_pair(self) -> None:
        """A refused EXE or JSON replacement must preserve both previous files.

        Returns:
            None: Assert byte-identical rollback after either replacement fails.
        """
        for step in (1, 2):
            with self.subTest(step=step):
                self.exercise_build(fail_publication_at=step)

    def test_publication_failure_leaves_no_partial_first_release(self) -> None:
        """A refused publication must remove an EXE with no matching JSON.

        Returns:
            None: Assert failed first publication leaves both artifacts absent.
        """
        for step in (1, 2):
            with self.subTest(step=step):
                self.exercise_build(fail_publication_at=step, previous_artifacts=False)

    def test_each_tool_failure_cleans_staging_and_preserves_previous_installer(self) -> None:
        """Failure at any build step must retain the previous published artifact.

        Returns:
            None: Checks all ordered stages, including frozen attestation.
        """
        complete_build = self.exercise_build()
        for step in range(1, len(complete_build) + 1):
            with self.subTest(step=step):
                self.exercise_build(fail_at=step)

    def test_frozen_helper_smoke_failure_prevents_inno_packaging(self) -> None:
        """A failed real-helper check must preserve the previous installer.

        Returns:
            None: Checks the staged helper after frozen-runtime attestation.
        """
        complete_build = self.exercise_build()
        helper_stage = next(index for index, command in enumerate(complete_build, start=1)
                            if "--self-update-smoke" in command)
        calls = self.exercise_build(fail_at=helper_stage)
        self.assertEqual("--self-update-smoke", calls[-1][1])
        self.assertFalse(any(call[0] == "ISCC.exe" for call in calls))

    def test_existing_dependencies_build_never_installs_packages(self):
        """Reuse a checked interpreter without creating a venv or installing."""
        with mock.patch.dict(BUILD.os.environ, {"PYTHONNOUSERSITE": "1"}):
            self.exercise_build(use_installed_dependencies=True)

    def test_existing_dependency_preflight_failure_stops_before_pyinstaller(self) -> None:
        """Preserve the older setup after either installed dependency check fails.

        Returns:
            None: A pip consistency error or dependency probe error stops freezing.
        """
        preflight_steps = len([
            command for command in self.exercise_build(use_installed_dependencies=True)
            if command[1:3] in (['-m', 'pip'], ['-c', BUILD.installed_dependencies_code()])
        ])
        for step in range(1, preflight_steps + 1):
            with self.subTest(step=step):
                calls = self.exercise_build(fail_at=step, use_installed_dependencies=True)
                self.assertFalse(any('PyInstaller' in command for command in calls))

    def test_installed_dependency_preflight_rejects_missing_and_old_packages(self) -> None:
        """Enforce exact package pins without installing or requiring a GUI.

        Linux audit runners need no GUI runtime for these negative cases. Stub
        the Tk modules so refusals happen before Tcl creation or imports of the
        actual installer build dependencies.

        Returns:
            None: Asserts unsupported versions and missing packages are refused.
        """
        tkinter_stub = types.ModuleType("tkinter")
        tkinter_stub.__path__ = []
        tkinter_ttk_stub = types.ModuleType("tkinter.ttk")
        tkinter_stub.ttk = tkinter_ttk_stub
        supported = {
            "PyInstaller": "6.22.3",
            "Pillow": "12.0.0",
            "pystray": "0.19.5",
            "reportlab": "5.0.1",
            "pypdf": "6.4.0",
            "polib": "1.2.0",
            "PySide6-Essentials": "6.10.3",
            "shiboken6": "6.10.3",
            "pyinstaller-hooks-contrib": "2026.10",
            "pefile": "2024.8.26",
            "pywin32-ctypes": "0.2.3",
            "altgraph": "0.17.5",
            "packaging": "26.4",
            "charset-normalizer": "3.5.3",
            "six": "1.17.0",
            "setuptools": "84.2.1",
        }
        for versions in (
            {**supported, "PyInstaller": "6.19.0"},
            {**supported, "PyInstaller": "7.0.0"},
            {**supported, "Pillow": "9.0.0"},
            {**supported, "pystray": "0.19.4"},
            {**supported, "reportlab": "5.0.0"},
            {**supported, "reportlab": "6.0.0"},
        ):
            with self.subTest(versions=versions), mock.patch(
                "importlib.metadata.version",
                side_effect=versions.__getitem__,
            ), mock.patch.dict(sys.modules, {
                "tkinter": tkinter_stub, "tkinter.ttk": tkinter_ttk_stub,
            }):
                with self.assertRaises(SystemExit):
                    exec(BUILD.installed_dependencies_code(), {})
        for missing_package in (
            "Pillow", "pystray", "reportlab", "pypdf", "polib",
            "PySide6-Essentials", "shiboken6", "pyinstaller-hooks-contrib",
            "pefile", "pywin32-ctypes", "altgraph", "packaging",
            "charset-normalizer", "six", "setuptools",
        ):
            versions = supported

            def package_version(package_name: str) -> str:
                """Return a fixture package version or the requested missing error.

                Args:
                    package_name (str): Package requested by the preflight.

                Returns:
                    str: Supported fixture version when installed.
                """
                if package_name == missing_package:
                    raise PackageNotFoundError(missing_package)
                return versions[package_name]

            with self.subTest(missing_package=missing_package), mock.patch(
                "importlib.metadata.version", side_effect=package_version
            ), mock.patch.dict(sys.modules, {
                "tkinter": tkinter_stub, "tkinter.ttk": tkinter_ttk_stub,
            }):
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

    def test_installer_distributes_the_single_exe_with_embedded_runtime(self) -> None:
        """Install one common runtime tree and the required LGPL notices.

        Returns:
            None: The standard canonical installer copies one complete application layout.
        """
        script = (BUILD.WINAPP_DIR / "installer.iss").read_text(encoding="utf-8")
        files = script.split('[Files]\n', 1)[1].split('[Icons]', 1)[0]
        self.assertEqual([
            'Source: "{#AppBundle}\\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs',
            'Source: "{#QtNotice}"; DestDir: "{app}\\licenses"; Flags: ignoreversion',
        ], files.strip().splitlines())
        self.assertEqual(1, script.count("OutputBaseFilename="))
        self.assertIn("OutputBaseFilename=PHPGalleryUploader-{#AppVersion}-Setup", script)
        self.assertNotIn("SimConnect.dll", script)

    def test_installer_stops_only_target_uploader_before_replacing_files(self) -> None:
        """Enforce shutdown for normal/silent updates without name-only killing.

        Returns:
            None: File writes require exact runtime quiescence and an unknown
                process identity continues to block installation.
        """
        script = (BUILD.WINAPP_DIR / "installer.iss").read_text(encoding="utf-8")
        for required in (
            "CloseApplications=no", "CloseApplicationsFilter=PHPGalleryUploader.exe",
            r"SetupMutex=PHPGalleryUploaderSetup-E7CEB8DB,Global\PHPGalleryUploaderSetup-E7CEB8DB",
            "RestartApplications=no", "function PrepareToInstall(var NeedsRestart: Boolean): String;",
            "TargetPath := ExpandFileName(ExpandConstant('{app}\\PHPGalleryUploader.exe'))",
            "ProcessPath := ExpandFileName(ProcessPath)",
            "SameWinAppPath(ProcessPath, TargetPath)",
            "SameWinAppPath(ProcessPath, LegacyQtPath)",
            "SamePathSpelling(GetShortName(Left), GetShortName(Right))",
            "CompareStringOrdinal(Left, -1, Right, -1, 1) = 2",
            "not RuntimePathIsLocal(RegisteredRoot)",
            "VarIsNull(Process.ExecutablePath)", "VarIsEmpty(Process.ExecutablePath)",
            "InspectUploaderProcesses(Services, TargetPath, LegacyQtPath) = 0",
            "for Attempt := 0 to 30 do", "Sleep(100)",
            "Result := CustomMessage('UploaderShutdownFailed')",
            "english.UploaderShutdownFailed=", "czech.UploaderShutdownFailed=",
        ):
            self.assertTrue(required in script, f"Missing installer safeguard {required}")
        self.assertNotIn("taskkill", script.lower())
        self.assertNotIn("Process.Terminate", script)
        self.assertNotIn("GetExceptionMessage", script)
        self.assertNotIn("WizardSilent", script)

    def test_self_update_refuses_running_uploader_and_uses_scoped_progress_pipe(self) -> None:
        """Preserve graceful self-update and scoped installer progress.

        Returns:
            None: Both common-runtime paths verify graceful shutdown before writes.
        """
        script = (BUILD.WINAPP_DIR / "installer.iss").read_text(encoding="utf-8")
        self.assertIn("if InspectUploaderProcesses(Services, TargetPath, LegacyQtPath) = 0 then", script)
        self.assertNotIn("TerminateMatches", script)
        self.assertIn("'{param:SELFUPDATE|0}'", script)
        self.assertIn("'{param:UPDATESTATUS|}'", script)
        self.assertIn("PHPGalleryUploaderUpdate-", script)
        self.assertIn("Length(SessionName) <> 32", script)
        self.assertIn("procedure CurInstallProgressChanged", script)
        self.assertNotIn("SaveStringToFile", script)

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

    def test_archive_verification_checks_the_actual_binary_payload(self) -> None:
        """Reject an absent, duplicated or substituted app-local SimConnect DLL.

        Returns:
            None: Only the exact expected bytes in the canonical slot pass.
        """
        payload = b"expected DLL payload"
        digest = hashlib.sha256(payload).hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            bundle = Path(directory)
            path = bundle / "_internal" / BUILD.SIMCONNECT_BUNDLE_PATH
            path.parent.mkdir(parents=True)
            with self.assertRaisesRegex(RuntimeError, "missing"):
                BUILD.verify_frozen_simconnect(bundle, digest)
            path.write_bytes(b"wrong DLL")
            with self.assertRaisesRegex(RuntimeError, "hash mismatch"):
                BUILD.verify_frozen_simconnect(bundle, digest)
            path.write_bytes(payload)
            BUILD.verify_frozen_simconnect(bundle, digest)
            (bundle / path.name).write_bytes(payload)
            with self.assertRaisesRegex(RuntimeError, "duplicated"):
                BUILD.verify_frozen_simconnect(bundle, digest)


if __name__ == "__main__":
    unittest.main()
