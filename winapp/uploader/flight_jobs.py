# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_jobs.py
# Module Type: Flight Persistence Model
# Purpose: Persist report preferences and connection-bound finalization receipts.
# Responsibilities:
#   - Keep recoverable session tasks separate from credentials and telemetry.
#   - Hold one cross-process owner for the flight workspace.
#   - Keep ordinary restart consent separate from updater continuation metadata.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Durable flight task plans, preferences and exclusive workspace ownership."""

import hashlib
import json
import os
import re
import stat
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import IO, Literal

if os.name == "nt":
    import msvcrt
else:
    import fcntl

from uploader.models import ImportJob, JsonValue
from uploader.flight_simbrief import load_dispatch
from uploader.windows_file_identity import WindowsDirectoryAnchor
from uploader.state_store import (
    _check_accepted_owner_path,
    _reject_mode_history_constant,
    _strict_mode_history_float,
    _strict_mode_history_object,
    atomic_write_json,
    accepted_work_owner,
    JobStateStore,
    load_upload_state_for_mutation,
    read_import_binding_entries,
    validate_import_job_update,
)

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
# Keep dispatch identity useful without crowding Windows PDF basenames.
# Type: int; Units: ASCII characters; Scope: one new PDF flight-number token.
# Consumers: readable_pdf_name. Rationale: 16 retains airline/number/suffix text.
REPORT_FLIGHT_NUMBER_CHARS = 16

# Refuse oversized accepted-import evidence instead of inferring an empty queue.
# Type: int; Units: bytes; Scope: strict read-only import binding admission.
IMPORT_BINDINGS_MAX_BYTES = 16 * 1024 * 1024

# Purpose: Bound the non-secret, one-use update continuation document.
# Type: int. Units: bytes. Scope: one flight workspace's update checkpoint.
# Consumers: WatcherContinuation.load. Rationale: 16384 bytes cover the fixed
# identity fields without allowing an unbounded local metadata read.
WATCHER_CONTINUATION_MAX_BYTES = 16384
# Purpose: Expire automatic continuation after an interrupted installation.
# Type: float. Units: seconds. Scope: one explicitly prepared update.
# Consumers: WatcherContinuation.load. Rationale: 86400 seconds allow an
# overnight installation without turning an old update into startup consent.
WATCHER_CONTINUATION_LIFETIME_SECONDS = 86400.0


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
            if (queue.get("pending")
                    or queue.get('schema_version') == 2 and queue.get('completed') is not True):
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
    """Retain identity, language paths and an optional one-based display version.

    Number comes from the existing catalog or its passive legacy ordering;
    exposing it never renames files or changes delivery identities.
    """

    report_id: str
    files: dict[str, Path]
    modified_utc: str = ""
    number: int | None = None


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
            Missing pypdf leaves pages unknown while keeping the file usable.

    Raises:
        OSError: The file cannot be inspected.
        ValueError: The selected file or private metadata redirects elsewhere.
    """
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
        from pypdf import PdfReader
        from pypdf.errors import PyPdfError
    except ImportError:
        # Source-mode repair must retain access to existing PDFs. This permanent
        # fallback belongs to the catalog, not the renderer: unknown page counts
        # are sufficient for opening/uploading unchanged verified report files.
        return ReportFileDetails(pages, created, estimated)
    try:
        with pdf.open("rb") as stream:
            pages = len(PdfReader(stream, strict=False).pages)
    except (PyPdfError, ValueError, TypeError, RecursionError):
        # Old or damaged PDFs remain visible and openable for recovery. Unknown
        # page counts must not hide a retained document or claim it is valid.
        pass
    return ReportFileDetails(pages, created, estimated)


def _report_catalog(directory: Path) -> tuple[Path, dict[str, JsonValue]]:
    """Read confined filename metadata with positive numeric version identities.

    Args:
        directory (Path): Owned stopped-flight session directory.

    Returns:
        tuple[Path, dict[str, JsonValue]]: Catalog path and revision-ID mapping;
            each row has positive int number, files mapping language strings to
            filename strings and optional sources mapping languages to originals.

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
        if type(row.get("number")) is not int or row["number"] < 1:
            raise ValueError("Invalid PDF revision number; restore its catalog.")
        for language, name in row["files"].items():
            if (
                language not in REPORT_LANGUAGES or not isinstance(name, str)
                or Path(name).name != name or not name.lower().endswith(".pdf")
            ):
                raise ValueError("Unsafe PDF catalog filename.")
    return path, rows


