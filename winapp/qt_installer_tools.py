# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/qt_installer_tools.py
# Module Type: Hosted Qt installer verification
# Purpose: Stage wheel notices and verify a real installed Qt process.
# Responsibilities: Copy licensed wheel texts and verify frozen startup,
#   plugin failure and orderly close on an isolated Windows runner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Prepare notices and verify the replaceable Windows Qt bundle."""

import ctypes
from ctypes import wintypes
import hashlib
from importlib.metadata import distribution
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time
from uuid import uuid4

try:
    from PIL import ImageGrab
except ImportError:
    ImageGrab = None

from uploader.app_linking import ROLLING_UPLOAD_SCOPE
from uploader.config import APP_PROFILE_NAME
from uploader.flight_jobs import SessionPlan
from uploader.gui_backend import (
    GUI_BACKEND_FILE, GUI_CHILD_OPTION, GUI_STARTUP_DIRECTORY, GUI_STARTUP_POLL_SECONDS,
    GUI_STARTUP_RESPONSE, GuiBackend, load_gui_backend,
)
from uploader.state_store import atomic_write_json, linked_upload_state_path
from uploader.watcher.queue import WatchQueueStore, observe_watch_source

# Purpose: Bound cold installed process startup and normal shutdown.
# Type: int. Units: seconds. Scope: one hosted smoke lifecycle.
# Consumers: verify_installation and the installed GUI lifecycle observers.
# Rationale: 60 accommodates cold setup and GUI loading on Windows runners.
INSTALLED_SMOKE_SECONDS = 60
# Purpose: Load Win32 window APIs only from the trusted native directory.
# Type: int. Units: Windows loader flag. Scope: hosted smoke window lookup.
# Consumers: request_window_close. Rationale: documented system-directory flag.
SYSTEM32_LIBRARY_FLAG = 0x800
# Purpose: Ask the owned test window to run its normal close event.
# Type: int. Units: Win32 window message. Scope: hosted smoke window.
# Consumers: request_window_close. Rationale: WM_CLOSE follows real user exit.
WM_CLOSE = 0x0010
# Native process ABI values. A wait-only handle observes ordinary close, and
# ERROR_INVALID_PARAMETER means the isolated GUI PID has already gone away.
_SYNCHRONIZE_PROCESS = 0x00100000
_WAIT_OBJECT_0 = 0
_ERROR_INVALID_PARAMETER = 87
# Purpose: Convert the hosted shutdown budget to the Windows wait API unit.
# Type: int. Units: milliseconds per second. Scope: owned GUI cleanup.
# Consumers: wait_for_desktop_exit. Rationale: Win32 waits use milliseconds.
_MILLISECONDS_PER_SECOND = 1000
# Purpose: Retain the final native setup operations for a failed hosted install.
# Type: int. Units: log lines. Scope: disposable fixture installations.
# Consumers: install_setup. Rationale: keeps the actionable Inno error bounded.
_FAILED_SETUP_LOG_LINES = 30
# Native ABI: WM_GETTEXT, SMTO_ABORTIFHUNG, and the text buffer for one dialog.
WM_GETTEXT = 0x000D
SMTO_ABORTIFHUNG = 0x0002
# Purpose: Preserve bounded native diagnostics from an owned smoke process.
# Type: int. Units: characters per control. Scope: frozen startup dialogs only.
# Consumers: installed_error_dialog. Rationale: 2048 covers narrow boot errors
# without retaining a large modal traceback or allocating an unbounded buffer.
INSTALLER_DIALOG_TEXT_CAPACITY = 2048
# Purpose: Keep a hung frozen process from blocking its diagnostic reader.
# Type: int. Units: milliseconds per Win32 request. Scope: owned static controls.
# Consumers: installed_error_dialog. Rationale: 150 is ample for a modal UI.
INSTALLER_DIALOG_TEXT_TIMEOUT_MS = 150
# Purpose: Let the owned desktop process the preceding native key chord.
# Type: float. Units: seconds. Scope: one hosted GUI input sequence.
# Consumers: send_installed_keys. Rationale: 0.15 spans several display turns.
INPUT_SETTLE_SECONDS = 0.15

# Win32 virtual key codes and INPUT ABI flags for the Settings fixture.
_VK_RETURN, _VK_SHIFT, _VK_CONTROL = 0x0D, 0x10, 0x11
_VK_UP, _VK_DOWN, _VK_B = 0x26, 0x28, 0x42
_INPUT_KEYBOARD, _KEYEVENTF_EXTENDEDKEY, _KEYEVENTF_KEYUP = 1, 0x0001, 0x0002


class _KeyboardInput(ctypes.Structure):
    """Represent an aligned Win32 keyboard input record."""

    _fields_ = [
        ('virtual_key', wintypes.WORD), ('scan_code', wintypes.WORD),
        ('flags', wintypes.DWORD), ('time', wintypes.DWORD), ('extra', ctypes.c_size_t),
    ]


