# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watcher_inspection_integration.py
# Module Type: Regression Test
# Purpose: Keep explicit Watcher folder inspection separate from live state.
# Responsibilities:
#   - Verify inspection runs independently of queue/UI status variables.
#   - Preserve a persisted queue byte-for-byte during the magnifier workflow.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Verify the Watcher inspection action is read-only and off the Tk thread."""

import hashlib
import importlib.machinery
import importlib.util
import queue
import sys
import tempfile
import unittest
from pathlib import Path
from types import ModuleType, SimpleNamespace
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.watch_queue import WatchQueueStore


def load_main_module() -> ModuleType:
    """Load the source launcher as an importable test module.

    Returns:
        ModuleType: Main uploader module with its Tk entrypoint unstarted.
    """
    loader = importlib.machinery.SourceFileLoader(
        'gallery_inspection_integration_test',
        str(WINAPP_DIR / 'gallery_watch_upload.pyw'),
    )
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        raise RuntimeError('Watcher test module could not be loaded.')
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    loader.exec_module(module)
    return module


MAIN = load_main_module()


class WatcherInspectionIntegrationTests(unittest.TestCase):
    """Ensure the folder magnifier never mutates capture admission state."""

    def test_inspection_does_not_change_live_status_or_persisted_journal(self) -> None:
        """Run read-only inspection while forbidden status writes fail loudly.

        Returns:
            None: Findings are event-queued and journal bytes remain identical.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / 'photos'
            folder.mkdir()
            (folder / 'one.jpg').write_bytes(b'image')
            journal_path = root / 'session' / 'watch-drain.json'
            journal = WatchQueueStore(journal_path, 'a' * 64, folder, False)
            journal.initialize([])
            from uploader.watch_queue import observe_watch_source
            for index in range(81):
                path = folder / f'{index:03}.jpg'
                path.write_bytes(f'image-{index}'.encode('ascii'))
            journal.reconcile([
                observe_watch_source(folder / f'{index:03}.jpg', float(index + 1))
                for index in range(81)
            ])
            self.assertEqual(81, len(journal.items()))
            head = journal.head()
            self.assertIsNotNone(head)
            journal.update(
                head, state='uncertain', phase='transport', http_status=503,
                reason='Proxy HTTP 503; dispatch may have begun.',
            )
            before = hashlib.sha256(journal_path.read_bytes()).digest()

            class ForbiddenStatusVariable:
                """Fail if inspection touches live Watcher status controls."""

                def set(self, value: str) -> None:
                    """Reject an attempted status update.

                    Args:
                        value (str): Unexpected proposed UI value.

                    Returns:
                        None: Always raises to expose forbidden coupling.
                    """
                    raise AssertionError('Inspection changed live Watcher status.')

            app = object.__new__(MAIN.WatcherApp)
            app.watched_folder_var = SimpleNamespace(get=lambda: str(folder))
            app.watch_recursive_var = SimpleNamespace(get=lambda: False)
            app.watch_ignored_var = ForbiddenStatusVariable()
            app.watch_last_scan_var = ForbiddenStatusVariable()
            app.events = queue.Queue()
            thread_spy = SimpleNamespace(start=lambda: None)
            with mock.patch.object(MAIN.threading, 'Thread', return_value=thread_spy) as start:
                MAIN.WatcherApp.dry_run_watch_folder(app)
                start.assert_called_once()
                start.call_args.kwargs['target']()

            event = app.events.get_nowait()
            self.assertEqual('watch_folder_inspection_complete', event['kind'])
            self.assertEqual(82, event['payload']['supported'])
            self.assertEqual(81, len(journal.items()))
            self.assertEqual(before, hashlib.sha256(journal_path.read_bytes()).digest())

    def test_dead_worker_rebuilds_review_owner_for_same_journal(self) -> None:
        """Never reuse a stopped worker's released recovery leases.

        Returns:
            None: Review-only recovery resumes the original journal in a new owner.
        """
        directory = Path('C:/fixture/session')
        stale = SimpleNamespace(
            is_alive=lambda: False,
            recovery_coordinator=object(),
            drain_manifest=directory / 'watch-drain.json',
        )
        resumed = SimpleNamespace()
        scheduled: list[tuple[int, object]] = []
        calls: list[tuple[Path, bool]] = []
        app = object.__new__(MAIN.WatcherApp)
        app.worker = stale
        app.exiting = False
        app.root = SimpleNamespace(after=lambda delay, callback: scheduled.append(
            (delay, callback),
        ))
        app.write_log = lambda message, level='info': None
        app.resume_flight_photos = lambda path, review_only=False: (
            calls.append((path, review_only)) or resumed
        )
        MAIN.WatcherApp.open_watch_recovery_review(app)
        self.assertEqual([(directory, True)], calls)
        self.assertIs(resumed, app.worker)
        self.assertEqual(1, len(scheduled))
