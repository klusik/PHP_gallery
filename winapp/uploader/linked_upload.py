# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linked_upload.py
# Module Type: Client Upload Transport
# Purpose: Build bounded streamed multipart data for a selected gallery.
# Responsibilities:
#   - Bind one image, optional thumbnails and metadata to a gallery ID.
#   - Stream file contents without retaining an entire image in memory.
#   - Refuse changed, unsafe or over-limit local file inputs.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""One-shot multipart construction without credentials or network access."""

import hashlib
import mimetypes
import os
import re
import secrets
import stat
import threading
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterator

from .app_linking import (
    LINKING_GALLERY_ID_MAX, LinkingError, LinkingSession, LinkedGallery,
    LinkedGalleryPage, UPLOAD_SCOPE, scope_base,
)
from .flight_jobs import WorkspaceLease
from .models import JsonValue
from .state_store import linked_upload_state_path

# Purpose: Limit memory while reading an original or thumbnail for upload.
# Type: int. Units: bytes. Scope: one streamed multipart file chunk.
# Consumers: LinkedMultipart.__iter__. Rationale: one MiB matches the existing
# uploader's hashing chunk and avoids buffering a complete large image.
LINKED_UPLOAD_CHUNK_BYTES = 1048576

# Purpose: Bound one local file admitted to a linked upload.
# Type: int. Units: bytes. Scope: one original or generated thumbnail.
# Consumers: _file_segment. Rationale: 32 GiB matches the maximum configurable
# existing import-file limit while preventing unbounded declared lengths.
LINKED_UPLOAD_FILE_MAX_BYTES = 34359738368

# Purpose: Bound the complete one-image multipart request including thumbnails.
# Type: int. Units: bytes. Scope: one linked upload body.
# Consumers: LinkedMultipart. Rationale: 33 GiB accommodates a 32-GiB source
# plus multipart metadata and bounded derivative images.
LINKED_UPLOAD_BODY_MAX_BYTES = 35433480192

# Purpose: Bound optional client-generated derivative files per image.
# Type: int. Units: files. Scope: one linked upload request.
# Consumers: LinkedMultipart. Rationale: 64 exceeds the supported derivative
# variants while bounding local descriptors and MIME metadata.
LINKED_UPLOAD_THUMBNAILS_MAX = 64

# Purpose: Bound individual metadata values before encoding multipart fields.
# Type: int. Units: UTF-8 bytes. Scope: one linked upload form value.
# Consumers: _text_part. Rationale: 4096 accommodates metadata while avoiding
# unbounded non-file body segments.
LINKED_UPLOAD_FIELD_MAX_BYTES = 4096

# Purpose: Bound one requested thumbnail dimension before it reaches PHP.
# Type: int. Units: pixels. Scope: one linked thumbnail descriptor.
# Consumers: LinkedMultipart. Rationale: 65535 exceeds supported derivatives
# while rejecting malformed, implausible dimensions.
LINKED_UPLOAD_THUMBNAIL_MAX_PIXELS = 65535

# Purpose: Identify the exclusive creator of one local target lease.
# Type: int. Units: random bytes. Scope: one linked-upload lease owner.
# Consumers: LinkedTargetLease. Rationale: 16 bytes make accidental owner
# identity collisions negligible without placing any credential in the file.
LINKED_LEASE_OWNER_RANDOM_BYTES = 16

# Purpose: Bound the bytes read when verifying ownership before lock removal.
# Type: int. Units: bytes. Scope: one linked-upload lease file.
# Consumers: LinkedTargetLease. Rationale: 128 bytes exceeds the fixed
# owner line while refusing to consume an unbounded replaced lock file.
LINKED_LEASE_OWNER_READ_MAX_BYTES = 128

_LOCATION_FIELDS = frozenset({
    'sim_location_source', 'sim_camera_latitude',
    'sim_camera_longitude', 'sim_camera_altitude',
})


