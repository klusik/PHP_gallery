# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/qualify_installer_updates.py
# Module Type: Hosted Windows update qualification
# Purpose: Exercise the released onefile-to-onedir and onedir update journeys.
# Responsibilities: Authenticate release assets, run the real helper and retain
#   isolated profile evidence without publishing qualification installers.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Qualify real Windows installer updates using isolated hosted profiles."""

import argparse
import atexit
import base64
import ctypes
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import time
from urllib.request import Request, urlopen
from uuid import uuid4
from ctypes import wintypes

from qualify_installed_linked_switch import qualify_installed_linked_switch
from qt_installer_tools import (
    INSTALLED_SMOKE_SECONDS, choose_installed_backend, installed_window,
    request_window_close, wait_installed_backend,
)
from uploader.config import save_thumbnail_batch_size, save_ui_language
from uploader.credential_store import WindowsCredentialStore
from uploader.dependency_versions import dependency_versions
from uploader.gallery_catalogue_cache import (
    GalleryCatalogueCache, GalleryCatalogueSnapshot,
)
from uploader.gui_backend import (
    GUI_BACKEND_FILE, GUI_BACKENDS, GUI_CHILD_OPTION, GUI_STARTUP_DIRECTORY,
    GUI_STARTUP_RESPONSE, GuiBackend, load_gui_backend, save_gui_backend,
)
from uploader.gui_backend_profile import inspect_gui_profile_work
from uploader.gallery_media_cache import GalleryMediaCache, MEDIA_CACHE_MAX_BYTES
from uploader.models import ActivityEvent, ImportJob
from uploader.state_store import (
    JobStateStore, load_recent_gallery_addresses, save_recent_gallery_addresses,
)
from uploader.update_helper import (
    UPDATE_RUNTIME_PROOF_CHUNK_BYTES, UPDATE_RUNTIME_PROOF_MAX_BYTES,
    UPDATE_RUNTIME_PROOF_MAX_ENTRIES, stage_update_helper_runtime,
)
from uploader.windows_file_identity import (
    FILE_ATTRIBUTE_REPARSE_POINT, LOAD_LIBRARY_SEARCH_SYSTEM32,
)
from uploader.flight_jobs import (
    SessionPlan, WatcherContinuation, WatcherContinuationError,
    WATCHER_CONTINUATION_LIFETIME_SECONDS, WorkspaceLease,
    connection_binding, saved_flights,
)
from uploader.flight_recorder import FlightRecorder
from uploader.watcher_profile import WATCHER_PREFERENCE_TYPES, WatcherProfile
from uploader.watch_queue import WatchQueueStore, observe_watch_source


# Purpose: Identify the immutable public WinApp starting installer.
# Type: str. Units: GitHub release tag. Scope: update qualification source.
# Consumers: fetch_public_stable_asset. Rationale: F3 begins at issued 1.7.1.
PUBLIC_STABLE_TAG = "v_1.7.1"
PUBLIC_REPOSITORY_API = "https://api.github.com/repos/klusik/PHP_gallery/"
# Purpose: Bind the release tag to the commit that supplied the published setup.
# Type: str. Units: Git commit SHA. Scope: update qualification source.
# Consumers: fetch_public_stable_asset. Rationale: reject a retargeted release.
PUBLIC_STABLE_COMMIT = "b63d6d5e5bee014ab3750080833f49dcc27e9a34"
# Purpose: Name the stable Windows setup attached to the exact public release.
# Type: str. Units: GitHub release asset. Scope: update qualification source.
# Consumers: fetch_public_stable_asset. Rationale: bind the installed baseline.
PUBLIC_STABLE_ASSET = "PHPGalleryUploader-1.7.1-Setup.exe"
# Purpose: Require one canonical setup filename for every published flavor.
# Type: str. Units: filename template. Scope: internal and public qualification setups.
# Consumers: _require_standard_setup. Rationale: one release asset contains both backends.
COMMON_SETUP_NAME_TEMPLATE = "PHPGalleryUploader-{version}-Setup.exe"
# Purpose: Exercise the installer-wide exclusion used by both candidate versions.
# Type: str. Units: Windows named mutex. Scope: one disposable preflight fixture.
# Consumers: _verify_setup_serialization. Rationale: Identical to the published
# product's SetupMutex global name, independent of installed version or backend.
UPDATE_SETUP_MUTEX_NAME = r'Global\PHPGalleryUploaderSetup-E7CEB8DB'
# Purpose: Bound a single hosted network operation.
# Type: int. Units: seconds. Scope: public release metadata and setup download.
# Consumers: _read_url. Rationale: fail the isolated job instead of hanging.
HTTP_TIMEOUT_SECONDS = 45
# Purpose: Bound release API metadata before JSON allocation.
# Type: int. Units: bytes. Scope: one GitHub release API response.
# Consumers: _read_url. Rationale: release descriptions and asset metadata fit.
RELEASE_METADATA_MAX_BYTES = 4 * 1024 * 1024
# Purpose: Bound the known public setup download before buffering.
# Type: int. Units: bytes. Scope: one stable setup response.
# Consumers: _read_url. Rationale: exceeds the published 33,818,403-byte asset.
PUBLIC_SETUP_MAX_BYTES = 48 * 1024 * 1024
# Purpose: Supply a non-launchable but digest-valid installer to the real helper.
# Type: bytes. Units: payload bytes. Scope: one ShellExecute refusal journey.
# Consumers: installed updater qualification. Rationale: Windows rejects this
#   synthetic non-PE image before a setup process can start.
NONLAUNCHABLE_SETUP_PAYLOAD = b"Synthetic qualification payload; not a Windows executable.\r\n"
# Purpose: Bound helper completion and exact-app shutdown waits.
# Type: int. Units: seconds. Scope: one update/restart lifecycle.
# Consumers: _wait_for_marker, _close_restarted_app. Rationale: runner smoke cap.
UPDATE_WAIT_SECONDS = 120
# Purpose: Bound the retained sanitized qualification evidence document.
# Type: int. Units: bytes. Scope: one F3 hosted update run.
# Consumers: main. Rationale: reports contain fixed scenario rows, never raw logs.
QUALIFICATION_REPORT_MAX_BYTES = 16 * 1024
# Purpose: Poll the local continuation marker while the installed app starts.
# Type: float. Units: seconds. Scope: one exact updater restart.
# Consumers: _wait_for_watcher_consumption. Rationale: avoids busy polling.
PROCESS_POLL_SECONDS = 0.1
# Purpose: Allow normal WM_CLOSE to be delivered before checking process exit.
# Type: float. Units: seconds. Scope: one updated GUI process.
# Consumers: _close_restarted_app. Rationale: yield to the UI message loop.
WINDOW_CLOSE_SETTLE_SECONDS = 0.25
# Purpose: Observe setup staging and installed runtime while the helper runs.
# Type: float. Units: seconds. Scope: one hosted update handoff.
# Consumers: _run_real_helper. Rationale: captures sustained disk changes with
# bounded directory enumeration while the installer replaces the runtime.
UPDATE_DISK_SAMPLE_SECONDS = 0.5
# Purpose: Observe only fixed preflight facts from the exact native setup log.
# Type: int. Units: bytes. Scope: one private Windows setup log.
# Consumers: _observed_setup_preflight. Rationale: Covers a full onedir install
# trace without allowing arbitrary log output into the qualification artifact.
UPDATE_SETUP_LOG_MAX_BYTES = 4 * 1024 * 1024
# Purpose: Observe real installed capture after its confirmed update continuation.
# Type: int. Units: milliseconds. Scope: one waiting Watcher observation.
# Consumers: _measure_waiting_watcher. Rationale: Matches the shell idle sample
# and includes several idle scans with an empty synthetic watched folder.
WATCHER_IDLE_SAMPLE_MS = 4000
# Purpose: Sample both published and retired payloads during the Inno handoff.
# Type: int. Units: filesystem entries. Scope: one owned measurement root.
# Consumers: _observed_update_bytes. Rationale: Two independently bounded
# runtime trees may coexist while setup publishes its replacement.
UPDATE_USAGE_MAX_ENTRIES = 2 * UPDATE_RUNTIME_PROOF_MAX_ENTRIES
# Purpose: Express native update-marker elapsed time in the evidence unit.
# Type: int. Units: milliseconds per second. Scope: update results.
# Consumers: _run_real_helper. Rationale: consistent with staging duration.
UPDATE_MILLISECONDS_PER_SECOND = 1000
# Purpose: Bound one PowerShell process/version query.
# Type: int. Units: seconds. Scope: one hosted process observation.
# Consumers: _restart_pids, _installed_version. Rationale: WMI is local and bounded.
WINDOWS_QUERY_TIMEOUT_SECONDS = 20
# Purpose: Bound command text passed to the native Windows argv parser.
# Type: int. Units: UTF-16 code units. Scope: one WMI process command line.
# Consumers: _windows_command_arguments. Rationale: Windows reserves one of the
#   32,767 CreateProcess units for its terminating null character.
HELPER_COMMAND_LINE_MAX_UNITS = 32766
# Purpose: Provide a loopback endpoint that cannot identify a real gallery.
# Type: str. Units: upload URL. Scope: no-network Watcher continuation fixture.
# Consumers: synthetic profile and installed Watcher. Rationale: localhost port
#   9 has no authority; the active switch fixture remains before inventory.
SYNTHETIC_WATCHER_URL = "http://localhost:9/index.php?page=upload_automation_upload"
# Purpose: Provide a non-secret key used only to bind synthetic local state.
# Type: str. Units: API-key-shaped fixture value. Scope: isolated hosted profile.
# Consumers: config seed and connection_binding. Rationale: no credential or gallery is contacted.
SYNTHETIC_WATCHER_KEY = "F3_SYNTHETIC_ONLY_NO_GALLERY_ACCESS"
# Purpose: Make malformed helper tickets fail the strict schema check.
# Type: int. Units: ticket schema version. Scope: rejected helper fixture.
# Consumers: _run_rejected_helper_ticket. Rationale: schema 9 is unsupported.
INVALID_TICKET_SCHEMA_VERSION = 9
# Purpose: Give expired evidence a timestamp beyond the accepted continuation lifetime.
# Type: int. Units: seconds. Scope: stale Watcher ticket fixture.
# Consumers: _write_expired_watcher_ticket. Rationale: cross the exact expiry boundary.
STALE_CHECKPOINT_AGE_MARGIN_SECONDS = 1
# Purpose: Size the random non-secret Credential Manager sentinel.
# Type: int. Units: bytes. Scope: hosted profile preservation check.
# Consumers: qualify_updates. Rationale: valid opaque payload without real access.
SYNTHETIC_CREDENTIAL_BYTES = 48
# Purpose: Version marker used by synthetic empty Watcher continuation evidence.
# Type: str. Units: application version. Scope: one synthetic F3 session.
# Consumers: _verify_synthetic_watcher_ticket. Rationale: preserve valid format.
SYNTHETIC_WATCHER_SOURCE_VERSION = "1.7.1"
# Purpose: Keep the expired checkpoint's version shape valid and independent of expiry.
# Type: str. Units: application version. Scope: stale Watcher ticket fixture.
# Consumers: _write_expired_watcher_ticket. Rationale: expiry is the sole refusal reason.
SYNTHETIC_WATCHER_TARGET_VERSION = "1.7.2"
# Purpose: Retain a small real thumbnail-cache preference in the isolated profile.
# Type: int. Units: bytes. Scope: synthetic hosted profile's shared thumbnail cache.
# Consumers: _seed_durable_profile_state. Rationale: 8 MiB is enough for the tiny fixture.
SYNTHETIC_THUMBNAIL_LIMIT_BYTES = 8 * 1024 * 1024
# Purpose: Bound pre/post snapshots of durable profile state used by F3.
# Type: int. Units: filesystem entries. Scope: app and local data profile roots.
# Consumers: _snapshot_durable_profile_state. Rationale: synthetic state stays small.
PROFILE_SNAPSHOT_MAX_ENTRIES = 1024
# Purpose: Bound total bytes read while preserving isolated durable profile evidence.
# Type: int. Units: bytes. Scope: app and local data profile roots.
# Consumers: _snapshot_durable_profile_state. Rationale: covers saved PDF and cache fixtures.
PROFILE_SNAPSHOT_MAX_BYTES = 32 * 1024 * 1024
# Purpose: Provide one valid, non-secret PNG object to exercise the local media cache.
# Type: str. Units: base64-encoded bytes. Scope: synthetic cache fixture.
# Consumers: _seed_durable_profile_state. Rationale: a 1x1 transparent image has no gallery data.
SYNTHETIC_CACHE_PNG_BASE64 = (
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+7I8AAAAASUVORK5CYII="
)
# Purpose: Bind synthetic saved catalogue rows without contacting a gallery.
# Type: str. Units: HTTPS issuer. Scope: isolated local cache/profile fixtures.
# Consumers: _seed_durable_profile_state. Rationale: reserved example domain has no live account.
SYNTHETIC_GALLERY_ISSUER = "https://qualification.example"
# Purpose: Seed a valid non-pending Watcher scan cadence in each isolated profile.
# Type: float. Units: seconds. Scope: synthetic Watcher preference.
# Consumers: _seed_durable_profile_state. Rationale: conservative fixture cadence is not run.
SYNTHETIC_WATCH_INTERVAL_SECONDS = 30.0
# Purpose: Seed a valid Watcher file-stability threshold without scanning files.
# Type: float. Units: seconds. Scope: synthetic Watcher preference.
# Consumers: _seed_durable_profile_state. Rationale: settings are persisted only, never executed.
SYNTHETIC_WATCH_STABLE_SECONDS = 2.0
# Purpose: Scan the real switch fixture quickly without starting a worker request.
# Type: float. Units: seconds. Scope: real installed backend-switch Watcher.
# Consumers: _verify_synthetic_watcher_ticket. Rationale: 0.2 s detects source B
# promptly behind the durable retry-wait FIFO head A.
SWITCH_WATCH_INTERVAL_SECONDS = 0.2
# Purpose: Provide a long secondary stability preference for switch fixtures.
# Type: float. Units: seconds. Scope: each hosted cross-backend source.
# Consumers: _verify_synthetic_watcher_ticket. Rationale: One hour exceeds the
# complete journey; the future retry deadline is the deterministic worker gate.
SWITCH_SOURCE_STABILITY_SECONDS = 3600.0
# Purpose: Bound observation of one synthetic file in the resumed real queue.
# Type: int. Units: seconds. Scope: one installed backend-switch journey.
# Consumers: _create_and_wait_for_switch_source. Rationale: Quick journal polling
# should observe both exact FIFO items without stalling the update job.
SWITCH_SOURCE_WAIT_SECONDS = 45
# Purpose: Keep the retryable FIFO head asleep through both native restarts.
# Type: float. Units: seconds. Scope: one isolated backend-switch qualification.
# Consumers: _verify_synthetic_watcher_ticket. Rationale: A future source retry
# avoids unsafe review states while deterministically fencing later FIFO items.
SWITCH_RETRY_DELAY_SECONDS = 86400.0
# Purpose: Keep the qualification retry deadline outside the whole hosted job.
# Type: float. Units: seconds. Scope: one retry-wait native switch fixture.
# Consumers: _create_and_wait_for_switch_source, _complete_resumed_backend_switch,
# _assert_durable_profile_preserved. Rationale: Reject a run that outlives the
# deterministic no-request retry window instead of accepting an advanced head.
SWITCH_RETRY_MINIMUM_REMAINING_SECONDS = 43200.0
# Purpose: Cap attempt counters retained in sanitized queue failure details.
# Type: int. Units: attempts. Scope: one synthetic Watcher queue observation.
# Consumers: _create_and_wait_for_switch_source. Rationale: Diagnostic evidence
# is structural only and must not echo arbitrary unbounded journal values.
SWITCH_QUEUE_ATTEMPT_DIAGNOSTIC_MAX = 100
# Purpose: Name the one harmless image held through a native backend switch.
# Type: str. Units: basename. Scope: synthetic no-network Watcher fixture.
# Consumers: _switch_resumed_backend. Rationale: Fixed local name avoids user data.
SWITCH_PENDING_SOURCE_NAME = 'issue-445-held-pending.png'
# Purpose: Name one safe retry-wait source at the head of the switch FIFO.
# Type: str. Units: basename. Scope: isolated synthetic Watcher session.
# Consumers: _verify_synthetic_watcher_ticket. Rationale: A future source retry
# prevents the real worker from reaching inventory or upload for later sources.
SWITCH_RETRY_HEAD_NAME = 'issue-445-retry-wait-head.png'


