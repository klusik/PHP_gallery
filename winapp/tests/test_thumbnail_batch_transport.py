# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_thumbnail_batch_transport.py
# Module Type: Regression Test
# Purpose: Verify the production thumbnail batch HTTP transport boundary.
# Responsibilities:
#   - Exercise streaming, strict response validation and safe error mapping.
#   - Verify exact request serialization and connection ownership.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Test batch transport with an owned in-memory HTTP response."""

import hashlib
import json
import socket
import struct
import sys
import threading
import time
from types import SimpleNamespace
import unittest
from email.message import Message
from pathlib import Path
from typing import Callable
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import app_linking as linking
from uploader.thumbnail_batch_protocol import ValidatedItem


# Purpose: Bound the lifetime of an in-memory transport test control.
# Type: float. Units: seconds. Scope: one fake request.
# Consumers: BatchTransportTests.invoke. Rationale: thirty seconds prevents
# scheduling jitter from becoming an accidental cancellation assertion.
TEST_DEADLINE_SECONDS = 30.0

# Purpose: Exercise the complete accepted Server-Timing grammar.
# Type: str. Units: bounded numeric response header. Scope: one batch response.
# Consumers: BatchTransportTests accepted timing fixture.
# Rationale: The exact PHP profiler format is parsed without retaining text.
TEST_SERVER_TIMING = (
    'gallery-php;dur=1.000, '
    'gallery-sql;dur=0.500;desc="n=1;max_ms=0.500;failed=0;cap=0", '
    'gallery-auth;dur=0.200, gallery-tree;dur=0.200, '
    'gallery-hash;dur=0.000;desc="bytes=0", '
    'gallery-media;dur=0.000'
)

# Purpose: Exercise a valid Retry-After without waiting in a fixture.
# Type: int. Units: seconds. Scope: synthetic thumbnail batch 429.
# Consumers: BatchTransportTests throttle mapping assertions.
# Rationale: One hour proves the full accepted value survives transport mapping.
TEST_RETRY_AFTER_SECONDS = 3600


def framed_item(image_id: str = '1', digest: str | None = None) -> bytes:
    """Encode one independent production frame and end marker.

    Args:
        image_id (str): Wire identity, optionally different from the request.
        digest (str | None): Digest override for integrity failure coverage.

    Returns:
        bytes: Complete one-item PGB1 response.
    """
    body = b'jpeg-fixture'
    metadata = json.dumps({
        'v': 1, 'image_id': image_id, 'request_index': 0, 'status': 'ok',
        'mime': 'image/jpeg', 'length': len(body),
        'sha256': digest or hashlib.sha256(body).hexdigest(), 'etag': None,
    }, separators=(',', ':')).encode('utf-8')
    # The two struct layouts are the protocol's fixed header and item lengths.
    return (struct.pack('>4sBH', b'PGB1', 1, 1) + b'I'
            + struct.pack('>HI', len(metadata), len(body)) + metadata + body
            + b'E' + struct.pack('>H', 1))


