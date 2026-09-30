# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Read simulator location through a shared SimConnect transport.
# Responsibilities:
#   - Own simulator identity, camera/aircraft strategies, native dispatch and bounded diagnostics.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/uploader/simconnect_location.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
#
# Notes:
#   - Keep comments and docstrings intact when modifying this file.
"""
Acquire optional screenshot coordinates through one bounded SimConnect transport.

OPEN metadata selects MSFS 2020 aircraft position or MSFS 2024 camera world
position with aircraft fallback. Unknown generations use available camera
exports as a bounded capability probe. All providers share one connection,
dispatch loop and response-wait deadline; failures remain optional metadata
failures rather than upload errors. Native calls belong to the watcher thread.
"""
import ctypes
import logging
import math
import os
from pathlib import Path
import sys
import threading
import time
from dataclasses import dataclass
from typing import Any, Callable, Dict, List, Optional, Tuple

SIMCONNECT_CAMERA_QUERY_TIMEOUT_SECONDS = 1.0
SIMCONNECT_POSITION_REFERENTIAL_WORLD = 2
SIMCONNECT_DLL_ENV_VAR = "SIMCONNECT_DLL"
SIMCONNECT_CLIENT_ID = b"PHPGalleryUploader"
SIMCONNECT_RECV_ID_EXCEPTION = 1
SIMCONNECT_RECV_ID_OPEN = 2
SIMCONNECT_RECV_ID_QUIT = 3
SIMCONNECT_RECV_ID_SIMOBJECT_DATA = 8
SIMCONNECT_RECV_ID_CAMERA_DATA = 40
SIMCONNECT_RECV_ID_CAMERA_STATUS = 41
SIMCONNECT_CAMERA_AVAILABILITY_LABELS = {0: "not acquired", 1: "acquired", 2: "acquired by another client", 3: "user disabled"}
SIMCONNECT_REQUEST_ID = 1
SIMCONNECT_DEFINITION_ID = 1
APP_DIR = Path(__file__).resolve().parent.parent
SIMCONNECT_COMMON_DLL_PATHS = [Path.home() / name / "SimConnect SDK" / "lib" / "SimConnect.dll" for name in ("MSFS 2024 SDK", "MSFS SDK", "AppData/Local/Programs/MSFS 2024 SDK", "AppData/Local/Programs/MSFS SDK")]

@dataclass
class SimCameraLocation:
    """
    Flight Simulator camera world position captured through SimConnect.

    @param latitude: Camera latitude in degrees.
    @param longitude: Camera longitude in degrees.
    @param altitude: Camera altitude in feet.
    @param source: Actual provider; aircraft results share the historical DTO.
    """

    latitude: float
    longitude: float
    altitude: float
    source: str = "camera_world_position"

    def upload_fields(self) -> Dict[str, str]:
        """
        Convert the camera position into upload automation metadata fields.

        @return Dict[str, str] Multipart form fields accepted by PHP Gallery.
        """
        # Existing servers accept only this historical source value. Actual
        # camera/aircraft provenance stays in local results and diagnostic logs.
        return {
            "sim_location_source": "simconnect_camera",
            "sim_camera_latitude": f"{self.latitude:.7f}",
            "sim_camera_longitude": f"{self.longitude:.7f}",
            "sim_camera_altitude": f"{self.altitude:.2f}",
        }


class _SimConnectRecv(ctypes.Structure):
    """Common DWORD header; validate its size before reading any native payload."""
    _fields_ = [
        ("dwSize", ctypes.c_uint32),
        ("dwVersion", ctypes.c_uint32),
        ("dwID", ctypes.c_uint32),
    ]


class _SimConnectDataXYZ(ctypes.Structure):
    """SDK double-precision coordinates; WORLD uses latitude/longitude/altitude."""
    _fields_ = [
        ("x", ctypes.c_double),
        ("y", ctypes.c_double),
        ("z", ctypes.c_double),
    ]


class _SimConnectDataPBH(ctypes.Structure):
    """SDK camera pitch/bank/heading floats, preserved for camera ABI alignment."""
    _fields_ = [
        ("Pitch", ctypes.c_float),
        ("Bank", ctypes.c_float),
        ("Heading", ctypes.c_float),
    ]


class _SimConnectDataCamera(ctypes.Structure):
    """Existing packed Camera API payload; preserve field types and ordering."""
    _pack_ = 1
    _fields_ = [
        ("Position", _SimConnectDataXYZ),
        ("PositionReferential", ctypes.c_uint32),
        ("PositionReferentialObjectId", ctypes.c_uint32),
        ("TargetedPos", _SimConnectDataXYZ),
        ("Pbh", _SimConnectDataPBH),
        ("RotationReferential", ctypes.c_uint32),
        ("RotationReferentialObjectId", ctypes.c_uint32),
        ("Fov", ctypes.c_double),
    ]


