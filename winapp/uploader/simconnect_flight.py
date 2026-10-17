# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/simconnect_flight.py
# Module Type: SimConnect Flight Provider
# Purpose: Stream read-only user-aircraft telemetry on one native connection.
# Responsibilities:
#   - Reuse the existing DLL loader, ctypes signatures and OPEN identity.
#   - Stream core per simulation frame and slower isolated optional SimVars.
#   - Mark pause, load, aircraft and rate discontinuities.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Persistent read-only SimConnect telemetry acquisition for flight recording."""
import ctypes
import math
import os
import threading
import time
from datetime import datetime, timezone
from typing import Any, Callable

from uploader.flight_data import FlightSample
from uploader.simconnect_location import (
    SIMCONNECT_RECV_ID_EXCEPTION,
    SIMCONNECT_RECV_ID_OPEN,
    SIMCONNECT_RECV_ID_QUIT,
    SIMCONNECT_RECV_ID_SIMOBJECT_DATA,
    SimConnectLocationClient,
    _SimConnectRecv,
    _SimConnectRecvException,
    _SimConnectRecvOpen,
    simconnect_hresult_failed,
)

# This seam lets native-provider fixtures run without changing host os.name.
WINDOWS_NATIVE_TRANSPORT = os.name == "nt"
# SDK enum values, not adjustable policy.
# Core clock is documented E:ABSOLUTE TIME in seconds.
SIMCONNECT_PERIOD_SECOND = 4
SIMCONNECT_PERIOD_SIM_FRAME = 3
SIMCONNECT_DATATYPE_FLOAT64 = 4
SIMCONNECT_DATATYPE_STRING256 = 9
# Private definition/request identities for the isolated SDK TITLE string.
FLIGHT_TITLE_DEFINITION_ID = 400
FLIGHT_TITLE_REQUEST_ID = 401
# Exact mass-unit conversion for the optional standard TOTAL_WEIGHT signal.
POUNDS_TO_KILOGRAMS = 0.45359237
SIMCONNECT_OBJECT_ID_USER = 0
FLIGHT_CORE_DEFINITION_ID = 300
FLIGHT_CORE_REQUEST_ID = 301
FLIGHT_OPTIONAL_FIRST_ID = 310
# Bound OPEN and next-frame waits so absent simulators stay nonfatal.
# Type: float. Units: seconds. Scope: one connection/frame request.
# Consumers: SimConnectFlightClient._pump. Rationale: bounded nonblocking retries.
FLIGHT_RESPONSE_TIMEOUT_SECONDS = 2.5
# Sleep between empty CallDispatch polling cycles.
# Type: float. Units: seconds. Scope: one response wait.
# Consumers: SimConnectFlightClient._pump. Rationale: low CPU with prompt dispatch.
FLIGHT_DISPATCH_INTERVAL_SECONDS = 0.01
# Drain this many native queue entries per caller sample opportunity.
# Type: int. Units: packets. Scope: one pump cycle.
# Consumers: SimConnectFlightClient._pump.
# Rationale: keep up with frame telemetry without unbounded callback work.
FLIGHT_DISPATCH_DRAIN_LIMIT = 64
# Retain bounded attribution for asynchronous SDK exceptions.
# Type: int. Units: sends. Scope: one native handle.
# Consumers: SimConnectFlightClient._send.
# Rationale: recent operations remain diagnosable without unbounded memory.
FLIGHT_SEND_HISTORY_LIMIT = 128
# Clock regression tolerated before splitting a simulator timeline.
# Type: float. Units: seconds. Scope: adjacent telemetry frames.
# Consumers: SimConnectFlightClient._sample_from_core.
# Rationale: tolerate floating point rounding, not replay resets.
SIM_CLOCK_BACKWARD_TOLERANCE_SECONDS = 0.01
# Largest simulator-time jump accepted as continuous evidence.
# Type: float. Units: seconds. Scope: adjacent telemetry frames.
# Consumers: SimConnectFlightClient._sample_from_core.
# Rationale: large jumps may cross pause or load transitions.
SIM_CLOCK_GAP_LIMIT_SECONDS = 5.0
# Host delay without simulator-clock progress indicating a pause.
# Type: float. Units: seconds. Scope: one native connection.
# Consumers: SimConnectFlightClient._sample_from_core.
# Rationale: catch clock stalls when pause events are unavailable.
SIM_CLOCK_STALL_LIMIT_SECONDS = 2.0
# Expire optional SimVars when their low-rate subscription stops updating.
# Type: float. Units: seconds. Scope: one connection and aircraft.
# Consumers: SimConnectFlightClient._sample_from_core.
# Rationale: old aircraft signals must not appear as current telemetry.
OPTIONAL_VALUE_MAX_AGE_SECONDS = 3.0
# Reject absolute-time jumps implausible against host monotonic elapsed time.
# Type: float. Units: ratio. Scope: adjacent received frames.
# Consumers: SimConnectFlightClient._sample_from_core.
# Rationale: detect wall-clock corrections without assuming real-time rate.
SIM_CLOCK_HOST_RATE_SANITY_RATIO = 20.0
# Permit normal packet jitter when applying the absolute-time plausibility guard.
# Type: float. Units: seconds. Scope: adjacent received frames.
# Consumers: SimConnectFlightClient._sample_from_core.
# Rationale: avoid classifying a delayed dispatch as a clock correction.
SIM_CLOCK_HOST_JITTER_ALLOWANCE_SECONDS = 2.0
METRES_PER_FOOT = 0.3048
METRES_PER_SECOND_PER_KNOT = 0.5144444444444445
METRES_PER_SECOND_PER_FPM = 0.00508
CORE_SIMVARS = (
    (b"PLANE LATITUDE", b"degrees"),
    (b"PLANE LONGITUDE", b"degrees"),
    (b"PLANE ALTITUDE", b"feet"),
    (b"PLANE ALT ABOVE GROUND", b"feet"),
    (b"E:ABSOLUTE TIME", b"seconds"),
    (b"SIM ON GROUND", b"bool"),
    (b"AIRSPEED INDICATED", b"knots"),
    (b"AIRSPEED TRUE", b"knots"),
    (b"GROUND VELOCITY", b"knots"),
    (b"VERTICAL SPEED", b"feet per minute"),
    (b"PLANE PITCH DEGREES", b"degrees"),
    (b"PLANE BANK DEGREES", b"degrees"),
    (b"PLANE HEADING DEGREES TRUE", b"degrees"),
)
OPTIONAL_SIMVARS = (
    ("rate", b"SIMULATION RATE", b"number"),
    ("light_nav", b"LIGHT NAV", b"bool"),
    ("light_beacon", b"LIGHT BEACON", b"bool"),
    ("light_landing", b"LIGHT LANDING", b"bool"),
    ("light_taxi", b"LIGHT TAXI", b"bool"),
    ("light_strobe", b"LIGHT STROBE", b"bool"),
    ("light_wing", b"LIGHT WING", b"bool"),
    ("light_logo", b"LIGHT LOGO", b"bool"),
    ("light_cabin", b"LIGHT CABIN", b"bool"),
    ("gear", b"GEAR HANDLE POSITION", b"percent"),
    ("flaps", b"FLAPS HANDLE PERCENT", b"percent"),
    ("fuel", b"FUEL TOTAL QUANTITY", b"gallons"),
    ("wind_velocity", b"AMBIENT WIND VELOCITY", b"knots"),
    ("wind_direction", b"AMBIENT WIND DIRECTION", b"degrees"),
    ("total_weight", b"TOTAL_WEIGHT", b"pounds"),
    ("brake_left", b"BRAKE LEFT POSITION", b"percent"),
    ("brake_right", b"BRAKE RIGHT POSITION", b"percent"),
    ("autopilot", b"AUTOPILOT MASTER", b"bool"),
    ("load_factor", b"G FORCE", b"g"),
    ("engine_1", b"GENERAL ENG RPM:1", b"rpm"),
    ("engine_2", b"GENERAL ENG RPM:2", b"rpm"),
    ("engine_3", b"GENERAL ENG RPM:3", b"rpm"),
    ("engine_4", b"GENERAL ENG RPM:4", b"rpm"),
)
EVENT_SIM_START = 100
EVENT_SIM_STOP = 101
EVENT_PAUSE_EX1 = 102
EVENT_FLIGHT_LOADED = 103
EVENT_AIRCRAFT_LOADED = 104
REQUEST_FLIGHT_STATE = 105
REQUEST_AIRCRAFT_STATE = 106


