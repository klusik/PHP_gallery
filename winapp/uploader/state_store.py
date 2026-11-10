# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Persist uploader state and job/activity history atomically.
# Responsibilities:
#   - Read and publish local state without exposing partially written records.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/uploader/state_store.py
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
Atomic state persistence and job/activity history for the Windows uploader.
"""

import json
import logging
from contextlib import ExitStack, closing, contextmanager
import math
import os
import re
import stat
import shutil
import tempfile
import threading
import time
from collections import deque
from pathlib import Path
from typing import Any, BinaryIO, Deque, Dict, Iterator, List, NoReturn, Optional

if os.name == "nt":
    import msvcrt
else:
    import fcntl

from .models import (
    ActivityEvent, ImportJob, ITEM_STATES, LINKED_UPLOAD_UNCERTAIN_REASON,
    JsonValue,
)
from .app_linking import linking_issuer
from .windows_file_identity import WindowsDirectoryAnchor

UPLOAD_STATE_SCHEMA_VERSION = 2
_STATE_LOCK = threading.RLock()
# Keep large legacy watcher histories, but bound destructive RMW parsing.
# Type: int; Units: bytes; Scope: complete upload-state admission and RMW reads.
# Consumers: load_upload_state_for_mutation and destination-change inspection.
# Rationale: Match the existing strict RMW envelope so valid legacy history
#   is not rejected only because read-only admission is more restrictive.
_UPLOAD_STATE_RMW_MAX_BYTES = 256 * 1024 * 1024

# Purpose: Retain a compact list of recently used gallery installations.
# Type: int. Units: addresses. Scope: one WinApp profile.
# Consumers: LinkingManager, load_recent_gallery_addresses.
# Rationale: Twenty entries cover frequent switching and bound local history.
RECENT_GALLERY_ADDRESS_LIMIT = 20

# Purpose: Bound the independent, non-secret address history document.
# Type: int. Units: bytes. Scope: one local gallery address history read.
# Consumers: load_recent_gallery_addresses.
# Rationale: 16 KiB fits twenty canonical 512-byte issuers and JSON framing.
_RECENT_GALLERY_ADDRESS_MAX_BYTES = 16 * 1024

# Purpose: Coalesce a Tk event drain's activity records into one disk write.
# Type: float; Units: seconds; Scope: one pending ActivityWriter batch.
# Consumers: ActivityWriter._run.
# Rationale: 50 ms gives adjacent UI events time to join a batch without
#   making recent activity visibly stale.
ACTIVITY_WRITER_BATCH_WINDOW_SECONDS = 0.05


class UploadStateTooLargeError(ValueError):
    """Represent a complete upload-state snapshot beyond its safe size cap."""


def linked_upload_state_path(config_dir: Path, binding: str) -> Path:
    """Resolve a separate local history for one approved native target.

    The non-secret binding identifies the exact issuer, approving account and
    gallery. Historical unbound API-key state is never imported into this
    target. Callers should resolve the path again before each read or write.

    Args:
        config_dir (Path): Existing per-user uploader configuration directory.
        binding (str): Lowercase SHA-256 fingerprint of the approved target.

    Returns:
        Path: Target-specific state path below the owned configuration folder.

    Raises:
        ValueError: The binding is malformed or the state path uses a symlink.
    """
    if not isinstance(binding, str) or re.fullmatch(r"[0-9a-f]{64}", binding) is None:
        raise ValueError("Invalid linked upload target binding.")
    directory = config_dir / "linked-upload-state"
    path = directory / (binding + ".json")
    if directory.is_symlink() or path.is_symlink():
        raise ValueError("Linked upload state must not use a symbolic link.")
    if os.name == "nt":
        # A junction is not necessarily reported by Path.is_symlink().
        # A different profile owner could otherwise reach the same state.
        for candidate in (config_dir, directory, path):
            try:
                attributes = candidate.lstat()
            except FileNotFoundError:
                continue
            if (getattr(attributes, "st_file_attributes", 0)
                    & stat.FILE_ATTRIBUTE_REPARSE_POINT):
                raise ValueError("Linked upload recovery path is redirected.")
    return path


def quarantine_malformed(path: Path) -> Optional[Path]:
    """
    Preserve an unreadable state/config file for diagnostics.

    Args:
        path (Path): Existing state/configuration JSON path to preserve as a
            timestamped diagnostic copy.

    Returns:
        Optional[Path]: Quarantine path when a copy was created, otherwise
            ``None``.
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


