# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_jobs.py
# Module Type: Flight Persistence Model
# Purpose: Persist report preferences and connection-bound finalization receipts.
# Responsibilities:
#   - Keep recoverable session tasks separate from credentials and telemetry.
#   - Hold one cross-process owner for the flight workspace.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Durable flight task plans, preferences and exclusive workspace ownership."""

import hashlib
import json
import os
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import IO

from uploader.models import JsonValue
from uploader.state_store import atomic_write_json

# Preserve the four supported report editions in a stable task order.
# Type: tuple[str, ...]; Units: language codes; Scope: flight preferences.
# Consumers: FlightPreferences and SessionPlan.
# Rationale: all four editions are requested unless the user deselects them.
REPORT_LANGUAGES = ("en", "cs", "de", "sv")


def connection_binding(url: str, key: str) -> str:
    """Bind a session to its exact connection without retaining its credential.

    Args:
        url (str): Canonical upload endpoint captured at admission.
        key (str): Gallery-scoped high-entropy API key captured at admission.

    Returns:
        str: SHA-256 connection fingerprint; never an authentication token.
    """
    return hashlib.sha256((url + "\0" + key).encode("utf-8")).hexdigest()


def read_document(path: Path) -> dict[str, JsonValue]:
    """Read a local JSON object without treating corrupt tasks as completed.

    Args:
        path (Path): Owned preference or task document path.

    Returns:
        dict[str, JsonValue]: Decoded JSON object with documented owner fields.

    Raises:
        ValueError: The document is malformed, unreadable or a symbolic link.
    """
    try:
        if path.is_symlink():
            raise ValueError("Flight state cannot be a symbolic link.")
        value = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(value, dict):
            raise ValueError("Flight state must be a JSON object.")
        return value
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise ValueError("Flight state needs recovery: " + path.name) from exc


@dataclass
class FlightPreferences:
    """Persist report opt-out and language selection independently of UI drafts."""

    recording: bool = True
    reports: bool = True
    languages: tuple[str, ...] = REPORT_LANGUAGES
    ui_language: str = "en"

    @classmethod
    def load(cls, root: Path) -> "FlightPreferences":
        """Load strict booleans and supported languages, preserving old configs.

        Args:
            root (Path): Owned flight workspace beside the uploader config.

        Returns:
            FlightPreferences: Saved preferences or defaults for first use.

        Raises:
            ValueError: Existing preferences cannot be decoded safely.
        """
        path = root / "preferences.json"
        if not path.exists():
            return cls()
        row = read_document(path)
        selected = row.get("languages", list(REPORT_LANGUAGES))
        if not isinstance(selected, list):
            raise ValueError("Flight languages must be a list.")
        languages = tuple(code for code in REPORT_LANGUAGES if code in selected)
        language = str(row.get("ui_language", "en"))
        return cls(
            recording=row.get("recording", True) is True,
            reports=row.get("reports", True) is True,
            languages=languages,
            ui_language=language if language in REPORT_LANGUAGES else "en",
        )

    def save(self, root: Path) -> None:
        """Save only flight preferences without persisting unrelated UI edits.

        Args:
            root (Path): Owned flight workspace beside the uploader config.

        Returns:
            None: Atomic preferences replace succeeds.

        Raises:
            ValueError: Reports are enabled without a selected language.
        """
        if self.reports and not self.languages:
            raise ValueError("Select a report language or disable reports.")
        atomic_write_json(root / "preferences.json", {
            "schema_version": 1,  # Initial explicit storage format.
            "recording": self.recording,
            "reports": self.reports,
            "languages": list(self.languages),
            "ui_language": self.ui_language,
        })


@dataclass
class SessionPlan:
    """Own required report tasks and acknowledged receipts for one flight."""

    directory: Path
    binding: str
    languages: tuple[str, ...]
    reports_enabled: bool
    completed: bool = False
    local_only: bool = False
    abandoned: bool = False
    receipts: dict[str, str] = field(default_factory=dict)
    error: str = ""

    @property
    def session_id(self) -> str:
        """Return the opaque session directory identity.

        Returns:
            str: Session identifier used for report idempotency.
        """
        return self.directory.name

    def save(self) -> None:
        """Atomically persist required editions and confirmed delivery receipts.

        Returns:
            None: The completion document is replaced on durable storage.
        """
        atomic_write_json(self.directory / "completion.json", {
            "schema_version": 1,  # Initial explicit task-plan format.
            "binding": self.binding,
            "languages": list(self.languages),
            "reports_enabled": self.reports_enabled,
            "completed": self.completed,
            "local_only": self.local_only,
            "abandoned": self.abandoned,
            "receipts": self.receipts,
            "error": self.error,
        })

    @classmethod
    def load(cls, directory: Path) -> "SessionPlan":
        """Restore one task plan without restoring irreversible revoke consent.

        Args:
            directory (Path): Owned session directory containing completion.json.

        Returns:
            SessionPlan: Connection-bound recoverable task plan.

        Raises:
            ValueError: Identity, language, receipt or state data is malformed.
        """
        row = read_document(directory / "completion.json")
        binding = row.get("binding")
        languages = row.get("languages")
        receipts = row.get("receipts", {})
        if (
            not isinstance(binding, str)
            or re.fullmatch(r"[0-9a-f]{64}", binding) is None
            or not isinstance(languages, list)
            or any(code not in REPORT_LANGUAGES for code in languages)
            or (row.get("reports_enabled") is True and not languages)
            or not isinstance(receipts, dict)
            or any(
                code not in REPORT_LANGUAGES or not isinstance(value, str)
                or re.fullmatch(r"[0-9a-f]{64}", value) is None
                for code, value in receipts.items()
            )
        ):
            raise ValueError("Invalid connection-bound flight task plan.")
        for name in (
            "reports_enabled", "completed", "local_only", "abandoned"
        ):
            if not isinstance(row.get(name), bool):
                raise ValueError("Invalid flight completion state: " + name)
        if (
            row["completed"] and row["reports_enabled"]
            and not row["local_only"] and not row["abandoned"]
            and any(code not in receipts for code in languages)
        ):
            raise ValueError("Completed reports are missing delivery receipts.")
        queue_path = directory / "watch-drain.json"
        if row["completed"] and not row["abandoned"] and queue_path.exists():
            queue = read_document(queue_path)
            if queue.get("pending"):
                raise ValueError("Completed session has unfinished photographs.")
        return cls(
            directory=directory,
            binding=binding,
            languages=tuple(languages),
            reports_enabled=row["reports_enabled"],
            completed=row["completed"],
            local_only=row["local_only"],
            abandoned=row["abandoned"],
            receipts=dict(receipts),
            error=str(row.get("error", "")),
        )


