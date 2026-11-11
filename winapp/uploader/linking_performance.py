# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking_performance.py
# Module Type: Windows Client Diagnostics
# Purpose: Retain bounded, privacy-safe linking route outcomes.
# Responsibilities:
#   - Reduce authorized client transport outcomes to fixed route/reason codes.
#   - Keep numeric status, elapsed and known HTTP send counts in a rolling window.
#   - Export bounded per-route counts without issuer, identity or request data.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Passive route-level diagnostics independent of linking transport decisions."""

from collections import Counter, defaultdict, deque
from dataclasses import dataclass
import math
import threading
import time


# Purpose: Bound route observations independently of a linked session lifetime.
# Type: int. Units: seconds. Scope: one linked client session's diagnostics.
# Consumers: LinkingRoutePerformanceWindow. Rationale: Matches A2.1 thumbnail
# windows while including one representative cold and warm viewport.
LINKING_ROUTE_WINDOW_SECONDS = 300

# Purpose: Bound retained per-operation records for any linked installation.
# Type: int. Units: observations. Scope: one linked client session.
# Consumers: LinkingRoutePerformanceWindow. Rationale: Covers 120 thumbnails
# plus adjacent metadata without unbounded per-request logging.
LINKING_ROUTE_SAMPLE_LIMIT = 256

# Purpose: Refuse unbounded, corrupted or non-finite diagnostic elapsed time.
# Type: float. Units: milliseconds. Scope: one completed transport operation.
# Consumers: LinkingRoutePerformanceWindow.observe. Rationale: Ten minutes
# exceeds regular read deadlines without accepting arbitrary numeric payloads.
LINKING_ROUTE_MAX_ELAPSED_MS = 600_000.0

# Purpose: Only known internal routes may appear in client diagnostic output.
# Type: dict[str, str]. Units: static route categories. Scope: JSON linking.
# Consumers: linking_route_category. Rationale: Never export a request URL,
# query string, installation address, user-controlled path or identifier.
_JSON_ROUTE_CATEGORIES = {
    'app_linking_identity': 'identity',
    'app_linking_revoke': 'revoke',
    'app_linking_galleries': 'gallery_page',
    'app_linking_gallery_tree': 'gallery_tree',
    'app_linking_inventory': 'inventory',
    'app_linking_upload': 'upload',
    'app_linking_gallery_detail': 'gallery_detail',
    'app_linking_gallery_create': 'gallery_create',
    'app_linking_gallery_create_status': 'gallery_create_status',
    'app_linking_gallery_create_abandon': 'gallery_create_abandon',
    'app_linking_gallery_visibility': 'gallery_visibility',
    'app_linking_photo_visibility': 'photo_visibility',
    'app_linking_media_delete': 'media_delete',
}

# Purpose: Distinguish physical media request kinds without parsing raw URLs.
# Type: dict[str, str]. Units: static media-kind categories. Scope: media GETs.
# Consumers: linking_route_category. Rationale: A GUI thumbnail batch is not
# the real single-thumbnail HTTP request observed at the session boundary.
_MEDIA_KIND_CATEGORIES = {
    'photos': 'media_page',
    'thumbnail': 'thumbnail_single',
    'photo': 'photo_single',
    'revision': 'revision',
    'presentation': 'presentation',
    'track': 'track',
    'info': 'media_info',
    'gallery_info': 'media_gallery_info',
    'photo_info': 'media_photo_info',
}

# Purpose: Preserve the transport's fixed safe error reasons, not exceptions.
# Type: frozenset[str]. Units: diagnostic reason codes. Scope: one operation.
# Consumers: LinkingRoutePerformanceWindow.observe. Rationale: No raw exception
# string, response body, SQL text or access credential is ever retained.
LINKING_ROUTE_REASONS = frozenset((
    'ok', 'unknown', 'http_status', 'content_type',
    'response_policy', 'response_size', 'invalid_json', 'invalid_shape',
    'dns_failure', 'connect_failure', 'tls_failure', 'timeout',
    'transport_failure', 'throttled', 'grant_rejected',
    'refresh_rejected', 'target_unavailable', 'revision_unsupported',
))


def linking_route_category(
    route: str, media_kind: str | None = None,
) -> str | None:
    """Resolve only known protocol operation selectors to fixed categories.

    Args:
        route (str): Internally owned canonical route, never an untrusted URL.
        media_kind (str | None): Fixed media selector or None for JSON routes.

    Returns:
        str | None: Allowlisted category, or None for an unknown combination.
    """
    if type(route) is not str:
        return None
    if route == 'app_linking_media':
        return (_MEDIA_KIND_CATEGORIES.get(media_kind)
                if type(media_kind) is str else None)
    if media_kind is not None:
        return None
    return _JSON_ROUTE_CATEGORIES.get(route)


