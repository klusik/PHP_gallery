# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos_catalogue_performance.py
# Module Type: Windows Client Diagnostics
# Purpose: Measure the existing Photos catalogue path without altering it.
# Responsibilities:
#   - Distinguish retained/disk snapshots from fresh tree and paged responses.
#   - Retain bounded worker and Tk mount phases with monotonic durations.
#   - Export only fixed labels and numeric counts, never gallery identities.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Session-local, privacy-safe, bounded Photos catalogue phase measurements."""

from collections import defaultdict, deque
from dataclasses import dataclass
import math
import threading
import time


# Purpose: Match the existing five-minute A2 diagnostic sampling window.
# Type: int. Units: monotonic seconds. Scope: one Photos workspace.
# Consumers: CataloguePerformanceWindow. Rationale: Compare repeated cold and
# warm catalogue passes without retaining unlimited historical operation data.
CATALOGUE_PERFORMANCE_WINDOW_SECONDS = 300

# Purpose: Prevent an unbounded sequence of deep paged catalogue measurements.
# Type: int. Units: samples. Scope: one Photos workspace.
# Consumers: CataloguePerformanceWindow. Rationale: Store at most 256 fixed
# numeric records regardless of gallery size and session duration.
CATALOGUE_PERFORMANCE_SAMPLE_LIMIT = 256

# Purpose: Reject corrupted, nonfinite or unreasonably long phase measurements.
# Type: float. Units: milliseconds. Scope: one measured catalogue phase.
# Consumers: CataloguePerformanceWindow.observe. Rationale: Ten minutes is
# longer than a single request or Tk callback, with a fixed validation ceiling.
CATALOGUE_PERFORMANCE_MAX_PHASE_MS = 600_000.0

# Purpose: Permit only established catalogue work stages in diagnostics.
# Type: frozenset[str]. Units: fixed stage names. Scope: one catalogue event.
# Consumers: CataloguePerformanceWindow.observe. Rationale: Reject arbitrary
# titles, URLs, identifiers, error strings and data supplied by remote hosts.
_CATALOGUE_PERFORMANCE_STAGES = frozenset((
    'cache_retained', 'cache_disk', 'cache_miss', 'cache_disabled',
    'tree_full', 'tree_unchanged', 'paged_fallback',
    'page_ok', 'page_error', 'page_throttled',
    'tree_error', 'tree_throttled', 'mount_cached', 'mount_fresh',
    'page_apply',
))

# Purpose: Avoid attributing the optional tree's opaque fallback to a cause
# that a compatibility-returning client cannot prove.
# Type: frozenset[str]. Units: fixed safe reasons. Scope: one catalogue pass.
# Consumers: CataloguePerformanceWindow.observe. Rationale: Preserve unknown
# cause without logging exception text or accidentally masking auth refusal.
_CATALOGUE_FALLBACK_REASONS = frozenset((
    'none', 'capability_missing', 'optional_unavailable',
    'revision_mismatch', 'tree_error',
))

# Purpose: Retain only first-once UI milestones on the owning Tk thread.
# Type: tuple[str, ...]. Units: fixed stage labels. Scope: one catalogue pass.
# Consumers: CataloguePerformanceWindow.mark_milestone and render_summary.
# Rationale: No IDs or timestamps from the server may influence these labels.
CATALOGUE_STARTUP_MILESTONES = (
    'cached_rows_mounted', 'fresh_rows_confirmed',
    'gallery_view_mounted', 'first_thumbnail_visible',
    'first_viewport_settled',
)


@dataclass(frozen=True, slots=True)
class _CataloguePhaseSample:
    """Carry one immutable, identity-free worker or Tk phase sample.

    Attributes:
        observed_at (float): Monotonic time at event acceptance.
        stage (str): Fixed safe phase label.
        duration_ms (float | None): Measured duration or unknown.
        rows (int): Bounded number of handled rows, never their identities.
    """

    observed_at: float
    stage: str
    duration_ms: float | None
    rows: int


