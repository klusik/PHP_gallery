# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Discover and verify public Windows installer updates.
# Responsibilities:
#   - Discover independent uploader versions and enforce trusted metadata.
#   - Download bounded installers and verify integrity before execution.
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/self_update.py
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Bounded, credential-free GitHub update discovery and verified downloads.

Only the uploader installer filename determines its version. Repository release
tags describe the CMS and are deliberately ignored. A missing SHA256 for the
highest newer installer is an error, never a reason to offer an older update.
"""

from dataclasses import dataclass
from contextlib import contextmanager
from contextvars import ContextVar, Token
from pathlib import Path
from types import TracebackType
from typing import Callable, Iterator, Optional, Any, Protocol
import hashlib
import uuid
import math
import json
import os
import re
import socket
import ssl
import tempfile
import threading
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request

from .models import JsonValue

REPOSITORY = "klusik/PHP_gallery"
API_URL = "https://api.github.com/repos/" + REPOSITORY + "/releases"
MAX_INSTALLER_SIZE = 1024 * 1024 * 1024
MAX_JSON_SIZE = 4 * 1024 * 1024
MAX_PAGES = 20
DISCOVERY_BUDGET_SECONDS = 120
TIMEOUT = 30
# Purpose: Recognize HTTP status integers under the HTTP protocol range.
# Type: int. Units: status code. Scope: updater diagnostics only.
# Consumers: _safe_exception_facts, _record_update_diagnostic and formatter.
# Rationale: Values outside the protocol's three-digit status range are not
# retained as server responses.
UPDATE_HTTP_STATUS_MIN = 100
UPDATE_HTTP_STATUS_MAX = 599
# Purpose: Bound numeric OS and TLS codes written to updater logs.
# Type: int. Units: absolute integer code. Scope: one diagnostic event.
# Consumers: _safe_exception_facts and format_update_diagnostic.
# Rationale: Platform errno/winerror/verification codes fit this bounded range;
# arbitrary exception attributes must not create unbounded log records.
UPDATE_DIAGNOSTIC_ERROR_CODE_MAX = 2**32 - 1
# Purpose: Bound exception traversal in an updater diagnostic event.
# Type: int. Units: exception objects or traceback frames. Scope: one failed
# update operation.
# Consumers: _safe_exception_facts. Rationale: eight links capture urllib's
# HTTPError/URLError/reason chain and latest owned frames while stopping cycles
# and hostile depth.
UPDATE_DIAGNOSTIC_CAUSE_LIMIT = 8
# Purpose: Bound locally authored exception class names in diagnostics.
# Type: int. Units: characters. Scope: one update diagnostic event.
# Consumers: _safe_exception_facts. Rationale: 64 characters retain ordinary
# built-in and app exception names without exposing arbitrary exception text.
UPDATE_DIAGNOSTIC_TYPE_NAME_MAX_CHARS = 64
_NAME = re.compile(r"PHPGalleryUploader-(\d+\.\d+\.\d+)-Setup\.exe\Z")
_VERSION = re.compile(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\Z")
_HASH = re.compile(r"[a-fA-F0-9]{64}\Z")


class _UpdateResponse(Protocol):
    """Describe the context-managed response consumed by update requests."""

    def read(self, size: int = -1) -> bytes:
        """Read at most the requested bytes from the response stream.

        Args:
            size (int): Maximum bytes to read, or -1 for the stream default.

        Returns:
            bytes: Raw response bytes from the trusted update endpoint.
        """
        ...

    def geturl(self) -> str:
        """Return the final URL for the redirect trust check.

        Returns:
            str: Final request URL, validated before response contents are used.
        """
        ...

    def close(self) -> None:
        """Close the response after a refused redirect or completed read.

        Returns:
            None: Releases response resources owned by the transport.
        """
        ...

    def __enter__(self) -> "_UpdateResponse":
        """Enter the response lifetime used by metadata and installer reads.

        Returns:
            _UpdateResponse: This live context-managed response.
        """
        ...

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool | None:
        """Release the response when its read context exits.

        Args:
            exc_type (type[BaseException] | None): Exception class, if raised.
            exc_value (BaseException | None): Exception instance, if raised.
            traceback (TracebackType | None): Active traceback, if raised.

        Returns:
            bool | None: True only when the response owner suppresses the error.
        """
        ...


class _UpdateOpener(Protocol):
    """Describe the request interface required by update transport owners."""

    def open(
        self, request: urllib.request.Request, timeout: float,
    ) -> _UpdateResponse:
        """Open a prepared request under the existing bounded timeout.

        Args:
            request (urllib.request.Request): Trusted request with fixed headers.
            timeout (float): Existing maximum wait for this HTTP operation.

        Returns:
            _UpdateResponse: Context-managed response stream for this request.
        """
        ...


class UpdateError(RuntimeError):
    """An update cannot safely be discovered, downloaded, or verified."""


class UpdateCancelled(UpdateError):
    """The caller cancelled a download before its verified completion."""


@dataclass(frozen=True)
class UpdateDiagnostic:
    """Describe one updater phase without retaining request or exception text.

    Attributes:
        phase (str): Fixed high-level updater operation such as discovery or
            download.
        stage (str): Fixed owner step such as http_open or installer_verify.
        endpoint (str): Fixed endpoint role, never a URL.
        host (str): Exact known public host or local/unknown.
        outcome (str): Started, succeeded, failed or cancelled.
        elapsed_ms (int): Monotonic duration for the bounded operation.
        bytes_received (int | None): Known response or installer bytes.
        http_status (int | None): Valid HTTP status code, when observed.
        category (str | None): Fixed error classification.
        error_type (str | None): Bounded Python exception class name.
        cause_types (tuple[str, ...]): Bounded exception-chain class names.
        error_code (int | None): Numeric errno, winerror or TLS verify code.
        source (str): Fixed source owner label, never a filesystem path.
        error_site (str | None): Owned source basename, function and line.
    """

    phase: str
    stage: str
    endpoint: str
    host: str
    outcome: str
    elapsed_ms: int
    bytes_received: int | None = None
    http_status: int | None = None
    category: str | None = None
    error_type: str | None = None
    cause_types: tuple[str, ...] = ()
    error_code: int | None = None
    source: str = ''
    error_site: str | None = None


_UPDATE_DIAGNOSTIC_SINK: ContextVar[
    Callable[[UpdateDiagnostic], None] | None
] = ContextVar('php_gallery_update_diagnostic_sink', default=None)


@contextmanager
def capture_update_diagnostics(
    sink: Callable[[UpdateDiagnostic], None],
) -> Iterator[None]:
    """Route safe updater facts to a caller-owned thread-safe sink.

    Args:
        sink (Callable[[UpdateDiagnostic], None]): Worker-safe event receiver.

    Returns:
        Iterator[None]: Context manager that scopes the sink to the current
            thread until its ``with`` block exits.

    Yields:
        None: Nested updater owners publish bounded events until the scope exits.
    """
    token: Token[Callable[[UpdateDiagnostic], None] | None] = (
        _UPDATE_DIAGNOSTIC_SINK.set(sink)
    )
    try:
        yield
    finally:
        _UPDATE_DIAGNOSTIC_SINK.reset(token)


def _safe_exception_facts(
    error: BaseException,
) -> tuple[
    str, tuple[str, ...], str, int | None, int | None, str | None,
    str | None,
]:
    """Extract explicit-cause and transport-reason facts without messages.

    Args:
        error (BaseException): Failure whose message and arguments are ignored.

    Returns:
        tuple[str, tuple[str, ...], str, int | None, int | None, str | None, str | None]: Top-level class, bounded explicit-cause names, fixed
            category, HTTP status, numeric platform or TLS code, owned-source
            frame label, and exact allowlisted HTTP error host. Implicit
            exception context is excluded so cleanup cannot inherit a prior
            operation's failure classification.
    """
    pending: list[BaseException] = [error]
    seen: set[int] = set()
    names: list[str] = []
    category = 'other'
    http_status: int | None = None
    http_host: str | None = None
    error_code: int | None = None
    while pending and len(names) < UPDATE_DIAGNOSTIC_CAUSE_LIMIT:
        current = pending.pop(0)
        if id(current) in seen:
            continue
        seen.add(id(current))
        class_name = type(current).__name__
        if (not class_name.isascii() or not class_name.isidentifier()
                or len(class_name) > UPDATE_DIAGNOSTIC_TYPE_NAME_MAX_CHARS):
            class_name = 'Exception'
        names.append(class_name)

        if isinstance(current, urllib.error.HTTPError):
            category = 'http'
            code = current.code
            if (type(code) is int and UPDATE_HTTP_STATUS_MIN <= code
                    <= UPDATE_HTTP_STATUS_MAX):
                http_status = code
            error_url = getattr(current, 'url', None)
            if isinstance(error_url, str):
                _, http_host = _endpoint_facts(error_url)
        elif isinstance(current, urllib.error.URLError):
            if category == 'other':
                category = 'url_transport'
            reason = current.reason
            if isinstance(reason, BaseException):
                pending.append(reason)
        elif isinstance(current, ssl.SSLError):
            category = 'tls'
            verify_code = getattr(current, 'verify_code', None)
            if (type(verify_code) is int
                    and -UPDATE_DIAGNOSTIC_ERROR_CODE_MAX <= verify_code
                    <= UPDATE_DIAGNOSTIC_ERROR_CODE_MAX):
                error_code = verify_code
        elif isinstance(current, socket.gaierror):
            category = 'dns'
        elif isinstance(current, (TimeoutError, socket.timeout)):
            category = 'timeout'
        elif isinstance(current, ConnectionError):
            category = 'connection'
        elif isinstance(current, UpdateCancelled):
            category = 'cancelled'

        for code_name in ('winerror', 'errno'):
            code_value = getattr(current, code_name, None)
            if (error_code is None and type(code_value) is int
                    and -UPDATE_DIAGNOSTIC_ERROR_CODE_MAX <= code_value
                    <= UPDATE_DIAGNOSTIC_ERROR_CODE_MAX):
                error_code = code_value
                break
        cause = current.__cause__
        if isinstance(cause, BaseException):
            pending.append(cause)

    top_name = names[0] if names else 'Exception'
    error_site: str | None = None
    try:
        frames = traceback.extract_tb(
            error.__traceback__, limit=-UPDATE_DIAGNOSTIC_CAUSE_LIMIT,
        )
        for frame in frames:
            filename = Path(frame.filename).name
            if filename not in {'self_update.py', 'update_ui.py'}:
                continue
            function = frame.name
            if not function.isascii() or not function.isidentifier():
                continue
            if len(function) > UPDATE_DIAGNOSTIC_TYPE_NAME_MAX_CHARS:
                continue
            error_site = f'{filename}:{function}:{frame.lineno}'
    except Exception:
        error_site = None
    return (top_name, tuple(names[1:]), category, http_status, error_code,
            error_site, http_host)


def _record_update_diagnostic(
    phase: str, stage: str, endpoint: str, host: str, outcome: str,
    started: float, *, bytes_received: int | None = None,
    http_status: int | None = None, category: str | None = None,
    error: BaseException | None = None, source: str,
    error_site: str | None = None,
) -> None:
    """Publish one allowlisted updater fact without affecting the operation.

    Args:
        phase (str): Fixed updater operation name.
        stage (str): Fixed owner operation name.
        endpoint (str): Fixed endpoint role, never a URL.
        host (str): Exact allowlisted host or local/unknown.
        outcome (str): Started, succeeded, failed or cancelled.
        started (float): Monotonic operation start time.
        bytes_received (int | None): Known byte count, if available.
        http_status (int | None): Observed HTTP response status, if available.
        category (str | None): Fixed known category override.
        error (BaseException | None): Error to classify without formatting.
        source (str): Fixed owned module and function label.
        error_site (str | None): Safe owned source frame label, if known.

    Returns:
        None: Sends an immutable event or silently ignores sink failure.
    """
    sink = _UPDATE_DIAGNOSTIC_SINK.get()
    if sink is None:
        return
    try:
        elapsed_ms = max(0, int((time.monotonic() - started) * 1000))
        error_type: str | None = None
        cause_types: tuple[str, ...] = ()
        error_code: int | None = None
        safe_error_site = error_site
        safe_host = host
        safe_status = (
            http_status if type(http_status) is int
            and UPDATE_HTTP_STATUS_MIN <= http_status <= UPDATE_HTTP_STATUS_MAX
            else None
        )
        safe_bytes = (bytes_received if type(bytes_received) is int
                      and bytes_received >= 0 else None)
        if error is not None:
            (error_type, cause_types, discovered_category,
             discovered_status, error_code, discovered_site,
             discovered_http_host) = (
                _safe_exception_facts(error)
            )
            safe_error_site = safe_error_site or discovered_site
            if discovered_http_host is not None:
                safe_host = discovered_http_host
            category = (discovered_category if discovered_category != 'other'
                        else category or discovered_category)
            safe_status = safe_status or discovered_status
        sink(UpdateDiagnostic(
            phase=phase, stage=stage, endpoint=endpoint, host=safe_host,
            outcome=outcome, elapsed_ms=elapsed_ms,
            bytes_received=safe_bytes, http_status=safe_status,
            category=category, error_type=error_type,
            cause_types=cause_types, error_code=error_code, source=source,
            error_site=safe_error_site,
        ))
    except Exception:
        # Diagnostics must never replace the updater's primary result.
        return


def report_update_diagnostic(
    phase: str, stage: str, endpoint: str, host: str, outcome: str, *,
    bytes_received: int | None = None, http_status: int | None = None,
    category: str | None = None, error: BaseException | None = None,
    source: str, started: float | None = None,
) -> None:
    """Publish one instantaneous safe fact to the active worker sink.

    Args:
        phase (str): Fixed updater operation label.
        stage (str): Fixed owner step label.
        endpoint (str): Fixed endpoint role, never a URL.
        host (str): Exact known host or local/unknown.
        outcome (str): Started, succeeded, failed or cancelled.
        bytes_received (int | None): Known bounded byte count, if available.
        http_status (int | None): Observed HTTP status, if available.
        category (str | None): Fixed error category override.
        error (BaseException | None): Exception classified without formatting.
        source (str): Fixed owned module/function label.
        started (float | None): Monotonic start time for measured work.

    Returns:
        None: Emits only when a worker diagnostic scope is active.
    """
    _record_update_diagnostic(
        phase, stage, endpoint, host, outcome,
        time.monotonic() if started is None else started,
        bytes_received=bytes_received, http_status=http_status,
        category=category, error=error, source=source,
    )


def format_update_diagnostic(diagnostic: UpdateDiagnostic) -> str:
    """Render one diagnostic while isolating malformed event fields.

    Args:
        diagnostic (UpdateDiagnostic): Immutable event from an updater owner.

    Returns:
        str: Bounded summary, or a fixed fallback if any field is malformed.
    """
    try:
        return _format_update_diagnostic(diagnostic)
    except Exception:
        return '[updater] diagnostic=unavailable'


def _format_update_diagnostic(diagnostic: UpdateDiagnostic) -> str:
    """Render a compact allowlisted updater event for the ordinary log.

    Args:
        diagnostic (UpdateDiagnostic): Immutable worker event to summarize.

    Returns:
        str: Bounded safe fields only; malformed values become ``unknown``.
    """
    allowed = {
        'phase': {'network', 'discovery', 'verification', 'download', 'cleanup', 'handoff', 'update'},
        'stage': {
            'http_open', 'metadata_read', 'installer_verify', 'installer_download',
            'partial_remove', 'update_worker', 'previous_installer_cleanup',
            'download_session_cleanup', 'install_gate', 'helper_handoff',
            'worker_operation', 'checkpoint_wait', 'helper_prepare',
            'helper_bind', 'helper_launch', 'installed_identity',
            'checkpoint_cancel',
        },
        'endpoint': {
            'release_api', 'release_manifest', 'installer_asset',
            'local_installer', 'local', 'unknown',
        },
        'host': {
            'api.github.com', 'github.com', 'release-assets.githubusercontent.com',
            'objects.githubusercontent.com', 'github-releases.githubusercontent.com',
            'local', 'unknown',
        },
        'outcome': {'started', 'succeeded', 'failed', 'cancelled'},
        'category': {
            'http', 'url_transport', 'dns', 'timeout', 'tls', 'connection',
            'cancelled', 'other', 'transport_failure', 'security_refusal',
            'response_size_limit',
            'invalid_json', 'integrity_refusal', 'integrity_or_storage_refusal',
            'cleanup_refused', 'checkpoint_refused',
        },
        'source': {
            'self_update._open', 'self_update._json',
            'self_update.verify_installer', 'self_update.download_update',
            'update_ui.UpdateController.run_worker',
            'update_ui.UpdateController.check',
            'update_ui.UpdateController.download',
            'update_ui.UpdateController.handoff',
            'update_ui.UpdateController.install',
            'update_ui.UpdateController.poll',
        },
    }

    def label(value: str | None, field: str) -> str:
        """Return a fixed known field label without converting arbitrary input.

        Args:
            value (str | None): Candidate diagnostic field.
            field (str): Field name with a fixed allowlist above.

        Returns:
            str: Allowed value or ``unknown``.
        """
        choices = allowed[field]
        return value if isinstance(value, str) and value in choices else 'unknown'

    fields = [
        '[updater]',
        'phase=' + label(diagnostic.phase, 'phase'),
        'stage=' + label(diagnostic.stage, 'stage'),
        'endpoint=' + label(diagnostic.endpoint, 'endpoint'),
        'host=' + label(diagnostic.host, 'host'),
        'outcome=' + label(diagnostic.outcome, 'outcome'),
    ]
    if type(diagnostic.elapsed_ms) is int and diagnostic.elapsed_ms >= 0:
        fields.append(f'elapsed_ms={diagnostic.elapsed_ms}')
    if type(diagnostic.bytes_received) is int and diagnostic.bytes_received >= 0:
        fields.append(f'bytes_received={diagnostic.bytes_received}')
    if (type(diagnostic.http_status) is int
            and UPDATE_HTTP_STATUS_MIN <= diagnostic.http_status
            <= UPDATE_HTTP_STATUS_MAX):
        fields.append(f'http_status={diagnostic.http_status}')
    category = label(diagnostic.category, 'category')
    if category != 'unknown':
        fields.append('category=' + category)
    if (isinstance(diagnostic.error_type, str)
            and diagnostic.error_type.isascii()
            and diagnostic.error_type.isidentifier()
            and len(diagnostic.error_type) <= UPDATE_DIAGNOSTIC_TYPE_NAME_MAX_CHARS):
        fields.append('error_type=' + diagnostic.error_type)
    if isinstance(diagnostic.cause_types, tuple) and diagnostic.cause_types:
        names = tuple(
            name for name in diagnostic.cause_types[:UPDATE_DIAGNOSTIC_CAUSE_LIMIT]
            if isinstance(name, str) and name.isascii() and name.isidentifier()
            and len(name) <= UPDATE_DIAGNOSTIC_TYPE_NAME_MAX_CHARS
        )
        if names:
            fields.append('causes=' + ','.join(names))
    if (type(diagnostic.error_code) is int
            and -UPDATE_DIAGNOSTIC_ERROR_CODE_MAX
            <= diagnostic.error_code <= UPDATE_DIAGNOSTIC_ERROR_CODE_MAX):
        fields.append(f'error_code={diagnostic.error_code}')
    source = label(diagnostic.source, 'source')
    if source != 'unknown':
        fields.append('source=' + source)
    if isinstance(diagnostic.error_site, str):
        parts = diagnostic.error_site.rsplit(':', 2)
        if (len(parts) == 3
                and parts[0] in {'self_update.py', 'update_ui.py'}
                and parts[1].isascii() and parts[1].isidentifier()
                and len(parts[1]) <= UPDATE_DIAGNOSTIC_TYPE_NAME_MAX_CHARS
                and parts[2].isdigit()
                and len(parts[2]) <= UPDATE_DIAGNOSTIC_TYPE_NAME_MAX_CHARS):
            fields.append('error_site=' + diagnostic.error_site)
    return ' '.join(fields)


@dataclass(frozen=True)
class ReleaseAsset:
    """Immutable validated installer metadata with an expected SHA256 digest."""

    version: str
    name: str
    download_url: str
    size: int
    sha256: str
    asset_id: int
    release_id: int


def _version(value: str) -> tuple[int, int, int]:
    """Parse a canonical numeric uploader version without lexical ordering."""
    if not isinstance(value, str) or len(value) > 32 or not _VERSION.fullmatch(value):
        raise UpdateError("Invalid uploader version; expected major.minor.patch.")
    major, minor, patch = value.split(".")
    return int(major), int(minor), int(patch)


def _positive(value: Any, maximum: int) -> int:
    """Validate bounded JSON integers while excluding booleans."""
    if type(value) is not int or not 0 < value <= maximum:
        raise UpdateError("GitHub returned invalid update metadata.")
    return value


def _asset_url(url: Any, name: str) -> str:
    """Require the exact repository and filename before any download request."""
    if not isinstance(url, str) or len(url) > 4096:
        raise UpdateError("Invalid installer download URL.")
    try:
        parsed = urllib.parse.urlsplit(url)
    except ValueError as exc:
        raise UpdateError("Invalid installer download URL.") from exc
    prefix = "/" + REPOSITORY + "/releases/download/"
    path = parsed.path
    tail = path[len(prefix):] if path.startswith(prefix) else ""
    parts = tail.split("/")
    if (parsed.scheme != "https" or parsed.netloc != "github.com"
            or parsed.query or parsed.fragment or len(parts) != 2
            or not parts[0] or urllib.parse.unquote(parts[1]) != name
            or "/" in urllib.parse.unquote(parts[0])):
        raise UpdateError("Installer URL does not belong to the expected GitHub release.")
    return url


def _trusted_url(url: str) -> bool:
    """Allow GitHub's exact public API/download hosts, never wildcard domains."""
    try:
        parsed = urllib.parse.urlsplit(url)
    except ValueError:
        return False
    return (parsed.scheme == "https" and parsed.netloc in {
        "api.github.com", "github.com", "release-assets.githubusercontent.com",
        "objects.githubusercontent.com", "github-releases.githubusercontent.com",
    } and not parsed.fragment)


