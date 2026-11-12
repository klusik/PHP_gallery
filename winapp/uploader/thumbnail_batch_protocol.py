# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/thumbnail_batch_protocol.py
# Module Type: Incremental Thumbnail Batch Protocol Codec
# Purpose: Parse experimental B1 bodies and strict production PGB1 batches.
# Responsibilities:
#   - Bound all wire input and in-flight payload retention.
#   - Enforce strict v1 identities, lengths, MIME, status and SHA-256.
#   - Publish only structurally complete and integrity-validated items.
#   - Keep production admission narrower than the isolated benchmark profile.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Incremental PGB1 parsers shared by benchmarks and the WinApp uploader.

The framed and multipart parsers preserve the B1 experimental contracts.
``ThumbnailBatchParser`` applies the narrower production PGB1 profile.
"""

import hashlib
import json
import re
import struct
from dataclasses import dataclass
from typing import Iterator


# Purpose: Cap one protocol response including metadata and framing.
# Type: int. Units: bytes. Scope: one B1.1 parser.
# Consumers: _BaseParser.feed. Rationale: 16 MiB is experimental, not a
# statement about a hosting provider's effective response size.
MAX_RESPONSE_BYTES = 16 * 1024 * 1024

# Purpose: Cap one complete thumbnail pending integrity verification.
# Type: int. Units: bytes. Scope: one B1.1 item.
# Consumers: _BaseParser._begin_item. Rationale: 4 MiB bounds retention.
MAX_IMAGE_BYTES = 4 * 1024 * 1024

# Purpose: Limit per-response work and identity tracking.
# Type: int. Units: items. Scope: one B1.1 parser.
# Consumers: both parsers. Rationale: 32 matches the largest fixture.
MAX_ITEMS = 32

# Purpose: Limit UTF-8 JSON associated with a single image.
# Type: int. Units: bytes. Scope: one v1 item.
# Consumers: both parsers. Rationale: 2048 is enough for fixed fields.
MAX_META_BYTES = 2048

# Purpose: Bound multipart header parsing before image allocation.
# Type: int. Units: bytes. Scope: one MIME part.
# Consumers: MultipartParser._step. Rationale: 4096 exceeds fixed
# headers while rejecting indefinite header lines.
MAX_PART_HEADER_BYTES = 4096

# Purpose: Process even whole-body input incrementally.
# Type: int. Units: bytes. Scope: one feed() iteration.
# Consumers: _BaseParser.feed. Rationale: 8192 keeps internal input
# buffering independent of how an upstream HTTP library chunks data.
INPUT_SLICE_BYTES = 8192

# Purpose: Identify the only supported v1 envelope.
# Type: int. Units: wire version. Scope: framing and metadata.
# Consumers: both parsers. Rationale: fail closed on unknown encodings.
WIRE_VERSION = 1

# Purpose: Use a fixed boundary only for reproducible isolated benchmarks.
# Type: str. Units: ASCII token. Scope: synthetic B1.1 MIME response.
# Consumers: MultipartParser and test harness. Rationale: matches PHP
# producer; a real server must choose a unique per-response delimiter.
BENCHMARK_BOUNDARY = "pgb1_362_benchmark_v1_boundary_2026"

# Purpose: Permit only the explicitly specified per-item outcomes.
# Type: frozenset[str]. Units: status tokens. Scope: B1.1 v1.
# Consumers: _BaseParser._begin_item. Rationale: unknown statuses
# must not be coerced to successful or cache-reusable content.
ITEM_STATUSES = frozenset(
    ("ok", "not_modified", "unavailable", "oversize")
)

# Purpose: Restrict validated new binary thumbnails to supported types.
# Type: frozenset[str]. Units: MIME tokens. Scope: B1.1 v1.
# Consumers: _BaseParser._begin_item. Rationale: other image and
# generic application formats require an explicit future version.
IMAGE_MIMES = frozenset(("image/jpeg", "image/webp"))

# Purpose: Require exactly one unambiguous set of v1 metadata fields.
# Type: frozenset[str]. Units: JSON keys. Scope: B1.1 v1.
# Consumers: _BaseParser._begin_item. Rationale: no alias or
# contradictory length/status fields.
META_KEYS = frozenset(
    ("v", "image_id", "request_index", "status",
     "mime", "length", "sha256", "etag")
)

# Purpose: Match canonical positive database image IDs.
# Type: Pattern[str]. Units: decimal characters. Scope: B1.1 metadata.
# Consumers: _BaseParser._begin_item. Rationale: identical canonical
# numeric selectors to the existing private native-photo route.
IMAGE_ID_PATTERN = re.compile(r"[1-9][0-9]{0,18}")

# Purpose: Accept a strong quoted lowercase SHA-256 cache validator.
# Type: Pattern[str]. Units: ASCII characters. Scope: B1.1 metadata.
# Consumers: _BaseParser._begin_item. Rationale: not_modified must
# never be mistaken for an unverifiable cache hint.
ETAG_PATTERN = re.compile(r'"[a-f0-9]{64}"')

# Purpose: Validate only the safe MIME boundary-token subset we generate.
# Type: Pattern[str]. Units: ASCII characters. Scope: B1.1 MIME.
# Consumers: MultipartParser.__init__. Rationale: prevent CRLF and
# ambiguous delimiter injection; valid RFC 2046 tokens can be broader.
BOUNDARY_PATTERN = re.compile(r"[A-Za-z0-9_'()+,./:=?-]{1,70}")

# Purpose: Describe fixed binary response-header fields.
# Type: struct.Struct. Units: bytes. Scope: framed v1.
# Consumers: FramedParser._step. Rationale: magic/version/count are
# 4-byte ASCII, unsigned 8-bit version and unsigned 16-bit count.
FRAMED_HEADER = struct.Struct(">4sBH")

# Purpose: Describe fixed per-frame length fields after tag I.
# Type: struct.Struct. Units: bytes. Scope: framed v1.
# Consumers: FramedParser._step. Rationale: uint16 JSON length,
# uint32 binary length, both network byte order.
FRAME_LENGTHS = struct.Struct(">HI")

# Purpose: Refuse numeric IDs outside signed PHP 64-bit range.
# Type: int. Units: image ID value. Scope: B1.1 canonical selector.
# Consumers: _BaseParser._begin_item. Rationale: avoid platform overflow.
MAX_IMAGE_ID = 9223372036854775807

# Purpose: Cap one production PGB1 response including every frame and footer.
# Type: int. Units: bytes. Scope: one production thumbnail batch.
# Consumers: ThumbnailBatchParser.feed. Rationale: 8 MiB bounds response
# retention independently of the larger experimental B1 parser ceiling.
PRODUCTION_MAX_RESPONSE_BYTES = 8 * 1024 * 1024

# Purpose: Bound production batch count independently of benchmark parsers.
# Type: int. Units: items. Scope: one production request/response.
# Consumers: ThumbnailBatchParser.__init__ and _step.
# Rationale: 32 is the reviewed v1 hard count even if B1 limits later change.
PRODUCTION_MAX_ITEMS = 32

# Purpose: Bound a production thumbnail before hashing or retaining its bytes.
# Type: int. Units: bytes. Scope: one successful production item.
# Consumers: ThumbnailBatchParser._begin_item.
# Rationale: 4 MiB limits per-item work and in-flight integrity buffers.
PRODUCTION_MAX_IMAGE_BYTES = 4 * 1024 * 1024

# Purpose: Bound production JSON metadata before decoding.
# Type: int. Units: bytes. Scope: one production item frame.
# Consumers: ThumbnailBatchParser._begin_item.
# Rationale: fixed v1 fields fit within 2048 bytes without free-form content.
PRODUCTION_MAX_METADATA_BYTES = 2048

# Purpose: Permit only item outcomes defined for production PGB1 v1.
# Type: frozenset[str]. Units: status tokens. Scope: one production item.
# Consumers: ThumbnailBatchParser._begin_item. Rationale: conditional
# not_modified is reserved until current cache proof is established.
PRODUCTION_ITEM_STATUSES = frozenset(("ok", "unavailable", "oversize"))

# Purpose: Permit only image formats implemented by the production batch client.
# Type: frozenset[str]. Units: MIME tokens. Scope: successful production items.
# Consumers: ThumbnailBatchParser._begin_item. Rationale: PNG/GIF support
# requires a separately qualified protocol capability.
PRODUCTION_IMAGE_MIMES = frozenset(("image/jpeg", "image/webp"))


class ProtocolError(ValueError):
    """Reject malformed, oversized or incomplete thumbnail responses."""


@dataclass(frozen=True, slots=True)
class ValidatedItem:
    """One complete, integrity-verified item or explicit non-binary status."""

    image_id: str
    request_index: int
    status: str
    mime: str
    data: bytes
    sha256: str | None
    etag: str | None


def _unique_json_pairs(pairs: list[tuple[str, object]]) -> dict[str, object]:
    """Build JSON metadata while rejecting any repeated field name.

    Args:
        pairs (list[tuple[str, object]]): Ordered decoded JSON object fields.

    Returns:
        dict[str, object]: Unique JSON key/value mapping.

    Raises:
        ProtocolError: A JSON field is repeated.
    """
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise ProtocolError("duplicate_metadata_key")
        result[key] = value
    return result


class _BaseParser:
    """Share strict metadata, byte accounting and per-item validation."""

    def __init__(self, expected_count: int | None) -> None:
        """Initialize bounded parser state without accepting any bytes.

        Args:
            expected_count (int | None): Known request count, or None for framed headers.

        Returns:
            None: Parser state is initialized with bounded empty buffers.

        Raises:
            ProtocolError: An invalid request size is supplied.
        """
        if expected_count is not None and (
            type(expected_count) is not int
            or not 1 <= expected_count <= MAX_ITEMS
        ):
            raise ProtocolError("invalid_batch_count")
        self.expected_count = expected_count
        self.state = "header"
        self.buffer = bytearray()
        self.total_bytes = 0
        self.items_seen = 0
        self.seen_ids: set[str] = set()
        self.current: dict[str, object] | None = None
        self.payload = bytearray()
        self.max_image_bytes = MAX_IMAGE_BYTES
        self.max_metadata_bytes = MAX_META_BYTES
        self.max_response_bytes = MAX_RESPONSE_BYTES
        self.max_items = MAX_ITEMS

    def feed(self, chunk: bytes) -> Iterator[ValidatedItem]:
        """Consume arbitrary chunks and yield only complete validated items.

        Args:
            chunk (bytes): Newly received bytes, including a possibly buffered body.

        Returns:
            Iterator[ValidatedItem]: One verified image or explicit status at a time.

        Raises:
            ProtocolError: A limit, byte contract or integrity check fails.
        """
        if not isinstance(chunk, bytes):
            raise ProtocolError("invalid_chunk_type")
        if self.state in ("done", "failed") and chunk:
            raise ProtocolError("bytes_after_termination")
        if len(chunk) > self.max_response_bytes - self.total_bytes:
            self.state = "failed"
            raise ProtocolError("response_oversize")
        self.total_bytes += len(chunk)
        try:
            for start in range(0, len(chunk), INPUT_SLICE_BYTES):
                self.buffer.extend(chunk[start:start + INPUT_SLICE_BYTES])
                while True:
                    result = self._step()
                    if result is None:
                        break
                    if isinstance(result, ValidatedItem):
                        yield result
        except (ProtocolError, UnicodeDecodeError, ValueError) as error:
            self.state = "failed"
            if isinstance(error, ProtocolError):
                raise
            raise ProtocolError("malformed_metadata") from error

    def finish(self) -> None:
        """Require an explicit complete end marker and no trailing bytes.

        Returns:
            None: The response is complete only with a valid final marker and EOF.

        Raises:
            ProtocolError: The response ended in an incomplete state.
        """
        if self.state != "done" or self.buffer:
            raise ProtocolError("truncated_or_unterminated")

    def _take(self, count: int) -> bytes | None:
        """Take exactly a fixed number of available wire bytes.

        Args:
            count (int): Number of bytes required before advancing state.

        Returns:
            bytes | None: Requested bytes, or None while waiting for more input.
        """
        if len(self.buffer) < count:
            return None
        data = bytes(self.buffer[:count])
        del self.buffer[:count]
        return data

    def _begin_item(self, encoded: bytes, declared_length: int) -> None:
        """Validate a strict JSON envelope before retaining image data.

        Args:
            encoded (bytes): Length-bounded UTF-8 JSON object.
            declared_length (int): Binary payload size advertised by transport.

        Returns:
            None: The bounded metadata is retained for its matching payload.

        Raises:
            ProtocolError: The envelope or item limits are invalid.
        """
        if not 1 <= len(encoded) <= self.max_metadata_bytes:
            raise ProtocolError("invalid_metadata_length")
        try:
            metadata = json.loads(
                encoded.decode("utf-8"),
                object_pairs_hook=_unique_json_pairs,
            )
        except (ValueError, UnicodeDecodeError) as error:
            raise ProtocolError("invalid_metadata_json") from error
        if not isinstance(metadata, dict) or (
            frozenset(metadata) != META_KEYS
        ):
            raise ProtocolError("invalid_metadata_fields")
        image_id = metadata["image_id"]
        index = metadata["request_index"]
        status = metadata["status"]
        mime = metadata["mime"]
        length = metadata["length"]
        digest = metadata["sha256"]
        etag = metadata["etag"]
        if type(metadata["v"]) is not int or (
            metadata["v"] != WIRE_VERSION
        ):
            raise ProtocolError("unknown_item_version")
        if not isinstance(image_id, str) or not IMAGE_ID_PATTERN.fullmatch(
            image_id
        ) or int(image_id) > MAX_IMAGE_ID:
            raise ProtocolError("invalid_image_id")
        if image_id in self.seen_ids:
            raise ProtocolError("duplicate_image_id")
        if type(index) is not int or index != self.items_seen:
            raise ProtocolError("invalid_request_index")
        if self.expected_count is None or index >= self.expected_count:
            raise ProtocolError("too_many_items")
        if not isinstance(status, str) or status not in ITEM_STATUSES:
            raise ProtocolError("unknown_status")
        if type(length) is not int or length != declared_length or (
            length < 0 or length > self.max_image_bytes
        ):
            raise ProtocolError("invalid_data_length")
        if not isinstance(mime, str):
            raise ProtocolError("invalid_mime")
        if etag is not None and (
            not isinstance(etag, str)
            or ETAG_PATTERN.fullmatch(etag) is None
        ):
            raise ProtocolError("invalid_etag")
        if status == "ok":
            if mime not in IMAGE_MIMES or length < 1:
                raise ProtocolError("invalid_image_payload")
            if not isinstance(digest, str) or (
                re.fullmatch(r"[a-f0-9]{64}", digest) is None
            ):
                raise ProtocolError("invalid_integrity_validator")
        elif length != 0 or digest is not None or (
            mime != "application/octet-stream"
        ):
            raise ProtocolError("invalid_status_payload")
        elif status == "not_modified" and etag is None:
            raise ProtocolError("missing_cached_validator")
        elif status != "not_modified" and etag is not None:
            raise ProtocolError("unexpected_cached_validator")
        self.current = metadata
        self.payload.clear()

    def _append_payload(self) -> bool:
        """Accumulate at most one item without delaying complete chunks.

        Returns:
            bool: True when bytes advanced, False if more input is needed.
        """
        if self.current is None:
            raise ProtocolError("missing_current_item")
        remaining = int(self.current["length"]) - len(self.payload)
        if remaining == 0:
            return True
        count = min(remaining, len(self.buffer))
        if count == 0:
            return False
        self.payload.extend(self.buffer[:count])
        del self.buffer[:count]
        return True

    def _payload_complete(self) -> bool:
        """Report whether the current item's declared bytes are complete.

        Returns:
            bool: True if the exact advertised payload length is present.
        """
        return self.current is not None and (
            len(self.payload) == self.current["length"]
        )

    def _end_item(self) -> ValidatedItem:
        """Verify integrity and publish only the completed current item.

        Returns:
            ValidatedItem: One byte-perfect image or explicit non-binary status.

        Raises:
            ProtocolError: A digest does not match actual image bytes.
        """
        if self.current is None or not self._payload_complete():
            raise ProtocolError("incomplete_item")
        metadata = self.current
        data = bytes(self.payload)
        if metadata["status"] == "ok" and (
            hashlib.sha256(data).hexdigest() != metadata["sha256"]
        ):
            raise ProtocolError("image_digest_mismatch")
        result = ValidatedItem(
            image_id=str(metadata["image_id"]),
            request_index=int(metadata["request_index"]),
            status=str(metadata["status"]),
            mime=str(metadata["mime"]),
            data=data,
            sha256=metadata["sha256"],
            etag=metadata["etag"],
        )
        self.seen_ids.add(result.image_id)
        self.items_seen += 1
        self.current = None
        self.payload.clear()
        return result

    def _step(self) -> bool | ValidatedItem | None:
        """Advance a protocol-specific finite-state machine by one step.

        Returns:
            bool | ValidatedItem | None: True after progress, a validated item, or None when waiting.
        """
        raise NotImplementedError


class FramedParser(_BaseParser):
    """Parse the experimental B1 framed profile for benchmarks only.

    Production WinApp callers must use ``ThumbnailBatchParser`` so the
    stricter byte cap, requested identities and production statuses apply.
    """

    def __init__(self) -> None:
        """Start at the fixed seven-byte framed header.

        Returns:
            None: The parser starts in the framed-header state.
        """
        super().__init__(expected_count=None)
        self.metadata_length = 0
        self.data_length = 0

    def _step(self) -> bool | ValidatedItem | None:
        """Advance the framed wire grammar without whole-response buffering.

        Returns:
            bool | ValidatedItem | None: True after progress, a verified item, or None when incomplete.

        Raises:
            ProtocolError: A version, frame or terminator is invalid.
        """
        if self.state == "header":
            header = self._take(FRAMED_HEADER.size)
            if header is None:
                return None
            magic, version, count = FRAMED_HEADER.unpack(header)
            if magic != b"PGB1" or version != WIRE_VERSION:
                raise ProtocolError("unknown_framed_header")
            if not 1 <= count <= self.max_items:
                raise ProtocolError("invalid_batch_count")
            self.expected_count = count
            self.state = "frame_tag"
            return True
        if self.state == "frame_tag":
            tag = self._take(1)
            if tag is None:
                return None
            if tag == b"I" and self.items_seen < self.expected_count:
                self.state = "frame_lengths"
            elif tag == b"E" and self.items_seen == self.expected_count:
                self.state = "end_count"
            else:
                raise ProtocolError("invalid_frame_tag")
            return True
        if self.state == "frame_lengths":
            encoded = self._take(FRAME_LENGTHS.size)
            if encoded is None:
                return None
            self.metadata_length, self.data_length = FRAME_LENGTHS.unpack(
                encoded
            )
            if not 1 <= self.metadata_length <= self.max_metadata_bytes or (
                self.data_length > self.max_image_bytes
            ):
                raise ProtocolError("invalid_frame_lengths")
            self.state = "frame_metadata"
            return True
        if self.state == "frame_metadata":
            encoded = self._take(self.metadata_length)
            if encoded is None:
                return None
            self._begin_item(encoded, self.data_length)
            self.state = "frame_payload"
            return True
        if self.state == "frame_payload":
            if not self._payload_complete():
                if not self._append_payload():
                    return None
            if self._payload_complete():
                result = self._end_item()
                self.state = "frame_tag"
                return result
            return True
        if self.state == "end_count":
            footer = self._take(2)
            if footer is None:
                return None
            if struct.unpack(">H", footer)[0] != self.items_seen:
                raise ProtocolError("wrong_final_count")
            self.state = "done"
            return True
        if self.state == "done" and not self.buffer:
            return None
        raise ProtocolError("unexpected_framed_data")


class MultipartParser(_BaseParser):
    """Parse the experimental MIME alternative for benchmarks only.

    Production WinApp transport negotiates only the framed parser profile.
    """

    def __init__(
        self, expected_count: int, boundary: str = BENCHMARK_BOUNDARY
    ) -> None:
        """Initialize a MIME parser with the advertised response boundary.

        Args:
            expected_count (int): Count of requested images, bounded to 32.
            boundary (str): Validated MIME boundary from the response Content-Type.

        Returns:
            None: The parser starts at the first MIME boundary.

        Raises:
            ProtocolError: The advertised boundary is unsafe or malformed.
        """
        super().__init__(expected_count)
        if not BOUNDARY_PATTERN.fullmatch(boundary):
            raise ProtocolError("invalid_mime_boundary")
        self.boundary = b"--" + boundary.encode("ascii")
        self.state = "boundary"

    def _step(self) -> bool | ValidatedItem | None:
        """Advance boundary, canonical headers, payload and CRLF tail.

        Returns:
            bool | ValidatedItem | None: True after progress, a verified item, or None when incomplete.

        Raises:
            ProtocolError: A MIME boundary, header or part is invalid.
        """
        if self.state == "boundary":
            minimum = len(self.boundary) + 2
            if len(self.buffer) < minimum:
                return None
            if not self.buffer.startswith(self.boundary):
                raise ProtocolError("invalid_mime_delimiter")
            terminator = bytes(self.buffer[len(self.boundary):minimum])
            if terminator == b"\r\n":
                if self.items_seen >= self.expected_count:
                    raise ProtocolError("too_many_mime_parts")
                del self.buffer[:minimum]
                self.state = "part_headers"
                return True
            if terminator == b"--":
                if len(self.buffer) < minimum + 2:
                    return None
                if bytes(self.buffer[minimum:minimum + 2]) != b"\r\n":
                    raise ProtocolError("invalid_mime_closing_delimiter")
                if self.items_seen != self.expected_count:
                    raise ProtocolError("missing_mime_parts")
                del self.buffer[:minimum + 2]
                self.state = "done"
                return True
            raise ProtocolError("invalid_mime_delimiter_tail")
        if self.state == "part_headers":
            end = self.buffer.find(b"\r\n\r\n")
            if end < 0:
                if len(self.buffer) > MAX_PART_HEADER_BYTES:
                    raise ProtocolError("mime_headers_oversize")
                return None
            if end + 4 > MAX_PART_HEADER_BYTES:
                raise ProtocolError("mime_headers_oversize")
            header = self._take(end + 4)
            if header is None:
                return None
            lines = header[:-4].split(b"\r\n")
            if len(lines) != 3 or not (
                lines[0].startswith(b"Content-Type: ")
                and lines[1].startswith(b"Content-Length: ")
                and lines[2].startswith(b"X-PG-Meta: ")
            ):
                raise ProtocolError("invalid_mime_headers")
            mime = lines[0][len(b"Content-Type: "):]
            size = lines[1][len(b"Content-Length: "):]
            meta = lines[2][len(b"X-PG-Meta: "):]
            if not size.isdigit() or (
                len(size) > 1 and size.startswith(b"0")
            ):
                raise ProtocolError("invalid_mime_content_length")
            length = int(size)
            if length > self.max_image_bytes or len(meta) > self.max_metadata_bytes:
                raise ProtocolError("invalid_mime_part_length")
            self._begin_item(meta, length)
            if self.current is None or (
                mime.decode("ascii", errors="replace") !=
                self.current["mime"]
            ):
                raise ProtocolError("mime_metadata_mismatch")
            self.state = "part_payload"
            return True
        if self.state == "part_payload":
            if not self._payload_complete():
                if not self._append_payload():
                    return None
            if self._payload_complete():
                self.state = "part_tail"
            return True
        if self.state == "part_tail":
            tail = self._take(2)
            if tail is None:
                return None
            if tail != b"\r\n":
                raise ProtocolError("invalid_mime_part_terminator")
            result = self._end_item()
            self.state = "boundary"
            return result
        if self.state == "done" and not self.buffer:
            return None
        raise ProtocolError("unexpected_multipart_data")


class ThumbnailBatchParser(FramedParser):
    """Validate ordered production PGB1 items against their requested IDs."""

    def __init__(self, requested_ids: tuple[str, ...] | list[str]) -> None:
        """Create a strict parser for one nonempty ordered image request.

        Args:
            requested_ids (tuple[str, ...] | list[str]): Canonical ordered
                positive decimal image IDs requested by the caller.

        Returns:
            None: The parser is ready to consume framed response bytes.

        Raises:
            ProtocolError: The request is empty, too large, duplicated or
                contains a noncanonical or out-of-range identifier.
        """
        if type(requested_ids) not in (tuple, list):
            raise ProtocolError("invalid_requested_ids")
        normalized_ids = tuple(requested_ids)
        if not 1 <= len(normalized_ids) <= PRODUCTION_MAX_ITEMS:
            raise ProtocolError("invalid_requested_count")
        for image_id in normalized_ids:
            if type(image_id) is not str or (
                IMAGE_ID_PATTERN.fullmatch(image_id) is None
                or int(image_id) > MAX_IMAGE_ID
            ):
                raise ProtocolError("invalid_requested_image_id")
        if len(set(normalized_ids)) != len(normalized_ids):
            raise ProtocolError("duplicate_requested_image_id")
        super().__init__()
        self.max_image_bytes = PRODUCTION_MAX_IMAGE_BYTES
        self.max_metadata_bytes = PRODUCTION_MAX_METADATA_BYTES
        self.max_response_bytes = PRODUCTION_MAX_RESPONSE_BYTES
        self.max_items = PRODUCTION_MAX_ITEMS
        self.requested_ids = normalized_ids
        self.strict_total_bytes = 0
        self.strict_failed = False
        self.strict_finished = False

    @property
    def completed(self) -> bool:
        """Report true only after finish confirms the complete envelope.

        Returns:
            bool: Whether the final count, terminator and EOF were validated.
        """
        return self.strict_finished and not self.strict_failed

    def feed(self, chunk: bytes) -> Iterator[ValidatedItem]:
        """Consume bounded arbitrary fragments under the production byte cap.

        Args:
            chunk (bytes): Newly received response bytes.

        Returns:
            Iterator[ValidatedItem]: Complete SHA-256-verified items only.

        Raises:
            ProtocolError: The input, production profile or response envelope
                is invalid. Any such failure permanently poisons this parser.
        """
        try:
            if self.strict_failed or self.strict_finished:
                raise ProtocolError("parser_not_accepting_input")
            if type(chunk) is not bytes:
                raise ProtocolError("invalid_chunk_type")
            if len(chunk) > (
                PRODUCTION_MAX_RESPONSE_BYTES - self.strict_total_bytes
            ):
                raise ProtocolError("production_response_oversize")
            self.strict_total_bytes += len(chunk)
            yield from super().feed(chunk)
        except Exception as error:
            self.strict_failed = True
            self.strict_finished = False
            self.state = "failed"
            if isinstance(error, ProtocolError):
                raise
            raise ProtocolError("invalid_production_response") from error

    def finish(self) -> None:
        """Require the explicit final marker, matching count and exact EOF.

        Returns:
            None: The parser is marked complete after all terminal checks pass.

        Raises:
            ProtocolError: The response is truncated, malformed or already
                poisoned. A failure permanently poisons this parser.
        """
        if self.strict_failed or self.strict_finished:
            self.strict_failed = True
            self.strict_finished = False
            self.state = "failed"
            raise ProtocolError("parser_not_finishable")
        try:
            super().finish()
            if self.items_seen != len(self.requested_ids):
                raise ProtocolError("wrong_production_item_count")
            self.strict_finished = True
        except Exception as error:
            self.strict_failed = True
            self.strict_finished = False
            self.state = "failed"
            if isinstance(error, ProtocolError):
                raise
            raise ProtocolError("invalid_production_termination") from error

    def _begin_item(self, encoded: bytes, declared_length: int) -> None:
        """Apply the production identity, status and payload profile.

        Args:
            encoded (bytes): Length-bounded UTF-8 item metadata.
            declared_length (int): Binary payload size advertised by framing.

        Returns:
            None: Metadata is admitted before any item payload is retained.

        Raises:
            ProtocolError: Identity, order, MIME, status or size differs from
                the production contract.
        """
        if len(encoded) > PRODUCTION_MAX_METADATA_BYTES:
            raise ProtocolError("production_metadata_oversize")
        if declared_length > PRODUCTION_MAX_IMAGE_BYTES:
            raise ProtocolError("production_image_oversize")
        super()._begin_item(encoded, declared_length)
        metadata = self.current
        if metadata is None:
            raise ProtocolError("missing_current_item")
        index = metadata["request_index"]
        if type(index) is not int or not 0 <= index < len(self.requested_ids):
            raise ProtocolError("invalid_production_request_index")
        if metadata["image_id"] != self.requested_ids[index]:
            raise ProtocolError("production_image_order_mismatch")
        if metadata["status"] not in PRODUCTION_ITEM_STATUSES:
            raise ProtocolError("unsupported_production_status")
        if metadata["status"] == "ok" and (
            metadata["mime"] not in PRODUCTION_IMAGE_MIMES
        ):
            raise ProtocolError("unsupported_production_mime")

    def _end_item(self) -> ValidatedItem:
        """Verify a returned ETag is bound to the exact image digest.

        Returns:
            ValidatedItem: Complete item with exact request identity and hash.

        Raises:
            ProtocolError: An ETag does not equal the quoted payload SHA-256.
        """
        result = super()._end_item()
        if result.etag is not None and result.etag != (
            '"' + str(result.sha256) + '"'
        ):
            raise ProtocolError("etag_digest_mismatch")
        return result

    def _step(self) -> bool | ValidatedItem | None:
        """Reject response counts that differ from the request before items.

        Returns:
            bool | ValidatedItem | None: Parent framed parser progress/result.

        Raises:
            ProtocolError: The advertised response count is not exact.
        """
        expected_before = self.expected_count
        result = super()._step()
        if expected_before is None and self.expected_count is not None and (
            self.expected_count != len(self.requested_ids)
            or self.expected_count > PRODUCTION_MAX_ITEMS
        ):
            raise ProtocolError("production_count_mismatch")
        return result
