# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_media_cache.py
# Module Type: Windows Client Cache
# Purpose: Retain bounded, revalidatable public media bytes per user.
# Responsibilities:
#   - Key cached bytes by a digest of exact gallery and image identity.
#   - Validate strong content hashes before returning cached media.
#   - Bound entry count, total bytes and filesystem work.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Bounded on-disk cache for server-authorized gallery media responses."""

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


@dataclass(frozen=True)
class PhotoCacheEntry:
    """Hold one verified media body, validator, and backing file identity."""

    body: bytes
    mime: str
    etag: str
    stored_at: int
    file_info: os.stat_result


class GalleryMediaCache:
    """Read and write bounded media bytes without storing gallery metadata."""

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

    def read(self, key: str, now: int | None = None) -> PhotoCacheEntry | None:
        """Read verified media bytes and record access for eviction.

        Args:
            key (str): Lowercase SHA-256 identity from ``key`` or ``track_key``.
            now (int | None): Access time in Unix seconds, or None for the clock.

        Returns:
            PhotoCacheEntry | None: Verified retained body or a cache miss.
        """
        if _CACHE_KEY_PATTERN.fullmatch(key) is None:
            return None
        try:
            with _CACHE_WRITE_LOCK:
                if self._directory.is_symlink():
                    return None
                path = self._entry_path(key)
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

    def contains(self, key: str, etag: str | None = None) -> bool:
        """Check whether a bounded cache object matches optional content identity.

        Args:
            key (str): Lowercase SHA-256 identity from ``key`` or ``track_key``.
            etag (str | None): Expected strong quoted SHA-256, if known.
        Returns:
            bool: True for a regular bounded cache file with valid metadata.
        """
        if (_CACHE_KEY_PATTERN.fullmatch(key) is None
                or (etag is not None
                    and re.fullmatch(r'"[a-f0-9]{64}"', etag) is None)):
            return False
        try:
            with _CACHE_WRITE_LOCK:
                if self._directory.is_symlink():
                    return False
                path = self._entry_path(key)
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
        self, key: str, entry: PhotoCacheEntry, now: int | None = None,
    ) -> None:
        """Refresh the validation stamp of the exact file accepted by a 304.

        Args:
            key (str): Lowercase SHA-256 photo cache identity.
            entry (PhotoCacheEntry): Verified file snapshot used for the validator.
            now (int | None): Validation time in Unix seconds, or None for the clock.

        Returns:
            None: Updates metadata only if the same cache file remains present.
        """
        try:
            with _CACHE_WRITE_LOCK:
                path = self._entry_path(key)
                if self._directory.is_symlink():
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
        now: int | None = None,
    ) -> bool:
        """Atomically retain one opt-in response within global cache limits.

        Args:
            key (str): Lowercase SHA-256 identity key from ``key``.
            body (bytes): Complete media response already validated by transport.
            mime (str): One accepted image or audio media type.
            etag (str): Strong quoted SHA-256 validator for ``body``.
            now (int | None): Current Unix seconds, or None to use the system clock.

        Returns:
            bool: True when the entry was atomically stored.
        """
        if (_CACHE_KEY_PATTERN.fullmatch(key) is None or not isinstance(body, bytes)
                or not 0 < len(body) <= self._max_body_bytes
                or mime not in _MIME_TO_CODE
                or etag != '"' + hashlib.sha256(body).hexdigest() + '"'):
            return False
        try:
            with _CACHE_WRITE_LOCK:
                self._ensure_directory()
                lock_path = self._directory / '.media-lock'
                if lock_path.is_symlink():
                    return False
                with lock_path.open('a+b') as lock:
                    if os.name == 'nt':
                        lock.seek(0)
                        msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, _CACHE_LOCK_SPAN)
                    else:
                        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                    # Closing the file releases the lock, including on a failed write.
                    return self._write_locked(key, body, mime, etag, now)
        except (OSError, ValueError, struct.error):
            return False

    def _write_locked(
        self, key: str, body: bytes, mime: str, etag: str,
        now: int | None,
    ) -> bool:
        """Evict within limits and atomically replace one cache object.

        Args:
            key (str): Lowercase SHA-256 identity key.
            body (bytes): Complete bounded media bytes.
            mime (str): Validated image or audio media type.
            etag (str): Strong SHA-256 validator.
            now (int | None): Current Unix seconds, or None to read the clock.

        Returns:
            bool: True when the complete cache object was committed.
        """
        current = int(time.time()) if now is None else now
        header = _CACHE_HEADER.pack(
            _CACHE_MAGIC, current, _MIME_TO_CODE[mime], etag[1:-1].encode('ascii'),
        )
        if not self._make_room(key, len(header) + len(body)):
            return False
        descriptor, temporary = tempfile.mkstemp(
            prefix=_CACHE_TEMP_PREFIX, suffix='.tmp', dir=self._directory,
        )
        try:
            with os.fdopen(descriptor, 'wb') as stream:
                stream.write(header)
                stream.write(body)
            # Initialize access and validation times with the same clock.
            os.utime(temporary, (current, current))
            os.replace(temporary, self._entry_path(key))
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
        if self._directory.is_symlink() or not self._directory.is_dir():
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
