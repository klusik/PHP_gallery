# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Apply a verified uploader update in an independent user process.
# Responsibilities: Validate local update tickets, observe setup and restart.
# File: winapp/uploader/update_helper.py
# Repository: https://github.com/klusik/PHP_gallery
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Minimal independent update helper; never imports uploader configuration."""
import ctypes

from uploader.config import APP_PROFILE_NAME, load_ui_language
from uploader.localization import configure_language, gettext, gettext_noop
from ctypes import wintypes
from dataclasses import asdict, dataclass
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import threading
import time
import uuid
import queue
from typing import Callable, Protocol, TYPE_CHECKING

from uploader.self_update import _version
from uploader.gui_backend import public_executable
from uploader.windows_file_identity import (
    FILE_ATTRIBUTE_REPARSE_POINT as UPDATE_REPARSE_POINT_ATTRIBUTE,
    reject_reparse_path,
)

try:
    import tkinter as tk
    from tkinter import ttk
except ImportError:
    tk = None

if tk is not None:
    from uploader.ui_theme import Button

try:
    import winreg
except ImportError:
    winreg = None

if TYPE_CHECKING:
    from uploader.self_update import ReleaseAsset

# Allow the app to finish its existing graceful shutdown before setup starts.
# Type: float; Units: seconds; Scope: one helper installation.
# Consumers: perform_update. Rationale: preserve the existing 30-second wait.
SHUTDOWN_TIMEOUT_SECONDS = 30.0
# Poll exact-target process state without busy-waiting.
# Type: float; Units: seconds; Scope: shutdown observation.
# Consumers: perform_update. Rationale: 0.1 seconds retains responsive handoff.
SHUTDOWN_POLL_SECONDS = 0.1
# Bound the uploader-to-helper proceed handshake before any installation.
# Type: float; Units: seconds; Scope: one update session.
# Consumers: main worker. Rationale: preserve the existing 25-second handshake.
HANDSHAKE_TIMEOUT_SECONDS = 25.0
# Purpose: Keep an unresponsive helper from delaying graceful app shutdown.
# Type: float. Units: seconds. Scope: one staged update helper launch.
# Consumers: launch_helper. Rationale: retain the existing 20-second bound.
HELPER_READY_TIMEOUT_SECONDS = 20.0
# Yield between proceed/cancel marker observations.
# Type: float; Units: seconds; Scope: one update session.
# Consumers: main worker. Rationale: 0.05 seconds avoids a spin loop.
HANDSHAKE_POLL_SECONDS = 0.05
# Read background progress at a modest Tk cadence.
# Type: int; Units: milliseconds; Scope: helper progress window.
# Consumers: main poll. Rationale: 100 ms keeps installation feedback responsive.
HELPER_POLL_MS = 100

# Purpose: Bound compatibility inspection of old updater sessions at startup.
# Type: int. Units: session directories. Scope: one old-client continuation.
# Consumers: find_legacy_update_ticket. Rationale: 128 exceeds ordinary retained
# updater history without allowing an unbounded startup directory scan.
LEGACY_UPDATE_SESSION_LIMIT = 128
# Preserve the old fixed-size ticket reader's bounded local metadata limit.
# Type: int; Units: bytes; Scope: one update-ticket read.
# Consumers: load_ticket. Rationale: tickets contain only fixed identity fields.
UPDATE_TICKET_MAX_BYTES = 4096
# Refuse marker files with any bytes beyond the old helper's exact sentinel.
# Type: int; Units: bytes; Scope: one legacy ready/proceed marker read.
# Consumers: _legacy_marker_is_fresh. Rationale: old helpers write one byte.
LEGACY_UPDATE_MARKER_MAX_BYTES = 1
# Purpose: Bound a staged helper runtime and installed recovery fingerprint.
# Type: int. Units: bytes. Scope: executable, _internal and license runtime trees.
# Consumers: stage_update_helper_runtime, _installed_runtime_fingerprint.
# Rationale: 1 GiB accommodates the complete shipped runtime with build room.
UPDATE_RUNTIME_PROOF_MAX_BYTES = 1024 * 1024 * 1024
# Purpose: Bound runtime directory enumeration before staging or recovery.
# Type: int. Units: filesystem entries. Scope: one installed runtime tree.
# Consumers: stage_update_helper_runtime, _installed_runtime_fingerprint.
# Rationale: 4096 covers the shipped PyInstaller runtime and notices.
UPDATE_RUNTIME_PROOF_MAX_ENTRIES = 4096
# Purpose: Stream runtime proof without retaining large DLLs in memory.
# Type: int. Units: bytes. Scope: one runtime digest read.
# Consumers: _installed_runtime_fingerprint. Rationale: 1 MiB matches setup digest I/O.
UPDATE_RUNTIME_PROOF_CHUNK_BYTES = 1024 * 1024
# Purpose: Name PyInstaller's relocatable onedir dependency tree.
# Type: str. Units: sibling directory name. Scope: the common installed app.
# Consumers: staging, cleanup and failure recovery. Rationale: match PyInstaller layout.
PYINSTALLER_INTERNAL_DIRECTORY = "_internal"
# Purpose: Include app-local legal notices in the relocated helper copy.
# Type: tuple[str, ...]. Units: sibling directory names. Scope: staged runtimes.
# Consumers: stage_update_helper_runtime, _installed_runtime_fingerprint.
# Rationale: preserve the complete canonical installation layout.
UPDATE_RUNTIME_SIBLING_DIRECTORIES = (PYINSTALLER_INTERNAL_DIRECTORY, "licenses")


@dataclass(frozen=True)
class UpdateTicket:
    """Locally generated update identity and bounded, session-owned paths."""
    session_id: str
    target_version: str
    expected_sha256: str
    expected_size: int
    installer_path: str
    installed_executable: str
    schema: int = 1
    resume_capture: bool = True


Progress = Callable[[tuple[int, int] | None], None]

# Load native Windows libraries only from System32, never the helper extraction directory.
LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x00000800
# Purpose: Recognize Windows cancelling the setup elevation request.
# Type: int. Units: Win32 error code. Scope: ShellExecuteExW failure.
# Consumers: WindowsBackend.install. Rationale: ERROR_CANCELLED (1223) retains
# the distinct operator cancellation outcome for the guarded restart.
ERROR_CANCELLED = 1223
# Purpose: Wait on the actual elevated installer process.
# Type: int. Units: Win32 ShellExecute flag. Scope: one setup launch.
# Consumers: WindowsBackend.install. Rationale: 0x40 requests the process handle.
SEE_MASK_NOCLOSEPROCESS = 0x00000040
# Purpose: Route ordinary Shell launch errors to the update helper's feedback.
# Type: int. Units: Win32 ShellExecute flag. Scope: one setup launch.
# Consumers: WindowsBackend.install. Rationale: 0x400 avoids a separate Shell
# error dialog holding the handoff open. Windows still owns security prompts.
SEE_MASK_FLAG_NO_UI = 0x00000400


class Backend(Protocol):
    """Injectable privilege and process boundary for deterministic tests."""
    def target_running(self, executable: str) -> bool:
        """Check every exact installed executable process."""
        ...

    def install(self, ticket: UpdateTicket, progress: Progress) -> int:
        """Run elevated setup and return its actual result."""
        ...

    def version(self, executable: str) -> str:
        """Read the installed PE resource."""
        ...

    def restart(self, executable: str, *, update_session: str) -> None:
        """Start the original user's app with its exact update checkpoint nonce.

        Args:
            executable (str): Verified installed application path.
            update_session (str): Non-secret helper session identifier.

        Returns:
            None: Starts only the verified installation under the original user.
        """
        ...


class SetupLaunchError(OSError):
    """Identify a ShellExecute refusal that proves setup never started."""


def updates_root() -> Path:
    """Return the current user's fixed helper storage owner."""
    return Path(os.environ["LOCALAPPDATA"]) / "PHPGalleryUploader" / "updates"


