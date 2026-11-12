# Issue #362 B1.3: thumbnail batch v1 production contract

## Status and scope

This document records the B1.3 application contract for the read-only native
thumbnail batch endpoint. The B2 PHP/HTTP endpoint, B3 strict PGB1 parser and
transport, session API, cache publication rules and native viewer integration
are implemented. Their hosted feature checks include owned PHP/HTTP and
Windows viewer profiles; those checks do not establish target-host acceptance.
The exact-SHA feature run for
`8c2418344e2e6bf241eec08b2ed6fc9e4322f3b8`
([run 43111401537](https://github.com/klusik/PHP_gallery/actions/runs/43111401537))
passed all six jobs, including the C1 tree HTTP/`LinkingSession` and Windows
`PhotosWorkspace` diagnostics. BASIC source checks also passed in
[run 43111401326](https://github.com/klusik/PHP_gallery/actions/runs/43111401326);
that run is syntax/declaration evidence, not diagnostic measurement evidence.
These are synthetic/hosted measurements, not FULL qualification, installed
Windows acceptance, production-host acceptance or staging rollout evidence.
The current policy defaults batching ON for fresh installations and for
upgrades without a saved choice; administrators can disable it in Admin >
Features. An explicit saved choice is retained across updates. Target-host
acceptance remains unverified and is not inferred from this default.

This specification follows the [B1.1 protocol benchmark](issue_362_b1_protocol_benchmark.md)
and the [B1.2 HTTP transport benchmark](issue_362_b1_http_transport_benchmark.md).
It selects the existing binary framed v1 encoding without changing its bytes.

## Endpoint and capability

The endpoint is a private app-linking read owned by the existing app-linking
controller and thumbnail batch service, reusing the `app_linking_media` owner.
Its exact route is
`POST index.php?page=app_linking_thumbnail_batch`; the entire `QUERY_STRING`
must equal `page=app_linking_thumbnail_batch`. Reject extra, reordered,
encoded, duplicated or unknown query parameters. This matches the repository's
exact-selector routing convention; do not add an `/api` rewrite.

This B1.3 specification describes the endpoint independently of target-host
acceptance. Real-deployment FPM/proxy/HTTPS acceptance remains separate D work
and open. No staging or production result is claimed.
The B2 endpoint accepts the compact production field set and canonical values
shown below. The B3 strict parser and session API consume that profile. The
WinApp viewer uses the negotiated batch route when discovery advertises it and
falls back to the original single-thumbnail route when the capability is
absent or the server returns the exact optional-route refusal.

Add the exact public discovery capability token `media:thumbnail-batch-v1`
to the existing unauthenticated discovery capability list only when existing
`media:read` readiness is true and the server-side preference is enabled.
Discovery remains a public, nonauthorizing list: it conveys no gallery, photo,
grant or scope access. The endpoint independently requires existing bearer
authentication, verified installation issuer, current grant revalidation and
scope policy before reading or emitting media. Capability revocation takes
effect on the next request. The canonical preference is the Admin > Features
`thumbnail_batch` switch, stored as `feature_flag.thumbnail_batch.enabled`.
For installations without a saved preference, the legacy environment value is
consulted as a compatibility fallback: unset or exact `1` means ON, exact `0`
or malformed values mean OFF. Once an administrator saves `0` or `1`, that
stored choice wins over the environment. Fresh-install seeding stores ON;
ordinary upgrades do not rewrite existing preferences. The generic capability
route list stays empty so a disabled batch request retains its exact
`unsupported_capability` JSON refusal and negotiated clients can use the
single-thumbnail route.

Preserve the existing verified direct localhost HTTP deployment behavior;
do not add a TLS-only restriction or trust forwarded-protocol headers. Remote
HTTPS/FPM/proxy acceptance remains a separate D staging gate. Do not accept
browser cookies as authority or permit CORS/cross-origin browser use.

The method is POST because the request contains a bounded ID list; it remains
read-only for gallery content: it does not mutate gallery state, files, or
caches. Existing authentication may update rolling-grant access bookkeeping,
and the existing rate limiter records admission. Repeated live authentication
is bounded by the batch count, but its host cost requires D staging measurement.

The request content type is `application/json`; response content type is
`application/vnd.php-gallery.thumbnail-batch.v1`. Every batch response MUST
send `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`,
and `Vary: Authorization`, regardless of per-item ETags. Do not emit aggregate
photo-cache `max-age` headers. Do not compress the binary body at the
application layer. Intermediary buffering or transfer framing must not alter
the body grammar.

## Request contract

The server accepts the compact JSON representation shown below: fields appear
in this exact order with no whitespace or escaped field names. This structural
restriction rejects duplicate keys without a second general JSON parser.
The JSON request is one object with exactly these fields:

```json
{"v":1,"gallery_id":"42","image_ids":["101","102"]}
```

`v` is the JSON integer 1. `gallery_id` and each `image_ids` value are
canonical positive decimal strings in signed 64-bit range (`[1-9][0-9]{0,18}`
and numeric value no greater than 9223372036854775807). Do not accept JSON
numbers, signs, whitespace, leading zeroes, exponent notation, empty strings,
or values that overflow the platform integer. `image_ids` must contain 1 to 32
unique IDs in the requested order. Reject duplicate JSON keys, unknown fields,
invalid UTF-8, trailing JSON values, and request bodies above 16 KiB before
expensive database or filesystem work. The existing pre-auth IP/global public
guards still run before parsing. The 16 KiB request ceiling is transport
admission, not an image-payload budget.

The batch is bound to one physical gallery. Smart-galleries, recursive parent
selectors, relative paths, filenames, arbitrary URLs, client-selected storage
paths and per-item gallery overrides are not accepted. Before any response
bytes, resolve the caller's current grant and scope, resolve the physical
gallery under the existing app-linking policy, and validate every submitted
photo ID as a direct photo in that gallery. If the gallery is not accessible,
or any ID is missing, belongs elsewhere, or is disallowed by the caller's
scope, return one generic HTTP 404 JSON error with no item details. This
all-or-nothing preflight prevents an ID list from probing cross-gallery
membership. Revalidate the grant immediately before opening each file; a
revocation or scope change during processing terminates the response without
an `E` completion record.

After preflight, preserve the caller's ID order exactly. Every response item
has its corresponding zero-based `request_index`; no item may be reordered,
omitted, duplicated, or substituted. Never interpret an invalid item as an
authorization-neutral cache hit.

## Authorization and media selection

Reuse the existing owners rather than duplicating policy. B2 must call the
current grant/scope and gallery admission owners, then
`app_linking_media_open_photo()` for each direct photo. That owner enforces
gallery membership, public-versus-Admin visibility, safe direct-photo
selection, existing thumbnail variant ordering and verified file containment.
Admin access, public export, Admin preload and NSFW decisions remain governed
by their existing independent policies. Batch membership does not upgrade
scope or make an otherwise restricted photo visible.

The batch route may return only the existing display thumbnail selected by the
existing media owner. It must not generate or repair derivatives, write files,
change DB rows, update access state, or read originals beyond the existing
bounded fallback that `app_linking_media_open_photo()` already permits for a
missing thumbnail. Keep the owner's current containment, regular-file,
symlink, MIME and byte checks. Do not emit paths, filenames, exception text,
or a distinction between nonexistent and unauthorized photos.

## Response wire: unchanged framed v1

All integer fields are unsigned, network-byte-order. The response body is:

```text
"PGB1" (4 bytes) | version (uint8 = 1) | count (uint16, exact request count)
repeat count times:
  "I" (1 byte) | metadata_length (uint16) | data_length (uint32)
  metadata_length bytes of UTF-8 JSON | exactly data_length image bytes
"E" (1 byte) | completed_count (uint16, exact request count)
EOF; no trailing bytes
```

Metadata is a compact UTF-8 JSON object with exactly these fields:

| Field | Contract |
| --- | --- |
| `v` | Integer `1` |
| `image_id` | Exact canonical ID from the request |
| `request_index` | Integer matching its sequential position |
| `status` | `ok`, `unavailable`, or `oversize` in production v1 |
| `mime` | Existing selected image MIME for `ok`; otherwise `application/octet-stream` |
| `length` | Exact `data_length`, from 0 through 4,194,304 |
| `sha256` | Lowercase 64-hex SHA-256 for `ok`; otherwise `null` |
| `etag` | Existing strong ETag for eligible public/preload content; otherwise `null` |

For `ok`, length is at least one, MIME is `image/jpeg` or `image/webp`, and
SHA-256 MUST cover the exact emitted bytes. This digest is a transport
integrity check only; it is not permission to cache. `etag` reuses the value
from `app_linking_media_open_photo()` for cache-eligible public or independently
approved Admin-preload responses; it is null for restricted photos. This ETag
is a per-item eligibility hint only: fresh descriptor, account and grant proof
remains required before using cached bytes. The
existing `X-PHP-Gallery-Preload: admin-v1` request header remains the only
Admin preload opt-in and is accepted only under its existing Admin-media and
preload-gallery policy. The current photo-cache request/header contract remains
authoritative. Existing media selection supports
other display MIME types, but the batch contract intentionally admits only
JPEG and WebP: expanding the set requires an explicit client capability and a
newly qualified contract. If the selected existing rendition is another
format, return `unavailable` for that item. `unavailable` and `oversize` have
zero data length, neutral MIME, null digest and null ETag. `oversize` means
the selected file exceeds the per-item batch ceiling; it does not trigger a
larger read or an unbounded fallback.

The benchmark parser recognizes `not_modified` because B1.1 explored that
state. Production v1 MUST NOT send it, and MUST NOT accept client conditional
validators. The current PHP media owner can produce a validator only for
some public/preload cases, while a safe cache reuse decision also requires
fresh authorization and proof that local bytes match the current authorized
content. B1 has not established that full WinApp cache proof across scopes.
Until a later version explicitly establishes and tests that proof,
`not_modified` is a protocol error; clients fetch bytes or retain their current
independently authorized cache under existing behavior.

The current media owner applies a 16 MiB read/hash ceiling by default.
B2 must add a batch-specific maximum-byte argument or equivalent bounded
owner entry point that applies the 4 MiB batch ceiling before hashing or
copying, while preserving the existing 16 MiB default for current callers.
Batch responses still hash each admitted file for the required SHA-256 even
when that photo's scope is not eligible for a cache validator. A digest does
not grant cross-request authorization or cache reuse.

Keep the B1.1 framing grammar, metadata keys, integer byte order and explicit
end marker unchanged. The production profile is narrower than the benchmark
parser in three respects: no `not_modified`, ETags only where the existing
media owner permits them, and only JPEG/WebP
successful payloads. These are application-level restrictions, not a framing
version change. Unknown fields/statuses/versions, duplicate IDs or JSON keys,
bad ordering, mismatched lengths, digest mismatch, missing `E`, wrong count,
trailing bytes or malformed UTF-8 invalidate the whole envelope.

## Limits and admission

Use these specification ceilings independently; count admission never implies
that the aggregate byte budget is available:

| Policy | Value | Application |
| --- | ---: | --- |
| Initial client group | Up to 8 items | Conservative first client grouping |
| Adaptive steady group | Up to 16 items | Only after a healthy initial group and D host acceptance |
| Absolute batch count | 32 items | Hard protocol/request ceiling |
| Whole response body | 8 MiB | Includes header, metadata, all payloads and terminator |
| One image payload | 4 MiB | Per-item absolute ceiling |
| One metadata object | 2,048 bytes | Reject before payload streaming |
| Request body | 16 KiB | Reject before JSON decode/work |
| Server copy/hash chunk | 8 KiB | Bounded read/write slices; no aggregate image buffer |

The 8 MiB total is intentionally stricter than the 16 MiB B1 parser ceiling.
It is a proposed production admission limit, not evidence that any host accepts
or efficiently streams that amount. Stream one item at a time. Before opening
or hashing each selected file, resolve and stat its one verified handle, then
reserve enough of the remaining total budget for its payload and bounded
metadata/status frame, the maximum status-frame budget for every still-requested
item, and the final terminator. If its actual size cannot fit while preserving
those reservations, emit `oversize` with zero payload; never hash or copy it.
If even all remaining status frames and the terminator cannot fit, terminate
without `E`.
Do not hash all files or buffer the complete batch to precompute a body size.
These caps bound protocol bytes but do not prove safe concurrent FPM worker
occupancy; that requires staging evidence.

The client initially groups up to 8 IDs and may adapt to 16 only after a
healthy bounded group. A slow, large or exceptional group returns to 8.
This adaptive policy is part of D staging acceptance before enablement, not
evidence that staging has passed. The enabled server accepts any valid
count up to the protocol's hard 32-item cap; do not impose an unnegotiated
cohort-specific count limit. A caller may choose a smaller group. No
configuration can raise the hard count, per-item, total-byte or metadata
ceiling without a new reviewed version.

## HTTP errors and partial delivery

Global admission and authorization errors occur before output and use ordinary
HTTP statuses: `400` malformed request; `401` missing/invalid bearer;
`403` invalid issuer or scope; `404` generic unavailable gallery/photo set or
the stable `unsupported_capability` code when the optional route is disabled
or unavailable; `413` request or response over limit; `429`
rate denied with `Retry-After`; `503` temporary service/resource admission
failure. Return a small JSON error object with a stable non-sensitive code,
`Cache-Control: no-store`, and no IDs or filesystem details. Do not return a
200 framed body for these errors.

Once response bytes begin, HTTP status cannot change. An individual file that
becomes unavailable or exceeds its item ceiling may produce the explicit
per-item status after all IDs passed preflight. A changed grant, scope,
gallery or file-integrity condition that makes authorization uncertain MUST
terminate the response without the final `E` record. Never synthesize a
successful terminator after an exception, disconnect, output failure or
revocation. Do not buffer the complete batch in PHP memory. Open and validate
one file at a time, stream in bounded chunks, and close it before advancing.
If an intermediary buffers the body, correctness and total limits remain the
same; only latency changes.

Only fully parsed items whose hashes passed are individually complete. If the
envelope later truncates or lacks `E`, the batch is failed and the client MUST
discard the batch result for this operation. It may preserve already validated
bytes only when the existing independent live authorization and cache policy
proves them safe; B1 does not establish such a cross-request cache grant.
Cancellation closes the response, stops parsing and schedules no retry.

## Rate accounting and client recovery

Reuse the existing app-linking rate-limit service. The pre-authenticated
public IP/global transport guard charges one HTTP request. After bearer
authentication, charge the requested image count N (not N+1) against the
existing `app_link_photo_ip` and `app_link_photo_global` policies (600 per IP
and 3,600 global per five minutes). Add a bounded authenticated-account
photo-read budget of 600 per five minutes through the existing rate-policy
owner; it must not multiply or replace the shared IP/global limits. Do not
create a batch-only allowance that permits more photo reads than existing
single-thumbnail traffic. Fail closed if rate storage or admission is
unavailable. Emit `429` with the existing retry interval; never reveal which
item caused denial.

The client starts with up to 8 IDs and may adapt to 16 after a healthy group;
slow, large or exceptional groups return to 8. Single-item requests are used
for explicitly unsupported capability and existing exceptional representations,
subject to independent authorization. When the saved preference or
absent-setting compatibility fallback disables the capability, or the
optional handler is unavailable, return a generic global `404` JSON error with stable code
`unsupported_capability`; this explicit response is the only 404 that proves
route unavailability. A `404` with `target_unavailable` for the requested
gallery/photo is not capability evidence.
Do not fallback after `401` or `403`; do not fan out after `429`; honor the
full `Retry-After` value or defer the operation. For `502`, `503`, transport
failure or timeout, permit at most one retry of the same or smaller group
after bounded exponential backoff within the whole-operation deadline. Never
retry cancellation. On malformed `200`, MIME mismatch, size violation,
integrity failure or missing terminator, invalidate the batch and do not
automatically fan out; surface an error or retry a smaller group only after
the client has classified the fault and preserved the shared rate budget.
Local cooldown deferral reports the remaining bounded delay without claiming
that a new HTTP 429 was received.
The issue-scoped Windows `thumbnail-batch-viewer` audit separately measures
real Tk publication over an owned synthetic loopback server and actual client
cache; it is not PHP/FPM, proxy, HTTPS or physical-monitor acceptance.
Its setup compares actual client connections to an IPv4-only listener and a
listener bound to the first supported localhost resolver family. The two probes
send no HTTP and report DNS, TCP and wall timing separately from the unchanged
traversals. Completed transport observations exclude an active worker. This is
an internal fixture diagnostic with no TeX manual or product behavior impact;
the measurements alone do not establish the cause of a timeout.
The separate physical diagnostics retain at most 256 observations in 300
seconds and count attempts and bytes once per operation. Tail timing remains
unknown below 100 single-send samples; multi-send TTFB is not a per-request
sample. Worker dispatch groups are distinct from four-item GUI slices.

Connect/inactivity/whole-operation deadlines must be configurable within
finite caps and proven against the target host before enablement. The B1.2
5/10/30-second values are measurement candidates only, not adopted production
timeouts. A longer `Retry-After` takes precedence over the client deadline:
defer or stop rather than shorten the server's wait.

The `thumbnail-batch-http` audit suite exercises the prepared controller and
media owners against an owned PHP CLI server and disposable MariaDB. Its
120-photo synthetic single/group timing observations have no latency threshold.
The added connected client binds the first 120 ordered authorized descriptors
from the real media pages; observed page and row counts do not claim the full
gallery size. It uses isolated empty client disk caches for each cold/warm
cohort; capability discovery, identity and media-page proof binding are outside
the thumbnail timings. Server filesystem and opcode cache state is
not reset or controlled. It compares exact SHA/MIME in memory, checks zero new
HTTP for warm cache reads, and reports only sanitized numeric observations.
These measurements are not evidence of production, HTTPS, proxy or FPM
acceptance, and do not measure GUI paint or whole WinApp startup.
Per-request elapsed samples remain in the sanitized report with each summary;
strictly parsed passive Server-Timing response-header snapshots are included
when available and otherwise recorded as null. They are pre-stream header
snapshots, not total PHP execution or worker-occupancy timings. Failure reports
retain bounded numeric samples and fixed safe check/allowlisted response
diagnostics only; they omit raw response content and exception text.

## C1 current-SHA tree diagnostics

The central `issue-362-c1-tree-http` suite runs the existing tree-v1 endpoint
against an owned copied installation and disposable MariaDB. It records three
full/unchanged pairs at 500, 2000 and 10000 physical gallery rows. It then
checks title and parent changes, insertion and deletion, exact-scope revision
binding, and that a visibility-only change remains unchanged because visibility
is not in the tree projection. A valid live grant is confirmed before wrong
scope, wrong issuer, explicit revocation and account removal are refused. The
connected 403 case keeps the client's previously approved gallery-list scope
while the fixture downgrades only the current server grant to identity-only;
this verifies a real HTTP refusal rather than a local client scope precheck.
For 401, the real client exposes its specific terminal grant-rejection type,
confirms its session is expired and records that the request started; it does
not infer the HTTP status from that exception. The paired PHP route sample
records the actual 401 response. For 403, the client requires a typed response
error with status 403 and does not attempt legacy fallback.

The owned route returns generic 503 for 10001 rows and for an under-10000-row
catalogue with four-byte UTF-8 titles exceeding the existing 4 MiB body limit.
Each case verifies only the first two distinct, advancing 50-row legacy pages;
it does not replay a fresh 201-page traversal. A separate real
`LinkingSession` child discovers tree-v1, validates full and unchanged trees,
falls back from the optional 503 to those pages, and confirms 401/403 do not
fall back. It retains only booleans and counts.

The sanitized run report contains exact source SHA, immutable base, GitHub run
ID, row counts, cURL TTFB/elapsed/body bytes and allowlisted A2 passive
`Server-Timing` values when strictly parsed. Missing or malformed values are
unknown, never zero. Header phases end before body transfer. SQL time overlaps
model/tree time; do not sum or subtract those values. Three samples do not
establish percentile tails. Per-record `rows` is the fixture population for
full/unchanged/overflow requests, not the number returned by a refusal; legacy
page counts are recorded by the connected proof. Source SHA
`8c2418344e2e6bf241eec08b2ed6fc9e4322f3b8` passed this diagnostic in
[feature run 43111401537](https://github.com/klusik/PHP_gallery/actions/runs/43111401537).
It does not establish provider FPM, HTTPS, proxy or PhotosWorkspace mount
performance. No TeX manual impact: this is an internal
measurement owner and adds no user-facing procedure or configuration.

The separate Windows `issue-362-c1-photos-workspace` audit suite mounts the
production `PhotosWorkspace` with 10,000 flat synthetic root rows through the
real scheduled Tk event loop. It measures cold full display, a Windows DPAPI
disk-cache display while the current remote tree is held, then a changed full
tree reconciliation. Exact row ordering, rename, add/remove results, first-row
timing, full mount/reconciliation callbacks, poll callbacks, heartbeat gaps and
aggregate sibling-order lookup count/time/max are retained as numeric evidence;
there is no machine-specific PASS threshold. It then disconnects during a
pending mount slice and verifies both stale result generation rejection while a
new session is current and cancellation after disconnect. The fixture uses an
actual `LinkingSession`, replacing only its remote tree and media-page calls;
the production cache performs its real local serialization and DPAPI protection.
It does not contact PHP, MariaDB or an external server. The exact-SHA feature
run ([43111401537](https://github.com/klusik/PHP_gallery/actions/runs/43111401537))
qualified this synthetic diagnostic; installed Windows behavior and production
latency remain unproven. No TeX manual impact: this internal measurement owner adds no
user-facing procedure or configuration.

The actual Windows Tk baseline on `d8f6c09e03e144ba352f8250c32fb7bac57e7dc8`
([run 43109273429](https://github.com/klusik/PHP_gallery/actions/runs/43109273429))
passed the workspace diagnostic while BASIC failed one declaration-doc type
finding. This is runtime evidence, not qualification of that source candidate.
Cold display took 8,710.068 ms and warm disk display 9,866.600 ms; the existing
ordered-index owner accounted for 4,002.571 and 4,787.130 ms respectively across
10,000 calls each. Mount p95 was 64.816/80.726 ms, versus 32.954 ms for the
single observed disk load/decrypt/parse. This identifies repeated sibling
enumeration during insertion as a measured client cost. The renderer now reads
the root tail once per render call and appends higher IDs, preserving the
general ordering path for lower IDs. No persistent index or server cache is
introduced. The post-change C1 report for
`8c2418344e2e6bf241eec08b2ed6fc9e4322f3b8` ([feature run 43111401537](https://github.com/klusik/PHP_gallery/actions/runs/43111401537))
measured cold display at 4,222.154 ms and warm disk display at 3,823.707 ms;
the 10,000 `_ordered_index` calls fell to zero. Cold/warm mount p95 was
4.432/4.146 ms. First-row timing was 222.955/124.360 ms versus the baseline
186.106/119.967 ms, so this result does not show faster first-row display.
Changed-tree reconciliation was 3,160.253 ms versus 3,185.736 ms. These are
single hosted synthetic observations, not installed-client performance or
cross-machine tail latency.

The PHP stream fixture also removes an independent second photo file after the
first complete item boundary. It drives the production stream and verified-file
owners with real temporary files and fake database/auth/policy boundaries, then
checks that only that item becomes neutral `unavailable` while the third item
and terminal remain valid. This is synthetic per-item path re-resolution
coverage, not production filesystem-race, FPM or deployment acceptance.

## D staging acceptance and rollout gate

The default-on Admin preference is not acceptance against the actual target
deployment. Before relying on batching, record acceptance for the real PHP version and
FPM/FastCGI process model, production-like reverse proxy, HTTPS termination,
response buffering/flush behavior, body-size and timeout limits, and effective
worker/concurrency budget. Exercise authenticated public and Admin scopes,
wrong issuer, grant revocation, mixed valid/missing/cross-gallery IDs,
oversized files, disconnects and cancellation. Measure first-item and complete
latency, peak PHP memory, worker occupancy under concurrent calls, errors,
429 handling, and single-route fallback behavior. Require no authorization
bypass, no unbounded memory growth, no retry storm, strict framing/integrity,
and no regressions to existing photo-read rate limits. Record environment,
configuration, exact candidate SHA, evidence and operator decision in the
separately authorized rollout record; synthetic/localhost results do not count.
Administrators can disable the capability through Admin > Features; once a
choice is saved, it takes precedence over the legacy environment fallback.

The WinApp session fixture also exercises the no-capability path through the
original thumbnail GET for gallery-list, upload/media and Admin-media grants,
and verifies that an old-route HTML 503 is surfaced without hidden batch
traffic. With the batch capability negotiated, an unexpected HTML 404 remains
a fail-closed batch error; only the exact JSON `unsupported_capability`
response selects the optional-route fallback. These WinApp session fixtures do
not emulate an archived old WinApp binary; the separate B2 archived-source
cohort is described below. Neither replaces the target-host matrix.
The session owner also verifies that queued listing/identity or upload work is
dispatched between two successive real batch API calls, and that a cancelled
batch waiting on the existing FIFO session gate withdraws without HTTP while an
incumbent retains ownership. This proves local client admission ordering only;
it does not measure deployed worker fairness or target-host scheduling.
The native viewer fixture also queues a decoded later frame from a partial real
batch before an authoritative page refresh, then checks the old generation's
raster is released and the refresh waits for that batch worker. This is an
in-process Tk lifecycle regression, not target-host evidence. No TeX manual
impact: it adds no shipped behavior, configuration or user procedure.

Sanitized WinApp timeout diagnostics now retain the finite transport step
observed at failure (`slot`, `dns`, `connect`, `tls`, `request`, `headers` or
`body`) beside the existing media route. The focused fake-transport checks
verify header/body attribution and redaction. This is diagnostic evidence only:
it does not identify a remote cause, establish an issuer URL or qualify target
network behavior.

Qualify both the adaptive client's initial group of 8 and its healthy group of
16 on the actual target deployment before treating hosted evidence as
deployment acceptance. Once enabled, the client may increase to 16 after a
healthy group; monitoring does not imply an operator-only 8-group mode. Keep
the capability revocable independently of client release. If deployment
failures or regressions are observed, set the Admin preference OFF. Missing
evidence remains unverified rather than accepted. B1.3
specifies this policy and protocol; it does not assert staging, production, or
release acceptance.

## Documentation impact

The Admin > Features policy and compatibility behavior are documented in all
four TeX sources; tracked PDFs remain unchanged. The internal compatibility
fixtures add no separate user procedure. The separate
[runnable-checkout handoff rule](../../app/AGENTS.md#runnable-checkout-handoff)
is also mirrored in all four TeX sources. The default-on preference does not
certify target-host acceptance. The D compatibility owner
archives the exact `winapp/uploader` package and `winapp/VERSION` from
commit `1c1e2e9145cd92cb2f0ee731da5655c900d4d3e1` in the disposable B2 fixture.
That actual 1.0.10 `LinkingSession` performs discovery, identity,
gallery/media-page reads and original single-thumbnail GETs against the current
PHP/MariaDB fixture. The owned observer delegates each request and response to
the real HTTP transport, verifies a public image, a public 404 for a private
image and an Admin read of that private image, and requires zero batch POSTs
despite the advertised batch capability. It compares image bytes to two
actual B2 single-route reference reads and points the archived client's cache
root inside the temporary fixture. A reciprocal cohort extracts the required
PHP source closure from that same immutable commit, verifies its 1.0.10
manifest, and starts it through the existing disposable fixture owner; the
archived seed runs against its own migrations and scanner. The current
`LinkingSession.media_thumbnails` API discovers the old server without the
batch capability, reads two seeded photos through the original GET route, and
matches MIME and SHA-256 against actual old-server reference GETs. The HTTP
observer delegates to stdlib transport and the report requires two thumbnail
GETs and zero batch POSTs. The B2 report records both source commits and
sanitized route counts. A passing paired run provides bidirectional source
compatibility evidence for the pinned revisions in owned loopback PHP/MariaDB
fixtures. It does not qualify an installed binary, another historical version,
or FPM/proxy/HTTPS deployment. The fixture assertions themselves change no user
operation or manual procedure; the independent handoff clarification is maintained
in the four manual sources.
