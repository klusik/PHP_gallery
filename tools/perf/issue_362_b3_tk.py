# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_b3_tk.py
# Module Type: Windows Native Viewer Benchmark
# Purpose: Observe real Tk thumbnail delivery over owned loopback HTTP.
# Responsibilities:
#   - Exercise actual session, parser, cache and viewer with disposable data.
#   - Separate cold single and batch reads from RAM-empty disk-warm returns.
#   - Export bounded numeric evidence without credentials or photo identities.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Run issue 362 native-viewer measurements only on exact-SHA Windows CI."""

import hashlib
import io
import json
import math
import os
import platform
import re
import socket
import sys
import tempfile
import threading
import time
import tkinter as tk
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from types import TracebackType
from urllib.parse import parse_qs, urlsplit

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'winapp'))

from PIL import Image, __version__ as PILLOW_VERSION
from uploader import app_linking as linking
from uploader import native_gallery_viewer as native
from uploader.thumbnail_batch_protocol import FRAMED_HEADER, FRAME_LENGTHS


# Purpose: Measure the requested realistic direct-photo cohort.
# Type: int. Units: photos. Scope: each cold or warm native traversal.
# Consumers: fixture pages and run_phase. Rationale: 120 matches the connected
# PHP benchmark while still exercising the real 64-item continuation boundary.
PHOTO_COUNT = 120

# Purpose: Bound all fixture phases before the external audit timeout.
# Type: float. Units: seconds. Scope: one benchmark process.
# Consumers: run_phase and main. Rationale: 120 seconds leaves teardown time
# inside the separate 180-second hosted audit owner.
WATCHDOG_SECONDS = 120.0

# Purpose: Give background network and cache workers an event-pump opportunity.
# Type: float. Units: seconds. Scope: one native Tk traversal iteration.
# Consumers: run_phase. Rationale: Ten milliseconds avoids busy polling without
# adding an artificial network round-trip delay.
PUMP_PAUSE_SECONDS = 0.01

# Purpose: Bound retained per-callback timing samples independently of runtime.
# Type: int. Units: observations. Scope: one benchmark phase and callback kind.
# Consumers: run_phase. Rationale: 4096 retains representative GUI work while
# preventing repeated event-loop pumping from growing the artifact unboundedly.
CALLBACK_SAMPLE_LIMIT = 4096

# Purpose: Avoid misleading tail statistics from tiny callback populations.
# Type: int. Units: callback observations. Scope: p95 and p99 reporting.
# Consumers: summarize. Rationale: At least 100 samples retain a visible tail.
TAIL_MIN_SAMPLES = 100

# Purpose: Keep synthetic session authority live during the bounded benchmark.
# Type: float. Units: seconds. Scope: one fixture session.
# Consumers: run_scenario. Rationale: 600 exceeds the 120-second watchdog and
# is not used to extend the independent catalogue proof freshness window.
SESSION_LIFETIME_SECONDS = 600.0

BatchPhase = dict[
    str, int | float | str | None | list[float]
    | dict[str, int | float | None] | dict[int, int],
]
CompletedPhase = dict[str, str | BatchPhase]

# Purpose: Use one reproducible real native viewport across all scenarios.
# Type: tuple[int, int]. Units: Tk logical pixels. Scope: benchmark root window.
# Consumers: run_phase. Rationale: 860 by 620 exercises multiple visible rows.
VIEWPORT_SIZE = (860, 620)

# Purpose: Keep independently encoded JPEG fixtures small and realistic.
# Type: int. Units: pixels per side. Scope: each synthetic thumbnail body.
# Consumers: main. Rationale: 48 pixels limits fixture generation and transfer.
JPEG_SIDE = 48

# Purpose: Bound orderly shutdown of the owned loopback server thread.
# Type: float. Units: seconds. Scope: benchmark process cleanup.
# Consumers: main. Rationale: Two seconds permits server shutdown without an
# unbounded join after the internal traversal watchdog expires.
SERVER_JOIN_SECONDS = 2.0

# Purpose: Bound a fixture's accepted batch request before JSON decoding.
# Type: int. Units: bytes. Scope: one local framed-thumbnail POST body.
# Consumers: FixtureHandler.do_POST. Rationale: Sixteen KiB mirrors the v1
# server admission ceiling without expanding the production parser contract.
FIXTURE_REQUEST_MAX_BYTES = 16 * 1024


