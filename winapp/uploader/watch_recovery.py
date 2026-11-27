# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watch_recovery.py
# Module Type: Recovery Service
# Purpose: Coordinate receipt, skip and one-photo approval for uncertain uploads.
# Responsibilities:
#   - Expose immutable queue review snapshots and validated counts.
#   - Require local leases, exact current head and retained-source evidence.
#   - Persist receipt, skip and one-time photo-upload decisions idempotently.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Coordinate explicit, head-only recovery decisions for a capture queue."""

from dataclasses import dataclass
from contextlib import nullcontext
from hashlib import sha256
import os
from pathlib import Path
import re
from typing import Callable, ContextManager, Literal
import uuid

from .linked_upload import (LinkedTargetLease, UploadPipelineLease,
                            capture_linked_source, verify_linked_source)
from .models import JsonValue
from .watch_queue import (WatchQueueItem, WatchQueueStore,
                          observe_watch_source)

# Purpose: Bound image bytes admitted by the recovery preview readers.
# Type: int. Units: bytes. Scope: one exact local or remote recovery preview.
# Consumers: read_recovery_preview and the desktop image decoders.
# Rationale: forty MiB preserves the existing Tk limit for captured sources.
PREVIEW_MAX_BYTES = 40 * 1024 * 1024

# Purpose: Bound untrusted filenames and target labels in both interfaces.
# Type: int. Units: characters. Scope: queue metadata labels.
# Consumers: sanitize_recovery_label. Rationale: 160 characters preserve context
# without widening a narrow recovery dialog.
DISPLAY_TEXT_MAX_CHARS = 160
# Purpose: Show a recognizable digest prefix without widening the evidence row.
# Type: int. Units: hexadecimal characters. Scope: desktop recovery display.
# Consumers: Legacy Tk and Qt metadata. Rationale: 16 characters suffice for
# recognizing the displayed item; decision checks retain the complete digest.
RECOVERY_DIGEST_PREFIX_CHARS = 16

WatchRecoveryRemote = tuple[bytes, Literal['receipt', 'observation']]


def sanitize_recovery_label(value: str) -> str:
    """Remove control characters and bound untrusted recovery metadata.

    Args:
        value (str): Filename, target or reason copied from a queue or service.

    Returns:
        str: Single-line printable label bounded to the display limit.
    """
    sanitized = re.sub(r"[\x00-\x1f\x7f]", " ", value).strip()
    if len(sanitized) > DISPLAY_TEXT_MAX_CHARS:
        return sanitized[:DISPLAY_TEXT_MAX_CHARS - 1] + "…"
    return sanitized


@dataclass(frozen=True)
class WatchRecoverySnapshot:
    """Describe one immutable view of durable recovery state."""

    item_id: str
    sequence: int
    source_path: str
    source_signature: str
    source_size: int
    source_mtime_ns: int
    sha256: str
    state: str
    target_binding: str
    session_id: str
    pending_count: int
    acknowledged_count: int
    skipped_count: int
    last_confirmed: str
    last_confirmed_sequence: int
    last_confirmed_at: float
    progress: str
    progress_at: float
    phase: str
    http_status: int | None
    captured_at: float
    expected_mode: str
    scope: str
    review_blocked_by_earlier_upload: bool
    retained_stop: bool
    prior_blocker_marker: str
    prior_blocker_path: str
    prior_blocker_sha256: str
    prior_blocker_target_binding: str
    error: str

    @property
    def decision_token(self) -> tuple[str, int, str, str, str, str, str]:
        """Return an opaque stable tuple for stale-view comparisons.

        Returns:
            tuple[str, int, str, str, str, str, str]: Exact identity, evidence,
                target and session fields.
        """
        return (self.item_id, self.sequence, self.source_path,
                self.source_signature, self.sha256, self.target_binding,
                self.session_id)


