# TEMP - PHP Gallery Audit Remediation Program

**Status:** Temporary implementation program for automated coding agents  
**Codebase baseline:** `php-gallery-deploy(20260918-233008).zip`  
**Observed application version:** 0.101.2  
**Purpose:** Convert the findings from the September 2026 gallery overview and telemetry audits into a codebase-grounded, staged engineering program.  
**Important:** This document is a working plan, not release documentation. Do not update release metadata, version numbers, manifests, changelogs, or deployment packages merely because a stage is completed.

---

## Implementation status - 2026-09-19

This status block records codebase state so a later agent does not repeat completed
work. Detailed requirements below remain authoritative when changing an implementation.

| Stage | Status | Current state |
| --- | --- | --- |
| 0 | Complete | Baseline semantics and affected subsystems were mapped before mutation. |
| 1 | Complete | Public media/gallery/search authorization invariants are locked by regression contracts; search revalidates listed-gallery plus visitor-aware password/share-link/NSFW policy in the service layer. |
| 2 | Complete | Session/page-view semantics are corrected and versioned with an explicit historical boundary. |
| 3 | Complete | Photo activation uses one state machine, bounded origin buckets, idempotent close, and preload isolation. |
| 4 | Complete | Reports support all / non-bot-classified / bot-classified segmentation without claiming human identity. |
| 5A-5E | Complete | Page-load, visible-image, media-byte, decoded-lightbox-cache, and central DB-query observability paths are wired and labelled by measurement scope. |
| 6 | Code complete, production evidence pending | Metric-specific dimension normalization, representative workload reduction tests, storage/cardinality diagnostics, completed-day hourly-vs-daily rollup consistency, request-local report SQL timings, and sanitized fixed-query EXPLAIN plans are implemented. Do not change indexes or retention until representative hosting measurements justify it. |
| 7 | Complete | Legacy server-ZIP cache health is an explicit capability and fails before expensive build work without disabling the modern path. |
| 8 | Complete | Legacy JPEG thumbnail inventory reuses existing cleanup ownership and remains non-destructive until explicit Admin cleanup. |
| 9 | Complete | Report wording distinguishes empty leaves, structural containers, unavailable capacity, disabled/no-sample telemetry, wide images, and row visibility versus effective access. |
| 10 | Complete | Maintenance sub-operation diagnostics are isolated, bounded, privacy-safe, and surface last success/failure to Admin. |
| 11 | Code complete, production evidence pending | Cross-stage regression contract and permanent architecture/database/testing documentation are complete. The export now captures the Stage 6 evidence needed for a production index/retention decision; the production measurements themselves remain an operator task, not a code guess. |

---

## 1. Agent operating contract

All implementation work based on this document must follow the repository rules in `AGENTS.md` and the current architecture.

### 1.1 Mandatory architectural rules

1. Preserve strict MVC ownership:
   - **Model:** SQL, PDO access, schema-specific persistence and query construction.
   - **Service:** domain policy, validation, reusable orchestration, filesystem workflows.
   - **Controller:** HTTP/request parsing, authentication and CSRF boundaries, status/headers, service invocation, view-model preparation.
   - **View:** presentation only. Do not move policy or SQL into views.
2. The canonical dependency direction is `Controller -> Service -> Model`.
3. Before adding a helper, search for the existing domain owner and extend it instead of creating a parallel implementation.
4. Preserve existing docstrings and comments when moving or materially refactoring code.
5. New PHP files must use `declare(strict_types=1);` and the existing project header conventions, including `Author: Rudolf Klusal` where first-party source/test file headers use that standard.
6. If a schema change is actually necessary, create a new timestamp-prefixed migration in `database/migrations/`. Never edit an already deployed historical migration to retrofit behavior.
7. Do not add MVC baseline exceptions to make new violations pass.
8. Do not introduce Composer, a JavaScript framework, or a Node build system.
9. Preserve existing public API and WinApp upload behavior unless a stage explicitly proves that a change is required. This remediation program is not permission to redesign unrelated interfaces.

### 1.2 Mandatory verification workflow

During implementation, use the repository audit runner as the authoritative verification interface:

```bash
php scripts/audit.php --profile=quick
```

Run the complete audit exactly once before handing off an implementation checkpoint or building an affected-files ZIP:

```bash
php scripts/audit.php --profile=full
```

Do not manually enumerate all tests, run duplicate syntax loops, or execute every focused test separately unless diagnosing a concrete audit failure or developing a newly added test before it is registered.

### 1.3 Scope discipline

This plan deliberately distinguishes three classes of findings:

- **Confirmed defect:** current source code demonstrates a semantic or implementation error.
- **Confirmed observability gap:** the application exposes a metric or report section that cannot currently receive data through the implemented path.
- **Invariant to lock down:** the current code appears correct, but the audit exposed a security or behavioral invariant important enough to protect explicitly with regression coverage.

Do not convert an invariant into a redesign without a failing test or reproduced defect.

---

# 2. Baseline facts and audit evidence

The production-style overview snapshot contained approximately:

- 508 galleries.
- 32,192 image records.
- 112.5 GiB original source media.
- 13.7 GiB generated media.
- 208,228 thumbnail variants, approximately 6.47 generated variants per image.
- 333.9 MiB total database size.
- Approximately 177.4 MiB in the four main telemetry tables, around 53 percent of the total database footprint.
- 61 unpublished galleries.
- 4 password-protected galleries.
- 15,076 legacy JPEG thumbnail variants, approximately 839 MiB, while the current compatibility mode is modern/WebP-oriented.

The 30-day telemetry export contained:

- Executive sessions: 5,841.
- Daily-trend session sum: 5,159.
- Difference: 682.
- Bounce sessions: 682.
- Executive page views: 10,588.
- Daily/route page views: 5,429.
- Difference: 5,159, exactly equal to the daily-trend session sum.
- Bot-classified sessions: 4,966, approximately 85 percent of all reported sessions.
- Photo opens on 2026-09-08 and 2026-09-09 combined: 7,919, approximately 58 percent of all 30-day photo opens, despite only about 60 sessions across those two days.
- Media bytes: 0.
- Cache hit/miss telemetry: no useful data.
- DB query telemetry: no useful data.
- Browser performance section: no useful aggregate data despite raw page-load events being present.

These numbers are evidence for investigation. Historical telemetry may contain data generated under old semantics and must not be silently reinterpreted as if it had always used corrected logic.

---

# 3. Confirmed current-code findings

## 3.1 Gallery authorization is stronger than the raw report implied

Do **not** redesign gallery authorization merely because all image rows report `visibility = public`.

Current code already centralizes important policy in `app/services/gallery_access.php`:

- `gallery_allows_direct_public_request()` intentionally permits both `public` and `unpublished` galleries by normal URL.
- `gallery_access_requirement()` walks the ancestor chain and inherits password requirements.
- `gallery_nsfw_requirement()` inherits NSFW restrictions through the hierarchy.
- `visitor_can_access_gallery()` evaluates the effective gallery access policy.
- `public_image_visible_to_current_visitor()` requires an image row to be public and then delegates to the gallery access policy and NSFW policy.

