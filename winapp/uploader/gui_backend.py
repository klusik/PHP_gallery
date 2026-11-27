# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gui_backend.py
# Module Type: Desktop composition service
# Purpose: Select the next interface and supervise isolated Qt startup.
# Responsibilities: Validate preferences, confirm readiness and bound fallback.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Coordinate the desktop backend without loading either GUI toolkit."""

import json
import os
import stat
import subprocess
import sys
import tempfile
import time
import uuid
from pathlib import Path
from typing import Literal, cast

from .models import JsonValue
from .state_store import atomic_write_json

GuiBackend = Literal["tkinter", "pyside6"]
GuiFallback = Literal[
    "startup_failed", "dependency_missing", "pending_work",
    "update_continuation",
]
GuiStartupState = Literal["ready", "activated", "refused"]
GUI_BACKENDS: tuple[GuiBackend, ...] = ("tkinter", "pyside6")
DEFAULT_GUI_BACKEND: GuiBackend = "tkinter"
GUI_BACKEND_FILE = "ui-backend.json"
GUI_CHILD_OPTION = "--gui-child"
GUI_STARTUP_DIRECTORY = "_PHP_GALLERY_GUI_STARTUP_DIRECTORY"
GUI_STARTUP_RESPONSE = "response.json"
GUI_FALLBACK_REASONS: tuple[GuiFallback, ...] = (
    "startup_failed", "dependency_missing", "pending_work",
    "update_continuation",
)

# Purpose: Bound preference and startup response parsing before allocating JSON.
# Type: int. Units: bytes. Scope: one desktop choice or readiness document.
# Consumers: _read_gui_document.
# Rationale: 1 KiB suffices for the backend name, nonce and refusal code.
GUI_DOCUMENT_MAX_BYTES = 1024
# Purpose: Recover when Qt or a native platform plugin stops before readiness.
# Type: float. Units: seconds. Scope: one isolated Qt startup.
# Consumers: supervise_gui_startup.
# Rationale: 25 seconds allows a cold DLL load and scene frame on a slow host
# while keeping a non-responsive startup bounded.
GUI_STARTUP_SECONDS = 25.0
# Purpose: Observe a published ready state without a busy wait.
# Type: float. Units: seconds. Scope: one startup supervision poll.
# Consumers: supervise_gui_startup.
# Rationale: 50 ms adds little startup latency without a tight filesystem loop.
GUI_STARTUP_POLL_SECONDS = 0.05
# Purpose: Verify that a timed-out child has actually lost profile ownership.
# Type: float. Units: seconds. Scope: one terminated child process.
# Consumers: supervise_gui_startup.
# Rationale: Five seconds permits native teardown before refusing a relaunch.
GUI_STARTUP_REAP_SECONDS = 5.0


def _read_gui_document(path: Path) -> dict[str, JsonValue]:
    """Read a small, complete interface document with unique keys.

    Args:
        path (Path): Fixed preference or private startup response path.
    Returns:
        dict[str, JsonValue]: Decoded fields, or an empty map for unreadable,
            malformed, redirected or unsupported documents.
    """
    try:
        attributes = path.lstat()
        if (not stat.S_ISREG(attributes.st_mode) or attributes.st_nlink != 1
                or getattr(attributes, "st_file_attributes", 0)
                & stat.FILE_ATTRIBUTE_REPARSE_POINT
                or attributes.st_size > GUI_DOCUMENT_MAX_BYTES):
            return {}
        with path.open("rb") as source:
            raw = source.read(GUI_DOCUMENT_MAX_BYTES + 1)
        if len(raw) > GUI_DOCUMENT_MAX_BYTES:
            return {}
        # Interface documents are flat objects. Preserve top-level pairs
        # through decoding so a duplicate selection cannot change its meaning.
        fields = json.loads(raw, object_pairs_hook=tuple)
        if not raw.lstrip().startswith(b"{") or not isinstance(fields, tuple):
            return {}
        payload = dict(fields)
        if len(payload) != len(fields):
            return {}
        return payload
    except (OSError, ValueError, TypeError, RecursionError):
        return {}


def load_gui_backend(profile: Path) -> GuiBackend:
    """Resolve the saved backend with a permanent stable default.

    Args:
        profile (Path): Per-user profile containing independent interface state.
    Returns:
        GuiBackend: The validated selection, or Tkinter for unusable evidence.
    """
    fields = _read_gui_document(profile / GUI_BACKEND_FILE)
    backend = fields.get("backend")
    if set(fields) == {"backend"} and isinstance(backend, str) and backend in GUI_BACKENDS:
        return cast(GuiBackend, backend)
    return DEFAULT_GUI_BACKEND


def save_gui_backend(profile: Path, backend: GuiBackend) -> None:
    """Persist only the toolkit requested for the next normal launch.

    Args:
        profile (Path): Per-user profile receiving the interface setting.
        backend (GuiBackend): Supported toolkit identifier.
    Returns:
        None: Publishes the complete preference atomically.
    Raises:
        OSError: The preference could not be durably staged or replaced.
        ValueError: The supplied toolkit identifier is unsupported.
    """
    if backend not in GUI_BACKENDS:
        raise ValueError("Unsupported GUI backend")
    atomic_write_json(profile / GUI_BACKEND_FILE, {"backend": backend}, require_sync=True)


def gui_startup_directory() -> Path | None:
    """Recognize the handshake directory delivered by the parent launcher.

    Returns:
        Path | None: Existing absolute private directory, or None when the
            process was invoked without the supervised startup environment.
    """
    value = os.environ.get(GUI_STARTUP_DIRECTORY, "")
    directory = Path(value)
    if directory.is_absolute() and not directory.is_symlink() and directory.is_dir():
        return directory
    return None


