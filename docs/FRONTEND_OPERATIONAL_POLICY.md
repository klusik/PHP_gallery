<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/FRONTEND_OPERATIONAL_POLICY.md
Module Type: Maintainer Guide
Purpose: Record the bounded frontend operational-policy migration and its remaining inventory.
Responsibilities:
  - Identify exact immutable owners, unchanged consumers, reviewed exceptions and verification limits.
Author: Rudolf Klusal
-->

# Frontend operational policy

This is a bounded P12 source migration, not a whole-JavaScript or whole-repository
completion claim. It covers title completion, Settings search, navigation-data,
gallery migration and report interaction policy, and reviews the existing bounded
gallery-picker policy. Migrated client values and scheduling semantics are unchanged;
the report browser now omits its fixed batch size so the server default owns that value.

## Ownership

[admin-interaction-policy.js](../public/assets/gallery-modules/admin-interaction-policy.js)
is the minimal shared immutable owner for the fourteen original browser values
below and seven report values documented in the final tranche section.
Every definition documents type, units, scope, consumers, rationale and range.
It has no imports, configuration fetch, document access, side effects or mutable
shared state. Equal numbers with different meanings remain separate definitions.

[gallery-picker-policy.js](../public/assets/gallery-modules/gallery-picker-policy.js)
remains the focused picker owner; its three existing definitions were reviewed
and not copied into the shared Admin policy asset.

[configuration_defaults.php](../app/configuration_defaults.php) continues to own
deployment-tunable PHP defaults. This migration does not change that map, add
browser-editable settings, serialize server configuration or expose secrets.
The server remains authoritative for query admission, response limits and
mutation authorization. The browser's title limits preserve the existing public
endpoint contract; they do not replace server validation or its scan budgets.

## Exact migrated inventory

All fourteen initial old and new values are identical. Definitions are in
`admin-interaction-policy.js`; the table names their consumers' behavior.

| Definition | Preserved value and units | Consumer behavior |
| --- | --- | --- |
| `GALLERY_TITLE_COMPLETION_MIN_CHARACTERS` | 2 Unicode code points | Title matcher and request admission |
| `GALLERY_TITLE_COMPLETION_MAX_CHARACTERS` | 255 Unicode code points | Title request admission |
| `GALLERY_TITLE_COMPLETION_RESULT_LIMIT` | 8 candidate rows | Title response consumption |
| `GALLERY_TITLE_COMPLETION_DEBOUNCE_MS` | 180 milliseconds | Title request scheduling |
| `GALLERY_TITLE_COMPLETION_REQUEST_TIMEOUT_MS` | 5000 milliseconds | Title fetch deadline |
| `ADMIN_SETTINGS_SEARCH_RESULT_LIMIT` | 12 result links | Settings visible result cap |
| `ADMIN_SETTINGS_SEARCH_PREFIX_SCORE` | 100 dimensionless ranking points per token | Settings label prefix relevance |
| `ADMIN_SETTINGS_SEARCH_WORD_SCORE` | 70 dimensionless ranking points per token | Settings label word-start relevance |
| `ADMIN_SETTINGS_SEARCH_SUBSTRING_SCORE` | 50 dimensionless ranking points per token | Settings label interior relevance |
| `ADMIN_SETTINGS_SEARCH_KEYWORD_SCORE` | 10 dimensionless ranking points per token | Settings description-only relevance |
| `ADMIN_SETTINGS_SEARCH_HIGHLIGHT_MS` | 1800 milliseconds | Settings destination highlight lifetime |
| `ADMIN_NAVDATA_COPY_FEEDBACK_MS` | 1600 milliseconds | Navigation-data clipboard feedback |
| `ADMIN_NAVDATA_LOOKUP_MIN_CHARACTERS` | 2 UTF-16 code units | Navigation-data lookup admission |
| `ADMIN_NAVDATA_SUBMIT_FEEDBACK_MS` | 250 milliseconds | Navigation-data import busy-state feedback |

