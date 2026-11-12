# Issue #362 B1.2: HTTP transport and failure qualification

The production-facing request, authorization, limits and rollout contract is
documented in [B1.3 thumbnail batch v1](issue_362_thumbnail_batch_v1.md).

## Scope, immutable evidence and non-goals

B1.2 qualifies the **experimental, hosting-independent** thumbnail batch
wire candidates over real disposable loopback HTTP, including incremental
delivery, buffering, faults, resource use and a single-request reference.
This is **not** a production endpoint, authorization implementation, WinApp
transport update, capacity test of a deployed host or general-purpose HTTP
client library.

- Issue: [#362](https://github.com/klusik/PHP_gallery/issues/362).
- Branch: [feature/362-b1-thumbnail-batch-protocol-benchmark](https://github.com/klusik/PHP_gallery/tree/feature/362-b1-thumbnail-batch-protocol-benchmark).
- Baseline: `develop` at `cd8f2b3d98c5bad1623d951c94979de777d7d2c7`.
- Predecessor: [B1.1 protocol specification and file-backed benchmark](issue_362_b1_protocol_benchmark.md).
- Historical B1.1 exact-source verification: [B1 run 43021741798](https://github.com/klusik/PHP_gallery/actions/runs/43021741798) and [BASIC 43021741960](https://github.com/klusik/PHP_gallery/actions/runs/43021741960), both `d8aa4694e8746927173752acf5d924f3e1a67662`.
- **Frozen numerical measurement source for tables below:** [B1.2 run 43028800438](https://github.com/klusik/PHP_gallery/actions/runs/43028800438), **SUCCESS** on exact `5b38d9464d8daca126ec168ec57d3f46b3e069be`, alongside [BASIC run 43028800499](https://github.com/klusik/PHP_gallery/actions/runs/43028800499), **SUCCESS** on the same SHA.
- Retained [PHP 8.1 numerical JSON/Markdown artifact](https://github.com/klusik/PHP_gallery/actions/runs/43028800438/artifacts/13859172425) and [PHP 8.5 artifact](https://github.com/klusik/PHP_gallery/actions/runs/43028800438/artifacts/13859112524). They report more fields and p95 values than the compact tables here. This historical measurement stays attributable to its original exact source, even after subsequent test-evidence changes.
- The B1.2 code also adds single-reference Python memory measurement and cancellation-duration evidence after this historical run. These **later-source fields must be accepted from their own successful exact-SHA run**, not inferred from the historical artifacts.

No production controller, route, rate policy, authorization scope, database,
media cache, thumbnail generator, viewer, WinApp, deployment or release
changes are part of this work. No local user-machine tests, hosting-provider
configuration, installation, composer, framework, Redis, queues, daemons,
background cron or production traffic are required.

## Test infrastructure and reproducibility

The existing B1.1 PHP producer in
`tools/perf/issue_362_b1_producer.php` now accepts an optional per-item
callback and can write its unchanged B1.1 framed or multipart body to
`php://output`. The original CLI entrypoint remains usable and its
original tests remain included. It separately measures hash_file(SHA-256)
work and producer elapsed time.

A disposable PHP built-in web server runs only inside GitHub Actions and
binds to `127.0.0.1` on a temporary port:
`tools/perf/issue_362_b1_http_router.php` serves `/batch`,
`/single`, `/status` and `/health` for synthetic test manifests only.
It requires `PHP_SAPI=cli-server`, `GITHUB_ACTIONS=true` and
`GALLERY_WORKFLOW_ENABLE=disposable-only`; the HTTP router rejects requests
with a non-loopback peer. The Python orchestrator in
`tools/perf/issue_362_b1_http_benchmark.py` owns temporary image data,
the PHP child process, request sequence IDs and metrics, and reaps the
server and closes sockets at the end.

The Python HTTP client in `tools/perf/issue_362_b1_http.py` uses
`http.client` and **reuses `FramedParser` and `MultipartParser`**
from B1.1. It does not duplicate wire parsing. HTTP status, exact content
type and content-length bounds are evaluated independently of the wire
parser. A valid B1.1 final marker does **not** convert an HTTP
Content-Length mismatch into a successful batch.

Input fixtures are seeded, deterministic Pillow-generated real JPEG
and WebP files. Their byte counts, MIME names and SHA-256 values are
identical across the three transport alternatives. The Ubuntu
matrix uses PHP 8.1 or PHP 8.5 with Python 3.14. Windows Python 3.14
qualifies parser and HTTP-policy regression code without starting PHP.
The original file-backed B1.1 tests still run in the same targeted
workflow.

The temporary built-in PHP server is **not a replica of PHP-FPM,
Apache, nginx, a reverse proxy, TLS or a production worker pool**.
Its finite request queue and flush behavior must not be extrapolated
into claims about production throughput, worker contention or live
authorization. External routing and HTTPS performance remain unknown.

## Candidate wire protocols and HTTP profiles

The two batch formats are byte-for-byte B1.1:

| Candidate | HTTP content type | Per-item completion | Required final proof |
| --- | --- | --- | --- |
| Binary framed v1 | `application/vnd.php-gallery.thumbnail-batch.v1` | Bounded frame, strict metadata, exact payload, SHA-256 | `E` plus completed count and EOF |
| Strict multipart/mixed | `multipart/mixed; boundary=...` | Bounded ordered MIME headers, exact payload, CRLF, SHA-256 | Required closing delimiter and EOF |
| Single raw reference | Actual JPEG/WebP MIME with ETag | One isolated complete SHA-256-verified image per HTTP request | The raw image and matching locally known digest |

The MIME boundary is deterministic only for the **test fixture**.
Production use must select a per-response safe, unpredictable boundary
and prevent delimiter collisions. The raw single-item reference is
an isolated approximation of the legacy one-request-per-thumbnail
transport principle. It is **not** the production endpoint, its
authentication policy, or a complete measurement of WinApp behavior.

### Streaming versus full buffering

- **Stream:** PHP writes each bounded frame/part in order, requests a
  flush after each item, and Python consumes the HTTP body incrementally.
  Timestamps indicate observed client-read completion, not producer
  intent or packet capture. A `flush()` call alone is never proof
  of forwarding.
- **Buffer:** PHP captures the full body with its output buffer, then
  sends the completed body. Python also buffers it before invoking
  the same incremental parser. The wire format does **not** change.
- **Delayed:** a controlled, disposable synthetic producer inserts
  25 ms after each item. A slow reader uses smaller reads and an
  additional small client-side delay. This is a deliberate stressor,
  **not** a measurement of any real network provider or proxy.
- **Malformed-response modes:** HTTP statuses, MIME corruption,
  body truncation, overdeclared HTTP length, digest/ETag/version
  corruption, body-size admission refusal and server stalls are
  specifically injected by the loopback harness.

## Measurement methodology

Every ordinary measured cell has **three repetitions**. Descriptive
p50 is the median; interpolated p95 is recorded in JSON and the hosted
Markdown artifacts. Three samples do not justify p99 or a stable
prediction of tail latency. No shared-runner elapsed time is a hard
CI PASS/FAIL threshold. Strict assertions apply instead to payload
integrity, HTTP admission, item order, limits, errors, cancellation
and cleanup.

The Python client uses `time.perf_counter_ns()` to measure actual
request start, receipt of HTTP response headers, receipt of the first
readable body byte, first fully validated item and complete body/parser
termination. The `first_verified_ms` field requires a complete
item, correct metadata and matching SHA-256. `complete_ms`
requires all expected items and the final protocol marker. Python
parser execution is accumulated independently of socket reads. PHP
uses `hrtime(true)` for producer duration and SHA-256 work.
PHP `memory_get_peak_usage(true)` reports allocator peak.
Python `tracemalloc` reports tracked allocation peak, not total
interpreter or extension allocations. Where available,
`/proc/self/statm` records a process-wide RSS observation, **not**
a request-isolated peak. A separate raw-single reference records
N actual sequential HTTP requests and aggregates their wall time.

These metrics have different scopes and **must not be summed** as an
end-to-end time. HTTP body bytes and protocol overhead are tracked
separately, but transport-layer header, TCP/TLS and handshake bytes
are **not** included in the body-byte totals. A reference with zero
extra *body* framing does not imply zero HTTP overhead.

### Input distribution

The same four input counts are used for each candidate: **8, 16, 24
and 32**. In the cold profile every thumbnail has status `ok` and
binary content is transmitted. In the mixed warm profile every fourth
item sends new image bytes and the remaining 75% send a
`not_modified` validator; their local image bytes are counted
separately as locally cached, rather than as transferred payload.
The raw reference issues an HTTP 304 for its corresponding validated
test ETag cases.

A synthetic ETag is **not authorization**. Before any production B3
cache reuse, the client must prove a currently authorized grant,
gallery/photo scope and exact matching local content. Neither the
synthetic benchmark nor the SHA-256 payload check establishes this.

## HTTP benchmark results: cold, real loopback

The tables below are the published measurements of PHP **8.5.12**,
Python **3.14.10**, exact SHA `5b38d946...`,
[run 43028800438](https://github.com/klusik/PHP_gallery/actions/runs/43028800438).
Durations are p50 milliseconds. Each batch variant made exactly
**one** HTTP request, the reference made **N** sequential requests.

| Count | Framed stream first / complete | Framed buffer first / complete | MIME stream first / complete | MIME buffer first / complete | Raw single first / complete |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 8 | 1.67 / 3.39 | 4.92 / 4.94 | 1.85 / 3.21 | 3.50 / 3.52 | 0.41 / 5.55 |
| 16 | 2.02 / 4.76 | 7.43 / 7.48 | 2.54 / 5.52 | 6.09 / 6.12 | 0.40 / 10.99 |
| 24 | 2.79 / 6.86 | 13.74 / 13.88 | 3.08 / 7.73 | 14.40 / 14.46 | 0.40 / 16.31 |
| 32 | 3.29 / 8.81 | 17.19 / 17.40 | 3.75 / 10.47 | 18.53 / 18.61 | 0.42 / 22.09 |

The comparable, successful PHP **8.1** run on the same workflow
demonstrated version-dependent timing, not identical host conditions:

| Count | Framed stream first / complete p50 ms | MIME stream first / complete p50 ms | Raw reference first / complete p50 ms |
| ---: | ---: | ---: | ---: |
| 8 | 5.60 / 8.26 | 4.17 / 6.22 | 0.51 / 9.21 |
| 16 | 7.38 / 12.08 | 7.02 / 11.30 | 0.55 / 19.61 |
| 24 | 10.27 / 17.54 | 9.97 / 16.53 | 0.51 / 28.11 |
| 32 | 13.29 / 23.12 | 13.12 / 22.13 | 0.52 / 36.59 |

Neither candidate is uniformly faster for *first* image than the raw
reference: a small raw HTTP request can finish earlier, especially
on a low-latency loopback. Batching's observed benefit here is fewer
requests and shorter **whole-set** completion at larger counts,
particularly with actual streaming. If an intermediary fully buffers
a response, the early-image advantage disappears.

### Transferred body bytes and framing overhead

| Count | JPEG/WebP source KiB | Framed body / overhead KiB | Multipart body / overhead KiB | Raw single body / framing KiB |
| ---: | ---: | ---: | ---: | ---: |
| 8 | 688.7 | 690.7 / 2.0 | 691.5 / 2.8 | 688.8 / 0.0 |
| 16 | 1377.4 | 1381.3 / 3.9 | 1382.9 / 5.5 | 1377.4 / 0.0 |
| 24 | 2066.3 | 2072.2 / 5.9 | 2074.5 / 8.2 | 2066.3 / 0.0 |
| 32 | 2754.9 | 2762.8 / 7.9 | 2765.9 / 11.0 | 2754.9 / 0.0 |

Rounding explains a slight discrepancy between some source and raw
reference numbers. For 32 items binary framed saves about **3.1 KiB**
relative to MIME, a small gain compared with image payload size.
This supports simpler framed semantics, **not** a claim of major HTTP
wire-throughput superiority.

## Mixed warm-cache measurements

These are synthetic protocol results with 25% new bytes and 75%
`not_modified`, not authenticated production caching.

| Count | Framed body KiB | MIME body KiB | Raw reference body KiB | Framed stream complete p50 ms | MIME stream complete p50 ms | Raw sequential complete p50 ms |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 8 | 14.3 | 15.2 | 12.6 | 0.93 | 1.11 | 5.31 |
| 16 | 28.6 | 30.3 | 25.2 | 1.36 | 1.61 | 10.58 |
| 24 | 42.9 | 45.4 | 37.8 | 1.71 | 2.05 | 15.81 |
| 32 | 57.2 | 60.6 | 50.4 | 2.03 | 2.54 | 21.05 |

A conditional raw single transfer still incurred one HTTP request
per image; most returned HTTP 304 without an image body. A batch
consolidated those validators into one response. This benchmark
explicitly does **not** model grant revocation or independent
authorized cache proof.

## Buffering, slow consumers and PHP worker exposure

On the PHP 8.5 32-image **cold framed** scenario:

| Metric | Stream | Fully buffered |
| --- | ---: | ---: |
| First verified image p50 | 3.29 ms | 17.19 ms |
| Entire batch p50 | 8.81 ms | 17.40 ms |
| PHP allocator peak | 2.00 MiB | 9.40 MiB |
| Python tracked allocation peak | 0.62 MiB | 8.36 MiB |
| PHP producer time p50 | 3.98 ms | 6.37 ms |

PHP full buffering's memory increased with response size: at 8/16/24/32
cold framed images approximately **4.00 / 6.00 / 8.06 / 9.40 MiB**,
versus the observed **2.00 MiB** in the stream mode. Python full-body
allocation likewise rose to approximately
**2.32 / 4.32 / 6.38 / 8.36 MiB**, versus approximately **0.61 MiB**
in streaming mode. These are allocator and tracked-allocation numbers,
not universal PHP/OS RSS limits.

A controlled 25 ms per-item producer delay highlighted the hazard of
assuming `flush()` guarantees early delivery. On PHP 8.5,
32-image cold framed completion was approximately **8.51 seconds**
and first validation approximately **28.49 ms** in the deliberately
slow-read/fragmented case, while the PHP producer's measured delayed
duration was approximately **0.81 seconds**. Client read size,
producer delay and local TCP buffering interact here. These
observations are reproducible on the sampled runner but are not
portable predictions of a particular proxy or production host.
A request holding a PHP process while a client reads slowly remains
a capacity concern; this built-in server does not quantify an FPM
worker pool or concurrency limit.

### CPU and per-item SHA-256

PHP's `sha256_ms` is recorded independently of `producer_ms`
in the new JSON. Its ratio depends on source size and fixture cache.
The B1.1 digest is necessary for integrity but not for authorization.
A production implementation must avoid unbounded filesystem reads,
reuse trusted content hashes only under a correct invalidation model,
and bound total PHP work. No meaningful production CPU percentage or
disk-cache penalty can be inferred from these short synthetic runs.

## HTTP failures and cancellation

The isolated CI client rejects responses before feeding them to the
wire parser when global HTTP admission fails. Both candidate formats
successfully exercised the following deterministic probes.

| Probe | Result and required classification |
| --- | --- |
| 200 with supported MIME | Parse exact count, SHA-256 and final marker |
| 400 | Refuse malformed request; do not retry unchanged input |
| 401, 403 | Stop as authorization/access failure; never switch to another route as bypass |
| 404, 405 | Optional unsupported-route fallback only after explicit capability and independent single-route authorization proof |
| 429 | Stop fan-out, honor rate budget; classify valid/missing/malformed `Retry-After` without bypass |
| 502, 503 | Bounded retry policy only, no automatic N-request explosion |
| HTML, wrong or absent MIME | Reject before binary parsing |
| Wrong or over-limit HTTP length | Reject length mismatch or oversized declaration, even if body end marker exists |
| Truncated header, first image, between images, missing end | Fail complete batch; never publish an incomplete image |
| Corrupt SHA-256, wrong ETag, duplicate ID, unknown wire version | Refuse invalid data or metadata |
| Invalid body, oversize body declaration | Fail closed within the 16 MiB experimental cap |
| Timeout before headers, before body or between chunks | Classify bounded transport timeout, close socket |
| Cancel before headers, after the first body byte, **during first-image payload**, after first/two verified items, during full buffering or after several slow-client reads | Return cancellation state and close connection; never publish an incomplete first image |

After truncation **between** images, a previous validated item can
exist even though the response is incomplete. After a missing final
marker, all 8 individual items can have passed their hashes while
the **batch remains invalid**. In the wrong HTTP Content-Length
case the parser can validate eight items, but the enclosing HTTP
operation must still fail. Nothing here grants permission to display
or cache those items across a changed authorization scope.

Malformed or missing `Retry-After` does **not** mean permission to
ignore HTTP 429. The policy tests include valid delta-seconds,
HTTP-date, negative, malformed and absent header values. The Python
helper's 120-second clamp bounds **reporting only**; the experimental
client performs no retries. A future B3 retry scheduler must honor
the complete server-prescribed wait or defer/reject the operation,
not silently shorten a longer rate-limit period.

The distinct cancellation-during-first-image probe requires actual
incremental bytes in the first item payload, before its SHA-256 succeeds.
The slow-client probe consumes several small chunks with deliberate delay
before cancellation; neither test is satisfied merely by aborting at the
first HTTP response byte.

Cancellation has no implicit retry. The owned Python HTTP connection
is always closed, the PHP server is terminated and reaped by the
fixture context manager, and disposable files live inside
`TemporaryDirectory`. The final isolated listener probe verifies
the server is no longer reachable. Neither benchmark nor CI
invokes a long-lived background service. A cancellation after
validated earlier items is intentionally different from treating
the entire envelope as a success.

### Bugs discovered by real negative controls

- A response with intact protocol marker but **incorrect HTTP
  Content-Length** originally passed. The client now compares the
  observed body length to the declared length before success.
- An initial bad-ETag mutation did not match an escaped JSON
  string and therefore left the fixture valid. The synthetic fault
  injector now demonstrably corrupts the actual ETag.
- The first-byte timing of the raw single reference was re-anchored
  to the start of each individual request rather than an imprecise
  later timestamp.

The exact hosted regressions detected and qualified these fixes
without disabling any test, relaxing an admission limit or touching
production logic.

## Fallback and backward compatibility contract for B3

These outcomes are **policy design and test assertions**, not
implemented WinApp routing:

1. If the optional batch capability/route is positively identified
   as unavailable, and independent current single-item access is
   authorized, return to the existing single-thumbnail API.
   A generic 404 for one missing image is not proof that an entire
   batch API is unsupported.
2. On 401 or 403, stop and surface the access failure. Do not make
   new legacy calls to avoid the denial.
3. On 429, preserve the shared rate budget and server retry semantics.
   Do not fan out one denied batch into many denied single calls.
4. On 502, 503, network timeout or disconnect, allow only a bounded
   retry decision with a total deadline and backoff; never an
   unbounded automatic single-request storm.
5. On malformed 200, MIME mismatch, failed integrity/termination or
   exceeded batch limits, invalidate the batch result. After
   investigation, a smaller **authorized** batch may be attempted.
   The current decision-contract prototype returns
   `reduce_batch_after_investigation` for eligible cases above 8
   items; it does not issue a retry itself.
6. Cancellation means stop. Previously verified items remain usable
   only after independently proving their live authority and cache
   eligibility; the prototype never claims that proof.
7. If a partial response includes independent `unavailable` or
   `oversize` entries, preserve explicit statuses, without
   inventing more image data or enlarging limits.

## Preliminary batch-size and timeout recommendation

These are **provisional** design choices and must be independently
validated on a controlled staging environment before B2/B3 launch:

| Parameter | B1.2 design recommendation | Confidence |
| --- | --- | --- |
| Default batch size | **16** images | Trade-off supported by synthetic HTTP data, not a production optimum |
| Maximum batch size | **32** images only after admission/resources validation | Tested experimental cap, not a required host ability |
| Preferred v1 wire | **Binary framed v1** | Simpler envelope, modest byte savings; both passed HTTP |
| Experimental total response hard cap | **16 MiB** | Tested B1.1/B1.2 safety guard, not a desirable default target |
| Initial deployed response budget for later review | **8 MiB or less** | Unverified conservative proposed starting point, must be capability/environment-aware |
| One thumbnail | **4 MiB** absolute test cap | Tested structural admission, may warrant a lower production default |
| Connect timeout | **5 seconds** | Unverified B3 staging candidate |
| HTTP inactivity timeout | **10 seconds** | Unverified B3 staging candidate |
| Whole-batch deadline | **30 seconds** | Unverified B3 staging candidate |
| Reduction policy | 32 → 16 → 8; stop at 8 with explicit error/fallback rules | Proposed B3 policy, never automatic without authorization/rate checks |
| User cancellation | Close response and do not schedule retries | Tested client decision; production lifecycle not yet implemented |

The 16-image default balances request consolidation against the
higher memory and first-item latency of 24/32 when an intermediary
buffers. For example at 16 cold framed images the sampled PHP 8.5
streaming whole-batch median was **4.76 ms**, versus **8.81 ms**
at 32, and full-buffer PHP peak **6.00 MiB** versus **9.40 MiB**.
The smaller request remains available when resource evidence is
weak. No test proves 16 optimal across hosting environments or
image distributions. Payload-size admission must be independent
of item-count limits; 32 images of 4 MiB each cannot fit in a
16 MiB response cap.

## Portability and unknowns

**Demonstrated:** PHP 8.1/8.5 syntax and producer interoperability
on separate hosted Ubuntu runners, Python 3.14 portable policies on
Windows, strict B1.1 framing in real localhost HTTP, client behavior
under intentionally induced buffering/fragmentation/corruption,
bounded per-item validation and deterministic error/fallback
classification. Both protocols parse all-at-once bytes or arbitrary
fragments without weakening integrity.

**Not demonstrated:** any production-provider configuration; HTTPS
latency or TLS handshake; mod_php/FastCGI/FPM behavior; reverse-proxy
stream flushing; HTTP/2 multiplexing; effective PHP worker count;
cross-user concurrency; network loss and retry behavior; production
auth/grant revocation, cache proof or content permissions; server
disk-cache effects; UI rendering and Tkinter paint time; real upload/
thumbnail generation; deployment readiness. Do not transform a
localhost microbenchmark into a universal latency/worker guarantee.

A fully buffering intermediary can remove the time-to-first-image
benefit of streaming. The protocol must remain correct in that case.
Conservative timeout and response-size negotiation must not depend
on a specific proxy brand, hosting company or runtime configuration.

## Concrete B1.3 scope

B1.3 should freeze a security-reviewed specification before any
production implementation:

1. Final version and capability negotiation, HTTP method/path,
   request schema, IDs, order, count, response MIME and framing.
2. Authentication and access checks **before bytes**, per-image
   authorization and gallery/grant scope, rate-budget accounting
   for both batch and fallback without denial bypass.
3. Strong ETag generation, conditional status semantics and proof
   required to use local cache data under current authorization.
4. Explicit `ok`, `not_modified`, `unavailable` and `oversize`
   item statuses; global errors, partial completion, interruption,
   cancellation and safe previously validated item policy.
5. Bounded delivery under streaming **and** forced buffering,
   content-length rules, per-item and total byte admission, server
   occupancy, resource/time budgets and controlled size reduction.
6. Compatibility across optional unsupported capability, legacy
   single-thumbnail API, old clients/new servers and mixed versions.
7. Staging plan covering PHP-FPM/reverse-proxy/HTTPS with real
   authorization and resource controls, without conditioning the
   specification on a named infrastructure vendor.
8. Only after acceptance, separately scoped **B2** PHP endpoint and
   **B3** WinApp client work. No implementation of either is part
   of B1.2.

**No TeX manual impact:** B1.2 introduces internal disposable
test/measurement infrastructure only. It changes no public product
behavior, supported runtime configuration or user/maintainer
operating procedure. The permanent issue-specific technical
documentation and Actions artifacts own this evidence.
