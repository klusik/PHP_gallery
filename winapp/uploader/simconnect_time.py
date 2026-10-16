# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/simconnect_time.py
# Module Type: Windows Tooling
# Purpose: Maintain opt-in simulator UTC while the folder watcher is active.
# Responsibilities:
#   - Read full simulator UTC, guard flight state and verify clock corrections.
#   - Own cancellable connections independently of photo-location acquisition.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Common MSFS 2020/2024 UTC watchdog using the existing native transport."""

import ctypes
from _thread import LockType
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
import math
import os
import threading
import time
from typing import Callable, Optional

from uploader.simconnect_location import (
    SimConnectLocationClient,
    _SimConnectRecv,
    _SimConnectRecvException,
    SIMCONNECT_RECV_ID_EXCEPTION,
    SIMCONNECT_RECV_ID_OPEN,
    SIMCONNECT_RECV_ID_QUIT,
    SIMCONNECT_RECV_ID_SIMOBJECT_DATA,
    simconnect_hresult_failed,
)
from uploader.diagnostics import redact_text

# Type: float; Units: seconds; Scope: UTC worker; Consumers: poll scheduling.
# Rationale: 10 seconds catches a new-flight mismatch without continuous writes.
UTC_CHECK_INTERVAL_SECONDS = 10.0
# Type: float; Units: seconds; Scope: UTC policy; Consumers: compare/readback.
# Rationale: 60 seconds accommodates setters that retain simulator seconds.
UTC_TOLERANCE_SECONDS = 60.0
# Type: float; Units: seconds; Scope: flight state; Consumers: write admission.
# Rationale: 5 seconds rejects temporary SimStart/SimStop pairs during loading.
UTC_SETTLE_SECONDS = 5.0
# Type: float; Units: seconds; Scope: native requests; Consumers: bounded pump.
# Rationale: 2 seconds bounds handshake/data waits without blocking Tk/uploads.
UTC_RESPONSE_SECONDS = 2.0
# Type: float; Units: seconds; Scope: native worker; Consumers: dispatch waits.
# Rationale: 0.05 seconds keeps cancellation prompt without busy polling.
UTC_DISPATCH_SECONDS = 0.05
# Type: float; Units: seconds; Scope: correction; Consumers: fresh readback.
# Rationale: 0.5 seconds lets the simulator apply the queued time events.
UTC_READBACK_SECONDS = 0.5
# Type: float; Units: seconds; Scope: correction; Consumers: retry scheduling.
# Rationale: 30 seconds prevents fighting a build that ignores time setters.
UTC_RETRY_SECONDS = 30.0
# Type: int; Units: attempts; Scope: connected session; Consumers: capability.
# Rationale: 3 failed corrections suspend writes until the next connection.
UTC_MAX_ATTEMPTS = 3
# Type: float; Units: seconds; Scope: shutdown; Consumers: manager join.
# Rationale: 3 seconds exceeds cancellable request waits but bounds shutdown.
UTC_SHUTDOWN_SECONDS = 3.0
# Type: int; Units: sends; Scope: connection; Consumers: exception correlation.
# Rationale: 128 recent operations bound memory while retaining pending sends.
UTC_SEND_HISTORY = 128
# Type: int; Units: packets; Scope: dispatch; Consumers: pending-state drain.
# Rationale: 64 queued packets bound each drain; saturation refuses stale writes.
UTC_DRAIN_PACKETS = 64
# Type: int; Units: characters; Scope: activity; Consumers: failure reporting.
# Rationale: 240 characters retain useful errors without unbounded native text.
UTC_DIAGNOSTIC_CHARACTERS = 240