def installed_identity(executable_path: Path | None = None) -> Path:
    """Require the frozen executable to match this product's HKLM installation.

    Args:
        executable_path (Path | None): Candidate executable or the current
            process executable when omitted.

    Returns:
        Path: Confirmed installed executable path under the registered root.

    Raises:
        RuntimeError: The installed executable or registry cannot be verified.
    """
    if sys.platform != "win32" or winreg is None or not getattr(sys, "frozen", False):
        raise RuntimeError("Self-update requires the installed Windows application")
    executable = (executable_path or public_executable()).resolve()
    if executable.name != "PHPGalleryUploader.exe":
        raise RuntimeError("Self-update requires the registered installed executable")
    key_name = (r"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"
                "\\{E7CEB8DB-A422-44D2-8114-FAEFA25E62DC}_is1")
    try:
        with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, key_name, 0,
                            winreg.KEY_READ | winreg.KEY_WOW64_64KEY) as key:
            directory, kind = winreg.QueryValueEx(key, "InstallLocation")
        if kind not in (winreg.REG_SZ, winreg.REG_EXPAND_SZ) or not isinstance(directory, str):
            raise RuntimeError("Invalid registered install location")
        registered = Path(os.path.expandvars(directory)).resolve() / "PHPGalleryUploader.exe"
        reject_reparse_path(registered)
        if os.path.normcase(str(registered)) != os.path.normcase(str(executable)):
            raise RuntimeError("This executable is not the registered installation")
    except OSError as exc:
        raise RuntimeError("Registered uploader installation was not found") from exc
    return executable


def launch_update(installer_path: Path, asset: "ReleaseAsset") -> subprocess.Popen:
    """Stage a verified asset and confirm helper readiness before UI shutdown."""
    executable = installed_identity()
    ticket_path = prepare_helper(installer_path, asset.version, asset.sha256, asset.size, executable)
    return launch_helper(ticket_path)


def validate_ticket(ticket: UpdateTicket, ticket_path: Path) -> UpdateTicket:
    """Reject malformed tickets and paths escaping their private session.

    Args:
        ticket (UpdateTicket): Parsed local installer identity and resume policy.
        ticket_path (Path): Exact ticket.json beneath its fixed update session.

    Returns:
        UpdateTicket: Same validated ticket, including explicit capture intent.

    Raises:
        ValueError: Ticket fields, paths or installation identity are invalid.
    """
    if any(type(value) is not str for value in (ticket.session_id, ticket.target_version, ticket.expected_sha256,
                                               ticket.installer_path, ticket.installed_executable)):
        raise ValueError("Invalid ticket field types")
    if type(ticket.schema) is not int or ticket.schema != 1:
        raise ValueError("Unsupported update ticket schema")
    if type(ticket.resume_capture) is not bool:
        raise ValueError('Invalid capture continuation policy')
    reject_reparse_path(ticket_path)
    reject_reparse_path(Path(ticket.installer_path))
    reject_reparse_path(Path(ticket.installed_executable))
    if not re.fullmatch(r"[0-9a-f]{32}", ticket.session_id):
        raise ValueError("Invalid update session")
    session = updates_root().resolve() / ticket.session_id
    if ticket_path.resolve() != session / "ticket.json":
        raise ValueError("Invalid ticket location")
    if Path(ticket.installer_path).resolve() != session / "setup.exe":
        raise ValueError("Invalid installer location")
    executable = Path(ticket.installed_executable)
    if not executable.is_absolute() or executable.name != "PHPGalleryUploader.exe" or session.is_relative_to(executable.parent.resolve()):
        raise ValueError("Invalid installed application location")
    if not re.fullmatch(r"\d+\.\d+\.\d+", ticket.target_version) or not re.fullmatch(r"[0-9a-f]{64}", ticket.expected_sha256):
        raise ValueError("Invalid update identity")
    if type(ticket.expected_size) is not int or not 0 < ticket.expected_size <= 1024 * 1024 * 1024:
        raise ValueError("Invalid installer size")
    return ticket


def load_ticket(path: Path) -> UpdateTicket:
    """Read one stable, strictly shaped local ticket without following links.

    Args:
        path (Path): Private update session's ticket document.

    Returns:
        UpdateTicket: Exact validated seven-field local installer identity.

    Raises:
        ValueError: The document is malformed, redirected, replaced or oversized.
        OSError: The ticket cannot be safely opened or inspected.
    """
    reject_reparse_path(path)
    before = path.lstat()
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or path.is_symlink()
            or (getattr(before, "st_file_attributes", 0)
                & UPDATE_REPARSE_POINT_ATTRIBUTE)):
        raise ValueError("Invalid ticket file identity")
    with path.open("rb") as source:
        opened = os.fstat(source.fileno())
        if not os.path.samestat(before, opened):
            raise ValueError("Ticket changed before it was read")
        data = source.read(UPDATE_TICKET_MAX_BYTES + 1)
        after_open = os.fstat(source.fileno())
    after = path.lstat()
    if (len(data) > UPDATE_TICKET_MAX_BYTES
            or len(data) != after_open.st_size
            or not os.path.samestat(opened, after_open)
            or not os.path.samestat(after_open, after)
            or before.st_size != after.st_size
            or before.st_mtime_ns != after.st_mtime_ns
            or after.st_nlink != 1 or not stat.S_ISREG(after.st_mode)
            or (getattr(after, "st_file_attributes", 0)
                & UPDATE_REPARSE_POINT_ATTRIBUTE)):
        raise ValueError("Ticket changed while being read")
    raw = json.loads(
        data.decode("utf-8"),
        object_pairs_hook=_strict_ticket_object,
        parse_constant=_reject_ticket_constant,
    )
    required = {"session_id", "target_version", "expected_sha256", "expected_size",
                "installer_path", "installed_executable", "schema"}
    if (not isinstance(raw, dict)
            or set(raw) not in (required, required | {'resume_capture'})):
        raise ValueError("Invalid ticket fields")
    return validate_ticket(UpdateTicket(**raw), path)


