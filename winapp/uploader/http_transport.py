# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/http_transport.py
# Module Type: Windows Client Transport
# Purpose: Own Gallery endpoint selection and credential-bearing HTTP requests.
# Responsibilities:
#   - Normalize Gallery roots and select bounded upload route candidates.
#   - Enforce safe redirect handling for requests carrying a Gallery API key.
#   - Parse JSON responses and stream authenticated binary downloads.
#   - Revoke an upload key through the same authenticated request path.
#   - Stream multipart uploads and reconcile passive inventory observations.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own Gallery endpoint selection and authenticated HTTP transport."""

import email.message
import http.client
import hashlib
import json
import logging
import mimetypes
from pathlib import Path
import socket
import threading
import time
import uuid
from dataclasses import dataclass
from typing import Any, Callable, Dict, IO, List, Optional, Set, Tuple
from urllib import error, parse, request

from .linking import validated_retry_after
from .linked_upload import LinkedUploadTarget
from .localization import gettext_noop
from .mode_coordinator import ModeCoordinatorError
from .models import JsonValue

# Purpose: Set the default timeout for Gallery HTTP metadata and upload calls.
# Type: int. Units: seconds. Scope: one request without an explicit timeout.
# Consumers: post_json_resolved, post_json, post_binary_to_file.
# Rationale: Preserve the Watcher request timeout used before transport ownership
# moved here; callers with bounded work may supply a shorter explicit timeout.
DEFAULT_TIMEOUT_SECONDS = 180

# Purpose: Bound linked-gallery preflight descriptors before JSON encoding.
# Type: int. Units: file descriptors. Scope: one linked preflight request.
# Consumers: ManualUploadThread.preload_remote_inventory,
# ImportPreflightThread.run. Rationale: 500 leaves room within the two-MiB
# request cap for long escaped filenames and their SHA-256 descriptors.
LINKED_INVENTORY_BATCH_SIZE = 500

# Purpose: Set the minimum interval between passive inventory refreshes.
# Type: float. Units: seconds. Scope: one process-local inventory session.
# Consumers: WatcherConfig and RemoteInventorySession.
# Rationale: Preserve the 30-second refresh cadence while allowing explicit
# refreshes after uncertain upload outcomes.
DEFAULT_INVENTORY_REFRESH_SECONDS = 30.0

def upload_endpoint_candidates(value: str) -> List[str]:
    """
    Resolve a user-supplied Gallery URL into ordered upload endpoint candidates.

    The query-string front-controller endpoint is always preferred, even when a
    user pastes the optional /api/upload alias. Real shared-hosting deployments
    can route clean URLs for ordinary GET pages while proxies/WAF rules still
    treat POST requests under /api/ differently. The query-string endpoint is
    therefore the portable canonical form. The clean route remains a bounded
    fallback only when the canonical route is rejected by the hosting layer with
    a non-JSON HTTP 404 before PHP Gallery is reached.

    Args:
        value (str): User-provided Gallery root or upload endpoint URL.

    Returns:
        List[str]: Ordered unique HTTP(S) endpoint candidates.
    """
    raw = value.strip()
    if not raw:
        return []

    parsed = parse.urlparse(raw)
    if not parsed.scheme:
        raw = "https://" + raw
        parsed = parse.urlparse(raw)
    if parsed.scheme.lower() not in {"http", "https"} or not parsed.netloc:
        return []

    parsed = parsed._replace(fragment="")
    path = parsed.path or ""
    stripped_path = path.rstrip("/")
    query = parse.parse_qs(parsed.query)
    page = query.get("page", [""])[0]

    if stripped_path.endswith("/api/upload"):
        base_path = stripped_path[:-len("/api/upload")]
    elif stripped_path.endswith("/index.php") or stripped_path == "/index.php":
        base_path = stripped_path[:-len("/index.php")] if stripped_path != "/index.php" else ""
    else:
        base_path = stripped_path

    base_path = base_path.rstrip("/")
    clean_path = (base_path + "/api/upload") if base_path else "/api/upload"
    query_path = (base_path + "/index.php") if base_path else "/index.php"

    clean_url = parse.urlunparse(parsed._replace(path=clean_path, query="", fragment=""))
    query_url = parse.urlunparse(parsed._replace(path=query_path, query="page=upload_automation_upload", fragment=""))

    # Preserve an already-canonical URL exactly apart from the discarded fragment.
    if (stripped_path.endswith("/index.php") or stripped_path == "/index.php") and page == "upload_automation_upload":
        query_url = parse.urlunparse(parsed)

    ordered = [query_url, clean_url]
    unique: List[str] = []
    seen: Set[str] = set()
    for candidate in ordered:
        if candidate not in seen:
            seen.add(candidate)
            unique.append(candidate)
    return unique


