# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/qualify_packaging_metrics.py
# Module Type: Hosted Windows package and startup metrics
# Purpose: Measure native startup and sizes across the three actual WinApp layouts.
# Responsibilities:
#   - Install the authenticated release, prior dual build and common candidate.
#   - Record setup, executable, tree and bundled DLL sizes.
#   - Sample installer and GUI child process working sets during native waits.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Collect exact-candidate Windows installer size and startup evidence."""

import argparse
import csv
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import shutil
import subprocess
import sys
import tempfile
from threading import Thread
import time
from typing import Callable

import psutil

from qt_installer_tools import (
    INSTALLED_SMOKE_SECONDS, installed_window, install_setup, observe_installed_tk,
    wait_for_desktop_exit,
    request_window_close, system_only_environment,
)
from qualify_installer_updates import fetch_public_stable_asset
from uploader.config import APP_PROFILE_NAME
from uploader.dependency_versions import dependency_versions
from uploader.gui_backend import GUI_STARTUP_POLL_SECONDS, save_gui_backend

# Purpose: Require multiple launches so warm and restart processes can follow the first.
# Type: int. Units: launches per frontend. Scope: one selected installed setup.
# Consumers: measure_frontend. Rationale: First, second and third launches
# separate initial process from repeated starts without inventing cold-cache control.
FRONTEND_LAUNCH_PHASES = ("cold_process", "warm_process", "third_process")
# Purpose: Poll disposable child processes while their real installer or window is active.
# Type: float. Units: seconds. Scope: one observed installation or startup operation.
# Consumers: sample_operation. Rationale: 50 ms samples sustained GUI working sets.
RSS_SAMPLE_INTERVAL_SECONDS = 0.05
# Purpose: Avoid recursively scanning large installed trees at RSS cadence.
# Type: float. Units: seconds. Scope: one packaging startup observation.
# Consumers: sample_operation. Rationale: One-second workspace samples show
# extraction/profile growth without perturbing native startup every 50 ms.
WORKSPACE_SAMPLE_INTERVAL_SECONDS = 1.0
# Purpose: Bound each complete installation or native startup operation.
# Type: int. Units: seconds. Scope: one selected installer or GUI lifecycle.
# Consumers: sample_operation. Rationale: Two smoke budgets allow clean close after readiness.
OPERATION_TIMEOUT_SECONDS = INSTALLED_SMOKE_SECONDS * 2
# Purpose: Bound the retained JSON and CSV evidence sizes.
# Type: int. Units: bytes. Scope: one selected package metrics report.
# Consumers: main. Rationale: Retain timestamps and process/temp samples for
# every installed layout, including first-run extraction and repeated starts.
REPORT_MAX_BYTES = 512 * 1024
# Purpose: Require the canonical common setup name and extract its application version.
# Type: str. Units: filename pattern. Scope: one selected installer.
# Consumers: main. Rationale: Flavor-specific setups are not valid installed products.
SETUP_NAME_PATTERN = r"^PHPGalleryUploader-(?P<version>[0-9]+\.[0-9]+\.[0-9]+)-Setup\.exe$"
# Purpose: Admit the historical isolated Qt setup artifact.
# Type: str. Units: filename pattern. Scope: prior 96e7 combined layout input.
# Consumers: main. Rationale: The prior CI artifact names its Qt-only setup.
PRIOR_SETUP_NAME_PATTERN = r"^PHPGalleryUploader-(?P<version>[0-9]+\.[0-9]+\.[0-9]+)-Qt-Setup\.exe$"


