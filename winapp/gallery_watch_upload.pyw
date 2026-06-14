"""
PHP Gallery watched-folder uploader.

This Windows companion app watches one local folder and uploads new image
files to PHP Gallery through a gallery-scoped API key. The Python side is
intentionally thin: it observes files, waits until each file is stable, sends
an HTTP multipart upload request, and records local retry state.

Gallery-side validation, permissions, storage decisions, database indexing,
thumbnail generation, and any future business rules remain owned by PHP
Gallery. Keeping those rules server-side prevents this helper from becoming a
second, divergent implementation of upload behavior.
"""

import argparse
import base64
import concurrent.futures
import ctypes
import hashlib
import importlib
import json
import logging
import math
import mimetypes
import multiprocessing
import os
import shutil
import queue
import subprocess
import sys
import tempfile
import threading
import time
import uuid
import webbrowser
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Set, Tuple
from urllib import error, parse, request

try:
    import tkinter as tk
    from tkinter import filedialog, messagebox, ttk
except ImportError:  # pragma: no cover
    # Tkinter can be absent in some stripped-down Python builds. The import is
    # optional so command-line mode can still report a clean error instead of
    # crashing at import time.
    tk = None
    filedialog = None
    messagebox = None
    ttk = None

try:
    from PIL import Image, ImageOps, ImageStat
except ImportError:  # pragma: no cover
    # Pillow is optional for the watcher. Manual client-side thumbnail generation
    # requires it, while normal watch-folder uploads keep working without it.
    Image = None
    ImageOps = None
    ImageStat = None

try:
    import pystray
except ImportError:  # pragma: no cover
    pystray = None


APP_NAME = "PHPGalleryUploader"
APP_DISPLAY_NAME = "PHP Gallery uploader"
CONFIG_DIR = Path(os.environ.get("APPDATA", str(Path.home()))) / APP_NAME
CONFIG_PATH = CONFIG_DIR / "config.json"
STATE_PATH = CONFIG_DIR / "upload_state.json"
LOG_PATH = CONFIG_DIR / "watcher.log"
APP_DIR = Path(__file__).resolve().parent
REQUIREMENTS_PATH = APP_DIR / "requirements.txt"
ASSETS_DIR = APP_DIR / "assets"
TRAY_ICON_PNG_PATH = ASSETS_DIR / "tray-icon.png"
TRAY_ICON_ICO_PATH = ASSETS_DIR / "tray-icon.ico"

_AI_TRANSFORMERS_PIPELINES: Dict[Tuple[str, str], Any] = {}
_AI_TRANSFORMERS_LOCK = threading.Lock()

SUPPORTED_SUFFIXES = {
    ".jpg",
    ".jpeg",
    ".png",
    ".gif",
    ".webp",
    ".heic",
    ".heif",
    ".dng",
}

DEFAULT_INTERVAL_SECONDS = 1.0
DEFAULT_STABLE_SECONDS = 2.0
DEFAULT_TIMEOUT_SECONDS = 180
DEFAULT_INVENTORY_REFRESH_SECONDS = 30.0
DEFAULT_AI_WORKER_POLL_SECONDS = 60.0
DEFAULT_AI_WORKER_LEASE_SECONDS = 600
AI_WORKER_MIN_POLL_SECONDS = 5.0
AI_WORKER_MAX_LEASE_SECONDS = 3600
AI_ANALYZER_TIMEOUT_SECONDS = 1800
AI_HEARTBEAT_MIN_SECONDS = 15.0
AI_MODEL_NAME_DEFAULT = "local-image-metadata"
AI_MODEL_VERSION_DEFAULT = "1"
AI_VISION_BACKEND_DEFAULT = "auto"
AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT = "Salesforce/blip-image-captioning-base"
AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT = "google/owlvit-base-patch32"
AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT = 0.18
AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT = (
    "person, people, man, woman, child, face, dog, cat, horse, bird, animal, car, truck, bus, "
    "train, aircraft, airplane, helicopter, boat, bicycle, motorcycle, house, building, bridge, "
    "road, street, city, village, church, castle, tower, window, door, guitar, piano, violin, "
    "musical instrument, food, drink, table, chair, bed, computer, laptop, phone, camera, flower, "
    "tree, forest, mountain, beach, sea, river, lake, sky, cloud, sunset, snow, rain, airport, "
    "runway, cockpit, airplane wing"
)
AI_OLLAMA_URL_DEFAULT = "http://127.0.0.1:11434"
AI_OLLAMA_MODEL_DEFAULT = "llava:latest"
AI_SEMANTIC_PROMPT_DEFAULT = (
    "Describe this image for private gallery search metadata. "
    "Return JSON only with keys internal_description, labels, objects, scene, activities, text, and confidence_notes. "
    "Use concise lower-case labels for visible objects such as person, bridge, guitar, house, car, animal, aircraft, food, landscape, building. "
    "Do not invent objects that are not visually apparent. The metadata is internal search data, not public prose."
)
AI_VISION_BACKEND_CHOICES = ("auto", "pillow", "transformers", "ollama", "external")
THUMBNAIL_SIZES = [300, 600, 800, 960, 1280, 1600]
DEFAULT_THUMBNAIL_WORKERS = max(2, min(12, (os.cpu_count() or 4) // 2 or 2))
DEFAULT_UPLOAD_WORKERS = 4
MAX_THUMBNAIL_WORKERS = 32
MAX_UPLOAD_WORKERS = 12
SIMCONNECT_CAMERA_QUERY_TIMEOUT_SECONDS = 1.0
SIMCONNECT_POSITION_REFERENTIAL_WORLD = 2
SIMCONNECT_DLL_ENV_VAR = "SIMCONNECT_DLL"
SIMCONNECT_CLIENT_ID = b"PHPGalleryUploader"
SIMCONNECT_RECV_ID_EXCEPTION = 1
SIMCONNECT_RECV_ID_CAMERA_DATA = 40
SIMCONNECT_RECV_ID_CAMERA_STATUS = 41
SIMCONNECT_CAMERA_AVAILABILITY_LABELS = {
    0: "not acquired",
    1: "acquired",
    2: "acquired by another client",
    3: "user disabled",
}
SIMCONNECT_COMMON_DLL_PATHS = [
    Path.home() / "MSFS 2024 SDK" / "SimConnect SDK" / "lib" / "SimConnect.dll",
    Path.home() / "MSFS SDK" / "SimConnect SDK" / "lib" / "SimConnect.dll",
    Path.home() / "AppData" / "Local" / "Programs" / "MSFS 2024 SDK" / "SimConnect SDK" / "lib" / "SimConnect.dll",
    Path.home() / "AppData" / "Local" / "Programs" / "MSFS SDK" / "SimConnect SDK" / "lib" / "SimConnect.dll",
]



@dataclass
class WatcherConfig:
    """
    Runtime and persisted configuration for the watcher app.

    The values are intentionally simple JSON-compatible primitives so the
    configuration file can be edited manually when needed. The GUI writes the
    same structure as command-line mode reads, which keeps both entry points
    aligned.

    @param watched_folder: Local folder to observe for newly added images.
    @param gallery_url: PHP Gallery site root or explicit upload endpoint URL.
    @param api_key: Gallery-scoped API key used in the X-Gallery-API-Key header.
    @param scan_interval_seconds: Polling delay between folder scans.
    @param stable_seconds: Minimum unchanged duration before a file is uploaded.
    @param create_thumbnails: Whether the server should create thumbnails after
        accepting the upload.
    @param attach_sim_camera_metadata: Whether watched-folder uploads should try
        to attach the current Flight Simulator camera location.
    @param simconnect_dll_path: Optional user-selected SimConnect.dll override.
    @param manual_thumbnail_workers: Manual upload process count for local
        thumbnail generation. Zero means automatic.
    @param manual_upload_workers: Manual upload thread count for multipart HTTP
        upload requests. Zero means automatic.
    @param inventory_refresh_seconds: Seconds between remote inventory handshakes.
        The default keeps long batches using fresh short-lived API requests.
    @param ai_worker_enabled: Whether this app should run the optional AI
        metadata worker. Disabled by default so existing upload behavior is
        unchanged after upgrade.
    @param ai_worker_poll_seconds: Delay between queue polls when the server has
        no AI work for this API key.
    @param ai_worker_lease_seconds: Requested server lease length for one
        claimed AI job. Heartbeats extend the lease while work continues.
    @param ai_model_name: Worker-reported model or analyzer family name.
    @param ai_model_version: Worker-reported model or analyzer version.
    @param ai_external_command: Optional local command that receives an image
        path placeholder and returns JSON metadata on stdout.
    @param ai_vision_backend: Analyzer selection. Auto prefers the external
        command when configured, then the in-process Transformers backend, then
        local Ollama vision, then Pillow.
    @param ai_transformers_caption_model: Optional Hugging Face image-to-text
        model used inside this Python process for semantic captions.
    @param ai_transformers_detector_model: Optional Hugging Face zero-shot
        object detector used inside this Python process for semantic labels.
    @param ai_transformers_object_labels: Candidate object labels passed to the
        local zero-shot detector.
    @param ai_transformers_detection_threshold: Minimum detector score saved as
        an internal object label.
    @param ai_ollama_url: Local Ollama base URL used for semantic vision
        captions. The default points to localhost.
    @param ai_ollama_model: Ollama vision model name, for example llava:latest
        or llama3.2-vision:latest.
    @param ai_semantic_prompt: Prompt sent only to a local vision backend.
    """

    watched_folder: str = ""
    gallery_url: str = ""
    api_key: str = ""
    scan_interval_seconds: float = DEFAULT_INTERVAL_SECONDS
    stable_seconds: float = DEFAULT_STABLE_SECONDS
    create_thumbnails: bool = True
    attach_sim_camera_metadata: bool = True
    simconnect_dll_path: str = ""
    delete_uploaded_files: bool = False
    manual_thumbnail_workers: int = 0
    manual_upload_workers: int = 0
    inventory_refresh_seconds: float = DEFAULT_INVENTORY_REFRESH_SECONDS
    ai_worker_enabled: bool = False
    ai_worker_poll_seconds: float = DEFAULT_AI_WORKER_POLL_SECONDS
    ai_worker_lease_seconds: int = DEFAULT_AI_WORKER_LEASE_SECONDS
    ai_model_name: str = AI_MODEL_NAME_DEFAULT
    ai_model_version: str = AI_MODEL_VERSION_DEFAULT
    ai_external_command: str = ""
    ai_vision_backend: str = AI_VISION_BACKEND_DEFAULT
    ai_transformers_caption_model: str = AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT
    ai_transformers_detector_model: str = AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT
    ai_transformers_object_labels: str = AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT
    ai_transformers_detection_threshold: float = AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT
    ai_ollama_url: str = AI_OLLAMA_URL_DEFAULT
    ai_ollama_model: str = AI_OLLAMA_MODEL_DEFAULT
    ai_semantic_prompt: str = AI_SEMANTIC_PROMPT_DEFAULT

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "WatcherConfig":
        """
        Build a typed configuration object from decoded JSON data.
        
        Missing keys are treated as defaults. This makes configuration upgrades
        safe because older config files keep working when new fields are added.
        Numeric values are coerced from strings where possible because manual
        edits and GUI variables often produce text.
        
        @param Dict[str, Any] data: Dictionary loaded from the JSON configuration file.
        @return WatcherConfig Normalized WatcherConfig instance.
        @raises ValueError: Raised by float conversion when numeric values are present but cannot be parsed.
        """
        return cls(
            watched_folder=str(data.get("watched_folder", "")),
            gallery_url=str(data.get("gallery_url", "")),
            api_key=str(data.get("api_key", "")),
            scan_interval_seconds=float(data.get("scan_interval_seconds", DEFAULT_INTERVAL_SECONDS) or DEFAULT_INTERVAL_SECONDS),
            stable_seconds=float(data.get("stable_seconds", DEFAULT_STABLE_SECONDS) or DEFAULT_STABLE_SECONDS),
            create_thumbnails=bool(data.get("create_thumbnails", True)),
            attach_sim_camera_metadata=bool(data.get("attach_sim_camera_metadata", True)),
            simconnect_dll_path=str(data.get("simconnect_dll_path", "")),
            delete_uploaded_files=bool(data.get("delete_uploaded_files", False)),
            manual_thumbnail_workers=int(data.get("manual_thumbnail_workers", 0) or 0),
            manual_upload_workers=int(data.get("manual_upload_workers", 0) or 0),
            inventory_refresh_seconds=float(data.get("inventory_refresh_seconds", DEFAULT_INVENTORY_REFRESH_SECONDS) or DEFAULT_INVENTORY_REFRESH_SECONDS),
            ai_worker_enabled=bool(data.get("ai_worker_enabled", False)),
            ai_worker_poll_seconds=float(data.get("ai_worker_poll_seconds", DEFAULT_AI_WORKER_POLL_SECONDS) or DEFAULT_AI_WORKER_POLL_SECONDS),
            ai_worker_lease_seconds=int(data.get("ai_worker_lease_seconds", DEFAULT_AI_WORKER_LEASE_SECONDS) or DEFAULT_AI_WORKER_LEASE_SECONDS),
            ai_model_name=str(data.get("ai_model_name", AI_MODEL_NAME_DEFAULT) or AI_MODEL_NAME_DEFAULT),
            ai_model_version=str(data.get("ai_model_version", AI_MODEL_VERSION_DEFAULT) or AI_MODEL_VERSION_DEFAULT),
            ai_external_command=str(data.get("ai_external_command", "")),
            ai_vision_backend=normalize_ai_vision_backend(str(data.get("ai_vision_backend", AI_VISION_BACKEND_DEFAULT) or AI_VISION_BACKEND_DEFAULT)),
            ai_transformers_caption_model=str(data.get("ai_transformers_caption_model", AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT) or AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT),
            ai_transformers_detector_model=str(data.get("ai_transformers_detector_model", AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT) or AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT),
            ai_transformers_object_labels=str(data.get("ai_transformers_object_labels", AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT) or AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT),
            ai_transformers_detection_threshold=float(data.get("ai_transformers_detection_threshold", AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT) or AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT),
            ai_ollama_url=str(data.get("ai_ollama_url", AI_OLLAMA_URL_DEFAULT) or AI_OLLAMA_URL_DEFAULT),
            ai_ollama_model=str(data.get("ai_ollama_model", AI_OLLAMA_MODEL_DEFAULT) or AI_OLLAMA_MODEL_DEFAULT),
            ai_semantic_prompt=str(data.get("ai_semantic_prompt", AI_SEMANTIC_PROMPT_DEFAULT) or AI_SEMANTIC_PROMPT_DEFAULT),
        )

    def to_dict(self) -> Dict[str, Any]:
        """
        Convert this configuration to a JSON-serializable dictionary.
        
        @return Dict[str, Any] Plain dictionary suitable for json.dumps().
        """
        return {
            "watched_folder": self.watched_folder,
            "gallery_url": self.gallery_url,
            "api_key": self.api_key,
            "scan_interval_seconds": self.scan_interval_seconds,
            "stable_seconds": self.stable_seconds,
            "create_thumbnails": self.create_thumbnails,
            "attach_sim_camera_metadata": self.attach_sim_camera_metadata,
            "simconnect_dll_path": self.simconnect_dll_path,
            "delete_uploaded_files": self.delete_uploaded_files,
            "manual_thumbnail_workers": self.manual_thumbnail_workers,
            "manual_upload_workers": self.manual_upload_workers,
            "inventory_refresh_seconds": self.inventory_refresh_seconds,
            "ai_worker_enabled": self.ai_worker_enabled,
            "ai_worker_poll_seconds": self.ai_worker_poll_seconds,
            "ai_worker_lease_seconds": self.ai_worker_lease_seconds,
            "ai_model_name": self.ai_model_name,
            "ai_model_version": self.ai_model_version,
            "ai_external_command": self.ai_external_command,
            "ai_vision_backend": self.ai_vision_backend,
            "ai_transformers_caption_model": self.ai_transformers_caption_model,
            "ai_transformers_detector_model": self.ai_transformers_detector_model,
            "ai_transformers_object_labels": self.ai_transformers_object_labels,
            "ai_transformers_detection_threshold": self.ai_transformers_detection_threshold,
            "ai_ollama_url": self.ai_ollama_url,
            "ai_ollama_model": self.ai_ollama_model,
            "ai_semantic_prompt": self.ai_semantic_prompt,
        }


@dataclass
class LocalThumbnail:
    """
    One locally generated thumbnail variant waiting to be uploaded.

    @param path: Temporary thumbnail file path on the client computer.
    @param size: Long-side size in pixels, matching PHP Gallery thumbnail_sizes().
    @param format: Output format accepted by the gallery, either jpg or webp.
    """

    path: Path
    size: int
    format: str


@dataclass
class SimCameraLocation:
    """
    Flight Simulator camera world position captured through SimConnect.

    @param latitude: Camera latitude in degrees.
    @param longitude: Camera longitude in degrees.
    @param altitude: Camera altitude in feet.
    """

    latitude: float
    longitude: float
    altitude: float

    def upload_fields(self) -> Dict[str, str]:
        """
        Convert the camera position into upload automation metadata fields.
        
        @return Dict[str, str] Multipart form fields accepted by PHP Gallery.
        """
        return {
            "sim_location_source": "simconnect_camera",
            "sim_camera_latitude": f"{self.latitude:.7f}",
            "sim_camera_longitude": f"{self.longitude:.7f}",
            "sim_camera_altitude": f"{self.altitude:.2f}",
        }


class _SimConnectRecv(ctypes.Structure):
    _fields_ = [
        ("dwSize", ctypes.c_uint32),
        ("dwVersion", ctypes.c_uint32),
        ("dwID", ctypes.c_uint32),
    ]


class _SimConnectDataXYZ(ctypes.Structure):
    _fields_ = [
        ("x", ctypes.c_double),
        ("y", ctypes.c_double),
        ("z", ctypes.c_double),
    ]


class _SimConnectDataPBH(ctypes.Structure):
    _fields_ = [
        ("Pitch", ctypes.c_float),
        ("Bank", ctypes.c_float),
        ("Heading", ctypes.c_float),
    ]


class _SimConnectDataCamera(ctypes.Structure):
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
    _pack_ = 1
    _fields_ = [
        ("dwSize", ctypes.c_uint32),
        ("dwVersion", ctypes.c_uint32),
        ("dwID", ctypes.c_uint32),
        ("CameraData", _SimConnectDataCamera),
    ]


class _SimConnectRecvException(ctypes.Structure):
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


class SimConnectCameraClient:
    """
    Minimal SimConnect camera reader used by watched-folder uploads.

    The client opens a short-lived SimConnect connection, requests the current
    camera in world referential coordinates, then closes the connection. Missing
    simulator, missing DLL, or camera API failures are reported as soft failures
    so uploads can continue without location metadata.
    """

    def __init__(self, dll_path: str = "", timeout_seconds: float = SIMCONNECT_CAMERA_QUERY_TIMEOUT_SECONDS) -> None:
        """
        Create a camera client.
        
        @param str dll_path: Optional explicit SimConnect.dll path selected by the user.
        @param float timeout_seconds: Maximum time to wait for one camera response.
        """
        self.timeout_seconds = max(0.2, float(timeout_seconds))
        self.configured_dll_path = trim_path(dll_path)
        self.handle = ctypes.c_void_p()
        self.error_message = ""
        self.dll_message = ""
        self.status_message = ""
        self.diagnostics: List[str] = []
        self.dispatch_count = 0
        self.last_recv_id: Optional[int] = None
        self.camera_data_packets = 0
        self.location: Optional[SimCameraLocation] = None

    def current_camera_location(self) -> Tuple[Optional[SimCameraLocation], str]:
        """
        Query the current Flight Simulator camera location.
        
        @return Tuple[Optional[SimCameraLocation], str] Tuple containing the location or None, plus a diagnostic string.
        """
        if os.name != "nt":
            return None, "SimConnect camera metadata is available only on Windows."

        try:
            dll_path, tried_paths = self.resolve_dll_path()
            if dll_path is None:
                return None, "SimConnect.dll is unavailable: no usable candidate found. Tried: " + ", ".join(str(path) for path in tried_paths)
            resolved_dll_path = dll_path.resolve()
            self.dll_message = f"Using SimConnect.dll: {resolved_dll_path}"
            self.diagnostics.append(self.dll_message)
            dll = ctypes.WinDLL(str(dll_path))
        except Exception as exc:  # noqa: BLE001
            return None, f"SimConnect.dll is unavailable: {exc}"

        try:
            dispatch_type = getattr(ctypes, "WINFUNCTYPE", ctypes.CFUNCTYPE)(None, ctypes.POINTER(_SimConnectRecv), ctypes.c_uint32, ctypes.c_void_p)
            self.configure_functions(dll, dispatch_type)
        except Exception as exc:  # noqa: BLE001
            return None, f"SimConnect camera API is unavailable: {exc}"

        try:
            open_result = dll.SimConnect_Open(ctypes.byref(self.handle), b"PHP Gallery uploader", None, 0, None, 0)
            self.diagnostics.append(f"SimConnect_Open HRESULT {int(open_result)}")
            if simconnect_hresult_failed(open_result) or not self.handle.value:
                return None, self.diagnostic_message(f"SimConnect connection failed: HRESULT {int(open_result)}")

            pre_dispatch_failure = ""
            acquire_result = dll.SimConnect_CameraAcquire(self.handle, SIMCONNECT_CLIENT_ID)
            self.diagnostics.append(f"SimConnect_CameraAcquire HRESULT {int(acquire_result)}")
            if simconnect_hresult_failed(acquire_result):
                pre_dispatch_failure = f"SimConnect_CameraAcquire failed: HRESULT {int(acquire_result)}"
            status_result = dll.SimConnect_CameraGetStatus(self.handle)
            self.diagnostics.append(f"SimConnect_CameraGetStatus HRESULT {int(status_result)}")
            camera_result = dll.SimConnect_CameraGet(self.handle, SIMCONNECT_POSITION_REFERENTIAL_WORLD)
            self.diagnostics.append(f"SimConnect_CameraGet WORLD HRESULT {int(camera_result)}")
            if simconnect_hresult_failed(camera_result):
                return None, self.diagnostic_message(f"SimConnect_CameraGet failed: HRESULT {int(camera_result)}")

            callback = dispatch_type(self.dispatch)
            deadline = time.time() + self.timeout_seconds
            while time.time() < deadline and self.location is None and self.error_message == "":
                dispatch_result = dll.SimConnect_CallDispatch(self.handle, callback, None)
                if simconnect_hresult_failed(dispatch_result):
                    self.error_message = f"SimConnect_CallDispatch failed: HRESULT {int(dispatch_result)}"
                    break
                time.sleep(0.01)

            if self.location is not None:
                return self.location, self.dll_message or f"Using SimConnect.dll: {resolved_dll_path}"
            if self.error_message:
                return None, self.diagnostic_message(self.error_message)
            if pre_dispatch_failure:
                return None, self.diagnostic_message(pre_dispatch_failure)
            return None, self.diagnostic_message("SimConnect camera data was not returned before the timeout.")
        except Exception as exc:  # noqa: BLE001
            return None, self.diagnostic_message(str(exc))
        finally:
            if self.handle.value:
                try:
                    dll.SimConnect_CameraRelease(self.handle, SIMCONNECT_CLIENT_ID)
                    dll.SimConnect_Close(self.handle)
                except Exception:  # noqa: BLE001
                    logging.debug("SimConnect close failed.", exc_info=True)
                self.handle = ctypes.c_void_p()

    def configure_functions(self, dll: Any, dispatch_type: Any) -> None:
        """
        Configure ctypes signatures for the SimConnect functions used here.
        
        @param Any dll: Loaded SimConnect.dll handle.
        @param Any dispatch_type: Callback type used by SimConnect_CallDispatch.
        """
        dll.SimConnect_Open.argtypes = [ctypes.POINTER(ctypes.c_void_p), ctypes.c_char_p, ctypes.c_void_p, ctypes.c_uint32, ctypes.c_void_p, ctypes.c_uint32]
        dll.SimConnect_Open.restype = ctypes.c_long
        dll.SimConnect_CameraAcquire.argtypes = [ctypes.c_void_p, ctypes.c_char_p]
        dll.SimConnect_CameraAcquire.restype = ctypes.c_long
        dll.SimConnect_CameraRelease.argtypes = [ctypes.c_void_p, ctypes.c_char_p]
        dll.SimConnect_CameraRelease.restype = ctypes.c_long
        dll.SimConnect_CameraGetStatus.argtypes = [ctypes.c_void_p]
        dll.SimConnect_CameraGetStatus.restype = ctypes.c_long
        dll.SimConnect_CameraGet.argtypes = [ctypes.c_void_p, ctypes.c_uint32]
        dll.SimConnect_CameraGet.restype = ctypes.c_long
        dll.SimConnect_CallDispatch.argtypes = [ctypes.c_void_p, dispatch_type, ctypes.c_void_p]
        dll.SimConnect_CallDispatch.restype = ctypes.c_long
        dll.SimConnect_Close.argtypes = [ctypes.c_void_p]
        dll.SimConnect_Close.restype = ctypes.c_long

    def resolve_dll_path(self) -> Tuple[Optional[Path], List[Path]]:
        """
        Find a usable SimConnect client DLL on the local machine.
        
        @return Tuple[Optional[Path], List[Path]] Tuple of the selected DLL path and every absolute candidate checked.
        """
        tried_paths: List[Path] = []

        def record(candidate: Optional[Path]) -> Optional[Path]:
            """
            Handle record.
            
            @param Optional[Path] candidate: Candidate value.
            @return Optional[Path] Result value for the caller.
            """
            if candidate is None:
                return None
            resolved = candidate.resolve(strict=False)
            tried_paths.append(resolved)
            return resolved if resolved.is_file() else None

        if self.configured_dll_path is not None and self.configured_dll_path.is_file():
            return self.configured_dll_path.resolve(), [self.configured_dll_path.resolve()]

        env_path = trim_path(os.environ.get(SIMCONNECT_DLL_ENV_VAR, ""))
        env_selected = record(env_path)
        if env_selected is not None:
            return env_selected, tried_paths

        local_candidates = [
            APP_DIR / "SimConnect.dll",
            APP_DIR.parent / "SimConnect.dll",
            Path.cwd() / "SimConnect.dll",
        ]
        for candidate in local_candidates:
            selected = record(candidate)
            if selected is not None:
                return selected, tried_paths

        for candidate in SIMCONNECT_COMMON_DLL_PATHS:
            selected = record(candidate)
            if selected is not None:
                return selected, tried_paths

        return None, tried_paths

    def dll_resolution_message(self) -> str:
        """
        Describe which SimConnect DLL path would be used without opening the sim.
        
        @return str Human-readable DLL resolution summary.
        """
        dll_path, tried_paths = self.resolve_dll_path()
        if dll_path is not None:
            return f"Using SimConnect.dll: {dll_path.resolve()}"
        if tried_paths:
            return "No SimConnect.dll found. Tried: " + ", ".join(str(path) for path in tried_paths)
        return "No SimConnect.dll path candidates were available."

    def diagnostic_message(self, reason: str) -> str:
        """
        Build one compact diagnostic message for the watcher console.
        
        @param str reason: Primary reason camera coordinates were not returned.
        @return str Human-readable diagnostic summary.
        """
        details = list(self.diagnostics)
        if self.status_message:
            details.append(self.status_message)
        if self.dispatch_count > 0:
            details.append(f"dispatch packets={self.dispatch_count}, last recv id={self.last_recv_id}, camera data packets={self.camera_data_packets}")
        else:
            details.append("dispatch packets=0")
        return reason + " Details: " + "; ".join(details)

    def dispatch(self, data: ctypes.POINTER(_SimConnectRecv), size: int, _context: ctypes.c_void_p) -> None:
        """
        Receive one SimConnect dispatch packet.
        
        @param ctypes.POINTER(_SimConnectRecv) data: Pointer to the base SimConnect receive structure.
        @param int size: Packet byte length.
        @param ctypes.c_void_p _context: Unused callback context.
        """
        if not data:
            return
        header = data.contents
        self.dispatch_count += 1
        self.last_recv_id = int(header.dwID)
        if header.dwID == SIMCONNECT_RECV_ID_EXCEPTION and size >= ctypes.sizeof(_SimConnectRecvException):
            exception = ctypes.cast(data, ctypes.POINTER(_SimConnectRecvException)).contents
            self.error_message = (
                f"SimConnect camera request failed with exception {int(exception.dwException)} "
                f"(send={int(exception.dwSendID)}, index={int(exception.dwIndex)})."
            )
            return
        if header.dwID == SIMCONNECT_RECV_ID_CAMERA_STATUS and size >= ctypes.sizeof(_SimConnectRecvCameraStatus):
            status = ctypes.cast(data, ctypes.POINTER(_SimConnectRecvCameraStatus)).contents
            status_id = int(status.acquiredState)
            status_label = SIMCONNECT_CAMERA_AVAILABILITY_LABELS.get(status_id, f"unknown {status_id}")
            self.status_message = (
                f"SimConnect camera status: {status_label}, game_controlled={int(bool(status.bGameControlled))}"
            )
            return
        if header.dwID != SIMCONNECT_RECV_ID_CAMERA_DATA:
            return
        self.camera_data_packets += 1
        if size < ctypes.sizeof(_SimConnectRecvCameraData):
            self.error_message = f"SimConnect camera data packet was too small: {size} bytes."
            return

        camera_packet = ctypes.cast(data, ctypes.POINTER(_SimConnectRecvCameraData)).contents
        camera = camera_packet.CameraData
        if int(camera.PositionReferential) != SIMCONNECT_POSITION_REFERENTIAL_WORLD:
            self.error_message = f"SimConnect returned camera referential {int(camera.PositionReferential)} instead of WORLD."
            return

        location = SimCameraLocation(
            latitude=float(camera.Position.x),
            longitude=float(camera.Position.y),
            altitude=float(camera.Position.z),
        )
        if simconnect_camera_position_valid(location):
            self.location = location
        else:
            self.error_message = (
                f"SimConnect returned invalid camera position: "
                f"lat={location.latitude}, lng={location.longitude}, alt={location.altitude}."
            )


def trim_path(value: str) -> Optional[Path]:
    """
    Convert one optional path string into a usable Path object.
    
    @param str value: Raw environment variable or config value.
    @return Optional[Path] Path when the string is non-empty, otherwise None.
    """
    text = str(value).strip()
    if not text:
        return None
    return Path(text)


class ConfigStore:
    """
    Small persistence wrapper for the local configuration file.

    The class owns all filesystem details for config persistence so the GUI and
    command-line code do not duplicate path handling, JSON parsing, and atomic
    write behavior.
    """

    def __init__(self, path: Path = CONFIG_PATH) -> None:
        """
        Create a config store for one JSON file.
        
        @param Path path: Path to the JSON configuration file.
        """
        self.path = path

    def load(self) -> WatcherConfig:
        """
        Load the configuration file.
        
        Invalid, missing, or non-object JSON content falls back to defaults. The
        watcher should remain startable even after a user accidentally damages
        the local config file.
        
        @return WatcherConfig Loaded configuration, or defaults when the file is unusable.
        """
        if not self.path.is_file():
            return WatcherConfig()
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
            if not isinstance(data, dict):
                return WatcherConfig()
            return WatcherConfig.from_dict(data)
        except (OSError, json.JSONDecodeError, ValueError):
            return WatcherConfig()

    def save(self, config: WatcherConfig) -> None:
        """
        Persist configuration atomically.
        
        The temporary file plus replace pattern avoids leaving a half-written
        config if Windows, antivirus, or the process interrupts the write.
        
        @param WatcherConfig config: Configuration object to persist.
        @raises OSError: Propagated when the file cannot be written.
        """
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = self.path.with_suffix(".tmp")
        tmp_path.write_text(json.dumps(config.to_dict(), indent=2), encoding="utf-8")
        tmp_path.replace(self.path)


class UploadState:
    """
    Tracks successful uploads, duplicate content, and retry scheduling.

    State is deliberately local and conservative. The server remains the final
    authority, but the helper avoids repeatedly uploading the same file content
    when the user restarts the app or copies a duplicate image into the watched
    folder.
    """

    def __init__(self, path: Path = STATE_PATH) -> None:
        """
        Create and load the upload state store.
        
        @param Path path: Path to the JSON state file.
        """
        self.path = path
        self.data: Dict[str, Any] = {
            "uploaded_paths": {},
            "uploaded_hashes": {},
            "failures": {},
        }
        self.load()

    def load(self) -> None:
        """
        Load upload state from disk into memory.
        
        Malformed sections are ignored individually so one damaged section does
        not invalidate all upload history. This matters because state is only an
        optimization and retry aid, not canonical gallery data.
        """
        if not self.path.is_file():
            return
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
            if isinstance(data, dict):
                uploaded_paths = data.get("uploaded_paths", {})
                uploaded_hashes = data.get("uploaded_hashes", {})
                failures = data.get("failures", {})
                self.data["uploaded_paths"] = uploaded_paths if isinstance(uploaded_paths, dict) else {}
                self.data["uploaded_hashes"] = uploaded_hashes if isinstance(uploaded_hashes, dict) else {}
                self.data["failures"] = failures if isinstance(failures, dict) else {}
        except (OSError, json.JSONDecodeError):
            return

    def save(self) -> None:
        """
        Persist upload state atomically.
        
        @raises OSError: Propagated when the state file cannot be written.
        """
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = self.path.with_suffix(".tmp")
        tmp_path.write_text(json.dumps(self.data, indent=2), encoding="utf-8")
        tmp_path.replace(self.path)

    def already_uploaded_path(self, path: Path, file_hash: str) -> bool:
        """
        Check whether the exact path and content were already uploaded.
        
        @param Path path: Local image path being considered.
        @param str file_hash: SHA-256 hash of the current file content.
        @return bool True when this path already succeeded with the same content.
        """
        entry = self.data["uploaded_paths"].get(str(path))
        return isinstance(entry, dict) and entry.get("sha256") == file_hash

    def already_uploaded_hash(self, file_hash: str) -> bool:
        """
        Check whether identical content already uploaded under any file name.
        
        @param str file_hash: SHA-256 hash of the current file content.
        @return bool True when this byte-identical file content is already known.
        """
        return file_hash in self.data["uploaded_hashes"]

    def mark_uploaded(self, path: Path, file_hash: str, size: int, response: Dict[str, Any]) -> None:
        """
        Record a successful upload after the server confirms it.
        
        @param Path path: Local file path that was uploaded.
        @param str file_hash: SHA-256 hash captured at upload time.
        @param int size: File size in bytes captured at upload time.
        @param Dict[str, Any] response: JSON object returned by the PHP Gallery endpoint.
        """
        now = time.time()
        record = {
            "sha256": file_hash,
            "size": size,
            "uploaded_at": now,
            "response": response,
        }
        self.data["uploaded_paths"][str(path)] = record
        self.data["uploaded_hashes"][file_hash] = {
            "first_path": str(path),
            "uploaded_at": now,
        }
        # A confirmed upload clears any retry metadata for the same path.
        self.data["failures"].pop(str(path), None)
        self.save()

    def mark_duplicate(self, path: Path, file_hash: str, size: int) -> None:
        """
        Record a skipped file whose content was already uploaded earlier.
        
        The path-level record prevents the same duplicate file from being
        repeatedly revisited during later scans.
        
        @param Path path: Local duplicate file path.
        @param str file_hash: SHA-256 hash matching already uploaded content.
        @param int size: File size in bytes.
        """
        self.data["uploaded_paths"][str(path)] = {
            "sha256": file_hash,
            "size": size,
            "uploaded_at": time.time(),
            "skipped_duplicate": True,
        }
        self.data["failures"].pop(str(path), None)
        self.save()

    def retry_allowed(self, path: Path, file_hash: str) -> bool:
        """
        Determine whether a failed upload may be attempted now.
        
        Retry delay is keyed by path and content hash. Replacing the file with
        different content immediately clears the wait because it is no longer
        the same failed upload attempt.
        
        @param Path path: Local file path being considered.
        @param str file_hash: Current SHA-256 hash of the file.
        @return bool True when no backoff delay is active.
        """
        failure = self.data["failures"].get(str(path))
        if not isinstance(failure, dict):
            return True
        if failure.get("sha256") != file_hash:
            return True
        return time.time() >= float(failure.get("next_retry_at", 0) or 0)

    def mark_failure(self, path: Path, file_hash: str, message: str) -> None:
        """
        Record a failed upload attempt and schedule a later retry.
        
        The retry delay uses exponential backoff capped at one hour. This avoids
        hammering the gallery endpoint while still recovering automatically from
        transient network, hosting, or maintenance failures.
        
        @param Path path: Local file path that failed to upload.
        @param str file_hash: SHA-256 hash of the file at failure time.
        @param str message: Human-readable failure reason.
        """
        previous = self.data["failures"].get(str(path))
        previous_attempts = int(previous.get("attempts", 0)) if isinstance(previous, dict) and previous.get("sha256") == file_hash else 0
        attempts = previous_attempts + 1
        retry_delay = min(3600, 5 * (2 ** min(attempts - 1, 7)))
        now = time.time()
        self.data["failures"][str(path)] = {
            "sha256": file_hash,
            "attempts": attempts,
            "last_error": message,
            "last_failed_at": now,
            "next_retry_at": now + retry_delay,
        }
        self.save()


class FileStabilityTracker:
    """
    Detects when copied files have stopped changing.

    Files copied into a watched folder are often visible before the copy is
    complete. Uploading such a file would produce truncated images or server-side
    errors. This tracker waits until size and modification time remain unchanged
    for the configured duration.
    """

    def __init__(self, stable_seconds: float) -> None:
        """
        Create a stability tracker.
        
        @param float stable_seconds: Required unchanged duration before a file is.
        """
        self.stable_seconds = max(0.5, stable_seconds)
        self._seen: Dict[Path, Tuple[int, float, float]] = {}

    def stable(self, path: Path) -> bool:
        """
        Check whether a path has remained unchanged long enough.
        
        @param Path path: File path to inspect.
        @return bool True when size and modification time have been stable for the.
        """
        try:
            stat = path.stat()
        except OSError:
            self._seen.pop(path, None)
            return False

        size = int(stat.st_size)
        mtime = float(stat.st_mtime)
        now = time.time()
        previous = self._seen.get(path)

        # First sighting, or a changed size/mtime, restarts the stability timer.
        if previous is None or previous[0] != size or previous[1] != mtime:
            self._seen[path] = (size, mtime, now)
            return False

        return now - previous[2] >= self.stable_seconds


def setup_logging() -> None:
    """
    Configure file logging for watcher diagnostics.
    
    The GUI shows recent status in the window, but the file log survives restarts
    and is more useful when diagnosing upload or hosting issues later.
    """
    CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    logging.basicConfig(
        filename=str(LOG_PATH),
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
    )


def normalize_upload_url(value: str) -> str:
    """
    Convert a site root or endpoint-like value into the upload endpoint URL.
    
    Accepted input forms include a bare host, a gallery root URL, index.php, an
    index.php URL already containing page=upload_automation_upload, or a future
    /api/upload style endpoint. The function is deliberately tolerant because
    users are likely to paste whatever URL they currently have open.
    
    @param str value: User-provided site URL or upload endpoint.
    @return str Normalized upload endpoint URL, or an empty string for blank input.
    """
    raw = value.strip()
    if not raw:
        return ""

    parsed = parse.urlparse(raw)
    if not parsed.scheme:
        raw = "https://" + raw
        parsed = parse.urlparse(raw)

    query = parse.parse_qs(parsed.query)
    if query.get("page", [""])[0] == "upload_automation_upload" or parsed.path.rstrip("/").endswith("/api/upload"):
        return raw
    if parsed.path.rstrip("/").endswith("/index.php") or parsed.path == "/index.php":
        return parse.urlunparse(parsed._replace(query="page=upload_automation_upload", fragment=""))
    return raw.rstrip("/") + "/index.php?page=upload_automation_upload"


def revoke_upload_key(upload_url: str, api_key: str) -> Dict[str, Any]:
    """
    Revoke the active upload automation key through the gallery endpoint.
    
    The gallery revokes the token identified by the authenticated API key, so
    the companion app does not need a second admin-only credential or token id.
    
    @param str upload_url: Normalized PHP Gallery upload endpoint.
    @param str api_key: Current gallery-scoped API key.
    @return Dict[str, Any] Parsed JSON response from the server.
    @raises RuntimeError: Raised for network, HTTP, or non-JSON failures.
    """
    body = parse.urlencode({"action": "revoke"}).encode("ascii")
    http_request = request.Request(
        upload_url,
        data=body,
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "Content-Length": str(len(body)),
            "X-Gallery-API-Key": api_key,
            "Accept": "application/json",
            "User-Agent": "PHPGalleryUploader/1.1",
        },
        method="POST",
    )

    try:
        with request.urlopen(http_request, timeout=DEFAULT_TIMEOUT_SECONDS) as response:
            response_body = response.read().decode("utf-8", errors="replace")
    except error.HTTPError as exc:
        response_body = exc.read().decode("utf-8", errors="replace")
        try:
            payload = json.loads(response_body)
            message = payload.get("error") if isinstance(payload, dict) else None
        except json.JSONDecodeError:
            message = None
        raise RuntimeError(str(message or f"HTTP {exc.code}: {response_body[:300]}")) from exc
    except error.URLError as exc:
        raise RuntimeError(str(exc.reason)) from exc

    try:
        payload = json.loads(response_body)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"Server returned non-JSON response: {response_body[:300]}") from exc
    if not isinstance(payload, dict):
        raise RuntimeError("Server returned an invalid JSON response.")
    if not payload.get("ok"):
        raise RuntimeError(str(payload.get("error") or "API key revocation failed."))
    return payload



