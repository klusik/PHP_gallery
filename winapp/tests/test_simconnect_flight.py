# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_simconnect_flight.py
# Module Type: SimConnect Flight Tests
# Purpose: Verify persistent read-only telemetry transport and discontinuities.
# Responsibilities:
#   - Exercise MSFS 2020/2024 handshakes with fake native packets.
#   - Check user-aircraft values and optional signal isolation.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Deterministic persistent SimConnect flight-provider regression tests."""
import ctypes
from typing import Callable, TypeAlias
from pathlib import Path
import sys
import unittest
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import simconnect_flight as flight
from uploader.simconnect_location import (
    SIMCONNECT_RECV_ID_OPEN,
    SIMCONNECT_RECV_ID_QUIT,
    _SimConnectRecv,
    _SimConnectRecvOpen,
)



# Type: TypeAlias. Units: not applicable. Scope: fake SimConnect exports.
# Consumers: NativeCallable, FakeFlightDLL and their variadic SDK fixtures.
# Rationale: model only values actually passed through the native test seam.
# Members are int SDK IDs/enums, float epsilon, bytes names, None optional
# arguments, c_void_p handles, c_uint32 values, byref c_void_p/c_uint32 slots,
# or the receive callback taking (pointer to _SimConnectRecv, uint32 size,
# c_void_p context) and returning None. Each Args block states SDK order.
NativeFlightArgument: TypeAlias = (
    int
    | float
    | bytes
    | None
    | ctypes.c_void_p
    | ctypes.c_uint32
    | type(ctypes.byref(ctypes.c_void_p()))
    | Callable[
        [ctypes.POINTER(_SimConnectRecv), int, ctypes.c_void_p],
        None,
    ]
)


class NativeCallable:
    """Callable fixture exposing writable ctypes signature attributes."""

    def __init__(self, callback: Callable[..., int]) -> None:
        """Store the callback and native signature metadata.

        Args:
            callback (Callable[..., int]): Fake export returning an HRESULT.

        Returns:
            None: The callable wrapper is ready for use.
        """
        self.callback = callback
        self.argtypes = None
        self.restype = None

    def __call__(self, *arguments: NativeFlightArgument) -> int:
        """Forward native-style arguments.

        Args:
            arguments (NativeFlightArgument): Each native value in SDK order. Open
                receives a c_void_p by-reference output, client-name bytes,
                optional window handle, event ID, optional event handle and
                configuration index. Close receives one c_void_p handle.
                CallDispatch receives a
                handle, receive callback, and optional context. The packet-ID
                export receives a handle and c_uint32 by-reference output.
                AddToDataDefinition receives handle, definition ID, variable
                bytes, optional units, datatype, epsilon and datum ID.
                RequestDataOnSimObject receives handle, request ID, definition
                ID, object ID, period, flags, origin, interval and limit.
                Event and state subscriptions receive a handle, ID and name.

        Returns:
            int: Fake SDK HRESULT returned by the native export callback.
        """
        return self.callback(*arguments)


