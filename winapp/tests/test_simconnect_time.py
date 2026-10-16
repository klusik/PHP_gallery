# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_simconnect_time.py
# Module Type: Regression Test
# Purpose: Verify guarded UTC synchronization without a simulator or uploads.
# Responsibilities:
#   - Exercise both native handshakes, date events, readback and cancellation.
#   - Cover saved opt-in, watcher/tray admission and updater worker drain.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native-wire and lifecycle regressions; live simulator behavior stays unverified."""

import ctypes
from datetime import datetime, timedelta, timezone
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import simconnect_time as UTC
from uploader import simconnect_location as SIM
from test_simconnect import FakeSimConnectDLL, NativeFunction, packet_header
from test_redesign import MAIN


class FakeClock:
    """Injectable monotonic clock; native waits advance without real sleeping."""

    def __init__(self) -> None:
        """Start at zero so exact settling/backoff boundaries are reproducible.

        Returns:
            None: Creates the fixture clock.
        """
        self.value = 0.0

    def now(self) -> float:
        """Read the current fixture monotonic timestamp.

        Returns:
            float: Seconds advanced by fixture waits.
        """
        return self.value

    def wait(self, seconds: float) -> bool:
        """Advance a requested wait without sleeping the test runner.

        Args:
            seconds (float): Requested bounded wait in seconds.

        Returns:
            bool: False; this clock does not trigger cancellation itself.
        """
        self.value += seconds
        return False


