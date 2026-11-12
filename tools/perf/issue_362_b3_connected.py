# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_b3_connected.py
# Module Type: Disposable Connected Client Qualification
# Purpose: Measure the production WinApp media client against owned HTTP fixtures.
# Responsibilities:
#   - Bind actual discovery, identity and current media-page proofs.
#   - Compare cold and warm single reads with bounded framed batch groups.
#   - Emit sanitized counts and numeric timing samples only.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Qualify connected thumbnail sessions against an owned disposable server."""

import hashlib
import json
import math
import os
import re
import sys
import time
from pathlib import Path
from statistics import median
from tempfile import TemporaryDirectory
from typing import Callable
from urllib.parse import urlsplit

REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
WINAPP_ROOT = REPOSITORY_ROOT / 'winapp'
if str(WINAPP_ROOT) not in sys.path:
    sys.path.insert(0, str(WINAPP_ROOT))

from uploader import app_linking as linking
from uploader.models import JsonValue

# Purpose: Match the existing disposable B2 synthetic thumbnail corpus.
# Type: int. Units: photos. Scope: one cold/warm connected-client cohort.
# Consumers: bind_media_proofs and run_profile. Rationale: 120 photos retain
# the existing B2 corpus and create 120 true item-level rate charges.
B3_IMAGE_COUNT = 120

# Purpose: Bound catalogue paging when every returned page must make progress.
# Type: int. Units: metadata pages. Scope: proof binding for one cohort.
# Consumers: bind_media_proofs. Rationale: At least one distinct row is
# required per page, so 120 pages is a finite upper bound for 120 selected rows.
B3_METADATA_PAGE_LIMIT = B3_IMAGE_COUNT

# Purpose: Exercise proposed initial and planned production group sizes.
# Type: tuple[int, ...]. Units: photos per POST. Scope: connected batch profiles.
# Consumers: main profile loop. Rationale: 8/16/24/32 cover the proposed
# initial group, planning cohorts and protocol maximum, not installed behavior.
B3_GROUP_SIZES = (8, 16, 24, 32)

# Purpose: Keep the session deadline conservative beneath the owned grant lease.
# Type: float. Units: seconds. Scope: one synthetic connected-client session.
# Consumers: LinkingSession construction. Rationale: Thirty minutes is shorter
# than the PHP fixture's 2400-second bearer lifetime and bounds one run.
B3_SESSION_LIFETIME_SECONDS = 1800.0

# Purpose: Report p95/p99 tails only when a complete cohort supports them.
# Type: tuple[float, float]. Units: percentile fractions.
# Scope: numeric request distributions. Consumers: summarize_samples.
# Rationale: 120 singles support nearest-rank tails; 3-15 batch requests retain
# null tails instead of implying precision.
B3_TAIL_PERCENTILES = (0.95, 0.99)

# Purpose: Keep emitted millisecond metrics stable and compact.
# Type: int. Units: decimal places. Scope: sanitized B3 numeric report.
# Consumers: summarize_samples and summarize_profile_phase. Rationale: Three
# decimal places retain useful sub-millisecond observations without noisy tails.
B3_TIMING_DECIMAL_PLACES = 3

# Purpose: Select stable nearest-rank tails only for a complete cohort.
# Type: int. Units: request samples. Scope: request latency summaries.
# Consumers: summarize_samples. Rationale: Do not infer tail latency from the
# small 3-15 physical-request batch cohorts.
B3_TAIL_SAMPLE_MINIMUM = B3_IMAGE_COUNT


class B3CheckFailure(RuntimeError):
    """Identify one fixed local qualification assertion without raw details.

    Args:
        code (str): Fixed assertion label from the owned client source.
    """

    def __init__(self, code: str) -> None:
        """Store a fixed check code for sanitized failure evidence.

        Args:
            code (str): Fixed source-owned contract check label.

        Returns:
            None: Stores only the static code.
        """
        self.code = code
        super().__init__(code)


def require(condition: bool, stage: str) -> None:
    """Raise a generic failure for a failed owned-client contract.

    Args:
        condition (bool): Exact assertion that must hold.
        stage (str): Fixed safe stage label without user or transport data.

    Returns:
        None: Continues only when the assertion is true.
    """
    if not condition:
        raise B3CheckFailure(stage)


