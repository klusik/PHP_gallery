# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_navdata.py
# Module Type: Flight Navigation Model
# Purpose: Retain bounded AIRAC-scoped provider waypoint coordinates.
# Responsibilities:
#   - Store only successfully imported OFP waypoint evidence.
#   - Preserve ambiguous coordinates instead of guessing waypoint identity.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Local waypoint evidence cache, independent of the Gallery database."""

import sqlite3
from pathlib import Path

from uploader.flight_simbrief import DispatchPlan

# Keep a useful multi-flight waypoint history with bounded disk growth.
# Type: int; Units: rows; Scope: private navigation cache.
# Consumers: remember_plan. Rationale: 20000 fixes retain many OFPs;
# session snapshots remain authoritative after cache eviction.
MAX_CACHED_FIXES = 20000


def remember_plan(root: Path, plan: DispatchPlan) -> None:
    """Cache provider-resolved fixes partitioned by their reported AIRAC cycle.

    Args:
        root (Path): Owned private flight workspace.
        plan (DispatchPlan): Successfully imported OFP with original coordinates.
    Returns:
        None: Upserts exact evidence; never resolves ambiguous bare identifiers.
    Raises:
        sqlite3.Error: The cache cannot be updated; session evidence is separate.
    """
    with sqlite3.connect(root / "navdata.sqlite") as connection:
        connection.execute("CREATE TABLE IF NOT EXISTS fixes (airac TEXT, ident TEXT, latitude REAL, longitude REAL, PRIMARY KEY (airac, ident, latitude, longitude))")
        for point in plan.points:
            connection.execute("INSERT OR REPLACE INTO fixes VALUES (?, ?, ?, ?)",
                (plan.airac, point.ident, point.latitude, point.longitude))
        connection.execute("DELETE FROM fixes WHERE rowid NOT IN (SELECT rowid FROM fixes ORDER BY rowid DESC LIMIT ?)",
            (MAX_CACHED_FIXES,))