def normalize_upload_url(value: str) -> str:
    """
    Convert a site root or endpoint-like value into the preferred upload URL.

    All accepted inputs, including the optional /api/upload alias, normalize to
    the portable query-string front-controller endpoint. The clean route remains
    available only as a routing-level 404 fallback through
    upload_endpoint_candidates().

    Args:
        value (str): User-provided site URL or upload endpoint.

    Returns:
        str: Preferred upload endpoint URL, or an empty string for invalid
            input.
    """
    candidates = upload_endpoint_candidates(value)
    return candidates[0] if candidates else ""


# Purpose: Bound the existing ambiguous gateway revocation retry delay.
# Type: float. Units: seconds. Scope: one captured gallery-key revoke request.
# Consumers: revoke_upload_key. Rationale: 2 seconds spaces its sole retry
# without extending retry count or changing the server reconciliation policy.
KEY_REVOCATION_RETRY_DELAY_SECONDS = 2.0


def revoke_upload_key(
    upload_url: str, api_key: str, *,
    before_request: Optional[Callable[[], None]] = None,
) -> Dict[str, JsonValue]:
    """
    Revoke the active upload automation key through the authenticated API.

    Revocation uses the same JSON request path and endpoint fallback rules as
    inventory and other metadata commands. The server identifies the exact token
    from X-Gallery-API-Key, so no admin session or token database id is required.

    A gateway 502/503/504 is treated as ambiguous because the reverse proxy may
    have lost the PHP response after the token was already revoked. One delayed
    retry is allowed. If that retry reports the key as invalid/revoked, the desired
    server state has been reached and the operation is considered successful. The
    retry is intentionally bounded to avoid amplifying an overloaded server.

    Args:
        upload_url (str): PHP Gallery upload endpoint or Gallery root URL.
        api_key (str): Current gallery-scoped API key.
        before_request (Optional[Callable[[], None]]): Captured work checkpoint
            before every route/redirect/retry attempt, or None for direct calls.

    Returns:
        Dict[str, JsonValue]: Positive ok/action=revoke response, optional message
            string and reconciled_after_gateway_error boolean.

    Raises:
        RuntimeError: Raised for network, HTTP, non-JSON, or server failures.
    """
    options = {'before_request': before_request} if before_request is not None else {}
    try:
        return post_json(upload_url, api_key, {"action": "revoke"}, **options)
    except RuntimeError as exc:
        first_message = str(exc)
        if not any(marker in first_message for marker in ("HTTP 502:", "HTTP 503:", "HTTP 504:")):
            raise
        logging.warning("API-key revocation received a transient gateway error; retrying once after a short delay.")
        time.sleep(KEY_REVOCATION_RETRY_DELAY_SECONDS)
        try:
            return post_json(upload_url, api_key, {"action": "revoke"}, **options)
        except RuntimeError as retry_exc:
            retry_message = str(retry_exc).lower()
            if "invalid or revoked api key" in retry_message:
                return {
                    "ok": True,
                    "action": "revoke",
                    "message": "API key is revoked.",
                    "reconciled_after_gateway_error": True,
                }
            raise RuntimeError(
                "Gallery server is temporarily unavailable while revoking the API key. "
                "The key was kept locally because server revocation could not be confirmed. "
                f"Original error: {first_message}; retry error: {retry_exc}"
            ) from retry_exc


class GalleryKeyRedirectError(RuntimeError):
    """Refuse a redirect that could disclose the legacy gallery API key."""


class LegacyUploadResponseError(RuntimeError):
    """Carry bounded legacy HTTP metadata without retaining a response body."""

    def __init__(
        self, status: int | None, retry_after_seconds: int | None = None,
        *, definitely_rejected: bool = False, request_started: bool = True,
    ) -> None:
        """Store the status and a validated retry hint for the queue owner.

        Args:
            status (int | None): Observed HTTP status, or None for transport failure.
            retry_after_seconds (int | None): Validated server delay in seconds.
            definitely_rejected (bool): Exact known refusal before legacy storage.
            request_started (bool): Whether HTTP might have begun; False only
                for a verified local DNS or connection-refused failure.

        Returns:
            None: Exposes no credentials, server paths or arbitrary response text.
        """
        self.status = status
        self.retry_after_seconds = retry_after_seconds
        self.definitely_rejected = definitely_rejected
        self.request_started = request_started
        self.operation_finished = None
        super().__init__(f'Legacy upload API failure; http={status}.')


class UploadPreparationError(RuntimeError):
    """Attest that a local write-ahead callback failed before legacy HTTP began."""

    request_started = False
    operation_finished = None


