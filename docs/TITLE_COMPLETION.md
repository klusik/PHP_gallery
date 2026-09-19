# Bounded administrator gallery-title completion

The create-gallery form exposes `title_completion = {candidates: [], url: ...}`.
The controller constructs the URL with `url_for('admin_gallery_title_completion')`.
No title catalog is loaded for this view model. The same empty model is used by
the full page and an injected side-panel form. Existing galleries and image forms
have an empty completion URL. Other gallery pickers are outside this change.

## HTTP and frontend contract

Administrator-only GET accepts `q` and `parent_id`. Omitted `parent_id` means `0`,
the root scope. IDs must be nonnegative decimal integers representable by PHP;
arrays, negative values, leading-zero strings other than `0`, and overflow are
rejected. Request IDs select priority only; this is an administrator catalog,
not a public gallery lookup or a parent existence check.

The envelope on every controller status is:

```json
{
  "ok": true,
  "candidates": [
    {"id": 123, "parent_id": 7, "title": "Flight 0123", "created_at": "2026-09-20 12:00:00"}
  ],
  "normalization": "nfkc-lowercase",
  "truncated": false
}
```

`candidates` contains at most eight objects, with only the four documented fields.
IDs are integers; root parent IDs are represented as `0`, including stored NULL.
Titles retain their stored spelling after the existing outer-whitespace trim.
Timestamps retain the SQL `YYYY-MM-DD HH:MM:SS` representation. No folder path,
access token, authorization policy field, or database exception is exposed.

The endpoint always emits JSON with `Cache-Control: private, no-store, max-age=0`,
`X-Content-Type-Options: nosniff`, and `X-Robots-Tag: noindex, nofollow`. It never
redirects an unauthenticated JSON caller into a login document. Anonymous users
and viewers receive 401; a non-GET administrator request receives 405 and
`Allow: GET`; malformed inputs receive 400. Suggestion/database/authentication
failures receive 503, `ok:false`, empty candidates, and `truncated:true`. Error
responses use the same envelope. Successful bounded searches receive 200.

`q` must be valid UTF-8, at most 255 Unicode code points and 1,024 bytes. Oversized
input is rejected, not silently shortened. Inputs with fewer than two code points
or only Unicode whitespace return an empty success without title queries. The
prefix otherwise preserves typed whitespace. Candidate titles independently obey
the same character/byte bounds, and the final encoded body has a 16,384-byte cap.

The frontend may use `ok` and `candidates` alone. `normalization` and `truncated`
are optional explanatory metadata for the UI. Empty results must never be used
as evidence that a title is unique. Suggestion failure must leave ordinary title
entry and gallery creation available. The frontend owns same-origin URL handling,
debouncing, cancellation, stale-response suppression, and safe grapheme-to-display
suffix mapping. Acceptance changes only the title, never submits the form.

## Matching and deliberately incomplete ranking

When both intl and mbstring are present, `normalization` is `nfkc-lowercase`:
apply NFKC, then locale-independent Unicode lowercase. This follows the browser's
`normalize('NFKC').toLowerCase()` operation order. It is not accent removal,
transliteration, locale-sensitive casing, or full Unicode case folding. For
example, a ligature `ﬀ` matches `ff`, fullwidth letters match their ASCII forms,
and composed/decomposed accents match; `ß` is not treated as `ss`.

When either extension is unavailable, `normalization` is `ascii-lowercase`.
Only all-ASCII queries and all-ASCII titles are eligible. ASCII A-Z is lowered
explicitly; non-ASCII candidates are omitted. This conservative fallback does
not claim Unicode equivalence. PHP/ICU/browser Unicode tables may differ across
runtime versions, so the browser still validates its matching/display boundary.

A candidate's normalized title must begin with the normalized query and be
strictly longer; normalized exact matches are not suggestions. Siblings come
first, with newest `created_at`, then highest `id`. Fallback uses the same order
over the disjoint non-sibling scope. Root siblings include NULL and zero parents.

The service's fixed, named deployment constants are:

| Budget | Value |
| --- | ---: |
| Returned candidates | 8 |
| Examined titles across both scopes | 1,024 |
| Examined siblings | 512 |
| Page size, excluding lookahead | 512 |
| SQL rows per page, including lookahead | 513 |
| SQL page queries per request | at most 3 |
| Materialized rows, including lookahead | at most 1,027 |
| Encoded JSON response | at most 16 KiB |

These are code constants, not browser-controlled limits or a new persisted
setting. The model also independently clamps its page limit to 513. If budgets
are changed, update the runtime contracts and remeasure query/page counts.

The model uses exclusive `(created_at,id)` keyset continuation, not OFFSET. An
extra row determines whether a page exhausted its scope without a COUNT query.
Matching stops immediately at eight candidates, conservatively setting
`truncated:true` without issuing extra sorted queries just to prove exhaustion.
Reaching a scope/scan budget with an extra row also sets `truncated:true`.

**If the sibling scope is truncated, the service stops before fallback.** An
unexamined older sibling could outrank every non-sibling. Returning fewer or zero
candidates preserves this priority; it is an intentional false negative. For
example, a matching sibling older than the newest 512 siblings can be omitted,
even if a newer matching non-sibling exists. Only an exhausted sibling scope
permits fallback. Once fallback reaches the shared budget, still older matches
may likewise be omitted. No result claims catalog-wide exhaustiveness once
`truncated` is true. A small exhausted eligible catalog can return false.

## Query plans and database limitations

SQL lives only in `gallery_model_title_completion_rows()`; the service performs
normalization and ranking. Queries use bound scope/cursor values and a clamped
integer LIMIT. There are no collation-dependent LIKE filters, schema probes,
schema migrations, normalized-title columns, or persisted normalized indexes.

The existing schema has a parent index but no `(created_at,id)` index. Running
EXPLAIN QUERY PLAN against the actual model SQL on the isolated SQLite fixture
shows an indexed `parent_id` lookup plus temporary ordering for siblings, and a
gallery scan plus temporary ordering for both first and continuing fallback
pages. Therefore SQL work is **not independent of catalog size**: LIMIT bounds
returned rows, not the database engine's examined/sorted rows. The worst fallback
uses two such sorted scans after its sibling lookup. No unbounded page loop or
per-candidate sorted query is introduced.

A 10,000-title service budget was considered but would bring catalog-scale PHP
normalization back to typing requests. The current budget bounds application
memory and normalization while honestly allowing omissions. No production MySQL
plan or latency was measured and no live local database was queried. Adding an
index requires representative disposable MySQL EXPLAIN/timing evidence first;
this SQLite measurement is not evidence of the production optimizer's choice.
A strict database wall-clock bound would additionally need database timeout
policy and suitable indexes; client cancellation alone does not supply one.

## Reproducible synthetic measurement

From a source checkout with PHP CLI and pdo_sqlite:

```text
php scripts/benchmark_title_completion.php
```

The script creates disposable `sqlite::memory:` fixtures containing 100, 1,000,
and 10,000 galleries. All creation timestamps are equal to exercise keyset ties;
one quarter of rows belong to the selected sibling scope. It measures the real
model and matching service, captures actual query plans, and never includes the
application bootstrap/configuration or uses an application database. It requires
`tests/support/gallery_title_completion_fixture.php`, so it is a source-checkout
utility, not a deployment dependency.

The removed-catalog baseline reconstructs the old payload with deterministic
synthetic paths. Its timings include query, candidate construction, JSON, and
HTML attribute escaping; paths are generated in PHP rather than stored/read by
SQL, so this is a synthetic payload baseline, not a complete old-page benchmark.
Endpoint timings include model/service execution, fixture instrumentation, and
JSON encoding. Nine runs supply the median. Peak additional PHP memory uses
`memory_reset_peak_usage()` when available and excludes fixture setup; older PHP
runtimes report null instead of a misleading incremental peak. The reported
SQL time is the final sample's prepare/execute/fetch duration, not a median.

Measured on 2026-09-20 using PHP 8.3.30 with intl and mbstring:

| Galleries | Removed embedded JSON attribute value | Removed baseline median / peak extra | Common-prefix JSON / median | Worst fallback miss median / peak extra |
| ---: | ---: | ---: | ---: | ---: |
| 100 | 19,185 bytes | 0.199 ms / 131,416 bytes | 733 bytes / 0.031 ms | 0.137 ms / 50,752 bytes |
| 1,000 | 193,787 bytes | 2.060 ms / 1,273,656 bytes | 741 bytes / 0.192 ms | 1.919 ms / 499,376 bytes |
| 10,000 | 1,957,789 bytes | 23.959 ms / 14,766,608 bytes | 749 bytes / 2.057 ms | 15.050 ms / 520,816 bytes |

The new title-completion view-model JSON is 72 bytes for the sample relative URL,
independent of gallery count, with zero title queries during form preparation.
Exact URL length depends on installation configuration. The title-completion
payload savings apply equally to full-page and dynamic forms; these figures do
not claim complete HTTP response sizes, since the rest of those forms was not
benchmarked here. Browser typing/rendering measurements belong to the frontend
work and are not inferred from PHP timings.

At 10,000 rows, the common prefix uses one query and returns eight candidates.
A sibling miss scans 512 titles plus one lookahead, sets `truncated:true`, and
skips fallback. The worst fallback miss selects a nonexistent parent, uses three
queries, fetches 1,026 rows including lookahead, and returns a 77-byte truncated
empty response. At 100 and 1,000 rows, exhausted no-match responses are 78 bytes
with `truncated:false`. These measurements support fixed application budgets;
they do not establish a production latency SLA.

## Browser matcher CPU measurement

The standalone measurement loads the actual baseline and working-tree modules
in a disposable Chromium document, without an application database:

~~~text
node scripts/benchmark_title_completion_browser.mjs CHROMIUM_PATH 1b823be89095415b3e0991acb9f0a5b3002df024
~~~

It compares the old whole-catalog matcher with eight returned candidates, verifies
the same newest result, warms up each matcher, and takes the median of nine batches
of 500 lookups. The fixture uses ASCII titles with tied timestamps. Results include
the baseline revision, current-source SHA-256 and browser version in ignored
`cache/benchmarks/title-completion-browser.json`. No virtual-time clock is used.

Measured 2026-09-20 in Windows headless Chromium 153:

| Synthetic catalog | Old matcher median | Current bounded matcher median |
| ---: | ---: | ---: |
| 100 | 0.0088 ms | 0.0026 ms |
| 1,000 | 0.0782 ms | 0.0020 ms |
| 10,000 | 0.7914 ms | 0.0020 ms |

Printable ASCII has an inexpensive safe-boundary path; non-ASCII still uses
grapheme segmentation or declines completion when unavailable. These synchronous
matching costs exclude DOM layout/painting, the 180 ms debounce, request latency,
full-page rendering and device-specific input handling. They do not establish a
mobile or end-to-end typing SLA. Hard budgets remain eight candidates, 255 code
points per title and 16 KiB per response, not a flaky wall-clock assertion.

## Regression coverage and verification

`tests/gallery_title_completion_service_test.php` executes the real model SQL,
service, and controller with isolated fixtures. It covers sibling/root ordering,
timestamp ties and second-page keysets, NFKC and conservative ASCII fallback,
empty/short/malformed input, 100/1,000/10,000 row budgets, candidate/body caps,
old-match omission, suppressed fallback after sibling truncation, anonymous and
viewer refusal, GET-only routing, cache headers, and safe failure after partial
results. It has a generous five-second fixture guard, not a production SLA.

`php scripts/audit.php` is the authoritative verification entrypoint. Use
`--profile=quick` during implementation and `--profile=full` before code handoff;
release preparation uses `--profile=release` instead of stacking profiles. The
central audit covers the PHP regression, browser-module contracts, syntax, and
MVC boundaries. Follow `AGENTS.md` for manifest regeneration and release checks
when updater-managed application files change.
