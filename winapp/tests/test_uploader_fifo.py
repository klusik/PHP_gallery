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
