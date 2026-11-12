# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_d_legacy_client.py
# Module Type: Disposable Archived Client Qualification
# Purpose: Exercise the archived 1.0.10 WinApp transport against the owned B2 server.
# Responsibilities:
#   - Materialize only the pinned historical uploader package in its fixture.
#   - Verify actual old-client discovery, listing and authorized single-photo reads.
#   - Report sanitized route observations without credentials or identifiers.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Qualify the immutable legacy WinApp source against the owned current server."""

import hashlib
import http.client
import importlib
import json
import os
import re
import subprocess
import sys
import tarfile
import time
from collections import Counter
from pathlib import Path, PurePosixPath
from tempfile import TemporaryDirectory
from typing import Iterable
from urllib.parse import parse_qs, urlsplit


# Purpose: Bind the compatibility cohort to one archived, immutable client.
# Type: str. Units: Git object SHA-1. Scope: the D legacy-client qualification.
# Consumers: git archive, source report and PHP report-contract validation.
# Rationale: This exact 1.0.10 client predates thumbnail batching and is retained
#   as source evidence without importing or copying current client modules.
D_LEGACY_SOURCE_COMMIT = '1c1e2e9145cd92cb2f0ee731da5655c900d4d3e1'

# Purpose: Bound archive extraction work for the exact pinned uploader source.
# Type: int. Units: bytes. Scope: one disposable archived client source package.
# Consumers: extract_archived_source. Rationale: the pinned 67-file package has
#   5,662,797 content bytes; 16 MiB allows tar padding and bounds extraction.
D_LEGACY_ARCHIVE_MAX_BYTES = 16 * 1024 * 1024

# Purpose: Bound the read-only Git archive subprocess.
# Type: int. Units: seconds. Scope: one archived-client materialization.
# Consumers: extract_archived_source. Rationale: The local immutable object is
#   available in the full hosted checkout; 20 seconds bounds unexpected Git stalls.
D_LEGACY_ARCHIVE_TIMEOUT_SECONDS = 20

# Purpose: Stream each validated archive file to its owned extraction path.
# Type: int. Units: bytes. Scope: one regular tar member write.
# Consumers: extract_archived_source. Rationale: 64 KiB bounds each temporary
#   copy buffer while retaining efficient local archive I/O.
D_LEGACY_ARCHIVE_READ_CHUNK_BYTES = 64 * 1024

# Purpose: Keep one archived source compatibility cohort within its parent B2 child.
# Type: float. Units: seconds. Scope: one historical LinkingSession lifetime.
# Consumers: run_cohorts. Rationale: 120 seconds covers the bounded localhost
#   discovery and three single-image reads without extending the parent deadline.
D_LEGACY_SESSION_SECONDS = 120.0

# Purpose: Validate the synthetic photo digest supplied by the owning PHP fixture.
# Type: re.Pattern[str]. Units: encoded SHA-256 shape. Scope: expected B2 photo proofs.
# Consumers: validate_environment. Rationale: SHA-256 is represented by exactly
#   64 lowercase hexadecimal characters; malformed evidence fails closed.
D_LEGACY_SHA256_PATTERN = re.compile(r'[a-f0-9]{64}')

LegacyRequestEvent = dict[str, str | int | None]
LegacyReport = dict[str, str | int | bool | dict[str, int]]


class LegacyCheckFailure(RuntimeError):
    """Identify one fixed archived-client qualification check.

    Args:
        code (str): Fixed safe source-owned check label.
    """

    def __init__(self, code: str) -> None:
        """Store one fixed diagnostic code.

        Args:
            code (str): Fixed safe assertion label without fixture data.

        Returns:
            None: Stores only the check code.
        """
        self.code = code
        super().__init__(code)


def require(condition: bool, code: str) -> None:
    """Stop the cohort when one owned compatibility assertion fails.

    Args:
        condition (bool): Exact condition that must hold.
        code (str): Fixed safe source-owned check label.

    Returns:
        None: Continues only after the condition passes.
    """
    if not condition:
        raise LegacyCheckFailure(code)


