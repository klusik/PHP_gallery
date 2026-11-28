# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/workers/common.py
# Module Type: Windows Worker Support
# Purpose: Own shared local observation primitives used by Watcher workers.
# Responsibilities:
#   - Detect when copied files have stopped changing before upload admission.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Shared local observation primitives for Watcher workers."""

import time
from pathlib import Path
from typing import Dict, Tuple

from ..queue import WATCH_QUEUE_STABILITY_MIN_SECONDS

class FileStabilityTracker:
    """
    Detects when copied files have stopped changing.

    Files copied into a watched folder are often visible before the copy is
    complete. Uploading such a file would produce truncated images or server-side
    errors. This tracker waits until size and modification time remain unchanged
    for the configured duration.
    """

    def __init__(self, stable_seconds: float) -> None:
        """
        Create a stability tracker.

        Args:
            stable_seconds (float): Required unchanged duration before a file
                is.

        Returns:
            None: Create a stability tracker.
        """
        self.stable_seconds = max(WATCH_QUEUE_STABILITY_MIN_SECONDS, stable_seconds)
        self._seen: Dict[Path, Tuple[int, float, float]] = {}

    def stable(self, path: Path) -> bool:
        """
        Check whether a path has remained unchanged long enough.

        Args:
            path (Path): File path to inspect.

        Returns:
            bool: True when size and modification time have been stable for
                the.
        """
        try:
            stat = path.stat()
        except OSError:
            self._seen.pop(path, None)
            return False

        size = int(stat.st_size)
        mtime = float(stat.st_mtime)
        now = time.time()
        previous = self._seen.get(path)

        # First sighting, or a changed size/mtime, restarts the stability timer.
        if previous is None or previous[0] != size or previous[1] != mtime:
            self._seen[path] = (size, mtime, now)
            return False

        return now - previous[2] >= self.stable_seconds
