# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/credential_store.py
# Module Type: Native Client Credential Storage
# Purpose: Store a linked credential in the current user's native vault.
# Responsibilities:
#   - Bind each credential reference and stored record to an issuer and account.
#   - Use Windows Credential Manager or Keychain for opaque records.
#   - Bound and validate native data before returning any credential bytes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native storage and fenced replacement for issuer-bound credentials."""

import ctypes
import hashlib
import hmac
import os
import stat
import struct
import sys
import threading
from pathlib import Path
from types import TracebackType

from .app_linking import _issuer_valid
from .session_resume import (
    delete_keychain_record, read_keychain_record, write_keychain_record,
)
from .upload_mode import ModeRecord, decode_mode_record, encode_mode_record


# Purpose: Bound one native generic credential blob before allocation or read.
# Type: int. Units: bytes. Scope: one Credential Manager record.
# Consumers: _WindowsCredentialApi.read, _encode_record.
# Rationale: 2560 bytes is the documented Windows generic credential limit.
CREDENTIAL_BLOB_MAX_BYTES = 2560

# Purpose: Limit one application credential while reserving space for its binding.
# Type: int. Units: bytes. Scope: one opaque linked credential payload.
# Consumers: _encode_record, _decode_record.
# Rationale: 2000 bytes leaves room for the bounded issuer and record header.
CREDENTIAL_PAYLOAD_MAX_BYTES = 2000

# Purpose: Bound a server account to the gallery's unsigned database identity.
# Type: int. Units: account identifier. Scope: one credential binding.
# Consumers: _binding_bytes.
# Rationale: 18446744073709551615 is the largest unsigned 64-bit account ID.
CREDENTIAL_ACCOUNT_ID_MAX = 18446744073709551615

# The Windows structure and persistence values are specified by Microsoft:
# https://learn.microsoft.com/en-us/windows/win32/api/wincred/ns-wincred-credentialw
# These are API enum values and a fixed library-search flag, not preferences.
# Local-machine persistence applies to the same Windows user on this computer;
# it does not expose the item to other users or enable roaming.
_CRED_TYPE_GENERIC = 1
_CRED_PERSIST_LOCAL_MACHINE = 2
_ERROR_NOT_FOUND = 1168
_LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x00000800

# The binary header stores a five-byte format marker, a two-byte issuer length,
# an eight-byte account ID and a two-byte payload length, all in network order.
_RECORD_MAGIC = b'PGLC\x01'
_RECORD_HEADER = struct.Struct('>5sHQH')
_TARGET_PREFIX = 'RudolfKlusal.PHPGallery.LinkedCredential.v1:'
_BINDING_DOMAIN = b'PHP Gallery linked credential\x00'

# Purpose: Bound cross-process waiting for one credential rotation.
# Type: int. Units: milliseconds. Scope: each named mutex acquisition.
# Consumers: _WindowsCredentialMutex. Rationale: avoid an indefinite UI wait.
_MUTEX_WAIT_MS = 10000

# Purpose: Bound the Windows TOKEN_USER buffer before allocating native memory.
# Type: int. Units: bytes. Scope: one token identity query.
# Consumers: _WindowsCredentialMutex. Rationale: genuine SIDs are far smaller.
_TOKEN_USER_MAX_BYTES = 65536

# These values are fixed Win32 API constants, not configurable policies.
_TOKEN_QUERY = 0x0008
_TOKEN_USER = 1
_ERROR_NO_TOKEN = 1008
_ERROR_INSUFFICIENT_BUFFER = 122
_WAIT_OBJECT_0 = 0
_WAIT_ABANDONED = 0x80
_PENDING_SUFFIX = ':refresh-pending-v1'
_PENDING_PAYLOAD = b'PHP Gallery refresh pending v1'
_MUTEX_PREFIX = 'Global\\PHPGallery.LinkedCredential.v1.'
_MUTEX_DOMAIN = b'PHP Gallery credential mutex\x00'
_BINDING_MUTEX_TARGET = 'PHP Gallery offline binding metadata v1'
_UPLOAD_MODE_TARGET = 'RudolfKlusal.PHPGallery.UploadMode.v1'

# The native mutex is reentrant on its owner thread. These process-local keys
# reject nested use of the same SID and binding before entering that mutex.
_PROCESS_MUTEX_GUARD = threading.Lock()
_PROCESS_MUTEX_HELD: set[tuple[threading.Thread, bytes, str]] = set()


class CredentialStoreError(RuntimeError):
    """Report a storage or validation failure without credential contents."""


class CredentialStoreUnavailable(CredentialStoreError):
    """Report that the current platform cannot provide the required vault."""


class _FileTime(ctypes.Structure):
    """Match the two DWORD fields in the Windows FILETIME structure."""

    _fields_ = [
        ('low', ctypes.c_uint32),
        ('high', ctypes.c_uint32),
    ]


class _CredentialW(ctypes.Structure):
    """Match the native CREDENTIALW layout for a generic credential."""

    _fields_ = [
        ('flags', ctypes.c_uint32),
        ('type', ctypes.c_uint32),
        ('target_name', ctypes.c_wchar_p),
        ('comment', ctypes.c_wchar_p),
        ('last_written', _FileTime),
        ('blob_size', ctypes.c_uint32),
        ('blob', ctypes.POINTER(ctypes.c_ubyte)),
        ('persist', ctypes.c_uint32),
        ('attribute_count', ctypes.c_uint32),
        ('attributes', ctypes.c_void_p),
        ('target_alias', ctypes.c_wchar_p),
        ('username', ctypes.c_wchar_p),
    ]