def _endpoint_facts(url: str) -> tuple[str, str]:
    """Map a validated update URL to fixed endpoint and host labels.

    Args:
        url (str): Candidate URL inspected only for a known host and route role.

    Returns:
        tuple[str, str]: Fixed endpoint role and exact known host, or unknown.
    """
    if not isinstance(url, str):
        return 'unknown', 'unknown'
    try:
        parsed = urllib.parse.urlsplit(url)
        host = parsed.hostname or ''
    except ValueError:
        parsed = None
        host = ''
    known_hosts = {
        'api.github.com', 'github.com', 'release-assets.githubusercontent.com',
        'objects.githubusercontent.com', 'github-releases.githubusercontent.com',
    }
    safe_host = host if host in known_hosts else 'unknown'
    if parsed is None or safe_host == 'unknown':
        return 'unknown', safe_host
    if safe_host == 'api.github.com' and parsed.path.startswith('/repos/'):
        return 'release_api', safe_host
    if safe_host == 'github.com' and parsed.path.endswith('/winapp-update.json'):
        return 'release_manifest', safe_host
    if safe_host in known_hosts:
        return 'installer_asset', safe_host
    return 'unknown', 'unknown'


class _Redirects(urllib.request.HTTPRedirectHandler):
    """Reject unsupported redirect hosts before urllib sends a request."""
    def redirect_request(self, req: Any, fp: Any, code: int, msg: str,
                         headers: Any, newurl: str) -> Any:
        """Validate the destination before following an HTTP redirect."""
        if not _trusted_url(newurl):
            raise UpdateError("GitHub redirected the update to an untrusted address.")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def _open(url: str, opener: _UpdateOpener | None) -> _UpdateResponse:
    """Open one trusted bounded-time public request and record safe facts.

    Args:
        url (str): Trusted public API or release-asset URL.
        opener (_UpdateOpener | None): Optional request opener implementing
            ``open(urllib.request.Request, timeout)``. None selects urllib's
            default opener with the trusted redirect handler.

    Returns:
        _UpdateResponse: Context-managed response providing ``read(size)``,
            final ``geturl()`` and ``close()``. Optional ``getcode()`` status
            metadata is recorded when the response implementation provides it.

    Raises:
        UpdateError: URL, redirect or transport is refused or unavailable.
    """
    started = time.monotonic()
    endpoint, host = _endpoint_facts(url)
    _record_update_diagnostic(
        'network', 'http_open', endpoint, host, 'started', started,
        source='self_update._open',
    )
    if not _trusted_url(url):
        error = UpdateError("Untrusted update address.")
        _record_update_diagnostic(
            'network', 'http_open', endpoint, host, 'failed', started,
            category='security_refusal', error=error,
            source='self_update._open',
        )
        raise error
    request = urllib.request.Request(url, headers={
        "Accept": "application/vnd.github+json" if url.startswith(API_URL) else "application/octet-stream",
        "User-Agent": "PHPGalleryUploader-self-update",
    })
    try:
        response = (opener or urllib.request.build_opener(_Redirects())).open(request, timeout=TIMEOUT)
        final_url = response.geturl()
        if not _trusted_url(final_url):
            response.close()
            raise UpdateError("Untrusted final update address.")
        final_endpoint, final_host = _endpoint_facts(final_url)
        try:
            getcode = getattr(response, 'getcode', None)
            observed_status = getcode() if callable(getcode) else None
            status = (observed_status if type(observed_status) is int
                      and UPDATE_HTTP_STATUS_MIN <= observed_status
                      <= UPDATE_HTTP_STATUS_MAX else None)
        except Exception:
            status = None
        _record_update_diagnostic(
            'network', 'http_open', final_endpoint, final_host, 'succeeded', started,
            http_status=status, source='self_update._open',
        )
        return response
    except UpdateError as exc:
        _record_update_diagnostic(
            'network', 'http_open', endpoint, host, 'failed', started,
            category='security_refusal', error=exc,
            source='self_update._open',
        )
        raise
    except Exception as exc:
        _record_update_diagnostic(
            'network', 'http_open', endpoint, host, 'failed', started,
            category='transport_failure', error=exc,
            source='self_update._open',
        )
        raise UpdateError("Could not contact GitHub for the uploader update.") from exc


