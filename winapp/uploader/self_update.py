# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Discover and verify public Windows installer updates.
# Responsibilities:
#   - Discover independent uploader versions and enforce trusted metadata.
#   - Download bounded installers and verify integrity before execution.
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/self_update.py
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Bounded, credential-free GitHub update discovery and verified downloads.

Only the uploader installer filename determines its version. Repository release
tags describe the CMS and are deliberately ignored. A missing SHA256 for the
highest newer installer is an error, never a reason to offer an older update.
"""

from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Optional, Any
import hashlib
import uuid
import math
import json
import os
import re
import tempfile
import threading
import time
import urllib.parse
import urllib.request

REPOSITORY = "klusik/PHP_gallery"
API_URL = "https://api.github.com/repos/" + REPOSITORY + "/releases"
MAX_INSTALLER_SIZE = 1024 * 1024 * 1024
MAX_JSON_SIZE = 4 * 1024 * 1024
MAX_PAGES = 20
DISCOVERY_BUDGET_SECONDS = 120
TIMEOUT = 30
_NAME = re.compile(r"PHPGalleryUploader-(\d+\.\d+\.\d+)-Setup\.exe\Z")
_VERSION = re.compile(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\Z")
_HASH = re.compile(r"[a-fA-F0-9]{64}\Z")


class UpdateError(RuntimeError):
    """An update cannot safely be discovered, downloaded, or verified."""


class UpdateCancelled(UpdateError):
    """The caller cancelled a download before its verified completion."""


@dataclass(frozen=True)
class ReleaseAsset:
    """Immutable validated installer metadata with an expected SHA256 digest."""

    version: str
    name: str
    download_url: str
    size: int
    sha256: str
    asset_id: int
    release_id: int


def _version(value: str) -> tuple[int, int, int]:
    """Parse a canonical numeric uploader version without lexical ordering."""
    if not isinstance(value, str) or len(value) > 32 or not _VERSION.fullmatch(value):
        raise UpdateError("Invalid uploader version; expected major.minor.patch.")
    major, minor, patch = value.split(".")
    return int(major), int(minor), int(patch)


def _positive(value: Any, maximum: int) -> int:
    """Validate bounded JSON integers while excluding booleans."""
    if type(value) is not int or not 0 < value <= maximum:
        raise UpdateError("GitHub returned invalid update metadata.")
    return value


def _asset_url(url: Any, name: str) -> str:
    """Require the exact repository and filename before any download request."""
    if not isinstance(url, str) or len(url) > 4096:
        raise UpdateError("Invalid installer download URL.")
    try:
        parsed = urllib.parse.urlsplit(url)
    except ValueError as exc:
        raise UpdateError("Invalid installer download URL.") from exc
    prefix = "/" + REPOSITORY + "/releases/download/"
    path = parsed.path
    tail = path[len(prefix):] if path.startswith(prefix) else ""
    parts = tail.split("/")
    if (parsed.scheme != "https" or parsed.netloc != "github.com"
            or parsed.query or parsed.fragment or len(parts) != 2
            or not parts[0] or urllib.parse.unquote(parts[1]) != name
            or "/" in urllib.parse.unquote(parts[0])):
        raise UpdateError("Installer URL does not belong to the expected GitHub release.")
    return url


def _trusted_url(url: str) -> bool:
    """Allow GitHub's exact public API/download hosts, never wildcard domains."""
    try:
        parsed = urllib.parse.urlsplit(url)
    except ValueError:
        return False
    return (parsed.scheme == "https" and parsed.netloc in {
        "api.github.com", "github.com", "release-assets.githubusercontent.com",
        "objects.githubusercontent.com", "github-releases.githubusercontent.com",
    } and not parsed.fragment)


class _Redirects(urllib.request.HTTPRedirectHandler):
    """Reject unsupported redirect hosts before urllib sends a request."""
    def redirect_request(self, req: Any, fp: Any, code: int, msg: str,
                         headers: Any, newurl: str) -> Any:
        """Validate the destination before following an HTTP redirect."""
        if not _trusted_url(newurl):
            raise UpdateError("GitHub redirected the update to an untrusted address.")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def _open(url: str, opener: Any) -> Any:
    """Open a bounded-time public request without credentials."""
    if not _trusted_url(url):
        raise UpdateError("Untrusted update address.")
    request = urllib.request.Request(url, headers={
        "Accept": "application/vnd.github+json" if url.startswith(API_URL) else "application/octet-stream",
        "User-Agent": "PHPGalleryUploader-self-update",
    })
    try:
        response = (opener or urllib.request.build_opener(_Redirects())).open(request, timeout=TIMEOUT)
        if not _trusted_url(response.geturl()):
            response.close()
            raise UpdateError("Untrusted final update address.")
        return response
    except UpdateError:
        raise
    except Exception as exc:
        raise UpdateError("Could not contact GitHub for the uploader update.") from exc


