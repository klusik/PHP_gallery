# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/pgu_upload_journal.py
# Module Type: Legacy Upload Journal
# Purpose: Record one target-bound upload before a legacy API request.
# Responsibilities:
#   - Serialize cooperating writers and retain uncertain upload intents.
#   - Persist accepted acknowledgements without storing endpoints or API keys.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Private pgu write-ahead state; no request or automatic replay is performed."""

import hashlib
import json
import os
import re
import secrets
import stat
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Literal

from .models import JsonValue
from .server_discovery import _unique_object

# Purpose: Bound a non-secret intent and acknowledgement record.
# Type: int. Units: bytes. Scope: one target journal document.
# Consumers: PguUploadJournal. Rationale: 2048 fits the fixed record shape.
PGU_JOURNAL_MAX_BYTES = 2048

# Purpose: Bound a file size to the signed server-side integer range.
# Type: int. Units: bytes. Scope: one upload intent.
# Consumers: PguUploadJournal.begin. Rationale: 2**63-1 avoids overflow.
PGU_JOURNAL_MAX_FILE_BYTES = 9223372036854775807

# Purpose: Uniquely identify a journal operation and its staging files.
# Type: int. Units: random bytes. Scope: one local attempt or staging name.
# Consumers: PguUploadJournal. Rationale: 16 bytes provide 128-bit identifiers.
PGU_JOURNAL_RANDOM_BYTES = 16

# Purpose: Separate pgu records from linked-client and legacy global state.
# Type: str. Units: one path component. Scope: one existing uploader profile.
# Consumers: PguUploadJournal. Rationale: versioned namespace supports evolution.
PGU_JOURNAL_DIRECTORY = 'pgu-upload-journal-v1'


class PguJournalError(RuntimeError):
    """Report a busy or uncertain local journal without sensitive details."""


@dataclass(frozen=True, repr=False)
class PguUploadRecord:
    """Identify one bound local attempt and its last acknowledged state.

    Attributes:
        binding (str): Local connection fingerprint, never the key or URL.
        sha256 (str): Lowercase source-content digest.
        size (int): Captured source size in bytes.
        nonce (str): Random local operation identifier.
        state (Literal['pending', 'accepted', 'reviewed']): Pending before an
            exact legacy acknowledgement, accepted after its publication, or
            reviewed after an explicit user decision about an uncertain result.
    """

    binding: str
    sha256: str
    size: int
    nonce: str
    state: Literal['pending', 'accepted', 'reviewed']


def _identity(info: os.stat_result) -> tuple[int, int]:
    """Extract an independently identifiable filesystem object.

    Args:
        info (os.stat_result): Result of a descriptor or path observation.

    Returns:
        tuple[int, int]: Positive device and inode identity.

    Raises:
        PguJournalError: Identity or reparse metadata cannot be trusted.
    """
    attributes = getattr(info, 'st_file_attributes', 0)
    reparse = getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 1024)
    if info.st_dev <= 0 or info.st_ino <= 0 or attributes & reparse:
        raise PguJournalError('Upload journal identity is unavailable.')
    return info.st_dev, info.st_ino


def _record_bytes(record: PguUploadRecord) -> bytes:
    """Encode the exact bounded journal shape without local paths or keys.

    Args:
        record (PguUploadRecord): Validated target-bound operation.

    Returns:
        bytes: Complete canonical UTF-8 JSON record.
    """
    return json.dumps({
        'version': 1, 'binding': record.binding, 'sha256': record.sha256,
        'size': record.size, 'nonce': record.nonce, 'state': record.state,
    }, separators=(',', ':'), sort_keys=True).encode('ascii')