class LinkedTargetLease:
    """Fence one linked target across processes and older uploader builds."""

    def __init__(self, config_dir: Path, binding: str) -> None:
        """Select a target's private lease without acquiring it.

        Args:
            config_dir (Path): Per-user uploader configuration directory.
            binding (str): Exact non-secret SHA-256 target fingerprint.

        Returns:
            None: Defers the native lock and older-client marker to acquisition.
        """
        self._config_dir = config_dir
        self._binding = binding
        self._descriptor: int | None = None
        self._path: Path | None = None
        self._native: WorkspaceLease | None = None
        self._marker = b''

    def acquire(self) -> None:
        """Acquire the native lock before creating or resuming its v2 marker.

        Older builds recognize any existing .lock file as busy. Their v1
        markers cannot prove native ownership and require separate recovery.

        Returns:
            None: Holds an exclusive lease until release or process exit.

        Raises:
            LinkingError: The native lock is busy or the marker is unsafe.
            ValueError: The target state path is redirected or malformed.
        """
        if self._descriptor is not None:
            raise LinkingError('Linked gallery lease is already held.')
        state = linked_upload_state_path(self._config_dir, self._binding)
        state.parent.mkdir(parents=True, exist_ok=True)
        state = linked_upload_state_path(self._config_dir, self._binding)
        path = state.with_suffix('.lock')
        try:
            native = WorkspaceLease(
                state.parent, lock_name=state.with_suffix('.owner.lock').name,
            )
        except (OSError, RuntimeError) as exc:
            raise LinkingError('Linked gallery is busy or its local lock is unavailable.') from exc
        descriptor = None
        created = False
        marker = b''
        try:
            if native.handle is None:
                raise OSError('Native gallery lock is unavailable.')
            identity = os.fstat(native.handle.fileno())
            prefix = (
                f'php-gallery-linked-lease-v2:{identity.st_dev:x}:{identity.st_ino:x}:'
            ).encode('ascii')
            nonce = secrets.token_hex(LINKED_LEASE_OWNER_RANDOM_BYTES)
            marker = prefix + nonce.encode('ascii') + b'\n'
            flags = (os.O_RDWR | getattr(os, 'O_BINARY', 0)
                     | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0))
            try:
                descriptor = os.open(path, flags | os.O_CREAT | os.O_EXCL, 0o600)
            except FileExistsError:
                observed = path.lstat()
                if (not stat.S_ISREG(observed.st_mode)
                        or observed.st_nlink != 1
                        or (os.name == 'nt' and (
                            getattr(observed, 'st_file_attributes', 0)
                            & stat.FILE_ATTRIBUTE_REPARSE_POINT
                        ))):
                    raise OSError('Unsafe linked gallery lock marker.')
                descriptor = os.open(path, flags)
                marker = os.read(descriptor, LINKED_LEASE_OWNER_READ_MAX_BYTES)
                # The fixed v2 wire format ties a 16-byte nonce to the same
                # persistent native lock file. A replaced lock proves nothing.
                if (not marker.startswith(prefix)
                        or re.fullmatch(rb'[0-9a-f]{32}\n', marker[len(prefix):]) is None):
                    raise OSError('Unverified linked gallery lock marker.')
            else:
                created = True
                if os.write(descriptor, marker) != len(marker):
                    raise OSError('Incomplete linked lease write.')
                os.fsync(descriptor)
            opened, named = os.fstat(descriptor), path.lstat()
            if (not stat.S_ISREG(opened.st_mode) or not stat.S_ISREG(named.st_mode)
                    or (os.name == 'nt' and (
                        getattr(named, 'st_file_attributes', 0)
                        & stat.FILE_ATTRIBUTE_REPARSE_POINT
                    ))
                    or opened.st_nlink != 1 or named.st_nlink != 1
                    or opened.st_ino == 0 or opened.st_size != len(marker)
                    or (opened.st_dev, opened.st_ino) != (named.st_dev, named.st_ino)):
                raise OSError('Linked gallery lock identity changed.')
            native.verify()
            self._descriptor, self._path = descriptor, path
            self._native, self._marker = native, marker
        except (OSError, ValueError):
            if descriptor is not None:
                try:
                    if created:
                        self._descriptor, self._path = descriptor, path
                        self._marker = marker
                        self._release_marker(allow_partial=True)
                    else:
                        os.close(descriptor)
                except OSError:
                    pass
            native.release()
            raise LinkingError(
                'The gallery has an older or unverified lock. Close all '
                'uploader processes, review the gallery and pending sources, '
                'then archive only the matching linked-upload-state lock: '
                + self._binding + '.lock.',
            ) from None

    def release(self) -> None:
        """Release the exact compatibility marker before the native lock.

        Returns:
            None: Other versions can proceed after safe, ordinary shutdown.
        """
        native, self._native = self._native, None
        try:
            if native is not None:
                try:
                    native.verify()
                except (OSError, ValueError):
                    # A replaced native owner no longer authorizes removal.
                    self._marker = b''
            self._release_marker()
        finally:
            if native is not None:
                native.release()

    def _release_marker(self, *, allow_partial: bool = False) -> None:
        """Remove only the exact marker that this native lease held.

        Args:
            allow_partial (bool): Also remove an incomplete write from this
                acquisition, while retaining any changed file identity.

        Returns:
            None: Preserves a replaced marker for inspection.
        """
        descriptor, path = self._descriptor, self._path
        self._descriptor, self._path = None, None
        expected, self._marker = self._marker, b''
        if descriptor is None or path is None:
            return
        try:
            stored = os.fstat(descriptor)
            os.lseek(descriptor, 0, os.SEEK_SET)
            owner = os.read(descriptor, LINKED_LEASE_OWNER_READ_MAX_BYTES)
        except OSError:
            return
        finally:
            os.close(descriptor)
        matches = owner == expected or (allow_partial and expected.startswith(owner))
        if not expected or not matches or stored.st_ino == 0:
            return
        try:
            # Windows can refuse removal while our own handle is open. Close it,
            # then reopen and recheck both owner and physical identity first.
            state = linked_upload_state_path(self._config_dir, self._binding)
            if path != state.with_suffix('.lock'):
                return
            flags = (os.O_RDONLY | getattr(os, 'O_BINARY', 0)
                     | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0))
            check = os.open(path, flags)
            try:
                current = os.fstat(check)
                if not stat.S_ISREG(current.st_mode):
                    return
                observed_owner = os.read(check, LINKED_LEASE_OWNER_READ_MAX_BYTES)
            finally:
                os.close(check)
            final = path.lstat()
            if (stat.S_ISREG(current.st_mode) and stat.S_ISREG(final.st_mode)
                    and stored.st_nlink == current.st_nlink == final.st_nlink == 1
                    and stored.st_dev == current.st_dev == final.st_dev
                    and stored.st_ino == current.st_ino == final.st_ino
                    and observed_owner == owner):
                path.unlink()
        except (OSError, ValueError):
            # Preserve an unverifiable or unremovable lock for explicit recovery.
            pass


