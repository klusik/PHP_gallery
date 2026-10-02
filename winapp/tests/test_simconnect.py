# Project: PHP Gallery
# Module Type: Regression Test
# Purpose: Verify automatic simulator location strategies and native packet handling.
# Responsibilities:
#   - Exercise SimConnect camera and aircraft paths without a simulator or native DLL.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/tests/test_simconnect.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
"""Deterministic SimConnect transport and watched-upload regression contracts.

Native calls are replaced with Python doubles, but the transport consumes real
ctypes receive structures through its native callback wrapper. These tests cover
packet handling, strategy selection, DLL discovery and upload tolerance without
opening a simulator, loading a real DLL or contacting a gallery. They cannot
establish compatibility with a running MSFS installation; that requires a live
Windows smoke test with the distributed runtime.
"""

import ctypes
import importlib.machinery
import importlib.util
import queue
import struct
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from unittest import mock
from typing import Any, List, Optional, Tuple

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import simconnect_location as SIM
from uploader.diagnostics import redact_text


class NativeFunction:
    """Native callable double that accepts ctypes signature configuration."""

    def __init__(self, function):
        """Wrap a callable while exposing writable native signature attributes."""
        self.function = function
        self.argtypes = None
        self.restype = None

    def __call__(self, *args):
        """Forward native-style arguments and preserve the callable's result or error."""
        return self.function(*args)


def packet_header(packet, receive_id):
    """Populate the common wire header with its real packed byte length."""
    packet.dwSize = ctypes.sizeof(packet)
    packet.dwVersion = 0
    packet.dwID = receive_id
    return packet


