# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_d_old_server_client.py
# Module Type: Disposable Historical Server Compatibility Qualification
# Purpose: Exercise the current WinApp source against archived PHP Gallery 1.0.10.
# Responsibilities:
#   - Materialize a bounded immutable PHP source tree in an owned fixture.
#   - Verify current session behavior and real legacy media reads over HTTP.
#   - Emit only source identities and sanitized compatibility observations.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Qualify current thumbnail fallback against an owned historical server."""

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
from typing import Callable
from urllib.parse import parse_qs, urlsplit


# Purpose: Bind the source compatibility check to the immutable 1.0.10 server.
# Type: str. Units: Git object SHA-1. Scope: D historical-server qualification.
# Consumers: git archive, report contract and PHP parent validation.
# Rationale: This exact release is the supported older server source fixture.
D_OLD_SERVER_COMMIT = '1c1e2e9145cd92cb2f0ee731da5655c900d4d3e1'

# Purpose: Bound archived and extracted historical source materialization.
# Type: int. Units: bytes. Scope: one owned archived PHP source tree.
# Consumers: extract_archived_server. Rationale: The pinned source is 25,716,356
#   content bytes; 64 MiB bounds tar overhead and extracted disk usage.
D_OLD_SERVER_ARCHIVE_MAX_BYTES = 64 * 1024 * 1024

# Purpose: Bound each archive member copy buffer.
# Type: int. Units: bytes. Scope: one regular archived source file.
# Consumers: extract_archived_server. Rationale: 64 KiB keeps extraction memory
#   bounded while avoiding excessive local copy operations.
D_OLD_SERVER_READ_CHUNK_BYTES = 64 * 1024

# Purpose: Bound the immutable-source Git subprocess.
# Type: int. Units: seconds. Scope: one old-server archive operation.
# Consumers: extract_archived_server. Rationale: Hosted checkout contains the
#   pinned object; 20 seconds bounds unexpected archive stalls.
D_OLD_SERVER_ARCHIVE_TIMEOUT_SECONDS = 20

# Purpose: Bound one current-client historical-server session.
# Type: float. Units: seconds. Scope: one local compatibility cohort.
# Consumers: LinkingSession lease. Rationale: 120 seconds covers metadata and
#   two bounded localhost thumbnail reads without extending the parent audit.
D_OLD_SERVER_SESSION_SECONDS = 120.0

# Purpose: Restrict sanitized source commit identities.
# Type: re.Pattern[str]. Units: lowercase hexadecimal SHA-1. Scope: report source.
# Consumers: validate_environment and main. Rationale: Git SHA-1 object IDs have
#   exactly 40 lowercase hexadecimal characters in the hosted repository.
D_OLD_SERVER_SHA_PATTERN = re.compile(r'[a-f0-9]{40}')

# Purpose: Match exact archived source paths admitted by this fixture.
# Type: tuple[str, ...]. Units: repository paths. Scope: one server archive.
# Consumers: extract_archived_server. Rationale: These paths are the complete
#   source closure of GalleryWorkflow\Fixture copying and old seed execution.
D_OLD_SERVER_PATHS = (
    'app', 'public', 'database', 'index.php',
    'scripts/cli_guard.php', 'scripts/reconcile_admin_operations.php',
    'tests/support/gallery_workflow_seed.php',
    'tests/support/gallery_workflow_safety.php',
    'tests/support/gallery_workflow_router.php',
    'tests/support/gallery_workflow_browser.js',
    'tests/support/public_widget_persistence_browser.js',
)

# Purpose: Validate the pinned source tree size established during static review.
# Type: tuple[int, int]. Units: regular files and content bytes. Scope: archive.
# Consumers: extract_archived_server. Rationale: Detect an incomplete or
#   unexpectedly changed archive for this immutable commit before fixture use.
D_OLD_SERVER_EXPECTED_TREE = (969, 25_716_356)

