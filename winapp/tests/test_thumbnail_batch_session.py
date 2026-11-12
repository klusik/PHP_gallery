# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_thumbnail_batch_session.py
# Module Type: Regression Test
# Purpose: Verify authorized thumbnail batch session orchestration.
# Responsibilities:
#   - Exercise session proof, worker, parser, fallback and retry ownership.
#   - Verify physical HTTP accounting and fail-closed future behavior.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Test thumbnail batch sessions through an owned HTTP boundary."""

import hashlib
import json
import struct
import sys
import threading
import time
import unittest
from email.message import Message
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace, TracebackType
from typing import Callable
from unittest import mock
from urllib.parse import SplitResult

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import app_linking as linking

# Purpose: Keep a synthetic approved grant live for bounded session work.
# Type: float. Units: seconds. Scope: one in-memory session fixture.
# Consumers: ThumbnailBatchSessionTests.make_session. Rationale: Ten minutes
# exceeds the tested operation lifetime without changing transport policy.
TEST_SESSION_LIFETIME_SECONDS = 600.0

# Purpose: Provide stable independently verifiable image bytes.
# Type: bytes. Units: payload bytes. Scope: synthetic PGB1 response item.
# Consumers: encode_batch and body assertions. Rationale: Fixed small bytes
# make digest and quoted ETag equality deterministic.
TEST_IMAGE_BODY = b'owned-thumbnail-image'

# Purpose: Keep compatibility reads isolated from prior fixture cache entries.
# Type: tuple[int, ...]. Units: synthetic photo IDs. Scope: legacy-route cases.
# Consumers: missing-capability and old-gateway regressions. Rationale: Each
# grant variant needs its own cold cache key to prove a real GET was made.
TEST_LEGACY_COMPAT_IMAGE_IDS = (71, 72, 73, 74)

# Purpose: Identify the synthetic legacy gateway refusal photo.
# Type: int. Units: synthetic photo ID. Scope: legacy-route 503 regression.
# Consumers: The old-route gateway regression.
# Rationale: Keep this failure case separate from the compatibility matrix.
TEST_LEGACY_GATEWAY_IMAGE_ID = 75

# Purpose: Identify the synthetic batch HTML refusal photo.
# Type: int. Units: synthetic photo ID. Scope: negotiated batch 404 regression.
# Consumers: The negotiated HTML 404 regression.
# Rationale: Keep this proof separate from the legacy-route response fixtures.
TEST_BATCH_HTML404_IMAGE_ID = 76

# Purpose: Bound the zero-frame transient retry in a future cooldown fixture.
# Type: float. Units: seconds. Scope: batch route retry policy.
# Consumers: transient regression assertions. Rationale: One second matches
# the named production retry policy and keeps the test bounded.
TEST_BATCH_RETRY_SECONDS = 1.0

# Purpose: Allow monotonic read skew when asserting one-second cooldown range.
# Type: float. Units: seconds. Scope: final transient retry test.
# Consumers: ThumbnailBatchSessionTests.test_exhausted_transient_retains_cooldown_and_releases_gate.
# Rationale: A tenth of a second covers assertion overhead without weakening
# the production retry duration or allowing another attempt.
TEST_RETRY_DEADLINE_TOLERANCE_SECONDS = 0.1

# Purpose: Bound worker completion and observed admission in session fixtures.
# Type: float. Units: seconds. Scope: one transport worker or admission phase.
# Consumers: ThumbnailBatchSessionTests deadline, FIFO and cancellation cases.
# Rationale: Two seconds bounds a stalled cleanup while allowing hosted runner
# scheduling; no transport policy is changed.
TEST_WORKER_JOIN_SECONDS = 2.0

# Purpose: Exercise preservation of a long, server-supplied cooldown.
# Type: int. Units: seconds. Scope: synthetic batch-route HTTP 429.
# Consumers: ThumbnailBatchSessionTests.test_long_throttle_prevents_followup_http.
# Rationale: One hour exceeds one operation deadline and must survive intact.
TEST_LONG_SERVER_RETRY_SECONDS = 3600


def encode_batch(
    image_ids: tuple[int, ...], *, body: bytes = TEST_IMAGE_BODY,
    digest_override: str | None = None, etag_override: str | None = None,
) -> bytes:
    """Encode a complete PGB1 response independently of production parsing.

    Args:
        image_ids (tuple[int, ...]): Ordered IDs included in the response.
        body (bytes): Bytes in each successful image frame.
        digest_override (str | None): Optional deliberately invalid digest.
        etag_override (str | None): Optional descriptor mismatch validator.

    Returns:
        bytes: Complete response with one item per ID and matching footer.
    """
    digest = digest_override or hashlib.sha256(body).hexdigest()
    etag = etag_override or '"' + hashlib.sha256(body).hexdigest() + '"'
    frames: list[bytes] = []
    for index, image_id in enumerate(image_ids):
        metadata = json.dumps({
            'v': 1, 'image_id': str(image_id), 'request_index': index,
            'status': 'ok', 'mime': 'image/jpeg', 'length': len(body),
            'sha256': digest, 'etag': etag,
        }, separators=(',', ':')).encode('utf-8')
        frames.append(b'I' + struct.pack('>HI', len(metadata), len(body))
                      + metadata + body)
    return (struct.pack('>4sBH', b'PGB1', 1, len(image_ids))
            + b''.join(frames) + b'E' + struct.pack('>H', len(image_ids)))