The first five are consumed by `admin-gallery-title-completion.js`, the next six
by `admin-settings-search.js`, and the last three by `admin-navdata-panel.js`
(copy/lookup) and `admin-navdata-update.js` (submission).
The previous local `MIN_COMPLETION_CHARACTERS` definition was removed.
Settings keeps additive token scoring, label tie-breaking and exactly the same
keyboard navigation. Navigation submission still waits for two animation frames,
then 250 milliseconds; there is no new network timeout, redirect or confirmation.
The existing ordinary POST fallback was not converted or otherwise redesigned.

## Reviewed picker policy and PHP refactor status

| Owner / definition | Preserved value | Disposition |
| --- | --- | --- |
| Browser `GALLERY_PICKER_SEARCH_DEBOUNCE_MS` | 200 ms | Existing focused owner retained |
| Browser `GALLERY_PICKER_DISPLAY_DEPTH_LIMIT` | 8 path levels | Presentation-only clamp; full path still visible |
| Browser `GALLERY_PICKER_POSITIVE_ID_PATTERN` | `/^[1-9][0-9]{0,18}$/` | Exact decimal strings, no Number coercion; root is the separate explicit zero action |
| Core `GALLERY_PICKER_SEARCH_PAGE_SIZE` | 30 rows | Moved previously to `app/policy_constants.php` |
| Core `GALLERY_PICKER_QUERY_MAX_BYTES` | 1024 UTF-8 bytes | Same central owner |
| Core `GALLERY_PICKER_TITLE_MAX_BYTES` | 1024 UTF-8 bytes | Same central owner |
| Core `GALLERY_PICKER_PATH_MAX_BYTES` | 4096 UTF-8 bytes | Same central owner |
| Core `GALLERY_PICKER_TITLE_MAX_CHARACTERS` | 255 code points | Same central owner |
| Core `GALLERY_PICKER_PATH_MAX_CHARACTERS` | 1024 code points | Same central owner |
| Core `GALLERY_PICKER_JSON_MAX_BYTES` | 524288 bytes | Same central owner |
| Core `GALLERY_PICKER_HTML_MAX_BYTES` | 1048576 bytes | Same central owner |

The eight PHP symbols are in namespace `Gallery\Core`; model/service/controller
consumers use explicit `use const` imports and the dependency-free central file.
The browser receives the page-size policy through its existing prepared
`data-page-size` field, not a copied browser constant or an editable query limit.
The follow-up moved the distinct server title constants from the service to the
same dependency-free Core owner, preserving their values:
`GALLERY_TITLE_COMPLETION_MAX_CANDIDATES=8`,
`GALLERY_TITLE_COMPLETION_SCAN_BUDGET=1024`,
`GALLERY_TITLE_COMPLETION_SIBLING_BUDGET=512`, and
`GALLERY_TITLE_COMPLETION_PAGE_SIZE=512`.
The service now imports all four explicitly, with no duplicate Services-namespace
definitions. These are immutable application work ceilings, not configurable
deployment defaults: the service never read them from config.php or a form.
The 512-row sibling allowance suppresses fallback when unexamined siblings could
outrank it; SQL lookahead is not counted as an examined row.

The parent-owned galleries model retains its independent 512 default / 513
defensive fetch cap. The exact coordinated follow-up is to import
`Gallery\Core\GALLERY_TITLE_COMPLETION_PAGE_SIZE`, use it as the function default,
and use `GALLERY_TITLE_COMPLETION_PAGE_SIZE + 1` in the existing clamp. Ensure
the standalone model include contract loads `dirname(__DIR__) . '/policy_constants.php'`
before any call relies on that default, rather than depending on service load order.
This worker did not edit that owned file. The new disposable PHP fixture checks
that its current defensive cap remains aligned with Core policy.
The title service's separate literal query/title admission rules remain outside
this four-constant relocation.

## Exact reviewed exceptions

