# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/macos_mode.py
# Module Type: macOS upload mode storage
# Purpose: Keep verified upload-mode metadata for local macOS profiles.
# Responsibilities:
#   - Admit owner-controlled directories on local volumes.
#   - Serialize and verify the second copy of the non-secret mode record.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Store mode metadata separately from the macOS profile configuration."""

from collections.abc import Iterator
from contextlib import contextmanager
import ctypes
import json
import os
from pathlib import Path
import stat
import sys

from .config_snapshot import ConfigSnapshot, ConfigSnapshotStore
from .flight_jobs import WorkspaceLease
from .upload_mode import ModeRecord, decode_mode_record, encode_mode_record


# Darwin sys/mount.h ABI fields, including the buffer sizes used by fstatfs.
_MNT_LOCAL = 0x00001000
_FS_TYPE_NAME_BYTES = 16
_MOUNT_PATH_BYTES = 1024
_FS_RESERVED_WORDS = 7
_FS_ID_WORDS = 2


class _StatFS(ctypes.Structure):
    """Represent Darwin's 64-bit inode statfs result."""

    _fields_ = [
        ('block_size', ctypes.c_uint32),
        ('io_size', ctypes.c_int32),
        ('blocks', ctypes.c_uint64),
        ('blocks_free', ctypes.c_uint64),
        ('blocks_available', ctypes.c_uint64),
        ('files', ctypes.c_uint64),
        ('files_free', ctypes.c_uint64),
        ('fs_id', ctypes.c_int32 * _FS_ID_WORDS),
        ('owner', ctypes.c_uint32),
        ('fs_type', ctypes.c_uint32),
        ('flags', ctypes.c_uint32),
        ('subtype', ctypes.c_uint32),
        ('fs_type_name', ctypes.c_char * _FS_TYPE_NAME_BYTES),
        ('mount_path', ctypes.c_char * _MOUNT_PATH_BYTES),
        ('source_path', ctypes.c_char * _MOUNT_PATH_BYTES),
        ('extended_flags', ctypes.c_uint32),
        ('reserved', ctypes.c_uint32 * _FS_RESERVED_WORDS),
    ]


def assert_local_macos_profile(directory: Path) -> tuple[int, int]:
    """Check the owner and local mount of an existing macOS directory.

    Args:
        directory (Path): Absolute profile or metadata directory.

    Returns:
        tuple[int, int]: Verified device and inode of the selected directory.

    Raises:
        OSError: The path, ownership or local filesystem cannot be verified.
    """
    try:
        if sys.platform != 'darwin' or not directory.is_absolute():
            raise OSError
        if directory.resolve(strict=True) != directory:
            raise OSError
        uid = os.geteuid()
        before = directory.lstat()
        if before.st_uid != uid or before.st_mode & (stat.S_IWGRP | stat.S_IWOTH):
            raise OSError
        for ancestor in directory.parents:
            info = ancestor.lstat()
            if (not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, uid)
                    or (info.st_mode & (stat.S_IWGRP | stat.S_IWOTH)
                        and not info.st_mode & stat.S_ISVTX)):
                raise OSError
        native = ctypes.CDLL(None)
        # Intel exposes a separate inode64 symbol. Apple silicon uses that
        # layout for its ordinary fstatfs symbol.
        fstatfs = getattr(native, 'fstatfs$INODE64', None) or native.fstatfs
        fstatfs.argtypes = (ctypes.c_int, ctypes.POINTER(_StatFS))
        fstatfs.restype = ctypes.c_int
        descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            opened = os.fstat(descriptor)
            mounted = _StatFS()
            if fstatfs(descriptor, ctypes.byref(mounted)) != 0:
                raise OSError
            after = directory.lstat()
            identity = (opened.st_dev, opened.st_ino)
            if (not mounted.flags & _MNT_LOCAL or not stat.S_ISDIR(after.st_mode)
                    or (before.st_dev, before.st_ino) != identity
                    or (after.st_dev, after.st_ino) != identity
                    or (after.st_uid, after.st_mode) != (before.st_uid, before.st_mode)):
                raise OSError
            return identity
        finally:
            os.close(descriptor)
    except (OSError, ValueError, RuntimeError, AttributeError, ctypes.ArgumentError):
        raise OSError('The macOS upload profile must be on a local drive.') from None


