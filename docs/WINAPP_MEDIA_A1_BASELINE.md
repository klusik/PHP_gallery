# WinApp media performance: A1 baseline and measurement handoff

**Author:** Rudolf Klusal  
**Related issue:** #362, stage A1  
**Source reviewed:** `develop` at `1c1e2e9145cd92cb2f0ee731da5655c900d4d3e1` (PHP Gallery 1.0.10, 2026-11-10)  
**Status:** A1 partial execution. Current-source audit and reproducible isolated PHP CLI subcomponent measurements are completed; end-to-end WinApp/API/MariaDB/WEDOS baseline remains **UNKNOWN** and A1 is **NOT COMPLETE**.

## Goal and boundaries

A1 must establish a reproducible baseline before choosing a new thumbnail transport, changing a server cache, or adding significant instrumentation. The primary questions are:

1. How many HTTP operations and how much latency are actually attributable to thumbnails, media metadata, tree discovery, authentication, and local UI work?
2. What proportion of startup latency is server/API processing versus networking, locally persisted catalogue loading, and Tk Treeview population?
3. Does the measured request pattern create meaningful **PHP process occupancy**, not just a high count of sequential GETs?
4. Which latency measurements and request categories are unavailable with today's instrumentation, and therefore belong in A2?
5. What is the minimum safe staged benchmark needed to evaluate batch sizes in B1?

All A1 source changes must be diagnostics/benchmark-oriented only. No batch transport, server tree materialization, new public API, release, production deploy or load testing is authorized by this handoff.

## Verified code inventory

| Area | Existing behavior / entry point | A1 evidence target |
| --- | --- | --- |
| Linked transport | `winapp/uploader/app_linking.py`, `_FairRequestGate`, `_run_linking_transport`, `_native_media_read_once` | Per-session serialized admission, transport slot, retry, TTFB and transfer phases |
| Media counters | `MediaReadTimings` in `winapp/uploader/app_linking.py` | Separate network, cache disk, gate, TLS/TCP, response body, and unsuccessful requests |
| Native viewer | `winapp/uploader/native_gallery_viewer.py`, `_load_next_thumbnail`, `_load_thumbnails`, `render_diagnostics` | Four-item GUI batches are **not** HTTP batches; network thumbnail reads are currently per image |
| Viewer refresh | `_schedule_revision_check`, `_check_revision`, `_queue_thumbnail_proof_refresh`, `_start_refresh` | 30-second revision eligibility, 60-second metadata proof age, and number of metadata page calls |
| Catalogue lifecycle | `winapp/uploader/photos_workspace.py`, `_request_page`, `_accept_complete_catalogue`, `_accept_page` | Tree-v1 full/unchanged/fallback outcome, stage durations, first usable tree, UI slice cost |
| Local tree cache | `winapp/uploader/gallery_catalogue_cache.py`, `GalleryCatalogueCache.load/save` | Max 10,000 rows / 8 MiB, binding to issuer/account/scope, Windows DPAPI; cache is display-only |
| Tree API | `app/services/app_linking.php`, `app_linking_gallery_tree`; `app/controllers/app_linking.php`, `cms_app_linking_galleries` | Existing `galleries:tree-v1`, max 10,000 rows / 4 MiB; fresh SQL+hash even on an unchanged revision |
| Paged fallback | `APP_LINKING_GALLERY_PAGE_SIZE = 50`, `PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT = 10`, `PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS = 5000` | Initial 10 pages / 500 rows, then 5-second pacing for remaining pages |
| Tk catalogue display | `PHOTOS_CATALOGUE_CACHE_BATCH_SIZE = 64`, `PHOTOS_CATALOGUE_CACHE_BATCH_DELAY_MS = 16` | Display batches are *local GUI work*, not 64 HTTP requests |
| Media PHP | `app/controllers/app_linking/media.php`, `app/services/app_linking_media.php` | Bearer/authorization/rate admission, gallery/photo DB reads, candidate filesystem reads, ETag hashing and transfer |
| Rate budgets | `app/services/app_linking_rate_limits.php` | Photo-byte requests use separate buckets; reject the assumption that 24 images should count as one authorization/budget unit |
| Server diagnostics | `app/services/admin_test_runs.php`, `app/services/admin_test_runs/summary.php` | Existing opt-in Admin Test Run measures *its selected traced request set*, not all WEDOS PHP workers |
| HTTP monitor | `winapp/gallery_http_monitor.py` | Existing public GET monitoring has controlled bursts and no bearer access: it is **not** a safe passive substitute for production API profiling |

The current WinApp transport constrains linked operations to one process transport slot and a serialized session gate. This does **not** establish the actual number of PHP processes in use by unrelated site traffic, the browser, other clients, scheduled PHP work or WEDOS infrastructure.

## Historical client evidence: observed, not a complete baseline

Two redacted installed WinApp **1.0.10 / Python 3.14.10** diagnostics were provided during the incident on 2026-11-10.

| Counter | Earlier sample | Later sample | Interpretation |
| --- | ---: | ---: | --- |
| `http_requests` (thumbnail) | 208 | 208 | Cumulative HTTP GET attempts in the tracked viewer; no increase between snapshots |
| `source_network` (thumbnail) | 208 | 208 | Cumulative network-sourced thumbnails |
| `batches` (thumbnail GUI worker) | 103 | 103 | Client scheduling batches, **not** multi-photo HTTP requests |
| `proof_refreshes` | 96 | 104 | +8 metadata-refresh attempts; **not** +8 thumbnail binary GETs |
| `ttfb_ms` (thumbnail only) | 159150.29 | 159150.29 | Sum of image-response header waits, not server execution time |
| `network_ms` (thumbnail only) | 167259.07 | 167259.07 | Accumulated thumbnail transport timing |
| `gate_wait_ms` (thumbnail only) | 50039.72 | 50039.72 | Accumulated client gate wait; not PHP worker wait |

Simple derived observation: `159150.29 / 208 ~= 765.15 ms` mean thumbnail TTFB **for this accumulated sample**. This does not identify PHP CPU, database load, root cause of 502/503, p95/p99, or requests/sec.

The earlier log included an HTTP **503** for `app_linking_galleries`; the later diagnostic recorded an HTTP **502 / HTML** failure on `app_linking_media` with `kind=refresh`. The later Photos activity log records `2026-11-10 14:54:46` in the client's local date/time representation; it does not include an explicit offset, so do not present that exact string as a verified UTC timestamp. This HTML response is consistent with an upstream gateway failure, but its actual origin has **not** been established.

**Missing evidence:** full timeline start/stop, timestamped per-endpoint request durations/counts, per-request failed operation timings, CDN/proxy and PHP timing split, PHP CPU/worker occupancy, WEDOS access/error logs, staging host benchmark, and measured cold/warm comparison.

