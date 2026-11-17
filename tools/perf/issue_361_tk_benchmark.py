#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_361_tk_benchmark.py
# Module Type: Performance Benchmark Driver
# Purpose: Run isolated source-pinned Tk benchmark stages and summarize evidence.
# Responsibilities:
#   - Extract immutable source archives and run fresh workload subprocesses.
#   - Validate workload evidence, preserve observations, and summarize pairs.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Orchestrate immutable Tk benchmark runs and retain auditable evidence."""

import argparse
import csv
import hashlib
import json
import math
import os
import platform
import random
import shutil
import statistics
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
from importlib.metadata import version
from pathlib import Path
from typing import TypedDict, TypeAlias, cast


class BenchmarkBlocked(RuntimeError):
    """Represent an explicit unavailable-environment benchmark outcome."""


class WorkloadEventExtras(TypedDict, total=False):
    """Describe optional per-event callback and dispatch observations."""

    callback_name: str
    dispatch_delay_ns: int


class WorkloadEvent(WorkloadEventExtras):
    """Describe one raw GUI event and optional bounded event context."""

    metric: str
    ns: int
    phase: str


class ChildReport(TypedDict):
    """Describe required child result fields and their JSON value shapes."""

    schema_version: int
    seed: int

    status: str
    metrics: dict[str, float]
    operations: dict[str, int]
    resources: dict[str, float | int | str | bool | None]
    metadata: dict[str, str | int | float | bool | None | dict[str, str | int | float | None]]
    raw_events: list[WorkloadEvent]
    raw_events_truncated: int
    failure: str | None
    missing_metrics: list[str]
    missing_required_metrics: list[str]
    scenario: str
    cache: str
    trial: int
    source: str


class MetricSummary(TypedDict):
    """Describe run-level statistics for one metric."""

    mean: float
    median: float
    sample_stddev: float
    p50: float
    p95: float
    p99: float
    min: float
    max: float


class MetricComparison(TypedDict):
    """Describe baseline, candidate, and paired metric statistics."""

    baseline: MetricSummary
    candidate: MetricSummary
    absolute_delta_mean: float
    percent_delta_mean: float | None
    candidate_to_baseline_ratio: float | None
    baseline_to_candidate_ratio: float | None
    paired_delta_ci95: list[float]
    paired_deltas: list[float]


class ComparisonGroup(TypedDict):
    """Describe one scenario/cache comparison group."""

    pairs: int
    metrics: dict[str, MetricComparison]


ComparisonSummary: TypeAlias = dict[str, ComparisonGroup]


class RunRecord(TypedDict):
    """Describe one completed independent workload process."""

    source: str
    source_sha: str
    scenario: str
    cache: str
    instrumentation: str
    trial: int
    elapsed_seconds: float
    returncode: int
    status: str
    report: ChildReport


class ProgressCheckpoint(TypedDict):
    """Describe one committed child checkpoint without qualifying its stage."""

    source: str
    source_sha: str
    scenario: str
    cache: str
    instrumentation: str
    trial: int
    elapsed_seconds: float
    returncode: int
    status: str
    raw_event_count: int


# Streaming digest block-size policy.
# Type: int.
# Units: bytes. Scope: one file hash operation.
# Consumers: sha256_file().
# Rationale: bound memory use when hashing maintained harness and protocol files.
HASH_READ_BLOCK_BYTES = 1048576

# Bounded archive diagnostic policy.
# Type: int.
# Units: characters. Scope: one failed archive subprocess.
# Consumers: extract_commit().
# Rationale: retain useful failure context without flooding CI output.
DIAGNOSTIC_TAIL_CHARS = 2000

# Workload process timeout policy.
# Type: int.
# Units: seconds. Scope: one workload subprocess.
# Consumers: run_workload().
# Rationale: stop a hung GUI fixture while preserving time for report validation.
PROCESS_TIMEOUT_SECONDS = 90

# Whole-run deadline policy.
# Type: int.
# Units: seconds. Scope: one benchmark driver invocation.
# Consumers: run_stage().
# Rationale: bound hosted runner occupancy across all benchmark matrix cells.
TOTAL_TIMEOUT_SECONDS = 2400

# Independent measured repetition policy.
# Type: int.
# Units: process runs per scenario/cache cell. Scope: baseline and compare.
# Consumers: run_stage(), summarize_comparison().
# Rationale: provide repeated run-level observations for descriptive estimates.
MEASURED_REPETITIONS = 10

# Deterministic bootstrap resample policy.
# Type: int.
# Units: resamples. Scope: one metric and scenario/cache group.
# Consumers: bootstrap_delta_ci().
# Rationale: stabilize paired percentile bounds reproducibly.
BOOTSTRAP_RESAMPLES = 2000

# Descriptive distribution percentile policy.
# Type: dict[str, float].
# Units: percentile fraction. Scope: each metric cell summary.
# Consumers: describe().
# Rationale: retain the standard median, 95th, and 99th percentile readings.
SUMMARY_PERCENTILE_FRACTIONS = {"p50": 0.50, "p95": 0.95, "p99": 0.99}

# Paired bootstrap interval tail policy.
# Type: tuple[float, float].
# Units: percentile fractions. Scope: one paired metric comparison.
# Consumers: bootstrap_delta_ci().
# Rationale: report the central 95 percent bootstrap interval.
BOOTSTRAP_INTERVAL_FRACTIONS = (0.025, 0.975)

