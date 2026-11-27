# Project: PHP Gallery
# Module Type: Regression Test
# Purpose: Verify isolated update helper process and installer boundaries.
# Responsibilities: Exercise ticket safety, process observation and installer results.
# File: winapp/tests/test_update_helper.py
# Repository: https://github.com/klusik/PHP_gallery
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Verify update control without installing software or prompting for UAC."""
from dataclasses import replace
from dataclasses import asdict
import hashlib
import json
import os
from pathlib import Path
import sys
import tempfile
import time
import types
import unittest
from collections.abc import Iterator
from typing import BinaryIO, TextIO
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from uploader import update_helper as helper

# Purpose: Make the source mutation visible to nanosecond file metadata checks.
# Type: int. Units: nanoseconds. Scope: one staging race regression fixture.
# Consumers: TestUpdateHelper._open_with_source_mutation. Rationale: advance the
#   timestamp by one full second so filesystem timestamp granularity cannot hide it.
SOURCE_MTIME_ADVANCE_NS = 1_000_000_000
# Purpose: Exercise a Windows launch refusal distinct from cancelling UAC.
# Type: int. Units: Win32 error code. Scope: ShellExecuteExW failure fixture.
# Consumers: UpdateHelperTests. Rationale: ERROR_ACCESS_DENIED (5) models a
# refused launch and follows the original-runtime restart path.
ERROR_ACCESS_DENIED = 5


class FakeBackend:
    """Record privilege-boundary actions without any Windows API calls."""
    def __init__(self, running: bool = False, code: int = 0, version: str = "1.2.3",
                 cancelled: bool = False) -> None:
        """Configure deterministic running-process and installer results.

        Args:
            running (bool): Whether the exact target is currently active.
            code (int): Synthetic setup exit code.
            version (str): Version reported for the installed executable.
            cancelled (bool): Whether setup should simulate UAC cancellation.

        Returns:
            None: Stores the fixture outcomes for later helper calls.
        """
        self.running = running
        self.code = code
        self.installed_version = version
        self.cancelled = cancelled
        self.installs = 0
        self.restarts: list[tuple[str, str]] = []

    def target_running(self, executable: str) -> bool:
        """Return a controlled exact-target shutdown observation."""
        return self.running

    def install(self, ticket: helper.UpdateTicket, progress: helper.Progress) -> int:
        """Report controlled UAC cancellation or installer completion."""
        self.installs += 1
        progress((2, 50))
        if self.cancelled:
            raise InterruptedError("Administrator permission was cancelled")
        return self.code

    def version(self, executable: str) -> str:
        """Return the simulated installed executable's resource version."""
        return self.installed_version

    def restart(self, executable: str, *, update_session: str) -> None:
        """Record nonce-bound original-user restarts for later assertions.

        Args:
            executable (str): Verified installed executable path.
            update_session (str): Non-secret update continuation nonce.

        Returns:
            None: Adds the exact restart request to the fixture history.
        """
        self.restarts.append((executable, update_session))