class _SimConnectRecvCameraData(ctypes.Structure):
    """Packed camera response with no request ID; accept only during camera phase."""
    _pack_ = 1
    _fields_ = [
        ("dwSize", ctypes.c_uint32),
        ("dwVersion", ctypes.c_uint32),
        ("dwID", ctypes.c_uint32),
        ("CameraData", _SimConnectDataCamera),
    ]


class _SimConnectRecvException(ctypes.Structure):
    """Server failure whose send ID identifies the originating native operation."""
    _pack_ = 1
    _fields_ = [
        ("dwSize", ctypes.c_uint32),
        ("dwVersion", ctypes.c_uint32),
        ("dwID", ctypes.c_uint32),
        ("dwException", ctypes.c_uint32),
        ("dwSendID", ctypes.c_uint32),
        ("dwIndex", ctypes.c_uint32),
    ]


class _SimConnectRecvCameraStatus(ctypes.Structure):
    """Camera ownership/availability report, retained for troubleshooting."""
    _pack_ = 1
    _fields_ = [
        ("dwSize", ctypes.c_uint32),
        ("dwVersion", ctypes.c_uint32),
        ("dwID", ctypes.c_uint32),
        ("acquiredState", ctypes.c_uint32),
        ("bGameControlled", ctypes.c_int32),
    ]


def simconnect_hresult_failed(value: int) -> bool:
    """
    Return whether a signed HRESULT indicates failure.

    @param int value: HRESULT returned by SimConnect.
    @return bool True when the HRESULT is a failure code.
    """
    return int(value) < 0


def simconnect_camera_position_valid(location: SimCameraLocation) -> bool:
    """
    Validate a world camera position before sending it to PHP Gallery.

    @param SimCameraLocation location: Candidate camera position.
    @return bool True when latitude, longitude, and altitude are usable.
    """
    return (
        math.isfinite(location.latitude)
        and math.isfinite(location.longitude)
        and math.isfinite(location.altitude)
        and -90.0 <= location.latitude <= 90.0
        and -180.0 <= location.longitude <= 180.0
    )




class _SimConnectRecvOpen(ctypes.Structure):
    """Packed OPEN handshake containing server identity and two four-part versions."""
    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        ("szApplicationName", ctypes.c_char * 256),
    ] + [(name, ctypes.c_uint32) for name in (
        "dwApplicationVersionMajor", "dwApplicationVersionMinor",
        "dwApplicationBuildMajor", "dwApplicationBuildMinor",
        "dwSimConnectVersionMajor", "dwSimConnectVersionMinor",
        "dwSimConnectBuildMajor", "dwSimConnectBuildMinor",
        "dwReserved1", "dwReserved2",
    )]


class _SimConnectRecvSimobjectData(ctypes.Structure):
    """
    Aircraft response for our three untagged FLOAT64 data definitions.

    The seven DWORD metadata fields follow the common header. Payload doubles
    start at byte 40, without host-alignment padding: latitude/longitude in
    degrees, altitude in feet, in exactly the order registered with SimConnect.
    """
    _pack_ = 1
    _fields_ = _SimConnectRecv._fields_ + [
        (name, ctypes.c_uint32) for name in (
            "dwRequestID", "dwObjectID", "dwDefineID", "dwFlags",
            "dwentrynumber", "dwoutof", "dwDefineCount",
        )
    ] + [(name, ctypes.c_double) for name in ("latitude", "longitude", "altitude")]