`app/controllers/public_media.php` and `app/services/downloads.php` already use `public_image_visible_to_current_visitor()` in multiple public-media/download paths.

Therefore the audit finding is primarily an **authorization invariant to regression-test across all public surfaces**, not evidence that the current application is leaking unpublished or password-protected media.

Also preserve the existing semantic distinction:

- `unpublished` means hidden from normal listing/discovery but directly accessible by ordinary URL, subject to other access rules.
- It must not automatically be treated as `private`.
- A public child below an unpublished ancestor is not automatically an error.

## 3.2 Telemetry page-view accounting contains a confirmed semantic defect

`app/services/telemetry.php` currently increments session `page_view_count` for all three events:

- `public.session.started`
- `public.page.viewed`
- `public.gallery.viewed`

A normal first gallery page therefore produces a session-start event and a gallery-view event, resulting in two page views in the session row for a single page visit.

This explains the observed arithmetic relationship between the executive session-table summary and hourly page-view/session aggregates.

The fix must establish the following canonical event semantics:

- `public.session.started`: session lifecycle signal only. It must **not** increment page-view count.
- `public.page.viewed`: one actual non-gallery page view.
- `public.gallery.viewed`: one actual gallery page view.
- `public.photo.opened`: one semantic transition into viewing one photo.
- `public.photo.visible_time`: capped visible duration for that photo activation.

## 3.3 Telemetry session counts use two different sources of truth

`telemetry_model_report_session_summary()` counts rows in `telemetry_sessions`.

`telemetry_model_report_daily_trends()` derives sessions from the hourly metric `public.sessions`, which is emitted from `public.session.started` events.

These cannot be guaranteed to match because:

- the browser stores the `_started` flag in `sessionStorage`;
- the server can create/update a session row from any accepted event carrying the same session identifier;
- a session-start event can be absent while later events still produce a session row;
- client sampling, transport loss, page lifecycle, or historical behavior can affect event delivery.

Canonical reporting should use `telemetry_sessions` for session counts and session-derived metrics, while hourly/daily event aggregates remain the source for event metrics.

## 3.4 Page-load performance events are accepted but never aggregated

The browser sends `client.performance.page_load`.

The server event allowlist accepts performance events, but `telemetry_metric_name_for_event()` does not map `client.performance.page_load` to an aggregate metric. The model report also queries only Web Vitals plus image decode/display metrics.

Result: raw page-load events may exist, while the Browser performance report still says no data.

This is a **confirmed observability gap**.

## 3.5 Thumbnail-byte reporting currently has no valid ingestion contract

The reporting model expects a metric named `media.thumbnail.bytes`.

`telemetry_metric_name_for_event()` can generically transform `media.thumbnail.served` into `media.thumbnail.bytes`, but the telemetry event allowlist does not currently permit `media.thumbnail.served`.

The public media controller records served image bytes in some full-image paths, but the thumbnail pipeline must be examined separately. Do not assume that all thumbnails pass through PHP. Static web-server-served thumbnails cannot be measured reliably by a PHP response hook.

Therefore:

- add a valid event only for thumbnail responses actually served through PHP;
- label the report as PHP-observed media traffic if static responses remain outside telemetry;
- do not route all thumbnail traffic through PHP merely to improve analytics coverage.

## 3.6 Client cache telemetry helpers exist without a proven producer

`PHPGalleryTelemetryCacheEvent()` is present in the public telemetry JavaScript, but no normal caller was found during code inspection.

Do not report `0.0% cache efficiency` when the real state is "not instrumented" or "no samples".

Define the cache domain before implementation. Distinguish at least:

- application/lightbox decoded-image reuse;
- generated derivative existence on the server;
- browser HTTP cache, which is not equivalent to the first two and cannot always be observed reliably.

## 3.7 Database telemetry persistence exists but is not integrated into the normal DB execution path

`app/services/database_observer.php` can persist anonymous DB query aggregates.

Normal DB access does not currently feed it consistently. The existing PDO/PDOStatement instrumentation in `app/database.php` is primarily tied to Admin test-run profiling.

A naive hook from every PDO execution into `telemetry_record_db_query()` would risk recursion because telemetry persistence itself performs database writes.

DB instrumentation must therefore include a request-local suppression/reentrancy mechanism and should aggregate in memory before flushing.

## 3.8 Legacy JPEG cleanup already exists

Do **not** create a second cleanup implementation.

The codebase already contains modern/legacy thumbnail compatibility policy and explicit cleanup operations, including helpers such as:

- `delete_legacy_jpg_thumbnails_for_image()`
- `delete_legacy_jpg_thumbnails_for_image_ids()`

The Admin thumbnail controller already contains legacy JPEG cleanup workflow and tests already cover important thumbnail compatibility invariants.

The improvement should be inventory, visibility, and operator guidance around the existing cleanup action, not duplicate deletion logic.

## 3.9 Legacy server ZIP fallback remains a supported compatibility path

The current download architecture contains a modern progressive/client-side path plus a legacy server-ZIP fallback.

The audit showed the configured ZIP cache as unreadable/unwritable. This does not imply that all downloads are broken, but it can make the legacy/no-JavaScript fallback fail.

Do not disable modern downloads because the fallback cache is unhealthy. Instead expose exact capability health and fail the fallback clearly before an expensive build attempt.

## 3.10 Report terminology contains some misleading zero/empty states

Examples:

- `Empty galleries` currently means "no directly owned images", not "leaf gallery with no content".
- Disk-free/disk-total helpers can surface `0 B` when capacity is unavailable, which can be mistaken for a truly full filesystem.
- `0` media/cache/DB metrics can represent missing instrumentation, not measured zero activity.
- `panorama` is currently based on a wide aspect-ratio threshold and can classify wide screenshots as panoramas.

These are report semantics defects, not necessarily storage/content defects.

---

# 4. Target telemetry contract

Before implementing later telemetry stages, write the event contract into code comments/tests so all reporting uses the same semantics.

| Event / metric | Canonical meaning | Session effect | Aggregate source | Notes |
|---|---|---:|---|---|
| `public.session.started` | Best-effort client lifecycle marker | create/touch only | raw diagnostic, not canonical session count | Never increment page views |
| `public.page.viewed` | One rendered non-gallery public page view | `page_view_count +1` | hourly/daily event metric | Deduplicate only if duplicate emission is proven |
| `public.gallery.viewed` | One rendered gallery page view | `page_view_count +1` | hourly/daily event metric | Same page-view semantic as above |
| `public.photo.opened` | One semantic photo activation | `photo_view_count +1` | hourly/daily event metric | One activation, one event |
| `public.photo.visible_time` | Capped visible duration for active photo | duration only | hourly/daily seconds | Must close exactly once per activation |
| `client.performance.page_load` | Navigation/page-load timing sample | none | `client.page_load_ms` | Respect performance sampling |
| Web Vitals | Browser metric sample | none | `web_vital.*` | Preserve existing metric-specific units |
| `client.performance.image_decode` | Decode duration of a relevant image | none | `client.image_decode_ms` | Avoid counting background preload as foreground UX unless explicitly labelled |
| `client.performance.image_display` | Time to display/ready of a relevant image | none | `client.image_display_ms` | Define start/end points precisely |
| `media.image.served` | Bytes actually served by PHP full-image endpoint | none | `media.image.bytes` | Not total site bandwidth |
| `media.thumbnail.served` | Bytes actually served by PHP thumbnail endpoint | none | `media.thumbnail.bytes` | Add to allowlist before use |
| `media.download.served` | Bytes emitted through tracked download path | none | `media.download.bytes` | Verify all successful paths |
| cache events | Explicitly defined application/server cache observation | none | cache metric | Must identify cache layer |
| DB query aggregate | One executed application query observation | none | `telemetry_db_query_metrics` | Never persist raw SQL or parameters |