UTC_EPOCH = datetime(1, 1, 1, tzinfo=timezone.utc)
# SDK wire enum/flag values and per-handle IDs, not adjustable timing policies.
UTC_DEFINITION_ID = 20
UTC_SIM_EVENT = 21
UTC_START_EVENT = 22
UTC_STOP_EVENT = 23
UTC_FLIGHT_EVENT = 24
UTC_PAUSE_EVENT = 25
UTC_FLIGHT_REQUEST = 26
UTC_FIRST_REQUEST = 100
UTC_FIRST_SET_EVENT = 200
RECV_EVENT = 4
RECV_EVENT_FILENAME = 6
RECV_SYSTEM_STATE = 15
EVENT_PRIORITY_HIGHEST = 1
EVENT_FLAG_GROUPID_IS_PRIORITY = 16
# SDK camera states 2..10 and 19 are flight views. Waiting, maps, hangars,
# menu/in-game cinematics and replay are deliberately excluded, on both SDKs.
UTC_FLIGHT_CAMERAS = frozenset((*range(2, 11), 19))
UTC_SET_EVENTS = (
    b"ZULU_YEAR_SET",
    b"ZULU_DAY_SET",
    b"ZULU_HOURS_SET",
    b"ZULU_MINUTES_SET",
)


class _TimeData(ctypes.Structure):
    """Packed SDK data header followed by three untagged FLOAT64 values."""

    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        (name, ctypes.c_uint32) for name in (
            "dwRequestID", "dwObjectID", "dwDefineID", "dwFlags",
            "dwentrynumber", "dwoutof", "dwDefineCount",
        )
    ] + [(name, ctypes.c_double) for name in ("absolute", "rate", "camera")]


class _SystemEvent(ctypes.Structure):
    """SDK event identity and value, including Pause_EX1's pause bitmask."""

    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        (name, ctypes.c_uint32) for name in ("uGroupID", "uEventID", "dwData")
    ]


class _FilenameEvent(ctypes.Structure):
    """Full FlightLoaded event; the SDK filename is an inline MAX_PATH buffer."""

    _pack_ = 1
    _fields_ = _SystemEvent._fields_ + [
        ("szFileName", ctypes.c_char * 260),
        ("dwFlags", ctypes.c_uint32),
    ]


class _SystemState(ctypes.Structure):
    """Initial FlightLoaded state for attaching to an already running flight."""

    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        ("dwRequestID", ctypes.c_uint32),
        ("dwInteger", ctypes.c_uint32),
        ("fFloat", ctypes.c_float),
        ("szString", ctypes.c_char * 260),
    ]


@dataclass(frozen=True)
class UtcSample:
    """One fresh absolute UTC/rate/camera response in a flight-state revision."""

    utc: datetime
    rate: float
    camera: int
    revision: int


def absolute_time_utc(seconds: float) -> datetime:
    """Decode SimConnect's year-1 epoch without using the Unix/local epoch.

    Args:
        seconds (float): Finite seconds since 0001-01-01 00:00:00 UTC.

    Returns:
        datetime: Aware simulator UTC date and time.

    Raises:
        ValueError: Timestamp is nonfinite or outside Python's date range.
    """
    if not math.isfinite(seconds) or seconds < 0:
        raise ValueError("Invalid simulator absolute UTC timestamp.")
    try:
        return UTC_EPOCH + timedelta(seconds=seconds)
    except OverflowError as exc:
        raise ValueError("Simulator UTC timestamp is out of range.") from exc


def utc_event_values(utc: datetime) -> tuple[int, int, int, int]:
    """Build year, ordinal day, hour and minute for the common Zulu events.

    ZULU_DAY_SET takes the one-based ordinal day, unlike the zero-based
    ZULU DAY OF YEAR SimVar. Fresh absolute-time readback verifies the result;
    accepted native return codes alone never establish runtime support.

    Args:
        utc (datetime): Aware target date/time, converted to UTC.

    Returns:
        tuple[int, int, int, int]: Year, day 1..366, hour 0..23, minute 0..59.

    Raises:
        ValueError: The input has no timezone.
    """
    if utc.utcoffset() is None:
        raise ValueError("UTC synchronization requires an aware host clock.")
    target = utc.astimezone(timezone.utc)
    return target.year, target.timetuple().tm_yday, target.hour, target.minute


