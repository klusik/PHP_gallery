# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_windows_startup.py
# Module Type: Desktop lifecycle regressions
# Purpose: Exercise startup preferences, native coordination and tray admission.
# Responsibilities: Isolate registry/API fakes from real user state.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Behavioral regressions discovered by the central WinApp unittest owner."""

from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import types
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import windows_startup as startup
from test_redesign import MAIN

# Purpose: Bound native child startup/shutdown without hanging central discovery.
# Type: int; Units: seconds; Scope: isolated Windows process fixtures.
# Consumers: subprocess.run, readiness communication and process wait.
# Rationale: ten seconds allows cold Python startup within the audit deadline.
CHILD_TIMEOUT_SECONDS = 10


class StartupPreferenceTests(unittest.TestCase):
    """Preserve opt-out and use only the frozen executable's per-user Run entry."""

    def test_defaults_migration_and_opt_out_round_trip(self) -> None:
        """Enable missing preferences and preserve an explicit false value.

        Returns:
            None: Both legacy defaults and persisted opt-out remain stable.
        """
        self.assertTrue(MAIN.WatcherConfig.from_dict({}).start_with_windows)
        configured = MAIN.WatcherConfig.from_dict({
            "start_with_windows": False, "api_key": "kept-key",
        })
        self.assertFalse(configured.start_with_windows)
        restored = MAIN.WatcherConfig.from_dict(configured.to_dict())
        self.assertFalse(restored.start_with_windows)
        self.assertEqual("kept-key", restored.api_key)

    def test_registry_registers_quoted_executable_and_removes_only_own_value(self) -> None:
        """Exercise registration, removal and idempotent missing-value opt-out.

        Returns:
            None: Uses HKCU, a quoted executable, --startup and the x64 view.
        """
        registry = mock.MagicMock()
        registry.KEY_SET_VALUE = 2  # Windows KEY_SET_VALUE access flag.
        registry.KEY_WOW64_64KEY = 0x100  # Windows x64 registry-view flag.
        executable = WINAPP_DIR / "installed app" / "PHPGalleryUploader.exe"
        with (
            mock.patch.object(startup, "startup_supported", return_value=True),
            mock.patch.object(startup.sys, "executable", str(executable)),
            mock.patch.dict(sys.modules, {"winreg": registry}),
        ):
            startup.synchronize_startup(True)
            key = registry.CreateKeyEx.return_value.__enter__.return_value
            registry.SetValueEx.assert_called_once_with(
                key, startup.RUN_VALUE, 0, registry.REG_SZ,
                '"' + str(executable.resolve()) + '" --startup',
            )
            registry.CreateKeyEx.assert_called_once_with(
                registry.HKEY_CURRENT_USER, startup.RUN_KEY, 0,
                registry.KEY_SET_VALUE | registry.KEY_WOW64_64KEY,
            )
            startup.synchronize_startup(False)
            key = registry.OpenKey.return_value.__enter__.return_value
            registry.DeleteValue.assert_called_once_with(key, startup.RUN_VALUE)
            registry.DeleteValue.side_effect = FileNotFoundError("already off")
            startup.synchronize_startup(False)
            registry.DeleteKey.assert_not_called()

    def test_source_mode_never_touches_registry(self) -> None:
        """Avoid registering Python or changing the workstation in source mode.

        Returns:
            None: Neither switch state reaches a registry API.
        """
        registry = mock.MagicMock()
        with (
            mock.patch.object(startup, "startup_supported", return_value=False),
            mock.patch.dict(sys.modules, {"winreg": registry}),
        ):
            startup.synchronize_startup(True)
            startup.synchronize_startup(False)
        self.assertEqual([], registry.mock_calls)

    def test_startup_switch_saves_only_the_selected_preference(self) -> None:
        """Keep unrelated connection/settings edits out of switch persistence.

        Returns:
            None: Saves opt-out immediately without reading the editable form.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.config = MAIN.WatcherConfig(api_key="saved-key")
        app.start_with_windows_var = mock.Mock()
        app.start_with_windows_var.get.return_value = False
        app.config_store = mock.Mock()
        app.api_key_revoke_running = False
        app.current_config = mock.Mock(side_effect=AssertionError("unsaved form"))
        app.apply_startup_registration = mock.Mock()
        app.save_startup_preference()
        saved = app.config_store.save.call_args.args[0]
        self.assertFalse(saved.start_with_windows)
        self.assertEqual("saved-key", saved.api_key)
        app.current_config.assert_not_called()
        app.apply_startup_registration.assert_called_once_with()

    def test_registry_denial_reaches_the_caller(self) -> None:
        """Retain an actionable failure instead of reporting registration success.

        Returns:
            None: A denied registration propagates its OSError.
        """
        registry = mock.MagicMock()
        registry.CreateKeyEx.side_effect = PermissionError("registry denied")
        registry.KEY_SET_VALUE = 2  # Windows registry access bit.
        registry.KEY_WOW64_64KEY = 0x100  # Windows x64 view bit.
        with (
            mock.patch.object(startup, "startup_supported", return_value=True),
            mock.patch.dict(sys.modules, {"winreg": registry}),
            self.assertRaisesRegex(PermissionError, "registry denied"),
        ):
            startup.synchronize_startup(True)

    def test_blocked_and_failed_switch_saves_preserve_saved_state(self) -> None:
        """Keep the prior preference when installation or disk errors block it.

        Returns:
            None: Neither refusal applies an unpersisted registry preference.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.config = MAIN.WatcherConfig(start_with_windows=True)
        app.root = mock.Mock()
        app.start_with_windows_var = mock.Mock()
        app.start_with_windows_var.get.return_value = False
        app.api_key_revoke_running = False
        app.config_store = mock.Mock()
        app.apply_startup_registration = mock.Mock()
        app.updates = mock.Mock()
        app.updates.blocked.return_value = True
        app.save_startup_preference()
        app.start_with_windows_var.set.assert_called_with(True)
        app.config_store.save.assert_not_called()
        app.updates.blocked.return_value = False
        app.config_store.save.side_effect = OSError("disk unavailable")
        with mock.patch.object(MAIN, "messagebox") as messages:
            app.save_startup_preference()
            messages.showerror.assert_called_once()
        self.assertTrue(app.config.start_with_windows)
        app.apply_startup_registration.assert_not_called()

    def test_installer_mode_reconciles_opt_out_without_ui(self) -> None:
        """Apply both initial defaults and saved opt-out from installer bootstrap.

        Returns:
            None: Setup's headless mode never opens Tk or resets false to true.
        """
        for enabled in (True, False):
            with (
                self.subTest(enabled=enabled),
                mock.patch.object(MAIN, "WindowsInstance") as factory,
                mock.patch.object(MAIN, "setup_logging"),
                mock.patch.object(MAIN, "ConfigStore") as store,
                mock.patch.object(MAIN, "synchronize_startup") as synchronize,
                mock.patch.object(MAIN, "WatcherApp") as app,
            ):
                factory.return_value.acquire.return_value = True
                store.return_value.load.return_value = MAIN.WatcherConfig(
                    start_with_windows=enabled,
                )
                self.assertEqual(0, MAIN.main(["--configure-startup"]))
                synchronize.assert_called_once_with(enabled)
                app.assert_not_called()


