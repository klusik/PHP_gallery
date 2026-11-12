# Issue #362 A1.2: connected WinApp / PHP API / MariaDB evidence

**Author: Rudolf Klusal**  
**Observation date:** 2026-11-10 (UTC)  
**Permanent report type:** exact-commit evidence, not synthetic estimates.

## Verified source, CI and raw artifacts

- **Measured source SHA:** `f308a96739398b40b903fa212bf3cc956e581df2`.
- **GitHub Actions workflow:** [Issue 362 A1 run 42872280320](https://github.com/klusik/PHP_gallery/actions/runs/42872280320), status **SUCCESS**.
- **Run UTC:** created 2026-11-10T18:09:23Z, completed/updated 2026-11-10T18:12:01Z.
- **PHP/MariaDB + connected Python raw evidence:** [artifact 13789083877](https://github.com/klusik/PHP_gallery/actions/runs/42872280320/artifacts/13789083877), seven-day retention.
- **Separate Windows native/Tk evidence:** [artifact 13788754634](https://github.com/klusik/PHP_gallery/actions/runs/42872280320/artifacts/13788754634), seven-day retention.
- **Exact-SHA BASIC:** [run 42872280542](https://github.com/klusik/PHP_gallery/actions/runs/42872280542), **SUCCESS**.
- **Previous immutable staged HTTP/Windows baseline:** [run 42855085368](https://github.com/klusik/PHP_gallery/actions/runs/42855085368); see [original hosted report](issue_362_a1_hosted_20261110.md).

The PHP job ran on Ubuntu 24.04 with PHP 8.5.12, MariaDB
10.11.19, Python 3.14.10 and Pillow 13.0.0. The Windows
job ran on Windows Server 2025 with Python 3.14.10.
The Windows native suite completed **110 PASS, 0 FAIL, 0 ERROR,
1 SKIP** (hosted audio-device absence); the synthetic Tk benchmark
also **PASS**. These Windows measurements are independent of the Ubuntu
client/API/DB connection. There is **no full Windows GUI-to-PHP
end-to-end** claim.

All connected cases are driven by the production `LinkingSession`,
production `MediaReadTimings`, `GalleryMediaCache` and the actual
`_NativeGalleryViewer` thumbnail decode/bounded RAM methods against
a bearer-authorized copied PHP controller, real MariaDB, and owned
filesystem, in the same runner. No endpoint is mocked as a successful
application response. The T7 fault cases intentionally override only
the **owned copied PHP router**, never the production router.
No external account, WEDOS server or production photographs are used.
The user need not run or install anything.

## Client thumbnail experiment: T1 and T2

Exactly 120 live media descriptors were sampled from a physical
synthetic photo fixture. Source images were tiny 96 x 64 JPEGs,
so the absolute byte and raster costs cannot be projected onto
large production thumbnails. The observed first logical viewport
contained 60 thumbnails, with an additional 12-item overscan.
**None of these timings represents the first Tk pixel on screen.**

| Observed quantity | Real value |
| --- | ---: |
| Cold authorized thumbnail GET | **120** |
| Cold network response bytes | **95,115 B** |
| Cold decoded/cache-ready first thumbnail | **89.051 ms** |
| Cold 60-photo logical viewport ready | **4,262.998 ms** |
| Cold 72-photo viewport + overscan ready | **5,110.129 ms** |
| Cold entire 120-thumbnail sample ready | **8,514.562 ms** |
| Cold request elapsed median / p95 / p99 | **70.496 / 73.371 / 80.817 ms** |
| Cold network TTFB median / p95 | **64.762 / 67.172 ms** |
| Cold thumbnail Pillow decode median / p95 | **1.162 / 1.315 ms** |
| Cold gate wait median / p95 | **0.00517 / 0.00609 ms** |
| New-session warm-disk thumbnail GET | **0** |
| New-session disk hits / hash verifications | **120 / 120** |
| New-session disk reads / hash bytes | **120 / 95,115 B** |
| New-session disk hit median / p95 | **0.250 / 0.298 ms** |
| Same-session decoded RAM cache hits | **120 / 120** |
| Same-session RAM hit median / p95 | **0.0105 / 0.0131 ms** |
| HTTP GET reduction on warm disk | **100%** |
| Thumbnail body byte reduction on warm disk | **100%** |

Fresh warm-disk metadata fetch still requires **two authorized page
requests**; no claim is made that the entire gallery refresh makes
zero HTTP requests. The headless RAM probe invokes the original
viewer methods directly but does not instantiate or render Tk widgets.

Repeated cold timings vary with runner, PHP CLI scheduling and
other unrelated processes: the preceding same-code connected run
at SHA `c1a870b7977525a91136b1f2c9dc4dcbee1a3f66`
measured approximately **7.219 seconds** for the full corpus,
versus **8.515 seconds** above. This is **environment variability**,
not evidence of a code regression or improvement.

## Tree and catalogue experiments: T3, T4, T5 and T6

| Client-side operation | Observed full response | Observed unchanged response |
| --- | ---: | ---: |
| `tree-v1`, 500 galleries | **72.557 ms** | **65.395 ms** |
| `tree-v1`, 2,000 galleries | **84.243 ms** | **69.304 ms** |
| `tree-v1`, 10,000 galleries | **125.659 ms** | **80.845 ms** |

All three real client parsers returned the exact expected complete row
count and accepted the known SHA-256 revision as `unchanged`
with zero gallery entries in that unchanged response. Actual metadata
refresh of the same 120-photo corpus was successful; its second
unchanged fetch took **201.851 ms**, including two metadata calls.
Adding/removing/changing a photograph while retaining the live
`PhotosWorkspace` view was **NOT MEASURED**.

At 10,001 gallery rows, the real server refused the optional
`tree-v1` request. A real paginated client successfully read the first
50 entries; a capability-disabled client correctly refrained from
requesting the tree and read the original paginated endpoint.
The capability-disabled setting **simulates old capability discovery**;
it is not a historical PHP server binary.

A subsequent bounded attempt to reconstruct all **201 pages** of the
10,001-row catalogue was **BLOCKED**, not PASS:
- **79** pages and **3,950** authorized galleries read.
- **5,505.388 ms** elapsed until an actual **HTTP 429**.
- Production code `cms_app_linking_galleries()` consumes the
  unchanged `app_link_galleries_ip` policy: **120 requests per
  300 seconds** from one IP. Other earlier gallery/metadata probes
  within this same isolated runner have already consumed part of
  the rate window.
- The test deliberately does **not** disable the limiter, retry for
  five minutes, bypass it with alternative IPs/tokens or report
  a completed pagination as evidence.

This reveals a real pagination/rate-budget tradeoff at 10,000+ galleries.
It does not prove a historical WEDOS incident or production database
worker limits. Neither actual PhotosWorkspace row reconciliation
nor a historical server fallback was exercised.

## Server-phase evidence and limitations

The original PHP cURL owner still completed its **265** expected
bearer-protected / negative-control HTTP requests, including
the 120 original and 120 repeated **server-side** thumbnail GETs.
The PHP loader trace contained **494** request observations, now
including genuine connected WinApp and fallback traffic.
These are **not all independent cURL benchmark observations**.

Across these heterogeneous requests, median PHP loader wall times
were approximately **64.118 ms for thumbnail** (368 observations)
and **99.770 ms for photo metadata** (13 observations).
The median client-observed thumbnail TTFB in its separate cold sample
was **64.762 ms**. These are unpaired distributions, so
**TTFB cannot be subtracted from loader wall time to calculate SQL or
wire duration**. Authorization, SQL query execution, JSON serialization,
revision SHA-256, filesystem metadata verification, PHP-FPM workers
and real network transit remain unresolved individual cost centers
(`UNKNOWN`). The disposable PHP CLI server is single-worker by
default. Median client gate wait in the serial cold test was negligible;
most measured cost appears before the first HTTP response byte.

## Deterministic fault and cancellation evidence: T7

The actual WinApp media transport on the disposable copy observed:
- Injected **429**: client status 429, category `throttled`, 1 HTTP read.
- Injected **502**: client status 502, category `http_status`, 1 HTTP read.
- Injected **503**: client status 503, category `http_status`, 1 HTTP read.
- Truncated response: `LinkingResponseError`, no corrupt disk cache hit.
- Cancellation while waiting on the real FIFO gate: **0** HTTP requests sent.
- Cancellation during intentionally delayed actual HTTP response:
  client failed safely, with **1** started HTTP request.
- Fault removed: the next authorized healthy thumbnail came from
  **network** and succeeded.

These are **controlled fault-injection states**, not real WEDOS
gateway observations. The application preload worker's exact retry
count/backoff schedule, connect timeout, cross-session shutdown
under active concurrent preload and all rate-limit recovery windows
remain **NOT MEASURED**.

## Real Watcher inventory and FIFO contention: T8

A separate, ephemeral upload-capable grant in the isolated MariaDB
authorized an actual `inventory()` request. The production
`LinkingSession` admitted genuine operations in the observed order:

1. Foreground/media thumbnail preload.
2. **Watcher inventory**, end-to-end **148.498 ms**.
3. Photo catalogue metadata refresh.
4. Second thumbnail preload.

All four operations completed successfully; Watcher was admitted before
later thumbnail work, with no starvation in this bounded sample.
The test preserved the client's real FIFO gate. The exact Watcher
gate-wait phase is not separately instrumented, and the single-worker
PHP server cannot prove hosted WEDOS server concurrency.

**Not measured:** a real multipart upload under contention, sustained
many-worker fairness, cancellation while upload is committing, separate
Windows GUI rendering, concurrent PHP-FPM pool limits.

## Acceptance evidence matrix

| A1 part | Verified result | Missing requirement |
| --- | --- | --- |
| T1, cold thumbnail | **PASS: real headless client + API**, 120 GET | GUI first paint; repeated independent samples |
| T2, warm disk/RAM | **PASS: 120 disk and 120 RAM hits** | Full GUI lifecycle and production-size memory behavior |
| T3, refresh | **PARTIAL: no-change refresh** | Changed, added, removed photo; in-flight refresh cancellation |
| T4, tree-v1 full | **PASS: transport/client parse 500, 2k, 10k** | Actual PhotosWorkspace reconciliation and phase-separated SQL |
| T5, unchanged | **PASS: revision response and parse** | End-to-end GUI reconciling |
| T6, legacy fallback | **BLOCKED: 429 after 79/201 pages** | Complete 201-page fallback across 10,001 galleries; historical server |
| T7, faults | **PARTIAL: 429/502/503, truncation, cancellation** | Automatic retry/backoff, connect timeout, complete shutdown |
| T8, contention | **PARTIAL: real FIFO Watcher inventory** | Multipart upload and sustained worker fairness |

The original A1 issue checkbox must remain **UNCHECKED**. Do not use
these observations as grounds to implement batch thumbnail API
or a new production tree-cache algorithm yet.

## Rerun and cleanup contract

The existing
[`.github/workflows/issue-362-a1-baseline.yml`](../../.github/workflows/issue-362-a1-baseline.yml)
runs on pushes to its exact feature branch and can be dispatched
by an authorized maintainer. It creates its own disposable fixture,
runs `tools/perf/issue_362_a1_http.php` within PHP CLI, and passes
real ephemeral bearer grants only to isolated Python children. The client code
is `tools/perf/issue_362_a1_connected.py`; the earlier cURL and Tk
owners are retained. Per-run data, exact source SHA, UTC clocks,
120 individual cold/warm samples and redacted fault/fairness observations
are stored in `cache/test-audit/issue-362-a1-client-*.json`.
The fixture finally shuts down its server, drops its temporary MariaDB,
and deletes the copied application and test-only router fault file.

**No TeX manual impact:** this work only modifies disposable testing
and passive performance documentation. No production or public API,
client-visible behavior, auth scope granting, user workflow or release
instructions have changed.