| Reviewed source | Retained local values and reason |
| --- | --- |
| `admin-gallery-title-completion.js` | Five CSS selectors are private markup/event roles, now documented beside their owner. Zero-delay focusout cleanup is next-task ordering, not an operational wait. IME key code 229 and ASCII/Unicode matching expressions are compatibility rules, not timing policy. State counters, ranking comparator signs and empty sentinels remain ordinary algorithm syntax. |
| `admin-settings-search.js` | DOM roles, active-index -1, boolean/sort signs and empty-array initialization are state/algorithm details. requestAnimationFrame still schedules target highlighting after the local tab change. |
| `admin-navdata-panel.js` | Six-decimal coordinate formatting and the offscreen clipboard textarea position are presentation/legacy adapter behavior, outside this operational policy pass. No timeout was added to diagnostic lookup. |
| `admin-navdata-update.js` | Two nested animation-frame callbacks retain their ordering; readiness flags and ordinary POST behavior are unchanged. |
| `searchable-gallery-picker.js` | Prepared page size is server-owned; selection/cursor strings and root zero are protocol state. Map/observer ownership and generation counters are lifecycle state. No extra client page-size constant or unbounded fallback was introduced. |
| `gallery-picker-policy.js` | Existing debounce, depth and ID representation owner is already appropriate; no value or consumer changes in this pass. |
| `admin-language-selector-design.js` | Reviewed read-only. DESIGN_COLOR_PROPERTIES and DESIGN_PIXEL_PROPERTIES map editor fields to CSS variables, not global operational policy. Theme colors, percentages and local presentation fallbacks remain untouched. Their documentation findings remain visible. |

`admin-operations.js` and `gallery.js` were traced as import edges only, not
claimed as fully reviewed behavior modules.

## Broader lexical inventory, not semantic acceptance

A read-only `check_policy_constants.php --json` inventory initially selected the 66
`.js` files directly discovered under `public/assets/gallery-modules` on
2026-09-20 after this migration. It observed 82 uppercase definitions and
94 advisory findings: 60 constant-documentation findings, 5 named numeric
assignments and 29 literal timer candidates. No duplicate-name finding was
reported in that selected set. Concurrent work can change these counts.

The earlier same-session observation before the five selector documentation
repairs had 99 findings; that is a documentation delta, not five policy moves.
Outside the explicit review above, the following paths remain lexical candidates:

| Paths relative to gallery-modules | Remaining finding counts in this snapshot |
| --- | --- |
| admin-gallery-benchmark.js; admin-logs.js; admin-media-renamer.js | 3; 1; 1 |
| admin-metadata-organizer.js; admin-refresh-progress.js; admin-search-diagnostics.js | 4; 1; 1 |
| admin-side-panel.js; admin-test-run.js; admin-update-jobs.js | 1; 3; 3 |
| gallery-download.js; lightbox-deferred.js; lightbox-preload-lifecycle.js; lightbox.js | 7; 2; 1; 8 |
| public-home-search.js; public-thumbnail-render-diagnostics.js; responsive-thumbnails.js | 3; 7; 1 |
| thumbnail-warmup.js; admin-date-picker.js; admin-duplicate-photo-detector.js | 4; 2; 1 |
| admin-gallery-date-suggestion.js; admin-gallery-migration.js | 2; 6 |
| admin-language-selector-design.js; admin-mutation-completion.js; admin-panel-policy.js | 2; 1; 7 |
| lightbox-zoom-model.js; progressive-thumbnail-renderer.js; progressive-thumbnail-upgrade.js | 6; 4; 1 |
| public-photo-drop-actions.js; zip-stream-writer.js | 3; 7 |

The remaining title-completion finding is its reviewed next-task zero timer.
No baseline or scanner exemption was added for it.

The scanner is conservative: literals in lower-case assignments, configuration
maps, function arguments, strings and complex syntax are not completely covered.
No finding is not proof that a module contains no operational policy.
This inventory excludes assets outside this directory, inline view scripts, CSS,
PHP, other languages, and generated/vendor output. It is not a repository-wide
P12 audit. Browser-agent-owned panel/lifecycle/drafts/policy/reordering/organizer
modules and the P5 upload module were not edited; zoom and renderer behavior were
also left untouched. Expansion requires a separate semantic review.