class FakeSimConnectDLL:
    """Dispatch real ctypes packets through assignable native-function doubles.

    Each native send gets a send ID, which an exception uses to identify its
    originating call. Aircraft data instead uses a request ID and definition ID;
    these independent identifiers intentionally exercise different correlations.
    Coordinates use latitude/longitude degrees and altitude feet, matching the
    camera wire contract and the requested aircraft SimVar units.
    """

    def __init__(self, generation=2024, camera="success", aircraft="success", camera_exports=True, open_result=0):
        """Choose OPEN identity, provider responses and the presence of camera exports.

        Response modes can queue success, malformed data or explicit exceptions,
        return a failed HRESULT, raise a native-call error, or leave dispatch
        empty to model a timeout. No real Windows DLL is instantiated.
        """
        self.generation = generation
        self.camera = camera
        self.aircraft = aircraft
        self.open_result = open_result
        self.packets = []
        self.calls = []
        self.last_send = 0
        self.camera_send = 0
        self.unknown_camera_send = 0
        self.close_count = 0
        self.release_fails = False
        self.aircraft_coordinates = (48.25, 12.75, 2000.0)
        self.camera_coordinates = (49.125, 13.5, 2284.0)
        self.foreign_aircraft = False
        for name, function in {
            "SimConnect_Open": self.open,
            "SimConnect_Close": self.close,
            "SimConnect_CallDispatch": self.call_dispatch,
            "SimConnect_GetLastSentPacketID": self.last_sent_packet,
            "SimConnect_AddToDataDefinition": self.add_definition,
            "SimConnect_RequestDataOnSimObject": self.request_aircraft,
        }.items():
            setattr(self, name, NativeFunction(function))
        if camera_exports:
            for name, function in {
                "SimConnect_CameraAcquire": self.acquire,
                "SimConnect_CameraRelease": self.release,
                "SimConnect_CameraGetStatus": self.camera_status,
                "SimConnect_CameraGet": self.request_camera,
            }.items():
                setattr(self, name, NativeFunction(function))

    def send(self, name, arguments):
        """Record the native send sequence used to correlate exceptions."""
        self.calls.append((name, arguments))
        self.last_send += 1

    def open(self, handle, *args):
        """Set the output handle and queue a realistic OPEN packet, or fail opening."""
        self.send("open", args)
        if self.open_result:
            return self.open_result
        ctypes.cast(handle, ctypes.POINTER(ctypes.c_void_p))[0] = ctypes.c_void_p(123)
        packet = packet_header(SIM._SimConnectRecvOpen(), SIM.SIMCONNECT_RECV_ID_OPEN)
        # The OPEN handshake uses product code names rather than launcher labels.
        packet.szApplicationName = {2024: b"SunRise", 2020: b"KittyHawk", None: b"Unidentified simulator"}[self.generation]
        packet.dwApplicationVersionMajor = 12 if self.generation == 2024 else 11
        packet.dwApplicationVersionMinor = 0
        packet.dwApplicationBuildMajor = 282174
        packet.dwApplicationBuildMinor = 999
        packet.dwSimConnectVersionMajor = 11
        packet.dwSimConnectVersionMinor = 0
        packet.dwSimConnectBuildMajor = 62651
        packet.dwSimConnectBuildMinor = 3
        self.packets.append((packet, ctypes.sizeof(packet)))
        return 0

    def close(self, *args):
        """Record transport cleanup separately from camera ownership release."""
        self.send("close", args)
        self.close_count += 1
        return 0

    def acquire(self, *args):
        """Model successful acquisition of camera ownership and its send ID."""
        self.send("camera_acquire", args)
        return 0

    def release(self, *args):
        """Release camera ownership or inject a cleanup error without closing the handle."""
        self.send("camera_release", args)
        if self.release_fails:
            raise OSError("camera release failed")
        return 0

    def camera_status(self, *args):
        """Accept the optional camera-status query without supplying a status packet."""
        self.send("camera_status", args)
        return 0

    def exception_packet(self, send_id, exception=1):
        """Build an exception correlated to a native send rather than an aircraft request."""
        packet = packet_header(SIM._SimConnectRecvException(), SIM.SIMCONNECT_RECV_ID_EXCEPTION)
        packet.dwException = exception
        packet.dwSendID = send_id
        packet.dwIndex = 0
        return packet

    def request_camera(self, *args):
        """Supply packed WORLD camera data or the configured camera failure mode."""
        self.send("camera_get", args)
        self.camera_send = self.last_send
        if self.camera == "raise":
            raise OSError("native camera call failed")
        if self.camera == "hresult":
            return -2147467259
        if self.camera == "exception":
            packet = self.exception_packet(self.camera_send)
            self.packets.append((packet, ctypes.sizeof(packet)))
        elif self.camera in {"success", "invalid", "truncated", "nonworld"}:
            packet = packet_header(SIM._SimConnectRecvCameraData(), SIM.SIMCONNECT_RECV_ID_CAMERA_DATA)
            packet.CameraData.Position.x, packet.CameraData.Position.y, packet.CameraData.Position.z = self.camera_coordinates
            packet.CameraData.PositionReferential = SIM.SIMCONNECT_POSITION_REFERENTIAL_WORLD
            if self.camera == "invalid":
                packet.CameraData.Position.x = float("nan")
            elif self.camera == "nonworld":
                packet.CameraData.PositionReferential = 0
            size = ctypes.sizeof(packet) - 1 if self.camera == "truncated" else ctypes.sizeof(packet)
            self.packets.append((packet, size))
        return 0

    def last_sent_packet(self, _handle, send_id):
        """Write the last native send ID through the caller's uint32 output pointer."""
        ctypes.cast(send_id, ctypes.POINTER(ctypes.c_uint32))[0] = self.last_send
        return 0

    def add_definition(self, *args):
        """Record requested SimVars and units so tests can verify the aircraft definition."""
        self.send("add_definition", args)
        return 0

    def aircraft_packet(self, request, definition):
        """Build an untagged user-aircraft reply containing three FLOAT64 coordinates."""
        packet = packet_header(SIM._SimConnectRecvSimobjectData(), SIM.SIMCONNECT_RECV_ID_SIMOBJECT_DATA)
        packet.dwRequestID = request
        packet.dwObjectID = 0
        packet.dwDefineID = definition
        packet.dwDefineCount = 3
        packet.dwentrynumber = 1
        packet.dwoutof = 1
        packet.latitude, packet.longitude, packet.altitude = self.aircraft_coordinates
        return packet

    def request_aircraft(self, *args):
        """Queue aircraft replies and optionally interleave foreign data or camera errors."""
        self.send("aircraft_get", args)
        request, definition = int(args[1]), int(args[2])
        if self.camera == "late_exception":
            packet = self.exception_packet(self.camera_send)
            self.packets.append((packet, ctypes.sizeof(packet)))
        elif self.camera == "late_camera_api_unknown":
            packet = self.exception_packet(self.unknown_camera_send, exception=46)
            self.packets.append((packet, ctypes.sizeof(packet)))
        if self.aircraft == "hresult":
            return -2147467259
        if self.aircraft == "exception":
            packet = self.exception_packet(self.last_send)
            self.packets.append((packet, ctypes.sizeof(packet)))
        elif self.aircraft in {"success", "invalid", "truncated"}:
            if self.foreign_aircraft:
                for foreign_request, foreign_definition in ((request + 20, definition), (request, definition + 20)):
                    packet = self.aircraft_packet(foreign_request, foreign_definition)
                    packet.latitude = -80.0
                    self.packets.append((packet, ctypes.sizeof(packet)))
            packet = self.aircraft_packet(request, definition)
            if self.aircraft == "invalid":
                packet.longitude = float("inf")
            size = ctypes.sizeof(packet) - 1 if self.aircraft == "truncated" else ctypes.sizeof(packet)
            self.packets.append((packet, size))
        return 0

    def call_dispatch(self, _handle, callback, context):
        """Deliver one queued packet as a base receive pointer with its callback byte count."""
        if self.packets:
            packet, size = self.packets.pop(0)
            callback(ctypes.cast(ctypes.pointer(packet), ctypes.POINTER(SIM._SimConnectRecv)), size, context)
        return 0


