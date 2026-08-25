#!/usr/bin/env python3
"""
Long-running first-party HTTP monitor for PHP Gallery and similar websites.

The monitor is intentionally self-contained and uses only the Python standard
library.  It is designed for unattended multi-hour diagnostics of intermittent
"Not Found", timeout, cold-cache, reverse-proxy, TLS, and first-byte problems.

Normal usage is interactive::

    python3 gallery_http_monitor.py

The script asks for the website and a few conservative scenario parameters.  It
then repeats this cycle until the requested duration elapses or Ctrl+C is used:

1. leave the website idle using a repeating short/medium/long schedule;
2. alternate a fixed sentinel URL and a discovered page for the "cold" request;
3. immediately request the exact same page again for a warm comparison;
4. browse a few discovered same-origin public links;
5. perform a deliberately small, bounded concurrent GET burst;
6. persist results, periodically create a consistent live ZIP, and return to idle.

For every HTTP transaction it records DNS answers and timing, selected remote
IP, TCP timing, TLS timing and metadata, HTTP status, response headers, time to
response headers (TTFB approximation), body transfer timing, response size and
SHA-256.  HTML is parsed only to discover same-origin links and first-party
assets.  JavaScript is not executed, so this is a browser-like HTTP workload,
not a full browser engine.

Potentially dangerous public paths (admin actions, upload, setup, reset, update,
logout, delete, API endpoints, etc.) are excluded from automatic navigation.
Only GET requests are generated.  The script does not import browser cookies or
credentials.  Cookie and Set-Cookie values are redacted in diagnostic files.

When an anomaly is detected, the monitor saves a dedicated snapshot, compares
the same URL through each resolved server address while preserving Host/SNI, and
makes one immediate confirmation request. If system curl is already available,
default, HTTP/1.1, and supported HTTP/2/HTTP/3 snapshots are captured. Results
are appended continuously; full request bodies are not retained in RAM. No
software is installed and curl is never required for normal operation.
"""

import argparse
import csv
import gzip
import hashlib
import html
import http.client
import ipaddress
import json
import math
import os
import random
import re
import shutil
import socket
import ssl
import statistics
import subprocess
import sys
import threading
import time
import traceback
import zipfile
import zlib
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser
from http.cookies import SimpleCookie
from pathlib import Path
from typing import Any, Iterable
from urllib.parse import parse_qsl, quote, urljoin, urlsplit, urlunsplit


VERSION = "1.2.1"
DEFAULT_HOURS = 0.0
DEFAULT_IDLE_SECONDS = 120.0
DEFAULT_MEDIUM_IDLE_SECONDS = 300.0
DEFAULT_LONG_IDLE_SECONDS = 600.0
DEFAULT_SNAPSHOT_EVERY_CYCLES = 5
MAX_STATS_SAMPLES = 20_000
MAX_PAGE_POOL = 10_000
MAX_ASSET_POOL = 20_000
FSYNC_EVERY_REQUESTS = 10
DEFAULT_BROWSE_PAGES = 3
DEFAULT_BURST_REQUESTS = 12
DEFAULT_BURST_CONCURRENCY = 4
DEFAULT_ASSET_LIMIT = 16
DEFAULT_ASSET_CONCURRENCY = 4
DEFAULT_CONNECT_TIMEOUT = 5.0
DEFAULT_REQUEST_TIMEOUT = 20.0
DEFAULT_SLOW_TTFB_SECONDS = 3.0
DEFAULT_SLOW_TOTAL_SECONDS = 8.0
DEFAULT_WARM_RELOAD_DELAY = 1.0
DEFAULT_BROWSE_DELAY_MIN = 1.0
DEFAULT_BROWSE_DELAY_MAX = 3.0
MAX_REDIRECTS = 5
MAX_HTML_STORE_BYTES = 8 * 1024 * 1024
MAX_ANOMALY_BODY_BYTES = 2 * 1024 * 1024
MAX_BODY_PREVIEW_BYTES = 16 * 1024

# Matches the user's ordinary macOS Chrome family closely enough to exercise the
# same generic CDN/WAF/browser path without pretending to be a special bot.
DEFAULT_USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/151.0.0.0 Safari/537.36"
)

# GET-only navigation is further constrained to avoid endpoints that are likely
# to be administrative, mutating, authenticated, installer, or API actions.
DANGEROUS_PATH_TOKENS = {
    "admin",
    "api",
    "archive",
    "delete",
    "destroy",
    "download",
    "download-original",
    "export",
    "install",
    "logout",
    "maintenance",
    "migrate",
    "revoke",
    "reset",
    "setup",
    "update",
    "upload",
    "webdav",
}
DANGEROUS_QUERY_TOKENS = {
    "action",
    "api_key",
    "apikey",
    "csrf",
    "archive",
    "delete",
    "destroy",
    "download",
    "export",
    "key",
    "logout",
    "nonce",
    "password",
    "revoke",
    "setup",
    "token",
    "update",
    "upload",
}
SENSITIVE_HEADER_NAMES = {
    "authorization",
    "cookie",
    "proxy-authorization",
    "set-cookie",
    "x-api-key",
    "x-gallery-api-key",
}
ASSET_EXTENSIONS = {
    ".avif",
    ".css",
    ".gif",
    ".ico",
    ".jpeg",
    ".jpg",
    ".js",
    ".mjs",
    ".png",
    ".svg",
    ".webp",
    ".woff",
    ".woff2",
}
PAGE_EXTENSIONS = {"", ".htm", ".html", ".php"}


PRINT_LOCK = threading.Lock()


def console(message: str = "") -> None:
    """Print one monitor line without interleaving concurrent worker output."""

    with PRINT_LOCK:
        print(message, flush=True)


def now_local_iso() -> str:
    """Return the current local timestamp with timezone offset."""

    return datetime.now().astimezone().isoformat(timespec="milliseconds")


def now_utc_iso() -> str:
    """Return the current UTC timestamp."""

    return datetime.now(timezone.utc).isoformat(timespec="milliseconds")


def safe_filename(value: str, limit: int = 120) -> str:
    """Convert arbitrary text to a bounded filesystem-safe filename."""

    cleaned = re.sub(r"[^A-Za-z0-9._-]+", "_", value).strip("._")
    return (cleaned or "item")[:limit]


def percentile(values: list[float], fraction: float) -> float | None:
    """Return a linearly interpolated percentile for a list of floats."""

    if not values:
        return None
    ordered = sorted(values)
    if len(ordered) == 1:
        return ordered[0]
    position = (len(ordered) - 1) * fraction
    lower = math.floor(position)
    upper = math.ceil(position)
    if lower == upper:
        return ordered[lower]
    weight = position - lower
    return ordered[lower] * (1.0 - weight) + ordered[upper] * weight


def format_seconds(value: float | None) -> str:
    """Format seconds compactly for console output."""

    if value is None:
        return "-"
    if value < 1.0:
        return f"{value * 1000.0:.1f}ms"
    return f"{value:.3f}s"


def normalize_start_url(raw: str) -> str:
    """Normalize an interactively supplied website URL."""

    value = raw.strip()
    if not value:
        raise ValueError("Website URL cannot be empty.")
    if "://" not in value:
        value = "https://" + value
    parts = urlsplit(value)
    if parts.scheme.lower() not in {"http", "https"}:
        raise ValueError("Only http:// and https:// URLs are supported.")
    if not parts.hostname:
        raise ValueError("The URL does not contain a hostname.")
    if parts.username or parts.password:
        raise ValueError("URLs containing credentials are intentionally rejected.")
    path = parts.path or "/"
    return urlunsplit((parts.scheme.lower(), parts.netloc, path, parts.query, ""))


def canonicalize_url(url: str) -> str:
    """Remove fragments and normalize an empty path for deduplication."""

    parts = urlsplit(url)
    path = parts.path or "/"
    return urlunsplit((parts.scheme.lower(), parts.netloc.lower(), path, parts.query, ""))


def same_origin(url: str, origin: tuple[str, str, int]) -> bool:
    """Return whether a URL belongs to the configured scheme/host/port origin."""

    parts = urlsplit(url)
    scheme = parts.scheme.lower()
    host = (parts.hostname or "").lower()
    port = parts.port or (443 if scheme == "https" else 80)
    return (scheme, host, port) == origin


def url_looks_safe_for_get(url: str, origin: tuple[str, str, int]) -> bool:
    """Conservatively decide whether an automatically discovered URL is safe."""

    try:
        if not same_origin(url, origin):
            return False
        parts = urlsplit(url)
    except ValueError:
        return False

    path_lower = parts.path.lower()
    segments = [segment for segment in path_lower.split("/") if segment]
    if any(token in DANGEROUS_PATH_TOKENS for token in segments):
        return False

    extension = Path(parts.path).suffix.lower()
    if extension and extension not in PAGE_EXTENSIONS and extension not in ASSET_EXTENSIONS:
        return False

    query_keys = {key.lower() for key, _ in parse_qsl(parts.query, keep_blank_values=True)}
    if query_keys & DANGEROUS_QUERY_TOKENS:
        return False
    page = dict(parse_qsl(parts.query, keep_blank_values=True)).get("page", "").lower()
    if any(token in page for token in DANGEROUS_PATH_TOKENS):
        return False
    return True


def redact_headers(headers: list[tuple[str, str]]) -> list[tuple[str, str]]:
    """Redact credential-bearing header values while preserving header presence."""

    redacted: list[tuple[str, str]] = []
    for name, value in headers:
        if name.lower() in SENSITIVE_HEADER_NAMES:
            redacted.append((name, "[REDACTED]"))
        else:
            redacted.append((name, value))
    return redacted


def header_values(headers: Iterable[tuple[str, str]], wanted: str) -> list[str]:
    """Return all values for a case-insensitive header name."""

    wanted_lower = wanted.lower()
    return [value for name, value in headers if name.lower() == wanted_lower]


def first_header(headers: Iterable[tuple[str, str]], wanted: str) -> str:
    """Return the first value for a header or an empty string."""

    values = header_values(headers, wanted)
    return values[0] if values else ""