## Measurement protocol for A1

### Sampling semantics

Use ISO 8601 timestamps with **UTC offset `Z` and one-second precision**, taken from a real clock for event timestamps. For elapsed measurements use a monotonic high-resolution timer. Record test host/environment, exact branch SHA, build/version, scenario, cache state, run/window start/end, measured requests, and optional externally available server traces.

Never combine lifetime cumulative counters from one opened viewer with a newly measured wall-clock interval. Where only cumulative counters exist, show **two snapshots and an explicit delta**, or mark missing/unknown. p50/p95/p99 require a population of **individual** durations; do not estimate percentiles from sums. Avoid adding overlapping timing phases. Distinguish attempted requests, completed requests, retries and local cache hits.

### Required scenarios (disposable test or authorized staging only)

| Scenario | Setup | Measurements |
| --- | --- | --- |
| T1 cold thumbnails | A fixed authorized album with at least 120 distinct thumbnails and empty *test* cache | First tile; first visible row; viewport complete; GET count; bytes; endpoint timing; UI spikes |
| T2 warm thumbnails | Same data and viewport, warm memory cache and separately warm disk cache | Cache hit/source distribution; GET reduction; latency versus T1 |
| T3 refresh | Same album, no edits, then one controlled single-photo upload/change in isolated fixture | Revision checks, proof refreshes, number of catalogue requests, unnecessary image reads |
| T4 fresh tree | 500, 2,000 and 10,000 gallery rows in **synthetic or disposable** fixture | `tree-v1-full` size, SQL/JSON/hash time, wall time, render time, responsiveness |
| T5 unchanged tree | Already saved matching local revision under same issuer/account/scope | `tree-v1-unchanged` transport bytes, latency and server fresh SQL/hash overhead |
| T6 legacy/overflow | Simulated old server or deliberately oversized tree >4 MiB/>10k rows | Exactly 50-row page fallback, post-first-500 pacing, response/status/reason and access correctness |
| T7 transport adverse | Local fixture simulates 502 HTML, 503 JSON/HTML, 429 Retry-After, timeout, cancelled read | Recoverability, no busy retry loop or hidden auth failures |
| T8 cross-workload | Synthetic viewer use interleaved with queued Watcher inventory/upload | No broken FIFO upload ownership, no unbounded linked transport lane or inflated concurrency |

For each scenario, repeat a **small fixed number of trials** (propose three), separate warmups from measured runs, retain raw measurements and report variability. Do not stress or benchmark live production WEDOS; never clear caches on production. Changing account/scope or credentials requires a separate valid approved fixture; never replay a bearer from logs.

### Client counters to collect or mark as A2 gap

- Active capability: `galleries:tree-v1` yes/no; selected `tree-v1-full`, `tree-v1-unchanged`, or `paged-fallback`, with safe reason and page count.
- Per allowlisted route category: count, status/status class, failure, bytes, gate/slot/DNS/TCP/TLS/TTFB/body/network timing, retry count, cache hit/miss, start and end.
- Time to: cached tree displayed; fresh validated tree available; first photo page; first visible thumbnail; visible viewport complete.
- Catalogue cache local read/decrypt/parse, server download/parse and Tk mount/reconciliation separately.
- Current and peak client-side outstanding/active HTTP operations; do **not** label this as PHP process concurrency.
- Media endpoint refetch during proof renewal and the additional work caused by revision checks.

If fields are not currently observable, do **not** present constructed values as measurements. Record a field-name/instrumentation proposal for A2 instead of silently adding a heavy new tracing subsystem within A1.

### Server counters to collect or mark as A2 gap

Within an authorized staging fixture, collect available existing PHP profiler / Admin Test Run evidence separately from client HTTP timings:
- PHP request elapsed and memory peak, SQL total/max/count, accepted bytes;
- per route: auth/rate limit, list/revision SQL, JSON/hash, ETag file hash and file streaming;
- available process overlap inferred from **instrumented PHP requests only**; label the sample scope and never claim measured WEDOS 25-slot saturation;
- provider logs/timings only when legitimately available. If inaccessible, mark unknown.

**Do not** trigger production Admin Test Run: it may clear caches and modifies conditions. **Do not** run the existing `gallery_http_monitor.py` default public GET burst on production for this issue. On staging, control its concurrency and retain original privacy safeguards.

## Output contract for A1

Deliver all of the following:

1. **Source audit** on current branch with concrete line/function references and current exact SHA, including whether tree-v1 negotiation is used, why paginated fallback occurs, and what current media timing counters actually mean.
2. **Reproducible baseline table(s)** for scenarios that can genuinely run. Include raw fixture/measurement artifacts when safe, command/runner details, dates, precision, runtime, cache state, warmup and timing window. Unknown hosting-specific figures must be marked unknown.
3. **Quantified hotspot ranking**, clearly separating observed measurements from code-only hypotheses. Identify latency attributable to network/proxy versus PHP/DB/filesystem versus local GUI.
4. **A2 gap list** with exact instrumentation hooks and minimal fields. Avoid inventing optimizations or drawing conclusions from HTTP status alone.
5. **Go/no-go decisions** for subsequent B1/C1 research, including whether server-side tree caching is justified. Do **not** implement batch transport at this stage.
6. **English GitHub progress reports** on issue #362, with per-entry `YYYY-MM-DDTHH:MM:SSZ` actual UTC timestamps, branch SHA, completed actions, results, blocker/unknown status and next action. Provide precise source and commit links.
7. **Only after all A1 acceptance criteria are met**, update issue #362's single checkbox `- [ ] **A1 / Baseline:**` to `- [x] ...` without reformatting or checking other milestones. If a mandatory production/staging observation is unavailable, report the precise blocker and **leave it unchecked**, unless a validated equivalent isolated benchmark explicitly satisfies the criterion.

## Handoff invariants

- Re-read the latest `develop` and feature HEAD before edits; use the **existing** `feature/362-p1winappapiperformance-batched-thumbnail-reads-tree-v1-startup-audit-and-passive-transport-diagnostics` branch. No duplicate branch.
- No changes to `main` or `develop`, no integration/release/deployment from A1, no forced reset, no destructive rebase, no unrelated refactors.
- Keep docstrings/comments, author headers, explicit Python imports, and existing security/session/cache boundaries. No frameworks, Composer, npm, Redis, daemon or provider-dependent dependency.
- Prefer bounded, targeted tests on **changed files** and reusable fixtures. Do not launch FULL or prolonged CI loops merely for a source audit. Preserve existing CI expectations.
- All code, docs, GitHub issue comments and commit messages use English; the operator-facing next-thread prompt may be Czech.