def _json(
    url: str, opener: _UpdateOpener | None, limit: int = MAX_JSON_SIZE,
) -> JsonValue:
    """Read bounded response bytes and decode JSON with safe diagnostics.

    Args:
        url (str): Trusted API or manifest URL validated by the request owner.
        opener (_UpdateOpener | None): Optional request opener implementing
            ``open(urllib.request.Request, timeout)``; None selects urllib's
            default opener and the existing trusted redirect handler.
        limit (int): Maximum response bytes accepted before decoding.

    Returns:
        JsonValue: JSON null, bool, int, float or str; a list containing further
            JSON values; or a dict mapping string keys to further JSON values.

    Raises:
        UpdateError: Response is oversized, unreadable or invalid JSON.
    """
    started = time.monotonic()
    endpoint, host = _endpoint_facts(url)
    _record_update_diagnostic(
        'discovery', 'metadata_read', endpoint, host, 'started', started,
        source='self_update._json',
    )
    status: int | None = None
    data = b''
    try:
        with _open(url, opener) as response:
            try:
                getcode = getattr(response, 'getcode', None)
                observed_status = getcode() if callable(getcode) else None
                if (type(observed_status) is int
                        and UPDATE_HTTP_STATUS_MIN <= observed_status
                        <= UPDATE_HTTP_STATUS_MAX):
                    status = observed_status
            except Exception:
                status = None
            data = response.read(limit + 1)
        if len(data) > limit:
            error = UpdateError("GitHub update metadata exceeds the size limit.")
            _record_update_diagnostic(
                'discovery', 'metadata_read', endpoint, host, 'failed', started,
                bytes_received=len(data), http_status=status,
                category='response_size_limit', error=error,
                source='self_update._json',
            )
            raise error
        decoded = json.loads(data)
        _record_update_diagnostic(
            'discovery', 'metadata_read', endpoint, host, 'succeeded', started,
            bytes_received=len(data), http_status=status,
            source='self_update._json',
        )
        return decoded
    except UpdateError as exc:
        _record_update_diagnostic(
            'discovery', 'metadata_read', endpoint, host, 'failed', started,
            bytes_received=len(data), http_status=status, error=exc,
            source='self_update._json',
        )
        raise
    except Exception as exc:
        category = ('invalid_json' if isinstance(exc, (json.JSONDecodeError, UnicodeError))
                    else None)
        _record_update_diagnostic(
            'discovery', 'metadata_read', endpoint, host, 'failed', started,
            bytes_received=len(data), http_status=status,
            category=category or 'transport_failure',
            error=exc, source='self_update._json',
        )
        raise UpdateError("GitHub returned unreadable update metadata.") from exc