def read_recovery_preview(snapshot: WatchRecoverySnapshot) -> tuple[bytes, str]:
    """Read only the local file generation represented by the recovery row.

    Args:
        snapshot (WatchRecoverySnapshot): Exact retained head witness.
    Returns:
        tuple[bytes, str]: Bounded preview bytes and their computed SHA-256.
    Raises:
        OSError: The file is unavailable, oversized, replaced or unverified.
    """
    path = Path(snapshot.source_path)
    info = path.lstat()
    observed = observe_watch_source(path, snapshot.captured_at)
    if (observed.signature != snapshot.source_signature or path.is_symlink()
            or info.st_size > PREVIEW_MAX_BYTES
            or info.st_size != getattr(snapshot, 'source_size', None)):
        raise OSError('Retained source generation changed.')
    expected_mtime = getattr(snapshot, 'source_mtime_ns', 0)
    if expected_mtime > 0 and info.st_mtime_ns != expected_mtime:
        raise OSError('Retained source generation changed.')
    with path.open('rb') as source:
        opened = os.fstat(source.fileno())
        before = (opened.st_dev, opened.st_ino, opened.st_size, opened.st_mtime_ns)
        if before != (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns):
            raise OSError('Retained source generation changed.')
        content = source.read(PREVIEW_MAX_BYTES + 1)
        after_handle = os.fstat(source.fileno())
    after = path.lstat()
    if (len(content) > PREVIEW_MAX_BYTES or path.is_symlink()
            or (after.st_dev, after.st_ino) != before[:2]
            or (after_handle.st_size, after_handle.st_mtime_ns) != before[2:]):
        raise OSError('Retained source changed during preview read.')
    digest = sha256(content).hexdigest()
    if snapshot.sha256 and digest != snapshot.sha256:
        raise OSError('Retained source hash changed.')
    return content, digest