class _GalleryKeyRedirectHandler(request.HTTPRedirectHandler):
    """Keep gallery credentials on the same host and a safe transport."""

    def __init__(self, checkpoint: Optional[Callable[[], None]] = None,
                 allow_redirects: bool = True) -> None:
        """Retain the optional request checkpoint for redirect attempts.

        Args:
            checkpoint (Optional[Callable[[], None]]): Revalidates admitted
                once-mode work or a manual batch, or None for other callers.
            allow_redirects (bool): Whether safe redirects may be followed.

        Returns:
            None: Initializes the redirect handler.
        """
        super().__init__()
        self._checkpoint = checkpoint
        self._allow_redirects = allow_redirects

    def redirect_request(
        self, req: request.Request, fp: IO[bytes], code: int, msg: str,
        headers: email.message.Message, newurl: str,
    ) -> Optional[request.Request]:
        """Restrict the destination and recheck any admitted once-mode work.

        Args:
            req (request.Request): Original urllib request.
            fp (IO[bytes]): Original HTTP response stream.
            code (int): HTTP redirect status.
            msg (str): HTTP redirect reason.
            headers (email.message.Message): Response headers.
            newurl (str): Destination selected by urllib.

        Returns:
            Optional[request.Request]: Redirect request, or None when urllib
                declines to follow it.

        Raises:
            ModeCoordinatorError: The admitted evidence is no longer valid.
            GalleryKeyRedirectError: The redirect changes host, weakens TLS,
                changes an unapproved port, or has ambiguous URL authority.
        """
        if not self._allow_redirects:
            raise GalleryKeyRedirectError("Gallery upload redirect was refused.")
        try:
            source = parse.urlsplit(req.full_url)
            target = parse.urlsplit(newurl)
            source_scheme, target_scheme = source.scheme.lower(), target.scheme.lower()
            source_port = source.port if source.port is not None else (
                80 if source_scheme == 'http' else 443
            )
            target_port = target.port if target.port is not None else (
                80 if target_scheme == 'http' else 443
            )
            safe = (
                source_scheme in ('http', 'https')
                and target_scheme in ('http', 'https')
                and source.hostname is not None and source.hostname == target.hostname
                and source.username is None and source.password is None
                and target.username is None and target.password is None
                and not (source_scheme == 'https' and target_scheme != 'https')
                and (source_port == target_port or (
                    source_scheme == 'http' and target_scheme == 'https'
                    and source_port == 80 and target_port == 443
                ))
            )
        except ValueError:
            safe = False
        if not safe:
            raise GalleryKeyRedirectError('Gallery API redirect was refused.')
        if self._checkpoint is not None:
            self._checkpoint()
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def _gallery_key_open(
    http_request: request.Request, timeout: float,
    before_request: Optional[Callable[[], None]] = None,
    allow_redirects: bool = True,
) -> http.client.HTTPResponse:
    """Open one legacy gallery request with guarded credential redirects.

    Args:
        http_request (request.Request): Existing request carrying a gallery key.
        timeout (float): Network socket timeout in seconds.
        before_request (Optional[Callable[[], None]]): Once-mode or manual
            batch checkpoint, or None for other callers.
        allow_redirects (bool): Whether safe redirects may be followed.

    Returns:
        http.client.HTTPResponse: Successful response owned by the caller.

    Raises:
        GalleryKeyRedirectError: An unsafe redirect is refused.
        ModeCoordinatorError: Once-mode evidence cannot authorize an attempt.
        OSError: The HTTP transport could not complete.
    """
    opener = request.build_opener(
        _GalleryKeyRedirectHandler(before_request, allow_redirects),
    )
    if before_request is not None:
        before_request()
    return opener.open(http_request, timeout=timeout)


