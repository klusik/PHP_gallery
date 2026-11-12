# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos_performance.py
# Module Type: Windows Client Diagnostics
# Purpose: Retain bounded, identity-free thumbnail read timing distributions.
# Responsibilities:
#   - Record existing thumbnail transport phases without initiating HTTP work.
#   - Keep a strict rolling observation window and finite sample capacity.
#   - Format privacy-safe window metrics for the existing Photos diagnostics.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Passive rolling metrics for the existing single-thumbnail read path."""

from collections import deque
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
import math
import threading
import time

from .app_linking import MediaReadTimings
from .thumbnail_batch_protocol import PRODUCTION_MAX_ITEMS, PRODUCTION_MAX_RESPONSE_BYTES


# Purpose: Bound the elapsed observation period independently of viewer lifetime.
# Type: int. Units: monotonic seconds. Scope: one native Photos viewer.
# Consumers: ThumbnailPerformanceWindow. Rationale: Five minutes is sufficient
# to compare a cold viewport with its immediate warm and refresh operations.
THUMBNAIL_PERFORMANCE_WINDOW_SECONDS = 300

# Purpose: Bound retained individual read metrics without unbounded history.
# Type: int. Units: read observations. Scope: one native Photos viewer.
# Consumers: ThumbnailPerformanceWindow. Rationale: 256 covers a representative
# 120-photo cold and warm pass while keeping memory independent of session age.
THUMBNAIL_PERFORMANCE_SAMPLE_LIMIT = 256

# Purpose: Reject malformed or non-finite phase measurements rather than emit
# misleading percentiles or unsanitized diagnostic text.
# Type: float. Units: milliseconds. Scope: one observed thumbnail read.
# Consumers: ThumbnailPerformanceWindow.observe. Rationale: Ten minutes exceeds
# the normal request deadline while enforcing a fixed numeric upper bound.
THUMBNAIL_PERFORMANCE_MAX_PHASE_MS = 600_000.0

# Purpose: Bound actual sends retained for one mixed batch/exceptional group.
# Type: int. Units: HTTP attempts. Scope: one batch physical observation.
# Consumers: ThumbnailBatchPhysicalWindow.observe. Rationale: Three attempts
# per maximum item covers two batch attempts and one exceptional single read.
THUMBNAIL_BATCH_PHYSICAL_MAX_ATTEMPTS = 3 * PRODUCTION_MAX_ITEMS

# Purpose: Bound body accounting for one mixed batch/exceptional group.
# Type: int. Units: bytes. Scope: one batch physical observation.
# Consumers: ThumbnailBatchPhysicalWindow.observe. Rationale: Four aggregate
# wire budgets per maximum item cover retries and bounded original GET bodies.
THUMBNAIL_BATCH_PHYSICAL_MAX_BYTES = (
    4 * PRODUCTION_MAX_ITEMS * PRODUCTION_MAX_RESPONSE_BYTES
)

# Purpose: Avoid presenting a tail percentile from a tiny physical sample.
# Type: int. Units: single-send observations. Scope: batch physical p95.
# Consumers: ThumbnailBatchPhysicalWindow.render_summary. Rationale: One
# hundred observations provide at least several samples in the upper tail.
THUMBNAIL_BATCH_TAIL_MIN_SAMPLES = 100

# Purpose: Accept only fixed, identity-free read outcomes in exported metrics.
# Type: frozenset[str]. Units: fixed outcome codes. Scope: thumbnail reads.
# Consumers: ThumbnailPerformanceWindow.observe. Rationale: Never retain
# arbitrary exception text, response metadata, image IDs or gallery names.
THUMBNAIL_PERFORMANCE_SOURCES = frozenset((
    'unknown', 'disk', 'network', 'revalidated',
))


@dataclass(frozen=True, slots=True)
class _ThumbnailPerformanceSample:
    """Retain only fixed numeric facts and one allowlisted outcome.

    :param observed_at: Monotonic seconds at receipt by the observer.
    :param source: Fixed disk, network, revalidated or unknown outcome.
    :param requests: Physical HTTP send attempts for this one read operation.
    :param received_bytes: Body bytes counted by the existing media transport.
    :param gate_ms: Time waiting for the shared session gate.
    :param slot_ms: Time waiting for the process HTTP transport slot.
    :param network_ms: Measured media transport phase, not full viewer latency.
    :param ttfb_ms: Accumulated send-to-header duration for HTTP attempts.
    :param body_ms: Response validation and body-read duration.
    :param decode_ms: Actual thumbnail decoder work.
    """

    observed_at: float
    source: str
    requests: int
    received_bytes: int
    gate_ms: float
    slot_ms: float
    network_ms: float
    ttfb_ms: float
    body_ms: float
    decode_ms: float


def _phase_valid(value: int | float) -> bool:
    """Accept one bounded, non-negative, finite millisecond value.

    Args:
        value (int | float): Candidate numeric phase.

    Returns:
        bool: Whether the value can safely enter a diagnostic percentile.
    """
    return (
        type(value) in (int, float)
        and math.isfinite(value)
        and 0 <= value <= THUMBNAIL_PERFORMANCE_MAX_PHASE_MS
    )