class _MouseInput(ctypes.Structure):
    """Preserve the size and alignment of the largest Win32 INPUT member."""

    _fields_ = [
        ('x', wintypes.LONG), ('y', wintypes.LONG), ('data', wintypes.DWORD),
        ('flags', wintypes.DWORD), ('time', wintypes.DWORD), ('extra', ctypes.c_size_t),
    ]


class _InputPayload(ctypes.Union):
    """Represent the keyboard and size-defining mouse alternatives of INPUT."""

    _fields_ = [('keyboard', _KeyboardInput), ('mouse', _MouseInput)]


class _InputRecord(ctypes.Structure):
    """Represent one Win32 INPUT event passed to SendInput."""

    _fields_ = [('kind', wintypes.DWORD), ('payload', _InputPayload)]


def collect_wheel_notices(directory: Path) -> None:
    """Copy the exact installed wheels' license texts outside the app archive.

    Args:
        directory (Path): New builder-owned directory for LGPL and third-party notices.
    Returns:
        None: Reproduces wheel-provided license texts with their original names.
    Raises:
        RuntimeError: A pinned distribution does not match the required version.
    """
    requirements = Path(__file__).with_name('requirements-qt.txt')
    pins = dict(line.split('==', 1) for line in requirements.read_text(encoding='utf-8').splitlines()
                if line and not line.startswith(('#', '-')) and '==' in line)
    # Windows Essentials wheels do not carry the complete LGPL/GPL text.
    # Include the verbatim Qt 6.10.3 source texts with any wheel notices.
    shutil.copytree(Path(__file__).with_name('licenses') / 'qt', directory)
    for name in ('PySide6-Essentials', 'shiboken6'):
        package = distribution(name)
        if package.version != pins.get(name):
            raise RuntimeError('Qt build package does not match its source pin: ' + name)
        notices = directory / name
        for entry in package.files or ():
            if not any(part.lower().startswith(('license', 'copying', 'notice')) for part in entry.parts):
                continue
            source = Path(package.locate_file(entry))
            if not source.is_file():
                continue
            destination = notices.joinpath(*entry.parts)
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, destination)


def installed_window(pid: int) -> int | None:
    """Find a visible native window of the exact installed process.

    Args:
        pid (int): Isolated installed process previously started by this fixture.
    Returns:
        int | None: A visible native HWND or None when none is present.
    """
    user = ctypes.WinDLL('user32.dll', use_last_error=True, winmode=SYSTEM32_LIBRARY_FLAG)
    enum_callback = ctypes.WINFUNCTYPE(wintypes.BOOL, wintypes.HWND, wintypes.LPARAM)
    user.EnumWindows.argtypes = [enum_callback, wintypes.LPARAM]
    user.EnumWindows.restype = wintypes.BOOL
    user.GetWindowThreadProcessId.argtypes = [wintypes.HWND, ctypes.POINTER(wintypes.DWORD)]
    user.IsWindowVisible.argtypes = [wintypes.HWND]
    result: int | None = None

    @enum_callback
    def find(window: int, _data: int) -> bool:
        """Select one visible window owned by the exact fixture PID.

        Args:
            window (int): Current top-level HWND from the native enumerator.
            _data (int): Unused LPARAM required by EnumWindows.
        Returns:
            bool: Continue until a visible window of the fixture process is found.
        """
        nonlocal result
        owner = wintypes.DWORD()
        user.GetWindowThreadProcessId(window, ctypes.byref(owner))
        if owner.value == pid and user.IsWindowVisible(window):
            result = window
        return result is None

    user.EnumWindows(find, 0)
    return result


def request_window_close(pid: int) -> bool:
    """Post normal close to the exact installed fixture process.

    Args:
        pid (int): Isolated installed application or helper process.
    Returns:
        bool: The native window accepted the request.
    """
    window = installed_window(pid)
    if window is None:
        return False
    user = ctypes.WinDLL('user32.dll', use_last_error=True, winmode=SYSTEM32_LIBRARY_FLAG)
    user.PostMessageW.argtypes = [wintypes.HWND, wintypes.UINT, wintypes.WPARAM, wintypes.LPARAM]
    return bool(user.PostMessageW(window, WM_CLOSE, 0, 0))


