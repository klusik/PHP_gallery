# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_b1_http.py
# Module Type: Disposable HTTP Client and Failure Policy
# Purpose: Observe real loopback HTTP bytes through the existing B1.1 parsers.
# Responsibilities:
#   - Validate status, MIME and bounded body before publishing complete items.
#   - Separate monotonic HTTP, producer-independent parser and cancellation times.
#   - Classify safe fallback decisions without modifying the production WinApp.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""CI-only HTTP transport observation, with no production authentication claim."""

import contextlib
import email.utils
import hashlib
import http.client
import json
import os
import re
import socket
import subprocess
import time
import tracemalloc
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator
from urllib.parse import urlencode

from tools.perf.issue_362_b1_protocol import (
    BOUNDARY_PATTERN,
    FramedParser,
    MAX_RESPONSE_BYTES,
    MultipartParser,
    ProtocolError,
)

# Purpose: Cap one HTTP client connection, including first-header wait.
# Type: float. Units: seconds. Scope: one synthetic request.
# Consumers: request_batch, request_single, wait_healthy.
# Rationale: three seconds bounds ordinary loopback failure without a long job.
HTTP_TIMEOUT_SECONDS = 3.0

# Purpose: Bound temporary PHP server initialization.
# Type: float. Units: seconds. Scope: one disposable server process.
# Consumers: wait_healthy. Rationale: three seconds avoids hanging CI startup.
SERVER_STARTUP_SECONDS = 3.0

# Purpose: Bound PHP termination before a forced kill.
# Type: float. Units: seconds. Scope: server cleanup.
# Consumers: temporary_server. Rationale: two seconds is enough for exit.
SERVER_EXIT_SECONDS = 2.0

# Purpose: Read one byte first to measure actual client-visible body arrival.
# Type: int. Units: bytes. Scope: HTTPResponse first read.
# Consumers: request_batch, request_single. Rationale: distinguish headers
# from actual payload, not the first 64 KiB coalesced read.
FIRST_BYTE_READ = 1

# Purpose: Compare fine versus coalesced transport reads.
# Type: int. Units: bytes/read. Scope: one HTTP request.
# Consumers: request_batch, request_single. Rationale: 1024 and 65536 bytes
# match existing B1.1 simulated chunk sizes for contrast.
SMALL_HTTP_CHUNK = 1024
LARGE_HTTP_CHUNK = 65536

# Purpose: Bound consumer-backpressure simulation.
# Type: float. Units: seconds/read. Scope: selected CI-only request.
# Consumers: request_batch. Rationale: 3 ms visibly slows an eight-item
# request without creating unnecessary resource load.
SLOW_CONSUMER_SECONDS = 0.003

# Purpose: Cancel slow-consumer tests only after meaningful incremental reads.
# Type: int. Units: 1024-byte chunks. Scope: B1.2 cancellation simulation.
# Consumers: request_batch. Rationale: three completed reads represent actual
# consumption of part of a JPEG/WebP rather than aborting at the first byte.
SLOW_CANCEL_READ_CHUNKS = 3

# Purpose: Bound repeated health requests.
# Type: float. Units: seconds/poll. Scope: server startup only.
# Consumers: wait_healthy. Rationale: 50 ms avoids busy-waiting.
HEALTH_POLL_SECONDS = 0.05

# Purpose: Prevent excessive retry delays from being interpreted as commands.
# Type: int. Units: seconds. Scope: classification only, no actual retries.
# Consumers: parse_retry_after. Rationale: 120 seconds is a reporting cap,
# not a permission to retry or ignore a longer server delay.
RETRY_REPORT_CAP_SECONDS = 120

# Purpose: Bound controller-created response parsing memory.
# Type: int. Units: bytes. Scope: one buffered response.
# Consumers: request_batch. Rationale: same 16 MiB experimental B1.1 cap.
HTTP_BODY_LIMIT = MAX_RESPONSE_BYTES

# Purpose: Require exact experimental media-type negotiation.
# Type: str. Units: MIME token. Scope: B1.2 framed HTTP.
# Consumers: make_parser. Rationale: never feed HTML to a binary parser.
FRAMED_CONTENT_TYPE = "application/vnd.php-gallery.thumbnail-batch.v1"

# Purpose: Permit only explicitly recognized transport cancellation checkpoints.
# Type: frozenset[str]. Units: cancellation labels. Scope: B1.2 client.
# Consumers: request_batch. Rationale: no arbitrary callback network effects.
CANCELLATION_POINTS = frozenset((
    "none", "before_headers", "first_body", "during_first",
    "after_first", "after_two", "buffered", "slow",
))

