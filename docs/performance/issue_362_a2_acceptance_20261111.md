# Issue #362: A2 diagnostics final acceptance audit (A2.7)

- **Author:** Rudolf Klusal
- **Date:** 2026-11-11 (UTC)
- **Scope:** A2.1 to A2.7 passive diagnostics, production Python/PHP source and isolated CI. Not B1/B2/B3, C1/C2 or D.
- **Repository:** https://github.com/klusik/PHP_gallery
- **Feature branch:** `feature/362-p1winappapiperformance-batched-thumbnail-reads-tree-v1-startup-audit-and-passive-transport-diagnostics`
- **Audit start SHA:** `2005f097a8cb3055056dcd59ea4529922ed86e13`.
- **Targeted repair source SHA:** `1bde2b0957b9d63940d0f977087da4f8d789f6ef`.
- **Verified starting develop SHA:** `5aaf9a772935f1f6f85135968f1df5943e8ef72b`.
- **Final integrated SHA:** **pending owner-authorized GitHub integration**. The exact post-squash qualified SHA and eventual `develop` SHA must be established from actual Actions `head_sha`, Git commit parents and remote readback, not guessed or embedded self-referentially in this document.

## Acceptance decision and nonclaims

The implementation and focused isolated A2 tests **PASS at the targeted repair SHA**. The A2 workstream cannot yet be marked fully accepted or checked in the issue until the exact post-squash candidate receives successful BASIC and all required FULL checks through the existing owner-authorized `develop-integration.yml`, is admitted to `develop` and its true remote commit/parents are verified. A green A2 workflow is not equivalent to that protected integration gate.

No actual WEDOS staging/production request, gateway trace, PHP-FPM worker-slot counter, end-user compositor scan-out, new HTTP batch, performance speedup or network-only latency observation has been produced. Values below are isolated baseline numbers or bounded runtime diagnostic capabilities, not WEDOS service-level guarantees.

## Acceptance matrix

