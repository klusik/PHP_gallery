# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/mode_admission.py
# Module Type: Upload Mode Work Admission
# Purpose: Track local work against one immutable upload-mode view.
# Responsibilities:
#   - Refuse stale work admission and mode changes while work remains active.
#   - Allow child drain work to share the parent's captured mode without waiting.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Unintegrated in-process coordination; it does not establish authorization.

The future host must validate config, consent and current server identity,
provide cross-process coordination, and keep admitted work alive through its
final in-flight request. No current uploader path uses this owner.
"""

from dataclasses import dataclass
import threading
from types import TracebackType
from typing import Literal

from .upload_mode import ModeRecord, encode_mode_record


ModeState = Literal[
    'first_setup', 'legacy_unmigrated', 'legacy', 'linked_disconnected',
    'linked_active', 'unknown',
]
WorkKind = Literal['local', 'legacy', 'legacy_revoke', 'linked', 'reconnect']


class ModeAdmissionError(RuntimeError):
    """Report unavailable, stale or busy work admission without secrets."""


@dataclass(frozen=True, eq=False)
class ModeView:
    """Describe one owner-issued, immutable local mode decision.

    Attributes:
        state (ModeState): Local admission state, not server authorization.
        record (ModeRecord | None): Validated non-secret bound record, when known.
    """

    state: ModeState
    record: ModeRecord | None


def _validate_view(state: ModeState, record: ModeRecord | None) -> None:
    """Reject inconsistent admission states before they reach the coordinator.

    Args:
        state (ModeState): Proposed local admission state.
        record (ModeRecord | None): Non-secret mode binding, if available.

    Returns:
        None: The state and record shape are consistent.

    Raises:
        ModeAdmissionError: The state or its binding is invalid.
    """
    try:
        if record is not None and type(record) is not ModeRecord:
            raise ValueError
        if state in ('first_setup', 'legacy_unmigrated', 'unknown'):
            if record is not None:
                raise ValueError
        elif state == 'legacy':
            if record is None:
                raise ValueError
            encode_mode_record(record)
            if record.mode != 'legacy':
                raise ValueError
        elif state in ('linked_disconnected', 'linked_active'):
            if record is None:
                raise ValueError
            encode_mode_record(record)
            if record.mode != 'linked':
                raise ValueError
        else:
            raise ValueError
    except (TypeError, ValueError):
        raise ModeAdmissionError('Upload mode state is unavailable.') from None


class WorkLease:
    """Track a single admitted operation until its final in-flight work ends."""

    def __init__(self, owner: 'ModeAdmission', view: ModeView,
                 kind: WorkKind) -> None:
        """Prepare a lease that only its coordinator can admit.

        Args:
            owner (ModeAdmission): Coordinator owning this process-local lease.
            view (ModeView): Exact view captured when the operation was admitted.
            kind (WorkKind): Local, legacy, legacy revoke, linked or reconnect.

        Returns:
            None: Creates a lease with an unregistered private ticket.
        """
        self._owner = owner
        self._ticket = object()
        self._view = view
        self._kind = kind

    @property
    def view(self) -> ModeView:
        """Return the immutable decision captured by this work lease.

        Returns:
            ModeView: Owner-issued decision for the work's complete lifetime.
        """
        return self._view

    @property
    def kind(self) -> WorkKind:
        """Return the explicit work category captured by this lease.

        Returns:
            WorkKind: Local, legacy, legacy revoke, linked or reconnect.
        """
        return self._kind

    def child(self) -> 'WorkLease':
        """Admit dependent work under the same still-active captured decision.

        Returns:
            WorkLease: Independent child lifetime with the same view and kind.

        Raises:
            ModeAdmissionError: The parent is no longer admitted.
        """
        return self._owner._child(self)

    def close(self) -> None:
        """Release only this lease; other parent or child work stays admitted.

        Returns:
            None: Repeated releases have no effect.
        """
        self._owner._release(self)

    def __enter__(self) -> 'WorkLease':
        """Use an already admitted lease as a context manager.

        Returns:
            WorkLease: The same currently active lease.

        Raises:
            ModeAdmissionError: The lease was already released.
        """
        self._owner._assert_active(self)
        return self

    def __exit__(self, exc_type: type[BaseException] | None,
                 exc_value: BaseException | None,
                 traceback: TracebackType | None) -> bool:
        """Release this lease without suppressing an operation failure.

        Args:
            exc_type (type[BaseException] | None): Raised exception class.
            exc_value (BaseException | None): Raised exception instance.
            traceback (TracebackType | None): Raised exception traceback.

        Returns:
            bool: False so caller exceptions remain visible.
        """
        self.close()
        return False


class ModeTransition:
    """Reserve an idle coordinator during one external mode publication."""

    def __init__(self, owner: 'ModeAdmission', view: ModeView) -> None:
        """Prepare a transition ticket that only its coordinator can reserve.

        Args:
            owner (ModeAdmission): Coordinator owning this reservation.
            view (ModeView): Exact prior decision reviewed by the caller.

        Returns:
            None: Creates an unregistered private transition ticket.
        """
        self._owner = owner
        self._view = view
        self._ticket = object()

    def commit(self, state: ModeState, record: ModeRecord | None = None) -> ModeView:
        """Publish the host-verified result and release the exclusive reservation.

        Args:
            state (ModeState): New state after the caller verified publication.
            record (ModeRecord | None): Validated non-secret target binding.

        Returns:
            ModeView: The newly published owner-issued local decision.

        Raises:
            ModeAdmissionError: State is invalid or this reservation is inactive.
        """
        return self._owner._commit_transition(self, state, record)

    def close(self) -> None:
        """Leave an uncommitted reservation in the conservative unknown state.

        Returns:
            None: Releases this reservation; repeated releases have no effect.
        """
        self._owner._close_transition(self)

    def __enter__(self) -> 'ModeTransition':
        """Use one reserved transition as a context manager.

        Returns:
            ModeTransition: The same currently active reservation.

        Raises:
            ModeAdmissionError: The reservation is no longer active.
        """
        self._owner._assert_transition(self)
        return self

    def __exit__(self, exc_type: type[BaseException] | None,
                 exc_value: BaseException | None,
                 traceback: TracebackType | None) -> bool:
        """Fail closed on an uncommitted exit without suppressing failures.

        Args:
            exc_type (type[BaseException] | None): Raised exception class.
            exc_value (BaseException | None): Raised exception instance.
            traceback (TracebackType | None): Raised exception traceback.

        Returns:
            bool: False so caller exceptions remain visible.
        """
        self.close()
        return False


class ModeAdmission:
    """Serialize local mode changes against all registered operation leases."""

    def __init__(self, state: ModeState, record: ModeRecord | None = None) -> None:
        """Initialize one process-local coordinator with a validated view.

        Args:
            state (ModeState): Initial decision supplied by the future host.
            record (ModeRecord | None): Validated non-secret binding when known.

        Returns:
            None: Creates an idle coordinator without opening storage or network.

        Raises:
            ModeAdmissionError: The initial state or binding is invalid.
        """
        _validate_view(state, record)
        self._lock = threading.RLock()
        self._view = ModeView(state, record)
        self._active: set[object] = set()
        self._transition: object | None = None

    def view(self) -> ModeView:
        """Read the exact current local view for a subsequent admission.

        Returns:
            ModeView: Immutable decision whose identity becomes stale on change.

        Raises:
            ModeAdmissionError: An external transition is still unresolved.
        """
        with self._lock:
            if self._transition is not None:
                raise ModeAdmissionError('Upload mode transition is unavailable.')
            return self._view

    def begin_transition(self, expected: ModeView) -> ModeTransition:
        """Reserve a mode change only after all admitted work has finished.

        The caller separately owns user approval, persistent publication,
        current identity, unresolved work and cross-process exclusion.

        Args:
            expected (ModeView): Exact owner-issued decision previously reviewed.
        Returns:
            ModeTransition: Exclusive reservation blocking new work until closed.

        Raises:
            ModeAdmissionError: The decision is stale, busy or already reserved.
        """
        with self._lock:
            if expected is not self._view or self._active or self._transition is not None:
                raise ModeAdmissionError('Upload mode changed or work is active.')
            reservation = ModeTransition(self, self._view)
            self._transition = reservation._ticket
            return reservation

    def acquire_work(self, expected: ModeView, kind: WorkKind) -> WorkLease:
        """Admit one operation without interpreting a missing target as legacy.

        Args:
            expected (ModeView): Exact current owner-issued decision.
            kind (WorkKind): Explicit local, legacy, legacy revoke, linked or
                reconnect work; a legacy lease does not authorize revocation.

        A legacy_unmigrated view is admitted only on the caller's assertion that
        configuration and vault were verified. Simultaneous evidence loss is
        indistinguishable from that old-profile state without another witness.

        Returns:
            WorkLease: Registered lifetime bound to the same local mode view.

        Raises:
            ModeAdmissionError: The view is stale or the category is unavailable.
        """
        with self._lock:
            allowed = (
                kind == 'local'
                or (kind in ('legacy', 'legacy_revoke')
                    and self._view.state in ('legacy', 'legacy_unmigrated'))
                or (kind == 'linked' and self._view.state == 'linked_active')
                or (kind == 'reconnect' and self._view.state == 'linked_disconnected')
            )
            if expected is not self._view or not allowed or self._transition is not None:
                raise ModeAdmissionError('Upload work is unavailable.')
            lease = WorkLease(self, self._view, kind)
            self._active.add(lease._ticket)
            return lease

    def _child(self, parent: WorkLease) -> WorkLease:
        """Register dependent work without waiting for its parent to finish.

        Args:
            parent (WorkLease): Active work owned by this coordinator.

        Returns:
            WorkLease: Independent child of the same view and work category.

        Raises:
            ModeAdmissionError: The parent is foreign, stale or already closed.
        """
        with self._lock:
            if (parent._owner is not self or parent._ticket not in self._active
                    or parent.view is not self._view):
                raise ModeAdmissionError('Upload work is unavailable.')
            child = WorkLease(self, parent.view, parent.kind)
            self._active.add(child._ticket)
            return child

    def _assert_active(self, lease: WorkLease) -> None:
        """Reject use of a closed or foreign lease.

        Args:
            lease (WorkLease): Lease whose registration must remain active.

        Returns:
            None: The lease is currently registered with this owner.

        Raises:
            ModeAdmissionError: The lease is not active for this owner.
        """
        with self._lock:
            if lease._owner is not self or lease._ticket not in self._active:
                raise ModeAdmissionError('Upload work is unavailable.')

    def _release(self, lease: WorkLease) -> None:
        """Remove only one registered lease without changing the mode.

        Args:
            lease (WorkLease): Parent or child operation ending locally.

        Returns:
            None: Other registered work remains active.
        """
        with self._lock:
            if lease._owner is self:
                self._active.discard(lease._ticket)

    def _assert_transition(self, reservation: ModeTransition) -> None:
        """Check ownership of an exclusive mode transition reservation.

        Args:
            reservation (ModeTransition): Reservation to validate.

        Returns:
            None: The same reservation is active for the original view.

        Raises:
            ModeAdmissionError: The reservation is closed or belongs elsewhere.
        """
        with self._lock:
            if (reservation._owner is not self
                    or reservation._ticket is not self._transition
                    or reservation._view is not self._view):
                raise ModeAdmissionError('Upload mode transition is unavailable.')

    def _commit_transition(self, reservation: ModeTransition, state: ModeState,
                           record: ModeRecord | None) -> ModeView:
        """Complete an active reservation after the host verifies publication.

        Args:
            reservation (ModeTransition): Exact currently active reservation.
            state (ModeState): Host-verified new local state.
            record (ModeRecord | None): Validated non-secret binding, if present.

        Returns:
            ModeView: Newly issued local decision.

        Raises:
            ModeAdmissionError: Validation or reservation ownership failed.
        """
        _validate_view(state, record)
        with self._lock:
            self._assert_transition(reservation)
            self._view = ModeView(state, record)
            self._transition = None
            return self._view

    def _close_transition(self, reservation: ModeTransition) -> None:
        """Resolve an unconfirmed transition to unknown without accepting work.

        Args:
            reservation (ModeTransition): Reservation being closed by the host.

        Returns:
            None: An active reservation is released into the unknown state.
        """
        with self._lock:
            if (reservation._owner is self
                    and reservation._ticket is self._transition):
                self._view = ModeView('unknown', None)
                self._transition = None
