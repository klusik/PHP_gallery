# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Define uploader import and activity data models.
# Responsibilities:
#   - Represent local job state and activity records shared by uploader workflows.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/uploader/models.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
#
# Notes:
#   - Keep comments and docstrings intact when modifying this file.
"""
Data models used by the Windows uploader import and activity workflows.
"""

from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional, Union
import time
import uuid

# Decoded AI/JSON inputs may contain scalars, recursive lists or string-keyed
# dictionaries. Name that complete contract instead of using an opaque Any.
JsonValue = Union[
    str,
    int,
    float,
    bool,
    None,
    List["JsonValue"],
    Dict[str, "JsonValue"],
]

ITEM_ACTIVE_STATES = {
    "discovered",
    "validating",
    "staged",
    "hashing",
    "queued",
    "uploading",
}
ITEM_TERMINAL_STATES = {
    "confirmed",
    "skipped_duplicate",
    "skipped_unsupported",
    "failed_retryable",
    "failed_permanent",
    "cancelled",
}
ITEM_STATES = ITEM_ACTIVE_STATES | ITEM_TERMINAL_STATES
RECOVERABLE_ITEM_STATES = {
    "discovered",
    "validating",
    "staged",
    "hashing",
    "queued",
    "uploading",
    "failed_retryable",
    "cancelled",
}


@dataclass
class ActivityEvent:
    """
    One durable human-readable activity event.

    Args:
        level (str): Event severity such as info, warning, error, or success.
        message (str): Human-readable activity description.
        operation (str): Logical subsystem, for example import, watcher, or ai.
        filename (str): Optional file name associated with the event.
        job_id (str): Optional manual-import job identifier.
        timestamp (float): Unix timestamp captured when the event was created.
    """

    level: str
    message: str
    operation: str = "system"
    filename: str = ""
    job_id: str = ""
    timestamp: float = field(default_factory=time.time)

    def to_dict(self) -> Dict[str, Any]:
        """Return a JSON-compatible event dictionary."""
        return asdict(self)

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "ActivityEvent":
        """Build one event from persisted JSON data."""
        return cls(
            level=str(data.get("level", "info") or "info"),
            message=str(data.get("message", "") or ""),
            operation=str(data.get("operation", "system") or "system"),
            filename=str(data.get("filename", "") or ""),
            job_id=str(data.get("job_id", "") or ""),
            timestamp=float(data.get("timestamp", time.time()) or time.time()),
        )


@dataclass
class ImportItem:
    """
    Durable state for one source item in a manual import job.

    Args:
        id (str): Stable item identifier.
        path (str): Local source or staged file path.
        source_label (str): Original local path or archive-relative entry name.
        size (int): Source size in bytes.
        sha256 (str): Content hash when known.
        state (str): Current item state.
        attempt_count (int): Number of upload attempts made.
        reason (str): Human-readable skip/failure detail.
        server_result (Dict[str, Any]): Small server result object safe to
            persist locally.
        last_update (float): Unix timestamp of the last state transition.
        from_archive (bool): Whether the local path is temporary ZIP staging
            data.
        local_decode (str): local, server_only, failed, or unknown.
    """

    id: str
    path: str
    source_label: str
    size: int = 0
    sha256: str = ""
    state: str = "discovered"
    attempt_count: int = 0
    reason: str = ""
    server_result: Dict[str, Any] = field(default_factory=dict)
    last_update: float = field(default_factory=time.time)
    from_archive: bool = False
    local_decode: str = "unknown"

    @classmethod
    def create(cls, path: Path, source_label: Optional[str] = None, from_archive: bool = False) -> "ImportItem":
        """Create a new item with a stable random identifier."""
        try:
            size = int(path.stat().st_size)
        except OSError:
            size = 0
        return cls(
            id=uuid.uuid4().hex,
            path=str(path),
            source_label=source_label or str(path),
            size=size,
            from_archive=from_archive,
        )

    def transition(self, state: str, reason: str = "") -> None:
        """
        Move the item to a valid state and refresh its timestamp.

        Args:
            state (str): New durable item state from the allowed ITEM_STATES
                set.
            reason (str): Optional human-readable skip/failure reason; empty
                clears the previous reason.

        Returns:
            None: Move the item to a valid state and refresh its timestamp.

        Raises:
            ValueError: Raised for unknown states.
        """
        if state not in ITEM_STATES:
            raise ValueError("Unknown import item state: " + state)
        self.state = state
        self.reason = reason
        self.last_update = time.time()

    def to_dict(self) -> Dict[str, Any]:
        """Return a JSON-compatible item dictionary."""
        return asdict(self)

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "ImportItem":
        """Build one item from persisted JSON data."""
        state = str(data.get("state", "discovered") or "discovered")
        if state not in ITEM_STATES:
            state = "failed_retryable"
        return cls(
            id=str(data.get("id", "") or uuid.uuid4().hex),
            path=str(data.get("path", "") or ""),
            source_label=str(data.get("source_label", data.get("path", "")) or ""),
            size=max(0, int(data.get("size", 0) or 0)),
            sha256=str(data.get("sha256", "") or ""),
            state=state,
            attempt_count=max(0, int(data.get("attempt_count", 0) or 0)),
            reason=str(data.get("reason", "") or ""),
            server_result=data.get("server_result", {}) if isinstance(data.get("server_result", {}), dict) else {},
            last_update=float(data.get("last_update", time.time()) or time.time()),
            from_archive=bool(data.get("from_archive", False)),
            local_decode=str(data.get("local_decode", "unknown") or "unknown"),
        )


