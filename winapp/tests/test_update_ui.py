# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Verify deterministic updater admission, draining and handoff behavior.
# Responsibilities:
#   - Exercise installer refusal and helper readiness without desktop mutation.
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_update_ui.py
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Safe deterministic controller tests; no Tk windows, network or installer."""
from dataclasses import asdict
import json
from pathlib import Path
import queue
import sys
import tempfile
import threading
from types import SimpleNamespace
import unittest
from typing import Callable
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from uploader.update_ui import InstallGate, UpdateController, check_due
from uploader.self_update import ReleaseAsset


class UpdateControllerTests(unittest.TestCase):
    """Admission remains closed until an acknowledged helper or explicit refusal."""

    def controller(self) -> UpdateController:
        """Build an isolated controller and app callback fixture.

        Returns:
            UpdateController: Controller with mocked Tk and persistent app hooks.
        """
        controller = UpdateController.__new__(UpdateController)
        controller.root = SimpleNamespace(after=Mock(), destroy=Mock())
        # Model the invoking window on a secondary monitor for place_dialog.
        controller.root.winfo_toplevel = Mock(return_value=controller.root)
        controller.root.winfo_rootx = Mock(return_value=3000)
        controller.root.winfo_rooty = Mock(return_value=1000)
        controller.root.winfo_width = Mock(return_value=900)
        controller.root.winfo_height = Mock(return_value=600)
        controller.app = SimpleNamespace(root=controller.root, exiting=False,
                                         stop_tray_icon=Mock(), write_log=Mock(),
                                         set_update_available=Mock())
        controller.version = '1.0.0'
        controller.gate = InstallGate(timeout=10)
        controller.events = queue.Queue()
        controller.busy = False
        controller.close_pending = False
        controller.auto_install = False
        controller.cached_offer_loaded = False
        controller.window = None
        controller.action = SimpleNamespace(configure=Mock())
        controller.status = SimpleNamespace(set=Mock())
        controller.cancel_button = SimpleNamespace(configure=Mock())
        controller.check_result_pending = False
        controller.worker = None
        controller.cancel = threading.Event()
        controller.state_path = Path("updates") / "state.json"
        controller.installer = Path('setup.exe')
        controller.asset = SimpleNamespace(version='1.2.3', sha256='a' * 64, size=100)
        return controller

    def test_admission_and_deadline(self) -> None:
        """Verify admission and deadline."""
        gate = InstallGate(timeout=10)
        gate.begin(50)
        self.assertTrue(gate.active)
        self.assertEqual(gate.poll(59, ['upload']), 'waiting')
        self.assertEqual(gate.poll(60, ['upload']), 'timeout')
        self.assertFalse(gate.active)
        gate.begin(100)
        self.assertEqual(gate.poll(101, []), 'ready')
        self.assertTrue(gate.active)

    def test_install_stops_workers_after_admission_closes_without_saving_forms(self) -> None:
        """Verify install stops workers after admission closes without saving forms."""
        controller = self.controller()
        worker = SimpleNamespace(is_alive=lambda: True, stop=Mock())
        worker.stop.side_effect = lambda: self.assertTrue(controller.gate.active)
        controller.app.manual_worker = worker
        controller.app.current_config = Mock(side_effect=AssertionError('unsaved form read'))
        controller.app.config_store = SimpleNamespace(save=Mock(side_effect=AssertionError('config overwrite')))
        with patch('uploader.update_helper.installed_identity', return_value=Path('PHPGalleryUploader.exe')):
            controller.install()
        worker.stop.assert_called_once()
        controller.app.current_config.assert_not_called()
        controller.app.config_store.save.assert_not_called()
        self.assertTrue(controller.gate.active)
        controller.root.destroy.assert_not_called()

    def test_timeout_reopens_admission_and_does_not_launch(self) -> None:
        """Verify timeout reopens admission and does not launch."""
        controller = self.controller()
        controller.gate.begin(0)
        controller.blockers = lambda: ['connection test']
        controller.run_worker = Mock()
        with patch('uploader.update_ui.time.monotonic', return_value=11):
            controller.drain()
        self.assertFalse(controller.gate.active)
        controller.run_worker.assert_not_called()
        controller.root.destroy.assert_not_called()

    def test_ready_drain_schedules_handoff_without_closing(self) -> None:
        """Verify ready drain schedules handoff without closing."""
        controller = self.controller()
        controller.gate.begin(0)
        controller.blockers = lambda: []
        controller.run_worker = Mock()
        controller.drain()
        controller.run_worker.assert_called_once_with(controller.handoff)
        controller.root.destroy.assert_not_called()
        self.assertTrue(controller.gate.active)

    def test_failed_handshake_leaves_app_open_and_reopens_gate(self) -> None:
        """Verify failed handshake leaves app open and reopens gate."""
        controller = self.controller()
        controller.gate.begin(0)
        controller.events.put(('error', 'Helper readiness timed out'))
        controller.poll()
        self.assertFalse(controller.gate.active)
        controller.root.destroy.assert_not_called()

    def test_only_ready_event_closes_tray_and_window(self) -> None:
        """Verify only ready event closes tray and window."""
        controller = self.controller()
        controller.gate.begin(0)
        controller.events.put(('handoff_ready', None))
        controller.poll()
        self.assertTrue(controller.app.exiting)
        controller.app.stop_tray_icon.assert_called_once()
        controller.root.destroy.assert_called_once()

    def test_source_mode_offers_installer_without_draining(self) -> None:
        """Verify source mode offers installer without draining."""
        controller = self.controller()
        controller.app.open_local_path = Mock()
        with patch('uploader.update_helper.installed_identity', side_effect=RuntimeError('Source mode')):
            controller.install()
        self.assertFalse(controller.gate.active)
        controller.app.open_local_path.assert_called_once_with(controller.installer)

    def test_connection_repair_threads_and_processes_are_blockers(self) -> None:
        """Verify connection repair threads and processes are blockers."""
        controller = self.controller()
        controller.app.connection_test_running = True
        controller.app.semantic_ai_install_running = True
        fake_thread = SimpleNamespace(name='connection', is_alive=lambda: True)
        fake_process = SimpleNamespace(name='thumbnail process', is_alive=lambda: True)
        with patch('uploader.update_ui.threading.enumerate', return_value=[threading.main_thread(), fake_thread]), patch('uploader.update_ui.multiprocessing.active_children', return_value=[fake_process]):
            self.assertEqual(controller.blockers(), ['connection_test_running', 'semantic_ai_install_running', 'connection', 'thumbnail process'])

    def test_hourly_check_state_is_independent_and_handles_clock_reversal(self) -> None:
        """Verify hourly check state is independent and handles clock reversal."""
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'state.json'
            self.assertTrue(check_due(path, 100))
            path.write_text('{"last_check": 100}', encoding='utf-8')
            self.assertFalse(check_due(path, 101))
            self.assertFalse(check_due(path, 3699))
            self.assertTrue(check_due(path, 3700))
            self.assertTrue(check_due(path, 99))
            path.write_text('broken', encoding='utf-8')
            self.assertTrue(check_due(path, 101))

    def test_successful_no_offer_clears_persistent_indicator(self) -> None:
        """A completed clean check removes the app-wide update indicator.

        Returns:
            None: Confirms the accepted no-offer transition clears the indicator.
        """
        controller = self.controller()
        controller.app.set_update_available.reset_mock()
        controller.window = object()
        controller.events.put(('checked', None))
        controller.poll()
        controller.app.set_update_available.assert_called_once_with(None)
        controller.action.configure.assert_any_call(
            state='disabled', text='Update', command=controller.download
        )
        self.assertIsNone(controller.asset)

    def test_checked_event_then_done_enables_offer_after_busy_clears(self) -> None:
        """Production checked-before-done ordering eventually enables the offer.

        Returns:
            None: Confirms completion reconciles the offer after busy becomes false.
        """
        controller = self.controller()
        controller.busy = True
        controller.show = Mock()
        controller.installer = None
        asset = ReleaseAsset(
            '1.2.3', 'PHPGalleryUploader-1.2.3-Setup.exe',
            'https://github.com/klusik/PHP_gallery/releases/download/v1/PHPGalleryUploader-1.2.3-Setup.exe',
            100, 'a' * 64, 1, 1,
        )
        controller.events.put(('checked', asset))
        controller.events.put(('done', None))
        controller.poll()
        self.assertFalse(controller.busy)
        self.assertIn(asset.version, controller.status.set.call_args.args[0])
        controller.action.configure.assert_any_call(
            state='normal', text='Update', command=controller.download
        )

    def test_error_then_done_keeps_failure_status_and_retained_offer(self) -> None:
        """Worker completion re-enables a retained offer without hiding failure text.

        Returns:
            None: Confirms the error remains visible and the old offer is usable.
        """
        controller = self.controller()
        controller.busy = True
        controller.window = object()
        controller.installer = None
        controller.events.put(('error', 'offline'))
        controller.events.put(('done', None))
        controller.poll()
        self.assertFalse(controller.busy)
        self.assertEqual(controller.status.set.call_args.args[0], 'Update stopped: offline')
        controller.action.configure.assert_any_call(
            state='normal', text='Update', command=controller.download
        )

    def test_later_keeps_offer_and_manual_open_does_not_recheck(self) -> None:
        """Hiding a known offer preserves it and reopening avoids network work.

        Returns:
            None: Confirms Later and open reuse the known offer.
        """
        controller = self.controller()
        controller.show = Mock()
        controller.check = Mock()
        controller.installer = None
        controller.later()
        controller.asset = SimpleNamespace(version='1.2.3')
        controller.open_updates()
        controller.show.assert_called_once_with()
        controller.check.assert_not_called()
        self.assertEqual(controller.asset.version, '1.2.3')

    def test_manual_open_checks_only_when_idle_without_known_offer(self) -> None:
        """Manual open schedules a check only when no offer or operation exists.

        Returns:
            None: Confirms the idle manual-check request.
        """
        controller = self.controller()
        controller.show = Mock()
        controller.installer = None
        controller.check = Mock()
        controller.asset = None
        controller.installer = None
        controller.open_updates()
        controller.check.assert_called_once_with(automatic=False)

    def test_close_permission_preserves_app_wide_update_indicator(self) -> None:
        """An allowed close decision leaves indicator cleanup to the app owner.

        Returns:
            None: Confirms updater admission does not mutate app-wide state.
        """
        controller = self.controller()
        controller.app.set_update_available('1.2.3')
        controller.app.set_update_available.reset_mock()
        self.assertFalse(controller.request_close())
        controller.app.set_update_available.assert_not_called()

    def test_gated_close_preserves_app_wide_update_indicator(self) -> None:
        """A refused close during install preparation preserves the indicator.

        Returns:
            None: Confirms install gating only presents the updater notice.
        """
        controller = self.controller()
        controller.app.set_update_available('1.2.3')
        controller.app.set_update_available.reset_mock()
        controller.gate.begin(0)
        controller.show = Mock()
        self.assertTrue(controller.request_close())
        controller.show.assert_called_once_with()
        controller.app.set_update_available.assert_not_called()

    def test_failed_recheck_preserves_cached_offer_and_advances_throttle(self) -> None:
        """An offline check updates last_check without discarding a verified offer.

        Returns:
            None: Confirms the failed I/O leaves verified offer metadata intact.
        """
        controller = self.controller()
        controller.show = Mock()
        controller.installer = None
        asset = ReleaseAsset(
            '1.2.3', 'PHPGalleryUploader-1.2.3-Setup.exe',
            'https://github.com/klusik/PHP_gallery/releases/download/v1/PHPGalleryUploader-1.2.3-Setup.exe',
            100, 'a' * 64, 1, 1,
        )
        controller.asset = asset
        controller.app.set_update_available(asset.version)
        controller.app.set_update_available.reset_mock()
        with tempfile.TemporaryDirectory() as directory:
            controller.state_path = Path(directory) / 'state.json'
            controller.state_path.write_text(json.dumps({
                'last_check': 1,
                'version': controller.version,
                'available': asdict(asset),
            }), encoding='utf-8')
            operations: list[Callable[[], None]] = []
            controller.run_worker = operations.append
            with patch('uploader.update_ui.time.time', return_value=200):
                controller.check(automatic=False)
            self.assertEqual(len(operations), 1)
            with patch('uploader.self_update.find_update', side_effect=OSError('offline')):
                with self.assertRaisesRegex(OSError, 'offline'):
                    operations[0]()
            saved = json.loads(controller.state_path.read_text(encoding='utf-8'))
            self.assertEqual(saved['last_check'], 200)
            self.assertEqual(saved['available'], asdict(asset))
            self.assertEqual(controller.asset, asset)
            controller.app.set_update_available.assert_not_called()

    def test_cache_write_failure_keeps_verified_installer(self) -> None:
        """A failed replacement cache write cannot retire the usable installer.

        Returns:
            None: Confirms cleanup follows successful metadata persistence.
        """
        from uploader.self_update import _record_download_file, create_download_session

        controller = self.controller()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            controller.state_path = root / 'state.json'
            session = create_download_session(root)
            installer = session / 'uploader-update-abcdefgh.exe'
            installer.write_bytes(b'verified setup')
            _record_download_file(session, installer.name)
            controller.installer = installer
            operations: list[Callable[[], None]] = []
            controller.run_worker = operations.append
            with patch('uploader.update_ui.time.time', return_value=4000):
                controller.check(automatic=True)
            asset = ReleaseAsset(
                '1.2.3', 'PHPGalleryUploader-1.2.3-Setup.exe',
                'https://github.com/klusik/PHP_gallery/releases/download/v1/PHPGalleryUploader-1.2.3-Setup.exe',
                100, 'a' * 64, 1, 1,
            )
            with patch('uploader.self_update.find_update', return_value=asset), \
                    patch('uploader.update_ui.atomic_write_json', side_effect=[None, OSError('cache write failed')]), \
                    patch('uploader.self_update.retire_download_session') as retire:
                with self.assertRaisesRegex(OSError, 'cache write failed'):
                    operations[0]()
            retire.assert_not_called()
            self.assertTrue(installer.exists())

    def test_cleanup_failure_still_accepts_check_and_clears_stale_installer(self) -> None:
        """Cleanup failure warns but does not reject the completed check result.

        Returns:
            None: Confirms metadata acceptance clears the stale installer reference.
        """
        from uploader.self_update import _record_download_file, create_download_session

        controller = self.controller()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            controller.state_path = root / 'state.json'
            session = create_download_session(root)
            installer = session / 'uploader-update-abcdefgh.exe'
            installer.write_bytes(b'verified setup')
            _record_download_file(session, installer.name)
            controller.installer = installer

            def capture(operation: Callable[[], None]) -> None:
                """Capture the worker body and mirror its busy state.

                Args:
                    operation (Callable[[], None]): Background check body.
                Returns:
                    None: Stores the operation for deterministic event ordering.
                """
                controller.busy = True
                operation()

            controller.run_worker = capture
            with patch('uploader.update_ui.time.time', return_value=4000), \
                    patch('uploader.self_update.find_update', return_value=None), \
                    patch('uploader.self_update.retire_download_session', side_effect=OSError('cleanup refused')):
                controller.check(automatic=True)
            controller.events.put(('done', None))
            controller.poll()
            saved = json.loads(controller.state_path.read_text(encoding='utf-8'))
        self.assertIsNone(controller.installer)
        self.assertIsNone(controller.asset)
        self.assertIsNone(saved['available'])
        controller.app.set_update_available.assert_called_once_with(None)
        controller.app.write_log.assert_any_call(
            'Previous installer cleanup refused: cleanup refused', 'warning'
        )

    def test_cached_offer_restores_after_restart_through_validator(self) -> None:
        """A new process restores only a cache matching its current version.

        Returns:
            None: Confirms the validator-approved cached release is published.
        """
        controller = self.controller()
        controller.installer = None
        asset = ReleaseAsset(
            '1.2.3', 'PHPGalleryUploader-1.2.3-Setup.exe',
            'https://github.com/klusik/PHP_gallery/releases/download/v1/PHPGalleryUploader-1.2.3-Setup.exe',
            100, 'a' * 64, 1, 1,
        )
        with tempfile.TemporaryDirectory() as directory:
            controller.state_path = Path(directory) / 'state.json'
            controller.state_path.write_text(json.dumps({
                'last_check': 100,
                'version': controller.version,
                'available': asdict(asset),
            }), encoding='utf-8')
            controller.restore_cached_offer()
        self.assertEqual(controller.asset, asset)
        controller.app.set_update_available.assert_called_once_with(asset.version)

    def test_cached_offer_after_offline_startup_opens_actionable_notice(self) -> None:
        """A restored offer remains usable after a failed fresh check.

        Returns:
            None: The new notice shows the cached version/action and is placed
                nonmodally over its invoking window on the secondary monitor.
        """
        controller = self.controller()
        controller.installer = None
        asset = ReleaseAsset(
            '1.2.3', 'PHPGalleryUploader-1.2.3-Setup.exe',
            'https://github.com/klusik/PHP_gallery/releases/download/v1/PHPGalleryUploader-1.2.3-Setup.exe',
            100, 'a' * 64, 1, 1,
        )
        with tempfile.TemporaryDirectory() as directory:
            controller.state_path = Path(directory) / 'state.json'
            controller.state_path.write_text(json.dumps({
                'last_check': 1,
                'version': controller.version,
                'available': asdict(asset),
            }), encoding='utf-8')
            controller.restore_cached_offer()
            operations: list[Callable[[], None]] = []
            controller.run_worker = lambda operation: (operations.append(operation), setattr(controller, 'busy', True))
            with patch('uploader.update_ui.time.time', return_value=4000):
                controller.check(automatic=True)
            with patch('uploader.self_update.find_update', side_effect=OSError('offline')):
                with self.assertRaisesRegex(OSError, 'offline'):
                    operations[0]()
            controller.events.put(('error', 'offline'))
            controller.events.put(('done', None))
            controller.poll()

            window = Mock()
            window.winfo_reqwidth.return_value = 400
            window.winfo_reqheight.return_value = 200
            status = Mock()
            button = Mock()
            ttk_stub = SimpleNamespace(
                Label=Mock(return_value=Mock()),
                Progressbar=Mock(return_value=Mock()),
                Frame=Mock(return_value=Mock()),
                # Update, Cancel and Later are distinct native controls.
                Button=Mock(side_effect=[button, Mock(), Mock()]),
            )
            tkinter_stub = SimpleNamespace(
                Toplevel=Mock(return_value=window),
                StringVar=Mock(return_value=status),
                ttk=ttk_stub,
            )
            with patch.dict(sys.modules, {'tkinter': tkinter_stub}):
                controller.open_updates()
            self.assertEqual(len(operations), 1)
            self.assertIs(controller.window, window)
            window.transient.assert_called_once_with(controller.root)
            window.geometry.assert_called_once_with('400x200+3250+1200')
            window.grab_set.assert_not_called()
            shown_text = str(status.set.call_args.args[0])
            self.assertIn(asset.version, shown_text)
            button.configure.assert_any_call(
                state='normal', text='Update', command=controller.download
            )

    def test_cold_start_fresh_check_completes_after_done_event(self) -> None:
        """A cold automatic check validates and presents its first release.

        Returns:
            None: Confirms fresh cache write and checked/done UI completion.
        """
        controller = self.controller()
        controller.installer = None
        controller.asset = None
        controller.show = Mock()
        asset = ReleaseAsset(
            '1.2.3', 'PHPGalleryUploader-1.2.3-Setup.exe',
            'https://github.com/klusik/PHP_gallery/releases/download/v1/PHPGalleryUploader-1.2.3-Setup.exe',
            100, 'a' * 64, 1, 1,
        )
        with tempfile.TemporaryDirectory() as directory:
            controller.state_path = Path(directory) / 'state.json'
            operations: list[Callable[[], None]] = []

            def capture(operation: Callable[[], None]) -> None:
                """Capture the worker operation and mirror its busy admission state.

                Args:
                    operation (Callable[[], None]): Background check body.
                Returns:
                    None: Stores the operation for deterministic execution.
                """
                operations.append(operation)
                controller.busy = True

            controller.run_worker = capture
            with patch('uploader.update_ui.time.time', return_value=4000):
                controller.automatic_check()
            self.assertTrue(controller.cached_offer_loaded)
            with patch('uploader.self_update.find_update', return_value=asset):
                operations[0]()
            controller.events.put(('done', None))
            controller.poll()
            stored = json.loads(controller.state_path.read_text(encoding='utf-8'))
        self.assertEqual(stored['available'], asdict(asset))
        self.assertFalse(controller.busy)
        self.assertIn(asset.version, controller.status.set.call_args.args[0])
        controller.action.configure.assert_any_call(
            state='normal', text='Update', command=controller.download
        )

    def test_actual_update_version_clears_old_cached_offer(self) -> None:
        """A newer installed app version invalidates its old-process offer cache.

        Returns:
            None: Confirms the stale cache is cleared for the current version.
        """
        controller = self.controller()
        controller.version = '1.2.3'
        old_asset = ReleaseAsset(
            '1.2.0', 'PHPGalleryUploader-1.2.0-Setup.exe',
            'https://github.com/klusik/PHP_gallery/releases/download/v1/PHPGalleryUploader-1.2.0-Setup.exe',
            100, 'a' * 64, 1, 1,
        )
        with tempfile.TemporaryDirectory() as directory:
            controller.state_path = Path(directory) / 'state.json'
            controller.state_path.write_text(json.dumps({
                'last_check': 100,
                'version': '1.0.0',
                'available': asdict(old_asset),
            }), encoding='utf-8')
            controller.restore_cached_offer()
            cached = json.loads(controller.state_path.read_text(encoding='utf-8'))
        self.assertIsNone(cached['available'])
        self.assertEqual(cached['version'], controller.version)
        controller.app.set_update_available.assert_called_once_with(None)

    def test_close_cancels_download_and_waits_for_done_event(self) -> None:
        """Shutdown waits for worker completion without changing the indicator.

        Returns:
            None: Confirms cancellation precedes close and preserves app-owned state.
        """
        controller = self.controller()
        controller.cancel = threading.Event()
        controller.busy = True
        controller.app.close = Mock()
        controller.app.set_update_available('1.2.3')
        controller.app.set_update_available.reset_mock()
        self.assertTrue(controller.request_close())
        self.assertTrue(controller.cancel.is_set())
        controller.app.close.assert_not_called()
        controller.events.put(('done', None))
        controller.poll()
        controller.app.close.assert_called_once()
        self.assertFalse(controller.auto_install)
        controller.app.set_update_available.assert_not_called()

    def test_handoff_does_not_emit_ready_before_helper_acknowledgment(self) -> None:
        """A failed helper readiness handshake cannot trigger application shutdown."""
        controller = self.controller()
        with patch('uploader.update_helper.prepare_helper', return_value=Path('ticket.json')), patch('uploader.update_helper.launch_helper', side_effect=RuntimeError('not ready')):
            with self.assertRaisesRegex(RuntimeError, 'not ready'):
                controller.handoff()
        self.assertTrue(controller.events.empty())
        controller.root.destroy.assert_not_called()

    def test_launcher_admission_guards_preserve_method_docstrings(self) -> None:
        """Every work-entry handler refuses new work during installation drain."""
        import ast
        source = (Path(__file__).resolve().parents[1] / 'gallery_watch_upload.pyw').read_text(encoding='utf-8')
        tree = ast.parse(source)
        app = next(node for node in tree.body if isinstance(node, ast.ClassDef) and node.name == 'WatcherApp')
        names = ('start', 'start_manual_upload', 'start_ai_worker', 'launch_manual_job', 'resume_manual_upload', 'retry_failed_import', 'rebuild_manual_preflight', 'test_connection', 'revoke_api_key', 'repair_dependencies', 'repair_semantic_ai_dependencies', 'save_config')
        for name in names:
            method = next(node for node in app.body if isinstance(node, ast.FunctionDef) and node.name == name)
            self.assertIsNotNone(ast.get_docstring(method), name)
            self.assertIsInstance(method.body[1], ast.If, name)
            self.assertIn('self.updates.blocked()', ast.unparse(method.body[1].test), name)
        connection = next(node for node in app.body if isinstance(node, ast.FunctionDef) and node.name == 'open_connection_dialog')
        callback = next(node for node in connection.body if isinstance(node, ast.FunctionDef) and node.name == 'apply_values')
        self.assertIn('self.updates.blocked()', ast.unparse(callback.body[1].test))

    def test_nonfinite_and_oversized_throttle_state_recovers(self) -> None:
        """NaN/infinity and oversized caches cannot suppress future automatic checks."""
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'state.json'
            for number in ('NaN', 'Infinity', '-Infinity', '"NaN"', '"inf"'):
                path.write_text('{"last_check": ' + number + '}', encoding='utf-8')
                self.assertTrue(check_due(path, 100), number)
            path.write_text('{"last_check": 100, "extra": "' + 'x' * 17000 + '"}', encoding='utf-8')
            self.assertTrue(check_due(path, 101))

    def test_download_session_preserves_unknown_content_and_unmarked_directory(self) -> None:
        """Even correctly named directories cannot authorize deletion of unrelated data."""
        from uploader.self_update import create_download_session, retire_download_session, UpdateError
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            unknown = root / ('download-' + 'a' * 32)
            unknown.mkdir()
            keep = unknown / 'precious.txt'
            keep.write_text('keep', encoding='utf-8')
            with self.assertRaises(UpdateError):
                retire_download_session(unknown, root)
            self.assertEqual(keep.read_text(encoding='utf-8'), 'keep')
            owned = create_download_session(root)
            extra = owned / 'uploader-update-abcdefgh.exe'
            extra.write_bytes(b'unregistered file')
            with self.assertRaises(UpdateError):
                retire_download_session(owned, root)
            self.assertTrue(extra.exists())
            self.assertTrue((owned / 'ownership.json').exists())

    def test_owned_download_session_retirement_is_precise(self) -> None:
        """Only files registered by the download workflow and its marker are removed."""
        from uploader.self_update import create_download_session, retire_download_session, _record_download_file
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            owned = create_download_session(root)
            installer = owned / 'uploader-update-abcdefgh.exe'
            installer.write_bytes(b'fixture')
            _record_download_file(owned, installer.name)
            retire_download_session(owned, root)
            self.assertFalse(owned.exists())
            self.assertTrue(root.exists())

    def test_reparse_ancestor_refuses_session_cleanup(self) -> None:
        """Canonical storage inspection protects every ancestor before deletion."""
        from uploader.self_update import create_download_session, retire_download_session, UpdateError
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            owned = create_download_session(root)
            with patch('uploader.update_helper.reject_reparse_path', side_effect=ValueError('reparse')):
                with self.assertRaises(UpdateError):
                    retire_download_session(owned, root)
            self.assertTrue((owned / 'ownership.json').exists())
            with self.assertRaises(UpdateError):
                retire_download_session(owned, root / 'other-owner')
            self.assertTrue(owned.exists())

    def test_invalid_cached_metadata_is_not_an_offer(self) -> None:
        """Cached URLs, versions, sizes and missing hashes receive public trust checks."""
        from uploader.self_update import validated_release_asset, UpdateError
        from dataclasses import asdict
        payload = asdict(ReleaseAsset('1.2.3', 'PHPGalleryUploader-1.2.3-Setup.exe', 'https://github.com/klusik/PHP_gallery/releases/download/v1/PHPGalleryUploader-1.2.3-Setup.exe', 100, 'a' * 64, 1, 1))
        for field, value in (('download_url', 'https://example.invalid/setup.exe'), ('sha256', ''), ('size', float('nan')), ('version', '0.0.1')):
            changed = dict(payload)
            changed[field] = value
            with self.assertRaises(UpdateError, msg=field):
                validated_release_asset(changed, '1.0.0')

    def test_cancel_after_verification_retires_session(self) -> None:
        """A cancel arriving during verification cannot enqueue installer readiness."""
        from uploader.self_update import _record_download_file, UpdateCancelled
        controller = self.controller()
        controller.run_worker = lambda operation: operation()
        def fixture_download(asset: ReleaseAsset, directory: Path, progress: Callable[[int, int], None], cancel: threading.Event) -> Path:
            """Create only a registered harmless generated file inside the owned session."""
            path = directory / 'uploader-update-abcdefgh.exe'
            path.write_bytes(b'fixture')
            _record_download_file(directory, path.name)
            return path
        with tempfile.TemporaryDirectory() as directory:
            controller.state_path = Path(directory) / 'state.json'
            with patch('uploader.self_update.download_update', side_effect=fixture_download), patch('uploader.self_update.verify_installer', side_effect=lambda path, asset: controller.cancel.set()):
                with self.assertRaises(UpdateCancelled):
                    controller.download()
            self.assertEqual(list(Path(directory).iterdir()), [])
            self.assertFalse(any(kind == 'downloaded' for kind, value in list(controller.events.queue)))

    def test_cancel_before_processing_downloaded_does_not_install(self) -> None:
        """Late cancellation rejects installer handoff without clearing an offer.

        Returns:
            None: Confirms cancellation retires the download and retains the offer.
        """
        from uploader.self_update import create_download_session
        controller = self.controller()
        controller.install = Mock()
        with tempfile.TemporaryDirectory() as directory:
            controller.state_path = Path(directory) / 'state.json'
            owned = create_download_session(Path(directory))
            controller.cancel.set()
            existing_asset = controller.asset
            controller.events.put(('downloaded', owned / 'uploader-update-abcdefgh.exe'))
            controller.events.put(('done', None))
            controller.poll()
            controller.install.assert_not_called()
            self.assertFalse(controller.auto_install)
            self.assertIs(controller.asset, existing_asset)
            controller.app.set_update_available.assert_not_called()
            self.assertFalse(owned.exists())

    def test_ready_handoff_retires_only_its_owned_download(self) -> None:
        """Successful helper readiness retires the download while keeping its owner root."""
        from uploader.self_update import create_download_session, _record_download_file
        controller = self.controller()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            controller.state_path = root / 'state.json'
            owned = create_download_session(root)
            controller.installer = owned / 'uploader-update-abcdefgh.exe'
            controller.installer.write_bytes(b'fixture')
            _record_download_file(owned, controller.installer.name)
            with patch('uploader.update_helper.prepare_helper', return_value=Path('ticket.json')), patch('uploader.update_helper.launch_helper'):
                controller.handoff()
            self.assertFalse(owned.exists())
            self.assertTrue(root.exists())
            self.assertEqual(controller.events.get_nowait(), ('handoff_ready', None))


if __name__ == '__main__':
    unittest.main()