## A1 execution checkpoint: source audit and bounded local fixture

**Execution date:** 2026-11-10 (UTC).  
**Source audited:** existing issue #362 branch, initially at `54e821050bc268a4cac76e76da7314a768aa68f5`, based on `develop` at `1c1e2e9145cd92cb2f0ee731da5655c900d4d3e1`.  
**Fixture file:** [`tools/perf/winapp_a1_isolated.php`](../tools/perf/winapp_a1_isolated.php), added without modifying production code.  
**Raw recorded local trial data:** [`docs/performance/issue_362_a1_php_cli_20261110.json`](performance/issue_362_a1_php_cli_20261110.json). Includes 18 individual full/unchanged tree timings plus 360 individual file-hash timings across three observed passes.  
**Raw-file provenance:** The JSON `commit` field records the audited feature-branch source baseline (`54e821050bc268a4cac76e76da7314a768aa68f5`) supplied as an environment variable when the local fixture ran. The separately authored fixture was published after that source commit. Its actual source content was verified byte-for-byte by matching the local `git hash-object` and the GitHub blob SHA `6f911f3080e015fad4d5b83b2970f92f98242aaf`. Do **not** interpret the recorded `commit` as a commit that already contained the fixture.  
**Classification:** source-confirmed facts, historical installed-client counters, and one narrowly scoped synthetic PHP CLI benchmark are **separate evidence classes**.

### Verified control and data flow

| Path / reference | Source-grounded behavior | What the current baseline does NOT establish |
| --- | --- | --- |
| `winapp/uploader/photos_workspace.py:1883-1975` | A background catalogue worker first retrieves the account/scope-bound local snapshot, attempts the optional live tree on initial load, and falls back to `gallery_page` when the optional tree returns `None`. Generation/cancellation fences prevent obsolete results from mounting. | Timing for cache decrypt/parse, first usable Tk tree, and subsequent live validation. |
| `winapp/uploader/photos_workspace.py:2037-2247` | Cached and live complete-tree rows mount in batches of 64 with a 16-ms scheduling delay; only changed rows need widget updates. Cached rows grant no live authorization. | Per-slice Tk duration, max callback stall and time to 500/2,000/10,000 mounted rows. |
| `winapp/uploader/photos_workspace.py:2398-2464,2648-2689` | Legacy pages are 50 galleries, first 10 pages are admitted before 5-second-paced continuation; page results reconcile incrementally. | Number of pages and queue delay in the failing installed session. |
| `winapp/uploader/native_gallery_viewer.py:2388-2573` | Eligible revision checks run on a 30-second timer. Expired 60-second thumbnail validator proofs can cause a metadata refresh spanning multiple pages of the loaded photo extent. | Per-refresh page count and server work in the historical 96-to-104 proof-refresh delta. |
| `winapp/uploader/native_gallery_viewer.py:3075-3248,3392-3535` | Each viewport-admitted thumbnail calls `session.media_photo(..., thumbnail=True)` separately. A “thumbnail batch” means up to four sequential image reads, with individual enqueueing and bounded four-item/8-ms Tk presentation. | End-to-end cold viewport completion or image-per-request latency distribution. |
| `winapp/uploader/app_linking.py:463-551,4865-4997` | Per-session FIFO `_FairRequestGate`, one process-wide transport slot, separate gate and slot waits, one in-flight network owner, pre-request token renewal. | Hosting-wide PHP worker occupancy or cross-client gateway contention. |
| `winapp/uploader/app_linking.py:793-917,2150-2238,3979-4110` | Existing `MediaReadTimings` tracks cache, network, connection and body phases. A verified exact-session HTTP/1.1 idle connection can be reused for 15 seconds. The TTFB counter times GET send through response-header receipt, while network time also includes slot admission, transfer and validation. | PHP execution/SQL time from TTFB, percentiles from lifetime totals, and the proxy-to-origin split. |
| `winapp/uploader/gallery_catalogue_cache.py:234-330` | Last-known tree is validated as a bounded protected local file, Windows DPAPI-protected; max 10,000 rows / 8 MiB. Only live responses confirm authority. | Warm DPAPI decrypt/parse timing and disk/storage variability. |
| `winapp/uploader/gallery_preload.py:534-616` | Background preload iterates per-photo calls with foreground gating and bounded retries; it shares the authorized session transport instead of opening a batch endpoint. | Fairness/latency impact under simultaneous Watcher upload. |
| `app/controllers/app_linking.php:969-1065` | Exact canonical gallery route, IP/global rate admission, bearer + scope checks, tree GET or legacy list, JSON 503 for controller-handled overflow/failure. | Source of prior gateway HTML 502 or production 503, and whether fallback amplified a transient outage. |
| `app/services/app_linking.php:577-755` | Tree-v1 selects up to 10,001 authorized rows, maps rows, encodes + SHA-256 hashes issuer/account/scope-bound full content, enforces 10,000-row/4-MiB bounds, then reauthenticates before deciding `unchanged`. Unchanged still repeats the database query and serialization/hash. | SQL time, peak PHP memory, exact full-tree CPU cost on WEDOS, or external response buffering. |
| `app/models/galleries.php:1132-1198` | The live tree source SELECT is joined to grant/user authority, ordered by gallery ID and bounded by a prepared LIMIT. | Query plan, index selectivity and MariaDB wall time for 500/2,000/10,000 rows. |
| `app/services/app_linking_media.php:391-478,611-764` | Native photo catalogue pages contain 64 descriptors and a lookahead. Eligible descriptors can compute strong ETags by opening/hashing existing thumbnails capped at 128 KiB each. A separate binary GET may hash the chosen existing file again, before streaming or sending 304. | Number of accepted validators, actual file sizes, fs cache miss count, hashing duration and total conditional GETs. |
| `app/controllers/app_linking/media.php:119-275` | Independent bearer/media/rate admission, per-photo authorization, JSON media metadata or verified binary stream, `fpassthru` after validator generation. Controller-handled exceptions return JSON 503 where headers permit. | Request entry-to-exit PHP wall time and proxy/gateway 502 origin. |
| `app/models/cooperative_galleries.php:297-340`, `app/models/images.php:901-961` | Public/Admin photo-page SELECT and Admin inventory revision aggregate are separate database work from image byte reads. | Their actual MariaDB query durations and repeated workload cost. |
| `app/services/app_linking_rate_limits.php:65-180` | Standalone valid photo attempts use photo-specific IP/global buckets (600 / 3600 attempts per 300 seconds); gallery metadata uses different budgets. | Actual admitted/rejected request rates without a bounded observation window. |
| `app/services/admin_test_runs/summary.php:54-155` | Admin Test Run summarizes concurrency and SQL only over its selected observed PHP request records. | Total hosting process slots, PHP requests rejected before execution, other sites, browser requests outside the trace. |