def validate_environment() -> tuple[
    str, str, str, str, str, str, str, str, Path,
]:
    """Validate the hosted disposable fence and fixture-owned inputs.

    Returns:
        tuple[str, str, str, str, str, str, str, str, Path]: Local issuer,
            public and Admin bearers, gallery/photo identifiers, expected
            public/private SHA-256 values and the private fixture directory.

    Raises:
        LegacyCheckFailure: CI, fixture, input or loopback fencing is invalid.
    """
    require(os.environ.get('GITHUB_ACTIONS') == 'true', 'ci_guard')
    require(os.environ.get('GALLERY_WORKFLOW_ENABLE') == 'disposable-only',
            'owner_guard')
    issuer = os.environ.get('PHP_GALLERY_D_LEGACY_ISSUER', '')
    public_bearer = os.environ.get('PHP_GALLERY_D_LEGACY_PUBLIC_BEARER', '')
    admin_bearer = os.environ.get('PHP_GALLERY_D_LEGACY_ADMIN_BEARER', '')
    gallery_id = os.environ.get('PHP_GALLERY_D_LEGACY_GALLERY_ID', '')
    public_image_id = os.environ.get('PHP_GALLERY_D_LEGACY_PUBLIC_IMAGE_ID', '')
    private_image_id = os.environ.get('PHP_GALLERY_D_LEGACY_PRIVATE_IMAGE_ID', '')
    public_sha256 = os.environ.get('PHP_GALLERY_D_LEGACY_PUBLIC_SHA256', '')
    private_sha256 = os.environ.get('PHP_GALLERY_D_LEGACY_PRIVATE_SHA256', '')
    fixture_text = os.environ.get('GALLERY_WORKFLOW_FIXTURE', '')
    fixture_token = os.environ.get('GALLERY_WORKFLOW_TOKEN', '')
    require(re.fullmatch(r'pgla_[A-Za-z0-9_-]{43}', public_bearer) is not None
            and re.fullmatch(r'pgla_[A-Za-z0-9_-]{43}', admin_bearer) is not None,
            'bearer_guard')
    require(all(value.isascii() and value.isdecimal() and int(value) > 0
                for value in (gallery_id, public_image_id, private_image_id)),
            'fixture_ids')
    require(D_LEGACY_SHA256_PATTERN.fullmatch(public_sha256) is not None
            and D_LEGACY_SHA256_PATTERN.fullmatch(private_sha256) is not None,
            'fixture_hashes')
    parsed_issuer = urlsplit(issuer)
    require(parsed_issuer.scheme == 'http'
            and parsed_issuer.hostname == 'localhost'
            and parsed_issuer.port is not None
            and parsed_issuer.username is None and parsed_issuer.password is None
            and not parsed_issuer.query and not parsed_issuer.fragment
            and parsed_issuer.path == '', 'issuer_fence')
    fixture = Path(fixture_text).resolve(strict=True)
    owner_path = fixture / '.workflow-owner'
    require(fixture.name.startswith('gallery-workflow-') and fixture_token
            and owner_path.is_file()
            and owner_path.read_text(encoding='ascii').strip() == fixture_token
            and (fixture / 'endpoint.json').is_file(), 'fixture_owner')
    endpoint = json.loads((fixture / 'endpoint.json').read_text(encoding='utf-8'))
    owned_url = urlsplit(str(endpoint.get('url', '')))
    require(owned_url.scheme == 'http' and owned_url.hostname == '127.0.0.1'
            and owned_url.port == parsed_issuer.port, 'endpoint_fence')
    return (issuer, public_bearer, admin_bearer, gallery_id, public_image_id,
            private_image_id, public_sha256, private_sha256, fixture)