class _Response:
    """Own one bounded fake HTTP response for the native transport."""

    def __init__(self, status: int, body: bytes, headers: dict[str, str]) -> None:
        """Initialize the exact response status, headers and body.

        Args:
            status (int): Synthetic HTTP status code.
            body (bytes): Complete body returned by the response.
            headers (dict[str, str]): Exact synthetic response fields.

        Returns:
            None: Creates a finite response fixture.
        """
        self.status = status
        self.headers = Message()
        for name, value in headers.items():
            self.headers[name] = value
        self.body = body
        self.offset = 0
        self.closed = False
        # http.client uses 11 as its HTTP/1.1 protocol version identifier.
        self.version = 11
        self.will_close = True

    def read1(self, amount: int) -> bytes:
        """Return the next bounded fragment until the response is exhausted.

        Args:
            amount (int): Maximum number of bytes requested.

        Returns:
            bytes: One response fragment or the empty EOF marker.
        """
        end = min(len(self.body), self.offset + amount)
        chunk = self.body[self.offset:end]
        self.offset = end
        return chunk

    def read(self, amount: int = -1) -> bytes:
        """Return the bounded error body used by exact 404 handling.

        Args:
            amount (int): Maximum response bytes requested.

        Returns:
            bytes: The response body capped to the requested length.
        """
        end = len(self.body) if amount < 0 else min(len(self.body), amount)
        chunk = self.body[self.offset:end]
        self.offset += len(chunk)
        return chunk

    def close(self) -> None:
        """Record response cleanup.

        Returns:
            None: Marks this response closed.
        """
        self.closed = True

    def __enter__(self) -> "_Response":
        """Return this response as the owner of its bounded body.

        Returns:
            _Response: This response context.
        """
        return self

    def __exit__(
        self, _exception_type: type[BaseException] | None,
        _exception: BaseException | None, _traceback: TracebackType | None,
    ) -> None:
        """Close the response after the media reader finishes.

        Args:
            _exception_type (type[BaseException] | None): Optional raised type.
            _exception (BaseException | None): Optional raised exception.
            _traceback (TracebackType | None): Optional traceback.

        Returns:
            None: Releases the synthetic response body owner.
        """
        del _exception_type, _exception, _traceback
        self.close()


class _Connection:
    """Capture one request and return one owned fake response."""

    def __init__(self, response: _Response) -> None:
        """Initialize the connection with its response owner.

        Args:
            response (_Response): Synthetic response for this request.

        Returns:
            None: Initializes request capture state.
        """
        self.response = response
        self.sock = None
        self.request_args: tuple[str, str] | None = None
        self.request_count = 0
        self.request_body: bytes | None = None
        self.request_headers: dict[str, str] | None = None
        self.closed = False

    def connect(self) -> None:
        """Model a successful connection without opening a socket.

        Returns:
            None: No external traffic is performed.
        """

    def request(
        self, method: str, url: str, body: bytes | None = None,
        headers: dict[str, str] | None = None,
    ) -> None:
        """Capture the method, route, body and headers from production.

        Args:
            method (str): HTTP method.
            url (str): Request target.
            body (bytes | None): Exact serialized request body.
            headers (dict[str, str] | None): Exact request headers.

        Returns:
            None: Saves the exact synthetic request.
        """
        self.request_count += 1
        self.request_args = (method, url)
        self.request_body = body
        self.request_headers = headers

    def getresponse(self) -> _Response:
        """Return the only response owned by this connection.

        Returns:
            _Response: Exact configured fixture response.
        """
        return self.response

    def close(self) -> None:
        """Record cleanup of the dedicated connection.

        Returns:
            None: Marks this connection closed.
        """
        self.closed = True


