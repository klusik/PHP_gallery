# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/config_snapshot.py
# Module Type: Configuration Persistence
# Purpose: Prepare conservative snapshots for the existing configuration owner.
# Responsibilities:
#   - Distinguish absent, validated and unavailable configuration evidence.
#   - Preserve unowned fields when publishing a verified configuration update.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Persistence primitive used exclusively by the existing ConfigStore owner.

The eventual caller must serialize all configuration writers and admissions.
Checks here are not an atomic compare-and-swap against unrelated processes.
"""

from dataclasses import dataclass, field
import json
import math
import os
from pathlib import Path
import stat
import tempfile
import threading
from typing import Literal

from .models import JsonValue
from .server_discovery import _reject_nonfinite, _unique_object
from .upload_mode import decode_mode_record, encode_mode_record


# Purpose: Bound private configuration reads and serialized updates.
# Type: int. Units: bytes. Scope: one uploader configuration file.
# Consumers: ConfigSnapshotStore.inspect and ConfigSnapshotStore.save.
# Rationale: 4 MiB exceeds ordinary preferences while preventing unbounded reads;
# historical larger files require explicit compatibility assessment before use.
CONFIG_SNAPSHOT_MAX_BYTES = 4 * 1024 * 1024

# Purpose: Reserve one configuration field for the separately verified mode.
# Type: str. Units: JSON field name. Scope: one config snapshot.
# Consumers: ConfigSnapshotStore mode-anchor methods.
# Rationale: Ordinary settings saves must not replace or remove this evidence.
CONFIG_MODE_ANCHOR_FIELD = 'upload_mode_v2'


class ConfigSnapshotError(OSError):
    """Report unavailable or changed configuration without exposing contents."""


@dataclass(frozen=True, repr=False)
class ConfigSnapshot:
    """Keep immutable, private evidence for one configuration observation.

    Attributes:
        state (Literal['missing', 'valid', 'unavailable']): Observation result.
        _owner (object): Identity of the store which made this observation.
        _parent (tuple[int, int] | None): Observed parent device and inode.
        _file (tuple[int, int, int, int] | None): File device, inode, size and
            modification time in nanoseconds.
        _content (bytes | None): Exact validated JSON bytes, potentially secret.
    """

    state: Literal['missing', 'valid', 'unavailable']
    _owner: object = field(repr=False)
    _parent: tuple[int, int] | None = field(repr=False)
    _file: tuple[int, int, int, int] | None = field(repr=False)
    _content: bytes | None = field(repr=False)


def _identity(info: os.stat_result) -> tuple[int, int, int, int]:
    """Extract the observed identity and modification attributes of a file.

    Args:
        info (os.stat_result): File metadata returned by stat or fstat.

    Returns:
        tuple[int, int, int, int]: Device, inode, size and nanosecond mtime.
    """
    return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)


def _identifiable(info: os.stat_result) -> bool:
    """Refuse metadata that cannot distinguish file or directory identity.

    Args:
        info (os.stat_result): Metadata returned by stat, lstat or fstat.

    Returns:
        bool: True only for positive device and inode identifiers.
    """
    return info.st_dev > 0 and info.st_ino > 0


def _finite_float(value: str) -> float:
    """Reject JSON numeric values outside the finite floating-point range.

    Args:
        value (str): JSON number representation provided by the decoder.

    Returns:
        float: The finite decoded number.

    Raises:
        ValueError: The number does not have a finite representation.
    """
    result = float(value)
    if not math.isfinite(result):
        raise ValueError('Configuration number is unavailable.')
    return result


def _document(content: bytes) -> dict[str, JsonValue]:
    """Decode one bounded JSON object without ambiguous duplicate keys.

    Args:
        content (bytes): Complete private configuration bytes.

    Returns:
        dict[str, JsonValue]: Unique keys and their JSON-compatible values.

    Raises:
        ValueError: The document is not an unambiguous UTF-8 JSON object.
    """
    try:
        value = json.loads(content.decode('utf-8'), object_pairs_hook=_unique_object,
                           parse_constant=_reject_nonfinite, parse_float=_finite_float)
        if not isinstance(value, dict):
            raise ValueError
        return value
    except (UnicodeError, ValueError, TypeError, RecursionError):
        raise ValueError('Configuration document is unavailable.') from None


def _merge_document(content: bytes | None, fields: dict[str, JsonValue]) -> bytes:
    """Replace owned top-level fields while retaining all other raw values.

    Args:
        content (bytes | None): Previously validated object, or confirmed absence.
        fields (dict[str, JsonValue]): Configuration keys owned by the caller.

    Returns:
        bytes: UTF-8 JSON object retaining untouched values byte-for-byte.

    Raises:
        ValueError: An owned value cannot be represented as strict JSON.
    """
    source = content.decode('utf-8') if content is not None else '{}'
    decoder = json.JSONDecoder(object_pairs_hook=_unique_object,
                               parse_constant=_reject_nonfinite,
                               parse_float=_finite_float)
    position = 0
    while source[position] in ' \t\r\n':
        position += 1
    position += 1  # The previously validated top-level value is an object.
    segments: list[str] = []
    remaining = dict(fields)
    while True:
        while source[position] in ' \t\r\n':
            position += 1
        if source[position] == '}':
            break
        key, position = decoder.raw_decode(source, position)
        while source[position] in ' \t\r\n':
            position += 1
        position += 1  # The already validated object contains a colon here.
        while source[position] in ' \t\r\n':
            position += 1
        start = position
        _, position = decoder.raw_decode(source, position)
        value = (json.dumps(remaining.pop(key), ensure_ascii=False, allow_nan=False)
                 if key in remaining else source[start:position])
        segments.append(json.dumps(key, ensure_ascii=False) + ':' + value)
        while source[position] in ' \t\r\n':
            position += 1
        if source[position] == '}':
            break
        position += 1  # The validated object contains a comma here.
    for key, value in remaining.items():
        segments.append(json.dumps(key, ensure_ascii=False) + ':'
                        + json.dumps(value, ensure_ascii=False, allow_nan=False))
    return ('{' + ','.join(segments) + '}').encode('utf-8')


class ConfigSnapshotStore:
    """Provide snapshot persistence to the application's ConfigStore.

    The caller owns the existing process lease and routes every standard config
    write, including migration, through this primitive. Old or unrelated writers
    do not participate in that lease.
    """

    def __init__(self, path: Path, managed_fields: frozenset[str]) -> None:
        """Bind one path and the fields the existing config owner may update.

        Args:
            path (Path): Configuration path selected by the application.
            managed_fields (frozenset[str]): Known WatcherConfig keys; future
                mode metadata must not be included in this set.

        Returns:
            None: Creates an unused, process-local persistence owner.
        """
        if CONFIG_MODE_ANCHOR_FIELD in managed_fields:
            raise ConfigSnapshotError('Configuration field ownership is invalid.')
        self.path = path
        self._managed_fields = managed_fields
        self._owner = object()
        self._lock = threading.RLock()

    def inspect(self) -> ConfigSnapshot:
        """Observe a complete object, a proven absent file, or an unknown state.

        Returns:
            ConfigSnapshot: Private immutable evidence owned by this store.
        """
        with self._lock:
            unknown = ConfigSnapshot('unavailable', self._owner, None, None, None)
            try:
                parent = self.path.parent.stat()
                if not stat.S_ISDIR(parent.st_mode) or not _identifiable(parent):
                    return unknown
                parent_id = (parent.st_dev, parent.st_ino)
                try:
                    observed = self.path.lstat()
                except FileNotFoundError:
                    after = self.path.parent.stat()
                    if parent_id != (after.st_dev, after.st_ino):
                        return unknown
                    return ConfigSnapshot('missing', self._owner, parent_id, None, None)
                if (not stat.S_ISREG(observed.st_mode) or not _identifiable(observed)
                        or observed.st_nlink != 1
                        or observed.st_size > CONFIG_SNAPSHOT_MAX_BYTES):
                    return unknown
                flags = os.O_RDONLY | getattr(os, 'O_BINARY', 0)
                flags |= getattr(os, 'O_NOFOLLOW', 0)
                flags |= getattr(os, 'O_NONBLOCK', 0)
                descriptor = os.open(self.path, flags)
                try:
                    handle = os.fdopen(descriptor, 'rb')
                except BaseException:
                    os.close(descriptor)
                    raise
                with handle:
                    opened = os.fstat(handle.fileno())
                    if (not stat.S_ISREG(opened.st_mode) or not _identifiable(opened)
                            or opened.st_nlink != 1
                            or _identity(opened) != _identity(observed)):
                        return unknown
                    content = handle.read(CONFIG_SNAPSHOT_MAX_BYTES + 1)
                    final_handle = os.fstat(handle.fileno())
                current = self.path.lstat()
                after = self.path.parent.stat()
                if (len(content) > CONFIG_SNAPSHOT_MAX_BYTES
                        or _identity(opened) != _identity(final_handle)
                        or _identity(opened) != _identity(current)
                        or current.st_nlink != 1
                        or parent_id != (after.st_dev, after.st_ino)):
                    return unknown
                _document(content)
                return ConfigSnapshot('valid', self._owner, parent_id,
                                      _identity(opened), content)
            except (OSError, ValueError):
                return unknown

    def read(self, snapshot: ConfigSnapshot) -> dict[str, JsonValue]:
        """Decode a validated snapshot without disclosing it in diagnostics.

        Args:
            snapshot (ConfigSnapshot): Immutable observation from this owner.

        Returns:
            dict[str, JsonValue]: A fresh object for the existing config parser.

        Raises:
            ConfigSnapshotError: The observation is not a validated object.
        """
        if snapshot._owner is not self._owner or snapshot.state != 'valid':
            raise ConfigSnapshotError('Configuration evidence is unavailable.')
        if snapshot._content is None:
            raise ConfigSnapshotError('Configuration evidence is unavailable.')
        return _document(snapshot._content)

    def save(self, snapshot: ConfigSnapshot,
             fields: dict[str, JsonValue]) -> ConfigSnapshot:
        """Publish managed fields only while the complete snapshot still matches.

        Args:
            snapshot (ConfigSnapshot): Valid or positively absent prior evidence.
            fields (dict[str, JsonValue]): Known keys and JSON-compatible values.

        Returns:
            ConfigSnapshot: Read-back evidence for the newly published document.

        Raises:
            ConfigSnapshotError: Evidence, staging, publication or readback fails.
        """
        return self._save_fields(snapshot, fields, self._managed_fields)

    def read_mode_anchor(self, snapshot: ConfigSnapshot) -> bytes | None:
        """Read only a validated anchor from the same current full snapshot.

        Args:
            snapshot (ConfigSnapshot): Previously observed existing config.

        Returns:
            bytes | None: Canonical mode record bytes, or confirmed field absence.

        Raises:
            ConfigSnapshotError: The config or anchor is changed or invalid.
        """
        with self._lock:
            if (snapshot._owner is not self._owner or snapshot.state != 'valid'
                    or self.inspect() != snapshot):
                raise ConfigSnapshotError('Configuration evidence changed.')
            document = self.read(snapshot)
            if CONFIG_MODE_ANCHOR_FIELD not in document:
                return None
            try:
                payload = json.dumps(
                    document[CONFIG_MODE_ANCHOR_FIELD], ensure_ascii=True,
                    separators=(',', ':'), allow_nan=False,
                ).encode('ascii')
                return encode_mode_record(decode_mode_record(payload))
            except (ValueError, TypeError, UnicodeError, RecursionError):
                raise ConfigSnapshotError('Upload mode anchor is unavailable.') from None

    def save_mode_anchor(self, snapshot: ConfigSnapshot, payload: bytes) -> ConfigSnapshot:
        """Update only the validated mode field under the same snapshot writer.

        Args:
            snapshot (ConfigSnapshot): Exact previously verified existing config.
            payload (bytes): Canonical validated upload mode record bytes.

        Returns:
            ConfigSnapshot: Read-back evidence for the updated configuration.

        Raises:
            ConfigSnapshotError: The snapshot, anchor or publication is unsafe.
        """
        with self._lock:
            if snapshot.state != 'valid':
                raise ConfigSnapshotError('Configuration evidence is unavailable.')
            self.read_mode_anchor(snapshot)
            try:
                record = decode_mode_record(payload)
                if encode_mode_record(record) != payload:
                    raise ValueError
                value = _document(payload)
            except (ValueError, TypeError, UnicodeError, RecursionError):
                raise ConfigSnapshotError('Upload mode anchor is unavailable.') from None
            updated = self._save_fields(
                snapshot, {CONFIG_MODE_ANCHOR_FIELD: value},
                frozenset({CONFIG_MODE_ANCHOR_FIELD}),
            )
            if self.read_mode_anchor(updated) != payload:
                raise ConfigSnapshotError('Upload mode publication is uncertain.')
            return updated

    def _save_fields(
        self, snapshot: ConfigSnapshot, fields: dict[str, JsonValue],
        allowed: frozenset[str],
    ) -> ConfigSnapshot:
        """Publish one owned field set through the shared snapshot writer.

        Args:
            snapshot (ConfigSnapshot): Exact current config observation.
            fields (dict[str, JsonValue]): Owned keys and JSON-compatible values.
            allowed (frozenset[str]): Keys this owner is allowed to change.

        Returns:
            ConfigSnapshot: Read-back evidence for the published document.

        Raises:
            ConfigSnapshotError: Evidence, staging or publication is unsafe.
        """
        with self._lock:
            if (snapshot._owner is not self._owner
                    or snapshot.state not in ('missing', 'valid')
                    or type(fields) is not dict
                    or any(type(key) is not str for key in fields)
                    or not fields.keys() <= allowed
                    or self.inspect() != snapshot):
                raise ConfigSnapshotError('Configuration evidence changed.')
            try:
                encoded = _merge_document(snapshot._content, fields)
                if len(encoded) > CONFIG_SNAPSHOT_MAX_BYTES:
                    raise ValueError
                _document(encoded)
                descriptor, temporary = tempfile.mkstemp(
                    prefix='.config-stage-', dir=self.path.parent,
                )
                stage = Path(temporary)
                stage_id = None
                try:
                    try:
                        stage_info = os.fstat(descriptor)
                        if not _identifiable(stage_info) or stage_info.st_nlink != 1:
                            raise ConfigSnapshotError('Configuration staging failed.')
                        stage_id = _identity(stage_info)[:2]
                        handle = os.fdopen(descriptor, 'w+b')
                    except BaseException:
                        os.close(descriptor)
                        raise
                    with handle:
                        handle.write(encoded)
                        handle.flush()
                        os.fsync(handle.fileno())
                        handle.seek(0)
                        if handle.read() != encoded:
                            raise ConfigSnapshotError('Configuration staging failed.')
                    info = stage.lstat()
                    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                            or _identity(info)[:2] != stage_id
                            or self.inspect() != snapshot):
                        raise ConfigSnapshotError('Configuration evidence changed.')
                    os.replace(stage, self.path)
                    result = self.inspect()
                    if (result.state != 'valid' or result._content != encoded
                            or result._file is None or result._file[:2] != stage_id):
                        raise ConfigSnapshotError('Configuration publication is uncertain.')
                    return result
                finally:
                    try:
                        info = stage.lstat()
                        if (stage_id is not None and stat.S_ISREG(info.st_mode)
                                and info.st_nlink == 1
                                and _identity(info)[:2] == stage_id):
                            stage.unlink()
                    except FileNotFoundError:
                        pass
            except (OSError, TypeError, ValueError, RecursionError):
                raise ConfigSnapshotError('Configuration update is unavailable.') from None
