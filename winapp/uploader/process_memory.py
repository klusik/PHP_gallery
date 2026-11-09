# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/process_memory.py
# Module Type: Native Process Observation
# Purpose: Measure the current uploader process resident memory cheaply.
# Responsibilities:
#   - Read current-process working set without inspecting other processes.
#   - Preserve unknown values on unsupported systems or measurement failure.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Optional current-process RAM observation without third-party dependencies."""

import ctypes
import os
import subprocess
import sys


# Purpose: Bound optional macOS resident-memory observation off the GUI thread.
# Type: float. Units: seconds. Scope: one current-process ps query.
# Consumers: current_process_ram_bytes. Rationale: One second prevents a stuck
# optional system observation from holding the storage worker indefinitely.
PROCESS_MEMORY_QUERY_TIMEOUT_SECONDS = 1.0


def current_process_ram_bytes() -> int | None:
    """Measure current-process resident RAM on Windows or macOS.

    Returns:
        int | None: Measured resident working-set bytes, or None when unsupported
            or unavailable. This is neither decoded-image bytes nor GPU memory.
    """
    if sys.platform == 'darwin':
        try:
            result = subprocess.run(
                ['/bin/ps', '-o', 'rss=', '-p', str(os.getpid())],
                capture_output=True, text=True, check=False,
                timeout=PROCESS_MEMORY_QUERY_TIMEOUT_SECONDS,
            )
            if result.returncode == 0:
                # BSD ps reports this exact process's resident set in KiB.
                resident_kib = int(result.stdout.strip())
                return resident_kib * 1024 if resident_kib > 0 else None
        except (OSError, ValueError, subprocess.TimeoutExpired):
            pass
        return None
    if os.name != 'nt':
        return None
    from ctypes import wintypes

    class ProcessMemoryCounters(ctypes.Structure):
        """Match the Windows PROCESS_MEMORY_COUNTERS native ABI."""

        _fields_ = [
            ('cb', wintypes.DWORD), ('PageFaultCount', wintypes.DWORD),
            ('PeakWorkingSetSize', ctypes.c_size_t),
            ('WorkingSetSize', ctypes.c_size_t),
            ('QuotaPeakPagedPoolUsage', ctypes.c_size_t),
            ('QuotaPagedPoolUsage', ctypes.c_size_t),
            ('QuotaPeakNonPagedPoolUsage', ctypes.c_size_t),
            ('QuotaNonPagedPoolUsage', ctypes.c_size_t),
            ('PagefileUsage', ctypes.c_size_t),
            ('PeakPagefileUsage', ctypes.c_size_t),
        ]

    try:
        kernel = ctypes.WinDLL('kernel32', use_last_error=True)
        psapi = ctypes.WinDLL('psapi', use_last_error=True)
        kernel.GetCurrentProcess.argtypes = []
        kernel.GetCurrentProcess.restype = wintypes.HANDLE
        psapi.GetProcessMemoryInfo.argtypes = [
            wintypes.HANDLE, ctypes.POINTER(ProcessMemoryCounters), wintypes.DWORD,
        ]
        psapi.GetProcessMemoryInfo.restype = wintypes.BOOL
        counters = ProcessMemoryCounters()
        counters.cb = ctypes.sizeof(counters)
        if psapi.GetProcessMemoryInfo(
            kernel.GetCurrentProcess(), ctypes.byref(counters), counters.cb,
        ):
            return int(counters.WorkingSetSize)
    except (OSError, AttributeError, ValueError):
        pass
    return None
