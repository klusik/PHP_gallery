"""
Manual files/folder/ZIP discovery with bounded archive staging.
"""

from dataclasses import dataclass, field
from pathlib import Path, PurePosixPath
from typing import Dict, Iterable, List, Optional, Sequence, Set, Tuple
import os
import re
import shutil
import stat
import time
import zipfile

from .models import ImportItem

_DRIVE_PATH_RE = re.compile(r"^[A-Za-z]:")


@dataclass
class ArchiveLimits:
    """Safety limits applied before and during ZIP extraction."""

    max_entries: int = 5000
    max_uncompressed_bytes: int = 16 * 1024 * 1024 * 1024
    max_file_bytes: int = 2 * 1024 * 1024 * 1024
    max_compression_ratio: float = 250.0


@dataclass
class DiscoveryResult:
    """Accepted items and rejection summaries from one import source."""

    items: List[ImportItem] = field(default_factory=list)
    unsupported: Dict[str, int] = field(default_factory=dict)
    unsafe_entries: List[str] = field(default_factory=list)
    warnings: List[str] = field(default_factory=list)
    staging_dir: str = ""

    @property
    def bytes_total(self) -> int:
        """Return accepted source bytes."""
        return sum(max(0, item.size) for item in self.items)


def _unsupported_key(path: Path) -> str:
    """Return a stable extension/reason bucket for unsupported input."""
    suffix = path.suffix.lower()
    return suffix if suffix else "no extension"


def discover_selected_files(paths: Iterable[Path], supported_suffixes: Set[str]) -> DiscoveryResult:
    """Validate an explicit multi-file selection without silently dropping entries."""
    result = DiscoveryResult()
    seen: Set[str] = set()
    for raw in paths:
        path = Path(raw)
        key = os.path.normcase(os.path.abspath(str(path)))
        if key in seen:
            continue
        seen.add(key)
        if not path.is_file():
            result.unsupported["missing/unreadable"] = result.unsupported.get("missing/unreadable", 0) + 1
            continue
        if path.suffix.lower() not in supported_suffixes:
            bucket = _unsupported_key(path)
            result.unsupported[bucket] = result.unsupported.get(bucket, 0) + 1
            continue
        result.items.append(ImportItem.create(path))
    return result


def discover_folder(folder: Path, supported_suffixes: Set[str], recursive: bool = False) -> DiscoveryResult:
    """Discover supported regular files in one folder, optionally recursively."""
    result = DiscoveryResult()
    folder = Path(folder)
    if not folder.is_dir():
        result.warnings.append("Folder does not exist or is not readable: " + str(folder))
        return result
    iterator = folder.rglob("*") if recursive else folder.glob("*")
    for path in sorted(iterator, key=lambda p: str(p).lower()):
        try:
            is_file = path.is_file()
        except OSError:
            is_file = False
        if not is_file:
            continue
        if path.suffix.lower() not in supported_suffixes:
            bucket = _unsupported_key(path)
            result.unsupported[bucket] = result.unsupported.get(bucket, 0) + 1
            continue
        result.items.append(ImportItem.create(path))
    return result


def _zip_entry_reason(info: zipfile.ZipInfo) -> Optional[str]:
    """Return a path/link rejection reason for one ZIP entry, if unsafe."""
    name = info.filename
    if "\x00" in name:
        return "NUL byte in archive path"
    normalized = name.replace("\\", "/")
    if normalized.startswith("/") or normalized.startswith("//"):
        return "absolute archive path"
    if _DRIVE_PATH_RE.match(normalized):
        return "drive-letter archive path"
    pure = PurePosixPath(normalized)
    if ".." in pure.parts:
        return "parent traversal in archive path"
    unix_mode = (info.external_attr >> 16) & 0xFFFF
    if unix_mode and stat.S_ISLNK(unix_mode):
        return "symbolic-link archive entry"
    dos_attrs = info.external_attr & 0xFFFF
    if dos_attrs & 0x0400:
        return "reparse-point-like archive entry"
    return None


def _safe_destination(staging_dir: Path, archive_name: str, used_names: Set[str]) -> Path:
    """Return a collision-safe flat destination using the archive basename."""
    base = Path(archive_name.replace("\\", "/")).name.strip()
    if not base:
        base = "image"
    safe_chars = []
    for char in base:
        safe_chars.append(char if char not in '<>:"/\\|?*\x00' else "_")
    base = "".join(safe_chars).strip(" .") or "image"
    stem = Path(base).stem or "image"
    suffix = Path(base).suffix
    candidate = base
    number = 2
    while candidate.lower() in used_names:
        candidate = f"{stem}__{number}{suffix}"
        number += 1
    used_names.add(candidate.lower())
    return staging_dir / candidate


