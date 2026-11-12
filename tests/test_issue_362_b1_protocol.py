# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tests/test_issue_362_b1_protocol.py
# Module Type: Protocol Fault Regression Suite
# Purpose: Verify isolated B1.1 streaming parsers under fragmented and bad input.
# Responsibilities:
#   - Prove item-level integrity, completion and exact v1 grammar.
#   - Prove fixed caps and no publication of partial or invalid image data.
#   - Cover both framed and RFC 2046 multipart delivery paths.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Deterministic byte-level regressions for issue #362 B1.1."""

import hashlib
import json
import struct
import unittest

from tools.perf.issue_362_b1_protocol import (
    BENCHMARK_BOUNDARY,
    FramedParser,
    MAX_ITEMS,
    MAX_RESPONSE_BYTES,
    MultipartParser,
    ProtocolError,
    ValidatedItem,
)


# Purpose: Enumerate isolated binary wire contracts under test.
# Type: tuple[str, ...]. Units: protocol identifiers. Scope: B1.1 suite.
# Consumers: all matrix test cases. Rationale: both formats receive identical
# fault coverage and independent, repeatable byte fixtures.
FORMATS = ("framed", "multipart")

# Purpose: Exercise the absolute limit using an input one byte too long.
# Type: int. Units: bytes. Scope: B1.1 test.
# Consumers: test_reject_bad_limits_and_empty_batch.
# Rationale: a single byte above 16 MiB must fail deterministically.
ONE_BYTE = 1

# Purpose: Supply a representative safe strong ETag for non-binary 304 states.
# Type: str. Units: HTTP validator characters. Scope: B1.1 test.
# Consumers: make_item. Rationale: SHA-256 quoted form matches v1 contract.
TEST_ETAG = '"' + "a" * 64 + '"'


def make_item(
    index: int,
    status: str = "ok",
    image_id: str | None = None,
) -> tuple[dict[str, object], bytes]:
    """Create one deterministic binary item with exact v1 metadata.

    Args:
        index (int): Zero-based request ordinal.
        status (str): One of the supported status values, or an invalid test value.
        image_id (str | None): Optional canonical image selector override.

    Returns:
        tuple[dict[str, object], bytes]: Metadata dictionary and binary payload, both controlled by the suite.
    """
    body = (bytes((index + ONE_BYTE,)) * 17) if status == "ok" else b""
    digest = hashlib.sha256(body).hexdigest() if body else None
    metadata: dict[str, object] = {
        "v": 1,
        "image_id": image_id or str(index + ONE_BYTE),
        "request_index": index,
        "status": status,
        "mime": "image/jpeg" if body else "application/octet-stream",
        "length": len(body),
        "sha256": digest,
        "etag": TEST_ETAG if status == "not_modified" else (
            '"' + digest + '"' if digest else None
        ),
    }
    return metadata, body


def encode_response(
    protocol: str,
    items: list[tuple[dict[str, object], bytes]],
    count: int | None = None,
    terminated: bool = True,
) -> bytes:
    """Construct exact synthetic wire bytes without calling production APIs.

    Args:
        protocol (str): Framed or multipart encoding name.
        items (list[tuple[dict[str, object], bytes]]): Ordered metadata and image payload pairs.
        count (int | None): Explicit advertised request size, defaults to item count.
        terminated (bool): Whether to emit the explicit closing marker.

    Returns:
        bytes: Complete or deliberately unterminated wire payload.
    """
    expected_count = count if count is not None else len(items)
    chunks: list[bytes] = []
    if protocol == "framed":
        chunks.append(struct.pack(">4sBH", b"PGB1", 1, expected_count))
    for metadata, body in items:
        meta = json.dumps(
            metadata, separators=(",", ":"), ensure_ascii=True
        ).encode("ascii")
        if protocol == "framed":
            chunks.append(b"I" + struct.pack(">HI", len(meta), len(body)))
            chunks.append(meta)
            chunks.append(body)
        else:
            chunks.append(
                (
                    "--" + BENCHMARK_BOUNDARY + "\r\n"
                    + "Content-Type: " + str(metadata["mime"]) + "\r\n"
                    + "Content-Length: " + str(len(body)) + "\r\n"
                    + "X-PG-Meta: "
                ).encode("ascii") + meta + b"\r\n\r\n"
            )
            chunks.append(body)
            chunks.append(b"\r\n")
    if terminated:
        if protocol == "framed":
            chunks.append(b"E" + struct.pack(">H", expected_count))
        else:
            chunks.append(
                ("--" + BENCHMARK_BOUNDARY + "--\r\n").encode("ascii")
            )
    return b"".join(chunks)