class SimConnectTimeClient(SimConnectLocationClient):
    """Reuse DLL discovery, native signatures, OPEN and exception transport.

    This separate instance owns its handle on the UTC worker thread only. It
    never invokes coordinate acquisition or shares the per-photo connection.
    Both identified generations use the same documented time events.
    """

    def __init__(
        self,
        dll_path: str,
        cancelled: threading.Event,
        allowed: Callable[[], bool],
        write_lock: LockType,
        monotonic: Callable[[], float] = time.monotonic,
    ) -> None:
        """Initialize an independent, cancellable native clock connection.

        Args:
            dll_path (str): Existing optional DLL override; empty discovers it.
            cancelled (threading.Event): Stop signal shared with the UTC
                worker.
            allowed (Callable[[], bool]): Whether this watcher still admits
                work.
            write_lock (LockType): Serialize cancellation and native writes.
            monotonic (Callable[[], float]): Clock for state/request deadlines.

        Returns:
            None: Initializes state without loading a DLL or connecting.
        """
        super().__init__(dll_path, timeout_seconds=UTC_RESPONSE_SECONDS)
        self.cancelled = cancelled
        self.allowed = allowed
        self.write_lock = write_lock
        self.monotonic = monotonic
        self.dll = None
        self.callback = None
        self.running: Optional[bool] = None
        self.paused: Optional[bool] = None
        self.flight_loaded = False
        self.revision = 0
        self.changed_at = monotonic()
        self.request_id = UTC_FIRST_REQUEST
        self.request_revision = 0
        self.sample: Optional[UtcSample] = None
        self.received = 0
        self.setter_error = ""
        self.capability_error = False
        self.connection_lost = False
        self.phase = "utc"

    def _call(self, name: str, *arguments: int | float | bytes) -> None:
        """Submit one native operation and retain bounded send correlation.

        Args:
            name (str): SimConnect export suffix.
            arguments (int|float|bytes): Ordered SDK IDs, values or ASCII
                names.

        Returns:
            None: Records successful sends for asynchronous exceptions.

        Raises:
            OSError: The native operation reports a failed HRESULT.
        """
        result = getattr(self.dll, "SimConnect_" + name)(
            self.handle, *arguments
        )
        if simconnect_hresult_failed(result):
            self.capability_error = name not in (
                "RequestDataOnSimObject", "TransmitClientEvent"
            )
            raise OSError(f"{name} failed: HRESULT {int(result)}")
        self._sent(self.dll, name)
        while len(self.send_ids) > UTC_SEND_HISTORY:
            del self.send_ids[next(iter(self.send_ids))]

    def open(self) -> None:
        """Connect, identify the simulator and subscribe to guarded state.

        Returns:
            None: Establishes a connection; never changes simulator state.

        Raises:
            OSError: DLL, handshake or required native capability is missing.
        """
        if os.name != "nt":
            raise OSError("UTC synchronization requires Windows.")
        path, _tried = self.resolve_dll_path()
        if path is None:
            raise OSError("SimConnect.dll unavailable; awaiting simulator.")
        self.dll = ctypes.WinDLL(str(path))
        callback_type = getattr(ctypes, "WINFUNCTYPE", ctypes.CFUNCTYPE)(
            None, ctypes.POINTER(_SimConnectRecv), ctypes.c_uint32,
            ctypes.c_void_p,
        )
        self.configure_functions(self.dll, callback_type)
        signatures = {
            "SubscribeToSystemEvent": [ctypes.c_uint32, ctypes.c_char_p],
            "RequestSystemState": [ctypes.c_uint32, ctypes.c_char_p],
            "MapClientEventToSimEvent": [ctypes.c_uint32, ctypes.c_char_p],
            "TransmitClientEvent": [ctypes.c_uint32] * 5,
        }
        for name, parameters in signatures.items():
            function = getattr(self.dll, "SimConnect_" + name)
            function.argtypes = [ctypes.c_void_p] + parameters
            function.restype = ctypes.c_long
        self.callback = callback_type(self._dispatch_safe)
        result = self.dll.SimConnect_Open(
            ctypes.byref(self.handle), b"PHP Gallery UTC watchdog",
            None, 0, None, 0,
        )
        if simconnect_hresult_failed(result) or not self.handle.value:
            raise OSError("SimConnect disconnected; awaiting simulator.")
        self.pump(UTC_RESPONSE_SECONDS, lambda: self.open_received)
        if not self.open_received:
            raise OSError("Simulator identity handshake unavailable.")
        for event_id, name in (
            (UTC_SIM_EVENT, b"Sim"),
            (UTC_START_EVENT, b"SimStart"),
            (UTC_STOP_EVENT, b"SimStop"),
            (UTC_FLIGHT_EVENT, b"FlightLoaded"),
            (UTC_PAUSE_EVENT, b"Pause_EX1"),
        ):
            self._call("SubscribeToSystemEvent", event_id, name)
        self._call("RequestSystemState", UTC_FLIGHT_REQUEST, b"FlightLoaded")
        for variable, units in (
            (b"ABSOLUTE TIME", b"seconds"),
            (b"SIMULATION RATE", b"number"),
            (b"CAMERA STATE", b"enum"),
        ):
            # FLOAT64=4, epsilon=0 and UNUSED datum ID are SDK wire literals.
            self._call(
                "AddToDataDefinition", UTC_DEFINITION_ID, variable, units,
                4, 0.0, 0xFFFFFFFF,
            )
        for index, name in enumerate(UTC_SET_EVENTS):
            self._call(
                "MapClientEventToSimEvent", UTC_FIRST_SET_EVENT + index, name
            )

    def pump(
        self, seconds: float, complete: Optional[Callable[[], bool]] = None
    ) -> None:
        """Dispatch on the owning thread with bounded, interruptible waits.

        Args:
            seconds (float): Maximum monotonic response budget in seconds.
            complete (Optional[Callable[[], bool]]): Optional completion
                predicate.

        Returns:
            None: Stops on completion, cancellation or deadline.

        Raises:
            OSError: Dispatch, packet validation or server failure occurs.
        """
        deadline = self.monotonic() + seconds
        while not self.cancelled.is_set() and self.allowed():
            # Drain queued transitions before admitting any write. A single
            # CallDispatch may deliver only one of several waiting events.
            for _index in range(UTC_DRAIN_PACKETS):
                previous = self.received
                result = self.dll.SimConnect_CallDispatch(
                    self.handle, self.callback, None
                )
                if simconnect_hresult_failed(result):
                    self.connection_lost = True
                    raise OSError("SimConnect dispatch failed.")
                if self.error_message:
                    raise OSError(self.error_message)
                if self.received == previous:
                    break
            else:
                raise OSError("UTC state queue did not settle.")
            if (complete is not None and complete()) or seconds == 0:
                return
            remaining = deadline - self.monotonic()
            if remaining <= 0:
                return
            self.cancelled.wait(min(UTC_DISPATCH_SECONDS, remaining))

    def dispatch(
        self,
        data: ctypes.POINTER(_SimConnectRecv),
        size: int,
        _context: ctypes.c_void_p,
    ) -> None:
        """Validate native payloads and reject stale request/state revisions.

        Args:
            data (ctypes.POINTER(_SimConnectRecv)): Native common-header
                pointer.
            size (int): Available callback bytes, independently size-checked.
            _context (ctypes.c_void_p): Unused native callback context.

        Returns:
            None: Updates only this connection's state or pending sample.
        """
        if not data or size < ctypes.sizeof(_SimConnectRecv):
            self.error_message = "Truncated UTC receive header."
            return
        header = data.contents
        self.received += 1
        if not ctypes.sizeof(_SimConnectRecv) <= header.dwSize <= size:
            self.error_message = "Invalid UTC receive size."
            return
        if header.dwID in (
            SIMCONNECT_RECV_ID_OPEN, SIMCONNECT_RECV_ID_QUIT,
            SIMCONNECT_RECV_ID_EXCEPTION,
        ):
            # The existing transport owns identity, safe OPEN/EXCEPTION sizes,
            # send-ID attribution and disconnection; no camera API is invoked.
            super().dispatch(data, size, _context)
            if header.dwID == SIMCONNECT_RECV_ID_QUIT:
                self.connection_lost = True
            if (
                header.dwID == SIMCONNECT_RECV_ID_EXCEPTION
                and header.dwSize >= ctypes.sizeof(_SimConnectRecvException)
            ):
                packet = ctypes.cast(
                    data, ctypes.POINTER(_SimConnectRecvException)
                ).contents
                if self.send_ids.get(packet.dwSendID) == "TransmitClientEvent":
                    self.setter_error = self.error_message
                    self.error_message = ""
                else:
                    self.capability_error = self.send_ids.get(
                        packet.dwSendID
                    ) in (
                        "SubscribeToSystemEvent", "RequestSystemState",
                        "AddToDataDefinition", "MapClientEventToSimEvent",
                    )
            return
        packet_type = {
            RECV_EVENT: _SystemEvent,
            RECV_EVENT_FILENAME: _FilenameEvent,
            RECV_SYSTEM_STATE: _SystemState,
            SIMCONNECT_RECV_ID_SIMOBJECT_DATA: _TimeData,
        }.get(int(header.dwID))
        if packet_type is None:
            return
        if header.dwSize < ctypes.sizeof(packet_type):
            self.error_message = "Truncated UTC response payload."
            return
        packet = ctypes.cast(data, ctypes.POINTER(packet_type)).contents
        changed = False
        if header.dwID == RECV_EVENT:
            if packet.uEventID in (UTC_SIM_EVENT, UTC_START_EVENT, UTC_STOP_EVENT):
                running = (
                    bool(packet.dwData) if packet.uEventID == UTC_SIM_EVENT
                    else packet.uEventID == UTC_START_EVENT
                )
                changed = (
                    running != self.running or packet.uEventID != UTC_SIM_EVENT
                )
                self.running = running
            elif packet.uEventID == UTC_PAUSE_EVENT:
                changed = bool(packet.dwData) != self.paused
                self.paused = bool(packet.dwData)
        elif header.dwID == RECV_EVENT_FILENAME:
            if packet.uEventID == UTC_FLIGHT_EVENT:
                self.flight_loaded = bool(packet.szFileName)
                changed = True
        elif header.dwID == RECV_SYSTEM_STATE:
            if packet.dwRequestID == UTC_FLIGHT_REQUEST:
                self.flight_loaded = bool(packet.szString)
                changed = True
        elif header.dwID == SIMCONNECT_RECV_ID_SIMOBJECT_DATA:
            if (
                packet.dwRequestID != self.request_id
                or packet.dwDefineID != UTC_DEFINITION_ID
                or self.request_revision != self.revision
            ):
                return
            if (
                packet.dwFlags != 0 or packet.dwDefineCount != 3
                or packet.dwentrynumber != 1 or packet.dwoutof != 1
            ):
                self.error_message = "Invalid UTC data response shape."
                return
            if (
                not math.isfinite(packet.rate)
                or not math.isfinite(packet.camera)
                or not packet.camera.is_integer()
            ):
                self.error_message = "Invalid UTC flight-state values."
                return
            self.sample = UtcSample(
                absolute_time_utc(packet.absolute), packet.rate,
                int(packet.camera), self.revision,
            )
        if changed:
            self.revision += 1
            self.changed_at = self.monotonic()
            self.sample = None

    def read(self) -> Optional[UtcSample]:
        """Request a new one-shot clock sample, never a cached prior response.

        Returns:
            Optional[UtcSample]: Fresh sample, or None on transition/timeout.

        Raises:
            OSError: A native/protocol failure invalidates the connection.
        """
        self.sample = None
        self.request_id += 1
        self.request_revision = self.revision
        self._call(
            "RequestDataOnSimObject", self.request_id, UTC_DEFINITION_ID,
            0, 1, 0, 0, 0, 0,
        )
        self.pump(UTC_RESPONSE_SECONDS, lambda: self.sample is not None)
        return self.sample

    def suspension(self, sample: UtcSample) -> str:
        """Explain why a sample cannot authorize a simulator write.

        Args:
            sample (UtcSample): Fresh clock/rate/camera and captured revision.

        Returns:
            str: Empty when safe, otherwise a concise nonfatal reason.
        """
        if self.cancelled.is_set() or not self.allowed():
            return "stopped"
        if self.running is not True or not self.flight_loaded:
            return "awaiting loaded flight"
        if self.paused is not False:
            return "paused or pause state unavailable"
        if sample.camera not in UTC_FLIGHT_CAMERAS:
            return "menu, loading or non-flight view"
        if sample.rate != 1.0:
            return f"simulation rate is {sample.rate:g}x"
        if (
            sample.revision != self.revision
            or self.monotonic() - self.changed_at < UTC_SETTLE_SECONDS
        ):
            return "flight state settling"
        return ""

    def correct(self, sample: UtcSample, target: datetime) -> bool:
        """Send the full UTC date/time only while the sampled flight stays safe.

        Args:
            sample (UtcSample): Pre-write state whose revision must stay
                current.
            target (datetime): Aware host UTC date/time for Zulu event values.

        Returns:
            bool: True if all events were sent; False if admission changed.

        Raises:
            OSError: A send/dispatch or correlated exception fails.
        """
        for index, value in enumerate(utc_event_values(target)):
            self.pump(0)
            if self.setter_error:
                raise OSError(self.setter_error)
            with self.write_lock:
                if self.suspension(sample):
                    return False
                self._call(
                    "TransmitClientEvent", 0, UTC_FIRST_SET_EVENT + index,
                    value, EVENT_PRIORITY_HIGHEST,
                    EVENT_FLAG_GROUPID_IS_PRIORITY,
                )
        return True

    def close(self) -> None:
        """Release this worker's handle even after cancellation or soft failure.

        Returns:
            None: Closes only this instance's native connection.
        """
        try:
            if self.dll is not None and self.handle.value:
                self.dll.SimConnect_Close(self.handle)
        finally:
            self.handle = ctypes.c_void_p()