def readable_pdf_name(
    recorded_utc: str, aircraft: str, language: str, number: int,
    *, flight_number: str = "", origin: str = "", destination: str = "",
) -> str:
    """Name a PDF by date, optional OFP identity, aircraft, edition and version.

    Args:
        recorded_utc (str): Recorded flight start in ISO UTC or an unknown label.
        aircraft (str): Recorded aircraft identity or a neutral unknown label.
        language (str): Supported report language code.
        number (int): One-based retained revision number within this flight.
        flight_number (str): Optional provider airline/flight number for a new PDF.
        origin (str): Optional four-character departure ICAO from its OFP.
        destination (str): Optional four-character arrival ICAO from its OFP.

    Returns:
        str: Windows-safe human-readable PDF basename without opaque identities.
    """
    # ISO date/time through whole seconds is the human identifier, not a digest.
    stamp = recorded_utc[:19].replace("T", " ").replace(":", "-")
    stamp = re.sub(r'[<>:"/\\|?*\x00-\x1f]', "_", stamp)
    aircraft = re.sub(r'[<>:"/\\|?*\x00-\x1f]', "_", aircraft)
    name = aircraft[:REPORT_AIRCRAFT_NAME_CHARS].rstrip(" .")
    identity = re.sub(r"[^A-Za-z0-9]", "", flight_number).upper()[:REPORT_FLIGHT_NUMBER_CHARS]
    airports = [code.upper() for code in (origin, destination)
                if re.fullmatch(r"[A-Za-z0-9]{4}", code)]
    tokens = [f"{stamp} UTC"]
    if identity:
        tokens.append(identity)
    if airports:
        tokens.append("-".join(airports))
    tokens.extend((name, language.upper(), f"v{number:02d}.pdf"))
    return " - ".join(tokens)