def bind_capture_resume(ticket_path: Path, resume_capture: bool) -> None:
    """Bind active-versus-idle restart intent before the helper is launched.

    Args:
        ticket_path (Path): Validated private helper ticket owned by this handoff.
        resume_capture (bool): True only for an exact prepared Watcher continuation.

    Returns:
        None: Durably publishes the selected policy without modifying any checkpoint.

    Raises:
        ValueError: The ticket changed or the policy is not a boolean.
        OSError: Durable ticket publication fails.
    """
    ticket = load_ticket(ticket_path)
    values = asdict(ticket)
    values['resume_capture'] = resume_capture
    validate_ticket(UpdateTicket(**values), ticket_path)
    temporary = ticket_path.with_name('ticket-' + uuid.uuid4().hex + '.tmp')
    try:
        with temporary.open('x', encoding='utf-8') as handle:
            json.dump(values, handle)
            handle.flush()
            os.fsync(handle.fileno())
        if load_ticket(ticket_path) != ticket:
            raise ValueError('Update ticket changed during policy binding.')
        os.replace(temporary, ticket_path)
        if asdict(load_ticket(ticket_path)) != values:
            raise ValueError('Update restart policy was not confirmed.')
    finally:
        temporary.unlink(missing_ok=True)


def _strict_ticket_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    """Reject duplicate JSON fields in the fixed updater ticket format.

    Args:
        pairs (list[tuple[str, object]]): Ordered key/value pairs from the
            standard JSON decoder.

    Returns:
        dict[str, object]: Unique-key mapping accepted by the ticket schema.

    Raises:
        ValueError: A key appears more than once in the same object.
    """
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate update ticket field")
        result[key] = value
    return result


def _reject_ticket_constant(value: str) -> None:
    """Reject non-standard JSON NaN and infinity values in updater tickets.

    Args:
        value (str): Non-finite token passed by the JSON decoder.

    Returns:
        None: This callback always raises instead of returning a value.

    Raises:
        ValueError: The token is not valid JSON.
    """
    raise ValueError("Invalid update ticket number")


def _legacy_marker_is_fresh(
    session: Path, name: str, ticket_mtime: float, now: float,
    maximum_age: float,
) -> bool:
    """Verify one old-helper sentinel's content, age and file identity.

    Args:
        session (Path): Exact private old-helper session directory.
        name (str): Fixed ``ready`` or ``proceed`` marker filename.
        ticket_mtime (float): Validated old ticket modification time.
        now (float): One captured current Unix time for this candidate.
        maximum_age (float): Maximum marker age admitted by the continuation.

    Returns:
        bool: True only for a fresh, exact one-byte marker owned by this session.
    """
    if name not in ("ready", "proceed"):
        return False
    marker = session / name
    try:
        reject_reparse_path(marker)
        before = marker.lstat()
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
                or marker.is_symlink()
                or (getattr(before, "st_file_attributes", 0)
                    & UPDATE_REPARSE_POINT_ATTRIBUTE)
                or before.st_mtime < ticket_mtime
                or not 0.0 <= now - before.st_mtime <= maximum_age):
            return False
        with marker.open("rb") as source:
            opened = os.fstat(source.fileno())
            if not os.path.samestat(before, opened):
                return False
            value = source.read(LEGACY_UPDATE_MARKER_MAX_BYTES + 1)
            after_open = os.fstat(source.fileno())
        after = marker.lstat()
        return (
            value == b"1"
            and len(value) == before.st_size
            and before.st_mtime_ns == after.st_mtime_ns
            and os.path.samestat(opened, after_open)
            and os.path.samestat(after_open, after)
            and before.st_size == after.st_size
            and after.st_nlink == 1 and stat.S_ISREG(after.st_mode)
            and not (getattr(after, "st_file_attributes", 0)
                     & UPDATE_REPARSE_POINT_ATTRIBUTE)
        )
    except (OSError, ValueError):
        return False


def find_legacy_update_ticket(
    current_version: str, previous_version: str, maximum_age: float,
) -> UpdateTicket | None:
    """Recognize one verified old-helper update without assuming it succeeded.

    Old helpers restart without a nonce. This read-only compatibility path
    requires an actual installed version transition, the exact registered
    executable, fresh ready/proceed markers and all original verified installer
    bytes. A completed marker alone is insufficient because failures write it
    too. The flight owner separately verifies active, unambiguous saved work.

    Args:
        current_version (str): Running application's canonical version.
        previous_version (str): Installed version captured from old updater state.
        maximum_age (float): Maximum permitted ticket age in seconds, supplied
            by the flight continuation lifetime policy.

    Returns:
        UpdateTicket | None: One exact validated handoff, or None when absent,
            ambiguous, expired, unsupported or not running installed Windows.
    """

    try:
        if (_version(previous_version) >= _version(current_version)
                or not 0.0 < maximum_age < float('inf')):
            return None
        executable = installed_identity()
        if WindowsBackend().version(str(executable)) != current_version:
            return None
        root = updates_root()
        reject_reparse_path(root)
        matches: list[UpdateTicket] = []
        for index, session in enumerate(root.iterdir()):
            if index >= LEGACY_UPDATE_SESSION_LIMIT:
                return None
            if re.fullmatch(r'[0-9a-f]{32}', session.name) is None:
                continue
            path = session / 'ticket.json'
            reject_reparse_path(path)
            if not path.is_file():
                continue
            ticket_info = path.lstat()
            if (not stat.S_ISREG(ticket_info.st_mode)
                    or ticket_info.st_nlink != 1
                    or path.is_symlink()
                    or (getattr(ticket_info, "st_file_attributes", 0)
                        & UPDATE_REPARSE_POINT_ATTRIBUTE)):
                continue
            now = time.time()
            age = now - ticket_info.st_mtime
            if not 0.0 <= age <= maximum_age:
                continue
            ticket = load_ticket(path)
            if (ticket.target_version != current_version
                    or os.path.normcase(ticket.installed_executable)
                    != os.path.normcase(str(executable))):
                continue
            if not all(
                _legacy_marker_is_fresh(
                    session, name, ticket_info.st_mtime, now, maximum_age,
                )
                for name in ("ready", "proceed")
            ):
                continue
            verify_ticket_installer(ticket)
            matches.append(ticket)
        return matches[0] if len(matches) == 1 else None
    except (OSError, ValueError, RuntimeError):
        return None


def verify_ticket_installer(ticket: UpdateTicket) -> None:
    """Rehash all installer bytes immediately before execution."""
    path = Path(ticket.installer_path)
    if path.stat().st_size != ticket.expected_size:
        raise ValueError("Installer size changed")
    digest = hashlib.sha256()
    counted = 0
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
            counted += len(chunk)
            if counted > ticket.expected_size:
                raise ValueError("Installer size changed")
    if counted != ticket.expected_size or digest.hexdigest() != ticket.expected_sha256:
        raise ValueError("Installer verification failed")


