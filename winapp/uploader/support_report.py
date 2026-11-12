# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/support_report.py
# Module Type: Windows Tooling
# Purpose: Build a local, bounded and privacy-filtered support report.
# Responsibilities:
#   - Format current diagnostics and allowlisted runtime/settings metadata.
#   - Include a bounded tail of an existing log without reading it whole.
#   - Save a report atomically with restrictive local file permissions.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Build complete offline support text while filtering local sensitive data."""

import math
import os
import re
import tempfile
from collections.abc import Iterable, Mapping
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

from .diagnostics import redact_text

# Purpose: Bound log-tail bytes included in one report.
# Type: int. Units: bytes. Scope: one support report log attachment.
# Consumers: build_support_report. Rationale: Keeps excerpts and memory small.
SUPPORT_LOG_TAIL_MAX_BYTES = 65_536

# Purpose: Identify whether the bounded log offset starts at a line boundary.
# Type: int. Units: bytes. Scope: one report log-tail seek.
# Consumers: build_support_report. Rationale: One preceding byte is sufficient.
SUPPORT_LOG_BOUNDARY_PROBE_BYTES = 1

# Purpose: Bound each allowlisted metadata value before report formatting.
# Type: int. Units: characters. Scope: one setting or runtime metadata value.
# Consumers: build_support_report. Rationale: Metadata should stay concise.
SUPPORT_METADATA_VALUE_MAX_CHARS = 256

# Purpose: Bound report text independent of diagnostic and log input sizes.
# Type: int. Units: characters. Scope: one complete report.
# Consumers: build_support_report. Rationale: Protect clipboard and disk use.
SUPPORT_REPORT_MAX_CHARS = 150_000

