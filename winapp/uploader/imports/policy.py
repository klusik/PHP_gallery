# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/imports/policy.py
# Module Type: Windows Import Policy
# Purpose: Own bounded import presentation and progress policy shared by workers and views.
# Responsibilities:
#   - Bound import item rows retained by progress snapshots and history views.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own bounded shared manual-import display policy."""

from typing import List

from ..models import (
    ImportJob, LEGACY_UPLOAD_UNCERTAIN_REASON, LINKED_UPLOAD_UNCERTAIN_REASON,
)

# Purpose: Bound retained item rows in progress and history projections.
# Type: int. Units: visible rows. Scope: the manual-import item detail views.
# Consumers: ManualUploadThread and WatcherApp.refresh_import_item_tree.
# Rationale: Preserve the existing 1000-item projection cap.
WATCHER_IMPORT_TREE_ROW_LIMIT = 1000


def protected_staging_job_ids(jobs: List[ImportJob]) -> List[str]:
    """Keep recoverable and uncertain linked ZIP jobs out of stale cleanup.

    Args:
        jobs (List[ImportJob]): Persisted jobs with their individual item states.

    Returns:
        List[str]: Job IDs whose staged sources must remain available.
    """
    return [
        job.id for job in jobs
        if job.recoverable() or any(
            item.reason in (LINKED_UPLOAD_UNCERTAIN_REASON, LEGACY_UPLOAD_UNCERTAIN_REASON)
            for item in job.items
        )
    ]
