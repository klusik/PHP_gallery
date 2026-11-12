# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_b1_http_benchmark.py
# Module Type: Disposable HTTP Qualification Orchestrator
# Purpose: Qualify B1.2 real loopback HTTP and explicitly simulated faults.
# Responsibilities:
#   - Reuse deterministic B1.1 JPEG/WebP fixtures and strict parsers.
#   - Measure framed, MIME and isolated raw single-item HTTP requests.
#   - Validate HTTP errors, interruptions, cancellation and safe cleanup.
#   - Retain exact-SHA sanitized Markdown/JSON evidence from GitHub Actions.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Synthetic GitHub-only HTTP experiments; not a production hosting model."""

import hashlib
import http.client
import json
import os
import re
import statistics
import subprocess
import sys
import tempfile
import time
import tracemalloc
from datetime import datetime, timezone
from itertools import count
from pathlib import Path
from typing import Any, Iterator
from urllib.parse import urlencode

from tools.perf.issue_362_b1_benchmark import (
    ITEM_COUNTS,
    PROTOCOLS,
    build_fixtures,
    percentile,
)
from tools.perf.issue_362_b1_http import (
    HTTP_TIMEOUT_SECONDS,
    LARGE_HTTP_CHUNK,
    SMALL_HTTP_CHUNK,
    current_rss_bytes,
    fallback_decision,
    request_batch,
    request_single,
    temporary_server,
)

# Purpose: Bound repeated HTTP cells while retaining descriptive quantiles.
# Type: int. Units: repeats per case. Scope: B1.2 loopback benchmark.
# Consumers: run_benchmark. Rationale: three samples yield p50 and a
# descriptive interpolated p95 without slow, timing-gated CI.
HTTP_REPEATS = 3

# Purpose: Compare actual PHP streaming with whole-body PHP buffering.
# Type: tuple[str, ...]. Units: PHP response mode labels. Scope: B1.2.
# Consumers: run_benchmark. Rationale: both modes share source fixtures.
HTTP_MODES = ("stream", "buffer")

# Purpose: Compare cold fetch against synthetic mixed-cache revalidation.
# Type: tuple[str, ...]. Units: cache profile labels. Scope: B1.2.
# Consumers: write_manifests, run_benchmark. Rationale: avoid treating
# cache hits as payload downloads or as an authorization proof.
CACHE_CASES = ("cold", "warm")

# Purpose: Exercise delayed delivery without expanding the main matrix.
# Type: tuple[int, ...]. Units: images. Scope: timed stress subset.
# Consumers: run_benchmark. Rationale: smallest and largest batches
# bracket first-item delay with limited CI work.
DELAYED_COUNTS = (8, 32)

# Purpose: Limit intentionally slow transport probes.
# Type: int. Units: byte per client read. Scope: transport faults.
# Consumers: validate_failures. Rationale: detect first-item delivery
# before later injected stalls without large read coalescing.
FAULT_READ_BYTES = 1024

# Purpose: Bound a fault fixture's socket deadline below the server stall.
# Type: float. Units: seconds. Scope: simulated timeout probes.
# Consumers: validate_failures. Rationale: 100 ms creates a deterministic
# time-out against the router's 300 ms stall without slow CI.
FAULT_TIMEOUT_SECONDS = 0.1

# Purpose: Retry a just-finished server metric file only for filesystem race.
# Type: int. Units: reads. Scope: one CI-only sample.
# Consumers: read_metric. Rationale: bounded lookup, not an HTTP retry.
METRIC_READ_ATTEMPTS = 8

# Purpose: Cap filesystem metric wait without CPU spin.
# Type: float. Units: seconds/poll. Scope: one metric file read.
# Consumers: read_metric. Rationale: 10 ms tolerates post-response flush.
METRIC_POLL_SECONDS = 0.01