def installed_error_dialog(pid: int) -> str:
    """Read a native error box belonging to the isolated smoke process.

    Args:
        pid (int): Native process ID of the fresh, config-free smoke run.
    Returns:
        str: Bounded dialog text or an empty string when no dialog is visible.
    """
    window = installed_window(pid)
    if window is None:
        return ''
    user = ctypes.WinDLL('user32.dll', use_last_error=True, winmode=SYSTEM32_LIBRARY_FLAG)
    callback = ctypes.WINFUNCTYPE(wintypes.BOOL, wintypes.HWND, wintypes.LPARAM)
    user.EnumChildWindows.argtypes = [wintypes.HWND, callback, wintypes.LPARAM]
    user.GetWindowTextW.argtypes = [wintypes.HWND, wintypes.LPWSTR, ctypes.c_int]
    user.SendMessageTimeoutW.argtypes = [
        wintypes.HWND, wintypes.UINT, wintypes.WPARAM, wintypes.LPARAM,
        wintypes.UINT, wintypes.UINT, ctypes.POINTER(ctypes.c_size_t),
    ]
    title = ctypes.create_unicode_buffer(INSTALLER_DIALOG_TEXT_CAPACITY)
    user.GetWindowTextW(window, title, len(title))
    paragraphs = [title.value] if title.value else []

    @callback
    def read_text(control: int, _unused: int) -> bool:
        """Collect one system-marshaled native control caption.

        Args:
            control (int): HWND of a child control of the owned window.
            _unused (int): Unused native enumerator context.
        Returns:
            bool: True to enumerate remaining dialog controls.
        """
        buffer = ctypes.create_unicode_buffer(INSTALLER_DIALOG_TEXT_CAPACITY)
        length = ctypes.c_size_t()
        accepted = user.SendMessageTimeoutW(
            control, WM_GETTEXT, len(buffer), ctypes.addressof(buffer),
            SMTO_ABORTIFHUNG, INSTALLER_DIALOG_TEXT_TIMEOUT_MS, ctypes.byref(length),
        )
        if accepted and length.value and buffer.value:
            paragraphs.append(buffer.value)
        return True

    user.EnumChildWindows(window, read_text, 0)
    return '\n'.join(paragraphs)[:INSTALLER_DIALOG_TEXT_CAPACITY]


def send_installed_keys(pid: int, keys: tuple[int, ...]) -> None:
    """Send a keyboard chord only when the fixture owns the foreground window.

    Args:
        pid (int): Actual effective desktop process of the disposable fixture.
        keys (tuple[int, ...]): Fixed virtual keys in modifier-first order.
    Returns:
        None: The desktop accepted the complete down/up sequence.
    Raises:
        RuntimeError: The owned GUI is absent, cannot be focused or rejects input.
    """
    user = ctypes.WinDLL('user32.dll', use_last_error=True, winmode=SYSTEM32_LIBRARY_FLAG)
    user.GetForegroundWindow.restype = wintypes.HWND
    user.SetForegroundWindow.argtypes = [wintypes.HWND]
    user.GetWindowThreadProcessId.argtypes = [wintypes.HWND, ctypes.POINTER(wintypes.DWORD)]
    user.SendInput.argtypes = [wintypes.UINT, ctypes.POINTER(_InputRecord), ctypes.c_int]
    user.SendInput.restype = wintypes.UINT
    owner = wintypes.DWORD()
    user.GetWindowThreadProcessId(user.GetForegroundWindow(), ctypes.byref(owner))
    if owner.value != pid:
        window = installed_window(pid)
        if window is None:
            raise RuntimeError('The hosted Settings window is not visible.')
        user.SetForegroundWindow(window)
        time.sleep(INPUT_SETTLE_SECONDS)
        user.GetWindowThreadProcessId(user.GetForegroundWindow(), ctypes.byref(owner))
        if owner.value != pid:
            raise RuntimeError('The hosted Settings window could not receive foreground input.')
    # The extended flag selects the dedicated arrow keys on Windows instead
    # of their numeric-keypad equivalents under the active keyboard layout.
    sequence = [(key, _KEYEVENTF_EXTENDEDKEY if key in (_VK_UP, _VK_DOWN) else 0) for key in keys]
    sequence.extend((key, flags | _KEYEVENTF_KEYUP) for key, flags in reversed(tuple(sequence)))
    events = (_InputRecord * len(sequence))(*(
        _InputRecord(_INPUT_KEYBOARD, _InputPayload(keyboard=_KeyboardInput(key, 0, flags, 0, 0)))
        for key, flags in sequence
    ))
    if user.SendInput(len(events), events, ctypes.sizeof(_InputRecord)) != len(events):
        raise RuntimeError('The hosted Settings control did not receive native input.')
    time.sleep(INPUT_SETTLE_SECONDS)