def _read_url(
    url: str, maximum_bytes: int = RELEASE_METADATA_MAX_BYTES, *, api_token: str = "",
) -> bytes:
    """Read one HTTPS response with a fixed GitHub API identity.

    Args:
        url (str): Public GitHub API or release-asset HTTPS URL.
        maximum_bytes (int): Maximum complete response body accepted.
        api_token (str): Optional Actions token for this repository's metadata only.
    Returns:
        bytes: Complete response body.
    Raises:
        RuntimeError: The request is not HTTPS or exceeds its bounded operation.
    """
    if not url.startswith("https://"):
        raise RuntimeError("Update qualification accepts HTTPS sources only.")
    request = Request(url, headers={
        "Accept": "application/vnd.github+json",
        "User-Agent": "PHP-Gallery-WinApp-update-qualification",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    if api_token and url.startswith(PUBLIC_REPOSITORY_API):
        request.add_unredirected_header("Authorization", f"Bearer {api_token}")
    with urlopen(request, timeout=HTTP_TIMEOUT_SECONDS) as response:
        if response.status != 200:
            raise RuntimeError("GitHub returned a non-success response.")
        declared_size = response.headers.get("Content-Length")
        if declared_size is not None and int(declared_size) > maximum_bytes:
            raise RuntimeError("GitHub response exceeds the qualification size limit.")
        payload = response.read(maximum_bytes + 1)
        if len(payload) > maximum_bytes:
            raise RuntimeError("GitHub response exceeds the qualification size limit.")
        return payload


def _verify_release_tag_commit(api_token: str) -> None:
    """Resolve the actual Git tag object to the expected immutable commit.

    Args:
        api_token (str): Hosted token restricted to this repository's API requests.
    Returns:
        None: The live tag ref resolves directly or through one annotated tag.
    Raises:
        RuntimeError: The tag ref is missing, malformed or points elsewhere.
    """
    base = PUBLIC_REPOSITORY_API + "git/"
    reference = json.loads(_read_url(
        base + "ref/tags/" + PUBLIC_STABLE_TAG, api_token=api_token,
    ).decode("utf-8"))
    tag_object = reference.get("object")
    if not isinstance(tag_object, dict):
        raise RuntimeError("Public stable tag reference is malformed.")
    if tag_object.get("type") == "tag":
        annotation = json.loads(
            _read_url(base + "tags/" + str(tag_object.get("sha", "")), api_token=api_token)
            .decode("utf-8"),
        )
        tag_object = annotation.get("object")
    if (not isinstance(tag_object, dict)
            or tag_object.get("type") != "commit"
            or tag_object.get("sha") != PUBLIC_STABLE_COMMIT):
        raise RuntimeError("Public stable tag does not resolve to the expected commit.")


def fetch_public_stable_asset(destination: Path, *, api_token: str = "") -> tuple[int, str]:
    """Download and authenticate the originally published stable setup.

    Args:
        destination (Path): Exact runner-owned destination for the installer.
        api_token (str): Hosted token limited to this repository's metadata requests.
    Returns:
        tuple[int, str]: Published byte size and lowercase SHA-256 digest.
    Raises:
        RuntimeError: The tag, release, asset metadata or downloaded bytes differ.
    """
    api = PUBLIC_REPOSITORY_API + "releases/tags/" + PUBLIC_STABLE_TAG
    release = json.loads(_read_url(api, api_token=api_token).decode("utf-8"))
    _verify_release_tag_commit(api_token)
    if (release.get("tag_name") != PUBLIC_STABLE_TAG
            or release.get("draft") is not False
            or release.get("prerelease") is not False
            or release.get("target_commitish") != PUBLIC_STABLE_COMMIT):
        raise RuntimeError("Public 1.7.1 release identity does not match its checkpoint.")
    matches = [
        asset for asset in release.get("assets", [])
        if asset.get("name") == PUBLIC_STABLE_ASSET
    ]
    if len(matches) != 1:
        raise RuntimeError("The published stable setup is missing or ambiguous.")
    asset = matches[0]
    digest = asset.get("digest", "")
    size = asset.get("size")
    url = asset.get("browser_download_url", "")
    if (type(size) is not int or size <= 0
            or not re.fullmatch(r"sha256:[0-9a-f]{64}", digest)
            or not url.endswith("/" + PUBLIC_STABLE_ASSET)):
        raise RuntimeError("The published stable setup metadata is incomplete.")
    metadata_assets = [
        item for item in release.get("assets", [])
        if item.get("name") == "winapp-update.json"
    ]
    if len(metadata_assets) != 1:
        raise RuntimeError("The published stable update metadata is missing or ambiguous.")
    update_metadata = json.loads(
        _read_url(metadata_assets[0].get("browser_download_url", ""))
        .decode("utf-8")
    )
    descriptors = [
        item for item in update_metadata.get("assets", [])
        if item.get("name") == PUBLIC_STABLE_ASSET
    ]
    if (len(descriptors) != 1
            or descriptors[0].get("version") != "1.7.1"
            or descriptors[0].get("size") != size
            or descriptors[0].get("sha256") != digest.removeprefix("sha256:")):
        raise RuntimeError("Published WinApp metadata disagrees with the release asset.")
    payload = _read_url(url, PUBLIC_SETUP_MAX_BYTES)
    actual = hashlib.sha256(payload).hexdigest()
    if len(payload) != size or actual != digest.removeprefix("sha256:"):
        raise RuntimeError("The public stable setup bytes do not match release metadata.")
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_bytes(payload)
    print(
        f"Verified public {PUBLIC_STABLE_TAG} stable setup: "
        f"{size} bytes sha256={actual}", flush=True,
    )
    return size, actual


def _require_standard_setup(setup: Path, version: str, role: str) -> None:
    """Require a qualification input to use the shared installer asset name.

    Args:
        setup (Path): Build artifact passed into an installed update journey.
        version (str): Exact temporary application version embedded in the setup.
        role (str): Fixed name of the qualification journey input.
    Returns:
        None: The standard all-backends setup name matches the requested version.
    Raises:
        RuntimeError: The setup is missing or uses a flavor-specific filename.
    """
    expected = COMMON_SETUP_NAME_TEMPLATE.format(version=version)
    if not setup.is_file() or setup.name != expected:
        raise RuntimeError(f"{role} must be the common installer asset {expected}.")


def _run_setup(setup: Path, install: Path, work: Path) -> None:
    """Install one exact setup silently into its dedicated registered path.

    Args:
        setup (Path): Verified or current hosted qualification setup.
        install (Path): Unique installation directory registered by Inno Setup.
        work (Path): Private log owner for this setup invocation.
    Returns:
        None: Setup returns successfully after its install transaction.
    Raises:
        subprocess.SubprocessError: Setup failed or exceeded its deadline.
    """
    work.mkdir(parents=True, exist_ok=True)
    subprocess.run([
        str(setup), "/VERYSILENT", "/SUPPRESSMSGBOXES", "/NORESTART",
        "/NOICONS", "/DIR=" + str(install), "/LOG=" + str(work / "setup.log"),
    ], cwd=work, check=True, timeout=INSTALLED_SMOKE_SECONDS)


def _wait_for_marker(path: Path, process: subprocess.Popen) -> None:
    """Wait for one helper handshake marker while enforcing child health.

    Args:
        path (Path): Helper-owned marker to observe.
        process (subprocess.Popen): Exact helper process launched by this fixture.
    Returns:
        None: The helper created the requested marker.
    Raises:
        RuntimeError: The helper exited or missed the bounded handshake.
    """
    deadline = time.monotonic() + UPDATE_WAIT_SECONDS
    while time.monotonic() < deadline:
        if path.is_file():
            return
        if process.poll() is not None:
            raise RuntimeError("The installed update helper exited before handoff.")
        time.sleep(PROCESS_POLL_SECONDS)
    raise RuntimeError("The installed update helper exceeded its handshake deadline.")


def _restart_pids(executable: Path) -> list[int]:
    """Find live processes by exact absolute executable path using WMI.

    Args:
        executable (Path): Registered application path for the current phase.
    Returns:
        list[int]: Process IDs whose executable path matches exactly.
    Raises:
        subprocess.SubprocessError: The Windows process query failed.
    """
    script = (
        "$expected = [IO.Path]::GetFullPath('" + str(executable).replace("'", "''")
        + "'); Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath "
        + "-and [String]::Equals([IO.Path]::GetFullPath($_.ExecutablePath), "
        + "$expected, [StringComparison]::OrdinalIgnoreCase) } | "
        + "Select-Object -ExpandProperty ProcessId | ConvertTo-Json -Compress"
    )
    result = subprocess.run(
        ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script],
        check=True, capture_output=True, text=True,
        timeout=WINDOWS_QUERY_TIMEOUT_SECONDS,
    )
    output = result.stdout.strip()
    if not output:
        return []
    parsed = json.loads(output)
    return [parsed] if type(parsed) is int else [int(value) for value in parsed]


def _windows_command_arguments(command_line: str) -> list[str]:
    """Parse a WMI command line with the Windows argv quoting rules.

    Args:
        command_line (str): Exact Win32_Process.CommandLine value.
    Returns:
        list[str]: Native argv items decoded by CommandLineToArgvW.
    Raises:
        OSError: Windows could not allocate or release its parsed argument block.
    """
    if (not isinstance(command_line, str)
            or len(command_line.encode("utf-16-le")) // 2
            > HELPER_COMMAND_LINE_MAX_UNITS):
        raise ValueError("Windows helper command line exceeds its bound.")
    shell = ctypes.WinDLL(
        "shell32.dll", use_last_error=True,
        winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
    )
    shell.CommandLineToArgvW.argtypes = [
        wintypes.LPCWSTR, ctypes.POINTER(ctypes.c_int),
    ]
    shell.CommandLineToArgvW.restype = ctypes.POINTER(wintypes.LPWSTR)
    count = ctypes.c_int()
    values = shell.CommandLineToArgvW(command_line, ctypes.byref(count))
    if not values:
        raise OSError(ctypes.get_last_error(), "Cannot parse helper process arguments.")
    kernel = ctypes.WinDLL(
        "kernel32.dll", use_last_error=True,
        winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
    )
    kernel.LocalFree.argtypes = [wintypes.HLOCAL]
    kernel.LocalFree.restype = wintypes.HLOCAL
    try:
        return [values[index] for index in range(count.value)]
    finally:
        if kernel.LocalFree(ctypes.cast(values, wintypes.HLOCAL)):
            raise OSError(ctypes.get_last_error(), "Cannot release parsed helper arguments.")


class HelperIdentityUnavailable(RuntimeError):
    """Indicate a CIM snapshot that omitted a helper identity during teardown."""


def _owned_helper_pids(
    root_pid: int, helper: Path, ticket: Path,
) -> list[int]:
    """Find the ticket-bound helper and exact-image descendants.

    Args:
        root_pid (int): Popen PID returned for the private helper executable.
        helper (Path): Exact copied helper path inside one owned update session.
        ticket (Path): Exact ticket path that must appear in helper commands.
    Returns:
        list[int]: Verified root or orphaned child and owned descendants.
    Raises:
        RuntimeError: WMI cannot prove the exact helper identity and ancestry.
        HelperIdentityUnavailable: A candidate row lacks its image or command.
        subprocess.SubprocessError: The Windows process query failed.
    """
    script = (
        "$helperName = 'helper.exe'; "
        + "$rows = @(Get-CimInstance Win32_Process | Where-Object { $_.Name -ieq $helperName } "
        + "| Select-Object ProcessId,ParentProcessId,ExecutablePath,CommandLine); "
        + "ConvertTo-Json -InputObject $rows -Compress"
    )
    result = subprocess.run(
        ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script],
        check=True, capture_output=True, text=True,
        timeout=WINDOWS_QUERY_TIMEOUT_SECONDS,
    )
    output = result.stdout.strip()
    if not output:
        return []
    rows = json.loads(output)
    if isinstance(rows, dict):
        rows = [rows]
    by_id = {int(row["ProcessId"]): row for row in rows}
    expected_helper = helper.resolve(strict=True)
    expected_ticket = ticket.resolve(strict=True)

    def matches_helper_image(row: dict[str, object]) -> bool:
        """Verify exact installed-helper image identity.

        Args:
            row (dict[str, object]): One WMI process record for helper.exe.
        Returns:
            bool: Its executable resolves to the exact copied helper file.
        Raises:
            HelperIdentityUnavailable: WMI omitted the needed identity.
        """
        image = row.get("ExecutablePath")
        if not isinstance(image, str):
            raise HelperIdentityUnavailable("WMI omitted helper executable identity.")
        return expected_helper.samefile(Path(image))

    def matches_ticket_command(row: dict[str, object]) -> bool:
        """Verify that a helper process received this exact ticket path.

        Args:
            row (dict[str, object]): One WMI process record for the helper.
        Returns:
            bool: Parsed argv includes the exact helper mode and ticket file.
        Raises:
            HelperIdentityUnavailable: WMI omitted the image or command.
        """
        if not matches_helper_image(row):
            return False
        command = row.get("CommandLine")
        if not isinstance(command, str):
            raise HelperIdentityUnavailable("WMI omitted helper command identity.")
        arguments = _windows_command_arguments(command)
        return (
            len(arguments) == 3
            and arguments[1] == "--self-update-helper"
            and Path(arguments[2]).samefile(expected_ticket)
        )

    root = by_id.get(root_pid)
    if root is not None:
        if not matches_ticket_command(root):
            raise RuntimeError("WMI root did not match the exact helper ticket.")
        owned = {root_pid}
    else:
        owned = {
            process_id for process_id, row in by_id.items()
            if int(row.get("ParentProcessId", -1)) == root_pid
            and matches_ticket_command(row)
        }
    changed = True
    while changed:
        changed = False
        for process_id, row in by_id.items():
            if (process_id not in owned
                    and int(row.get("ParentProcessId", -1)) in owned
                    and matches_helper_image(row)):
                owned.add(process_id)
                changed = True
    return sorted(owned)


def _dismiss_owned_helper(
    process: subprocess.Popen, helper: Path, ticket: Path,
    deadline: float,
) -> bool:
    """Close visible windows only in the exact helper process tree.

    Args:
        process (subprocess.Popen): Root helper process owned by this fixture.
        helper (Path): Exact copied helper path inside one update session.
        ticket (Path): Exact session ticket bound to the helper command line.
        deadline (float): Monotonic limit for the close handshake.
    Returns:
        bool: The root helper exited before the deadline.
    Raises:
        RuntimeError: The exact owned helper process tree cannot be verified.
    """
    incomplete: HelperIdentityUnavailable | None = None
    while time.monotonic() < deadline:
        try:
            process_ids = _owned_helper_pids(process.pid, helper, ticket)
        except HelperIdentityUnavailable as exc:
            # CIM can still list a finishing process after dropping its command.
            # Wait for a complete snapshot before closing anything or accepting exit.
            incomplete = exc
        else:
            incomplete = None
            if process_ids:
                for process_id in process_ids:
                    request_window_close(process_id)
            elif process.poll() is not None:
                return True
        time.sleep(PROCESS_POLL_SECONDS)
    if incomplete is not None:
        raise incomplete
    return process.poll() is not None and not _owned_helper_pids(
        process.pid, helper, ticket,
    )


def _close_restarted_app(
    executable: Path, profile: Path, expected_nonce: str | None = None,
    *, expected_backend: str | None = None, expected_pid: int | None = None,
    staging: dict[str, int | str] | None = None,
) -> None:
    """Close one ready restarted app window once through normal WM_CLOSE.

    Args:
        executable (Path): Exact installed image to close after handoff.
        profile (Path): Isolated app profile for sanitized lifecycle evidence.
        expected_nonce (str | None): Required updater nonce, or None when the
            exact expected backend/PID identifies an ordinary restart.
        expected_backend (str | None): Required visible native frontend, if known.
        expected_pid (int | None): Exact HWND owner selected by a preceding backend switch.
        staging (dict[str, int | str] | None): Optional hosted resource evidence for this update.
    Returns:
        None: The normal-close request was accepted and every owned process exited.
    Raises:
        RuntimeError: The app failed to start, show a window or exit normally.
    """
    deadline = time.monotonic() + UPDATE_WAIT_SECONDS
    close_stage = 'awaiting-process'
    close_pid: int | None = None
    close_sent = False
    while time.monotonic() < deadline:
        process_ids = _restart_pids(executable)
        if not process_ids:
            if close_sent:
                return
            time.sleep(PROCESS_POLL_SECONDS)
            continue
        candidates = process_ids
        if (expected_nonce is not None or expected_backend is not None
                or expected_pid is not None):
            script = (
                "$rows = @(Get-CimInstance Win32_Process | "
                + "Where-Object { $_.Name -ieq 'PHPGalleryUploader.exe' } | "
                + "Select-Object ProcessId,ExecutablePath,CommandLine); "
                + "ConvertTo-Json -InputObject $rows -Compress"
            )
            output = subprocess.run(
                ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script],
                check=True, capture_output=True, text=True,
                timeout=WINDOWS_QUERY_TIMEOUT_SECONDS,
            ).stdout.strip()
            rows = json.loads(output) if output else []
            if isinstance(rows, dict):
                rows = [rows]
            candidates = []
            wrong_backend = False
            for row in rows:
                image, command, process_id = (
                    row.get("ExecutablePath"), row.get("CommandLine"),
                    row.get("ProcessId"),
                )
                if (not isinstance(image, str) or not isinstance(command, str)
                        or type(process_id) is not int
                        or process_id not in process_ids
                        or (expected_pid is not None and process_id != expected_pid)
                        or not executable.resolve(strict=True).samefile(Path(image))):
                    continue
                arguments = _windows_command_arguments(command)
                has_nonce = any(
                    arguments[index] == "--after-update"
                    and arguments[index + 1] == expected_nonce
                    for index in range(len(arguments) - 1)
                ) if expected_nonce is not None else True
                if not has_nonce:
                    continue
                observed_backend = 'pyside6' if GUI_CHILD_OPTION in arguments else 'tkinter'
                if (expected_backend is None or expected_backend == observed_backend):
                    if installed_window(process_id) is not None:
                        if staging is not None:
                            staging['resumed_backend'] = observed_backend
                        candidates.append(process_id)
                elif installed_window(process_id) is not None:
                    wrong_backend = True
            if wrong_backend and not candidates:
                raise RuntimeError(
                    'Update continuation opened a different GUI backend. '
                    + _restart_close_diagnostic(
                        profile, executable, 'resumed-backend-mismatch', None,
                    ),
                )
            # Every close candidate still matches the ticket and exact visible
            # process. The Qt supervisor has no application window of its own.
        close_stage = 'awaiting-visible-window'
        for process_id in candidates:
            if installed_window(process_id) is None:
                continue
            close_pid = process_id
            if expected_nonce is not None and staging is not None:
                staging.update(_measure_waiting_watcher(process_ids))
            if not request_window_close(process_id):
                raise RuntimeError(
                    "Ready app window rejected normal WM_CLOSE. "
                    + _restart_close_diagnostic(
                        profile, executable, 'close-post-failed', close_pid,
                    ),
                )
            close_sent = True
            close_stage = 'awaiting-normal-exit'
            break
        if close_sent:
            while time.monotonic() < deadline:
                if not _restart_pids(executable):
                    return
                time.sleep(PROCESS_POLL_SECONDS)
            break
        time.sleep(PROCESS_POLL_SECONDS)
    raise RuntimeError(
        "The exact updated app did not exit normally; "
        + _restart_close_diagnostic(
            profile, executable, close_stage, close_pid,
        ),
    )


