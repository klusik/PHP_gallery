# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_uploader_fifo.py
# Module Type: Regression Test
# Purpose: Assert head-only request traces through real Watcher queue transitions.
# Responsibilities:
#   - Exercise retry, uncertainty, concurrent local admission and manual refresh.
#   - Reproduce outage arrivals without using any historical user screenshots.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Behavioral FIFO regressions with controlled time and simulated transport."""

import queue
import tempfile
import threading
import time
import unittest
from dataclasses import replace
from pathlib import Path
from types import ModuleType, SimpleNamespace
from unittest import mock

from test_flight_watch_drain import load_main_module
from uploader.models import JsonValue
from uploader.watch_queue import WatchSource

# Purpose: Bound event-driven synthetic uploads without real network retries.
# Type: float. Units: seconds. Scope: controlled producer/consumer races.
# Consumers: threaded capture and force-refresh regression joins.
# Rationale: Three seconds exposes a deadlock without waiting for production backoff.
FIFO_FIXTURE_WAIT_SECONDS = 3.0

# Purpose: Keep event polling responsive while bounded worker publication completes.
# Type: float. Units: seconds. Scope: Force Stop event-consumption regression only.
# Consumers: Two watcher stop-publication event queue reads.
# Rationale: Repeated short reads keep the same deadline checks responsive.
FIFO_EVENT_POLL_SECONDS = 0.05