@dataclass(frozen=True)
class LinkedThumbnail:
    """Identify one locally generated thumbnail and its server metadata.

    Args:
        path (Path): Local generated thumbnail file.
        size (int): Requested long-side size in pixels.
        format (str): Server format identifier, either jpg or webp.
    """

    path: Path
    size: int
    format: str


@dataclass(frozen=True)
class _FileSegment:
    """Remember a file's immutable admission metadata for one streamed part.

    Args:
        path (Path): Local path to the original or generated thumbnail.
        size (int): Declared file size in bytes.
        device (int): Filesystem device identity captured before streaming.
        inode (int): Filesystem inode or platform-equivalent file identity.
        modified_ns (int): Last-modified timestamp in nanoseconds at admission.
        expected_sha256 (str): Preflight hash of the original, or empty for
            a locally generated derivative without a separate inventory hash.
    """

    path: Path
    size: int
    device: int
    inode: int
    modified_ns: int
    expected_sha256: str


def _file_segment(path: Path, expected_sha256: str = '') -> _FileSegment:
    """Capture a regular non-symlink file before calculating multipart length.

    Args:
        path (Path): Source file path selected by the existing upload owner.
        expected_sha256 (str): Expected lowercase source hash, or empty for a
            derivative not independently checked by server inventory.

    Returns:
        _FileSegment: Size and identity used to detect a changed source.

    Raises:
        LinkingError: The source is unsafe or exceeds its configured bound.
        OSError: The file metadata cannot be read.
    """
    metadata = path.lstat()
    if (not stat.S_ISREG(metadata.st_mode) or metadata.st_size < 0
            or (expected_sha256 and re.fullmatch(r'[a-f0-9]{64}', expected_sha256) is None)
            or metadata.st_size > LINKED_UPLOAD_FILE_MAX_BYTES):
        raise LinkingError('Upload source is unavailable or too large.')
    return _FileSegment(
        path, metadata.st_size, metadata.st_dev, metadata.st_ino,
        metadata.st_mtime_ns, expected_sha256,
    )


def capture_linked_source(path: Path, expected_sha256: str, size: int) -> _FileSegment:
    """Capture the source identity before a linked inventory request.

    Args:
        path (Path): Original source selected for one linked upload.
        expected_sha256 (str): Previously computed lowercase source SHA-256.
        size (int): Previously observed source size in bytes.

    Returns:
        _FileSegment: Validated snapshot to recheck before a duplicate skip.

    Raises:
        LinkingError: Identity, size or expected digest is unavailable.
        OSError: The source cannot be inspected.
    """
    if (not isinstance(expected_sha256, str)
            or re.fullmatch(r'[a-f0-9]{64}', expected_sha256) is None):
        raise LinkingError('Linked source identity is unavailable.')
    snapshot = _file_segment(path, expected_sha256)
    if (type(size) is not int or size != snapshot.size or snapshot.inode <= 0):
        raise LinkingError('Linked source identity is unavailable.')
    return snapshot