# Purpose: Limit HTTP status cases to defined experimental decisions.
# Type: frozenset[int]. Units: HTTP status values. Scope: B1.2 policy.
# Consumers: fallback_decision. Rationale: default-deny for other errors.
SAFE_OPTIONAL_UNSUPPORTED_STATUSES = frozenset((404, 405))


def parse_retry_after(value: str | None, now: datetime | None = None) -> int | None:
    """Interpret a valid Retry-After delay without actually performing a retry.

    Args:
        value (str | None): Header value, absent or untrusted.
        now (datetime | None): UTC reference for HTTP-date, default current UTC.
    Returns:
        int | None: Nonnegative delay, bounded for reporting, or no valid delay.
    """
    if value is None:
        return None
    if re.fullmatch(r"[0-9]{1,10}", value):
        return min(int(value), RETRY_REPORT_CAP_SECONDS)
    try:
        deadline = email.utils.parsedate_to_datetime(value)
    except (TypeError, ValueError, IndexError):
        return None
    if deadline is None or deadline.tzinfo is None:
        return None
    reference = now or datetime.now(timezone.utc)
    delay = (deadline - reference).total_seconds()
    if delay < 0:
        return None
    return min(int(delay + 0.999999), RETRY_REPORT_CAP_SECONDS)


def fallback_decision(
    status: int | None,
    failure: str | None,
    *,
    optional_route_missing: bool = False,
    single_authorized: bool = False,
    batch_count: int = 8,
    cancelled: bool = False,
) -> str:
    """Classify conservative B3 design choices without issuing HTTP requests.

    Args:
        status (int | None): Observed HTTP status, or None for failed transport.
        failure (str | None): Transport/protocol failure identifier, if any.
        optional_route_missing (bool): Verified optional batch route absence.
        single_authorized (bool): Independent current single-route authority proof.
        batch_count (int): Proposed number of images in the failed batch.
        cancelled (bool): User initiated cancellation.
    Returns:
        str: Explicit stop, smaller-batch, bounded-retry or authorized fallback
            decision, never an automatic retry or authorization assertion.
    """
    if cancelled:
        return "stop_cancelled"
    if status in (401, 403):
        return "stop_authorization"
    if status == 429:
        return "stop_rate_limited"
    if status in SAFE_OPTIONAL_UNSUPPORTED_STATUSES:
        if optional_route_missing and single_authorized:
            return "single_after_capability_check"
        return "stop_unverified_route"
    if status in (502, 503) or failure in (
        "transport_timeout", "transport_interrupted",
    ):
        return "bounded_retry_only"
    if failure in (
        "invalid_content_type", "invalid_content_length",
        "protocol_invalid", "response_oversize",
        "transport_incomplete",
    ):
        if batch_count > 8 and status == 200:
            return "reduce_batch_after_investigation"
        return "stop_invalid_batch"
    return "stop_no_fallback"


def make_parser(
    protocol: str, content_types: list[str], count: int
) -> FramedParser | MultipartParser:
    """Create the existing strict B1.1 parser after HTTP MIME negotiation.

    Args:
        protocol (str): Requested framed or multipart protocol.
        content_types (list[str]): Unmodified response Content-Type values.
        count (int): Number of requested items, required for multipart.
    Returns:
        FramedParser | MultipartParser: Validated parser for this exact media type.
    Raises:
        ProtocolError: Absent, duplicate, unknown or ambiguous MIME.
    """
    if len(content_types) != 1:
        raise ProtocolError("invalid_content_type")
    content_type = content_types[0]
    if protocol == "framed":
        if content_type != FRAMED_CONTENT_TYPE:
            raise ProtocolError("invalid_content_type")
        return FramedParser()
    if protocol != "multipart":
        raise ProtocolError("unknown_http_protocol")
    prefix = "multipart/mixed; boundary="
    if not content_type.startswith(prefix):
        raise ProtocolError("invalid_content_type")
    boundary = content_type[len(prefix):]
    if not BOUNDARY_PATTERN.fullmatch(boundary):
        raise ProtocolError("invalid_content_type")
    return MultipartParser(count, boundary)


def validate_content_length(headers: list[str]) -> int | None:
    """Reject ambiguous or excessive declared body sizes before allocation.

    Args:
        headers (list[str]): All untrusted HTTP Content-Length field values.
    Returns:
        int | None: One admissible content length or absent length.
    Raises:
        ProtocolError: Duplicate, malformed, negative or oversized length.
    """
    if not headers:
        return None
    if len(headers) != 1 or re.fullmatch(r"[0-9]+", headers[0]) is None:
        raise ProtocolError("invalid_content_length")
    length = int(headers[0])
    if length > HTTP_BODY_LIMIT:
        raise ProtocolError("response_oversize")
    return length