## Import/cache handoff and verification

All four consumers import
`./admin-interaction-policy.js?v=20260920-admin-interaction-policy-v1`.
`gallery.js` now references the corresponding refreshed Settings/navigation
imports, title completion `20260920-gallery-title-completion-policy-v4`, and
`admin-operations.js?v=20260920-panel-lifecycle-interaction-policy-v2`.
The operations facade refreshes its navigation-data-update export while retaining
other agents' unrelated export changes.

The already registered picker parent Chromium fixture allowlists the policy
route. The follow-up also added `/admin-interaction-policy.js` to the title
Chromium fixture and title benchmark, preserving existing headers and private
fixture routing. The benchmark records the current policy source SHA-256 alongside
the current module hash, without claiming fresh measurements. Parent central
runs remain the browser verification authority.

New `tests/frontend_operational_policy_test.mjs` was developed and passed with:

```text
node tests/frontend_operational_policy_test.mjs
```

It imports the real policy exports and executes current title/Settings/navigation
consumer bodies in disposable DOM/timer seams. It checks exact unchanged values,
attached policy documentation, no policy runtime dependencies, code-point title
admission, debounce/deadline/result cap, Settings ranking/result cap/highlight,
clipboard feedback, short identifier rejection, two paint frames before delayed
submission, and import cache revisions. It is a Node seam, not browser acceptance.
Parent registration is `'frontend_operational_policy_test.mjs' => []`, not
`browser => true`.

Read-only policy/documentation inventory commands were also used. No central
audit, existing normal test suite, production bootstrap, live database mutation,
release metadata or manifest operation was run by this frontend pass. The newly
changed ESM integration still needs the parent's registered Chromium/central
run; earlier picker browser evidence is documented separately in
[GALLERY_PICKER.md](GALLERY_PICKER.md). No new end-to-end create/save/move or
JavaScript-disabled acceptance claim is made here.

## Changed paths in this frontend pass

These are this worker's P12 changes, not the complete concurrent worktree diff:

- `public/assets/gallery-modules/admin-interaction-policy.js` (new)
- `public/assets/gallery-modules/admin-gallery-title-completion.js`
- `public/assets/gallery-modules/admin-settings-search.js`
- `public/assets/gallery-modules/admin-navdata-panel.js`
- `public/assets/gallery-modules/admin-navdata-update.js`
- `public/assets/gallery-modules/admin-operations.js` (navigation import only)
- `public/assets/gallery.js` (owned import revisions only)
- `tests/frontend_operational_policy_test.mjs` (new)
- `tests/gallery_picker_parent_integration_browser_test.mjs` (policy asset route only)
- `docs/FRONTEND_OPERATIONAL_POLICY.md` (new)
- `docs/GALLERY_PICKER.md` (registration status and policy-evidence distinction)

The earlier picker PHP/browser refactor is described in GALLERY_PICKER.md; its
files are not misattributed as new P12 edits.

## Follow-up bounded batch: gallery migration

Read and traced `admin-gallery-migration.js` through form normalization,
request deadlines, package retry, target-status confirmation, cancellation and
the unchanged mutation-completion handoff. Its six definitions now live in
`gallery-migration-policy.js`, imported with
`?v=20260920-gallery-migration-policy-v1`.
The gallery entrypoint refreshes the consumer to
`admin-gallery-migration.js?v=20260920-gallery-migration-policy-v3`.

| Previous module-local name | New focused-owner definition | Unchanged meaning/value |
| --- | --- | --- |
| DEFAULT_RECONNECT_SECONDS | GALLERY_MIGRATION_DEFAULT_RECONNECT_SECONDS | 30 seconds, only the absent/invalid-input fallback |
| MIN_RECONNECT_SECONDS | GALLERY_MIGRATION_MIN_RECONNECT_SECONDS | 5-second browser lower bound |
| MAX_RECONNECT_SECONDS | GALLERY_MIGRATION_MAX_RECONNECT_SECONDS | 300-second browser upper bound |
| MAX_PACKAGE_RETRIES | GALLERY_MIGRATION_PACKAGE_ATTEMPT_LIMIT | 6 total transfer attempts, including the first; at most 5 resends |
| STATUS_PROBE_COUNT | GALLERY_MIGRATION_STATUS_PROBE_LIMIT | 4 target-status probes per interrupted transfer |
| STATUS_PROBE_DELAY_MS | GALLERY_MIGRATION_STATUS_PROBE_DELAY_MS | 1500 milliseconds between probes, with no initial wait |

