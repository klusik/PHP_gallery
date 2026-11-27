# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watch_queue.py
# Module Type: Regression Test
# Purpose: Verify FIFO admission, generation identity and terminal boundaries.
# Responsibilities:
#   - Exercise real journal writes using controlled clocks and isolated sources.
#   - Reject stale transitions and retain force-finished recovery evidence.
#   - Recover a write-ahead uncertain head after its owned Python child exits.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise queue state through durable transitions rather than source-string checks."""

import hashlib
import json
import os
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from dataclasses import replace
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.watch_queue import WatchQueueStore, WatchSource, observe_watch_source
from uploader import state_store

# Purpose: Bound fixture threads without awaiting any production retry interval.
# Type: float. Units: seconds. Scope: concurrent local admission regression.
# Consumers: test_concurrent_refresh_preserves_identity.
# Rationale: Three seconds detects a deadlock while permitting slow hosted disks.
QUEUE_FIXTURE_JOIN_SECONDS = 3.0

# Purpose: Bound restoration and durable publication by one isolated child.
# Type: float. Units: seconds. Scope: one WatchQueueStore child readiness wait.
# Consumers: WatchQueueTests.test_child_termination_restores_uncertain_head.
# Rationale: Fifteen seconds covers a cold interpreter and journal fsync on CI.
QUEUE_CHILD_READY_TIMEOUT_SECONDS = 15.0

# Purpose: Poll the child's readback marker without waiting on an unowned handle.
# Type: float. Units: seconds. Scope: one readiness polling interval.
# Consumers: WatchQueueTests.test_child_termination_restores_uncertain_head.
# Rationale: 20 ms bounds marker latency without a busy loop.
QUEUE_CHILD_POLL_INTERVAL_SECONDS = 0.02

# Purpose: Bound reap after terminating the exact owned fixture child.
# Type: float. Units: seconds. Scope: one child termination and cleanup.
# Consumers: WatchQueueTests.test_child_termination_restores_uncertain_head.
# Rationale: Five seconds is ample for a killed local Python process to exit.
QUEUE_CHILD_REAP_TIMEOUT_SECONDS = 5.0


