# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/upload_mode.py
# Module Type: Upload Mode Policy
# Purpose: Validate independent restart-mode evidence without selecting a target.
# Responsibilities:
#   - Bind a versioned preference to an exact account and gallery.
#   - Refuse absent, malformed or conflicting restart evidence.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Unintegrated restart-mode policy; it never selects or contacts a gallery."""

from dataclasses import dataclass
import hashlib
import json
import re
import sys
from typing import Callable, Literal, Protocol

from .app_linking import LINKING_GALLERY_ID_MAX, UPLOAD_SCOPE, _issuer_valid, scope_base
from .server_discovery import _unique_object


# Purpose: Bound non-secret mode records before parsing or native storage.
# Type: int. Units: bytes. Scope: one preference record.
# Consumers: encode_mode_record, decode_mode_record.
# Rationale: 2000 bytes fits the existing Credential Manager blob limit.
UPLOAD_MODE_MAX_BYTES = 2000

# Purpose: Version the exact non-secret restart-mode record shape.
# Type: int. Units: format version. Scope: one mode record.
# Consumers: ModeRecord, encode_mode_record, decode_mode_record.
# Rationale: Version 2 adds an exact lexical config-path binding.
UPLOAD_MODE_VERSION = 2

# Purpose: Bound an untrusted config path before validation and hashing.
# Type: int. Units: Unicode characters. Scope: one lexical profile path.
# Consumers: profile_path_digest.
# Rationale: 32767 bounds Python code points; it is not a native UTF-16 limit.
UPLOAD_MODE_PATH_MAX_CHARS = 32767

# Purpose: Separate lexical profile digests from every other application hash.
# Type: bytes. Units: fixed prefix. Scope: version 2 mode-path binding.
# Consumers: profile_path_digest. Rationale: Prevents cross-domain hash reuse.
_PROFILE_PATH_DOMAIN = b'PHP Gallery upload mode lexical config path v2\x00'

# Purpose: Refuse DOS device names with extensions or pre-extension spaces.
# Type: frozenset[str]. Units: case-insensitive component names.
# Scope: lexical Windows profile paths. Consumers: profile_path_digest.
# Rationale: These names may resolve to devices rather than ordinary files.
_WINDOWS_RESERVED_NAMES = frozenset({
    'CON', 'PRN', 'AUX', 'NUL', 'CONIN$', 'CONOUT$',
    *(f'COM{index}' for index in '123456789¹²³'),
    *(f'LPT{index}' for index in '123456789¹²³'),
})


class UploadModeError(ValueError):
    """Report invalid or inconclusive mode evidence without its contents."""


@dataclass(frozen=True)
class ModeRecord:
    """Bind one explicit mode generation to a stable local profile.

    Attributes:
        profile_id (str): Random 32-character lowercase hexadecimal profile ID.
        generation (str): Random 32-character lowercase hexadecimal generation.
        mode (str): Exact 'legacy' or 'linked' selection.
        profile_path_hash (str): Domain-separated lexical config-path digest.
        issuer (str | None): Exact canonical installation for linked mode.
        account_id (int | None): Server-verified linked Admin ID.
        scope (str | None): Exact previously approved linked upload scope.
        gallery_id (int | None): Server-verified linked gallery ID.
    """

    profile_id: str
    generation: str
    mode: Literal['legacy', 'linked']
    profile_path_hash: str
    issuer: str | None = None
    account_id: int | None = None
    scope: str | None = None
    gallery_id: int | None = None


class ModeVaultTransaction(Protocol):
    """Describe a caller-owned vault transaction for one mode record."""

    def read(self) -> ModeRecord | None:
        """Read the current validated mode record or confirmed absence.

        Returns:
            ModeRecord | None: Bound record or confirmed absence.
        """
        ...

    def write(self, record: ModeRecord) -> None:
        """Persist and verify one versioned mode record.

        Args:
            record (ModeRecord): Exact generation selected by the caller.

        Returns:
            None: The record was written and read back by its owner.
        """
        ...