def validate_environment() -> tuple[str, str, int, Path]:
    """Verify owned CI, fixture ownership and loopback-only target inputs.

    Returns:
        tuple[str, str, int, Path]: Issuer, bearer, gallery ID and private
            disposable fixture directory. Secret values remain process-local.

    Raises:
        RuntimeError: Any environment or ownership fence is missing.
    """
    require(os.environ.get('GITHUB_ACTIONS') == 'true', 'ci_guard')
    require(os.environ.get('GALLERY_WORKFLOW_ENABLE') == 'disposable-only',
            'owner_guard')
    issuer = os.environ.get('PHP_GALLERY_B3_ISSUER', '')
    bearer = os.environ.get('PHP_GALLERY_B3_BEARER', '')
    gallery_text = os.environ.get('PHP_GALLERY_B3_GALLERY_ID', '')
    fixture_text = os.environ.get('GALLERY_WORKFLOW_FIXTURE', '')
    owner = os.environ.get('GALLERY_WORKFLOW_TOKEN', '')
    parsed = urlsplit(issuer)
    require(parsed.scheme == 'http' and parsed.hostname == 'localhost'
            and parsed.port is not None and parsed.port > 0
            and parsed.username is None and parsed.password is None
            and not parsed.query and not parsed.fragment,
            'loopback_guard')
    require(re.fullmatch(r'pgla_[A-Za-z0-9_-]{43}', bearer) is not None,
            'bearer_guard')
    require(re.fullmatch(r'[1-9][0-9]{0,18}', gallery_text) is not None,
            'gallery_guard')
    fixture = Path(fixture_text).resolve(strict=True)
    require(fixture.name.startswith('gallery-workflow-') and owner
            and (fixture / '.workflow-owner').read_text(encoding='ascii').strip()
            == owner, 'fixture_owner_guard')
    return issuer, bearer, int(gallery_text), fixture


def summarize_samples(samples: list[float]) -> dict[str, JsonValue]:
    """Return bounded descriptive statistics and safe numeric samples.

    Args:
        samples (list[float]): Physical request durations in milliseconds.

    Returns:
        dict[str, JsonValue]: Count, raw numeric durations, summary statistics
            and optional nearest-rank p95/p99 for a complete cohort.
    """
    if not samples:
        return {'count': 0, 'samples_ms': [], 'min_ms': None, 'max_ms': None,
            'p50_ms': None, 'p95_ms': None, 'p99_ms': None}
    ordered = sorted(samples)
    result: dict[str, JsonValue] = {
        'count': len(ordered),
        'samples_ms': [round(value, B3_TIMING_DECIMAL_PLACES)
                       for value in samples],
        'min_ms': round(ordered[0], B3_TIMING_DECIMAL_PLACES),
        'max_ms': round(ordered[-1], B3_TIMING_DECIMAL_PLACES),
        'p50_ms': round(float(median(ordered)), B3_TIMING_DECIMAL_PLACES),
        'p95_ms': None, 'p99_ms': None,
    }
    if len(ordered) >= B3_TAIL_SAMPLE_MINIMUM:
        for percentile in B3_TAIL_PERCENTILES:
            rank = math.ceil(percentile * len(ordered))
            key = 'p95_ms' if percentile == B3_TAIL_PERCENTILES[0] else 'p99_ms'
            result[key] = round(ordered[rank - 1], B3_TIMING_DECIMAL_PLACES)
    return result


def bind_media_proofs(
    issuer: str, bearer: str, gallery_id: int,
    observed_counts: dict[str, int],
) -> tuple[linking.LinkingSession, tuple[linking.LinkedPhoto, ...]]:
    """Discover capability and bind the first 120 ordered live photo proofs.

    Args:
        issuer (str): Exact loopback fixture issuer.
        bearer (str): Owned media-read bearer held only in memory.
        gallery_id (int): Owned fixture gallery identity.
        observed_counts (dict[str, int]): Mutable safe page/row counters for
            partial failure evidence; contains no identities.

    Returns:
        tuple[linking.LinkingSession, tuple[linking.LinkedPhoto, ...]]: Bound
            session and all current ordered catalogue descriptors.

    Raises:
        linking.LinkingError: Discovery, identity or page proof was refused.
        RuntimeError: The disposable catalogue does not match the cohort size.
    """
    capabilities = linking.discover_linking_capabilities(issuer)
    batch_supported = linking.THUMBNAIL_BATCH_CAPABILITY in capabilities
    require(batch_supported, 'capability_missing')
    session = linking.LinkingSession(
        issuer, bearer, time.monotonic() + B3_SESSION_LIFETIME_SECONDS,
        linking.GALLERIES_SCOPE + linking.MEDIA_SCOPE_SUFFIX,
        thumbnail_batch_supported=batch_supported,
    )
    try:
        identity = session.identity()
        require(identity.user_id > 0, 'identity_invalid')
        observed_photos: list[linking.LinkedPhoto] = []
        after_id: int | None = None
        while len(observed_photos) < B3_IMAGE_COUNT:
            require(observed_counts['metadata_pages'] < B3_METADATA_PAGE_LIMIT,
                    'metadata_page_limit')
            page = session.media_page(gallery_id, after_id)
            observed_counts['metadata_pages'] += 1
            observed_counts['metadata_rows_observed'] += len(page.photos)
            require(page.gallery_id == gallery_id, 'page_gallery_mismatch')
            require(bool(page.photos) or page.next_after is None,
                    'metadata_page_no_progress')
            observed_photos.extend(page.photos)
            if page.next_after is None:
                break
            require(after_id is None or page.next_after > after_id,
                    'metadata_cursor_no_progress')
            after_id = page.next_after
        photos = tuple(observed_photos[:B3_IMAGE_COUNT])
        observed_counts['selected_count'] = len(photos)
        require(len(photos) == B3_IMAGE_COUNT, 'page_count_mismatch')
        require(all(photo.thumbnail_etag is not None
                    and photo.thumbnail_verified_at is not None for photo in photos),
                'thumbnail_proof_missing')
        require(tuple(photo.image_id for photo in photos)
                == tuple(sorted({photo.image_id for photo in photos})),
                'photo_order_invalid')
        return session, photos
    except Exception:
        session.close()
        raise