class WatchQueueTests(unittest.TestCase):
    """Preserve every accepted generation and immutable chronological position."""

    def setUp(self) -> None:
        """Create a private folder, journal and deterministic wall clock.

        Returns:
            None: Initializes an empty destination-bound FIFO outside all user profiles.
        """
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.folder = self.root / 'screenshots'
        self.folder.mkdir()
        # Structural Unix-seconds fixture epoch, never a wait policy.
        self.now = 1000.0
        self.journal = self.store()
        self.journal.initialize([])

    def store(self, binding: str = 'a' * 64) -> WatchQueueStore:
        """Construct another owner of the same private journal for recovery tests.

        Args:
            binding (str): Synthetic destination fingerprint selected by the fixture.

        Returns:
            WatchQueueStore: Uninitialized owner using the controlled wall clock.
        """
        return WatchQueueStore(
            self.root / 'session' / 'watch-drain.json', binding, self.folder, False,
            clock=lambda: self.now,
        )

    def source(self, name: str, captured: float, content: bytes = b'image') -> WatchSource:
        """Create a source with independently controlled chronological evidence.

        Args:
            name (str): Private source basename.
            captured (float): Synthetic capture/birth timestamp in Unix seconds.
            content (bytes): Source content, including partial-writer vectors.

        Returns:
            WatchSource: Real file identity with controlled capture and birth clocks.
        """
        path = self.folder / name
        path.write_bytes(content)
        return replace(observe_watch_source(path, self.now),
                       captured_at=captured, born_at=captured)

    def test_order_survives_rescan_metadata_change_and_restart(self) -> None:
        """Persist arrival order independently of filename prefixes and mutable mtime.

        Returns:
            None: Older accepted sequence and ID survive replacement observations.
        """
        first = self.source('999_20261109231103_1.png', 1.0)
        second = self.source('1_20261109231104_1.png', 2.0)
        self.journal.reconcile([second, first])
        original = self.journal.items()
        self.assertEqual([first.path, second.path], [item.source.path for item in original])
        changed = self.source(Path(first.path).name, 99.0, b'larger unfinished image')
        self.journal.reconcile([second, changed])
        recovered = self.store()
        recovered.restore()
        self.assertEqual([(item.item_id, item.sequence) for item in original],
                         [(item.item_id, item.sequence) for item in recovered.items()])
        self.assertEqual('observed', recovered.head().state)
        self.assertEqual('', recovered.head().sha256)

    def test_steam_clock_ignores_game_prefix(self) -> None:
        """Recognize the embedded timestamp before using a deterministic name tie.

        Returns:
            None: Steam capture order differs correctly from lexical game-prefix order.
        """
        self.source('999_20261109231103_1.png', 1.0)
        self.source('1_20261109231104_1.png', 2.0)
        observed = [observe_watch_source(path, self.now) for path in self.folder.iterdir()]
        self.journal.reconcile(observed)
        self.assertEqual('999_20261109231103_1.png', Path(self.journal.head().source.path).name)
        self.assertEqual('steam_filename_local', self.journal.head().source.clock)

    def test_unchanged_identity_does_not_include_observation_time(self) -> None:
        """Keep portable file signatures stable across later scans and recovery.

        Returns:
            None: A platform without birth time admits unchanged bytes only once.
        """
        source = self.folder / 'ordinary.png'
        source.write_bytes(b'unchanged image')
        actual = source.stat()
        # Deliberately omit native birth time, as on supported POSIX fixtures.
        portable = SimpleNamespace(
            st_mode=actual.st_mode, st_dev=actual.st_dev,
            st_ino=actual.st_ino, st_size=actual.st_size,
            st_mtime_ns=actual.st_mtime_ns, st_ctime=actual.st_ctime,
        )
        with (mock.patch.object(Path, 'lstat', return_value=portable),
              mock.patch('uploader.watch_queue.os', SimpleNamespace(name='posix'))):
            first = observe_watch_source(source, self.now)
            second = observe_watch_source(source, self.now + 1)
        self.assertEqual(first.signature, second.signature)
        self.assertEqual(first.identity, second.identity)
        self.journal.reconcile([first])
        self.journal.reconcile([second])
        self.assertEqual(1, len(self.journal.items()))

    def test_uncertain_head_blocks_all_later_transitions_after_restart(self) -> None:
        """Keep interrupted dispatch unknown instead of treating absence as failure.

        Returns:
            None: Recovery blocks B and does not clear A's durable uncertainty.
        """
        self.journal.reconcile([self.source('a.png', 1.0), self.source('b.png', 2.0)])
        first, second = self.journal.items()
        self.journal.update(first, state='dispatching', sha256='a' * 64)
        recovered = self.store()
        recovered.restore()
        self.assertEqual('uncertain', recovered.head().state)
        with self.assertRaises(ValueError):
            recovered.update(second, state='inventory')
        self.assertEqual(first.item_id, recovered.head().item_id)

    def test_child_termination_restores_uncertain_head(self) -> None:
        """Recover the committed FIFO after killing its blocked writer process.

        Returns:
            None: Verified dispatch becomes an uncertain head without changing
                queue generations, successor order, source paths or source bytes.
        """
        first_source = self.source('child-head.png', 1.0, b'uncertain-head-bytes')
        second_source = self.source('child-successor.png', 2.0, b'fifo-successor-bytes')
        self.assertEqual(2, self.journal.reconcile([first_source, second_source]))
        original_items = self.journal.items()
        original_pending = [item.source.path for item in original_items]
        original_bytes = {
            item.source.path: Path(item.source.path).read_bytes()
            for item in original_items
        }
        original_identity = [
            (item.item_id, item.sequence, item.source.identity, item.source.signature)
            for item in original_items
        ]
        original_scan_generation = self.journal.data['scan_generation']
        head_hash = hashlib.sha256(original_bytes[first_source.path]).hexdigest()
        ready_path = self.root / 'child-ready'
        child_program = '''
import sys
from pathlib import Path

from uploader.watch_queue import WatchQueueStore

journal_path = Path(sys.argv[1])
journal = WatchQueueStore(
    journal_path, sys.argv[2], Path(sys.argv[3]), False,
)
journal.restore()
head = journal.head()
if head is None:
    raise RuntimeError('The private FIFO head could not be restored.')
digest = sys.argv[5]
journal.update(head, state='dispatching', phase='upload', sha256=digest)
marked = WatchQueueStore.read_journal(journal_path)['items'][0]
if (marked['item_id'], marked['state'], marked['sha256']) != (
    head.item_id, 'dispatching', digest,
):
    raise RuntimeError('Write-ahead publication readback did not match.')
Path(sys.argv[4]).touch()
sys.stdin.buffer.read(1)
'''
        child = subprocess.Popen(
            [
                sys.executable, '-c', child_program, str(self.journal.path),
                self.journal.binding, str(self.folder), str(ready_path), head_hash,
            ],
            cwd=WINAPP_DIR, stdin=subprocess.PIPE, stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE, text=True,
        )
        child_stderr = ''
        ready = False
        try:
            deadline = time.monotonic() + QUEUE_CHILD_READY_TIMEOUT_SECONDS
            while (not ready_path.is_file() and child.poll() is None
                   and time.monotonic() < deadline):
                time.sleep(QUEUE_CHILD_POLL_INTERVAL_SECONDS)
            ready = ready_path.is_file() and child.poll() is None
        finally:
            if child.poll() is None:
                child.kill()
            try:
                child.wait(timeout=QUEUE_CHILD_REAP_TIMEOUT_SECONDS)
            except subprocess.TimeoutExpired:
                child.kill()
                child.wait(timeout=QUEUE_CHILD_REAP_TIMEOUT_SECONDS)
            if child.stderr is not None:
                child_stderr = child.stderr.read()
            for stream in (child.stdin, child.stdout, child.stderr):
                if stream is not None:
                    stream.close()
        self.assertTrue(
            ready,
            f'Child did not publish verified readiness; stderr={child_stderr!r}',
        )
        self.assertNotEqual(0, child.returncode)

        recovered = self.store()
        recovered.restore()
        self.assertEqual(original_scan_generation, recovered.data['scan_generation'])
        self.assertEqual(
            original_identity,
            [(item.item_id, item.sequence, item.source.identity, item.source.signature)
             for item in recovered.items()],
        )
        self.assertEqual(original_pending, recovered.data['pending'])
        self.assertEqual('uncertain', recovered.head().state)
        self.assertEqual('upload', recovered.head().phase)
        self.assertEqual(head_hash, recovered.head().sha256)
        self.assertEqual('observed', recovered.items()[1].state)
        self.assertEqual(
            original_bytes,
            {path: Path(path).read_bytes() for path in original_pending},
        )

    def test_finish_waits_for_last_writer_and_fresh_reconciliation(self) -> None:
        """Include pre-cutoff writers while excluding genuinely later screenshots.

        Returns:
            None: Completion requires both acknowledgement and a newer scan generation.
        """
        before = self.source('before.png', self.now - 1, b'partial')
        self.journal.finish(self.now)
        self.now += 1
        after = self.source('after.png', self.now)
        self.journal.reconcile([before, after])
        head = self.journal.head()
        self.assertEqual(before.path, head.source.path)
        self.assertEqual(1, len(self.journal.items()))
        self.assertFalse(self.journal.complete(0.0))
        self.journal.update(head, state='acknowledged', sha256='a' * 64)
        self.assertFalse(self.journal.complete(0.0))
        # Same wall time is intentional: scan generations close this race.
        self.journal.reconcile([before, after])
        self.assertTrue(self.journal.complete(0.0))
        self.assertTrue(self.journal.data['completed'])

    def test_stop_and_retain_survives_restore_until_explicit_resume(self) -> None:
        """Retain accepted work and cutoff while blocking automatic admission.

        Returns:
            None: Restore preserves stop and explicit resume preserves same queue.
        """
        source = self.source('retained.png', self.now)
        self.journal.reconcile([source])
        self.journal.stop_and_retain(self.now + 1)
        self.assertEqual(0, self.journal.reconcile([self.source('later.png', self.now + 2)]))
        recovered = self.store()
        recovered.restore()
        self.assertTrue(recovered.data['retained_stop'])
        self.assertEqual([source.path], recovered.data['pending'])
        retained_cutoff = recovered.data['cutoff']
        recovered.resume_retained()
        self.assertFalse(recovered.data['retained_stop'])
        self.assertEqual(retained_cutoff, recovered.data['cutoff'])
        self.assertEqual(source.path, recovered.head().source.path)

    def test_force_finish_is_durable_idempotent_and_retains_pending_evidence(self) -> None:
        """Seal a session without changing pending, acknowledged or source evidence.

        Returns:
            None: Restart preserves the first cutoff and does not replay pending work.
        """
        acknowledged_source = self.source('acknowledged.png', self.now)
        pending_source = self.source('pending.png', self.now + 1)
        self.journal.reconcile([acknowledged_source, pending_source])
        first = self.journal.items()[0]
        digest = hashlib.sha256(Path(first.source.path).read_bytes()).hexdigest()
        self.journal.update(first, state='acknowledged', sha256=digest,
                            disposition='uploaded')
        self.journal.finish(self.now + 2)
        pending = self.journal.head()
        assert pending is not None
        self.journal.update(pending, state='uncertain', sha256='b' * 64,
                            phase='upload', reason='Outcome unknown.')
        before_items = self.journal.items()
        before_pending = list(self.journal.data['pending'])

        self.journal.force_finish(self.now + 3)
        self.journal.force_finish(self.now + 4)

        self.assertTrue(self.journal.force_finished)
        self.assertEqual(self.now + 3, self.journal.force_finished_at)
        self.assertEqual(self.now + 2, self.journal.data['cutoff'])
        self.assertFalse(self.journal.data['completed'])
        self.assertEqual(before_pending, self.journal.data['pending'])
        self.assertEqual(before_items, self.journal.items())
        self.assertTrue(Path(acknowledged_source.path).exists())
        self.assertTrue(Path(pending_source.path).exists())
        self.assertEqual(1, sum(item.state == 'acknowledged'
                                for item in self.journal.items()))
        self.assertEqual(1, sum(item.state == 'uncertain'
                                for item in self.journal.items()))
        self.assertEqual(0, self.journal.reconcile([
            self.source('later.png', self.now + 5),
        ]))
        self.journal.finish(self.now + 5)
        self.assertFalse(self.journal.complete(0.0))

        recovered = self.store()
        recovered.restore()
        self.assertTrue(recovered.force_finished)
        self.assertEqual(self.now + 3, recovered.force_finished_at)
        self.assertEqual(before_pending, recovered.data['pending'])
        self.assertEqual('uncertain', recovered.head().state)
        self.assertTrue(Path(pending_source.path).exists())

    def test_interrupted_initial_scan_can_close_but_cannot_resume(self) -> None:
        """Keep the startup boundary mandatory for any upload resumption.

        Returns:
            None: A verified empty startup journal can be closed locally, and
                normal restore continues to reject its uncaptured boundary.
        """
        startup = WatchQueueStore(
            self.root / 'interrupted' / 'watch-drain.json', self.journal.binding,
            self.folder, False, clock=lambda: self.now,
        )
        startup.initialize(None)
        with self.assertRaisesRegex(ValueError, 'Original capture boundary'):
            startup.restore()
        startup.restore(for_close=True)
        startup.force_finish(self.now)
        self.assertTrue(startup.force_finished)
        self.assertEqual([], startup.items())
        with self.assertRaisesRegex(ValueError, 'Original capture boundary'):
            startup.restore()

    def test_force_finish_keeps_existing_cutoff_and_blocks_non_ack_transitions(self) -> None:
        """Preserve a prior boundary and reject old-session retry transitions.

        Returns:
            None: A stale retry cannot advance the permanently closed session.
        """
        source = self.source('retained.png', self.now)
        self.journal.reconcile([source])
        head = self.journal.head()
        assert head is not None
        dispatching = self.journal.update(
            head, state='dispatching', sha256='a' * 64,
        )
        self.journal.force_finish(self.now + 1)
        with self.assertRaises(ValueError):
            self.journal.update(dispatching, state='retry_wait',
                                reason='late timeout')
        self.assertEqual('dispatching', self.journal.head().state)
        self.assertEqual(self.now + 1, self.journal.data['cutoff'])
        self.assertTrue(Path(source.path).exists())
        restored = self.store()
        restored.restore()
        self.assertEqual('uncertain', restored.head().state)
        self.assertEqual(
            0, restored.reconcile([observe_watch_source(Path(source.path), self.now)]),
        )

    def test_force_finish_rejects_malformed_persisted_marker(self) -> None:
        """Require a strict boolean and matching timestamp in restored journals.

        Returns:
            None: Contradictory terminal markers cannot authorize session recovery.
        """
        row = json.loads(self.journal.path.read_text(encoding='utf-8'))
        row['force_finished'] = True
        row['force_finished_at'] = 0.0
        self.journal.path.write_text(json.dumps(row), encoding='utf-8')
        with self.assertRaises(ValueError):
            self.store().restore()

    def test_force_finish_preserves_an_already_completed_queue(self) -> None:
        """Keep genuine completion evidence instead of downgrading it to force state.

        Returns:
            None: Host cancellation after completion leaves the verified journal intact.
        """
        self.journal.finish(self.now)
        self.journal.reconcile([])
        self.assertTrue(self.journal.complete(0.0))
        before = self.journal.path.read_bytes()

        self.journal.force_finish(self.now + 2)

        self.assertEqual(before, self.journal.path.read_bytes())
        self.assertTrue(self.journal.data['completed'])
        self.assertFalse(self.journal.force_finished)

    def test_failed_persistence_never_reports_acceptance(self) -> None:
        """Retain the last confirmed journal when durable admission cannot be written.

        Returns:
            None: No in-memory acceptance, counter advance or source deletion occurs.
        """
        observed = self.source('a.png', self.now)
        before = self.journal.path.read_bytes()
        with mock.patch('uploader.watch_queue.atomic_write_json', side_effect=OSError('disk full')):
            with self.assertRaises(OSError):
                self.journal.reconcile([observed])
        self.assertIsNone(self.journal.head())
        self.assertEqual(before, self.journal.path.read_bytes())
        self.assertTrue(Path(observed.path).exists())

    def test_required_fsync_failure_refuses_publication(self) -> None:
        """Do not turn fsync failure into a successful accepted-work write.

        Returns:
            None: Existing evidence remains intact when synchronization is unavailable.
        """
        target = self.root / 'durable.json'
        state_store.atomic_write_json(target, {'state': 'original'}, require_sync=True)
        with mock.patch.object(state_store.os, 'fsync', side_effect=OSError('no space')):
            with self.assertRaises(OSError):
                state_store.atomic_write_json(target, {'state': 'new'}, require_sync=True)
        self.assertEqual({'state': 'original'}, json.loads(target.read_text()))

    def test_stale_owner_cannot_overwrite_finish(self) -> None:
        """Refuse an old Force preparation after another owner saved Finish.

        Returns:
            None: The first Finish cutoff survives a stale complete-file publication.
        """
        stale = self.store()
        stale.restore()
        self.journal.finish(self.now)
        with self.assertRaises(ValueError):
            stale.reconcile([self.source('a.png', self.now - 1)])
        self.assertEqual(self.now, json.loads(self.journal.path.read_text())['cutoff'])

    def test_retry_and_finish_survive_restart_without_losing_outage_arrivals(self) -> None:
        """Restore a sleeping head's deadline and append new arrivals to the same queue.

        Returns:
            None: Finish, retry metadata and immutable A/B positions survive recovery.
        """
        original = self.source('a.png', self.now - 1)
        self.journal.reconcile([original])
        head = self.journal.head()
        waiting = self.journal.defer(head, 'Rate limit', 'inventory', 429, 60)
        self.journal.finish(self.now)
        self.now += 1  # Structural restart before the known server deadline.
        recovered = self.store()
        recovered.restore()
        later_discovered = self.source('b.png', self.now - 2)
        recovered.reconcile([later_discovered, original])
        self.assertEqual(waiting.next_retry_at, recovered.head().next_retry_at)
        self.assertEqual(waiting.item_id, recovered.head().item_id)
        self.assertEqual('retry_wait', recovered.head().state)
        self.assertEqual(2, len(recovered.items()))
        self.assertEqual(0, recovered.reconcile([later_discovered, original]))
        self.assertFalse(recovered.complete(0.0))

    def test_repeated_limits_keep_intent_and_bound_only_attempt_frequency(self) -> None:
        """Never turn repeated temporary refusals into a terminal photograph failure.

        Returns:
            None: Retry count grows while the same oldest source remains durable.
        """
        self.journal.reconcile([self.source('a.png', self.now)])
        original = self.journal.head()
        # Structural sequence passes the exponential cap, not a runtime retry limit.
        for _ in range(10):
            waiting = self.journal.defer(self.journal.head(), 'Offline', 'inventory')
            self.assertLessEqual(waiting.next_retry_at - self.now, 300)
            self.now = waiting.next_retry_at
        recovered = self.store()
        recovered.restore()
        self.assertEqual('retry_wait', recovered.head().state)
        self.assertEqual(original.item_id, recovered.head().item_id)
        self.assertEqual(10, recovered.head().attempts)

    def test_concurrent_refresh_preserves_identity(self) -> None:
        """Coalesce repeated scan results without duplicate items or renumbering.

        Returns:
            None: Concurrent automatic/manual reconciliation admits one generation.
        """
        observed = self.source('a.png', self.now)
        workers = [threading.Thread(target=self.journal.reconcile, args=([observed],))
                   for _ in range(2)]  # Structural automatic/manual race pair.
        for worker in workers:
            worker.start()
        for worker in workers:
            worker.join(QUEUE_FIXTURE_JOIN_SECONDS)
            self.assertFalse(worker.is_alive())
        self.assertEqual(1, len(self.journal.items()))
        self.assertEqual(1, self.journal.head().sequence)

    def test_legacy_zero_cutoff_stays_open_and_unknown_order_blocks(self) -> None:
        """Never reinterpret the old error checkpoint as an explicit Finish.

        Returns:
            None: A legacy multi-path queue is retained with unverified order.
        """
        sources = [self.source('a.png', 1.0), self.source('b.png', 2.0)]
        row = {
            'schema_version': 1, 'folder': str(self.folder), 'recursive': False,
            'initial': [], 'pending': [item.path for item in sources],
            'initial_captured': True, 'frozen': False, 'cutoff': 0.0,
            'checkpoint': self.now, 'error': 'Temporary failure',
        }
        self.journal.path.write_text(json.dumps(row), encoding='utf-8')
        recovered = self.store()
        recovered.restore()
        self.assertEqual(0.0, recovered.data['cutoff'])
        self.assertIs(recovered.data['order_verified'], False)
        self.assertIn('no provable FIFO order', recovered.data['error'])
        self.assertEqual([item.path for item in sources], recovered.data['pending'])

    def test_late_older_source_appends_after_existing_acknowledgement(self) -> None:
        """Expose the observable chronology boundary without rewriting past transfers.

        Returns:
            None: A late older file gets a new sequence after the completed item.
        """
        self.journal.reconcile([self.source('newer.png', 20.0)])
        first = self.journal.head()
        self.journal.update(first, state='acknowledged', sha256='a' * 64)
        self.journal.reconcile([self.source('older.png', 10.0)])
        self.assertEqual(2, self.journal.head().sequence)
        self.assertEqual(first.item_id, self.journal.items()[0].item_id)

    def test_target_and_scope_mismatch_preserve_original_bytes(self) -> None:
        """Refuse recovery under a different destination or approval scope.

        Returns:
            None: Invalid owners cannot rewrite or reset the original journal.
        """
        before = hashlib.sha256(self.journal.path.read_bytes()).hexdigest()
        with self.assertRaises(ValueError):
            self.store('b' * 64).restore()
        changed = self.store()
        changed.scope = 'another:scope'
        with self.assertRaises(ValueError):
            changed.restore()
        self.assertEqual(before, hashlib.sha256(self.journal.path.read_bytes()).hexdigest())

    @unittest.skipUnless(os.name == 'nt', 'Requires native Windows path spelling.')
    def test_windows_separator_compatibility_preserves_queue_boundaries(self) -> None:
        """Restore both journal versions with only their folder slashes changed.

        Returns:
            None: Source identity and order survive; unrelated boundaries are
                rejected without rewriting the original persisted bytes.
        """
        source = self.source('pending.png', 1.0)
        self.journal.reconcile([source])
        original_items = self.journal.items()
        version_two = json.loads(self.journal.path.read_text(encoding='utf-8'))
        version_one = {
            'schema_version': 1, 'folder': str(self.folder), 'recursive': False,
            'initial': [], 'pending': [source.path], 'initial_captured': True,
            'frozen': False, 'cutoff': 0.0, 'checkpoint': self.now, 'error': '',
        }
        for version, original in ((1, version_one), (2, version_two)):
            with self.subTest(version=version, boundary='slash spelling'):
                row = dict(original)
                row['folder'] = str(self.folder).replace('\\', '/')
                self.journal.path.write_text(json.dumps(row), encoding='utf-8')
                recovered = self.store()
                recovered.restore()
                self.assertEqual([source.path], recovered.data['pending'])
                self.assertEqual(0.0, recovered.data['cutoff'])
                self.assertTrue(recovered.data['order_verified'])
                if version == 2:
                    self.assertEqual(
                        [(item.item_id, item.sequence, item.source.path)
                         for item in original_items],
                        [(item.item_id, item.sequence, item.source.path)
                         for item in recovered.items()],
                    )
                    self.assertEqual(row['folder'], recovered.data['folder'])
                else:
                    self.assertEqual(source.path, recovered.items()[0].source.path)
                    self.assertEqual(1, recovered.items()[0].sequence)

            cases = [
                ('different folder', 'folder', str(self.root / 'other')),
                ('parent alias', 'folder', str(self.folder / '..' / self.folder.name)),
                ('different case', 'folder', str(self.folder).swapcase()),
                ('missing folder', 'folder', None),
                ('binding', 'binding', 'b' * 64),
                ('recursion', 'recursive', True),
            ]
            if version == 2:
                cases.extend((
                    ('scope', 'scope', 'other:scope'),
                    ('session', 'session_id', 'different-session'),
                ))
            for name, field, value in cases:
                with self.subTest(version=version, boundary=name):
                    row = dict(original)
                    row['folder'] = str(self.folder).replace('\\', '/')
                    row[field] = value
                    encoded = json.dumps(row).encode('utf-8')
                    self.journal.path.write_bytes(encoded)
                    with self.assertRaises(ValueError):
                        self.store().restore()
                    self.assertEqual(encoded, self.journal.path.read_bytes())

    @unittest.skipIf(os.name == 'nt', 'Requires native POSIX path spelling.')
    def test_posix_backslash_remains_a_distinct_folder_character(self) -> None:
        """Keep a backslash distinct from a POSIX directory separator.

        Returns:
            None: A changed lexical folder is rejected without rewriting it.
        """
        row = json.loads(self.journal.path.read_text(encoding='utf-8'))
        row['folder'] = str(self.folder).replace('/', '\\')
        encoded = json.dumps(row).encode('utf-8')
        self.journal.path.write_bytes(encoded)
        with self.assertRaises(ValueError):
            self.store().restore()
        self.assertEqual(encoded, self.journal.path.read_bytes())