class FixtureServer(ThreadingHTTPServer):
    """Own ephemeral public media and numeric request counters on loopback."""

    daemon_threads = True

    def __init__(self, photos: tuple[bytes, ...], family: int = socket.AF_INET) -> None:
        """Bind a new localhost fixture without contacting external services.

        Args:
            photos (tuple[bytes, ...]): Ordered in-memory JPEG fixture bodies.
            family (int): IPv4 or IPv6 loopback socket family.

        Returns:
            None: Creates isolated server state and numeric counters.
        """
        if family not in (socket.AF_INET, socket.AF_INET6):
            raise ValueError('Unsupported fixture family.')
        self.address_family = family
        host = '::1' if family == socket.AF_INET6 else '127.0.0.1'
        super().__init__((host, 0), FixtureHandler)
        self.photos = photos
        self.lock = threading.Lock()
        self.batch_enabled = False
        self.gets = 0
        self.posts = 0
        self.media_bytes = 0
        self.metadata_requests = 0
        self.catalogue_sizes: dict[int, int] = {}
        self.batch_sizes: dict[int, int] = {}
        self.active = 0
        self.peak_active = 0

    def counters(self) -> dict[str, int | dict[int, int]]:
        """Return a numeric copy without disclosing selectors or addresses.

        Returns:
            dict[str, int | dict[int, int]]: Fixed transport counters and sizes.
        """
        with self.lock:
            return {'thumbnail_gets': self.gets, 'thumbnail_posts': self.posts,
                    'thumbnail_bytes': self.media_bytes,
                    'metadata_requests': self.metadata_requests,
                    'catalogue_sizes': dict(self.catalogue_sizes),
                    'batch_sizes': dict(self.batch_sizes),
                    'peak_handler_intervals': self.peak_active}

    def reset_counters(self) -> None:
        """Start the next measured phase without altering any cache state.

        Returns:
            None: Clears only fixture-owned in-memory request counters.
        """
        with self.lock:
            self.gets = self.posts = self.media_bytes = self.metadata_requests = 0
            self.batch_sizes.clear()
            self.catalogue_sizes.clear()
            self.peak_active = self.active

    def handle_error(self, request: socket.socket, client_address: tuple[str, int]) -> None:
        """Suppress exception tracebacks that could expose fixture addresses.

        Args:
            request (socket.socket): Socket accepted by the local fixture server.
            client_address (tuple[str, int]): Local peer address, never logged.

        Returns:
            None: A failed handler remains visible through missing evidence.
        """
        del request, client_address


