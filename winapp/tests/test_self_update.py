# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Deterministic installer update trust and download contracts.
# Responsibilities:
#   - Exercise public metadata, integrity and cancellation with fake network IO.
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_self_update.py
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Public update discovery and download tests without live network traffic."""

import hashlib
import io
import json
from pathlib import Path
import socket
import ssl
import sys
import tempfile
import threading
import unittest
import urllib.request
from typing import Any, cast
import urllib.error
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from uploader.self_update import (API_URL, ReleaseAsset, UpdateCancelled, UpdateError,
                                 UpdateDiagnostic, capture_update_diagnostics,
                                 download_update, find_update,
                                 format_update_diagnostic, verify_installer,
                                 _safe_exception_facts)

DATA = b"deterministic installer content"
SHA = hashlib.sha256(DATA).hexdigest()
# Purpose: Distinguish SSL verify-code precedence from the exception errno.
# Type: int. Units: synthetic TLS verify-code value. Scope: one diagnostic test.
# Consumers: SelfUpdateTests.test_wrapped_dns_and_tls_causes_are_classified_without_text.
# Rationale: This sentinel differs from the SSL exception's errno of 1.
TEST_TLS_VERIFY_CODE = 817


class Response(io.BytesIO):
    """Response deterministic update test fixture."""
    def __init__(self, body: bytes, url: str) -> None:
        """Check init."""
        super().__init__(body)
        self.url = url

    def geturl(self) -> str:
        """Check geturl."""
        return self.url

    def getcode(self) -> int:
        """Return the success status used by the synthetic response.

        Returns:
            int: Structural HTTP 200 status for successful fixture responses.
        """
        return 200


class Opener:
    """Opener deterministic update test fixture."""
    def __init__(self, pages: list[list[dict[str, Any]]] | None = None, body: bytes = DATA) -> None:
        """Check init."""
        self.pages = pages or []
        self.body = body
        self.requests = []

    def open(
        self, request: urllib.request.Request, timeout: float,
    ) -> Response:
        """Return one deterministic metadata or installer response.

        Args:
            request (urllib.request.Request): Trusted update request under test.
            timeout (float): Existing bounded request timeout.

        Returns:
            Response: Context-managed byte stream matching the request route.
        """
        self.requests.append(request)
        self.assert_no_credentials(request)
        url = request.full_url
        if url.startswith(API_URL):
            page = int(url.rsplit("=", 1)[1])
            body = json.dumps(self.pages[page - 1] if page <= len(self.pages) else []).encode()
        else:
            body = self.body
        return Response(body, url)

    @staticmethod
    def assert_no_credentials(request: Any) -> None:
        """Check assert no credentials."""
        assert not request.has_header("Authorization")


def release(version: str | None = None, identifier: int = 1, **changes: Any) -> dict[str, Any]:
    """Check release."""
    assets = []
    if version:
        name = f"PHPGalleryUploader-{version}-Setup.exe"
        assets = [{"id": identifier, "name": name, "size": len(DATA),
                   "digest": "sha256:" + SHA,
                   "browser_download_url": "https://github.com/klusik/PHP_gallery/releases/download/CMS-9.9/" + name}]
    record = {"id": identifier, "draft": False, "prerelease": False, "assets": assets,
              "tag_name": "v999.0.0"}
    record.update(changes)
    return record


def asset() -> ReleaseAsset:
    """Check asset."""
    item = release("0.3.0")["assets"][0]
    return ReleaseAsset("0.3.0", item["name"], item["browser_download_url"], len(DATA), SHA, 1, 1)


class SelfUpdateTests(unittest.TestCase):
    """SelfUpdateTests deterministic update test fixture."""
    def test_pagination_numeric_versions_and_cms_tags(self) -> None:
        """Check pagination numeric versions and cms tags."""
        first = [release(identifier=index + 10) for index in range(99)] + [release("0.9.0")]
        second = [release("0.10.0", 2), release("88.0.0", 3, prerelease=True),
                  release("99.0.0", 4, draft=True)]
        opener = Opener([first, second])
        found = find_update("0.2.0", opener=opener)
        self.assertEqual(found.version, "0.10.0")
        self.assertEqual(len(opener.requests), 2)

    def test_no_installer_is_not_cms_update(self) -> None:
        """Check no installer is not cms update."""
        self.assertIsNone(find_update("0.2.0", opener=Opener([[release(), release("0.2.0")]])))

    def test_duplicate_versions_refused(self) -> None:
        """Check duplicate versions refused."""
        conflicting = release("0.3.0", 2)
        conflicting["assets"][0]["digest"] = "sha256:" + "1" * 64
        with self.assertRaises(UpdateError):
            find_update("0.2.0", opener=Opener([[release("0.3.0"), conflicting]]))

    def test_identical_installer_reattached_to_cms_release(self) -> None:
        """Check identical installer reattached to cms release."""
        found = find_update("0.2.0", opener=Opener([[release("0.3.0"), release("0.3.0", 2)]]))
        self.assertEqual(found.asset_id, 2)

    def test_missing_highest_digest_does_not_fall_back(self) -> None:
        """Check missing highest digest does not fall back."""
        newest = release("0.4.0", 2)
        newest["assets"][0]["digest"] = None
        with self.assertRaisesRegex(UpdateError, "SHA256"):
            find_update("0.2.0", opener=Opener([[release("0.3.0"), newest]]))

    def test_invalid_asset_schema_and_urls(self) -> None:
        """Check invalid asset schema and urls."""
        for field, value in [("size", True), ("id", -1), ("digest", "sha256:no"),
                             ("browser_download_url", "http://github.com/klusik/PHP_gallery/releases/download/v/a.exe"),
                             ("browser_download_url", "https://github.com/other/repo/releases/download/v/a.exe")]:
            with self.subTest(field=field, value=value):
                record = release("0.3.0")
                record["assets"][0][field] = value
                with self.assertRaises(UpdateError):
                    find_update("0.2.0", opener=Opener([[record]]))

    def test_same_release_manifest(self) -> None:
        """Check same release manifest."""
        record = release("0.3.0")
        record["assets"][0]["digest"] = None
        record["assets"].append({"id": 2, "name": "winapp-update.json", "size": 200,
            "browser_download_url": "https://github.com/klusik/PHP_gallery/releases/download/CMS-9.9/winapp-update.json"})
        manifest = {"assets": [{"name": asset().name, "version": "0.3.0", "size": len(DATA), "sha256": SHA}]}
        found = find_update("0.2.0", opener=Opener([[record]], json.dumps(manifest).encode()))
        self.assertEqual(found.sha256, SHA)
        manifest["assets"][0]["size"] += 1
        with self.assertRaises(UpdateError):
            find_update("0.2.0", opener=Opener([[record]], json.dumps(manifest).encode()))

    def test_download_and_prelaunch_verification(self) -> None:
        """Check download and prelaunch verification."""
        with tempfile.TemporaryDirectory() as folder:
            progress = []
            path = download_update(asset(), Path(folder), lambda done, total: progress.append((done, total)),
                                   threading.Event(), opener=Opener())
            self.assertEqual(path.suffix, ".exe")
            self.assertEqual(path.read_bytes(), DATA)
            self.assertEqual(progress[-1], (len(DATA), len(DATA)))
            verify_installer(path, asset())
            path.write_bytes(b"x" * len(DATA))
            with self.assertRaises(UpdateError):
                verify_installer(path, asset())

    def test_http_failure_diagnostics_keep_status_and_hide_response_secrets(self) -> None:
        """Retain safe HTTP facts when discovery wraps a provider exception.

        Returns:
            None: The API route and 504 survive while URL and response text do
                not appear in any rendered diagnostic.
        """
        secret = 'secret-query-and-response-body'
        url = API_URL + '?token=' + secret

        class FailingOpener:
            """Return a synthetic HTTP failure without network access."""

            def open(
                self, request: urllib.request.Request, timeout: float,
            ) -> Response:
                """Raise an HTTP error containing data diagnostics must omit.

                Args:
                    request (urllib.request.Request): Native request under test.
                    timeout (float): Existing bounded updater request timeout.

                Returns:
                    Response: Never returned because the fixture always fails.
                """
                raise urllib.error.HTTPError(
                    url, 504, secret, {}, io.BytesIO(secret.encode()),
                )

        events: list[UpdateDiagnostic] = []
        with capture_update_diagnostics(events.append):
            with self.assertRaises(UpdateError):
                find_update('0.2.0', opener=FailingOpener())
        rendered = '\n'.join(format_update_diagnostic(event) for event in events)
        self.assertIn('stage=http_open', rendered)
        self.assertIn('endpoint=release_api', rendered)
        self.assertIn('http_status=504', rendered)
        self.assertIn('category=http', rendered)
        self.assertNotIn(secret, rendered)
        self.assertNotIn('token=', rendered)

    def test_wrapped_dns_and_tls_causes_are_classified_without_text(self) -> None:
        """Inspect typed transport causes without formatting their messages.

        Returns:
            None: DNS and TLS categories survive urllib wrapping, and cyclic
                causes stay bounded with no exception text retained.
        """
        class KnownCertificateError(ssl.SSLCertVerificationError):
            """Expose a deterministic verify code distinct from errno."""

            @property
            def verify_code(self) -> int:
                """Return the synthetic verification code used by this test.

                Returns:
                    int: Fixed code that must survive later errno inspection.
                """
                return TEST_TLS_VERIFY_CODE

        dns_error = urllib.error.URLError(
            socket.gaierror(11001, 'private dns message'),
        )
        timeout_error = urllib.error.URLError(TimeoutError('private timeout'))
        connection_error = urllib.error.URLError(
            ConnectionError('private connection message'),
        )
        tls_error = urllib.error.URLError(
            KnownCertificateError(1, 'private certificate message'),
        )
        tls_reason = tls_error.reason
        secret_cycle = RuntimeError('private cyclic message')
        secret_cycle.__cause__ = secret_cycle

        self.assertEqual(_safe_exception_facts(dns_error)[2], 'dns')
        self.assertEqual(_safe_exception_facts(timeout_error)[2], 'timeout')
        self.assertEqual(_safe_exception_facts(connection_error)[2], 'connection')
        tls_facts = _safe_exception_facts(tls_error)
        self.assertEqual(tls_facts[2], 'tls')
        self.assertNotEqual(getattr(tls_reason, 'errno', None), TEST_TLS_VERIFY_CODE)
        self.assertEqual(tls_facts[4], TEST_TLS_VERIFY_CODE)
        cycle = _safe_exception_facts(secret_cycle)
        self.assertEqual(cycle[0], 'RuntimeError')
        self.assertEqual(cycle[1], ())
        self.assertNotIn('private', repr((cycle,)))

    def test_diagnostic_formatter_has_a_fixed_malformed_event_fallback(self) -> None:
        """Keep malformed diagnostic fields from breaking the UI poller.

        Returns:
            None: Formatter failure is converted to a fixed safe summary.
        """
        class BrokenDiagnostic:
            """Malformed event fixture that refuses field access."""

            @property
            def phase(self) -> str:
                """Raise without exposing exception text to the formatter.

                Returns:
                    str: Never returned because this malformed property fails.
                """
                raise RuntimeError('private message')

        summary = format_update_diagnostic(cast(UpdateDiagnostic, BrokenDiagnostic()))
        self.assertEqual(summary, '[updater] diagnostic=unavailable')
        self.assertNotIn('private', summary)

    def test_manifest_http_failure_is_attributed_to_manifest_route(self) -> None:
        """Distinguish the optional same-release manifest from discovery.

        Returns:
            None: The failed manifest endpoint remains identifiable without
                retaining its URL or body.
        """
        record = release('0.3.0')
        record['assets'][0]['digest'] = None
        record['assets'].append({
            'id': 2,
            'name': 'winapp-update.json',
            'size': 200,
            'browser_download_url': (
                'https://github.com/klusik/PHP_gallery/releases/download/'
                'CMS-9.9/winapp-update.json'
            ),
        })

        class ManifestOpener(Opener):
            """Serve valid release discovery and refuse the manifest request."""

            def open(
                self, request: urllib.request.Request, timeout: float,
            ) -> Response:
                """Serve release JSON or raise a secret-bearing manifest error.

                Args:
                    request (urllib.request.Request): Native request under test.
                    timeout (float): Existing bounded updater request timeout.

                Returns:
                    Response: Discovery page; manifest requests raise instead.
                """
                if request.full_url.startswith(API_URL):
                    body = json.dumps([record]).encode()
                    return Response(body, request.full_url)
                raise urllib.error.HTTPError(
                    'https://release-assets.githubusercontent.com/'
                    'redirected-manifest?signature=private-query',
                    504, 'private response', {}, None,
                )

        events: list[UpdateDiagnostic] = []
        with capture_update_diagnostics(events.append):
            with self.assertRaises(UpdateError):
                find_update('0.2.0', opener=ManifestOpener())
        rendered = '\n'.join(format_update_diagnostic(event) for event in events)
        self.assertIn('endpoint=release_manifest', rendered)
        self.assertIn('host=release-assets.githubusercontent.com', rendered)
        self.assertIn('http_status=504', rendered)
        self.assertNotIn('private response', rendered)
        self.assertNotIn('signature=', rendered)
        self.assertNotIn('redirected-manifest', rendered)
        self.assertNotIn('winapp-update.json', rendered)

    def test_installer_open_http_failure_has_asset_endpoint_and_status(self) -> None:
        """Retain safe HTTP status when the installer request itself fails.

        Returns:
            None: The failed installer endpoint is recorded without retaining
                a signed URL or the provider response body.
        """
        secret = 'signed-query-and-private-body'

        class InstallerOpener(Opener):
            """Raise a synthetic HTTP response during installer open."""

            def open(
                self, request: urllib.request.Request, timeout: float,
            ) -> Response:
                """Raise a status-bearing error containing private strings.

                Args:
                    request (urllib.request.Request): Installer request.
                    timeout (float): Existing bounded updater request timeout.

                Returns:
                    Response: Never returned because the fixture always fails.
                """
                raise urllib.error.HTTPError(
                    request.full_url + '?signature=' + secret,
                    504, secret, {}, None,
                )

        events: list[UpdateDiagnostic] = []
        with tempfile.TemporaryDirectory() as folder:
            with capture_update_diagnostics(events.append):
                with self.assertRaises(UpdateError):
                    download_update(
                        asset(), Path(folder), lambda *_: None,
                        threading.Event(), opener=InstallerOpener(),
                    )
        rendered = '\n'.join(format_update_diagnostic(event) for event in events)
        self.assertIn('stage=http_open', rendered)
        self.assertIn('endpoint=installer_asset', rendered)
        self.assertIn('http_status=504', rendered)
        self.assertNotIn(secret, rendered)

    def test_download_read_failure_and_cleanup_preserve_primary_http_cause(self) -> None:
        """Keep a failed read as the primary error if partial cleanup also fails.

        Returns:
            None: Diagnostics retain safe HTTP and cleanup facts without raw
                request, response, or exception text.
        """
        secret = 'signed-url-secret-body'

        class BrokenResponse(Response):
            """Readable response fixture that fails after the request opens."""

            def read(self, size: int = -1) -> bytes:
                """Raise a synthetic HTTP error during streaming.

                Args:
                    size (int): Requested bounded stream chunk size.

                Returns:
                    bytes: Never returned because the fixture always fails.
                """
                raise urllib.error.HTTPError(
                    self.url + '?signature=' + secret, 504,
                    secret, {}, None,
                )

        class ReadFailureOpener(Opener):
            """Open a response whose body fails without real network traffic."""

            def open(
                self, request: urllib.request.Request, timeout: float,
            ) -> BrokenResponse:
                """Return the synthetic stream for a trusted installer URL.

                Args:
                    request (urllib.request.Request): Installer request under test.
                    timeout (float): Existing bounded updater request timeout.

                Returns:
                    BrokenResponse: Stream that raises on its first read.
                """
                return BrokenResponse(b'', request.full_url)

        original_unlink = Path.unlink

        def fail_partial_unlink(path: Path, missing_ok: bool = False) -> None:
            """Refuse only this test's partial-file cleanup operation.

            Args:
                path (Path): Candidate owned path to unlink.
                missing_ok (bool): Whether a missing path may be ignored.

            Returns:
                None: Raises for the generated partial and delegates otherwise.
            """
            if path.suffix == '.part':
                raise OSError(secret)
            original_unlink(path, missing_ok=missing_ok)

        events: list[UpdateDiagnostic] = []
        with tempfile.TemporaryDirectory() as folder:
            with patch('pathlib.Path.unlink', new=fail_partial_unlink):
                with capture_update_diagnostics(events.append):
                    with self.assertRaises(UpdateError) as caught:
                        download_update(
                            asset(), Path(folder), lambda *_: None,
                            threading.Event(), opener=ReadFailureOpener(),
                        )
        self.assertEqual(str(caught.exception),
                         'Could not download the uploader installer.')
        rendered = '\n'.join(format_update_diagnostic(event) for event in events)
        self.assertIn('http_status=504', rendered)
        self.assertIn('category=http', rendered)
        cleanup_events = [
            event for event in events
            if event.phase == 'cleanup' and event.stage == 'partial_remove'
        ]
        self.assertEqual(len(cleanup_events), 1)
        cleanup = cleanup_events[0]
        self.assertEqual('local_installer', cleanup.endpoint)
        self.assertEqual('local', cleanup.host)
        self.assertIsNone(cleanup.http_status)
        self.assertEqual('cleanup_refused', cleanup.category)
        self.assertEqual('OSError', cleanup.error_type)
        self.assertIn('category=cleanup_refused', rendered)
        self.assertNotIn(secret, rendered)
        self.assertNotIn('signature=', rendered)

    def test_verification_and_cancellation_emit_distinct_safe_outcomes(self) -> None:
        """Record integrity refusal separately from user cancellation.

        Returns:
            None: Both terminal states are retained without file paths or
                exception messages.
        """
        events: list[UpdateDiagnostic] = []
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'private-local-path.exe'
            path.write_bytes(b'x' * len(DATA))
            with capture_update_diagnostics(events.append):
                with self.assertRaises(UpdateError):
                    verify_installer(path, asset())
                cancel = threading.Event()
                cancel.set()
                with self.assertRaises(UpdateCancelled):
                    download_update(
                        asset(), Path(folder), lambda *_: None, cancel,
                        opener=Opener(),
                    )
        rendered = '\n'.join(format_update_diagnostic(event) for event in events)
        self.assertIn('stage=installer_verify', rendered)
        self.assertIn('category=integrity_refusal', rendered)
        self.assertIn('outcome=cancelled', rendered)
        self.assertNotIn('private-local-path.exe', rendered)

    def test_failure_and_cancel_preserve_unrelated_partial(self) -> None:
        """Check failure and cancel preserve unrelated partial."""
        for body in [b"short", b"x" * len(DATA), DATA + b"oversize"]:
            with tempfile.TemporaryDirectory() as folder:
                directory = Path(folder)
                other = directory / "unrelated.part"
                other.write_bytes(b"keep")
                with self.assertRaises(UpdateError):
                    download_update(asset(), directory, lambda *_: None, threading.Event(), opener=Opener(body=body))
                self.assertEqual(list(directory.iterdir()), [other])
                self.assertEqual(other.read_bytes(), b"keep")
        with tempfile.TemporaryDirectory() as folder:
            cancel = threading.Event()
            def progress(done: int, total: int) -> None:
                """Check progress."""
                cancel.set()
            with self.assertRaises(UpdateCancelled):
                download_update(asset(), Path(folder), progress, cancel, opener=Opener())
            self.assertEqual(list(Path(folder).iterdir()), [])

    def test_untrusted_redirect_response(self) -> None:
        """Check untrusted redirect response."""
        class RedirectOpener(Opener):
            """RedirectOpener deterministic update test fixture."""
            def open(self, request: Any, timeout: float) -> Response:
                """Check open."""
                return Response(DATA, "https://evil.example/installer.exe")
        with tempfile.TemporaryDirectory() as folder:
            with self.assertRaises(UpdateError):
                download_update(asset(), Path(folder), lambda *_: None, threading.Event(), opener=RedirectOpener())
            self.assertEqual(list(Path(folder).iterdir()), [])


if __name__ == "__main__":
    unittest.main()
