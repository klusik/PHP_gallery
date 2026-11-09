# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_media_cache.py
# Module Type: Windows Client Cache
# Purpose: Cache server-approved media with per-user quotas and retention.
# Responsibilities:
#   - Key cached bytes by a digest of exact gallery and image identity.
#   - Validate strong content hashes before returning cached media.
#   - Bound ordinary entries and isolate explicit per-gallery retention pins.
#   - Summarize ordinary and retained physical storage outside GUI work.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Quota-managed media cache with optional hashed gallery retention areas."""

import hashlib
import sqlite3
import os
import re
import stat
import struct
import tempfile
import threading
import time
from dataclasses import dataclass
from pathlib import Path
from .thumbnail_cache_index import ThumbnailCacheIndex

if os.name == 'nt':
    import msvcrt
else:
    import fcntl


# Purpose: Accept a response without contacting the gallery briefly.
# Type: int. Units: seconds. Scope: one photo cache entry's fresh interval.
# Consumers: GalleryMediaCache.is_fresh. Rationale: 60 seconds matches the server
# opt-in freshness contract and keeps repeated thumbnail reads responsive.
PHOTO_CACHE_FRESH_SECONDS = 60

# Purpose: Bound the main full-photo, detail-photo and audio cache.
# Type: int. Units: bytes. Scope: one user's shared non-thumbnail media pool.
# Consumers: GalleryMediaCache._make_room. Rationale: 1 GiB accommodates
# full/detail renditions and playback without unbounded growth.
MEDIA_CACHE_MAX_BYTES = 1024 * 1024 * 1024

# Purpose: Bound main-pool files even when media bodies are small.
# Type: int. Units: cache entries. Scope: one user's non-thumbnail media pool.
# Consumers: GalleryMediaCache._make_room. Rationale: 4096 keeps directory
# work bounded while covering several large browsed galleries.
MEDIA_CACHE_MAX_ENTRIES = 4096

# Purpose: Bound one main-pool scan after interrupted or manual file copies.
# Type: int. Units: directory entries. Scope: one non-thumbnail cache pass.
# Consumers: GalleryMediaCache._inventory. Rationale: Twice the retained count
# allows room for interrupted writes without permitting unbounded traversal.
MEDIA_CACHE_SCAN_MAX_ENTRIES = MEDIA_CACHE_MAX_ENTRIES * 2

# Purpose: Bound ordinary thumbnail bytes independently from full media/audio.
# Type: int. Units: bytes. Scope: one user's persistent thumbnail pool.
# Consumers: GalleryMediaCache._make_room.
# Rationale: 4 GiB retains many large galleries independently of detail/audio
# and the strictly bounded decoded/Tk image memory owners.
MEDIA_THUMBNAIL_CACHE_MAX_BYTES = 4 * 1024 * 1024 * 1024

# Purpose: Bound the number of ordinary thumbnail files in the separate pool.
# Type: int. Units: cache entries. Scope: one user's thumbnail cache.
# Consumers: GalleryMediaCache._make_room.
# Rationale: 262144 entries let 4 GiB hold many small thumbnails. Indexed
# admission avoids filesystem work proportional to this cap on each write.
MEDIA_THUMBNAIL_CACHE_MAX_ENTRIES = 262_144

# Purpose: Validate the shared per-user thumbnail storage preference.
# Type: int. Units: bytes. Scope: all profiles sharing ordinary thumbnails.
# Consumers: GalleryMediaCache.set_thumbnail_limit_bytes.
# Rationale: 64 GiB permits large collections while rejecting accidental
# arbitrary-precision quotas and SQLite integer overflow.
MEDIA_THUMBNAIL_CACHE_LIMIT_MAX_BYTES = 64 * 1024 * 1024 * 1024

# Purpose: Bound one thumbnail-pool eviction scan after interrupted writes.
# Type: int. Units: directory entries. Scope: one thumbnail maintenance pass.
# Consumers: GalleryMediaCache._inventory.
# Rationale: Twice the pool quota tolerates stale writes without unbounded walks.
MEDIA_THUMBNAIL_CACHE_SCAN_MAX_ENTRIES = (
    MEDIA_THUMBNAIL_CACHE_MAX_ENTRIES * 2
)

# Purpose: Bound an explicit unpin conversion for one retained gallery.
# Type: int. Units: directory entries. Scope: one requested gallery only.
# Consumers: GalleryMediaCache.set_gallery_pinned. Rationale: A conversion
# visits one gallery and admits two renditions for 100,000 photos plus 256
# playlist and structural entries, independently of ordinary cache scans.
MEDIA_CACHE_GALLERY_SCAN_MAX_ENTRIES = 200_256

# Purpose: Bound retained gallery directories visited for one statistics scan.
# Type: int. Units: directories. Scope: retained snapshot of one cache root.
# Consumers: GalleryMediaCache.retained_storage_snapshot.
# Rationale: 1024 galleries cap metadata work while covering broad Keep usage.
MEDIA_RETAINED_STATS_MAX_GALLERIES = 1024

# Purpose: Bound retained filesystem entries visited for one statistics scan.
# Type: int. Units: files. Scope: retained snapshot of one cache root.
# Consumers: GalleryMediaCache.retained_storage_snapshot.
# Rationale: 524288 bounds cancellable off-GUI maintenance for large libraries.
MEDIA_RETAINED_STATS_MAX_FILES = 524_288

# Purpose: Bound unrelated root entries inspected during retained discovery.
# Type: int. Units: entries. Scope: one retained-root directory scan.
# Consumers: GalleryMediaCache.retained_storage_snapshot.
# Rationale: The combined bound limits malformed entries while honoring the
# separate retained-gallery and file caps.
MEDIA_RETAINED_STATS_MAX_ROOT_ENTRIES = (
    MEDIA_RETAINED_STATS_MAX_GALLERIES + MEDIA_RETAINED_STATS_MAX_FILES
)

# Purpose: Bound roots retained by the process-wide metadata snapshot cache.
# Type: int. Units: cache roots. Scope: one uploader process.
# Consumers: GalleryMediaCache.retained_storage_snapshot.
# Rationale: Eight roots cover active and test profiles without path-state leaks.
MEDIA_RETAINED_STATS_MAX_ROOTS = 8

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
_THUMBNAIL_RETAINED_PREFIX = 't-'


@dataclass(frozen=True)
class _RetainedGalleryStats:
    """Cache totals for one unchanged retained gallery directory.

    Attributes:
        identity (tuple[int, int, int, int]): Device, inode, modification and
            change-time identity observed without opening media files.
        thumbnail_count (int): Count of recognized ``t-<hash>.bin`` files.
        thumbnail_bytes (int): Physical bytes of those files, including headers.
        detail_count (int): Count of recognized unprefixed hash filenames.
        detail_bytes (int): Physical bytes of those files, including headers.
    """

    identity: tuple[int, int, int, int]
    thumbnail_count: int
    thumbnail_bytes: int
    detail_count: int
    detail_bytes: int


@dataclass(frozen=True)
class _RetainedRootStats:
    """Cache gallery identities and totals for one unchanged retained root.

    Attributes:
        identity (tuple[int, int, int, int]): Root device, inode, modification
            and change-time identity.
        galleries (dict[str, _RetainedGalleryStats]): Hashed directory names
            mapped to their last stable metadata totals; removed names are
            dropped.
    """

    identity: tuple[int, int, int, int]
    galleries: dict[str, _RetainedGalleryStats]


_RETAINED_STATS_LOCK = threading.Lock()

