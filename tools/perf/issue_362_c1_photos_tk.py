# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_c1_photos_tk.py
# Module Type: Windows PhotosWorkspace Diagnostic
# Purpose: Measure real PhotosWorkspace mounting and reconciliation at 10,000 rows.
# Responsibilities:
#   - Exercise the production Tk tree owner with a controlled session boundary.
#   - Measure cold and DPAPI-backed warm local catalogue display and refresh.
#   - Retain Tk callback, heartbeat, cancellation and generation-fence evidence.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Measure the real PhotosWorkspace tree path on hosted Windows Tk."""

import json
import math
import os
import platform
import re
import sys
import time
import tkinter as tk
from contextlib import ExitStack
from datetime import datetime, timezone
from pathlib import Path
import tempfile
import threading
from types import TracebackType
from typing import Callable

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'winapp'))

from uploader import photos_workspace, ui_theme
from uploader.app_linking import (
    GALLERIES_SCOPE, LinkedGallery, LinkedGalleryTree, LinkedMediaPage,
    LinkingSession,
)
from uploader.gallery_catalogue_cache import GalleryCatalogueCache
from uploader.models import JsonValue
from uploader.windows_display import prepare_windows_display


# Purpose: Exercise the accepted complete-tree/cache row capacity.
# Type: int. Units: gallery rows. Scope: each cold, warm and refresh phase.
# Consumers: make_tree and run_diagnostic. Rationale: 10,000 is the existing
# tree protocol and local snapshot maximum, and flat roots expose sibling scans.
PHOTOS_ROW_COUNT = 10_000

# Purpose: Keep the fake current session live through the complete diagnostic.
# Type: float. Units: monotonic seconds. Scope: one synthetic session instance.
# Consumers: make_session. Rationale: 900 seconds exceeds the existing 180-second
# audit child cap without changing production session expiry policy.
SESSION_LIFETIME_SECONDS = 900.0

# Purpose: Sample Tk responsiveness independently of production callback logs.
# Type: int. Units: milliseconds. Scope: one PhotosWorkspace mainloop run.
# Consumers: heartbeat. Rationale: 10 ms observes pauses without busy work.
HEARTBEAT_INTERVAL_MS = 10

# Purpose: Bound asynchronous fixture state checks while Tk continues dispatching.
# Type: int. Units: milliseconds. Scope: one scheduled phase transition.
# Consumers: wait_for. Rationale: 10 ms keeps transition checks separate from
# the production 16 ms row-mount cadence.
PHASE_POLL_INTERVAL_MS = 10

# Purpose: Bound the amount of detailed callback timing retained in one report.
# Type: int. Units: observations. Scope: all measured workspace callbacks.
# Consumers: record_callback. Rationale: 4096 exceeds expected 10,000-row slices
# plus polls while preventing evidence size from growing with runner jitter.
CALLBACK_SAMPLE_LIMIT = 4096

# Purpose: Bound the independent Tk heartbeat samples retained for responsiveness.
# Type: int. Units: observations. Scope: one diagnostic mainloop.
# Consumers: heartbeat. Rationale: 4096 spans more than 40 seconds at the
# scheduled cadence and stays bounded if later phases take longer.
HEARTBEAT_SAMPLE_LIMIT = 4096

# Purpose: Keep a 10,000-row display inside the existing native viewer audit cap.
# Type: float. Units: seconds. Scope: this diagnostic's internal watchdog.
# Consumers: wait_for. Rationale: use the existing 180-second owner limit and
# leave no larger wait than audit_run_thumbnail_batch_viewer already grants.
WATCHDOG_SECONDS = 180.0

# Purpose: Use a fixed, synthetic localhost-only bearer accepted by LinkingSession.
# Type: str. Units: token characters. Scope: fake session external boundary.
# Consumers: make_session. Rationale: match the existing B3 local fixture token
# shape; this value is never sent to a server or retained in the report.
FIXTURE_TOKEN = 'pgla_' + 'A' * 43

# Each phase keeps *_count integers, *_ms float samples or elapsed scalars,
# the phase_started monotonic origin, first_rows_ms (possibly None), and
# samples_truncated. Keys are fixture-owned; no server values become labels.
CallbackMetrics = dict[str, int | float | list[float] | bool | None]
# Original production poll, cached mount, fresh mount and sibling-index methods.
WorkspaceCallbacks = tuple[
    Callable[[photos_workspace.PhotosWorkspace], None],
    Callable[[photos_workspace.PhotosWorkspace], None],
    Callable[[photos_workspace.PhotosWorkspace], None],
    Callable[[photos_workspace.PhotosWorkspace, str, int], int],
]


