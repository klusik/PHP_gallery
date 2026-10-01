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
        """Build an isolated controller with a fake root."""
        controller = UpdateController.__new__(UpdateController)
        controller.root = SimpleNamespace(after=Mock(), destroy=Mock())
        controller.app = SimpleNamespace(root=controller.root, exiting=False,
                                         stop_tray_icon=Mock(), write_log=Mock())
        controller.gate = InstallGate(timeout=10)
        controller.events = queue.Queue()
        controller.busy = False
        controller.close_pending = False
        controller.auto_install = False
        controller.window = None
        controller.action = SimpleNamespace(configure=Mock())
        controller.status = SimpleNamespace(set=Mock())
        controller.cancel_button = SimpleNamespace(configure=Mock())
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

    def test_close_cancels_download_and_waits_for_done_event(self) -> None:
        """Normal shutdown cannot abandon a download or launch a late installer."""
        controller = self.controller()
        controller.cancel = threading.Event()
        controller.busy = True
        controller.app.close = Mock()
        self.assertTrue(controller.request_close())
        self.assertTrue(controller.cancel.is_set())
        controller.app.close.assert_not_called()
        controller.events.put(('done', None))
        controller.poll()
        controller.app.close.assert_called_once()
        self.assertFalse(controller.auto_install)

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
        """Late main-thread cancellation rejects a queued verified installer event."""
        from uploader.self_update import create_download_session
        controller = self.controller()
        controller.install = Mock()
        with tempfile.TemporaryDirectory() as directory:
            controller.state_path = Path(directory) / 'state.json'
            owned = create_download_session(Path(directory))
            controller.cancel.set()
            controller.events.put(('downloaded', owned / 'uploader-update-abcdefgh.exe'))
            controller.events.put(('done', None))
            controller.poll()
            controller.install.assert_not_called()
            self.assertFalse(controller.auto_install)
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
