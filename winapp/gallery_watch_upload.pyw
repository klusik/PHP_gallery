"""
PHP Gallery watched-folder uploader.

This Windows companion app watches one local folder and uploads stable image
files to PHP Gallery through a gallery-scoped API key. Gallery validation,
storage, scanning, and thumbnail generation remain server-side concerns.
"""

from __future__ import annotations

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
from typing import Any
from urllib import error, parse, request

try:
    import tkinter as tk
    from tkinter import filedialog, messagebox, ttk
except ImportError:  # pragma: no cover
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
    """Configuration saved locally for the watcher app."""

    watched_folder: str = ""
    gallery_url: str = ""
    api_key: str = ""
    scan_interval_seconds: float = DEFAULT_INTERVAL_SECONDS
    stable_seconds: float = DEFAULT_STABLE_SECONDS
    create_thumbnails: bool = True

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> "WatcherConfig":
        """Build a typed configuration object from loaded JSON data."""
        return cls(
            watched_folder=str(data.get("watched_folder", "")),
            gallery_url=str(data.get("gallery_url", "")),
            api_key=str(data.get("api_key", "")),
            scan_interval_seconds=float(data.get("scan_interval_seconds", DEFAULT_INTERVAL_SECONDS) or DEFAULT_INTERVAL_SECONDS),
            stable_seconds=float(data.get("stable_seconds", DEFAULT_STABLE_SECONDS) or DEFAULT_STABLE_SECONDS),
            create_thumbnails=bool(data.get("create_thumbnails", True)),
        )

    def to_dict(self) -> dict[str, Any]:
        """Return a JSON-serializable representation."""
        return {
            "watched_folder": self.watched_folder,
            "gallery_url": self.gallery_url,
            "api_key": self.api_key,
            "scan_interval_seconds": self.scan_interval_seconds,
            "stable_seconds": self.stable_seconds,
            "create_thumbnails": self.create_thumbnails,
        }


class ConfigStore:
    """Loads and saves local app configuration."""

    def __init__(self, path: Path = CONFIG_PATH) -> None:
        self.path = path

    def load(self) -> WatcherConfig:
        """Load the configuration file, returning defaults when it is missing."""
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
        """Persist configuration atomically."""
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = self.path.with_suffix(".tmp")
        tmp_path.write_text(json.dumps(config.to_dict(), indent=2), encoding="utf-8")
        tmp_path.replace(self.path)


