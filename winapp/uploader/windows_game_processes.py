# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/windows_game_processes.py
# Module Type: Read-only Windows Process Adapter
# Purpose: Find known game processes owned by the uploader's Windows account.
# Responsibilities:
#   - Enumerate process names without elevation, memory reads or process writes.
#   - Compare account SIDs and close snapshot, process and token handles.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Restrict optional game detection to readable processes of the current user."""

import ctypes
import os

# Purpose: Select process names only, without heaps, modules or memory access.
# Type: int; Units: SDK flags; Scope: native process snapshot.
# Consumers: WindowsGameProcesses.names. Rationale: SDK-defined 0x2 selects names.
TH32CS_SNAPPROCESS = 0x00000002
# Purpose: Request only read-only process identity access.
# Type: int; Units: SDK access mask; Scope: candidate game handles.
# Consumers: WindowsGameProcesses.names. Rationale: 0x1000 avoids full/debug access.
PROCESS_QUERY_LIMITED_INFORMATION = 0x1000
# Purpose: Read account identity without token mutation.
# Type: int; Units: SDK access mask; Scope: process token handles.
# Consumers: WindowsGameProcesses.user_sid. Rationale: SDK TOKEN_QUERY is 0x8.
TOKEN_QUERY = 0x0008
# Purpose: Select the user identity field of a Windows token.
# Type: int; Units: SDK enum value; Scope: token information.
# Consumers: WindowsGameProcesses.user_sid. Rationale: TokenUser is defined as 1.
TOKEN_USER = 1
# Purpose: Resolve Windows system DLLs from the trusted system directory.
# Type: int; Units: SDK load flags; Scope: adapter DLL loading.
# Consumers: WindowsGameProcesses.__init__. Rationale: 0x800 excludes search paths.
LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x00000800
# Purpose: Bound copied identity data to the Windows SID ABI maximum.
# Type: int; Units: bytes; Scope: native token-user buffer.
# Consumers: WindowsGameProcesses.user_sid. Rationale: 68 bytes hold the SID
# header plus the SDK maximum of fifteen subauthorities; this is not a policy.
MAX_SID_BYTES = 68
# Purpose: Distinguish complete enumeration from a failed/partial snapshot.
# Type: int; Units: SDK error code; Scope: process snapshot traversal.
# Consumers: WindowsGameProcesses.names. Rationale: SDK error 18 means exhaustion.
ERROR_NO_MORE_FILES = 18


class ProcessEntry(ctypes.Structure):
    """Match PROCESSENTRY32W, including its pointer-width heap field."""

    _fields_ = [
        ("size", ctypes.c_uint32),
        ("usage", ctypes.c_uint32),
        ("pid", ctypes.c_uint32),
        ("heap", ctypes.c_size_t),
        ("module", ctypes.c_uint32),
        ("threads", ctypes.c_uint32),
        ("parent", ctypes.c_uint32),
        ("priority", ctypes.c_int32),
        ("flags", ctypes.c_uint32),
        # MAX_PATH is the SDK's fixed executable-name field width.
        ("exe", ctypes.c_wchar * 260),
    ]


