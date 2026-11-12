# Issue #362 B1.1: universal thumbnail-batch protocol benchmark

The ratified production-facing design contract is documented in
[B1.3 thumbnail batch v1](issue_362_thumbnail_batch_v1.md).

## Scope, provenance, and non-goals

This document describes an **isolated experimental protocol**, not a shipped
app-linking capability. B1.1 changes no production controller, service,
authentication scope, database schema, media cache, WinApp transport, or
Tkinter surface. The original single-thumbnail endpoint remains unchanged.

- Source branch: `feature/362-b1-thumbnail-batch-protocol-benchmark`.
- Baseline: `develop` at `cd8f2b3d98c5bad1623d951c94979de777d7d2c7`.
- PHP/Python integration benchmark: GitHub Actions
  [run 43021485434](https://github.com/klusik/PHP_gallery/actions/runs/43021485434),
  exact source `3321a0d925e278ec896b354255e1d05dee7c6bb4`,
  all three jobs passed.
- Reproducible owners: `tools/perf/issue_362_b1_producer.php`,
  `tools/perf/issue_362_b1_protocol.py`,
  `tools/perf/issue_362_b1_benchmark.py`,
  `tests/test_issue_362_b1_protocol.py` and
  `.github/workflows/issue-362-b1-protocol.yml`.
- This is a GitHub-runner, file-backed **transport simulation**, not an HTTP,
  proxy, TLS, authorization or production-server qualification.

### Tested environments

The focused workflow uses Linux PHP 8.1 and PHP 8.5 independently,
Python 3.14 on Linux, and Python 3.14 on Windows.
Pillow generates deterministic random-pattern JPEG/WebP images only on
disposable Linux CI runners. No binary fixture is committed. The producer
requires `GITHUB_ACTIONS=true` and
`GALLERY_WORKFLOW_ENABLE=disposable-only`, and runs only in PHP CLI.
No Composer, framework, Redis, daemon, cron, external server or persistent
database is required.

## Protocol alternatives

| Property | Binary framed v1 | Strict multipart/mixed profile |
| --- | --- | --- |
| Wire semantics | Custom length-prefixed binary frames | RFC 2046 MIME with required Content-Length per part |
| Per-image identity | Strict JSON v1 metadata | Same JSON in X-PG-Meta |
| Integrity | Per-item SHA-256 | Same per-item SHA-256 |
| Incremental result | After complete data/hash | After complete data, CRLF and hash |
| End of batch | Explicit E frame with count | Closing MIME delimiter plus known request count |
| Buffering requirement | At most one image | At most one image |
| Format implementation | Smaller wire envelope, custom contract | Familiar MIME envelope, stricter parser |
| Whole-response buffering | Not required | Not required |
| Provisional suitability | Preferred for v1 | Valid fallback candidate |

The experimental MIME parser accepts a deliberately **restricted** interoperable
profile: three exactly ordered headers (`Content-Type`, `Content-Length`,
`X-PG-Meta`), a known safe boundary token, explicit byte lengths, exact CRLF
separators, and a mandatory closing delimiter. It does not purport to be a
general-purpose permissive MIME email parser. The fixed synthetic boundary is
for deterministic comparison only. A real producer must choose a unique,
high-entropy per-response boundary and avoid delimiter collisions, including
when the body contains arbitrary binary JPEG/WebP data.

### Optional ZIP STORE alternative

ZIP STORE was intentionally deferred, not measured or presented as a third
successful prototype. Its entry headers and end-of-archive central directory
add framing and indexing, and portable readers may need random access,
spooling or delayed completion to validate an entire archive. No justified
benefit over the two streamable formats has been established for already
compressed JPEG/WebP bytes. B1.2 may add a focused ZIP STORE comparison only
if a concrete portability or tooling advantage warrants its extra scope.

## Proposed wire contract v1

This is a **draft for B1.3 ratification**, not an implemented HTTP API.
The later B2 route must be an optional, bearer-authenticated private linking
read with unchanged gallery/photo/scope/issuer policy and existing rate budgets.

### Shared item schema

Each item contains **exactly** these JSON keys:

| Key | Type and constraints | Meaning |
| --- | --- | --- |
| `v` | integer `1` | Envelope version; reject everything else |
| `image_id` | canonical positive decimal string, signed 64-bit range | Existing native image selector |
| `request_index` | integer `0..count-1`, in exact sequential order | Unique position in client request |
| `status` | `ok`, `not_modified`, `unavailable`, `oversize` | One independent outcome |
| `mime` | `image/jpeg` or `image/webp` for `ok`; otherwise `application/octet-stream` | Actual binary type |
| `length` | integer `0..4194304` | Exact raw data byte count |
| `sha256` | 64 lowercase hex characters for `ok`, otherwise `null` | Raw bytes SHA-256 |
| `etag` | optional strong quoted lowercase SHA-256, otherwise `null` | Content validator after authorization |

`ok` requires at least one data byte, a permitted image MIME, exact data
length and matching SHA-256. Other statuses require zero data bytes,
`sha256=null`, and the neutral MIME. `not_modified` requires a strong ETag;
`unavailable` and `oversize` cannot carry one. Repeated IDs, duplicate JSON
keys, reordered indexes, extra fields, unknown statuses/versions and
noncanonical IDs are fatal protocol errors.

`not_modified` does **not** grant access. B3 may reuse local bytes only after
the active grant/scope/gallery/photo proof is still valid and the current
authorized local content matches the advertised strong ETag. The prototype
parser returns this state without binary bytes, and does not impersonate
authorization or a cache verifier. `unavailable` deliberately does not
distinguish missing from unauthorized media in public error detail.
`oversize` means the single image cannot be returned within the established
per-item bound; it is not a reason to allocate more memory.

### Binary framed response

Suggested HTTP Content-Type:
`application/vnd.php-gallery.thumbnail-batch.v1`.
All integers are **unsigned, network-byte-order**.

```text
Body:
  "PGB1" (4 bytes) | version (uint8 = 1) | count (uint16, 1..32)
  repeated count times:
    "I" (1 byte) | metadata_length (uint16)
    | data_length (uint32)
    | metadata_length bytes of UTF-8 JSON
    | data_length raw image bytes
  "E" (1 byte) | completed_count (uint16)
  EOF, with no trailing bytes
```

The parser cross-checks the item metadata's `length` with `data_length`,
requires exactly the advertised item count and final completed count,
and fails if the terminal E record is missing. No base64 image serialization.

### multipart/mixed response

Suggested HTTP Content-Type:
`multipart/mixed; boundary=<response-specific-safe-token>`.

```text
--<boundary>\r\n
Content-Type: image/jpeg\r\n
Content-Length: <decimal-payload-length>\r\n
X-PG-Meta: <compact-v1-JSON>\r\n
\r\n
<exactly Content-Length raw bytes>\r\n
(repeat for all items)
--<boundary>--\r\n
EOF
```

The parser does not search inside image bytes for delimiters. It reads
exactly `Content-Length` bytes, validates the following CRLF, and then
requires the next opening or closing delimiter. A complete body delivered
as one chunk and a body delivered one byte at a time have the same
interpretation.

### Required limits and timing policy

| Limit | Experimental value | Rationale |
| --- | --- | --- |
| Images/request | 1 to 32 | Hard cap on per-request work and retained identifiers |
| Whole encoded response | 16 MiB | Reject unexpected buffering and oversized batches |
| One raw image | 4 MiB | Bound the one-image integrity-verification buffer |
| JSON metadata per image | 2,048 bytes | No identity or error text bloat |
| MIME part headers | 4,096 bytes | Reject indefinite or injected headers |
| Internal Python feed slice | 8,192 bytes | No whole-batch parser input allocation required |
| PHP file-copy chunk | 8,192 bytes | No whole-image PHP copy allocation |
| CI producer subprocess | 30 seconds | Finite disposable CI timeout; not a PHP hosting guarantee |

Suggested **future** B2/B3 client defaults to evaluate on staging: bounded
connect timeout (5 seconds), inactivity timeout (10 seconds), and whole-batch
deadline (30 seconds), all explicitly configurable and capped. These are
design candidates, **not implemented HTTP timings or required host values**.
Hosting infrastructure may enforce lower limits, buffer all output, or have
different concurrency/memory policies. B2 must admit only work that fits
the observed environment and fall back to fewer items or the existing
single-image protocol without repeat storms.

## HTTP responses and error boundaries

| HTTP outcome | Required future client behavior |
| --- | --- |
| 200 + negotiated type | Strictly parse the entire response envelope; items have independent statuses |
| 400 | Malformed batch request; do not retry unchanged input |
| 401 / 403 | Token/grant/scope failure; never bypass via an unauthenticated fallback |
| 404 / 405 | Recognized missing optional route or unsupported method may permit single-image fallback only after correct negotiation/context assessment; a missing photo is not automatically an unsupported API |
| 429 with Retry-After | Honor global/rate budget and backoff; do not multiply retries per image |
| 502 / 503 | Treat as transient infrastructure/service failure; bounded backoff, no assumptions about its origin |
| Wrong MIME or HTML error page | Never feed to binary/MIME parser as valid results |
| Timeout, disconnection, missing end | Fail the batch envelope; preserve only fully validated individually authorized items |
| Failure after HTTP headers | HTTP status can no longer be changed; emit only a defined item failure if safe, or terminate without successful final marker |

`ok`, `not_modified`, `unavailable` and `oversize` are **per-image**
outcomes. Global authentication and admission failures must occur before
a 200 response or any output. No synthetic failure marker can turn an
already corrupted or truncated envelope into success.
A SHA-256 match demonstrates byte integrity, not access authorization or
origin authenticity. B2/B3 still require HTTPS, live bearer authority,
resource containment and proper cache proof.

## Reproducible correctness and safety

The eight named deterministic Python test groups cover both formats:
one-byte arrival, split headers and length fields, all-at-once input,
several items per input chunk, partial and prematurely ended images,
interruption between items, missing final marker, wrong digest, wrong
metadata/byte length, duplicate IDs and JSON keys, unknown versions and
statuses, unexpected MIME, invalid MIME delimiters, corrupted structure,
trailing data, zero-length requests, over-cap counts/total bytes,
cancellation before completion, and independent mixed-success outcomes.

The Linux integration also runs the **actual PHP producer** for all four
statuses against each Python parser. Invalid data cannot appear as a
`ValidatedItem` before the entire item's length, syntax and hash have
been checked. Previously completed items are not retroactively made
valid for another gallery or stale authorization by this benchmark.
The prototype does not perform image-decoder safety validation.
B3 must decode only validated bytes on a bounded worker after admission.

All three focused CI jobs passed for the documented measurement SHA;
the final source/documentation SHA must be qualified again after edits.
The existing repository BASIC checks are a separate mandatory gate.

## Benchmark design and metric interpretation

Synthetic real JPEG/WebP images use reproducible seeded pixel data and four
sizes, from 96x96 through 640x480. Each 8/16/24/32-item case is measured
five times per protocol on disposable GitHub runners. Files are generated
afresh at the runner. Every PHP run writes its response to a temporary file.
The Python parser then consumes it in three separate **simulated** modes:
1,024-byte reads, 65,536-byte reads, or one complete buffered read.
A separate eight-item run inserts a small fixed delay after each verified
image to model a slower consumer.

PHP `hrtime()` measures metadata preparation, per-image hashing, serialization
and response file writing, but not PHP process startup, the Python orchestrator,
or a real HTTP response. Python `perf_counter_ns()` measures parser/read time
and first fully validated item after starting a local-file read. These
durations are **not network TTFB** or a measurement of frontend paint time.
PHP reports `memory_get_peak_usage(true)` allocator peak; Python reports
`tracemalloc` tracked allocations (not full interpreter/C-library RSS).
Buffered delivery naturally retains more bytes at the caller boundary.

The report records p50 and descriptive interpolated p95, separated producer
time, parser time, first validated item, per-mode memory, exact bytes and
protocol overhead. With only five repeats, p99 is not statistically useful
and is intentionally omitted. No unstable shared-runner elapsed time is a
PASS/FAIL threshold. Correctness, integrity and bounds are strict gates.

## Measured results

These values are from the successful [PHP 8.5 B1 run
43021485434](https://github.com/klusik/PHP_gallery/actions/runs/43021485434),
using exact SHA `3321a0d925e278ec896b354255e1d05dee7c6bb4`.
The equivalent PHP 8.1 and all modes, p95 and memory values are retained
as JSON and Markdown artifacts attached to the same workflow run.

### Serialized sizes and overhead

| Images | Source image KiB | Framed wire KiB | Framed overhead KiB | MIME wire KiB | MIME overhead KiB |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 8 | 688.7 | 690.7 | 2.0 | 691.5 | 2.8 |
| 16 | 1377.4 | 1381.3 | 3.9 | 1382.9 | 5.5 |
| 24 | 2066.3 | 2072.2 | 5.9 | 2074.5 | 8.2 |
| 32 | 2754.9 | 2762.8 | 7.9 | 2765.9 | 11.0 |

Values are rounded to 0.1 KiB. The actual artifact includes integer
byte counts and avoids rounding ambiguity. Overhead is all bytes above
the original image payload, including JSON metadata.

### PHP 8.5 monotonic times, simulated 65,536-byte reads

| Images | Framed PHP p50 / p95 ms | MIME PHP p50 / p95 ms | Framed parse p50 / p95 ms | MIME parse p50 / p95 ms |
| ---: | ---: | ---: | ---: | ---: |
| 8 | 1.23 / 1.29 | 1.23 / 1.25 | 0.86 / 1.23 | 0.93 / 0.94 |
| 16 | 2.34 / 2.37 | 2.33 / 2.35 | 1.65 / 1.71 | 1.80 / 1.82 |
| 24 | 3.43 / 5.38 | 3.41 / 3.60 | 2.60 / 3.56 | 2.66 / 2.75 |
| 32 | 4.53 / 4.56 | 4.56 / 4.62 | 3.37 / 3.44 | 3.59 / 3.61 |

At 32 images on this runner, first verified-item parser time is
approximately 0.07 ms p50 in both formats for simulated large chunks.
This measures **local parsing after a synthetic response already exists**,
not the first image reaching a client over a network.

For 32 images, PHP allocator peak is 2.00 MiB in this test, while Python
`tracemalloc` peak for 65,536-byte delivery is about 0.71 MiB in both
formats. When the caller buffers the entire 2.7 MiB response, tracked
Python peak rises to about 5.73 MiB. This illustrates why the receiving
HTTP library's buffering choice matters even when the parser is incremental.

## Portability and response buffering

HTTP servers, proxies, CGI/FPM gateways and TLS stacks may hold output
without immediately forwarding a PHP `flush()`. Neither candidate depends
on flushing each item. Both parse an arbitrarily chunked response or a
complete buffered response. The first-image advantage of true streaming
exists **only if** the delivery infrastructure actually forwards useful
bytes early. A buffered proxy may provide no first-image latency benefit
from one batch, and larger requests can occupy a PHP worker longer.

No vendor-specific configuration is a prerequisite. A compatible future
hosting environment needs PHP 8.1+, HTTPS, normal request-body handling,
a bounded response path and existing application storage/authorization.
There is no required proxy brand, worker count, PHP `memory_limit`,
`max_execution_time`, external queue or HTTP/2 support assumption.
Actual effective body limits and timeout policy must be observed at
runtime or in isolated staging evidence before activating larger batches.

A response containing 16 MiB is not a goal. It is an experimental
hard ceiling to reject pathological allocations. B2 should default
conservatively, honor individual-image limits and admit smaller batches
when resource evidence warrants.

## Preliminary protocol recommendation

**Prefer the versioned binary framed stream for B2 design exploration.**
It has a smaller, simpler deterministic envelope and supports safe
per-image yield. The measured 32-image wire savings relative to
multipart are only about 3.1 KiB, however, and synthetic parser times
are not enough to establish faster real HTTP. If integration with
standard MIME tooling becomes materially safer or simpler,
`multipart/mixed` remains a valid second candidate. Do not select
ZIP STORE by default.

The decision remains provisional pending actual HTTP buffering behavior,
security review and controlled staging measurements, without tests
against any production gallery installation.

## Remaining B1.2 and B1.3 work

**B1.2, controlled transport and failure qualification:**
exercise a disposable local HTTP server on GitHub Actions (not the
user's computer and not production), add response buffering/chunk
scheduling/cancellation/retry simulations, measure producer resource
limits and time to first validated item through actual loopback HTTP,
and compare 8/16/24/32 plus mixed cache states. Assert PHP 8.1/8.5,
Windows Python parser and existing BASIC across the same exact SHA.
Select explicit safe defaults and a data-driven smaller-batch fallback.

**B1.3, wire/security decision freeze:**
review and freeze version negotiation, request schema, authentication
before bytes, per-image reauthorization, rate-limit accounting,
Cache/ETag authority, partial success handling, status/error compatibility,
content types, timeout limits and mixed-version behavior. Define how
unsupported capability/route returns to the **existing single-thumbnail
API without hiding 401/403 or 429**. Only then implement B2 server and
B3 client on separately scoped commits. No route or capability is
created during B1.1.

**No TeX manual impact:** this experimental, issue-specific CI harness
does not alter a shipped product feature, maintainer release procedure,
public API reference or supported user/developer operating instructions.
The permanent technical evidence is this document and attached CI reports.