Canonical session definition for reporting:

> One row in `telemetry_sessions`, identified by the existing anonymized session hash and first observed within the selected window.

Canonical bounce definition:

> A session with `page_view_count <= 1` after page views count only real `public.page.viewed` and `public.gallery.viewed` events.

Do not derive canonical session totals from the presence of `public.session.started` events.

---

# 5. Execution stages

## STAGE 0 - Establish baseline and freeze semantics

**Priority:** P0 prerequisite  
**Type:** Safety / reproducibility

### Goal

Capture the current behavior before changing telemetry and report semantics so later agents can distinguish intended policy from historical artifacts.

### Required work

1. Read the exact current implementations in:
   - `app/services/telemetry.php`
   - `app/models/telemetry.php`
   - `app/services/telemetry_rollup.php`
   - `app/controllers/admin_telemetry.php`
   - `app/services/telemetry_privacy.php`
   - `app/services/telemetry_settings.php`
   - `public/assets/usage.js`
   - `public/assets/telemetry.js`
   - `public/assets/gallery-modules/lightbox.js`
   - `app/services/gallery_access.php`
   - `app/controllers/public_media.php`
   - `app/services/downloads.php`
   - `app/controllers/downloads.php`
   - `app/services/thumbnail_compatibility.php`
   - `app/controllers/admin_thumbnails.php`
   - `app/models/admin_gallery_report.php`
   - `app/services/admin_gallery_report/*`
   - `app/views/admin_gallery_report_export.php`
2. Determine why both `public/assets/usage.js` and `public/assets/telemetry.js` exist and are byte-identical in the current baseline.
   - `telemetry_append_public_script()` currently loads `usage.js`.
   - Do not maintain two diverging implementations.
   - If one is a compatibility alias/copy, document and test that contract.
   - If one is obsolete, remove it only after proving no route/template/external integration references it.
3. Add or update focused regression fixtures for the canonical event semantics before changing production logic.
4. Run `php scripts/audit.php --profile=quick` after the baseline tests are integrated.

### Acceptance criteria

- Event semantics are represented by tests, not only comments.
- No production behavior has been broadened merely to satisfy the plan.
- Existing public API, WinApp upload flow, map generation, SimBrief-related functionality, gallery routes, and image upload behavior remain untouched unless directly required.

---

## STAGE 1 - Lock public authorization invariants across all media surfaces

**Priority:** P0  
**Type:** Security regression protection, not expected redesign

### Problem

The production report has public image rows inside unpublished/password-related gallery structures. Current policy appears to handle this correctly, but future refactors could accidentally authorize image files from row visibility alone.

### Current implementation to preserve

`public_image_visible_to_current_visitor()` is the canonical image-level public visibility decision and delegates to gallery access plus NSFW policy.

Password and NSFW requirements inherit through gallery ancestors.

`unpublished` intentionally remains direct-URL-accessible and is not equivalent to `private`.

### Required test matrix

Add regression coverage that exercises every relevant public surface with at least these gallery configurations:

1. Public gallery, public image.
2. Unpublished gallery, public image, direct URL.
3. Private gallery, public image.
4. Password gallery before unlock.
5. Password gallery after valid unlock.
6. Public child under password-protected parent.
7. Unpublished child under password-protected parent.
8. Public child under unpublished parent with no password, preserving current direct-access/listing semantics.
9. NSFW gallery/image without grant.
10. NSFW gallery/image with valid grant.
11. Share-token path where supported.
12. Administrator path only where administrator bypass is intentionally part of that surface.
13. Viewer-owned source-image references must use the no-admin-bypass policy where that is already the documented contract.

Surfaces to cover:

- public full-image endpoint;
- public thumbnail endpoint;
- lightbox/source-image URL generation;
- download manifest generation;
- individual/progressive download path;
- legacy server ZIP manifest/build path;
- public search result exposure and subsequent navigation;
- map/image references where source-image URLs can be emitted;
- any API endpoint that exposes public gallery image URLs.

### Search behavior caution

The public search models filter gallery/image visibility and listing state. Do not automatically add `visitor_can_access_gallery()` inside SQL/model code. First define the expected product behavior for password-protected but listed galleries:

- Is title/thumbnail metadata allowed to appear and prompt for access?
- Or must all result metadata remain hidden until unlock?

Use existing UI/help text and tests as the authority. Keep access policy in services rather than moving session-aware decisions into models.

### Likely affected files

- `tests/viewer_identity_boundary_test.php`
- `tests/gallery_access_schema_policy_test.php`
- `tests/public_media_concurrency_hardening_test.php`
- new focused authorization-surface regression test if existing files become too broad
- only if a real gap is reproduced: the owning controller/service/model for that surface

### Acceptance criteria

- No public media surface can bypass the canonical gallery/image policy.
- Unpublished semantics are unchanged.
- Password/NSFW inheritance remains intact.
- No SQL is introduced into controllers or views.
- No access rule is duplicated independently in multiple controllers.

---

## STAGE 2 - Correct telemetry session and page-view semantics

**Priority:** P0  
**Type:** Confirmed correctness defect

### Goal

Make executive, trend, landing/exit, route, and bounce metrics use compatible definitions.

### Required implementation

#### 2.1 Fix session page-view increments

In the telemetry service, change the session-touch logic so:

```text
public.session.started  -> page increment 0
public.page.viewed      -> page increment 1
public.gallery.viewed   -> page increment 1
all other events        -> page increment 0
```

Do not special-case the report to compensate for double counting. Fix the semantic producer.

#### 2.2 Make `telemetry_sessions` the canonical session-count source

Add model queries for daily session counts grouped by `DATE(started_at)` from `telemetry_sessions`.

The daily trend should combine:

- sessions from `telemetry_sessions`;
- page views/photo views/photo seconds/errors/media from the appropriate aggregate/event source.

Do not use hourly `public.sessions` event count as the canonical session total.

`public.sessions` may remain temporarily as a diagnostic/lifecycle metric for backward compatibility, but report labels must not imply it is the authoritative unique-session total.

#### 2.3 Recalculate bounce correctly going forward

The existing session upsert expression can remain conceptually valid once page increments are fixed, but regression tests must prove:

- session with 0 page-view events and only a non-page event remains <=1 and therefore bounce-like by current definition;
- one page view is bounce;
- two page views is not bounce;
- session-start plus one page view counts as one page view, not two.

