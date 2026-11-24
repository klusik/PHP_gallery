# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_434_tk_benchmark.py
# Module Type: Phase 2 Tk Performance Protocol Driver
# Purpose: Run and collect isolated Windows GUI measurements at exact revisions.
# Responsibilities:
#   - Use the existing Tk benchmark extraction and paired statistics.
#   - Verify independent child results and preserve failed trials.
#   - Collect all scheduled cells with exact workflow and configuration identity.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Run one hosted Tk workload cell or collect a complete evidence matrix."""

import argparse
import csv
import hashlib
import json
import math
import os
import re
import statistics
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import NotRequired, TypeAlias, TypedDict, cast

from issue_361_tk_benchmark import (
    BenchmarkBlocked,
    TOTAL_TIMEOUT_SECONDS,
    MetricSummary,
    bootstrap_delta_ci,
    describe,
    extract_commit,
    host_metadata,
    sha256_file,
)

JsonValue: TypeAlias = (
    str | int | float | bool | None | list["JsonValue"] | dict[str, "JsonValue"]
)

# Allow startup, fixture installation and teardown beyond each measured period.
# Type: int. Units: seconds. Scope: one hosted child process.
# Consumers: subprocess timeout. Rationale: 95 seconds accommodates native
# viewer startup and cleanup while preserving the central 40-minute deadline.
TRIAL_OVERHEAD_SECONDS = 95
# Bound stderr copied into the compact parent failure record.
# Type: int. Units: characters. Scope: one failed process summary.
# Consumers: trial error reporting. Rationale: 1200 retains actionable endings
# while complete stdout and stderr remain available in their own files.
DIAGNOSTIC_TAIL_CHARS = 1200
# Convert seconds and milliseconds for timeouts without reinterpreting units.
# Type: int. Units: milliseconds per second. Scope: one timed child.
# Consumers: subprocess timeout. Rationale: exact SI conversion.
MS_PER_SECOND = 1000

HERE = Path(__file__).resolve().parent
PROTOCOL = HERE / "issue_434_tk_protocol.json"
CHILD = HERE / "issue_434_tk_workloads.py"
HARNESS_FILES = (
    "issue_434_tk_benchmark.py",
    "issue_434_tk_workloads.py",
    "issue_434_tk_shell.py",
    "issue_434_tk_media.py",
    "issue_434_tk_presentation_probe.py",
    "issue_361_tk_benchmark.py",
    "issue_361_tk_workloads.py",
)
CORE_METRICS = (
    "scenario_elapsed_ms",
    "event_loop_drift_p50_ms",
    "event_loop_drift_p95_ms",
    "event_loop_drift_p99_ms",
    "event_loop_drift_max_ms",
    "process_cpu_ms",
    "process_peak_rss_bytes",
)
PRIMARY_LATENCIES = (
    "event_loop_drift_p95_ms", "event_loop_drift_p99_ms",
    "interaction_feedback_p95_ms", "interaction_feedback_p99_ms",
    "wheel_feedback_p95_ms", "lightbox_preview_p95_ms",
    "lightbox_final_p95_ms", "photos_viewport_feedback_p95_ms",
    "lightbox_regular_feedback_p95_ms", "lightbox_rapid_feedback_p95_ms",
    "lightbox_preview_request_to_canvas_publication_p95_ms",
    "lightbox_final_request_to_canvas_publication_p95_ms",
)


class Case(TypedDict):
    """Immutable synthetic workload settings shared by both source legs."""

    id: str
    scenario: str
    scale: int
    duration_ms: int
    interval_ms: int
    caches: list[str]
    phases: list[str]
    pilot: bool
    raster_size: list[int] | None
    repetitions: NotRequired[int]


class WorkloadProtocol(TypedDict):
    """Allowlisted configuration and predeclared acceptance margins."""

    schema_version: int
    baseline_sha: str
    seed: int
    runner_image: str
    repetitions: dict[str, int]
    thresholds: dict[str, float]
    cases: list[Case]


class Trial(TypedDict):
    """Commands and observations for one independent source process."""

    role: str
    source_sha: str
    trial: int
    status: str
    wall_seconds: float
    metrics: dict[str, float]
    missing_metrics: list[str]
    metadata: dict[str, JsonValue]
    error: str | None