def wait_installed_backend(log: Path, backend: GuiBackend, previous: int | None) -> int:
    """Observe a new visible backend process with its actual readiness log.

    Args:
        log (Path): Owned profile log for this exact installation.
        backend (GuiBackend): Expected effective toolkit after the restart.
        previous (int | None): Earlier toolkit process that must have closed.
    Returns:
        int: Process ID owning the current visible window.
    Raises:
        RuntimeError: Effective toolkit or orderly previous exit is absent.
    """
    deadline = time.monotonic() + INSTALLED_SMOKE_SECONDS
    contents = ''
    while time.monotonic() < deadline:
        try:
            contents = log.read_text(encoding='utf-8', errors='replace')
        except FileNotFoundError:
            contents = ''
        owners = re.findall(rf'Desktop backend ready: {backend}; pid=(\d+)', contents)
        if owners:
            pid = int(owners[-1])
            previous_closed = previous is None or f'phase=mainloop_returned; pid={previous}' in contents
            if previous_closed and pid != previous and installed_window(pid) is not None:
                return pid
        time.sleep(GUI_STARTUP_POLL_SECONDS)
    for line in contents.splitlines():
        if ('[watcher-process]' in line or 'Desktop backend ready:' in line
                or 'Qt startup fallback:' in line or 'Shutdown wait expired for' in line
                or 'Desktop restart confirmation ready;' in line
                or 'Desktop restart accepted;' in line or 'Desktop restart deferred;' in line):
            print(line, flush=True)
    raise RuntimeError('The installed app did not reach Settings readiness for ' + backend + '.')


def choose_installed_backend(pid: int, profile: Path, backend: GuiBackend, log: Path) -> None:
    """Choose the real Settings backend control and accept its restart prompt.

    Args:
        pid (int): Current owned installed desktop process.
        profile (Path): Shared preference directory of this fixture.
        backend (GuiBackend): Toolkit selected for the next process.
        log (Path): Isolated app's native readiness and lifecycle log.
    Returns:
        None: The actual Settings control persisted the choice and accepted restart.
    Raises:
        RuntimeError: The Settings picker did not save the requested backend.
    """
    prior_backend = 'tkinter' if backend == 'pyside6' else 'pyside6'
    prompt = 'Desktop restart confirmation ready; backend=' + prior_backend
    previous_prompts = log.read_text(encoding='utf-8', errors='replace').count(prompt)
    send_installed_keys(pid, (_VK_CONTROL, _VK_SHIFT, _VK_B))
    if backend == 'pyside6':
        # Tk's read-only ttk Combobox opens its list on Down. A second Down
        # selects the adjacent already-rendered choice, which Return commits.
        send_installed_keys(pid, (_VK_DOWN,))
        send_installed_keys(pid, (_VK_DOWN,))
        send_installed_keys(pid, (_VK_RETURN,))
    else:
        # QComboBox activates the previous item on Up when the list is closed.
        send_installed_keys(pid, (_VK_UP,))
    deadline = time.monotonic() + INSTALLED_SMOKE_SECONDS
    while time.monotonic() < deadline:
        if ((profile / GUI_BACKEND_FILE).is_file() and load_gui_backend(profile) == backend
                and log.read_text(encoding='utf-8', errors='replace').count(prompt) > previous_prompts):
            # The real prompt leaves Later as its default. Ctrl+Enter invokes
            # the affirmative Restart now button regardless of tab order.
            send_installed_keys(pid, (_VK_CONTROL, _VK_RETURN))
            return
        time.sleep(GUI_STARTUP_POLL_SECONDS)
    observed = load_gui_backend(profile)
    visible = installed_error_dialog(pid)
    if visible:
        print('Active Settings window: ' + visible, flush=True)
    raise RuntimeError(f'The installed Settings picker or restart prompt for {backend} is missing. Saved: {observed}.')


def wait_for_desktop_exit(pid: int) -> None:
    """Wait for the exact hosted GUI to unload its application runtime.

    Args:
        pid (int): Recently observed GUI PID in the fixture-owned profile.
    Returns:
        None: The native process is gone and its image can be replaced.
    Raises:
        OSError: Opening a wait-only handle to a live process failed.
        RuntimeError: The owned process did not exit within its hosted budget.
    """
    kernel = ctypes.WinDLL('kernel32.dll', use_last_error=True, winmode=SYSTEM32_LIBRARY_FLAG)
    kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
    kernel.OpenProcess.restype = wintypes.HANDLE
    kernel.WaitForSingleObject.argtypes = [wintypes.HANDLE, wintypes.DWORD]
    kernel.WaitForSingleObject.restype = wintypes.DWORD
    kernel.CloseHandle.argtypes = [wintypes.HANDLE]
    kernel.CloseHandle.restype = wintypes.BOOL
    handle = kernel.OpenProcess(_SYNCHRONIZE_PROCESS, False, pid)
    if not handle:
        error = ctypes.get_last_error()
        if error == _ERROR_INVALID_PARAMETER:
            return
        raise ctypes.WinError(error)
    try:
        if kernel.WaitForSingleObject(handle, INSTALLED_SMOKE_SECONDS * _MILLISECONDS_PER_SECOND) != _WAIT_OBJECT_0:
            raise RuntimeError('The hosted desktop process did not finish unloading its image.')
    finally:
        kernel.CloseHandle(handle)


