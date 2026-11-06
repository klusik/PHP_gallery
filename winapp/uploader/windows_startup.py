# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/windows_startup.py
# Module Type: Windows desktop lifecycle service
# Purpose: Own per-user startup registration and single-instance activation.
# Responsibilities: Update the Run entry and coordinate native process handles.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Keep one Windows uploader alive and restore it after an explicit relaunch."""

import ctypes
from ctypes import wintypes
import hashlib
import os
from pathlib import Path
import sys

from .windows_file_identity import WindowsDirectoryAnchor


IS_WINDOWS = sys.platform == "win32"
RUN_KEY = r"Software\Microsoft\Windows\CurrentVersion\Run"
RUN_VALUE = "PHPGalleryUploader"

# Purpose: Observe activation without blocking Tk or adding a worker thread.
# Type: int; Units: milliseconds; Scope: one desktop application's main loop.
# Consumers: WatcherApp.poll_instance_activation.
# Rationale: 200 ms keeps explicit relaunch responsive with negligible idle work.
ACTIVATION_POLL_MS = 200

# Purpose: Recover a visible app if native tray initialization stalls.
# Type: float; Units: seconds; Scope: one minimized desktop launch.
# Consumers: WatcherApp.complete_startup.
# Rationale: five seconds allows shell startup without hiding indefinitely.
STARTUP_TRAY_TIMEOUT_SECONDS = 5.0

# Purpose: Enforce Windows' documented maximum Run command length.
# Type: int; Units: characters; Scope: this user's Run value.
# Consumers: startup registration admission.
# Rationale: Windows limits the command line to 260 characters.
RUN_COMMAND_MAX_CHARACTERS = 260

# Windows ABI constants, not configurable application timing/size policies.
ERROR_ALREADY_EXISTS = 183
WAIT_OBJECT_0 = 0
WAIT_ABANDONED = 0x80
WAIT_TIMEOUT = 0x102
ASFW_ANY = 0xFFFFFFFF
LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x800


def startup_supported() -> bool:
    """Identify the installed Windows executable that can register itself.

    Returns:
        bool: True for a frozen Windows executable; source runs stay opt-out
            from OS registration and never register a Python interpreter.
    """
    return IS_WINDOWS and bool(getattr(sys, "frozen", False))


def synchronize_startup(enabled: bool) -> None:
    """Reconcile this user's Run value with the saved startup preference.

    Args:
        enabled (bool): Register the frozen executable with --startup when true;
            otherwise remove only the uploader's named value.
    Returns:
        None: Updates HKCU without elevation; source/non-Windows runs do nothing.
    Raises:
        OSError: Windows refuses a registry operation or the Run command exceeds
            its documented 260-character limit.
    """
    if not startup_supported():
        return
    import winreg

    command = '"' + str(Path(sys.executable).resolve()) + '" --startup'
    if enabled and len(command) > RUN_COMMAND_MAX_CHARACTERS:
        raise OSError("Windows startup command exceeds 260 characters.")
    # Match the x64 installer even if this code is inspected by another runtime.
    access = winreg.KEY_SET_VALUE | winreg.KEY_WOW64_64KEY
    if enabled:
        with winreg.CreateKeyEx(winreg.HKEY_CURRENT_USER, RUN_KEY, 0, access) as key:
            winreg.SetValueEx(key, RUN_VALUE, 0, winreg.REG_SZ, command)
    else:
        try:
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, RUN_KEY, 0, access) as key:
                winreg.DeleteValue(key, RUN_VALUE)
        except FileNotFoundError:
            pass  # An absent value already expresses the user's opt-out.