def post_json(upload_url: str, api_key: str, payload: Dict[str, Any], timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS) -> Dict[str, Any]:
    """
    Submit one JSON API request and parse the JSON response.
    
    The companion app uses JSON only for metadata handshakes. Image bytes still
    use multipart/form-data because PHP Gallery routes them through the normal
    upload pipeline. Keeping this helper separate from multipart_upload makes the
    reconnect inventory request cheap and safe to call repeatedly during long
    batches.
    
    @param str upload_url: Normalized PHP Gallery upload endpoint.
    @param str api_key: Gallery-scoped API key sent as X-Gallery-API-Key.
    @param Dict[str, Any] payload: JSON-serializable request object.
    @param float timeout_seconds: Network timeout for this metadata request.
    @return Dict[str, Any] Parsed JSON response from the server.
    @raises RuntimeError: Raised for network, HTTP, non-JSON, or gallery errors.
    """
    body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    http_request = request.Request(
        upload_url,
        data=body,
        headers={
            "Content-Type": "application/json; charset=utf-8",
            "Content-Length": str(len(body)),
            "X-Gallery-API-Key": api_key,
            "Accept": "application/json",
            "User-Agent": "PHPGalleryUploader/1.2",
        },
        method="POST",
    )

    try:
        with request.urlopen(http_request, timeout=max(5.0, float(timeout_seconds))) as response:
            response_body = response.read().decode("utf-8", errors="replace")
    except error.HTTPError as exc:
        response_body = exc.read().decode("utf-8", errors="replace")
        try:
            error_payload = json.loads(response_body)
            message = error_payload.get("error") if isinstance(error_payload, dict) else None
        except json.JSONDecodeError:
            message = None
        raise RuntimeError(str(message or f"HTTP {exc.code}: {response_body[:300]}")) from exc
    except error.URLError as exc:
        raise RuntimeError(str(exc.reason)) from exc

    try:
        response_payload = json.loads(response_body)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"Server returned non-JSON response: {response_body[:300]}") from exc
    if not isinstance(response_payload, dict):
        raise RuntimeError("Server returned an invalid JSON response.")
    if not response_payload.get("ok"):
        raise RuntimeError(str(response_payload.get("error") or "API request failed."))
    return response_payload


def post_binary_to_file(
    upload_url: str,
    api_key: str,
    fields: Dict[str, str],
    target_path: Path,
    timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
) -> Tuple[str, int]:
    """
    Submit one form-encoded request and stream the binary response to a file.
    
    AI-analysis workers use this helper to download only the server-assigned
    image asset for a claimed job. The API key and claim token remain required;
    the public gallery URL is not used, so private or unpublished images do not
    need to be exposed to anonymous visitors for processing.
    
    @param str upload_url: Normalized PHP Gallery upload endpoint.
    @param str api_key: Gallery-scoped API key sent as X-Gallery-API-Key.
    @param Dict[str, str] fields: Form fields accepted by the upload automation endpoint.
    @param Path target_path: Local path where the downloaded asset should be stored.
    @param float timeout_seconds: Network timeout for the download request.
    @return Tuple[str, int] Tuple of response content type and downloaded byte count.
    @raises RuntimeError: Raised for network or HTTP failures.
    """
    body = parse.urlencode(fields).encode("utf-8")
    http_request = request.Request(
        upload_url,
        data=body,
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "Content-Length": str(len(body)),
            "X-Gallery-API-Key": api_key,
            "Accept": "application/octet-stream, application/json;q=0.8",
            "User-Agent": "PHPGalleryUploader/1.3",
        },
        method="POST",
    )

    try:
        with request.urlopen(http_request, timeout=max(5.0, float(timeout_seconds))) as response:
            content_type = str(response.headers.get("Content-Type") or "application/octet-stream")
            downloaded = 0
            with target_path.open("wb") as handle:
                while True:
                    chunk = response.read(1024 * 256)
                    if not chunk:
                        break
                    handle.write(chunk)
                    downloaded += len(chunk)
            return content_type, downloaded
    except error.HTTPError as exc:
        response_body = exc.read().decode("utf-8", errors="replace")
        try:
            error_payload = json.loads(response_body)
            message = error_payload.get("error") if isinstance(error_payload, dict) else None
        except json.JSONDecodeError:
            message = None
        raise RuntimeError(str(message or f"HTTP {exc.code}: {response_body[:300]}")) from exc
    except error.URLError as exc:
        raise RuntimeError(str(exc.reason)) from exc