def _measure_waiting_watcher(process_ids: list[int]) -> dict[str, int | str]:
    """Sample actual installed processes while the resumed Watcher has an empty queue.

    Args:
        process_ids (list[int]): Verified app and supervisor PIDs from this exact setup.
    Returns:
        dict[str, int | str]: Interval, process count, aggregate CPU milliseconds
            and sampled working set before/after, or a fixed unavailable category.
    """
    pids = ','.join(str(value) for value in process_ids)
    script = (
        '$ErrorActionPreference = "Stop"; $exactIds = @(' + pids + '); '
        '$first = @(Get-Process -Id $exactIds | Sort-Object Id | '
        'Select-Object Id,StartTime,TotalProcessorTime,WorkingSet64); '
        '$interval = [Diagnostics.Stopwatch]::StartNew(); '
        f'Start-Sleep -Milliseconds {WATCHER_IDLE_SAMPLE_MS}; '
        '$last = @(Get-Process -Id $exactIds | Sort-Object Id | '
        'Select-Object Id,StartTime,TotalProcessorTime,WorkingSet64); '
        '$interval.Stop(); $cpu = 0.0; $before = 0L; $after = 0L; '
        'for($i = 0; $i -lt $first.Count; $i++) { '
        'if ($last[$i].Id -ne $first[$i].Id -or '
        '$last[$i].StartTime -ne $first[$i].StartTime) { throw "Changed owner" }; '
        '$cpu += ($last[$i].TotalProcessorTime - $first[$i].TotalProcessorTime).TotalMilliseconds; '
        '$before += $first[$i].WorkingSet64; $after += $last[$i].WorkingSet64 }; '
        '[ordered]@{watcher_idle_wall_ms=$interval.ElapsedMilliseconds; '
        'watcher_idle_processes=$first.Count; watcher_idle_cpu_ms=[int][math]::Round($cpu); '
        'watcher_idle_rss_before_bytes=$before; watcher_idle_rss_after_bytes=$after} '
        '| ConvertTo-Json -Compress'
    )
    try:
        output = subprocess.run(
            ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', script],
            check=True, text=True, capture_output=True, timeout=WINDOWS_QUERY_TIMEOUT_SECONDS,
        ).stdout
        metrics = json.loads(output)
        if (not isinstance(metrics, dict) or metrics.get('watcher_idle_processes') != len(process_ids)
                or any(type(value) is not int or value < 0 for value in metrics.values())):
            raise ValueError('Installed Watcher sample could not be verified.')
    except (OSError, subprocess.SubprocessError, ValueError):
        return {'watcher_idle_status': 'unavailable'}
    return {'watcher_idle_status': 'measured', **metrics}


def _restart_close_diagnostic(
    profile: Path, executable: Path, stage: str, process_id: int | None,
) -> str:
    """Summarize fixed lifecycle categories after an app-close failure.

    Args:
        profile (Path): Isolated profile containing the application log.
        executable (Path): Exact installed image whose process lifetime is checked.
        stage (str): Fixed harness-owned close stage.
        process_id (int | None): Exact process sent the single close request.
    Returns:
        str: Path-free marker, process and lifecycle state summary.
    """
    marker_phase = 'missing'
    try:
        marker = json.loads(
            (profile / 'flights' / 'watcher-continuation.json')
            .read_text(encoding='utf-8'),
        )
        candidate = marker.get('phase') if isinstance(marker, dict) else None
        if isinstance(candidate, str) and re.fullmatch(r'[a-z_]{1,24}', candidate):
            marker_phase = candidate
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        pass
    try:
        lines = (profile / 'watcher.log').read_text(
            encoding='utf-8', errors='replace',
        ).splitlines()[-256:]
    except OSError:
        lines = []
    resume_rows: list[str] = []
    process_rows: list[str] = []
    for line in lines:
        resume = re.search(
            r'\[watcher-resume\] stage=([a-z_]{1,32}); reason=([a-z_]{1,48})',
            line,
        )
        if resume:
            resume_rows.append(resume.group(1) + '/' + resume.group(2))
        lifecycle = re.search(
            r'\[watcher-process\] phase=([a-z_]{1,32})'
            r'(?:; reason=([a-z_]{1,32}))?', line,
        )
        if lifecycle:
            process_rows.append(
                lifecycle.group(1)
                + ('/' + lifecycle.group(2) if lifecycle.group(2) else ''),
            )
    process_state = 'running' if _restart_pids(executable) else 'exited'
    resume_state = resume_rows[-1] if resume_rows else 'unavailable'
    lifecycle_state = process_rows[-1] if process_rows else 'unavailable'
    pid_state = str(process_id) if process_id is not None else 'none'
    return (
        'close=' + stage + '; marker=' + marker_phase + '; process='
        + process_state + '; close_pid=' + pid_state + '; resume='
        + resume_state + '; lifecycle=' + lifecycle_state + '.'
    )


def _installed_version(executable: Path) -> str:
    """Read the product version from one exact installed executable.

    Args:
        executable (Path): Registered setup target whose PE resource is read.
    Returns:
        str: Three-component product version.
    Raises:
        subprocess.SubprocessError: Windows could not read the PE resource.
    """
    value = subprocess.run(
        ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command",
         "(Get-Item -LiteralPath '" + str(executable).replace("'", "''")
         + "').VersionInfo.ProductVersion"],
        check=True, capture_output=True, text=True,
        timeout=WINDOWS_QUERY_TIMEOUT_SECONDS,
    ).stdout.strip()
    return ".".join(value.split(".")[:3])


def _verify_synthetic_watcher_ticket(
    profile: Path, update_session: str, source_version: str,
    target_version: str, *, hold_pending_source: bool = False,
) -> WatcherContinuation:
    """Prove exact nonce admission with a deterministic retry-wait FIFO fixture.

    Args:
        profile (Path): Isolated APPDATA product profile for the fixture.
        update_session (str): Exact updater ticket nonce under qualification.
        source_version (str): Installed version before the update handoff.
        target_version (str): Version authorized by that same updater ticket.
        hold_pending_source (bool): Seed one durable retry-wait head so a
            later installed switch can observe a second real source behind it.
    Returns:
        WatcherContinuation: Exact accepted profile-bound restart record.
    Raises:
        RuntimeError: The profile owner refuses or changes the synthetic ticket.
    """
    flight_root = profile / "flights"
    flight_root.mkdir(parents=True, exist_ok=True)
    session_id = uuid4().hex
    session_directory = flight_root / session_id
    session_directory.mkdir()
    watch_folder = profile.parent / "synthetic-watch-folder"
    watch_folder.mkdir(exist_ok=True)
    binding = connection_binding(SYNTHETIC_WATCHER_URL, SYNTHETIC_WATCHER_KEY)
    config_path = profile / "config.json"
    try:
        config = json.loads(config_path.read_text(encoding="utf-8"))
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        config = {}
    if not isinstance(config, dict):
        config = {}
    config.update({
        "gallery_url": SYNTHETIC_WATCHER_URL,
        "api_key": SYNTHETIC_WATCHER_KEY,
        "watched_folder": str(watch_folder),
        "close_to_tray": False,
        "start_with_windows": False,
        "watch_recursive": False,
    })
    if hold_pending_source:
        config.update({
            'scan_interval_seconds': SWITCH_WATCH_INTERVAL_SECONDS,
            'stable_seconds': SWITCH_SOURCE_STABILITY_SECONDS,
        })
    config_path.write_text(json.dumps(config), encoding="utf-8")
    SessionPlan(
        directory=session_directory, binding=binding, languages=(),
        reports_enabled=False,
    ).save()
    drain = session_directory / "watch-drain.json"
    if hold_pending_source:
        blocked_source = watch_folder / SWITCH_RETRY_HEAD_NAME
        blocked_contents = base64.b64decode(SYNTHETIC_CACHE_PNG_BASE64, validate=True)
        with blocked_source.open('xb') as output:
            output.write(blocked_contents)
        queue = WatchQueueStore(drain, binding, watch_folder, False)
        queue.initialize([])
        if queue.reconcile([observe_watch_source(blocked_source, time.time())]) != 1:
            raise RuntimeError('The durable retry-wait FIFO head was not admitted.')
        queue.restore()
        head = queue.head()
        if head is None or head.source.path != str(blocked_source):
            raise RuntimeError('The durable retry-wait FIFO head lost its exact source.')
        head = queue.update(
            head, state='retry_wait', phase='source', attempts=0,
            next_retry_at=time.time() + SWITCH_RETRY_DELAY_SECONDS,
            reason='Qualification source retry is deferred for qualification.',
        )
        if (head.state != 'retry_wait' or head.phase != 'source'
                or head.attempts != 0
                or head.next_retry_at
                <= time.time() + SWITCH_RETRY_MINIMUM_REMAINING_SECONDS
                or head.inventory_ready
                or hashlib.sha256(blocked_source.read_bytes()).hexdigest()
                != hashlib.sha256(blocked_contents).hexdigest()):
            raise RuntimeError('The durable retry-wait FIFO head failed readback.')
    else:
        drain.write_text(json.dumps({
            "schema_version": 1,
            "folder": str(watch_folder),
            "recursive": False,
            "initial": [],
            "pending": [],
            "initial_captured": True,
            "frozen": False,
            "cutoff": 0,
            "checkpoint": time.time(),
            "error": "",
        }), encoding="utf-8")
    recorder = FlightRecorder(
        flight_root, SYNTHETIC_WATCHER_URL, "", lambda _level, _message: None,
        _session_id=session_id,
    )
    recorder._checkpoint()
    restored_recorder = FlightRecorder.from_session(
        session_directory, SYNTHETIC_WATCHER_URL, "",
        lambda _level, _message: None,
    )
    if (restored_recorder.session_id != session_id
            or restored_recorder._sample_count != 0
            or restored_recorder.started_utc != recorder.started_utc):
        raise RuntimeError("Synthetic flight recorder failed exact resume validation.")
    lease = WorkspaceLease(flight_root)
    try:
        if hold_pending_source:
            previous_profile = WatcherProfile.load(flight_root)
            if (previous_profile is None or previous_profile.pending
                    or previous_profile.binding != binding):
                raise RuntimeError('The synthetic Watcher profile cannot hold a switch source.')
            preferences = dict(previous_profile.preferences)
            preferences['scan_interval_seconds'] = SWITCH_WATCH_INTERVAL_SECONDS
            preferences['stable_seconds'] = SWITCH_SOURCE_STABILITY_SECONDS
            WatcherProfile(False, binding, preferences).save(lease)
        record = WatcherContinuation(
            session_id=session_id, binding=binding,
            profile_hash=WatcherContinuation.profile_digest(flight_root),
            source_version=source_version, target_version=target_version,
            update_session=update_session, mode="legacy", contextual=False,
            created=time.time(), phase="prepared",
        )
        record.save(lease)
        lease.release()
        accepted = inspect_gui_profile_work(
            profile, legacy_import_binding=None, capture_binding=binding,
            capture_scope="", restart_version=target_version,
            installer_restart=(update_session, target_version),
        )
        if accepted.blocked or accepted.restart != record:
            raise RuntimeError("The exact synthetic Watcher ticket was not admitted.")
        payload = drain.read_text(encoding="utf-8")
        if "credential" in payload.lower() or "api_key" in payload.lower():
            raise RuntimeError("Synthetic Watcher files contain credential material.")
        return accepted.restart
    finally:
        lease.release()


def _wait_for_watcher_consumption(
    profile: Path, session_id: str, target: str, executable: Path,
) -> None:
    """Wait until the relaunched app durably consumes this exact checkpoint.

    Args:
        profile (Path): Isolated APPDATA product profile used by the app.
        session_id (str): Exact updater nonce from the helper's ticket.
        target (str): Exact target version bound by the helper ticket.
        executable (Path): Exact installed image whose restart is observed.
    Returns:
        None: The application wrote the matching continuation as consumed.
    Raises:
        RuntimeError: The app does not consume this nonce and version pair.
    """
    marker = profile / "flights" / "watcher-continuation.json"
    deadline = time.monotonic() + UPDATE_WAIT_SECONDS
    while time.monotonic() < deadline:
        try:
            row = json.loads(marker.read_text(encoding="utf-8"))
        except (FileNotFoundError, json.JSONDecodeError, OSError):
            row = {}
        if (row.get("update_session") == session_id
                and row.get("target_version") == target
                and row.get("phase") == "consumed"):
            return
        time.sleep(PROCESS_POLL_SECONDS)
    marker_phase = "missing"
    try:
        marker_row = json.loads(marker.read_text(encoding="utf-8"))
        if isinstance(marker_row, dict):
            candidate = marker_row.get("phase")
            if isinstance(candidate, str) and re.fullmatch(r"[a-z_]{1,24}", candidate):
                marker_phase = candidate
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        pass
    resume_diagnostics: list[str] = []
    log_path = profile / "watcher.log"
    try:
        lines = log_path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        lines = []
    for line in lines[-256:]:
        match = re.search(
            r"\[watcher-resume\] stage=([a-z_]{1,32}); reason=([a-z_]{1,48})",
            line,
        )
        if match:
            resume_diagnostics.append(
                "resume=" + match.group(1) + "/" + match.group(2),
            )
    lifecycle: list[str] = []
    for line in lines[-256:]:
        match = re.search(
            r"\[watcher-process\] phase=([a-z_]{1,32})"
            r"(?:; reason=([a-z_]{1,32}))?",
            line,
        )
        if match:
            lifecycle.append(
                match.group(1) + ("/" + match.group(2) if match.group(2) else ""),
            )
    process_state = "running" if _restart_pids(executable) else "exited"
    latest_resume = resume_diagnostics[-1] if resume_diagnostics else "resume=unavailable"
    latest_lifecycle = lifecycle[-1] if lifecycle else "lifecycle=unavailable"
    raise RuntimeError(
        "Watcher continuation timeout: marker=" + marker_phase
        + "; process=" + process_state + "; " + latest_resume
        + "; " + latest_lifecycle + ".",
    )


def _find_after_update_gui_pid(
    executable: Path, session_id: str, backend: GuiBackend,
) -> int:
    """Find the visible installed GUI owning this exact updater nonce.

    Args:
        executable (Path): Exact launcher installed by the candidate setup.
        session_id (str): One-use updater nonce passed on the relaunched command.
        backend (GuiBackend): Expected visible native frontend.
    Returns:
        int: PID owning the nonce-matched visible main window.
    Raises:
        RuntimeError: No unique process satisfies executable, nonce and backend.
        subprocess.SubprocessError: Windows process identity could not be read.
    """
    script = (
        "$rows = @(Get-CimInstance Win32_Process | "
        + "Where-Object { $_.Name -ieq 'PHPGalleryUploader.exe' } | "
        + "Select-Object ProcessId,ExecutablePath,CommandLine); "
        + "ConvertTo-Json -InputObject $rows -Compress"
    )
    output = subprocess.run(
        ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script],
        check=True, capture_output=True, text=True,
        timeout=WINDOWS_QUERY_TIMEOUT_SECONDS,
    ).stdout.strip()
    rows = json.loads(output) if output else []
    if isinstance(rows, dict):
        rows = [rows]
    if not isinstance(rows, list):
        raise RuntimeError("Installed GUI process identity response is malformed.")
    installed_pids = set(_restart_pids(executable))
    matching: list[int] = []
    for row in rows:
        if not isinstance(row, dict):
            continue
        image, command, process_id = (
            row.get("ExecutablePath"), row.get("CommandLine"),
            row.get("ProcessId"),
        )
        if (not isinstance(image, str) or not isinstance(command, str)
                or type(process_id) is not int or process_id not in installed_pids
                or not executable.resolve(strict=True).samefile(Path(image))):
            continue
        arguments = _windows_command_arguments(command)
        if not any(
            arguments[index] == "--after-update"
            and arguments[index + 1] == session_id
            for index in range(len(arguments) - 1)
        ):
            continue
        observed_backend: GuiBackend = (
            "pyside6" if GUI_CHILD_OPTION in arguments else "tkinter"
        )
        if observed_backend == backend and installed_window(process_id) is not None:
            matching.append(process_id)
    if len(matching) != 1:
        raise RuntimeError("Updater nonce did not identify one visible installed GUI.")
    return matching[0]