def _digest(asset: dict[str, JsonValue], release: dict[str, JsonValue], version: str,
            opener: _UpdateOpener | None) -> str:
    """Resolve an asset digest from release metadata or its trusted manifest.

    Args:
        asset (dict[str, JsonValue]): One release asset record with ``id`` as
            int, ``name`` and ``browser_download_url`` as str, ``size`` as int,
            and optional ``digest`` as str.
        release (dict[str, JsonValue]): Containing record with ``id`` as int,
            ``draft`` and ``prerelease`` as bool, and ``assets`` as a list of
            asset records having the fields documented above.
        version (str): Strict version parsed from the installer filename.
        opener (_UpdateOpener | None): Existing update HTTP owner for manifests.

    Returns:
        str: Lowercase SHA256 digest for this exact installer asset.

    Raises:
        UpdateError: No digest matches the exact release asset and version.
    """
    digest = asset.get("digest")
    if digest is not None:
        if not isinstance(digest, str) or not digest.startswith("sha256:") or not _HASH.fullmatch(digest[7:]):
            raise UpdateError("The newer installer has an invalid SHA256 digest.")
        return digest[7:].lower()
    manifests = [item for item in release["assets"]
                 if isinstance(item, dict) and item.get("name") == "winapp-update.json"]
    if len(manifests) == 1:
        manifest = manifests[0]
        _positive(manifest.get("size"), 64 * 1024)
        url = _asset_url(manifest.get("browser_download_url"), "winapp-update.json")
        if url.rsplit("/", 1)[0] != asset["browser_download_url"].rsplit("/", 1)[0]:
            raise UpdateError("Update manifest does not belong to the installer's release.")
        document = _json(url, opener, 64 * 1024)
        # Same-release manifest schema: {"assets": [{"name", "version", "size", "sha256"}]}.
        if isinstance(document, dict) and isinstance(document.get("assets"), list):
            entries = [entry for entry in document["assets"] if isinstance(entry, dict)
                       and entry.get("name") == asset["name"]]
            if len(entries) == 1:
                entry = entries[0]
                expected = entry.get("sha256")
                if (entry.get("version") == version and type(entry.get("size")) is int
                        and entry["size"] == asset["size"] and isinstance(expected, str)
                        and _HASH.fullmatch(expected)):
                    return expected.lower()
    raise UpdateError("A newer uploader exists, but no trustworthy SHA256 is published. Update refused.")


