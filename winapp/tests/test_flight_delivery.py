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


if __name__ == "__main__":
    unittest.main()