class UploadState:
    """Tracks completed uploads and retry scheduling."""

    def __init__(self, path: Path = STATE_PATH) -> None:
        self.path = path
        self.data: dict[str, Any] = {
            "uploaded_paths": {},
            "uploaded_hashes": {},
            "failures": {},
        }
        self.load()

    def load(self) -> None:
        """Load upload state from disk."""
        if not self.path.is_file():
            return
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
            if isinstance(data, dict):
                self.data["uploaded_paths"] = data.get("uploaded_paths", {}) if isinstance(data.get("uploaded_paths", {}), dict) else {}
                self.data["uploaded_hashes"] = data.get("uploaded_hashes", {}) if isinstance(data.get("uploaded_hashes", {}), dict) else {}
                self.data["failures"] = data.get("failures", {}) if isinstance(data.get("failures", {}), dict) else {}
        except (OSError, json.JSONDecodeError):
            return

    def save(self) -> None:
        """Persist upload state atomically."""
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = self.path.with_suffix(".tmp")
        tmp_path.write_text(json.dumps(self.data, indent=2), encoding="utf-8")
        tmp_path.replace(self.path)

    def already_uploaded_path(self, path: Path, file_hash: str) -> bool:
        """Return true when this exact path and content already succeeded."""
        entry = self.data["uploaded_paths"].get(str(path))
        return isinstance(entry, dict) and entry.get("sha256") == file_hash

    def already_uploaded_hash(self, file_hash: str) -> bool:
        """Return true when this content already succeeded under any path."""
        return file_hash in self.data["uploaded_hashes"]

    def mark_uploaded(self, path: Path, file_hash: str, size: int, response: dict[str, Any]) -> None:
        """Record a successful upload after the server confirms it."""
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
        self.data["failures"].pop(str(path), None)
        self.save()

    def mark_duplicate(self, path: Path, file_hash: str, size: int) -> None:
        """Record a skipped file whose content was already uploaded earlier."""
        self.data["uploaded_paths"][str(path)] = {
            "sha256": file_hash,
            "size": size,
            "uploaded_at": time.time(),
            "skipped_duplicate": True,
        }
        self.data["failures"].pop(str(path), None)
        self.save()

    def retry_allowed(self, path: Path, file_hash: str) -> bool:
        """Return true when a failed file may be retried now."""
        failure = self.data["failures"].get(str(path))
        if not isinstance(failure, dict):
            return True
        if failure.get("sha256") != file_hash:
            return True
        return time.time() >= float(failure.get("next_retry_at", 0) or 0)

    def mark_failure(self, path: Path, file_hash: str, message: str) -> None:
        """Record a failed attempt and schedule a later retry."""
        previous = self.data["failures"].get(str(path))
        previous_attempts = int(previous.get("attempts", 0)) if isinstance(previous, dict) and previous.get("sha256") == file_hash else 0
        attempts = previous_attempts + 1
        retry_delay = min(3600, 5 * (2 ** min(attempts - 1, 7)))
        self.data["failures"][str(path)] = {
            "sha256": file_hash,
            "attempts": attempts,
            "last_error": message,
            "last_failed_at": time.time(),
            "next_retry_at": time.time() + retry_delay,
        }
        self.save()


class FileStabilityTracker:
    """Detects when copied files have stopped changing."""

    def __init__(self, stable_seconds: float) -> None:
        self.stable_seconds = max(0.5, stable_seconds)
        self._seen: dict[Path, tuple[int, float, float]] = {}

    def stable(self, path: Path) -> bool:
        """Return true when size and mtime have remained unchanged long enough."""
        try:
            stat = path.stat()
        except OSError:
            self._seen.pop(path, None)
            return False
        size = int(stat.st_size)
        mtime = float(stat.st_mtime)
        now = time.time()
        previous = self._seen.get(path)
        if previous is None or previous[0] != size or previous[1] != mtime:
            self._seen[path] = (size, mtime, now)
            return False
        return now - previous[2] >= self.stable_seconds


def setup_logging() -> None:
    """Configure file logging for watcher diagnostics."""
    CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    logging.basicConfig(
        filename=str(LOG_PATH),
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
    )


