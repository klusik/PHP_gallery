# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/passive_server_timing.py
# Module Type: Client Diagnostics
# Purpose: Compare an accepted HTTP response with its opt-in PHP work safely.
# Responsibilities:
#   - Strictly parse the existing, authorized fixed-format Server-Timing header.
#   - Retain only bounded numeric data from one accepted HTTP response.
#   - Refuse to attribute unmeasured time to gateway, network or WEDOS workers.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Strict, identity-free correlation for already authorized HTTP responses."""

from collections import defaultdict, deque
from dataclasses import dataclass
import math
import re
import threading
import time


# Purpose: Mirror the existing PHP A2 maximum phase without altering the server.
# Type: float. Units: milliseconds. Scope: one accepted HTTP response.
# Consumers: parse_server_timing and ServerTimingWindow.observe.
MAX_PASSIVE_SERVER_MS = 600_000.0

# Purpose: Require exactly the six metrics of our own opt-in PHP profiler.
# Type: re.Pattern[str]. Units: fixed response header. Scope: parser.
# Consumers: parse_server_timing. Rationale: Never retain arbitrary headers,
# descriptions, filenames, route parameters, injection or future unknown labels.
_PASSIVE_WIRE = re.compile(
    r'gallery-php;dur=(?P<php>[0-9]{1,6}\.[0-9]{3}), '
    r'gallery-sql;dur=(?P<sql>[0-9]{1,6}\.[0-9]{3});desc="'
    r'n=(?P<queries>[0-9]{1,3});max_ms=(?P<max_sql>[0-9]{1,6}\.[0-9]{3});'
    r'failed=(?P<failed>[0-9]{1,3});cap=(?P<cap>[01])", '
    r'gallery-auth;dur=(?P<auth>[0-9]{1,6}\.[0-9]{3}), '
    r'gallery-tree;dur=(?P<tree>[0-9]{1,6}\.[0-9]{3}), '
    r'gallery-hash;dur=(?P<hash>[0-9]{1,6}\.[0-9]{3});desc="'
    r'bytes=(?P<bytes>[0-9]{1,8})", '
    r'gallery-media;dur=(?P<media>[0-9]{1,6}\.[0-9]{3})'
)

# Purpose: Restrict the diagnostic buffer to recent current-session responses.
# Type: int. Units: monotonic seconds. Scope: one LinkingSession instance.
# Consumers: ServerTimingWindow. Rationale: Match the prior A2 client windows.
SERVER_TIMING_WINDOW_SECONDS = 300

# Purpose: Bound memory without storing a request identifier or any payload.
# Type: int. Units: request observations. Scope: one LinkingSession.
# Consumers: ServerTimingWindow. Rationale: No per-response unbounded log.
SERVER_TIMING_MAX_SAMPLES = 256

# Purpose: Prevent arbitrary endpoint labels from entering copyable statistics.
# Type: frozenset[str]. Units: static route category. Scope: accepted HTTP.
# Consumers: ServerTimingWindow.observe. Rationale: Match current native reads.
_SERVER_TIMING_ROUTES = frozenset((
    'gallery_tree', 'gallery_page', 'media_page',
    'thumbnail_single', 'thumbnail_batch', 'photo_single', 'revision',
))


@dataclass(frozen=True, slots=True)
class PassiveServerTiming:
    """Retain only the established PHP profiler's bounded numeric fields.

    Attributes:
        php_ms (float): PHP profiler interval ending before its response header.
        sql_ms (float): Sum of observed PDO execution intervals.
        sql_count (int): Bounded observed SQL operation count.
        sql_max_ms (float): Largest observed SQL execution interval.
        sql_failed (int): Count of observed failed SQL operations.
        sql_capped (bool): Whether the observer reached its fixed hard cap.
        auth_ms (float): Explicit current-grant and media authority interval.
        tree_ms (float): Tree model, JSON and SHA-256 combined intervals.
        hash_ms (float): SHA-256 bytes calculation interval.
        hash_bytes (int): Count of existing media bytes passed to the hash.
        media_ms (float): Media metadata and approved-file opening interval.
    """

    php_ms: float
    sql_ms: float
    sql_count: int
    sql_max_ms: float
    sql_failed: int
    sql_capped: bool
    auth_ms: float
    tree_ms: float
    hash_ms: float
    hash_bytes: int
    media_ms: float


def parse_server_timing(values: list[str]) -> PassiveServerTiming | None:
    """Accept only one exact, small numeric PHP Server-Timing header.

    Args:
        values (list[str]): Raw repeated header values, not trusted by default.

    Returns:
        PassiveServerTiming | None: Strict numeric facts, or no observation.
    """
    if (type(values) is not list or len(values) != 1
            or type(values[0]) is not str
            or len(values[0]) > 512):
        return None
    match = _PASSIVE_WIRE.fullmatch(values[0])
    if match is None:
        return None
    numbers = {
        name: float(match.group(name))
        for name in ('php', 'sql', 'max_sql', 'auth', 'tree', 'hash', 'media')
    }
    if not all(math.isfinite(value) and 0 <= value <= MAX_PASSIVE_SERVER_MS
               for value in numbers.values()):
        return None
    queries = int(match.group('queries'))
    failures = int(match.group('failed'))
    amount = int(match.group('bytes'))
    if (queries > 512 or failures > queries or amount > 67_108_864):
        return None
    return PassiveServerTiming(
        numbers['php'], numbers['sql'], queries, numbers['max_sql'],
        failures, match.group('cap') == '1', numbers['auth'],
        numbers['tree'], numbers['hash'], amount, numbers['media'],
    )