class InstanceCoordinationTests(unittest.TestCase):
    """Model native handles without changing the host platform or user profile."""

    def test_owner_duplicate_signal_and_abandoned_recovery(self) -> None:
        """Keep pointer-width handles, early requests and crash recovery intact.

        Returns:
            None: Only an owner releases the mutex and cleanup closes once.
        """
        kernel = mock.MagicMock()
        # Values above DWORD range expose accidental HANDLE truncation on x64.
        kernel.CreateEventW.return_value = 0x100000001
        kernel.CreateMutexW.return_value = 0x100000002
        with (
            mock.patch.object(startup, "IS_WINDOWS", True),
            mock.patch.object(startup.ctypes, "WinDLL", return_value=kernel, create=True),
            mock.patch.object(startup.ctypes, "get_last_error", return_value=0, create=True) as error,
        ):
            owner = startup.WindowsInstance(WINAPP_DIR / "isolated-profile")
            self.assertTrue(owner.acquire())
            self.assertTrue(owner.owned)
            self.assertEqual(
                ["CreateEventW", "CreateMutexW"],
                [call[0] for call in kernel.mock_calls[:2]],
            )
            error.return_value = startup.ERROR_ALREADY_EXISTS
            kernel.WaitForSingleObject.return_value = startup.WAIT_TIMEOUT
            duplicate = startup.WindowsInstance(WINAPP_DIR / "isolated-profile")
            self.assertFalse(duplicate.acquire())
            duplicate.request_activation()
            kernel.SetEvent.assert_called_once_with(duplicate.event)
            duplicate.close()
            kernel.ReleaseMutex.assert_not_called()
            kernel.WaitForSingleObject.return_value = startup.WAIT_OBJECT_0
            self.assertTrue(owner.activation_requested())
            kernel.WaitForSingleObject.return_value = startup.WAIT_TIMEOUT
            self.assertFalse(owner.activation_requested())
            kernel.WaitForSingleObject.return_value = startup.WAIT_ABANDONED
            recovered = startup.WindowsInstance(WINAPP_DIR / "isolated-profile")
            self.assertTrue(recovered.acquire())
            recovered.close()
            owner.close()
            closed = kernel.CloseHandle.call_count
            owner.close()
            self.assertEqual(closed, kernel.CloseHandle.call_count)

    def test_duplicate_never_loads_or_mutates_app_state(self) -> None:
        """Send manual activation before opening logging, configuration or Tk.

        Returns:
            None: A manual duplicate activates once and releases its handles.
        """
        with (
            mock.patch.object(MAIN, "WindowsInstance") as factory,
            mock.patch.object(MAIN, "setup_logging") as logging_setup,
            mock.patch.object(MAIN, "ConfigStore") as store,
            mock.patch.object(MAIN, "WatcherApp") as app,
        ):
            instance = factory.return_value
            instance.acquire.return_value = False
            self.assertEqual(0, MAIN.main([]))
            instance.request_activation.assert_called_once_with()
            logging_setup.assert_not_called()
            store.assert_not_called()
            app.assert_not_called()
            instance.close.assert_called_once_with()
            instance.request_activation.reset_mock()
            self.assertEqual(0, MAIN.main(["--startup"]))
            self.assertEqual(0, MAIN.main(["--configure-startup"]))
            self.assertEqual(1, MAIN.main(["--once"]))
            instance.request_activation.assert_not_called()

    def test_disabled_startup_exits_without_ui(self) -> None:
        """A stale Run entry cannot open the app after a persisted opt-out.

        Returns:
            None: Removes the stale registration and leaves all GUI work absent.
        """
        with (
            mock.patch.object(MAIN, "WindowsInstance") as factory,
            mock.patch.object(MAIN, "setup_logging"),
            mock.patch.object(MAIN, "ConfigStore") as store,
            mock.patch.object(MAIN, "synchronize_startup") as synchronize,
            mock.patch.object(MAIN, "WatcherApp") as app,
        ):
            factory.return_value.acquire.return_value = True
            store.return_value.load.return_value = MAIN.WatcherConfig(
                start_with_windows=False,
            )
            self.assertEqual(0, MAIN.main(["--startup"]))
            synchronize.assert_called_once_with(False)
            app.assert_not_called()

    def test_coordination_failure_never_admits_an_unlocked_writer(self) -> None:
        """Show a native admission error without opening any application state.

        Returns:
            None: Failed coordination exits nonzero and closes acquired handles.
        """
        with (
            mock.patch.object(MAIN, "WindowsInstance") as factory,
            mock.patch.object(MAIN, "setup_logging") as logging_setup,
            mock.patch.object(MAIN, "ConfigStore") as store,
            mock.patch.object(MAIN, "WatcherApp") as app,
            mock.patch.object(MAIN, "messagebox") as messages,
        ):
            factory.return_value.acquire.side_effect = OSError("native denied")
            self.assertEqual(1, MAIN.main([]))
            logging_setup.assert_not_called()
            store.assert_not_called()
            app.assert_not_called()
            messages.showerror.assert_called_once()
            factory.return_value.close.assert_called_once_with()

    def test_primary_logon_passes_minimized_mode_and_keeps_lock_until_exit(self) -> None:
        """Keep ownership around the complete GUI lifetime, including teardown.

        Returns:
            None: Passes startup intent and closes native handles after run.
        """
        order = []
        with (
            mock.patch.object(MAIN, "WindowsInstance") as factory,
            mock.patch.object(MAIN, "setup_logging"),
            mock.patch.object(MAIN, "ConfigStore") as store,
            mock.patch.object(MAIN, "WatcherApp") as app,
        ):
            factory.return_value.acquire.return_value = True
            store.return_value.load.return_value = MAIN.WatcherConfig()
            app.return_value.run.side_effect = lambda: order.append("run")
            factory.return_value.close.side_effect = lambda: order.append("close")
            self.assertEqual(0, MAIN.main(["--startup"]))
            app.assert_called_once_with(
                config_store=store.return_value, start_minimized=True,
            )
            self.assertEqual(["run", "close"], order)