def _remove_staged_runtime_entry(path: Path) -> None:
    """Remove one bounded helper-owned file or directory without following links.

    Args:
        path (Path): Exact helper runtime entry beneath a completed session.
    Returns:
        None: Removes only regular files and ordinary directories.
    Raises:
        OSError: The entry cannot be safely removed or exceeds the cleanup bound.
        ValueError: A redirected, linked or special entry is encountered.
    """
    if not path.exists() and not path.is_symlink():
        return
    pending: list[tuple[Path, bool]] = [(path, False)]
    entries = 0
    while pending:
        current, visited = pending.pop()
        reject_reparse_path(current)
        info = current.lstat()
        if visited:
            current.rmdir()
            continue
        entries += 1
        if entries > UPDATE_RUNTIME_PROOF_MAX_ENTRIES:
            raise OSError("Staged helper cleanup exceeds its entry limit.")
        if stat.S_ISDIR(info.st_mode):
            children = sorted(current.iterdir(), reverse=True)
            if entries + len(pending) + len(children) > UPDATE_RUNTIME_PROOF_MAX_ENTRIES:
                raise OSError("Staged helper cleanup exceeds its entry limit.")
            pending.append((current, True))
            pending.extend((child, False) for child in children)
            continue
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise ValueError("Staged helper cleanup refused a non-owned file.")
        current.unlink()


def _cleanup_staged_helper_runtime(helper: Path) -> None:
    """Remove only the known copied helper executable and runtime siblings.

    Args:
        helper (Path): Exact staged ``helper.exe`` in its owned session.
    Returns:
        None: Removes the executable, _internal tree and optional licenses tree.
    Raises:
        OSError: Windows cannot remove a copied runtime file still in use.
        ValueError: A staged entry was redirected or is not an ordinary file.
    """
    for name in (helper.name, *UPDATE_RUNTIME_SIBLING_DIRECTORIES):
        _remove_staged_runtime_entry(helper.parent / name)


def stage_update_helper_runtime(
    installed_exe: Path, helper: Path, *, allow_legacy_onefile: bool = False,
) -> dict[str, int]:
    """Copy an installed PyInstaller runtime into one private helper session.

    The normal onedir layout copies the executable, its adjacent ``_internal``
    tree, and optional app-local licenses. The onefile exception is solely for
    the explicitly pinned historical 1.7.1 public installer qualification.
    Copies are independent files; no installed runtime file is linked or moved.

    Args:
        installed_exe (Path): Exact installed or staged canonical launcher.
        helper (Path): New session path named ``helper.exe``.
        allow_legacy_onefile (bool): Permit a missing ``_internal`` for the
            verified historical onefile source only.
    Returns:
        dict[str, int]: Copied byte count, entry count and monotonic duration.
    Raises:
        OSError: Copying is unavailable or any byte/entry bound is exceeded.
        ValueError: A source or destination is redirected, linked or special.
        RuntimeError: The canonical onedir runtime is incomplete or occupied.
    """
    source = Path(installed_exe)
    target = Path(helper)
    if (type(allow_legacy_onefile) is not bool
            or target.name != "helper.exe"
            or not target.parent.is_dir()):
        raise ValueError("Helper runtime paths or compatibility mode are invalid.")
    started_at = time.monotonic()
    reject_reparse_path(source)
    reject_reparse_path(target.parent)
    try:
        if target.parent.resolve().is_relative_to(source.parent.resolve()):
            raise ValueError("Helper runtime destination cannot be inside the installed runtime.")
    except OSError as error:
        raise ValueError("Helper runtime roots cannot be resolved safely.") from error
    source_info = source.lstat()
    if (source.name != "PHPGalleryUploader.exe"
            or not stat.S_ISREG(source_info.st_mode) or source_info.st_nlink != 1
            or getattr(source_info, "st_file_attributes", 0)
            & UPDATE_REPARSE_POINT_ATTRIBUTE):
        raise ValueError("Installed launcher is not an ordinary owned file.")
    internal = source.parent / PYINSTALLER_INTERNAL_DIRECTORY
    internal_present = internal.exists() or internal.is_symlink()
    if not internal_present and not allow_legacy_onefile:
        raise RuntimeError("Installed onedir runtime is missing _internal.")
    if internal_present and not internal.is_dir():
        raise ValueError("Installed _internal runtime is not an ordinary directory.")
    sources = [(source, target)]
    if internal_present:
        sources.append((internal, target.parent / PYINSTALLER_INTERNAL_DIRECTORY))
    license_source = source.parent / "licenses"
    if license_source.exists() or license_source.is_symlink():
        if not license_source.is_dir():
            raise ValueError("Installed licenses runtime is not an ordinary directory.")
        sources.append((license_source, target.parent / "licenses"))
    destinations = (
        target,
        target.parent / PYINSTALLER_INTERNAL_DIRECTORY,
        target.parent / "licenses",
    )
    if any(destination.exists() or destination.is_symlink() for destination in destinations):
        raise RuntimeError("Helper runtime destination is already occupied.")

    copied_bytes = 0
    copied_entries = 0
    directory_snapshots: dict[Path, os.stat_result] = {}
    try:
        for source_root, destination_root in sources:
            pending = [(source_root, destination_root)]
            while pending:
                current_source, current_destination = pending.pop()
                reject_reparse_path(current_source)
                info = current_source.lstat()
                copied_entries += 1
                if copied_entries > UPDATE_RUNTIME_PROOF_MAX_ENTRIES:
                    raise OSError("Helper runtime exceeds its entry limit.")
                if stat.S_ISDIR(info.st_mode):
                    if (getattr(info, "st_file_attributes", 0)
                            & UPDATE_REPARSE_POINT_ATTRIBUTE):
                        raise ValueError("Helper runtime contains a redirected directory.")
                    directory_snapshots[current_source] = info
                    current_destination.mkdir()
                    children = sorted(current_source.iterdir(), reverse=True)
                    if (copied_entries + len(pending) + len(children)
                            > UPDATE_RUNTIME_PROOF_MAX_ENTRIES):
                        raise OSError("Helper runtime exceeds its entry limit.")
                    pending.extend(
                        (child, current_destination / child.name)
                        for child in children
                    )
                    continue
                if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                        or getattr(info, "st_file_attributes", 0)
                        & UPDATE_REPARSE_POINT_ATTRIBUTE):
                    raise ValueError("Helper runtime contains a linked or special file.")
                if info.st_size > UPDATE_RUNTIME_PROOF_MAX_BYTES - copied_bytes:
                    raise OSError("Helper runtime exceeds its byte limit.")
                current_destination.parent.mkdir(parents=True, exist_ok=True)
                reject_reparse_path(current_destination.parent)
                digest = hashlib.sha256()
                counted = 0
                with current_source.open("rb") as reader:
                    opened = os.fstat(reader.fileno())
                    if not os.path.samestat(info, opened):
                        raise ValueError("Helper runtime source changed before copy.")
                    with current_destination.open("xb") as writer:
                        for chunk in iter(
                            lambda: reader.read(UPDATE_RUNTIME_PROOF_CHUNK_BYTES), b"",
                        ):
                            counted += len(chunk)
                            copied_bytes += len(chunk)
                            if (counted > info.st_size
                                    or copied_bytes > UPDATE_RUNTIME_PROOF_MAX_BYTES):
                                raise OSError("Helper runtime exceeds its byte limit.")
                            digest.update(chunk)
                            writer.write(chunk)
                    opened_after = os.fstat(reader.fileno())
                latest = current_source.lstat()
                if (counted != info.st_size or opened_after.st_size != info.st_size
                        or opened_after.st_mtime_ns != info.st_mtime_ns
                        or not os.path.samestat(info, latest)):
                    raise ValueError("Helper runtime source changed during copy.")
                copied_info = current_destination.lstat()
                if (not stat.S_ISREG(copied_info.st_mode)
                        or copied_info.st_nlink != 1
                        or copied_info.st_size != info.st_size):
                    raise ValueError("Staged helper file identity is invalid.")
                copied_digest = hashlib.sha256()
                with current_destination.open("rb") as staged:
                    for chunk in iter(
                        lambda: staged.read(UPDATE_RUNTIME_PROOF_CHUNK_BYTES), b"",
                    ):
                        copied_digest.update(chunk)
                if copied_digest.digest() != digest.digest():
                    raise OSError("Staged helper file did not preserve source bytes.")
        for directory, before in directory_snapshots.items():
            after = directory.lstat()
            if (not os.path.samestat(before, after)
                    or before.st_mtime_ns != after.st_mtime_ns):
                raise ValueError("Helper runtime directory changed during copy.")
    except BaseException:
        try:
            _cleanup_staged_helper_runtime(target)
        except (OSError, ValueError):
            pass
        raise
    return {
        "runtime_bytes": copied_bytes,
        "runtime_entries": copied_entries,
        "duration_ms": max(0, int((time.monotonic() - started_at) * 1000)),
    }


