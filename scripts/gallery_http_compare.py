# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: scripts/gallery_http_compare.py
# Module Type: Experimental Measurement Tool
# Purpose: Compare exact local source commits through loopback HTTP only.
# Responsibilities: Guard source switching; retain private response-free timing evidence.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

import argparse
import concurrent.futures
import hashlib
import html.parser
import ipaddress
import json
import math
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.parse
from datetime import datetime, timezone

# Five alternating blocks reduce simple monotonic warm-cache ordering bias.
# Type: int. Units: blocks. Scope: one comparison.
# Consumers: main. Rationale: five blocks retain thirty waves per arm.
BLOCKS = 5
# Six waves per block yield thirty independent scheduling opportunities.
# Type: int. Units: waves. Scope: one arm/block/case.
# Consumers: main. Rationale: six times five gives the requested n30 wave sample.
WAVES = 6
# Admit only the requested bounded concurrency levels.
# Type: tuple[int, ...]. Units: simultaneous requests. Scope: one case wave.
# Consumers: read_cases. Rationale: C1/C2/C4/C8 prevent arbitrary load generation.
CONCURRENCIES = (1, 2, 4, 8)
# Bound an HTTP subprocess including a stalled loopback application.
# Type: int. Units: seconds. Scope: one HTTP response.
# Consumers: request. Rationale: forty-five seconds preserves the historical local probe deadline.
HTTP_SECONDS = 45
# Bound local metadata/ref operations without changing their results.
# Type: int. Units: seconds. Scope: one Git subprocess.
# Consumers: git. Rationale: sixty seconds permits local switching but stops a hung hook.
GIT_SECONDS = 60
# Keep response decoding bounded in the measurement process.
# Type: int. Units: bytes. Scope: one HTML response.
# Consumers: request. Rationale: 32 MiB admits representative gallery markup.
BODY_BYTES = 33554432

# Describe a tail observation without interpolating absent measurements.
# Type: float. Units: quantile fraction. Scope: timing summaries.
# Consumers: distribution. Rationale: 0.95 gives the requested nearest-rank p95.
P95_QUANTILE = 0.95
# Bound case-file admission before creating worker pools.
# Type: int. Units: cases. Scope: one comparison.
# Consumers: read_cases. Rationale: sixteen cases keep this explicitly invoked experiment finite.
MAX_CASES = 16
# Limit timeout overhead for establishing loopback connections.
# Type: int. Units: seconds. Scope: one curl connection.
# Consumers: request. Rationale: three seconds preserves the historical loopback connect deadline.
CONNECT_SECONDS = 3
# Query keys are an allowlist, so credentials cannot hide under arbitrary names.
# Type: frozenset[str]. Units: parameter names. Scope: all configured URLs.
# Consumers: read_cases. Rationale: public gallery navigation needs no token-bearing keys.
QUERY_KEYS = frozenset(('page', 'gallery', 'id', 'slug', 'sort', 'order', 'lang', 'tag', 'offset', 'limit'))


def stamp() -> str:
    """Return a UTC observation timestamp.

    Returns:
        str: ISO-formatted UTC time.
    """
    return datetime.now(timezone.utc).isoformat()


def git(root: pathlib.Path, *arguments: str) -> str:
    """Run one bounded Git operation without a shell.

    Args:
        root (pathlib.Path): Verified repository checkout.
        arguments (str): Explicit Git argument sequence.
    Returns:
        str: Trimmed command output; raises on failure without exposing stderr.
    """
    result = subprocess.run(['git', '-C', str(root), *arguments], capture_output=True, timeout=GIT_SECONDS, check=False)
    if result.returncode:
        raise RuntimeError('Git operation failed: ' + arguments[0])
    return result.stdout.decode('utf-8', 'strict').strip()


def clean(root: pathlib.Path) -> None:
    """Refuse tracked changes and untracked files before source switching.

    Args:
        root (pathlib.Path): Repository checkout to inspect.
    Returns:
        None: Returns only for an entirely clean worktree and index.
    """
    if git(root, 'status', '--porcelain=v1', '--untracked-files=all'):
        raise RuntimeError('Checkout has tracked or untracked changes; refusing source switch.')