def extension_for_content_type(content_type: str, fallback_name: str) -> str:
    """
    Return a safe filename suffix for a downloaded AI job asset.
    
    @param str content_type: Response Content-Type header.
    @param str fallback_name: Original filename from the claimed job payload.
    @return str File extension including the leading dot.
    """
    fallback_suffix = Path(fallback_name).suffix.lower()
    if fallback_suffix in SUPPORTED_SUFFIXES:
        return fallback_suffix
    mime = content_type.split(";", 1)[0].strip().lower()
    guessed = mimetypes.guess_extension(mime) or ""
    if guessed.lower() in {".jpe"}:
        return ".jpg"
    return guessed if guessed else ".img"


def ai_worker_id() -> str:
    """
    Return a stable local worker id for server-side lease diagnostics.
    
    The value is not a secret and is not used for authorization. It lets the
    server show which companion app instance currently owns a claim.
    
    @return str Stable worker identifier for this Windows profile and machine.
    """
    CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    worker_path = CONFIG_DIR / "worker_id.txt"
    try:
        if worker_path.is_file():
            existing = worker_path.read_text(encoding="utf-8").strip()
            if existing:
                return existing
        generated = f"{os.environ.get('COMPUTERNAME', 'windows').strip() or 'windows'}:{uuid.uuid4().hex}"
        worker_path.write_text(generated, encoding="utf-8")
        return generated
    except OSError:
        return f"temporary:{uuid.uuid4().hex}"


def enter_background_thread_mode() -> None:
    """
    Ask Windows to schedule the current thread as background work.
    
    This keeps optional image analysis from competing aggressively with the tray
    UI and ordinary uploads. Unsupported platforms silently continue because the
    worker can still run correctly without this scheduling hint.
    """
    if os.name != "nt":
        return
    try:
        kernel32 = ctypes.windll.kernel32  # type: ignore[attr-defined]
        thread_handle = kernel32.GetCurrentThread()
        thread_mode_background_begin = 0x00010000
        if kernel32.SetThreadPriority(thread_handle, thread_mode_background_begin):
            return
        thread_priority_below_normal = -1
        kernel32.SetThreadPriority(thread_handle, thread_priority_below_normal)
    except Exception:  # noqa: BLE001
        logging.debug("Could not set background thread priority.", exc_info=True)


def inventory_candidate(path: Path, file_hash: Optional[str] = None, size: Optional[int] = None) -> Dict[str, Any]:
    """
    Build one file descriptor for a remote gallery inventory request.
    
    @param Path path: Local image path being compared with the target gallery.
    @param Optional[str] file_hash: Optional precomputed SHA-256 hash. When omitted, the file is hashed now.
    @param Optional[int] size: Optional precomputed file size. When omitted, stat() is used now.
    @return Dict[str, Any] JSON-safe file descriptor accepted by PHP Gallery.
    @raises OSError: Propagated when the file cannot be read or statted.
    """
    resolved_hash = file_hash or sha256_file(path)
    resolved_size = int(size if size is not None else path.stat().st_size)
    return {
        "client_id": resolved_hash,
        "filename": path.name,
        "size": resolved_size,
        "sha256": resolved_hash,
    }


class RemoteInventorySession:
    """
    Maintains a short-lived view of the passive gallery inventory.

    The session does not persist transfer state. It asks the remote gallery what
    already exists, remembers only the current process answer, and refreshes that
    answer after the configured reconnect interval or immediately after an upload
    failure. This is deliberately API-driven so a restarted client still treats
    the gallery as the authority.
    """

    def __init__(self, refresh_seconds: float, emit: Any) -> None:
        """
        Create a remote inventory session.
        
        @param float refresh_seconds: Minimum seconds between planned inventory probes.
        @param Any emit: Callback receiving status level and message.
        """
        self.refresh_seconds = max(1.0, float(refresh_seconds or DEFAULT_INVENTORY_REFRESH_SECONDS))
        self.emit = emit
        self.last_refresh_at = 0.0
        self.last_fingerprint = ""
        self.existing_hashes: Set[str] = set()
        self.lock = threading.Lock()

    def due(self) -> bool:
        """
        Return whether the planned reconnect interval has elapsed.
        
        @return bool True when another inventory handshake should be made.
        """
        return (time.time() - self.last_refresh_at) >= self.refresh_seconds

    def remember_existing(self, payload: Dict[str, Any]) -> int:
        """
        Store hashes confirmed by a gallery inventory response.
        
        @param Dict[str, Any] payload: Parsed inventory response returned by PHP Gallery.
        @return int Number of confirmed remote files in this response.
        """
        existing = payload.get("existing", [])
        if not isinstance(existing, list):
            return 0
        matched = 0
        for row in existing:
            if not isinstance(row, dict):
                continue
            remote_hash = str(row.get("sha256", "")).lower()
            if len(remote_hash) != 64:
                continue
            self.existing_hashes.add(remote_hash)
            matched += 1
        self.last_fingerprint = str(payload.get("fingerprint", "") or self.last_fingerprint)
        return matched

    def refresh(self, upload_url: str, api_key: str, candidates: List[Dict[str, Any]], force: bool = False) -> bool:
        """
        Ask the passive gallery which submitted files already exist.
        
        @param str upload_url: Normalized PHP Gallery upload endpoint.
        @param str api_key: Gallery-scoped API key.
        @param List[Dict[str, Any]] candidates: Local file descriptors to compare with the gallery.
        @param bool force: When True, refresh even before the planned interval elapses.
        @return bool True when the API call completed successfully.
        """
        if not candidates:
            return False
        if not force and not self.due():
            return False
        with self.lock:
            if not force and not self.due():
                return False
            try:
                payload = post_json(
                    upload_url,
                    api_key,
                    {"action": "inventory", "files": candidates},
                    timeout_seconds=min(DEFAULT_TIMEOUT_SECONDS, max(10.0, self.refresh_seconds)),
                )
                matched = self.remember_existing(payload)
                self.last_refresh_at = time.time()
                checked = int(payload.get("checked", len(candidates)) or 0)
                self.emit("info", f"Remote inventory refreshed: checked={checked}, already_present={matched}.")
                return True
            except Exception as exc:  # noqa: BLE001
                self.last_refresh_at = time.time()
                self.emit("warning", f"Remote inventory refresh failed: {exc}")
                return False

    def has_hash(self, file_hash: str) -> bool:
        """
        Return whether the remote gallery has confirmed this content hash.
        
        @param str file_hash: SHA-256 hash of a local file.
        @return bool True when a previous inventory response matched it.
        """
        return file_hash.lower() in self.existing_hashes

    def confirm_after_failure(self, upload_url: str, api_key: str, candidate: Dict[str, Any]) -> bool:
        """
        Force a reconnect probe after an upload request failed or timed out.
        
        @param str upload_url: Normalized PHP Gallery upload endpoint.
        @param str api_key: Gallery-scoped API key.
        @param Dict[str, Any] candidate: File descriptor for the request that just failed.
        @return bool True when the gallery now reports the file as already present.
        """
        self.refresh(upload_url, api_key, [candidate], force=True)
        return self.has_hash(str(candidate.get("sha256", "")))


def sha256_file(path: Path) -> str:
    """
    Calculate a file SHA-256 hash without loading the whole file at once.
    
    @param Path path: File to hash.
    @return str Hex-encoded SHA-256 digest.
    @raises OSError: Propagated when the file cannot be opened or read.
    """
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while True:
            chunk = handle.read(1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest()


def iter_candidate_files(folder: Path) -> List[Path]:
    """
    Return supported image files directly inside the watched folder.
    
    The watcher is intentionally non-recursive. A watched import/drop folder is
    expected to act as a flat staging area, while gallery hierarchy and final
    placement remain controlled by PHP Gallery.
    
    @param Path folder: Folder to scan.
    @return List[Path] Sorted list of supported image paths. Missing or unreadable folders.
    """
    try:
        candidates = [item for item in folder.iterdir() if item.is_file() and item.suffix.lower() in SUPPORTED_SUFFIXES]
    except OSError:
        return []

    # Oldest first produces stable, predictable upload order when several files
    # are copied into the folder at roughly the same time.
    return sorted(candidates, key=lambda item: (item.stat().st_mtime if item.exists() else 0, item.name.lower()))


def multipart_field_part(boundary: str, name: str, value: str) -> bytes:
    """
    Build one text field for a multipart/form-data request body.
    
    @param str boundary: Multipart boundary string without the leading dashes.
    @param str name: Submitted field name.
    @param str value: Submitted field value.
    @return bytes Encoded multipart field bytes.
    """
    return b"".join([
        f"--{boundary}\r\n".encode("ascii"),
        f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode("ascii"),
        str(value).encode("utf-8"),
        b"\r\n",
    ])


def multipart_file_part(boundary: str, field_name: str, path: Path, filename: Optional[str] = None) -> bytes:
    """
    Build one file field for a multipart/form-data request body.
    
    @param str boundary: Multipart boundary string without the leading dashes.
    @param str field_name: Submitted file field name.
    @param Path path: Local file path whose bytes should be sent.
    @param Optional[str] filename: Optional remote file name. When omitted, path.name is used.
    @return bytes Encoded multipart file bytes including the file content.
    @raises OSError: Propagated when the file cannot be read.
    """
    safe_name = (filename or path.name).replace('"', "_")
    content_type = mimetypes.guess_type(safe_name)[0] or "application/octet-stream"
    return b"".join([
        f"--{boundary}\r\n".encode("ascii"),
        f'Content-Disposition: form-data; name="{field_name}"; filename="{safe_name}"\r\n'.encode("utf-8"),
        f"Content-Type: {content_type}\r\n\r\n".encode("ascii"),
        path.read_bytes(),
        b"\r\n",
    ])


def multipart_upload(
    upload_url: str,
    api_key: str,
    path: Path,
    create_thumbnails: bool,
    thumbnails: Optional[List[LocalThumbnail]] = None,
    client_upload_id: Optional[str] = None,
    metadata_fields: Optional[Dict[str, str]] = None,
) -> Dict[str, Any]:
    """
    Upload one image file using standard-library HTTP multipart/form-data.
    
    The same endpoint is used for watch-folder uploads and manual uploads. Manual
    uploads may include locally generated thumbnail files in the same request. The
    server still stores the original image through the existing gallery upload
    pipeline, then installs the supplied thumbnail variants beside the final image
    record.
    
    @param str upload_url: Normalized PHP Gallery upload endpoint.
    @param str api_key: Gallery-scoped API key sent as X-Gallery-API-Key.
    @param Path path: Local image path to upload.
    @param bool create_thumbnails: Whether to ask the gallery to generate thumbnails.
    @param Optional[List[LocalThumbnail]] thumbnails: Optional local thumbnail variants to send with the image.
    @param Optional[str] client_upload_id: Optional stable request-local ID used to map supplied.
    @param Optional[Dict[str, str]] metadata_fields: Optional text fields to submit beside the image.
    @return Dict[str, Any] Parsed JSON response from the server.
    @raises RuntimeError: Raised for HTTP errors, network errors, non-JSON responses, malformed JSON payloads, or server-declared upload failure.
    @raises OSError: Propagated when the image or a thumbnail cannot be read.
    """
    boundary = "PHPGalleryUpload" + uuid.uuid4().hex
    field_entries: List[Tuple[str, str]] = [
        ("create_thumbnails", "1" if create_thumbnails else "0"),
    ]
    if client_upload_id:
        field_entries.append(("image_client_ids[]", client_upload_id))
    for name, value in (metadata_fields or {}).items():
        field_entries.append((str(name), str(value)))

    body_parts: List[bytes] = []
    for name, value in field_entries:
        body_parts.append(multipart_field_part(boundary, name, value))

    body_parts.append(multipart_file_part(boundary, "images[]", path, path.name))

    for thumbnail in thumbnails or []:
        body_parts.append(multipart_field_part(boundary, "thumbnail_client_ids[]", client_upload_id or ""))
        body_parts.append(multipart_field_part(boundary, "thumbnail_sizes[]", str(int(thumbnail.size))))
        body_parts.append(multipart_field_part(boundary, "thumbnail_formats[]", thumbnail.format))
        body_parts.append(multipart_file_part(boundary, "client_thumbnails[]", thumbnail.path, thumbnail.path.name))

    body_parts.append(f"--{boundary}--\r\n".encode("ascii"))
    body = b"".join(body_parts)

    http_request = request.Request(
        upload_url,
        data=body,
        headers={
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Content-Length": str(len(body)),
            "X-Gallery-API-Key": api_key,
            "Accept": "application/json",
            "User-Agent": "PHPGalleryUploader/1.1",
        },
        method="POST",
    )

    try:
        with request.urlopen(http_request, timeout=DEFAULT_TIMEOUT_SECONDS) as response:
            response_body = response.read().decode("utf-8", errors="replace")
    except error.HTTPError as exc:
        # PHP Gallery should return a JSON error payload, but hosting errors or
        # PHP fatal errors may return HTML. Preserve a safe excerpt for diagnosis.
        response_body = exc.read().decode("utf-8", errors="replace")
        try:
            payload = json.loads(response_body)
            message = payload.get("error") if isinstance(payload, dict) else None
        except json.JSONDecodeError:
            message = None
        raise RuntimeError(str(message or f"HTTP {exc.code}: {response_body[:300]}")) from exc
    except error.URLError as exc:
        raise RuntimeError(str(exc.reason)) from exc

    try:
        payload = json.loads(response_body)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"Server returned non-JSON response: {response_body[:300]}") from exc
    if not isinstance(payload, dict):
        raise RuntimeError("Server returned an invalid JSON response.")
    if not payload.get("ok"):
        raise RuntimeError(str(payload.get("error") or "Upload failed."))
    return payload


def selected_image_filetypes() -> List[Tuple[str, str]]:
    """
    Return file picker filters for manual image selection.
    
    @return List[Tuple[str, str]] Tkinter-compatible file type filters.
    """
    return [
        ("Image files", "*.jpg *.jpeg *.png *.gif *.webp *.heic *.heif *.dng"),
        ("JPEG", "*.jpg *.jpeg"),
        ("PNG", "*.png"),
        ("WebP", "*.webp"),
        ("All files", "*.*"),
    ]


def filter_supported_paths(paths: Iterable[str]) -> List[Path]:
    """
    Normalize manually selected paths and keep only supported image suffixes.
    
    @param Iterable[str] paths: Raw path strings returned by Tkinter.
    @return List[Path] Sorted, de-duplicated Path objects.
    """
    unique: Dict[str, Path] = {}
    for raw_path in paths:
        path = Path(raw_path)
        if path.is_file() and path.suffix.lower() in SUPPORTED_SUFFIXES:
            unique[str(path)] = path
    return [unique[key] for key in sorted(unique.keys(), key=str.lower)]


def local_thumbnail_supported() -> bool:
    """
    Return whether Pillow is available for client-side thumbnail generation.
    
    @return bool True when Pillow imports are available.
    """
    return Image is not None and ImageOps is not None


def thumbnail_runtime_status() -> str:
    """
    Build a clear status line for the Python runtime used by this process.
    
    Windows can have multiple Python installations and Microsoft Store aliases.
    The app must report the exact executable currently running the GUI because
    optional helper packages have to be installed into this same interpreter.
    
    @return str Human-readable runtime and Pillow availability status.
    """
    executable = sys.executable or "unknown Python executable"
    version = sys.version.split()[0]
    tray_status = "tray available" if pystray is not None else "tray unavailable"
    if local_thumbnail_supported():
        pillow_version = getattr(Image, "__version__", "installed")
        return f"Client-side thumbnails available. Python {version}: {executable}. Pillow: {pillow_version}; {tray_status}."
    return f"Client-side thumbnails unavailable for this Python runtime: {executable}. Python {version}; {tray_status}."


def clamp_int(value: int, minimum: int, maximum: int) -> int:
    """
    Restrict an integer to a configured inclusive range.
    
    @param int value: Candidate integer value.
    @param int minimum: Lowest accepted value.
    @param int maximum: Highest accepted value.
    @return int Value restricted to the accepted range.
    """
    return max(minimum, min(maximum, int(value)))