@unittest.skipUnless(sys.platform == "win32", "Native mutex/event needs Windows")
class NativeInstanceTests(unittest.TestCase):
    """Use separate processes and a temporary identity without touching app data."""

    def test_second_process_signals_owner_then_clean_exit_allows_relaunch(self) -> None:
        """Deliver a real cross-process event and acquire again after shutdown.

        Returns:
            None: A live owner wins once and releases without a stale file lock.
        """
        with tempfile.TemporaryDirectory() as directory:
            profile = Path(directory)
            owner = startup.WindowsInstance(profile)
            try:
                self.assertTrue(owner.acquire())
                child = subprocess.run(
                    [sys.executable, "-c", (
                        "from pathlib import Path; import sys; "
                        "from uploader.windows_startup import WindowsInstance; "
                        "instance = WindowsInstance(Path(sys.argv[1])); "
                        "assert not instance.acquire(); "
                        "instance.request_activation(); instance.close()"
                    ), directory],
                    cwd=WINAPP_DIR, capture_output=True, text=True,
                    timeout=CHILD_TIMEOUT_SECONDS,
                    creationflags=subprocess.CREATE_NO_WINDOW,
                )
                self.assertEqual(0, child.returncode, child.stderr)
                self.assertTrue(owner.activation_requested())
                self.assertFalse(owner.activation_requested())
            finally:
                owner.close()
            replacement = startup.WindowsInstance(profile)
            try:
                self.assertTrue(replacement.acquire())
            finally:
                replacement.close()

    def test_crashed_owner_is_replaced_while_a_secondary_still_holds_handles(self) -> None:
        """Recover an abandoned mutex even before another launcher has exited.

        Returns:
            None: Kernel abandonment admits a replacement without deleting state.
        """
        with tempfile.TemporaryDirectory() as directory:
            child = subprocess.Popen(
                [sys.executable, "-c", (
                    "from pathlib import Path; import sys; "
                    "from uploader.windows_startup import WindowsInstance; "
                    "instance = WindowsInstance(Path(sys.argv[1])); "
                    "assert instance.acquire(); "
                    "print('ready', flush=True); sys.stdin.read()"
                ), directory],
                cwd=WINAPP_DIR, stdin=subprocess.PIPE,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                creationflags=subprocess.CREATE_NO_WINDOW,
            )
            secondary = startup.WindowsInstance(Path(directory))
            replacement = startup.WindowsInstance(Path(directory))
            ready = threading.Event()
            ready_line: list[bytes] = []
            assert child.stdout is not None
            reader = threading.Thread(
                target=lambda: (ready_line.append(child.stdout.readline()), ready.set()),
                daemon=True,
            )
            reader.start()
            try:
                self.assertTrue(ready.wait(CHILD_TIMEOUT_SECONDS))
                self.assertEqual([b"ready\n"], ready_line)
                self.assertFalse(secondary.acquire())
                child.kill()
                child.wait(timeout=CHILD_TIMEOUT_SECONDS)
                self.assertTrue(replacement.acquire())
            finally:
                replacement.close()
                secondary.close()
                if child.poll() is None:
                    child.kill()
                reader.join(timeout=CHILD_TIMEOUT_SECONDS)
                child.communicate(timeout=CHILD_TIMEOUT_SECONDS)


