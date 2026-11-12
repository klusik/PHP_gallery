# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_a1_connected.py
# Module Type: Disposable Connected Client Benchmark
# Purpose: Measure real WinApp linking transport against an owned PHP/MariaDB fixture.
# Responsibilities:
#   - Exercise authorized tree-v1, bounded legacy fallback, and media reads.
#   - Distinguish cold transport, warm disk and bounded decoded RAM reuse.
#   - Persist only sanitized timings and enforce a disposable loopback origin.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Connected WinApp transport measurements for the issue #362 A1 CI fixture."""

import hashlib
import json
import math
import os
from pathlib import Path
import statistics
import subprocess
import sys
import tempfile
import threading
import tkinter as tk
import time
from datetime import datetime, timezone
from types import SimpleNamespace
from urllib.parse import urlsplit

from uploader.photos_workspace import (
    PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT,
    PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS,
)
from uploader.app_linking import (
    GALLERIES_SCOPE,
    UPLOAD_SCOPE,
    MEDIA_SCOPE_SUFFIX,
    LinkingError,
    LinkingResponseError,
    LinkingSession,
    LinkedGallery,
    LinkedPhoto,
    MediaReadTimings,
)
from uploader.linked_upload import LinkedMultipart
from PIL import Image
from uploader.native_gallery_viewer import (
    GalleryThumbnailMemory,
    _NativeGalleryViewer,
)


# Purpose: Keep the bearer valid only inside the already bounded fixture grant.
# Type: int. Units: seconds. Scope: disposable connected client session.
# Consumers: new_session. Rationale: 600 is less than fixture grant's 900 seconds.
A1_SESSION_LIFETIME_SECONDS = 2400

# Purpose: Cover one fixed logical visible viewport independent of Tk geometry.
# Type: int. Units: photos. Scope: isolated headless client observation.
# Consumers: _MemoryProbe and measure_media. Rationale: 60 matches the real
# viewer cache's documented example of 60 visible tiles, not a rendered claim.
A1_VISIBLE_PHOTOS = 60

# Purpose: Bound one logical one-row overscan around the fixed viewport.
# Type: int. Units: photos. Scope: headless media observation.
# Consumers: measure_media. Rationale: one 12-card row is independently labelled.
A1_OVERSCAN_PHOTOS = 12

# Purpose: Measure a fixed original baseline set, not all seed-only extras.
# Type: int. Units: distinct photos. Scope: connected media observation.
# Consumers: measure_media. Rationale: matches the issue's 120-photo requirement.
A1_MEDIA_PHOTOS = 120

# Purpose: Reject unexpected metadata-page loops in a disposable client.
# Type: int. Units: pages. Scope: one authorized sample album.
# Consumers: load_photos. Rationale: 4 admits 120 photos in 64-row pages.
A1_MAX_MEDIA_PAGES = 4

# Purpose: Do not expose unstable nearest-rank extreme tails on tiny samples.
# Type: int. Units: observations. Scope: timing statistic generation.
# Consumers: summarize. Rationale: twenty observations suffice for descriptive
# p95; p99 is only emitted for one hundred or more independent observations.
A1_P95_MIN_SAMPLES = 20
A1_P99_MIN_SAMPLES = 100

# Purpose: Bound full legacy fallback pagination at the 10,001-row limit.
# Type: int. Units: pages. Scope: issue #362 connected overflow benchmark.
# Consumers: measure_overflow. Rationale: 201 pages hold 10,001 rows at 50
# each, and one extra page guards against malformed cursors.
A1_MAX_GALLERY_PAGES = 202

# Purpose: Confine report names and mode selection to owned A1 scenarios.
# Type: frozenset[str]. Units: scenario identifiers. Scope: CI entrypoint.
# Consumers: main. Rationale: reject arbitrary paths or out-of-scope operations.
A1_MODES = frozenset({"tree", "overflow", "media", "fault", "fairness", "gui", "mutate"})


class _MemoryProbe(_NativeGalleryViewer):
    """Reuse real viewer decode and bounded memory methods without starting Tk.

    This object is intentionally NOT a GUI viewer. Its only callable inherited
    methods here are _prepare_thumbnail, _remember_thumbnail and
    _cached_thumbnail, with every attribute those methods read initialized.
    There is no Tk widget, canvas, scheduling or paint-timing claim.
    """

    def __init__(
        self, session: LinkingSession, gallery_id: int,
        photos: tuple[LinkedPhoto, ...],
    ) -> None:
        """Create a method-limited isolated owner for actual viewer RAM rules.

        Args:
            session (LinkingSession): Live authorized synthetic-gallery bearer.
            gallery_id (int): Authorized synthetic album identifier.
            photos (tuple[LinkedPhoto, ...]): Current validated sample photo rows.

        Returns:
            None: Bounded memory, identity and logical viewport are initialized.
        """
        self.session = session
        self.gallery = SimpleNamespace(gallery_id=gallery_id)
        self._photos = photos
        self._visible_thumbnail_indices = tuple(
            range(min(A1_VISIBLE_PHOTOS, len(photos)))
        )
        self._thumbnail_memory = GalleryThumbnailMemory()

    def close_probe(self) -> None:
        """Release decoded rasters without calling the real widget close path.

        Returns:
            None: Every headless RAM cache image is released.
        """
        self._thumbnail_memory.clear()


def utc_now() -> str:
    """Read the actual UTC wall clock for an absolute benchmark checkpoint.

    Returns:
        str: Current ISO 8601 timestamp at second precision.
    """
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def new_session(issuer: str, token: str, *, tree: bool) -> LinkingSession:
    """Create a genuine unmodified WinApp bearer client for the owned fixture.

    Args:
        issuer (str): Validated exact disposable loopback gallery origin.
        token (str): Server-issued synthetic bearer from private child environment.
        tree (bool): Whether the emulated discovery advertised tree-v1.

    Returns:
        LinkingSession: Live transport owner with its original admission gates.
    """
    return LinkingSession(
        issuer, token, time.monotonic() + A1_SESSION_LIFETIME_SECONDS,
        scope=GALLERIES_SCOPE + MEDIA_SCOPE_SUFFIX,
        gallery_tree_supported=tree,
    )


def summarize(values: list[float]) -> dict[str, float | int | None]:
    """Describe individually observed monotonic intervals without extrapolation.

    Args:
        values (list[float]): Durations in milliseconds from real calls.

    Returns:
        dict[str, float | int | None]: Count, extrema, mean, median and
            descriptive nearest-rank tails only with enough observations.
    """
    if not values:
        return {"count": 0}
    ordered = sorted(values)

    def rank(percentile: float) -> float:
        """Select a measured nearest-rank sample, not an interpolated one.

        Args:
            percentile (float): Desired cumulative proportion, zero through one.

        Returns:
            float: An actual value from the sorted observations.
        """
        return ordered[max(0, math.ceil(percentile * len(ordered)) - 1)]

    return {
        "count": len(ordered),
        "min_ms": round(ordered[0], 6),
        "max_ms": round(ordered[-1], 6),
        "mean_ms": round(statistics.mean(ordered), 6),
        "p50_ms": round(statistics.median(ordered), 6),
        "p95_ms": round(rank(0.95), 6)
        if len(ordered) >= A1_P95_MIN_SAMPLES else None,
        "p99_ms": round(rank(0.99), 6)
        if len(ordered) >= A1_P99_MIN_SAMPLES else None,
    }


def observe(
    index: int, elapsed_ms: float, timings: MediaReadTimings,
    decode_ms: float = 0.0,
) -> dict[str, str | int | float | bool]:
    """Emit only native numeric counters and a positional, nonsecret index.

    Args:
        index (int): Sample ordinal, never an image or gallery database ID.
        elapsed_ms (float): Monotonic full operation time in milliseconds.
        timings (MediaReadTimings): Actual WinApp diagnostic counter owner.
        decode_ms (float): Measured production-thumbnail Pillow decode time.

    Returns:
        dict[str, str | int | float | bool]: Whitelisted transport/cache metrics.
    """
    return {
        "sample": index,
        "elapsed_ms": round(elapsed_ms, 6),
        "decode_ms": round(decode_ms, 6),
        "source": timings.source,
        "cache_state": timings.cache_state,
        "disk_read_ms": round(timings.disk_read_ms, 6),
        "disk_write_ms": round(timings.disk_write_ms, 6),
        "gate_wait_ms": round(timings.gate_wait_ms, 6),
        "slot_wait_ms": round(timings.slot_wait_ms, 6),
        "ttfb_ms": round(timings.ttfb_ms, 6),
        "body_ms": round(timings.body_ms, 6),
        "network_ms": round(timings.network_ms, 6),
        "encoded_bytes": timings.encoded_bytes,
        "http_requests": timings.http_requests,
        "network_reads": timings.network_reads,
        "connections": timings.connections,
        "connection_reuses": timings.connection_reuses,
        "cache_reads": timings.cache_reads,
        "cache_hashes": timings.cache_hashes,
        "cache_hash_bytes": timings.cache_hash_bytes,
        "cache_hash_ns": timings.cache_hash_ns,
        "cache_writes": timings.cache_writes,
        "cache_stored": timings.cache_stored,
        "stored_bytes": timings.stored_bytes,
    }