class UtcSyncWorker(threading.Thread):
    """Cancellable UTC policy loop; native calls stay on this thread only."""

    def __init__(
        self,
        dll_path: str,
        allowed: Callable[[], bool],
        emit: Callable[[str, str], None],
        utc_now: Callable[[], datetime] = lambda: datetime.now(timezone.utc),
        monotonic: Callable[[], float] = time.monotonic,
    ) -> None:
        """Capture watcher admission and injectable UTC/monotonic clocks.

        Args:
            dll_path (str): Saved/active watcher DLL discovery override.
            allowed (Callable[[], bool]): Active, non-stopping watcher
                predicate.
            emit (Callable[[str, str], None]): Level/message activity queue
                sink.
            utc_now (Callable[[], datetime]): Aware Windows system UTC clock.
            monotonic (Callable[[], float]): Scheduling and backoff clock.

        Returns:
            None: Initializes an unstarted daemon and cancellation barrier.
        """
        super().__init__(daemon=True, name="SimConnect UTC sync")
        self.cancelled = threading.Event()
        self.write_lock = threading.Lock()
        self.dll_path = dll_path
        self.allowed = allowed
        self.emit = emit
        self.utc_now = utc_now
        self.monotonic = monotonic
        self.status = "awaiting simulator"
        self.simulator = "not connected"
        self.failures = 0
        self.retry_at = 0.0
        self.unsupported = False

    def stop(self) -> None:
        """Revoke write admission before returning, including draining workers.

        Returns:
            None: Signals cancellation without joining or calling native APIs.
        """
        with self.write_lock:
            self.cancelled.set()

    def report(self, message: str, level: str = "info") -> None:
        """Publish state changes only, suppressing cancelled worker callbacks.

        Args:
            message (str): Concise status without credentials or local paths.
            level (str): Existing activity level, normally info or warning.

        Returns:
            None: Emits at most once for an unchanged state.
        """
        if message != self.status and not self.cancelled.is_set():
            self.status = message
            self.emit(level, "[simconnect][time] " + message)

    def check(self, client: SimConnectTimeClient) -> None:
        """Compare UTC and verify corrections with a separately requested sample.

        Args:
            client (SimConnectTimeClient): Connected provider owned by this
                thread.

        Returns:
            None: Applies at most one bounded correction attempt per check.

        Raises:
            OSError: Connection or clock-read failure requires reconnecting.
        """
        if self.unsupported:
            return
        if client.setter_error:
            self.correction_failed(client.setter_error)
            client.setter_error = ""
            return
        sample = client.read()
        if sample is None:
            self.report("awaiting fresh simulator UTC")
            return
        reason = client.suspension(sample)
        if reason:
            self.report("UTC sync suspended: " + reason)
            return
        target = self.utc_now()
        utc_event_values(target)
        delta = (sample.utc - target).total_seconds()
        if abs(delta) <= UTC_TOLERANCE_SECONDS:
            self.failures = 0
            self.report("UTC aligned; monitoring every 10 s")
            return
        if self.monotonic() < self.retry_at:
            return
        try:
            if not client.correct(sample, target):
                self.report("UTC sync suspended: flight state changed")
                return
            client.pump(UTC_READBACK_SECONDS)
            readback = client.read()
            if client.setter_error:
                raise OSError(client.setter_error)
            if (
                (readback is not None and client.suspension(readback))
                or (readback is None and client.revision != sample.revision)
            ):
                self.report("UTC readback deferred: flight state changed")
                return
            verified = (
                (readback.utc - self.utc_now()).total_seconds()
                if readback is not None else math.inf
            )
            if abs(verified) <= UTC_TOLERANCE_SECONDS:
                self.failures = 0
                self.report(
                    f"UTC correction verified; delta={verified:+.0f} s "
                    f"(was {delta:+.0f} s); "
                    f"target={target.isoformat(timespec='minutes')}"
                )
                return
            failure = "time setters did not reach UTC tolerance"
        except OSError as exc:
            # An exception from a time setter is a capability failure for this
            # connection. A QUIT/dispatch failure still reconnects below.
            if client.error_message:
                raise
            failure = str(exc)
            client.setter_error = ""
        self.correction_failed(failure)

    def correction_failed(self, failure: str) -> None:
        """Bound retries for an ignored/rejected setter on this connection.

        Args:
            failure (str): Native exception or readback failure reason.

        Returns:
            None: Schedules backoff or suspends this connection's capability.
        """
        self.failures += 1
        self.retry_at = self.monotonic() + UTC_RETRY_SECONDS
        self.unsupported = self.failures >= UTC_MAX_ATTEMPTS
        if self.unsupported:
            self.report(
                "UTC sync unsupported for this connection: " + failure,
                "warning",
            )
        else:
            self.report(
                f"UTC correction unverified ({self.failures}/"
                f"{UTC_MAX_ATTEMPTS}); retry in 30 s: {failure}", "warning",
            )

    def run(self) -> None:
        """Reconnect softly and keep screenshot processing independent.

        Returns:
            None: Exits after cancellation/watcher stop and closes its handle.
        """
        while not self.cancelled.is_set() and self.allowed():
            client = SimConnectTimeClient(
                self.dll_path, self.cancelled, self.allowed, self.write_lock,
                self.monotonic,
            )
            ready = False
            try:
                client.open()
                ready = True
                self.simulator = (
                    f"{client.simulator_info.display_name} "
                    f"({client.simulator_info.app_version})"
                )
                self.failures = 0
                self.retry_at = 0.0
                self.unsupported = client.simulator_info.generation not in (
                    2020, 2024
                )
                self.report(
                    "UTC sync unsupported: unidentified simulator" if
                    self.unsupported else "UTC sync connected: " + self.simulator
                )
                next_check = self.monotonic()
                while not self.cancelled.is_set() and self.allowed():
                    client.pump(UTC_DISPATCH_SECONDS)
                    if self.monotonic() >= next_check:
                        next_check = self.monotonic() + UTC_CHECK_INTERVAL_SECONDS
                        self.check(client)
            except (OSError, ValueError, AttributeError) as exc:
                # Do not expose DLL/local paths through routine activity status.
                reason = redact_text(str(exc), redact_paths=True)
                reason = reason[:UTC_DIAGNOSTIC_CHARACTERS]
                if (
                    client.open_received and not client.connection_lost
                    and (client.capability_error or not ready)
                    and not self.cancelled.is_set()
                ):
                    self.report(
                        "UTC sync unsupported for this connection: " + reason,
                        "warning",
                    )
                    # A setup/capability rejection is scoped to this handle.
                    # Stay subscribed for QUIT, then probe a new connection.
                    client.error_message = ""
                    while not self.cancelled.is_set() and self.allowed():
                        try:
                            client.pump(UTC_DISPATCH_SECONDS)
                        except OSError:
                            if client.connection_lost:
                                break
                            client.error_message = ""
                            self.cancelled.wait(UTC_DISPATCH_SECONDS)
                else:
                    self.report("UTC sync awaiting simulator: " + reason)
            finally:
                try:
                    client.close()
                except OSError:
                    self.report("UTC connection cleanup failed", "warning")
            self.cancelled.wait(UTC_CHECK_INTERVAL_SECONDS)