def qualified(root: pathlib.Path, value: str) -> str:
    """Resolve only an explicitly supplied full existing commit identity.

    Args:
        root (pathlib.Path): Owning checkout.
        value (str): Full hexadecimal source commit SHA.
    Returns:
        str: Verified exact commit SHA; raises for unknown or abbreviated sources.
    """
    if not re.fullmatch(r'[0-9a-f]{40}', value):
        raise ValueError('Use full lowercase 40-character source SHAs.')
    resolved = git(root, 'rev-parse', '--verify', value + '^{commit}')
    if resolved != value:
        raise ValueError('Source did not resolve to the exact supplied commit.')
    return resolved


def read_cases(path: pathlib.Path) -> list[dict[str, str | int]]:
    """Validate bounded public loopback workloads without credential transport.

    Args:
        path (pathlib.Path): JSON array containing name, URL, concurrency and session fields.
    Returns:
        list[dict[str, str | int]]: Validated name/url/concurrency/session case records.
    """
    cases = json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(cases, list) or not cases or len(cases) > MAX_CASES:
        raise ValueError('Cases must be a nonempty bounded JSON list.')
    names = set()
    for case in cases:
        if not isinstance(case, dict) or set(case) != {'name', 'url', 'concurrency', 'session'}:
            raise ValueError('Each case requires exactly name/url/concurrency/session.')
        if not isinstance(case['name'], str) or not re.fullmatch(r'[A-Za-z0-9_-]+', case['name']) or case['name'] in names:
            raise ValueError('Case names must be unique safe identifiers.')
        names.add(case['name'])
        if type(case['concurrency']) is not int or case['concurrency'] not in CONCURRENCIES or case['session'] not in ('fresh', 'same'):
            raise ValueError('Case concurrency/session is unsupported.')
        if not isinstance(case['url'], str) or any(ord(char) < 33 for char in case['url']):
            raise ValueError('URLs must contain no whitespace/control characters.')
        url = urllib.parse.urlsplit(case['url'])
        if url.scheme not in ('http', 'https') or url.username or url.password or url.fragment:
            raise ValueError('URL credentials, fragments and non-HTTP schemes are refused.')
        try:
            loopback = ipaddress.ip_address(url.hostname or '').is_loopback
        except ValueError:
            loopback = False
        # The exact localhost name is pinned by curl; all other names are refused.
        if not loopback and url.hostname != 'localhost':
            raise ValueError('Only localhost or literal loopback IP URLs are supported.')
        query_keys = set()
        for key, value in urllib.parse.parse_qsl(url.query, keep_blank_values=True, strict_parsing=True):
            if key in query_keys:
                raise ValueError('Duplicate decoded query keys are refused.')
            query_keys.add(key)
            if key == 'page' and value not in ('gallery', 'smart_gallery'):
                raise ValueError('Only public gallery page selectors are supported.')
            if key not in QUERY_KEYS or not re.fullmatch(r'[A-Za-z0-9_./ -]*', value):
                raise ValueError('Query secrets or unsupported public-navigation fields are refused.')
        if url.port is not None and not 0 < url.port <= 65535:
            raise ValueError('Invalid HTTP port.')
    return cases


class Markup(html.parser.HTMLParser):
    """Hash public image/source tags and preserve image-ID order without retaining HTML."""

    def __init__(self) -> None:
        """Initialize independent tag and order digests.

        Returns:
            None: Creates empty streaming hashes and counters.
        """
        super().__init__(convert_charrefs=True)
        self.tags = hashlib.sha256()
        self.raw_tags = hashlib.sha256()
        self.ids = hashlib.sha256()
        self.tag_count = 0
        self.id_count = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        """Hash selected public markup in encounter order.

        Args:
            tag (str): Parsed lowercase tag name.
            attrs (list[tuple[str, str | None]]): Parsed ordered attribute name/value pairs.
        Returns:
            None: Updates raw-tag and parsed-attribute hashes without persisting values.
        """
        if tag in ('img', 'source'):
            self.tags.update(json.dumps([tag, attrs], ensure_ascii=True).encode() + b'\n')
            # The decoder preserves UTF-8 bytes and line endings; no separator
            # is inserted into the concatenated original start-tag byte stream.
            self.raw_tags.update(self.get_starttag_text().encode('utf-8'))
            self.tag_count += 1
        for key, value in attrs:
            if key == 'data-image-id':
                self.ids.update(json.dumps(value).encode() + b'\n')
                self.id_count += 1