class FakeFlightDLL:
    """Fake native DLL dispatching real packed SDK receive structures."""

    def __init__(self, generation: int) -> None:
        """Prepare handshake/data responses for one simulator generation.

        Args:
            generation (int): Supported simulator generation, 2020 or 2024.

        Returns:
            None: Native exports are installed as signature-aware callables.
        """
        self.generation = generation
        self.queue = []
        self.sent = 0
        self.calls = []
        self.closed = 0
        exports = {
            "Open": self.open,
            "Close": self.close,
            "CallDispatch": self.call_dispatch,
            "GetLastSentPacketID": self.last_sent,
            "AddToDataDefinition": self.add_definition,
            "RequestDataOnSimObject": self.request_data,
            "SubscribeToSystemEvent": self.subscribe,
            "RequestSystemState": self.request_state,
        }
        for name, callback in exports.items():
            setattr(self, "SimConnect_" + name, NativeCallable(callback))

    def next_send(
        self,
        name: str,
        arguments: tuple[NativeFlightArgument, ...],
    ) -> int:
        """Record a native send and advance its send identity.

        Args:
            name (str): Export purpose label.
            arguments (tuple[NativeFlightArgument, ...]): Values after the handle, varying by
                SDK export. Open has name/window/event/config values; definition
                has ID/name/units/type/epsilon/datum ID; data request has
                request/definition/object IDs, period, flags, origin, interval
                and limit; event/state requests have ID/name. Close has none.

        Returns:
            int: Successful fake HRESULT.
        """
        self.sent += 1
        self.calls.append((name, arguments))
        return 0

    def open(
        self,
        output_handle: ctypes.c_void_p,
        *arguments: NativeFlightArgument,
    ) -> int:
        """Set a native handle and queue a generation-specific OPEN packet.

        Args:
            output_handle (ctypes.c_void_p): By-reference output slot for the
                native SimConnect handle.
            arguments (NativeFlightArgument): SimConnect_Open values after
                output_handle, in order: client-name bytes, optional HWND,
                Win32 event ID, optional event HANDLE and configuration index.

        Returns:
            int: Successful fake HRESULT.
        """
        self.next_send("open", arguments)
        ctypes.cast(output_handle, ctypes.POINTER(ctypes.c_void_p))[0] = ctypes.c_void_p(77)
        packet = _SimConnectRecvOpen()
        packet.dwSize = ctypes.sizeof(packet)
        packet.dwID = SIMCONNECT_RECV_ID_OPEN
        packet.szApplicationName = b"KittyHawk" if self.generation == 2020 else b"SunRise"
        packet.dwApplicationVersionMajor = 11 if self.generation == 2020 else 12
        packet.dwApplicationVersionMinor = 0
        packet.dwApplicationBuildMajor = 282174
        packet.dwApplicationBuildMinor = 999
        packet.dwSimConnectVersionMajor = 11
        packet.dwSimConnectVersionMinor = 0
        packet.dwSimConnectBuildMajor = 62651
        packet.dwSimConnectBuildMinor = 3
        self.queue.append((packet, ctypes.sizeof(packet)))
        return 0

    def close(self, _handle: ctypes.c_void_p) -> int:
        """Count native close calls.

        Args:
            _handle (ctypes.c_void_p): Native SimConnect handle being closed.

        Returns:
            int: Successful fake HRESULT.
        """
        self.closed += 1
        return self.next_send("close", ())

    def call_dispatch(
        self,
        _handle: ctypes.c_void_p,
        callback: Callable[
            [ctypes.POINTER(_SimConnectRecv), int, ctypes.c_void_p], None
        ],
        context: ctypes.c_void_p | None,
    ) -> int:
        """Deliver one packet through the actual receive-pointer callback.

        Args:
            _handle (ctypes.c_void_p): Handle passed to SimConnect_CallDispatch.
            callback (Callable[..., None]): Native callback receiving a
                ctypes pointer to _SimConnectRecv, byte size (int), and optional
                ctypes.c_void_p context, in that order.
            context (ctypes.c_void_p | None): Optional callback context; fixture
                dispatch passes None.

        Returns:
            int: Successful fake HRESULT.
        """
        if self.queue:
            packet, size = self.queue.pop(0)
            callback(
                ctypes.cast(ctypes.pointer(packet), ctypes.POINTER(_SimConnectRecv)),
                size,
                context,
            )
        return 0

    def last_sent(
        self, _handle: ctypes.c_void_p, output: ctypes.c_uint32
    ) -> int:
        """Return the most recent send ID through its output pointer.

        Args:
            _handle (ctypes.c_void_p): Handle being inspected.
            output (ctypes.c_uint32): By-reference output slot receiving the
                fixture packet ID.

        Returns:
            int: Successful fake HRESULT.
        """
        ctypes.cast(output, ctypes.POINTER(ctypes.c_uint32)).contents.value = self.sent
        return 0

    def add_definition(
        self, _handle: ctypes.c_void_p, *arguments: NativeFlightArgument
    ) -> int:
        """Record one read-only SimVar definition.

        Args:
            _handle (ctypes.c_void_p): Handle passed to
                SimConnect_AddToDataDefinition.
            arguments (NativeFlightArgument): Values after the handle in order:
                definition ID, SimVar-name bytes, optional unit bytes, datatype
                enum, floating epsilon and datum ID.

        Returns:
            int: Successful fake HRESULT.
        """
        return self.next_send("definition", arguments)

    def subscribe(
        self, _handle: ctypes.c_void_p, *arguments: NativeFlightArgument
    ) -> int:
        """Record one state-event subscription.

        Args:
            _handle (ctypes.c_void_p): Handle passed to
                SimConnect_SubscribeToSystemEvent.
            arguments (NativeFlightArgument): Client event ID, then system-event
                name bytes.

        Returns:
            int: Successful fake HRESULT.
        """
        return self.next_send("subscribe", arguments)

    def request_state(
        self, _handle: ctypes.c_void_p, *arguments: NativeFlightArgument
    ) -> int:
        """Queue an initial flight or aircraft identity response.

        Args:
            _handle (ctypes.c_void_p): Handle passed to
                SimConnect_RequestSystemState.
            arguments (NativeFlightArgument): Request ID, then system-state
                name bytes.

        Returns:
            int: Successful fake HRESULT.
        """
        result = self.next_send("state", arguments)
        packet = flight._FlightSystemState()
        packet.dwSize = ctypes.sizeof(packet)
        packet.dwID = 15
        packet.dwRequestID = int(arguments[0])
        packet.dwInteger = 1
        packet.szString = (
            b"A320.cfg"
            if packet.dwRequestID == flight.REQUEST_AIRCRAFT_STATE
            else b"flight.flt"
        )
        self.queue.append((packet, ctypes.sizeof(packet)))
        return result

    def request_data(
        self, _handle: ctypes.c_void_p, *arguments: NativeFlightArgument
    ) -> int:
        """Queue a user-aircraft core frame for the periodic request.

        Args:
            _handle (ctypes.c_void_p): Handle passed to
                SimConnect_RequestDataOnSimObject.
            arguments (NativeFlightArgument): Request ID, definition ID, object
                selector, period, flags, origin, interval and request limit, in
                native SDK order.

        Returns:
            int: Successful fake HRESULT.
        """
        result = self.next_send("request", arguments)
        request_id, definition_id = int(arguments[0]), int(arguments[1])
        if definition_id == flight.FLIGHT_TITLE_DEFINITION_ID:
            packet = flight._FlightTitleData()
            packet.dwSize = ctypes.sizeof(packet)
            packet.dwID = flight.SIMCONNECT_RECV_ID_SIMOBJECT_DATA
            packet.dwRequestID = request_id
            packet.dwObjectID = 17
            packet.dwDefineID = definition_id
            packet.dwentrynumber = 1
            packet.dwoutof = 1
            packet.dwDefineCount = 1
            packet.value = b"Airbus A320"
            self.queue.append((packet, ctypes.sizeof(packet)))
        elif definition_id == flight.FLIGHT_CORE_DEFINITION_ID:
            packet = flight._FlightCoreData()
            packet.dwSize = ctypes.sizeof(packet)
            packet.dwID = flight.SIMCONNECT_RECV_ID_SIMOBJECT_DATA
            packet.dwRequestID = request_id
            packet.dwObjectID = 17
            packet.dwDefineID = definition_id
            packet.dwentrynumber = 1
            packet.dwoutof = 1
            packet.dwDefineCount = len(flight.CORE_SIMVARS)
            values = (
                49.0, 14.0, 1000.0, 850.0, 0.05, 0.0,
                110.0, 130.0, 105.0, -500.0, 2.0, -1.0, 180.0,
            )
            for index, value in enumerate(values):
                setattr(packet, f"value_{index}", value)
            self.queue.append((packet, ctypes.sizeof(packet)))
        elif definition_id >= flight.FLIGHT_OPTIONAL_FIRST_ID:
            packet = flight._FlightOptionalData()
            packet.dwSize = ctypes.sizeof(packet)
            packet.dwID = flight.SIMCONNECT_RECV_ID_SIMOBJECT_DATA
            packet.dwRequestID = request_id
            packet.dwObjectID = flight.SIMCONNECT_OBJECT_ID_USER
            packet.dwDefineID = definition_id
            packet.dwentrynumber = 1
            packet.dwoutof = 1
            packet.dwDefineCount = 1
            index = (definition_id - flight.FLIGHT_OPTIONAL_FIRST_ID) // 2
            key = flight.OPTIONAL_SIMVARS[index][0]
            packet.value = {
                "rate": 1.0,
                "gear": 75.0,
                "flaps": 50.0,
                "brake_left": 25.0,
                "brake_right": 50.0,
                "fuel": 240.0,
                "load_factor": 1.2,
            }.get(key, 1.0)
            self.queue.append((packet, ctypes.sizeof(packet)))
        return result


