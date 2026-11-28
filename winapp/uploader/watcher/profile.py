# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/profile.py
# Module Type: Watcher Profile Persistence
# Purpose: Isolate factory-reset Watcher state from other uploader modes.
# Responsibilities:
#   - Persist the reset fence, Watcher-only preferences and chosen destination.
#   - Retire capture and one-scan journals without traversing source folders.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

import json
import math
import re
import stat
from dataclasses import dataclass, replace
from pathlib import Path

from ..flight_jobs import (
    FlightPreferences, SessionPlan, WorkspaceLease, connection_binding,
)
from ..state_store import atomic_write_json


WATCHER_PREFERENCE_TYPES = {
    'watched_folder': str,
    'scan_interval_seconds': float,
    'stable_seconds': float,
    'attach_sim_camera_metadata': bool,
    'delete_uploaded_files': bool,
    'watch_recursive': bool,
    'sync_utc_time': bool,
}
WATCHER_ONCE_LOCK = 'watcher-once.owner.lock'
WATCHER_PROFILE_FILE = 'watcher-profile.json'
WATCHER_CONTINUATION_FILES = ('watcher-restart.json', 'watcher-continuation.json')
# Purpose: Read only the small, local Watcher preference and admission record.
# Type: int; Units: bytes; Scope: one Watcher profile.
# Consumers: WatcherProfile.load.
# Rationale: 256 KiB accommodate escaped Unicode in a Windows long folder path.
WATCHER_PROFILE_MAX_BYTES = 256 * 1024


def _local_entry(path: Path, *, directory: bool = False) -> bool:
    """Check a named profile entry without following redirected or linked nodes.

    Args:
        path (Path): Fixed profile path or enumerated direct child.
        directory (bool): Whether an existing entry must be a directory.
    Returns:
        bool: True for a safe local entry, False for a missing entry.
    Raises:
        OSError: The entry is unsafe or its metadata cannot be read.
    """
    try:
        attributes = path.lstat()
    except FileNotFoundError:
        return False
    if getattr(attributes, 'st_file_attributes', 0) & stat.FILE_ATTRIBUTE_REPARSE_POINT:
        raise OSError('Watcher profile entry is redirected.')
    if directory:
        if not stat.S_ISDIR(attributes.st_mode):
            raise OSError('Watcher profile directory is unsafe.')
    elif not stat.S_ISREG(attributes.st_mode) or attributes.st_nlink != 1:
        raise OSError('Watcher profile file is unsafe.')
    return True


@dataclass(frozen=True)
class WatcherProfile:
    """Store post-reset settings and only one explicitly reselected destination.

    A missing profile uses the pre-reset shared settings. A pending profile
    blocks capture while older persistent writers are stopped and retired.
    """

    pending: bool
    binding: str
    preferences: dict[str, str | float | bool]

    @classmethod
    def load(cls, root: Path) -> "WatcherProfile | None":
        """Read the dedicated Watcher record independently of shared settings.

        Args:
            root (Path): The uploader's local flight workspace.
        Returns:
            WatcherProfile | None: Validated settings and fence, or legacy mode
                when the dedicated file has never been published.
        Raises:
            OSError: A present profile is redirected, unavailable or invalid.
        """
        path = root / WATCHER_PROFILE_FILE
        if not _local_entry(root, directory=True) or not _local_entry(path):
            return None
        try:
            with path.open('rb') as source:
                raw = source.read(WATCHER_PROFILE_MAX_BYTES + 1)
            if len(raw) > WATCHER_PROFILE_MAX_BYTES:
                raise ValueError('Watcher profile is oversized.')
            row = json.loads(raw)
            if not isinstance(row, dict) or set(row) != {
                'schema_version', 'pending', 'binding', 'preferences',
            }:
                raise ValueError('Watcher profile fields are invalid.')
            pending, binding, settings = (
                row['pending'], row['binding'], row['preferences'],
            )
            if (
                row['schema_version'] != 1  # Initial Watcher-only format.
                or type(pending) is not bool or not isinstance(binding, str)
                or (binding and (pending or not re.fullmatch('[0-9a-f]{64}', binding)))
                or not isinstance(settings, dict)
                or settings.keys() != WATCHER_PREFERENCE_TYPES.keys()
                or any(type(settings[key]) is not expected
                       for key, expected in WATCHER_PREFERENCE_TYPES.items())
                or not math.isfinite(settings['scan_interval_seconds'])
                or not math.isfinite(settings['stable_seconds'])
            ):
                raise ValueError('Watcher profile values are invalid.')
            return cls(pending, binding, settings)
        except (ValueError, RecursionError) as exc:
            raise OSError('Watcher profile could not be verified.') from exc

    def save(self, lease: WorkspaceLease) -> None:
        """Publish and verify one Watcher-only state under its workspace lock.

        Args:
            lease (WorkspaceLease): Exclusive owner of this flight workspace.
        Returns:
            None: The written settings and admission state were read back.
        Raises:
            OSError: Publication, file safety or readback could not be verified.
        """
        lease.verify()
        path = lease.root / WATCHER_PROFILE_FILE
        _local_entry(path)
        atomic_write_json(path, {
            'schema_version': 1,  # Initial Watcher-only format.
            'pending': self.pending,
            'binding': self.binding,
            'preferences': self.preferences,
        }, require_sync=True)
        lease.verify()
        if self.load(lease.root) != self:
            raise OSError('Watcher profile publication could not be confirmed.')