# Bound the configured cookie name before constructing a curl header.
# Type: int. Units: ASCII characters. Scope: one explicit session cookie name.
# Consumers: validate_session_cookie_name. Rationale: 128 admits PHP names while
# rejecting unbounded command metadata supplied to this experiment.
SESSION_COOKIE_NAME_MAX_LENGTH = 128
# Bound in-memory session tokens accepted from Set-Cookie responses.
# Type: int. Units: ASCII characters. Scope: one isolated response session token.
# Consumers: response_session. Rationale: 256 admits normal PHP IDs without
# allowing arbitrary response text to become a request header.
SESSION_TOKEN_MAX_LENGTH = 256


def validate_session_cookie_name(name: str | None, required: bool) -> str | None:
    """Validate an explicit PHP session cookie name without supplying a default.

    Args:
        name (str | None): CLI name, or None when omitted.
        required (bool): Whether at least one case requires a real shared session.
    Returns:
        str | None: Validated alphanumeric/underscore name, or None for fresh-only runs.
    Raises:
        ValueError: A required name is absent or the explicit name is unsafe.
    """
    if name is None:
        if required:
            raise ValueError('Same-session cases require --session-cookie-name.')
        return None
    if len(name) > SESSION_COOKIE_NAME_MAX_LENGTH or re.fullmatch(r'[A-Za-z0-9_]+', name) is None:
        raise ValueError('Session cookie name must contain only ASCII alphanumerics or underscore.')
    return name


def response_session(headers: str, name: str, prior: str, initialize: bool) -> str:
    """Require exact session initialization and reject ambiguous or rotated tokens.

    Args:
        headers (str): Private response headers, never persisted to evidence.
        name (str): Exact validated configured cookie name, compared case sensitively.
        prior (str): In-memory token sent for a measured response, or empty for warmup.
        initialize (bool): Whether this cookieless response must establish the session.
    Returns:
        str: Validated private response token or unchanged previously initialized token.
    Raises:
        RuntimeError: Initialization is missing or response tokens are ambiguous,
            malformed or different from the initialized measured session.
    """
    values = []
    for line in headers.splitlines():
        header, separator, value = line.partition(':')
        if not separator or header.lower() != 'set-cookie':
            continue
        pair = value.lstrip().split(';', 1)[0]
        cookie_name, equals, token = pair.partition('=')
        if cookie_name.strip() != name:
            continue
        if cookie_name != name or not equals or len(token) > SESSION_TOKEN_MAX_LENGTH or re.fullmatch(r'[A-Za-z0-9,-]+', token) is None:
            raise RuntimeError('Configured session cookie has a malformed token or name.')
        values.append(token)
    if len(values) > 1:
        raise RuntimeError('Configured session cookie is duplicated or conflicting.')
    if initialize:
        if prior or len(values) != 1:
            raise RuntimeError('Cookieless warmup must initialize exactly the configured session cookie.')
        return values[0]
    if not prior:
        raise RuntimeError('Measured same-session request has no initialized token.')
    if values and values[0] != prior:
        raise RuntimeError('Configured session cookie rotated during collection.')
    return prior


