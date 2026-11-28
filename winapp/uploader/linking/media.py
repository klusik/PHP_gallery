# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/media.py
# Module Type: Windows Client Transport
# Purpose: Read and validate native gallery media responses.
# Responsibilities:
#   - Keep bounded image, thumbnail batch and JSON media readers in their protocol owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Read and validate native gallery media responses."""

from typing import Callable
from ..thumbnail_batch_protocol import INPUT_SLICE_BYTES
from ..models import JsonValue
from ..thumbnail_batch_protocol import MAX_IMAGE_ID
from ..gallery_media_cache import PHOTO_CACHE_FRESH_SECONDS
from ..thumbnail_batch_protocol import PRODUCTION_MAX_RESPONSE_BYTES
from ..thumbnail_batch_protocol import ProtocolError
from ..thumbnail_batch_protocol import ThumbnailBatchParser
from ..thumbnail_batch_protocol import ValidatedItem
from ..server_discovery import _reject_nonfinite
from ..server_discovery import _unique_object
import hashlib
import http.client
import json
from ..passive_server_timing import parse_server_timing
import re
import time
from urllib.parse import urlsplit
from .core import (
    LINKING_USER_AGENT,
    LinkingError,
    LinkingGrantRejected,
    LinkingMediaThrottled,
    LinkingMediaUnavailable,
    LinkingResponseError,
    LinkingRevisionUnsupported,
    LinkingThumbnailBatchUnsupported,
    MEDIA_RETRY_AFTER_MAX_SECONDS,
    MEDIA_STALE_GET_RETRIES,
    MediaReadTimings,
    NATIVE_MEDIA_CATALOGUE_BYTES,
    NATIVE_MEDIA_JSON_BYTES,
    NATIVE_MEDIA_MAX_BYTES,
    NATIVE_MEDIA_PAGE_SIZE,
    _NATIVE_AUDIO_TYPES,
    _NATIVE_IMAGE_TYPES,
    _NativeMediaResponse,
    _issuer_valid,
    validated_retry_after,
)
from . import transport
from .transport import (
    _LinkingTransportControl,
    _MediaConnectionPool,
    _response_content_kind,
)
from .models import (
    _native_media_policy_facts,
    _private_media_cache_control_matches,
)