class FixtureHandler(BaseHTTPRequestHandler):
    """Serve strict synthetic discovery, catalogue and thumbnail responses."""

    protocol_version = 'HTTP/1.1'

    def setup(self) -> None:
        """Bound local handler inactivity using the existing client policy.

        Returns:
            None: The owned socket receives the native transport timeout.
        """
        super().setup()
        self.connection.settimeout(linking.LINKING_SOCKET_TIMEOUT_SECONDS)

    def log_message(self, format: str, *args: str | int) -> None:
        """Suppress request paths and credentials from benchmark logs.

        Args:
            format (str): Standard server log format, intentionally unused.
            *args (str | int): Standard server log values, intentionally unused.

        Returns:
            None: Emits no request log.
        """
        del format, args

    def respond(self, body: bytes, mime: str, headers: dict[str, str]) -> None:
        """Emit one bounded owned response with an exact body length.

        Args:
            body (bytes): Complete synthetic response bytes.
            mime (str): Fixed response media type.
            headers (dict[str, str]): Fixed policy headers for this route.

        Returns:
            None: Writes the exact response to the local client.
        """
        self.send_response(200)
        self.send_header('Content-Type', mime)
        self.send_header('Content-Length', str(len(body)))
        for name, value in headers.items():
            self.send_header(name, value)
        self.end_headers()
        self.wfile.write(body)
        self.wfile.flush()

    def do_GET(self) -> None:
        """Serve fixed discovery, current metadata or one actual JPEG read.

        Returns:
            None: Responds only to known local fixture selectors.
        """
        server: FixtureServer = self.server
        query = parse_qs(urlsplit(self.path).query)
        page = query.get('page', [''])[0]
        if page == 'app_linking_discovery':
            issuer = f'http://localhost:{server.server_port}'
            capabilities = ['linking', 'galleries:list']
            if server.batch_enabled:
                capabilities.append(linking.THUMBNAIL_BATCH_CAPABILITY)
            body = json.dumps({'contract_version': linking.LINKING_DISCOVERY_CONTRACT_VERSION,
                               'app_linking': {'available': True,
                                               'protocol_version': linking.LINKING_PROTOCOL_VERSION,
                                               'browser_handoff_version': linking.BROWSER_HANDOFF_VERSION,
                                               'issuer': issuer,
                                               'capabilities': capabilities}}).encode()
            with server.lock:
                server.metadata_requests += 1
            self.respond(body, 'application/json', {'Cache-Control': 'no-store'})
            return
        if self.headers.get('Authorization') != 'Bearer ' + fixture_token():
            self.send_error(401)
            return
        if page == 'app_linking_media' and query.get('kind') == ['photos']:
            after = int(query.get('after', ['0'])[0])
            end = min(after + linking.NATIVE_MEDIA_PAGE_SIZE, PHOTO_COUNT)
            rows = [{'id': index, 'title': 'Fixture',
                     'thumbnail_etag': '"' + hashlib.sha256(server.photos[index - 1]).hexdigest() + '"'}
                    for index in range(after + 1, end + 1)]
            payload = {'gallery_id': 1, 'parent_id': None, 'photos': rows,
                       'next_after': end if end < PHOTO_COUNT else None}
            with server.lock:
                server.metadata_requests += 1
                server.catalogue_sizes[len(rows)] = (
                    server.catalogue_sizes.get(len(rows), 0) + 1
                )
            self.respond(json.dumps(payload).encode(), 'application/json',
                         {'Cache-Control': 'no-store'})
            return
        if page == 'app_linking_media' and query.get('kind') == ['thumbnail']:
            image_id = int(query['image_id'][0])
            body = server.photos[image_id - 1]
            with server.lock:
                server.gets += 1
                server.media_bytes += len(body)
                server.active += 1
                server.peak_active = max(server.peak_active, server.active)
            try:
                self.respond(body, 'image/jpeg', {
                    'Cache-Control': f'private, max-age={linking.PHOTO_CACHE_FRESH_SECONDS}, must-revalidate',
                    'Vary': 'Authorization, X-PHP-Gallery-Photo-Cache',
                    'X-PHP-Gallery-Photo-Cache': '1',
                    'ETag': '"' + hashlib.sha256(body).hexdigest() + '"',
                })
            finally:
                with server.lock:
                    server.active -= 1
            return
        self.send_error(404)

    def do_POST(self) -> None:
        """Serve a strictly ordered PGB1 batch from the captured fixture IDs.

        Returns:
            None: Emits framed JPEG bytes on the exact authenticated route.
        """
        server: FixtureServer = self.server
        if (not server.batch_enabled
                or self.path != '/index.php?page=app_linking_thumbnail_batch'
                or self.headers.get('Authorization') != 'Bearer ' + fixture_token()):
            self.send_error(404)
            return
        lengths = self.headers.get_all('Content-Length', [])
        if (len(lengths) != 1 or re.fullmatch(r'[1-9][0-9]*', lengths[0]) is None
                or len(lengths[0]) > len(str(FIXTURE_REQUEST_MAX_BYTES))
                or int(lengths[0]) > FIXTURE_REQUEST_MAX_BYTES
                or self.headers.get_all('Content-Type', []) != ['application/json']):
            self.send_error(400)
            return
        raw_request = self.rfile.read(int(lengths[0]))
        request = json.loads(raw_request)
        if (not isinstance(request, dict) or set(request) != {'v', 'gallery_id', 'image_ids'}
                or type(request['v']) is not int or request['v'] != 1
                or request['gallery_id'] != '1' or not isinstance(request['image_ids'], list)
                or json.dumps(request, separators=(',', ':')).encode() != raw_request
                or tuple(request) != ('v', 'gallery_id', 'image_ids')):
            self.send_error(400)
            return
        ids = request['image_ids']
        if (not 1 <= len(ids) <= linking.PRODUCTION_MAX_ITEMS
                or any(type(image_id) is not str
                       or re.fullmatch(r'[1-9][0-9]*', image_id) is None
                       or len(image_id) > len(str(PHOTO_COUNT))
                       or int(image_id) > PHOTO_COUNT for image_id in ids)
                or len(set(ids)) != len(ids)):
            self.send_error(400)
            return
        frames = [FRAMED_HEADER.pack(b'PGB1', 1, len(ids))]
        for index, image_id in enumerate(ids):
            data = server.photos[int(image_id) - 1]
            digest = hashlib.sha256(data).hexdigest()
            metadata = json.dumps({'v': 1, 'image_id': image_id,
                                   'request_index': index, 'status': 'ok',
                                   'mime': 'image/jpeg', 'length': len(data),
                                   'sha256': digest, 'etag': '"' + digest + '"'},
                                  separators=(',', ':')).encode()
            frames.extend((b'I', FRAME_LENGTHS.pack(len(metadata), len(data)), metadata, data))
        frames.append(b'E' + len(ids).to_bytes(2, 'big'))
        body = b''.join(frames)
        with server.lock:
            server.posts += 1
            server.media_bytes += len(body)
            server.batch_sizes[len(ids)] = server.batch_sizes.get(len(ids), 0) + 1
            server.active += 1
            server.peak_active = max(server.peak_active, server.active)
        try:
            self.respond(body, linking.THUMBNAIL_BATCH_MIME,
                         {'Cache-Control': 'private, no-store',
                          'Vary': 'Authorization, X-PHP-Gallery-Preload'})
        finally:
            with server.lock:
                server.active -= 1