If product semantics require a session with zero recorded page views to be excluded from bounce-rate denominator, implement that intentionally in the reporting query and document it. Do not let it happen accidentally.

#### 2.4 Add consistency checks to the admin report

Add a diagnostic section or internal consistency status that compares metrics that should agree under the corrected contract, for example:

- session summary total vs sum of daily canonical session rows;
- page-view session sum vs hourly/daily page-view event total for the same date window, allowing documented boundary differences if timestamps/timezones differ.

The report must say why a mismatch exists instead of silently displaying contradictory numbers.

### Historical-data policy

Do not pretend historical rows were produced under the corrected semantics.

Implement one of these non-destructive strategies, preferring the least invasive one compatible with the current settings model:

1. Store a telemetry semantics/version marker and effective timestamp in existing telemetry settings, and show a report note that pre-change history uses legacy semantics.
2. Optionally rebuild only the period for which raw events are still retained if the rebuild can be deterministic and idempotent.

Do not erase telemetry automatically. A destructive reset must remain an explicit administrator action.

### Likely affected files

- `app/services/telemetry.php`
- `app/models/telemetry.php`
- `app/controllers/admin_telemetry.php`
- telemetry report view/export owner
- `app/services/telemetry_settings.php` if semantics metadata belongs there
- telemetry regression tests
- migration only if an existing settings table cannot represent the marker cleanly

### Acceptance criteria

Using a deterministic fixture:

- 1 session start + 1 gallery view = 1 session, 1 page view, bounce true.
- 1 session start + 2 gallery/page views = 1 session, 2 page views, bounce false.
- Daily session sum equals canonical session summary for identical window boundaries.
- Executive page views no longer contain the historical +1 per session artifact.
- Existing raw legacy history is visibly distinguished from corrected data.

---

## STAGE 3 - Make photo-open telemetry one semantic event per activation

**Priority:** P1  
**Type:** Anomaly investigation and client correctness

### Problem

The 2026-09-08 to 2026-09-09 spike is too large to assume normal user behavior, but the current evidence does not prove that preload is the cause.

Current browser telemetry has multiple possible producers:

- the lightbox module calls the global photo-open hook;
- a fallback capture listener can also call the same hook;
- a 500 ms same-image dedupe heuristic suppresses some duplicates.

A heuristic timeout is not a robust event contract.

### Required investigation

Build deterministic JavaScript tests that cover at least:

1. Click thumbnail to open image.
2. Open image from direct/deep link if supported.
3. Next/previous button navigation.
4. Keyboard navigation.
5. Touch/swipe navigation if test harness supports it.
6. Slideshow/automatic navigation if present.
7. Fullscreen transitions.
8. Browser history/back/forward behavior.
9. Rapid next/previous changes.
10. Preloading adjacent images.
11. Reopening the same image after an actual close.
12. Duplicate DOM/capture handler invocation for the same semantic activation.
13. `visibilitychange`, `pagehide`, and teardown so visible-time is flushed once.

### Target state machine

Replace time-only duplicate suppression with explicit semantic state where practical:

```text
closed
  -> open(image A): emit opened(A), start visible interval
open(image A)
  -> duplicate open(image A) without close/change: no-op
  -> open(image B): close A once, emit opened(B), start B
  -> close: close current once, flush duration once
closed
  -> open(image A) later: emit opened(A) again
```

The state owner should be singular. If native lightbox integration is active, the fallback observer must not independently create duplicate semantic events.

### Diagnostic context

Add only privacy-safe, low-cardinality context needed to diagnose event origin, for example an allowlisted trigger/source bucket such as:

- `click`
- `keyboard`
- `slideshow`
- `history`
- `direct`
- `fallback`
- `unknown`

Do not record DOM selectors, URLs, free-form strings, user-entered values, or raw identifiers in context.

Update telemetry privacy normalization/allowlists accordingly.

### Report improvement

Add anomaly-oriented aggregates without exposing session identifiers in exported reports:

- photo opens per session distribution or bounded percentiles;
- sessions above a sensible opens/session diagnostic threshold;
- top galleries by opens/session, with a minimum-session threshold;
- optional event-origin breakdown after the new context is deployed.

Historical spike data should be labelled as legacy/unclassified because it lacks the new event-origin field.

### Likely affected files

- `public/assets/usage.js` or the chosen canonical telemetry asset
- `public/assets/telemetry.js` only according to the Stage 0 canonicalization decision
- `public/assets/gallery-modules/lightbox.js`
- `app/services/telemetry.php`
- `app/services/telemetry_privacy.php`
- `app/models/telemetry.php`
- admin telemetry controller/view
- Node/browser telemetry tests

### Acceptance criteria

- One visible activation produces exactly one `public.photo.opened` event.
- Preload does not count as photo open.
- A true transition A -> B emits one close/time flush for A and one open for B.
- Reopening A after actual closure is counted again.
- The same photo is not double-counted merely because both native and fallback handlers observe one user action.

---

## STAGE 4 - Separate bot-classified and non-bot-classified analytics

**Priority:** P1  
**Type:** Reporting quality

### Problem

Approximately 85 percent of the observed sessions were classified as bots. Mixing this traffic into UX/device/browser summaries makes product analytics misleading.

The current bot classifier is heuristic, based on coarse user-agent classification. It must not be described as perfect human detection.

### Required implementation

1. Preserve all traffic. Do not discard bot-classified events at ingestion.
2. Add a report filter or parallel summaries for:
   - **All traffic**
   - **Non-bot-classified traffic** (`device_type != 'bot'`)
   - **Bot-classified traffic** (`device_type = 'bot'`)
3. Apply the selected segment consistently to:
   - sessions;
   - page views;
   - photo engagement;
   - traffic/referrer breakdowns;
   - browser/OS/device/viewport breakdowns;
   - top galleries/routes;
   - performance where the dimensions support it.
4. Prefer labels such as "non-bot-classified" rather than "human" unless there is a stronger classifier.
5. Do not create a new persistent `traffic_class` column unless a measured query/performance or semantic requirement justifies it. The existing `device_type` dimension can initially support the split.

### MVC requirement

Filtering belongs in model query parameters selected by the service/controller. Do not splice arbitrary SQL fragments passed down from controllers.

Use a constrained enum such as `all`, `non_bot`, `bot`, mapped to explicit model query behavior.

### Acceptance criteria

- Every affected dashboard section clearly states the traffic segment.
- Switching segments changes all dependent values consistently.
- Exported telemetry reports carry the selected segment in their report metadata.
- No raw UA or visitor identifier is exposed.

---

## STAGE 5 - Complete observability paths without fabricating coverage

**Priority:** P1  
**Type:** Confirmed observability gaps

Implement this stage in sub-stages. Do not enable every signal at once without tests.

### STAGE 5A - Page-load performance aggregation

#### Required work

1. Add an explicit metric mapping:

```text
client.performance.page_load -> client.page_load_ms
```

2. Include `client.page_load_ms` in `telemetry_model_report_performance_metrics()`.
3. Add a human-readable label and unit in the admin report.
4. Respect the existing performance sample rate.
5. Report sample count together with average/min/max so an average is never shown without context.

