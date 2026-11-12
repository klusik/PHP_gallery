# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_b1_benchmark.py
# Module Type: Disposable Protocol Benchmark Orchestrator
# Purpose: Compare PHP producers and Python 3.14 parsers on GitHub runners.
# Responsibilities:
#   - Generate deterministic real JPEG/WebP fixtures outside source control.
#   - Check binary producer/parser interoperation and independent item states.
#   - Record separated monotonic timings, wire bytes and peak memory.
#   - Publish sanitized exact-SHA JSON/Markdown evidence.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""B1.1 isolated measurements; no production endpoints, auth or HTTP network."""

import json
import os
import random
import re
import statistics
import subprocess
import sys
import tempfile
import time
import tracemalloc
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from PIL import Image, features

from tools.perf.issue_362_b1_protocol import (
    BENCHMARK_BOUNDARY,
    FramedParser,
    MultipartParser,
    ProtocolError,
)


# Purpose: Repeat observations instead of qualifying shared-runner latencies.
# Type: int. Units: repetitions/case. Scope: B1.1 benchmark.
# Consumers: run_benchmarks. Rationale: five provides p50 and descriptive
# p95 without promoting unstable timing numbers to CI thresholds.
MEASUREMENT_REPEATS = 5

# Purpose: Exercise every requested workload size.
# Type: tuple[int, ...]. Units: image items. Scope: B1.1 benchmark.
# Consumers: run_benchmarks. Rationale: 8, 16, 24, 32 are B1.1 gates.
ITEM_COUNTS = (8, 16, 24, 32)

# Purpose: Exercise both non-production transport candidates.
# Type: tuple[str, ...]. Units: protocol variants. Scope: B1.1 benchmark.
# Consumers: run_benchmarks. Rationale: comparable data for each candidate.
PROTOCOLS = ("framed", "multipart")

# Purpose: Simulate small upstream HTTP read chunks.
# Type: int. Units: bytes/read. Scope: B1.1 synthetic reader.
# Consumers: read_parse_metrics. Rationale: 1024 stresses incremental logic.
SMALL_READ_BYTES = 1024

# Purpose: Simulate larger upstream HTTP read chunks.
# Type: int. Units: bytes/read. Scope: B1.1 synthetic reader.
# Consumers: read_parse_metrics. Rationale: 65536 models coalesced delivery.
LARGE_READ_BYTES = 65536

# Purpose: Give the producer a finite CI-only execution deadline.
# Type: int. Units: seconds. Scope: one PHP child.
# Consumers: produce_body. Rationale: 30 prevents a stuck fixture.
PHP_TIMEOUT_SECONDS = 30

# Purpose: Exercise a slower downstream consumer without network claims.
# Type: float. Units: seconds/validated item. Scope: B1.1 simulation.
# Consumers: read_parse_metrics. Rationale: a short fixed delay isolates
# consumer backpressure cost from PHP and actual HTTP infrastructure.
SLOW_CONSUMER_DELAY_SECONDS = 0.0005

# Purpose: Use deterministic output fidelity for JPEG fixture production.
# Type: int. Units: JPEG quality percent. Scope: synthetic Pillow fixtures.
# Consumers: build_fixtures. Rationale: 81 yields representative small files.
JPEG_QUALITY = 81

# Purpose: Use deterministic output fidelity for WebP fixture production.
# Type: int. Units: WebP quality percent. Scope: synthetic Pillow fixtures.
# Consumers: build_fixtures. Rationale: 78 covers realistic WebP payloads.
WEBP_QUALITY = 78

# Purpose: Vary thumbnail sizes across the requested batch matrix.
# Type: tuple[tuple[int,int], ...]. Units: pixels. Scope: synthetic images.
# Consumers: build_fixtures. Rationale: four sizes from 96 to 640 pixels
# yield small, medium and larger encoded images without fixture commits.
FIXTURE_DIMENSIONS = (
    (96, 96), (256, 192), (480, 320), (640, 480)
)

