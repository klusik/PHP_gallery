# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_flight_delivery.py
# Module Type: Regression Test
# Purpose: Verify gallery report capability, delivery and ambiguous retry recovery.
# Responsibilities:
#   - Exercise PDF identity, multipart fields, progress and checksum receipts
#   - Ensure a committed upload is reconciled before a duplicate retry
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Protocol fixtures for the gallery-scoped flight report delivery client."""

import io
import json
import sys
import tempfile
import unittest
from pathlib import Path
from typing import (
    Any,
    Callable,
    Dict,
    List,
    Literal,
    Optional,
    TypedDict,
    Union,
)
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import flight_delivery as DELIVERY


class FlightReportReceipt(TypedDict):
    """Describe the protected document metadata returned by the Gallery."""

    id: str
    sha256: str
    size: int
    filename: str


class FlightReportStatusRequest(TypedDict):
    """Describe the JSON identity and checksum used for reconciliation."""

    action: Literal["flight_report_status"]
    report_id: str
    language: str
    sha256: str


class FlightReportUploadFields(TypedDict):
    """Describe the multipart fields sent with one report PDF."""

    action: Literal["flight_report"]
    report_id: str
    session_id: str
    language: str
    sha256: str


class FlightReportStatusResponse(TypedDict):
    """Describe the server's identity lookup and optional document receipt."""

    ok: bool
    action: Literal["flight_report_status"]
    found: bool
    conflict: bool
    report: Optional[FlightReportReceipt]


class FlightReportUploadResponse(TypedDict):
    """Describe an accepted report attachment receipt."""

    ok: bool
    action: Literal["flight_report"]
    found: bool
    conflict: bool
    report: FlightReportReceipt


class FlightInventoryResponse(TypedDict):
    """Describe inventory capability flags returned by the uploader API."""

    ok: bool
    capabilities: Dict[str, bool]


class FakeResponse:
    """Return a controlled HTTP status and JSON response body.

    Args:
        status (int): HTTP status presented to the delivery client.
        payload (Dict[str, Any]): JSON object returned by the fake server.

    Returns:
        None: Initializes the fake response.
    """

    def __init__(self, status: int, payload: Dict[str, Any]) -> None:
        """Encode one response object for bounded client reads.

        Args:
            status (int): HTTP status presented to the client.
            payload (Dict[str, Any]): JSON response body object.

        Returns:
            None: Initializes this response fixture.
        """
        self.status = status
        self.body = json.dumps(payload).encode("utf-8")

    def read(self, limit: int) -> bytes:
        """Return no more than the requested response byte limit.

        Args:
            limit (int): Maximum response bytes requested by the client.

        Returns:
            bytes: Encoded JSON response prefix.
        """
        return self.body[:limit]

    def getheader(self, name: str, default: str = "") -> str:
        """Expose a JSON response header to malformed-response diagnostics.

        Args:
            name (str): Case-insensitive HTTP header requested by transport.
            default (str): Value returned for an absent header.

        Returns:
            str: JSON media type for Content-Type, otherwise the default.
        """
        return "application/json" if name.lower() == "content-type" else default