class _FlightCoreData(ctypes.Structure):
    """Packed SDK core response with thirteen untagged FLOAT64 values."""

    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        (name, ctypes.c_uint32)
        for name in (
            "dwRequestID", "dwObjectID", "dwDefineID", "dwFlags",
            "dwentrynumber", "dwoutof", "dwDefineCount",
        )
    ] + [
        (f"value_{index}", ctypes.c_double)
        for index in range(len(CORE_SIMVARS))
    ]


class _FlightOptionalData(ctypes.Structure):
    """Packed SDK response with one untagged FLOAT64 value."""

    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        (name, ctypes.c_uint32)
        for name in (
            "dwRequestID", "dwObjectID", "dwDefineID", "dwFlags",
            "dwentrynumber", "dwoutof", "dwDefineCount",
        )
    ] + [("value", ctypes.c_double)]


class _FlightTitleData(ctypes.Structure):
    """Packed SDK response containing the aircraft TITLE string."""

    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        (name, ctypes.c_uint32)
        for name in (
            "dwRequestID", "dwObjectID", "dwDefineID", "dwFlags",
            "dwentrynumber", "dwoutof", "dwDefineCount",
        )
    ] + [("value", ctypes.c_char * 256)]


class _FlightEvent(ctypes.Structure):
    """SDK event identity and integer value."""

    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        ("uGroupID", ctypes.c_uint32),
        ("uEventID", ctypes.c_uint32),
        ("dwData", ctypes.c_uint32),
    ]