class MacUploadModeStore:
    """Own the second mode record for one local macOS source profile."""

    def __init__(self, profile: Path) -> None:
        """Select a private metadata directory in the existing profile.

        Args:
            profile (Path): Per-user uploader workspace.

        Returns:
            None: Defers filesystem access until a mode transaction starts.
        """
        self._profile = profile
        self._directory = profile / 'upload-mode'
        self._store = ConfigSnapshotStore(
            self._directory / 'mode.json', frozenset({'record'}),
        )

    @contextmanager
    def transaction(self) -> Iterator['_MacUploadModeTransaction']:
        """Hold an exclusive local lease across mode reads and writes.

        Returns:
            Iterator[_MacUploadModeTransaction]: Entered metadata owner.

        Raises:
            OSError: The profile or its private metadata directory is unsafe.
            RuntimeError: Another process owns the metadata workspace.
        """
        assert_local_macos_profile(self._profile)
        self._directory.mkdir(mode=stat.S_IRWXU, exist_ok=True)
        identity = assert_local_macos_profile(self._directory)
        if self._directory.lstat().st_mode & (stat.S_IRWXG | stat.S_IRWXO):
            raise OSError('The macOS upload mode directory must be private.')
        lease = WorkspaceLease(self._directory)
        transaction = _MacUploadModeTransaction(self._store, identity, lease)
        try:
            yield transaction
        finally:
            transaction.close()
            lease.release()


class _MacUploadModeTransaction:
    """Compare and publish one non-secret record while its lease is held."""

    def __init__(
        self, store: ConfigSnapshotStore, identity: tuple[int, int],
        lease: WorkspaceLease,
    ) -> None:
        """Bind the record to the verified metadata directory.

        Args:
            store (ConfigSnapshotStore): Snapshot owner for the mode JSON file.
            identity (tuple[int, int]): Device and inode of its private directory.
            lease (WorkspaceLease): Held lock for that same directory.

        Returns:
            None: Creates an entered transaction with no observed snapshot.
        """
        self._store = store
        self._identity = identity
        self._lease = lease
        self._snapshot: ConfigSnapshot | None = None
        self._active = True

    def _check_owner(self) -> None:
        """Recheck directory identity and privacy before trusting evidence.

        Returns:
            None: The transaction still owns the same directory.

        Raises:
            OSError: The lease ended or the directory changed.
            ValueError: The directory or lock no longer matches the lease.
        """
        parent = self._store.path.parent
        if not self._active:
            raise OSError('Upload mode transaction is closed.')
        self._lease.verify()
        info = parent.lstat()
        if ((info.st_dev, info.st_ino) != self._identity
                or not stat.S_ISDIR(info.st_mode)
                or info.st_uid != os.geteuid()
                or info.st_mode & (stat.S_IRWXG | stat.S_IRWXO)):
            raise OSError('Upload mode storage changed.')

    def read(self) -> ModeRecord | None:
        """Observe the exact record without interpreting corruption as absence.

        Returns:
            ModeRecord | None: Validated mode metadata or confirmed absence.

        Raises:
            OSError: The mode file or its directory cannot be verified.
            ValueError: The stored record is malformed.
        """
        self._check_owner()
        snapshot = self._store.inspect()
        self._check_owner()
        if snapshot.state == 'missing':
            self._snapshot = snapshot
            return None
        document = self._store.read(snapshot)
        if set(document) != {'record'}:
            raise ValueError('Upload mode record is invalid.')
        record = decode_mode_record(json.dumps(
            document['record'], ensure_ascii=True, separators=(',', ':'),
        ).encode('ascii'))
        self._snapshot = snapshot
        return record

    def write(self, record: ModeRecord) -> None:
        """Publish and read back a record against the last observed snapshot.

        Args:
            record (ModeRecord): Exact validated mode generation to publish.

        Returns:
            None: The same generation was read back from the mode file.

        Raises:
            OSError: The snapshot, save or readback is unconfirmed.
            ValueError: The proposed record is invalid.
        """
        self._check_owner()
        if self._snapshot is None:
            raise OSError('Upload mode evidence was not observed.')
        self._store.save(
            self._snapshot, {'record': json.loads(encode_mode_record(record))},
        )
        if self.read() != record:
            raise OSError('Upload mode write could not be verified.')

    def close(self) -> None:
        """Invalidate the transaction before its workspace lease is released.

        Returns:
            None: Refuses any later read or write through this transaction.
        """
        self._active = False
        self._snapshot = None