class FakeConnection:
    """Capture one HTTP request while returning a controlled JSON response.

    Args:
        response (FakeResponse): Response to expose after request transmission.

    Returns:
        None: Initializes the fake HTTP connection.
    """

    def __init__(self, response: FakeResponse) -> None:
        """Initialize captured request fields and response state.

        Args:
            response (FakeResponse): Response to expose to the caller.

        Returns:
            None: Initializes this connection fixture.
        """
        self.response = response
        self.headers: Dict[str, str] = {}
        self.sent = bytearray()
        self.method = ""
        self.target = ""
        self.request_body = b""
        self.closed = False

    def request(
        self,
        method: str,
        target: str,
        body: bytes,
        headers: Dict[str, str],
    ) -> None:
        """Capture a non-streamed JSON request.

        Args:
            method (str): HTTP method used by the client.
            target (str): Request path and query.
            body (bytes): Encoded JSON body.
            headers (Dict[str, str]): Request headers.

        Returns:
            None: Stores the captured request.
        """
        self.method = method
        self.target = target
        self.request_body = body
        self.headers = dict(headers)

    def putrequest(self, method: str, target: str) -> None:
        """Capture the start line for one streamed multipart request.

        Args:
            method (str): HTTP method used by the client.
            target (str): Request path and query.

        Returns:
            None: Stores the captured request line.
        """
        self.method = method
        self.target = target

    def putheader(self, name: str, value: str) -> None:
        """Capture one outgoing HTTP header.

        Args:
            name (str): Header name.
            value (str): Header value.

        Returns:
            None: Stores the header.
        """
        self.headers[name] = value

    def endheaders(self) -> None:
        """Mark the request headers as complete.

        Returns:
            None: The fixture needs no additional header processing.
        """
        return None

    def send(self, data: bytes) -> None:
        """Capture one body chunk sent by the client.

        Args:
            data (bytes): Request body bytes.

        Returns:
            None: Appends bytes to the captured request body.
        """
        self.sent.extend(data)

    def getresponse(self) -> FakeResponse:
        """Return the configured response after the request was sent.

        Returns:
            FakeResponse: Configured status and JSON body.
        """
        return self.response

    def close(self) -> None:
        """Record connection cleanup by the delivery client.

        Returns:
            None: Marks this fixture closed.
        """
        self.closed = True


