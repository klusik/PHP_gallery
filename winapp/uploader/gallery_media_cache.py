# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_media_cache.py
# Module Type: Windows Client Cache
# Purpose: Cache public media with bounded ordinary eviction per user.
# Responsibilities:
#   - Key cached bytes by a digest of exact gallery and image identity.
#   - Validate strong content hashes before returning cached media.
#   - Bound ordinary entries and isolate explicit per-gallery retention pins.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Quota-managed media cache with optional hashed gallery retention areas."""

import hashlib
import os
import re
import stat
import struct
import tempfile
import threading
import time
from dataclasses import dataclass
from pathlib import Path

if os.name == 'nt':
    import msvcrt
else:
    import fcntl


# Purpose: Accept a response without contacting the gallery briefly.
# Type: int. Units: seconds. Scope: one photo cache entry's fresh interval.
# Consumers: GalleryMediaCache.is_fresh. Rationale: 60 seconds matches the server
# opt-in freshness contract and keeps repeated thumbnail reads responsive.
PHOTO_CACHE_FRESH_SECONDS = 60

# Purpose: Bound the total persisted media bytes for this application profile.
# Type: int. Units: bytes. Scope: one user's shared photo and audio cache.
# Consumers: GalleryMediaCache._make_room. Rationale: 1 GiB accommodates
# browsed thumbnails, detail renditions, and playback without unbounded growth.
MEDIA_CACHE_MAX_BYTES = 1024 * 1024 * 1024

# Purpose: Bound the number of files even with small thumbnails.
# Type: int. Units: cache entries. Scope: one user's shared media cache.
# Consumers: GalleryMediaCache._make_room. Rationale: 4096 keeps directory
# work bounded while covering several large browsed galleries.
MEDIA_CACHE_MAX_ENTRIES = 4096

# Purpose: Bound one cleanup scan even after interrupted or manual file copies.
# Type: int. Units: directory entries. Scope: one cache maintenance pass.
# Consumers: GalleryMediaCache._inventory. Rationale: Twice the retained count
# allows room for interrupted writes without permitting unbounded traversal.
MEDIA_CACHE_SCAN_MAX_ENTRIES = MEDIA_CACHE_MAX_ENTRIES * 2

# Purpose: Bound an explicit unpin conversion for one retained gallery.
# Type: int. Units: directory entries. Scope: one requested gallery only.
# Consumers: GalleryMediaCache.set_gallery_pinned. Rationale: A conversion
# visits one gallery and admits two renditions for 100,000 photos plus 256
# playlist and structural entries, independently of ordinary cache scans.
MEDIA_CACHE_GALLERY_SCAN_MAX_ENTRIES = 200_256

# Purpose: Keep cache metadata fixed-size and versioned independently of media.
# Type: bytes. Units: header bytes. Scope: one cache file.
# Consumers: GalleryMediaCache.read and GalleryMediaCache.write.
# Rationale: Retain the existing header and raster codes so old photo files still work.
# Audio uses new MIME codes. File modification time is the validation stamp.
_CACHE_MAGIC = b'PGPHOTO1'
_CACHE_HEADER = struct.Struct('>8sQB64s')
_MIME_TO_CODE = {'image/jpeg': 1, 'image/png': 2,
                 'image/gif': 3, 'image/webp': 4,
                 'audio/mpeg': 5, 'audio/wav': 6, 'audio/x-wav': 7}
_CODE_TO_MIME = {code: mime for mime, code in _MIME_TO_CODE.items()}
_CACHE_KEY_PATTERN = re.compile(r'[a-f0-9]{64}')
_CACHE_WRITE_LOCK = threading.RLock()
# Purpose: Serialize eviction and replacement across uploader processes.
# Type: int. Units: bytes. Scope: the per-user cache lock on Windows.
# Consumers: GalleryMediaCache.write. Rationale: One byte is enough for an
# exclusive advisory lock and carries no application data.
_CACHE_LOCK_SPAN = 1
_CACHE_TEMP_PREFIX = '.media-'
# Fixed hash-only retained storage names; labels, titles and credentials are absent.
_RETAINED_DIRECTORY = '.retained'
_PIN_MARKER = '.pinned'
_PIN_MARKER_CONTENT = b'1'


@dataclass(frozen=True)
class PhotoCacheEntry:
    """Hold one verified media body, validator, and backing file identity."""

    body: bytes
    mime: str
    etag: str
    stored_at: int
    file_info: os.stat_result