def profile_path_digest(config_path: str) -> str:
    """Hash an exact absolute config-path spelling for an admitted profile.

    This lexical identifier deliberately does not resolve aliases, case,
    junctions, reparse points or filesystem identity. Ambiguous syntax is
    refused; equivalent paths with different spellings remain different.

    Args:
        config_path (str): Absolute drive-rooted Windows path, or an absolute
            POSIX path on macOS.

    Returns:
        str: Lowercase domain-separated SHA-256 of its exact UTF-8 spelling.

    Raises:
        UploadModeError: Path syntax or its Unicode representation is unsafe.
    """
    if (not isinstance(config_path, str) or not config_path
            or len(config_path) > UPLOAD_MODE_PATH_MAX_CHARS):
        raise UploadModeError('Upload mode profile path is unavailable.')
    if sys.platform == 'darwin' and config_path.startswith('/'):
        for component in config_path[1:].split('/'):
            if (not component or component in ('.', '..')
                    or any(ord(char) < 32 or ord(char) == 127 for char in component)):
                raise UploadModeError('Upload mode profile path is unavailable.')
    else:
        if (len(config_path) < 4
                or config_path[0] not in 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'
                or config_path[1:3] != ':\\'):
            raise UploadModeError('Upload mode profile path is unavailable.')
        for component in config_path[3:].split('\\'):
            device_name = component.split('.', 1)[0].rstrip(' ').upper()
            if (not component or component in ('.', '..')
                    or component[-1] in (' ', '.')
                    or any(ord(char) < 32 or ord(char) == 127
                           or char in '<>:"/|?*' for char in component)
                    or device_name in _WINDOWS_RESERVED_NAMES):
                raise UploadModeError('Upload mode profile path is unavailable.')
    try:
        encoded = config_path.encode('utf-8')
    except UnicodeError:
        raise UploadModeError('Upload mode profile path is unavailable.') from None
    return hashlib.sha256(_PROFILE_PATH_DOMAIN + encoded).hexdigest()

def encode_mode_record(record: ModeRecord) -> bytes:
    """Validate and serialize one exact non-secret restart preference.

    Args:
        record (ModeRecord): Profile, generation and explicit mode binding.

    Returns:
        bytes: Bounded canonical ASCII JSON for independent storage owners.

    Raises:
        UploadModeError: The binding, generation or encoding is invalid.
    """
    if (not isinstance(record, ModeRecord)
            or not isinstance(record.profile_id, str)
            or re.fullmatch(r'[a-f0-9]{32}', record.profile_id) is None
            or not isinstance(record.generation, str)
            or re.fullmatch(r'[a-f0-9]{32}', record.generation) is None
            or not isinstance(record.profile_path_hash, str)
            or re.fullmatch(r'[a-f0-9]{64}', record.profile_path_hash) is None):
        raise UploadModeError('Upload mode record is invalid.')
    if record.mode == 'legacy':
        if any(value is not None for value in (
            record.issuer, record.account_id, record.scope, record.gallery_id,
        )):
            raise UploadModeError('Upload mode record is invalid.')
    elif record.mode == 'linked':
        if (not isinstance(record.issuer, str) or not _issuer_valid(record.issuer)
                or type(record.account_id) is not int
                or not 0 < record.account_id <= LINKING_GALLERY_ID_MAX
                or not isinstance(record.scope, str)
                or scope_base(record.scope) != UPLOAD_SCOPE
                or type(record.gallery_id) is not int
                or not 0 < record.gallery_id <= LINKING_GALLERY_ID_MAX):
            raise UploadModeError('Upload mode record is invalid.')
    else:
        raise UploadModeError('Upload mode record is invalid.')
    payload = json.dumps({
        'version': UPLOAD_MODE_VERSION,
        'profile_id': record.profile_id,
        'generation': record.generation,
        'mode': record.mode,
        'profile_path_hash': record.profile_path_hash,
        'issuer': record.issuer,
        'account_id': record.account_id,
        'scope': record.scope,
        'gallery_id': record.gallery_id,
    }, ensure_ascii=True, separators=(',', ':')).encode('ascii')
    if len(payload) > UPLOAD_MODE_MAX_BYTES:
        raise UploadModeError('Upload mode record is invalid.')
    return payload


def decode_mode_record(payload: bytes) -> ModeRecord:
    """Decode one exact mode record without accepting duplicate fields.

    Args:
        payload (bytes): Bounded untrusted JSON bytes from one storage owner.

    Returns:
        ModeRecord: Fully validated profile, generation and selection.

    Raises:
        UploadModeError: The bytes, shape or selected binding are invalid.
    """
    if type(payload) is not bytes or not payload or len(payload) > UPLOAD_MODE_MAX_BYTES:
        raise UploadModeError('Upload mode record is invalid.')
    try:
        data = json.loads(payload.decode('ascii'), object_pairs_hook=_unique_object)
        if (not isinstance(data, dict) or set(data) != {
            'version', 'profile_id', 'generation', 'mode', 'profile_path_hash', 'issuer',
            'account_id', 'scope', 'gallery_id',
        } or type(data['version']) is not int or data['version'] != UPLOAD_MODE_VERSION):
            raise UploadModeError('Upload mode record is invalid.')
        mode = data['mode']
        if mode not in ('legacy', 'linked'):
            raise UploadModeError('Upload mode record is invalid.')
        record = ModeRecord(
            data['profile_id'], data['generation'], mode, data['profile_path_hash'], data['issuer'],
            data['account_id'], data['scope'], data['gallery_id'],
        )
        encode_mode_record(record)
        return record
    except (ValueError, UnicodeError, TypeError, RecursionError):
        raise UploadModeError('Upload mode record is invalid.') from None