def verify_linked_source(snapshot: _FileSegment) -> None:
    """Recheck the same source identity and bytes before acknowledging a skip.

    Args:
        snapshot (_FileSegment): Source identity captured before inventory.

    Returns:
        None: The current regular file still has the captured identity and hash.

    Raises:
        LinkingError: The source changed or its identity cannot be verified.
        OSError: The source cannot be opened or read.
    """
    flags = os.O_RDONLY | getattr(os, 'O_BINARY', 0) | getattr(os, 'O_NOFOLLOW', 0)
    descriptor = os.open(snapshot.path, flags)
    try:
        source = os.fdopen(descriptor, 'rb')
    except BaseException:
        os.close(descriptor)
        raise
    with source:
        before = os.fstat(source.fileno())
        current = snapshot.path.lstat()
        if (not stat.S_ISREG(before.st_mode) or not stat.S_ISREG(current.st_mode)
                or before.st_ino <= 0 or before.st_nlink != 1 or current.st_nlink != 1
                or before.st_size != snapshot.size or current.st_size != snapshot.size
                or (before.st_dev, before.st_ino) != (snapshot.device, snapshot.inode)
                or (current.st_dev, current.st_ino) != (snapshot.device, snapshot.inode)
                or before.st_mtime_ns != snapshot.modified_ns
                or current.st_mtime_ns != snapshot.modified_ns):
            raise LinkingError('Linked source changed during inventory.')
        digest = hashlib.sha256()
        remaining = snapshot.size
        while remaining:
            chunk = source.read(min(remaining, LINKED_UPLOAD_CHUNK_BYTES))
            if not chunk:
                raise LinkingError('Linked source changed during inventory.')
            remaining -= len(chunk)
            digest.update(chunk)
        after = os.fstat(source.fileno())
        final = snapshot.path.lstat()
        if (not stat.S_ISREG(final.st_mode) or after.st_nlink != 1 or final.st_nlink != 1
                or after.st_size != snapshot.size or final.st_size != snapshot.size
                or (after.st_dev, after.st_ino) != (snapshot.device, snapshot.inode)
                or (final.st_dev, final.st_ino) != (snapshot.device, snapshot.inode)
                or after.st_mtime_ns != snapshot.modified_ns
                or final.st_mtime_ns != snapshot.modified_ns
                or digest.hexdigest() != snapshot.expected_sha256):
            raise LinkingError('Linked source changed during inventory.')