def file_sha256(path: Path) -> str:
    """Hash the exact selected setup without loading it all into memory.

    Args:
        path (Path): Existing setup installer selected by the hosted workflow.
    Returns:
        str: Lowercase SHA-256 digest of every setup byte.
    Raises:
        OSError: The selected setup cannot be read.
    """
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def directory_metrics(root: Path, *, live: bool = False) -> dict[str, int]:
    """Count regular files, total bytes and bundled DLLs in one installed tree.

    Args:
        root (Path): Disposable installed application directory.
        live (bool): Permit temporary files to disappear during active sampling.
    Returns:
        dict[str, int]: `file_count`, `tree_bytes`, `dll_count` and `dll_bytes`.
    Raises:
        OSError: The installed tree could not be inspected completely.
    """
    file_count = 0
    tree_bytes = 0
    dll_count = 0
    dll_bytes = 0
    for directory, subdirectories, filenames in os.walk(root, followlinks=False):
        subdirectories[:] = [
            name for name in subdirectories
            if not (Path(directory) / name).is_symlink()
        ]
        for filename in filenames:
            path = Path(directory) / filename
            if path.is_symlink():
                continue
            try:
                size = path.stat().st_size
            except FileNotFoundError:
                if live:
                    # The onefile launcher retires its extracted temp tree on exit.
                    continue
                raise
            file_count += 1
            tree_bytes += size
            if path.suffix.lower() == ".dll":
                dll_count += 1
                dll_bytes += size
    return {
        "file_count": file_count, "tree_bytes": tree_bytes,
        "dll_count": dll_count, "dll_bytes": dll_bytes,
    }


def workspace_bytes(root: Path) -> int:
    """Sum regular file bytes in the temporary package qualification workspace.

    Args:
        root (Path): Run-owned temporary workspace containing install and profiles.
    Returns:
        int: Current retained file bytes, including setup logs and smoke profiles.
    Raises:
        OSError: A workspace entry cannot be measured.
    """
    return directory_metrics(root, live=True)["tree_bytes"]


def sample_operation(
    operation: Callable[[], float | None], workspace: Path,
) -> dict[str, object]:
    """Run one real setup or native GUI observer while sampling child RSS.

    Args:
        operation (Callable[[], float | None]): Existing install or GUI observer.
        workspace (Path): Temporary workspace measured at the operation boundary.
    Returns:
        dict[str, object]: Readiness seconds, lifecycle time and raw sampled RSS.
    Raises:
        RuntimeError: The child operation exceeded its budget or failed.
    """
    completed: dict[str, float | None] = {}
    failures: list[Exception] = []

    def invoke() -> None:
        """Capture one observer's result or exception for the sampling thread.

        Returns:
            None: The calling sampler observes completion through thread state.
        """
        try:
            completed["readiness_seconds"] = operation()
        except Exception as error:
            failures.append(error)

    baseline = {
        child.pid for child in psutil.Process().children(recursive=True)
    }
    worker = Thread(target=invoke, name="PackagingMetricsOperation", daemon=True)
    started = time.monotonic()
    worker.start()
    rss_samples: list[int] = []
    process_samples_seconds: list[float] = []
    write_samples: list[int] = []
    workspace_samples: list[int] = []
    workspace_samples_seconds: list[float] = []
    write_high_water: dict[int, int] = {}
    children: dict[int, psutil.Process] = {}
    next_sample = started
    next_workspace_sample = started
    while worker.is_alive():
        now = time.monotonic()
        if now >= next_sample:
            children.update({child.pid: child for child in psutil.Process().children(recursive=True)
                             if child.pid not in baseline})
            current_rss = 0
            for process in children.values():
                try:
                    if not process.is_running():
                        continue
                    current_rss += process.memory_info().rss
                    counters = process.io_counters()
                    write_high_water[process.pid] = counters.write_bytes
                except psutil.Error:
                    continue
            process_samples_seconds.append(round(now - started, 3))
            rss_samples.append(current_rss)
            write_samples.append(sum(write_high_water.values()))
            next_sample = now + RSS_SAMPLE_INTERVAL_SECONDS
            if now >= next_workspace_sample:
                workspace_samples.append(workspace_bytes(workspace))
                workspace_samples_seconds.append(round(now - started, 3))
                next_workspace_sample = now + WORKSPACE_SAMPLE_INTERVAL_SECONDS
        if now - started > OPERATION_TIMEOUT_SECONDS:
            raise RuntimeError("Packaging metric operation exceeded its hosted timeout.")
        worker.join(RSS_SAMPLE_INTERVAL_SECONDS)
    worker.join()
    if failures:
        raise RuntimeError(f"Packaging metric operation failed: {failures[0]}")
    elapsed = time.monotonic() - started
    return {
        "observer_readiness_seconds": completed.get("readiness_seconds"),
        "operation_lifecycle_seconds": elapsed,
        "process_sample_seconds": process_samples_seconds,
        "child_process_rss_samples_bytes": rss_samples,
        "child_process_rss_peak_sampled_bytes": max(rss_samples, default=0),
        "child_process_write_bytes_samples": write_samples,
        "child_process_write_bytes_peak_sampled": max(write_samples, default=0),
        "workspace_bytes_samples": workspace_samples,
        "workspace_sample_seconds": workspace_samples_seconds,
        "workspace_bytes_peak_sampled": max(workspace_samples, default=0),
        "workspace_sampling_seconds": WORKSPACE_SAMPLE_INTERVAL_SECONDS,
        "rss_sampling_seconds": RSS_SAMPLE_INTERVAL_SECONDS,
        "rss_note": "Summed live child working sets; shared pages may be counted more than once.",
    }