The reconnect input remains administrator-selected, with the existing parseInt
behavior and clamp. The 30-second fallback is not a forced setting or a newly
writable configuration default. The existing PHP reconnect owner and rendered
5/300 form bounds were traced read-only; moving those independent server
definitions is not included. The existing browser zero/negative clamp and
PHP nonpositive fallback differ; this refactor does not silently reconcile them.

Operational retry order is unchanged: after each failed package request, inspect
target receipt before deciding whether to resend. A confirmed package stops
resends. When all status observations fail, the existing error exits the transfer
instead of guessing receipt state. Cancellation still stops the next attempt.
The local canonical mutation envelope/event is untouched.

Retained local literals: the seconds-to-milliseconds unit conversion, zero/one
loop and progress state, JSON/form field vocabulary and 95/100 progress
presentation. These were reviewed, not mechanically promoted to global policy.
No zoom, renderer, panel lifecycle, upload, organizer or telemetry-owned module
was changed in that batch. `admin-gallery-report.js` was initially inspected and
deferred; its separately authorized follow-up is documented below.

Selected read-only policy inventory of the migration consumer plus its new owner
reported six definitions and zero findings. This narrow lexical result does not
replace semantic tests or establish whole-JavaScript P12 completion. The earlier
66-module/94-finding snapshot above is historical, not a newly measured total
after this additional asset and other agents' concurrent changes.

### Follow-up verification and changed paths

Only these newly developed fixtures were executed for this follow-up:

- `php tests/gallery_title_completion_policy_test.php` — PASS on disposable
  SQLite: exact Core budgets, no duplicate service owner, browser cap parity,
  bounded real model queries and sibling-first truncation.
- `node tests/gallery_migration_policy_test.mjs` — PASS with disposable request
  and timer seams: exact six values, range/fallback/parse behavior, deadline
  cleanup, four-probe spacing, six total attempts, confirmed-receipt suppression,
  unknown-receipt refusal, cancellation, cache imports and fixture route presence.

The PHP test is automatically discoverable by the central PHP runner.
Parent registration for the new Node test is
`'gallery_migration_policy_test.mjs' => []`, not a Chromium entry.
The already registered fourteen-value frontend fixture was not rerun here.
The existing service regression test gained explicit Core imports and immutable
value assertions, but was not independently executed. No central audit,
Chromium run, benchmark run, real API transfer or live database/configuration
access was performed by this follow-up.

Exact follow-up paths, separate from the previous batch:

- `app/policy_constants.php` — four documented Core definitions
- `app/services/gallery_picker.php` — explicit imports and removal of local definitions
- `public/assets/gallery-modules/gallery-migration-policy.js` — new focused browser owner
- `public/assets/gallery-modules/admin-gallery-migration.js` — constant consumers only
- `public/assets/gallery.js` — migration cache import only
- `scripts/benchmark_title_completion_browser.mjs` — allowlisted policy source and provenance hash
- `tests/admin_gallery_title_completion_browser_test.mjs` — policy asset route
- `tests/gallery_title_completion_service_test.php` — Core ownership assertions
- `tests/gallery_title_completion_policy_test.php` — new disposable PHP fixture
- `tests/gallery_migration_policy_test.mjs` — new Node policy fixture
- `docs/FRONTEND_OPERATIONAL_POLICY.md` — current status, inventory and limitations

## Changed-declaration gate and Quick5 follow-up