def _binding_bytes(issuer: str, account_id: int) -> tuple[bytes, bytes]:
    """Validate and encode the exact issuer and unsigned account identity.

    Args:
        issuer (str): Previously trusted canonical issuer with its mount.
        account_id (int): Positive current Admin ID returned by the server.

    Returns:
        tuple[bytes, bytes]: ASCII issuer and big-endian account ID bytes.

    Raises:
        CredentialStoreError: The issuer or account identity is invalid.
    """
    if (not isinstance(issuer, str)
            or not _issuer_valid(issuer, allow_local_http=True)
            or type(account_id) is not int
            or account_id <= 0 or account_id > CREDENTIAL_ACCOUNT_ID_MAX):
        raise CredentialStoreError('Invalid credential binding.')
    # The shared issuer policy accepts canonical ASCII only. Two header bytes
    # encode its bounded length without a delimiter or Unicode ambiguity.
    issuer_bytes = issuer.encode('ascii')
    return issuer_bytes, account_id.to_bytes(8, 'big')


def credential_target_name(issuer: str, account_id: int) -> str:
    """Derive a deterministic vault target from one exact issuer and account.

    Args:
        issuer (str): Previously trusted canonical issuer with its mount.
        account_id (int): Positive current Admin ID returned by the server.

    Returns:
        str: Versioned target containing a lowercase hash, not the raw identity.

    Raises:
        CredentialStoreError: The issuer or account identity is invalid.
    """
    issuer_bytes, account_bytes = _binding_bytes(issuer, account_id)
    material = (_BINDING_DOMAIN + len(issuer_bytes).to_bytes(2, 'big')
                + issuer_bytes + account_bytes)
    return _TARGET_PREFIX + hashlib.sha256(material).hexdigest()


def _encode_record(issuer: str, account_id: int, payload: bytes) -> bytes:
    """Bind opaque credential bytes to their exact server and account.

    Args:
        issuer (str): Previously trusted canonical issuer with its mount.
        account_id (int): Positive current Admin ID returned by the server.
        payload (bytes): Nonempty opaque credential; never log or write as JSON.

    Returns:
        bytes: Bounded versioned binary record for Windows Credential Manager.

    Raises:
        CredentialStoreError: The binding or payload is invalid or oversized.
    """
    issuer_bytes, _ = _binding_bytes(issuer, account_id)
    if (type(payload) is not bytes or not payload
            or len(payload) > CREDENTIAL_PAYLOAD_MAX_BYTES):
        raise CredentialStoreError('Invalid credential payload.')
    record = (_RECORD_HEADER.pack(
        _RECORD_MAGIC, len(issuer_bytes), account_id, len(payload),
    ) + issuer_bytes + payload)
    if len(record) > CREDENTIAL_BLOB_MAX_BYTES:
        raise CredentialStoreError('Credential record is too large.')
    return record


def _decode_record(issuer: str, account_id: int, record: bytes) -> bytes:
    """Validate the complete stored record before returning credential bytes.

    Args:
        issuer (str): Previously trusted canonical issuer with its mount.
        account_id (int): Positive current Admin ID returned by the server.
        record (bytes): Raw versioned binary record read from the user vault.

    Returns:
        bytes: Opaque credential bound to the requested issuer and account.

    Raises:
        CredentialStoreError: The record, binding or payload is invalid.
    """
    issuer_bytes, _ = _binding_bytes(issuer, account_id)
    if (type(record) is not bytes or len(record) < _RECORD_HEADER.size
            or len(record) > CREDENTIAL_BLOB_MAX_BYTES):
        raise CredentialStoreError('Stored credential is invalid.')
    marker, issuer_length, stored_account, payload_length = (
        _RECORD_HEADER.unpack_from(record)
    )
    issuer_end = _RECORD_HEADER.size + issuer_length
    if (marker != _RECORD_MAGIC or issuer_length != len(issuer_bytes)
            or stored_account != account_id or payload_length == 0
            or payload_length > CREDENTIAL_PAYLOAD_MAX_BYTES
            or issuer_end + payload_length != len(record)
            or not hmac.compare_digest(
                record[_RECORD_HEADER.size:issuer_end], issuer_bytes,
            )):
        raise CredentialStoreError('Stored credential is invalid.')
    return record[issuer_end:]