def _native_media_read_once(
    issuer: str, query: str, bearer: str, accepted: tuple[str, ...],
    control: _LinkingTransportControl, if_none_match: str | None = None,
    *, allow_restricted: bool = False, admin_preload: bool = False,
    pool: _MediaConnectionPool | None = None,
    timings: MediaReadTimings | None = None,
    cover_only: bool = False,
) -> _NativeMediaResponse:
    """Read one current media projection from the exact scoped installation.

    Args:
        issuer (str): Independently verified canonical installation origin and mount.
        query (str): Encoded fixed media route and validated resource selectors.
        bearer (str): Current opaque token sent only in the authorization header.
        accepted (tuple[str, ...]): Exact expected JSON or binary media types.
        control (_LinkingTransportControl): Absolute worker deadline and socket owner.
        if_none_match (str | None): Previously verified strong photo ETag for
            one conditional photo or thumbnail revalidation.
        allow_restricted (bool): Permit the explicit no-store Admin photo lane.
        admin_preload (bool): Request the versioned Admin private-cache lane.
        pool (_MediaConnectionPool | None): Session's sequential idle socket,
            or None for the original one-request behavior.
        timings (MediaReadTimings | None): Caller-owned aggregate phase metrics.
        cover_only (bool): Request only the first authorized cover descriptor.
            Older servers retain their bounded legacy page; cache admission is
            still decided by their current metadata and response policy.

    Returns:
        _NativeMediaResponse: Body or authorized not-modified marker, media type,
            strong validator, and whether the server opted into disk caching.

    Raises:
        LinkingError: A redirect, unsafe header, length or digest was received.
        OSError: The network or TLS connection failed.
        http.client.HTTPException: HTTP framing or body was incomplete.
    """
    parsed = urlsplit(issuer)
    control.timings = timings
    if timings is not None:
        timings.server_timing = None
    if pool is not None:
        connection, reused, pool_epoch = pool.borrow(parsed, control)
    else:
        connection = transport._linking_connection(parsed, control)
        reused, pool_epoch = False, 0
    reusable = False
    response: http.client.HTTPResponse | None = None
    body_started = 0.0

    def read_encoded(limit: int) -> bytes:
        """Count consumed encoded bodies even when policy later rejects them.

        Args:
            limit (int): Maximum response bytes requested by the existing parser.
        Returns:
            bytes: Encoded bytes from the owned HTTP response.
        """
        assert response is not None
        control.set_transport_step('body')
        try:
            payload = response.read(limit)
        except http.client.IncompleteRead as error:
            if timings is not None:
                timings.encoded_bytes += len(error.partial)
            raise
        if timings is not None:
            timings.encoded_bytes += len(payload)
        return payload

    try:
        control.remaining()
        headers = {
            'Accept': ', '.join(accepted),
            'Authorization': 'Bearer ' + bearer,
            'User-Agent': LINKING_USER_AGENT,
        }
        photo_request = any(mime in _NATIVE_IMAGE_TYPES for mime in accepted)
        track_request = any(mime in _NATIVE_AUDIO_TYPES for mime in accepted)
        catalogue_request = '&kind=photos' in query
        cache_header = None
        cache_policy = ''
        if photo_request:
            cache_header = 'X-PHP-Gallery-Photo-Cache'
            cache_policy = (
                f'private, max-age={PHOTO_CACHE_FRESH_SECONDS}, must-revalidate'
            )
        elif track_request:
            cache_header = 'X-PHP-Gallery-Track-Cache'
            cache_policy = 'private, no-cache'
        if cache_header is not None:
            headers[cache_header] = '1'
        if catalogue_request:
            headers['X-PHP-Gallery-Photo-Page'] = (
                'cover-v1' if cover_only else str(NATIVE_MEDIA_PAGE_SIZE)
            )
        if admin_preload:
            headers['X-PHP-Gallery-Preload'] = 'admin-v1'
        if if_none_match is not None:
            if not photo_request or re.fullmatch(r'"[a-f0-9]{64}"', if_none_match) is None:
                raise LinkingError('Invalid gallery photo validator.')
            headers['If-None-Match'] = if_none_match
        for attempt in range(MEDIA_STALE_GET_RETRIES + 1):
            control.remaining()
            try:
                # Resolve/connect/TLS before measuring the request/header
                # phase; connection setup therefore has separate observations.
                if connection.sock is None:
                    connection.connect()
                started = time.perf_counter() if timings is not None else 0.0
                if timings is not None:
                    timings.http_requests += 1
                try:
                    control.set_transport_step('request')
                    connection.request(
                        'GET', parsed.path + '/index.php?' + query,
                        headers=headers,
                    )
                    control.set_transport_step('headers')
                    response = connection.getresponse()
                finally:
                    if timings is not None:
                        timings.ttfb_ms += (time.perf_counter() - started) * 1000.0
                break
            except (
                http.client.RemoteDisconnected, BrokenPipeError,
                ConnectionResetError, ConnectionAbortedError,
            ):
                # Only a borrowed stale socket may be retried, and only before
                # a response exists. No response/body/auth failure is replayed.
                if (not reused or pool is None
                        or attempt >= MEDIA_STALE_GET_RETRIES):
                    raise
                connection.close()
                control.finish()
                control.remaining()
                connection, reused, pool_epoch = pool.borrow(parsed, control)
        if response is None:
            raise LinkingError('Gallery media response is unavailable.')
        body_started = time.perf_counter() if timings is not None else 0.0
        with response:
            types = response.headers.get_all('Content-Type', [])
            media_type = types[0].split(';', 1)[0].strip().lower() if len(types) == 1 else ''
            content_kind = _response_content_kind(types)
            cache_controls = response.headers.get_all('Cache-Control', [])
            vary_values = response.headers.get_all('Vary', [])
            vary_tokens = {
                part.strip().lower()
                for value in vary_values for part in value.split(',')
            }
            cacheable = bool(
                cache_header is not None
                and response.headers.get_all(cache_header, []) == ['1']
                and _private_media_cache_control_matches(
                    cache_controls,
                    photo_request=photo_request,
                    track_request=track_request,
                )
                and '*' not in vary_tokens
                and {'authorization', cache_header.lower()}.issubset(vary_tokens)
                and (not admin_preload or (
                    response.headers.get_all('X-PHP-Gallery-Preload') == ['admin-v1']
                    and 'x-php-gallery-preload' in vary_tokens
                ))
            )
            directives = {part.strip().lower()
                          for value in cache_controls
                          for part in value.split(',')}
            if response.headers.get('Content-Encoding') is not None:
                policy_facts = _native_media_policy_facts(
                    response.headers, cache_header, cache_policy, admin_preload,
                )
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'response_policy',
                    media_policy_facts=policy_facts,
                )
            if response.status == 401:
                if content_kind != 'json':
                    raise LinkingResponseError(
                        'media', 'app_linking_media', response.status,
                        content_kind, 'http_status',
                    )
                payload = read_encoded(NATIVE_MEDIA_JSON_BYTES + 1)
                if (len(types) == 1 and media_type == 'application/json'
                        and cache_controls == ['no-store']
                        and len(payload) <= NATIVE_MEDIA_JSON_BYTES
                        and _native_media_json(payload) == {'error': 'invalid_token'}):
                    raise LinkingGrantRejected('The linking grant was rejected.')
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'http_status',
                )
            if response.status == 304:
                etags = response.headers.get_all('ETag', [])
                if (if_none_match is None or len(etags) != 1
                        or etags[0] != if_none_match
                        or not (cacheable or (
                            not admin_preload and 'no-store' in directives
                        ))):
                    policy_facts = _native_media_policy_facts(
                        response.headers, cache_header, cache_policy,
                        admin_preload,
                    )
                    raise LinkingResponseError(
                        'media', 'app_linking_media', response.status,
                        content_kind, 'response_policy',
                        media_policy_facts=policy_facts,
                    )
                control.remaining()
                # HTTP 304 has no body. Finalize its HTTPResponse state before
                # allowing the connection's next sequential request.
                if read_encoded(1):
                    raise LinkingError('Unexpected not-modified response body.')
                reusable = bool(
                    response.version == 11 and not response.will_close
                    and response.isclosed()
                    and not response.headers.get_all('Transfer-Encoding', [])
                    and response.headers.get_all('Content-Length', [])
                    in ([], ['0'])
                )
                if timings is not None:
                    timings.server_timing = parse_server_timing(
                        response.headers.get_all('Server-Timing', []),
                    )
                return None, '', etags[0], cacheable
            if response.status == 400 and '&kind=revision' in query:
                payload = read_encoded(NATIVE_MEDIA_JSON_BYTES + 1)
                if (len(types) == 1 and media_type == 'application/json'
                        and cache_controls == ['no-store']
                        and len(payload) <= NATIVE_MEDIA_JSON_BYTES
                        and _native_media_json(payload) == {'error': 'invalid_request'}):
                    raise LinkingRevisionUnsupported('Gallery revision is unsupported.')
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'http_status',
                )
            if response.status == 404 and catalogue_request:
                payload = read_encoded(NATIVE_MEDIA_JSON_BYTES + 1)
                if (len(types) == 1 and media_type == 'application/json'
                        and cache_controls == ['no-store']
                        and len(payload) <= NATIVE_MEDIA_JSON_BYTES):
                    try:
                        error_data = _native_media_json(payload)
                    except LinkingError:
                        error_data = {}
                    if error_data == {'error': 'target_unavailable'}:
                        raise LinkingMediaUnavailable(
                            'Photos are unavailable for this gallery.',
                        )
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'http_status',
                )
            if response.status == 429:
                payload = read_encoded(NATIVE_MEDIA_JSON_BYTES + 1)
                if (len(types) == 1 and media_type == 'application/json'
                        and cache_controls == ['no-store']
                        and len(payload) <= NATIVE_MEDIA_JSON_BYTES):
                    try:
                        error_data = _native_media_json(payload)
                    except LinkingError:
                        error_data = {}
                    if error_data == {'error': 'temporarily_unavailable'}:
                        retry_values = response.headers.get_all('Retry-After', [])
                        retry_after = None
                        if (len(retry_values) == 1
                                and re.fullmatch(r'[1-9][0-9]{0,2}', retry_values[0])
                                is not None):
                            parsed_retry = int(retry_values[0])
                            if parsed_retry <= MEDIA_RETRY_AFTER_MAX_SECONDS:
                                retry_after = parsed_retry
                        raise LinkingMediaThrottled(retry_after)
                raise LinkingError('Gallery media response was not accepted.')
            if (response.status != 200 or media_type not in accepted
                    or not (cacheable or 'no-store' in directives)):
                reason = (
                    'http_status' if response.status != 200
                    else 'content_type' if media_type not in accepted
                    else 'response_policy'
                )
                policy_facts = (
                    _native_media_policy_facts(
                        response.headers, cache_header, cache_policy,
                        admin_preload,
                    )
                    if reason == 'response_policy' else None
                )
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, reason,
                    media_policy_facts=policy_facts,
                )
            if admin_preload and not cacheable and 'no-store' not in directives:
                policy_facts = _native_media_policy_facts(
                    response.headers, cache_header, cache_policy, admin_preload,
                )
                raise LinkingResponseError(
                    'media', 'app_linking_media', response.status,
                    content_kind, 'response_policy',
                    media_policy_facts=policy_facts,
                )
            if media_type == 'application/json':
                max_bytes = (
                    NATIVE_MEDIA_CATALOGUE_BYTES if catalogue_request else NATIVE_MEDIA_JSON_BYTES
                )
                payload = read_encoded(max_bytes + 1)
                if len(payload) > max_bytes:
                    raise LinkingError('Gallery media metadata is too large.')
                etag, cacheable = None, False
            else:
                lengths = response.headers.get_all('Content-Length', [])
                etags = response.headers.get_all('ETag', [])
                restricted = (
                    allow_restricted and photo_request and cache_controls == ['no-store']
                    and response.headers.get_all('X-PHP-Gallery-Media-Restricted', []) == ['1']
                    and not response.headers.get_all('X-PHP-Gallery-Photo-Cache', [])
                    and not etags
                )
                has_validator = (
                    len(etags) == 1 and re.fullmatch(r'"[a-f0-9]{64}"', etags[0]) is not None
                )
                valid_length = (
                    len(lengths) == 1
                    and re.fullmatch(r'[1-9][0-9]{0,7}', lengths[0]) is not None
                    and int(lengths[0]) <= NATIVE_MEDIA_MAX_BYTES
                )
                invalid_framing = (
                    response.headers.get('Transfer-Encoding') is not None
                )
                if (not valid_length or not (restricted or has_validator)
                        or invalid_framing):
                    binary_check = (
                        'length' if not valid_length else
                        'framing' if invalid_framing else 'validator'
                    )
                    raise LinkingResponseError(
                        'media', 'app_linking_media', response.status,
                        content_kind, 'response_policy',
                        media_policy_facts=_native_media_policy_facts(
                            response.headers, cache_header, cache_policy,
                            admin_preload, binary_check=binary_check,
                        ),
                    )
                length = int(lengths[0])
                payload = read_encoded(length)
                if (len(payload) != length
                        or (has_validator
                            and hashlib.sha256(payload).hexdigest() != etags[0][1:-1])):
                    raise LinkingResponseError(
                        'media', 'app_linking_media', response.status,
                        content_kind, 'response_policy',
                        media_policy_facts=_native_media_policy_facts(
                            response.headers, cache_header, cache_policy,
                            admin_preload,
                            binary_check=(
                                'body_length' if len(payload) != length
                                else 'digest'
                            ),
                        ),
                    )
                etag = etags[0] if has_validator else None
            if timings is not None:
                mime_counter = {
                    'image/jpeg': 'jpeg_responses', 'image/png': 'png_responses',
                    'image/gif': 'gif_responses', 'image/webp': 'webp_responses',
                }.get(media_type)
                if mime_counter is not None:
                    setattr(timings, mime_counter, getattr(timings, mime_counter) + 1)
            control.remaining()
            reusable = bool(
                response.version == 11 and not response.will_close
                and response.isclosed()
                and not response.headers.get_all('Transfer-Encoding', [])
                and response.headers.get_all('Content-Length', [])
                == [str(len(payload))]
            )
            if timings is not None:
                timings.server_timing = parse_server_timing(
                    response.headers.get_all('Server-Timing', []),
                )
            return payload, media_type, etag, cacheable
    finally:
        if timings is not None and response is not None:
            timings.body_ms += (time.perf_counter() - body_started) * 1000.0
        retained = bool(
            reusable and pool is not None
            and pool.release(parsed, connection, control, pool_epoch)
        )
        if not retained:
            connection.close()