SafeReport = dict[str, str | int | bool | dict[str, int]]
RequestEvent = dict[str, str | int | None]
HTTPConnectionRequest = Callable[
    [http.client.HTTPConnection, str, str, bytes | str | None,
     dict[str, str] | None], None,
]
HTTPConnectionResponse = Callable[
    [http.client.HTTPConnection], http.client.HTTPResponse,
]


class OldServerCheckFailure(RuntimeError):
    """Identify one fixed historical-server qualification check.

    Args:
        code (str): Fixed safe source-owned check label.
    """

    def __init__(self, code: str) -> None:
        """Store a fixed diagnostic code.

        Args:
            code (str): Fixed safe assertion label without fixture data.

        Returns:
            None: Stores only the check code.
        """
        self.code = code
        super().__init__(code)


def require(condition: bool, code: str) -> None:
    """Stop when one exact owned compatibility assertion fails.

    Args:
        condition (bool): Contract that must hold.
        code (str): Fixed safe diagnostic label.

    Returns:
        None: Continues only after the condition passes.
    """
    if not condition:
        raise OldServerCheckFailure(code)


def validate_parent_fixture() -> tuple[Path, Path]:
    """Validate CI ownership and return the repository and parent fixture.

    Returns:
        tuple[Path, Path]: Current repository root and verified parent fixture.

    Raises:
        OldServerCheckFailure: Hosted workflow or fixture ownership is invalid.
    """
    require(os.environ.get('GITHUB_ACTIONS') == 'true', 'ci_guard')
    require(os.environ.get('GALLERY_WORKFLOW_ENABLE') == 'disposable-only',
            'owner_guard')
    root = Path(__file__).resolve().parents[2]
    fixture = Path(os.environ.get('GALLERY_WORKFLOW_FIXTURE', '')).resolve(
        strict=True,
    )
    owner = os.environ.get('GALLERY_WORKFLOW_TOKEN', '')
    require(fixture.name.startswith('gallery-workflow-')
            and re.fullmatch(r'[a-f0-9]{24}', owner) is not None
            and (fixture / '.workflow-owner').read_text(
                encoding='ascii',
            ).strip() == owner, 'parent_fixture')
    require((fixture / 'endpoint.json').is_file(), 'parent_endpoint')
    return root, fixture


def extract_archived_server(
    repo_root: Path, fixture: Path,
) -> tuple[Path, str]:
    """Safely extract only the pinned historical PHP source closure.

    Args:
        repo_root (Path): Current repository containing the immutable object.
        fixture (Path): Verified current disposable fixture directory.

    Returns:
        tuple[Path, str]: Extracted server source root and verified version.

    Raises:
        OldServerCheckFailure: Archive identity, size, path or content is invalid.
    """
    archive_path = fixture / 'd-old-server.tar'
    source_root = fixture / 'd-old-server-source'
    source_root.mkdir()
    try:
        with archive_path.open('xb') as archive_stream:
            subprocess.run(
                ['git', '-C', str(repo_root), 'archive', '--format=tar',
                 D_OLD_SERVER_COMMIT, '--', *D_OLD_SERVER_PATHS],
                stdout=archive_stream, stderr=subprocess.DEVNULL, check=True,
                timeout=D_OLD_SERVER_ARCHIVE_TIMEOUT_SECONDS,
            )
    except (OSError, subprocess.SubprocessError):
        raise OldServerCheckFailure('archive_unavailable') from None
    require(archive_path.stat().st_size <= D_OLD_SERVER_ARCHIVE_MAX_BYTES,
            'archive_size')
    names: set[str] = set()
    expanded_bytes = 0
    file_count = 0
    with tarfile.open(archive_path, mode='r:') as archive:
        for member in archive.getmembers():
            relative = PurePosixPath(member.name)
            name = relative.as_posix()
            require(not relative.is_absolute() and '\\' not in member.name
                    and '..' not in relative.parts and name not in names,
                    'archive_path')
            names.add(name)
            allowed = any(
                name == prefix or name.startswith(prefix.rstrip('/') + '/')
                for prefix in D_OLD_SERVER_PATHS
            ) or name in ('scripts', 'tests', 'tests/support')
            require(allowed and (member.isdir() or member.isfile()),
                    'archive_member')
            destination = (source_root / Path(*relative.parts)).resolve()
            require(destination == source_root
                    or source_root in destination.parents, 'archive_escape')
            if member.isdir():
                destination.mkdir(parents=True, exist_ok=True)
                continue
            expanded_bytes += member.size
            file_count += 1
            require(expanded_bytes <= D_OLD_SERVER_ARCHIVE_MAX_BYTES,
                    'archive_expansion')
            destination.parent.mkdir(parents=True, exist_ok=True)
            source = archive.extractfile(member)
            require(source is not None, 'archive_member_data')
            with source, destination.open('xb') as target:
                while chunk := source.read(D_OLD_SERVER_READ_CHUNK_BYTES):
                    target.write(chunk)
    require((file_count, expanded_bytes) == D_OLD_SERVER_EXPECTED_TREE,
            'archive_tree_shape')
    manifest_path = source_root / 'app' / 'core-manifest.json'
    manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    version = manifest.get('version')
    require(version == '1.0.10', 'manifest_version')
    return source_root, version


