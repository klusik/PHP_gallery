# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_434_tk_report_bundle.py
# Module Type: Hosted Benchmark Evidence Bundler
# Purpose: Assemble a Czech issue 434 report and a traceable offline evidence ZIP.
# Responsibilities:
#   - Read current hosted benchmark evidence and optional prior checkpoint runs.
#   - Read exact-SHA Issue 361 results and retain the central audit reports.
#   - Generate the report and retain source summaries, raw cells and protocol.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Build the Czech Tk report and evidence archive in the hosted Actions job."""

import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import zipfile
from pathlib import Path
from typing import TypeAlias

from issue_434_tk_report import build_report

JsonValue: TypeAlias = (
    str | int | float | bool | None | list["JsonValue"] | dict[str, "JsonValue"]
)
JsonObject: TypeAlias = dict[str, JsonValue]

# Purpose: Bound metadata and job lookup subprocesses to a short response window.
# Type: int. Units: seconds. Scope: one gh invocation.
# Consumers: gh_json. Rationale: hosted report generation must not hang on API calls.
GH_COMMAND_TIMEOUT_SECONDS = 30
# Purpose: Bound waiting for the exact-SHA functional workflow result.
# Type: int. Units: seconds. Scope: report assembly job.
# Consumers: collect_functional. Rationale: permit an active audit to finish while
# leaving report packaging inside the workflow's twenty-minute job deadline.
FUNCTIONAL_WAIT_SECONDS = 600
# Purpose: Limit the polling interval for functional workflow state.
# Type: int. Units: seconds. Scope: functional workflow polling.
# Consumers: collect_functional. Rationale: ten seconds balances response latency
# against unnecessary GitHub API calls.
FUNCTIONAL_POLL_SECONDS = 10
# Purpose: Cap matching workflow runs returned by the GitHub CLI.
# Type: int. Units: workflow runs. Scope: one exact-SHA functional lookup.
# Consumers: workflow_runs. Rationale: GitHub CLI's maximum page size is 100.
WORKFLOW_RUN_LIMIT = 100
# Purpose: Allow large archived raw Tk evidence to download without truncation.
# Type: int. Units: seconds. Scope: one artifact download.
# Consumers: download_artifacts. Rationale: raw evidence streams can be large.
ARTIFACT_DOWNLOAD_TIMEOUT_SECONDS = 300
# Purpose: Cap copied CLI diagnostics recorded in report evidence.
# Type: int. Units: characters. Scope: one gh command failure.
# Consumers: command_error. Rationale: preserve useful context without large logs.
DIAGNOSTIC_LIMIT = 2000

HERE = Path(__file__).resolve().parent
PROTOCOL = HERE / "issue_434_tk_protocol.json"
FUNCTIONAL_WORKFLOW = "issue-361-winapp-functional.yml"
ADVISORY_FUNCTIONAL_JOB = "Advisory WinApp regression (macOS; real result shown)"
FROZEN_FUNCTIONAL_JOB = "Opt-in real frozen executable and installer build"
FUNCTIONAL_AUDIT_ARTIFACTS = {
    "Required WinApp regression (Windows)": "issue-361-winapp-windows",
    "Required WinApp regression (Linux)": "issue-361-winapp-linux",
    "Required audit coordinator contracts (Linux)": "issue-361-audit-contracts",
    ADVISORY_FUNCTIONAL_JOB: "issue-361-winapp-macos-advisory",
    FROZEN_FUNCTIONAL_JOB: "issue-361-winapp-frozen",
}


def read_json(path: Path) -> JsonValue:
    """Read a UTF-8 JSON file from the hosted evidence tree.

    Args:
        path (Path): File path.

    Returns:
        JsonValue: Decoded JSON value.

    Raises:
        OSError: The file cannot be read.
        json.JSONDecodeError: The file is invalid JSON.
    """
    return json.loads(path.read_text(encoding="utf-8"))