class _WindowsCredentialApi:
    """Own the system-only native credential functions for the current user."""

    def __init__(self) -> None:
        """Load and declare the Windows functions only on Windows.

        Returns:
            None: Retains the system credential library for later calls.

        Raises:
            CredentialStoreUnavailable: Windows or the native API is absent.
        """
        if sys.platform != 'win32':
            raise CredentialStoreUnavailable('Credential Manager is unavailable.')
        try:
            library = ctypes.WinDLL(
                'Advapi32.dll', use_last_error=True,
                winmode=_LOAD_LIBRARY_SEARCH_SYSTEM32,
            )
            library.CredWriteW.argtypes = [
                ctypes.POINTER(_CredentialW), ctypes.c_uint32,
            ]
            library.CredWriteW.restype = ctypes.c_int
            library.CredReadW.argtypes = [
                ctypes.c_wchar_p, ctypes.c_uint32, ctypes.c_uint32,
                ctypes.POINTER(ctypes.POINTER(_CredentialW)),
            ]
            library.CredReadW.restype = ctypes.c_int
            library.CredDeleteW.argtypes = [
                ctypes.c_wchar_p, ctypes.c_uint32, ctypes.c_uint32,
            ]
            library.CredDeleteW.restype = ctypes.c_int
            library.CredFree.argtypes = [ctypes.c_void_p]
            library.CredFree.restype = None
        except (AttributeError, OSError, TypeError, ValueError):
            raise CredentialStoreUnavailable(
                'Credential Manager is unavailable.',
            ) from None
        self._library = library

    def write(self, target: str, record: bytes) -> None:
        """Write one bounded binary record to the current user's vault.

        Args:
            target (str): Deterministic versioned Credential Manager target.
            record (bytes): Validated binary record including credential bytes.

        Returns:
            None: The native write reported success.

        Raises:
            CredentialStoreError: The operating system refused the write.
        """
        buffer = (ctypes.c_ubyte * len(record)).from_buffer_copy(record)
        credential = _CredentialW()
        credential.type = _CRED_TYPE_GENERIC
        credential.target_name = target
        credential.blob_size = len(record)
        credential.blob = ctypes.cast(buffer, ctypes.POINTER(ctypes.c_ubyte))
        credential.persist = _CRED_PERSIST_LOCAL_MACHINE
        try:
            try:
                succeeded = self._library.CredWriteW(
                    ctypes.byref(credential), 0,
                )
            except (OSError, ValueError, TypeError, ctypes.ArgumentError):
                raise CredentialStoreError(
                    'Credential Manager write failed.',
                ) from None
            if not succeeded:
                raise CredentialStoreError('Credential Manager write failed.')
        finally:
            # This clears our mutable native copy; immutable Python copies can
            # remain until their owners are collected and cannot be guaranteed
            # erased by this module.
            ctypes.memset(ctypes.addressof(buffer), 0, len(record))

    def read(self, target: str) -> bytes | None:
        """Read and release one bounded generic credential record.

        Args:
            target (str): Deterministic versioned Credential Manager target.

        Returns:
            bytes | None: Validated-size native record, or None if absent.

        Raises:
            CredentialStoreError: The native read failed or returned bad data.
        """
        pointer = ctypes.POINTER(_CredentialW)()
        try:
            succeeded = self._library.CredReadW(
                target, _CRED_TYPE_GENERIC, 0, ctypes.byref(pointer),
            )
        except (OSError, ValueError, TypeError, ctypes.ArgumentError):
            raise CredentialStoreError('Credential Manager read failed.') from None
        if not succeeded:
            if ctypes.get_last_error() == _ERROR_NOT_FOUND:
                return None
            raise CredentialStoreError('Credential Manager read failed.')
        if not pointer:
            raise CredentialStoreError('Credential Manager returned invalid data.')
        try:
            credential = pointer.contents
            size = credential.blob_size
            if (credential.type != _CRED_TYPE_GENERIC
                    or credential.persist != _CRED_PERSIST_LOCAL_MACHINE
                    or credential.target_name != target
                    or size == 0 or size > CREDENTIAL_BLOB_MAX_BYTES
                    or not credential.blob):
                raise CredentialStoreError(
                    'Credential Manager returned invalid data.',
                )
            return ctypes.string_at(credential.blob, size)
        finally:
            self._library.CredFree(ctypes.cast(pointer, ctypes.c_void_p))

    def delete(self, target: str) -> bool:
        """Delete one generic credential from the current user's vault.

        Args:
            target (str): Deterministic versioned Credential Manager target.

        Returns:
            bool: True when deleted; False when the target was already absent.

        Raises:
            CredentialStoreError: The operating system refused the operation.
        """
        try:
            succeeded = self._library.CredDeleteW(target, _CRED_TYPE_GENERIC, 0)
        except (OSError, ValueError, TypeError, ctypes.ArgumentError):
            raise CredentialStoreError('Credential Manager delete failed.') from None
        if succeeded:
            return True
        if ctypes.get_last_error() == _ERROR_NOT_FOUND:
            return False
        raise CredentialStoreError('Credential Manager delete failed.')


class _SidAndAttributes(ctypes.Structure):
    """Match SID_AND_ATTRIBUTES in the native TOKEN_USER structure."""

    _fields_ = [('sid', ctypes.c_void_p), ('attributes', ctypes.c_uint32)]


def _enter_process_mutex(sid: bytes, target: str) -> tuple[threading.Thread, bytes, str]:
    """Reject nested acquisition for the current thread and exact binding.

    Args:
        sid (bytes): Bounded binary SID for the effective Windows account.
        target (str): Deterministic credential target for issuer and account.

    Returns:
        tuple[threading.Thread, bytes, str]: Key owned until native lock cleanup.

    Raises:
        CredentialStoreError: This thread already owns the same binding.
    """
    key = (threading.current_thread(), sid, target)
    with _PROCESS_MUTEX_GUARD:
        if key in _PROCESS_MUTEX_HELD:
            raise CredentialStoreError('Credential mutex cannot be nested.')
        _PROCESS_MUTEX_HELD.add(key)
    return key


def _leave_process_mutex(key: tuple[threading.Thread, bytes, str]) -> None:
    """Release one process-local guard after native ownership is gone.

    Args:
        key (tuple[threading.Thread, bytes, str]): Previously acquired key.

    Returns:
        None: The same binding can be entered again by its original thread.

    Raises:
        CredentialStoreError: The process-local ownership is inconsistent.
    """
    with _PROCESS_MUTEX_GUARD:
        if key not in _PROCESS_MUTEX_HELD:
            raise CredentialStoreError('Credential mutex ownership is invalid.')
        _PROCESS_MUTEX_HELD.remove(key)