#### Acceptance criteria

A deterministic raw `client.performance.page_load` event appears in Browser performance aggregate output.

### STAGE 5B - Image decode/display performance

The browser helper for image decode exists but no production caller was found in the inspected baseline.

#### Required work

1. Identify the actual visible-image load/decode state transition in `lightbox.js`.
2. Instrument the visible image, not every adjacent background preload unless a separately named preload metric is intentionally added.
3. Define exact timing boundaries in comments/tests.
4. If `client.performance.image_display` remains an allowed event, either wire it to a well-defined visible-display transition or remove/deprecate the unused event from the report contract. Do not keep a permanently empty advertised metric without explanation.

### STAGE 5C - Media byte coverage

#### Full images

Audit all successful full-image response paths in `app/controllers/public_media.php` and verify `media.image.served` is emitted only for bytes actually sent through PHP.

#### Thumbnails

1. Add `media.thumbnail.served` to the strict server allowlist if PHP thumbnail responses are to be measured.
2. Emit it from the authoritative PHP thumbnail-serving path with actual response bytes and normalized variant.
3. Do not instrument generation as if it were serving. Generation bytes and network bytes are separate concepts.
4. If normal thumbnail URLs are static files served by Apache, do not proxy them through PHP merely for telemetry.
5. Change report copy to "PHP-observed media bytes" or equivalent when coverage is partial.

#### Downloads

Search every successful download path for `media.download.served` coverage:

- progressive per-file downloads;
- any source-image download endpoint;
- legacy server ZIP output where meaningful;
- generated manifest delivery must not be counted as image bytes.

Do not double-count the same payload at both an inner and outer response layer.

### STAGE 5D - Cache telemetry

Before emitting cache events, define exactly which cache is measured.

Recommended initial scope:

- application-level lightbox decoded/preloaded-image reuse;
- server derivative existence/hit where the PHP request actually resolves it.

Avoid claiming browser HTTP cache efficiency based solely on application state.

If reliable HTTP cache information is derived from Resource Timing, keep it as a separate metric with documented browser limitations.

Admin UI behavior:

- no samples -> `Not instrumented` or `No samples`;
- samples present -> show hit/miss counts and efficiency;
- disabled setting -> `Disabled`.

Never render an unobserved subsystem as `0.0% efficiency`.

### STAGE 5E - Database query telemetry

This is the most sensitive observability sub-stage.

#### Design requirements

1. Reuse/extend the central PDO instrumentation architecture in `app/database.php`; do not patch dozens of models individually.
2. Record executed statements, not both prepare and execute as separate logical queries.
3. Measure elapsed execution time.
4. Derive only privacy-safe fields already supported by the observer contract:
   - operation;
   - normalized table/domain bucket;
   - route/operation owner if available;
   - safe fingerprint;
   - success/failure;
   - duration;
   - affected-row count where meaningful.
5. Never persist raw SQL, bound parameter values, request bodies, search text, filenames, names, tokens, or secrets.
6. Avoid relying on `PDOStatement::rowCount()` as a portable "rows returned" value for SELECT queries.

#### Reentrancy protection

Telemetry writes themselves use the database. The observer must therefore have a request-local suppression guard.

Preferred architecture:

1. PDO/PDOStatement wrapper observes normal application query execution.
2. Observations accumulate in an in-memory request buffer keyed by safe aggregate dimensions/fingerprint.
3. At shutdown or a controlled flush point, persist the aggregate batch.
4. Set a suppression flag while writing telemetry aggregates.
5. Queries against telemetry tables and observer maintenance must not recursively observe themselves.
6. Preserve the existing Admin test-run query profiler and dispatch to both observers without double-counting.

#### Failure behavior

Telemetry must be fail-open relative to the gallery:

- observer failure must not break public page rendering;
- telemetry DB write failure must not turn a successful application query into an HTTP failure;
- diagnostics should record the observer failure through an existing safe operational channel if possible, without recursive telemetry.

### Acceptance criteria for Stage 5

- Page-load performance shows real aggregate samples.
- Image performance has a documented producer or is explicitly shown as unavailable.
- Media report distinguishes measured coverage from unmeasured static traffic.
- Cache report distinguishes no data from zero misses/hits.
- DB telemetry produces aggregate rows under its enabled setting without recursion and without exposing SQL/parameters.
- Disabling each telemetry sub-feature returns overhead close to the previous path and produces an explicit Disabled state.

---

## STAGE 6 - Reduce telemetry database cardinality and storage cost

**Priority:** P2  
**Type:** Performance/storage optimization after correctness

### Rule

Do **not** optimize table layout before Stages 2-5 establish correct semantics. Measure the corrected workload first.

### Problem

The audit showed telemetry consuming over half of total database storage. In particular, `telemetry_hourly_metrics` has a wide high-cardinality key across many dimensions, which increases both row count and index size.

### Required measurement before modification

Capture from a representative database:

- exact row counts;
- data size and index size per telemetry table;
- rows/day growth;
- distinct cardinality of each dimension by metric name;
- top report query timings;
- `EXPLAIN` output for expensive report queries where available;
- current retention settings.

Store only the measurement summary in diagnostics/documentation. Do not commit production data dumps.

### First optimization: metric-specific dimension normalization

Before creating new tables, reduce meaningless dimensionality during aggregate writes.

Define an explicit allowed-dimension set per metric family. Example direction:

- session/page view: route, page kind, gallery, browser, OS, device, viewport, referrer;
- photo engagement: gallery, image, selected traffic dimensions, referrer where useful;
- page performance: route/page kind and selected client dimensions, no image/media/cache dimensions;
- media bytes: gallery, image, media variant, relevant cache result, route if useful;
- cache metrics: only cache-relevant dimensions;
- DB metrics: keep in `telemetry_db_query_metrics`, not generic hourly dimensions.

Normalize irrelevant dimensions to their neutral values before the aggregate-key write. This can collapse many otherwise distinct rows without schema change.

### Long-window report strategy

Evaluate use of `telemetry_daily_metrics` for long report windows instead of always scanning hourly rows.

Suggested rule after validation:

- short/recent windows needing hourly precision -> hourly table;
- long windows -> daily rollup;
- session-derived values -> session table regardless of hourly/daily event metric.

Ensure daily rollup semantics match the corrected metric contract before switching queries.

The implementation must expose a read-only consistency check over completed calendar
days that compares hourly and daily `sample_count`, `event_count`, and `value_sum` per
metric. Exclude the current partial day. A missing or mismatched daily metric blocks a
future long-window source switch; `no_samples` must remain distinct from a successful
match.

### Retention

Keep retention configurable. Do not shorten history solely to make the database smaller without an explicit product decision.

Admin diagnostics should estimate:

- current telemetry size;
- approximate growth/day;
- oldest row by table;
- configured retention horizon;
- most expensive metric families by row count;
- request-local runtime for the bounded report query families used to build the export;
- sanitized `EXPLAIN` plans for a fixed source-owned subset of representative report queries where the hosting database supports them.

