# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watch_recovery_integration.py
# Module Type: Integration Regression Test
# Purpose: Verify recovery decisions against real watcher and linked-state owners.
# Responsibilities:
#   - Exercise real leases, queue publications and linked uncertainty history.
#   - Keep transport synthetic while asserting FIFO dispatch and receipt fences.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise Watcher recovery with production queue, state and lease owners."""

import queue
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from types import ModuleType, SimpleNamespace
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from test_flight_watch_drain import load_main_module
from uploader.models import JsonValue
from uploader.watch_queue import WatchQueueItem, observe_watch_source


class WatchRecoveryIntegrationTests(unittest.TestCase):
    """Verify recovery against linked state and the actual FIFO consumer."""

    @classmethod
    def setUpClass(cls) -> None:
        """Load the production desktop source without starting its GUI.

        Returns:
            None: Keeps the real WatcherThread and LinkedUploadState owners.
        """
        cls.main: ModuleType = load_main_module()

    def setUp(self) -> None:
        """Create isolated linked profile, source directory and real target state.

        Returns:
            None: Prepares a private profile and records original cleanup actions.
        """
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.folder = self.root / 'photos'
        self.folder.mkdir()
        self.profile = self.root / 'profile'
        self.profile.mkdir()
        config_patch = mock.patch.object(self.main, 'CONFIG_DIR', self.profile)
        config_patch.start()
        self.addCleanup(config_patch.stop)

        def target_binding() -> str:
            """Return this fixture's exact linked target fingerprint.

            Returns:
                str: Synthetic non-secret account/gallery binding.
            """
            return 'b' * 64

        def approved_scope() -> str:
            """Return the upload permission approved for this fixture target.

            Returns:
                str: Synthetic upload-only scope.
            """
            return 'upload'

        def can_upload() -> bool:
            """Report that the fixture target is authorized for upload.

            Returns:
                bool: True for this isolated synthetic gallery.
            """
            return True

        def matches_issuer(issuer: str) -> bool:
            """Match the one synthetic canonical installation origin.

            Args:
                issuer (str): Installation origin checked by linked admission.

            Returns:
                bool: True only for the fixture's fixed HTTPS origin.
            """
            return issuer == 'https://gallery.example.test'

        self.target = SimpleNamespace(
            binding=target_binding,
            issuer='https://gallery.example.test',
            user_id=7,
            gallery_id=11,
            title='Synthetic linked target',
            watch_key='fixture-watch-key',
            session=SimpleNamespace(
                approved_scope=approved_scope, can_upload=can_upload,
                matches_issuer=matches_issuer,
            ),
        )
        self.state = self.main.LinkedUploadState(self.target)
        self.state.save()

    def make_worker(self, session_name: str) -> threading.Thread:
        """Construct a real linked Watcher with a private durable drain journal.

        Args:
            session_name (str): Stable synthetic session directory name.

        Returns:
            WatcherThread: Real production worker, not started as a thread.
        """
        native = mock.patch.object(
            self.main, 'SimConnectCameraClient', return_value=mock.Mock(),
        )
        native.start()
        self.addCleanup(native.stop)
        worker = self.main.WatcherThread(
            self.main.WatcherConfig(
                gallery_url='https://gallery.example.test', api_key='',
                watched_folder=str(self.folder), delete_uploaded_files=False,
                attach_sim_camera_metadata=False, stable_seconds=0.0,
            ), queue.Queue(), self.target,
        )
        self.addCleanup(worker._linked_lease.release)
        worker.configure_drain(self.root / session_name, [])
        worker.state = self.state
        worker.stability = mock.Mock()
        worker.stability.stable.return_value = True
        worker.remote_inventory = mock.Mock()
        worker.remote_inventory.refresh.return_value = True
        worker.remote_inventory.has_hash.return_value = False
        pipeline = self.main.UploadPipelineLease(self.profile)
        pipeline.acquire()
        self.addCleanup(pipeline.release)
        worker._pipeline_lease = pipeline
        worker._initialize_recovery_coordinator(pipeline)
        return worker

    def add_source(self, name: str, content: bytes) -> Path:
        """Create an ordinary synthetic original beneath the isolated folder.

        Args:
            name (str): Synthetic source basename.
            content (bytes): Fixture-only source content.

        Returns:
            Path: Created source path retained throughout the scenario.
        """
        path = self.folder / name
        path.write_bytes(content)
        return path

    def test_earlier_unknown_blocks_then_reviews_and_sends_same_head_once(self) -> None:
        """Review A without resolving it, then first-dispatch B in FIFO order.

        Returns:
            None: A remains retained and reviewed while B receives one success.
        """
        prior = self.add_source('prior-a.png', b'prior unknown original')
        prior_hash = self.main.sha256_file(prior)
        self.state.mark_uncertain(prior, prior_hash)
        current = self.add_source('current-b.png', b'never dispatched original')
        later = self.add_source('later-c.png', b'next fifo original')
        worker = self.make_worker('same-session')
        worker._queue.reconcile([
            observe_watch_source(current, 100.0),
            observe_watch_source(later, 101.0),
        ])
        original_head = worker._queue.head()
        assert original_head is not None
        dispatches: list[str] = []

        def transfer(
            url: str, path: Path, metadata: dict[str, str],
            file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Record B's synthetic network dispatch and return one success.

            Args:
                url (str): Synthetic upload endpoint.
                path (Path): Exact source admitted by the consumer.
                metadata (dict[str, str]): Optional simulator metadata fields.
                file_hash (str): SHA-256 supplied to the uploader.
                size (int): Expected original byte size.

            Returns:
                dict[str, JsonValue]: Positive upload acknowledgement envelope.
            """
            worker._mark_watch_dispatch()
            dispatches.append(path.name)
            return {
                'ok': True, 'success': True, 'gallery_id': 11,
                'uploaded': 1, 'scanned': 1, 'image_ids': [32],
                'filenames': [path.name], 'upload_evidence': 'fresh_insert_v1',
            }

        worker.upload_watched_file = transfer
        worker._consume_head(self.folder, 'https://gallery.example.test')
        blocked = worker._queue.head()
        assert blocked is not None
        self.assertEqual([], dispatches)
        self.assertEqual(original_head.item_id, blocked.item_id)
        self.assertEqual('blocked', blocked.state)
        self.assertEqual('prior_upload_review', blocked.phase)
        self.assertEqual('same-session', worker._queue.data['session_id'])

        snapshot = worker.recovery_coordinator.snapshot()
        assert snapshot is not None
        self.assertTrue(snapshot.review_blocked_by_earlier_upload)
        self.assertEqual(str(prior), snapshot.prior_blocker_path)
        self.assertEqual(prior_hash, snapshot.prior_blocker_sha256)
        worker.recovery_coordinator.review_earlier_blocker(snapshot)
        self.assertTrue(self.state.data['failures'][str(prior)]['reviewed'])
        self.assertTrue(prior.exists())
        self.assertEqual(original_head.item_id, worker._queue.head().item_id)

        worker._consume_head(self.folder, 'https://gallery.example.test')
        self.assertEqual(['current-b.png'], dispatches)
        self.assertEqual('acknowledged', worker._queue.items()[0].state)
        self.assertEqual('later-c.png', Path(worker._queue.head().source.path).name)
        self.assertTrue(current.exists())
        self.assertEqual('same-session', worker._queue.data['session_id'])

    def test_exact_receipt_mismatch_and_live_transport_refuse_confirmation(self) -> None:
        """Require matching queue ID/hash and idle owned transport for receipt ACK.

        Returns:
            None: Mismatched evidence and a live transport preserve the uncertain head.
        """
        source = self.add_source('uncertain.png', b'uncertain original')
        digest = self.main.sha256_file(source)
        self.state.mark_uncertain(source, digest)
        worker = self.make_worker('receipt-session')
        worker._queue.reconcile([observe_watch_source(source, 100.0)])
        head = worker._queue.head()
        assert head is not None
        head = worker._queue.update(
            head, state='uncertain', phase='upload', sha256=digest,
            reason='Upload response outcome unknown.',
        )
        worker.state.mark_uploaded(source, digest, source.stat().st_size, {
            'ok': True, 'success': True, 'gallery_id': 11,
            'uploaded': 1, 'scanned': 1, 'image_ids': [33],
            'filenames': [source.name], 'upload_evidence': 'fresh_insert_v1',
            'queue_item_id': head.item_id,
        })
        snapshot = worker.recovery_coordinator.snapshot()
        assert snapshot is not None
        path = worker.state.data['uploaded_paths'][str(source)]
        path['response']['queue_item_id'] = 'wrong-id'
        worker.state.save()
        self.assertFalse(worker.recovery_coordinator.can_confirm_receipt(snapshot))
        with self.assertRaises(ValueError):
            worker.recovery_coordinator.confirm_receipt(snapshot)

        path['response']['queue_item_id'] = head.item_id
        path['sha256'] = 'c' * 64
        worker.state.save()
        self.assertFalse(worker.recovery_coordinator.can_confirm_receipt(snapshot))

        path['sha256'] = digest
        worker.state.save()
        worker._pending_transport = threading.Event()
        self.assertFalse(worker._pending_transport.is_set())
        self.assertFalse(worker.recovery_coordinator.can_confirm_receipt(snapshot))
        with self.assertRaises(ValueError):
            worker.recovery_coordinator.confirm_receipt(snapshot)
        worker._pending_transport.set()
        self.assertTrue(worker.recovery_coordinator.can_confirm_receipt(snapshot))
        worker.recovery_coordinator.confirm_receipt(snapshot)
        self.assertEqual('acknowledged', worker._queue.items()[0].state)
        self.assertEqual('confirmed_by_receipt', worker._queue.items()[0].disposition)
        self.assertTrue(source.exists())
        worker._pipeline_lease._native.verify()
        worker._linked_lease._native.verify()

    def test_prior_review_commit_then_lost_queue_reply_repairs_same_marker(self) -> None:
        """Repair A review after linked commit while B queue readback was lost.

        Returns:
            None: Restart repeats the exact A review and preserves unrelated history.
        """
        prior = self.add_source('prior-a.png', b'prior unknown original')
        prior_hash = self.main.sha256_file(prior)
        self.state.mark_uncertain(prior, prior_hash)
        unrelated_path = str(self.folder / 'unrelated-reviewed.png')
        unrelated_hash = 'd' * 64
        self.state.data['failures'][unrelated_path] = {
            'sha256': unrelated_hash, 'uncertain': True,
            'reviewed': True, 'last_error': 'Retained unrelated marker.',
        }
        self.state.save()
        current = self.add_source('current-b.png', b'never dispatched original')
        worker = self.make_worker('repair-session')
        worker._queue.reconcile([observe_watch_source(current, 100.0)])
        worker._consume_head(self.folder, 'https://gallery.example.test')
        snapshot = worker.recovery_coordinator.snapshot()
        assert snapshot is not None

        original_publish = worker._queue._publish

        def lose_blocked_head_publication(row: dict[str, JsonValue]) -> None:
            """Fail B's queue write after the linked A review has committed.

            Args:
                row (dict[str, JsonValue]): Candidate complete queue revision.

            Returns:
                None: Publishes unrelated revisions and raises for B preparation.

            Raises:
                OSError: Simulates a failed queue publication after linked commit.
            """
            records = row.get('items', [])
            head_record = records[0] if records else {}
            if (row.get('recovery_intent') is not None
                    and isinstance(head_record, dict)
                    and head_record.get('state') == 'preparing'):
                raise OSError('simulated queue write failure after linked commit')
            original_publish(row)

        with mock.patch.object(
                worker._queue, '_publish', side_effect=lose_blocked_head_publication):
            with self.assertRaises(OSError):
                worker.recovery_coordinator.review_earlier_blocker(snapshot)
        self.assertTrue(self.state.data['failures'][str(prior)]['reviewed'])
        self.assertEqual('blocked', worker._queue.head().state)
        self.assertIn('recovery_intent', worker._queue.data)

        worker._linked_lease.release()
        worker._pipeline_lease.release()
        restarted = self.main.WatcherThread(
            self.main.WatcherConfig(
                gallery_url='https://gallery.example.test', api_key='',
                watched_folder=str(self.folder), delete_uploaded_files=False,
                attach_sim_camera_metadata=False, stable_seconds=0.0,
            ), queue.Queue(), self.target,
        )
        self.addCleanup(restarted._linked_lease.release)
        restarted.configure_drain(
            self.root / 'repair-session', [], resume=True,
        )
        restart_pipeline = self.main.UploadPipelineLease(self.profile)
        restart_pipeline.acquire()
        self.addCleanup(restart_pipeline.release)
        restarted._pipeline_lease = restart_pipeline
        restarted._initialize_recovery_coordinator(restart_pipeline)
        restarted.recovery_coordinator.repair_interrupted_decision()
        self.assertNotIn('recovery_intent', restarted._queue.data)
        self.assertEqual('preparing', restarted._queue.head().state)
        self.assertTrue(restarted.state.data['failures'][str(prior)]['reviewed'])
        self.assertEqual(
            {'sha256': unrelated_hash, 'uncertain': True, 'reviewed': True,
             'last_error': 'Retained unrelated marker.'},
            restarted.state.data['failures'][unrelated_path],
        )
        self.assertTrue(prior.exists())
        self.assertTrue(current.exists())