def resolve_mode(
    anchor: bytes | None, vault: bytes | None, *, config_verified: bool,
    config_path: str,
) -> tuple[Literal['legacy_unmigrated', 'legacy', 'linked_disconnected'], ModeRecord | None]:
    """Resolve two independently read mode records without enabling upload.

    Args:
        anchor (bytes | None): Config anchor bytes, or confirmed absence.
        vault (bytes | None): Vault record bytes, or confirmed absence.
        config_verified (bool): True only after the complete existing config was
            read and validated independently; a missing first-run file is false.
            This resolver cannot itself observe or distinguish those states.
        config_path (str): Independently selected absolute lexical config path.

    Returns:
        tuple[Literal['legacy_unmigrated', 'legacy', 'linked_disconnected'], ModeRecord | None]:
            Conservative state and its matching record, if migrated.

    Raises:
        UploadModeError: Storage is mismatched, malformed or not legacy-safe.
    """
    if config_verified is not True or not isinstance(config_path, str) or not config_path:
        raise UploadModeError('Upload mode evidence is unavailable.')
    if anchor is None and vault is None:
        return 'legacy_unmigrated', None
    if anchor is None or vault is None:
        raise UploadModeError('Upload mode evidence is unavailable.')
    expected_path_hash = profile_path_digest(config_path)
    config_record = decode_mode_record(anchor)
    vault_record = decode_mode_record(vault)
    if (config_record != vault_record
            or config_record.profile_path_hash != expected_path_hash):
        raise UploadModeError('Upload mode evidence does not match.')
    return ('legacy' if config_record.mode == 'legacy' else 'linked_disconnected'), config_record


def publish_mode(
    record: ModeRecord, vault: ModeVaultTransaction,
    read_anchor: Callable[[], bytes | None],
    write_anchor: Callable[[bytes], None], *, config_verified: bool,
    expected_previous: ModeRecord | None, config_path: str,
) -> None:
    """Publish one explicit generation through caller-owned storage leases.

    The caller holds the metadata and work-admission leases throughout. Anchor
    callbacks must safely read and atomically publish the config-owned record;
    a successful readback does not guarantee directory durability on power loss.
    Any interrupted or uncertain write must be resolved by reading both records.
    This policy does not establish profile-path identity, preserve unrelated
    config fields, or exclude older/non-cooperating app processes. Those owners
    require Windows qualification before any runtime integration is enabled.

    Args:
        record (ModeRecord): Explicitly selected profile and new generation.
        vault (ModeVaultTransaction): Entered, SID-bound mode vault transaction.
        read_anchor (Callable[[], bytes | None]): Read validated anchor bytes or
            confirmed absence; storage errors must raise rather than return None.
        write_anchor (Callable[[bytes], None]): Publish exact anchor bytes.
        config_verified (bool): True only for an independently verified existing
            valid config, never for a missing first-run file.
        expected_previous (ModeRecord | None): Exact state reviewed before the
            user confirmed this change, or confirmed unmigrated legacy state.
        config_path (str): Independently selected absolute lexical config path.

    Returns:
        None: Both storage owners read back the same new generation.

    Raises:
        UploadModeError: Evidence, generation or readback is inconsistent.
    """
    payload = encode_mode_record(record)
    if record.profile_path_hash != profile_path_digest(config_path):
        raise UploadModeError('Upload mode profile path does not match.')
    original_anchor = read_anchor()
    original_vault = vault.read()
    original_vault_bytes = (
        encode_mode_record(original_vault) if original_vault is not None else None
    )
    _, previous = resolve_mode(
        original_anchor, original_vault_bytes,
        config_verified=config_verified, config_path=config_path,
    )
    if previous != expected_previous:
        raise UploadModeError('Upload mode changed before confirmation.')
    if previous is not None and (
        previous.profile_id != record.profile_id
        or previous.generation == record.generation
    ):
        raise UploadModeError('Upload mode generation is unavailable.')
    vault.write(record)
    if vault.read() != record or read_anchor() != original_anchor:
        raise UploadModeError('Upload mode publication is unconfirmed.')
    write_anchor(payload)
    if read_anchor() != payload or vault.read() != record:
        raise UploadModeError('Upload mode publication is unconfirmed.')