def _json(url: str, opener: Any, limit: int = MAX_JSON_SIZE) -> Any:
    """Decode only bounded JSON responses and normalize transport failures."""
    try:
        with _open(url, opener) as response:
            data = response.read(limit + 1)
        if len(data) > limit:
            raise UpdateError("GitHub update metadata exceeds the size limit.")
        return json.loads(data)
    except UpdateError:
        raise
    except Exception as exc:
        raise UpdateError("GitHub returned unreadable update metadata.") from exc


def _digest(asset: dict[str, Any], release: dict[str, Any], version: str,
            opener: Any) -> str:
    """Resolve the GitHub digest or a strictly matching same-release manifest."""
    digest = asset.get("digest")
    if digest is not None:
        if not isinstance(digest, str) or not digest.startswith("sha256:") or not _HASH.fullmatch(digest[7:]):
            raise UpdateError("The newer installer has an invalid SHA256 digest.")
        return digest[7:].lower()
    manifests = [item for item in release["assets"]
                 if isinstance(item, dict) and item.get("name") == "winapp-update.json"]
    if len(manifests) == 1:
        manifest = manifests[0]
        _positive(manifest.get("size"), 64 * 1024)
        url = _asset_url(manifest.get("browser_download_url"), "winapp-update.json")
        if url.rsplit("/", 1)[0] != asset["browser_download_url"].rsplit("/", 1)[0]:
            raise UpdateError("Update manifest does not belong to the installer's release.")
        document = _json(url, opener, 64 * 1024)
        # Same-release manifest schema: {"assets": [{"name", "version", "size", "sha256"}]}.
        if isinstance(document, dict) and isinstance(document.get("assets"), list):
            entries = [entry for entry in document["assets"] if isinstance(entry, dict)
                       and entry.get("name") == asset["name"]]
            if len(entries) == 1:
                entry = entries[0]
                expected = entry.get("sha256")
                if (entry.get("version") == version and type(entry.get("size")) is int
                        and entry["size"] == asset["size"] and isinstance(expected, str)
                        and _HASH.fullmatch(expected)):
                    return expected.lower()
    raise UpdateError("A newer uploader exists, but no trustworthy SHA256 is published. Update refused.")


def find_update(current_version: str, *, opener: Any = None) -> Optional[ReleaseAsset]:
    """Find the highest stable installer across paginated public GitHub releases.

    ``opener`` is a urllib-compatible opener for deterministic tests. No token,
    account setting, CMS version, or repository 'latest' endpoint is used.
    Discovery is limited to 20 pages and a two-minute budget between requests;
    an in-flight request may consume at most its additional 30-second timeout.
    """
    current = _version(current_version)
    candidates: list[tuple[tuple[int, int, int], dict[str, Any], dict[str, Any], str]] = []
    deadline = time.monotonic() + DISCOVERY_BUDGET_SECONDS
    for page in range(1, MAX_PAGES + 1):
        if time.monotonic() >= deadline:
            raise UpdateError("GitHub update discovery exceeded its time budget; please retry later.")
        releases = _json(f"{API_URL}?per_page=100&page={page}", opener)
        if not isinstance(releases, list) or len(releases) > 100:
            raise UpdateError("GitHub returned an invalid release list.")
        if not releases:
            break
        for release in releases:
            if (not isinstance(release, dict) or type(release.get("draft")) is not bool
                    or type(release.get("prerelease")) is not bool):
                raise UpdateError("GitHub returned invalid release metadata.")
            if release["draft"] or release["prerelease"]:
                continue
            _positive(release.get("id"), 2**63 - 1)
            assets = release.get("assets")
            if not isinstance(assets, list) or len(assets) > 1000:
                raise UpdateError("GitHub returned invalid release assets.")
            for asset in assets:
                if not isinstance(asset, dict) or not isinstance(asset.get("name"), str):
                    raise UpdateError("GitHub returned invalid asset metadata.")
                match = _NAME.fullmatch(asset["name"])
                if not match:
                    continue
                version = match.group(1)
                numeric = _version(version)
                _positive(asset.get("id"), 2**63 - 1)
                _positive(asset.get("size"), MAX_INSTALLER_SIZE)
                _asset_url(asset.get("browser_download_url"), asset["name"])
                if numeric > current:
                    candidates.append((numeric, asset, release, version))
        if len(releases) < 100:
            break
    else:
        raise UpdateError("GitHub release pagination exceeded the safety limit.")
    if not candidates:
        return None
    highest = max(item[0] for item in candidates)
    winners = [item for item in candidates if item[0] == highest]
    # CMS releases may reattach identical uploader bytes under distinct asset IDs.
    # Resolve every highest-version digest before accepting this duplication.
    resolved = []
    for _, candidate, release, version in winners:
        if time.monotonic() >= deadline:
            raise UpdateError("GitHub update discovery exceeded its time budget; please retry later.")
        resolved.append((candidate, release, version, _digest(candidate, release, version, opener)))
    if len({(item[0]["size"], item[3]) for item in resolved}) != 1:
        raise UpdateError("Published installers with the same uploader version contain different bytes.")
    asset, release, version, digest = max(resolved, key=lambda item: (item[1]["id"], item[0]["id"]))
    return ReleaseAsset(version, asset["name"], asset["browser_download_url"],
                        asset["size"], digest,
                        asset["id"], release["id"])