class _WindowsCredentialMutex:
    """Own a cross-session mutex for one Windows user and gallery account."""

    def __init__(
        self, issuer: str | None, account_id: int | None, *, metadata: bool = False,
    ) -> None:
        """Prepare the exact credential binding and Windows API declarations.

        Args:
            issuer (str | None): Canonical issuer, or None for metadata-only use.
            account_id (int | None): Admin ID, or None for metadata-only use.
            metadata (bool): Select the independent non-secret binding mutex.

        Returns:
            None: Prepares an unlocked mutex owner.

        Raises:
            CredentialStoreError: The binding or required Windows API is invalid.
        """
        if metadata and issuer is None and account_id is None:
            target = _BINDING_MUTEX_TARGET
        else:
            if not isinstance(issuer, str) or type(account_id) is not int:
                raise CredentialStoreError('Invalid credential binding.')
            target = credential_target_name(issuer, account_id)
        self._target = _BINDING_MUTEX_TARGET if metadata else target
        self._handle = None
        self._thread_id = None
        self._sid = None
        self._process_key = None
        if sys.platform != 'win32':
            raise CredentialStoreUnavailable('Credential mutex is unavailable.')
        try:
            kernel = ctypes.WinDLL(
                'Kernel32.dll', use_last_error=True,
                winmode=_LOAD_LIBRARY_SEARCH_SYSTEM32,
            )
            security = ctypes.WinDLL(
                'Advapi32.dll', use_last_error=True,
                winmode=_LOAD_LIBRARY_SEARCH_SYSTEM32,
            )
            kernel.GetCurrentThread.argtypes = []
            kernel.GetCurrentThread.restype = ctypes.c_void_p
            kernel.GetCurrentProcess.argtypes = []
            kernel.GetCurrentProcess.restype = ctypes.c_void_p
            kernel.CreateMutexW.argtypes = [
                ctypes.c_void_p, ctypes.c_int, ctypes.c_wchar_p,
            ]
            kernel.CreateMutexW.restype = ctypes.c_void_p
            kernel.WaitForSingleObject.argtypes = [ctypes.c_void_p, ctypes.c_uint32]
            kernel.WaitForSingleObject.restype = ctypes.c_uint32
            kernel.ReleaseMutex.argtypes = [ctypes.c_void_p]
            kernel.ReleaseMutex.restype = ctypes.c_int
            kernel.CloseHandle.argtypes = [ctypes.c_void_p]
            kernel.CloseHandle.restype = ctypes.c_int
            security.OpenThreadToken.argtypes = [
                ctypes.c_void_p, ctypes.c_uint32, ctypes.c_int,
                ctypes.POINTER(ctypes.c_void_p),
            ]
            security.OpenThreadToken.restype = ctypes.c_int
            security.OpenProcessToken.argtypes = [
                ctypes.c_void_p, ctypes.c_uint32, ctypes.POINTER(ctypes.c_void_p),
            ]
            security.OpenProcessToken.restype = ctypes.c_int
            security.GetTokenInformation.argtypes = [
                ctypes.c_void_p, ctypes.c_int, ctypes.c_void_p, ctypes.c_uint32,
                ctypes.POINTER(ctypes.c_uint32),
            ]
            security.GetTokenInformation.restype = ctypes.c_int
        except (AttributeError, OSError, TypeError, ValueError):
            raise CredentialStoreUnavailable('Credential mutex is unavailable.') from None
        self._kernel = kernel
        self._security = security

    def _current_sid(self) -> bytes:
        """Read the effective Windows token's bounded binary user SID.

        Returns:
            bytes: Validated binary SID for the current effective token.

        Raises:
            CredentialStoreError: The effective token or its SID cannot be read.
        """
        token = ctypes.c_void_p()
        try:
            opened = self._security.OpenThreadToken(
                self._kernel.GetCurrentThread(), _TOKEN_QUERY, 1,
                ctypes.byref(token),
            )
            if not opened:
                if ctypes.get_last_error() != _ERROR_NO_TOKEN:
                    raise CredentialStoreError('Credential identity is unavailable.')
                opened = self._security.OpenProcessToken(
                    self._kernel.GetCurrentProcess(), _TOKEN_QUERY,
                    ctypes.byref(token),
                )
            if not opened or not token.value:
                raise CredentialStoreError('Credential identity is unavailable.')
            size = ctypes.c_uint32()
            result = self._security.GetTokenInformation(
                token, _TOKEN_USER, None, 0, ctypes.byref(size),
            )
            if (result or ctypes.get_last_error() != _ERROR_INSUFFICIENT_BUFFER
                    or size.value < ctypes.sizeof(_SidAndAttributes)
                    or size.value > _TOKEN_USER_MAX_BYTES):
                raise CredentialStoreError('Credential identity is invalid.')
            buffer = ctypes.create_string_buffer(size.value)
            returned = ctypes.c_uint32()
            if not self._security.GetTokenInformation(
                    token, _TOKEN_USER, buffer, size.value, ctypes.byref(returned)):
                raise CredentialStoreError('Credential identity is unavailable.')
            if returned.value > size.value or returned.value < ctypes.sizeof(_SidAndAttributes):
                raise CredentialStoreError('Credential identity is invalid.')
            address = _SidAndAttributes.from_buffer(buffer).sid
            start = ctypes.addressof(buffer)
            end = start + returned.value
            if not address or address < start or address + 8 > end:
                raise CredentialStoreError('Credential identity is invalid.')
            prefix = ctypes.string_at(address, 8)
            length = 8 + 4 * prefix[1]
            if prefix[0] != 1 or prefix[1] > 15 or address + length > end:
                raise CredentialStoreError('Credential identity is invalid.')
            return ctypes.string_at(address, length)
        except (OSError, ValueError, TypeError, ctypes.ArgumentError):
            raise CredentialStoreError('Credential identity is unavailable.') from None
        finally:
            if token.value and not self._kernel.CloseHandle(token):
                raise CredentialStoreError('Credential identity cleanup failed.')

    def __enter__(self) -> '_WindowsCredentialMutex':
        """Acquire the bounded user and binding mutex without fallback.

        Returns:
            _WindowsCredentialMutex: Held mutex owned by the calling thread.

        Raises:
            CredentialStoreError: Acquisition fails, times out or is abandoned.
        """
        if self._handle is not None:
            raise CredentialStoreError('Credential mutex is already held.')
        sid = self._current_sid()
        digest = hashlib.sha256(
            _MUTEX_DOMAIN + len(sid).to_bytes(2, 'big') + sid
            + self._target.encode('ascii'),
        ).hexdigest()
        key = _enter_process_mutex(sid, self._target)
        handle = None
        owned = False
        try:
            handle = self._kernel.CreateMutexW(None, 0, _MUTEX_PREFIX + digest)
            if not handle:
                raise CredentialStoreError('Credential mutex creation failed.')
            result = self._kernel.WaitForSingleObject(handle, _MUTEX_WAIT_MS)
            owned = result in (_WAIT_OBJECT_0, _WAIT_ABANDONED)
            if result != _WAIT_OBJECT_0:
                raise CredentialStoreError(
                    'Credential mutex was abandoned.' if result == _WAIT_ABANDONED
                    else 'Credential mutex acquisition failed.',
                )
            self._handle = handle
            self._thread_id = threading.get_ident()
            self._sid = sid
            self._process_key = key
            self.check_identity()
            return self
        except BaseException:
            self._handle = None
            self._thread_id = None
            self._sid = None
            self._process_key = None
            try:
                try:
                    released = not owned or bool(self._kernel.ReleaseMutex(handle))
                finally:
                    closed = not handle or bool(self._kernel.CloseHandle(handle))
            except (OSError, ValueError, TypeError, ctypes.ArgumentError):
                raise CredentialStoreError('Credential mutex cleanup failed.') from None
            if not released or not closed:
                # Keep the local fence if native ownership might still exist.
                raise CredentialStoreError('Credential mutex cleanup failed.') from None
            _leave_process_mutex(key)
            raise

    def check_identity(self) -> None:
        """Require the same effective Windows user and owning thread.

        Returns:
            None: The caller still owns the mutex under its original identity.

        Raises:
            CredentialStoreError: Ownership or the effective identity changed.
        """
        if (self._handle is None or self._thread_id != threading.get_ident()
                or not hmac.compare_digest(self._sid, self._current_sid())):
            raise CredentialStoreError('Credential mutex identity changed.')

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Release the mutex and native handle even when the caller fails.

        Args:
            exc_type (type | None): Exception class from the protected block.
            exc_value (BaseException | None): Exception raised by the block.
            traceback (TracebackType | None): Traceback when an exception exists.

        Returns:
            bool: False, preserving any protected-block exception.

        Raises:
            CredentialStoreError: The owner thread differs or cleanup fails.
        """
        if self._handle is None or self._thread_id != threading.get_ident():
            raise CredentialStoreError('Credential mutex ownership changed.')
        handle = self._handle
        key = self._process_key
        self._handle = None
        self._thread_id = None
        self._sid = None
        self._process_key = None
        try:
            try:
                released = bool(self._kernel.ReleaseMutex(handle))
            finally:
                closed = bool(self._kernel.CloseHandle(handle))
        except (OSError, ValueError, TypeError, ctypes.ArgumentError):
            raise CredentialStoreError('Credential mutex cleanup failed.') from None
        if not released or not closed or key is None:
            # A failed native cleanup must not admit nested use later.
            raise CredentialStoreError('Credential mutex cleanup failed.')
        _leave_process_mutex(key)
        return False


class NativeCredentialStore:
    """Bind native vault operations and refresh fencing to one exact account.

    The platform adapter provides the vault and cross-process lock. A pending
    refresh blocks access until the user explicitly discards it.
    """

    _api: '_WindowsCredentialApi | _KeychainCredentialApi'

    def _mutex(
        self, issuer: str, account_id: int, *, metadata: bool = False,
    ) -> '_WindowsCredentialMutex | _KeychainCredentialMutex':
        """Select the native lock for a binding or shared metadata.

        Args:
            issuer (str): Canonical exact issuer and mount.
            account_id (int): Verified server Admin ID.
            metadata (bool): Select the separate outer metadata lock.

        Returns:
            _WindowsCredentialMutex | _KeychainCredentialMutex: Unentered lock.

        Raises:
            NotImplementedError: A platform adapter is required.
        """
        raise NotImplementedError

    def is_available(self) -> bool:
        """Probe whether the effective user has a usable native vault.

        Returns:
            bool: True when a harmless generic-credential read succeeded or
                reported absence; false on unavailable or uncertain storage.
        """
        try:
            self._api.read('RudolfKlusal.PHPGallery.AvailabilityProbe.v1')
            return True
        except RuntimeError:
            return False

    def binding_transaction(
        self, issuer: str, account_id: int,
    ) -> '_WindowsCredentialMutex | _KeychainCredentialMutex':
        """Serialize non-secret binding-file changes for the current user.

        The metadata mutex has its own namespace shared across all gallery
        accounts. Acquire it before any binding-file and vault operation;
        credential-specific mutexes remain nested inside this owner.

        Args:
            issuer (str): Canonical issuer used to validate the request.
            account_id (int): Positive server Admin identity.

        Returns:
            _WindowsCredentialMutex | _KeychainCredentialMutex: Unentered metadata lock.

        Raises:
            CredentialStoreError: The binding or native mutex is unavailable.
        """
        return self._mutex(issuer, account_id, metadata=True)

    def write(self, issuer: str, account_id: int, payload: bytes) -> None:
        """Store or replace the record bound to one exact gallery account.

        Args:
            issuer (str): Previously trusted canonical issuer and mount.
            account_id (int): Positive Admin ID confirmed by the server.
            payload (bytes): Nonempty opaque credential; never log or save as JSON.

        Returns:
            None: The vault acknowledged the credential write.

        Raises:
            CredentialStoreError: The input is invalid or the native write fails.
        """
        target = credential_target_name(issuer, account_id)
        record = _encode_record(issuer, account_id, payload)
        with self._mutex(issuer, account_id) as mutex:
            mutex.check_identity()
            self._ensure_not_pending(target)
            mutex.check_identity()
            self._api.write(target, record)

    def read(self, issuer: str, account_id: int) -> bytes | None:
        """Load a record only if its embedded binding matches this account.

        Args:
            issuer (str): Previously trusted canonical issuer and mount.
            account_id (int): Positive Admin ID confirmed by the server.

        Returns:
            bytes | None: Bound opaque credential or None when no entry exists.

        Raises:
            CredentialStoreError: The input, record or native read is invalid.
        """
        target = credential_target_name(issuer, account_id)
        with self._mutex(issuer, account_id) as mutex:
            mutex.check_identity()
            self._ensure_not_pending(target)
            mutex.check_identity()
            record = self._api.read(target)
            if record is None:
                return None
            return _decode_record(issuer, account_id, record)

    def delete(self, issuer: str, account_id: int) -> bool:
        """Delete the local record without claiming a remote revocation.

        Args:
            issuer (str): Previously trusted canonical issuer and mount.
            account_id (int): Positive Admin ID confirmed by the server.

        Returns:
            bool: True when deleted; False if the local entry was absent.

        Raises:
            CredentialStoreError: The input or native deletion is invalid.
        """
        target = credential_target_name(issuer, account_id)
        with self._mutex(issuer, account_id) as mutex:
            mutex.check_identity()
            self._ensure_not_pending(target)
            mutex.check_identity()
            return self._api.delete(target)

    def _ensure_not_pending(self, target: str) -> None:
        """Refuse old credential access whenever any refresh marker exists.

        Args:
            target (str): Validated Credential Manager target for the account.

        Returns:
            None: No pending refresh marker is present.

        Raises:
            CredentialStoreError: A marker exists or its presence cannot be read.
        """
        if self._api.read(target + _PENDING_SUFFIX) is not None:
            raise CredentialStoreError('Credential refresh needs recovery.')

    def refresh_transaction(
        self, issuer: str, account_id: int,
    ) -> '_CredentialRefresh':
        """Create a fenced transaction for one refresh.

        The caller must enter the context, read the credential, successfully
        persist the pending marker, and only then send the network request.
        A missing response or exception leaves the marker in place. Recovery
        requires an explicit discard followed by new authorization; this API
        never retries the old credential or clears a failed rotation itself.

        Args:
            issuer (str): Previously trusted canonical issuer and mount.
            account_id (int): Positive Admin ID confirmed by the server.

        Returns:
            _CredentialRefresh: Unentered, binding-specific transaction.

        Raises:
            CredentialStoreError: The account binding is invalid.
        """
        return _CredentialRefresh(self, issuer, account_id)

    def discard_pending_refresh(self, issuer: str, account_id: int) -> bool:
        """Discard a fenced credential after an explicit user decision.

        Remove and verify the old local credential before clearing the marker.
        A crash or error before that point leaves the marker in place. This
        operation does not revoke any grant on the server. Fresh browser
        authorization is required afterward.

        Args:
            issuer (str): Previously trusted canonical issuer and mount.
            account_id (int): Positive Admin ID confirmed by the server.

        Returns:
            bool: True when the pending marker was cleared; False if absent.

        Raises:
            CredentialStoreError: Locking, deletion or verification fails.
        """
        target = credential_target_name(issuer, account_id)
        marker = target + _PENDING_SUFFIX
        with self._mutex(issuer, account_id) as mutex:
            mutex.check_identity()
            if self._api.read(marker) is None:
                return False
            mutex.check_identity()
            self._api.delete(target)
            mutex.check_identity()
            if self._api.read(target) is not None:
                raise CredentialStoreError('Credential discard verification failed.')
            mutex.check_identity()
            if not self._api.delete(marker):
                raise CredentialStoreError('Credential refresh marker disappeared.')
            return True


class WindowsCredentialStore(NativeCredentialStore):
    """Store credentials in the current Windows user's Credential Manager."""

    def __init__(self) -> None:
        """Load the native Windows vault interface.

        Returns:
            None: The store is ready to probe or access its vault.

        Raises:
            CredentialStoreUnavailable: The operating system or API is unavailable.
        """
        self._api = _WindowsCredentialApi()

    def _mutex(
        self, issuer: str, account_id: int, *, metadata: bool = False,
    ) -> _WindowsCredentialMutex:
        """Select the Credential Manager lock for this Windows user.

        Args:
            issuer (str): Canonical exact issuer and mount.
            account_id (int): Verified server Admin ID.
            metadata (bool): Select the separate outer metadata lock.

        Returns:
            _WindowsCredentialMutex: Unentered named Windows mutex.
        """
        if metadata:
            return _WindowsCredentialMutex(issuer, account_id, metadata=True)
        return _WindowsCredentialMutex(issuer, account_id)


