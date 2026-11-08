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
# Identify Win32 reparse points in native stat metadata on Windows.
# Type: int; Units: file-attribute bit; Scope: update-owned files and paths.
# Consumers: reject_reparse_path, load_ticket, _legacy_marker_is_fresh.
# Rationale: 0x400 is FILE_ATTRIBUTE_REPARSE_POINT from the Windows ABI.
UPDATE_REPARSE_POINT_ATTRIBUTE = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0x400)


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


Progress = Callable[[tuple[int, int] | None], None]

# Load native Windows libraries only from System32, never the helper extraction directory.
LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x00000800


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


def reject_reparse_path(path: Path) -> None:
    """Reject links and reparse points from one path through its ancestors.

    Args:
        path (Path): Candidate update file or directory to inspect.

    Returns:
        None: Accepts only a path whose existing components are not redirected.

    Raises:
        ValueError: A symlink or native reparse point occurs in the path chain.
    """
    for candidate in (path, *path.parents):
        if candidate.is_symlink():
            raise ValueError("Update paths must not contain reparse points")
        if candidate.exists():
            info = candidate.lstat()
            if (getattr(info, "st_file_attributes", 0)
                    & UPDATE_REPARSE_POINT_ATTRIBUTE):
                raise ValueError("Update paths must not contain reparse points")


def updates_root() -> Path:
    """Return the current user's fixed helper storage owner."""
    return Path(os.environ["LOCALAPPDATA"]) / "PHPGalleryUploader" / "updates"


def installed_identity(executable_path: Path | None = None) -> Path:
    """Require the frozen executable to match this product's HKLM installation."""
    if sys.platform != "win32" or not getattr(sys, "frozen", False):
        raise RuntimeError("Self-update requires the installed Windows application")
    executable = (executable_path or Path(sys.executable)).resolve()
    if executable.name != "PHPGalleryUploader.exe":
        raise RuntimeError("Self-update requires the registered installed executable")
    import winreg
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
    """Reject malformed tickets and paths escaping their private session."""
    if any(type(value) is not str for value in (ticket.session_id, ticket.target_version, ticket.expected_sha256,
                                               ticket.installer_path, ticket.installed_executable)):
        raise ValueError("Invalid ticket field types")
    if type(ticket.schema) is not int or ticket.schema != 1:
        raise ValueError("Unsupported update ticket schema")
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
    if not isinstance(raw, dict) or set(raw) != required:
        raise ValueError("Invalid ticket fields")
    return validate_ticket(UpdateTicket(**raw), path)


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
    from uploader.self_update import _version

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


def prepare_helper(installer_path: Path, target_version: str, expected_sha256: str,
                   expected_size: int, installed_executable: Path | None = None) -> Path:
    """Copy the verified setup and frozen helper outside the installation."""
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
        shutil.copyfile(sys.executable, session / "helper.exe")
        path.write_text(json.dumps(asdict(ticket)), encoding="utf-8")
    except Exception:
        (session / "completed").write_text("1", encoding="ascii")
        clean_completed_sessions()
        raise
    return path


def clean_completed_sessions() -> None:
    """Remove only known files in explicitly completed updater-owned sessions."""
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
            for name in ("setup.exe", "setup.log", "ready", "proceed", "cancel", "helper.exe", "ticket.json", "completed"):
                candidate = session / name
                reject_reparse_path(candidate)
                candidate.unlink(missing_ok=True)
            session.rmdir()
        except (OSError, ValueError):
            # A still-running copied helper is locked on Windows; a later
            # preparation can retry without touching its active session.
            continue