def _decode_record(raw: bytes, binding: str) -> PguUploadRecord:
    """Decode a strict record and compare it to the selected target binding.

    Args:
        raw (bytes): Bounded untrusted persisted journal content.
        binding (str): Exact expected lowercase target fingerprint.

    Returns:
        PguUploadRecord: Validated target-bound intent or acknowledgement.

    Raises:
        PguJournalError: Content is malformed, duplicated or belongs elsewhere.
    """
    try:
        data = json.loads(raw.decode('ascii'), object_pairs_hook=_unique_object)
        if (not isinstance(data, dict) or set(data) != {
                'version', 'binding', 'sha256', 'size', 'nonce', 'state',
        } or type(data['version']) is not int or data['version'] != 1
                or data['binding'] != binding
                or not isinstance(data['sha256'], str)
                or re.fullmatch(r'[0-9a-f]{64}', data['sha256']) is None
                or type(data['size']) is not int
                or not 0 < data['size'] <= PGU_JOURNAL_MAX_FILE_BYTES
                or not isinstance(data['nonce'], str)
                or re.fullmatch(r'[0-9a-f]{32}', data['nonce']) is None
                or data['state'] not in ('pending', 'accepted', 'reviewed')):
            raise ValueError('Invalid record.')
        return PguUploadRecord(binding, data['sha256'], data['size'],
                               data['nonce'], data['state'])
    except (UnicodeError, ValueError, TypeError, KeyError):
        raise PguJournalError('Upload journal cannot be verified.') from None