def find_update(
    current_version: str, *, opener: _UpdateOpener | None = None,
) -> Optional[ReleaseAsset]:
    """Find the highest stable installer across paginated public releases.

    The installer filename supplies the version; discovery does not trust a
    CMS version or a repository ``latest`` value. It uses no token or account
    setting. The search is limited to ``MAX_PAGES`` pages and
    ``DISCOVERY_BUDGET_SECONDS`` overall, with each in-flight request bounded
    by ``TIMEOUT`` seconds.

    Args:
        current_version (str): Strict semantic version of this uploader.
        opener (_UpdateOpener | None): Optional deterministic request owner;
            None uses urllib's default opener with trusted redirects.

    Returns:
        Optional[ReleaseAsset]: Highest verified stable installer, or None when
            no newer stable installer is available.

    Raises:
        UpdateError: Release metadata, redirect, digest or transport is refused.
    """
    current = _version(current_version)
    candidates: list[tuple[tuple[int, int, int], dict[str, Any], dict[str, Any], str]] = []
    deadline = time.monotonic() + DISCOVERY_BUDGET_SECONDS
    for page in range(1, MAX_PAGES + 1):
        if time.monotonic() >= deadline:
            raise UpdateError("GitHub update discovery exceeded its time budget; please retry later.")
        releases = _json(f"{API_URL}?per_page=100&page={page}", opener)
        if not isinstance(releases, list) or len(releases) > 100:
            raise UpdateError("GitHub returned an invalid release list.")
        if not releases:
            break
        for release in releases:
            if (not isinstance(release, dict) or type(release.get("draft")) is not bool
                    or type(release.get("prerelease")) is not bool):
                raise UpdateError("GitHub returned invalid release metadata.")
            if release["draft"] or release["prerelease"]:
                continue
            _positive(release.get("id"), 2**63 - 1)
            assets = release.get("assets")
            if not isinstance(assets, list) or len(assets) > 1000:
                raise UpdateError("GitHub returned invalid release assets.")
            for asset in assets:
                if not isinstance(asset, dict) or not isinstance(asset.get("name"), str):
                    raise UpdateError("GitHub returned invalid asset metadata.")
                match = _NAME.fullmatch(asset["name"])
                if not match:
                    continue
                version = match.group(1)
                numeric = _version(version)
                _positive(asset.get("id"), 2**63 - 1)
                _positive(asset.get("size"), MAX_INSTALLER_SIZE)
                _asset_url(asset.get("browser_download_url"), asset["name"])
                if numeric > current:
                    candidates.append((numeric, asset, release, version))
        if len(releases) < 100:
            break
    else:
        raise UpdateError("GitHub release pagination exceeded the safety limit.")
    if not candidates:
        return None
    highest = max(item[0] for item in candidates)
    winners = [item for item in candidates if item[0] == highest]
    # CMS releases may reattach identical uploader bytes under distinct asset IDs.
    # Resolve every highest-version digest before accepting this duplication.
    resolved = []
    for _, candidate, release, version in winners:
        if time.monotonic() >= deadline:
            raise UpdateError("GitHub update discovery exceeded its time budget; please retry later.")
        resolved.append((candidate, release, version, _digest(candidate, release, version, opener)))
    if len({(item[0]["size"], item[3]) for item in resolved}) != 1:
        raise UpdateError("Published installers with the same uploader version contain different bytes.")
    asset, release, version, digest = max(resolved, key=lambda item: (item[1]["id"], item[0]["id"]))
    return ReleaseAsset(version, asset["name"], asset["browser_download_url"],
                        asset["size"], digest,
                        asset["id"], release["id"])