def discard_capture_queues(lease: WorkspaceLease) -> tuple[int, int]:
    """Retire all GUI capture plans and release their queue and restart files.

    Args:
        lease (WorkspaceLease): Flight workspace owner after all capture writers
            and recovery publishers in this process have stopped.
    Returns:
        tuple[int, int]: Count of plans closed and queue journals removed.
    Raises:
        OSError: An owned file cannot be safely inspected or retired. Repeating
            the operation after a partial write is safe.
    """
    lease.verify()
    for name in WATCHER_CONTINUATION_FILES:
        path = lease.root / name
        if _local_entry(path):
            path.unlink()
    plans_closed = journals_removed = 0
    for directory in lease.root.iterdir():
        info = directory.lstat()
        if stat.S_ISREG(info.st_mode):
            continue
        _local_entry(directory, directory=True)
        plan_path = directory / 'completion.json'
        queue_path = directory / 'watch-drain.json'
        backup = directory / 'completion.before-watcher-reset.json'
        has_plan = _local_entry(plan_path)
        has_queue = _local_entry(queue_path)
        plan = None
        if has_plan:
            try:
                plan = SessionPlan.load(directory, check_queue=False)
            except ValueError:
                # Preserve malformed evidence for support while replacing it
                # with a valid, local-only plan for the saved report browser.
                if _local_entry(backup):
                    raise OSError('Another invalid capture plan is already retained.')
                plan_path.rename(backup)
        if plan is None and _local_entry(backup):
            plan = SessionPlan(
                directory, connection_binding('', ''), (), False, local_only=True,
            )
        if plan is not None and not plan.completed:
            plan.persist_abandoned(lease)
            plans_closed += 1
        if has_queue:
            queue_path.unlink()
            journals_removed += 1
    preferences = FlightPreferences.load(lease.root)
    if preferences.recording != FlightPreferences().recording:
        replace(preferences, recording=FlightPreferences().recording).save(lease.root)
    lease.verify()
    return plans_closed, journals_removed


def discard_once_queues(profile: Path) -> int:
    """Forget one-scan queue journals while holding the dedicated one-scan lock.

    Args:
        profile (Path): Per-user uploader configuration directory under its
            one-scan lock. Import journals live under a different owner.
    Returns:
        int: Number of one-scan journals removed.
    Raises:
        OSError: A queue path is redirected or its file could not be removed.
    """
    root = profile / 'once-queues'
    if not _local_entry(root, directory=True):
        return 0
    count = 0
    for directory in root.iterdir():
        _local_entry(directory, directory=True)
        path = directory / 'watch-drain.json'
        if _local_entry(path):
            path.unlink()
            count += 1
    return count
