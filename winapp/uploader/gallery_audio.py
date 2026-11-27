# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_audio.py
# Module Type: Native desktop media service
# Purpose: Play a previously verified MP3 or WAV through the host operating system.
# Responsibilities: Restrict DLL lookup and manage only owned players and files.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Use the same Windows and macOS audio devices from the desktop frontends."""

import ctypes
import os
from pathlib import Path
import signal
import subprocess
import sys
from typing import Callable, TypeAlias
from uuid import uuid4

MciSender: TypeAlias = Callable[[str, ctypes.c_wchar_p | None, int, int | None], int]

# Purpose: Detect a completed track promptly while playback is active.
# Type: int. Units: milliseconds. Scope: one active native track.
# Consumers: Tk Photos and Qt gallery playback loops.
# Rationale: 100 ms is the existing playlist handoff interval.
MEDIA_AUDIO_POLL_MS = 100
# Purpose: Bound the Windows playback mode reply.
# Type: int. Units: wide characters. Scope: one mode query.
# Consumers: NativeGalleryAudio.running and Tk's native audio status.
# Rationale: 32 accommodates the documented MCI mode names.
MEDIA_MCI_MODE_CAPACITY = 32
# Purpose: Reap a stopped macOS audio child without a long GUI wait.
# Type: float. Units: seconds. Scope: one terminated afplay child.
# Consumers: NativeGalleryAudio.stop and Tk audio teardown.
# Rationale: 0.2 seconds preserves the existing termination budget.
MEDIA_AUDIO_REAP_SECONDS = 0.2
# Purpose: Resolve the operating system's multimedia library path.
# Type: int. Units: wide characters. Scope: GetSystemDirectoryW result.
# Consumers: load_winmm.
# Rationale: 32768 accommodates the supported maximum Windows path.
WINDOWS_SYSTEM_DIRECTORY_BUFFER = 32768
# Purpose: Restrict native library lookup to Windows System32.
# Type: int. Units: LoadLibraryEx flag bits. Scope: kernel32 and winmm loading.
# Consumers: load_winmm.
# Rationale: Prevents lookup through the application or working directory.
LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x00000800


def load_winmm() -> tuple[ctypes.CDLL, MciSender]:
    """Load the system's Unicode multimedia command entry point.

    Returns:
        tuple[ctypes.CDLL, MciSender]: Retained library and typed entry point.
    Raises:
        OSError: Windows multimedia or its system directory is unavailable.
    """
    if sys.platform != 'win32' or not hasattr(ctypes, 'WinDLL'):
        raise OSError('Windows multimedia is unavailable')
    kernel32 = ctypes.WinDLL('kernel32.dll', use_last_error=True, winmode=LOAD_LIBRARY_SEARCH_SYSTEM32)
    system_directory = kernel32.GetSystemDirectoryW
    system_directory.argtypes = [ctypes.c_wchar_p, ctypes.c_uint]
    system_directory.restype = ctypes.c_uint
    buffer = ctypes.create_unicode_buffer(WINDOWS_SYSTEM_DIRECTORY_BUFFER)
    length = system_directory(buffer, len(buffer))
    if length == 0 or length >= len(buffer):
        raise OSError('Could not resolve Windows System32')
    path = os.path.join(buffer.value, 'winmm.dll')
    if not os.path.isabs(path):
        raise OSError('Windows multimedia path is not absolute')
    library = ctypes.WinDLL(path, use_last_error=True, winmode=LOAD_LIBRARY_SEARCH_SYSTEM32)
    mci = library.mciSendStringW
    mci.argtypes = [ctypes.c_wchar_p, ctypes.c_wchar_p, ctypes.c_uint, ctypes.c_void_p]
    mci.restype = ctypes.c_uint
    return library, mci


def send_mci(mci: MciSender, command: str) -> None:
    """Send one locally constructed command to the owned Windows device.

    Args:
        mci (MciSender): System multimedia function.
        command (str): Fixed action and locally generated player alias.
    Returns:
        None: The native command completed.
    Raises:
        OSError: The audio device refused the command.
    """
    result = mci(command, None, 0, None)  # Structural no-result command.
    if result:
        raise OSError(f'MCI command failed: {result}')