class ThumbnailBatchSessionTests(unittest.TestCase):
    """Exercise the production session, worker, helper and strict parser."""

    def setUp(self) -> None:
        """Create one isolated real disk cache for this test case.

        Returns:
            None: Every session in this case shares only its private fixture root.
        """
        self.cache_directory = TemporaryDirectory()
        self.addCleanup(self.cache_directory.cleanup)
        self.cache = linking.GalleryMediaCache(
            Path(self.cache_directory.name), linking.NATIVE_MEDIA_MAX_BYTES,
            thumbnails=True,
        )
        cache_patch = mock.patch.object(
            linking.GalleryMediaCache, 'for_current_user', return_value=self.cache,
        )
        cache_patch.start()
        self.addCleanup(cache_patch.stop)

    def make_session(
        self, *, scope: str = linking.GALLERIES_SCOPE,
        thumbnail_batch_supported: bool = True,
    ) -> linking.LinkingSession:
        """Create a live session with a selected scope and capability.

        Args:
            scope (str): Existing approved gallery or media grant scope.
            thumbnail_batch_supported (bool): Whether discovery negotiated the
                optional batch route for this session.

        Returns:
            linking.LinkingSession: Fresh exact-issuer session with the chosen
                grant and capability state.
        """
        session = linking.LinkingSession(
            'https://gallery.example/Galerie', 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_SESSION_LIFETIME_SECONDS,
            scope, thumbnail_batch_supported=thumbnail_batch_supported,
        )
        self.addCleanup(session.close)
        return session

    def media_response(self, *, status: int = 200) -> _Response:
        """Build one ordinary image or legacy gateway response.

        Args:
            status (int): HTTP status returned by the original media route.

        Returns:
            _Response: Bounded synthetic response with production-shaped
                headers for the selected status.
        """
        if status == 200:
            headers = {
                'Content-Type': 'image/jpeg',
                'Content-Length': str(len(TEST_IMAGE_BODY)),
                'ETag': '"' + hashlib.sha256(TEST_IMAGE_BODY).hexdigest() + '"',
                'Cache-Control': 'private, max-age='
                    + str(linking.PHOTO_CACHE_FRESH_SECONDS)
                    + ', must-revalidate',
                'Vary': 'Authorization, X-PHP-Gallery-Photo-Cache',
                'X-PHP-Gallery-Photo-Cache': '1',
            }
            body = TEST_IMAGE_BODY
        else:
            headers = {
                'Content-Type': 'text/html; charset=utf-8',
                'Cache-Control': 'no-store',
            }
            body = b'<html>gateway unavailable</html>'
        return _Response(status, body, headers)

    def register_page(
        self, session: linking.LinkingSession, image_ids: tuple[int, ...],
        etag: str | None = None,
    ) -> tuple[linking.LinkedPhoto, ...]:
        """Register public photo proofs using the scope's real page parser.

        Args:
            session (linking.LinkingSession): Proof owner whose grant selects
                the public or Admin descriptor contract.
            image_ids (tuple[int, ...]): Ordered unique positive photo IDs.
            etag (str | None): Optional strong thumbnail descriptor.

        Returns:
            tuple[linking.LinkedPhoto, ...]: Parsed freshness-stamped public
                photos, including explicit stored settings for Admin readers.
        """
        selected = etag or '"' + hashlib.sha256(TEST_IMAGE_BODY).hexdigest() + '"'
        rows: list[dict[str, linking.JsonValue]] = [
            {'id': image_id, 'title': 'Fixture photo',
             'thumbnail_etag': selected} for image_id in image_ids
        ]
        if session.can_read_admin_media():
            # Admin metadata has an explicit stored-settings projection even
            # for a public photo. Keep the production parser's strict shape.
            for row in rows:
                row.update({
                    'visibility': 'public', 'nsfw': False,
                    'cache_eligible': True, 'size': len(TEST_IMAGE_BODY),
                })
        payload = json.dumps({
            'gallery_id': 42, 'parent_id': None,
            'photos': rows, 'next_after': None,
        }, separators=(',', ':')).encode('utf-8')
        with mock.patch.object(session, '_media_read', return_value=(
            payload, 'application/json', None, False,
        )):
            return session.media_page(42).photos

    def response(
        self, status: int, body: bytes = b'',
        retry: int = TEST_LONG_SERVER_RETRY_SECONDS,
    ) -> _Response:
        """Construct headers accepted by the selected HTTP status owner.

        Args:
            status (int): Synthetic HTTP status.
            body (bytes): Complete frame or error body.
            retry (int): Synthetic validated Retry-After seconds.

        Returns:
            _Response: Bounded in-memory HTTP response.
        """
        if status == 200:
            headers = {
                'Content-Type': linking.THUMBNAIL_BATCH_MIME,
                'Cache-Control': 'private, no-store',
                'Vary': 'Authorization', 'Content-Length': str(len(body)),
            }
        elif status == 404:
            headers = {'Content-Type': 'application/json; charset=utf-8',
                       'Cache-Control': 'no-store'}
        elif status == 429:
            headers = {'Retry-After': str(retry)}
        else:
            headers = {'Content-Type': 'application/json; charset=utf-8'}
        return _Response(status, body, headers)

    def test_valid_batch_counts_physical_request_once(self) -> None:
        """Deliver a verified frame through real page and transport owners.

        Returns:
            None: The response bytes and one-HTTP accounting are exact.
        """
        session = self.make_session()
        image_ids = tuple(range(7, 15))
        photos = self.register_page(session, image_ids)
        wire = encode_batch(image_ids)
        connection = _Connection(self.response(200, wire))
        published: list[linking.ThumbnailPublication | linking.LegacyThumbnailPublication] = []
        item_timings: list[linking.MediaReadTimings] = []
        timings = linking.MediaReadTimings()
        stats = linking.ThumbnailBatchStats()

        def publish(
            result: linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
            _timings: linking.MediaReadTimings, check_active: Callable[[], None],
        ) -> None:
            """Collect one result after whole-operation validation.

            Args:
                result (ThumbnailPublication | LegacyThumbnailPublication):
                    Session-owned result.
                _timings (MediaReadTimings): Item-local observations.
                check_active (Callable[[], None]): Current operation fence.

            Returns:
                None: Retains the completed publication.
            """
            check_active()
            published.append(result)
            item_timings.append(_timings)

        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            session.media_thumbnails(42, photos, publish, timings=timings, stats=stats)
        self.assertEqual(list(image_ids), [item.photo.image_id for item in published])
        self.assertEqual([TEST_IMAGE_BODY] * len(image_ids),
                         [item.body for item in published])
        expected_etag = '"' + hashlib.sha256(TEST_IMAGE_BODY).hexdigest() + '"'
        self.assertEqual([expected_etag] * len(image_ids),
                         [item.etag for item in published])
        self.assertTrue(all(item.ram_eligible for item in published))
        self.assertEqual(len(image_ids), len(item_timings))
        self.assertTrue(all(item.cache_stored for item in item_timings))
        for image_id in image_ids:
            cached = self.cache.read(self.cache.key(
                'https://gallery.example/Galerie', 42, image_id, True,
            ), gallery_key=self.cache.gallery_key(
                'https://gallery.example/Galerie', 42,
            ))
            self.assertIsNotNone(cached)
            self.assertEqual(TEST_IMAGE_BODY, cached.body)
        self.assertEqual(1, timings.http_requests)
        self.assertEqual(len(wire), timings.encoded_bytes)
        self.assertEqual(len(image_ids), stats.wire_items)
        self.assertEqual(len(image_ids), stats.delivered)
        self.assertIsNotNone(stats.first_frame_ms)
        self.assertEqual(('POST', '/Galerie/index.php?page=app_linking_thumbnail_batch'),
                         connection.request_args)
        self.assertEqual(
            b'{"v":1,"gallery_id":"42","image_ids":["7","8","9","10","11","12","13","14"]}',
            connection.request_body,
        )
        self.assertTrue(connection.closed)

    def test_foreground_request_runs_between_successive_batch_reads(self) -> None:
        """Admit a queued foreground or upload request between batch calls.

        Returns:
            None: The real FIFO session gate admits the waiting route before
                the same caller starts its next independent thumbnail batch.
        """
        for case_index, route in enumerate((
            'app_linking_galleries', 'app_linking_identity',
            'app_linking_upload',
        )):
            with self.subTest(route=route):
                # Each route gets two distinct positive IDs so the previous
                # cohort's real disk writes cannot bypass batch admission.
                first_id = 7 + case_index * 2
                second_id = first_id + 1
                scope = (
                    linking.UPLOAD_SCOPE + linking.MEDIA_SCOPE_SUFFIX
                    if route == 'app_linking_upload'
                    else linking.GALLERIES_SCOPE + linking.MEDIA_SCOPE_SUFFIX
                )
                session = self.make_session(scope=scope)
                photos = self.register_page(session, (first_id, second_id))
                connections = [
                    _Connection(self.response(200, encode_batch((first_id,)))),
                    _Connection(_Response(200, b'{}', {
                        'Content-Type': 'application/json',
                        'Content-Length': '2', 'Cache-Control': 'no-store',
                    })),
                    _Connection(self.response(200, encode_batch((second_id,)))),
                ]
                request_order: list[str] = []
                published_ids: list[int] = []
                first_published = threading.Event()
                release_first = threading.Event()
                foreground_waiting = threading.Event()
                batch_finished = threading.Event()
                foreground_finished = threading.Event()
                batch_errors: list[Exception] = []
                foreground_errors: list[Exception] = []
                gate = session._request_gate
                original_wait = gate._condition.wait
                original_request = _Connection.request
                self.addCleanup(release_first.set)

                def observe_wait(timeout: float | None = None) -> bool:
                    """Signal the foreground caller's actual FIFO wait.

                    Args:
                        timeout (float | None): Existing gate wait duration.

                    Returns:
                        bool: Result from the real condition wait.
                    """
                    if threading.current_thread().name == 'batch-foreground':
                        foreground_waiting.set()
                    return original_wait(timeout)

                def record_request(
                    connection: _Connection, method: str, url: str,
                    body: bytes | None = None,
                    headers: dict[str, str] | None = None,
                ) -> None:
                    """Record dispatch order and use the owned connection.

                    Args:
                        connection (_Connection): Current fake HTTP owner.
                        method (str): Production request method.
                        url (str): Production request target.
                        body (bytes | None): Exact request body, if any.
                        headers (dict[str, str] | None): Production headers.

                    Returns:
                        None: Records dispatch while the session gate is held.
                    """
                    if 'page=app_linking_thumbnail_batch' in url:
                        self.assertIsInstance(body, bytes)
                        request_data = json.loads(body.decode('ascii'))
                        request_order.append(
                            'batch:' + request_data['image_ids'][0],
                        )
                    else:
                        self.assertEqual(
                            '/Galerie/index.php?page=' + route, url,
                        )
                        self.assertEqual(
                            'POST' if route == 'app_linking_upload' else 'GET',
                            method,
                        )
                        request_order.append(route)
                    original_request(connection, method, url, body, headers)

                def publish(
                    result: linking.ThumbnailPublication
                    | linking.LegacyThumbnailPublication,
                    _timings: linking.MediaReadTimings,
                    check_active: Callable[[], None],
                ) -> None:
                    """Hold the first validated result until a caller queues.

                    Args:
                        result (ThumbnailPublication | LegacyThumbnailPublication):
                            Real session publication for one registered photo.
                        _timings (MediaReadTimings): Item-specific observations.
                        check_active (Callable[[], None]): Operation fence.

                    Returns:
                        None: Retains the photo ID after its active check.
                    """
                    check_active()
                    published_ids.append(result.photo.image_id)
                    if result.photo.image_id == first_id:
                        first_published.set()
                        if not release_first.wait(TEST_WORKER_JOIN_SECONDS):
                            raise TimeoutError('Foreground did not queue.')

                def read_two_batches() -> None:
                    """Read the two registered rows in successive API calls.

                    Returns:
                        None: Captures any worker failure for the test thread.
                    """
                    try:
                        session.media_thumbnails(42, (photos[0],), publish)
                        session.media_thumbnails(42, (photos[1],), publish)
                    except Exception as error:
                        batch_errors.append(error)
                    finally:
                        batch_finished.set()

                def read_foreground() -> None:
                    """Issue one real serialized session route.

                    Returns:
                        None: Captures any request failure for the test thread.
                    """
                    try:
                        if route == 'app_linking_upload':
                            boundary = '0123456789abcdef'
                            upload_body = (
                                b'--' + boundary.encode('ascii') + b'--\r\n'
                            )
                            session._request(
                                route, body=upload_body,
                                multipart_boundary=boundary,
                                content_length=len(upload_body),
                            )
                        else:
                            session._request(route)
                    except Exception as error:
                        foreground_errors.append(error)
                    finally:
                        foreground_finished.set()

                batch_worker = threading.Thread(
                    target=read_two_batches, name='batch-reader', daemon=True,
                )
                foreground_worker = threading.Thread(
                    target=read_foreground, name='batch-foreground', daemon=True,
                )
                with mock.patch.object(
                    linking, '_linking_connection', side_effect=connections,
                ) as connection_factory, mock.patch.object(
                    _Connection, 'request', autospec=True,
                    side_effect=record_request,
                ), mock.patch.object(
                    gate._condition, 'wait', side_effect=observe_wait,
                ):
                    batch_worker.start()
                    try:
                        self.assertTrue(
                            first_published.wait(TEST_WORKER_JOIN_SECONDS),
                            'The first batch did not publish its registered photo.',
                        )
                        foreground_worker.start()
                        self.assertTrue(
                            foreground_waiting.wait(TEST_WORKER_JOIN_SECONDS),
                            'The foreground request did not queue at the real gate.',
                        )
                        release_first.set()
                        self.assertTrue(batch_finished.wait(TEST_WORKER_JOIN_SECONDS))
                        self.assertTrue(
                            foreground_finished.wait(TEST_WORKER_JOIN_SECONDS),
                        )
                    finally:
                        release_first.set()
                        batch_worker.join(TEST_WORKER_JOIN_SECONDS)
                        if foreground_worker.ident is not None:
                            foreground_worker.join(TEST_WORKER_JOIN_SECONDS)

                self.assertFalse(batch_worker.is_alive())
                self.assertFalse(foreground_worker.is_alive())
                self.assertEqual([], batch_errors)
                self.assertEqual([], foreground_errors)
                self.assertEqual([first_id, second_id], published_ids)
                self.assertEqual(
                    [f'batch:{first_id}', route, f'batch:{second_id}'],
                    request_order,
                )
                self.assertEqual(3, connection_factory.call_count)
                self.assertTrue(all(connection.request_count == 1
                                    for connection in connections))
                self.assertTrue(all(connection.closed
                                    and connection.response.closed
                                    for connection in connections))
                self.assertFalse(gate.locked())
                with gate._condition:
                    self.assertEqual((), tuple(gate._waiters))
                self.assertFalse(linking._LINKING_TRANSPORT_SLOTS.locked())
                self.assertFalse(session._pending_gallery_cancels)

    def test_cancelled_batch_withdraws_from_held_session_gate(self) -> None:
        """Withdraw a cancelled batch ticket without releasing its incumbent.

        Returns:
            None: The real batch call exits without HTTP; its waiting ticket and
                pending-cancel registration are removed while the incumbent
                remains the only gate owner.
        """
        session = self.make_session()
        photos = self.register_page(session, (7,))
        gate = session._request_gate
        self.assertTrue(gate.acquire(blocking=False))
        cancelled = threading.Event()
        waiter_entered = threading.Event()
        finished = threading.Event()
        failures: list[Exception] = []
        original_wait = gate._condition.wait

        def observe_wait(timeout: float | None = None) -> bool:
            """Signal the batch caller's actual FIFO admission wait.

            Args:
                timeout (float | None): Existing gate wait duration.

            Returns:
                bool: Result from the real condition wait.
            """
            waiter_entered.set()
            return original_wait(timeout)

        def run_batch() -> None:
            """Call the real session API and retain its cancellation result.

            Returns:
                None: Reports the expected refusal to the test thread.
            """
            try:
                session.media_thumbnails(
                    42, photos, lambda *_args: None, cancelled,
                )
            except Exception as error:
                failures.append(error)
            finally:
                finished.set()

        worker = threading.Thread(
            target=run_batch, name='cancelled-batch', daemon=True,
        )
        try:
            with mock.patch.object(
                linking, '_linking_connection',
            ) as connection_factory, mock.patch.object(
                gate._condition, 'wait', side_effect=observe_wait,
            ):
                worker.start()
                self.assertTrue(
                    waiter_entered.wait(TEST_WORKER_JOIN_SECONDS),
                    'The batch did not reach the held session gate.',
                )
                cancelled.set()
                self.assertTrue(finished.wait(TEST_WORKER_JOIN_SECONDS))
                worker.join(TEST_WORKER_JOIN_SECONDS)
                connection_factory.assert_not_called()
            self.assertFalse(worker.is_alive())
            self.assertEqual(1, len(failures))
            self.assertIsInstance(failures[0], linking.LinkingError)
            self.assertTrue(gate.locked())
            with gate._condition:
                self.assertEqual((), tuple(gate._waiters))
            self.assertFalse(session._pending_gallery_cancels)
        finally:
            cancelled.set()
            gate.release()
            if worker.ident is not None:
                worker.join(TEST_WORKER_JOIN_SECONDS)

    def test_bad_digest_disables_future_wire_without_current_fanout(self) -> None:
        """Reject the current malformed response and make wire fault sticky.

        Returns:
            None: Invalid bytes are not published and no single GET follows.
        """
        session = self.make_session()
        photos = self.register_page(session, (7,))
        connection = _Connection(self.response(
            200, encode_batch((7,), digest_override='0' * 64),
        ))
        with mock.patch.object(linking, '_linking_connection', return_value=connection), mock.patch.object(
            session, '_media_read',
        ) as legacy:
            with self.assertRaises(linking.LinkingResponseError):
                session.media_thumbnails(42, photos, lambda *_args: None)
            legacy.assert_not_called()
        self.assertEqual('wire_fault', session.thumbnail_batch_disabled_reason())

    def test_valid_but_changed_etag_is_typed_proof_change(self) -> None:
        """Do not disable a sound protocol for a stale descriptor proof.

        Returns:
            None: A valid body with a different strong ETag raises the typed
                refresh error and causes no fallback request.
        """
        session = self.make_session()
        photos = self.register_page(session, (7,))
        wire = encode_batch((7,), body=b'updated image')
        connection = _Connection(self.response(200, wire))
        with mock.patch.object(linking, '_linking_connection', return_value=connection), mock.patch.object(
            session, '_media_read',
        ) as legacy:
            with self.assertRaises(linking.LinkingThumbnailProofChanged):
                session.media_thumbnails(42, photos, lambda *_args: None)
            legacy.assert_not_called()
        self.assertEqual('available', session.thumbnail_batch_disabled_reason())

    def test_authorized_warm_disk_hit_uses_no_http(self) -> None:
        """Use the existing issuer/gallery cache key only under current proof.

        Returns:
            None: A fresh matching cache body is delivered without batch HTTP.
        """
        issuer = 'https://gallery.example/Galerie'
        session = self.make_session()
        photos = self.register_page(session, (7,))
        etag = '"' + hashlib.sha256(TEST_IMAGE_BODY).hexdigest() + '"'
        self.assertTrue(self.cache.write(
            self.cache.key(issuer, 42, 7, True), TEST_IMAGE_BODY,
            'image/jpeg', etag,
            gallery_key=self.cache.gallery_key(issuer, 42),
        ))
        delivered: list[linking.ThumbnailPublication | linking.LegacyThumbnailPublication] = []
        with mock.patch.object(linking, '_linking_connection') as network:
            session.media_thumbnails(
                42, photos, lambda result, *_args: delivered.append(result),
            )
            network.assert_not_called()
        self.assertEqual(TEST_IMAGE_BODY, delivered[0].body)

    def test_exact_unsupported_uses_legacy_and_sticks_for_later_call(self) -> None:
        """Use singles only after exact unsupported capability refusal.

        Returns:
            None: One POST refusal yields transient singles on both calls; the
                later call skips POST and keeps ordinary per-item authorization.
        """
        session = self.make_session()
        photos = self.register_page(session, (7,))
        connection = _Connection(self.response(
            404, b'{"error":"unsupported_capability"}',
        ))
        expected = '"' + hashlib.sha256(TEST_IMAGE_BODY).hexdigest() + '"'

        def legacy_read(
            gallery_id: int, kind: linking._NativeMediaKind,
            selector: int | str | None, cancelled: threading.Event | None,
            if_none_match: str | None = None, *, admin_preload: bool = False,
            timings: linking.MediaReadTimings | None = None,
            proof_generation_out: list[int] | None = None,
            operation_deadline: float | None = None,
        ) -> tuple[bytes, str, str, bool]:
            """Return one single-read result with its current generation.

            Args:
                gallery_id (int): Physical gallery for the selected row.
                kind (_NativeMediaKind): Requested media rendition.
                selector (int | str | None): Photo identifier.
                cancelled (threading.Event | None): Optional viewer cancellation.
                if_none_match (str | None): Optional existing validator.
                admin_preload (bool): Whether this is the private cache lane.
                timings (MediaReadTimings | None): Optional physical counters.
                proof_generation_out (list[int] | None): Session proof sink.
                operation_deadline (float | None): Shared operation deadline.

            Returns:
                tuple[bytes, str, str, bool]: Current authorized thumbnail.
            """
            del (gallery_id, kind, selector, cancelled, if_none_match,
                 admin_preload, timings, operation_deadline)
            if proof_generation_out is not None:
                proof_generation_out.append(session._private_preload_generation)
            return TEST_IMAGE_BODY, 'image/jpeg', expected, False

        published: list[linking.ThumbnailPublication | linking.LegacyThumbnailPublication] = []
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ) as connection_factory, mock.patch.object(
            session, '_media_read', side_effect=legacy_read,
        ) as legacy:
            session.media_thumbnails(
                42, photos, lambda result, *_args: published.append(result),
            )
            session.media_thumbnails(
                42, photos, lambda result, *_args: published.append(result),
            )
        self.assertEqual(2, legacy.call_count)
        self.assertEqual(1, connection_factory.call_count)
        self.assertEqual('unsupported', session.thumbnail_batch_disabled_reason())
        self.assertEqual(2, len(published))
        self.assertTrue(all(isinstance(
            item, linking.LegacyThumbnailPublication,
        ) and item.ram_eligible is False for item in published))
        self.assertIsNone(self.cache.read(self.cache.key(
            session._issuer, 42, 7, True,
        ), gallery_key=self.cache.gallery_key(session._issuer, 42)))

    def test_missing_capability_uses_original_media_route_for_old_grants(self) -> None:
        """Keep legacy thumbnail reads working across supported old grants.

        Returns:
            None: Each grant uses one original media GET and never sends batch.
        """
        grant_scopes = (
            linking.GALLERIES_SCOPE,
            linking.UPLOAD_SCOPE,
            linking.UPLOAD_SCOPE + linking.MEDIA_SCOPE_SUFFIX,
            linking.GALLERIES_SCOPE + linking.ADMIN_MEDIA_SCOPE_SUFFIX,
        )
        for image_id, scope in zip(
            TEST_LEGACY_COMPAT_IMAGE_IDS, grant_scopes, strict=True,
        ):
            with self.subTest(scope=scope):
                session = self.make_session(
                    scope=scope, thumbnail_batch_supported=False,
                )
                photos = self.register_page(session, (image_id,))
                connection = _Connection(self.media_response())
                published: list[
                    linking.ThumbnailPublication
                    | linking.LegacyThumbnailPublication
                ] = []
                with mock.patch.object(
                    linking, '_linking_connection', return_value=connection,
                ) as connection_factory:
                    session.media_thumbnails(
                        42, photos,
                        lambda result, *_args: published.append(result),
                    )
                    connection_factory.assert_called_once()

                self.assertEqual(
                    ('GET', '/Galerie/index.php?page=app_linking_media'
                     f'&gallery_id=42&kind=thumbnail&image_id={image_id}'),
                    connection.request_args,
                )
                self.assertEqual(1, connection.request_count)
                self.assertEqual(1, len(published))
                self.assertEqual(TEST_IMAGE_BODY, published[0].body)
                self.assertIsInstance(
                    published[0], linking.LegacyThumbnailPublication,
                )
                self.assertFalse(published[0].ram_eligible)
                self.assertEqual(
                    '1', connection.request_headers['X-PHP-Gallery-Photo-Cache'],
                )
                self.assertIsNone(self.cache.read(self.cache.key(
                    session._issuer, 42, image_id, True,
                ), gallery_key=self.cache.gallery_key(session._issuer, 42)))
                self.assertEqual(
                    'capability_missing', session.thumbnail_batch_disabled_reason(),
                )
                self.assertTrue(connection.closed)
                self.assertTrue(connection.response.closed)

    def test_legacy_gateway_html_503_stays_on_original_route(self) -> None:
        """Surface an old media gateway failure without hidden batch traffic.

        Returns:
            None: One sanitized 503 is raised from the legacy GET; no batch
                POST or automatic second request is attempted.
        """
        session = self.make_session(
            scope=linking.UPLOAD_SCOPE + linking.MEDIA_SCOPE_SUFFIX,
            thumbnail_batch_supported=False,
        )
        photos = self.register_page(session, (TEST_LEGACY_GATEWAY_IMAGE_ID,))
        connection = _Connection(self.media_response(status=503))
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ) as connection_factory, mock.patch.object(
            session, '_media_read', wraps=session._media_read,
        ) as legacy:
            with self.assertRaises(linking.LinkingResponseError) as raised:
                session.media_thumbnails(42, photos, lambda *_args: None)

        self.assertEqual(503, raised.exception.status)
        self.assertEqual('app_linking_media', raised.exception.route)
        self.assertEqual('html', raised.exception.content_kind)
        self.assertEqual('http_status', raised.exception.reason)
        self.assertEqual(1, legacy.call_count)
        connection_factory.assert_called_once()
        self.assertEqual(1, connection.request_count)
        self.assertEqual(
            ('GET', '/Galerie/index.php?page=app_linking_media'
             '&gallery_id=42&kind=thumbnail&image_id='
             f'{TEST_LEGACY_GATEWAY_IMAGE_ID}'),
            connection.request_args,
        )
        self.assertEqual(
            'capability_missing', session.thumbnail_batch_disabled_reason(),
        )
        self.assertTrue(connection.closed)
        self.assertTrue(connection.response.closed)

    def test_unexpected_html_404_after_negotiation_fails_closed(self) -> None:
        """Do not treat an unrecognized old-node 404 as optional support.

        Returns:
            None: The batch refusal is surfaced and no single GET follows.
        """
        session = self.make_session()
        photos = self.register_page(session, (TEST_BATCH_HTML404_IMAGE_ID,))
        connection = _Connection(_Response(404, b'<html>not found</html>', {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-store',
        }))
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ) as connection_factory, mock.patch.object(
            session, '_media_read',
        ) as legacy:
            with self.assertRaises(linking.LinkingResponseError) as raised:
                session.media_thumbnails(42, photos, lambda *_args: None)
            legacy.assert_not_called()

        self.assertEqual(404, raised.exception.status)
        self.assertEqual('app_linking_thumbnail_batch', raised.exception.route)
        self.assertEqual('html', raised.exception.content_kind)
        self.assertEqual('http_status', raised.exception.reason)
        connection_factory.assert_called_once()
        self.assertEqual(1, connection.request_count)
        self.assertEqual(
            ('POST', '/Galerie/index.php?page=app_linking_thumbnail_batch'),
            connection.request_args,
        )
        self.assertEqual(
            'available', session.thumbnail_batch_disabled_reason(),
        )
        self.assertTrue(connection.closed)
        self.assertTrue(connection.response.closed)

    def test_auth_refusals_and_target_404_never_fan_out(self) -> None:
        """Preserve authorization and target refusal without ordinary GETs.

        Returns:
            None: 401/403 expire the grant and target 404 remains a refusal.
        """
        for status in (401, 403, 404):
            with self.subTest(status=status):
                session = self.make_session()
                photos = self.register_page(session, (7,))
                body = b'{"error":"target_unavailable"}' if status == 404 else b''
                connection = _Connection(self.response(status, body))
                with mock.patch.object(
                    linking, '_linking_connection', return_value=connection,
                ), mock.patch.object(session, '_media_read') as legacy:
                    error = (linking.LinkingGrantRejected if status in (401, 403)
                             else linking.LinkingMediaUnavailable)
                    with self.assertRaises(error):
                        session.media_thumbnails(42, photos, lambda *_args: None)
                    legacy.assert_not_called()
                if status in (401, 403):
                    self.assertFalse(session.available())

    def test_long_throttle_prevents_followup_http(self) -> None:
        """Preserve the actual 429 then defer locally without a new request.

        Returns:
            None: The first error retains its server hint; the later local
                cooldown is not misreported as another HTTP throttle.
        """
        session = self.make_session()
        photos = self.register_page(session, (7,))
        connection = _Connection(self.response(
            429, retry=TEST_LONG_SERVER_RETRY_SECONDS,
        ))
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ) as connection_factory:
            with self.assertRaises(linking.LinkingMediaThrottled) as initial:
                session.media_thumbnails(42, photos, lambda *_args: None)
            self.assertEqual(
                TEST_LONG_SERVER_RETRY_SECONDS,
                initial.exception.retry_after_seconds,
            )
            with self.assertRaises(linking.LinkingThumbnailBatchDeferred) as deferred:
                session.media_thumbnails(42, photos, lambda *_args: None)
            self.assertEqual(
                'server_retry_after', deferred.exception.cooldown_source,
            )
            self.assertIs(
                initial.exception.operation_finished,
                deferred.exception.operation_finished,
            )
            self.assertNotIsInstance(
                deferred.exception, linking.LinkingMediaThrottled,
            )
            self.assertGreater(deferred.exception.retry_after_seconds, 0)
            self.assertLessEqual(
                deferred.exception.retry_after_seconds, TEST_LONG_SERVER_RETRY_SECONDS,
            )
            connection_factory.assert_called_once()
        self.assertIsNotNone(connection.request_args)

    def test_exhausted_transient_retains_cooldown_and_releases_gate(self) -> None:
        """Keep cooldown after a second 503 and leave serialized admission free.

        Returns:
            None: A later operation is deferred before HTTP and the existing
                session gate is immediately reusable.
        """
        session = self.make_session()
        photos = self.register_page(session, (7,))
        connections = [_Connection(self.response(503)), _Connection(self.response(503))]
        pending = iter(connections)

        def connect(
            issuer: SplitResult, control: linking._LinkingTransportControl,
        ) -> _Connection:
            """Return the next bounded response owner.

            Args:
                issuer (SplitResult): Validated installation issuer.
                control (_LinkingTransportControl): Active transport owner.

            Returns:
                _Connection: Next configured response owner.
            """
            del issuer, control
            return next(pending)

        with mock.patch.object(
            linking, '_linking_connection', side_effect=connect,
        ) as connection_factory:
            with self.assertRaises(linking.LinkingResponseError) as exhausted:
                session.media_thumbnails(42, photos, lambda *_args: None)
            self.assertGreater(
                session._thumbnail_batch_cooldown_until, time.monotonic(),
                'final transient response must defer subsequent operations',
            )
            self.assertLessEqual(
                session._thumbnail_batch_cooldown_until - time.monotonic(),
                TEST_BATCH_RETRY_SECONDS + TEST_RETRY_DEADLINE_TOLERANCE_SECONDS,
            )
            with self.assertRaises(linking.LinkingThumbnailBatchDeferred) as deferred:
                session.media_thumbnails(42, photos, lambda *_args: None)
            self.assertEqual('transient', deferred.exception.cooldown_source)
            self.assertIs(
                exhausted.exception.operation_finished,
                deferred.exception.operation_finished,
            )
            self.assertNotIsInstance(
                deferred.exception, linking.LinkingMediaThrottled,
            )
            self.assertEqual(2, connection_factory.call_count)
        self.assertEqual(2, sum(item.request_args is not None for item in connections))
        self.assertTrue(session._request_gate.acquire(blocking=False))
        session._request_gate.release()
        self.assertTrue(linking._LINKING_TRANSPORT_SLOTS.acquire(blocking=False))
        linking._LINKING_TRANSPORT_SLOTS.release()

    def test_deadline_fence_stops_blocked_publication_without_caller_cancel(self) -> None:
        """Make a backpressured callback exit when its operation deadline ends.

        Returns:
            None: The caller cancellation event stays unset while both the
                session and global worker gates become reusable.
        """
        session = self.make_session()
        photos = self.register_page(session, (7,))
        connection = _Connection(self.response(200, encode_batch((7,))))
        fake_clock = [time.monotonic()]
        clock = SimpleNamespace(
            monotonic=lambda: fake_clock[0], perf_counter=time.perf_counter,
        )
        caller_cancel = threading.Event()
        callback_entered = threading.Event()
        controls: list[linking._LinkingTransportControl] = []

        def blocked_publish(
            _result: linking.ThumbnailPublication | linking.LegacyThumbnailPublication,
            _timings: linking.MediaReadTimings,
            check_active: Callable[[], None],
        ) -> None:
            """Represent a full GUI queue until the shared deadline expires.

            Args:
                _result (ThumbnailPublication | LegacyThumbnailPublication):
                    Verified frame waiting for bounded publication capacity.
                _timings (MediaReadTimings): Item-specific metrics.
                check_active (Callable[[], None]): Whole-call deadline fence.

            Returns:
                None: The callback stops when the caller operation expires.
            """
            callback_entered.set()
            fake_clock[0] += linking.THUMBNAIL_BATCH_OPERATION_SECONDS
            check_active()

        def connection_factory(
            issuer: SplitResult, control: linking._LinkingTransportControl,
        ) -> _Connection:
            """Capture the transport completion event and return its response.

            Args:
                issuer (SplitResult): Validated installation issuer.
                control (_LinkingTransportControl): Worker-owned request control.

            Returns:
                _Connection: The sole configured batch response.
            """
            del issuer
            controls.append(control)
            return connection

        with mock.patch.object(linking, 'time', clock), mock.patch.object(
            linking, '_linking_connection', side_effect=connection_factory,
        ):
            with self.assertRaises(linking.LinkingError):
                session.media_thumbnails(
                    42, photos, blocked_publish, caller_cancel,
                )
        self.assertTrue(callback_entered.is_set())
        self.assertFalse(caller_cancel.is_set())
        self.assertEqual(1, len(controls))
        self.assertTrue(controls[0].operation_finished.wait(TEST_WORKER_JOIN_SECONDS))
        self.assertTrue(session._request_gate.acquire(blocking=False))
        session._request_gate.release()
        self.assertTrue(linking._LINKING_TRANSPORT_SLOTS.acquire(blocking=False))
        linking._LINKING_TRANSPORT_SLOTS.release()

    def test_live_session_cancellation_during_cooldown_uses_no_http(self) -> None:
        """Cancel the logical gallery operation during automatic retry backoff.

        Returns:
            None: The live session remains usable, the caller event stays unset,
                and no retry starts after the first HTTP failure.
        """
        session = self.make_session()
        photos = self.register_page(session, (7,))
        connections = [_Connection(self.response(503)), _Connection(self.response(503))]
        pending = iter(connections)
        wait_started = threading.Event()
        outcome_ready = threading.Event()

        class ObservedCancelEvent(threading.Event):
            """Signal when the cooldown owner begins an interruptible wait."""

            def wait(self, timeout: float | None = None) -> bool:
                """Expose the actual cancellation wait to the test coordinator.

                Args:
                    timeout (float | None): Maximum production wait interval.

                Returns:
                    bool: Whether the caller event was set.
                """
                wait_started.set()
                return super().wait(timeout)

        caller_cancel = ObservedCancelEvent()

        def connect(
            issuer: SplitResult, control: linking._LinkingTransportControl,
        ) -> _Connection:
            """Return the next bounded transient response.

            Args:
                issuer (SplitResult): Validated installation issuer.
                control (_LinkingTransportControl): Active transport owner.

            Returns:
                _Connection: Next configured response owner.
            """
            del issuer, control
            return next(pending)

        def deferred_operation() -> None:
            """Attempt one operation and retain its typed cancellation result.

            Returns:
                None: Signals completion after cancellation from cooldown wait.
            """
            try:
                session.media_thumbnails(
                    42, photos, lambda *_args: None, caller_cancel,
                )
            except linking.LinkingError:
                outcome_ready.set()

        with mock.patch.object(
            linking, '_linking_connection', side_effect=connect,
        ) as connection_factory:
            worker = threading.Thread(target=deferred_operation)
            worker.start()
            self.assertTrue(wait_started.wait(TEST_WORKER_JOIN_SECONDS))
            session.cancel_pending(gallery_only=True)
            self.assertTrue(outcome_ready.wait(TEST_WORKER_JOIN_SECONDS))
            worker.join(TEST_WORKER_JOIN_SECONDS)
            self.assertFalse(worker.is_alive())
            self.assertEqual(1, connection_factory.call_count)

        self.assertTrue(session.available())
        self.assertFalse(caller_cancel.is_set())
        self.assertEqual(1, sum(item.request_args is not None for item in connections))
        self.assertFalse(session._pending_gallery_cancels)
        self.assertTrue(session._request_gate.acquire(blocking=False))
        session._request_gate.release()
        self.assertTrue(linking._LINKING_TRANSPORT_SLOTS.acquire(blocking=False))
        linking._LINKING_TRANSPORT_SLOTS.release()