def _validate(asset: ReleaseAsset) -> None:
    """Validate metadata again at every public disk/launch boundary."""
    if not isinstance(asset, ReleaseAsset):
        raise UpdateError("Invalid installer metadata.")
    _version(asset.version)
    if asset.name != f"PHPGalleryUploader-{asset.version}-Setup.exe":
        raise UpdateError("Installer name does not match its version.")
    _asset_url(asset.download_url, asset.name)
    _positive(asset.size, MAX_INSTALLER_SIZE)
    _positive(asset.asset_id, 2**63 - 1)
    _positive(asset.release_id, 2**63 - 1)
    if not isinstance(asset.sha256, str) or not _HASH.fullmatch(asset.sha256):
        raise UpdateError("No trustworthy SHA256 is available for this installer.")



def validated_release_asset(payload: object, current_version: str) -> ReleaseAsset:
    """Validate persisted metadata and require a strictly newer uploader version."""
    if not isinstance(payload, dict):
        raise UpdateError("Invalid cached update metadata.")
    try:
        asset = ReleaseAsset(**payload)
    except TypeError as exc:
        raise UpdateError("Invalid cached update metadata.") from exc
    _validate(asset)
    if _version(asset.version) <= _version(current_version):
        raise UpdateError("Cached update is not newer than this application.")
    return asset