def remove_audio_file(path: Path) -> None:
    """Unlink one exact temporary file owned by the playback request.

    Args:
        path (Path): Local generated audio pathname.
    Returns:
        None: Cleanup is harmless if already complete or refused by the OS.
    """
    try:
        path.unlink(missing_ok=True)
    except OSError:
        pass


class NativeGalleryAudio:
    """Own one native player and the verified temporary file it has open."""

    def __init__(self) -> None:
        """Prepare an idle player with no native handles.

        Returns:
            None: No process or device is created until explicit playback.
        """
        self._path: Path | None = None
        self._process: subprocess.Popen[bytes] | None = None
        self._library: ctypes.CDLL | None = None
        self._mci: MciSender | None = None
        self._alias: str | None = None

    def start(self, path: Path) -> None:
        """Play a bounded, verified file transferred by its current caller.

        Args:
            path (Path): Temporary MP3 or WAV owned by this request.
        Returns:
            None: Transfers cleanup ownership even when startup fails.
        Raises:
            OSError: The native player cannot open or play the file.
            ValueError: The temporary path cannot safely be used by the player.
        """
        self.stop()
        self._path = path
        try:
            if sys.platform == 'darwin':
                self._process = subprocess.Popen(
                    ['/usr/bin/afplay', str(path)], stdin=subprocess.DEVNULL,
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                )
            else:
                if '"' in str(path) or path.suffix not in ('.mp3', '.wav'):
                    raise ValueError('Unsupported temporary path')
                alias = 'pglink' + uuid4().hex
                self._library, self._mci = load_winmm()
                media_type = 'mpegvideo' if path.suffix == '.mp3' else 'waveaudio'
                send_mci(self._mci, f'open "{path}" type {media_type} alias {alias}')
                self._alias = alias
                send_mci(self._mci, f'play {alias}')
        except (OSError, ValueError):
            self.stop()
            raise

    def pause(self, paused: bool) -> None:
        """Apply a play or pause request to the same native device.

        Args:
            paused (bool): Hold playback position when true.
        Returns:
            None: A stopped player is unaffected.
        """
        if self._process is not None:
            self._process.send_signal(signal.SIGSTOP if paused else signal.SIGCONT)
        elif self._mci is not None and self._alias is not None:
            action = 'pause' if paused else 'resume'
            send_mci(self._mci, f'{action} {self._alias}')

    def running(self) -> bool:
        """Observe whether current native playback can continue.

        Returns:
            bool: True while playing or seeking, false after normal completion.
        Raises:
            OSError: The active player failed or its status could not be read.
        """
        if self._process is not None:
            result = self._process.poll()
            if result not in (None, 0):
                raise OSError('Native audio player failed')
            return result is None
        if self._mci is None or self._alias is None:
            return False
        mode = ctypes.create_unicode_buffer(MEDIA_MCI_MODE_CAPACITY)
        if self._mci(f'status {self._alias} mode', ctypes.cast(mode, ctypes.c_wchar_p), len(mode), None):
            raise OSError('Could not read native audio state')
        return mode.value.lower() in ('playing', 'seeking')

    def stop(self) -> None:
        """Release only this player's device, child and file.

        Returns:
            None: The audio slot is safe to reuse after cleanup.
        """
        if self._mci is not None and self._alias is not None:
            for action in ('stop', 'close'):
                try:
                    send_mci(self._mci, f'{action} {self._alias}')
                except OSError:
                    pass
        process, self._process = self._process, None
        if process is not None and process.poll() is None:
            try:
                process.kill()
                process.wait(timeout=MEDIA_AUDIO_REAP_SECONDS)
            except (OSError, subprocess.TimeoutExpired):
                pass
        path, self._path = self._path, None
        if path is not None:
            remove_audio_file(path)
        self._alias, self._mci, self._library = None, None, None