def fixture_token() -> str:
    """Return a fixed synthetic canonical bearer for the local server only.

    Returns:
        str: Nonsecret fixture token, never exported in diagnostic evidence.
    """
    return 'pgla_' + 'A' * 43


def preferred_loopback_families() -> tuple[int, ...]:
    """Collect only localhost loopback families in resolver order.

    Returns:
        tuple[int, ...]: Distinct IPv4/IPv6 families with exact loopback peers.

    Raises:
        RuntimeError: No supported loopback address was resolved.
    """
    families: list[int] = []
    for family, _kind, _protocol, _name, target in socket.getaddrinfo(
        'localhost', 0, 0, socket.SOCK_STREAM,
    ):
        if ((family == socket.AF_INET and target[0] == '127.0.0.1')
                or (family == socket.AF_INET6 and target[0] == '::1')):
            if family not in families:
                families.append(family)
    if not families:
        raise RuntimeError('loopback_resolution')
    return tuple(families)


def probe_connection(server: FixtureServer, deadline: float) -> dict[str, int | float | bool]:
    """Measure the production localhost connector without sending any HTTP.

    Args:
        server (FixtureServer): Owned bound loopback listener.
        deadline (float): Absolute benchmark watchdog deadline.

    Returns:
        dict[str, int | float | bool]: Actual success, DNS, TCP and wall facts.
    """
    timings = linking.MediaReadTimings()
    control = linking._LinkingTransportControl(
        min(deadline, time.monotonic() + linking.LINKING_REQUEST_DEADLINE_SECONDS),
        None, linking.LINKING_SOCKET_TIMEOUT_SECONDS,
    )
    control.timings = timings
    started = time.perf_counter()
    success = False
    try:
        control.connect(('localhost', server.server_port), None, loopback_only=True)
        success = True
    except (OSError, linking.LinkingError):
        pass
    finally:
        control.finish()
    return {'success': success, 'dns_ms': round(timings.dns_ms, 3),
            'tcp_ms': round(timings.tcp_ms, 3),
            'wall_ms': round((time.perf_counter() - started) * 1000.0, 3),
            'successful_connections': timings.connections}


def summarize(values: list[float]) -> dict[str, int | float | None]:
    """Describe observed callback durations without inventing small tails.

    Args:
        values (list[float]): Bounded measured callback durations in milliseconds.

    Returns:
        dict[str, int | float | None]: Count, min, median, max and optional tails.
    """
    ordered = sorted(values)
    def rank(percent: int) -> float | None:
        """Return a nearest-rank value for a nonempty bounded sample.

        Args:
            percent (int): Integer percentile in the observed distribution.

        Returns:
            float | None: Rounded observed duration or None for no samples.
        """
        if not ordered:
            return None
        return round(ordered[max(0, math.ceil(len(ordered) * percent / 100) - 1)], 3)
    return {'samples': len(ordered), 'min_ms': round(ordered[0], 3) if ordered else None,
            'median_ms': rank(50), 'max_ms': round(ordered[-1], 3) if ordered else None,
            'p95_ms': rank(95) if len(ordered) >= TAIL_MIN_SAMPLES else None,
            'p99_ms': rank(99) if len(ordered) >= TAIL_MIN_SAMPLES else None}