@dataclass(frozen=True, slots=True)
class CataloguePhaseSummary:
    """Expose one phase's bounded sample count, rows and nearest-rank timings.

    Attributes:
        stage (str): Allowlisted phase identifier.
        samples (int): Observations within the retained window.
        measured (int): Observations with genuine timing data.
        rows (int): Count of rows handled in these observations.
        p50_ms (float | None): Nearest-rank median or missing.
        p95_ms (float | None): Nearest-rank p95 or missing.
        p99_ms (float | None): Nearest-rank p99 or missing.
    """

    stage: str
    samples: int
    measured: int
    rows: int
    p50_ms: float | None
    p95_ms: float | None
    p99_ms: float | None


@dataclass(frozen=True, slots=True)
class CataloguePerformanceSnapshot:
    """Summarize the current pass and a fixed rolling observation window.

    Attributes:
        window_seconds (int): Monotonic trailing history interval.
        events (int): Currently retained samples, never a lifetime total.
        cache_source (str): Current pass's last-known display cache outcome.
        transport_source (str): Fresh authoritative tree/paged result or pending.
        fallback_reason (str): Fixed optional-tree fallback code or none.
        confirmed_rows (int): Rows confirmed in the current fresh pass.
        completed_pages (int): Current pass's accepted legacy page count.
        phases (tuple[CataloguePhaseSummary, ...]): Identity-free phase metrics.
        milestones (tuple[tuple[str, float], ...]): First-once elapsed durations
            from this explicit local catalogue pass, never wall-clock dates.
        fresh_to_thumbnail_ms (float | None): Comparable milestone delta, or
            None when no fresh rows preceded the visible thumbnail.
        fresh_to_viewport_settled_ms (float | None): Delta to the first fully
            resolved current visible viewport, not physical compositor output.
    """

    window_seconds: int
    events: int
    cache_source: str
    transport_source: str
    fallback_reason: str
    confirmed_rows: int
    completed_pages: int
    phases: tuple[CataloguePhaseSummary, ...]
    milestones: tuple[tuple[str, float], ...]
    fresh_to_thumbnail_ms: float | None
    fresh_to_viewport_settled_ms: float | None


def _nearest_rank(values: list[float], percentile: int) -> float | None:
    """Compute the nearest rank of actual measured durations.

    Args:
        values (list[float]): Finite, bounded phase durations in milliseconds.
        percentile (int): Requested whole percentile from 1 to 100.

    Returns:
        float | None: Rounded sample value, or None if unmeasured.
    """
    if not values:
        return None
    values = sorted(values)
    return round(
        values[max(0, math.ceil(len(values) * percentile / 100) - 1)],
        3,
    )