@dataclass
class ImportJob:
    """
    Durable manual-import job summary and item collection.

    Args:
        id (str): Stable job identifier.
        source_kind (str): files, folder, or zip.
        source_label (str): Human-readable source description.
        target_gallery_label (str): Target endpoint/gallery label without
            secrets.
        items (List[ImportItem]): Accepted item records.
        skipped (int): Number of unsupported/unsafe items rejected during
            preflight.
        bytes_total (int): Total accepted source bytes.
        bytes_sent (int): Confirmed source bytes completed or remotely
            confirmed.
        started_at (float): Unix timestamp for upload start.
        finished_at (float): Unix timestamp for terminal job completion.
        created_at (float): Unix timestamp for preflight creation.
        staging_dir (str): Optional ZIP staging directory owned by this job.
        source_zip (str): Optional original ZIP path.
        delete_source_zip_after_success (bool): Whether the original archive
            may be deleted after all accepted entries succeed.
    """

    id: str
    source_kind: str
    source_label: str
    target_gallery_label: str
    items: List[ImportItem] = field(default_factory=list)
    skipped: int = 0
    bytes_total: int = 0
    bytes_sent: int = 0
    started_at: float = 0.0
    finished_at: float = 0.0
    created_at: float = field(default_factory=time.time)
    staging_dir: str = ""
    source_zip: str = ""
    delete_source_zip_after_success: bool = False

    @classmethod
    def create(cls, source_kind: str, source_label: str, target_gallery_label: str) -> "ImportJob":
        """Create a new empty import job."""
        return cls(
            id=uuid.uuid4().hex,
            source_kind=source_kind,
            source_label=source_label,
            target_gallery_label=target_gallery_label,
        )

    def counts(self) -> Dict[str, int]:
        """Return aggregate counts derived from current item states."""
        result = {
            "discovered": len(self.items),
            "accepted": len(self.items),
            "uploaded": 0,
            "failed": 0,
            "cancelled": 0,
            "duplicates": 0,
            "active": 0,
            "skipped": self.skipped,
        }
        for item in self.items:
            if item.state == "confirmed":
                result["uploaded"] += 1
            elif item.state == "skipped_duplicate":
                result["duplicates"] += 1
            elif item.state in {"failed_retryable", "failed_permanent"}:
                result["failed"] += 1
            elif item.state == "cancelled":
                result["cancelled"] += 1
            elif item.state in ITEM_ACTIVE_STATES:
                result["active"] += 1
        return result

    def recoverable(self) -> bool:
        """Return whether the job contains work that can be resumed or retried."""
        if self.finished_at and not any(item.state in RECOVERABLE_ITEM_STATES for item in self.items):
            return False
        return any(item.state in RECOVERABLE_ITEM_STATES for item in self.items)

    def to_dict(self) -> Dict[str, Any]:
        """Return a JSON-compatible job dictionary."""
        data = asdict(self)
        data["items"] = [item.to_dict() for item in self.items]
        return data

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "ImportJob":
        """Build a job from persisted JSON data."""
        raw_items = data.get("items", [])
        items = [ImportItem.from_dict(row) for row in raw_items if isinstance(row, dict)] if isinstance(raw_items, list) else []
        return cls(
            id=str(data.get("id", "") or uuid.uuid4().hex),
            source_kind=str(data.get("source_kind", "files") or "files"),
            source_label=str(data.get("source_label", "") or ""),
            target_gallery_label=str(data.get("target_gallery_label", "") or ""),
            items=items,
            skipped=max(0, int(data.get("skipped", 0) or 0)),
            bytes_total=max(0, int(data.get("bytes_total", 0) or 0)),
            bytes_sent=max(0, int(data.get("bytes_sent", 0) or 0)),
            started_at=float(data.get("started_at", 0.0) or 0.0),
            finished_at=float(data.get("finished_at", 0.0) or 0.0),
            created_at=float(data.get("created_at", time.time()) or time.time()),
            staging_dir=str(data.get("staging_dir", "") or ""),
            source_zip=str(data.get("source_zip", "") or ""),
            delete_source_zip_after_success=bool(data.get("delete_source_zip_after_success", False)),
        )


@dataclass
class ImportPlan:
    """
    Result of manual import discovery and preflight.

    Args:
        job (ImportJob): Durable job record containing accepted files.
        unsupported (Dict[str, int]): Mapping of extension/reason labels to
            counts.
        unsafe_entries (List[str]): Archive entries rejected for
            path/symlink/limit reasons.
        duplicate_local (int): Number of files matching locally known hashes.
        duplicate_remote (int): Number of files confirmed by remote inventory.
        capability_notes (List[str]): Human-readable local media capability
            lines.
        warnings (List[str]): Additional preflight warnings.
    """

    job: ImportJob
    unsupported: Dict[str, int] = field(default_factory=dict)
    unsafe_entries: List[str] = field(default_factory=list)
    duplicate_local: int = 0
    duplicate_remote: int = 0
    capability_notes: List[str] = field(default_factory=list)
    warnings: List[str] = field(default_factory=list)

    def accepted_paths(self) -> List[Path]:
        """Return local accepted paths that can be passed to the upload worker."""
        return [Path(item.path) for item in self.job.items]
