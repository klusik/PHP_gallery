# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linked_create.py
# Module Type: Client Gallery Creation Journal
# Purpose: Persist a non-secret create intent before a single remote mutation.
# Responsibilities:
#   - Serialize all profile creation attempts using the existing local lease.
#   - Retain uncertain requests for status, same-key resume or explicit closure.
#   - Bind reconciliation to the verified issuer and Admin account.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Fail-closed local gallery creation intent; no tokens are persisted."""

import hashlib
import hmac
import json
import os
import re
import secrets
import stat
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Literal

from .app_linking import (
    LINKING_CREATE_TITLE_MAX_BYTES, LINKING_GALLERY_ID_MAX, LinkedGallery,
    LinkingError, LinkingSession, _issuer_valid,
)
from .linked_upload import LinkedTargetLease
from .server_discovery import _unique_object
from .state_store import linked_upload_state_path

# Purpose: Protect one unresolved create across all issuers of a user profile.
# Type: str. Units: lowercase SHA-256 hex. Scope: one uploader profile.
# Consumers: GalleryCreateJournal. Rationale: a fixed domain-separated binding
# prevents changing issuer or account from bypassing an unresolved operation.
_CREATE_LEASE_BINDING = hashlib.sha256(b'php-gallery-create-journal-v1').hexdigest()

# Purpose: Bound local untrusted pending-intent parsing.
# Type: int. Units: bytes. Scope: one non-secret JSON journal file.
# Consumers: GalleryCreateJournal._read. Rationale: 4096 accommodates the issuer,
# title and operation key while bounding memory on malformed or replaced files.
_CREATE_JOURNAL_MAX_BYTES = 4096

# Purpose: Generate an unpredictable globally unique server operation key.
# Type: int. Units: random bytes. Scope: each explicit creation attempt.
# Consumers: GalleryCreateJournal.create. Rationale: 32 bytes provide 256 bits.
_CREATE_KEY_RANDOM_BYTES = 32

# Purpose: Keep concurrent pending-intent staging names distinct.
# Type: int. Units: random bytes. Scope: one pending creation staging file.
# Consumers: GalleryCreateJournal._publish. Rationale: 16 random bytes make
# accidental collisions negligible without replacing another staging file.
_INTENT_TEMP_RANDOM_BYTES = 16

# Purpose: Make receipt staging names unique across concurrent client processes.
# Type: int. Units: random bytes. Scope: one completed receipt staging file.
# Consumers: GalleryCreateJournal._publish_receipt. Rationale: 16 random bytes
# make accidental filename collisions negligible without replacing prior files.
_RECEIPT_TEMP_RANDOM_BYTES = 16


class CreateDispatch:
    """Coordinate dialog cancellation with admission of one create request."""

    def __init__(self) -> None:
        """Create an unlocked cancellation gate.

        Returns:
            None: The request is initially eligible for admission.
        """
        self._lock = threading.Lock()
        self.cancelled = threading.Event()

    def cancel(self) -> None:
        """Prevent admission if a create request has not already been admitted.

        Returns:
            None: Later admission observes the cancellation.
        """
        with self._lock:
            self.cancelled.set()

    def admit(self) -> bool:
        """Choose whether creation may begin before concurrent cancellation.

        Returns:
            bool: True if admitted; false if cancellation happened first.
        """
        with self._lock:
            return not self.cancelled.is_set()


@dataclass(frozen=True)
class CreateIntent:
    """Bind one non-secret pending operation to its original verified account.

    Args:
        issuer (str): Exact previously validated HTTPS installation issuer.
        account_id (int): Server-verified positive Admin account identifier.
        operation_key (str): Exact 64-character lowercase operation key.
        title (str): Original gallery title sent to the server.
        parent_id (int | None): Optional positive parent gallery identifier.
    """

    issuer: str
    account_id: int
    operation_key: str
    title: str
    parent_id: int | None


@dataclass(frozen=True)
class BoundCreateReceipt:
    """Bind a completed gallery to the exact locally confirmed operation.

    Args:
        gallery (LinkedGallery): Server-confirmed gallery identity and title.
        intent_fingerprint (str): Domain-separated digest of the original
            issuer, account, operation key, title and parent.
    """

    gallery: LinkedGallery
    intent_fingerprint: str