| Original A2 diagnostic requirement | Classification at repair SHA | Source/evidence and precise boundary |
|---|---|---|
| Fixed route and media-kind classification, HTTP results and safe errors | Implemented and tested | `linking_performance.py`, `app_linking.py`, `test_linking_performance.py`; physical GET attempts are not inferred from GUI batches or unmetered socket reuse |
| Real single-thumbnail gate/slot, DNS/TCP/TLS, TTFB, body, bytes, decoding | Implemented and tested where observed | `MediaReadTimings`, `photos_performance.py`; DNS/TCP/TLS only describe actual new connections; no synthetic phase on a reused connection or missing sample |
| Windowed p50/p95/p99 and safely retained cumulative counters | Implemented and tested | 300 monotonic seconds, at most 256 observations for each bounded client reducer; retries never become one-request TTFB |
| Cache source, tree-v1 full/unchanged, paged fallback and fixed reason | Implemented and tested | `photos_catalogue_performance.py`, `photos_workspace.py`; retained/disk metadata is display-only, never a new grant |
| Local snapshot, tree/page, 64-row Tk mount and reconciliation durations | Implemented and tested | Generation-fenced accepted worker timings and actual Tk-owner mounts; combined local cache load/decrypt/parse is not decomposed into invented components |
| First usable cached/fresh rows, viewer, real visible thumbnail | Implemented and tested on actual Tk | `photos_workspace.py`, `test_native_gallery_viewer.py`; only actual Tk `PhotoImage` binding, not a physical monitor paint |
| First fully settled actual visible viewport, placeholders, scroll supersession | Implemented and tested on actual Tk | `viewport_performance.py`, genuine Tk integration; excludes overscan, incomplete, hidden and stale bands and distinguishes real image vs terminal unavailable tile |
| Refreshed catalogue with a still-mounted viewer | Fixed in A2.7 and tested | `rearm_first_viewport_diagnostics()` and workspace fresh-gallery/generation rebind; stale old callback rejected, no extra thumbnail HTTP call; test lives in existing Windows Tk owner |
| Local queue and admitted worker gauges | Implemented and tested | `native_gallery_viewer.py`; client-only instantaneous pending/local/GUI/worker counters, never host-wide PHP overlap |
| PHP SQL, auth, tree JSON/hash, actual file-hash bytes and header-to-emit duration | Implemented and tested | `app_linking_passive_profile.php`, existing PDO observer, PHP 8.1/8.5 + MariaDB 10.11; overlapping phases are not added as disjoint intervals |
| Profiler disabled by default; authorized-only Server-Timing | Implemented and tested | Exact server environment `PHP_GALLERY_A2_PASSIVE_PROFILE=1`; unauthorized 401 and denied Admin 404 have no header; actual allowed 200/304 return bounded numeric header |
| Exact-response PHP/client timing comparison | Implemented and tested | `passive_server_timing.py`, `ServerTimingWindow`; single physical send required; positive remainder is `unattributed_to_measured_php`, **not** network-only or PHP-FPM queue time |
| Privacy-safe copyable Photos diagnostics | Implemented and tested | Fixed labels, numeric values and safe reasons; no raw URL, issuer, gallery/photo title or ID, token, SQL text, path or response body retained in diagnostic reducers |
| 401/403/429/502/503 and HTML gateway failures | Implemented, mixed owner coverage | A2 profiler specifically checks anonymous 401 and Admin 404; A1 isolated transport and WinApp regressions own 429/502/503, HTML fault, retry and gate/cancellation coverage. No live WEDOS failure trace |
| Old/new server interoperability for nonbatch routes | Implemented, A1/A2 fixture qualified | Existing 50-row page and single-image transports are unchanged; optional tree remains fallback; **new batch interoperability is intentionally deferred to B/D** |
| Actual WEDOS gateway header propagation or proxy buffering | Not verified, environment constraint | Only independently isolated loopback HTTP tested; requires authorized staging under D, without production stress |
| True host-wide worker count, pre-PHP queuing, other-site overlap, PHP-FPM saturation | Not measurable from current application telemetry | Explicit **unknown**; only hosting provider/runtime can verify, and request-local PHP profiling cannot observe arrivals blocked before PHP |
| Physical pixel-on-monitor timing / whole desktop callback latency | Not measured | Tk owns widget binding and geometry, not Windows compositor scan-out; full workload or end-user performance requires separate D acceptance |
| Thumbnail-batch request count, per-item frame publication and 8/16/24/32 results | Not yet implemented, correctly B1/B2/B3 | No batch endpoint, capability, protocol, parser or benchmark was added by A2 |
| Tree server materialization/invalidation | Not yet implemented, conditional C2 | No unsafe cache or weakening of 10,000-row/4-MiB tree limits; C1 must first establish a real measured hotspot |
| Required exact-candidate BASIC/FULL and protected develop readback | Pending integration gate | Cannot infer FULL from the isolated A2 workflow. Integration owner and trusted required checks must establish it |

## Actual hosted evidence