def measure_tree(
    session: LinkingSession, target: int,
) -> dict[str, object]:
    """Exercise real serialized client parse and a matching unchanged revision.

    Args:
        session (LinkingSession): Real authorized tree-v1 transport owner.
        target (int): Exact seeded fixture row count for the active checkpoint.

    Returns:
        dict[str, object]: Strict full/unchanged validated times and row counts.

    Raises:
        AssertionError: Actual client tree parsing or current revision is wrong.
    """
    start = time.perf_counter()
    full = session.gallery_tree()
    full_ms = (time.perf_counter() - start) * 1000.0
    if full is None or full.unchanged or len(full.galleries) != target:
        raise AssertionError("Real WinApp full-tree contract failed.")
    start = time.perf_counter()
    unchanged = session.gallery_tree(full.revision)
    unchanged_ms = (time.perf_counter() - start) * 1000.0
    if unchanged is None or not unchanged.unchanged or unchanged.revision != full.revision:
        raise AssertionError("Real WinApp unchanged-tree contract failed.")
    return {
        "status": "PASS", "rows": target,
        "full_client_elapsed_ms": round(full_ms, 6),
        "unchanged_client_elapsed_ms": round(unchanged_ms, 6),
        "full_returned_rows": len(full.galleries),
        "unchanged_returned_rows": len(unchanged.galleries),
        "tree_source": "tree-v1-full + tree-v1-unchanged",
        "limits": [
            "Client total includes HTTP, JSON parse and row validation.",
            "The source exposes no safe separate tree SQL/JSON timing here.",
            "No Tk widget mount or real catalogue snapshot persistence was run.",
        ],
    }


def measure_overflow(
    issuer: str, token: str,
) -> dict[str, object]:
    """Verify real overflow fallback and client-side missing capability path.

    Args:
        issuer (str): Exact isolated PHP origin.
        token (str): Only the synthetic token privately supplied by PHP.

    Returns:
        dict[str, object]: Real 10,001-row refusal, complete 201-page
            client fallback and capability negotiation evidence.

    Raises:
        AssertionError: A genuine client cannot complete the expected fallback.
    """
    capable = new_session(issuer, token, tree=True)
    legacy_capability = new_session(issuer, token, tree=False)
    try:
        started = time.perf_counter()
        full = capable.gallery_tree()
        tree_ms = (time.perf_counter() - started) * 1000.0
        if full is not None:
            raise AssertionError("Overflow was not rejected by real WinApp tree read.")
        started = time.perf_counter()
        page = capable.gallery_page()
        if len(page.galleries) != 50 or page.next_after is None:
            raise AssertionError("Real overflow client paginated fallback failed.")
        first_page_count = len(page.galleries)
        # First verify the configured absence of tree capability while the
        # normal per-IP gallery rate limit has not been exhausted.
        if legacy_capability.supports_gallery_tree():
            raise AssertionError("Absent tree capability was incorrectly negotiated.")
        if legacy_capability.gallery_tree() is not None:
            raise AssertionError("Client requested unsupported optional tree.")
        simulated_page = legacy_capability.gallery_page()
        if len(simulated_page.galleries) != 50:
            raise AssertionError("Capability-disabled client lost original endpoint.")
        page_count = 1
        row_count = first_page_count
        last_gallery_id = page.galleries[-1].gallery_id
        cursor = page.next_after
        throttle_status = None
        throttle_count = 0
        pace_wait_ms = 0.0
        # Bound the genuine fallback to one full 201-page journey. The
        # production PhotosWorkspace constants are reused, but the
        # actual Tk scheduler is tested separately.
        max_throttle_retries = 2
        while cursor is not None:
            if page_count >= A1_MAX_GALLERY_PAGES:
                raise AssertionError("Legacy fallback exceeded pagination bound.")
            if page_count >= PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT:
                waiting = time.perf_counter()
                threading.Event().wait(
                    PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS / 1000.0,
                )
                pace_wait_ms += (time.perf_counter() - waiting) * 1000.0
            try:
                next_page = capable.gallery_page(cursor)
            except LinkingResponseError as error:
                if error.status != 429:
                    raise
                throttle_count += 1
                if throttle_count > max_throttle_retries:
                    throttle_status = error.status
                    break
                # A missing server hint cannot justify early retries.
                retry_after = error.retry_after_seconds or 300
                if not 1 <= retry_after <= 300:
                    throttle_status = error.status
                    break
                waiting = time.perf_counter()
                threading.Event().wait(retry_after)
                pace_wait_ms += (time.perf_counter() - waiting) * 1000.0
                continue
            if not next_page.galleries:
                raise AssertionError("Legacy fallback lost an authorized page.")
            first_id = next_page.galleries[0].gallery_id
            if (first_id <= last_gallery_id
                    or next_page.galleries[-1].gallery_id <= last_gallery_id):
                raise AssertionError("Legacy fallback did not advance its cursor.")
            last_gallery_id = next_page.galleries[-1].gallery_id
            row_count += len(next_page.galleries)
            page_count += 1
            cursor = next_page.next_after
        fallback_ms = (time.perf_counter() - started) * 1000.0
        if throttle_status is not None:
            if cursor is None or page_count >= A1_MAX_GALLERY_PAGES:
                raise AssertionError("Invalid rate-limit refusal stage.")
            return {
                "status": "BLOCKED",
                "reason": "real_authenticated_gallery_rate_limit",
                "http_status": throttle_status,
                "complete": False,
                "tree_refusal_client_elapsed_ms": round(tree_ms, 6),
                "paged_client_elapsed_ms": round(fallback_ms, 6),
                "fallback_first_page_rows": first_page_count,
                "fallback_pages_before_throttle": page_count,
                "fallback_rows_before_throttle": row_count,
                "capability_disabled_page_rows": len(simulated_page.galleries),
                "pacing_delay_ms": PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS,
                "pacing_wait_ms": round(pace_wait_ms, 6),
                "observed_429": throttle_count,
                "limits": [
                    "Real app_link_galleries_ip policy is 120 requests / 300 s.",
                    "Full 201-page fallback is BLOCKED, NOT measured as PASS.",
                    "No rate limiter is disabled, bypassed or weakened.",
                    "Missing tree capability remains simulated, not historic.",
                ],
            }
        if row_count != 10001 or page_count != 201:
            raise AssertionError("Legacy fallback lost a seeded gallery.")
        return {
            "status": "PASS",
            "tree_refusal_client_elapsed_ms": round(tree_ms, 6),
            "paged_client_elapsed_ms": round(fallback_ms, 6),
            "fallback_first_page_rows": first_page_count,
            "fallback_pages": page_count,
            "fallback_complete_rows": row_count,
            "capability_disabled_page_rows": len(simulated_page.galleries),
            "pacing_delay_ms": PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS,
            "pacing_after_pages": PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT,
            "pacing_wait_ms": round(pace_wait_ms, 6),
            "observed_429": throttle_count,
            "limits": [
                "Overflow is genuine PHP 10,001-row handling.",
                "All 201 authorized pages were traversed through real WinApp.",
                "Pacing mirrors PhotosWorkspace constants, not its Tk scheduler.",
                "Missing tree capability is client-configured, not a historic server.",
                "Actual PhotosWorkspace widget reconciliation is NOT measured.",
            ],
        }
    finally:
        capable.close()
        legacy_capability.close()


def load_photos(
    session: LinkingSession, gallery_id: int,
) -> tuple[tuple[LinkedPhoto, ...], dict[str, float | int]]:
    """Read all bounded current descriptors through real WinApp media_page calls.

    Args:
        session (LinkingSession): Current bearer and live request owner.
        gallery_id (int): Synthetic seeded album identity, never exported.

    Returns:
        tuple[tuple[LinkedPhoto, ...], dict[str, float | int]]: Current descriptors and
            counted network metadata operations, without their identifiers.
    """
    rows: list = []
    cursor = None
    page_count = 0
    requests = 0
    elapsed_ms = 0.0
    while True:
        timing = MediaReadTimings()
        started = time.perf_counter()
        page = session.media_page(gallery_id, cursor, timings=timing)
        elapsed_ms += (time.perf_counter() - started) * 1000.0
        requests += timing.http_requests
        rows.extend(page.photos)
        page_count += 1
        if page_count > A1_MAX_MEDIA_PAGES:
            raise AssertionError("Unexpected media-page traversal.")
        if page.next_after is None:
            break
        cursor = page.next_after
    if len(rows) < A1_MEDIA_PHOTOS:
        raise AssertionError("Fewer than 120 authorized client-side photos.")
    return tuple(rows[:A1_MEDIA_PHOTOS]), {
        "metadata_pages": page_count,
        "metadata_http_requests": requests,
        "metadata_elapsed_ms": round(elapsed_ms, 6),
    }