class FlightDeliveryTests(unittest.TestCase):
    """Exercise delivery protocol using isolated files and fake HTTP exchanges."""

    def setUp(self: "FlightDeliveryTests") -> None:
        """Create one complete small PDF in an isolated temporary directory.

        Returns:
            None: Prepares state for one delivery test.
        """
        self.temp_directory = tempfile.TemporaryDirectory()
        self.pdf = Path(self.temp_directory.name) / "local-report.pdf"
        self.pdf_bytes = (
            b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
        )
        self.pdf.write_bytes(self.pdf_bytes)
        self.digest = DELIVERY.hashlib.sha256(self.pdf_bytes).hexdigest()

    def tearDown(self: "FlightDeliveryTests") -> None:
        """Remove the isolated PDF after each protocol scenario.

        Returns:
            None: Cleans up temporary test state.
        """
        self.temp_directory.cleanup()

    def _status_response(
        self: "FlightDeliveryTests",
        found: bool,
    ) -> FlightReportStatusResponse:
        """Build one matching status response for the current PDF.

        Args:
            found (bool): Whether the server already has the report.

        Returns:
            FlightReportStatusResponse: Status result with an optional typed document receipt.
        """
        report = {
            "id": "a" * 32,
            "sha256": self.digest,
            "size": len(self.pdf_bytes),
            "filename": "flight-report-a-en.pdf",
        }
        return {
            "ok": True,
            "action": "flight_report_status",
            "found": found,
            "conflict": False,
            "report": report if found else None,
        }

    def test_host_checkpoint_refuses_capability_and_destination_before_connect(self) -> None:
        """Do not open either inventory request after host admission fails.

        Returns:
            None: Both request owners preserve the refusal without networking.
        """
        def refused() -> None:
            """Simulate unavailable host evidence without exposing its details.

            Returns:
                None: Never returns successfully in this negative fixture.

            Raises:
                OSError: Synthetic host storage failure with private text.
            """
            raise OSError('private checkpoint detail')

        with mock.patch.object(DELIVERY, '_connection_target') as connect:
            for action in ('capability', 'destination'):
                with self.subTest(action=action):
                    with self.assertRaises(DELIVERY.FlightRequestAdmissionError) as error:
                        if action == 'capability':
                            DELIVERY.check_report_capability(
                                'https://gallery.test/upload', 'key', refused,
                            )
                        else:
                            DELIVERY.saved_report_destination(
                                'https://gallery.test/upload', 'key', 'Gallery', refused,
                            )
                    self.assertNotIn('private checkpoint detail', str(error.exception))
            connect.assert_not_called()

    def test_host_checkpoint_precedes_each_report_attempt(self) -> None:
        """Stop status, multipart or reconciliation without a hidden replay.

        Returns:
            None: Refusal opens no next connection and retains local PDF bytes.
        """
        for denied_attempt in (1, 2, 3):
            with self.subTest(denied_attempt=denied_attempt):
                status = FakeConnection(FakeResponse(200, self._status_response(False)))
                upload = FakeConnection(FakeResponse(503, {'ok': False, 'error': 'unavailable'}))
                attempts = [0]

                def checkpoint() -> None:
                    """Refuse one exact request using a synthetic vault failure.

                    Returns:
                        None: Earlier attempts remain permitted.

                    Raises:
                        OSError: The selected attempt has unavailable evidence.
                    """
                    attempts[0] += 1
                    if attempts[0] == denied_attempt:
                        raise OSError('private checkpoint detail')

                with mock.patch.object(
                    DELIVERY, '_connection_target',
                    side_effect=[(status, '/upload'), (upload, '/upload')],
                ) as connect:
                    with self.assertRaises(DELIVERY.FlightRequestAdmissionError):
                        DELIVERY.deliver_report(
                            'https://gallery.test/upload', 'key', self.pdf,
                            'session-01', 'en', 'report-01',
                            before_request=checkpoint,
                        )
                self.assertEqual(denied_attempt, attempts[0])
                self.assertEqual(denied_attempt - 1, connect.call_count)
                self.assertEqual(self.pdf_bytes, self.pdf.read_bytes())
                if denied_attempt == 3:
                    self.assertIn(self.pdf_bytes, bytes(upload.sent))
                    self.assertTrue(upload.closed)

    def test_host_checkpoint_permits_current_status_and_upload(self) -> None:
        """Allow a report when both independently checked attempts remain valid.

        Returns:
            None: The status and multipart requests each use the host callback.
        """
        status = FakeConnection(FakeResponse(200, self._status_response(False)))
        upload = FakeConnection(FakeResponse(200, {
            'ok': True, 'action': 'flight_report', 'found': True, 'conflict': False,
            'report': {
                'id': 'b' * 32, 'sha256': self.digest,
                'size': len(self.pdf_bytes), 'filename': 'flight-report-b-en.pdf',
            },
        }))
        checkpoint = mock.Mock()
        with mock.patch.object(
            DELIVERY, '_connection_target',
            side_effect=[(status, '/upload'), (upload, '/upload')],
        ):
            receipt = DELIVERY.deliver_report(
                'https://gallery.test/upload', 'key', self.pdf,
                'session-01', 'en', 'report-01', before_request=checkpoint,
            )
        self.assertEqual(2, checkpoint.call_count)
        self.assertEqual(self.digest, receipt['sha256'])
        self.assertIn(self.pdf_bytes, bytes(upload.sent))

    def test_invalid_response_names_action_http_type_and_redacts_credentials(self) -> None:
        """Retain actionable HTML/PHP response context without leaking the key.

        Returns:
            None: The error identifies inventory and HTTP failure while removing
                the configured credential from its bounded response preview.
        """
        response = FakeResponse(502, {})
        response.body = b"<html>Upstream failed; gallery-key confidential</html>"
        connection = FakeConnection(response)
        with mock.patch.object(DELIVERY, "_connection_target", return_value=(connection, "/api")):
            with self.assertRaises(DELIVERY.FlightDeliveryError) as caught:
                DELIVERY._perform_request("https://gallery.test/api", "gallery-key", {"action": "inventory"})
        message = str(caught.exception)
        self.assertIn("action=inventory", message)
        self.assertIn("HTTP 502", message)
        self.assertIn("Content-Type=application/json", message)
        self.assertIn("Upstream failed", message)
        self.assertNotIn("gallery-key", message)
        self.assertTrue(connection.closed)

    def test_saved_upload_requires_the_exact_confirmed_gallery(self) -> None:
        """Refuse a stale destination label even when the key itself is valid.

        Returns:
            None: Only an explicitly supported matching gallery permits upload.
        """
        response = {"ok": True, "gallery_folder": "Flights/A320", "capabilities": {"flight_reports_v1": True}}
        with mock.patch.object(DELIVERY, "_perform_request", return_value=response):
            DELIVERY.saved_report_destination("https://gallery.test/api", "new-key", "Flights/A320")
            with self.assertRaises(ValueError):
                DELIVERY.saved_report_destination("https://gallery.test/api", "new-key", "Other gallery")
            response["capabilities"]["flight_reports_v1"] = False
            with self.assertRaises(DELIVERY.FlightDeliveryError):
                DELIVERY.saved_report_destination("https://gallery.test/api", "new-key", "Flights/A320")

    def test_capability_probe_reads_inventory_flag(
        self: "FlightDeliveryTests",
    ) -> None:
        """Treat only an explicit inventory capability as supported.

        Returns:
            None: Verifies both advertised and older-gallery responses.
        """
        inventory_responses: List[FlightInventoryResponse] = [
            {"ok": True, "capabilities": {"flight_reports_v1": True}},
            {"ok": True, "capabilities": {}},
        ]
        with mock.patch.object(
            DELIVERY,
            "_perform_request",
            side_effect=inventory_responses,
        ):
            self.assertTrue(
                DELIVERY.check_report_capability("https://gallery.test/upload", "key")
            )
            self.assertFalse(
                DELIVERY.check_report_capability("https://gallery.test/upload", "key")
            )

    def test_existing_status_returns_receipt_without_upload(
        self: "FlightDeliveryTests",
    ) -> None:
        """Return the durable attachment id when a prior upload is found.

        Returns:
            None: Confirms the status check is the only network request.
        """
        with mock.patch.object(
            DELIVERY,
            "_perform_request",
            return_value=self._status_response(True),
        ) as request:
            receipt = DELIVERY.deliver_report(
                "https://gallery.test/upload",
                "key",
                self.pdf,
                "session-01",
                "en",
                "report-01",
            )
        self.assertEqual(receipt["id"], "a" * 32)
        self.assertEqual(receipt["sha256"], self.digest)
        self.assertTrue(receipt["already_present"])
        self.assertEqual(request.call_count, 1)

    def test_multipart_upload_contains_identity_and_progress(
        self: "FlightDeliveryTests",
    ) -> None:
        """Send the expected report fields and acknowledge exact PDF bytes.

        Returns:
            None: Verifies streamed form content, progress and durable receipt.
        """
        status_connection = FakeConnection(
            FakeResponse(200, self._status_response(False))
        )
        upload_connection = FakeConnection(
            FakeResponse(
                200,
                {
                    "ok": True,
                    "action": "flight_report",
                    "found": True,
                    "conflict": False,
                    "report": {
                        "id": "b" * 32,
                        "sha256": self.digest,
                        "size": len(self.pdf_bytes),
                        "filename": "flight-report-b-en.pdf",
                    },
                },
            )
        )
        progress = []
        with mock.patch.object(
            DELIVERY,
            "_connection_target",
            side_effect=[
                (status_connection, "/upload"),
                (upload_connection, "/upload"),
            ],
        ):
            receipt = DELIVERY.deliver_report(
                "https://gallery.test/upload",
                "key",
                self.pdf,
                "session-01",
                "EN",
                "report-01",
                lambda current, total: progress.append((current, total)),
            )

        body = bytes(upload_connection.sent)
        self.assertIn(b'name="report_id"\r\n\r\nreport-01', body)
        self.assertIn(b'name="session_id"\r\n\r\nsession-01', body)
        self.assertIn(b'name="language"\r\n\r\nen', body)
        self.assertIn(b'name="report"; filename="flight-report.pdf"', body)
        self.assertIn(self.pdf_bytes, body)
        self.assertEqual(progress[0], (0, len(self.pdf_bytes)))
        self.assertEqual(progress[-1], (len(self.pdf_bytes), len(self.pdf_bytes)))
        self.assertEqual(receipt["id"], "b" * 32)
        self.assertEqual(receipt["sha256"], self.digest)
        self.assertFalse(receipt["already_present"])
        self.assertTrue(status_connection.closed)
        self.assertTrue(upload_connection.closed)

    def test_ambiguous_upload_is_reconciled_before_retry(
        self: "FlightDeliveryTests",
    ) -> None:
        """Resolve a socket reset by checking status before another upload.

        Returns:
            None: Confirms the committed report is returned without duplication.
        """
        responses = [
            self._status_response(False),
            ConnectionResetError("connection closed after server write"),
            self._status_response(True),
        ]

        typed_responses: List[
            Union[FlightReportStatusResponse, BaseException]
        ] = responses

        def request(
            url: str,
            api_key: str,
            payload: Optional[FlightReportStatusRequest] = None,
            pdf_path: Optional[Path] = None,
            fields: Optional[FlightReportUploadFields] = None,
            emit: Optional[Callable[[int, int], None]] = None,
            file_size: int = 0,
        ) -> Union[FlightReportStatusResponse, FlightReportUploadResponse]:
            """Return typed status or receipt responses in request order.

            Args:
                url (str): Upload endpoint used by the delivery client.
                api_key (str): Gallery-scoped key supplied to the request.
                payload (Optional[FlightReportStatusRequest]): Status identity,
                    language and SHA-256, or None for multipart transfer.
                pdf_path (Optional[Path]): PDF source for a multipart request.
                fields (Optional[FlightReportUploadFields]): Report identity,
                    session, language and checksum form fields.
                emit (Optional[Callable[[int, int], None]]): Optional byte
                    progress callback supplied to the transport.
                file_size (int): PDF byte count used for multipart framing.

            Returns:
                Union[FlightReportStatusResponse, FlightReportUploadResponse]:
                    Status fields or a report receipt with document id,
                    checksum, size and filename.

            Raises:
                ConnectionResetError: When the simulated upload response is
                    lost after the server write.
            """
            del url, api_key, payload, pdf_path, fields, emit, file_size
            result = typed_responses.pop(0)
            if isinstance(result, BaseException):
                raise result
            return result

        with mock.patch.object(DELIVERY, "_perform_request", side_effect=request) as call:
            receipt = DELIVERY.deliver_report(
                "https://gallery.test/upload",
                "key",
                self.pdf,
                "session-01",
                "cs",
                "report-02",
            )
        self.assertEqual(call.call_count, 3)
        self.assertTrue(receipt["already_present"])
        self.assertEqual(receipt["sha256"], self.digest)

    def test_checksum_conflict_stops_before_upload(
        self: "FlightDeliveryTests",
    ) -> None:
        """Refuse to replace a report identity with different bytes.

        Returns:
            None: Confirms an identity conflict is terminal for this request.
        """
        conflict = {
            "ok": False,
            "action": "flight_report_status",
            "conflict": True,
            "error": "different checksum",
        }
        with mock.patch.object(
            DELIVERY,
            "_perform_request",
            return_value=conflict,
        ) as request:
            with self.assertRaises(DELIVERY.FlightDeliveryError):
                DELIVERY.deliver_report(
                    "https://gallery.test/upload",
                    "key",
                    self.pdf,
                    "session-01",
                    "de",
                    "report-03",
                )
        self.assertEqual(request.call_count, 1)

    def test_incomplete_status_cannot_start_a_report_upload(self) -> None:
        """Refuse ambiguous absence without issuing a multipart request.

        Returns:
            None: Each malformed status leaves the local PDF untouched.
        """
        responses = (
            {"ok": True},
            {"ok": True, "action": "inventory", "found": False,
             "conflict": False, "report": None},
            {"ok": True, "action": "flight_report_status", "found": "false",
             "conflict": False, "report": None},
            {"ok": True, "action": "flight_report_status", "found": False,
             "report": None},
            {"ok": True, "action": "flight_report_status", "found": False,
             "conflict": False, "report": {"id": "a" * 32}},
        )
        for response in responses:
            with self.subTest(response=response), mock.patch.object(
                DELIVERY, "_perform_request", return_value=response,
            ) as request:
                with self.assertRaises(DELIVERY.FlightDeliveryError):
                    DELIVERY.deliver_report(
                        "https://gallery.test/upload", "key", self.pdf,
                        "session-01", "en", "report-01",
                    )
                self.assertEqual(request.call_count, 1)
                self.assertEqual(self.pdf_bytes, self.pdf.read_bytes())

    def test_duplicate_status_keys_are_rejected_before_upload(self) -> None:
        """Do not interpret an ambiguous JSON object as confirmed absence.

        Returns:
            None: The client closes the connection and sends no PDF.
        """
        response = FakeResponse(200, {})
        response.body = (
            b'{"ok":true,"action":"flight_report_status","found":true,'
            b'"found":false,"conflict":false,"report":null}'
        )
        connection = FakeConnection(response)
        with mock.patch.object(
            DELIVERY, "_connection_target", return_value=(connection, "/upload"),
        ) as request:
            with self.assertRaises(DELIVERY.FlightDeliveryError):
                DELIVERY.deliver_report(
                    "https://gallery.test/upload", "key", self.pdf,
                    "session-01", "en", "report-01",
                )
        self.assertEqual(request.call_count, 1)
        self.assertEqual(b"", bytes(connection.sent))
        self.assertTrue(connection.closed)
        self.assertEqual(self.pdf_bytes, self.pdf.read_bytes())

    def test_found_receipt_requires_the_exact_pdf_size(self) -> None:
        """Do not confirm a same-digest receipt with contradictory length.

        Returns:
            None: A bad receipt stops before upload and leaves the PDF intact.
        """
        response = self._status_response(True)
        assert response["report"] is not None
        response["report"]["size"] = len(self.pdf_bytes) + 1
        with mock.patch.object(DELIVERY, "_perform_request", return_value=response) as request:
            with self.assertRaises(DELIVERY.FlightDeliveryError):
                DELIVERY.deliver_report(
                    "https://gallery.test/upload", "key", self.pdf,
                    "session-01", "en", "report-01",
                )
        self.assertEqual(request.call_count, 1)
        self.assertEqual(self.pdf_bytes, self.pdf.read_bytes())

    def test_invalid_upload_receipt_never_retries_multipart(self) -> None:
        """A malformed response after upload cannot count as success or replay.

        Returns:
            None: One status, one upload and one status leave unknown outcome.
        """
        malformed_receipt = {
            "ok": True, "action": "flight_report", "found": True,
            "conflict": False,
            "report": {"id": "a" * 32, "sha256": self.digest,
                       "size": len(self.pdf_bytes) + 1, "filename": "test.pdf"},
        }
        responses = [self._status_response(False), malformed_receipt,
                     self._status_response(False)]
        with mock.patch.object(DELIVERY, "_perform_request", side_effect=responses) as request:
            with self.assertRaisesRegex(DELIVERY.FlightDeliveryError, "outcome is unknown"):
                DELIVERY.deliver_report(
                    "https://gallery.test/upload", "key", self.pdf,
                    "session-01", "en", "report-01",
                )
        self.assertEqual(request.call_count, 3)
        self.assertIsNotNone(request.call_args_list[1].kwargs.get("pdf_path"))
        self.assertIsNone(request.call_args_list[2].kwargs.get("pdf_path"))
        self.assertEqual(self.pdf_bytes, self.pdf.read_bytes())

    def test_upload_422_can_follow_server_commit(self) -> None:
        """Reconcile a rejected upload response without resending PDF bytes.

        Returns:
            None: A matching passive status produces the authoritative receipt.
        """
        responses = [self._status_response(False),
                     DELIVERY.FlightDeliveryError("post-commit failure", 422),
                     self._status_response(True)]
        with mock.patch.object(DELIVERY, "_perform_request", side_effect=responses) as request:
            receipt = DELIVERY.deliver_report(
                "https://gallery.test/upload", "key", self.pdf,
                "session-01", "en", "report-01",
            )
        self.assertTrue(receipt["already_present"])
        self.assertEqual(request.call_count, 3)
        self.assertIsNotNone(request.call_args_list[1].kwargs.get("pdf_path"))
        self.assertIsNone(request.call_args_list[2].kwargs.get("pdf_path"))
        self.assertEqual(self.pdf_bytes, self.pdf.read_bytes())

    def test_expected_digest_must_match_before_any_request(self) -> None:
        """Refuse a report that changed after the caller captured its hash.

        Returns:
            None: No status or upload is sent and the local PDF remains.
        """
        with mock.patch.object(DELIVERY, "_perform_request") as request:
            with self.assertRaises(DELIVERY.FlightDeliveryError):
                DELIVERY.deliver_report(
                    "https://gallery.test/upload", "key", self.pdf,
                    "session-01", "en", "report-01", expected_sha256="f" * 64,
                )
        request.assert_not_called()
        self.assertEqual(self.pdf_bytes, self.pdf.read_bytes())

    def test_digest_rejects_a_file_growing_past_the_read_limit(self) -> None:
        """Stop a growing stream even when its initial file stat was small.

        Returns:
            None: The bounded digest refuses bytes beyond the test limit.
        """
        limit = len(self.pdf_bytes) + 8
        growing = io.BytesIO(self.pdf_bytes + b"x" * 9)
        with mock.patch.object(DELIVERY, "FLIGHT_DELIVERY_MAX_PDF_BYTES", limit), \
                mock.patch.object(Path, "open", return_value=growing):
            with self.assertRaises(ValueError):
                DELIVERY.flight_report_digest(self.pdf)

    def test_report_changed_during_status_is_not_acknowledged(self) -> None:
        """Refuse to mark the changed source as confirmed by an old receipt.

        Returns:
            None: The changed PDF is retained and no upload is attempted.
        """
        response = self._status_response(True)
        changed = b"%PDF-1.4\nchanged report bytes\n%%EOF\n"

        def status(
            url: str, api_key: str,
            payload: Optional[FlightReportStatusRequest] = None,
            pdf_path: Optional[Path] = None,
            fields: Optional[FlightReportUploadFields] = None,
            emit: Optional[Callable[[int, int], None]] = None,
            file_size: int = 0,
        ) -> FlightReportStatusResponse:
            """Change only the isolated fixture before returning prior status.

            Args:
                url (str): Unused fixture endpoint.
                api_key (str): Unused fixture key.
                payload (Optional[FlightReportStatusRequest]): Status request.
                pdf_path (Optional[Path]): Unused multipart source.
                fields (Optional[FlightReportUploadFields]): Unused form fields.
                emit (Optional[Callable[[int, int], None]]): Unused progress.
                file_size (int): Unused multipart length.

            Returns:
                FlightReportStatusResponse: Receipt for the earlier PDF bytes.
            """
            self.pdf.write_bytes(changed)
            return response

        with mock.patch.object(DELIVERY, "_perform_request", side_effect=status) as request:
            with self.assertRaises(DELIVERY.FlightDeliveryError):
                DELIVERY.deliver_report(
                    "https://gallery.test/upload", "key", self.pdf,
                    "session-01", "en", "report-01", expected_sha256=self.digest,
                )
        self.assertEqual(request.call_count, 1)
        self.assertEqual(changed, self.pdf.read_bytes())


if __name__ == "__main__":
    unittest.main()