class WatchRecoveryCoordinator:
    """Validate explicit decisions against queue, target and live ownership."""

    def __init__(
        self,
        store: WatchQueueStore,
        pipeline_lease: UploadPipelineLease,
        linked_lease: LinkedTargetLease | None,
        receipt_lookup: Callable[[WatchQueueItem], bool],
        transport_active: Callable[[], bool],
        target_binding: str | Callable[[], str],
        mode: str | Callable[[], str],
        linked_review: Callable[[WatchQueueItem, str], None],
        earlier_blocker_details: Callable[[WatchQueueItem], tuple[str, str, str]] | None = None,
        earlier_blocker_review: Callable[[WatchQueueItem, str], None] | None = None,
        decision_gate: ContextManager[object] | None = None,
    ) -> None:
        """Bind recovery to the existing queue and verified upload context.

        Args:
            store (WatchQueueStore): Restored durable capture queue.
            pipeline_lease (UploadPipelineLease): Shared local pipeline fence.
            linked_lease (LinkedTargetLease | None): Existing linked target fence.
            receipt_lookup (Callable[[WatchQueueItem], bool]): Exact durable receipt check.
            transport_active (Callable[[], bool]): Reports a live upload transport.
            target_binding (str | Callable[[], str]): Current verified target
                fingerprint or a callback that reads it at decision time.
            mode (str | Callable[[], str]): Current mode name or live reader.
            linked_review (Callable[[WatchQueueItem, str], None]): Idempotent
                linked history publication for the exact reviewed uncertainty.
            earlier_blocker_details (Callable[[WatchQueueItem], tuple[str, str, str]] | None):
                Return exact earlier marker, source path and SHA-256.
            earlier_blocker_review (Callable[[WatchQueueItem, str], None] | None):
                Idempotent linked review of the exact earlier marker.
            decision_gate (ContextManager[object] | None): Shared worker context manager that
                serializes review decisions against head processing.

        Returns:
            None: Retains callbacks and immutable target expectations.
        """
        self.store = store
        self.pipeline_lease = pipeline_lease
        self.linked_lease = linked_lease
        self.receipt_lookup = receipt_lookup
        self.transport_active = transport_active
        self.target_binding = target_binding
        self.mode = mode
        self.expected_mode = mode() if callable(mode) else mode
        self.linked_review = linked_review
        self.earlier_blocker_details = earlier_blocker_details
        self.earlier_blocker_review = earlier_blocker_review
        self.decision_gate = decision_gate

    def snapshot(self) -> WatchRecoverySnapshot | None:
        """Return current head and durable progress without changing queue state.

        Returns:
            WatchRecoverySnapshot | None: Head review snapshot, or None if clear.
        """
        with self._decision_context(), self.store.lock:
            if not self.store.data.get('session_id'):
                raise ValueError('Recovery session identity is unavailable.')
            items = self.store.items()
            head = self.store.head()
            if head is None:
                return None
            skipped = sum(item.disposition == 'skipped_by_user' for item in items)
            acknowledged = sum(item.state == 'acknowledged' for item in items)
            last_confirmed_item = next((item for item in reversed(items)
                                        if item.state == 'acknowledged'), None)
            last_confirmed = (str(last_confirmed_item.sequence)
                              if last_confirmed_item else '')
            prior_blocked = self._earlier_blocker(head)
            blocker = (self.earlier_blocker_details(head)
                       if prior_blocked and self.earlier_blocker_details
                       else ('', '', ''))
            return WatchRecoverySnapshot(
                head.item_id, head.sequence, head.source.path, head.source.signature,
                head.source.size, head.source.modified_ns,
                head.sha256, head.state,
                self.target_binding() if callable(self.target_binding) else self.target_binding,
                str(self.store.data.get('session_id', '')),
                sum(item.state not in ('acknowledged', 'skipped') for item in items),
                acknowledged, skipped, last_confirmed,
                last_confirmed_item.sequence if last_confirmed_item else 0,
                float(last_confirmed_item.disposition_at)
                if last_confirmed_item else 0.0,
                f'{acknowledged} acknowledged, {skipped} skipped',
                float(self.store.data.get('last_change_at', 0.0)),
                head.phase, head.http_status, head.source.captured_at,
                self.expected_mode, self.store.scope,
                prior_blocked, self.store.data.get('retained_stop') is True,
                blocker[0], blocker[1], blocker[2],
                self.target_binding() if callable(self.target_binding) else self.target_binding,
                head.reason or str(self.store.data.get('error', '')),
            )

    def confirm_receipt(self, snapshot: WatchRecoverySnapshot) -> WatchRecoverySnapshot | None:
        """Resolve the current item only when its exact old receipt is proven.

        Args:
            snapshot (WatchRecoverySnapshot): Previously displayed head snapshot.

        Returns:
            WatchRecoverySnapshot | None: Updated view after durable confirmation.

        Raises:
            ValueError: Context, live transport, head, or receipt evidence is invalid.
            OSError: Queue publication fails or cannot be read back.
        """
        with self._decision_context():
            self._validate(snapshot)
            with self.store.lock:
                head = self._matching_head(snapshot)
                if (head is None or head.state != 'uncertain' or not head.sha256
                        or self._earlier_blocker(head)
                        or not self.receipt_lookup(head)):
                    raise ValueError('No exact durable acknowledgement receipt exists.')
                self._write_intent(head, 'confirmed_by_receipt')
                self.linked_review(head, 'confirmed_by_receipt')
                self.store.record_recovery_disposition(head, 'confirmed_by_receipt')
            return self.snapshot()

    def can_confirm_receipt(self, snapshot: WatchRecoverySnapshot) -> bool:
        """Probe exact durable receipt availability without mutating state.

        Args:
            snapshot (WatchRecoverySnapshot): Displayed uncertain-head snapshot.

        Returns:
            bool: True only when context, exact generation and receipt all match.
        """
        try:
            with self._decision_context():
                self._validate(snapshot)
                with self.store.lock:
                    head = self._matching_head(snapshot)
                    return bool(head is not None and head.state == 'uncertain'
                                and not self._earlier_blocker(head) and head.sha256
                                and self.receipt_lookup(head))
        except (OSError, ValueError, RuntimeError):
            return False

    def can_approve_photo_upload(self, snapshot: WatchRecoverySnapshot) -> bool:
        """Probe whether the exact retained uncertain photo can be released.

        Args:
            snapshot (WatchRecoverySnapshot): Displayed uncertain-head snapshot.

        Returns:
            bool: True only when all local fences and retained source evidence match.
        """
        try:
            with self._decision_context():
                self._validate(snapshot)
                with self.store.lock:
                    head = self._matching_head(snapshot)
                    return bool(head is not None and head.state == 'uncertain'
                                and head.sha256 and not self._earlier_blocker(head)
                                and self.store.data.get('recovery_intent') is None
                                and self.store.data.get('approved_upload') is None
                                and self._source_matches(head))
        except (OSError, ValueError, RuntimeError):
            return False

    def approve_photo_upload(
        self, snapshot: WatchRecoverySnapshot,
    ) -> WatchRecoverySnapshot | None:
        """Release one explicitly approved uncertain photo into normal FIFO checks.

        Args:
            snapshot (WatchRecoverySnapshot): Exact currently displayed head view.

        Returns:
            WatchRecoverySnapshot | None: Updated view after the approval is durable.

        Raises:
            ValueError: Context, source, head or linked marker changed.
            OSError: Durable intent or queue publication failed.
            RuntimeError: Retained source or linked history verification failed.
        """
        with self._decision_context():
            self._validate(snapshot)
            with self.store.lock:
                head = self._matching_head(snapshot)
                if (head is None or head.state != 'uncertain' or not head.sha256
                        or self._earlier_blocker(head)
                        or self.store.data.get('approved_upload') is not None
                        or not self._source_matches(head)):
                    raise ValueError('The uncertain photo no longer matches this review.')
                token = uuid.uuid4().hex
                approved_at = self.store.clock()
                intent = self._approval_record(head, snapshot, token, approved_at)
                row = dict(self.store.data)
                existing = row.get('recovery_intent')
                if existing is not None and existing != intent:
                    raise ValueError('Another recovery decision needs repair first.')
                row['recovery_intent'] = intent
                self.store._publish(row)
                self.linked_review(head, 'approved_photo_upload')
                self.store.approve_photo_upload(head, self._approval_entry(intent))
            return self.snapshot()

    def skip_head(self, snapshot: WatchRecoverySnapshot) -> WatchRecoverySnapshot | None:
        """Explicitly retain and skip the exact current unresolved source.

        Args:
            snapshot (WatchRecoverySnapshot): Previously displayed head snapshot.

        Returns:
            WatchRecoverySnapshot | None: Updated review view after durable skip.

        Raises:
            ValueError: Context, live transport, or displayed head is stale.
            OSError: Queue publication fails or cannot be read back.
        """
        with self._decision_context():
            self._validate(snapshot)
            with self.store.lock:
                head = self._matching_head(snapshot)
                skip_missing_source = (head is not None
                                       and head.state == 'retry_wait'
                                       and head.phase == 'source')
                if (head is None
                        or head.state not in ('uncertain', 'blocked', 'auth_required',
                                              'retry_wait')
                        or head.state == 'retry_wait' and not skip_missing_source
                        or self._earlier_blocker(head)):
                    raise ValueError('The current head is not eligible for review.')
                self._write_intent(head, 'skipped_by_user')
                self.linked_review(head, 'skipped_by_user')
                self.store.record_recovery_disposition(head, 'skipped_by_user')
            return self.snapshot()

    def resume_retained(
        self, snapshot: WatchRecoverySnapshot,
    ) -> WatchRecoverySnapshot | None:
        """Resume only safe pending work after an explicit user decision.

        Args:
            snapshot (WatchRecoverySnapshot): Exact safe pending view from a
                review-only restart or an explicit retained stop.

        Returns:
            WatchRecoverySnapshot | None: Updated queue snapshot after resume.

        Raises:
            ValueError: Context, head or stop flag changed, or the head still
                needs recovery review.
            OSError: Stop-flag publication fails.
        """
        with self._decision_context():
            self._validate(snapshot)
            with self.store.lock:
                head = self._matching_head(snapshot)
                retained_stop = self.store.data.get('retained_stop') is True
                if (head is None
                        or snapshot.retained_stop != retained_stop
                        or not self._can_resume_head(head)):
                    raise ValueError('Retained queue head still requires review.')
                self.store.resume_retained()
            return self.snapshot()

    def resume_pending_at_start(self) -> bool:
        """Resume an explicitly continued queue before it starts processing.

        Returns:
            bool: True for an empty or safe queue under its current owner.
                False leaves a head that requires review paused.
        Raises:
            ValueError: The queue or its current ownership is unavailable.
            OSError: The retained-stop update could not be verified.
        """
        with self._decision_context(), self.store.lock:
            self._validate_context()
            if self.store.force_finished:
                return False
            head = self.store.head()
            if head is not None and not self._can_resume_head(head):
                return False
            self.store.resume_retained()
            return True

    def _can_resume_head(self, head: WatchQueueItem) -> bool:
        """Identify a pending head eligible for ordinary FIFO processing.

        Args:
            head (WatchQueueItem): Current head while holding the queue lock.
        Returns:
            bool: True for safe pending work with no earlier unresolved upload.
        """
        return (head.state in (
            'observed', 'stabilizing', 'preparing', 'inventory', 'retry_wait',
        ) and (head.state, head.phase) != ('retry_wait', 'source')
            and not self._earlier_blocker(head))

    def repair_interrupted_decision(self) -> None:
        """Finish linked-history then queue publication for a durable intent.

        Returns:
            None: Clears a completed intent or safely leaves it retryable.

        Raises:
            ValueError: Intent does not identify the exact current head.
            OSError: Readback-verified queue publication cannot complete.
        """
        with self._decision_context():
            with self.store.lock:
                if self.store.force_finished:
                    return
                self._validate_context()
                intent = self.store.data.get('recovery_intent')
                if not isinstance(intent, dict):
                    return
                head = self.store.head()
                if head is None or (intent.get('item_id'), intent.get('sequence'),
                                    intent.get('signature')) != (
                        head.item_id, head.sequence, head.source.signature):
                    raise ValueError('Recovery intent does not match the current head.')
                disposition = intent.get('disposition')
                if disposition == 'approved_photo_upload':
                    self._repair_approved_photo(head, intent)
                    return
                if disposition == 'continue_after_prior_review':
                    repairable = (self._earlier_blocker(head)
                                  or head.state == 'preparing' and head.phase == 'hash'
                                  and head.reason == '')
                    if (not repairable or self.earlier_blocker_review is None
                            or self.earlier_blocker_details is None):
                        raise ValueError('Earlier linked uncertainty cannot be repaired.')
                    details = self.earlier_blocker_details(head)
                    if (details != (intent.get('blocker_marker'), intent.get('blocker_path'),
                                    intent.get('blocker_sha256'))
                            or intent.get('session_id') != self.store.data.get('session_id')
                            or intent.get('head_sha256') != head.sha256
                            or intent.get('target_binding') != self.store.binding
                            or intent.get('scope') != self.store.scope
                            or intent.get('mode') != self.expected_mode):
                        raise ValueError('Earlier blocker identity changed.')
                    self.earlier_blocker_review(head, details[0])
                    if self._earlier_blocker(head):
                        self.store.update(head, state='preparing', phase='hash', reason='')
                    self._clear_intent()
                    return
                if disposition not in ('confirmed_by_receipt', 'skipped_by_user'):
                    raise ValueError('Recovery intent is malformed.')
                if (disposition == 'confirmed_by_receipt'
                        and (head.state != 'uncertain' or self._earlier_blocker(head)
                             or not head.sha256 or not self.receipt_lookup(head))):
                    raise ValueError('Receipt evidence is no longer available.')
                self.linked_review(head, disposition)
                self.store.record_recovery_disposition(head, disposition)

    def review_earlier_blocker(
        self, snapshot: WatchRecoverySnapshot,
    ) -> WatchRecoverySnapshot | None:
        """Review an earlier linked uncertainty while preserving current head B.

        Args:
            snapshot (WatchRecoverySnapshot): Exact B head blocked before dispatch.

        Returns:
            WatchRecoverySnapshot | None: Current queue view after B is unblocked.

        Raises:
            ValueError: B is not the known pre-dispatch blocker or callback is absent.
            OSError: Durable intent or linked history publication fails.
        """
        with self._decision_context():
            self._validate(snapshot)
            with self.store.lock:
                head = self._matching_head(snapshot)
                if (head is None or not self._earlier_blocker(head)
                        or self.earlier_blocker_review is None
                        or self.earlier_blocker_details is None):
                    raise ValueError('No reviewable earlier linked blocker exists.')
                details = self.earlier_blocker_details(head)
                if details != (snapshot.prior_blocker_marker, snapshot.prior_blocker_path,
                               snapshot.prior_blocker_sha256):
                    raise ValueError('Earlier blocker changed after snapshot.')
                row = dict(self.store.data)
                row['recovery_intent'] = {
                    'item_id': head.item_id, 'sequence': head.sequence,
                    'signature': head.source.signature,
                    'disposition': 'continue_after_prior_review',
                    'session_id': str(self.store.data.get('session_id', '')),
                    'head_sha256': head.sha256,
                    'blocker_marker': details[0], 'blocker_path': details[1],
                    'blocker_sha256': details[2],
                    'target_binding': snapshot.prior_blocker_target_binding,
                    'scope': snapshot.scope, 'mode': snapshot.expected_mode,
                }
                self.store._publish(row)
                self.earlier_blocker_review(head, details[0])
                self.store.update(head, state='preparing', phase='hash', reason='')
                self._clear_intent()
            return self.snapshot()

    def _source_matches(self, head: WatchQueueItem) -> bool:
        """Revalidate retained source identity and bytes against the queue hash.

        Args:
            head (WatchQueueItem): Exact retained source generation under review.

        Returns:
            bool: True when the same regular file still has the recorded bytes.

        Raises:
            OSError: The source cannot be opened or read safely.
            ValueError: The retained generation or digest no longer matches.
            RuntimeError: Linked source verification rejected the retained bytes.
        """
        path = Path(head.source.path)
        before = observe_watch_source(path, self.store.clock())
        if before.signature != head.source.signature or before.size != head.source.size:
            return False
        snapshot = capture_linked_source(path, head.sha256, head.source.size)
        verify_linked_source(snapshot)
        after = observe_watch_source(path, self.store.clock())
        return (after.signature == head.source.signature
                and after.size == head.source.size)

    def _approval_record(
        self, head: WatchQueueItem, snapshot: WatchRecoverySnapshot,
        token: str, approved_at: float,
    ) -> dict[str, JsonValue]:
        """Create the exact durable cross-file recovery intent for approval.

        Args:
            head (WatchQueueItem): Exact uncertain FIFO head.
            snapshot (WatchRecoverySnapshot): User-reviewed context snapshot.
            token (str): Unique one-use approval token.
            approved_at (float): Captured approval time in Unix seconds.

        Returns:
            dict[str, JsonValue]: Queue intent bound to source, target and mode.
        """
        return {
            'item_id': head.item_id, 'sequence': head.sequence,
            'signature': head.source.signature,
            'disposition': 'approved_photo_upload',
            'session_id': snapshot.session_id, 'head_sha256': head.sha256,
            'source_path': head.source.path, 'source_size': head.source.size,
            'target_binding': snapshot.target_binding, 'scope': snapshot.scope,
            'mode': snapshot.expected_mode, 'approval_token': token,
            'approved_at': approved_at,
            'prior_phase': head.phase, 'prior_reason': head.reason,
            'prior_attempts': head.attempts,
            'prior_http_status': head.http_status,
            'prior_next_retry_at': head.next_retry_at,
        }

    @staticmethod
    def _approval_entry(intent: dict[str, JsonValue]) -> dict[str, JsonValue]:
        """Project a validated recovery intent into the queue approval record.

        Args:
            intent (dict[str, JsonValue]): Durable approval recovery intent.

        Returns:
            dict[str, JsonValue]: Primitive queue capability with pending outcome.
        """
        return {
            'token': intent['approval_token'], 'item_id': intent['item_id'],
            'sequence': intent['sequence'], 'signature': intent['signature'],
            'size': intent['source_size'], 'sha256': intent['head_sha256'],
            'source_path': intent['source_path'], 'session_id': intent['session_id'],
            'binding': intent['target_binding'], 'scope': intent['scope'],
            'mode': intent['mode'], 'approved_at': intent['approved_at'],
            'outcome': 'approved',
            'prior_phase': intent['prior_phase'],
            'prior_reason': intent['prior_reason'],
            'prior_attempts': intent['prior_attempts'],
            'prior_http_status': intent['prior_http_status'],
            'prior_next_retry_at': intent['prior_next_retry_at'],
        }

    def _repair_approved_photo(
        self, head: WatchQueueItem, intent: dict[str, JsonValue],
    ) -> None:
        """Finish an interrupted approval idempotently before queue consumption.

        Args:
            head (WatchQueueItem): Current unresolved queue head.
            intent (dict[str, JsonValue]): Strictly restored durable intent.

        Returns:
            None: Publishes linked review then the same approved queue generation.

        Raises:
            ValueError: Context or exact source no longer matches the intent.
            OSError: Linked or queue publication could not be confirmed.
            RuntimeError: Source or linked history verification failed.
        """
        if (head.state != 'uncertain' or self._earlier_blocker(head)
                or (intent.get('session_id'), intent.get('head_sha256'),
                    intent.get('source_path'), intent.get('source_size'),
                    intent.get('target_binding'), intent.get('scope'), intent.get('mode')) != (
                    self.store.data.get('session_id'), head.sha256, head.source.path,
                    head.source.size, self.store.binding, self.store.scope,
                    self.expected_mode)
                or not isinstance(intent.get('approval_token'), str)
                or not isinstance(intent.get('approved_at'), (float, int))
                or not self._source_matches(head)):
            raise ValueError('Approved photo intent no longer matches its source or target.')
        self.linked_review(head, 'approved_photo_upload')
        self.store.approve_photo_upload(head, self._approval_entry(intent))

    def _earlier_blocker(self, head: WatchQueueItem) -> bool:
        """Recognize only the existing exact never-dispatched B diagnostic.

        Args:
            head (WatchQueueItem): Current candidate requiring review.

        Returns:
            bool: True for recovery phase and the known earlier-upload reason.
        """
        new_marker = (head.state == 'blocked'
                      and head.phase == 'prior_upload_review'
                      and self.earlier_blocker_details is not None)
        legacy_marker = (
            head.state == 'uncertain' and head.phase == 'recovery'
            and head.reason ==
            'Earlier linked upload has an unknown result; review required.'
        )
        return (new_marker or legacy_marker) and head.attempts == 0

    def _clear_intent(self) -> None:
        """Publish a queue revision without the completed recovery intent.

        Returns:
            None: Preserves updated head and all unrelated queue evidence.
        """
        row = dict(self.store.data)
        row.pop('recovery_intent', None)
        self.store._publish(row)

    def _matching_head(self, snapshot: WatchRecoverySnapshot) -> WatchQueueItem | None:
        """Require displayed generation, content hash, state and session to match.

        Args:
            snapshot (WatchRecoverySnapshot): Immutable user-reviewed view.

        Returns:
            WatchQueueItem | None: Exact current head, or None if it changed.
        """
        head = self.store.head()
        if (head is None or (head.item_id, head.sequence, head.source.path,
                             head.source.signature, head.source.size,
                             head.source.modified_ns, head.sha256, head.state) != (
                snapshot.item_id, snapshot.sequence, snapshot.source_path,
                snapshot.source_signature, snapshot.source_size,
                snapshot.source_mtime_ns, snapshot.sha256, snapshot.state)):
            return None
        return head

    def _write_intent(self, head: WatchQueueItem, disposition: str) -> None:
        """Publish a readback-verified intent before linked history mutation.

        Args:
            head (WatchQueueItem): Exact current queue head.
            disposition (str): Explicit decision being repaired idempotently.

        Returns:
            None: Durable intent is the recovery point for cross-file crashes.

        Raises:
            OSError: Queue intent publication fails.
            ValueError: Another unresolved intent already owns the queue.
        """
        existing = self.store.data.get('recovery_intent')
        expected = {
            'item_id': head.item_id, 'sequence': head.sequence,
            'signature': head.source.signature, 'disposition': disposition,
        }
        if existing == expected:
            return
        if existing is not None:
            raise ValueError('Another recovery decision needs repair first.')
        row = dict(self.store.data)
        row['recovery_intent'] = expected
        self.store._publish(row)

    def _validate(self, snapshot: WatchRecoverySnapshot) -> None:
        """Reject stale views, wrong targets, missing leases and live requests.

        Args:
            snapshot (WatchRecoverySnapshot): View the caller intends to decide.

        Returns:
            None: Returns only when current context still matches the view.

        Raises:
            ValueError: Any expected recovery precondition is absent.
        """
        with self.store.lock:
            if self.store.force_finished:
                raise ValueError('Force-finished session cannot accept recovery decisions.')
            self._validate_context()
            current_target = (self.target_binding() if callable(self.target_binding)
                              else self.target_binding)
            if snapshot.target_binding != current_target:
                raise ValueError('Recovery snapshot target is stale.')
            head = self._matching_head(snapshot)
            if (head is None or snapshot.session_id != str(
                    self.store.data.get('session_id', ''))
                    or snapshot.expected_mode != self.expected_mode
                    or snapshot.scope != self.store.scope
                    or snapshot.prior_blocker_target_binding != current_target):
                raise ValueError('Recovery snapshot is stale.')

    def _decision_context(self) -> ContextManager[object]:
        """Return the shared worker gate or a harmless standalone context.

        Returns:
            ContextManager[object]: Gate used by queue processing and decisions.
        """
        return self.decision_gate if self.decision_gate is not None else nullcontext()

    def _validate_context(self) -> None:
        """Verify current target, mode, both required leases and idle transport.

        Returns:
            None: Returns only when the current worker owns every required fence.

        Raises:
            ValueError: Target/mode/lease is stale or a request is still active.
        """
        current_target = self.target_binding() if callable(self.target_binding) else self.target_binding
        mode = self._current_mode()
        linked_required = mode == 'linked'
        if (mode != self.expected_mode
                or self.store.binding != current_target
                or mode not in ('linked', 'legacy')
                or not self._lease_valid(self.pipeline_lease)
                or linked_required and (self.linked_lease is None
                                        or not self._lease_valid(self.linked_lease))
                or self.linked_lease is not None and mode == 'legacy'
                and not self._lease_valid(self.linked_lease)
                or self.transport_active()):
            raise ValueError('Recovery context is not exclusively available.')

    def _current_mode(self) -> str:
        """Resolve current mode evidence instead of trusting a stale constructor value.

        Returns:
            str: Current linked or legacy mode identifier.
        """
        return self.mode() if callable(self.mode) else self.mode

    @staticmethod
    def _lease_valid(lease: LinkedTargetLease) -> bool:
        """Verify that an existing worker-owned native lease remains held.

        Args:
            lease (LinkedTargetLease): Existing target or shared pipeline lease.

        Returns:
            bool: True only when its native owner verifies successfully.
        """
        native = lease._native
        if native is None:
            return False
        try:
            native.verify()
        except (OSError, ValueError, RuntimeError):
            return False
        return True