def validate_client_environment() -> tuple[
    str, str, int, tuple[int, int], tuple[str, str], tuple[str, str], Path, str,
]:
    """Validate the old fixture endpoint, grant, expected proofs and source SHA.

    Returns:
        tuple[str, str, int, tuple[int, int], tuple[str, str], tuple[str, str], Path, str]:
            Issuer, bearer, gallery ID, two photo IDs, expected MIME/hash pairs,
            fixture path and current client source commit.

    Raises:
        OldServerCheckFailure: Any ownership, grant, endpoint or input fence fails.
    """
    issuer = os.environ.get('PHP_GALLERY_D_OLD_SERVER_ISSUER', '')
    bearer = os.environ.get('PHP_GALLERY_D_OLD_SERVER_BEARER', '')
    gallery_text = os.environ.get('PHP_GALLERY_D_OLD_SERVER_GALLERY_ID', '')
    photo_texts = (
        os.environ.get('PHP_GALLERY_D_OLD_SERVER_PHOTO_1', ''),
        os.environ.get('PHP_GALLERY_D_OLD_SERVER_PHOTO_2', ''),
    )
    mimes = (
        os.environ.get('PHP_GALLERY_D_OLD_SERVER_MIME_1', ''),
        os.environ.get('PHP_GALLERY_D_OLD_SERVER_MIME_2', ''),
    )
    hashes = (
        os.environ.get('PHP_GALLERY_D_OLD_SERVER_SHA256_1', ''),
        os.environ.get('PHP_GALLERY_D_OLD_SERVER_SHA256_2', ''),
    )
    commit = os.environ.get('GITHUB_SHA', '')
    fixture = Path(os.environ.get('GALLERY_WORKFLOW_FIXTURE', '')).resolve(
        strict=True,
    )
    owner = os.environ.get('GALLERY_WORKFLOW_TOKEN', '')
    endpoint = json.loads((fixture / 'endpoint.json').read_text(encoding='utf-8'))
    target = urlsplit(str(endpoint.get('url', '')))
    parsed = urlsplit(issuer)
    require(parsed.scheme == 'http' and parsed.hostname == 'localhost'
            and parsed.port is not None and parsed.path == ''
            and not parsed.query and not parsed.fragment
            and parsed.username is None and parsed.password is None,
            'issuer_fence')
    require(target.scheme == 'http' and target.hostname == '127.0.0.1'
            and target.port == parsed.port, 'endpoint_fence')
    require(fixture.name.startswith('gallery-workflow-')
            and re.fullmatch(r'[a-f0-9]{24}', owner) is not None
            and (fixture / '.workflow-owner').read_text(
                encoding='ascii',
            ).strip() == owner, 'fixture_owner')
    require(re.fullmatch(r'pgla_[A-Za-z0-9_-]{43}', bearer) is not None,
            'bearer_guard')
    require(re.fullmatch(r'[1-9][0-9]{0,18}', gallery_text) is not None
            and all(re.fullmatch(r'[1-9][0-9]{0,18}', value) is not None
                    for value in photo_texts)
            and photo_texts[0] != photo_texts[1], 'fixture_ids')
    require(all(re.fullmatch(r'image/[a-zA-Z0-9.+-]{1,64}', value) is not None
                for value in mimes)
            and all(re.fullmatch(r'[a-f0-9]{64}', value) is not None
                    for value in hashes), 'fixture_proofs')
    require(D_OLD_SERVER_SHA_PATTERN.fullmatch(commit) is not None,
            'client_source_commit')
    return (
        issuer, bearer, int(gallery_text),
        (int(photo_texts[0]), int(photo_texts[1])),
        (mimes[0], mimes[1]), (hashes[0], hashes[1]), fixture, commit,
    )