class WireSimConnectDLL(FakeSimConnectDLL):
    """Supply independently encoded SDK packets instead of production ctypes layouts."""

    def __init__(self, generation: int = 2020, camera: str = "success", replies: Optional[List[Tuple[bytes, int]]] = None) -> None:
        """Choose provider behavior and optional exact wire replies for aircraft requests."""
        super().__init__(generation=generation, camera=camera)
        self.replies = replies

    def open(self, handle: Any, *args: Any) -> int:
        """Encode literal OPEN receive ID 2 and SDK fixed-width identity fields."""
        self.send("open", args)
        ctypes.cast(handle, ctypes.POINTER(ctypes.c_void_p))[0] = ctypes.c_void_p(123)
        name = b"KittyHawk" if self.generation == 2020 else b"SunRise"
        wire = struct.pack("<3I256s10I", 308, 0, 2, name, 11 if self.generation == 2020 else 12, 0, 282174, 999, 11, 0, 62651, 3, 0, 0)
        self.packets.append((ctypes.create_string_buffer(wire, len(wire)), len(wire)))
        return 0

    @staticmethod
    def aircraft_wire(request: int = 1, definition: int = 1, object_id: int = 73, coordinates: Tuple[float, float, float] = (48.25, 12.75, 2000.0), declared_size: int = 64) -> bytes:
        """Encode SDK SIMOBJECT_DATA ID 8, ten DWORDs and three little-endian doubles."""
        return struct.pack("<10I3d", declared_size, 0, 8, request, object_id, definition, 0, 1, 1, 3, *coordinates)

    def request_aircraft(self, *args: Any) -> int:
        """Queue caller-selected byte buffers while preserving their callback byte counts."""
        self.send("aircraft_get", args)
        replies = self.replies if self.replies is not None else [(self.aircraft_wire(int(args[1]), int(args[2])), 64)]
        for wire, callback_size in replies:
            self.packets.append((ctypes.create_string_buffer(wire, len(wire)), callback_size))
        return 0