The runtime profiler and optimizer-plan probes must remain operator-only and non-persistent. They must never export SQL text, bound values, result payloads, or raw database error messages. The runtime snapshot must be taken before the EXPLAIN probes so diagnostic-plan overhead does not contaminate the measured report query timings.

### Schema/index changes

Only after the normalization measurement, consider a migration for index/table changes. Every index removal/addition must be justified by actual report queries and measured `EXPLAIN`/timing behavior.

Do not create a parallel analytics schema unless the simpler normalization and daily-rollup strategy is insufficient.

### Acceptance criteria

- Corrected report values are unchanged by storage optimization.
- Aggregate row growth drops measurably on a representative fixture/workload.
- A representative workload test demonstrates material key collapse for metrics with
  irrelevant dimensions while preserving required image-level cardinality.
- Recent completed-day hourly and daily rollups can be compared per metric without
  persisting extra diagnostics or including the current partial day.
- No report query regresses materially.
- Retention cleanup remains bounded and idempotent.

---

## STAGE 7 - Make legacy ZIP fallback health explicit and fail gracefully

**Priority:** P2  
**Type:** Operational robustness

### Problem

The configured ZIP cache existed in the audit but was reported unreadable/unwritable. The modern progressive download flow can still operate, but the supported legacy server-ZIP fallback can fail.

### Required implementation

Create or extend a download-domain service status function, for example conceptually `download_legacy_cache_status()`, owned by `app/services/downloads.php` or a split download-service part if that module is already large.

The status should return structured data, not presentation text:

- configured path;
- path configured yes/no;
- exists;
- directory yes/no;
- readable;
- writable;
- state/work subdirectory health where relevant;
- free-space availability known/unknown;
- free bytes when known;
- legacy server-build capability boolean;
- machine-readable reason code.

### Behavior

1. Modern progressive/client-side downloads remain available when only legacy cache is unhealthy.
2. Before starting a legacy server ZIP build, perform the capability check.
3. If unavailable, return a clear controlled error instead of attempting a build expected to fail.
4. Admin diagnostics/report should show `Legacy server ZIP fallback unavailable` plus reason.
5. Do not leak filesystem paths to anonymous users. Full path may be shown only in the authenticated Admin diagnostic where current conventions permit it.
6. Avoid noisy repeated `mkdir()` warnings from every failed request.
7. Preserve current concurrency, quota, source-size, ZIP64, and capability security constraints.

### Tests

Cover:

- healthy writable temporary cache;
- missing cache directory that can be created;
- configured path is a file rather than directory;
- non-writable cache where permissions can be simulated;
- free-space API unavailable;
- modern download capability remains true when only legacy fallback is false;
- legacy request fails with controlled response and no partial artifact leak.

### Likely affected files

- `app/services/downloads.php` and/or its split-module parts
- `app/controllers/downloads.php`
- admin diagnostics/report service/view if status is surfaced there
- language files
- existing download tests or a new focused cache-health test

### Acceptance criteria

- A broken legacy cache no longer looks like a global download outage.
- Legacy build failure is detected before expensive work begins.
- Operational report states exact capability and reason.

---

## STAGE 8 - Surface legacy JPEG thumbnail inventory using the existing cleanup engine

**Priority:** P2  
**Type:** Operator UX / storage maintenance

### Problem

The snapshot contained 15,076 generated JPEG thumbnail variants, approximately 839 MiB, while current mode is modern. The cleanup engine already exists.

### Required implementation

1. Reuse thumbnail-compatibility model/service functions to calculate:
   - legacy JPEG variant count;
   - total bytes where metadata/filesystem information is available;
   - affected image count;
   - count of variants already missing on disk vs registered metadata if relevant.
2. Show the inventory in the Admin thumbnail maintenance area before cleanup.
3. When compatibility mode is modern and legacy variants exist, show a non-destructive recommendation that the existing cleanup action can reclaim approximately the measured space.
4. Require the same explicit administrator action currently used for cleanup.
5. Preserve batching, CSRF, authorization, cancellation, and stale-response protections.
6. Do not auto-delete JPEG variants during deploy, migration, report generation, normal maintenance, or a mode switch unless that behavior is already explicitly documented and tested.
7. After cleanup, refresh inventory from authoritative metadata/filesystem state rather than decrementing UI counters optimistically without verification.

### Reporting improvement

The complete overview report should distinguish:

- current modern WebP variants;
- legacy generated JPEG variants;
- whether legacy cleanup is available;
- estimated reclaimable bytes.

Do not call the JPEG files corrupt or orphaned merely because they are legacy-compatible artifacts.

### Likely affected files

- `app/services/thumbnail_compatibility.php`
- owning thumbnail model if inventory SQL belongs there
- `app/controllers/admin_thumbnails.php`
- Admin thumbnail view/assets
- `app/lang/en.json`, and corresponding CS/DE/SV translations according to project localization conventions
- `app/services/admin_gallery_report/*` if overview report is enhanced
- thumbnail compatibility tests

### Acceptance criteria

- Existing deletion code is reused.
- Inventory matches the authoritative variant registry/filesystem fixture.
- No deletion occurs merely by opening the page/report.

---

## STAGE 9 - Correct report semantics and unavailable-state rendering

**Priority:** P2  
**Type:** Reporting correctness

### STAGE 9A - Gallery emptiness terminology

Current SQL in `app/models/admin_gallery_report.php` counts galleries with no directly owned image:

```sql
SELECT COUNT(*)
FROM galleries g
WHERE NOT EXISTS (
    SELECT 1 FROM images i WHERE i.gallery_id = g.id
)
```

This is not the same as a truly empty leaf gallery.

Implement separate metrics:

1. `zero_direct_image_count`: no directly owned images.
2. `empty_leaf_count`: no directly owned images and no child gallery.

Optional third metric if useful:

3. `structural_container_count`: zero direct images but has one or more child galleries.

Rename the UI from ambiguous `Empty galleries` to precise terms. Keep compatibility aliases in internal data only if needed by existing tests/export readers.

### STAGE 9B - Filesystem capacity unknown vs zero

Refactor disk-capacity helpers so an unavailable host API/result is represented as `null`/`available=false`, not numeric zero.

UI/export behavior:

- known zero -> `0 B`;
- unknown/unavailable -> `Unavailable`;
- known positive -> formatted bytes.

Do the same for any percentage derived from unavailable capacity.

### STAGE 9C - Telemetry zero vs no samples vs disabled

For media, cache, DB, and browser-performance panels, introduce explicit state:

- `disabled`
- `not_instrumented` where the code path is not wired or unsupported
- `no_samples` where enabled/instrumented but none occurred in the selected window
- `available`

Only show numeric zero when zero is a measured value under an active, instrumented subsystem.

### STAGE 9D - Panorama terminology

The current image-summary service categorizes sufficiently wide images as `panorama` using aspect ratio alone.

Unless true panorama metadata/detection exists, rename user-visible wording to something like:

- `Wide (>= 2:1)`
- `Panorama / wide aspect`

Keep the internal key if changing it would cause unnecessary compatibility churn.

### STAGE 9E - Image visibility wording

