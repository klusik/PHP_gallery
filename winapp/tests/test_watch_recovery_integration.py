# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watch_recovery_integration.py
# Module Type: Integration Regression Test
# Purpose: Verify recovery decisions against real watcher and linked-state owners.
# Responsibilities:
#   - Exercise real leases, queue publications and linked uncertainty history.
#   - Keep transport synthetic while asserting FIFO dispatch and receipt fences.
#   - Verify approved retries retain history and normal duplicate policy.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise Watcher recovery with production queue, state and lease owners."""

from copy import deepcopy
import queue
import os
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
from uploader.app_linking import LinkingResponseError
from uploader.capture_session import CaptureReview, inspect_watch_folder
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

    def test_remote_observation_requires_current_capture_and_never_confirms_head(self) -> None:
        """Exercise preview reads through the real linked queue and capture port.

        Returns:
            None: Current media may be observed, while cancellation, target
                replacement and missing receipt leave the journal unchanged.
        """
        worker = self.make_worker('optional-preview')
        source = self.add_source('pending.png', b'disposable source')
        journal = worker._queue
        journal.reconcile([observe_watch_source(source, 1.0)])
        head = journal.head()
        assert head is not None
        journal.update(head, state='uncertain', phase='upload', http_status=503)
        coordinator = worker.recovery_coordinator
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        review = CaptureReview(snapshot, False, False)
        port = SimpleNamespace(worker=worker)
        session = self.target.session
        session.media_page = mock.Mock(return_value=SimpleNamespace(
            photos=(SimpleNamespace(image_id=97),), next_after=None,
        ))
        session.media_photo = mock.Mock(return_value=(b'media', 'image/png'))
        cancelled = threading.Event()
        before = journal.path.read_bytes()
        read = self.main.DesktopCapture.review_remote
        self.assertEqual((b'media', 'observation'), read(port, review, cancelled))
        session.media_photo.assert_called_once_with(
            self.target.gallery_id, 97, True, cancelled=cancelled,
            cache_eligible=False, preload_eligible=False,
        )
        cancelled.set()
        self.assertIsNone(read(port, review, cancelled))
        session.media_page.assert_called_once()
        self.assertFalse(coordinator.can_confirm_receipt(snapshot))
        self.assertEqual(before, journal.path.read_bytes())

        def replace_target_during_read(
            gallery_id: int, image_id: int, thumbnail: bool, *,
            cancelled: threading.Event, cache_eligible: bool, preload_eligible: bool,
        ) -> tuple[bytes, str]:
            """Withdraw the target after guarded synthetic transport began.

            Args:
                gallery_id (int): Exact requested linked gallery.
                image_id (int): The last observed image in that gallery.
                thumbnail (bool): This route requests a bounded preview.
                cancelled (threading.Event): Review cancellation fence.
                cache_eligible (bool): Public cache admission from this read.
                preload_eligible (bool): Private preload admission from this read.

            Returns:
                tuple[bytes, str]: Media that is stale when returned to the worker.
            """
            worker.linked_target = None
            return b'late original preview', 'image/png'

        session.media_photo.side_effect = replace_target_during_read
        self.assertIsNone(read(port, review, threading.Event()))
        self.assertEqual(before, journal.path.read_bytes())
        self.assertTrue(source.exists())

    def test_503_after_sequence_443_keeps_all_later_sources_in_original_order(self) -> None:
        """Replay 81 pending originals through the real linked Watcher consumer.

        Returns:
            None: A post-dispatch 503 cannot replay the head or admit its successor.
                Inspection preserves the journal, and explicit skip allows #445.
        """
        # Exact historical sequence and later FIFO count from the reported incident.
        last_acknowledged = 443
        later_pending = 80
        head_sequence = last_acknowledged + 1
        paths = [
            self.add_source(f'{sequence:03}.png', f'original {sequence}'.encode('ascii'))
            for sequence in range(1, head_sequence + later_pending + 1)
        ]
        worker = self.make_worker('original-503-incident')
        journal = worker._queue
        journal.reconcile([
            observe_watch_source(path, float(sequence))
            for sequence, path in enumerate(paths, start=1)
        ])
        persisted = deepcopy(journal.data)
        for acknowledged in persisted['items'][:last_acknowledged]:
            acknowledged['state'] = 'acknowledged'
            acknowledged['sha256'] = 'a' * 64
            acknowledged['disposition'] = 'uploaded'
        persisted['pending'] = [
            item['source']['path'] for item in persisted['items'][last_acknowledged:]
        ]
        journal._publish(persisted)
        dispatches: list[Path] = []
        returned = threading.Event()
        returned.set()

        def transfer(
            url: str, path: Path, metadata: dict[str, str], file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Dispatch one original and simulate a proxy losing only the first reply.

            Args:
                url (str): Synthetic gallery installation endpoint.
                path (Path): Exact source admitted by the real queue.
                metadata (dict[str, str]): Additional request metadata captured by the worker.
                file_hash (str): Verified upload bytes from preparation.
                size (int): Exact byte count captured during preparation.

            Returns:
                dict[str, JsonValue]: Fresh insert acknowledgement for the next original.

            Raises:
                LinkingResponseError: The first request completed with ambiguous 503.
            """
            worker._mark_watch_dispatch()
            dispatches.append(path)
            if path == paths[last_acknowledged]:
                raise LinkingResponseError(
                    'linking', 'app_linking_upload', 503, 'html', 'http_status',
                    operation_finished=returned,
                )
            return {
                'ok': True, 'success': True, 'gallery_id': self.target.gallery_id,
                'uploaded': 1, 'scanned': 1, 'image_ids': [head_sequence + 1],
                'filenames': [path.name], 'upload_evidence': 'fresh_insert_v1',
            }

        worker.upload_watched_file = transfer
        worker._consume_head(self.folder, self.target.issuer)
        coordinator = worker.recovery_coordinator
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        self.assertEqual(
            (head_sequence, last_acknowledged, later_pending + 1, 'uncertain', 503),
            (snapshot.sequence, snapshot.last_confirmed_sequence, snapshot.pending_count,
             snapshot.state, snapshot.http_status),
        )
        before = journal.path.read_bytes()
        inspection = inspect_watch_folder(self.folder, {'.png'}, False, threading.Event())
        assert inspection is not None
        self.assertEqual(len(paths), inspection.supported)
        self.assertEqual(before, journal.path.read_bytes())
        worker._consume_head(self.folder, self.target.issuer)
        self.assertEqual([paths[last_acknowledged]], dispatches)
        self.assertFalse(coordinator.can_confirm_receipt(snapshot))

        coordinator.skip_head(snapshot)
        worker.apply_recovery_decision()
        worker._consume_head(self.folder, self.target.issuer)
        self.assertEqual(paths[last_acknowledged:last_acknowledged + 2], dispatches)
        items = journal.items()
        self.assertEqual('skipped_by_user', items[last_acknowledged].disposition)
        self.assertEqual('acknowledged', items[head_sequence].state)
        remaining = journal.head()
        assert remaining is not None
        self.assertEqual(head_sequence + 2, remaining.sequence)
        self.assertEqual(snapshot.session_id, journal.data['session_id'])
        self.assertTrue(all(path.is_file() for path in paths))

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

    def test_explicit_photo_approval_reuploads_once_and_keeps_unknown_history(self) -> None:
        """Retry the exact approved head through normal inventory and upload flow.

        Returns:
            None: The user approval is consumed once and old unknown evidence remains.
        """
        source = self.add_source('approved-uncertain.png', b'original uncertain bytes')
        digest = self.main.sha256_file(source)
        self.state.mark_uncertain(source, digest)
        original_failure = dict(self.state.data['failures'][str(source)])
        worker = self.make_worker('approved-session')
        worker._queue.reconcile([observe_watch_source(source, 100.0)])
        head = worker._queue.head()
        assert head is not None
        head = worker._queue.update(
            head, state='uncertain', phase='upload', sha256=digest,
            reason='Upload response outcome unknown.',
        )
        snapshot = worker.recovery_coordinator.snapshot()
        assert snapshot is not None
        self.assertTrue(worker.recovery_coordinator.can_approve_photo_upload(snapshot))
        worker.recovery_coordinator.approve_photo_upload(snapshot)
        archived = self.state.data['reviewed_upload_attempts']
        self.assertEqual(1, len(archived))
        self.assertEqual(original_failure, archived[0]['failure'])
        self.assertTrue(self.state.data['failures'][str(source)]['reviewed'])

        dispatches: list[str] = []

        def transfer(
            url: str, path: Path, metadata: dict[str, str],
            file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Record one normally checked retry and its fresh insert response.

            Args:
                url (str): Synthetic upload endpoint.
                path (Path): Exact source admitted by the queue.
                metadata (dict[str, str]): Optional camera metadata fields.
                file_hash (str): Hash checked by ordinary queue preparation.
                size (int): Source size checked by ordinary queue preparation.

            Returns:
                dict[str, JsonValue]: Synthetic successful insertion response.
            """
            worker._mark_watch_dispatch()
            dispatches.append(path.name)
            return {
                'ok': True, 'success': True, 'gallery_id': 11,
                'uploaded': 1, 'scanned': 1, 'image_ids': [34],
                'filenames': [path.name], 'upload_evidence': 'fresh_insert_v1',
            }

        worker.upload_watched_file = transfer
        worker._consume_head(self.folder, 'https://gallery.example.test')
        self.assertEqual(['approved-uncertain.png'], dispatches)
        self.assertIsNone(worker._queue.head())
        self.assertEqual('acknowledged', worker._queue.items()[0].state)
        self.assertIsNone(self.state.data['failures'].get(str(source)))
        self.assertEqual(original_failure, self.state.data['reviewed_upload_attempts'][0]['failure'])
        self.assertEqual('dispatched', worker._queue.data['approval_history'][0]['outcome'])
        self.assertTrue(source.exists())

    def test_explicit_approval_keeps_normal_remote_duplicate_skip(self) -> None:
        """Honor an ordinary remote duplicate found after explicit approval.

        Returns:
            None: No POST occurs and the matching queue item is normally acknowledged.
        """
        source = self.add_source('approved-duplicate.png', b'original duplicate bytes')
        digest = self.main.sha256_file(source)
        self.state.mark_uncertain(source, digest)
        worker = self.make_worker('approved-duplicate-session')
        worker._queue.reconcile([observe_watch_source(source, 100.0)])
        head = worker._queue.head()
        assert head is not None
        worker._queue.update(head, state='uncertain', phase='upload', sha256=digest)
        snapshot = worker.recovery_coordinator.snapshot()
        assert snapshot is not None
        worker.recovery_coordinator.approve_photo_upload(snapshot)
        worker.remote_inventory.has_hash.return_value = True
        dispatches: list[str] = []
        worker.upload_watched_file = mock.Mock(side_effect=AssertionError(
            'An ordinary remote duplicate must not be force-uploaded.',
        ))

        worker._consume_head(self.folder, 'https://gallery.example.test')

        self.assertEqual([], dispatches)
        self.assertIsNone(worker._queue.head())
        self.assertEqual('acknowledged', worker._queue.items()[0].state)
        self.assertEqual('duplicate', worker._queue.items()[0].disposition)
        self.assertIsNone(worker._queue.data['approved_upload'])
        self.assertEqual('resolved', worker._queue.data['approval_history'][0]['outcome'])
        self.assertTrue(source.exists())

    def test_approved_same_signature_replacement_keeps_original_hash_and_capability(self) -> None:
        """Reject changed bytes even when an in-place writer restores metadata.

        Returns:
            None: The queue retains the displayed SHA and refuses HTTP dispatch.
        """
        source = self.add_source('approved-same-signature.png', b'original bytes')
        digest = self.main.sha256_file(source)
        self.state.mark_uncertain(source, digest)
        worker = self.make_worker('approved-source-change-session')
        worker._queue.reconcile([observe_watch_source(source, 100.0)])
        head = worker._queue.head()
        assert head is not None
        worker._queue.update(head, state='uncertain', phase='upload', sha256=digest)
        snapshot = worker.recovery_coordinator.snapshot()
        assert snapshot is not None
        worker.recovery_coordinator.approve_photo_upload(snapshot)
        approved_source = worker._queue.head().source
        before = source.stat()
        source.write_bytes(b'replaced bytes')
        os.utime(source, ns=(before.st_atime_ns, before.st_mtime_ns))
        self.assertEqual(approved_source.signature,
                         observe_watch_source(source, 200.0).signature)
        worker.upload_watched_file = mock.Mock(side_effect=AssertionError(
            'Changed bytes must not be dispatched under the original approval.',
        ))

        worker._consume_head(self.folder, 'https://gallery.example.test')

        current = worker._queue.head()
        assert current is not None
        self.assertEqual('blocked', current.state)
        self.assertEqual(digest, current.sha256)
        self.assertIsNotNone(worker._queue.data['approved_upload'])

    def test_interrupted_photo_approval_repairs_linked_history_idempotently(self) -> None:
        """Repair a lost queue commit after the exact linked marker was archived.

        Returns:
            None: One approval is completed with one retained original failure.
        """
        source = self.add_source('approved-repair.png', b'uncertain source bytes')
        digest = self.main.sha256_file(source)
        self.state.mark_uncertain(source, digest)
        worker = self.make_worker('approved-repair-session')
        worker._queue.reconcile([observe_watch_source(source, 100.0)])
        head = worker._queue.head()
        assert head is not None
        worker._queue.update(head, state='uncertain', phase='upload', sha256=digest)
        snapshot = worker.recovery_coordinator.snapshot()
        assert snapshot is not None

        with mock.patch.object(worker._queue, 'approve_photo_upload', side_effect=OSError(
                'simulated queue write failure after linked approval')):
            with self.assertRaises(OSError):
                worker.recovery_coordinator.approve_photo_upload(snapshot)
        self.assertIn('recovery_intent', worker._queue.data)
        self.assertEqual(1, len(self.state.data['reviewed_upload_attempts']))

        worker.recovery_coordinator.repair_interrupted_decision()

        self.assertNotIn('recovery_intent', worker._queue.data)
        self.assertEqual(1, len(self.state.data['reviewed_upload_attempts']))
        self.assertEqual('preparing', worker._queue.head().state)
        self.assertTrue(worker._queue.approved_upload_matches(
            worker._queue.head(), 'linked',
        ))

    def test_unwritten_linked_approval_repairs_only_after_fresh_state_load(self) -> None:
        """Do not publish queue approval from an unpersisted in-memory archive.

        Returns:
            None: Same-instance repair remains blocked; a fresh owner commits once.
        """
        source = self.add_source('approved-unwritten.png', b'uncertain source bytes')
        digest = self.main.sha256_file(source)
        self.state.mark_uncertain(source, digest)
        worker = self.make_worker('approved-unwritten-session')
        worker._queue.reconcile([observe_watch_source(source, 100.0)])
        head = worker._queue.head()
        assert head is not None
        worker._queue.update(head, state='uncertain', phase='upload', sha256=digest)
        snapshot = worker.recovery_coordinator.snapshot()
        assert snapshot is not None

        with mock.patch.object(self.state, 'save', side_effect=OSError(
                'simulated failure before linked history write')):
            with self.assertRaises(OSError):
                worker.recovery_coordinator.approve_photo_upload(snapshot)
        self.assertIn('recovery_intent', worker._queue.data)
        self.assertIsNone(worker._queue.data['approved_upload'])
        self.assertEqual(1, len(self.state.data['reviewed_upload_attempts']))

        with self.assertRaises(ValueError):
            worker.recovery_coordinator.repair_interrupted_decision()
        self.assertIn('recovery_intent', worker._queue.data)
        self.assertIsNone(worker._queue.data['approved_upload'])

        fresh_state = self.main.LinkedUploadState(self.target)
        worker.state = fresh_state
        worker.recovery_coordinator.repair_interrupted_decision()

        self.assertNotIn('recovery_intent', worker._queue.data)
        self.assertEqual(1, len(fresh_state.data['reviewed_upload_attempts']))
        self.assertEqual('preparing', worker._queue.head().state)
        confirmed_state = self.main.LinkedUploadState(self.target)
        self.assertEqual(1, len(confirmed_state.data['reviewed_upload_attempts']))

    def test_ambiguous_approved_retry_requires_a_new_human_decision(self) -> None:
        """Never auto-repeat a second ambiguous attempt after consuming approval.

        Returns:
            None: One explicit decision permits at most one possibly-sent request.
        """
        source = self.add_source('approved-ambiguous.png', b'ambiguous retry bytes')
        digest = self.main.sha256_file(source)
        self.state.mark_uncertain(source, digest)
        worker = self.make_worker('approved-ambiguous-session')
        worker._queue.reconcile([observe_watch_source(source, 100.0)])
        head = worker._queue.head()
        assert head is not None
        worker._queue.update(head, state='uncertain', phase='upload', sha256=digest)
        snapshot = worker.recovery_coordinator.snapshot()
        assert snapshot is not None
        worker.recovery_coordinator.approve_photo_upload(snapshot)
        dispatches: list[str] = []

        def ambiguous_transfer(
            url: str, path: Path, metadata: dict[str, str],
            file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Persist write-ahead then report a possibly-sent failure.

            Args:
                url (str): Synthetic upload endpoint.
                path (Path): Exact explicitly approved source.
                metadata (dict[str, str]): Optional camera metadata fields.
                file_hash (str): Current source SHA-256.
                size (int): Current source size.

            Returns:
                dict[str, JsonValue]: Never returns because this attempt is ambiguous.

            Raises:
                RuntimeError: Represents a response that cannot prove non-delivery.
            """
            worker._mark_watch_dispatch()
            dispatches.append(path.name)
            raise RuntimeError('synthetic unknown response after dispatch')

        worker.upload_watched_file = ambiguous_transfer
        worker._consume_head(self.folder, 'https://gallery.example.test')
        self.assertEqual(['approved-ambiguous.png'], dispatches)
        self.assertEqual('uncertain', worker._queue.head().state)
        self.assertFalse(worker._queue.approved_upload_matches(worker._queue.head()))

        worker._consume_head(self.folder, 'https://gallery.example.test')

        self.assertEqual(['approved-ambiguous.png'], dispatches)
        second_snapshot = worker.recovery_coordinator.snapshot()
        assert second_snapshot is not None
        self.assertTrue(worker.recovery_coordinator.can_approve_photo_upload(second_snapshot))
        worker.recovery_coordinator.approve_photo_upload(second_snapshot)
        self.assertEqual(2, len(self.state.data['reviewed_upload_attempts']))

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