def observe_request_events(
    events: list[RequestEvent],
) -> tuple[
    HTTPConnectionRequest, HTTPConnectionResponse,
]:
    """Install real HTTPConnection observers that delegate to original methods.

    Args:
        events (list[RequestEvent]): Private in-memory route observations.

    Returns:
        tuple[HTTPConnectionRequest, HTTPConnectionResponse]: Original methods
            needed to restore the stdlib transport after the cohort.
    """
    original_request = http.client.HTTPConnection.request
    original_response = http.client.HTTPConnection.getresponse
    pending: dict[int, RequestEvent] = {}

    def observe_request(
        connection: http.client.HTTPConnection,
        method: str,
        url: str,
        body: bytes | str | None = None,
        headers: dict[str, str] | None = None,
        *,
        encode_chunked: bool = False,
    ) -> None:
        """Record route shape and forward the exact request to stdlib transport.

        Args:
            connection (http.client.HTTPConnection): Actual production connection.
            method (str): Actual HTTP method.
            url (str): Origin-form target retained only in memory for parsing.
            body (bytes | str | None): Original outbound body.
            headers (dict[str, str] | None): Original request headers.
            encode_chunked (bool): Original stdlib framing option.

        Returns:
            None: The original transport sends the request.
        """
        target = urlsplit(url)
        query = parse_qs(target.query, keep_blank_values=True, strict_parsing=True)
        page_values = query.get('page', [])
        require(target.path == '/index.php' and len(page_values) == 1,
                'request_route')
        route = page_values[0]
        require(route in {
            'app_linking_discovery', 'app_linking_identity',
            'app_linking_galleries', 'app_linking_media',
            'app_linking_thumbnail_batch',
        }, 'unexpected_route')
        event: RequestEvent = {
            'method': method,
            'route': route,
            'kind': query.get('kind', [None])[0],
            'status': None,
        }
        events.append(event)
        pending[id(connection)] = event
        original_request(
            connection, method, url, body=body,
            headers={} if headers is None else headers,
            encode_chunked=encode_chunked,
        )

    def observe_response(
        connection: http.client.HTTPConnection,
    ) -> http.client.HTTPResponse:
        """Forward response acquisition and record its status code.

        Args:
            connection (http.client.HTTPConnection): Actual production connection.

        Returns:
            http.client.HTTPResponse: Original response from stdlib transport.
        """
        response = original_response(connection)
        event = pending.get(id(connection))
        if event is not None:
            event['status'] = response.status
        return response

    http.client.HTTPConnection.request = observe_request
    http.client.HTTPConnection.getresponse = observe_response
    return original_request, original_response