def verify_installed_switches(
    executable: Path, profile_root: Path, environment: dict[str, str], version: str,
    evidence: Path | None = None,
) -> None:
    """Start Qt by default and switch both ways with retained profile history.

    Args:
        executable (Path): The single installed application executable.
        profile_root (Path): New disposable user-data root.
        environment (dict[str, str]): System-only process search path.
        version (str): Current version used to suppress unrelated update traffic.
        evidence (Path | None): Hosted artifact directory for an owned failure window.
    Returns:
        None: The unconfigured profile starts Qt. The Settings round trip and
            shutdown preserve an old linked outcome and the closed flight's
            retained queue and original photograph.
    Raises:
        RuntimeError: The owned Settings journey or exact profile proof failed.
    """
    profile = profile_root / APP_PROFILE_NAME
    profile.mkdir(parents=True)
    original_source = profile_root / 'synthetic-upload.jpg'
    prior_target = hashlib.sha256(b'prior synthetic linked destination').hexdigest()
    original_digest = hashlib.sha256(b'unavailable synthetic original').hexdigest()
    history = linked_upload_state_path(profile, prior_target)
    atomic_write_json(history, {
        # Schema 2 is the existing linked upload journal format.
        'schema_version': 2, 'uploaded_paths': {}, 'uploaded_hashes': {},
        'jobs': {}, 'recent_events': [], 'reviewed_upload_attempts': [],
        'failures': {str(original_source.resolve()): {
            'sha256': original_digest, 'uncertain': True,
            'last_error': 'Synthetic prior upload outcome remains uncertain.',
            'last_failed_at': time.time(),
        }},
    })
    archived_flight = profile / 'flights' / uuid4().hex
    archived_flight.mkdir(parents=True)
    SessionPlan(
        archived_flight, prior_target, (), False,
        completed=True, local_only=True, abandoned=True,
    ).save()
    metadata = archived_flight / 'session.json'
    atomic_write_json(metadata, {'recording_ended': True})
    photo = (profile_root / 'archived-capture.jpg').resolve()
    photo.write_bytes(b'Synthetic retained flight photograph.')
    journal = archived_flight / 'watch-drain.json'
    queue = WatchQueueStore(journal, prior_target, photo.parent, False, scope=ROLLING_UPLOAD_SCOPE)
    queue.initialize([])
    queue.reconcile([observe_watch_source(photo, time.time())])
    queue.finish(time.time())
    legacy = WatchQueueStore.read_journal(journal)
    if legacy['pending'] != [str(photo)]:
        raise RuntimeError('The archived Settings fixture did not retain its queued photograph.')
    # Use the existing schema-2 shape from before local force-finish journals.
    for key in ('force_finished', 'force_finished_at', 'retained_stop'):
        legacy.pop(key)
    atomic_write_json(journal, legacy)
    retained = {
        path: path.read_bytes() for path in (
            history, photo, metadata, journal, archived_flight / 'completion.json',
        )
    }
    config = profile_root / 'fixture.json'
    config.write_text(json.dumps({'close_to_tray': False, 'start_with_windows': False}), encoding='utf-8')
    local = profile_root / 'local'
    updates = local / APP_PROFILE_NAME / 'updates'
    updates.mkdir(parents=True)
    (updates / 'state.json').write_text(json.dumps({
        'last_check': time.time(), 'version': version, 'available': None,
    }), encoding='utf-8')
    log = profile / 'watcher.log'
    launcher = subprocess.Popen(
        [str(executable), '--config', str(config)], cwd=executable.parent,
        env={**environment, 'APPDATA': str(profile_root), 'LOCALAPPDATA': str(local)},
    )
    pid: int | None = None
    try:
        pid = wait_installed_backend(log, 'pyside6', None)
        if (profile / GUI_BACKEND_FILE).exists():
            raise RuntimeError('Default Qt startup unexpectedly wrote a backend preference.')
        for backend in ('tkinter', 'pyside6'):
            started = time.monotonic()
            choose_installed_backend(pid, profile, backend, log)
            pid = wait_installed_backend(log, backend, pid)
            if original_source.exists() or any(path.read_bytes() != saved for path, saved in retained.items()):
                raise RuntimeError('The Settings restart changed retained upload or flight history.')
            print(f'Installed Settings switched to {backend} in {time.monotonic() - started:.2f}s.', flush=True)
        if not request_window_close(pid):
            raise RuntimeError('The Settings round trip did not close normally.')
        deadline = time.monotonic() + INSTALLED_SMOKE_SECONDS
        while time.monotonic() < deadline:
            if f'phase=mainloop_returned; pid={pid}' in log.read_text(encoding='utf-8', errors='replace'):
                wait_for_desktop_exit(pid)
                if original_source.exists() or any(path.read_bytes() != saved for path, saved in retained.items()):
                    raise RuntimeError('The final shutdown changed retained upload or flight history.')
                print('Both installed backends retained exact linked and closed flight history.', flush=True)
                return
            time.sleep(GUI_STARTUP_POLL_SECONDS)
        raise RuntimeError('The final installed Qt process did not finish shutdown.')
    except (RuntimeError, OSError):
        window = installed_window(pid) if pid is not None else None
        if evidence is not None and window is not None and ImageGrab is not None:
            try:
                ImageGrab.grab(window=window).save(evidence / 'settings-restart-failure.png')
            except OSError as error:
                print('Unable to capture the owned installer test window: ' + type(error).__name__, flush=True)
        raise
    finally:
        if pid is not None:
            request_window_close(pid)
        if launcher.poll() is None:
            launcher.terminate()
            launcher.wait(timeout=INSTALLED_SMOKE_SECONDS)