Read-only `check_source_documentation.php --changed --json` inspection of 33
selected picker/policy/integration paths reports 184 added declarations,
40 changed declarations, zero doc regressions and no blocked coverage.
After this worker repaired 123 findings, the sole remaining finding was in the
browser agent's changed `view_render_admin_image_reorder_script()` declaration
at `app/views/admin_gallery_edit_components.php`: its existing docblock needed
`@return void` describing emitted inline reorder initialization. The parent then
explicitly assigned that documentation fix to this worker. It is now fixed; the
one-file changed-declaration gate passed with zero findings. The earlier combined
33-path report was not rerun and is not a claimed central PASS.

Repairs describe callback parameters, return values, request/event lifetimes,
exact picker response fields, disposable fixture event/options shapes and the
controller's consumed gallery identity. Existing useful comments and author
headers were preserved. These repairs alter documentation/whitespace only.
No baseline or scanner exception was added.

The two reported Quick5 import failures were stale test expectations, not broken
production edges. Both expected the earlier operations-facade revision while
the entrypoint correctly references
`20260920-panel-lifecycle-interaction-policy-v2`. Only the two assertions were
updated; the browser agent's side-panel import revision was preserved.
Both reported regressions were then run individually and passed:

- `php tests/smart_gallery_high_priority_hardening_test.php`
- `php tests/stage4_mutation_hardening_contract_test.php`

No other normal regression, browser run or central audit was executed in this
documentation/Quick5 follow-up. Parent central orchestration remains authoritative.

Exact documentation/Quick5 paths changed by this worker:

- `app/controllers/admin_galleries_edit_views.php` — owned bulk toolbar input shape
- `app/controllers/admin_gallery_picker_search.php` — parsed query input shape
- `public/assets/gallery-modules/admin-gallery-title-completion.js`
- `public/assets/gallery-modules/admin-navdata-update.js`
- `public/assets/gallery-modules/admin-settings-search.js`
- `public/assets/gallery-modules/searchable-gallery-picker.js`
- `scripts/benchmark_title_completion_browser.mjs`
- `tests/gallery_picker_search_test.php`
- `tests/frontend_operational_policy_test.mjs`
- `tests/gallery_migration_policy_test.mjs`
- `tests/gallery_picker_browser_test.mjs`
- `tests/fixtures/gallery_picker_parent_integration.js`
- `tests/gallery_picker_parent_integration_browser_test.mjs`
- `tests/smart_gallery_high_priority_hardening_test.php` — one revision assertion
- `tests/stage4_mutation_hardening_contract_test.php` — one revision assertion
- `docs/FRONTEND_OPERATIONAL_POLICY.md` — evidence and ownership handoff

## Follow-up bounded tranche: report requests

This tranche reviews only `admin-gallery-report.js`, its existing shared policy
owner, the report entrypoint import and focused fixtures. No PHP, report session
checkpoint, telemetry collection/storage, side-panel handler or other browser
agent module was edited. The parent owns the PHP report policy and batching
source assertion.

Seven client definitions were appended to `admin-interaction-policy.js`, bringing
that owner's exact export count to 21. They are immutable client behavior, not
new administrator settings or a server-configuration projection.

| Definition | Preserved value and units | Exact behavior |
| --- | --- | --- |
| `ADMIN_GALLERY_REPORT_DEFAULT_TELEMETRY_DAYS` | 30 days | Absent, empty or non-finite selector fallback; selected values still win |
| `ADMIN_GALLERY_REPORT_MIN_TELEMETRY_DAYS` | 1 day | Lower browser input clamp, not retention policy |
| `ADMIN_GALLERY_REPORT_MAX_TELEMETRY_DAYS` | 3650 days | Upper browser input clamp, not permission for server work |
| `ADMIN_GALLERY_REPORT_REQUEST_ATTEMPT_LIMIT` | 4 total attempts per action | Includes the initial request; at most three resends |
| `ADMIN_GALLERY_REPORT_RETRY_BASE_DELAY_MS` | 750 milliseconds | First retry delay, with no initial wait |
| `ADMIN_GALLERY_REPORT_RETRY_BACKOFF_FACTOR` | 2 dimensionless | Subsequent waits remain 1500 and 3000 ms, without jitter |
| `ADMIN_GALLERY_REPORT_RETRYABLE_HTTP_STATUSES` | Frozen HTTP codes 408, 429, 500, 502, 503, 504 | Only these HTTP failures retry; transport rejections retry independently |