def run_phase(
    session: linking.LinkingSession, server: FixtureServer, deadline: float,
    progress: dict[str, int | float | str | None],
) -> dict[str, int | float | str | None | list[float] | dict[str, int | float | None] | dict[int, int]]:
    """Traverse all real tiles using the unmodified viewer scheduler and cache.

    Args:
        session (linking.LinkingSession): Authorized local fixture session.
        server (FixtureServer): Owned loopback request and byte counter.
        deadline (float): Absolute monotonic benchmark watchdog deadline.
        progress (dict[str, int | float | str | None]): Fixed safe partial facts
            retained if the watchdog or another fixture boundary fails.

    Returns:
        dict[str, int | float | str | None | list[float] | dict[str, int | float | None] | dict[int, int]]:
            Numeric timing, sample and request observations for this phase.

    Raises:
        RuntimeError: A watchdog, incomplete viewport or incomplete traversal fails.
    """
    shown: set[int] = set()
    first_ms: float | None = None
    initial_ms: float | None = None
    all_shown_ms: float | None = None
    first_admitted: set[int] | None = None
    poll_samples: list[float] = []
    show_samples: list[float] = []
    window: tk.Tk | None = None
    viewer: native._NativeGalleryViewer | None = None
    original_show = native._NativeGalleryViewer._show_thumbnail
    original_poll = native._NativeGalleryViewer._poll_queue
    started = time.perf_counter()

    def observe_show(
        owner: native._NativeGalleryViewer, index: int,
        data: 'Image.Image | None', *, remember: bool = True,
    ) -> None:
        """Measure real PhotoImage application after its original Tk owner returns.

        Args:
            owner (native._NativeGalleryViewer): Actual mounted viewer.
            index (int): Current tile index accepted by the original owner.
            data (Image.Image | None): Worker-owned raster or placeholder.
            remember (bool): Existing decoded-memory admission decision.

        Returns:
            None: Calls the original method and retains only numeric evidence.
        """
        nonlocal first_ms, all_shown_ms
        tick = time.perf_counter()
        original_show(owner, index, data, remember=remember)
        if len(show_samples) < CALLBACK_SAMPLE_LIMIT:
            show_samples.append((time.perf_counter() - tick) * 1000.0)
        if (owner is viewer and index < len(owner._photo_images)
                and owner._photo_images[index] is not None):
            shown.add(owner._photos[index].image_id)
            if first_ms is None:
                first_ms = (time.perf_counter() - started) * 1000.0
            if len(shown) == PHOTO_COUNT and all_shown_ms is None:
                all_shown_ms = (time.perf_counter() - started) * 1000.0

    def observe_poll(owner: native._NativeGalleryViewer) -> None:
        """Measure the original Tk delivery callback without altering scheduling.

        Args:
            owner (native._NativeGalleryViewer): Actual mounted viewer.

        Returns:
            None: Records one bounded callback duration after the original call.
        """
        tick = time.perf_counter()
        try:
            original_poll(owner)
        finally:
            if len(poll_samples) < CALLBACK_SAMPLE_LIMIT:
                poll_samples.append((time.perf_counter() - tick) * 1000.0)

    native._NativeGalleryViewer._show_thumbnail = observe_show
    native._NativeGalleryViewer._poll_queue = observe_poll
    try:
        window = tk.Tk()
        def report_tk_error(
            exception_type: type[BaseException], error: BaseException,
            traceback: TracebackType | None,
        ) -> None:
            """Keep Tk callback failures visible without logging private text.

            Args:
                exception_type (type[BaseException]): Raised exception class.
                error (BaseException): Exception instance, never retained.
                traceback (TracebackType | None): Original stack, never retained.

            Returns:
                None: Marks the phase for a fixed safe failure report.
            """
            del exception_type, error, traceback
            progress['tk_error'] = 1

        window.report_callback_exception = report_tk_error
        window.geometry(f'{VIEWPORT_SIZE[0]}x{VIEWPORT_SIZE[1]}')
        viewer = native._NativeGalleryViewer(
            window, session, linking.LinkedGallery(1, None, 'Fixture'),
            lambda: 'en', show_title=False,
        )
        viewer.frame.pack(fill='both', expand=True)
        while time.monotonic() < deadline:
            window.update()
            if progress.get('tk_error') == 1:
                raise RuntimeError('tk_callback')
            progress['displayed'] = len(shown)
            progress['worker_active'] = int(viewer._worker is not None)
            progress['first_show_ms'] = first_ms
            progress['phase_elapsed_ms'] = round(
                (time.perf_counter() - started) * 1000.0, 3,
            )
            progress['photos_loaded'] = len(viewer._photos)
            progress['admitted_count'] = len(viewer._admitted_thumbnail_indices)
            progress['visible_count'] = len(viewer._visible_thumbnail_indices)
            progress['thumbnail_loaded_count'] = len(viewer._thumbnail_loaded)
            progress['thumbnail_pending_count'] = len(viewer._thumbnail_pending)
            progress['warm_pending_count'] = len(viewer._warm_thumbnail_pending)
            progress['queued_results'] = viewer._events.qsize()
            progress['disk_lane_busy'] = int(viewer._thumbnail_disk_lane.busy())
            progress['dispatch_group_size'] = len(viewer._thumbnail_batch_indices)
            progress['network_failure_latched'] = int(viewer._http_thumbnail_failure_latched)
            progress['media_throttled'] = int(viewer._media_throttled)
            progress['refresh_pending'] = int(viewer._refresh_pending)
            progress['next_page_pending'] = int(viewer._next_after is not None)
            progress['worker_kind'] = (viewer._worker_kind if viewer._worker_kind in (
                'thumbnail_batch', 'page', 'more', 'refresh', 'presentation',
            ) else 'none' if viewer._worker_kind is None else 'other')
            # These viewer totals contain only completed physical observations;
            # the currently active worker is explicitly not included yet.
            with viewer._thumbnail_metrics_lock:
                for name in ('connections', 'connection_reuses', 'dns_ms',
                             'tcp_ms', 'http_requests'):
                    progress['completed_' + name] = round(
                        viewer._thumbnail_transport_totals[name], 3,
                    )
                progress['completed_network_ms'] = round(viewer._thumbnail_network_ms, 3)
                progress['completed_disk_write_ms'] = round(viewer._thumbnail_disk_write_ms, 3)
                progress['http_incomplete'] = viewer._http_batch_incomplete
            admitted = viewer._admitted_thumbnail_indices
            if first_admitted is None and admitted:
                first_admitted = set(admitted)
            if (initial_ms is None and first_admitted
                    and all(index < len(viewer._photo_images)
                            and viewer._photo_images[index] is not None
                            for index in first_admitted)):
                initial_ms = (time.perf_counter() - started) * 1000.0
            if (len(shown) == PHOTO_COUNT and initial_ms is not None
                    and viewer._worker is None and viewer._events.empty()
                    and not viewer._thumbnail_disk_lane.busy()):
                break
            if (initial_ms is not None and admitted
                    and admitted.issubset(viewer._thumbnail_loaded)
                    and viewer._worker is None and viewer._viewport_after_id is None):
                viewer.grid_canvas.yview_scroll(1, 'units')
                viewer._on_grid_viewport(None)
            threading.Event().wait(PUMP_PAUSE_SECONDS)
        if (len(shown) != PHOTO_COUNT or first_ms is None or initial_ms is None
                or all_shown_ms is None or viewer._worker is not None
                or not viewer._events.empty() or viewer._thumbnail_disk_lane.busy()):
            raise RuntimeError('incomplete_traversal')
        elapsed_ms = (time.perf_counter() - started) * 1000.0
        counters = server.counters()
        return {'displayed': len(shown), 'first_show_ms': round(first_ms, 3),
                'initial_admitted_complete_ms': round(initial_ms, 3),
                'initial_admitted_count': len(first_admitted or ()),
                'traversal_ms': round(all_shown_ms, 3),
                'worker_settled_ms': round(elapsed_ms, 3),
                'tk_scaling': float(window.tk.call('tk', 'scaling')),
                'window_width': window.winfo_width(),
                'window_height': window.winfo_height(),
                'poll_samples_ms': [round(value, 3) for value in poll_samples],
                'show_samples_ms': [round(value, 3) for value in show_samples],
                'poll': summarize(poll_samples), 'show': summarize(show_samples),
                'disk_hits': viewer._thumbnail_source_disk_count,
                'network_sources': viewer._thumbnail_source_network_count,
                'dispatch_groups': viewer._thumbnail_batch_count,
                'completed_connections': progress['completed_connections'],
                'completed_connection_reuses': progress['completed_connection_reuses'],
                'completed_dns_ms': progress['completed_dns_ms'],
                'completed_tcp_ms': progress['completed_tcp_ms'],
                'completed_http_requests': progress['completed_http_requests'],
                'completed_network_ms': progress['completed_network_ms'],
                'completed_disk_write_ms': progress['completed_disk_write_ms'],
                'http_incomplete': progress['http_incomplete'],
                'gui_queue_capacity': viewer._events.maxsize,
                'http_batches': viewer._http_batch_requests,
                'thumbnail_gets': counters['thumbnail_gets'],
                'thumbnail_posts': counters['thumbnail_posts'],
                'thumbnail_bytes': counters['thumbnail_bytes'],
                'metadata_requests': counters['metadata_requests'],
                'catalogue_sizes': counters['catalogue_sizes'],
                'batch_sizes': counters['batch_sizes'],
                'peak_handler_intervals': counters['peak_handler_intervals']}
    finally:
        if viewer is not None:
            viewer.close()
        if window is not None:
            window.destroy()
        native._NativeGalleryViewer._show_thumbnail = original_show
        native._NativeGalleryViewer._poll_queue = original_poll