def _create_and_wait_for_switch_source(
    profile: Path, session_id: str,
) -> tuple[
    Path, str, tuple[str, int, str, str, float, str],
    tuple[str, int, str, str, str],
]:
    """Admit source B behind a durable retry-wait head A.

    Args:
        profile (Path): Product profile owning the resumed installed Watcher.
        session_id (str): Exact capture session to observe in its queue.
    Returns:
        tuple[Path, str, tuple[str, int, str, str, float, str], tuple[str, int, str, str, str]]: Source B and digest.
            The last two entries identify the exact items, sequences,
            signatures, generations and byte digests of sources A and B.
    Raises:
        RuntimeError: The ordered retry-wait head or second item changes.
    """
    config_path = profile / 'config.json'
    try:
        config = json.loads(config_path.read_text(encoding='utf-8'))
    except (OSError, json.JSONDecodeError) as error:
        raise RuntimeError('The installed synthetic Watcher settings are unavailable.') from error
    folder_value = config.get('watched_folder') if isinstance(config, dict) else None
    if not isinstance(folder_value, str) or not Path(folder_value).is_absolute():
        raise RuntimeError('The installed synthetic Watcher folder is not absolute.')
    folder = Path(folder_value)
    expected_folder = profile.parent / 'synthetic-watch-folder'
    if (folder != expected_folder or folder.is_symlink()
            or not folder.is_dir() or folder.resolve(strict=True) != expected_folder.resolve(strict=True)):
        raise RuntimeError('The installed synthetic Watcher folder is not runner-owned.')
    blocked_source = folder / SWITCH_RETRY_HEAD_NAME
    source = folder / SWITCH_PENDING_SOURCE_NAME
    if not blocked_source.is_file() or source.exists() or source.is_symlink():
        raise RuntimeError('The synthetic switch source paths are not in their expected state.')
    contents = base64.b64decode(SYNTHETIC_CACHE_PNG_BASE64, validate=True)
    try:
        with source.open('xb') as output:
            output.write(contents)
    except OSError as error:
        raise RuntimeError('The synthetic switch source could not be created.') from error
    expected_digest = hashlib.sha256(contents).hexdigest()
    blocked_digest = hashlib.sha256(blocked_source.read_bytes()).hexdigest()
    journal = profile / 'flights' / session_id / 'watch-drain.json'
    deadline = time.monotonic() + SWITCH_SOURCE_WAIT_SECONDS
    while time.monotonic() < deadline:
        try:
            row = WatchQueueStore.read_journal(journal)
            queue = WatchQueueStore(journal, row['binding'], folder, False)
            queue.restore(read_only=True)
            items = queue.items()
        except (FileNotFoundError, OSError, ValueError, KeyError, TypeError):
            time.sleep(PROCESS_POLL_SECONDS)
            continue
        if (row.get('pending') == [str(blocked_source), str(source)]
                and len(items) == 2):
            head, pending = items
            blocked_witness = (
                head.item_id, head.sequence, head.source.signature,
                head.source.identity, head.next_retry_at, blocked_digest,
            )
            pending_witness = (
                pending.item_id, pending.sequence, pending.source.signature,
                pending.source.identity, expected_digest,
            )
            if (head.source.path != str(blocked_source)
                    or head.state != 'retry_wait' or head.phase != 'source'
                    or head.reason != 'Qualification source retry is deferred for qualification.'
                    or head.attempts != 0 or head.sha256 or head.inventory_ready
                    or head.next_retry_at
                    <= time.time() + SWITCH_RETRY_MINIMUM_REMAINING_SECONDS
                    or pending.source.path != str(source)
                    or pending.state != 'observed' or pending.phase != 'discovery'
                    or pending.attempts != 0 or pending.sha256
                    or pending.inventory_ready
                    or row.get('binding') != connection_binding(
                        SYNTHETIC_WATCHER_URL, SYNTHETIC_WATCHER_KEY,
                    )
                    or row.get('scope', '') != ''
                    or row.get('session_id') != session_id
                    or row.get('frozen') is not False or row.get('cutoff') != 0
                    or row.get('error') != '' or row.get('retained_stop') is not False
                    or row.get('recovery_intent') is not None
                    or row.get('approved_upload') is not None):
                raise RuntimeError(
                    'The installed Watcher changed the retry-wait head or second FIFO item.'
                )
            if (blocked_source.read_bytes() != base64.b64decode(
                    SYNTHETIC_CACHE_PNG_BASE64, validate=True)
                    or source.read_bytes() != contents):
                raise RuntimeError('The exact synthetic FIFO source bytes changed.')
            return source, expected_digest, blocked_witness, pending_witness
        time.sleep(PROCESS_POLL_SECONDS)
    raise RuntimeError('The installed Watcher did not append source B behind retry-wait head A.')

def _switch_resumed_backend(
    executable: Path, profile: Path, updater_nonce: str, target_version: str,
    previous_backend: GuiBackend, next_backend: GuiBackend,
    continuation: WatcherContinuation,
) -> tuple[dict[str, str], int]:
    """Switch the live resumed Watcher through Settings and verify its mirror.

    Args:
        executable (Path): Exact installed public application launcher.
        profile (Path): Isolated profile that owns the active Watcher session.
        updater_nonce (str): Exact updater ticket already consumed by the app.
        target_version (str): Candidate version that must remain on both markers.
        previous_backend (GuiBackend): Current installed native frontend.
        next_backend (GuiBackend): Explicitly selected next native frontend.
        continuation (WatcherContinuation): Original synthetic flight identity.
    Returns:
    tuple[dict[str, str], int]: Sanitized backend, ordinary-marker and
        retry-wait FIFO evidence plus the exact new visible GUI PID.
    Raises:
        RuntimeError: The Settings switch, ordinary mirror or same-session check fails.
    """
    if previous_backend == next_backend:
        raise ValueError("A backend journey must select the other native frontend.")
    app_profile = Path(profile)
    flight_root = app_profile / "flights"
    current_path = flight_root / "watcher-continuation.json"
    current = json.loads(current_path.read_text(encoding="utf-8"))
    if (not isinstance(current, dict)
            or current.get("update_session") != updater_nonce
            or current.get("target_version") != target_version
            or current.get("phase") != "consumed"
            or current.get("session_id") != continuation.session_id):
        raise RuntimeError("Backend switch did not begin from the consumed updater capture.")
    gui_pid = _find_after_update_gui_pid(executable, updater_nonce, previous_backend)
    log = app_profile / "watcher.log"
    if f"Desktop backend ready: {previous_backend}; pid={gui_pid}" not in log.read_text(
            encoding="utf-8", errors="replace"):
        raise RuntimeError("Nonce-matched installed app lacks its backend-ready record.")
    held_source, held_digest, blocked_witness, pending_witness = (
        _create_and_wait_for_switch_source(app_profile, continuation.session_id)
    )
    return _complete_resumed_backend_switch(
        executable, app_profile, updater_nonce, target_version,
        previous_backend, next_backend, continuation, current_path,
        flight_root, gui_pid, log, held_source, held_digest,
        blocked_witness, pending_witness,
    )


def _complete_resumed_backend_switch(
    executable: Path, app_profile: Path, updater_nonce: str,
    target_version: str, previous_backend: GuiBackend,
    next_backend: GuiBackend, continuation: WatcherContinuation,
    current_path: Path, flight_root: Path, gui_pid: int, log: Path,
    held_source: Path, held_digest: str,
    blocked_witness: tuple[str, int, str, str, float, str],
    pending_witness: tuple[str, int, str, str, str],
) -> tuple[dict[str, str], int]:
    """Finish one native switch while retry-wait head A fences source B.

    Args:
        executable (Path): Exact installed public application launcher.
        app_profile (Path): Isolated product profile owning the session.
        updater_nonce (str): Exact updater ticket consumed before switching.
        target_version (str): Version authorized by the update ticket.
        previous_backend (GuiBackend): Frontend currently running.
        next_backend (GuiBackend): Explicitly selected target frontend.
        continuation (WatcherContinuation): Exact resumed Watcher session.
        current_path (Path): Primary continuation marker in the flight workspace.
        flight_root (Path): Isolated workspace containing mirrored markers.
        gui_pid (int): Exact currently visible installed GUI process.
        log (Path): Product log used to identify normal restart readiness.
        held_source (Path): Exact synthetic source held before dispatch.
        held_digest (str): Source content digest before and after the switch.
        blocked_witness (tuple[str, int, str, str, float, str]): Exact head A
            identity, sequence, signature, generation, retry epoch and digest.
        pending_witness (tuple[str, int, str, str, str]): Exact second item B
            identity, sequence, signature, generation and content digest.
    Returns:
        tuple[dict[str, str], int]: Sanitized evidence and exact new GUI process.
    Raises:
        RuntimeError: The switch changes session identity or source disposition.
    """
    choose_installed_backend(gui_pid, app_profile, next_backend, log)
    next_pid = wait_installed_backend(log, next_backend, gui_pid)
    if load_gui_backend(app_profile) != next_backend:
        raise RuntimeError("Settings backend selection did not persist for the next launch.")
    deadline = time.monotonic() + UPDATE_WAIT_SECONDS
    ordinary_nonce = ""
    while time.monotonic() < deadline:
        try:
            primary_row = json.loads(current_path.read_text(encoding="utf-8"))
            mirror_row = json.loads(
                (flight_root / "watcher-restart.json").read_text(encoding="utf-8"),
            )
            primary = WatcherContinuation.from_payload(primary_row, flight_root)
            mirror = WatcherContinuation.from_payload(mirror_row, flight_root)
        except (FileNotFoundError, json.JSONDecodeError, OSError, ValueError):
            time.sleep(PROCESS_POLL_SECONDS)
            continue
        if (primary == mirror and primary.phase == "consumed"
                and primary.session_id == continuation.session_id
                and primary.binding == continuation.binding
                and primary.profile_hash == continuation.profile_hash
                and primary.mode == continuation.mode
                and primary.contextual == continuation.contextual
                and primary.approved_scope == continuation.approved_scope
                and primary.source_version == target_version
                and primary.target_version == target_version
                and primary.update_session != updater_nonce):
            ordinary_nonce = primary.update_session
            break
        time.sleep(PROCESS_POLL_SECONDS)
    if not ordinary_nonce:
        raise RuntimeError("The switched GUI did not consume one exact mirrored ordinary nonce.")
    session_directory = flight_root / continuation.session_id
    plan = SessionPlan.load(session_directory)
    telemetry = json.loads((session_directory / "session.json").read_text(encoding="utf-8"))
    journal = session_directory / "watch-drain.json"
    queue = WatchQueueStore.read_journal(journal)
    queue_owner = WatchQueueStore(
        journal, continuation.binding,
        Path(queue['folder']), False, scope=continuation.approved_scope,
    )
    queue_owner.restore(read_only=True)
    items = queue_owner.items()
    blocked_source = held_source.with_name(SWITCH_RETRY_HEAD_NAME)
    if (plan.binding != continuation.binding or plan.completed or plan.abandoned
            or telemetry.get("session_id") != continuation.session_id
            or telemetry.get("sample_count") != 0
            or not isinstance(telemetry.get("started_utc"), str)
            or queue.get("pending") != [str(blocked_source), str(held_source)]
            or len(items) != 2
            or queue.get("binding") != continuation.binding
            or queue.get("scope", "") != continuation.approved_scope
            or queue.get("session_id") != continuation.session_id
            or queue.get("frozen") is not False or queue.get("cutoff") != 0
            or queue.get("error") != ""
            or queue.get("retained_stop") is not False
            or queue.get("recovery_intent") is not None
            or queue.get("approved_upload") is not None):
        raise RuntimeError("Backend switch changed the active retry-wait-head session.")
    blocked, pending = items
    blocked_id, blocked_sequence, blocked_signature, blocked_generation, blocked_epoch, blocked_digest = blocked_witness
    pending_id, pending_sequence, pending_signature, pending_generation, pending_digest = pending_witness
    if (blocked.source.path != str(blocked_source)
            or blocked.item_id != blocked_id or blocked.sequence != blocked_sequence
            or blocked.source.signature != blocked_signature
            or blocked.source.identity != blocked_generation
            or blocked.state != "retry_wait" or blocked.phase != "source"
            or blocked.reason != 'Qualification source retry is deferred for qualification.'
            or blocked.attempts != 0 or blocked.sha256 or blocked.inventory_ready
            or blocked.next_retry_at != blocked_epoch
            or blocked.next_retry_at
            <= time.time() + SWITCH_RETRY_MINIMUM_REMAINING_SECONDS
            or pending.source.path != str(held_source)
            or pending.item_id != pending_id or pending.sequence != pending_sequence
            or pending.source.signature != pending_signature
            or pending.source.identity != pending_generation
            or pending.state != "observed" or pending.phase != "discovery"
            or pending.attempts != 0 or pending.sha256 or pending.inventory_ready
            or hashlib.sha256(blocked_source.read_bytes()).hexdigest() != blocked_digest
            or hashlib.sha256(held_source.read_bytes()).hexdigest() != pending_digest
            or pending_digest != held_digest):
        raise RuntimeError('Backend switch changed or advanced either exact FIFO item.')
    if (next_pid == gui_pid or next_pid not in _restart_pids(executable)
            or installed_window(next_pid) is None):
        raise RuntimeError("Backend switch did not leave the new GUI process visible.")
    return ({
            "source_backend": previous_backend,
            "target_backend": next_backend,
            "ordinary_restart_pair": "identical-and-consumed",
            "same_flight_session": "verified",
            "queue": "retry-wait-head-and-second-item-unchanged",
            "source_bytes": "both-exact-sha256-and-signature-preserved",
            "upload_dispatch": "none",
            "old_gui_exit": "normal",
            "new_gui": "ready-and-visible",
        }, next_pid)


def _write_expired_watcher_ticket(profile: Path) -> None:
    """Require the production owner to reject an expired synthetic checkpoint.

    Args:
        profile (Path): Isolated product profile containing its flight workspace.
    Returns:
        None: The production continuation reader reports its fixed expired reason.
    Raises:
        RuntimeError: Expired continuation evidence was admitted.
    """
    flight_root = profile / "flights"
    flight_root.mkdir(parents=True, exist_ok=True)
    expired = WatcherContinuation(
        session_id=uuid4().hex, binding="a" * 64,
        profile_hash=WatcherContinuation.profile_digest(flight_root),
        source_version=SYNTHETIC_WATCHER_SOURCE_VERSION,
        target_version=SYNTHETIC_WATCHER_TARGET_VERSION,
        update_session=uuid4().hex, mode="legacy", contextual=False,
        created=(time.time() - WATCHER_CONTINUATION_LIFETIME_SECONDS
                 - STALE_CHECKPOINT_AGE_MARGIN_SECONDS),
        phase="prepared",
    )
    lease = WorkspaceLease(flight_root)
    try:
        # Save refuses expired records by design, so write the exact owned
        # schema fixture and ask the production reader to reject it.
        (flight_root / "watcher-continuation.json").write_text(
            json.dumps(expired.payload()), encoding="utf-8",
        )
        try:
            WatcherContinuation.load(lease)
        except WatcherContinuationError as error:
            if error.reason == "expired":
                return
            raise RuntimeError("Stale Watcher ticket failed for the wrong reason.")
        raise RuntimeError("Expired Watcher continuation ticket was admitted.")
    finally:
        lease.release()


def _run_rejected_helper_ticket(
    setup: Path, target_version: str, install: Path,
    environment: dict[str, str], work: Path, failure: str,
) -> None:
    """Exercise malformed, foreign and digest-failed tickets in the real helper.

    Args:
        setup (Path): Valid candidate setup used only for its isolated ticket.
        target_version (str): Version claimed by the ticket under examination.
        install (Path): Registered product whose version must remain unchanged.
        environment (dict[str, str]): Isolated profile environment.
        work (Path): Owned directory for one rejected ticket.
        failure (str): One of invalid, foreign or digest-mismatch.
    Returns:
        None: The installed helper rejects the ticket without changing the app.
    Raises:
        RuntimeError: A rejected handoff proceeds or changes installed bytes.
    """
    executable = install / "PHPGalleryUploader.exe"
    before_version = _installed_version(executable)
    session_id = uuid4().hex
    session = Path(environment["LOCALAPPDATA"]) / "PHPGalleryUploader" / "updates" / session_id
    session.mkdir(parents=True, exist_ok=False)
    staged = session / "setup.exe"
    shutil.copyfile(setup, staged)
    setup_digest = hashlib.sha256(staged.read_bytes()).hexdigest()
    ticket = {
        "session_id": session_id, "target_version": target_version,
        "expected_sha256": setup_digest, "expected_size": staged.stat().st_size,
        "installer_path": str(staged.resolve()),
        "installed_executable": str(executable.resolve()),
        "schema": 1, "resume_capture": False,
    }
    if failure == "invalid":
        ticket["schema"] = INVALID_TICKET_SCHEMA_VERSION
    elif failure == "foreign":
        foreign = work / "foreign" / executable.name
        foreign.parent.mkdir(parents=True)
        ticket["installed_executable"] = str(foreign.resolve())
    elif failure == "digest-mismatch":
        ticket["expected_sha256"] = "0" * 64
    else:
        raise ValueError("Unsupported rejected-helper fixture.")
    ticket_path = session / "ticket.json"
    ticket_path.write_text(json.dumps(ticket), encoding="utf-8")
    helper = session / "helper.exe"
    stage_update_helper_runtime(
        executable, helper,
        allow_legacy_onefile=(before_version == "1.7.1"),
    )
    helper_environment = {**environment, "PYINSTALLER_RESET_ENVIRONMENT": "1"}
    process = subprocess.Popen(
        [str(helper), "--self-update-helper", str(ticket_path)],
        cwd=session, env=helper_environment,
    )
    try:
        deadline = time.monotonic() + UPDATE_WAIT_SECONDS
        ready = session / "ready"
        if failure == "digest-mismatch":
            _wait_for_marker(ready, process)
            (session / "proceed").write_text("1", encoding="ascii")
            while time.monotonic() < deadline and not (session / "completed").is_file():
                if process.poll() is not None:
                    raise RuntimeError("Digest-rejected helper exited before completion.")
                time.sleep(PROCESS_POLL_SECONDS)
            if not (session / "completed").is_file():
                raise RuntimeError("Digest-rejected helper missed its failure deadline.")
        deadline = time.monotonic() + UPDATE_WAIT_SECONDS
        if not _dismiss_owned_helper(process, helper, ticket_path, deadline):
            raise RuntimeError("Ticket-owned rejected helper process tree did not exit.")
    finally:
        if process.poll() is None:
            process.kill()
            process.wait(timeout=INSTALLED_SMOKE_SECONDS)
    if process.returncode == 0:
        raise RuntimeError("The installed helper accepted a rejected ticket.")
    if _installed_version(executable) != before_version:
        raise RuntimeError("A rejected helper ticket changed the installed version.")
    print(f"Real installed helper refused {failure} ticket without mutation.", flush=True)