def cache_environment(cache_root: str) -> tuple[str | None, str | None]:
    """Select one isolated per-cohort cache root and remove Windows override.

    Args:
        cache_root (str): Private temporary directory for one cohort.

    Returns:
        tuple[str | None, str | None]: Previous XDG and LOCALAPPDATA values
            for exact restoration after the profile.
    """
    prior_xdg = os.environ.get('XDG_CACHE_HOME')
    prior_local = os.environ.get('LOCALAPPDATA')
    os.environ['XDG_CACHE_HOME'] = cache_root
    os.environ.pop('LOCALAPPDATA', None)
    return prior_xdg, prior_local


def restore_cache_environment(previous: tuple[str | None, str | None]) -> None:
    """Restore the caller's cache environment after a cohort completes.

    Args:
        previous (tuple[str | None, str | None]): Saved XDG and Windows cache
            roots from cache_environment.

    Returns:
        None: Restores each original variable or removes it when absent.
    """
    prior_xdg, prior_local = previous
    if prior_xdg is None:
        os.environ.pop('XDG_CACHE_HOME', None)
    else:
        os.environ['XDG_CACHE_HOME'] = prior_xdg
    if prior_local is None:
        os.environ.pop('LOCALAPPDATA', None)
    else:
        os.environ['LOCALAPPDATA'] = prior_local


def sample_result(
    timings: linking.MediaReadTimings, operation_elapsed_ms: float,
) -> dict[str, JsonValue]:
    """Capture one physical request's numeric transport observation.

    Args:
        timings (MediaReadTimings): Existing per-operation physical counters.
        operation_elapsed_ms (float): Wall time for that one API operation.

    Returns:
        dict[str, JsonValue]: Numeric transport facts and only the strict parsed
            Server-Timing fields, or null when the header was absent/invalid.
    """
    server_timing = timings.server_timing
    server_snapshot: dict[str, JsonValue] | None = None
    if server_timing is not None:
        server_snapshot = {
            'php_ms': server_timing.php_ms,
            'sql_ms': server_timing.sql_ms,
            'sql_count': server_timing.sql_count,
            'sql_max_ms': server_timing.sql_max_ms,
            'sql_failed': server_timing.sql_failed,
            'sql_capped': server_timing.sql_capped,
            'auth_ms': server_timing.auth_ms,
            'tree_ms': server_timing.tree_ms,
            'hash_ms': server_timing.hash_ms,
            'hash_bytes': server_timing.hash_bytes,
            'media_ms': server_timing.media_ms,
        }
    return {
        'http_requests': timings.http_requests,
        'response_bytes': timings.encoded_bytes,
        'network_ms': timings.network_ms,
        'ttfb_ms': timings.ttfb_ms,
        'body_ms': timings.body_ms,
        'operation_ms': operation_elapsed_ms,
        'server_timing_header_snapshot': server_snapshot,
    }


