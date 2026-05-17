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
import hashlib
import json
import logging
import mimetypes
import os
import queue
import sys
import threading
import time
import uuid
import webbrowser
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple
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


APP_NAME = "PHPGalleryUploader"
CONFIG_DIR = Path(os.environ.get("APPDATA", str(Path.home()))) / APP_NAME
CONFIG_PATH = CONFIG_DIR / "config.json"
STATE_PATH = CONFIG_DIR / "upload_state.json"
LOG_PATH = CONFIG_DIR / "watcher.log"

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
    """

    watched_folder: str = ""
    gallery_url: str = ""
    api_key: str = ""
    scan_interval_seconds: float = DEFAULT_INTERVAL_SECONDS
    stable_seconds: float = DEFAULT_STABLE_SECONDS
    create_thumbnails: bool = True

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "WatcherConfig":
        """
        Build a typed configuration object from decoded JSON data.

        Missing keys are treated as defaults. This makes configuration upgrades
        safe because older config files keep working when new fields are added.
        Numeric values are coerced from strings where possible because manual
        edits and GUI variables often produce text.

        @param data: Dictionary loaded from the JSON configuration file.
        @return: Normalized WatcherConfig instance.
        @raises ValueError: Raised by float conversion when numeric values are
            present but cannot be parsed.
        """
        return cls(
            watched_folder=str(data.get("watched_folder", "")),
            gallery_url=str(data.get("gallery_url", "")),
            api_key=str(data.get("api_key", "")),
            scan_interval_seconds=float(data.get("scan_interval_seconds", DEFAULT_INTERVAL_SECONDS) or DEFAULT_INTERVAL_SECONDS),
            stable_seconds=float(data.get("stable_seconds", DEFAULT_STABLE_SECONDS) or DEFAULT_STABLE_SECONDS),
            create_thumbnails=bool(data.get("create_thumbnails", True)),
        )

    def to_dict(self) -> Dict[str, Any]:
        """
        Convert this configuration to a JSON-serializable dictionary.

        @return: Plain dictionary suitable for json.dumps().
        """
        return {
            "watched_folder": self.watched_folder,
            "gallery_url": self.gallery_url,
            "api_key": self.api_key,
            "scan_interval_seconds": self.scan_interval_seconds,
            "stable_seconds": self.stable_seconds,
            "create_thumbnails": self.create_thumbnails,
        }


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

        @param path: Path to the JSON configuration file.
        """
        self.path = path

    def load(self) -> WatcherConfig:
        """
        Load the configuration file.

        Invalid, missing, or non-object JSON content falls back to defaults. The
        watcher should remain startable even after a user accidentally damages
        the local config file.

        @return: Loaded configuration, or defaults when the file is unusable.
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

        @param config: Configuration object to persist.
        @return: None.
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

        @param path: Path to the JSON state file.
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

        @return: None.
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

        @return: None.
        @raises OSError: Propagated when the state file cannot be written.
        """
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = self.path.with_suffix(".tmp")
        tmp_path.write_text(json.dumps(self.data, indent=2), encoding="utf-8")
        tmp_path.replace(self.path)

    def already_uploaded_path(self, path: Path, file_hash: str) -> bool:
        """
        Check whether the exact path and content were already uploaded.

        @param path: Local image path being considered.
        @param file_hash: SHA-256 hash of the current file content.
        @return: True when this path already succeeded with the same content.
        """
        entry = self.data["uploaded_paths"].get(str(path))
        return isinstance(entry, dict) and entry.get("sha256") == file_hash

    def already_uploaded_hash(self, file_hash: str) -> bool:
        """
        Check whether identical content already uploaded under any file name.

        @param file_hash: SHA-256 hash of the current file content.
        @return: True when this byte-identical file content is already known.
        """
        return file_hash in self.data["uploaded_hashes"]

    def mark_uploaded(self, path: Path, file_hash: str, size: int, response: Dict[str, Any]) -> None:
        """
        Record a successful upload after the server confirms it.

        @param path: Local file path that was uploaded.
        @param file_hash: SHA-256 hash captured at upload time.
        @param size: File size in bytes captured at upload time.
        @param response: JSON object returned by the PHP Gallery endpoint.
        @return: None.
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

        @param path: Local duplicate file path.
        @param file_hash: SHA-256 hash matching already uploaded content.
        @param size: File size in bytes.
        @return: None.
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

        @param path: Local file path being considered.
        @param file_hash: Current SHA-256 hash of the file.
        @return: True when no backoff delay is active.
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

        @param path: Local file path that failed to upload.
        @param file_hash: SHA-256 hash of the file at failure time.
        @param message: Human-readable failure reason.
        @return: None.
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

        @param stable_seconds: Required unchanged duration before a file is
            considered ready. Values below 0.5 are clamped to 0.5 seconds.
        """
        self.stable_seconds = max(0.5, stable_seconds)
        self._seen: Dict[Path, Tuple[int, float, float]] = {}

    def stable(self, path: Path) -> bool:
        """
        Check whether a path has remained unchanged long enough.

        @param path: File path to inspect.
        @return: True when size and modification time have been stable for the
            configured duration. False when the file is new, changing, missing,
            or temporarily unreadable.
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

    @return: None.
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

    @param value: User-provided site URL or upload endpoint.
    @return: Normalized upload endpoint URL, or an empty string for blank input.
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


def sha256_file(path: Path) -> str:
    """
    Calculate a file SHA-256 hash without loading the whole file at once.

    @param path: File to hash.
    @return: Hex-encoded SHA-256 digest.
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

    @param folder: Folder to scan.
    @return: Sorted list of supported image paths. Missing or unreadable folders
        return an empty list.
    """
    try:
        candidates = [item for item in folder.iterdir() if item.is_file() and item.suffix.lower() in SUPPORTED_SUFFIXES]
    except OSError:
        return []

    # Oldest first produces stable, predictable upload order when several files
    # are copied into the folder at roughly the same time.
    return sorted(candidates, key=lambda item: (item.stat().st_mtime if item.exists() else 0, item.name.lower()))


def multipart_upload(upload_url: str, api_key: str, path: Path, create_thumbnails: bool) -> Dict[str, Any]:
    """
    Upload one image file using standard-library HTTP multipart/form-data.

    No third-party dependency is required. This matters for a small Windows
    helper that should run from a normal Python installation without a packaging
    or virtual environment requirement.

    @param upload_url: Normalized PHP Gallery upload endpoint.
    @param api_key: Gallery-scoped API key sent as X-Gallery-API-Key.
    @param path: Local image path to upload.
    @param create_thumbnails: Whether to ask the gallery to generate thumbnails.
    @return: Parsed JSON response from the server.
    @raises RuntimeError: Raised for HTTP errors, network errors, non-JSON
        responses, malformed JSON payloads, or server-declared upload failure.
    @raises OSError: Propagated when the image cannot be read.
    """
    boundary = "PHPGalleryUpload" + uuid.uuid4().hex
    content_type = mimetypes.guess_type(path.name)[0] or "application/octet-stream"
    fields = {
        "create_thumbnails": "1" if create_thumbnails else "0",
    }

    body_parts: List[bytes] = []
    for name, value in fields.items():
        body_parts.append(f"--{boundary}\r\n".encode("ascii"))
        body_parts.append(f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode("ascii"))
        body_parts.append(str(value).encode("utf-8"))
        body_parts.append(b"\r\n")

    body_parts.append(f"--{boundary}\r\n".encode("ascii"))
    safe_name = path.name.replace('"', "_")
    body_parts.append(f'Content-Disposition: form-data; name="images[]"; filename="{safe_name}"\r\n'.encode("utf-8"))
    body_parts.append(f"Content-Type: {content_type}\r\n\r\n".encode("ascii"))
    body_parts.append(path.read_bytes())
    body_parts.append(b"\r\n")
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
            "User-Agent": "PHPGalleryUploader/1.0",
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

        @param config: Runtime configuration captured when the worker starts.
        @param events: Thread-safe queue used to send status messages to the GUI
            or command-line runner.
        """
        super().__init__(daemon=True)
        self.config = config
        self.events = events
        self.stop_event = threading.Event()
        self.state = UploadState()
        self.stability = FileStabilityTracker(config.stable_seconds)
        self.initial_paths: Set[Path] = set()

    def stop(self) -> None:
        """
        Request the worker to stop.

        @return: None.
        """
        self.stop_event.set()

    def emit(self, level: str, message: str) -> None:
        """
        Send a message to the UI queue and the persistent log file.

        @param level: Logging level name such as info, warning, or error.
        @param message: Human-readable status message.
        @return: None.
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

        @return: None.
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
        if self.initial_paths:
            self.emit("info", f"Ignoring {len(self.initial_paths)} existing image file(s); only files added after watcher start will upload.")

        while not self.stop_event.is_set():
            self.scan_once(folder, upload_url)
            self.stop_event.wait(max(0.2, self.config.scan_interval_seconds))

        self.emit("info", "Watcher stopped.")

    def scan_once(self, folder: Path, upload_url: str) -> None:
        """
        Scan the watched folder once and process files that are ready.

        @param folder: Folder to scan.
        @param upload_url: Normalized endpoint used for uploads.
        @return: None.
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

            if self.state.already_uploaded_path(path, file_hash):
                continue
            if self.state.already_uploaded_hash(file_hash):
                self.state.mark_duplicate(path, file_hash, size)
                self.emit("info", f"Skipped duplicate content: {path.name}")
                continue
            if not self.state.retry_allowed(path, file_hash):
                continue

            try:
                self.emit("info", f"Uploading {path.name}")
                payload = multipart_upload(upload_url, self.config.api_key.strip(), path, self.config.create_thumbnails)
                self.state.mark_uploaded(path, file_hash, size, payload)
                uploaded = payload.get("uploaded", 0)
                scanned = payload.get("scanned", 0)
                self.emit("info", f"Uploaded {path.name}: uploaded={uploaded}, scanned={scanned}")
            except Exception as exc:  # noqa: BLE001
                message = str(exc)
                self.state.mark_failure(path, file_hash, message)
                self.emit("error", f"Upload failed for {path.name}: {message}")


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

        @return: None.
        @raises RuntimeError: Raised when Tkinter is not available.
        """
        if tk is None or ttk is None or filedialog is None or messagebox is None:
            raise RuntimeError("Tkinter is not available in this Python installation.")

        self.root = tk.Tk()
        self.root.title("PHP Gallery watched-folder uploader")
        self.root.geometry("860x620")

        self.config_store = ConfigStore()
        self.config = self.config_store.load()
        self.events: "queue.Queue[Tuple[str, str]]" = queue.Queue()
        self.worker: Optional[WatcherThread] = None

        self.watched_folder_var = tk.StringVar(value=self.config.watched_folder)
        self.gallery_url_var = tk.StringVar(value=self.config.gallery_url)
        self.api_key_var = tk.StringVar(value=self.config.api_key)
        self.interval_var = tk.StringVar(value=str(self.config.scan_interval_seconds))
        self.stable_var = tk.StringVar(value=str(self.config.stable_seconds))
        self.create_thumbnails_var = tk.BooleanVar(value=self.config.create_thumbnails)
        self.status_var = tk.StringVar(value="Stopped")

        self.build_ui()
        self.root.protocol("WM_DELETE_WINDOW", self.close)
        self.root.after(200, self.drain_events)

    def build_ui(self) -> None:
        """
        Create the visible controls and initial status text.

        @return: None.
        """
        outer = ttk.Frame(self.root, padding=16)
        outer.pack(fill="both", expand=True)

        title = ttk.Label(outer, text="PHP Gallery watched-folder uploader", font=("Segoe UI", 16, "bold"))
        title.pack(anchor="w")
        subtitle = ttk.Label(
            outer,
            text="Watches one local folder and uploads new image files through a gallery-scoped API key.",
        )
        subtitle.pack(anchor="w", pady=(2, 14))

        form = ttk.Frame(outer)
        form.pack(fill="x")
        form.columnconfigure(1, weight=1)

        ttk.Label(form, text="Watched folder").grid(row=0, column=0, sticky="w", pady=5)
        ttk.Entry(form, textvariable=self.watched_folder_var).grid(row=0, column=1, sticky="ew", padx=8, pady=5)
        ttk.Button(form, text="Browse", command=self.browse_folder).grid(row=0, column=2, sticky="ew", pady=5)

        ttk.Label(form, text="Gallery URL or upload endpoint").grid(row=1, column=0, sticky="w", pady=5)
        ttk.Entry(form, textvariable=self.gallery_url_var).grid(row=1, column=1, columnspan=2, sticky="ew", padx=8, pady=5)

        ttk.Label(form, text="API key").grid(row=2, column=0, sticky="w", pady=5)
        ttk.Entry(form, textvariable=self.api_key_var, show="*").grid(row=2, column=1, columnspan=2, sticky="ew", padx=8, pady=5)

        ttk.Label(form, text="Scan interval seconds").grid(row=3, column=0, sticky="w", pady=5)
        ttk.Entry(form, textvariable=self.interval_var, width=12).grid(row=3, column=1, sticky="w", padx=8, pady=5)

        ttk.Label(form, text="Stable file seconds").grid(row=4, column=0, sticky="w", pady=5)
        ttk.Entry(form, textvariable=self.stable_var, width=12).grid(row=4, column=1, sticky="w", padx=8, pady=5)

        ttk.Checkbutton(
            form,
            text="Ask gallery to create thumbnails after upload",
            variable=self.create_thumbnails_var,
        ).grid(row=5, column=1, columnspan=2, sticky="w", padx=8, pady=5)

        actions = ttk.Frame(outer)
        actions.pack(fill="x", pady=14)
        ttk.Button(actions, text="Save configuration", command=self.save_config).pack(side="left")
        ttk.Button(actions, text="Start watching", command=self.start).pack(side="left", padx=8)
        ttk.Button(actions, text="Stop", command=self.stop).pack(side="left")
        ttk.Button(actions, text="Open config folder", command=self.open_config_folder).pack(side="left", padx=8)
        ttk.Label(actions, textvariable=self.status_var).pack(side="right")

        log_frame = ttk.LabelFrame(outer, text="Status log")
        log_frame.pack(fill="both", expand=True)
        self.log_text = tk.Text(log_frame, height=18, wrap="word")
        self.log_text.pack(fill="both", expand=True, padx=8, pady=8)
        self.log_text.configure(state="disabled")

        self.write_log(f"Configuration: {CONFIG_PATH}")
        self.write_log(f"State: {STATE_PATH}")
        self.write_log(f"Log: {LOG_PATH}")

    def current_config(self) -> WatcherConfig:
        """
        Read and validate the current UI values.

        @return: WatcherConfig built from the current form state.
        @raises ValueError: Raised when numeric settings cannot be parsed.
        """
        try:
            interval = max(0.2, float(self.interval_var.get().strip() or DEFAULT_INTERVAL_SECONDS))
            stable = max(0.5, float(self.stable_var.get().strip() or DEFAULT_STABLE_SECONDS))
        except ValueError as exc:
            raise ValueError("Scan interval and stable file seconds must be numeric.") from exc

        return WatcherConfig(
            watched_folder=self.watched_folder_var.get().strip(),
            gallery_url=self.gallery_url_var.get().strip(),
            api_key=self.api_key_var.get().strip(),
            scan_interval_seconds=interval,
            stable_seconds=stable,
            create_thumbnails=bool(self.create_thumbnails_var.get()),
        )

    def browse_folder(self) -> None:
        """
        Open a folder picker and store the selected path in the UI.

        @return: None.
        """
        selected = filedialog.askdirectory(initialdir=self.watched_folder_var.get() or str(Path.home()))
        if selected:
            self.watched_folder_var.set(selected)

    def save_config(self) -> None:
        """
        Persist current settings to the local config file.

        @return: None.
        """
        try:
            config = self.current_config()
            self.config_store.save(config)
            self.config = config
            self.write_log("Configuration saved.")
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))

    def start(self) -> None:
        """
        Start the watcher worker using the current form values.

        The configuration is saved before starting so command-line mode and later
        GUI launches use the same values.

        @return: None.
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
        self.write_log("Watcher started.")

    def stop(self) -> None:
        """
        Stop the watcher worker if one exists.

        @return: None.
        """
        if self.worker:
            self.worker.stop()
        self.status_var.set("Stopped")

    def close(self) -> None:
        """
        Stop background work and close the window.

        @return: None.
        """
        self.stop()
        self.root.after(150, self.root.destroy)

    def open_config_folder(self) -> None:
        """
        Open the folder containing config, state, and log files.

        @return: None.
        """
        CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        webbrowser.open(str(CONFIG_DIR))

    def drain_events(self) -> None:
        """
        Move worker events into the visible log.

        This method is scheduled on the Tkinter event loop instead of being
        called directly from the worker thread. Tkinter widgets must only be
        updated by the main UI thread.

        @return: None.
        """
        while True:
            try:
                level, message = self.events.get_nowait()
            except queue.Empty:
                break
            self.write_log(f"{level.upper()}: {message}")
            if level == "error":
                self.status_var.set("Running with errors")

        self.root.after(200, self.drain_events)

    def write_log(self, message: str) -> None:
        """
        Append one line to the status log.

        @param message: Message text to append.
        @return: None.
        """
        stamp = time.strftime("%H:%M:%S")
        self.log_text.configure(state="normal")
        self.log_text.insert("end", f"[{stamp}] {message}\n")
        self.log_text.see("end")
        self.log_text.configure(state="disabled")

    def run(self) -> None:
        """
        Run the Tkinter event loop.

        @return: None.
        """
        self.root.mainloop()


def run_once(config: WatcherConfig) -> int:
    """
    Run one scan without showing the GUI.

    This is mostly useful for testing and scheduled execution. Note that the
    live watcher behavior of ignoring pre-existing files is not applied here
    unless initial_paths is explicitly populated before calling scan_once().

    @param config: Configuration to use for the scan.
    @return: Process exit code. Zero means the scan command completed.
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

    @param argv: Command-line argument list without the executable name.
    @return: Parsed argparse namespace.
    """
    parser = argparse.ArgumentParser(description="Watch a folder and upload new images to PHP Gallery.")
    parser.add_argument("--once", action="store_true", help="Run one scan using saved configuration and exit.")
    parser.add_argument("--config", default=str(CONFIG_PATH), help="Path to a config JSON file.")
    return parser.parse_args(argv)


def main(argv: Optional[List[str]] = None) -> int:
    """
    Application entry point.

    @param argv: Optional command-line argument list. When omitted, sys.argv is
        used.
    @return: Process exit code.
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
    raise SystemExit(main())