def observe_qt_selection(
    executable: Path, profile: Path, environment: dict[str, str],
) -> float:
    """Follow the old or new public launcher through Qt supervision and shutdown.

    Args:
        executable (Path): The installed public launcher for the selected layout.
        profile (Path): Disposable, unique AppData for this one launch.
        environment (dict[str, str]): System-only process paths and owned temp.
    Returns:
        float: Seconds until the supervisor completes and the Qt window is visible.
    Raises:
        RuntimeError: Qt readiness, a usable window or clean exit was not observed.
    """
    user_dir = profile / APP_PROFILE_NAME
    user_dir.mkdir(parents=True)
    settings = profile / "fixture.json"
    settings.write_text(json.dumps({"close_to_tray": False, "start_with_windows": False}), encoding="utf-8")
    save_gui_backend(user_dir, "pyside6")
    log = user_dir / "watcher.log"
    qt_pid = None
    started = time.monotonic()
    with subprocess.Popen([str(executable), "--config", str(settings)], cwd=executable.parent,
                          env={**environment, "APPDATA": str(profile),
                               "LOCALAPPDATA": str(profile / "local")}) as launcher:
        try:
            deadline = started + INSTALLED_SMOKE_SECONDS
            while time.monotonic() < deadline:
                output = log.read_text(encoding="utf-8", errors="replace") if log.is_file() else ""
                owners = re.findall(r"phase=start; reason=manual; pid=(\d+); version=", output)
                if "Qt startup fallback:" in output or launcher.poll() not in (None, 0):
                    raise RuntimeError("The installed public launcher did not select Qt.")
                if owners:
                    qt_pid = int(owners[-1])
                    if launcher.poll() == 0 and installed_window(qt_pid) is not None:
                        elapsed = time.monotonic() - started
                        if not request_window_close(qt_pid):
                            raise RuntimeError("The installed Qt window refused ordinary close.")
                        break
                time.sleep(GUI_STARTUP_POLL_SECONDS)
            else:
                raise RuntimeError("The installed Qt supervisor did not reach readiness.")
            deadline = time.monotonic() + INSTALLED_SMOKE_SECONDS
            while time.monotonic() < deadline:
                output = log.read_text(encoding="utf-8", errors="replace")
                if f"phase=mainloop_returned; pid={qt_pid}" in output:
                    wait_for_desktop_exit(qt_pid)
                    return elapsed
                time.sleep(GUI_STARTUP_POLL_SECONDS)
            raise RuntimeError("The installed Qt window did not close normally.")
        finally:
            if launcher.poll() is None:
                launcher.kill()
            if qt_pid is not None:
                request_window_close(qt_pid)