def decode_content(body: bytes, encoding: str) -> bytes:
    """Decode gzip/deflate content when possible, otherwise return raw bytes."""

    encoding_lower = encoding.lower().strip()
    try:
        if encoding_lower == "gzip":
            return gzip.decompress(body)
        if encoding_lower == "deflate":
            try:
                return zlib.decompress(body)
            except zlib.error:
                return zlib.decompress(body, -zlib.MAX_WBITS)
    except Exception:
        return body
    return body


def decode_text(body: bytes, content_type: str) -> str:
    """Decode textual response bytes using a declared charset or UTF-8 fallback."""

    charset = "utf-8"
    match = re.search(r"charset\s*=\s*([^;\s]+)", content_type, re.IGNORECASE)
    if match:
        charset = match.group(1).strip('"\'')
    try:
        return body.decode(charset, errors="replace")
    except LookupError:
        return body.decode("utf-8", errors="replace")


def is_plain_not_found(status: int | None, content_type: str, body: bytes) -> bool:
    """Detect the bare/minimal Not Found symptom being investigated."""

    if not body:
        return False
    decoded = decode_text(body[:MAX_BODY_PREVIEW_BYTES], content_type)
    stripped = html.unescape(decoded).strip()
    normalized = re.sub(r"\s+", " ", stripped).lower()
    if normalized in {"not found", "404 not found", "404: not found"}:
        return True
    if len(normalized) <= 512 and normalized.startswith("not found"):
        return True
    if status == 404 and len(normalized) <= 512 and "not found" in normalized:
        return True
    return False


def classify_not_found_response(
    status: int | None,
    content_type: str,
    headers: list[tuple[str, str]],
    body: bytes,
) -> str:
    """Classify minimal Not Found responses without assuming every 404 has one origin."""

    if not is_plain_not_found(status, content_type, body):
        return ""
    normalized = re.sub(
        r"\s+",
        " ",
        html.unescape(decode_text(body[:MAX_BODY_PREVIEW_BYTES], content_type)).strip(),
    ).lower()
    x_robots = " ".join(header_values(headers, "X-Robots-Tag")).lower()
    cache_control = " ".join(header_values(headers, "Cache-Control")).lower()
    pragma = " ".join(header_values(headers, "Pragma")).lower()
    if (
        status == 404
        and normalized in {"not found", "not found."}
        and "noindex" in x_robots
        and "nofollow" in x_robots
        and "no-store" in cache_control
        and "no-cache" in pragma
    ):
        return "likely_gallery_seo_guard_404"
    return "generic_plain_not_found"


def find_curl_binary() -> str | None:
    """Return an existing system curl executable without installing anything."""

    candidates = [shutil.which("curl"), shutil.which("curl.exe")]
    system_root = os.environ.get("SystemRoot", "")
    if system_root:
        candidates.append(str(Path(system_root) / "System32" / "curl.exe"))
    candidates.extend([
        "/usr/bin/curl",
        "/usr/local/bin/curl",
        "/opt/homebrew/bin/curl",
    ])
    for candidate in candidates:
        if candidate and Path(candidate).is_file():
            return candidate
    return None


def curl_capabilities(curl: str) -> set[str]:
    """Return normalized capabilities reported by an existing curl binary."""

    try:
        completed = subprocess.run(
            [curl, "--version"],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=5.0,
            check=False,
            text=True,
        )
    except Exception:
        return set()
    text = completed.stdout.upper()
    capabilities = {"DEFAULT", "HTTP1"}
    if "HTTP2" in text:
        capabilities.add("HTTP2")
    if "HTTP3" in text:
        capabilities.add("HTTP3")
    return capabilities