_DOWNLOAD_SESSION = re.compile(r"download-[a-f0-9]{32}\Z")
_DOWNLOAD_FILE = re.compile(r"uploader-update-[a-zA-Z0-9_-]{8}\.(?:part|exe)\Z")
_DOWNLOAD_MARKER = "ownership.json"


def _safe_download_path(path: Path) -> Path:
    """Reject every reparse ancestor before resolving a storage path."""
    from uploader.update_helper import reject_reparse_path
    path = path.absolute()
    for candidate in (path, *path.parents):
        if candidate.is_symlink():
            raise UpdateError("Download storage must not contain symlinks.")
    try:
        reject_reparse_path(path)
        return path.resolve()
    except (OSError, ValueError) as exc:
        raise UpdateError("Download storage must not contain reparse points.") from exc


def _download_ownership(path: Path, updates_directory: Path) -> dict[str, object]:
    """Read a bounded marker only for an immediate child of the fixed owner root."""
    root = _safe_download_path(updates_directory)
    session = _safe_download_path(path)
    if session.parent != root or not _DOWNLOAD_SESSION.fullmatch(session.name):
        raise UpdateError("Unknown download session path.")
    marker = _safe_download_path(session / _DOWNLOAD_MARKER)
    try:
        with marker.open("rb") as stream:
            data = stream.read(4097)
        if len(data) > 4096:
            raise UpdateError("Download ownership marker exceeds its limit.")
        payload = json.loads(data)
    except (OSError, ValueError) as exc:
        raise UpdateError("Unknown download session ownership.") from exc
    if (not isinstance(payload, dict) or set(payload) != {"schema", "session", "created", "files"}
            or type(payload["schema"]) is not int or payload["schema"] != 1 or payload["session"] != session.name
            or type(payload["created"]) not in (int, float) or not math.isfinite(payload["created"])
            or not isinstance(payload["files"], list) or len(payload["files"]) > 4
            or any(not isinstance(name, str) or not _DOWNLOAD_FILE.fullmatch(name) for name in payload["files"])
            or len(set(payload["files"])) != len(payload["files"])):
        raise UpdateError("Unknown download session ownership.")
    return payload


def create_download_session(updates_directory: Path) -> Path:
    """Create an exact named, marked session under the fixed safe update root."""
    root = _safe_download_path(updates_directory)
    root.mkdir(parents=True, exist_ok=True)
    session = root / ("download-" + uuid.uuid4().hex)
    session.mkdir()
    (session / _DOWNLOAD_MARKER).write_text(json.dumps({"schema": 1, "session": session.name,
        "created": time.time(), "files": []}), encoding="utf-8")
    return session


def _record_download_file(directory: Path, name: str) -> None:
    """Record only this call's created names when using a managed download session."""
    if not _DOWNLOAD_SESSION.fullmatch(directory.name):
        return
    payload = _download_ownership(directory, directory.parent)
    files = payload["files"]
    if not isinstance(files, list) or not _DOWNLOAD_FILE.fullmatch(name):
        raise UpdateError("Invalid generated download file name.")
    if name not in files:
        if len(files) >= 4:
            raise UpdateError("Download session generated-file limit exceeded.")
        files.append(name)
    marker = _safe_download_path(directory / _DOWNLOAD_MARKER)
    marker.write_text(json.dumps(payload), encoding="utf-8")


def retire_download_session(path: Path, updates_directory: Path) -> None:
    """Delete only marked generated files; refuse unknown contents before any deletion."""
    payload = _download_ownership(path, updates_directory)
    session = _safe_download_path(path)
    allowed = {_DOWNLOAD_MARKER, *payload["files"]}
    entries = list(session.iterdir())
    if any(entry.name not in allowed for entry in entries):
        raise UpdateError("Download session contains unknown files; cleanup refused.")
    for entry in entries:
        _safe_download_path(entry)
        if not entry.is_file():
            raise UpdateError("Download session contains an unexpected file type.")
    for entry in entries:
        if entry.name != _DOWNLOAD_MARKER:
            entry.unlink()
    (session / _DOWNLOAD_MARKER).unlink()
    session.rmdir()


def cleanup_old_download_sessions(updates_directory: Path, now: float) -> None:
    """Retire only complete owned sessions older than one week, preserving unknown data."""
    root = _safe_download_path(updates_directory)
    if not root.exists():
        return
    for session in root.iterdir():
        if not _DOWNLOAD_SESSION.fullmatch(session.name):
            continue
        try:
            payload = _download_ownership(session, root)
            if now - float(payload["created"]) > 7 * 86400:
                retire_download_session(session, root)
        except (OSError, UpdateError):
            continue

