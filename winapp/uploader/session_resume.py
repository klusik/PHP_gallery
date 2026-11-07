# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/session_resume.py
# Module Type: Native Client Session Persistence
# Purpose: Keep an already-approved transient gallery grant in the user vault briefly.
# Responsibilities:
#   - Store only the original short-lived access grant in the OS credential vault.
#   - Bind every read and deletion to one exact issuer and verified Admin identity.
#   - Never refresh a grant or persist it in application files.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Short-lived, OS-vault-only snapshots for browser-approved transient grants."""

import ctypes
import ctypes.util
import hashlib
import json
import sys
import time
from dataclasses import dataclass, field
from typing import TypeAlias

from .app_linking import (
    GALLERIES_SCOPE, TOKEN_PREFIX,
    UPLOAD_SCOPE, _canonical_256bit, _issuer_valid, scope_base,
)

# Purpose: Refuse a vault snapshot after the original server access lifetime.
# Type: int. Units: seconds. Scope: one transient bearer snapshot.
# Consumers: save_transient, load_transient. Rationale: server tokens last at
# most fifteen minutes; storage must never extend that original grant.
TRANSIENT_RESUME_MAX_SECONDS = 900

_KEYCHAIN_SERVICE = 'RudolfKlusal.PHPGallery.TransientSession.v1'
_KEYCHAIN_MISSING = -25300
# CoreFoundation's fixed kCFStringEncodingUTF8 enum.
_CF_UTF8 = 0x08000100
# Version of the opaque Keychain payload layout.
_RECORD_VERSION = 1

# Purpose: Bound one serialized transient grant before decoding.
# Type: int. Units: bytes. Scope: one generic-password Keychain item.
# Consumers: read_keychain_record. Rationale: the issuer and bearer fit comfortably
# below this cap while malformed or unexpected records fail closed.
TRANSIENT_RECORD_MAX_BYTES = 4096

_KeychainFields: TypeAlias = tuple[
    tuple[ctypes.c_void_p, str | bytes | int | bool | ctypes.c_void_p], ...,
]


class _CFDictionaryCallbacks(ctypes.Structure):
    """Match the six native CoreFoundation dictionary callback fields."""

    _fields_ = [
        ('version', ctypes.c_long),
        ('retain', ctypes.c_void_p),
        ('release', ctypes.c_void_p),
        ('copy_description', ctypes.c_void_p),
        ('equal', ctypes.c_void_p),
        ('hash', ctypes.c_void_p),
    ]


@dataclass(frozen=True)
class TransientGrant:
    """Bind one transient bearer to its approved gallery and original deadline.

    Attributes:
        issuer (str): Exact validated installation issuer and mount.
        account_id (int): Positive Admin ID independently verified by identity.
        scope (str): Exact browser-approved scope bundle.
        expires_at (float): Unix deadline from the original server lifetime.
        token (str): Access bearer held out of object representations.
    """

    issuer: str
    account_id: int
    scope: str
    expires_at: float
    token: str = field(repr=False)


def _keychain() -> tuple[ctypes.CDLL, ctypes.CDLL]:
    """Load the native macOS Keychain and CoreFoundation frameworks.

    Returns:
        tuple[ctypes.CDLL, ctypes.CDLL]: Security and CoreFoundation libraries.

    Raises:
        RuntimeError: The native frameworks are unavailable.
    """
    if sys.platform != 'darwin':
        raise RuntimeError('macOS Keychain is unavailable.')
    security_path = ctypes.util.find_library('Security')
    foundation_path = ctypes.util.find_library('CoreFoundation')
    if not security_path or not foundation_path:
        raise RuntimeError('macOS Keychain is unavailable.')
    return ctypes.CDLL(security_path), ctypes.CDLL(foundation_path)


def _cf_string(foundation: ctypes.CDLL, value: str) -> ctypes.c_void_p:
    """Create one native UTF-8 CFString for a Keychain dictionary value.

    Args:
        foundation (ctypes.CDLL): Loaded CoreFoundation framework.
        value (str): Non-secret key or service string.

    Returns:
        ctypes.c_void_p: Owned CFString reference.
    """
    create = foundation.CFStringCreateWithCString
    create.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.c_uint32]
    create.restype = ctypes.c_void_p
    result = create(None, value.encode('utf-8'), _CF_UTF8)
    if not result:
        raise RuntimeError('Keychain value could not be prepared.')
    return ctypes.c_void_p(result)