# Purpose: Export only predetermined categories to the summary reducer.
# Type: frozenset[str]. Units: fixed labels. Scope: one snapshot.
# Consumers: LinkingRoutePerformanceWindow.observe. Rationale: Validate
# categories a second time even if the caller bypasses the route mapper.
_LINKING_ROUTE_LABELS = frozenset((
    *_JSON_ROUTE_CATEGORIES.values(), *_MEDIA_KIND_CATEGORIES.values(),
))


@dataclass(frozen=True, slots=True)
class _RouteObservation:
    """Store one fixed route, status, reason and bounded monotonic duration.

    Attributes:
        observed_at (float): Monotonic seconds when this operation completed.
        route (str): Safe, fixed category, never an HTTP path or URL.
        status (int | None): Actual known HTTP status, otherwise None.
        reason (str): Allowlisted outcome label, never exception text.
        elapsed_ms (float): End-to-end interval for the observed owner scope.
        attempts (int | None): Known physical HTTP sends or unknown.
    """

    observed_at: float
    route: str
    status: int | None
    reason: str
    elapsed_ms: float
    attempts: int | None


@dataclass(frozen=True, slots=True)
class RoutePerformanceSummary:
    """Immutable per-route diagnostic aggregate without operation identity.

    Attributes:
        route (str): One fixed operation category.
        operations (int): Count of retained completed client operations.
        known_http_attempts (int): Physical sends with validated known counts.
        unknown_attempt_operations (int): Operations without reliable send count.
        statuses (tuple[tuple[str, int], ...]): Known numeric statuses or none.
        reasons (tuple[tuple[str, int], ...]): Fixed outcome categories and counts.
        elapsed_p50_ms (float | None): Nearest-rank median of local elapsed time.
        elapsed_p95_ms (float | None): Nearest-rank p95 of local elapsed time.
        elapsed_p99_ms (float | None): Nearest-rank p99 of local elapsed time.
    """

    route: str
    operations: int
    known_http_attempts: int
    unknown_attempt_operations: int
    statuses: tuple[tuple[str, int], ...]
    reasons: tuple[tuple[str, int], ...]
    elapsed_p50_ms: float | None
    elapsed_p95_ms: float | None
    elapsed_p99_ms: float | None


@dataclass(frozen=True, slots=True)
class RoutePerformanceSnapshot:
    """Bounded aggregate for the trailing monotonic observation window.

    Attributes:
        window_seconds (int): Fixed trailing observation interval.
        sampled_operations (int): Retained, completed client operations.
        routes (tuple[RoutePerformanceSummary, ...]): Fixed labeled summaries.
    """

    window_seconds: int
    sampled_operations: int
    routes: tuple[RoutePerformanceSummary, ...]


def _nearest_rank(values: list[float], percentile: int) -> float | None:
    """Calculate a nearest-rank elapsed-time percentile.

    Args:
        values (list[float]): Positive finite measured milliseconds.
        percentile (int): Whole percentile within 1 through 100.

    Returns:
        float | None: Rounded measured percentile or None without samples.
    """
    if not values:
        return None
    ordered = sorted(values)
    return round(
        ordered[max(0, math.ceil(percentile * len(ordered) / 100) - 1)],
        3,
    )


