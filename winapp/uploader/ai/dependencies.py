# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ai/dependencies.py
# Module Type: Windows AI Runtime Support
# Purpose: Install optional local semantic-model packages for AI analysis.
# Responsibilities:
#   - Keep large in-process model dependencies outside the default runtime set.
#   - Report frozen-runtime limitations without starting a package installer.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Install optional dependencies for local semantic AI analysis."""

import subprocess
import sys
from typing import Tuple

from ..localization import gettext_noop

def install_semantic_ai_dependencies_for_current_runtime() -> Tuple[bool, str]:
    """
    Install optional in-process semantic AI packages for this Python runtime.

    These packages are intentionally not listed in requirements.txt because they
    are large and are needed only when the operator selects the Transformers
    backend. The normal uploader, tray icon, manual upload, and Pillow fallback
    continue to use the lightweight required dependency set.

    Returns:
        Tuple[bool, str]: Tuple containing success flag and command output
            text.
    """
    if getattr(sys, "frozen", False):
        return False, (
            gettext_noop(
                "The standalone installer cannot install Python packages. Use Ollama or an external analyzer, "
                "or run the Python source version to use Transformers."
            )
        )
    command = [
        sys.executable,
        "-m",
        "pip",
        "install",
        "--user",
        "transformers>=4.40",
        "torch>=2.2",
        "torchvision>=0.17",
    ]
    try:
        completed = subprocess.run(
            command,
            check=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
    except Exception as exc:  # noqa: BLE001
        return False, str(exc)

    output = completed.stdout.strip() if completed.stdout else "pip produced no output"
    if completed.returncode == 0:
        try:
            importlib.import_module("torch")
            importlib.import_module("transformers")
        except Exception as exc:  # noqa: BLE001
            return False, f"pip finished, but semantic AI packages still cannot be imported by this process: {exc}\n{output}"
    return completed.returncode == 0, output