def _dictionary(
    foundation: ctypes.CDLL, security: ctypes.CDLL,
    fields: _KeychainFields,
) -> tuple[ctypes.c_void_p, list[ctypes.c_void_p]]:
    """Build a retained native Keychain query dictionary.

    Args:
        foundation (ctypes.CDLL): Loaded CoreFoundation framework.
        security (ctypes.CDLL): Loaded Security framework.
        fields (_KeychainFields): Native CF key references paired with text,
            data, integers, booleans or borrowed native values.

    Returns:
        tuple[ctypes.c_void_p, list[ctypes.c_void_p]]: Dictionary and created
            references that the caller releases after the Security call.

    Raises:
        RuntimeError: A native value or dictionary could not be created.
    """
    dictionary_create = foundation.CFDictionaryCreateMutable
    dictionary_create.argtypes = [
        ctypes.c_void_p, ctypes.c_long, ctypes.c_void_p, ctypes.c_void_p,
    ]
    dictionary_create.restype = ctypes.c_void_p
    keys_callbacks = _CFDictionaryCallbacks.in_dll(
        foundation, 'kCFTypeDictionaryKeyCallBacks',
    )
    values_callbacks = _CFDictionaryCallbacks.in_dll(
        foundation, 'kCFTypeDictionaryValueCallBacks',
    )
    result = dictionary_create(
        None, 0, ctypes.byref(keys_callbacks), ctypes.byref(values_callbacks),
    )
    if not result:
        raise RuntimeError('Keychain query could not be prepared.')
    dictionary = ctypes.c_void_p(result)
    set_value = foundation.CFDictionarySetValue
    set_value.argtypes = [ctypes.c_void_p, ctypes.c_void_p, ctypes.c_void_p]
    retained: list[ctypes.c_void_p] = []
    for name, value in fields:
        key = name
        if not isinstance(name, ctypes.c_void_p):
            raise RuntimeError('Keychain query key is invalid.')
        owned_value = True
        if isinstance(value, str):
            native_value = _cf_string(foundation, value)
        elif isinstance(value, bytes):
            create_data = foundation.CFDataCreate
            create_data.argtypes = [
                ctypes.c_void_p, ctypes.POINTER(ctypes.c_ubyte), ctypes.c_long,
            ]
            create_data.restype = ctypes.c_void_p
            buffer = (ctypes.c_ubyte * len(value)).from_buffer_copy(value)
            native_value = ctypes.c_void_p(
                create_data(None, buffer, len(value)),
            )
        elif type(value) is bool:
            native_value = ctypes.c_void_p.in_dll(
                foundation,
                'kCFBooleanTrue' if value else 'kCFBooleanFalse',
            )
            owned_value = False
        elif isinstance(value, ctypes.c_void_p):
            native_value = value
            owned_value = False
        else:
            number_create = foundation.CFNumberCreate
            number_create.argtypes = [
                ctypes.c_void_p, ctypes.c_int, ctypes.c_void_p,
            ]
            number_create.restype = ctypes.c_void_p
            number = ctypes.c_long(value)
            native_value = ctypes.c_void_p(
                number_create(None, 4, ctypes.byref(number)),
            )
        if not native_value:
            _release(foundation, dictionary, retained)
            raise RuntimeError('Keychain query value could not be prepared.')
        if owned_value:
            retained.append(native_value)
        set_value(dictionary, key, native_value)
    return dictionary, retained


def _release(
    foundation: ctypes.CDLL, dictionary: ctypes.c_void_p,
    retained: list[ctypes.c_void_p],
) -> None:
    """Release references created for one native Keychain call.

    Args:
        foundation (ctypes.CDLL): Loaded CoreFoundation framework.
        dictionary (ctypes.c_void_p): Mutable query dictionary.
        retained (list[ctypes.c_void_p]): Created keys and values.

    Returns:
        None: Releases caller-owned CoreFoundation references.
    """
    release = foundation.CFRelease
    release.argtypes = [ctypes.c_void_p]
    for item in reversed(retained):
        release(item)
    release(dictionary)


def _identity(issuer: str) -> str:
    """Derive a non-secret stable account key for one exact issuer.

    Args:
        issuer (str): Canonical issuer and installation mount.

    Returns:
        str: Hexadecimal SHA-256 identifier used only as Keychain account name.
    """
    return hashlib.sha256(issuer.encode('utf-8')).hexdigest()