def measure_frontend(
    backend: str, executable: Path, version: str, environment: dict[str, str],
    workspace: Path,
) -> list[dict[str, object]]:
    """Measure first, warm and repeated process starts of one installed backend.

    Args:
        backend (str): `tk` or `qt` installed frontend identity.
        executable (Path): Public installed application executable.
        version (str): Exact setup version for Tk's isolated update profile.
        environment (dict[str, str]): System-only process environment.
        workspace (Path): Temporary owner for each isolated frontend profile.
    Returns:
        list[dict[str, object]]: Three native readiness and sampled-memory observations.
    Raises:
        RuntimeError: The installed native frontend did not close after startup.
    """
    observations: list[dict[str, object]] = []
    for index, phase in enumerate(FRONTEND_LAUNCH_PHASES):
        profile = workspace / "profiles" / f"{backend}-{index}"
        profile.parent.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix="startup-", dir=workspace) as temporary:
            isolated = {**environment, "TEMP": temporary, "TMP": temporary}
            if backend == "tk":
                run = lambda: observe_installed_tk(executable, profile, isolated, version)
            else:
                run = lambda: observe_qt_selection(executable, profile, isolated)
            measured = sample_operation(run, Path(temporary))
        observations.append({
            "backend": backend,
            "launch_phase": phase,
            "profile_reused": False,
            "os_cache_state_controlled": False,
            **measured,
        })
    return observations


def measure_layout(
    name: str, root: Path, version: str, environment: dict[str, str],
    workspace: Path, qt_executable: Path | None = None,
    *, install_sample: dict[str, object] | None = None,
) -> dict[str, object]:
    """Measure one installed layout's files and available native frontends.

    Args:
        name (str): Stable identifier for the source packaging layout.
        root (Path): Installed application root or historical bundle directory.
        version (str): Version assigned to the isolated Tk update profile.
        environment (dict[str, str]): System-only runtime environment.
        workspace (Path): Temporary owner for startup profiles and samples.
        qt_executable (Path | None): Optional separate historical Qt executable.
        install_sample (dict[str, object] | None): Setup sampler when installed here.
    Returns:
        dict[str, object]: Tree/executable sizes and per-backend startup samples.
    Raises:
        OSError: Installer or temporary tree data could not be read.
        RuntimeError: A required native frontend smoke failed.
    """
    executable = root / "PHPGalleryUploader.exe"
    if not executable.is_file():
        raise RuntimeError(f"{name} does not contain PHPGalleryUploader.exe.")
    frontends = measure_frontend("tk", executable, version, environment, workspace)
    if qt_executable is not None or (root / "_internal").is_dir():
        frontends += measure_frontend("qt", executable, version, environment, workspace)
    python_dlls = sorted(
        path.relative_to(root).as_posix() for path in root.rglob("python*.dll")
        if re.fullmatch(r"python\d{2,}t?\.dll", path.name, re.IGNORECASE)
    )
    embedded_python = int(name in {"published-1.7.1-onefile-tk", "historical-96e7-dual-build"})
    if name == "candidate-unified" and len(python_dlls) != 1:
        raise RuntimeError("The measured common runtime must contain exactly one Python DLL.")
    result: dict[str, object] = {
        "layout": name,
        "root": {**directory_metrics(root),
                 "public_executable_bytes": executable.stat().st_size,
                 "root_path_role": "exact input root"},
        "frontend_startups": frontends,
        "python_runtimes": {
            "total": embedded_python + len(python_dlls),
            "embedded_in_authenticated_onefile": embedded_python,
            "installed_python_runtime_dlls": python_dlls,
        },
    }
    if qt_executable is not None:
        result["separate_qt_executable"] = {
            "filename": qt_executable.name, "size_bytes": qt_executable.stat().st_size,
        }
    if install_sample is not None:
        result["setup_install"] = install_sample
    return result