class SimConnectTransportTests(unittest.TestCase):
    """Protect both generations, fallbacks, bounded waits and packet correlation."""

    def acquire(self, dll, client=None):
        """Use an in-memory DLL, while retaining the actual ctypes dispatch loop."""
        if client is None:
            client = SIM.SimConnectLocationClient(timeout_seconds=0.08)
        dll_path = WINAPP_DIR / "SimConnect.dll"
        with mock.patch.object(SIM.os, "name", "nt"), mock.patch.object(client, "resolve_dll_path", return_value=(dll_path, [dll_path])), mock.patch.object(SIM.ctypes, "WinDLL", return_value=dll, create=True):
            location, message = client.current_location()
        return location, message, client

    def test_msfs_2024_uses_world_camera_without_aircraft_request(self):
        """Keep the existing 2024 WORLD camera path as the preferred successful source."""
        dll = FakeSimConnectDLL()
        location, message, client = self.acquire(dll)
        self.assertEqual("camera_world_position", location.source)
        self.assertEqual(dll.camera_coordinates, (location.latitude, location.longitude, location.altitude))
        self.assertFalse(any(name == "aircraft_get" for name, _ in dll.calls))
        self.assertEqual(2024, client.simulator_info.generation)
        self.assertEqual(1, dll.close_count)

    def test_independent_fs2020_wire_accepts_actual_user_aircraft_object_id(self) -> None:
        """Accept literal receive ID 8 with a nonzero simulator-assigned user object ID."""
        dll = WireSimConnectDLL()
        location, message, client = self.acquire(dll)
        self.assertIsNotNone(location, message)
        self.assertEqual("aircraft_position", location.source)
        self.assertEqual((48.25, 12.75, 2000.0), (location.latitude, location.longitude, location.altitude))
        report = client.diagnostics_snapshot()
        self.assertEqual([2, 8], report["dispatch_sequence"])
        self.assertEqual(73, report["aircraft_packets"][-1]["object_id"])
        self.assertEqual(40, report["aircraft_packets"][-1]["payload_offset"])
        packet = report["aircraft_packets"][-1]
        self.assertEqual(1, packet["expected_request_id"])
        self.assertEqual(1, packet["expected_definition_id"])
        self.assertEqual(packet["expected_request_id"], packet["request_id"])
        self.assertEqual(packet["expected_definition_id"], packet["definition_id"])
        self.assertEqual(64, packet["cb_data"])
        self.assertEqual(64, packet["dw_size"])
        self.assertEqual(40, packet["header_size"])
        self.assertEqual(24, packet["payload_available"])
        self.assertNotIn("timeout", message.lower())
        self.assertEqual(1, dll.close_count)

    def test_independent_wire_isolated_identifier_mismatches_are_observable(self) -> None:
        """Reject foreign requests or definitions with concrete reasons instead of timeout."""
        for field, request, definition in (("request", 99, 1), ("definition", 1, 99)):
            with self.subTest(field=field):
                wire = WireSimConnectDLL.aircraft_wire(request=request, definition=definition)
                location, message, client = self.acquire(WireSimConnectDLL(replies=[(wire, len(wire))]))
                self.assertIsNone(location)
                report = client.diagnostics_snapshot()
                self.assertIn(field, report["aircraft_reason"].lower())
                self.assertIn("99", report["aircraft_reason"])
                self.assertTrue(report["aircraft_packets"][-1]["rejection"])
                self.assertNotIn("timeout", message.lower())

    def test_independent_wire_foreign_packets_do_not_block_later_matching_reply(self) -> None:
        """Continue dispatch after both identifier mismatches and accept the matching reply."""
        replies = [(WireSimConnectDLL.aircraft_wire(request=99), 64), (WireSimConnectDLL.aircraft_wire(definition=99), 64), (WireSimConnectDLL.aircraft_wire(), 64)]
        location, message, client = self.acquire(WireSimConnectDLL(replies=replies))
        self.assertIsNotNone(location, message)
        self.assertEqual(48.25, location.latitude)
        packets = client.diagnostics_snapshot()["aircraft_packets"]
        self.assertEqual(3, len(packets))
        self.assertTrue(packets[0]["rejection"])
        self.assertTrue(packets[1]["rejection"])
        self.assertFalse(packets[2]["rejection"])

    def test_independent_wire_truncation_never_reads_beyond_native_bounds(self) -> None:
        """Reject short buffers and inconsistent declared/callback lengths with useful reasons."""
        wire = WireSimConnectDLL.aircraft_wire()
        cases = (("common header", wire[:8], 8), ("metadata", WireSimConnectDLL.aircraft_wire(declared_size=36)[:36], 36), ("payload", WireSimConnectDLL.aircraft_wire(declared_size=63)[:63], 63), ("callback length", wire, 63), ("declared short", WireSimConnectDLL.aircraft_wire(declared_size=40), 64), ("declared oversized", WireSimConnectDLL.aircraft_wire(declared_size=65), 64))
        for label, packet, callback_size in cases:
            with self.subTest(case=label):
                location, message, client = self.acquire(WireSimConnectDLL(replies=[(packet, callback_size)]))
                self.assertIsNone(location)
                reason = client.diagnostics_snapshot()["aircraft_reason"].lower()
                self.assertTrue(any(word in reason for word in ("small", "size", "short", "truncated", "payload", "header")), reason)
                self.assertNotIn("timeout", message.lower())

    def test_independent_wire_accepts_finite_zero_coordinates_and_altitude(self) -> None:
        """Keep numeric zero valid independently for every field and at the geographic origin."""
        for coordinates in ((0.0, 12.75, 2000.0), (48.25, 0.0, 2000.0), (48.25, 12.75, 0.0), (0.0, 0.0, 0.0)):
            with self.subTest(coordinates=coordinates):
                wire = WireSimConnectDLL.aircraft_wire(coordinates=coordinates)
                location, message, _ = self.acquire(WireSimConnectDLL(replies=[(wire, 64)]))
                self.assertIsNotNone(location, message)
                self.assertEqual(coordinates, (location.latitude, location.longitude, location.altitude))

    def test_fs2024_camera_failure_decodes_independent_aircraft_wire(self) -> None:
        """Preserve camera-first strategy and decode real-layout aircraft fallback on camera failure."""
        dll = WireSimConnectDLL(generation=2024, camera="exception")
        location, message, _ = self.acquire(dll)
        self.assertIsNotNone(location, message)
        self.assertEqual("aircraft_position_fallback", location.source)
        self.assertTrue(any(name == "camera_get" for name, _ in dll.calls))
        self.assertTrue(any(name == "aircraft_get" for name, _ in dll.calls))

    def test_wire_mismatch_reason_survives_windows_path_redaction(self) -> None:
        """Keep failure details visible when native DLL paths are sanitized for support logs."""
        wire = WireSimConnectDLL.aircraft_wire(request=99)
        _, _, client = self.acquire(WireSimConnectDLL(replies=[(wire, 64)]))
        client.dll_message = "Using SimConnect.dll: C:\\Users\\pilot\\SimConnect.dll"
        redacted = redact_text(client.diagnostic_message(client.aircraft_error))
        self.assertIn("request ID mismatch", redacted)
        self.assertIn("received 99", redacted)
        self.assertIn("provider=aircraft_position", redacted)
        self.assertIn("last aircraft packet", redacted)
        self.assertIn("expected_request_id", redacted)
        self.assertNotIn("C:\\Users\\pilot", redacted)

    def test_wire_diagnostic_history_is_bounded_and_resets_between_acquisitions(self) -> None:
        """Bound foreign packet history and clear it before a subsequent acquisition."""
        replies = [(WireSimConnectDLL.aircraft_wire(request=99), 64) for _ in range(20)]
        replies.append((WireSimConnectDLL.aircraft_wire(), 64))
        client = SIM.SimConnectLocationClient(timeout_seconds=0.5)
        location, message, _ = self.acquire(WireSimConnectDLL(replies=replies), client)
        self.assertIsNotNone(location, message)
        report = client.diagnostics_snapshot()
        self.assertEqual(21, report["aircraft_data_packets"])
        self.assertEqual(8, len(report["aircraft_packets"]))
        self.assertEqual(12, len(report["dispatch_sequence"]))
        location, message, _ = self.acquire(WireSimConnectDLL(), client)
        self.assertIsNotNone(location, message)
        report = client.diagnostics_snapshot()
        self.assertEqual(1, report["aircraft_data_packets"])
        self.assertEqual(1, len(report["aircraft_packets"]))
        self.assertEqual([2, 8], report["dispatch_sequence"])
        self.assertEqual("", report["aircraft_reason"])

    def test_msfs_2024_camera_failures_fall_back_to_aircraft(self):
        """Recover aircraft coordinates after each supported camera failure category."""
        for failure in ("timeout", "exception", "hresult", "raise", "invalid", "truncated", "nonworld"):
            with self.subTest(failure=failure):
                dll = FakeSimConnectDLL(camera=failure)
                location, message, client = self.acquire(dll)
                self.assertIsNotNone(location, message)
                self.assertEqual("aircraft_position_fallback", location.source)
                self.assertEqual(dll.aircraft_coordinates, (location.latitude, location.longitude, location.altitude))
                self.assertEqual(1, dll.close_count)

    def test_msfs_2020_never_calls_camera(self):
        """Select classic aircraft SimVars and correct units for the 2020 handshake."""
        dll = FakeSimConnectDLL(generation=2020)
        location, message, client = self.acquire(dll)
        self.assertIsNotNone(location, message)
        self.assertEqual("aircraft_position", location.source)
        self.assertEqual(2020, client.simulator_info.generation)
        self.assertFalse(any(name.startswith("camera_") for name, _ in dll.calls))
        definitions = [arguments for name, arguments in dll.calls if name == "add_definition"]
        self.assertEqual([b"PLANE LATITUDE", b"PLANE LONGITUDE", b"PLANE ALTITUDE"], [arguments[2] for arguments in definitions])
        self.assertEqual([b"degrees", b"degrees", b"feet"], [arguments[3] for arguments in definitions])

    def test_unknown_generation_always_attempts_aircraft(self):
        """Ensure uncertain simulator metadata still permits aircraft acquisition."""
        dll = FakeSimConnectDLL(generation=None, camera="timeout")
        location, message, client = self.acquire(dll)
        self.assertIsNotNone(location, message)
        self.assertIsNone(client.simulator_info.generation)
        self.assertTrue(any(name == "aircraft_get" for name, _ in dll.calls))

    def test_missing_camera_exports_still_uses_aircraft_transport(self):
        """Treat camera export availability separately from basic transport availability."""
        dll = FakeSimConnectDLL(camera_exports=False)
        location, message, client = self.acquire(dll)
        self.assertIsNotNone(location, message)
        self.assertTrue(location.source.startswith("aircraft_position"))

    def test_complete_failure_is_nonfatal_and_closes_connection(self):
        """Return no location with explicit exception diagnostics when both providers fail."""
        dll = FakeSimConnectDLL(camera="exception", aircraft="exception")
        location, message, client = self.acquire(dll)
        self.assertIsNone(location)
        self.assertIn("exception", message.lower())
        self.assertNotIn("before the timeout", message.lower())
        self.assertEqual(1, dll.close_count)

    def test_no_running_simulator_returns_connection_failure(self):
        """Stop provider requests gracefully when the server connection cannot open."""
        dll = FakeSimConnectDLL(open_result=-2147467259)
        location, message, client = self.acquire(dll)
        self.assertIsNone(location)
        self.assertIn("connection", message.lower())
        self.assertFalse(any(name.startswith("camera_") or name == "aircraft_get" for name, _ in dll.calls))

    def test_late_camera_exception_cannot_cancel_aircraft_fallback(self):
        """Correlate delayed camera exceptions to their original send during fallback."""
        dll = FakeSimConnectDLL(camera="late_exception")
        location, message, client = self.acquire(dll)
        self.assertIsNotNone(location, message)
        self.assertEqual("aircraft_position_fallback", location.source)

    def test_foreign_request_and_definition_cannot_supply_aircraft_location(self):
        """Ignore aircraft packets belonging to a different request or data definition."""
        dll = FakeSimConnectDLL(generation=2020)
        dll.foreign_aircraft = True
        location, message, client = self.acquire(dll)
        self.assertIsNotNone(location, message)
        self.assertEqual(dll.aircraft_coordinates[0], location.latitude)

    def test_unmapped_late_camera_api_exception_does_not_cancel_aircraft_fallback(self):
        """Recognize explicit camera API exceptions even when no send correlation is available."""
        for send_id in (0, 9999):
            with self.subTest(send_id=send_id):
                dll = FakeSimConnectDLL(camera="late_camera_api_unknown")
                dll.unknown_camera_send = send_id
                location, message, client = self.acquire(dll)
                self.assertIsNotNone(location, message)
                self.assertEqual("aircraft_position_fallback", location.source)
                report = client.diagnostics_snapshot()
                self.assertIn("46", report["camera_reason"])
                self.assertEqual("", report["aircraft_reason"])

    def test_rapid_successive_calls_reset_previous_location_and_failure(self):
        """Prevent earlier coordinates or failure state from leaking into the next screenshot."""
        client = SIM.SimConnectLocationClient(timeout_seconds=0.08)
        for latitude in (10.0, 20.0, 30.0):
            dll = FakeSimConnectDLL(generation=2020)
            dll.aircraft_coordinates = (latitude, 12.75, 2000.0)
            location, message, _ = self.acquire(dll, client)
            self.assertIsNotNone(location, message)
            self.assertEqual(latitude, location.latitude)
        dll = FakeSimConnectDLL(generation=2020, aircraft="exception")
        self.assertIsNone(self.acquire(dll, client)[0])
        dll = FakeSimConnectDLL()
        self.assertEqual("camera_world_position", self.acquire(dll, client)[0].source)

    def test_release_failure_still_closes_connection(self):
        """Close the transport even when camera ownership cleanup raises an error."""
        dll = FakeSimConnectDLL()
        dll.release_fails = True
        location, message, _ = self.acquire(dll)
        self.assertIsNotNone(location, message)
        self.assertEqual(1, dll.close_count)

    def test_invalid_and_truncated_aircraft_data_are_not_uploaded(self):
        """Reject nonfinite coordinates and incomplete aircraft packets before metadata creation."""
        for response in ("invalid", "truncated"):
            with self.subTest(response=response):
                location, message, _ = self.acquire(FakeSimConnectDLL(generation=2020, aircraft=response))
                self.assertIsNone(location)
                self.assertTrue("invalid" in message.lower() or "small" in message.lower() or "truncated" in message.lower(), message)

    def test_dll_not_found_returns_a_soft_failure_without_loading_native_code(self):
        """Keep missing runtime discovery nonfatal and avoid calling the native loader."""
        client = SIM.SimConnectLocationClient()
        with mock.patch.object(SIM.os, "name", "nt"), mock.patch.object(client, "resolve_dll_path", return_value=(None, [])), mock.patch.object(SIM.ctypes, "WinDLL", create=True) as loader:
            location, message = client.current_location()
        self.assertIsNone(location)
        self.assertIn("SimConnect.dll is unavailable", message)
        loader.assert_not_called()
        self.assertEqual("not connected", client.diagnostics_snapshot()["connection"])

    def test_diagnostics_and_events_identify_provider_and_fallback_reason(self):
        """Expose the detected generation, actual source and camera failure in diagnostics."""
        events = []
        client = SIM.SimConnectLocationClient(timeout_seconds=0.08, event_sink=lambda level, message: events.append((level, message)))
        location, message, _ = self.acquire(FakeSimConnectDLL(camera="exception"), client)
        self.assertIsNotNone(location, message)
        report = client.diagnostics_snapshot()
        self.assertEqual(2024, report["generation"])
        self.assertEqual("success", report["last_result"])
        self.assertEqual("aircraft_position_fallback", report["source"])
        self.assertIn("exception", report["camera_reason"].lower())
        self.assertTrue(any(level == "warning" and "Falling back to aircraft" in text for level, text in events))
        self.assertTrue(any("source=aircraft_position_fallback" in text for _, text in events))

    def test_one_client_serializes_concurrent_native_connections(self):
        """Ensure concurrent callers cannot share mutable connection or dispatch state."""
        client = SIM.SimConnectLocationClient(timeout_seconds=0.2)
        dll = FakeSimConnectDLL(generation=2020)
        opened = threading.Event()
        release_open = threading.Event()
        second_started = threading.Event()
        original_open = dll.open

        def held_open(*args):
            """Hold the first connection open until both callers have begun their work."""
            result = original_open(*args)
            opened.set()
            release_open.wait(1.0)
            return result

        dll.SimConnect_Open = NativeFunction(held_open)
        results = []

        def query(second=False):
            """Collect one location acquisition from a worker thread."""
            if second:
                second_started.set()
            results.append(client.current_location())

        dll_path = WINAPP_DIR / "SimConnect.dll"
        with mock.patch.object(SIM.os, "name", "nt"), mock.patch.object(client, "resolve_dll_path", return_value=(dll_path, [dll_path])), mock.patch.object(SIM.ctypes, "WinDLL", return_value=dll, create=True):
            first = threading.Thread(target=query)
            second = threading.Thread(target=query, args=(True,))
            try:
                first.start()
                self.assertTrue(opened.wait(1.0))
                second.start()
                self.assertTrue(second_started.wait(1.0))
            finally:
                release_open.set()
                first.join(2.0)
                if second.ident is not None:
                    second.join(2.0)
        self.assertFalse(first.is_alive())
        self.assertFalse(second.is_alive())
        self.assertEqual(2, len(results))
        self.assertTrue(all(location is not None for location, _ in results), results)
        self.assertEqual(["open", "close", "open", "close"], [name for name, _ in dll.calls if name in {"open", "close"}])

    def test_callback_exception_is_caught_and_aircraft_fallback_succeeds(self):
        """Turn a Python callback error into a camera failure that permits aircraft fallback."""
        client = SIM.SimConnectLocationClient(timeout_seconds=0.2)
        dispatch = client.dispatch
        raised = []

        def fail_camera_once(data, size, context):
            """Inject one camera dispatch error and delegate all remaining packets."""
            if client.phase == "camera" and not raised:
                raised.append(True)
                raise OSError("malformed native camera callback")
            dispatch(data, size, context)

        with mock.patch.object(client, "dispatch", side_effect=fail_camera_once):
            location, message, _ = self.acquire(FakeSimConnectDLL(), client)
        self.assertEqual([True], raised)
        self.assertIsNotNone(location, message)
        self.assertEqual("aircraft_position_fallback", location.source)
        self.assertIn("callback", client.diagnostics_snapshot()["camera_reason"].lower())

    def test_camera_and_aircraft_timeouts_share_one_total_deadline(self):
        """Bound both unavailable providers by one budget without relying on wall-clock timing."""
        class Clock:
            """Advance only for requested sleeps, making bounded waits deterministic."""

            def __init__(self):
                """Start simulated monotonic time away from zero."""
                self.now = 100.0

            def monotonic(self):
                """Return the deterministic current time used by dispatch deadlines."""
                return self.now

            def sleep(self, duration):
                """Advance simulated time while ensuring polling eventually reaches its deadline."""
                self.now += max(float(duration), 0.000001)

        clock = Clock()
        client = SIM.SimConnectLocationClient(timeout_seconds=0.2)
        dll = FakeSimConnectDLL(camera="timeout", aircraft="timeout")
        with mock.patch.object(SIM.time, "monotonic", side_effect=clock.monotonic), mock.patch.object(SIM.time, "sleep", side_effect=clock.sleep):
            location, message, _ = self.acquire(dll, client)
        self.assertIsNone(location)
        self.assertTrue(any(name == "aircraft_get" for name, _ in dll.calls))
        self.assertLessEqual(clock.now - 100.0, client.timeout_seconds + 0.00001)
        report = client.diagnostics_snapshot()
        self.assertIn("timeout", report["camera_reason"].lower())
        self.assertIn("timeout", report["aircraft_reason"].lower())