def make_tree(revision: str, changed_title: bool = False) -> LinkedGalleryTree:
    """Create a flat full-size gallery tree with stable ordered identities.

    Args:
        revision (str): Canonical synthetic revision of 64 lowercase hex chars.
        changed_title (bool): Whether the first surviving row changes in refresh.

    Returns:
        LinkedGalleryTree: Complete flat tree-v1 fixture with 10,000 rows.
    """
    start_id = 2 if changed_title else 1
    rows = tuple(
        LinkedGallery(gallery_id, None,
                      'Updated gallery' if changed_title and gallery_id == 2
                      else 'Synthetic gallery ' + str(gallery_id))
        for gallery_id in range(start_id, start_id + PHOTOS_ROW_COUNT)
    )
    return LinkedGalleryTree(rows, revision)


def make_session(
    tree: LinkedGalleryTree, tree_gate: threading.Event | None = None,
) -> LinkingSession:
    """Create an actual LinkingSession with a deterministic external boundary.

    Args:
        tree (LinkedGalleryTree): Full tree returned by the synthetic reader.
        tree_gate (threading.Event | None): Optional fixture release gate that
            lets the real cached-display callbacks finish before fresh data arrives.

    Returns:
        LinkingSession: Real session owner whose only replaced operations are
            the remote tree and media-page calls; no network is contacted.
    """
    session = LinkingSession(
        'http://localhost', FIXTURE_TOKEN,
        time.monotonic() + SESSION_LIFETIME_SECONDS,
        GALLERIES_SCOPE,
        gallery_tree_supported=True,
    )
    def read_tree(
        revision: str | None = None,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryTree:
        """Return the deterministic complete tree after optional fixture gating.

        Args:
            revision (str | None): Cached revision requested by the real owner.
            cancelled (threading.Event | None): Captured session generation fence.

        Returns:
            LinkedGalleryTree: Current synthetic response for this session.

        Raises:
            RuntimeError: Cancellation or the existing diagnostic deadline
                prevents delivery of this controlled response.
        """
        del revision
        if cancelled is not None and cancelled.is_set():
            raise RuntimeError('fixture_cancelled')
        if tree_gate is not None and not tree_gate.wait(WATCHDOG_SECONDS):
            raise RuntimeError('fixture_tree_gate_timeout')
        if cancelled is not None and cancelled.is_set():
            raise RuntimeError('fixture_cancelled')
        return tree

    session.gallery_tree = read_tree
    session.media_page = lambda gallery_id, after_id=None, cancelled=None: (
        LinkedMediaPage(gallery_id, None, (), None)
    )
    return session


def record_callback(
    metrics: CallbackMetrics, kind: str, elapsed_ms: float,
) -> None:
    """Retain bounded callback duration samples and complete sample counts.

    Args:
        metrics (CallbackMetrics): Mutable metrics owned by one fixture phase.
        kind (str): Fixed callback kind, either poll, cached_mount or fresh_mount.
        elapsed_ms (float): Time spent in the complete original Tk callback.

    Returns:
        None: Count always advances while detailed samples remain bounded.
    """
    count_key = kind + '_count'
    metrics[count_key] = int(metrics.get(count_key, 0)) + 1
    samples = metrics.setdefault(kind + '_ms', [])
    if isinstance(samples, list) and len(samples) < CALLBACK_SAMPLE_LIMIT:
        samples.append(round(elapsed_ms, 3))
    elif isinstance(samples, list):
        metrics['samples_truncated'] = True


def percentile(samples: list[float], fraction: float) -> float | None:
    """Return a nearest-rank percentile or None before the first observation.

    Args:
        samples (list[float]): Callback or heartbeat samples in milliseconds.
        fraction (float): Requested percentile in the inclusive zero-to-one range.

    Returns:
        float | None: Rounded nearest-rank observation, or None for no samples.
    """
    if not samples:
        return None
    ordered = sorted(samples)
    # Nearest rank is one-based; clamp its converted list index at either edge.
    index = max(0, min(len(ordered) - 1, math.ceil(len(ordered) * fraction) - 1))
    return round(ordered[index], 3)


def instrument_workspace(
    active_metrics: list[CallbackMetrics],
) -> WorkspaceCallbacks:
    """Wrap real workspace callbacks and retain their original class methods.

    Args:
        active_metrics (list[CallbackMetrics]): One-element reference to the
            callback metrics for the currently measured phase.

    Returns:
        WorkspaceCallbacks:
            Original poll, cached mount, fresh mount and ordering methods.
    """
    workspace_class = photos_workspace.PhotosWorkspace
    original_poll = workspace_class._poll_events
    original_cached = workspace_class._mount_cached_catalogue_batch
    original_fresh = workspace_class._mount_complete_catalogue_batch
    original_ordered = workspace_class._ordered_index

    def measured_poll(workspace: photos_workspace.PhotosWorkspace) -> None:
        """Measure the complete production result-poll callback.

        Args:
            workspace (photos_workspace.PhotosWorkspace): Current Tk owner.

        Returns:
            None: Delegates to the original result-poll implementation.
        """
        started = time.perf_counter()
        original_poll(workspace)
        phase_metrics = active_metrics[0]
        if (phase_metrics.get('first_rows_ms') is None
                and workspace.tree.get_children()):
            phase_metrics['first_rows_ms'] = round(
                (time.perf_counter() - float(phase_metrics['phase_started']))
                * 1000.0, 3,
            )
        record_callback(phase_metrics, 'poll',
                        (time.perf_counter() - started) * 1000.0)

    def measured_cached(workspace: photos_workspace.PhotosWorkspace) -> None:
        """Measure one actual production cached-row mount callback.

        Args:
            workspace (photos_workspace.PhotosWorkspace): Current Tk owner.

        Returns:
            None: Delegates to the original cached-row mount implementation.
        """
        started = time.perf_counter()
        prior_index = workspace._cached_mount_index
        original_cached(workspace)
        if (workspace._cached_mount_index - prior_index
                > photos_workspace.PHOTOS_CATALOGUE_CACHE_BATCH_SIZE):
            raise RuntimeError('cached_mount_slice_unbounded')
        phase_metrics = active_metrics[0]
        if (phase_metrics.get('first_rows_ms') is None
                and workspace.tree.get_children()):
            phase_metrics['first_rows_ms'] = round(
                (time.perf_counter() - float(phase_metrics['phase_started']))
                * 1000.0, 3,
            )
        record_callback(phase_metrics, 'cached_mount',
                        (time.perf_counter() - started) * 1000.0)

    def measured_fresh(workspace: photos_workspace.PhotosWorkspace) -> None:
        """Measure one actual production fresh-tree reconciliation callback.

        Args:
            workspace (photos_workspace.PhotosWorkspace): Current Tk owner.

        Returns:
            None: Delegates to the original fresh-tree mount implementation.
        """
        started = time.perf_counter()
        prior_index = workspace._cached_mount_index
        original_fresh(workspace)
        if (workspace._cached_mount_index - prior_index
                > photos_workspace.PHOTOS_CATALOGUE_CACHE_BATCH_SIZE):
            raise RuntimeError('fresh_mount_slice_unbounded')
        phase_metrics = active_metrics[0]
        if (phase_metrics.get('first_rows_ms') is None
                and workspace.tree.get_children()):
            phase_metrics['first_rows_ms'] = round(
                (time.perf_counter() - float(phase_metrics['phase_started']))
                * 1000.0, 3,
            )
        record_callback(phase_metrics, 'fresh_mount',
                        (time.perf_counter() - started) * 1000.0)

    def measured_ordered(
        workspace: photos_workspace.PhotosWorkspace,
        parent_id: str, gallery_id: int,
    ) -> int:
        """Measure real sibling-order lookup calls without retaining each sample.

        Args:
            workspace (photos_workspace.PhotosWorkspace): Current Tk owner.
            parent_id (str): Actual tree parent passed by production rendering.
            gallery_id (int): Actual gallery identity passed by production rendering.

        Returns:
            int: Original sibling-order insertion index.
        """
        started = time.perf_counter()
        result = original_ordered(workspace, parent_id, gallery_id)
        elapsed_ms = (time.perf_counter() - started) * 1000.0
        phase_metrics = active_metrics[0]
        phase_metrics['ordered_index_count'] = int(
            phase_metrics.get('ordered_index_count', 0),
        ) + 1
        phase_metrics['ordered_index_total_ms'] = float(
            phase_metrics.get('ordered_index_total_ms', 0.0),
        ) + elapsed_ms
        phase_metrics['ordered_index_max_ms'] = max(
            float(phase_metrics.get('ordered_index_max_ms', 0.0)), elapsed_ms,
        )
        return result

    workspace_class._poll_events = measured_poll
    workspace_class._mount_cached_catalogue_batch = measured_cached
    workspace_class._mount_complete_catalogue_batch = measured_fresh
    workspace_class._ordered_index = measured_ordered
    return original_poll, original_cached, original_fresh, original_ordered


def restore_workspace_instrumentation(
    originals: WorkspaceCallbacks,
) -> None:
    """Restore the production workspace class after the isolated run.

    Args:
        originals (WorkspaceCallbacks):
            Original poll, cached mount, fresh mount and ordered-index callbacks.

    Returns:
        None: The imported application class has no diagnostic wrappers left.
    """
    workspace_class = photos_workspace.PhotosWorkspace
    (workspace_class._poll_events,
     workspace_class._mount_cached_catalogue_batch,
     workspace_class._mount_complete_catalogue_batch,
     workspace_class._ordered_index) = originals


def callback_summary(metrics: CallbackMetrics) -> dict[str, int | float | None | bool]:
    """Summarize one phase's bounded Tk and sibling-order timing samples.

    Args:
        metrics (CallbackMetrics): Raw bounded callback measurements for one phase.

    Returns:
        dict[str, int | float | None | bool]: Count, p95, max and first-row facts.
    """
    result: dict[str, int | float | None | bool] = {
        'first_rows_ms': (
            float(metrics['first_rows_ms'])
            if isinstance(metrics.get('first_rows_ms'), (int, float)) else None
        ),
        'ordered_index_count': int(metrics.get('ordered_index_count', 0)),
        'ordered_index_total_ms': round(
            float(metrics.get('ordered_index_total_ms', 0.0)), 3,
        ),
        'ordered_index_max_ms': round(
            float(metrics.get('ordered_index_max_ms', 0.0)), 3,
        ),
        'samples_truncated': bool(metrics.get('samples_truncated', False)),
    }
    for kind in ('poll', 'cached_mount', 'fresh_mount'):
        samples = metrics.get(kind + '_ms', [])
        values = samples if isinstance(samples, list) else []
        result[kind + '_count'] = int(metrics.get(kind + '_count', 0))
        result[kind + '_p95_ms'] = percentile(values, 0.95)
        result[kind + '_max_ms'] = round(max(values), 3) if values else None
    return result


def run_diagnostic(progress: dict[str, JsonValue]) -> dict[str, JsonValue]:
    """Run cold, warm-reconcile and disconnect-fence phases on real Tk.

    Args:
        progress (dict[str, JsonValue]): Mutable bounded evidence retained even
            when a Tk callback or phase fails.

    Returns:
        dict[str, JsonValue]: Schema/status, commit/run identity, runtime facts,
            fixed scope/limitations, and phase/callback/heartbeat numeric metrics;
            no bearer, account, profile path or gallery title is retained.

    Raises:
        RuntimeError: The real workspace or its local snapshot misses a required
            count, ordering, cache, incremental-mount or generation-fence check.
    """
    prepare_windows_display()
    prior_local = os.environ.get('LOCALAPPDATA')
    start_utc = datetime.now(timezone.utc).isoformat(timespec='seconds')
    progress.update({
        'schema_version': 1, 'status': 'FAIL',
        'commit_sha': os.environ['GITHUB_SHA'],
        'github_run_id': os.environ['GITHUB_RUN_ID'],
        'started_utc': start_utc,
        'scope': 'owned-windows-tk-real-photos-workspace-synthetic-session',
        'rows_per_phase': PHOTOS_ROW_COUNT,
        'cache_protection': 'windows-dpapi-user-scope',
    })
    callback_metrics: CallbackMetrics = {
        'poll_count': 0, 'cached_mount_count': 0, 'fresh_mount_count': 0,
        'poll_ms': [], 'cached_mount_ms': [], 'fresh_mount_ms': [],
        'samples_truncated': False, 'phase_started': time.perf_counter(),
        'first_rows_ms': None,
    }
    phase_callbacks: dict[str, CallbackMetrics] = {'cold': callback_metrics}
    active_metrics = [callback_metrics]
    heartbeat_samples: list[float] = []
    heartbeat_dropped = [0]
    heartbeat_max_ms = [0.0]
    original_methods = instrument_workspace(active_metrics)
    root: tk.Tk | None = None
    workspace: photos_workspace.PhotosWorkspace | None = None
    sessions: list[LinkingSession] = []
    reports: dict[str, JsonValue] = {}
    progress['phases'] = reports
    progress['callbacks'] = phase_callbacks
    progress['heartbeat_samples_ms'] = heartbeat_samples
    failure: list[str] = []
    phase_started = time.perf_counter()
    deadline = phase_started + WATCHDOG_SECONDS
    current_tree_gate: list[threading.Event | None] = [None]

    try:
        with (tempfile.TemporaryDirectory(prefix='c1-photos-') as temporary,
              ExitStack() as ui_cleanup):
            # Destroy Tk and release a held response before deleting its profile.
            ui_cleanup.callback(lambda: current_tree_gate[0].set()
                                if current_tree_gate[0] is not None else None)
            temp_root = Path(temporary)
            os.environ['LOCALAPPDATA'] = str(temp_root / 'profile')
            binding = 'a' * 64
            cache = GalleryCatalogueCache(temp_root / 'catalogue-cache', binding)
            root = tk.Tk()
            ui_cleanup.callback(root.destroy)
            root.geometry('900x650')
            root.title('PHP Gallery C1 PhotosWorkspace diagnostic')
            ui_theme.configure_theme(root)
            last_heartbeat = [time.perf_counter()]

            def heartbeat() -> None:
                """Measure gaps between scheduled Tk loop observations.

                Returns:
                    None: Schedules the next heartbeat until fixture teardown.
                """
                now = time.perf_counter()
                elapsed_ms = (now - last_heartbeat[0]) * 1000.0
                heartbeat_max_ms[0] = max(heartbeat_max_ms[0], elapsed_ms)
                if len(heartbeat_samples) < HEARTBEAT_SAMPLE_LIMIT:
                    heartbeat_samples.append(elapsed_ms)
                else:
                    heartbeat_dropped[0] += 1
                last_heartbeat[0] = now
                if root is not None and root.winfo_exists():
                    root.after(HEARTBEAT_INTERVAL_MS, heartbeat)

            def wait_for(
                predicate: Callable[[], bool], success: Callable[[], None], label: str,
            ) -> None:
                """Poll a fixture phase without blocking Tk's event loop.

                Args:
                    predicate (Callable[[], bool]): Readiness predicate.
                    success (Callable[[], None]): Next-phase callback.
                    label (str): Fixed safe phase name for timeout diagnostics.

                Returns:
                    None: Calls the next phase only after predicate completion.
                """
                try:
                    if time.perf_counter() >= deadline:
                        failure.append('watchdog_' + label)
                        root.quit()
                        return
                    if predicate():
                        success()
                        return
                except Exception as error:
                    progress['failure_phase'] = label
                    handle_tk_callback_error(type(error), error, error.__traceback__)
                    return
                root.after(PHASE_POLL_INTERVAL_MS,
                           lambda: wait_for(predicate, success, label))

            def mounted(workspace_owner: photos_workspace.PhotosWorkspace) -> bool:
                """Check exact full tree identity and completion on Tk.

                Args:
                    workspace_owner (photos_workspace.PhotosWorkspace): Active owner.

                Returns:
                    bool: True after all rows are mounted and the tree pass closes.
                """
                return (
                    len(workspace_owner.tree.get_children()) == PHOTOS_ROW_COUNT
                    and workspace_owner._complete_catalogue_tree is None
                    and workspace_owner._cached_mount_index == PHOTOS_ROW_COUNT
                )

            def workspace_factory(
                session: LinkingSession,
            ) -> photos_workspace.PhotosWorkspace:
                """Mount the actual workspace using the shared real cache owner.

                Args:
                    session (LinkingSession): Actual synthetic session owner.

                Returns:
                    photos_workspace.PhotosWorkspace: Mapped production widget tree.
                """
                del session
                owner = photos_workspace.PhotosWorkspace(
                    root, lambda: current_session[0], lambda: None,
                    lambda: 'en', catalogue_cache=lambda _session: cache,
                    load_selected_gallery=lambda _session: 1,
                )
                owner.pack(fill='both', expand=True)
                return owner

            current_session: list[LinkingSession | None] = [None]
            root.after(0, heartbeat)

            def start_cold() -> None:
                """Mount and measure the empty-cache full tree on real Tk.

                Returns:
                    None: The warm session starts after the cold row count settles.
                """
                nonlocal workspace, phase_started
                cold_session = make_session(make_tree('a' * 64))
                sessions.append(cold_session)
                current_session[0] = cold_session
                phase_started = time.perf_counter()
                callback_metrics['phase_started'] = phase_started
                workspace = workspace_factory(cold_session)
                wait_for(
                    lambda: workspace is not None and mounted(workspace),
                    finish_cold, 'cold_mount',
                )

            def finish_cold() -> None:
                """Record cold display and start the disk-warm changed tree.

                Returns:
                    None: New session generation reads the existing local snapshot.
                """
                nonlocal workspace
                if workspace is None:
                    raise RuntimeError('cold_workspace_missing')
                rows = tuple(int(item) for item in workspace.tree.get_children())
                if (len(rows) != PHOTOS_ROW_COUNT
                        or rows != tuple(range(1, PHOTOS_ROW_COUNT + 1))):
                    raise RuntimeError('cold_tree_identity')
                cold_cache_source = workspace._catalogue_performance.snapshot().cache_source
                if cold_cache_source != 'miss':
                    raise RuntimeError('cold_cache_not_empty')
                reports['cold'] = {
                    'rows': len(rows), 'elapsed_ms': round(
                        (time.perf_counter() - phase_started) * 1000.0, 3,
                    ), 'cache_source': cold_cache_source,
                    'first_rows_ms': callback_metrics['first_rows_ms'],
                    'callbacks': callback_metrics,
                }
                wait_for(
                    lambda: workspace is not None
                    and workspace._catalogue_cache_writer._worker is None,
                    start_warm, 'cold_cache_save',
                )

            def start_warm() -> None:
                """Start the disk-warm pass after the cold snapshot is atomic.

                Returns:
                    None: The production cache loader runs on its worker.
                """
                nonlocal workspace, phase_started
                if workspace is None:
                    raise RuntimeError('cold_workspace_missing')
                workspace.destroy()
                workspace = None
                warm_tree = make_tree('b' * 64, changed_title=True)
                warm_gate = threading.Event()
                current_tree_gate[0] = warm_gate
                warm_metrics: CallbackMetrics = {
                    'poll_count': 0, 'cached_mount_count': 0,
                    'fresh_mount_count': 0, 'poll_ms': [],
                    'cached_mount_ms': [], 'fresh_mount_ms': [],
                    'samples_truncated': False,
                    'phase_started': time.perf_counter(),
                    'first_rows_ms': None,
                }
                phase_callbacks['warm_cache_display'] = warm_metrics
                active_metrics[0] = warm_metrics
                warm_session = make_session(warm_tree, warm_gate)
                sessions.append(warm_session)
                current_session[0] = warm_session
                phase_started = time.perf_counter()
                workspace = workspace_factory(warm_session)
                wait_for(
                    lambda: workspace is not None
                    and len(workspace.tree.get_children()) == PHOTOS_ROW_COUNT
                    and workspace._cached_mount_index == PHOTOS_ROW_COUNT
                    and workspace._complete_catalogue_tree is None,
                    release_warm_tree, 'warm_cache_display',
                )

            def release_warm_tree() -> None:
                """Record the disk-only mount before releasing fresh reconciliation.

                Returns:
                    None: Fresh tree delivery starts a separately timed phase.
                """
                nonlocal phase_started
                if workspace is None:
                    raise RuntimeError('warm_workspace_missing')
                snapshot = workspace._catalogue_performance.snapshot()
                if snapshot.cache_source != 'disk':
                    raise RuntimeError('warm_cache_source')
                rows = tuple(int(item) for item in workspace.tree.get_children())
                if rows != tuple(range(1, PHOTOS_ROW_COUNT + 1)):
                    raise RuntimeError('warm_cache_identity')
                cache_phase = next(
                    (phase for phase in snapshot.phases
                     if phase.stage == 'cache_disk'), None,
                )
                reports['warm_cache_display'] = {
                    'rows': len(rows), 'cache_source': snapshot.cache_source,
                    'elapsed_ms': round((time.perf_counter() - phase_started) * 1000.0, 3),
                    'first_rows_ms': phase_callbacks['warm_cache_display']['first_rows_ms'],
                    'cache_read_p50_ms': cache_phase.p50_ms if cache_phase else None,
                    'cache_read_samples': cache_phase.samples if cache_phase else 0,
                    'callbacks': phase_callbacks['warm_cache_display'],
                }
                reconcile_metrics: CallbackMetrics = {
                    'poll_count': 0, 'cached_mount_count': 0,
                    'fresh_mount_count': 0, 'poll_ms': [],
                    'cached_mount_ms': [], 'fresh_mount_ms': [],
                    'samples_truncated': False,
                    'phase_started': time.perf_counter(),
                    'first_rows_ms': None,
                }
                phase_callbacks['warm_reconcile'] = reconcile_metrics
                active_metrics[0] = reconcile_metrics
                phase_started = time.perf_counter()
                if current_tree_gate[0] is None:
                    raise RuntimeError('warm_tree_gate_missing')
                current_tree_gate[0].set()
                wait_for(
                    lambda: workspace is not None
                    and mounted(workspace)
                    and workspace._catalogue_performance.snapshot().transport_source
                    == 'tree-v1-full'
                    and workspace._catalogue_cache_snapshot is not None
                    and workspace._catalogue_cache_snapshot.revision == 'b' * 64,
                    finish_warm, 'warm_reconcile',
                )

            def finish_warm() -> None:
                """Verify changed-tree reconciliation and await its cache save.

                Returns:
                    None: Cancellation starts after the prior snapshot writer drains.
                """
                nonlocal phase_started
                if workspace is None:
                    raise RuntimeError('warm_workspace_missing')
                rows = tuple(int(item) for item in workspace.tree.get_children())
                if (len(rows) != PHOTOS_ROW_COUNT
                        or rows != tuple(range(2, PHOTOS_ROW_COUNT + 2))):
                    raise RuntimeError('warm_tree_identity')
                if 'Updated gallery' not in str(workspace.tree.item('2', 'text')):
                    raise RuntimeError('warm_title_not_reconciled')
                if workspace._catalogue_performance.snapshot().cache_source != 'disk':
                    raise RuntimeError('warm_cache_not_retained')
                reports['warm_reconcile'] = {
                    'rows': len(rows), 'elapsed_ms': round(
                        (time.perf_counter() - phase_started) * 1000.0, 3,
                    ), 'cache_source': workspace._catalogue_performance.snapshot().cache_source,
                    'revision': 'b' * 64,
                    'first_rows_ms': phase_callbacks['warm_reconcile']['first_rows_ms'],
                    'callbacks': phase_callbacks['warm_reconcile'],
                }
                wait_for(
                    lambda: workspace is not None
                    and workspace._catalogue_cache_writer._worker is None,
                    start_cancel, 'warm_cache_save',
                )

            def start_cancel() -> None:
                """Begin a new large pass that will be cancelled mid-mount.

                Returns:
                    None: A later disconnect fences its pending UI callback.
                """
                nonlocal phase_started
                if workspace is None:
                    raise RuntimeError('cancel_workspace_missing')
                cancel_metrics: CallbackMetrics = {
                    'poll_count': 0, 'cached_mount_count': 0,
                    'fresh_mount_count': 0, 'poll_ms': [],
                    'cached_mount_ms': [], 'fresh_mount_ms': [],
                    'samples_truncated': False,
                    'phase_started': time.perf_counter(),
                    'first_rows_ms': None,
                }
                phase_callbacks['cancel_disconnect'] = cancel_metrics
                active_metrics[0] = cancel_metrics
                pending_gate = threading.Event()
                pending_session = make_session(make_tree('c' * 64), pending_gate)
                current_tree_gate[0] = pending_gate
                sessions.append(pending_session)
                current_session[0] = pending_session
                phase_started = time.perf_counter()
                workspace.refresh_session()
                wait_for(
                    lambda: workspace is not None
                    and workspace._cached_mount_after is not None
                    and 0 < len(workspace.tree.get_children()) < PHOTOS_ROW_COUNT,
                    cancel_pending_mount, 'cancel_pending',
                )

            def cancel_pending_mount() -> None:
                """Disconnect during a scheduled slice and reject stale results.

                Returns:
                    None: The stale generation remains absent after Tk dispatches.
                """
                if workspace is None:
                    raise RuntimeError('cancel_workspace_missing')
                pending_generation = workspace._catalogue_generation
                pending_rows = len(workspace.tree.get_children())
                if workspace._cached_mount_after is None or pending_rows >= PHOTOS_ROW_COUNT:
                    raise RuntimeError('cancel_not_pending')
                pending_index = workspace._cached_mount_index
                workspace._events.put(photos_workspace._CatalogueTreeResult(
                    pending_generation - 1, make_tree('d' * 64),
                ))
                if workspace._poll_id is not None:
                    workspace.after_cancel(workspace._poll_id)
                    workspace._poll_id = None
                workspace._poll_events()
                if (len(workspace.tree.get_children()) != pending_rows
                        or workspace._cached_mount_index != pending_index
                        or workspace._catalogue_generation != pending_generation):
                    raise RuntimeError('stale_generation_accepted')
                reports['stale_generation_rejected'] = True
                current_session[0] = None
                workspace.refresh_session()
                if current_tree_gate[0] is not None:
                    current_tree_gate[0].set()
                workspace._events.put(photos_workspace._CatalogueTreeResult(
                    pending_generation, make_tree('c' * 64),
                ))
                workspace._poll_events()
                if workspace.tree.get_children():
                    raise RuntimeError('stale_generation_mounted')
                reports['cancel_disconnect'] = {
                    'rows_before_disconnect': pending_rows,
                    'rows_after_stale_delivery': len(workspace.tree.get_children()),
                    'elapsed_ms': round((time.perf_counter() - phase_started) * 1000.0, 3),
                    'generation_advanced': workspace._catalogue_generation > pending_generation,
                    'callbacks': phase_callbacks['cancel_disconnect'],
                }
                if workspace._cached_mount_after is not None:
                    raise RuntimeError('pending_callback_not_cancelled')
                root.after(0, root.quit)

            def handle_tk_callback_error(
                exception_type: type[BaseException], exception: BaseException,
                traceback: TracebackType | None,
            ) -> None:
                """Stop the fixture and retain only a fixed callback error code.

                Args:
                    exception_type (type[BaseException]): Tk exception class.
                    exception (BaseException): Tk exception, never exported.
                    traceback (TracebackType | None): Tk traceback, never exported.

                Returns:
                    None: Quits instead of waiting until the outer process timeout.
                """
                del traceback
                reason = exception.args[0] if len(exception.args) == 1 else None
                failure.append(
                    reason if isinstance(reason, str)
                    and re.fullmatch(r'[a-z0-9_]+', reason) else 'tk_callback'
                )
                error_name = exception_type.__name__
                progress['tk_callback_exception_type'] = (
                    error_name if re.fullmatch(r'[A-Za-z][A-Za-z0-9]{0,63}', error_name)
                    else 'Other'
                )
                root.quit()

            root.report_callback_exception = handle_tk_callback_error
            root.after(0, start_cold)
            root.mainloop()
            if failure:
                raise RuntimeError(failure[0])
            if 'cancel_disconnect' not in reports:
                raise RuntimeError('diagnostic_incomplete')
            reports['heartbeat'] = {
                'samples': len(heartbeat_samples),
                'dropped': heartbeat_dropped[0],
                'p50_ms': percentile(heartbeat_samples, 0.50),
                'p95_ms': percentile(heartbeat_samples, 0.95),
                'max_ms': round(heartbeat_max_ms[0], 3) if heartbeat_samples else None,
            }
            reports['callback_summaries'] = {
                name: callback_summary(metrics)
                for name, metrics in phase_callbacks.items()
            }
            progress.update({
                'schema_version': 1,
                'status': 'PASS',
                'commit_sha': os.environ['GITHUB_SHA'],
                'github_run_id': os.environ['GITHUB_RUN_ID'],
                'started_utc': start_utc,
                'finished_utc': datetime.now(timezone.utc).isoformat(timespec='seconds'),
                'scope': 'owned-windows-tk-real-photos-workspace-synthetic-session',
                'runtime': {'python': platform.python_version(), 'windows': platform.platform(),
                            'tk': tk.TkVersion},
                'rows_per_phase': PHOTOS_ROW_COUNT,
                'cache_protection': 'windows-dpapi-user-scope',
                'limitations': [
                    'Synthetic session boundary; no PHP, MariaDB, network, or account identity.',
                    'Mounted flat root siblings exercise the real Tk hierarchy and ordering path.',
                    'Callback timing is hosted-runner observation without a pass threshold.',
                    'Not evidence of installed-machine responsiveness or monitor scanout.',
                ],
            })
            return progress
    finally:
        if workspace is not None and not workspace._destroyed:
            workspace.destroy()
        if root is not None:
            try:
                root.destroy()
            except tk.TclError:
                pass
        for session in sessions:
            session.close()
        restore_workspace_instrumentation(original_methods)
        if prior_local is None:
            os.environ.pop('LOCALAPPDATA', None)
        else:
            os.environ['LOCALAPPDATA'] = prior_local


def main() -> int:
    """Run only as an exact-SHA hosted Windows diagnostic and save evidence.

    Returns:
        int: Zero for complete evidence, one for failed observations, or two
            when hosted identity, platform or output confinement is refused.
    """
    sha = os.environ.get('GITHUB_SHA', '')
    expected = os.environ.get('ISSUE_362_EXPECTED_SHA', '')
    run_id = os.environ.get('GITHUB_RUN_ID', '')
    if (sys.platform != 'win32' or os.environ.get('GITHUB_ACTIONS') != 'true'
            or re.fullmatch(r'[a-f0-9]{40}', sha) is None or sha != expected
            or re.fullmatch(r'[0-9]+', run_id) is None or len(sys.argv) != 2):
        print('C1_PHOTOS_BLOCKED reason=ci_identity', file=sys.stderr)
        return 2
    report_path = Path(sys.argv[1]).resolve()
    output_root = (ROOT / 'cache' / 'test-audit').resolve()
    if not report_path.is_relative_to(output_root) or report_path.exists():
        print('C1_PHOTOS_BLOCKED reason=output', file=sys.stderr)
        return 2
    try:
        progress: dict[str, JsonValue] = {}
        report = run_diagnostic(progress)
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
        print('C1_PHOTOS_PASS')
        return 0
    except Exception as error:  # noqa: BLE001
        # Only fixed safe reason tokens enter the retained output.
        reason = error.args[0] if len(error.args) == 1 and isinstance(error.args[0], str) else ''
        safe_reason = reason if re.fullmatch(r'[a-z0-9_]+', reason) else 'fixture_error'
        failure_type = type(error).__name__
        if re.fullmatch(r'[A-Za-z][A-Za-z0-9]{0,63}', failure_type) is None:
            failure_type = 'Other'
        try:
            report_path.parent.mkdir(parents=True, exist_ok=True)
            progress.update({
                'schema_version': 1, 'status': 'FAIL', 'commit_sha': sha,
                'github_run_id': run_id, 'code': safe_reason,
                'failure_type': failure_type,
            })
            report_path.write_text(json.dumps(progress, indent=2) + '\n', encoding='utf-8')
        except OSError:
            pass
        print(f'C1_PHOTOS_FAIL reason={safe_reason}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