def request(
    curl: str,
    case: dict[str, str | int],
    cookie: str,
    directory: pathlib.Path,
    cookie_name: str | None = None,
    initialize_session: bool = False,
) -> dict[str, str | int | float | bool | None | dict[str, int]]:
    """Measure one response, retaining only timings, safe counts and markup hashes.

    Args:
        curl (str): Resolved curl executable.
        case (dict[str, str | int]): Validated name/url/concurrency/session workload.
        cookie (str): Private server-initialized token, or empty for cookieless warmup/fresh requests.
        directory (pathlib.Path): Owned output tree for temporary response files.
        cookie_name (str | None): Explicit validated cookie name; mandatory for same-session cases.
        initialize_session (bool): Require a cookieless same-session warmup response to initialize the token.
    Returns:
        dict[str, str | int | float | bool | None | dict[str, int]]: Response record containing
            started_at/ended_at (UTC strings), status (HTTP integer),
            ttfb_seconds/total_seconds/connect_seconds (float seconds), bytes
            (download count), header_counts (lowercase name to occurrence count),
            tag_count (img/source start-tag count), tags_sha256 (parsed attribute
            digest), raw_tags_sha256 (concatenated original UTF-8 start-tag byte
            digest), image_id_count (data-image-id count), image_ids_sha256
            (ordered decoded ID digest), session_cookie_name (configured name or
            None for fresh), session_cookie_sent (bool), session_initialized
            (bool), session_consistent (bool for same or None for fresh), and
            private _session (isolated server-issued cookie
            string returned only to the caller in memory and removed before
            serialization). No HTML/header values are retained in the record.
    Raises:
        ValueError: Cookie transport contradicts the requested session mode.
        RuntimeError: HTTP, body admission or session continuity fails.
    """
    same_session = case['session'] == 'same'
    cookie_name = validate_session_cookie_name(cookie_name, same_session)
    if same_session:
        if initialize_session and cookie:
            raise ValueError('Session warmup must send no cookie.')
        if not initialize_session and (not cookie or len(cookie) > SESSION_TOKEN_MAX_LENGTH or re.fullmatch(r'[A-Za-z0-9,-]+', cookie) is None):
            raise ValueError('Same-session request requires an initialized valid token.')
    elif cookie or initialize_session:
        raise ValueError('Fresh requests must never send or initialize cookies.')
    started = stamp()
    with tempfile.TemporaryDirectory(prefix='response-', dir=directory) as temporary:
        body = pathlib.Path(temporary) / 'body'
        headers = pathlib.Path(temporary) / 'headers'
        command = [curl, '--disable', '--silent', '--show-error', '--noproxy', '*', '--proto', '=http,https', '--max-redirs', '0', '--max-time', str(HTTP_SECONDS), '--connect-timeout', str(CONNECT_SECONDS), '--max-filesize', str(BODY_BYTES), '--dump-header', str(headers), '--output', str(body), '--write-out', '%{http_code} %{time_starttransfer} %{time_total} %{time_connect} %{size_download}', '--url', str(case['url'])]
        parsed_url = urllib.parse.urlsplit(str(case['url']))
        if parsed_url.hostname == 'localhost':
            # Default ports are HTTP protocol grammar, not measurement policy.
            port = parsed_url.port or (443 if parsed_url.scheme == 'https' else 80)
            command += ['--resolve', 'localhost:' + str(port) + ':127.0.0.1']
        if cookie:
            command += ['--cookie', str(cookie_name) + '=' + cookie]
        try:
            result = subprocess.run(command, capture_output=True, timeout=HTTP_SECONDS + CONNECT_SECONDS, check=False)
        except (subprocess.SubprocessError, OSError):
            raise RuntimeError('Loopback curl subprocess failed; command details were discarded.') from None
        if result.returncode:
            raise RuntimeError('Loopback curl request failed; response/error text was discarded.')
        status, ttfb, total, connect, size = result.stdout.decode('ascii').split()
        if not 200 <= int(status) < 300:
            raise RuntimeError('Non-success or redirect response refused: ' + status)
        if body.stat().st_size > BODY_BYTES:
            raise RuntimeError('Response body exceeds admission bound.')
        names = {}
        header_text = headers.read_text(encoding='latin-1')
        session = response_session(header_text, str(cookie_name), cookie, initialize_session) if same_session else ''
        for line in header_text.splitlines():
            name, separator, value = line.partition(':')
            if separator and re.fullmatch(r'[A-Za-z0-9-]+', name):
                name = name.lower()
                names[name] = names.get(name, 0) + 1
        markup = Markup()
        markup.feed(body.read_bytes().decode('utf-8', errors='strict'))
        markup.close()
        return {'_session': session, 'session_cookie_name': cookie_name if same_session else None, 'session_cookie_sent': bool(cookie), 'session_initialized': same_session, 'session_consistent': True if same_session else None, 'started_at': started, 'ended_at': stamp(), 'status': int(status), 'ttfb_seconds': float(ttfb), 'total_seconds': float(total), 'connect_seconds': float(connect), 'bytes': int(size), 'header_counts': names, 'tag_count': markup.tag_count, 'tags_sha256': markup.tags.hexdigest(), 'raw_tags_sha256': markup.raw_tags.hexdigest(), 'image_id_count': markup.id_count, 'image_ids_sha256': markup.ids.hexdigest()}


def distribution(values: list[float]) -> dict[str, int | float]:
    """Describe observed samples with exact nearest-rank tail statistics.

    Args:
        values (list[float]): Nonempty timing samples.
    Returns:
        dict[str, int | float]: Sample count, median, nearest-rank p95 and observed maximum.
    """
    ordered = sorted(values)
    count = len(ordered)
    middle = count // 2
    median = ordered[middle] if count % 2 else (ordered[middle - 1] + ordered[middle]) / 2
    return {'n': count, 'median': median, 'p95_nearest_rank': ordered[math.ceil(count * P95_QUANTILE) - 1], 'max': ordered[-1]}