class SimConnectFlightTests(unittest.TestCase):
    """Exercise native handshakes, frames and state events without MSFS."""

    def provider(self, generation: int) -> tuple[flight.SimConnectFlightClient, FakeFlightDLL]:
        """Create a provider and matching fake DLL.

        Args:
            generation (int): Supported generation 2020 or 2024.

        Returns:
            tuple[SimConnectFlightClient, FakeFlightDLL]: Test provider and DLL.
        """
        provider = flight.SimConnectFlightClient(
            "", lambda _level, _message: None
        )
        dll = FakeFlightDLL(generation)
        provider.transport.resolve_dll_path = lambda: (Path("SimConnect.dll"), [])
        return provider, dll

    def test_both_generations_stream_user_aircraft_core_telemetry(self) -> None:
        """Use OPEN identity and normalize a real-shaped frame for both versions.

        Returns:
            None: Assertions cover the fake native provider behavior.
        """
        for generation in (2020, 2024):
            with self.subTest(generation=generation):
                provider, dll = self.provider(generation)
                with (
                    mock.patch.object(flight, "WINDOWS_NATIVE_TRANSPORT", True),
                    mock.patch.object(flight.ctypes, "WinDLL", return_value=dll, create=True),
                ):
                    result = provider.sample()
                    self.assertIsNotNone(result)
                    sample, identity = result
                    self.assertEqual(generation, provider.generation)
                    self.assertAlmostEqual(49.0, sample.latitude_deg)
                    self.assertAlmostEqual(304.8, sample.altitude_m)
                    self.assertAlmostEqual(105 * flight.METRES_PER_SECOND_PER_KNOT, sample.ground_speed_mps)
                    self.assertAlmostEqual(130 * flight.METRES_PER_SECOND_PER_KNOT, sample.true_airspeed_mps)
                    self.assertAlmostEqual(110 * flight.METRES_PER_SECOND_PER_KNOT, sample.indicated_airspeed_mps)
                    self.assertAlmostEqual(-500 * flight.METRES_PER_SECOND_PER_FPM, sample.vertical_speed_mps)
                    self.assertFalse(sample.on_ground)
                    self.assertAlmostEqual(75.0, sample.gear_position)
                    self.assertAlmostEqual(50.0, sample.flaps_position)
                    self.assertAlmostEqual(25.0, sample.left_brake_position)
                    self.assertAlmostEqual(50.0, sample.right_brake_position)
                    self.assertAlmostEqual(1.2, sample.load_factor_g)
                    self.assertEqual(4, len(sample.engine_rpm))
                    self.assertEqual(provider.simulator, identity["simulator"])
                    self.assertEqual("Airbus A320", identity["aircraft"])
                    self.assertEqual(
                        provider.simulator_build,
                        identity["simulator_build"],
                    )
                    self.assertIn(" / SimConnect ", identity["simulator_build"])
                    core_requests = [
                        args for name, args in dll.calls
                        if name == "request" and args[1] == flight.FLIGHT_CORE_DEFINITION_ID
                    ]
                    self.assertEqual(1, len(core_requests))
                    self.assertEqual(flight.SIMCONNECT_PERIOD_SIM_FRAME, core_requests[0][3])
                    self.assertEqual(1, sum(name == "open" for name, _args in dll.calls))
                    definitions = [
                        arguments for name, arguments in dll.calls
                        if name == "definition"
                    ]
                    percent_variables = {
                        arguments[1] for arguments in definitions
                        if arguments[2] == b"percent"
                    }
                    self.assertTrue({
                        b"GEAR HANDLE POSITION",
                        b"FLAPS HANDLE PERCENT",
                        b"BRAKE LEFT POSITION",
                        b"BRAKE RIGHT POSITION",
                    }.issubset(percent_variables))
                    total_weight = next(
                        arguments for arguments in definitions
                        if arguments[1] == b"TOTAL WEIGHT"
                    )
                    self.assertEqual(b"pounds", total_weight[2])
                    provider.stop()
                self.assertEqual(1, dll.closed)

    def test_quit_reconnects_with_fresh_cross_generation_open(self) -> None:
        """Reconnect after QUIT and replace all identity with the next OPEN.

        Returns:
            None: The 2020 handle follows a disconnected 2024 handle cleanly.
        """
        provider, first_dll = self.provider(2024)
        second_dll = FakeFlightDLL(2020)
        with (
            mock.patch.object(flight, "WINDOWS_NATIVE_TRANSPORT", True),
            mock.patch.object(
                flight.ctypes, "WinDLL",
                side_effect=[first_dll, second_dll],
                create=True,
            ),
        ):
            first = provider.sample()
            self.assertIsNotNone(first)
            self.assertEqual(2024, provider.generation)
            quit_packet = _SimConnectRecv()
            quit_packet.dwSize = ctypes.sizeof(quit_packet)
            quit_packet.dwID = SIMCONNECT_RECV_ID_QUIT
            provider.dispatch(
                ctypes.pointer(quit_packet),
                ctypes.sizeof(quit_packet),
                None,
            )
            self.assertTrue(provider.connection_lost)
            self.assertEqual(
                "simulator disconnected",
                provider.pending_discontinuity,
            )
            provider.close()
            second = provider.sample()
            self.assertIsNotNone(second)
            sample, identity = second
            self.assertGreaterEqual(
                sample.session_elapsed_seconds,
                first[0].session_elapsed_seconds,
            )
            self.assertEqual(2020, provider.generation)
            self.assertEqual("Microsoft Flight Simulator 2020", identity["simulator"])
            self.assertEqual(
                "simulator disconnected",
                sample.discontinuity_before,
            )
            self.assertFalse(provider.connection_lost)
            provider.stop()
        self.assertEqual(1, first_dll.closed)
        self.assertEqual(1, second_dll.closed)

    def test_reconnect_without_open_does_not_reuse_previous_identity(self) -> None:
        """Reject a replacement handle that never completes a fresh OPEN.

        Returns:
            None: The previous simulator identity remains cleared after failure.
        """
        provider, first_dll = self.provider(2024)
        second_dll = FakeFlightDLL(2020)

        def open_without_handshake(
            output_handle: ctypes.c_void_p, *_arguments: NativeFlightArgument
        ) -> int:
            """Return a handle without queueing the required OPEN response.

            Args:
                output_handle (ctypes.c_void_p): By-reference native handle
                    output slot.
                _arguments (NativeFlightArgument): Open arguments in SDK order:
                    client-name bytes, optional HWND, Win32 event ID, HANDLE and
                    configuration index.

            Returns:
                int: Successful HRESULT with an intentionally absent handshake.
            """
            ctypes.cast(
                output_handle,
                ctypes.POINTER(ctypes.c_void_p),
            )[0] = ctypes.c_void_p(88)
            return 0

        second_dll.SimConnect_Open = NativeCallable(open_without_handshake)
        with (
            mock.patch.object(flight, "WINDOWS_NATIVE_TRANSPORT", True),
            mock.patch.object(
                flight.ctypes, "WinDLL",
                side_effect=[first_dll, second_dll],
                create=True,
            ),
        ):
            self.assertIsNotNone(provider.sample())
            self.assertEqual(2024, provider.generation)
            quit_packet = _SimConnectRecv()
            quit_packet.dwSize = ctypes.sizeof(quit_packet)
            quit_packet.dwID = SIMCONNECT_RECV_ID_QUIT
            provider.dispatch(
                ctypes.pointer(quit_packet),
                ctypes.sizeof(quit_packet),
                None,
            )
            provider.close()
            with mock.patch.object(
                flight, "FLIGHT_RESPONSE_TIMEOUT_SECONDS", 0.0
            ):
                self.assertIsNone(provider.sample())
            self.assertFalse(provider.transport.open_received)
            self.assertEqual("unknown", provider.simulator)
            self.assertIsNone(provider.simulator_build)
            self.assertIsNone(provider.generation)
        self.assertEqual(1, first_dll.closed)
        self.assertEqual(1, second_dll.closed)


    def test_pause_flight_and_aircraft_events_mark_track_breaks(self) -> None:
        """Preserve pause/resume and load transitions as discontinuity evidence.

        Returns:
            None: Assertions cover the fake native provider behavior.
        """
        provider, _dll = self.provider(2024)
        pause = flight._FlightEvent()
        pause.dwSize = ctypes.sizeof(pause)
        pause.dwID = 4
        pause.uEventID = flight.EVENT_PAUSE_EX1
        pause.dwData = 4
        provider.dispatch(ctypes.pointer(pause), ctypes.sizeof(pause), None)
        self.assertIn("paused", provider.pending_discontinuity)
        pause.dwData = 0
        provider.dispatch(ctypes.pointer(pause), ctypes.sizeof(pause), None)
        self.assertEqual("simulator resumed", provider.pending_discontinuity)
        event = flight._FlightFilenameEvent()
        event.dwSize = ctypes.sizeof(event)
        event.dwID = 6
        event.uEventID = flight.EVENT_AIRCRAFT_LOADED
        event.szFileName = b"Aircraft/Asobo_A320.air"
        provider.dispatch(ctypes.pointer(event), ctypes.sizeof(event), None)
        self.assertIsNone(provider.aircraft)
        self.assertEqual("aircraft loaded", provider.pending_discontinuity)
        self.assertEqual({}, provider.values)
        self.assertEqual({}, provider.value_timestamps)
        self.assertEqual(set(), provider.unavailable)

    def test_optional_values_expire_without_fresh_native_packets(self) -> None:
        """Expose stale optional values as unknown when their feed stops.

        Returns:
            None: A cached gear value older than the policy is discarded.
        """
        provider, _dll = self.provider(2020)
        provider.latest_core = (
            49.0, 14.0, 1000.0, 850.0, 120.0, 1.0,
            110.0, 130.0, 105.0, 0.0, 0.0, 0.0, 90.0,
        )
        provider.values["gear"] = 75.0
        provider.value_timestamps["gear"] = 100.0
        with mock.patch.object(
            flight.time,
            "monotonic",
            return_value=100.0 + flight.OPTIONAL_VALUE_MAX_AGE_SECONDS + 0.1,
        ):
            sample = provider._sample_from_core(4.0)
        self.assertIsNone(sample.gear_position)
        self.assertNotIn("gear", provider.values)
        self.assertNotIn("gear", provider.value_timestamps)

    def test_aircraft_load_clears_old_optional_capability_values(self) -> None:
        """Prevent a new aircraft inheriting the previous aircraft's signals.

        Returns:
            None: Load transition clears cached readings and capability state.
        """
        provider, _dll = self.provider(2024)
        provider.aircraft = "previous aircraft"
        provider.values["gear"] = 75.0
        provider.value_timestamps["gear"] = 10.0
        provider.unavailable.add("fuel")
        event = flight._FlightFilenameEvent()
        event.dwSize = ctypes.sizeof(event)
        event.dwID = 6
        event.uEventID = flight.EVENT_AIRCRAFT_LOADED
        event.szFileName = b"Aircraft/new plane.cfg"
        provider.dispatch(ctypes.pointer(event), ctypes.sizeof(event), None)
        self.assertIsNone(provider.aircraft)
        self.assertEqual({}, provider.values)
        self.assertEqual({}, provider.value_timestamps)
        self.assertEqual(set(), provider.unavailable)
        self.assertEqual("aircraft loaded", provider.pending_discontinuity)

    def test_accelerated_rate_marks_sample_as_discontinuous(self) -> None:
        """Mark an actual rate transition as a track discontinuity.

        Returns:
            None: Assertions cover the fake native provider behavior.
        """
        provider, _dll = self.provider(2020)
        provider.latest_core = (
            49.0, 14.0, 1000.0, 850.0, 120.0, 1.0,
            110.0, 130.0, 105.0, 0.0, 0.0, 0.0, 90.0,
        )
        provider.last_rate = 1.0
        provider.values["rate"] = 4.0
        sample = provider._sample_from_core(4.0)
        self.assertEqual("simulation rate changed", sample.discontinuity_before)
        self.assertEqual(4.0, sample.simulator_rate)


if __name__ == "__main__":
    unittest.main()