class BatchTransportTests(unittest.TestCase):
    """Exercise one batch POST without opening a network socket."""

    def invoke(
        self, chunks: list[bytes], *, status: int = 200,
        headers: dict[str, str] | None = None,
        cancel: threading.Event | None = None,
        on_publish: Callable[[], None] | None = None,
        timings: linking.MediaReadTimings | None = None,
    ) -> tuple[list[ValidatedItem], mock.MagicMock, mock.MagicMock]:
        """Call the helper using a fake connection and caller-owned chunks.

        Args:
            chunks (list[bytes]): Consecutive response fragments followed by EOF.
            status (int): HTTP status supplied by the fake response.
            headers (dict[str, str] | None): Complete replacement response headers.
            cancel (threading.Event | None): Optional request cancellation event.
            on_publish (Callable[[], None] | None): Optional callback invoked
                after each item.
            timings (linking.MediaReadTimings | None): Optional caller metrics.

        Returns:
            tuple[list[ValidatedItem], mock.MagicMock, mock.MagicMock]: Published
                items, connection and response mocks for request assertions.
        """
        fields = Message()
        values = headers if headers is not None else {
            'Content-Type': linking.THUMBNAIL_BATCH_MIME,
            'Cache-Control': 'private, no-store', 'Vary': 'Authorization',
            'Server-Timing': TEST_SERVER_TIMING,
        }
        for key, value in values.items():
            fields[key] = value
        response = mock.MagicMock()
        response.status = status
        response.headers = fields
        response.read1.side_effect = list(chunks) + [b'']
        response.read.return_value = b''.join(chunks)
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        items: list[ValidatedItem] = []

        def publish(item: ValidatedItem) -> None:
            """Collect a validated item and notify the optional test hook.

            Args:
                item (ValidatedItem): Digest-verified response item.

            Returns:
                None: Records the item before invoking the test hook.
            """
            self.assertLessEqual(response.read1.call_count, len(chunks))
            items.append(item)
            if on_publish is not None:
                on_publish()

        control = linking._LinkingTransportControl(
            time.monotonic() + TEST_DEADLINE_SECONDS, cancel,
        )
        with mock.patch.object(linking, '_linking_connection', return_value=connection):
            linking._native_thumbnail_batch_read_once(
                'https://gallery.example/Galerie', 'secret', ('1',), 42,
                control, publish, timings=timings,
            )
        return items, connection, response

    def test_exact_post_and_incremental_delivery(self) -> None:
        """Publish the verified item before reading the response footer.

        Returns:
            None: Checks request bytes, authorization and connection closure.
        """
        wire = framed_item()
        calls: list[bool] = []
        timings = linking.MediaReadTimings()
        items, connection, response = self.invoke(
            [wire[:-3], wire[-3:]], on_publish=lambda: calls.append(True),
            timings=timings,
        )
        self.assertEqual([True], calls)
        self.assertEqual(b'jpeg-fixture', items[0].data)
        self.assertEqual(3, response.read1.call_count)
        args, kwargs = connection.request.call_args
        self.assertEqual(('POST', '/Galerie/index.php?page=app_linking_thumbnail_batch'), args)
        self.assertEqual(b'{"v":1,"gallery_id":"42","image_ids":["1"]}', kwargs['body'])
        self.assertEqual('Bearer secret', kwargs['headers']['Authorization'])
        self.assertEqual(1, timings.http_requests)
        self.assertEqual(len(wire), timings.encoded_bytes)
        self.assertGreater(timings.body_ms, 0.0)
        self.assertIsNotNone(timings.server_timing)
        self.assertEqual(1.0, timings.server_timing.php_ms)
        connection.close.assert_called_once()

    def test_header_timeout_keeps_only_the_batch_transport_step(self) -> None:
        """Attribute a batch response-header timeout without request secrets.

        Returns:
            None: The typed refusal reports headers and publishes no items.
        """
        connection = mock.MagicMock()
        connection.getresponse.side_effect = socket.timeout(
            'https://private.example/Galerie bearer=fixture-secret',
        )
        publish = mock.Mock()
        with mock.patch.object(
            linking, '_linking_connection', return_value=connection,
        ):
            with self.assertRaises(linking.LinkingResponseError) as raised:
                linking._run_linking_transport(
                    lambda control: linking._native_thumbnail_batch_read_once(
                        'https://private.example/Galerie', 'fixture-secret',
                        ('1',), 42, control, publish,
                    ),
                    None, TEST_DEADLINE_SECONDS,
                    linking.LINKING_SOCKET_TIMEOUT_SECONDS,
                    phase='media', route='app_linking_thumbnail_batch',
                )

        self.assertEqual('headers', raised.exception.transport_step)
        self.assertIn('route=app_linking_thumbnail_batch',
                      raised.exception.safe_summary)
        self.assertNotIn('private.example', raised.exception.safe_summary)
        self.assertNotIn('fixture-secret', raised.exception.safe_summary)
        publish.assert_not_called()

    def test_body_timing_excludes_publish_callback_duration(self) -> None:
        """Exclude worker-side publication from network and body time.

        Returns:
            None: Deterministic clocks isolate 700 ms network and 600 ms body.
        """
        wire = framed_item()
        readings = iter((0.0, 0.1, 0.2, 0.3, 0.4, 0.7, 1.0, 1.2))
        clock = SimpleNamespace(
            monotonic=time.monotonic,
            perf_counter=mock.Mock(side_effect=lambda: next(readings)),
        )
        timings = linking.MediaReadTimings()
        with mock.patch.object(linking, 'time', clock):
            self.invoke([wire], on_publish=lambda: None, timings=timings)
        self.assertAlmostEqual(600.0, timings.body_ms)
        self.assertAlmostEqual(700.0, timings.network_ms)

    def test_invalid_wire_and_headers_fail_closed(self) -> None:
        """Reject malformed identity, digest, footer, MIME and length.

        Returns:
            None: Each malformed response raises a bounded safe diagnosis.
        """
        valid = framed_item()
        for wire in (framed_item('2'), framed_item(digest='0' * 64), valid[:-1]):
            with self.subTest(wire=wire[-8:]):
                with self.assertRaises(linking.LinkingResponseError):
                    self.invoke([wire])
        for fields in (
            {'Content-Type': 'image/jpeg', 'Cache-Control': 'private, no-store',
             'Vary': 'Authorization'},
            {'Content-Type': linking.THUMBNAIL_BATCH_MIME,
             'Cache-Control': 'private, no-store', 'Vary': 'Authorization',
             'Content-Length': str(len(valid) + 1)},
            {'Content-Type': linking.THUMBNAIL_BATCH_MIME,
             'Cache-Control': 'private, no-store', 'Vary': 'Authorization',
             'Content-Length': str(linking.PRODUCTION_MAX_RESPONSE_BYTES + 1)},
            {'Content-Type': linking.THUMBNAIL_BATCH_MIME,
             'Cache-Control': 'private, no-store', 'Vary': 'Authorization',
             'Transfer-Encoding': 'chunked', 'Content-Length': str(len(valid))},
            {'Content-Type': linking.THUMBNAIL_BATCH_MIME,
             'Cache-Control': 'private, no-store', 'Vary': 'Authorization',
             'Content-Encoding': 'gzip'},
            {'Content-Type': linking.THUMBNAIL_BATCH_MIME,
             'Cache-Control': 'public', 'Vary': 'Authorization'},
        ):
            with self.subTest(fields=fields):
                with self.assertRaises(linking.LinkingResponseError):
                    self.invoke([valid], headers=fields)

    def test_statuses_and_unsupported_are_distinct(self) -> None:
        """Keep authentication, throttling and explicit unsupported distinct.

        Returns:
            None: The caller receives typed failures without publishing items.
        """
        for status in (401, 403):
            with self.subTest(status=status):
                with self.assertRaises(linking.LinkingGrantRejected):
                    self.invoke([], status=status)
        with self.assertRaises(linking.LinkingMediaThrottled) as throttled:
            self.invoke([], status=429, headers={
                'Retry-After': str(TEST_RETRY_AFTER_SECONDS),
            })
        self.assertEqual(
            TEST_RETRY_AFTER_SECONDS, throttled.exception.retry_after_seconds,
        )
        self.assertEqual('app_linking_thumbnail_batch', throttled.exception.route)
        fields = {'Content-Type': 'application/json; charset=utf-8',
                  'Cache-Control': 'no-store'}
        with self.assertRaises(linking.LinkingThumbnailBatchUnsupported):
            self.invoke([b'{"error":"unsupported_capability"}'], status=404, headers=fields)
        with self.assertRaises(linking.LinkingMediaUnavailable):
            self.invoke([b'{"error":"target_unavailable"}'], status=404, headers=fields)
        with self.assertRaises(linking.LinkingResponseError) as generic:
            self.invoke([b'{"error":"unknown"}'], status=404, headers=fields)
        self.assertEqual(404, generic.exception.status)
        self.assertNotIsInstance(generic.exception, linking.LinkingThumbnailBatchUnsupported)

    def test_worker_preserves_throttle_route_and_retry(self) -> None:
        """Preserve the batch route and full validated delay across admission.

        Returns:
            None: The worker reports the same typed throttle to its caller.
        """
        def fail(control: linking._LinkingTransportControl) -> dict[str, linking.JsonValue]:
            """Emit a simulated batch throttle from the admitted worker.

            Args:
                control (linking._LinkingTransportControl): Worker deadline owner.

            Returns:
                dict[str, linking.JsonValue]: No result because this always fails.
            """
            control.remaining()
            raise linking.LinkingMediaThrottled(
                TEST_RETRY_AFTER_SECONDS,
                route='app_linking_thumbnail_batch',
            )

        with self.assertRaises(linking.LinkingMediaThrottled) as result:
            linking._run_linking_transport(
                fail, None, TEST_DEADLINE_SECONDS, TEST_DEADLINE_SECONDS,
                phase='media', route='app_linking_thumbnail_batch',
            )
        self.assertEqual('app_linking_thumbnail_batch', result.exception.route)
        self.assertEqual(
            TEST_RETRY_AFTER_SECONDS, result.exception.retry_after_seconds,
        )
        self.assertEqual('app_linking_media', linking.LinkingMediaThrottled().route)
        self.assertEqual(
            linking.MEDIA_RETRY_AFTER_MAX_SECONDS,
            linking.LinkingMediaThrottled(
                linking.MEDIA_RETRY_AFTER_MAX_SECONDS,
            ).retry_after_seconds,
        )
        self.assertIsNone(linking.LinkingMediaThrottled(
            TEST_RETRY_AFTER_SECONDS,
        ).retry_after_seconds)
        with self.assertRaises(ValueError):
            linking.LinkingMediaThrottled(route='invalid')

    def test_cancellation_interrupts_before_next_frame(self) -> None:
        """Stop after a verified item when the caller cancels publication.

        Returns:
            None: Cancellation prevents the remaining response from completing.
        """
        cancel = threading.Event()
        wire = framed_item()
        with self.assertRaises(linking.LinkingError):
            self.invoke([wire[:-3], wire[-3:]], cancel=cancel, on_publish=cancel.set)

    def test_failed_response_closes_owned_connection(self) -> None:
        """Release the dedicated connection after a refused HTTP response.

        Returns:
            None: No item is published and the connection is closed once.
        """
        response = mock.MagicMock()
        response.status = 403
        response.headers = Message()
        response.close.side_effect = OSError('synthetic close failure')
        connection = mock.MagicMock()
        connection.getresponse.return_value = response
        publish = mock.Mock()
        control = linking._LinkingTransportControl(
            time.monotonic() + TEST_DEADLINE_SECONDS, None,
        )
        with mock.patch.object(linking, '_linking_connection', return_value=connection):
            with self.assertRaises(linking.LinkingGrantRejected):
                linking._native_thumbnail_batch_read_once(
                    'https://gallery.example/Galerie', 'secret', ('1',), 42,
                    control, publish,
                )
        publish.assert_not_called()
        response.close.assert_called_once()
        connection.close.assert_called_once()