class SimulatorHandshakeTests(unittest.TestCase):
    """Match actual server identities and conservatively reject conflicting metadata."""

    def test_server_names_versions_and_conflicts(self):
        """Recognize known product identities and avoid guesses for conflicting or future servers."""
        cases = (
            (b"KittyHawk", 11, "MSFS", 2020),
            (b"SunRise", 12, "MSFS", 2024),
            (b"kItTyHaWk", 11, "MSFS", 2020),
            (b"sUnRiSe", 12, "MSFS", 2024),
            (b"Microsoft Flight Simulator", 11, "MSFS", 2020),
            (b"Microsoft Flight Simulator", 12, "MSFS", 2024),
            (b"MSFS 2020", 11, "MSFS", 2020),
            (b"Microsoft Flight Simulator 2024", 12, "MSFS", 2024),
            (b"KittyHawk", 12, "MSFS", None),
            (b"SunRise", 11, "MSFS", None),
            (b"Microsoft Flight Simulator 2020", 12, "MSFS", None),
            (b"Microsoft Flight Simulator 2024", 11, "MSFS", None),
            (b"Unidentified simulator", 11, "unknown", None),
            (b"Future Simulator", 13, "unknown", None),
            (b"Microsoft Flight Simulator", 13, "MSFS", None),
            (b"Microsoft Flight Simulator 2028", 12, "MSFS", None),
        )
        for name, major, family, generation in cases:
            with self.subTest(name=name, major=major):
                packet = packet_header(SIM._SimConnectRecvOpen(), SIM.SIMCONNECT_RECV_ID_OPEN)
                packet.szApplicationName = name
                packet.dwApplicationVersionMajor = major
                packet.dwApplicationBuildMajor = 282174
                packet.dwApplicationBuildMinor = 999
                packet.dwSimConnectVersionMajor = 11
                packet.dwSimConnectBuildMajor = 62651
                packet.dwSimConnectBuildMinor = 3
                info = SIM.SimulatorInfo.from_open(packet)
                self.assertEqual(family, info.family)
                self.assertEqual(generation, info.generation)
                self.assertEqual(f"{major}.0.282174.999", info.app_version)
                self.assertEqual("11.0.62651.3", info.simconnect_version)