# Fixed fixture workload seed policy.
# Type: int.
# Units: seed value. Scope: every workload process.
# Consumers: run_workload().
# Rationale: keep generated fixture data consistent across source revisions.
WORKLOAD_SEED = 3612026

# Fixed scenario matrix policy.
# Type: tuple[str, ...].
# Units: scenario identifiers. Scope: protocol version 1.
# Consumers: matrix_cells(), run_stage().
# Rationale: exercise dashboard, import, gallery, and lightbox workflows.
SCENARIOS = ("dashboard", "import", "gallery", "lightbox")

# Cache-state matrix policy.
# Type: tuple[str, ...].
# Units: cache-state identifiers. Scope: protocol version 1.
# Consumers: matrix_cells(), run_stage().
# Rationale: separate cold and warm behavior per independent process.
CACHE_STATES = ("cold", "warm")


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    """Parse the absolute audit-suite output directory.

    Args:
        argv (list[str] | None): Optional vector; None reads process arguments.

    Returns:
        argparse.Namespace: Parsed command-line arguments.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args(argv)
    if not args.output.is_absolute():
        parser.error("--output must be an absolute path")
    return args


def sha256_file(path: Path) -> str:
    """Hash exact file bytes.

    Args:
        path (Path): Existing source file.

    Returns:
        str: Lowercase SHA-256 digest.
    """
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(HASH_READ_BLOCK_BYTES), b""):
            digest.update(block)
    return digest.hexdigest()


def extract_commit(source_sha: str, destination: Path) -> None:
    """Extract an existing immutable commit without moving repository refs.

    Args:
        source_sha (str): Full lowercase immutable commit SHA.
        destination (Path): Existing empty source directory.

    Returns:
        None: The selected application subtree is extracted.

    Raises:
        RuntimeError: If the commit identifier or archive extraction is invalid.
    """
    if len(source_sha) != 40 or any(c not in "0123456789abcdef" for c in source_sha):
        raise RuntimeError(f"invalid immutable commit SHA: {source_sha}")
    archive = subprocess.run(
        ["git", "archive", "--format=tar", source_sha, "winapp"],
        check=False, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=PROCESS_TIMEOUT_SECONDS,
    )
    if archive.returncode:
        raise RuntimeError(archive.stderr.decode("utf-8", "replace")[-DIAGNOSTIC_TAIL_CHARS:])
    result = subprocess.run(
        ["tar", "-xf", "-", "-C", str(destination)],
        input=archive.stdout, check=False, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, timeout=PROCESS_TIMEOUT_SECONDS,
    )
    if result.returncode:
        raise RuntimeError(result.stderr.decode("utf-8", "replace")[-DIAGNOSTIC_TAIL_CHARS:])


def select_pilot_scenarios(configured: list[str] | None) -> tuple[str, ...]:
    """Validate and canonically order an optional pilot scenario subset.

    Args:
        configured (list[str] | None): Optional protocol scenario identifiers.

    Returns:
        tuple[str, ...]: Nonempty selected scenarios in canonical order.

    Raises:
        ValueError: If the subset is empty, duplicated, or unknown.
    """
    if configured is None:
        return SCENARIOS
    if not configured or len(set(configured)) != len(configured):
        raise ValueError("pilot_scenarios must be nonempty and contain no duplicates")
    unknown = set(configured).difference(SCENARIOS)
    if unknown:
        raise ValueError(f"unknown pilot scenarios: {sorted(unknown)}")
    return tuple(scenario for scenario in SCENARIOS if scenario in configured)


def matrix_cells(scenarios: tuple[str, ...] = SCENARIOS) -> list[tuple[str, str]]:
    """Return the selected scenario/cache matrix.

    Args:
        scenarios (tuple[str, ...]): Canonically ordered scenario identifiers.

    Returns:
        list[tuple[str, str]]: Ordered scenario and cache-state pairs.
    """
    return [(scenario, cache) for scenario in scenarios for cache in CACHE_STATES]


def validate_report(
    report: ChildReport,
    scenario: str,
    cache: str,
    trial: int,
    source: str,
    instrumentation: str,
) -> ChildReport:
    """Validate identity, required fields, status, and numeric observations.

    Args:
        report (ChildReport): Decoded JSON object written by one child.
        scenario (str): Requested scenario identifier.
        cache (str): Requested cache state.
        trial (int): Requested process trial number.
        source (str): Exact application commit SHA used by the child.
        instrumentation (str): on or off measurement mode.

    Returns:
        ChildReport: Validated child report.

    Raises:
        RuntimeError: If identity or report evidence is missing or invalid.
    """
    if not isinstance(report, dict):
        raise RuntimeError("workload report root must be an object")
    required = {
        "schema_version", "seed", "status", "metrics", "operations", "resources", "metadata",
        "raw_events", "raw_events_truncated", "failure", "missing_metrics", "missing_required_metrics",
        "scenario", "cache", "trial", "source",
    }
    absent = sorted(required - report.keys())
    if absent:
        raise RuntimeError(f"workload report missing fields: {absent}")
    if report["schema_version"] != 1 or report["seed"] != WORKLOAD_SEED:
        raise RuntimeError("workload schema version or deterministic seed mismatch")
    if instrumentation not in ("on", "off"):
        raise RuntimeError(f"invalid instrumentation mode: {instrumentation}")
    for key, expected in (
        ("scenario", scenario), ("cache", cache), ("trial", trial), ("source", source),
    ):
        if report[key] != expected:
            raise RuntimeError(f"workload report {key} mismatch")
    if report["status"] == "PASS" and report["failure"] not in (None, ""):
        raise RuntimeError("PASS report includes a failure detail")
    if report["status"] == "BLOCKED":
        raise BenchmarkBlocked(
            "child BLOCKED; missing=" + str(report["missing_required_metrics"])
        )
    if report["status"] != "PASS":
        reason = report.get("reason", report.get("error", "no child failure reason"))
        raise RuntimeError("child status " + str(report["status"]) + ": " + str(reason))
    if not isinstance(report["metrics"], dict) or not report["metrics"]:
        raise RuntimeError("metrics must be a nonempty object")
    if not isinstance(report["operations"], dict):
        raise RuntimeError("operations must be an object")
    if any(
        not isinstance(name, str) or not isinstance(count, int)
        or isinstance(count, bool) or count < 0
        for name, count in report["operations"].items()
    ):
        raise RuntimeError("operation counts must be nonnegative integers")
    if not isinstance(report["resources"], dict) or not isinstance(report["metadata"], dict):
        raise RuntimeError("resources and metadata must be objects")
    if not isinstance(report["missing_metrics"], list):
        raise RuntimeError("missing_metrics must be a list")
    if not isinstance(report["missing_required_metrics"], list) or report["missing_required_metrics"]:
        raise RuntimeError("required child metrics are missing")
    required_metrics = {
        "on": {
            "event_loop_drift_p95_ms", "callback_p95_ms",
            "interaction_feedback_p95_ms",
        },
        "off": {"scenario_elapsed_ms"},
    }[instrumentation]
    if scenario in ("gallery", "lightbox") and instrumentation == "on":
        required_metrics.add("image_publication_p95_ms")
    if not required_metrics <= report["metrics"].keys():
        raise RuntimeError("instrumentation-specific metrics are missing")
    for name, value in report["metrics"].items():
        if not isinstance(name, str) or not name.endswith(("_ms", "_count", "_bytes")):
            raise RuntimeError(f"metric name lacks unit suffix: {name!r}")
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) or value < 0:
            raise RuntimeError(f"metric value is invalid: {name!r}")
    if not isinstance(report["raw_events"], list):
        raise RuntimeError("raw_events must be a list")
    truncated_count = report["raw_events_truncated"]
    if not isinstance(truncated_count, int) or isinstance(truncated_count, bool):
        raise RuntimeError("raw_events_truncated must be an integer count")
    if truncated_count != 0:
        raise RuntimeError("truncated raw event evidence cannot qualify a report")
    if report["failure"] is not None and not isinstance(report["failure"], str):
        raise RuntimeError("failure detail must be a string or null")
    measured_metrics: set[str] = set()
    for event in report["raw_events"]:
        if not isinstance(event, dict) or not {"metric", "ns", "phase"} <= event.keys():
            raise RuntimeError("raw event needs metric, ns, and phase")
        if not isinstance(event["metric"], str) or not event["metric"]:
            raise RuntimeError("raw event metric must be a nonempty string")
        if event["phase"] not in ("warmup", "measured"):
            raise RuntimeError("raw event phase is invalid")
        if not isinstance(event["ns"], int) or isinstance(event["ns"], bool) or event["ns"] < 0:
            raise RuntimeError("raw event timestamp must be nonnegative integer")
        if "callback_name" in event and (
            not isinstance(event["callback_name"], str)
            or not event["callback_name"]
        ):
            raise RuntimeError("raw event callback_name must be a nonempty string")
        if "dispatch_delay_ns" in event and (
            not isinstance(event["dispatch_delay_ns"], int)
            or isinstance(event["dispatch_delay_ns"], bool)
            or event["dispatch_delay_ns"] < 0
        ):
            raise RuntimeError("raw event dispatch_delay_ns must be a nonnegative integer")
        if event["phase"] == "measured":
            measured_metrics.add(event["metric"])
    if instrumentation == "on":
        raw_metric_names = {
            "event_loop_drift_p95_ms": "event_loop_drift",
            "callback_p95_ms": "callback",
            "interaction_feedback_p95_ms": "interaction_feedback",
            "image_publication_p95_ms": "image_publication",
        }
        required_raw_metrics = {raw_metric_names[name] for name in required_metrics}
        if not required_raw_metrics <= measured_metrics:
            raise RuntimeError("instrumented metrics have no measured raw events")
    return cast(ChildReport, report)


def run_workload(
    harness: Path,
    source_root: Path,
    output: Path,
    scenario: str,
    cache: str,
    trial: int,
    instrumentation: str,
    source: str,
    source_sha: str,
    deadline: float,
) -> RunRecord:
    """Run, preserve, and validate one independent workload process.

    Args:
        harness (Path): Absolute shared workload harness path.
        source_root (Path): Extracted immutable application source root.
        output (Path): Existing directory for evidence.
        scenario (str): Scenario matrix value.
        cache (str): Cache-state matrix value.
        trial (int): Independent process run number.
        instrumentation (str): on or off instrumentation state.
        source (str): Comparison label baseline or candidate.
        source_sha (str): Immutable source SHA supplied to the child.
        deadline (float): Monotonic whole-run deadline.

    Returns:
        RunRecord: Validated process record.

    Raises:
        RuntimeError: On process timeout, error, missing file, or invalid report.
    """
    report_path = output / (
        f"{source}-{scenario}-{cache}-{instrumentation}-{trial:03d}.json"
    )
    if time.monotonic() >= deadline:
        raise RuntimeError("total deadline exceeded before workload launch")
    command = [
        sys.executable, str(harness), "--source", str(source_root),
        "--scenario", scenario, "--cache", cache, "--trial", str(trial),
        "--output", str(report_path), "--instrumentation", instrumentation,
        "--seed", str(WORKLOAD_SEED), "--source-id", source_sha,
    ]
    started = time.monotonic()
    timeout = min(PROCESS_TIMEOUT_SECONDS, max(1, int(deadline - started)))
    try:
        result = subprocess.run(
            command, cwd=str(harness.parent), check=False, capture_output=True,
            text=True, encoding="utf-8", errors="replace", timeout=timeout,
        )
    except subprocess.TimeoutExpired as error:
        (output / f"{report_path.stem}.stdout.txt").write_text(
            str(error.stdout or ""), encoding="utf-8"
        )
        (output / f"{report_path.stem}.stderr.txt").write_text(
            str(error.stderr or ""), encoding="utf-8"
        )
        raise RuntimeError(f"workload timed out: {report_path.stem}") from error
    elapsed = time.monotonic() - started
    (output / f"{report_path.stem}.stdout.txt").write_text(
        result.stdout, encoding="utf-8"
    )
    (output / f"{report_path.stem}.stderr.txt").write_text(
        result.stderr, encoding="utf-8"
    )
    if result.returncode:
        child_status = "missing report"
        child_reason = "no child failure reason"
        if report_path.is_file():
            try:
                failed_payload = json.loads(report_path.read_text(encoding="utf-8"))
                if isinstance(failed_payload, dict):
                    child_status = str(failed_payload.get("status", child_status))
                    child_reason = str(failed_payload.get(
                        "reason", failed_payload.get("error", failed_payload.get("message", failed_payload.get("failure", child_reason)))
                    ))
            except (OSError, json.JSONDecodeError) as error:
                child_reason = "invalid failure report: " + str(error)
        stderr_tail = result.stderr[-DIAGNOSTIC_TAIL_CHARS:]
        detail = (
            "workload exit " + str(result.returncode) + "; status=" + child_status
            + "; reason=" + child_reason + "; stderr=" + stderr_tail
        )
        if child_status == "BLOCKED":
            raise BenchmarkBlocked(detail)
        raise RuntimeError(detail)
    if not report_path.is_file():
        raise RuntimeError("child omitted report: " + report_path.stem)
    report = validate_report(
        json.loads(report_path.read_text(encoding="utf-8")),
        scenario, cache, trial, source_sha, instrumentation,
    )
    return {
        "source": source, "source_sha": source_sha, "scenario": scenario, "cache": cache,
        "instrumentation": instrumentation, "trial": trial,
        "elapsed_seconds": elapsed, "returncode": result.returncode,
        "status": "PASS", "report": report,
    }


def percentile(values: list[float], fraction: float) -> float:
    """Calculate a linearly interpolated percentile.

    Args:
        values (list[float]): Nonempty numeric observations.
        fraction (float): Percentile fraction from zero to one.

    Returns:
        float: Interpolated percentile value.
    """
    ordered = sorted(values)
    position = (len(ordered) - 1) * fraction
    lower, upper = math.floor(position), math.ceil(position)
    return ordered[lower] + (ordered[upper] - ordered[lower]) * (position - lower)


def describe(values: list[float]) -> MetricSummary:
    """Summarize independent process-level metric values.

    Args:
        values (list[float]): Nonempty numeric observations.

    Returns:
        MetricSummary: Mean, median, sample deviation, percentiles, and range.
    """
    return {
        "mean": statistics.fmean(values), "median": statistics.median(values),
        "sample_stddev": statistics.stdev(values) if len(values) > 1 else 0.0,
        "p50": percentile(values, SUMMARY_PERCENTILE_FRACTIONS["p50"]),
        "p95": percentile(values, SUMMARY_PERCENTILE_FRACTIONS["p95"]),
        "p99": percentile(values, SUMMARY_PERCENTILE_FRACTIONS["p99"]), "min": min(values), "max": max(values),
    }


def bootstrap_delta_ci(deltas: list[float], seed_text: str) -> list[float]:
    """Compute deterministic percentile bounds from paired process deltas.

    Args:
        deltas (list[float]): Candidate-minus-baseline value per pair.
        seed_text (str): Stable scenario/cache/metric seed description.

    Returns:
        list[float]: Lower and upper 95 percent bootstrap bounds.
    """
    seed = int(hashlib.sha256(seed_text.encode("utf-8")).hexdigest()[:16], 16)
    rng = random.Random(seed)
    means = [
        statistics.fmean(deltas[rng.randrange(len(deltas))] for _ in deltas)
        for _ in range(BOOTSTRAP_RESAMPLES)
    ]
    return [percentile(means, fraction) for fraction in BOOTSTRAP_INTERVAL_FRACTIONS]


def summarize_comparison(records: list[RunRecord]) -> ComparisonSummary:
    """Summarize each scenario/cache using paired independent run samples.

    Args:
        records (list[RunRecord]): Validated baseline and candidate records.

    Returns:
        ComparisonSummary: Per-cell descriptive and paired statistics.

    Raises:
        RuntimeError: If a required group has fewer than ten complete pairs.
    """
    summary: ComparisonSummary = {}
    for scenario, cache in matrix_cells():
        samples: dict[str, dict[int, dict[str, float]]] = {
            "baseline": {}, "candidate": {},
        }
        for record in records:
            child = record["report"]
            for field in ("scenario", "cache", "trial"):
                if child[field] != record[field]:
                    raise RuntimeError(f"nested report {field} differs from run record")
            if child["source"] != record["source_sha"]:
                raise RuntimeError("nested report source SHA differs from run record")
            if record["source"] not in samples:
                raise RuntimeError("comparison source label must be baseline or candidate")
            if record["scenario"] == scenario and record["cache"] == cache:
                bucket = samples[record["source"]]
                if record["trial"] in bucket:
                    raise RuntimeError(f"duplicate pair identity for {scenario}/{cache}")
                bucket[record["trial"]] = record["report"]["metrics"]
        pairs = sorted(set(samples["baseline"]) & set(samples["candidate"]))
        if set(samples["baseline"]) != set(samples["candidate"]):
            raise RuntimeError(f"unmatched trial identities for {scenario}/{cache}")
        if len(pairs) < MEASURED_REPETITIONS:
            raise RuntimeError(f"incomplete pairs for {scenario}/{cache}")
        metric_sets = [
            (set(samples["baseline"][pair]), set(samples["candidate"][pair]))
            for pair in pairs
        ]
        expected_names = metric_sets[0][0]
        if any(
            base_names != candidate_names or base_names != expected_names
            for base_names, candidate_names in metric_sets
        ):
            raise RuntimeError(f"inconsistent metric set for {scenario}/{cache}")
        names = sorted(expected_names)
        metrics: dict[str, MetricComparison] = {}
        for name in names:
            base = [samples["baseline"][pair][name] for pair in pairs]
            candidate = [samples["candidate"][pair][name] for pair in pairs]
            deltas = [right - left for left, right in zip(base, candidate)]
            base_mean, candidate_mean = statistics.fmean(base), statistics.fmean(candidate)
            metrics[name] = {
                "baseline": describe(base), "candidate": describe(candidate),
                "absolute_delta_mean": statistics.fmean(deltas),
                "percent_delta_mean": (
                    (candidate_mean - base_mean) / abs(base_mean) * 100
                    if base_mean else None
                ),
                "candidate_to_baseline_ratio": (
                    candidate_mean / base_mean if base_mean else None
                ),
                "baseline_to_candidate_ratio": (
                    base_mean / candidate_mean if candidate_mean else None
                ),
                "paired_delta_ci95": bootstrap_delta_ci(
                    deltas, f"{scenario}/{cache}/{name}"
                ),
                "paired_deltas": deltas,
            }
        summary[f"{scenario}/{cache}"] = {"pairs": len(pairs), "metrics": metrics}
    return summary


def summarize_baseline(
    records: list[RunRecord],
) -> dict[str, dict[str, dict[str, float]]]:
    """Summarize baseline metrics per scenario/cache from independent processes.

    Args:
        records (list[RunRecord]): Valid completed baseline process records.

    Returns:
        dict[str, dict[str, dict[str, float]]]: Cell to metric to statistics mapping.

    Raises:
        RuntimeError: If a cell has fewer than ten runs or metric sets differ.
    """
    cells: dict[str, list[RunRecord]] = {}
    for scenario, cache in matrix_cells():
        cell = f"{scenario}/{cache}"
        rows = [
            row for row in records
            if row["scenario"] == scenario and row["cache"] == cache
            and row["source"] == "baseline"
        ]
        if len(rows) < MEASURED_REPETITIONS:
            raise RuntimeError(f"incomplete baseline cell {cell}")
        if len({row["trial"] for row in rows}) != len(rows):
            raise RuntimeError(f"duplicate baseline trial identity in {cell}")
        expected = set(rows[0]["report"]["metrics"])
        if any(set(row["report"]["metrics"]) != expected for row in rows):
            raise RuntimeError(f"inconsistent baseline metric set in {cell}")
        cells[cell] = rows
    return {
        cell: {
            metric: describe([row["report"]["metrics"][metric] for row in rows])
            for metric in sorted(rows[0]["report"]["metrics"])
        }
        for cell, rows in cells.items()
    }


def summarize_pilot(
    records: list[RunRecord], scenarios: tuple[str, ...] = SCENARIOS,
) -> dict[str, dict[str, float]]:
    """Summarize descriptive instrumentation overhead from one on/off pair.

    Args:
        records (list[RunRecord]): Valid pilot process records.
        scenarios (tuple[str, ...]): Scenarios included in this pilot.

    Returns:
        dict[str, dict[str, float]]: Cell wall times and descriptive overhead.

    Raises:
        RuntimeError: If either instrumentation state is missing in a cell.
    """
    result: dict[str, dict[str, float]] = {}
    for scenario, cache in matrix_cells(scenarios):
        rows = {
            row["instrumentation"]: row
            for row in records
            if row["scenario"] == scenario and row["cache"] == cache
        }
        cell_rows = [
            row for row in records
            if row["scenario"] == scenario and row["cache"] == cache
        ]
        if len(rows) != 2 or set(rows) != {"on", "off"} or len(cell_rows) != 2:
            raise RuntimeError(f"incomplete instrumentation pilot for {scenario}/{cache}")
        on_seconds = rows["on"]["elapsed_seconds"]
        off_seconds = rows["off"]["elapsed_seconds"]
        if off_seconds <= 0:
            raise RuntimeError(f"invalid zero wall-time observation for {scenario}/{cache}")
        result[f"{scenario}/{cache}"] = {
            "instrumentation_on_seconds": on_seconds,
            "instrumentation_off_seconds": off_seconds,
            "absolute_overhead_seconds": on_seconds - off_seconds,
            "percent_overhead": (
                (on_seconds - off_seconds) / off_seconds * 100
            ),
        }
    return result


def save_records(
    output: Path, records: list[RunRecord], *, append: bool = False,
) -> None:
    """Persist new child checkpoints or export the complete current evidence.

    Args:
        output (Path): Existing evidence directory.
        records (list[RunRecord]): Valid completed process records to append or
            the complete in-memory record set for final export.
        append (bool): Append only these new rows and progress checkpoints.
            Defaults to False to write the existing combined report and CSV export.

    Returns:
        None: Evidence is appended or exported in the existing output schemas.

    Raises:
        OSError: An evidence file cannot be created or written.
    """
    fields = (
        "source", "source_sha", "scenario", "cache", "instrumentation", "trial",
        "elapsed_seconds", "returncode", "status",
    )
    event_fields = (
        "source", "source_sha", "scenario", "cache", "instrumentation", "trial",
        "metric", "ns", "phase", "callback_name", "dispatch_delay_ns",
    )
    if append:
        # Zero bytes identify an empty CSV; each nonempty CSV owns one header.
        record_path = output / "raw_records.csv"
        record_has_header = record_path.is_file() and record_path.stat().st_size > 0
        with record_path.open("a", newline="", encoding="utf-8") as stream:
            writer = csv.DictWriter(stream, fieldnames=fields)
            if not record_has_header:
                writer.writeheader()
            writer.writerows(
                {key: row.get(key) for key in fields} for row in records
            )

        event_path = output / "raw_events.csv"
        event_has_header = event_path.is_file() and event_path.stat().st_size > 0
        with event_path.open("a", newline="", encoding="utf-8") as stream:
            writer = csv.DictWriter(stream, fieldnames=event_fields)
            if not event_has_header:
                writer.writeheader()
            for row in records:
                for event in row["report"]["raw_events"]:
                    writer.writerow({
                        "source": row["source"],
                        "source_sha": row["source_sha"],
                        "scenario": row["scenario"],
                        "cache": row["cache"],
                        "instrumentation": row["instrumentation"],
                        "trial": row["trial"],
                        **event,
                    })

        progress_path = output / "progress.jsonl"
        with progress_path.open("a", encoding="utf-8", newline="\n") as stream:
            for row in records:
                checkpoint: ProgressCheckpoint = {
                    "source": row["source"],
                    "source_sha": row["source_sha"],
                    "scenario": row["scenario"],
                    "cache": row["cache"],
                    "instrumentation": row["instrumentation"],
                    "trial": row["trial"],
                    "elapsed_seconds": row["elapsed_seconds"],
                    "returncode": row["returncode"],
                    "status": row["status"],
                    "raw_event_count": len(row["report"]["raw_events"]),
                }
                stream.write(json.dumps(checkpoint, sort_keys=True) + "\n")
            stream.flush()
        return

    (output / "report.json").write_text(
        json.dumps({"schema_version": 1, "records": records}, indent=2) + "\n",
        encoding="utf-8",
    )
    with (output / "raw_records.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=fields)
        writer.writeheader()
        writer.writerows({key: row.get(key) for key in fields} for row in records)
    with (output / "raw_events.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=event_fields)
        writer.writeheader()
        for row in records:
            for event in row["report"]["raw_events"]:
                writer.writerow({
                    "source": row["source"], "source_sha": row["source_sha"], "scenario": row["scenario"],
                    "cache": row["cache"], "instrumentation": row["instrumentation"],
                    "trial": row["trial"], **event,
                })


def host_metadata() -> dict[str, str | int | float | None]:
    """Collect static host metadata outside benchmark timings.

    Returns:
        dict[str, str | int | float | None]: OS and hardware property values.
    """
    cpu_model = platform.processor() or "unavailable"
    if sys.platform == "win32":
        try:
            import winreg
            registry_path = chr(92).join(("HARDWARE", "DESCRIPTION", "System", "CentralProcessor", "0"))
            cpu_key = winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, registry_path)
            cpu_model = str(winreg.QueryValueEx(cpu_key, "ProcessorNameString")[0]).strip()
        except OSError:
            pass
    try:
        import psutil
        logical_cores = psutil.cpu_count(logical=True)
        memory_bytes = psutil.virtual_memory().total
        psutil_version = version("psutil")
    except (ImportError, OSError):
        logical_cores, memory_bytes, psutil_version = None, None, "unavailable"
    try:
        pillow_version = version("Pillow")
    except Exception:
        pillow_version = "unavailable"
    return {
        "windows_build": platform.version(),
        "windows_release": " ".join(platform.win32_ver()),
        "cpu_model": cpu_model,
        "logical_cores": logical_cores,
        "memory_total_bytes": memory_bytes,
        "psutil_version": psutil_version,
        "pillow_version": pillow_version,
    }


def run_stage() -> int:
    """Execute selected stage and write aggregate machine/human summaries.

    Returns:
        int: Zero only for a complete valid stage.
    """
    args = parse_args()
    output = args.output
    output.mkdir(parents=True, exist_ok=True)
    here = Path(__file__).resolve().parent
    protocol_path = here / "issue_361_tk_protocol.json"
    protocol = json.loads(protocol_path.read_text(encoding="utf-8"))
    stage = os.environ.get("PHP_GALLERY_TK_PERF_STAGE", protocol["stage"])
    baseline_sha = os.environ.get(
        "PHP_GALLERY_TK_PERF_BASELINE", protocol["baseline_sha"]
    )
    candidate_sha = os.environ.get(
        "PHP_GALLERY_TK_PERF_CANDIDATE", os.environ.get("GITHUB_SHA", "")
    )
    if stage not in ("pilot", "baseline", "compare"):
        raise RuntimeError(f"unsupported stage: {stage}")
    if stage == "compare" and not candidate_sha:
        raise RuntimeError("comparison requires immutable candidate SHA")
    configured_scenarios = protocol.get("pilot_scenarios")
    if configured_scenarios is not None and (
        not isinstance(configured_scenarios, list)
        or any(not isinstance(item, str) for item in configured_scenarios)
    ):
        raise ValueError("pilot_scenarios must be a JSON list of scenario names")
    selected_pilot_scenarios = select_pilot_scenarios(configured_scenarios)
    targeted_pilot = stage == "pilot" and selected_pilot_scenarios != SCENARIOS
    pilot_cells = matrix_cells(selected_pilot_scenarios)
    harness = here / "issue_361_tk_workloads.py"
    if not harness.is_file():
        raise RuntimeError(f"workload harness is absent: {harness.name}")
    deadline = time.monotonic() + TOTAL_TIMEOUT_SECONDS
    metadata = {
        "created_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "platform": platform.platform(), "python": sys.version,
        "processor": platform.processor(), "machine": platform.machine(), **host_metadata(),
        "harness_sha256": sha256_file(harness),
        "workloads_sha256": sha256_file(here / "issue_361_tk_workloads.py"),
        "driver_sha256": sha256_file(Path(__file__).resolve()),
        "protocol_sha256": sha256_file(protocol_path),
        "protocol_semantic_sha256": hashlib.sha256(json.dumps(
            {key: value for key, value in protocol.items() if key != "stage"},
            sort_keys=True, separators=(",", ":"),
        ).encode("utf-8")).hexdigest(),
        "protocol": protocol,
        "baseline_sha": baseline_sha, "candidate_sha": candidate_sha or None,
        "stage": stage, "targeted": targeted_pilot,
        "pilot_complete": False,
        "pilot_scenarios": list(selected_pilot_scenarios),
        "runner_image": os.environ.get("ImageOS"),
        "runner_image_version": os.environ.get("ImageVersion"),
        "display": os.environ.get("DISPLAY"),
    }
    records: list[RunRecord] = []
    comparison = None
    baseline_summary = None
    pilot_overhead = None
    status = "FAIL"
    order = []
    temporary = Path(tempfile.mkdtemp(prefix="issue-361-tk-", dir=output))
    try:
        roots: dict[str, Path] = {}
        sources = [("baseline", baseline_sha)]
        if stage == "compare":
            sources.append(("candidate", candidate_sha))
        for label, sha in sources:
            root = temporary / label
            root.mkdir()
            extract_commit(sha, root)
            roots[label] = root
        if stage == "pilot":
            order = [
                ("baseline", scenario, cache, 0, state)
                for index, (scenario, cache) in enumerate(pilot_cells)
                for state in (("on", "off") if index % 2 == 0 else ("off", "on"))
            ]
        elif stage == "baseline":
            order = [
                ("baseline", scenario, cache, trial, "on")
                for trial in range(MEASURED_REPETITIONS)
                for scenario, cache in matrix_cells()
            ]
        else:
            order = []
            for pair in range(MEASURED_REPETITIONS):
                for scenario, cache in matrix_cells():
                    pair_order = ("baseline", "candidate") if pair % 2 == 0 else (
                        "candidate", "baseline"
                    )
                    order.extend(
                        (source, scenario, cache, pair, "on") for source in pair_order
                    )
        for source, scenario, cache, trial, instrumentation in order:
            source_sha = baseline_sha if source == "baseline" else candidate_sha
            record = run_workload(
                harness, roots[source], output, scenario, cache, trial,
                instrumentation, source, source_sha, deadline,
            )
            records.append(record)
            save_records(output, [record], append=True)
        if stage == "compare":
            comparison = summarize_comparison(records)
            (output / "comparison.json").write_text(
                json.dumps(comparison, indent=2) + "\n", encoding="utf-8"
            )
        elif stage == "baseline":
            baseline_summary = summarize_baseline(records)
        else:
            pilot_overhead = summarize_pilot(records, selected_pilot_scenarios)

        if len(records) != len(order):
            raise RuntimeError("completed record count does not match required schedule")
        status = "PASS"
    except BenchmarkBlocked as error:
        metadata["error"] = str(error)
        status = "BLOCKED"
        print(metadata["error"], file=sys.stderr)
    except Exception as error:
        metadata["error"] = f"{type(error).__name__}: {error}"
        status = "FAIL"
        print(metadata["error"], file=sys.stderr)
    finally:
        save_records(output, records)
        shutil.rmtree(temporary, ignore_errors=True)

    metadata["pilot_complete"] = stage == "pilot" and status == "PASS" and not targeted_pilot
    required_count = len(order) if "order" in locals() else 0
    summary = {
        "schema_version": 1, "status": status, "stage": stage,
        "metadata": metadata, "valid_records": len(records),
        "required_records": len(order), "targeted": targeted_pilot,
        "pilot_complete": bool(metadata["pilot_complete"]),
        "pilot_scenarios": list(selected_pilot_scenarios),
        "comparison": comparison,
        "baseline_summary": baseline_summary, "pilot_overhead": pilot_overhead,
        "baseline_sha": baseline_sha, "candidate_sha": candidate_sha or None,
    }
    report_path = output / "report.json"
    report = json.loads(report_path.read_text(encoding="utf-8"))
    report.update(summary)
    report_path.write_text(
        json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    concise = json.dumps(summary, indent=2, sort_keys=True)
    (output / "summary.json").write_text(concise + "\n", encoding="utf-8")
    runtime = records[0]["report"]["metadata"] if records else {}
    runtime_keys = ("python", "tcl", "tk", "tk_windowing_system", "display", "pillow", "visual_evidence", "interaction_latency_semantics")
    runtime_values = {key: runtime[key] for key in runtime_keys if key in runtime}
    lines = [
        f"# Tk benchmark {stage}: {status}", "",
        f"- UTC: {metadata['created_at_utc']}",
        f"- Platform: {metadata['platform']} / {metadata['windows_build']}",
        f"- CPU: {metadata['cpu_model']} ({metadata['logical_cores']} logical cores); RAM: {metadata['memory_total_bytes']} bytes",
        f"- Runner image: {metadata['runner_image']} {metadata['runner_image_version']}",
        "- Host packages: psutil " + str(metadata["psutil_version"]) + "; Pillow " + str(metadata["pillow_version"]),
        f"- Workload runtime: {json.dumps(runtime_values, sort_keys=True)}",
        f"- Python: {sys.version.split()[0]}",
        f"- Baseline SHA: {baseline_sha}",
        f"- Candidate SHA: {candidate_sha or 'not used'}",
        f"- Harness SHA-256: {metadata['harness_sha256']}",
        f"- Workload SHA-256: {metadata['workloads_sha256']}",
        f"- Driver/statistics SHA-256: {metadata['driver_sha256']}",
        f"- Protocol SHA-256: {metadata['protocol_sha256']}",
        f"- Valid records: {len(records)} / {required_count}",
        "- Physical monitor latency: unavailable; not inferred.", "",
    ]
    if status != "PASS":
        lines.append(f"- Failure: {metadata.get('error', 'stage did not complete')}")
        lines.append("")
    if baseline_summary:
        lines.extend(["## Baseline process summaries", "", "| Cell | Metric | n | Mean | Median | Sample SD | P50 | P95 | P99 | Min | Max |", "|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|"])
        for cell, metrics in sorted(baseline_summary.items()):
            for metric, values in sorted(metrics.items()):
                lines.append(
                    f"| {cell} | {metric} | {MEASURED_REPETITIONS} | "
                    f"{values['mean']:.3f} | {values['median']:.3f} | "
                    f"{values['sample_stddev']:.3f} | {values['p50']:.3f} | "
                    f"{values['p95']:.3f} | {values['p99']:.3f} | "
                    f"{values['min']:.3f} | {values['max']:.3f} |"
                )
        lines.append("")
    if pilot_overhead:
        lines.extend(["## Pilot instrumentation overhead (descriptive)", "", "| Cell | On seconds | Off seconds | Overhead % |", "|---|---:|---:|---:|"])
        for cell, values in sorted(pilot_overhead.items()):
            lines.append(
                f"| {cell} | {values['instrumentation_on_seconds']:.3f} | "
                f"{values['instrumentation_off_seconds']:.3f} | "
                f"{values['percent_overhead']:.2f} |"
            )
        lines.append("")
    if comparison:
        for group, detail in sorted(comparison.items()):
            lines.extend([
                f"## {group} ({detail['pairs']} pairs)", "",
                "| Metric | Baseline mean | Candidate mean | Delta | Base/Candidate | Paired 95% CI |",
                "|---|---:|---:|---:|---:|---:|",
            ])
            for metric, value in sorted(detail["metrics"].items()):
                low, high = value["paired_delta_ci95"]
                lines.append(
                    f"| {metric} | {value['baseline']['mean']:.3f} | "
                    f"{value['candidate']['mean']:.3f} | "
                    f"{value['absolute_delta_mean']:.3f} | "
                    f"{value['baseline_to_candidate_ratio']} | "
                    f"[{low:.3f}, {high:.3f}] |"
                )
            lines.append("")
    lines.extend(["Pilot n=1 instrumentation overhead is descriptive only.", ""])
    markdown = "\n".join(lines)
    (output / "summary.md").write_text(markdown, encoding="utf-8")
    summary_target = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary_target:
        with Path(summary_target).open("a", encoding="utf-8") as stream:
            stream.write(markdown + "\n" + concise + "\n")
    (output / "metadata.json").write_text(
        json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    return 0 if status == "PASS" else 1


if __name__ == "__main__":
    sys.exit(run_stage())