**Important read amplification:** a qualifying 64-photo metadata page can logically SHA-256-read up to 64 × 128 KiB = **8 MiB** of existing thumbnail content. A subsequent standalone thumbnail GET with a strong validator can read the selected file again to compute its ETag, then transmit it. This is a **code-derived upper bound for one metadata page**, not observed I/O, CPU time, physical disk reads or evidence of 502/503 causation. Warm OS page cache may completely change actual storage traffic.

**Tree error/fallback distinction:** the optional client's `gallery_tree()` (`app_linking.py:6931-6976`) preserves explicit 401/403/429 and throttling failures, but returns `None` on other `LinkingResponseError` outcomes, prompting legacy pagination. The server deliberately emits generic 503 on tree size overflow. Transient tree 503 responses can therefore also trigger paginated reads. Whether this occurred in the incident is **UNKNOWN**. A2 should count fallback reason/status and the resulting page requests, without changing compatibility now.

### Local isolated fixture: environment and reproducibility

Only an explicitly disposable **local** PHP subcomponent experiment was executable. This **does not run the production `app_linking_gallery_tree()` function** and does not exercise the installed WinApp, PHP controller, auth, SQL, HTTP, Tk rendering or WEDOS.

- Platform: Linux, PHP **8.4.24 CLI** (NTS); not the hosted production PHP version.
- Synthetic tree input: deterministic gallery IDs/parent links with 80-character ASCII title prefixes; 500, 2,000 or 10,000 rows. The fixture applies the same JSON/SHA-256 encoding and 4-MiB response guard as the production tree core.
- Hash input: 120 unique **disposable temporary files**, each 65,536 bytes (64 KiB), with deterministic test bytes. Each read calls native PHP `hash_update_stream()`; 120 files amount to **7,864,320 bytes per trial**. The OS page cache was **not** cleared, so these are not physical cold-disk observations.
- Sampling: one discarded warmup, three measured trials for each tree outcome and the 120-file hash pass; monotonic `hrtime(true)` determines elapsed time. Start and end UTC timestamps are recorded to seconds; when both fall in the same second, do not infer zero elapsed duration.
- Run window of the **reported corrected fixture**: `2026-11-10T15:09:56Z` to `2026-11-10T15:09:56Z`, precise sub-second elapsed values below. Peak **whole PHP CLI process** memory: 8,388,608 bytes; not peak hosted request memory.

Run from the repository root with system PHP:

```bash
php -l tools/perf/winapp_a1_isolated.php
A1_COMMIT_SHA="$(git rev-parse HEAD)" php tools/perf/winapp_a1_isolated.php > winapp_a1_local_results.json
```

PowerShell:

```powershell
php -l tools/perf/winapp_a1_isolated.php
$env:A1_COMMIT_SHA = (git rev-parse HEAD)
php tools/perf/winapp_a1_isolated.php | Set-Content -Encoding utf8 winapp_a1_local_results.json
```

The JSON output retains every measured full/unchanged tree sample and each individual 64-KiB file-hash elapsed duration, along with fixture environment, window, and commit ID. Keep the output local or add a **redacted** artifact to the issue. Re-run against the same PHP version, machine and OS cache state before using a comparison.

### Measured partial results: PHP CLI only

Tree encoding + SHA-256, **without model SQL, authentication, PHP request/bootstrap, HTTP transfer or Tk**, elapsed milliseconds:

| Synthetic rows | Full raw trials (ms) | Unchanged raw trials (ms) | Full reply bytes | Unchanged reply bytes |
| ---: | --- | --- | ---: | ---: |
| 500 | 0.149826, 0.176586, 0.148704 | 0.149045, 0.150037, 0.148855 | 59,811 | 108 |
| 2,000 | 0.676461, 0.623931, 0.607637 | 0.628959, 0.641238, 0.614477 | 242,814 | 108 |
| 10,000 | 3.426782, 3.181011, 3.232498 | 3.237266, 3.183624, 3.208382 | 1,226,817 | 108 |

Sample medians are respectively **0.149826 / 0.149045 ms** (500 full/unchanged), **0.623931 / 0.628959 ms** (2,000) and **3.232498 / 3.208382 ms** (10,000). With only three local trials and an isolated CPU-only path, do not treat these as host p95/p99 or production estimates.

Standalone existing-file SHA-256, **120 × 64 KiB = 7.5 MiB per measured pass**, total elapsed (ms): **6.163822, 6.124864, 6.507452**. For the 360 local per-file durations (three passes), nearest-rank **p50 0.048503 ms, p95 0.063176 ms, p99 0.155124 ms**. These percentiles apply *only* to local test-file hashing, not HTTP operations, PHP worker time, metadata endpoints or network TTFB.

The synthetic unchanged tree returns just **108 bytes**, but redoes the full in-memory tree hash. This confirms the **algorithmic behavior of the fixture**, consistent with the audited production source; it does **not** prove the live SQL/authorization cost is small or large.

### T1-T8 evidence inventory

| Scenario | Status | Available evidence and specific gap |
| --- | --- | --- |
| T1 cold 120+ thumbnails | **NOT RUN** | Historical 208 cumulative GETs only. Need an authorized disposable album, fresh cache, timed HTTP/first-tile/viewport metrics and PHP traces. |
| T2 warm RAM and disk cache | **NOT RUN** | Existing cache code verified; no controlled repeated viewport window or cache-source delta. |
| T3 unchanged/changed album refresh | **NOT RUN** | 30-second revision and 60-second proof logic/source verified; no safe isolated image edit and per-page timeline. |
| T4 500/2,000/10,000 fresh tree | **PARTIAL, SYNTHETIC ONLY** | PHP CLI encoding/hash measured as above; actual SQL/auth/HTTP/Tk are UNKNOWN. |
| T5 unchanged tree | **PARTIAL, SYNTHETIC ONLY** | Core encoding/hash measured; actual SQL/authorization and end-to-end response are UNKNOWN. |
| T6 legacy/overflow paged fallback | **SOURCE AUDITED, NOT RUN** | Hard 10,000/4-MiB bounds, client fallback and 50-row pages verified from source; no real HTTP fixture or 5-second pacing timeline. |
| T7 429/502/503/timeout/cancel | **SOURCE AUDITED, NOT RUN** | Response/error routing inspected; no isolated fault-injection HTTP fixture executed. |
| T8 concurrent Watcher/upload/inventory | **SOURCE AUDITED, NOT RUN** | FIFO session gate, single process transport slot, foreground-aware preload verified; no actual workload fairness/latency test. |