def run_current_client(
    issuer: str,
    bearer: str,
    gallery_id: int,
    photo_ids: tuple[int, int],
    expected_mimes: tuple[str, str],
    expected_hashes: tuple[str, str],
) -> SafeReport:
    """Call the current production session API against the old fixture server.

    Args:
        issuer (str): Exact old-fixture localhost issuer.
        bearer (str): Fixture-scoped public media bearer.
        gallery_id (int): Historical fixture physical gallery ID.
        photo_ids (tuple[int, int]): Exact two historical seeded photo IDs.
        expected_mimes (tuple[str, str]): MIME values from reference HTTP GETs.
        expected_hashes (tuple[str, str]): SHA-256 digests from reference GETs.

    Returns:
        SafeReport: Sanitized source identity, capability, route, publication
            and digest-match evidence without URLs, credentials or identifiers.

    Raises:
        OldServerCheckFailure: Current session, transport or proof fails.
    """
    repo_root = Path(__file__).resolve().parents[2]
    winapp_root = repo_root / 'winapp'
    uploader_root = winapp_root / 'uploader'
    require('uploader' not in sys.modules, 'current_module_preloaded')
    sys.path.insert(0, str(winapp_root))
    linking = importlib.import_module('uploader.app_linking')
    module_path = Path(str(linking.__file__)).resolve()
    require(module_path == (uploader_root / 'app_linking.py').resolve(),
            'current_module_path')
    package = sys.modules.get('uploader')
    package_paths = tuple(
        Path(path).resolve() for path in getattr(package, '__path__', ())
    )
    require(package_paths == (uploader_root.resolve(),), 'current_package_path')

    events: list[RequestEvent] = []
    original_request, original_response = observe_request_events(events)
    session = None
    stage = 'discovery'
    try:
        capabilities = linking.discover_linking_capabilities(issuer)
        require('media:read' in capabilities, 'old_server_media_missing')
        require('media:thumbnail-batch-v1' not in capabilities,
                'old_server_advertised_batch')
        session = linking.LinkingSession(
            issuer, bearer, time.monotonic() + D_OLD_SERVER_SESSION_SECONDS,
            scope='link:identity galleries:list media:read',
            thumbnail_batch_supported=(
                'media:thumbnail-batch-v1' in capabilities
            ),
        )
        require(not session.supports_thumbnail_batch(), 'batch_negotiated')
        stage = 'identity'
        session.identity()
        stage = 'galleries'
        gallery_page = session.gallery_page()
        require(any(row.gallery_id == gallery_id
                    for row in gallery_page.galleries), 'gallery_missing')
        stage = 'media_page'
        media_page = session.media_page(gallery_id)
        selected = tuple(
            photo for photo in media_page.photos
            if photo.image_id in photo_ids
        )
        require(len(selected) == 2
                and tuple(photo.image_id for photo in selected)
                == tuple(sorted(photo_ids)), 'photos_missing')
        published: list[tuple[bytes, str]] = []

        def publish(
            result: linking.ThumbnailPublication
            | linking.LegacyThumbnailPublication,
            _timings: linking.MediaReadTimings,
            check_active: Callable[[], None],
        ) -> None:
            """Collect only validated current session publication values.

            Args:
                result (linking.ThumbnailPublication | linking.LegacyThumbnailPublication):
                    Production thumbnail publication object.
                _timings (linking.MediaReadTimings): Production item timing.
                check_active (Callable[[], None]): Operation authority callback.

            Returns:
                None: Stores the response body and MIME for in-memory proof.

            Raises:
                OldServerCheckFailure: Publication did not contain an image.
            """
            require(callable(check_active), 'publication_callback')
            check_active()
            body = getattr(result, 'body', None)
            mime = getattr(result, 'mime', None)
            require(getattr(result, 'status', None) == 'ok'
                    and isinstance(body, bytes)
                    and isinstance(mime, str), 'publication_body')
            published.append((body, mime))

        stats = linking.ThumbnailBatchStats()
        stage = 'thumbnails'
        session.media_thumbnails(
            gallery_id, selected, publish, stats=stats,
        )
        require(len(published) == 2 and stats.delivered == 2
                and stats.returned == 2
                and stats.fallback_capability_missing == 2,
                'fallback_publications')
        matches = [
            mime == expected_mimes[index]
            and hashlib.sha256(body).hexdigest() == expected_hashes[index]
            for index, (body, mime) in enumerate(published)
        ]
        require(all(matches), 'reference_bytes')
    except linking.LinkingResponseError as error:
        # Preserve only the fixed call stage and a numeric HTTP status.
        status = error.status if type(error.status) is int else 0
        raise OldServerCheckFailure(stage + '_http_' + str(status)) from None
    except linking.LinkingError:
        raise OldServerCheckFailure(stage + '_linking_error') from None
    finally:
        try:
            if session is not None:
                session.close()
        finally:
            http.client.HTTPConnection.request = original_request
            http.client.HTTPConnection.getresponse = original_response

    counts = Counter(str(event['route']) for event in events)
    thumbnail_gets = [
        event for event in events
        if event['route'] == 'app_linking_media'
        and event['kind'] == 'thumbnail'
    ]
    batch_posts = [
        event for event in events
        if event['route'] == 'app_linking_thumbnail_batch'
        and event['method'] == 'POST'
    ]
    require(all(isinstance(event['status'], int) for event in events)
            and all(event['method'] == 'GET' for event in events),
            'transport_events')
    statuses = Counter(str(event['status']) for event in events)
    require(len(thumbnail_gets) == 2 and not batch_posts
            and counts == Counter({
                'app_linking_discovery': 1,
                'app_linking_identity': 1,
                'app_linking_galleries': 1,
                'app_linking_media': 3,
            }) and statuses == Counter({'200': 6}), 'route_counts')
    return {
        'schema': 'issue-362-d-old-server-v1',
        'client_source_commit': os.environ['GITHUB_SHA'],
        'server_source_commit': D_OLD_SERVER_COMMIT,
        'server_version': '1.0.10',
        'assertions': 'passed',
        'batch_capability_advertised': False,
        'current_session_batch_enabled': False,
        'reference_mime_sha256_matches': all(matches),
        'published_images': len(published),
        'legacy_thumbnail_gets': len(thumbnail_gets),
        'batch_posts': len(batch_posts),
        'routes': dict(counts),
        'statuses': dict(statuses),
    }