def verify_installer(path: Path, asset: ReleaseAsset) -> None:
    """Recheck installer size and digest without logging its local path.

    Args:
        path (Path): Session-owned downloaded installer.
        asset (ReleaseAsset): Validated metadata with expected size and digest.

    Returns:
        None: The complete installer matches its published metadata.

    Raises:
        UpdateError: Installer metadata, size, path or digest is invalid.
    """
    started = time.monotonic()
    _record_update_diagnostic(
        'verification', 'installer_verify', 'local_installer', 'local',
        'started', started, source='self_update.verify_installer',
    )
    total = 0
    try:
        _validate(asset)
        if path.is_symlink() or not path.is_file() or path.stat().st_size != asset.size:
            raise UpdateError("Installer size does not match the published update.")
        digest = hashlib.sha256()
        with path.open("rb") as stream:
            while block := stream.read(128 * 1024):
                total += len(block)
                if total > asset.size:
                    raise UpdateError("Installer exceeds the expected size.")
                digest.update(block)
        if total != asset.size or digest.hexdigest() != asset.sha256.lower():
            raise UpdateError("Installer SHA256 verification failed. Update refused.")
    except UpdateError as exc:
        _record_update_diagnostic(
            'verification', 'installer_verify', 'local_installer', 'local',
            'failed', started, bytes_received=total,
            category='integrity_refusal', error=exc,
            source='self_update.verify_installer',
        )
        raise
    except OSError as exc:
        _record_update_diagnostic(
            'verification', 'installer_verify', 'local_installer', 'local',
            'failed', started, bytes_received=total, error=exc,
            source='self_update.verify_installer',
        )
        raise UpdateError("Could not read the downloaded installer.") from exc
    _record_update_diagnostic(
        'verification', 'installer_verify', 'local_installer', 'local',
        'succeeded', started, bytes_received=total,
        source='self_update.verify_installer',
    )


def download_update(asset: ReleaseAsset, directory: Path,
                    progress: Callable[[int, int], None], cancel: threading.Event,
                    *, opener: _UpdateOpener | None = None) -> Path:
    """Stream to a unique owned partial file, verify, then atomically finalize.

    The returned unique filename is session-owned. Failures delete only the
    partial file created by this call, preserving every preexisting file.

    Args:
        asset (ReleaseAsset): Metadata validated against the public release.
        directory (Path): Private update session owned by this application.
        progress (Callable[[int, int], None]): Worker-safe actual byte progress.
        cancel (threading.Event): Cancellation requested by the update dialog.
        opener (_UpdateOpener | None): Optional request opener implementing
            ``open(urllib.request.Request, timeout)``; None selects urllib's
            default opener and the existing trusted redirect handler.

    Returns:
        Path: Unique verified installer in the selected session directory.

    Raises:
        UpdateCancelled: User requested cancellation before finalization.
        UpdateError: Transport, storage, size or digest validation failed.
    """
    started = time.monotonic()
    total = 0
    primary_failed = False
    asset_url = (asset.download_url
                 if isinstance(asset, ReleaseAsset) else '')
    endpoint, host = _endpoint_facts(asset_url)
    _record_update_diagnostic(
        'download', 'installer_download', endpoint, host,
        'started', started, source='self_update.download_update',
    )
    partial: Optional[Path] = None
    try:
        _validate(asset)
        if cancel.is_set():
            raise UpdateCancelled("Update download cancelled.")
        directory.mkdir(parents=True, exist_ok=True)
        descriptor, name = tempfile.mkstemp(prefix="uploader-update-", suffix=".part", dir=directory)
        partial = Path(name)
        try:
            _record_download_file(directory, partial.name)
        except Exception:
            os.close(descriptor)
            raise
        digest = hashlib.sha256()
        with os.fdopen(descriptor, "wb") as stream, _open(asset.download_url, opener) as response:
            endpoint, host = _endpoint_facts(response.geturl())
            progress(0, asset.size)
            while True:
                if cancel.is_set():
                    raise UpdateCancelled("Update download cancelled.")
                block = response.read(128 * 1024)
                if not block:
                    break
                total += len(block)
                if total > asset.size:
                    raise UpdateError("Installer download exceeds its published size.")
                stream.write(block)
                digest.update(block)
                progress(total, asset.size)
            stream.flush()
            os.fsync(stream.fileno())
        if cancel.is_set():
            raise UpdateCancelled("Update download cancelled.")
        if total != asset.size or digest.hexdigest() != asset.sha256.lower():
            raise UpdateError("Installer download failed size or SHA256 verification.")
        final = partial.with_suffix(".exe")
        # The random basename belongs to this session. Refuse an improbable
        # collision rather than overwriting any unrelated installer.
        if final.exists():
            raise UpdateError("Update download destination already exists.")
        _record_download_file(directory, final.name)
        partial.rename(final)
        partial = None
        _record_update_diagnostic(
            'download', 'installer_download', endpoint, host,
            'succeeded', started, bytes_received=total,
            source='self_update.download_update',
        )
        return final
    except UpdateCancelled as exc:
        primary_failed = True
        _record_update_diagnostic(
            'download', 'installer_download', endpoint, host,
            'cancelled', started, bytes_received=total,
            category='cancelled', error=exc,
            source='self_update.download_update',
        )
        raise
    except UpdateError as exc:
        primary_failed = True
        _record_update_diagnostic(
            'download', 'installer_download', endpoint, host,
            'failed', started, bytes_received=total,
            category='integrity_or_storage_refusal', error=exc,
            source='self_update.download_update',
        )
        raise
    except Exception as exc:
        primary_failed = True
        _record_update_diagnostic(
            'download', 'installer_download', endpoint, host,
            'failed', started, bytes_received=total,
            category='transport_failure', error=exc,
            source='self_update.download_update',
        )
        raise UpdateError("Could not download the uploader installer.") from exc
    finally:
        if partial is not None:
            cleanup_started = time.monotonic()
            try:
                partial.unlink(missing_ok=True)
            except OSError as exc:
                _record_update_diagnostic(
                    'cleanup', 'partial_remove', 'local_installer', 'local',
                    'failed', cleanup_started, category='cleanup_refused',
                    error=exc,
                    source='self_update.download_update',
                )
                if not primary_failed:
                    raise
            else:
                _record_update_diagnostic(
                    'cleanup', 'partial_remove', 'local_installer', 'local',
                    'succeeded', cleanup_started,
                    source='self_update.download_update',
                )