def _verify_uninstall_reinstall(
    setup: Path, install: Path, environment: dict[str, str], work: Path,
) -> None:
    """Uninstall and reinstall the common setup while retaining user state.

    Args:
        setup (Path): Candidate common setup used for the reinstall phase.
        install (Path): Exact isolated installation to remove and restore.
        environment (dict[str, str]): Isolated profile environment.
        work (Path): Owned log directory for uninstall and reinstall.
    Returns:
        None: Product binaries return with profile settings preserved.
    Raises:
        RuntimeError: Uninstall leaves binaries behind or reinstall loses state.
    """
    profile = Path(environment["APPDATA"]) / "PHPGalleryUploader"
    preserved = {
        path: path.read_bytes()
        for path in profile.rglob("*") if path.is_file()
    }
    uninstaller = install / "unins000.exe"
    if not uninstaller.is_file():
        raise RuntimeError("Common setup did not install its registered uninstaller.")
    uninstall_work = work / "uninstall"
    uninstall_work.mkdir(parents=True)
    subprocess.run(
        [str(uninstaller), "/VERYSILENT", "/SUPPRESSMSGBOXES", "/NORESTART",
         "/LOG=" + str(uninstall_work / "uninstall.log")],
        cwd=uninstall_work, env=environment, check=True,
        timeout=INSTALLED_SMOKE_SECONDS,
    )
    if ((install / "PHPGalleryUploader.exe").exists()
            or (install / "_internal").exists()
            or (install / "licenses").exists()):
        raise RuntimeError("Uninstall left application runtime files behind.")
    for path, contents in preserved.items():
        if not path.is_file() or path.read_bytes() != contents:
            raise RuntimeError("Uninstall changed retained profile state.")
    _run_setup(setup, install, work / "reinstall")
    if not (install / "PHPGalleryUploader.exe").is_file():
        raise RuntimeError("Reinstall did not restore the stable launcher.")
    if not (install / "_internal").is_dir():
        raise RuntimeError("Reinstall did not restore the onedir runtime.")
    for path, contents in preserved.items():
        if not path.is_file() or path.read_bytes() != contents:
            raise RuntimeError("Reinstall changed retained profile state.")
    print("Uninstall/reinstall preserved settings and restored the runtime.", flush=True)


def _hold_installed_launcher_read_only(executable: Path) -> int:
    """Open the installed launcher without granting installer replacement rights.

    Args:
        executable (Path): Exact registered launcher the candidate setup replaces.
    Returns:
        int: Native file handle retained until the failed setup exits.
    Raises:
        OSError: Windows cannot obtain the requested read-only sharing lock.
    """
    kernel = ctypes.WinDLL("kernel32.dll", use_last_error=True)
    create_file = kernel.CreateFileW
    create_file.argtypes = [
        wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD, ctypes.c_void_p,
        wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE,
    ]
    create_file.restype = ctypes.c_void_p
    handle = create_file(
        str(executable), 0x80000000, 0x00000001, None, 3, 0x00000080, None,
    )
    if handle is None or handle == ctypes.c_void_p(-1).value:
        error = ctypes.get_last_error()
        raise OSError(error, "Cannot hold installed launcher for setup refusal.")
    return handle


def _release_installed_launcher(handle: int) -> None:
    """Release the exact native handle acquired for the failed setup fixture.

    Args:
        handle (int): Native handle returned by _hold_installed_launcher_read_only.
    Returns:
        None: Windows may replace or delete the launcher again.
    Raises:
        OSError: Windows failed to release the owned test handle.
    """
    kernel = ctypes.WinDLL("kernel32.dll", use_last_error=True)
    kernel.CloseHandle.argtypes = [ctypes.c_void_p]
    kernel.CloseHandle.restype = wintypes.BOOL
    if not kernel.CloseHandle(ctypes.c_void_p(handle)):
        raise OSError(ctypes.get_last_error(), "Cannot release the test launcher handle.")


def _runtime_fingerprint(install: Path) -> dict[str, tuple[int, str]]:
    """Hash the executable and canonical onedir runtime trees.

    Args:
        install (Path): Isolated root containing executable and runtime trees.
    Returns:
        dict[str, tuple[int, str]]: Relative path mapped to size and SHA-256.
    Raises:
        OSError: A runtime file cannot be read completely.
    """
    fingerprint: dict[str, tuple[int, str]] = {}
    executable = install / "PHPGalleryUploader.exe"
    candidates = [executable]
    for runtime_name in ("_internal", "licenses"):
        runtime = install / runtime_name
        if runtime.exists() or runtime.is_symlink():
            candidates.append(runtime)
    pending = list(reversed(candidates))
    entries = 0
    total_bytes = 0
    while pending:
        path = pending.pop()
        info = path.lstat()
        entries += 1
        if entries > UPDATE_RUNTIME_PROOF_MAX_ENTRIES:
            raise RuntimeError("Installed runtime exceeds its entry limit.")
        if (path.is_symlink()
                or getattr(info, "st_file_attributes", 0)
                & FILE_ATTRIBUTE_REPARSE_POINT):
            raise RuntimeError("Installed runtime contains a redirected path.")
        if path.is_dir():
            children = sorted(path.iterdir(), reverse=True)
            if entries + len(pending) + len(children) > UPDATE_RUNTIME_PROOF_MAX_ENTRIES:
                raise RuntimeError("Installed runtime exceeds its entry limit.")
            pending.extend(children)
            continue
        if not path.is_file() or info.st_nlink != 1:
            raise RuntimeError("Installed runtime contains a special file.")
        digest = hashlib.sha256()
        size = 0
        with path.open("rb") as source:
            for chunk in iter(
                lambda: source.read(UPDATE_RUNTIME_PROOF_CHUNK_BYTES), b"",
            ):
                digest.update(chunk)
                size += len(chunk)
                total_bytes += len(chunk)
                if total_bytes > UPDATE_RUNTIME_PROOF_MAX_BYTES:
                    raise RuntimeError("Installed runtime exceeds its byte limit.")
        fingerprint[str(path.relative_to(install))] = (size, digest.hexdigest())
    return fingerprint


def _verify_setup_serialization(setup: Path, install: Path, work: Path) -> None:
    """Require native setup refusal without mutation while its product mutex exists.

    Args:
        setup (Path): Exact common candidate setup under test.
        install (Path): Previously verified isolated registered installation.
        work (Path): Separate otherwise unused refusal evidence directory.
    Returns:
        None: Inno refused the competing setup and retained every runtime byte.
    Raises:
        RuntimeError: The competing setup mutated the installation or did not refuse.
        OSError: The fixture could not establish and release its own native lock.
    """
    fingerprint = _runtime_fingerprint(install)
    kernel = ctypes.WinDLL('kernel32.dll', use_last_error=True, winmode=LOAD_LIBRARY_SEARCH_SYSTEM32)
    kernel.CreateMutexW.argtypes = [ctypes.c_void_p, wintypes.BOOL, wintypes.LPCWSTR]
    kernel.CreateMutexW.restype = wintypes.HANDLE
    kernel.CloseHandle.argtypes = [wintypes.HANDLE]
    kernel.CloseHandle.restype = wintypes.BOOL
    handle = kernel.CreateMutexW(None, False, UPDATE_SETUP_MUTEX_NAME)
    if not handle:
        raise OSError(ctypes.get_last_error(), 'Could not create the setup mutex fixture.')
    try:
        try:
            _run_setup(setup, install, work)
        except subprocess.CalledProcessError:
            pass
        else:
            raise RuntimeError('The candidate installer did not refuse the existing setup mutex.')
    finally:
        if not kernel.CloseHandle(handle):
            raise OSError(ctypes.get_last_error(), 'Could not release the setup mutex fixture.')
    if _runtime_fingerprint(install) != fingerprint:
        raise RuntimeError('Competing setup changed the earlier runtime.')


def _start_installed_backend(
    executable: Path, environment: dict[str, str], backend: GuiBackend,
    *, backend_override: GuiBackend | None = None,
) -> tuple[subprocess.Popen, int]:
    """Start one real installed GUI backend and wait for its main-loop record.

    Args:
        executable (Path): One common installed application executable.
        environment (dict[str, str]): Isolated Windows profile environment.
        backend (GuiBackend): Expected native toolkit, tkinter or pyside6.
        backend_override (GuiBackend | None): Optional one-launch backend
            selection, which must not replace the saved profile preference.
    Returns:
        tuple[subprocess.Popen, int]: Launch process and GUI PID recorded by app.
    Raises:
        RuntimeError: The installed GUI does not reach its normal start record.
    """
    if backend not in GUI_BACKENDS or (
        backend_override is not None and backend_override not in GUI_BACKENDS
    ):
        raise ValueError("Installed backend fixture must be tkinter or pyside6.")
    profile = Path(environment["APPDATA"]) / "PHPGalleryUploader"
    config = profile / "config.json"
    settings = json.loads(config.read_text(encoding="utf-8"))
    settings["close_to_tray"] = False
    settings["start_with_windows"] = False
    config.write_text(json.dumps(settings), encoding="utf-8")
    process_environment = {
        **environment, "PYINSTALLER_RESET_ENVIRONMENT": "1",
    }
    command = [str(executable), "--config", str(config)]
    if backend_override is not None:
        command.extend(("--gui-backend", backend_override))
    qt_startup_directory: Path | None = None
    qt_startup_nonce: str | None = None
    if backend == "pyside6":
        qt_startup_nonce = uuid4().hex
        qt_startup_directory = (
            Path(environment["LOCALAPPDATA"])
            / "PHPGalleryUploader" / ("qt-startup-" + qt_startup_nonce)
        )
        qt_startup_directory.mkdir(parents=True, exist_ok=False)
        process_environment[GUI_STARTUP_DIRECTORY] = str(qt_startup_directory)
        command.extend((GUI_CHILD_OPTION, qt_startup_nonce))
    process = subprocess.Popen(
        command,
        cwd=executable.parent, env=process_environment,
    )
    log = profile / "watcher.log"
    deadline = time.monotonic() + UPDATE_WAIT_SECONDS
    while time.monotonic() < deadline:
        try:
            content = log.read_text(encoding="utf-8", errors="replace")
        except OSError:
            content = ""
        matches = re.findall(
            r"phase=start; reason=manual; pid=(\d+); version=", content,
        )
        if matches:
            gui_pid = int(matches[-1])
            running_ids = _restart_pids(executable)
            qt_ready = backend == "tkinter"
            if qt_startup_directory is not None and qt_startup_nonce is not None:
                try:
                    response = json.loads(
                        (qt_startup_directory / GUI_STARTUP_RESPONSE)
                        .read_text(encoding="utf-8"),
                    )
                except (FileNotFoundError, json.JSONDecodeError, OSError):
                    response = {}
                if (response.get("nonce") == qt_startup_nonce
                        and response.get("state") == "refused"):
                    raise RuntimeError(
                        "Installed Qt backend refused startup: "
                        + str(response.get("reason", "unknown")),
                    )
                qt_ready = (
                    response.get("nonce") == qt_startup_nonce
                    and response.get("state") == "ready"
                    and response.get("reason") is None
                )
            if gui_pid in running_ids and qt_ready and installed_window(gui_pid) is not None:
                if qt_startup_directory is not None:
                    (qt_startup_directory / GUI_STARTUP_RESPONSE).unlink(
                        missing_ok=True,
                    )
                    qt_startup_directory.rmdir()
                print(f"Installed {backend} GUI reached its main loop.", flush=True)
                return process, gui_pid
        if process.poll() not in (None, 0) and not _restart_pids(executable):
            raise RuntimeError(f"Installed {backend} GUI exited before readiness.")
        time.sleep(PROCESS_POLL_SECONDS)
    if process.poll() is None:
        process.kill()
        process.wait(timeout=INSTALLED_SMOKE_SECONDS)
    raise RuntimeError(f"Installed {backend} GUI missed the readiness deadline.")


def _close_active_backend_for_update(
    executable: Path, profile: Path, gui_pid: int,
) -> None:
    """Close the actual installed GUI normally after updater readiness.

    Args:
        executable (Path): Exact common installed executable.
        profile (Path): Isolated profile whose log records main-loop completion.
        gui_pid (int): Exact GUI process reported by the installed app.
    Returns:
        None: The GUI exited normally before its staged installer can run.
    Raises:
        RuntimeError: The exact GUI refused WM_CLOSE or remained active.
    """
    if gui_pid not in _restart_pids(executable):
        raise RuntimeError("Active GUI PID no longer matches the installed executable.")
    if not request_window_close(gui_pid):
        raise RuntimeError("Active installed GUI did not accept normal WM_CLOSE.")
    log = profile / "watcher.log"
    deadline = time.monotonic() + UPDATE_WAIT_SECONDS
    while time.monotonic() < deadline:
        try:
            content = log.read_text(encoding="utf-8", errors="replace")
        except OSError:
            content = ""
        if (f"phase=mainloop_returned; pid={gui_pid}" in content
                and not _restart_pids(executable)):
            return
        time.sleep(PROCESS_POLL_SECONDS)
    raise RuntimeError("The installed GUI did not complete orderly shutdown for update.")


def _observed_update_bytes(roots: tuple[Path, ...]) -> int | None:
    """Measure visible regular-file bytes in the isolated update directories.

    Args:
        roots (tuple[Path, ...]): Disjoint install, session and private temp roots.
    Returns:
        int | None: Sum of visible logical sizes, or unknown when the measuring
            budget is exceeded. This sampling never blocks the real update.
    """
    total = 0
    for root in roots:
        observed = 0
        for directory, children, files in os.walk(root, followlinks=False):
            observed += len(children) + len(files)
            if observed > UPDATE_USAGE_MAX_ENTRIES:
                return None
            for name in files:
                try:
                    item = (Path(directory) / name).stat(follow_symlinks=False)
                except (FileNotFoundError, PermissionError):
                    # Setup may remove, replace or temporarily lock a file.
                    continue
                if stat.S_ISREG(item.st_mode):
                    total += item.st_size
    return total


def _observed_setup_preflight(path: Path) -> str:
    """Return bounded setup preflight codes without copying any path or log line.

    Args:
        path (Path): Exact Inno log from this isolated helper ticket.
    Returns:
        str: Comma-separated known codes, or a fixed unavailable/not-logged status.
    """
    try:
        with path.open('rb') as stream:
            facts = stream.read(UPDATE_SETUP_LOG_MAX_BYTES).decode('utf-8', errors='replace')
    except OSError:
        return 'setup-log-unavailable'
    observed = []
    if 'WinApp installer verified an existing Windows path alias' in facts:
        observed.append('windows-path-alias-verified')
    for reason in (
        'redirected-runtime-path', 'occupied-runtime-slot', 'missing-install-registration',
        'install-location-mismatch', 'runtime-version-unverified', 'launcher-replacement-blocked',
    ):
        if f'WinApp preflight rejected: {reason}' in facts:
            observed.append(reason)
    if 'Uploader shutdown verification timed out' in facts:
        observed.append('uploader-still-running')
    if 'Uploader shutdown verification unavailable' in facts:
        observed.append('uploader-identity-unavailable')
    return ', '.join(observed) or 'no-preflight-error-recorded'