def prepare_helper(installer_path: Path, target_version: str, expected_sha256: str,
                   expected_size: int, installed_executable: Path | None = None) -> Path:
    """Copy the verified setup and stable launcher to the private helper session.

    Args:
        installer_path (Path): Current verified download.
        target_version (str): Exact published next WinApp version.
        expected_sha256 (str): Published lowercase installer digest.
        expected_size (int): Published installer length in bytes.
        installed_executable (Path | None): Verified stable launcher when supplied.
    Returns:
        Path: Prepared ticket outside both installed desktop runtimes.
    """
    if not getattr(sys, "frozen", False):
        raise RuntimeError("Self-update requires the installed standalone application")
    executable = installed_identity(installed_executable)
    clean_completed_sessions()
    session_id = uuid.uuid4().hex
    session = updates_root() / session_id
    session.mkdir(parents=True, exist_ok=False)
    ticket = UpdateTicket(session_id, target_version, expected_sha256, expected_size,
                          str((session / "setup.exe").resolve()), str(executable))
    path = session / "ticket.json"
    try:
        validate_ticket(ticket, path)
        shutil.copyfile(installer_path, ticket.installer_path)
        verify_ticket_installer(ticket)
        stage_update_helper_runtime(executable, session / "helper.exe")
        path.write_text(json.dumps(asdict(ticket)), encoding="utf-8")
    except Exception:
        (session / "completed").write_text("1", encoding="ascii")
        clean_completed_sessions()
        raise
    return path


def clean_completed_sessions() -> None:
    """Remove only known files in explicitly completed updater-owned sessions.

    Returns:
        None: Removes owned unlocked sessions. Active or unknown entries remain.
    """
    root = updates_root()
    reject_reparse_path(root)
    if not root.exists():
        return
    for session in root.iterdir():
        if not re.fullmatch(r"[0-9a-f]{32}", session.name) or session.is_symlink():
            continue
        try:
            reject_reparse_path(session)
            if not (session / "completed").is_file():
                continue
            for name in ("setup.exe", "setup.log", "ready", "proceed", "cancel", "ticket.json"):
                candidate = session / name
                reject_reparse_path(candidate)
                candidate.unlink(missing_ok=True)
            _cleanup_staged_helper_runtime(session / "helper.exe")
            completed = session / "completed"
            reject_reparse_path(completed)
            completed.unlink(missing_ok=True)
            session.rmdir()
        except (OSError, ValueError):
            # A still-running copied helper is locked on Windows; a later
            # preparation can retry without touching its active session.
            continue


def launch_helper(
    ticket_path: Path, timeout: float = HELPER_READY_TIMEOUT_SECONDS,
) -> subprocess.Popen[bytes]:
    """Wait for helper readiness before the uploader begins graceful shutdown.

    Args:
        ticket_path (Path): Exact validation ticket under a private helper session.
        timeout (float): Seconds the uploader can wait for the ready marker.
    Returns:
        subprocess.Popen[bytes]: The running update helper admitted to proceed.
    Raises:
        RuntimeError: The helper timed out or exited without publishing readiness.
        OSError: Process startup or session marker publication failed.
    """
    environment = os.environ.copy()
    environment["PYINSTALLER_RESET_ENVIRONMENT"] = "1"
    process = subprocess.Popen(
        [str(ticket_path.parent / "helper.exe"), "--self-update-helper", str(ticket_path)],
        env=environment,
    )
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if (ticket_path.parent / "ready").is_file():
            (ticket_path.parent / "proceed").write_text("1", encoding="ascii")
            return process
        if process.poll() is not None:
            break
        time.sleep(HANDSHAKE_POLL_SECONDS)
    (ticket_path.parent / "cancel").write_text("1", encoding="ascii")
    # The helper waits for proceed before it can launch setup. A timeout cannot
    # leave an autonomous installation pending after the uploader resumes.
    raise RuntimeError("Update helper did not become ready; uploader remains open")


def installer_arguments(ticket: UpdateTicket) -> list[str]:
    """Supply only locally derived Inno switches and exact installation path."""
    session = Path(ticket.installer_path).parent
    return ["/VERYSILENT", "/SUPPRESSMSGBOXES", "/NORESTART", "/RESTARTEXITCODE=3010",
            "/SP-", "/NORESTARTAPPLICATIONS", "/SELFUPDATE=1",
            "/DIR=" + str(Path(ticket.installed_executable).parent),
            "/LOG=" + str(session / "setup.log"), "/UPDATESTATUS=" + ticket.session_id]


def parse_status(data: bytes) -> tuple[int, int] | None:
    """Parse a small advisory installer status record."""
    try:
        text = data.decode("ascii")
        if len(text) > 32 or not re.fullmatch(r"[0-4] [0-9]{1,3}", text):
            return None
        phase, percent = map(int, text.split())
        return (phase, percent) if percent <= 100 else None
    except (UnicodeError, ValueError):
        return None


