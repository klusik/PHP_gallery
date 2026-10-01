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
import types
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from uploader import update_helper as helper


class FakeBackend:
    """Record privilege-boundary actions without any Windows API calls."""
    def __init__(self, running: bool = False, code: int = 0, version: str = "1.2.3",
                 cancelled: bool = False) -> None:
        """Configure deterministic running-process and installer results."""
        self.running = running
        self.code = code
        self.installed_version = version
        self.cancelled = cancelled
        self.installs = 0
        self.restarts: list[str] = []

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

    def restart(self, executable: str) -> None:
        """Record original-user restarts for later assertions."""
        self.restarts.append(executable)


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

    def test_broken_symlink_is_rejected_before_existence_check(self) -> None:
        """A dangling link remains a forbidden path even without a target."""
        with mock.patch.object(Path, "is_symlink", return_value=True), \
                mock.patch.object(Path, "exists", return_value=False) as exists:
            with self.assertRaisesRegex(ValueError, "reparse points"):
                helper.reject_reparse_path(self.root / "broken-link")
        exists.assert_not_called()

    @unittest.skipUnless(sys.platform == "win32" and os.environ.get("PHP_GALLERY_NATIVE_UPDATE_SMOKE") == "1",
                         "Read-only native smoke requires a completed installer build")
    def test_native_windows_version_and_absent_target_process(self) -> None:
        """Read real PE metadata and enumerate processes without starting setup."""
        winapp = Path(__file__).resolve().parents[1]
        version = (winapp / "VERSION").read_text(encoding="utf-8").strip()
        installer = winapp / "dist" / f"PHPGalleryUploader-{version}-Setup.exe"
        if not installer.is_file():
            self.skipTest(f"Built {version} installer is unavailable")
        backend = helper.WindowsBackend()
        self.assertEqual(version, backend.version(str(installer)))
        self.assertFalse(backend.target_running(str(self.root / "PHPGalleryUploader.exe")))

    def test_ticket_reader_requires_schema_and_rejects_additional_keys(self) -> None:
        """Only the versioned local ticket shape may reach the privileged boundary."""
        path = self.session / "ticket.json"
        raw = asdict(self.ticket)
        path.write_text(json.dumps(raw), encoding="utf-8")
        self.assertEqual(self.ticket, helper.load_ticket(path))
        for changed in ({**raw, "schema": True}, {**raw, "web_path": "anything"},
                        {key: value for key, value in raw.items() if key != "schema"}):
            path.write_text(json.dumps(changed), encoding="utf-8")
            with self.assertRaises(ValueError):
                helper.load_ticket(path)

    def test_installed_identity_rejects_copied_frozen_executable(self) -> None:
        """A developer copy sharing the product basename is never an update target."""
        registry = types.ModuleType("winreg")
        registry.HKEY_LOCAL_MACHINE = 1
        registry.KEY_READ = 2
        registry.KEY_WOW64_64KEY = 4
        registry.REG_SZ = 1
        registry.REG_EXPAND_SZ = 2
        registry.OpenKey = mock.MagicMock()
        registry.QueryValueEx = mock.Mock(return_value=(str(self.installed.parent), 1))
        with mock.patch.dict(sys.modules, {"winreg": registry}), mock.patch.object(helper.sys, "platform", "win32"), \
                mock.patch.object(helper.sys, "frozen", True, create=True), \
                mock.patch.object(helper.sys, "executable", str(self.installed)):
            self.assertEqual(self.installed, helper.installed_identity())
            copied = self.root / "developer" / "PHPGalleryUploader.exe"
            with self.assertRaisesRegex(RuntimeError, "registered installation"):
                helper.installed_identity(copied)

    def test_running_target_refuses_setup_without_force_killing(self) -> None:
        """Both onefile processes must exit before installer execution starts."""
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
        """Installer success alone cannot claim a verified application update."""
        backend = FakeBackend(version="1.2.2")
        with self.assertRaisesRegex(RuntimeError, "does not match"):
            helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertEqual([], backend.restarts)
        backend = FakeBackend()
        self.assertIn("installed", helper.perform_update(self.ticket, backend, mock.Mock()))
        self.assertEqual([str(self.installed)], backend.restarts)

    def test_uac_cancellation_reopens_old_application_under_original_user(self) -> None:
        """Cancellation preserves usability without claiming an update or rollback."""
        backend = FakeBackend(cancelled=True)
        with self.assertRaises(InterruptedError):
            helper.perform_update(self.ticket, backend, mock.Mock())
        self.assertEqual([str(self.installed)], backend.restarts)

    def test_restart_required_and_unknown_codes_are_honest(self) -> None:
        """Restart-required results never prematurely relaunch the uploader."""
        backend = FakeBackend(code=3010)
        self.assertIn("restart required", helper.perform_update(self.ticket, backend, mock.Mock()))
        self.assertEqual([], backend.restarts)
        with self.assertRaisesRegex(RuntimeError, "code 42"):
            helper.perform_update(self.ticket, FakeBackend(code=42), mock.Mock())

    def test_launch_timeout_cancels_before_proceed_handshake(self) -> None:
        """A late helper cannot autonomously install after uploader timeout."""
        with mock.patch.object(helper.subprocess, "Popen"):
            with self.assertRaisesRegex(RuntimeError, "remains open"):
                helper.launch_helper(self.session / "ticket.json", timeout=0)
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


if __name__ == "__main__":
    unittest.main()