def configure_frozen_qt_environment() -> None:
    """Set library search paths inside the supervised Windows Qt process.

    Returns:
        None: The pinned wheel locates its app-local plugins, QML and DLLs.
            Source launches keep their ordinary interpreter environment.
    """
    if sys.platform != "win32" or not getattr(sys, "frozen", False):
        return
    runtime = Path(sys._MEIPASS)
    # The pinned Windows wheels embed their own Qt prefix. Keep the loader's
    # plugin and QML lookups inside this instance's replaceable runtime.
    qt = runtime / "PySide6"
    os.environ["QT_PLUGIN_PATH"] = str(qt / "plugins")
    os.environ["QML2_IMPORT_PATH"] = str(qt / "qml")
    os.environ["PATH"] = str(runtime) + os.pathsep + os.environ.get("PATH", "")


def report_gui_startup(
    directory: Path, nonce: str, state: GuiStartupState,
    reason: GuiFallback | None = None,
) -> None:
    """Publish the supervised child's startup decision in one atomic write.

    Args:
        directory (Path): Private temporary handshake directory from the parent.
        nonce (str): Exact launch identity supplied on the child command line.
        state (GuiStartupState): Ready, an activation of the existing GUI,
            or a refusal before any toolkit admitted work.
        reason (GuiFallback | None): Allowlisted explanation for a refusal.
    Returns:
        None: The complete response is ready for the parent to consume.
    Raises:
        OSError: The parent cannot be notified through this launch directory.
    """
    atomic_write_json(directory / GUI_STARTUP_RESPONSE, {
        "nonce": nonce, "state": state, "reason": reason,
    })


def public_executable() -> Path:
    """Return the single public executable for either GUI backend.

    Returns:
        Path: Current common launcher, shared by Tk and supervised Qt processes.
    """
    return Path(sys.executable)


def launcher_command(script: Path, config: str) -> list[str]:
    """Preserve the public source and frozen launcher for a fresh process.

    Args:
        script (Path): Public source launcher when running an interpreter.
        config (str): Original effective --config argument.
    Returns:
        list[str]: Executable and arguments without shell interpolation.
    """
    command = [str(public_executable())]
    if not getattr(sys, "frozen", False):
        command.append(str(script.resolve()))
    return [*command, "--config", config]


def launcher_environment() -> dict[str, str]:
    """Prepare an independent process with no inherited startup handshake.

    Returns:
        dict[str, str]: Current environment minus private startup coordination,
            with the frozen bootloader reset for a fresh run where applicable.
    """
    environment = os.environ.copy()
    environment.pop(GUI_STARTUP_DIRECTORY, None)
    if getattr(sys, "frozen", False):
        # The restarted onedir process owns an independent bootloader runtime.
        environment["PYINSTALLER_RESET_ENVIRONMENT"] = "1"
    return environment


def supervise_gui_startup(command: list[str]) -> GuiFallback | None:
    """Allow one child to initialize Qt before admitting a Tk recovery launch.

    Args:
        command (list[str]): Public launcher, original --config and any tray
            intent. The parent adds the private launch identity.
    Returns:
        GuiFallback | None: None when Qt is ready or an existing owner was
            activated. An allowlisted reason permits one fresh Tk launch.
    Raises:
        OSError: A child could not be proven stopped after a refused startup.
            A recovery GUI must wait to acquire the same profile.
    """
    with tempfile.TemporaryDirectory(prefix="php-gallery-gui-") as temporary:
        directory = Path(temporary)
        nonce = uuid.uuid4().hex
        environment = launcher_environment()
        environment[GUI_STARTUP_DIRECTORY] = str(directory)
        try:
            child = subprocess.Popen(
                [*command, GUI_CHILD_OPTION, nonce], env=environment,
            )
        except OSError:
            return "startup_failed"
        deadline = time.monotonic() + GUI_STARTUP_SECONDS
        reason: GuiFallback = "startup_failed"
        try:
            while time.monotonic() < deadline:
                report = _read_gui_document(directory / GUI_STARTUP_RESPONSE)
                if report.get("nonce") == nonce:
                    state = report.get("state")
                    if state in ("ready", "activated") and report.get("reason") is None:
                        return None
                    reported_reason = report.get("reason")
                    if state == "refused" and reported_reason in GUI_FALLBACK_REASONS:
                        reason = cast(GuiFallback, reported_reason)
                        break
                if child.poll() is not None:
                    # Read once more: the child can publish between the first
                    # observation and the process exit becoming visible.
                    final = _read_gui_document(directory / GUI_STARTUP_RESPONSE)
                    if final.get("nonce") == nonce:
                        state = final.get("state")
                        if state in ("ready", "activated") and final.get("reason") is None:
                            return None
                        if state == "refused" and final.get("reason") in GUI_FALLBACK_REASONS:
                            reason = cast(GuiFallback, final["reason"])
                    break
                time.sleep(GUI_STARTUP_POLL_SECONDS)
        except BaseException:
            if child.poll() is None:
                child.kill()
            child.wait(timeout=GUI_STARTUP_REAP_SECONDS)
            raise
        if child.poll() is None:
            child.kill()
        try:
            child.wait(timeout=GUI_STARTUP_REAP_SECONDS)
        except subprocess.TimeoutExpired as exc:
            raise OSError("Could not confirm Qt startup process exit.") from exc
        return reason