class LinkCollector(HTMLParser):
    """Collect navigable links and first-party asset candidates from HTML."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.pages: set[str] = set()
        self.assets: set[str] = set()

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        """Collect URL-bearing attributes from common browser resource tags."""

        values = {key.lower(): value for key, value in attrs if value is not None}
        tag_lower = tag.lower()
        if tag_lower == "a" and values.get("href"):
            self.pages.add(values["href"])
        if tag_lower in {"img", "script", "iframe", "source", "video", "audio"}:
            if values.get("src"):
                self.assets.add(values["src"])
        if tag_lower == "link" and values.get("href"):
            rel = (values.get("rel") or "").lower()
            if any(token in rel for token in ("stylesheet", "icon", "preload", "modulepreload")):
                self.assets.add(values["href"])
        srcset = values.get("srcset")
        if srcset:
            for candidate in srcset.split(","):
                url_part = candidate.strip().split(" ", 1)[0]
                if url_part:
                    self.assets.add(url_part)


@dataclass(slots=True)
class MonitorConfig:
    """Runtime configuration for one monitor session."""

    start_url: str
    hours: float
    idle_seconds: float
    medium_idle_seconds: float
    long_idle_seconds: float
    snapshot_every_cycles: int
    browse_pages: int
    burst_requests: int
    burst_concurrency: int
    asset_limit: int
    asset_concurrency: int
    connect_timeout: float
    request_timeout: float
    slow_ttfb_seconds: float
    slow_total_seconds: float
    warm_reload_delay: float
    curl_on_anomaly: bool
    user_agent: str
    log_root: Path


@dataclass(slots=True)
class RequestResult:
    """Complete diagnostics for one physical HTTP request/redirect hop."""

    request_id: int
    cycle: int
    phase: str
    parent_request_id: int | None
    timestamp_local: str
    timestamp_utc: str
    method: str
    url: str
    host: str
    scheme: str
    port: int
    forced_ip: str = ""
    http_version: str = "HTTP/1.1"
    request_headers: list[tuple[str, str]] = field(default_factory=list)
    status: int | None = None
    reason: str = ""
    remote_ip: str = ""
    remote_port: int | None = None
    local_ip: str = ""
    local_port: int | None = None
    dns_addresses: list[str] = field(default_factory=list)
    dns_seconds: float | None = None
    tcp_seconds: float | None = None
    tls_seconds: float | None = None
    request_send_seconds: float | None = None
    ttfb_seconds: float | None = None
    body_seconds: float | None = None
    total_seconds: float | None = None
    tls_version: str = ""
    tls_cipher: str = ""
    tls_alpn: str = ""
    tls_cert_subject: str = ""
    tls_cert_issuer: str = ""
    tls_cert_not_after: str = ""
    response_http_version: str = ""
    content_type: str = ""
    content_encoding: str = ""
    content_length_header: str = ""
    bytes_received: int = 0
    body_sha256: str = ""
    location: str = ""
    cache_control: list[str] = field(default_factory=list)
    age: str = ""
    server: str = ""
    via: list[str] = field(default_factory=list)
    x_headers: dict[str, list[str]] = field(default_factory=dict)
    response_headers: list[tuple[str, str]] = field(default_factory=list)
    body_preview: str = ""
    plain_not_found: bool = False
    not_found_class: str = ""
    anomaly_reasons: list[str] = field(default_factory=list)
    error_type: str = ""
    error_message: str = ""
    traceback: str = ""
    final_url_after_redirects: str = ""
    body_for_processing: bytes = field(default=b"", repr=False)

    @property
    def ok(self) -> bool:
        """Return whether the transaction completed with a non-error HTTP status."""

        return not self.error_type and self.status is not None and self.status < 400


class SimpleCookieStore:
    """Very small same-origin cookie store used only for public browser realism."""

    def __init__(self) -> None:
        self._cookies: dict[str, str] = {}
        self._lock = threading.Lock()

    def update_from_headers(self, headers: list[tuple[str, str]]) -> None:
        """Store simple Set-Cookie name/value pairs without persisting secrets."""

        with self._lock:
            for raw in header_values(headers, "Set-Cookie"):
                cookie = SimpleCookie()
                try:
                    cookie.load(raw)
                except Exception:
                    continue
                for key, morsel in cookie.items():
                    if morsel.value:
                        self._cookies[key] = morsel.value
                    else:
                        self._cookies.pop(key, None)

    def header_value(self) -> str:
        """Return a Cookie header value for the current in-memory session."""

        with self._lock:
            return "; ".join(f"{key}={value}" for key, value in sorted(self._cookies.items()))


class RunLogger:
    """Persist request data immediately while keeping only bounded aggregate state in RAM."""

    CSV_FIELDS = [
        "request_id",
        "cycle",
        "phase",
        "timestamp_local",
        "method",
        "url",
        "status",
        "remote_ip",
        "forced_ip",
        "dns_seconds",
        "tcp_seconds",
        "tls_seconds",
        "ttfb_seconds",
        "body_seconds",
        "total_seconds",
        "bytes_received",
        "content_type",
        "server",
        "age",
        "cache_control",
        "plain_not_found",
        "not_found_class",
        "anomaly_reasons",
        "error_type",
        "error_message",
    ]

    def __init__(self, root: Path, config: MonitorConfig) -> None:
        run_stamp = datetime.now().astimezone().strftime("%Y%m%d-%H%M%S")
        host = safe_filename(urlsplit(config.start_url).hostname or "site")
        self.run_dir = root / f"{run_stamp}_{host}"
        self.run_dir.mkdir(parents=True, exist_ok=False)
        self.anomaly_dir = self.run_dir / "anomalies"
        self.anomaly_dir.mkdir()
        self.anomaly_report_path = self.anomaly_dir / "report.html"
        self.jsonl_path = self.run_dir / "requests.jsonl"
        self.csv_path = self.run_dir / "requests.csv"
        self.events_path = self.run_dir / "events.log"
        self.summary_path = self.run_dir / "summary.txt"
        self.config_path = self.run_dir / "config.json"
        self.live_snapshot_path = self.run_dir.parent / f"{self.run_dir.name}.live.zip"
        self.snapshot_every_cycles = max(1, config.snapshot_every_cycles)
        self._lock = threading.RLock()
        self._request_count = 0
        self._anomaly_count = 0
        self._error_count = 0
        self._statuses: dict[str, int] = {}
        self._remote_ips: dict[str, int] = {}
        self._phases: dict[str, int] = {}
        self._not_found_classes: dict[str, int] = {}
        self._ttfb_samples: list[float] = []
        self._total_samples: list[float] = []
        self._ttfb_seen = 0
        self._total_seen = 0
        self._write_config(config)
        with self.csv_path.open("w", newline="", encoding="utf-8") as handle:
            writer = csv.DictWriter(handle, fieldnames=self.CSV_FIELDS)
            writer.writeheader()
            self._flush_handle(handle, durable=True)

    @staticmethod
    def _flush_handle(handle: Any, durable: bool) -> None:
        """Flush one open log handle and optionally force it through the OS filesystem cache."""

        handle.flush()
        if durable:
            try:
                os.fsync(handle.fileno())
            except OSError:
                pass

    @staticmethod
    def _write_text_durable(path: Path, text: str) -> None:
        """Write a small text artifact and fsync it before returning."""

        with path.open("w", encoding="utf-8") as handle:
            handle.write(text)
            RunLogger._flush_handle(handle, durable=True)

    @staticmethod
    def _write_bytes_durable(path: Path, payload: bytes) -> None:
        """Write a small binary artifact and fsync it before returning."""

        with path.open("wb") as handle:
            handle.write(payload)
            RunLogger._flush_handle(handle, durable=True)

    def _write_config(self, config: MonitorConfig) -> None:
        payload = asdict(config)
        payload["log_root"] = str(payload["log_root"])
        payload["version"] = VERSION
        payload["started_local"] = now_local_iso()
        self._write_text_durable(
            self.config_path,
            json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
        )

    def event(self, message: str) -> None:
        """Append a timestamped scenario event."""

        line = f"{now_local_iso()} {message}"
        with self._lock:
            with self.events_path.open("a", encoding="utf-8") as handle:
                handle.write(line + "\n")
                self._flush_handle(handle, durable=False)
        console(line)

    @staticmethod
    def _reservoir_add(samples: list[float], seen: int, value: float) -> int:
        """Update one bounded reservoir sample and return its new total-seen count."""

        seen += 1
        if len(samples) < MAX_STATS_SAMPLES:
            samples.append(value)
        else:
            candidate = random.randrange(seen)
            if candidate < MAX_STATS_SAMPLES:
                samples[candidate] = value
        return seen

    def _update_stats(self, result: RequestResult) -> None:
        """Update bounded aggregate counters for one request."""

        self._request_count += 1
        status_key = str(result.status) if result.status is not None else "NO_HTTP_STATUS"
        self._statuses[status_key] = self._statuses.get(status_key, 0) + 1
        if result.remote_ip:
            self._remote_ips[result.remote_ip] = self._remote_ips.get(result.remote_ip, 0) + 1
        self._phases[result.phase] = self._phases.get(result.phase, 0) + 1
        if result.not_found_class:
            self._not_found_classes[result.not_found_class] = self._not_found_classes.get(result.not_found_class, 0) + 1
        if result.anomaly_reasons:
            self._anomaly_count += 1
        if result.error_type:
            self._error_count += 1
        if result.ttfb_seconds is not None:
            self._ttfb_seen = self._reservoir_add(self._ttfb_samples, self._ttfb_seen, result.ttfb_seconds)
        if result.total_seconds is not None:
            self._total_seen = self._reservoir_add(self._total_samples, self._total_seen, result.total_seconds)

    def record(self, result: RequestResult) -> None:
        """Persist one transaction immediately in JSONL and compact CSV."""

        payload = asdict(result)
        payload.pop("body_for_processing", None)
        row = {
            "request_id": result.request_id,
            "cycle": result.cycle,
            "phase": result.phase,
            "timestamp_local": result.timestamp_local,
            "method": result.method,
            "url": result.url,
            "status": result.status if result.status is not None else "",
            "remote_ip": result.remote_ip,
            "forced_ip": result.forced_ip,
            "dns_seconds": result.dns_seconds if result.dns_seconds is not None else "",
            "tcp_seconds": result.tcp_seconds if result.tcp_seconds is not None else "",
            "tls_seconds": result.tls_seconds if result.tls_seconds is not None else "",
            "ttfb_seconds": result.ttfb_seconds if result.ttfb_seconds is not None else "",
            "body_seconds": result.body_seconds if result.body_seconds is not None else "",
            "total_seconds": result.total_seconds if result.total_seconds is not None else "",
            "bytes_received": result.bytes_received,
            "content_type": result.content_type,
            "server": result.server,
            "age": result.age,
            "cache_control": " | ".join(result.cache_control),
            "plain_not_found": int(result.plain_not_found),
            "not_found_class": result.not_found_class,
            "anomaly_reasons": " | ".join(result.anomaly_reasons),
            "error_type": result.error_type,
            "error_message": result.error_message,
        }
        with self._lock:
            self._update_stats(result)
            durable = bool(result.anomaly_reasons) or self._request_count % FSYNC_EVERY_REQUESTS == 0
            with self.jsonl_path.open("a", encoding="utf-8") as handle:
                handle.write(json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n")
                self._flush_handle(handle, durable=durable)
            with self.csv_path.open("a", newline="", encoding="utf-8") as handle:
                writer = csv.DictWriter(handle, fieldnames=self.CSV_FIELDS)
                writer.writerow(row)
                self._flush_handle(handle, durable=durable)

    def capture_anomaly(self, result: RequestResult) -> Path:
        """Save headers/body/metadata durably for an anomalous response."""

        base = f"{result.request_id:06d}_{safe_filename(result.phase)}"
        directory = self.anomaly_dir / base
        directory.mkdir(parents=True, exist_ok=True)
        metadata = asdict(result)
        body = metadata.pop("body_for_processing", b"")
        with self._lock:
            self._write_text_durable(
                directory / "metadata.json",
                json.dumps(metadata, indent=2, ensure_ascii=False) + "\n",
            )
            header_lines = [f"HTTP status: {result.status} {result.reason}".rstrip()]
            header_lines.extend(f"{name}: {value}" for name, value in result.response_headers)
            self._write_text_durable(directory / "response_headers.txt", "\n".join(header_lines) + "\n")
            if body:
                self._write_bytes_durable(directory / "response_body.bin", body[:MAX_ANOMALY_BODY_BYTES])
                if result.content_type.lower().startswith("text/") or "json" in result.content_type.lower():
                    self._write_text_durable(
                        directory / "response_body.txt",
                        decode_text(body[:MAX_ANOMALY_BODY_BYTES], result.content_type),
                    )
        return directory

    @staticmethod
    def _report_status_text(result: dict[str, Any]) -> str:
        """Return a compact human-readable outcome for an anomaly report cell."""

        error_type = str(result.get("error_type") or "")
        error_message = str(result.get("error_message") or "").strip()
        status = result.get("status")
        not_found_class = str(result.get("not_found_class") or "")
        ttfb = result.get("ttfb_seconds")
        total = result.get("total_seconds")
        url = str(result.get("url") or "")
        path = urlsplit(url).path.lower()
        extension = Path(path).suffix.lower()

        if error_type:
            if error_type in {"TimeoutError", "SSLError", "SSLEOFError"} and result.get("tcp_seconds") is not None:
                label = f"TLS/transport error: {error_type}"
            elif error_type == "ConnectionResetError":
                label = "Connection reset by remote host"
            else:
                label = f"Transport error: {error_type}"
            if error_message:
                label += f" ({error_message})"
            if total is not None:
                label += f" after {float(total):.2f} s"
            return label

        if status is not None and int(status) >= 400:
            if not_found_class == "generic_plain_not_found" and int(status) == 404:
                prefix = "Static asset → " if extension in ASSET_EXTENSIONS else ""
                label = f"{prefix}Generic Apache 404"
            else:
                reason = str(result.get("reason") or "").strip()
                label = f"HTTP {status}" + (f" {reason}" if reason else "")
            if ttfb is not None:
                label += f", TTFB {float(ttfb) * 1000.0:.1f} ms"
            return label

        reasons = [str(item) for item in (result.get("anomaly_reasons") or [])]
        slow_ttfb = next((item for item in reasons if item.startswith("slow_ttfb:")), "")
        slow_total = next((item for item in reasons if item.startswith("slow_total:")), "")
        if slow_ttfb:
            label = f"Slow TTFB: {float(ttfb):.3f} s" if ttfb is not None else slow_ttfb.replace("slow_ttfb:", "Slow TTFB: ")
            if status is not None:
                label += f" (HTTP {status})"
            return label
        if slow_total:
            label = f"Slow total time: {float(total):.3f} s" if total is not None else slow_total.replace("slow_total:", "Slow total: ")
            if status is not None:
                label += f" (HTTP {status})"
            return label
        return "Anomaly"

    @staticmethod
    def _report_probe_outcome(result: dict[str, Any]) -> str:
        """Return a terse outcome for one forced-IP or immediate-recheck probe."""

        ip = str(result.get("forced_ip") or result.get("remote_ip") or "-")
        if ip.startswith("185.8.237."):
            ip = "." + ip.rsplit(".", 1)[-1]
        error_type = str(result.get("error_type") or "")
        status = result.get("status")
        if error_type:
            if error_type == "ConnectionResetError":
                outcome = "RESET"
            elif "Timeout" in error_type:
                outcome = "TIMEOUT"
            elif error_type in {"SSLError", "SSLEOFError"}:
                outcome = "TLS ERROR"
            else:
                outcome = error_type
        elif status is None:
            outcome = "NO STATUS"
        else:
            outcome = str(status)
        return f"{ip} → {outcome}"

    @staticmethod
    def _report_timestamp(value: str) -> datetime | None:
        """Parse one monitor ISO timestamp for relative-delay formatting."""

        if not value:
            return None
        try:
            return datetime.fromisoformat(value)
        except ValueError:
            return None

    @classmethod
    def _report_immediate_check(cls, primary: dict[str, Any], probes: list[dict[str, Any]]) -> str:
        """Summarize forced-address probes and the immediate recheck for one primary anomaly."""

        if not probes:
            return "No immediate diagnostic probe recorded."
        probes = sorted(probes, key=lambda item: int(item.get("request_id") or 0))
        primary_time = cls._report_timestamp(str(primary.get("timestamp_local") or ""))
        pieces: list[str] = []
        first = True
        for probe in probes:
            phase = str(probe.get("phase") or "")
            outcome = cls._report_probe_outcome(probe)
            if first:
                probe_time = cls._report_timestamp(str(probe.get("timestamp_local") or ""))
                if primary_time is not None and probe_time is not None:
                    delay_ms = max(0.0, (probe_time - primary_time).total_seconds() * 1000.0)
                    if delay_ms < 2000.0:
                        pieces.append(f"after {delay_ms:.0f} ms: {outcome}")
                    else:
                        pieces.append(outcome)
                else:
                    pieces.append(outcome)
                first = False
                continue
            if phase.startswith("anomaly_recheck:"):
                pieces.append(f"recheck {outcome}")
            else:
                pieces.append(outcome)
        return "; ".join(pieces)

    def write_anomaly_report(self) -> Path | None:
        """Regenerate a standalone HTML table containing only primary monitor anomalies.

        Normal successful requests and diagnostic forced/recheck requests are not
        shown as independent rows.  Forced-address probes and the immediate
        recheck are folded into the final column of their originating anomaly.
        Known Gallery SEO-guard 404s are intentionally omitted.
        """

        with self._lock:
            if not self.jsonl_path.exists():
                return None
            records: list[dict[str, Any]] = []
            with self.jsonl_path.open("r", encoding="utf-8") as handle:
                for line in handle:
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        records.append(json.loads(line))
                    except json.JSONDecodeError:
                        continue

            primaries: list[dict[str, Any]] = []
            probes_by_primary: dict[int, list[dict[str, Any]]] = {}
            for record in records:
                phase = str(record.get("phase") or "")
                request_id = int(record.get("request_id") or 0)
                if phase.startswith("anomaly_forced_ip:"):
                    try:
                        parent = int(phase.split(":", 1)[1].split(":", 1)[0])
                    except ValueError:
                        parent = int(record.get("parent_request_id") or 0)
                    if parent:
                        probes_by_primary.setdefault(parent, []).append(record)
                    continue
                if phase.startswith("anomaly_recheck:"):
                    match = re.match(r"anomaly_recheck:(\d+)", phase)
                    if match:
                        probes_by_primary.setdefault(int(match.group(1)), []).append(record)
                    continue

                reasons = record.get("anomaly_reasons") or []
                if not reasons:
                    continue
                if str(record.get("not_found_class") or "") == "likely_gallery_seo_guard_404":
                    continue
                primaries.append(record)

            if not primaries:
                self.anomaly_report_path.unlink(missing_ok=True)
                return None

            primaries.sort(key=lambda item: int(item.get("request_id") or 0))
            rows: list[str] = []
            for primary in primaries:
                request_id = int(primary.get("request_id") or 0)
                timestamp = str(primary.get("timestamp_local") or "")
                time_text = timestamp
                parsed = self._report_timestamp(timestamp)
                if parsed is not None:
                    time_text = parsed.strftime("%H:%M:%S.") + f"{parsed.microsecond // 1000:03d}"
                url = str(primary.get("url") or "")
                remote_ip = str(primary.get("remote_ip") or primary.get("forced_ip") or "-")
                what = self._report_status_text(primary)
                check = self._report_immediate_check(primary, probes_by_primary.get(request_id, []))
                rows.append(
                    "<tr>"
                    f"<td class=\"time\"><strong>{html.escape(time_text)}</strong></td>"
                    f"<td class=\"url\"><a href=\"{html.escape(url, quote=True)}\"><code>{html.escape(url)}</code></a></td>"
                    f"<td class=\"ip\"><code>{html.escape(remote_ip)}</code></td>"
                    f"<td>{html.escape(what)}</td>"
                    f"<td>{html.escape(check)}</td>"
                    "</tr>"
                )

            generated = now_local_iso()
            first_timestamp = str(primaries[0].get("timestamp_local") or "")
            run_date = first_timestamp[:10] if len(first_timestamp) >= 10 else ""
            document = f"""<!doctype html>