def delete_verified_linked_source(snapshot: _FileSegment) -> None:
    """Delete only the exact acknowledged source through a verified Windows handle.

    The handle denies concurrent write and delete opens. Its identity and bytes
    are checked before delete disposition is applied to that same file object,
    avoiding a path swap between verification and deletion.

    Args:
        snapshot (_FileSegment): Captured path, identity, size and digest for
            the exact source whose upload was positively acknowledged.

    Returns:
        None: The verified file object is marked for deletion.

    Raises:
        OSError: Windows cannot safely admit or delete the verified source.
        LinkingError: The path no longer identifies the captured bytes.
        RuntimeError: Safe handle deletion is unavailable on this platform.
    """
    if os.name != 'nt':
        raise RuntimeError('Safe source deletion is unavailable on this platform.')
    if (snapshot.device <= 0 or snapshot.inode <= 0 or snapshot.size < 0
            or re.fullmatch(r'[a-f0-9]{64}', snapshot.expected_sha256) is None):
        raise LinkingError('Captured source identity is invalid.')

    import ctypes
    import msvcrt

    # LOAD_LIBRARY_SEARCH_SYSTEM32 prevents an adjacent DLL from shadowing it.
    kernel32 = ctypes.WinDLL(
        'kernel32.dll', use_last_error=True, winmode=0x00000800,
    )
    create_file = kernel32.CreateFileW
    create_file.argtypes = (
        ctypes.c_wchar_p, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_void_p,
        ctypes.c_ulong, ctypes.c_ulong, ctypes.c_void_p,
    )
    create_file.restype = ctypes.c_void_p
    get_information = kernel32.GetFileInformationByHandle
    get_information.argtypes = (ctypes.c_void_p, ctypes.c_void_p)
    get_information.restype = ctypes.c_int
    set_information = kernel32.SetFileInformationByHandle
    set_information.argtypes = (
        ctypes.c_void_p, ctypes.c_int, ctypes.c_void_p, ctypes.c_ulong,
    )
    set_information.restype = ctypes.c_int
    close_handle = kernel32.CloseHandle
    close_handle.argtypes = (ctypes.c_void_p,)
    close_handle.restype = ctypes.c_int

    # Purpose: Request exact read, metadata and deletion rights on one source.
    # Type: int; Units: Win32 access mask; Scope: acknowledged source deletion.
    # Consumers: CreateFileW. Rationale: Hash and delete the same file handle.
    desired_access = 0x0001 | 0x0080 | 0x10000
    # Purpose: Deny concurrent writers and path deletion while verifying bytes.
    # Type: int; Units: Win32 share mask; Scope: acknowledged source deletion.
    # Consumers: CreateFileW. Rationale: Existing reads may continue safely.
    share_mode = 0x0001
    # Purpose: Open the existing entry itself without following reparse points.
    # Type: int; Units: Win32 creation disposition; Scope: source admission.
    # Consumers: CreateFileW. Rationale: Never create or truncate a source.
    open_existing = 3
    # FILE_FLAG_OPEN_REPARSE_POINT inspects the entry itself, not its target.
    open_reparse_point = 0x00200000
    # INVALID_HANDLE_VALUE is the Win32 (HANDLE)-1 failure sentinel.
    invalid_handle = ctypes.c_void_p(-1).value
    handle = create_file(
        str(snapshot.path), desired_access, share_mode, None, open_existing,
        open_reparse_point, None,
    )
    if handle is None or handle == invalid_handle:
        raise OSError(ctypes.get_last_error(), 'Source could not be safely opened.')

    descriptor: int | None = None
    try:
        descriptor = msvcrt.open_osfhandle(
            int(handle), os.O_RDONLY | getattr(os, 'O_BINARY', 0),
        )
        handle = None
        with os.fdopen(descriptor, 'rb') as source:
            descriptor = None
            # BY_HANDLE_FILE_INFORMATION is a 52-byte Win32 ABI record.
            info = ctypes.create_string_buffer(52)
            native_handle = msvcrt.get_osfhandle(source.fileno())
            if not get_information(native_handle, info):
                raise OSError(ctypes.get_last_error(), 'Source identity is unavailable.')
            # ABI offsets: attributes DWORD at 0; link-count DWORD at 40.
            attributes = int.from_bytes(info.raw[0:4], 'little')
            link_count = int.from_bytes(info.raw[40:44], 'little')
            # FILE_ATTRIBUTE_DIRECTORY and FILE_ATTRIBUTE_REPARSE_POINT.
            if attributes & (0x10 | 0x400) or link_count != 1:
                raise LinkingError('Source is not a single regular file.')

            before = os.fstat(source.fileno())
            path_before = snapshot.path.lstat()
            expected_identity = (snapshot.device, snapshot.inode, snapshot.size,
                                 snapshot.modified_ns)

            def matches(info: os.stat_result) -> bool:
                """Compare one open or path observation with the captured source.

                Args:
                    info (os.stat_result): Current descriptor or path metadata.

                Returns:
                    bool: True when type, link count, identity, size and time match.
                """
                return (
                    stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                    and (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)
                    == expected_identity
                )

            if not matches(before) or not matches(path_before):
                raise LinkingError('Source changed after upload acknowledgement.')
            digest = hashlib.sha256()
            remaining = snapshot.size
            while remaining:
                chunk = source.read(min(remaining, LINKED_UPLOAD_CHUNK_BYTES))
                if not chunk:
                    raise LinkingError(
                        'Source changed after upload acknowledgement.'
                    )
                remaining -= len(chunk)
                digest.update(chunk)
            if source.read(1):
                raise LinkingError('Source changed after upload acknowledgement.')
            after = os.fstat(source.fileno())
            path_after = snapshot.path.lstat()
            if (not matches(after) or not matches(path_after)
                    or digest.hexdigest() != snapshot.expected_sha256):
                raise LinkingError('Source changed after upload acknowledgement.')
            # FILE_DISPOSITION_INFO uses class 4 and a one-byte BOOLEAN.
            disposition = ctypes.c_byte(1)
            if not set_information(
                native_handle, 4, ctypes.byref(disposition), ctypes.sizeof(disposition),
            ):
                raise OSError(ctypes.get_last_error(), 'Source could not be safely deleted.')
    finally:
        if descriptor is not None:
            os.close(descriptor)
        if handle is not None:
            close_handle(handle)


def _text_part(boundary: str, name: str, value: str) -> bytes:
    """Encode a bounded fixed-name multipart field without header injection.

    Args:
        boundary (str): Generated ASCII multipart boundary.
        name (str): Fixed field identifier including permitted array suffix.
        value (str): Non-secret UTF-8 form value.

    Returns:
        bytes: Complete text field including its final CRLF.

    Raises:
        LinkingError: Name or encoded value exceeds the safe form contract.
    """
    encoded = value.encode('utf-8')
    if (re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*(?:\[\])?', name) is None
            or len(encoded) > LINKED_UPLOAD_FIELD_MAX_BYTES):
        raise LinkingError('Invalid upload metadata.')
    return (
        f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n'.encode('ascii')
        + encoded + b'\r\n'
    )