def current_rss_bytes() -> int | None:
    """Observe resident bytes without installing platform-specific packages.

    Returns:
        int | None: Linux /proc resident bytes, otherwise unavailable.
    """
    try:
        fields = Path("/proc/self/statm").read_text(encoding="ascii").split()
        return int(fields[1]) * os.sysconf("SC_PAGE_SIZE")
    except (OSError, ValueError, IndexError, AttributeError):
        return None


def request_batch(
    port: int,
    protocol: str,
    count: int,
    *,
    sequence: int,
    scenario: str = "cold",
    mode: str = "stream",
    fault: str = "none",
    chunk_bytes: int = LARGE_HTTP_CHUNK,
    cancellation: str = "none",
    timeout_seconds: float = HTTP_TIMEOUT_SECONDS,
) -> dict[str, Any]:
    """Observe one actual HTTP batch and preserve only checked item summaries.

    Args:
        port (int): Temporary loopback PHP HTTP server port.
        protocol (str): B1.1 framed or multipart wire variant.
        count (int): Expected request item count.
        sequence (int): Unique bounded fixture measurement identifier.
        scenario (str): Cold or mixed warm synthetic manifest.
        mode (str): PHP streaming, delayed or fully buffered mode.
        fault (str): Named deterministic HTTP fault injection.
        chunk_bytes (int): Bytes read from HTTPResponse after first byte.
        cancellation (str): Optional explicit cancellation checkpoint.
        timeout_seconds (float): Bounded socket and header wait deadline.
    Returns:
        dict[str, Any]: Sanitized monotonic durations, checked item statuses,
            body bytes, resource observations and failure classification.
    Raises:
        ValueError: Invalid cancellation or read mode.
    """
    if cancellation not in CANCELLATION_POINTS or chunk_bytes < 1:
        raise ValueError("invalid_http_reader_options")
    query = urlencode({
        "protocol": protocol, "count": count, "case": scenario,
        "mode": mode, "fault": fault, "sequence": sequence,
    })
    connection = http.client.HTTPConnection(
        "127.0.0.1", port, timeout=timeout_seconds
    )
    started = time.perf_counter_ns()
    observed: dict[str, Any] = {
        "protocol": protocol, "items_requested": count, "case": scenario,
        "mode": mode, "fault_case": fault, "cancel_point": cancellation,
        "requests_sent": 0, "body_bytes": 0,
        "headers_ms": None, "first_byte_ms": None,
        "first_verified_ms": None, "complete_ms": None,
        "parser_ms": 0.0, "python_tracemalloc_peak_bytes": None,
        "python_rss_peak_observed_bytes": current_rss_bytes(),
        "validated": [], "complete": False, "http_status": None,
        "failure": None, "retry_after_seconds": None,
    }
    parser_ns = 0
    response = None
    tracemalloc.start()
    try:
        connection.request("GET", "/batch?" + query)
        observed["requests_sent"] = 1
        if cancellation == "before_headers":
            observed["failure"] = "cancelled"
            return observed
        response = connection.getresponse()
        observed["headers_ms"] = (
            time.perf_counter_ns() - started
        ) / 1_000_000
        observed["http_status"] = response.status
        observed["retry_after_seconds"] = parse_retry_after(
            response.getheader("Retry-After")
        )
        if response.status != 200:
            observed["failure"] = "http_" + str(response.status)
            return observed
        parser = make_parser(
            protocol, response.headers.get_all("Content-Type", []), count
        )
        declared_length = validate_content_length(
            response.headers.get_all("Content-Length", [])
        )
        if cancellation == "buffered" and mode != "buffer":
            raise ValueError("buffered_cancel_requires_buffer_mode")
        first = response.read(FIRST_BYTE_READ)
        if not first:
            observed["failure"] = "transport_incomplete"
            return observed
        observed["first_byte_ms"] = (
            time.perf_counter_ns() - started
        ) / 1_000_000
        observed["body_bytes"] += len(first)
        if cancellation in ("first_body", "buffered"):
            observed["failure"] = "cancelled"
            return observed

        def feed_bytes(data: bytes) -> None:
            """Feed already received bytes and keep no unchecked image payload.

            Args:
                data (bytes): One delivered response fragment.
            Returns:
                None: None.
            """
            nonlocal parser_ns
            parse_start = time.perf_counter_ns()
            items = list(parser.feed(data))
            parser_ns += time.perf_counter_ns() - parse_start
            for item in items:
                observed["validated"].append({
                    "image_id": item.image_id, "status": item.status,
                    "bytes": len(item.data), "etag": item.etag,
                })
                if observed["first_verified_ms"] is None:
                    observed["first_verified_ms"] = (
                        time.perf_counter_ns() - started
                    ) / 1_000_000

        if mode == "buffer":
            chunks = bytearray(first)
            while chunk := response.read(chunk_bytes):
                if len(chunk) > HTTP_BODY_LIMIT - len(chunks):
                    raise ProtocolError("response_oversize")
                chunks.extend(chunk)
                observed["body_bytes"] += len(chunk)
            feed_bytes(bytes(chunks))
        else:
            feed_bytes(first)
            while chunk := response.read(chunk_bytes):
                observed["body_bytes"] += len(chunk)
                feed_bytes(chunk)
                if cancellation == "during_first" and (
                    not observed["validated"] and len(parser.payload) > 0
                ):
                    # The first item's actual binary bytes have arrived,
                    # but its SHA-256 cannot yet be validated or published.
                    observed["failure"] = "cancelled"
                    return observed
                if cancellation == "slow":
                    time.sleep(SLOW_CONSUMER_SECONDS)
                    if observed["body_bytes"] >= (
                        SMALL_HTTP_CHUNK * SLOW_CANCEL_READ_CHUNKS
                    ):
                        observed["failure"] = "cancelled"
                        return observed
                if cancellation == "after_first" and (
                    len(observed["validated"]) >= 1
                ):
                    observed["failure"] = "cancelled"
                    return observed
                if cancellation == "after_two" and (
                    len(observed["validated"]) >= 2
                ):
                    observed["failure"] = "cancelled"
                    return observed
                if mode == "delayed":
                    time.sleep(SLOW_CONSUMER_SECONDS)
        # A valid B1 end marker cannot override an incorrect HTTP envelope.
        if declared_length is not None and (
            observed["body_bytes"] != declared_length
        ):
            raise ProtocolError("invalid_content_length")
        finish_start = time.perf_counter_ns()
        parser.finish()
        parser_ns += time.perf_counter_ns() - finish_start
        if len(observed["validated"]) != count:
            raise ProtocolError("wrong_completed_item_count")
        observed["complete"] = True
        observed["complete_ms"] = (
            time.perf_counter_ns() - started
        ) / 1_000_000
    except socket.timeout:
        observed["failure"] = "transport_timeout"
    except http.client.IncompleteRead:
        observed["failure"] = "transport_incomplete"
    except (ConnectionError, BrokenPipeError, OSError, http.client.HTTPException):
        observed["failure"] = "transport_interrupted"
    except ProtocolError as error:
        observed["failure"] = (
            "invalid_content_type" if str(error) == "invalid_content_type"
            else "response_oversize" if str(error) == "response_oversize"
            else "invalid_content_length" if str(error) == "invalid_content_length"
            else "protocol_invalid"
        )
        observed["fault_detail"] = str(error)
    finally:
        observed["parser_ms"] = parser_ns / 1_000_000
        _, memory_peak = tracemalloc.get_traced_memory()
        observed["python_tracemalloc_peak_bytes"] = memory_peak
        tracemalloc.stop()
        rss = current_rss_bytes()
        if rss is not None:
            observed["python_rss_peak_observed_bytes"] = max(
                observed["python_rss_peak_observed_bytes"] or 0, rss
            )
        if response is not None:
            response.close()
        connection.close()
        observed["elapsed_ms"] = (
            time.perf_counter_ns() - started
        ) / 1_000_000
        observed["decision"] = fallback_decision(
            observed["http_status"], observed["failure"],
            batch_count=count, cancelled=observed["failure"] == "cancelled",
        )
    return observed