def _constant(security: ctypes.CDLL, name: str) -> ctypes.c_void_p:
    """Read one exported Security framework CFString constant.

    Args:
        security (ctypes.CDLL): Loaded Security framework.
        name (str): Exported constant name.

    Returns:
        ctypes.c_void_p: Borrowed native CFString reference.

    Raises:
        RuntimeError: The native constant is unavailable.
    """
    try:
        return ctypes.c_void_p.in_dll(security, name)
    except (ValueError, OSError):
        raise RuntimeError('Keychain constants are unavailable.') from None


def _query_fields(
    security: ctypes.CDLL, service: str, account: str,
) -> tuple[tuple[ctypes.c_void_p, str | ctypes.c_void_p], ...]:
    """Build the exact Keychain class, service and account selector.

    Args:
        security (ctypes.CDLL): Loaded owner of the native query constants.
        service (str): Exact Keychain service namespace.
        account (str): Exact Keychain item account selector.

    Returns:
        tuple[tuple[ctypes.c_void_p, str | ctypes.c_void_p], ...]:
            Fixed query attributes using Security's exported CF constants.
    """
    return (
        (_constant(security, 'kSecClass'),
         _constant(security, 'kSecClassGenericPassword')),
        (_constant(security, 'kSecAttrService'), service),
        (_constant(security, 'kSecAttrAccount'), account),
    )


def _validate(grant: TransientGrant, now: float) -> bool:
    """Check that a snapshot stays within a live transient grant boundary.

    Args:
        grant (TransientGrant): Candidate OS-vault record.
        now (float): Current Unix time used for expiry validation.

    Returns:
        bool: True only for a canonical issuer, identity, scope and live token.
    """
    if type(grant.expires_at) not in (int, float):
        return False
    lifetime = grant.expires_at - now
    return (
        isinstance(grant.issuer, str)
        and _issuer_valid(grant.issuer, allow_local_http=True)
        and type(grant.account_id) is int and grant.account_id > 0
        and isinstance(grant.scope, str)
        and scope_base(grant.scope) in (GALLERIES_SCOPE, UPLOAD_SCOPE)
        and 'offline_access' not in grant.scope.split()
        and isinstance(grant.token, str) and grant.token.startswith(TOKEN_PREFIX)
        and _canonical_256bit(grant.token[len(TOKEN_PREFIX):])
        and 0 < lifetime <= TRANSIENT_RESUME_MAX_SECONDS
    )


def save_transient(grant: TransientGrant) -> None:
    """Store an already-approved transient bearer in macOS Keychain only.

    Args:
        grant (TransientGrant): Identity-verified live transient grant.

    Returns:
        None: Keychain acknowledged the item update or insertion.

    Raises:
        RuntimeError: The grant is invalid or native Keychain storage failed.
    """
    if not _validate(grant, time.time()):
        raise RuntimeError('Transient grant is not eligible for resume.')
    record = json.dumps({
        'v': _RECORD_VERSION, 'issuer': grant.issuer,
        'account_id': grant.account_id, 'scope': grant.scope,
        'expires_at': grant.expires_at, 'token': grant.token,
    }, separators=(',', ':')).encode('utf-8')
    write_keychain_record(_KEYCHAIN_SERVICE, _identity(grant.issuer), record)


def write_keychain_record(service: str, account: str, record: bytes) -> None:
    """Write one opaque native Keychain record.

    Args:
        service (str): Exact Keychain service namespace.
        account (str): Exact account selector in that service.
        record (bytes): Opaque credential data.

    Returns:
        None: Keychain acknowledged insertion or replacement.

    Raises:
        RuntimeError: Native Keychain storage failed.
    """
    security, foundation = _keychain()
    base, retained = _dictionary(
        foundation, security, _query_fields(security, service, account),
    )
    attributes, attribute_refs = _dictionary(
        foundation, security,
        ((_constant(security, 'kSecValueData'), record),),
    )
    try:
        update = security.SecItemUpdate
        update.argtypes = [ctypes.c_void_p, ctypes.c_void_p]
        update.restype = ctypes.c_int32
        status = update(base, attributes)
        if status == _KEYCHAIN_MISSING:
            add_fields = _query_fields(security, service, account) + (
                (_constant(security, 'kSecValueData'), record),
            )
            item, item_refs = _dictionary(foundation, security, add_fields)
            try:
                add = security.SecItemAdd
                add.argtypes = [ctypes.c_void_p, ctypes.c_void_p]
                add.restype = ctypes.c_int32
                status = add(item, None)
            finally:
                _release(foundation, item, item_refs)
        if status != 0:
            raise RuntimeError('Keychain write failed.')
    finally:
        _release(foundation, attributes, attribute_refs)
        _release(foundation, base, retained)