@dataclass
class SimulatorInfo:
    """Server identity reported by the SimConnect OPEN handshake.

    app_version is the API/server version, not the retail launcher version.
    KittyHawk and SunRise are the simulator's actual OPEN server names.
    """
    app_name: str = "unknown"
    app_version: str = "unknown"
    simconnect_version: str = "unknown"
    family: str = "unknown"
    generation: Optional[int] = None

    @property
    def display_name(self) -> str:
        """Show the product generation while retaining raw server identity separately."""
        if self.family == "MSFS":
            suffix = str(self.generation) if self.generation is not None else "generation unknown"
            return "Microsoft Flight Simulator " + suffix
        return self.app_name

    @classmethod
    def from_open(cls, packet: _SimConnectRecvOpen) -> "SimulatorInfo":
        """
        Identify the connected simulator from its OPEN response, not local files.

        MSFS normally reports internal server names: KittyHawk for 2020 and
        SunRise for 2024. Retail Microsoft Flight Simulator/MSFS names are also
        recognized. Only an identified MSFS family can receive a generation;
        another simulator with the same major version remains unknown.

        Application major 11 identifies 2020 and major 12 identifies 2024.
        A known server name or explicit product year can supply the generation
        when the numeric version is unfamiliar, but contradictory known values
        are never resolved by guessing. Future major versions/product years
        also remain unknown, allowing capability detection and aircraft fallback.

        Both version strings retain all four server-reported components. The
        application version is an internal API/server version and may differ
        from the retail simulator version displayed to the user.

        @param _SimConnectRecvOpen packet: Size-validated OPEN packet from this connection.
        @return SimulatorInfo Raw identity/versions, family and optional generation.
        """
        name = bytes(packet.szApplicationName).split(b"\0", 1)[0].decode("utf-8", "replace")[:256]
        identity = name.casefold()
        # Names and numeric versions are independent pieces of server evidence.
        server_generation = {"kittyhawk": 2020, "sunrise": 2024}.get(identity)
        is_msfs = server_generation is not None or "microsoft flight simulator" in identity or identity.startswith("msfs")
        explicit = [year for year in (2020, 2024) if str(year) in identity]
        if server_generation is not None:
            explicit.append(server_generation)
        major = int(packet.dwApplicationVersionMajor)
        inferred = {11: 2020, 12: 2024}.get(major)
        generation = None
        if not explicit:
            generation = inferred
        elif len(explicit) == 1 and (inferred is None or explicit[0] == inferred):
            generation = explicit[0]
        # Unknown future product names must not be mistaken for a supported generation.
        if major > 12 or any(str(year) in identity for year in range(2025, 2100)):
            generation = None
        version_parts = ("VersionMajor", "VersionMinor", "BuildMajor", "BuildMinor")
        return cls(
            app_name=name,
            app_version=".".join(str(getattr(packet, "dwApplication" + part)) for part in version_parts),
            simconnect_version=".".join(str(getattr(packet, "dwSimConnect" + part)) for part in version_parts),
            family="MSFS" if is_msfs else "unknown",
            generation=generation if is_msfs else None,
        )


