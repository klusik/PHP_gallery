#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: scripts/audit_winapp_partitioned.py
# Module Type: WinApp Audit Coordinator
# Purpose: Run complete WinApp discovery in sequential class-preserving processes.
# Responsibilities:
#   - Partition canonical test classes deterministically into bounded children
#   - Require the complete disjoint test identity union before reporting success
#   - Preserve child reports, process outcomes and elapsed times for the PHP audit
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Coordinate complete WinApp discovery across serial class-based processes."""

import json
import math
import subprocess
import sys
import time
from collections import OrderedDict
from pathlib import Path
from typing import TypeAlias, TypeGuard

from scripts.audit_winapp_report import AuditReport
from scripts.audit_winapp_report import PartitionCoverageProof, PartitionProcess
from scripts.audit_winapp_report import discover_canonical_suite, flatten_suite
from scripts.audit_winapp_report import write_complete_report


# Purpose: Describe every value shape accepted from decoded JSON evidence.
# Type: recursive JSON value union. Units: values. Scope: untrusted report input.
# Consumers: validate_child_report(). Rationale: retain malformed JSON values
# for explicit runtime rejection while giving the declaration a concrete shape.
JSONValue: TypeAlias = (
    type(None) | bool | int | float | str | list["JSONValue"]
    | dict[str, "JSONValue"]
)


# Purpose: Bound untrusted selected-child test identity strings.
# Type: int. Units: characters. Scope: one child report test identifier.
# Consumers: validate_child_report().
# Rationale: Match the central PHP validator's established 4,096-character cap.
CHILD_TEST_ID_LIMIT = 4096

# Purpose: Bound untrusted selected-child outcome reasons and tracebacks.
# Type: int. Units: characters. Scope: one child report reason field.
# Consumers: validate_child_report().
# Rationale: Match the central PHP validator's established 16,384-character cap.
CHILD_REASON_LIMIT = 16384

# Purpose: Isolate the observed long-running viewer test module in one child.
# Type: frozenset[str]. Units: unittest module names. Scope: five WinApp shards.
# Consumers: partition_test_ids(). Rationale: hosted timings showed the viewer
# module alone approaches the existing per-child limit; keep all its classes
# separate from every isolated benchmark and the remaining canonical inventory.
PARTITION_ISOLATED_MODULES = frozenset({"test_native_gallery_viewer"})

# Purpose: Isolate measured benchmark and preload classes in separate children.
# Type: tuple[str, ...]. Units: unittest module/class identities.
# Scope: five WinApp shards. Consumers: partition_test_ids().
# Rationale: the two filesystem cohorts and real-session preload benchmark each
# approach the existing child deadline and must not share another slow owner.
PARTITION_ISOLATED_CLASSES = (
    "test_gallery_read_linking.ThumbnailCacheSmallBenchmarkTests",
    "test_gallery_read_linking.ThumbnailCacheLargeBenchmarkTests",
    "test_gallery_preload.GalleryPreloadWorkerTests",
)


def partition_test_ids(test_ids: list[str], count: int) -> list[list[str]]:
    """Isolate measured owners or balance whole classes when policies are absent.

    Args:
        test_ids (list[str]): Canonical ordered unittest IDs.
        count (int): Required partition count.
    Returns:
        list[list[str]]: Disjoint class-preserving IDs in stable shard order;
            the measured module and classes each occupy separate
            shards when all configured groups are discovered.
    """
    if count < 2 or len(test_ids) != len(set(test_ids)):
        raise ValueError("Partition count or canonical test identities are invalid.")
    classes: OrderedDict[str, list[str]] = OrderedDict()
    for test_id in test_ids:
        class_id = test_id.rpartition('.')[0]
        if not class_id:
            raise ValueError(f"Canonical test identity lacks a class: {test_id}")
        classes.setdefault(class_id, []).append(test_id)
    if len(classes) < count:
        raise ValueError("Canonical inventory has fewer classes than partitions.")

    # Count each isolated class plus native-module and canonical-remainder buckets.
    isolation_group_count = len(PARTITION_ISOLATED_CLASSES) + 2
    if count == isolation_group_count:
        viewer_ids: list[str] = []
        benchmark_ids_by_class: dict[str, list[str]] = {
            class_id: [] for class_id in PARTITION_ISOLATED_CLASSES
        }
        remaining_ids: list[str] = []
        for test_id in test_ids:
            class_id = test_id.rpartition('.')[0]
            module_name = class_id.rpartition('.')[0]
            if module_name in PARTITION_ISOLATED_MODULES:
                viewer_ids.append(test_id)
            elif class_id in benchmark_ids_by_class:
                benchmark_ids_by_class[class_id].append(test_id)
            else:
                remaining_ids.append(test_id)
        isolated_ids = [
            benchmark_ids_by_class[class_id]
            for class_id in PARTITION_ISOLATED_CLASSES
        ]
        if viewer_ids and all(isolated_ids) and remaining_ids:
            shards = [viewer_ids]
            shards.extend(isolated_ids)
            shards.append(remaining_ids)
            return shards

    shards: list[list[str]] = [[] for _ in range(count)]
    weights = [0] * count
    indexed_classes = list(enumerate(classes.values()))
    for _, class_tests in sorted(
        indexed_classes, key=lambda item: (-len(item[1]), item[0]),
    ):
        target = min(range(count), key=lambda index: (weights[index], index))
        shards[target].extend(class_tests)
        weights[target] += len(class_tests)
    positions = {test_id: index for index, test_id in enumerate(test_ids)}
    return [sorted(shard, key=positions.__getitem__) for shard in shards]


def validate_child_report(
    report: AuditReport | JSONValue,
) -> TypeGuard[AuditReport]:
    """Validate a selected-ID child report before any aggregate can normalize it.

    Args:
        report (AuditReport | JSONValue): A schema-1 audit mapping or any
            decoded JSON value: null, boolean, number, string, list of JSON
            values, or string-keyed mapping of JSON values. Acceptance requires
            the exact selected-ID report mapping with runtime, counts, named
            test/subtest outcomes, and fixture lists; malformed mappings and
            full-suite reports are refused.
    Returns:
        TypeGuard[AuditReport]: True only for a complete, self-consistent
            selected-partition report.
    """
    if not isinstance(report, dict) or set(report) != {
        "schema_version", "successful", "runtime", "counts", "tests",
        "fixture_errors", "fixture_skips",
    }:
        return False
    schema_version = report.get("schema_version")
    if type(schema_version) is not int or schema_version != 1:
        return False
    if not isinstance(report.get("successful"), bool):
        return False
    runtime = report.get("runtime")
    if not isinstance(runtime, dict) or set(runtime) != {
        "python_version", "python_implementation", "platform", "executable",
        "suite_settings",
    }:
        return False
    if not all(isinstance(runtime.get(key), str) and runtime[key]
               and len(runtime[key]) <= CHILD_TEST_ID_LIMIT
               for key in ("python_version", "python_implementation", "platform", "executable")):
        return False
    settings = runtime.get("suite_settings")
    if (not isinstance(settings, dict)
            or type(settings.get("verbosity")) is not int
            or settings != {
        "start_directory": "winapp/tests", "pattern": "test_*.py",
        "runner": "unittest.TextTestRunner", "verbosity": 1,
        "partition_selection": "selected-id-subset",
    }):
        return False
    counts = report.get("counts")
    count_keys = {
        "total", "passed", "failed", "errors", "skipped", "expected_failures",
        "unexpected_successes", "subtest_failures", "subtest_errors",
        "subtest_skips", "fixture_errors", "fixture_skips",
    }
    tests = report.get("tests")
    fixture_errors = report.get("fixture_errors")
    fixture_skips = report.get("fixture_skips")
    if (not isinstance(counts, dict) or set(counts) != count_keys
            or not all(type(value) is int and value >= 0 for value in counts.values())
            or not isinstance(tests, list) or not isinstance(fixture_errors, list)
            or not isinstance(fixture_skips, list)):
        return False
    computed = dict.fromkeys(count_keys, 0)
    seen_ids: set[str] = set()
    status_to_count = {
        "PASS": "passed", "FAIL": "failed", "ERROR": "errors", "SKIP": "skipped",
        "EXPECTED_FAILURE": "expected_failures",
        "UNEXPECTED_SUCCESS": "unexpected_successes",
    }
    for test in tests:
        if (not isinstance(test, dict) or set(test) != {
                "id", "status", "duration_seconds", "reason", "subtests",
        } or not isinstance(test.get("id"), str)
                or test["id"] in seen_ids
                or not isinstance(test.get("status"), str)
                or test["status"] not in status_to_count
                or not test["id"] or len(test["id"]) > CHILD_TEST_ID_LIMIT
                or any(ord(character) < 32 or ord(character) == 127
                       for character in test["id"])
                or type(test.get("duration_seconds")) not in (int, float)
                or not math.isfinite(test["duration_seconds"])
                or test["duration_seconds"] < 0
                or (test.get("reason") is not None and
                    (not isinstance(test["reason"], str)
                     or len(test["reason"]) > CHILD_REASON_LIMIT))
                or (test["status"] == "PASS" and test["reason"] is not None)
                or (test["status"] in {"FAIL", "ERROR", "SKIP", "EXPECTED_FAILURE"}
                    and (not isinstance(test["reason"], str)
                         or not test["reason"].strip()))
                or not isinstance(test.get("subtests"), list)):
            return False
        seen_ids.add(test["id"])
        computed["total"] += 1
        computed[status_to_count[test["status"]]] += 1
        seen_subtest_ids: set[str] = set()
        has_failing_child = False
        has_skipped_child = False
        for subtest in test["subtests"]:
            if (not isinstance(subtest, dict) or set(subtest) != {"id", "status", "reason"}
                    or not isinstance(subtest.get("id"), str)
                    or not subtest["id"] or len(subtest["id"]) > CHILD_TEST_ID_LIMIT
                    or subtest["id"] in seen_subtest_ids
                    or not isinstance(subtest.get("status"), str)
                    or subtest["status"] not in {"FAIL", "ERROR", "SKIP"}
                    or not isinstance(subtest.get("reason"), str)
                    or not subtest["reason"].strip()
                    or len(subtest["reason"]) > CHILD_REASON_LIMIT):
                return False
            seen_subtest_ids.add(subtest["id"])
            has_failing_child = has_failing_child or subtest["status"] in {"FAIL", "ERROR"}
            has_skipped_child = has_skipped_child or subtest["status"] == "SKIP"
            key = {"FAIL": "subtest_failures", "ERROR": "subtest_errors",
                   "SKIP": "subtest_skips"}[subtest["status"]]
            computed[key] += 1
        if ((has_failing_child and test["status"] not in {"FAIL", "ERROR"})
                or (has_skipped_child and test["status"] == "PASS")):
            return False
    seen_fixture_ids: set[str] = set()
    allowed_phases = {"setUpClass", "tearDownClass", "setUpModule", "tearDownModule"}
    for collection, count_key in ((fixture_errors, "fixture_errors"),
                                  (fixture_skips, "fixture_skips")):
        for item in collection:
            if (not isinstance(item, dict) or set(item) != {"phase", "id", "reason"}
                    or not all(isinstance(item.get(key), str) and item[key]
                               for key in ("phase", "id", "reason"))
                    or item["phase"] not in allowed_phases
                    or item["id"] in seen_fixture_ids
                    or len(item["id"]) > CHILD_TEST_ID_LIMIT
                    or len(item["reason"]) > CHILD_REASON_LIMIT):
                return False
            seen_fixture_ids.add(item["id"])
        computed[count_key] = len(collection)
    computed["errors"] += len(fixture_errors)
    has_failure = any(computed[key] for key in (
        "failed", "errors", "unexpected_successes", "subtest_failures",
        "subtest_errors", "fixture_errors",
    ))
    return (counts == computed and bool(tests or fixture_errors or fixture_skips)
            and report["successful"] is (not has_failure))


def merge_reports(
    reports: list[AuditReport | None], processes: list[PartitionProcess],
    expected_ids: list[str], duration: float,
) -> tuple[AuditReport, bool]:
    """Merge original child outcomes and independently prove exact test coverage.

    Args:
        reports (list[AuditReport | None]): Child schema-1 reports in shard order.
        processes (list[PartitionProcess]): Actual child process evidence.
        expected_ids (list[str]): Canonical full-discovery test IDs.
        duration (float): Coordinator wall duration in seconds.
    Returns:
        tuple[AuditReport, bool]: Merged report and whether every child/report passed.
    """
    if len(reports) != len(processes) or len(processes) < 2:
        raise ValueError("Partition process and report counts are incomplete.")
    if any(report is not None and not validate_child_report(report)
           for report in reports):
        raise ValueError("A selected-ID child report failed schema or count validation.")
    for report, process in zip(reports, processes, strict=True):
        if report is None:
            if process["report_valid"] or process["report_successful"] is not None:
                raise ValueError("Missing or invalid child report has contradictory status.")
        elif (not process["report_valid"] or not process["report_present"]
              or process["report_successful"] is not report["successful"]
              or [item["id"] for item in report["tests"]]
              != process["observed_test_ids"]):
            raise ValueError("Child report conflicts with its retained process record.")
    present = [report for report in reports if report is not None]
    if not present:
        raise ValueError("No child produced a structured report.")
    runtime = present[0]["runtime"]
    for report in present[1:]:
        if report["runtime"] != runtime:
            raise ValueError("Partition runtime/source identities differ.")
    settings = runtime["suite_settings"]
    if settings.get("partition_selection") != "selected-id-subset":
        raise ValueError("Child report is not marked as selected partition evidence.")

    tests = [item for report in present for item in report["tests"]]
    observed_ids = [item["id"] for item in tests]
    unique_observed = len(observed_ids) == len(set(observed_ids))
    exact_union = (
        unique_observed
        and len(expected_ids) == len(set(expected_ids))
        and set(observed_ids) == set(expected_ids)
    )
    partition_expected_ids = [
        test_id for process in processes for test_id in process["expected_test_ids"]
    ]
    partition_observed_ids = [
        test_id for process in processes for test_id in process["observed_test_ids"]
    ]
    class_owners: dict[str, int] = {}
    classes_are_whole = True
    for process in processes:
        for test_id in process["expected_test_ids"]:
            class_id = test_id.rpartition('.')[0]
            prior_owner = class_owners.setdefault(class_id, process["partition"])
            classes_are_whole = classes_are_whole and prior_owner == process["partition"]
    inventory_complete = (
        exact_union
        and len(partition_expected_ids) == len(set(partition_expected_ids))
        and set(partition_expected_ids) == set(expected_ids)
        and partition_observed_ids == observed_ids
        and all(process["coverage_complete"] for process in processes)
        and classes_are_whole
    )
    normalized_runtime = runtime
    if inventory_complete:
        normalized_runtime = dict(runtime)
        normalized_runtime["suite_settings"] = {
            key: value for key, value in settings.items()
            if key != "partition_selection"
        }
    fixture_errors = [
        {**item, "id": f"partition-{index}: {item['id']}"}
        for index, report in enumerate(reports, start=1) if report is not None
        for item in report["fixture_errors"]
    ]
    fixture_skips = [
        {**item, "id": f"partition-{index}: {item['id']}"}
        for index, report in enumerate(reports, start=1) if report is not None
        for item in report["fixture_skips"]
    ]
    counts: dict[str, int] = {
        "total": 0, "passed": 0, "failed": 0, "errors": 0, "skipped": 0,
        "expected_failures": 0, "unexpected_successes": 0,
        "subtest_failures": 0, "subtest_errors": 0, "subtest_skips": 0,
        "fixture_errors": 0, "fixture_skips": 0,
    }
    status_keys = {
        "PASS": "passed", "FAIL": "failed", "ERROR": "errors",
        "SKIP": "skipped", "EXPECTED_FAILURE": "expected_failures",
        "UNEXPECTED_SUCCESS": "unexpected_successes",
    }
    for test in tests:
        counts["total"] += 1
        counts[status_keys[test["status"]]] += 1
        for subtest in test["subtests"]:
            key = {"FAIL": "subtest_failures", "ERROR": "subtest_errors",
                   "SKIP": "subtest_skips"}[subtest["status"]]
            counts[key] += 1
    counts["fixture_errors"] = len(fixture_errors)
    counts["fixture_skips"] = len(fixture_skips)
    counts["errors"] += len(fixture_errors)
    processes_passed = all(
        process["exit_code"] == 0 and not process["timed_out"]
        and process["report_present"] and process["report_valid"]
        and process["report_successful"] is True
        and process["coverage_complete"]
        for process in processes
    )
    proof: PartitionCoverageProof = {
        "partition_count": len(processes),
        "complete": inventory_complete,
        "exact_union": exact_union, "expected_test_ids": expected_ids,
        "observed_test_ids": observed_ids,
        "total_duration_seconds": duration,
    }
    merged: AuditReport = {
        "schema_version": 1,
        "successful": inventory_complete and processes_passed and not fixture_errors,
        "runtime": normalized_runtime,
        "counts": counts,
        "tests": tests,
        "fixture_errors": fixture_errors,
        "fixture_skips": fixture_skips,
        "partition_processes": processes,
        "partition_proof": proof,
    }
    return merged, merged["successful"]


def main(arguments: list[str]) -> int:
    """Run canonical WinApp partitions serially and write one complete report.

    Args:
        arguments (list[str]): Output path, partition count and per-child timeout.
    Returns:
        int: Zero only when all complete child reports prove and pass full discovery.
    """
    if len(arguments) != 3:
        return 2
    output_path = Path(arguments[0]).expanduser().resolve()
    try:
        partition_count = int(arguments[1])
        child_timeout = int(arguments[2])
        if output_path.exists() or child_timeout < 1:
            return 2
        cases = flatten_suite(discover_canonical_suite())
        expected_ids = [case.id() for case in cases]
        shards = partition_test_ids(expected_ids, partition_count)
        run_directory = output_path.parent / (output_path.stem + "-partitions")
        run_directory.mkdir(parents=True, exist_ok=False)
        reports: list[AuditReport | None] = []
        process_records: list[PartitionProcess] = []
        started = time.perf_counter()
        for index, shard in enumerate(shards, start=1):
            ids_path = run_directory / f"partition-{index}-ids.json"
            report_path = run_directory / f"partition-{index}-report.json"
            log_path = run_directory / f"partition-{index}.log"
            ids_path.write_text(json.dumps(shard), encoding="utf-8")
            command = [
                sys.executable, "-m", "scripts.audit_winapp_report",
                str(report_path), f"--test-ids-file={ids_path}",
            ]
            child_started = time.perf_counter()
            exit_code: int | None = None
            timed_out = False
            with log_path.open("w", encoding="utf-8", errors="replace") as child_log:
                try:
                    completed = subprocess.run(
                        command, stdout=child_log, stderr=subprocess.STDOUT,
                        text=True, timeout=child_timeout, check=False,
                    )
                    exit_code = completed.returncode
                except subprocess.TimeoutExpired:
                    timed_out = True
                    child_log.write("\nChild timed out.\n")
            report: AuditReport | None = None
            report_file_present = report_path.is_file()
            if report_path.is_file():
                try:
                    decoded = json.loads(report_path.read_text(encoding="utf-8"))
                except json.JSONDecodeError:
                    decoded = None
                if validate_child_report(decoded):
                    report = decoded
            reports.append(report)
            observed_shard_ids = (
                [item["id"] for item in report["tests"]] if report else []
            )
            process_records.append({
                "partition": index, "test_count": len(shard),
                "exit_code": exit_code, "timed_out": timed_out,
                "duration_seconds": max(0.0, time.perf_counter() - child_started),
                "report_present": report_file_present,
                "report_valid": report is not None,
                "report_successful": report["successful"] if report else None,
                "coverage_complete": bool(report) and
                observed_shard_ids == shard,
                "expected_test_ids": shard,
                "observed_test_ids": observed_shard_ids,
            })
        duration = max(0.0, time.perf_counter() - started)
        merged, successful = merge_reports(
            reports, process_records, expected_ids, duration,
        )
        merged["partition_processes"] = process_records
        write_complete_report(output_path, merged)
        for record in process_records:
            print(json.dumps(record, sort_keys=True), file=sys.stderr)
        return 0 if successful else 1
    except Exception as error:
        print(
            f"Partitioned WinApp coordinator failed: {type(error).__name__}: {error}",
            file=sys.stderr,
        )
        return 2


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