def _run_real_helper(
    setup: Path, target_version: str, install: Path,
    environment: dict[str, str], work: Path, *, resume_capture: bool = False,
    expect_setup_failure: bool = False,
    expect_setup_launch_failure: bool = False,
    active_backend: GuiBackend | None = None,
    switch_backend: GuiBackend | None = None,
    backend_switch_evidence: list[dict[str, str]] | None = None,
    staging_metrics: list[dict[str, int | str]] | None = None,
) -> tuple[str, int]:
    """Apply one setup through the installed frozen updater helper.

    Args:
        setup (Path): Candidate installer whose size and digest enter the ticket.
        target_version (str): Numeric version embedded in the setup resource.
        install (Path): Registered product installation being updated in place.
        environment (dict[str, str]): Isolated Windows profile environment.
        work (Path): Owned location for setup digest and helper diagnostics.
        resume_capture (bool): Bind the helper restart to its exact one-use nonce.
        expect_setup_failure (bool): Require authentic setup refusal and old runtime recovery.
        expect_setup_launch_failure (bool): Require ShellExecute to reject a digest-valid
            non-executable image before setup starts and recover the old runtime.
        active_backend (GuiBackend | None): Open this actual installed backend before handoff.
        switch_backend (GuiBackend | None): Settings destination after the resumed updater.
        backend_switch_evidence (list[dict[str, str]] | None): Sanitized completed
            cross-backend journey rows.
        staging_metrics (list[dict[str, int | str]] | None): Optional sanitized staging report.
    Returns:
        tuple[str, int]: Exact updater nonce and helper process exit code.
    Raises:
        RuntimeError: Ticket, helper, install result or installed version is wrong.
        ValueError: Both definitive setup-failure boundaries were requested.
    """
    if not re.fullmatch(r"\d+\.\d+\.\d+", target_version):
        raise RuntimeError("Update qualification version must be numeric major.minor.patch.")
    if expect_setup_failure and expect_setup_launch_failure:
        raise ValueError("Setup failure fixtures must select one observed boundary.")
    work.mkdir(parents=True, exist_ok=True)
    executable = install / "PHPGalleryUploader.exe"
    if not executable.is_file():
        raise RuntimeError("Registered application executable is absent before update.")
    before_version = _installed_version(executable)
    if active_backend is not None:
        active_app_process, active_gui_pid = _start_installed_backend(
            executable, environment, active_backend,
        )
        try:
            _close_active_backend_for_update(
                executable, Path(environment["APPDATA"]) / "PHPGalleryUploader",
                active_gui_pid,
            )
            active_app_process.wait(timeout=INSTALLED_SMOKE_SECONDS)
        except BaseException:
            if active_app_process.poll() is None:
                active_app_process.kill()
                active_app_process.wait(timeout=INSTALLED_SMOKE_SECONDS)
            raise
        if active_backend == "pyside6":
            # The supervised Qt child loads config read-only until readiness.
            # Run the authentic earlier Tk launcher once so its normal schema
            # migration precedes the profile snapshot, without changing the
            # saved frontend selection.
            app_profile = Path(environment["APPDATA"]) / "PHPGalleryUploader"
            saved_backend = load_gui_backend(app_profile)
            migration_process, migration_pid = _start_installed_backend(
                executable, environment, "tkinter", backend_override="tkinter",
            )
            try:
                _close_active_backend_for_update(
                    executable, app_profile, migration_pid,
                )
                migration_process.wait(timeout=INSTALLED_SMOKE_SECONDS)
            except BaseException:
                if migration_process.poll() is None:
                    migration_process.kill()
                    migration_process.wait(timeout=INSTALLED_SMOKE_SECONDS)
                raise
            if load_gui_backend(app_profile) != saved_backend:
                raise RuntimeError("One-launch Tk migration changed the saved backend.")
    session_id = uuid4().hex
    session = Path(environment["LOCALAPPDATA"]) / "PHPGalleryUploader" / "updates" / session_id
    session.mkdir(parents=True)
    staged_setup = session / "setup.exe"
    shutil.copyfile(setup, staged_setup)
    digest = hashlib.sha256(staged_setup.read_bytes()).hexdigest()
    helper = session / "helper.exe"
    staged_runtime = stage_update_helper_runtime(
        executable, helper,
        allow_legacy_onefile=(before_version == "1.7.1"),
    )
    staging: dict[str, int | str] | None = None
    if staging_metrics is not None:
        staging = {
            "scenario": work.name,
            **staged_runtime,
            "setup_bytes": staged_setup.stat().st_size,
            "temporary_bytes": staged_runtime["runtime_bytes"]
            + staged_setup.stat().st_size,
        }
        staging_metrics.append(staging)
    ticket = {
        "session_id": session_id,
        "target_version": target_version,
        "expected_sha256": digest,
        "expected_size": staged_setup.stat().st_size,
        "installer_path": str(staged_setup.resolve()),
        "installed_executable": str(executable.resolve()),
        "schema": 1,
        "resume_capture": resume_capture,
    }
    ticket_path = session / "ticket.json"
    ticket_path.write_text(json.dumps(ticket), encoding="utf-8")
    app_profile = Path(environment["APPDATA"]) / "PHPGalleryUploader"
    continuation = (
        _verify_synthetic_watcher_ticket(
            app_profile, session_id, _installed_version(executable),
            target_version, hold_pending_source=switch_backend is not None,
        )
        if resume_capture else None
    )
    profile_snapshot = _snapshot_durable_profile_state(
        app_profile, Path(environment["LOCALAPPDATA"]) / "PHPGalleryUploader",
    )
    observes_failure = expect_setup_failure or expect_setup_launch_failure
    before_fingerprint = _runtime_fingerprint(install) if observes_failure else None
    launcher_lock = (
        _hold_installed_launcher_read_only(executable)
        if expect_setup_failure else None
    )
    helper_temp = work / "helper-temp"
    helper_temp.mkdir()
    helper_environment = {
        **environment, "PYINSTALLER_RESET_ENVIRONMENT": "1",
        "TEMP": str(helper_temp), "TMP": str(helper_temp),
    }
    usage_roots = (install, session, helper_temp)
    try:
        process = subprocess.Popen(
            [str(helper), "--self-update-helper", str(ticket_path)],
            cwd=session, env=helper_environment,
        )
        try:
            _wait_for_marker(session / "ready", process)
            setup_started = time.monotonic()
            next_sample = setup_started
            peak_usage = 0
            usage_observed = True
            (session / "proceed").write_text("1", encoding="ascii")
            deadline = setup_started + UPDATE_WAIT_SECONDS
            while time.monotonic() < deadline and not (session / "completed").is_file():
                if process.poll() is not None and process.returncode != 0:
                    raise RuntimeError("The real update helper reported failure.")
                now = time.monotonic()
                if staging is not None and now >= next_sample:
                    observed = _observed_update_bytes(usage_roots)
                    if observed is None:
                        usage_observed = False
                    else:
                        peak_usage = max(peak_usage, observed)
                    next_sample = now + UPDATE_DISK_SAMPLE_SECONDS
                time.sleep(PROCESS_POLL_SECONDS)
            if not (session / "completed").is_file():
                raise RuntimeError("The real update helper did not complete its ticket.")
            preflight = _observed_setup_preflight(session / 'setup.log')
            if staging is not None:
                staging['setup_preflight'] = preflight
                staging["setup_wait_ms"] = round(
                    (time.monotonic() - setup_started) * UPDATE_MILLISECONDS_PER_SECOND,
                )
                observed = _observed_update_bytes(usage_roots)
                if observed is not None:
                    peak_usage = max(peak_usage, observed)
                staging['usage_sample_status'] = 'measured' if usage_observed and observed is not None else 'partial'
                if peak_usage:
                    staging['peak_observed_update_bytes'] = peak_usage
            if observes_failure and process.poll() is None:
                close_deadline = time.monotonic() + UPDATE_WAIT_SECONDS
                if not _dismiss_owned_helper(
                    process, helper, ticket_path, close_deadline,
                ):
                    raise RuntimeError("Ticket-owned failed-update helper process tree did not exit.")
            try:
                result = process.wait(timeout=INSTALLED_SMOKE_SECONDS)
            except subprocess.TimeoutExpired as exc:
                try:
                    observed_version = _installed_version(executable)
                except (OSError, RuntimeError):
                    observed_version = 'unavailable'
                if staging is not None:
                    staging['installed_version_after_timeout'] = observed_version
                raise RuntimeError(
                    f'Update helper remained visible after setup ({preflight}). '
                    f'Installed launcher version was {observed_version}.',
                ) from exc
        finally:
            if process.poll() is None:
                process.kill()
                process.wait(timeout=INSTALLED_SMOKE_SECONDS)
    finally:
        if launcher_lock is not None:
            _release_installed_launcher(launcher_lock)
    if observes_failure:
        if result == 0:
            raise RuntimeError("The expected setup failure unexpectedly returned success.")
        if _runtime_fingerprint(install) != before_fingerprint:
            raise RuntimeError("Failed setup changed the earlier Qt runtime fingerprint.")
        if _installed_version(executable) != before_version:
            raise RuntimeError("Failed setup changed the earlier Qt launcher version.")
        if expect_setup_launch_failure and (session / "setup.log").exists():
            raise RuntimeError("A setup log exists after the non-launchable image refusal.")
    elif result != 0:
        raise RuntimeError("The real update helper returned a failure status.")
    if resume_capture:
        _wait_for_watcher_consumption(
            app_profile, session_id, target_version, executable,
        )
    close_nonce = session_id if resume_capture else None
    close_backend = active_backend
    close_pid = None
    if switch_backend is not None:
        if (not resume_capture or continuation is None or active_backend is None
                or observes_failure):
            raise RuntimeError("Native backend switching requires a successful resumed update.")
        preference = app_profile / GUI_BACKEND_FILE
        if (load_gui_backend(app_profile) != active_backend
                or preference.read_bytes() != profile_snapshot.get('roaming/' + GUI_BACKEND_FILE)):
            raise RuntimeError("The update changed the saved backend before the Settings restart.")
        if staging is not None:
            staging['resumed_backend'] = active_backend
            staging.update(_measure_waiting_watcher(_restart_pids(executable)))
        switch_result, close_pid = _switch_resumed_backend(
            executable, app_profile, session_id, target_version,
            active_backend, switch_backend, continuation,
        )
        if backend_switch_evidence is not None:
            backend_switch_evidence.append(switch_result)
        close_nonce = None
        close_backend = switch_backend
    _close_restarted_app(
        executable, app_profile, close_nonce,
        expected_backend=close_backend, expected_pid=close_pid,
        staging=staging if switch_backend is None else None,
    )
    _assert_durable_profile_preserved(
        profile_snapshot, app_profile,
        Path(environment["LOCALAPPDATA"]) / "PHPGalleryUploader",
        resumed_session_id=continuation.session_id if continuation is not None else None,
        expected_backend=switch_backend,
        expected_pending_source=(
            app_profile.parent / 'synthetic-watch-folder' / SWITCH_PENDING_SOURCE_NAME
            if switch_backend is not None else None
        ),
        expected_retry_head=(
            app_profile.parent / 'synthetic-watch-folder' / SWITCH_RETRY_HEAD_NAME
            if switch_backend is not None else None
        ),
    )
    expected_version = (
        before_version if observes_failure else target_version
    )
    if _installed_version(executable) != expected_version:
        raise RuntimeError("Installed PE version does not match the helper ticket.")
    result_label = "recovered from setup refusal at " if observes_failure else "updated installation to "
    print(f"Real helper {result_label}{expected_version}.", flush=True)
    return session_id, result


def _mark_evidence(
    evidence: list[dict[str, str]], name: str, status: str, detail: str,
) -> None:
    """Update or append one bounded, path-free hosted scenario result.

    Args:
        evidence (list[dict[str, str]]): Current ordered result rows.
        name (str): Stable machine-readable scenario name.
        status (str): RUNNING, PASS, or FAIL.
        detail (str): Short non-sensitive evidence summary.
    Returns:
        None: Replaces the matching running row or appends a new scenario row.
    Raises:
        ValueError: A report field does not match its bounded schema.
    """
    if (not re.fullmatch(r"[a-z0-9-]{1,48}", name)
            or status not in ("RUNNING", "PASS", "FAIL")
            or len(detail) > 240 or "\\" in detail or ":\\" in detail):
        raise ValueError("Qualification evidence fields are invalid.")
    row = {"name": name, "status": status, "detail": detail}
    if status != "RUNNING":
        for index in range(len(evidence) - 1, -1, -1):
            prior = evidence[index]
            if prior["name"] == name and prior["status"] == "RUNNING":
                evidence[index] = row
                return
    evidence.append(row)


def _seed_durable_profile_state(
    profile: Path, local_profile: Path, synthetic_watch_folder: Path,
    binding: str, account_id: int,
) -> tuple[str, str]:
    """Create representative app-owned state without any live account data.

    Args:
        profile (Path): Isolated APPDATA product profile.
        local_profile (Path): Isolated LOCALAPPDATA product profile.
        synthetic_watch_folder (Path): Empty no-network target saved for Watcher.
        binding (str): Hash-only fingerprint of the synthetic local endpoint.
        account_id (int): Random synthetic account namespace for local catalogue data.
    Returns:
        tuple[str, str]: Synthetic Import job ID and SHA-256 cache object key.
    Raises:
        RuntimeError: An app-owned fixture could not be persisted and read back.
    """
    profile.mkdir(parents=True, exist_ok=True)
    local_profile.mkdir(parents=True, exist_ok=True)
    synthetic_watch_folder.mkdir(parents=True, exist_ok=True)
    (profile / "config.json").write_text(json.dumps({
        "gallery_url": SYNTHETIC_WATCHER_URL,
        "api_key": SYNTHETIC_WATCHER_KEY,
        "watched_folder": str(synthetic_watch_folder),
        "close_to_tray": False,
        "start_with_windows": False,
        "watch_recursive": False,
    }), encoding="utf-8")
    save_recent_gallery_addresses(
        profile / "recent-gallery-addresses.json", (SYNTHETIC_GALLERY_ISSUER,),
    )
    if load_recent_gallery_addresses(
            profile / "recent-gallery-addresses.json") != (SYNTHETIC_GALLERY_ISSUER,):
        raise RuntimeError("Synthetic recent gallery preference failed readback.")

    state_store = JobStateStore(profile / "upload_state.json")
    import_job = ImportJob.create(
        "files", "Synthetic qualification history", "Qualification gallery",
    )
    state_store.save_job(import_job)
    import_job.finished_at = time.time()
    state_store.save_job(import_job)
    state_store.append_event(ActivityEvent(
        level="info", message="Synthetic update qualification activity",
        operation="qualification",
    ))
    if (state_store.get_job(import_job.id) is None
            or not any(
                event.message == "Synthetic update qualification activity"
                for event in state_store.list_events()
            )):
        raise RuntimeError("Synthetic Import history or Activity could not be read back.")

    png = base64.b64decode(SYNTHETIC_CACHE_PNG_BASE64, validate=True)
    cache_key = hashlib.sha256(png).hexdigest()
    media_cache = GalleryMediaCache(
        local_profile / "photo-cache", MEDIA_CACHE_MAX_BYTES, thumbnails=True,
    )
    if not media_cache.set_thumbnail_limit_bytes(SYNTHETIC_THUMBNAIL_LIMIT_BYTES):
        raise RuntimeError("Synthetic thumbnail cache preference could not be saved.")
    if not media_cache.write(
        cache_key, png, "image/png", '"' + cache_key + '"',
    ):
        raise RuntimeError("Synthetic thumbnail cache object could not be written.")
    if media_cache.read(cache_key) is None:
        raise RuntimeError("Synthetic thumbnail cache object could not be verified.")
    catalogue_binding = hashlib.sha256(json.dumps(
        [SYNTHETIC_GALLERY_ISSUER, account_id, "gallery.read"],
        separators=(",", ":"),
    ).encode("utf-8")).hexdigest()
    catalogue = GalleryCatalogueCache(
        local_profile / "catalogue-cache", catalogue_binding,
    )
    if not catalogue.save(GalleryCatalogueSnapshot(())):
        raise RuntimeError("Synthetic gallery catalogue cache could not be saved.")
    if catalogue.load() != GalleryCatalogueSnapshot(()):
        raise RuntimeError("Synthetic gallery catalogue cache could not be verified.")

    flight_root = profile / "flights"
    flight_root.mkdir(parents=True, exist_ok=True)
    preferences: dict[str, str | float | bool] = {
        "watched_folder": str(synthetic_watch_folder),
        "scan_interval_seconds": SYNTHETIC_WATCH_INTERVAL_SECONDS,
        "stable_seconds": SYNTHETIC_WATCH_STABLE_SECONDS,
        "attach_sim_camera_metadata": False,
        "delete_uploaded_files": False,
        "watch_recursive": False,
        "sync_utc_time": False,
    }
    if preferences.keys() != WATCHER_PREFERENCE_TYPES.keys():
        raise RuntimeError("Synthetic Watcher preferences do not match the app schema.")
    lease = WorkspaceLease(flight_root)
    try:
        WatcherProfile(False, binding, preferences).save(lease)
    finally:
        lease.release()

    saved_directory = flight_root / uuid4().hex
    saved_directory.mkdir()
    SessionPlan(
        directory=saved_directory, binding=binding, languages=(),
        reports_enabled=False, completed=True, local_only=True,
    ).save()
    (saved_directory / "session.json").write_text(json.dumps({
        "started_utc": "2026-11-26T00:00:00+00:00",
        "aircraft": "Synthetic qualification flight",
        "sample_count": 1,
        "recording_ended": True,
        "gallery_identity": "",
    }), encoding="utf-8")
    reports = saved_directory / "reports"
    reports.mkdir()
    (reports / "Synthetic qualification report.pdf").write_bytes(
        b"%PDF-1.4\n% Synthetic offline qualification fixture\n%%EOF\n",
    )
    flights = saved_flights(flight_root)
    saved = next((row for row in flights if row.directory == saved_directory), None)
    if saved is None or not saved.completed or saved.report_count != 1:
        raise RuntimeError("Synthetic saved flight or PDF could not be read back.")
    return import_job.id, cache_key