def summarize_profile_phase(
    name: str, item_count: int, elapsed_ms: float,
    operation_samples: list[dict[str, JsonValue]],
    first_ready_ms: float | None, first_wire_frame_ms: float | None,
    cache_hits: int, cache_writes: int, cache_written_bytes: int,
    equal_items: int, request_size_histogram: dict[str, int],
) -> dict[str, JsonValue]:
    """Summarize a cold or warm cohort phase without raw identities or samples.

    Args:
        name (str): Fixed phase label.
        item_count (int): Number of validated delivered images.
        elapsed_ms (float): Total phase wall time.
        operation_samples (list[dict[str, JsonValue]]): Numeric per-operation
            rows; only entries with an HTTP send enter physical distributions.
        first_ready_ms (float | None): First callback time relative to phase start.
        first_wire_frame_ms (float | None): First complete batch frame time.
        cache_hits (int): Hits confirmed by the owning cache/session API.
        cache_writes (int): Cache writes confirmed by the existing owner.
        cache_written_bytes (int): Bytes in those confirmed cache writes.
        equal_items (int): Items matching the in-memory baseline SHA/MIME map.
        request_size_histogram (dict[str, int]): Actual item counts per batch
            request, grouped by their numeric size string.

    Returns:
        dict[str, JsonValue]: Sanitized counts, totals and request distributions.
    """
    def values(field: str) -> list[float]:
        """Project one numeric timing field from request rows.

        Args:
            field (str): Fixed numeric key from sample_result.

        Returns:
            list[float]: Existing numeric samples without request identifiers.
        """
        return [float(value) for sample in operation_samples
                if isinstance((value := sample[field]), (int, float))]

    physical_samples = [
        sample for sample in operation_samples if int(sample['http_requests']) > 0
    ]

    def physical_values(field: str) -> list[float]:
        """Return one observed physical-request timing field.

        Args:
            field (str): Fixed numeric key from sample_result.

        Returns:
            list[float]: Samples only for operations that sent HTTP.
        """
        return [float(value) for sample in physical_samples
                if isinstance((value := sample[field]), (int, float))]

    return {
        'phase': name, 'items': item_count,
        'http_requests': sum(int(sample['http_requests']) for sample in operation_samples),
        'response_bytes': sum(int(sample['response_bytes']) for sample in operation_samples),
        'cache_hits': cache_hits, 'equal_items': equal_items,
        'cache_writes': cache_writes,
        'cache_written_bytes': cache_written_bytes,
        'request_size_histogram': request_size_histogram,
        'elapsed_ms': round(elapsed_ms, B3_TIMING_DECIMAL_PLACES),
        'first_ready_ms': (round(first_ready_ms, B3_TIMING_DECIMAL_PLACES)
                           if first_ready_ms is not None else None),
        'first_wire_frame_ms': (round(
            first_wire_frame_ms, B3_TIMING_DECIMAL_PLACES,
        )
                                if first_wire_frame_ms is not None else None),
        'network_request_ms': summarize_samples(physical_values('network_ms')),
        'ttfb_ms': summarize_samples(physical_values('ttfb_ms')),
        'body_ms': summarize_samples(physical_values('body_ms')),
        'operation_ms': summarize_samples(values('operation_ms')),
        'server_timing_header_snapshot': [
            sample['server_timing_header_snapshot'] for sample in physical_samples
        ],
    }


def safe_failure_diagnostic(error: Exception) -> dict[str, JsonValue]:
    """Project a failure into fixed local or validated transport fields.

    Args:
        error (Exception): Failure raised by the owned client operation.

    Returns:
        dict[str, JsonValue]: Static check label, fixed exception class or
            allowlisted response facts without message contents.
    """
    if isinstance(error, B3CheckFailure):
        return {'error_class': 'B3CheckFailure', 'check_code': error.code}
    if isinstance(error, linking.LinkingResponseError):
        allowed_routes = (
            'app_linking_discovery', 'app_linking_push', 'app_linking_token',
            'app_linking_identity', 'app_linking_revoke',
            'app_linking_galleries', 'app_linking_gallery_tree',
            'app_linking_inventory', 'app_linking_upload',
            'app_linking_gallery_detail', 'app_linking_gallery_create',
            'app_linking_gallery_create_status',
            'app_linking_gallery_create_abandon',
            'app_linking_gallery_visibility', 'app_linking_photo_visibility',
            'app_linking_media_delete', 'app_linking_media',
            'app_linking_thumbnail_batch',
        )
        if (error.phase not in ('discovery', 'linking', 'media')
                or error.route not in allowed_routes
                or (error.status is not None and (
                    type(error.status) is not int
                    or not 100 <= error.status <= 599
                ))
                or error.content_kind not in ('json', 'html', 'other')
                or error.reason not in (
                    'http_status', 'content_type', 'response_policy',
                    'response_size', 'invalid_json', 'invalid_shape',
                    'dns_failure', 'connect_failure', 'tls_failure', 'timeout',
                    'transport_failure', 'throttled',
                )):
            return {'error_class': 'LinkingResponseError'}
        return {
            'error_class': 'LinkingResponseError',
            'phase': error.phase,
            'route': error.route,
            'status': error.status,
            'content_kind': error.content_kind,
            'reason': error.reason,
        }
    if isinstance(error, linking.LinkingError):
        return {'error_class': 'LinkingError'}
    if isinstance(error, TimeoutError):
        return {'error_class': 'TimeoutError'}
    if isinstance(error, OSError):
        return {'error_class': 'OSError'}
    if isinstance(error, ValueError):
        return {'error_class': 'ValueError'}
    return {'error_class': 'UnexpectedError'}