@dataclass(frozen=True, slots=True)
class _TimingSample:
    """One fixed-category observation without a request identity.

    Attributes:
        when (float): Monotonic seconds of accepted response observation.
        route (str): Allowlisted native operation category.
        timing (PassiveServerTiming | None): Parsed or absent PHP measurements.
        outside_php_ms (float | None): Non-attributed positive header-time delta.
    """

    when: float
    route: str
    timing: PassiveServerTiming | None
    outside_php_ms: float | None


def _percentile(values: list[float], rank: int) -> float | None:
    """Compute one nearest-rank aggregate without unmeasured interpolation.

    Args:
        values (list[float]): Finite, validated observed durations.
        rank (int): Integer percentile from 1 to 100.

    Returns:
        float | None: Rounded measured percentile or unknown without samples.
    """
    if not values:
        return None
    ordered = sorted(values)
    return round(ordered[max(0, math.ceil(len(ordered) * rank / 100) - 1)], 3)


class ServerTimingWindow:
    """Keep only bounded, request-paired safe PHP header observations."""

    def __init__(self) -> None:
        """Construct a session-local recorder without any network activity.

        Returns:
            None: An empty bounded rolling observation buffer.
        """
        self._lock = threading.Lock()
        self._samples: deque[_TimingSample] = deque(
            maxlen=SERVER_TIMING_MAX_SAMPLES,
        )

    def observe(
        self, route: str, timing: PassiveServerTiming | None,
        client_headers_ms: float | None, attempts: int | None, *,
        at_monotonic: float | None = None,
    ) -> bool:
        """Observe one accepted response, including explicitly missing PHP data.

        Args:
            route (str): Fixed route category from the existing WinApp client.
            timing (PassiveServerTiming | None): Strictly parsed same response.
            client_headers_ms (float | None): Time from send to headers,
                excluding DNS/TCP/TLS and preceding session-gate work.
            attempts (int | None): Known actual HTTP sends, or unknown.
            at_monotonic (float | None): Optional test time for deterministic CI.

        Returns:
            bool: Whether this numeric-only observation passed all bounds.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if (type(route) is not str or route not in _SERVER_TIMING_ROUTES
                or type(now) not in (float, int)
                or not math.isfinite(now) or now < 0
                or timing is not None and type(timing) is not PassiveServerTiming
                or attempts is not None and (
                    type(attempts) is not int or not 0 <= attempts <= 2
                ) or client_headers_ms is not None and (
                    type(client_headers_ms) not in (int, float)
                    or not math.isfinite(client_headers_ms)
                    or not 0 <= client_headers_ms <= MAX_PASSIVE_SERVER_MS
                )):
            return False
        outside = None
        if (timing is not None and attempts == 1
                and client_headers_ms is not None
                and client_headers_ms >= timing.php_ms):
            outside = float(client_headers_ms - timing.php_ms)
        sample = _TimingSample(float(now), route, timing, outside)
        with self._lock:
            cutoff = float(now) - SERVER_TIMING_WINDOW_SECONDS
            while self._samples and self._samples[0].when < cutoff:
                self._samples.popleft()
            self._samples.append(sample)
        return True

    def render_summary(self, *, at_monotonic: float | None = None) -> str:
        """Render only fixed routes and finite numeric aggregate comparisons.

        Args:
            at_monotonic (float | None): Optional deterministic test time.

        Returns:
            str: Bounded copyable summary, with missing timings explicit.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if type(now) not in (float, int) or not math.isfinite(now) or now < 0:
            raise ValueError('Invalid monotonic time.')
        with self._lock:
            cutoff = float(now) - SERVER_TIMING_WINDOW_SECONDS
            while self._samples and self._samples[0].when < cutoff:
                self._samples.popleft()
            samples = tuple(self._samples)
        grouped: dict[str, list[_TimingSample]] = defaultdict(list)
        for entry in samples:
            grouped[entry.route].append(entry)
        lines = [
            f'Photos PHP timing comparison: window_seconds=300 '
            f'samples={len(samples)}',
        ]
        for route, entries in sorted(grouped.items()):
            known = [s.timing for s in entries if s.timing is not None]
            php = [s.php_ms for s in known]
            sql = [s.sql_ms for s in known]
            outside = [s.outside_php_ms for s in entries
                       if s.outside_php_ms is not None]
            lines.append(
                f'route={route} requests={len(entries)} '
                f'php_present={len(known)} php_missing={len(entries)-len(known)} '
                f'php_p50_ms={_percentile(php, 50)} '
                f'php_p95_ms={_percentile(php, 95)} '
                f'sql_p95_ms={_percentile(sql, 95)} '
                f'unattributed_to_measured_php_samples={len(outside)} '
                f'unattributed_to_measured_php_p95_ms={_percentile(outside, 95)}'
            )
        return '\n'.join(lines)