def atomic_write_json(
    path: Path, payload: Dict[str, JsonValue], *, require_sync: bool = False,
) -> None:
    """
    Write JSON through a same-directory temporary file and atomic replace.

    Args:
        path (Path): Owned destination file for a complete state snapshot.
        payload (Dict[str, JsonValue]): Complete string-keyed JSON document;
            values are JSON scalars, lists or recursively keyed objects.
        require_sync (bool): Refuse publication when file fsync fails, as
            required for accepted upload work; False preserves display caches.

    Returns:
        None: The complete temporary file replaces the destination.

    Raises:
        OSError: Writing or replacement fails, or required fsync is unavailable.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    encoded = json.dumps(payload, indent=2, ensure_ascii=False)
    # Each writer owns its staging file; a second process must never truncate
    # another writer's shared ".tmp" before either publication completes.
    descriptor, stage_name = tempfile.mkstemp(
        prefix=path.name + ".", suffix=".tmp", dir=path.parent,
    )
    stage = Path(stage_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(encoded)
            handle.flush()
            try:
                os.fsync(handle.fileno())
            except OSError:
                if require_sync:
                    raise
        stage.replace(path)
    finally:
        stage.unlink(missing_ok=True)


def load_recent_gallery_addresses(path: Path) -> tuple[str, ...]:
    """Read only canonical installation URLs from this profile's history.

    Args:
        path (Path): Dedicated non-secret gallery address history file.

    Returns:
        tuple[str, ...]: Unique recent issuers, newest first, or an empty list
            for an absent or unreadable history.
    """
    try:
        if path.parent.is_symlink() or not stat.S_ISREG(path.lstat().st_mode):
            return ()
        with path.open('rb') as handle:
            raw = handle.read(_RECENT_GALLERY_ADDRESS_MAX_BYTES + 1)
        if len(raw) > _RECENT_GALLERY_ADDRESS_MAX_BYTES:
            return ()
        data = json.loads(raw)
        if not isinstance(data, dict) or set(data) != {'addresses'}:
            return ()
        addresses = data['addresses']
        if not isinstance(addresses, list) or len(addresses) > RECENT_GALLERY_ADDRESS_LIMIT:
            return ()
        return tuple(dict.fromkeys(
            address for address in addresses
            if isinstance(address, str) and address and linking_issuer(address) == address
        ))
    except (OSError, ValueError, RecursionError):
        return ()


def save_recent_gallery_addresses(path: Path, addresses: tuple[str, ...]) -> None:
    """Atomically publish the profile's non-secret recent gallery addresses.

    Args:
        path (Path): Dedicated profile file, separate from upload configuration.
        addresses (tuple[str, ...]): Unique canonical issuers, most recent first.

    Returns:
        None: The complete address history replaces the prior file.

    Raises:
        ValueError: The path or supplied address list is invalid.
        OSError: The profile could not publish the history.
    """
    if (len(addresses) > RECENT_GALLERY_ADDRESS_LIMIT
            or any(not isinstance(address, str) or not address
                   or linking_issuer(address) != address
                   for address in addresses)
            or len(set(addresses)) != len(addresses)
            or path.parent.is_symlink() or path.is_symlink()):
        raise ValueError('Gallery address history is invalid.')
    atomic_write_json(path, {'addresses': list(addresses)})


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


def merge_upload_sections(
    path: Path, replacement: Dict[str, Any], baseline: Dict[str, Any],
) -> Dict[str, Any]:
    """Publish legacy watcher history only against its last verified baseline.

    The cooperative owner covers the fresh read, comparison, publication and
    readback. Job/event mutations made by another cooperating writer remain
    intact. A stale watcher cannot replace newer upload outcomes, failures or
    retry evidence with an older in-memory snapshot.

    Args:
        path (Path): Legacy upload-state document beside the accepted-work lock.
        replacement (Dict[str, Any]): Complete watcher-owned sections to save.
        baseline (Dict[str, Any]): Deep-copy watcher sections from the last
            owner-protected read or confirmed publication.

    Returns:
        Dict[str, Any]: Fully verified published document for the next baseline.

    Raises:
        ValueError: Original evidence changed or replacement is malformed.
        RuntimeError: The published state cannot be read back.
    """
    sections = ("uploaded_paths", "uploaded_hashes", "failures")
    with accepted_work_owner(path.parent):
        current = load_upload_state_for_mutation(path)
        if not isinstance(baseline, dict) or any(
            not isinstance(baseline.get(key), dict)
            or not isinstance(replacement.get(key), dict)
            for key in sections
        ):
            raise ValueError("Legacy upload history baseline is unavailable.")
        for key in sections:
            if current[key] != baseline[key]:
                raise ValueError(
                    "Legacy upload history changed; review before continuing."
                )
            current[key] = replacement[key]
        current["schema_version"] = UPLOAD_STATE_SCHEMA_VERSION
        atomic_write_json(path, current)
        confirmed = load_upload_state_for_mutation(path)
        if confirmed != current:
            raise RuntimeError("Published uploader state could not be verified.")
        return confirmed


def _strict_mode_history_object(pairs: List[tuple[str, Any]]) -> Dict[str, Any]:
    """Reject duplicate JSON keys when checking permission to change targets.

    Duplicate job or section keys can hide accepted work from normal JSON
    decoding. Legacy upload-history parsing remains unchanged.

    Args:
        pairs (List[tuple[str, Any]]): Decoder-owned ordered object members.

    Returns:
        Dict[str, Any]: Unique, exactly preserved JSON object.

    Raises:
        ValueError: A duplicate key would make the evidence ambiguous.
    """
    result: Dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Import recovery state contains duplicate fields.')
        result[key] = value
    return result


def _reject_mode_history_constant(value: str) -> NoReturn:
    """Reject non-JSON floating-point constants from mode-change evidence.

    Args:
        value (str): Decoder spelling of a non-finite value.

    Returns:
        NoReturn: Never returns; invalid numeric data blocks admission.

    Raises:
        ValueError: NaN and Infinity cannot be reliable durable evidence.
    """
    raise ValueError('Import recovery state contains an invalid number.')


def _strict_mode_history_float(value: str) -> float:
    """Reject overflowing JSON exponents in security-sensitive recovery evidence.

    Args:
        value (str): Decoder spelling of a JSON floating-point number.

    Returns:
        float: Its finite numerical value.

    Raises:
        ValueError: An exponent overflowed to a non-finite runtime value.
    """
    parsed = float(value)
    if not math.isfinite(parsed):
        raise ValueError('Recovery state contains an invalid number.')
    return parsed


# An in-place file, never JSON, coordinates complete cooperative RMW operations.
# It is not a two-file atomic commit or a rollback-resistant provenance record.
_ACCEPTED_WORK_LOCK_NAME = "accepted-work.lock"


def _check_accepted_owner_path(
    directory: Path, original_directory: tuple[int, int],
    lock_path: Path, handle: BinaryIO,
) -> None:
    """Ensure the owned lock file and workspace have not changed identity.

    Args:
        directory (Path): Profile directory used by both accepted-work ledgers.
        original_directory (tuple[int, int]): Directory ``(device, inode)`` identity.
        lock_path (Path): Persistent in-place lock path.
        handle (BinaryIO): Open binary stream retaining the regular lock file.

    Returns:
        None: Both paths still name the held filesystem objects.

    Raises:
        ValueError: A lock file or workspace path was replaced.
    """
    try:
        # Windows must not follow a new direct reparse point while checking
        # the already-open owner. This check complements the native anchor;
        # arbitrary ancestor replacement remains a separate blocker.
        root = directory.lstat() if os.name == "nt" else directory.stat()
        named = lock_path.lstat()
        opened = os.fstat(handle.fileno())
    except OSError:
        raise ValueError("Accepted-work owner identity is unavailable.") from None
    if (not stat.S_ISDIR(root.st_mode)
            or (os.name == "nt"
                and (getattr(root, "st_file_attributes", 0)
                     & stat.FILE_ATTRIBUTE_REPARSE_POINT))
            or (root.st_dev, root.st_ino) != original_directory
            or not stat.S_ISREG(named.st_mode)
            or not stat.S_ISREG(opened.st_mode)
            or named.st_nlink != 1 or opened.st_nlink != 1
            or named.st_dev <= 0 or named.st_ino <= 0
            or (named.st_dev, named.st_ino) != (opened.st_dev, opened.st_ino)):
        raise ValueError("Accepted-work owner identity changed.")


@contextmanager
def accepted_work_owner(config_dir: Path) -> Iterator[None]:
    """Acquire one OS-backed cooperative owner before accepted-work RMW.

    On Windows native volume/File ID directory handles remain open for both
    the profile and its non-reparse flights/ child throughout the transaction.
    Their path identities are rechecked before and after publication.
    Every cooperating writer must obtain this same *file* lock before reading
    current state, changing it, publishing, and reading it back. Aliases to the
    same underlying lock file contend. This does not fence older binaries or
    attest that missing or jointly rolled-back history never existed.

    Args:
        config_dir (Path): Profile containing upload_state.json and flights/.

    Returns:
        Iterator[None]: Yield while holding the native cross-process lock.

    Raises:
        OSError: The directory or lock file cannot be opened.
        RuntimeError: Another process owns accepted work or locking fails.
        ValueError: A filesystem identity is missing or changed.
    """
    with _STATE_LOCK, ExitStack() as resources:
        config_dir.mkdir(parents=True, exist_ok=True)
        if os.name == "nt":
            # An alias to a different profile must not acquire a separate
            # owner while implicitly reaching the same accepted-work files.
            # Reject the direct profile reparse point before opening its
            # native identity handle; ancestor redirection is still unproven.
            named_profile = config_dir.lstat()
            if (not stat.S_ISDIR(named_profile.st_mode)
                    or (getattr(named_profile, "st_file_attributes", 0)
                        & stat.FILE_ATTRIBUTE_REPARSE_POINT)):
                raise ValueError("Accepted-work profile is redirected.")
        native = (
            resources.enter_context(closing(WindowsDirectoryAnchor(config_dir)))
            if os.name == "nt" else None
        )
        # Both JSON ledgers must remain inside the same pinned profile.
        # A reparse-point flights/ directory can be shared by another profile
        # with a different owner lock, so fail closed rather than mutate it.
        flight_native = None
        if native is not None:
            flight_path = config_dir / "flights"
            flight_path.mkdir(exist_ok=True)
            flight_info = flight_path.lstat()
            if (not stat.S_ISDIR(flight_info.st_mode)
                    or (getattr(flight_info, "st_file_attributes", 0)
                        & stat.FILE_ATTRIBUTE_REPARSE_POINT)):
                raise ValueError("Accepted flight workspace is redirected.")
            flight_native = resources.enter_context(
                closing(WindowsDirectoryAnchor(flight_path))
            )
            flight_native.verify()
        directory = config_dir.stat()
        if not stat.S_ISDIR(directory.st_mode):
            raise ValueError("Accepted-work profile must be a directory.")
        directory_id = (directory.st_dev, directory.st_ino)
        lock_path = config_dir / _ACCEPTED_WORK_LOCK_NAME
        flags = (os.O_RDWR | os.O_CREAT | getattr(os, "O_BINARY", 0)
                 | getattr(os, "O_NOFOLLOW", 0))
        descriptor = os.open(lock_path, flags, 0o600)
        with os.fdopen(descriptor, "r+b") as handle:
            acquired = False
            try:
                # Refuse redirected/hardlinked lock files before even touching
                # their sentinel bytes or attempting native acquisition.
                _check_accepted_owner_path(
                    config_dir, directory_id, lock_path, handle,
                )
                if native is not None:
                    native.verify()
                    if flight_native is None:
                        raise ValueError("Accepted flight identity is unavailable.")
                    flight_native.verify()
                try:
                    handle.seek(0)
                    sentinel = handle.read(1)
                    if not sentinel:
                        handle.seek(0)
                        handle.write(b"0")
                        handle.flush()
                    elif sentinel != b"0":
                        raise ValueError("Accepted-work lock file is invalid.")
                    handle.seek(0)
                    if os.name == "nt":
                        msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
                    else:
                        fcntl.flock(
                            handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB,
                        )
                    acquired = True
                except OSError:
                    raise RuntimeError(
                        "Another process owns accepted work or its lock is unavailable."
                    ) from None
                _check_accepted_owner_path(
                    config_dir, directory_id, lock_path, handle,
                )
                if native is not None:
                    native.verify()
                    if flight_native is None:
                        raise ValueError("Accepted flight identity is unavailable.")
                    flight_native.verify()
                yield
                _check_accepted_owner_path(
                    config_dir, directory_id, lock_path, handle,
                )
                if native is not None:
                    native.verify()
                    if flight_native is None:
                        raise ValueError("Accepted flight identity is unavailable.")
                    flight_native.verify()
            finally:
                if acquired:
                    handle.seek(0)
                    if os.name == "nt":
                        msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
                    else:
                        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def load_upload_state_for_mutation(path: Path) -> Dict[str, Any]:
    """Read a complete existing history before any destructive RMW publication.

    Unlike the legacy display/cache reader, a writer must not replace a
    malformed, redirected, truncated or concurrently changed snapshot with an
    empty document. This is a per-file guard, not a cross-process transaction.

    Args:
        path (Path): Shared state file to read before a mutation.

    Returns:
        Dict[str, Any]: Verified legacy-compatible sections, or a new document
            if the file is absent at this instant.

    Raises:
        ValueError: Existing history cannot be safely read and retained.
    """
    try:
        before = path.lstat()
    except FileNotFoundError:
        return default_upload_state()
    except OSError:
        raise ValueError("Existing upload history is unavailable.") from None
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or before.st_dev <= 0 or before.st_ino <= 0
            or before.st_size > _UPLOAD_STATE_RMW_MAX_BYTES):
        raise ValueError("Existing upload history is unavailable.")
    flags = (os.O_RDONLY | getattr(os, "O_BINARY", 0)
             | getattr(os, "O_NOFOLLOW", 0)
             | getattr(os, "O_NONBLOCK", 0))
    try:
        with os.fdopen(os.open(path, flags), "rb") as source:
            opened = os.fstat(source.fileno())
            raw = source.read(_UPLOAD_STATE_RMW_MAX_BYTES + 1)
            finished = os.fstat(source.fileno())
        after = path.lstat()
        snapshots = (before, opened, finished, after)
        identities = {
            (item.st_dev, item.st_ino, item.st_size, item.st_mtime_ns)
            for item in snapshots
        }
        if (len(raw) > _UPLOAD_STATE_RMW_MAX_BYTES
                or len(raw) != opened.st_size or len(identities) != 1
                or any(not stat.S_ISREG(item.st_mode) or item.st_nlink != 1
                       for item in snapshots)):
            raise ValueError("Existing upload history changed.")
        payload = json.loads(
            raw.decode("utf-8"),
            object_pairs_hook=_strict_mode_history_object,
            parse_constant=_reject_mode_history_constant,
            parse_float=_strict_mode_history_float,
        )
    except (OSError, UnicodeError, TypeError, OverflowError, ValueError):
        raise ValueError("Existing upload history needs manual review.") from None
    if not isinstance(payload, dict):
        raise ValueError("Existing upload history needs manual review.")
    version = payload.get("schema_version", 1)
    if type(version) is not int or version not in (1, UPLOAD_STATE_SCHEMA_VERSION):
        raise ValueError("Existing upload history schema needs manual review.")
    for section in ("uploaded_paths", "uploaded_hashes", "failures", "jobs"):
        if section in payload and not isinstance(payload[section], dict):
            raise ValueError("Existing upload history section needs manual review.")
    if "recent_events" in payload and not isinstance(payload["recent_events"], list):
        raise ValueError("Existing upload history events need manual review.")
    return normalize_upload_state(payload)


def read_import_binding_entries(
    path: Path, max_bytes: int = 16 * 1024 * 1024,
) -> Dict[str, str]:
    """Read immutable job-to-target claims without repairing missing history.

    This caller-neutral parser is shared by new-job admission and existing
    ImportBindings readers. Security decisions must call it only after
    acquiring the accepted-work owner. A missing document is not evidence
    that a previous binding never existed or survived a joint rollback.

    Args:
        path (Path): Existing flights/import-bindings.json path.
        max_bytes (int): Maximum complete ledger size for strict admission.

    Returns:
        Dict[str, str]: Validated original job IDs and target fingerprints.

    Raises:
        ValueError: The retained binding evidence is unsafe or malformed.
    """
    try:
        before = path.lstat()
    except FileNotFoundError:
        return {}
    except OSError:
        raise ValueError("Accepted-import connection ledger is unavailable.") from None
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or before.st_dev <= 0 or before.st_ino <= 0
            or before.st_size > max_bytes):
        raise ValueError("Accepted-import connection ledger is unavailable.")
    flags = (os.O_RDONLY | getattr(os, "O_BINARY", 0)
             | getattr(os, "O_NOFOLLOW", 0)
             | getattr(os, "O_NONBLOCK", 0))
    try:
        with os.fdopen(os.open(path, flags), "rb") as source:
            opened = os.fstat(source.fileno())
            raw = source.read(max_bytes + 1)
            finished = os.fstat(source.fileno())
        after = path.lstat()
        snapshots = (before, opened, finished, after)
        identities = {
            (item.st_dev, item.st_ino, item.st_size, item.st_mtime_ns)
            for item in snapshots
        }
        if (len(raw) > max_bytes or len(raw) != opened.st_size
                or len(identities) != 1
                or any(not stat.S_ISREG(item.st_mode) or item.st_nlink != 1
                       for item in snapshots)):
            raise ValueError("Accepted-import connection ledger changed.")
        row = json.loads(
            raw.decode("utf-8"),
            object_pairs_hook=_strict_mode_history_object,
            parse_constant=_reject_mode_history_constant,
            parse_float=_strict_mode_history_float,
        )
    except (OSError, UnicodeError, TypeError, OverflowError, ValueError):
        raise ValueError("Accepted-import connection ledger needs review.") from None
    if (not isinstance(row, dict)
            or type(row.get("schema_version")) is not int
            or row["schema_version"] != 1
            or not isinstance(row.get("jobs"), dict)):
        raise ValueError("Invalid accepted-import connection ledger.")
    entries = row["jobs"]
    if any(
        not isinstance(key, str) or not key or not isinstance(value, str)
        or re.fullmatch(r"[0-9a-f]{64}", value) is None
        for key, value in entries.items()
    ):
        raise ValueError("Invalid accepted-import connection ledger.")
    return dict(entries)


def validate_import_job_update(
    previous: Optional[Dict[str, Any]], current: Dict[str, Any],
) -> None:
    """Refuse stale replacement of accepted source or durable upload evidence.

    This validates an existing row under the shared accepted-work owner. It
    protects the current document from a stale cooperative writer, but cannot
    establish that missing or jointly rolled-back history never existed.
    A validated live upload reply may complete its own write-ahead attempt;
    inventory observations and a new attempt cannot clear uncertain history.

    Args:
        previous (Optional[Dict[str, Any]]): Existing serialized ImportJob with
            the identity, items and progress fields described for current,
            or None for a new preflight; malformed rows are rejected.
        current (Dict[str, Any]): Serialized ImportJob with string id,
            source_kind, source_label, target_gallery_label, staging_dir and
            source_zip; numeric created_at, started_at, finished_at,
            bytes_total, bytes_sent and skipped; Boolean
            delete_source_zip_after_success; and items containing string id,
            path, source_label, sha256, state and reason, integer size and
            attempt_count, Boolean from_archive, and server_result dictionaries
            with integer uploaded/scanned counts and optional filename lists
            or thumbnail summaries from validated replies.

    Returns:
        None: The candidate preserves existing accepted-work evidence.

    Raises:
        ValueError: Immutable source, attempts, outcomes or progress regressed.
    """
    if previous is None:
        return
    if not isinstance(previous, dict) or not isinstance(current, dict):
        raise ValueError("Existing import job requires manual review.")
    immutable = (
        "id", "source_kind", "source_label", "target_gallery_label",
        "created_at", "staging_dir", "source_zip",
        "delete_source_zip_after_success", "skipped", "bytes_total",
    )
    if any(previous.get(key) != current.get(key) for key in immutable):
        raise ValueError("Existing import source identity changed.")
    old_items = previous.get("items")
    new_items = current.get("items")
    if (not isinstance(old_items, list) or not isinstance(new_items, list)
            or len(old_items) != len(new_items)):
        raise ValueError("Existing import source list changed.")
    item_identity = (
        "id", "path", "source_label", "size", "sha256", "from_archive",
    )
    for old, new in zip(old_items, new_items):
        if not isinstance(old, dict) or not isinstance(new, dict):
            raise ValueError("Existing import item is malformed.")
        if any(old.get(key) != new.get(key) for key in item_identity):
            raise ValueError("Existing import item identity changed.")
        old_attempts = old.get("attempt_count", 0)
        new_attempts = new.get("attempt_count", 0)
        if (type(old_attempts) is not int or type(new_attempts) is not int
                or old_attempts < 0 or new_attempts < old_attempts):
            raise ValueError("Existing import attempt history regressed.")
        old_receipt = old.get("server_result")
        new_receipt = new.get("server_result")
        if old_receipt and old_receipt != new_receipt:
            raise ValueError("Existing server result cannot be replaced.")
        if (old.get("state") in ("confirmed", "skipped_duplicate")
                and old.get("state") != new.get("state")):
            raise ValueError("Confirmed import outcome cannot regress.")
        old_reason = old.get("reason")
        # The worker writes uncertainty before dispatch and supplies this
        # response only after LinkedUploadTarget's transport validates the
        # selected gallery and fresh scanner insertion. Complete that same
        # attempt without allowing a retry, inventory match or empty response
        # to erase uncertainty. This is a live acknowledgement, not an
        # immutable receipt across database rollback or local history loss.
        acknowledged_attempt = (
            old_reason == LINKED_UPLOAD_UNCERTAIN_REASON
            and old.get("state") == "failed_permanent"
            and new.get("state") == "confirmed"
            and old_attempts > 0
            and old_attempts == new_attempts
            and not old_receipt
            and isinstance(new_receipt, dict)
            and type(new_receipt.get("uploaded")) is int
            and new_receipt["uploaded"] == 1
            and type(new_receipt.get("scanned")) is int
            and new_receipt["scanned"] >= 0
        )
        if (isinstance(old_reason, str)
                and ("uncertain" in old_reason.lower()
                     or "unconfirmed" in old_reason.lower())
                and old_reason != new.get("reason")
                and not acknowledged_attempt):
            raise ValueError("Uncertain upload evidence cannot be cleared.")
    old_bytes = previous.get("bytes_sent", 0)
    new_bytes = current.get("bytes_sent", 0)
    if (type(old_bytes) is not int or type(new_bytes) is not int
            or old_bytes < 0 or new_bytes < old_bytes):
        raise ValueError("Existing confirmed byte progress regressed.")
    old_start = previous.get("started_at", 0)
    new_start = current.get("started_at", 0)
    if old_start and old_start != new_start:
        # Retry may reset finished_at, but cannot rewrite the first dispatch.
        raise ValueError("Existing import dispatch history changed.")


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

    def _load_for_write(self) -> Dict[str, Any]:
        """Preserve existing accepted work or refuse a destructive state write.

        Returns:
            Dict[str, Any]: The current verified document or a new empty state.

        Raises:
            ValueError: Existing history is unreadable, redirected or malformed.
        """
        return load_upload_state_for_mutation(self.path)

    def save_job(self, job: ImportJob) -> None:
        """Create or replace one persisted import job.

        Args:
            job (ImportJob): Job snapshot whose accepted history is preserved.

        Returns:
            None: The validated job row was atomically published.

        Raises:
            ValueError: Existing accepted-work evidence is missing or regressed.
            OSError: The state document could not be atomically written.
            RuntimeError: Published state could not be verified by readback.
        """
        with accepted_work_owner(self.path.parent):
            data = self._load_for_write()
            row = job.to_dict()
            jobs = data.setdefault("jobs", {})
            previous = jobs.get(job.id)
            if previous is None:
                # A crash after binding publication can leave an orphan target
                # claim even when the primary job row is missing. Never
                # recreate that ID as an unrelated fresh preflight.
                bound_jobs = read_import_binding_entries(
                    self.path.parent / "flights" / "import-bindings.json"
                )
                if job.id in bound_jobs:
                    raise ValueError(
                        "Import ID already has retained target evidence; review it."
                    )
            if previous is None and (
                row.get("started_at", 0) or row.get("finished_at", 0)
                or row.get("bytes_sent", 0)
                or any(
                    item.get("attempt_count", 0)
                    or item.get("server_result")
                    or item.get("state") in (
                        "uploading", "confirmed", "failed_retryable", "cancelled",
                    )
                    for item in row.get("items", [])
                )
            ):
                # A fresh local snapshot is not evidence that accepted
                # history never existed. A dispatched job may not be silently
                # recreated after a missing or rolled-back upload_state.json.
                raise ValueError(
                    "Previously dispatched import history is unavailable."
                )
            validate_import_job_update(previous, row)
            jobs[job.id] = row
            atomic_write_json(self.path, data)
            if load_upload_state_for_mutation(self.path)["jobs"].get(job.id) != row:
                raise RuntimeError("Saved import job could not be verified.")

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

    def inspect_jobs_for_target_change(self) -> List[ImportJob]:
        """Read accepted import jobs without normalizing or repairing lost state.

        This is a conservative point-in-time inspection, not a cross-process
        lock or proof that a disappeared file never contained accepted work.
        The ordinary legacy reader and its migration behavior are unchanged.

        Returns:
            List[ImportJob]: Jobs from one validated complete state snapshot.

        Raises:
            UploadStateTooLargeError: The complete history exceeds the shared
                safe snapshot envelope.
            ValueError: An existing snapshot is redirected, changed, damaged,
                oversized or otherwise insufficient to authorize a mode change.
        """
        with _STATE_LOCK:
            try:
                before = self.path.lstat()
            except FileNotFoundError:
                return []
            except OSError:
                raise ValueError('Import recovery state is unavailable.') from None
            if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
                    or before.st_dev <= 0 or before.st_ino <= 0):
                raise ValueError('Import recovery state is unavailable.')
            if before.st_size > _UPLOAD_STATE_RMW_MAX_BYTES:
                raise UploadStateTooLargeError(
                    'Import recovery state exceeds the safe size limit.'
                )
            flags = (os.O_RDONLY | getattr(os, 'O_BINARY', 0)
                     | getattr(os, 'O_NOFOLLOW', 0)
                     | getattr(os, 'O_NONBLOCK', 0))
            try:
                with os.fdopen(os.open(self.path, flags), 'rb') as source:
                    opened = os.fstat(source.fileno())
                    raw = source.read(before.st_size + 1)
                    finished = os.fstat(source.fileno())
                after = self.path.lstat()

                def identity(info: os.stat_result) -> tuple[int, int, int, int]:
                    """Capture stable file identity and complete-content metadata.

                    Args:
                        info (os.stat_result): One filesystem observation.

                    Returns:
                        tuple[int, int, int, int]: Device, inode, size and mtime.
                    """
                    return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)

                if (
                    max(opened.st_size, finished.st_size, after.st_size)
                    > _UPLOAD_STATE_RMW_MAX_BYTES
                ):
                    raise UploadStateTooLargeError(
                        'Import recovery state exceeds the safe size limit.'
                    )
                if len(raw) != before.st_size:
                    raise ValueError('Import recovery state changed.')
                if (not stat.S_ISREG(opened.st_mode)
                        or not stat.S_ISREG(after.st_mode)
                        or opened.st_nlink != 1 or after.st_nlink != 1
                        or identity(before) != identity(opened)
                        or identity(opened) != identity(finished)
                        or identity(finished) != identity(after)):
                    raise ValueError('Import recovery state changed.')
                document = json.loads(
                    raw.decode('utf-8'),
                    object_pairs_hook=_strict_mode_history_object,
                    parse_constant=_reject_mode_history_constant,
                    parse_float=_strict_mode_history_float,
                )
                if (not isinstance(document, dict)
                        or type(document.get('schema_version')) is not int
                        or document['schema_version'] != UPLOAD_STATE_SCHEMA_VERSION
                        or any(not isinstance(document.get(section), dict)
                               for section in ('uploaded_paths', 'uploaded_hashes',
                                               'failures', 'jobs'))
                        or not isinstance(document.get('recent_events'), list)):
                    raise ValueError('Import recovery state is malformed.')
                jobs = []
                for job_id, row in document['jobs'].items():
                    if (not isinstance(job_id, str) or not job_id
                            or not isinstance(row, dict) or row.get('id') != job_id
                            or type(row.get('started_at', 0)) not in (int, float)
                            or type(row.get('finished_at', 0)) not in (int, float)
                            or type(row.get('bytes_sent', 0)) is not int
                            or row.get('bytes_sent', 0) < 0
                            or not isinstance(row.get('items'), list)
                            or any(not isinstance(item, dict)
                                   or not isinstance(item.get('id'), str)
                                   or not isinstance(item.get('path'), str)
                                   or type(item.get('attempt_count', 0)) is not int
                                   or item.get('attempt_count', 0) < 0
                                   or not isinstance(item.get('server_result', {}), dict)
                                   or not isinstance(item.get('reason', ''), str)
                                   or item.get('state') not in ITEM_STATES
                                   for item in row['items'])):
                        raise ValueError('Import recovery job is malformed.')
                    jobs.append(ImportJob.from_dict(row))
                return jobs
            except UploadStateTooLargeError:
                raise
            except (OSError, UnicodeError, TypeError, OverflowError, ValueError):
                raise ValueError('Import recovery state needs manual review.') from None

    def admitted_imports_match_target(
        self, bindings: Dict[str, str], target_binding: str,
    ) -> bool:
        """Refuse a target change if any accepted work lacks exact provenance.

        A completed job may retain its binding permanently. This read-only
        predicate must not resolve or delete another process's history, and
        cannot protect against a noncooperating concurrent writer.

        Args:
            bindings (Dict[str, str]): Validated persistent job-to-target ledger.
            target_binding (str): Exact non-secret desired connection fingerprint.

        Returns:
            bool: True only if all unfinished accepted imports belong to this
                target and no dispatch evidence lacks a binding.
        """
        if (not isinstance(bindings, dict)
                or not isinstance(target_binding, str)
                or re.fullmatch(r'[a-f0-9]{64}', target_binding) is None
                or any(not isinstance(key, str) or not isinstance(value, str)
                       or re.fullmatch(r'[a-f0-9]{64}', value) is None
                       for key, value in bindings.items())):
            raise ValueError('Import target binding is unavailable.')
        jobs = self.inspect_jobs_for_target_change()
        indexed = {job.id: job for job in jobs}
        if any(job_id not in indexed for job_id in bindings):
            return False
        for job in jobs:
            counts = job.counts()
            # A terminal item is not evidence that its original target binding
            # was never accepted. Missing ownership blocks *any* target change,
            # even when an older process left a locally complete job behind.
            has_dispatch_evidence = bool(
                job.started_at or job.finished_at or job.bytes_sent
                or any(
                    item.attempt_count or item.server_result
                    or item.state not in (
                        "discovered", "validating", "staged", "hashing",
                        "queued", "skipped_duplicate", "skipped_unsupported",
                    )
                    or "uncertain" in item.reason.lower()
                    or "unconfirmed" in item.reason.lower()
                    for item in job.items
                )
            )
            if job.id not in bindings:
                if has_dispatch_evidence:
                    return False
                # An undispatched preflight is a preview. Retain its record,
                # but do not require it to own an upload destination.
                continue
            if (counts['active'] or counts['failed'] or counts['cancelled']):
                if bindings.get(job.id) != target_binding:
                    return False
        return True

    def recoverable_jobs(self) -> List[ImportJob]:
        """Return jobs that still contain resumable or retryable work."""
        return [job for job in self.list_jobs() if job.recoverable()]

    def delete_job(self, job_id: str) -> None:
        """Refuse unilateral removal of any existing accepted-work job.

        Removing one job without checking its separate target-binding ledger
        could erase the only remaining record after a split publication. Use
        ImportBindings.discard_unbound_preflight() for the strictly unbound,
        undispatched preflight case instead. This method remains available
        for legacy callers but does not erase durable accepted-work evidence.

        Args:
            job_id (str): Job whose removal was requested.

        Returns:
            None: A nonexistent job requires no state publication.

        Raises:
            ValueError: Existing job provenance must be retained.
        """
        with accepted_work_owner(self.path.parent):
            data = self._load_for_write()
            if job_id in data["jobs"]:
                raise ValueError(
                    "Import removal requires verified unbound preflight ownership."
                )

    def append_event(self, event: ActivityEvent) -> None:
        """Append one event through the batched history writer owner.

        Args:
            event (ActivityEvent): Event record to append to recent history.

        Returns:
            None: The bounded event history was atomically published and read back.

        Raises:
            OSError: The state document could not be atomically written.
            RuntimeError: The saved event history failed read-back verification.
        """
        self.append_events([event])

    def append_events(self, event_batch: List[ActivityEvent]) -> None:
        """Append an ordered batch while preserving the shared upload state.

        Args:
            event_batch (List[ActivityEvent]): Ordered recent events to append.

        Returns:
            None: The bounded event history is atomically published and verified.

        Raises:
            OSError: The state document could not be atomically written.
            RuntimeError: The saved event history failed read-back verification.
        """
        if not event_batch:
            return
        with accepted_work_owner(self.path.parent):
            data = self._load_for_write()
            history_rows = data.get("recent_events", [])
            if not isinstance(history_rows, list):
                history_rows = []
            history_rows.extend(event.to_dict() for event in event_batch)
            bounded_events = history_rows[-self.history_limit :]
            data["recent_events"] = bounded_events
            atomic_write_json(self.path, data)
            if (load_upload_state_for_mutation(self.path)["recent_events"]
                    != bounded_events):
                raise RuntimeError("Saved activity history could not be verified.")

    def list_events(self) -> List[ActivityEvent]:
        """Return durable recent events in chronological order."""
        with _STATE_LOCK:
            rows = self._load().get("recent_events", [])
        return [ActivityEvent.from_dict(row) for row in rows if isinstance(row, dict)] if isinstance(rows, list) else []


class ActivityWriter:
    """Batch recent activity persistence away from the Tk event thread."""

    def __init__(self, store: JobStateStore) -> None:
        """Start one daemon writer for a bounded recent-activity queue.

        Args:
            store (JobStateStore): Existing owner of the shared upload-state file.

        Returns:
            None: Starts one memory-buffered worker without reading the profile.
        """
        self._store = store
        self._condition = threading.Condition()
        self._pending: Deque[ActivityEvent] = deque(maxlen=store.history_limit)
        self._closing = False
        self._write_failed = False
        self._thread = threading.Thread(
            target=self._run, name='GalleryActivityWriter', daemon=True,
        )
        self._thread.start()

    def submit(self, event: ActivityEvent) -> bool:
        """Queue one immutable activity snapshot without performing disk I/O.

        Args:
            event (ActivityEvent): Recent event to append to bounded history.

        Returns:
            bool: True when accepted; False after close has begun.
        """
        if not isinstance(event, ActivityEvent):
            raise TypeError('Activity writer accepts ActivityEvent records only.')
        snapshot = ActivityEvent.from_dict(event.to_dict())
        with self._condition:
            if self._closing:
                return False
            self._pending.append(snapshot)
            self._condition.notify()
            return True

    def close(self, timeout: float) -> bool:
        """Flush queued events and join the worker within the caller's deadline.

        Args:
            timeout (float): Maximum seconds to wait for the final write and join.

        Returns:
            bool: True only when the worker stopped without a storage failure.
        """
        deadline = time.monotonic() + max(0.0, timeout)
        with self._condition:
            self._closing = True
            self._condition.notify_all()
        self._thread.join(max(0.0, deadline - time.monotonic()))
        return not self._thread.is_alive() and not self._write_failed

    def _run(self) -> None:
        """Persist ordered event batches without touching the UI thread.

        Returns:
            None: Writes each pending batch once and exits after a close flush.
        """
        while True:
            with self._condition:
                while not self._pending and not self._closing:
                    self._condition.wait()
                if not self._pending and self._closing:
                    return
                if not self._closing:
                    deadline = (
                        time.monotonic()
                        + ACTIVITY_WRITER_BATCH_WINDOW_SECONDS
                    )
                    while not self._closing:
                        remaining = deadline - time.monotonic()
                        if remaining <= 0:
                            break
                        self._condition.wait(remaining)
                batch = list(self._pending)
                self._pending.clear()
            try:
                self._store.append_events(batch)
            except Exception:  # noqa: BLE001
                self._write_failed = True
                logging.error('Could not persist recent activity history.')