def observe_qt_start(
    executable: Path, profile: Path, environment: dict[str, str],
) -> float:
    """Exercise first rendered readiness and ordinary close after setup.

    Args:
        executable (Path): Installed application providing the requested Qt frontend.
        profile (Path): Unrelated fixture profile root for this exact attempt.
        environment (dict[str, str]): Isolated builder subprocess environment.
    Returns:
        float: Elapsed seconds to readiness.
    Raises:
        RuntimeError: Incorrect readiness, premature exit or unclean shutdown.
    """
    handshake = profile / 'handshake'
    handshake.mkdir(parents=True)
    config = profile / 'fixture.json'
    # The real default hides ordinary window close in the tray. This fixture
    # chooses the visible ordinary-exit behavior to test worker teardown.
    config.write_text(json.dumps({'close_to_tray': False, 'start_with_windows': False}), encoding='utf-8')
    nonce = uuid4().hex
    child_environment = {
        **environment, GUI_STARTUP_DIRECTORY: str(handshake),
        'APPDATA': str(profile), 'LOCALAPPDATA': str(profile / 'local'),
        'PYINSTALLER_RESET_ENVIRONMENT': '1',
    }
    started = time.monotonic()
    child = subprocess.Popen(
        [str(executable), '--config', str(config), GUI_CHILD_OPTION, nonce],
        cwd=executable.parent, env=child_environment,
    )
    response = handshake / GUI_STARTUP_RESPONSE
    try:
        while time.monotonic() - started < INSTALLED_SMOKE_SECONDS:
            if response.is_file():
                document = json.loads(response.read_text(encoding='utf-8'))
                if document.get('nonce') != nonce:
                    raise RuntimeError('Frozen Qt smoke received a foreign startup response.')
                if document.get('state') == 'ready':
                    elapsed = time.monotonic() - started
                    print(f'Frozen Qt rendered its first scene in {elapsed:.2f}s.', flush=True)
                    if not request_window_close(child.pid):
                        raise RuntimeError('The installed Qt window could not be closed.')
                    if child.wait(timeout=INSTALLED_SMOKE_SECONDS) != 0:
                        raise RuntimeError('The installed Qt window did not close normally.')
                    return elapsed
            if child.poll() is not None:
                raise RuntimeError(f'The installed Qt process exited before readiness ({child.returncode}).')
            time.sleep(GUI_STARTUP_POLL_SECONDS)
        raise RuntimeError('The installed Qt process did not complete startup within the hosted budget.')
    finally:
        if child.poll() is None:
            child.kill()
            child.wait(timeout=INSTALLED_SMOKE_SECONDS)


