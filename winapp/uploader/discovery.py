# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Discover manual files, folders and ZIP inputs for upload.
# Responsibilities:
#   - Bound archive staging and normalize selected upload candidates.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/uploader/discovery.py
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
Manual files/folder/ZIP discovery with bounded archive staging.
"""

from dataclasses import dataclass, field
from pathlib import Path, PurePosixPath
from typing import Dict, Iterable, List, Optional, Sequence, Set, Tuple
import os
import re
import stat
import zipfile
import time

from .watch_queue import WatchSource, observe_watch_source

from .models import ImportItem

_DRIVE_PATH_RE = re.compile(r"^[A-Za-z]:")


def discover_watch_sources(
    folder: Path, supported_suffixes: Set[str], recursive: bool = False,
) -> List[WatchSource]:
    """Observe a complete capture folder without hashing or swallowing scan failure.

    Args:
        folder (Path): Original absolute capture folder.
        supported_suffixes (Set[str]): Approved lowercase image extensions.
        recursive (bool): Whether ordinary subdirectories belong to the capture.

    Returns:
        List[WatchSource]: Cheap regular-file observations with explicit clock evidence.

    Raises:
        OSError: A folder or image cannot be enumerated safely; completion must wait.
    """
    observed_at = time.time()
    result: List[WatchSource] = []
    directories = [folder.absolute()]
    while directories:
        directory = directories.pop()
        with os.scandir(directory) as entries:
            for entry in entries:
                if entry.is_dir(follow_symlinks=False):
                    if recursive and not entry.is_symlink():
                        # Directory junctions can escape the selected capture
                        # or recurse forever. Cloud-backed ordinary folders
                        # remain supported; a junction requires explicit review.
                        if hasattr(entry, 'is_junction') and entry.is_junction():
                            raise OSError('Capture subdirectory is redirected.')
                        directories.append(Path(entry.path))
                elif Path(entry.name).suffix.lower() in supported_suffixes:
                    try:
                        result.append(observe_watch_source(Path(entry.path), observed_at))
                    except FileNotFoundError:
                        # A vanished new event is recovered on the next scan;
                        # previously accepted missing sources stay in the FIFO.
                        continue
    return result


class WatchDirectorySignal:
    """Use coalesced Windows directory notifications with periodic reconciliation."""

    def __init__(self, folder: Path, recursive: bool) -> None:
        """Create an optional local notification handle without an extra worker.

        Args:
            folder (Path): Original capture directory.
            recursive (bool): Whether nested changes should signal discovery.

        Returns:
            None: Portable/unavailable native notifications use the same periodic scan.
        """
        self._handle: int | None = None
        self._kernel = None
        if os.name == 'nt':
            import ctypes
            from ctypes import wintypes

            kernel = ctypes.WinDLL('kernel32', use_last_error=True)
            kernel.FindFirstChangeNotificationW.argtypes = [
                wintypes.LPCWSTR, wintypes.BOOL, wintypes.DWORD,
            ]
            kernel.FindFirstChangeNotificationW.restype = wintypes.HANDLE
            kernel.FindNextChangeNotification.argtypes = [wintypes.HANDLE]
            kernel.FindNextChangeNotification.restype = wintypes.BOOL
            kernel.FindCloseChangeNotification.argtypes = [wintypes.HANDLE]
            kernel.WaitForSingleObject.argtypes = [wintypes.HANDLE, wintypes.DWORD]
            # Win32 FILE_NAME, DIR_NAME, SIZE and LAST_WRITE notification flags.
            handle = kernel.FindFirstChangeNotificationW(str(folder), recursive, 0x1B)
            if handle not in (None, ctypes.c_void_p(-1).value):
                self._kernel, self._handle = kernel, handle

    def changed(self) -> bool:
        """Poll a native signal without delaying refresh, Finish or shutdown.

        Returns:
            bool: True for a pending local directory notification; False otherwise.
        """
        if self._handle is None or self._kernel is None:
            return False
        # WAIT_OBJECT_0 and a zero-duration wait are Win32 structural constants.
        if self._kernel.WaitForSingleObject(self._handle, 0) == 0:
            self._kernel.FindNextChangeNotification(self._handle)
            return True
        return False

    def close(self) -> None:
        """Release only this producer's native notification handle.

        Returns:
            None: Leaves source files and pending directory events untouched.
        """
        if self._handle is not None and self._kernel is not None:
            self._kernel.FindCloseChangeNotification(self._handle)
            self._handle = None


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

    Args:
        zip_path (Path): ZIP archive selected as the import source.
        staging_root (Path): Owned directory under which job staging is made.
        job_id (str): Stable identifier used to name this job's staging folder.
        supported_suffixes (Set[str]): Lowercase file suffixes admitted for
            media extraction.
        limits (ArchiveLimits): Entry, expanded-size, file-size and ratio caps.

    Returns:
        DiscoveryResult: Accepted staged items and any warnings or rejections.
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
    if (staging_root.is_symlink()
            or (os.name == "nt"
                and (getattr(staging_root.lstat(), "st_file_attributes", 0)
                     & stat.FILE_ATTRIBUTE_REPARSE_POINT))):
        # Junctions and other direct reparse points can redirect extraction
        # into a different physical workspace.
        result.warnings.append(
            "ZIP staging root is redirected; existing files were preserved."
        )
        return result
    staging_dir = staging_root / ("job-" + job_id)
    # A reused job ID can be historical accepted work. Never recursively
    # replace an existing extracted source directory based on an ID alone.
    try:
        staging_dir.mkdir(parents=False, exist_ok=False)
    except FileExistsError:
        result.warnings.append(
            "ZIP staging directory already exists; review the previous import."
        )
        return result
    result.staging_dir = str(staging_dir)
    used_names: Set[str] = set()

    try:
        with zipfile.ZipFile(str(zip_path), "r") as archive:
            infos = archive.infolist()
            if len(infos) > limits.max_entries:
                result.warnings.append(
                    f"Archive has {len(infos)} entries, above the configured limit of {limits.max_entries}. Nothing was extracted."
                )
                result.warnings.append(
                    "ZIP staging directory retained for manual review: "
                    + str(staging_dir)
                )
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
                    result.warnings.append(
                        "ZIP staging directory retained for manual review: "
                        + str(staging_dir)
                    )
                    return result
            if total_uncompressed and total_compressed:
                total_ratio = total_uncompressed / max(1, total_compressed)
                if total_ratio > limits.max_compression_ratio:
                    result.warnings.append(
                        f"Archive compression ratio {total_ratio:.1f}:1 exceeds the configured {limits.max_compression_ratio:.1f}:1 limit. Nothing was extracted."
                    )
                    result.warnings.append(
                        "ZIP staging directory retained for manual review: "
                        + str(staging_dir)
                    )
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
                    # Exclusive creation must never truncate an existing
                    # staged source, including during a competing extraction.
                    with archive.open(info, "r") as source, destination.open("xb") as target:
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
                    # Preserve partial staged bytes; unlinking by a mutable
                    # filename could remove another process's source instead.
                    result.unsafe_entries.append(f"{info.filename}: extraction failed: {exc}")
    except (OSError, zipfile.BadZipFile, zipfile.LargeZipFile) as exc:
        result.warnings.append("Archive could not be read: " + str(exc))

    if not result.items and result.staging_dir:
        # Empty or rejected preflight is not authority to unlink a potentially
        # redirected source directory after another process has seen its path.
        result.warnings.append(
            "No accepted ZIP items; staging directory retained: "
            + result.staging_dir
        )
    return result


def cleanup_stale_staging(staging_root: Path, age_hours: float, active_job_ids: Sequence[str] = ()) -> List[str]:
    """Remove old job staging directories while leaving known active jobs intact.

    This legacy entry point is disabled: age and a list of visible job IDs
    do not prove that earlier accepted history was never lost or rolled back.
    Staged media is retained until independently verified server receipts
    and an explicit source-ownership cleanup protocol are available.

    Args:
        staging_root (Path): Root containing per-job staged source directories.
        age_hours (float): Age threshold that the disabled cleanup would use.
        active_job_ids (Sequence[str]): Job identifiers that must be retained.

    Returns:
        List[str]: Empty list because this entry point performs no removals.
    """
    return []