def _file_header(boundary: str, name: str, path: Path) -> bytes:
    """Encode a fixed file-field header without exposing a local directory.

    Args:
        boundary (str): Generated ASCII multipart boundary.
        name (str): Fixed image or thumbnail form field.
        path (Path): Source path; only a sanitized basename is transmitted.

    Returns:
        bytes: Complete multipart file header before the actual file content.
    """
    filename = ''.join(
        '_' if ord(char) < 32 or ord(char) == 127 or char in '"\\' else char
        for char in path.name
    ) or 'image'
    content_type = mimetypes.guess_type(filename)[0] or 'application/octet-stream'
    if re.fullmatch(r'[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+', content_type) is None:
        content_type = 'application/octet-stream'
    return (
        f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; '
        f'filename="{filename}"\r\nContent-Type: {content_type}\r\n\r\n'
    ).encode('utf-8')


class LinkedMultipart:
    """Build one replay-prohibited, bounded, streamed image upload body."""

    def __init__(
        self,
        gallery_id: int,
        image: Path,
        create_thumbnails: bool,
        expected_sha256: str,
        thumbnails: tuple[LinkedThumbnail, ...] = (),
        client_upload_id: str = '',
        metadata: dict[str, str] | None = None,
    ) -> None:
        """Bind a gallery and validated local sources to one request body.

        Args:
            gallery_id (int): Explicit positive server gallery identifier.
            image (Path): Local original image file.
            create_thumbnails (bool): Request server-side derivative generation.
            expected_sha256 (str): Original SHA-256 checked during inventory.
            thumbnails (tuple[LinkedThumbnail, ...]): Optional generated files.
            client_upload_id (str): Optional client ID mapping originals to variants.
            metadata (dict[str, str] | None): Optional approved camera-location
                source, latitude, longitude and altitude form fields.

        Returns:
            None: Computes the fixed content length without reading file bytes.

        Raises:
            LinkingError: A gallery, thumbnail or metadata input is unsafe.
            OSError: An input file cannot be inspected.
        """
        if (type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                or len(thumbnails) > LINKED_UPLOAD_THUMBNAILS_MAX
                or not isinstance(client_upload_id, str)
                or not isinstance(expected_sha256, str)
                or re.fullmatch(r'[a-f0-9]{64}', expected_sha256) is None
                or (thumbnails and not client_upload_id)):
            raise LinkingError('Invalid gallery upload request.')
        values = metadata or {}
        if (not isinstance(values, dict) or not set(values).issubset(_LOCATION_FIELDS)
                or any(not isinstance(value, str) for value in values.values())):
            raise LinkingError('Invalid upload metadata.')
        self.boundary = 'PHPGalleryLinked' + uuid.uuid4().hex
        self.gallery_id = gallery_id
        self._segments: list[bytes | _FileSegment] = []
        self._lock = threading.Lock()
        self._consumed = False

        def text_field(name: str, value: str) -> None:
            """Append one validated local form field to this one-shot body.

            Args:
                name (str): Fixed multipart field name.
                value (str): UTF-8 field value.

            Returns:
                None: Adds its encoded bytes to the multipart sequence.
            """
            self._segments.append(_text_part(self.boundary, name, value))

        def file_field(name: str, path: Path, expected_hash: str = '') -> None:
            """Append a source header and captured file identity.

            Args:
                name (str): Fixed image or thumbnail multipart field name.
                path (Path): Original or derivative file to stream.
                expected_hash (str): Expected original SHA-256, or empty for a
                    generated derivative.

            Returns:
                None: Adds header, file descriptor and closing separator.
            """
            part = _file_segment(path, expected_hash)
            self._segments.extend((
                _file_header(self.boundary, name, path), part, b'\r\n',
            ))

        text_field('gallery_id', str(gallery_id))
        text_field('create_thumbnails', '1' if create_thumbnails else '0')
        if client_upload_id:
            text_field('image_client_ids[]', client_upload_id)
        for name, value in values.items():
            text_field(name, value)
        file_field('images[]', image, expected_sha256)
        for thumbnail in thumbnails:
            if (not isinstance(thumbnail, LinkedThumbnail)
                    or type(thumbnail.size) is not int
                    or not 0 < thumbnail.size <= LINKED_UPLOAD_THUMBNAIL_MAX_PIXELS
                    or thumbnail.format not in ('jpg', 'webp')):
                raise LinkingError('Invalid client thumbnail.')
            text_field('thumbnail_client_ids[]', client_upload_id)
            text_field('thumbnail_sizes[]', str(thumbnail.size))
            text_field('thumbnail_formats[]', thumbnail.format)
            file_field('client_thumbnails[]', thumbnail.path)
        self._segments.append(f'--{self.boundary}--\r\n'.encode('ascii'))
        self.content_length = sum(
            len(segment) if isinstance(segment, bytes) else segment.size
            for segment in self._segments
        )
        if self.content_length > LINKED_UPLOAD_BODY_MAX_BYTES:
            raise LinkingError('Gallery upload request is too large.')

    def __iter__(self) -> Iterator[bytes]:
        """Stream the body once and recheck each opened file's identity.

        Returns:
            Iterator[bytes]: Ordered MIME headers, bounded file chunks and footer.

        Raises:
            LinkingError: A source changed or this one-shot body was reused.
            OSError: A source cannot be opened or read safely.
        """
        with self._lock:
            if self._consumed:
                raise LinkingError('Upload body cannot be replayed.')
            self._consumed = True
        for segment in self._segments:
            if isinstance(segment, bytes):
                yield segment
                continue
            flags = os.O_RDONLY | getattr(os, 'O_BINARY', 0) | getattr(os, 'O_NOFOLLOW', 0)
            descriptor = os.open(segment.path, flags)
            with os.fdopen(descriptor, 'rb') as source:
                before = os.fstat(source.fileno())
                if (not stat.S_ISREG(before.st_mode) or before.st_size != segment.size
                        or before.st_dev != segment.device or before.st_ino != segment.inode
                        or before.st_mtime_ns != segment.modified_ns):
                    raise LinkingError('Upload source changed before transfer.')
                remaining = segment.size
                digest = hashlib.sha256() if segment.expected_sha256 else None
                while remaining:
                    chunk = source.read(min(remaining, LINKED_UPLOAD_CHUNK_BYTES))
                    if not chunk:
                        raise LinkingError('Upload source changed during transfer.')
                    remaining -= len(chunk)
                    if digest is not None:
                        digest.update(chunk)
                    yield chunk
                if digest is not None and digest.hexdigest() != segment.expected_sha256:
                    raise LinkingError('Upload source content changed during transfer.')
                after = os.fstat(source.fileno())
                if (after.st_size != segment.size or after.st_dev != segment.device
                        or after.st_ino != segment.inode
                        or after.st_mtime_ns != segment.modified_ns):
                    raise LinkingError('Upload source changed during transfer.')