class _FlightFilenameEvent(ctypes.Structure):
    """Flight or aircraft load event with its inline MAX_PATH filename."""

    _pack_ = 1
    _fields_ = _FlightEvent._fields_ + [
        ("szFileName", ctypes.c_char * 260),
        ("dwFlags", ctypes.c_uint32),
    ]


class _FlightSystemState(ctypes.Structure):
    """Initial FlightLoaded response when the recorder attaches mid-flight."""

    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        ("dwRequestID", ctypes.c_uint32),
        ("dwInteger", ctypes.c_uint32),
        ("fFloat", ctypes.c_float),
        ("szString", ctypes.c_char * 260),
    ]


class SimConnectFlightClient:
    """Own a persistent read-only SimConnect handle on the recorder thread."""

    def __init__(self, dll_path: str, emit: Callable[[str, str], None]) -> None:
        """Prepare DLL discovery and per-connection flight state.

        Args:
            dll_path (str): Optional explicit SimConnect.dll path.
            emit (Callable[[str, str], None]): Thread-safe level/message sink.

        Returns:
            None: Does not load a DLL or open a connection.
        """
        self.transport = SimConnectLocationClient(dll_path=dll_path)
        self.emit = emit
        self.dll: Any = None
        self.callback: Any = None
        self.handle = ctypes.c_void_p()
        self.simulator = "unknown"
        self.simulator_build: str | None = None
        self.generation: int | None = None
        self.aircraft: str | None = None
        self.flight_loaded = False
        self.running: bool | None = None
        self.pause_flags: int | None = None
        self.values: dict[str, float] = {}
        self.value_timestamps: dict[str, float] = {}
        self.unavailable: set[str] = set()
        self.latest_core: tuple[float, ...] | None = None
        self.core_sequence = 0
        self.started_monotonic = time.monotonic()
        self.pending_discontinuity: str | None = None
        self.last_rate: float | None = None
        self.last_sim_elapsed: float | None = None
        self.last_absolute_sim_time: float | None = None
        self.last_sample_host_elapsed: float | None = None
        self.last_sim_progress_host: float | None = None
        self.clock_stall_reported = False
        self.send_ids: dict[int, str] = {}
        self.last_error = ""
        self.connection_lost = False
        self._lock = threading.RLock()

    def _emit(self, level: str, message: str) -> None:
        """Send an activity diagnostic without breaking telemetry.

        Args:
            level (str): Existing activity level, normally info or warning.
            message (str): Concise message without credentials.

        Returns:
            None: Event-sink errors are ignored.
        """
        try:
            self.emit(level, "[simconnect][flight] " + message)
        except Exception:
            pass

    def _send(
        self,
        name: str,
        phase: str,
        *arguments: int | float | bytes | None,
    ) -> None:
        """Call one native export and retain bounded send-ID attribution.

        Args:
            name (str): SimConnect export suffix.
            phase (str): Operation purpose used for exception mapping.
            arguments (int | float | bytes | None): Ordered SDK parameters after handle.

        Returns:
            None: Successful sends are retained in recent attribution state.

        Raises:
            OSError: Native export returns a failed HRESULT.
        """
        result = getattr(self.dll, "SimConnect_" + name)(
            self.handle, *arguments
        )
        if simconnect_hresult_failed(result):
            raise OSError(f"{name} failed: HRESULT {int(result)}")
        sent = ctypes.c_uint32()
        result = self.dll.SimConnect_GetLastSentPacketID(
            self.handle, ctypes.byref(sent)
        )
        if not simconnect_hresult_failed(result) and sent.value:
            self.send_ids[int(sent.value)] = phase
            while len(self.send_ids) > FLIGHT_SEND_HISTORY_LIMIT:
                del self.send_ids[next(iter(self.send_ids))]

    def _native_dispatch(
        self,
        data: ctypes.POINTER(_SimConnectRecv),
        size: int,
        context: ctypes.c_void_p,
    ) -> None:
        """Contain Python exceptions before returning through the C callback ABI.

        Args:
            data (ctypes.POINTER(_SimConnectRecv)): SDK packet pointer.
            size (int): Available callback bytes.
            context (ctypes.c_void_p): Unused SDK callback context.

        Returns:
            None: Callback failures become a soft connection error.
        """
        try:
            self.dispatch(data, size, context)
        except Exception as exc:
            self.last_error = (
                f"SimConnect callback failed: {type(exc).__name__}: {exc}"
            )

    def _pump(
        self,
        deadline: float,
        stop_on_core: bool = False,
        stop_on_open: bool = False,
    ) -> None:
        """Dispatch bounded batches until new data, handshake or timeout.

        Args:
            deadline (float): Absolute monotonic response deadline.
            stop_on_core (bool): Whether a new core frame ends this pump.
            stop_on_open (bool): Whether OPEN handshake ends this pump.

        Returns:
            None: Dispatches queued events/data on this connection thread.

        Raises:
            OSError: Native dispatch, protocol or disconnect failure occurs.
        """
        previous = self.core_sequence
        while time.monotonic() < deadline:
            if self.last_error:
                raise OSError(self.last_error)
            if self.connection_lost:
                raise OSError("Simulator disconnected.")
            new_core = False
            for _index in range(FLIGHT_DISPATCH_DRAIN_LIMIT):
                result = self.dll.SimConnect_CallDispatch(
                    self.handle, self.callback, None
                )
                if simconnect_hresult_failed(result):
                    self.connection_lost = True
                    raise OSError(
                        f"SimConnect dispatch failed: HRESULT {int(result)}"
                    )
                if self.transport.open_received and stop_on_open:
                    return
                if self.core_sequence > previous:
                    new_core = True
                    previous = self.core_sequence
            if stop_on_core and new_core:
                return
            time.sleep(min(
                FLIGHT_DISPATCH_INTERVAL_SECONDS,
                max(0.0, deadline - time.monotonic()),
            ))

    def _subscribe(self, event_id: int, name: bytes) -> None:
        """Subscribe to optional state events without failing core capture.

        Args:
            event_id (int): Per-handle client event ID.
            name (bytes): SDK system event name.

        Returns:
            None: Unsupported state events are diagnosed and omitted.
        """
        try:
            self._send(
                "SubscribeToSystemEvent", f"event:{event_id}", event_id, name
            )
        except (AttributeError, OSError) as exc:
            self._emit("warning", f"State event {name.decode()} unavailable: {exc}")

    def _add_core_definition(self) -> None:
        """Register common core SimVars and one frame-based user request.

        Returns:
            None: All core values share one ordered definition.

        Raises:
            OSError: A standard cross-generation core variable is rejected.
        """
        for variable, units in CORE_SIMVARS:
            self._send(
                "AddToDataDefinition", "definition:core",
                FLIGHT_CORE_DEFINITION_ID, variable, units,
                SIMCONNECT_DATATYPE_FLOAT64, 0.0, 0xFFFFFFFF,
            )
        self._send(
            "RequestDataOnSimObject", "request:core",
            FLIGHT_CORE_REQUEST_ID, FLIGHT_CORE_DEFINITION_ID,
            SIMCONNECT_OBJECT_ID_USER, SIMCONNECT_PERIOD_SIM_FRAME,
            0, 0, 0, 0,
        )

    def _add_title_definition(self) -> None:
        """Request the documented aircraft TITLE as an isolated string SimVar.

        Returns:
            None: Aircraft identity is populated when a valid response arrives.
                A failed string subscription leaves aircraft as None.
        """
        try:
            self._send(
                "AddToDataDefinition", "definition:title",
                FLIGHT_TITLE_DEFINITION_ID, b"TITLE", None,
                SIMCONNECT_DATATYPE_STRING256, 0.0, 0xFFFFFFFF,
            )
            self._send(
                "RequestDataOnSimObject", "request:title",
                FLIGHT_TITLE_REQUEST_ID, FLIGHT_TITLE_DEFINITION_ID,
                SIMCONNECT_OBJECT_ID_USER, SIMCONNECT_PERIOD_SIM_FRAME,
                0, 0, 0, 0,
            )
        except (AttributeError, OSError) as exc:
            self._emit("warning", f"Aircraft TITLE unavailable: {exc}")

    def _add_optional(
        self,
        index: int,
        key: str,
        variable: bytes,
        units: bytes,
    ) -> None:
        """Register an isolated optional SimVar on the simulation-frame cadence.

        Args:
            index (int): Position in OPTIONAL_SIMVARS.
            key (str): Normalized field identifier.
            variable (bytes): SDK simulation variable name.
            units (bytes): SDK units for returned data.

        Returns:
            None: Unsupported optional data remains explicitly unknown.
        """
        definition = FLIGHT_OPTIONAL_FIRST_ID + index * 2
        try:
            self._send(
                "AddToDataDefinition", f"definition:{key}",
                definition, variable, units, SIMCONNECT_DATATYPE_FLOAT64,
                0.0, 0xFFFFFFFF,
            )
            self._send(
                "RequestDataOnSimObject", f"request:{key}",
                definition + 1, definition, SIMCONNECT_OBJECT_ID_USER,
                (
                    SIMCONNECT_PERIOD_SIM_FRAME
                    if key == "load_factor"
                    else SIMCONNECT_PERIOD_SECOND
                ),
                0, 0, 0, 0,
            )
        except (AttributeError, OSError) as exc:
            self.unavailable.add(key)
            self._emit("warning", f"SimVar {variable.decode()} unavailable: {exc}")

    def open(self) -> None:
        """Open one owned handle, identify its simulator, and request telemetry.

        Returns:
            None: Starts recurring read-only data subscriptions.

        Raises:
            OSError: Windows, DLL, OPEN or core data setup is unavailable.
        """
        with self._lock:
            # A native handle owns all cached identity, event and clock state.
            # Reconnect starts a fresh OPEN handshake while preserving the
            # discontinuity that explains the gap between the two handles.
            pending_discontinuity = self.pending_discontinuity
            self.connection_lost = False
            self.last_error = ""
            self.transport.open_received = False
            self.transport.simulator_info = type(self.transport.simulator_info)()
            self.simulator = "unknown"
            self.simulator_build = None
            self.generation = None
            self.aircraft = None
            self.flight_loaded = False
            self.running = None
            self.pause_flags = None
            self.values.clear()
            self.value_timestamps.clear()
            self.unavailable.clear()
            self.latest_core = None
            self.last_rate = None
            self.last_sim_elapsed = None
            self.last_absolute_sim_time = None
            self.last_sample_host_elapsed = None
            self.last_sim_progress_host = None
            self.clock_stall_reported = False
            self.send_ids.clear()
            self.core_sequence = 0
            self.pending_discontinuity = pending_discontinuity
            if not WINDOWS_NATIVE_TRANSPORT:
                raise OSError("SimConnect flight recording is available only on Windows.")
            path, tried = self.transport.resolve_dll_path()
            if path is None:
                raise OSError(
                    "SimConnect.dll unavailable. Tried: "
                    + ", ".join(map(str, tried))
                )
            self.dll = ctypes.WinDLL(str(path))
            callback_type = getattr(ctypes, "WINFUNCTYPE", ctypes.CFUNCTYPE)(
                None, ctypes.POINTER(_SimConnectRecv),
                ctypes.c_uint32, ctypes.c_void_p,
            )
            self.transport.configure_functions(self.dll, callback_type)
            for name, parameters in {
                "SubscribeToSystemEvent": [ctypes.c_uint32, ctypes.c_char_p],
                "RequestSystemState": [ctypes.c_uint32, ctypes.c_char_p],
            }.items():
                function = getattr(self.dll, "SimConnect_" + name)
                function.argtypes = [ctypes.c_void_p] + parameters
                function.restype = ctypes.c_long
            self.callback = callback_type(self._native_dispatch)
            result = self.dll.SimConnect_Open(
                ctypes.byref(self.handle), b"PHP Gallery flight recorder",
                None, 0, None, 0,
            )
            if simconnect_hresult_failed(result) or not self.handle.value:
                raise OSError(f"SimConnect_Open failed: HRESULT {int(result)}")
            self._pump(
                time.monotonic() + FLIGHT_RESPONSE_TIMEOUT_SECONDS,
                stop_on_open=True,
            )
            if not self.transport.open_received:
                # Never expose a prior connection's simulator identity when
                # the new handle did not complete its own OPEN handshake.
                self.transport.simulator_info = type(self.transport.simulator_info)()
                self.simulator = "unknown"
                self.simulator_build = None
                self.generation = None
                raise OSError("SimConnect OPEN identity handshake timed out.")
            self.pending_discontinuity = (
                pending_discontinuity or self.pending_discontinuity
            )
            info = self.transport.simulator_info
            self.simulator = info.display_name
            # OPEN versions identify the server/API, not the retail product.
            self.simulator_build = (
                f"{info.app_version} / SimConnect {info.simconnect_version}"
                if any(
                    value and value != "unknown"
                    for value in (info.app_version, info.simconnect_version)
                )
                else None
            )
            self.generation = info.generation
            for event_id, name in (
                (EVENT_SIM_START, b"SimStart"),
                (EVENT_SIM_STOP, b"SimStop"),
                (EVENT_PAUSE_EX1, b"Pause_EX1"),
                (EVENT_FLIGHT_LOADED, b"FlightLoaded"),
                (EVENT_AIRCRAFT_LOADED, b"AircraftLoaded"),
            ):
                self._subscribe(event_id, name)
            try:
                self._send(
                    "RequestSystemState", "state:flight",
                    REQUEST_FLIGHT_STATE, b"FlightLoaded",
                )
            except (AttributeError, OSError) as exc:
                self._emit("warning", f"Initial flight state unavailable: {exc}")
            try:
                self._send(
                    "RequestSystemState", "state:aircraft",
                    REQUEST_AIRCRAFT_STATE, b"AircraftLoaded",
                )
            except (AttributeError, OSError) as exc:
                self._emit("warning", f"Initial aircraft identity unavailable: {exc}")
            self._add_title_definition()
            self._add_core_definition()
            for index, (key, variable, units) in enumerate(OPTIONAL_SIMVARS):
                self._add_optional(index, key, variable, units)
            self._emit("info", f"Read-only telemetry connected to {self.simulator}.")

    def _header(
        self,
        data: ctypes.POINTER(_SimConnectRecv),
        size: int,
    ) -> _SimConnectRecv | None:
        """Validate a receive pointer and declared packet size.

        Args:
            data (ctypes.POINTER(_SimConnectRecv)): SDK packet pointer.
            size (int): Available packet bytes.

        Returns:
            _SimConnectRecv | None: Valid header, or None with a soft error set.
        """
        if not data or size < ctypes.sizeof(_SimConnectRecv):
            self.last_error = "Truncated SimConnect receive header."
            return None
        header = data.contents
        if not ctypes.sizeof(_SimConnectRecv) <= header.dwSize <= size:
            self.last_error = "Invalid SimConnect receive size."
            return None
        return header

    def _exception(
        self,
        data: ctypes.POINTER(_SimConnectRecv),
        size: int,
    ) -> None:
        """Map an SDK exception to an optional field or core transport failure.

        Args:
            data (ctypes.POINTER(_SimConnectRecv)): Exception packet pointer.
            size (int): Available bytes.

        Returns:
            None: Optional fields degrade alone; core failure is surfaced.
        """
        if size < ctypes.sizeof(_SimConnectRecvException):
            self.last_error = "Truncated SimConnect exception packet."
            return
        packet = ctypes.cast(
            data, ctypes.POINTER(_SimConnectRecvException)
        ).contents
        phase = self.send_ids.get(packet.dwSendID, "")
        if phase.endswith(":core"):
            self.last_error = f"Core telemetry rejected ({packet.dwException})."
        elif phase.startswith(("definition:", "request:")):
            key = phase.split(":", 1)[1]
            self.unavailable.add(key)
            self.values.pop(key, None)
            self.value_timestamps.pop(key, None)
            self._emit("warning", f"Optional telemetry {key} unavailable.")

    def _data(
        self,
        data: ctypes.POINTER(_SimConnectRecv),
        size: int,
    ) -> None:
        """Validate the core packet or one optional data packet.

        Args:
            data (ctypes.POINTER(_SimConnectRecv)): Data packet pointer.
            size (int): Available bytes.

        Returns:
            None: Valid values update this connection's latest-sample state.
        """
        if size >= ctypes.sizeof(_FlightTitleData):
            title_packet = ctypes.cast(
                data, ctypes.POINTER(_FlightTitleData)
            ).contents
            if (
                title_packet.dwRequestID == FLIGHT_TITLE_REQUEST_ID
                and title_packet.dwDefineID == FLIGHT_TITLE_DEFINITION_ID
                and title_packet.dwDefineCount == 1
            ):
                title = bytes(title_packet.value).split(b"\0", 1)[0]
                self.aircraft = title.decode("utf-8", "replace").strip() or None
                return
        if size >= ctypes.sizeof(_FlightCoreData):
            packet = ctypes.cast(data, ctypes.POINTER(_FlightCoreData)).contents
            if (
                packet.dwRequestID != FLIGHT_CORE_REQUEST_ID
                or packet.dwDefineID != FLIGHT_CORE_DEFINITION_ID
                or packet.dwDefineCount != len(CORE_SIMVARS)
                or packet.dwentrynumber != 1
                or packet.dwoutof != 1
            ):
                return
            values = tuple(
                float(getattr(packet, f"value_{index}"))
                for index in range(len(CORE_SIMVARS))
            )
            if all(math.isfinite(value) for value in values):
                self.latest_core = values
                self.core_sequence += 1
            else:
                self.last_error = "Core telemetry contains nonfinite values."
            return
        if size < ctypes.sizeof(_FlightOptionalData):
            return
        packet = ctypes.cast(
            data, ctypes.POINTER(_FlightOptionalData)
        ).contents
        for index, (key, _variable, _units) in enumerate(OPTIONAL_SIMVARS):
            definition = FLIGHT_OPTIONAL_FIRST_ID + index * 2
            if packet.dwDefineID == definition:
                if packet.dwRequestID == definition + 1 and packet.dwDefineCount == 1:
                    value = float(packet.value)
                    if math.isfinite(value):
                        self.values[key] = value
                        self.value_timestamps[key] = time.monotonic()
                return

    def dispatch(
        self,
        data: ctypes.POINTER(_SimConnectRecv),
        size: int,
        _context: ctypes.c_void_p,
    ) -> None:
        """Handle OPEN, state, exception and telemetry receive packets.

        Args:
            data (ctypes.POINTER(_SimConnectRecv)): SDK packet pointer.
            size (int): Available packet bytes.
            _context (ctypes.c_void_p): Unused SDK callback context.

        Returns:
            None: Updates this connection's state only.
        """
        header = self._header(data, size)
        if header is None:
            return
        if header.dwID == SIMCONNECT_RECV_ID_OPEN:
            if header.dwSize < ctypes.sizeof(_SimConnectRecvOpen):
                self.last_error = "Truncated OPEN handshake."
                return
            packet = ctypes.cast(data, ctypes.POINTER(_SimConnectRecvOpen)).contents
            self.transport.simulator_info = self.transport.simulator_info.from_open(packet)
            self.transport.open_received = True
        elif header.dwID == SIMCONNECT_RECV_ID_EXCEPTION:
            self._exception(data, header.dwSize)
        elif header.dwID == SIMCONNECT_RECV_ID_QUIT:
            self.connection_lost = True
            self.pending_discontinuity = "simulator disconnected"
        elif header.dwID == SIMCONNECT_RECV_ID_SIMOBJECT_DATA:
            self._data(data, header.dwSize)
        elif header.dwID == 4:
            if header.dwSize < ctypes.sizeof(_FlightEvent):
                self.last_error = "Truncated state event."
                return
            packet = ctypes.cast(data, ctypes.POINTER(_FlightEvent)).contents
            if packet.uEventID == EVENT_SIM_START:
                self.running = True
                self.pending_discontinuity = "simulator started"
            elif packet.uEventID == EVENT_SIM_STOP:
                self.running = False
                self.pending_discontinuity = "simulator stopped"
            elif packet.uEventID == EVENT_PAUSE_EX1:
                prior = self.pause_flags
                self.pause_flags = int(packet.dwData)
                if prior != self.pause_flags:
                    self.pending_discontinuity = (
                        "simulator resumed" if self.pause_flags == 0
                        else "simulator paused or active-paused"
                    )
        elif header.dwID == 6:
            if header.dwSize < ctypes.sizeof(_FlightFilenameEvent):
                self.last_error = "Truncated load event."
                return
            packet = ctypes.cast(
                data, ctypes.POINTER(_FlightFilenameEvent)
            ).contents
            filename = bytes(packet.szFileName).split(
                b"\0", 1
            )[0].decode("utf-8", "replace")
            if packet.uEventID == EVENT_FLIGHT_LOADED:
                self.flight_loaded = bool(filename)
                self.values.clear()
                self.value_timestamps.clear()
                self.unavailable.clear()
                self.pending_discontinuity = "flight loaded"
            elif packet.uEventID == EVENT_AIRCRAFT_LOADED:
                self.aircraft = None
                self.values.clear()
                self.value_timestamps.clear()
                self.unavailable.clear()
                self.pending_discontinuity = "aircraft loaded"
        elif header.dwID == 15:
            if header.dwSize < ctypes.sizeof(_FlightSystemState):
                self.last_error = "Truncated initial flight state."
                return
            packet = ctypes.cast(
                data, ctypes.POINTER(_FlightSystemState)
            ).contents
            if packet.dwRequestID == REQUEST_FLIGHT_STATE:
                self.flight_loaded = bool(packet.szString)
                if self.flight_loaded:
                    self.pending_discontinuity = (\n                    self.pending_discontinuity or "attached to loaded flight"\n                )
            elif packet.dwRequestID == REQUEST_AIRCRAFT_STATE:
                loaded_aircraft = bytes(packet.szString).split(
                    b"\0", 1
                )[0].decode("utf-8", "replace")
                self.pending_discontinuity = (\n                    self.pending_discontinuity\n                    or "attached aircraft identity pending"\n                )

    def _sample_from_core(self, elapsed: float) -> FlightSample | None:
        """Normalize the latest full frame and currently available optional data.

        Args:
            elapsed (float): Monotonic connection elapsed seconds.

        Returns:
            FlightSample | None: Normalized telemetry, or None without a core frame.
        """
        values = self.latest_core
        if values is None:
            return None
        now = time.monotonic()
        for key, received_at in tuple(self.value_timestamps.items()):
            if now - received_at > OPTIONAL_VALUE_MAX_AGE_SECONDS:
                self.value_timestamps.pop(key, None)
                self.values.pop(key, None)
        (
            latitude, longitude, altitude_ft, agl_ft, absolute_sim_time, ground,
            indicated_knots, true_knots, ground_knots, vertical_fpm,
            pitch, bank, heading,
        ) = values
        rate = self.values.get("rate")
        raw_sim_time = absolute_sim_time
        discontinuity = self.pending_discontinuity
        if self.last_sim_elapsed is None or self.last_absolute_sim_time is None:
            sim_elapsed = 0.0
            sim_delta = 0.0
            self.last_sim_progress_host = elapsed
        else:
            prior_host_elapsed = self.last_sample_host_elapsed
            host_delta = max(
                0.0,
                elapsed - (
                    prior_host_elapsed if prior_host_elapsed is not None
                    else elapsed
                ),
            )
            sim_delta = raw_sim_time - self.last_absolute_sim_time
            plausible_delta = (
                host_delta * SIM_CLOCK_HOST_RATE_SANITY_RATIO
                + SIM_CLOCK_HOST_JITTER_ALLOWANCE_SECONDS
            )
            if sim_delta < -SIM_CLOCK_BACKWARD_TOLERANCE_SECONDS:
                discontinuity = discontinuity or "absolute simulator clock moved backwards"
                sim_delta = 0.0
            elif sim_delta > plausible_delta:
                discontinuity = discontinuity or "absolute simulator clock jumped"
                sim_delta = 0.0
            elif sim_delta > SIM_CLOCK_GAP_LIMIT_SECONDS:
                discontinuity = discontinuity or "simulator clock gap"
            if sim_delta > 0.0:
                self.last_sim_progress_host = elapsed
                self.clock_stall_reported = False
            elif (
                not self.clock_stall_reported
                and self.last_sim_progress_host is not None
                and elapsed - self.last_sim_progress_host > SIM_CLOCK_STALL_LIMIT_SECONDS
            ):
                discontinuity = discontinuity or "simulator clock stopped"
                self.clock_stall_reported = True
            sim_elapsed = self.last_sim_elapsed + sim_delta
        self.last_absolute_sim_time = raw_sim_time
        self.last_sample_host_elapsed = elapsed
        self.last_sim_elapsed = sim_elapsed
        self.pending_discontinuity = None
        if rate is not None and self.last_rate is not None and rate != self.last_rate:
            discontinuity = discontinuity or "simulation rate changed"
        if rate is not None:
            self.last_rate = rate
        lights: dict[str, bool | None] = {}
        for key in (
            "light_nav", "light_beacon", "light_landing", "light_taxi",
            "light_strobe", "light_wing", "light_logo", "light_cabin",
        ):
            value = self.values.get(key)
            lights[key.removeprefix("light_")] = (
                bool(round(value)) if value in (0.0, 1.0) else None
            )
        return FlightSample(
            session_elapsed_seconds=elapsed,
            recorded_utc=datetime.now(timezone.utc).isoformat(),
            simulator_elapsed_seconds=sim_elapsed,
            simulator_rate=rate,
            latitude_deg=latitude if -90 <= latitude <= 90 else None,
            longitude_deg=longitude if -180 <= longitude <= 180 else None,
            altitude_m=altitude_ft * METRES_PER_FOOT,
            agl_m=agl_ft * METRES_PER_FOOT,
            vertical_speed_mps=vertical_fpm * METRES_PER_SECOND_PER_FPM,
            ground_speed_mps=ground_knots * METRES_PER_SECOND_PER_KNOT,
            true_airspeed_mps=true_knots * METRES_PER_SECOND_PER_KNOT,
            indicated_airspeed_mps=indicated_knots * METRES_PER_SECOND_PER_KNOT,
            load_factor_g=self.values.get("load_factor"),
            pitch_deg=pitch,
            bank_deg=bank,
            heading_deg=heading % 360.0,
            on_ground=bool(round(ground)) if ground in (0.0, 1.0) else None,
            lights=lights,
            gear_position=self.values.get("gear"),
            flaps_position=self.values.get("flaps"),
            engine_rpm=tuple(
                self.values.get(f"engine_{index}") for index in range(1, 5)
            ),
            fuel_gallons=self.values.get("fuel"),
            wind_velocity_mps=(
                self.values["wind_velocity"] * METRES_PER_SECOND_PER_KNOT
                if "wind_velocity" in self.values else None
            ),
            wind_direction_deg=self.values.get("wind_direction"),
            total_weight_kg=(
                self.values["total_weight"] * POUNDS_TO_KILOGRAMS
                if "total_weight" in self.values else None
            ),
            left_brake_position=self.values.get("brake_left"),
            right_brake_position=self.values.get("brake_right"),
            autopilot_master=(
                bool(round(self.values["autopilot"]))
                if self.values.get("autopilot") in (0.0, 1.0) else None
            ),
            discontinuity_before=discontinuity,
        )

    def sample(self) -> tuple[FlightSample, dict[str, str | None]] | None:
        """Return a fresh streamed core frame, never a cached prior observation.

        Returns:
            tuple[FlightSample, dict[str, str | None]] | None: New sample and
                identity, or None for absent/unresponsive simulator.
        """
        if not self.handle.value:
            self.last_error = ""
            try:
                self.open()
            except (OSError, AttributeError, ValueError) as exc:
                self._emit("warning", str(exc))
                self.close()
                return None
        self.last_error = ""
        self.latest_core = None
        try:
            self._pump(
                time.monotonic() + FLIGHT_RESPONSE_TIMEOUT_SECONDS,
                stop_on_core=True,
            )
        except (OSError, AttributeError, ValueError) as exc:
            self.pending_discontinuity = "SimConnect reconnect"
            self._emit("warning", str(exc))
            self.close()
            return None
        elapsed = max(0.0, time.monotonic() - self.started_monotonic)
        sample = self._sample_from_core(elapsed)
        if sample is None:
            return None
        return sample, {
            "simulator": self.simulator,
            "simulator_build": self.simulator_build,
            "aircraft": self.aircraft,
            "discontinuity": sample.discontinuity_before,
        }

    def close(self) -> None:
        """Close this owned handle and discard stale frame state.

        Returns:
            None: The handle is closed if open.
        """
        with self._lock:
            try:
                if self.dll is not None and self.handle.value:
                    self.dll.SimConnect_Close(self.handle)
            except (OSError, AttributeError):
                pass
            self.handle = ctypes.c_void_p()
            self.latest_core = None
            self.values.clear()
            self.value_timestamps.clear()
            self.unavailable.clear()
            self.last_rate = None
            self.last_sim_elapsed = None
            self.last_absolute_sim_time = None
            self.last_sample_host_elapsed = None
            self.last_sim_progress_host = None
            self.clock_stall_reported = False
            self.send_ids.clear()

    def stop(self) -> None:
        """Release the worker-owned native handle after capture stops.

        Returns:
            None: Closes this client's SimConnect handle.
        """
        self.close()