def collect_metrics(args: argparse.Namespace) -> dict[str, object]:
    """Compare authenticated stable, historical dual and candidate layouts.

    Args:
        args (argparse.Namespace): Validated exact setup, prior paths and output.
    Returns:
        dict[str, object]: Three layout metrics and raw native startup evidence.
    Raises:
        OSError: Installer or temporary tree data could not be read.
        RuntimeError: Installation or a required frontend smoke failed.
    """
    environment = system_only_environment(dict(os.environ))
    # The historical package retains nested Qt QML plugin names. Use the short
    # OS temp root so install paths do not depend on the audit directory depth.
    with tempfile.TemporaryDirectory(prefix="pg445-") as directory:
        workspace = Path(directory)
        stable_setup = workspace / "PHPGalleryUploader-1.7.1-Setup.exe"
        stable_size, stable_hash = fetch_public_stable_asset(stable_setup)
        stable_root = workspace / "stable-installed"
        stable_install = sample_operation(
            lambda: install_setup(stable_setup, stable_root, workspace, environment), workspace,
        )
        stable = measure_layout(
            "published-1.7.1-onefile-tk", stable_root, "1.7.1", environment,
            workspace / "stable-profiles", install_sample=stable_install,
        )
        prior_match = re.fullmatch(PRIOR_SETUP_NAME_PATTERN, args.prior_setup.name)
        if prior_match is None:
            raise RuntimeError("The prior setup filename is not canonical.")
        prior_version = prior_match.group("version")
        prior_setup = workspace / args.prior_setup.name
        shutil.copyfile(args.prior_setup, prior_setup)
        prior_root = workspace / "prior-installed"
        prior_install = sample_operation(
            lambda: install_setup(prior_setup, prior_root, workspace, environment), workspace,
        )
        prior = measure_layout(
            "historical-96e7-dual-build", prior_root, prior_version,
            environment, workspace / "prior-profiles",
            prior_root / "qt" / "PHPGalleryUploader.exe",
            install_sample=prior_install,
        )
        prior_upgrade_started = time.monotonic()
        install_setup(args.setup, prior_root, workspace, environment)
        upgrade_seconds = time.monotonic() - prior_upgrade_started
        old_slots = ("qt", "qt.winapp-previous", "_internal.winapp-previous")
        launcher = prior_root / "PHPGalleryUploader.exe"
        if any((prior_root / slot).exists() for slot in old_slots) or tuple(
            prior_root.rglob(launcher.name),
        ) != (launcher,):
            raise RuntimeError("The upgraded historical build retained a stale runtime slot.")
        prior_upgrade = {
            "install_seconds": upgrade_seconds,
            "tk_ready_seconds": observe_installed_tk(
                launcher, workspace / "upgraded-dual-tk", environment, args.version,
                require_clean_imports=True,
            ),
            "qt_ready_seconds": observe_qt_selection(
                launcher, workspace / "upgraded-dual-qt", environment,
            ),
        }
        candidate_root = workspace / "candidate-installed"
        candidate_install = sample_operation(
            lambda: install_setup(args.setup, candidate_root, workspace, environment), workspace,
        )
        candidate = measure_layout(
            "candidate-unified", candidate_root, args.version, environment,
            workspace / "candidate-profiles", install_sample=candidate_install,
        )
        return {
            "schema_version": 1, "status": "PASS",
            "stage": "issue-445-three-layout-packaging-metrics",
            "source_sha": args.source_sha,
            "candidate_setup": {
                "filename": args.setup.name, "version": args.version,
                "size_bytes": args.setup.stat().st_size,
                "sha256": args.setup_sha256,
            },
            "published_setup": {
                "filename": stable_setup.name, "version": "1.7.1",
                "size_bytes": stable_size, "sha256": stable_hash,
                "authentication": "qualify_installer_updates.fetch_public_stable_asset",
            },
            "prior_setup": {
                "filename": args.prior_setup.name,
                "version": prior_version,
                "size_bytes": args.prior_setup.stat().st_size,
                "sha256": file_sha256(args.prior_setup),
                "authentication": "Exact historical 96e7c85 workflow artifact; workflow verifies descriptor bytes.",
            },
            "layouts": [stable, prior, candidate],
            "historical_dual_upgrade": prior_upgrade,
            "startup_scope": {
                "cold_process": "First process start after layout installation; OS file cache was not cleared.",
                "warm_process": "Second process start after normal close; Windows OS cache state is uncontrolled.",
                "third_process": "Third normal process start with a fresh isolated profile; not an in-app restart measurement.",
                "settings_restart": "Measured separately by the existing installed Settings roundtrip smoke, not inferred here.",
                "readiness_proxy": "Tk visible-window and Qt first-rendered-scene handshake.",
                "memory_proxy": "Sampled summed child process working sets, shared pages may be counted more than once.",
            },
            "host": {"platform": platform.platform(), "python": sys.version},
        }


