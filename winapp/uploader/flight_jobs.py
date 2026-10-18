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
from datetime import datetime, timezone
from pathlib import Path
from typing import IO

from uploader.models import JsonValue
from uploader.state_store import atomic_write_json

# Preserve the four supported report editions in a stable task order.
# Type: tuple[str, ...]; Units: language codes; Scope: flight preferences.
# Consumers: FlightPreferences and SessionPlan.
# Rationale: all four editions are requested unless the user deselects them.
REPORT_LANGUAGES = ("en", "cs", "de", "sv")

# Purpose: Admit the same stable report identities as the gallery transport.
# Type: int; Units: characters; Scope: saved report revision catalog.
# Consumers: report_revisions. Rationale: the server and delivery client accept
# at most 80 ASCII identifier characters, including manual session prefixes.
REPORT_REVISION_MAX_CHARS = 80
# Purpose: Keep human PDF basenames below ordinary Windows path limits.
# Type: int; Units: characters; Scope: aircraft component in PDF filenames.
# Consumers: readable_pdf_name. Rationale: 64 characters retain aircraft identity
# while reserving space for date, edition and version in the profile directory.
REPORT_AIRCRAFT_NAME_CHARS = 64


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


@dataclass
class ReportRevision:
    """Describe one retained PDF revision and its available language files."""

    report_id: str
    files: dict[str, Path]
    modified_utc: str = ""


@dataclass
class ReportFileDetails:
    """Expose PDF page count and actual render time or a marked legacy file time."""

    pages: int | None
    created_utc: str
    estimated_time: bool


def report_file_details(pdf: Path) -> ReportFileDetails:
    """Inspect a retained PDF without modifying its bytes or private metadata.

    Args:
        pdf (Path): Confined report file selected by report_revisions.

    Returns:
        ReportFileDetails: Actual page count when readable, render timestamp
            when recorded, otherwise file modification time marked as estimated.

    Raises:
        OSError: The file cannot be inspected.
        ValueError: The selected file or private metadata redirects elsewhere.
    """
    from pypdf import PdfReader
    from pypdf.errors import PyPdfError

    if pdf.is_symlink() or pdf.parent.is_symlink():
        raise ValueError("Saved PDF cannot redirect.")
    stat = pdf.stat()
    created = datetime.fromtimestamp(stat.st_mtime, timezone.utc).isoformat()
    estimated = True
    metadata = pdf.parent / ".metadata"
    manifest = metadata / (pdf.name + ".manifest.json")
    if metadata.is_symlink() or manifest.is_symlink():
        raise ValueError("PDF metadata cannot redirect.")
    if manifest.is_file():
        recorded = read_document(manifest).get("created_utc")
        if isinstance(recorded, str):
            try:
                stamp = datetime.fromisoformat(recorded.replace("Z", "+00:00"))
                if stamp.tzinfo is not None:
                    created, estimated = stamp.astimezone(timezone.utc).isoformat(), False
            except ValueError:
                pass
    pages = None
    try:
        with pdf.open("rb") as stream:
            pages = len(PdfReader(stream, strict=False).pages)
    except (PyPdfError, ValueError, TypeError, RecursionError):
        # Old or damaged PDFs remain visible and openable for recovery. Unknown
        # page counts must not hide a retained document or claim it is valid.
        pass
    return ReportFileDetails(pages, created, estimated)