_SETTINGS_KEYS = frozenset({
    "scan_interval_seconds", "stable_seconds", "watch_recursive",
    "create_thumbnails", "attach_sim_camera_metadata", "sync_utc_time",
    "delete_uploaded_files", "close_to_tray", "start_with_windows",
    "manual_thumbnail_mode", "manual_thumbnail_workers",
    "manual_upload_workers", "ai_worker_enabled", "ai_vision_backend",
})
_RUNTIME_KEYS = frozenset({
    "app_version", "python_version", "platform", "machine", "runtime",
    "tk", "pillow",
})
_IDENTITY_RE = re.compile(
    r"(?i)([A-Z]:\\Users\\|/home/|/Users/)[^\\/\s]+"
)
_URL_RE = re.compile(r"(?i)\bhttps?://[^\s<>\"']+")
_POSIX_PATH_RE = re.compile(r"(?<![\w])/(?:[^\s/:]+/)+[^\s,:;]+")
_HOST_LABEL_RE = re.compile(
    r"(?i)(\b(?:host(?:[_ -]?name)?|computer(?:[_ -]?name)?|"
    r"user(?:[_ -]?name)?)\s*[=:]\s*)[^\s,;]+"
)
_EMAIL_RE = re.compile(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", re.I)
_SECRET_FIELD_RE = re.compile(
    r"(?i)([\"']?(?:api[_ -]?key|authorization|access[_ -]?token|"
    r"refresh[_ -]?token|id[_ -]?token|jwt|token|client[_ -]?secret|"
    r"private[_ -]?key|secret|password|cookie|set-cookie)"
    r"[\"']?\s*[:=]\s*)(?:\"[^\"]*\"|'[^']*'|[^\s,;}\]]+)"
)
_AUTHORIZATION_RE = re.compile(
    r"(?i)([\"']?authorization[\"']?\s*[:=]\s*)"
    r"(?:[\"']?bearer\s+)?[^\r\n,;}]+"
)
_BEARER_RE = re.compile(r"(?i)\bbearer\s+[^\s,;\"'}]+")
_COOKIE_LINE_RE = re.compile(
    r"(?im)([\"']?(?:set-cookie|cookie)[\"']?\s*[:=]\s*)[^\r\n]+"
)
_COORDINATE_RE = re.compile(
    r"(?i)[\"']?\b(latitude_deg|longitude_deg|latitude|longitude|"
    r"altitude|coordinates?|lat|lon|lng|alt|gps|position)\b[\"']?"
    r"\s*[=:]\s*(?:\"[^\"]*\"|'[^']*'|[^\s,;}]+)"
)


def _redact_report_text(value: str, secrets: Iterable[str] = ()) -> str:
    """Apply diagnostic redaction plus report-specific privacy filters.

    Args:
        value (str): Untrusted diagnostic or log excerpt.
        secrets (Iterable[str]): Known credential values to remove literally.

    Returns:
        str: Redacted text with local identities, URLs and credentials removed.
    """
    result = _AUTHORIZATION_RE.sub(r"\1[REDACTED]", value)
    result = _COOKIE_LINE_RE.sub(r"\1[REDACTED]", result)
    result = _BEARER_RE.sub("Bearer [REDACTED]", result)
    result = _SECRET_FIELD_RE.sub(r"\1[REDACTED]", result)
    result = redact_text(result, secrets, redact_paths=True)
    urls: list[str] = []

    def protect_url(match: re.Match[str]) -> str:
        """Protect one sanitized URL from local path filtering.

        Args:
            match (re.Match[str]): Complete URL token in report text.

        Returns:
            str: Temporary marker restored after path filtering.
        """
        urls.append(_redact_url(match.group(0)))
        return f"\x00SUPPORT_URL_{len(urls) - 1}\x00"

    result = _URL_RE.sub(protect_url, result)
    result = _IDENTITY_RE.sub(r"\1[USER]", result)
    result = _POSIX_PATH_RE.sub("[LOCAL PATH]", result)
    result = _HOST_LABEL_RE.sub(r"\1[REDACTED]", result)
    result = _EMAIL_RE.sub("[EMAIL]", result)
    result = _COORDINATE_RE.sub(r"\1=[REDACTED]", result)
    for index, url in enumerate(urls):
        result = result.replace(f"\x00SUPPORT_URL_{index}\x00", url)
    return result


def _redact_url(value: str) -> str:
    """Remove URL credentials, query values and fragments.

    Args:
        value (str): One URL token found in report text.

    Returns:
        str: URL origin/path without credentials or query/fragment data.
    """
    try:
        parsed = urlsplit(value)
    except ValueError:
        return "[URL REDACTED]"
    host = parsed.hostname or ""
    try:
        port = parsed.port
    except ValueError:
        port = None
    if ":" in host and not host.startswith("["):
        host = f"[{host}]"
    if port is not None:
        host += f":{port}"
    query = "[REDACTED]" if parsed.query else ""
    fragment = "[REDACTED]" if parsed.fragment else ""
    return urlunsplit((parsed.scheme, host, parsed.path, query, fragment))


def _safe_metadata(
    values: Mapping[str, str | int | float | bool | None],
    allowed_keys: frozenset[str],
    secrets: tuple[str, ...],
) -> list[tuple[str, str]]:
    """Select and bound primitive values from a fixed metadata allowlist.

    Args:
        values (Mapping[str, str | int | float | bool | None]): Caller metadata;
            only finite floating-point values are retained.
        allowed_keys (frozenset[str]): Fixed field names admitted to output.
        secrets (tuple[str, ...]): Known credentials excluded from values.

    Returns:
        list[tuple[str, str]]: Ordered field/value pairs safe for rendering.
    """
    output: list[tuple[str, str]] = []
    for key in sorted(allowed_keys):
        value = values.get(key)
        if type(value) not in (str, int, float, bool) and value is not None:
            continue
        if value is None:
            continue
        if type(value) is float and not math.isfinite(value):
            continue
        safe = _redact_report_text(
            str(value), secrets,
        )[:SUPPORT_METADATA_VALUE_MAX_CHARS]
        output.append((key, safe))
    return output


def build_support_report(
    diagnostic_text: str,
    settings: Mapping[str, str | int | float | bool | None],
    runtime: Mapping[str, str | int | float | bool | None],
    log_path: Path | None = None,
    *,
    captured_at: datetime | None = None,
    secrets: Iterable[str] = (),
) -> str:
    """Build readable offline support text from safe diagnostics and local log.

    Args:
        diagnostic_text (str): Prebuilt current diagnostic details to retain.
        settings (Mapping[str, str | int | float | bool | None]): Settings;
            only allowlisted primitive values and finite floats are included.
        runtime (Mapping[str, str | int | float | bool | None]): Runtime facts;
            only allowlisted primitive values and finite floats are included.
        log_path (Path | None): Optional existing log whose tail is included.
        captured_at (datetime | None): Optional deterministic capture timestamp.
        secrets (Iterable[str]): Known credentials to remove from metadata,
            diagnostics and log text even when they lack a recognizable label.

    Returns:
        str: Complete bounded report with redacted diagnostics and log status.
    """
    timestamp = captured_at or datetime.now(timezone.utc)
    if timestamp.tzinfo is None:
        timestamp = timestamp.replace(tzinfo=timezone.utc)
    known_secrets = tuple(secret for secret in secrets if secret)
    lines = [
        "PHP Gallery Windows Support Report",
        f"Captured: {timestamp.astimezone(timezone.utc).isoformat()}",
        "Collection: offline; no network access",
        "",
        "Runtime",
    ]
    lines.extend(
        f"{key}: {value}"
        for key, value in _safe_metadata(runtime, _RUNTIME_KEYS, known_secrets)
    )
    lines.extend(["", "Settings"])
    lines.extend(
        f"{key}: {value}"
        for key, value in _safe_metadata(settings, _SETTINGS_KEYS, known_secrets)
    )
    lines.extend([
        "", "Current diagnostics",
        _redact_report_text(diagnostic_text, known_secrets),
    ])
    lines.extend(["", "Log tail"])
    if log_path is None:
        lines.append("status: not requested")
    else:
        try:
            with log_path.open("rb") as stream:
                stream.seek(0, os.SEEK_END)
                total_bytes = stream.tell()
                offset = max(0, total_bytes - SUPPORT_LOG_TAIL_MAX_BYTES)
                previous_byte = b""
                if offset:
                    stream.seek(offset - 1)
                    previous_byte = stream.read(SUPPORT_LOG_BOUNDARY_PROBE_BYTES)
                stream.seek(offset)
                tail = stream.read(SUPPORT_LOG_TAIL_MAX_BYTES)
            leading_partial_line = bool(offset and previous_byte != b"\n")
            if leading_partial_line:
                newline = tail.find(b"\n")
                tail = tail[newline + 1:] if newline >= 0 else b""
            lines.append(
                f"status: included; bytes={len(tail)}; "
                f"truncated={'yes' if offset else 'no'}; "
                f"leading_partial_line={'dropped' if leading_partial_line else 'no'}"
            )
            lines.append(_redact_report_text(
                tail.decode("utf-8", errors="replace"), known_secrets,
            ))
        except FileNotFoundError:
            lines.append("status: missing")
        except OSError:
            lines.append("status: unreadable")
    report = "\n".join(lines)
    if len(report) > SUPPORT_REPORT_MAX_CHARS:
        marker = "\n[REPORT TRUNCATED]"
        report = report[:SUPPORT_REPORT_MAX_CHARS - len(marker)] + marker
    return report


def save_support_report(path: Path, report: str) -> None:
    """Atomically save support text as a restrictive local file.

    Args:
        path (Path): User-selected destination path.
        report (str): Complete report text returned by the builder.

    Returns:
        None: The destination contains the report after a successful write.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{path.name}.", suffix=".tmp", dir=path.parent,
    )
    try:
        if os.name != "nt":
            os.chmod(temporary_name, 0o600)
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as stream:
            stream.write(report)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary_name, path)
    except BaseException:
        try:
            os.close(descriptor)
        except OSError:
            pass
        try:
            os.unlink(temporary_name)
        except OSError:
            pass
        raise