# Purpose: Keep deterministic pseudo-random image content reproducible.
# Type: int. Units: PRNG seed base. Scope: synthetic images.
# Consumers: build_fixtures. Rationale: stable fixture sizes across CI.
FIXTURE_SEED = 3621100

# Purpose: Format only sensible numbers in the printed metric report.
# Type: int. Units: decimal places. Scope: JSON/Markdown output.
# Consumers: summarize. Rationale: three decimals retain useful detail.
METRIC_DECIMALS = 3

# Purpose: Preserve only a short summary of the data model.
# Type: str. Units: schema label. Scope: sanitized CI artifact.
# Consumers: run_benchmarks. Rationale: distinguish B1.1 simulations.
REPORT_SCHEMA = "issue-362-b1.1-synthetic-v1"

# Purpose: Identify the separate PHP producer checked out in the same commit.
# Type: Path. Units: file location. Scope: this repository.
# Consumers: produce_body. Rationale: no downloaded dependency or endpoint.
PRODUCER_PATH = (
    Path(__file__).resolve().parent / "issue_362_b1_producer.php"
)


def build_fixtures(root: Path) -> list[dict[str, str]]:
    """Create reproducible real JPEG and WebP images in scratch storage.

    Args:
        root (Path): Temporary runner-owned directory, never a production gallery.

    Returns:
        list[dict[str, str]]: Ordered synthetic manifest image descriptors.

    Raises:
        RuntimeError: The runner does not support WebP encoding.
    """
    if not features.check("webp"):
        raise RuntimeError("Pillow WebP support unavailable on runner")
    items: list[dict[str, str]] = []
    for index in range(max(ITEM_COUNTS)):
        width, height = FIXTURE_DIMENSIONS[index % len(FIXTURE_DIMENSIONS)]
        pixels = random.Random(FIXTURE_SEED + index).randbytes(
            width * height * 3
        )
        image = Image.frombytes("RGB", (width, height), pixels)
        webp = (index % 2) == 0
        filename = "thumb_%03d.%s" % (
            index, "webp" if webp else "jpg"
        )
        image.save(
            root / filename, "WEBP" if webp else "JPEG",
            quality=WEBP_QUALITY if webp else JPEG_QUALITY
        )
        image.close()
        items.append({
            "image_id": str(index + 1),
            "status": "ok",
            "file": filename,
            "mime": "image/webp" if webp else "image/jpeg",
        })
    return items