def read_photo(
    session: LinkingSession, gallery_id: int, photo: LinkedPhoto,
    ordinal: int, memory: _MemoryProbe | None,
) -> dict[str, str | int | float | bool]:
    """Read one photo through production transport/cache and optional decode.

    Args:
        session (LinkingSession): Exact real, current WinApp bearer session.
        gallery_id (int): Server-authorized album identity.
        photo (LinkedPhoto): Exact validated live photo-page descriptor.
        ordinal (int): Nonsecret zero-based sampling position.
        memory (_MemoryProbe | None): Optional production decoder/RAM cache probe.

    Returns:
        dict[str, str | int | float | bool]: Native metrics with no file or photo ID.
    """
    timing = MediaReadTimings()
    start = time.perf_counter()
    body, mime = session.media_photo(
        gallery_id, photo.image_id, thumbnail=True,
        cache_eligible=photo.cache_eligible,
        thumbnail_etag=photo.thumbnail_etag,
        thumbnail_verified_at=photo.thumbnail_verified_at,
        preload_eligible=photo.preload_eligible,
        preload_thumbnail_etag=photo.preload_thumbnail_etag,
        preload_verified_at=photo.preload_verified_at,
        timings=timing,
    )
    if not body or not mime.startswith("image/"):
        raise AssertionError("Authorized media returned no supported image.")
    decode_ms = 0.0
    if memory is not None:
        decode_started = time.perf_counter()
        image = memory._prepare_thumbnail(body)
        decode_ms = (time.perf_counter() - decode_started) * 1000.0
        memory._remember_thumbnail(photo, image)
    elapsed_ms = (time.perf_counter() - start) * 1000.0
    return observe(ordinal, elapsed_ms, timing, decode_ms)


def sum_field(rows: list[dict], key: str) -> int:
    """Aggregate one genuine counter from individual client observations.

    Args:
        rows (list[dict]): Each source-derived measured thumbnail observation.
        key (str): Allowlisted numeric count field, never a raw body or identity.

    Returns:
        int: Exact sum of its per-operation nonnegative count values.
    """
    return sum(int(row[key]) for row in rows)


def phase_summary(rows: list[dict]) -> dict[str, object]:
    """Retain independent samples and aggregate exact native cache/network work.

    Args:
        rows (list[dict]): Current phase's individually observed operation rows.

    Returns:
        dict[str, object]: Measured elapsed distribution and transport counters.
    """
    return {
        "reads": len(rows),
        "elapsed": summarize([float(row["elapsed_ms"]) for row in rows]),
        "ttfb": summarize([
            float(row["ttfb_ms"]) for row in rows if int(row["http_requests"]) > 0
        ]),
        "gate_wait": summarize([float(row["gate_wait_ms"]) for row in rows]),
        "decode": summarize([float(row["decode_ms"]) for row in rows]),
        "http_requests": sum_field(rows, "http_requests"),
        "network_bytes": sum_field(rows, "encoded_bytes"),
        "disk_reads": sum_field(rows, "cache_reads"),
        "hash_operations": sum_field(rows, "cache_hashes"),
        "hash_bytes": sum_field(rows, "cache_hash_bytes"),
        "stored_entries": sum(int(bool(row["cache_stored"])) for row in rows),
        "disk_hits": sum(row["source"] == "disk" for row in rows),
        "network_sources": sum(row["source"] == "network" for row in rows),
    }


def measure_media(
    issuer: str, token: str, gallery_id: int,
) -> dict[str, object]:
    """Compare cold, warm RAM and new-session disk reads on the same PHP fixture.

    Args:
        issuer (str): Exact verified disposable localhost server origin.
        token (str): Disposable verified bearer, never persisted.
        gallery_id (int): Owned synthetic photo gallery, never persisted.

    Returns:
        dict[str, object]: Real native metrics plus honest headless GUI limits.

    Raises:
        AssertionError: Missing, corrupt or unexpectedly uncached authorized data.
    """
    first = new_session(issuer, token, tree=True)
    memory = None
    try:
        with tempfile.TemporaryDirectory(prefix="a1-client-cache-") as cache_root:
            os.environ["XDG_CACHE_HOME"] = cache_root
            os.environ.pop("LOCALAPPDATA", None)
            photos, cold_metadata = load_photos(first, gallery_id)
            memory = _MemoryProbe(first, gallery_id, photos)
            cold: list[dict] = []
            ready_at_ms = None
            viewport_at_ms = None
            overscan_at_ms = None
            started = time.perf_counter()
            for position, photo in enumerate(photos):
                cold.append(read_photo(first, gallery_id, photo, position, memory))
                elapsed_ms = (time.perf_counter() - started) * 1000.0
                if ready_at_ms is None:
                    ready_at_ms = elapsed_ms
                if position + 1 == A1_VISIBLE_PHOTOS:
                    viewport_at_ms = elapsed_ms
                if position + 1 == A1_VISIBLE_PHOTOS + A1_OVERSCAN_PHOTOS:
                    overscan_at_ms = elapsed_ms
            cold_total_ms = (time.perf_counter() - started) * 1000.0
            if sum_field(cold, "http_requests") < A1_MEDIA_PHOTOS:
                raise AssertionError("Cold test did not send independent native GETs.")

            # Production viewer RAM methods are called without a Tk widget.
            ram_hits = 0
            ram_times: list[float] = []
            for photo in photos:
                hit_started = time.perf_counter()
                image = memory._cached_thumbnail(photo)
                ram_times.append((time.perf_counter() - hit_started) * 1000.0)
                if image is not None:
                    ram_hits += 1
                    image.close()
            if ram_hits != A1_MEDIA_PHOTOS:
                raise AssertionError("Decoded RAM cache did not retain all sample tiles.")

            # Revalidate current descriptors before the warm disk session.
            memory.close_probe()
            memory = None
            first.close()
            second = new_session(issuer, token, tree=True)
            try:
                current, warm_metadata = load_photos(second, gallery_id)
                if tuple(p.image_id for p in current) != tuple(
                    p.image_id for p in photos
                ):
                    raise AssertionError("Unexpected descriptor identity mutation.")
                warm: list[dict] = [
                    read_photo(second, gallery_id, photo, i, None)
                    for i, photo in enumerate(current)
                ]
                if sum_field(warm, "http_requests") != 0:
                    raise AssertionError("Fresh warm-disk thumbnails reissued HTTP GETs.")
                if sum(row["source"] == "disk" for row in warm) != A1_MEDIA_PHOTOS:
                    raise AssertionError("Warm disk-cache accepted fewer than 120 hits.")

                # A no-change metadata refresh must not invalidate current data.
                refreshed, refresh_metadata = load_photos(second, gallery_id)
                if tuple(p.image_id for p in refreshed) != tuple(
                    p.image_id for p in current
                ):
                    raise AssertionError("Unchanged album unexpectedly changed IDs.")
                refreshed_read = read_photo(
                    second, gallery_id, refreshed[0], 0, None,
                )
                return {
                    "status": "PASS",
                    "corpus": {
                        "total_photos": len(photos),
                        "logical_visible": A1_VISIBLE_PHOTOS,
                        "logical_overscan": A1_OVERSCAN_PHOTOS,
                        "gui_rendered": False,
                    },
                    "cold_metadata": cold_metadata,
                    "warm_metadata": warm_metadata,
                    "refresh_unchanged_metadata": refresh_metadata,
                    "cold": {
                        **phase_summary(cold),
                        "first_decoded_available_ms": round(ready_at_ms, 6),
                        "visible_decoded_complete_ms": round(viewport_at_ms, 6),
                        "overscan_decoded_complete_ms": round(overscan_at_ms, 6),
                        "all_decoded_complete_ms": round(cold_total_ms, 6),
                    },
                    "ram": {
                        "hits": ram_hits,
                        "lookups": len(photos),
                        "elapsed": summarize(ram_times),
                        "additional_http_requests": 0,
                        "memory_owner": "real _NativeGalleryViewer RAM methods",
                    },
                    "disk": phase_summary(warm),
                    "unchanged_refresh_first_photo": refreshed_read,
                    "requests_reduced_percent": round(
                        100 * (
                            1 - sum_field(warm, "http_requests")
                            / sum_field(cold, "http_requests")
                        ), 6,
                    ),
                    "limits": [
                        "Real LinkingSession/HTTP, media cache, viewer decode/RAM methods.",
                        "Logical viewport is not Tk geometry or real first paint.",
                        "No Windows PhotosWorkspace widget or Watcher upload here.",
                        "No photo addition, removal or validator change tested.",
                        "PHP SQL/file phases and WEDOS capacity remain UNKNOWN.",
                    ],
                    "raw_samples": {"cold": cold, "warm_disk": warm},
                }
            finally:
                second.close()
    finally:
        if memory is not None:
            memory.close_probe()
        first.close()