No timed sampling window, request/response byte totals, per-endpoint distributions, server query profiler records, or available PHP host worker overlap was acquired for T1/T2/T3/T6/T7/T8. Every such field is **UNKNOWN**, not zero. No provider credentials, WEDOS worker logs, isolated MariaDB site or complete locally installed WinApp were available to this task. No production load was generated.

### Evidence-ranked performance questions

These are **investigation priorities**, not a ranking of **measured** production bottleneck durations.

1. **Observed client symptom, unallocated:** Historical 208 network thumbnail GETs and 159,150.29-ms aggregate TTFB (765.15 ms per request as a lifetime-derived mean). This is confirmed installed-client evidence, but lacks a shared wall-clock period, sample distribution, endpoint status and PHP/gateway split.
2. **Source-confirmed potentially expensive I/O:** `app_linking_media_page()` hashes qualifying thumbnail files again on metadata refresh; `app_linking_media_open_verified_photo_file()` hashes existing file bytes before standalone binary delivery or conditional responses. Actual per-request hash bytes and elapsed are **UNKNOWN**. The standalone 64-KiB local hash experiment is not a hosted measurement.
3. **Source-confirmed catalogue revisit:** unchanged `tree-v1` reexecutes authorized SELECT, complete JSON encoding/hash, and final authority check. SQL query plan/time, PHP wall time and UI mount cost are **UNKNOWN**; local isolated encoding-only samples are available above.
4. **Client-side serialization and UI:** one shared network lane plus multiple page/metadata requests can extend visible time; bounded Tk slices may still have expensive callbacks. Gate wait historical sum is **50,039.72 ms** but cannot be divided into a defined session duration. Real queue lengths, first paint and UI p95 are **UNKNOWN**.
5. **Infrastructure incident hypothesis:** 502 HTML / 503 catalogue may reflect proxy, upstream, PHP errors, throttling or load; **the cause and full-host worker occupancy are UNKNOWN**. Do not attribute these failures to a hypothetical WEDOS slot limit or “fix” them with batching alone.

**No production hotspot timing breakdown is established.** The isolated PHP microbench is informative about only two local computations and must not be compared directly to the client’s network TTFB sum.

### A2 instrumentation handoff: exact minimal hooks

Instrumentation must remain optional, off by default, bounded in space/sampling, and privacy-safe. Keep route labels allowlisted; do not record tokens, image IDs, gallery titles, private paths, URLs, arbitrary query/body values or account identities.

| Hook | Minimal proposed fields | What to distinguish |
| --- | --- | --- |
| `app_linking.py:_run_linking_transport`, `_native_media_read_once` | monotonic request start/end, sanitized route/kind, attempt, status/status class, retry, slot wait, gate wait, new/reused connection, DNS/TCP/TLS/TTFB/body, received bytes, safe reason | Client admission vs transport vs unsuccessful/retried calls, with bounded per-operation samples. |
| `app_linking.py:media_photo`, `native_gallery_viewer.py:_observe_thumbnail_read` | source disk/network/revalidated, cache state/verified age, validated byte count, decode, discard/cancellation, counted HTTP attempts | Distinct authorized cache hit and attempted network operation. |
| `native_gallery_viewer.py:_load_next_thumbnail`, `_poll_queue`, viewport code | viewport generation, first admitted tile, first displayed tile, visible viewport complete, queue depth and age, Tk slice p50/p95/max, GUI batch count vs GET count | First paint, UI stalls, dropped/cancelled items, never relabel GUI batches as HTTP batches. |
| `photos_workspace.py:_request_page`, `_accept_complete_catalogue`, `_mount_complete_catalogue_batch` | capability yes/no, outcome `tree-v1-full`/`tree-v1-unchanged`/`paged-fallback`, safe fallback code, page/row count, local cache load/decrypt/parse, response parse, row reconciliation slice and first usable tree | Startup live tree vs cached display vs paged fallback. |
| `cms_app_linking_galleries`, `app_linking_gallery_tree`, `gallery_model_linked_client_page` | sampled route elapsed, auth status (not identity), SQL count/total/max, rows, JSON encode+hash duration/bytes, final authority check, outcome | Tree SQL and authorization vs hashing/encoding vs gateway timing. |
| `cms_app_linking_media`, `app_linking_media_page`, `app_linking_media_open_verified_photo_file` | sampled handler wall time, rate/auth elapsed, photo-page SQL elapsed, candidate count, validator hash count/bytes/ms, stream bytes/ms, PHP peak request memory, response class | Metadata hash/read amplification, conditional responses, file hashing and transfer. |
| Passive server trace aggregator, when strictly authorized | per-request process entry/exit monotonic time, opaque bounded correlation ID, count of **instrumented** overlapping PHP entries | Scope-limited request overlap only, never global WEDOS capacity, PHP workers waiting before admission or other tenants. |

If provider allows a restricted `Server-Timing` header, validate on staging first whether the proxy preserves it. Do not expose private route internals or large cardinality tags in public responses. Request correlation must not become a shared permanent log, a new global mutex, a profiler run that clears caches, or polling that creates more work than it measures.

### Follow-up decisions and acceptance blockers

- **A2 first:** add precisely scoped passive timing + export for existing routes; the current tool cannot tell where 765.15-ms historical mean client TTFB was spent. Include a common measurement window and per-route samples.
- **B1 conditional:** after a representative authorized 120+ thumbnail baseline, benchmark proposed 8/16/24/32-sized framed, multipart and optional STORE/ZIP transports **only in isolated HTTP/PHP staging**. Preserve individual authorization/rate accounting, cache proofs, low first-paint latency and Watcher priority. No batch wire format can be chosen from this CPU-only microbench.
- **C1 conditional:** instrument existing `galleries:tree-v1` SQL, auth, JSON/hash and 64-row Tk mount phases before changing it. Retain the existing paginated fallback. **C2 server tree cache is NO-GO** without expensive-stage evidence plus complete account/scope isolation and mutation/grant invalidation proof.
- **Acceptance blocker:** a real controlled cold/warm WinApp viewport and a comparable server-side authorized SQL/PHP request measurement were **not executable** in this environment. To close A1, supply an isolated/staging PHP+MariaDB install with approved credentials and WinApp, or validated equivalent local setup, then collect T1/T2 and T4/T5 endpoint traces plus T3/T6/T7/T8 targeted cases. Production WEDOS must not be stress-tested or cleared.
- **A1 status:** **NOT COMPLETE; leave issue checkbox unchecked**. Keep the completed source audit and local subcomponent artifact, and report the exact missing evidence. No production runtime code, server cache, batch API, integration into `develop`, release, or hosting configuration was changed.