class PguUploadJournal:
    """Own one target-specific pending slot and retained accepted records.

    The caller derives ``binding`` using the existing connection_binding owner.
    Named files and their contents never contain the endpoint, API key or source
    path. Advisory files coordinate only cooperating processes. Filesystem
    replacements by other processes and Windows/power-loss behavior are not
    qualified by this owner.
    """

    def __init__(self, config_dir: Path, binding: str) -> None:
        """Select an existing profile and one opaque legacy connection.

        Args:
            config_dir (Path): Existing per-user uploader profile directory.
            binding (str): SHA-256 from connection_binding(endpoint, key).

        Returns:
            None: Records configuration without opening storage or the network.

        Raises:
            PguJournalError: The supplied target binding is malformed.
        """
        if not isinstance(binding, str) or re.fullmatch(r'[0-9a-f]{64}', binding) is None:
            raise PguJournalError('Upload target binding is unavailable.')
        self._root = Path(config_dir)
        self._binding = binding
        self._directory = self._root / PGU_JOURNAL_DIRECTORY
        self._slot = self._directory / (binding + '.json')
        self._witness = self._directory / (binding + '.started.json')
        self._lock = self._directory / (binding + '.lock')

    def _prepare_directory(self) -> tuple[tuple[int, int], tuple[int, int]]:
        """Create and verify the direct profile and journal directory owners.

        Returns:
            tuple[tuple[int, int], tuple[int, int]]: Profile and journal IDs.

        Raises:
            PguJournalError: A direct owner is missing, replaced or redirected.
        """
        try:
            root = self._root.lstat()
            if not stat.S_ISDIR(root.st_mode):
                raise PguJournalError('Upload journal directory is unavailable.')
            root_id = _identity(root)
            self._directory.mkdir(mode=0o700, exist_ok=True)
            directory = self._directory.lstat()
            if not stat.S_ISDIR(directory.st_mode):
                raise PguJournalError('Upload journal directory is unavailable.')
            directory_id = _identity(directory)
            if _identity(self._root.lstat()) != root_id:
                raise PguJournalError('Upload journal directory changed.')
            return root_id, directory_id
        except (OSError, ValueError):
            raise PguJournalError('Upload journal directory is unavailable.') from None

    def _check_directory(self, identities: tuple[tuple[int, int], tuple[int, int]]) -> None:
        """Compare current direct parent identities with their lease snapshot.

        Args:
            identities (tuple[tuple[int, int], tuple[int, int]]): Profile and
                journal device/inode pairs captured at lease acquisition.

        Returns:
            None: Both directories still match the original observations.

        Raises:
            PguJournalError: A directory changed or cannot be observed.
        """
        try:
            root, directory = self._root.lstat(), self._directory.lstat()
            if (not stat.S_ISDIR(root.st_mode) or not stat.S_ISDIR(directory.st_mode)
                    or (_identity(root), _identity(directory)) != identities):
                raise PguJournalError('Upload journal directory changed.')
        except OSError:
            raise PguJournalError('Upload journal directory is unavailable.') from None

    def _read(self, path: Path) -> tuple[PguUploadRecord, tuple[int, int]] | None:
        """Read one bounded, unredirected regular record.

        Args:
            path (Path): Journal-owned slot or accepted-record path.

        Returns:
            tuple[PguUploadRecord, tuple[int, int]] | None: Exact record and
                device/inode, or None only when the final file is absent.

        Raises:
            PguJournalError: The file is unreadable, changed or malformed.
        """
        try:
            observed = path.lstat()
        except FileNotFoundError:
            return None
        except OSError:
            raise PguJournalError('Upload journal cannot be read.') from None
        try:
            identity = _identity(observed)
            if (not stat.S_ISREG(observed.st_mode) or observed.st_nlink != 1
                    or observed.st_size > PGU_JOURNAL_MAX_BYTES):
                raise PguJournalError('Upload journal cannot be verified.')
            flags = os.O_RDONLY | getattr(os, 'O_BINARY', 0)
            flags |= getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0)
            descriptor = os.open(path, flags)
            try:
                opened = os.fstat(descriptor)
                if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
                        or _identity(opened) != identity):
                    raise PguJournalError('Upload journal changed.')
                raw = os.read(descriptor, PGU_JOURNAL_MAX_BYTES + 1)
                after = os.fstat(descriptor)
            finally:
                os.close(descriptor)
            current = path.lstat()
            if (len(raw) > PGU_JOURNAL_MAX_BYTES or after.st_nlink != 1
                    or current.st_nlink != 1 or _identity(after) != identity
                    or _identity(current) != identity or len(raw) != after.st_size
                    or opened.st_size != after.st_size or after.st_size != current.st_size
                    or opened.st_mtime_ns != after.st_mtime_ns
                    or after.st_mtime_ns != current.st_mtime_ns):
                raise PguJournalError('Upload journal changed.')
            return _decode_record(raw, self._binding), identity
        except (OSError, ValueError):
            raise PguJournalError('Upload journal cannot be verified.') from None

    def _stage(self, payload: bytes) -> tuple[Path, tuple[int, int]]:
        """Write and read back a new exclusive journal staging file.

        Args:
            payload (bytes): Complete canonical bounded record bytes.

        Returns:
            tuple[Path, tuple[int, int]]: Owned stage path and device/inode.

        Raises:
            PguJournalError: Staging cannot be durably written and verified.
        """
        stage = self._directory / ('.stage-' + secrets.token_hex(PGU_JOURNAL_RANDOM_BYTES))
        identity: tuple[int, int] | None = None
        try:
            flags = os.O_RDWR | os.O_CREAT | os.O_EXCL | getattr(os, 'O_BINARY', 0)
            flags |= getattr(os, 'O_NOFOLLOW', 0)
            descriptor = os.open(stage, flags, 0o600)
            try:
                info = os.fstat(descriptor)
                identity = _identity(info)
                if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                    raise PguJournalError('Upload journal stage is unsafe.')
                offset = 0
                while offset < len(payload):
                    written = os.write(descriptor, payload[offset:])
                    if written <= 0:
                        raise PguJournalError('Upload journal write is incomplete.')
                    offset += written
                os.fsync(descriptor)
                os.lseek(descriptor, 0, os.SEEK_SET)
                after = os.fstat(descriptor)
                if (after.st_nlink != 1 or _identity(after) != identity
                        or os.read(descriptor, len(payload) + 1) != payload):
                    raise PguJournalError('Upload journal stage changed.')
            finally:
                os.close(descriptor)
            return stage, identity
        except (OSError, ValueError, PguJournalError):
            if identity is not None:
                self._remove_owned(stage, identity)
            raise PguJournalError('Upload journal could not be persisted.') from None

    def _remove_owned(self, path: Path, identity: tuple[int, int]) -> bool:
        """Remove only the identified regular file at this exact path.

        Args:
            path (Path): Journal-owned candidate file.
            identity (tuple[int, int]): Previously observed device/inode pair.

        Returns:
            bool: True when the identified file was removed, otherwise False.
        """
        try:
            current = path.lstat()
            if not stat.S_ISREG(current.st_mode) or _identity(current) != identity:
                return False
            path.unlink()
            return True
        except (OSError, ValueError, PguJournalError):
            return False

    def _publish(self, path: Path, record: PguUploadRecord,
                 prior: tuple[PguUploadRecord, tuple[int, int]] | None) -> None:
        """Publish a full record exclusively or replace the exact checked slot.

        Args:
            path (Path): Owned target slot or immutable accepted-record path.
            record (PguUploadRecord): Complete record to persist.
            prior (tuple[PguUploadRecord, tuple[int, int]] | None): Expected
                existing slot identity, or None for exclusive publication.

        Returns:
            None: The exact new record was read back at the intended path.

        Raises:
            PguJournalError: Publication is unavailable or uncertain.
        """
        stage, identity = self._stage(_record_bytes(record))
        try:
            current = stage.lstat()
            if (not stat.S_ISREG(current.st_mode) or current.st_nlink != 1
                    or _identity(current) != identity):
                raise PguJournalError('Upload journal stage changed.')
            if prior is None:
                os.link(stage, path)
                if not self._remove_owned(stage, identity):
                    raise PguJournalError('Upload journal stage cleanup failed.')
            else:
                if self._read(path) != prior:
                    raise PguJournalError('Upload journal changed.')
                os.replace(stage, path)
            saved = self._read(path)
            if saved is None or saved[0] != record or saved[1] != identity:
                raise PguJournalError('Upload journal publication is uncertain.')
        except (OSError, ValueError, PguJournalError):
            raise PguJournalError('Upload journal publication is uncertain.') from None
        finally:
            self._remove_owned(stage, identity)

    def _acquire(self) -> tuple[int, tuple[int, int], bytes,
                                tuple[tuple[int, int], tuple[int, int]]]:
        """Create and verify one exclusive cooperative target lease.

        Returns:
            tuple[int, tuple[int, int], bytes, tuple[tuple[int, int], tuple[int, int]]]:
                Open lease descriptor, identity, private owner and parent IDs.

        Raises:
            PguJournalError: The lease exists, is unsafe or cannot be created.
        """
        parents = self._prepare_directory()
        owner = secrets.token_hex(PGU_JOURNAL_RANDOM_BYTES).encode('ascii')
        descriptor: int | None = None
        identity: tuple[int, int] | None = None
        try:
            flags = os.O_RDWR | os.O_CREAT | os.O_EXCL | getattr(os, 'O_BINARY', 0)
            flags |= getattr(os, 'O_NOFOLLOW', 0)
            descriptor = os.open(self._lock, flags, 0o600)
            info = os.fstat(descriptor)
            identity = _identity(info)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                raise PguJournalError('Upload journal lease is unsafe.')
            if os.write(descriptor, owner) != len(owner):
                raise PguJournalError('Upload journal lease is incomplete.')
            os.fsync(descriptor)
            self._check_directory(parents)
            current = self._lock.lstat()
            if current.st_nlink != 1 or _identity(current) != identity:
                raise PguJournalError('Upload journal lease changed.')
            return descriptor, identity, owner, parents
        except (OSError, ValueError, PguJournalError):
            if descriptor is not None:
                os.close(descriptor)
            if identity is not None:
                self._remove_owned(self._lock, identity)
            raise PguJournalError('Upload journal is busy or unavailable.') from None

    def _content_index(self, sha256: str, size: int) -> Path:
        """Select the bounded exact-content index without scanning history.

        Args:
            sha256 (str): Validated lowercase source-content digest.
            size (int): Validated source length in bytes.

        Returns:
            Path: Deterministic target-bound index path, without source details.
        """
        token = hashlib.sha256(
            ('pgu-content-v1\0' + self._binding + '\0' + sha256 + '\0' + str(size))
            .encode('ascii'),
        ).hexdigest()
        return self._directory / (token + '.content.json')

    def begin(self, sha256: str, size: int) -> 'PguUploadAttempt':
        """Persist one pending upload before permitting a single HTTP attempt.

        Args:
            sha256 (str): Lowercase SHA-256 of the captured source content.
            size (int): Captured source length in bytes.

        Returns:
            PguUploadAttempt: Exclusive lease containing the persisted intent.

        Raises:
            PguJournalError: Existing uncertainty, contention or persistence
                failure prevents the request; a pending slot is retained.
        """
        return self._begin(sha256, size, allow_repeat=False)

    def begin_explicit_repeat(self, sha256: str, size: int) -> 'PguUploadAttempt':
        """Admit a separately reviewed, explicit repeat of prior content.

        This operation must be connected only to an explicit user decision,
        never an automatic retry or ordinary worker fallback.

        Args:
            sha256 (str): Lowercase SHA-256 of the captured source content.
            size (int): Captured source length in bytes.

        Returns:
            PguUploadAttempt: Exclusive lease holding the new pending intent.

        Raises:
            PguJournalError: A pending or unverifiable attempt still blocks it.
        """
        return self._begin(sha256, size, allow_repeat=True)

    def _begin(self, sha256: str, size: int, *, allow_repeat: bool) -> 'PguUploadAttempt':
        """Publish one validated intent after checking the exact history key.

        Args:
            sha256 (str): Lowercase SHA-256 of the captured source content.
            size (int): Captured source length in bytes.
            allow_repeat (bool): True only for the explicit repeat entrypoint.

        Returns:
            PguUploadAttempt: Exclusive lease holding the new pending intent.

        Raises:
            PguJournalError: The intent, target history or lease is unavailable.
        """
        if (not isinstance(sha256, str) or re.fullmatch(r'[0-9a-f]{64}', sha256) is None
                or type(size) is not int or not 0 < size <= PGU_JOURNAL_MAX_FILE_BYTES):
            raise PguJournalError('Upload intent is invalid.')
        acquired = self._acquire()
        attempt = PguUploadAttempt(self, acquired)
        try:
            attempt._check_lease()
            previous = self._read(self._slot)
            witness = self._read(self._witness)
            if (previous is None) != (witness is None):
                raise PguJournalError('Upload journal is incomplete and requires review.')
            if previous is not None and previous[0].state == 'pending':
                raise PguJournalError('An earlier upload requires review.')
            if previous is not None:
                archive = self._directory / (
                    self._binding + '.' + previous[0].nonce + '.'
                    + previous[0].state + '.json'
                )
                archived = self._read(archive)
                if archived is None:
                    self._publish(archive, previous[0], None)
                elif archived[0] != previous[0]:
                    raise PguJournalError('Upload acknowledgement changed.')
                index_path = self._content_index(previous[0].sha256, previous[0].size)
                indexed = self._read(index_path)
                if indexed is None:
                    self._publish(index_path, previous[0], None)
                elif (indexed[0].state not in ('accepted', 'reviewed')
                      or indexed[0].sha256 != previous[0].sha256
                      or indexed[0].size != previous[0].size):
                    raise PguJournalError('Upload acknowledgement index changed.')
            indexed = self._read(self._content_index(sha256, size))
            if indexed is not None:
                if (indexed[0].state not in ('accepted', 'reviewed') or indexed[0].sha256 != sha256
                        or indexed[0].size != size):
                    raise PguJournalError('Upload acknowledgement index changed.')
                if not allow_repeat:
                    raise PguJournalError('A prior acknowledgement requires review.')
            record = PguUploadRecord(self._binding, sha256, size,
                                     secrets.token_hex(PGU_JOURNAL_RANDOM_BYTES), 'pending')
            attempt._check_lease()
            if witness is None:
                # This independent retained witness makes loss of just the
                # current slot fail closed. Loss of both cannot be distinguished
                # from a first installation without another durable authority.
                self._publish(self._witness, record, None)
            self._publish(self._slot, record, previous)
            if self._read(self._witness) is None:
                raise PguJournalError('Upload journal witness is unavailable.')
            attempt._record = record
            attempt._check_lease()
            return attempt
        except BaseException:
            attempt.close()
            raise

    def inspect(self) -> PguUploadRecord | None:
        """Inspect the current slot under an exclusive target lease.

        Returns:
            PguUploadRecord | None: Pending, accepted or reviewed record, or no slot.

        Raises:
            PguJournalError: Busy, unknown or corrupt state cannot be observed.
        """
        attempt = PguUploadAttempt(self, self._acquire())
        try:
            attempt._check_lease()
            result = self._read(self._slot)
            witness = self._read(self._witness)
            if (result is None) != (witness is None):
                raise PguJournalError('Upload journal is incomplete and requires review.')
            attempt._check_lease()
            return result[0] if result is not None else None
        finally:
            attempt.close()

    def resolve_pending(self, expected: PguUploadRecord) -> PguUploadRecord:
        """Record an explicit decision about one exact uncertain attempt.

        This does not claim that the server stored a file and never sends or
        retries a request. The caller must obtain the user's explicit review.

        Args:
            expected (PguUploadRecord): Exact pending record previously shown
                for the same target and user review.

        Returns:
            PguUploadRecord: Retained reviewed record with its original nonce.

        Raises:
            PguJournalError: The target, original intent or lease is stale or
                the recorded decision cannot be verified.
        """
        if (type(expected) is not PguUploadRecord or expected.binding != self._binding
                or expected.state != 'pending'):
            raise PguJournalError('Upload review is unavailable.')
        attempt = PguUploadAttempt(self, self._acquire())
        try:
            attempt._check_lease()
            if self._read(self._witness) is None:
                raise PguJournalError('Upload journal witness is unavailable.')
            prior = self._read(self._slot)
            if prior is None or prior[0] != expected:
                raise PguJournalError('Upload intent changed.')
            reviewed = PguUploadRecord(expected.binding, expected.sha256,
                                       expected.size, expected.nonce, 'reviewed')
            attempt._uncertain = True
            self._publish(self._slot, reviewed, prior)
            attempt._check_lease()
            if self._read(self._witness) is None:
                raise PguJournalError('Upload journal witness is unavailable.')
            attempt._uncertain = False
            return reviewed
        finally:
            attempt.close()