# Purpose: Bound live linked GUI updates on the owned Xvfb display.
# Type: float. Units: seconds. Scope: A1 connected native viewer scenario.
# Consumers: measure_gui. Rationale: 45 s covers HTTP/metadata/visible bytes
# with margin over the 120-item logical transport baseline.
A1_GUI_DEADLINE_SECONDS = 45.0

# Purpose: Yield between individual native Tk event pump observations.
# Type: float. Units: seconds. Scope: A1 connected GUI measurements.
# Consumers: measure_gui. Rationale: 10 ms permits background worker progress.
A1_GUI_EVENT_SLEEP_SECONDS = 0.01


def measure_gui(
    issuer: str, token: str, gallery_id: int,
) -> dict[str, object]:
    """Run the actual native viewer under Xvfb against PHP and MariaDB.

    Args:
        issuer (str): Validated owned loopback PHP fixture.
        token (str): Synthetic bearer never persisted to diagnostic output.
        gallery_id (int): Existing physical fixture gallery, not a fabricated ID.

    Returns:
        dict[str, object]: Current viewport, Tk PhotoImage and event-loop
            measurements; no monitor scan-out or Windows claims.

    Raises:
        AssertionError: The genuine viewer fails to attach admitted thumbnails.
    """
    with tempfile.TemporaryDirectory(prefix="a1-connected-gui-") as root:
        os.environ["XDG_CACHE_HOME"] = root
        os.environ.pop("LOCALAPPDATA", None)
        session = new_session(issuer, token, tree=True)
        window = None
        viewer = None
        try:
            # Resolve the real descriptor before building a Tk widget.
            page = session.gallery_page()
            gallery = next(
                (entry for entry in page.galleries
                 if entry.gallery_id == gallery_id), None,
            )
            if gallery is None:
                raise AssertionError("GUI fixture gallery was not discovered.")
            window = tk.Tk()
            window.title("A1 disposable linked viewport")
            window.geometry("860x620")
            viewer = _NativeGalleryViewer(
                window, session, gallery, lambda: "en",
                show_title=False,
            )
            viewer.frame.pack(fill="both", expand=True)
            started = time.perf_counter()
            first_image_ms = None
            visible_ready_ms = None
            callbacks: list[float] = []
            peak_ms = 0.0
            deadline = time.monotonic() + A1_GUI_DEADLINE_SECONDS
            while time.monotonic() < deadline:
                tick = time.perf_counter()
                window.update()
                callback_ms = (time.perf_counter() - tick) * 1000.0
                callbacks.append(callback_ms)
                peak_ms = max(peak_ms, callback_ms)
                elapsed_ms = (time.perf_counter() - started) * 1000.0
                if (first_image_ms is None
                        and viewer._thumbnail_image_create_count > 0):
                    first_image_ms = elapsed_ms
                visible = viewer._visible_thumbnail_indices
                if (visible and visible.issubset(viewer._thumbnail_loaded)
                        and viewer._thumbnail_image_create_count > 0):
                    visible_ready_ms = elapsed_ms
                    break
                threading.Event().wait(A1_GUI_EVENT_SLEEP_SECONDS)
            if (first_image_ms is None or visible_ready_ms is None
                    or not viewer._visible_thumbnail_indices):
                raise AssertionError("Real linked Tk viewport did not become ready.")
            window.update_idletasks()
            return {
                "status": "PASS",
                "actual_viewer": "_NativeGalleryViewer",
                "tk_owner_thread": threading.current_thread().name,
                "first_tk_photoimage_ms": round(first_image_ms, 6),
                "visible_gui_ready_ms": round(visible_ready_ms, 6),
                "visible_photo_count": len(viewer._visible_thumbnail_indices),
                "admitted_photo_count": len(viewer._admitted_thumbnail_indices),
                "loaded_admitted_count": len(
                    viewer._admitted_thumbnail_indices
                    & viewer._thumbnail_loaded
                ),
                "tk_photoimage_creations": viewer._thumbnail_image_create_count,
                "tk_thumbnail_apply_total_ms": round(
                    viewer._thumbnail_tk_ms, 6,
                ),
                "thumbnail_gui_queue_wait_total_ms": round(
                    viewer._thumbnail_queue_wait_ms, 6,
                ),
                "viewport_callback_peak_ms": round(
                    viewer._viewport_peak_update_ms, 6,
                ),
                "event_pump_callback_max_ms": round(peak_ms, 6),
                "event_pump": summarize(callbacks),
                "cache_http_thumbnail_get_count": int(
                    viewer._thumbnail_transport_totals["http_requests"]
                ),
                "limits": [
                    "Real Tk/Pillow widgets, production WinApp viewer and PHP.",
                    "Xvfb has no physical monitor; PhotoImage is not scan-out.",
                    "A single viewport on Ubuntu is not Windows desktop paint.",
                    "Tk update() includes all ready callbacks, not only images.",
                ],
            }
        finally:
            if viewer is not None:
                viewer.close()
            if window is not None:
                window.destroy()
            session.close()


# Purpose: Bound the isolated real PHP scanner/deletion child.
# Type: int. Units: seconds. Scope: one A1 database mutation.
# Consumers: _mutate_fixture. Rationale: 30 s exceeds one tiny gallery scan.
A1_MUTATION_TIMEOUT_SECONDS = 30


def _mutate_fixture(action: str) -> None:
    """Request one owned source mutation through the PHP production owner.

    Args:
        action (str): Fixed small/large replacement, addition or removal.

    Returns:
        None: No state advances until the real mutation child succeeds.

    Raises:
        AssertionError: Missing fixture authority or mutation refusal.
    """
    fixture = os.environ.get("GALLERY_WORKFLOW_FIXTURE")
    nonce = os.environ.get("A1_CLIENT_FIXTURE_NONCE")
    if (not fixture or not nonce
            or action not in ("replace", "replace_large", "add", "remove")):
        raise AssertionError("Owned fixture mutation is not authorized.")
    result = subprocess.run(
        ["php", str(Path(__file__).with_name("issue_362_a1_mutate.php")),
         fixture, nonce, action],
        capture_output=True, check=False,
        timeout=A1_MUTATION_TIMEOUT_SECONDS,
    )
    if result.returncode:
        raise AssertionError("Actual PHP scanner/deletion mutation failed.")


def _load_all_current_photos(
    session: LinkingSession, gallery_id: int,
) -> tuple[tuple[LinkedPhoto, ...], int]:
    """Collect every current authorized image, with bounded metadata pages.

    Args:
        session (LinkingSession): Real bearer/HTTP session.
        gallery_id (int): Physical owned gallery identifier.

    Returns:
        tuple[tuple[LinkedPhoto, ...], int]: Fresh descriptors and page count.

    Raises:
        AssertionError: The server returns an endless page continuation.
    """
    photos: list[LinkedPhoto] = []
    cursor: int | None = None
    for page_count in range(1, A1_MAX_MEDIA_PAGES + 1):
        page = session.media_page(gallery_id, cursor)
        photos.extend(page.photos)
        if page.next_after is None:
            return tuple(photos), page_count
        cursor = page.next_after
    raise AssertionError("Photo metadata traversal exceeded its limit.")


def _actual_thumbnail_content(
    session: LinkingSession, gallery_id: int, photo: LinkedPhoto,
) -> tuple[bytes, MediaReadTimings]:
    """Return bytes and transport evidence from the actual cached client path.

    Args:
        session (LinkingSession): Current owned bearer and cache transport.
        gallery_id (int): Authorized disposable album.
        photo (LinkedPhoto): Latest authenticated page descriptor.

    Returns:
        tuple[bytes, MediaReadTimings]: Actual image and counted cache/network
            work; no client body is persisted in the hosted artifact.
    """
    timing = MediaReadTimings()
    body, mime = session.media_photo(
        gallery_id, photo.image_id, True,
        cache_eligible=photo.cache_eligible,
        thumbnail_etag=photo.thumbnail_etag,
        thumbnail_verified_at=photo.thumbnail_verified_at,
        preload_eligible=photo.preload_eligible,
        preload_thumbnail_etag=photo.preload_thumbnail_etag,
        preload_verified_at=photo.preload_verified_at,
        timings=timing,
    )
    if not body or not mime.startswith("image/"):
        raise AssertionError("Actual thumbnail body was not accepted.")
    return body, timing