### Verification executed

- `php -l tools/perf/winapp_a1_isolated.php`: **PASS**, on the same local content subsequently published to GitHub (Git blob SHA `6f911f3080e015fad4d5b83b2970f92f98242aaf`).
- Isolated PHP 8.4.24 CLI benchmark: **PASS** (real run, three measured trials for each named subcase, one warmup discarded).
- Installed WinApp 1.0.10 / Python 3.14.10 client benchmark: **NOT RUN** (only historical diagnostics available).
- Isolated PHP+MariaDB+HTTP full-path benchmark: **NOT RUN** (environment unavailable).
- WEDOS production load testing, cache-clearing Admin Test Run, FULL CI, and develop merge: **SKIPPED by policy**.


## GitHub-hosted A1 baseline execution path (2026-11-10)

**Status:** Implemented as a separate, issue-scoped Actions workflow on the #362 feature branch; hosted evidence is collected independently of BASIC. Do not claim A1 acceptance before reviewing each exact-SHA run and the scenario matrix.

- Workflow: [`.github/workflows/issue-362-a1-baseline.yml`](../.github/workflows/issue-362-a1-baseline.yml).
- Linux disposable DB/HTTP: `tools/perf/issue_362_a1_ci.php`, `tools/perf/issue_362_a1_seed.php`, `tools/perf/issue_362_a1_http.php`. Reuses `GalleryWorkflow\Fixture` for a fresh migrated and seeded MariaDB 10.11 database, real application copy and loopback-only PHP HTTP server. The dedicated database principal is created with only isolated schema privileges and then the bootstrap credential is removed from the child environment.
- Windows native: registered `winapp-native` suite through `scripts/audit.php`, plus [`tools/perf/issue_362_a1_tk.py`](../tools/perf/issue_362_a1_tk.py) for measured **synthetic** Tk Treeview insertion cadence, not a purported real Photos rendering benchmark. The 114-test native-only Tk diagnostic uses a separate finite **150-second child deadline**: authenticated GitHub Windows runs previously passed in 61.91, 80.07 and 86.32 seconds before one later run reached the old 90-second cap without producing a report. Complete WinApp regression discovery retains its independent **90-second** deadline. Every failed assertion, incomplete report and elapsed diagnostic deadline remains a failure, not an advisory result.
- CI artifacts: `issue-362-a1-php-<SHA>` contains isolated CLI calculations and real PHP/HTTP observations; `issue-362-a1-windows-<SHA>` contains the native audit, diagnostic `winapp-native.log` (including bounded partial output after timeout), and synthetic Tk results when completed. Bounded seven-day retention, `contents: read`, no production endpoint or credential in any job. Record the source run ID and download exact artifacts for analysis before expiry.
- The PHP fixture now verifies canonical native bearer authority on `localhost` using a disposable-only configuration copy; it **does not loosen** the production HTTP/HTTPS issuer policy. Benchmark route selectors remain allowlisted, and the report stores numeric route classes, not raw private identifiers, HTTP URLs, bearer credentials or response bodies.

### First hosted finding and necessary minimal functional correction