def launch_helper(ticket_path: Path, timeout: float = 20) -> subprocess.Popen:
    """Wait for helper readiness before the uploader begins graceful shutdown."""
    process = subprocess.Popen([str(ticket_path.parent / "helper.exe"), "--self-update-helper", str(ticket_path)])
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if (ticket_path.parent / "ready").is_file():
            (ticket_path.parent / "proceed").write_text("1", encoding="ascii")
            return process
        if process.poll() is not None:
            break
        time.sleep(0.05)
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
        """Enumerate exact executable identities including both onefile processes."""
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
                        if os.path.normcase(os.path.abspath(buffer.value)) == os.path.normcase(os.path.abspath(executable)):
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
        """Elevate setup alone and observe its actual process exit status."""
        class ShellInfo(ctypes.Structure):
            """Native SHELLEXECUTEINFOW including the returned process handle."""
            _fields_ = [("cbSize", wintypes.DWORD), ("fMask", wintypes.ULONG), ("hwnd", wintypes.HWND),
                        ("lpVerb", wintypes.LPCWSTR), ("lpFile", wintypes.LPCWSTR), ("lpParameters", wintypes.LPCWSTR),
                        ("lpDirectory", wintypes.LPCWSTR), ("nShow", ctypes.c_int), ("hInstApp", wintypes.HINSTANCE),
                        ("lpIDList", ctypes.c_void_p), ("lpClass", wintypes.LPCWSTR), ("hkeyClass", wintypes.HKEY),
                        ("dwHotKey", wintypes.DWORD), ("hIcon", wintypes.HANDLE), ("hProcess", wintypes.HANDLE)]
        info = ShellInfo()
        info.cbSize = ctypes.sizeof(info)
        info.fMask = 0x40
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
            if error == 1223:
                raise InterruptedError("Administrator permission was cancelled")
            raise OSError("Unable to start setup")
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
        """Relaunch under the original user with one exact continuation nonce.

        Args:
            executable (str): Verified registered application executable.
            update_session (str): Non-secret 128-bit updater session identifier.

        Returns:
            None: Starts the same installed app without elevation.

        Raises:
            ValueError: The session identifier is malformed.
            OSError: Windows cannot start the installed application.
        """
        if re.fullmatch(r'[0-9a-f]{32}', update_session) is None:
            raise ValueError('Invalid update continuation session.')
        subprocess.Popen(
            [executable, '--after-update', update_session],
            cwd=str(Path(executable).parent),
        )


@dataclass(frozen=True)
class UpdateOutcome:
    """Distinguish verified/restarted installation from a required Windows reboot."""

    message: str
    verified: bool


def perform_update(ticket: UpdateTicket, backend: Backend, progress: Progress,
                   timeout: float = SHUTDOWN_TIMEOUT_SECONDS) -> UpdateOutcome:
    """Wait for graceful shutdown, run setup, and verify its resulting version.

    Args:
        ticket (UpdateTicket): Validated local installer and target identity.
        backend (Backend): Native process, installation and version adapter.
        progress (Progress): Callback receiving phase/percent pairs or None.
        timeout (float): Seconds allowed for the uploader to exit gracefully.
    Returns:
        UpdateOutcome: Verified success only after matching version and restart;
            a reboot requirement is returned with verified False.
    Raises:
        RuntimeError: Shutdown, installation or resulting version was not verified.
        InterruptedError: Administrator approval was cancelled.
    """
    deadline = time.monotonic() + timeout
    while backend.target_running(ticket.installed_executable):
        if time.monotonic() >= deadline:
            raise RuntimeError("Uploader is still running. No update was started.")
        time.sleep(SHUTDOWN_POLL_SECONDS)
    verify_ticket_installer(ticket)
    try:
        code = backend.install(ticket, progress)
    except InterruptedError:
        if Path(ticket.installed_executable).is_file():
            backend.restart(
                ticket.installed_executable, update_session=ticket.session_id,
            )
        raise
    if code == 3010:
        return UpdateOutcome(
            "Windows restart required. Restart Windows before opening the uploader.", False,
        )
    if code != 0:
        raise RuntimeError(f"Setup exited with code {code}. The update was not verified.")
    if backend.version(ticket.installed_executable) != ticket.target_version:
        raise RuntimeError("Setup finished, but the installed version does not match. The update was not verified.")
    backend.restart(
        ticket.installed_executable, update_session=ticket.session_id,
    )
    return UpdateOutcome(f"Version {ticket.target_version} installed. Uploader restarted.", True)


def main(ticket_path: str) -> int:
    """Open update progress and close automatically only after verified success.

    Args:
        ticket_path (str): Owned session's validated local update-ticket path.
    Returns:
        int: Zero after installation or a required reboot; one after failure.
            Errors and reboot instructions remain visible until dismissed.
    """
    import tkinter as tk
    from tkinter import ttk
    path = Path(ticket_path)
    root = tk.Tk()
    root.title("PHP Gallery Uploader update")
    root.geometry("520x150")
    text = tk.StringVar(value="Waiting for uploader to close…")
    ttk.Label(root, textvariable=text, wraplength=490).pack(padx=15, pady=15)
    bar = ttk.Progressbar(root, maximum=100)
    bar.pack(fill="x", padx=15)
    button = ttk.Button(root, text="Close", command=root.destroy, state="disabled")
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
                text.set(value.message if isinstance(value, UpdateOutcome) else str(value))
                button.configure(state="normal")
                root.protocol("WM_DELETE_WINDOW", root.destroy)
                return
            elif isinstance(value, tuple):
                phase, percent = value
                bar.configure(value=percent)
                text.set(["Preparing setup…", "Preparing installation…", "Installing…", "Finishing setup…", "Verifying installation…"][phase])
        root.after(HELPER_POLL_MS, poll)
    threading.Thread(target=worker, daemon=True).start()
    poll()
    root.mainloop()
    return exit_status