def extract_archived_source(
    repo_root: Path, temporary_root: Path,
) -> tuple[Path, str]:
    """Archive and safely materialize only the pinned historical client files.

    Args:
        repo_root (Path): Current repository root containing the immutable Git object.
        temporary_root (Path): Temporary directory inside the owned fixture.

    Returns:
        tuple[Path, str]: Temporary extraction root and archived version string.

    Raises:
        LegacyCheckFailure: Git archive bytes, paths, member types or version fail.
    """
    archive_path = temporary_root / 'client.tar'
    extract_root = temporary_root / 'source'
    extract_root.mkdir()
    try:
        with archive_path.open('wb') as archive_stream:
            subprocess.run(
                ['git', '-C', str(repo_root), 'archive', '--format=tar',
                 D_LEGACY_SOURCE_COMMIT, 'winapp/uploader', 'winapp/VERSION'],
                stdout=archive_stream, stderr=subprocess.DEVNULL, check=True,
                timeout=D_LEGACY_ARCHIVE_TIMEOUT_SECONDS,
            )
    except (OSError, subprocess.SubprocessError):
        raise LegacyCheckFailure('archive_unavailable') from None
    require(archive_path.stat().st_size <= D_LEGACY_ARCHIVE_MAX_BYTES,
            'archive_size')
    expanded_bytes = 0
    seen_names: set[str] = set()
    with tarfile.open(archive_path, mode='r:') as archive:
        members = archive.getmembers()
        for member in members:
            relative = PurePosixPath(member.name)
            name = relative.as_posix()
            require(not relative.is_absolute() and '\\' not in member.name
                    and '..' not in relative.parts and name not in seen_names,
                    'archive_path')
            seen_names.add(name)
            allowed = (
                name in ('winapp', 'winapp/uploader')
                or name.startswith('winapp/uploader/')
                or name == 'winapp/VERSION'
            )
            require(allowed and (member.isdir() or member.isfile()),
                    'archive_member')
            if member.isfile():
                expanded_bytes += member.size
                require(expanded_bytes <= D_LEGACY_ARCHIVE_MAX_BYTES,
                        'archive_expansion')
            destination = (extract_root / Path(*relative.parts)).resolve()
            require(destination == extract_root
                    or extract_root in destination.parents, 'archive_escape')
            if member.isdir():
                destination.mkdir(parents=True, exist_ok=True)
                continue
            destination.parent.mkdir(parents=True, exist_ok=True)
            source = archive.extractfile(member)
            require(source is not None, 'archive_member_data')
            with source, destination.open('xb') as target:
                while chunk := source.read(D_LEGACY_ARCHIVE_READ_CHUNK_BYTES):
                    target.write(chunk)
    version_path = extract_root / 'winapp' / 'VERSION'
    require(version_path.is_file(), 'version_missing')
    version = version_path.read_text(encoding='ascii').strip()
    require(version == '1.0.10', 'version_mismatch')
    return extract_root, version


