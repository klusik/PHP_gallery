# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_thumbnail_batch_protocol.py
# Module Type: Production Thumbnail Batch Protocol Regression Suite
# Purpose: Verify strict PGB1 response parsing against independent wire fixtures.
# Responsibilities:
#   - Check production limits, request identity, integrity and terminal framing.
#   - Prove incremental publication and permanent parser poisoning on failure.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Regress strict PGB1 parsing independently of production transport code."""

import hashlib
import json
import struct
import sys
import unittest
from pathlib import Path
from typing import cast

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.thumbnail_batch_protocol import (
    MAX_IMAGE_ID,
    PRODUCTION_MAX_IMAGE_BYTES,
    PRODUCTION_MAX_ITEMS,
    PRODUCTION_MAX_METADATA_BYTES,
    PRODUCTION_MAX_RESPONSE_BYTES,
    ProtocolError,
    ThumbnailBatchParser,
    ValidatedItem,
)


# Purpose: Encode the exact v1 response header and frame fields in fixtures.
# Type: struct.Struct. Units: bytes. Scope: independent production fixture.
# Consumers: encode_response. Rationale: these network-order layouts are the
# public PGB1 grammar exercised without calling production parser helpers.
WIRE_HEADER = struct.Struct(">4sBH")
WIRE_LENGTHS = struct.Struct(">HI")

# Purpose: Bound the smallest legal arbitrary fragmentation in regressions.
# Type: int. Units: bytes. Scope: one test feed fragment.
# Consumers: test_one_byte_fragmentation_and_utf8_payload.
# Rationale: one byte proves parser independence from transport chunking.
ONE_BYTE_FRAGMENT = 1

# Purpose: Exercise each supported production count boundary.
# Type: tuple[int, ...]. Units: requested image IDs. Scope: regression inputs.
# Consumers: test_supported_batch_counts_preserve_order.
# Rationale: these representative counts cover initial, default, expanded
# and absolute batch group sizes without relying on implementation defaults.
PRODUCTION_BATCH_COUNTS = (1, 8, 16, 24, PRODUCTION_MAX_ITEMS)

# Purpose: Exceed the production response budget by exactly one byte.
# Type: int. Units: bytes. Scope: strict response cap negative control.
# Consumers: test_production_total_cap_poisoning.
# Rationale: the first prohibited byte must fail before any parser recovery.
ONE_BYTE_OVER_RESPONSE_LIMIT = PRODUCTION_MAX_RESPONSE_BYTES + ONE_BYTE_FRAGMENT

# Purpose: Encode the largest legal item plus one byte for rejection.
# Type: int. Units: bytes. Scope: production per-image boundary fixture.
# Consumers: test_image_size_boundary.
# Rationale: strict v1 admits exactly the named production image cap.
ONE_BYTE_OVER_IMAGE_LIMIT = PRODUCTION_MAX_IMAGE_BYTES + ONE_BYTE_FRAGMENT

# Purpose: Reject metadata one byte above the inherited framed-v1 ceiling.
# Type: int. Units: bytes. Scope: one production item envelope.
# Consumers: test_metadata_duplicates_version_length_and_truncation.
# Rationale: fixed metadata must remain bounded before JSON decoding.
ONE_BYTE_OVER_METADATA_LIMIT = (
    PRODUCTION_MAX_METADATA_BYTES + ONE_BYTE_FRAGMENT
)


Metadata = dict[str, int | str | None]
WireItem = tuple[Metadata, bytes]


def make_item(
    image_id: str,
    request_index: int,
    status: str = "ok",
    data: bytes | None = None,
    mime: str = "image/jpeg",
    sha256: str | None = None,
    etag: str | None = None,
) -> WireItem:
    """Build one independent metadata/payload pair for a framed response.

    Args:
        image_id (str): Canonical positive decimal selector in the fixture.
        request_index (int): Zero-based request position.
        status (str): Production or deliberately invalid status token.
        data (bytes | None): Exact payload, defaulting to a small image body.
        mime (str): Item MIME type under test.
        sha256 (str | None): Optional digest override for fault injection.
        etag (str | None): Optional ETag override; defaults to quoted digest.

    Returns:
        WireItem: Metadata and raw bytes encoded by the independent builder.
    """
    payload = data if data is not None else b"thumbnail-bytes"
    successful = status == "ok"
    actual_data = payload if successful else b""
    digest = sha256
    if digest is None and successful:
        digest = hashlib.sha256(actual_data).hexdigest()
    validator = etag
    if validator is None and successful and digest is not None:
        validator = '"' + digest + '"'
    metadata: Metadata = {
        "v": 1,
        "image_id": image_id,
        "request_index": request_index,
        "status": status,
        "mime": mime if successful else "application/octet-stream",
        "length": len(actual_data),
        "sha256": digest,
        "etag": validator if successful else None,
    }
    return metadata, actual_data