class WindowsBackend:
    """Windows process APIs loaded only when the standalone helper runs."""
    def __init__(self) -> None:
        """Declare native argument widths before touching any process handles."""
        self.kernel = ctypes.WinDLL("kernel32.dll", use_last_error=True, winmode=LOAD_LIBRARY_SEARCH_SYSTEM32)
        self.kernel.OpenProcess.restype = wintypes.HANDLE
        self.kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
        self.kernel.CloseHandle.argtypes = [wintypes.HANDLE]
        self.kernel.WaitForSingleObject.argtypes = [wintypes.HANDLE, wintypes.DWORD]
        self.kernel.QueryFullProcessImageNameW.argtypes = [wintypes.HANDLE, wintypes.DWORD, wintypes.LPWSTR, ctypes.POINTER(wintypes.DWORD)]
        self.kernel.QueryFullProcessImageNameW.restype = wintypes.BOOL
        self.kernel.WaitForSingleObject.restype = wintypes.DWORD
        self.kernel.CloseHandle.restype = wintypes.BOOL
        self.kernel.GetExitCodeProcess.argtypes = [wintypes.HANDLE, ctypes.POINTER(wintypes.DWORD)]
        self.kernel.GetExitCodeProcess.restype = wintypes.BOOL

    def target_running(self, executable: str) -> bool:
        """Check the exact single installed launcher process path.

        Args:
            executable (str): Verified registered stable launcher path.
        Returns:
            bool: A process belonging to this installation still holds its runtime.
        """
        target = os.path.normcase(os.path.abspath(executable))
        class Entry(ctypes.Structure):
            """Native PROCESSENTRY32W with pointer-width heap identity."""
            _fields_ = [("size", wintypes.DWORD), ("usage", wintypes.DWORD), ("pid", wintypes.DWORD),
                        ("heap", ctypes.c_size_t), ("module", wintypes.DWORD), ("threads", wintypes.DWORD),
                        ("parent", wintypes.DWORD), ("priority", wintypes.LONG), ("flags", wintypes.DWORD), ("exe", wintypes.WCHAR * 260)]
        self.kernel.CreateToolhelp32Snapshot.restype = wintypes.HANDLE
        self.kernel.CreateToolhelp32Snapshot.argtypes = [wintypes.DWORD, wintypes.DWORD]
        self.kernel.Process32FirstW.argtypes = [wintypes.HANDLE, ctypes.POINTER(Entry)]
        self.kernel.Process32FirstW.restype = wintypes.BOOL
        self.kernel.Process32NextW.argtypes = [wintypes.HANDLE, ctypes.POINTER(Entry)]
        self.kernel.Process32NextW.restype = wintypes.BOOL
        snapshot = self.kernel.CreateToolhelp32Snapshot(2, 0)
        if snapshot == ctypes.c_void_p(-1).value:
            raise OSError("Cannot inspect running uploader")
        entry = Entry()
        entry.size = ctypes.sizeof(entry)
        try:
            more = self.kernel.Process32FirstW(snapshot, ctypes.byref(entry))
            while more:
                if entry.exe.lower() == "phpgalleryuploader.exe":
                    handle = self.kernel.OpenProcess(0x1000 | 0x100000, False, entry.pid)
                    if not handle:
                        raise OSError("Cannot verify uploader process identity")
                    try:
                        buffer = ctypes.create_unicode_buffer(32768)
                        length = wintypes.DWORD(len(buffer))
                        if not self.kernel.QueryFullProcessImageNameW(handle, 0, buffer, ctypes.byref(length)):
                            raise OSError("Cannot verify uploader executable path")
                        if os.path.normcase(os.path.abspath(buffer.value)) == target:
                            return True
                    finally:
                        self.kernel.CloseHandle(handle)
                more = self.kernel.Process32NextW(snapshot, ctypes.byref(entry))
            if ctypes.get_last_error() != 18:
                raise OSError("Cannot enumerate uploader processes")
            return False
        finally:
            self.kernel.CloseHandle(snapshot)

    def install(self, ticket: UpdateTicket, progress: Progress) -> int:
        """Elevate setup alone and observe its actual process exit status.

        Args:
            ticket (UpdateTicket): Verified setup identity and continuation.
            progress (Progress): Safe installer progress sink.
        Returns:
            int: Exit code from the setup process Windows launched.
        Raises:
            InterruptedError: Administrator approval was cancelled.
            SetupLaunchError: Windows rejected the setup launch.
            OSError: The launched process result could not be observed.
        """
        class ShellInfo(ctypes.Structure):
            """Native SHELLEXECUTEINFOW including the returned process handle."""
            _fields_ = [("cbSize", wintypes.DWORD), ("fMask", wintypes.ULONG), ("hwnd", wintypes.HWND),
                        ("lpVerb", wintypes.LPCWSTR), ("lpFile", wintypes.LPCWSTR), ("lpParameters", wintypes.LPCWSTR),
                        ("lpDirectory", wintypes.LPCWSTR), ("nShow", ctypes.c_int), ("hInstApp", wintypes.HINSTANCE),
                        ("lpIDList", ctypes.c_void_p), ("lpClass", wintypes.LPCWSTR), ("hkeyClass", wintypes.HKEY),
                        ("dwHotKey", wintypes.DWORD), ("hIcon", wintypes.HANDLE), ("hProcess", wintypes.HANDLE)]
        info = ShellInfo()
        info.cbSize = ctypes.sizeof(info)
        info.fMask = SEE_MASK_NOCLOSEPROCESS | SEE_MASK_FLAG_NO_UI
        info.lpVerb = "runas"
        info.lpFile = ticket.installer_path
        info.lpParameters = subprocess.list2cmdline(installer_arguments(ticket))
        info.nShow = 0
        shell = ctypes.WinDLL("shell32.dll", use_last_error=True, winmode=LOAD_LIBRARY_SEARCH_SYSTEM32)
        shell.ShellExecuteExW.argtypes = [ctypes.POINTER(ShellInfo)]
        shell.ShellExecuteExW.restype = wintypes.BOOL
        pipe = self.create_status_pipe(ticket.session_id)
        if not shell.ShellExecuteExW(ctypes.byref(info)):
            error = ctypes.get_last_error()
            if pipe:
                self.kernel.CloseHandle(pipe)
            if error == ERROR_CANCELLED:
                raise InterruptedError("Administrator permission was cancelled")
            raise SetupLaunchError("Unable to start setup")
        try:
            while True:
                wait = self.kernel.WaitForSingleObject(info.hProcess, 200)
                if wait == 0:
                    break
                if wait != 258:
                    raise OSError("Cannot observe setup completion")
                if pipe:
                    progress(self.poll_status_pipe(pipe))
            code = wintypes.DWORD()
            if not self.kernel.GetExitCodeProcess(info.hProcess, ctypes.byref(code)):
                raise OSError("Unable to read setup result")
            return code.value
        finally:
            self.kernel.CloseHandle(info.hProcess)
            if pipe:
                self.kernel.CloseHandle(pipe)

    def create_status_pipe(self, session_id: str) -> int | None:
        """Open one advisory pipe with remote clients rejected and no wait I/O."""
        self.kernel.CreateNamedPipeW.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD,
                                               wintypes.DWORD, wintypes.DWORD, wintypes.DWORD,
                                               wintypes.DWORD, ctypes.c_void_p]
        self.kernel.CreateNamedPipeW.restype = wintypes.HANDLE
        handle = self.kernel.CreateNamedPipeW("\\\\.\\pipe\\PHPGalleryUploaderUpdate-" + session_id,
                                             1 | 0x80000, 1 | 8, 1, 0, 64, 0, None)
        return None if handle == ctypes.c_void_p(-1).value else handle

    def poll_status_pipe(self, handle: int) -> tuple[int, int] | None:
        """Poll a bounded numeric record; missing progress is never fatal."""
        self.kernel.ConnectNamedPipe.argtypes = [wintypes.HANDLE, ctypes.c_void_p]
        self.kernel.ConnectNamedPipe.restype = wintypes.BOOL
        self.kernel.ReadFile.argtypes = [wintypes.HANDLE, ctypes.c_void_p, wintypes.DWORD,
                                        ctypes.POINTER(wintypes.DWORD), ctypes.c_void_p]
        self.kernel.ReadFile.restype = wintypes.BOOL
        self.kernel.DisconnectNamedPipe.argtypes = [wintypes.HANDLE]
        self.kernel.DisconnectNamedPipe.restype = wintypes.BOOL
        self.kernel.ConnectNamedPipe(handle, None)
        buffer = ctypes.create_string_buffer(64)
        length = wintypes.DWORD()
        if self.kernel.ReadFile(handle, buffer, len(buffer), ctypes.byref(length), None):
            self.kernel.DisconnectNamedPipe(handle)
            return parse_status(buffer.raw[:length.value])
        if ctypes.get_last_error() == 109:
            self.kernel.DisconnectNamedPipe(handle)
        return None

    def version(self, executable: str) -> str:
        """Read fixed PE version data rather than trusting setup's exit code."""
        # The explicit extension avoids PyInstaller matching the bundled VERSION text file.
        library = ctypes.WinDLL("version.dll", use_last_error=True, winmode=LOAD_LIBRARY_SEARCH_SYSTEM32)
        library.GetFileVersionInfoSizeW.argtypes = [wintypes.LPCWSTR, ctypes.POINTER(wintypes.DWORD)]
        library.GetFileVersionInfoSizeW.restype = wintypes.DWORD
        library.GetFileVersionInfoW.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD, ctypes.c_void_p]
        library.GetFileVersionInfoW.restype = wintypes.BOOL
        library.VerQueryValueW.argtypes = [ctypes.c_void_p, wintypes.LPCWSTR, ctypes.POINTER(ctypes.c_void_p), ctypes.POINTER(wintypes.UINT)]
        library.VerQueryValueW.restype = wintypes.BOOL
        size = library.GetFileVersionInfoSizeW(executable, None)
        if not size:
            raise OSError("Installed executable version unavailable")
        data = ctypes.create_string_buffer(size)
        if not library.GetFileVersionInfoW(executable, 0, size, data):
            raise OSError("Installed executable version unavailable")
        pointer = ctypes.c_void_p()
        length = wintypes.UINT()
        if not library.VerQueryValueW(data, "\\", ctypes.byref(pointer), ctypes.byref(length)) or length.value < 52:
            raise OSError("Installed executable version unavailable")
        values = ctypes.cast(pointer, ctypes.POINTER(wintypes.DWORD))
        if values[0] != 0xFEEF04BD:
            raise OSError("Invalid installed version resource")
        if values[3] & 65535:
            raise OSError("Unexpected installed executable version revision")
        return f"{values[2] >> 16}.{values[2] & 65535}.{values[3] >> 16}"

    def restart(self, executable: str, *, update_session: str) -> None:
        """Relaunch under the original user with the ticket's capture policy.

        Args:
            executable (str): Verified registered application executable.
            update_session (str): Non-secret 128-bit updater session identifier.

        Returns:
            None: Starts the same installed app without elevation; only an
                active capture receives the exact one-use continuation nonce.

        Raises:
            ValueError: The session identifier is malformed.
            OSError: Windows cannot start the installed application.
        """
        if re.fullmatch(r'[0-9a-f]{32}', update_session) is None:
            raise ValueError('Invalid update continuation session.')
        ticket = load_ticket(updates_root() / update_session / 'ticket.json')
        if Path(ticket.installed_executable) != Path(executable):
            raise ValueError('Update restart target changed.')
        installed_identity(Path(executable))
        arguments = ([executable, '--after-update', update_session]
                     if ticket.resume_capture else [executable])
        environment = os.environ.copy()
        environment['PYINSTALLER_RESET_ENVIRONMENT'] = '1'
        subprocess.Popen(
            arguments,
            cwd=str(Path(executable).parent),
            env=environment,
        )