def main() -> None:
    """Collect guarded A/B observations and restore the original clean feature branch.

    Returns:
        None: Writes unique measurement artifacts; raises on unsafe source/state changes.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--baseline', required=True)
    parser.add_argument('--candidate', required=True)
    parser.add_argument('--branch', required=True)
    parser.add_argument('--cases', required=True, type=pathlib.Path)
    parser.add_argument('--output', required=True, type=pathlib.Path)
    parser.add_argument('--session-cookie-name', help='Explicit PHP cookie name required for every same-session run.')
    args = parser.parse_args()
    cases = read_cases(args.cases)
    cookie_name = validate_session_cookie_name(args.session_cookie_name, any(case['session'] == 'same' for case in cases))
    root = pathlib.Path(__file__).resolve().parent.parent
    if pathlib.Path(git(root, 'rev-parse', '--show-toplevel')).resolve() != root:
        raise RuntimeError('Runner must reside in its owning checkout.')
    origin = git(root, 'remote', 'get-url', 'origin')
    if origin not in ('https://github.com/klusik/PHP_gallery.git', 'https://github.com/klusik/PHP_gallery', 'git@github.com:klusik/PHP_gallery.git', 'ssh://git@github.com/klusik/PHP_gallery.git'):
        raise RuntimeError('Unexpected origin repository.')
    if not re.fullmatch(r'feature/[A-Za-z0-9._/-]+', args.branch) or git(root, 'symbolic-ref', '--short', 'HEAD') != args.branch:
        raise RuntimeError('Select the exact existing feature branch before measurement.')
    clean(root)
    baseline = qualified(root, args.baseline)
    candidate = qualified(root, args.candidate)
    if git(root, 'rev-parse', 'HEAD') != candidate or baseline == candidate:
        raise RuntimeError('Candidate must equal initial HEAD and differ from baseline.')
    git(root, 'merge-base', '--is-ancestor', baseline, candidate)
    output = args.output.resolve()
    if output == root or root in output.parents:
        raise RuntimeError('Output must be outside the checkout to preserve clean switching.')
    curl = shutil.which('curl.exe')
    if not curl:
        raise RuntimeError('curl.exe is required.')
    output.mkdir(parents=False, exist_ok=False)
    metadata = {'started_at': stamp(), 'branch': args.branch, 'baseline': baseline, 'candidate': candidate, 'trees': {arm: git(root, 'rev-parse', sha + '^{tree}') for arm, sha in [('baseline', baseline), ('candidate', candidate)]}, 'cases': cases, 'blocks': BLOCKS, 'waves_per_block': WAVES, 'session_cookie_name': cookie_name, 'session_cookie_protocol': 'response-initialized-v1', 'status': 'running'}
    (output / 'metadata.json').write_text(json.dumps(metadata, indent=2), encoding='utf-8')
    rows = []
    waves = []
    signatures = {}
    error = None
    expected_head = candidate
    expected_ref = args.branch
    try:
        with (output / 'requests.jsonl').open('x', encoding='utf-8') as raw:
            for block in range(BLOCKS):
                order = [('baseline', baseline), ('candidate', candidate)]
                if block % 2:
                    order.reverse()
                for arm, sha in order:
                    clean(root)
                    if git(root, 'rev-parse', '--abbrev-ref', 'HEAD') != expected_ref:
                        raise RuntimeError('Unexpected branch checkout; refusing source switch.')
                    if git(root, 'rev-parse', 'HEAD') != expected_head:
                        raise RuntimeError('Unexpected HEAD movement; refusing source switch.')
                    if git(root, 'rev-parse', 'refs/heads/' + args.branch) != candidate:
                        raise RuntimeError('Feature branch moved during comparison; refusing switch.')
                    git(root, 'switch', '--detach', sha)
                    expected_head = sha
                    expected_ref = 'HEAD'
                    clean(root)
                    if git(root, 'rev-parse', 'HEAD') != sha:
                        raise RuntimeError('Source checkout did not match requested arm.')
                    print('Starting block ' + str(block + 1) + '/' + str(BLOCKS) + ' arm ' + arm, flush=True)
                    for case in cases:
                        print('Warming case ' + str(case['name']) + ' arm ' + arm, flush=True)
                        cookie = ''
                        warmup = request(curl, case, cookie, output, cookie_name, case['session'] == 'same')
                        # Adopt only the exact configured server-issued cookie in RAM.
                        cookie = str(warmup.pop('_session'))
                        for wave in range(WAVES):
                            wave_started = time.perf_counter()
                            with concurrent.futures.ThreadPoolExecutor(max_workers=int(case['concurrency'])) as pool:
                                futures = {pool.submit(request, curl, case, cookie, output, cookie_name): slot for slot in range(int(case['concurrency']))}
                                for future in concurrent.futures.as_completed(futures):
                                    row = future.result()
                                    response_session = str(row.pop('_session'))
                                    if cookie and response_session != cookie:
                                        raise RuntimeError('Synthetic session rotated during concurrent collection.')
                                    row.update({'arm': arm, 'source': sha, 'block': block, 'wave': wave, 'slot': futures[future], 'case': str(case['name']), 'concurrency': int(case['concurrency']), 'session': str(case['session']), 'arm_order': [item[0] for item in order]})
                                    key = str(case['name'])
                                    signature = (row['raw_tags_sha256'], row['tags_sha256'], row['image_ids_sha256'], row['tag_count'], row['image_id_count'])
                                    signatures.setdefault(key, set()).add(signature)
                                    raw.write(json.dumps(row, sort_keys=True) + '\n')
                                    raw.flush()
                                    rows.append(row)
                            waves.append({'arm': arm, 'case': case['name'], 'block': block, 'wave': wave, 'total_seconds': time.perf_counter() - wave_started})
                            print('Completed ' + arm + ' case ' + str(case['name']) + ' wave ' + str(wave + 1) + '/' + str(WAVES), flush=True)
                    clean(root)
                    if git(root, 'rev-parse', 'HEAD') != sha:
                        raise RuntimeError('Source HEAD changed during HTTP collection.')
                    print('Completed arm ' + arm + ' block ' + str(block + 1), flush=True)
                print('Completed block ' + str(block + 1) + '/' + str(BLOCKS), flush=True)
        summary = {'cases': {}, 'wave_statistics': {}, 'markup_consistent_across_all_responses': {name: len(values) == 1 for name, values in signatures.items()}, 'tail_caveat': 'For n30 wave samples nearest-rank p99 equals the observed maximum; it is not a stable tail estimate.'}
        for case in cases:
            for arm in ('baseline', 'candidate'):
                key = str(case['name']) + ':' + arm
                selected = [row for row in rows if row['case'] == case['name'] and row['arm'] == arm]
                summary['cases'][key] = {metric: distribution([float(row[metric]) for row in selected]) for metric in ('ttfb_seconds', 'total_seconds', 'connect_seconds')}
                summary['wave_statistics'][key] = distribution([float(wave['total_seconds']) for wave in waves if wave['case'] == case['name'] and wave['arm'] == arm])
        (output / 'summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
        metadata['status'] = 'collected'
    except BaseException as failure:
        error = failure
        metadata['status'] = 'failed'
        metadata['failure_type'] = type(failure).__name__
    finally:
        try:
            clean(root)
            if git(root, 'rev-parse', '--abbrev-ref', 'HEAD') != expected_ref:
                raise RuntimeError('Unexpected branch checkout; automatic restoration refused.')
            if git(root, 'rev-parse', 'HEAD') != expected_head:
                raise RuntimeError('Unexpected HEAD movement; automatic restoration refused.')
            if git(root, 'rev-parse', 'refs/heads/' + args.branch) != candidate:
                raise RuntimeError('Original branch moved; automatic restoration refused.')
            git(root, 'switch', args.branch)
            clean(root)
            if git(root, 'rev-parse', 'HEAD') != candidate:
                raise RuntimeError('Restored source does not equal original candidate.')
            metadata['restored'] = True
        except BaseException as failure:
            metadata['restored'] = False
            metadata['restore_failure_type'] = type(failure).__name__
            error = error or failure
        metadata['ended_at'] = stamp()
        (output / 'metadata.json').write_text(json.dumps(metadata, indent=2), encoding='utf-8')
    if error:
        raise RuntimeError('Comparison failed; inspect metadata and preserve any unknown checkout edits.') from error


if __name__ == '__main__':
    main()