| Source SHA | Workflow | Exact evidence |
|---|---|---|
| `2005f097a8cb3055056dcd59ea4529922ed86e13` | [A2 #42993357824](https://github.com/klusik/PHP_gallery/actions/runs/42993357824) | SUCCESS: A2.6 portable and genuine Windows Tk plus isolated PHP profiling |
| `2005f097a8cb3055056dcd59ea4529922ed86e13` | [BASIC #42993357972](https://github.com/klusik/PHP_gallery/actions/runs/42993357972) | SUCCESS |
| `1bde2b0957b9d63940d0f977087da4f8d789f6ef` | [A2 #42995757782](https://github.com/klusik/PHP_gallery/actions/runs/42995757782) | SUCCESS, four independent jobs: Python 3.14 Ubuntu 24.04, Python 3.14 Windows 2025 with actual Tk, PHP 8.1 + MariaDB 10.11, PHP 8.5 + MariaDB 10.11 |
| `1bde2b0957b9d63940d0f977087da4f8d789f6ef` | [BASIC #42995757763](https://github.com/klusik/PHP_gallery/actions/runs/42995757763) | SUCCESS |

A1's independently isolated full HTTP + media cache + MariaDB + Windows Tk baseline is documented in [issue_362_a1_final_20261110.md](issue_362_a1_final_20261110.md) and [A1 run #42882790798](https://github.com/klusik/PHP_gallery/actions/runs/42882790798). Its measurements include a historical 120-image cold set, warm disk/RAM reuse with zero further GET, 500/2,000/10,000-row tree work, bounded overflow pagination and injected HTTP faults. Those are **not newly measured A2.7** numbers. No A2.7 timing percentile is fabricated without a retained numeric artifact.

## A2.7 defect and minimal repair

Before A2.7, `_refresh_catalogue()` advanced the catalogue generation and cleared first-once startup milestones but preserved the current native photo viewer. Its `on_first_viewport_settled` callback remained captured against the older generation and `_first_viewport_reported` stayed true. Consequently an unchanged, already-settled viewport could never report the newly refreshed catalogue pass.

A2.7 rebinds the retained viewer's callbacks only after the selected gallery ID is confirmed by the current fresh tree or authorized page and only once per catalogue generation. A native viewer method rearms a new real coalesced viewport observation. There is no API request, synthetic success, new timer class, extra worker, cache authority, transport slot or modified thumbnail publication. Stale previous-generation callbacks are still rejected. A genuine Windows Tk fixture verifies the old callback is ignored, the exact viewer survives, the new fresh-to-viewport milestone exists, a second real viewport sample is observed and there are no additional thumbnail HTTP reads.

A reused already-visible photo is **not** misleadingly asserted to have been freshly created during a catalogue refresh: the first-new-thumbnail milestone can remain **unknown** when no new `PhotoImage` is attached. The refreshed viewport milestone still reflects completion of the actual visible band. The currently admitted band may consist entirely or partly of terminal unavailable placeholders; a settled band is not synonymous with successful image display.

## Security, hosting and semantics

- No new HTTP polling, increased process/session transport concurrency, public route, API key scope, client profiler toggle or persistent diagnostic storage.
- No PHP profiler header before live bearer/scope/gallery checks; denied and anonymous requests receive no diagnostic phase leak.
- Existing session grant, private/Admin/NSFW, cache validator, filesystem containment, transport admission, refresh cancellation and snapshot-authority rules remain the owners.
- `Server-Timing` ends before the response body stream; an arithmetic remainder against send-to-headers is not pure RTT or server queue time.
- No production WEDOS access, no load test, no cache-clearing Admin Test Run, no service/daemon, no Composer/npm/framework/Redis, no new schema.
- Existing public legacy API-key reference for #352 remains unchanged; browser-approved routes were not added to it.

## Residual work and hard acceptance boundary

**B1:** benchmark bounded framed streaming vs `multipart/mixed` with representative 8/16/24/32 synthetic thumbnail groups, optionally `ZIP STORE`; compare startup paint, bounded memory, response completeness, per-image outcome/integrity, first-frame latency, encoding overhead, timeout/cancellation, buffering and retry behavior; choose explicit wire format and byte/item caps from evidence, without production load.

**B2:** introduce the selected capability-gated bearer-only, read-only batch route; reuse per-item gallery grant, private/Admin visibility, canonical path, rate-limit and cache authority; reject cross-gallery/oversize/truncated data, account for each item and prohibit shared private-response caching.

**B3:** implement existing one-session transport-owner visible-first batch scheduling with a permanent single-item/old-server fallback, immutable per-frame publication, cache proof checks, abort/backoff and fair upload/Watcher priority.

**C1:** qualify existing tree-v1 full/unchanged/legacy paths and actual server/client hotspots under representative cold/warm catalogue sizes, including the 10,001-row overflow, before optimizing. **C2:** only if C1/staging evidence warrants it and complete authorization-aware mutation invalidation is proved, evaluate bounded private server tree caching; otherwise do not implement it.

**D:** full mixed-version/security matrix, exact-candidate Linux/Windows/PHP FULL integration, owned staging-only proxy/streaming qualification, reproducible before/after viewport and catalogue metrics, resource limits and safe rollout gate. WEDOS worker availability remains unknown without authorized provider evidence.

**To close A2:** produce a one-commit feature squash based directly on the current develop, obtain BASIC for the exact candidate SHA, run `.github/workflows/develop-integration.yml` with `source_branch` set to the existing feature branch and `auto_squash_generated=true`, require its complete FULL and immutable integration artifacts, then independently read back `develop`, source parents and required statuses. Only then check the A2 box while leaving #362 open for B/C/D.