# Purpose: Retain one explicit schema identity for reproducible evidence.
# Type: str. Units: report schema identifier. Scope: CI JSON output.
# Consumers: run_benchmark. Rationale: distinguish actual HTTP B1.2
# observations from synthetic local-file B1.1 data.
REPORT_SCHEMA = "issue-362-b1.2-http-v1"

# Purpose: Label the baseline mode without pretending to run production APIs.
# Type: str. Units: comparison category label. Scope: isolated CI.
# Consumers: benchmark_single. Rationale: raw single HTTP calls only
# approximate the original one-request-per-thumbnail transport principle.
SINGLE_LABEL = "single_raw_reference"


def write_manifests(
    root: Path, fixtures: list[dict[str, str]]
) -> dict[str, dict[int, list[dict[str, str]]]]:
    """Construct cold and mixed warm manifests without production metadata.

    Args:
        root (Path): Owned disposable image and manifest directory.
        fixtures (list[dict[str, str]]): Existing deterministic B1.1 images.
    Returns:
        dict[str, dict[int, list[dict[str, str]]]]: Inputs keyed by
            cache profile and requested count.
    """
    cases: dict[str, dict[int, list[dict[str, str]]]] = {}
    for cache_case in CACHE_CASES:
        cases[cache_case] = {}
        for count_value in ITEM_COUNTS:
            selected: list[dict[str, str]] = []
            for index, fixture in enumerate(fixtures[:count_value]):
                if cache_case == "warm" and index % 4 != 0:
                    digest = hashlib.sha256(
                        (root / fixture["file"]).read_bytes()
                    ).hexdigest()
                    selected.append({
                        "image_id": fixture["image_id"],
                        "status": "not_modified",
                        "etag": '"' + digest + '"',
                    })
                else:
                    selected.append(dict(fixture))
            path = root / (
                "manifest_" + str(count_value) + "_" + cache_case + ".json"
            )
            path.write_text(
                json.dumps({"root": str(root), "items": selected}),
                encoding="utf-8",
            )
            cases[cache_case][count_value] = selected
    return cases


def validate_items(
    root: Path,
    selected: list[dict[str, str]],
    observed: dict[str, Any],
) -> None:
    """Assert that HTTP yielded each exact requested, verified item outcome.

    Args:
        root (Path): Canonical synthetic image fixture root.
        selected (list[dict[str, str]]): Ordered cold/warm manifest descriptors.
        observed (dict[str, Any]): Strict HTTP parser's sanitized observations.
    Returns:
        None: None.
    Raises:
        AssertionError: Missing, extra, invalid or mismatched status bytes.
    """
    assert observed["complete"], observed
    assert observed["failure"] is None, observed
    assert len(observed["validated"]) == len(selected), observed
    for source, item in zip(selected, observed["validated"], strict=True):
        assert item["image_id"] == source["image_id"]
        assert item["status"] == source["status"]
        if item["status"] == "ok":
            assert item["bytes"] == (
                root / source["file"]
            ).stat().st_size
        else:
            assert item["bytes"] == 0
            assert item["etag"] == source["etag"]


def read_metric(root: Path, sequence: int) -> dict[str, Any]:
    """Read the server's SHA-bound, synthetic-only post-request metrics.

    Args:
        root (Path): Disposable root shared by the owned PHP server.
        sequence (int): Exact assigned request identifier.
    Returns:
        dict[str, Any]: Producer time, SHA duration and peak allocator bytes.
    Raises:
        RuntimeError: A finished request has no valid metric record.
    """
    path = root / ("metric_" + str(sequence) + ".json")
    for attempt in range(METRIC_READ_ATTEMPTS):
        if path.is_file():
            record = json.loads(path.read_text(encoding="utf-8"))
            if all(key in record for key in (
                "producer_ms", "sha256_ms", "php_peak_bytes", "wire_bytes",
            )):
                return record
        if attempt < METRIC_READ_ATTEMPTS - 1:
            time.sleep(METRIC_POLL_SECONDS)
    raise RuntimeError("php_metric_missing_" + str(sequence))


