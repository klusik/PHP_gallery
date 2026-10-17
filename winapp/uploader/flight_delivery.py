# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_delivery.py
# Module Type: Windows Tooling
# Purpose: Deliver generated flight report PDFs to the authorized gallery.
# Responsibilities:
#   - Negotiate the gallery-scoped report capability
#   - Reconcile stable report identities and checksums before retrying uploads
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Gallery-scoped delivery for locally generated flight report PDFs."""

import hashlib
import http.client
import json
import re
import uuid
from pathlib import Path
from typing import Any, Callable, Dict, Optional, Tuple
from urllib.parse import urlsplit


# Purpose: Bound one report to the existing gallery PDF service policy.
# Type: int; Units: bytes; Scope: one generated report PDF.
# Consumers: _pdf_digest and _perform_request.
# Rationale: The gallery PDF service accepts originals up to 25 MiB.
FLIGHT_DELIVERY_MAX_PDF_BYTES = 25 * 1024 * 1024

# Purpose: Bound each streamed report upload read and network write.
# Type: int; Units: bytes; Scope: one multipart report transfer.
# Consumers: _perform_request.
# Rationale: 64 KiB chunks cap transient memory while keeping progress responsive.
FLIGHT_DELIVERY_CHUNK_BYTES = 64 * 1024

# Purpose: Bound connection and response waits for report delivery.
# Type: int; Units: seconds; Scope: one HTTP request.
# Consumers: _perform_request.
# Rationale: 180 seconds allows a slow shared-hosting upload to finish.
FLIGHT_DELIVERY_TIMEOUT_SECONDS = 180

# Purpose: Bound JSON response bodies accepted from the gallery endpoint.
# Type: int; Units: bytes; Scope: one upload automation response.
# Consumers: _perform_request.
# Rationale: Receipts and errors are small metadata and need no large body.
FLIGHT_DELIVERY_RESPONSE_MAX_BYTES = 64 * 1024

# Purpose: Keep opaque report identities compact in upload fields.
# Type: int; Units: characters; Scope: report and session identifiers.
# Consumers: the _SAFE_ID validation pattern.
# Rationale: The server accepts identifiers up to the same 80-character bound.
FLIGHT_DELIVERY_MAX_ID_CHARS = 80

_SUPPORTED_LANGUAGES = {"en", "cs", "de", "sv"}
_HEX_SHA256 = re.compile(r"^[a-f0-9]{64}$")
_SAFE_ID = re.compile(
    r"^[A-Za-z0-9_-]{1,"
    + str(FLIGHT_DELIVERY_MAX_ID_CHARS)
    + r"}$"
)


class FlightDeliveryError(RuntimeError):
    """Represent a report delivery protocol or server rejection.

    Args:
        message (str): Safe user-facing failure description.
        status_code (Optional[int]): HTTP status returned by the gallery, or
            None when the request did not receive an HTTP response.

    Returns:
        None: Initializes a protocol error with optional HTTP status context.
    """

    def __init__(
        self, message: str, status_code: Optional[int] = None
    ) -> None:
        """Store the readable error and its optional HTTP status.

        Args:
            message (str): Safe user-facing failure description.
            status_code (Optional[int]): HTTP response status when available.

        Returns:
            None: Initializes this exception.
        """
        super().__init__(message)
        self.status_code = status_code


def _validate_identity(
    session_id: str,
    report_id: str,
    language: str,
) -> Tuple[str, str]:
    """Validate and normalize the stable report request identity.

    Args:
        session_id (str): Local flight session identifier.
        report_id (str): Stable report revision identifier.
        language (str): Requested language tag.

    Returns:
        Tuple[str, str]: Lowercase language and unchanged session identity.

    Raises:
        ValueError: When an identity or language is unsupported.
    """
    normalized_language = language.strip().lower()
    if not _SAFE_ID.fullmatch(session_id.strip()):
        raise ValueError("The flight session identity is invalid.")
    if not _SAFE_ID.fullmatch(report_id.strip()):
        raise ValueError("The flight report identity is invalid.")
    if normalized_language not in _SUPPORTED_LANGUAGES:
        raise ValueError("The flight report language is unsupported.")
    return normalized_language, session_id.strip()


def _pdf_digest(path: Path) -> Tuple[str, int]:
    """Validate and hash one local PDF without loading it all into memory.

    Args:
        path (Path): Locally retained report file.

    Returns:
        Tuple[str, int]: Lowercase SHA-256 and exact PDF byte count.

    Raises:
        ValueError: When the file is not a bounded complete PDF.
        OSError: When the file cannot be read.
    """
    if not path.is_file() or path.is_symlink():
        raise ValueError("The flight report file is unavailable.")
    size = path.stat().st_size
    if size < 16 or size > FLIGHT_DELIVERY_MAX_PDF_BYTES:
        raise ValueError("The flight report is outside the gallery PDF size limit.")

    digest = hashlib.sha256()
    tail = b""
    total = 0
    with path.open("rb") as source:
        if source.read(5) != b"%PDF-":
            raise ValueError("The generated report is not a PDF.")
        source.seek(0)
        while True:
            chunk = source.read(FLIGHT_DELIVERY_CHUNK_BYTES)
            if not chunk:
                break
            total += len(chunk)
            digest.update(chunk)
            tail = (tail + chunk)[-4096:]
    if total != size or b"%%EOF" not in tail:
        raise ValueError("The generated report is incomplete.")
    return digest.hexdigest(), size


def _connection_target(url: str) -> Tuple[http.client.HTTPConnection, str]:
    """Build a verified HTTP connection and request target from an endpoint.

    Args:
        url (str): Absolute upload automation endpoint URL.

    Returns:
        Tuple[http.client.HTTPConnection, str]: TLS-verified connection and
            path/query target.

    Raises:
        ValueError: When the endpoint URL is invalid or contains credentials.
    """
    parsed = urlsplit(url.strip())
    if parsed.scheme not in {"http", "https"} or not parsed.hostname:
        raise ValueError("The gallery upload URL is invalid.")
    if parsed.username is not None or parsed.password is not None:
        raise ValueError("The gallery upload URL must not contain credentials.")
    if parsed.fragment:
        raise ValueError("The gallery upload URL must not contain a fragment.")
    try:
        port = parsed.port
    except ValueError as exc:
        raise ValueError("The gallery upload URL has an invalid port.") from exc
    host = parsed.hostname
    timeout = FLIGHT_DELIVERY_TIMEOUT_SECONDS
    if parsed.scheme == "https":
        connection = http.client.HTTPSConnection(
            host, port=port, timeout=timeout
        )
    else:
        connection = http.client.HTTPConnection(
            host, port=port, timeout=timeout
        )
    target = parsed.path or "/"
    if parsed.query:
        target += "?" + parsed.query
    return connection, target


def _perform_request(
    url: str,
    api_key: str,
    payload: Optional[Dict[str, Any]] = None,
    pdf_path: Optional[Path] = None,
    fields: Optional[Dict[str, str]] = None,
    emit: Optional[Callable[[int, int], None]] = None,
    file_size: int = 0,
) -> Dict[str, Any]:
    """Send one JSON or streamed multipart request to the gallery.

    Args:
        url (str): Absolute upload automation endpoint URL.
        api_key (str): Gallery-scoped upload key.
        payload (Optional[Dict[str, Any]]): JSON request object, or None
            for a multipart request.
        pdf_path (Optional[Path]): PDF streamed in the report part.
        fields (Optional[Dict[str, str]]): ASCII form fields beside the PDF.
        emit (Optional[Callable[[int, int], None]]): Optional file-byte progress
            callback receiving bytes sent and total bytes.
        file_size (int): Expected PDF byte count for multipart framing.

    Returns:
        Dict[str, Any]: Decoded gallery JSON response object.

    Raises:
        FlightDeliveryError: When the HTTP response is unsuccessful or invalid.
        OSError: When the local file or network connection fails.
        http.client.HTTPException: When the HTTP exchange is malformed.
    """
    if not api_key.strip():
        raise ValueError("A gallery API key is required.")
    connection, target = _connection_target(url)
    headers = {
        "X-Gallery-Api-Key": api_key.strip(),
        "Accept": "application/json",
        "X-Content-Type-Options": "nosniff",
    }
    try:
        if pdf_path is None:
            encoded = json.dumps(payload or {}).encode("utf-8")
            headers["Content-Type"] = "application/json"
            headers["Content-Length"] = str(len(encoded))
            connection.request("POST", target, body=encoded, headers=headers)
        else:
            boundary = "----PHPGalleryFlight" + uuid.uuid4().hex
            safe_fields = fields or {}
            field_parts = []
            for name, value in safe_fields.items():
                field_parts.append(
                    (
                        "--" + boundary + "\r\n"
                        + 'Content-Disposition: form-data; name="'
                        + name + '"\r\n\r\n' + value + "\r\n"
                    ).encode("utf-8")
                )
            file_header = (
                "--" + boundary + "\r\n"
                + 'Content-Disposition: form-data; name="report"; '
                + 'filename="flight-report.pdf"\r\n'
                + "Content-Type: application/pdf\r\n\r\n"
            ).encode("ascii")
            ending = ("\r\n--" + boundary + "--\r\n").encode("ascii")
            length = (
                sum(len(part) for part in field_parts)
                + len(file_header) + file_size + len(ending)
            )
            headers["Content-Type"] = (
                "multipart/form-data; boundary=" + boundary
            )
            headers["Content-Length"] = str(length)
            connection.putrequest("POST", target)
            for name, value in headers.items():
                connection.putheader(name, value)
            connection.endheaders()
            for part in field_parts:
                connection.send(part)
            connection.send(file_header)
            sent = 0
            if emit is not None:
                emit(0, file_size)
            with pdf_path.open("rb") as source:
                while sent < file_size:
                    remaining = file_size - sent
                    chunk = source.read(
                        min(FLIGHT_DELIVERY_CHUNK_BYTES, remaining)
                    )
                    if not chunk:
                        break
                    connection.send(chunk)
                    sent += len(chunk)
                    if emit is not None:
                        emit(sent, file_size)
            if sent != file_size:
                raise OSError("The flight report changed during upload.")
            connection.send(ending)

        response = connection.getresponse()
        body = response.read(FLIGHT_DELIVERY_RESPONSE_MAX_BYTES + 1)
        if len(body) > FLIGHT_DELIVERY_RESPONSE_MAX_BYTES:
            raise FlightDeliveryError("The gallery response exceeded its limit.")
        try:
            decoded = json.loads(body.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise FlightDeliveryError(
                "The gallery returned an invalid report response.",
                response.status,
            ) from exc
        if not isinstance(decoded, dict):
            raise FlightDeliveryError(
                "The gallery returned an invalid report response.",
                response.status,
            )
        if response.status < 200 or response.status >= 300:
            message = str(decoded.get("error", "The gallery rejected the report."))
            raise FlightDeliveryError(message[:400], response.status)
        if decoded.get("ok") is not True:
            message = str(decoded.get("error", "The gallery rejected the report."))
            raise FlightDeliveryError(message[:400], response.status)
        return decoded
    finally:
        connection.close()


def _receipt(
    response: Dict[str, Any],
    report_id: str,
    language: str,
    sha256: str,
    already_present: bool,
) -> Dict[str, Any]:
    """Validate a server acknowledgement and return its compact receipt.

    Args:
        response (Dict[str, Any]): Successful status or upload response.
        report_id (str): Requested stable report identity.
        language (str): Normalized language tag.
        sha256 (str): Expected exact PDF digest.
        already_present (bool): Whether the status check found the report.

    Returns:
        Dict[str, Any]: Receipt containing opaque document id, checksum,
            report identity, language, size, filename and prior-presence flag.

    Raises:
        FlightDeliveryError: When the server acknowledgement is incomplete or
            refers to different PDF bytes.
    """
    report = response.get("report")
    if not isinstance(report, dict):
        raise FlightDeliveryError("The gallery did not acknowledge the report.")
    identifier = report.get("id")
    digest = report.get("sha256")
    if (
        not isinstance(identifier, str)
        or re.fullmatch(r"[a-f0-9]{32}", identifier) is None
        or not isinstance(digest, str)
        or _HEX_SHA256.fullmatch(digest) is None
        or digest != sha256
    ):
        raise FlightDeliveryError(
            "The gallery acknowledgement does not match the report checksum."
        )
    return {
        "id": identifier,
        "sha256": sha256,
        "report_id": report_id,
        "language": language,
        "size": int(report.get("size", 0)),
        "filename": str(report.get("filename", "")),
        "already_present": already_present,
    }


def check_report_capability(upload_url: str, api_key: str) -> bool:
    """Check whether the authorized gallery advertises flight PDF delivery.

    Args:
        upload_url (str): Absolute upload automation endpoint URL.
        api_key (str): Gallery-scoped upload key.

    Returns:
        bool: True when inventory advertises flight_reports_v1; otherwise
            False for an older endpoint that rejects the capability probe.

    Raises:
        OSError: When the capability request cannot reach the gallery.
        FlightDeliveryError: When the gallery response is malformed.
    """
    try:
        response = _perform_request(
            upload_url,
            api_key,
            {"action": "inventory", "candidates": []},
        )
    except FlightDeliveryError as exc:
        if exc.status_code in {400, 404, 405}:
            return False
        raise
    capabilities = response.get("capabilities")
    return (
        isinstance(capabilities, dict)
        and capabilities.get("flight_reports_v1") is True
    )


def deliver_report(
    upload_url: str,
    api_key: str,
    pdf: Path,
    session_id: str,
    language: str,
    report_id: str,
    emit: Optional[Callable[[int, int], None]] = None,
) -> Dict[str, Any]:
    """Deliver one report after status reconciliation and return its receipt.

    Args:
        upload_url (str): Absolute upload automation endpoint URL.
        api_key (str): Gallery-scoped upload key.
        pdf (Path): Locally retained report PDF.
        session_id (str): Local flight session identity.
        language (str): Report language, one of EN, CS, DE or SV.
        report_id (str): Stable report revision identity.
        emit (Optional[Callable[[int, int], None]]): Optional progress callback
            receiving PDF bytes sent and exact total PDF bytes.

    Returns:
        Dict[str, Any]: Receipt containing attachment id and sha256, plus
            report identity, language, size, filename and retry state.

    Raises:
        ValueError: When identities or PDF bytes are invalid.
        OSError: When local file access or network delivery fails.
        FlightDeliveryError: When the gallery rejects or cannot confirm the
            report.
    """
    language, session_id = _validate_identity(
        session_id, report_id, language
    )
    digest, size = _pdf_digest(pdf)
    status_payload = {
        "action": "flight_report_status",
        "report_id": report_id.strip(),
        "language": language,
        "sha256": digest,
    }
    status = _perform_request(upload_url, api_key, status_payload)
    if status.get("conflict") is True:
        raise FlightDeliveryError(
            "A different PDF is already stored for this report identity.",
            409,
        )
    if status.get("found") is True:
        return _receipt(status, report_id.strip(), language, digest, True)

    fields = {
        "action": "flight_report",
        "report_id": report_id.strip(),
        "session_id": session_id,
        "language": language,
        "sha256": digest,
    }
    try:
        uploaded = _perform_request(
            upload_url,
            api_key,
            pdf_path=pdf,
            fields=fields,
            emit=emit,
            file_size=size,
        )
        if uploaded.get("conflict") is True:
            raise FlightDeliveryError(
                "A different PDF is already stored for this report identity.",
                409,
            )
        return _receipt(uploaded, report_id.strip(), language, digest, False)
    except FlightDeliveryError as exc:
        if (
            exc.status_code is not None
            and 400 <= exc.status_code < 500
        ):
            raise
    except (OSError, http.client.HTTPException):
        pass

    # A reset or server error may follow a committed file write. Reconcile the
    # stable identity before exposing failure so the caller never duplicates it.
    reconciled = _perform_request(upload_url, api_key, status_payload)
    if reconciled.get("conflict") is True:
        raise FlightDeliveryError(
            "A different PDF is already stored for this report identity.",
            409,
        )
    if reconciled.get("found") is True:
        return _receipt(
            reconciled, report_id.strip(), language, digest, True
        )
    raise FlightDeliveryError(
        "The report upload outcome is unknown; check its status before retrying."
    )