def produce_body(
    protocol: str,
    root: Path,
    manifest_items: list[dict[str, str]],
    body_path: Path,
) -> dict[str, Any]:
    """Execute the isolated PHP producer with a disposable fixture manifest.

    Args:
        protocol (str): Framed or multipart body variant.
        root (Path): Canonical scratch-fixture directory.
        manifest_items (list[dict[str, str]]): Requested synthetic source and status descriptors.
        body_path (Path): Disposable output path, overwritten by the producer.

    Returns:
        dict[str, Any]: PHP's validated JSON wall-time, byte count and allocator peak.

    Raises:
        RuntimeError: The producer fails or reports inconsistent size.
    """
    manifest = root / "manifest.json"
    manifest.write_text(
        json.dumps({"root": str(root), "items": manifest_items}),
        encoding="utf-8",
    )
    result = subprocess.run(
        ["php", str(PRODUCER_PATH), protocol, str(manifest),
         str(body_path)],
        text=True, capture_output=True, timeout=PHP_TIMEOUT_SECONDS,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(
            "PHP producer failed: " + result.stderr[:200]
        )
    report = json.loads(result.stdout)
    if report["protocol"] != protocol or (
        report["items"] != len(manifest_items)
    ) or report["bytes"] != body_path.stat().st_size:
        raise RuntimeError("producer_metric_mismatch")
    return report


def read_parse_metrics(
    protocol: str,
    response_path: Path,
    expected_ids: list[str],
    mode: str,
    slow_consumer: bool = False,
) -> dict[str, float | int]:
    """Parse a file through simulated streaming or buffered delivery.

    Args:
        protocol (str): Candidate format under test.
        response_path (Path): Complete bytes produced by isolated PHP.
        expected_ids (list[str]): Ordered canonical IDs selected by the manifest.
        mode (str): One of small, large or buffered simulation.
        slow_consumer (bool): Whether to delay each already validated item.

    Returns:
        dict[str, float | int]: Monotonic parser and first-item times, and tracemalloc peak bytes.

    Raises:
        ProtocolError: A parser rejects an image, frame or end marker.
        RuntimeError: A producer result does not match the manifest.
    """
    parser = (
        FramedParser() if protocol == "framed"
        else MultipartParser(len(expected_ids), BENCHMARK_BOUNDARY)
    )
    chunk_bytes = {
        "small": SMALL_READ_BYTES,
        "large": LARGE_READ_BYTES,
        "buffered": response_path.stat().st_size,
    }[mode]
    count = 0
    first_ms: float | None = None
    tracemalloc.start()
    started_ns = time.perf_counter_ns()
    try:
        with response_path.open("rb") as source:
            while chunk := source.read(chunk_bytes):
                for item in parser.feed(chunk):
                    if item.image_id != expected_ids[count]:
                        raise RuntimeError("wrong_request_order")
                    if item.status != "ok" or not item.data:
                        raise RuntimeError("invalid_synthetic_thumbnail")
                    if first_ms is None:
                        first_ms = (
                            time.perf_counter_ns() - started_ns
                        ) / 1_000_000
                    count += 1
                    if slow_consumer:
                        time.sleep(SLOW_CONSUMER_DELAY_SECONDS)
        parser.finish()
        parsed_ms = (time.perf_counter_ns() - started_ns) / 1_000_000
        _, peak_bytes = tracemalloc.get_traced_memory()
    finally:
        tracemalloc.stop()
    if count != len(expected_ids) or first_ms is None:
        raise RuntimeError("missing_verified_image")
    return {
        "parser_ms": parsed_ms,
        "first_verified_ms": first_ms,
        "python_tracemalloc_peak_bytes": peak_bytes,
        "validated_count": count,
    }


def percentile(values: list[float], fraction: float) -> float:
    """Interpolate a descriptive quantile for a short repeated sample.

    Args:
        values (list[float]): Nonempty finite measurements of one compatible metric.
        fraction (float): Requested quantile from 0 to 1 inclusive.

    Returns:
        float: Linearly interpolated quantile in the input units.
    """
    ordered = sorted(values)
    position = (len(ordered) - 1) * fraction
    lower = int(position)
    upper = min(lower + 1, len(ordered) - 1)
    return ordered[lower] + (
        ordered[upper] - ordered[lower]
    ) * (position - lower)


def summarize(
    protocol: str,
    count: int,
    mode: str,
    samples: list[dict[str, float | int]],
    payload_bytes: int,
) -> dict[str, Any]:
    """Produce bounded aggregate metrics without machine identifiers.

    Args:
        protocol (str): Measured candidate format.
        count (int): Images in each repeated batch.
        mode (str): Simulated chunking mode.
        samples (list[dict[str, float | int]]): Repeated per-run producer and parser observations.
        payload_bytes (int): Sum of actual source-image byte sizes.

    Returns:
        dict[str, Any]: JSON-serializable sizes, p50/p95 timing and memory statistics.
    """
    row: dict[str, Any] = {
        "protocol": protocol, "items": count, "mode": mode,
        "repeats": len(samples), "payload_bytes": payload_bytes,
        "wire_bytes": int(samples[0]["wire_bytes"]),
        "overhead_bytes": int(samples[0]["wire_bytes"]) - payload_bytes,
    }
    keys = (
        "producer_ms", "parser_ms", "first_verified_ms",
        "php_peak_bytes", "python_tracemalloc_peak_bytes",
    )
    for key in keys:
        numbers = [float(sample[key]) for sample in samples]
        row[key + "_p50"] = round(
            statistics.median(numbers), METRIC_DECIMALS
        )
        row[key + "_p95"] = round(
            percentile(numbers, 0.95), METRIC_DECIMALS
        )
    return row


def verify_mixed_status_interoperability(
    root: Path, fixtures: list[dict[str, str]]
) -> None:
    """Prove the real PHP producer emits each non-binary status faithfully.

    Args:
        root (Path): Temporary runner fixture root.
        fixtures (list[dict[str, str]]): Pre-generated valid synthetic image descriptors.

    Returns:
        None: None.

    Raises:
        RuntimeError: A status or body differs from the strict v1 contract.
    """
    items = [
        fixtures[0],
        {"image_id": "2", "status": "not_modified",
         "etag": '"' + "a" * 64 + '"'},
        {"image_id": "3", "status": "unavailable"},
        {"image_id": "4", "status": "oversize"},
    ]
    for protocol in PROTOCOLS:
        path = root / ("mixed_" + protocol + ".bin")
        produce_body(protocol, root, items, path)
        parser = (
            FramedParser() if protocol == "framed"
            else MultipartParser(len(items), BENCHMARK_BOUNDARY)
        )
        results = []
        with path.open("rb") as source:
            while chunk := source.read(SMALL_READ_BYTES):
                results.extend(parser.feed(chunk))
        parser.finish()
        if [item.status for item in results] != [
            "ok", "not_modified", "unavailable", "oversize"
        ] or [len(item.data) for item in results[1:]] != [0, 0, 0]:
            raise RuntimeError("mixed_status_interoperability_failed")


def render_markdown(report: dict[str, Any]) -> str:
    """Render a compact human-readable summary from sanitized metrics.

    Args:
        report (dict[str, Any]): Exact-SHA benchmark JSON structure.

    Returns:
        str: Markdown with all format/count/chunk combinations and boundaries.
    """
    lines = [
        "# Issue #362 B1.1 isolated protocol benchmark",
        "",
        "Commit: " + report["commit_sha"],
        "PHP: " + report["php_version"] + ", Python: "
        + report["python_version"],
        "",
        "No HTTP network or reverse proxy was involved. Buffered input "
        "allocations count toward Python tracemalloc; the PHP peak is "
        "allocator memory, not full process RSS.",
        "",
        "| Format | Images | Delivery simulation | Wire KiB | "
        "Overhead KiB | PHP p50 / p95 ms | Parse p50 / p95 ms | "
        "First validated p50 / p95 ms | PHP peak MiB p50 | "
        "Python peak MiB p50 |",
        "| --- | ---: | --- | ---: | ---: | --- | --- | "
        "--- | ---: | ---: |",
    ]
    for row in report["cases"]:
        mib = 1024 * 1024
        kib = 1024
        lines.append(
            "| {protocol} | {items} | {mode} | {wire:.1f} | {over:.1f} | "
            "{pp50:.2f} / {pp95:.2f} | {cp50:.2f} / {cp95:.2f} | "
            "{fp50:.2f} / {fp95:.2f} | {php:.2f} | {py:.2f} |".format(
                protocol=row["protocol"], items=row["items"],
                mode=row["mode"], wire=row["wire_bytes"] / kib,
                over=row["overhead_bytes"] / kib,
                pp50=row["producer_ms_p50"],
                pp95=row["producer_ms_p95"],
                cp50=row["parser_ms_p50"],
                cp95=row["parser_ms_p95"],
                fp50=row["first_verified_ms_p50"],
                fp95=row["first_verified_ms_p95"],
                php=row["php_peak_bytes_p50"] / mib,
                py=row["python_tracemalloc_peak_bytes_p50"] / mib,
            )
        )
    lines.extend([
        "",
        "Five synthetic repetitions per cell. Quantiles are descriptive "
        "only, not pass/fail thresholds. End-to-end network latency, "
        "PHP HTTP response buffering, host worker contention, authorization "
        "and real UI paint time are intentionally unmeasured.",
    ])
    return "\n".join(lines) + "\n"


def run_benchmarks(output_directory: Path) -> dict[str, Any]:
    """Qualify synthetic PHP/Python interoperability and record observations.

    Args:
        output_directory (Path): Directory for sanitized hosted CI artifacts.

    Returns:
        dict[str, Any]: Sanitized report including the exact GitHub commit identity.

    Raises:
        RuntimeError: Runner identity or mandatory test outcomes are invalid.
    """
    sha = os.environ.get("GITHUB_SHA", "")
    if not (
        os.environ.get("GITHUB_ACTIONS") == "true"
        and os.environ.get("GALLERY_WORKFLOW_ENABLE") == "disposable-only"
        and re.fullmatch(r"[0-9a-f]{40}", sha)
    ):
        raise RuntimeError("B1.1 benchmark requires exact-SHA GitHub CI")
    php = subprocess.run(
        ["php", "-r", "echo PHP_VERSION;"],
        text=True, capture_output=True, check=True,
        timeout=PHP_TIMEOUT_SECONDS,
    ).stdout
    report: dict[str, Any] = {
        "schema": REPORT_SCHEMA,
        "commit_sha": sha,
        "created_utc": datetime.now(timezone.utc).isoformat(),
        "php_version": php,
        "python_version": sys.version.split()[0],
        "environment": "github_actions_isolated_synthetic_no_network",
        "repeats": MEASUREMENT_REPEATS,
        "cases": [],
        "slow_consumer_scenario": [],
    }
    with tempfile.TemporaryDirectory(prefix="issue362-b1-") as directory:
        root = Path(directory).resolve()
        fixtures = build_fixtures(root)
        verify_mixed_status_interoperability(root, fixtures)
        for protocol in PROTOCOLS:
            for count in ITEM_COUNTS:
                selected = fixtures[:count]
                source_bytes = sum(
                    (root / entry["file"]).stat().st_size
                    for entry in selected
                )
                expected = [entry["image_id"] for entry in selected]
                samples: dict[str, list[dict[str, float | int]]] = {
                    "small": [], "large": [], "buffered": []
                }
                for repetition in range(MEASUREMENT_REPEATS):
                    response = root / (
                        protocol + "_" + str(count) + "_"
                        + str(repetition) + ".bin"
                    )
                    producer = produce_body(
                        protocol, root, selected, response
                    )
                    for mode in samples:
                        parser = read_parse_metrics(
                            protocol, response, expected, mode
                        )
                        samples[mode].append({
                            **parser,
                            "wire_bytes": producer["bytes"],
                            "producer_ms": producer["producer_ms"],
                            "php_peak_bytes": producer["php_peak_bytes"],
                        })
                    response.unlink()
                for mode, observed in samples.items():
                    report["cases"].append(
                        summarize(
                            protocol, count, mode, observed, source_bytes
                        )
                    )
            slow_response = root / (protocol + "_slow.bin")
            small_batch = fixtures[:min(ITEM_COUNTS)]
            produce_body(protocol, root, small_batch, slow_response)
            slow = read_parse_metrics(
                protocol, slow_response,
                [entry["image_id"] for entry in small_batch],
                "large", slow_consumer=True,
            )
            report["slow_consumer_scenario"].append({
                "protocol": protocol,
                "items": len(small_batch),
                **slow,
            })
    output_directory.mkdir(parents=True, exist_ok=True)
    stem = "issue-362-b1-php-" + php.replace(".", "_")
    json_path = output_directory / (stem + ".json")
    md_path = output_directory / (stem + ".md")
    json_path.write_text(
        json.dumps(report, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    md = render_markdown(report)
    md_path.write_text(md, encoding="utf-8")
    print(md, flush=True)
    step_summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if step_summary:
        with Path(step_summary).open("a", encoding="utf-8") as output:
            output.write(md)
    return report


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: issue_362_b1_benchmark.py OUTPUT_DIRECTORY")
    run_benchmarks(Path(sys.argv[1]))