class UtcSyncManager:
    """Tk-owned worker lifecycle retaining cancelled workers until they drain."""

    def __init__(self, emit: Callable[[str, str], None]) -> None:
        """Attach the existing thread-safe activity receiver.

        Args:
            emit (Callable[[str, str], None]): Level/message activity queue
                sink.

        Returns:
            None: Initializes an idle manager with no native work.
        """
        self.emit = emit
        self.workers: list[UtcSyncWorker] = []
        self.current: Optional[UtcSyncWorker] = None

    def start(self, dll_path: str, allowed: Callable[[], bool]) -> None:
        """Start once; rapid toggles never revive a cancelled connection.

        Args:
            dll_path (str): Active watcher's saved DLL override.
            allowed (Callable[[], bool]): Active watcher admission predicate.

        Returns:
            None: Starts a fresh worker only when an active one is absent.
        """
        if self.current is not None and self.current.is_alive():
            return
        self.workers = [worker for worker in self.workers if worker.is_alive()]
        self.current = UtcSyncWorker(dll_path, allowed, self.emit)
        self.workers.append(self.current)
        self.current.start()

    def stop(self) -> None:
        """Cancel every retained worker without blocking the Tk event loop.

        Returns:
            None: Stops admission and clears the displayed active worker.
        """
        for worker in self.workers:
            worker.stop()
        self.current = None

    def is_alive(self) -> bool:
        """Include draining workers in shutdown and installer admission.

        Returns:
            bool: True while any retained UTC worker has not exited.
        """
        return any(worker.is_alive() for worker in self.workers)

    def join(self, timeout: float = UTC_SHUTDOWN_SECONDS) -> None:
        """Wait within one shared budget for all cancelled workers.

        Args:
            timeout (float): Total allowed shutdown wait in seconds.

        Returns:
            None: Returns when drained or the shared deadline expires.
        """
        deadline = time.monotonic() + timeout
        for worker in self.workers:
            worker.join(timeout=max(0.0, deadline - time.monotonic()))

    def status(self) -> str:
        """Expose the current worker only; stale workers cannot overwrite UI.

        Returns:
            str: Current UTC status, or stopped when no worker is admitted.
        """
        if self.current is None or not self.current.is_alive():
            return "UTC sync stopped"
        return self.current.status
