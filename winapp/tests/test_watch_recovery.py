# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watch_recovery.py
# Module Type: Regression Test
# Purpose: Verify explicit durable recovery decisions retain uncertain sources.
# Responsibilities:
#   - Exercise head-only confirmation and user skip across restart.
#   - Verify stale snapshots and missing receipt evidence are rejected.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise exact-head recovery decisions against durable queue publications."""

import sys
import tempfile
import threading
import unittest
from unittest import mock
import copy
import hashlib
from dataclasses import replace
from pathlib import Path

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.linked_upload import UploadPipelineLease
from uploader.watch_queue import WatchQueueItem, WatchQueueStore, observe_watch_source
from uploader.watch_recovery import WatchRecoveryCoordinator

# Purpose: Bound concurrency-fixture synchronization without a local test loop.
# Type: tuple[float,float]. Units: seconds. Scope: one gate regression.
# Consumers: test_decision_waits_for_worker_head_gate.
# Rationale: One second detects early execution; three seconds bounds thread join.
RECOVERY_GATE_FIXTURE_SECONDS = (1.0, 3.0)


def no_receipt(item: WatchQueueItem) -> bool:
    """Represent absent exact durable receipt evidence.

    Args:
        item (WatchQueueItem): Queue head being checked.

    Returns:
        bool: Always false for this negative fixture callback.
    """
    return False


def no_transport() -> bool:
    """Represent an idle transport in isolated model fixtures.

    Returns:
        bool: Always false because no HTTP request is active in the fixture.
    """
    return False


def ignore_linked_review(item: WatchQueueItem, disposition: str) -> None:
    """Accept a linked review without external state in model-only fixtures.

    Args:
        item (WatchQueueItem): Exact queue head supplied to the callback.
        disposition (str): Receipt confirmation or user skip.

    Returns:
        None: Leaves linked persistence outside this minimal callback.
    """