class Comparison(TypedDict):
    """Descriptive and paired statistics for a common process-level metric."""

    baseline: MetricSummary
    candidate: MetricSummary
    paired_delta_mean: float
    paired_delta_median: float
    paired_delta_ci95: list[float]
    relative_delta: float | None
    baseline_to_candidate_ratio: float | None
    paired_deltas: list[float]
    gate: str
    regression_margin: float | None


class MatrixCell(TypedDict):
    """Workflow scheduler output for one measurement job."""

    case: str
    cache: str
    reference: str
    reference_sha: str
    repetition: str


class CollectionSource(TypedDict):
    """Provider-bound identity and matrix for evidence collection."""

    sha: str
    run_id: str
    stage: str
    profile: str
    checkpoint: str
    cell_result: str
    matrix: list[MatrixCell]
    protocol_bytes: bytes


def write_json(path: Path, payload: JsonValue) -> None:
    """Write a machine-readable artifact with deterministic JSON formatting.

    Args:
        path (Path): Destination inside an Actions artifact directory.
        payload (JsonValue): Structured benchmark evidence without nonfinite numbers.

    Returns:
        None: The artifact contains the serialized evidence.
    """
    path.write_text(
        json.dumps(payload, indent=2, ensure_ascii=False, allow_nan=False) + "\n",
        encoding="utf-8",
    )