def observe_installed_tk(
    executable: Path, profile: Path, environment: dict[str, str], version: str,
    *, require_qt_fallback: bool = False, require_clean_imports: bool = False,
) -> float:
    """Exercise the installed public launcher's stable graphical entry point.

    Args:
        executable (Path): Installed public stable executable.
        profile (Path): Fresh hosted profile for the exact launch.
        environment (dict[str, str]): Isolated fixture subprocess environment.
        version (str): Exact package version for the fixture's update cache.
        require_qt_fallback (bool): Use the unconfigured Qt default and require its
            failed start before Legacy Tk. Other common installs select Legacy Tk
            explicitly, while the published onefile build uses its original CLI.
        require_clean_imports (bool): Require the stable startup import record
            from the new common packaging.
    Returns:
        float: Seconds to the first visible Tk window followed by normal quit.
    Raises:
        RuntimeError: The required startup or shutdown cannot be verified.
    """
    profile.mkdir()
    config = profile / 'fixture.json'
    config.write_text(json.dumps({'close_to_tray': False, 'start_with_windows': False}), encoding='utf-8')
    updates = profile / 'local' / APP_PROFILE_NAME / 'updates'
    updates.mkdir(parents=True)
    (updates / 'state.json').write_text(json.dumps({
        'last_check': time.time(), 'version': version, 'available': None,
    }), encoding='utf-8')
    started = time.monotonic()
    command = [str(executable), '--config', str(config)]
    if not require_qt_fallback and (executable.parent / '_internal').is_dir():
        command.extend(('--gui-backend', 'tkinter'))
    child = subprocess.Popen(
        command,
        env={**environment, 'APPDATA': str(profile), 'LOCALAPPDATA': str(profile / 'local')},
        cwd=executable.parent,
    )
    log = profile / APP_PROFILE_NAME / 'watcher.log'
    first_window: float | None = None
    try:
        while time.monotonic() - started < INSTALLED_SMOKE_SECONDS:
            try:
                output = log.read_text(encoding='utf-8', errors='replace')
            except FileNotFoundError:
                output = ''
            starts = re.findall(r'phase=start; reason=manual; pid=(\d+); version=', output)
            if starts and (not require_qt_fallback or 'Qt startup fallback:' in output):
                pid = int(starts[-1])
                if f'phase=mainloop_returned; pid={pid}' in output:
                    if child.wait(timeout=INSTALLED_SMOKE_SECONDS) != 0 or first_window is None:
                        raise RuntimeError('The installed Tk window did not close normally.')
                    if require_clean_imports and 'Frozen Tk startup imported Qt: no' not in output:
                        raise RuntimeError('Stable Tk startup loaded a PySide6 module.')
                    if pid != child.pid:
                        wait_for_desktop_exit(pid)
                    return first_window
                # The first close dismisses the fallback notice. A subsequent
                # close reaches the visible stable window with tray disabled.
                if request_window_close(pid) and first_window is None:
                    first_window = time.monotonic() - started
            if child.poll() not in (None, 0):
                raise RuntimeError('The public Tk launcher failed before verification.')
            time.sleep(GUI_STARTUP_POLL_SECONDS)
        raise RuntimeError('The installed launcher did not complete the Tk window lifecycle.')
    finally:
        if child.poll() is None:
            child.kill()
            child.wait(timeout=INSTALLED_SMOKE_SECONDS)


def verify_qt_replacement(
    executable: Path, work: Path, environment: dict[str, str],
) -> None:
    """Start the installed application with a byte-distinct ABI-compatible library.

    Args:
        executable (Path): Common application executable under the owned fixture.
        work (Path): Builder staging directory for the original DLL and profile.
        environment (dict[str, str]): Isolated builder subprocess environment.
    Returns:
        None: The replacement is loaded through a first frame and normal close.
    Raises:
        RuntimeError: Installed library, notice evidence or startup is missing.
    """
    runtime = executable.parent / '_internal'
    notices = executable.parent / 'licenses'
    source_notices = Path(__file__).with_name('licenses') / 'qt'
    for name in ('LGPL-3.0-only.txt', 'GPL-3.0-only.txt'):
        if (not (notices / name).is_file()
                or (notices / name).read_bytes() != (source_notices / name).read_bytes()):
            raise RuntimeError('Qt license text was not installed intact: ' + name)
    notice = Path(__file__).with_name('QT-LICENSE.txt').read_bytes()
    if (notices / 'QT-LICENSE.txt').read_bytes() != notice:
        raise RuntimeError('Qt corresponding-source and third-party notice was changed.')
    libraries = tuple(runtime.rglob('Qt6Core.dll'))
    if len(libraries) != 1:
        raise RuntimeError('Expected one independently replaceable Qt Core DLL.')
    library = libraries[0]
    backup = work / 'original-qt-library.dll'
    library.replace(backup)
    try:
        shutil.copyfile(backup, library)
        # The Windows loader maps PE sections by their headers. A trailer
        # changes the bytes without changing any code, export or ABI.
        with library.open('ab') as modified:
            modified.write(b'PHP Gallery hosted ABI replacement fixture')
        observe_qt_start(executable, work / 'qt-replacement-profile', environment)
        print('Installed Qt license texts and ABI-compatible DLL replacement verified.', flush=True)
    finally:
        backup.replace(library)


def install_setup(setup: Path, installed: Path, work: Path, environment: dict[str, str]) -> None:
    """Run the standard setup against a dedicated hosted install directory.

    Args:
        setup (Path): Exact freshly built common setup.
        installed (Path): Owning temporary installation directory.
        work (Path): Disposable staging directory.
        environment (dict[str, str]): System-only runtime environment.
    Returns:
        None: The silent setup exits successfully.
    Raises:
        subprocess.SubprocessError: Setup failed or exceeded the hosted budget.
    """
    log = work / 'desktop-setup.log'
    log.unlink(missing_ok=True)
    try:
        subprocess.run(
            [str(setup), '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', '/NOICONS',
             '/SELFUPDATE=1', '/DIR=' + str(installed), '/LOG=' + str(log)],
            cwd=work, env=environment, check=True, timeout=INSTALLED_SMOKE_SECONDS,
        )
    except subprocess.SubprocessError:
        if log.is_file():
            lines = log.read_text(encoding='utf-8-sig', errors='replace').splitlines()
            print('\n'.join(lines[-_FAILED_SETUP_LOG_LINES:]), flush=True)
        raise