def run_profile(
    issuer: str, bearer: str, gallery_id: int, name: str,
    group_size: int | None, baseline: dict[int, tuple[str, str]] | None,
    progress: Callable[[dict[str, JsonValue]], None],
) -> tuple[dict[str, JsonValue], dict[int, tuple[str, str]]]:
    """Run cold then warm reads using one real session and isolated disk cache.

    Args:
        issuer (str): Exact loopback fixture issuer.
        bearer (str): Owned in-memory media-read bearer.
        gallery_id (int): Owned gallery identity.
        name (str): Fixed safe profile label.
        group_size (int | None): Batch size, or None for sequential singles.
        baseline (dict[int, tuple[str, str]] | None): In-memory ID to digest/MIME
            map from the cold single-read control; never serialized.
        progress (Callable[[dict[str, JsonValue]], None]): Sanitized checkpoint
            writer for partial evidence.

    Returns:
        tuple[dict[str, JsonValue], dict[int, tuple[str, str]]]: Sanitized profile
            report and updated in-memory digest/MIME reference map.

    Raises:
        RuntimeError: Any HTTP, proof, equality, cache or accounting assertion fails.
    """
    with TemporaryDirectory(prefix='php-gallery-b3-cache-') as cache_path:
        prior_environment = cache_environment(cache_path)
        session: linking.LinkingSession | None = None
        current_phase = 'proof_binding'
        current_samples: list[dict[str, JsonValue]] = []
        current_item_count = 0
        proof_binding_counts = {
            'metadata_pages': 0, 'metadata_rows_observed': 0,
            'selected_count': 0,
        }
        inflight_metrics: linking.MediaReadTimings | None = None
        inflight_started: float | None = None
        phase_started = time.perf_counter()
        try:
            session, photos = bind_media_proofs(
                issuer, bearer, gallery_id, proof_binding_counts,
            )
            expected: dict[int, tuple[str, str]] = {}
            if baseline is not None:
                expected = baseline
            cold_start = time.perf_counter()
            phase_started = cold_start
            current_phase = 'cold'
            current_item_count = 0
            cold_samples: list[dict[str, JsonValue]] = []
            current_samples = cold_samples
            cold_equal = 0
            cold_cache_hits = 0
            cold_cache_writes = 0
            cold_cache_written_bytes = 0
            cold_request_size_histogram: dict[str, int] = {}
            first_ready_ms: float | None = None
            first_wire_frame_ms: float | None = None
            if group_size is None:
                for photo in photos:
                    metrics = linking.MediaReadTimings()
                    operation_started = time.perf_counter()
                    inflight_metrics = metrics
                    inflight_started = operation_started
                    body, mime = session.media_photo(
                        gallery_id, photo.image_id, True,
                        cache_eligible=photo.cache_eligible,
                        thumbnail_etag=photo.thumbnail_etag,
                        thumbnail_verified_at=photo.thumbnail_verified_at,
                        timings=metrics,
                    )
                    operation_ms = (time.perf_counter() - operation_started) * 1000.0
                    require(metrics.http_requests == 1, 'single_cold_http_count')
                    digest = hashlib.sha256(body).hexdigest()
                    if baseline is None:
                        expected[photo.image_id] = (digest, mime)
                    else:
                        require(expected.get(photo.image_id) == (digest, mime),
                                'single_cold_reference_mismatch')
                    cold_equal += 1
                    current_item_count = cold_equal
                    require(mime in ('image/jpeg', 'image/webp'), 'single_mime_invalid')
                    cold_cache_writes += int(metrics.cache_stored)
                    cold_cache_written_bytes += metrics.stored_bytes
                    if first_ready_ms is None:
                        first_ready_ms = (time.perf_counter() - cold_start) * 1000.0
                    cold_samples.append(sample_result(metrics, operation_ms))
                    inflight_metrics = None
                    inflight_started = None
                    if metrics.http_requests:
                        cold_request_size_histogram['1'] = (
                            cold_request_size_histogram.get('1', 0)
                            + metrics.http_requests
                        )
            else:
                for offset in range(0, len(photos), group_size):
                    group = photos[offset:offset + group_size]
                    metrics = linking.MediaReadTimings()
                    stats = linking.ThumbnailBatchStats()
                    operation_started = time.perf_counter()
                    inflight_metrics = metrics
                    inflight_started = operation_started
                    group_ready: list[
                        tuple[linking.ThumbnailPublication,
                              linking.MediaReadTimings, float]
                    ] = []

                    def publish(
                        result: linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
                        item_metrics: linking.MediaReadTimings,
                        check_active: Callable[[], None],
                    ) -> None:
                        """Retain one bounded item after caller proof checking.

                        Args:
                            result (ThumbnailPublication | LegacyThumbnailPublication):
                                Exact session publication.
                            item_metrics (MediaReadTimings): Per-item cache observations.
                            check_active (Callable[[], None]): Whole-operation fence.

                        Returns:
                            None: Appends only successful batch publications.
                        """
                        nonlocal first_ready_ms, current_item_count
                        check_active()
                        require(isinstance(result, linking.ThumbnailPublication)
                                and result.status == 'ok' and result.body is not None
                                and result.mime in ('image/jpeg', 'image/webp'),
                                'batch_fallback_or_status')
                        ready_ms = (time.perf_counter() - cold_start) * 1000.0
                        group_ready.append((result, item_metrics, ready_ms))
                        current_item_count += 1
                        if first_ready_ms is None:
                            first_ready_ms = ready_ms

                    session.media_thumbnails(
                        gallery_id, tuple(group), publish, timings=metrics,
                        stats=stats,
                    )
                    operation_ms = (time.perf_counter() - operation_started) * 1000.0
                    require(metrics.http_requests == 1, 'batch_cold_http_count')
                    require(stats.requested == len(group)
                            and stats.returned == len(group)
                            and stats.delivered == len(group)
                            and stats.wire_items == len(group)
                            and stats.cache_hits == 0
                            and stats.fallback_unsupported == 0
                            and stats.fallback_capability_missing == 0
                            and stats.fallback_wire_disabled == 0
                            and stats.fallback_restricted == 0
                            and stats.fallback_exceptional == 0
                            and stats.operation_failures == 0,
                            'batch_cold_stats')
                    require(len(group_ready) == len(group), 'batch_cold_delivery_count')
                    if first_wire_frame_ms is None and stats.first_frame_ms is not None:
                        first_wire_frame_ms = (
                            (operation_started - cold_start) * 1000.0
                            + stats.first_frame_ms
                        )
                    for result, item_metrics, _ready_ms in group_ready:
                        image_id = result.photo.image_id
                        body = result.body
                        mime = result.mime
                        require(body is not None and mime is not None,
                                'batch_body_missing')
                        digest = hashlib.sha256(body).hexdigest()
                        if baseline is None:
                            expected[image_id] = (digest, mime)
                        else:
                            require(expected.get(image_id) == (digest, mime),
                                    'batch_reference_mismatch')
                        cold_equal += 1
                        cold_cache_writes += int(item_metrics.cache_stored)
                        cold_cache_written_bytes += item_metrics.stored_bytes
                    cold_samples.append(sample_result(metrics, operation_ms))
                    inflight_metrics = None
                    inflight_started = None
                    for actual_size, actual_count in stats.batch_sizes.items():
                        size_key = str(actual_size)
                        cold_request_size_histogram[size_key] = (
                            cold_request_size_histogram.get(size_key, 0)
                            + actual_count
                        )
            require(cold_equal == len(photos), 'cold_item_count')
            require(cold_cache_hits == 0, 'cold_cache_hit')
            require(cold_cache_writes == len(photos)
                    and cold_cache_written_bytes > 0,
                    'cold_cache_publication')
            expected_cold_requests = (
                len(photos) if group_size is None
                else math.ceil(len(photos) / group_size)
            )
            require(sum(int(sample['http_requests']) for sample in cold_samples)
                    == expected_cold_requests, 'cold_physical_request_count')
            cold_elapsed_ms = (time.perf_counter() - cold_start) * 1000.0
            cold = summarize_profile_phase(
                'cold', cold_equal, cold_elapsed_ms, cold_samples,
                first_ready_ms, first_wire_frame_ms, cold_cache_hits,
                cold_cache_writes, cold_cache_written_bytes,
                cold_equal if baseline is not None else 0,
                cold_request_size_histogram,
            )
            progress({'profile': name, 'cold': cold})

            warm_start = time.perf_counter()
            phase_started = warm_start
            current_phase = 'warm'
            current_item_count = 0
            warm_samples: list[dict[str, JsonValue]] = []
            current_samples = warm_samples
            warm_equal = 0
            warm_cache_hits = 0
            warm_cache_writes = 0
            warm_cache_written_bytes = 0
            warm_request_size_histogram: dict[str, int] = {}
            first_warm_ready_ms: float | None = None
            if group_size is None:
                for photo in photos:
                    metrics = linking.MediaReadTimings()
                    operation_started = time.perf_counter()
                    inflight_metrics = metrics
                    inflight_started = operation_started
                    body, mime = session.media_photo(
                        gallery_id, photo.image_id, True,
                        cache_eligible=photo.cache_eligible,
                        thumbnail_etag=photo.thumbnail_etag,
                        thumbnail_verified_at=photo.thumbnail_verified_at,
                        timings=metrics,
                    )
                    operation_ms = (time.perf_counter() - operation_started) * 1000.0
                    require(metrics.http_requests == 0 and metrics.source == 'disk'
                            and metrics.cache_state == 'hit', 'single_warm_http_or_cache')
                    require(expected.get(photo.image_id)
                            == (hashlib.sha256(body).hexdigest(), mime),
                            'single_warm_reference_mismatch')
                    warm_equal += 1
                    current_item_count = warm_equal
                    warm_cache_hits += 1
                    warm_cache_writes += int(metrics.cache_stored)
                    warm_cache_written_bytes += metrics.stored_bytes
                    warm_samples.append(sample_result(metrics, operation_ms))
                    inflight_metrics = None
                    inflight_started = None
                    if first_warm_ready_ms is None:
                        first_warm_ready_ms = (time.perf_counter() - warm_start) * 1000.0
            else:
                for offset in range(0, len(photos), group_size):
                    group = photos[offset:offset + group_size]
                    metrics = linking.MediaReadTimings()
                    stats = linking.ThumbnailBatchStats()
                    operation_started = time.perf_counter()
                    inflight_metrics = metrics
                    inflight_started = operation_started
                    group_ready: list[linking.ThumbnailPublication] = []

                    def publish_warm(
                        result: linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
                        item_metrics: linking.MediaReadTimings,
                        check_active: Callable[[], None],
                    ) -> None:
                        """Collect one authorized warm cache publication.

                        Args:
                            result (ThumbnailPublication | LegacyThumbnailPublication):
                                Exact session publication.
                            item_metrics (MediaReadTimings): Per-item cache record.
                            check_active (Callable[[], None]): Whole-operation fence.

                        Returns:
                            None: Appends only verified batch cache hits.
                        """
                        nonlocal first_warm_ready_ms, current_item_count
                        nonlocal warm_cache_writes
                        nonlocal warm_cache_written_bytes
                        check_active()
                        require(isinstance(result, linking.ThumbnailPublication)
                                and result.status == 'ok' and result.body is not None
                                and result.mime in ('image/jpeg', 'image/webp'),
                                'batch_warm_fallback_or_status')
                        group_ready.append(result)
                        current_item_count += 1
                        warm_cache_writes += int(item_metrics.cache_stored)
                        warm_cache_written_bytes += item_metrics.stored_bytes
                        if first_warm_ready_ms is None:
                            first_warm_ready_ms = (
                                (time.perf_counter() - warm_start) * 1000.0
                            )

                    session.media_thumbnails(
                        gallery_id, tuple(group), publish_warm, timings=metrics,
                        stats=stats,
                    )
                    operation_ms = (time.perf_counter() - operation_started) * 1000.0
                    require(metrics.http_requests == 0
                            and stats.cache_hits == len(group)
                            and stats.wire_items == 0
                            and stats.delivered == len(group)
                            and len(group_ready) == len(group),
                            'batch_warm_http_or_cache')
                    warm_cache_hits += stats.cache_hits
                    warm_samples.append(sample_result(metrics, operation_ms))
                    inflight_metrics = None
                    inflight_started = None
                    for result in group_ready:
                        body = result.body
                        mime = result.mime
                        require(body is not None and mime is not None
                                and expected.get(result.photo.image_id)
                                == (hashlib.sha256(body).hexdigest(), mime),
                                'batch_warm_reference_mismatch')
                        warm_equal += 1
            require(warm_equal == len(photos), 'warm_item_count')
            require(warm_cache_hits == len(photos)
                    and warm_cache_writes == 0
                    and warm_cache_written_bytes == 0,
                    'warm_cache_accounting')
            require(sum(int(sample['http_requests']) for sample in warm_samples) == 0,
                    'warm_physical_request_count')
            warm_elapsed_ms = (time.perf_counter() - warm_start) * 1000.0
            warm = summarize_profile_phase(
                'warm', warm_equal, warm_elapsed_ms, warm_samples,
                first_warm_ready_ms, None, warm_cache_hits,
                warm_cache_writes, warm_cache_written_bytes,
                warm_equal,
                warm_request_size_histogram,
            )
            profile: dict[str, JsonValue] = {
                'name': name, 'group_size': group_size,
                'items': len(photos), 'proof_binding': proof_binding_counts,
                'cold': cold, 'warm': warm,
            }
            progress({'profile_complete': profile})
            return profile, expected
        except Exception as error:
            failure_samples = list(current_samples)
            if (inflight_metrics is not None and inflight_started is not None
                    and inflight_metrics.http_requests > 0):
                failure_samples.append(sample_result(
                    inflight_metrics,
                    (time.perf_counter() - inflight_started) * 1000.0,
                ))
            progress({
                'profile_in_progress': name,
                'phase_in_progress': current_phase,
                'proof_binding': proof_binding_counts,
                'items_delivered': current_item_count,
                'operation_count': len(failure_samples),
                'elapsed_ms': round(
                    (time.perf_counter() - phase_started) * 1000.0,
                    B3_TIMING_DECIMAL_PLACES,
                ),
                'operation_samples': failure_samples,
                'diagnostic': safe_failure_diagnostic(error),
            })
            raise
        finally:
            if session is not None:
                session.close()
            restore_cache_environment(prior_environment)


