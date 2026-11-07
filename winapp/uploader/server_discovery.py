# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/server_discovery.py
# Module Type: Windows Client Transport
# Purpose: Read the public app-linking discovery document without credentials.
# Responsibilities:
#   - Restrict requests to an explicit HTTPS installation and its mount.
#   - Bound socket inactivity and response bytes and refuse redirects.
#   - Distinguish unavailable linking, absent discovery and unsafe responses.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Unauthenticated, explicit server discovery for the Windows companion."""

import http.client
import json
import re
from email.message import Message
from typing import IO, Literal, NoReturn

from urllib.error import HTTPError, URLError
from urllib.parse import parse_qsl, unquote_to_bytes, urlsplit, urlunsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

from .models import JsonValue

# Purpose: Bound the length of an explicit installation address.
# Type: int. Units: characters. Scope: one server discovery request.
# Consumers: discovery_url.
# Rationale: 4096 characters covers normal installation paths without unbounded input.
DISCOVERY_URL_MAX_CHARS = 4096

# Purpose: Bound the server discovery HTTP socket inactivity timeout.
# Type: int. Units: seconds. Scope: one network operation.
# Consumers: discover_server.
# Rationale: 8 seconds allows normal shared hosting while bounding stalled I/O.
DISCOVERY_TIMEOUT_SECONDS = 8

# Purpose: Bound memory used by the public discovery document.
# Type: int. Units: bytes. Scope: one discovery response body.
# Consumers: discover_server.
# Rationale: 8192 bytes is ample for the initial contract and rejects excess input.
DISCOVERY_MAX_BYTES = 8192

# Purpose: Identify the supported public discovery representation.
# Type: int. Units: protocol version. Scope: native discovery validation.
# Consumers: discover_server.
# Rationale: Version 1 is the only contract implemented by this client.
DISCOVERY_CONTRACT_VERSION = 1

DiscoveryState = Literal['unavailable', 'unsupported', 'reported_available', 'error']


class _NoRedirect(HTTPRedirectHandler):
    """Refuse all redirects before any second request can leave the client."""

    def redirect_request(
        self, req: Request, fp: IO[bytes], code: int, msg: str,
        headers: Message, newurl: str,
    ) -> None:
        """Prevent the redirect handler from forwarding a discovery request.

        Args:
            req (Request): Original request supplied by urllib.
            fp (IO[bytes]): Original response stream supplied by urllib.
            code (int): HTTP redirect status.
            msg (str): HTTP reason phrase.
            headers (Message): Original response headers.
            newurl (str): Redirect target supplied by the server.

        Returns:
            None: Instructs urllib not to follow the redirect.
        """
        return None


def discovery_url(value: str, *, allow_local_http: bool = False) -> str:
    """Derive the discovery route from an explicit gallery address.

    Args:
        value (str): Gallery root or canonical upload endpoint, with any mount.
        allow_local_http (bool): Permit literal localhost HTTP syntax for a
            caller whose transport confines connections to loopback.

    Returns:
        str: Discovery URL, or an empty string for an unsafe address.
    """
    raw = value.strip()
    if (not raw or len(raw) > DISCOVERY_URL_MAX_CHARS
            or re.search(r'[\x00-\x20\x7f\\]', raw)):
        return ''
    try:
        parsed = urlsplit(raw)
        port = parsed.port
        local_http = (allow_local_http and parsed.scheme == 'http'
                      and parsed.hostname == 'localhost')
        if ((parsed.scheme != 'https' and not local_http) or not parsed.hostname
                or parsed.username is not None or parsed.password is not None
                or parsed.fragment or port == 0):
            return ''
        parsed.netloc.encode('ascii')
    except (ValueError, UnicodeError):
        return ''
    path = parsed.path.rstrip('/')
    if '//' in path or re.search(r'%(?![0-9a-fA-F]{2})', path):
        return ''
    for segment in path.split('/'):
        decoded = unquote_to_bytes(segment)
        if (decoded in (b'.', b'..') or any(
                byte in decoded for byte in (b'/', b'\\', b'%', b'\x00'))
                or any(byte < 32 or byte == 127 for byte in decoded)):
            return ''
    query = parse_qsl(parsed.query, keep_blank_values=True)
    if path.endswith('/api/upload'):
        if parsed.query:
            return ''
        mount = path[:-len('/api/upload')]
    elif path.endswith('/index.php'):
        if parsed.query and query != [('page', 'upload_automation_upload')]:
            return ''
        mount = path[:-len('/index.php')]
    else:
        if parsed.query:
            return ''
        mount = path
    return urlunsplit((
        parsed.scheme, parsed.netloc, mount + '/index.php',
        'page=app_linking_discovery', '',
    ))


def _unique_object(
    pairs: list[tuple[str, JsonValue]],
) -> dict[str, JsonValue]:
    """Reject duplicate JSON keys before interpreting the protocol.

    Args:
        pairs (list[tuple[str, JsonValue]]): Object members in received order;
            values are JSON scalars, lists or nested decoded objects.

    Returns:
        dict[str, JsonValue]: Unique member names and their decoded JSON values.

    Raises:
        ValueError: The object repeats a member name.
    """
    result: dict[str, JsonValue] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate discovery JSON member.')
        result[key] = value
    return result


def _reject_nonfinite(value: str) -> NoReturn:
    """Refuse JavaScript nonfinite constants that are not JSON values.

    Args:
        value (str): Parser-supplied nonfinite numeric spelling.

    Returns:
        NoReturn: Always raises instead of producing a JSON value.

    Raises:
        ValueError: The server sent NaN or infinity.
    """
    raise ValueError('Nonfinite discovery JSON number: ' + value)


def discover_server(value: str) -> DiscoveryState:
    """Read one public discovery document without any upload credentials.

    Args:
        value (str): Explicit HTTPS installation root or upload endpoint.

    Returns:
        DiscoveryState: Unavailable, absent, reported available, or error;
            reported availability does not mean this app implements linking.
    """
    url = discovery_url(value)
    if not url:
        return 'error'
    request = Request(url, headers={
        'Accept': 'application/json',
        'User-Agent': 'PHPGalleryUploader-discovery',
    }, method='GET')
    try:
        with build_opener(_NoRedirect()).open(
            request, timeout=DISCOVERY_TIMEOUT_SECONDS,
        ) as response:
            if response.status != 200 or response.geturl() != url:
                return 'error'
            content_type = response.headers.get('Content-Type', '')
            if content_type.split(';', 1)[0].strip().lower() != 'application/json':
                return 'error'
            # Read one extra byte solely to distinguish the exact size boundary.
            payload = response.read(DISCOVERY_MAX_BYTES + 1)
            if len(payload) > DISCOVERY_MAX_BYTES:
                return 'error'
            data = json.loads(
                payload.decode('utf-8'), object_pairs_hook=_unique_object,
                parse_constant=_reject_nonfinite,
            )
            if (not isinstance(data, dict)
                    or type(data.get('contract_version')) is not int
                    or data['contract_version'] != DISCOVERY_CONTRACT_VERSION
                    or not isinstance(data.get('app_linking'), dict)
                    or type(data['app_linking'].get('available')) is not bool):
                return 'error'
            return ('reported_available'
                    if data['app_linking']['available'] else 'unavailable')
    except HTTPError as exc:
        try:
            if exc.code in (404, 405) and exc.geturl() == url:
                return 'unsupported'
            return 'error'
        finally:
            exc.close()
    except (URLError, OSError, ValueError, UnicodeError, RecursionError,
            http.client.HTTPException):
        return 'error'