@dataclass(frozen=True)
class UpdateOutcome:
    """Distinguish verified/restarted installation from a required Windows reboot."""

    message: str
    verified: bool


def _installed_runtime_fingerprint(executable: Path) -> bytes | None:
    """Fingerprint the locally installed executable and onedir runtime trees.

    Args:
        executable (Path): Exact registered launcher validated by the updater.
    Returns:
        bytes | None: Digest of executable, _internal and license path bytes, or None when
            the bounded local installation cannot be verified safely.
    """
    digest = hashlib.sha256()
    pending = [executable]
    remaining = UPDATE_RUNTIME_PROOF_MAX_BYTES
    entries = 0
    try:
        reject_reparse_path(executable)
        for name in UPDATE_RUNTIME_SIBLING_DIRECTORIES:
            runtime = executable.parent / name
            # Include broken or redirected runtimes so they cannot appear absent.
            if runtime.exists() or runtime.is_symlink():
                pending.append(runtime)
        while pending:
            path = pending.pop()
            entries += 1
            if entries > UPDATE_RUNTIME_PROOF_MAX_ENTRIES:
                return None
            info = path.lstat()
            if (stat.S_ISLNK(info.st_mode)
                    or getattr(info, 'st_file_attributes', 0) & UPDATE_REPARSE_POINT_ATTRIBUTE):
                return None
            relative = path.relative_to(executable.parent).as_posix()
            digest.update(relative.encode('utf-8') + b'\0')
            if stat.S_ISDIR(info.st_mode):
                digest.update(b'd')
                children = []
                for child in path.iterdir():
                    if entries + len(pending) + len(children) >= UPDATE_RUNTIME_PROOF_MAX_ENTRIES:
                        return None
                    children.append(child)
                pending.extend(sorted(children, reverse=True))
                continue
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                    or info.st_size > remaining):
                return None
            part = hashlib.sha256()
            counted = 0
            with path.open('rb') as source:
                for chunk in iter(lambda: source.read(UPDATE_RUNTIME_PROOF_CHUNK_BYTES), b''):
                    counted += len(chunk)
                    if counted > info.st_size:
                        return None
                    part.update(chunk)
                opened = os.fstat(source.fileno())
            latest = path.lstat()
            for name in ('st_dev', 'st_ino', 'st_size', 'st_mtime_ns', 'st_mode'):
                if getattr(latest, name) != getattr(info, name):
                    return None
            # Windows derives pathname execute bits from the extension. Its
            # descriptor stat need not repeat those pathname permission bits.
            if (counted != info.st_size or opened.st_size != info.st_size
                    or opened.st_mtime_ns != info.st_mtime_ns):
                return None
            if (opened.st_ino and info.st_ino
                    and (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino)):
                return None
            remaining -= counted
            digest.update(b'f' + part.digest())
        return digest.digest()
    except (OSError, ValueError, UnicodeError):
        return None