def _authoritative_thumbnail_content(
    session: LinkingSession, gallery_id: int, image_id: int,
) -> tuple[bytes, str]:
    """Read a current PHP thumbnail independently of every WinApp cache.

    Args:
        session (LinkingSession): Actual bearer and production HTTP transport.
        gallery_id (int): Authorized disposable album identity.
        image_id (int): Current physical scanner image identity.

    Returns:
        tuple[bytes, str]: Direct authorized response bytes and their SHA-256;
            the server-supplied strong ETag must match exactly when present.
    """
    body, mime, etag, _cacheable = session._media_read(
        gallery_id, "thumbnail", image_id, None,
    )
    if body is None or not mime.startswith("image/"):
        raise AssertionError("Independent authorized image is missing.")
    digest = hashlib.sha256(body).hexdigest()
    if etag is not None and etag != '"' + digest + '"':
        raise AssertionError("Authoritative media validator disagrees with its body.")
    return body, digest


def _assert_ram_content(
    memory: _MemoryProbe, photo: LinkedPhoto, authoritative: bytes,
    *, remember: bool,
) -> bool:
    """Compare actual decoded cached pixels to independently sourced bytes.

    Args:
        memory (_MemoryProbe): Production viewer RAM/decode method owner.
        photo (LinkedPhoto): Exact current authorized thumbnail descriptor.
        authoritative (bytes): Independently fetched authorized PHP image.
        remember (bool): Whether to insert a verified newly decoded candidate.

    Returns:
        bool: True only when a RAM copy exists and every decoded pixel matches.
    """
    if remember:
        memory._remember_thumbnail(
            photo, memory._prepare_thumbnail(authoritative),
        )
    actual = memory._cached_thumbnail(photo)
    expected = memory._prepare_thumbnail(authoritative)
    try:
        return (
            actual is not None and actual.size == expected.size
            and actual.tobytes() == expected.tobytes()
        )
    finally:
        expected.close()
        if actual is not None:
            actual.close()


def measure_mutations(
    issuer: str, token: str, gallery_id: int,
) -> dict[str, object]:
    """Prove exact image bytes after real scanner mutations and cache refresh.

    Args:
        issuer (str): Genuine disposable localhost origin.
        token (str): Fresh read-only synthetic bearer.
        gallery_id (int): Authorized physical fixture album.

    Returns:
        dict[str, object]: Independent SHA-256, real client cache/RAM,
            addition/deletion and stale-descriptor refusal evidence.

    Raises:
        AssertionError: Any stale body, missing validator, unexpected GET or
            unauthorized cache/metadata reuse.
    """
    with tempfile.TemporaryDirectory(prefix="a1-mutated-photos-") as cache_root:
        os.environ["XDG_CACHE_HOME"] = cache_root
        os.environ.pop("LOCALAPPDATA", None)
        session = new_session(issuer, token, tree=True)
        memory: _MemoryProbe | None = None
        try:
            initial, initial_pages = _load_all_current_photos(session, gallery_id)
            changed = next(p for p in initial if p.title == "a1-photo-000.jpg")
            stable = next(p for p in initial if p.title == "a1-photo-001.jpg")
            removed = next(p for p in initial if p.title == "a1-photo-119.jpg")
            memory = _MemoryProbe(session, gallery_id, initial)

            original, original_timing = _actual_thumbnail_content(
                session, gallery_id, changed,
            )
            _original_server, original_sha = _authoritative_thumbnail_content(
                session, gallery_id, changed.image_id,
            )
            if hashlib.sha256(original).hexdigest() != original_sha:
                raise AssertionError("Original cached bytes differed from PHP.")
            if changed.thumbnail_etag != '"' + original_sha + '"':
                raise AssertionError("Small original fallback lacks its strong ETag.")
            if not _assert_ram_content(memory, changed, original, remember=True):
                raise AssertionError("Initial decoded RAM body was not preserved.")
            stable_original, stable_first_timing = _actual_thumbnail_content(
                session, gallery_id, stable,
            )

            _mutate_fixture("replace")
            replaced, replacement_pages = _load_all_current_photos(
                session, gallery_id,
            )
            changed_now = next(
                p for p in replaced if p.image_id == changed.image_id
            )
            stable_now = next(
                p for p in replaced if p.image_id == stable.image_id
            )
            expected_small, small_sha = _authoritative_thumbnail_content(
                session, gallery_id, changed.image_id,
            )
            if small_sha == original_sha:
                raise AssertionError("Physical source mutation changed no bytes.")
            if changed_now.thumbnail_etag != '"' + small_sha + '"':
                raise AssertionError("Refreshed small fallback validator is stale.")
            memory._photos = replaced
            if memory._cached_thumbnail(changed_now) is not None:
                raise AssertionError("Stale first RAM pixels survived mutation.")
            new_body, new_timing = _actual_thumbnail_content(
                session, gallery_id, changed_now,
            )
            if (hashlib.sha256(new_body).hexdigest() != small_sha
                    or new_timing.http_requests != 1
                    or new_timing.source != "network"):
                raise AssertionError("Changed image was returned from stale disk.")
            if not _assert_ram_content(
                memory, changed_now, expected_small, remember=True,
            ):
                raise AssertionError("Changed image reached RAM with wrong pixels.")
            same_body, same_timing = _actual_thumbnail_content(
                session, gallery_id, stable_now,
            )
            if (same_body != stable_original
                    or same_timing.http_requests != 0):
                raise AssertionError("Unchanged photo lost its valid disk cache.")
            prior_strong_body, prior_strong_timing = _actual_thumbnail_content(
                session, gallery_id, stable,
            )
            if (prior_strong_body != stable_original
                    or prior_strong_timing.http_requests != 0):
                raise AssertionError(
                    "Current strong ETag failed to preserve unchanged old-page bytes."
                )

            # An intentionally large original has no metadata ETag by design.
            # A fresh page must require a conditional read, then preserve its
            # confirmed body for subsequent same-epoch disk and RAM hits.
            _mutate_fixture("replace_large")
            large_pages, large_page_count = _load_all_current_photos(
                session, gallery_id,
            )
            large = next(p for p in large_pages if p.image_id == changed.image_id)
            if large.thumbnail_etag is not None:
                raise AssertionError("Over-budget original was hashed as metadata.")
            expected_large, large_sha = _authoritative_thumbnail_content(
                session, gallery_id, changed.image_id,
            )
            if large_sha in (original_sha, small_sha):
                raise AssertionError("Second physical mutation changed no bytes.")
            memory._photos = large_pages
            if memory._cached_thumbnail(large) is not None:
                raise AssertionError("Unproven large fallback reused old RAM.")
            large_body, large_timing = _actual_thumbnail_content(
                session, gallery_id, large,
            )
            if (hashlib.sha256(large_body).hexdigest() != large_sha
                    or large_timing.http_requests != 1
                    or large_timing.source != "network"):
                raise AssertionError("Unproven changed original used stale disk.")
            if not _assert_ram_content(
                memory, large, expected_large, remember=True,
            ):
                raise AssertionError("New large original was not decoded correctly.")
            identical_body, identical_timing = _actual_thumbnail_content(
                session, gallery_id, large,
            )
            if (identical_body != expected_large
                    or identical_timing.http_requests != 0):
                raise AssertionError("Confirmed same-page fallback did not reuse disk.")

            refreshed, refreshed_pages = _load_all_current_photos(
                session, gallery_id,
            )
            same_large = next(
                p for p in refreshed if p.image_id == changed.image_id
            )
            memory._photos = refreshed
            if memory._cached_thumbnail(same_large) is not None:
                raise AssertionError("New unproven page retained outdated RAM epoch.")
            check_body, check_timing = _actual_thumbnail_content(
                session, gallery_id, same_large,
            )
            if (check_body != expected_large
                    or check_timing.http_requests != 1
                    or check_timing.source != "revalidated"):
                raise AssertionError("New page failed conditional 304 revalidation.")
            if not _assert_ram_content(
                memory, same_large, expected_large, remember=True,
            ):
                raise AssertionError("Decoded RAM was stale after 304.")
            stable_refreshed = next(
                p for p in refreshed if p.image_id == stable.image_id
            )
            stable_reused, stable_after_timing = _actual_thumbnail_content(
                session, gallery_id, stable_refreshed,
            )
            if (stable_reused != stable_original
                    or stable_after_timing.http_requests != 0):
                raise AssertionError("Strong unchanged proof lost disk reuse.")

            # Fresh sessions cannot inherit the previous page's no-ETag
            # confirmation, even though they can hash-verify the disk bytes.
            second = new_session(issuer, token, tree=True)
            try:
                second_rows, second_pages = _load_all_current_photos(
                    second, gallery_id,
                )
                second_large = next(
                    p for p in second_rows if p.image_id == changed.image_id
                )
                second_body, second_timing = _actual_thumbnail_content(
                    second, gallery_id, second_large,
                )
                if (second_body != expected_large
                        or second_timing.http_requests != 1):
                    raise AssertionError("New session accepted old page authority.")
            finally:
                second.close()

            _mutate_fixture("add")
            added, addition_pages = _load_all_current_photos(
                session, gallery_id,
            )
            additions = [p for p in added if p.title == "a1-photo-120.jpg"]
            if len(added) != len(initial) + 1 or len(additions) != 1:
                raise AssertionError("Actual scanner failed to add one photo.")
            added_body, added_timing = _actual_thumbnail_content(
                session, gallery_id, additions[0],
            )
            authoritative_added, added_sha = _authoritative_thumbnail_content(
                session, gallery_id, additions[0].image_id,
            )
            if (added_timing.http_requests != 1
                    or hashlib.sha256(added_body).hexdigest() != added_sha
                    or added_body != authoritative_added):
                raise AssertionError("New physical photo was not downloaded once.")
            # Ensure an old descriptor is not silently admitted from disk
            # after its database row and filesystem file are deleted.
            _removed_body, removed_timing = _actual_thumbnail_content(
                session, gallery_id, next(
                    p for p in added if p.image_id == removed.image_id
                ),
            )
            _mutate_fixture("remove")
            final, removal_pages = _load_all_current_photos(session, gallery_id)
            if (len(final) != len(initial)
                    or any(p.image_id == removed.image_id for p in final)):
                raise AssertionError("Admin deletion left a stale catalogue row.")
            stale_refused = False
            stale_timing = MediaReadTimings()
            try:
                session.media_photo(
                    gallery_id, removed.image_id, True,
                    cache_eligible=removed.cache_eligible,
                    thumbnail_etag=removed.thumbnail_etag,
                    thumbnail_verified_at=removed.thumbnail_verified_at,
                    timings=stale_timing,
                )
            except LinkingError:
                stale_refused = True
            if not stale_refused or stale_timing.http_requests != 0:
                raise AssertionError("Deleted stale descriptor was locally accepted.")
            binary_refused = False
            try:
                session._media_read(gallery_id, "thumbnail", removed.image_id, None)
            except LinkingError:
                binary_refused = True
            if not binary_refused:
                raise AssertionError("Deleted image remained authorized on PHP.")
            return {
                "status": "PASS",
                "initial_images": len(initial),
                "replaced_images": len(replaced),
                "added_images": len(added),
                "remaining_images": len(final),
                "original_sha256": original_sha,
                "replacement_sha256": small_sha,
                "large_fallback_sha256": large_sha,
                "added_sha256": added_sha,
                "original_changed_network": original_timing.http_requests,
                "replacement_network": new_timing.http_requests,
                "replacement_source": new_timing.source,
                "replacement_etag_present_before": changed.thumbnail_etag is not None,
                "replacement_etag_present_after": changed_now.thumbnail_etag is not None,
                "large_fallback_metadata_etag": large.thumbnail_etag,
                "large_fallback_network": large_timing.http_requests,
                "large_fallback_same_epoch_disk_get": identical_timing.http_requests,
                "large_fallback_new_epoch_revalidation": check_timing.http_requests,
                "large_fallback_new_epoch_source": check_timing.source,
                "large_fallback_new_session_get": second_timing.http_requests,
                "unchanged_after_mutation_network": same_timing.http_requests,
                "unchanged_strong_old_descriptor_get": (
                    prior_strong_timing.http_requests
                ),
                "unchanged_after_large_mutation_network": stable_after_timing.http_requests,
                "added_photo_network": added_timing.http_requests,
                "deleted_photo_initial_network": removed_timing.http_requests,
                "deleted_stale_descriptor_refused": stale_refused,
                "deleted_php_binary_refused": binary_refused,
                "decoded_ram_verified": True,
                "metadata_http_requests": (
                    initial_pages + replacement_pages + large_page_count
                    + refreshed_pages + second_pages + addition_pages
                    + removal_pages
                ),
                "limits": [
                    "True scanner and Admin deletion on disposable PHP and MariaDB.",
                    "Direct independent authorized binary SHA-256 versus client body.",
                    "Actual production viewer Pillow decoder and bounded RAM cache.",
                    "No physical Windows display or WEDOS infrastructure in fixture.",
                ],
            }
        finally:
            if memory is not None:
                memory.close_probe()
            session.close()