def request_single(
    port: int,
    index: int,
    sequence: int,
    *,
    expected_sha256: str,
    expected_mime: str,
    conditional_etag: str | None = None,
) -> dict[str, Any]:
    """Measure one synthetic raw-image HTTP request and optional 304 validator.

    Args:
        port (int): Disposable PHP server port.
        index (int): Zero-based synthetic fixture index.
        sequence (int): Unique metric request identifier.
        expected_sha256 (str): Trusted local fixture SHA-256.
        expected_mime (str): Trusted synthetic image MIME.
        conditional_etag (str | None): Known safe local validator to revalidate.
    Returns:
        dict[str, Any]: HTTP status, actual body bytes, monotonic timing
            and integrity result with no unvalidated image publication.
    """
    query = urlencode({"index": index, "sequence": sequence})
    connection = http.client.HTTPConnection(
        "127.0.0.1", port, timeout=HTTP_TIMEOUT_SECONDS
    )
    started = time.perf_counter_ns()
    headers = {}
    if conditional_etag is not None:
        headers["If-None-Match"] = conditional_etag
    received = 0
    first_ms = None
    response = None
    try:
        connection.request("GET", "/single?" + query, headers=headers)
        response = connection.getresponse()
        header_ms = (time.perf_counter_ns() - started) / 1_000_000
        if response.status == 304 and (
            conditional_etag is not None
            and response.getheader("ETag") == conditional_etag
        ):
            return {
                "http_status": 304, "requests_sent": 1, "body_bytes": 0,
                "complete_ms": (time.perf_counter_ns() - started) / 1_000_000,
                "first_verified_ms": None, "headers_ms": header_ms,
                "cache_validated": True, "complete": True,
            }
        if response.status != 200 or (
            response.getheader("Content-Type") != expected_mime
        ):
            raise ProtocolError("invalid_single_http_response")
        digest = hashlib.sha256()
        first = response.read(FIRST_BYTE_READ)
        if first:
            first_ms = (time.perf_counter_ns() - started) / 1_000_000
            digest.update(first)
            received += len(first)
        while chunk := response.read(LARGE_HTTP_CHUNK):
            received += len(chunk)
            if received > HTTP_BODY_LIMIT:
                raise ProtocolError("response_oversize")
            digest.update(chunk)
        if digest.hexdigest() != expected_sha256:
            raise ProtocolError("invalid_single_digest")
        completed = (time.perf_counter_ns() - started) / 1_000_000
        return {
            "http_status": 200, "requests_sent": 1,
            "body_bytes": received, "headers_ms": header_ms,
            "first_byte_ms": first_ms,
            "first_verified_ms": completed, "complete_ms": completed,
            "cache_validated": False, "complete": True,
        }
    finally:
        if response is not None:
            response.close()
        connection.close()