def run_cohorts(
    extract_root: Path, issuer: str, public_bearer: str, admin_bearer: str,
    gallery_id: str, public_image_id: str, private_image_id: str,
    public_sha256: str, private_sha256: str,
) -> LegacyReport:
    """Exercise archived discovery, page proofs and actual legacy media GETs.

    Args:
        extract_root (Path): Root of the verified archived source package.
        issuer (str): Exact fixture-owned localhost issuer.
        public_bearer (str): Owned public-media grant, kept in process memory.
        admin_bearer (str): Owned Admin-media grant, kept in process memory.
        gallery_id (str): Owned physical gallery ID.
        public_image_id (str): Public synthetic image ID.
        private_image_id (str): Synthetic image made private for this cohort.
        public_sha256 (str): Existing B2 single-route proof for the public photo.
        private_sha256 (str): Existing B2 single-route proof for the private photo.

    Returns:
        LegacyReport: Sanitized schema, pinned source commit/version, assertion
            string, discovery and client capability booleans, SHA/MIME match
            booleans, public-private refusal status, legacy GET and batch POST
            counts, plus fixed route names mapped to request counts.

    Raises:
        LegacyCheckFailure: Archived module, live HTTP behavior or response proof fails.
    """
    archive_winapp = extract_root / 'winapp'
    sys.path.insert(0, str(archive_winapp))
    require('uploader' not in sys.modules, 'module_preloaded')
    linking = importlib.import_module('uploader.app_linking')
    module_path = Path(str(linking.__file__)).resolve()
    require(module_path == (archive_winapp / 'uploader' / 'app_linking.py').resolve(),
            'module_path')
    package = sys.modules.get('uploader')
    package_paths = tuple(Path(path).resolve() for path in getattr(package, '__path__', ()))
    require(package_paths == ((archive_winapp / 'uploader').resolve(),),
            'package_path')
    package_root = (archive_winapp / 'uploader').resolve()
    imported_paths = [
        Path(str(module.__file__)).resolve()
        for name, module in sys.modules.items()
        if (name == 'uploader' or name.startswith('uploader.'))
        and getattr(module, '__file__', None) is not None
    ]
    require(imported_paths and all(path.is_relative_to(package_root)
                                   for path in imported_paths), 'module_mixed')
    require(not hasattr(linking.LinkingSession, 'media_thumbnails'),
            'archived_client_has_batch')

    original_request = http.client.HTTPConnection.request
    original_getresponse = http.client.HTTPConnection.getresponse
    events: list[LegacyRequestEvent] = []
    last_event: dict[int, LegacyRequestEvent] = {}

    def observe_request(
        connection: http.client.HTTPConnection, method: str, url: str,
        body: bytes | str | Iterable[bytes] | None = None,
        headers: dict[str, str] | None = None, *, encode_chunked: bool = False,
    ) -> None:
        """Record route facts then delegate to the original live HTTP transport.

        Args:
            connection (http.client.HTTPConnection): Actual old-client connection.
            method (str): HTTP method selected by the archived client.
            url (str): Request target inspected in memory and never reported.
            body (bytes | str | Iterable[bytes] | None): Original request body.
            headers (dict[str, str] | None): Original headers, or None for default.
            encode_chunked (bool): Original chunked-transfer option.

        Returns:
            None: The original transport sends the unmodified request.
        """
        parts = urlsplit(url)
        query = parse_qs(parts.query, keep_blank_values=True, strict_parsing=True)
        page_values = query.get('page', [])
        require(parts.path.endswith('/index.php') and len(page_values) == 1,
                'request_route')
        route = page_values[0]
        require(route in {
            'app_linking_discovery', 'app_linking_identity',
            'app_linking_galleries', 'app_linking_media',
            'app_linking_thumbnail_batch',
        }, 'unexpected_route')
        event: LegacyRequestEvent = {
            'method': method, 'route': route, 'status': None,
            'kind': query.get('kind', [''])[0],
            'image_id': query.get('image_id', [''])[0],
            'gallery_id': query.get('gallery_id', [''])[0],
        }
        expected_media_keys = (
            {'gallery_id', 'kind', 'page'}
            if query.get('kind') == ['photos']
            else {'gallery_id', 'image_id', 'kind', 'page'}
        )
        if route == 'app_linking_media':
            require(method == 'GET' and set(query) == expected_media_keys
                    and len(query.get('gallery_id', [])) == 1
                    and query['gallery_id'][0] == gallery_id
                    and query.get('kind') in (['photos'], ['thumbnail']),
                    'media_route_shape')
            if query.get('kind') == ['thumbnail']:
                require(len(query.get('image_id', [])) == 1
                        and query['image_id'][0] in {
                            public_image_id, private_image_id,
                        }, 'media_image_route')
        events.append(event)
        last_event[id(connection)] = event
        original_request(
            connection, method, url, body=body,
            headers={} if headers is None else headers,
            encode_chunked=encode_chunked,
        )

    def observe_getresponse(
        connection: http.client.HTTPConnection,
    ) -> http.client.HTTPResponse:
        """Capture an actual response status and return its original response.

        Args:
            connection (http.client.HTTPConnection): Actual old-client connection.

        Returns:
            http.client.HTTPResponse: Unmodified response from the live server.
        """
        response = original_getresponse(connection)
        event = last_event.get(id(connection))
        if event is not None:
            event['status'] = response.status
        return response

    sessions: list[linking.LinkingSession] = []
    http.client.HTTPConnection.request = observe_request
    http.client.HTTPConnection.getresponse = observe_getresponse
    try:
        capabilities = linking.discover_linking_capabilities(issuer)
        require('media:thumbnail-batch-v1' in capabilities,
                'batch_discovery_missing')
        public_session = linking.LinkingSession(
            issuer, public_bearer, time.monotonic() + D_LEGACY_SESSION_SECONDS,
            scope='link:identity galleries:list media:read',
        )
        sessions.append(public_session)
        public_session.identity()
        public_galleries = public_session.gallery_page().galleries
        require(any(row.gallery_id == int(gallery_id) for row in public_galleries),
                'public_gallery_missing')
        public_page = public_session.media_page(int(gallery_id))
        public_photo_ids = {photo.image_id for photo in public_page.photos}
        require(int(public_image_id) in public_photo_ids, 'public_photo_missing')
        require(int(private_image_id) not in public_photo_ids,
                'private_photo_listed_public')
        public_bytes, public_mime = public_session.media_photo(
            int(gallery_id), int(public_image_id), True, cache_eligible=False,
        )
        require(public_mime == 'image/jpeg'
                and hashlib.sha256(public_bytes).hexdigest() == public_sha256,
                'public_photo_proof')
        refusal_before = len(events)
        try:
            public_session.media_photo(
                int(gallery_id), int(private_image_id), True,
                cache_eligible=False,
            )
        except linking.LinkingError:
            pass
        else:
            raise LegacyCheckFailure('public_private_read_allowed')
        refusal_events = events[refusal_before:]
        require(len(refusal_events) == 1
                and refusal_events[0]['route'] == 'app_linking_media'
                and refusal_events[0]['method'] == 'GET'
                and refusal_events[0]['status'] == 404,
                'public_private_refusal')

        admin_session = linking.LinkingSession(
            issuer, admin_bearer, time.monotonic() + D_LEGACY_SESSION_SECONDS,
            scope='link:identity galleries:list media:admin',
        )
        sessions.append(admin_session)
        admin_session.identity()
        admin_galleries = admin_session.gallery_page().galleries
        require(any(row.gallery_id == int(gallery_id) for row in admin_galleries),
                'admin_gallery_missing')
        admin_page = admin_session.media_page(int(gallery_id))
        admin_photo_ids = {photo.image_id for photo in admin_page.photos}
        require(int(private_image_id) in admin_photo_ids,
                'admin_private_photo_missing')
        private_bytes, private_mime = admin_session.media_photo(
            int(gallery_id), int(private_image_id), True, cache_eligible=False,
        )
        require(private_mime == 'image/jpeg'
                and hashlib.sha256(private_bytes).hexdigest() == private_sha256,
                'admin_private_photo_proof')
    finally:
        for session in sessions:
            session.close()
        http.client.HTTPConnection.request = original_request
        http.client.HTTPConnection.getresponse = original_getresponse

    media_reads = [event for event in events if event['route'] == 'app_linking_media'
                   and event['kind'] == 'thumbnail']
    batch_posts = [event for event in events
                   if event['route'] == 'app_linking_thumbnail_batch'
                   and event['method'] == 'POST']
    route_counts = Counter(str(event['route']) for event in events)
    require(all(event['method'] == 'GET' and isinstance(event['status'], int)
                for event in events), 'transport_events')
    # Two sessions perform one media page each; public success, public refusal
    # and Admin private access are the three expected image GETs.
    require(len(media_reads) == 3 and not batch_posts
            and route_counts == Counter({
                'app_linking_discovery': 1,
                'app_linking_identity': 2,
                'app_linking_galleries': 2,
                'app_linking_media': 5,
            }), 'route_counts')
    return {
        'schema': 'issue-362-d-legacy-client-v1',
        'source_commit': D_LEGACY_SOURCE_COMMIT,
        'source_version': '1.0.10',
        'assertions': 'passed',
        'batch_capability_advertised': True,
        'legacy_client_batch_method': False,
        'public_photo_sha256_match': True,
        'public_photo_mime_match': True,
        'public_private_refusal_status': 404,
        'admin_private_photo_sha256_match': True,
        'admin_private_photo_mime_match': True,
        'legacy_thumbnail_gets': len(media_reads),
        'batch_posts': len(batch_posts),
        'routes': dict(route_counts),
    }