def run_scenario(
    server: FixtureServer, batch: bool, deadline: float, cache_root: str,
    progress: dict[str, int | float | str | None],
    completed_phases: list[CompletedPhase],
) -> dict[str, dict[str, int | float | str | None | list[float] | dict[str, int | float | None] | dict[int, int]]]:
    """Measure a cold traversal followed by a fresh-viewer disk-warm return.

    Args:
        server (FixtureServer): Owned loopback HTTP fixture.
        batch (bool): Whether discovery advertises the optional protocol.
        deadline (float): Absolute watchdog deadline for the whole benchmark.
        cache_root (str): Dedicated temporary LOCALAPPDATA fixture root.
        progress (dict[str, int | float | str | None]): Safe partial phase facts.
        completed_phases (list[CompletedPhase]): Ordered completed phase reports
            retained even when a later phase cannot complete.

    Returns:
        dict[str, dict[str, int | float | str | None | list[float] | dict[str, int | float | None] | dict[int, int]]]:
            Separately observed cold and disk-warm native traversals.

    Raises:
        RuntimeError: Discovery or observed cache/HTTP behavior is incomplete.
    """
    os.environ['LOCALAPPDATA'] = cache_root
    server.batch_enabled = batch
    issuer = f'http://localhost:{server.server_port}'
    capabilities = linking.discover_linking_capabilities(issuer)
    advertised = linking.THUMBNAIL_BATCH_CAPABILITY in capabilities
    if advertised is not batch:
        raise RuntimeError('discovery_mismatch')
    session = linking.LinkingSession(
        issuer, fixture_token(), time.monotonic() + SESSION_LIFETIME_SECONDS,
        linking.GALLERIES_SCOPE + linking.MEDIA_SCOPE_SUFFIX,
        thumbnail_batch_supported=advertised,
    )
    try:
        server.reset_counters()
        progress.update(stage='batch_cold' if batch else 'single_cold',
                        displayed=0, worker_active=0, first_show_ms=None)
        cold = run_phase(session, server, deadline, progress)
        completed_phases.append({'phase': str(progress['stage']), 'metrics': cold})
        if cold['displayed'] != PHOTO_COUNT or cold['network_sources'] != PHOTO_COUNT:
            raise RuntimeError('incomplete_cold')
        histogram = cold['batch_sizes']
        if ((batch and (cold['thumbnail_gets'] != 0
                        or not isinstance(histogram, dict)
                        or sum(size * count for size, count in histogram.items()) != PHOTO_COUNT
                        or cold['thumbnail_posts'] != sum(histogram.values())))
                or (not batch and (cold['thumbnail_gets'] != PHOTO_COUNT
                                   or cold['thumbnail_posts'] != 0))):
            raise RuntimeError('unexpected_cold_transport')
        sizes = cold['catalogue_sizes']
        if (not isinstance(sizes, dict)
                or linking.NATIVE_MEDIA_PAGE_SIZE not in sizes
                or PHOTO_COUNT - linking.NATIVE_MEDIA_PAGE_SIZE not in sizes):
            raise RuntimeError('incomplete_catalogue')
        server.reset_counters()
        progress.update(stage='batch_warm' if batch else 'single_warm',
                        displayed=0, worker_active=0, first_show_ms=None)
        warm = run_phase(session, server, deadline, progress)
        completed_phases.append({'phase': str(progress['stage']), 'metrics': warm})
        if (warm['displayed'] != PHOTO_COUNT or warm['disk_hits'] != PHOTO_COUNT
                or warm['thumbnail_gets'] != 0 or warm['thumbnail_posts'] != 0):
            raise RuntimeError('incomplete_warm')
        warm_sizes = warm['catalogue_sizes']
        if (not isinstance(warm_sizes, dict)
                or linking.NATIVE_MEDIA_PAGE_SIZE not in warm_sizes
                or PHOTO_COUNT - linking.NATIVE_MEDIA_PAGE_SIZE not in warm_sizes):
            raise RuntimeError('incomplete_catalogue')
        return {'cold': cold, 'disk_warm_ram_empty': warm}
    finally:
        session.close()