class WindowsInstance:
    """Hold a profile-scoped Windows mutex and a coalescing activation event.

    All desktop launches use the shared application profile, including different
    executable/source copies. The legacy path mutex remains held for older
    cooperating builds, while an additional native volume/File ID mutex rejects
    different aliases of the same open directory. Global objects coordinate
    Windows sessions. Default Windows object ACLs apply. Helper/version/runtime
    modes bypass this desktop owner; older binaries are not physically fenced.
    """

    def __init__(self, profile: Path) -> None:
        """Prepare native identities without opening files or native handles.

        Args:
            profile (Path): Shared uploader profile containing mutable state.
        Returns:
            None: Creates an unacquired lifecycle owner.
        """
        self.profile = profile
        identity = os.path.normcase(str(profile.resolve())).casefold()
        digest = hashlib.sha256(identity.encode("utf-8")).hexdigest()
        self.name = "Global\\PHPGalleryUploader-" + digest
        self.kernel: "ctypes.WinDLL | None" = None
        self.mutex: int | None = None
        self.event: int | None = None
        self.owned = False
        self.directory: WindowsDirectoryAnchor | None = None
        self.physical_mutex: int | None = None
        self.physical_event: int | None = None
        self.physical_owned = False

    def acquire(self) -> bool:
        """Acquire the profile before configuration, logging or workers start.

        Returns:
            bool: True for the owner or a non-Windows source run, false when
                another Windows process owns this profile.
        Raises:
            OSError: Native synchronization fails; no unlocked app is admitted.
        """
        if not IS_WINDOWS:
            return True
        self.kernel = ctypes.WinDLL(
            "kernel32.dll", use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        self.kernel.CreateMutexW.argtypes = [
            ctypes.c_void_p, wintypes.BOOL, wintypes.LPCWSTR,
        ]
        self.kernel.CreateMutexW.restype = wintypes.HANDLE
        self.kernel.CreateEventW.argtypes = [
            ctypes.c_void_p, wintypes.BOOL, wintypes.BOOL, wintypes.LPCWSTR,
        ]
        self.kernel.CreateEventW.restype = wintypes.HANDLE
        self.kernel.WaitForSingleObject.argtypes = [wintypes.HANDLE, wintypes.DWORD]
        self.kernel.WaitForSingleObject.restype = wintypes.DWORD
        for name in ("SetEvent", "ReleaseMutex", "CloseHandle"):
            function = getattr(self.kernel, name)
            function.argtypes = [wintypes.HANDLE]
            function.restype = wintypes.BOOL
        try:
            # Open the event before electing an owner. Concurrent cold launches
            # retain a shared handle, so an early activation cannot disappear.
            self.event = self.kernel.CreateEventW(
                None, False, False, self.name + "-Activate",
            )
            if not self.event:
                raise ctypes.WinError(ctypes.get_last_error())
            self.mutex = self.kernel.CreateMutexW(None, True, self.name)
            error = ctypes.get_last_error()
            if not self.mutex:
                raise ctypes.WinError(error)
            if error != ERROR_ALREADY_EXISTS:
                self.owned = True
            else:
                # A crashed/exiting owner may leave a handle in another launcher.
                # Acquire an abandoned/unowned mutex instead of stranding it.
                status = self.kernel.WaitForSingleObject(self.mutex, 0)
                if status == WAIT_TIMEOUT:
                    return False
                if status not in (WAIT_OBJECT_0, WAIT_ABANDONED):
                    raise ctypes.WinError(ctypes.get_last_error())
                self.owned = True
            if sys.platform == "win32":
                # The legacy path owner is retained before creating the
                # directory or resolving its opened native filesystem identity.
                self.profile.mkdir(parents=True, exist_ok=True)
                self.directory = WindowsDirectoryAnchor(self.profile)
                self.directory.verify()
                physical_id = self.directory.identity
                if physical_id is None:
                    raise OSError("Native profile identity is unavailable.")
                # Keep all 128 File ID bits, not the truncated ReFS 64-bit index.
                physical_digest = hashlib.sha256(
                    b"PHPGallery physical profile\x00" + physical_id
                ).hexdigest()
                physical_name = "Global\\PHPGalleryUploader.Physical-" + physical_digest
                self.physical_event = self.kernel.CreateEventW(
                    None, False, False, physical_name + "-Activate",
                )
                if not self.physical_event:
                    raise ctypes.WinError(ctypes.get_last_error())
                ctypes.set_last_error(0)
                self.physical_mutex = self.kernel.CreateMutexW(
                    None, True, physical_name,
                )
                physical_error = ctypes.get_last_error()
                if not self.physical_mutex:
                    raise ctypes.WinError(physical_error)
                if physical_error == ERROR_ALREADY_EXISTS:
                    status = self.kernel.WaitForSingleObject(self.physical_mutex, 0)
                    if status == WAIT_TIMEOUT:
                        return False
                    if status not in (WAIT_OBJECT_0, WAIT_ABANDONED):
                        raise ctypes.WinError(ctypes.get_last_error())
                self.physical_owned = True
                self.directory.verify()
            return True
        except OSError:
            self.close()
            raise

    def request_activation(self) -> None:
        """Ask the current owner to restore its window after an explicit launch.

        Returns:
            None: Signals the event; repeated requests coalesce into one restore.
        Raises:
            OSError: Windows refuses delivery of the activation request.
        """
        if self.kernel is None or self.event is None:
            return
        event = (
            self.physical_event
            if self.physical_event is not None and not self.physical_owned
            else self.event
        )
        user = ctypes.WinDLL(
            "user32.dll", use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        user.AllowSetForegroundWindow.argtypes = [wintypes.DWORD]
        user.AllowSetForegroundWindow.restype = wintypes.BOOL
        # The explicit launcher can transfer its foreground allowance. Windows
        # revokes ASFW_ANY at the next user input; no credentials/data are sent.
        user.AllowSetForegroundWindow(ASFW_ANY)
        if not self.kernel.SetEvent(event):
            raise ctypes.WinError(ctypes.get_last_error())

    def activation_requested(self) -> bool:
        """Consume one pending restore request without waiting on the UI thread.

        Returns:
            bool: True once for a signaled event, otherwise false.
        Raises:
            OSError: Windows cannot inspect the activation event.
        """
        if self.kernel is None or self.event is None:
            return False
        for event in (self.event, self.physical_event):
            if event is None:
                continue
            status = self.kernel.WaitForSingleObject(event, 0)
            if status not in (WAIT_OBJECT_0, WAIT_TIMEOUT):
                raise ctypes.WinError(ctypes.get_last_error())
            if status == WAIT_OBJECT_0:
                return True
        return False

    def close(self) -> None:
        """Release owned synchronization resources after the main loop ends.

        Returns:
            None: Closes each handle once; repeated cleanup is harmless. Windows
                also releases the mutex after an unexpected process termination.
        """
        if self.kernel is not None:
            if self.physical_event:
                self.kernel.CloseHandle(self.physical_event)
            if self.physical_mutex:
                if self.physical_owned:
                    self.kernel.ReleaseMutex(self.physical_mutex)
                self.kernel.CloseHandle(self.physical_mutex)
            if self.event:
                self.kernel.CloseHandle(self.event)
            if self.mutex:
                if self.owned:
                    self.kernel.ReleaseMutex(self.mutex)
                self.kernel.CloseHandle(self.mutex)
        if self.directory is not None:
            self.directory.close()
        self.directory = None
        self.physical_event = None
        self.physical_mutex = None
        self.physical_owned = False
        self.event = None
        self.mutex = None
        self.owned = False