def automatic_thumbnail_worker_count() -> int:
    """
    Choose a conservative multiprocessing thumbnail worker count.
    
    The automatic value intentionally avoids using every logical CPU. Image
    resizing and WebP encoding also consume disk I/O, memory bandwidth, and RAM.
    On high-core CPUs, using roughly half the logical CPUs is usually faster and
    more stable than launching one encoder process per thread.
    
    @return int Worker process count for thumbnail generation.
    """
    cpu_count = os.cpu_count() or 4
    return clamp_int(max(2, cpu_count // 2), 2, min(MAX_THUMBNAIL_WORKERS, cpu_count))


def automatic_upload_worker_count() -> int:
    """
    Choose a conservative manual upload worker count.
    
    Upload concurrency should remain lower than thumbnail concurrency because the
    shared-hosting server, PHP process limits, and network uplink are often the
    bottleneck. Too many concurrent uploads can make the gallery slower instead
    of faster.
    
    @return int Worker thread count for multipart uploads.
    """
    return DEFAULT_UPLOAD_WORKERS


def resolve_thumbnail_worker_count(configured_value: int) -> int:
    """
    Resolve the manual thumbnail worker setting into a real process count.
    
    @param int configured_value: Stored UI value, where zero means automatic.
    @return int Safe worker process count.
    """
    if int(configured_value) <= 0:
        return automatic_thumbnail_worker_count()
    return clamp_int(int(configured_value), 1, MAX_THUMBNAIL_WORKERS)


def resolve_upload_worker_count(configured_value: int) -> int:
    """
    Resolve the manual upload worker setting into a real thread count.
    
    @param int configured_value: Stored UI value, where zero means automatic.
    @return int Safe worker thread count.
    """
    if int(configured_value) <= 0:
        return automatic_upload_worker_count()
    return clamp_int(int(configured_value), 1, MAX_UPLOAD_WORKERS)


def worker_choice_values(maximum: int) -> List[str]:
    """
    Build human-readable worker choices for the Tkinter comboboxes.
    
    @param int maximum: Highest explicit worker value to offer.
    @return List[str] List containing Auto plus numeric worker counts.
    """
    values = ["Auto"]
    for value in [1, 2, 4, 6, 8, 12, 16, 24, 32]:
        if value <= maximum:
            values.append(str(value))
    return values


def parse_worker_choice(value: str) -> int:
    """
    Convert a UI worker choice into the persisted integer representation.
    
    @param str value: Combobox text, either Auto or a positive integer.
    @return int Zero for Auto, otherwise a positive integer.
    """
    text = str(value).strip()
    if not text or text.lower() == "auto":
        return 0
    return int(text)


def normalize_ai_vision_backend(value: str) -> str:
    """
    Return a supported AI vision backend identifier.
    
    @param str value: Raw backend value from config or UI.
    @return str One of the supported backend identifiers.
    """
    normalized = value.strip().lower()
    if normalized in AI_VISION_BACKEND_CHOICES:
        return normalized
    return AI_VISION_BACKEND_DEFAULT


def format_worker_choice(value: int) -> str:
    """
    Convert a persisted worker value into combobox text.
    
    @param int value: Stored worker value, where zero means Auto.
    @return str Combobox text.
    """
    if int(value) <= 0:
        return "Auto"
    return str(int(value))


def install_dependencies_for_current_runtime() -> Tuple[bool, str]:
    """
    Install or repair winapp dependencies for the exact Python interpreter.
    
    This avoids the common Windows issue where install.bat installs packages into
    one Python version while a .pyw file association launches another version.
    
    @return Tuple[bool, str] Tuple containing success flag and command output text.
    """
    command = [sys.executable, "-m", "pip", "install", "--user"]
    if REQUIREMENTS_PATH.is_file():
        command.extend(["-r", str(REQUIREMENTS_PATH)])
    else:
        command.extend(["Pillow>=10.0", "pystray>=0.19.5"])

    try:
        completed = subprocess.run(
            command,
            check=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
    except Exception as exc:  # noqa: BLE001
        return False, str(exc)

    output = completed.stdout.strip() if completed.stdout else "pip produced no output"
    if completed.returncode == 0:
        try:
            globals()["Image"] = importlib.import_module("PIL.Image")
            globals()["ImageOps"] = importlib.import_module("PIL.ImageOps")
            globals()["ImageStat"] = importlib.import_module("PIL.ImageStat")
            globals()["pystray"] = importlib.import_module("pystray")
        except Exception as exc:  # noqa: BLE001
            return False, f"pip finished, but required packages still cannot be imported by this process: {exc}\n{output}"
    return completed.returncode == 0, output


def install_semantic_ai_dependencies_for_current_runtime() -> Tuple[bool, str]:
    """
    Install optional in-process semantic AI packages for this Python runtime.
    
    These packages are intentionally not listed in requirements.txt because they
    are large and are needed only when the operator selects the Transformers
    backend. The normal uploader, tray icon, manual upload, and Pillow fallback
    continue to use the lightweight required dependency set.
    
    @return Tuple[bool, str] Tuple containing success flag and command output text.
    """
    command = [
        sys.executable,
        "-m",
        "pip",
        "install",
        "--user",
        "transformers>=4.40",
        "torch>=2.2",
        "torchvision>=0.17",
    ]
    try:
        completed = subprocess.run(
            command,
            check=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
    except Exception as exc:  # noqa: BLE001
        return False, str(exc)

    output = completed.stdout.strip() if completed.stdout else "pip produced no output"
    if completed.returncode == 0:
        try:
            importlib.import_module("torch")
            importlib.import_module("transformers")
        except Exception as exc:  # noqa: BLE001
            return False, f"pip finished, but semantic AI packages still cannot be imported by this process: {exc}\n{output}"
    return completed.returncode == 0, output


def image_has_alpha(image: Any) -> bool:
    """
    Return whether a Pillow image contains transparency that matters for output.
    
    @param Any image: Pillow image instance.
    @return bool True when the image has an alpha channel or palette transparency.
    """
    return image.mode in {"RGBA", "LA"} or (image.mode == "P" and "transparency" in image.info)


def prepare_jpeg_image(image: Any) -> Any:
    """
    Convert a Pillow image to the RGB canvas expected by JPEG output.
    
    Transparent pixels are composited over white to match the gallery server's
    JPEG thumbnail behavior.
    
    @param Any image: Pillow image instance.
    @return Any RGB Pillow image suitable for JPEG encoding.
    """
    if image_has_alpha(image):
        rgba = image.convert("RGBA")
        background = Image.new("RGB", rgba.size, (255, 255, 255))
        background.paste(rgba, mask=rgba.getchannel("A"))
        return background
    return image.convert("RGB")


def prepare_webp_image(image: Any) -> Any:
    """
    Convert a Pillow image to a WebP-friendly mode while preserving alpha.
    
    @param Any image: Pillow image instance.
    @return Any Pillow image suitable for WebP encoding.
    """
    if image_has_alpha(image):
        return image.convert("RGBA")
    return image.convert("RGB")


def generate_local_thumbnails(source_path: Path, output_root: Path, client_upload_id: str) -> List[LocalThumbnail]:
    """
    Generate PHP Gallery responsive thumbnail variants on the client computer.
    
    The size list and naming intent mirror PHP Gallery's thumbnail service:
    300, 600, 800, 960, 1280, and 1600 pixels on the long side, emitted as JPG
    and WebP. The server decides where those files belong after it stores the
    original and resolves the final image record.
    
    @param Path source_path: Original image selected for manual upload.
    @param Path output_root: Temporary parent directory for generated files.
    @param str client_upload_id: Request-local ID shared with the server metadata.
    @return List[LocalThumbnail] Generated thumbnail descriptors.
    @raises RuntimeError: Raised when Pillow is unavailable.
    @raises OSError: Propagated when files cannot be read or written.
    @raises Exception: Propagated when Pillow cannot decode or encode the image.
    """
    if not local_thumbnail_supported():
        raise RuntimeError("Client-side thumbnails require Pillow. Install it with: python -m pip install Pillow")

    target_dir = output_root / client_upload_id
    target_dir.mkdir(parents=True, exist_ok=True)
    thumbnails: List[LocalThumbnail] = []

    with Image.open(source_path) as opened:
        try:
            opened.seek(0)
        except EOFError:
            pass
        source = ImageOps.exif_transpose(opened)
        stem = source_path.stem.replace('"', "_")

        for size in THUMBNAIL_SIZES:
            resized = source.copy()
            resized.thumbnail((size, size), Image.Resampling.LANCZOS)

            jpeg_path = target_dir / f"{stem}_thumb{size}.jpg"
            jpeg_image = prepare_jpeg_image(resized)
            jpeg_image.save(jpeg_path, "JPEG", quality=82, optimize=True, progressive=True)
            thumbnails.append(LocalThumbnail(path=jpeg_path, size=size, format="jpg"))

            webp_path = target_dir / f"{stem}_thumb{size}.webp"
            webp_image = prepare_webp_image(resized)
            webp_image.save(webp_path, "WEBP", quality=82, method=6)
            thumbnails.append(LocalThumbnail(path=webp_path, size=size, format="webp"))

    return thumbnails


class PeriodicAIHeartbeat(threading.Thread):
    """
    Sends periodic lease heartbeats while one AI job is being processed.

    The worker thread owns the actual image analysis. This helper only extends
    the server lease, which protects long external model runs from being claimed
    by another companion app after the original lease expires.
    """

    def __init__(
        self,
        upload_url: str,
        api_key: str,
        job_id: int,
        claim_token: str,
        lease_seconds: int,
        progress_callback: Any,
        emit: Any,
    ) -> None:
        """
        Create a heartbeat thread for one claimed job.
        
        @param str upload_url: Normalized upload automation endpoint.
        @param str api_key: Gallery-scoped API key.
        @param int job_id: Server job id from the claim response.
        @param str claim_token: Server claim token returned with the job.
        @param int lease_seconds: Requested lease extension length.
        @param Any progress_callback: Callable returning progress percent and text.
        @param Any emit: Status callback receiving level and message.
        """
        super().__init__(name="PHPGalleryAIHeartbeat", daemon=True)
        self.upload_url = upload_url
        self.api_key = api_key
        self.job_id = job_id
        self.claim_token = claim_token
        self.lease_seconds = lease_seconds
        self.progress_callback = progress_callback
        self.emit = emit
        self.stop_event = threading.Event()
        self.interval = max(AI_HEARTBEAT_MIN_SECONDS, min(120.0, lease_seconds / 3.0))

    def stop(self) -> None:
        """
        Request heartbeat shutdown.
        """
        self.stop_event.set()

    def run(self) -> None:
        """
        Send heartbeats until stopped.
        """
        while not self.stop_event.wait(self.interval):
            try:
                progress_percent, message = self.progress_callback()
                post_json(
                    self.upload_url,
                    self.api_key,
                    {
                        "action": "ai_heartbeat",
                        "job_id": self.job_id,
                        "claim_token": self.claim_token,
                        "lease_seconds": self.lease_seconds,
                        "progress_percent": int(progress_percent),
                        "message": str(message),
                    },
                    timeout_seconds=30,
                )
            except Exception as exc:  # noqa: BLE001
                self.emit("warning", f"AI worker heartbeat failed for job {self.job_id}: {exc}")


class AIImageAnalyzer:
    """
    Local image-analysis adapter used by the optional AI metadata worker.

    The default implementation is deliberately dependency-light and relies on
    Pillow because the companion app already supports it. Operators who run a
    real local vision model can provide an external command that receives the
    downloaded image path and writes JSON to stdout.
    """

    def __init__(self, config: WatcherConfig) -> None:
        """
        Create an analyzer adapter from the current worker configuration.
        
        @param WatcherConfig config: Worker configuration captured at start time.
        """
        self.config = config
        self.external_command = config.ai_external_command.strip()
        self.vision_backend = normalize_ai_vision_backend(config.ai_vision_backend)
        self.transformers_caption_model = config.ai_transformers_caption_model.strip() or AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT
        self.transformers_detector_model = config.ai_transformers_detector_model.strip() or AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT
        self.transformers_object_labels = config.ai_transformers_object_labels.strip() or AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT
        self.transformers_detection_threshold = max(0.01, min(0.95, float(config.ai_transformers_detection_threshold or AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT)))
        self.ollama_url = config.ai_ollama_url.strip().rstrip("/") or AI_OLLAMA_URL_DEFAULT
        self.ollama_model = config.ai_ollama_model.strip() or AI_OLLAMA_MODEL_DEFAULT
        self.semantic_prompt = config.ai_semantic_prompt.strip() or AI_SEMANTIC_PROMPT_DEFAULT

    def analyze(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Analyze one downloaded image asset and return internal metadata.
        
        @param Path image_path: Local path to the claimed image asset.
        @param Dict[str, Any] job: Job payload returned by the PHP Gallery server.
        @return Tuple[Dict[str, Any], str] Tuple of metadata dictionary and explicit searchable text.
        @raises RuntimeError: Raised when neither the external command nor the built-in Pillow analysis can produce metadata.
        """
        if self.vision_backend == "external":
            if not self.external_command:
                raise RuntimeError("The external AI backend is selected, but no external analyzer command is configured.")
            return self.analyze_with_external_command(image_path, job)
        if self.vision_backend == "transformers":
            return self.analyze_with_transformers(image_path, job)
        if self.vision_backend == "ollama":
            return self.analyze_with_ollama(image_path, job)
        if self.vision_backend == "pillow":
            return self.analyze_with_pillow(image_path, job)
        if self.external_command:
            return self.analyze_with_external_command(image_path, job)
        try:
            return self.analyze_with_transformers(image_path, job)
        except Exception as exc:  # noqa: BLE001
            logging.info("Local Transformers backend unavailable, using next AI fallback: %s", exc)
        try:
            return self.analyze_with_ollama(image_path, job)
        except Exception as exc:  # noqa: BLE001
            logging.info("Local Ollama backend unavailable, using Pillow fallback: %s", exc)
        return self.analyze_with_pillow(image_path, job)

    def analyze_with_external_command(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Run an operator-provided local analyzer command.
        
        The command must print a JSON object to stdout. Accepted shapes are:
        {"metadata": {...}, "searchable_text": "..."} or any JSON object, which
        will be stored directly as metadata.
        
        @param Path image_path: Local path to the downloaded image.
        @param Dict[str, Any] job: Server job payload.
        @return Tuple[Dict[str, Any], str] Tuple of metadata dictionary and explicit searchable text.
        @raises RuntimeError: Raised when the command fails or returns bad JSON.
        """
        image = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        command = self.external_command
        # Placeholder replacement is intentionally simple so JSON braces in shell
        # commands are not accidentally parsed as Python format fields.
        command = command.replace("{image_path}", str(image_path))
        command = command.replace("{filename}", str(image.get("filename") or image_path.name))
        command = command.replace("{job_id}", str(job.get("job_id") or ""))
        completed = subprocess.run(
            command,
            shell=True,
            capture_output=True,
            text=True,
            timeout=AI_ANALYZER_TIMEOUT_SECONDS,
            check=False,
        )
        if completed.returncode != 0:
            stderr = (completed.stderr or completed.stdout or "").strip()
            raise RuntimeError(f"External AI analyzer failed with exit code {completed.returncode}: {stderr[:800]}")

        try:
            payload = json.loads(completed.stdout)
        except json.JSONDecodeError as exc:
            raise RuntimeError(f"External AI analyzer returned non-JSON output: {completed.stdout[:800]}") from exc
        if not isinstance(payload, dict):
            raise RuntimeError("External AI analyzer must return a JSON object.")

        metadata = payload.get("metadata") if isinstance(payload.get("metadata"), dict) else payload
        searchable_text = str(payload.get("searchable_text") or "")
        metadata.setdefault("analyzer", "external-command")
        metadata.setdefault("external_command_used", True)
        return metadata, searchable_text

    def analyze_with_transformers(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Produce semantic metadata inside this Python process with Transformers.
        
        This backend does not require a separate local server. It imports the
        optional Hugging Face Transformers and PyTorch packages directly, loads
        the configured models in-process, and analyzes the assigned image on the
        worker PC. First use may download model files into the normal local
        Hugging Face cache for the current Windows user.
        
        @param Path image_path: Local path to the downloaded image.
        @param Dict[str, Any] job: Server job payload.
        @return Tuple[Dict[str, Any], str] Tuple of metadata dictionary and searchable text.
        @raises RuntimeError: Raised when optional packages or models are absent.
        """
        if Image is None or ImageOps is None:
            raise RuntimeError("Pillow is required before the local Transformers backend can open images.")

        image_info = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        with Image.open(image_path) as opened:
            image = ImageOps.exif_transpose(opened).convert("RGB")
            caption = self.transformers_caption(image)
            detections = self.transformers_object_detections(image)

        objects = self.normalize_label_list([item["label"] for item in detections])
        caption_labels = self.labels_from_caption(caption, self.transformers_candidate_labels())
        labels = sorted(set(objects + caption_labels))
        internal_description = caption.strip().rstrip(".")
        if objects:
            internal_description += "; objects: " + ", ".join(objects[:16])
        if not internal_description:
            internal_description = "semantic image metadata generated locally"

        metadata = {
            "analyzer": "local-transformers-vision-metadata",
            "analyzer_version": "1",
            "backend": "transformers",
            "caption_model": self.transformers_caption_model,
            "detector_model": self.transformers_detector_model,
            "detection_threshold": self.transformers_detection_threshold,
            "internal_description": internal_description,
            "caption": caption,
            "labels": labels,
            "objects": objects,
            "detections": detections[:40],
            "source": {
                "filename": str(image_info.get("filename") or image_path.name),
                "image_id": int(image_info.get("id") or 0),
                "checksum_sha256": str(image_info.get("checksum_sha256") or ""),
            },
        }
        searchable_parts = [
            internal_description,
            caption,
            " ".join(labels),
            " ".join(objects),
            str(image_info.get("filename") or ""),
        ]
        searchable_text = " ".join(part for part in searchable_parts if part).strip()
        return metadata, searchable_text

    def transformers_caption(self, image: Any) -> str:
        """
        Return one concise caption from the configured in-process caption model.
        
        @param Any image: RGB Pillow image.
        @return str Caption text, lower-case and whitespace-normalized.
        @raises RuntimeError: Raised when the model cannot run.
        """
        last_error: Optional[Exception] = None
        for task in ("image-to-text", "image-text-to-text"):
            try:
                pipeline = self.transformers_pipeline(task, self.transformers_caption_model)
                try:
                    result = pipeline(image, max_new_tokens=60)
                except TypeError:
                    result = pipeline(image)
                return self.transformers_caption_text_from_result(result)
            except Exception as exc:  # noqa: BLE001
                last_error = exc
                logging.info("Local Transformers caption task %s unavailable: %s", task, exc)
        raise RuntimeError(f"Local Transformers caption model failed: {last_error}")

    def transformers_caption_text_from_result(self, result: Any) -> str:
        """
        Extract normalized caption text from multiple Transformers result shapes.
        
        Transformers versions differ in their preferred image captioning task and
        output field names. This helper keeps the analyzer tolerant while keeping
        diagnostic details out of metadata sent to the gallery server.
        
        @param Any result: Raw pipeline result returned by Transformers.
        @return str Lower-case, whitespace-normalized caption text.
        """
        if isinstance(result, list) and result:
            first = result[0]
            if isinstance(first, dict):
                text = str(first.get("generated_text") or first.get("caption") or first.get("text") or "")
            else:
                text = str(first)
        elif isinstance(result, dict):
            text = str(result.get("generated_text") or result.get("caption") or result.get("text") or "")
        else:
            text = str(result or "")
        return " ".join(text.lower().split())

    def transformers_object_detections(self, image: Any) -> List[Dict[str, Any]]:
        """
        Return zero-shot object detections from the configured local model.
        
        The detector is optional even when the Transformers backend is selected.
        If the detector model fails after a caption has been produced, the
        analyzer keeps the caption result and stores a diagnostic object label
        only in local logs through the raised message when no caption exists.
        
        @param Any image: RGB Pillow image.
        @return List[Dict[str, Any]] List of compact detection dictionaries.
        """
        labels = self.transformers_candidate_labels()
        if not labels:
            return []
        try:
            pipeline = self.transformers_pipeline("zero-shot-object-detection", self.transformers_detector_model)
            result = pipeline(image, candidate_labels=labels, threshold=self.transformers_detection_threshold)
        except TypeError:
            result = pipeline(image, candidate_labels=labels)
        except Exception as exc:  # noqa: BLE001
            logging.info("Local Transformers detector unavailable: %s", exc)
            return []

        detections: List[Dict[str, Any]] = []
        if not isinstance(result, list):
            return detections
        for item in result:
            if not isinstance(item, dict):
                continue
            score = float(item.get("score") or 0.0)
            if score < self.transformers_detection_threshold:
                continue
            label = " ".join(str(item.get("label") or "").lower().split())
            if not label:
                continue
            box = item.get("box") if isinstance(item.get("box"), dict) else {}
            detections.append({
                "label": label,
                "score": round(score, 4),
                "box": {
                    "xmin": int(float(box.get("xmin") or 0)),
                    "ymin": int(float(box.get("ymin") or 0)),
                    "xmax": int(float(box.get("xmax") or 0)),
                    "ymax": int(float(box.get("ymax") or 0)),
                } if box else {},
            })
        detections.sort(key=lambda item: float(item.get("score") or 0.0), reverse=True)
        return detections[:40]

    def transformers_pipeline(self, task: str, model_name: str) -> Any:
        """
        Load and cache one Transformers pipeline inside the current process.
        
        @param str task: Transformers pipeline task name.
        @param str model_name: Hugging Face model identifier or local model path.
        @return Any Pipeline callable.
        @raises RuntimeError: Raised when optional packages are not installed.
        """
        cache_key = (task, model_name)
        with _AI_TRANSFORMERS_LOCK:
            if cache_key in _AI_TRANSFORMERS_PIPELINES:
                return _AI_TRANSFORMERS_PIPELINES[cache_key]
            try:
                transformers = importlib.import_module("transformers")
                importlib.import_module("torch")
            except Exception as exc:  # noqa: BLE001
                raise RuntimeError(
                    "Local Transformers backend needs optional packages. Use the AI tab button "
                    "or run: python -m pip install --user transformers torch torchvision"
                ) from exc
            try:
                pipeline = transformers.pipeline(task, model=model_name)
            except Exception as exc:  # noqa: BLE001
                raise RuntimeError(f"Could not load local Transformers model {model_name!r} for {task}: {exc}") from exc
            _AI_TRANSFORMERS_PIPELINES[cache_key] = pipeline
            return pipeline

    def transformers_candidate_labels(self) -> List[str]:
        """
        Return normalized object labels for local zero-shot detection.
        
        @return List[str] Deduplicated candidate label list.
        """
        return self.normalize_label_list(self.transformers_object_labels)

    def labels_from_caption(self, caption: str, candidates: List[str]) -> List[str]:
        """
        Extract configured search labels explicitly mentioned in a caption.
        
        @param str caption: Caption text from the local caption model.
        @param List[str] candidates: Candidate labels configured for object detection.
        @return List[str] Labels found in the caption.
        """
        caption_text = " " + " ".join(caption.lower().replace("-", " ").split()) + " "
        found: List[str] = []
        for label in candidates:
            label_text = " ".join(label.lower().replace("-", " ").split())
            if not label_text:
                continue
            plural = label_text + "s"
            if f" {label_text} " in caption_text or f" {plural} " in caption_text:
                if label_text not in found:
                    found.append(label_text)
        return found[:40]

    def analyze_with_ollama(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Produce semantic image metadata with a local Ollama vision model.
        
        This backend keeps heavy analysis on the Windows machine. It contacts
        only the configured local Ollama endpoint and never sends image bytes to
        PHP Gallery except for the final internal metadata result.
        
        @param Path image_path: Local path to the downloaded image.
        @param Dict[str, Any] job: Server job payload.
        @return Tuple[Dict[str, Any], str] Tuple of metadata dictionary and searchable text.
        @raises RuntimeError: Raised when Ollama is unavailable or returns bad data.
        """
        image_info = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        with image_path.open("rb") as handle:
            encoded_image = base64.b64encode(handle.read()).decode("ascii")

        payload = {
            "model": self.ollama_model,
            "prompt": self.semantic_prompt,
            "images": [encoded_image],
            "stream": False,
            "format": "json",
            "options": {
                "temperature": 0.1,
                "num_predict": 512,
            },
        }
        body = json.dumps(payload).encode("utf-8")
        req = request.Request(
            self.ollama_url + "/api/generate",
            data=body,
            headers={"Content-Type": "application/json", "Accept": "application/json"},
            method="POST",
        )
        try:
            with request.urlopen(req, timeout=AI_ANALYZER_TIMEOUT_SECONDS) as response:
                response_payload = json.loads(response.read().decode("utf-8", "replace"))
        except error.URLError as exc:
            raise RuntimeError(f"Ollama vision backend is unavailable at {self.ollama_url}: {exc}") from exc
        except json.JSONDecodeError as exc:
            raise RuntimeError("Ollama vision backend returned invalid JSON.") from exc

        raw_response = str(response_payload.get("response") or "").strip()
        semantic = self.parse_semantic_json(raw_response)
        labels = self.normalize_label_list(semantic.get("labels"))
        objects = self.normalize_label_list(semantic.get("objects"))
        activities = self.normalize_label_list(semantic.get("activities"))
        scene = str(semantic.get("scene") or "").strip().lower()
        recognized_text = str(semantic.get("text") or "").strip()
        internal_description = str(semantic.get("internal_description") or "").strip()
        if not internal_description:
            internal_description = self.semantic_description_from_parts(scene, objects, activities, labels)
        if not internal_description:
            internal_description = "semantic image metadata generated locally"

        metadata = {
            "analyzer": "local-ollama-vision-metadata",
            "analyzer_version": "1",
            "backend": "ollama",
            "ollama_url": self.ollama_url,
            "ollama_model": self.ollama_model,
            "internal_description": internal_description,
            "labels": sorted(set(labels + objects + activities + ([scene] if scene else []))),
            "objects": objects,
            "scene": scene,
            "activities": activities,
            "text": recognized_text,
            "confidence_notes": str(semantic.get("confidence_notes") or "").strip(),
            "source": {
                "filename": str(image_info.get("filename") or image_path.name),
                "image_id": int(image_info.get("id") or 0),
                "checksum_sha256": str(image_info.get("checksum_sha256") or ""),
            },
        }
        searchable_parts = [
            internal_description,
            scene,
            recognized_text,
            " ".join(metadata["labels"]),
            str(image_info.get("filename") or ""),
        ]
        searchable_text = " ".join(part for part in searchable_parts if part).strip()
        return metadata, searchable_text

    def parse_semantic_json(self, raw_response: str) -> Dict[str, Any]:
        """
        Decode the JSON object produced by a local vision model.
        
        Vision models sometimes wrap JSON in explanatory text despite explicit
        prompting. This parser first tries strict JSON, then extracts the first
        object-shaped range as a practical recovery path.
        
        @param str raw_response: Raw response text from the local model.
        @return Dict[str, Any] Decoded dictionary.
        @raises RuntimeError: Raised when no JSON object can be decoded.
        """
        try:
            decoded = json.loads(raw_response)
        except json.JSONDecodeError:
            start = raw_response.find("{")
            end = raw_response.rfind("}")
            if start < 0 or end <= start:
                raise RuntimeError(f"Ollama vision backend did not return a JSON object: {raw_response[:500]}")
            try:
                decoded = json.loads(raw_response[start:end + 1])
            except json.JSONDecodeError as exc:
                raise RuntimeError(f"Ollama vision backend returned unusable JSON: {raw_response[:500]}") from exc
        if not isinstance(decoded, dict):
            raise RuntimeError("Ollama vision backend JSON must be an object.")
        return decoded

    def normalize_label_list(self, value: Any) -> List[str]:
        """
        Normalize model-produced labels into short searchable strings.
        
        @param Any value: Raw JSON value, usually a list of strings.
        @return List[str] Deduplicated list of lower-case labels.
        """
        if isinstance(value, str):
            candidates = [part.strip() for part in value.replace(";", ",").split(",")]
        elif isinstance(value, list):
            candidates = [str(part).strip() for part in value]
        else:
            candidates = []
        labels: List[str] = []
        for candidate in candidates:
            label = " ".join(candidate.lower().split())
            if label and len(label) <= 80 and label not in labels:
                labels.append(label)
        return labels[:40]

    def semantic_description_from_parts(self, scene: str, objects: List[str], activities: List[str], labels: List[str]) -> str:
        """
        Create a compact fallback sentence from semantic model fields.
        
        @param str scene: Scene label returned by the model.
        @param List[str] objects: Visible object labels.
        @param List[str] activities: Visible activity labels.
        @param List[str] labels: General labels.
        @return str Compact internal description.
        """
        parts: List[str] = []
        if scene:
            parts.append(scene)
        if objects:
            parts.append("objects: " + ", ".join(objects[:12]))
        if activities:
            parts.append("activities: " + ", ".join(activities[:8]))
        if not parts and labels:
            parts.append(", ".join(labels[:12]))
        return "; ".join(parts)

    def analyze_with_pillow(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Produce internal searchable metadata using local Pillow inspection.
        
        This is a conservative fallback, not an authoritative human caption. It
        records technical and visual descriptors that help search find likely
        images while a stronger local model can be configured later.
        
        @param Path image_path: Local path to the downloaded image.
        @param Dict[str, Any] job: Server job payload.
        @return Tuple[Dict[str, Any], str] Tuple of metadata dictionary and searchable text.
        @raises RuntimeError: Raised when Pillow is unavailable or cannot read the file.
        """
        if Image is None or ImageOps is None or ImageStat is None:
            raise RuntimeError("Pillow is required for the built-in AI metadata analyzer. Use Install or repair dependencies.")

        image_info = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        with Image.open(image_path) as opened:
            image = ImageOps.exif_transpose(opened)
            width, height = image.size
            mode = image.mode
            fmt = str(opened.format or image_path.suffix.lstrip(".")).upper()
            rgb = image.convert("RGB")
            sample = rgb.copy()
            sample.thumbnail((256, 256))
            grayscale = sample.convert("L")
            luminance_stat = ImageStat.Stat(grayscale)
            rgb_stat = ImageStat.Stat(sample)
            mean_luminance = float(luminance_stat.mean[0]) if luminance_stat.mean else 0.0
            luminance_std = float(luminance_stat.stddev[0]) if luminance_stat.stddev else 0.0
            colorfulness = self.colorfulness_score(rgb_stat)
            dominant_colors = self.dominant_color_labels(sample)

        orientation = self.orientation_label(width, height)
        brightness = self.brightness_label(mean_luminance)
        contrast = self.contrast_label(luminance_std)
        color_label = self.colorfulness_label(colorfulness)
        labels = [orientation, brightness, contrast, color_label]
        labels.extend(dominant_colors)
        labels = [label for label in labels if label]
        internal_description = f"{orientation} image, {brightness}, {contrast}, {color_label}"
        if dominant_colors:
            internal_description += ", dominant colors: " + ", ".join(dominant_colors)

        metadata = {
            "analyzer": "local-pillow-visual-metadata",
            "analyzer_version": "1",
            "internal_description": internal_description,
            "labels": labels,
            "dominant_colors": dominant_colors,
            "technical": {
                "width": width,
                "height": height,
                "aspect_ratio": round(width / height, 4) if height else None,
                "orientation": orientation,
                "mode": mode,
                "format": fmt,
                "mean_luminance": round(mean_luminance, 2),
                "luminance_stddev": round(luminance_std, 2),
                "colorfulness": round(colorfulness, 2),
            },
            "source": {
                "filename": str(image_info.get("filename") or image_path.name),
                "image_id": int(image_info.get("id") or 0),
                "checksum_sha256": str(image_info.get("checksum_sha256") or ""),
            },
        }
        searchable_text = " ".join([internal_description, " ".join(labels), str(image_info.get("filename") or "")])
        return metadata, searchable_text

    def colorfulness_score(self, stat: Any) -> float:
        """
        Return a simple RGB dispersion score for colorfulness.
        
        @param Any stat: ImageStat.Stat instance for a sampled RGB image.
        @return float Approximate colorfulness score.
        """
        if not stat.mean or len(stat.mean) < 3:
            return 0.0
        red, green, blue = [float(value) for value in stat.mean[:3]]
        rg = abs(red - green)
        yb = abs(0.5 * (red + green) - blue)
        return math.sqrt((rg * rg) + (yb * yb))

    def dominant_color_labels(self, image: Any) -> List[str]:
        """
        Return compact color labels from a sampled image.
        
        @param Any image: PIL RGB image.
        @return List[str] List of color labels suitable for internal search.
        """
        adaptive_palette = getattr(Image, "ADAPTIVE", None)
        if adaptive_palette is None and hasattr(Image, "Palette"):
            adaptive_palette = getattr(Image.Palette, "ADAPTIVE", 1)
        if adaptive_palette is None:
            adaptive_palette = 1
        quantized = image.convert("P", palette=adaptive_palette, colors=5).convert("RGB")
        colors = quantized.getcolors(maxcolors=256 * 256) or []
        colors = sorted(colors, key=lambda item: item[0], reverse=True)[:5]
        labels: List[str] = []
        for _count, rgb in colors:
            label = self.rgb_label(rgb)
            if label and label not in labels:
                labels.append(label)
        return labels[:4]

    def rgb_label(self, rgb: Tuple[int, int, int]) -> str:
        """
        Convert one RGB color into a coarse human-readable label.
        
        @param Tuple[int, int, int] rgb: Red, green, and blue tuple.
        @return str Coarse color label.
        """
        red, green, blue = rgb
        maximum = max(red, green, blue)
        minimum = min(red, green, blue)
        if maximum < 45:
            return "black"
        if minimum > 215:
            return "white"
        if maximum - minimum < 24:
            if maximum < 95:
                return "dark gray"
            if maximum > 175:
                return "light gray"
            return "gray"
        if red >= green and red >= blue:
            if green > 140 and blue < 110:
                return "yellow"
            if blue > 120 and green < 120:
                return "magenta"
            return "red"
        if green >= red and green >= blue:
            if red > 130 and blue < 110:
                return "yellow-green"
            if blue > 120:
                return "cyan"
            return "green"
        if red > 120 and green < 120:
            return "purple"
        return "blue"

    def orientation_label(self, width: int, height: int) -> str:
        """
        Return landscape, portrait, square, or panorama from image dimensions.
        
        @param int width: Image width in pixels.
        @param int height: Image height in pixels.
        @return str Orientation label.
        """
        if width <= 0 or height <= 0:
            return "unknown orientation"
        ratio = width / height
        if ratio > 2.0:
            return "panorama"
        if ratio > 1.15:
            return "landscape"
        if ratio < 0.87:
            return "portrait"
        return "square"

    def brightness_label(self, luminance: float) -> str:
        """
        Return a coarse brightness label.
        
        @param float luminance: Mean luminance from 0 to 255.
        @return str Brightness label.
        """
        if luminance < 55:
            return "dark"
        if luminance < 115:
            return "dim"
        if luminance < 185:
            return "balanced brightness"
        return "bright"

    def contrast_label(self, stddev: float) -> str:
        """
        Return a coarse contrast label.
        
        @param float stddev: Luminance standard deviation.
        @return str Contrast label.
        """
        if stddev < 28:
            return "low contrast"
        if stddev > 72:
            return "high contrast"
        return "medium contrast"

    def colorfulness_label(self, score: float) -> str:
        """
        Return a coarse colorfulness label.
        
        @param float score: Approximate colorfulness score.
        @return str Colorfulness label.
        """
        if score < 18:
            return "muted colors"
        if score > 70:
            return "very colorful"
        return "colorful"


class AIAnalysisWorkerThread(threading.Thread):
    """
    Background worker that asks PHP Gallery for server-assigned AI jobs.

    It never scans the gallery by itself. The server is authoritative: each poll
    either returns one claimed job with a lease token or no job. This makes it
    safe to run several companion app instances against the same gallery key.
    """

    def __init__(self, config: WatcherConfig, events: "queue.Queue[Tuple[str, str]]") -> None:
        """
        Create a background AI-analysis worker.
        
        @param WatcherConfig config: Runtime configuration captured when the worker starts.
        @param queue.Queue[Tuple[str, str]] events: Queue used to send status lines back to the UI.
        """
        super().__init__(name="PHPGalleryAIAnalysisWorker", daemon=True)
        self.config = config
        self.events = events
        self.stop_event = threading.Event()
        self.worker_id = ai_worker_id()
        self.progress_lock = threading.Lock()
        self.progress_percent = 0
        self.progress_message = "Starting."

    def stop(self) -> None:
        """
        Request the worker loop to stop.
        """
        self.stop_event.set()

    def emit(self, level: str, message: str) -> None:
        """
        Send one worker event to the UI and file log.
        
        @param str level: Severity label.
        @param str message: Human-readable status text.
        """
        logging.log(logging.ERROR if level == "error" else logging.INFO, "%s", message)
        self.events.put((level, message))

    def update_progress(self, percent: int, message: str) -> None:
        """
        Store the current progress text used by heartbeat requests.
        
        @param int percent: Integer progress from 0 to 99 while running.
        @param str message: Short status text.
        """
        with self.progress_lock:
            self.progress_percent = max(0, min(99, int(percent)))
            self.progress_message = message

    def current_progress(self) -> Tuple[int, str]:
        """
        Return the latest heartbeat progress tuple.
        
        @return Tuple[int, str] Progress percent and message.
        """
        with self.progress_lock:
            return self.progress_percent, self.progress_message

    def run(self) -> None:
        """
        Poll the server and process claimed jobs until stopped.
        """
        enter_background_thread_mode()
        upload_url = normalize_upload_url(self.config.gallery_url)
        api_key = self.config.api_key.strip()
        if not self.config.ai_worker_enabled:
            self.emit("info", "AI metadata worker is disabled in configuration.")
            return
        if not upload_url or not api_key:
            self.emit("error", "AI metadata worker needs Gallery URL and API key.")
            return

        poll_seconds = max(AI_WORKER_MIN_POLL_SECONDS, float(self.config.ai_worker_poll_seconds or DEFAULT_AI_WORKER_POLL_SECONDS))
        lease_seconds = max(60, min(AI_WORKER_MAX_LEASE_SECONDS, int(self.config.ai_worker_lease_seconds or DEFAULT_AI_WORKER_LEASE_SECONDS)))
        model_name = self.config.ai_model_name.strip() or AI_MODEL_NAME_DEFAULT
        model_version = self.config.ai_model_version.strip() or AI_MODEL_VERSION_DEFAULT
        self.emit("info", f"AI metadata worker started as {self.worker_id} using {model_name} {model_version}.")

        while not self.stop_event.is_set():
            try:
                claimed = post_json(
                    upload_url,
                    api_key,
                    {
                        "action": "ai_next_job",
                        "worker_id": self.worker_id,
                        "model_name": model_name,
                        "model_version": model_version,
                        "lease_seconds": lease_seconds,
                    },
                    timeout_seconds=60,
                )
                job = claimed.get("job")
                if not isinstance(job, dict):
                    wait_seconds = float(claimed.get("poll_after_seconds") or poll_seconds)
                    self.update_progress(0, "No job available.")
                    self.stop_event.wait(max(AI_WORKER_MIN_POLL_SECONDS, wait_seconds))
                    continue
                self.process_job(upload_url, api_key, job, lease_seconds)
            except Exception as exc:  # noqa: BLE001
                self.emit("warning", f"AI metadata worker poll failed: {exc}")
                self.stop_event.wait(poll_seconds)

        self.emit("info", "AI metadata worker stopped.")

    def process_job(self, upload_url: str, api_key: str, job: Dict[str, Any], lease_seconds: int) -> None:
        """
        Download, analyze, and report one claimed AI job.
        
        @param str upload_url: Normalized upload automation endpoint.
        @param str api_key: Gallery-scoped API key.
        @param Dict[str, Any] job: Claimed job payload from the server.
        @param int lease_seconds: Requested lease extension length.
        """
        job_id = int(job.get("job_id") or 0)
        claim_token = str(job.get("claim_token") or "")
        image = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        filename = str(image.get("filename") or f"job-{job_id}.img")
        if job_id <= 0 or not claim_token:
            self.emit("warning", "AI metadata worker received an invalid job payload.")
            return

        heartbeat = PeriodicAIHeartbeat(upload_url, api_key, job_id, claim_token, lease_seconds, self.current_progress, self.emit)
        heartbeat.start()
        temp_dir = Path(tempfile.mkdtemp(prefix="php_gallery_ai_"))
        downloaded_path = temp_dir / ("source" + extension_for_content_type(str(image.get("mime_type") or ""), filename))
        try:
            self.update_progress(5, "Downloading assigned image.")
            self.emit("info", f"AI metadata worker claimed job {job_id} for {filename}.")
            content_type, downloaded = post_binary_to_file(
                upload_url,
                api_key,
                {
                    "action": "ai_asset",
                    "job_id": str(job_id),
                    "claim_token": claim_token,
                },
                downloaded_path,
                timeout_seconds=DEFAULT_TIMEOUT_SECONDS,
            )
            if downloaded <= 0:
                raise RuntimeError("Server returned an empty image asset.")
            corrected_suffix = extension_for_content_type(content_type, filename)
            if downloaded_path.suffix.lower() != corrected_suffix:
                corrected_path = temp_dir / ("source" + corrected_suffix)
                downloaded_path.rename(corrected_path)
                downloaded_path = corrected_path

            self.update_progress(35, "Analyzing image locally.")
            analyzer = AIImageAnalyzer(self.config)
            metadata, searchable_text = analyzer.analyze(downloaded_path, job)
            self.update_progress(90, "Reporting AI metadata.")
            post_json(
                upload_url,
                api_key,
                {
                    "action": "ai_complete",
                    "job_id": job_id,
                    "claim_token": claim_token,
                    "status": "succeeded",
                    "metadata": metadata,
                    "searchable_text": searchable_text,
                },
                timeout_seconds=60,
            )
            self.update_progress(100, "Completed.")
            self.emit("info", f"AI metadata stored for {filename}.")
        except Exception as exc:  # noqa: BLE001
            self.update_progress(0, "Reporting failure.")
            self.report_failure(upload_url, api_key, job_id, claim_token, filename, exc)
        finally:
            heartbeat.stop()
            heartbeat.join(timeout=2.0)
            shutil.rmtree(temp_dir, ignore_errors=True)

    def report_failure(self, upload_url: str, api_key: str, job_id: int, claim_token: str, filename: str, exc: Exception) -> None:
        """
        Report one failed job to the server without hiding local diagnostics.
        
        @param str upload_url: Normalized upload automation endpoint.
        @param str api_key: Gallery-scoped API key.
        @param int job_id: Failed server job id.
        @param str claim_token: Claim token returned with the job.
        @param str filename: Original filename for readable logging.
        @param Exception exc: Exception raised during processing.
        """
        message = str(exc)
        try:
            post_json(
                upload_url,
                api_key,
                {
                    "action": "ai_complete",
                    "job_id": job_id,
                    "claim_token": claim_token,
                    "status": "failed",
                    "error": message,
                },
                timeout_seconds=60,
            )
        except Exception as report_exc:  # noqa: BLE001
            self.emit("warning", f"AI metadata worker could not report failure for {filename}: {report_exc}")
        self.emit("warning", f"AI metadata worker failed for {filename}: {message}")


class WatcherThread(threading.Thread):
    """
    Background worker that scans, uploads, and reports status events.

    The worker uses polling rather than OS-specific filesystem notifications.
    Polling is less elegant, but it is robust on Windows network folders, synced
    folders, camera import folders, and other locations where notification APIs
    can behave inconsistently.
    """

    def __init__(self, config: WatcherConfig, events: "queue.Queue[Tuple[str, str]]") -> None:
        """
        Create a watcher worker.
        
        @param WatcherConfig config: Runtime configuration captured when the worker starts.
        @param queue.Queue[Tuple[str, str]] events: Thread-safe queue used to send status messages to the GUI.
        """
        super().__init__(daemon=True)
        self.config = config
        self.events = events
        self.stop_event = threading.Event()
        self.state = UploadState()
        self.stability = FileStabilityTracker(config.stable_seconds)
        self.remote_inventory = RemoteInventorySession(config.inventory_refresh_seconds, self.emit)
        self.remote_skipped_paths: Set[Tuple[Path, str]] = set()
        self.initial_paths: Set[Path] = set()

    def stop(self) -> None:
        """
        Request the worker to stop.
        """
        self.stop_event.set()

    def emit(self, level: str, message: str) -> None:
        """
        Send a message to the UI queue and the persistent log file.
        
        @param str level: Logging level name such as info, warning, or error.
        @param str message: Human-readable status message.
        """
        self.events.put((level, message))
        log_method = getattr(logging, level if level in {"debug", "info", "warning", "error"} else "info")
        log_method(message)

    def run(self) -> None:
        """
        Run the polling loop until stopped.
        
        Existing files are captured before the first scan and ignored for the
        lifetime of this worker. That makes the app behave like a live import
        bridge: only files added after Start watching are uploaded.
        """
        folder = Path(self.config.watched_folder)
        upload_url = normalize_upload_url(self.config.gallery_url)

        if not folder.is_dir():
            self.emit("error", f"Watched folder does not exist: {folder}")
            return
        if not upload_url or not self.config.api_key.strip():
            self.emit("error", "Gallery URL and API key are required.")
            return

        self.initial_paths = set(iter_candidate_files(folder))
        self.emit("info", f"Watching {folder}")
        self.emit("info", f"Upload endpoint: {upload_url}")
        self.emit("info", f"Remote inventory reconnect interval: {self.config.inventory_refresh_seconds:g} seconds.")
        if self.config.attach_sim_camera_metadata:
            self.emit("info", "Flight Simulator camera metadata enabled. " + SimConnectCameraClient(self.config.simconnect_dll_path).dll_resolution_message())
        else:
            self.emit("info", "Flight Simulator camera metadata disabled.")
        if self.initial_paths:
            self.emit("info", f"Ignoring {len(self.initial_paths)} existing image file(s); only files added after watcher start will upload.")

        while not self.stop_event.is_set():
            self.scan_once(folder, upload_url)
            self.stop_event.wait(max(0.2, self.config.scan_interval_seconds))

        self.emit("info", "Watcher stopped.")

    def scan_once(self, folder: Path, upload_url: str) -> None:
        """
        Scan the watched folder once and process files that are ready.
        
        @param Path folder: Folder to scan.
        @param str upload_url: Normalized endpoint used for uploads.
        """
        for path in iter_candidate_files(folder):
            if self.stop_event.is_set():
                return

            # Files already present when the worker started are intentionally
            # ignored. The watched folder can contain historical images without
            # causing a mass upload on startup.
            if path in self.initial_paths:
                continue

            if not self.stability.stable(path):
                continue

            try:
                file_hash = sha256_file(path)
                size = path.stat().st_size
            except OSError as exc:
                self.emit("warning", f"Cannot read {path.name}: {exc}")
                continue

            if (path, file_hash) in self.remote_skipped_paths:
                continue
            if self.state.already_uploaded_path(path, file_hash):
                continue
            if self.state.already_uploaded_hash(file_hash):
                self.state.mark_duplicate(path, file_hash, size)
                self.emit("info", f"Skipped duplicate content: {path.name}")
                continue

            # The remote gallery remains authoritative after restarts or lost
            # responses. Refreshing here creates a new short API connection before
            # the next upload when the planned reconnect interval has elapsed.
            candidate = inventory_candidate(path, file_hash, size)
            self.remote_inventory.refresh(upload_url, self.config.api_key.strip(), [candidate])
            if self.remote_inventory.has_hash(file_hash):
                self.remote_skipped_paths.add((path, file_hash))
                self.emit("info", f"Skipped already present on gallery: {path.name}")
                continue

            if not self.state.retry_allowed(path, file_hash):
                continue

            try:
                self.emit("info", f"Uploading {path.name}")
                metadata_fields = self.sim_camera_metadata_fields(path)
                payload = multipart_upload(upload_url, self.config.api_key.strip(), path, self.config.create_thumbnails, metadata_fields=metadata_fields)
                self.state.mark_uploaded(path, file_hash, size, payload)
                uploaded = payload.get("uploaded", 0)
                scanned = payload.get("scanned", 0)
                self.emit("info", f"Uploaded {path.name}: uploaded={uploaded}, scanned={scanned}")
                sim_result = payload.get("sim_camera_metadata")
                if isinstance(sim_result, dict):
                    attached_count = int(sim_result.get("attached", 0) or 0)
                    sim_error = str(sim_result.get("error", "") or "")
                    if attached_count > 0:
                        self.emit("info", f"Stored Flight Simulator camera location for {path.name}.")
                    elif sim_error:
                        self.emit("warning", f"Uploaded {path.name}, but camera location metadata was not stored: {sim_error}")
                if self.config.delete_uploaded_files:
                    self.delete_uploaded_file(path, payload)
            except Exception as exc:  # noqa: BLE001
                message = str(exc)
                if self.remote_inventory.confirm_after_failure(upload_url, self.config.api_key.strip(), candidate):
                    self.remote_skipped_paths.add((path, file_hash))
                    self.emit("warning", f"Upload response failed after transfer, but gallery inventory confirms {path.name} is already present; skipping retry. Original response error: {message}")
                    if self.config.delete_uploaded_files:
                        self.delete_uploaded_file(path, {"uploaded": 1, "filenames": [path.name]})
                    continue
                self.state.mark_failure(path, file_hash, message)
                self.emit("error", f"Upload failed for {path.name}: {message}")

    def sim_camera_metadata_fields(self, path: Path) -> Dict[str, str]:
        """
        Return Flight Simulator camera metadata fields for one watched upload.
        
        @param Path path: Local image path about to be uploaded.
        @return Dict[str, str] Multipart form fields, or an empty dictionary when unavailable.
        """
        if not self.config.attach_sim_camera_metadata:
            return {}

        location, message = SimConnectCameraClient(self.config.simconnect_dll_path).current_camera_location()
        if location is None:
            self.emit("info", f"Flight Simulator camera location unavailable for {path.name}: {message}")
            return {}

        self.emit(
            "info",
            (
                f"Attached Flight Simulator camera location for {path.name}: "
                f"lat={location.latitude:.7f}, lng={location.longitude:.7f}, alt={location.altitude:.2f} ft. "
                f"{message}"
            ),
        )
        return location.upload_fields()

    def delete_uploaded_file(self, path: Path, payload: Dict[str, Any]) -> None:
        """
        Delete a watched-folder file after a confirmed successful upload.
        
        The watcher deletes only originals that the gallery reports as uploaded.
        Skipped, duplicate, or failed files remain in place.
        
        @param Path path: Local image file that was just submitted.
        @param Dict[str, Any] payload: Successful JSON response returned by the gallery.
        """
        uploaded_count = int(payload.get("uploaded", 0) or 0)
        if uploaded_count <= 0:
            self.emit("warning", f"Kept {path.name}: gallery did not confirm a stored upload.")
            return

        try:
            path.unlink()
            self.initial_paths.discard(path)
            self.emit("info", f"Deleted uploaded source file: {path.name}")
        except FileNotFoundError:
            self.initial_paths.discard(path)
            self.emit("warning", f"Uploaded source file was already gone: {path.name}")
        except OSError as exc:
            self.emit("warning", f"Uploaded {path.name}, but could not delete the source file: {exc}")


class ManualUploadThread(threading.Thread):
    """
    Background worker for manual bulk uploads.

    The worker keeps manual uploading separate from watch-folder polling while
    reusing the same config object, endpoint normalizer, API-key header, and
    multipart upload function. Client-side thumbnail generation is optional and
    runs in a worker pool before each image is uploaded.
    """

    def __init__(
        self,
        config: WatcherConfig,
        paths: List[Path],
        client_thumbnails: bool,
        thumbnail_workers: int,
        upload_workers: int,
        events: "queue.Queue[Tuple[str, str]]",
    ) -> None:
        """
        Create a manual upload worker.
        
        @param WatcherConfig config: Shared connection configuration captured from the UI.
        @param List[Path] paths: Image paths selected by the user for manual upload.
        @param bool client_thumbnails: Whether to generate responsive thumbnails on.
        @param int thumbnail_workers: Process count used for local thumbnail work.
        @param int upload_workers: Thread count used for concurrent HTTP uploads.
        @param queue.Queue[Tuple[str, str]] events: Thread-safe queue used to send status messages to the GUI.
        """
        super().__init__(daemon=True)
        self.config = config
        self.paths = paths
        self.client_thumbnails = client_thumbnails
        self.thumbnail_workers = resolve_thumbnail_worker_count(thumbnail_workers)
        self.upload_workers = resolve_upload_worker_count(upload_workers)
        self.events = events
        self.stop_event = threading.Event()
        self.uploaded = 0
        self.failed = 0
        self.skipped_existing = 0
        self.completed_uploads = 0
        self.progress_lock = threading.Lock()
        self.remote_inventory = RemoteInventorySession(config.inventory_refresh_seconds, self.emit)

    def stop(self) -> None:
        """
        Request the manual upload worker to stop after the current item.
        """
        self.stop_event.set()

    def emit(self, level: str, message: str) -> None:
        """
        Send a status message to the GUI and persistent log.
        
        @param str level: Logging level name such as info, warning, or error.
        @param str message: Human-readable status message.
        """
        self.events.put((level, message))
        log_method = getattr(logging, level if level in {"debug", "info", "warning", "error"} else "info")
        log_method(message)

    def run(self) -> None:
        """
        Run the selected manual upload mode.
        """
        upload_url = normalize_upload_url(self.config.gallery_url)
        if not upload_url or not self.config.api_key.strip():
            self.emit("error", "Gallery URL and API key are required.")
            return
        if not self.paths:
            self.emit("warning", "No manual upload files were selected.")
            return

        self.emit("info", f"Manual upload started: {len(self.paths)} file(s).")
        self.emit("info", f"Upload endpoint: {upload_url}")
        self.emit("info", f"Remote inventory reconnect interval: {self.config.inventory_refresh_seconds:g} seconds.")

        self.preload_remote_inventory(upload_url)

        if self.client_thumbnails:
            self.run_with_client_thumbnails(upload_url)
        else:
            self.run_with_server_thumbnails(upload_url)

        self.emit("info", f"Manual upload finished: uploaded={self.uploaded}, skipped_existing={self.skipped_existing}, failed={self.failed}.")

    def preload_remote_inventory(self, upload_url: str) -> None:
        """
        Ask the gallery which selected files are already present before a batch.
        
        @param str upload_url: Normalized PHP Gallery upload endpoint.
        """
        candidates: List[Dict[str, Any]] = []
        for path in self.paths:
            if self.stop_event.is_set():
                return
            try:
                candidates.append(inventory_candidate(path))
            except OSError as exc:
                self.emit("warning", f"Cannot include {path.name} in remote inventory preload: {exc}")
        if candidates:
            self.remote_inventory.refresh(upload_url, self.config.api_key.strip(), candidates, force=True)

    def remote_skip_before_work(self, upload_url: str, path: Path, file_hash: str, size: int) -> bool:
        """
        Return whether an upload should be skipped because the gallery has it.
        
        @param str upload_url: Normalized PHP Gallery upload endpoint.
        @param Path path: Local image path being considered.
        @param str file_hash: SHA-256 hash of the local image.
        @param int size: Local file size in bytes.
        @return bool True when the remote gallery confirms the file already exists.
        """
        candidate = inventory_candidate(path, file_hash, size)
        self.remote_inventory.refresh(upload_url, self.config.api_key.strip(), [candidate])
        if not self.remote_inventory.has_hash(file_hash):
            return False
        with self.progress_lock:
            self.skipped_existing += 1
            self.completed_uploads += 1
        self.emit("info", f"Skipped already present on gallery: {path.name}")
        return True

    def run_with_server_thumbnails(self, upload_url: str) -> None:
        """
        Upload selected originals and ask PHP Gallery to create thumbnails.
        
        This mode still uses parallel HTTP uploads because multipart requests are
        network-bound. The worker count is intentionally modest so shared hosting
        is not overwhelmed by many simultaneous PHP upload requests.
        
        @param str upload_url: Normalized PHP Gallery upload endpoint.
        """
        self.emit("info", f"Uploading with {self.upload_workers} upload thread(s).")
        with concurrent.futures.ThreadPoolExecutor(max_workers=self.upload_workers) as executor:
            pending: Dict[concurrent.futures.Future[None], Path] = {}
            path_iterator = iter(enumerate(self.paths, start=1))
            queue_limit = max(1, self.upload_workers * 2)

            def submit_next() -> bool:
                """
                Submit one server-thumbnail upload when input remains.
                
                @return bool True when an upload task was queued.
                """
                try:
                    index, path = next(path_iterator)
                except StopIteration:
                    return False
                future = executor.submit(self.upload_one, upload_url, path, index, [], None, True)
                pending[future] = path
                return True

            for _ in range(queue_limit):
                if not submit_next():
                    break

            while pending:
                if self.stop_event.is_set():
                    self.emit("info", "Manual upload stopped by user.")
                    return
                done, _ = concurrent.futures.wait(pending.keys(), return_when=concurrent.futures.FIRST_COMPLETED)
                for future in done:
                    pending.pop(future)
                    try:
                        future.result()
                    except Exception as exc:  # noqa: BLE001
                        with self.progress_lock:
                            self.failed += 1
                        self.emit("error", f"Manual upload worker crashed: {exc}")
                    if not self.stop_event.is_set():
                        submit_next()

    def run_with_client_thumbnails(self, upload_url: str) -> None:
        """
        Generate thumbnails in separate processes and upload in parallel threads.
        
        This is a producer-consumer pipeline tuned for large manual batches. CPU
        heavy resize and encode work runs in a ProcessPoolExecutor so Windows can
        schedule it across many CPU cores. Network-bound multipart uploads run in
        a separate ThreadPoolExecutor. Thumbnail generation, uploading, and temp
        file cleanup overlap, but both queues remain bounded so a 500-photo batch
        does not fill the disk with completed thumbnail sets waiting to upload.
        
        @param str upload_url: Normalized PHP Gallery upload endpoint.
        """
        if not local_thumbnail_supported():
            self.emit("error", "Client-side thumbnails require Pillow. Run: python -m pip install Pillow")
            return

        temp_root = Path(tempfile.mkdtemp(prefix="php_gallery_thumbs_"))
        thumbnail_executor = concurrent.futures.ProcessPoolExecutor(max_workers=self.thumbnail_workers)
        upload_executor = concurrent.futures.ThreadPoolExecutor(max_workers=self.upload_workers)
        pending_thumbnails: Dict[concurrent.futures.Future[List[LocalThumbnail]], Tuple[int, Path, str]] = {}
        pending_uploads: Dict[concurrent.futures.Future[None], Tuple[Path, Path]] = {}
        path_iterator = iter(enumerate(self.paths, start=1))
        thumbnail_queue_limit = max(1, self.thumbnail_workers * 2)
        upload_queue_limit = max(1, self.upload_workers * 2)

        def submit_next_thumbnail() -> bool:
            """
            Submit one thumbnail job to the process pool when input remains.
            
            Files already confirmed by the remote gallery are consumed here
            without starting local thumbnail generation. The loop continues until
            it either queues real work or exhausts the selected file list.
            
            @return bool True when a thumbnail task was submitted.
            """
            while not self.stop_event.is_set():
                try:
                    index, path = next(path_iterator)
                except StopIteration:
                    return False
                try:
                    file_hash = sha256_file(path)
                    size = path.stat().st_size
                except OSError as exc:
                    with self.progress_lock:
                        self.failed += 1
                        self.completed_uploads += 1
                    self.emit("error", f"Cannot read {path.name}: {exc}")
                    continue
                if self.remote_skip_before_work(upload_url, path, file_hash, size):
                    continue
                client_upload_id = uuid.uuid4().hex
                future = thumbnail_executor.submit(generate_local_thumbnails, path, temp_root, client_upload_id)
                pending_thumbnails[future] = (index, path, client_upload_id)
                return True
            return False

        def submit_upload(
            path: Path,
            index: int,
            thumbnails: List[LocalThumbnail],
            client_upload_id: Optional[str],
            create_server_thumbnails: bool,
            cleanup_dir: Path,
        ) -> None:
            """
            Submit one upload job to the upload thread pool.
            
            @param Path path: Original image path to upload.
            @param int index: One-based item index for progress messages.
            @param List[LocalThumbnail] thumbnails: Local thumbnail files to include in the request.
            @param Optional[str] client_upload_id: Request-local ID mapping thumbnails to the.
            @param bool create_server_thumbnails: Whether the server should generate.
            @param Path cleanup_dir: Temporary directory to delete after upload.
            """
            future = upload_executor.submit(
                self.upload_one,
                upload_url,
                path,
                index,
                thumbnails,
                client_upload_id,
                create_server_thumbnails,
            )
            pending_uploads[future] = (path, cleanup_dir)

        def drain_completed_uploads(wait_for_one: bool) -> None:
            """
            Collect completed upload jobs and remove their temp directories.
            
            @param bool wait_for_one: When True, wait until at least one upload has.
            """
            if not pending_uploads:
                return
            timeout = None if wait_for_one else 0
            done, _ = concurrent.futures.wait(
                pending_uploads.keys(),
                timeout=timeout,
                return_when=concurrent.futures.FIRST_COMPLETED,
            )
            for future in done:
                _path, cleanup_dir = pending_uploads.pop(future)
                try:
                    future.result()
                except Exception as exc:  # noqa: BLE001
                    # upload_one already traps normal upload failures. This guard
                    # is for unexpected programming errors inside the upload thread.
                    with self.progress_lock:
                        self.failed += 1
                    self.emit("error", f"Manual upload worker crashed: {exc}")
                finally:
                    shutil.rmtree(cleanup_dir, ignore_errors=True)

        try:
            for _ in range(thumbnail_queue_limit):
                if not submit_next_thumbnail():
                    break

            self.emit(
                "info",
                f"Generating thumbnails with {self.thumbnail_workers} worker process(es) and uploading with {self.upload_workers} thread(s).",
            )

            while pending_thumbnails or pending_uploads:
                if self.stop_event.is_set():
                    self.emit("info", "Manual upload stopped by user.")
                    return

                while len(pending_uploads) >= upload_queue_limit:
                    drain_completed_uploads(wait_for_one=True)
                    if self.stop_event.is_set():
                        self.emit("info", "Manual upload stopped by user.")
                        return

                drain_completed_uploads(wait_for_one=False)

                if not pending_thumbnails:
                    drain_completed_uploads(wait_for_one=True)
                    continue

                done, _ = concurrent.futures.wait(
                    pending_thumbnails.keys(),
                    timeout=0.1,
                    return_when=concurrent.futures.FIRST_COMPLETED,
                )
                if not done:
                    continue

                for future in done:
                    index, path, client_upload_id = pending_thumbnails.pop(future)
                    thumbnail_dir = temp_root / client_upload_id
                    try:
                        thumbnails = future.result()
                        self.emit("info", f"Generated {len(thumbnails)} thumbnail file(s) for {path.name}.")
                        submit_upload(path, index, thumbnails, client_upload_id, False, thumbnail_dir)
                    except Exception as exc:  # noqa: BLE001
                        self.emit("warning", f"Local thumbnails failed for {path.name}; asking server to create them: {exc}")
                        submit_upload(path, index, [], None, True, thumbnail_dir)

                    if not self.stop_event.is_set() and len(pending_thumbnails) < thumbnail_queue_limit:
                        submit_next_thumbnail()

            drain_completed_uploads(wait_for_one=False)
        finally:
            thumbnail_executor.shutdown(wait=False, cancel_futures=True)
            upload_executor.shutdown(wait=True, cancel_futures=True)
            for _path, cleanup_dir in list(pending_uploads.values()):
                shutil.rmtree(cleanup_dir, ignore_errors=True)
            shutil.rmtree(temp_root, ignore_errors=True)

    def upload_one(
        self,
        upload_url: str,
        path: Path,
        index: int,
        thumbnails: List[LocalThumbnail],
        client_upload_id: Optional[str],
        create_server_thumbnails: bool,
    ) -> None:
        """
        Upload one original, optionally with locally generated thumbnails.
        
        @param str upload_url: Normalized PHP Gallery upload endpoint.
        @param Path path: Original image path to upload.
        @param int index: One-based item index for status messages.
        @param List[LocalThumbnail] thumbnails: Local thumbnail files to submit with the original.
        @param Optional[str] client_upload_id: Request-local ID used to map thumbnails to the.
        @param bool create_server_thumbnails: Whether PHP Gallery should generate.
        """
        try:
            file_hash = sha256_file(path)
            size = path.stat().st_size
            candidate = inventory_candidate(path, file_hash, size)
            self.remote_inventory.refresh(upload_url, self.config.api_key.strip(), [candidate])
            if self.remote_inventory.has_hash(file_hash):
                with self.progress_lock:
                    self.skipped_existing += 1
                    self.completed_uploads += 1
                self.emit("info", f"Skipped already present on gallery: {path.name}")
                return

            self.emit("info", f"Uploading {index}/{len(self.paths)}: {path.name}")
            payload = multipart_upload(
                upload_url,
                self.config.api_key.strip(),
                path,
                create_server_thumbnails,
                thumbnails=thumbnails,
                client_upload_id=client_upload_id,
            )
            with self.progress_lock:
                self.uploaded += int(payload.get("uploaded", 0) or 0)
                self.completed_uploads += 1
            installed = 0
            failed_thumbnails = 0
            thumbnail_errors: List[str] = []
            client_result = payload.get("client_thumbnails")
            if isinstance(client_result, dict):
                installed = int(client_result.get("installed", 0) or 0)
                failed_thumbnails = int(client_result.get("failed", 0) or 0)
                raw_errors = client_result.get("errors")
                if isinstance(raw_errors, list):
                    thumbnail_errors = [str(item) for item in raw_errors[:3]]
            self.emit("info", f"Uploaded {path.name}: uploaded={payload.get('uploaded', 0)}, scanned={payload.get('scanned', 0)}, client_thumbnails={installed}")
            if failed_thumbnails or thumbnail_errors:
                details = "; ".join(thumbnail_errors) if thumbnail_errors else "no detailed server message"
                self.emit("warning", f"Client thumbnails were partially rejected for {path.name}: failed={failed_thumbnails}; {details}")
        except Exception as exc:  # noqa: BLE001
            try:
                if "candidate" in locals() and self.remote_inventory.confirm_after_failure(upload_url, self.config.api_key.strip(), candidate):
                    with self.progress_lock:
                        self.skipped_existing += 1
                        self.completed_uploads += 1
                    self.emit("warning", f"Upload response failed after transfer, but gallery inventory confirms {path.name} is already present; skipping retry. Original response error: {exc}")
                    return
            except Exception as confirm_exc:  # noqa: BLE001
                self.emit("warning", f"Could not confirm remote inventory after failure for {path.name}: {confirm_exc}")
            with self.progress_lock:
                self.failed += 1
                self.completed_uploads += 1
            self.emit("error", f"Manual upload failed for {path.name}: {exc}")


class WatcherApp:
    """
    Tkinter user interface for the watched-folder uploader.

    The GUI is intentionally small: it collects configuration, starts and stops
    the worker, and mirrors worker events into a visible status log. Upload logic
    stays in WatcherThread so the same behavior can be reused by command-line
    mode.
    """

    def __init__(self) -> None:
        """
        Initialize the Tkinter application and load saved configuration.
        
        @raises RuntimeError: Raised when Tkinter is not available.
        """
        if tk is None or ttk is None or filedialog is None or messagebox is None:
            raise RuntimeError("Tkinter is not available in this Python installation.")

        self.root = tk.Tk()
        self.root.title(APP_DISPLAY_NAME)
        self.root.geometry("980x860")

        self.config_store = ConfigStore()
        self.config = self.config_store.load()
        self.events: "queue.Queue[Tuple[str, str]]" = queue.Queue()
        self.worker: Optional[WatcherThread] = None
        self.manual_worker: Optional[ManualUploadThread] = None
        self.ai_worker: Optional[AIAnalysisWorkerThread] = None
        self.ai_worker_stop_requested = False
        self.semantic_ai_install_running = False
        self.semantic_ai_install_button: Optional[Any] = None
        self.manual_paths: List[Path] = []
        self.tray_icon: Optional[Any] = None
        self.tray_thread: Optional[threading.Thread] = None
        self.window_hidden_to_tray = False
        self.exiting = False

        self.watched_folder_var = tk.StringVar(value=self.config.watched_folder)
        self.gallery_url_var = tk.StringVar(value=self.config.gallery_url)
        self.api_key_var = tk.StringVar(value=self.config.api_key)
        self.interval_var = tk.StringVar(value=str(self.config.scan_interval_seconds))
        self.stable_var = tk.StringVar(value=str(self.config.stable_seconds))
        self.create_thumbnails_var = tk.BooleanVar(value=self.config.create_thumbnails)
        self.attach_sim_camera_metadata_var = tk.BooleanVar(value=self.config.attach_sim_camera_metadata)
        self.simconnect_dll_path_var = tk.StringVar(value=self.config.simconnect_dll_path)
        self.delete_uploaded_files_var = tk.BooleanVar(value=self.config.delete_uploaded_files)
        self.manual_local_thumbnails_var = tk.BooleanVar(value=True)
        self.manual_thumbnail_workers_var = tk.StringVar(value=format_worker_choice(self.config.manual_thumbnail_workers))
        self.manual_upload_workers_var = tk.StringVar(value=format_worker_choice(self.config.manual_upload_workers))
        self.inventory_refresh_var = tk.StringVar(value=f"{self.config.inventory_refresh_seconds:g}")
        self.ai_worker_enabled_var = tk.BooleanVar(value=self.config.ai_worker_enabled)
        self.ai_worker_poll_var = tk.StringVar(value=f"{self.config.ai_worker_poll_seconds:g}")
        self.ai_worker_lease_var = tk.StringVar(value=str(self.config.ai_worker_lease_seconds))
        self.ai_model_name_var = tk.StringVar(value=self.config.ai_model_name)
        self.ai_model_version_var = tk.StringVar(value=self.config.ai_model_version)
        self.ai_external_command_var = tk.StringVar(value=self.config.ai_external_command)
        self.ai_vision_backend_var = tk.StringVar(value=normalize_ai_vision_backend(self.config.ai_vision_backend))
        self.ai_transformers_caption_model_var = tk.StringVar(value=self.config.ai_transformers_caption_model)
        self.ai_transformers_detector_model_var = tk.StringVar(value=self.config.ai_transformers_detector_model)
        self.ai_transformers_object_labels_var = tk.StringVar(value=self.config.ai_transformers_object_labels)
        self.ai_transformers_detection_threshold_var = tk.StringVar(value=f"{self.config.ai_transformers_detection_threshold:g}")
        self.ai_ollama_url_var = tk.StringVar(value=self.config.ai_ollama_url)
        self.ai_ollama_model_var = tk.StringVar(value=self.config.ai_ollama_model)
        self.ai_semantic_prompt_var = tk.StringVar(value=self.config.ai_semantic_prompt)
        self.ai_advanced_visible_var = tk.BooleanVar(value=False)
        self.ai_advanced_frame = None
        self.thumbnail_runtime_var = tk.StringVar(value=thumbnail_runtime_status())
        self.manual_selection_var = tk.StringVar(value="No files selected")
        self.status_var = tk.StringVar(value="Watcher stopped")
        self.manual_status_var = tk.StringVar(value="Manual upload idle")
        self.ai_status_var = tk.StringVar(value="AI metadata worker idle")
        self.monitor_state_var = tk.StringVar(value="Monitoring disabled")
        self.monitor_detail_var = tk.StringVar(value="No watcher is active.")
        self.monitor_state = "disabled"
        self.monitor_detail = "No watcher is active."
        self.log_tags_ready = False

        self.build_ui()
        self.configure_window_icon()
        self.start_tray_icon()
        self.root.protocol("WM_DELETE_WINDOW", self.request_window_close)
        self.root.bind("<Unmap>", self.handle_window_unmap, add="+")
        self.root.after(200, self.drain_events)

    def build_ui(self) -> None:
        """
        Create the visible controls and initial status text.
        """
        outer = ttk.Frame(self.root, padding=16)
        outer.pack(fill="both", expand=True)

        title = ttk.Label(outer, text=APP_DISPLAY_NAME, font=("Segoe UI", 16, "bold"))
        title.pack(anchor="w")
        subtitle = ttk.Label(
            outer,
            text="Uploads images through one gallery-scoped API key, either from a watched folder, from a manual selection, or from the optional AI metadata worker.",
        )
        subtitle.pack(anchor="w", pady=(2, 14))

        monitor_strip = ttk.Frame(outer)
        monitor_strip.pack(fill="x", pady=(0, 10))
        self.monitor_light = tk.Canvas(monitor_strip, width=16, height=16, highlightthickness=0, bd=0)
        self.monitor_light.pack(side="left", padx=(0, 8))
        ttk.Label(monitor_strip, textvariable=self.monitor_state_var).pack(side="left")
        ttk.Label(monitor_strip, textvariable=self.monitor_detail_var, foreground="#666666").pack(side="left", padx=(10, 0))

        connection = ttk.LabelFrame(outer, text="Shared connection settings")
        connection.pack(fill="x", pady=(0, 12))
        connection.columnconfigure(1, weight=1)
        connection.columnconfigure(3, weight=0)

        ttk.Label(connection, text="Gallery URL or upload endpoint").grid(row=0, column=0, sticky="w", padx=8, pady=6)
        ttk.Entry(connection, textvariable=self.gallery_url_var).grid(row=0, column=1, columnspan=2, sticky="ew", padx=8, pady=6)

        ttk.Label(connection, text="API key").grid(row=1, column=0, sticky="w", padx=8, pady=6)
        ttk.Entry(connection, textvariable=self.api_key_var, show="*").grid(row=1, column=1, columnspan=2, sticky="ew", padx=8, pady=6)

        ttk.Label(connection, text="Inventory reconnect seconds").grid(row=2, column=0, sticky="w", padx=8, pady=6)
        ttk.Entry(connection, textvariable=self.inventory_refresh_var, width=12).grid(row=2, column=1, sticky="w", padx=8, pady=6)
        ttk.Label(connection, text="Default 30. The app asks the gallery what already exists before continuing long batches.", foreground="#666666").grid(row=2, column=2, columnspan=2, sticky="w", padx=8, pady=6)

        ttk.Button(connection, text="Save configuration", command=self.save_config).grid(row=3, column=1, sticky="w", padx=8, pady=(4, 8))
        self.revoke_button = ttk.Button(connection, text="Revoke API key", command=self.revoke_api_key)
        self.revoke_button.grid(row=3, column=2, sticky="e", padx=8, pady=(4, 8))
        ttk.Button(connection, text="Open config folder", command=self.open_config_folder).grid(row=3, column=3, sticky="e", padx=8, pady=(4, 8))

        notebook = ttk.Notebook(outer)
        notebook.pack(fill="x", pady=(0, 12))

        watch_tab = ttk.Frame(notebook, padding=12)
        manual_tab = ttk.Frame(notebook, padding=12)
        ai_tab = ttk.Frame(notebook, padding=12)
        notebook.add(watch_tab, text="Watch folder")
        notebook.add(manual_tab, text="Manual upload")
        notebook.add(ai_tab, text="AI metadata")

        self.build_watch_tab(watch_tab)
        self.build_manual_tab(manual_tab)
        self.build_ai_tab(ai_tab)

        log_frame = ttk.LabelFrame(outer, text="Status log")
        log_frame.pack(fill="both", expand=True)
        self.log_text = tk.Text(log_frame, height=18, wrap="word")
        self.log_text.pack(fill="both", expand=True, padx=8, pady=8)
        self.log_text.configure(state="disabled")
        self.configure_log_tags()

        self.write_log(f"Configuration: {CONFIG_PATH}", "system")
        self.write_log(f"State: {STATE_PATH}", "system")
        self.write_log(f"Log: {LOG_PATH}", "system")
        self.write_log(thumbnail_runtime_status(), "system")
        self.refresh_revoke_button_state()
        self.update_monitor_state("disabled", "No watcher is active.")

    def configure_window_icon(self) -> None:
        """
        Apply the bundled Windows icon to the Tkinter window when available.
        """
        if not TRAY_ICON_ICO_PATH.is_file():
            return
        try:
            self.root.iconbitmap(default=str(TRAY_ICON_ICO_PATH))
        except Exception as exc:  # noqa: BLE001
            logging.warning("Window icon could not be loaded: %s", exc)

    def start_tray_icon(self) -> None:
        """
        Start the Windows tray icon in a background thread.
        """
        if pystray is None or Image is None:
            self.write_log("System tray unavailable. Run install.bat to install pystray and Pillow.", "warning")
            return
        if not TRAY_ICON_PNG_PATH.is_file():
            self.write_log(f"System tray icon asset is missing: {TRAY_ICON_PNG_PATH}", "warning")
            return

        try:
            with Image.open(TRAY_ICON_PNG_PATH) as opened:
                icon_image = opened.convert("RGBA").copy()
            menu = pystray.Menu(
                pystray.MenuItem("Open", self.tray_restore_window, default=True),
                pystray.MenuItem("Start watching", self.tray_start_watching),
                pystray.MenuItem("Stop watching", self.tray_stop_watching),
                pystray.MenuItem("Start AI metadata worker", self.tray_start_ai_worker),
                pystray.MenuItem("Stop AI metadata worker", self.tray_stop_ai_worker),
                pystray.Menu.SEPARATOR,
                pystray.MenuItem("Exit", self.tray_exit_application),
            )
            self.tray_icon = pystray.Icon(APP_NAME, icon_image, APP_DISPLAY_NAME, menu)
            self.tray_thread = threading.Thread(target=self.tray_icon.run, name="PHPGalleryTray", daemon=True)
            self.tray_thread.start()
            self.write_log("System tray icon is active.", "system")
        except Exception as exc:  # noqa: BLE001
            self.tray_icon = None
            self.tray_thread = None
            self.write_log(f"System tray could not be started: {exc}", "warning")

    def schedule_ui(self, callback: Any) -> None:
        """
        Run a tray callback on the Tkinter UI thread.
        
        @param Any callback: Callable with no arguments.
        """
        if self.exiting:
            return
        try:
            self.root.after(0, callback)
        except Exception:  # noqa: BLE001
            logging.debug("Ignored tray callback after Tk shutdown.", exc_info=True)

    def tray_restore_window(self, *_args: Any) -> None:
        """
        Restore the hidden window from a tray icon action.
        
        @param Any _args: Args value.
        """
        self.schedule_ui(self.restore_from_tray)

    def tray_start_watching(self, *_args: Any) -> None:
        """
        Start the watcher from the tray menu.
        
        @param Any _args: Args value.
        """
        self.schedule_ui(self.start)

    def tray_stop_watching(self, *_args: Any) -> None:
        """
        Stop the watcher from the tray menu.
        
        @param Any _args: Args value.
        """
        self.schedule_ui(self.stop)

    def tray_start_ai_worker(self, *_args: Any) -> None:
        """
        Start the optional AI metadata worker from the tray menu.
        
        @param Any _args: Args value.
        """
        self.schedule_ui(self.start_ai_worker)

    def tray_stop_ai_worker(self, *_args: Any) -> None:
        """
        Stop the optional AI metadata worker from the tray menu.
        
        @param Any _args: Args value.
        """
        self.schedule_ui(self.stop_ai_worker)

    def tray_exit_application(self, *_args: Any) -> None:
        """
        Exit the application from the tray menu.
        
        @param Any _args: Args value.
        """
        self.schedule_ui(self.close)

    def request_window_close(self) -> None:
        """
        Hide to tray when the window close button is used.
        """
        if not self.tray_icon:
            self.close()
            return

        if self.background_work_active():
            choice = messagebox.askyesnocancel(
                APP_DISPLAY_NAME,
                "Background work is still running. Choose Yes to hide to tray, No to stop work and exit, or Cancel to keep this window open.",
            )
            if choice is None:
                return
            if choice is False:
                self.close()
                return

        self.hide_to_tray()

    def background_work_active(self) -> bool:
        """
        Return whether any background worker is running.
        
        @return bool True when any background worker is alive.
        """
        return bool(
            (self.worker and self.worker.is_alive())
            or (self.manual_worker and self.manual_worker.is_alive())
            or (self.ai_worker and self.ai_worker.is_alive())
        )

    def handle_window_unmap(self, event: Any) -> None:
        """
        Convert normal window minimization into tray hiding.
        
        @param Any event: Tkinter unmap event.
        """
        if self.exiting or self.window_hidden_to_tray or event.widget is not self.root:
            return
        self.root.after(100, self.hide_if_minimized)

    def hide_if_minimized(self) -> None:
        """
        Hide the window to tray after a user minimize action.
        """
        if self.exiting or self.window_hidden_to_tray or not self.tray_icon:
            return
        try:
            if self.root.state() == "iconic":
                self.hide_to_tray()
        except Exception:  # noqa: BLE001
            logging.debug("Ignored minimize-to-tray check after Tk shutdown.", exc_info=True)

    def hide_to_tray(self) -> None:
        """
        Hide the window while keeping the app alive in the system tray.
        """
        if not self.tray_icon:
            return
        self.window_hidden_to_tray = True
        self.root.withdraw()
        self.write_log("Window hidden to tray. Use the tray icon to restore it.", "system")

    def restore_from_tray(self) -> None:
        """
        Show the Tkinter window from the tray icon.
        """
        if self.exiting:
            return
        self.window_hidden_to_tray = False
        self.root.deiconify()
        try:
            self.root.state("normal")
        except Exception:  # noqa: BLE001
            logging.debug("Could not force normal window state.", exc_info=True)
        self.root.lift()
        self.root.focus_force()

    def stop_tray_icon(self) -> None:
        """
        Stop the tray icon loop during application shutdown.
        """
        icon = self.tray_icon
        self.tray_icon = None
        if icon is None:
            return
        try:
            icon.visible = False
            icon.stop()
        except Exception:  # noqa: BLE001
            logging.debug("Tray icon shutdown failed.", exc_info=True)

    def build_watch_tab(self, parent: Any) -> None:
        """
        Build controls dedicated to watch-folder uploading.
        
        @param Any parent: Tkinter frame that receives the controls.
        """
        parent.columnconfigure(1, weight=1)

        ttk.Label(parent, text="Watched folder").grid(row=0, column=0, sticky="w", pady=5)
        ttk.Entry(parent, textvariable=self.watched_folder_var).grid(row=0, column=1, sticky="ew", padx=8, pady=5)
        ttk.Button(parent, text="Browse", command=self.browse_folder).grid(row=0, column=2, sticky="ew", pady=5)

        ttk.Label(parent, text="Scan interval seconds").grid(row=1, column=0, sticky="w", pady=5)
        ttk.Entry(parent, textvariable=self.interval_var, width=12).grid(row=1, column=1, sticky="w", padx=8, pady=5)

        ttk.Label(parent, text="Stable file seconds").grid(row=2, column=0, sticky="w", pady=5)
        ttk.Entry(parent, textvariable=self.stable_var, width=12).grid(row=2, column=1, sticky="w", padx=8, pady=5)

        ttk.Checkbutton(
            parent,
            text="Ask gallery to create thumbnails after watched-folder upload",
            variable=self.create_thumbnails_var,
        ).grid(row=3, column=1, columnspan=2, sticky="w", padx=8, pady=5)

        ttk.Checkbutton(
            parent,
            text="Attach current Flight Simulator camera location to watched-folder uploads",
            variable=self.attach_sim_camera_metadata_var,
        ).grid(row=4, column=1, columnspan=2, sticky="w", padx=8, pady=5)

        ttk.Label(parent, text="SimConnect.dll override (optional)").grid(row=5, column=0, sticky="w", pady=5)
        ttk.Entry(parent, textvariable=self.simconnect_dll_path_var).grid(row=5, column=1, sticky="ew", padx=8, pady=5)
        ttk.Button(parent, text="Browse", command=self.browse_simconnect_dll).grid(row=5, column=2, sticky="ew", pady=5)

        ttk.Checkbutton(
            parent,
            text="Delete watched-folder files after a confirmed successful upload",
            variable=self.delete_uploaded_files_var,
        ).grid(row=6, column=1, columnspan=2, sticky="w", padx=8, pady=5)

        actions = ttk.Frame(parent)
        actions.grid(row=7, column=0, columnspan=3, sticky="ew", pady=(10, 0))
        ttk.Button(actions, text="Start watching", command=self.start).pack(side="left")
        ttk.Button(actions, text="Stop", command=self.stop).pack(side="left", padx=8)
        ttk.Label(actions, textvariable=self.status_var).pack(side="right")

    def build_manual_tab(self, parent: Any) -> None:
        """
        Build controls dedicated to manual bulk uploading.
        
        @param Any parent: Tkinter frame that receives the controls.
        """
        parent.columnconfigure(0, weight=1)

        intro = ttk.Label(
            parent,
            text="Select photos manually and upload them into the same gallery target as the API key. Local thumbnail conversion uses separate worker processes; uploads use parallel network threads.",
            wraplength=880,
        )
        intro.grid(row=0, column=0, columnspan=4, sticky="w", pady=(0, 8))

        ttk.Button(parent, text="Add pictures", command=self.select_manual_files).grid(row=1, column=0, sticky="w", pady=5)
        ttk.Button(parent, text="Clear selection", command=self.clear_manual_files).grid(row=1, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(parent, textvariable=self.manual_selection_var).grid(row=1, column=2, columnspan=2, sticky="w", padx=8, pady=5)

        self.thumbnail_check = ttk.Checkbutton(
            parent,
            text="Generate responsive thumbnails on this PC before upload",
            variable=self.manual_local_thumbnails_var,
        )
        self.thumbnail_check.grid(row=2, column=0, columnspan=4, sticky="w", pady=5)

        performance = ttk.LabelFrame(parent, text="Manual upload performance")
        performance.grid(row=3, column=0, columnspan=4, sticky="ew", pady=(4, 8))
        performance.columnconfigure(4, weight=1)
        ttk.Label(performance, text="Thumbnail processes").grid(row=0, column=0, sticky="w", padx=8, pady=6)
        ttk.Combobox(
            performance,
            textvariable=self.manual_thumbnail_workers_var,
            values=worker_choice_values(MAX_THUMBNAIL_WORKERS),
            width=10,
            state="readonly",
        ).grid(row=0, column=1, sticky="w", padx=(0, 12), pady=6)
        ttk.Label(performance, text="Upload threads").grid(row=0, column=2, sticky="w", padx=8, pady=6)
        ttk.Combobox(
            performance,
            textvariable=self.manual_upload_workers_var,
            values=worker_choice_values(MAX_UPLOAD_WORKERS),
            width=10,
            state="readonly",
        ).grid(row=0, column=3, sticky="w", padx=(0, 12), pady=6)
        auto_text = f"Auto uses {automatic_thumbnail_worker_count()} thumbnail process(es) and {automatic_upload_worker_count()} upload thread(s)."
        ttk.Label(performance, text=auto_text).grid(row=0, column=4, sticky="w", padx=8, pady=6)

        runtime_row = ttk.Frame(parent)
        runtime_row.grid(row=4, column=0, columnspan=4, sticky="ew", pady=(0, 6))
        runtime_row.columnconfigure(0, weight=1)
        ttk.Label(runtime_row, textvariable=self.thumbnail_runtime_var, wraplength=760).grid(row=0, column=0, sticky="w")
        ttk.Button(runtime_row, text="Install or repair dependencies", command=self.repair_dependencies).grid(row=0, column=1, sticky="e", padx=(8, 0))

        self.refresh_thumbnail_controls()

        actions = ttk.Frame(parent)
        actions.grid(row=5, column=0, columnspan=4, sticky="ew", pady=(10, 0))
        ttk.Button(actions, text="Start manual upload", command=self.start_manual_upload).pack(side="left")
        ttk.Button(actions, text="Stop manual upload", command=self.stop_manual_upload).pack(side="left", padx=8)
        ttk.Label(actions, textvariable=self.manual_status_var).pack(side="right")

    def build_ai_tab(self, parent: Any) -> None:
        """
        Build controls for the optional AI metadata worker.
        
        The tab intentionally keeps the normal operator view small. The common
        workflow is enable, choose the local backend, install dependencies if
        needed, and start the worker. Low-level tuning fields stay available in
        a collapsible advanced section, but they do not dominate the UI.
        
        @param Any parent: Tkinter frame that receives the controls.
        """
        parent.columnconfigure(1, weight=1)

        intro = ttk.Label(
            parent,
            text="Optional server-assigned worker mode. The gallery server gives this PC one image job at a time. Heavy analysis runs locally and only internal searchable metadata is sent back.",
            wraplength=880,
        )
        intro.grid(row=0, column=0, columnspan=3, sticky="w", pady=(0, 8))

        ttk.Checkbutton(
            parent,
            text="Enable AI metadata worker on this PC",
            variable=self.ai_worker_enabled_var,
        ).grid(row=1, column=0, columnspan=3, sticky="w", pady=5)

        quick = ttk.LabelFrame(parent, text="Normal use")
        quick.grid(row=2, column=0, columnspan=3, sticky="ew", pady=(4, 8))
        quick.columnconfigure(1, weight=1)

        ttk.Label(quick, text="Vision backend").grid(row=0, column=0, sticky="w", padx=8, pady=6)
        ttk.OptionMenu(quick, self.ai_vision_backend_var, normalize_ai_vision_backend(self.ai_vision_backend_var.get()), *AI_VISION_BACKEND_CHOICES).grid(row=0, column=1, sticky="w", padx=8, pady=6)
        ttk.Label(quick, text="Use transformers for local semantic labels without a separate server. Auto keeps safe fallbacks.", foreground="#666666", wraplength=420).grid(row=0, column=2, sticky="w", padx=8, pady=6)

        ttk.Label(quick, text="Model version").grid(row=1, column=0, sticky="w", padx=8, pady=6)
        ttk.Entry(quick, textvariable=self.ai_model_version_var, width=24).grid(row=1, column=1, sticky="w", padx=8, pady=6)
        ttk.Label(quick, text="Change this when you want the server to treat results as a new generation.", foreground="#666666", wraplength=420).grid(row=1, column=2, sticky="w", padx=8, pady=6)

        runtime_row = ttk.Frame(quick)
        runtime_row.grid(row=2, column=0, columnspan=3, sticky="ew", padx=8, pady=(2, 8))
        runtime_row.columnconfigure(0, weight=1)
        ttk.Label(runtime_row, textvariable=self.thumbnail_runtime_var, wraplength=560).grid(row=0, column=0, sticky="w")
        self.semantic_ai_install_button = ttk.Button(runtime_row, text="Install local AI module", command=self.repair_semantic_ai_dependencies)
        self.semantic_ai_install_button.grid(row=0, column=1, sticky="e", padx=(8, 0))
        ttk.Button(runtime_row, text="Install or repair basic dependencies", command=self.repair_dependencies).grid(row=0, column=2, sticky="e", padx=(8, 0))

        advanced_toggle = ttk.Checkbutton(
            parent,
            text="Show advanced AI settings",
            variable=self.ai_advanced_visible_var,
            command=self.toggle_ai_advanced_settings,
        )
        advanced_toggle.grid(row=3, column=0, columnspan=3, sticky="w", pady=(0, 4))

        advanced = ttk.LabelFrame(parent, text="Advanced AI settings")
        self.ai_advanced_frame = advanced
        advanced.columnconfigure(1, weight=1)

        ttk.Label(advanced, text="Poll seconds when idle").grid(row=0, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_worker_poll_var, width=12).grid(row=0, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(advanced, text="Default 60. Larger values keep the server quieter.", foreground="#666666").grid(row=0, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Lease seconds per job").grid(row=1, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_worker_lease_var, width=12).grid(row=1, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(advanced, text="Default 600. Heartbeats extend the lease while analysis runs.", foreground="#666666").grid(row=1, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Model or analyzer name").grid(row=2, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_model_name_var).grid(row=2, column=1, sticky="ew", padx=8, pady=5)

        ttk.Label(advanced, text="Transformers caption model").grid(row=3, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_transformers_caption_model_var).grid(row=3, column=1, sticky="ew", padx=8, pady=5)
        ttk.Label(advanced, text="First use may download model files locally.", foreground="#666666", wraplength=360).grid(row=3, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Transformers object detector").grid(row=4, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_transformers_detector_model_var).grid(row=4, column=1, sticky="ew", padx=8, pady=5)
        ttk.Label(advanced, text="Zero-shot object labels such as person, bridge, guitar, house.", foreground="#666666").grid(row=4, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Detector threshold").grid(row=5, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_transformers_detection_threshold_var, width=12).grid(row=5, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(advanced, text="Default 0.18. Lower sees more objects but may add noise.", foreground="#666666").grid(row=5, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Object labels").grid(row=6, column=0, sticky="nw", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_transformers_object_labels_var).grid(row=6, column=1, columnspan=2, sticky="ew", padx=8, pady=5)

        ttk.Label(advanced, text="Local Ollama URL").grid(row=7, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_ollama_url_var).grid(row=7, column=1, sticky="ew", padx=8, pady=5)
        ttk.Label(advanced, text="Optional fallback when you intentionally run Ollama.", foreground="#666666").grid(row=7, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Ollama vision model").grid(row=8, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_ollama_model_var, width=32).grid(row=8, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(advanced, text="Examples: llava:latest or llama3.2-vision:latest.", foreground="#666666").grid(row=8, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Semantic prompt").grid(row=9, column=0, sticky="nw", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_semantic_prompt_var).grid(row=9, column=1, columnspan=2, sticky="ew", padx=8, pady=5)

        ttk.Label(advanced, text="External analyzer command").grid(row=10, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_external_command_var).grid(row=10, column=1, columnspan=2, sticky="ew", padx=8, pady=5)
        ttk.Label(
            advanced,
            text="Optional. Use {image_path}, {filename}, and {job_id}. The command must print JSON metadata to stdout. Leave blank unless you use your own local analyzer.",
            foreground="#666666",
            wraplength=820,
        ).grid(row=11, column=1, columnspan=2, sticky="w", padx=8, pady=(0, 6))

        actions = ttk.Frame(parent)
        actions.grid(row=5, column=0, columnspan=3, sticky="ew", pady=(10, 0))
        ttk.Button(actions, text="Start AI metadata worker", command=self.start_ai_worker).pack(side="left")
        ttk.Button(actions, text="Stop AI metadata worker", command=self.stop_ai_worker).pack(side="left", padx=8)
        ttk.Label(actions, textvariable=self.ai_status_var).pack(side="right")

        self.toggle_ai_advanced_settings()

    def toggle_ai_advanced_settings(self) -> None:
        """
        Show or hide low-level AI worker tuning controls.
        """
        if not self.ai_advanced_frame:
            return
        if self.ai_advanced_visible_var.get():
            self.ai_advanced_frame.grid(row=4, column=0, columnspan=3, sticky="ew", pady=(0, 8))
        else:
            self.ai_advanced_frame.grid_remove()

    def refresh_thumbnail_controls(self) -> None:
        """
        Refresh local thumbnail availability in the manual upload tab.
        """
        self.thumbnail_runtime_var.set(thumbnail_runtime_status())
        if local_thumbnail_supported():
            self.thumbnail_check.state(["!disabled"])
        else:
            self.thumbnail_check.state(["disabled"])
            self.manual_local_thumbnails_var.set(False)

    def repair_dependencies(self) -> None:
        """
        Install winapp dependencies into the current Python interpreter.
        """
        self.write_log("Installing or repairing Python dependencies for the current runtime...")
        ok, output = install_dependencies_for_current_runtime()
        for line in output.splitlines()[-12:]:
            self.write_log(line)
        if ok:
            if not self.tray_icon:
                self.start_tray_icon()
            messagebox.showinfo("Dependency repair", "Required dependencies are available for this Python runtime.")
        else:
            messagebox.showerror("Dependency repair failed", output[-1200:] if output else "pip failed without output")
        self.refresh_thumbnail_controls()

    def current_config(self) -> WatcherConfig:
        """
        Read and validate the current UI values.
        
        @return WatcherConfig WatcherConfig built from the current form state.
        @raises ValueError: Raised when numeric settings cannot be parsed.
        """
        try:
            interval = max(0.2, float(self.interval_var.get().strip() or DEFAULT_INTERVAL_SECONDS))
            stable = max(0.5, float(self.stable_var.get().strip() or DEFAULT_STABLE_SECONDS))
            thumbnail_workers = parse_worker_choice(self.manual_thumbnail_workers_var.get())
            upload_workers = parse_worker_choice(self.manual_upload_workers_var.get())
            inventory_refresh = max(1.0, float(self.inventory_refresh_var.get().strip() or DEFAULT_INVENTORY_REFRESH_SECONDS))
            ai_worker_poll = max(AI_WORKER_MIN_POLL_SECONDS, float(self.ai_worker_poll_var.get().strip() or DEFAULT_AI_WORKER_POLL_SECONDS))
            ai_worker_lease = max(60, min(AI_WORKER_MAX_LEASE_SECONDS, int(self.ai_worker_lease_var.get().strip() or DEFAULT_AI_WORKER_LEASE_SECONDS)))
            ai_transformers_detection_threshold = max(0.01, min(0.95, float(self.ai_transformers_detection_threshold_var.get().strip() or AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT)))
        except ValueError as exc:
            raise ValueError("Scan interval, stable file seconds, inventory reconnect seconds, AI worker timing, detector threshold, and worker counts must be numeric.") from exc

        return WatcherConfig(
            watched_folder=self.watched_folder_var.get().strip(),
            gallery_url=self.gallery_url_var.get().strip(),
            api_key=self.api_key_var.get().strip(),
            scan_interval_seconds=interval,
            stable_seconds=stable,
            create_thumbnails=bool(self.create_thumbnails_var.get()),
            attach_sim_camera_metadata=bool(self.attach_sim_camera_metadata_var.get()),
            simconnect_dll_path=self.simconnect_dll_path_var.get().strip(),
            delete_uploaded_files=bool(self.delete_uploaded_files_var.get()),
            manual_thumbnail_workers=thumbnail_workers,
            manual_upload_workers=upload_workers,
            inventory_refresh_seconds=inventory_refresh,
            ai_worker_enabled=bool(self.ai_worker_enabled_var.get()),
            ai_worker_poll_seconds=ai_worker_poll,
            ai_worker_lease_seconds=ai_worker_lease,
            ai_model_name=self.ai_model_name_var.get().strip() or AI_MODEL_NAME_DEFAULT,
            ai_model_version=self.ai_model_version_var.get().strip() or AI_MODEL_VERSION_DEFAULT,
            ai_external_command=self.ai_external_command_var.get().strip(),
            ai_vision_backend=normalize_ai_vision_backend(self.ai_vision_backend_var.get()),
            ai_transformers_caption_model=self.ai_transformers_caption_model_var.get().strip() or AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT,
            ai_transformers_detector_model=self.ai_transformers_detector_model_var.get().strip() or AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT,
            ai_transformers_object_labels=self.ai_transformers_object_labels_var.get().strip() or AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT,
            ai_transformers_detection_threshold=ai_transformers_detection_threshold,
            ai_ollama_url=self.ai_ollama_url_var.get().strip() or AI_OLLAMA_URL_DEFAULT,
            ai_ollama_model=self.ai_ollama_model_var.get().strip() or AI_OLLAMA_MODEL_DEFAULT,
            ai_semantic_prompt=self.ai_semantic_prompt_var.get().strip() or AI_SEMANTIC_PROMPT_DEFAULT,
        )

    def browse_folder(self) -> None:
        """
        Open a folder picker and store the selected path in the UI.
        """
        selected = filedialog.askdirectory(initialdir=self.watched_folder_var.get() or str(Path.home()))
        if selected:
            self.watched_folder_var.set(selected)

    def browse_simconnect_dll(self) -> None:
        """
        Open a file picker for the optional SimConnect client DLL.
        """
        selected = filedialog.askopenfilename(
            title="Select SimConnect.dll",
            initialdir=str(Path(self.simconnect_dll_path_var.get()).parent) if self.simconnect_dll_path_var.get().strip() else str(Path.home()),
            filetypes=[("SimConnect DLL", "SimConnect.dll"), ("DLL files", "*.dll"), ("All files", "*.*")],
        )
        if selected:
            self.simconnect_dll_path_var.set(selected)

    def select_manual_files(self) -> None:
        """
        Open a file picker and add supported images to the manual upload list.
        """
        selected = filedialog.askopenfilenames(
            title="Select pictures to upload",
            initialdir=self.watched_folder_var.get() or str(Path.home()),
            filetypes=selected_image_filetypes(),
        )
        if not selected:
            return

        existing = {str(path): path for path in self.manual_paths}
        for path in filter_supported_paths(selected):
            existing[str(path)] = path
        self.manual_paths = [existing[key] for key in sorted(existing.keys(), key=str.lower)]
        self.refresh_manual_file_label()

    def clear_manual_files(self) -> None:
        """
        Clear the manual upload selection.
        """
        self.manual_paths = []
        self.refresh_manual_file_label()

    def refresh_manual_file_label(self) -> None:
        """
        Refresh the visible manual selection count.
        """
        count = len(self.manual_paths)
        if count == 0:
            self.manual_selection_var.set("No files selected")
        elif count == 1:
            self.manual_selection_var.set("1 file selected")
        else:
            self.manual_selection_var.set(f"{count} files selected")

    def save_config(self) -> None:
        """
        Persist current settings to the local config file.
        """
        try:
            config = self.current_config()
            self.config_store.save(config)
            self.config = config
            self.write_log("Configuration saved.", "success")
            self.refresh_revoke_button_state()
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))

    def start(self) -> None:
        """
        Start the watcher worker using the current form values.
        
        The configuration is saved before starting so command-line mode and later
        GUI launches use the same values.
        """
        if self.worker and self.worker.is_alive():
            self.write_log("Watcher is already running.")
            return

        try:
            config = self.current_config()
            self.config_store.save(config)
            self.config = config
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))
            return

        self.worker = WatcherThread(config, self.events)
        self.worker.start()
        self.status_var.set("Running")
        self.write_log("Watcher started.", "success")
        self.refresh_revoke_button_state()

    def stop(self) -> None:
        """
        Stop the watcher worker if one exists.
        """
        if self.worker:
            self.worker.stop()
        self.status_var.set("Stopped")
        self.update_monitor_state("disabled", "Watcher stopped.")
        self.refresh_revoke_button_state()

    def start_manual_upload(self) -> None:
        """
        Start the manual upload worker using the current shared connection fields.
        """
        if self.manual_worker and self.manual_worker.is_alive():
            self.write_log("Manual upload is already running.")
            return
        if not self.manual_paths:
            messagebox.showwarning("Manual upload", "Select at least one image first.")
            return

        try:
            config = self.current_config()
            self.config_store.save(config)
            self.config = config
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))
            return

        use_local_thumbnails = bool(self.manual_local_thumbnails_var.get()) and local_thumbnail_supported()
        if self.manual_local_thumbnails_var.get() and not local_thumbnail_supported():
            self.write_log("WARNING: Local thumbnails were requested, but Pillow is unavailable. Server thumbnail generation will be used.")

        self.manual_worker = ManualUploadThread(
            config,
            list(self.manual_paths),
            use_local_thumbnails,
            config.manual_thumbnail_workers,
            config.manual_upload_workers,
            self.events,
        )
        self.manual_worker.start()
        self.manual_status_var.set("Manual upload running")
        self.write_log("Manual upload worker started.", "success")

    def stop_manual_upload(self) -> None:
        """
        Request the manual upload worker to stop.
        """
        if self.manual_worker:
            self.manual_worker.stop()
        self.manual_status_var.set("Manual upload stopped")
        self.refresh_revoke_button_state()

    def repair_semantic_ai_dependencies(self) -> None:
        """
        Install optional in-process AI packages without freezing the tray UI.
        
        PyTorch and Transformers are large packages, so pip can run for a long
        time and produce a lot of output. The installation is therefore handled
        by a small background thread and reported through the same event queue as
        watcher and AI worker messages. Tkinter remains responsive while pip is
        downloading or checking packages.
        """
        if self.semantic_ai_install_running:
            self.write_log("Optional local AI module dependency installation is already running.")
            return

        self.semantic_ai_install_running = True
        if self.semantic_ai_install_button is not None:
            self.semantic_ai_install_button.configure(state="disabled")
        self.write_log("Installing optional local AI module dependencies into the current Python runtime...")

        def installer() -> None:
            """
            Run pip installation away from the Tkinter event loop.
            """
            ok, output = install_semantic_ai_dependencies_for_current_runtime()
            safe_output = output.strip() if output else "pip produced no output"
            if ok:
                self.events.put(("info", "Optional local AI module dependency installation finished successfully. Restart the app before running the Transformers backend for the first time."))
            else:
                self.events.put(("error", "Optional local AI module dependency installation finished with errors: " + safe_output[:4000]))

        threading.Thread(target=installer, daemon=True).start()

    def start_ai_worker(self) -> None:
        """
        Start the optional AI metadata worker using current shared connection fields.
        """
        if self.ai_worker and self.ai_worker.is_alive():
            self.write_log("AI metadata worker is already running.")
            return

        try:
            config = self.current_config()
            self.config_store.save(config)
            self.config = config
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))
            return

        if not config.ai_worker_enabled:
            messagebox.showwarning("AI metadata worker", "Enable the AI metadata worker before starting it.")
            return

        self.ai_worker_stop_requested = False
        self.ai_worker = AIAnalysisWorkerThread(config, self.events)
        self.ai_worker.start()
        self.ai_status_var.set("AI metadata worker running")
        self.write_log("AI metadata worker started.", "success")
        self.refresh_revoke_button_state()

    def stop_ai_worker(self) -> None:
        """
        Request the optional AI metadata worker to stop.
        """
        if self.ai_worker:
            self.ai_worker.stop()
            self.ai_worker_stop_requested = True
        self.ai_status_var.set("AI metadata worker stopping")
        self.refresh_activity_monitor_state()
        self.refresh_revoke_button_state()

    def refresh_revoke_button_state(self) -> None:
        """
        Enable revocation only when the watcher and manual uploader are idle.
        """
        if not hasattr(self, "revoke_button"):
            return
        api_key_present = bool(self.api_key_var.get().strip())
        watcher_running = bool(self.worker and self.worker.is_alive())
        manual_running = bool(self.manual_worker and self.manual_worker.is_alive())
        ai_running = bool(self.ai_worker and self.ai_worker.is_alive())
        if api_key_present and not watcher_running and not manual_running and not ai_running:
            self.revoke_button.state(["!disabled"])
        else:
            self.revoke_button.state(["disabled"])

    def revoke_api_key(self) -> None:
        """
        Revoke the saved API key on the gallery and clear it locally.
        """
        if self.worker and self.worker.is_alive():
            messagebox.showwarning("Revoke API key", "Stop watching before revoking the key.")
            return
        if self.manual_worker and self.manual_worker.is_alive():
            messagebox.showwarning("Revoke API key", "Wait for manual upload to finish before revoking the key.")
            return
        if self.ai_worker and self.ai_worker.is_alive():
            messagebox.showwarning("Revoke API key", "Stop the AI metadata worker before revoking the key.")
            return

        upload_url = normalize_upload_url(self.gallery_url_var.get())
        api_key = self.api_key_var.get().strip()
        if not upload_url or not api_key:
            messagebox.showwarning("Revoke API key", "Gallery URL and API key are required.")
            return

        if not messagebox.askyesno("Revoke API key", "Revoke this API key on the gallery and remove it from this app?"):
            return

        self.write_log("Revoking API key on the gallery...")
        try:
            result = revoke_upload_key(upload_url, api_key)
            self.api_key_var.set("")
            self.config.api_key = ""
            self.config_store.save(self.current_config())
            self.write_log(str(result.get("message") or "API key revoked."))
            messagebox.showinfo("Revoke API key", str(result.get("message") or "API key revoked."))
            self.refresh_revoke_button_state()
        except Exception as exc:  # noqa: BLE001
            self.write_log(f"ERROR: {exc}")
            messagebox.showerror("Revoke API key failed", str(exc))

    def close(self) -> None:
        """
        Stop background work and close the window.
        """
        if self.exiting:
            return
        self.exiting = True
        self.stop_tray_icon()
        self.stop()
        self.stop_manual_upload()
        self.stop_ai_worker()
        self.root.after(150, self.root.destroy)

    def open_config_folder(self) -> None:
        """
        Open the folder containing config, state, and log files.
        """
        CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        webbrowser.open(str(CONFIG_DIR))

    def refresh_activity_monitor_state(self) -> None:
        """
        Recalculate the top activity indicator from the live worker objects.
        
        The tray app has three independent background activities: folder
        monitoring, manual upload, and optional AI metadata processing. Stopping
        one activity must not leave stale text from another activity in the
        shared monitor strip.
        """
        watcher_running = bool(self.worker and self.worker.is_alive())
        manual_running = bool(self.manual_worker and self.manual_worker.is_alive())
        ai_running = bool(self.ai_worker and self.ai_worker.is_alive() and not self.ai_worker_stop_requested)

        if watcher_running:
            self.update_monitor_state("green", "Folder monitoring is active.")
            return
        if manual_running:
            self.update_monitor_state("green", "Manual upload is active.")
            return
        if ai_running:
            self.update_monitor_state("green", "AI metadata worker is active.")
            return
        self.update_monitor_state("disabled", "No background worker is active.")

    def drain_events(self) -> None:
        """
        Move worker events into the visible log.
        
        This method is scheduled on the Tkinter event loop instead of being
        called directly from the worker thread. Tkinter widgets must only be
        updated by the main UI thread.
        """
        if self.exiting:
            return
        while True:
            try:
                level, message = self.events.get_nowait()
            except queue.Empty:
                break
            log_level = self.classify_log_level(level, message)
            self.write_log(f"{level.upper()}: {message}", log_level)
            if message.startswith("Manual upload finished"):
                self.manual_status_var.set("Manual upload idle")
            elif message.startswith("Manual upload stopped"):
                self.manual_status_var.set("Manual upload stopped")
                self.refresh_revoke_button_state()
            elif message.startswith("Watcher stopped"):
                self.status_var.set("Stopped")
                self.update_monitor_state("disabled", "Watcher stopped.")
                self.refresh_revoke_button_state()
            elif message.startswith("AI metadata worker stopped"):
                self.ai_worker_stop_requested = False
                self.ai_status_var.set("AI metadata worker stopped")
                self.refresh_activity_monitor_state()
                self.refresh_revoke_button_state()
            elif message.startswith("AI metadata worker started"):
                self.ai_worker_stop_requested = False
                self.ai_status_var.set("AI metadata worker running")
                self.update_monitor_state("green", "AI metadata worker is active.")
                self.refresh_revoke_button_state()
            elif level == "error" or level == "warning":
                self.status_var.set("Running with errors")
                self.update_monitor_state("red", message)
                if self.manual_worker and self.manual_worker.is_alive():
                    self.manual_status_var.set("Manual upload has errors")
                if self.ai_worker and self.ai_worker.is_alive() and not self.ai_worker_stop_requested:
                    self.ai_status_var.set("AI metadata worker has errors")
            elif level in {"info", "debug"}:
                if "Upload failed" not in message and "error" not in message.lower():
                    if self.worker and self.worker.is_alive():
                        self.status_var.set("Running")
                        self.update_monitor_state("green", "Folder monitoring is active.")
                    if self.ai_worker and self.ai_worker.is_alive() and not self.ai_worker_stop_requested and message.startswith("AI metadata"):
                        self.ai_status_var.set("AI metadata worker running")
                        self.update_monitor_state("green", "AI metadata worker is active.")
            if message.startswith("Watching ") or message.startswith("Upload endpoint:"):
                self.update_monitor_state("green", "Folder monitoring is active.")
            if message.startswith("Uploaded ") or message.startswith("Skipped duplicate content"):
                self.update_monitor_state("green", "Folder monitoring is active.")
            if message.startswith("AI metadata stored") and not self.ai_worker_stop_requested:
                self.ai_status_var.set("AI metadata worker running")
                self.update_monitor_state("green", "AI metadata worker is active.")
            if message.startswith("Optional local AI module dependency installation finished"):
                self.semantic_ai_install_running = False
                if self.semantic_ai_install_button is not None:
                    self.semantic_ai_install_button.configure(state="normal")
            if message.startswith("Manual upload started"):
                self.write_log("Manual upload job accepted.", "system")

        self.root.after(200, self.drain_events)

    def configure_log_tags(self) -> None:
        """
        Configure log colors and styles for fast visual scanning.
        """
        if self.log_tags_ready:
            return
        self.log_text.tag_configure("timestamp", foreground="#666666")
        self.log_text.tag_configure("system", foreground="#4f5b66")
        self.log_text.tag_configure("success", foreground="#1f7a1f")
        self.log_text.tag_configure("warning", foreground="#b36b00")
        self.log_text.tag_configure("error", foreground="#b00020")
        self.log_text.tag_configure("debug", foreground="#6a6a6a")
        self.log_text.tag_configure("prefix_system", foreground="#4f5b66", font=("Segoe UI", 9, "bold"))
        self.log_text.tag_configure("prefix_success", foreground="#1f7a1f", font=("Segoe UI", 9, "bold"))
        self.log_text.tag_configure("prefix_warning", foreground="#b36b00", font=("Segoe UI", 9, "bold"))
        self.log_text.tag_configure("prefix_error", foreground="#b00020", font=("Segoe UI", 9, "bold"))
        self.log_text.tag_configure("prefix_debug", foreground="#6a6a6a", font=("Segoe UI", 9, "bold"))
        self.log_tags_ready = True

    def update_monitor_state(self, state: str, detail: str) -> None:
        """
        Update the small monitoring light and its text labels.
        
        @param str state: One of disabled, green, or red.
        @param str detail: Short human-readable explanation.
        """
        state = state if state in {"disabled", "green", "red"} else "red"
        self.monitor_state = state
        self.monitor_detail = detail
        palette = {
            "disabled": ("#c0c0c0", "#8a8a8a", "Monitoring disabled"),
            "green": ("#4caf50", "#2e7d32", "Monitoring active"),
            "red": ("#ef5350", "#b71c1c", "Monitoring error"),
        }
        fill, outline, label = palette[state]
        self.monitor_state_var.set(label)
        self.monitor_detail_var.set(detail)
        self.monitor_light.delete("all")
        self.monitor_light.create_oval(2, 2, 14, 14, fill=fill, outline=outline, width=1)

    def classify_log_level(self, level: str, message: str) -> str:
        """
        Convert watcher events into readable log colors.
        
        @param str level: Original worker severity.
        @param str message: Event text.
        @return str Tk text tag name.
        """
        lower = message.lower()
        if level == "error":
            return "error"
        if level == "warning":
            return "warning"
        if any(lower.startswith(prefix) for prefix in ["watching ", "upload endpoint:", "watcher started", "configuration saved", "manual upload started", "manual upload worker started", "manual upload finished", "ai metadata worker started", "ai metadata stored"]):
            return "success"
        if lower.startswith("uploaded ") or lower.startswith("skipped duplicate content") or lower.startswith("generated "):
            return "success"
        if lower.startswith("manual upload stopped") or lower.startswith("watcher stopped") or lower.startswith("ai metadata worker stopped"):
            return "system"
        return "system"

    def write_log(self, message: str, tag: str = "system") -> None:
        """
        Append one line to the status log.
        
        @param str message: Message text to append.
        @param str tag: Log color tag name.
        """
        stamp = time.strftime("%H:%M:%S")
        self.log_text.configure(state="normal")
        self.log_text.insert("end", "[", ("timestamp",))
        self.log_text.insert("end", stamp, ("timestamp",))
        self.log_text.insert("end", "] ", ("timestamp",))
        self.log_text.insert("end", message, (tag if tag in self.log_text.tag_names() else "system",))
        self.log_text.insert("end", "\n")
        self.log_text.see("end")
        self.log_text.configure(state="disabled")

    def run(self) -> None:
        """
        Run the Tkinter event loop.
        """
        self.root.mainloop()


def run_once(config: WatcherConfig) -> int:
    """
    Run one scan without showing the GUI.
    
    This is mostly useful for testing and scheduled execution. Note that the
    live watcher behavior of ignoring pre-existing files is not applied here
    unless initial_paths is explicitly populated before calling scan_once().
    
    @param WatcherConfig config: Configuration to use for the scan.
    @return int Process exit code. Zero means the scan command completed.
    """
    setup_logging()
    events: "queue.Queue[Tuple[str, str]]" = queue.Queue()
    worker = WatcherThread(config, events)
    folder = Path(config.watched_folder)
    upload_url = normalize_upload_url(config.gallery_url)
    worker.scan_once(folder, upload_url)

    while not events.empty():
        level, message = events.get()
        print(f"{level.upper()}: {message}")

    return 0


def parse_args(argv: List[str]) -> argparse.Namespace:
    """
    Parse optional command-line switches.
    
    @param List[str] argv: Command-line argument list without the executable name.
    @return argparse.Namespace Parsed argparse namespace.
    """
    parser = argparse.ArgumentParser(description="Watch a folder and upload new images to PHP Gallery.")
    parser.add_argument("--once", action="store_true", help="Run one scan using saved configuration and exit.")
    parser.add_argument("--config", default=str(CONFIG_PATH), help="Path to a config JSON file.")
    return parser.parse_args(argv)


def main(argv: Optional[List[str]] = None) -> int:
    """
    Application entry point.
    
    @param Optional[List[str]] argv: Optional command-line argument list. When omitted, sys.argv is.
    @return int Process exit code.
    """
    setup_logging()
    args = parse_args(list(argv) if argv is not None else sys.argv[1:])
    store = ConfigStore(Path(args.config))
    config = store.load()

    if args.once:
        return run_once(config)

    app = WatcherApp()
    app.run()
    return 0


if __name__ == "__main__":
    multiprocessing.freeze_support()
    raise SystemExit(main())