class PguUploadAttempt:
    """Hold one target lease from durable intent through the HTTP outcome."""

    def __init__(self, journal: PguUploadJournal,
                 acquired: tuple[int, tuple[int, int], bytes,
                                 tuple[tuple[int, int], tuple[int, int]]]) -> None:
        """Retain a journal and its exclusively created lease.

        Args:
            journal (PguUploadJournal): Target-bound storage owner.
            acquired (tuple[int, tuple[int, int], bytes, tuple[tuple[int, int], tuple[int, int]]]):
                Lease descriptor, physical identity, owner bytes and parent IDs.

        Returns:
            None: Creates an active handle; no network work is performed.
        """
        self._journal = journal
        self._descriptor, self._identity, self._owner, self._parents = acquired
        self._record: PguUploadRecord | None = None
        self._uncertain = False
        self._mutex = threading.RLock()

    def _check_lease(self) -> None:
        """Require the same lease descriptor, path, owner and direct parents.

        Returns:
            None: The original cooperative lease remains observable.

        Raises:
            PguJournalError: The lease is closed, replaced or unavailable.
        """
        if self._descriptor is None:
            raise PguJournalError('Upload journal lease is unavailable.')
        try:
            self._journal._check_directory(self._parents)
            opened, current = os.fstat(self._descriptor), self._journal._lock.lstat()
            os.lseek(self._descriptor, 0, os.SEEK_SET)
            owner = os.read(self._descriptor, len(self._owner) + 1)
            if (not stat.S_ISREG(opened.st_mode) or not stat.S_ISREG(current.st_mode)
                    or opened.st_nlink != 1 or current.st_nlink != 1
                    or _identity(opened) != self._identity
                    or _identity(current) != self._identity or owner != self._owner):
                raise PguJournalError('Upload journal lease changed.')
        except (OSError, ValueError):
            raise PguJournalError('Upload journal lease is unavailable.') from None

    def accept(self, response: dict[str, JsonValue]) -> None:
        """Record one exact server acknowledgement without asserting file existence.

        Args:
            response (dict[str, JsonValue]): Parsed legacy response; ``ok``
                must be true and ``uploaded`` must be the integer one.

        Returns:
            None: The pending slot was atomically replaced by its accepted state.

        Raises:
            PguJournalError: The acknowledgement, lease or publication is
                uncertain. Inspect the retained slot before any further work.
        """
        with self._mutex:
            if (not isinstance(response, dict) or response.get('ok') is not True
                    or type(response.get('uploaded')) is not int or response['uploaded'] != 1):
                raise PguJournalError('Upload acknowledgement is unavailable.')
            if self._record is None or self._record.state != 'pending':
                raise PguJournalError('Upload intent is unavailable.')
            # An error after replace can leave either slot state. A retained
            # lease blocks all later workers until an operator inspects it.
            self._uncertain = True
            self._check_lease()
            if self._journal._read(self._journal._witness) is None:
                raise PguJournalError('Upload journal witness is unavailable.')
            prior = self._journal._read(self._journal._slot)
            if prior is None or prior[0] != self._record:
                raise PguJournalError('Upload intent changed.')
            accepted = PguUploadRecord(self._record.binding, self._record.sha256,
                                       self._record.size, self._record.nonce, 'accepted')
            self._journal._publish(self._journal._slot, accepted, prior)
            self._check_lease()
            if self._journal._read(self._journal._witness) is None:
                raise PguJournalError('Upload journal witness is unavailable.')
            self._record = accepted
            self._uncertain = False

    def close(self) -> None:
        """Release only the original observed lease; retain all journal records.

        Returns:
            None: Leaves an uncertain or replaced lease in place for review.
        """
        with self._mutex:
            descriptor = self._descriptor
            if descriptor is None:
                return
            checked = False
            try:
                if not self._uncertain:
                    self._check_lease()
                    checked = True
            except (OSError, PguJournalError):
                pass
            finally:
                self._descriptor = None
                os.close(descriptor)
            if not checked:
                return
            try:
                # Windows may refuse to unlink a file while its owner has it
                # open. Close first, then re-open and verify the exact owner.
                self._journal._check_directory(self._parents)
                flags = os.O_RDONLY | getattr(os, 'O_BINARY', 0)
                flags |= getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0)
                check = os.open(self._journal._lock, flags)
                try:
                    observed = os.fstat(check)
                    owner = os.read(check, len(self._owner) + 1)
                finally:
                    os.close(check)
                current = self._journal._lock.lstat()
                if (stat.S_ISREG(observed.st_mode) and stat.S_ISREG(current.st_mode)
                        and observed.st_nlink == 1 and current.st_nlink == 1
                        and _identity(observed) == self._identity
                        and _identity(current) == self._identity and owner == self._owner):
                    self._journal._remove_owned(self._journal._lock, self._identity)
            except (OSError, ValueError, PguJournalError):
                pass