def _snapshot_durable_profile_state(
    profile: Path, local_profile: Path,
) -> dict[str, bytes]:
    """Read bounded profile files while omitting only expected volatile state.

    Args:
        profile (Path): Isolated APPDATA product profile.
        local_profile (Path): Isolated LOCALAPPDATA product profile.
    Returns:
        dict[str, bytes]: Relative privacy-safe identity to exact file bytes.
    Raises:
        RuntimeError: A profile entry redirects, changes or exceeds its bound.
    """
    result: dict[str, bytes] = {}
    total_bytes = 0
    entry_count = 0
    roots = (("roaming", profile), ("local", local_profile))
    for label, root in roots:
        if not root.exists():
            continue
        pending = [root]
        while pending:
            directory = pending.pop()
            directory_info = directory.lstat()
            if (not stat.S_ISDIR(directory_info.st_mode)
                    or directory.is_symlink()
                    or getattr(directory_info, "st_file_attributes", 0)
                    & FILE_ATTRIBUTE_REPARSE_POINT):
                raise RuntimeError("Durable profile contains a redirected directory.")
            for path in sorted(directory.iterdir(), reverse=True):
                relative = path.relative_to(root)
                if (label == "local" and relative.parts[0] == "updates"):
                    continue
                if (label == "roaming" and path.name.startswith("watcher.log")):
                    continue
                if (label == "roaming" and path.name == "upload_state.json"):
                    continue
                if (label == "roaming" and relative == Path(
                        "flights/watcher-continuation.json")
                        or label == "roaming" and relative == Path(
                            "flights/watcher-restart.json")):
                    continue
                info = path.lstat()
                entry_count += 1
                if entry_count > PROFILE_SNAPSHOT_MAX_ENTRIES:
                    raise RuntimeError("Durable profile exceeds its entry limit.")
                if stat.S_ISDIR(info.st_mode):
                    pending.append(path)
                    continue
                if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                        or getattr(info, "st_file_attributes", 0)
                        & FILE_ATTRIBUTE_REPARSE_POINT
                        or info.st_size > PROFILE_SNAPSHOT_MAX_BYTES - total_bytes):
                    raise RuntimeError("Durable profile contains a linked or oversized file.")
                contents = path.read_bytes()
                after = path.lstat()
                if (len(contents) != info.st_size
                        or not os.path.samestat(info, after)
                        or info.st_mtime_ns != after.st_mtime_ns):
                    raise RuntimeError("Durable profile changed during its snapshot.")
                total_bytes += len(contents)
                result[label + "/" + relative.as_posix()] = contents
    return result


def _assert_durable_profile_preserved(
    snapshot: dict[str, bytes], profile: Path, local_profile: Path,
    *, resumed_session_id: str | None = None,
    expected_backend: GuiBackend | None = None,
    expected_pending_source: Path | None = None,
    expected_retry_head: Path | None = None,
) -> None:
    """Preserve durable files and validate only exact resumed-session changes.

    Args:
        snapshot (dict[str, bytes]): Bounded pre-update file map.
        profile (Path): Isolated APPDATA product profile after restart.
        local_profile (Path): Isolated LOCALAPPDATA product profile after restart.
        resumed_session_id (str | None): Exact capture session permitted to
            update its own recorder and Watcher drain metadata.
        expected_backend (GuiBackend | None): Explicit Settings selection, or
            None when the saved preference must remain unchanged.
        expected_pending_source (Path | None): Exact synthetic FIFO source B,
            or None when no source is expected.
        expected_retry_head (Path | None): Exact durable retry-wait head A
            that must precede B without inventory or dispatch.
    Returns:
        None: Every durable file and permitted resumed state passes readback.
    Raises:
        RuntimeError: An unrelated file or exact resumed session changed.
    """
    after = _snapshot_durable_profile_state(profile, local_profile)
    allowed_mutable_paths: set[str] = set()
    if resumed_session_id is not None:
        if re.fullmatch(r"[0-9a-f]{32}", resumed_session_id) is None:
            raise RuntimeError("Resumed Watcher session identity is invalid.")
        prefix = "roaming/flights/" + resumed_session_id + "/"
        allowed_mutable_paths = {
            prefix + "session.json", prefix + "watch-drain.json",
        }
    if expected_backend is not None:
        allowed_mutable_paths.add("roaming/" + GUI_BACKEND_FILE)
    for relative, contents in snapshot.items():
        if relative in allowed_mutable_paths:
            continue
        if after.get(relative) != contents:
            raise RuntimeError("Update changed durable profile state: " + relative)
    if resumed_session_id is None:
        if expected_backend is not None and load_gui_backend(profile) != expected_backend:
            raise RuntimeError("Settings backend preference did not match the explicit selection.")
        return
    session_path = "roaming/flights/" + resumed_session_id + "/session.json"
    drain_path = "roaming/flights/" + resumed_session_id + "/watch-drain.json"
    original_session_bytes = snapshot.get(session_path)
    current_session_bytes = after.get(session_path)
    original_drain_bytes = snapshot.get(drain_path)
    current_drain_bytes = after.get(drain_path)
    if None in (
        original_session_bytes, current_session_bytes,
        original_drain_bytes, current_drain_bytes,
    ):
        raise RuntimeError("Resumed Watcher metadata is missing.")
    original_session = json.loads(original_session_bytes)
    current_session = json.loads(current_session_bytes)
    original_drain = json.loads(original_drain_bytes)
    current_drain = json.loads(current_drain_bytes)
    if (not isinstance(original_session, dict)
            or not isinstance(current_session, dict)
            or original_session.get("session_id") != resumed_session_id
            or current_session.get("session_id") != resumed_session_id
            or not isinstance(original_session.get("started_utc"), str)
            or current_session.get("started_utc") != original_session["started_utc"]
            or original_session.get("sample_count") != 0
            or current_session.get("sample_count") != 0):
        raise RuntimeError("Resumed flight identity or telemetry sample count changed.")
    expected_pending = (
        [str(expected_retry_head), str(expected_pending_source)]
        if expected_retry_head is not None and expected_pending_source is not None
        else [str(expected_pending_source)] if expected_pending_source is not None else []
    )
    original_pending = (
        [str(expected_retry_head)] if expected_retry_head is not None else []
    )
    if (not isinstance(original_drain, dict)
            or not isinstance(current_drain, dict)
            or original_drain.get("folder") != current_drain.get("folder")
            or original_drain.get("recursive") is not current_drain.get("recursive")
            or original_drain.get("pending") != original_pending
            or current_drain.get("pending") != expected_pending
            or original_drain.get("initial") != current_drain.get("initial")
            or original_drain.get("initial") != []
            or current_drain.get("initial_captured") is not True
            or current_drain.get("frozen") is not False
            or current_drain.get("cutoff") != 0
            or current_drain.get("error") != ""):
        raise RuntimeError("Resumed Watcher folder or retained queue state changed.")
    items = current_drain.get("items")
    contents = base64.b64decode(SYNTHETIC_CACHE_PNG_BASE64, validate=True)
    if expected_retry_head is not None:
        if expected_pending_source is None:
            raise RuntimeError("Blocked-head verification requires its exact second source.")
        blocked = items[0] if isinstance(items, list) and len(items) == 2 else None
        pending = items[1] if isinstance(items, list) and len(items) == 2 else None
        original_items = original_drain.get("items")
        original_blocked = (
            original_items[0]
            if isinstance(original_items, list) and len(original_items) == 1
            else None
        )
        original_blocked_source = (
            original_blocked.get("source")
            if isinstance(original_blocked, dict) else None
        )
        blocked_source = blocked.get("source") if isinstance(blocked, dict) else None
        pending_source = pending.get("source") if isinstance(pending, dict) else None
        if (expected_retry_head.is_symlink()
                or expected_pending_source.is_symlink()
                or expected_retry_head.read_bytes() != contents
                or expected_pending_source.read_bytes() != contents
                or not isinstance(blocked, dict) or not isinstance(blocked_source, dict)
                or not isinstance(pending, dict) or not isinstance(pending_source, dict)
                or not isinstance(original_blocked, dict)
                or not isinstance(original_blocked_source, dict)
                or original_blocked_source.get("path") != str(expected_retry_head)
                or original_blocked.get("state") != "retry_wait"
                or original_blocked.get("phase") != "source"
                or original_blocked.get("reason") != "Qualification source retry is deferred for qualification."
                or original_blocked.get("attempts") != 0
                or type(original_blocked.get("next_retry_at")) not in (int, float)
                or original_blocked.get("next_retry_at", 0)
                <= time.time() + SWITCH_RETRY_MINIMUM_REMAINING_SECONDS
                or blocked_source.get("path") != str(expected_retry_head)
                or blocked.get("item_id") != original_blocked.get("item_id")
                or blocked.get("sequence") != original_blocked.get("sequence")
                or blocked_source.get("signature") != original_blocked_source.get("signature")
                or blocked_source.get("identity") != original_blocked_source.get("identity")
                or blocked.get("next_retry_at") != original_blocked.get("next_retry_at")
                or blocked.get("next_retry_at", 0)
                <= time.time() + SWITCH_RETRY_MINIMUM_REMAINING_SECONDS
                or pending_source.get("path") != str(expected_pending_source)
                or blocked.get("state") != "retry_wait" or blocked.get("phase") != "source"
                or blocked.get("reason") != "Qualification source retry is deferred for qualification."
                or pending.get("state") != "observed" or pending.get("phase") != "discovery"
                or any(item.get("attempts") != 0 or item.get("sha256")
                       or item.get("inventory_ready") for item in (blocked, pending))
                or current_drain.get("retained_stop") is not False
                or current_drain.get("recovery_intent") is not None
                or current_drain.get("approved_upload") is not None):
            raise RuntimeError("The retry-wait head or second FIFO source changed or dispatched.")
    elif expected_pending_source is not None:
        item = items[0] if isinstance(items, list) and len(items) == 1 else None
        source = item.get("source") if isinstance(item, dict) else None
        if (expected_pending_source.is_symlink()
                or expected_pending_source.read_bytes() != contents
                or not isinstance(item, dict) or not isinstance(source, dict)
                or source.get("path") != str(expected_pending_source)
                or source.get("size") != len(contents)
                or item.get("state") != "stabilizing"
                or item.get("phase") != "stability"
                or item.get("attempts") != 0 or item.get("sha256") != ""
                or current_drain.get("retained_stop") is not False
                or current_drain.get("recovery_intent") is not None
                or current_drain.get("approved_upload") is not None):
            raise RuntimeError("The held Watcher source was changed or dispatched.")
    elif items != []:
        raise RuntimeError("Resumed Watcher has an unexpected pending queue item.")
    if expected_backend is not None and load_gui_backend(profile) != expected_backend:
        raise RuntimeError("Settings backend preference did not match the explicit selection.")