def main() -> int:
    """Run the five owned cold/warm profiles and persist sanitized evidence.

    Returns:
        int: Zero on complete valid evidence, one for a generic safe failure.
    """
    fixture: Path | None = None
    stage = 'guard'
    completed: list[dict[str, JsonValue]] = []
    partial_path: Path | None = None
    last_checkpoint: dict[str, JsonValue] | None = None
    current_profile = 'setup'

    def write_progress(progress: dict[str, JsonValue]) -> None:
        """Append a sanitized checkpoint to the private fixture report.

        Args:
            progress (dict[str, JsonValue]): Fixed labels and numeric summaries.

        Returns:
            None: Atomically replaces the current partial report.
        """
        nonlocal last_checkpoint
        last_checkpoint = progress
        if partial_path is None:
            return
        document: dict[str, JsonValue] = {
            'schema': 'php-gallery-b3-client-v1',
            'assertions': 'in_progress', 'completed_profiles': completed,
            'last_checkpoint': progress,
        }
        temporary = partial_path.with_suffix('.tmp')
        temporary.write_text(json.dumps(document, separators=(',', ':')),
                             encoding='utf-8')
        temporary.replace(partial_path)

    try:
        issuer, bearer, gallery_id, fixture = validate_environment()
        partial_path = fixture / 'issue-362-b3-client.json'
        stage = 'single_profile'
        current_profile = 'single'
        single, baseline = run_profile(
            issuer, bearer, gallery_id, 'single', None, None, write_progress,
        )
        completed.append(single)
        require(len(baseline) == B3_IMAGE_COUNT, 'baseline_count')
        for group_size in B3_GROUP_SIZES:
            stage = 'batch_profile'
            name = 'batch_' + str(group_size)
            current_profile = name
            profile, _reference = run_profile(
                issuer, bearer, gallery_id, name, group_size, baseline,
                write_progress,
            )
            completed.append(profile)
        report: dict[str, JsonValue] = {
            'schema': 'php-gallery-b3-client-v1', 'assertions': 'passed',
            'profile_count': len(completed), 'image_count': B3_IMAGE_COUNT,
            'equal_sha_mime_items_per_profile': B3_IMAGE_COUNT,
            'profiles': completed,
            'measurement': {
                'cold_definition': 'empty isolated client disk cache; discovery, identity and media-page proof binding excluded',
                'server_cache_state': 'not controlled or reset',
                'server_timing_scope': 'strict parsed response-header snapshot before body stream; not total PHP runtime or worker occupancy',
            },
            'scope': 'owned loopback PHP CLI and disposable MariaDB; timings are client observations, not GUI paint or production-host acceptance',
        }
        partial_path.write_text(json.dumps(report, indent=2), encoding='utf-8')
        print('PASS B3 connected thumbnail client')
        return 0
    except Exception as error:
        if partial_path is not None:
            try:
                partial_path.write_text(json.dumps({
                    'schema': 'php-gallery-b3-client-v1',
                    'assertions': 'failed', 'stage': stage,
                    'profile': current_profile,
                    'diagnostic': safe_failure_diagnostic(error),
                    'profile_count': len(completed), 'profiles': completed,
                    'last_checkpoint': last_checkpoint,
                    'measurement': {
                        'cold_definition': 'empty isolated client disk cache; discovery, identity and media-page proof binding excluded',
                        'server_cache_state': 'not controlled or reset',
                        'server_timing_scope': 'strict parsed response-header snapshot before body stream; not total PHP runtime or worker occupancy',
                    },
                }, indent=2), encoding='utf-8')
            except OSError:
                pass
        print('FAIL B3 connected thumbnail client stage=' + stage)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