def main() -> int:
    """Admit one exact hosted Windows setup and retain JSON and raw CSV samples.

    Returns:
        int: Zero when installation and all six native startup proxies complete.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--setup", type=Path, required=True)
    parser.add_argument("--prior-setup", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--source-sha")
    parser.add_argument("--version")
    args = parser.parse_args()
    candidate = (
        os.environ.get("PHP_GALLERY_ISSUE_445_CANDIDATE")
        or os.environ.get("GITHUB_SHA", "")
    )
    head = subprocess.run(
        ["git", "rev-parse", "HEAD"], check=False, capture_output=True,
        text=True, cwd=Path(__file__).resolve().parents[1],
    )
    args.source_sha = args.source_sha or candidate
    args.setup = args.setup.resolve()
    args.prior_setup = args.prior_setup.resolve()
    match = re.fullmatch(SETUP_NAME_PATTERN, args.setup.name)
    args.version = args.version or (match.group("version") if match is not None else "")
    if (os.environ.get("GITHUB_ACTIONS") != "true" or sys.platform != "win32"
            or not args.source_sha or args.source_sha != candidate
            or head.returncode != 0 or head.stdout.strip() != args.source_sha
            or not args.setup.is_file() or match is None
            or not args.prior_setup.is_file()
            or re.fullmatch(PRIOR_SETUP_NAME_PATTERN, args.prior_setup.name) is None
            or args.version != match.group("version")
            or not args.output.is_absolute()):
        parser.error("Run on hosted Windows with one exact candidate common setup.")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.setup_sha256 = file_sha256(args.setup)
    version_evidence = dependency_versions()
    try:
        report = collect_metrics(args)
        report["dependencies"] = version_evidence
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        report = {
            "schema_version": 1, "status": "FAIL",
            "stage": "issue-445-three-layout-packaging-metrics",
            "source_sha": args.source_sha,
            "setup_name": args.setup.name,
            "dependencies": version_evidence,
            "failure": f"{type(error).__name__}: {error}"[:1000],
        }
    content = json.dumps(report, indent=2, allow_nan=False).encode("utf-8")
    if len(content) > REPORT_MAX_BYTES:
        report = {
            "schema_version": 1, "status": "FAIL",
            "stage": "selected-installer-packaging-metrics",
            "source_sha": args.source_sha,
            "setup_name": args.setup.name,
            "dependencies": version_evidence,
            "failure": "The sampled metric report exceeded its evidence size limit.",
        }
        content = json.dumps(report, indent=2).encode("utf-8")
    args.output.write_bytes(content)
    with args.output.with_suffix(".csv").open(
        "w", newline="", encoding="utf-8",
    ) as stream:
        writer = csv.writer(stream)
        writer.writerow((
            "phase", "elapsed_seconds", "child_process_rss_bytes",
            "child_process_write_bytes", "workspace_bytes",
        ))
        for layout in report.get("layouts", []):
            for run in layout.get("frontend_startups", []):
                phase = f"{layout['layout']}-{run['backend']}-{run['launch_phase']}"
                rss_samples = run.get("child_process_rss_samples_bytes", [])
                write_samples = run.get("child_process_write_bytes_samples", [])
                for elapsed, rss, writes in zip(run["process_sample_seconds"], rss_samples, write_samples):
                    writer.writerow((phase, elapsed, rss, writes, ""))
                for elapsed, size in zip(run["workspace_sample_seconds"], run["workspace_bytes_samples"]):
                    writer.writerow((phase, elapsed, "", "", size))
    return int(report.get("status") != "PASS")


if __name__ == "__main__":
    raise SystemExit(main())