def post_json_resolved(
    upload_url: str,
    api_key: str,
    payload: Dict[str, Any],
    timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
    before_request: Optional[Callable[[], None]] = None,
) -> Tuple[Dict[str, Any], str]:
    """
    Submit one JSON API request and report the endpoint that actually succeeded.

    Only a non-JSON HTTP 404 is treated as a front-controller routing failure and
    retried against the alternate clean/query-string endpoint. Gallery-generated
    JSON errors, including 404 gallery-not-found and 401/403 authentication
    failures, are authoritative and are never hidden behind fallback retries.

    Args:
        upload_url (str): PHP Gallery root or upload endpoint.
        api_key (str): Gallery-scoped API key sent as X-Gallery-API-Key.
        payload (Dict[str, Any]): Request object with action and its JSON
            scalar, object or array fields for the selected automation action.
        timeout_seconds (float): Network timeout for this metadata request.
        before_request (Optional[Callable[[], None]]): Once-mode or manual
            batch checkpoint before each endpoint and redirect, or None.

    Returns:
        Tuple[Dict[str, Any], str]: Response object containing ok and
            action-specific JSON fields, and the successful endpoint URL.

    Raises:
        RuntimeError: Raised for network, HTTP, non-JSON, or gallery errors.
        ModeCoordinatorError: The once-mode evidence changed before an attempt.
        GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
    """
    body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    candidates = upload_endpoint_candidates(upload_url)
    if not candidates:
        raise RuntimeError("Gallery URL or upload endpoint is invalid.")

    for index, endpoint in enumerate(candidates):
        http_request = request.Request(
            endpoint,
            data=body,
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "Content-Length": str(len(body)),
                "X-Gallery-API-Key": api_key,
                "Accept": "application/json",
                "User-Agent": "PHPGalleryUploader/1.4",
            },
            method="POST",
        )

        try:
            request_options = (
                {'before_request': before_request} if before_request is not None else {}
            )
            with _gallery_key_open(
                http_request, max(5.0, float(timeout_seconds)), **request_options,
            ) as response:
                response_body = response.read().decode("utf-8", errors="replace")
        except error.HTTPError as exc:
            response_body = exc.read().decode("utf-8", errors="replace")
            try:
                error_payload = json.loads(response_body)
                message = error_payload.get("error") if isinstance(error_payload, dict) else None
                json_error = isinstance(error_payload, dict)
            except json.JSONDecodeError:
                message = None
                json_error = False

            has_fallback = index + 1 < len(candidates)
            if exc.code == 404 and not json_error and has_fallback:
                logging.warning("Upload endpoint route returned HTTP 404; retrying alternate Gallery API route.")
                continue
            raise LegacyUploadResponseError(
                exc.code, validated_retry_after(
                    exc.headers.get_all('Retry-After', [])
                    if hasattr(exc.headers, 'get_all') else [],
                ),
            ) from None
        except error.URLError as exc:
            raise LegacyUploadResponseError(None) from None

        try:
            response_payload = json.loads(response_body)
        except json.JSONDecodeError as exc:
            raise RuntimeError(f"Server returned non-JSON response: {response_body[:300]}") from exc
        if not isinstance(response_payload, dict):
            raise RuntimeError("Server returned an invalid JSON response.")
        if not response_payload.get("ok"):
            raise RuntimeError(str(response_payload.get("error") or "API request failed."))
        return response_payload, endpoint

    raise RuntimeError("No usable PHP Gallery upload endpoint was found.")


def post_json(
    upload_url: str, api_key: str, payload: Dict[str, Any],
    timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
    before_request: Optional[Callable[[], None]] = None,
) -> Dict[str, Any]:
    """
    Submit one JSON API request and parse the JSON response.

    The companion app uses JSON only for metadata handshakes. Image bytes still
    use multipart/form-data because PHP Gallery routes them through the normal
    upload pipeline. Routing-only HTTP 404 responses may use the alternate
    clean/query-string endpoint, while authenticated gallery errors remain final.

    Args:
        upload_url (str): PHP Gallery root or upload endpoint.
        api_key (str): Gallery-scoped API key sent as X-Gallery-API-Key.
        payload (Dict[str, Any]): Action and its JSON scalar, object or array
            fields for the selected automation request.
        timeout_seconds (float): Network timeout for this metadata request.
        before_request (Optional[Callable[[], None]]): Once-mode or manual
            batch checkpoint before each endpoint and redirect, or None.

    Returns:
        Dict[str, Any]: Response object with ok and action-specific JSON fields.

    Raises:
        RuntimeError: Raised for network, HTTP, non-JSON, or gallery errors.
        ModeCoordinatorError: The once-mode evidence changed before an attempt.
        GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
    """
    if before_request is None:
        response_payload, _resolved_url = post_json_resolved(upload_url, api_key, payload, timeout_seconds)
    else:
        response_payload, _resolved_url = post_json_resolved(
            upload_url, api_key, payload, timeout_seconds, before_request,
        )
    return response_payload