def decode_response(
    protocol: str, body: bytes, chunk_size: int, expected_count: int
) -> list[ValidatedItem]:
    """Consume arbitrary transport chunks with exact parser finish checks.

    Args:
        protocol (str): Selected experimental wire format.
        body (bytes): Full or deliberately broken response bytes.
        chunk_size (int): Bytes delivered to each parser feed call.
        expected_count (int): Count of requested image IDs.

    Returns:
        list[ValidatedItem]: Only structurally complete and integrity-checked items.

    Raises:
        ProtocolError: A parser rejects malformed, truncated or unsafe input.
    """
    parser = (
        FramedParser() if protocol == "framed"
        else MultipartParser(expected_count)
    )
    received: list[ValidatedItem] = []
    for position in range(0, len(body), chunk_size):
        received.extend(parser.feed(body[position:position + chunk_size]))
    parser.finish()
    return received


class B1ProtocolTests(unittest.TestCase):
    """Assert fail-closed streaming semantics for both candidate protocols."""

    def test_fragmentation_and_complete_or_buffered_delivery(self) -> None:
        """Cover 1-byte, split-length, multi-item and whole-response input.

        Returns:
            None: None.
        """
        items = [make_item(0), make_item(1), make_item(2)]
        for protocol in FORMATS:
            body = encode_response(protocol, items)
            for chunk in (1, 2, 3, 7, 8192, len(body)):
                with self.subTest(protocol=protocol, chunk=chunk):
                    result = decode_response(protocol, body, chunk, 3)
                    self.assertEqual(
                        [item.image_id for item in result], ["1", "2", "3"]
                    )
                    self.assertTrue(all(item.status == "ok" for item in result))
                    self.assertEqual(
                        [item.data for item in result],
                        [item[1] for item in items],
                    )

    def test_partial_success_and_nonbinary_states(self) -> None:
        """Keep independent statuses explicit without publishing fake images.

        Returns:
            None: None.
        """
        items = [
            make_item(0),
            make_item(1, status="not_modified"),
            make_item(2, status="unavailable"),
            make_item(3, status="oversize"),
        ]
        for protocol in FORMATS:
            with self.subTest(protocol=protocol):
                result = decode_response(
                    protocol, encode_response(protocol, items), 5, 4
                )
                self.assertEqual(
                    [item.status for item in result],
                    ["ok", "not_modified", "unavailable", "oversize"],
                )
                self.assertEqual([len(item.data) for item in result],
                                 [17, 0, 0, 0])
                self.assertEqual(result[1].etag, TEST_ETAG)

    def test_interrupted_images_and_missing_final_marker(self) -> None:
        """Refuse truncated data and terminators even after valid prior items.

        Returns:
            None: None.
        """
        entries = [make_item(0), make_item(1)]
        for protocol in FORMATS:
            whole = encode_response(protocol, entries)
            for bad in (
                whole[:-1],
                whole[:-8],
                encode_response(protocol, entries, terminated=False),
                encode_response(
                    protocol, entries[:1], count=2, terminated=False
                ),
            ):
                with self.subTest(protocol=protocol, size=len(bad)):
                    with self.assertRaises(ProtocolError):
                        decode_response(protocol, bad, 3, 2)
            # Complete items may survive a later interruption. The parser
            # never claims the whole response succeeded without its marker.
            interrupted = encode_response(
                protocol, entries[:1], count=2, terminated=False
            )
            parser = (
                FramedParser() if protocol == "framed"
                else MultipartParser(2)
            )
            valid = list(parser.feed(interrupted))
            self.assertEqual(len(valid), 1)
            self.assertEqual(valid[0].image_id, "1")
            with self.assertRaises(ProtocolError):
                parser.finish()

    def test_bad_metadata_fail_closed(self) -> None:
        """Reject wrong digests, MIME, versions, statuses, lengths and IDs.

        Returns:
            None: None.
        """
        violations = {
            "digest": {"sha256": "0" * 64},
            "invalid_length": {"length": 18},
            "invalid_mime": {"mime": "image/png"},
            "unknown_version": {"v": 2},
            "unknown_status": {"status": "unexpected"},
            "request_order": {"request_index": 5},
            "noncanonical_id": {"image_id": "01"},
            "unknown_field": {"unrecognized": True},
            "missing_required_hash": {"sha256": None},
        }
        for protocol in FORMATS:
            for label, mutation in violations.items():
                metadata, body = make_item(0)
                metadata.update(mutation)
                wire = encode_response(protocol, [(metadata, body)])
                with self.subTest(protocol=protocol, violation=label):
                    with self.assertRaises(ProtocolError):
                        decode_response(protocol, wire, 2, 1)

    def test_duplicates_and_cache_validator_refusal(self) -> None:
        """Reject duplicate image selectors and unproved 304-like states.

        Returns:
            None: None.
        """
        entries = [make_item(0), make_item(1, image_id="1")]
        invalid_cache, empty = make_item(0, "not_modified")
        invalid_cache["etag"] = None
        for protocol in FORMATS:
            with self.subTest(protocol=protocol, case="duplicate"):
                with self.assertRaises(ProtocolError):
                    decode_response(
                        protocol, encode_response(protocol, entries), 7, 2
                    )
            with self.subTest(protocol=protocol, case="missing_etag"):
                with self.assertRaises(ProtocolError):
                    decode_response(
                        protocol,
                        encode_response(protocol, [(invalid_cache, empty)]),
                        7, 1
                    )

    def test_reject_bad_limits_and_empty_batch(self) -> None:
        """Enforce 16 MiB total, 32 items, and nonempty requests.

        Returns:
            None: None.
        """
        for protocol in FORMATS:
            with self.subTest(protocol=protocol, case="empty"):
                if protocol == "framed":
                    with self.assertRaises(ProtocolError):
                        decode_response(
                            protocol, encode_response(protocol, []), 10, 0
                        )
                else:
                    with self.assertRaises(ProtocolError):
                        MultipartParser(0)
            with self.subTest(protocol=protocol, case="excess_count"):
                if protocol == "framed":
                    with self.assertRaises(ProtocolError):
                        decode_response(
                            protocol,
                            encode_response(protocol, [], count=MAX_ITEMS + 1),
                            10, MAX_ITEMS + 1
                        )
                else:
                    with self.assertRaises(ProtocolError):
                        MultipartParser(MAX_ITEMS + 1)
            with self.subTest(protocol=protocol, case="excess_response"):
                parser = (
                    FramedParser() if protocol == "framed"
                    else MultipartParser(1)
                )
                with self.assertRaises(ProtocolError):
                    list(parser.feed(
                        b"\x00" * (MAX_RESPONSE_BYTES + ONE_BYTE)
                    ))

    def test_structure_and_cancelled_request(self) -> None:
        """Require proper frame tags, MIME delimiters and terminal state.

        Returns:
            None: None.
        """
        entries = [make_item(0)]
        for protocol in FORMATS:
            wire = encode_response(protocol, entries)
            bad = (
                wire[:7] + b"X" + wire[8:]
                if protocol == "framed" else b"XX" + wire[2:]
            )
            with self.subTest(protocol=protocol, case="invalid_structure"):
                with self.assertRaises(ProtocolError):
                    decode_response(protocol, bad, 1, 1)
            with self.subTest(protocol=protocol, case="trailing_data"):
                with self.assertRaises(ProtocolError):
                    decode_response(protocol, wire + b"X", 1, 1)
            with self.subTest(protocol=protocol, case="cancel"):
                parser = (
                    FramedParser() if protocol == "framed"
                    else MultipartParser(1)
                )
                list(parser.feed(wire[:11]))
                with self.assertRaises(ProtocolError):
                    parser.finish()

    def test_duplicate_json_fields_and_bad_mime_boundary(self) -> None:
        """Refuse JSON key shadowing and unsafe user-supplied delimiters.

        Returns:
            None: None.
        """
        for protocol in FORMATS:
            metadata, payload = make_item(0)
            body = encode_response(protocol, [(metadata, payload)])
            # Mutate a known field without changing any binary payload bytes.
            extra = b',"v":1'
            marker = b',"image_id":'
            if protocol == "framed":
                raw = json.dumps(
                    metadata, separators=(",", ":")
                ).encode("ascii")
                malicious = raw.replace(marker, extra + marker, 1)
                wire = (
                    body[:8] + struct.pack(">H", len(malicious))
                    + body[10:14] + malicious + payload + body[-3:]
                )
            else:
                wire = body.replace(marker, extra + marker, 1)
            with self.subTest(protocol=protocol, case="dup_json"):
                with self.assertRaises(ProtocolError):
                    decode_response(protocol, wire, 5, 1)
        for boundary in ("", "abc\r\nInjected: x", "a" * 71):
            with self.subTest(boundary=boundary):
                with self.assertRaises(ProtocolError):
                    MultipartParser(1, boundary)


if __name__ == "__main__":
    unittest.main()