class SimConnectLoaderTests(unittest.TestCase):
    """Prefer the distributed runtime, while preserving explicit advanced overrides."""

    def test_frozen_embedded_runtime_precedes_legacy_adjacent_copy(self):
        """Prefer the extracted bundled DLL and retain adjacent discovery only as a fallback."""
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            executable = root / "installed" / "Uploader.exe"
            adjacent = executable.parent / "SimConnect.dll"
            embedded_root = root / "extracted"
            embedded = embedded_root / "runtime" / "simconnect" / "SimConnect.dll"
            for dll in (adjacent, embedded):
                dll.parent.mkdir(parents=True, exist_ok=True)
                dll.write_bytes(b"runtime fixture")
            client = SIM.SimConnectLocationClient(app_dir=embedded_root)
            with mock.patch.object(SIM.sys, "frozen", True, create=True), mock.patch.object(SIM.sys, "executable", str(executable)), mock.patch.object(SIM.sys, "_MEIPASS", str(embedded_root), create=True), mock.patch.dict(SIM.os.environ, {SIM.SIMCONNECT_DLL_ENV_VAR: ""}):
                selected, tried = client.resolve_dll_path()
            self.assertEqual(embedded.resolve(), selected)
            self.assertNotIn(adjacent.resolve(), tried)
            embedded.unlink()
            with mock.patch.object(SIM.sys, "frozen", True, create=True), mock.patch.object(SIM.sys, "executable", str(executable)), mock.patch.object(SIM.sys, "_MEIPASS", str(embedded_root), create=True), mock.patch.dict(SIM.os.environ, {SIM.SIMCONNECT_DLL_ENV_VAR: ""}):
                selected, tried = client.resolve_dll_path()
            self.assertEqual(adjacent.resolve(), selected)
            self.assertIn(embedded.resolve(), tried)

    def test_source_runtime_and_explicit_override_environment_precedence(self):
        """Respect valid advanced overrides and recover bundled source paths from invalid ones."""
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "source"
            bundled = source / "SimConnect.dll"
            override = root / "override.dll"
            env_dll = root / "environment.dll"
            source.mkdir()
            for dll in (bundled, override, env_dll):
                dll.write_bytes(b"runtime fixture")
            cases = (
                ("", "", bundled),
                (str(override), str(env_dll), override),
                ("", str(env_dll), env_dll),
                (str(root / "missing-override.dll"), str(env_dll), env_dll),
                (str(root / "missing-override.dll"), str(root / "missing-env.dll"), bundled),
            )
            for configured, env_value, expected in cases:
                with self.subTest(configured=configured, env=env_value):
                    client = SIM.SimConnectLocationClient(configured, app_dir=source)
                    with mock.patch.object(SIM.sys, "frozen", False, create=True), mock.patch.object(SIM.sys, "_MEIPASS", None, create=True), mock.patch.dict(SIM.os.environ, {SIM.SIMCONNECT_DLL_ENV_VAR: env_value}):
                        selected, tried = client.resolve_dll_path()
                    self.assertEqual(expected.resolve(), selected)
                    self.assertEqual(len(set(tried)), len(tried))


