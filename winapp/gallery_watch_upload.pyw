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
import concurrent.futures
import hashlib
import importlib
import json
import logging
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
    from PIL import Image, ImageOps
except ImportError:  # pragma: no cover
    # Pillow is optional for the watcher. Manual client-side thumbnail generation
    # requires it, while normal watch-folder uploads keep working without it.
    Image = None
    ImageOps = None


APP_NAME = "PHPGalleryUploader"
CONFIG_DIR = Path(os.environ.get("APPDATA", str(Path.home()))) / APP_NAME
CONFIG_PATH = CONFIG_DIR / "config.json"
STATE_PATH = CONFIG_DIR / "upload_state.json"
LOG_PATH = CONFIG_DIR / "watcher.log"
APP_DIR = Path(__file__).resolve().parent
REQUIREMENTS_PATH = APP_DIR / "requirements.txt"

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
THUMBNAIL_SIZES = [300, 600, 800, 960, 1280, 1600]
DEFAULT_THUMBNAIL_WORKERS = max(2, min(12, (os.cpu_count() or 4) // 2 or 2))
DEFAULT_UPLOAD_WORKERS = 4
MAX_THUMBNAIL_WORKERS = 32
MAX_UPLOAD_WORKERS = 12



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
    @param manual_thumbnail_workers: Manual upload process count for local
        thumbnail generation. Zero means automatic.
    @param manual_upload_workers: Manual upload thread count for multipart HTTP
        upload requests. Zero means automatic.
    """

    watched_folder: str = ""
    gallery_url: str = ""
    api_key: str = ""
    scan_interval_seconds: float = DEFAULT_INTERVAL_SECONDS
    stable_seconds: float = DEFAULT_STABLE_SECONDS
    create_thumbnails: bool = True
    manual_thumbnail_workers: int = 0
    manual_upload_workers: int = 0

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
            manual_thumbnail_workers=int(data.get("manual_thumbnail_workers", 0) or 0),
            manual_upload_workers=int(data.get("manual_upload_workers", 0) or 0),
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
            "manual_thumbnail_workers": self.manual_thumbnail_workers,
            "manual_upload_workers": self.manual_upload_workers,
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


def multipart_field_part(boundary: str, name: str, value: str) -> bytes:
    """
    Build one text field for a multipart/form-data request body.

    @param boundary: Multipart boundary string without the leading dashes.
    @param name: Submitted field name.
    @param value: Submitted field value.
    @return: Encoded multipart field bytes.
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

    @param boundary: Multipart boundary string without the leading dashes.
    @param field_name: Submitted file field name.
    @param path: Local file path whose bytes should be sent.
    @param filename: Optional remote file name. When omitted, path.name is used.
    @return: Encoded multipart file bytes including the file content.
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
) -> Dict[str, Any]:
    """
    Upload one image file using standard-library HTTP multipart/form-data.

    The same endpoint is used for watch-folder uploads and manual uploads. Manual
    uploads may include locally generated thumbnail files in the same request. The
    server still stores the original image through the existing gallery upload
    pipeline, then installs the supplied thumbnail variants beside the final image
    record.

    @param upload_url: Normalized PHP Gallery upload endpoint.
    @param api_key: Gallery-scoped API key sent as X-Gallery-API-Key.
    @param path: Local image path to upload.
    @param create_thumbnails: Whether to ask the gallery to generate thumbnails.
    @param thumbnails: Optional local thumbnail variants to send with the image.
    @param client_upload_id: Optional stable request-local ID used to map supplied
        thumbnails to the stored image after server-side filename normalization.
    @return: Parsed JSON response from the server.
    @raises RuntimeError: Raised for HTTP errors, network errors, non-JSON
        responses, malformed JSON payloads, or server-declared upload failure.
    @raises OSError: Propagated when the image or a thumbnail cannot be read.
    """
    boundary = "PHPGalleryUpload" + uuid.uuid4().hex
    field_entries: List[Tuple[str, str]] = [
        ("create_thumbnails", "1" if create_thumbnails else "0"),
    ]
    if client_upload_id:
        field_entries.append(("image_client_ids[]", client_upload_id))

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

    @return: Tkinter-compatible file type filters.
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

    @param paths: Raw path strings returned by Tkinter.
    @return: Sorted, de-duplicated Path objects.
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

    @return: True when Pillow imports are available.
    """
    return Image is not None and ImageOps is not None


def thumbnail_runtime_status() -> str:
    """
    Build a clear status line for the Python runtime used by this process.

    Windows can have multiple Python installations and Microsoft Store aliases.
    The app must report the exact executable currently running the GUI because
    Pillow has to be installed into this same interpreter.

    @return: Human-readable runtime and Pillow availability status.
    """
    executable = sys.executable or "unknown Python executable"
    version = sys.version.split()[0]
    if local_thumbnail_supported():
        pillow_version = getattr(Image, "__version__", "installed")
        return f"Client-side thumbnails available. Python {version}: {executable}. Pillow: {pillow_version}."
    return f"Client-side thumbnails unavailable for this Python runtime: {executable}. Python {version}."


def clamp_int(value: int, minimum: int, maximum: int) -> int:
    """
    Restrict an integer to a configured inclusive range.

    @param value: Candidate integer value.
    @param minimum: Lowest accepted value.
    @param maximum: Highest accepted value.
    @return: Value restricted to the accepted range.
    """
    return max(minimum, min(maximum, int(value)))


def automatic_thumbnail_worker_count() -> int:
    """
    Choose a conservative multiprocessing thumbnail worker count.

    The automatic value intentionally avoids using every logical CPU. Image
    resizing and WebP encoding also consume disk I/O, memory bandwidth, and RAM.
    On high-core CPUs, using roughly half the logical CPUs is usually faster and
    more stable than launching one encoder process per thread.

    @return: Worker process count for thumbnail generation.
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

    @return: Worker thread count for multipart uploads.
    """
    return DEFAULT_UPLOAD_WORKERS


def resolve_thumbnail_worker_count(configured_value: int) -> int:
    """
    Resolve the manual thumbnail worker setting into a real process count.

    @param configured_value: Stored UI value, where zero means automatic.
    @return: Safe worker process count.
    """
    if int(configured_value) <= 0:
        return automatic_thumbnail_worker_count()
    return clamp_int(int(configured_value), 1, MAX_THUMBNAIL_WORKERS)


def resolve_upload_worker_count(configured_value: int) -> int:
    """
    Resolve the manual upload worker setting into a real thread count.

    @param configured_value: Stored UI value, where zero means automatic.
    @return: Safe worker thread count.
    """
    if int(configured_value) <= 0:
        return automatic_upload_worker_count()
    return clamp_int(int(configured_value), 1, MAX_UPLOAD_WORKERS)


def worker_choice_values(maximum: int) -> List[str]:
    """
    Build human-readable worker choices for the Tkinter comboboxes.

    @param maximum: Highest explicit worker value to offer.
    @return: List containing Auto plus numeric worker counts.
    """
    values = ["Auto"]
    for value in [1, 2, 4, 6, 8, 12, 16, 24, 32]:
        if value <= maximum:
            values.append(str(value))
    return values


def parse_worker_choice(value: str) -> int:
    """
    Convert a UI worker choice into the persisted integer representation.

    @param value: Combobox text, either Auto or a positive integer.
    @return: Zero for Auto, otherwise a positive integer.
    """
    text = str(value).strip()
    if not text or text.lower() == "auto":
        return 0
    return int(text)


def format_worker_choice(value: int) -> str:
    """
    Convert a persisted worker value into combobox text.

    @param value: Stored worker value, where zero means Auto.
    @return: Combobox text.
    """
    if int(value) <= 0:
        return "Auto"
    return str(int(value))


def install_pillow_for_current_runtime() -> Tuple[bool, str]:
    """
    Install or repair Pillow for the exact Python interpreter running the app.

    This avoids the common Windows issue where install.bat installs packages into
    one Python version while a .pyw file association launches another version.

    @return: Tuple containing success flag and command output text.
    """
    command = [sys.executable, "-m", "pip", "install", "--user"]
    if REQUIREMENTS_PATH.is_file():
        command.extend(["-r", str(REQUIREMENTS_PATH)])
    else:
        command.append("Pillow>=10.0")

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
        except Exception as exc:  # noqa: BLE001
            return False, f"pip finished, but Pillow still cannot be imported by this process: {exc}\n{output}"
    return completed.returncode == 0, output


def image_has_alpha(image: Any) -> bool:
    """
    Return whether a Pillow image contains transparency that matters for output.

    @param image: Pillow image instance.
    @return: True when the image has an alpha channel or palette transparency.
    """
    return image.mode in {"RGBA", "LA"} or (image.mode == "P" and "transparency" in image.info)


def prepare_jpeg_image(image: Any) -> Any:
    """
    Convert a Pillow image to the RGB canvas expected by JPEG output.

    Transparent pixels are composited over white to match the gallery server's
    JPEG thumbnail behavior.

    @param image: Pillow image instance.
    @return: RGB Pillow image suitable for JPEG encoding.
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

    @param image: Pillow image instance.
    @return: Pillow image suitable for WebP encoding.
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

    @param source_path: Original image selected for manual upload.
    @param output_root: Temporary parent directory for generated files.
    @param client_upload_id: Request-local ID shared with the server metadata.
    @return: Generated thumbnail descriptors.
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

        @param config: Shared connection configuration captured from the UI.
        @param paths: Image paths selected by the user for manual upload.
        @param client_thumbnails: Whether to generate responsive thumbnails on
            this computer before uploading each original.
        @param thumbnail_workers: Process count used for local thumbnail work.
        @param upload_workers: Thread count used for concurrent HTTP uploads.
        @param events: Thread-safe queue used to send status messages to the GUI.
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
        self.completed_uploads = 0
        self.progress_lock = threading.Lock()

    def stop(self) -> None:
        """
        Request the manual upload worker to stop after the current item.

        @return: None.
        """
        self.stop_event.set()

    def emit(self, level: str, message: str) -> None:
        """
        Send a status message to the GUI and persistent log.

        @param level: Logging level name such as info, warning, or error.
        @param message: Human-readable status message.
        @return: None.
        """
        self.events.put((level, message))
        log_method = getattr(logging, level if level in {"debug", "info", "warning", "error"} else "info")
        log_method(message)

    def run(self) -> None:
        """
        Run the selected manual upload mode.

        @return: None.
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

        if self.client_thumbnails:
            self.run_with_client_thumbnails(upload_url)
        else:
            self.run_with_server_thumbnails(upload_url)

        self.emit("info", f"Manual upload finished: uploaded={self.uploaded}, failed={self.failed}.")

    def run_with_server_thumbnails(self, upload_url: str) -> None:
        """
        Upload selected originals and ask PHP Gallery to create thumbnails.

        This mode still uses parallel HTTP uploads because multipart requests are
        network-bound. The worker count is intentionally modest so shared hosting
        is not overwhelmed by many simultaneous PHP upload requests.

        @param upload_url: Normalized PHP Gallery upload endpoint.
        @return: None.
        """
        self.emit("info", f"Uploading with {self.upload_workers} upload thread(s).")
        with concurrent.futures.ThreadPoolExecutor(max_workers=self.upload_workers) as executor:
            pending: Dict[concurrent.futures.Future[None], Path] = {}
            path_iterator = iter(enumerate(self.paths, start=1))
            queue_limit = max(1, self.upload_workers * 2)

            def submit_next() -> bool:
                """
                Submit one server-thumbnail upload when input remains.

                @return: True when an upload task was queued.
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

        @param upload_url: Normalized PHP Gallery upload endpoint.
        @return: None.
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

            @return: True when a thumbnail task was submitted.
            """
            try:
                index, path = next(path_iterator)
            except StopIteration:
                return False
            client_upload_id = uuid.uuid4().hex
            future = thumbnail_executor.submit(generate_local_thumbnails, path, temp_root, client_upload_id)
            pending_thumbnails[future] = (index, path, client_upload_id)
            return True

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

            @param path: Original image path to upload.
            @param index: One-based item index for progress messages.
            @param thumbnails: Local thumbnail files to include in the request.
            @param client_upload_id: Request-local ID mapping thumbnails to the
                original image on the server.
            @param create_server_thumbnails: Whether the server should generate
                thumbnails after accepting the original.
            @param cleanup_dir: Temporary directory to delete after upload.
            @return: None.
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

            @param wait_for_one: When True, wait until at least one upload has
                completed. When False, only collect uploads already finished.
            @return: None.
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

        @param upload_url: Normalized PHP Gallery upload endpoint.
        @param path: Original image path to upload.
        @param index: One-based item index for status messages.
        @param thumbnails: Local thumbnail files to submit with the original.
        @param client_upload_id: Request-local ID used to map thumbnails to the
            stored image record.
        @param create_server_thumbnails: Whether PHP Gallery should generate
            thumbnails after accepting the original.
        @return: None.
        """
        try:
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

        @return: None.
        @raises RuntimeError: Raised when Tkinter is not available.
        """
        if tk is None or ttk is None or filedialog is None or messagebox is None:
            raise RuntimeError("Tkinter is not available in this Python installation.")

        self.root = tk.Tk()
        self.root.title("PHP Gallery uploader")
        self.root.geometry("980x760")

        self.config_store = ConfigStore()
        self.config = self.config_store.load()
        self.events: "queue.Queue[Tuple[str, str]]" = queue.Queue()
        self.worker: Optional[WatcherThread] = None
        self.manual_worker: Optional[ManualUploadThread] = None
        self.manual_paths: List[Path] = []

        self.watched_folder_var = tk.StringVar(value=self.config.watched_folder)
        self.gallery_url_var = tk.StringVar(value=self.config.gallery_url)
        self.api_key_var = tk.StringVar(value=self.config.api_key)
        self.interval_var = tk.StringVar(value=str(self.config.scan_interval_seconds))
        self.stable_var = tk.StringVar(value=str(self.config.stable_seconds))
        self.create_thumbnails_var = tk.BooleanVar(value=self.config.create_thumbnails)
        self.manual_local_thumbnails_var = tk.BooleanVar(value=True)
        self.manual_thumbnail_workers_var = tk.StringVar(value=format_worker_choice(self.config.manual_thumbnail_workers))
        self.manual_upload_workers_var = tk.StringVar(value=format_worker_choice(self.config.manual_upload_workers))
        self.thumbnail_runtime_var = tk.StringVar(value=thumbnail_runtime_status())
        self.manual_selection_var = tk.StringVar(value="No files selected")
        self.status_var = tk.StringVar(value="Watcher stopped")
        self.manual_status_var = tk.StringVar(value="Manual upload idle")

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

        title = ttk.Label(outer, text="PHP Gallery uploader", font=("Segoe UI", 16, "bold"))
        title.pack(anchor="w")
        subtitle = ttk.Label(
            outer,
            text="Uploads images through one gallery-scoped API key, either from a watched folder or from a manual selection.",
        )
        subtitle.pack(anchor="w", pady=(2, 14))

        connection = ttk.LabelFrame(outer, text="Shared connection settings")
        connection.pack(fill="x", pady=(0, 12))
        connection.columnconfigure(1, weight=1)

        ttk.Label(connection, text="Gallery URL or upload endpoint").grid(row=0, column=0, sticky="w", padx=8, pady=6)
        ttk.Entry(connection, textvariable=self.gallery_url_var).grid(row=0, column=1, columnspan=2, sticky="ew", padx=8, pady=6)

        ttk.Label(connection, text="API key").grid(row=1, column=0, sticky="w", padx=8, pady=6)
        ttk.Entry(connection, textvariable=self.api_key_var, show="*").grid(row=1, column=1, columnspan=2, sticky="ew", padx=8, pady=6)

        ttk.Button(connection, text="Save configuration", command=self.save_config).grid(row=2, column=1, sticky="w", padx=8, pady=(4, 8))
        ttk.Button(connection, text="Open config folder", command=self.open_config_folder).grid(row=2, column=2, sticky="e", padx=8, pady=(4, 8))

        notebook = ttk.Notebook(outer)
        notebook.pack(fill="x", pady=(0, 12))

        watch_tab = ttk.Frame(notebook, padding=12)
        manual_tab = ttk.Frame(notebook, padding=12)
        notebook.add(watch_tab, text="Watch folder")
        notebook.add(manual_tab, text="Manual upload")

        self.build_watch_tab(watch_tab)
        self.build_manual_tab(manual_tab)

        log_frame = ttk.LabelFrame(outer, text="Status log")
        log_frame.pack(fill="both", expand=True)
        self.log_text = tk.Text(log_frame, height=18, wrap="word")
        self.log_text.pack(fill="both", expand=True, padx=8, pady=8)
        self.log_text.configure(state="disabled")

        self.write_log(f"Configuration: {CONFIG_PATH}")
        self.write_log(f"State: {STATE_PATH}")
        self.write_log(f"Log: {LOG_PATH}")
        self.write_log(thumbnail_runtime_status())

    def build_watch_tab(self, parent: Any) -> None:
        """
        Build controls dedicated to watch-folder uploading.

        @param parent: Tkinter frame that receives the controls.
        @return: None.
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

        actions = ttk.Frame(parent)
        actions.grid(row=4, column=0, columnspan=3, sticky="ew", pady=(10, 0))
        ttk.Button(actions, text="Start watching", command=self.start).pack(side="left")
        ttk.Button(actions, text="Stop", command=self.stop).pack(side="left", padx=8)
        ttk.Label(actions, textvariable=self.status_var).pack(side="right")

    def build_manual_tab(self, parent: Any) -> None:
        """
        Build controls dedicated to manual bulk uploading.

        @param parent: Tkinter frame that receives the controls.
        @return: None.
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
        ttk.Button(runtime_row, text="Install or repair Pillow", command=self.repair_pillow).grid(row=0, column=1, sticky="e", padx=(8, 0))

        self.refresh_thumbnail_controls()

        actions = ttk.Frame(parent)
        actions.grid(row=5, column=0, columnspan=4, sticky="ew", pady=(10, 0))
        ttk.Button(actions, text="Start manual upload", command=self.start_manual_upload).pack(side="left")
        ttk.Button(actions, text="Stop manual upload", command=self.stop_manual_upload).pack(side="left", padx=8)
        ttk.Label(actions, textvariable=self.manual_status_var).pack(side="right")

    def refresh_thumbnail_controls(self) -> None:
        """
        Refresh local thumbnail availability in the manual upload tab.

        @return: None.
        """
        self.thumbnail_runtime_var.set(thumbnail_runtime_status())
        if local_thumbnail_supported():
            self.thumbnail_check.state(["!disabled"])
        else:
            self.thumbnail_check.state(["disabled"])
            self.manual_local_thumbnails_var.set(False)

    def repair_pillow(self) -> None:
        """
        Install Pillow into the Python interpreter currently running the GUI.

        @return: None.
        """
        self.write_log("Installing or repairing Pillow for the current Python runtime...")
        ok, output = install_pillow_for_current_runtime()
        for line in output.splitlines()[-12:]:
            self.write_log(line)
        if ok:
            messagebox.showinfo("Pillow repair", "Pillow is available for this Python runtime.")
        else:
            messagebox.showerror("Pillow repair failed", output[-1200:] if output else "pip failed without output")
        self.refresh_thumbnail_controls()

    def current_config(self) -> WatcherConfig:
        """
        Read and validate the current UI values.

        @return: WatcherConfig built from the current form state.
        @raises ValueError: Raised when numeric settings cannot be parsed.
        """
        try:
            interval = max(0.2, float(self.interval_var.get().strip() or DEFAULT_INTERVAL_SECONDS))
            stable = max(0.5, float(self.stable_var.get().strip() or DEFAULT_STABLE_SECONDS))
            thumbnail_workers = parse_worker_choice(self.manual_thumbnail_workers_var.get())
            upload_workers = parse_worker_choice(self.manual_upload_workers_var.get())
        except ValueError as exc:
            raise ValueError("Scan interval, stable file seconds, and worker counts must be numeric.") from exc

        return WatcherConfig(
            watched_folder=self.watched_folder_var.get().strip(),
            gallery_url=self.gallery_url_var.get().strip(),
            api_key=self.api_key_var.get().strip(),
            scan_interval_seconds=interval,
            stable_seconds=stable,
            create_thumbnails=bool(self.create_thumbnails_var.get()),
            manual_thumbnail_workers=thumbnail_workers,
            manual_upload_workers=upload_workers,
        )

    def browse_folder(self) -> None:
        """
        Open a folder picker and store the selected path in the UI.

        @return: None.
        """
        selected = filedialog.askdirectory(initialdir=self.watched_folder_var.get() or str(Path.home()))
        if selected:
            self.watched_folder_var.set(selected)

    def select_manual_files(self) -> None:
        """
        Open a file picker and add supported images to the manual upload list.

        @return: None.
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

        @return: None.
        """
        self.manual_paths = []
        self.refresh_manual_file_label()

    def refresh_manual_file_label(self) -> None:
        """
        Refresh the visible manual selection count.

        @return: None.
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

    def start_manual_upload(self) -> None:
        """
        Start the manual upload worker using the current shared connection fields.

        @return: None.
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
        self.write_log("Manual upload worker started.")

    def stop_manual_upload(self) -> None:
        """
        Request the manual upload worker to stop.

        @return: None.
        """
        if self.manual_worker:
            self.manual_worker.stop()
        self.manual_status_var.set("Manual upload stopped")

    def close(self) -> None:
        """
        Stop background work and close the window.

        @return: None.
        """
        self.stop()
        self.stop_manual_upload()
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
            if message.startswith("Manual upload finished"):
                self.manual_status_var.set("Manual upload idle")
            elif message.startswith("Manual upload stopped"):
                self.manual_status_var.set("Manual upload stopped")
            elif level == "error":
                self.status_var.set("Running with errors")
                if self.manual_worker and self.manual_worker.is_alive():
                    self.manual_status_var.set("Manual upload has errors")

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
    multiprocessing.freeze_support()
    raise SystemExit(main())