def payload_distribution(
    root: Path, selected: list[dict[str, str]]
) -> dict[str, int]:
    """Distinguish transferred bytes, local cache hits and validators.

    Args:
        root (Path): Temporary image directory.
        selected (list[dict[str, str]]): Manifest metadata with cache statuses.
    Returns:
        dict[str, int]: Source bytes needed, local cache bytes and validator count.
    """
    transferred = 0
    locally_cached = 0
    validators = 0
    for entry in selected:
        if entry["status"] == "ok":
            transferred += (root / entry["file"]).stat().st_size
        else:
            validators += 1
            fixture_index = int(entry["image_id"]) - 1
            fixture_name = (
                "thumb_%03d.%s" % (
                    fixture_index, "webp" if fixture_index % 2 == 0 else "jpg"
                )
            )
            locally_cached += (root / fixture_name).stat().st_size
    return {
        "payload_required_bytes": transferred,
        "cached_local_bytes": locally_cached,
        "validator_items": validators,
    }


def summarize_samples(
    protocol: str, item_count: int, cache_case: str,
    mode: str, samples: list[dict[str, Any]],
    distribution: dict[str, int],
) -> dict[str, Any]:
    """Aggregate compatible HTTP observations with descriptive p50/p95 only.

    Args:
        protocol (str): Experimental wire candidate or raw-single reference.
        item_count (int): Number of originally requested images.
        cache_case (str): Cold transfer or mixed cache.
        mode (str): Actual PHP stream, buffer or delayed producer.
        samples (list[dict[str, Any]]): Verified observations of one cell.
        distribution (dict[str, int]): Actual source/cache/validator counts.
    Returns:
        dict[str, Any]: Explicit request, body, protocol overhead and percentiles.
    """
    first = samples[0]
    row: dict[str, Any] = {
        "protocol": protocol, "items": item_count, "cache": cache_case,
        "mode": mode, "repeats": len(samples),
        "requests": first["requests_sent"],
        "body_bytes": first["body_bytes"],
        "overhead_bytes": (
            first["body_bytes"] - distribution["payload_required_bytes"]
        ),
        **distribution,
    }
    for field in (
        "headers_ms", "first_byte_ms", "first_verified_ms",
        "complete_ms", "parser_ms", "producer_ms", "sha256_ms",
        "php_peak_bytes", "python_tracemalloc_peak_bytes",
        "python_rss_peak_observed_bytes",
    ):
        values = [
            float(sample[field]) for sample in samples
            if sample.get(field) is not None
        ]
        row[field + "_p50"] = (
            round(statistics.median(values), 3) if values else None
        )
        row[field + "_p95"] = (
            round(percentile(values, 0.95), 3) if values else None
        )
    return row


def benchmark_batch(
    root: Path, port: int,
    selected: list[dict[str, str]], protocol: str,
    cache_case: str, mode: str, identifiers: Iterator[int],
) -> list[dict[str, Any]]:
    """Measure a complete cell through actual HTTP rather than local files.

    Args:
        root (Path): Disposable fixture and metric directory.
        port (int): Owned loopback PHP server.
        selected (list[dict[str, str]]): Exact fixed manifest for the cell.
        protocol (str): Framed or multipart.
        cache_case (str): Cold or warm source subset.
        mode (str): PHP streamed, fully buffered or delayed mode.
        identifiers (Iterator[int]): Unique sequence values for metrics.
    Returns:
        list[dict[str, Any]]: Validated samples with separable metrics.
    """
    samples: list[dict[str, Any]] = []
    for repetition in range(HTTP_REPEATS):
        sequence = next(identifiers)
        observed = request_batch(
            port, protocol, len(selected), sequence=sequence,
            scenario=cache_case, mode=mode,
            chunk_bytes=(
                SMALL_HTTP_CHUNK if mode == "delayed"
                else LARGE_HTTP_CHUNK
            ),
        )
        validate_items(root, selected, observed)
        metric = read_metric(root, sequence)
        assert metric["wire_bytes"] == observed["body_bytes"]
        samples.append({
            **observed,
            "producer_ms": metric["producer_ms"],
            "sha256_ms": metric["sha256_ms"],
            "php_peak_bytes": metric["php_peak_bytes"],
        })
    return samples


