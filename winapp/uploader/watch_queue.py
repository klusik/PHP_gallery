# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watch_queue.py
# Module Type: Windows Service
# Purpose: Retain capture admission and immutable FIFO order independently of HTTP.
# Responsibilities:
#   - Journal observed source generations before hashing or network work.
#   - Preserve target binding, explicit Finish intent and retry/unknown outcomes.
#   - Extend state_store atomic publication without importing desktop UI owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Destination-bound capture queue shared by discovery and its single consumer."""

import copy
import hashlib
import json
import math
import os
import re
import stat
import threading
import time
import uuid
from dataclasses import asdict, dataclass, replace
from datetime import datetime
from pathlib import Path
from typing import Callable

from .models import JsonValue
from .state_store import atomic_write_json, accepted_work_owner

# Purpose: Bound complete queue reads, including retained acknowledgements.
# Type: int. Units: bytes. Scope: one session journal.
# Consumers: WatchQueueStore._read and _publish.
# Rationale: 32 MiB covers long capture sessions while refusing unbounded JSON.
WATCH_QUEUE_MAX_BYTES = 32 * 1024 * 1024

# Purpose: Bound admission without ever dropping already accepted photographs.
# Type: int. Units: source generations. Scope: one capture session.
# Consumers: WatchQueueStore.reconcile.
# Rationale: 20000 covers extended captures; exhaustion blocks visibly on disk.
WATCH_QUEUE_MAX_ITEMS = 20000

# Purpose: Reconcile missed filesystem notifications throughout network waits.
# Type: float. Units: seconds. Scope: each active local producer.
# Consumers: WatcherThread._produce_photographs.
# Rationale: One second limits normal capture observation delay without hashing.
WATCH_QUEUE_RECONCILE_SECONDS = 1.0

# Purpose: Require an unchanged observation interval even with a zero UI preference.
# Type: float. Units: seconds. Scope: source stability and final reconciliation.
# Consumers: FileStabilityTracker, WatcherThread._consume_head and _run_owned.
# Rationale: Half a second preserves the existing minimum against partial writers.
WATCH_QUEUE_STABILITY_MIN_SECONDS = 0.5

# Purpose: Pace retryable pre-dispatch and inventory failures without storms.
# Type: tuple[float,float]. Units: seconds. Scope: one queue head's retry delay.
# Consumers: WatchQueueStore.defer.
# Rationale: Five seconds initially and 300 seconds maximum retain intent
#   indefinitely while keeping unavailable installations quiet.
WATCH_QUEUE_RETRY_SECONDS = (5.0, 300.0)

# Purpose: Bound exponent growth before applying the retry-delay ceiling.
# Type: int. Units: doublings. Scope: one unresolved queue head.
# Consumers: WatchQueueStore.defer.
# Rationale: Six doublings already exceed the five-minute delay ceiling.
WATCH_QUEUE_RETRY_MAX_DOUBLINGS = 6

WATCH_QUEUE_STATES = frozenset({
    'observed', 'stabilizing', 'preparing', 'inventory', 'dispatching',
    'retry_wait', 'auth_required', 'uncertain', 'blocked', 'acknowledged', 'skipped',
})


@dataclass(frozen=True)
class WatchSource:
    """Retain one observation's path, identity, chronological evidence and size."""

    path: str
    signature: str
    size: int
    captured_at: float
    born_at: float
    first_seen: float
    clock: str
    identity: str = ''
    existence_at: float = 0.0
    modified_ns: int = 0


def observe_watch_source(path: Path, first_seen: float) -> WatchSource:
    """Read cheap source identity without hashing a possibly unfinished image.

    Steam's embedded local capture timestamp precedes the game-independent
    tie breaker. Otherwise native birth time is used when available, followed
    by first observation. Modification time detects changes, never FIFO order.

    Args:
        path (Path): Supported source beneath the already selected folder.
        first_seen (float): Local discovery's Unix timestamp in seconds.

    Returns:
        WatchSource: Identity digest, byte size and explicit clock evidence.

    Raises:
        OSError: The source is missing, redirected or not a regular file.
    """
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or path.is_symlink():
        raise OSError('Screenshot source is not an ordinary local file.')
    # OneDrive cloud files can be regular reparse entries. Admit their metadata
    # without hydrating them; the existing same-handle upload owner verifies
    # exact file identity/bytes before dispatch and refuses unsafe deletion.
    native_birth = getattr(info, 'st_birthtime', None)
    if native_birth is None and os.name == 'nt':
        native_birth = info.st_ctime
    born = float(native_birth) if native_birth is not None else first_seen
    captured, clock = born, 'birth' if born != first_seen else 'first_seen'
    match = re.fullmatch(r'\d+_(\d{14})_\d+\.[A-Za-z0-9]+', path.name)
    if match is not None:
        try:
            captured = datetime.strptime(match[1], '%Y%m%d%H%M%S').timestamp()
            clock = 'steam_filename_local'
        except (ValueError, OverflowError, OSError):
            pass  # Unknown filename clocks retain the explicit native fallback.
    identity = [
        info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns,
        # First observation is a chronological fallback, never file identity.
        # A platform without birth time must produce the same signature on
        # every unchanged scan, including after a process restart.
        getattr(info, 'st_birthtime_ns',
                int(native_birth * 1_000_000_000) if native_birth is not None else 0),
    ]
    signature = hashlib.sha256(repr(identity).encode('ascii')).hexdigest()
    generation = hashlib.sha256(repr((
        info.st_dev, info.st_ino, native_birth,
    )).encode('ascii')).hexdigest()
    return WatchSource(
        str(path), signature, info.st_size, captured, born, first_seen, clock,
        generation, float(info.st_ctime), info.st_mtime_ns,
    )