<html lang=\"en\">
<head>
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
<title>PHP Gallery HTTP Monitor - Anomalies</title>
<style>
:root {{ color-scheme: light; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif; }}
body {{ margin: 0; background: #f5f6f8; color: #1f2937; }}
main {{ max-width: 1500px; margin: 32px auto; padding: 0 20px 40px; }}
header {{ margin-bottom: 18px; }}
h1 {{ margin: 0 0 8px; font-size: 24px; }}
p {{ margin: 5px 0; color: #4b5563; }}
.meta {{ font-size: 13px; }}
.table-wrap {{ overflow-x: auto; background: #fff; border: 1px solid #dfe3e8; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }}
table {{ width: 100%; border-collapse: collapse; min-width: 1100px; }}
th, td {{ padding: 11px 12px; text-align: left; vertical-align: top; border-bottom: 1px solid #e8ebef; }}
th {{ position: sticky; top: 0; background: #eef1f4; font-size: 13px; white-space: nowrap; }}
td {{ font-size: 13px; line-height: 1.45; }}
tr:last-child td {{ border-bottom: 0; }}
.time, .ip {{ white-space: nowrap; }}
.url {{ min-width: 440px; max-width: 680px; overflow-wrap: anywhere; }}
code {{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }}
a {{ color: #155eef; text-decoration: none; }}
a:hover {{ text-decoration: underline; }}
.note {{ margin-top: 12px; font-size: 12px; color: #6b7280; }}
</style>
</head>
<body>
<main>
<header>
<h1>PHP Gallery HTTP Monitor - anomaly report</h1>
<p><strong>{len(primaries)}</strong> primary anomalies{f' on {html.escape(run_date)}' if run_date else ''}.</p>
<p class=\"meta\">Generated: {html.escape(generated)} · Monitor version: {html.escape(VERSION)}</p>
</header>
<div class=\"table-wrap\">
<table>
<thead><tr><th>Čas CEST</th><th>URL</th><th>WEDOS IP</th><th>Co se stalo</th><th>Bezprostřední kontrola</th></tr></thead>
<tbody>
{''.join(rows)}
</tbody>
</table>
</div>
<p class=\"note\">Normal HTTP 200 requests are omitted unless they themselves triggered a slow-request anomaly. Diagnostic forced-IP and immediate-recheck requests are folded into the last column instead of appearing as separate rows. Known Gallery SEO-guard 404 responses are omitted.</p>
</main>
</body>
</html>
"""
            self._write_text_durable(self.anomaly_report_path, document)
            return self.anomaly_report_path

    def _create_zip_locked(self, destination: Path) -> Path:
        """Create an atomic ZIP snapshot while normal request writers are paused by the logger lock."""

        temporary = destination.with_suffix(destination.suffix + ".tmp")
        temporary.unlink(missing_ok=True)
        with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
            for path in sorted(self.run_dir.rglob("*")):
                if path.is_file():
                    archive.write(path, path.relative_to(self.run_dir).as_posix())
        os.replace(temporary, destination)
        return destination

    def create_archive(self) -> Path:
        """Create one upload-friendly ZIP containing the complete monitor run."""

        self.write_anomaly_report()
        destination = Path(str(self.run_dir) + ".zip")
        with self._lock:
            return self._create_zip_locked(destination)

    def maybe_create_live_snapshot(self, cycle: int) -> Path | None:
        """Periodically create a consistent live ZIP without requiring the monitor to stop."""

        if cycle <= 0 or cycle % self.snapshot_every_cycles != 0:
            return None
        with self._lock:
            return self._create_zip_locked(self.live_snapshot_path)

    def snapshot_stats(self) -> dict[str, Any]:
        """Return a bounded stable copy of aggregate statistics."""

        with self._lock:
            return {
                "request_count": self._request_count,
                "anomaly_count": self._anomaly_count,
                "error_count": self._error_count,
                "statuses": dict(self._statuses),
                "remote_ips": dict(self._remote_ips),
                "phases": dict(self._phases),
                "not_found_classes": dict(self._not_found_classes),
                "ttfb_samples": list(self._ttfb_samples),
                "total_samples": list(self._total_samples),
                "ttfb_seen": self._ttfb_seen,
                "total_seen": self._total_seen,
            }

    def write_summary(self, interrupted: bool = False) -> None:
        """Write aggregate diagnostic statistics atomically from bounded in-memory state."""

        stats = self.snapshot_stats()
        ttfb_values = stats["ttfb_samples"]
        total_values = stats["total_samples"]

        def metric_lines(label: str, values: list[float], total_seen: int) -> list[str]:
            if not values:
                return [f"{label}: no samples"]
            suffix = "" if total_seen <= len(values) else f" (reservoir {len(values)} of {total_seen})"
            return [
                f"{label} samples: {len(values)}{suffix}",
                f"{label} min: {min(values):.6f} s",
                f"{label} median: {statistics.median(values):.6f} s",
                f"{label} p95: {percentile(values, 0.95):.6f} s",
                f"{label} p99: {percentile(values, 0.99):.6f} s",
                f"{label} max: {max(values):.6f} s",
            ]

        lines = [
            "PHP Gallery HTTP Monitor summary",
            "================================",
            f"Version: {VERSION}",
            f"Generated: {now_local_iso()}",
            f"Interrupted: {'yes' if interrupted else 'no'}",
            f"Requests recorded: {stats['request_count']}",
            f"Anomalous requests: {stats['anomaly_count']}",
            f"Transport/protocol errors: {stats['error_count']}",
            "",
            "HTTP status distribution:",
        ]
        for key, value in sorted(stats["statuses"].items(), key=lambda pair: pair[0]):
            lines.append(f"  {key}: {value}")
        lines.extend(["", "Not Found classifications:"])
        if stats["not_found_classes"]:
            for key, value in sorted(stats["not_found_classes"].items(), key=lambda pair: (-pair[1], pair[0])):
                lines.append(f"  {key}: {value}")
        else:
            lines.append("  none")
        lines.extend(["", "Remote IP distribution:"])
        for key, value in sorted(stats["remote_ips"].items(), key=lambda pair: (-pair[1], pair[0])):
            lines.append(f"  {key}: {value}")
        lines.extend(["", "Scenario phase distribution:"])
        for key, value in sorted(stats["phases"].items(), key=lambda pair: (-pair[1], pair[0])):
            lines.append(f"  {key}: {value}")
        lines.extend([""] + metric_lines("TTFB", ttfb_values, stats["ttfb_seen"]))
        lines.extend([""] + metric_lines("Total", total_values, stats["total_seen"]))
        lines.extend(
            [
                "",
                "Files:",
                f"  JSONL: {self.jsonl_path}",
                f"  CSV: {self.csv_path}",
                f"  Events: {self.events_path}",
                f"  Anomalies: {self.anomaly_dir}",
                f"  Anomaly HTML: {self.anomaly_report_path}",
                f"  Live ZIP: {self.live_snapshot_path}",
            ]
        )
        text = "\n".join(lines) + "\n"
        temporary = self.summary_path.with_suffix(".txt.tmp")
        with self._lock:
            self._write_text_durable(temporary, text)
            os.replace(temporary, self.summary_path)


def format_certificate_name(value: Any) -> str:
    """Flatten ssl.getpeercert() subject/issuer tuples into readable text."""

    if not value:
        return ""
    parts: list[str] = []
    try:
        for rdn in value:
            for key, item in rdn:
                parts.append(f"{key}={item}")
    except Exception:
        return str(value)
    return ", ".join(parts)


class HttpMonitor:
    """Execute raw HTTP/1.1 diagnostics and the repeated browsing scenario."""

    def __init__(self, config: MonitorConfig, logger: RunLogger) -> None:
        self.config = config
        self.logger = logger
        parts = urlsplit(config.start_url)
        scheme = parts.scheme.lower()
        port = parts.port or (443 if scheme == "https" else 80)
        self.origin = (scheme, (parts.hostname or "").lower(), port)
        self.page_pool: set[str] = {canonicalize_url(config.start_url)}
        self.asset_pool: set[str] = set()
        self.sentinel_url = canonicalize_url(config.start_url)
        self.cookies = SimpleCookieStore()
        self._request_counter = 0
        self._counter_lock = threading.Lock()
        self._anomaly_guard = threading.local()
        self._stop_event = threading.Event()
        self._seen_asset_cache: dict[str, float] = {}
        self._asset_cache_lock = threading.Lock()
        self._curl_binary = find_curl_binary()
        self._curl_capabilities = curl_capabilities(self._curl_binary) if self._curl_binary else set()

    def next_request_id(self) -> int:
        """Allocate a monotonically increasing request id."""

        with self._counter_lock:
            self._request_counter += 1
            return self._request_counter

    def stop(self) -> None:
        """Request graceful scenario termination."""

        self._stop_event.set()

    def stopped(self) -> bool:
        """Return whether the run has been asked to stop."""

        return self._stop_event.is_set()

    def interruptible_sleep(self, seconds: float, label: str = "sleep") -> bool:
        """Sleep until timeout or stop, printing occasional idle progress."""

        if seconds <= 0:
            return not self.stopped()
        deadline = time.monotonic() + seconds
        next_report = time.monotonic()
        while not self.stopped():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                return True
            now = time.monotonic()
            if now >= next_report and seconds >= 30:
                console(f"  {label}: {remaining:.0f}s remaining")
                next_report = now + 30.0
            self._stop_event.wait(min(1.0, remaining))
        return False

    def build_request_headers(self, url: str, referer: str | None = None, is_document: bool = True) -> list[tuple[str, str]]:
        """Build conservative browser-like request headers without credentials."""

        headers = [
            ("User-Agent", self.config.user_agent),
            (
                "Accept",
                "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8"
                if is_document
                else "image/avif,image/webp,image/png,image/svg+xml,*/*;q=0.8",
            ),
            ("Accept-Language", "en-US,en;q=0.9,cs;q=0.8"),
            ("Accept-Encoding", "gzip, deflate"),
            ("Connection", "close"),
            ("Sec-Fetch-Dest", "document" if is_document else "empty"),
            ("Sec-Fetch-Mode", "navigate" if is_document else "no-cors"),
            ("Sec-Fetch-Site", "same-origin" if referer else "none"),
        ]
        if is_document:
            headers.extend([("Sec-Fetch-User", "?1"), ("Upgrade-Insecure-Requests", "1")])
        if referer:
            headers.append(("Referer", referer))
        cookie_value = self.cookies.header_value()
        if cookie_value:
            headers.append(("Cookie", cookie_value))
        return headers

    def _connect_socket(
        self,
        host: str,
        port: int,
        timeout: float,
        forced_ip: str | None = None,
    ) -> tuple[socket.socket, list[str], float, float, str, int]:
        """Resolve the host and connect normally or to one forced address while preserving SNI later."""

        dns_start = time.monotonic()
        infos = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
        dns_seconds = time.monotonic() - dns_start
        addresses: list[str] = []
        seen: set[tuple[Any, ...]] = set()
        unique_infos: list[tuple[Any, ...]] = []
        for info in infos:
            sockaddr = info[4]
            ip = sockaddr[0]
            if ip not in addresses:
                addresses.append(ip)
            key = (info[0], info[1], info[2], sockaddr)
            if key not in seen:
                seen.add(key)
                unique_infos.append(info)

        if forced_ip:
            try:
                ipaddress.ip_address(forced_ip)
            except ValueError as exc:
                raise ValueError(f"Invalid forced IP address: {forced_ip}") from exc
            unique_infos = socket.getaddrinfo(
                forced_ip,
                port,
                type=socket.SOCK_STREAM,
                flags=socket.AI_NUMERICHOST,
            )
            if forced_ip not in addresses:
                addresses.append(forced_ip)

        errors: list[str] = []
        for family, socktype, proto, _, sockaddr in unique_infos:
            sock = socket.socket(family, socktype, proto)
            sock.settimeout(timeout)
            connect_start = time.monotonic()
            try:
                sock.connect(sockaddr)
                tcp_seconds = time.monotonic() - connect_start
                peer = sock.getpeername()
                return sock, addresses, dns_seconds, tcp_seconds, peer[0], peer[1]
            except Exception as exc:
                errors.append(f"{sockaddr}: {type(exc).__name__}: {exc}")
                try:
                    sock.close()
                except Exception:
                    pass
        raise ConnectionError("; ".join(errors) or f"No usable address for {host}:{port}")

    def request_once(
        self,
        url: str,
        cycle: int,
        phase: str,
        parent_request_id: int | None = None,
        referer: str | None = None,
        is_document: bool = True,
        forced_ip: str | None = None,
    ) -> RequestResult:
        """Perform and record one physical GET transaction without redirect following."""

        request_id = self.next_request_id()
        timestamp_local = now_local_iso()
        timestamp_utc = now_utc_iso()
        started = time.monotonic()
        parts = urlsplit(url)
        scheme = parts.scheme.lower()
        host = parts.hostname or ""
        port = parts.port or (443 if scheme == "https" else 80)
        result = RequestResult(
            request_id=request_id,
            cycle=cycle,
            phase=phase,
            parent_request_id=parent_request_id,
            timestamp_local=timestamp_local,
            timestamp_utc=timestamp_utc,
            method="GET",
            url=url,
            host=host,
            scheme=scheme,
            port=port,
            forced_ip=forced_ip or "",
        )
        raw_socket: socket.socket | None = None
        transport: socket.socket | ssl.SSLSocket | None = None
        try:
            raw_socket, addresses, dns_seconds, tcp_seconds, remote_ip, remote_port = self._connect_socket(
                host, port, self.config.connect_timeout, forced_ip=forced_ip
            )
            result.dns_addresses = addresses
            result.dns_seconds = dns_seconds
            result.tcp_seconds = tcp_seconds
            result.remote_ip = remote_ip
            result.remote_port = remote_port
            local = raw_socket.getsockname()
            result.local_ip = str(local[0])
            result.local_port = int(local[1])
            transport = raw_socket

            if scheme == "https":
                tls_start = time.monotonic()
                context = ssl.create_default_context()
                context.set_alpn_protocols(["http/1.1"])
                transport = context.wrap_socket(raw_socket, server_hostname=host)
                result.tls_seconds = time.monotonic() - tls_start
                result.tls_version = transport.version() or ""
                cipher = transport.cipher()
                result.tls_cipher = cipher[0] if cipher else ""
                result.tls_alpn = transport.selected_alpn_protocol() or ""
                certificate = transport.getpeercert() or {}
                result.tls_cert_subject = format_certificate_name(certificate.get("subject"))
                result.tls_cert_issuer = format_certificate_name(certificate.get("issuer"))
                result.tls_cert_not_after = str(certificate.get("notAfter", ""))
            elif scheme != "http":
                raise ValueError(f"Unsupported URL scheme: {scheme}")

            transport.settimeout(self.config.request_timeout)
            encoded_path = quote(parts.path or "/", safe="/%:@!$&'()*+,;=-._~")
            encoded_query = quote(parts.query, safe="=&%:@!$'()*+,;/?-._~")
            path = urlunsplit(("", "", encoded_path, encoded_query, ""))
            default_port = 443 if scheme == "https" else 80
            host_header = host if port == default_port else f"{host}:{port}"
            headers = self.build_request_headers(url, referer=referer, is_document=is_document)
            result.request_headers = redact_headers([("Host", host_header), *headers])
            request_lines = [f"GET {path} HTTP/1.1", f"Host: {host_header}"]
            request_lines.extend(f"{name}: {value}" for name, value in headers)
            request_bytes = ("\r\n".join(request_lines) + "\r\n\r\n").encode("iso-8859-1", errors="replace")
            send_start = time.monotonic()
            transport.sendall(request_bytes)
            result.request_send_seconds = time.monotonic() - send_start

            response = http.client.HTTPResponse(transport, method="GET")
            header_wait_start = time.monotonic()
            response.begin()
            result.ttfb_seconds = time.monotonic() - header_wait_start
            result.status = response.status
            result.reason = response.reason or ""
            result.response_http_version = {10: "HTTP/1.0", 11: "HTTP/1.1"}.get(response.version, str(response.version))
            raw_headers = response.getheaders()
            result.response_headers = redact_headers(raw_headers)
            result.content_type = first_header(raw_headers, "Content-Type")
            result.content_encoding = first_header(raw_headers, "Content-Encoding")
            result.content_length_header = first_header(raw_headers, "Content-Length")
            result.location = first_header(raw_headers, "Location")
            result.cache_control = header_values(raw_headers, "Cache-Control")
            result.age = first_header(raw_headers, "Age")
            result.server = first_header(raw_headers, "Server")
            result.via = header_values(raw_headers, "Via")
            result.x_headers = {}
            for name, value in raw_headers:
                if name.lower().startswith("x-"):
                    result.x_headers.setdefault(name, []).append(value)
            self.cookies.update_from_headers(raw_headers)

            body_start = time.monotonic()
            hasher = hashlib.sha256()
            stored = bytearray()
            total_bytes = 0
            while True:
                chunk = response.read(64 * 1024)
                if not chunk:
                    break
                total_bytes += len(chunk)
                hasher.update(chunk)
                if len(stored) < MAX_HTML_STORE_BYTES:
                    remaining = MAX_HTML_STORE_BYTES - len(stored)
                    stored.extend(chunk[:remaining])
            result.body_seconds = time.monotonic() - body_start
            result.bytes_received = total_bytes
            result.body_sha256 = hasher.hexdigest() if total_bytes else hashlib.sha256(b"").hexdigest()
            decoded_body = decode_content(bytes(stored), result.content_encoding)
            result.body_for_processing = decoded_body
            preview = decode_text(decoded_body[:MAX_BODY_PREVIEW_BYTES], result.content_type)
            result.body_preview = preview
            result.plain_not_found = is_plain_not_found(result.status, result.content_type, decoded_body)
            result.not_found_class = classify_not_found_response(
                result.status,
                result.content_type,
                raw_headers,
                decoded_body,
            )
        except Exception as exc:
            result.error_type = type(exc).__name__
            result.error_message = str(exc)
            result.traceback = traceback.format_exc(limit=8)
        finally:
            result.total_seconds = time.monotonic() - started
            if transport is not None:
                try:
                    transport.close()
                except Exception:
                    pass
            elif raw_socket is not None:
                try:
                    raw_socket.close()
                except Exception:
                    pass

        result.anomaly_reasons = self.detect_anomalies(result)
        self.logger.record(result)
        self.print_result(result)
        return result

    def detect_anomalies(self, result: RequestResult) -> list[str]:
        """Classify conditions worth preserving and immediately rechecking."""

        reasons: list[str] = []
        if result.error_type:
            reasons.append(f"transport_error:{result.error_type}")
        if result.status is not None and result.status >= 400:
            reasons.append(f"http_status:{result.status}")
        if result.plain_not_found:
            reasons.append("plain_not_found_body")
        if result.not_found_class:
            reasons.append(f"not_found_class:{result.not_found_class}")
        if result.ttfb_seconds is not None and result.ttfb_seconds >= self.config.slow_ttfb_seconds:
            reasons.append(f"slow_ttfb:{result.ttfb_seconds:.3f}s")
        if result.total_seconds is not None and result.total_seconds >= self.config.slow_total_seconds:
            reasons.append(f"slow_total:{result.total_seconds:.3f}s")
        return reasons

    def print_result(self, result: RequestResult) -> None:
        """Print one compact request result suitable for an unattended terminal."""

        status = str(result.status) if result.status is not None else "ERR"
        marker = " !!!" if result.anomaly_reasons else ""
        console(
            f"[{result.request_id:06d}] {result.phase:<20} {status:<4} "
            f"ip={result.remote_ip or '-':<15} "
            f"forced={result.forced_ip or '-':<15} "
            f"dns={format_seconds(result.dns_seconds):>8} "
            f"tcp={format_seconds(result.tcp_seconds):>8} "
            f"tls={format_seconds(result.tls_seconds):>8} "
            f"ttfb={format_seconds(result.ttfb_seconds):>8} "
            f"total={format_seconds(result.total_seconds):>8}{marker}"
        )
        if result.anomaly_reasons:
            console(f"         anomaly: {', '.join(result.anomaly_reasons)}")
            console(f"         url: {result.url}")
            if result.error_message:
                console(f"         error: {result.error_message}")
            elif result.body_preview.strip():
                one_line = re.sub(r"\s+", " ", result.body_preview.strip())[:240]
                console(f"         body: {one_line}")

    def fetch(
        self,
        url: str,
        cycle: int,
        phase: str,
        referer: str | None = None,
        is_document: bool = True,
        allow_anomaly_recheck: bool = True,
    ) -> tuple[RequestResult, list[RequestResult]]:
        """Fetch a URL, following redirects while logging every physical request."""

        current = canonicalize_url(url)
        chain: list[RequestResult] = []
        parent_id: int | None = None
        for redirect_index in range(MAX_REDIRECTS + 1):
            hop_phase = phase if redirect_index == 0 else f"{phase}:redirect{redirect_index}"
            result = self.request_once(
                current,
                cycle=cycle,
                phase=hop_phase,
                parent_request_id=parent_id,
                referer=referer,
                is_document=is_document,
            )
            chain.append(result)
            parent_id = result.request_id
            if result.anomaly_reasons:
                anomaly_path = self.logger.capture_anomaly(result)
                self.logger.event(
                    f"ANOMALY request={result.request_id} phase={result.phase} "
                    f"reasons={','.join(result.anomaly_reasons)} snapshot={anomaly_path}"
                )
                if allow_anomaly_recheck:
                    self.capture_forced_ip_probes(result, cycle, anomaly_path)
                    if self.config.curl_on_anomaly:
                        self.capture_curl_snapshots(result, anomaly_path)
                    if not phase.startswith("anomaly_recheck"):
                        self.anomaly_recheck(result, cycle)
                    try:
                        self.logger.write_anomaly_report()
                    except Exception as exc:
                        self.logger.event(
                            f"Anomaly HTML report update failed request={result.request_id}: "
                            f"{type(exc).__name__}: {exc}"
                        )
            if result.error_type:
                result.final_url_after_redirects = current
                return result, chain
            if result.status is not None and result.status in {301, 302, 303, 307, 308} and result.location:
                next_url = canonicalize_url(urljoin(current, result.location))
                if not same_origin(next_url, self.origin):
                    self.logger.event(
                        f"Redirect to different origin not followed: {current} -> {next_url}"
                    )
                    result.final_url_after_redirects = current
                    return result, chain
                if not url_looks_safe_for_get(next_url, self.origin):
                    self.logger.event(
                        f"Potentially mutating/admin redirect not followed automatically: {current} -> {next_url}"
                    )
                    result.final_url_after_redirects = current
                    return result, chain
                current = next_url
                referer = result.url
                continue
            result.final_url_after_redirects = current
            return result, chain
        final = chain[-1]
        final.anomaly_reasons.append("redirect_limit_exceeded")
        return final, chain

    def anomaly_recheck(self, result: RequestResult, cycle: int) -> None:
        """Immediately request the exact anomalous URL once more for comparison."""

        if getattr(self._anomaly_guard, "active", False):
            return
        self._anomaly_guard.active = True
        try:
            self.logger.event(
                f"Immediate anomaly recheck for request={result.request_id} url={result.url}"
            )
            self.interruptible_sleep(0.5)
            self.fetch(
                result.url,
                cycle=cycle,
                phase=f"anomaly_recheck:{result.request_id}",
                is_document=True,
                allow_anomaly_recheck=False,
            )
        finally:
            self._anomaly_guard.active = False

    def capture_forced_ip_probes(self, result: RequestResult, cycle: int, directory: Path) -> None:
        """Immediately compare the anomalous URL through each resolved address while preserving Host and TLS SNI."""

        if result.forced_ip:
            return
        candidates: list[str] = []
        for address in result.dns_addresses:
            if address and address not in candidates:
                candidates.append(address)
            if len(candidates) >= 4:
                break
        if not candidates:
            return
        self.logger.event(
            f"Forced-address comparison request={result.request_id} addresses={','.join(candidates)}"
        )
        for address in candidates:
            if self.stopped():
                break
            self.request_once(
                result.url,
                cycle=cycle,
                phase=f"anomaly_forced_ip:{result.request_id}",
                parent_request_id=result.request_id,
                referer=None,
                is_document=True,
                forced_ip=address,
            )
        RunLogger._write_text_durable(
            directory / "forced_ip_probe_note.txt",
            "Forced-address probes are regular request records in requests.jsonl/requests.csv "
            f"with phase anomaly_forced_ip:{result.request_id} and forced_ip populated.\n",
        )

    def _run_curl_probe(self, result: RequestResult, directory: Path, label: str, extra_args: list[str]) -> None:
        """Run one bounded curl protocol comparison and save its output."""

        curl = self._curl_binary
        if not curl:
            return
        body_path = directory / f"curl_{label}_body.bin"
        headers_path = directory / f"curl_{label}_headers.txt"
        stderr_path = directory / f"curl_{label}_verbose.txt"
        exit_path = directory / f"curl_{label}_exit_code.txt"
        command = [
            curl,
            "-sS",
            "-v",
            *extra_args,
            "--connect-timeout",
            str(max(1.0, self.config.connect_timeout)),
            "--max-time",
            str(max(2.0, self.config.request_timeout)),
            "-A",
            self.config.user_agent,
            "-D",
            str(headers_path),
            "-o",
            str(body_path),
            result.url,
        ]
        try:
            completed = subprocess.run(
                command,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=self.config.request_timeout + 5.0,
                check=False,
            )
            RunLogger._write_bytes_durable(stderr_path, completed.stderr)
            RunLogger._write_text_durable(exit_path, str(completed.returncode) + "\n")
            self.logger.event(
                f"curl {label} anomaly snapshot request={result.request_id} exit={completed.returncode}"
            )
        except Exception as exc:
            RunLogger._write_text_durable(
                stderr_path,
                f"curl snapshot failed: {type(exc).__name__}: {exc}\n",
            )
            self.logger.event(
                f"curl {label} anomaly snapshot failed request={result.request_id}: {type(exc).__name__}: {exc}"
            )

    def capture_curl_snapshots(self, result: RequestResult, directory: Path) -> None:
        """Capture default, HTTP/1.1 and supported HTTP/2/HTTP/3 curl comparisons after an anomaly."""

        if not self._curl_binary:
            self.logger.event("curl not available; anomaly curl snapshots skipped")
            return
        RunLogger._write_text_durable(
            directory / "curl_version.txt",
            subprocess.run(
                [self._curl_binary, "--version"],
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                timeout=5.0,
                check=False,
                text=True,
            ).stdout,
        )
        self._run_curl_probe(result, directory, "default", [])
        self._run_curl_probe(result, directory, "http1_1", ["--http1.1"])
        if "HTTP2" in self._curl_capabilities:
            self._run_curl_probe(result, directory, "http2", ["--http2"])
        if "HTTP3" in self._curl_capabilities:
            self._run_curl_probe(result, directory, "http3", ["--http3"])


    def discover_from_html(self, page_url: str, body: bytes, content_type: str) -> tuple[list[str], list[str]]:
        """Parse same-origin public pages and assets from one HTML response."""

        if "html" not in content_type.lower() or not body:
            return [], []
        text = decode_text(body, content_type)
        collector = LinkCollector()
        try:
            collector.feed(text)
        except Exception:
            return [], []

        pages: set[str] = set()
        assets: set[str] = set()
        for raw in collector.pages:
            if raw.startswith(("#", "mailto:", "tel:", "javascript:", "data:")):
                continue
            absolute = canonicalize_url(urljoin(page_url, raw))
            if not url_looks_safe_for_get(absolute, self.origin):
                continue
            extension = Path(urlsplit(absolute).path).suffix.lower()
            if extension in PAGE_EXTENSIONS:
                pages.add(absolute)
            elif extension in ASSET_EXTENSIONS:
                assets.add(absolute)

        for raw in collector.assets:
            if raw.startswith(("data:", "blob:", "javascript:")):
                continue
            absolute = canonicalize_url(urljoin(page_url, raw))
            if same_origin(absolute, self.origin) and url_looks_safe_for_get(absolute, self.origin):
                assets.add(absolute)

        for page in sorted(pages):
            if len(self.page_pool) >= MAX_PAGE_POOL:
                break
            self.page_pool.add(page)
        for asset in sorted(assets):
            if len(self.asset_pool) >= MAX_ASSET_POOL:
                break
            self.asset_pool.add(asset)
        return sorted(pages), sorted(assets)

    def cache_lifetime_seconds(self, result: RequestResult) -> float:
        """Extract a simple positive max-age lifetime for local asset-cache emulation."""

        for value in result.cache_control:
            lower = value.lower()
            if "no-store" in lower:
                return 0.0
            match = re.search(r"(?:s-maxage|max-age)\s*=\s*(\d+)", lower)
            if match:
                return float(match.group(1))
        expires = first_header(result.response_headers, "Expires")
        date = first_header(result.response_headers, "Date")
        if expires and date:
            try:
                expires_dt = parsedate_to_datetime(expires)
                date_dt = parsedate_to_datetime(date)
                return max(0.0, (expires_dt - date_dt).total_seconds())
            except Exception:
                pass
        return 0.0

    def asset_is_fresh(self, url: str) -> bool:
        """Return whether the monitor's browser-like local asset cache is fresh."""

        with self._asset_cache_lock:
            expiry = self._seen_asset_cache.get(url, 0.0)
            return expiry > time.monotonic()

    def remember_asset_cache(self, url: str, result: RequestResult) -> None:
        """Remember a first-party asset max-age without ever caching HTML documents."""

        lifetime = self.cache_lifetime_seconds(result)
        if lifetime <= 0:
            return
        with self._asset_cache_lock:
            self._seen_asset_cache[url] = time.monotonic() + min(lifetime, 7 * 24 * 3600)

    def load_assets(self, page_url: str, assets: list[str], cycle: int, phase: str) -> None:
        """Load a bounded number of first-party assets with browser-like concurrency."""

        if self.config.asset_limit <= 0 or not assets or self.stopped():
            return
        selected = assets[:]
        random.shuffle(selected)
        selected = selected[: self.config.asset_limit]
        to_fetch: list[str] = []
        cached = 0
        for url in selected:
            if self.asset_is_fresh(url):
                cached += 1
            else:
                to_fetch.append(url)
        if cached:
            self.logger.event(f"{phase}: skipped {cached} fresh local asset-cache entries")
        if not to_fetch:
            return

        workers = max(1, min(self.config.asset_concurrency, len(to_fetch)))
        with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="gallery-monitor-asset") as pool:
            futures = {
                pool.submit(
                    self.fetch,
                    url,
                    cycle,
                    f"{phase}:asset",
                    page_url,
                    False,
                    True,
                ): url
                for url in to_fetch
            }
            for future in as_completed(futures):
                if self.stopped():
                    break
                try:
                    result, _ = future.result()
                    if result.ok:
                        self.remember_asset_cache(futures[future], result)
                except Exception as exc:
                    self.logger.event(f"asset worker failure: {type(exc).__name__}: {exc}")

    def open_page(
        self,
        url: str,
        cycle: int,
        phase: str,
        load_assets: bool = True,
        referer: str | None = None,
    ) -> RequestResult:
        """Open one document, discover links, and optionally load first-party assets."""

        result, _ = self.fetch(url, cycle=cycle, phase=phase, referer=referer, is_document=True)
        if result.ok and result.body_for_processing:
            pages, assets = self.discover_from_html(
                result.final_url_after_redirects or result.url,
                result.body_for_processing,
                result.content_type,
            )
            if pages or assets:
                self.logger.event(
                    f"{phase}: discovered pages={len(pages)} assets={len(assets)} "
                    f"pool_pages={len(self.page_pool)} pool_assets={len(self.asset_pool)}"
                )
            if load_assets:
                self.load_assets(
                    result.final_url_after_redirects or result.url,
                    assets,
                    cycle,
                    phase,
                )
        return result

    def choose_page(self, exclude_sentinel: bool = False) -> str:
        """Choose a bounded discovered public page, optionally excluding the fixed sentinel URL."""

        safe_pages = [url for url in self.page_pool if url_looks_safe_for_get(url, self.origin)]
        if exclude_sentinel:
            non_sentinel = [url for url in safe_pages if url != self.sentinel_url]
            if non_sentinel:
                return random.choice(non_sentinel)
        if not safe_pages:
            return self.sentinel_url
        if random.random() < 0.25:
            return self.sentinel_url
        return random.choice(safe_pages)

    def idle_seconds_for_cycle(self, cycle: int) -> float:
        """Return the 120/300/600-style idle schedule while respecting a larger user base value."""

        base = max(1.0, self.config.idle_seconds)
        if cycle % 10 == 0:
            return max(base, self.config.long_idle_seconds)
        if cycle % 5 == 0:
            return max(base, self.config.medium_idle_seconds)
        return base

    def cold_target_for_cycle(self, cycle: int) -> tuple[str, str]:
        """Alternate a fixed sentinel URL with a random discovered page for comparable cold-path data."""

        if cycle % 2 == 1:
            return self.sentinel_url, "sentinel"
        return self.choose_page(exclude_sentinel=True), "random"

    def light_burst(self, cycle: int) -> None:
        """Perform a small bounded concurrent GET burst against discovered public pages."""

        count = max(0, self.config.burst_requests)
        if count == 0 or self.stopped():
            return
        pages = [url for url in self.page_pool if url_looks_safe_for_get(url, self.origin)]
        if not pages:
            pages = [self.config.start_url]
        targets = [random.choice(pages) for _ in range(count)]
        workers = max(1, min(self.config.burst_concurrency, count))
        self.logger.event(
            f"cycle={cycle} light burst start requests={count} concurrency={workers}"
        )
        with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="gallery-monitor-burst") as pool:
            futures = [
                pool.submit(
                    self.fetch,
                    url,
                    cycle,
                    "light_burst",
                    None,
                    True,
                    True,
                )
                for url in targets
            ]
            for future in as_completed(futures):
                if self.stopped():
                    break
                try:
                    future.result()
                except Exception as exc:
                    self.logger.event(f"burst worker failure: {type(exc).__name__}: {exc}")
        self.logger.event(f"cycle={cycle} light burst end")

    def run(self) -> bool:
        """Run the baseline plus repeated scheduled-idle/cold/warm/browse/burst scenario."""

        started = time.monotonic()
        deadline = None if self.config.hours <= 0 else started + self.config.hours * 3600.0
        self.logger.event(f"Monitor {VERSION} starting for {self.config.start_url}")
        self.logger.event(
            "Protocol note: pure-Python probes deliberately use fresh HTTP/1.1 TLS connections. "
            "JavaScript is not executed. Anomalies trigger forced-address probes plus system-curl "
            "HTTP protocol comparisons when curl is available."
        )
        self.logger.event(
            f"Idle schedule base={self.config.idle_seconds:.1f}s "
            f"every5={self.config.medium_idle_seconds:.1f}s every10={self.config.long_idle_seconds:.1f}s"
        )
        self.logger.event(f"Fixed sentinel URL: {self.sentinel_url}")
        self.logger.event("Initial baseline page open")
        self.open_page(self.sentinel_url, cycle=0, phase="baseline", load_assets=True)

        cycle = 0
        while not self.stopped():
            if deadline is not None and time.monotonic() >= deadline:
                break
            cycle += 1
            idle_seconds = self.idle_seconds_for_cycle(cycle)
            target, target_kind = self.cold_target_for_cycle(cycle)
            self.logger.event(
                f"cycle={cycle} idle start seconds={idle_seconds:.1f} "
                f"target_kind={target_kind} target={target} known_pages={len(self.page_pool)}"
            )
            sleep_for = idle_seconds
            if deadline is not None:
                sleep_for = min(sleep_for, max(0.0, deadline - time.monotonic()))
            if not self.interruptible_sleep(sleep_for, label=f"cycle {cycle} idle"):
                break
            if deadline is not None and time.monotonic() >= deadline:
                break

            self.logger.event(
                f"cycle={cycle} cold-after-idle idle={idle_seconds:.1f}s kind={target_kind} target={target}"
            )
            self.open_page(target, cycle=cycle, phase=f"cold_after_idle:{target_kind}", load_assets=True)
            if self.stopped():
                break

            if not self.interruptible_sleep(self.config.warm_reload_delay):
                break
            self.logger.event(f"cycle={cycle} immediate warm reload target={target}")
            self.open_page(
                target,
                cycle=cycle,
                phase=f"warm_reload:{target_kind}",
                load_assets=True,
                referer=target,
            )

            previous_page = target
            for browse_index in range(max(0, self.config.browse_pages)):
                if self.stopped():
                    break
                delay = random.uniform(DEFAULT_BROWSE_DELAY_MIN, DEFAULT_BROWSE_DELAY_MAX)
                if not self.interruptible_sleep(delay):
                    break
                browse_target = self.choose_page()
                self.open_page(
                    browse_target,
                    cycle=cycle,
                    phase=f"browse_{browse_index + 1}",
                    load_assets=True,
                    referer=previous_page,
                )
                previous_page = browse_target

            if self.stopped():
                break
            self.light_burst(cycle)
            self.logger.write_summary(interrupted=False)
            try:
                snapshot = self.logger.maybe_create_live_snapshot(cycle)
                if snapshot is not None:
                    self.logger.event(f"Consistent live snapshot created: {snapshot}")
            except Exception as exc:
                self.logger.event(f"Live snapshot failed: {type(exc).__name__}: {exc}")

        elapsed = time.monotonic() - started
        self.logger.event(f"Monitor stopping after {elapsed / 3600.0:.3f} hours")
        return self.stopped()


def prompt_float(label: str, default: float, minimum: float | None = None) -> float:
    """Prompt for a float with validation and a displayed default."""

    while True:
        raw = input(f"{label} [{default}]: ").strip()
        if not raw:
            return default
        try:
            value = float(raw)
            if minimum is not None and value < minimum:
                raise ValueError
            return value
        except ValueError:
            console(f"Please enter a number >= {minimum if minimum is not None else '-infinity'}.")


def prompt_int(label: str, default: int, minimum: int = 0, maximum: int | None = None) -> int:
    """Prompt for an integer with bounds and a displayed default."""

    while True:
        raw = input(f"{label} [{default}]: ").strip()
        if not raw:
            return default
        try:
            value = int(raw)
            if value < minimum or (maximum is not None and value > maximum):
                raise ValueError
            return value
        except ValueError:
            maximum_text = f" and <= {maximum}" if maximum is not None else ""
            console(f"Please enter an integer >= {minimum}{maximum_text}.")


def interactive_config(args: argparse.Namespace) -> MonitorConfig:
    """Build configuration from CLI overrides plus interactive defaults."""

    console("PHP Gallery HTTP Monitor")
    console("========================")
    console("No third-party Python packages are required.")
    console("Press Ctrl+C at any time for a clean stop and summary.\n")

    if args.url:
        start_url = normalize_start_url(args.url)
    else:
        while True:
            try:
                start_url = normalize_start_url(input("Website URL to monitor: "))
                break
            except ValueError as exc:
                console(f"Invalid URL: {exc}")

    hours = args.hours if args.hours is not None else prompt_float(
        "Total runtime in hours (0 = until Ctrl+C)", DEFAULT_HOURS, minimum=0.0
    )
    idle_seconds = args.idle if args.idle is not None else prompt_float(
        "Idle seconds before each cold request", DEFAULT_IDLE_SECONDS, minimum=1.0
    )
    browse_pages = args.browse_pages if args.browse_pages is not None else prompt_int(
        "Normal browse pages per cycle", DEFAULT_BROWSE_PAGES, minimum=0, maximum=20
    )
    burst_requests = args.burst if args.burst is not None else prompt_int(
        "Light burst requests per cycle", DEFAULT_BURST_REQUESTS, minimum=0, maximum=50
    )
    burst_concurrency = args.concurrency if args.concurrency is not None else prompt_int(
        "Light burst concurrency", DEFAULT_BURST_CONCURRENCY, minimum=1, maximum=8
    )

    script_dir = Path(__file__).resolve().parent
    log_root = Path(args.log_dir).expanduser().resolve() if args.log_dir else script_dir / "http_monitor_logs"
    return MonitorConfig(
        start_url=start_url,
        hours=hours,
        idle_seconds=idle_seconds,
        medium_idle_seconds=max(idle_seconds, DEFAULT_MEDIUM_IDLE_SECONDS),
        long_idle_seconds=max(idle_seconds, DEFAULT_LONG_IDLE_SECONDS),
        snapshot_every_cycles=DEFAULT_SNAPSHOT_EVERY_CYCLES,
        browse_pages=browse_pages,
        burst_requests=burst_requests,
        burst_concurrency=burst_concurrency,
        asset_limit=args.asset_limit,
        asset_concurrency=args.asset_concurrency,
        connect_timeout=args.connect_timeout,
        request_timeout=args.request_timeout,
        slow_ttfb_seconds=args.slow_ttfb,
        slow_total_seconds=args.slow_total,
        warm_reload_delay=DEFAULT_WARM_RELOAD_DELAY,
        curl_on_anomaly=not args.no_curl,
        user_agent=args.user_agent or DEFAULT_USER_AGENT,
        log_root=log_root,
    )


def build_arg_parser() -> argparse.ArgumentParser:
    """Create optional CLI arguments while keeping simple interactive usage."""

    parser = argparse.ArgumentParser(
        description=(
            "Run a conservative browser-like HTTP monitor to capture intermittent cold-cache, "
            "Not Found, timeout, TLS, proxy and TTFB failures."
        )
    )
    parser.add_argument("--url", help="Website URL. Omit for interactive prompt.")
    parser.add_argument("--hours", type=float, help="Runtime in hours; 0 means until Ctrl+C.")
    parser.add_argument("--idle", type=float, help="Idle seconds before each cold request.")
    parser.add_argument("--browse-pages", type=int, help="Sequential public pages per cycle.")
    parser.add_argument("--burst", type=int, help="GET requests in each bounded light burst.")
    parser.add_argument("--concurrency", type=int, help="Maximum light-burst concurrency (max 8 recommended).")
    parser.add_argument("--asset-limit", type=int, default=DEFAULT_ASSET_LIMIT, help="First-party assets loaded per page.")
    parser.add_argument(
        "--asset-concurrency",
        type=int,
        default=DEFAULT_ASSET_CONCURRENCY,
        help="Maximum concurrent first-party asset requests.",
    )
    parser.add_argument("--connect-timeout", type=float, default=DEFAULT_CONNECT_TIMEOUT)
    parser.add_argument("--request-timeout", type=float, default=DEFAULT_REQUEST_TIMEOUT)
    parser.add_argument("--slow-ttfb", type=float, default=DEFAULT_SLOW_TTFB_SECONDS)
    parser.add_argument("--slow-total", type=float, default=DEFAULT_SLOW_TOTAL_SECONDS)
    parser.add_argument("--user-agent", help="Override the default macOS Chrome-like User-Agent.")
    parser.add_argument("--log-dir", help="Root directory for monitor run logs.")
    parser.add_argument("--no-curl", action="store_true", help="Do not capture system-curl snapshots after anomalies.")
    parser.add_argument("--version", action="version", version=f"%(prog)s {VERSION}")
    return parser


def validate_config(config: MonitorConfig) -> None:
    """Reject unsafe or unreasonable CLI values before network traffic starts."""

    if config.hours < 0:
        raise ValueError("hours must be >= 0")
    if config.idle_seconds < 1:
        raise ValueError("idle seconds must be >= 1")
    if not 0 <= config.browse_pages <= 20:
        raise ValueError("browse-pages must be between 0 and 20")
    if not 0 <= config.burst_requests <= 50:
        raise ValueError("burst must be between 0 and 50")
    if not 1 <= config.burst_concurrency <= 8:
        raise ValueError("concurrency must be between 1 and 8")
    if not 0 <= config.asset_limit <= 100:
        raise ValueError("asset-limit must be between 0 and 100")
    if not 1 <= config.asset_concurrency <= 8:
        raise ValueError("asset-concurrency must be between 1 and 8")
    if config.connect_timeout <= 0 or config.request_timeout <= 0:
        raise ValueError("timeouts must be positive")


def main(argv: list[str] | None = None) -> int:
    """Interactive/CLI entry point."""

    parser = build_arg_parser()
    args = parser.parse_args(argv)
    try:
        config = interactive_config(args)
        validate_config(config)
    except (ValueError, EOFError) as exc:
        console(f"Configuration error: {exc}")
        return 2
    except KeyboardInterrupt:
        console("\nCancelled before start.")
        return 130

    logger = RunLogger(config.log_root, config)
    monitor = HttpMonitor(config, logger)
    console("\nScenario")
    console("--------")
    console(f"URL:                 {config.start_url}")
    console(f"Runtime:             {'until Ctrl+C' if config.hours == 0 else f'{config.hours:g} h'}")
    console(
        f"Idle schedule:        {config.idle_seconds:g} s normally, "
        f"{config.medium_idle_seconds:g} s every 5th, {config.long_idle_seconds:g} s every 10th cycle"
    )
    console(f"Browse pages/cycle:  {config.browse_pages}")
    console(f"Light burst:         {config.burst_requests} requests @ concurrency {config.burst_concurrency}")
    console(f"Asset load cap:      {config.asset_limit} @ concurrency {config.asset_concurrency}")
    curl_binary = find_curl_binary()
    console(f"Anomaly curl probe:  {'yes: ' + curl_binary if config.curl_on_anomaly and curl_binary else 'no'}")
    console(f"Logs:                {logger.run_dir}")
    console("\nStarting. Ctrl+C stops cleanly.\n")

    interrupted = False
    try:
        interrupted = monitor.run()
    except KeyboardInterrupt:
        interrupted = True
        monitor.stop()
        logger.event("Ctrl+C received; stopping gracefully")
    except Exception as exc:
        interrupted = True
        logger.event(f"Fatal monitor exception: {type(exc).__name__}: {exc}")
        crash_path = logger.run_dir / "fatal_exception.txt"
        crash_path.write_text(traceback.format_exc(), encoding="utf-8")
        console(f"Fatal error details: {crash_path}")
    finally:
        logger.write_summary(interrupted=interrupted)

    archive_path: Path | None = None
    try:
        archive_path = logger.create_archive()
    except Exception as exc:
        logger.event(f"Could not create final run ZIP: {type(exc).__name__}: {exc}")

    console("\nFinished.")
    console(f"Summary: {logger.summary_path}")
    console(f"Full JSONL: {logger.jsonl_path}")
    console(f"Compact CSV: {logger.csv_path}")
    console(f"Anomaly captures: {logger.anomaly_dir}")
    if logger.anomaly_report_path.exists():
        console(f"Anomaly HTML report: {logger.anomaly_report_path}")
    if archive_path is not None:
        console(f"Upload-friendly ZIP: {archive_path}")
    return 130 if interrupted else 0


if __name__ == "__main__":
    raise SystemExit(main())