def benchmark_single(
    root: Path, port: int,
    fixtures: list[dict[str, str]],
    count_value: int, cache_case: str,
    identifiers: Iterator[int],
) -> list[dict[str, Any]]:
    """Measure one real HTTP request per thumbnail as an isolated reference.

    Args:
        root (Path): Synthetic JPEG/WebP directory.
        port (int): Temporary loopback server port.
        fixtures (list[dict[str, str]]): Fixed B1.1 source images.
        count_value (int): Original desired image count.
        cache_case (str): Full raw fetch or 75% 304 validator hits.
        identifiers (Iterator[int]): Request-local metric identity source.
    Returns:
        list[dict[str, Any]]: Aggregate sequential N-request measurements.
    """
    samples: list[dict[str, Any]] = []
    for repetition in range(HTTP_REPEATS):
        tracemalloc.start()
        started = time.perf_counter_ns()
        body_bytes = 0
        php_ms = 0.0
        hash_ms = 0.0
        php_peak = 0
        headers_ms = None
        first_byte_ms = None
        first_verified_ms = None
        for index, fixture in enumerate(fixtures[:count_value]):
            digest = hashlib.sha256(
                (root / fixture["file"]).read_bytes()
            ).hexdigest()
            etag = '"' + digest + '"'
            conditional = (
                etag if cache_case == "warm" and index % 4 != 0
                else None
            )
            sequence = next(identifiers)
            request_started = time.perf_counter_ns()
            measurement = request_single(
                port, index, sequence, expected_sha256=digest,
                expected_mime=fixture["mime"], conditional_etag=conditional,
            )
            assert measurement["complete"]
            assert measurement["body_bytes"] == (
                0 if conditional else (root / fixture["file"]).stat().st_size
            )
            body_bytes += measurement["body_bytes"]
            metric = read_metric(root, sequence)
            php_ms += metric["producer_ms"]
            hash_ms += metric["sha256_ms"]
            php_peak = max(php_peak, metric["php_peak_bytes"])
            if headers_ms is None:
                headers_ms = (
                    request_started - started
                ) / 1_000_000 + measurement["headers_ms"]
            if first_byte_ms is None and (
                measurement.get("first_byte_ms") is not None
            ):
                first_byte_ms = (
                    request_started - started
                ) / 1_000_000 + measurement["first_byte_ms"]
            if first_verified_ms is None and (
                measurement.get("first_verified_ms") is not None
            ):
                first_verified_ms = (
                    request_started - started
                ) / 1_000_000 + measurement["first_verified_ms"]
        _, allocation_peak = tracemalloc.get_traced_memory()
        tracemalloc.stop()
        process_rss = current_rss_bytes()
        samples.append({
            "requests_sent": count_value, "body_bytes": body_bytes,
            "headers_ms": headers_ms,
            "first_byte_ms": first_byte_ms,
            "first_verified_ms": first_verified_ms,
            "complete_ms": (
                time.perf_counter_ns() - started
            ) / 1_000_000,
            "parser_ms": 0.0,
            "producer_ms": php_ms,
            "sha256_ms": hash_ms,
            "php_peak_bytes": php_peak,
            "python_tracemalloc_peak_bytes": allocation_peak,
            "python_rss_peak_observed_bytes": process_rss,
        })
    return samples