def _report_catalog(directory: Path) -> tuple[Path, dict[str, JsonValue]]:
    """Read the private filename journal after confining its storage paths.

    Args:
        directory (Path): Owned stopped-flight session directory.

    Returns:
        tuple[Path, dict[str, JsonValue]]: Catalog path and revision-ID mapping;
            each row contains number, language filenames and original sources.

    Raises:
        ValueError: Catalog paths redirect or the revision mapping is malformed.
    """
    reports = directory / "reports"
    metadata = reports / ".metadata"
    path = metadata / "catalog.json"
    for item in (directory, reports, metadata, path, path.with_name("catalog.json.tmp")):
        if item.is_symlink() or not item.resolve().is_relative_to(directory.resolve()):
            raise ValueError("PDF metadata cannot redirect outside its session.")
    rows = read_document(path).get("revisions", {}) if path.exists() else {}
    if not isinstance(rows, dict):
        raise ValueError("PDF filename catalog needs recovery.")
    for identity, row in rows.items():
        if not isinstance(row, dict) or not isinstance(row.get("files"), dict):
            raise ValueError("Invalid PDF revision catalog entry.")
        if re.fullmatch(r"[A-Za-z0-9_-]+", identity) is None:
            raise ValueError("Invalid PDF revision identity.")
        for language, name in row["files"].items():
            if (
                language not in REPORT_LANGUAGES or not isinstance(name, str)
                or Path(name).name != name or not name.lower().endswith(".pdf")
            ):
                raise ValueError("Unsafe PDF catalog filename.")
    return path, rows


def readable_pdf_name(recorded_utc: str, aircraft: str, language: str, number: int) -> str:
    """Name a PDF by recorded date, aircraft, edition and retained version.

    Args:
        recorded_utc (str): Recorded flight start in ISO UTC or an unknown label.
        aircraft (str): Recorded aircraft identity or a neutral unknown label.
        language (str): Supported report language code.
        number (int): One-based retained revision number within this flight.

    Returns:
        str: Windows-safe human-readable PDF basename without opaque identities.
    """
    # ISO date/time through whole seconds is the human identifier, not a digest.
    stamp = recorded_utc[:19].replace("T", " ").replace(":", "-")
    stamp = re.sub(r'[<>:"/\\|?*\x00-\x1f]', "_", stamp)
    aircraft = re.sub(r'[<>:"/\\|?*\x00-\x1f]', "_", aircraft)
    name = aircraft[:REPORT_AIRCRAFT_NAME_CHARS].rstrip(" .")
    return f"{stamp} UTC - {name} - {language.upper()} - v{number:02d}.pdf"


def _report_filename(directory: Path, language: str, number: int) -> str:
    """Adapt stored session metadata to the common readable PDF naming policy.

    Args:
        directory (Path): Owned session containing recorded UTC and aircraft.
        language (str): Supported report edition.
        number (int): One-based retained version number.

    Returns:
        str: Safe PDF basename shared by catalog migration and new generation.
    """
    meta = read_document(directory / "session.json")
    return readable_pdf_name(
        str(meta.get("started_utc") or "Flight"),
        str(meta.get("aircraft") or "Aircraft"), language, number,
    )


def organize_reports(directory: Path) -> None:
    """Flatten retained PDFs using a restartable filename journal and safe moves.

    Args:
        directory (Path): Owned session; raw data and completion plans stay intact.

    Returns:
        None: PDFs receive readable filenames in reports; JSON sidecars move to
            .metadata. Existing report identities and bytes remain unchanged.

    Raises:
        ValueError: Paths redirect, the journal is corrupt or destinations differ.
        OSError: Journal persistence or an atomic rename cannot complete.
    """
    path, rows = _report_catalog(directory)
    reports = directory / "reports"
    for revision in reversed(report_revisions(directory)):
        if revision.report_id not in rows:
            number = len(rows) + 1
            rows[revision.report_id] = {
                "number": number,
                "files": {code: _report_filename(directory, code, number) for code in revision.files},
                "sources": {code: str(pdf.relative_to(reports)) for code, pdf in revision.files.items()},
            }
    path.parent.mkdir(parents=True, exist_ok=True)
    # Journal every source/destination before the first move, so interrupted
    # migrations can finish without changing report identity or losing an edition.
    atomic_write_json(path, {"revisions": rows})
    for row in rows.values():
        sources = row.get("sources", {})
        if not isinstance(sources, dict):
            raise ValueError("Invalid PDF migration source mapping.")
        for code, name in row["files"].items():
            target = reports / name
            source = reports / str(sources.get(code, name))
            if (
                source.is_symlink() or source.parent.is_symlink() or target.is_symlink()
                or not source.resolve().is_relative_to(reports.resolve())
            ):
                raise ValueError("Unsafe retained PDF migration path.")
            if source != target and source.is_file():
                if target.exists():
                    raise ValueError("PDF destination already exists; original retained.")
                source.replace(target)
            manifest = source.with_suffix(source.suffix + ".manifest.json")
            destination = path.parent / (target.name + ".manifest.json")
            if manifest.is_symlink() or destination.is_symlink():
                raise ValueError("PDF manifest cannot redirect.")
            if manifest.is_file() and manifest != destination:
                if destination.exists() and manifest.read_bytes() != destination.read_bytes():
                    raise ValueError("Conflicting PDF metadata; original retained.")
                manifest.replace(destination)
            if source.parent != reports and source.parent.is_dir() and not any(source.parent.iterdir()):
                source.parent.rmdir()