class UpdateHelperTests(unittest.TestCase):
    """Protect identity, handshake, progress, cancellation and failure behavior."""
    def setUp(self) -> None:
        """Create entirely temporary session and installed application files."""
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.env = mock.patch.dict(helper.os.environ, {"LOCALAPPDATA": str(self.root)})
        self.env.start()
        self.addCleanup(self.env.stop)
        self.session = helper.updates_root() / ("a" * 32)
        self.session.mkdir(parents=True)
        self.setup = self.session / "setup.exe"
        self.setup.write_bytes(b"verified setup")
        self.installed = self.root / "installed" / "PHPGalleryUploader.exe"
        self.installed.parent.mkdir()
        self.installed.write_bytes(b"old uploader")
        self.ticket = helper.UpdateTicket("a" * 32, "1.2.3", hashlib.sha256(self.setup.read_bytes()).hexdigest(),
                                          self.setup.stat().st_size, str(self.setup), str(self.installed))

    def _make_onedir_runtime(self) -> tuple[Path, dict[str, bytes]]:
        """Create a small canonical launcher runtime for staging checks.

        Returns:
            tuple[Path, dict[str, bytes]]: Launcher path and all source file bytes.
        """
        runtime = self.root / "canonical-runtime"
        internal = runtime / helper.PYINSTALLER_INTERNAL_DIRECTORY
        nested = internal / "uploader" / "resources"
        nested.mkdir(parents=True)
        executable = runtime / "PHPGalleryUploader.exe"
        executable.write_bytes(b"canonical onedir launcher")
        (internal / "python314.dll").write_bytes(b"python runtime")
        (nested / "catalog.json").write_bytes(b'{"ready":true}')
        notices = runtime / "licenses"
        notices.mkdir()
        (notices / "LGPL-3.0-only.txt").write_text("license notice", encoding="utf-8")
        content = {
            str(path.relative_to(runtime)): path.read_bytes()
            for path in runtime.rglob("*") if path.is_file()
        }
        return executable, content

    def test_stage_update_helper_runtime_copies_complete_onedir_tree(self) -> None:
        """Copy the executable, _internal and licenses without linking source files.

        Returns:
            None: The helper session is byte-identical and independently owned.
        """
        executable, content = self._make_onedir_runtime()
        helper_exe = self.session / "helper.exe"
        setup_before = self.setup.read_bytes()
        metrics = helper.stage_update_helper_runtime(executable, helper_exe)
        staged_root = self.session
        staged = {
            str(path.relative_to(staged_root)): path.read_bytes()
            for path in staged_root.rglob("*") if path.is_file()
        }
        expected = dict(content)
        expected["helper.exe"] = expected.pop("PHPGalleryUploader.exe")
        expected["setup.exe"] = setup_before
        self.assertEqual(expected, staged)
        self.assertEqual(setup_before, (self.session / "setup.exe").read_bytes())
        self.assertEqual(sum(map(len, content.values())), metrics["runtime_bytes"])
        self.assertGreaterEqual(metrics["runtime_entries"], len(content))
        self.assertGreaterEqual(metrics["duration_ms"], 0)
        for relative in content:
            source = executable.parent / relative
            copied = (
                helper_exe if relative == "PHPGalleryUploader.exe"
                else staged_root / relative
            )
            self.assertFalse(os.path.samestat(source.stat(), copied.stat()))

    def _open_with_source_mutation(
        self, path: Path, mode: str = "r",
        *args: int | str | None, **kwargs: int | str | None,
    ) -> BinaryIO | TextIO:
        """Change one source file between staging inventory and byte copy.

        Args:
            path (Path): File requested by staging or the source mutation.
            mode (str): Requested open mode.
            *args (int | str | None): Positional buffering and text-mode options.
            **kwargs (int | str | None): Named buffering and text-mode options.
        Returns:
            BinaryIO | TextIO: Opened file returned by Path.open.
        """
        original_open = self._original_path_open
        if path == self._mutated_runtime_file and mode == "rb":
            with original_open(path, "wb") as destination:
                destination.write(b"PYTHON RUNTIME")
            modified = path.stat()
            os.utime(
                path, ns=(
                    modified.st_atime_ns,
                    modified.st_mtime_ns + SOURCE_MTIME_ADVANCE_NS,
                ),
            )
        return original_open(path, mode, *args, **kwargs)

    def test_stage_update_helper_runtime_refuses_inaccessible_source_tree(self) -> None:
        """An unreadable source directory removes all earlier staged entries.

        Returns:
            None: Permission failure is surfaced and helper runtime is cleaned.
        """
        executable, _ = self._make_onedir_runtime()
        internal = executable.parent / helper.PYINSTALLER_INTERNAL_DIRECTORY
        original_iterdir = Path.iterdir

        def fail_source_listing(path: Path) -> Iterator[Path]:
            """Raise permission failure only for the owned source directory.

            Args:
                path (Path): Source or staged directory requested by the caller.
            Returns:
                Iterator[Path]: Entries from a readable directory.
            Raises:
                PermissionError: The synthetic source runtime is inaccessible.
            """
            if path == internal:
                raise PermissionError("synthetic inaccessible runtime")
            return original_iterdir(path)

        with mock.patch.object(Path, "iterdir", fail_source_listing):
            with self.assertRaisesRegex(PermissionError, "inaccessible"):
                helper.stage_update_helper_runtime(
                    executable, self.session / "helper.exe",
                )
        self.assertFalse((self.session / "helper.exe").exists())
        self.assertFalse((self.session / helper.PYINSTALLER_INTERNAL_DIRECTORY).exists())

    def test_stage_update_helper_runtime_refuses_runtime_changed_mid_copy(self) -> None:
        """A changed runtime after inventory is rejected and partial bytes removed.

        Returns:
            None: A source mutation cannot produce an executable staged helper.
        """
        executable, _ = self._make_onedir_runtime()
        self._original_path_open = Path.open
        self._mutated_runtime_file = (
            executable.parent / helper.PYINSTALLER_INTERNAL_DIRECTORY
            / "python314.dll"
        )
        with mock.patch.object(
            Path, "open", autospec=True,
            side_effect=self._open_with_source_mutation,
        ):
            with self.assertRaisesRegex(ValueError, "changed"):
                helper.stage_update_helper_runtime(
                    executable, self.session / "helper.exe",
                )
        self.assertFalse((self.session / "helper.exe").exists())
        self.assertFalse((self.session / helper.PYINSTALLER_INTERNAL_DIRECTORY).exists())

    def test_stage_update_helper_runtime_requires_explicit_legacy_allowance(self) -> None:
        """Only the historical onefile fixture may omit _internal.

        Returns:
            None: Default staging fails closed; explicit legacy staging copies only EXE.
        """
        runtime = self.root / "historical-onefile"
        runtime.mkdir()
        executable = runtime / "PHPGalleryUploader.exe"
        executable.write_bytes(b"historical onefile")
        helper_exe = self.session / "helper.exe"
        with self.assertRaisesRegex(RuntimeError, "missing _internal"):
            helper.stage_update_helper_runtime(executable, helper_exe)
        helper.stage_update_helper_runtime(
            executable, helper_exe, allow_legacy_onefile=True,
        )
        self.assertEqual(executable.read_bytes(), helper_exe.read_bytes())
        self.assertFalse((self.session / helper.PYINSTALLER_INTERNAL_DIRECTORY).exists())

    def test_stage_update_helper_runtime_cleans_partial_copy_when_bound_exceeded(self) -> None:
        """Bound the complete copy and remove every partially staged runtime entry.

        Returns:
            None: Oversized runtime raises and leaves the owned session clean.
        """
        executable, _ = self._make_onedir_runtime()
        with mock.patch.object(helper, "UPDATE_RUNTIME_PROOF_MAX_BYTES", 16):
            with self.assertRaisesRegex(OSError, "byte limit"):
                helper.stage_update_helper_runtime(executable, self.session / "helper.exe")
        self.assertFalse((self.session / "helper.exe").exists())
        self.assertFalse((self.session / helper.PYINSTALLER_INTERNAL_DIRECTORY).exists())
        self.assertFalse((self.session / "licenses").exists())

    def test_stage_update_helper_runtime_rejects_redirected_and_hardlinked_files(self) -> None:
        """Runtime staging refuses links before a helper can execute them.

        Returns:
            None: Reparse directories and multiply linked files are rejected.
        """
        executable, _ = self._make_onedir_runtime()
        internal = executable.parent / helper.PYINSTALLER_INTERNAL_DIRECTORY
        ordinary_file = internal / "python314.dll"
        external = self.root / "external-runtime.dll"
        external.write_bytes(b"outside runtime")
        original = internal / "redirected.dll"
        try:
            original.symlink_to(external)
        except (OSError, NotImplementedError):
            self.skipTest("The current account cannot create a source runtime symlink")
        with self.assertRaisesRegex(ValueError, "reparse|redirected"):
            helper.stage_update_helper_runtime(executable, self.session / "helper.exe")
        self.assertFalse((self.session / "helper.exe").exists())
        original.unlink()
        linked = internal / "hardlinked.dll"
        try:
            os.link(ordinary_file, linked)
        except OSError:
            self.skipTest("The current volume cannot create a runtime hardlink")
        with self.assertRaisesRegex(ValueError, "linked or special"):
            helper.stage_update_helper_runtime(executable, self.session / "helper.exe")
        self.assertFalse((self.session / "helper.exe").exists())

    def test_ticket_rejects_external_paths_and_invalid_identity(self) -> None:
        """Web-derived path injection and wrong field types never become setup."""
        helper.validate_ticket(self.ticket, self.session / "ticket.json")
        for ticket in (replace(self.ticket, installer_path=str(self.installed)),
                       replace(self.ticket, expected_size=True),
                       replace(self.ticket, session_id="../escape"),
                       replace(self.ticket, target_version="v1.2.3"),
                       replace(self.ticket, expected_sha256="garbage")):
            with self.subTest(ticket=ticket), self.assertRaises(ValueError):
                helper.validate_ticket(ticket, self.session / "ticket.json")

    def test_native_libraries_avoid_frozen_version_data_collision(self) -> None:
        """Exercise native loads under PyInstaller's case-insensitive VERSION redirection.

        Returns:
            None: System libraries resolve securely and setup refusal keeps its exact category.
        """
        extraction = self.root / "_MEI"
        extraction.mkdir()
        version_data = extraction / "VERSION"
        version_data.write_text("0.3.2", encoding="utf-8")
        kernel, shell, version = mock.MagicMock(), mock.MagicMock(), mock.MagicMock()
        shell.ShellExecuteExW.return_value = False
        version.GetFileVersionInfoSizeW.return_value = 0
        libraries = {"kernel32.dll": kernel, "shell32.dll": shell, "version.dll": version}

        def frozen_name(name: str) -> str:
            """Mirror the installed hook with Windows filename comparison on every platform."""
            if not Path(name).is_file():
                for candidate in extraction.iterdir():
                    if candidate.name.casefold() == Path(name).name.casefold() and candidate.is_file():
                        return str(candidate)
            return name

        def load_library(name: str, *, use_last_error: bool, winmode: int) -> mock.MagicMock:
            """Reject bundled data and unrestricted searches before returning a fake native DLL."""
            self.assertTrue(use_last_error)
            self.assertEqual(0x00000800, winmode)
            return libraries[frozen_name(name)]

        self.assertEqual(str(version_data), frozen_name("version"))
        with mock.patch.object(helper.ctypes, "WinDLL", side_effect=load_library, create=True) as loader, \
                mock.patch.object(
                    helper.ctypes, "get_last_error",
                    side_effect=[helper.ERROR_CANCELLED, ERROR_ACCESS_DENIED], create=True,
                ):
            backend = helper.WindowsBackend()
            with mock.patch.object(backend, "create_status_pipe", return_value=None), \
                    self.assertRaises(InterruptedError):
                backend.install(self.ticket, mock.Mock())
            with mock.patch.object(backend, "create_status_pipe", return_value=None), \
                    self.assertRaises(helper.SetupLaunchError):
                backend.install(self.ticket, mock.Mock())
            with self.assertRaisesRegex(OSError, "version unavailable"):
                backend.version(str(self.installed))
        for invocation in shell.ShellExecuteExW.call_args_list:
            shell_info = invocation.args[0]._obj
            self.assertEqual('runas', shell_info.lpVerb)
            self.assertEqual(
                helper.SEE_MASK_NOCLOSEPROCESS | helper.SEE_MASK_FLAG_NO_UI,
                shell_info.fMask,
            )
        self.assertEqual([mock.call(name, use_last_error=True, winmode=0x00000800)
                          for name in ("kernel32.dll", "shell32.dll", "shell32.dll", "version.dll")],
                         loader.call_args_list)

    def test_broken_symlink_is_rejected_before_existence_check(self) -> None:
        """A dangling link remains a forbidden path even without a target."""
        with mock.patch.object(Path, "is_symlink", return_value=True), \
                mock.patch.object(Path, "exists", return_value=False) as exists:
            with self.assertRaisesRegex(ValueError, "reparse points"):
                helper.reject_reparse_path(self.root / "broken-link")
        exists.assert_not_called()

    def _write_legacy_session(self, session_id: str) -> Path:
        """Create the exact seven-field ticket written by the old helper.

        Args:
            session_id (str): Canonical fixture session directory identity.

        Returns:
            Path: Old-format session directory with a verified setup file.
        """
        session = helper.updates_root() / session_id
        session.mkdir(parents=True, exist_ok=True)
        setup = session / "setup.exe"
        setup.write_bytes(b"verified legacy setup")
        raw = {
            "session_id": session_id,
            "target_version": "1.2.3",
            "expected_sha256": hashlib.sha256(setup.read_bytes()).hexdigest(),
            "expected_size": setup.stat().st_size,
            "installer_path": str(setup.resolve()),
            "installed_executable": str(self.installed.resolve()),
            "schema": 1,
        }
        (session / "ticket.json").write_text(json.dumps(raw), encoding="utf-8")
        (session / "ready").write_text("1", encoding="ascii")
        (session / "proceed").write_text("1", encoding="ascii")
        return session

    def test_legacy_update_requires_one_fresh_verified_handoff(self) -> None:
        """Admit one old helper only after version and marker evidence agree.

        Returns:
            None: Exact legacy ticket shape is accepted; marker-only,
                tampered, stale, ambiguous and source-mode candidates fail closed.
        """
        first = self._write_legacy_session("a" * 32)
        with mock.patch.object(helper, "installed_identity", return_value=self.installed.resolve()), \
                mock.patch.object(helper.WindowsBackend, "__init__", return_value=None), \
                mock.patch.object(helper.WindowsBackend, "version", return_value="1.2.3"):
            accepted = helper.find_legacy_update_ticket("1.2.3", "1.2.2", 3600.0)
            self.assertIsNotNone(accepted)
            self.assertEqual("a" * 32, accepted.session_id)

            ticket_path = first / "ticket.json"
            valid_text = ticket_path.read_text(encoding="utf-8")
            ticket_path.write_text(
                valid_text[:-1] + ',"schema":1}', encoding="utf-8",
            )
            self.assertIsNone(
                helper.find_legacy_update_ticket("1.2.3", "1.2.2", 3600.0),
            )
            first = self._write_legacy_session("a" * 32)

            second = self._write_legacy_session("b" * 32)
            (second / "ready").unlink()
            (second / "proceed").unlink()
            (second / "completed").write_text("1", encoding="ascii")
            first_ticket = first / "ticket.json"
            first_ticket.unlink()
            self.assertIsNone(
                helper.find_legacy_update_ticket("1.2.3", "1.2.2", 3600.0),
            )
            (second / "ticket.json").unlink()
            first = self._write_legacy_session("a" * 32)

            (first / "ready").write_text("1tampered", encoding="ascii")
            self.assertIsNone(
                helper.find_legacy_update_ticket("1.2.3", "1.2.2", 3600.0),
            )
            (first / "ready").write_text("1", encoding="ascii")
            old_marker_time = time.time() - 7200.0
            os.utime(first / "ready", (old_marker_time, old_marker_time))
            self.assertIsNone(
                helper.find_legacy_update_ticket("1.2.3", "1.2.2", 3600.0),
            )
            (first / "ready").write_text("1", encoding="ascii")
            stale = first / "ticket.json"
            old_time = time.time() - 7200.0
            os.utime(stale, (old_time, old_time))
            self.assertIsNone(
                helper.find_legacy_update_ticket("1.2.3", "1.2.2", 3600.0),
            )
            os.utime(stale, None)
            (first / "ready").write_text("1", encoding="ascii")
            (first / "proceed").write_text("1", encoding="ascii")

            third = self._write_legacy_session("c" * 32)
            self.assertTrue((third / "proceed").is_file())
            self.assertIsNone(
                helper.find_legacy_update_ticket("1.2.3", "1.2.2", 3600.0),
            )

        with mock.patch.object(helper, "installed_identity", side_effect=RuntimeError("source mode")):
            self.assertIsNone(
                helper.find_legacy_update_ticket("1.2.3", "1.2.2", 3600.0),
            )

    def test_legacy_update_rejects_a_reparse_ready_marker(self) -> None:
        """A marker redirected outside its old-helper session proves nothing.

        Returns:
            None: Symlink markers are refused before ticket candidate admission.
        """
        session = self._write_legacy_session("f" * 32)
        marker = session / "ready"
        marker.unlink()
        external = self.root / "external-ready"
        external.write_text("1", encoding="ascii")
        try:
            marker.symlink_to(external)
        except (OSError, NotImplementedError):
            self.skipTest("The current account cannot create a marker symlink")
        with mock.patch.object(helper, "installed_identity", return_value=self.installed.resolve()), \
                mock.patch.object(helper.WindowsBackend, "__init__", return_value=None), \
                mock.patch.object(helper.WindowsBackend, "version", return_value="1.2.3"):
            self.assertIsNone(
                helper.find_legacy_update_ticket("1.2.3", "1.2.2", 3600.0),
            )

    @unittest.skipUnless(sys.platform == "win32" and os.environ.get("PHP_GALLERY_NATIVE_UPDATE_SMOKE") == "1",
                         "Read-only native smoke requires a completed installer build")
    def test_native_windows_version_and_absent_target_process(self) -> None:
        """Read real PE metadata and enumerate processes without starting setup.

        Returns:
            None: Assert the built installer's version and absence of target processes.
        """
        winapp = Path(__file__).resolve().parents[1]
        version = (winapp / "VERSION").read_text(encoding="utf-8").strip()
        installer = winapp / "dist" / version / f"PHPGalleryUploader-{version}-Setup.exe"
        if not installer.is_file():
            self.skipTest(f"Built {version} installer is unavailable")
        backend = helper.WindowsBackend()
        self.assertEqual(version, backend.version(str(installer)))
        self.assertFalse(backend.target_running(str(self.root / "PHPGalleryUploader.exe")))

    def test_ticket_reader_requires_schema_and_rejects_additional_keys(self) -> None:
        """Only the versioned local ticket shape may reach the privileged boundary.

        Returns:
            None: Accepts the exact schema and refuses malformed ticket fields.
        """
        path = self.session / "ticket.json"
        raw = asdict(self.ticket)
        path.write_text(json.dumps(raw), encoding="utf-8")
        self.assertEqual(self.ticket, helper.load_ticket(path))
        encoded = json.dumps(raw, separators=(",", ":"))
        path.write_text(encoded[:-1] + ',"schema":1}', encoding="utf-8")
        with self.assertRaises(ValueError):
            helper.load_ticket(path)
        path.write_text(
            encoded.replace('"expected_size":' + str(raw["expected_size"]),
                            '"expected_size":NaN'),
            encoding="utf-8",
        )
        with self.assertRaises(ValueError):
            helper.load_ticket(path)
        for changed in ({**raw, "schema": True}, {**raw, "web_path": "anything"},
                        {key: value for key, value in raw.items() if key != "schema"}):
            path.write_text(json.dumps(changed), encoding="utf-8")
            with self.assertRaises(ValueError):
                helper.load_ticket(path)

    def test_installed_identity_rejects_copied_frozen_executable(self) -> None:
        """A developer copy sharing the product basename is never an update target.

        Returns:
            None: Accept the actual registered file, never an executable copy.
        """
        registry = types.ModuleType("winreg")
        registry.HKEY_LOCAL_MACHINE = 1
        registry.KEY_READ = 2
        registry.KEY_WOW64_64KEY = 4
        registry.REG_SZ = 1
        registry.REG_EXPAND_SZ = 2
        registry.OpenKey = mock.MagicMock()
        registry.QueryValueEx = mock.Mock(return_value=(str(self.installed.parent), 1))
        with mock.patch.object(helper, "winreg", registry), mock.patch.object(helper.sys, "platform", "win32"), \
                mock.patch.object(helper.sys, "frozen", True, create=True), \
                mock.patch.object(helper.sys, "executable", str(self.installed)):
            self.assertTrue(self.installed.samefile(helper.installed_identity()))
            copied = self.root / "developer" / "PHPGalleryUploader.exe"
            with self.assertRaisesRegex(RuntimeError, "registered installation"):
                helper.installed_identity(copied)

    def test_running_target_refuses_setup_without_force_killing(self) -> None:
        """The exact common executable must exit before installer execution starts."""
        backend = FakeBackend(running=True)
        with self.assertRaisesRegex(RuntimeError, "still running"):
            helper.perform_update(self.ticket, backend, mock.Mock(), timeout=0)
        self.assertEqual(0, backend.installs)
        self.assertEqual([], backend.restarts)

    def test_installer_is_reverified_after_shutdown(self) -> None:
        """A changed cached setup is refused before the elevated boundary."""
        self.setup.write_bytes(b"modified setup")
        backend = FakeBackend()
        with self.assertRaises(ValueError):
            helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertEqual(0, backend.installs)

    def test_success_requires_matching_pe_version(self) -> None:
        """Installer success alone cannot claim a verified application update.

        Returns:
            None: Only matching installed version plus restart marks success.
        """
        backend = FakeBackend(version="1.2.2")
        with self.assertRaisesRegex(RuntimeError, "does not match"):
            helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertEqual([], backend.restarts)
        backend = FakeBackend()
        result = helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertIn("installed", result.message)
        self.assertTrue(result.verified)
        self.assertEqual([(str(self.installed), self.ticket.session_id)], backend.restarts)

    def test_uac_cancellation_reopens_old_application_under_original_user(self) -> None:
        """Cancellation preserves usability without claiming an update or rollback.

        Returns:
            None: Relaunches the same executable with its validated session nonce.
        """
        backend = FakeBackend(cancelled=True)
        with self.assertRaises(InterruptedError):
            helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertEqual([(str(self.installed), self.ticket.session_id)], backend.restarts)

    def test_definitive_setup_launch_refusal_restarts_only_unchanged_runtime(self) -> None:
        """Recover exact capture only when ShellExecute proves setup never started.

        Returns:
            None: Exact nonce restarts for unchanged bytes; changed runtime stays fenced.
        """
        backend = FakeBackend()
        with mock.patch.object(
            backend, "install", side_effect=helper.SetupLaunchError("refused"),
        ):
            with self.assertRaises(helper.SetupLaunchError):
                helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertEqual([(str(self.installed), self.ticket.session_id)], backend.restarts)

        changed_backend = FakeBackend()
        with (
            mock.patch.object(
                helper, "_installed_runtime_fingerprint", side_effect=[b"before", b"after"],
            ),
            mock.patch.object(
                changed_backend, "install", side_effect=helper.SetupLaunchError("refused"),
            ),
        ):
            with self.assertRaises(helper.SetupLaunchError):
                helper.perform_update(self.ticket, changed_backend, mock.Mock())
        self.assertEqual([], changed_backend.restarts)

        uncertain_backend = FakeBackend()
        with mock.patch.object(
            uncertain_backend, "install", side_effect=OSError("wait failed"),
        ):
            with self.assertRaisesRegex(OSError, "wait failed"):
                helper.perform_update(self.ticket, uncertain_backend, mock.Mock())
        self.assertEqual([], uncertain_backend.restarts)

    def test_uac_cancellation_refuses_a_changed_idle_runtime(self) -> None:
        """Check a non-capture install identity even when approval is cancelled.

        Returns:
            None: No changed runtime restarts after a definitive cancellation.
        """
        backend = FakeBackend(cancelled=True)
        idle = replace(self.ticket, resume_capture=False)
        with mock.patch.object(helper, '_installed_runtime_fingerprint', side_effect=[b'old', b'new']):
            with self.assertRaises(InterruptedError):
                helper.perform_update(idle, backend, mock.Mock())
        self.assertEqual([], backend.restarts)

    def test_windows_restart_passes_only_the_validated_update_nonce(self) -> None:
        """Relaunch uses the helper nonce without adding privilege elevation.

        Returns:
            None: The exact installed executable receives the update argument;
                malformed nonces never reach process creation.
        """
        backend = object.__new__(helper.WindowsBackend)
        nonce = self.ticket.session_id
        (self.session / 'ticket.json').write_text(json.dumps(asdict(self.ticket)), encoding='utf-8')
        with (
            mock.patch.object(helper.subprocess, "Popen") as popen,
            mock.patch.object(helper, "installed_identity", return_value=self.installed) as registered,
        ):
            backend.restart(str(self.installed), update_session=nonce)
            registered.assert_called_once_with(self.installed)
            arguments, options = popen.call_args
            self.assertEqual([str(self.installed), "--after-update", nonce], arguments[0])
            self.assertEqual(str(self.installed.parent), options["cwd"])
            self.assertEqual("1", options["env"]["PYINSTALLER_RESET_ENVIRONMENT"])
            popen.reset_mock()
            with self.assertRaises(ValueError):
                backend.restart(str(self.installed), update_session="not-a-nonce")
        popen.assert_not_called()

    def test_idle_update_restarts_without_capture_continuation(self) -> None:
        """Bind idle handoff explicitly instead of reusing a stale capture checkpoint.

        Returns:
            None: Idle restart omits --after-update; active restart keeps its exact nonce.
        """
        ticket_path = self.session / 'ticket.json'
        ticket_path.write_text(json.dumps(asdict(self.ticket)), encoding='utf-8')
        helper.bind_capture_resume(ticket_path, False)
        self.assertFalse(helper.load_ticket(ticket_path).resume_capture)
        backend = object.__new__(helper.WindowsBackend)
        with (
            mock.patch.object(helper.subprocess, 'Popen') as popen,
            mock.patch.object(helper, 'installed_identity', return_value=self.installed),
        ):
            backend.restart(str(self.installed), update_session=self.ticket.session_id)
        arguments, options = popen.call_args
        self.assertEqual([str(self.installed)], arguments[0])
        self.assertEqual(str(self.installed.parent), options["cwd"])
        self.assertEqual("1", options["env"]["PYINSTALLER_RESET_ENVIRONMENT"])
        helper.bind_capture_resume(ticket_path, True)
        with (
            mock.patch.object(helper.subprocess, 'Popen') as popen,
            mock.patch.object(helper, 'installed_identity', return_value=self.installed),
        ):
            backend.restart(str(self.installed), update_session=self.ticket.session_id)
        arguments, options = popen.call_args
        self.assertEqual(
            [str(self.installed), '--after-update', self.ticket.session_id],
            arguments[0],
        )
        self.assertEqual(str(self.installed.parent), options["cwd"])
        self.assertEqual("1", options["env"]["PYINSTALLER_RESET_ENVIRONMENT"])

    def test_restart_required_and_unknown_codes_are_honest(self) -> None:
        """Restart-required results never prematurely relaunch the uploader.

        Returns:
            None: Reboot notices remain unverified and unknown codes still fail.
        """
        backend = FakeBackend(code=3010)
        result = helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertIn("restart required", result.message)
        self.assertFalse(result.verified)
        self.assertEqual([], backend.restarts)
        with self.assertRaisesRegex(RuntimeError, "code 42"):
            helper.perform_update(self.ticket, FakeBackend(code=42), mock.Mock())

    def test_failed_setup_resumes_only_the_byte_identical_installed_runtime(self) -> None:
        """Keep a paused capture on the original runtime after a failed setup.

        Returns:
            None: A changed _internal file keeps recovery fenced.
        """
        internal = self.installed.parent / helper.PYINSTALLER_INTERNAL_DIRECTORY
        internal.mkdir()
        library = internal / 'python314.dll'
        library.write_bytes(b'original sealed fixture')
        backend = FakeBackend(code=42)
        with self.assertRaisesRegex(RuntimeError, 'code 42'):
            helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertEqual([(str(self.installed), self.ticket.session_id)], backend.restarts)

        def failed_partial_setup(_ticket: helper.UpdateTicket, _progress: helper.Progress) -> int:
            """Change one _internal file before the injected setup failure.

            Args:
                _ticket (helper.UpdateTicket): Valid site-specific fixture ticket.
                _progress (helper.Progress): Unused setup feedback no-op.
            Returns:
                int: Synthetic nonzero setup failure exit code.
            """
            library.write_bytes(b'replaced runtime fixture')
            return 42

        backend = FakeBackend(code=42)
        with mock.patch.object(backend, 'install', side_effect=failed_partial_setup):
            with self.assertRaisesRegex(RuntimeError, 'code 42'):
                helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertEqual([], backend.restarts)

    def test_launch_timeout_cancels_before_proceed_handshake(self) -> None:
        """Prevent a late helper from installing after uploader timeout.

        Returns:
            None: Launch publishes cancellation without the proceed marker.
        """
        with mock.patch.object(helper.subprocess, "Popen") as popen:
            with self.assertRaisesRegex(RuntimeError, "remains open"):
                helper.launch_helper(self.session / "ticket.json", timeout=0)
        options = popen.call_args.kwargs
        self.assertEqual("1", options["env"]["PYINSTALLER_RESET_ENVIRONMENT"])
        self.assertTrue((self.session / "cancel").is_file())
        self.assertFalse((self.session / "proceed").exists())

    def test_progress_is_bounded_numeric_and_installer_arguments_are_local(self) -> None:
        """Status records carry phase/progress only and never a diagnostic path."""
        self.assertEqual((2, 75), helper.parse_status(b"2 75"))
        for record in (b"2 101", b"9 50", b"2 -1", b"error secret", b"2 50\nextra"):
            self.assertIsNone(helper.parse_status(record))
        arguments = helper.installer_arguments(self.ticket)
        self.assertIn("/SELFUPDATE=1", arguments)
        self.assertIn("/DIR=" + str(self.installed.parent), arguments)
        self.assertIn("/UPDATESTATUS=" + self.ticket.session_id, arguments)
        self.assertIn("/RESTARTEXITCODE=3010", arguments)


    def test_helper_closes_only_after_verified_success(self) -> None:
        """Exercise Tk terminal handling without installing or opening windows.

        Returns:
            None: Verified success closes automatically after its marker;
                reboot notices and failures retain the enabled Close action.
        """
        for outcome in (
            helper.UpdateOutcome("installed", True),
            helper.UpdateOutcome("Windows restart required", False),
            RuntimeError("setup failed"),
        ):
            with self.subTest(outcome=outcome):
                root, label, button = mock.Mock(), mock.Mock(), mock.Mock()
                root.destroy.side_effect = lambda: self.assertTrue((self.session / "completed").is_file())
                tkinter = types.ModuleType("tkinter")
                tkinter.Tk = mock.Mock(return_value=root)
                tkinter.StringVar = mock.Mock(return_value=label)
                ttk = types.ModuleType("tkinter.ttk")
                ttk.Label = mock.Mock()
                ttk.Progressbar = mock.Mock()
                ttk.Button = mock.Mock(return_value=button)
                tkinter.ttk = ttk
                (self.session / "proceed").write_text("1", encoding="ascii")
                (self.session / "completed").unlink(missing_ok=True)
                result_options = {"side_effect": outcome} if isinstance(outcome, Exception) else {"return_value": outcome}
                with (
                    mock.patch.object(helper, "tk", tkinter),
                    mock.patch.object(helper, "ttk", ttk),
                    mock.patch.object(helper, "Button", ttk.Button),
                    mock.patch.object(helper, "load_ui_language", return_value="en"),
                    mock.patch.object(helper, "load_ticket", return_value=self.ticket),
                    mock.patch.object(helper, "installed_identity"),
                    mock.patch.object(helper, "WindowsBackend"),
                    mock.patch.object(helper.sys, "executable", str(self.session / "helper.exe")),
                    mock.patch.object(helper, "perform_update", **result_options),
                    mock.patch.object(helper.threading, "Thread",
                        side_effect=lambda **kwargs: types.SimpleNamespace(start=kwargs["target"])),
                ):
                    code = helper.main(str(self.session / "ticket.json"))
                self.assertEqual(1 if isinstance(outcome, Exception) else 0, code)
                if isinstance(outcome, helper.UpdateOutcome) and outcome.verified:
                    root.destroy.assert_called_once()
                    button.configure.assert_not_called()
                else:
                    root.destroy.assert_not_called()
                    button.configure.assert_called_once_with(state="normal")
                root.after.assert_not_called()


if __name__ == "__main__":
    unittest.main()