class UploaderFifoTests(unittest.TestCase):
    """Require A to resolve durably before B can begin any network stage."""

    @classmethod
    def setUpClass(cls) -> None:
        """Load the production source without launching Tk or touching user state.

        Returns:
            None: Retains the source module for real Watcher construction.
        """
        cls.main: ModuleType = load_main_module()

    def setUp(self) -> None:
        """Prepare an isolated real Watcher with a controlled clock and fake server.

        Returns:
            None: Creates a durable private session and an observable request trace.
        """
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.folder = self.root / 'screenshots'
        self.folder.mkdir()
        self.now = time.time()
        self.births: dict[str, float] = {}
        self.trace: list[tuple[str, str]] = []
        profile = mock.patch.object(self.main, 'CONFIG_DIR', self.root / 'profile')
        profile.start()
        self.addCleanup(profile.stop)
        clock = mock.patch.object(self.main, 'time', SimpleNamespace(
            time=lambda: self.now, monotonic=time.monotonic,
            strftime=time.strftime, localtime=time.localtime,
        ))
        clock.start()
        self.addCleanup(clock.stop)
        native = mock.patch.object(self.main, 'SimConnectCameraClient', return_value=mock.Mock())
        native.start()
        self.addCleanup(native.stop)
        self.worker = self.main.WatcherThread(self.main.WatcherConfig(
            gallery_url='https://gallery.example.test', api_key='fixture-key',
            watched_folder=str(self.folder), delete_uploaded_files=False,
            attach_sim_camera_metadata=False, stable_seconds=0.0,
        ), queue.Queue())
        self.worker.configure_drain(self.root / 'session', [])
        self.worker._queue.clock = lambda: self.now
        self.worker.stability = mock.Mock()
        self.worker.stability.stable.return_value = True
        self.worker.remote_inventory = mock.Mock()
        self.worker.remote_inventory.refresh.side_effect = self.inventory
        self.worker.remote_inventory.has_hash.return_value = False
        self.worker.remote_inventory.last_error = None
        self.worker.upload_watched_file = mock.Mock(side_effect=self.transfer)
        discovery = self.main.discover_watch_sources

        def observe(folder: Path, suffixes: set[str], recursive: bool) -> list[WatchSource]:
            """Retain real file discovery with deterministic birth/order clocks.

            Args:
                folder (Path): Fixture-owned source directory.
                suffixes (set[str]): Production-supported image extensions.
                recursive (bool): Original capture recursion preference.

            Returns:
                list[WatchSource]: Actual observed identities with controlled time evidence.
            """
            return [replace(item, born_at=self.births[Path(item.path).name],
                            captured_at=self.births[Path(item.path).name])
                    for item in discovery(folder, suffixes, recursive)]

        observation = mock.patch.object(self.main, 'discover_watch_sources', side_effect=observe)
        observation.start()
        self.addCleanup(observation.stop)

    def create(self, name: str, content: bytes | None = None) -> Path:
        """Create one synthetic screenshot at a deterministic capture time.

        Args:
            name (str): Synthetic basename beneath this fixture's source folder.
            content (bytes | None): Explicit bytes, or None for unique filename bytes.

        Returns:
            Path: New ordinary source file; no real screenshot is opened or uploaded.
        """
        self.now += 0.1  # Structural distinct capture timestamps, not a retry delay.
        self.births[name] = self.now
        path = self.folder / name
        path.write_bytes(content if content is not None else name.encode('ascii'))
        return path

    def inventory(self, url: str, key: str, candidates: list[dict[str, JsonValue]], **options: bool) -> bool:
        """Record only the current head's simulated inventory request.

        Args:
            url (str): Synthetic captured endpoint.
            key (str): Fixture-only legacy credential, never sent anywhere.
            candidates (list[dict[str, JsonValue]]): Head descriptor with filename,
                client_id, size and SHA-256 fields.
            **options (bool): Production force/deep-check flags.

        Returns:
            bool: True for the configured successful inventory response.
        """
        self.assertEqual(1, len(candidates))
        self.trace.append(('inventory', candidates[0]['filename']))
        return True

    def transfer(
        self, url: str, path: Path, metadata: dict[str, str],
        file_hash: str, size: int,
    ) -> dict[str, JsonValue]:
        """Simulate one original's prepared HTTP dispatch and positive response.

        Args:
            url (str): Synthetic captured endpoint.
            path (Path): Current immutable head source.
            metadata (dict[str, str]): Optional camera metadata from the production owner.
            file_hash (str): Expected exact source SHA-256.
            size (int): Expected source byte length.

        Returns:
            dict[str, JsonValue]: Validated-success shaped ok/uploaded/scanned counts.
        """
        self.worker._mark_watch_dispatch()
        self.trace.append(('upload', path.name))
        return {'ok': True, 'uploaded': 1, 'scanned': 1}

    def scan(self) -> None:
        """Drive a real producer reconciliation and head-only processing cycle.

        Returns:
            None: Adds observable inventory/upload stages to this fixture's trace.
        """
        self.worker.scan_once(self.folder, 'https://gallery.example.test')

    def test_safe_retry_trace_is_a_a_b_c(self) -> None:
        """Never begin B after a safe temporary upload refusal of A.

        Returns:
            None: Request trace and persisted acknowledgements prove A,A,B,C order.
        """
        for name in ('a.png', 'b.png', 'c.png'):
            self.create(name)
        attempts = 0

        def upload(
            url: str, path: Path, metadata: dict[str, str], file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Refuse A once using attested pre-storage rate-limit metadata.

            Args:
                url (str): Synthetic captured endpoint.
                path (Path): Current queue head source.
                metadata (dict[str, str]): Synthetic camera metadata.
                file_hash (str): Expected source SHA-256.
                size (int): Expected original byte length.

            Returns:
                dict[str, JsonValue]: Positive ok/uploaded/scanned acknowledgement.

            Raises:
                LinkingResponseError: First A attempt receives a known safe refusal.
            """
            nonlocal attempts
            result = self.transfer(url, path, metadata, file_hash, size)
            attempts += 1
            if attempts == 1:
                raise self.main.LinkingResponseError(
                    'linking', 'app_linking_upload', 429, 'json', 'http_status',
                    definitely_rejected=True, retry_after_seconds=60,
                )
            return result

        self.worker.upload_watched_file.side_effect = upload
        self.scan()
        self.assertEqual([('inventory', 'a.png'), ('upload', 'a.png')], self.trace)
        deadline = self.worker._queue.head().next_retry_at
        self.worker.request_refresh()
        self.scan()
        self.assertEqual(2, len(self.trace))
        self.now = deadline
        self.scan()
        self.assertEqual(['a.png', 'a.png', 'b.png', 'c.png'],
                         [name for stage, name in self.trace if stage == 'upload'])
        self.assertIsNone(self.worker._queue.head())

    def test_inventory_throttle_keeps_outage_arrivals_in_original_session(self) -> None:
        """Reproduce the missing 22 screenshots without a new initial snapshot.

        Returns:
            None: All outage arrivals are durable while B has no network workflow.
        """
        self.create('a.png')
        failure = self.main.LinkingResponseError(
            'linking', 'app_linking_inventory', 429, 'json', 'http_status',
            retry_after_seconds=300,
        )
        self.worker.remote_inventory.last_error = failure
        self.worker.remote_inventory.refresh.return_value = False
        self.worker.remote_inventory.refresh.side_effect = None
        self.scan()
        for index in range(22):  # Structural historical outage vector, synthetic bytes only.
            self.create(f'outage-{index:02d}.png')
        self.worker.request_refresh()
        self.scan()
        self.assertEqual([], self.trace)
        self.assertEqual(23, len(self.worker._queue.items()))
        self.assertEqual([], self.worker._queue.data['initial'])
        self.assertFalse(self.worker.stop_event.is_set())
        self.assertEqual(0.0, self.worker._queue.data['cutoff'])
        self.worker.request_drain()
        self.now = self.worker._queue.head().next_retry_at
        self.worker.remote_inventory.refresh.side_effect = self.inventory
        self.worker.remote_inventory.last_error = None
        self.scan()
        self.assertEqual(23, len([stage for stage, name in self.trace if stage == 'upload']))

    def test_unstable_head_prevents_every_later_request(self) -> None:
        """Retain a partial older file even when the newer file is stable.

        Returns:
            None: No inventory, preflight or upload occurs until the head stabilizes.
        """
        self.create('a.png', b'partial')
        self.create('b.png')
        self.worker.stability.stable.side_effect = lambda path: path.name != 'a.png'
        self.scan()
        self.assertEqual([], self.trace)
        self.assertEqual('stabilizing', self.worker._queue.head().state)

    def test_lost_upload_reply_blocks_resend_and_b_even_after_force(self) -> None:
        """Treat an upload timeout as unknown rather than permission to resend.

        Returns:
            None: A remains uncertain and repeated scans/refreshes create no request.
        """
        self.create('a.png')
        self.create('b.png')

        def lose_reply(
            url: str, path: Path, metadata: dict[str, str], file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Simulate storage followed by a missing HTTP acknowledgement.

            Args:
                url (str): Synthetic captured endpoint.
                path (Path): Current queue head source.
                metadata (dict[str, str]): Synthetic camera metadata.
                file_hash (str): Expected source SHA-256.
                size (int): Expected original byte length.

            Returns:
                dict[str, JsonValue]: Never returns a successful acknowledgement.

            Raises:
                LegacyUploadResponseError: Connection failed after dispatch.
            """
            self.transfer(url, path, metadata, file_hash, size)
            raise self.main.LegacyUploadResponseError(None)

        self.worker.upload_watched_file.side_effect = lose_reply
        self.scan()
        before = list(self.trace)
        self.worker.request_refresh()
        self.now += 3600  # Structural expired-wait vector, never an actual sleep.
        self.scan()
        self.assertEqual(before, self.trace)
        self.assertEqual('uncertain', self.worker._queue.head().state)
        self.assertTrue((self.folder / 'a.png').exists())

    def test_thumbnail_failure_is_retryable_without_any_upload(self) -> None:
        """Classify local preparation errors before the write-ahead dispatch marker.

        Returns:
            None: Head waits automatically and B starts no pipeline stage.
        """
        self.create('a.png')
        self.create('b.png')
        self.worker.upload_watched_file.side_effect = OSError('thumbnail disk full')
        self.scan()
        self.assertEqual([('inventory', 'a.png')], self.trace)
        self.assertEqual('retry_wait', self.worker._queue.head().state)

    def test_invalid_acknowledgement_never_attests_unsent_upload(self) -> None:
        """Keep a rejected success envelope unknown after actual HTTP dispatch.

        Returns:
            None: A generic linking validation error cannot authorize resending A.
        """
        self.create('a.png')
        self.create('b.png')

        def invalid_reply(
            url: str, path: Path, metadata: dict[str, str], file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Model a successful store followed by invalid acknowledgement fields.

            Args:
                url (str): Synthetic captured endpoint.
                path (Path): Current FIFO head source.
                metadata (dict[str, str]): Synthetic camera metadata.
                file_hash (str): Expected source SHA-256.
                size (int): Expected original byte length.

            Returns:
                dict[str, JsonValue]: Never returns a validated acknowledgement.

            Raises:
                LinkingError: The stored upload's response cannot be confirmed.
            """
            self.transfer(url, path, metadata, file_hash, size)
            raise self.main.LinkingError('Gallery upload was not confirmed.')

        self.worker.upload_watched_file.side_effect = invalid_reply
        self.scan()
        self.worker.request_refresh()
        self.scan()
        self.assertEqual('uncertain', self.worker._queue.head().state)
        self.assertEqual([('inventory', 'a.png'), ('upload', 'a.png')], self.trace)

    def test_reappearing_confirmed_bytes_are_not_uploaded_twice(self) -> None:
        """Deduplicate restored OneDrive bytes while accepting changed same-path content.

        Returns:
            None: Restored identical bytes skip; a later new content generation uploads.
        """
        path = self.create('a.png', b'original')
        self.scan()
        original = self.worker._queue.items()[0]
        path.unlink()
        self.create('a.png', b'original')
        self.scan()
        self.assertEqual(1, len([entry for entry in self.trace if entry[0] == 'upload']))
        self.create('a.png', b'new contents')
        self.scan()
        self.assertEqual(2, len([entry for entry in self.trace if entry[0] == 'upload']))
        self.assertEqual(original.item_id, self.worker._queue.items()[0].item_id)

    def test_capture_persists_b_and_c_while_a_upload_is_blocked(self) -> None:
        """Run the actual independent producer during a blocked synchronous upload.

        Returns:
            None: B/C are durable before A finishes and have zero network requests.
        """
        self.create('a.png')
        entered, release, accepted = threading.Event(), threading.Event(), threading.Event()
        snapshot = self.worker.emit_queue_snapshot

        def publish() -> None:
            """Signal only after B/C have reached the real durable journal.

            Returns:
                None: Preserves production snapshots and releases the fixture observer.
            """
            snapshot()
            if len(self.worker._queue.items()) >= 3:
                accepted.set()

        def blocked(
            url: str, path: Path, metadata: dict[str, str], file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Hold A's dispatch while the independent producer observes new files.

            Args:
                url (str): Synthetic captured endpoint.
                path (Path): Current queue head source.
                metadata (dict[str, str]): Synthetic camera metadata.
                file_hash (str): Expected source SHA-256.
                size (int): Expected original byte length.

            Returns:
                dict[str, JsonValue]: Positive acknowledgement after release.
            """
            result = self.transfer(url, path, metadata, file_hash, size)
            if path.name == 'a.png':
                entered.set()
                self.assertTrue(release.wait(FIFO_FIXTURE_WAIT_SECONDS))
            return result

        self.worker.emit_queue_snapshot = publish
        self.worker.upload_watched_file.side_effect = blocked
        self.worker.start()
        try:
            self.assertTrue(entered.wait(FIFO_FIXTURE_WAIT_SECONDS))
            self.create('b.png')
            self.create('c.png')
            self.worker.request_refresh()
            self.assertTrue(accepted.wait(FIFO_FIXTURE_WAIT_SECONDS))
            self.assertEqual([('inventory', 'a.png'), ('upload', 'a.png')], self.trace)
            self.worker.request_drain()
            self.now += self.main.WATCH_QUEUE_STABILITY_MIN_SECONDS
            release.set()
            self.worker.join(FIFO_FIXTURE_WAIT_SECONDS)
            self.assertFalse(self.worker.is_alive())
            self.assertEqual(['a.png', 'b.png', 'c.png'],
                             [name for stage, name in self.trace if stage == 'upload'])
            self.assertTrue(self.worker._queue.data['completed'])
        finally:
            release.set()
            self.worker.stop()
            self.worker._producer_wake.set()
            self.worker.join(FIFO_FIXTURE_WAIT_SECONDS)

    def test_prior_linked_uncertainty_review_resumes_same_never_sent_head(self) -> None:
        """Review older A and dispatch B once without a new capture session.

        Returns:
            None: A remains retained/reviewed while the original B sequence is sent.
        """
        prior = self.folder / 'prior-a.png'
        prior.write_bytes(b'prior-uncertain-a')
        target = SimpleNamespace(
            binding=lambda: 'b' * 64,
            issuer='https://gallery.example.test',
            gallery_id=7,
            session=SimpleNamespace(
                approved_scope=lambda: 'upload', can_upload=lambda: True,
            ),
        )
        state = self.main.LinkedUploadState(target)
        state.save()
        state.mark_uncertain(prior, self.main.sha256_file(prior))
        worker = self.main.WatcherThread(
            self.main.WatcherConfig(
                gallery_url='https://gallery.example.test', api_key='',
                watched_folder=str(self.folder), delete_uploaded_files=False,
                attach_sim_camera_metadata=False, stable_seconds=0.0,
            ), queue.Queue(), target,
        )
        self.addCleanup(worker._linked_lease.release)
        session_dir = self.root / 'linked-session'
        worker.configure_drain(session_dir, [])
        current = self.create('current-b.png', b'never-sent-b')
        worker._queue.reconcile([
            self.main.observe_watch_source(current, self.now),
        ])
        worker.stability = mock.Mock()
        worker.stability.stable.return_value = True
        worker.remote_inventory = mock.Mock()
        worker.remote_inventory.refresh.return_value = True
        worker.remote_inventory.has_hash.return_value = False
        uploads: list[str] = []

        def upload(
            url: str, path: Path, metadata: dict[str, str], file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Record B's one simulated successful dispatch.

            Args:
                url (str): Synthetic gallery endpoint.
                path (Path): Current immutable queue head.
                metadata (dict[str, str]): Optional simulator metadata.
                file_hash (str): Hash of the exact dispatched source.
                size (int): Source size in bytes.

            Returns:
                dict[str, JsonValue]: Success acknowledgement accepted by the owner.
            """
            worker._mark_watch_dispatch()
            uploads.append(path.name)
            return {
                'ok': True, 'upload_evidence': 'fresh_insert_v1',
                'gallery_id': 7, 'uploaded': 1, 'scanned': 1,
                'image_ids': [901], 'filenames': ['gallery_901.png'],
            }

        worker.upload_watched_file = upload
        pipeline = self.main.UploadPipelineLease(self.root / 'profile')
        pipeline.acquire()
        self.addCleanup(pipeline.release)
        worker._pipeline_lease = pipeline
        worker.stability = mock.Mock()
        worker.stability.stable.return_value = True
        worker._consume_head(self.folder, 'https://gallery.example.test')
        self.assertEqual('blocked', worker._queue.head().state)
        self.assertEqual('prior_upload_review', worker._queue.head().phase)
        worker._initialize_recovery_coordinator(pipeline)
        worker.recovery_coordinator.repair_interrupted_decision()
        snapshot = worker.recovery_coordinator.snapshot()
        self.assertIsNotNone(snapshot)
        self.assertTrue(snapshot.review_blocked_by_earlier_upload)
        reviewed = worker.recovery_coordinator.review_earlier_blocker(snapshot)
        self.assertIsNotNone(reviewed)
        self.assertEqual('preparing', worker._queue.head().state)
        self.assertTrue(worker.state.data['failures'][str(prior)]['reviewed'])
        self.assertEqual('current-b.png', Path(worker._queue.head().source.path).name)
        worker._consume_head(self.folder, 'https://gallery.example.test')
        self.assertEqual(['current-b.png'], uploads)
        self.assertEqual('acknowledged', worker._queue.items()[0].state)
        self.assertTrue(prior.exists())
        self.assertTrue(current.exists())
        self.assertEqual('linked-session', worker._queue.data['session_id'])

    def test_force_stop_during_upload_retains_late_acknowledged_source(self) -> None:
        """Keep source bytes when Stop arrives before a valid late ACK.

        Returns:
            None: The exact head is acknowledged, retained and never deleted.
        """
        source = self.create('late-ack.png', b'late-ack-source')
        self.worker.config = replace(self.worker.config, delete_uploaded_files=True)
        self.worker.stability = mock.Mock()
        self.worker.stability.stable.return_value = True
        self.worker.remote_inventory.refresh.return_value = True
        self.worker.remote_inventory.has_hash.return_value = False
        item = self.worker._queue.reconcile([
            self.main.observe_watch_source(source, self.now),
        ])
        self.assertEqual(1, item)

        def upload(
            url: str, path: Path, metadata: dict[str, str], file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Persist Stop while the current synthetic request is returning.

            Args:
                url (str): Synthetic endpoint.
                path (Path): Current queue head source.
                metadata (dict[str, str]): Optional camera metadata.
                file_hash (str): Exact source digest.
                size (int): Original file size in bytes.

            Returns:
                dict[str, JsonValue]: Valid late success for the exact request.
            """
            self.worker._keep_sources_after_stop = True
            self.worker._queue.stop_and_retain(self.now)
            self.worker.stop()
            self.worker._mark_watch_dispatch()
            return {'ok': True, 'uploaded': 1, 'scanned': 1}

        self.worker.upload_watched_file = upload
        self.worker._consume_head(self.folder, 'https://gallery.example.test')
        self.assertTrue(self.worker._queue.data['retained_stop'])
        self.assertEqual('acknowledged', self.worker._queue.items()[0].state)
        self.assertTrue(source.exists())

    def test_force_stop_holds_pipeline_until_cutoff_readback_and_request_end(self) -> None:
        """Keep both fences through Stop publication and the active late ACK.

        Returns:
            None: A failed save keeps the owner gated; success stops only after
                readback and releases the pipeline only after the HTTP owner exits.
        """
        source = self.create('owned-stop.png', b'owned-stop-source')
        entered, release_upload = threading.Event(), threading.Event()
        upload_result: dict[str, JsonValue] = {
            'ok': True, 'uploaded': 1, 'scanned': 1,
        }

        def upload(
            url: str, path: Path, metadata: dict[str, str], file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Hold the exact head after its durable write-ahead boundary.

            Args:
                url (str): Synthetic endpoint.
                path (Path): Active source owned by the queue head.
                metadata (dict[str, str]): Optional camera metadata.
                file_hash (str): Exact source digest.
                size (int): Original file size in bytes.

            Returns:
                dict[str, JsonValue]: Positive late acknowledgement after release.
            """
            self.worker._mark_watch_dispatch()
            entered.set()
            self.assertTrue(release_upload.wait(FIFO_FIXTURE_WAIT_SECONDS))
            return upload_result

        self.worker.upload_watched_file = upload
        self.worker.start()
        self.addCleanup(self.worker.join, FIFO_FIXTURE_WAIT_SECONDS)
        self.addCleanup(self.worker.stop)
        self.addCleanup(release_upload.set)
        app = object.__new__(self.main.WatcherApp)
        app.worker = self.worker
        app.events = self.worker.events
        app.flights = SimpleNamespace(
            ui_language=SimpleNamespace(get=lambda: 'en'),
        )
        app.status_var = SimpleNamespace(set=lambda value: None)
        app._set_watcher_recording = lambda active: None
        self.assertTrue(entered.wait(FIFO_FIXTURE_WAIT_SECONDS))

        original_stop = self.worker._queue.stop_and_retain
        self.worker._queue.stop_and_retain = lambda cutoff: (_ for _ in ()).throw(
            OSError('fixture publication failure'),
        )
        failed_result: list[bool] = []
        self.assertTrue(self.main.WatcherApp.stop_and_keep_watch_pending(
            app, failed_result.append,
        ))
        deadline = time.monotonic() + FIFO_FIXTURE_WAIT_SECONDS
        failure_event = None
        while time.monotonic() < deadline and failure_event is None:
            try:
                event = self.worker.events.get(timeout=FIFO_EVENT_POLL_SECONDS)
            except queue.Empty:
                continue
            if isinstance(event, dict) and event.get('kind') == 'watch_stop_retained':
                failure_event = event
        self.assertIsNotNone(failure_event)
        failure_event['payload']['on_persisted'](False)
        self.assertEqual([False], failed_result)
        self.assertTrue(self.worker.is_alive())
        self.assertFalse(self.worker.stop_event.is_set())
        first_cutoff = self.worker._retained_stop_cutoff
        late_source = self.create('after-stop-click.png', b'after-click')
        self.assertEqual(0, self.worker._reconcile_watch_queue(self.folder))
        self.assertNotIn(
            str(late_source),
            [item.source.path for item in self.worker._queue.items()],
        )

        contender = self.main.UploadPipelineLease(self.root / 'profile')
        self.addCleanup(contender.release)
        with self.assertRaises(self.main.LinkingError):
            contender.acquire()

        persist_started, persist_release = threading.Event(), threading.Event()
        persisted_cutoffs: list[float] = []

        def delayed_stop(cutoff: float) -> None:
            """Hold Stop publication to expose ownership before readback.

            Args:
                cutoff (float): Stop time captured by the UI request.

            Returns:
                None: Publishes the original queue boundary after test release.
            """
            persist_started.set()
            self.assertTrue(persist_release.wait(FIFO_FIXTURE_WAIT_SECONDS))
            persisted_cutoffs.append(cutoff)
            original_stop(cutoff)

        self.worker._queue.stop_and_retain = delayed_stop
        saved_result: list[bool] = []
        self.assertTrue(self.main.WatcherApp.stop_and_keep_watch_pending(
            app, saved_result.append,
        ))
        self.assertTrue(persist_started.wait(FIFO_FIXTURE_WAIT_SECONDS))
        self.assertEqual(first_cutoff, self.worker._retained_stop_cutoff)
        self.assertFalse(self.worker.stop_event.is_set())
        with self.assertRaises(self.main.LinkingError):
            contender.acquire()
        persist_release.set()

        deadline = time.monotonic() + FIFO_FIXTURE_WAIT_SECONDS
        success_event = None
        while time.monotonic() < deadline and success_event is None:
            try:
                event = self.worker.events.get(timeout=FIFO_EVENT_POLL_SECONDS)
            except queue.Empty:
                continue
            if isinstance(event, dict) and event.get('kind') == 'watch_stop_retained':
                success_event = event
        self.assertIsNotNone(success_event)
        self.assertEqual([first_cutoff], persisted_cutoffs)
        success_event['payload']['on_persisted'](True)
        self.assertEqual([True], saved_result)
        self.assertTrue(self.worker.stop_event.is_set())
        self.assertTrue(self.worker.is_alive())
        release_upload.set()
        self.worker.join(FIFO_FIXTURE_WAIT_SECONDS)
        self.assertFalse(self.worker.is_alive())
        self.assertTrue(source.exists())
        self.assertTrue(self.worker._queue.data['retained_stop'])
        self.assertEqual('acknowledged', self.worker._queue.items()[0].state)
        contender.acquire()
        contender.release()

    def test_explicit_resume_after_failed_force_stop_restores_capture_and_finish(self) -> None:
        """Resume the same Finish owner and producer only after explicit review.

        Returns:
            None: The pending source drains, a fresh scan completes Finish,
                and a post-cutoff source stays outside the original session.
        """
        source = self.create('resume-after-stop-failure.png', b'pending source')
        self.worker._queue.reconcile([
            self.main.observe_watch_source(source, self.now),
        ])
        original_cutoff = self.now
        self.worker._queue.finish(original_cutoff)
        attempts = 0
        uploaded: list[str] = []

        def fail_once_then_upload(
            url: str, path: Path, metadata: dict[str, str],
            file_hash: str, size: int,
        ) -> dict[str, JsonValue]:
            """Keep one safe retry pending, then acknowledge after explicit Resume.

            Args:
                url (str): Captured synthetic endpoint.
                path (Path): Exact pending original owned by this queue head.
                metadata (dict[str, str]): Optional camera metadata fields.
                file_hash (str): Exact source digest from the production worker.
                size (int): Original byte size from the source snapshot.

            Returns:
                dict[str, JsonValue]: Valid fixture acknowledgement after retry.

            Raises:
                UploadPreparationError: First attempt fails before dispatch.
            """
            nonlocal attempts
            attempts += 1
            if attempts == 1:
                raise self.main.UploadPreparationError('Synthetic local preparation failure.')
            self.worker._mark_watch_dispatch()
            uploaded.append(path.name)
            return {'ok': True, 'uploaded': 1, 'scanned': 1}

        self.worker.upload_watched_file = fail_once_then_upload
        self.worker.start()
        self.addCleanup(self.worker.join, FIFO_FIXTURE_WAIT_SECONDS)
        self.addCleanup(self.worker.stop)
        deadline = time.monotonic() + FIFO_FIXTURE_WAIT_SECONDS
        while time.monotonic() < deadline:
            head = self.worker._queue.head()
            if head is not None and head.state == 'retry_wait':
                break
            try:
                self.worker.events.get(timeout=FIFO_EVENT_POLL_SECONDS)
            except queue.Empty:
                pass
        head = self.worker._queue.head()
        self.assertIsNotNone(head)
        self.assertEqual('retry_wait', head.state)
        self.assertEqual(1, attempts)
        producer = self.worker._producer_thread
        self.assertIsNotNone(producer)

        app = object.__new__(self.main.WatcherApp)
        app.worker = self.worker
        app.events = self.worker.events
        app.flights = SimpleNamespace(
            ui_language=SimpleNamespace(get=lambda: 'en'),
        )
        app.status_var = SimpleNamespace(set=lambda value: None)
        app._set_watcher_recording = lambda active: None

        def failed_publication(cutoff: float) -> None:
            """Fail retention publication while leaving the live owner fenced.

            Args:
                cutoff (float): Captured original Force Stop cutoff.

            Returns:
                None: Simulates a local persistence failure before readback.

            Raises:
                OSError: The fixture's deliberate publication failure.
            """
            raise OSError('Synthetic retained-stop publication failure.')

        self.worker._queue.stop_and_retain = failed_publication
        stop_result: list[bool] = []
        self.assertTrue(self.main.WatcherApp.stop_and_keep_watch_pending(
            app, stop_result.append,
        ))
        deadline = time.monotonic() + FIFO_FIXTURE_WAIT_SECONDS
        while time.monotonic() < deadline and not stop_result:
            try:
                event = self.worker.events.get(timeout=FIFO_EVENT_POLL_SECONDS)
            except queue.Empty:
                continue
            if isinstance(event, dict) and event.get('kind') == 'watch_stop_retained':
                event['payload']['on_persisted'](False)
        self.assertEqual([False], stop_result)
        self.assertFalse(self.worker.stop_event.is_set())
        self.assertTrue(self.worker._recovery_review_only)
        assert producer is not None
        producer.join(FIFO_FIXTURE_WAIT_SECONDS)
        self.assertFalse(producer.is_alive())

        late_source = self.create('arrived-after-finish-cutoff.png', b'late source')
        self.assertGreater(self.now, original_cutoff)
        snapshot = self.worker.recovery_coordinator.snapshot()
        self.assertIsNotNone(snapshot)
        self.assertFalse(snapshot.retained_stop)
        self.assertIsNotNone(self.worker.recovery_coordinator.resume_retained(snapshot))
        self.main.WatcherApp._watch_recovery_resolved(app, self.worker)
        self.assertFalse(self.worker._force_stop_requested.is_set())
        self.assertFalse(self.worker._producer_stop.is_set())
        self.now = max(self.now, head.next_retry_at)
        self.worker._consumer_wake.set()

        self.worker.join(FIFO_FIXTURE_WAIT_SECONDS)
        self.assertFalse(self.worker.is_alive())
        self.assertEqual(['resume-after-stop-failure.png'], uploaded)
        self.assertTrue(source.exists())
        self.assertNotIn(
            str(late_source),
            [item.source.path for item in self.worker._queue.items()],
        )
        self.assertEqual(original_cutoff, self.worker._queue.data['cutoff'])
        self.assertTrue(self.worker._queue.data['completed'])
        self.assertGreater(
            self.worker._queue.data['scan_generation'],
            self.worker._queue.data['last_change_scan_generation'],
        )
