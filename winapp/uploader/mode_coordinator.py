# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/mode_coordinator.py
# Module Type: Upload Mode Coordination
# Purpose: Coordinate verified configuration and vault mode evidence headlessly.
# Responsibilities:
#   - Refuse stale or ambiguous evidence before local work admission.
#   - Reserve transitions across vault-first publication and fail closed.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Unintegrated coordinator; the host still owns consent and cross-process locks.

The ConfigStore port must serialize its existing sole writer throughout each
transaction. This module never opens Windows APIs or contacts a gallery itself.
"""

from contextlib import AbstractContextManager
from dataclasses import dataclass
import secrets
import threading
from types import TracebackType
from typing import Callable, Protocol

from .config_snapshot import ConfigSnapshot
from .linked_upload import LinkedUploadTarget
from .mode_admission import ModeAdmission, ModeState, ModeView, WorkKind, WorkLease
from .upload_mode import (
    ModeRecord, ModeVaultTransaction, encode_mode_record, profile_path_digest,
    publish_mode, resolve_mode,
)


class ModeCoordinatorError(RuntimeError):
    """Report unavailable mode evidence without exposing private configuration."""


class ModeConfigTransaction(Protocol):
    """Describe one entered transaction owned by the existing ConfigStore."""

    @property
    def config_path(self) -> str:
        """Return the ConfigStore's current lexical config-path spelling.

        Returns:
            str: Owner path spelling, not a raw CLI argument or filesystem identity.
        """
        ...

    def snapshot(self) -> ConfigSnapshot:
        """Reobserve the complete config while checking owner and identity.

        Returns:
            ConfigSnapshot: Current immutable missing, valid or unavailable evidence.
        """
        ...

    def read_mode_anchor(self, snapshot: ConfigSnapshot) -> bytes | None:
        """Read the anchor only from the same currently verified full snapshot.

        Args:
            snapshot (ConfigSnapshot): Exact ConfigStore-owned valid evidence.

        Returns:
            bytes | None: Strict mode JSON bytes, or confirmed absent anchor.
        """
        ...

    def write_mode_anchor(self, snapshot: ConfigSnapshot,
                          payload: bytes) -> ConfigSnapshot:
        """Compare and update only the anchor under the ConfigStore owner.

        Args:
            snapshot (ConfigSnapshot): Exact prior complete valid evidence.
            payload (bytes): Strict new mode JSON bytes.

        Returns:
            ConfigSnapshot: Read-back evidence after updating the owner revision.
        """
        ...


class ModeConfigPort(Protocol):
    """Supply the future ConfigStore mode transaction without a second writer."""

    def mode_transaction(self) -> AbstractContextManager[ModeConfigTransaction]:
        """Prepare an exclusive transaction on the existing ConfigStore owner.

        Returns:
            AbstractContextManager[ModeConfigTransaction]: Unentered config owner.
        """
        ...


class ModeVaultPort(Protocol):
    """Supply the second upload-mode record under its platform lease."""

    def transaction(self) -> AbstractContextManager[ModeVaultTransaction]:
        """Prepare a mode-only vault transaction and its metadata mutex.

        Returns:
            AbstractContextManager[ModeVaultTransaction]: Unentered vault owner.
        """
        ...


@dataclass(frozen=True, repr=False)
class _Observation:
    """Keep one complete private config and vault observation for comparison.

    Attributes:
        snapshot (ConfigSnapshot): Exact complete ConfigStore-owned evidence.
        path (str): Owner's lexical config-path spelling in this observation.
        anchor (bytes | None): Validated mode JSON or positively absent anchor.
        vault (ModeRecord | None): Validated mode vault record or confirmed absence.
        state (ModeState): Conservative resolved admission state.
        record (ModeRecord | None): Matching mode record when one is present.
    """

    snapshot: ConfigSnapshot
    path: str
    anchor: bytes | None
    vault: ModeRecord | None
    state: ModeState
    record: ModeRecord | None


def _observe(config: ModeConfigTransaction,
             vault: ModeVaultTransaction) -> _Observation:
    """Read both owners consistently under their already acquired transactions.

    Args:
        config (ModeConfigTransaction): Entered existing ConfigStore transaction.
        vault (ModeVaultTransaction): Entered SID-bound vault transaction.

    Returns:
        _Observation: Stable complete local evidence and conservative mode.

    Raises:
        ModeCoordinatorError: A source is unavailable, changed or inconsistent.
    """
    path = config.config_path
    snapshot = config.snapshot()
    if not isinstance(path, str) or not path or not isinstance(snapshot, ConfigSnapshot):
        raise ModeCoordinatorError('Upload mode evidence is unavailable.')
    vault_record = vault.read()
    if snapshot.state == 'missing':
        if vault_record is not None:
            raise ModeCoordinatorError('Upload mode evidence is unavailable.')
        anchor = None
        state: ModeState = 'first_setup'
        record = None
    elif snapshot.state == 'valid':
        anchor = config.read_mode_anchor(snapshot)
        if anchor is not None and type(anchor) is not bytes:
            raise ModeCoordinatorError('Upload mode evidence is unavailable.')
        vault_bytes = encode_mode_record(vault_record) if vault_record is not None else None
        state, record = resolve_mode(anchor, vault_bytes, config_verified=True,
                                     config_path=path)
    else:
        raise ModeCoordinatorError('Upload mode evidence is unavailable.')
    if config.snapshot() != snapshot or config.config_path != path or vault.read() != vault_record:
        raise ModeCoordinatorError('Upload mode evidence changed.')
    return _Observation(snapshot, path, anchor, vault_record, state, record)


class CoordinatedWork:
    """Bind a work lifetime and every new child to coordinator revalidation."""

    def __init__(self, owner: 'ModeCoordinator', lease: WorkLease,
                 config: ModeConfigPort | None = None) -> None:
        """Wrap one already admitted, coordinator-owned work lease.

        Args:
            owner (ModeCoordinator): Owner of the current evidence and lease.
            lease (WorkLease): Private process-local admitted lifetime.
            config (ModeConfigPort | None): Optional captured worker config
                checked against the same complete snapshot on every request.

        Returns:
            None: Creates a wrapper without admitting extra work.
        """
        self._owner = owner
        self._lease = lease
        self._config = config

    @property
    def view(self) -> ModeView:
        """Return the immutable decision captured at work admission.

        Returns:
            ModeView: Original owner-issued view for this work lifetime.
        """
        return self._lease.view

    @property
    def kind(self) -> WorkKind:
        """Return the explicit category captured at work admission.

        Returns:
            WorkKind: Admitted local, legacy, revoke, linked or reconnect work.
        """
        return self._lease.kind

    def checkpoint(self) -> None:
        """Revalidate evidence before the host starts another network request.

        Returns:
            None: The lease and complete current evidence are still accepted.

        Raises:
            ModeCoordinatorError: Work is closed or mode evidence is unavailable.
        """
        self._owner._checkpoint(self)

    def child(self) -> 'CoordinatedWork':
        """Admit dependent work only while the parent's evidence remains current.

        Returns:
            CoordinatedWork: Child with its own lifetime and the same work kind.

        Raises:
            ModeCoordinatorError: Parent or complete mode evidence is unavailable.
        """
        return self._owner._child(self)

    def close(self) -> None:
        """Release this lifetime without cancelling already admitted children.

        Returns:
            None: Other work remains registered; repeated closes have no effect.
        """
        self._owner._close_work(self)

    def __enter__(self) -> 'CoordinatedWork':
        """Recheck this work before entering its scoped lifecycle.

        Returns:
            CoordinatedWork: The same currently admitted and verified work.

        Raises:
            ModeCoordinatorError: Work or current evidence is unavailable.
        """
        try:
            self.checkpoint()
        except BaseException:
            self.close()
            raise
        return self

    def __exit__(self, exc_type: type[BaseException] | None,
                 exc_value: BaseException | None,
                 traceback: TracebackType | None) -> bool:
        """Release this lifetime without suppressing the caller's failure.

        Args:
            exc_type (type[BaseException] | None): Raised exception class.
            exc_value (BaseException | None): Raised exception instance.
            traceback (TracebackType | None): Raised exception traceback.

        Returns:
            bool: False so caller failures remain visible.
        """
        self.close()
        return False


class ModeCoordinator:
    """Coordinate one process's mode evidence and admitted work lifetimes."""

    def __init__(self, config: ModeConfigPort, vault: ModeVaultPort) -> None:
        """Prepare an initially unavailable coordinator without touching storage.

        Args:
            config (ModeConfigPort): Existing ConfigStore mode transaction owner.
            vault (ModeVaultPort): SID-bound mode-only credential store owner.

        Returns:
            None: Creates an unintegrated coordinator in the unknown state.
        """
        self._config = config
        self._vault = vault
        self._gate = threading.RLock()
        self._admission = ModeAdmission('unknown')
        self._evidence: _Observation | None = None
        self._healthy = False

    def view(self) -> ModeView:
        """Return the last verified owner-issued mode without revealing secrets.

        Returns:
            ModeView: Current conservative state and non-secret bound mode record.

        Raises:
            ModeCoordinatorError: Evidence is unavailable or a transition failed.
        """
        with self._gate:
            if not self._healthy:
                raise ModeCoordinatorError('Upload mode evidence is unavailable.')
            return self._admission.view()

    def refresh(self) -> ModeView:
        """Resolve fresh evidence when no previously admitted work is active.

        Returns:
            ModeView: Verified first-setup, legacy or disconnected linked state.

        Raises:
            ModeCoordinatorError: Work is active or either evidence owner fails.
        """
        with self._gate:
            try:
                reservation = self._admission.begin_transition(self._admission.view())
            except RuntimeError:
                raise ModeCoordinatorError('Upload mode work is active.') from None
            self._healthy = False
            self._evidence = None
            try:
                with reservation:
                    with self._vault.transaction() as vault:
                        with self._config.mode_transaction() as config:
                            observation = _observe(config, vault)
                    view = reservation.commit(observation.state, observation.record)
                    self._evidence = observation
                    self._healthy = True
                    return view
            except Exception:
                raise ModeCoordinatorError('Upload mode evidence is unavailable.') from None

    def rebind_config(self, expected: ModeView, config_port: ModeConfigPort) -> ModeView:
        """Adopt an explicitly saved config generation without replacing this owner.

        The host owns consent and may call this only after its existing ConfigStore
        has accepted the replacement configuration. This does not establish that
        a configuration change was authorized, protect against another writer, or
        perform a mode change. The snapshot owner, parent identity, exact path and
        both mode records must remain the same. No work may be active.

        Args:
            expected (ModeView): Exact owner-issued decision before the save.
            config_port (ModeConfigPort): Same ConfigStore bound to its newly
                accepted live configuration object and generation.

        Returns:
            ModeView: Fresh decision with the same mode and binding; the former
                view is stale even when its displayed fields are equal.

        Raises:
            ModeCoordinatorError: Work is active, the view is stale, or the
                replacement config and unchanged mode evidence cannot be proven.
        """
        with self._gate:
            previous = self._evidence
            if not self._healthy or previous is None:
                raise ModeCoordinatorError('Upload mode evidence is unavailable.')
            try:
                reservation = self._admission.begin_transition(expected)
            except RuntimeError:
                raise ModeCoordinatorError('Upload mode work is active or stale.') from None
            self._healthy = False
            self._evidence = None
            try:
                with reservation:
                    with self._vault.transaction() as vault:
                        with config_port.mode_transaction() as config:
                            observation = _observe(config, vault)
                            if (observation.path != previous.path
                                    or observation.snapshot._owner is not previous.snapshot._owner
                                    or observation.snapshot._parent != previous.snapshot._parent
                                    or observation.snapshot.state != previous.snapshot.state
                                    or observation.anchor != previous.anchor
                                    or observation.vault != previous.vault
                                    or observation.record != previous.record
                                    or observation.state != previous.state
                                    or expected.record != observation.record
                                    or (expected.state != observation.state
                                        and not (expected.state == 'linked_active'
                                                 and observation.state == 'linked_disconnected'))):
                                raise ModeCoordinatorError('Upload mode evidence changed.')
                    view = reservation.commit(expected.state, observation.record)
                    self._config = config_port
                    self._evidence = observation
                    self._healthy = True
                    return view
            except Exception:
                raise ModeCoordinatorError('Upload mode evidence is unavailable.') from None

    def save_and_rebind(
        self, expected: ModeView, save: Callable[[], ModeConfigPort],
        *, first_setup: bool = False,
        completed_work: CoordinatedWork | None = None,
    ) -> ModeView:
        """Reserve admission across an explicit config save and its readback.

        The callback must use the same sole ConfigStore and return its port
        bound to the accepted config. It must not perform network I/O. The host
        is responsible for user consent and for choosing first_setup only for
        an explicit first configuration save, never an automatic preference.

        Args:
            expected (ModeView): Exact current owner-issued mode decision.
            save (Callable[[], ModeConfigPort]): One config-owner save and
                returned port for its accepted live configuration object.
            first_setup (bool): Permit missing-to-valid only on explicit setup.
            completed_work (CoordinatedWork | None): Exact server-confirmed
                legacy_revoke lifetime to exchange atomically for this save.
                Ordinary saves omit it and still require fully idle admission.

        Returns:
            ModeView: New decision after complete config and vault readback.

        Raises:
            ModeCoordinatorError: Admission, save or evidence is unavailable.
        """
        with self._gate:
            previous = self._evidence
            if (not self._healthy or previous is None
                    or (expected.state == 'first_setup' and not first_setup)):
                raise ModeCoordinatorError('Upload mode evidence is unavailable.')
            try:
                if completed_work is None:
                    reservation = self._admission.begin_transition(expected)
                else:
                    if completed_work._owner is not self or completed_work.view is not expected:
                        raise ModeCoordinatorError('Revocation cleanup is unavailable.')
                    self._checkpoint(completed_work)
                    reservation = self._admission.begin_transition_from_work(
                        completed_work._lease,
                    )
            except RuntimeError:
                raise ModeCoordinatorError('Upload mode work is active or stale.') from None
            self._healthy = False
            self._evidence = None
            try:
                with reservation:
                    with self._vault.transaction() as vault:
                        with self._config.mode_transaction() as old_config:
                            if _observe(old_config, vault) != previous:
                                raise ModeCoordinatorError('Upload mode evidence changed.')
                        new_port = save()
                        with new_port.mode_transaction() as new_config:
                            current = _observe(new_config, vault)
                            setup = (first_setup and expected.state == 'first_setup'
                                     and previous.snapshot.state == 'missing'
                                     and current.snapshot.state == 'valid'
                                     and current.state == 'legacy_unmigrated')
                            if (current.path != previous.path
                                    or current.snapshot._owner is not previous.snapshot._owner
                                    or current.snapshot._parent != previous.snapshot._parent
                                    or current.anchor != previous.anchor
                                    or current.vault != previous.vault
                                    or current.record != previous.record
                                    or (not setup and (
                                        current.snapshot.state != previous.snapshot.state
                                        or current.state != previous.state))
                                    or expected.record != previous.record):
                                raise ModeCoordinatorError('Upload mode evidence changed.')
                            result_state = current.state if setup else expected.state
                            if (not setup and expected.state != current.state
                                    and not (expected.state == 'linked_active'
                                             and current.state == 'linked_disconnected')):
                                raise ModeCoordinatorError('Upload mode evidence changed.')
                    view = reservation.commit(result_state, current.record)
                    self._config = new_port
                    self._evidence = current
                    self._healthy = True
                    return view
            except Exception:
                raise ModeCoordinatorError('Upload mode save is unconfirmed.') from None

    def acquire_work(self, expected: ModeView, kind: WorkKind,
                     config: ModeConfigPort | None = None) -> CoordinatedWork:
        """Admit work only after comparing the complete current evidence again.

        Args:
            expected (ModeView): Exact previously reviewed coordinator-issued view.
            kind (WorkKind): Explicit local, legacy, legacy revoke, linked or
                reconnect work category; this is not server authorization.
            config (ModeConfigPort | None): Optional exact captured worker
                configuration, independently checked by its ConfigStore owner.

        Returns:
            CoordinatedWork: Lifetime retained until all admitted work ends;
                the host must checkpoint before each new network request.

        Raises:
            ModeCoordinatorError: Evidence, reviewed view or work is unavailable.
        """
        with self._gate:
            if not self._healthy or self._evidence is None:
                raise ModeCoordinatorError('Upload mode evidence is unavailable.')
            try:
                lease = self._admission.acquire_work(expected, kind)
            except RuntimeError:
                raise ModeCoordinatorError('Upload work is unavailable.') from None
            try:
                with self._vault.transaction() as vault:
                    with self._config.mode_transaction() as owner_config:
                        current = _observe(owner_config, vault)
                    if config is not None:
                        with config.mode_transaction() as worker_config:
                            if _observe(worker_config, vault) != current:
                                raise ModeCoordinatorError('Upload worker configuration changed.')
                if current != self._evidence:
                    raise ModeCoordinatorError('Upload mode evidence changed.')
                return CoordinatedWork(self, lease, config)
            except BaseException as exc:
                self._healthy = False
                self._evidence = None
                lease.close()
                if isinstance(exc, Exception):
                    raise ModeCoordinatorError('Upload mode evidence is unavailable.') from None
                raise

    def _checkpoint(self, work: CoordinatedWork) -> None:
        """Reject new effects after a lease closes or evidence becomes stale.

        Args:
            work (CoordinatedWork): Existing work owned by this coordinator.

        Returns:
            None: Lease and current complete evidence remain valid.

        Raises:
            ModeCoordinatorError: Work is closed or the evidence is unavailable.
        """
        with self._gate:
            if (work._owner is not self or work._lease._owner is not self._admission
                    or not self._healthy or self._evidence is None):
                raise ModeCoordinatorError('Upload mode evidence is unavailable.')
            try:
                work._lease.__enter__()
            except RuntimeError:
                raise ModeCoordinatorError('Upload work is unavailable.') from None
            try:
                with self._vault.transaction() as vault:
                    with self._config.mode_transaction() as config:
                        current = _observe(config, vault)
                    if work._config is not None:
                        with work._config.mode_transaction() as worker_config:
                            if _observe(worker_config, vault) != current:
                                raise ModeCoordinatorError('Upload worker configuration changed.')
                if current != self._evidence:
                    raise ModeCoordinatorError('Upload mode evidence changed.')
            except BaseException as exc:
                self._healthy = False
                self._evidence = None
                if isinstance(exc, Exception):
                    raise ModeCoordinatorError('Upload mode evidence is unavailable.') from None
                raise

    def _child(self, parent: CoordinatedWork) -> CoordinatedWork:
        """Recheck evidence and admit a child without waiting for its parent.

        Args:
            parent (CoordinatedWork): Existing active coordinator-owned work.

        Returns:
            CoordinatedWork: Independently retained child of the same category.

        Raises:
            ModeCoordinatorError: The parent or current evidence is unavailable.
        """
        with self._gate:
            self._checkpoint(parent)
            try:
                child = parent._lease.child()
            except RuntimeError:
                raise ModeCoordinatorError('Upload work is unavailable.') from None
            try:
                return CoordinatedWork(self, child, parent._config)
            except BaseException:
                child.close()
                raise

    def _close_work(self, work: CoordinatedWork) -> None:
        """Release one owned lifetime even after mode evidence becomes unknown.

        Args:
            work (CoordinatedWork): Existing work to release.

        Returns:
            None: This lifetime is released without changing other work.
        """
        with self._gate:
            if work._owner is self and work._lease._owner is self._admission:
                work._lease.close()

    def publish(self, expected: ModeView, record: ModeRecord) -> ModeView:
        """Publish one externally approved generation through both existing owners.

        The host must first verify consent, live identity, unresolved work and
        cross-process exclusivity. This method never authenticates a gallery.

        Args:
            expected (ModeView): Exact mode view reviewed before user consent.
            record (ModeRecord): Explicit non-secret new profile and target binding.

        Returns:
            ModeView: Verified legacy or linked-disconnected published generation.

        Raises:
            ModeCoordinatorError: Evidence, admission or publication is uncertain.
        """
        with self._gate:
            previous = self._evidence
            if not self._healthy or previous is None or previous.snapshot.state != 'valid':
                raise ModeCoordinatorError('Upload mode evidence is unavailable.')
            try:
                reservation = self._admission.begin_transition(expected)
            except RuntimeError:
                raise ModeCoordinatorError('Upload mode work is active.') from None
            self._healthy = False
            self._evidence = None
            try:
                with reservation:
                    with self._vault.transaction() as vault:
                        with self._config.mode_transaction() as config:
                            if _observe(config, vault) != previous:
                                raise ModeCoordinatorError('Upload mode evidence changed.')
                            snapshot = previous.snapshot

                            def read_anchor() -> bytes | None:
                                """Recheck the entire config before reading its anchor.

                                Returns:
                                    bytes | None: Current validated anchor or absence.
                                """
                                if config.snapshot() != snapshot or config.config_path != previous.path:
                                    raise ModeCoordinatorError('Upload mode evidence changed.')
                                return config.read_mode_anchor(snapshot)

                            def write_anchor(payload: bytes) -> None:
                                """Publish only the anchor against the exact snapshot.

                                Args:
                                    payload (bytes): Validated new mode JSON bytes.

                                Returns:
                                    None: Advances to the verified config snapshot.
                                """
                                nonlocal snapshot
                                snapshot = config.write_mode_anchor(snapshot, payload)

                            publish_mode(record, vault, read_anchor, write_anchor,
                                         config_verified=True,
                                         expected_previous=previous.record,
                                         config_path=previous.path)
                            observation = _observe(config, vault)
                            if observation.record != record or observation.snapshot != snapshot:
                                raise ModeCoordinatorError('Upload mode publication is uncertain.')
                    view = reservation.commit(observation.state, observation.record)
                    self._evidence = observation
                    self._healthy = True
                    return view
            except Exception:
                raise ModeCoordinatorError('Upload mode publication is uncertain.') from None

    def activate_linked(self, expected: ModeView,
                        target: LinkedUploadTarget) -> ModeView:
        """Activate a caller-verified target against unchanged local evidence.

        The host must freshly verify server identity and gallery before calling,
        keep its UI owner current, and retain this same target for admitted work.
        This method performs no network request; later requests still require
        server authorization and a live session. It does not persist activation.

        Args:
            expected (ModeView): Exact owner-issued disconnected decision.
            target (LinkedUploadTarget): Freshly verified target and session.

        Returns:
            ModeView: New local linked-active decision for the same record.

        Raises:
            ModeCoordinatorError: The view, target or either owner is stale,
                unavailable or busy.
        """
        with self._gate:
            previous = self._evidence
            if (not self._healthy or previous is None
                    or expected.state != 'linked_disconnected'
                    or expected.record is None or previous.record != expected.record
                    or type(target) is not LinkedUploadTarget):
                raise ModeCoordinatorError('Linked target is unavailable.')
            try:
                reservation = self._admission.begin_transition(expected)
            except RuntimeError:
                raise ModeCoordinatorError('Upload mode work is active or stale.') from None
            self._healthy = False
            self._evidence = None
            try:
                with reservation:
                    with self._vault.transaction() as vault:
                        with self._config.mode_transaction() as config:
                            observation = _observe(config, vault)
                            record = observation.record
                            if (observation != previous or record is None
                                    or observation.state != 'linked_disconnected'
                                    or record.mode != 'linked'
                                    or target.issuer != record.issuer
                                    or target.user_id != record.account_id
                                    or target.gallery_id != record.gallery_id
                                    or not target.session.matches_issuer(target.issuer)
                                    or not target.session.can_upload()
                                    or target.session.approved_scope() != record.scope):
                                raise ModeCoordinatorError('Linked target changed.')
                            view = reservation.commit('linked_active', record)
                    self._evidence = observation
                    self._healthy = True
                    return view
            except Exception:
                raise ModeCoordinatorError('Linked target is unavailable.') from None


    def select_linked(self, expected: ModeView, target: LinkedUploadTarget) -> ModeView:
        """Publish an explicitly selected target and activate the verified grant.

        This is the local publication boundary, not network authorization.
        The host must already have received an explicit user choice and a fresh
        server verification from verify_linked_upload_target. A matching saved
        linked profile after restart is reactivated without rotating its mode
        generation. Changing galleries or connection mode publishes a new
        non-secret generation under both existing persistent owners.

        Args:
            expected (ModeView): Exact reviewed GUI mode decision.
            target (LinkedUploadTarget): Freshly verified server-owned gallery.

        Returns:
            ModeView: Active linked selection, or no result if activation fails.

        Raises:
            ModeCoordinatorError: The selection, evidence or work is unsafe.
        """
        with self._gate:
            evidence = self._evidence
            if (not self._healthy or evidence is None
                    or expected is not self._admission.view()
                    or evidence.snapshot.state != 'valid'
                    or expected.state not in ('legacy_unmigrated', 'legacy',
                                               'linked_disconnected', 'linked_active')
                    or type(target) is not LinkedUploadTarget
                    or not target.session.matches_issuer(target.issuer)
                    or not target.session.can_upload()):
                raise ModeCoordinatorError('Linked target is unavailable.')
            scope = target.session.approved_scope()
            record = expected.record
            matching = (record is not None and record.mode == 'linked'
                        and record.issuer == target.issuer
                        and record.account_id == target.user_id
                        and record.gallery_id == target.gallery_id
                        and record.scope == scope)
            if matching:
                if expected.state == 'linked_active':
                    expected = self.refresh()
                return self.activate_linked(expected, target)
            try:
                path_hash = profile_path_digest(evidence.path)
                new_record = ModeRecord(
                    record.profile_id if record is not None else secrets.token_hex(16),
                    secrets.token_hex(16), 'linked', path_hash, target.issuer,
                    target.user_id, scope, target.gallery_id,
                )
                encode_mode_record(new_record)
            except (TypeError, ValueError):
                raise ModeCoordinatorError('Linked target is unavailable.') from None
            disconnected = self.publish(expected, new_record)
            return self.activate_linked(disconnected, target)

    def select_legacy(self, expected: ModeView) -> ModeView:
        """Explicitly return from linked mode without replacing the saved key.

        This is never an automatic fallback. The GUI must independently obtain
        user consent and refuse unresolved work before invoking the transition.

        Args:
            expected (ModeView): Exact current decision selected by the user.

        Returns:
            ModeView: Verified legacy mode or existing unmigrated legacy mode.

        Raises:
            ModeCoordinatorError: The transition or storage cannot be verified.
        """
        with self._gate:
            evidence = self._evidence
            if (not self._healthy or evidence is None
                    or expected is not self._admission.view()
                    or evidence.snapshot.state != 'valid'):
                raise ModeCoordinatorError('Upload mode is unavailable.')
            if expected.state in ('legacy_unmigrated', 'legacy'):
                return expected
            if (expected.state not in ('linked_active', 'linked_disconnected')
                    or expected.record is None):
                raise ModeCoordinatorError('Upload mode is unavailable.')
            try:
                path_hash = profile_path_digest(evidence.path)
            except (TypeError, ValueError):
                raise ModeCoordinatorError('Upload mode is unavailable.') from None
            record = ModeRecord(
                expected.record.profile_id, secrets.token_hex(16),
                'legacy', path_hash,
            )
            return self.publish(expected, record)