def system_only_environment(environment: dict[str, str]) -> dict[str, str]:
    """Prevent installed smokes from inheriting runner SDK or Python lookup.

    Args:
        environment (dict[str, str]): Builder environment with owned temp paths.
    Returns:
        dict[str, str]: System PATH and no ambient Tk, Python or Qt overrides.
    """
    environment = {
        key.upper(): value for key, value in environment.items()
        if not key.upper().startswith(('PYTHON', 'PYINSTALLER', 'QT_', 'QML', 'TCL_', 'TK_'))
    }
    windows = Path(environment['SYSTEMROOT'])
    environment['PATH'] = str(windows / 'System32') + ';' + str(windows)
    return environment


def verify_installation(
    setup: Path, work: Path, environment: dict[str, str], *, version: str,
    evidence: Path | None = None,
) -> None:
    """Verify both installed frontends from one app-local runtime in the hosted VM.

    Args:
        setup (Path): Candidate setup EXE built in the current audit.
        work (Path): Builder-owned staging directory on the hosted machine.
        environment (dict[str, str]): Current isolated subprocess environment.
        version (str): Setup version reused by the owned GUI smoke profiles.
        evidence (Path | None): Hosted artifact directory for UI diagnostics.
    Returns:
        None: Records real install, both backends, library replacement and fallback.
    """
    runtime_env = system_only_environment(environment)
    installed = work / 'installed-desktop-smoke'
    install_setup(setup, installed, work, runtime_env)
    application = installed / 'PHPGalleryUploader.exe'
    if tuple(installed.rglob(application.name)) != (application,):
        raise RuntimeError('Setup must install exactly one application executable.')
    stable = observe_installed_tk(
        application, work / 'tk-profile', runtime_env, version, require_clean_imports=True,
    )
    print(f'Installed Tk visible window and normal close verified in {stable:.2f}s.', flush=True)
    observed = observe_qt_start(application, work / 'qt-profile', runtime_env)
    print(f'Installed Qt first scene and normal close verified in {observed:.2f}s.', flush=True)
    verify_qt_replacement(application, work, runtime_env)
    for filename, fault in (
        ('qwindows.dll', 'missing'), ('Qt6Core.dll', 'missing'), ('Qt6Core.dll', 'invalid'),
    ):
        files = tuple((installed / '_internal').rglob(filename))
        if len(files) != 1:
            raise RuntimeError('Expected one installed Qt dependency: ' + filename)
        library = files[0]
        backup = work / f'{fault}-{filename}'
        library.replace(backup)
        if fault == 'invalid':
            library.write_bytes(b'Invalid native image used by the hosted installer fixture')
        observe_installed_tk(
            application, work / f'profile-{fault}-{filename}', runtime_env,
            version, require_qt_fallback=True, require_clean_imports=True,
        )
        install_setup(setup, installed, work, runtime_env)
        if (not library.is_file() or library.read_bytes() != backup.read_bytes()
                or (installed / '_internal.winapp-previous').exists()):
            raise RuntimeError('The standard setup did not repair its app-local dependency: ' + filename)
        print(f'Installed Tk fallback and standard setup repair verified for {fault} {filename}.', flush=True)
    verify_installed_switches(application, work / 'settings-round-trip', runtime_env, version, evidence)


def main() -> int:
    """Stage component notices or verify one exact installer on hosted Windows.

    Returns:
        int: Zero for successful notice staging or native desktop verification.
            One for a verified failure and two for unsupported inputs.
    """
    if len(sys.argv) == 2:
        collect_wheel_notices(Path(sys.argv[1]))
        return 0
    if (len(sys.argv) != 5 or sys.argv[1] != '--verify-installed'
            or os.environ.get('GITHUB_ACTIONS') != 'true' or sys.platform != 'win32'):
        return 2
    try:
        setup = Path(sys.argv[2]).resolve(strict=True)
        evidence = Path(sys.argv[4]).resolve(strict=True)
        with tempfile.TemporaryDirectory(prefix='php-gallery-installed-desktop-') as directory:
            verify_installation(setup, Path(directory), dict(os.environ), version=sys.argv[3], evidence=evidence)
    except (OSError, RuntimeError, subprocess.SubprocessError, ValueError) as error:
        print(str(error), file=sys.stderr, flush=True)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