def encode_response(
    items: list[WireItem],
    count: int | None = None,
    terminated: bool = True,
    raw_metadata: bytes | None = None,
) -> bytes:
    """Encode framed bytes without using production serialization code.

    Args:
        items (list[WireItem]): Ordered metadata and payload fixture pairs.
        count (int | None): Optional advertised count override.
        terminated (bool): Whether to include the final E record.
        raw_metadata (bytes | None): Optional metadata bytes for duplicate-key
            and malformed-UTF-8 fault injection in a one-item response.

    Returns:
        bytes: Complete or intentionally malformed PGB1 fixture bytes.
    """
    advertised_count = len(items) if count is None else count
    output = bytearray(WIRE_HEADER.pack(b"PGB1", 1, advertised_count))
    for item_index, (metadata, payload) in enumerate(items):
        encoded_metadata = raw_metadata if (
            raw_metadata is not None and item_index == 0
        ) else json.dumps(
            metadata,
            separators=(",", ":"),
            ensure_ascii=True,
        ).encode("utf-8")
        output.extend(b"I")
        output.extend(WIRE_LENGTHS.pack(len(encoded_metadata), len(payload)))
        output.extend(encoded_metadata)
        output.extend(payload)
    if terminated:
        output.extend(b"E")
        output.extend(struct.pack(">H", len(items)))
    return bytes(output)


def parse_response(
    requested_ids: tuple[str, ...] | list[str],
    body: bytes,
    fragment_size: int = PRODUCTION_MAX_RESPONSE_BYTES,
) -> tuple[ThumbnailBatchParser, list[ValidatedItem]]:
    """Feed one response in bounded fragments and validate terminal EOF.

    Args:
        requested_ids (tuple[str, ...] | list[str]): Exact expected image IDs.
        body (bytes): Complete response fixture.
        fragment_size (int): Maximum bytes supplied in one feed call.

    Returns:
        tuple[ThumbnailBatchParser, list[ValidatedItem]]: Completed parser and
            its individually verified items.

    Raises:
        ProtocolError: The request or response violates the production profile.
    """
    parser = ThumbnailBatchParser(requested_ids)
    received: list[ValidatedItem] = []
    for offset in range(0, len(body), fragment_size):
        received.extend(parser.feed(body[offset:offset + fragment_size]))
    parser.finish()
    return parser, received