class GalleryMediaCache:
    """Manage ordinary quotas and explicit per-gallery media retention."""

    def __init__(self, directory: Path, max_body_bytes: int) -> None:
        """Create a cache rooted in the current user's private application data.

        Args:
            directory (Path): Per-user cache directory.
            max_body_bytes (int): Largest accepted media body in bytes.

        Returns:
            None: Stores only local cache configuration.
        """
        self._directory = directory
        self._max_body_bytes = max_body_bytes

    @staticmethod
    def key(
        issuer: str, gallery_id: int, image_id: int, thumbnail: bool,
    ) -> str:
        """Hash exact installation, gallery, image and rendition identity.

        Args:
            issuer (str): Canonical selected installation issuer.
            gallery_id (int): Positive selected gallery ID.
            image_id (int): Positive selected photo ID.
            thumbnail (bool): Whether the smallest rendition is requested.

        Returns:
            str: Lowercase SHA-256 filename key without retained source labels.

        Raises:
            ValueError: An identity component is malformed.
        """
        if (not isinstance(issuer, str) or not issuer or type(gallery_id) is not int
                or gallery_id <= 0 or type(image_id) is not int or image_id <= 0
                or type(thumbnail) is not bool):
            raise ValueError('Invalid gallery photo cache identity.')
        variant = b'thumbnail' if thumbnail else b'detail'
        material = b'\0'.join((
            issuer.encode('utf-8'), str(gallery_id).encode('ascii'),
            str(image_id).encode('ascii'), variant,
        ))
        return hashlib.sha256(material).hexdigest()

    @staticmethod
    def gallery_key(issuer: str, gallery_id: int) -> str:
        """Hash one exact issuer and positive gallery identity.

        Args:
            issuer (str): Canonical selected installation issuer.
            gallery_id (int): Positive physical gallery ID.

        Returns:
            str: Lowercase SHA-256 directory key without source labels.

        Raises:
            ValueError: The issuer or gallery identity is malformed.
        """
        if (not isinstance(issuer, str) or not issuer
                or type(gallery_id) is not int or gallery_id <= 0):
            raise ValueError('Invalid gallery cache identity.')
        material = issuer.encode('utf-8') + b'\0' + str(gallery_id).encode('ascii')
        return hashlib.sha256(material).hexdigest()

    def is_gallery_pinned(self, issuer: str, gallery_id: int) -> bool:
        """Check the persistent local retention preference for one gallery.

        Args:
            issuer (str): Canonical selected installation issuer.
            gallery_id (int): Positive physical gallery ID.

        Returns:
            bool: True only for an intact marker in the exact hashed directory.
        """
        try:
            key = self.gallery_key(issuer, gallery_id)
        except (UnicodeError, ValueError):
            return False
        directory = self._gallery_directory(key)
        return directory is not None and self._pin_marker_is_valid(directory)

    def set_gallery_pinned(
        self, issuer: str, gallery_id: int, pinned: bool,
    ) -> bool:
        """Persist or remove one gallery's local retention preference.

        Args:
            issuer (str): Canonical selected installation issuer.
            gallery_id (int): Positive physical gallery ID.
            pinned (bool): Whether retained-gallery storage should be active.

        Returns:
            bool: True when the preference and any unpin conversion complete.
                A copy failure keeps every retained original. A cleanup failure
                keeps the marker and any retained entries not yet removed.
        """
        if type(pinned) is not bool:
            return False
        try:
            gallery_key = self.gallery_key(issuer, gallery_id)
            with _CACHE_WRITE_LOCK:
                self._ensure_directory()
                lock_path = self._directory / '.media-lock'
                if self._path_is_reparse(lock_path):
                    return False
                with lock_path.open('a+b') as lock:
                    if os.name == 'nt':
                        lock.seek(0)
                        msvcrt.locking(
                            lock.fileno(), msvcrt.LK_NBLCK, _CACHE_LOCK_SPAN,
                        )
                    else:
                        fcntl.flock(
                            lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB,
                        )
                    directory = self._gallery_directory(
                        gallery_key, create=pinned,
                    )
                    if directory is None:
                        return (not pinned and self._gallery_absent_safely(
                            gallery_key,
                        ))
                    marker = directory / _PIN_MARKER
                    if pinned:
                        return self._write_pin_marker(directory, marker)
                    if not self._pin_marker_is_valid(directory):
                        return not marker.exists() and not marker.is_symlink()
                    retained = self._retained_entries(directory)
                    if retained is None or not self._copy_retained_to_ordinary(
                        retained,
                    ):
                        return False
                    for path, info in retained:
                        if not self._unlink_if_matches(path, info):
                            return False
                    if not self._unlink_if_matches(marker, marker.lstat()):
                        return False
                    self._remove_empty_gallery_directory(directory)
                    return True
        except (OSError, UnicodeError, ValueError, struct.error):
            return False

    @staticmethod
    def track_key(issuer: str, gallery_id: int, track_id: str, digest: str) -> str:
        """Hash one verified playlist row's track and content identity.

        Args:
            issuer (str): Canonical selected installation issuer.
            gallery_id (int): Authorized physical gallery ID.
            track_id (str): Validated server track identity.
            digest (str): Verified lowercase SHA-256 from the current playlist.

        Returns:
            str: Lowercase SHA-256 filename key with no retained source labels.
        """
        material = '\0'.join((issuer, str(gallery_id), 'track', track_id, digest))
        return hashlib.sha256(material.encode('utf-8')).hexdigest()

    @classmethod
    def for_current_user(cls, max_body_bytes: int) -> 'GalleryMediaCache':
        """Resolve the application's per-user cache directory.

        Args:
            max_body_bytes (int): Largest accepted media body in bytes.

        Returns:
            GalleryMediaCache: Cache that degrades to misses when unavailable.
        """
        root = (os.environ.get('LOCALAPPDATA') or os.environ.get('XDG_CACHE_HOME')
                or str(Path.home() / '.cache'))
        return cls(Path(root) / 'PHPGalleryUploader' / 'photo-cache', max_body_bytes)

    def read(
        self, key: str, now: int | None = None, *,
        gallery_key: str | None = None,
    ) -> PhotoCacheEntry | None:
        """Read verified media bytes and record access for eviction.

        Args:
            key (str): Lowercase SHA-256 identity from ``key`` or ``track_key``.
            now (int | None): Access time in Unix seconds, or None for the clock.
            gallery_key (str | None): Exact hashed gallery directory identity,
                or None for legacy flat-cache behavior.

        Returns:
            PhotoCacheEntry | None: Verified retained body or a cache miss.
        """
        if (_CACHE_KEY_PATTERN.fullmatch(key) is None
                or not self._valid_gallery_key(gallery_key)):
            return None
        try:
            with _CACHE_WRITE_LOCK:
                if self._path_is_reparse(self._directory):
                    return None
                pinned = (gallery_key is not None
                          and self._gallery_is_pinned(gallery_key))
                retained_path = (
                    self._retained_entry_path(gallery_key, key)
                    if pinned and gallery_key is not None else None
                )
                if retained_path is not None:
                    entry = self._read_entry_path(retained_path, now)
                    if entry is not None:
                        self._remove_matching_flat_duplicate(
                            key, gallery_key, entry,
                        )
                        return entry
                path = self._entry_path(key)
                entry = self._read_entry_path(path, now)
                if (entry is not None and pinned
                        and gallery_key is not None):
                    return self._promote_flat_entry(
                        key, gallery_key, path, entry,
                    )
                return entry
        except (OSError, ValueError, struct.error):
            return None

    def contains(
        self, key: str, etag: str | None = None, *,
        gallery_key: str | None = None,
    ) -> bool:
        """Check whether a bounded cache object matches optional content identity.

        Args:
            key (str): Lowercase SHA-256 identity from ``key`` or ``track_key``.
            etag (str | None): Expected strong quoted SHA-256, if known.
            gallery_key (str | None): Exact hashed gallery identity, or None
                for legacy flat-cache behavior.
        Returns:
            bool: True for a regular bounded cache file with valid metadata.
        """
        if (_CACHE_KEY_PATTERN.fullmatch(key) is None
                or not self._valid_gallery_key(gallery_key)
                or (etag is not None
                    and re.fullmatch(r'"[a-f0-9]{64}"', etag) is None)):
            return False
        try:
            with _CACHE_WRITE_LOCK:
                if self._path_is_reparse(self._directory):
                    return False
                path = self._entry_path(key)
                if (gallery_key is not None
                        and self._gallery_is_pinned(gallery_key)):
                    retained = self._retained_entry_path(gallery_key, key)
                    if retained is not None:
                        try:
                            retained.lstat()
                            path = retained
                        except OSError:
                            pass
                info = path.lstat()
                if (not stat.S_ISREG(info.st_mode)
                        or not _CACHE_HEADER.size < info.st_size
                        <= _CACHE_HEADER.size + self._max_body_bytes):
                    return False
                with path.open('rb') as stream:
                    opened = os.fstat(stream.fileno())
                    if not os.path.samestat(info, opened):
                        return False
                    header = stream.read(_CACHE_HEADER.size)
                if len(header) != _CACHE_HEADER.size:
                    return False
                magic, _written_at, mime_code, digest = _CACHE_HEADER.unpack(header)
                if magic != _CACHE_MAGIC or mime_code not in _CODE_TO_MIME:
                    return False
                decoded_digest = digest.decode('ascii')
                if re.fullmatch(r'[a-f0-9]{64}', decoded_digest) is None:
                    return False
                return etag is None or etag == '"' + decoded_digest + '"'
        except (OSError, ValueError, struct.error, UnicodeDecodeError):
            return False

    def revalidate(
        self, key: str, entry: PhotoCacheEntry, now: int | None = None, *,
        gallery_key: str | None = None,
    ) -> None:
        """Refresh the validation stamp of the exact file accepted by a 304.

        Args:
            key (str): Lowercase SHA-256 photo cache identity.
            entry (PhotoCacheEntry): Verified file snapshot used for the validator.
            now (int | None): Validation time in Unix seconds, or None for the clock.
            gallery_key (str | None): Exact hashed gallery identity, or None
                for legacy flat-cache behavior.

        Returns:
            None: Updates metadata only if the same cache file remains present.
        """
        if (not self._valid_gallery_key(gallery_key)
                or _CACHE_KEY_PATTERN.fullmatch(key) is None):
            return
        try:
            with _CACHE_WRITE_LOCK:
                path = self._entry_path(key)
                if (gallery_key is not None
                        and self._gallery_is_pinned(gallery_key)):
                    retained = self._retained_entry_path(gallery_key, key)
                    if retained is None:
                        return
                    path = retained
                if self._path_is_reparse(self._directory):
                    return
                info = path.lstat()
                if not stat.S_ISREG(info.st_mode) or not os.path.samestat(info, entry.file_info):
                    return
                with path.open('rb') as stream:
                    current = int(time.time()) if now is None else now
                    self._stamp(path, stream.fileno(), entry.file_info, current, current)
        except (OSError, ValueError):
            return

    @staticmethod
    def _stamp(
        path: Path, descriptor: int, expected: os.stat_result,
        accessed_at: int, validated_at: int | None = None,
    ) -> None:
        """Touch one open file without transferring validation to its replacement.

        Args:
            path (Path): Cache path, used on Windows while the file is open.
            descriptor (int): Open cache file descriptor.
            expected (os.stat_result): Verified file identity and modification time.
            accessed_at (int): New access time in Unix seconds.
            validated_at (int | None): New validation time in Unix seconds, or None
                to preserve the exact current modification time.

        Returns:
            None: Applies timestamps when the verified file still matches.
        """
        try:
            current = os.fstat(descriptor)
            if (not os.path.samestat(current, expected)
                    or current.st_size != expected.st_size
                    or current.st_mtime_ns != expected.st_mtime_ns):
                return
            timestamps = (
                accessed_at * 10**9, current.st_mtime_ns if validated_at is None
                else validated_at * 10**9,
            )
            if os.utime in os.supports_fd:
                os.utime(descriptor, ns=timestamps)
            elif os.name == 'nt' and os.path.samestat(path.lstat(), current):
                # A regular CRT file handle on Windows denies deletion/replacement.
                os.utime(path, ns=timestamps)
        except OSError:
            return

    def write(
        self, key: str, body: bytes, mime: str, etag: str,
        now: int | None = None, *, gallery_key: str | None = None,
    ) -> bool:
        """Atomically retain one opt-in response using its active cache policy.

        Args:
            key (str): Lowercase SHA-256 identity key from ``key``.
            body (bytes): Complete media response already validated by transport.
            mime (str): One accepted image or audio media type.
            etag (str): Strong quoted SHA-256 validator for ``body``.
            now (int | None): Current Unix seconds, or None to use the system clock.
            gallery_key (str | None): Exact hashed gallery identity, or None
                for legacy flat-cache behavior.

        Returns:
            bool: True when the entry was atomically stored.
        """
        if (_CACHE_KEY_PATTERN.fullmatch(key) is None
                or not self._valid_gallery_key(gallery_key)
                or not isinstance(body, bytes)
                or not 0 < len(body) <= self._max_body_bytes
                or mime not in _MIME_TO_CODE
                or etag != '"' + hashlib.sha256(body).hexdigest() + '"'):
            return False
        try:
            with _CACHE_WRITE_LOCK:
                self._ensure_directory()
                lock_path = self._directory / '.media-lock'
                if self._path_is_reparse(lock_path):
                    return False
                with lock_path.open('a+b') as lock:
                    if os.name == 'nt':
                        lock.seek(0)
                        msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, _CACHE_LOCK_SPAN)
                    else:
                        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                    # Closing the file releases the lock, including on a failed write.
                    pinned_key = (
                        gallery_key if gallery_key is not None
                        and self._gallery_is_pinned(gallery_key) else None
                    )
                    return self._write_locked(
                        key, body, mime, etag, now, pinned_key,
                    )
        except (OSError, ValueError, struct.error):
            return False

    def _write_locked(
        self, key: str, body: bytes, mime: str, etag: str,
        now: int | None, gallery_key: str | None = None,
    ) -> bool:
        """Atomically replace an ordinary or gallery-retained cache object.

        Args:
            key (str): Lowercase SHA-256 identity key.
            body (bytes): Complete bounded media bytes.
            mime (str): Validated image or audio media type.
            etag (str): Strong SHA-256 validator.
            now (int | None): Current Unix seconds, or None to read the clock.
            gallery_key (str | None): Exact pinned gallery directory, or None
                to apply ordinary cache quotas.

        Returns:
            bool: True when the complete cache object was committed.
        """
        current = int(time.time()) if now is None else now
        header = _CACHE_HEADER.pack(
            _CACHE_MAGIC, current, _MIME_TO_CODE[mime], etag[1:-1].encode('ascii'),
        )
        if gallery_key is None:
            if not self._make_room(key, len(header) + len(body)):
                return False
            destination = self._entry_path(key)
        else:
            directory = self._gallery_directory(gallery_key, create=True)
            if directory is None or not self._gallery_is_pinned(gallery_key):
                return False
            destination = self._retained_entry_path(gallery_key, key)
            if destination is None:
                return False
        descriptor, temporary = tempfile.mkstemp(
            prefix=_CACHE_TEMP_PREFIX, suffix='.tmp', dir=destination.parent,
        )
        try:
            with os.fdopen(descriptor, 'wb') as stream:
                stream.write(header)
                stream.write(body)
            # Initialize access and validation times with the same clock.
            os.utime(temporary, (current, current))
            os.replace(temporary, destination)
        finally:
            self._unlink(Path(temporary))
        return True

    def is_fresh(self, entry: PhotoCacheEntry, now: int | None = None) -> bool:
        """Check the short server-advertised freshness interval.

        Args:
            entry (PhotoCacheEntry): Verified retained image entry.
            now (int | None): Current Unix seconds, or None to use the system clock.

        Returns:
            bool: True while the entry is within the local freshness interval.
        """
        current = int(time.time()) if now is None else now
        return 0 <= current - entry.stored_at <= PHOTO_CACHE_FRESH_SECONDS

    @staticmethod
    def _valid_gallery_key(gallery_key: str | None) -> bool:
        """Validate an optional opaque gallery directory key.

        Args:
            gallery_key (str | None): Optional lowercase SHA-256 gallery key.

        Returns:
            bool: True for omitted legacy behavior or one exact hash key.
        """
        return (gallery_key is None or (
            isinstance(gallery_key, str)
            and _CACHE_KEY_PATTERN.fullmatch(gallery_key) is not None
        ))

    def _gallery_directory(
        self, gallery_key: str, *, create: bool = False,
    ) -> Path | None:
        """Resolve one retained directory without following reparse points.

        Args:
            gallery_key (str): Lowercase SHA-256 gallery directory key.
            create (bool): Create missing cache, retained and gallery directories.

        Returns:
            Path | None: Exact safe gallery directory, or None when unavailable.
        """
        if not self._valid_gallery_key(gallery_key) or gallery_key is None:
            return None
        try:
            if create:
                self._ensure_directory()
            elif (self._path_is_reparse(self._directory)
                  or not self._directory.is_dir()):
                return None
            retained_root = self._directory / _RETAINED_DIRECTORY
            if not retained_root.exists():
                if not create:
                    return None
                retained_root.mkdir(mode=0o700)
            root_info = retained_root.lstat()
            if (not stat.S_ISDIR(root_info.st_mode)
                    or self._path_is_reparse(retained_root)):
                return None
            directory = retained_root / gallery_key
            if not directory.exists():
                if not create:
                    return None
                directory.mkdir(mode=0o700)
            directory_info = directory.lstat()
            if (not stat.S_ISDIR(directory_info.st_mode)
                    or self._path_is_reparse(directory)):
                return None
            return directory
        except OSError:
            return None

    def _gallery_is_pinned(self, gallery_key: str) -> bool:
        """Read one marker after validating both retained directory levels.

        Args:
            gallery_key (str): Lowercase SHA-256 gallery directory key.

        Returns:
            bool: True for an exact regular one-byte pin marker.
        """
        directory = self._gallery_directory(gallery_key)
        return directory is not None and self._pin_marker_is_valid(directory)

    def _gallery_absent_safely(self, gallery_key: str) -> bool:
        """Distinguish an absent pin from an unsafe retained directory.

        Args:
            gallery_key (str): Lowercase SHA-256 gallery directory key.

        Returns:
            bool: True only when no retained path exists below safe ancestors.
        """
        try:
            if self._path_is_reparse(self._directory):
                return False
            retained_root = self._directory / _RETAINED_DIRECTORY
            try:
                root_info = retained_root.lstat()
            except FileNotFoundError:
                return True
            if (not stat.S_ISDIR(root_info.st_mode)
                    or self._path_is_reparse(retained_root)):
                return False
            gallery_path = retained_root / gallery_key
            try:
                gallery_path.lstat()
            except FileNotFoundError:
                return True
            return False
        except OSError:
            return False

    @staticmethod
    def _pin_marker_is_valid(directory: Path) -> bool:
        """Check a pin marker through a stable regular-file handle.

        Args:
            directory (Path): Previously checked hashed gallery directory.

        Returns:
            bool: True only when the marker's exact one-byte content is intact.
        """
        try:
            marker = directory / _PIN_MARKER
            before = marker.lstat()
            if not stat.S_ISREG(before.st_mode) or before.st_size != 1:
                return False
            with marker.open('rb') as stream:
                opened = os.fstat(stream.fileno())
                return (os.path.samestat(before, opened)
                        and stream.read(2) == _PIN_MARKER_CONTENT)
        except OSError:
            return False

    @staticmethod
    def _write_pin_marker(directory: Path, marker: Path) -> bool:
        """Atomically persist one pin marker inside its private directory.

        Args:
            directory (Path): Previously checked hashed gallery directory.
            marker (Path): Exact fixed marker path inside the directory.

        Returns:
            bool: True when the complete marker is installed.
        """
        try:
            if marker.exists() or marker.is_symlink():
                return GalleryMediaCache._pin_marker_is_valid(directory)
            descriptor, temporary = tempfile.mkstemp(
                prefix='.pin-', suffix='.tmp', dir=directory,
            )
            try:
                with os.fdopen(descriptor, 'wb') as stream:
                    stream.write(_PIN_MARKER_CONTENT)
                    stream.flush()
                    os.fsync(stream.fileno())
                os.replace(temporary, marker)
            finally:
                GalleryMediaCache._unlink(Path(temporary))
            return GalleryMediaCache._pin_marker_is_valid(directory)
        except OSError:
            return False

    def _retained_entry_path(self, gallery_key: str, key: str) -> Path | None:
        """Resolve one hash-only object path inside an existing safe directory.

        Args:
            gallery_key (str): Lowercase SHA-256 gallery directory key.
            key (str): Lowercase SHA-256 media object key.

        Returns:
            Path | None: Exact path or None for invalid identity or unsafe parent.
        """
        if _CACHE_KEY_PATTERN.fullmatch(key) is None:
            return None
        directory = self._gallery_directory(gallery_key)
        return None if directory is None else directory / (key + '.bin')

    def _read_entry_path(
        self, path: Path, now: int | None,
    ) -> PhotoCacheEntry | None:
        """Read and hash-verify one cache file through a stable descriptor.

        Args:
            path (Path): Exact flat or gallery-retained cache file path.
            now (int | None): Access time override, or None for the clock.

        Returns:
            PhotoCacheEntry | None: Verified bytes and descriptor identity, or miss.
        """
        try:
            if not self._safe_entry_parent(path):
                return None
            info = path.lstat()
            if (not stat.S_ISREG(info.st_mode)
                    or not _CACHE_HEADER.size < info.st_size
                    <= _CACHE_HEADER.size + self._max_body_bytes):
                return None
            with path.open('rb') as stream:
                opened = os.fstat(stream.fileno())
                if not os.path.samestat(info, opened):
                    return None
                header = stream.read(_CACHE_HEADER.size)
                body = stream.read(self._max_body_bytes + 1)
                if (len(header) != _CACHE_HEADER.size
                        or not 0 < len(body) <= self._max_body_bytes):
                    return None
                magic, _written_at, mime_code, digest = _CACHE_HEADER.unpack(header)
                mime = _CODE_TO_MIME.get(mime_code)
                if (magic != _CACHE_MAGIC or mime is None
                        or hashlib.sha256(body).hexdigest().encode('ascii') != digest):
                    return None
                current = int(time.time()) if now is None else now
                self._stamp(path, stream.fileno(), opened, current)
            return PhotoCacheEntry(
                body, mime, '"' + digest.decode('ascii') + '"',
                int(opened.st_mtime), opened,
            )
        except (OSError, ValueError, struct.error):
            return None

    def _safe_entry_parent(self, path: Path) -> bool:
        """Confirm flat or retained ancestry is directory-only and no-reparse.

        Args:
            path (Path): Exact file path to inspect without following ancestors.

        Returns:
            bool: True only for the cache root or one verified retained gallery.
        """
        try:
            if self._path_is_reparse(self._directory):
                return False
            parent = path.parent
            if parent == self._directory:
                return True
            retained_root = self._directory / _RETAINED_DIRECTORY
            if parent.parent != retained_root:
                return False
            root_info = retained_root.lstat()
            parent_info = parent.lstat()
            return (
                stat.S_ISDIR(root_info.st_mode)
                and stat.S_ISDIR(parent_info.st_mode)
                and not self._path_is_reparse(retained_root)
                and not self._path_is_reparse(parent)
                and _CACHE_KEY_PATTERN.fullmatch(parent.name) is not None
            )
        except OSError:
            return False

    @staticmethod
    def _path_is_reparse(path: Path) -> bool:
        """Detect symbolic links and Windows junctions without traversal.

        Args:
            path (Path): Existing directory or cache entry path.

        Returns:
            bool: True for symbolic-link or junction reparse points.
        """
        junction_check = getattr(path, 'is_junction', None)
        return path.is_symlink() or (
            callable(junction_check) and bool(junction_check())
        )

    def _promote_flat_entry(
        self, key: str, gallery_key: str, flat_path: Path,
        entry: PhotoCacheEntry,
    ) -> PhotoCacheEntry | None:
        """Move one verified flat hit into its pinned gallery under the lock.

        Args:
            key (str): Lowercase media object identity.
            gallery_key (str): Exact hashed gallery directory identity.
            flat_path (Path): Legacy ordinary path verified by the caller.
            entry (PhotoCacheEntry): Verified bytes and original file identity.

        Returns:
            PhotoCacheEntry | None: Promoted retained entry, or None on failure.
        """
        try:
            with _CACHE_WRITE_LOCK:
                lock_path = self._directory / '.media-lock'
                if self._path_is_reparse(lock_path):
                    return None
                with lock_path.open('a+b') as lock:
                    if os.name == 'nt':
                        lock.seek(0)
                        msvcrt.locking(
                            lock.fileno(), msvcrt.LK_NBLCK, _CACHE_LOCK_SPAN,
                        )
                    else:
                        fcntl.flock(
                            lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB,
                        )
                    if not self._gallery_is_pinned(gallery_key):
                        return None
                    current = self._read_entry_path(flat_path, entry.stored_at)
                    if (current is None
                            or not os.path.samestat(current.file_info, entry.file_info)
                            or current.etag != entry.etag):
                        return None
                    if not self._write_locked(
                        key, current.body, current.mime, current.etag,
                        current.stored_at, gallery_key,
                    ):
                        return None
                    retained_path = self._retained_entry_path(gallery_key, key)
                    if retained_path is None:
                        return None
                    retained = self._read_entry_path(
                        retained_path, current.stored_at,
                    )
                    if retained is None:
                        return None
                    try:
                        flat_info = flat_path.lstat()
                        if (os.path.samestat(flat_info, current.file_info)
                                and flat_info.st_size == current.file_info.st_size):
                            if not self._unlink(flat_path):
                                return None
                    except OSError:
                        return None
                    return retained
        except (OSError, ValueError, struct.error):
            return None

    def _retained_entries(
        self, directory: Path,
    ) -> list[tuple[Path, os.stat_result]] | None:
        """List one gallery's regular object files within the work bound.

        Args:
            directory (Path): Exact hashed retained gallery directory.

        Returns:
            list[tuple[Path, os.stat_result]] | None: Hash-only files and their
                no-follow metadata, or None for unsafe entries or excess work.
        """
        entries: list[tuple[Path, os.stat_result]] = []
        try:
            with os.scandir(directory) as listing:
                for index, item in enumerate(listing):
                    if index >= MEDIA_CACHE_GALLERY_SCAN_MAX_ENTRIES:
                        return None
                    if item.name == _PIN_MARKER:
                        continue
                    temporary = (
                        item.name.startswith('.media-')
                        or item.name.startswith('.pin-')
                    ) and item.name.endswith('.tmp')
                    item_path = Path(item.path)
                    if temporary:
                        info = item_path.lstat()
                        if not stat.S_ISREG(info.st_mode):
                            return None
                        if not self._unlink_if_matches(item_path, info):
                            return None
                        continue
                    match = item.name.endswith('.bin') and (
                        _CACHE_KEY_PATTERN.fullmatch(item.name[:-4]) is not None
                    )
                    if not match:
                        continue
                    # Windows DirEntry.stat can omit the inode. A direct
                    # no-follow stat supplies the identity checked against fstat.
                    info = item_path.lstat()
                    if not stat.S_ISREG(info.st_mode):
                        return None
                    entries.append((item_path, info))
            return entries
        except OSError:
            return None

    def _remove_matching_flat_duplicate(
        self, key: str, gallery_key: str, retained: PhotoCacheEntry,
    ) -> None:
        """Remove one identical ordinary copy while retaining the pinned entry.

        Args:
            key (str): Lowercase media object identity.
            gallery_key (str): Exact hashed gallery directory identity.
            retained (PhotoCacheEntry): Validated pinned body and file identity.

        Returns:
            None: Removes only a hash-verified identical flat copy under the
                shared mutation lock.
        """
        try:
            with _CACHE_WRITE_LOCK:
                lock_path = self._directory / '.media-lock'
                if self._path_is_reparse(lock_path):
                    return
                with lock_path.open('a+b') as lock:
                    if os.name == 'nt':
                        lock.seek(0)
                        msvcrt.locking(
                            lock.fileno(), msvcrt.LK_NBLCK, _CACHE_LOCK_SPAN,
                        )
                    else:
                        fcntl.flock(
                            lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB,
                        )
                    if not self._gallery_is_pinned(gallery_key):
                        return
                    flat_path = self._entry_path(key)
                    flat = self._read_entry_path(flat_path, retained.stored_at)
                    if flat is None or flat.etag != retained.etag:
                        return
                    self._unlink_if_matches(flat_path, flat.file_info)
        except (OSError, ValueError, struct.error):
            return

    def _copy_retained_to_ordinary(
        self, retained: list[tuple[Path, os.stat_result]],
    ) -> bool:
        """Copy one retained gallery to quota-managed storage transactionally.

        Args:
            retained (list[tuple[Path, os.stat_result]]): Exact gallery entries
                and no-follow identities captured by the bounded scan.

        Returns:
            bool: True when all objects are available in ordinary storage.
                Failed partial copies are removed while retained originals remain.
        """
        prepared: list[
            tuple[Path, str, os.stat_result, str, str, int, bool]
        ] = []
        for path, expected in retained:
            key = path.name[:-4]
            entry = self._read_entry_path(path, None)
            if (entry is None or not os.path.samestat(entry.file_info, expected)):
                return False
            ordinary = self._read_entry_path(self._entry_path(key), None)
            if ordinary is not None and ordinary.etag != entry.etag:
                return False
            prepared.append((
                path, key, expected, entry.etag, entry.mime, entry.stored_at,
                ordinary is not None,
            ))
        copied: list[tuple[Path, os.stat_result]] = []
        for path, key, expected, etag, mime, stored_at, already_present in prepared:
            if already_present:
                continue
            entry = self._read_entry_path(path, stored_at)
            if (entry is None or not os.path.samestat(entry.file_info, expected)
                    or entry.etag != etag):
                for copied_path, copied_info in copied:
                    self._unlink_if_matches(copied_path, copied_info)
                return False
            if not self._write_locked(
                key, entry.body, mime, etag, stored_at,
            ):
                for copied_path, copied_info in copied:
                    self._unlink_if_matches(copied_path, copied_info)
                return False
            destination = self._entry_path(key)
            try:
                copied.append((destination, destination.lstat()))
            except OSError:
                for copied_path, copied_info in copied:
                    self._unlink_if_matches(copied_path, copied_info)
                return False
        return True

    @staticmethod
    def _unlink_if_matches(path: Path, expected: os.stat_result) -> bool:
        """Remove a copied cache file only while its identity is unchanged.

        Args:
            path (Path): Exact hash-only ordinary cache file.
            expected (os.stat_result): Identity observed after the owned copy.

        Returns:
            bool: True when the exact copy was removed or already absent.
        """
        try:
            current = path.lstat()
            if not os.path.samestat(current, expected):
                return False
            return GalleryMediaCache._unlink(path)
        except OSError:
            return False

    def _remove_empty_gallery_directory(self, directory: Path) -> None:
        """Remove one now-empty retained gallery directory and its root if empty.

        Args:
            directory (Path): Exact hashed retained gallery directory.

        Returns:
            None: Removes only empty directories without following links.
        """
        try:
            directory.rmdir()
            (self._directory / _RETAINED_DIRECTORY).rmdir()
        except OSError:
            return

    def _entry_path(self, key: str) -> Path:
        """Return one validated hash-only cache filename.

        Args:
            key (str): Lowercase SHA-256 cache identity.

        Returns:
            Path: Cache data path below the configured directory.
        """
        if _CACHE_KEY_PATTERN.fullmatch(key) is None:
            raise ValueError('Invalid gallery photo cache key.')
        return self._directory / (key + '.bin')

    def _ensure_directory(self) -> None:
        """Create and verify the leaf cache directory without following it.

        Returns:
            None: Raises an OS error when the cache root is unavailable.
        """
        self._directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        if self._path_is_reparse(self._directory) or not self._directory.is_dir():
            raise OSError('Gallery photo cache directory is unsafe.')

    def _inventory(self) -> list[tuple[Path, int, float]]:
        """List only bounded regular cache files for eviction.

        Returns:
            list[tuple[Path, int, float]]: Path, byte size and access time.

        Raises:
            OSError: Directory traversal is unavailable or exceeds its work cap.
        """
        entries = []
        with os.scandir(self._directory) as directory:
            for index, item in enumerate(directory):
                if index >= MEDIA_CACHE_SCAN_MAX_ENTRIES:
                    raise OSError('Gallery photo cache scan limit reached.')
                if not item.is_file(follow_symlinks=False):
                    continue
                if item.name.startswith(_CACHE_TEMP_PREFIX) and item.name.endswith('.tmp'):
                    # The process lock excludes active writers using this prefix.
                    self._unlink(Path(item.path))
                    continue
                if (not item.name.endswith('.bin')
                        or _CACHE_KEY_PATTERN.fullmatch(item.name[:-4]) is None):
                    continue
                info = item.stat(follow_symlinks=False)
                entries.append((Path(item.path), info.st_size, info.st_atime))
        return entries

    def _make_room(self, key: str, new_size: int) -> bool:
        """Evict least recently accessed files before one atomic write.

        Args:
            key (str): Entry being replaced or added.
            new_size (int): Complete encoded file size in bytes.

        Returns:
            bool: True when entry and byte limits admit the replacement.
        """
        entries = self._inventory()
        target = self._entry_path(key)
        entries = [entry for entry in entries if entry[0] != target]
        entries.sort(key=lambda entry: entry[2])
        total = sum(entry[1] for entry in entries)
        count = len(entries)
        for path, size, _accessed_at in entries:
            over_limit = (
                total + new_size > MEDIA_CACHE_MAX_BYTES
                or count + 1 > MEDIA_CACHE_MAX_ENTRIES
            )
            if over_limit and self._unlink(path):
                total -= size
                count -= 1
        return (total + new_size <= MEDIA_CACHE_MAX_BYTES
                and count + 1 <= MEDIA_CACHE_MAX_ENTRIES)

    @staticmethod
    def _unlink(path: Path) -> bool:
        """Remove one cache entry while ignoring an already absent file.

        Args:
            path (Path): Hash-only cache entry or its adjacent temporary file.

        Returns:
            bool: True when the file was removed or already absent.
        """
        try:
            path.unlink(missing_ok=True)
        except OSError:
            return False
        return True