# Purpose: Prevent fixture fault and FIFO tests from waiting indefinitely.
# Type: float. Units: seconds. Scope: one owned A1 concurrent client test.
# Consumers: _wait_for_tickets and measure_fairness.
# Rationale: 5 seconds is much longer than thread scheduling in the runner.
A1_GATE_QUEUE_TIMEOUT_SECONDS = 5.0

# Purpose: Bound a synthetic blocked response without using socket timeouts.
# Type: float. Units: seconds. Scope: fault-injected slow thumbnail GET.
# Consumers: measure_fault. Rationale: 0.3 s is within the fixture's 1.5 s hold.
A1_CANCEL_DELAY_SECONDS = 0.3

# Purpose: Bound one cancelled client and subsequent cleanup.
# Type: float. Units: seconds. Scope: fault and fairness worker joins.
# Consumers: measure_fault and measure_fairness.
# Rationale: 8 seconds accommodates cancellation polling and server drain.
A1_THREAD_JOIN_SECONDS = 8.0


def _failure_probe(
    session: LinkingSession, gallery_id: int, photo: LinkedPhoto,
    cancelled: threading.Event, errors: list[LinkingError],
    timings: MediaReadTimings,
) -> None:
    """Attempt an actual media read, retaining only bounded diagnostic classes.

    Args:
        session (LinkingSession): Real bearer owner for the fixture.
        gallery_id (int): Authorized synthetic album identifier.
        photo (LinkedPhoto): Its first live validated media descriptor.
        cancelled (threading.Event): Caller-owned HTTP cancellation event.
        errors (list[LinkingError]): Single-threaded sink of observed refusals.
        timings (MediaReadTimings): Read owner for confirming an HTTP attempt.

    Returns:
        None: A genuine failed/cancelled request appends its safe exception.
    """
    try:
        session.media_photo(
            gallery_id, photo.image_id, True, cancelled,
            cache_eligible=photo.cache_eligible,
            thumbnail_etag=photo.thumbnail_etag,
            thumbnail_verified_at=photo.thumbnail_verified_at,
            timings=timings,
        )
    except LinkingError as error:
        errors.append(error)


def _wait_for_tickets(session: LinkingSession, needed: int) -> None:
    """Wait for actual FIFO tickets, never substituting a mocked admission.

    Args:
        session (LinkingSession): Real gate-owning session.
        needed (int): Minimum number of concurrently waiting real callers.

    Returns:
        None: The gate's protected queue reached the requested size.

    Raises:
        AssertionError: Bounded hosted scheduling could not create the queue.
    """
    deadline = time.monotonic() + A1_GATE_QUEUE_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        with session._request_gate._condition:
            current = len(session._request_gate._waiters)
        if current >= needed:
            return
        threading.Event().wait(0.01)
    raise AssertionError("Unable to observe queued real client requests.")