def pending_sessions(root: Path, binding: str) -> list[SessionPlan]:
    """Recover unfinished plans before allowing new connection work or revoke.

    Args:
        root (Path): Owned flight workspace.
        binding (str): Fingerprint of the current saved connection.

    Returns:
        list[SessionPlan]: Unfinished matching sessions in stable identity order.

    Raises:
        ValueError: Any existing plan is corrupt and cannot be proven unrelated.
    """
    result: list[SessionPlan] = []
    if not root.exists():
        return result
    for directory in sorted(root.iterdir()):
        if directory.is_symlink():
            raise ValueError("Flight session symlinks require recovery.")
        if not directory.is_dir() or not (directory / "completion.json").exists():
            continue
        plan = SessionPlan.load(directory)
        if plan.binding == binding and not plan.completed:
            result.append(plan)
    return result


class WorkspaceLease:
    """Prevent two uploader instances from finalizing the same flight workspace."""

    def __init__(self, root: Path) -> None:
        """Acquire a nonblocking operating-system file lock.

        Args:
            root (Path): Shared per-user flight workspace.

        Returns:
            None: Owns the lock until release or process termination.

        Raises:
            RuntimeError: Another uploader instance owns the flight workspace.
        """
        root.mkdir(parents=True, exist_ok=True)
        self.handle: IO[bytes] | None = (root / "owner.lock").open("a+b")
        self.handle.seek(0)
        if not self.handle.read(1):
            self.handle.write(b"0")
            self.handle.flush()
        self.handle.seek(0)
        try:
            if os.name == "nt":
                import msvcrt
                msvcrt.locking(self.handle.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(
                    self.handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB
                )
        except OSError as exc:
            self.handle.close()
            self.handle = None
            raise RuntimeError(
                "Another uploader owns flight work; finish it there first."
            ) from exc

    def release(self) -> None:
        """Release workspace ownership after every related worker is stopped.

        Returns:
            None: Closes the OS-owned handle; repeated release is harmless.
        """
        if self.handle is not None:
            self.handle.close()
            self.handle = None


class ImportBindings:
    """Bind accepted manual jobs to credentials without changing legacy job rows."""

    def __init__(self, root: Path) -> None:
        """Select the private import-admission ledger.

        Args:
            root (Path): Owned flight workspace beside uploader state.

        Returns:
            None: Defers reading until an admission or blocker check.
        """
        self.path = root / "import-bindings.json"

    def entries(self) -> dict[str, str]:
        """Read admitted job IDs and their exact non-secret connection binding.

        Returns:
            dict[str, str]: Job ID to connection fingerprint for unresolved jobs.

        Raises:
            ValueError: Existing admission state is malformed.
        """
        if not self.path.exists():
            return {}
        row = read_document(self.path)
        entries = row.get("jobs")
        if not isinstance(entries, dict) or any(
            not isinstance(key, str) or not isinstance(value, str)
            or re.fullmatch(r"[0-9a-f]{64}", value) is None
            for key, value in entries.items()
        ):
            raise ValueError("Invalid accepted-import connection ledger.")
        return dict(entries)

    def bind(self, job_id: str, binding: str) -> None:
        """Record credential ownership before a manual upload worker starts.

        Args:
            job_id (str): Durable ImportJob identifier.
            binding (str): Current canonical endpoint/key fingerprint.

        Returns:
            None: Admission is atomically durable before any API call.

        Raises:
            ValueError: The job belongs to another saved connection.
        """
        entries = self.entries()
        if job_id in entries and entries[job_id] != binding:
            raise ValueError("This import belongs to another gallery/key.")
        entries[job_id] = binding
        atomic_write_json(self.path, {"schema_version": 1, "jobs": entries})

    def resolve(self, job_id: str) -> None:
        """Remove a confirmed-complete or explicitly abandoned import binding.

        Args:
            job_id (str): Existing durable accepted job identifier.

        Returns:
            None: Other gallery/key admissions remain unchanged.
        """
        entries = self.entries()
        entries.pop(job_id, None)
        atomic_write_json(self.path, {"schema_version": 1, "jobs": entries})