def main() -> int:
    """Run extraction or the fenced current-client compatibility cohort.

    Returns:
        int: Zero after a complete safe result, otherwise one.
    """
    try:
        repo_root, parent_fixture = validate_parent_fixture()
        mode = sys.argv[1] if len(sys.argv) == 2 else ''
        if mode == 'extract':
            source_root, _version = extract_archived_server(
                repo_root, parent_fixture,
            )
            require(source_root.is_dir(), 'source_root')
            print('PASS gallery workflow archived old server source')
            return 0
        require(mode == 'client', 'mode')
        (issuer, bearer, gallery_id, photo_ids, expected_mimes, expected_hashes, fixture,
         client_commit) = validate_client_environment()
        report_path = Path(os.environ.get(
            'PHP_GALLERY_D_OLD_SERVER_REPORT', '',
        )).resolve()
        require(fixture in report_path.parents, 'report_path')
        cache_root = fixture / 'd-old-server-cache'
        cache_root.mkdir()
        os.environ['XDG_CACHE_HOME'] = str(cache_root)
        os.environ['LOCALAPPDATA'] = str(cache_root)
        report = run_current_client(
            issuer, bearer, gallery_id, photo_ids, expected_mimes,
            expected_hashes,
        )
        require(report['client_source_commit'] == client_commit,
                'client_commit_report')
        report_path.write_text(
            json.dumps(report, sort_keys=True, separators=(',', ':')) + '\n',
            encoding='utf-8',
        )
        print('PASS gallery workflow current client old server')
        return 0
    except OldServerCheckFailure as error:
        print('FAIL gallery workflow old server ' + error.code, file=sys.stderr)
    except Exception as error:
        error_class = type(error).__name__
        if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', error_class) is None:
            error_class = 'Exception'
        print('FAIL gallery workflow old server unexpected ' + error_class,
              file=sys.stderr)
    return 1


if __name__ == '__main__':
    raise SystemExit(main())