class CataloguePerformanceWindow:
    """Retain bounded catalogue phases with resettable per-pass provenance."""

    def __init__(self) -> None:
        """Create a passive recorder without launching threads or file writes.

        Returns:
            None: Empty measurements with no claimed fresh authorization.
        """
        self._lock = threading.Lock()
        self._samples: deque[_CataloguePhaseSample] = deque(
            maxlen=CATALOGUE_PERFORMANCE_SAMPLE_LIMIT,
        )
        self._cache_source = 'unknown'
        self._transport_source = 'pending'
        self._fallback_reason = 'none'
        self._confirmed_rows = 0
        self._completed_pages = 0
        self._pass_started: float | None = None
        self._milestones: dict[str, float] = {}

    def begin_pass(self, *, at_monotonic: float | None = None) -> None:
        """Begin one local startup timeline while retaining bounded history.

        Args:
            at_monotonic (float | None): Optional deterministic monotonic time.

        Returns:
            None: Current-pass route and first-once timings reset atomically.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if type(now) not in (int, float) or not math.isfinite(now) or now < 0:
            raise ValueError('Invalid monotonic catalogue-pass start.')
        with self._lock:
            self._cache_source = 'unknown'
            self._transport_source = 'pending'
            self._fallback_reason = 'none'
            self._confirmed_rows = 0
            self._completed_pages = 0
            self._pass_started = float(now)
            self._milestones.clear()

    def mark_milestone(
        self, stage: str, *, at_monotonic: float | None = None,
    ) -> bool:
        """Retain the first valid fixed milestone of the current pass.

        Args:
            stage (str): Fixed Tk-source or thumbnail presentation milestone.
            at_monotonic (float | None): Optional deterministic monotonic time.

        Returns:
            bool: True exactly once per stage and started catalogue pass.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if (type(stage) is not str
                or stage not in CATALOGUE_STARTUP_MILESTONES
                or type(now) not in (int, float)
                or not math.isfinite(now) or now < 0):
            return False
        with self._lock:
            started = self._pass_started
            if (started is None or stage in self._milestones
                    or float(now) < started
                    or float(now) - started > 600.0):
                return False
            self._milestones[stage] = round(
                (float(now) - started) * 1000.0, 3,
            )
        return True

    def _expire(self, now: float) -> None:
        """Remove expired observations under the owning lock.

        Args:
            now (float): Monotonic seconds of this observer or snapshot.

        Returns:
            None: Only samples older than the rolling interval are dropped.
        """
        cutoff = now - CATALOGUE_PERFORMANCE_WINDOW_SECONDS
        while self._samples and self._samples[0].observed_at < cutoff:
            self._samples.popleft()

    def observe(
        self, stage: str, duration_ms: float | None = None, *,
        rows: int = 0, reason: str = 'none',
        at_monotonic: float | None = None,
    ) -> bool:
        """Accept one strict, constant-vocabulary catalogue observation.

        Args:
            stage (str): Known cache, fresh tree, page or Tk-mount phase.
            duration_ms (float | None): Actual measured time or unknown.
            rows (int): Non-negative count of processed gallery rows.
            reason (str): Fixed fallback cause, never an exception message.
            at_monotonic (float | None): Injected time for deterministic tests.

        Returns:
            bool: True if the bounded, privacy-safe sample was accepted.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if (type(now) not in (int, float) or not math.isfinite(now) or now < 0
                or type(stage) is not str
                or stage not in _CATALOGUE_PERFORMANCE_STAGES
                or type(reason) is not str
                or reason not in _CATALOGUE_FALLBACK_REASONS
                or (stage != 'paged_fallback' and reason != 'none')
                or (duration_ms is not None and (
                    type(duration_ms) not in (int, float)
                    or not math.isfinite(duration_ms)
                    or not 0 <= duration_ms <= CATALOGUE_PERFORMANCE_MAX_PHASE_MS
                ))
                or type(rows) is not int or not 0 <= rows <= 10_000):
            return False
        record = _CataloguePhaseSample(
            float(now), stage,
            float(duration_ms) if duration_ms is not None else None, rows,
        )
        with self._lock:
            self._expire(float(now))
            self._samples.append(record)
            if stage.startswith('cache_'):
                self._cache_source = stage.removeprefix('cache_')
            elif stage == 'tree_full':
                self._transport_source = 'tree-v1-full'
                self._confirmed_rows = rows
                self._completed_pages = 0
                self._fallback_reason = 'none'
            elif stage == 'tree_unchanged':
                self._transport_source = 'tree-v1-unchanged'
                self._confirmed_rows = rows
                self._completed_pages = 0
                self._fallback_reason = 'none'
            elif stage == 'paged_fallback':
                self._transport_source = 'paged-fallback'
                self._fallback_reason = reason
            elif stage == 'page_ok':
                if self._transport_source == 'pending':
                    self._transport_source = 'paged-fallback'
                self._completed_pages += 1
                self._confirmed_rows += rows
        return True

    def snapshot(
        self, *, at_monotonic: float | None = None,
    ) -> CataloguePerformanceSnapshot:
        """Return one atomic bounded sample view and the latest pass path.

        Args:
            at_monotonic (float | None): Optional monotonic controlled time.

        Returns:
            CataloguePerformanceSnapshot: Fixed labels, counts and percentiles.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if type(now) not in (int, float) or not math.isfinite(now) or now < 0:
            raise ValueError('Invalid monotonic diagnostic time.')
        with self._lock:
            self._expire(float(now))
            samples = tuple(self._samples)
            cache_source = self._cache_source
            transport_source = self._transport_source
            fallback_reason = self._fallback_reason
            confirmed_rows = self._confirmed_rows
            completed_pages = self._completed_pages
            milestones = tuple(
                (key, self._milestones[key])
                for key in CATALOGUE_STARTUP_MILESTONES
                if key in self._milestones
            )
        milestone_values = dict(milestones)
        fresh = milestone_values.get('fresh_rows_confirmed')
        thumb = milestone_values.get('first_thumbnail_visible')
        fresh_to_thumbnail = (
            round(thumb - fresh, 3)
            if fresh is not None and thumb is not None and thumb >= fresh
            else None
        )
        viewport = milestone_values.get('first_viewport_settled')
        fresh_to_viewport = (
            round(viewport - fresh, 3)
            if fresh is not None and viewport is not None and viewport >= fresh
            else None
        )
        grouped: dict[str, list[_CataloguePhaseSample]] = defaultdict(list)
        for sample in samples:
            grouped[sample.stage].append(sample)
        phases = []
        for stage, entries in sorted(grouped.items()):
            durations = [
                entry.duration_ms for entry in entries
                if entry.duration_ms is not None
            ]
            phases.append(CataloguePhaseSummary(
                stage, len(entries), len(durations),
                sum(entry.rows for entry in entries),
                _nearest_rank(durations, 50), _nearest_rank(durations, 95),
                _nearest_rank(durations, 99),
            ))
        return CataloguePerformanceSnapshot(
            CATALOGUE_PERFORMANCE_WINDOW_SECONDS, len(samples),
            cache_source, transport_source, fallback_reason,
            confirmed_rows, completed_pages, tuple(phases),
            milestones, fresh_to_thumbnail, fresh_to_viewport,
        )

    def render_summary(
        self, *, at_monotonic: float | None = None,
    ) -> str:
        """Render one identity-free text summary for the Photos support view.

        Args:
            at_monotonic (float | None): Optional test clock value.

        Returns:
            str: Fixed labels, row counts and bounded numeric phase timings.
        """
        snapshot = self.snapshot(at_monotonic=at_monotonic)
        lines = [
            'Photos catalogue performance: '
            f'window_seconds={snapshot.window_seconds} '
            f'events={snapshot.events} '
            f'cache_source={snapshot.cache_source} '
            f'transport_source={snapshot.transport_source} '
            f'fallback_reason={snapshot.fallback_reason} '
            f'confirmed_rows={snapshot.confirmed_rows} '
            f'completed_pages={snapshot.completed_pages}',
        ]
        milestones = dict(snapshot.milestones)
        lines.append(
            'Photos startup: '
            + ' '.join(
                f'{stage}_ms={milestones.get(stage, "unknown")}'
                for stage in CATALOGUE_STARTUP_MILESTONES
            )
            + ' fresh_to_first_thumbnail_ms='
            + (str(snapshot.fresh_to_thumbnail_ms)
               if snapshot.fresh_to_thumbnail_ms is not None else 'unknown')
            + ' fresh_to_first_viewport_settled_ms='
            + (str(snapshot.fresh_to_viewport_settled_ms)
               if snapshot.fresh_to_viewport_settled_ms is not None else 'unknown')
        )
        for stage in snapshot.phases:
            lines.append(
                f'catalogue_phase={stage.stage} samples={stage.samples} '
                f'measured={stage.measured} rows={stage.rows} '
                f'p50_ms={stage.p50_ms if stage.p50_ms is not None else "unknown"} '
                f'p95_ms={stage.p95_ms if stage.p95_ms is not None else "unknown"} '
                f'p99_ms={stage.p99_ms if stage.p99_ms is not None else "unknown"}'
            )
        return '\n'.join(lines)
