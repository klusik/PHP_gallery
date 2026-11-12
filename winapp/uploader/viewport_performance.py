# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/viewport_performance.py
# Module Type: Windows Client Diagnostics
# Purpose: Measure completed visible Tk thumbnail viewports without new work.
# Responsibilities:
#   - Observe one current visible band at a time, never overscan as visible.
#   - Separate successful Tk images from terminal unavailable placeholders.
#   - Reject superseded bands and retain bounded numeric timing samples.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Bounded, identity-free timing of actual settled visible thumbnail bands."""

from collections import deque
from dataclasses import dataclass
import math
import threading
import time


# Purpose: Match the five-minute passive A2 observation interval.
# Type: int. Units: monotonic seconds. Scope: one native viewer instance.
VIEWPORT_PERFORMANCE_WINDOW_SECONDS = 300

# Purpose: Limit retained settled viewport durations regardless of scrolling.
# Type: int. Units: samples. Scope: one native viewer instance.
VIEWPORT_PERFORMANCE_SAMPLE_LIMIT = 256

# Purpose: Prevent invalid or unbounded viewport counters from entering output.
# Type: int. Units: visible tiles. Scope: one accepted Tk viewport.
VIEWPORT_PERFORMANCE_MAX_VISIBLE = 10_000

# Purpose: Reject impossible or stale elapsed viewport durations.
# Type: float. Units: milliseconds. Scope: one visible band.
VIEWPORT_PERFORMANCE_MAX_MS = 600_000.0


@dataclass(frozen=True, slots=True)
class _SettledViewport:
    """Retain only numeric facts from an entirely resolved visible band.

    :param observed_at: Monotonic time when the Tk band settled.
    :param elapsed_ms: From accepted viewport admission to last outcome.
    :param visible: Count of actually visible tile widgets.
    :param available: Count of successfully attached real Tk images.
    :param unavailable: Count of terminal unavailable placeholders.
    """

    observed_at: float
    elapsed_ms: float
    visible: int
    available: int
    unavailable: int


@dataclass(frozen=True, slots=True)
class ViewportPerformanceSnapshot:
    """Expose fixed bounded counters without gallery or image identities.

    :param window_seconds: Rolling sample retention interval.
    :param settled: Number of retained completed viewport samples.
    :param superseded_lifetime: Number of unfinished bands superseded in session.
    :param current_visible: Number of tiles in the latest admitted visible band.
    :param current_state: Empty, pending, or settled fixed state label.
    :param current_available: Available images, zero until fully settled.
    :param current_unavailable: Terminal placeholders, zero until settled.
    :param p50_ms: Nearest-rank median of completed viewport durations.
    :param p95_ms: Nearest-rank p95 of completed viewport durations.
    :param p99_ms: Nearest-rank p99 of completed viewport durations.
    :param unavailable_samples: Retained completed bands with any unavailable tile.
    """

    window_seconds: int
    settled: int
    superseded_lifetime: int
    current_visible: int
    current_state: str
    current_available: int
    current_unavailable: int
    p50_ms: float | None
    p95_ms: float | None
    p99_ms: float | None
    unavailable_samples: int


def _viewport_percentile(values: list[float], percentile: int) -> float | None:
    """Calculate nearest-rank duration without interpolating missing data.

    :param values: Valid finite elapsed durations in milliseconds.
    :param percentile: Required rank between 1 and 100.
    :return: Measured duration or None if no settled viewport was observed.

    Args:
        values (list[float]): Bounded measured viewport durations.
        percentile (int): Nearest-rank percentile to compute.

    Returns:
        float | None: Actual observed percentile or unknown.
    """
    if not values:
        return None
    ordered = sorted(values)
    return round(ordered[max(0, math.ceil(len(ordered) * percentile / 100) - 1)], 3)