@dataclass(frozen=True)
class CreateJournalSnapshot:
    """Describe a persisted create state without exposing its operation key.

    Attributes:
        state (Literal['empty', 'pending', 'completed']): Validated journal
            state observed while the profile lease was held.
        issuer (str | None): Original canonical issuer for a pending/completed
            operation, or None when the journal is empty.
        account_id (int | None): Original verified Admin identity, or None when
            the journal is empty.
        title (str | None): Original gallery title, or None when empty.
        parent_id (int | None): Original parent, or None for empty or root.
        intent_fingerprint (str | None): Digest binding the exact saved intent
            without exposing its operation key.
        receipt (BoundCreateReceipt | None): Non-secret completed gallery proof;
            present only for a completed state.
    """

    state: Literal['empty', 'pending', 'completed']
    issuer: str | None
    account_id: int | None
    title: str | None
    parent_id: int | None
    intent_fingerprint: str | None
    receipt: BoundCreateReceipt | None


def _decode_intent(data: object) -> CreateIntent:
    """Validate non-secret persisted creation inputs before using them.

    Args:
        data (uploader.models.JsonValue | object): Untrusted decoded value; a
            valid object has string issuer, operation_key and title fields,
            an integer account_id, and an integer or null parent_id.

    Returns:
        CreateIntent: The exact valid original request binding and inputs.

    Raises:
        ValueError: The object, identity or creation fields are invalid.
    """
    if not isinstance(data, dict) or set(data) != {
        'issuer', 'account_id', 'operation_key', 'title', 'parent_id',
    }:
        raise ValueError('Invalid journal fields.')
    issuer, account = data['issuer'], data['account_id']
    key, title, parent = data['operation_key'], data['title'], data['parent_id']
    if (not isinstance(issuer, str) or not _issuer_valid(issuer)
            or type(account) is not int or not 0 < account <= LINKING_GALLERY_ID_MAX
            or not isinstance(key, str) or re.fullmatch(r'[0-9a-f]{64}', key) is None
            or not isinstance(title, str) or not title.strip(' \t\n\r\x00\x0b')
            or len(title.encode('utf-8')) > LINKING_CREATE_TITLE_MAX_BYTES
            or any(ord(c) < 32 or ord(c) == 127 for c in title)
            or (parent is not None and (type(parent) is not int
                or not 0 < parent <= LINKING_GALLERY_ID_MAX))):
        raise ValueError('Invalid journal contents.')
    return CreateIntent(issuer, account, key, title, parent)


def _intent_fields(intent: CreateIntent) -> dict[str, str | int | None]:
    """Return the exact non-secret request fields for persistence.

    Args:
        intent (CreateIntent): Validated issuer, account, key, title and parent.

    Returns:
        dict[str, str | int | None]: Issuer, account_id, operation_key, title
            and parent_id values for the versionless intent document.
    """
    return {'issuer': intent.issuer, 'account_id': intent.account_id,
            'operation_key': intent.operation_key, 'title': intent.title,
            'parent_id': intent.parent_id}


def _bind_receipt(intent: CreateIntent, gallery: LinkedGallery) -> BoundCreateReceipt:
    """Identify a completed gallery and its exact original creation intent.

    Args:
        intent (CreateIntent): Validated original request and account binding.
        gallery (LinkedGallery): Current validated server receipt.

    Returns:
        BoundCreateReceipt: Gallery and deterministic non-secret intent digest.
    """
    return BoundCreateReceipt(gallery, _intent_fingerprint(intent))


def _intent_fingerprint(intent: CreateIntent) -> str:
    """Bind the exact persisted intent without exposing its operation key.

    Args:
        intent (CreateIntent): Validated original issuer, account and inputs.

    Returns:
        str: Stable lowercase SHA-256 digest of the complete intent fields.
    """
    encoded = json.dumps(
        _intent_fields(intent), ensure_ascii=True, sort_keys=True,
        separators=(',', ':'),
    ).encode('ascii')
    digest = hashlib.sha256(b'php-gallery-create-intent-binding-v1\x00' + encoded)
    return digest.hexdigest()