def load_transient(issuer: str) -> TransientGrant | None:
    """Read and validate one exact transient grant from macOS Keychain.

    Args:
        issuer (str): Exact canonical installation issuer to restore.

    Returns:
        TransientGrant | None: Valid original grant, or None when absent/expired.

    Raises:
        RuntimeError: Native Keychain access failed or stored data is malformed.
    """
    if not _issuer_valid(issuer, allow_local_http=True):
        return None
    raw = read_keychain_record(_KEYCHAIN_SERVICE, _identity(issuer))
    if raw is None:
        return None
    decoded = json.loads(raw.decode('utf-8'))
    if (type(decoded) is not dict or set(decoded) != {
            'v', 'issuer', 'account_id', 'scope', 'expires_at', 'token',
    } or decoded['v'] != _RECORD_VERSION):
        raise RuntimeError('Keychain record is invalid.')
    grant = TransientGrant(
        decoded['issuer'], decoded['account_id'], decoded['scope'],
        decoded['expires_at'], decoded['token'],
    )
    if grant.issuer != issuer or not _validate(grant, time.time()):
        delete_transient(issuer)
        return None
    return grant


def read_keychain_record(service: str, account: str) -> bytes | None:
    """Read one bounded opaque native Keychain record.

    Args:
        service (str): Exact Keychain service namespace.
        account (str): Exact account selector in that service.

    Returns:
        bytes | None: Opaque record, or None when the item is absent.

    Raises:
        RuntimeError: Native Keychain access or the record size is invalid.
    """
    security, foundation = _keychain()
    fields = _query_fields(security, service, account) + (
        (_constant(security, 'kSecReturnData'), True),
        (_constant(security, 'kSecMatchLimit'),
         _constant(security, 'kSecMatchLimitOne')),
    )
    query, retained = _dictionary(foundation, security, fields)
    result = ctypes.c_void_p()
    try:
        copy = security.SecItemCopyMatching
        copy.argtypes = [ctypes.c_void_p, ctypes.POINTER(ctypes.c_void_p)]
        copy.restype = ctypes.c_int32
        status = copy(query, ctypes.byref(result))
        if status == _KEYCHAIN_MISSING:
            return None
        if status != 0 or not result:
            raise RuntimeError('Keychain read failed.')
        length = foundation.CFDataGetLength
        length.argtypes = [ctypes.c_void_p]
        length.restype = ctypes.c_long
        pointer = foundation.CFDataGetBytePtr
        pointer.argtypes = [ctypes.c_void_p]
        pointer.restype = ctypes.POINTER(ctypes.c_ubyte)
        size = length(result)
        if size < 2 or size > TRANSIENT_RECORD_MAX_BYTES:
            raise RuntimeError('Keychain record is invalid.')
        return ctypes.string_at(pointer(result), size)
    finally:
        if result:
            foundation.CFRelease.argtypes = [ctypes.c_void_p]
            foundation.CFRelease(result)
        _release(foundation, query, retained)


def delete_transient(issuer: str) -> None:
    """Delete only the transient vault item for one exact issuer.

    Args:
        issuer (str): Canonical issuer whose saved snapshot is removed.

    Returns:
        None: The matching Keychain item is absent.

    Raises:
        RuntimeError: Native Keychain deletion failed.
    """
    if not _issuer_valid(issuer, allow_local_http=True):
        return
    delete_keychain_record(_KEYCHAIN_SERVICE, _identity(issuer))


def delete_keychain_record(service: str, account: str) -> bool:
    """Delete the specified native Keychain record.

    Args:
        service (str): Exact Keychain service namespace.
        account (str): Exact account selector in that service.

    Returns:
        bool: True if deleted, false if absent.

    Raises:
        RuntimeError: Native Keychain deletion failed.
    """
    security, foundation = _keychain()
    query, retained = _dictionary(
        foundation, security, _query_fields(security, service, account),
    )
    try:
        delete = security.SecItemDelete
        delete.argtypes = [ctypes.c_void_p]
        delete.restype = ctypes.c_int32
        status = delete(query)
        if status not in (0, _KEYCHAIN_MISSING):
            raise RuntimeError('Keychain deletion failed.')
        return status == 0
    finally:
        _release(foundation, query, retained)