def as_object(value: JsonValue) -> JsonObject | None:
    """Return the object shape when a JSON value is a mapping.

    Args:
        value (JsonValue): Decoded JSON value.

    Returns:
        JsonObject | None: Mapping or None for other JSON shapes.
    """
    return value if isinstance(value, dict) else None


def write_json(path: Path, value: JsonValue) -> None:
    """Write stable, readable UTF-8 evidence JSON.

    Args:
        path (Path): Destination file.
        value (JsonValue): JSON-compatible evidence value.

    Returns:
        None: The file is written or an I/O exception is raised.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


def command_error(result: subprocess.CompletedProcess[str]) -> str:
    """Return bounded stderr/stdout diagnostics for a failed CLI call.

    Args:
        result (subprocess.CompletedProcess[str]): Completed command.

    Returns:
        str: Exit code and bounded diagnostic tail.
    """
    detail = (result.stderr or result.stdout or "bez podrobností").strip()
    return f"exit={result.returncode}: {detail[-DIAGNOSTIC_LIMIT:]}"


def gh_json(arguments: list[str]) -> tuple[JsonValue | None, str | None]:
    """Run gh and decode its JSON output without exposing credentials.

    Args:
        arguments (list[str]): Arguments following the gh executable.

    Returns:
        tuple[JsonValue | None, str | None]: Decoded output and failure text.
    """
    try:
        result = subprocess.run(
            ["gh", *arguments], check=False, capture_output=True, text=True,
            timeout=GH_COMMAND_TIMEOUT_SECONDS,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        return None, f"gh {' '.join(arguments[:3])}: {error}"
    if result.returncode:
        return None, f"gh {' '.join(arguments[:3])}: {command_error(result)}"
    try:
        return json.loads(result.stdout), None
    except json.JSONDecodeError as error:
        return None, f"gh returned invalid JSON: {error}"


def summary_files(root: Path) -> list[Path]:
    """Find current and downloaded benchmark summary files deterministically.

    Args:
        root (Path): Downloaded current evidence directory.

    Returns:
        list[Path]: Existing summary.json files in stable path order.
    """
    return sorted(path for path in root.rglob("summary.json") if path.is_file())


def download_artifacts(
    run_id: str, destination: Path, patterns: tuple[str, ...],
) -> str | None:
    """Download artifacts for a provider run into an isolated directory.

    Args:
        run_id (str): Exact GitHub Actions run ID.
        destination (Path): Stage where the GitHub CLI extracts evidence.
        patterns (tuple[str, ...]): Artifact name filters for this run.

    Returns:
        str | None: Bounded failure details, or None after download.
    """
    destination.mkdir(parents=True, exist_ok=True)
    command = ["gh", "run", "download", run_id, "--dir", str(destination)]
    for pattern in patterns:
        command.extend(("--pattern", pattern))
    try:
        result = subprocess.run(
            command, check=False, capture_output=True, text=True,
            timeout=ARTIFACT_DOWNLOAD_TIMEOUT_SECONDS,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        return f"Stažení běhu {run_id} selhalo: {error}"
    if result.returncode:
        return f"Stažení běhu {run_id} selhalo: {command_error(result)}"
    return None


def baseline_shas_from_summaries(paths: list[Path]) -> set[str]:
    """Collect recorded baseline commit IDs from current cell summaries.

    Args:
        paths (list[Path]): Current checkpoint summary paths.

    Returns:
        set[str]: Distinct baseline source SHAs present in cell reports.
    """
    hashes: set[str] = set()
    for path in paths:
        try:
            summary = as_object(read_json(path))
        except (OSError, json.JSONDecodeError):
            continue
        cases = summary.get("cases") if summary else None
        if not isinstance(cases, list):
            continue
        for item in cases:
            cell = as_object(item)
            sha = str(cell.get("baseline_sha", "")) if cell else ""
            if sha:
                hashes.add(sha)
    return hashes


def checkpoint_summary(root: Path, checkpoint: str, run_id: str) -> Path | None:
    """Find a summary that proves a run or its artifact-only recovery.

    Args:
        root (Path): Downloaded artifact directory.
        checkpoint (str): Required benchmark checkpoint label.
        run_id (str): Exact GitHub Actions run ID.

    Returns:
        Path | None: Matching summary or None if no identity match exists.
    """
    for path in summary_files(root):
        try:
            summary = as_object(read_json(path))
        except (OSError, json.JSONDecodeError):
            continue
        direct = summary and str(summary.get("run_id")) == run_id
        recovered = summary and str(summary.get("collector_run_id")) == run_id
        if ((direct or recovered) and str(summary.get("checkpoint")) == checkpoint):
            return path
    return None


def download_run(checkpoint: str, run_id: str, destination: Path) -> tuple[Path | None, str | None]:
    """Download one prior run artifact and verify its run and checkpoint.

    Args:
        checkpoint (str): Required checkpoint label from reviewed configuration.
        run_id (str): Numeric workflow run ID from reviewed comparison config.
        destination (Path): Dedicated download directory.

    Returns:
        tuple[Path | None, str | None]: Verified summary and explanation.
    """
    metadata, issue = gh_json([
        "run", "view", run_id,
        "--json", "databaseId,headSha,event,status,conclusion,url,workflowName",
    ])
    if issue:
        return None, issue
    observed = as_object(metadata)
    if not observed or str(observed.get("databaseId")) != run_id:
        return None, f"Běh {run_id}: metadata neprokazují požadované run ID."
    if (not str(observed.get("workflowName", "")).startswith("Issue 434")
            or observed.get("event") not in ("push", "workflow_dispatch")):
        return None, f"Běh {run_id}: workflow nebo event není běh issue 434."
    if observed.get("status") != "completed":
        return None, f"Běh {run_id}: stav Actions je {observed.get('status')}/{observed.get('conclusion')}."
    if observed.get("conclusion") != "success":
        details, lookup_issue = gh_json(["run", "view", run_id, "--json", "jobs"])
        detail = as_object(details) or {}
        jobs = detail.get("jobs")
        measured = isinstance(jobs, list) and any(
            isinstance(job, dict)
            and job.get("name") == "Collect Phase 2 benchmark evidence"
            and job.get("conclusion") == "success"
            for job in jobs
        )
        if lookup_issue or not measured:
            return None, (lookup_issue or
                          f"Běh {run_id}: úspěšný sběr měření nebyl potvrzen.")
    issue = download_artifacts(run_id, destination, (
        "issue-434-*-evidence-*",
        "issue-434-baseline-recovered-*",
        "issue-434-cell-*",
    ))
    if issue:
        return None, issue
    summary = checkpoint_summary(destination, checkpoint, run_id)
    if summary is None:
        return None, f"Běh {run_id}: souhrn nepotvrdil kontrolní bod {checkpoint}."
    try:
        summary_object = as_object(read_json(summary)) or {}
    except (OSError, json.JSONDecodeError) as error:
        return None, f"Běh {run_id}: souhrn nelze načíst: {error}"
    cases_value = summary_object.get("cases")
    cases = [as_object(item) for item in cases_value] if isinstance(cases_value, list) else []
    if (summary_object.get("status") != "PASS" or not cases
            or any(not case or case.get("status") != "PASS" for case in cases)
            or summary_object.get("failures")):
        return None, f"Běh {run_id} neprokazuje PASS všech měřených buněk."
    if str(summary_object.get("collector_run_id", "")) == run_id:
        if str(summary_object.get("collector_sha", "")).lower() != str(observed.get("headSha", "")).lower():
            return None, f"Běh {run_id}: collector SHA nesouhlasí s head SHA."
        measurement_run_id = str(summary_object.get("run_id", ""))
        measurement, measurement_issue = gh_json([
            "run", "view", measurement_run_id,
            "--json", "databaseId,headSha,status,conclusion,event,workflowName",
        ])
        measurement_object = as_object(measurement)
        if (measurement_issue or not measurement_object
                or str(measurement_object.get("databaseId")) != measurement_run_id
                or not str(measurement_object.get("workflowName", "")).startswith("Issue 434")
                or measurement_object.get("event") not in ("push", "workflow_dispatch")
                or str(measurement_object.get("headSha", "")).lower()
                != str(summary_object.get("harness_sha", "")).lower()):
            return None, (measurement_issue or
                          f"Běh měření {measurement_run_id} neodpovídá SHA souhrnu.")
        if measurement_run_id != run_id:
            issue = download_artifacts(
                measurement_run_id, destination / "original-measurement",
                ("issue-434-cell-*",),
            )
            if issue:
                return summary, issue
    elif str(summary_object.get("harness_sha", "")).lower() != str(observed.get("headSha", "")).lower():
        return None, f"Běh {run_id}: head SHA nesouhlasí se SHA souhrnu."
    return summary, None


def workflow_runs(sha: str) -> tuple[list[JsonObject], str | None]:
    """List Issue 361 functional runs for one exact commit SHA.

    Args:
        sha (str): Required commit SHA.

    Returns:
        tuple[list[JsonObject], str | None]: Workflow runs and lookup failure.
    """
    result, issue = gh_json([
        "run", "list", "--workflow", FUNCTIONAL_WORKFLOW, "--commit", sha,
        "--limit", str(WORKFLOW_RUN_LIMIT), "--json",
        "databaseId,headSha,event,status,conclusion,workflowName,url,createdAt",
    ])
    if issue:
        return [], issue
    if not isinstance(result, list):
        return [], "gh run list nevrátil seznam běhů."
    matches = []
    for value in result:
        row = as_object(value)
        if (row and str(row.get("headSha", "")).lower() == sha.lower()
                and row.get("workflowName") == "Issue 361 WinApp functional regressions"):
            matches.append(row)
    return matches, None


def retain_functional_audits(
    run_id: str, sha: str, jobs: list[JsonObject], destination: Path,
) -> list[str]:
    """Retain small exact-SHA central audit reports for completed jobs.

    Args:
        run_id (str): Provider run ID confirmed by the functional lookup.
        sha (str): Required source SHA for the downloaded audits.
        jobs (list[JsonObject]): Actual provider job rows to enrich with report paths.
        destination (Path): Evidence bundle directory for readable audit reports.

    Returns:
        list[str]: Evidence gaps with the associated job and reason.
    """
    with tempfile.TemporaryDirectory(prefix="issue-434-functional-") as temporary:
        staging = Path(temporary)
        issue = download_artifacts(run_id, staging, (f"issue-361-*-{sha}",))
        if issue:
            return [issue]
        gaps: list[str] = []
        for job in jobs:
            prefix = FUNCTIONAL_AUDIT_ARTIFACTS.get(str(job.get("name")))
            if not prefix or job.get("conclusion") == "skipped":
                continue
            audit_dir = staging / f"{prefix}-{sha}"
            report = audit_dir / "latest.json"
            try:
                audit = as_object(read_json(report)) or {}
            except (OSError, json.JSONDecodeError):
                gaps.append(f"{job['name']}: centrální výsledek chybí nebo je nečitelný.")
                continue
            source = as_object(audit.get("source")) or {}
            if source.get("head_sha") != sha or source.get("worktree_dirty") is not False:
                gaps.append(f"{job['name']}: centrální výsledek nemá požadovaný čistý SHA.")
                continue
            copy_evidence(report, destination / prefix / report.name)
            markdown = audit_dir / "latest.md"
            if markdown.is_file():
                copy_evidence(markdown, destination / prefix / markdown.name)
            job["audit_report"] = f"{prefix}/{report.name}"
        return gaps


def collect_functional(
    destination: Path, wait_seconds: int, require_frozen: bool,
) -> JsonObject:
    """Collect exact-SHA WinApp functional workflow and named job outcomes.

    Args:
        destination (Path): Functional evidence JSON destination.
        wait_seconds (int): Maximum wait for an active matching run.
        require_frozen (bool): Whether packaging must succeed for this checkpoint.

    Returns:
        JsonObject: Actual status, workflow metadata and job result rows.
    """
    sha = os.environ.get("GITHUB_SHA", "")
    started = time.monotonic()
    outcome: JsonObject = {
        "status": "BLOCKED", "head_sha": sha,
        "workflow": FUNCTIONAL_WORKFLOW, "runs": [], "jobs": [],
        "note": "Výsledek nebyl potvrzen.",
    }
    selected: JsonObject | None = None
    lookup_issue = None
    while True:
        runs, lookup_issue = workflow_runs(sha)
        if lookup_issue:
            outcome["note"] = lookup_issue
            break
        latest = max(runs, key=lambda row: str(row.get("createdAt", ""))) if runs else None
        if latest and latest.get("status") == "completed":
            selected = latest
            break
        if (not latest or latest.get("status") not in ("queued", "in_progress")
                or time.monotonic() - started >= wait_seconds):
            outcome["note"] = (
                "Pro tento přesný SHA nebyl nalezen dokončený běh funkčních regresí."
            )
            break
        time.sleep(min(FUNCTIONAL_POLL_SECONDS, max(0, wait_seconds - int(time.monotonic() - started))))
    outcome["runs"] = [dict(row) for row in (runs if not lookup_issue else [])]
    if selected:
        run_id = str(selected.get("databaseId", ""))
        details, issue = gh_json(["run", "view", run_id, "--json", "jobs,headSha,event,status,conclusion,url"])
        if issue:
            outcome["note"] = issue
        else:
            detail_object = as_object(details) or {}
            if str(detail_object.get("headSha", "")).lower() != sha.lower():
                outcome["note"] = "Detail běhu má jiný head SHA; výsledky nejsou přijaty."
            else:
                jobs_value = detail_object.get("jobs")
                jobs: list[JsonObject] = []
                if isinstance(jobs_value, list):
                    for item in jobs_value:
                        job = as_object(item)
                        if job:
                            jobs.append(dict(job))
                outcome["jobs"] = jobs
                required_names = set(FUNCTIONAL_AUDIT_ARTIFACTS) - {ADVISORY_FUNCTIONAL_JOB}
                if not require_frozen:
                    required_names.remove(FROZEN_FUNCTIONAL_JOB)
                results = {str(job.get("name")): job.get("conclusion") for job in jobs}
                required = [results.get(name) for name in sorted(required_names)]
                for job in jobs:
                    job["qualification"] = (
                        "required" if job.get("name") in required_names else "advisory"
                        if job.get("name") == ADVISORY_FUNCTIONAL_JOB else "additional"
                    )
                if all(result == "success" for result in required):
                    outcome["status"] = "PASS"
                    outcome["note"] = "Všechny povinné kontroly pro tento SHA prošly."
                elif any(result in ("failure", "cancelled", "timed_out") for result in required):
                    outcome["status"] = "FAIL"
                    outcome["note"] = "Nejméně jedna povinná kontrola selhala nebo byla zrušena."
                else:
                    outcome["note"] = "Nejméně jedna povinná kontrola chybí nebo byla přeskočena."
                outcome["selected_run"] = selected
                outcome["audit_gaps"] = retain_functional_audits(
                    run_id, sha, jobs, destination.parent,
                )
    outcome["lookup_error"] = lookup_issue
    write_json(destination, outcome)
    return outcome


def copy_evidence(source: Path, destination: Path) -> None:
    """Copy a complete evidence tree while retaining relative paths.

    Args:
        source (Path): Existing file or directory.
        destination (Path): Output path under the bundle staging directory.

    Returns:
        None: Files are copied; I/O errors propagate to the caller.
    """
    if source.is_dir():
        shutil.copytree(source, destination, dirs_exist_ok=True)
    elif source.is_file():
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, destination)


def build_bundle(current: Path, raw_cells: Path, output: Path, comparison: Path) -> int:
    """Build report artifacts and a ZIP containing all retained source evidence.

    Args:
        current (Path): Downloaded final checkpoint evidence directory.
        raw_cells (Path): Downloaded matrix child artifact directory.
        output (Path): Absolute final bundle directory.
        comparison (Path): Reviewed checkpoint/run mapping JSON.

    Returns:
        int: Zero when report and archive were created, otherwise raises an error.

    Raises:
        OSError: Inputs cannot be copied or report files cannot be written.
        ValueError: Required current evidence or run identity is missing.
    """
    if os.environ.get("GITHUB_ACTIONS", "").lower() != "true":
        raise ValueError("Bundler lze spouštět pouze v GitHub Actions.")
    for variable in ("GITHUB_SHA", "GITHUB_RUN_ID", "GITHUB_RUN_ATTEMPT", "GITHUB_REPOSITORY"):
        if not os.environ.get(variable):
            raise ValueError(f"Chybí identita běhu: {variable}")
    if not output.is_absolute():
        raise ValueError("Výstupní cesta musí být absolutní.")
    if not comparison.is_file():
        raise ValueError(f"Konfigurační soubor s kontextem chybí: {comparison}")
    summaries = summary_files(current)
    if len(summaries) != 1:
        raise ValueError("Aktuální adresář musí obsahovat jediný souhrn summary.json.")
    comparison_value = as_object(read_json(comparison)) or {}
    summary = as_object(read_json(summaries[0])) or {}
    checkpoint = comparison_value.get("candidate_label")
    expected = {
        "checkpoint": checkpoint,
        "profile": "final" if checkpoint == "BF" else "compare",
        "harness_sha": os.environ["GITHUB_SHA"],
        "run_id": os.environ["GITHUB_RUN_ID"],
        "collector_sha": os.environ["GITHUB_SHA"],
        "collector_run_id": os.environ["GITHUB_RUN_ID"],
        "collector_attempt": os.environ["GITHUB_RUN_ATTEMPT"],
    }
    mismatched = [key for key, value in expected.items() if summary.get(key) != value]
    if mismatched:
        raise ValueError("Identita aktuálního souhrnu nesouhlasí: " + ", ".join(mismatched))
    output.mkdir(parents=True, exist_ok=True)
    evidence_dir = output / "evidence"
    evidence_dir.mkdir(parents=True, exist_ok=True)
    copy_evidence(current, evidence_dir / "current")
    if raw_cells.exists():
        copy_evidence(raw_cells, evidence_dir / "raw-cells")
    copy_evidence(comparison, evidence_dir / "issue-434-comparison.json")
    if PROTOCOL.is_file():
        copy_evidence(PROTOCOL, evidence_dir / PROTOCOL.name)

    report_inputs = [str(path) for path in summaries]
    raw_report_inputs = [str(raw_cells)]
    run_gaps: list[str] = []
    original_run_urls: dict[str, str] = {}
    with tempfile.TemporaryDirectory(prefix="issue-434-prior-") as temporary:
        temp_root = Path(temporary)
        run_map = as_object(comparison_value.get("evidence_runs")) or {}
        for checkpoint, raw_run_id in sorted(run_map.items()):
            run_id = str(raw_run_id)
            if not run_id.isdigit():
                run_gaps.append(f"{checkpoint}: neplatné run ID {run_id!r}.")
                continue
            summary, issue = download_run(checkpoint, run_id, temp_root / checkpoint)
            if issue:
                run_gaps.append(f"{checkpoint}: {issue}")
            if summary is None:
                if not issue:
                    run_gaps.append(f"{checkpoint}: souhrn chybí")
                continue
            summary_object = as_object(read_json(summary)) or {}
            measurement_run_id = str(summary_object.get("run_id", run_id))
            original_run_urls[checkpoint] = (
                f"https://github.com/{os.environ['GITHUB_REPOSITORY']}"
                f"/actions/runs/{measurement_run_id}"
            )
            copy_evidence(temp_root / checkpoint, evidence_dir / "prior-runs" / checkpoint)
            report_inputs.append(str(summary))
            raw_report_inputs.append(str(temp_root / checkpoint))
        wait_seconds = min(
            FUNCTIONAL_WAIT_SECONDS,
            max(0, int(os.environ.get("PHP_GALLERY_ISSUE_434_FUNCTIONAL_WAIT_SECONDS",
                                      str(FUNCTIONAL_WAIT_SECONDS)))),
        )
        functional = collect_functional(
            evidence_dir / "functional" / "issue-361-functional.json",
            wait_seconds,
            require_frozen=checkpoint == "BF",
        )
        baseline_shas = baseline_shas_from_summaries(summaries)
        baseline_sha = str(comparison_value.get("baseline_sha", ""))
        if not baseline_sha and len(baseline_shas) == 1:
            baseline_sha = next(iter(baseline_shas))
        repository_url = f"https://github.com/{os.environ['GITHUB_REPOSITORY']}"
        related_runs = as_object(comparison_value.get("related_runs")) or {}
        related_urls = {
            label: f"{repository_url}/actions/runs/{run_id}"
            for label, run_id in related_runs.items()
            if str(run_id).isdecimal()
        }
        notes: JsonObject = {
            "baseline_context": comparison_value.get("baseline_context", []),
            "optimizations": comparison_value.get("optimizations", []),
            "variants": comparison_value.get("variants", []),
            "reverted": comparison_value.get("reverted", []),
            "methodology": comparison_value.get("methodology", []),
            "known_limits": comparison_value.get("known_limits", []),
            "remaining_problems": comparison_value.get("remaining_problems", []),
            "recommendations": comparison_value.get("recommendations", []),
            "evidence_gaps": run_gaps,
            "issue_url": comparison_value.get("issue_url"),
            "run_url": f"{repository_url}/actions/runs/{os.environ['GITHUB_RUN_ID']}",
            "candidate_commit_url": f"{repository_url}/commit/{os.environ['GITHUB_SHA']}",
            "baseline_commit_url": (
                f"{repository_url}/commit/{baseline_sha}"
                if baseline_sha else None
            ),
            "checkpoint_run_urls": original_run_urls,
            "related_run_urls": related_urls,
            "architecture": comparison_value.get("architecture", {}),
        }
        notes_path = evidence_dir / "report-notes.json"
        write_json(notes_path, notes)
        functional_path = evidence_dir / "functional" / "issue-361-functional.json"
        write_json(evidence_dir / "collection-status.json", {
            "current_run_id": os.environ["GITHUB_RUN_ID"],
            "current_run_attempt": os.environ["GITHUB_RUN_ATTEMPT"],
            "current_sha": os.environ["GITHUB_SHA"],
            "repository": os.environ["GITHUB_REPOSITORY"],
            "prior_run_gaps": run_gaps,
            "functional_status": functional.get("status"),
        })
        report_path = output / "issue-434-report.html"
        build_report(report_path, report_inputs, raw_report_inputs,
                     str(functional_path), str(notes_path))

    archive = output / "issue-434-report-evidence.zip"
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as bundle:
        for path in sorted(output.rglob("*")):
            if path.is_file() and path != archive:
                bundle.write(path, path.relative_to(output))
    return 0


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    """Parse the hosted report bundle command line.

    Args:
        argv (list[str] | None): Optional arguments without executable name.

    Returns:
        argparse.Namespace: Current evidence, raw cells, output and comparison.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--current", required=True, type=Path)
    parser.add_argument("--raw-cells", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--comparison", required=True, type=Path)
    args = parser.parse_args(argv)
    if not args.output.is_absolute():
        parser.error("--output musí být absolutní cesta")
    return args


def main(argv: list[str] | None = None) -> int:
    """Generate the hosted Czech report bundle.

    Args:
        argv (list[str] | None): Optional arguments without executable name.

    Returns:
        int: Zero when the report and evidence archive are written.
    """
    args = parse_args(argv)
    return build_bundle(args.current, args.raw_cells, args.output, args.comparison)


if __name__ == "__main__":
    raise SystemExit(main())