def qualify_updates(
    stable_installer: Path, earlier_installer: Path, earlier_version: str,
    target_installer: Path, target_version: str, work: Path,
    evidence: list[dict[str, str]],
    staging_metrics: list[dict[str, int | str]],
    backend_switch_results: list[dict[str, str]],
    linked_switch_result: dict[str, str], *, evidence_dir: Path,
) -> tuple[int, str]:
    """Run verified stable-to-onedir, active and linked-backend journeys.

    Args:
        stable_installer (Path): Download destination for public stable 1.7.1.
        earlier_installer (Path): First real standard onedir qualification setup.
        earlier_version (str): Version embedded in the first standard setup.
        target_installer (Path): Second real standard onedir qualification setup.
        target_version (str): Version embedded in the second standard setup.
        work (Path): New, disposable root created by the calling audit job.
        evidence (list[dict[str, str]]): Result rows included in the JSON report.
        staging_metrics (list[dict[str, int | str]]): Per-helper runtime copy sizes and duration.
        backend_switch_results (list[dict[str, str]]): Verified native switch
            observations, including exact held-source and marker states.
        linked_switch_result (dict[str, str]): Sanitized linked-profile restart,
            native vault and explicit target reconnection evidence.
        evidence_dir (Path): Runner-owned report directory for native linked screenshots.
    Returns:
        tuple[int, str]: Authenticated public setup size and SHA-256 digest.
    Raises:
        RuntimeError: Any public identity, install or update assertion fails.
    """
    if os.name != "nt" or work.exists():
        raise RuntimeError("Update qualification needs a new Windows-owned work root.")
    if (not re.fullmatch(r"\d+\.\d+\.\d+", earlier_version)
            or not re.fullmatch(r"\d+\.\d+\.\d+", target_version)
            or tuple(map(int, earlier_version.split("."))) <= (1, 7, 1)
            or tuple(map(int, target_version.split(".")))
            <= tuple(map(int, earlier_version.split(".")))):
        raise RuntimeError("Temporary setup versions must increase above released 1.7.1.")
    _require_standard_setup(earlier_installer, earlier_version, "Earlier-version")
    _require_standard_setup(target_installer, target_version, "Target-version")
    work.mkdir(parents=True)
    _mark_evidence(evidence, "public-stable-release", "RUNNING", "Authenticating tag, metadata and setup bytes.")
    # Do not propagate the metadata-only token to setup, helpers or installed apps.
    api_token = os.environ.pop("PHP_GALLERY_RELEASE_API_TOKEN", "")
    stable_size, stable_hash = fetch_public_stable_asset(stable_installer, api_token=api_token)
    if stable_installer.stat().st_size != stable_size:
        raise RuntimeError("Stable release bytes changed after verification.")
    print(f"Stable setup SHA-256: {stable_hash}", flush=True)
    _mark_evidence(
        evidence, "public-stable-release", "PASS",
        f"v_1.7.1 {stable_size} bytes sha256={stable_hash}",
    )

    profile = work / "profile"
    local = work / "local"
    profile.mkdir()
    local.mkdir()
    environment = {
        **os.environ, "APPDATA": str(profile), "LOCALAPPDATA": str(local),
        "PYINSTALLER_RESET_ENVIRONMENT": "1",
    }
    stable_install = work / "stable-install"
    _mark_evidence(evidence, "stable-install", "RUNNING", "Installing authenticated public setup.")
    _run_setup(stable_installer, stable_install, work / "stable-setup")
    stable_executable = stable_install / "PHPGalleryUploader.exe"
    if _installed_version(stable_executable) != "1.7.1":
        raise RuntimeError("The public stable setup did not install version 1.7.1.")
    _mark_evidence(evidence, "stable-install", "PASS", "Published stable setup installed as 1.7.1.")
    _mark_evidence(evidence, "invalid-ticket", "RUNNING", "Launching installed helper with unsupported schema.")
    _run_rejected_helper_ticket(
        earlier_installer, earlier_version, stable_install, environment,
        work / "invalid-ticket", "invalid",
    )
    _mark_evidence(evidence, "invalid-ticket", "PASS", "Real helper refused unsupported ticket schema.")
    _mark_evidence(evidence, "foreign-ticket", "RUNNING", "Launching installed helper with foreign install path.")
    _run_rejected_helper_ticket(
        earlier_installer, earlier_version, stable_install, environment,
        work / "foreign-ticket", "foreign",
    )
    _mark_evidence(evidence, "foreign-ticket", "PASS", "Real helper refused an unregistered install path.")
    _mark_evidence(evidence, "failed-update-no-mutation", "RUNNING", "Launching installed helper with a bad setup digest.")
    _run_rejected_helper_ticket(
        earlier_installer, earlier_version, stable_install, environment,
        work / "bad-digest-ticket", "digest-mismatch",
    )
    _mark_evidence(evidence, "failed-update-no-mutation", "PASS", "Digest failure retained the 1.7.1 install; valid update follows.")
    _mark_evidence(evidence, "stale-watcher-ticket", "RUNNING", "Checking expiry through the production reader.")
    _write_expired_watcher_ticket(work / "stale-watcher-profile")
    _mark_evidence(evidence, "stale-watcher-ticket", "PASS", "Production reader refused expired synthetic continuation.")
    state = profile / "PHPGalleryUploader"
    state.mkdir(parents=True, exist_ok=True)
    if (state / GUI_BACKEND_FILE).exists():
        raise RuntimeError("The published onefile profile unexpectedly contains a GUI preference.")
    save_ui_language(state, "en")
    save_thumbnail_batch_size(state, 24)
    credential_store = WindowsCredentialStore()
    credential_issuer = "https://" + uuid4().hex + ".qualification.example/gallery"
    credential_account = int(uuid4().hex[:15], 16)
    credential = os.urandom(SYNTHETIC_CREDENTIAL_BYTES)
    credential_store.write(credential_issuer, credential_account, credential)
    atexit.register(
        credential_store.delete, credential_issuer, credential_account,
    )
    synthetic_binding = connection_binding(
        SYNTHETIC_WATCHER_URL, SYNTHETIC_WATCHER_KEY,
    )
    import_job_id, stable_cache_key = _seed_durable_profile_state(
        state, Path(environment["LOCALAPPDATA"]) / "PHPGalleryUploader",
        profile / "synthetic-watch-folder", synthetic_binding, credential_account,
    )
    # The published onefile app ignores this independent preference. Pin the
    # target's initial backend so the native journey still exercises Tk to Qt.
    save_gui_backend(state, "tkinter")
    _mark_evidence(evidence, "stable-profile-preservation", "RUNNING", "Comparing saved settings and credential vault sentinel.")
    _mark_evidence(evidence, "stable-to-unified-helper", "RUNNING", "Updating authentic 1.7.1 to the common onedir setup with an exact Watcher continuation.")
    _mark_evidence(evidence, "active-watcher-tk-to-qt", "RUNNING", f"Switching the resumed {target_version} Watcher through installed Settings.")
    _run_real_helper(
        target_installer, target_version, stable_install, environment,
        work / "stable-to-unified", resume_capture=True,
        active_backend="tkinter", switch_backend="pyside6",
        backend_switch_evidence=backend_switch_results,
        staging_metrics=staging_metrics,
    )
    if (not backend_switch_results
            or backend_switch_results[-1].get("ordinary_restart_pair")
            != "identical-and-consumed"):
        raise RuntimeError("Tk-to-Qt active Watcher switch evidence is incomplete.")
    _mark_evidence(evidence, "stable-to-unified-helper", "PASS", "Real helper installed onedir; the active Tk Watcher consumed its exact nonce before switching to Qt.")
    _mark_evidence(evidence, "active-watcher-tk-to-qt", "PASS", "Qt retained the same flight, retry-wait FIFO head A and untouched source B; no inventory or dispatch occurred.")
    if not stable_install.joinpath("_internal").is_dir():
        raise RuntimeError("Stable-to-onedir update did not install its shared runtime.")
    if (load_gui_backend(state) != "pyside6"
            or not (state / GUI_BACKEND_FILE).is_file()):
        raise RuntimeError("Tk-to-Qt Settings switch did not persist its selected backend.")
    if credential_store.read(credential_issuer, credential_account) != credential:
        raise RuntimeError("Stable-to-onedir update changed the protected test credential.")
    durable_store = JobStateStore(state / "upload_state.json")
    if (durable_store.get_job(import_job_id) is None
            or not any(
                event.message == "Synthetic update qualification activity"
                for event in durable_store.list_events()
            )):
        raise RuntimeError("Stable-to-onedir update lost Import or Activity history.")
    stable_media_cache = GalleryMediaCache(
        Path(environment["LOCALAPPDATA"]) / "PHPGalleryUploader" / "photo-cache",
        MEDIA_CACHE_MAX_BYTES, thumbnails=True,
    )
    if stable_media_cache.read(stable_cache_key) is None:
        raise RuntimeError("Stable-to-onedir update lost its local cache object.")
    persisted_config = json.loads(
        (state / "config.json").read_text(encoding="utf-8"),
    )
    if (persisted_config.get("api_key") != SYNTHETIC_WATCHER_KEY
            or persisted_config.get("gallery_url") != SYNTHETIC_WATCHER_URL
            or persisted_config.get("watched_folder")
            != str(profile / "synthetic-watch-folder")):
        raise RuntimeError("Stable-to-onedir update changed synthetic connection settings.")
    print("Onefile-to-onedir update preserved the isolated user profile.", flush=True)
    _mark_evidence(evidence, "stable-profile-preservation", "PASS", "Settings, caches, Import/Activity, saved flight/PDF, Watcher state and vault sentinel survived.")
    _mark_evidence(evidence, "onedir-tk-update", "RUNNING", "Installing the earlier common setup and updating its active Tk application.")
    tk_install = work / "onedir-tk-install"
    tk_environment = {
        **environment, "APPDATA": str(work / "tk-profile"),
        "LOCALAPPDATA": str(work / "tk-local"),
    }
    Path(tk_environment["APPDATA"]).mkdir()
    Path(tk_environment["LOCALAPPDATA"]).mkdir()
    tk_profile = Path(tk_environment["APPDATA"]) / "PHPGalleryUploader"
    save_gui_backend(tk_profile, "tkinter")
    save_ui_language(tk_profile, "en")
    save_thumbnail_batch_size(tk_profile, 24)
    tk_job_id, tk_cache_key = _seed_durable_profile_state(
        tk_profile, Path(tk_environment["LOCALAPPDATA"]) / "PHPGalleryUploader",
        Path(tk_environment["APPDATA"]) / "synthetic-watch-folder",
        synthetic_binding, int(uuid4().hex[:15], 16),
    )
    _run_setup(earlier_installer, tk_install, work / "onedir-tk-first-setup")
    _run_real_helper(
        target_installer, target_version, tk_install, tk_environment,
        work / "onedir-tk-update", resume_capture=True,
        active_backend="tkinter", staging_metrics=staging_metrics,
    )
    if not tk_install.joinpath("_internal").is_dir():
        raise RuntimeError("Tk onedir update removed the shared runtime.")
    tk_store = JobStateStore(tk_profile / "upload_state.json")
    tk_cache = GalleryMediaCache(
        Path(tk_environment["LOCALAPPDATA"]) / "PHPGalleryUploader" / "photo-cache",
        MEDIA_CACHE_MAX_BYTES, thumbnails=True,
    )
    if (tk_store.get_job(tk_job_id) is None or tk_cache.read(tk_cache_key) is None
            or not any(
                event.message == "Synthetic update qualification activity"
                for event in tk_store.list_events()
            )):
        raise RuntimeError("Tk update lost synthetic cache or history state.")
    _mark_evidence(evidence, "onedir-tk-update", "PASS", "Active Tk onedir updated; local cache and durable profile state survived.")

    # The returned Qt app genuinely resumes the rollback capture and retains
    # its ordinary quit marker. Leave that evidence in its own profile. A
    # separate synthetic capture is required for the successful update journey.
    recovery_install = work / 'onedir-qt-recovery-install'
    recovery_environment = {
        **environment, 'APPDATA': str(work / 'qt-recovery-profile'),
        'LOCALAPPDATA': str(work / 'qt-recovery-local'),
    }
    Path(recovery_environment['APPDATA']).mkdir()
    Path(recovery_environment['LOCALAPPDATA']).mkdir()
    recovery_profile = Path(recovery_environment['APPDATA']) / 'PHPGalleryUploader'
    recovery_local = Path(recovery_environment['LOCALAPPDATA']) / 'PHPGalleryUploader'
    save_gui_backend(recovery_profile, 'pyside6')
    save_ui_language(recovery_profile, 'en')
    save_thumbnail_batch_size(recovery_profile, 24)
    recovery_job, recovery_cache_key = _seed_durable_profile_state(
        recovery_profile, recovery_local,
        Path(recovery_environment['APPDATA']) / 'synthetic-watch-folder',
        synthetic_binding, int(uuid4().hex[:15], 16),
    )
    _run_setup(earlier_installer, recovery_install, work / 'onedir-qt-recovery-setup')
    _mark_evidence(evidence, "authentic-setup-failure-recovery", "RUNNING", "Holding earlier launcher against replacement during the real target setup.")
    _, failed_setup_exit = _run_real_helper(
        target_installer, target_version, recovery_install, recovery_environment,
        work / "qt-failed-setup", resume_capture=True,
        expect_setup_failure=True, active_backend="pyside6",
        staging_metrics=staging_metrics,
    )
    recovery_store = JobStateStore(recovery_profile / 'upload_state.json')
    recovery_cache = GalleryMediaCache(
        recovery_local / 'photo-cache',
        MEDIA_CACHE_MAX_BYTES, thumbnails=True,
    )
    if (recovery_store.get_job(recovery_job) is None or recovery_cache.read(recovery_cache_key) is None
            or not any(
                event.message == "Synthetic update qualification activity"
                for event in recovery_store.list_events()
            )):
        raise RuntimeError("Failed setup recovery lost synthetic cache or history state.")
    _mark_evidence(evidence, "authentic-setup-failure-recovery", "PASS", f"Helper exited {failed_setup_exit}; runtime fingerprint matched and prior Qt app consumed its exact nonce.")

    _mark_evidence(evidence, "onedir-qt-update", "RUNNING", "Installing the earlier common setup and updating its active Qt application.")
    qt_install = work / "onedir-qt-install"
    qt_environment = {
        **environment, "APPDATA": str(work / "qt-profile"),
        "LOCALAPPDATA": str(work / "qt-local"),
    }
    Path(qt_environment["APPDATA"]).mkdir()
    Path(qt_environment["LOCALAPPDATA"]).mkdir()
    qt_profile = Path(qt_environment["APPDATA"]) / "PHPGalleryUploader"
    save_gui_backend(qt_profile, "pyside6")
    save_ui_language(qt_profile, "en")
    save_thumbnail_batch_size(qt_profile, 24)
    qt_job_id, qt_cache_key = _seed_durable_profile_state(
        qt_profile, Path(qt_environment["LOCALAPPDATA"]) / "PHPGalleryUploader",
        Path(qt_environment["APPDATA"]) / "synthetic-watch-folder",
        synthetic_binding, int(uuid4().hex[:15], 16),
    )
    _run_setup(earlier_installer, qt_install, work / "onedir-qt-first-setup")
    _mark_evidence(evidence, 'concurrent-setup-refused', 'RUNNING', 'Holding the native product setup mutex.')
    _verify_setup_serialization(target_installer, qt_install, work / 'existing-setup-mutex')
    _mark_evidence(evidence, 'concurrent-setup-refused', 'PASS', 'Native setup refused the product mutex and left the prior runtime byte-identical.')
    _mark_evidence(evidence, "active-watcher-qt-to-tk", "RUNNING", f"Switching the resumed {target_version} Qt Watcher through installed Settings.")
    _run_real_helper(
        target_installer, target_version, qt_install, qt_environment,
        work / "onedir-qt-update", resume_capture=True, active_backend="pyside6",
        switch_backend="tkinter", backend_switch_evidence=backend_switch_results,
        staging_metrics=staging_metrics,
    )
    if (len(backend_switch_results) < 2
            or backend_switch_results[-1].get("ordinary_restart_pair")
            != "identical-and-consumed"):
        raise RuntimeError("Qt-to-Tk active Watcher switch evidence is incomplete.")
    if not qt_install.joinpath("_internal").is_dir():
        raise RuntimeError("Onedir-to-onedir update removed the shared runtime.")
    qt_store = JobStateStore(qt_profile / "upload_state.json")
    qt_cache = GalleryMediaCache(
        Path(qt_environment["LOCALAPPDATA"]) / "PHPGalleryUploader" / "photo-cache",
        MEDIA_CACHE_MAX_BYTES, thumbnails=True,
    )
    if (qt_store.get_job(qt_job_id) is None or qt_cache.read(qt_cache_key) is None
            or not any(
                event.message == "Synthetic update qualification activity"
                for event in qt_store.list_events()
            )):
        raise RuntimeError("Qt update lost synthetic cache or history state.")
    if credential_store.read(credential_issuer, credential_account) != credential:
        raise RuntimeError("Onedir-to-onedir update changed the protected test credential.")
    print("Onedir-to-onedir Tk and Qt update journeys completed.", flush=True)
    _mark_evidence(evidence, "onedir-qt-update", "PASS", "Active Qt onedir updated; its Watcher continued in Tk and profile state survived.")
    _mark_evidence(evidence, "active-watcher-qt-to-tk", "PASS", "Tk retained the same flight, retry-wait FIFO head A and untouched source B; no inventory or dispatch occurred.")
    _mark_evidence(evidence, "uninstall-reinstall", "RUNNING", "Removing and reinstalling the common target setup.")
    reinstall_profile_snapshot = _snapshot_durable_profile_state(
        qt_profile, Path(qt_environment["LOCALAPPDATA"]) / "PHPGalleryUploader",
    )
    _verify_uninstall_reinstall(
        target_installer, qt_install, qt_environment, work / "onedir-reinstall",
    )
    _assert_durable_profile_preserved(
        reinstall_profile_snapshot, qt_profile,
        Path(qt_environment["LOCALAPPDATA"]) / "PHPGalleryUploader",
    )
    if credential_store.read(credential_issuer, credential_account) != credential:
        raise RuntimeError("Uninstall/reinstall changed the protected test credential.")
    _mark_evidence(evidence, "uninstall-reinstall", "PASS", "Uninstall removed runtime; reinstall restored it and retained the full profile.")
    credential_store.delete(credential_issuer, credential_account)
    _mark_evidence(
        evidence, "installed-linked-backend-switch", "RUNNING",
        "Using the real native vault and explicit linked reconnection in each installed backend.",
    )
    linked_error: Exception | None = None
    try:
        linked_switch_result.update(qualify_installed_linked_switch(
            qt_install / "PHPGalleryUploader.exe", work / "linked-switch", target_version,
            evidence_dir=evidence_dir, progress=linked_switch_result,
        ))
    except Exception as error:
        # The linked journey owns and closes its own processes. An independent
        # profile can still qualify the setup refusal against this installation.
        _mark_evidence(evidence, "installed-linked-backend-switch", "FAIL", type(error).__name__)
        print(f"Installed linked switch failed: {error}", flush=True)
        linked_error = error
    else:
        _mark_evidence(
            evidence, "installed-linked-backend-switch", "PASS",
            "The installed round trip retained its approved issuer, account, scope and target.",
        )

    launch_refusal_work = work / 'qt-setup-launch-refusal'
    launch_refusal_work.mkdir()
    # Keep this refusal on the newly built runtime in a profile with its own
    # stopped Watcher and exact continuation after the successful journeys.
    launch_refusal_environment = {
        **environment, 'APPDATA': str(launch_refusal_work / 'roaming'),
        'LOCALAPPDATA': str(launch_refusal_work / 'local'),
    }
    refusal_roaming = Path(launch_refusal_environment['APPDATA'])
    refusal_profile = refusal_roaming / 'PHPGalleryUploader'
    refusal_local = Path(launch_refusal_environment['LOCALAPPDATA']) / 'PHPGalleryUploader'
    save_gui_backend(refusal_profile, 'pyside6')
    save_ui_language(refusal_profile, 'en')
    save_thumbnail_batch_size(refusal_profile, 24)
    refusal_job, refusal_cache_key = _seed_durable_profile_state(
        refusal_profile, refusal_local, refusal_roaming / 'synthetic-watch-folder',
        synthetic_binding, int(uuid4().hex[:15], 16),
    )
    nonlaunchable_setup = launch_refusal_work / 'nonlaunchable-setup.exe'
    nonlaunchable_setup.write_bytes(NONLAUNCHABLE_SETUP_PAYLOAD)
    _mark_evidence(
        evidence, "setup-launch-refusal-recovery", "RUNNING",
        "Passing a digest-valid non-executable image through the installed helper.",
    )
    _, launch_refusal_exit = _run_real_helper(
        nonlaunchable_setup, target_version, qt_install, launch_refusal_environment,
        launch_refusal_work, resume_capture=True,
        expect_setup_launch_failure=True, active_backend="pyside6",
        staging_metrics=staging_metrics,
    )
    refusal_store = JobStateStore(refusal_profile / 'upload_state.json')
    refusal_cache = GalleryMediaCache(
        refusal_local / 'photo-cache', MEDIA_CACHE_MAX_BYTES, thumbnails=True,
    )
    if (refusal_store.get_job(refusal_job) is None
            or refusal_cache.read(refusal_cache_key) is None
            or not any(
                event.message == "Synthetic update qualification activity"
                for event in refusal_store.list_events()
            )):
        raise RuntimeError("Launch-refusal recovery lost synthetic cache or history state.")
    _mark_evidence(
        evidence, "setup-launch-refusal-recovery", "PASS",
        f"Helper exited {launch_refusal_exit} for the non-PE setup image with no setup log. "
        "The unchanged installation resumed the exact Qt nonce and retained its profile.",
    )
    if linked_error is not None:
        raise RuntimeError('Installed linked switch qualification failed.') from linked_error
    return stable_size, stable_hash


def main() -> int:
    """Parse the dedicated hosted update qualification inputs.

    Returns:
        int: Zero after both installed update paths pass; one on failure.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--stable-installer", type=Path, required=True)
    parser.add_argument(
        "--earlier-installer", "--earlier-qt",
        dest="earlier_installer", type=Path, required=True,
    )
    parser.add_argument("--earlier-version", required=True)
    parser.add_argument(
        "--target-installer", "--target-qt",
        dest="target_installer", type=Path, required=True,
    )
    parser.add_argument("--target-version", required=True)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--work", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    arguments = parser.parse_args()
    selected = os.environ.get("PHP_GALLERY_ISSUE_445_CANDIDATE") or os.environ.get("GITHUB_SHA", "")
    if (os.environ.get("GITHUB_ACTIONS") != "true" or selected != arguments.source_sha
            or re.fullmatch(r"[0-9a-f]{40}", arguments.source_sha) is None):
        parser.error("An exact hosted issue 445 candidate is required.")
    evidence: list[dict[str, str]] = []
    staging_metrics: list[dict[str, int | str]] = []
    backend_switch_results: list[dict[str, str]] = []
    linked_switch_result: dict[str, str] = {}
    report: dict[str, object] = {
        "schema_version": 1,
        "status": "RUNNING",
        "source_sha": arguments.source_sha,
        "public_source": {
            "tag": PUBLIC_STABLE_TAG,
            "commit": PUBLIC_STABLE_COMMIT,
            "asset": PUBLIC_STABLE_ASSET,
        },
        "qualification_versions": {
            "earlier_installer": arguments.earlier_version,
            "target_installer": arguments.target_version,
        },
        "dependencies": dependency_versions(),
        "scenarios": evidence,
        "active_backend_switches": backend_switch_results,
        "linked_backend_switch": linked_switch_result,
    }
    status = 1
    try:
        with arguments.earlier_installer.open("rb") as setup:
            report["earlier_setup_sha256"] = hashlib.file_digest(setup, "sha256").hexdigest()
        with arguments.target_installer.open("rb") as setup:
            report["target_setup_sha256"] = hashlib.file_digest(setup, "sha256").hexdigest()
        size, digest = qualify_updates(
            arguments.stable_installer, arguments.earlier_installer,
            arguments.earlier_version, arguments.target_installer,
            arguments.target_version, arguments.work, evidence, staging_metrics,
            backend_switch_results, linked_switch_result,
            evidence_dir=arguments.report.parent,
        )
        report["public_source"] = {
            **report["public_source"],
            "size": size,
            "sha256": digest,
        }
        report["status"] = "PASS"
        report["helper_staging"] = staging_metrics
        status = 0
    except Exception as exc:
        print("Installed update qualification failed: " + str(exc), file=sys.stderr)
        report["status"] = "FAIL"
        report["helper_staging"] = staging_metrics
        running_rows = [row for row in evidence if row["status"] == "RUNNING"]
        for row in running_rows:
            row["status"] = "FAIL"
            row["detail"] = type(exc).__name__
        if not running_rows:
            _mark_evidence(evidence, "hosted-run", "FAIL", type(exc).__name__)
    try:
        payload = json.dumps(report, sort_keys=True, indent=2) + "\n"
        encoded = payload.encode("utf-8")
        if len(encoded) > QUALIFICATION_REPORT_MAX_BYTES:
            raise RuntimeError("Qualification evidence exceeds its report bound.")
        arguments.report.parent.mkdir(parents=True, exist_ok=True)
        arguments.report.write_bytes(encoded)
    except (OSError, RuntimeError) as exc:
        print("Unable to retain qualification report: " + str(exc), file=sys.stderr)
        return 1
    return status


if __name__ == "__main__":
    raise SystemExit(main())
