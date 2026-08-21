"""
Atomic state persistence and job/activity history for the Windows uploader.
"""

import json
import os
import shutil
import threading
import time
from pathlib import Path
from typing import Any, Dict, List, Optional

from .models import ActivityEvent, ImportJob

UPLOAD_STATE_SCHEMA_VERSION = 2
_STATE_LOCK = threading.RLock()


def quarantine_malformed(path: Path) -> Optional[Path]:
    """
    Preserve an unreadable state/config file for diagnostics.

    @return: Quarantine path when a copy was created, otherwise ``None``.
    """
    if not path.is_file():
        return None
    stamp = time.strftime("%Y%m%d-%H%M%S")
    target = path.with_name(path.name + ".malformed-" + stamp)
    try:
        shutil.copy2(str(path), str(target))
        return target
    except OSError:
        return None


def atomic_write_json(path: Path, payload: Dict[str, Any]) -> None:
    """
    Write JSON through a same-directory temporary file and atomic replace.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = path.with_name(path.name + ".tmp")
    encoded = json.dumps(payload, indent=2, ensure_ascii=False)
    with tmp_path.open("w", encoding="utf-8", newline="\n") as handle:
        handle.write(encoded)
        handle.flush()
        try:
            os.fsync(handle.fileno())
        except OSError:
            pass
    tmp_path.replace(path)


def default_upload_state() -> Dict[str, Any]:
    """Return the current upload-state document shape."""
    return {
        "schema_version": UPLOAD_STATE_SCHEMA_VERSION,
        "uploaded_paths": {},
        "uploaded_hashes": {},
        "failures": {},
        "jobs": {},
        "recent_events": [],
    }


def normalize_upload_state(payload: Any) -> Dict[str, Any]:
    """Normalize an older state document without invalidating upload history."""
    result = default_upload_state()
    if not isinstance(payload, dict):
        return result
    for key in ("uploaded_paths", "uploaded_hashes", "failures", "jobs"):
        value = payload.get(key)
        if isinstance(value, dict):
            result[key] = value
    events = payload.get("recent_events")
    if isinstance(events, list):
        result["recent_events"] = [row for row in events if isinstance(row, dict)]
    result["schema_version"] = UPLOAD_STATE_SCHEMA_VERSION
    return result


def load_upload_state(path: Path, quarantine_bad: bool = True) -> Dict[str, Any]:
    """Load and normalize upload state, quarantining malformed JSON when requested."""
    if not path.is_file():
        return default_upload_state()
    try:
        return normalize_upload_state(json.loads(path.read_text(encoding="utf-8")))
    except (OSError, json.JSONDecodeError, UnicodeError):
        if quarantine_bad:
            quarantine_malformed(path)
        return default_upload_state()


def merge_upload_sections(path: Path, replacement: Dict[str, Any]) -> None:
    """
    Replace watcher-owned sections while preserving redesign job/event sections.
    """
    with _STATE_LOCK:
        current = load_upload_state(path, quarantine_bad=True)
        for key in ("uploaded_paths", "uploaded_hashes", "failures"):
            value = replacement.get(key)
            if isinstance(value, dict):
                current[key] = value
        current["schema_version"] = UPLOAD_STATE_SCHEMA_VERSION
        atomic_write_json(path, current)


class JobStateStore:
    """
    Durable job and activity history stored in ``upload_state.json``.
    """

    def __init__(self, path: Path, history_limit: int = 500) -> None:
        """Create a job store for the shared upload-state document."""
        self.path = path
        self.history_limit = max(50, min(5000, int(history_limit or 500)))

    def _load(self) -> Dict[str, Any]:
        """Load the current shared state while holding the process lock."""
        return load_upload_state(self.path, quarantine_bad=True)

    def save_job(self, job: ImportJob) -> None:
        """Create or replace one persisted import job."""
        with _STATE_LOCK:
            data = self._load()
            data.setdefault("jobs", {})[job.id] = job.to_dict()
            atomic_write_json(self.path, data)

    def get_job(self, job_id: str) -> Optional[ImportJob]:
        """Return one persisted import job by id."""
        with _STATE_LOCK:
            row = self._load().get("jobs", {}).get(job_id)
        return ImportJob.from_dict(row) if isinstance(row, dict) else None

    def list_jobs(self) -> List[ImportJob]:
        """Return persisted jobs sorted newest first."""
        with _STATE_LOCK:
            jobs = self._load().get("jobs", {})
        result = [ImportJob.from_dict(row) for row in jobs.values() if isinstance(row, dict)] if isinstance(jobs, dict) else []
        result.sort(key=lambda job: job.created_at, reverse=True)
        return result

    def recoverable_jobs(self) -> List[ImportJob]:
        """Return jobs that still contain resumable or retryable work."""
        return [job for job in self.list_jobs() if job.recoverable()]

    def delete_job(self, job_id: str) -> None:
        """Remove one completed/stale job record without touching upload history."""
        with _STATE_LOCK:
            data = self._load()
            jobs = data.get("jobs", {})
            if isinstance(jobs, dict):
                jobs.pop(job_id, None)
            atomic_write_json(self.path, data)

    def append_event(self, event: ActivityEvent) -> None:
        """Append one redacted-safe activity event with a bounded history."""
        with _STATE_LOCK:
            data = self._load()
            events = data.get("recent_events", [])
            if not isinstance(events, list):
                events = []
            events.append(event.to_dict())
            data["recent_events"] = events[-self.history_limit :]
            atomic_write_json(self.path, data)

    def list_events(self) -> List[ActivityEvent]:
        """Return durable recent events in chronological order."""
        with _STATE_LOCK:
            rows = self._load().get("recent_events", [])
        return [ActivityEvent.from_dict(row) for row in rows if isinstance(row, dict)] if isinstance(rows, list) else []
