# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/windows_file_identity.py
# Module Type: Windows filesystem ownership service
# Purpose: Admit local Windows profiles and pin opened directory identities.
# Responsibilities: Read native volume/File ID and recheck path resolution.
#   - Reject redirected or non-local profile roots before linked-mode activation.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Admit local Windows profile paths and pin opened directory identities."""

import ctypes
from ctypes import wintypes
import os
from pathlib import Path
import stat
import sys


# FILE_ID_INFO uses a 64-bit volume serial and the full 128-bit FileId.
# The truncated 64-bit BY_HANDLE_FILE_INFORMATION index is unsafe on ReFS.
FILE_ID_INFO_CLASS = 18
FILE_READ_ATTRIBUTES = 0x80
FILE_SHARE_READ = 0x1
FILE_SHARE_WRITE = 0x2
OPEN_EXISTING = 3
FILE_FLAG_BACKUP_SEMANTICS = 0x02000000
LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x800
INVALID_HANDLE_VALUE = ctypes.c_void_p(-1).value

# Win32 DRIVE_FIXED return code from GetDriveTypeW, not policy configuration.
DRIVE_FIXED = 3
# Win32 FILE_ATTRIBUTE_REPARSE_POINT bit, including older Python releases.
FILE_ATTRIBUTE_REPARSE_POINT = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0x0400)


def assert_local_windows_profile(directory: Path) -> None:
    """Require an existing fixed-drive directory with no reparse ancestors.

    This is a point-in-time admission check. It does not prevent a later
    external change to the path or its ancestors.

    Args:
        directory (Path): Absolute local profile directory to admit.

    Returns:
        None: The path is an existing directory on a fixed local drive.

    Raises:
        OSError: The path is nonlocal, redirected, missing or unverifiable.
    """
    try:
        if sys.platform != "win32":
            raise OSError
        raw_path = os.fspath(directory)
        if (not isinstance(raw_path, str)
                or raw_path.startswith(("\\\\", "//"))
                or raw_path.startswith(("\\\\?\\", "\\\\.\\"))
                or not directory.is_absolute()
                or len(directory.drive) != 2
                or directory.drive[1] != ":"
                or not directory.drive[0].isalpha()
                or directory.anchor != directory.drive + "\\"
                or any(part in (".", "..") for part in directory.parts[1:])):
            raise OSError

        kernel = ctypes.WinDLL(
            "kernel32.dll", use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        get_drive_type = kernel.GetDriveTypeW
        get_drive_type.argtypes = [wintypes.LPCWSTR]
        get_drive_type.restype = wintypes.UINT
        if get_drive_type(directory.anchor) != DRIVE_FIXED:
            raise OSError

        root = Path(directory.anchor)
        current = directory
        while True:
            information = current.lstat()
            attributes = getattr(information, "st_file_attributes", 0)
            if (not stat.S_ISDIR(information.st_mode)
                    or attributes & FILE_ATTRIBUTE_REPARSE_POINT):
                raise OSError
            if current == root:
                break
            parent = current.parent
            if parent == current:
                raise OSError
            current = parent
    except (
        OSError, TypeError, ValueError, AttributeError, ctypes.ArgumentError,
    ):
        raise OSError("Windows profile directory is unavailable.") from None


class _FileIdInfo(ctypes.Structure):
    """Native FILE_ID_INFO layout returned by GetFileInformationByHandleEx."""

    _fields_ = [
        ("volume_serial", ctypes.c_uint64),
        ("file_id", ctypes.c_ubyte * 16),
    ]


class WindowsDirectoryAnchor:
    """Retain a native directory handle and its exact 192-bit identity.

    Omitting FILE_SHARE_DELETE on the opened target inhibits ordinary rename
    or deletion of that directory while the anchor lives. Reparse points in
    ancestor directories and remote file servers require separate qualification;
    this handle alone is not a complete linked-upload admission certificate.
    """

    def __init__(self, path: Path) -> None:
        """Open an existing directory, keeping its physical identity pinned.

        Args:
            path (Path): Profile or flight-workspace directory.

        Returns:
            None: Retains the directory handle until close().

        Raises:
            OSError: Native directory opening or FileIdInfo is unavailable.
        """
        if sys.platform != "win32":
            raise OSError("Windows directory identity is unavailable.")
        self.path = path
        self.kernel = ctypes.WinDLL(
            "kernel32.dll", use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        self.kernel.CreateFileW.argtypes = [
            wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD,
            ctypes.c_void_p, wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE,
        ]
        self.kernel.CreateFileW.restype = wintypes.HANDLE
        self.kernel.GetFileInformationByHandleEx.argtypes = [
            wintypes.HANDLE, wintypes.DWORD, ctypes.c_void_p, wintypes.DWORD,
        ]
        self.kernel.GetFileInformationByHandleEx.restype = wintypes.BOOL
        self.kernel.CloseHandle.argtypes = [wintypes.HANDLE]
        self.kernel.CloseHandle.restype = wintypes.BOOL
        self.handle: int | None = None
        self.identity: bytes | None = None
        try:
            self.handle = self._open()
            self.identity = self._read_identity(self.handle)
        except BaseException:
            self.close()
            raise

    def _open(self) -> int:
        """Open the resolved target directory without following stale strings.

        Returns:
            int: Native HANDLE of the opened filesystem object.

        Raises:
            OSError: CreateFileW cannot open the selected directory.
        """
        name = os.path.abspath(os.fspath(self.path))
        handle = self.kernel.CreateFileW(
            name, FILE_READ_ATTRIBUTES, FILE_SHARE_READ | FILE_SHARE_WRITE,
            None, OPEN_EXISTING, FILE_FLAG_BACKUP_SEMANTICS, None,
        )
        if not handle or handle == INVALID_HANDLE_VALUE:
            raise ctypes.WinError(ctypes.get_last_error())
        return handle

    def _read_identity(self, handle: int) -> bytes:
        """Read a volume serial plus complete 128-bit File ID from this handle.

        Args:
            handle (int): Open native directory handle.

        Returns:
            bytes: Nonzero 64-bit volume serial and 128-bit file ID.

        Raises:
            OSError: Information is unsupported, unavailable or invalid.
        """
        information = _FileIdInfo()
        if not self.kernel.GetFileInformationByHandleEx(
                handle, FILE_ID_INFO_CLASS, ctypes.byref(information),
                ctypes.sizeof(information)):
            raise ctypes.WinError(ctypes.get_last_error())
        volume = int(information.volume_serial)
        identifier = bytes(information.file_id)
        if volume == 0 or not any(identifier):
            raise OSError("Directory volume/File ID identity is incomplete.")
        return volume.to_bytes(8, "little") + identifier

    def verify(self) -> None:
        """Reject paths that no longer resolve to the pinned directory.

        Returns:
            None: A separately opened path retains the same native identity.

        Raises:
            OSError: Directory resolution changed or could not be rechecked.
        """
        if self.handle is None or self.identity is None:
            raise OSError("Directory identity anchor is closed.")
        current = self._open()
        try:
            if self._read_identity(current) != self.identity:
                raise OSError("Directory path changed physical identity.")
        finally:
            if not self.kernel.CloseHandle(current):
                raise ctypes.WinError(ctypes.get_last_error())

    def close(self) -> None:
        """Release the OS handle, including after partial acquisition failure.

        Returns:
            None: The pinned directory no longer has a handle in this owner.
        """
        if self.handle is not None:
            handle = self.handle
            self.handle = None
            self.identity = None
            if not self.kernel.CloseHandle(handle):
                raise ctypes.WinError(ctypes.get_last_error())