def normalize_upload_url(value: str) -> str:
    """Return a usable upload endpoint from either a site root or full endpoint."""
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
    """Calculate SHA-256 without loading the whole file at once."""
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while True:
            chunk = handle.read(1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest()


def iter_candidate_files(folder: Path) -> list[Path]:
    """Return supported image files directly inside the watched folder."""
    try:
        candidates = [item for item in folder.iterdir() if item.is_file() and item.suffix.lower() in SUPPORTED_SUFFIXES]
    except OSError:
        return []
    return sorted(candidates, key=lambda item: (item.stat().st_mtime if item.exists() else 0, item.name.lower()))


def multipart_upload(upload_url: str, api_key: str, path: Path, create_thumbnails: bool) -> dict[str, Any]:
    """Upload one image file using only Python standard-library HTTP tools."""
    boundary = "PHPGalleryUpload" + uuid.uuid4().hex
    content_type = mimetypes.guess_type(path.name)[0] or "application/octet-stream"
    fields = {
        "create_thumbnails": "1" if create_thumbnails else "0",
    }
    body_parts: list[bytes] = []
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
    """Background worker that scans, uploads, and reports status events."""

    def __init__(self, config: WatcherConfig, events: "queue.Queue[tuple[str, str]]") -> None:
        super().__init__(daemon=True)
        self.config = config
        self.events = events
        self.stop_event = threading.Event()
        self.state = UploadState()
        self.stability = FileStabilityTracker(config.stable_seconds)
        self.initial_paths: set[Path] = set()

    def stop(self) -> None:
        """Request the worker to stop."""
        self.stop_event.set()

    def emit(self, level: str, message: str) -> None:
        """Send a message to the UI and log file."""
        self.events.put((level, message))
        getattr(logging, level if level in {"debug", "info", "warning", "error"} else "info")(message)

    def run(self) -> None:
        """Run the polling loop until stopped."""
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
        """Scan the watched folder once and process files that are ready."""
        for path in iter_candidate_files(folder):
            if self.stop_event.is_set():
                return
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
    """Tkinter user interface for the watched-folder uploader."""

    def __init__(self) -> None:
        if tk is None or ttk is None or filedialog is None or messagebox is None:
            raise RuntimeError("Tkinter is not available in this Python installation.")
        self.root = tk.Tk()
        self.root.title("PHP Gallery watched-folder uploader")
        self.root.geometry("860x620")
        self.config_store = ConfigStore()
        self.config = self.config_store.load()
        self.events: "queue.Queue[tuple[str, str]]" = queue.Queue()
        self.worker: WatcherThread | None = None
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
        """Create the visible controls."""
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
        """Read and validate the current UI values."""
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
        """Open a folder picker and store the selected path in the UI."""
        selected = filedialog.askdirectory(initialdir=self.watched_folder_var.get() or str(Path.home()))
        if selected:
            self.watched_folder_var.set(selected)

    def save_config(self) -> None:
        """Persist current settings to the local config file."""
        try:
            config = self.current_config()
            self.config_store.save(config)
            self.config = config
            self.write_log("Configuration saved.")
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))

    def start(self) -> None:
        """Start the watcher worker."""
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
        """Stop the watcher worker."""
        if self.worker:
            self.worker.stop()
        self.status_var.set("Stopped")

    def close(self) -> None:
        """Stop background work and close the window."""
        self.stop()
        self.root.after(150, self.root.destroy)

    def open_config_folder(self) -> None:
        """Open the folder containing config, state, and log files."""
        CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        webbrowser.open(str(CONFIG_DIR))

    def drain_events(self) -> None:
        """Move worker events into the visible log."""
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
        """Append one line to the status log."""
        stamp = time.strftime("%H:%M:%S")
        self.log_text.configure(state="normal")
        self.log_text.insert("end", f"[{stamp}] {message}\n")
        self.log_text.see("end")
        self.log_text.configure(state="disabled")

    def run(self) -> None:
        """Run the Tkinter event loop."""
        self.root.mainloop()


def run_once(config: WatcherConfig) -> int:
    """Run one scan without showing the GUI."""
    setup_logging()
    events: "queue.Queue[tuple[str, str]]" = queue.Queue()
    worker = WatcherThread(config, events)
    folder = Path(config.watched_folder)
    upload_url = normalize_upload_url(config.gallery_url)
    worker.scan_once(folder, upload_url)
    while not events.empty():
        level, message = events.get()
        print(f"{level.upper()}: {message}")
    return 0


def parse_args(argv: list[str]) -> argparse.Namespace:
    """Parse optional command-line switches."""
    parser = argparse.ArgumentParser(description="Watch a folder and upload new images to PHP Gallery.")
    parser.add_argument("--once", action="store_true", help="Run one scan using saved configuration and exit.")
    parser.add_argument("--config", default=str(CONFIG_PATH), help="Path to a config JSON file.")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    """Application entry point."""
    setup_logging()
    args = parse_args(list(argv if argv is not None else sys.argv[1:]))
    store = ConfigStore(Path(args.config))
    config = store.load()
    if args.once:
        return run_once(config)
    app = WatcherApp()
    app.run()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