class GuiModeOwner:
    """Retain one coordinator across GUI config saves and worker lifetimes."""

    def __init__(self, config: ModeConfigPort,
                 vault_factory: Callable[[], ModeVaultPort]) -> None:
        """Create an unavailable owner before opening platform storage.

        Args:
            config (ModeConfigPort): Port for the host's loaded configuration.
            vault_factory (Callable[[], ModeVaultPort]): Creates the separate
                mode-only vault owner on the background startup thread.

        Returns:
            None: Leaves all legacy work blocked until initialization succeeds.
        """
        self._lock = threading.RLock()
        self._config = config
        self._vault_factory = vault_factory
        self._coordinator: ModeCoordinator | None = None
        self._view: ModeView | None = None
        self._initialized = False

    def initialize(self) -> None:
        """Observe startup evidence once without admitting an unverified key.

        Returns:
            None: Stores a verified mode or retains the unavailable state.

        Raises:
            ModeCoordinatorError: Storage, identity or startup evidence failed.
        """
        with self._lock:
            if self._initialized:
                raise ModeCoordinatorError('Upload mode is unavailable.')
            self._initialized = True
        try:
            coordinator = ModeCoordinator(self._config, self._vault_factory())
            view = coordinator.refresh()
            with self._lock:
                self._coordinator = coordinator
                self._view = view
        except Exception:
            raise ModeCoordinatorError('Upload mode is unavailable.') from None

    def acquire(self, kind: WorkKind, config: ModeConfigPort) -> CoordinatedWork:
        """Admit one GUI work lifetime against current full mode evidence.

        Args:
            kind (WorkKind): Explicit legacy, revoke, linked or local category.
            config (ModeConfigPort): Port bound to the exact immutable worker
                configuration and its ConfigStore snapshot generation.

        Returns:
            CoordinatedWork: Registered lifetime; caller closes after its work.

        Raises:
            ModeCoordinatorError: Startup, state or evidence refuses the work.
        """
        with self._lock:
            if self._coordinator is None or self._view is None:
                raise ModeCoordinatorError('Upload mode is unavailable.')
            return self._coordinator.acquire_work(self._view, kind, config)

    def save(self, save: Callable[[], ModeConfigPort], *,
             first_setup: bool = False,
             completed_work: CoordinatedWork | None = None) -> None:
        """Serialize a GUI configuration save against all admitted work.

        Args:
            save (Callable[[], ModeConfigPort]): Sole-owner config write and
                port bound to its successfully saved replacement object.
            first_setup (bool): True only for an explicit first setup save.
            completed_work (CoordinatedWork | None): Exact confirmed revocation
                lifetime handed atomically to the configuration save, or None.

        Returns:
            None: Keeps the same coordinator with a fresh owner-issued view.

        Raises:
            ModeCoordinatorError: Work is active or save evidence is uncertain.
        """
        with self._lock:
            if self._coordinator is None or self._view is None:
                raise ModeCoordinatorError('Upload mode is unavailable.')
            try:
                options = (
                    {'completed_work': completed_work}
                    if completed_work is not None else {}
                )
                self._view = self._coordinator.save_and_rebind(
                    self._view, save, first_setup=first_setup,
                    **options,
                )
            except Exception:
                try:
                    self._view = self._coordinator.view()
                except ModeCoordinatorError:
                    self._view = None
                raise

    def view(self) -> ModeView:
        """Inspect only the verified current GUI mode decision.

        Returns:
            ModeView: Exact coordinator-issued view for this process.

        Raises:
            ModeCoordinatorError: Startup or current mode evidence is unavailable.
        """
        with self._lock:
            if self._coordinator is None or self._view is None:
                raise ModeCoordinatorError('Upload mode is unavailable.')
            current = self._coordinator.view()
            if current is not self._view:
                raise ModeCoordinatorError('Upload mode is unavailable.')
            return current

    def publish(self, record: ModeRecord) -> ModeView:
        """Publish an explicitly authorized selection via the existing owners.

        The host must enforce consent and a safe unresolved-work handoff before
        calling this method. A failed or uncertain publication never falls back
        to the previous gallery API key.

        Args:
            record (ModeRecord): Fully bound new mode generation to publish.

        Returns:
            ModeView: Newly verified selection, disconnected if linked.

        Raises:
            ModeCoordinatorError: Startup, work or persistent evidence is unsafe.
        """
        with self._lock:
            if self._coordinator is None or self._view is None:
                raise ModeCoordinatorError('Upload mode is unavailable.')
            try:
                self._view = self._coordinator.publish(self._view, record)
                return self._view
            except Exception:
                try:
                    self._view = self._coordinator.view()
                except ModeCoordinatorError:
                    self._view = None
                raise

    def activate_linked(self, target: LinkedUploadTarget) -> ModeView:
        """Activate one freshly verified target against the saved linked record.

        No token or mode decision is synthesized by this method. The caller must
        recheck its live session and server-provided gallery before activating.

        Args:
            target (LinkedUploadTarget): Freshly verified exact remote target.

        Returns:
            ModeView: Owner-issued active linked decision for this process.

        Raises:
            ModeCoordinatorError: Saved mode, target or work is unavailable.
        """
        with self._lock:
            if self._coordinator is None or self._view is None:
                raise ModeCoordinatorError('Upload mode is unavailable.')
            try:
                self._view = self._coordinator.activate_linked(self._view, target)
                return self._view
            except Exception:
                try:
                    self._view = self._coordinator.view()
                except ModeCoordinatorError:
                    self._view = None
                raise
    def select_linked(self, target: LinkedUploadTarget) -> ModeView:
        """Complete an explicit target choice against verified GUI mode evidence.

        The caller must already have verified identity, exact gallery and user
        consent, and must not use this method for automatic startup selection.

        Args:
            target (LinkedUploadTarget): Fresh server-verified linked destination.

        Returns:
            ModeView: Active linked view after durable mode publication.

        Raises:
            ModeCoordinatorError: Work, saved mode or target is unsafe.
        """
        with self._lock:
            if self._coordinator is None or self._view is None:
                raise ModeCoordinatorError('Upload mode is unavailable.')
            try:
                self._view = self._coordinator.select_linked(self._view, target)
                return self._view
            except Exception:
                try:
                    self._view = self._coordinator.view()
                except ModeCoordinatorError:
                    self._view = None
                raise

    def select_legacy(self) -> ModeView:
        """Honor only an explicit GUI choice to use the preserved API key.

        Returns:
            ModeView: Current verified legacy state after any required publish.

        Raises:
            ModeCoordinatorError: Work, config or mode evidence is unavailable.
        """
        with self._lock:
            if self._coordinator is None or self._view is None:
                raise ModeCoordinatorError('Upload mode is unavailable.')
            try:
                self._view = self._coordinator.select_legacy(self._view)
                return self._view
            except Exception:
                try:
                    self._view = self._coordinator.view()
                except ModeCoordinatorError:
                    self._view = None
                raise