@dataclass(frozen=True)
class WatchQueueItem:
    """Describe an immutable session position and its durable processing state."""

    item_id: str
    sequence: int
    source: WatchSource
    state: str = 'observed'
    sha256: str = ''
    attempts: int = 0
    next_retry_at: float = 0.0
    reason: str = ''
    phase: str = 'discovery'
    http_status: int | None = None
    inventory_ready: bool = False
    disposition: str = ''
    last_source_change: float = 0.0
    disposition_at: float = 0.0


def _queue_object(pairs: list[tuple[str, JsonValue]]) -> dict[str, JsonValue]:
    """Reject duplicate object keys instead of replacing earlier queue evidence.

    Args:
        pairs (list[tuple[str, JsonValue]]): Ordered decoded JSON key/value pairs.

    Returns:
        dict[str, JsonValue]: Object with each original key represented once.

    Raises:
        ValueError: A key occurs more than once.
    """
    result: dict[str, JsonValue] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate capture journal field.')
        result[key] = value
    return result


class WatchQueueStore:
    """Own readback-verified queue transitions under one producer/consumer lock."""

    def __init__(
        self, path: Path, binding: str, folder: Path, recursive: bool,
        *, clock: Callable[[], float] = time.time,
        allowed_paths: tuple[str, ...] | None = None,
        scope: str = '',
    ) -> None:
        """Select an existing session journal without admitting or sending files.

        Args:
            path (Path): Session-owned watch-drain.json publication path.
            binding (str): Verified issuer/account/gallery or legacy connection digest.
            folder (Path): Original absolute capture folder.
            recursive (bool): Original subfolder admission preference.
            clock (Callable[[], float]): Unix-seconds clock, injectable for fixtures.
            allowed_paths (tuple[str, ...] | None): Exact accepted import sources;
                None retains capture-folder containment instead of explicit selection.
            scope (str): Exact approved linked scope, or empty for legacy keys.

        Returns:
            None: Creates an uninitialized journal owner and its shared lock.
        """
        self.path = path
        self.binding = binding
        self.folder = folder
        self.recursive = recursive
        self.clock = clock
        self.allowed_paths = allowed_paths
        self.scope = scope
        self.lock = threading.RLock()
        self.data: dict[str, JsonValue] = {}
        self._disk_baseline: dict[str, JsonValue] | None = None
        self.last_scan_at = 0.0
        self.scan_generation = 0

    def _read(self) -> dict[str, JsonValue]:
        """Read a bounded nonredirected complete journal without repairing it.

        Returns:
            dict[str, JsonValue]: Strict string-keyed persisted queue document.

        Raises:
            OSError: The journal cannot be read or uses an unsafe path.
            ValueError: The document is oversized, malformed or not an object.
        """
        if self.path.is_symlink() or self.path.parent.is_symlink():
            raise OSError('Capture journal is redirected.')
        with self.path.open('rb') as handle:
            raw = handle.read(WATCH_QUEUE_MAX_BYTES + 1)
        if len(raw) > WATCH_QUEUE_MAX_BYTES:
            raise ValueError('Capture journal exceeds its safe size limit.')
        row = json.loads(raw, object_pairs_hook=_queue_object)
        if not isinstance(row, dict):
            raise ValueError('Capture journal is not an object.')
        return row

    def _publish(self, row: dict[str, JsonValue]) -> None:
        """Publish and verify a complete transition before changing live state.

        Args:
            row (dict[str, JsonValue]): Queue document with binding, ordered item
                records, admission exclusions, retry state and Finish metadata.

        Returns:
            None: Advances memory only after required fsync and exact readback.

        Raises:
            OSError: Durable publication or readback fails.
            ValueError: The document is oversized or publication is unconfirmed.
        """
        if len(json.dumps(row).encode('utf-8')) > WATCH_QUEUE_MAX_BYTES:
            raise ValueError('Capture journal is full; source photographs retained.')
        with accepted_work_owner(self.path.parent):
            current = self._read() if self.path.exists() else None
            if current != self._disk_baseline:
                raise ValueError('Capture journal changed under another owner.')
            atomic_write_json(self.path, row, require_sync=True)
            if self._read() != row:
                raise ValueError('Capture journal publication could not be confirmed.')
            self.data = row
            self._disk_baseline = copy.deepcopy(row)

    def _new_document(self, initial: list[WatchSource] | None) -> dict[str, JsonValue]:
        """Build new session data without replacing an existing recovery file.

        Args:
            initial (list[WatchSource] | None): Exact startup observations, or
                None while the background initial scan has not completed.

        Returns:
            dict[str, JsonValue]: Target-bound empty FIFO and protected exclusions.
        """
        with self.lock:
            now = self.clock()
            return {
                'schema_version': 2, 'binding': self.binding,
                'scope': self.scope,
                'session_id': self.path.parent.name,
                'folder': str(self.folder), 'recursive': self.recursive,
                'allowed_paths': list(self.allowed_paths) if self.allowed_paths is not None else None,
                'initial': [source.path for source in initial or ()],
                'initial_sources': [asdict(source) for source in initial or ()],
                'initial_hashes': {},
                'initial_captured': initial is not None,
                'pending': [], 'items': [], 'next_sequence': 1,
                'frozen': initial is None, 'cutoff': 0.0, 'checkpoint': now,
                'capture_started_at': now, 'last_change_at': now,
                'last_change_scan_generation': 0,
                'last_scan_at': 0.0, 'scan_generation': 0,
                'error': '', 'last_failure': '', 'completed': False,
                'order_verified': True, 'retained_stop': False,
            }

    def initialize(self, initial: list[WatchSource] | None) -> None:
        """Persist a new session boundary before displaying capture as active.

        Args:
            initial (list[WatchSource] | None): Exact startup observations, or
                None while the background initial scan has not completed.

        Returns:
            None: Publishes a target-bound empty FIFO with protected exclusions.
        """
        with self.lock:
            self._publish(self._new_document(initial))

    def restore(self) -> None:
        """Verify target, order and source containment before resuming a journal.

        Version-one queues retain their original boundary. Multiple pending
        paths have no recorded arrival order and therefore block for review.
        A zero cutoff stays open; checkpoint time is never invented as Finish.

        Returns:
            None: Loads verified v2 records or conservatively upgrades v1 data.

        Raises:
            ValueError: Binding, item identity, order or boundary is malformed.
            OSError: The original journal is unavailable.
        """
        with self.lock:
            row = self._read()
            self._disk_baseline = copy.deepcopy(row)
            version = row.get('schema_version', 1)
            if type(version) is not int or version not in (1, 2):
                raise ValueError('Capture queue version is malformed.')
            stored_folder = row.get('folder')
            captured_folder = str(self.folder)
            # Windows accepts either separator spelling for the same lexical
            # boundary; do not normalize case, dot components or aliases.
            if os.name == 'nt' and isinstance(stored_folder, str):
                stored_folder = stored_folder.replace('/', '\\')
                captured_folder = captured_folder.replace('/', '\\')
            if (not isinstance(stored_folder, str)
                    or stored_folder != captured_folder
                    or row.get('recursive') is not self.recursive
                    or row.get('initial_captured', True) is not True):
                raise ValueError('Original capture boundary could not be verified.')
            if row.get('allowed_paths') != (
                    list(self.allowed_paths) if self.allowed_paths is not None else None):
                raise ValueError('Original explicit import selection changed.')
            if version == 1:
                pending = row.get('pending')
                initial = row.get('initial')
                if (not isinstance(pending, list) or not isinstance(initial, list)
                        or any(not isinstance(value, str) for value in pending + initial)):
                    raise ValueError('Legacy capture paths are malformed.')
                previous = copy.deepcopy(row)
                if previous.get('binding', self.binding) != self.binding:
                    raise ValueError('Legacy capture belongs to another target.')
                if previous.get('frozen') is True and not previous.get('cutoff'):
                    raise ValueError('Legacy frozen capture has no verified Finish boundary.')
                row = self._new_document([])
                row.update({
                    'initial': initial, 'initial_sources': [],
                    'frozen': bool(previous.get('cutoff')),
                    'cutoff': previous.get('cutoff', 0.0),
                    'order_verified': len(pending) <= 1,
                    'error': ('Legacy queue has no provable FIFO order; review required.'
                              if len(pending) > 1 else ''),
                })
                for value in initial:
                    try:
                        source = observe_watch_source(Path(value), self.clock())
                    except OSError:
                        source = WatchSource(value, '', 0, 0.0, 0.0,
                                             self.clock(), 'legacy_unknown')
                    row['initial_sources'].append(asdict(source))
                for sequence, value in enumerate(pending, start=1):
                    try:
                        source = observe_watch_source(Path(value), self.clock())
                    except OSError:
                        source = WatchSource(value, '', 0, self.clock(), 0.0,
                                             self.clock(), 'legacy_unknown')
                    row['items'].append(asdict(WatchQueueItem(
                        uuid.uuid4().hex, sequence, source,
                    )))
                row['next_sequence'] = len(pending) + 1
                row['pending'] = pending
            elif (row.get('schema_version') != 2 or row.get('binding') != self.binding
                  or row.get('scope', '') != self.scope
                  or row.get('session_id') != self.path.parent.name):
                raise ValueError('Capture belongs to another target or queue version.')
            row.setdefault('retained_stop', False)
            items = row.get('items')
            if not isinstance(items, list) or len(items) > WATCH_QUEUE_MAX_ITEMS:
                raise ValueError('Capture item collection is malformed.')
            identities: set[str] = set()
            previous_sequence = 0
            for raw in items:
                item = self._item(raw)
                if ((item.state == 'skipped') != (item.disposition == 'skipped_by_user')
                        or item.disposition == 'confirmed_by_receipt'
                        and item.state != 'acknowledged'):
                    raise ValueError('Capture disposition and terminal state disagree.')
                if (item.item_id in identities or item.sequence != previous_sequence + 1
                        or not self._allows_source(item.source.path)):
                    raise ValueError('Capture order or source containment is invalid.')
                identities.add(item.item_id)
                previous_sequence = item.sequence
                if item.state == 'dispatching':
                    raw['state'] = 'uncertain'
                    raw['reason'] = 'Interrupted upload has no durable acknowledgement.'
            cutoff = row.get('cutoff')
            if (type(cutoff) not in (float, int) or not math.isfinite(cutoff)
                    or cutoff < 0 or type(row.get('next_sequence')) is not int
                    or row['next_sequence'] != previous_sequence + 1
                    or not isinstance(row.get('initial'), list)
                    or any(not isinstance(value, str) or not self._allows_source(value)
                           for value in row['initial'])
                    or len(set(row['initial'])) != len(row['initial'])
                    or not isinstance(row.get('initial_sources'), list)
                    or row.get('pending') != [raw['source']['path'] for raw in items
                                              if raw['state'] not in ('acknowledged', 'skipped')]
                    or type(row.get('frozen')) is not bool
                    or type(row.get('completed')) is not bool
                    or type(row.get('order_verified')) is not bool
                    or type(row.get('retained_stop')) is not bool
                    or not isinstance(row.get('error'), str)
                    or not isinstance(row.get('last_failure'), str)
                    or not isinstance(row.get('initial_hashes'), dict)
                    or any(not isinstance(key, str) or not isinstance(value, str)
                           or re.fullmatch(r'[a-f0-9]{64}', value) is None
                           for key, value in row['initial_hashes'].items())):
                raise ValueError('Capture sequence or Finish boundary is invalid.')
            initial_sources = [self._source(value) for value in row['initial_sources']]
            if [source.path for source in initial_sources] != row['initial']:
                raise ValueError('Original capture exclusions do not match their sources.')
            intent = row.get('recovery_intent')
            if intent is not None:
                common_intent_keys = {
                    'item_id', 'sequence', 'signature', 'disposition',
                }
                blocker_intent_keys = common_intent_keys | {
                    'session_id', 'head_sha256', 'blocker_marker',
                    'blocker_path', 'blocker_sha256', 'target_binding',
                    'scope', 'mode',
                }
                if (not isinstance(intent, dict)
                        or set(intent) not in (common_intent_keys, blocker_intent_keys)
                        or intent.get('disposition') not in (
                            'confirmed_by_receipt', 'skipped_by_user',
                            'continue_after_prior_review')
                        or type(intent.get('sequence')) is not int
                        or not isinstance(intent.get('item_id'), str)
                        or not isinstance(intent.get('signature'), str)):
                    raise ValueError('Recovery intent is malformed.')
                if (intent.get('disposition') == 'continue_after_prior_review'
                        and (set(intent) != blocker_intent_keys
                             or not all(isinstance(intent.get(key), str) and intent[key]
                                        for key in ('session_id', 'blocker_marker',
                                                    'blocker_path', 'blocker_sha256',
                                                    'target_binding', 'mode'))
                             or not isinstance(intent.get('scope'), str)
                             or not isinstance(intent.get('head_sha256'), str))):
                    raise ValueError('Prior-review intent is malformed.')
                current_head = next((raw for raw in items
                                     if raw['state'] not in ('acknowledged', 'skipped')), None)
                if (current_head is None or (intent['item_id'], intent['sequence'],
                                             intent['signature']) != (
                        current_head['item_id'], current_head['sequence'],
                        current_head['source']['signature'])):
                    raise ValueError('Recovery intent does not identify the current head.')
            if (any(key not in row['initial'] for key in row['initial_hashes'])
                    or row['frozen'] != bool(cutoff)
                    or any(type(row.get(key)) not in (int, float)
                           or not math.isfinite(row[key]) or row[key] < 0
                           for key in ('capture_started_at', 'checkpoint',
                                       'last_change_at', 'last_scan_at'))
                    or any(type(row.get(key)) is not int or row[key] < 0
                           for key in ('scan_generation', 'last_change_scan_generation'))):
                raise ValueError('Capture boundary or reconciliation evidence is malformed.')
            if (row['completed'] and (row['pending'] or not cutoff or not row['frozen']
                                      or row['retained_stop']
                                      or row['order_verified'] is not True)):
                raise ValueError('Completed capture lacks its final queue barrier.')
            self._publish(row)
            self.scan_generation = int(row.get('scan_generation', 0))

    def _allows_source(self, value: str) -> bool:
        """Verify lexical containment without following mutable filesystem redirects.

        Args:
            value (str): Absolute journal or discovery source path.

        Returns:
            bool: True for the exact accepted selection or original folder depth.
        """
        path = Path(value)
        if not path.is_absolute() or '..' in path.parts:
            return False
        if self.allowed_paths is not None:
            return value in self.allowed_paths
        return (path.is_relative_to(self.folder) and path != self.folder
                and (self.recursive or path.parent == self.folder))

    def _source(self, raw: JsonValue) -> WatchSource:
        """Validate source identity and clocks without opening or hydrating the path.

        Args:
            raw (JsonValue): Source object with path, signature, identity, size
                and capture/birth/first-observed/existence clock evidence.

        Returns:
            WatchSource: Typed ordinary source descriptor with finite timestamps.

        Raises:
            ValueError: A source field is missing, malformed or outside its contract.
        """
        try:
            if not isinstance(raw, dict):
                raise ValueError('Capture source is not an object.')
            source_row = dict(raw)
            source_row.setdefault('modified_ns', 0)
            source = WatchSource(**source_row)
            if (not isinstance(source.path, str) or not Path(source.path).is_absolute()
                    or '..' in Path(source.path).parts
                    or type(source.size) is not int or source.size < 0
                    or type(source.modified_ns) is not int or source.modified_ns < 0
                    or source.clock not in ('birth', 'first_seen', 'steam_filename_local',
                                            'legacy_unknown', 'accepted_import_order')
                    or any(not isinstance(value, str) or value
                           and re.fullmatch(r'[a-f0-9]{64}', value) is None
                           for value in (source.signature, source.identity))
                    or any(type(value) not in (int, float) or not math.isfinite(value)
                           for value in (source.captured_at, source.born_at,
                                         source.first_seen, source.existence_at))):
                raise ValueError('Capture source identity or clocks are invalid.')
            return source
        except TypeError as error:
            raise ValueError('Capture source fields are malformed.') from error

    def _item(self, raw: JsonValue) -> WatchQueueItem:
        """Validate and decode one stored source generation without opening it.

        Args:
            raw (JsonValue): Item object with ID, sequence, source, state and retry fields.

        Returns:
            WatchQueueItem: Typed record with a concrete source observation.

        Raises:
            ValueError: Item fields are malformed or outside the state contract.
        """
        try:
            if not isinstance(raw, dict) or not isinstance(raw.get('source'), dict):
                raise ValueError('Capture source record is malformed.')
            item = WatchQueueItem(**{**raw, 'source': self._source(raw['source'])})
            if (re.fullmatch(r'[a-f0-9]{32}', item.item_id) is None
                    or type(item.sequence) is not int or item.sequence < 1
                    or item.state not in WATCH_QUEUE_STATES
                    or not isinstance(item.source.path, str)
                    or not isinstance(item.source.signature, str)
                    or type(item.source.size) is not int or item.source.size < 0
                    or not isinstance(item.sha256, str)
                    or (item.sha256 and re.fullmatch(r'[a-f0-9]{64}', item.sha256) is None)
                    or type(item.attempts) is not int or item.attempts < 0
                    or type(item.next_retry_at) not in (int, float)
                    or not math.isfinite(item.next_retry_at)
                    or item.next_retry_at < 0
                    or type(item.last_source_change) not in (int, float)
                    or not math.isfinite(item.last_source_change)
                    or item.last_source_change < 0
                    or type(item.disposition_at) not in (int, float)
                    or not math.isfinite(item.disposition_at)
                    or item.disposition_at < 0
                    or type(item.inventory_ready) is not bool
            or item.disposition not in ('', 'uploaded', 'duplicate',
                                        'confirmed_by_receipt', 'skipped_by_user')
                    or not isinstance(item.reason, str) or not isinstance(item.phase, str)
                    or (item.http_status is not None and (
                        type(item.http_status) is not int or not 100 <= item.http_status <= 599))
                    or any(type(value) not in (int, float) or not math.isfinite(value)
                           for value in (item.source.captured_at, item.source.born_at,
                                         item.source.first_seen))):
                raise ValueError('Capture item state is invalid.')
            return item
        except (TypeError, KeyError) as error:
            raise ValueError('Capture item record is malformed.') from error

    def items(self) -> list[WatchQueueItem]:
        """Return ordered immutable records for safe producer/consumer snapshots.

        Returns:
            list[WatchQueueItem]: All accepted source generations in sequence order.
        """
        with self.lock:
            return [self._item(raw) for raw in self.data.get('items', [])]

    def capture_initial(
        self, initial: list[WatchSource], hashes: dict[str, str] | None = None,
    ) -> None:
        """Complete startup exclusions while preserving a concurrent Finish intent.

        Args:
            initial (list[WatchSource]): Complete background startup scan observations.
            hashes (dict[str, str] | None): Stable original path/SHA-256 exclusion
                evidence, or None when no historical bytes could be verified.

        Returns:
            None: Activates only this original boundary without resetting queue data.
        """
        with self.lock:
            if self.data.get('initial_captured') is True:
                return
            row = copy.deepcopy(self.data)
            row.update(
                initial=[source.path for source in initial],
                initial_sources=[asdict(source) for source in initial],
                initial_hashes=hashes or {},
                initial_captured=True, frozen=bool(row['cutoff']),
                capture_started_at=self.clock(),
            )
            self._publish(row)

    def record_error(self, reason: str) -> None:
        """Retain a producer/persistence diagnosis without claiming new acceptance.

        Args:
            reason (str): Safe fixed error, or empty after a successful local scan.

        Returns:
            None: Updates current error while retaining the last historical failure.
        """
        with self.lock:
            if self.data.get('error') == reason:
                return
            row = copy.deepcopy(self.data)
            row['error'] = reason
            if reason:
                row['last_failure'] = f'{self.clock():.3f} phase=discovery {reason}'
            self._publish(row)

    def remember_initial_hash(self, source: WatchSource, digest: str) -> None:
        """Retain historical byte evidence without creating an accepted queue item.

        Args:
            source (WatchSource): Unchanged original startup exclusion observation.
            digest (str): SHA-256 read from that exact unchanged generation.

        Returns:
            None: Records only a still-matching original source fingerprint.
        """
        with self.lock:
            matching = any(value['path'] == source.path
                           and value['signature'] == source.signature
                           for value in self.data.get('initial_sources', []))
            if not matching or self.data.get('completed') is True:
                return
            row = copy.deepcopy(self.data)
            row['initial_hashes'][source.path] = digest
            self._publish(row)

    def head(self) -> WatchQueueItem | None:
        """Select the oldest unresolved generation without admitting later work.

        Returns:
            WatchQueueItem | None: First unresolved item, or None for an empty FIFO.
        """
        return next((item for item in self.items()
                     if item.state not in ('acknowledged', 'skipped')),
                    None)

    def record_recovery_disposition(
        self, item: WatchQueueItem, disposition: str,
    ) -> WatchQueueItem:
        """Record an explicit head-only receipt confirmation or user skip.

        Args:
            item (WatchQueueItem): Exact immutable head snapshot being reviewed.
            disposition (str): ``confirmed_by_receipt`` or ``skipped_by_user``.

        Returns:
            WatchQueueItem: Readback-verified terminal review record.

        Raises:
            ValueError: The head changed or disposition is unsupported.
            OSError: Durable publication could not be confirmed.
        """
        if disposition not in ('confirmed_by_receipt', 'skipped_by_user'):
            raise ValueError('Unsupported recovery disposition.')
        with self.lock:
            current = self.head()
            if current is None or (current.item_id, current.sequence,
                                   current.source.signature) != (
                    item.item_id, item.sequence, item.source.signature):
                raise ValueError('Recovery snapshot is stale.')
            return self.update(
                current, state='acknowledged' if disposition == 'confirmed_by_receipt'
                else 'skipped', disposition=disposition,
                reason='Confirmed from existing durable receipt.'
                if disposition == 'confirmed_by_receipt' else 'Skipped by user.',
                phase='manual_recovery', next_retry_at=0.0,
            )

    def reconcile(self, observations: list[WatchSource]) -> int:
        """Durably append a local discovery batch without renumbering older items.

        Args:
            observations (list[WatchSource]): Complete successful local scan;
                each source carries identity and an explicit chronological clock.

        Returns:
            int: Newly appended generations; changes to unfinished writers retain position.

        Raises:
            OSError: Publication fails; no new acceptance is reported.
            ValueError: The journal is full or source admission cannot be verified.
        """
        with self.lock:
            row = copy.deepcopy(self.data)
            if row.get('recovery_intent') is not None:
                raise ValueError('An interrupted recovery decision must be repaired first.')
            if row.get('completed') is True:
                return 0
            if row.get('retained_stop') is True:
                return 0
            cutoff = float(row['cutoff'])
            initial = {value['path']: value['signature']
                       for value in row.get('initial_sources', [])}
            legacy_initial = set(row['initial']) - set(initial)
            latest = {raw['source']['path']: raw for raw in row['items']}
            added, changed = 0, False
            accepted_order = ({path: index for index, path in enumerate(self.allowed_paths)}
                              if self.allowed_paths is not None else {})
            for source in sorted(observations, key=lambda value: (
                    accepted_order.get(value.path, value.captured_at),
                    os.path.normcase(value.path), value.signature)):
                if not self._allows_source(source.path):
                    raise ValueError('Observed source escaped its accepted selection.')
                if (source.path in legacy_initial
                        or initial.get(source.path) == source.signature):
                    continue
                old = latest.get(source.path)
                if old is not None and old['source']['signature'] == source.signature:
                    continue
                if cutoff and source.born_at > cutoff:
                    # A cloud restore can replace birth time. A recognized
                    # capture clock within this exact interval is positive
                    # eligibility evidence; arbitrary old paths are not.
                    accepted_writer = (old is not None
                                       and old['state'] not in ('acknowledged', 'uncertain')
                                       and bool(source.identity)
                                       and old['source'].get('identity') == source.identity)
                    # POSIX ctime is not birth time or an ordering clock. An
                    # unchanged pre-cutoff metadata-change time can only prove
                    # that the file already existed before the cutoff.
                    existed_before = (source.born_at == source.first_seen
                                      and 0 < source.existence_at <= cutoff)
                    if not (accepted_writer or existed_before
                            or source.clock == 'steam_filename_local'
                            and float(row['capture_started_at']) <= source.captured_at <= cutoff):
                        continue
                if (old is not None and old['state'] != 'skipped' and old['state'] not in (
                        'acknowledged', 'dispatching', 'uncertain')):
                    old['source'] = asdict(replace(
                        source, first_seen=old['source']['first_seen'],
                    ))
                    old['sha256'] = ''
                    old['last_source_change'] = self.clock()
                    old['inventory_ready'] = False
                    if old['state'] not in ('blocked', 'auth_required', 'retry_wait'):
                        old['state'] = 'observed'
                    changed = True
                    continue
                if len(row['items']) >= WATCH_QUEUE_MAX_ITEMS:
                    raise ValueError('Capture queue is full; source photographs retained.')
                raw = asdict(WatchQueueItem(
                    uuid.uuid4().hex, row['next_sequence'], source,
                ))
                row['items'].append(raw)
                latest[source.path] = raw
                row['next_sequence'] += 1
                added += 1
            now = self.clock()
            self.last_scan_at = now
            self.scan_generation += 1
            if not added and not changed:
                # Heartbeat observations need no full-file fsync every second.
                # Admission, transitions and the final barrier remain durable.
                return 0
            row['pending'] = [raw['source']['path'] for raw in row['items']
                              if raw['state'] not in ('acknowledged', 'skipped')]
            row.pop('recovery_intent', None)
            row['last_scan_at'] = now
            row['scan_generation'] = self.scan_generation
            row['checkpoint'] = now
            if added or changed:
                row['last_change_at'] = now
                row['last_change_scan_generation'] = self.scan_generation
            self._publish(row)
            return added

    def update(self, item: WatchQueueItem, **changes: JsonValue) -> WatchQueueItem:
        """Persist one head-owned transition while preserving immutable ordering.

        Args:
            item (WatchQueueItem): Previously selected head identity.
            **changes (JsonValue): State, sha256, retry, phase, status or inventory fields.

        Returns:
            WatchQueueItem: Published current record for this same sequence.

        Raises:
            ValueError: The item is not the current head or immutable fields change.
            OSError: Durable publication fails.
        """
        allowed = {'state', 'sha256', 'attempts', 'next_retry_at', 'reason',
                   'phase', 'http_status', 'inventory_ready', 'disposition'}
        with self.lock:
            current = self.head()
            if (current is None or current.item_id != item.item_id
                    or current.source.signature != item.source.signature
                    or set(changes) - allowed):
                raise ValueError('Only the current FIFO head may advance.')
            updated = replace(current, **changes)
            if (updated.state == 'acknowledged'
                    or updated.disposition in ('confirmed_by_receipt', 'skipped_by_user')):
                updated = replace(updated, disposition_at=self.clock())
            self._item(asdict(updated))
            row = copy.deepcopy(self.data)
            row['items'] = [asdict(updated) if raw['item_id'] == item.item_id else raw
                            for raw in row['items']]
            if updated.disposition in ('confirmed_by_receipt', 'skipped_by_user'):
                row.pop('recovery_intent', None)
            row['pending'] = [raw['source']['path'] for raw in row['items']
                              if raw['state'] not in ('acknowledged', 'skipped')]
            row['checkpoint'] = row['last_change_at'] = self.clock()
            row['last_change_scan_generation'] = self.scan_generation
            row['scan_generation'] = self.scan_generation
            if updated.reason:
                row['last_failure'] = (
                    f'{self.clock():.3f} item={updated.item_id} seq={updated.sequence} '
                    f'phase={updated.phase} http={updated.http_status} {updated.reason}'
                )
            self._publish(row)
            return updated

    def defer(
        self, item: WatchQueueItem, reason: str, phase: str,
        status: int | None = None, retry_after: float | None = None,
    ) -> WatchQueueItem:
        """Schedule another safe head attempt without limiting completion intent.

        Args:
            item (WatchQueueItem): Current unresolved FIFO head.
            reason (str): Fixed safe explanation without credentials or response text.
            phase (str): Local processing phase that could not complete.
            status (int | None): Observed HTTP status, if available.
            retry_after (float | None): Validated server delay in seconds, if available.

        Returns:
            WatchQueueItem: Persisted waiting head with its absolute retry deadline.
        """
        attempts = item.attempts + 1
        delay = min(WATCH_QUEUE_RETRY_SECONDS[1],
                    WATCH_QUEUE_RETRY_SECONDS[0]
                    * 2 ** min(attempts - 1, WATCH_QUEUE_RETRY_MAX_DOUBLINGS))
        if retry_after is not None and math.isfinite(retry_after) and retry_after > 0:
            delay = max(WATCH_QUEUE_RETRY_SECONDS[0], retry_after)
        return self.update(
            item, state='retry_wait', attempts=attempts,
            next_retry_at=self.clock() + delay, reason=reason,
            phase=phase, http_status=status,
        )

    def finish(self, cutoff: float) -> None:
        """Persist one explicit admission cutoff without cancelling an HTTP request.

        Args:
            cutoff (float): User action's Unix timestamp, in seconds.

        Returns:
            None: Preserves the first Finish intent through all retries and restarts.
        """
        with self.lock:
            if self.data.get('cutoff'):
                return
            row = copy.deepcopy(self.data)
            row.update(cutoff=cutoff, frozen=True, completed=False)
            row['last_change_at'] = cutoff
            row['last_change_scan_generation'] = self.scan_generation
            row['scan_generation'] = self.scan_generation
            self._publish(row)

    def stop_and_retain(self, cutoff: float) -> None:
        """Persist an explicit stop while retaining accepted queue work.

        Args:
            cutoff (float): Stop time in Unix seconds; an existing cutoff wins.

        Returns:
            None: Saves a durable stop flag without completion or abandonment.
        """
        if not math.isfinite(cutoff) or cutoff <= 0:
            raise ValueError('Stop cutoff must be a positive finite timestamp.')
        with self.lock:
            row = copy.deepcopy(self.data)
            if not row.get('cutoff'):
                row['cutoff'] = cutoff
                row['frozen'] = True
            row['retained_stop'] = True
            row['completed'] = False
            row['checkpoint'] = self.clock()
            self._publish(row)

    def resume_retained(self) -> None:
        """Clear only a retained-stop flag after an explicit resume decision.

        Returns:
            None: Preserves cutoff, session, accepted paths and their order.
        """
        with self.lock:
            if self.data.get('retained_stop') is not True:
                return
            row = copy.deepcopy(self.data)
            row['retained_stop'] = False
            row['checkpoint'] = self.clock()
            self._publish(row)

    def complete(self, stable_seconds: float) -> bool:
        """Seal an empty finished interval only after a fresh successful final scan.

        Args:
            stable_seconds (float): Minimum post-cutoff observation grace in seconds.

        Returns:
            bool: True after durable completion; False while more evidence is needed.
        """
        with self.lock:
            cutoff = float(self.data.get('cutoff', 0.0))
            scanned = self.last_scan_at
            if (not cutoff or self.data.get('retained_stop') is True
                    or self.head() is not None
                    or self.data.get('order_verified') is not True
                    or scanned < cutoff + stable_seconds
                    or scanned < float(self.data['last_change_at'])
                    or self.scan_generation <= self.data.get('last_change_scan_generation', 0)):
                return False
            row = copy.deepcopy(self.data)
            row['completed'] = True
            row['last_scan_at'] = scanned
            row['scan_generation'] = self.scan_generation
            self._publish(row)
            return True