def _report_filename(
    directory: Path, language: str, number: int, *, include_dispatch: bool = False,
) -> str:
    """Adapt stored session metadata to the common readable PDF naming policy.

    Args:
        directory (Path): Owned session containing recorded UTC and aircraft.
        language (str): Supported report edition.
        number (int): One-based retained version number.
        include_dispatch (bool): Add current OFP identity only for newly reserved
            PDFs; legacy migration must not relabel old evidence with a new plan.

    Returns:
        str: Safe PDF basename shared by catalog migration and new generation.
    """
    meta = read_document(directory / "session.json")
    dispatch = load_dispatch(directory) if include_dispatch else None
    return readable_pdf_name(
        str(meta.get("started_utc") or "Flight"),
        str(meta.get("aircraft") or "Aircraft"), language, number,
        flight_number=dispatch.flight_number if dispatch else "",
        origin=dispatch.origin if dispatch else "",
        destination=dispatch.destination if dispatch else "",
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
        row["files"].setdefault(code, _report_filename(
            directory, code, number, include_dispatch=True,
        ))
    atomic_write_json(path, {"revisions": rows})
    return ReportRevision(report_id,
        {code: directory / "reports" / row["files"][code] for code in languages},
        number=number)


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
    if revision.number is not None:
        return _report_filename(directory, language, revision.number)
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
        list[ReportRevision]: Newest file modification first, with stable report
            identity, language paths, UTC timestamp and catalog version number
            (passive legacy ordering when uncataloged); absent reports return [].

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
    ordered = sorted(groups, key=lambda item: (modified[item], item), reverse=True)
    legacy_numbers = {identity: index + 1
                      for index, identity in enumerate(reversed(ordered))}
    return [
        ReportRevision(
            identity, groups[identity],
            datetime.fromtimestamp(modified[identity], timezone.utc).isoformat(
                timespec="seconds"
            ),
            number=catalog[identity]["number"] if identity in catalog
                else legacy_numbers[identity],
        )
        for identity in ordered
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
    """Prevent two uploader instances from owning the same local workspace."""

    def __init__(self, root: Path, *, lock_name: str = "owner.lock") -> None:
        """Acquire a nonblocking operating-system file lock.

        The Windows directory handle stays open for the full lease, so the
        direct workspace cannot normally be renamed or removed while owned.
        Both workspace and lock-file identity are checked before accessing
        sentinel bytes and after native acquisition. This is cooperative and
        cannot stop older clients or qualify arbitrary ancestor swaps.

        Args:
            root (Path): Per-user workspace whose lock is shared by its writers.
            lock_name (str): Single lock filename within that workspace.

        Returns:
            None: Owns the lock until release or process termination.

        Raises:
            RuntimeError: Another uploader owns the workspace, or its
                native identity/lock file cannot be established safely.
        """
        root.mkdir(parents=True, exist_ok=True)
        self.root = root
        self.handle: IO[bytes] | None = None
        self.directory: WindowsDirectoryAnchor | None = None
        try:
            if Path(lock_name).name != lock_name or lock_name in ("", ".", ".."):
                raise ValueError("Workspace lock filename is invalid.")
            self.lock_path = root / lock_name
            named_root = root.lstat()
            if (not stat.S_ISDIR(named_root.st_mode) or
                    (os.name == "nt"
                     and (getattr(named_root, "st_file_attributes", 0)
                          & stat.FILE_ATTRIBUTE_REPARSE_POINT))):
                raise ValueError("Flight workspace is redirected.")
            root_info = root.stat()
            self._root_identity = (root_info.st_dev, root_info.st_ino)
            if os.name == "nt":
                self.directory = WindowsDirectoryAnchor(root)
                self.directory.verify()
            flags = (
                os.O_RDWR | os.O_CREAT | getattr(os, "O_BINARY", 0)
                | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
            )
            descriptor = os.open(self.lock_path, flags, stat.S_IRUSR | stat.S_IWUSR)
            try:
                self.handle = os.fdopen(descriptor, "r+b")
            except BaseException:
                os.close(descriptor)
                raise
            self.verify()
            # A second Windows process cannot read the first owner's locked
            # byte. Treat the read refusal as a busy workspace, not an
            # unhandled PermissionError before the ownership error boundary.
            self.handle.seek(0)
            if not self.handle.read(1):
                self.handle.write(b"0")
                self.handle.flush()
            self.handle.seek(0)
            if os.name == "nt":
                msvcrt.locking(self.handle.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                fcntl.flock(
                    self.handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB,
                )
            self.verify()
        except (OSError, ValueError) as exc:
            self.release()
            raise RuntimeError(
                "Another uploader owns flight work or its native identity "
                "is unavailable; finish it there first."
            ) from exc

    def verify(self) -> None:
        """Check that the workspace and named lock still match the open lease.

        Returns:
            None: The named directory and lock retain their original identity.

        Raises:
            ValueError: The workspace, handle or lock identity changed.
            OSError: The native identity cannot be checked.
        """
        if self.handle is None:
            raise ValueError("The workspace lease is closed.")
        _check_accepted_owner_path(
            self.root, self._root_identity, self.lock_path, self.handle,
        )
        if self.directory is not None:
            self.directory.verify()

    def release(self) -> None:
        """Release workspace ownership after every related worker is stopped.

        Returns:
            None: Closes the OS-owned lock and pinned directory handles.
                Repeated release is harmless.
        """
        try:
            if self.handle is not None:
                self.handle.close()
        finally:
            self.handle = None
            if self.directory is not None:
                directory = self.directory
                self.directory = None
                directory.close()


class WatcherContinuationError(ValueError):
    """Identify a safe Watcher continuation validation failure.

    Args:
        reason (str): Fixed local category without checkpoint contents.
        message (str): Existing compatible validation message.

    Returns:
        None: Creates a ValueError-compatible diagnostic with a safe reason.
    """

    def __init__(self, reason: str, message: str) -> None:
        """Store one fixed reason and preserve the existing error message.

        Args:
            reason (str): Fixed category selected by the validation owner.
            message (str): Existing human-readable validation message.

        Returns:
            None: Initializes the ValueError and its safe reason attribute.
        """
        self.reason = reason
        super().__init__(message)


@dataclass(frozen=True)
class WatcherContinuation:
    """Bind ordinary or updater restarts without credentials.

    Args:
        session_id (str): Existing opaque session directory name.
        binding (str): Exact non-secret upload-target fingerprint.
        profile_hash (str): Digest of the original flight workspace spelling.
        source_version (str): Version preparing the admitted checkpoint.
        target_version (str): Authorized restart version; equal to the source
            for ordinary Quit, or selected for an updater installation.
        update_session (str): Helper nonce for updater flow; empty only while
            preparing. Ordinary Quit uses its own one-use restart nonce under
            the same schema and an isolated checkpoint filename.
        mode (Literal['legacy', 'linked']): Original admitted upload mode.
        contextual (bool): Whether the original capture used a Watcher key.
        created (float): Unix time of the explicit continuation decision.
        phase (Literal['preparing', 'prepared', 'claimed', 'consumed', 'cancelled']):
            Durable checkpoint ownership and consumption state.
        approved_scope (str): Exact original linked upload scope, or empty for
            the independent saved API-key mode.
        legacy_source_pid (int): Explicitly prepared old-client bridge PID,
            or zero for the ordinary updater protocol.
    """

    session_id: str
    binding: str
    profile_hash: str
    source_version: str
    target_version: str
    update_session: str
    mode: Literal['legacy', 'linked']
    contextual: bool
    created: float
    phase: Literal['preparing', 'prepared', 'claimed', 'consumed', 'cancelled']
    approved_scope: str = ''
    legacy_source_pid: int = 0

    @staticmethod
    def profile_digest(root: Path) -> str:
        """Identify the same lexical workspace used by its native owner.

        Args:
            root (Path): Flight workspace whose lease is already verified.

        Returns:
            str: SHA-256 digest without exposing the user's profile path.
        """
        spelling = os.path.normcase(os.path.abspath(str(root)))
        return hashlib.sha256(spelling.encode('utf-8')).hexdigest()

    def payload(self) -> dict[str, JsonValue]:
        """Encode only fixed checkpoint fields, never upload credentials.

        Returns:
            dict[str, JsonValue]: Schema version, session/profile/binding
                fingerprints, source/target versions, helper nonce, mode,
                original approved scope, contextual flag, decision time,
                phase and legacy bridge PID.
        """
        return {
            'schema_version': 1,  # First explicit update continuation format.
            'session_id': self.session_id,
            'binding': self.binding,
            'profile_hash': self.profile_hash,
            'source_version': self.source_version,
            'target_version': self.target_version,
            'update_session': self.update_session,
            'mode': self.mode,
            'contextual': self.contextual,
            'created': self.created,
            'phase': self.phase,
            'approved_scope': self.approved_scope,
            'legacy_source_pid': self.legacy_source_pid,
        }

    @classmethod
    def from_payload(
        cls, row: dict[str, JsonValue], root: Path,
    ) -> 'WatcherContinuation':
        """Validate the complete fixed-format checkpoint for this profile.

        Args:
            row (dict[str, JsonValue]): Exact fields emitted by payload().
            root (Path): Workspace selected by the current native lease.

        Returns:
            WatcherContinuation: Validated non-secret checkpoint data.

        Raises:
            WatcherContinuationError: Checkpoint validation failed with a
                fixed safe ``reason`` category.
        """
        required = {
            'schema_version', 'session_id', 'binding', 'profile_hash',
            'source_version', 'target_version', 'update_session', 'mode',
            'contextual', 'created', 'phase', 'legacy_source_pid',
            'approved_scope',
        }
        if (set(row) != required or type(row.get('schema_version')) is not int
                or row['schema_version'] != 1):
            raise WatcherContinuationError(
                'malformed_shape', 'Update continuation metadata is malformed.',
            )
        for name, pattern in (
            ('session_id', r'[0-9a-f]{32}'),
            ('binding', r'[0-9a-f]{64}'),
            ('profile_hash', r'[0-9a-f]{64}'),
            ('source_version', r'[0-9]+\.[0-9]+\.[0-9]+'),
            ('target_version', r'[0-9]+\.[0-9]+\.[0-9]+'),
            ('update_session', r'(?:[0-9a-f]{32})?'),
        ):
            value = row[name]
            if not isinstance(value, str) or re.fullmatch(pattern, value) is None:
                raise WatcherContinuationError(
                    'malformed_identity',
                    'Update continuation identity is malformed.',
                )
        mode, phase = row['mode'], row['phase']
        created, legacy_pid = row['created'], row['legacy_source_pid']
        scope = row['approved_scope']
        if not isinstance(scope, str):
            raise WatcherContinuationError(
                'invalid_scope', 'Update continuation scope is malformed.',
            )
        if mode == 'linked':
            from uploader.app_linking import UPLOAD_SCOPE, scope_base

            if scope_base(scope) != UPLOAD_SCOPE:
                raise WatcherContinuationError(
                    'invalid_scope', 'Update continuation scope is malformed.',
                )
        elif scope:
            raise WatcherContinuationError(
                'invalid_scope',
                'An API-key continuation cannot carry linked scope.',
            )
        if mode not in ('legacy', 'linked'):
            raise WatcherContinuationError(
                'malformed_identity', 'Update continuation identity is malformed.',
            )
        if (phase not in (
                    'preparing', 'prepared', 'claimed', 'consumed', 'cancelled',
                )
                or (phase != 'preparing' and not row['update_session'])):
            raise WatcherContinuationError(
                'invalid_phase',
                'Update continuation does not match this profile.',
            )
        if (not isinstance(row['contextual'], bool)
                or type(created) not in (int, float)
                or not isinstance(created, (int, float))
                or not 0.0 <= float(created) < float('inf')
                or type(legacy_pid) is not int or legacy_pid < 0
                or (mode == 'legacy' and row['contextual'])
                or (legacy_pid and (mode != 'legacy' or row['contextual']))):
            raise WatcherContinuationError(
                'malformed_identity',
                'Update continuation does not match this profile.',
            )
        if row['profile_hash'] != cls.profile_digest(root):
            raise WatcherContinuationError(
                'profile_mismatch',
                'Update continuation does not match this profile.',
            )
        return cls(
            session_id=str(row['session_id']), binding=str(row['binding']),
            profile_hash=str(row['profile_hash']),
            source_version=str(row['source_version']),
            target_version=str(row['target_version']),
            update_session=str(row['update_session']), mode=mode,
            contextual=row['contextual'], created=float(created), phase=phase,
            approved_scope=scope,
            legacy_source_pid=legacy_pid,
        )

    @classmethod
    def load(
        cls, lease: WorkspaceLease, *, ordinary_restart: bool = False,
    ) -> 'WatcherContinuation | None':
        """Read one bounded restart checkpoint under native profile ownership.

        Args:
            lease (WorkspaceLease): Current exclusive flight workspace lease.
            ordinary_restart (bool): Select the ordinary-restart checkpoint
                instead of the updater continuation file.

        Returns:
            WatcherContinuation | None: Verified checkpoint, or None if absent.

        Raises:
            WatcherContinuationError: Checkpoint validation failed with a
                fixed safe ``reason`` category.
            ValueError: The native workspace lease no longer verifies.
            OSError: Its physical identity or bytes cannot be read safely.
        """
        lease.verify()
        filename = (
            'watcher-restart.json' if ordinary_restart
            else 'watcher-continuation.json'
        )
        path = lease.root / filename
        try:
            named = path.lstat()
        except FileNotFoundError:
            return None
        if (not stat.S_ISREG(named.st_mode) or named.st_nlink != 1
                or path.is_symlink()
                or (getattr(named, 'st_file_attributes', 0)
                    & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0))):
            raise WatcherContinuationError(
                'redirected', 'Update continuation metadata is redirected.',
            )
        with path.open('rb') as source:
            opened = os.fstat(source.fileno())
            if (opened.st_dev, opened.st_ino) != (named.st_dev, named.st_ino):
                raise WatcherContinuationError(
                    'replaced', 'Update continuation metadata was replaced.',
                )
            payload = source.read(WATCHER_CONTINUATION_MAX_BYTES + 1)
        after = path.lstat()
        if ((after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns)
                != (named.st_dev, named.st_ino, named.st_size, named.st_mtime_ns)
                or after.st_nlink != 1
                or len(payload) > WATCHER_CONTINUATION_MAX_BYTES):
            raise WatcherContinuationError(
                'changed_or_oversized',
                'Update continuation metadata changed or is oversized.',
            )
        try:
            row = json.loads(
                payload.decode('utf-8'),
                object_pairs_hook=_strict_mode_history_object,
                parse_float=_strict_mode_history_float,
                parse_constant=_reject_mode_history_constant,
            )
        except (UnicodeError, ValueError, RecursionError):
            raise WatcherContinuationError(
                'malformed_json', 'Update continuation metadata is malformed.',
            ) from None
        if not isinstance(row, dict):
            raise WatcherContinuationError(
                'malformed_json', 'Update continuation metadata is malformed.',
            )
        result = cls.from_payload(row, lease.root)
        age = time.time() - result.created
        if (result.phase in ('preparing', 'prepared', 'claimed')
                and not 0.0 <= age <= WATCHER_CONTINUATION_LIFETIME_SECONDS):
            raise WatcherContinuationError(
                'expired',
                'Update continuation expired; explicit resume is required.',
            )
        lease.verify()
        return result

    def save(
        self, lease: WorkspaceLease, *, ordinary_restart: bool = False,
    ) -> None:
        """Publish one restart checkpoint through the existing atomic writer.

        Args:
            lease (WorkspaceLease): Current exclusive flight workspace lease.
            ordinary_restart (bool): Select the ordinary-restart checkpoint
                instead of the updater continuation file.

        Returns:
            None: Publishes and verifies a non-secret, profile-bound checkpoint.

        Raises:
            ValueError: The checkpoint or native workspace identity is invalid.
            OSError: The document cannot be published.
        """
        lease.verify()
        payload = self.payload()
        self.from_payload(payload, lease.root)
        filename = (
            'watcher-restart.json' if ordinary_restart
            else 'watcher-continuation.json'
        )
        path = lease.root / filename
        try:
            existing = path.lstat()
        except FileNotFoundError:
            existing = None
        if existing is not None and (
            not stat.S_ISREG(existing.st_mode) or existing.st_nlink != 1
            or (getattr(existing, 'st_file_attributes', 0)
                & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0))
        ):
            raise ValueError('Update continuation metadata is redirected.')
        atomic_write_json(path, payload)
        lease.verify()
        verified = (
            self.load(lease, ordinary_restart=True)
            if ordinary_restart else self.load(lease)
        )
        if verified != self:
            raise ValueError('Update continuation could not be verified.')


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
        return read_import_binding_entries(self.path, IMPORT_BINDINGS_MAX_BYTES)

    def admit_job(
        self, store: JobStateStore, job: ImportJob, binding: str,
    ) -> None:
        """Publish a job and its immutable target binding before first dispatch.

        One cooperative owner spans fresh reads, both independent file
        publications and readback. A crash between the two file replacements
        leaves an orphaned binding that must fail closed at target admission.
        This is not an atomic two-file commit or a server upload receipt.

        Args:
            store (JobStateStore): Job ledger sharing this flight workspace.
            job (ImportJob): Already accepted import with its original job ID.
            binding (str): Exact approved gallery/credential fingerprint.

        Returns:
            None: Both ledgers are independently published and read back.

        Raises:
            ValueError: Topology, binding or existing evidence is invalid.
            RuntimeError: Either publication cannot be confirmed.
        """
        if (not isinstance(store, JobStateStore)
                or not isinstance(job, ImportJob)
                or not isinstance(job.id, str) or not job.id
                or not isinstance(binding, str)
                or re.fullmatch(r"[0-9a-f]{64}", binding) is None):
            raise ValueError("Accepted import identity is invalid.")
        with accepted_work_owner(store.path.parent):
            if not store.path.parent.samefile(self.path.parent.parent):
                raise ValueError("Accepted job and binding use different profiles.")
            # Both fresh snapshots are captured after ownership, never before.
            entries = self.entries()
            state = load_upload_state_for_mutation(store.path)
            if job.id in entries and entries[job.id] != binding:
                raise ValueError("This import belongs to another gallery/key.")
            if job.id in entries and job.id not in state["jobs"]:
                # A prior admission may have written its binding before its
                # job document, or job history may have been lost afterwards.
                raise ValueError("Accepted import job history needs manual review.")
            row = job.to_dict()
            previous = state["jobs"].get(job.id)
            if previous is None:
                # A fresh in-memory job is not an accepted preflight receipt.
                # A lost job ledger or crash-split binding must never be
                # reconstructed by the first-dispatch admission path.
                raise ValueError(
                    "Persisted import preflight is missing; review before admission."
                )
            if job.id not in entries:
                if (not isinstance(previous, dict)
                        or previous.get("started_at", 0)
                        or previous.get("finished_at", 0)
                        or not isinstance(previous.get("items"), list)
                        or any(
                            not isinstance(item, dict)
                            or item.get("attempt_count", 0)
                            or (item.get("server_result")
                                and item.get("state") != "skipped_duplicate")
                            or item.get("state") in (
                                "uploading", "confirmed", "failed_retryable",
                                "failed_permanent", "cancelled",
                            )
                            for item in previous["items"]
                        )):
                    # Preflight-only jobs may obtain their first binding.
                    # A previously dispatched or recovered job may not.
                    raise ValueError("Accepted import binding history is missing.")
            if previous is not None:
                # Use the same monotone evidence guard as subsequent job saves.
                validate_import_job_update(previous, row)
                # Reusing an accepted ID must not retarget its source or roll
                # back completed media and receipts from a fresher process.
                if not isinstance(previous, dict):
                    raise ValueError("Existing accepted import job is malformed.")
                immutable = (
                    "id", "source_kind", "source_label",
                    "target_gallery_label", "created_at", "staging_dir",
                    "source_zip", "delete_source_zip_after_success",
                    "skipped", "bytes_total",
                )
                if any(previous.get(key) != row.get(key) for key in immutable):
                    raise ValueError("Existing import identity changed.")
                old_items = previous.get("items")
                if not isinstance(old_items, list) or len(old_items) != len(row["items"]):
                    raise ValueError("Existing import source list changed.")
                item_identity = (
                    "id", "path", "source_label", "size",
                    "sha256", "from_archive",
                )
                for before, after in zip(old_items, row["items"]):
                    if (not isinstance(before, dict)
                            or any(before.get(key) != after.get(key)
                                   for key in item_identity)
                            or not isinstance(before.get("attempt_count"), int)
                            or before["attempt_count"] > after["attempt_count"]
                            or (before.get("server_result")
                                and before.get("server_result") != after.get("server_result"))
                            or (before.get("state") in ("confirmed", "skipped_duplicate")
                                and before.get("state") != after.get("state"))):
                        raise ValueError("Existing accepted import progress changed.")
                if (not isinstance(previous.get("bytes_sent"), int)
                        or previous["bytes_sent"] > row["bytes_sent"]):
                    raise ValueError("Existing accepted upload progress changed.")
            entries[job.id] = binding
            state["jobs"][job.id] = row
            # A binding without a job is an explicit recovery blocker; writing
            # the binding first avoids an unbound job being dispatched.
            atomic_write_json(self.path, {"schema_version": 1, "jobs": entries})
            if self.entries() != entries:
                raise RuntimeError("Accepted import binding is unconfirmed.")
            atomic_write_json(store.path, state)
            confirmed = load_upload_state_for_mutation(store.path)
            if confirmed["jobs"].get(job.id) != row or self.entries() != entries:
                raise RuntimeError("Accepted import publication is unconfirmed.")

    def discard_unbound_preflight(
        self, store: JobStateStore, job_id: str,
    ) -> None:
        """Forget only an undispatched preflight under both ledger owners.

        This removes no staged source bytes. The accepted-import binding file
        is read after acquiring the owner; an existing binding, attempted
        upload, receipt or invalid job blocks removal. A cooperating-lock
        check cannot reconstruct jointly lost or rolled-back historical data.

        Args:
            store (JobStateStore): Uploader job ledger for this profile.
            job_id (str): Preflight-only identifier to retire.

        Returns:
            None: The verified unbound preflight row is removed or absent.

        Raises:
            ValueError: The job may contain accepted or uncertain work.
            RuntimeError: Publication or readback cannot be confirmed.
        """
        if (not isinstance(store, JobStateStore)
                or not isinstance(job_id, str) or not job_id):
            raise ValueError("Preflight identity is invalid.")
        with accepted_work_owner(store.path.parent):
            if not store.path.parent.samefile(self.path.parent.parent):
                raise ValueError("Preflight and binding use different profiles.")
            entries = self.entries()
            state = load_upload_state_for_mutation(store.path)
            if job_id in entries:
                raise ValueError("Preflight has an accepted target binding; retain it.")
            row = state["jobs"].get(job_id)
            if row is None:
                return
            items = row.get("items") if isinstance(row, dict) else None
            if (not isinstance(row, dict) or row.get("id") != job_id
                    or row.get("started_at", 0)
                    or row.get("finished_at", 0)
                    or row.get("bytes_sent", 0)
                    or not isinstance(items, list)
                    or any(
                        not isinstance(item, dict)
                        or type(item.get("attempt_count", 0)) is not int
                        or item.get("attempt_count", 0) != 0
                        or bool(item.get("server_result"))
                        or item.get("state") not in (
                            "discovered", "validating", "staged",
                            "hashing", "queued", "skipped_duplicate",
                        )
                        for item in items
                    )):
                raise ValueError("Preflight may contain accepted upload work.")
            del state["jobs"][job_id]
            atomic_write_json(store.path, state)
            confirmed = load_upload_state_for_mutation(store.path)
            if job_id in confirmed["jobs"] or self.entries() != entries:
                raise RuntimeError("Preflight retirement is unconfirmed.")

    def bind(self, job_id: str, binding: str) -> None:
        """Record credential ownership before a manual upload worker starts.

        Args:
            job_id (str): Durable ImportJob identifier.
            binding (str): Current canonical endpoint/key fingerprint.

        Returns:
            None: The existing job-to-target binding is published and read back.

        Raises:
            ValueError: The job is absent, dispatched or belongs to another key.
        """
        if (not isinstance(job_id, str) or not job_id
                or not isinstance(binding, str)
                or re.fullmatch(r"[0-9a-f]{64}", binding) is None):
            raise ValueError("Accepted import identity is invalid.")
        with accepted_work_owner(self.path.parent.parent):
            entries = self.entries()
            state = load_upload_state_for_mutation(
                self.path.parent.parent / "upload_state.json"
            )
            row = state["jobs"].get(job_id)
            if not isinstance(row, dict) or row.get("id") != job_id:
                raise ValueError("Accepted import job history is missing.")
            if job_id in entries and entries[job_id] != binding:
                raise ValueError("This import belongs to another gallery/key.")
            items = row.get("items")
            if job_id not in entries and (
                row.get("started_at", 0)
                or not isinstance(items, list)
                or any(
                    not isinstance(item, dict)
                    or item.get("attempt_count", 0)
                    or (item.get("server_result")
                        and item.get("state") != "skipped_duplicate")
                    or item.get("state") in (
                        "uploading", "confirmed", "failed_retryable",
                        "failed_permanent", "cancelled",
                    )
                    for item in items
                )
            ):
                raise ValueError("Accepted import binding history may be missing.")
            entries[job_id] = binding
            atomic_write_json(self.path, {"schema_version": 1, "jobs": entries})
            if (self.entries() != entries
                    or load_upload_state_for_mutation(
                        self.path.parent.parent / "upload_state.json"
                    )["jobs"].get(job_id) != row):
                raise RuntimeError("Accepted-import binding write could not be verified.")

    def resolve(self, job_id: str) -> None:
        """Retain the immutable binding of a confirmed-complete import.

        Completed imports must not lose their original target provenance.
        Callers may finish local credential cleanup after checking this job,
        but this method must never erase a historical binding that could be
        needed to detect a later missing or rolled-back job-state ledger.

        Args:
            job_id (str): Existing durable accepted job identifier.

        Returns:
            None: Other gallery/key admissions and the completed job remain unchanged.

        Raises:
            ValueError: Completion or the job ledger cannot be verified.
            RuntimeError: The retained binding cannot be read back.
        """
        if not isinstance(job_id, str) or not job_id:
            raise ValueError("Accepted import identity is invalid.")
        with accepted_work_owner(self.path.parent.parent):
            entries = self.entries()
            if job_id not in entries:
                return
            state = load_upload_state_for_mutation(
                self.path.parent.parent / "upload_state.json"
            )
            row = state["jobs"].get(job_id)
            items = row.get("items") if isinstance(row, dict) else None
            if (not isinstance(row, dict) or row.get("id") != job_id
                    or not row.get("finished_at", 0)
                    or not isinstance(items, list)
                    or any(
                        not isinstance(item, dict)
                        or item.get("state") not in (
                            "confirmed", "skipped_duplicate",
                        )
                        or (item.get("state") == "confirmed"
                            and not item.get("server_result"))
                        or (isinstance(item.get("reason"), str)
                            and ("uncertain" in item["reason"].lower()
                                 or "unconfirmed" in item["reason"].lower()))
                        for item in items
                    )):
                raise ValueError(
                    "Accepted import is unresolved; retain its target binding."
                )
            # A locally complete job is not a server idempotency receipt.
            # Keep its original target fingerprint so even later inspection
            # cannot silently reinterpret that accepted job for another key.
            if (self.entries() != entries
                    or load_upload_state_for_mutation(
                        self.path.parent.parent / "upload_state.json"
                    )["jobs"].get(job_id) != row):
                raise RuntimeError("Accepted-import binding retention is unverified.")