def load_main_module():
    """Load the uploader entry point without opening its Tkinter application."""
    loader = importlib.machinery.SourceFileLoader("gallery_simconnect_integration_test", str(WINAPP_DIR / "gallery_watch_upload.pyw"))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


class WatchedUploadLocationTests(unittest.TestCase):
    """An optional simulator location must never prevent a watched upload."""

    @classmethod
    def setUpClass(cls):
        """Load the entry point once without starting the GUI or persistent workers."""
        cls.main = load_main_module()

    def watcher(self):
        """Create an isolated watcher without reading or writing persisted state."""
        watcher = object.__new__(self.main.WatcherThread)
        watcher.config = self.main.WatcherConfig(gallery_url="https://example.test", api_key="test-key", delete_uploaded_files=False)
        watcher.events = queue.Queue()
        watcher.stop_event = threading.Event()
        watcher.initial_paths = set()
        watcher.remote_skipped_paths = set()
        watcher.stability = mock.Mock()
        watcher.stability.stable.return_value = True
        watcher.state = mock.Mock()
        watcher.state.already_uploaded_path.return_value = False
        watcher.state.already_uploaded_hash.return_value = False
        watcher.state.can_attempt.return_value = True
        watcher.remote_inventory = mock.Mock()
        watcher.remote_inventory.has_hash.return_value = False
        watcher.simconnect_client = mock.Mock()
        watcher.simconnect_client.diagnostics_snapshot.return_value = {"result": "unavailable"}
        watcher.simconnect_diagnostics = {}
        watcher.upload_watched_file = mock.Mock(return_value={"uploaded": 1, "scanned": 1})
        return watcher

    def test_location_failure_and_unexpected_exception_do_not_block_upload(self):
        """Complete upload and state recording despite unavailable or unexpectedly broken geolocation."""
        for result in ((None, "simulator is not running"), RuntimeError("native provider failed")):
            with self.subTest(result=str(result)), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                photo = root / "flight.png"
                photo.write_bytes(b"watched image")
                watcher = self.watcher()
                if isinstance(result, Exception):
                    watcher.simconnect_client.current_location.side_effect = result
                else:
                    watcher.simconnect_client.current_location.return_value = result
                watcher.scan_once(root, "https://example.test/?route=api/upload")
                watcher.upload_watched_file.assert_called_once()
                self.assertEqual({}, watcher.upload_watched_file.call_args.args[2])
                watcher.state.mark_uploaded.assert_called_once()
                watcher.state.mark_failure.assert_not_called()

    def test_aircraft_source_is_internal_and_preserves_existing_form_contract(self):
        """Keep aircraft provenance internal while preserving server-compatible multipart fields."""
        watcher = self.watcher()
        watcher.simconnect_client.current_location.return_value = (SIM.SimCameraLocation(49.123, 13.456, 2284.0, source="aircraft_position"), "aircraft position received")
        fields = watcher.sim_camera_metadata_fields(Path("flight.png"))
        self.assertEqual("simconnect_camera", fields["sim_location_source"])
        self.assertEqual("49.1230000", fields["sim_camera_latitude"])
        self.assertEqual("13.4560000", fields["sim_camera_longitude"])
        self.assertEqual("2284.00", fields["sim_camera_altitude"])

    def test_disabled_location_does_not_call_native_transport(self):
        """Honor the existing metadata preference without probing SimConnect."""
        watcher = self.watcher()
        watcher.config.attach_sim_camera_metadata = False
        self.assertEqual({}, watcher.sim_camera_metadata_fields(Path("flight.png")))
        watcher.simconnect_client.current_location.assert_not_called()


if __name__ == "__main__":
    unittest.main()