def post_binary_to_file(
    upload_url: str,
    api_key: str,
    fields: Dict[str, str],
    target_path: Path,
    timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
    before_request: Optional[Callable[[], None]] = None,
) -> Tuple[str, int]:
    """
    Submit one form-encoded request and stream the binary response to a file.

    AI-analysis workers use this helper to download only the server-assigned
    image asset for a claimed job. The API key and claim token remain required;
    the public gallery URL is not used, so private or unpublished images do not
    need to be exposed to anonymous visitors for processing.

    Args:
        upload_url (str): Normalized PHP Gallery upload endpoint.
        api_key (str): Gallery-scoped API key sent as X-Gallery-API-Key.
        fields (Dict[str, str]): Form fields accepted by the upload automation
            endpoint.
        target_path (Path): Local path where the downloaded asset should be
            stored.
        timeout_seconds (float): Network timeout for the download request.
        before_request (Optional[Callable[[], None]]): Optional work checkpoint
            before each endpoint and redirect attempt.

    Returns:
        Tuple[str, int]: Tuple of response content type and downloaded byte
            count.

    Raises:
        RuntimeError: Raised for network or HTTP failures.
        GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
    """
    body = parse.urlencode(fields).encode("utf-8")
    candidates = upload_endpoint_candidates(upload_url)
    if not candidates:
        raise RuntimeError("Gallery URL or upload endpoint is invalid.")

    for index, endpoint in enumerate(candidates):
        http_request = request.Request(
            endpoint,
            data=body,
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "Content-Length": str(len(body)),
                "X-Gallery-API-Key": api_key,
                "Accept": "application/octet-stream, application/json;q=0.8",
                "User-Agent": "PHPGalleryUploader/1.4",
            },
            method="POST",
        )

        try:
            with _gallery_key_open(
                http_request, max(5.0, float(timeout_seconds)),
                **({'before_request': before_request} if before_request is not None else {}),
            ) as response:
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
                json_error = isinstance(error_payload, dict)
            except json.JSONDecodeError:
                message = None
                json_error = False
            has_fallback = index + 1 < len(candidates)
            if exc.code == 404 and not json_error and has_fallback:
                logging.warning("Upload endpoint route returned HTTP 404 during binary API request; retrying alternate Gallery API route.")
                continue
            raise RuntimeError(str(message or f"HTTP {exc.code}: {response_body[:300]}")) from exc
        except error.URLError as exc:
            raise RuntimeError(str(exc.reason)) from exc

    raise RuntimeError("No usable PHP Gallery upload endpoint was found.")


def upload_failure_needs_inventory_reconciliation(
    error_value: Exception | str,
) -> bool:
    """
    Return whether a failed upload response is ambiguous enough to justify one inventory probe.

    Gateway failures, timeouts, and dropped connections can happen after PHP has
    already committed the upload. Authentication and other explicit HTTP client
    errors are authoritative failures and must not trigger an immediate second
    API request, especially while a shared host is already under pressure.

    Args:
        error_value (Exception | str): Exception or error text from the
            multipart request; only its string message is inspected.

    Returns:
        bool: True when one bounded remote-inventory reconciliation is useful.
    """
    message = str(error_value).strip().lower()
    if not message:
        return False

    definitive_markers = (
        "http 400:",
        "http 401:",
        "http 403:",
        "http 404:",
        "http 405:",
        "http 409:",
        "http 413:",
        "http 415:",
        "http 422:",
        "invalid api key",
        "revoked api key",
        "gallery assigned to this api key no longer exists",
    )
    if any(marker in message for marker in definitive_markers):
        return False

    ambiguous_markers = (
        "http 500:",
        "http 502:",
        "http 503:",
        "http 504:",
        "timed out",
        "timeout",
        "connection reset",
        "connection aborted",
        "remote end closed",
        "incomplete response",
        "broken pipe",
        "temporarily unavailable",
    )
    return any(marker in message for marker in ambiguous_markers)