class GalleryCreateJournal:
    """Serialize profile-wide creation and retain any unconfirmed intent."""

    def __init__(self, config_dir: Path) -> None:
        """Select the uploader profile without opening the file or network.

        Args:
            config_dir (Path): Current user's existing uploader profile directory.

        Returns:
            None: Saves only the profile path for subsequent explicit actions.
        """
        self._config_dir = config_dir

    def _path(self) -> Path:
        """Resolve the journal under the existing validated local state owner.

        Returns:
            Path: Validated profile-scoped path for the pending creation.

        Raises:
            ValueError: A state directory or target was redirected.
        """
        return linked_upload_state_path(self._config_dir, _CREATE_LEASE_BINDING)

    def _receipt_path(self) -> Path:
        """Resolve the separate completion record without replacing the intent.

        Returns:
            Path: Profile-scoped completion path alongside the pending file.

        Raises:
            ValueError: The base state path is redirected or malformed.
        """
        return self._path().with_suffix('.receipt.json')

    def _read_receipt(
        self,
    ) -> tuple[CreateIntent, LinkedGallery, os.stat_result] | None:
        """Load the durable completed result and its original operation.

        Returns:
            tuple[CreateIntent, LinkedGallery, os.stat_result] | None: Valid
                intent, matching gallery and file identity, or None if absent.

        Raises:
            LinkingError: An existing receipt cannot be safely verified.
        """
        path = self._receipt_path()
        try:
            descriptor = os.open(path, os.O_RDONLY | getattr(os, 'O_BINARY', 0)
                                 | getattr(os, 'O_NOFOLLOW', 0))
        except FileNotFoundError:
            return None
        except OSError:
            raise LinkingError('Gallery creation receipt cannot be read safely.') from None
        try:
            info = os.fstat(descriptor)
            if not stat.S_ISREG(info.st_mode):
                raise ValueError('Invalid receipt type.')
            raw = os.read(descriptor, _CREATE_JOURNAL_MAX_BYTES + 1)
            if len(raw) > _CREATE_JOURNAL_MAX_BYTES:
                raise ValueError('Receipt too large.')
            data = json.loads(raw.decode('utf-8'), object_pairs_hook=_unique_object)
            if not isinstance(data, dict) or set(data) != {'intent', 'gallery'}:
                raise ValueError('Invalid receipt fields.')
            intent = _decode_intent(data['intent'])
            row = data['gallery']
            if not isinstance(row, dict) or set(row) != {'id', 'parent_id', 'title'}:
                raise ValueError('Invalid receipt gallery.')
            gallery_id, parent, title = row['id'], row['parent_id'], row['title']
            if (type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                    or parent != intent.parent_id
                    or (parent is not None and type(parent) is not int)
                    or title != intent.title.strip(' \t\n\r\x00\x0b')):
                raise ValueError('Receipt does not match the original intent.')
            return intent, LinkedGallery(gallery_id, parent, title), info
        except (OSError, UnicodeError, ValueError, TypeError):
            raise LinkingError('Gallery creation receipt cannot be verified.') from None
        finally:
            os.close(descriptor)

    def _publish_receipt(self, intent: CreateIntent, gallery: LinkedGallery) -> None:
        """Persist a completed receipt without modifying the pending intent.

        Args:
            intent (CreateIntent): Original issuer, account and operation inputs.
            gallery (LinkedGallery): Validated completed server result.

        Returns:
            None: A separate completed record was flushed and verified.

        Raises:
            LinkingError: Persistence failed or a previous record already exists.
        """
        path = self._receipt_path()
        payload = json.dumps({
            'intent': _intent_fields(intent),
            'gallery': {'id': gallery.gallery_id, 'parent_id': gallery.parent_id,
                        'title': gallery.title},
        }, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
        if len(payload) > _CREATE_JOURNAL_MAX_BYTES:
            raise LinkingError('Gallery creation receipt is too large.')
        temporary = path.with_name(
            path.name + '.' + secrets.token_hex(_RECEIPT_TEMP_RANDOM_BYTES) + '.tmp',
        )
        try:
            descriptor = os.open(temporary, os.O_RDWR | os.O_CREAT | os.O_EXCL
                                 | getattr(os, 'O_BINARY', 0)
                                 | getattr(os, 'O_NOFOLLOW', 0), 0o600)
        except OSError:
            raise LinkingError('Gallery creation receipt cannot be saved safely.') from None
        owned: os.stat_result | None = None
        try:
            try:
                owned = os.fstat(descriptor)
                if (not stat.S_ISREG(owned.st_mode) or owned.st_ino <= 0
                        or owned.st_nlink != 1):
                    raise OSError('Unsafe receipt staging identity.')
                offset = 0
                while offset < len(payload):
                    count = os.write(descriptor, payload[offset:])
                    if count <= 0:
                        raise OSError('Incomplete receipt write.')
                    offset += count
                os.fsync(descriptor)
                os.lseek(descriptor, 0, os.SEEK_SET)
                staged = os.fstat(descriptor)
                if (not stat.S_ISREG(staged.st_mode) or staged.st_nlink != 1
                        or (staged.st_dev, staged.st_ino) != (owned.st_dev, owned.st_ino)
                        or os.read(descriptor, len(payload) + 1) != payload):
                    raise OSError('Receipt verification failed.')
            finally:
                os.close(descriptor)
            current = temporary.lstat()
            if (not stat.S_ISREG(current.st_mode) or current.st_nlink != 1
                    or (current.st_dev, current.st_ino) != (owned.st_dev, owned.st_ino)):
                raise OSError('Receipt staging identity changed.')
            # Publish the complete receipt exclusively without replacing a
            # pending intent or a pre-existing completed result.
            os.link(temporary, path)
            saved = self._read_receipt()
            if (saved is None or saved[0] != intent or saved[1] != gallery
                    or saved[2].st_nlink != 2
                    or (saved[2].st_dev, saved[2].st_ino) != (owned.st_dev, owned.st_ino)):
                raise OSError('Published receipt identity changed.')
            if not self._remove_file(temporary, owned):
                raise OSError('Receipt staging cleanup is unavailable.')
            saved = self._read_receipt()
            if (saved is None or saved[0] != intent or saved[1] != gallery
                    or saved[2].st_nlink != 1
                    or (saved[2].st_dev, saved[2].st_ino) != (owned.st_dev, owned.st_ino)):
                raise OSError('Published receipt could not be verified.')
        except (OSError, ValueError):
            raise LinkingError('Gallery creation receipt cannot be saved safely.') from None
        finally:
            if owned is not None:
                self._remove_file(temporary, owned)

    def _read(self) -> tuple[CreateIntent, os.stat_result] | None:
        """Read a bounded valid journal while its profile lease is held.

        Returns:
            tuple[CreateIntent, os.stat_result] | None: Valid intent with its
                physical identity, or None when no pending file exists.

        Raises:
            LinkingError: Existing journal is unreadable, unsafe or invalid.
        """
        path = self._path()
        try:
            descriptor = os.open(path, os.O_RDONLY | getattr(os, 'O_BINARY', 0)
                                 | getattr(os, 'O_NOFOLLOW', 0))
        except FileNotFoundError:
            return None
        except OSError:
            raise LinkingError('Pending gallery creation cannot be read safely.') from None
        try:
            info = os.fstat(descriptor)
            if not stat.S_ISREG(info.st_mode):
                raise ValueError('Invalid journal type.')
            raw = os.read(descriptor, _CREATE_JOURNAL_MAX_BYTES + 1)
            if len(raw) > _CREATE_JOURNAL_MAX_BYTES:
                raise ValueError('Journal too large.')
            data = json.loads(raw.decode('utf-8'), object_pairs_hook=_unique_object)
            return _decode_intent(data), info
        except (OSError, UnicodeError, ValueError, TypeError):
            raise LinkingError('Pending gallery creation cannot be read safely.') from None
        finally:
            os.close(descriptor)

    def _publish(self, intent: CreateIntent) -> os.stat_result:
        """Publish a complete intent exclusively before admitting network work.

        Args:
            intent (CreateIntent): Validated request bound to issuer and account.

        Returns:
            os.stat_result: Physical identity of the verified pending file.

        Raises:
            LinkingError: Existing or unverified journal blocks the mutation.
        """
        path = self._path()
        payload = json.dumps(_intent_fields(intent), ensure_ascii=False,
                             separators=(',', ':')).encode('utf-8')
        if len(payload) > _CREATE_JOURNAL_MAX_BYTES:
            raise LinkingError('Gallery creation intent is too large.')
        temporary = path.with_name(
            path.name + '.' + secrets.token_hex(_INTENT_TEMP_RANDOM_BYTES) + '.tmp',
        )
        try:
            descriptor = os.open(temporary, os.O_RDWR | os.O_CREAT | os.O_EXCL
                                 | getattr(os, 'O_BINARY', 0)
                                 | getattr(os, 'O_NOFOLLOW', 0), 0o600)
        except OSError:
            raise LinkingError('Gallery creation intent could not be persisted.') from None
        owned: os.stat_result | None = None
        try:
            try:
                owned = os.fstat(descriptor)
                if (not stat.S_ISREG(owned.st_mode) or owned.st_ino == 0
                        or owned.st_nlink != 1):
                    raise OSError('Unsafe staging identity.')
                offset = 0
                while offset < len(payload):
                    written = os.write(descriptor, payload[offset:])
                    if written <= 0:
                        raise OSError('Incomplete journal write.')
                    offset += written
                os.fsync(descriptor)
                os.lseek(descriptor, 0, os.SEEK_SET)
                staged = os.fstat(descriptor)
                if (not stat.S_ISREG(staged.st_mode) or staged.st_nlink != 1
                        or (staged.st_dev, staged.st_ino) != (owned.st_dev, owned.st_ino)
                        or os.read(descriptor, len(payload) + 1) != payload):
                    raise OSError('Journal verification failed.')
            finally:
                os.close(descriptor)
            current = temporary.lstat()
            if (not stat.S_ISREG(current.st_mode) or current.st_nlink != 1
                    or (current.st_dev, current.st_ino) != (owned.st_dev, owned.st_ino)):
                raise OSError('Journal staging identity changed.')
            # Publish only a complete file and never overwrite a pending key.
            os.link(temporary, path)
            saved = self._read()
            if (saved is None or saved[0] != intent or saved[1].st_nlink != 2
                    or (saved[1].st_dev, saved[1].st_ino) != (owned.st_dev, owned.st_ino)):
                raise OSError('Published journal identity changed.')
            if not self._remove_file(temporary, owned):
                raise OSError('Journal staging cleanup is unavailable.')
            saved = self._read()
            if (saved is None or saved[0] != intent or saved[1].st_nlink != 1
                    or (saved[1].st_dev, saved[1].st_ino) != (owned.st_dev, owned.st_ino)):
                raise OSError('Published journal could not be verified.')
            return saved[1]
        except (OSError, ValueError):
            raise LinkingError('Gallery creation intent could not be persisted.') from None
        finally:
            if owned is not None:
                self._remove_file(temporary, owned)

    def _remove_file(self, path: Path, expected: os.stat_result) -> bool:
        """Remove only the exact owned journal file observed under the lease.

        Args:
            path (Path): Pending intent or receipt path within this profile.
            expected (os.stat_result): Identity observed when reading the marker.

        Returns:
            bool: True when removed; false if its identity or removal is uncertain.
        """
        try:
            current = path.lstat()
            if (stat.S_ISREG(current.st_mode) and expected.st_ino != 0
                    and current.st_dev == expected.st_dev
                    and current.st_ino == expected.st_ino):
                path.unlink()
                return True
        except (OSError, ValueError):
            pass
        return False

    def create(
        self, session: LinkingSession, issuer: str, account_id: int,
        title: str, parent_id: int | None, dispatch: CreateDispatch | None = None,
    ) -> BoundCreateReceipt:
        """Persist one intent then make exactly one authorized create request.

        Args:
            session (LinkingSession): Live session with explicit create scope.
            issuer (str): Exact current installation issuer.
            account_id (int): Previously server-verified Admin account ID.
            title (str): Explicit user-provided title, at most 255 UTF-8 bytes.
            parent_id (int | None): Explicit selected parent, or root.
            dispatch (CreateDispatch | None): Dialog cancellation gate, if any.

        Returns:
            BoundCreateReceipt: Completed gallery bound to its original intent.

        Raises:
            LinkingError: Pending, invalid, unsafe or unconfirmed operation.
        """
        if (not session.matches_issuer(issuer) or not session.can_create_gallery()
                or type(account_id) is not int or not 0 < account_id <= LINKING_GALLERY_ID_MAX):
            raise LinkingError('Gallery creation session is unavailable.')
        try:
            valid_title = (isinstance(title, str) and bool(title.strip(' \t\n\r\x00\x0b'))
                           and len(title.encode('utf-8')) <= LINKING_CREATE_TITLE_MAX_BYTES
                           and not any(ord(c) < 32 or ord(c) == 127 for c in title))
        except UnicodeError:
            valid_title = False
        if (not valid_title or (parent_id is not None and (type(parent_id) is not int
                or not 0 < parent_id <= LINKING_GALLERY_ID_MAX))):
            raise LinkingError('Invalid gallery creation input.')
        lease = LinkedTargetLease(self._config_dir, _CREATE_LEASE_BINDING)
        lease.acquire()
        try:
            if self._read() is not None or self._read_receipt() is not None:
                raise LinkingError('A pending gallery creation must be resolved first.')
            if session.identity().user_id != account_id:
                raise LinkingError('Reconnect to the original gallery and Admin account.')
            intent = CreateIntent(issuer, account_id,
                                  secrets.token_hex(_CREATE_KEY_RANDOM_BYTES), title, parent_id)
            identity = self._publish(intent)
            if dispatch is not None and not dispatch.admit():
                # Nothing has been sent, so this exact new marker may be removed.
                self._remove_file(self._path(), identity)
                raise LinkingError('Gallery creation was cancelled before sending.')
            if dispatch is None:
                result = session.gallery_create_request(intent.operation_key, title, parent_id)
            else:
                result = session.gallery_create_request(
                    intent.operation_key, title, parent_id, cancelled=dispatch.cancelled,
                )
            if result is None:
                raise LinkingError('Gallery creation is unconfirmed.')
            if (result.parent_id != intent.parent_id
                    or result.title != intent.title.strip(' \t\n\r\x00\x0b')):
                raise LinkingError('Gallery creation receipt does not match the intent.')
            self._publish_receipt(intent, result)
            return _bind_receipt(intent, result)
        finally:
            lease.release()

    def pending_intent(self, issuer: str, account_id: int) -> CreateIntent:
        """Read the original unresolved intent for an explicit resume prompt.

        Args:
            issuer (str): Current canonical installation issuer.
            account_id (int): Previously verified Admin account identifier.

        Returns:
            CreateIntent: Exact validated input and key for the original attempt.

        Raises:
            LinkingError: No matching unresolved intent is available safely.
        """
        lease = LinkedTargetLease(self._config_dir, _CREATE_LEASE_BINDING)
        lease.acquire()
        try:
            saved = self._read()
            if (type(account_id) is not int or saved is None
                    or self._read_receipt() is not None
                    or saved[0].issuer != issuer or saved[0].account_id != account_id):
                raise LinkingError('Original gallery creation cannot be resumed.')
            return saved[0]
        finally:
            lease.release()

    def inspect(self) -> CreateJournalSnapshot:
        """Inspect the profile journal without returning any operation key.

        Returns:
            CreateJournalSnapshot: Empty, pending or completed state and only
                the original site/account/title/parent, non-secret intent digest
                and an optional bound receipt.

        Raises:
            LinkingError: Existing journal data is malformed, inconsistent or
                cannot be read safely under the profile lease.
        """
        lease = LinkedTargetLease(self._config_dir, _CREATE_LEASE_BINDING)
        lease.acquire()
        try:
            pending = self._read()
            receipt = self._read_receipt()
            if pending is None and receipt is None:
                return CreateJournalSnapshot(
                    'empty', None, None, None, None, None, None,
                )
            if receipt is not None:
                intent, gallery, _identity = receipt
                if pending is not None and pending[0] != intent:
                    raise LinkingError(
                        'Gallery creation journal records are inconsistent.',
                    )
                return CreateJournalSnapshot(
                    'completed', intent.issuer, intent.account_id,
                    intent.title, intent.parent_id,
                    _intent_fingerprint(intent),
                    _bind_receipt(intent, gallery),
                )
            if pending is None:
                raise LinkingError('Gallery creation journal state is unavailable.')
            intent = pending[0]
            return CreateJournalSnapshot(
                'pending', intent.issuer, intent.account_id,
                intent.title, intent.parent_id, _intent_fingerprint(intent), None,
            )
        finally:
            lease.release()

    def resume(
        self, session: LinkingSession, intent: CreateIntent,
        dispatch: CreateDispatch | None = None,
    ) -> BoundCreateReceipt:
        """Send the explicitly confirmed original request using its existing key.

        Args:
            session (LinkingSession): Live session for the original installation.
            intent (CreateIntent): Original account and inputs shown for confirmation.
            dispatch (CreateDispatch | None): Dialog cancellation gate, if any.

        Returns:
            BoundCreateReceipt: Persisted gallery bound to the original intent.

        Raises:
            LinkingError: Intent, authority, dispatch or outcome is unavailable.
        """
        if (not isinstance(intent, CreateIntent)
                or not session.matches_issuer(intent.issuer)
                or not session.can_create_gallery()):
            raise LinkingError('Original gallery creation cannot be resumed.')
        if session.identity().user_id != intent.account_id:
            raise LinkingError('Reconnect to the original gallery and Admin account.')
        lease = LinkedTargetLease(self._config_dir, _CREATE_LEASE_BINDING)
        lease.acquire()
        try:
            saved = self._read()
            if (saved is None or saved[0] != intent
                    or self._read_receipt() is not None):
                raise LinkingError('Original gallery creation changed.')
            if dispatch is not None and not dispatch.admit():
                # A prior request may have been sent; never remove its intent.
                raise LinkingError('Gallery creation resume was cancelled.')
            if dispatch is None:
                result = session.gallery_create_request(
                    intent.operation_key, intent.title, intent.parent_id,
                )
            else:
                result = session.gallery_create_request(
                    intent.operation_key, intent.title, intent.parent_id,
                    cancelled=dispatch.cancelled,
                )
            if (result is None or result.parent_id != intent.parent_id
                    or result.title != intent.title.strip(' \t\n\r\x00\x0b')):
                raise LinkingError('Gallery creation remains unconfirmed.')
            self._publish_receipt(intent, result)
            return _bind_receipt(intent, result)
        finally:
            lease.release()

    def abandon(
        self, session: LinkingSession, intent: CreateIntent,
        dispatch: CreateDispatch | None = None,
    ) -> BoundCreateReceipt | None:
        """Close the exact original key only after a confirmed server tombstone.

        Args:
            session (LinkingSession): Live session for the original installation.
            intent (CreateIntent): Original account and inputs shown for confirmation.
            dispatch (CreateDispatch | None): Dialog cancellation gate, if any.

        Returns:
            BoundCreateReceipt | None: Persisted gallery bound to the original
                intent, or None after the server confirmed closure.

        Raises:
            LinkingError: Intent, identity, tombstone or local removal is uncertain.
        """
        if (not isinstance(intent, CreateIntent)
                or not session.matches_issuer(intent.issuer)
                or not session.can_create_gallery()):
            raise LinkingError('Original gallery creation cannot be closed.')
        if session.identity().user_id != intent.account_id:
            raise LinkingError('Reconnect to the original gallery and Admin account.')
        lease = LinkedTargetLease(self._config_dir, _CREATE_LEASE_BINDING)
        lease.acquire()
        try:
            saved = self._read()
            if (saved is None or saved[0] != intent
                    or self._read_receipt() is not None):
                raise LinkingError('Original gallery creation changed.')
            if dispatch is not None and not dispatch.admit():
                raise LinkingError('Gallery creation closure was cancelled.')
            result = session.gallery_create_request(
                intent.operation_key, intent.title, intent.parent_id,
                cancelled=dispatch.cancelled if dispatch is not None else None,
                abandon=True,
            )
            if result is not None:
                if (result.parent_id != intent.parent_id
                        or result.title != intent.title.strip(' \t\n\r\x00\x0b')):
                    raise LinkingError('Gallery creation receipt does not match the intent.')
                self._publish_receipt(intent, result)
                return _bind_receipt(intent, result)
            # None can only represent the exact server-confirmed tombstone.
            # Never delete on an absent, malformed, interrupted or unknown reply.
            if not self._remove_file(self._path(), saved[1]):
                raise LinkingError('Closed creation intent could not be removed safely.')
            return None
        finally:
            lease.release()

    def reconcile(
        self, session: LinkingSession, issuer: str, account_id: int,
        *, expected_intent_fingerprint: str | None = None,
    ) -> BoundCreateReceipt | None:
        """Read status once for the original account without issuing a mutation.

        Args:
            session (LinkingSession): Live session with explicit create scope.
            issuer (str): Exact current installation issuer.
            account_id (int): Previously server-verified Admin account ID.
            expected_intent_fingerprint (str | None): Optional digest from a
                prior key-free snapshot; when supplied, status may proceed only
                if the profile still contains that exact intent.

        Returns:
            BoundCreateReceipt | None: Current gallery bound to the original
                intent, or None if still unknown.

        Raises:
            LinkingError: Missing, mismatched, unsafe or unavailable intent.
        """
        if (not session.matches_issuer(issuer) or not session.can_create_gallery()
                or type(account_id) is not int
                or not 0 < account_id <= LINKING_GALLERY_ID_MAX
                or (expected_intent_fingerprint is not None
                    and (not isinstance(expected_intent_fingerprint, str)
                         or re.fullmatch(
                             r'[a-f0-9]{64}', expected_intent_fingerprint,
                         ) is None))):
            raise LinkingError('Gallery creation session is unavailable.')
        lease = LinkedTargetLease(self._config_dir, _CREATE_LEASE_BINDING)
        lease.acquire()
        try:
            saved = self._read()
            receipt = self._read_receipt()
            if saved is None and receipt is None:
                raise LinkingError('No pending gallery creation exists.')
            intent = saved[0] if saved is not None else receipt[0]
            if intent.issuer != issuer or intent.account_id != account_id:
                raise LinkingError('Reconnect to the original gallery and Admin account.')
            if (expected_intent_fingerprint is not None
                    and not hmac.compare_digest(
                        _intent_fingerprint(intent), expected_intent_fingerprint,
                    )):
                raise LinkingError('Gallery creation changed before status check.')
            if session.identity().user_id != intent.account_id:
                raise LinkingError('Reconnect to the original gallery and Admin account.')
            if receipt is not None:
                if receipt[0] != intent:
                    raise LinkingError('Gallery creation receipt does not match the intent.')
                # The receipt proves a past outcome, not current access or
                # continued existence. Ask the server again before exposing it.
                current = session.gallery_create_request(intent.operation_key)
                if current is None:
                    return None
                if current != receipt[1]:
                    raise LinkingError('Gallery creation status does not match the receipt.')
                return _bind_receipt(intent, current)
            result = session.gallery_create_request(intent.operation_key)
            if result is not None:
                if (result.parent_id != intent.parent_id
                        or result.title != intent.title.strip(' \t\n\r\x00\x0b')):
                    raise LinkingError('Gallery creation receipt does not match the intent.')
                self._publish_receipt(intent, result)
            return _bind_receipt(intent, result) if result is not None else None
        finally:
            lease.release()

    def _matching_receipt(
        self, issuer: str, account_id: int, bound: BoundCreateReceipt,
    ) -> tuple[
        tuple[CreateIntent, os.stat_result] | None,
        tuple[CreateIntent, LinkedGallery, os.stat_result],
    ]:
        """Require the exact expected receipt while the profile lease is held.

        Args:
            issuer (str): Independently selected canonical gallery issuer.
            account_id (int): Previously verified Admin account identifier.
            bound (BoundCreateReceipt): Expected gallery and intent fingerprint.

        Returns:
            tuple[tuple[CreateIntent, os.stat_result] | None, tuple[CreateIntent, LinkedGallery, os.stat_result]]:
                Optional pending record and required matching receipt.

        Raises:
            LinkingError: Identity, contents or expected intent differs.
        """
        if (type(account_id) is not int or not 0 < account_id <= LINKING_GALLERY_ID_MAX
                or not isinstance(bound, BoundCreateReceipt)
                or not isinstance(bound.intent_fingerprint, str)
                or re.fullmatch(r'[a-f0-9]{64}', bound.intent_fingerprint) is None):
            raise LinkingError('Gallery creation receipt changed.')
        pending = self._read()
        receipt = self._read_receipt()
        if (receipt is None or receipt[0].issuer != issuer
                or receipt[0].account_id != account_id
                or receipt[1] != bound.gallery
                or not hmac.compare_digest(
                    _bind_receipt(receipt[0], receipt[1]).intent_fingerprint,
                    bound.intent_fingerprint,
                )
                or (pending is not None and pending[0] != receipt[0])):
            raise LinkingError('Gallery creation receipt changed.')
        return pending, receipt

    def apply_receipt(
        self, issuer: str, account_id: int, bound: BoundCreateReceipt,
        apply: Callable[[], bool],
    ) -> bool:
        """Apply a current receipt synchronously while retaining its lease.

        The callback must not perform network I/O or modify the journal.

        Args:
            issuer (str): Independently selected canonical gallery issuer.
            account_id (int): Previously verified Admin account identifier.
            bound (BoundCreateReceipt): Exact receipt previously checked online.
            apply (Callable[[], bool]): Synchronous local target admission.

        Returns:
            bool: The callback's local admission decision.

        Raises:
            LinkingError: The lease, receipt or expected intent is unavailable.
        """
        lease = LinkedTargetLease(self._config_dir, _CREATE_LEASE_BINDING)
        lease.acquire()
        try:
            self._matching_receipt(issuer, account_id, bound)
            return apply()
        finally:
            lease.release()

    def acknowledge(
        self, issuer: str, account_id: int, bound: BoundCreateReceipt,
    ) -> None:
        """Clear a completed receipt only after an explicit user acknowledgement.

        Args:
            issuer (str): Exact selected HTTPS installation issuer.
            account_id (int): Previously verified current Admin account ID.
            bound (BoundCreateReceipt): Exact completed operation and gallery
                the user acknowledged.

        Returns:
            None: Both matching records are removed, or a refusal is raised.

        Raises:
            LinkingError: The records changed or cannot be removed safely.
        """
        lease = LinkedTargetLease(self._config_dir, _CREATE_LEASE_BINDING)
        lease.acquire()
        try:
            pending, receipt = self._matching_receipt(issuer, account_id, bound)
            # The receipt includes the original intent. If interrupted after
            # removing pending, it still blocks creation and can be recovered.
            if pending is not None and not self._remove_file(self._path(), pending[1]):
                raise LinkingError('Gallery creation intent could not be acknowledged.')
            if not self._remove_file(self._receipt_path(), receipt[2]):
                raise LinkingError('Gallery creation receipt could not be acknowledged.')
        finally:
            lease.release()
