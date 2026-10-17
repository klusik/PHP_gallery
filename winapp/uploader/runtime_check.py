# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/runtime_check.py
# Module Type: Windows Tooling
# Purpose: Verify the build interpreter and the actual frozen Python runtime.
# Responsibilities:
#   - Require x64 CPython 3.14.x and expose its concrete patch version.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Interpreter attestation shared by installer preparation and the frozen EXE."""

import platform
import re
import struct
from typing import Mapping

# Type: str; Units: Python version text; Scope: installer; Consumers: validation.
# Rationale: 3.14.patch allows patch fixes while refusing a silent minor upgrade.
PACKAGED_PYTHON_PATTERN = r"3\.14\.\d+"
# Type: int; Units: bits; Scope: installer; Consumers: runtime validation.
# Rationale: 64-bit CPython matches the shipped x64 SimConnect native runtime.
PACKAGED_POINTER_BITS = 64


def validate_runtime_info(info: Mapping[str, str | int]) -> None:
    """Refuse unsupported versions/architectures before installer publication.

    Args:
        info (Mapping[str, str|int]): python_version string, implementation
            string and pointer_bits integer reported by the executing
            interpreter.

    Returns:
        None: Accepts only stable CPython 3.14.patch with 64-bit pointers.

    Raises:
        RuntimeError: The runtime report is incomplete or unsupported.
    """
    if (
        not re.fullmatch(
            PACKAGED_PYTHON_PATTERN, str(info.get("python_version", ""))
        )
        or info.get("implementation") != "CPython"
        or info.get("pointer_bits") != PACKAGED_POINTER_BITS
    ):
        raise RuntimeError("Installer requires x64 CPython 3.14.x.")


def runtime_info() -> dict[str, str | int]:
    """Read and validate this executing interpreter, including a frozen runtime.

    Returns:
        dict[str, str|int]: python_version, implementation and pointer_bits.

    Raises:
        RuntimeError: This interpreter is not x64 CPython 3.14.x.
    """
    info: dict[str, str | int] = {
        "python_version": platform.python_version(),
        "implementation": platform.python_implementation(),
        # Eight bits per byte is the Windows/Python ABI conversion, not a limit.
        "pointer_bits": struct.calcsize("P") * 8,
    }
    validate_runtime_info(info)
    return info
