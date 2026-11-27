# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_dispatch_owner.py
# Module Type: Flight Dispatch Persistence Service
# Purpose: Attach fetched dispatch data to one verified stopped flight.
# Responsibilities: Capture and recheck the exact session identity under the
#   shared workspace lease before persisting optional SimBrief data.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Guard saved-flight dispatch attachment without toolkit dependencies."""

from dataclasses import dataclass
import hashlib
import os
from pathlib import Path
import stat

from .flight_jobs import SessionPlan, WorkspaceLease, read_document
from .flight_simbrief import DispatchPlan, OFP_MAX_BYTES, identity_query
from .state_store import atomic_write_json


@dataclass(frozen=True)
class SavedDispatchTarget:
    """Pin one stopped session directory identity across an async fetch."""

    directory: Path
    device: int
    inode: int
    dispatch_signature: tuple[int, int, int, int, str] | None = None


def capture_saved_dispatch_target(
    workspace_root: Path, lease: WorkspaceLease, session: Path,
    active_session: Path | None, finalizer_active: bool,
) -> SavedDispatchTarget:
    """Validate one stopped saved flight before starting provider work.

    Args:
        workspace_root (Path): Per-profile flights workspace.
        lease (WorkspaceLease): Exact current owner of that workspace.
        session (Path): Explicit selected flight directory.
        active_session (Path | None): Live recorder's current session, if any.
        finalizer_active (bool): Whether report finalization owns session files.
    Returns:
        SavedDispatchTarget: Resolved session path and native directory identity.
    Raises:
        OSError: Session or workspace metadata cannot be inspected.
        RuntimeError: Another uploader owns the workspace or capture lifecycle.
        ValueError: The selected source is active, redirected or not stopped.
    """
    lease.verify()
    resolved_root = workspace_root.resolve(strict=True)
    if lease.root.resolve(strict=True) != resolved_root:
        raise ValueError('Saved flight workspace changed.')
    if finalizer_active:
        raise ValueError('Wait for the current operation to finish.')
    if active_session is not None:
        raise ValueError('Finish the current recording before loading a saved OFP.')
    # A persisted witness uses the canonical child path. The workspace can
    # still be addressed through a Windows short-name spelling on relaunch.
    if (session.is_symlink() or session.is_junction()
            or session.parent.resolve(strict=True) != resolved_root):
        raise ValueError('Select a saved flight.')
    resolved_session = session.resolve(strict=True)
    if resolved_session.parent != resolved_root:
        raise ValueError('Select a saved flight.')
    info = resolved_session.stat()
    if not resolved_session.is_dir():
        raise ValueError('Select a saved flight.')
    metadata = read_document(resolved_session / 'session.json')
    if metadata.get('recording_ended') is not True:
        raise ValueError('Finish recording before loading a saved OFP.')
    SessionPlan.load(resolved_session)
    dispatch_path = resolved_session / 'simbrief.json'
    if dispatch_path.is_symlink():
        raise ValueError('Saved dispatch data cannot be redirected.')
    try:
        before = dispatch_path.lstat()
    except FileNotFoundError:
        dispatch_signature = None
    else:
        if not stat.S_ISREG(before.st_mode) or before.st_size > OFP_MAX_BYTES:
            raise ValueError('Saved dispatch data is not a bounded regular file.')
        with dispatch_path.open('rb') as stream:
            opened = os.fstat(stream.fileno())
            if ((opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino)
                    or not stat.S_ISREG(opened.st_mode)):
                raise ValueError('Saved dispatch data changed while it was inspected.')
            # Read one byte beyond the cap to detect growth after the metadata check.
            content = stream.read(OFP_MAX_BYTES + 1)
        after = dispatch_path.lstat()
        if (len(content) != before.st_size or before.st_dev != after.st_dev
                or before.st_ino != after.st_ino or before.st_mtime_ns != after.st_mtime_ns):
            raise ValueError('Saved dispatch data changed while it was inspected.')
        dispatch_signature = (
            after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns,
            hashlib.sha256(content).hexdigest(),
        )
    lease.verify()
    return SavedDispatchTarget(
        resolved_session, info.st_dev, info.st_ino, dispatch_signature,
    )


def persist_saved_dispatch(
    workspace_root: Path, lease: WorkspaceLease, target: SavedDispatchTarget,
    identity: str, plan: DispatchPlan, active_session: Path | None,
    finalizer_active: bool,
) -> None:
    """Persist one fetched OFP only if its original stopped target still owns.

    Args:
        workspace_root (Path): Per-profile flights workspace and account settings.
        lease (WorkspaceLease): Exact current owner of the flights workspace.
        target (SavedDispatchTarget): Identity captured before provider fetch.
        identity (str): Successful SimBrief identity to retain for later use.
        plan (DispatchPlan): Validated bounded provider response.
        active_session (Path | None): Current recorder session, if any.
        finalizer_active (bool): Whether report finalization now owns session files.
    Returns:
        None: Writes the optional dispatch and account identity under the lease.
    Raises:
        OSError: A local persistence operation fails.
        RuntimeError: The shared workspace or lifecycle is busy.
        ValueError: Identity, selected session or ownership changed before commit.
    """
    identity_query(identity)
    try:
        current = capture_saved_dispatch_target(
            workspace_root, lease, target.directory, active_session, finalizer_active,
        )
    except OSError as error:
        raise ValueError('Saved flight changed while SimBrief was loading.') from error
    if (current.directory != target.directory or current.device != target.device
            or current.inode != target.inode
            or current.dispatch_signature != target.dispatch_signature):
        raise ValueError('Saved flight changed while SimBrief was loading.')
    account_path = current.directory.parent / 'simbrief-account.json'
    if account_path.is_symlink():
        raise ValueError('Saved SimBrief identity cannot be redirected.')
    plan.save(current.directory)
    lease.verify()
    atomic_write_json(account_path, {
        'identity': identity.strip(),
    })
    lease.verify()