def inspect_status(
    port: int, status: int, retry: str | None = None
) -> tuple[int, str | None]:
    """Check real HTTP response status and optional untrusted retry header.

    Args:
        port (int): Owned loopback server.
        status (int): Requested deterministic HTTP status.
        retry (str | None): Optional Retry-After fixture value.
    Returns:
        tuple[int, str | None]: Actual HTTP status and raw delay field.
    """
    arguments = {"code": status}
    if retry is not None:
        arguments["retry"] = retry
    connection = http.client.HTTPConnection(
        "127.0.0.1", port, timeout=HTTP_TIMEOUT_SECONDS
    )
    try:
        connection.request("GET", "/status?" + urlencode(arguments))
        response = connection.getresponse()
        try:
            return response.status, response.getheader("Retry-After")
        finally:
            response.read()
            response.close()
    finally:
        connection.close()


def validate_failures(
    root: Path, port: int, identifiers: Iterator[int],
) -> list[dict[str, Any]]:
    """Prove specified HTTP status, corruption and cancellation decisions.

    Args:
        root (Path): Synthetic source and metric directory.
        port (int): Owned isolated PHP server.
        identifiers (Iterator[int]): Bounded exact metric identifiers.
    Returns:
        list[dict[str, Any]]: Sanitized decision and completion records.
    Raises:
        AssertionError: Unsafe HTTP handling, invalid publication or retry.
    """
    checks: list[dict[str, Any]] = []
    for status in (400, 401, 403, 404, 405, 429, 502, 503):
        actual, header = inspect_status(port, status)
        assert actual == status
        decision = fallback_decision(
            status, "http_" + str(status), batch_count=32
        )
        assert decision != "single_after_capability_check"
        checks.append({
            "case": "http_" + str(status), "http_status": actual,
            "decision": decision, "result": "PASS",
        })
    for retry in ("2", "bad", "-5", ""):
        actual, header = inspect_status(port, 429, retry)
        assert actual == 429
        checks.append({
            "case": "retry_after_" + (retry or "empty"),
            "http_status": actual, "raw_header": header,
            "decision": "stop_rate_limited", "result": "PASS",
        })
    for protocol in PROTOCOLS:
        for fault in (
            "html", "wrong_type", "no_type", "wrong_length",
            "short_header", "short_image", "between_items",
            "missing_end", "bad_digest", "unknown_version",
            "duplicate_id", "bad_etag", "invalid_body",
            "oversize_length", "stall_before_headers",
            "stall_before_body", "stall_between_items",
        ):
            result = request_batch(
                port, protocol, 8, sequence=next(identifiers),
                mode="stream", fault=fault,
                chunk_bytes=FAULT_READ_BYTES,
                timeout_seconds=(
                    FAULT_TIMEOUT_SECONDS if fault.startswith("stall_")
                    else HTTP_TIMEOUT_SECONDS
                ),
            )
            assert not result["complete"], (protocol, fault, result)
            assert result["failure"] is not None, (protocol, fault)
            if fault in ("bad_digest", "short_image", "short_header",
                         "unknown_version", "bad_etag"):
                assert not result["validated"], (protocol, fault, result)
            assert all(
                item["status"] in (
                    "ok", "not_modified", "unavailable", "oversize"
                )
                for item in result["validated"]
            )
            checks.append({
                "case": protocol + "_" + fault,
                "failure": result["failure"],
                "previously_validated_items": len(result["validated"]),
                "decision": result["decision"],
                "result": "PASS",
            })
        for point in (
            "before_headers", "first_body", "during_first",
            "after_first", "after_two", "buffered", "slow",
        ):
            mode = "buffer" if point == "buffered" else "delayed"
            result = request_batch(
                port, protocol, 8, sequence=next(identifiers),
                mode=mode, cancellation=point,
                chunk_bytes=FAULT_READ_BYTES,
            )
            assert not result["complete"], (protocol, point, result)
            assert result["failure"] == "cancelled", result
            assert result["decision"] == "stop_cancelled", result
            if point in (
                "before_headers", "first_body", "during_first",
                "buffered", "slow"
            ):
                assert not result["validated"], result
            checks.append({
                "case": protocol + "_cancel_" + point,
                "previously_validated_items": len(result["validated"]),
                "decision": result["decision"],
                "cancellation_ms": round(result["elapsed_ms"], 3),
                "result": "PASS",
            })
    return checks