class FakeTimeDLL(FakeSimConnectDLL):
    """Reuse the existing native double, adding full UTC/state/event packets."""

    def __init__(self, generation: int = 2024, apply: bool = True) -> None:
        """Choose simulator identity and whether time events actually take effect.

        Args:
            generation (int): Supported OPEN identity, 2020 or 2024.
            apply (bool): False models accepted HRESULTs with ignored setters.

        Returns:
            None: Creates deterministic state and assignable native exports.
        """
        super().__init__(generation=generation, camera_exports=False)
        self.host = datetime(2026, 10, 16, 20, 40, tzinfo=timezone.utc)
        # A four-hour error crosses UTC midnight, matching the reported defect.
        self.utc = self.host + timedelta(hours=4)
        self.apply = apply
        self.rate = 1.0
        self.view = 2.0  # SDK cockpit enum; this is a structural test vector.
        self.sent_values: list[tuple[int, int]] = []
        self.parts = list(UTC.utc_event_values(self.utc))
        self.setter_exception = False
        self.mapping_exception = False
        self.foreign_reply = False
        self.truncated = False
        self.abs_override: float | None = None
        self.read_count = 0
        for name, function in {
            "SubscribeToSystemEvent": self.subscribe,
            "RequestSystemState": self.system_state,
            "MapClientEventToSimEvent": self.map_event,
            "TransmitClientEvent": self.transmit,
        }.items():
            setattr(self, "SimConnect_" + name, NativeFunction(function))

    def event(self, event_id: int, value: int) -> None:
        """Queue an actual packed Sim/Start/Stop/Pause_EX1 packet.

        Args:
            event_id (int): UTC client's subscribed event ID.
            value (int): Running bit or pause flags.

        Returns:
            None: Appends one complete native event response.
        """
        packet = packet_header(UTC._SystemEvent(), UTC.RECV_EVENT)
        packet.uEventID = event_id
        packet.dwData = value
        self.packets.append((packet, ctypes.sizeof(packet)))

    def subscribe(
        self, _handle: ctypes.c_void_p, event_id: int, name: bytes
    ) -> int:
        """Supply the SDK's immediate Sim and Pause_EX1 state notifications.

        Args:
            _handle (ctypes.c_void_p): Unused native connection handle.
            event_id (int): Client's requested event ID.
            name (bytes): ASCII system-event name.

        Returns:
            int: Successful HRESULT; initial state remains in the queue.
        """
        self.send("subscribe", (event_id, name))
        if name in (b"Sim", b"Pause_EX1"):
            self.event(event_id, int(name == b"Sim"))
        return 0

    def system_state(
        self, _handle: ctypes.c_void_p, request_id: int, name: bytes
    ) -> int:
        """Model joining a flight after its FlightLoaded notification has passed.

        Args:
            _handle (ctypes.c_void_p): Unused native connection handle.
            request_id (int): Correlated initial FlightLoaded request.
            name (bytes): Requested system-state name.

        Returns:
            int: Successful HRESULT with a full system-state response queued.
        """
        self.send("system_state", (request_id, name))
        packet = packet_header(UTC._SystemState(), UTC.RECV_SYSTEM_STATE)
        packet.dwRequestID = request_id
        packet.szString = b"synthetic-flight.FLT"
        self.packets.append((packet, ctypes.sizeof(packet)))
        return 0

    def map_event(
        self, _handle: ctypes.c_void_p, event_id: int, name: bytes
    ) -> int:
        """Record common event mapping and optionally reject it asynchronously.

        Args:
            _handle (ctypes.c_void_p): Unused native connection handle.
            event_id (int): Client's mapped setter ID.
            name (bytes): ASCII Zulu setter name.

        Returns:
            int: Successful HRESULT, including asynchronous rejection cases.
        """
        self.send("map", (event_id, name))
        if self.mapping_exception:
            packet = self.exception_packet(self.last_send, exception=3)
            self.packets.append((packet, ctypes.sizeof(packet)))
        return 0

    def transmit(self, *arguments: int | ctypes.c_void_p) -> int:
        """Apply date/time only after all four ordered Zulu events arrive.

        Args:
            arguments (int|ctypes.c_void_p): Handle, object, event, value,
                priority and flags in native call order.

        Returns:
            int: Successful HRESULT; exceptions/ignored writes remain possible.
        """
        self.send("transmit", arguments)
        event_id, value = int(arguments[2]), int(arguments[3])
        self.sent_values.append((event_id, value))
        if self.setter_exception:
            packet = self.exception_packet(self.last_send, exception=3)
            self.packets.append((packet, ctypes.sizeof(packet)))
            return 0
        index = event_id - UTC.UTC_FIRST_SET_EVENT
        self.parts[index] = value
        if index == len(UTC.UTC_SET_EVENTS) - 1 and self.apply:
            year, ordinal, hour, minute = self.parts
            self.utc = datetime(year, 1, 1, tzinfo=timezone.utc) + timedelta(
                days=ordinal - 1, hours=hour, minutes=minute
            )
        return 0

    def request_aircraft(self, *arguments: int | ctypes.c_void_p) -> int:
        """Return three clock/state FLOAT64s, preserving actual request identity.

        Args:
            arguments (int|ctypes.c_void_p): Native data request parameters.

        Returns:
            int: Successful HRESULT with an independent packed wire response.
        """
        self.send("time_read", arguments)
        self.read_count += 1
        packet = packet_header(UTC._TimeData(), SIM.SIMCONNECT_RECV_ID_SIMOBJECT_DATA)
        packet.dwRequestID = int(arguments[1]) + int(self.foreign_reply)
        packet.dwDefineID = int(arguments[2])
        # USER is a request selector; the returned actual object may be nonzero.
        packet.dwObjectID = 7
        packet.dwentrynumber = 1
        packet.dwoutof = 1
        packet.dwDefineCount = 3
        packet.absolute = (
            (self.utc - UTC.UTC_EPOCH).total_seconds()
            if self.abs_override is None else self.abs_override
        )
        packet.rate = self.rate
        packet.camera = self.view
        size = ctypes.sizeof(packet) - int(self.truncated)
        self.packets.append((packet, size))
        return 0