class ThumbnailBatchProtocolTests(unittest.TestCase):
    """Exercise production PGB1 identity, framing and byte-boundary rules."""

    def test_supported_batch_counts_preserve_order(self) -> None:
        """Accept every covered group size and preserve exact request order.

        Returns:
            None: Assertions fail when a valid group is rejected or reordered.
        """
        for count in PRODUCTION_BATCH_COUNTS:
            requested_ids = tuple(str(index + 1) for index in range(count))
            items = [
                make_item(image_id, index)
                for index, image_id in enumerate(requested_ids)
            ]
            parser, received = parse_response(
                requested_ids,
                encode_response(items),
            )
            self.assertTrue(parser.completed)
            self.assertEqual(
                [item.image_id for item in received], list(requested_ids),
            )
            self.assertEqual(
                [item.request_index for item in received], list(range(count)),
            )

    def test_one_byte_fragmentation_and_incremental_publication(self) -> None:
        """Publish a verified item before E while accepting byte fragments.

        Returns:
            None: Assertions require complete item validation and later E proof.
        """
        requested_ids = ("17", "18")
        first_item = make_item("17", 0, data="snowman ☃".encode("utf-8"))
        second_item = make_item("18", 1)
        prefix = encode_response([first_item], count=len(requested_ids), terminated=False)
        response = encode_response([first_item, second_item])
        parser = ThumbnailBatchParser(requested_ids)
        published: list[ValidatedItem] = []
        for offset in range(0, len(prefix), ONE_BYTE_FRAGMENT):
            published.extend(parser.feed(prefix[offset:offset + ONE_BYTE_FRAGMENT]))
        self.assertEqual([item.image_id for item in published], ["17"])
        self.assertFalse(parser.completed)
        tail = response[len(prefix):]
        published.extend(parser.feed(tail))
        parser.finish()
        self.assertTrue(parser.completed)
        self.assertEqual([item.image_id for item in published], ["17", "18"])

    def test_strict_request_ids_and_response_order(self) -> None:
        """Reject invalid request selectors, count and item substitutions.

        Returns:
            None: Assertions fail if any noncanonical identity is admitted.
        """
        invalid_requests: tuple[
            tuple[str | int | bool, ...] | str, ...
        ] = (
            (), ("01",), ("+1",), ("0",), ("1", "1"),
            (str(MAX_IMAGE_ID + 1),), (1,), (True,), "1",
            tuple(str(index + 1) for index in range(PRODUCTION_MAX_ITEMS + 1)),
        )
        for requested_ids in invalid_requests:
            with self.subTest(requested_ids=requested_ids):
                with self.assertRaises(ProtocolError):
                    ThumbnailBatchParser(cast(
                        tuple[str, ...] | list[str], requested_ids,
                    ))

        valid_items = [make_item("1", 0), make_item("2", 1)]
        invalid_responses = (
            encode_response(valid_items, count=1),
            encode_response([valid_items[1], valid_items[0]]),
            encode_response([make_item("3", 0), valid_items[1]]),
        )
        for body in invalid_responses:
            with self.subTest(body_length=len(body)):
                with self.assertRaises(ProtocolError):
                    parse_response(("1", "2"), body)
        count_mismatch = ThumbnailBatchParser(("1", "2"))
        with self.assertRaises(ProtocolError):
            list(count_mismatch.feed(encode_response(valid_items, count=1)))
        self.assertEqual(count_mismatch.items_seen, 0)

    def test_integrity_status_mime_and_etag_rules(self) -> None:
        """Reject bad hashes, wrong ETags, reserved status and unsupported MIME.

        Returns:
            None: Assertions retain the production integrity and status profile.
        """
        digest = hashlib.sha256(b"thumbnail-bytes").hexdigest()
        bad_etag = '"' + ("0" * 64) + '"'
        bad_digest = "0" * 64
        not_modified = make_item(
            "1", 0, status="not_modified", etag='"' + digest + '"',
        )
        not_modified[0]["etag"] = '"' + digest + '"'
        wrong_valid_etag = make_item("1", 0, etag=bad_etag)
        wrong_digest = make_item("1", 0, sha256=bad_digest)
        unsupported_mime = make_item("1", 0, mime="image/png")
        invalid_items = (
            not_modified,
            wrong_valid_etag,
            wrong_digest,
            unsupported_mime,
        )
        for item in invalid_items:
            with self.subTest(status=item[0]["status"], mime=item[0]["mime"]):
                with self.assertRaises(ProtocolError):
                    parse_response(("1",), encode_response([item]))

        poisoned = ThumbnailBatchParser(("1",))
        with self.assertRaises(ProtocolError):
            list(poisoned.feed(encode_response([wrong_digest])))
        self.assertEqual(poisoned.state, "failed")
        with self.assertRaises(ProtocolError):
            list(poisoned.feed(encode_response([make_item("1", 0)])))

        unavailable = make_item("1", 0, status="unavailable")
        parser, received = parse_response(
            ("1",), encode_response([unavailable]),
        )
        self.assertTrue(parser.completed)
        self.assertEqual(received[0].status, "unavailable")
        self.assertEqual(received[0].data, b"")
        oversize = make_item("1", 0, status="oversize")
        _, oversize_items = parse_response(
            ("1",), encode_response([oversize]),
        )
        self.assertEqual(oversize_items[0].status, "oversize")

        restricted = make_item("1", 0)
        restricted[0]["etag"] = None
        _, restricted_items = parse_response(
            ("1",), encode_response([restricted]),
        )
        self.assertIsNone(restricted_items[0].etag)

        webp = make_item("1", 0, mime="image/webp")
        _, webp_items = parse_response(("1",), encode_response([webp]))
        self.assertEqual(webp_items[0].mime, "image/webp")

    def test_metadata_duplicates_version_length_and_truncation(self) -> None:
        """Reject ambiguous JSON, invalid framing and incomplete envelopes.

        Returns:
            None: Each malformed body must raise the protocol error type.
        """
        item = make_item("1", 0)
        encoded_metadata = json.dumps(
            item[0], separators=(",", ":"),
        ).encode("utf-8")
        duplicate_metadata = encoded_metadata[:-1] + b',"v":1}'
        bad_version = encoded_metadata.replace(b'"v":1', b'"v":2', 1)
        malformed_bodies = [
            encode_response([item], raw_metadata=duplicate_metadata),
            encode_response([item], raw_metadata=bad_version),
            encode_response([item], raw_metadata=b"\xff"),
            encode_response(
                [item], raw_metadata=b" " * ONE_BYTE_OVER_METADATA_LIMIT,
            ),
            encode_response([item])[:-1],
            encode_response([item]) + b"x",
            encode_response([item], terminated=False),
        ]
        wrong_footer_count = bytearray(encode_response([item]))
        wrong_footer_count[-1] = 2
        malformed_bodies.append(bytes(wrong_footer_count))
        mismatched_length = bytearray(encode_response([item]))
        original = WIRE_LENGTHS.unpack_from(
            mismatched_length, WIRE_HEADER.size + 1,
        )
        WIRE_LENGTHS.pack_into(
            mismatched_length,
            WIRE_HEADER.size + 1,
            original[0],
            original[1] + ONE_BYTE_FRAGMENT,
        )
        malformed_bodies.append(bytes(mismatched_length))
        malformed_bodies.append(
            b"PGB1" + bytes((2,)) + struct.pack(">H", 1)
            + bytes(mismatched_length[WIRE_HEADER.size + 3:])
        )
        for body in malformed_bodies:
            with self.subTest(body_length=len(body), marker=body[:7]):
                with self.assertRaises(ProtocolError):
                    parse_response(("1",), body)

    def test_image_size_boundary(self) -> None:
        """Accept the exact item cap and reject one byte above it.

        Returns:
            None: The exact boundary is accepted only with matching SHA-256.
        """
        exact_payload = b"x" * PRODUCTION_MAX_IMAGE_BYTES
        exact_item = make_item("1", 0, data=exact_payload)
        _, received = parse_response(("1",), encode_response([exact_item]))
        self.assertEqual(len(received[0].data), PRODUCTION_MAX_IMAGE_BYTES)

        oversized_payload = b"x" * ONE_BYTE_OVER_IMAGE_LIMIT
        oversized_item = make_item("1", 0, data=oversized_payload)
        with self.assertRaises(ProtocolError):
            parse_response(("1",), encode_response([oversized_item]))

    def test_production_total_cap_poisoning(self) -> None:
        """Reject over-cap input before parse and keep the parser poisoned.

        Returns:
            None: A rejected parser cannot resume or publish later bytes.
        """
        parser = ThumbnailBatchParser(("1",))
        too_large = b"x" * ONE_BYTE_OVER_RESPONSE_LIMIT
        with self.assertRaises(ProtocolError):
            list(parser.feed(too_large))
        self.assertFalse(parser.completed)
        self.assertEqual(parser.state, "failed")
        with self.assertRaises(ProtocolError):
            list(parser.feed(encode_response([make_item("1", 0)])))

    def test_truncated_payload_never_yields_partial_item(self) -> None:
        """Keep incomplete image bytes unpublished and poison on finish.

        Returns:
            None: Only the already complete earlier item may have been yielded.
        """
        first = make_item("1", 0)
        second = make_item("2", 1)
        body = encode_response([first, second])
        parser = ThumbnailBatchParser(("1", "2"))
        first_frame_end = len(encode_response([first], count=2, terminated=False))
        first_items = list(parser.feed(body[:first_frame_end]))
        self.assertEqual([item.image_id for item in first_items], ["1"])
        partial_end = len(body) - 4
        partial_items = list(parser.feed(body[first_frame_end:partial_end]))
        self.assertEqual(partial_items, [])
        with self.assertRaises(ProtocolError):
            parser.finish()
        self.assertFalse(parser.completed)
        with self.assertRaises(ProtocolError):
            list(parser.feed(body[partial_end:]))


if __name__ == "__main__":
    unittest.main()