def stage_zip_archive(
    zip_path: Path,
    staging_root: Path,
    job_id: str,
    supported_suffixes: Set[str],
    limits: ArchiveLimits,
) -> DiscoveryResult:
    """
    Inspect and safely stage supported media from one ZIP archive.

    Unsafe paths and unsupported entries are never extracted.  All extracted
    files are confined to a per-job directory owned by the caller.
    """
    result = DiscoveryResult()
    zip_path = Path(zip_path)
    if zip_path.suffix.lower() != ".zip":
        result.warnings.append("Only .zip archives are accepted in ZIP import mode.")
        return result
    if not zip_path.is_file():
        result.warnings.append("ZIP archive does not exist: " + str(zip_path))
        return result

    staging_root.mkdir(parents=True, exist_ok=True)
    staging_dir = staging_root / ("job-" + job_id)
    if staging_dir.exists():
        shutil.rmtree(staging_dir, ignore_errors=True)
    staging_dir.mkdir(parents=True, exist_ok=True)
    result.staging_dir = str(staging_dir)
    used_names: Set[str] = set()

    try:
        with zipfile.ZipFile(str(zip_path), "r") as archive:
            infos = archive.infolist()
            if len(infos) > limits.max_entries:
                result.warnings.append(
                    f"Archive has {len(infos)} entries, above the configured limit of {limits.max_entries}. Nothing was extracted."
                )
                shutil.rmtree(staging_dir, ignore_errors=True)
                result.staging_dir = ""
                return result

            total_uncompressed = 0
            total_compressed = 0
            for info in infos:
                if info.is_dir():
                    continue
                total_uncompressed += max(0, int(info.file_size))
                total_compressed += max(0, int(info.compress_size))
                if total_uncompressed > limits.max_uncompressed_bytes:
                    result.warnings.append("Archive exceeds the configured total uncompressed-size limit. Nothing was extracted.")
                    shutil.rmtree(staging_dir, ignore_errors=True)
                    result.staging_dir = ""
                    return result
            if total_uncompressed and total_compressed:
                total_ratio = total_uncompressed / max(1, total_compressed)
                if total_ratio > limits.max_compression_ratio:
                    result.warnings.append(
                        f"Archive compression ratio {total_ratio:.1f}:1 exceeds the configured {limits.max_compression_ratio:.1f}:1 limit. Nothing was extracted."
                    )
                    shutil.rmtree(staging_dir, ignore_errors=True)
                    result.staging_dir = ""
                    return result

            for info in infos:
                if info.is_dir():
                    continue
                reason = _zip_entry_reason(info)
                if reason:
                    result.unsafe_entries.append(f"{info.filename}: {reason}")
                    continue
                if info.file_size > limits.max_file_bytes:
                    result.unsafe_entries.append(f"{info.filename}: file exceeds configured per-file size limit")
                    continue
                if info.file_size > 0:
                    ratio = info.file_size / max(1, info.compress_size)
                    if ratio > limits.max_compression_ratio:
                        result.unsafe_entries.append(f"{info.filename}: compression ratio {ratio:.1f}:1 exceeds limit")
                        continue
                suffix = Path(info.filename).suffix.lower()
                if suffix not in supported_suffixes:
                    bucket = suffix if suffix else "no extension"
                    result.unsupported[bucket] = result.unsupported.get(bucket, 0) + 1
                    continue

                destination = _safe_destination(staging_dir, info.filename, used_names)
                resolved_root = staging_dir.resolve()
                resolved_destination = destination.resolve()
                try:
                    resolved_destination.relative_to(resolved_root)
                except ValueError:
                    result.unsafe_entries.append(f"{info.filename}: destination escaped staging directory")
                    continue

                try:
                    with archive.open(info, "r") as source, destination.open("wb") as target:
                        copied = 0
                        while True:
                            chunk = source.read(1024 * 1024)
                            if not chunk:
                                break
                            copied += len(chunk)
                            if copied > limits.max_file_bytes or copied > info.file_size + 1024:
                                raise ValueError("entry exceeded declared or configured size while extracting")
                            target.write(chunk)
                    result.items.append(ImportItem.create(destination, source_label=info.filename, from_archive=True))
                except Exception as exc:  # noqa: BLE001
                    try:
                        destination.unlink()
                    except OSError:
                        pass
                    result.unsafe_entries.append(f"{info.filename}: extraction failed: {exc}")
    except (OSError, zipfile.BadZipFile, zipfile.LargeZipFile) as exc:
        result.warnings.append("Archive could not be read: " + str(exc))

    if not result.items and result.staging_dir:
        shutil.rmtree(result.staging_dir, ignore_errors=True)
        result.staging_dir = ""
    return result


def cleanup_stale_staging(staging_root: Path, age_hours: float, active_job_ids: Sequence[str] = ()) -> List[str]:
    """Remove old job staging directories while leaving known active jobs intact."""
    removed: List[str] = []
    if not staging_root.is_dir():
        return removed
    cutoff = time.time() - max(1.0, float(age_hours)) * 3600.0
    active_names = {"job-" + job_id for job_id in active_job_ids}
    for path in staging_root.iterdir():
        if not path.is_dir() or path.name in active_names:
            continue
        try:
            modified = path.stat().st_mtime
        except OSError:
            continue
        if modified > cutoff:
            continue
        shutil.rmtree(path, ignore_errors=True)
        if not path.exists():
            removed.append(path.name)
    return removed