def linked_target_binding(issuer: str, account_id: int, gallery_id: int) -> str:
    """Fingerprint the same destination with or without a live grant.

    Args:
        issuer (str): Exact verified installation origin and path.
        account_id (int): Verified administrator identifier.
        gallery_id (int): Exact upload gallery identifier.

    Returns:
        str: Stable SHA-256 used by the existing local upload history.
    """
    identity = '\0'.join((
        'php-gallery-linked-target-v1', issuer, str(account_id), str(gallery_id),
    ))
    return hashlib.sha256(identity.encode('utf-8')).hexdigest()


@dataclass(frozen=True, repr=False)
class LinkedUploadTarget:
    """Bind one live in-memory session to an explicitly selected gallery.

    Args:
        session (LinkingSession): Live explicitly upload-scoped session.
        issuer (str): Exact canonical HTTPS installation origin and mount.
        user_id (int): Positive administrator ID confirmed by the server.
        gallery_id (int): Positive server-owned selected gallery identifier.
        title (str): Last server-provided title, used only for display.
        watch_key (str | None): In-memory temporary API key for one contextual
            Watcher capture. It never contributes to the durable target binding.
    """

    session: LinkingSession
    issuer: str
    user_id: int
    gallery_id: int
    title: str
    watch_key: str | None = field(default=None, repr=False, compare=False)

    def __post_init__(self) -> None:
        """Require a live upload scope and an exact installation binding.

        Returns:
            None: Accepts only valid target and account identities.

        Raises:
            LinkingError: The session, issuer, account or gallery is invalid.
        """
        if (not self.session.can_upload() or not self.session.matches_issuer(self.issuer)
                or type(self.user_id) is not int or self.user_id <= 0
                or self.user_id > LINKING_GALLERY_ID_MAX
                or type(self.gallery_id) is not int or self.gallery_id <= 0
                or self.gallery_id > LINKING_GALLERY_ID_MAX
                or not isinstance(self.title, str)):
            raise LinkingError('The selected linked gallery is unavailable.')

    def binding(self) -> str:
        """Identify this account and gallery without retaining its credential.

        Returns:
            str: Stable SHA-256 of the exact issuer, Admin and gallery IDs.
        """
        return linked_target_binding(self.issuer, self.user_id, self.gallery_id)

    def inventory(self, files: list[dict[str, JsonValue]]) -> dict[str, JsonValue]:
        """Query this target's database inventory without disk-based recovery.

        Args:
            files (list[dict[str, JsonValue]]): Candidate client_id, filename,
                size and SHA-256 values owned by the existing upload workflow.

        Returns:
            dict[str, JsonValue]: Current server inventory for this gallery.

        Raises:
            LinkingError: Scope, session, target, response or request failed.
        """
        if self.watch_key is None:
            return self.session.inventory(self.gallery_id, files)
        return self.session.inventory(self.gallery_id, files, watch_key=self.watch_key)

    def upload(
        self, image: Path, create_thumbnails: bool,
        expected_sha256: str,
        thumbnails: tuple[LinkedThumbnail, ...] = (), client_upload_id: str = '',
        metadata: dict[str, str] | None = None,
    ) -> dict[str, JsonValue]:
        """Stream one image once to this gallery without automatic retry.

        Args:
            image (Path): Stable local original image.
            create_thumbnails (bool): Whether the server generates derivatives.
            expected_sha256 (str): Original SHA-256 checked during inventory.
            thumbnails (tuple[LinkedThumbnail, ...]): Optional local derivatives.
            client_upload_id (str): Optional ID associating original and variants.
            metadata (dict[str, str] | None): Approved camera-location metadata.

        Returns:
            dict[str, JsonValue]: Server confirmation for this exact gallery.

        Raises:
            LinkingError: Input, authorization or transport fails; an attempted
                transfer may have completed even when no response is received.
            OSError: An input file cannot be inspected or read.
        """
        body = LinkedMultipart(
            self.gallery_id, image, create_thumbnails, expected_sha256, thumbnails,
            client_upload_id, metadata,
        )
        if self.watch_key is None:
            return self.session.upload_multipart(
                self.gallery_id, body, body.boundary, body.content_length,
            )
        return self.session.upload_multipart(
            self.gallery_id, body, body.boundary, body.content_length,
            watch_key=self.watch_key,
        )


