# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/thumbnail_cache_index.py
# Module Type: Windows Client Cache Accounting
# Purpose: Account ordinary thumbnails without rescanning the pool per write.
# Responsibilities:
#   - Persist shared quota, indexed LRU order and constant-size totals.
#   - Reconcile interrupted mutations before admitting new ordinary bytes.
#   - Keep accounting hints separate from media integrity and authorization.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""SQLite accounting used only while the canonical media mutation lock is held."""

import os
import re
import sqlite3
import stat
import threading
import time
from pathlib import Path
from typing import Callable

# Purpose: Bound recovery independently of the number of writes in a preload.
# Type: int. Units: directory entries. Scope: one index reconciliation.
# Consumers: ThumbnailCacheIndex._reconcile. Rationale: 524288 observations
# accommodate the 262144-entry pool and interrupted files without an unbounded
# walk. Cancellation is checked for every entry; incomplete recovery admits no
# writes and publishes unknown statistics.
THUMBNAIL_INDEX_RECOVERY_LIMIT = 524_288
# Purpose: Bound one quota-reduction or replacement eviction operation.
# Type: int. Units: candidate files. Scope: one write under the media lock.
# Consumers: ThumbnailCacheIndex.make_room. Rationale: 4096 candidates limit
# lock ownership when a quota is drastically reduced; at most one additional
# pass follows lazy access-order refresh, then later writes continue.
THUMBNAIL_INDEX_EVICTION_LIMIT = 4096
# Purpose: Keep SQLite contention inside the existing canonical lock policy.
# Type: float. Units: seconds. Scope: one accounting connection admission.
# Consumers: ThumbnailCacheIndex.__init__. Rationale: 0 seconds adds no hidden
# second wait behind the existing nonblocking cross-process media lock.
THUMBNAIL_INDEX_SQL_TIMEOUT_SECONDS = 0.0

_KEY = re.compile(r'[a-f0-9]{64}\.bin')
_INDEX_NAME = '.thumbnail-index.sqlite3'