def measure_fault(
    issuer: str, token: str, gallery_id: int,
) -> dict[str, object]:
    """Exercise real WinApp responses against deterministic disposable router faults.

    Args:
        issuer (str): Existing owned localhost PHP origin.
        token (str): Valid synthetic read-only bearer.
        gallery_id (int): Isolated synthetic gallery identity.

    Returns:
        dict[str, object]: Verified 429/502/503, truncation, gate and read
            cancellation, and clean recovery, with no private file identity.

    Raises:
        AssertionError: Any unsafe successful or unrecoverable fault outcome.
    """
    fixture = Path(os.environ["GALLERY_WORKFLOW_FIXTURE"])
    fault_file = fixture / ".issue362-a1-fault-mode"
    results: list[dict[str, object]] = []
    with tempfile.TemporaryDirectory(prefix="a1-client-fault-") as root:
        os.environ["XDG_CACHE_HOME"] = root
        os.environ.pop("LOCALAPPDATA", None)
        session = new_session(issuer, token, tree=True)
        try:
            photos, metadata = load_photos(session, gallery_id)
            photo = photos[0]
            for status in (429, 502, 503):
                fault_file.write_text(str(status), encoding="ascii")
                try:
                    timing = MediaReadTimings()
                    started = time.perf_counter()
                    try:
                        session.media_photo(
                            gallery_id, photo.image_id, True,
                            cache_eligible=photo.cache_eligible,
                            thumbnail_etag=photo.thumbnail_etag,
                            thumbnail_verified_at=photo.thumbnail_verified_at,
                            timings=timing,
                        )
                    except LinkingResponseError as error:
                        if error.status != status:
                            raise AssertionError("Unexpected native fault status.") from None
                        results.append({
                            "injected_status": status, "client_status": error.status,
                            "native_reason": error.reason,
                            "http_requests": timing.http_requests,
                            "elapsed_ms": round(
                                (time.perf_counter() - started) * 1000.0, 6,
                            ),
                        })
                    else:
                        raise AssertionError("Native media erroneously accepted HTTP fault.")
                    if session.has_cached_photo(gallery_id, photo, thumbnail=True):
                        raise AssertionError("HTTP refusal was published in cache.")
                finally:
                    fault_file.unlink(missing_ok=True)
            # A hosting gateway may return an HTML 502/503 page instead of
            # the application's JSON refusal envelope. It must remain a
            # classified transport failure, not be decoded or cached as media.
            for status in (502, 503):
                fault_file.write_text(f"html_{status}", encoding="ascii")
                try:
                    timing = MediaReadTimings()
                    try:
                        session.media_photo(
                            gallery_id, photo.image_id, True,
                            cache_eligible=photo.cache_eligible,
                            thumbnail_etag=photo.thumbnail_etag,
                            thumbnail_verified_at=photo.thumbnail_verified_at,
                            timings=timing,
                        )
                    except LinkingResponseError as error:
                        if error.status != status:
                            raise AssertionError("Unexpected HTML gateway status.") from None
                        results.append({
                            "injected_status": f"html_{status}",
                            "client_status": error.status,
                            "native_reason": error.reason,
                            "http_requests": timing.http_requests,
                        })
                    else:
                        raise AssertionError("HTML gateway response became image data.")
                    if session.has_cached_photo(gallery_id, photo, thumbnail=True):
                        raise AssertionError("HTML response entered the image cache.")
                finally:
                    fault_file.unlink(missing_ok=True)
            fault_file.write_text("truncated", encoding="ascii")
            try:
                timing = MediaReadTimings()
                try:
                    session.media_photo(
                        gallery_id, photo.image_id, True,
                        cache_eligible=photo.cache_eligible,
                        thumbnail_etag=photo.thumbnail_etag,
                        thumbnail_verified_at=photo.thumbnail_verified_at,
                        timings=timing,
                    )
                except LinkingError as error:
                    results.append({
                        "injected_status": "truncated",
                        "client_error_class": type(error).__name__,
                        "http_requests": timing.http_requests,
                    })
                else:
                    raise AssertionError("Truncated binary response was accepted.")
                if session.has_cached_photo(gallery_id, photo, thumbnail=True):
                    raise AssertionError("Partial body was incorrectly cached.")
            finally:
                fault_file.unlink(missing_ok=True)

            # Cancellation while waiting for real FIFO admission must withdraw
            # its ticket before another gallery request can be admitted.
            gate_cancel = threading.Event()
            gate_errors: list[LinkingError] = []
            gate_timings = MediaReadTimings()
            if not session._request_gate.acquire(timeout=A1_GATE_QUEUE_TIMEOUT_SECONDS):
                raise AssertionError("Could not own the isolated cancellation gate.")
            try:
                queued = threading.Thread(
                    target=_failure_probe,
                    args=(
                        session, gallery_id, photo, gate_cancel,
                        gate_errors, gate_timings,
                    ),
                    daemon=True,
                )
                queued.start()
                _wait_for_tickets(session, 1)
                gate_cancel.set()
                queued.join(A1_THREAD_JOIN_SECONDS)
                if queued.is_alive() or len(gate_errors) != 1:
                    raise AssertionError("Cancelled FIFO waiter was not withdrawn.")
                if gate_timings.http_requests:
                    raise AssertionError("Cancelled FIFO waiter sent a network request.")
            finally:
                session._request_gate.release()

            # The server deliberately holds its response before the original
            # authorized route. Cancelling interrupts the live native HTTP read.
            fault_file.write_text("slow", encoding="ascii")
            try:
                read_cancel = threading.Event()
                read_errors: list[LinkingError] = []
                read_timings = MediaReadTimings()
                active = threading.Thread(
                    target=_failure_probe,
                    args=(
                        session, gallery_id, photo, read_cancel,
                        read_errors, read_timings,
                    ),
                    daemon=True,
                )
                active.start()
                threading.Event().wait(A1_CANCEL_DELAY_SECONDS)
                read_cancel.set()
                active.join(A1_THREAD_JOIN_SECONDS)
                if (active.is_alive() or len(read_errors) != 1
                        or read_timings.http_requests == 0):
                    raise AssertionError("Active native HTTP read did not cancel safely.")
                results.append({
                    "injected_status": "slow_cancel",
                    "client_error_class": type(read_errors[0]).__name__,
                    "http_requests": read_timings.http_requests,
                })
            finally:
                fault_file.unlink(missing_ok=True)

            if session.has_cached_photo(gallery_id, photo, thumbnail=True):
                raise AssertionError("Cancellation produced an unexpected cache hit.")
            recovered = MediaReadTimings()
            body, _mime = session.media_photo(
                gallery_id, photo.image_id, True,
                cache_eligible=photo.cache_eligible,
                thumbnail_etag=photo.thumbnail_etag,
                thumbnail_verified_at=photo.thumbnail_verified_at,
                timings=recovered,
            )
            if not body or recovered.http_requests == 0:
                raise AssertionError("Healthy authorized media did not recover.")
            return {
                "status": "PASS",
                "metadata": metadata,
                "faults": results,
                "gate_cancel_sent_http": gate_timings.http_requests,
                "recovery_source": recovered.source,
                "recovery_http_requests": recovered.http_requests,
                "limits": [
                    "Faults originate in the OWNED copied PHP router, not WEDOS.",
                    "Failures exercise real WinApp HTTP/parser/cache handling.",
                    "No application-level preload retry/backoff timing verified.",
                    "No authentication relaxation in the production PHP router.",
                ],
            }
        finally:
            fault_file.unlink(missing_ok=True)
            session.close()


def _fairness_worker(
    session: LinkingSession, gallery_id: int, action: str,
    photo: LinkedPhoto | None, completed: list[dict[str, object]],
    errors: list[str], lock: threading.Lock,
    upload_source: Path | None, upload_ids: list[int],
) -> None:
    """Execute one real shared-session transport operation in a worker.

    Args:
        session (LinkingSession): Shared real authorization and FIFO owner.
        gallery_id (int): Exact fixture album identifier.
        action (str): One fixed media, metadata or Watcher inventory operation.
        photo (LinkedPhoto | None): Real photo descriptor for media operations.
        completed (list[dict[str, object]]): Sanitized timed completion ledger.
        errors (list[str]): Allowlisted action names that failed.
        lock (threading.Lock): Shared ledger protection.
        upload_source (Path | None): One synthetic one-shot JPEG source.
        upload_ids (list[int]): Private in-memory server-acknowledged IDs.

    Returns:
        None: Captures measured completion without exposing token or body.
    """
    started = time.perf_counter()
    timing = MediaReadTimings()
    uploaded_count = 0
    try:
        if action in ("preload_first", "preload_second") and photo is not None:
            data, _mime = session.media_photo(
                gallery_id, photo.image_id, True,
                cache_eligible=photo.cache_eligible,
                thumbnail_etag=photo.thumbnail_etag,
                thumbnail_verified_at=photo.thumbnail_verified_at,
                timings=timing,
            )
            if not data:
                raise AssertionError("Thumbnail did not return content.")
        elif action == "multipart_upload" and upload_source is not None:
            digest = hashlib.sha256(upload_source.read_bytes()).hexdigest()
            body = LinkedMultipart(
                gallery_id, upload_source, False, digest,
            )
            result = session.upload_multipart(
                gallery_id, body, body.boundary, body.content_length,
            )
            received_ids = result["image_ids"]
            if not isinstance(received_ids, list) or len(received_ids) != 1:
                raise AssertionError("Unconfirmed physical upload.")
            upload_ids.extend(received_ids)
            uploaded_count = 1
        elif action == "watcher_inventory":
            inventory = session.inventory(gallery_id, [])
            if inventory.get("ok") is not True:
                raise AssertionError("Authenticated Watcher inventory failed.")
        elif action == "catalogue_refresh":
            page = session.media_page(gallery_id, timings=timing)
            if not page.photos:
                raise AssertionError("Authenticated metadata refresh was empty.")
        else:
            raise AssertionError("Invalid queued operation in isolated fairness test.")
        with lock:
            completed.append({
                "action": action,
                "elapsed_ms": round(
                    (time.perf_counter() - started) * 1000.0, 6,
                ),
                "media_gate_wait_ms": round(timing.gate_wait_ms, 6),
                "http_requests_from_media_timing": timing.http_requests,
                "uploaded_images": uploaded_count,
            })
    except Exception:
        with lock:
            errors.append(action)