def wait_healthy(port: int, process: subprocess.Popen[bytes]) -> None:
    """Require an isolated PHP loopback server before test requests.

    Args:
        port (int): Selected free loopback port.
        process (subprocess.Popen[bytes]): PHP development server child.
    Returns:
        None: None.
    Raises:
        RuntimeError: Process exits or fails to answer within finite startup.
    """
    deadline = time.monotonic() + SERVER_STARTUP_SECONDS
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError("disposable_php_server_exited")
        try:
            conn = http.client.HTTPConnection(
                "127.0.0.1", port, timeout=0.25
            )
            try:
                conn.request("GET", "/health")
                reply = conn.getresponse()
                if reply.status == 200 and reply.read() == b"ok":
                    return
            finally:
                conn.close()
        except (OSError, http.client.HTTPException):
            time.sleep(HEALTH_POLL_SECONDS)
    raise RuntimeError("disposable_php_server_unavailable")


@contextlib.contextmanager
def temporary_server(
    fixture_root: Path, log_file: Path
) -> Iterator[int]:
    """Start and always reap exactly one CI-only PHP development server.

    Args:
        fixture_root (Path): Disposable canonical synthetic JPEG/WebP root.
        log_file (Path): Temporary runner-owned PHP stdout/stderr log.
    Returns:
        Iterator[int]: Ephemeral 127.0.0.1 TCP port.
    Raises:
        RuntimeError: CI guard or server startup fails.
    """
    if (
        os.environ.get("GITHUB_ACTIONS") != "true"
        or os.environ.get("GALLERY_WORKFLOW_ENABLE") != "disposable-only"
    ):
        raise RuntimeError("disposable_ci_only")
    router = Path(__file__).with_name("issue_362_b1_http_router.php")
    with socket.socket() as reservation:
        reservation.bind(("127.0.0.1", 0))
        port = reservation.getsockname()[1]
    environment = os.environ.copy()
    environment["B1_HTTP_FIXTURE_ROOT"] = str(fixture_root.resolve())
    environment.pop("PHP_CLI_SERVER_WORKERS", None)
    with log_file.open("wb") as output:
        process = subprocess.Popen(
            ["php", "-d", "output_buffering=0", "-d",
             "zlib.output_compression=0", "-S", "127.0.0.1:" + str(port),
             str(router)],
            stdout=output, stderr=subprocess.STDOUT,
            env=environment, cwd=Path(__file__).resolve().parents[2],
        )
        try:
            wait_healthy(port, process)
            yield port
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=SERVER_EXIT_SECONDS)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=SERVER_EXIT_SECONDS)
            if process.poll() is None:
                raise RuntimeError("disposable_php_server_cleanup_failed")
