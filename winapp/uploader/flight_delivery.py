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

from uploader.diagnostics import redact_text
from uploader.models import JsonValue
from uploader.server_discovery import _unique_object


# Purpose: Bound one report to the existing gallery PDF service policy.
# Type: int; Units: bytes; Scope: one generated report PDF.
# Consumers: flight_report_digest and _perform_request.
# Rationale: The gallery PDF service accepts originals up to 25 MiB.
FLIGHT_DELIVERY_MAX_PDF_BYTES = 25 * 1024 * 1024

# Purpose: Bound each streamed report upload read and network write.
# Type: int; Units: bytes; Scope: one report read or multipart transfer.
# Consumers: flight_report_digest, _perform_request and local finalization.
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

# Purpose: Keep malformed-response diagnostics useful and bounded after redaction.
# Type: int; Units: characters; Scope: one report HTTP error message.
# Consumers: _perform_request. Rationale: 320 characters identify HTML/PHP errors
# without copying an entire hosting response into logs or durable session state.
FLIGHT_DELIVERY_ERROR_PREVIEW_CHARS = 320

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


class FlightRequestAdmissionError(RuntimeError):
    """Refuse a report request whose host-owned work is no longer admitted."""


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


def flight_report_digest(path: Path) -> Tuple[str, int]:
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
            if total > FLIGHT_DELIVERY_MAX_PDF_BYTES:
                raise ValueError("The flight report is outside the gallery PDF size limit.")
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
    before_request: Optional[Callable[[], None]] = None,
) -> Dict[str, Any]:
    """Send one JSON or streamed multipart request to the gallery.

    Args:
        url (str): Absolute upload automation endpoint URL.
        api_key (str): Gallery-scoped upload key.
        payload (Optional[Dict[str, Any]]): Inventory action and candidates,
            or report status action with identity, language and SHA-256;
            None for multipart requests.
        pdf_path (Optional[Path]): PDF streamed in the report part.
        fields (Optional[Dict[str, str]]): Multipart action, session and report
            identities, language and SHA-256, or None for JSON requests.
        emit (Optional[Callable[[int, int], None]]): Optional file-byte progress
            callback receiving bytes sent and total bytes.
        file_size (int): Expected PDF byte count for multipart framing.
        before_request (Optional[Callable[[], None]]): Host-owned work
            checkpoint, or None when the host has not integrated one.

    Returns:
        Dict[str, Any]: Gallery JSON with ok, action-specific capability or
            status fields and an optional report receipt object.

    Raises:
        FlightDeliveryError: When the HTTP response is unsuccessful or invalid.
        OSError: When the local file or network connection fails.
        http.client.HTTPException: When the HTTP exchange is malformed.
        FlightRequestAdmissionError: The host checkpoint could not authorize
            this attempt; a prior attempt may still have committed.
    """
    if not api_key.strip():
        raise ValueError("A gallery API key is required.")
    if before_request is not None:
        try:
            before_request()
        except Exception:
            # Never misclassify failed admission as a retryable network error.
            raise FlightRequestAdmissionError(
                'Flight report request admission is unavailable; an earlier '
                'upload may need reconciliation.',
            ) from None
    connection, target = _connection_target(url)
    headers = {
        "X-Gallery-Api-Key": api_key.strip(),
        "Accept": "application/json",
        "X-Content-Type-Options": "nosniff",
        # Match the established photograph/inventory client's identification.
        # Hosting protection rejects unidentified http.client requests before
        # Gallery authentication; no browser challenge or TLS bypass is used.
        "User-Agent": "PHPGalleryUploader/1.4",
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
        action = str((payload or fields or {}).get("action", "unknown"))
        content_type = redact_text(
            response.getheader("Content-Type", "unknown"), secrets=(api_key,),
        )[:FLIGHT_DELIVERY_ERROR_PREVIEW_CHARS]
        preview = " ".join(redact_text(
            body.decode("utf-8", errors="replace"), secrets=(api_key,),
        ).split())[:FLIGHT_DELIVERY_ERROR_PREVIEW_CHARS]
        context = redact_text(
            f"action={action}; HTTP {response.status}; "
            f"Content-Type={content_type}; response={preview or '(empty)'}",
            secrets=(api_key,),
        )
        if response.status in {401, 403} and "json" not in content_type.lower():
            protection = (
                "WEDOS.protection security challenge"
                if b"wedos" in body.lower() else "non-JSON access gateway"
            )
            raise FlightDeliveryError(
                "Report request blocked by " + protection
                + "; no PDF acknowledgement received. " + context,
                response.status,
            )
        if len(body) > FLIGHT_DELIVERY_RESPONSE_MAX_BYTES:
            raise FlightDeliveryError(
                "The gallery response exceeded its limit. " + context,
                response.status,
            )
        try:
            decoded = json.loads(body.decode("utf-8"), object_pairs_hook=_unique_object)
        except (UnicodeDecodeError, ValueError) as exc:
            raise FlightDeliveryError(
                "The gallery returned invalid JSON for a report request. " + context,
                response.status,
            ) from exc
        if not isinstance(decoded, dict):
            raise FlightDeliveryError(
                "The gallery report response must be a JSON object. " + context,
                response.status,
            )
        if response.status < 200 or response.status >= 300:
            message = str(decoded.get("error", "The gallery rejected the report."))
            raise FlightDeliveryError(
                redact_text(message, secrets=(api_key,))[:FLIGHT_DELIVERY_ERROR_PREVIEW_CHARS]
                + "; " + context, response.status,
            )
        if decoded.get("ok") is not True:
            message = str(decoded.get("error", "The gallery rejected the report."))
            raise FlightDeliveryError(
                redact_text(message, secrets=(api_key,))[:FLIGHT_DELIVERY_ERROR_PREVIEW_CHARS]
                + "; " + context, response.status,
            )
        return decoded
    finally:
        connection.close()


def _receipt(
    response: Dict[str, Any],
    report_id: str,
    language: str,
    sha256: str,
    size: int,
    already_present: bool,
) -> Dict[str, Any]:
    """Validate a server acknowledgement and return its compact receipt.

    Args:
        response (Dict[str, Any]): Successful status or upload response.
        report_id (str): Requested stable report identity.
        language (str): Normalized language tag.
        sha256 (str): Expected exact PDF digest.
        size (int): Expected byte length of the locally validated PDF.
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
    report_size = report.get("size")
    if (
        not isinstance(identifier, str)
        or re.fullmatch(r"[a-f0-9]{32}", identifier) is None
        or not isinstance(digest, str)
        or _HEX_SHA256.fullmatch(digest) is None
        or digest != sha256
        or type(report_size) is not int
        or report_size != size
    ):
        raise FlightDeliveryError(
            "The gallery acknowledgement does not match the report checksum."
        )
    return {
        "id": identifier,
        "sha256": sha256,
        "report_id": report_id,
        "language": language,
        "size": report_size,
        "filename": str(report.get("filename", "")),
        "already_present": already_present,
    }


def _status_found(response: Dict[str, JsonValue]) -> bool:
    """Require an unambiguous report-status response before any upload.

    Args:
        response (Dict[str, JsonValue]): Decoded status containing action,
            found, conflict and optional report metadata fields.

    Returns:
        bool: True for a reported match, false only for confirmed absence.

    Raises:
        FlightDeliveryError: Status is conflicting, incomplete or inconsistent.
    """
    if response.get("conflict") is True:
        raise FlightDeliveryError(
            "A different PDF is already stored for this report identity.", 409,
        )
    found = response.get("found")
    if (response.get("ok") is not True
            or response.get("action") != "flight_report_status"
            or type(found) is not bool
            or response.get("conflict") is not False
            or "report" not in response
            or (not found and response["report"] is not None)
            or (found and not isinstance(response["report"], dict))):
        raise FlightDeliveryError("The gallery report status could not be verified.")
    return found


def _verify_report_unchanged(pdf: Path, sha256: str, size: int) -> None:
    """Check the retained report again before publishing its acknowledgement.

    Args:
        pdf (Path): Retained local PDF selected for the current delivery.
        sha256 (str): SHA-256 captured before the first report request.
        size (int): Byte length captured before the first report request.

    Returns:
        None: A fresh read still has the captured digest and byte length.

    Raises:
        FlightDeliveryError: The retained file changed or cannot be verified.
    """
    try:
        current_digest, current_size = flight_report_digest(pdf)
        if current_digest != sha256 or current_size != size:
            raise ValueError
    except (OSError, ValueError):
        raise FlightDeliveryError(
            "The local report changed; its delivery cannot be confirmed."
        ) from None


def check_report_capability(
    upload_url: str, api_key: str,
    before_request: Optional[Callable[[], None]] = None,
) -> bool:
    """Check whether the authorized gallery advertises flight PDF delivery.

    Args:
        upload_url (str): Absolute upload automation endpoint URL.
        api_key (str): Gallery-scoped upload key.
        before_request (Optional[Callable[[], None]]): Host-owned request
            checkpoint, or None when no host guard is integrated.

    Returns:
        bool: True when inventory advertises flight_reports_v1; otherwise
            False for an older endpoint that rejects the capability probe.

    Raises:
        OSError: When the capability request cannot reach the gallery.
        FlightDeliveryError: When the gallery response is malformed.
        FlightRequestAdmissionError: The host denied the capability request.
    """
    try:
        request_options = (
            {'before_request': before_request} if before_request is not None else {}
        )
        response = _perform_request(
            upload_url,
            api_key,
            {"action": "inventory", "candidates": []},
            **request_options,
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


def saved_report_destination(
    upload_url: str, api_key: str, expected_gallery: str,
    before_request: Optional[Callable[[], None]] = None,
) -> None:
    """Verify the current key's gallery against the explicit saved-upload choice.

    Args:
        upload_url (str): Saved canonical upload endpoint.
        api_key (str): Current gallery-scoped key, including a replacement key.
        expected_gallery (str): Gallery folder displayed in the confirmation.
        before_request (Optional[Callable[[], None]]): Host-owned request
            checkpoint, or None when no host guard is integrated.

    Returns:
        None: Inventory confirms the destination and flight_reports_v1 support.

    Raises:
        ValueError: The key belongs to a different or unknown gallery.
        FlightDeliveryError: The endpoint cannot confirm report capability.
        OSError: The inventory request cannot reach the server.
        FlightRequestAdmissionError: The host denied the destination request.
    """
    request_options = (
        {'before_request': before_request} if before_request is not None else {}
    )
    response = _perform_request(
        upload_url, api_key, {"action": "inventory", "candidates": []},
        **request_options,
    )
    if not expected_gallery or response.get("gallery_folder") != expected_gallery:
        raise ValueError("Gallery changed; test the saved connection and confirm its destination again.")
    capabilities = response.get("capabilities")
    if not isinstance(capabilities, dict) or capabilities.get("flight_reports_v1") is not True:
        raise FlightDeliveryError("The gallery does not support flight_reports_v1.")


def deliver_report(
    upload_url: str,
    api_key: str,
    pdf: Path,
    session_id: str,
    language: str,
    report_id: str,
    emit: Optional[Callable[[int, int], None]] = None,
    expected_sha256: Optional[str] = None,
    before_request: Optional[Callable[[], None]] = None,
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
        expected_sha256 (Optional[str]): Earlier captured PDF SHA-256, or None
            when the caller has not independently captured it.
        before_request (Optional[Callable[[], None]]): Host-owned checkpoint
            before each status, upload and reconciliation request, or None.

    Returns:
        Dict[str, Any]: Receipt containing attachment id and sha256, plus
            report identity, language, size, filename and retry state.

    Raises:
        ValueError: When identities or PDF bytes are invalid.
        OSError: When local file access or network delivery fails.
        FlightDeliveryError: When the gallery rejects or cannot confirm the
            report.
        FlightRequestAdmissionError: The host denied an attempt. An earlier
            upload may still have committed and requires reconciliation.
    """
    language, session_id = _validate_identity(
        session_id, report_id, language
    )
    digest, size = flight_report_digest(pdf)
    if expected_sha256 is not None and (
        not isinstance(expected_sha256, str)
        or _HEX_SHA256.fullmatch(expected_sha256) is None
        or digest != expected_sha256
    ):
        raise FlightDeliveryError("The local report changed before delivery.")
    status_payload = {
        "action": "flight_report_status",
        "report_id": report_id.strip(),
        "language": language,
        "sha256": digest,
    }
    request_options = (
        {'before_request': before_request} if before_request is not None else {}
    )
    status = _perform_request(upload_url, api_key, status_payload, **request_options)
    if _status_found(status):
        receipt = _receipt(status, report_id.strip(), language, digest, size, True)
        _verify_report_unchanged(pdf, digest, size)
        return receipt

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
            **request_options,
        )
        if uploaded.get("conflict") is True:
            raise FlightDeliveryError(
                "A different PDF is already stored for this report identity.",
                409,
            )
        if (uploaded.get("ok") is not True
                or uploaded.get("action") != "flight_report"
                or uploaded.get("found") is not True
                or uploaded.get("conflict") is not False):
            raise FlightDeliveryError("The gallery report receipt could not be verified.")
        receipt = _receipt(uploaded, report_id.strip(), language, digest, size, False)
    except FlightDeliveryError as exc:
        if exc.status_code == 409:
            raise
    except (OSError, http.client.HTTPException):
        pass
    else:
        _verify_report_unchanged(pdf, digest, size)
        return receipt

    # A reset or server error may follow a committed file write. Reconcile the
    # stable identity before exposing failure so the caller never duplicates it.
    try:
        reconciled = _perform_request(
            upload_url, api_key, status_payload, **request_options,
        )
        found = _status_found(reconciled)
    except FlightDeliveryError as exc:
        if exc.status_code == 409:
            raise
        raise FlightDeliveryError(
            "The report upload outcome is unknown; check its status before retrying."
        ) from exc
    except (OSError, http.client.HTTPException) as exc:
        raise FlightDeliveryError(
            "The report upload outcome is unknown; check its status before retrying."
        ) from exc
    if found:
        receipt = _receipt(
            reconciled, report_id.strip(), language, digest, size, True
        )
        _verify_report_unchanged(pdf, digest, size)
        return receipt
    raise FlightDeliveryError(
        "The report upload outcome is unknown; check its status before retrying."
    )