class ThumbnailCacheIndex:
    """Maintain durable physical file accounting, never an access proof.

    The caller holds the existing cross-process media lock for the entire
    lifetime. A committed dirty bit precedes filesystem changes. Dirty state or
    a changed directory fingerprint requires recovery before quota admission.
    Recovery streams metadata into SQLite and never hashes media bodies.
    """

    def __init__(
        self, root: Path, directory: Path, default_limit: int,
        max_entries: int, safe_path: Callable[[Path], bool],
        counters: dict[str, int],
        cleanup: Callable[[Path, os.stat_result], bool],
        cancelled: threading.Event | None = None,
    ) -> None:
        """Open/recover accounting under the caller's canonical media lock.

        Args:
            root (Path): Safe canonical media root holding the SQLite file.
            directory (Path): Safe ordinary thumbnail directory only.
            default_limit (int): Factory byte quota for a new shared index.
            max_entries (int): Independent ordinary thumbnail entry quota.
            safe_path (Callable[[Path], bool]): Reject reparse/symlink paths.
            counters (dict[str, int]): Caller-owned numeric operation counters.
            cleanup (Callable[[Path, os.stat_result], bool]): Existing safe
                compare-and-unlink owner for abandoned temporary cache files.
            cancelled (threading.Event | None): Optional recovery cancellation.
        Returns:
            None: Opens a ready index or raises without authorizing any media.
        """
        self.directory = directory
        self.counters = counters
        self.max_entries = max_entries
        self._safe_path = safe_path
        self._cleanup = cleanup
        self._cancelled = cancelled
        path = root / _INDEX_NAME
        for candidate in (path, Path(str(path) + '-journal'),
                          Path(str(path) + '-wal'), Path(str(path) + '-shm')):
            if not safe_path(candidate):
                raise OSError('Unsafe thumbnail accounting path.')
        self.connection = sqlite3.connect(
            path, timeout=THUMBNAIL_INDEX_SQL_TIMEOUT_SECONDS,
        )
        try:
            if not safe_path(path):
                raise OSError('Unsafe thumbnail accounting path.')
            self.connection.execute('PRAGMA journal_mode=DELETE')
            self.connection.execute('PRAGMA synchronous=FULL')
            self.connection.executescript('''
                CREATE TABLE IF NOT EXISTS state (
                    singleton INTEGER PRIMARY KEY CHECK(singleton=1),
                    bytes INTEGER NOT NULL, count INTEGER NOT NULL,
                    quota INTEGER NOT NULL, dirty INTEGER NOT NULL,
                    directory_stamp TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS entries (
                    name TEXT PRIMARY KEY, size INTEGER NOT NULL,
                    accessed INTEGER NOT NULL
                );
                CREATE INDEX IF NOT EXISTS thumbnail_lru
                    ON entries(accessed, name);
            ''')
            self.connection.execute(
                'INSERT OR IGNORE INTO state VALUES(1,0,0,?,1,?)',
                (default_limit, ''),
            )
            self.connection.commit()
            dirty, stamp = self.connection.execute(
                'SELECT dirty,directory_stamp FROM state WHERE singleton=1',
            ).fetchone()
            if dirty or stamp != self._directory_stamp():
                self._reconcile()
        except BaseException:
            self.connection.close()
            raise

    def close(self) -> None:
        """Release the accounting connection without clearing dirty recovery.

        Returns:
            None: Rolls back any incomplete SQL transaction and closes SQLite.
        """
        self.connection.close()

    def _directory_stamp(self) -> str:
        """Identify the current ordinary directory generation without a walk.

        Returns:
            str: Device/inode and nanosecond modification/change timestamps.
        """
        info = self.directory.lstat()
        if not stat.S_ISDIR(info.st_mode) or not self._safe_path(self.directory):
            raise OSError('Unsafe thumbnail directory.')
        self.counters['stat_calls'] += 1
        return ':'.join(str(value) for value in (
            info.st_dev, info.st_ino, info.st_mtime_ns, info.st_ctime_ns,
        ))

    def _reconcile(self) -> None:
        """Stream a bounded, cancelable metadata recovery into durable totals.

        Returns:
            None: Publishes ready totals only after the complete safe traversal.
                Interruption preserves dirty state and refuses quota admission.
        """
        self.mark_dirty()
        self.counters['inventory_scans'] += 1
        started = time.perf_counter_ns()
        count = used = 0
        try:
            self.connection.execute('DELETE FROM entries')
            with os.scandir(self.directory) as entries:
                for ordinal, item in enumerate(entries):
                    if (ordinal >= THUMBNAIL_INDEX_RECOVERY_LIMIT
                            or (self._cancelled is not None
                                and self._cancelled.is_set())):
                        raise OSError('Thumbnail recovery incomplete.')
                    temporary = (
                        item.name.startswith('.media-')
                        and item.name.endswith('.tmp')
                    )
                    if _KEY.fullmatch(item.name) is None and not temporary:
                        continue
                    path = self.directory / item.name
                    info = path.lstat()
                    self.counters['stat_calls'] += 1
                    if (not stat.S_ISREG(info.st_mode)
                            or not self._safe_path(path)):
                        raise OSError('Unsafe thumbnail entry.')
                    if temporary:
                        # Canonical process lock excludes every active writer;
                        # these files survived an interrupted publication.
                        if not self._cleanup(path, info):
                            raise OSError('Thumbnail recovery incomplete.')
                        continue
                    self.connection.execute(
                        'INSERT INTO entries VALUES(?,?,?)',
                        (item.name, info.st_size, info.st_atime_ns),
                    )
                    count += 1
                    used += info.st_size
            self.connection.execute(
                'UPDATE state SET bytes=?,count=?,dirty=0,directory_stamp=? '
                'WHERE singleton=1', (used, count, self._directory_stamp()),
            )
            self.connection.commit()
        except BaseException:
            self.connection.rollback()
            raise
        finally:
            self.counters['inventory_ns'] += time.perf_counter_ns() - started

    def snapshot(self) -> tuple[int, int, int]:
        """Read constant-size count, physical encoded bytes and shared quota.

        Returns:
            tuple[int, int, int]: Ordinary thumbnail count, file bytes including
                fixed cache headers, and effective byte quota; excludes pins.
        """
        count, used, quota = self.connection.execute(
            'SELECT count,bytes,quota FROM state WHERE singleton=1',
        ).fetchone()
        return int(count), int(used), int(quota)

    def set_limit(self, limit: int) -> None:
        """Persist the per-user quota shared by every instance and process.

        Args:
            limit (int): Already validated ordinary thumbnail byte quota.
        Returns:
            None: Saves the limit; subsequent admissions enforce it. Existing
                files remain intact until normal quota-driven eviction.
        """
        self.connection.execute(
            'UPDATE state SET quota=? WHERE singleton=1', (limit,),
        )
        self.connection.commit()

    def mark_dirty(self) -> None:
        """Commit recovery intent before any filesystem replacement or unlink.

        Returns:
            None: A process crash cannot expose an undercount as ready evidence.
        """
        self.connection.execute('UPDATE state SET dirty=1 WHERE singleton=1')
        self.connection.commit()

    def make_room(
        self, name: str, new_size: int, protected: frozenset[str],
        unlink: Callable[[Path, os.stat_result], bool],
        *, refresh_access: bool = True,
    ) -> bool:
        """Evict indexed LRU candidates with fresh no-follow file identities.

        Args:
            name (str): Hash-only target filename including the bin suffix.
            new_size (int): Complete incoming file bytes including its header.
            protected (frozenset[str]): Hash keys preserved by unpin conversion.
            unlink (Callable[[Path, os.stat_result], bool]): Existing safe
                compare-and-unlink primitive under the canonical media lock.
            refresh_access (bool): Permit one second ordered pass after lazy
                atime refresh; false prevents recursive unbounded work.
        Returns:
            bool: True if both quotas admit the target; False after bounded
                pressure or unsafe/mutated filesystem observations.
        """
        count, used, limit = self.snapshot()
        if new_size > limit:
            return False
        existing = self.connection.execute(
            'SELECT size FROM entries WHERE name=?', (name,),
        ).fetchone()
        if existing is not None:
            count -= 1
            used -= existing[0]
        self.mark_dirty()
        access_changed = False
        candidates = self.connection.execute(
            'SELECT name,size,accessed FROM entries ORDER BY accessed,name',
        )
        for ordinal, (candidate, size, accessed) in enumerate(candidates):
            if used + new_size <= limit and count + 1 <= self.max_entries:
                return True
            if ordinal >= THUMBNAIL_INDEX_EVICTION_LIMIT:
                return False
            if candidate == name or candidate[:-4] in protected:
                continue
            path = self.directory / candidate
            try:
                info = path.lstat()
                self.counters['stat_calls'] += 1
            except FileNotFoundError:
                info = None
            if info is not None:
                if (not stat.S_ISREG(info.st_mode)
                        or not self._safe_path(path) or info.st_size != size):
                    return False
                # Reads update atime without changing the directory generation.
                # Avoid evicting a newly touched file before its older peers.
                if info.st_atime_ns > accessed:
                    self.connection.execute(
                        'UPDATE entries SET accessed=? WHERE name=?',
                        (info.st_atime_ns, candidate),
                    )
                    access_changed = True
                    continue
                started = time.perf_counter_ns()
                removed = unlink(path, info)
                self.counters['eviction_ns'] += time.perf_counter_ns() - started
                if not removed:
                    return False
                self.counters['evictions'] += 1
            self.connection.execute('DELETE FROM entries WHERE name=?', (candidate,))
            self.connection.execute(
                'UPDATE state SET bytes=bytes-?,count=count-1 WHERE singleton=1',
                (size,),
            )
            used -= size
            count -= 1
        if used + new_size <= limit and count + 1 <= self.max_entries:
            return True
        if access_changed and refresh_access:
            return self.make_room(
                name, new_size, protected, unlink, refresh_access=False,
            )
        return False

    def publish(self, path: Path) -> None:
        """Account one actual atomic replacement and seal its generation.

        Args:
            path (Path): Exact newly committed ordinary thumbnail destination.
        Returns:
            None: Commits actual target size and O(1) running totals. Any error
                leaves the prior dirty marker committed for crash recovery.
        """
        info = path.lstat()
        self.counters['stat_calls'] += 1
        if not stat.S_ISREG(info.st_mode) or not self._safe_path(path):
            raise OSError('Unsafe thumbnail publication.')
        previous = self.connection.execute(
            'SELECT size FROM entries WHERE name=?', (path.name,),
        ).fetchone()
        self.connection.execute(
            'INSERT OR REPLACE INTO entries VALUES(?,?,?)',
            (path.name, info.st_size, info.st_atime_ns),
        )
        self.connection.execute(
            'UPDATE state SET bytes=bytes+?,count=count+? WHERE singleton=1',
            (info.st_size - (previous[0] if previous else 0),
             0 if previous else 1),
        )
        self.connection.execute(
            'UPDATE state SET dirty=0,directory_stamp=? WHERE singleton=1',
            (self._directory_stamp(),),
        )
        self.connection.commit()