def stable_digest(payload: JsonValue) -> str:
    """Hash a normalized fixture specification or harness digest map.

    Args:
        payload (JsonValue): Deterministic JSON data without host-specific paths.

    Returns:
        str: SHA-256 of compact key-sorted UTF-8 JSON.
    """
    wire = json.dumps(payload, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(wire.encode("utf-8")).hexdigest()


def child_contract(
    data: JsonValue, expected: dict[str, JsonValue],
) -> dict[str, JsonValue]:
    """Require exact process identity and raw timed observations.

    Args:
        data (JsonValue): Decoded workload report provided by the child.
        expected (dict[str, JsonValue]): Required scenario and source identity.

    Returns:
        dict[str, JsonValue]: Structurally validated success report.
    """
    if not isinstance(data, dict):
        raise RuntimeError("Child report is not a JSON object.")
    if any(data.get(key) != value for key, value in expected.items()):
        raise RuntimeError("Child report identity differs from its request.")
    if data.get("status") == "BLOCKED":
        raise BenchmarkBlocked(str(data.get("failure")))
    if data.get("status") != "PASS" or data.get("failure"):
        raise RuntimeError(str(data.get("failure") or "Child reported failure."))
    metrics = data.get("metrics")
    if not isinstance(metrics, dict) or not set(CORE_METRICS) <= metrics.keys():
        raise RuntimeError("Child report omitted a required drift, CPU or RSS metric.")
    for metric, value in metrics.items():
        if type(value) not in (int, float) or not math.isfinite(value) or value < 0:
            raise RuntimeError(f"Invalid process measurement {metric}.")
    events = data.get("raw_events")
    if not isinstance(events, list) or not events or data.get("raw_events_truncated"):
        raise RuntimeError("Required raw event evidence is missing or truncated.")
    operations = data.get("operations")
    if not isinstance(operations, dict):
        raise RuntimeError("Child report omitted operation counts.")
    if operations.get("shell_import_worker_error_count", 0):
        raise RuntimeError(
            "Synthetic Import worker failed: "
            + str(operations.get("shell_import_worker_last_error")),
        )
    if operations.get("shell_required_operations_met") is False:
        raise RuntimeError("Shell child did not complete its required workload operations.")
    scale = expected["scale"]
    if expected["scenario"] == "import" and operations.get("shell_import_rows") != scale:
        raise RuntimeError("Import child did not mount the requested item extent.")
    loaded_photos = operations.get("media_loaded_photos")
    if loaded_photos is not None and loaded_photos != scale:
        raise RuntimeError("Photos child did not load the requested catalogue extent.")
    if not isinstance(data.get("metadata"), dict):
        raise RuntimeError("Child report omitted environment metadata.")
    missing = data.get("missing_metrics")
    if not isinstance(missing, list) or any(type(name) is not str for name in missing):
        raise RuntimeError("Child report did not disclose missing metrics.")
    return data


def run_trial(
    case: Case,
    cache: str,
    role: str,
    source_sha: str,
    source_root: Path,
    seed: int,
    trial: int,
    deadline: float,
    output: Path,
    profile: bool,
) -> Trial:
    """Run and retain an independent Tk process using the shared harness.

    Args:
        case (Case): Frozen workload dimensions and timing.
        cache (str): Cold or separately primed warm cache mode.
        role (str): Baseline or candidate role in this cell.
        source_sha (str): Exact production commit extracted into source_root.
        source_root (Path): Isolated app subtree for this process.
        seed (int): Fixed fixture seed shared by all source revisions.
        trial (int): Zero-based pair identity shared by both legs.
        deadline (float): Monotonic deadline of the whole cell.
        output (Path): Retained evidence directory.
        profile (bool): Whether to run separate pilot-only profiling.

    Returns:
        Trial: Outcome, metrics, and useful failure context for one attempt.
    """
    stem = f"{trial:03d}-{role}"
    path = output / f"{stem}.json"
    expected: dict[str, JsonValue] = {
        "schema_version": 1, "trial": trial, "scenario": case["scenario"],
        "cache": cache, "source": source_sha, "seed": seed,
        "scale": case["scale"], "duration_ms": case["duration_ms"],
        "interval_ms": case["interval_ms"], "raster_size": case["raster_size"],
        "instrumentation": "on",
    }
    command = [
        sys.executable, str(CHILD), "--source", str(source_root),
        "--source-id", source_sha, "--scenario", case["scenario"],
        "--cache", cache, "--trial", str(trial), "--seed", str(seed),
        "--scale", str(case["scale"]), "--duration-ms", str(case["duration_ms"]),
        "--interval-ms", str(case["interval_ms"]), "--output", str(path),
    ]
    if case["raster_size"] is not None:
        width, height = case["raster_size"]
        command.extend(["--raster-width", str(width), "--raster-height", str(height)])
    if profile:
        command.append("--profile")
    started = time.monotonic()
    result: Trial = {
        "role": role, "source_sha": source_sha, "trial": trial, "status": "FAIL",
        "wall_seconds": 0.0, "metrics": {}, "missing_metrics": [],
        "metadata": {}, "error": None,
    }
    stdout, stderr = b"", b""
    try:
        remaining = deadline - started
        if remaining <= 0:
            raise BenchmarkBlocked("Cell deadline expired before child launch.")
        timeout = min(
            remaining, case["duration_ms"] / MS_PER_SECOND + TRIAL_OVERHEAD_SECONDS,
        )
        completed = subprocess.run(
            command, check=False, capture_output=True, timeout=timeout,
        )
        stdout, stderr = completed.stdout, completed.stderr
        if not path.is_file():
            raise RuntimeError(f"Child exited {completed.returncode} without JSON.")
        data = child_contract(json.loads(path.read_text(encoding="utf-8")), expected)
        if completed.returncode:
            raise RuntimeError(f"Child exited {completed.returncode} with PASS JSON.")
        result["metrics"] = cast(dict[str, float], data["metrics"])
        result["missing_metrics"] = cast(list[str], data["missing_metrics"])
        result["metadata"] = cast(dict[str, JsonValue], data["metadata"])
        result["status"] = "PASS"
    except subprocess.TimeoutExpired as error:
        stdout, stderr = error.stdout or b"", error.stderr or b""
        result["status"] = "BLOCKED"
        result["error"] = "Independent workload exceeded its process deadline."
    except (BenchmarkBlocked, OSError, RuntimeError, ValueError) as error:
        result["status"] = "BLOCKED" if isinstance(error, BenchmarkBlocked) else "FAIL"
        result["error"] = str(error)
    finally:
        (output / f"{stem}.stdout.txt").write_bytes(stdout)
        (output / f"{stem}.stderr.txt").write_bytes(stderr)
    if result["error"] and stderr:
        result["error"] += "; " + stderr.decode(
            "utf-8", "replace",
        )[-DIAGNOSTIC_TAIL_CHARS:].strip()
    result["wall_seconds"] = time.monotonic() - started
    return result


def metric_comparison(
    baseline: list[float],
    candidate: list[float],
    metric: str,
    seed_key: str,
    thresholds: dict[str, float],
) -> Comparison:
    """Apply the preregistered paired uncertainty and regression margins.

    Args:
        baseline (list[float]): Process metrics in matched baseline trial order.
        candidate (list[float]): Corresponding process metrics in candidate order.
        metric (str): Metric name with a semantic unit suffix.
        seed_key (str): Stable cell and reference identity for bootstrap samples.
        thresholds (dict[str, float]): Absolute and relative preregistered margins.

    Returns:
        Comparison: Descriptive summaries, paired effects and guard classification.
    """
    left, right = describe(baseline), describe(candidate)
    deltas = [b - a for a, b in zip(baseline, candidate, strict=True)]
    interval = bootstrap_delta_ci(deltas, f"{seed_key}/{metric}")
    delta = statistics.fmean(deltas)
    margin = None
    lower_is_better = True
    if metric in PRIMARY_LATENCIES:
        margin = max(
            thresholds["absolute_latency_ms"],
            left["median"] * thresholds["relative_latency"],
        )
    elif metric == "import_items_per_second":
        margin = left["median"] * thresholds["throughput_loss"]
        lower_is_better = False
    elif metric in ("process_peak_rss_bytes", "process_steady_rss_bytes"):
        margin = max(
            thresholds["rss_growth_bytes"], left["median"] * thresholds["relative_rss_growth"],
        )
    worsening = interval if lower_is_better else [-interval[1], -interval[0]]
    gate = "observed"
    if margin is not None:
        if worsening[0] > margin:
            gate = "investigate" if metric.endswith("_rss_bytes") else "regression"
        elif worsening[1] > margin:
            gate = "inconclusive"
        else:
            gate = "within_margin"
    return {
        "baseline": left, "candidate": right, "paired_delta_mean": delta,
        "paired_delta_median": statistics.median(deltas),
        "paired_delta_ci95": interval,
        "relative_delta": delta / left["mean"] if left["mean"] else None,
        "baseline_to_candidate_ratio": (
            left["mean"] / right["mean"] if right["mean"] and left["mean"] else None
        ),
        "paired_deltas": deltas, "gate": gate, "regression_margin": margin,
    }


def statistics_for_cell(
    records: list[Trial], case_key: str, thresholds: dict[str, float],
) -> dict[str, JsonValue]:
    """Summarize schedule results without discarding failed independent trials.

    Args:
        records (list[Trial]): Complete order of intended child runs and outcomes.
        case_key (str): Reference, workload and cache identifier.
        thresholds (dict[str, float]): Fixed Phase 2 regression margins.

    Returns:
        dict[str, JsonValue]: Counts, common metric summaries and missing samples.
    """
    baseline = {r["trial"]: r for r in records
                if r["role"] == "baseline" and r["status"] == "PASS"}
    candidate = {r["trial"]: r for r in records
                 if r["role"] == "candidate" and r["status"] == "PASS"}
    pairs = sorted(baseline.keys() & candidate.keys())
    available = sorted({
        metric for record in (*baseline.values(), *candidate.values())
        for metric in record["metrics"]
    })
    base_stats: dict[str, MetricSummary] = {}
    comparisons: dict[str, Comparison] = {}
    sample_counts: dict[str, list[int]] = {}
    for name in available:
        observed = [r["metrics"][name] for r in baseline.values() if name in r["metrics"]]
        paired = [i for i in pairs
                  if name in baseline[i]["metrics"] and name in candidate[i]["metrics"]]
        sample_counts[name] = [len(observed), len(paired)]
        if observed:
            base_stats[name] = describe(observed)
        if paired and len(paired) == len(pairs):
            comparisons[name] = metric_comparison(
                [baseline[i]["metrics"][name] for i in paired],
                [candidate[i]["metrics"][name] for i in paired],
                name, case_key, thresholds,
            )
    return {
        "successful_baselines": len(baseline), "pairs": len(pairs),
        "sample_counts": cast(JsonValue, sample_counts),
        "baseline": cast(JsonValue, base_stats), "paired": cast(JsonValue, comparisons),
    }


def run_cell(output: Path, protocol: WorkloadProtocol) -> int:
    """Schedule one isolated workload cell on one Windows virtual machine.

    Args:
        output (Path): Existing central audit evidence directory.
        protocol (WorkloadProtocol): Checked exact-commit experiment inputs.

    Returns:
        int: Zero only when every scheduled workload process passes.
    """
    stage = os.environ["PHP_GALLERY_TK_PERF_STAGE"]
    reference_sha = os.environ["PHP_GALLERY_TK_PERF_BASELINE"]
    candidate_sha = os.environ["PHP_GALLERY_TK_PERF_CANDIDATE"]
    reference = os.environ["PHP_GALLERY_TK_PHASE2_REFERENCE"]
    checkpoint = os.environ["PHP_GALLERY_TK_PHASE2_CHECKPOINT"]
    case_id = os.environ["PHP_GALLERY_TK_PHASE2_CASE"]
    cache = os.environ["PHP_GALLERY_TK_PHASE2_CACHE"]
    repetition = os.environ["PHP_GALLERY_TK_PHASE2_REPETITION"]
    planned = [case for case in protocol["cases"] if case["id"] == case_id]
    if len(planned) != 1 or cache not in planned[0]["caches"]:
        raise ValueError("Selected workload and cache do not exist in the frozen protocol.")
    case = planned[0]
    if reference == "B0" and reference_sha != protocol["baseline_sha"]:
        raise ValueError("Selected B0 differs from the immutable production baseline.")
    if stage == "compare" and reference_sha == candidate_sha:
        raise ValueError("A comparison needs distinct production SHAs.")
    if stage != "compare" and reference != "B0":
        raise ValueError("Pilot and baseline must run on the immutable B0 source.")
    if stage == "pilot":
        repeats = protocol["repetitions"]["pilot"]
    else:
        repeats = case.get("repetitions", protocol["repetitions"][repetition])
    sources = {"baseline": reference_sha}
    if stage == "compare":
        sources["candidate"] = candidate_sha
    host = {
        **host_metadata(), "python": sys.version, "platform": sys.platform,
        "runner_image_label": protocol["runner_image"],
        "runner_image": os.environ.get("ImageOS"),
        "runner_image_version": os.environ.get("ImageVersion"),
    }
    digests = {name: sha256_file(HERE / name) for name in HARNESS_FILES}
    records: list[Trial] = []
    error = None
    status = "FAIL"
    output_trials = output / "trials"
    output_trials.mkdir()
    deadline = time.monotonic() + TOTAL_TIMEOUT_SECONDS
    try:
        with tempfile.TemporaryDirectory(prefix="issue434-src-") as temporary:
            roots = {role: Path(temporary) / role for role in sources}
            for role, sha in sources.items():
                roots[role].mkdir()
                extract_commit(sha, roots[role])
            for trial in range(repeats):
                order = tuple(sources)
                # Alternating AB/BA puts both legs on this same VM.
                if trial % 2:
                    order = order[::-1]
                for role in order:
                    pilot_profile = stage == "pilot" and case_id in (
                        "watcher-burst", "import-5000", "rapid-4k",
                    )
                    record = run_trial(
                        case, cache, role, sources[role], roots[role], protocol["seed"],
                        trial, deadline, output_trials, pilot_profile,
                    )
                    records.append(record)
                    print(f"{case_id}/{cache} {trial}/{role}: {record['status']}", flush=True)
                    if record["error"]:
                        print(record["error"], flush=True)
        counts = {record["status"] for record in records}
        status = "FAIL" if "FAIL" in counts else "BLOCKED" if "BLOCKED" in counts else "PASS"
    except (OSError, RuntimeError, subprocess.SubprocessError) as reason:
        error = str(reason)
    key = f"{reference}/{case_id}/{cache}"
    stats = statistics_for_cell(records, key, protocol["thresholds"])
    succeeded = sum(record["status"] == "PASS" for record in records)
    expected_runs = repeats * len(sources)
    summary = f"{key}: {succeeded}/{expected_runs} independent workload processes passed"
    if error:
        summary += f"; {error}"
    else:
        first_failure = next((r["error"] for r in records if r["error"]), None)
        if first_failure:
            summary += f"; first failure: {first_failure}"
    report: dict[str, JsonValue] = {
        "schema_version": 1, "status": status, "summary": summary,
        "stage": stage, "candidate_sha": candidate_sha, "baseline_sha": reference_sha,
        "reference": reference, "checkpoint": checkpoint, "cell": key,
        "cache": cache, "case": cast(JsonValue, case),
        "run_id": os.environ.get("GITHUB_RUN_ID"),
        "run_attempt": os.environ.get("GITHUB_RUN_ATTEMPT"),
        "immutable_source_base": os.environ.get("PHP_GALLERY_SOURCE_BASE"),
        "harness_sha": candidate_sha, "harness_sha256": stable_digest(digests),
        "harness_files": cast(JsonValue, digests),
        "protocol_sha256": sha256_file(PROTOCOL),
        "fixture_configuration_sha256": stable_digest({
            "case": cast(JsonValue, case), "seed": protocol["seed"],
        }),
        "expected_trials": expected_runs, "host": cast(JsonValue, host),
        "records": cast(JsonValue, records), "statistics": stats,
    }
    write_json(output / "report.json", report)
    print(f"{status} {summary}", flush=True)
    return 0 if status == "PASS" else 1


def report_from_artifact(
    folder: Path, cell: MatrixCell, source: CollectionSource,
) -> dict[str, JsonValue]:
    """Read one unique audit result corresponding to an expected matrix cell.

    Args:
        folder (Path): Downloaded artifact directory named by its cell.
        cell (MatrixCell): Immutable provider job matrix coordinates.
        source (CollectionSource): Exact original provider run and protocol bytes.

    Returns:
        dict[str, JsonValue]: Verified report for the requested hosted source.
    """
    paths = [path for path in folder.rglob("report.json") if path.parent.name == "issue-434"]
    if len(paths) != 1:
        raise RuntimeError(f"Expected one central report, found {len(paths)}.")
    data = json.loads(paths[0].read_text(encoding="utf-8"))
    sha = source["sha"]
    case_id = f"{cell['reference']}/{cell['case']}/{cell['cache']}"
    expected = {
        "schema_version": 1, "cell": case_id, "harness_sha": sha,
        "candidate_sha": sha, "baseline_sha": cell["reference_sha"],
        "stage": source["stage"], "run_id": source["run_id"],
    }
    if not isinstance(data, dict):
        raise RuntimeError(f"Central report for {case_id} is not an object.")
    mismatched = [key for key, value in expected.items() if data.get(key) != value]
    if mismatched:
        raise RuntimeError(f"Central report identity mismatch for {case_id}: {mismatched}.")
    attempt = str(data.get("run_attempt", ""))
    if not attempt.isdecimal() or int(attempt) < 1:
        raise RuntimeError(f"Missing measurement attempt for {case_id}.")
    if (source["run_id"] == os.environ["GITHUB_RUN_ID"]
            and int(attempt) > int(os.environ["GITHUB_RUN_ATTEMPT"])):
        raise RuntimeError(f"Unexpected later measurement attempt for {case_id}.")
    # Git may present identical tracked JSON with LF or CRLF on Windows.
    # Accept only those two encodings of the exact source protocol bytes.
    canonical = source["protocol_bytes"].replace(b"\r\n", b"\n")
    protocol_hashes = {
        hashlib.sha256(canonical).hexdigest(),
        hashlib.sha256(canonical.replace(b"\n", b"\r\n")).hexdigest(),
    }
    if data.get("protocol_sha256") not in protocol_hashes:
        raise RuntimeError(f"Protocol bytes did not match the evidence for {case_id}.")
    protocol = cast(WorkloadProtocol, json.loads(canonical))
    case = next((item for item in protocol["cases"] if item["id"] == cell["case"]), None)
    if case is None or data.get("case") != case:
        raise RuntimeError(f"Reported workload settings do not match {case_id}.")
    records = data.get("records")
    if not isinstance(records, list) or data.get("status") not in {"PASS", "FAIL", "BLOCKED"}:
        raise RuntimeError(f"Invalid outcome structure for {case_id}.")
    if data["status"] == "PASS":
        repetitions = (
            protocol["repetitions"]["pilot"] if source["stage"] == "pilot"
            else case.get("repetitions", protocol["repetitions"][cell["repetition"]])
        )
        roles = {"baseline": cell["reference_sha"]}
        if source["stage"] == "compare":
            roles["candidate"] = sha
        expected_trials = {
            (role, trial, production_sha)
            for role, production_sha in roles.items()
            for trial in range(repetitions)
        }
        passed_trials = {
            (record.get("role"), record.get("trial"), record.get("source_sha"))
            for record in records
            if isinstance(record, dict) and record.get("status") == "PASS"
        }
        if (
            data.get("expected_trials") != len(expected_trials)
            or len(records) != len(expected_trials) or passed_trials != expected_trials
        ):
            raise RuntimeError(f"Successful report has incomplete trial coverage for {case_id}.")
    return cast(dict[str, JsonValue], data)


def collect(input_dir: Path, output: Path, source: CollectionSource) -> int:
    """Collect the declared matrix and preserve complete and failed outcomes.

    Args:
        input_dir (Path): Per-cell Actions artifacts from this workflow run.
        output (Path): Destination for compact JSON, CSV and Markdown evidence.
        source (CollectionSource): Original workflow and matrix to authenticate.

    Returns:
        int: Zero only for complete, exact-run measurement evidence.
    """
    reports: list[dict[str, JsonValue]] = []
    failures: list[dict[str, str]] = []
    checkpoint = source["checkpoint"]
    profile = source["profile"]
    lines = [
        f"## WinApp responsiveness · {profile} {checkpoint}", "",
        f"Harness SHA: `{source['sha']}` from run `{source['run_id']}`", "",
        "| Reference / workload / cache | Measurement | Baselines | Pairs | B0/Ref drift p95 ms | Candidate drift p95 ms |",
        "| --- | --- | ---: | ---: | ---: | ---: |",
    ]
    for cell in source["matrix"]:
        name = f"{cell['reference']}-{cell['case']}-{cell['cache']}"
        try:
            report = report_from_artifact(input_dir / f"issue-434-cell-{name}", cell, source)
            reports.append(report)
            stats = cast(dict[str, JsonValue], report["statistics"])
            status = str(report["status"])
            baseline = cast(dict[str, MetricSummary], stats["baseline"])
            paired = cast(dict[str, Comparison], stats["paired"])
            drift = "event_loop_drift_p95_ms"
            left = f"{baseline[drift]['median']:.2f}" if drift in baseline else "—"
            right = f"{paired[drift]['candidate']['median']:.2f}" if drift in paired else "—"
            if status != "PASS":
                failures.append({"cell": name, "error": str(report["summary"])})
            lines.append(
                f"| {report['cell']} | {status} | {stats['successful_baselines']} "
                f"| {stats['pairs']} | {left} | {right} |",
            )
        except (OSError, RuntimeError, ValueError, KeyError) as error:
            failures.append({"cell": name, "error": str(error)})
            lines.append(f"| {name} | MISSING | 0 | 0 | — | — |")
    if not source["matrix"] or source["cell_result"] != "success":
        failures.append({"cell": "matrix", "error": "One or more hosted cell jobs did not succeed."})
    if failures:
        lines.extend(("", "### Unqualified measurements", ""))
        lines.extend(f"- {row['cell']}: {row['error']}" for row in failures)
    status = "FAIL" if failures else "PASS"
    lines.extend(("", f"**Measurement coverage: {status}.**",
                  "Tk timing is a software proxy for presentation."))
    (output / "summary.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    write_json(output / "summary.json", {
        "schema_version": 1, "status": status, "profile": profile,
        "checkpoint": checkpoint, "harness_sha": source["sha"],
        "run_id": source["run_id"], "cases": cast(JsonValue, reports),
        "collector_sha": os.environ["GITHUB_SHA"],
        "collector_run_id": os.environ["GITHUB_RUN_ID"],
        "collector_attempt": os.environ["GITHUB_RUN_ATTEMPT"],
        # A failed-job rerun can validly collect successful cells from earlier
        # attempts of the same immutable run. Every cell carries its attempt.
        "measurement_attempts": sorted({str(report["run_attempt"]) for report in reports}, key=int),
        "failures": cast(JsonValue, failures),
    })
    write_json(output / "environment.json", {
        str(report["cell"]): {
            "host": report["host"],
            "sources": [record["source_sha"]
                        for record in cast(list[Trial], report["records"])],
            "child_metadata": [record["metadata"]
                               for record in cast(list[Trial], report["records"])],
        }
        for report in reports
    })
    (output / PROTOCOL.name).write_bytes(source["protocol_bytes"])
    with (output / "raw_records.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.writer(stream)
        writer.writerow(("cell", "role", "sha", "trial", "status", "metric", "value", "error"))
        for report in reports:
            for record in cast(list[Trial], report["records"]):
                prefix = (
                    report["cell"], record["role"], record["source_sha"],
                    record["trial"], record["status"],
                )
                if record["metrics"]:
                    for key, value in sorted(record["metrics"].items()):
                        writer.writerow((*prefix, key, value, ""))
                else:
                    writer.writerow((*prefix, "", "", record["error"]))
    return 0 if status == "PASS" else 1


def recover_baseline(
    run_id: str, input_dir: Path, output: Path,
) -> int:
    """Recollect existing Windows cells after a reporting-only failure.

    Args:
        run_id (str): Original completed Phase 2 baseline Actions run ID.
        input_dir (Path): Downloaded original per-cell artifacts.
        output (Path): Recovery artifact directory for this workflow.

    Returns:
        int: Zero only for independently authenticated complete B0 evidence.
    """
    if not re.fullmatch(r"[1-9][0-9]*", run_id):
        raise ValueError("Invalid original baseline run ID.")
    repo = os.environ["GITHUB_REPOSITORY"]
    view = subprocess.check_output([
        "gh", "run", "view", run_id, "--repo", repo,
        "--json", "databaseId,headBranch,headSha,status,workflowName,jobs",
    ], text=True, encoding="utf-8")
    run = json.loads(view)
    sha = run["headSha"]
    if (
        run["status"] != "completed" or str(run["databaseId"]) != run_id
        or run["headBranch"] != os.environ["GITHUB_REF_NAME"]
        or run["workflowName"] != "Issue 434 WinApp GUI responsiveness"
        or not re.fullmatch(r"[a-f0-9]{40}", sha)
    ):
        raise ValueError("Original run is not a completed owned baseline workflow.")
    native = json.loads(subprocess.check_output([
        "gh", "api", f"repos/{repo}/actions/runs/{run_id}",
    ]))
    workflow_path = ".github/workflows/issue-434-tk-performance.yml"
    if (
        native.get("head_sha") != sha
        or str(native.get("path", "")).partition("@")[0] != workflow_path
    ):
        raise ValueError("Original run provider evidence has a different source or workflow.")
    subprocess.run(["git", "merge-base", "--is-ancestor", sha, "HEAD"], check=True)
    archived_protocol = subprocess.check_output([
        "git", "show", f"{sha}:tools/perf/{PROTOCOL.name}",
    ])
    protocol = cast(WorkloadProtocol, json.loads(archived_protocol))
    if protocol["baseline_sha"] != json.loads(PROTOCOL.read_bytes())["baseline_sha"]:
        raise ValueError("Original experiment uses a different immutable B0.")
    matrix: list[MatrixCell] = [
        {
            "case": case["id"], "cache": cache, "reference": "B0",
            "reference_sha": protocol["baseline_sha"], "repetition": "primary",
        }
        for case in protocol["cases"]
        for cache in case["caches"]
    ]
    expected_jobs = {f"B0 · {c['case']} · {c['cache']}" for c in matrix}
    jobs = [job for job in run["jobs"] if job["name"] in expected_jobs]
    all_passed = (
        {job["name"] for job in jobs} == expected_jobs
        and len(jobs) == len(expected_jobs)
        and all(job["conclusion"] == "success" for job in jobs)
    )
    return collect(input_dir, output, {
        "sha": sha, "run_id": run_id, "stage": "baseline", "profile": "baseline",
        "checkpoint": "B0", "cell_result": "success" if all_passed else "failure",
        "matrix": matrix, "protocol_bytes": archived_protocol,
    })


def main(argv: list[str] | None = None) -> int:
    """Select one exact cell or collect its hosted matrix.

    Args:
        argv (list[str] | None): Optional argument vector excluding executable name.

    Returns:
        int: Zero only when the requested hosted command completes successfully.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--collect", type=Path)
    parser.add_argument("--recover-baseline")
    args = parser.parse_args(argv)
    if not args.output.is_absolute():
        parser.error("--output requires an absolute path")
    if os.environ.get("GITHUB_ACTIONS") != "true":
        parser.error("this benchmark requires GitHub Actions")
    args.output.mkdir(parents=True, exist_ok=True)
    if args.collect:
        if args.recover_baseline:
            return recover_baseline(args.recover_baseline, args.collect, args.output)
        return collect(args.collect, args.output, {
            "sha": os.environ["GITHUB_SHA"],
            "run_id": os.environ["GITHUB_RUN_ID"],
            "stage": os.environ["PHP_GALLERY_TK_PERF_STAGE"],
            "profile": os.environ["PHP_GALLERY_TK_PHASE2_PROFILE"],
            "checkpoint": os.environ["PHP_GALLERY_TK_PHASE2_CHECKPOINT"],
            "cell_result": os.environ["PHP_GALLERY_TK_PHASE2_CELL_RESULT"],
            "matrix": cast(list[MatrixCell], json.loads(
                os.environ["PHP_GALLERY_TK_PHASE2_MATRIX"],
            )["include"]),
            "protocol_bytes": PROTOCOL.read_bytes(),
        })
    protocol = cast(WorkloadProtocol, json.loads(PROTOCOL.read_text(encoding="utf-8")))
    if sys.platform != "win32" or not re.fullmatch(
        r"[a-f0-9]{40}", os.environ.get("GITHUB_SHA", ""),
    ):
        parser.error("an exact Windows hosted revision is required")
    return run_cell(args.output, protocol)


if __name__ == "__main__":
    raise SystemExit(main())