def inventory_candidate(path: Path, file_hash: Optional[str] = None, size: Optional[int] = None) -> Dict[str, Any]:
    """
    Build one file descriptor for a remote gallery inventory request.

    Args:
        path (Path): Local image path being compared with the target gallery.
        file_hash (Optional[str]): Optional precomputed SHA-256 hash. When
            omitted, the file is hashed now.
        size (Optional[int]): Optional precomputed file size. When omitted,
            stat() is used now.

    Returns:
        Dict[str, Any]: JSON-safe file descriptor accepted by PHP Gallery.

    Raises:
        OSError: Propagated when the file cannot be read or statted.
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
    answer after the configured reconnect interval or after an ambiguous upload
    failure. This is deliberately API-driven so a restarted client still treats
    the gallery as the authority.
    """

    def __init__(
        self,
        refresh_seconds: float,
        emit: Callable[[str, str], None],
        linked_target: Optional[LinkedUploadTarget] = None,
        before_request: Optional[Callable[[], None]] = None,
    ) -> None:
        """
        Create a remote inventory session.

        Args:
            refresh_seconds (float): Minimum seconds between planned inventory
                probes.
            emit (Callable[[str, str], None]): Callback receiving status level
                and message; it queues/logs the event and returns None.
            linked_target (Optional[LinkedUploadTarget]): Captured scoped
                destination, or None for the original API-key connection.
            before_request (Optional[Callable[[], None]]): Once-mode or manual
                batch request checkpoint, or None for other inventory.

        Returns:
            None: Create a remote inventory session.
        """
        self.refresh_seconds = max(1.0, float(refresh_seconds or DEFAULT_INVENTORY_REFRESH_SECONDS))
        self.emit = emit
        self.linked_target = linked_target
        self.before_request = before_request
        self.last_refresh_at = 0.0
        self.last_error: Exception | None = None
        self.last_fingerprint = ""
        self.existing_hashes: Set[str] = set()
        self.resolved_endpoint = ""
        self.lock = threading.Lock()

    def due(self) -> bool:
        """
        Return whether the planned reconnect interval has elapsed.

        Returns:
            bool: True when another inventory handshake should be made.
        """
        return (time.time() - self.last_refresh_at) >= self.refresh_seconds

    def remember_existing(self, payload: Dict[str, Any]) -> int:
        """
        Store hashes confirmed by a gallery inventory response.

        Args:
            payload (Dict[str, Any]): Parsed inventory response returned by PHP
                Gallery.

        Returns:
            int: Number of confirmed remote files in this response.
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

    def refresh(
        self,
        upload_url: str,
        api_key: str,
        candidates: List[Dict[str, Any]],
        force: bool = False,
        deep_check: bool = False,
    ) -> bool:
        """
        Ask the passive gallery which submitted files already exist.

        Routine inventory is database-only. The expensive filesystem fallback is
        requested only after an ambiguous upload failure, where the server may have
        stored a file before the HTTP response was lost. This keeps normal watcher
        polling and import preflight from scanning a large gallery directory.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.
            api_key (str): Gallery-scoped API key.
            candidates (List[Dict[str, Any]]): Local file descriptors to
                compare with the gallery.
            force (bool): When True, refresh even before the planned interval
                elapses.
            deep_check (bool): Request the bounded disk fallback for failure
                reconciliation.

        Returns:
            bool: True when the API call completed successfully.

        Raises:
            ModeCoordinatorError: Once-mode evidence changed before a request.
            GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
        """
        if not candidates:
            return False
        if not force and not self.due():
            return False
        with self.lock:
            if not force and not self.due():
                return False
            self.last_error = None
            # A fresh failed or negative check must never reuse an old match.
            queried = {str(item.get('sha256', '')).lower() for item in candidates}
            self.existing_hashes.difference_update(queried)
            self.resolved_endpoint = ""
            try:
                if self.linked_target is not None:
                    if deep_check:
                        self.emit(
                            "warning", gettext_noop(
                                "Linked upload recovery requires manual "
                                "review; database absence does not prove failure."
                            ),
                        )
                        return False
                    if self.before_request is not None:
                        self.before_request()
                    payload = self.linked_target.inventory(candidates)
                else:
                    request_options = (
                        {'before_request': self.before_request}
                        if self.before_request is not None else {}
                    )
                    payload, endpoint = post_json_resolved(
                        upload_url,
                        api_key,
                        {"action": "inventory", "files": candidates, "deep_check": bool(deep_check)},
                        timeout_seconds=min(DEFAULT_TIMEOUT_SECONDS, max(10.0, self.refresh_seconds)),
                        **request_options,
                    )
                    if (payload.get("action") == "inventory"
                            and endpoint in upload_endpoint_candidates(upload_url)):
                        self.resolved_endpoint = endpoint
                matched = self.remember_existing(payload)
                self.last_refresh_at = time.time()
                checked = int(payload.get("checked", len(candidates)) or 0)
                self.emit("info", f"Remote inventory refreshed: checked={checked}, already_present={matched}.")
                return True
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                raise
            except Exception as exc:  # noqa: BLE001
                self.last_error = exc
                self.last_refresh_at = time.time()
                self.emit("warning", f"Remote inventory refresh failed: {exc}")
                return False

    def has_hash(self, file_hash: str) -> bool:
        """
        Return whether the remote gallery has confirmed this content hash.

        Args:
            file_hash (str): SHA-256 hash of a local file.

        Returns:
            bool: True when a previous inventory response matched it.
        """
        return file_hash.lower() in self.existing_hashes

    def confirm_after_failure(self, upload_url: str, api_key: str, candidate: Dict[str, Any]) -> bool:
        """
        Force a reconnect probe after an ambiguous upload request failure or timeout.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.
            api_key (str): Gallery-scoped API key.
            candidate (Dict[str, Any]): File descriptor for the request that
                just failed.

        Returns:
            bool: True when the gallery now reports the file as already
                present.
        """
        if self.linked_target is not None:
            return False
        self.refresh(upload_url, api_key, [candidate], force=True, deep_check=True)
        return self.has_hash(str(candidate.get("sha256", "")))


def sha256_file(path: Path) -> str:
    """
    Calculate a file SHA-256 hash without loading the whole file at once.

    Args:
        path (Path): File to hash.

    Returns:
        str: Hex-encoded SHA-256 digest.

    Raises:
        OSError: Propagated when the file cannot be opened or read.
    """
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while True:
            chunk = handle.read(1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest()


@dataclass
class LocalThumbnail:
    """
    One locally generated thumbnail variant waiting to be uploaded.

    Args:
        path (Path): Temporary thumbnail file path on the client computer.
        size (int): Long-side size in pixels, matching PHP Gallery
            thumbnail_sizes().
        format (str): Output format accepted by the gallery, either jpg or
            webp.
    """

    path: Path
    size: int
    format: str


def multipart_field_part(boundary: str, name: str, value: str) -> bytes:
    """
    Build one text field for a multipart/form-data request body.

    Args:
        boundary (str): Multipart boundary string without the leading dashes.
        name (str): Submitted field name.
        value (str): Submitted field value.

    Returns:
        bytes: Encoded multipart field bytes.
    """
    return b"".join([
        f"--{boundary}\r\n".encode("ascii"),
        f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode("ascii"),
        str(value).encode("utf-8"),
        b"\r\n",
    ])


def multipart_file_part(
    boundary: str, field_name: str, path: Path, filename: Optional[str] = None,
    file_bytes: Optional[bytes] = None,
) -> bytes:
    """
    Build one file field for a multipart/form-data request body.

    Args:
        boundary (str): Multipart boundary string without the leading dashes.
        field_name (str): Submitted file field name.
        path (Path): Local file path whose bytes should be sent.
        filename (Optional[str]): Optional remote file name. When omitted,
            path.name is used.
        file_bytes (Optional[bytes]): Exact captured file bytes, or None to
            read the selected file once.

    Returns:
        bytes: Encoded multipart file bytes including the file content.

    Raises:
        OSError: Propagated when the file cannot be read.
    """
    safe_name = (filename or path.name).replace('"', "_")
    content_type = mimetypes.guess_type(safe_name)[0] or "application/octet-stream"
    return b"".join([
        f"--{boundary}\r\n".encode("ascii"),
        f'Content-Disposition: form-data; name="{field_name}"; filename="{safe_name}"\r\n'.encode("utf-8"),
        f"Content-Type: {content_type}\r\n\r\n".encode("ascii"),
        path.read_bytes() if file_bytes is None else file_bytes,
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
    before_request: Optional[Callable[[], None]] = None,
    expected_sha256: str = "",
    expected_size: Optional[int] = None,
    single_dispatch: bool = False,
    selected_endpoint: str = "",
    before_dispatch: Optional[Callable[[], None]] = None,
) -> Dict[str, Any]:
    """
    Upload one image file using standard-library HTTP multipart/form-data.

    The same endpoint is used for watch-folder uploads and manual uploads. Manual
    uploads may include locally generated thumbnail files in the same request. The
    server still stores the original image through the existing gallery upload
    pipeline, then installs the supplied thumbnail variants beside the final image
    record.

    Args:
        upload_url (str): Normalized PHP Gallery upload endpoint.
        api_key (str): Gallery-scoped API key sent as X-Gallery-API-Key.
        path (Path): Local image path to upload.
        create_thumbnails (bool): Whether to ask the gallery to generate
            thumbnails.
        thumbnails (Optional[List[LocalThumbnail]]): Optional local thumbnail
            variants to send with the image.
        client_upload_id (Optional[str]): Optional stable request-local ID used
            to map supplied.
        metadata_fields (Optional[Dict[str, str]]): Optional text fields to
            submit beside the image.
        before_request (Optional[Callable[[], None]]): Once-mode or manual
            batch checkpoint before each endpoint and redirect, or None.
        expected_sha256 (str): Expected image digest, or empty for an older
            direct caller without a captured candidate.
        expected_size (Optional[int]): Expected image length, or None when no
            candidate was captured.
        single_dispatch (bool): Refuse redirects and alternate multipart POSTs.
        selected_endpoint (str): Successful inventory route, or empty for the
            canonical route; used only with single_dispatch.
        before_dispatch (Optional[Callable[[], None]]): Durable head marker callback
            after local body preparation and before the one HTTP attempt.

    Returns:
        Dict[str, Any]: Response with ok and upload result fields, including
            uploaded, scanned, filenames and optional metadata or rename status.

    Raises:
        RuntimeError: Raised for HTTP errors, network errors, non-JSON
            responses, malformed JSON payloads, or server-declared upload
            failure.
        OSError: Propagated when the image or a thumbnail cannot be read.
        ValueError: The bytes to send do not match the captured candidate.
        ModeCoordinatorError: The once-mode evidence changed before an attempt.
        GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
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

    # Hash the immutable bytes that enter the request, not a prior path read.
    image_bytes = path.read_bytes()
    if ((expected_size is not None and len(image_bytes) != expected_size)
            or (expected_sha256
                and hashlib.sha256(image_bytes).hexdigest() != expected_sha256)):
        raise ValueError('Source changed before upload; no request was sent.')
    body_parts.append(multipart_file_part(
        boundary, "images[]", path, path.name, image_bytes,
    ))

    for thumbnail in thumbnails or []:
        body_parts.append(multipart_field_part(boundary, "thumbnail_client_ids[]", client_upload_id or ""))
        body_parts.append(multipart_field_part(boundary, "thumbnail_sizes[]", str(int(thumbnail.size))))
        body_parts.append(multipart_field_part(boundary, "thumbnail_formats[]", thumbnail.format))
        body_parts.append(multipart_file_part(boundary, "client_thumbnails[]", thumbnail.path, thumbnail.path.name))

    body_parts.append(f"--{boundary}--\r\n".encode("ascii"))
    body = b"".join(body_parts)

    candidates = upload_endpoint_candidates(upload_url)
    if not candidates:
        raise RuntimeError("Gallery URL or upload endpoint is invalid.")
    if single_dispatch:
        if selected_endpoint and selected_endpoint not in candidates:
            raise ValueError("The selected upload endpoint is invalid.")
        candidates = [selected_endpoint or candidates[0]]

    for index, endpoint in enumerate(candidates):
        http_request = request.Request(
            endpoint,
            data=body,
            headers={
                "Content-Type": f"multipart/form-data; boundary={boundary}",
                "Content-Length": str(len(body)),
                "X-Gallery-API-Key": api_key,
                "Accept": "application/json",
                "User-Agent": "PHPGalleryUploader/1.4",
            },
            method="POST",
        )

        try:
            request_options = (
                {'before_request': before_request} if before_request is not None else {}
            )
            if single_dispatch:
                request_options["allow_redirects"] = False
            if before_dispatch is not None:
                try:
                    before_dispatch()
                except (OSError, ValueError, RuntimeError):
                    raise UploadPreparationError('Local upload preparation failed.') from None
            with _gallery_key_open(
                http_request, DEFAULT_TIMEOUT_SECONDS, **request_options,
            ) as response:
                response_body = response.read().decode("utf-8", errors="replace")
        except error.HTTPError as exc:
            # PHP Gallery returns JSON for application errors. Only an HTML/plain
            # 404 from the hosting layer is eligible for alternate-route retry.
            response_body = exc.read().decode("utf-8", errors="replace")
            try:
                error_payload = json.loads(response_body)
                message = error_payload.get("error") if isinstance(error_payload, dict) else None
                json_error = isinstance(error_payload, dict)
            except json.JSONDecodeError:
                message = None
                json_error = False
            has_fallback = index + 1 < len(candidates)
            if exc.code == 404 and not json_error and has_fallback:
                logging.warning("Upload endpoint route returned HTTP 404 during multipart upload; retrying alternate Gallery API route.")
                continue
            raise LegacyUploadResponseError(
                exc.code, validated_retry_after(
                    exc.headers.get_all('Retry-After', [])
                    if hasattr(exc.headers, 'get_all') else [],
                ),
                definitely_rejected=(
                    exc.code == 401 and message == 'Invalid or revoked API key.'
                    or exc.code == 403 and message == (
                        'API key is not allowed to upload to the submitted gallery.'
                    )
                ),
            ) from None
        except error.URLError as exc:
            raise LegacyUploadResponseError(
                None,
                request_started=not isinstance(exc.reason, (
                    socket.gaierror, ConnectionRefusedError,
                )),
            ) from None

        try:
            payload = json.loads(response_body)
        except json.JSONDecodeError as exc:
            raise RuntimeError(f"Server returned non-JSON response: {response_body[:300]}") from exc
        if not isinstance(payload, dict):
            raise RuntimeError("Server returned an invalid JSON response.")
        if not payload.get("ok"):
            raise RuntimeError(str(payload.get("error") or "Upload failed."))
        if expected_sha256 and (payload.get("ok") is not True
                                or type(payload.get("uploaded")) is not int
                                or payload.get("uploaded") != 1):
            raise RuntimeError("Upload outcome is unconfirmed; review the gallery.")
        return payload

    raise RuntimeError("No usable PHP Gallery upload endpoint was found.")
