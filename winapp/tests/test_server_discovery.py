# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_server_discovery.py
# Module Type: Regression Test
# Purpose: Verify bounded unauthenticated native server discovery.
# Responsibilities:
#   - Validate installation mounts, response types and transport failures.
#   - Ensure discovery never sends the gallery-scoped upload key.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Isolated server-discovery tests without real network traffic."""

import io
import sys
import unittest
from pathlib import Path
from unittest import mock
from urllib.error import HTTPError, URLError

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import server_discovery as discovery


class ServerDiscoveryTests(unittest.TestCase):
    """Exercise exact public discovery and safe failure classifications."""

    def test_root_mount_and_upload_alias(self) -> None:
        """Preserve the selected HTTPS origin and installation mount.

        Returns:
            None: Valid roots and legacy upload routes resolve consistently.
        """
        expected = 'https://gallery.example/one/two/index.php?page=app_linking_discovery'
        for source in (
            'https://gallery.example/one/two/',
            'https://gallery.example/one/two/api/upload',
            'https://gallery.example/one/two/index.php?page=upload_automation_upload',
        ):
            with self.subTest(source=source):
                self.assertEqual(expected, discovery.discovery_url(source))
        self.assertEqual(
            'https://gallery.example/index.php?page=app_linking_discovery',
            discovery.discovery_url('https://gallery.example'),
        )

    def test_unsafe_url_never_creates_a_request(self) -> None:
        """Reject insecure or ambiguous inputs before initiating any traffic.

        Returns:
            None: Unsafe origins and mount traversal do not reach the opener.
        """
        values = (
            'http://gallery.example/',
            'https://user:secret@gallery.example/',
            'https://gallery.example/%2e%2e/private',
            'https://gallery.example/%252e%252e/private',
            'https://gallery.example/a%2fb',
            'https://gallery.example/a%5cb',
            'https://gallery.example/a?token=secret',
            'https://gallery.example/a#fragment',
            'https://gallery.example:99999/',
            'https://gallery.example//other',
        )
        with mock.patch.object(discovery, 'build_opener') as opener:
            for value in values:
                with self.subTest(value=value):
                    self.assertEqual('error', discovery.discover_server(value))
            opener.assert_not_called()

    def test_valid_response_is_unauthenticated_and_bounded(self) -> None:
        """Require valid JSON and send only public discovery headers.

        Returns:
            None: Exact false and true capability states remain distinct.
        """
        url = 'https://gallery.example/sub/index.php?page=app_linking_discovery'
        body = b'{"contract_version":1,"app_linking":{"available":false}}'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.geturl.return_value = url
        response.headers = {'Content-Type': 'application/json; charset=utf-8'}
        response.read.return_value = body
        with mock.patch.object(discovery, 'build_opener') as factory:
            factory.return_value.open.return_value = response
            self.assertEqual(
                'unavailable', discovery.discover_server('https://gallery.example/sub'),
            )
            request = factory.return_value.open.call_args.args[0]
            self.assertEqual(url, request.full_url)
            self.assertEqual('GET', request.get_method())
            self.assertEqual({'accept', 'user-agent'},
                             {name.lower() for name, _ in request.header_items()})
            self.assertNotIn('pgu_', str(request.header_items()))
            self.assertEqual(discovery.DISCOVERY_TIMEOUT_SECONDS,
                             factory.return_value.open.call_args.kwargs['timeout'])
            response.read.assert_called_with(discovery.DISCOVERY_MAX_BYTES + 1)
            response.read.return_value = body.replace(b'false', b'true')
            self.assertEqual('reported_available',
                             discovery.discover_server('https://gallery.example/sub'))

    def test_invalid_payloads_do_not_advertise_linking(self) -> None:
        """Reject unsafe or unknown response envelopes and destinations.

        Returns:
            None: Malformed JSON, duplicates, wrong types and oversize fail.
        """
        url = 'https://gallery.example/index.php?page=app_linking_discovery'
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.geturl.return_value = url
        response.headers = {'Content-Type': 'application/json'}
        cases = (
            b'<html>not json</html>',
            b'{"contract_version":1,"app_linking":{"available":false},"extra":NaN}',
            b'{"contract_version":1,"app_linking":{"available":false},"extra":Infinity}',
            b'{"contract_version":true,"app_linking":{"available":true}}',
            b'{"contract_version":2,"app_linking":{"available":true}}',
            b'{"contract_version":1,"app_linking":{"available":1}}',
            b'{"contract_version":1,"contract_version":1,"app_linking":{"available":true}}',
            b'x' * (discovery.DISCOVERY_MAX_BYTES + 1),
        )
        with mock.patch.object(discovery, 'build_opener') as factory:
            factory.return_value.open.return_value = response
            for body in cases:
                response.read.return_value = body
                with self.subTest(body=body[:100]):
                    self.assertEqual('error', discovery.discover_server('https://gallery.example'))
            response.read.return_value = b'{"contract_version":1,"app_linking":{"available":false}}'
            response.headers = {'Content-Type': 'text/html'}
            self.assertEqual('error', discovery.discover_server('https://gallery.example'))
            response.headers = {'Content-Type': 'application/json'}
            response.geturl.return_value = 'https://other.example/index.php'
            self.assertEqual('error', discovery.discover_server('https://gallery.example'))

    def test_absent_endpoint_is_distinct_from_transport_errors(self) -> None:
        """Classify 404/405 at the selected URL without following redirects.

        Returns:
            None: Unexpected statuses, redirects and TLS errors remain errors.
        """
        url = 'https://gallery.example/index.php?page=app_linking_discovery'
        with mock.patch.object(discovery, 'build_opener') as factory:
            for code in (404, 405, 302, 500):
                error = HTTPError(url, code, 'fixture', {}, io.BytesIO())
                factory.return_value.open.side_effect = error
                expected = 'unsupported' if code in (404, 405) else 'error'
                with self.subTest(code=code):
                    self.assertEqual(expected,
                                     discovery.discover_server('https://gallery.example'))
            factory.return_value.open.side_effect = URLError('TLS failure')
            self.assertEqual('error', discovery.discover_server('https://gallery.example'))
        self.assertIsNone(discovery._NoRedirect().redirect_request(
            mock.Mock(), io.BytesIO(), 302, 'redirect', mock.Mock(),
            'https://other.example/',
        ))


if __name__ == '__main__':
    unittest.main()