class UtcTransportTests(unittest.TestCase):
    """Guard UTC writes with real callback packets and both SDK identities."""

    def connected(
        self, dll: FakeTimeDLL
    ) -> tuple[UTC.SimConnectTimeClient, UTC.UtcSyncWorker, FakeClock]:
        """Create a settled real transport over a fake DLL, with cleanup registered.

        Scope Windows emulation to the transport so host path/profile lookup
        retains the actual platform during diagnostics.

        Args:
            dll (FakeTimeDLL): Native fixture providing OPEN, state and data.

        Returns:
            tuple[SimConnectTimeClient, UtcSyncWorker, FakeClock]: Connected
                client, policy worker and injected clock; no real native APIs
                run.
        """
        clock = FakeClock()
        worker = UTC.UtcSyncWorker(
            "", lambda: True, lambda _level, _message: None,
            utc_now=lambda: dll.host, monotonic=clock.now,
        )
        worker.cancelled.wait = clock.wait
        client = UTC.SimConnectTimeClient(
            "", worker.cancelled, worker.allowed, worker.write_lock, clock.now
        )
        path = WINAPP_DIR / "SimConnect.dll"
        with (
            mock.patch.object(UTC, "os", SimpleNamespace(name="nt")),
            mock.patch.object(client, "resolve_dll_path", return_value=(path, [])),
            mock.patch.object(ctypes, "WinDLL", return_value=dll, create=True),
        ):
            client.open()
        self.addCleanup(client.close)
        client.pump(0)
        clock.value += UTC.UTC_SETTLE_SECONDS
        return client, worker, clock

    def test_both_handshakes_correct_full_date_and_fresh_readback(self) -> None:
        """Correct the reported midnight mismatch for both simulator identities.

        Returns:
            None: Asserts event values, fresh reads and no repeated rewrites.
        """
        for generation in (2020, 2024):
            with self.subTest(generation=generation):
                dll = FakeTimeDLL(generation)
                client, worker, _clock = self.connected(dll)
                worker.check(client)
                self.assertEqual(client.simulator_info.generation, generation)
                self.assertEqual([2026, 289, 20, 40], [v for _, v in dll.sent_values])
                self.assertEqual(2, dll.read_count)
                self.assertIn("correction verified", worker.status)
                worker.check(client)
                self.assertEqual(4, len(dll.sent_values))
                self.assertFalse(any(
                    name.startswith("camera") for name, _ in dll.calls
                ))

    def test_threshold_boundaries_in_both_directions(self) -> None:
        """Allow exactly 60 seconds and correct 61 seconds in either direction.

        Returns:
            None: Asserts strict absolute-delta behavior, without local time.
        """
        for delta in (-61, -60, 0, 60, 61):
            with self.subTest(delta=delta):
                dll = FakeTimeDLL()
                dll.utc = dll.host + timedelta(seconds=delta)
                client, worker, _clock = self.connected(dll)
                worker.check(client)
                self.assertEqual(abs(delta) > 60, bool(dll.sent_values))

    def test_calendar_epoch_and_ordinal_setters(self) -> None:
        """Cover year/month rollover, leap day, ordinal 366 and timezone conversion.

        Returns:
            None: Asserts exact calendar values independently of fake setters.
        """
        vectors = (
            (datetime(2024, 2, 29, tzinfo=timezone.utc), 60),
            (datetime(2024, 12, 31, tzinfo=timezone.utc), 366),
            (datetime(2025, 1, 1, tzinfo=timezone.utc), 1),
            (datetime(2026, 3, 1, tzinfo=timezone.utc), 60),
        )
        for target, ordinal in vectors:
            with self.subTest(target=target):
                seconds = (target - UTC.UTC_EPOCH).total_seconds()
                self.assertEqual(target, UTC.absolute_time_utc(seconds))
                self.assertEqual(
                    (target.year, ordinal, 0, 0), UTC.utc_event_values(target)
                )
        offset = timezone(timedelta(hours=9))
        local = datetime(2025, 1, 1, 1, 0, tzinfo=offset)
        self.assertEqual((2024, 366, 16, 0), UTC.utc_event_values(local))
        for invalid in (float("nan"), float("inf"), -1.0, 1e30):
            with self.subTest(invalid=invalid), self.assertRaises(ValueError):
                UTC.absolute_time_utc(invalid)
        with self.assertRaises(ValueError):
            UTC.utc_event_values(datetime(2026, 1, 1))

    def test_loading_pause_menu_and_rate_never_write(self) -> None:
        """Suspend unsafe states, including active pause and non-1x rates.

        Returns:
            None: Asserts each unsafe condition leaves native setters unused.
        """
        for state in (
            "stopped", "unloaded", "pause", "active_pause",
            "menu", "settling", "rate",
        ):
            with self.subTest(state=state):
                dll = FakeTimeDLL()
                client, worker, clock = self.connected(dll)
                if state == "stopped":
                    dll.event(UTC.UTC_STOP_EVENT, 0)
                elif state == "unloaded":
                    client.flight_loaded = False
                elif state in ("pause", "active_pause"):
                    dll.event(UTC.UTC_PAUSE_EVENT, 4 if state == "active_pause" else 1)
                elif state == "menu":
                    dll.view = 15
                elif state == "settling":
                    client.changed_at = clock.now()
                else:
                    dll.rate = 4.0
                worker.check(client)
                self.assertEqual([], dll.sent_values)

    def test_repeat_flight_transitions_and_stale_samples(self) -> None:
        """Reject a previous flight's sample after queued stop/start transitions.

        Returns:
            None: Asserts settling is renewed before a second flight can write.
        """
        dll = FakeTimeDLL()
        client, worker, clock = self.connected(dll)
        sample = client.read()
        dll.event(UTC.UTC_STOP_EVENT, 0)
        dll.event(UTC.UTC_START_EVENT, 0)
        self.assertFalse(client.correct(sample, dll.host))
        self.assertEqual([], dll.sent_values)
        clock.value += UTC.UTC_SETTLE_SECONDS
        worker.check(client)
        self.assertIn("verified", worker.status)

    def test_ignored_events_have_bounded_backoff_for_both_generations(self) -> None:
        """Suspend an incompatible connection after three unverified attempts.

        Returns:
            None: Asserts no endless writes and readback remains mandatory.
        """
        for generation in (2020, 2024):
            with self.subTest(generation=generation):
                dll = FakeTimeDLL(generation, apply=False)
                client, worker, clock = self.connected(dll)
                worker.check(client)
                count = len(dll.sent_values)
                worker.check(client)
                self.assertEqual(count, len(dll.sent_values))
                for _attempt in range(UTC.UTC_MAX_ATTEMPTS - 1):
                    clock.value += UTC.UTC_RETRY_SECONDS
                    worker.check(client)
                self.assertTrue(worker.unsupported)
                count = len(dll.sent_values)
                clock.value += UTC.UTC_RETRY_SECONDS
                worker.check(client)
                self.assertEqual(count, len(dll.sent_values))
                self.assertIn("unsupported", worker.status)

    def test_asynchronous_setter_exception_is_correlated(self) -> None:
        """Reject accepted sends when the matching asynchronous exception arrives.

        Returns:
            None: Asserts failure/backoff instead of falsely verified success.
        """
        dll = FakeTimeDLL()
        dll.setter_exception = True
        client, worker, _clock = self.connected(dll)
        worker.check(client)
        self.assertEqual(1, worker.failures)
        self.assertIn("unverified", worker.status)
        self.assertIn("send=", worker.status)

    def test_invalid_missing_and_foreign_packets_do_not_write(self) -> None:
        """Treat nonfinite/truncated/mismatched data as non-writing failures.

        Returns:
            None: Asserts each invalid input leaves simulator events unused.
        """
        for mode in ("nan", "truncated", "foreign"):
            with self.subTest(mode=mode):
                dll = FakeTimeDLL()
                client, worker, _clock = self.connected(dll)
                dll.abs_override = float("nan") if mode == "nan" else None
                dll.truncated = mode == "truncated"
                dll.foreign_reply = mode == "foreign"
                if mode == "foreign":
                    worker.check(client)
                else:
                    with self.assertRaises(OSError):
                        worker.check(client)
                self.assertEqual([], dll.sent_values)

    def test_disconnect_and_cancellation_revoke_writes(self) -> None:
        """Do not send after checkbox stop, watcher stop or simulator QUIT.

        Returns:
            None: Asserts native correction cannot outlive admission.
        """
        for mode in ("cancel", "watcher", "quit"):
            with self.subTest(mode=mode):
                dll = FakeTimeDLL()
                client, worker, _clock = self.connected(dll)
                sample = client.read()
                if mode == "cancel":
                    worker.stop()
                elif mode == "watcher":
                    client.allowed = lambda: False
                else:
                    packet = packet_header(
                        SIM._SimConnectRecv(), SIM.SIMCONNECT_RECV_ID_QUIT
                    )
                    dll.packets.append((packet, ctypes.sizeof(packet)))
                if mode == "quit":
                    with self.assertRaises(OSError):
                        client.correct(sample, dll.host)
                else:
                    self.assertFalse(client.correct(sample, dll.host))
                self.assertEqual([], dll.sent_values)

    def test_reconnect_reprobes_after_unsupported_connection(self) -> None:
        """Reprobe a new simulator after bounded ignored setters and disconnection.

        Keep real host profile lookup and diagnostic redaction active through
        the disconnect path, including on Linux CI runners.

        Returns:
            None: Asserts a 2024 failure cannot disable a later 2020 session.
        """
        first = FakeTimeDLL(2024, apply=False)
        second = FakeTimeDLL(2020)
        clock = FakeClock()
        messages: list[str] = []

        def emit(_level: str, message: str) -> None:
            """Disconnect the rejected build, then stop after verified recovery.

            Args:
                _level (str): Unused ordinary activity severity.
                message (str): State-change diagnostic from the real policy
                    loop.

            Returns:
                None: Queues QUIT or revokes admission at the test boundary.
            """
            messages.append(message)
            if "unsupported for this connection" in message:
                packet = packet_header(
                    SIM._SimConnectRecv(), SIM.SIMCONNECT_RECV_ID_QUIT
                )
                first.packets.append((packet, ctypes.sizeof(packet)))
            elif "correction verified" in message:
                worker.stop()

        # 150 virtual seconds bound the scripted three-attempt/reconnect vector.
        worker = UTC.UtcSyncWorker(
            "", lambda: clock.now() < 150, emit,
            utc_now=lambda: second.host, monotonic=clock.now,
        )
        worker.cancelled.wait = clock.wait
        path = WINAPP_DIR / "SimConnect.dll"
        with (
            mock.patch.object(UTC, "os", SimpleNamespace(name="nt")),
            mock.patch.object(
                UTC.SimConnectTimeClient, "resolve_dll_path",
                return_value=(path, []),
            ),
            mock.patch.object(
                ctypes, "WinDLL", side_effect=(first, second), create=True
            ),
        ):
            worker.run()
        self.assertTrue(any("unsupported" in message for message in messages))
        self.assertTrue(any(
            "awaiting simulator" in message and "closed by server" in message
            for message in messages
        ))
        self.assertTrue(any("correction verified" in message for message in messages))
        self.assertIn("2020", worker.simulator)
        self.assertFalse(worker.unsupported)
        self.assertEqual(1, first.close_count)
        self.assertEqual(1, second.close_count)

    def test_mapping_exception_is_a_connection_capability_failure(self) -> None:
        """A native event-mapping rejection is not successful UTC support.

        Returns:
            None: Asserts correlated setup errors prevent clock writes.
        """
        dll = FakeTimeDLL()
        client, _worker, _clock = self.connected(dll)
        client.send_ids[dll.last_send] = "MapClientEventToSimEvent"
        packet = dll.exception_packet(dll.last_send, exception=3)
        dll.packets.append((packet, ctypes.sizeof(packet)))
        with self.assertRaises(OSError):
            client.pump(0)
        self.assertTrue(client.capability_error)
        self.assertEqual([], dll.sent_values)