# Purpose: Bound one batch error response before strict JSON parsing.
# Type: int. Units: bytes. Scope: one thumbnail batch HTTP error.
# Consumers: _native_thumbnail_batch_read_once. Rationale: 4096 bytes allows
# fixed server error objects without buffering arbitrary intermediary content.
THUMBNAIL_BATCH_ERROR_BYTES = 4096

# Purpose: Identify the exact v1 framed response media type.
# Type: str. Units: MIME media type. Scope: thumbnail batch responses.
# Consumers: _native_thumbnail_batch_read_once. Rationale: a different format
# must never be interpreted as the reviewed binary framing.
THUMBNAIL_BATCH_MIME = 'application/vnd.php-gallery.thumbnail-batch.v1'


def _native_thumbnail_batch_read_once(
    issuer: str, bearer: str, requested_ids: tuple[str, ...], gallery_id: int,
    control: _LinkingTransportControl, publish: Callable[[ValidatedItem], None],
    *, admin_preload: bool = False, timings: MediaReadTimings | None = None,
) -> None:
    """Stream one authenticated batch through the strict production parser.

    Args:
        issuer (str): Previously verified exact installation issuer and mount.
        bearer (str): Current scoped bearer, never retained in diagnostics.
        requested_ids (tuple[str, ...]): Ordered unique canonical photo IDs.
        gallery_id (int): Positive physical gallery ID in signed 64-bit range.
        control (_LinkingTransportControl): Deadline and interruptible socket owner.
        publish (Callable[[ValidatedItem], None]): Worker-safe receiver for each
            completely framed and digest-verified item; it must recheck authority.
        admin_preload (bool): Request the existing Admin preload policy lane.
        timings (MediaReadTimings | None): Caller-owned network observations.

    Returns:
        None: The footer, exact response length and EOF were validated.

    Raises:
        LinkingError: Invalid input, cancellation, refusal or unsafe response.
        OSError: An underlying transport operation failed.
        http.client.HTTPException: HTTP framing or response transfer failed.
    """
    if (not _issuer_valid(issuer, allow_local_http=True)
            or type(gallery_id) is not int or not 0 < gallery_id <= MAX_IMAGE_ID
            or type(requested_ids) is not tuple or type(admin_preload) is not bool):
        raise LinkingError('Invalid thumbnail batch request.')
    try:
        parser = ThumbnailBatchParser(requested_ids)
    except ProtocolError:
        raise LinkingError('Invalid thumbnail batch request.') from None
    payload = json.dumps(
        {'v': 1, 'gallery_id': str(gallery_id), 'image_ids': requested_ids},
        separators=(',', ':'), ensure_ascii=True,
    ).encode('ascii')
    parsed = urlsplit(issuer)
    control.timings = timings
    if timings is not None:
        timings.server_timing = None
    connection = transport._linking_connection(parsed, control)
    started = time.perf_counter()
    body_started = 0.0
    publication_seconds = 0.0
    response: http.client.HTTPResponse | None = None
    try:
        control.remaining()
        headers = {
            'Accept': THUMBNAIL_BATCH_MIME,
            'Authorization': 'Bearer ' + bearer,
            'Content-Type': 'application/json',
            'User-Agent': LINKING_USER_AGENT,
        }
        if admin_preload:
            headers['X-PHP-Gallery-Preload'] = 'admin-v1'
        connection.connect()
        control.remaining()
        header_started = time.perf_counter()
        control.request_started = True
        control.set_transport_step('request')
        if timings is not None:
            timings.network_reads += 1
            timings.http_requests += 1
        try:
            connection.request(
                'POST', parsed.path + '/index.php?page=app_linking_thumbnail_batch',
                body=payload, headers=headers,
            )
            control.set_transport_step('headers')
            response = connection.getresponse()
        finally:
            if timings is not None:
                timings.ttfb_ms += (time.perf_counter() - header_started) * 1000.0
        control.remaining()
        body_started = time.perf_counter()
        types = response.headers.get_all('Content-Type', [])
        kind = _response_content_kind(types)
        if response.status in (401, 403):
            raise LinkingGrantRejected('The linking grant was rejected.')
        if response.status == 429:
            raise LinkingMediaThrottled(validated_retry_after(
                response.headers.get_all('Retry-After', []),
            ), route='app_linking_thumbnail_batch')
        if response.status == 404:
            control.set_transport_step('body')
            error_body = response.read(THUMBNAIL_BATCH_ERROR_BYTES + 1)
            if timings is not None:
                timings.encoded_bytes += len(error_body)
            control.remaining()
            if (len(types) == 1 and re.fullmatch(
                    r'application/json\s*(?:;\s*charset\s*=\s*(?:utf-8|"utf-8")\s*)?',
                    types[0], flags=re.IGNORECASE,
                ) is not None
                    and response.headers.get_all('Cache-Control', []) == ['no-store']
                    and len(error_body) <= THUMBNAIL_BATCH_ERROR_BYTES):
                try:
                    error_data = _native_media_json(error_body)
                except LinkingError:
                    error_data = {}
                if error_data == {'error': 'unsupported_capability'}:
                    raise LinkingThumbnailBatchUnsupported()
                if error_data == {'error': 'target_unavailable'}:
                    raise LinkingMediaUnavailable('Photos are unavailable.')
        if response.status != 200:
            raise LinkingResponseError(
                'media', 'app_linking_thumbnail_batch', response.status,
                kind, 'http_status',
            )
        if types != [THUMBNAIL_BATCH_MIME]:
            raise LinkingResponseError(
                'media', 'app_linking_thumbnail_batch', 200, kind, 'content_type',
            )
        directives = {part.strip().lower() for value in
                      response.headers.get_all('Cache-Control', [])
                      for part in value.split(',')}
        vary = {part.strip().lower() for value in
                response.headers.get_all('Vary', []) for part in value.split(',')}
        encodings = response.headers.get_all('Content-Encoding', [])
        lengths = response.headers.get_all('Content-Length', [])
        transfer = response.headers.get_all('Transfer-Encoding', [])
        if (not {'private', 'no-store'}.issubset(directives)
                or 'authorization' not in vary or '*' in vary
                or (admin_preload and 'x-php-gallery-preload' not in vary)
                or encodings not in ([], ['identity'])
                or len(lengths) > 1 or len(transfer) > 1
                or (lengths and transfer)
                or (transfer and transfer[0].lower().strip() != 'chunked')
                or (lengths and re.fullmatch(r'[0-9]+', lengths[0]) is None)):
            raise LinkingResponseError(
                'media', 'app_linking_thumbnail_batch', 200, kind, 'response_policy',
            )
        # Reject an excessive decimal before int conversion, including headers
        # too long for Python's integer conversion safety limit.
        length_text = lengths[0].lstrip('0') or '0' if lengths else None
        limit_text = str(PRODUCTION_MAX_RESPONSE_BYTES)
        if (length_text is not None and (
                len(length_text) > len(limit_text)
                or (len(length_text) == len(limit_text)
                    and length_text > limit_text))):
            raise LinkingResponseError(
                'media', 'app_linking_thumbnail_batch', 200, kind, 'response_size',
            )
        declared = int(length_text) if length_text is not None else None
        seen = 0
        try:
            while True:
                control.remaining()
                control.set_transport_step('body')
                chunk = response.read1(INPUT_SLICE_BYTES)
                control.remaining()
                if not chunk:
                    break
                seen += len(chunk)
                if timings is not None:
                    timings.encoded_bytes += len(chunk)
                if seen > PRODUCTION_MAX_RESPONSE_BYTES:
                    raise LinkingResponseError(
                        'media', 'app_linking_thumbnail_batch', 200, kind,
                        'response_size',
                    )
                for item in parser.feed(chunk):
                    control.remaining()
                    publish_started = time.perf_counter()
                    try:
                        publish(item)
                    finally:
                        publication_seconds += time.perf_counter() - publish_started
            if declared is not None and seen != declared:
                raise ProtocolError('response_length_mismatch')
            parser.finish()
            if timings is not None:
                timings.server_timing = parse_server_timing(
                    response.headers.get_all('Server-Timing', []),
                )
        except ProtocolError:
            raise LinkingResponseError(
                'media', 'app_linking_thumbnail_batch', 200, kind, 'invalid_shape',
            ) from None
    finally:
        if timings is not None:
            timings.network_ms += max(
                0.0, time.perf_counter() - started - publication_seconds,
            ) * 1000.0
            if body_started:
                timings.body_ms += max(
                    0.0, time.perf_counter() - body_started - publication_seconds,
                ) * 1000.0
        # Cleanup is best-effort so close errors cannot replace the primary
        # auth/protocol failure. This dedicated connection is never pooled;
        # every owner still attempts response, connection and control cleanup.
        if response is not None:
            try:
                response.close()
            except Exception:  # noqa: BLE001
                pass
        try:
            connection.close()
        except Exception:  # noqa: BLE001
            pass
        try:
            control.finish()
        except Exception:  # noqa: BLE001
            pass


def _native_media_json(payload: bytes) -> dict[str, JsonValue]:
    """Decode strict, already bounded media metadata for typed validation.

    Args:
        payload (bytes): Complete native JSON body in UTF-8.

    Returns:
        dict[str, JsonValue]: Photo page or presentation fields before validation.

    Raises:
        LinkingError: The response is not one strict JSON object.
    """
    try:
        data = json.loads(payload.decode('utf-8'), object_pairs_hook=_unique_object,
                          parse_constant=_reject_nonfinite)
    except (UnicodeError, ValueError, RecursionError):
        raise LinkingError('Gallery media metadata was not accepted.') from None
    if not isinstance(data, dict):
        raise LinkingError('Gallery media metadata was not accepted.')
    return data
