# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/offline_linking.py
# Module Type: Optional Native Client Refresh Workflow
# Purpose: Safely rotate an explicitly approved persistent client credential.
# Responsibilities:
#   - Keep the local rotation fence held across token and identity requests.
#   - Bind the replacement to the trusted issuer, client, account and scope.
#   - Expose an access token only after verified persistent replacement.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Explicit offline linking workflow with secure platform-owned credentials.

The caller supplies an already constructed Windows credential owner. Browser
consent and initial live identity verification belong to the eventual caller.
A failed or ambiguous rotation remains fenced and requires explicit discard
and fresh authorization. Local discard never asserts remote revocation.
"""

import hmac
import time
from dataclasses import dataclass, field
from pathlib import Path
from threading import Event
from urllib.parse import urlencode

from .app_linking import (
    CLIENT_ID,
    GALLERIES_SCOPE,
    IDENTITY_SCOPE,
    LINKING_USERNAME_MAX_CHARS,
    READ_OFFLINE_SCOPE,
    READ_UPLOAD_OFFLINE_SCOPE,
    CREATE_OFFLINE_SCOPE,
    READ_CREATE_OFFLINE_SCOPE,
    UPLOAD_CREATE_OFFLINE_SCOPE,
    READ_UPLOAD_CREATE_OFFLINE_SCOPE,
    VISIBILITY_OFFLINE_SCOPE,
    UPLOAD_VISIBILITY_OFFLINE_SCOPE,
    CREATE_VISIBILITY_OFFLINE_SCOPE,
    UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
    TOKEN_PREFIX,
    UPLOAD_SCOPE,
    _canonical_256bit,
    _issuer_valid,
    _request_json,
)
from .credential_store import CREDENTIAL_ACCOUNT_ID_MAX, WindowsCredentialStore

# Purpose: Bound a rotated access credential to the current server policy.
# Type: int. Units: seconds. Scope: one newly rotated access bearer.
# Consumers: _validated_rotation. Rationale: 900 matches server issuance.
OFFLINE_ACCESS_MAX_SECONDS = 900

# Purpose: Bound a refresh credential by the server's inactivity policy.
# Type: int. Units: seconds. Scope: one current refresh generation.
# Consumers: store_approved_refresh, _validated_rotation.
# Rationale: 604800 is seven days and is below the absolute 30-day family cap.
OFFLINE_REFRESH_MAX_SECONDS = 604800

_REFRESH_PREFIX = 'pglr_'
_RECORD_MAGIC = b'PGOR\x01'
_OFFLINE_SCOPES = (
    IDENTITY_SCOPE + ' offline_access',
    GALLERIES_SCOPE + ' offline_access',
    UPLOAD_SCOPE + ' offline_access',
    # Append-only: earlier positions are persisted in credential records.
    READ_OFFLINE_SCOPE,
    READ_UPLOAD_OFFLINE_SCOPE,
    CREATE_OFFLINE_SCOPE,
    READ_CREATE_OFFLINE_SCOPE,
    UPLOAD_CREATE_OFFLINE_SCOPE,
    READ_UPLOAD_CREATE_OFFLINE_SCOPE,
    VISIBILITY_OFFLINE_SCOPE,
    UPLOAD_VISIBILITY_OFFLINE_SCOPE,
    CREATE_VISIBILITY_OFFLINE_SCOPE,
    UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
)


class OfflineLinkingError(RuntimeError):
    """Report a generic offline-linking refusal without credential contents."""


@dataclass(frozen=True)
class OfflineAccess:
    """Hold a verified access bearer and its monotonic in-memory expiry.

    Attributes:
        issuer (str): Exact trusted HTTPS origin and gallery installation mount.
        account_id (int): Current positive Admin identifier verified by server.
        scope (str): Exact explicitly approved scope including offline_access.
        expires_at (float): Monotonic deadline measured from request start.
        token (str): In-memory bearer; excluded from the object's representation.
    """

    issuer: str
    account_id: int
    scope: str
    expires_at: float
    token: str = field(repr=False)


def _binding_valid(issuer: str, account_id: int, scope: str) -> bool:
    """Check one exact trusted installation, account and offline bundle.

    Args:
        issuer (str): Previously trusted canonical HTTPS issuer and mount.
        account_id (int): Positive current server Admin identifier.
        scope (str): Exact explicitly approved offline scope bundle.

    Returns:
        bool: True only for a canonical supported binding.
    """
    return (isinstance(issuer, str) and _issuer_valid(issuer, allow_local_http=False)
            and type(account_id) is int and 0 < account_id <= CREDENTIAL_ACCOUNT_ID_MAX
            and isinstance(scope, str) and scope in _OFFLINE_SCOPES)


def _valid_token(value: object, prefix: str) -> bool:
    """Check a canonical 256-bit credential with its exact token prefix.

    Args:
        value (uploader.models.JsonValue | object): Untrusted decoded JSON
            value or another runtime value; only a canonical token string is
            accepted.
        prefix (str): Required protocol prefix for this credential kind.

    Returns:
        bool: True only for the required prefix and canonical random payload.
    """
    return (isinstance(value, str) and value.startswith(prefix)
            and _canonical_256bit(value[len(prefix):]))


def _pack_refresh(scope: str, refresh: str) -> bytes:
    """Encode a validated scope and refresh token without JSON or logging.

    Args:
        scope (str): Exact approved offline scope bundle.
        refresh (str): Canonical opaque refresh credential.

    Returns:
        bytes: Versioned compact record to be bound by the vault owner.

    Raises:
        OfflineLinkingError: The scope or credential is invalid.
    """
    if scope not in _OFFLINE_SCOPES or not _valid_token(refresh, _REFRESH_PREFIX):
        raise OfflineLinkingError('Offline credential is invalid.')
    return _RECORD_MAGIC + bytes([_OFFLINE_SCOPES.index(scope)]) + refresh.encode('ascii')


def _unpack_refresh(record: bytes, scope: str) -> str:
    """Validate a stored record against its independently approved scope.

    Args:
        record (bytes): Opaque record read inside the vault transaction.
        scope (str): Exact offline scope expected by the caller.

    Returns:
        str: Canonical refresh token for this exact scope.

    Raises:
        OfflineLinkingError: The record or approved scope is invalid.
    """
    if (type(record) is not bytes or not record.startswith(_RECORD_MAGIC)
            or len(record) <= len(_RECORD_MAGIC)):
        raise OfflineLinkingError('Offline credential is invalid.')
    index = record[len(_RECORD_MAGIC)]
    if index >= len(_OFFLINE_SCOPES) or _OFFLINE_SCOPES[index] != scope:
        raise OfflineLinkingError('Offline credential is invalid.')
    try:
        token = record[len(_RECORD_MAGIC) + 1:].decode('ascii')
    except UnicodeError:
        raise OfflineLinkingError('Offline credential is invalid.') from None
    if not _valid_token(token, _REFRESH_PREFIX):
        raise OfflineLinkingError('Offline credential is invalid.')
    return token


def store_approved_refresh(
    store: WindowsCredentialStore, issuer: str, account_id: int,
    scope: str, refresh_token: str, refresh_expires_in: int,
) -> None:
    """Persist the initial refresh after explicit consent and identity proof.

    The caller must have validated the original token response, browser consent,
    issuer, client and live Admin identity before calling. This function does
    not perform authorization, identity lookup or remote grant revocation.

    Args:
        store (WindowsCredentialStore): Current Windows user's vault owner.
        issuer (str): Independently trusted exact HTTPS issuer and mount.
        account_id (int): Admin ID independently verified with the new bearer.
        scope (str): Exact offline bundle explicitly approved in the browser.
        refresh_token (str): Canonical refresh token from that same exchange.
        refresh_expires_in (int): Server-reported current refresh lifetime.

    Returns:
        None: The binding and credential were accepted by the vault owner.

    Raises:
        OfflineLinkingError: The binding, lifetime or secure storage fails.
    """
    if (not _binding_valid(issuer, account_id, scope)
            or type(refresh_expires_in) is not int
            or not 0 < refresh_expires_in <= OFFLINE_REFRESH_MAX_SECONDS):
        raise OfflineLinkingError('Offline credential is invalid.')
    record = _pack_refresh(scope, refresh_token)
    try:
        store.write(issuer, account_id, record)
    except Exception:
        raise OfflineLinkingError('Offline credential storage failed.') from None


def rotate_offline_access(
    store: WindowsCredentialStore, issuer: str, account_id: int,
    scope: str, cancelled: Event | None = None,
) -> OfflineAccess:
    """Rotate exactly once and verify the live account before releasing access.

    The pending marker and cross-process mutex remain held over the token POST,
    identity GET, replacement write and verification. No retry occurs after any
    failure or uncertain response. Scope and issuer/client are fixed by the
    trusted request; the server's token response has no issuer or client fields.

    Args:
        store (WindowsCredentialStore): Current Windows user's vault owner.
        issuer (str): Independently trusted exact HTTPS issuer and mount.
        account_id (int): Admin ID independently bound during initial approval.
        scope (str): Exact explicitly approved offline scope bundle.
        cancelled (Event | None): Optional cancellation signal for both requests.

    Returns:
        OfflineAccess: Validated in-memory bearer after replacement is committed.

    Raises:
        OfflineLinkingError: Binding, identity, transport or secure storage fails.
    """
    if not _binding_valid(issuer, account_id, scope):
        raise OfflineLinkingError('Offline connection is invalid.')
    try:
        with store.refresh_transaction(issuer, account_id) as transaction:
            record = transaction.read_current()
            if record is None:
                raise OfflineLinkingError('Offline credential is unavailable.')
            refresh = _unpack_refresh(record, scope)
            if cancelled is not None and cancelled.is_set():
                raise OfflineLinkingError('Offline connection was cancelled.')
            body = urlencode({
                'grant_type': 'refresh_token',
                'refresh_token': refresh,
                'client_id': CLIENT_ID,
            }).encode('ascii')
            transaction.begin_rotation()
            started = time.monotonic()
            response = _request_json(
                issuer, 'app_linking_token', 'POST', body, None,
                cancelled=cancelled,
            )
            access = response.get('access_token')
            replacement = response.get('refresh_token')
            lifetime = response.get('expires_in')
            refresh_lifetime = response.get('refresh_expires_in')
            if (set(response) != {'access_token', 'token_type', 'expires_in',
                                 'scope', 'refresh_token', 'refresh_expires_in'}
                    or not _valid_token(access, TOKEN_PREFIX)
                    or not _valid_token(replacement, _REFRESH_PREFIX)
                    or hmac.compare_digest(refresh, replacement)
                    or response.get('token_type') != 'Bearer'
                    or response.get('scope') != scope
                    or type(lifetime) is not int
                    or not 0 < lifetime <= OFFLINE_ACCESS_MAX_SECONDS
                    or type(refresh_lifetime) is not int
                    or not 0 < refresh_lifetime <= OFFLINE_REFRESH_MAX_SECONDS
                    or time.monotonic() >= started + lifetime):
                raise OfflineLinkingError('Offline response was not accepted.')
            identity = _request_json(
                issuer, 'app_linking_identity', 'GET', None, access,
                cancelled=cancelled,
            )
            user = identity.get('user')
            if (set(identity) != {'user', 'scope'} or identity.get('scope') != scope
                    or not isinstance(user, dict) or set(user) != {'id', 'username'}
                    or type(user['id']) is not int or user['id'] != account_id
                    or not isinstance(user['username'], str) or not user['username']
                    or len(user['username']) > LINKING_USERNAME_MAX_CHARS
                    or any(ord(c) < 32 or ord(c) == 127 for c in user['username'])
                    or (cancelled is not None and cancelled.is_set())
                    or time.monotonic() >= started + lifetime
                    or time.monotonic() >= started + refresh_lifetime):
                raise OfflineLinkingError('Offline identity was not accepted.')
            transaction.commit_rotated(_pack_refresh(scope, replacement))
            if ((cancelled is not None and cancelled.is_set())
                    or time.monotonic() >= started + lifetime
                    or time.monotonic() >= started + refresh_lifetime):
                raise OfflineLinkingError('Offline connection expired.')
            return OfflineAccess(issuer, account_id, scope, started + lifetime, access)
    except Exception:
        # A timeout or malformed reply may follow a committed server rotation.
        # Never expose exception text, repeat the token request or clear a fence.
        raise OfflineLinkingError('Offline refresh did not complete safely.') from None


def discard_uncertain_refresh(
    store: WindowsCredentialStore, issuer: str, account_id: int,
) -> bool:
    """Explicitly discard an uncertain local refresh without remote claims.

    Args:
        store (WindowsCredentialStore): Current Windows user's vault owner.
        issuer (str): Independently trusted canonical HTTPS issuer and mount.
        account_id (int): Positive Admin ID bound to the local record.

    Returns:
        bool: True when a pending local fence was removed; false if absent.

    Raises:
        OfflineLinkingError: The binding or local discard could not be verified.
    """
    if (not isinstance(issuer, str)
            or not _issuer_valid(issuer, allow_local_http=False)
            or type(account_id) is not int
            or not 0 < account_id <= CREDENTIAL_ACCOUNT_ID_MAX):
        raise OfflineLinkingError('Offline connection is invalid.')
    try:
        return store.discard_pending_refresh(issuer, account_id)
    except Exception:
        raise OfflineLinkingError('Offline discard did not complete safely.') from None

# Purpose: Bound one non-authoritative restart binding before JSON parsing.
# Type: int. Units: bytes. Scope: one local offline binding file.
# Consumers: load_offline_binding. Rationale: 2048 covers the exact bounded
# issuer and scalar metadata without accepting unbounded local input.
OFFLINE_BINDING_MAX_BYTES = 2048

# Purpose: Make temporary binding filenames unique across uploader processes.
# Type: int. Units: random bytes. Scope: one binding-file replacement.
# Consumers: save_offline_binding. Rationale: 16 bytes avoids collisions.
OFFLINE_BINDING_TEMP_RANDOM_BYTES = 16


@dataclass(frozen=True)
class OfflineBinding:
    """Describe non-secret restart hints; authority still requires the server.

    Attributes:
        issuer (str): Canonical HTTPS issuer and installation mount.
        account_id (int): Admin ID checked again after every token rotation.
        scope (str): Previously approved exact offline scope bundle.
    """

    issuer: str
    account_id: int
    scope: str


def save_offline_binding(path: Path, binding: OfflineBinding) -> None:
    """Atomically publish a new non-secret restart hint, never credentials.

    Args:
        path (Path): Dedicated binding file separate from legacy configuration.
        binding (OfflineBinding): Canonical previously consented account hint.

    Returns:
        None: The complete hint was published without replacing an existing one.

    Raises:
        OfflineLinkingError: The binding or local persistence is invalid.
    """
    import json
    import os
    import secrets
    import stat

    if not _binding_valid(binding.issuer, binding.account_id, binding.scope):
        raise OfflineLinkingError('Offline binding is invalid.')
    payload = json.dumps({
        'issuer': binding.issuer, 'account_id': binding.account_id,
        'scope': binding.scope,
    }, ensure_ascii=True, separators=(',', ':')).encode('ascii')
    if len(payload) > OFFLINE_BINDING_MAX_BYTES:
        raise OfflineLinkingError('Offline binding is invalid.')
    temporary = path.with_name(path.name + '.' + secrets.token_hex(OFFLINE_BINDING_TEMP_RANDOM_BYTES) + '.tmp')
    owned_stat = None
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        if path.parent.is_symlink() or path.is_symlink():
            raise OSError('Unsafe binding path.')
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_BINARY', 0)
        flags |= getattr(os, 'O_NOFOLLOW', 0)
        descriptor = os.open(temporary, flags, 0o600)
        try:
            owned_stat = os.fstat(descriptor)
        except BaseException:
            os.close(descriptor)
            raise
        try:
            handle = os.fdopen(descriptor, 'wb')
        except BaseException:
            os.close(descriptor)
            raise
        with handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        if path.parent.is_symlink() or path.is_symlink():
            raise OSError('Unsafe binding path.')
        # Atomic exclusive publication prevents concurrent link attempts from
        # overwriting an existing account hint or silently stranding its grant.
        os.link(temporary, path)
    except (OSError, ValueError):
        raise OfflineLinkingError('Offline binding could not be saved.') from None
    finally:
        if owned_stat is not None:
            try:
                current = temporary.lstat()
                if (stat.S_ISREG(current.st_mode)
                        and (current.st_dev, current.st_ino)
                        == (owned_stat.st_dev, owned_stat.st_ino)):
                    temporary.unlink()
            except OSError:
                pass


def load_offline_binding(path: Path, expected_issuer: str) -> OfflineBinding | None:
    """Read a bounded hint only for the independently selected exact issuer.

    Args:
        path (Path): Dedicated binding file separate from legacy configuration.
        expected_issuer (str): Canonical issuer selected independently by user.

    Returns:
        OfflineBinding | None: Validated same-issuer hint, or None when absent.

    Raises:
        OfflineLinkingError: The hint, path or storage state is invalid.
    """
    import json
    from .server_discovery import _unique_object

    if (not isinstance(expected_issuer, str)
            or not _issuer_valid(expected_issuer, allow_local_http=False)):
        raise OfflineLinkingError('Offline binding is invalid.')
    try:
        if path.parent.is_symlink() or path.is_symlink():
            raise OSError('Unsafe binding path.')
        with path.open('rb') as handle:
            raw = handle.read(OFFLINE_BINDING_MAX_BYTES + 1)
        if len(raw) > OFFLINE_BINDING_MAX_BYTES:
            raise ValueError('Binding is oversized.')
        data = json.loads(raw.decode('utf-8'), object_pairs_hook=_unique_object)
        if (not isinstance(data, dict) or set(data) != {'issuer', 'account_id', 'scope'}
                or data['issuer'] != expected_issuer
                or not _binding_valid(data['issuer'], data['account_id'], data['scope'])):
            raise ValueError('Binding is invalid.')
        return OfflineBinding(data['issuer'], data['account_id'], data['scope'])
    except FileNotFoundError:
        return None
    except (OSError, ValueError, UnicodeError, TypeError):
        raise OfflineLinkingError('Offline binding could not be read.') from None


def remove_offline_binding(path: Path, binding: OfflineBinding) -> None:
    """Remove only the same current hint following explicit local discard.

    Args:
        path (Path): Dedicated non-secret binding file.
        binding (OfflineBinding): Exact binding the user elected to remove.

    Returns:
        None: The matching hint is absent, or no hint existed.

    Raises:
        OfflineLinkingError: The hint changed or cannot be safely removed.
    """
    current = load_offline_binding(path, binding.issuer)
    if current is None:
        return
    if current != binding:
        raise OfflineLinkingError('Offline binding changed.')
    try:
        path.unlink()
    except OSError:
        raise OfflineLinkingError('Offline binding could not be removed.') from None