def main() -> int:
    """Run the fenced cohort and write one sanitized fixture-owned report.

    Returns:
        int: Process exit status, zero only for all legacy compatibility checks.
    """
    try:
        (issuer, public_bearer, admin_bearer, gallery_id, public_image_id,
         private_image_id, public_sha256, private_sha256, fixture) = validate_environment()
        report_path_text = os.environ.get('PHP_GALLERY_D_LEGACY_REPORT', '')
        report_path = Path(report_path_text).resolve()
        require(fixture in report_path.parents, 'report_path')
        repo_root = Path(__file__).resolve().parents[2]
        with TemporaryDirectory(
            prefix='issue-362-legacy-source-', dir=fixture,
        ) as source_directory:
            source_root = Path(source_directory).resolve()
            extract_root, version = extract_archived_source(
                repo_root, source_root,
            )
            cache_root = source_root / 'client-cache'
            cache_root.mkdir()
            os.environ['XDG_CACHE_HOME'] = str(cache_root)
            os.environ['LOCALAPPDATA'] = str(cache_root)
            report = run_cohorts(
                extract_root, issuer, public_bearer, admin_bearer,
                gallery_id, public_image_id, private_image_id,
                public_sha256, private_sha256,
            )
            require(report['source_version'] == version, 'report_version')
            report_path.write_text(
                json.dumps(report, sort_keys=True, separators=(',', ':')) + '\n',
                encoding='utf-8',
            )
        print('PASS gallery workflow archived legacy client')
        return 0
    except LegacyCheckFailure as error:
        print('FAIL gallery workflow archived legacy client ' + error.code,
              file=sys.stderr)
    except Exception as error:
        # Class names are source-owned diagnostic labels; exception messages
        # can contain bearer values, URLs or paths and must remain private.
        error_class = type(error).__name__
        if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', error_class) is None:
            error_class = 'Exception'
        print('FAIL gallery workflow archived legacy client unexpected '
              + error_class, file=sys.stderr)
    return 1


if __name__ == '__main__':
    raise SystemExit(main())