class TrayStartupTests(unittest.TestCase):
    """Keep logon invisible and restore the same window on demand or tray loss."""

    def test_ready_tray_remains_hidden(self) -> None:
        """Complete a tray-only startup without presenting recovery or workers.

        Returns:
            None: A visible native icon admits the hidden main window.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.exiting = False
        app.startup_waiting_for_tray = True
        app.tray_icon = types.SimpleNamespace(visible=True)
        app.tray_thread = mock.Mock()
        app.tray_thread.is_alive.return_value = True
        app.root = mock.Mock()
        app.write_log = mock.Mock()
        app.complete_startup()
        self.assertFalse(app.startup_waiting_for_tray)
        app.root.deiconify.assert_not_called()

    def test_missing_and_stalled_tray_restore_existing_window(self) -> None:
        """Recover an accessible window when a hidden launch cannot expose tray.

        Returns:
            None: Missing and timed-out initialization both restore the window.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.exiting = False
        app.startup_waiting_for_tray = True
        app.write_log = mock.Mock()
        app.restore_from_tray = mock.Mock()
        app.tray_icon = None
        app.complete_startup()
        app.restore_from_tray.assert_called_once_with()
        app.tray_icon = types.SimpleNamespace(visible=False)
        app.tray_thread = mock.Mock()
        app.tray_thread.is_alive.return_value = True
        app.startup_tray_deadline = 0.0  # Already expired, no wall-clock sleep.
        app.complete_startup()
        self.assertEqual(2, app.restore_from_tray.call_count)

    def test_relaunch_restores_without_restarting_workers(self) -> None:
        """Consume activation on Tk and show deferred recovery only once.

        Returns:
            None: Restores the existing root and schedules the next native poll.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.exiting = False
        app.instance = mock.Mock()
        app.instance.activation_requested.return_value = True
        app.root = mock.Mock()
        app.recovery_offer_pending = True
        app.offer_recoverable_jobs = mock.Mock()
        app.start = mock.Mock()
        app.poll_instance_activation()
        app.root.deiconify.assert_called_once_with()
        app.root.focus_force.assert_called_once_with()
        app.root.after_idle.assert_called_once_with(app.offer_recoverable_jobs)
        app.root.after.assert_called_once_with(
            startup.ACTIVATION_POLL_MS, app.poll_instance_activation,
        )
        app.restore_from_tray()
        app.root.after_idle.assert_called_once()
        app.start.assert_not_called()


if __name__ == "__main__":
    unittest.main()