def _restart_unchanged_installation(
    ticket: UpdateTicket, backend: Backend, original_runtime: bytes | None,
) -> None:
    """Recover the original application only after a definitive setup refusal.

    Args:
        ticket (UpdateTicket): Exact validated session and launcher identity.
        backend (Backend): Native process and restart adapter.
        original_runtime (bytes | None): Pre-setup fingerprint or unknown identity.
    Returns:
        None: Relaunches an unchanged, closed installation when it is safe.
            Unknown, changed or busy installations retain their recovery state.
    """
    if original_runtime is None:
        return
    executable = Path(ticket.installed_executable)
    try:
        if (not backend.target_running(ticket.installed_executable)
                and _installed_runtime_fingerprint(executable) == original_runtime):
            backend.restart(ticket.installed_executable, update_session=ticket.session_id)
    except (OSError, RuntimeError, ValueError):
        # Preserve the original setup result if identity or launch is unavailable.
        pass


def perform_update(ticket: UpdateTicket, backend: Backend, progress: Progress,
                   timeout: float = SHUTDOWN_TIMEOUT_SECONDS) -> UpdateOutcome:
    """Wait for graceful shutdown, run setup, and verify its resulting version.

    Args:
        ticket (UpdateTicket): Validated local installer and target identity.
        backend (Backend): Native process, installation and version adapter.
        progress (Progress): Callback receiving phase/percent pairs or None.
        timeout (float): Seconds allowed for the uploader to exit gracefully.
    Returns:
        UpdateOutcome: Verified success after matching version and submitting restart;
            a reboot requirement is returned with verified False.
    Raises:
        RuntimeError: Shutdown, installation or resulting version was not verified.
            An unmodified installed runtime may resume an exact capture after
            setup has definitively exited with an error.
        InterruptedError: Administrator approval was cancelled.
        SetupLaunchError: Windows definitively refused to start setup; an unchanged
            installed runtime may resume its exact capture before the error is shown.
    """
    deadline = time.monotonic() + timeout
    while backend.target_running(ticket.installed_executable):
        if time.monotonic() >= deadline:
            raise RuntimeError("Uploader is still running. No update was started.")
        time.sleep(SHUTDOWN_POLL_SECONDS)
    verify_ticket_installer(ticket)
    original_runtime = _installed_runtime_fingerprint(Path(ticket.installed_executable))
    try:
        code = backend.install(ticket, progress)
    except (InterruptedError, SetupLaunchError):
        _restart_unchanged_installation(ticket, backend, original_runtime)
        raise
    if code == 3010:
        return UpdateOutcome(
            gettext_noop(
                "Windows restart required. Restart Windows before opening the uploader."
            ), False,
        )
    if code != 0:
        # A definite setup exit permits comparison. A failed process wait or
        # unverified successful setup cannot prove that replacement is over.
        _restart_unchanged_installation(ticket, backend, original_runtime)
        raise RuntimeError(f"Setup exited with code {code}. The update was not verified.")
    if backend.version(ticket.installed_executable) != ticket.target_version:
        raise RuntimeError("Setup finished, but the installed version does not match. The update was not verified.")
    backend.restart(
        ticket.installed_executable, update_session=ticket.session_id,
    )
    return UpdateOutcome(f"Version {ticket.target_version} installed. WinApp launch requested.", True)


def main(ticket_path: str) -> int:
    """Open update progress and close automatically only after verified success.

    Args:
        ticket_path (str): Owned session's validated local update-ticket path.
    Returns:
        int: Zero after installation or a required reboot; one after failure
            or when the Tk runtime for the graphical helper is unavailable.
            Errors and reboot instructions remain visible until dismissed.
    """
    if tk is None:
        return 1
    profile = Path(os.environ.get("APPDATA", str(Path.home()))) / APP_PROFILE_NAME
    configure_language(load_ui_language(profile))
    path = Path(ticket_path)
    root = tk.Tk()
    root.title(gettext("PHP Gallery Uploader update"))
    root.geometry("520x150")
    text = tk.StringVar(value=gettext("Waiting for uploader to close…"))
    ttk.Label(root, textvariable=text, wraplength=490).pack(padx=15, pady=15)
    bar = ttk.Progressbar(root, maximum=100)
    bar.pack(fill="x", padx=15)
    button = Button(root, text=gettext("Close"), command=root.destroy, state="disabled")
    button.pack(pady=10)
    def prevent_close() -> None:
        """Keep the helper alive while setup or verification is in progress.

        Returns:
            None: Ignores closing until the operation reaches a terminal result.
        """
        return None

    root.protocol("WM_DELETE_WINDOW", prevent_close)
    events: queue.Queue[tuple[str, str | tuple[int, int] | UpdateOutcome | None]] = queue.Queue()
    try:
        ticket = load_ticket(path)
        # Compare the same canonical representation on both sides; Windows
        # can expose the owned session via an 8.3 short-path alias.
        if Path(sys.executable).resolve() != (path.parent / "helper.exe").resolve():
            raise ValueError("Helper is not running from its owned session")
        installed_identity(Path(ticket.installed_executable))
        backend = WindowsBackend()
        (path.parent / "ready").write_text("1", encoding="ascii")
    except Exception as exc:
        text.set(str(exc))
        button.configure(state="normal")
        root.protocol("WM_DELETE_WINDOW", root.destroy)
        root.mainloop()
        return 1
    exit_status = 1

    def forward_progress(value: tuple[int, int] | None) -> None:
        """Queue a bounded status record for the Tk event thread.

        Args:
            value (tuple[int, int] | None): Phase/percent pair or unavailable progress.
        Returns:
            None: Queues feedback without touching native Tk widgets.
        """
        events.put(("progress", value))

    def worker() -> None:
        """Wait for consent and seal helper state before publishing completion.

        Returns:
            None: Publishes a terminal outcome only after its completion marker.
        """
        nonlocal exit_status
        try:
            deadline = time.monotonic() + HANDSHAKE_TIMEOUT_SECONDS
            while not (path.parent / "proceed").is_file():
                if (path.parent / "cancel").is_file() or time.monotonic() >= deadline:
                    raise RuntimeError("Update cancelled before uploader shutdown")
                time.sleep(HANDSHAKE_POLL_SECONDS)
            result = perform_update(ticket, backend, forward_progress)
            exit_status = 0
        except Exception as exc:
            result = str(exc)
        try:
            (path.parent / "completed").write_text("1", encoding="ascii")
        except OSError as exc:
            exit_status = 1
            result = str(exc)
        events.put(("done", result))
    def poll() -> None:
        """Apply progress and dismiss only a verified terminal result on Tk.

        Returns:
            None: Stops polling after terminal completion; failures stay visible.
        """
        while not events.empty():
            kind, value = events.get_nowait()
            if kind == "done":
                if isinstance(value, UpdateOutcome) and value.verified:
                    root.destroy()
                    return
                text.set(gettext(value.message if isinstance(value, UpdateOutcome) else str(value)))
                button.configure(state="normal")
                root.protocol("WM_DELETE_WINDOW", root.destroy)
                return
            elif isinstance(value, tuple):
                phase, percent = value
                bar.configure(value=percent)
                text.set([gettext("Preparing setup…"), gettext("Preparing installation…"), gettext("Installing…"), gettext("Finishing setup…"), gettext("Verifying installation…")][phase])
        root.after(HELPER_POLL_MS, poll)
    threading.Thread(target=worker, daemon=True).start()
    poll()
    root.mainloop()
    return exit_status
