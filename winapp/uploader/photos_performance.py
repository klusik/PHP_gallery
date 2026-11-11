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