Removed, not relocated: `body.set('batch_size', '250')`. Both start and step
requests now send only the existing CSRF marker, action and telemetry window.
The parent-owned controller already defaults absent batch size to Core
`ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE` (250); its server clamp remains
authoritative. The browser no longer duplicates or overrides that work default.

Retry ordering and response semantics are preserved. Successful or non-retryable
HTTP responses return immediately; the fourth HTTP response is returned even if
unsuccessful, and a fourth transport rejection keeps its original error identity.
JSON-decoding errors are not retried. Requests remain serial, reusing the same
FormData on retry. There is no new deadline, overall job-step budget, Retry-After
handling, status probe, jitter or cancellation behavior. These are explicit limits
of this policy-only refactor, not claims that a whole job is time-bounded.

Window input keeps the existing Number conversion, including fractional values
and whitespace-to-zero followed by the one-day clamp. Browser bounds do not
replace PHP validation. Download headers and the existing JSON/HTML response
adapter are unchanged. The expected consumed response fields now have a named
JSDoc shape; this documents the contract without claiming runtime schema validation.

Reviewed local exceptions remain local: loop zero/one counters and final-attempt
offset; progress 0/100 and decimal precision; byte-format base 1024, display units
and precision thresholds; DOM roles, action/status/header vocabulary; per-panel
WeakMap object-URL ownership. These are algorithm, protocol or presentation
details, not additional global operational knobs. No download or renderer
behavior was redesigned.

### Report cache edges and verification

- `gallery.js` imports `admin-gallery-report.js?v=20260920-admin-gallery-report-policy-v3`.
- The report imports `admin-interaction-policy.js?v=20260920-admin-interaction-policy-report-v2`.
- The older four policy consumers keep their existing v1 imports: their fourteen
  exports and behavior are unchanged, and the dependency-free asset's additive
  exports do not require touching those other handlers.

New `tests/gallery_report_policy_test.mjs` was developed and executed with
`node tests/gallery_report_policy_test.mjs`: PASS. It exercises the actual report
functions in isolated Node VM/request/timer seams, with synthetic Responses and
in-memory FormData only. It covers exact seven values and documentation, frozen
status membership, window edge cases, all six retryable statuses, seven immediate
HTTP refusals, four-attempt exhaustion, ordered 750/1500/3000 ms waits, transport
failure/success mixtures, final error identity, both actions' omitted batch field,
preserved request fields and completed HTML/invalid-JSON response handling.

Parent registry requirement: `'gallery_report_policy_test.mjs' => []` in the
plain Node suite, not `browser => true`. The already registered
`frontend_operational_policy_test.mjs` now expects exactly 21 documented exports,
including the frozen report-status array. It was updated but not independently
rerun; its title/Settings/navigation runtime seams remain unchanged.

Read-only scoped changed-documentation gate: PASS, zero findings or blocked
coverage across the five changed JS/test paths. Read-only strict policy inspection
of the report consumer plus shared owner: 21 definitions, zero findings. These
are bounded source results, not a whole-JavaScript or central-audit PASS.
No existing PHP regression, Chromium fixture, authenticated report generation,
live database/configuration access, benchmark or central audit was run for this
tranche. Parent central orchestration remains the integration authority.

Exact tranche paths:

- `public/assets/gallery-modules/admin-gallery-report.js`
- `public/assets/gallery-modules/admin-interaction-policy.js`
- `public/assets/gallery.js` — report import only
- `tests/gallery_report_policy_test.mjs` — new Node seam
- `tests/frontend_operational_policy_test.mjs` — expanded owner inventory only
- `docs/FRONTEND_OPERATIONAL_POLICY.md`
