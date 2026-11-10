# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_upload_transport_recovery.py
# Module Type: Regression Test
# Purpose: Verify retry metadata and actual transport completion for upload recovery.
# Responsibilities:
#   - Separate known pre-storage refusal from generic post-dispatch failure.
#   - Exercise the common native pipeline lease without any external traffic.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Transport recovery evidence with simulated responses and real local leases."""

import json
import socket
import sys
import tempfile
import unittest
from email.message import Message
from email.utils import formatdate
from pathlib import Path
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import app_linking as linking
from uploader.linked_upload import UploadPipelineLease


class UploadTransportRecoveryTests(unittest.TestCase):
    """Trust only validated transport metadata when authorizing another head attempt."""

    def response_error(self, status: int, attested: bool = False) -> linking.LinkingResponseError:
        """Execute the real transport against one controlled HTTP response.

        Args:
            status (int): Synthetic HTTP refusal status.
            attested (bool): Whether the application marked pre-storage admission refusal.

        Returns:
            LinkingResponseError: Actual typed failure with completion and retry metadata.
        """
        response = mock.MagicMock()
        response.status = status
        headers = Message()
        headers['Content-Type'] = 'application/json'
        headers['Cache-Control'] = 'no-store'
        headers['Retry-After'] = '60'
        if attested:
            headers['X-PHP-Gallery-Upload-Phase'] = 'rejected-before-store-v1'
        response.headers = headers
        response.read.return_value = json.dumps({'error': 'temporarily_unavailable'}).encode()
        response.__enter__.return_value = response
        connection = mock.Mock()
        connection.getresponse.return_value = response
        with mock.patch.object(linking, '_linking_connection', return_value=connection):
            with self.assertRaises(linking.LinkingResponseError) as raised:
                linking._request_json(
                    'https://gallery.example.test', 'app_linking_upload', 'POST',
                    b'body', 'pga_' + 'a' * 64,
                    multipart_boundary='PHPGalleryFixtureBoundary', content_length=4,
                )
        connection.request.assert_called_once()
        return raised.exception

    def test_retry_after_validation_includes_http_dates_and_ambiguity(self) -> None:
        """Reject malformed or repeated hints without discarding valid HTTP dates.

        Returns:
            None: The queue receives bounded positive seconds or explicit absence.
        """
        # Structural fixed Unix epoch makes HTTP-date decoding deterministic.
        epoch = 1_800_000_000.0
        self.assertEqual(60, linking.validated_retry_after(['60'], epoch))
        self.assertEqual(30, linking.validated_retry_after([formatdate(epoch + 30, usegmt=True)], epoch))
        for values in ([], ['0'], ['-1'], ['60', '60'], ['bad'], ['999999999']):
            self.assertIsNone(linking.validated_retry_after(values, epoch))

    def test_application_limit_is_distinct_from_ambiguous_server_failure(self) -> None:
        """Retain phase and Retry-After without treating every 503 as safe rejection.

        Returns:
            None: Exact 429 and attested 503 are safe; generic 503 remains unknown.
        """
        limited = self.response_error(429)
        self.assertEqual(60, limited.retry_after_seconds)
        self.assertTrue(limited.definitely_rejected)
        self.assertTrue(limited.request_started)
        self.assertTrue(limited.operation_finished.is_set())
        generic = self.response_error(503)
        self.assertFalse(generic.definitely_rejected)
        attested = self.response_error(503, True)
        self.assertTrue(attested.definitely_rejected)

    def test_dns_failure_has_no_dispatch_and_finished_worker_evidence(self) -> None:
        """Prove an unsent failure using the actual worker's completion event.

        Returns:
            None: The request never starts and the original worker has terminated.
        """
        connection = mock.Mock()
        connection.connect.side_effect = socket.gaierror('synthetic unavailable DNS')
        with mock.patch.object(linking, '_linking_connection', return_value=connection):
            with self.assertRaises(linking.LinkingResponseError) as raised:
                linking._request_json(
                    'https://gallery.example.test', 'app_linking_upload', 'POST',
                    b'body', 'pga_' + 'a' * 64,
                    multipart_boundary='PHPGalleryFixtureBoundary', content_length=4,
                )
        self.assertFalse(raised.exception.request_started)
        self.assertTrue(raised.exception.operation_finished.is_set())
        connection.request.assert_not_called()

    def test_common_lease_refuses_a_second_live_pipeline(self) -> None:
        """Fence Watcher/import/one-shot owners through the same real native lease.

        Returns:
            None: A second consumer is refused until the first explicitly releases ownership.
        """
        with tempfile.TemporaryDirectory() as temporary:
            profile = Path(temporary)
            first, second = UploadPipelineLease(profile), UploadPipelineLease(profile)
            first.acquire()
            try:
                with self.assertRaises(linking.LinkingError):
                    second.acquire()
            finally:
                first.release()
            second.acquire()
            second.release()