class ViewportPerformanceWindow:
    """Retain only current-band state and capped settled numeric observations."""

    def __init__(self) -> None:
        """Initialize the passive sampler, without polling or worker threads.

        Returns:
            None: Initializes the empty, thread-safe sampler.
        """
        self._lock = threading.Lock()
        self._samples: deque[_SettledViewport] = deque(
            maxlen=VIEWPORT_PERFORMANCE_SAMPLE_LIMIT,
        )
        self._epoch = 0
        self._started: float | None = None
        self._visible = 0
        self._settled = False
        self._available = 0
        self._unavailable = 0
        self._superseded = 0

    def begin(
        self, visible: int, *, at_monotonic: float | None = None,
    ) -> int | None:
        """Admit a newly computed visible tile band on Tk's owning thread.

        :param visible: Exact number of current visible indices, not overscan.
        :param at_monotonic: Optional deterministic test clock.
        :return: Opaque local epoch or None if the observation was invalid.

        Args:
            visible (int): Number of currently visible Tk tile indices.
            at_monotonic (float | None): Optional deterministic clock.

        Returns:
            int | None: Current epoch token or invalid observation.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if (type(visible) is not int or not 0 <= visible
                <= VIEWPORT_PERFORMANCE_MAX_VISIBLE
                or type(now) not in (int, float)
                or not math.isfinite(now) or now < 0):
            return None
        with self._lock:
            if self._visible and self._started is not None and not self._settled:
                self._superseded += 1
            self._epoch += 1
            self._visible = visible
            self._started = float(now) if visible else None
            self._settled = False
            self._available = 0
            self._unavailable = 0
            return self._epoch

    def settle(
        self, epoch: int, available: int, unavailable: int, *,
        at_monotonic: float | None = None,
    ) -> bool:
        """Accept once only when all tiles in the current band have outcomes.

        :param epoch: Exact current viewport admission token.
        :param available: Count of successfully bound non-placeholder Tk images.
        :param unavailable: Count of fully resolved unavailable placeholders.
        :param at_monotonic: Optional deterministic test clock.
        :return: True only for a newly complete, current, valid visible band.

        Args:
            epoch (int): Token from begin for the current band.
            available (int): Successfully bound real Tk images.
            unavailable (int): Terminal unavailable placeholder count.
            at_monotonic (float | None): Optional deterministic clock.

        Returns:
            bool: Whether this complete band was accepted exactly once.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if (type(epoch) is not int or type(available) is not int
                or type(unavailable) is not int
                or available < 0 or unavailable < 0
                or type(now) not in (int, float)
                or not math.isfinite(now) or now < 0):
            return False
        with self._lock:
            started = self._started
            if (epoch != self._epoch or self._settled
                    or started is None or not self._visible
                    or available + unavailable != self._visible):
                return False
            elapsed_ms = (float(now) - started) * 1000.0
            if not 0 <= elapsed_ms <= VIEWPORT_PERFORMANCE_MAX_MS:
                return False
            self._settled = True
            self._available = available
            self._unavailable = unavailable
            self._expire(float(now))
            self._samples.append(_SettledViewport(
                float(now), round(elapsed_ms, 3),
                self._visible, available, unavailable,
            ))
            return True

    def _expire(self, now: float) -> None:
        """Remove samples outside the five-minute window under the lock.

        :param now: Monotonic time for sample expiry.

        Args:
            now (float): Monotonic time for sample expiry.

        Returns:
            None: Old samples are removed under the lock.
        """
        cutoff = now - VIEWPORT_PERFORMANCE_WINDOW_SECONDS
        while self._samples and self._samples[0].observed_at < cutoff:
            self._samples.popleft()

    def snapshot(
        self, *, at_monotonic: float | None = None,
    ) -> ViewportPerformanceSnapshot:
        """Return bounded measurements, with pending bands explicitly pending.

        :param at_monotonic: Optional deterministic test clock.
        :return: Immutable aggregate containing no image indices or identifiers.

        Args:
            at_monotonic (float | None): Optional deterministic clock.

        Returns:
            ViewportPerformanceSnapshot: Identity-free numeric counts and timings.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if type(now) not in (int, float) or not math.isfinite(now) or now < 0:
            raise ValueError('Invalid monotonic viewport observation time.')
        with self._lock:
            self._expire(float(now))
            samples = tuple(self._samples)
            state = (
                'empty' if self._visible == 0
                else 'settled' if self._settled else 'pending'
            )
            current_visible = self._visible
            current_available = self._available
            current_unavailable = self._unavailable
            superseded = self._superseded
        durations = [sample.elapsed_ms for sample in samples]
        return ViewportPerformanceSnapshot(
            VIEWPORT_PERFORMANCE_WINDOW_SECONDS, len(samples),
            superseded, current_visible, state,
            current_available, current_unavailable,
            _viewport_percentile(durations, 50),
            _viewport_percentile(durations, 95),
            _viewport_percentile(durations, 99),
            sum(sample.unavailable > 0 for sample in samples),
        )

    def render_summary(self, *, at_monotonic: float | None = None) -> str:
        """Render a fixed numeric support line, without raw user data.

        :param at_monotonic: Optional deterministic test clock.
        :return: Safe copyable display of completed viewport outcomes.

        Args:
            at_monotonic (float | None): Optional deterministic clock.

        Returns:
            str: Privacy-safe fixed-format diagnostic line.
        """
        snapshot = self.snapshot(at_monotonic=at_monotonic)
        return (
            'Photos viewport performance: '
            f'window_seconds={snapshot.window_seconds} '
            f'settled={snapshot.settled} '
            f'superseded_lifetime={snapshot.superseded_lifetime} '
            f'current_visible={snapshot.current_visible} '
            f'current_state={snapshot.current_state} '
            f'current_available={snapshot.current_available} '
            f'current_unavailable={snapshot.current_unavailable} '
            f'unavailable_samples={snapshot.unavailable_samples} '
            f'p50_ms={snapshot.p50_ms if snapshot.p50_ms is not None else "unknown"} '
            f'p95_ms={snapshot.p95_ms if snapshot.p95_ms is not None else "unknown"} '
            f'p99_ms={snapshot.p99_ms if snapshot.p99_ms is not None else "unknown"}'
        )