def _validate(asset: ReleaseAsset) -> None:
    """Validate metadata again at every public disk/launch boundary."""
    if not isinstance(asset, ReleaseAsset):
        raise UpdateError("Invalid installer metadata.")
    _version(asset.version)
    if asset.name != f"PHPGalleryUploader-{asset.version}-Setup.exe":
        raise UpdateError("Installer name does not match its version.")
    _asset_url(asset.download_url, asset.name)
    _positive(asset.size, MAX_INSTALLER_SIZE)
    _positive(asset.asset_id, 2**63 - 1)
    _positive(asset.release_id, 2**63 - 1)
    if not isinstance(asset.sha256, str) or not _HASH.fullmatch(asset.sha256):
        raise UpdateError("No trustworthy SHA256 is available for this installer.")



def validated_release_asset(payload: object, current_version: str) -> ReleaseAsset:
    """Validate persisted metadata and require a strictly newer uploader version."""
    if not isinstance(payload, dict):
        raise UpdateError("Invalid cached update metadata.")
    try:
        asset = ReleaseAsset(**payload)
    except TypeError as exc:
        raise UpdateError("Invalid cached update metadata.") from exc
    _validate(asset)
    if _version(asset.version) <= _version(current_version):
        raise UpdateError("Cached update is not newer than this application.")
    return asset


_DOWNLOAD_SESSION = re.compile(r"download-[a-f0-9]{32}\Z")
_DOWNLOAD_FILE = re.compile(r"uploader-update-[a-zA-Z0-9_-]{8}\.(?:part|exe)\Z")
_DOWNLOAD_MARKER = "ownership.json"


def _safe_download_path(path: Path) -> Path:
    """Reject every reparse ancestor before resolving a storage path."""
    from uploader.update_helper import reject_reparse_path
    path = path.absolute()
    for candidate in (path, *path.parents):
        if candidate.is_symlink():
            raise UpdateError("Download storage must not contain symlinks.")
    try:
        reject_reparse_path(path)
        return path.resolve()
    except (OSError, ValueError) as exc:
        raise UpdateError("Download storage must not contain reparse points.") from exc


def _download_ownership(path: Path, updates_directory: Path) -> dict[str, object]:
    """Read a bounded marker only for an immediate child of the fixed owner root."""
    root = _safe_download_path(updates_directory)
    session = _safe_download_path(path)
    if session.parent != root or not _DOWNLOAD_SESSION.fullmatch(session.name):
        raise UpdateError("Unknown download session path.")
    marker = _safe_download_path(session / _DOWNLOAD_MARKER)
    try:
        with marker.open("rb") as stream:
            data = stream.read(4097)
        if len(data) > 4096:
            raise UpdateError("Download ownership marker exceeds its limit.")
        payload = json.loads(data)
    except (OSError, ValueError) as exc:
        raise UpdateError("Unknown download session ownership.") from exc
    if (not isinstance(payload, dict) or set(payload) != {"schema", "session", "created", "files"}
            or type(payload["schema"]) is not int or payload["schema"] != 1 or payload["session"] != session.name
            or type(payload["created"]) not in (int, float) or not math.isfinite(payload["created"])
            or not isinstance(payload["files"], list) or len(payload["files"]) > 4
            or any(not isinstance(name, str) or not _DOWNLOAD_FILE.fullmatch(name) for name in payload["files"])
            or len(set(payload["files"])) != len(payload["files"])):
        raise UpdateError("Unknown download session ownership.")
    return payload


def create_download_session(updates_directory: Path) -> Path:
    """Create an exact named, marked session under the fixed safe update root."""
    root = _safe_download_path(updates_directory)
    root.mkdir(parents=True, exist_ok=True)
    session = root / ("download-" + uuid.uuid4().hex)
    session.mkdir()
    (session / _DOWNLOAD_MARKER).write_text(json.dumps({"schema": 1, "session": session.name,
        "created": time.time(), "files": []}), encoding="utf-8")
    return session