class LinkingRoutePerformanceWindow:
    """Retain only bounded, passive operation outcomes for one linked session."""

    def __init__(self) -> None:
        """Create the recorder without starting threads, I/O or a timer.

        Returns:
            None: An empty, session-owned observation window.
        """
        self._lock = threading.Lock()
        self._samples: deque[_RouteObservation] = deque(
            maxlen=LINKING_ROUTE_SAMPLE_LIMIT,
        )

    def _expire(self, now: float) -> None:
        """Discard old entries with the owning lock already acquired.

        Args:
            now (float): Valid monotonic time in seconds.

        Returns:
            None: Only out-of-window observations are discarded.
        """
        cutoff = now - LINKING_ROUTE_WINDOW_SECONDS
        while self._samples and self._samples[0].observed_at < cutoff:
            self._samples.popleft()

    def observe(
        self, route: str, status: int | None, reason: str,
        elapsed_ms: float, attempts: int | None, *,
        at_monotonic: float | None = None,
    ) -> bool:
        """Retain one operation only when every field is privacy-safe.

        Args:
            route (str): One fixed category from linking_route_category.
            status (int | None): Observed HTTP status, or unknown.
            reason (str): One fixed outcome reason, never an error message.
            elapsed_ms (float): Duration of the observed client operation.
            attempts (int | None): Known physical sends, or unknown.
            at_monotonic (float | None): Optional controlled test clock.

        Returns:
            bool: Whether this single, bounded sample was accepted.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if (type(now) not in (float, int)
                or not math.isfinite(now) or now < 0
                or type(route) is not str or route not in _LINKING_ROUTE_LABELS
                or type(reason) is not str or reason not in LINKING_ROUTE_REASONS
                or (status is not None and (type(status) is not int
                                            or not 100 <= status <= 599))
                or type(elapsed_ms) not in (float, int)
                or not math.isfinite(elapsed_ms)
                or not 0 <= elapsed_ms <= LINKING_ROUTE_MAX_ELAPSED_MS
                or (attempts is not None and (
                    type(attempts) is not int or not 0 <= attempts <= 2
                ))):
            return False
        sample = _RouteObservation(
            float(now), route, status, reason, float(elapsed_ms), attempts,
        )
        with self._lock:
            self._expire(float(now))
            self._samples.append(sample)
        return True

    def snapshot(
        self, *, at_monotonic: float | None = None,
    ) -> RoutePerformanceSnapshot:
        """Build a complete safe aggregate from one atomic bounded copy.

        Args:
            at_monotonic (float | None): Optional deterministic monotonic time.

        Returns:
            RoutePerformanceSnapshot: Per-route counts and elapsed percentiles.
        """
        now = time.monotonic() if at_monotonic is None else at_monotonic
        if type(now) not in (int, float) or not math.isfinite(now) or now < 0:
            raise ValueError('Invalid monotonic diagnostic timestamp.')
        with self._lock:
            self._expire(float(now))
            samples = tuple(self._samples)
        groups: dict[str, list[_RouteObservation]] = defaultdict(list)
        for sample in samples:
            groups[sample.route].append(sample)
        summaries = []
        for route, entries in sorted(groups.items()):
            statuses = Counter(
                str(entry.status) if entry.status is not None else 'none'
                for entry in entries
            )
            reasons = Counter(entry.reason for entry in entries)
            durations = [entry.elapsed_ms for entry in entries]
            summaries.append(RoutePerformanceSummary(
                route=route, operations=len(entries),
                known_http_attempts=sum(
                    entry.attempts for entry in entries
                    if entry.attempts is not None
                ),
                unknown_attempt_operations=sum(
                    entry.attempts is None for entry in entries
                ),
                statuses=tuple(sorted(statuses.items())),
                reasons=tuple(sorted(reasons.items())),
                elapsed_p50_ms=_nearest_rank(durations, 50),
                elapsed_p95_ms=_nearest_rank(durations, 95),
                elapsed_p99_ms=_nearest_rank(durations, 99),
            ))
        return RoutePerformanceSnapshot(
            LINKING_ROUTE_WINDOW_SECONDS, len(samples), tuple(summaries),
        )

    def render_summary(
        self, *, at_monotonic: float | None = None,
    ) -> str:
        """Render bounded, fixed vocabulary metrics for Photos support text.

        Args:
            at_monotonic (float | None): Optional deterministic test timestamp.

        Returns:
            str: A safe compact diagnostic summary without user-supplied data.
        """
        snapshot = self.snapshot(at_monotonic=at_monotonic)
        lines = [
            'Photos HTTP routes: '
            f'window_seconds={snapshot.window_seconds} '
            f'operations={snapshot.sampled_operations}',
        ]
        for entry in snapshot.routes:
            statuses = ','.join(
                f'{status}:{count}' for status, count in entry.statuses
            )
            reasons = ','.join(
                f'{reason}:{count}' for reason, count in entry.reasons
            )
            lines.append(
                f'route={entry.route} operations={entry.operations} '
                f'http_attempts={entry.known_http_attempts} '
                f'unknown_attempt_operations={entry.unknown_attempt_operations} '
                f'statuses={statuses} reasons={reasons} '
                f'elapsed_p50_ms={entry.elapsed_p50_ms} '
                f'elapsed_p95_ms={entry.elapsed_p95_ms} '
                f'elapsed_p99_ms={entry.elapsed_p99_ms}'
            )
        return '\n'.join(lines)