class UtcLifecycleTests(unittest.TestCase):
    """Preserve saved settings and exact watcher admission without a Tk display."""

    def test_saved_setting_defaults_off_and_roundtrips(self) -> None:
        """Old/malformed configs stay opt-out; true survives serialization.

        Returns:
            None: Asserts backward-compatible, explicit boolean opt-in.
        """
        self.assertFalse(MAIN.WatcherConfig.from_dict({}).sync_utc_time)
        malformed = MAIN.WatcherConfig.from_dict({"sync_utc_time": "false"})
        self.assertFalse(malformed.sync_utc_time)
        config = MAIN.WatcherConfig(sync_utc_time=True)
        self.assertTrue(MAIN.WatcherConfig.from_dict(config.to_dict()).sync_utc_time)

    def test_toggle_persists_only_preference_and_stops_before_save(self) -> None:
        """Do not persist unsaved credentials when changing the UTC checkbox.

        Returns:
            None: Asserts immediate cancellation and preservation of settings.
        """
        app = mock.Mock()
        app.exiting = False
        app.updates.blocked.return_value = False
        app.config = MAIN.WatcherConfig(api_key="saved", sync_utc_time=True)
        app.sync_utc_time_var.get.return_value = False
        app.current_config.side_effect = AssertionError("Must not read unsaved form")
        MAIN.WatcherApp.toggle_utc_sync(app)
        self.assertFalse(app.config.sync_utc_time)
        self.assertEqual("saved", app.config.api_key)
        self.assertLess(
            app.mock_calls.index(mock.call.utc_sync.stop()),
            next(
                i for i, call in enumerate(app.mock_calls)
                if call[0] == "config_store.save"
            ),
        )

    def test_watcher_admission_and_tray_share_start_stop(self) -> None:
        """Selected UTC work follows the exact watcher and its stop signal.

        Returns:
            None: Asserts off/stopped/draining states cannot start native work.
        """
        app = mock.Mock()
        app.exiting = False
        app.config = MAIN.WatcherConfig(sync_utc_time=True)
        app.worker.is_alive.return_value = True
        app.worker.stop_event.is_set.return_value = False
        MAIN.WatcherApp.refresh_utc_sync(app)
        allowed = app.utc_sync.start.call_args.args[1]
        self.assertTrue(allowed())
        app.worker.stop_event.is_set.return_value = True
        self.assertFalse(allowed())
        MAIN.WatcherApp.refresh_utc_sync(app)
        app.utc_sync.stop.assert_called_once()
        MAIN.WatcherApp.tray_stop_watching(app)
        app.schedule_ui.assert_called_once_with(app.stop)

    def test_manager_retains_cancelled_workers_for_update_drain(self) -> None:
        """Rapid off/on creates a new worker and keeps the old one drainable.

        Returns:
            None: Asserts no cancelled worker is revived or lost to shutdown.
        """
        with mock.patch.object(UTC, "UtcSyncWorker") as factory:
            old, new = mock.Mock(), mock.Mock()
            old.is_alive.return_value = True
            new.is_alive.return_value = True
            factory.side_effect = (old, new)
            manager = UTC.UtcSyncManager(lambda _level, _message: None)
            manager.start("", lambda: True)
            manager.stop()
            manager.start("", lambda: True)
            self.assertEqual([old, new], manager.workers)
            self.assertTrue(manager.is_alive())
            manager.stop()
            manager.join()
            old.stop.assert_called()
            old.join.assert_called_once()
            new.stop.assert_called_once()
            new.join.assert_called_once()


if __name__ == "__main__":
    unittest.main()