def allocate_report_revision(
    directory: Path, report_id: str, languages: tuple[str, ...],
) -> ReportRevision:
    """Reserve readable flat filenames while retaining stable delivery identity.

    Args:
        directory (Path): Owned flight session with immutable recorded metadata.
        report_id (str): Existing automatic identity or a fresh manual identity.
        languages (tuple[str, ...]): Supported editions to reserve.

    Returns:
        ReportRevision: Stable identity with allocated language-to-PDF paths.

    Raises:
        ValueError: Existing catalog or requested edition/identity is unsafe.
        OSError: The revision journal cannot be saved atomically.
    """
    if not languages or any(code not in REPORT_LANGUAGES for code in languages):
        raise ValueError("Select supported PDF languages.")
    if re.fullmatch(r"[A-Za-z0-9_-]{1," + str(REPORT_REVISION_MAX_CHARS) + "}", report_id) is None:
        raise ValueError("Invalid report revision identity.")
    organize_reports(directory)
    path, rows = _report_catalog(directory)
    row = rows.get(report_id)
    if row is None:
        row = {"number": len(rows) + 1, "files": {}, "sources": {}}
        rows[report_id] = row
    number = row.get("number")
    if type(number) is not int or number < 1:
        raise ValueError("Invalid retained PDF version number.")
    for code in languages:
        row["files"].setdefault(code, _report_filename(directory, code, number))
    atomic_write_json(path, {"revisions": rows})
    return ReportRevision(report_id, {code: directory / "reports" / row["files"][code] for code in languages})


def report_file_label(directory: Path, revision: ReportRevision, language: str) -> str:
    """Give historical opaque filenames the same readable label as new PDFs.

    Args:
        directory (Path): Owned session whose recorded date/aircraft are shown.
        revision (ReportRevision): Retained revision and selected PDF mapping.
        language (str): Edition in the selected revision.

    Returns:
        str: Actual readable basename or its future normalized display name;
            this passive lookup never renames files.
    """
    filename = revision.files[language].name
    if " - v" in filename:
        return filename
    revisions = list(reversed(report_revisions(directory)))
    number = next((
        index + 1 for index, row in enumerate(revisions)
        if row.report_id == revision.report_id
    ), None)
    if number is None:
        raise ValueError("Saved PDF changed; refresh the file list.")
    return _report_filename(directory, language, number)