# Purpose: Reuse stable directory-generation statistics across cache objects.
# Type: dict[str, _RetainedRootStats]. Units: roots and retained galleries.
# Scope: one uploader process; keys are exact configured cache-root spellings.
# Consumers: GalleryMediaCache.retained_storage_snapshot.
# Rationale: A tiny lock protects immutable-by-convention snapshot replacement;
# file walks and metadata calls always happen outside this lock.
_RETAINED_STATS_CACHE: dict[str, _RetainedRootStats] = {}


@dataclass(frozen=True)
class PhotoCacheEntry:
    """Hold one verified media body, validator, and backing file identity."""

    body: bytes
    mime: str
    etag: str
    stored_at: int
    file_info: os.stat_result
    path: Path


@dataclass(frozen=True)
class PhotoCacheReceipt:
    """Describe a previously verified file for cheap identity revalidation."""

    path: Path
    file_info: os.stat_result
    etag: str
    mime: str


class GalleryMediaCache:
    """Manage ordinary quotas and explicit per-gallery media retention."""

    def __init__(
        self, directory: Path, max_body_bytes: int, *,
        thumbnails: bool = False,
    ) -> None:
        """Create a cache rooted in the current user's private application data.

        Args:
            directory (Path): Per-user cache directory.
            max_body_bytes (int): Largest accepted media body in bytes.
            thumbnails (bool): Route ordinary objects to the independently
                bounded thumbnail child pool while sharing canonical lock/pins.

        Returns:
            None: Stores only local cache configuration.
        """
        self._directory = directory
        self._max_body_bytes = max_body_bytes
        self._thumbnail_pool = thumbnails
        self._thumbnail_stats = {
            'inventory_scans': 0, 'stat_calls': 0, 'sorts': 0,
            'evictions': 0, 'writes': 0, 'flushes': 0,
            'hashes': 0, 'hash_bytes': 0, 'reads': 0,
            'hash_ns': 0, 'inventory_ns': 0, 'eviction_ns': 0,
            'write_ns': 0, 'flush_ns': 0, 'index_ns': 0, 'replace_ns': 0,
        }
        self._pending_thumbnail_index: ThumbnailCacheIndex | None = None

    def set_thumbnail_limit_bytes(self, limit: int) -> bool:
        """Persist the ordinary thumbnail quota shared by every local profile.

        Args:
            limit (int): Positive physical file-byte quota up to 64 GiB.

        Returns:
            bool: True when the requested quota is valid and stored.
        """
        if (type(limit) is not int
                or not 0 < limit <= MEDIA_THUMBNAIL_CACHE_LIMIT_MAX_BYTES):
            return False
        try:
            self._thumbnail_accounting(limit=limit)
            return True
        except (OSError, ValueError, sqlite3.Error):
            return False

    def thumbnail_storage_snapshot(
        self, cancelled: threading.Event | None = None,
    ) -> tuple[int | None, int | None, int | None, bool, str | None]:
        """Return a privacy-safe thumbnail occupancy snapshot.

        Args:
            cancelled (threading.Event | None): Stop recovery during teardown.
        Returns:
            tuple[int | None, int | None, int | None, bool, str | None]: Ordinary
                count, physical file bytes including headers, shared quota,
                ready flag and fixed error; unknown values are never fake zero.
                Call off Tk because missing/dirty indexes require recovery.
        """
        try:
            count, used, limit = self._thumbnail_accounting(cancelled=cancelled)
            return count, used, limit, True, None
        except (OSError, ValueError, sqlite3.Error):
            return None, None, None, False, 'unavailable'

    def retained_storage_snapshot(
        self, cancelled: threading.Event | None = None,
    ) -> tuple[
        int | None, int | None, int | None, int | None, int | None, bool,
    ]:
        """Summarize pinned gallery files without reading or hashing bodies.

        Args:
            cancelled (threading.Event | None): Stop directory discovery or
                a changed-gallery scan; call this method off the GUI thread.

        Returns:
            tuple[int | None, int | None, int | None, int | None, int | None, bool]:
                Safe retained gallery count, thumbnail count and bytes,
                other-media count and bytes, then readiness. Other media may
                include photos, audio and older untyped thumbnails; opaque
                hashes cannot identify their rendition. All five counts are
                None when the snapshot is unsafe, cancelled or changed mid-scan.
                Only directories containing recognized media contribute to the
                gallery count. Physical bytes include each cache header.
        """
        unknown = (None, None, None, None, None, False)
        empty = (0, 0, 0, 0, 0, True)
        if cancelled is not None and cancelled.is_set():
            return unknown
        root_key = str(self._directory)
        retained_root = self._directory / _RETAINED_DIRECTORY
        try:
            base_info = self._directory.lstat()
        except FileNotFoundError:
            with _RETAINED_STATS_LOCK:
                _RETAINED_STATS_CACHE.pop(root_key, None)
            return empty
        except OSError:
            return unknown
        if (not stat.S_ISDIR(base_info.st_mode)
                or self._path_is_reparse(self._directory)):
            return unknown
        base_identity = (
            base_info.st_dev, base_info.st_ino,
            base_info.st_mtime_ns, base_info.st_ctime_ns,
        )
        try:
            root_info = retained_root.lstat()
        except FileNotFoundError:
            with _RETAINED_STATS_LOCK:
                _RETAINED_STATS_CACHE.pop(root_key, None)
            return empty
        except OSError:
            return unknown
        if (not stat.S_ISDIR(root_info.st_mode)
                or self._path_is_reparse(retained_root)):
            return unknown
        root_identity = (
            root_info.st_dev, root_info.st_ino,
            root_info.st_mtime_ns, root_info.st_ctime_ns,
        )
        with _RETAINED_STATS_LOCK:
            previous = _RETAINED_STATS_CACHE.get(root_key)
            if previous is not None:
                _RETAINED_STATS_CACHE.pop(root_key)
                _RETAINED_STATS_CACHE[root_key] = previous

        files_seen = 0
        if previous is not None and previous.identity == root_identity:
            gallery_names = tuple(previous.galleries)
            previous_galleries = previous.galleries
        else:
            gallery_names_list: list[str] = []
            root_entries_seen = 0
            try:
                with os.scandir(retained_root) as listing:
                    for item in listing:
                        if cancelled is not None and cancelled.is_set():
                            return unknown
                        root_entries_seen += 1
                        if root_entries_seen > MEDIA_RETAINED_STATS_MAX_ROOT_ENTRIES:
                            return unknown
                        item_path = Path(item.path)
                        info = item_path.lstat()
                        if self._path_is_reparse(item_path):
                            return unknown
                        if stat.S_ISDIR(info.st_mode):
                            if _CACHE_KEY_PATTERN.fullmatch(item.name) is None:
                                continue
                            gallery_names_list.append(item.name)
                            if len(gallery_names_list) > MEDIA_RETAINED_STATS_MAX_GALLERIES:
                                return unknown
                        else:
                            if not stat.S_ISREG(info.st_mode):
                                return unknown
                            files_seen += 1
                            if files_seen > MEDIA_RETAINED_STATS_MAX_FILES:
                                return unknown
                gallery_names = tuple(gallery_names_list)
                previous_galleries = (
                    previous.galleries if previous is not None else {}
                )
            except OSError:
                return unknown

        current_galleries: dict[str, _RetainedGalleryStats] = {}
        for gallery_name in gallery_names:
            if cancelled is not None and cancelled.is_set():
                return unknown
            directory = retained_root / gallery_name
            try:
                before = directory.lstat()
            except OSError:
                return unknown
            if (not stat.S_ISDIR(before.st_mode)
                    or self._path_is_reparse(directory)):
                return unknown
            directory_identity = (
                before.st_dev, before.st_ino,
                before.st_mtime_ns, before.st_ctime_ns,
            )
            cached = previous_galleries.get(gallery_name)
            if cached is not None and cached.identity == directory_identity:
                current_galleries[gallery_name] = cached
                continue

            thumbnail_count = 0
            thumbnail_bytes = 0
            detail_count = 0
            detail_bytes = 0
            try:
                with os.scandir(directory) as listing:
                    for item in listing:
                        if cancelled is not None and cancelled.is_set():
                            return unknown
                        files_seen += 1
                        if files_seen > MEDIA_RETAINED_STATS_MAX_FILES:
                            return unknown
                        path = Path(item.path)
                        info = path.lstat()
                        if self._path_is_reparse(path):
                            return unknown
                        if not stat.S_ISREG(info.st_mode):
                            return unknown
                        if (item.name == _PIN_MARKER
                                or item.name.startswith('.preload-')
                                or ((item.name.startswith('.media-')
                                     or item.name.startswith('.pin-'))
                                    and item.name.endswith('.tmp'))):
                            continue
                        filename = item.name[:-4] if item.name.endswith('.bin') else ''
                        if filename.startswith(_THUMBNAIL_RETAINED_PREFIX):
                            key = filename[len(_THUMBNAIL_RETAINED_PREFIX):]
                            if _CACHE_KEY_PATTERN.fullmatch(key) is None:
                                continue
                            thumbnail_count += 1
                            thumbnail_bytes += info.st_size
                        elif _CACHE_KEY_PATTERN.fullmatch(filename) is not None:
                            detail_count += 1
                            detail_bytes += info.st_size
                after = directory.lstat()
            except OSError:
                return unknown
            after_identity = (
                after.st_dev, after.st_ino,
                after.st_mtime_ns, after.st_ctime_ns,
            )
            if (not stat.S_ISDIR(after.st_mode)
                    or self._path_is_reparse(directory)
                    or after_identity != directory_identity):
                return unknown
            current_galleries[gallery_name] = _RetainedGalleryStats(
                directory_identity, thumbnail_count, thumbnail_bytes,
                detail_count, detail_bytes,
            )

        for gallery_name, stats in current_galleries.items():
            if cancelled is not None and cancelled.is_set():
                return unknown
            directory = retained_root / gallery_name
            try:
                after = directory.lstat()
            except OSError:
                return unknown
            after_identity = (
                after.st_dev, after.st_ino,
                after.st_mtime_ns, after.st_ctime_ns,
            )
            if (not stat.S_ISDIR(after.st_mode)
                    or self._path_is_reparse(directory)
                    or after_identity != stats.identity):
                return unknown

        if cancelled is not None and cancelled.is_set():
            return unknown
        try:
            root_after = retained_root.lstat()
            base_after = self._directory.lstat()
        except OSError:
            return unknown
        after_root_identity = (
            root_after.st_dev, root_after.st_ino,
            root_after.st_mtime_ns, root_after.st_ctime_ns,
        )
        after_base_identity = (
            base_after.st_dev, base_after.st_ino,
            base_after.st_mtime_ns, base_after.st_ctime_ns,
        )
        if (not stat.S_ISDIR(root_after.st_mode)
                or not stat.S_ISDIR(base_after.st_mode)
                or self._path_is_reparse(self._directory)
                or self._path_is_reparse(retained_root)
                or after_root_identity != root_identity
                or after_base_identity != base_identity):
            return unknown

        snapshot = _RetainedRootStats(root_identity, current_galleries)
        with _RETAINED_STATS_LOCK:
            _RETAINED_STATS_CACHE[root_key] = snapshot
            while len(_RETAINED_STATS_CACHE) > MEDIA_RETAINED_STATS_MAX_ROOTS:
                oldest_root = next(iter(_RETAINED_STATS_CACHE))
                _RETAINED_STATS_CACHE.pop(oldest_root)

        thumbnail_total = sum(
            entry.thumbnail_count for entry in current_galleries.values()
        )
        thumbnail_byte_total = sum(
            entry.thumbnail_bytes for entry in current_galleries.values()
        )
        detail_total = sum(
            entry.detail_count for entry in current_galleries.values()
        )
        detail_byte_total = sum(
            entry.detail_bytes for entry in current_galleries.values()
        )
        gallery_total = sum(
            entry.thumbnail_count + entry.detail_count > 0
            for entry in current_galleries.values()
        )
        return (
            gallery_total, thumbnail_total, thumbnail_byte_total,
            detail_total, detail_byte_total, True,
        )

    def _open_thumbnail_index(
        self, cancelled: threading.Event | None = None,
    ) -> ThumbnailCacheIndex:
        """Open ordinary accounting while the caller owns the media lock.

        Args:
            cancelled (threading.Event | None): Optional recovery interruption.
        Returns:
            ThumbnailCacheIndex: Ready shared index; caller closes it.
        """
        self._ensure_pool_directory(True)
        started = time.perf_counter_ns()
        try:
            return ThumbnailCacheIndex(
                self._directory, self._directory / 'thumbnails',
                MEDIA_THUMBNAIL_CACHE_MAX_BYTES, MEDIA_THUMBNAIL_CACHE_MAX_ENTRIES,
                lambda path: not self._path_is_reparse(path),
                self._thumbnail_stats, self._unlink_if_matches, cancelled,
            )
        finally:
            self._thumbnail_stats['index_ns'] += time.perf_counter_ns() - started

    def operation_counts(self) -> dict[str, int]:
        """Copy numeric work counters from this exact cache operation owner.

        Returns:
            dict[str, int]: Inventory scans, metadata stats, sorts, evictions,
                writes, flushes, body reads, SHA-256 operations and hashed bytes.
                Fields ending in _ns measure cumulative hash/inventory/eviction,
                write/flush/replace/index nanoseconds. Index time contains
                recovery and overlaps inventory time; never sum these as wall time.
                Counters contain no filenames or authorization material.
        """
        return dict(self._thumbnail_stats)

    def _thumbnail_accounting(
        self, *, limit: int | None = None,
        cancelled: threading.Event | None = None,
    ) -> tuple[int, int, int]:
        """Read or configure physical accounting under both existing locks.

        Args:
            limit (int | None): Validated shared quota update, or read only.
            cancelled (threading.Event | None): Recovery cancellation signal.
        Returns:
            tuple[int, int, int]: Ready ordinary count, file bytes and byte quota.
        """
        with _CACHE_WRITE_LOCK:
            self._ensure_directory()
            lock_path = self._directory / '.media-lock'
            if self._path_is_reparse(lock_path):
                raise OSError('Unsafe media lock.')
            with lock_path.open('a+b') as lock:
                if os.name == 'nt':
                    lock.seek(0)
                    msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, _CACHE_LOCK_SPAN)
                else:
                    fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                index = self._open_thumbnail_index(cancelled)
                try:
                    if limit is not None:
                        index.set_limit(limit)
                    return index.snapshot()
                finally:
                    index.close()

    def receipt_current(
        self, receipt: PhotoCacheReceipt, *, key: str,
        gallery_key: str | None = None,
    ) -> bool:
        """Check a verified cache receipt against the current no-follow file.

        Args:
            receipt (PhotoCacheReceipt): Identity captured during verified read.
            key (str): Expected exact hash identity in this pool.
            gallery_key (str | None): Expected exact retained-gallery identity.

        Returns:
            bool: True when stable file identity and mutation timestamps match.
        """
        try:
            if (_CACHE_KEY_PATTERN.fullmatch(key) is None
                    or not self._valid_gallery_key(gallery_key)
                    or self._path_is_reparse(self._directory)):
                return False
            allowed = {self._entry_path(key)}
            if gallery_key is not None and self._gallery_is_pinned(gallery_key):
                retained = self._retained_entry_path(gallery_key, key)
                if retained is not None:
                    allowed.add(retained)
            if (receipt.path not in allowed
                    or not self._safe_entry_parent(receipt.path)
                    or self._path_is_reparse(receipt.path)):
                return False
            info = receipt.path.lstat()
            self._thumbnail_stats['stat_calls'] += 1
            with receipt.path.open('rb') as stream:
                opened = os.fstat(stream.fileno())
                return (stat.S_ISREG(info.st_mode)
                        and os.path.samestat(opened, info)
                        and os.path.samestat(opened, receipt.file_info)
                        and opened.st_size == receipt.file_info.st_size
                        and opened.st_mtime_ns == receipt.file_info.st_mtime_ns
                        and opened.st_ctime_ns == receipt.file_info.st_ctime_ns)
        except OSError:
            return False

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
    def private_key(
        issuer: str, user_id: int, gallery_id: int, image_id: int,
        thumbnail: bool,
    ) -> str:
        """Hash automatic thumbnails and opted-in full Admin media per account.

        Args:
            issuer (str): Canonical selected installation issuer.
            user_id (int): Positive server-verified account identity.
            gallery_id (int): Positive physical gallery ID.
            image_id (int): Positive direct photo ID.
            thumbnail (bool): Whether the entry is the thumbnail rendition.

        Returns:
            str: Lowercase SHA-256 entry key without source labels.

        Raises:
            ValueError: An identity component is malformed.
        """
        if (not isinstance(issuer, str) or not issuer
                or type(user_id) is not int or user_id <= 0
                or type(gallery_id) is not int or gallery_id <= 0
                or type(image_id) is not int or image_id <= 0
                or type(thumbnail) is not bool):
            raise ValueError('Invalid private gallery cache identity.')
        material = b'\0'.join((
            b'admin-preload-v1', issuer.encode('utf-8'),
            str(user_id).encode('ascii'), str(gallery_id).encode('ascii'),
            str(image_id).encode('ascii'),
            b'thumbnail' if thumbnail else b'detail',
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

    def has_private_preload_intent(
        self, issuer: str, user_id: int, gallery_id: int,
    ) -> bool:
        """Read explicit private-preload intent for one verified account.

        Args:
            issuer (str): Canonical selected installation issuer.
            user_id (int): Positive server-verified account identity.
            gallery_id (int): Positive physical gallery ID.

        Returns:
            bool: True only for an intact hash-only marker in the exact gallery.
        """
        if type(user_id) is not int or user_id <= 0:
            return False
        try:
            gallery_key = self.gallery_key(issuer, gallery_id)
            marker_key = hashlib.sha256(str(user_id).encode('ascii')).hexdigest()
        except (UnicodeError, ValueError):
            return False
        directory = self._gallery_directory(gallery_key)
        return (directory is not None and self._marker_is_valid(
            directory / ('.preload-' + marker_key),
        ))

    def set_private_preload_intent(
        self, issuer: str, user_id: int, gallery_id: int, enabled: bool,
    ) -> bool:
        """Persist explicit private-media reuse intent for one account/gallery.

        Args:
            issuer (str): Canonical selected installation issuer.
            user_id (int): Positive server-verified account identity.
            gallery_id (int): Positive physical gallery ID.
            enabled (bool): Whether a later viewer may use the private cache lane.

        Returns:
            bool: True when marker persistence or removal is confirmed.
        """
        if (type(user_id) is not int or user_id <= 0
                or type(enabled) is not bool):
            return False
        try:
            gallery_key = self.gallery_key(issuer, gallery_id)
            marker_key = hashlib.sha256(str(user_id).encode('ascii')).hexdigest()
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
                        gallery_key, create=enabled,
                    )
                    if directory is None:
                        return not enabled and self._gallery_absent_safely(
                            gallery_key,
                        )
                    marker = directory / ('.preload-' + marker_key)
                    if enabled:
                        return self._write_marker(marker)
                    try:
                        info = marker.lstat()
                    except FileNotFoundError:
                        return True
                    if not self._marker_is_valid(marker):
                        return False
                    return self._unlink_if_matches(marker, info)
        except (OSError, UnicodeError, ValueError, struct.error, sqlite3.Error):
            return False

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
        except (OSError, UnicodeError, ValueError, struct.error, sqlite3.Error):
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
    def for_current_user(
        cls, max_body_bytes: int, *, thumbnails: bool = False,
    ) -> 'GalleryMediaCache':
        """Resolve the shared per-user cache and choose one ordinary pool.

        Args:
            max_body_bytes (int): Largest accepted media body in bytes.
            thumbnails (bool): Use the separate thumbnail-only ordinary quota.

        Returns:
            GalleryMediaCache: Cache that degrades to misses when unavailable.
        """
        root = (os.environ.get('LOCALAPPDATA') or os.environ.get('XDG_CACHE_HOME')
                or str(Path.home() / '.cache'))
        return cls(
            Path(root) / 'PHPGalleryUploader' / 'photo-cache',
            max_body_bytes, thumbnails=thumbnails,
        )

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
                if self._thumbnail_pool and self._thumbnail_pool_is_unsafe():
                    return None
                pinned = (gallery_key is not None
                          and self._gallery_is_pinned(gallery_key))
                candidates: list[tuple[Path, bool]] = []
                if pinned and gallery_key is not None:
                    retained_path = self._retained_entry_path(gallery_key, key)
                    if retained_path is not None:
                        candidates.append((retained_path, False))
                    if self._thumbnail_pool:
                        legacy_retained = self._legacy_retained_entry_path(
                            gallery_key, key,
                        )
                        if legacy_retained is not None:
                            candidates.append((legacy_retained, True))
                candidates.append((self._entry_path(key), False))
                if self._thumbnail_pool:
                    candidates.append((self._ordinary_entry_path(key), True))
                for path, legacy in candidates:
                    entry = self._read_entry_path(path, now)
                    if entry is None:
                        continue
                    is_retained = (
                        path.parent.parent
                        == self._directory / _RETAINED_DIRECTORY
                    )
                    if pinned and gallery_key is not None and not is_retained:
                        promoted = self._promote_flat_entry(
                            key, gallery_key, path, entry,
                        )
                        return promoted
                    if pinned and gallery_key is not None:
                        self._remove_matching_flat_duplicate(
                            key, gallery_key, entry,
                        )
                    if legacy:
                        return self._migrate_legacy_entry(
                            key, gallery_key, path, entry,
                        ) or entry
                    return entry
                return None
        except (OSError, ValueError, struct.error, sqlite3.Error):
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
                if self._thumbnail_pool and self._thumbnail_pool_is_unsafe():
                    return False
                paths: list[Path] = []
                if (gallery_key is not None
                        and self._gallery_is_pinned(gallery_key)):
                    retained = self._retained_entry_path(gallery_key, key)
                    if retained is not None:
                        paths.append(retained)
                    if self._thumbnail_pool:
                        legacy_retained = self._legacy_retained_entry_path(
                            gallery_key, key,
                        )
                        if legacy_retained is not None:
                            paths.append(legacy_retained)
                paths.append(self._entry_path(key))
                if self._thumbnail_pool:
                    paths.append(self._ordinary_entry_path(key))
                return any(self._contains_path(path, etag) for path in paths)
        except (OSError, ValueError, struct.error, UnicodeDecodeError):
            return False

    def revalidate(
        self, key: str, entry: PhotoCacheEntry, now: int | None = None, *,
        gallery_key: str | None = None,
        receipts: list[PhotoCacheReceipt] | None = None,
    ) -> bool:
        """Refresh the validation stamp of the exact file accepted by a 304.

        Args:
            key (str): Lowercase SHA-256 photo cache identity.
            entry (PhotoCacheEntry): Verified file snapshot used for the validator.
            now (int | None): Validation time in Unix seconds, or None for the clock.
            gallery_key (str | None): Exact hashed gallery identity, or None
                for legacy flat-cache behavior.
            receipts (list[PhotoCacheReceipt] | None): Optional confirmed
                publication witnesses captured from the open verified file.

        Returns:
            bool: True only when the exact file has the confirmed new stamp.
        """
        if (not self._valid_gallery_key(gallery_key)
                or _CACHE_KEY_PATTERN.fullmatch(key) is None):
            return False
        try:
            with _CACHE_WRITE_LOCK:
                path = entry.path
                allowed_paths = {self._entry_path(key), self._ordinary_entry_path(key)}
                if gallery_key is not None:
                    retained = self._retained_entry_path(gallery_key, key)
                    legacy_retained = self._legacy_retained_entry_path(
                        gallery_key, key,
                    )
                    if retained is not None:
                        allowed_paths.add(retained)
                    if legacy_retained is not None:
                        allowed_paths.add(legacy_retained)
                if path not in allowed_paths:
                    return False
                if (self._thumbnail_pool and self._thumbnail_pool_is_unsafe()):
                    return False
                if self._path_is_reparse(self._directory):
                    return False
                if not self._safe_entry_parent(path):
                    return False
                info = path.lstat()
                if not stat.S_ISREG(info.st_mode) or not os.path.samestat(info, entry.file_info):
                    return False
                with path.open('rb') as stream:
                    opened = os.fstat(stream.fileno())
                    if (not os.path.samestat(opened, entry.file_info)
                            or opened.st_size != entry.file_info.st_size
                            or opened.st_mtime_ns != entry.file_info.st_mtime_ns
                            or opened.st_ctime_ns != entry.file_info.st_ctime_ns):
                        return False
                    current = int(time.time()) if now is None else now
                    self._stamp(path, stream.fileno(), entry.file_info, current, current)
                    updated = os.fstat(stream.fileno())
                    if (not os.path.samestat(updated, entry.file_info)
                            or updated.st_size != entry.file_info.st_size
                            or updated.st_mtime_ns != current * 10**9):
                        return False
                    if receipts is not None:
                        receipts.append(PhotoCacheReceipt(
                            path, updated, entry.etag, entry.mime,
                        ))
                    return True
        except (OSError, ValueError):
            return False

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
        receipts: list[PhotoCacheReceipt] | None = None,
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
            receipts (list[PhotoCacheReceipt] | None): Optional cache-confirmed
                file witnesses captured before releasing the mutation lock.

        Returns:
            bool: True when the entry was atomically stored.
        """
        body_digest = None
        if isinstance(body, bytes) and 0 < len(body) <= self._max_body_bytes:
            self._thumbnail_stats['hashes'] += 1
            self._thumbnail_stats['hash_bytes'] += len(body)
            hash_started = time.perf_counter_ns()
            body_digest = '"' + hashlib.sha256(body).hexdigest() + '"'
            self._thumbnail_stats['hash_ns'] += time.perf_counter_ns() - hash_started
        if (_CACHE_KEY_PATTERN.fullmatch(key) is None
                or not self._valid_gallery_key(gallery_key)
                or not isinstance(body, bytes)
                or not 0 < len(body) <= self._max_body_bytes
                or mime not in _MIME_TO_CODE
                or etag != body_digest):
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
                        key, body, mime, etag, now, pinned_key, receipts=receipts,
                    )
        except (OSError, ValueError, struct.error, sqlite3.Error):
            return False

    def _write_locked(
        self, key: str, body: bytes, mime: str, etag: str,
        now: int | None, gallery_key: str | None = None, *,
        pool_thumbnail: bool | None = None,
        protected_keys: frozenset[str] = frozenset(),
        receipts: list[PhotoCacheReceipt] | None = None,
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
            pool_thumbnail (bool | None): Explicit destination pool for a
                retained conversion, or None to use this instance's pool.
            protected_keys (frozenset[str]): Pool keys which eviction must keep.
            receipts (list[PhotoCacheReceipt] | None): Optional confirmed file
                witnesses; receives no receipt after a failed publication.

        Returns:
            bool: True when the complete cache object was committed.
        """
        current = int(time.time()) if now is None else now
        thumbnail_pool = (
            self._thumbnail_pool if pool_thumbnail is None else pool_thumbnail
        )
        if gallery_key is None and thumbnail_pool:
            try:
                self._ensure_pool_directory(True)
            except OSError:
                return False
        header = _CACHE_HEADER.pack(
            _CACHE_MAGIC, current, _MIME_TO_CODE[mime], etag[1:-1].encode('ascii'),
        )
        if gallery_key is None:
            try:
                admitted = self._make_room(
                    key, len(header) + len(body),
                    pool_thumbnail=thumbnail_pool,
                    protected_keys=protected_keys,
                )
            except BaseException:
                if self._pending_thumbnail_index is not None:
                    self._pending_thumbnail_index.close()
                    self._pending_thumbnail_index = None
                raise
            if not admitted:
                if self._pending_thumbnail_index is not None:
                    self._pending_thumbnail_index.close()
                    self._pending_thumbnail_index = None
                return False
            destination = self._pool_entry_path(key, thumbnail_pool)
        else:
            directory = self._gallery_directory(gallery_key, create=True)
            if directory is None or not self._gallery_is_pinned(gallery_key):
                return False
            destination = self._retained_entry_path(
                gallery_key, key, thumbnails=thumbnail_pool,
            )
            if destination is None:
                return False
        try:
            descriptor, temporary = tempfile.mkstemp(
                prefix=_CACHE_TEMP_PREFIX, suffix='.tmp', dir=destination.parent,
            )
        except BaseException:
            if self._pending_thumbnail_index is not None:
                self._pending_thumbnail_index.close()
                self._pending_thumbnail_index = None
            raise
        try:
            with os.fdopen(descriptor, 'wb') as stream:
                write_started = time.perf_counter_ns()
                stream.write(header)
                stream.write(body)
                self._thumbnail_stats['write_ns'] += time.perf_counter_ns() - write_started
                flush_started = time.perf_counter_ns()
                stream.flush()
                os.fsync(stream.fileno())
                self._thumbnail_stats['flush_ns'] += time.perf_counter_ns() - flush_started
                self._thumbnail_stats['flushes'] += 1
            # Initialize access and validation times with the same clock.
            os.utime(temporary, (current, current))
            replace_started = time.perf_counter_ns()
            os.replace(temporary, destination)
            self._thumbnail_stats['replace_ns'] += time.perf_counter_ns() - replace_started
            self._thumbnail_stats['writes'] += 1
            if self._pending_thumbnail_index is not None:
                index_started = time.perf_counter_ns()
                self._pending_thumbnail_index.publish(destination)
                self._thumbnail_stats['index_ns'] += time.perf_counter_ns() - index_started
            if receipts is not None:
                info = destination.lstat()
                if (not stat.S_ISREG(info.st_mode)
                        or self._path_is_reparse(destination)):
                    return False
                # Capture mutation timestamps through the same opened-file API
                # used by receipt_current. Windows path metadata may expose
                # creation time where fstat reports the file change time.
                with destination.open('rb') as published:
                    opened = os.fstat(published.fileno())
                if (not os.path.samestat(info, opened)
                        or not stat.S_ISREG(opened.st_mode)
                        or opened.st_size != len(header) + len(body)):
                    return False
                receipts.append(PhotoCacheReceipt(destination, opened, etag, mime))
        finally:
            self._unlink(Path(temporary))
            if self._pending_thumbnail_index is not None:
                self._pending_thumbnail_index.close()
                self._pending_thumbnail_index = None
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
    def _marker_is_valid(marker: Path) -> bool:
        """Validate a one-byte local preference marker without following links.

        Args:
            marker (Path): Fixed marker path below an already checked directory.

        Returns:
            bool: True when one stable regular file contains the marker byte.
        """
        try:
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
    def _write_marker(marker: Path) -> bool:
        """Atomically write a fixed one-byte preference marker.

        Args:
            marker (Path): Fixed marker path below a checked directory.

        Returns:
            bool: True after a valid marker is present.
        """
        return GalleryMediaCache._write_pin_marker(marker.parent, marker)

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
                return GalleryMediaCache._marker_is_valid(marker)
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
            return GalleryMediaCache._marker_is_valid(marker)
        except OSError:
            return False

    def _retained_entry_path(
        self, gallery_key: str, key: str, *,
        thumbnails: bool | None = None,
    ) -> Path | None:
        """Resolve one hash-only object path inside an existing safe directory.

        Args:
            gallery_key (str): Lowercase SHA-256 gallery directory key.
            key (str): Lowercase SHA-256 media object key.
            thumbnails (bool | None): Select new thumbnail-prefixed retention,
                or use this cache instance's pool when omitted.

        Returns:
            Path | None: Exact path or None for invalid identity or unsafe parent.
        """
        if _CACHE_KEY_PATTERN.fullmatch(key) is None:
            return None
        directory = self._gallery_directory(gallery_key)
        thumbnail_pool = (
            self._thumbnail_pool if thumbnails is None else thumbnails
        )
        prefix = _THUMBNAIL_RETAINED_PREFIX if thumbnail_pool else ''
        return None if directory is None else directory / (prefix + key + '.bin')

    def _legacy_retained_entry_path(
        self, gallery_key: str, key: str,
    ) -> Path | None:
        """Resolve one pre-pool retained filename for an exact thumbnail key.

        Args:
            gallery_key (str): Exact lowercase hashed gallery directory.
            key (str): Exact lowercase key already classified as a thumbnail.

        Returns:
            Path | None: Safe legacy unprefixed path or None when unavailable.
        """
        return self._retained_entry_path(
            gallery_key, key, thumbnails=False,
        )

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
                self._thumbnail_stats['reads'] += 1
                if (len(header) != _CACHE_HEADER.size
                        or not 0 < len(body) <= self._max_body_bytes):
                    return None
                magic, _written_at, mime_code, digest = _CACHE_HEADER.unpack(header)
                mime = _CODE_TO_MIME.get(mime_code)
                self._thumbnail_stats['hashes'] += 1
                self._thumbnail_stats['hash_bytes'] += len(body)
                hash_started = time.perf_counter_ns()
                actual_digest = hashlib.sha256(body).hexdigest().encode('ascii')
                self._thumbnail_stats['hash_ns'] += time.perf_counter_ns() - hash_started
                if (magic != _CACHE_MAGIC or mime is None
                        or actual_digest != digest):
                    return None
                current = int(time.time()) if now is None else now
                self._stamp(path, stream.fileno(), opened, current)
                opened = os.fstat(stream.fileno())
            return PhotoCacheEntry(
                body, mime, '"' + digest.decode('ascii') + '"',
                int(opened.st_mtime), opened, path,
            )
        except (OSError, ValueError, struct.error, sqlite3.Error):
            return None

    def _contains_path(self, path: Path, etag: str | None) -> bool:
        """Check one exact cache path using no-follow metadata and header bytes.

        Args:
            path (Path): Candidate cache path already derived from a hash key.
            etag (str | None): Optional expected strong content validator.

        Returns:
            bool: True when the stable regular file has valid bounded metadata.
        """
        try:
            if not self._safe_entry_parent(path):
                return False
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

    def _migrate_legacy_entry(
        self, key: str, gallery_key: str | None, source: Path,
        entry: PhotoCacheEntry,
    ) -> PhotoCacheEntry | None:
        """Copy one exact legacy thumbnail into its typed destination safely.

        Args:
            key (str): Exact lowercase photo rendition key.
            gallery_key (str | None): Current hashed gallery, when available.
            source (Path): Exact legacy flat or retained file that was verified.
            entry (PhotoCacheEntry): Verified source body and descriptor identity.

        Returns:
            PhotoCacheEntry | None: Verified migrated entry, or None if migration
                could not complete; callers keep using the verified source entry.
        """
        try:
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
                current = self._read_entry_path(source, entry.stored_at)
                if (current is None
                        or not os.path.samestat(current.file_info, entry.file_info)
                        or current.file_info.st_size != entry.file_info.st_size
                        or current.file_info.st_mtime_ns != entry.file_info.st_mtime_ns
                        or current.stored_at != entry.stored_at
                        or current.etag != entry.etag or current.mime != entry.mime
                        or current.body != entry.body):
                    return None
                pinned = (gallery_key is not None
                          and self._gallery_is_pinned(gallery_key))
                if pinned and gallery_key is not None:
                    destination_path = self._retained_entry_path(
                        gallery_key, key, thumbnails=True,
                    )
                    if destination_path is None:
                        return None
                    destination_gallery = gallery_key
                else:
                    destination_path = self._entry_path(key)
                    destination_gallery = None
                if destination_path == source:
                    return current
                if not self._write_locked(
                    key, current.body, current.mime, current.etag,
                    current.stored_at, destination_gallery,
                    pool_thumbnail=True,
                ):
                    return None
                migrated = self._read_entry_path(destination_path, current.stored_at)
                if (migrated is None or migrated.etag != current.etag
                        or migrated.body != current.body):
                    return None
                source_info = source.lstat()
                if (not os.path.samestat(source_info, current.file_info)
                        or source_info.st_size != current.file_info.st_size
                        or source_info.st_mtime_ns != current.file_info.st_mtime_ns
                        or not self._unlink_if_matches(source, current.file_info)):
                    return None
                return migrated
        except (OSError, ValueError, struct.error, sqlite3.Error):
            return None

    def _safe_entry_parent(self, path: Path) -> bool:
        """Confirm flat or retained ancestry is directory-only and no-reparse.

        Args:
            path (Path): Exact file path to inspect without following ancestors.

        Returns:
            bool: True only for the root, selected thumbnail child, or a
                verified retained gallery.
        """
        try:
            if self._path_is_reparse(self._directory):
                return False
            parent = path.parent
            if parent == self._directory:
                return True
            thumbnail_directory = self._thumbnail_directory()
            if parent == thumbnail_directory and self._thumbnail_pool:
                return (thumbnail_directory is not None
                        and self._safe_thumbnail_directory())
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
                            or current.file_info.st_size != entry.file_info.st_size
                            or current.file_info.st_mtime_ns != entry.file_info.st_mtime_ns
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
                    if not self._unlink_if_matches(flat_path, current.file_info):
                        return None
                    return retained
        except (OSError, ValueError, struct.error, sqlite3.Error):
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
                    filename = item.name[:-4] if item.name.endswith('.bin') else ''
                    key = filename
                    if filename.startswith(_THUMBNAIL_RETAINED_PREFIX):
                        key = filename[len(_THUMBNAIL_RETAINED_PREFIX):]
                    if _CACHE_KEY_PATTERN.fullmatch(key) is None:
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
            flat_path = self._entry_path(key)
            try:
                # Most retained hits have no ordinary duplicate. This
                # no-follow probe avoids lock and marker work; a present path
                # still gets the original locked hash and identity checks.
                flat_path.lstat()
            except FileNotFoundError:
                return
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
                    flat = self._read_entry_path(flat_path, retained.stored_at)
                    if (flat is None or flat.etag != retained.etag
                            or flat.mime != retained.mime):
                        return
                    self._unlink_if_matches(flat_path, flat.file_info)
        except (OSError, ValueError, struct.error, sqlite3.Error):
            return

    def _copy_retained_to_ordinary(
        self, retained: list[tuple[Path, os.stat_result]],
    ) -> bool:
        """Copy retained objects only after both pools can hold every target.

        Args:
            retained (list[tuple[Path, os.stat_result]]): Exact gallery entries
                and no-follow identities captured by the bounded scan.

        Returns:
            bool: True when every target is verified together. Failure leaves
                retained originals available for a later unpin attempt.
        """
        prepared: list[tuple[
            Path, str, os.stat_result, bool, str, str, int, bool,
        ]] = []
        pools: dict[bool, tuple[GalleryMediaCache, set[str], int, int]] = {}
        for path, expected in retained:
            filename = path.name[:-4]
            thumbnail_pool = filename.startswith(_THUMBNAIL_RETAINED_PREFIX)
            key = (filename[len(_THUMBNAIL_RETAINED_PREFIX):]
                   if thumbnail_pool else filename)
            if _CACHE_KEY_PATTERN.fullmatch(key) is None:
                return False
            entry = self._read_entry_path(path, None)
            if (entry is None or not os.path.samestat(entry.file_info, expected)):
                return False
            pool_cache = GalleryMediaCache(
                self._directory, self._max_body_bytes,
                thumbnails=thumbnail_pool,
            )
            destination = pool_cache._pool_entry_path(key, thumbnail_pool)
            ordinary = pool_cache._read_entry_path(destination, None)
            if (ordinary is not None and (ordinary.etag != entry.etag
                    or ordinary.mime != entry.mime
                    or ordinary.body != entry.body)):
                return False
            existing = ordinary is not None
            pool_cache, pool_keys, pool_bytes, pool_count = pools.get(
                thumbnail_pool, (pool_cache, set(), 0, 0),
            )
            pool_keys.add(key)
            pool_bytes += _CACHE_HEADER.size + len(entry.body)
            pool_count += 1
            pools[thumbnail_pool] = (
                pool_cache, pool_keys, pool_bytes, pool_count,
            )
            prepared.append((
                path, key, expected, thumbnail_pool, entry.etag, entry.mime,
                entry.stored_at, existing,
            ))
        eviction_plan: list[
            tuple[GalleryMediaCache, Path, os.stat_result]
        ] = []
        for thumbnail_pool, (pool_cache, keys, required_bytes, required_count) in pools.items():
            victims = pool_cache._plan_pool_capacity(
                keys, required_bytes, required_count, thumbnail_pool,
            )
            if victims is None:
                return False
            eviction_plan.extend(
                (pool_cache, path, info) for path, info in victims
            )
        if not all(
            pool_cache._unlink_if_matches(path, info)
            for pool_cache, path, info in eviction_plan
        ):
            return False
        created: list[tuple[GalleryMediaCache, Path, os.stat_result]] = []
        try:
            for (path, key, expected, thumbnail_pool, etag, mime, stored_at,
                 already_present) in prepared:
                pool_cache = pools[thumbnail_pool][0]
                destination = pool_cache._pool_entry_path(key, thumbnail_pool)
                if already_present:
                    continue
                current = self._read_entry_path(path, stored_at)
                if (current is None
                        or not os.path.samestat(current.file_info, expected)
                        or current.file_info.st_size != expected.st_size
                        or current.file_info.st_mtime_ns != expected.st_mtime_ns
                        or current.etag != etag or current.mime != mime):
                    raise OSError('Retained cache entry changed during unpin.')
                keys = frozenset(pools[thumbnail_pool][1])
                if not pool_cache._write_locked(
                    key, current.body, mime, etag, stored_at,
                    pool_thumbnail=thumbnail_pool, protected_keys=keys,
                ):
                    raise OSError('Ordinary cache quota rejected retained data.')
                created.append((pool_cache, destination, destination.lstat()))
            for (path, key, expected, thumbnail_pool, etag, _mime, _stored_at,
                 _already_present) in prepared:
                pool_cache = pools[thumbnail_pool][0]
                destination = pool_cache._pool_entry_path(key, thumbnail_pool)
                verified = pool_cache._read_entry_path(destination, None)
                source_now = self._read_entry_path(path, None)
                if (verified is None or verified.etag != etag
                        or verified.mime != _mime
                        or source_now is None or source_now.etag != etag
                        or source_now.mime != _mime
                        or not os.path.samestat(source_now.file_info, expected)):
                    raise OSError('Unpin destination verification failed.')
            return True
        except (OSError, ValueError, struct.error, sqlite3.Error):
            for pool_cache, copied_path, copied_info in created:
                pool_cache._unlink_if_matches(copied_path, copied_info)
            return False

    def _plan_pool_capacity(
        self, protected_keys: set[str], required_bytes: int,
        required_count: int, thumbnail_pool: bool,
    ) -> list[tuple[Path, os.stat_result]] | None:
        """Plan safe LRU evictions for all targets without changing the pool.

        Args:
            protected_keys (set[str]): All unpin targets in this ordinary pool.
            required_bytes (int): Encoded bytes for the complete target set.
            required_count (int): Entry count for the complete target set.
            thumbnail_pool (bool): Pool whose independent quota is checked.

        Returns:
            list[tuple[Path, os.stat_result]] | None: Exact LRU victims, or None
                when the quota cannot hold the complete unpin target set.
        """
        try:
            self._ensure_pool_directory(thumbnail_pool)
            max_bytes = MEDIA_CACHE_MAX_BYTES
            if thumbnail_pool:
                index = self._open_thumbnail_index()
                try:
                    max_bytes = index.snapshot()[2]
                finally:
                    index.close()
            max_entries = (MEDIA_THUMBNAIL_CACHE_MAX_ENTRIES if thumbnail_pool
                           else MEDIA_CACHE_MAX_ENTRIES)
            entries = self._inventory(thumbnail_pool)
            protected_paths = {
                self._pool_entry_path(key, thumbnail_pool)
                for key in protected_keys
            }
            eligible = [entry for entry in entries if entry[0] not in protected_paths]
            eligible.sort(key=lambda item: item[2])
            total = sum(size for _path, size, _atime in eligible)
            count = len(eligible)
            victims: list[tuple[Path, os.stat_result]] = []
            for path, size, _atime in eligible:
                if (total + required_bytes <= max_bytes
                        and count + required_count <= max_entries):
                    break
                info = path.lstat()
                if not stat.S_ISREG(info.st_mode):
                    return None
                victims.append((path, info))
                total -= size
                count -= 1
            if (total + required_bytes > max_bytes
                    or count + required_count > max_entries):
                return None
            return victims
        except (OSError, ValueError, sqlite3.Error):
            return None

    def _unlink_if_matches(
        self, path: Path, expected: os.stat_result,
    ) -> bool:
        """Remove a copied cache file only while its identity is unchanged.

        Args:
            path (Path): Exact cache object below the checked cache root.
            expected (os.stat_result): Identity observed after the owned copy.

        Returns:
            bool: True when the exact copy was removed or already absent.
        """
        try:
            if not self._safe_entry_parent(path):
                return False
            current = path.lstat()
            if (not os.path.samestat(current, expected)
                    or current.st_size != expected.st_size
                    or current.st_mtime_ns != expected.st_mtime_ns):
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
            Path: Cache data path in this instance's ordinary pool.
        """
        if _CACHE_KEY_PATTERN.fullmatch(key) is None:
            raise ValueError('Invalid gallery photo cache key.')
        return self._storage_directory() / (key + '.bin')

    def _pool_entry_path(self, key: str, thumbnails: bool) -> Path:
        """Return one hashed ordinary filename in an explicitly selected pool.

        Args:
            key (str): Lowercase SHA-256 cache identity.
            thumbnails (bool): Select the independent thumbnail child pool.

        Returns:
            Path: Exact validated path under the selected ordinary pool.
        """
        if _CACHE_KEY_PATTERN.fullmatch(key) is None:
            raise ValueError('Invalid gallery photo cache key.')
        directory = self._directory / 'thumbnails' if thumbnails else self._directory
        return directory / (key + '.bin')

    def _ordinary_entry_path(self, key: str) -> Path:
        """Return one validated legacy/main-pool filename below the root.

        Args:
            key (str): Lowercase SHA-256 cache identity.

        Returns:
            Path: Exact flat path retained by the pre-pool cache layout.
        """
        if _CACHE_KEY_PATTERN.fullmatch(key) is None:
            raise ValueError('Invalid gallery photo cache key.')
        return self._directory / (key + '.bin')

    def _thumbnail_directory(self) -> Path | None:
        """Return the fixed child thumbnail pool path below the canonical root.

        Returns:
            Path | None: Child directory path, or None for the main pool.
        """
        if not self._thumbnail_pool:
            return None
        return self._directory / 'thumbnails'

    def _storage_directory(self) -> Path:
        """Return the active ordinary pool while retaining canonical lock root.

        Returns:
            Path: Root for this instance's flat, quota-managed entries.
        """
        return self._thumbnail_directory() or self._directory

    def _ensure_directory(self) -> None:
        """Create and verify the leaf cache directory without following it.

        Returns:
            None: Raises an OS error when the cache root is unavailable.
        """
        self._directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        if self._path_is_reparse(self._directory) or not self._directory.is_dir():
            raise OSError('Gallery photo cache directory is unsafe.')
        thumbnail_directory = self._thumbnail_directory()
        if thumbnail_directory is not None:
            if not thumbnail_directory.exists():
                thumbnail_directory.mkdir(mode=0o700)
            if (self._path_is_reparse(thumbnail_directory)
                    or not thumbnail_directory.is_dir()):
                raise OSError('Gallery thumbnail cache directory is unsafe.')

    def _safe_thumbnail_directory(self) -> bool:
        """Validate the child thumbnail pool without traversing a reparse point.

        Returns:
            bool: True only for the expected regular child directory.
        """
        thumbnail_directory = self._directory / 'thumbnails'
        try:
            info = thumbnail_directory.lstat()
            return (stat.S_ISDIR(info.st_mode)
                    and not self._path_is_reparse(thumbnail_directory))
        except OSError:
            return False

    def _thumbnail_pool_is_unsafe(self) -> bool:
        """Detect an existing thumbnail child that must block legacy fallback.

        Returns:
            bool: True when the child exists but is not a real safe directory.
        """
        child = self._directory / 'thumbnails'
        try:
            info = child.lstat()
        except FileNotFoundError:
            return False
        except OSError:
            return True
        return (not stat.S_ISDIR(info.st_mode)
                or self._path_is_reparse(child))

    def _ensure_pool_directory(self, thumbnails: bool) -> None:
        """Create and validate the canonical root and selected ordinary pool.

        Args:
            thumbnails (bool): Create the thumbnail child when True.

        Returns:
            None: Raises when either required directory is unsafe.
        """
        self._directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        if self._path_is_reparse(self._directory) or not self._directory.is_dir():
            raise OSError('Gallery photo cache directory is unsafe.')
        if not thumbnails:
            return
        child = self._directory / 'thumbnails'
        if not child.exists():
            child.mkdir(mode=0o700)
        if self._path_is_reparse(child) or not child.is_dir():
            raise OSError('Gallery thumbnail cache directory is unsafe.')

    def _inventory(
        self, pool_thumbnail: bool | None = None,
    ) -> list[tuple[Path, int, float]]:
        """List only bounded regular cache files for eviction.

        Args:
            pool_thumbnail (bool | None): Scan a specific pool or this instance.

        Returns:
            list[tuple[Path, int, float]]: Path, byte size and access time.

        Raises:
            OSError: Directory traversal is unavailable or exceeds its work cap.
        """
        thumbnail_pool = (
            self._thumbnail_pool if pool_thumbnail is None else pool_thumbnail
        )
        directory_path = self._directory / 'thumbnails' if thumbnail_pool else self._directory
        if thumbnail_pool and not self._safe_thumbnail_directory():
            raise OSError('Gallery thumbnail cache directory is unsafe.')
        scan_limit = (
            MEDIA_THUMBNAIL_CACHE_SCAN_MAX_ENTRIES if thumbnail_pool
            else MEDIA_CACHE_SCAN_MAX_ENTRIES
        )
        entries: list[tuple[Path, int, float]] = []
        with os.scandir(directory_path) as directory:
            for index, item in enumerate(directory):
                if index >= scan_limit:
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

    def _make_room(
        self, key: str, new_size: int, *,
        pool_thumbnail: bool | None = None,
        protected_keys: frozenset[str] = frozenset(),
    ) -> bool:
        """Evict least recently accessed files before one atomic write.

        Args:
            key (str): Entry being replaced or added.
            new_size (int): Complete encoded file size in bytes.
            pool_thumbnail (bool | None): Pool to enforce, or this instance's.
            protected_keys (frozenset[str]): Hash keys excluded from eviction.

        Returns:
            bool: True when entry and byte limits admit the replacement.
        """
        thumbnail_pool = (
            self._thumbnail_pool if pool_thumbnail is None else pool_thumbnail
        )
        if thumbnail_pool:
            index = self._open_thumbnail_index()
            self._pending_thumbnail_index = index
            return index.make_room(
                key + '.bin', new_size, protected_keys, self._unlink_if_matches,
            )
        max_bytes = (
            MEDIA_THUMBNAIL_CACHE_MAX_BYTES if thumbnail_pool
            else MEDIA_CACHE_MAX_BYTES
        )
        max_entries = (
            MEDIA_THUMBNAIL_CACHE_MAX_ENTRIES if thumbnail_pool
            else MEDIA_CACHE_MAX_ENTRIES
        )
        entries = self._inventory(thumbnail_pool)
        target = self._pool_entry_path(key, thumbnail_pool)
        protected_paths = {
            self._pool_entry_path(protected, thumbnail_pool)
            for protected in protected_keys
        }
        entries = [entry for entry in entries if entry[0] != target]
        entries.sort(key=lambda entry: entry[2])
        total = sum(entry[1] for entry in entries)
        count = len(entries)
        for path, size, _accessed_at in entries:
            over_limit = (
                total + new_size > max_bytes
                or count + 1 > max_entries
            )
            if path in protected_paths:
                continue
            if over_limit and self._unlink(path):
                total -= size
                count -= 1
        return (total + new_size <= max_bytes
                and count + 1 <= max_entries)

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