def render_markdown(report: dict[str, Any]) -> str:
    """Render reproducible observations without claiming hosting portability.

    Args:
        report (dict[str, Any]): Completed exact-SHA JSON data.
    Returns:
        str: Human-readable benchmark and failure matrix.
    """
    lines = [
        "# Issue #362 B1.2 isolated loopback HTTP benchmark",
        "",
        "Commit: " + report["commit_sha"],
        "PHP: " + report["php_version"] + ", Python: "
        + report["python_version"],
        "",
        "All network observations used **real HTTP to 127.0.0.1** on a "
        "disposable PHP development server. Buffering, delays, corruption "
        "and status injection were **controlled simulations**. "
        "No proxy, PHP-FPM pool, HTTPS, real gallery, or authorization "
        "was measured. Single-item HTTP is a raw synthetic reference.",
        "",
        "Three repetitions per cell, interpolated descriptive p95. "
        "Timing is not a pass/fail gate.",
        "",
        "| Protocol | Count | Cache | Mode | Requests | HTTP body KiB | "
        "Overhead KiB | First valid p50/p95 ms | Complete p50/p95 ms | "
        "PHP p50 ms | PHP peak MiB | Python alloc peak MiB |",
        "| --- | ---: | --- | --- | ---: | ---: | ---: | --- | "
        "--- | ---: | ---: | ---: |",
    ]
    for row in report["cases"]:
        def format_time(key: str) -> str:
            """Format one nullable metric pair for the Markdown table.

            Args:
                key (str): Exact reported timing metric prefix.
            Returns:
                str: Human-readable p50/p95 or unmeasured marker.
            """
            median = row[key + "_p50"]
            upper = row[key + "_p95"]
            return (
                "%.2f / %.2f" % (median, upper)
                if median is not None else "n/a"
            )
        php_peak = row["php_peak_bytes_p50"]
        python_peak = row["python_tracemalloc_peak_bytes_p50"]
        lines.append(
            "| %s | %s | %s | %s | %s | %.1f | %.1f | %s | "
            "%s | %s | %.2f | %s |" % (
                row["protocol"], row["items"], row["cache"],
                row["mode"], row["requests"],
                row["body_bytes"] / 1024,
                row["overhead_bytes"] / 1024,
                format_time("first_verified_ms"),
                format_time("complete_ms"),
                format_time("producer_ms"),
                php_peak / (1024 * 1024) if php_peak else 0.0,
                "%.2f" % (python_peak / (1024 * 1024))
                if python_peak is not None else "n/a",
            )
        )
    lines += [
        "",
        "## HTTP failure and cancellation qualification",
        "",
        "| Scenario | Observed failure | Validated earlier items | Policy |",
        "| --- | --- | ---: | --- |",
    ]
    for check in report["failure_checks"]:
        lines.append(
            "| %s | %s | %s | %s |" % (
                check["case"], check.get("failure", "status observed"),
                check.get("previously_validated_items", 0),
                check["decision"],
            )
        )
    lines += [
        "",
        "Actual byte arrival and header timing are measured by Python's "
        "monotonic clock; parser CPU wall segments, PHP producer and "
        "SHA-256 cost, PHP allocator peak and Python allocation peak "
        "are reported separately in JSON. Python /proc RSS is a "
        "process-wide observation, not an isolated per-request peak. "
        "Total single-reference duration includes sequential N HTTP "
        "requests, not a sum of parser times.",
        "",
        "No result demonstrates FPM/proxy/TLS flush forwarding, "
        "real-host worker capacity, access grants, cache proof, UI paint "
        "latency or production route behavior.",
    ]
    return "\n".join(lines) + "\n"