class SimConnectLocationClient:
    """Minimal SimConnect reader used by watched-folder uploads.

    The client opens a short-lived SimConnect connection, requests the current
    camera in world referential coordinates, then closes the connection. Missing
    simulator, missing DLL, or camera API failures are reported as soft failures
    so uploads can continue without location metadata. Aircraft position supplies
    MSFS 2020 location and the bounded camera fallback.
    """

    def __init__(self, dll_path: str = "", timeout_seconds: float = SIMCONNECT_CAMERA_QUERY_TIMEOUT_SECONDS, app_dir: Optional[Path] = None, event_sink: Optional[Callable[[str, str], None]] = None) -> None:
        """
        Create a camera client with aircraft-location support.

        @param str dll_path: Optional explicit SimConnect.dll path selected by the user.
        @param float timeout_seconds: Maximum response-wait budget shared by all providers.
        @param Optional[Path] app_dir: Application root for source/bundled DLL discovery.
        @param Optional[Callable] event_sink: Thread-safe diagnostic event receiver.
        """
        self.configured_dll_path = Path(dll_path.strip().strip('"')) if dll_path.strip().strip('"') else None
        self.timeout_seconds = max(0.2, float(timeout_seconds))
        self.app_dir = Path(app_dir) if app_dir is not None else APP_DIR
        self.event_sink = event_sink
        self._lock = threading.RLock()
        self._reset()

    def _reset(self) -> None:
        """
        Clear connection, coordinates and failure state before each acquisition.

        Called under the client lock (or during construction). Never carry a
        previous photo's coordinates/send IDs into a later connection.
        """
        self.handle = ctypes.c_void_p()
        self.location: Optional[SimCameraLocation] = None
        self.simulator_info = SimulatorInfo()
        self.error_message = ""
        self.camera_error = ""
        self.aircraft_error = ""
        self.status_message = ""
        self.dll_message = ""
        self.diagnostics: List[str] = []
        self.dispatch_count = 0
        self.last_recv_id: Optional[int] = None
        self.camera_data_packets = 0
        self.phase = "handshake"
        self.send_ids: Dict[int, str] = {}
        self.camera_available: Optional[bool] = None
        self.camera_exports = False
        self.connection_state = "not connected"
        self.dll_path = ""
        self.dll_loadable = False
        self.provider = "none"
        self.open_received = False
        self._camera_acquired = False
        self.attempted = False
        self.last_connection_success = False
        self.dll_architecture = "unknown"

    def _emit(self, level: str, message: str) -> None:
        """
        Deliver a concise SimConnect event through the watcher's logging queue.

        Without a sink, use ordinary logging. A failing diagnostic consumer must
        not interrupt native cleanup, location fallback or the photo upload.
        """
        text = "[simconnect] " + message
        if self.event_sink is not None:
            try:
                self.event_sink(level, text)
            except Exception:
                logging.debug("SimConnect diagnostic sink failed", exc_info=True)
        else:
            getattr(logging, level)(text)

    def resolve_dll_path(self) -> Tuple[Optional[Path], List[Path]]:
        """
        Find a usable SimConnect client DLL on the local machine.

        @return Tuple[Optional[Path], List[Path]] Tuple of the selected DLL path and every absolute candidate checked.
        """
        tried: List[Path] = []
        roots = [self.app_dir]
        if getattr(sys, "_MEIPASS", None):
            roots.insert(0, Path(sys._MEIPASS))
        env_path = os.environ.get(SIMCONNECT_DLL_ENV_VAR, "").strip().strip('"')
        candidates = [self.configured_dll_path, Path(env_path) if env_path else None]
        candidates += [root / "runtime" / "simconnect" / "SimConnect.dll" for root in roots]
        candidates += [root / "SimConnect.dll" for root in roots]
        if getattr(sys, "frozen", False):
            candidates.append(Path(sys.executable).resolve().parent / "SimConnect.dll")
        candidates += [self.app_dir.parent / "SimConnect.dll", Path.cwd() / "SimConnect.dll"] + SIMCONNECT_COMMON_DLL_PATHS
        for candidate in candidates:
            if candidate is None:
                continue
            resolved = candidate.resolve(strict=False)
            if resolved in tried:
                continue
            tried.append(resolved)
            if resolved.is_file():
                return resolved, tried
        return None, tried

    @staticmethod
    def _pe_architecture(path: Path) -> str:
        """Read the bounded PE header to distinguish DLL and process architecture."""
        try:
            with path.open("rb") as stream:
                header = stream.read(64)
                if len(header) != 64 or header[:2] != b"MZ":
                    return "unknown"
                offset = int.from_bytes(header[60:64], "little")
                if offset > 1024 * 1024:
                    return "unknown"
                stream.seek(offset)
                pe = stream.read(6)
                if pe[:4] != b"PE\0\0":
                    return "unknown"
                return {0x8664: "x64", 0x14c: "x86", 0xaa64: "arm64"}.get(int.from_bytes(pe[4:6], "little"), "unknown")
        except OSError:
            return "unknown"

    def dll_resolution_message(self) -> str:
        """
        Describe which SimConnect DLL path would be used without opening the sim.

        @return str Human-readable DLL resolution summary.
        """
        path, tried = self.resolve_dll_path()
        return f"Using SimConnect.dll: {path}" if path else "No SimConnect.dll found. Tried: " + ", ".join(map(str, tried))

    def diagnostics_snapshot(self) -> Dict[str, Any]:
        """
        Copy JSON-safe state of the last acquisition without probing the server.

        The client lock prevents a partial snapshot. The watcher publishes this
        completed copy for UI use, so copying diagnostics cannot block behind a
        new acquisition. Closed transport and last successful handshake are
        reported separately. Reasons and native-call history are bounded.

        @return Dict[str, Any] Runtime, identity, provider, provenance and failure data.
        """
        with self._lock:
            return {
                "dll_path": self.dll_path,
                "dll_architecture": self.dll_architecture,
                "process_architecture": "x64" if ctypes.sizeof(ctypes.c_void_p) == 8 else "x86",
                "dll_loadable": self.dll_loadable,
                "connection": self.connection_state,
                "simulator": self.simulator_info.display_name,
                "server_name": self.simulator_info.app_name,
                "family": self.simulator_info.family,
                "generation": self.simulator_info.generation,
                "simulator_version": self.simulator_info.app_version,
                "simconnect_version": self.simulator_info.simconnect_version,
                "provider": self.provider,
                "camera_exports": self.camera_exports,
                "camera_api_available": self.camera_available,
                "last_result": "not_requested" if not self.attempted else "success" if self.location else "unavailable",
                "last_handshake_received": self.open_received,
                "last_connection_success": self.last_connection_success,
                "diagnostics": self.diagnostics[-12:],
                "camera_status": self.status_message,
                "camera_data_packets": self.camera_data_packets,
                "source": self.location.source if self.location else None,
                "camera_reason": self.camera_error[:500],
                "aircraft_reason": self.aircraft_error[:500],
                "reason": self.error_message[:500],
                "dispatch_packets": self.dispatch_count,
                "last_recv_id": self.last_recv_id,
            }

    def diagnostic_message(self, reason: str) -> str:
        """
        Build one compact diagnostic message for the watcher console.

        @param str reason: Primary reason camera or aircraft coordinates were not returned.
        @return str Human-readable diagnostic summary.
        """
        details = [
            self.dll_message,
            f"Simulator={self.simulator_info.display_name}, server={self.simulator_info.app_name}, "
            f"version={self.simulator_info.app_version}, generation={self.simulator_info.generation}, provider={self.provider}",
            f"camera={self.camera_error or 'no failure'}, aircraft={self.aircraft_error or 'no failure'}",
            f"dispatch packets={self.dispatch_count}, last recv id={self.last_recv_id}",
        ]
        return reason + " Details: " + "; ".join(detail for detail in details if detail)

    def configure_functions(self, dll: Any, dispatch_type: Any) -> None:
        """
        Configure ctypes signatures for the SimConnect functions used here.

        @param Any dll: Loaded SimConnect.dll handle.
        @param Any dispatch_type: Callback type used by SimConnect_CallDispatch.
        """
        signatures = {
            "Open": [ctypes.POINTER(ctypes.c_void_p), ctypes.c_char_p, ctypes.c_void_p, ctypes.c_uint32, ctypes.c_void_p, ctypes.c_uint32],
            "Close": [ctypes.c_void_p], "CallDispatch": [ctypes.c_void_p, dispatch_type, ctypes.c_void_p],
            "AddToDataDefinition": [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_char_p, ctypes.c_char_p, ctypes.c_uint32, ctypes.c_float, ctypes.c_uint32],
            "RequestDataOnSimObject": [ctypes.c_void_p] + [ctypes.c_uint32] * 8,
            "GetLastSentPacketID": [ctypes.c_void_p, ctypes.POINTER(ctypes.c_uint32)],
        }
        for name, args in signatures.items():
            function = getattr(dll, "SimConnect_" + name)
            function.argtypes, function.restype = args, ctypes.c_long
        camera = {"CameraAcquire": [ctypes.c_void_p, ctypes.c_char_p], "CameraRelease": [ctypes.c_void_p, ctypes.c_char_p], "CameraGetStatus": [ctypes.c_void_p], "CameraGet": [ctypes.c_void_p, ctypes.c_uint32]}
        self.camera_exports = all(hasattr(dll, "SimConnect_" + name) for name in camera)
        if self.camera_exports:
            for name, args in camera.items():
                function = getattr(dll, "SimConnect_" + name)
                function.argtypes, function.restype = args, ctypes.c_long

    def _sent(self, dll: Any, phase: str) -> None:
        """
        Attribute the last successfully submitted packet to its provider phase.

        Call only after a successful HRESULT: a failed send can leave the
        previous packet ID unchanged. Send IDs correlate asynchronous EXCEPTION
        packets; they are separate from the aircraft request/definition IDs.
        Zero is unknown and is never stored as an attributable operation.
        """
        send_id = ctypes.c_uint32()
        if not simconnect_hresult_failed(dll.SimConnect_GetLastSentPacketID(self.handle, ctypes.byref(send_id))):
            if send_id.value:
                self.send_ids[int(send_id.value)] = phase

    def _pump(self, dll: Any, callback: Any, deadline: float, handshake: bool = False) -> None:
        """
        Dispatch native responses until the phase completes or its deadline ends.

        A monotonic deadline bounds all waits even when the wall clock changes.
        Provider-local errors end their attempt immediately; fatal connection or
        dispatch errors stop the transport. Short sleeps avoid busy polling.
        The callback remains strongly referenced by the acquisition's stack.
        """
        while time.monotonic() < deadline and not self.error_message:
            phase_error = self.camera_error if self.phase == "camera" else self.aircraft_error
            if (handshake and self.open_received) or (not handshake and (self.location is not None or phase_error)):
                break
            result = dll.SimConnect_CallDispatch(self.handle, callback, None)
            if simconnect_hresult_failed(result):
                self.error_message = f"SimConnect dispatch failed: HRESULT {int(result)}"
                break
            time.sleep(min(0.01, max(0.0, deadline - time.monotonic())))

    def current_camera_location(self) -> Tuple[Optional[SimCameraLocation], str]:
        """
        Query the current Flight Simulator camera location.

        This compatibility entry point also uses the selected aircraft fallback.
        @return Tuple[Optional[SimCameraLocation], str] Tuple containing the location or None, plus a diagnostic string.
        """
        return self.current_location()

    def current_location(self) -> Tuple[Optional[SimCameraLocation], str]:
        """
        Acquire validated coordinates on one serialized, short-lived connection.

        MSFS 2020 skips camera calls. MSFS 2024 and unknown generations try the
        existing WORLD camera path when its exports exist, then aircraft SimVars
        on camera failure. Handshake/camera waits reserve part of the same total
        budget for aircraft data. No previous location is cached or returned.

        Each native handle belongs to this invocation; rapid/concurrent calls
        cannot consume another photo's responses. Camera release and connection
        close run independently in finally. API/dispatch failures return None,
        allowing the watcher to upload the photo without simulator metadata.

        @return Tuple[Optional[SimCameraLocation], str] Location or soft failure, plus diagnostics.
        """
        with self._lock:
            self._reset()
            self.attempted = True
            dll = None
            try:
                if os.name != "nt":
                    self.error_message = "SimConnect metadata is available only on Windows."
                    return None, self.diagnostic_message(self.error_message)
                path, _tried = self.resolve_dll_path()
                if path is None:
                    self.error_message = "SimConnect.dll is unavailable: no usable candidate found. Tried: " + ", ".join(map(str, _tried))
                    return None, self.diagnostic_message(self.error_message)
                self.dll_path = str(path)
                self.dll_architecture = self._pe_architecture(path)
                self.dll_message = f"Using SimConnect.dll: {path}"
                dll = ctypes.WinDLL(str(path))
                self.dll_loadable = True
                self._emit("info", "SimConnect DLL loaded: " + str(path))
                dispatch_type = getattr(ctypes, "WINFUNCTYPE", ctypes.CFUNCTYPE)(None, ctypes.POINTER(_SimConnectRecv), ctypes.c_uint32, ctypes.c_void_p)
                self.configure_functions(dll, dispatch_type)
                callback = dispatch_type(self._dispatch_safe)
                deadline = time.monotonic() + self.timeout_seconds
                result = dll.SimConnect_Open(ctypes.byref(self.handle), b"PHP Gallery uploader", None, 0, None, 0)
                self.diagnostics.append(f"SimConnect_Open HRESULT {int(result)}")
                if simconnect_hresult_failed(result) or not self.handle.value:
                    self.error_message = f"Simulator connection not available: SimConnect_Open HRESULT {int(result)}"
                    return None, self.diagnostic_message(self.error_message)
                self.connection_state = "opening"
                self._sent(dll, "handshake")
                self._pump(dll, callback, min(deadline, time.monotonic() + min(0.2, self.timeout_seconds * 0.2)), True)
                if self.error_message:
                    return None, self.diagnostic_message(self.error_message)
                if not self.open_received:
                    self._emit("warning", "Simulator handshake not received; generation unknown, using bounded capability detection.")
                if self.simulator_info.generation == 2020:
                    self.camera_available = False
                self.provider = "aircraft_position" if self.simulator_info.generation == 2020 else "camera_world_position with aircraft fallback"
                self._emit("info", "Location provider selected: " + self.provider)
                if self.simulator_info.generation != 2020:
                    self.phase = "camera"
                    if self.camera_exports:
                        try:
                            acquire = dll.SimConnect_CameraAcquire(self.handle, SIMCONNECT_CLIENT_ID)
                            if not simconnect_hresult_failed(acquire):
                                self._sent(dll, "camera")
                            self._camera_acquired = not simconnect_hresult_failed(acquire)
                            if simconnect_hresult_failed(acquire):
                                self.diagnostics.append(f"CameraAcquire HRESULT {int(acquire)}")
                            status = dll.SimConnect_CameraGetStatus(self.handle)
                            if not simconnect_hresult_failed(status):
                                self._sent(dll, "camera")
                            if simconnect_hresult_failed(status):
                                self.diagnostics.append(f"CameraGetStatus HRESULT {int(status)}")
                            result = dll.SimConnect_CameraGet(self.handle, SIMCONNECT_POSITION_REFERENTIAL_WORLD)
                            if not simconnect_hresult_failed(result):
                                self._sent(dll, "camera")
                            self.diagnostics.append(f"CameraGet WORLD HRESULT {int(result)}")
                            if simconnect_hresult_failed(result):
                                self.camera_error = f"Camera request failed: HRESULT {int(result)}"
                            else:
                                self._pump(dll, callback, min(deadline - self.timeout_seconds * 0.4, time.monotonic() + self.timeout_seconds * 0.4))
                            if self.location is None and not self.camera_error:
                                self.camera_error = "Camera data timeout" if not self.error_message else self.error_message
                        except Exception as exc:
                            self.camera_error = f"Camera API failed: {type(exc).__name__}: {exc}"
                    else:
                        self.camera_available = False
                        self.camera_error = "Camera API unsupported: DLL exports unavailable"
                    if self.location is None:
                        self._emit("warning", f"Camera position unavailable: {self.camera_error}. Falling back to aircraft position.")
                if self.location is None and not self.error_message:
                    self.phase = "aircraft"
                    for variable, units in ((b"PLANE LATITUDE", b"degrees"), (b"PLANE LONGITUDE", b"degrees"), (b"PLANE ALTITUDE", b"feet")):
                        result = dll.SimConnect_AddToDataDefinition(self.handle, SIMCONNECT_DEFINITION_ID, variable, units, 4, 0.0, 0xFFFFFFFF)
                        if not simconnect_hresult_failed(result):
                            self._sent(dll, "aircraft")
                        if simconnect_hresult_failed(result):
                            self.aircraft_error = f"Aircraft data definition failed: HRESULT {int(result)}"
                            break
                    if not self.aircraft_error:
                        result = dll.SimConnect_RequestDataOnSimObject(self.handle, SIMCONNECT_REQUEST_ID, SIMCONNECT_DEFINITION_ID, 0, 1, 0, 0, 0, 0)
                        if not simconnect_hresult_failed(result):
                            self._sent(dll, "aircraft")
                        self.diagnostics.append(f"RequestDataOnSimObject HRESULT {int(result)}")
                        if simconnect_hresult_failed(result):
                            self.aircraft_error = f"Aircraft request failed: HRESULT {int(result)}"
                        else:
                            self._pump(dll, callback, deadline)
                    if self.location is None and not self.aircraft_error:
                        self.aircraft_error = self.error_message or "Aircraft data timeout"
                if self.location is not None:
                    self._emit("info", f"Location received: source={self.location.source}, lat={self.location.latitude:.7f}, lon={self.location.longitude:.7f}, alt={self.location.altitude:.2f} ft.")
                    return self.location, self.diagnostic_message("Simulator location acquired.")
                self._emit("warning", "Simulator location unavailable. " + self.diagnostic_message(self.error_message or "All location providers failed.") + " Upload will continue without simulator location.")
                return None, self.diagnostic_message(self.error_message or "All location providers failed.")
            except Exception as exc:
                self.error_message = f"SimConnect acquisition failed: {type(exc).__name__}: {exc}"
                self._emit("warning", self.error_message)
                return None, self.diagnostic_message(self.error_message)
            finally:
                if dll is not None and self.handle.value:
                    if self._camera_acquired:
                        try:
                            dll.SimConnect_CameraRelease(self.handle, SIMCONNECT_CLIENT_ID)
                        except Exception:
                            logging.debug("SimConnect camera release failed.", exc_info=True)
                    try:
                        dll.SimConnect_Close(self.handle)
                    except Exception:
                        logging.debug("SimConnect close failed.", exc_info=True)
                    self.connection_state = "closed"
                    self.handle = ctypes.c_void_p()

    def _dispatch_safe(self, data: ctypes.POINTER(_SimConnectRecv), size: int, context: ctypes.c_void_p) -> None:
        """Contain callback errors because ctypes would otherwise suppress them."""
        try:
            self.dispatch(data, size, context)
        except Exception as exc:
            reason = f"SimConnect dispatch callback failed: {type(exc).__name__}: {exc}"
            if self.phase == "camera":
                self.camera_error = reason
            elif self.phase == "aircraft":
                self.aircraft_error = reason
            else:
                self.error_message = reason
            self._emit("warning", reason)

    def dispatch(self, data: ctypes.POINTER(_SimConnectRecv), size: int, _context: ctypes.c_void_p) -> None:
        """
        Receive one SimConnect dispatch packet and correlate active requests.

        @param ctypes.POINTER(_SimConnectRecv) data: Pointer to the base SimConnect receive structure.
        @param int size: Packet byte length.
        @param ctypes.c_void_p _context: Unused callback context.
        """
        if not data or size < ctypes.sizeof(_SimConnectRecv):
            self.error_message = "SimConnect receive header was too small."
            return
        header = data.contents
        if header.dwSize < ctypes.sizeof(_SimConnectRecv) or header.dwSize > size:
            reason = "SimConnect receive packet size was invalid."
            if header.dwID in (SIMCONNECT_RECV_ID_CAMERA_DATA, SIMCONNECT_RECV_ID_CAMERA_STATUS):
                self.camera_error = reason
            elif header.dwID == SIMCONNECT_RECV_ID_SIMOBJECT_DATA:
                self.aircraft_error = reason
            else:
                self.error_message = reason
            return
        size = min(size, int(header.dwSize))
        self.dispatch_count += 1
        self.last_recv_id = int(header.dwID)
        logging.debug("SimConnect dispatch recv=%d bytes=%d phase=%s", header.dwID, size, self.phase)
        packet_types = {SIMCONNECT_RECV_ID_OPEN: _SimConnectRecvOpen, SIMCONNECT_RECV_ID_EXCEPTION: _SimConnectRecvException, SIMCONNECT_RECV_ID_CAMERA_STATUS: _SimConnectRecvCameraStatus, SIMCONNECT_RECV_ID_CAMERA_DATA: _SimConnectRecvCameraData, SIMCONNECT_RECV_ID_SIMOBJECT_DATA: _SimConnectRecvSimobjectData}
        structure = packet_types.get(int(header.dwID))
        if structure and size < ctypes.sizeof(structure):
            reason = f"SimConnect receive {int(header.dwID)} packet was too small: {size} bytes."
            if header.dwID in (SIMCONNECT_RECV_ID_CAMERA_DATA, SIMCONNECT_RECV_ID_CAMERA_STATUS):
                self.camera_error = reason
            elif header.dwID == SIMCONNECT_RECV_ID_SIMOBJECT_DATA:
                self.aircraft_error = reason
            else:
                self.error_message = reason
            return
        if header.dwID == SIMCONNECT_RECV_ID_OPEN:
            self.simulator_info = SimulatorInfo.from_open(ctypes.cast(data, ctypes.POINTER(_SimConnectRecvOpen)).contents)
            self.open_received = True
            self.connection_state = "connected"
            self.last_connection_success = True
            self._emit("info", f"SimConnect connection established. Simulator detected: {self.simulator_info.display_name}; server={self.simulator_info.app_name}; generation={self.simulator_info.generation or 'unknown'}; version={self.simulator_info.app_version}; SimConnect={self.simulator_info.simconnect_version}.")
        elif header.dwID == SIMCONNECT_RECV_ID_QUIT:
            self.error_message = "Simulator connection closed by server."
        elif header.dwID == SIMCONNECT_RECV_ID_EXCEPTION:
            exception = ctypes.cast(data, ctypes.POINTER(_SimConnectRecvException)).contents
            exception_id = int(exception.dwException)
            exception_name = {3: "UNRECOGNIZED_ID", 5: "VERSION_MISMATCH", 46: "CAMERA_API"}.get(exception_id, "UNKNOWN")
            reason = f"SimConnect exception {exception_id} {exception_name} (send={int(exception.dwSendID)}, index={int(exception.dwIndex)})."
            # CAMERA_API belongs to the camera even when the server omits its
            # send ID. A late camera failure must not cancel an aircraft request
            # already in progress. Recorded send IDs remain authoritative.
            default_phase = "camera" if exception_id == 46 else self.phase
            phase = self.send_ids.get(int(exception.dwSendID), default_phase)
            if phase == "camera":
                self.camera_error = reason
                self.camera_available = False if exception_id in (3, 5, 46) else self.camera_available
            elif phase == "aircraft":
                self.aircraft_error = reason
            else:
                self.error_message = reason
            self._emit("warning", f"{phase}: {reason}")
        elif header.dwID == SIMCONNECT_RECV_ID_CAMERA_STATUS:
            status = ctypes.cast(data, ctypes.POINTER(_SimConnectRecvCameraStatus)).contents
            self.status_message = "SimConnect camera status: " + SIMCONNECT_CAMERA_AVAILABILITY_LABELS.get(int(status.acquiredState), "unknown")
        elif header.dwID == SIMCONNECT_RECV_ID_CAMERA_DATA:
            self.camera_data_packets += 1
            if self.phase != "camera":
                return
            camera = ctypes.cast(data, ctypes.POINTER(_SimConnectRecvCameraData)).contents.CameraData
            if camera.PositionReferential != SIMCONNECT_POSITION_REFERENTIAL_WORLD:
                self.camera_error = f"SimConnect returned camera referential {int(camera.PositionReferential)} instead of WORLD."
                return
            candidate = SimCameraLocation(float(camera.Position.x), float(camera.Position.y), float(camera.Position.z))
            if simconnect_camera_position_valid(candidate):
                self.location = candidate
                self.camera_available = True
            else:
                self.camera_error = "SimConnect returned invalid camera position."
        elif header.dwID == SIMCONNECT_RECV_ID_SIMOBJECT_DATA and self.phase == "aircraft":
            packet = ctypes.cast(data, ctypes.POINTER(_SimConnectRecvSimobjectData)).contents
            if packet.dwRequestID != SIMCONNECT_REQUEST_ID or packet.dwDefineID != SIMCONNECT_DEFINITION_ID or packet.dwObjectID != 0:
                return
            if packet.dwDefineCount != 3 or packet.dwFlags != 0 or packet.dwentrynumber != 1 or packet.dwoutof != 1:
                self.aircraft_error = "Aircraft data packet shape was invalid."
                return
            source = "aircraft_position" if self.simulator_info.generation == 2020 else "aircraft_position_fallback"
            candidate = SimCameraLocation(float(packet.latitude), float(packet.longitude), float(packet.altitude), source)
            if simconnect_camera_position_valid(candidate):
                self.location = candidate
            else:
                self.aircraft_error = "SimConnect returned invalid aircraft position."


SimConnectCameraClient = SimConnectLocationClient