Where the overview says all images are public, clarify that this is **image-row visibility**, not proof that every source file is anonymously reachable independent of gallery access policy.

Recommended conceptual wording:

```text
Image rows marked public: 32,192
Effective access additionally depends on gallery, password/share-token, and NSFW policy.
```

### Likely affected files

- `app/models/admin_gallery_report.php`
- `app/services/admin_gallery_report/system_summary.php`
- `app/services/admin_gallery_report/image_summary.php`
- `app/views/admin_gallery_report_export.php`
- report controller/view-model preparation owner
- localization files
- `tests/admin_gallery_report_batching_test.php` plus focused report semantic tests

### Acceptance criteria

- A structural parent gallery is no longer reported as genuinely empty.
- Unknown filesystem capacity is never displayed as zero capacity.
- Missing telemetry instrumentation is never displayed as perfect zero activity/zero-error efficiency.
- Wide screenshot classification does not claim photographic panorama certainty.

---

## STAGE 10 - Improve maintenance failure diagnostics without broadening failure impact

**Priority:** P3  
**Type:** Operations

### Problem

The audit contained at least one `site_maintenance.failed` operational event. A single historical failure is not enough evidence to redesign maintenance, but operators should be able to identify the failing sub-task quickly.

### Required work

1. Inspect the current maintenance coordinator and existing Admin diagnostics before adding new infrastructure.
2. Ensure each maintenance sub-operation has:
   - stable operation name;
   - start/end or result state where current logging conventions support it;
   - concise safe failure class/code;
   - bounded diagnostic text;
   - duration when useful.
3. Preserve fail-safe isolation so failure in one optional cleanup task does not corrupt unrelated maintenance work.
4. Surface the most recent maintenance failure and most recent successful run in authenticated Admin diagnostics.
5. Do not expose filesystem secrets, stack traces, SQL, tokens, or request data in public telemetry exports.
6. Do not change maintenance scheduling/retention values solely because one historical failure occurred.

### Acceptance criteria

An administrator can identify which maintenance component failed and when, without reading raw hosting logs, while public exports remain privacy-safe.

---

## STAGE 11 - Final integration, documentation, and handoff

**Priority:** Required before implementation completion

### Cross-checks

Before considering this program complete, verify all of the following:

1. **Authorization**
   - media/download/search surfaces use the intended access policy;
   - unpublished direct-access semantics were not accidentally converted to private semantics;
   - ancestor password/NSFW behavior is preserved.
2. **Telemetry correctness**
   - session and page-view definitions are consistent;
   - daily and executive session totals agree for identical windows;
   - legacy semantic boundary is visible.
3. **Photo lifecycle**
   - one activation produces one open event;
   - preloads do not masquerade as opens;
   - visible time closes once.
4. **Traffic segmentation**
   - bot/non-bot selection applies consistently.
5. **Observability**
   - page-load metrics aggregate;
   - image metrics have a real producer or explicit unavailable state;
   - media bytes state coverage honestly;
   - cache metrics identify the cache layer;
   - DB observer cannot recurse.
6. **Storage**
   - telemetry normalization does not alter metric meaning;
   - table growth and report performance are measured before/after.
7. **Downloads**
   - legacy cache health is explicit;
   - modern download path is not disabled by a legacy-cache problem.
8. **Thumbnails**
   - existing JPEG cleanup implementation is reused;
   - inventory is non-destructive.
9. **Reports**
   - zero vs unknown vs unavailable states are distinct;
   - structural gallery containers are not called empty leaf galleries.
10. **Architecture**
    - no new MVC violations;
    - no controller/view SQL;
    - no duplicate policy helpers.

### Verification

During edits, use quick audit as needed. Before final handoff:

```bash
php scripts/audit.php --profile=full
```

If full audit fails:

- inspect the compact failure summary;
- inspect only the relevant suite log;
- fix the concrete failure;
- rerun the appropriate central audit;
- do not bypass the failure by adding baseline exceptions or removing coverage.

### Handoff format for later implementation sessions

For every implementation checkpoint requested by the user:

1. Return an **affected-files ZIP**, even when the current stage is only partially completed.
2. Include only files actually changed for that checkpoint.
3. State which stage/sub-stage is complete, partial, or blocked.
4. State the central audit result.
5. Do not claim later stages are complete merely because their prerequisite helpers were added.

---

# 6. Suggested dependency order

Agents should normally execute the program in this order:

```text
Stage 0
  |
  +--> Stage 1 authorization regression lock
  |
  +--> Stage 2 telemetry semantic correction
           |
           +--> Stage 3 photo lifecycle correctness
           |
           +--> Stage 4 traffic segmentation
           |
           +--> Stage 5 observability completion
                    |
                    +--> Stage 6 telemetry storage optimization
  |
  +--> Stage 7 legacy ZIP cache health
  |
  +--> Stage 8 legacy thumbnail inventory
  |
  +--> Stage 9 report semantics
  |
  +--> Stage 10 maintenance diagnostics
  |
  +--> Stage 11 integration and handoff
```

Stage 6 must remain after telemetry correctness because optimizing incorrect aggregates only makes the defect harder to reason about.

Stages 7-10 may be developed in parallel with telemetry work if separate agents are used, but each must still honor shared report/service boundaries and central audit verification.

---

# 7. Detailed test requirements

The exact test filenames may change according to existing ownership, but the behavioral coverage below is mandatory.

## 7.1 Telemetry semantic fixture

Create a deterministic fixture with events such as:

```text
Session A:
  session.started
  gallery.viewed
  photo.opened image 1
  photo.visible_time image 1 = 5 s

Session B:
  session.started
  gallery.viewed
  page.viewed

Session C:
  gallery.viewed       # intentionally no session.started transport
```

Expected canonical result:

```text
sessions = 3
page_views = 4
bounce sessions = 2   # A and C, if <=1 page view remains the chosen definition
photo_views = 1
photo_seconds = 5
```

The fixture must prove that missing `session.started` does not make an otherwise observed session disappear from canonical session reporting.

## 7.2 Historical-boundary fixture

Given metrics before and after a semantics effective timestamp:

- report visibly marks the boundary;
- corrected totals do not silently combine incompatible fields as though they were identical;
- no destructive migration is required to open the report.

## 7.3 Photo state-machine fixture

Assert ordered emitted events for:

```text
open A
open A duplicate callback
navigate B
close B
reopen A
```

Expected opens:

```text
A, B, A
```

Expected closes/time flushes:

```text
A exactly once before B
B exactly once on close
A later closes according to its new activation
```

## 7.4 Authorization fixture

At minimum prove denied media bytes are never emitted before authorization succeeds, and that direct unpublished behavior remains intentionally reachable where no password/private/NSFW restriction applies.

## 7.5 DB observer fixture

Prove:

- one ordinary application SELECT/UPDATE produces the expected aggregate observation;
- observer persistence queries do not recursively create more DB telemetry rows;
- disabled telemetry produces no observer write;
- query failure is counted safely without changing the exception semantics seen by the original caller;
- no raw SQL or parameter value is stored.

## 7.6 Report-state fixture

Prove all states separately:

```text
metric disabled
metric enabled but no samples
metric available with measured zero
metric available with positive value
host capacity unavailable
host capacity measured as zero
```

The rendered/exported labels must not collapse these states.

---

# 8. Migration and compatibility strategy

## 8.1 Prefer no schema change for early stages

Stages 1-5 can largely be implemented using existing tables and settings. Do not create a migration merely because the task is large.

Potential schema changes should be introduced only when required for:

- a semantics-version field that cannot safely live in existing telemetry settings;
- a proven index/cardinality optimization in Stage 6;
- a new bounded dimension that cannot be represented safely in existing context/aggregate structures.

## 8.2 Historical telemetry

Never rewrite 30/90/730-day historical tables under new meaning unless reconstruction is provably deterministic from retained source events.

Raw event retention is shorter than aggregate retention, so complete historical reconstruction is generally impossible.

Preferred approach:

- retain legacy rows;
- record semantics effective timestamp/version;
- make the UI honest about the boundary;
- optionally rebuild only the still-retained raw-event window.

## 8.3 Public route/API compatibility

Do not rename or remove existing public routes or API response fields just to make telemetry/report code cleaner.

If an internal field name such as `panorama_count` is kept for compatibility while the display label changes to `Wide (>= 2:1)`, that is acceptable and preferable to unnecessary churn.

## 8.4 Localization

Any new operator-facing text must be added consistently across the project's supported languages according to existing translation conventions:

- English
- Czech
- German
- Swedish

Do not leave new Admin labels as hardcoded English strings where the surrounding UI uses translation keys.

---

# 9. Performance and privacy requirements

## 9.1 Telemetry overhead budget

Telemetry must remain subordinate to gallery operation.

- Do not introduce one extra DB write per instrumented SQL query.
- Batch or aggregate where practical.
- Preserve sampling controls.
- Public requests must succeed even if telemetry persistence fails.
- Avoid synchronous filesystem scans on normal public requests for reporting-only data.

## 9.2 Privacy boundary

Maintain the existing anonymous telemetry posture.

Never store/export:

- raw IP address;
- raw user-agent string;
- raw referrer URL;
- names/email/account identity;
- authentication/session cookies;
- source image authorization tokens;
- request body;
- arbitrary search/query content;
- raw SQL;
- SQL parameter values;
- exact user location.

New diagnostic fields must be normalized, bounded enums or safe numeric/object IDs already allowed by the current telemetry model.

## 9.3 Cardinality discipline

Before adding any new aggregate dimension, estimate its maximum cardinality.

Avoid free-form dimensions such as:

- URL/path strings;
- filenames;
- exception messages;
- arbitrary JavaScript error text;
- SQL fragments;
- DOM selectors.

Use normalized buckets and store detailed bounded diagnostics only in the appropriate short-retention raw-event/operational-log path when allowed.

---

# 10. Explicit non-goals

This remediation program does **not** authorize the following unrelated work:

1. Redesigning the whole gallery UI.
2. Changing the intended unpublished-gallery semantics.
3. Replacing the current file-based media source of truth.
4. Replacing the database engine.
5. Introducing a third-party analytics platform.
6. Routing all static media through PHP merely to collect bandwidth telemetry.
7. Replacing the modern browser-side ZIP download architecture with server ZIPs.
8. Reimplementing legacy JPEG cleanup that already exists.
9. Changing WinApp upload/API behavior without a reproduced issue.
10. Changing map/SimBrief/flight-gallery functionality unrelated to a confirmed access or telemetry defect.
11. Releasing/version-bumping the application.
12. Deleting historical telemetry automatically.

---

# 11. Recommended implementation checkpoints

To keep changes reviewable and compatible with the user's Git workflow, later coding agents should prefer these affected-ZIP checkpoints:

### Checkpoint A

- Stage 0 tests/contracts
- Stage 1 authorization regression lock

### Checkpoint B

- Stage 2 telemetry session/page-view correctness
- semantics boundary/report consistency diagnostics

### Checkpoint C

- Stage 3 photo lifecycle
- Stage 4 traffic segmentation

### Checkpoint D

- Stage 5A through 5D performance/media/cache observability

### Checkpoint E

- Stage 5E DB observer with recursion protection

### Checkpoint F

- Stage 6 storage/cardinality optimization, only after measurements

### Checkpoint G

- Stage 7 legacy ZIP health
- Stage 8 thumbnail inventory
- Stage 9 report semantic cleanup

### Checkpoint H

- Stage 10 maintenance diagnostics
- Stage 11 final integration/full audit

A checkpoint may be split further if an agent reaches a safe intermediate state. Even an incomplete checkpoint should be returned as an affected-files ZIP when requested, rather than keeping unreturned working changes in a long conversation.

---

# 12. Definition of done

This TEMP program is fully implemented only when all of the following are true:

- Authorization behavior is protected across every public media/download surface by regression tests.
- `public.session.started` no longer counts as a page view.
- Canonical session totals come from `telemetry_sessions` and daily totals use the same definition.
- Bounce/page-view arithmetic is internally consistent for corrected data.
- Historical telemetry semantics are visibly versioned/bounded rather than silently rewritten.
- Photo-open telemetry has a deterministic state machine and the dual-handler/preload scenario is tested.
- Reports can separate all, bot-classified, and non-bot-classified traffic.
- `client.performance.page_load` reaches a visible aggregate metric.
- Image performance either has a real measured producer or an explicit unavailable state.
- PHP-observed full-image, thumbnail, and download byte coverage is truthful and not confused with total web-server bandwidth.
- Cache reporting identifies the measured cache layer and distinguishes no samples from measured zero.
- DB query telemetry is integrated centrally, privacy-safe, batched, fail-open, and recursion-proof.
- Telemetry storage optimization is based on measured cardinality and does not alter metric semantics.
- Legacy ZIP cache health is explicit and cannot unnecessarily disable modern downloads.
- Legacy JPEG inventory points to the existing safe cleanup implementation rather than duplicating deletion code.
- Gallery report wording distinguishes structural containers from truly empty leaf galleries.
- Filesystem capacity unknown is never rendered as `0 B`.
- Admin/export reports distinguish disabled, uninstrumented, no-sample, and measured-zero states.
- The full central audit passes before the final affected-files handoff.

---

# 13. Final agent warning

The main risk in this program is not implementation difficulty. It is **changing a valid domain semantic because an aggregate report looked suspicious**.

Always follow this sequence:

```text
Observe -> reproduce -> identify the canonical domain owner -> add a failing/locking test -> change the smallest responsible layer -> run central audit -> measure result.
```

Do not infer authorization from `images.visibility` alone.  
Do not infer session truth from `public.session.started` alone.  
Do not infer cache efficiency from absent events.  
Do not infer bandwidth from only PHP-served files.  
Do not infer a true panorama from aspect ratio alone.  
Do not infer a broken global download system from an unhealthy legacy ZIP cache.  
Do not implement functionality that already exists under another domain owner.

This TEMP document should be deleted or archived after the remediation program is fully implemented and its durable architectural/test decisions have been transferred into the appropriate permanent documentation and regression suite.