def main() -> int:
    """Run both exact-SHA Windows scenarios and retain safe numeric evidence.

    Returns:
        int: Zero only after both complete cold and warm traversals succeed.
    """
    sha = os.environ.get('GITHUB_SHA', '')
    expected = os.environ.get('ISSUE_362_EXPECTED_SHA', '')
    run_id = os.environ.get('GITHUB_RUN_ID', '')
    if (sys.platform != 'win32' or os.environ.get('GITHUB_ACTIONS') != 'true'
            or re.fullmatch(r'[a-f0-9]{40}', sha) is None or sha != expected
            or re.fullmatch(r'[0-9]+', run_id) is None or len(sys.argv) != 2):
        print('B3_VIEWER_BLOCKED reason=ci_identity', file=sys.stderr)
        return 2
    start_utc = datetime.now(timezone.utc).isoformat(timespec='seconds')
    deadline = time.monotonic() + WATCHDOG_SECONDS
    prior_local = os.environ.get('LOCALAPPDATA')
    progress: dict[str, int | float | str | None] = {
        'stage': 'setup', 'displayed': 0, 'worker_active': 0,
        'first_show_ms': None,
    }
    completed_phases: list[CompletedPhase] = []
    probes: dict[str, linking.JsonValue] = {}
    server: FixtureServer | None = None
    thread: threading.Thread | None = None
    report_path = Path(sys.argv[1]).resolve()
    allowed = (ROOT / 'cache' / 'test-audit').resolve()
    if not report_path.is_relative_to(allowed) or report_path.exists():
        print('B3_VIEWER_BLOCKED reason=output', file=sys.stderr)
        return 2
    try:
        photos: list[bytes] = []
        for index in range(PHOTO_COUNT):
            # Fixed color components distinguish synthetic bodies; they are not
            # operational limits or user-controlled image data.
            image = Image.new('RGB', (JPEG_SIDE, JPEG_SIDE), (index, 60, 140))
            image_buffer = io.BytesIO()
            image.save(image_buffer, format='JPEG')
            image.close()
            photos.append(image_buffer.getvalue())
        progress['stage'] = 'loopback_probe'
        families = preferred_loopback_families()
        probes['first_resolved_family'] = (
            'ipv6' if families[0] == socket.AF_INET6 else 'ipv4'
        )
        original = FixtureServer(tuple(photos), socket.AF_INET)
        try:
            probes['ipv4_only'] = probe_connection(original, deadline)
        finally:
            original.server_close()
        try:
            server = FixtureServer(tuple(photos), families[0])
        except OSError as error:
            raise RuntimeError('loopback_bind') from error
        probes['configured_family'] = (
            'ipv6' if server.address_family == socket.AF_INET6 else 'ipv4'
        )
        preferred_probe = probe_connection(server, deadline)
        probes['preferred_listener'] = preferred_probe
        if (preferred_probe['success'] is not True
                or preferred_probe['successful_connections'] != 1):
            raise RuntimeError('loopback_probe')
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        with tempfile.TemporaryDirectory(prefix='b3-viewer-') as temporary:
            base = Path(temporary)
            single = run_scenario(server, False, deadline, str(base / 'single'),
                                  progress, completed_phases)
            batch = run_scenario(server, True, deadline, str(base / 'batch'),
                                 progress, completed_phases)
            report = {
                'schema_version': 1, 'status': 'PASS', 'commit_sha': sha,
                'github_run_id': run_id, 'started_utc': start_utc,
                'finished_utc': datetime.now(timezone.utc).isoformat(timespec='seconds'),
                'scope': 'owned-windows-tk-loopback-synthetic',
                'runtime': {'python': platform.python_version(),
                            'windows': platform.platform(), 'tk': tk.TkVersion,
                            'pillow': PILLOW_VERSION},
                'configured_transport_workers': linking.LINKING_TRANSPORT_WORKER_LIMIT,
                'backend_overlap': 'unknown',
                'handler_interval_scope': 'request handler through body flush',
                'connection_probes': probes,
                'transport_timing_scope': 'completed observations; active worker excluded',
                'limitations': ['single synthetic cohort', 'no PHP or MariaDB',
                                'no FPM, HTTPS, proxy or staging acceptance',
                                'Tk publication is not monitor scanout'],
                'single': single, 'batch': batch,
            }
            report_path.parent.mkdir(parents=True, exist_ok=True)
            report_path.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
        print('B3_VIEWER_PASS')
        return 0
    except Exception as error:  # noqa: BLE001
        safe_codes = frozenset((
            'discovery_mismatch', 'incomplete_traversal', 'incomplete_cold',
            'unexpected_cold_transport', 'incomplete_catalogue', 'incomplete_warm',
            'tk_callback', 'loopback_resolution', 'loopback_bind', 'loopback_probe',
        ))
        code = (error.args[0] if len(error.args) == 1
                and isinstance(error.args[0], str) and error.args[0] in safe_codes
                else 'fixture_error')
        failure = {
            'schema_version': 1, 'status': 'FAIL', 'commit_sha': sha,
            'github_run_id': run_id, 'started_utc': start_utc,
            'finished_utc': datetime.now(timezone.utc).isoformat(timespec='seconds'),
            'code': code, 'progress': progress, 'completed_phases': completed_phases,
            'connection_probes': probes,
            'transport_timing_scope': 'completed observations; active worker excluded',
            'transport': server.counters() if server is not None else None,
        }
        try:
            report_path.parent.mkdir(parents=True, exist_ok=True)
            report_path.write_text(json.dumps(failure, indent=2) + '\n', encoding='utf-8')
        except OSError:
            pass
        print(f'B3_VIEWER_FAIL stage={progress["stage"]} reason={code}',
              file=sys.stderr)
        return 1
    finally:
        if server is not None:
            if thread is not None and thread.is_alive():
                server.shutdown()
            server.server_close()
        if thread is not None and thread.ident is not None:
            thread.join(timeout=SERVER_JOIN_SECONDS)
        if prior_local is None:
            os.environ.pop('LOCALAPPDATA', None)
        else:
            os.environ['LOCALAPPDATA'] = prior_local


if __name__ == '__main__':
    raise SystemExit(main())