def report_revisions(directory: Path) -> list[ReportRevision]:
    """Catalog retained automatic and manual PDF editions without rendering.

    Args:
        directory (Path): Owned session directory with a reports child.

    Returns:
        list[ReportRevision]: Newest revisions first, with stable report identity,
            language-to-file mappings and UTC modification timestamp; absent
            reports produce an empty list.

    Raises:
        ValueError: A report path redirects outside its session or has an
            unsupported revision identity.
        OSError: Report files cannot be inspected.
    """
    reports = directory / "reports"
    if directory.is_symlink() or reports.is_symlink():
        raise ValueError("Flight reports cannot use redirected directories.")
    if not reports.exists():
        return []
    groups: dict[str, dict[str, Path]] = {}
    modified: dict[str, float] = {}
    _path, catalog = _report_catalog(directory)
    catalog_paths: set[Path] = set()
    for identity, row in catalog.items():
        files = {code: reports / name for code, name in row["files"].items()}
        for code, pdf in files.items():
            catalog_paths.add(pdf)
            if pdf.is_symlink():
                raise ValueError("Saved PDF cannot redirect.")
            if pdf.is_file():
                groups.setdefault(identity, {})[code] = pdf
                modified[identity] = max(modified.get(identity, 0.0), pdf.stat().st_mtime)
    paths = list(reports.glob("*.pdf")) + list(reports.glob("manual-*/*.pdf"))
    for path in paths:
        if path in catalog_paths:
            continue
        if " - v" in path.name:
            raise ValueError("Readable PDF has no identity catalog; restore reports/.metadata before upload.")
        if (
            path.is_symlink() or path.parent.is_symlink()
            or not path.resolve().is_relative_to(reports.resolve())
        ):
            raise ValueError("Flight PDF redirects outside its report directory.")
        if not path.is_file():
            continue
        if path.parent == reports:
            revision, separator, language = path.stem.rpartition("-")
            if not separator:
                continue
        else:
            revision = directory.name + "-" + path.parent.name
            language = path.stem
        if language not in REPORT_LANGUAGES:
            continue
        pattern = r"[A-Za-z0-9_-]{1," + str(REPORT_REVISION_MAX_CHARS) + r"}"
        if re.fullmatch(pattern, revision) is None:
            raise ValueError("Unsupported saved PDF revision identity.")
        groups.setdefault(revision, {})[language] = path
        modified[revision] = max(modified.get(revision, 0.0), path.stat().st_mtime)
    return [
        ReportRevision(
            identity, groups[identity],
            datetime.fromtimestamp(modified[identity], timezone.utc).isoformat(
                timespec="seconds"
            ),
        )
        for identity in sorted(
            groups, key=lambda item: (modified[item], item), reverse=True
        )
    ]


@dataclass
class SavedFlight:
    """Describe one local session for the passive report browser."""

    directory: Path
    started_utc: str = ""
    aircraft: str = ""
    sample_count: int = 0
    recording_ended: bool = False
    completed: bool = False
    report_count: int = 0
    error: str = ""


def saved_flights(root: Path) -> list[SavedFlight]:
    """List local sessions without analysis, native queries or remote requests.

    Args:
        root (Path): App-owned flight workspace, independent of the current key.

    Returns:
        list[SavedFlight]: Newest checkpoints first, with aircraft, stored sample
            count, ended/completed flags, local PDF count and recovery error.
            Corrupt or redirected sessions remain visible as error rows.
    """
    result: list[SavedFlight] = []
    if not root.exists():
        return result
    for directory in root.iterdir():
        if not directory.is_dir():
            continue
        row = SavedFlight(directory=directory)
        try:
            if directory.is_symlink() or directory.resolve().parent != root.resolve():
                raise ValueError("Flight session redirects outside its workspace.")
            if not (directory / "session.json").exists():
                continue
            meta = read_document(directory / "session.json")
            plan = SessionPlan.load(directory)
            row.started_utc = str(meta.get("started_utc", ""))
            row.aircraft = str(meta.get("aircraft") or "")
            count = meta.get("sample_count", 0)
            row.sample_count = count if type(count) is int and count >= 0 else 0
            row.recording_ended = meta.get("recording_ended") is True
            row.completed = plan.completed
            row.error = plan.error
            reports = directory / "reports"
            if reports.is_symlink() or not reports.resolve().is_relative_to(directory.resolve()):
                raise ValueError("Flight report folder redirects outside its session.")
            # Automatic PDFs are direct children; manual revisions add exactly
            # one directory level. Never traverse arbitrary nested directories.
            paths = list(reports.glob("*.pdf")) + list(reports.glob("manual-*/*.pdf"))
            row.report_count = sum(
                path.is_file() and not path.is_symlink()
                and path.resolve().is_relative_to(reports.resolve())
                for path in paths
            )
            state = directory / "manual-report.json"
            if state.exists():
                manual = read_document(state)
                if manual.get("phase") == "error":
                    row.error = str(manual.get("error", "Local PDF creation failed."))
        except (OSError, ValueError) as exc:
            row.error = f"{type(exc).__name__}: {exc}"
        result.append(row)
    return sorted(result, key=lambda row: row.started_utc, reverse=True)


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
