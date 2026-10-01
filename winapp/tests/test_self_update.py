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
import sys
import tempfile
import threading
import unittest
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from uploader.self_update import (API_URL, ReleaseAsset, UpdateCancelled, UpdateError,
                                 download_update, find_update, verify_installer)

DATA = b"deterministic installer content"
SHA = hashlib.sha256(DATA).hexdigest()


class Response(io.BytesIO):
    """Response deterministic update test fixture."""
    def __init__(self, body: bytes, url: str) -> None:
        """Check init."""
        super().__init__(body)
        self.url = url

    def geturl(self) -> str:
        """Check geturl."""
        return self.url


class Opener:
    """Opener deterministic update test fixture."""
    def __init__(self, pages: list[list[dict[str, Any]]] | None = None, body: bytes = DATA) -> None:
        """Check init."""
        self.pages = pages or []
        self.body = body
        self.requests = []

    def open(self, request: Any, timeout: float) -> Response:
        """Check open."""
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