def run_benchmark(destination: Path) -> dict[str, Any]:
    """Run bounded exact-SHA GitHub experiments and write sanitized artifacts.

    Args:
        destination (Path): Job-owned output directory for CI upload.
    Returns:
        dict[str, Any]: Qualified report with test and benchmark results.
    Raises:
        RuntimeError: Missing CI identity or unavailable PHP server.
        AssertionError: A strict protocol or failure scenario is incorrect.
    """
    sha = os.environ.get("GITHUB_SHA", "")
    if not (
        os.environ.get("GITHUB_ACTIONS") == "true"
        and os.environ.get("GALLERY_WORKFLOW_ENABLE") == "disposable-only"
        and re.fullmatch(r"[0-9a-f]{40}", sha)
    ):
        raise RuntimeError("B1.2 HTTP qualification requires exact-SHA CI")
    php = subprocess.run(
        ["php", "-r", "echo PHP_VERSION;"],
        capture_output=True, text=True, timeout=HTTP_TIMEOUT_SECONDS,
        check=True,
    ).stdout
    report: dict[str, Any] = {
        "schema": REPORT_SCHEMA, "commit_sha": sha,
        "created_utc": datetime.now(timezone.utc).isoformat(),
        "php_version": php, "python_version": sys.version.split()[0],
        "environment": "github_actions_php_cli_server_real_loopback_only",
        "measurement_repeats": HTTP_REPEATS, "cases": [],
        "failure_checks": [], "cleanup": "not_started",
    }
    with tempfile.TemporaryDirectory(
        prefix="issue362-b1-http-"
    ) as directory:
        root = Path(directory).resolve()
        fixtures = build_fixtures(root)
        manifests = write_manifests(root, fixtures)
        identifiers = count(1)
        selected_port = None
        with temporary_server(root, root / "php_server.log") as port:
            selected_port = port
            for protocol in PROTOCOLS:
                for cache_case in CACHE_CASES:
                    for count_value in ITEM_COUNTS:
                        selected = manifests[cache_case][count_value]
                        profile = payload_distribution(root, selected)
                        for mode in HTTP_MODES + (
                            ("delayed",) if count_value in DELAYED_COUNTS else ()
                        ):
                            samples = benchmark_batch(
                                root, port, selected, protocol,
                                cache_case, mode, identifiers,
                            )
                            report["cases"].append(summarize_samples(
                                protocol, count_value, cache_case, mode,
                                samples, profile,
                            ))
            for cache_case in CACHE_CASES:
                for count_value in ITEM_COUNTS:
                    samples = benchmark_single(
                        root, port, fixtures, count_value,
                        cache_case, identifiers,
                    )
                    selected = manifests[cache_case][count_value]
                    report["cases"].append(summarize_samples(
                        SINGLE_LABEL, count_value, cache_case,
                        "sequential", samples,
                        payload_distribution(root, selected),
                    ))
            report["failure_checks"] = validate_failures(
                root, port, identifiers
            )
        # The context manager must reap PHP; no listener may survive it.
        if selected_port is None:
            raise RuntimeError("missing_server_port")
        probe = http.client.HTTPConnection(
            "127.0.0.1", selected_port, timeout=0.3
        )
        try:
            try:
                probe.connect()
            except OSError:
                pass
            else:
                raise RuntimeError("orphan_php_listener")
        finally:
            probe.close()
        report["cleanup"] = "php_reaped_socket_closed_tempdir_owned"
    destination.mkdir(parents=True, exist_ok=True)
    stem = "issue-362-b1-http-php-" + php.replace(".", "_")
    (destination / (stem + ".json")).write_text(
        json.dumps(report, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    md = render_markdown(report)
    (destination / (stem + ".md")).write_text(md, encoding="utf-8")
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with Path(summary).open("a", encoding="utf-8") as output:
            output.write(md)
    print(md, flush=True)
    return report


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit(
            "usage: python -m tools.perf.issue_362_b1_http_benchmark OUTPUT"
        )
    run_benchmark(Path(sys.argv[1]))