_KEYCHAIN_REFRESH_SERVICE = 'RudolfKlusal.PHPGallery.Refresh.v1'


class _KeychainCredentialApi:
    """Keep refresh records and their durable markers in native Keychain items."""

    def write(self, target: str, record: bytes) -> None:
        """Insert or replace one opaque credential or fence.

        Args:
            target (str): Exact derived Keychain account name.
            record (bytes): Encoded vault payload.

        Returns:
            None: Native Keychain acknowledged the write.
        """
        write_keychain_record(_KEYCHAIN_REFRESH_SERVICE, target, record)

    def read(self, target: str) -> bytes | None:
        """Read one credential or fence by exact account name.

        Args:
            target (str): Exact derived Keychain account name.

        Returns:
            bytes | None: Native Keychain record or None when absent.
        """
        return read_keychain_record(_KEYCHAIN_REFRESH_SERVICE, target)

    def delete(self, target: str) -> bool:
        """Delete a credential or fence by exact account name.

        Args:
            target (str): Exact derived Keychain account name.

        Returns:
            bool: True when the item existed and was removed.
        """
        return delete_keychain_record(_KEYCHAIN_REFRESH_SERVICE, target)


class _KeychainCredentialMutex:
    """Hold a process-released flock separately from the durable vault fence."""

    def __init__(self, path: Path) -> None:
        """Select one stable private profile lock file.

        Args:
            path (Path): Stable lock file shared by the current user's clients.

        Returns:
            None: Prepares an unentered lock without touching the profile.
        """
        self._path = path
        self._descriptor: int | None = None
        self._uid = os.geteuid()
        self._thread = threading.current_thread()

    def __enter__(self) -> '_KeychainCredentialMutex':
        """Acquire an exclusive lock or fail before any vault operation.

        Returns:
            _KeychainCredentialMutex: Active lock held on the owning thread.

        Raises:
            CredentialStoreError: The profile, lock or owner is unsafe or busy.
        """
        import fcntl

        try:
            self._path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            directory = self._path.parent.lstat()
            if (not stat.S_ISDIR(directory.st_mode)
                    or directory.st_uid != self._uid or directory.st_mode & 0o022):
                raise OSError('Unsafe vault lock directory.')
            self._descriptor = os.open(
                self._path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600,
            )
            fcntl.flock(self._descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.check_identity()
        except (OSError, CredentialStoreError):
            if self._descriptor is not None:
                os.close(self._descriptor)
                self._descriptor = None
            raise CredentialStoreError('Native credential lock is unavailable.') from None
        return self

    def check_identity(self) -> None:
        """Verify the current user, thread and still-current lock inode.

        Returns:
            None: The active lock is the private regular profile file.

        Raises:
            CredentialStoreError: The lock or effective user changed.
        """
        descriptor = self._descriptor
        if (descriptor is None or os.geteuid() != self._uid
                or threading.current_thread() is not self._thread):
            raise CredentialStoreError('Native credential lock owner changed.')
        try:
            held = os.fstat(descriptor)
            current = self._path.lstat()
        except OSError:
            raise CredentialStoreError('Native credential lock changed.') from None
        if (not stat.S_ISREG(held.st_mode) or held.st_uid != self._uid
                or held.st_mode & 0o077 or held.st_nlink != 1
                or (held.st_dev, held.st_ino) != (current.st_dev, current.st_ino)):
            raise CredentialStoreError('Native credential lock changed.')

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Close the descriptor and release the lock on every exit.

        Args:
            exc_type (type[BaseException] | None): Protected-block exception type.
            exc_value (BaseException | None): Protected-block exception instance.
            traceback (TracebackType | None): Protected-block traceback.

        Returns:
            bool: False to preserve any protected-block exception.
        """
        if self._descriptor is not None:
            os.close(self._descriptor)
            self._descriptor = None
        return False


class KeychainCredentialStore(NativeCredentialStore):
    """Use macOS Keychain with a per-user profile lock for refresh operations."""

    def __init__(self, profile: Path) -> None:
        """Bind the vault to the uploader's shared per-user profile.

        Args:
            profile (Path): Existing uploader profile, shared by its processes.

        Returns:
            None: The store is ready to probe Keychain.
        """
        self._profile = profile
        self._api = _KeychainCredentialApi()

    def _mutex(
        self, issuer: str, account_id: int, *, metadata: bool = False,
    ) -> _KeychainCredentialMutex:
        """Select a separate metadata or credential lock.

        Args:
            issuer (str): Canonical exact issuer and mount.
            account_id (int): Verified server Admin ID.
            metadata (bool): Select the outer binding-file lock.

        Returns:
            _KeychainCredentialMutex: Unentered private profile file lock.
        """
        target = credential_target_name(issuer, account_id)
        key = 'binding' if metadata else hashlib.sha256(target.encode('ascii')).hexdigest()
        return _KeychainCredentialMutex(self._profile / ('.offline-' + key + '.lock'))


class WindowsUploadModeStore:
    """Own a separate non-secret upload-mode record for the current user.

    This unintegrated owner never reads or overwrites a refresh credential. Its
    fixed target is independent of issuer and account so missing configuration
    does not prevent finding existing mode evidence.
    """

    def __init__(self) -> None:
        """Open the system vault without reading or changing any record.

        Returns:
            None: Creates a mode-only storage owner.

        Raises:
            CredentialStoreUnavailable: Native credential storage is unavailable.
        """
        self._api = _WindowsCredentialApi()

    def transaction(self) -> '_WindowsUploadModeTransaction':
        """Prepare an issuer-independent SID-bound metadata transaction.

        Returns:
            _WindowsUploadModeTransaction: Unentered mode metadata transaction.
        """
        return _WindowsUploadModeTransaction(self._api)


class _WindowsUploadModeTransaction:
    """Serialize typed mode reads and writes under the shared metadata mutex."""

    def __init__(self, api: _WindowsCredentialApi) -> None:
        """Prepare the mode transaction without acquiring its mutex.

        Args:
            api (_WindowsCredentialApi): Current-user native vault owner.

        Returns:
            None: Creates an unentered transaction.
        """
        self._api = api
        self._mutex = _WindowsCredentialMutex(None, None, metadata=True)
        self._entered = False

    def __enter__(self) -> '_WindowsUploadModeTransaction':
        """Acquire and validate the current user's metadata mutex.

        Returns:
            _WindowsUploadModeTransaction: Entered transaction for this thread.

        Raises:
            CredentialStoreError: Mutex acquisition or identity is unavailable.
        """
        self._mutex.__enter__()
        self._entered = True
        try:
            self._mutex.check_identity()
        except BaseException:
            self._entered = False
            self._mutex.__exit__(*sys.exc_info())
            raise
        return self

    def read(self) -> ModeRecord | None:
        """Read a validated mode record or confirm that it is absent.

        Returns:
            ModeRecord | None: Validated mode evidence, or confirmed absence.

        Raises:
            CredentialStoreError: The lease, vault or stored record is invalid.
        """
        if not self._entered:
            raise CredentialStoreError('Upload mode transaction is unavailable.')
        self._mutex.check_identity()
        payload = self._api.read(_UPLOAD_MODE_TARGET)
        self._mutex.check_identity()
        if payload is None:
            return None
        try:
            return decode_mode_record(payload)
        except ValueError:
            raise CredentialStoreError('Upload mode record is invalid.') from None

    def write(self, record: ModeRecord) -> None:
        """Write and read back one typed non-secret mode record.

        Args:
            record (ModeRecord): Exact validated profile and mode generation.

        Returns:
            None: The same record was read back under the metadata mutex.

        Raises:
            CredentialStoreError: Validation, write or verification failed.
        """
        if not self._entered:
            raise CredentialStoreError('Upload mode transaction is unavailable.')
        try:
            payload = encode_mode_record(record)
        except ValueError:
            raise CredentialStoreError('Upload mode record is invalid.') from None
        self._mutex.check_identity()
        self._api.write(_UPLOAD_MODE_TARGET, payload)
        self._mutex.check_identity()
        if self.read() != record:
            raise CredentialStoreError('Upload mode write could not be verified.')

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Release the metadata mutex without suppressing caller failures.

        Args:
            exc_type (type[BaseException] | None): Raised exception class.
            exc_value (BaseException | None): Raised exception instance.
            traceback (TracebackType | None): Raised exception traceback.

        Returns:
            bool: False so caller failures remain visible.
        """
        self._entered = False
        return self._mutex.__exit__(exc_type, exc_value, traceback)


class _CredentialRefresh:
    """Hold a user-bound lock and persistent fence across one rotation."""

    def __init__(
        self, store: NativeCredentialStore, issuer: str, account_id: int,
    ) -> None:
        """Prepare a single-use refresh transaction for an exact binding.

        Args:
            store (NativeCredentialStore): Owner of the current user's vault.
            issuer (str): Previously trusted canonical issuer and mount.
            account_id (int): Positive Admin ID confirmed by the server.

        Returns:
            None: Prepares the transaction without locking or reading secrets.

        Raises:
            CredentialStoreError: The account binding is invalid.
        """
        self._store = store
        self._issuer = issuer
        self._account_id = account_id
        self._target = credential_target_name(issuer, account_id)
        self._marker = _encode_record(issuer, account_id, _PENDING_PAYLOAD)
        self._mutex = None
        self._used = False
        self._original_digest = None
        self._pending = False
        self._committed = False

    def __enter__(self) -> '_CredentialRefresh':
        """Lock the binding and refuse any unresolved previous rotation.

        Returns:
            _CredentialRefresh: Active transaction on its owning thread.

        Raises:
            CredentialStoreError: The lock or pending-marker check fails.
        """
        if self._used:
            raise CredentialStoreError('Credential transaction was already used.')
        self._used = True
        mutex = self._store._mutex(self._issuer, self._account_id)
        mutex.__enter__()
        try:
            mutex.check_identity()
            self._store._ensure_not_pending(self._target)
        except BaseException:
            mutex.__exit__(*sys.exc_info())
            raise
        self._mutex = mutex
        return self

    def _check_active(self) -> None:
        """Check that the original caller still owns this transaction.

        Returns:
            None: The transaction and effective user are still valid.

        Raises:
            CredentialStoreError: The transaction is inactive or changed user.
        """
        if self._mutex is None or self._committed:
            raise CredentialStoreError('Credential transaction is inactive.')
        self._mutex.check_identity()

    def read_current(self) -> bytes | None:
        """Read the existing credential before marking a refresh pending.

        Returns:
            bytes | None: Bound credential, or None when no record exists.

        Raises:
            CredentialStoreError: The transaction or stored record is invalid.
        """
        self._check_active()
        if self._pending:
            raise CredentialStoreError('Credential refresh is already pending.')
        self._store._ensure_not_pending(self._target)
        self._check_active()
        record = self._store._api.read(self._target)
        if record is None:
            self._original_digest = None
            return None
        payload = _decode_record(self._issuer, self._account_id, record)
        self._original_digest = hashlib.sha256(payload).digest()
        return payload

    def begin_rotation(self) -> None:
        """Persist and verify the fence before any refresh network request.

        Returns:
            None: The vault acknowledged and returned the pending marker.

        Raises:
            CredentialStoreError: The marker or transaction cannot be verified.
        """
        self._check_active()
        if self._pending or self._original_digest is None:
            raise CredentialStoreError('Credential refresh is not ready.')
        self._store._ensure_not_pending(self._target)
        self._check_active()
        record = self._store._api.read(self._target)
        if record is None:
            raise CredentialStoreError('Credential changed before refresh.')
        payload = _decode_record(self._issuer, self._account_id, record)
        if not hmac.compare_digest(hashlib.sha256(payload).digest(), self._original_digest):
            raise CredentialStoreError('Credential changed before refresh.')
        self._check_active()
        self._store._api.write(self._target + _PENDING_SUFFIX, self._marker)
        self._check_active()
        marker = self._store._api.read(self._target + _PENDING_SUFFIX)
        if marker is None or not hmac.compare_digest(marker, self._marker):
            raise CredentialStoreError('Credential refresh marker failed.')
        self._pending = True

    def commit_rotated(self, payload: bytes) -> None:
        """Save and verify a distinct replacement before clearing the fence.

        Args:
            payload (bytes): Nonempty rotated credential returned by the server.

        Returns:
            None: The vault acknowledged the replacement and marker deletion.

        Raises:
            CredentialStoreError: Validation, storage or verification fails.
        """
        self._check_active()
        if not self._pending or self._original_digest is None:
            raise CredentialStoreError('Credential refresh is not pending.')
        record = _encode_record(self._issuer, self._account_id, payload)
        if hmac.compare_digest(hashlib.sha256(payload).digest(), self._original_digest):
            raise CredentialStoreError('Credential did not rotate.')
        marker = self._store._api.read(self._target + _PENDING_SUFFIX)
        if marker is None or not hmac.compare_digest(marker, self._marker):
            raise CredentialStoreError('Credential refresh marker failed.')
        self._check_active()
        self._store._api.write(self._target, record)
        self._check_active()
        stored = self._store._api.read(self._target)
        if stored is None or not hmac.compare_digest(stored, record):
            raise CredentialStoreError('Credential replacement verification failed.')
        self._check_active()
        if not self._store._api.delete(self._target + _PENDING_SUFFIX):
            raise CredentialStoreError('Credential refresh marker disappeared.')
        self._committed = True

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Release the lock without clearing any unresolved refresh marker.

        Args:
            exc_type (type | None): Exception class from the protected block.
            exc_value (BaseException | None): Exception raised by the block.
            traceback (TracebackType | None): Traceback when an exception exists.

        Returns:
            bool: False, preserving any protected-block exception.

        Raises:
            CredentialStoreError: The mutex owner or cleanup is invalid.
        """
        if self._mutex is None:
            raise CredentialStoreError('Credential transaction is inactive.')
        mutex = self._mutex
        self._mutex = None
        return mutex.__exit__(exc_type, exc_value, traceback)