def verify_linked_upload_target(
    session: LinkingSession, issuer: str, account_id: int, scope: str,
    gallery_id: int, cancelled: threading.Event | None = None,
) -> LinkedUploadTarget:
    """Verify one exact upload target with two fresh identity reads.

    The server's gallery endpoint orders IDs ascending and accepts an exclusive
    numeric cursor, so one page beginning immediately before the requested ID
    is sufficient. This does not persist a selection or authorize later work;
    the server must still check every upload. Cancellation is checked around
    every request; an already-running read may finish before it is discarded.
    The caller must recheck its owner and cancellation before consuming a
    queued result, because cancellation can also arrive after this returns.

    Args:
        session (LinkingSession): One explicit live upload-scoped session.
        issuer (str): Exact independently selected HTTPS gallery issuer.
        account_id (int): Positive Admin identity expected for this session.
        scope (str): Exact independently approved upload scope bundle.
        gallery_id (int): Positive server gallery ID selected by the user.
        cancelled (threading.Event | None): Optional owner cancellation signal.

    Returns:
        LinkedUploadTarget: Freshly observed ID and title bound to this session.

    Raises:
        LinkingError: Cancellation, identity, scope or gallery is unavailable.
    """
    if (not isinstance(issuer, str) or not isinstance(scope, str)
            or scope_base(scope) != UPLOAD_SCOPE
            or type(account_id) is not int or not 0 < account_id <= LINKING_GALLERY_ID_MAX
            or type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
            or (cancelled is not None and not isinstance(cancelled, threading.Event))):
        raise LinkingError('The selected linked gallery is unavailable.')

    def checkpoint() -> None:
        """Refuse cancellation or any changed local session binding.

        Returns:
            None: The same session still holds the exact requested authority.

        Raises:
            LinkingError: Cancellation or local session state is unavailable.
        """
        if ((cancelled is not None and cancelled.is_set())
                or not session.matches_issuer(issuer)
                or not session.can_upload() or session.approved_scope() != scope):
            raise LinkingError('The selected linked gallery is unavailable.')

    checkpoint()
    if session.identity().user_id != account_id:
        raise LinkingError('The selected linked gallery is unavailable.')
    checkpoint()
    page = session.gallery_page(gallery_id - 1 if gallery_id > 1 else None, cancelled)
    checkpoint()
    if (not isinstance(page, LinkedGalleryPage) or not page.galleries
            or not isinstance(page.galleries[0], LinkedGallery)
            or page.galleries[0].gallery_id != gallery_id):
        raise LinkingError('The selected linked gallery is unavailable.')
    if session.identity().user_id != account_id:
        raise LinkingError('The selected linked gallery is unavailable.')
    checkpoint()
    target = LinkedUploadTarget(session, issuer, account_id, gallery_id,
                                page.galleries[0].title)
    checkpoint()
    return target