class WindowsGameProcesses:
    """Read candidate process names under the caller's existing access rights."""

    def __init__(self) -> None:
        """Bind system DLL functions with exact Windows argument widths.

        Returns:
            None: Creates a read-only adapter without acquiring process handles.
        Raises:
            OSError: Required Windows system DLLs are unavailable.
        """
        self.kernel = ctypes.WinDLL(
            "kernel32.dll", use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        self.security = ctypes.WinDLL(
            "advapi32.dll", use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        handle = ctypes.c_void_p
        dword = ctypes.c_uint32
        signatures = (
            (self.kernel.CreateToolhelp32Snapshot, [dword, dword], handle),
            (
                self.kernel.Process32FirstW,
                [handle, ctypes.POINTER(ProcessEntry)], ctypes.c_int32,
            ),
            (
                self.kernel.Process32NextW,
                [handle, ctypes.POINTER(ProcessEntry)], ctypes.c_int32,
            ),
            (self.kernel.OpenProcess, [dword, ctypes.c_int32, dword], handle),
            (self.kernel.GetCurrentProcess, [], handle),
            (self.kernel.CloseHandle, [handle], ctypes.c_int32),
            (
                self.security.OpenProcessToken,
                [handle, dword, ctypes.POINTER(handle)], ctypes.c_int32,
            ),
            (
                self.security.GetTokenInformation,
                [handle, ctypes.c_int32, handle, dword, ctypes.POINTER(dword)],
                ctypes.c_int32,
            ),
            (self.security.GetLengthSid, [handle], dword),
        )
        for function, arguments, result in signatures:
            function.argtypes = arguments
            function.restype = result

    def user_sid(self, process: int) -> bytes | None:
        """Copy a process token's user SID without requesting extra privileges.

        Args:
            process (int): Queryable process handle or the current pseudo-handle.
        Returns:
            bytes | None: Native user SID bytes, or None when identity is denied
                or unavailable; the token handle is always closed.
        """
        token = ctypes.c_void_p()
        if not self.security.OpenProcessToken(
            process, TOKEN_QUERY, ctypes.byref(token)
        ):
            return None
        try:
            length = ctypes.c_uint32()
            self.security.GetTokenInformation(
                token, TOKEN_USER, None, 0, ctypes.byref(length)
            )
            # TOKEN_USER holds SID_AND_ATTRIBUTES plus a SID. This ABI bound
            # avoids trusting an invalid native size before allocating memory.
            maximum = ctypes.sizeof(ctypes.c_void_p) * 2 + MAX_SID_BYTES
            if not 0 < length.value <= maximum:
                return None
            buffer = ctypes.create_string_buffer(length.value)
            if not self.security.GetTokenInformation(
                token, TOKEN_USER, buffer, length, ctypes.byref(length)
            ):
                return None
            sid = ctypes.cast(buffer, ctypes.POINTER(ctypes.c_void_p))[0]
            if not sid:
                return None
            sid_length = self.security.GetLengthSid(sid)
            if not 0 < sid_length <= MAX_SID_BYTES:
                return None
            return ctypes.string_at(sid, sid_length)
        finally:
            self.kernel.CloseHandle(token)

    def names(self, candidates: set[str]) -> set[str]:
        """Return only candidate executable basenames owned by this account.

        Args:
            candidates (set[str]): Lowercase executable basenames from the
                offline game catalog and reviewed custom rules.
        Returns:
            set[str]: Matching current-user basenames. Denied/exited processes
                and an unreadable current identity produce no inferred match.
        """
        current_sid = self.user_sid(self.kernel.GetCurrentProcess())
        if current_sid is None:
            return set()
        snapshot = self.kernel.CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0)
        if snapshot in (None, ctypes.c_void_p(-1).value):
            return set()
        matched = set()
        entry = ProcessEntry()
        entry.size = ctypes.sizeof(entry)
        try:
            more = self.kernel.Process32FirstW(snapshot, ctypes.byref(entry))
            while more:
                name = entry.exe.casefold()
                if name in candidates:
                    process = self.kernel.OpenProcess(
                        PROCESS_QUERY_LIMITED_INFORMATION, False, entry.pid
                    )
                    if process:
                        try:
                            if self.user_sid(process) == current_sid:
                                matched.add(name)
                        finally:
                            self.kernel.CloseHandle(process)
                more = self.kernel.Process32NextW(snapshot, ctypes.byref(entry))
            return (
                matched if ctypes.get_last_error() == ERROR_NO_MORE_FILES
                else set()
            )
        finally:
            self.kernel.CloseHandle(snapshot)


def current_user_game_processes(candidates: set[str]) -> set[str]:
    """Safely read optional game presence without admin or debug privileges.

    Args:
        candidates (set[str]): Reviewed lowercase game executable basenames.
    Returns:
        set[str]: Candidate processes of this user, or an empty set when the
            host/API is unavailable. No inventory is logged, saved or uploaded.
    """
    if os.name != "nt":
        return set()
    try:
        return WindowsGameProcesses().names(candidates)
    except (OSError, AttributeError, ValueError):
        # Ordinary games remain supported without process-inspection access.
        # This permanent optional adapter never controls lifecycle admission.
        return set()