def _record_download_file(directory: Path, name: str) -> None:
    """Record only this call's created names when using a managed download session."""
    if not _DOWNLOAD_SESSION.fullmatch(directory.name):
        return
    payload = _download_ownership(directory, directory.parent)
    files = payload["files"]
    if not isinstance(files, list) or not _DOWNLOAD_FILE.fullmatch(name):
        raise UpdateError("Invalid generated download file name.")
    if name not in files:
        if len(files) >= 4:
            raise UpdateError("Download session generated-file limit exceeded.")
        files.append(name)
    marker = _safe_download_path(directory / _DOWNLOAD_MARKER)
    marker.write_text(json.dumps(payload), encoding="utf-8")


def retire_download_session(path: Path, updates_directory: Path) -> None:
    """Delete only marked generated files; refuse unknown contents before any deletion."""
    payload = _download_ownership(path, updates_directory)
    session = _safe_download_path(path)
    allowed = {_DOWNLOAD_MARKER, *payload["files"]}
    entries = list(session.iterdir())
    if any(entry.name not in allowed for entry in entries):
        raise UpdateError("Download session contains unknown files; cleanup refused.")
    for entry in entries:
        _safe_download_path(entry)
        if not entry.is_file():
            raise UpdateError("Download session contains an unexpected file type.")
    for entry in entries:
        if entry.name != _DOWNLOAD_MARKER:
            entry.unlink()
    (session / _DOWNLOAD_MARKER).unlink()
    session.rmdir()


def cleanup_old_download_sessions(updates_directory: Path, now: float) -> None:
    """Retire only complete owned sessions older than one week, preserving unknown data."""
    root = _safe_download_path(updates_directory)
    if not root.exists():
        return
    for session in root.iterdir():
        if not _DOWNLOAD_SESSION.fullmatch(session.name):
            continue
        try:
            payload = _download_ownership(session, root)
            if now - float(payload["created"]) > 7 * 86400:
                retire_download_session(session, root)
        except (OSError, UpdateError):
            continue

def verify_installer(path: Path, asset: ReleaseAsset) -> None:
    """Recheck the complete installer immediately before launching it."""
    _validate(asset)
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size != asset.size:
            raise UpdateError("Installer size does not match the published update.")
        digest = hashlib.sha256()
        with path.open("rb") as stream:
            total = 0
            while block := stream.read(128 * 1024):
                total += len(block)
                if total > asset.size:
                    raise UpdateError("Installer exceeds the expected size.")
                digest.update(block)
        if total != asset.size or digest.hexdigest() != asset.sha256.lower():
            raise UpdateError("Installer SHA256 verification failed. Update refused.")
    except OSError as exc:
        raise UpdateError("Could not read the downloaded installer.") from exc


def download_update(asset: ReleaseAsset, directory: Path,
                    progress: Callable[[int, int], None], cancel: threading.Event,
                    *, opener: Any = None) -> Path:
    """Stream to a unique owned partial file, verify, then atomically finalize.

    The returned unique filename is session-owned. Failures delete only the
    partial file created by this call, preserving every preexisting file.
    """
    _validate(asset)
    partial: Optional[Path] = None
    try:
        if cancel.is_set():
            raise UpdateCancelled("Update download cancelled.")
        directory.mkdir(parents=True, exist_ok=True)
        descriptor, name = tempfile.mkstemp(prefix="uploader-update-", suffix=".part", dir=directory)
        partial = Path(name)
        try:
            _record_download_file(directory, partial.name)
        except Exception:
            os.close(descriptor)
            raise
        digest = hashlib.sha256()
        total = 0
        with os.fdopen(descriptor, "wb") as stream, _open(asset.download_url, opener) as response:
            progress(0, asset.size)
            while True:
                if cancel.is_set():
                    raise UpdateCancelled("Update download cancelled.")
                block = response.read(128 * 1024)
                if not block:
                    break
                total += len(block)
                if total > asset.size:
                    raise UpdateError("Installer download exceeds its published size.")
                stream.write(block)
                digest.update(block)
                progress(total, asset.size)
            stream.flush()
            os.fsync(stream.fileno())
        if cancel.is_set():
            raise UpdateCancelled("Update download cancelled.")
        if total != asset.size or digest.hexdigest() != asset.sha256.lower():
            raise UpdateError("Installer download failed size or SHA256 verification.")
        final = partial.with_suffix(".exe")
        # The random basename belongs to this session. Refuse an improbable
        # collision rather than overwriting any unrelated installer.
        if final.exists():
            raise UpdateError("Update download destination already exists.")
        _record_download_file(directory, final.name)
        partial.rename(final)
        partial = None
        return final
    except UpdateError:
        raise
    except Exception as exc:
        raise UpdateError("Could not download the uploader installer.") from exc
    finally:
        if partial is not None:
            partial.unlink(missing_ok=True)