class WatchRecoveryTests(unittest.TestCase):
    """Keep uncertain originals until one explicit durable decision per head."""

    def setUp(self) -> None:
        """Create a private queue and a held profile lease for each scenario.

        Returns:
            None: Initializes isolated source files and durable queue state.
        """
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.folder = self.root / 'photos'
        self.folder.mkdir()
        self.config = self.root / 'config'
        self.config.mkdir()
        self.store = WatchQueueStore(
            self.root / 'session' / 'watch-drain.json', 'a' * 64,
            self.folder, False,
        )
        self.store.initialize([])
        self.lease = UploadPipelineLease(self.config)
        self.lease.acquire()
        self.addCleanup(self.lease.release)

    def coordinator(self, receipt: bool = False, active: bool = False) -> WatchRecoveryCoordinator:
        """Bind callbacks to the current synthetic recovery context.

        Args:
            receipt (bool): Whether the exact durable-receipt stub accepts the head.
            active (bool): Whether a transport is currently in flight.

        Returns:
            WatchRecoveryCoordinator: Recovery service bound to held local ownership.
        """
        def receipt_lookup(item: WatchQueueItem) -> bool:
            """Return the fixture's configured exact receipt result.

            Args:
                item (WatchQueueItem): Current queue head under review.

            Returns:
                bool: Whether this fixture permits exact receipt confirmation.
            """
            return receipt

        def transport_active() -> bool:
            """Return the fixture's configured live-transport state.

            Returns:
                bool: True while the fixture models an in-flight request.
            """
            return active

        def linked_review(item: WatchQueueItem, disposition: str) -> None:
            """Accept one fixture linked-history review publication.

            Args:
                item (WatchQueueItem): Exact queue head under review.
                disposition (str): Explicit receipt or skip disposition.

            Returns:
                None: Leaves external linked-state behavior at this test boundary.
            """

        return WatchRecoveryCoordinator(
            self.store, self.lease, None, receipt_lookup, transport_active,
            'a' * 64, 'legacy', linked_review,
        )

    def add_items(self, count: int) -> list[Path]:
        """Admit an ordered set of concrete original files.

        Args:
            count (int): Number of deterministic source generations to admit.

        Returns:
            list[Path]: Paths that must remain present through recovery.
        """
        paths = []
        for index in range(count):
            path = self.folder / f'{index:03}.jpg'
            path.write_bytes(b'original')
            paths.append(path)
        self.store.reconcile([
            observe_watch_source(path, float(index + 1))
            for index, path in enumerate(paths)
        ])
        first = self.store.head()
        assert first is not None
        self.store.update(first, state='uncertain', reason='Outcome unknown.')
        return paths

    def test_81_items_skip_retains_source_and_advances_only_head(self) -> None:
        """Reproduce ACK #443, unknown #444 and 80 newer accepted photographs.

        Returns:
            None: A single skip advances sequence while preserving every original.
        """
        paths = self.add_items(524)
        row = copy.deepcopy(self.store.data)
        for index, item in enumerate(row['items'][:443]):
            item['state'] = 'acknowledged'
            item['sha256'] = 'a' * 64
            item['disposition'] = 'uploaded'
            item['disposition_at'] = float(index + 1)
        row['items'][443]['state'] = 'uncertain'
        row['items'][443]['phase'] = 'upload'
        row['items'][443]['sha256'] = 'b' * 64
        row['items'][443]['reason'] = 'Response outcome unknown.'
        row['pending'] = [item['source']['path'] for item in row['items'][443:]]
        self.store._publish(row)
        coordinator = self.coordinator()
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        self.assertEqual(444, snapshot.sequence)
        self.assertEqual(443, snapshot.last_confirmed_sequence)
        self.assertEqual(81, snapshot.pending_count)
        updated = coordinator.skip_head(snapshot)
        assert updated is not None
        self.assertEqual(1, updated.skipped_count)
        self.assertEqual(443, updated.acknowledged_count)
        self.assertEqual(445, updated.sequence)
        self.assertEqual(80, updated.pending_count)
        self.assertTrue(all(path.exists() for path in paths))
        restored = WatchQueueStore(
            self.store.path, 'a' * 64, self.folder, False,
        )
        restored.restore()
        skipped = next(
            item for item in restored.items() if item.sequence == 444
        )
        self.assertEqual('skipped_by_user', skipped.disposition)
        self.assertEqual(80, len(restored.data['pending']))

    def test_receipt_confirmation_requires_evidence_and_is_idempotence_guarded(self) -> None:
        """Require exact receipt callback and reject a replayed stale click.

        Returns:
            None: Confirmed ACK and rejected duplicate decision remain durable.
        """
        source = self.add_items(1)[0]
        uncertain = self.store.head()
        assert uncertain is not None
        digest = hashlib.sha256(source.read_bytes()).hexdigest()
        self.store.update(uncertain, sha256=digest)
        snapshot = self.coordinator(receipt=True).snapshot()
        assert snapshot is not None
        self.coordinator(receipt=True).confirm_receipt(snapshot)
        self.assertTrue(source.exists())
        self.assertEqual('confirmed_by_receipt', self.store.items()[0].disposition)
        with self.assertRaises(ValueError):
            self.coordinator(receipt=True).confirm_receipt(snapshot)

    def test_missing_receipt_live_transport_and_target_mismatch_block(self) -> None:
        """Refuse evidence-free, live and stale-target decisions without mutation.

        Returns:
            None: The uncertain source and serialized state remain unchanged.
        """
        source = self.add_items(1)[0]
        original = self.store.path.read_bytes()
        snapshot = self.coordinator().snapshot()
        assert snapshot is not None
        with self.assertRaises(ValueError):
            self.coordinator().confirm_receipt(snapshot)
        with self.assertRaises(ValueError):
            self.coordinator(active=True).skip_head(snapshot)
        stale_target = replace(snapshot, target_binding='b' * 64)
        with self.assertRaises(ValueError):
            self.coordinator().skip_head(stale_target)
        self.assertEqual(original, self.store.path.read_bytes())
        self.assertTrue(source.exists())

    def test_interrupted_linked_publication_is_repaired_idempotently(self) -> None:
        """Replay a durable intent after linked history commits but queue reply is lost.

        Returns:
            None: Repeated callback repair terminalizes the original exact head.
        """
        self.add_items(2)
        linked_reviews: set[tuple[str, str]] = {('other-marker', 'unrelated')}
        calls = []

        def linked_review(item: WatchQueueItem, disposition: str) -> None:
            """Count idempotent linked review calls for the exact item.

            Args:
                item (WatchQueueItem): Queue head associated with the review.
                disposition (str): Explicit review disposition.

            Returns:
                None: Records fixture callback invocation.
            """
            key = (item.item_id, disposition)
            calls.append(key)
            linked_reviews.add(key)

        coordinator = WatchRecoveryCoordinator(
            self.store, self.lease, None, no_receipt, no_transport,
            'a' * 64, 'legacy', linked_review,
        )
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        with mock.patch.object(
                self.store, 'record_recovery_disposition',
                side_effect=OSError('simulated lost queue publication')):
            with self.assertRaises(OSError):
                coordinator.skip_head(snapshot)
        self.assertEqual(2, len(linked_reviews))
        self.assertIn(('other-marker', 'unrelated'), linked_reviews)
        recovered = WatchQueueStore(self.store.path, 'a' * 64, self.folder, False)
        recovered.restore()

        def idempotent_linked_review(
            item: WatchQueueItem, disposition: str,
        ) -> None:
            """Repair the exact linked decision without duplicating history.

            Args:
                item (WatchQueueItem): Exact item retained by queue intent.
                disposition (str): Stored decision to repair.

            Returns:
                None: Existing linked marker is accepted as already committed.
            """
            key = (item.item_id, disposition)
            calls.append(key)
            linked_reviews.add(key)

        restarted = WatchRecoveryCoordinator(
            recovered, self.lease, None, no_receipt, no_transport,
            'a' * 64, 'legacy', idempotent_linked_review,
        )
        restarted.repair_interrupted_decision()
        self.assertEqual(2, len(calls))
        self.assertEqual(2, len(linked_reviews))
        self.assertIn(('other-marker', 'unrelated'), linked_reviews)
        self.assertEqual('skipped', recovered.items()[0].state)
        self.assertEqual(2, recovered.head().sequence)

    def test_prior_uncertainty_review_unblocks_unsent_head_without_ack(self) -> None:
        """Review A and leave blocked B eligible for its first ordinary send.

        Returns:
            None: B remains pending with no receipt/skip disposition after A review.
        """
        paths = self.add_items(2)
        head = self.store.head()
        assert head is not None
        self.store.update(head, state='acknowledged', sha256='a' * 64)
        head = self.store.head()
        assert head is not None
        blocked = self.store.update(
            head, state='blocked', phase='prior_upload_review', attempts=0,
            sha256='b' * 64,
            reason='Earlier linked upload has an unknown result; review required.',
        )
        reviewed = []

        def blocker_details(item: WatchQueueItem) -> tuple[str, str, str]:
            """Return deterministic prior uncertainty identity for the fixture.

            Args:
                item (WatchQueueItem): Current never-dispatched head B.

            Returns:
                tuple[str, str, str]: Marker, source path and attempted hash for A.
            """
            return ('marker', 'prior.jpg', 'c' * 64)

        def blocker_review(item: WatchQueueItem, marker: str) -> None:
            """Retain the exact earlier blocker identity passed for review.

            Args:
                item (WatchQueueItem): Current head B being unblocked.
                marker (str): Existing uncertainty marker for earlier item A.

            Returns:
                None: Appends the reviewed B identity for the assertion.
            """
            reviewed.append(item.item_id)

        coordinator = WatchRecoveryCoordinator(
            self.store, self.lease, None, no_receipt, no_transport,
            'a' * 64, 'legacy', ignore_linked_review,
            blocker_details, blocker_review,
        )
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        result = coordinator.review_earlier_blocker(snapshot)
        assert result is not None
        current = self.store.head()
        assert current is not None
        self.assertEqual([blocked.item_id], reviewed)
        self.assertEqual('preparing', current.state)
        self.assertEqual('', current.disposition)
        self.assertTrue(all(path.exists() for path in paths))

    def test_skip_restart_double_click_rejects_old_head_snapshot(self) -> None:
        """Restore a skip and refuse a repeated click against the prior head.

        Returns:
            None: Durable skip survives restore and the next item remains head.
        """
        paths = self.add_items(2)
        coordinator = self.coordinator()
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        coordinator.skip_head(snapshot)
        with self.assertRaises(ValueError):
            coordinator.skip_head(snapshot)
        restored = WatchQueueStore(self.store.path, 'a' * 64, self.folder, False)
        restored.restore()
        self.assertEqual('skipped', restored.items()[0].state)
        self.assertEqual(paths[1].as_posix(), Path(restored.head().source.path).as_posix())
        self.assertTrue(all(path.exists() for path in paths))

    def test_new_generation_at_skipped_path_is_admitted_after_old_skip(self) -> None:
        """Retain the skipped generation while admitting replacement bytes.

        Returns:
            None: New signature gets a new sequence and old source stays retained.
        """
        paths = self.add_items(2)
        coordinator = self.coordinator()
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        coordinator.skip_head(snapshot)
        paths[0].write_bytes(b'replacement bytes')
        replacement = observe_watch_source(paths[0], 100.0)
        self.store.reconcile([replacement])
        items = self.store.items()
        self.assertEqual('skipped', items[0].state)
        self.assertEqual(3, items[2].sequence)
        self.assertNotEqual(items[0].source.signature, items[2].source.signature)
        self.assertTrue(paths[0].exists())

    def test_dynamic_mode_change_and_lost_lease_refuse_decision(self) -> None:
        """Reject context drift after snapshot and before explicit mutation.

        Returns:
            None: Mode switch or invalid native lease leaves queue unchanged.
        """
        self.add_items(1)
        mode = ['legacy']

        def current_mode() -> str:
            """Return the controlled current mode evidence.

            Returns:
                str: Mutable linked or legacy mode selected by the fixture.
            """
            return mode[0]

        coordinator = WatchRecoveryCoordinator(
            self.store, self.lease, None, no_receipt, no_transport,
            'a' * 64, current_mode,
            ignore_linked_review,
        )
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        mode[0] = 'linked'
        with self.assertRaises(ValueError):
            coordinator.skip_head(snapshot)
        mode[0] = 'legacy'
        with mock.patch.object(self.lease._native, 'verify',
                               side_effect=OSError('lease lost')):
            with self.assertRaises(ValueError):
                coordinator.skip_head(snapshot)

    def test_missing_source_retry_wait_can_be_explicitly_skipped(self) -> None:
        """Retain a missing head as skipped without claiming server success.

        Returns:
            None: Skip survives restart and acknowledgement counts stay unchanged.
        """
        source = self.add_items(1)[0]
        head = self.store.head()
        assert head is not None
        self.store.update(
            head, state='retry_wait', phase='source',
            reason='Oldest photograph is missing; restore its source.',
        )
        source.unlink()
        coordinator = self.coordinator()
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        updated = coordinator.skip_head(snapshot)
        self.assertIsNone(updated)
        self.assertEqual(
            1, sum(item.state == 'skipped' for item in self.store.items()),
        )
        self.assertEqual(
            0, sum(item.state == 'acknowledged' for item in self.store.items()),
        )
        self.assertEqual('skipped', self.store.items()[0].state)
        restored = WatchQueueStore(self.store.path, 'a' * 64, self.folder, False)
        restored.restore()
        self.assertEqual('skipped_by_user', restored.items()[0].disposition)
        self.assertEqual([], restored.data['pending'])
        self.assertFalse(source.exists())

    def test_retained_resume_allows_safe_dead_worker_view_and_rejects_stale_stop(self) -> None:
        """Resume a safe review-only queue and reject a changed stop flag.

        Returns:
            None: Resume preserves accepted queue state and only clears a matching stop.
        """
        self.add_items(1)
        head = self.store.head()
        assert head is not None
        self.store.update(head, state='observed', phase='discovery')
        self.store.stop_and_retain(50.0)
        coordinator = self.coordinator()
        stopped_snapshot = coordinator.snapshot()
        assert stopped_snapshot is not None
        self.store.resume_retained()
        with self.assertRaises(ValueError):
            coordinator.resume_retained(stopped_snapshot)

        clear_snapshot = coordinator.snapshot()
        assert clear_snapshot is not None
        self.assertFalse(clear_snapshot.retained_stop)
        self.assertIsNotNone(coordinator.resume_retained(clear_snapshot))
        self.assertFalse(self.store.data['retained_stop'])

    def test_snapshot_mode_scope_and_blocker_target_are_bound_before_decision(self) -> None:
        """Reject altered context fields before writing any recovery intent.

        Returns:
            None: Each stale context variant leaves the queue journal unchanged.
        """
        self.add_items(1)
        coordinator = self.coordinator()
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        original = self.store.path.read_bytes()
        stale_views = (
            replace(snapshot, expected_mode='linked'),
            replace(snapshot, scope='another-scope'),
            replace(snapshot, prior_blocker_target_binding='b' * 64),
        )
        for stale_snapshot in stale_views:
            with self.subTest(
                    mode=stale_snapshot.expected_mode,
                    scope=stale_snapshot.scope,
                    blocker_target=stale_snapshot.prior_blocker_target_binding):
                with self.assertRaises(ValueError):
                    coordinator.skip_head(stale_snapshot)
                self.assertEqual(original, self.store.path.read_bytes())

    def test_decision_waits_for_worker_head_gate(self) -> None:
        """Serialize review with an in-flight head transition on shared gate.

        Returns:
            None: Decision remains blocked until the worker releases its gate.
        """
        self.add_items(1)
        gate = threading.RLock()
        coordinator = WatchRecoveryCoordinator(
            self.store, self.lease, None, no_receipt, no_transport,
            'a' * 64, 'legacy', ignore_linked_review,
            decision_gate=gate,
        )
        snapshot = coordinator.snapshot()
        assert snapshot is not None
        started = threading.Event()
        finished = threading.Event()

        def decide() -> None:
            """Attempt a decision while worker owns the shared head gate.

            Returns:
                None: Signals entry and completion around the serialized action.
            """
            started.set()
            coordinator.skip_head(snapshot)
            finished.set()

        with gate:
            thread = threading.Thread(target=decide)
            thread.start()
            self.assertTrue(started.wait(RECOVERY_GATE_FIXTURE_SECONDS[0]))
            self.assertFalse(finished.is_set())
        thread.join(RECOVERY_GATE_FIXTURE_SECONDS[1])
        self.assertFalse(thread.is_alive())
        self.assertTrue(finished.is_set())

    def test_force_finished_session_is_read_only_to_recovery_coordinator(self) -> None:
        """Keep durable recovery visible while refusing every old-session mutation.

        Returns:
            None: Forced queue evidence remains unchanged through stale UI decisions.
        """
        source = self.add_items(1)[0]
        head = self.store.head()
        assert head is not None
        self.store.update(head, sha256=hashlib.sha256(source.read_bytes()).hexdigest())
        snapshot = self.coordinator(receipt=True).snapshot()
        assert snapshot is not None
        self.store.force_finish(2000.0)
        before = self.store.path.read_bytes()
        forced_service = self.coordinator(receipt=True)

        retained = forced_service.snapshot()
        assert retained is not None
        self.assertEqual(snapshot.item_id, retained.item_id)
        self.assertTrue(self.store.force_finished)
        with self.assertRaises(ValueError):
            forced_service.confirm_receipt(snapshot)
        with self.assertRaises(ValueError):
            forced_service.skip_head(snapshot)
        with self.assertRaises(ValueError):
            forced_service.resume_retained(snapshot)
        forced_service.repair_interrupted_decision()
        self.assertEqual(before, self.store.path.read_bytes())
        self.assertEqual('uncertain', self.store.head().state)
        self.assertTrue(source.exists())