def measure_fairness(
    issuer: str, token: str, gallery_id: int,
) -> dict[str, object]:
    """Verify a real Watcher inventory is admitted ahead of later thumbnail work.

    Args:
        issuer (str): Owned loopback PHP application issuer.
        token (str): Actual upload-plus-media scoped disposable bearer.
        gallery_id (int): Real isolated seeded gallery identifier.

    Returns:
        dict[str, object]: Measured serialized FIFO admission and Watcher latency.

    Raises:
        AssertionError: Permission failure, starvation or unexpected queue order.
    """
    with tempfile.TemporaryDirectory(prefix="a1-client-fairness-") as root:
        os.environ["XDG_CACHE_HOME"] = root
        os.environ.pop("LOCALAPPDATA", None)
        session = LinkingSession(
            issuer, token, time.monotonic() + A1_SESSION_LIFETIME_SECONDS,
            scope=UPLOAD_SCOPE + MEDIA_SCOPE_SUFFIX,
            gallery_tree_supported=True,
        )
        try:
            photos, metadata = load_photos(session, gallery_id)
            upload_source = Path(root) / "a1-fairness-upload.jpg"
            synthetic = Image.new("RGB", (96, 64), color=(34, 117, 201))
            try:
                synthetic.save(upload_source, "JPEG")
            finally:
                synthetic.close()
            upload_ids: list[int] = []
            # Establish successful real upload-scope inventory before timing.
            inventory = session.inventory(gallery_id, [])
            if inventory.get("ok") is not True:
                raise AssertionError("Real Watcher inventory preflight failed.")
            gate = session._request_gate
            if not gate.acquire(timeout=A1_GATE_QUEUE_TIMEOUT_SECONDS):
                raise AssertionError("Could not own the actual FIFO session gate.")
            completed: list[dict[str, object]] = []
            errors: list[str] = []
            results_lock = threading.Lock()
            order = (
                ("preload_first", photos[0]),
                ("watcher_inventory", None),
                ("catalogue_refresh", None),
                ("multipart_upload", None),
                ("preload_second", photos[1]),
            )
            workers: list[threading.Thread] = []
            try:
                for position, (action, photo) in enumerate(order, 1):
                    worker = threading.Thread(
                        target=_fairness_worker,
                        args=(
                            session, gallery_id, action, photo,
                            completed, errors, results_lock,
                            upload_source if action == "multipart_upload" else None,
                            upload_ids,
                        ),
                        daemon=True,
                    )
                    workers.append(worker)
                    worker.start()
                    _wait_for_tickets(session, position)
            finally:
                gate.release()
            for worker in workers:
                worker.join(A1_THREAD_JOIN_SECONDS)
            unfinished = [
                action for (action, _photo), worker in zip(order, workers)
                if worker.is_alive()
            ]
            if unfinished or errors:
                # Only fixed action names, never exception messages or grant
                # metadata, may be exported for failure localization.
                failed_actions = sorted(set(unfinished + errors))
                raise AssertionError(
                    "A1_FAIRNESS_ACTIONS:" + ",".join(failed_actions)
                )
            names = [str(row["action"]) for row in completed]
            if names != [action for action, _photo in order]:
                raise AssertionError("Shared FIFO admitted Watcher/upload out of order.")
            if len(upload_ids) != 1 or sum(
                int(row["uploaded_images"]) for row in completed
            ) != 1:
                raise AssertionError("Multipart upload did not commit once.")
            all_photos: list[LinkedPhoto] = []
            after_id = None
            for _page_index in range(A1_MAX_MEDIA_PAGES):
                checked = session.media_page(gallery_id, after_id)
                all_photos.extend(checked.photos)
                after_id = checked.next_after
                if after_id is None:
                    break
            if after_id is not None or sum(
                photo.image_id == upload_ids[0] for photo in all_photos
            ) != 1:
                raise AssertionError("Uploaded photo did not appear exactly once.")
            watcher = next(
                row for row in completed if row["action"] == "watcher_inventory"
            )
            return {
                "status": "PASS",
                "metadata": metadata,
                "actual_queue_completion_order": names,
                "sampled_operations": completed,
                "watcher_end_to_end_ms": watcher["elapsed_ms"],
                "starvation_observed": False,
                "multipart_uploaded": 1,
                "uploaded_photo_visible_once": True,
                "limits": [
                    "Actual upload-scope WinApp inventory and two real photo GETs.",
                    "Single PHP CLI server and one shared WinApp FIFO lane.",
                    "Server-side concurrency is not a WEDOS worker measurement.",
                    "One actual authorized one-shot multipart upload was timed.",
                    "Separate Watcher-specific gate wait is not passively exposed.",
                ],
            }
        finally:
            session.close()


def main() -> int:
    """Run one safe A1 case and persist its sanitized exact-SHA measurements.

    Returns:
        int: Zero only when all scoped real-client assertions passed.
    """
    if (os.getenv("GITHUB_ACTIONS") != "true"
            or os.getenv("GALLERY_WORKFLOW_ENABLE") != "disposable-only"
            or len(sys.argv) != 3 or sys.argv[1] not in A1_MODES):
        raise RuntimeError("Disposable GitHub Actions A1 mode required.")
    mode, count_text = sys.argv[1:]
    if not count_text.isdecimal():
        raise RuntimeError("Invalid bounded case count.")
    count = int(count_text)
    issuer = os.getenv("A1_CLIENT_ISSUER", "")
    token = os.getenv("A1_CLIENT_BEARER", "")
    owner = Path(os.environ["GALLERY_WORKFLOW_FIXTURE"])
    owner_token = os.environ["GALLERY_WORKFLOW_TOKEN"]
    parsed = urlsplit(issuer)
    if (parsed.scheme != "http" or parsed.hostname != "localhost"
            or parsed.port is None or parsed.path or parsed.query
            or parsed.fragment or owner.joinpath(".workflow-owner").read_text(
                encoding="utf-8"
            ) != owner_token):
        raise RuntimeError("Nonowned loopback benchmark refused.")
    if mode == "tree" and count not in (500, 2000, 10000):
        raise RuntimeError("Unexpected tree fixture size.")
    if mode == "overflow" and count != 10001:
        raise RuntimeError("Unexpected overflow fixture size.")
    if mode in ("media", "fault", "fairness", "gui") and count <= 0:
        raise RuntimeError("Invalid isolated media target.")

    started = utc_now()
    record: dict[str, object] = {
        "schema": "php-gallery-a1-connected-client-v1",
        "scenario": mode if mode != "tree" else f"tree_{count}",
        "started_utc": started,
        "head_sha": os.getenv("GITHUB_SHA", "UNKNOWN"),
        "run_id": os.getenv("GITHUB_RUN_ID", "UNKNOWN"),
        "job": os.getenv("GITHUB_JOB", "UNKNOWN"),
        "os": sys.platform,
        "python": sys.version.split()[0],
        "client": "actual LinkingSession / GalleryMediaCache",
        "loopback": True,
    }
    suffix = mode if mode != "tree" else f"tree-{count}"
    path = Path("cache/test-audit") / f"issue-362-a1-client-{suffix}.json"
    path.parent.mkdir(parents=True, exist_ok=True)
    try:
        if mode == "media":
            record["measurements"] = measure_media(issuer, token, count)
        elif mode == "fault":
            record["measurements"] = measure_fault(issuer, token, count)
        elif mode == "gui":
            record["measurements"] = measure_gui(issuer, token, count)
        elif mode == "mutate":
            record["measurements"] = measure_mutations(issuer, token, count)
        elif mode == "fairness":
            watcher_token = os.getenv("A1_WATCHER_BEARER", "")
            if not watcher_token:
                raise AssertionError("Watcher fixture bearer was not created.")
            record["measurements"] = measure_fairness(issuer, watcher_token, count)
        elif mode == "overflow":
            record["measurements"] = measure_overflow(issuer, token)
        else:
            session = new_session(issuer, token, tree=True)
            try:
                record["measurements"] = measure_tree(session, count)
            finally:
                session.close()
        record["ended_utc"] = utc_now()
        path.write_text(json.dumps(record, indent=2) + "\n", encoding="utf-8")
        print("PASS gallery workflow A1 connected " + suffix.replace("-", " "))
        return 0
    except Exception as error:
        record["ended_utc"] = utc_now()
        # Export only safe code coordinates, not error messages, bearer
        # metadata, paths or fixture identifiers. This locates a failed
        # assertion in the exact checked-out source without leaking data.
        failed_at = error.__traceback__
        while failed_at is not None and failed_at.tb_next is not None:
            failed_at = failed_at.tb_next
        record["measurements"] = {
            "status": "FAIL", "exception_class": type(error).__name__,
            "failure_function": (
                failed_at.tb_frame.f_code.co_name
                if failed_at is not None else "UNKNOWN"
            ),
            "failure_line": (
                failed_at.tb_lineno if failed_at is not None else None
            ),
            "failure_source": (
                Path(failed_at.tb_frame.f_code.co_filename).name
                if failed_at is not None else "UNKNOWN"
            ),
            "failure_actions": (
                [
                    action for action in (
                        "preload_first", "watcher_inventory",
                        "catalogue_refresh", "multipart_upload",
                        "preload_second",
                    )
                    if action in str(error)
                ] if str(error).startswith("A1_FAIRNESS_ACTIONS:")
                else []
            ),
            "reason": "Sanitized source coordinate; no identity exported.",
        }
        path.write_text(json.dumps(record, indent=2) + "\n", encoding="utf-8")
        raise RuntimeError("A1 connected client assertion failed.") from None


if __name__ == "__main__":
    raise SystemExit(main())