def _percentile(values: list[float], percentile: int) -> float | None:
    """Calculate a bounded nearest-rank percentile without interpolation.

    Args:
        values (list[float]): Already validated finite measured milliseconds.
        percentile (int): Requested percentile between 1 and 100.

    Returns:
        float | None: Rounded milliseconds, or None when no samples exist.
    """
    if not values:
        return None
    ordered = sorted(values)
    index = max(0, math.ceil(len(ordered) * percentile / 100) - 1)
    return round(ordered[index], 3)


class ThumbnailPerformanceWindow:
    """Thread-safe fixed-capacity statistics for genuine thumbnail read work."""

    def __init__(self) -> None:
        """Initialize a session-local, non-persistent diagnostic buffer.

        Returns:
            None: Retains no timing data until a real read is observed.
        """
        self._lock = threading.Lock()
        self._samples: deque[_ThumbnailPerformanceSample] = deque(
            maxlen=THUMBNAIL_PERFORMANCE_SAMPLE_LIMIT,
        )

    def _expire(self, now: float) -> None:
        """Expire old observations while the owning lock is held.

        Args:
            now (float): Monotonic observation time.

        Returns:
            None: Removes only samples outside the fixed window.
        """
        cutoff = now - THUMBNAIL_PERFORMANCE_WINDOW_SECONDS
        while self._samples and self._samples[0].observed_at < cutoff:
            self._samples.popleft()

    def observe(
        self, timings: MediaReadTimings, decode_ms: float, *,
        at_monotonic: float | None = None,
    ) -> bool:
        """Retain bounded existing counters without touching transport or Tk.

        Args:
            timings (MediaReadTimings): Caller-owned observed read timing facts.
            decode_ms (float): Actual thumbnail decoder time in milliseconds.
            at_monotonic (float | None): Optional injected monotonic test time.

        Returns:
            bool: True only when a sanitized sample was admitted.
        """
        if not isinstance(timings, MediaReadTimings):
            return False
        now = time.monotonic() if at_monotonic is None else at_monotonic
        phases = (
            timings.gate_wait_ms, timings.slot_wait_ms,
            timings.network_ms, timings.ttfb_ms, timings.body_ms, decode_ms,
        )
        if (type(now) not in (int, float)
                or not math.isfinite(now) or now < 0):
            return False
        if (timings.source not in THUMBNAIL_PERFORMANCE_SOURCES
                or type(timings.http_requests) is not int
                or not 0 <= timings.http_requests <= 8
                or type(timings.encoded_bytes) is not int
                or not 0 <= timings.encoded_bytes <= 128 * 1024 * 1024
                or not all(_phase_valid(phase) for phase in phases)):
            return False
        sample = _ThumbnailPerformanceSample(
            observed_at=float(now), source=timings.source,
            requests=timings.http_requests,
            received_bytes=timings.encoded_bytes,
            gate_ms=float(timings.gate_wait_ms),
            slot_ms=float(timings.slot_wait_ms),
            network_ms=float(timings.network_ms),
            ttfb_ms=float(timings.ttfb_ms),
            body_ms=float(timings.body_ms),
            decode_ms=float(decode_ms),
        )
        with self._lock:
            self._expire(sample.observed_at)
            self._samples.append(sample)
        return True

    def snapshot(
        self, *, at_monotonic: float | None = None,
    ) -> dict[str, int | float | str | None]:
        """Return a privacy-safe, rolling-window counter and percentile view.

        Args:
            at_monotonic (float | None): Optional deterministic monotonic time.

        Returns:
            dict[str, int | float | str | None]: Fixed safe numeric metrics,
                UTC interval bounds and single-request timing percentiles.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if type(now) not in (int, float) or not math.isfinite(now) or now < 0:
            raise ValueError('Invalid monotonic diagnostic timestamp.')
        with self._lock:
            self._expire(float(now))
            samples = tuple(self._samples)

        end_utc = datetime.now(timezone.utc)
        beginning_utc = end_utc - timedelta(
            seconds=THUMBNAIL_PERFORMANCE_WINDOW_SECONDS,
        )
        singles = [s for s in samples if s.requests == 1]
        result: dict[str, int | float | str | None] = {
            'route': 'thumbnail_single',
            'window_seconds': THUMBNAIL_PERFORMANCE_WINDOW_SECONDS,
            'window_start_utc': beginning_utc.isoformat(timespec='seconds'),
            'window_end_utc': end_utc.isoformat(timespec='seconds'),
            'sampled_reads': len(samples),
            'http_attempts': sum(s.requests for s in samples),
            'received_bytes': sum(s.received_bytes for s in samples),
            'source_disk': sum(s.source == 'disk' for s in samples),
            'source_network': sum(s.source == 'network' for s in samples),
            'source_revalidated': sum(
                s.source == 'revalidated' for s in samples
            ),
            'source_unknown': sum(s.source == 'unknown' for s in samples),
            'single_http_samples': len(singles),
            'multiple_http_samples': sum(s.requests > 1 for s in samples),
        }
        phases = {
            'ttfb': [s.ttfb_ms for s in singles],
            'network': [s.network_ms for s in singles],
            'gate': [s.gate_ms for s in samples],
            'slot': [s.slot_ms for s in samples],
            'decode': [s.decode_ms for s in samples],
        }
        for name, values in phases.items():
            for quantile in (50, 95, 99):
                result[f'{name}_p{quantile}_ms'] = _percentile(
                    values, quantile,
                )
        return result

    def render_summary(
        self, *, at_monotonic: float | None = None,
    ) -> str:
        """Format an identity-free line for the existing Photos copyable view.

        Args:
            at_monotonic (float | None): Optional deterministic test timestamp.

        Returns:
            str: Fixed labels and numeric measurements, never request details.
        """
        data = self.snapshot(at_monotonic=at_monotonic)
        parts = [
            f'{key}={value if value is not None else "unknown"}'
            for key, value in data.items()
        ]
        return 'Photos thumbnail performance: ' + ' '.join(parts)


class ThumbnailBatchPhysicalWindow:
    """Retain one bounded physical observation per batch API operation."""

    def __init__(self) -> None:
        """Initialize a separate numeric-only rolling transport window.

        Returns:
            None: No per-tile samples or HTTP observations are synthesized.
        """
        self._lock = threading.Lock()
        self._samples: deque[tuple[float, int, int, float, float]] = deque(
            maxlen=THUMBNAIL_PERFORMANCE_SAMPLE_LIMIT,
        )

    def observe(self, timings: MediaReadTimings) -> bool:
        """Record actual complete-call sends and timing once per operation.

        Args:
            timings (MediaReadTimings): Aggregate completed physical metrics.

        Returns:
            bool: True only when the fixed numeric bounds admit the sample.
        """
        if (not isinstance(timings, MediaReadTimings)
                or type(timings.http_requests) is not int
                or not 0 <= timings.http_requests <= THUMBNAIL_BATCH_PHYSICAL_MAX_ATTEMPTS
                or type(timings.encoded_bytes) is not int
                or not 0 <= timings.encoded_bytes <= THUMBNAIL_BATCH_PHYSICAL_MAX_BYTES
                or not _phase_valid(timings.ttfb_ms)
                or not _phase_valid(timings.network_ms)):
            return False
        now = time.monotonic()
        with self._lock:
            cutoff = now - THUMBNAIL_PERFORMANCE_WINDOW_SECONDS
            while self._samples and self._samples[0][0] < cutoff:
                self._samples.popleft()
            self._samples.append((
                now, timings.http_requests, timings.encoded_bytes,
                float(timings.ttfb_ms), float(timings.network_ms),
            ))
        return True

    def render_summary(self) -> str:
        """Render bounded aggregate sends and single-request timing only.

        Returns:
            str: Fixed route, numeric counts and nullable single-send p95 values.
        """
        now = time.monotonic()
        with self._lock:
            cutoff = now - THUMBNAIL_PERFORMANCE_WINDOW_SECONDS
            while self._samples and self._samples[0][0] < cutoff:
                self._samples.popleft()
            samples = tuple(self._samples)
        singles = [sample for sample in samples if sample[1] == 1]
        ttfb_values = [sample[3] for sample in singles]
        network_values = [sample[4] for sample in singles]
        first_byte = (_percentile(ttfb_values, 95)
                      if len(singles) >= THUMBNAIL_BATCH_TAIL_MIN_SAMPLES else None)
        network = (_percentile(network_values, 95)
                   if len(singles) >= THUMBNAIL_BATCH_TAIL_MIN_SAMPLES else None)
        median = _percentile(ttfb_values, 50)
        maximum = max(ttfb_values, default=None)
        end_utc = datetime.now(timezone.utc)
        start_utc = end_utc - timedelta(seconds=THUMBNAIL_PERFORMANCE_WINDOW_SECONDS)
        return (
            'Photos batch physical: route=thumbnail_batch '
            f'operations={len(samples)} http_attempts={sum(item[1] for item in samples)} '
            f'window_seconds={THUMBNAIL_PERFORMANCE_WINDOW_SECONDS} '
            f'window_start_utc={start_utc.isoformat(timespec="seconds")} '
            f'window_end_utc={end_utc.isoformat(timespec="seconds")} '
            f'received_bytes={sum(item[2] for item in samples)} '
            f'single_http_samples={len(singles)} '
            f'multiple_http_samples={sum(item[1] > 1 for item in samples)} '
            f'tail_min_samples={THUMBNAIL_BATCH_TAIL_MIN_SAMPLES} '
            f'ttfb_median_ms={median if median is not None else "unknown"} '
            f'ttfb_max_ms={maximum if maximum is not None else "unknown"} '
            f'ttfb_p95_ms={first_byte if first_byte is not None else "unknown"} '
            f'network_p95_ms={network if network is not None else "unknown"}'
        )