A real PHP/MariaDB HTTP route run against the exact issue-branch source returned **404** on `?page=app_linking_galleries&tree=1` before the intended controller was entered ([Actions run #42852970855](https://github.com/klusik/PHP_gallery/actions/runs/42852970855)). The server's default-enabled `seo_request_guard_allowed_parameters_for_page()` had not included the `tree` and `revision` query keys for the existing bearer route. The URL was therefore filtered before the controller's strict tree-v1 query-shape checks could run.

The narrowly scoped fix extends that allowlist to `['after', 'tree', 'revision']`. The existing `cms_app_linking_galleries()` canonical regex, bearer checks, rate limits and tree bounds still independently reject malformed or unauthorized requests. The existing `tests/app_linking_gallery_guard_test.php` now covers both accepted tree-v1 forms and refusal of arbitrary additional keys. A dedicated A1 workflow trigger covers changes in these two source/test files.

**Evidence classification:** the 404 is a confirmed isolated real-controller admission failure. It does **not** prove the earlier deployed production WEDOS 502/503 share that cause. Re-run the corrected version and inspect full response status and timings before marking any tree scenario complete.

### Separation of measurements and acceptance gates

| Experiment | Hosted job | Direct evidence | Still unavailable |
| --- | --- | --- | --- |
| Synthetic PHP core tree JSON/hash and file SHA | Linux PHP 8.5 CLI | Individually timed process-local calculations and file reads | WEDOS disk/CPU; client transport; PHP SQL |
| Real `galleries:tree-v1` full and unchanged, 500/2,000/10,000 rows | Linux real PHP + MariaDB + loopback HTTP | Request status, bytes, TTFB and elapsed per sample; PHP router wall/memory when trace available | MariaDB per-query timings unless separately instrumented, WEDOS proxy/FPM, Windows UI |
| Real 120-image first/repeated HTTP GET, native media metadata | Linux real PHP + MariaDB + loopback HTTP | Individual GET status, bytes, request latency, PHP side samples | Actual WinApp RAM/disk cache and end-to-end viewport timing |
| Native WinApp/Tk regression | Windows GitHub Actions | Existing behavioral assertions against native widgets | WEDOS server, actual network state and client-to-server combined timings |
| Synthetic 500/2,000/10,000-row Treeview | Windows GitHub Actions | First mounted row, complete synthetic tree, callback sample p50/p95/p99/max | Photos-specific reconciliation, real-tree parser and remote traffic |
| Error, legacy protocol and simultaneous Watcher workloads | Existing focused fixtures and future A1 work | Only explicit executed fixtures qualify | Integrated T6/T7/T8 workload baseline until evidence is supplied |

**Critical constraints:** the fixture starts a default single-worker PHP CLI server, so a measured server request overlap of one is a harness limitation, not an inferred WEDOS worker count. The Windows and Linux jobs use separate runners, therefore their measurements must never be summed into a fictitious single end-to-end timeline. Real WinApp cold/warm viewport time, PHP SQL/fstat/hash phase detail and a complete failure/fallback suite remain part of the A1 evidence gap until measured.

### Workflow retirement note

The [workflow inventory and cleanup audit](CI_WORKFLOW_AUDIT_20261110.md) documents every installed workflow, confirms removal of the obsolete issue-345-only dispatcher **on the #362 feature branch**, and explains why protected release lifecycle workflows, historical compatibility owners, manual readability and TinyTeX warming remain. No protected branch was modified. **No TeX manual impact:** this change only adds internal diagnostic utilities and restores an existing authorized API query route; user-visible gallery controls and public API documentation are unchanged.

**A1 acceptance remains pending** until exact hosted runs and the missing scenario data are verified. Do not change the A1 checkbox on the basis of fixture creation or BASIC success alone.

## Verified hosted GitHub Actions baseline: 2026-11-10

The first two-job isolated GitHub-hosted A1 workflow **passed at the exact
candidate SHA** `5dd3a04c387953655f3586100cb8733742b10a48`:
[GitHub Actions #42855085368](https://github.com/klusik/PHP_gallery/actions/runs/42855085368).

A permanent evidence review is available in
[`docs/performance/issue_362_a1_hosted_20261110.md`](performance/issue_362_a1_hosted_20261110.md).
Its raw PHP HTTP and Windows Tk/WinApp observations were retrieved and validated
against the exact GitHub artifact IDs `13782068644` and `13782706469`.
The complete method remains rerunnable after the current 7-day artifacts expire.

- Ubuntu 24.04, PHP 8.5.12, MariaDB 10.11.19: **265** real
  bearer-protected or intentionally invalid loopback HTTP requests with 265
  corresponding PHP router observations, all expected status/shape assertions
  **PASS**. This includes three full/unchanged samples for each 500, 2,000
  and 10,000-row tree, two 64-photo metadata pages, 120 initial and 120
  repeated thumbnail GETs, canonical query refusals, one 10,001-row
  **controlled 503**, and a verified 50-row fallback response.
- Windows Server 2025, Python 3.14.10: registered `winapp-native`
  **PASS** (110 pass, zero fail, one audio-device skip).
  The standalone synthetic `ttk.Treeview` timing probe also **PASS**,
  using 500, 2,000, and 10,000 rows with a discarded warmup and three
  measured runs each.
- The staged HTTP run **confirmed and repaired** a previously masked optional
  tree-v1 404: the SEO query guard lacked already supported `tree` and
  `revision` keys. Only the allowlisted names were added to the existing
  guard, with negative/positive regression vectors. A second fixture-only
  correction supplied missing PHP CLI `SERVER_ADDR` listener metadata
  inside the **disposable copied router**, without weakening production
  issuer checks. Both corrections are source-grounded, not claims about
  historical WEDOS 502/503.
- The two jobs do **not** share an interactive Windows-to-PHP client session.
  A server-side repeated GET is **not** a WinApp warm RAM/disk cache hit.
  PHP CLI worker count, SQL cost and hosted Tk insert-only timings cannot be
  generalized to WEDOS FPM or full Photos UI painting.

**A1 remains NOT COMPLETE.** The exact outstanding T1/T2/T3/T6/T7/T8
client-side/fault/Watcher evidence is itemized in the linked report.
The A1 checkbox remains deliberately unchecked. No production load,
new cache, batch media API, FULL, deploy, or integration was performed.


## A1.2 connected client/HTTP/MariaDB methodology (verification pending)

The issue-scoped workflow now launches a Python 3.14 child **inside the same
Ubuntu runner and disposable PHP/MariaDB fixture** already used for real
HTTP samples. The child imports the production
`winapp/uploader/app_linking.py` `LinkingSession`,
`MediaReadTimings`, and the genuine disk-cache and decoded-thumbnail
RAM/decode methods. Its API calls go to the bearer-authorized copied PHP
application through the real localhost HTTP connection and the same
per-session FIFO admission gate. No production credentials, WEDOS endpoints,
public staging server, or local operator actions are used.

The host PHP fixture creates an ephemeral bearer and passes it only to a
direct child environment. The child checks `GITHUB_ACTIONS=true`,
`GALLERY_WORKFLOW_ENABLE=disposable-only`, the exact `localhost`
issuer and fixture ownership token. It uses a temporary, initially empty
client cache root, destroyed after measurement. Only numeric counters and
non-secret positional sample ordinals are persisted.

The connected `tree` phase reads 500, 2,000 and 10,000 rows using the
real client parser and validates complete and unchanged responses. The
connected `overflow` phase exercises actual 10,001-row server refusal,
then the first authorized 50-row paginated fallback. The no-capability
case uses a deliberately capability-disabled client, **not a historic
server**. The `media` phase reads 120 current photo descriptors and
performs 120 cold native thumbnail reads with `MediaReadTimings` and
the real Pillow decoder. A defined **logical** viewport has 60 admitted
photos plus 12 overscan photos. Readiness milestones are decoded/cache
availability, **never GUI paint**. Reuse within the same session invokes
the actual bounded `_NativeGalleryViewer` RAM-cache routines without
constructing a Tk widget; a fresh session uses the exact production
disk-cache path and demands 120 authenticated warm-disk hits with zero
additional thumbnail GETs. Unchanged metadata refresh is checked again.

The existing workflow retains both its prior PHP cURL/SQL fixture and
independent Windows native/Tk jobs. Connected raw JSON is written as
`cache/test-audit/issue-362-a1-client-*.json`, uploaded under the same
seven-day PHP evidence artifact with the measured source SHA. The
`tools/perf/issue_362_a1_connected.py` entrypoint may only be run from
that owned GitHub Actions fixture.

**Evidence boundary:** This method alone does not establish Windows
`PhotosWorkspace` first paint, server SQL per-query timing, changed/
deleted-photo invalidation, historical legacy-server behavior, 429/502/
503 or truncated-response fault injection, cancellation under live HTTP,
or concurrent Watcher/upload fairness. Such cases remain `NOT MEASURED`
until real exact-SHA artifacts show otherwise. The original, immutable
2026-11-10 baseline must not be relabeled or overwritten. A1 stays
unchecked until every mandatory criterion has validated hosted evidence.

**No TeX manual impact:** These are private disposable measurements only.
No production feature, public API-key contract, UI, link authorization,
deployment procedure or administrator operation changes.

### A1.2 focused T7/T8 fixture extension (qualification pending)

The same **owned PHP CLI server** now supports a disposable-only, loopback-only
fault switch in the *copied* router. It intercepts only synthetic thumbnail
requests for deterministic **429**, **502**, **503**, truncated-body and slow
response probes. The normal controller and production PHP router are unchanged.
The client runs the real `LinkingSession.media_photo` parser, cache policy,
socket cancellation and FIFO gate. Refusals must not create disk cache entries;
the healthy route must recover and return an actual authorized body. This does
**not** demonstrate the historical source of production WEDOS errors, automatic
preload retries or real request-service time under WEDOS.

A second short-lived, server-authenticated, **upload-plus-media scoped** bearer
is inserted in the fixture's MariaDB specifically for contention evidence. In
one real `LinkingSession`, the Python harness queues a media preload, an
actual `inventory()` Watcher request, photo-page metadata and a second
preload behind the client's own FIFO gate. It validates the observed queue
completion sequence and records the Watcher end-to-end latency. This proves
shared session admission only, **not** a multipart-upload benchmark or actual
server-side worker capacity. All modes have bounded worker joins and the fault
file is removed when the child terminates. Raw summaries are placed in
`issue-362-a1-client-fault.json` and
`issue-362-a1-client-fairness.json` in the existing PHP artifact.

Neither a failure-injection fixture nor a queued Watcher inventory is a
substitute for full T3 mutation, real multipart upload, full Windows GUI paint
or production stress testing. Keep A1 unchecked until those remaining proof
requirements are met. **No TeX manual impact:** private disposable fixture
routing and test observations do not alter any public or administrator workflow.

### A1.2 complete 10,001-row paginated fallback probe (qualification pending)

The connected overflow mode now traverses **all 201 pages** of the unchanged
real 50-row gallery endpoint after the optional tree-v1 endpoint refuses a
10,001-row tree. The actual WinApp `gallery_page(after_id)` parser must
advance strict gallery IDs, terminate within the bounded 202-page guard,
and reconstruct the complete 10,001-row authorized set. The separate
capability-disabled client still proves only its configured no-tree path,
not a deployed historical server. This may incur repeated server-side
requests; its complete elapsed time is reported separately from one-page
latency. No production server or client pagination policy is changed.

### Verified 10,001-row fallback rate-limit boundary (2026-11-10)

The first attempted **full 201-page** legacy traversal at exact SHA
`6f5e3e34012a17e030e9cdf824c89d3d24d2b293`, hosted run
[42871416760](https://github.com/klusik/PHP_gallery/actions/runs/42871416760),
received a real `LinkingResponseError` before completion. The production
`cms_app_linking_galleries` route invokes
`app_linking_admit_public('app_link_galleries_ip', ...)`, with the
unchanged `app_link_galleries_ip` policy of **120 requests / 300 seconds**.
A 10,001-gallery listing requires **201 pages** at 50 entries/page. A rapid
single-window traversal therefore cannot be guaranteed, regardless of how
small the individual requests are.

The follow-up harness reports an exact **BLOCKED** result, page and row
counts reached, measured time, and the actual 429 HTTP status if encountered.
Other unexpected HTTP statuses or malformed pagination remain hard failures.
This new full-pagination experiment is deliberately executed **last** so the
real limiter does not invalidate the earlier media/fault/Watcher work. It
does not disable the real rate limiter. The initial one-page compatibility
assertion remains required. This is a measurable protocol limitation, not
evidence about WEDOS hosting capacity or a reason to alter production
authorization or caching during A1.

## Final verified hosted A1.2 evidence (2026-11-10)

The [dedicated connected WinApp/PHP/MariaDB report](performance/issue_362_a1_connected_20261110.md)
records the exact immutable source SHA
`f308a96739398b40b903fa212bf3cc956e581df2`,
[hosted A1 workflow #42872280320](https://github.com/klusik/PHP_gallery/actions/runs/42872280320)
(**SUCCESS**), [BASIC #42872280542](https://github.com/klusik/PHP_gallery/actions/runs/42872280542)
(**SUCCESS**), independently verified raw evidence, timings,
and all remaining T1–T8 limitations.

In this completed run, the actual 120-thumbnail WinApp cold transport
finished in **8,514.562 ms**; the new client session had **120 warm disk
hits with zero thumbnail GETs**, and the same-session bounded decoded
RAM cache had **120 hits**. The real FIFO Watcher `inventory()` completed
before later preload work in **148.498 ms**. The full 10,001-gallery
legacy traversal is deliberately **BLOCKED** by the unchanged per-IP
rate limiter at 79 of 201 pages (3,950 galleries, actual HTTP 429).
Those are distinct protocol observations; they do not establish
Tk GUI paint or WEDOS worker capacity.

**A1 is PARTIAL, not complete.** The issue checkbox must remain
unchecked. The standalone original 2026-11-10 baseline is unchanged.
No local test, FULL, integration, release, production authorization
change, batch media API or TeX manual update was performed.

## A1.4 actual source-mutation correctness, final qualification

A1.2 and the earlier A1.3 reports above remain historical, immutable
observations. The later complete A1 run [42882790798](https://github.com/klusik/PHP_gallery/actions/runs/42882790798)
at source SHA `69fb9f13668ff6fc97852004382ab84446774311`
finished all **201** legacy pages and **10,001** rows with pacing and
**zero HTTP 429**. Its [BASIC companion](https://github.com/klusik/PHP_gallery/actions/runs/42882790524)
also passed. It is **not** a valid T3 content-correctness pass: the
`issue-362-a1-client-mutate.json` raw fixture showed
`replacement_network=0`, `replacement_source=disk`, and missing
before/after thumbnail ETags after replacing a real photograph. The old
assertions did not compare the body to the current server response.

A1.4 removes that false-positive acceptance, without changing the
shared-hosting rate limiter or disabling photo caching. The PHP native
media catalogue now tries the same original display fallback as the
authorized thumbnail route, but obtains a strong validator **only**
through its existing 128 KiB per-file hashing budget. Oversized
originals keep no catalogue ETag. The public WinApp preserves latest
authorized descriptor epochs, rejects old/deleted descriptors, and
requires an actual authorized conditional binary revalidation when a
new page has no content validator. Confirmed same-page bodies retain
their normal cached reuse. The native viewer scopes decoded pixels and
already-mounted `PhotoImage` tiles to those same metadata epochs.

The owned scanner mutation fixture now covers a small replaced original
with strong ETag, a deliberately oversized replaced original without
a metadata ETag, independently fetched SHA-256 and decoded pixels,
unchanged-photo cache retention, cross-page and cross-session
revalidation, an actual newly added photo and a deleted photo whose
old descriptor must be refused. The disposable router also tests HTML
502/503 in addition to prior JSON faults. The existing Windows native
suite is supplemented with a real Tk tile-refresh regression. No
duplicate T6 stress method or additional one-off workflow was added.

Full baseline metrics, hosted source provenance, explicit measurement
boundaries, and A2/B1/C1/C2 gates are in
[the A1.4 final technical report](performance/issue_362_a1_final_20261110.md).
The final issue #362 comment links the subsequently qualified exact-SHA
GitHub Actions runs and raw artifacts. Completion requires their
independent verification; a green job without matching content
assertions never suffices. Older results above are not retroactively
relabeled as measurements of the repaired code.

**No TeX manual impact:** only internal cache validity, private
authorization-proof bookkeeping, and disposable CI measurements change.
The public API-key surface, user-visible flows, setup, translations,
administrator procedures, and documented TeX commands do not change.
