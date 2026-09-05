# Testing Guide

This guide applies to PHP Gallery Version 0.96.1. Release verification must include the central audit runner's `quick`/`full`/`release` profiles and the deployment scripts' interactive tests-folder prompt, plus the retained Version 0.96 coverage: recursive, resumable gallery migration with bounded ZIP packages and imported child-tree reconstruction; canonical map-marker photo-page fallbacks and in-viewer map navigation across physical-gallery pagination, fullscreen split-map persistence, the canonical Admin side-panel mutation envelope and completion coordinator, multi-context postcondition verification, stale/out-of-order suppression, browser upload pipeline safeguards, opened-gallery branch image counters and their Theme/per-gallery visibility policy, progressive thumbnail dimension detection and responsive compatibility, the Version 0.93 request-budget/TTFB behavior, request-local database caching, resumable updater safety, updater server-policy reconciliation, Admin test-run diagnostics, public media concurrency and cache invalidation, clean-home URL handling, upload auto-renaming and inventory behavior, the redesigned Windows uploader, the Windows HTTP monitor schedules/protocol snapshots/report ZIPs, deployment exclusion rules, lightbox detached-image cleanup, decoded-cache ownership, preload-generation invalidation, teardown/reopen cycles, public lightbox zoom and progressive quality promotion, Shift+Left/Right ten-photo navigation, public Smart Gallery visibility, presentation settings, cycle-safe placement/order evaluation, viewer account privacy/access, collection sharing, bounded gallery benchmark diagnostics, access intersection and pagination; multilingual gallery/photo content and fallbacks; browser-local ZIP imports; progressive gallery and Smart Gallery ZIP downloads; browser download symbol rendering; ordered migration upgrades; complete deployment packaging; updater safety; the configurable public language selector; hourly automatic-update throttling; and the supported English, Czech, German, and Swedish catalogs.

## Purpose
This project is a plain PHP gallery CMS without a formal browser automation stack. Automated verification is centralized through `scripts/audit.php`; focused commands documented later are diagnostic and manual-acceptance tools, not a second test plan that agents should execute in addition to the audit.


## Central Audit Runner

### Agent execution rule

Automated agents must use the central audit runner as the default and authoritative verification interface. **This rule overrides later wording such as "run these tests", "run", or lists of focused PHP/Node commands elsewhere in this guide.** Those lists document coverage ownership and give humans/agents precise reproduction commands after a failure; they are not instructions to replay the regression tree before or after a successful central audit.

For normal agent work:

```text
edit/debug cycle          php scripts/audit.php --profile=quick
final code handoff/ZIP    php scripts/audit.php --profile=full
actual release            php scripts/audit.php --profile=release
```

Do not enumerate `tests/`, do not create shell/PowerShell loops over test files, do not run every focused command listed in a feature section, and do not separately run whole-tree `php -l`/`node --check` passes when the selected audit profile already performs them. Run a focused command only to reproduce a specific audit failure, develop/register a new test, perform genuinely manual/browser acceptance outside the automated runner, or satisfy an explicit user request. Once diagnosis is complete, rerun the appropriate central profile once rather than manually replaying sibling tests.

The central runner intentionally suppresses passing child stdout to protect agent context. Read the console summary first; read `cache/test-audit/latest.md` only when needed; open raw suite logs only for `FAIL`, `BLOCKED`, or a materially relevant `SKIP`. A successful suite does not justify reading its raw log.


```bash
php scripts/audit.php --profile=quick
php scripts/audit.php --profile=full
php scripts/audit.php --profile=release
```

`quick` keeps the complete PHP regression suite but omits intentionally slow/browser-only work and prefers Git-changed PHP/JavaScript syntax targets. If Git metadata is unavailable, changed-file linting safely falls back to the full source tree. `full` runs all deterministic source checks, including the slow ZIP64 boundary fixture and full PHP/JavaScript syntax validation. `release` adds Chromium map integration when the host can safely provide a browser, `app/core-manifest.json` freshness, and `git diff --check` when checkout metadata is available.

The runner captures subprocess stdout/stderr instead of streaming passing-test noise. Its console output is deliberately compact, but it prints and flushes a `RUN` line before every suite and that suite's normalized result immediately after completion, so long Windows process-spawn phases never look like a hung command. Normal persisted output is:

```text
cache/test-audit/latest.md
cache/test-audit/latest.json
cache/test-audit/<run-id>/report.md
cache/test-audit/<run-id>/report.json
cache/test-audit/<run-id>/*.log
```

Read `latest.md` first. Open a suite log only when that suite is `FAIL` or `BLOCKED`, or when diagnosing a `SKIP`. Status has strict meaning: `PASS` executed and passed; `FAIL` executed and detected a regression; `SKIP` is intentionally unavailable optional coverage such as a missing real browser or self-skipped database integration; `BLOCKED` means required coverage could not execute because its source/dependency is unavailable. The overall command exits `0` on PASS, `1` on FAIL, and `2` on BLOCKED.

Node execution is registry-driven through `scripts/audit_registry.php`, not a blind `*_test.mjs` glob. This is required because ZIP writer fixtures need temporary output arguments, the ZIP64 test is deliberately slow, and the real lightbox browser test needs a Chromium-family executable. Per-test PHP environment requirements also belong in that registry. `tests/audit_runner_test.php` prevents unregistered Node tests and profile drift.

Python discovery is execution-based rather than filesystem-only. The runner probes `python3 --version`, `python --version`, and `py -3 --version` in order and accepts only a successful Python 3 response. This is intentional on Windows: App Execution Aliases and launchers can be runnable from `PATH` even when PHP does not expose the alias as an ordinary file to `is_file()`. `PHP_GALLERY_PYTHON` remains available as an explicit executable-name/path override and is validated by the same Python 3 probe before WinApp tests start.

WinApp tests that exercise optional host integrations must remain isolated from the developer workstation. In particular, SimConnect tests must not assume that a deliberately invalid manual override means no usable SimConnect DLL exists: the runtime intentionally falls back to automatic DLL discovery. The regression fixture therefore stubs DLL resolution when testing the nonfatal missing-DLL branch. The audit also parses Python `unittest` trailers so the compact console/report summary distinguishes passed tests, assertion failures, errors, and skips without dumping the full Python log.

`php tests/run.php` is retained only for compatibility and delegates to `scripts/audit.php --suite=php-regression --no-report`. It is not an agent entrypoint. Focused commands elsewhere in this document are reproduction/diagnostic references or manual acceptance steps only; the global agent execution rule above takes precedence over them. Do not pre-run focused tests "just in case", and do not replay them after a successful central audit. Duplicate runs are justified only while investigating a concrete failure or validating a new test before registry integration.

## Multilingual Content

Run `php tests/content_localization_model_test.php`, `php tests/admin_content_localization_test.php`, `php tests/public_content_localization_test.php`, `php tests/openai_text_assist_model_test.php`, `php tests/public_language_preference_test.php`, `php tests/translation_catalog_consistency_test.php`, and `php tests/migration_consistency_test.php`.

Coverage includes unclassified existing content, all maintained languages, invalid-language rejection, independent gallery-field fallback, non-mixed photo caption variants, blank-row deletion, batch/cache behavior, side-panel FormData ownership, access-before-localization ordering, server-rendered cards/lightbox/SEO, translated search terms, sidecar transfer, and review-only provider drafts. Translation behavior must not alter slugs, paths, ordering, filenames, visibility, access, NSFW, or media authorization. Finish with syntax checks for changed PHP files and `php scripts/audit.php --profile=full`.

## Test Layers

### 1. Syntax Checks
Syntax checks are owned by the central audit during normal agent verification. For targeted diagnosis of a parser failure, a single file can still be checked directly:

```bash
php -l path/to/file.php
```

The full profile performs repository PHP and JavaScript syntax validation automatically. Do not duplicate that work with a second manual tree walk after the audit.

### Version 0.96 recursive gallery migration

Run these checks after changing gallery migration manifests, API routes, package planning, target-tree creation, resume state, the Admin migration form, translations, or browser orchestration:

```text
php tests/gallery_migration_model_test.php
php tests/mutation_schema_policy_test.php
php tests/stage3_auxiliary_mutation_contract_test.php
php tests/translation_catalog_consistency_test.php
node --check public/assets/gallery-modules/admin-gallery-migration.js
php scripts/audit.php --profile=full
```

The focused model test exercises production helpers for legacy and recursive manifest normalization, parent-first tree validation, cross-gallery asset identity, atomic original/thumbnail grouping, deterministic receiver-sized package plans, soft and hard byte limits, package JSON validation, and malformed-tree rejection. The full suite retains route, feature-flag, SEO guard, schema policy, localization, function-documentation, cache-revision, and manifest integration coverage.

For manual verification, use two same-version disposable installations and gallery-scoped API keys. Exercise both push and pull with **Include subgalleries** enabled and disabled. Confirm the imported root is created as a child of the selected receiving gallery, descendants retain their hierarchy and supported metadata, and the selected parent is neither overwritten nor moved. Include originals, multiple existing thumbnail formats, gallery branding, translations, and flight-map data. Interrupt a package request, reload, and verify target status skips installed asset keys before retrying. Confirm completion is refused with a missing asset and succeeds only after every package is present. Repeat with mismatched versions, wrong-gallery keys, malformed package membership, checksum mismatch, path-like ZIP names, an unavailable schema inspection, and a package above the receiving upload limit; each refusal must occur without unauthorized target mutation. Revoke temporary API keys after testing.

The Stage 1 through Stage 6 download sections below preserve the acceptance criteria that applied immediately after each incremental stage. Later stages intentionally supersede some earlier compatibility expectations, for example Stage 4 removes Stage 2 tokenless manifest/source access and legacy capability issuance from the progressive start response. For the current post-Stage-7 release, execute the Stage 7 checklist plus the retained invariants referenced there; use earlier sections when bisecting or validating a rollback to that specific stage.

### Public download hardening: Stage 1

Stage 1 keeps the existing download contract unchanged while adding operational diagnostics and crawler hygiene. Before moving to the capability stages, verify all of the following against the deployed build:

1. A normal physical-gallery Download click still opens the progressive browser dialog, fetches `download_gallery_manifest`, streams source files, and produces the ZIP locally.
2. Direct navigation to `index.php?page=download_gallery&id=<public-gallery-id>` still reaches the bounded no-JavaScript/server-ZIP fallback. Repeat the equivalent Smart Gallery checks.
3. Public download anchors render both `rel="nofollow"` and the `download` attribute while retaining `data-gallery-download` and `data-gallery-download-manifest-url`.
4. All physical and Smart Gallery legacy/manifest/source download responses include `X-Robots-Tag: noindex, nofollow`. A normal `gallery` or `smart_gallery` page must not receive that header from this policy.
5. `robots.txt` explicitly disallows all six download route names in query-string routing.
6. Force one controlled legacy preparation failure in a local/test gallery and inspect the Admin log. The event must contain the resource ID, exception class, sanitized exception message, request method, route, request ID when available, `download_mode=legacy`, stable failure stage/reason, bounded User-Agent, Referer without query/fragment data, and a keyed client-IP fingerprint rather than a raw address. SQL, stack traces, filesystem paths, cookies, share/access tokens, and arbitrary headers must not appear.

Finish with `php -l` for every changed PHP file and `php scripts/generate_manifest.php --check` after regenerating `app/core-manifest.json`. The deployment ZIP normally omits `tests/`, so absence of the source test tree in a production deployment package is expected and must not be "fixed" by adding ad-hoc runtime test files.

### Public download hardening: Stage 2

Stage 2 adds optional stateless HMAC download capabilities while preserving every tokenless Stage 1 route. Verify both paths because the capability path is intentionally parallel, not mandatory yet:

1. Request `index.php?page=download_gallery_start&id=<public-gallery-id>` and confirm the JSON response contains `ok=true`, a `progressive` capability, a separate legacy capability, expiry metadata, and capability-bearing manifest/legacy URLs. No ZIP artifact should be created by this request.
2. Open the returned physical-gallery manifest URL. Confirm it succeeds, every returned source URL carries the same progressive capability, and the browser ZIP can be assembled normally. Repeat with a public downloadable Smart Gallery using `download_smart_gallery_start`.
3. Change one character in a capability, use it with another gallery ID, use a physical-gallery token on a Smart Gallery route, and use a progressive token on the legacy route. Each request must fail cheaply with HTTP 403 before manifest traversal or ZIP building.
4. Verify expiry by issuing a test token with the service-level test harness or by temporarily shortening `runtime_limits['download.capability_ttl_seconds']` in a local config. Expired capabilities must return 403. Oversized/malformed capabilities must also be rejected before JSON/resource work.
5. Re-run the original tokenless `download_gallery_manifest`, `download_gallery_file`, `download_gallery`, and all Smart Gallery equivalents. They must behave exactly as Stage 1 did. This is the Stage 2 rollback/compatibility invariant.
6. Confirm `download_gallery_start` and `download_smart_gallery_start` are behind the existing Downloads feature flag, receive `X-Robots-Tag: noindex, nofollow`, and are listed in `robots.txt`.
7. Confirm existing `config.php` files need no edits. Capability signing must derive a purpose-specific key from the existing stable application secret unless a dedicated `download_security.capability_secret` override is explicitly configured.
8. Review `app/configuration_defaults.php` as the canonical source for download/browser-upload operational limits. Feature modules must consume these values through `cms_runtime_limit()` rather than redeclaring numeric policy constants.

Finish with PHP syntax checks for every changed PHP file, the focused capability regression script from the source test tree when available, and `php scripts/generate_manifest.php --check`.

### Public download hardening: Stage 3

Stage 3 moves the normal JavaScript downloader to header-transport capabilities while keeping the bounded query transport only for compatibility. Verify:

1. Click Download on a physical gallery and a downloadable Smart Gallery. The browser must POST to the corresponding `*_start` route before any manifest/source request and must not navigate away from the page.
2. In Network, confirm normal manifest and source URLs contain no `capability=` query parameter. Requests instead carry `X-PHP-Gallery-Download-Capability`, responses are `private, no-store`, and `Referrer-Policy: no-referrer` is present.
3. Remove the header, alter the token, use the wrong scope/resource type/ID, or let the token expire. Manifest/source requests must return 403 before bounded enumeration/source resolution.
4. Exercise the documented query-token compatibility path manually and confirm it remains resource/scoped, bounded, and noindex. Supplying different header and query tokens must fail.
5. Confirm the browser still validates source `Content-Length` against manifest `size`, handles source-version 409 by failing/retrying the download rather than silently creating a corrupt ZIP, and preserves ZIP64 behavior for large local archives.

### Public download hardening: Stage 4

Stage 4 makes every crawler-reachable legacy GET/HEAD route cheap and non-building. Verify:

1. `GET` and `HEAD` for `download_gallery?id=<id>` and `download_smart_gallery?id=<id>` may render/describe the explicit fallback confirmation, but must not enumerate a manifest, create a partial ZIP, or change the immutable artifact/cache tree.
2. The public hero control is an explicit POST form containing resource ID plus a valid `legacy` capability. With JavaScript enabled, progressive enhancement intercepts it and uses the progressive start handshake. With JavaScript disabled, POST submits the bounded legacy fallback.
3. A POST without capability, with malformed/expired capability, wrong resource ID/type, or a `progressive` token must return 403 before manifest enumeration or ZIP building.
4. Confirm large legacy requests still stop at configured file/byte caps with a controlled response. Normal progressive browser downloads are not subject to the smaller legacy fallback cap.
5. Confirm no normal public page contains a crawlable URL whose GET alone can trigger server ZIP construction.

### Public download hardening: Stage 5

Stage 5 adds canonical single-flight and global build admission around the remaining deliberate legacy build path. Verify with two or more parallel POST requests against an uncached small gallery:

1. Only one request owns the canonical build lock. A duplicate build receives HTTP 503 plus bounded `Retry-After`, or reuses the completed result if publication wins the race.
2. Start builds for different resources until `download.legacy_max_concurrent_builds` is reached. Further uncached builds must return 503 without waiting indefinitely or beginning ZIP work.
3. A completed cache/artifact hit must not consume a global build slot and transfer speed must not be throttled.
4. Changing only capability nonce, request ID, User-Agent, host, or irrelevant query parameters must not change the canonical build key. A true gallery/result revision must change it.
5. Kill a local build process while it owns a lock and retry. Kernel `flock()` release must allow recovery without manual lease deletion; incomplete partial output must never be served.

### Public download hardening: Stage 6

Stage 6 makes completed legacy fallback ZIPs immutable managed artifacts. Verify:

1. First authorized legacy POST builds and atomically publishes one artifact. The next POST for unchanged content reuses it without invoking ZIP construction again.
2. Mutate a physical gallery or change the canonical Smart Gallery result. The new revision/fingerprint must use a distinct artifact identity; the old artifact remains isolated until maintenance eligibility.
3. Inspect `metadata.json` in a local cache fixture. It may contain bounded artifact identity, timestamps, size, and expected count, but never capability tokens, visitor/client identity, public URLs, or source paths.
4. Simulate interrupted publication. `.partial-*` content must not be returned by artifact lookup/serve paths.
5. Run maintenance while one artifact is served/built under its lease. Active content must be skipped. Eligible expired artifacts, abandoned partials, and stale inactive coordination state may be removed only from managed cache roots.
6. Temporarily lower `download.legacy_artifact_cache_max_bytes` or raise the free-space margin enough to refuse a new build. Confirm HTTP 507, no partial published artifact, and an Admin event `download.legacy_cache_capacity_refused`.
7. Restore production limits and confirm physical default retention is seven days, Smart Gallery retention one day, partial retention six hours, and inactive coordination retention 24 hours unless locally overridden.

### Public download hardening: Stage 7

Stage 7 reduces repeated progressive-manifest filesystem cost without weakening current authorization. Verify both physical and Smart Gallery routes:

1. Use an Admin test run or browser/devtools timing against a small, medium, and large gallery. On the first authorized request inspect the `download_manifest_profile` component, `Server-Timing`, and `X-PHP-Gallery-Manifest-Cache: miss`. Record SQL query delta when Admin test instrumentation is active, gallery/image row counts, filesystem checks/size reads/realpath checks, elapsed time, and memory. Repeat immediately and expect `hit` with the per-source filesystem work absent while normal authorization/query work remains.
2. Repeat the exact request with irrelevant parameters such as `&noise=1`, a different request ID, or a newly issued capability for the same content. It must resolve to the same revision-keyed cache entry. Do not use arbitrary query parameters that the dispatcher itself rejects as a proxy for cache identity; verify the cache header and on-disk revision filename instead.
3. Inspect one cached `.download-manifests/<type>/<id>/<revision>.json` fixture. It may contain only format/resource/revision/timestamp plus normalized `name`, `size`, `image_id`, `version` data and totals. Search the file for the active capability token and confirm it is absent.
4. Change gallery/image metadata that affects the physical content revision, or change Smart Gallery rules so the ordered result changes. The next manifest must miss the old identity and produce current metadata. Changing only title/host/client/query data that does not affect downloaded file content must not create a new revision entry.
5. While a metadata cache entry exists, revoke access or make a source image/gallery non-public. A request that is no longer authorized must fail before the cache can grant content. Smart Gallery membership must be recomputed before cache reuse. Capability validation remains mandatory on every protected manifest/source request.
6. Request one manifest/source with POST or HEAD, malformed/oversized/non-decimal IDs, array parameters, or malformed source version. Expect 405/400 before DB/filesystem work as applicable. Valid GET follows capability, resource lookup, authorization, revision/cache, bounded work, then transfer ordering.
7. Source-file requests must still independently resolve gallery/Smart Gallery membership, image visibility, containment, size, and optional `v` version. Generated URLs also contain `mr` and `s`; confirm the SEO request guard accepts both parameters on `download_gallery_file` and `download_smart_gallery_file` before dispatch. When the actual size differs, the endpoint must return 409 and invalidate only an exact cache entry that proves the same image/version/expected-size tuple. Tampering with `s` must not evict a valid cache entry. The successful response retains exact `Content-Length`, no intentional bandwidth throttle, and authorized PHP `readfile()` streaming.
8. Exercise the SEO guard with a deliberately unexpected parameter while the same request also contains a fake `capability`, `token`, or `share` value. The sampled Admin security event may record the request path and unexpected parameter names, but its `request_uri` field must replace the entire query string with `?[query-redacted]`; no bearer/query value may appear in the event context.
9. For the no-JavaScript legacy path, mutate a source file size after a manifest has been cached. Before any ZIP build, the server must recompute current aggregate source bytes, invalidate stale manifest metadata on mismatch, and enforce `download.legacy_max_source_bytes` against actual bytes rather than the cached total.
10. Run scheduled/site maintenance and confirm expired/corrupt manifest metadata and old partials are removed within `download.manifest_cache_cleanup_max_entries`; unrelated ZIP/cache/media files must not be touched by this cleanup.
11. Disable JavaScript and verify the explicit Stage 4-6 bounded legacy POST fallback still works for a small physical and Smart Gallery. Re-enable JavaScript and verify both progressive downloads end-to-end, including ZIP creation in the browser.
12. Confirm PHP 8.1 compatibility for changed syntax/APIs, run `php -l` on every changed PHP file, regenerate `app/core-manifest.json`, run `php scripts/generate_manifest.php --check`, and run `php scripts/audit.php --profile=full` when the source test tree is available. The central full profile already owns the registered Node suite. Production deployment ZIPs normally omit `tests/`, so record that absence rather than inventing runtime tests.

Stage 7 runtime defaults are centrally merged and require no existing `config.php` edit: physical manifest metadata retention 86400 seconds, Smart Gallery retention 900 seconds, maximum single metadata file 16777216 bytes, and bounded cleanup scan 10000 entries. A rollback may delete only the private `.download-manifests` subtree; the next authorized request follows the cache-miss path and the progressive protocol remains unchanged.

### 2. Script-Level Tests
The repository uses current direct PHP regression tests under `tests/`. Run the complete isolated suite with:

```bash
php scripts/audit.php --profile=full
```

The central full profile owns every registered standalone Node model. Do not glob `tests/*_test.mjs` manually: the ZIP writer tests require managed temporary output paths, ZIP64 is classified as slow coverage, and browser integration has a different environment contract. When diagnosing one Node failure directly, use the exact invocation recorded in `scripts/audit_registry.php`. The central runner creates and removes temporary ZIP outputs automatically.

Deployment packages remain production-like and omit `tests/` by default. A normal local ZIP is created with `./deploy.sh --mode local --deploy-folder deploy --upload-media false --make-zip-deploy true`; add `--include-tests true` only for a development/source-review ZIP. On Windows use `scripts/deploy.ps1 -Mode local -DeployFolder deploy -UploadMedia false -MakeZipDeploy true`, adding `-IncludeTests true` for the review package. Test inclusion is local-only and is rejected in FTP mode; all existing secret, runtime-data, cache, log, `.git`, and media exclusions remain in force.

Browser ZIP-import changes must additionally verify stored and Deflate archives, nested image paths, mixed supported and unsupported entries, empty archives, damaged central directories, encrypted/ZIP64 archives, traversal names, hidden `__MACOSX` metadata, oversized entries, expansion-ratio limits, duplicate filenames, and a ZIP selected while browser-assisted upload is unchecked or unsupported. The archive itself must never reach the classic PHP upload request; extracted valid images must still use the existing browser preparation, batching, server validation, thumbnail, and Admin side-panel progress pipeline.

For Windows upload-automation regression testing, exercise both the watched-folder screenshot path and manual WinApp upload. The canonical multipart request must use `X-Gallery-API-Key`, `images[]`, `create_thumbnails`, optional `image_client_ids[]`, and optional `client_thumbnails[]` metadata. With local thumbnail generation available, verify the client can submit the full JPG+WebP compatibility matrix against both thumbnail modes: a modern WebP-only server must accept the original and WebP variants while counting valid JPG extras as skipped without returning an upload error or triggering watcher fallback to server-side thumbnail generation; a legacy server must accept both formats. Keep invalid/unknown formats, MIME mismatches, unsupported sizes, and malformed thumbnail uploads as hard request failures. Watched screenshots should also preserve optional Flight Simulator camera metadata and inventory-based duplicate suppression.

For create-with-upload regression testing, also create a new child gallery with one or more photos while browser-assisted upload is enabled. Confirm exactly one gallery row/folder/card is created and each selected photo is stored once. The successful browser result contains canonical `fallback` metadata as an object; this must not cause the classic upload path to run afterward. With selected photos and browser processing checked, deliberately break one required browser capability or worker preparation step and confirm the upload stops before persistence instead of switching to classic PHP upload. A browser batch with thumbnail creation enabled must carry `prepared_thumbnails_required=1`, and PHP must reject an incomplete prepared-thumbnail manifest before storing originals. The literal `fallback === true` path is reserved for an empty create-gallery submission with no files. Run `php scripts/check_admin_mutation_contracts.php` to protect these invariants. Repeat once with browser preparation disabled to confirm the classic path still creates exactly one gallery and remains the explicit server-side choice.

Run `node tests/browser_upload_zip_worker_test.mjs` for the generated stored/Deflate mixed-archive worker fixture. Node is a development-only test convenience; it is not required by the deployed PHP application.

Run one focused test directly when diagnosing a failure:

```bash
php tests/gallery_visibility_model_test.php
php tests/duplicate_photo_detector_test.php
php tests/duplicate_photo_ledger_test.php
php tests/browser_upload_settings_test.php
php tests/gallery_public_paths_test.php
php tests/migration_consistency_test.php
php tests/migration_legacy_runner_compatibility_test.php
php tests/database_maintenance_test.php
php tests/database_maintenance_schema_repair_test.php
php tests/updater_safety_model_test.php
php tests/updater_resumable_state_machine_test.php
php tests/thumbnail_warmup_model_test.php
php tests/public_thumbnail_rendering_model_test.php
php tests/public_thumbnail_markup_test.php
php tests/hero_tag_theme_model_test.php
php tests/tag_metadata_mysql_compatibility_test.php
php tests/translation_catalog_consistency_test.php
php tests/lightbox_zoom_lifecycle_test.php
php tests/lightbox_zoom_integration_test.php
php tests/lightbox_zoom_translation_test.php
php tests/lightbox_zoom_quality_candidates_test.php
php tests/lightbox_zoom_quality_rendering_test.php
php tests/lightbox_zoom_quality_lifecycle_test.php
php tests/lightbox_zoom_quality_indicator_test.php
node tests/lightbox_zoom_model_test.mjs
node tests/progressive_thumbnail_renderer_test.mjs
```

The favorite shortcut test covers zero configured shortcuts, direct gallery links, the optional main-page shortcut, duplicate/missing-gallery cleanup, public visibility filtering, and HTML escaping.
The gallery dates test covers manual date range normalization, reversed-range rejection, public display formatting with en dash separators, rendered date attributes, and branch matching used by scoped EXIF suggestion reviews.
The duplicate photo detector tests cover exact checksum matches, normalized EXIF candidates, file-size-only rejection, selected-branch/global scope, deterministic and bounded pair expansion, persistent pair/exact-gallery filtering, parent/child gallery independence, clickable public context links, delete and ledger scope validation, detector-job pruning, database migration contracts, reuse of the existing image deletion service, and in-place AJAX side-panel integration for delete/ignore/clear actions. The ledger test separately covers canonical pair storage, per-administrator keys, exact-gallery semantics, cascade constraints, current-admin clearing, and protected maintenance policy.
The gallery public-path test covers Czech transliteration, decomposed accents, invisible Unicode characters, HTML entities, hierarchical paths, and sibling slug collisions.
The tag metadata MySQL compatibility test guards the Admin tag-usage query against MySQL error 3065 by requiring every DISTINCT ordering expression that is not already projected to be included in the SELECT list.
The migration consistency test validates every migration definition, preflights the complete migration set, and proves that old schema_migrations rows remain harmless after obsolete migration files are removed.
The legacy migration-runner compatibility test verifies that PHP repair migrations work both with the current definition-aware runner and with the former SQL-only runner that may still be present during a partial patch deployment.
The database maintenance test covers information_schema normalization, compact and legacy schema detection, SQL-literal reference scoping, obsolete thumbnail objects, orphan and expiry rules, deterministic duplicate survivor selection, protected content/log/telemetry tables, report-only unsupported thumbnail variants, Admin authentication, CSRF, confirmation contracts, and the absence of filesystem cleanup side effects.
The Admin log scaling test covers indexed age/grouping migration contracts, grouped browsing, bounded keyset exports, retention normalization, and the archive-first deletion boundary. The Admin log archive maintenance test covers protected day archive paths, self-describing JSON/HTML output, row-count verification, interrupted-work recovery, resumable state, and retention cleanup without exposing archive data publicly.
The database maintenance schema-repair test uses a mutable PDO fixture to verify audit-table creation, absent thumbnail tables, partially compacted schemas, geometry migration before destructive DDL, obsolete index/foreign-key cleanup, already compact schemas, idempotent retry, and the absence of row or filesystem deletion.
The updater safety test verifies that critical runtime files, the core manifest, and the resumable update service are required before deployment starts and that valid top-level app entries such as `app/views.php`, `app/views/`, `app/lang/`, and migration support modules are never classified as misplaced project copies. `tests/updater_resumable_state_machine_test.php` additionally covers ordered stage transitions, bounded time budgets, package-path rejection, manifest coverage, corrupt-archive rejection, safe error redaction, worker locking, stale-lock recovery, rollback snapshot copying, activation ordering, stable/beta/reinstall/restore job routing, background continuation, migration checkpoint wiring, Admin in-place controls, side-panel event delegation, browser reopen continuation, and the absence of page reload/navigation in the JavaScript updater.

The translation catalog consistency test requires English, Czech, German, and Swedish to remain key-for-key complete, verifies placeholder parity across all four maintained catalogs, statically checks that literal PHP/JavaScript translation calls exist in English, validates dormant future-language skeletons as safe subsets of English, confirms that only `en`, `cs`, `de`, and `sv` are selectable in `config.example.php`, and guards the Admin/Public selector filtering plus the English default/fallback contract.

The public thumbnail rendering model test covers progressive default/fallback normalization, supported setting persistence, invalid Admin input normalization, the narrow renderer dispatch boundary, the unchanged responsive eager/lazy/fetchpriority thresholds, and progressive small-thumbnail thresholds. The public thumbnail markup test covers complete responsive srcsets, small-only progressive active srcsets, inert larger candidates, WebP/JPEG structures, missing variants, synthetic bounds, intrinsic dimensions, media fallback, warm-up attributes, and selected-gallery NSFW gate ordering. The hero tag Theme model test covers 20-tag and five-row defaults, server-side clamping, display-all and scrollbar booleans, usage/alphabetical mode normalization, Admin persistence wiring, complete server-rendered hero groups, full-width CSS overrides, anonymous/logged-in browser entrypoints, accessible disclosure state, row-based scrollbar activation, and English/Czech public strings. `tests/progressive_thumbnail_renderer_test.mjs` covers browser-independent candidate parsing, smallest-adequate selection, capped DPR width calculation, queue deduplication, visible priority, and the two-worker concurrency bound. DOM intersection, actual browser network order, decode timing, cache reuse, lightbox/maps/votes interaction, hero tag wrapping at real browser widths, and reduced-motion rendering remain manual checks.

These tests are maintained against the current namespaced production code. They are best for pure logic, helper functions, and regression checks that do not require a browser session. A release patch should not be published while `php scripts/audit.php --profile=full` reports a failure.

### Viewer Phase 0 security-foundation coverage

Phase 0 viewer foundations deliberately have no HTTP route, so focused tests exercise services and static architecture boundaries without requiring an external mail provider, browser, or Internet service:

```text
php tests/viewer_security_foundations_test.php
php tests/viewer_schema_foundations_test.php
php tests/viewer_identity_boundary_test.php
```

`viewer_security_foundations_test.php` verifies disabled-by-default/fail-closed configuration, service-level refusal before database access while disabled, independent viewer session namespace behavior, preservation of administrator session keys, cryptographically random opaque tokens, authority hashing/verification, deterministic email normalization, native password hash/verify/rehash behavior, no silent bcrypt-length truncation, one-time-token expiry/consumption policy, fixed abuse-policy names, identifier/subnet normalization, configured hard subject caps, trusted-proxy CIDR behavior, default rejection of spoofed forwarding headers, and security-event context redaction.

`viewer_schema_foundations_test.php` validates that the migrations are additive and ordered, creates every intended viewer table including the Phase 0.6 durable-account counter, leaves historical identity/media tables untouched, stores token authority hashed, defines expiry/invalidation/single-use lifecycle fields, uses canonical `images.id` references, protects favourite/collection uniqueness with database constraints, provides deterministic collection ordering, keeps collection/share rows free of gallery/media permission fields, stores no passkey private key, uses bounded rate-limit storage, defaults the feature and registration off, defaults trusted proxies off, exposes no viewer controller/dispatcher route, and verifies scheduled viewer cleanup is no longer gated by feature enablement.

`viewer_identity_boundary_test.php` guards the most important repository-specific security invariant: `current_user()` continues to use only the administrator `users` table and `$_SESSION['user_id']`, while `current_viewer()` uses only viewer session/tables. It also proves the historical `visitor_can_access_gallery()` administrator bypass still depends only on `current_user()`, public media does not consult viewer auth, existing administrator auth/persistent-login code remains viewer-unaware, the existing CSRF contract is unchanged, and historical gallery share-token validation remains separate from future collection sharing.

These focused tests supplement, rather than replace, `php scripts/audit.php --profile=full`, `tests/migration_consistency_test.php`, authentication schema-policy tests, gallery-access schema-policy tests, and the Node model tests. Fresh installation and upgrade safety are represented by the shared migration directory/runner contract plus migration preflight/replay tests. When a disposable MySQL/MariaDB instance is available, release qualification should additionally execute a fresh install and an upgrade from a pre-Phase-0 database because MySQL DDL cannot be rolled back as one transaction.

### Viewer Phase 0.5 registration/mail-abuse foundation coverage

Phase 0.5 remains route-free and transport-free. Run:

```text
php tests/viewer_registration_foundations_test.php
php tests/viewer_mail_abuse_foundations_test.php
```

`viewer_registration_foundations_test.php` verifies disabled/fail-closed staging, generic public-result foundations, optional invitation email binding, claimed/revoked/expired invitation rejection, transactional invitation-state revalidation after preflight, revocation availability while admission is disabled, scanner-safe non-consuming verification predicates, replay rejection, three-state schema availability, binary-unique pending-email deduplication, unique invitation use, hashed invitation/verification authority, indexed expiry cleanup, the locked registration-capacity counter, absence of password storage in staging, and Phase 4.1 reuse of the same registration service rather than a parallel signup subsystem.

`viewer_mail_abuse_foundations_test.php` verifies independent verification/reset/invitation mail budget plans, per-address cooldown/hour/day limits, per-client and installation-wide limits, narrow-to-global reservation ordering that protects the global circuit breaker from suppressed-request exhaustion, generic future external outcomes, fail-closed invalid recipient/client handling, corrected `max_attempts` semantics, and the deliberate absence of PHP `mail()`, SMTP sockets, provider APIs, queues, or other delivery code. Existing administrator password-reset mail is not refactored by Phase 0.5.

These tests intentionally do not claim live transactional concurrency coverage because the standard sandbox/repository test environment may not provide a PDO MySQL/MariaDB driver. The SQL paths use row locks, unique constraints, and transaction-scoped capacity admission. Release qualification on a disposable MySQL/MariaDB database should additionally race duplicate pending requests, one invitation claim, and one verification confirmation to confirm exactly one durable state transition.

### Viewer Phase 0.6 authentication/request-security foundation coverage

Phase 0.6 remains route-free, UI-free, cookie-emission-free, and mail-transport-free. Run:

```text
php tests/viewer_authentication_phase06_test.php
```

`viewer_authentication_phase06_test.php` exercises the aggregate three-state viewer-auth schema capability (available/missing/unknown), 15-code-point native password policy, Unicode/spaces/no-composition behavior, native hash/verify/rehash, viewer/admin CSRF separation, short-lived activation and reset pre-auth namespaces, activation-state HMAC binding/expiry, forged versus trusted forwarded HTTPS, invalid proxy config, IPv4/IPv6 trusted-proxy behavior, strict configured security-link origin and Host-header poisoning resistance.

The same test statically protects database transaction/locking contracts that cannot be executed without PDO MySQL: singleton `viewer_account_state` serialization, invitation re-lock during activation, staging retirement, login throttle ordering before account/password work, deterministic session/remember caps, remember selector/verifier rotation, security-version-aware reset locking/revocation, collection-share revocation on account state changes, viewer/pre-auth no-store classification, cleanup while disabled, and the Phase 0.6 service boundary remaining free of direct cookie emission and mail transport. Phase 1.0 HTTP adapters may now consume those established services.

A release environment with MySQL/MariaDB and `pdo_mysql` should additionally run live races for durable account-cap admission, two concurrent activations, session-cap admission, remember restore rotation, reset-token final use, and security-version invalidation. If that database capability is absent, release notes/test reports must state those integration races were **not run** rather than presenting static/model checks as live concurrency coverage.

### Viewer Phase 0.7 lifecycle/content-authorization coverage

Phase 0.7 is still HTTP-free and UI-free. Run the focused deterministic contract test with:

```text
php tests/viewer_account_lifecycle_phase07_test.php
```

It covers three-state lifecycle schema capability, recent-reauth namespace clearing/expiry, interactive-login versus remember-restoration semantics, strict future content-quota parsing, 120-code-point/480-byte plain-text title policy, invalid UTF-8/NUL/control/bidi rejection, scanner-safe staged email change, security-version-aware password/email mutation structure, account-deletion capacity reconciliation, no-admin-bypass source-image authorization, and the continued absence of Phase 0.7 lifecycle HTTP wiring while keeping the Phase 0.7 content-foundation service policy-only; later favourite CRUD is covered separately by the Phase 1.1 test and private collection CRUD is covered separately by the Phase 2.0 test.

A real MySQL/MariaDB race harness is optional and intentionally separate from the default suite:

```text
GALLERY_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=php_gallery_test;charset=utf8mb4' \
GALLERY_TEST_MYSQL_USER='gallery_test' \
GALLERY_TEST_MYSQL_PASSWORD='...' \
php tests/viewer_phase07_mysql_concurrency_test.php
```

The database must already contain the current migrated schema and must be disposable test data. The harness creates isolated fixture rows, launches independent PHP worker processes with independent PDO connections, releases them through an explicit process-pipe barrier, and cleans up/reconciles viewer capacity state afterwards. It exercises seven storage-level race invariants separately: duplicate verified activation, hard durable-account cap, active session cap, remember-token rotation, one reset-token final use, security-version invalidation competing with authentication authority, and account deletion versus account-capacity consistency. It is a low-level InnoDB/row-lock integration harness, not a replacement for service-contract tests.

If `pdo_mysql`, the DSN, or the required migrated tables are unavailable, the harness prints `SKIP` with the exact reason and exits without claiming that a race ran. Static SQL inspection is not equivalent to this live test.

### Schema-inspection reliability regression coverage

The schema-inspection reliability conversion is complete. Repository-wide review
should still search for `SHOW COLUMNS`, `SHOW TABLES`, `information_schema`,
`db_column_exists`, `db_table_exists`, `schema_ready`, `column_exists`, and
`table_exists` when adding or modifying schema-sensitive code. A direct metadata
query is acceptable only when its purpose cannot be represented as inspection of a
known table/column/index/definition and the exception is documented and tested.

Behavioral coverage must preserve all three inspection states:

- successful inspection with the object available;
- successful inspection with the object missing;
- failed inspection with state unknown.

Tests must prove that unknown state cannot become a permissive access default,
an unthrottled authentication path, an accepted upload, a partial destructive
mutation, or a misleading “migration missing” diagnostic. Presentation-only
fallback tests should prove that the base page can continue only when omitting
the optional feature cannot reveal protected content or authorize a write.
Migration tests must also prove that request-local inspection state is reset
after successful DDL when inspection and validation happen in one PHP process.

The Phase 2 primitive test is available now:

```text
php tests/schema_inspection_model_test.php
```

It covers table, column, and index availability and absence; generic and PDO
inspection failures; safe SQLSTATE handling; secret and hostname redaction;
identifier rejection before query execution; request-local cache reuse and
reset; state predicates; feature requirement preservation; and
`unknown > missing > available` aggregation. The dedicated Phase 3 hardening
also verifies the production `information_schema` query definitions and bound
parameters, `DATABASE()` scoping, registration before consumers, independent
cache identities, cached missing/unknown results, executor-triggered cache
reset, mutually exclusive predicates, private-path/token redaction, and
rejection of incomplete aggregate requirements. It uses a narrow executor seam
and does not connect to a live database.

The first security-sensitive caller test is available now:

```text
php tests/nsfw_schema_policy_test.php
```

It verifies unchanged gallery-level and image-level NSFW enforcement with a
complete schema, the documented historical compatibility path for confirmed
pre-feature schemas, and fail-closed behavior for unknown inspection state. An
isolated dispatcher fixture runs the real dispatcher and 503 response helper to
prove that unknown state blocks media, thumbnails, lazy lightbox metadata, and
map metadata before their handlers emit output; returns translated HTML, JSON,
or plain text with status 503 and a safe request reference; and never exposes
fixture SQL or secrets. The same test distinguishes missing from unknown Admin
health, proves unknown never becomes disabled, verifies logged-in anonymous
preview follows the same safe public policy, preserves complete-schema route
behavior, and protects the explicit bulk-mutation refusal contract. It uses the
isolated schema query executor and does not connect to a live database.

The response boundary has an additional focused test:

```text
php tests/service_unavailable_response_test.php
```

It renders the real response helper with isolated translation, request-ID, and
Admin-log doubles. The test verifies HTTP 503 status; HTML, JSON, and plain-text
bodies; stable public and internal error codes; request correlation; bounded
security log context; absence of representative SQL, credential, token, stack,
and private-path values; and the no-store, retry, crawler, and content-type
header contract. It requires no database or web server.

Admin health interpretation has a focused pure-model test:

```text
php tests/admin_nsfw_system_health_test.php
```

It covers available, confirmed missing, unknown, intentionally disabled, and
malformed states; request-reference ownership; migration and operational
suggested-check keys; validated affected-object identities; rejection of raw
diagnostics and malformed object names; dashboard Maintenance/System Health
action badges; and shared Runtime Diagnostics ownership. The disabled case
protects the common health vocabulary. NSFW Guard itself currently has no
configuration feature flag and therefore does not produce disabled in normal
runtime operation.

Phase 8 adds migration/cache integration coverage:

```text
php tests/migration_schema_cache_reset_test.php
```

The test uses a minimal PDO double plus the schema-inspection executor seam, so
it needs no live MySQL or MariaDB service. It first proves that a cached
`missing` result is reused during the request and remains cached across a
data-only migration statement. It then executes the real
`apply_migration_statement()` boundary with simulated DDL and proves that the
next capability check performs a fresh inspection and sees the changed schema.
The duplicate-DDL replay path is covered as well because interrupted shared-host
updates can encounter objects that already exist. Source contracts additionally
protect cache invalidation after the `schema_migrations` bootstrap and after
successful migration repair callbacks, which may perform their own DDL.

`tests/nsfw_schema_policy_test.php` also counts the pilot's metadata calls. A
complete NSFW capability requires exactly one request-local lookup for
`galleries.nsfw_enabled` and one for `images.nsfw_enabled`; repeated readiness,
gallery, and image policy helpers must not add more `information_schema`
queries in the same request.

For Phase 8 pilot review, run the focused set before the full suite:

```text
php tests/schema_inspection_model_test.php
php tests/nsfw_schema_policy_test.php
php tests/service_unavailable_response_test.php
php tests/admin_nsfw_system_health_test.php
php tests/migration_schema_cache_reset_test.php
php tests/migration_consistency_test.php
php scripts/audit.php --profile=full
```

Manual NSFW outage verification should simulate an inspection failure on a
disposable installation. Confirm that gallery pages receive translated 503
HTML, lightbox/map/search requests receive 503 JSON, media and thumbnail routes
receive 503 plain text, no protected URL or metadata appears, and Admin System
Health shows an inspection failure with a safe reference. Confirm that gallery,
image, and bulk NSFW changes are refused, then restore database access and
verify existing restrictions behave unchanged.

### Phase 9 security and authentication schema policy tests

Phase 9 adds three focused test scripts plus an isolated dispatcher fixture:

```text
php tests/gallery_access_schema_policy_test.php
php tests/auth_schema_policy_test.php
php tests/security_schema_system_health_test.php
```

`tests/gallery_access_schema_policy_test.php` covers the public authorization
side of the conversion. It verifies:

- complete gallery-access schema preserves password inheritance and unlisted
  behavior;
- confirmed legacy compatibility is permitted only when all five core access
  columns are absent;
- a partially applied access migration fails closed instead of substituting
  `normal`/`listed`;
- access metadata inspection failure remains `unknown` and redacts simulated SQL
  and credential material;
- confirmed historical visibility vocabulary stores canonical `unpublished` as
  `draft`, while unknown enum inspection refuses to guess;
- missing share-token display storage disables token use, while unknown storage
  raises the dedicated policy exception;
- the real dispatcher returns 503 before gallery, public-media, public-thumbnail,
  lazy-lightbox-data, and gallery-download sentinel handlers for partial access,
  unknown access, and unknown visibility states;
- response representation remains HTML, JSON, or plain text according to route;
- bounded logs contain only the feature, state, route, response format, and
  request correlation, never fixture secrets, DSNs, passwords, or SQL.

`tests/support/security_schema_policy_dispatch_fixture.php` provides the isolated
real-dispatcher environment for those route tests. Keep it aligned with the
central sensitive-route preflight in `app/bootstrap/dispatch.php` whenever a new
public endpoint can expose protected gallery state, metadata, archives, or media.

`tests/auth_schema_policy_test.php` covers authentication capabilities without a
live database. It verifies:

- `admin_remember_tokens`, `users.email`, `password_reset_tokens`, and
  `user_google_accounts` available/missing/unknown states;
- request-local cache reuse, including one metadata query for `users.email` even
  when both email-login and password-reset status request it;
- confirmed missing remember-token storage degrades to ordinary PHP-session login
  instead of failing authentication;
- unknown remember-token storage refuses persistent-token issuance/use and logs
  only bounded feature/operation context;
- password reset becomes incomplete when either its table or `users.email` is
  missing and remains unknown when the shared email dependency cannot be
  inspected;
- confirmed missing external-identity storage can safely disable read/link UI,
  while unknown storage refuses both lookup and mutation;
- configuration-disabled persistent login short-circuits before metadata queries;
- schema-policy-only checks do not touch application data tables.

`tests/security_schema_system_health_test.php` protects the generic four-state
Admin health model, bounded affected-object normalization, request-reference
behavior, the complete Phase 9 capability registry, the System Health action
badge contract, and Runtime Diagnostics use of the same status set.

For Phase 9 regression work, run the focused security set first:

```text
php tests/schema_inspection_model_test.php
php tests/gallery_access_schema_policy_test.php
php tests/nsfw_schema_policy_test.php
php tests/auth_schema_policy_test.php
php tests/security_schema_system_health_test.php
php tests/admin_nsfw_system_health_test.php
php tests/service_unavailable_response_test.php
php tests/migration_schema_cache_reset_test.php
php tests/translation_catalog_consistency_test.php
php scripts/audit.php --profile=full
```

Manual Phase 9 outage verification should use a disposable installation and
exercise more than the NSFW card. Temporarily deny metadata inspection or make
the selected database unavailable, then confirm: public gallery/media/thumb/
lightbox/download requests fail before protected output; System Health shows the
affected access/visibility/auth capability as unknown; password login does not
turn an inspection failure into an invalid-credential result; persistent login
is not issued; password reset and Google link/login operations report temporary
storage unavailability; an already authenticated PHP session remains usable
where the failed optional capability is not needed. Restore metadata access and
confirm normal behavior without restarting the PHP process when testing through
a same-process migration path.

Manual partial-migration verification should remove or rename one access column
only on a disposable database. Confirm that the installation is not treated as a
fully legacy unprotected gallery. Restore/apply the migration and verify the
existing password, unlisted, share-link, media, and download rules again.

### Phase 10 destructive and ingestion schema policy tests

Phase 10 adds `tests/mutation_schema_policy_test.php` and extends the updater
safety fixture. The focused test is intentionally mixed behavioral/static
coverage because the mutation policy itself is database-independent while many
production mutation functions also require filesystem, HTTP upload, or full Admin
runtime context.

`tests/mutation_schema_policy_test.php` verifies:

- available, confirmed missing, and unknown aggregate state for a destructive
  gallery capability;
- redaction of simulated database connection/SQL/credential details from unknown
  inspection results;
- confirmed missing optional columns return the documented compatibility answer,
  while an unknown optional column raises `MutationSchemaUnavailableException`
  instead of being converted to `false`;
- upload-automation issuance/authentication requires the complete token schema,
  while `upload_automation_revocation_schema_status()` remains available with the
  smaller identity/revocation column set;
- mobile WebDAV issuance/authentication requires the complete credential schema,
  while the independently verified deletion capability can remain available;
- `upload_ingestion_schema_status()` uses exactly twelve metadata probes for its
  complete gallery/image requirement set on first use and zero additional probes
  when repeated in the same request;
- every converted Phase 10 mutation service is free of `db_table_exists()` and
  `db_column_exists()` authorization logic;
- `mutation_schema_policy.php` is loaded before destructive consumers;
- all ten mutation capability keys are registered in Admin System Health and the
  same set is consumed by Runtime Diagnostics;
- classic upload performs thumbnail-write compatibility preflight before
  `move_uploaded_file()` can commit a source to the gallery;
- prepared browser upload performs the same preflight before writing an original
  gallery file;
- thumbnail generation checks its complete metadata write shape before creating
  the derivative directory;
- each beta/stable/reinstall/restore/rollback update job calls
  `application_update_assert_activation_schema_known()` before the `ready`/activation boundary;
- updater source validation requires `app/services/schema_inspection.php`,
  `app/services/mutation_schema_policy.php`, `app/services/updates_jobs.php`, and
  `app/core-manifest.json`;
- all normal preparation stages checkpoint before active files change, while
  `activate` contains only prepared local replacements and is retry-safe rather
  than pretending to be interruptible;
- migration continuation uses `run_migrations_bounded(1)` and the
  `schema_migrations` row as its durable file-level checkpoint.

`tests/updater_safety_model_test.php` now builds a Phase 10-capable incomplete
snapshot fixture. The fixture contains the two schema-policy services so its
historical assertion still proves that a missing core runtime file such as
`app/views.php` prevents update activation.

Run the Phase 10 focused regression set after any deletion, ingestion, token,
migration, thumbnail, database-maintenance, updater, or mutation-health change:

```text
php tests/mutation_schema_policy_test.php
php tests/schema_inspection_model_test.php
php tests/migration_schema_cache_reset_test.php
php tests/duplicate_photo_ledger_test.php
php tests/browser_upload_settings_test.php
php tests/gallery_migration_model_test.php
php tests/thumbnail_compatibility_model_test.php
php tests/thumbnail_warmup_model_test.php
php tests/database_maintenance_schema_repair_test.php
php tests/updater_safety_model_test.php
php tests/security_schema_system_health_test.php
php tests/translation_catalog_consistency_test.php
php scripts/audit.php --profile=full
```

Manual Phase 10 outage verification must use a disposable installation with a
coordinated filesystem/database backup. Temporarily deny metadata inspection or
select an unavailable database, then verify these workflows are refused **before**
their target mutation:

1. Delete a gallery/image and confirm the source file, gallery folder, and rows are
   unchanged.
2. Move/copy an image or gallery and confirm both old path/ownership and target
   location are unchanged.
3. Add/clear a Duplicate Photo Detector ledger item and confirm the existing ledger
   remains unchanged while System Health shows the ledger capability as unknown.
4. Submit a classic upload and a prepared browser ZIP. Confirm no source image is
   moved/written into the gallery. The PHP temporary upload or prepared package
   should remain the recoverable source for the failed request where the hosting
   runtime permits it.
5. Attempt upload-automation and mobile-WebDAV credential creation/use. Confirm no
   credential is created/trusted. Separately verify revocation still works when the
   full schema is intentionally incomplete but the narrow revocation columns are
   present and inspectable.
6. Start/resume a gallery migration and confirm the job remains resumable with no
   new target original/thumbnail when the relevant preflight is unknown.
7. Generate/repair/delete thumbnails and confirm derivative files are untouched on
   unknown metadata schema. Repeat with a **confirmed absent** metadata table on an
   old-schema fixture to verify the documented file-only compatibility path.
8. Start database cleanup/schema repair and confirm no cleanup batch or repair DDL
   executes while metadata inspection is unknown.
9. Stage an application update and confirm download/extraction may complete, but
   active files are not replaced when activation schema readiness is unknown.
10. Open Admin System Health and Runtime Diagnostics. Confirm all ten mutation
    capability cards use the same state, missing/unknown produces an Action signal,
    and visible/copied diagnostics contain no SQL, raw exception text, DSN,
    password, API/WebDAV token, upload path, migration source path, or staging path.

Restore metadata access and rerun the operations. Also test confirmed-missing states
separately from unknown states: pending migrations should be reported as migration
requirements, while explicitly audited compatibility/bootstrap paths continue only
where documented. This distinction is the core Phase 10 acceptance criterion.

### Phase 11 optional presentation and reporting schema policy tests

Phase 11 adds `tests/presentation_schema_policy_test.php` and extends existing
lightbox, translation, upload-automation, telemetry, dashboard and report source
contracts. The focused policy test is intentionally database-free and uses the
schema-inspection executor seam so `available`, confirmed `missing`, and `unknown`
can be reproduced deterministically.

`tests/presentation_schema_policy_test.php` verifies:

- complete voting storage resolves to `available` and requires exactly **ten**
  first-use metadata probes; checking the same voting capability again in the same
  request performs zero additional probes because object results are cached;
- a confirmed absent voting column produces `missing`, safe optional rendering is
  omitted, `presentation_schema_assert_known()` allows only the audited
  compatibility path, and `presentation_schema_assert_write_available()` blocks a
  write that requires the feature;
- an injected metadata exception produces `unknown`, safe optional rendering is
  omitted, dependent writes throw `PresentationSchemaUnavailableException`, and
  bounded logs contain neither the injected secret marker nor a credential-bearing
  DSN;
- `presentation_schema_health_definitions()` registers exactly fifteen Phase 11
  capabilities and is **lazy**: building the registry performs zero metadata queries.
  Resolving only the voting health entry performs only the ten voting probes;
- complete Picture Game requirements aggregate correctly after composing the voting
  and game-specific storage requirements;
- converted Phase 11 service files, including gallery sidecar creation/import and
  metadata-organizer capture-date readiness, do not contain legacy `db_table_exists()`,
  `db_column_exists()`, or direct `SHOW COLUMNS` policy probes;
- gallery creation/import verifies voting storage before enabling voting and refuses
  unknown lightbox override persistence instead of silently dropping an explicit or
  inherited override;
- the Complete Admin Gallery Report uses structured named-object checks for known
  dependencies, retains the explicitly justified dynamic
  `information_schema.TABLES` base-table inventory query, and does not export raw
  `$exception->getMessage()` database text;
- the AI worker endpoint preflights the AI metadata capability before queue writes
  and its Phase 11 action handler does not return or log raw service/database
  exception text;
- Picture Game bulk mutation uses the exact Picture Game status instead of the old
  unrelated `admin_feature_schema_ready()` aggregate;
- Admin System Health and Runtime Diagnostics consume the final Phase 11 registry.

`tests/gallery_lightbox_mode_model_test.php` now drives lightbox schema readiness
through the structured schema-inspection executor instead of stubbing the old
boolean database helper. This keeps model coverage representative of production
policy.

Run the Phase 11 focused regression set after changes to maps, voting, Picture Game,
lightbox overrides, OpenAI/AI metadata, SimBrief, navigation data, telemetry, the
Admin report, presentation health, or the schema inspector:

```text
php tests/presentation_schema_policy_test.php
php tests/gallery_lightbox_mode_model_test.php
php tests/openai_text_assist_model_test.php
php tests/simbrief_description_model_test.php
php tests/schema_inspection_model_test.php
php tests/migration_schema_cache_reset_test.php
php tests/security_schema_system_health_test.php
php tests/mutation_schema_policy_test.php
php tests/translation_catalog_consistency_test.php
php scripts/audit.php --profile=full
```

Manual Phase 11 verification should use a disposable database or a database user
whose metadata permissions can be temporarily restricted. Verify both confirmed
missing and unknown states separately:

1. Enable GPS maps, then make one required GPS/EXIF metadata object uninspectable.
   The public gallery must remain usable without the optional map, while System
   Health reports the GPS capability as unknown. Restore access and confirm the map
   returns.
2. Attempt to change the per-gallery GPS override while its nullability/column
   definition is unknown. Confirm the setting is not changed and the Admin notice
   points to System Health rather than claiming a migration is missing.
3. For image voting and Picture Game, confirm read-only UI omission where applicable,
   but vote submission, displayed-pair recording, game votes and Admin bulk game
   toggles refuse unknown schema. A confirmed missing migration should instead show
   migration guidance.
4. Make the lightbox override definition uninspectable. Existing gallery viewing may
   use the safe inherited/default mode, but a submitted per-gallery override must not
   be persisted until inspection succeeds.
5. Make OpenAI settings or AI image-analysis storage uninspectable. OpenAI settings
   saves and AI queue mutations must refuse the write. The companion AI worker must
   receive a bounded operational error with no SQL, DSN, raw PDO message, token, or
   private path.
6. Test SimBrief with route-map storage confirmed missing and then unknown. Draft/OFP
   generation may continue, but no route-map database write may be claimed. Unknown
   must appear in diagnostics.
7. Test navigation account persistence with a confirmed pre-account schema and with
   an inspection outage. Session-only compatibility is allowed only for confirmed
   absence. Unknown storage must refuse persistence. Separately verify the narrow
   verified disconnect/delete capability can still remove stored credentials.
8. Test telemetry dashboard/export/settings/maintenance. Confirm a missing schema is
   presented as migration-required, while unknown is presented as database status
   unavailable. Setting changes, rollup, and purge must not silently succeed on
   unknown schema.
9. Export the Complete Admin Gallery Report with one optional section absent and with
   a simulated inventory/read failure. Confirm absent sections degrade safely and
   report output contains generic unavailable text, not raw database exception text.
   Confirm Picture Game statistics show completed selections versus
   displayed-without-selection rows.
10. Disable each feature-flagged Phase 11 capability and load System Health. Confirm
    it reports `disabled` without inspecting that feature's schema. Re-enable the
    feature and confirm the resolver runs and shows available/missing/unknown.
11. Copy Runtime Diagnostics and confirm the same fifteen capability states are
    represented with only validated object identities, safe suggested checks, and a
    request reference for unknown state.

After restoring metadata access, rerun the complete suite. Release acceptance requires
every currently registered PHP regression test to pass, translation catalogs to remain
aligned across English/Czech/German/Swedish, all changed PHP files to pass `php -l`,
all changed JavaScript modules and Node fixtures to parse and pass, the integrity
manifest to be current, the administrator manual to be rebuilt and visually verified,
and no temporary implementation roadmap to remain in the repository or release package.

### 2.1 Release preparation and handoff

Use this order for each release so generated integrity data describes the final tree:

1. Start from a clean release branch. Compare it with the exact previous release tag, inspect every intervening commit, and review every changed path, including migrations, protected files, package exclusions, translations, browser modules, generated artifacts, and documentation. Record intentionally excluded work.
2. Audit every new migration in timestamp order. Run migration consistency and legacy-runner compatibility tests, confirm upgrades require no manual schema edits or metadata rebuild unless explicitly documented, and verify partial/unknown schema states still follow the applicable security, mutation, or presentation policy.
3. Update `app/bootstrap.php`, `release-metadata.json`, `PATCH_NOTES.md`, README/architecture/database/testing version markers, and all affected user/developer documentation. Keep the newest patch-note entry above historical entries and follow `PATCH_NOTES_TEMPLATE.md`. Confirm changed browser modules have a fresh cache-busting import chain.
4. Compile and visually inspect `docs/PHP_Gallery_Manual.pdf` using all commands in `docs/LATEX_BUILD.md`. Check the title/version/date, permanent purpose and usage guide, table of contents, bookmarks, index, page breaks, and newly changed feature sections. Confirm no release-news or version-history section was inserted at the beginning; release history belongs in `PATCH_NOTES.md` or a deliberate appendix only.
5. Complete the final textual/source edit, then run `php scripts/audit.php --profile=full`. Use focused PHP/Node commands only to diagnose any reported regression. Resolve all deterministic failures and inspect skipped or blocked environment-dependent coverage before generating release integrity data.
6. Run `php scripts/generate_manifest.php`, then run `php scripts/audit.php --profile=release`. The release profile re-runs deterministic coverage and executes `php scripts/generate_manifest.php --check`, browser integration when the host can safely provide Chromium, and `git diff --check` when checkout metadata is available. Manifest freshness must be PASS; confirm its version matches `CMS_VERSION`, newly managed files and migrations are covered, and no source edit follows generation without another refresh.
7. Run the deployment helper to create the requested local folder or ZIP. The helper packages files only; it does not execute PHP or silently repair a stale manifest. Re-run the manifest check after packaging and inspect the archive with a listing tool before publishing.
8. Confirm the archive contains the current manual PDF, patch notes, release metadata, all migrations, and `app/core-manifest.json`, while excluding `config.php`, caches, logs, temporary files, local tooling, gallery media according to the chosen package policy, and deploy output. Confirm `CMS_VERSION`, the newest patch-note heading, release-metadata key/tag, manifest version, archive/release name, and intended Git tag all identify the same version.
9. Review `git status`, the staged diff, and the final archive inventory. Create the release commit and annotated `v_<version>` tag only after all checks pass; publish that exact commit and archive, then perform an updater smoke test from the previous stable version and verify migrations, Admin login, public rendering, and integrity status.

If a local dependency such as PHP, Node, or a TeX tool is unavailable, record the exact blocked command and environment in the handoff. Do not describe the release as fully verified until that command has been run successfully.

### Version 0.94.2 thumbnail-policy regression

Run `php tests/thumbnail_format_metadata_consistency_test.php`, `php tests/thumbnail_compatibility_model_test.php`, `php tests/public_thumbnail_markup_test.php`, `php tests/public_thumbnail_rendering_model_test.php`, `php tests/thumbnail_warmup_model_test.php`, and `php scripts/audit.php --profile=full` after changing thumbnail compatibility, metadata, manifest, bundle, generation, maintenance, or public rendering code. Verify that the default and explicit `modern` mode advertise only WebP derivatives, including progressive and responsive candidates, even with historical valid JPEG metadata or files present. Verify that explicit `legacy` mode continues to advertise valid JPEG and WebP derivatives, and that cleanup removes stale JPEG metadata whether or not the generated file exists. Confirm old `.jpg` requests in modern mode do not generate files or mutate settings.

### Version 0.94.1 runtime-hardening regression

Run `php tests/version_094_audit_hardening.php` and `php tests/public_media_version_routing_test.php` after changing the root/public rewrite rules, `app/early_runtime.php`, either front controller, updater activation publication, or public media URL generation. With real Apache, verify protected top-level internal trees under both a subdirectory installation and document-root installation return `403` or `404`, while later public slug components with the same words still route normally. Verify uncaught, PDO-style, missing-require, fatal-shutdown, JSON, and already-streaming fixtures with `display_errors=0`; no emergency response may expose a path, trace, SQL, or credential. Simulated activation must return private/no-store `503`, completed state must recover, corrupt state must fail closed, and all temporary marker state must be removed. Public originals must have stable version identities, unchanged payloads, and conditional `ETag`/`304` behavior. Production acceptance also requires `display_errors=0` at the hosting layer.

When checking Admin dashboard performance, verify that opening `?page=admin` does not request `admin_dashboard_maintenance` until `#admin-tab-maintenance` is selected. Then verify that the authenticated JSON response replaces the placeholder, nested maintenance tabs initialize, and direct or no-JavaScript fallback links remain usable.

### Public Lightbox Zoom Verification

Run all seven PHP zoom contracts and the Node model test listed above after changing lightbox markup, browser events,
fullscreen/mobile CSS, quality candidates, cache-busting, translations, maps, voting, or lazy metadata. The model test
covers scale bounds, reset, two-axis pan clamping, centered and fractional anchors, repeated off-center zoom through 400%,
required source pixels, density caps, malformed candidates, and no-downgrade selection. PHP contracts cover semantic
controls, reset ordering, server/lazy candidate rendering, immediate deliberate-zoom source promotion, passive 100%
quality evaluation, accessible loading feedback, stale-request cancellation, failure fallback, fullscreen/map
remeasurement, event scope, browser-modifier preservation, catalog coverage, and the existing gallery/NSFW access
boundary. Also run `node --check` on every changed JavaScript module and `php -l` on every changed PHP file.

Manual browser coverage remains required because the repository has no production browser-automation dependency:

1. In current Chromium/Edge, Firefox, and Safari/WebKit where available, open a normal gallery photo and use `+`, `−`,
   percentage reset, `+`/`=`, `-`/`_`, `0`, wheel/trackpad, drag, and pinch. Verify the limits at exactly 100% and 400%.
2. Verify the 100% photograph is centered and the photograph frame itself grows when zooming. In normal lightbox the
   enlarged frame may extend beyond the original stage instead of behaving like a fixed crop window. In fullscreen the
   browser viewport remains the clip boundary.
3. Put the pointer on a recognizable off-center detail and zoom repeatedly from 100% toward 400%. The same photograph
   point must remain under the cursor after every step, including rapid wheel input while the 120 ms transition is still
   animating. Repeat with touch pinch around an off-center midpoint. Confirm there is no cumulative top-left or
   bottom-right drift.
4. While zoomed, drag to all photograph edges. In fullscreen verify both horizontal and vertical pan are available for a
   wide image once zoom creates overflow. Confirm the close button and other fullscreen HUD controls remain clickable at
   125%, 200%, and 400%, and that photo pan does not steal their pointer events.
5. Confirm Ctrl/Command-wheel still performs browser page zoom and scrolling outside the active photo stage is not
   intercepted. At 100%, one-finger mobile swipe must retain photo navigation; above 100%, one pointer pans and two
   pointers pinch.
6. With Network and Elements tools open, use an image substantially wider than the generated preview. A sufficiently
   small 100% stage may remain on the preview, while a large/high-DPI stage may be promoted passively. Perform one
   deliberate zoom-in action and confirm a high-priority request for the protected `data-full-src` begins in that same
   input task. The request must not wait for resize, fullscreen, an animation frame, or a mode toggle. While it transfers,
   the existing preview remains usable and the translated loading pill/ring plus `aria-busy` are present. When the live
   image finishes loading the original, sharpness must improve in the current mode without changing scale, pan, or
   fullscreen state.
7. Navigate previous/next while an original request is pending, select picture-strip and 3D-carousel neighbors, and open
   a lazy non-visible item. A late result must never mutate the new photograph. Each new image starts centered at 100%,
   and zooming one photograph must not prefetch adjacent originals. Simulate a failed full-media request and confirm the
   protected preview is restored without repeated retries.
8. Toggle browser/CSS mobile fullscreen while enlarged and confirm scale is preserved, the fitted frame is recentered for
   the new viewport, and translation is safely reclamped. Start slideshow and confirm zoom resets before automatic
   navigation. Open/close fullscreen map split and confirm Leaflet, votes, help, metadata, strip/carousel items, navigation,
   and the close button remain independent controls.
9. In a physical gallery with enough GPS-tagged photographs to span multiple HTML pages, open the gallery map and choose
   **Open photo** on a marker from the current page, then on a marker beyond the current page. Both actions must open the
   correct photograph in the existing lightbox without exposing a raw-media URL. Repeat in desktop fullscreen split-map
   mode and confirm the map stays mounted while only the photograph pane changes; click two markers rapidly and confirm
   the second click does not toggle fullscreen on the underlying stage. From the ordinary map overlay, confirm the overlay
   closes before the selected photograph appears. Re-select a previously loaded off-page marker and confirm no new target
   request is needed. Delay metadata responses, select a second marker, then close the map or viewer: cancelled/older work
   must neither change the photo nor navigate away, including after reopen. Repeat after exiting fullscreen or changing
   photos with the keyboard. Fail a current lookup deliberately and confirm its canonical photo-page fallback still works.
   Copy a popup's canonical link, disable JavaScript, and open that URL directly to verify the authorized page fallback.
   Repeat as a public visitor and Admin, with password/share access where configured, and confirm no access rule is weakened.
10. After changing `lightbox.js`, reload page source and confirm `data-gallery-asset-revision` changes even if another
   dependency has a later filesystem modification time. Confirm the deferred `lightbox.js?v=...` request uses the new
   revision. Disable JavaScript and confirm the ordinary server-rendered photo/navigation fallback remains usable. Watch
   the console and memory while repeatedly opening, zooming, promoting, navigating, toggling fullscreen, and closing;
   there must be no stuck pointer capture, stale pan state, uncancelled listener, unbounded decoded-source cache, or
   zoom-caused URL/history change.
11. On a parent gallery view with no lightbox-capable photo cards, create a subgallery through the Admin side panel, then
   delete a visible subgallery card and repeat create/delete once more without reloading the browser. Each mutation must
   refresh the parent fragment in place, and the console must remain free of temporal-dead-zone errors such as
   `Cannot access 'lightboxHiddenCleanupTimer' before initialization`. This specifically exercises teardown of an earlier
   `setupGalleryLightbox()` instance that returned before mounting a viewer.

### 3. Manual Functional Smoke Tests

Map-photo navigation regression commands:

```text
php tests/seo_lightbox_target_guard_test.php
node tests/lightbox_map_navigation_test.mjs
node tests/lightbox_map_browser_test.mjs "C:\Program Files\Google\Chrome\Application\chrome.exe"
```

The PHP test executes the real request guard in isolated public/Admin requests, including rejection of unknown parameters
and rejection of `target_image_id` on an endpoint that does not support it. The Node test executes production closure
functions with controlled fetch/DOM/timer boundaries; it covers detached-cache reuse, cancellation, supersession, close/reopen,
and genuine-failure fallback. The browser runner requires an installed Chromium executable and uses a fresh profile under
`cache/` plus a loopback-only fixture server. It loads the complete production lightbox module with synthetic photographs
and controlled metadata responses. Its popup-shaped links exercise real DOM event routing and CSS fullscreen split state;
it does not validate Leaflet rendering, native fullscreen, touch gestures, or live database/media authorization. Run the
manual matrix above for those integration checks. `--baseline` on either Node runner loads the committed `HEAD` lightbox
source to reproduce a regression before committing a fix.

For feature work, use the same end-to-end scenario every time. Keep one dedicated test installation or local database so you can create and remove test content freely.

Recommended flow:

1. Log in as admin.
2. Open the dashboard and confirm it renders without errors.
3. Create a new gallery.
4. Edit gallery title, description, manual date range, visibility, tags, and ordering settings.
5. Upload 2 to 3 images.
6. Open the gallery on the public site.
7. Open an image in the lightbox.
8. Reorder images if the change touches ordering.
9. Open an existing gallery that contains photos with EXIF dates, use **Apply to this gallery**, and confirm the From/To fields plus the compact suggestion fragment update without a full page reload when JavaScript is enabled. Repeat from a side-panel editor opened while viewing that gallery, then from its parent/root gallery-card context. In both cases confirm the browser URL remains unchanged, the drawer stays mounted, the server-rendered hero/card date updates through the shared mutation coordinator, and the resulting branch range uses the en dash separator. For the enhanced endpoint, also verify expired Admin authentication returns JSON with `error_code=auth.admin_required` and an invalid CSRF token returns JSON with `error_code=security.invalid_csrf`, rather than a login redirect or plain-text response.
10. Open **Review branch suggestions** for a parent gallery and confirm the table only lists that gallery and its subgalleries.
11. Open Admin **Gallery dates** after scanning images with EXIF dates, then apply one suggestion and confirm the gallery card displays the resulting date range.
12. Rename or move the gallery if the change touches file or path logic. Confirm the public URL uses lowercase ASCII slugs, contains no encoded spaces or diacritics, and still resolves after moving the gallery under another parent.
13. Create a gallery named **Testovací fotky** with a child named **Test nahrání** and confirm the child URL is `/gallery/testovaci-fotky/test-nahrani/`.
14. Delete the test gallery and confirm cleanup succeeds.


### Centralized Admin Settings Tests

Run the focused contracts after changing the Settings hub, any registered setting owner, Admin navigation, or shared tab behavior:

```bash
php tests/admin_settings_registry_test.php
php tests/admin_settings_normalization_test.php
php tests/admin_settings_navigation_contract_test.php
php tests/admin_settings_rendering_contract_test.php
```

The registry test checks stable unique IDs/keys, known sections, ownership metadata, secret redaction and specialized routes. The normalization test locks safe thumbnail fallback, browser-upload numeric clamping, central site-name normalization and unknown-write rejection. The navigation test locks the route, Admin menu, specialized backlinks, Gallery tags deep link, stable section IDs and href-history tab mode. The rendering contract checks headings, fieldsets, labels/help wiring, tab ARIA state, hidden inactive panels, error summary and secret redaction.

Manual browser verification:

1. Load every direct section URL from `general` through `advanced` and confirm the query plus `#settings-*` fragment agree with the visible heading.
2. Change sections with pointer and keyboard. Verify arrow/Home/End tab movement, Browser Back/Forward, and refresh preserve the active section.
3. Disable JavaScript and use the section links. Each link must load the correct server-selected section and all specialized links must remain usable.
4. At mobile width, confirm the top-level tab strip remains a single horizontally scrollable row rather than a multi-line wall.
5. Submit each centrally editable group and verify only that group is posted. Confirm success notice and redirect stay on the same section.
6. Force an invalid language/renderer value with a direct POST test and verify a page-level error summary plus field error. Unrelated fields must retain submitted values, including unchecked checkboxes.
7. Verify Theme, Tags, Upload settings, Telemetry, Account and Dashboard settings still save directly when opened without visiting the central hub.
8. Verify central links to Theme Gallery tags use `appearance_subtab=admin-theme-appearance-subtab-gallery-tags#admin-theme-tab-appearance`.
9. Confirm `password_reset_smtp_password`, site-maintenance tokens, OpenAI keys and upload API keys never appear in central page source, error output or Admin logs.
10. Re-run `hero_tag_theme_model_test.php`, `tag_page_theme_model_test.php`, upload/settings tests, telemetry tests and the complete `php scripts/audit.php --profile=full` suite.

### Public Thumbnail Rendering Smoke Test

Use a gallery with enough photos to create several viewport lengths. Test with browser DevTools, an empty cache, and a simulated slow connection. Perform the checks both anonymously and while logged in.

1. Leave Admin > Theme > Layout > Public thumbnail rendering on **Progressive thumbnail sharpening - Default**. Confirm a missing/fresh setting also selects this mode. On an upgraded installation, confirm the renderer migration changes the previously persisted responsive default to progressive and bumps the public content revision.
2. In the Elements panel, confirm progressive photo cards contain server-rendered `<picture>/<img>` markup, expose only the small candidate in the active `srcset`, and keep larger bounded candidates inert in `data-progressive-srcset` before browser activation. The `src` should prefer the 300 px derivative when available.
3. In Network, reload with an empty cache. Confirm the small progressive request begins first and larger requests appear only after renderer activation for visible or near-visible cards.
4. Switch to **Responsive browser selection - Legacy**. Confirm responsive photo cards expose their complete available bounded WebP/JPEG `srcset` immediately and the browser directly requests the candidate it selects without requiring JavaScript. Then switch back to the progressive default for the remaining checks.
5. Reload with an empty cache and slow throttling. Confirm the small request begins first. Larger requests must begin only after the small image is loaded and only for visible or approximately 720 px near-visible cards. Scroll slowly and verify far-offscreen cards remain unupgraded.
6. In Network, verify no more than 2 progressive larger preload/decode jobs are active at once. Visible cards should overtake merely near-visible queued cards when both are waiting.
7. Watch one sharpening card under heavy throttling. The small image must remain visible until the replacement loads/decodes. Force a larger request to fail and confirm the small image remains functional. No fake percentage indicator should appear.
8. Resize the window and change device emulation DPR. Relevant cards may upgrade further when a larger candidate is required, but they must not downgrade, loop indefinitely, or repeatedly download an already adequate candidate.
9. Check for accidental double downloads by filtering Network to one photo basename. In progressive mode, one small transfer plus at most the needed larger replacement is expected. Repeated requests for the same larger URL after resize/reinitialization indicate a regression. Responsive mode should not perform the progressive small-then-large sequence intentionally.
10. Disable JavaScript and reload progressive mode. Confirm small thumbnails, direct photo/gallery links, alt text, layout, password/access behavior, and navigation still work. Responsive mode must remain fully functional too.
11. Confirm stable card dimensions before decode. Stored intrinsic width/height should be present when known, and the existing thumbnail background should paint the slot without shifting surrounding cards.
12. Open the lightbox, vote on an image, use photo maps where available, search/navigate normally, and exercise thumbnail warm-up fallback. These features must behave identically in both modes.
13. Verify restricted NSFW cards and inaccessible/password-protected galleries do not expose protected thumbnail/media URLs through progressive data attributes.
14. Enable `prefers-reduced-motion: reduce`. The progressive renderer introduces no required pulse/shimmer animation; the card remains static while sharpening occurs.
15. Compare perceived readiness rather than claiming total bytes are lower. Progressive mode can transfer both the small image and a larger replacement, so record transfer totals separately from first useful paint/interaction observations.

The browser/network observations above are manual verification only. The standalone PHP and Node tests do not claim coverage of real browser request scheduling or visual decode behavior.

### Gallery Hero Tag Theme Smoke Test

Also verify the adjacent public tag-page settings: use Configure tag display from Edit tags and confirm it opens Theme > Appearance > Gallery tags; set columns, rows, and card design; save; and verify the dedicated grid, pagination capacity, and card layout on a public tag page without changing ordinary gallery pages. Use Manage tag metadata to verify the reverse link. Run php tests/tag_page_theme_model_test.php.

Use a gallery with more than 20 direct and/or contained tags, including several tags with deliberately different assignment frequencies. Perform the public checks both anonymously and while logged in, because the two render pipelines use different browser entrypoints.

1. Open **Admin > Theme > Appearance > Gallery tags**. With a fresh installation or missing settings, confirm **Most used first** is selected, **Display every tag immediately** is off, the initial tag limit is 20, scrollbar support is enabled, and its row threshold is 5.
2. Move the visible-tag slider and confirm the exact number field follows it. Edit the number field and confirm the slider follows it. Repeat for the scrollbar-row slider and number field. Values must remain within 1 to 200 tags and 1 to 12 rows.
3. Enable **Display every tag immediately** and confirm the initial-limit controls are hidden because they no longer affect public disclosure. Disable it and confirm the controls return with the previous value. Disable the scrollbar and confirm its row controls are hidden; re-enable it and confirm the saved row value remains available.
4. Save the Theme page, reload it, and confirm all five values persist. No database migration should be required because the settings use `app_settings`.
5. Open the tagged public gallery at desktop width. Confirm the hero tag panel itself and each tag list use the full available hero width. Tags must continue wrapping toward the right edge rather than stopping at the normal readable paragraph width.
6. With the default 20-tag limit and more than 20 available tags, confirm only the first 20 tags are visible after JavaScript initializes and **Display all tags** appears. Click it and confirm all already-rendered tags appear immediately with no page navigation, reload, XHR, or fetch. The button must change to **Show fewer tags** and expose `aria-expanded="true"`. Collapse again and confirm the content returns to the configured limit.
7. When the collapse boundary falls before all tags in the contained-tag group, confirm a **Containing tags** label is hidden if that group has no visible tag. Expand the collection and confirm the label returns with its tags.
8. Switch to **Alphabetical**, save, and verify each semantic group is alphabetically ordered. Switch back to **Most used first** and verify tags with more direct gallery plus photo assignments appear first within their own group; equal counts should be alphabetical. Direct gallery and contained groups must not be merged together.
9. Resize the browser until tags wrap across different numbers of lines. With the scrollbar enabled and its threshold set low enough to trigger, confirm scrolling appears only after the measured visual row count exceeds the configured threshold. Resize wider so the rows fall below the threshold and confirm the internal scrollbar disappears automatically. Disable the scrollbar setting and confirm the hero grows naturally at all widths.
10. Disable JavaScript and reload the gallery. Confirm every tag is visible, usable and server-rendered, and the progressive disclosure control stays hidden. Re-enable JavaScript and repeat anonymously plus in the logged-in public view.
11. Run `php tests/hero_tag_theme_model_test.php`, JavaScript syntax checks for `hero-tags.js`, `theme-form.js`, both public entrypoints, and then the full `php scripts/audit.php --profile=full` suite.

### Duplicate Photo Detector Smoke Test

1. Apply pending migrations, including `202608080001_duplicate_photo_ledger.php`, then log in as an administrator and open a gallery containing prepared duplicate photos across the selected gallery and one or more nested subgalleries.
2. Open **Find duplicate photos** from the gallery Images section and confirm it uses the existing right-side Admin panel rather than a second modal or standalone route.
3. Confirm **Search all galleries** is unchecked on a fresh detector view. Run local and explicit global scans and verify the scope labels and bounded AJAX progress while the panel remains open.
4. Verify exact SHA-256 and normalized-EXIF possible matches still behave as specified, including different file sizes for valid EXIF candidates and rejection of size-only matches.
5. Confirm completed findings are rendered as deterministic left/right pairs. Verify each side shows image id, filename, file size, dimensions/MIME where stored, EXIF/camera/lens context, and matching signals.
6. Click each gallery title/path and verify it opens the correct public gallery in a new tab. Click each preview, filename, and gallery-relative path and verify it opens the correct public photo context in a new tab. The Admin page and detector panel must remain unchanged.
7. Click **Ignore this pair from now on** on one finding. Verify the action completes through AJAX with no reload/navigation, the right-side panel remains open, the pair disappears immediately, and the ledger count increases. In the JSON response verify `mutation.type=duplicate_photo_ledger.ignore_pair`, `panel.workflow=duplicate-photo-detector`, and an empty `contexts` array because ledger state does not alter the public gallery render.
8. Start a new duplicate search with the same administrator and verify the ignored pair is not shown again while other relationships from the same source group remain eligible.
9. On a left/right pair from different galleries, click **Ignore all from this gallery** on only one side. Verify all currently displayed/future pairs involving that exact gallery are suppressed. Verify a parent or child gallery with a different gallery id is not suppressed automatically.
10. Repeat the exact-gallery action from the opposite side of another pair to confirm left/right controls are independent and the server derives the stored gallery from the submitted result image rather than a browser-provided gallery id.
11. Use **Clear ledger**. Verify it runs through AJAX, the panel stays open, counts return to zero, and a new search can show previously ignored pair/gallery findings again. Verify the successful response uses the canonical `duplicate_photo_ledger.clear` mutation envelope.
12. Confirm ledger decisions are per administrator by testing with a second administrator account when available. One account's ignored pairs/galleries must not suppress another account's results.
13. On a disposable duplicate, press **Delete this** once. Confirm there is no confirmation dialog, no reload/navigation, the browser URL stays unchanged, the panel remains open, and refreshed pair counts/results reflect the deletion.
14. Confirm deletion reuses the existing gallery image mutation semantics for original files, image rows, derivatives, cover references, path safety, and Admin logging. Repeat for a nested subgallery and global-search result.
15. Confirm forged/stale pair IDs, image IDs, moved images outside an immutable local scope, and missing/expired detector jobs are rejected server-side. For AJAX requests, also test an invalid CSRF token and an expired/non-admin session: both must return JSON error envelopes rather than plain text or a login-page redirect.
16. Disable JavaScript or use the detector route directly and verify normal POST/redirect forms still work as fallback for scan continuation, ledger actions, and explicit deletion. This fallback is not the expected JavaScript interaction path.
17. Run `php tests/duplicate_photo_detector_test.php`, `php tests/duplicate_photo_ledger_test.php`, `php tests/migration_consistency_test.php`, and the full `php scripts/audit.php --profile=full` suite.


## What To Retest After A Change

### Low-Risk Changes
For CSS, text, layout polish, or small UI tweaks, test the affected page and one nearby page.

### Medium-Risk Changes
For controllers, forms, admin tools, or display logic, retest the touched feature plus the main gallery browse flow.

### High-Risk Changes
For routing, permissions, uploads, deletes, renames, migrations, public media serving, or feature flags, retest:

- admin login
- gallery create/edit/delete
- photo upload and public rendering
- lightbox and navigation
- any route or tool the change can disable
- schema migration or install flow, if database code changed

## Practical Rule
Ask these questions before and after the change:

- Can an admin still manage galleries?
- Can visitors still browse public galleries?
- Can media still upload, render, and delete?
- Did I touch a route, permission check, or database schema?
- If an action starts in the Admin right-side panel, does the JavaScript path keep the panel open and avoid page navigation/reload?

If the answer is yes to any of those, run the manual smoke test in addition to syntax checks.


### Admin side-panel mutation pipeline

For any change to a persistent Admin side-panel mutation, its server response, batching layer, or dynamic form wiring, run the repository contract check first:

```bash
php scripts/check_admin_mutation_contracts.php
```

In a source checkout that contains the tracked regression tree, run `php scripts/audit.php --profile=full`. Invoke focused mutation-related PHP/Node tests separately only when diagnosing an audit failure or reproducing a specific mutation regression. Deployment ZIPs intentionally exclude `tests/`, so absence of that directory in a deployment artifact is not a passing test result and must be reported as an unavailable source-tree validation step. Run `php -l` on every changed PHP file and `node --check` on every changed JavaScript file.

The mutation contract check protects strict canonical-envelope consumption, stable ID survival through classic upload aggregation, Metadata Organizer envelope preservation, dynamic form interception, centralized retry ownership, the known bulk visibility/NSFW JSON return boundary, exact effective-visibility postconditions for gallery Published/Unpublished transitions, direct-card-first gallery membership verification, current-origin posting of server-rendered side-panel mutation forms, clean JSON authentication/CSRF/delete response boundaries, and the absence of hard reload/history rewriting in enhanced side-panel completion. It intentionally permits direct-page navigation fallbacks outside the mounted-panel success path.

For manual browser qualification, exercise the highest-risk applicable operations: child gallery create; title edit; gallery Published -> Unpublished -> Published transitions; slug/folder rename; reparent; delete; upload to existing gallery; create-with-upload; image delete/move/visibility/NSFW/cover; scan/import; Media Renamer; Metadata Organizer; Duplicate Detector delete; image reorder/edit; tag slug edit; and Smart Gallery placement. For every applicable case verify the persisted server state, panel remains mounted/open, browser URL stays byte-for-byte unchanged, no full-page navigation/reload occurs, the current public context reaches the declared postcondition, replacement controls work on the next mutation, back/forward remains sane after closing the panel, and the direct-page/non-JavaScript fallback still redirects correctly. For gallery visibility transitions specifically, the declared postcondition must be `gallery_visibility` with the newly persisted effective visibility rather than a timestamp-only proxy.

For the parent-gallery card workflow, explicitly test creating a child while viewing its parent and confirm the new server-rendered card appears without reload. Repeat on an unpaginated parent and on a paginated parent where the new child may sort onto another page; direct card presence must win over an auxiliary count mismatch, while off-page completion must still require the full context count to converge. Then delete a visible child through its card trash button and confirm the request returns `application/json`, the card disappears without reload, and the browser URL is unchanged. Repeat with an expired Admin session and an invalid CSRF token: both must return JSON with `error_code=auth.admin_required` or `error_code=security.invalid_csrf`, never an HTML login page or plain text. On a disposable installation with `display_errors=1`, inject a temporary warning inside the gallery-delete mutation path and confirm the browser still receives valid JSON while `gallery.public_json_output_discarded` or the PHP error log records the discarded diagnostic. Also test the same installation through any supported host alias/scheme used in development or hosting and confirm server-rendered form fetches retain the active session by posting to the current origin.

### Resumable application-update verification

Do not run destructive update tests against a real installation. Automated updater tests must use temporary directories, fake job state, corrupt synthetic archives, or static wiring assertions unless a disposable filesystem and database are explicitly configured.

For a disposable shared-hosting test instance, verify these interruption points separately:

1. Start a stable update and close the browser during download, extraction, manifest validation, file staging, and backup. Reopen **Updates** and confirm the same job id resumes from its persisted cursor.
2. Kill a worker after a bounded request returns and confirm completed stages do not repeat. Create a synthetic archive with more than 500 entries and confirm `archive_validate_index` advances in multiple requests. For a package-stage failure, confirm Retry discards untrusted archive/extract artifacts, Range validators, and source URL state before restarting download.
3. Open the update page in two browser sessions and confirm only one worker advances the job. Address an older failed/running job id directly while another job owns `active-job.json` and confirm the old job cannot execute, retry, cancel, or clear the active owner. Also leave stale text in `worker.lock` without holding the OS lock and confirm the next worker proceeds.
4. Corrupt or truncate a ZIP, add a traversal entry, add a symbolic link, add a file above 32 MiB, exceed the expanded-size cap, remove a required runtime file, modify a manifest-covered file without updating its hash, and add an installable managed file without adding it to the manifest. Each case must fail before `activate`. Confirm deterministic manifest/hash/version mismatches hide retry, retain cancel, and explain that a newer build is required. For a resumed stable download, change the branch snapshot between slices and confirm `If-Range` causes a clean restart rather than an append across two snapshots.
5. Confirm byte-identical release files are excluded from `activation_files`, then confirm `ready/` and `rollback/original/` are complete before the first active-file replacement. Also confirm a managed symbolic-link destination and an oversized (>128 MiB) active rollback file fail before activation. A pre-activation failure must leave the active tree byte-for-byte unchanged.
6. Interrupt activation only on a disposable installation. The next worker must recognize already matching prepared hashes and finish the remaining files. This test documents the unavoidable mixed-tree window on hosts without atomic release-directory switching.
7. Add a disposable migration whose callback is intentionally interrupted once, then rerun it. Verify the migration definition is safe to replay and that a recorded `schema_migrations` version never runs again.
8. Before activation, use **Cancel prepared update** and confirm the active job is released and application files remain byte-for-byte unchanged. After a completed or failed post-activation update, run **Rollback application files**. Confirm the pre-update file snapshot is restored through a new resumable job and that database migrations are not reversed. Confirm cancellation is rejected once activation has begun.
9. Initiate the update from the Admin side panel. Progress must refresh in place, dynamic forms must remain intercepted, and no normal success path may call `window.location.reload()` or assign `window.location.href`. Repeat with JavaScript disabled and use the normal Continue/Retry POST fallback.
   Start a beta install from **Advanced tools** and verify the synchronized job card and progress bar remain visible in both **Advanced tools** and **Status** throughout the job.
10. Close the browser with a running Admin job, reopen the update UI, and confirm continuation starts from durable server state. For unattended stable updates on an idle site, run `php scripts/application_update.php --time-budget=8` repeatedly or from cron and confirm it advances only `trigger=background` jobs. When no job exists, confirm one invocation performs bounded metadata discovery and only creates the job; package work starts on the next invocation.
11. Run with low `max_execution_time` where possible. Confirm the reported worker budget stays below the PHP limit reserve and remote metadata discovery stops at its own request budget. Do not treat successful `set_time_limit()` calls as evidence of safety; the updater must remain correct when that function is disabled or ignored by hosting.
12. Inspect Admin JSON/log output from induced transport, ZIP, migration, and filesystem errors. Only generic text plus a short reference fingerprint may be exposed. Paths, URLs containing tokens, raw SQL, credentials, stack traces, and exception messages must not appear.

For side-panel work, full-page POST/redirect behavior is a fallback test, not the expected JavaScript behavior. Test the in-panel path first. A panel action should update its fragment or affected page elements in place. If the feature is specified as one-click, also verify that no unrequested `window.confirm()` or other intermediate prompt was introduced.
For persistent side-panel mutations such as ignore/review ledgers, verify every action through the JavaScript path first: the request must ask for JSON, the browser URL must not change, the panel shell must stay open, only owned fragments/page elements may refresh, and controls rendered by the replacement fragment must still be intercepted by delegated handlers.

## Recommended Habit
Keep a short test note for each significant change:

- what changed
- what you tested
- what you did not test
- any warning signs or follow-up work

That makes regressions easier to track and helps future changes focus on the highest-risk paths first.

### Duplicate Photo Detector side-panel deletion

1. Open a gallery while authenticated as an administrator and launch **Find duplicate photos** from the existing right-side Admin panel.
2. Complete a scan that contains at least one duplicate group.
3. Click **Delete this** once and verify no browser confirmation dialog appears.
4. Verify the delete request starts immediately through AJAX, the browser URL does not navigate to the standalone Admin Duplicate Photo Detector page, no full-page reload occurs, and the right-side panel remains open.
5. Verify only the detector fragment refreshes in place, the deleted photo disappears immediately, and a group with only one surviving member is removed.
6. Repeat from a result belonging to a nested subgallery and, separately, from **Search all galleries** scope.
7. Refresh the underlying gallery afterward and verify the deleted image remains deleted and no unrelated image was removed.
### Smart Galleries

Run the focused Smart Gallery regressions first:

```bash
php tests/smart_gallery_rules_test.php
php tests/smart_gallery_cycle_placement_test.php
php tests/smart_gallery_presentation_test.php
php tests/smart_gallery_public_contract_test.php
```

`smart_gallery_presentation_test.php` exercises Theme/site inheritance, malformed JSON, unknown presentation versions, explicit booleans, invalid grid/renderer/lightbox values, Admin preview precedence, normalized thumbnail ranges, and physical-gallery thumbnail guardrails. `smart_gallery_public_contract_test.php` protects the shared public membership predicate, stable ordering, bounded page/lightbox windows, route policy, side-panel enhancement, preview renderer reuse, download authorization structure, and normal-gallery slideshow default.

Then run `php scripts/audit.php --profile=full`. Manually create a tag-plus-rating collection, preview and publish it, verify multiple pages, then move one image between three and five stars and confirm membership changes immediately. Test nested logic, deleted references, query-string and clean URLs, search conversion, and inaccessible matching images. Logged-out counts, covers, page rows, lazy lightbox metadata, and downloads must exclude private, locked, unpublished, share-only-without-valid-access, and otherwise inaccessible source galleries.

Test all placement modes: unlisted must remain absent from listings; root must participate in homepage pagination; gallery mode must render independently around the selected physical parent's normal content. For one parent attach at least two Smart Galleries above and two below, give them differing and equal order values, and verify the sequence is `top -> normal subgalleries/photos -> bottom` with Smart Gallery ID as the equal-order tie breaker. Attach one Smart Gallery beneath multiple physical galleries, change placement/order in only one parent, remove it from another, and verify every other assignment remains intact. Disabled or private Smart Galleries must remain absent regardless of placement and must not leave an empty attachment panel.

For presentation, first leave **Override Theme defaults** disabled and change the global grid, thumbnail renderer, and lightbox mode. Verify the Smart Gallery follows those changes. Enable its override and test columns, rows, pagination, thumbnail min/max, responsive/progressive rendering, gallery-card layout, metadata, lightbox mode, slideshow, voting, and download. Save, reload, and confirm persistence. Corrupt or remove `presentation_json` in a test database and confirm the page safely inherits defaults. Verify conflicting Smart Gallery thumbnail bounds never bypass stricter physical gallery/image bounds.

For lightbox, use a Smart Gallery whose result set spans at least three HTML pages. Open the last item on page 1 and navigate forward, then open the first item on page 2 and navigate backward. Continue across another boundary. Verify keyboard arrows, swipe, fullscreen, zoom, close/reopen, optional slideshow, and the existing stale-request behavior. In Network tools, each `smart_gallery_lightbox_data` request must be bounded to at most 80 metadata rows and opening the page must not download all originals or all result metadata.

For cycle safety, create a Smart Gallery whose positive gallery rule selects physical Gallery A and attempt to attach it to A; the server must reject the relationship. Repeat with a multi-step path through descendants and another attached Smart Gallery. Attempt a physical-gallery hierarchy move and a complete drag-and-drop tree change that would introduce a loop; rejection must occur before the first filesystem move. Also exercise `sync_gallery_parent_ids()` and public-path parent repair with a filesystem-derived parent map that would introduce a Smart Gallery cycle; both must refuse before the first `parent_id` update. For a valid multi-gallery drag-and-drop whose temporary move order would create an intermediate invalid shape, verify the preflighted final map succeeds and graph-dependent reads after each committed hierarchy mutation do not reuse stale cached parents. Seed a legacy cyclic junction/rule combination in a disposable database and verify public placement skips it, Admin shows a repair diagnostic, detach remains available, unrelated Smart Galleries still work, and no request grows without bound.

For the Admin drawer, open create/edit from the Smart Gallery list and verify the right-side panel stays mounted, the browser URL does not change, Preview shows real matching cards, Save/Preview/duplicate/update-placement/remove-placement actions update the drawer in place, and rule/presentation controls still work after repeated submissions. In the physical gallery editor, save top/bottom/order attachment changes inside the existing gallery-edit panel and verify that panel also stays mounted. Repeat both workflows with JavaScript disabled and verify normal form POST/redirect behavior remains functional. Also test a local host alias/port that differs from configured `base_url` to ensure panel saves keep the authenticated session.

For downloads, enable the global downloads feature and the Smart Gallery download override, verify the ZIP contains only currently authorized matching originals, and confirm disabling either setting removes/refuses the action. Verify the independent 5,000-image guard and lower `smart_gallery_zip_max_source_bytes` temporarily in a test config to confirm cumulative original bytes are rejected before ZIP creation. Start two requests for the same Smart Gallery/signature and verify only one builder owns the final path, the waiting request reuses the completed cache after acquiring the lock, no `.partial-*` file remains after success/failure, and the final ZIP appears only through the atomic rename. Force a ZIP failure and verify the persistent log contains only the Smart Gallery ID, exception class, and stable reason code, never a raw exception message or filesystem/database detail.

### Public language preference

```powershell
php tests/public_language_preference_test.php
php tests/translation_catalog_consistency_test.php
```

These focused scripts verify that only complete maintained languages appear, each selector language maps to an existing bundled SVG flag, and the viewer selector defaults to enabled with all four languages. They also cover ordered subset normalization, rejection of an empty selection, disabled-language query rejection, complete feature disabling, query/cookie/session precedence, reset behavior, same-page links, shared Theme/Settings panel registration, and catalog key/placeholder parity.

Manual Admin regression:

1. Open Theme > Language and confirm the viewer-language panel appears beside the Admin and public default selectors with all four languages selected on an installation without explicit settings.
2. Open Settings > General and confirm the same panel structure and current values appear. Both the panel and Settings search descriptions must explicitly say that the selector is only for public viewers, that each personal choice is saved in that viewer's browser, and that it does not change the site default, Admin language, or another viewer. Search for “viewer language selector” and “viewer languages”; each result must open General and focus its owned control.
3. Disable Swedish, save, and confirm the Swedish flag disappears from public headers while Swedish remains available for Admin language, public site default, and pack editing.
4. Disable the viewer feature, save, and confirm public headers render no language buttons and `?lang=de` plus an existing public-language cookie no longer override the site-wide public language.
5. Re-enable the feature from the other Admin surface and confirm the saved language subset returns. Dynamically revisit both pages and verify their values remain synchronized.
6. Uncheck every viewer language and submit. Confirm the page shows a validation error, persists no partial selector change, and retains the rejected checkbox state for correction.
7. Confirm Settings > General shows only preset and flag design controls plus the Theme > Language detailed-settings link. Save a basic change and verify existing custom colors and dimensions remain intact. In Theme > Language, exercise Classic, Solid pills, Outline, Soft cards, and Minimal in the preview above the compact one-line controls. Change every color/range/select/toggle class of control, including each color's Transparent switch, and confirm the actual flags, code/name visibility, spacing, borders, active state, and responsive orientation update without saving or navigation.
8. Use an individual Reset and confirm only that value changes; use Reset this preset and confirm global choices plus other presets remain untouched; use Reset all and confirm Classic plus every canonical design default returns while selector enabled state and enabled languages remain untouched. Submit only afterward and reload both Settings surfaces to confirm synchronization.

## Pre-Phase-3 Viewer Feature Wrapper

Run the focused master feature regression:

```powershell
php tests/viewer_feature_wrapper_test.php
```

The wrapper test verifies that the canonical `viewer_accounts` Admin feature defaults to disabled while all established feature switches retain their historical enabled defaults. It proves that historical `config.php` or `viewer_accounts_admin_mode=invite_only` state cannot bypass the master switch, every current `viewer_*` dispatcher route and the historical Admin viewer-management route belong to the wrapper, the Admin Viewer accounts navigation item is feature-owned, public disabled viewer routes use a generic not-found result, production load order establishes feature flags before viewer services, and every feature reference used by the current Admin menu/route map resolves to a registered switch.

Manual regression:

1. On an installation with no persisted `feature_flag.viewer_accounts.enabled`, open **Admin > Features** and confirm **Viewer accounts and collections** is present under the account/personalization group and is OFF while the established feature cards retain their previous defaults.
2. With the master switch OFF, confirm public viewer Login/Account controls, favourite/collection UI, and the Admin **Viewer accounts** menu entry are absent. Direct viewer URLs must fail closed and must not advertise that the viewer subsystem exists. Existing viewer rows and content remain stored.
3. Turn the master switch ON. Confirm the Admin **Viewer accounts** menu entry appears. The subordinate Viewer Accounts registration mode remains independently configurable as **Disabled**, **Invite only**, or **Open registration** once the master switch is ON.
4. Keep the master ON but turn the subordinate viewer frontend mode OFF. Confirm Admin account creation/deletion and Phase 2.5 security controls remain available, while public viewer login remains unavailable.
5. Re-enable the subordinate mode and confirm an existing active viewer can authenticate normally. Turn the master OFF again and confirm the same credentials and any existing viewer session no longer establish `current_viewer()` authority, while Admin authentication and public gallery browsing remain unchanged.
6. Review **Admin > Features** after the registry audit and confirm the existing optional switches remain present. Core gallery/admin workflows such as ordinary galleries, Smart Galleries, tags, standard uploads, authentication, updates, integrity, and logs remain core behavior rather than being accidentally wrapped as optional features.

## Phase 1.0 Invite-only Viewer Account Boundary

Run the focused trust-boundary regression:

```powershell
php tests/viewer_http_phase10_test.php
```

It verifies the Phase 1.0 viewer/Admin account route surface, absence of open signup/collections routes, isolation of later favourite mutation logic from the Phase 1.0 account controller, Admin-only invitation mutations with Admin CSRF, scanner-safe invitation/verification/reset GET handling, viewer/pre-auth CSRF, mail-abuse authorization before verification transport, login delegation and pre-hash rate limiting, viewer/Admin session separation, POST-only viewer logout, dedicated remember-cookie rotation without recent reauthentication, no-store plus no-referrer classification, secret-bearing viewer routes bypassing the generic SEO query logger, suppression of viewer bearer URLs from the Admin-login return parameter, feature-switch gating, and unchanged gallery-authorization boundaries.

The historical Phase 0 through 0.7 tests remain active. Their route-free assertions now protect the original service-layer separation rather than incorrectly requiring the whole application to remain viewer-HTTP-free after Phase 1.0. `translation_catalog_consistency_test.php` also requires all new literal viewer UI keys to remain aligned across English, Czech, German, and Swedish.

Manual Phase 1.0 regression on an HTTPS test installation should cover this sequence:

1. Keep the master **Viewer accounts and collections** feature disabled in **Admin > Features**. Verify public galleries and Admin login behave normally, no public viewer Login entry is shown, and no Viewer accounts entry appears in the Admin Account menu. A direct public viewer route should return the ordinary not-found surface.
2. Enable the master feature in **Admin > Features**. Open **Admin > Account > Viewer accounts**, select **Invite only** with the subordinate Admin registration-mode selector, create an invitation, copy the show-once link, and verify no account row is created yet. Confirm this works even when the fallback `config.php` viewer block remains disabled.
3. Open the invitation link with GET and verify repeated/scanner-style GET requests do not consume it. Submit an incorrect bound email and confirm the public result remains generic. Submit the correct email and confirm a verification message is sent only through configured mail transport.
4. Open the verification URL repeatedly with GET and confirm no durable account exists. POST Continue, then choose a password of at least 15 characters and activate once. Replaying the original verification URL must not create a second account.
5. Log in as the viewer and, in the same browser session if desired, separately log in as Admin. Confirm `current_user()`/Admin access remains independent from the viewer account and viewer login does not unlock any password/private gallery.
6. Log out from the viewer account and confirm the Admin session remains alive. A GET to the logout route must not perform logout.
7. Log in with Remember me, allow the ordinary viewer PHP session to disappear, and confirm the dedicated viewer remember credential restores only viewer identity, rotates, and does not satisfy recent reauthentication.
8. Request forgotten-password mail for one known and one unknown email and confirm the browser-visible responses are equivalent. Repeated reset-link GETs must be non-consuming; final POST reset must invalidate old viewer sessions/remember authority without affecting Admin identity.
9. Inspect response headers for login, invitation, verification, reset, and account pages. They must be private/no-store. Public anonymous galleries must retain their existing cache/access behavior.
10. Re-disable viewer accounts and verify the viewer UI disappears/fails closed while ordinary galleries and Admin authentication continue normally.

Optional real MySQL/MariaDB concurrency coverage remains `tests/viewer_phase07_mysql_concurrency_test.php`. It is executed only when `pdo_mysql` plus `GALLERY_TEST_MYSQL_DSN`, `GALLERY_TEST_MYSQL_USER`, and `GALLERY_TEST_MYSQL_PASSWORD` are actually available. A skip must be reported as a skip, never as a pass.

## Phase 1.1 Viewer Favourites

Run the focused favourites boundary regression:

```powershell
php tests/viewer_favourites_phase11_test.php
```

It verifies the existing Phase 0 `viewer_favourites` table is reused, the mutation route is POST-only and viewer-CSRF-protected, no Admin principal/session state is used, every write delegates to `viewer_source_image_can_reference()`, owner/security-version checks and quota admission occur under the viewer-account row lock, and the controller contains no favourite SQL. It also requires the private list to re-check `viewer_source_image_can_render_reference()` before metadata rendering, optional favourite-state decoration to fail closed on viewer-storage errors, normal/Smart Gallery authorization code to remain viewer-independent, lightbox state to remain server-provided, no secret-bearing gallery return URL to be relayed through the mutation form, and keeps collection/share/profile/upload responsibilities out of the Phase 1.1 favourites service/controller itself.

Manual Phase 1.1 regression on an HTTPS test installation should cover:

1. With viewer accounts disabled, verify anonymous/public galleries, protected galleries, Smart Galleries, Admin login, and existing share links behave exactly as before and no favourite control appears.
2. Sign in as an active viewer and open a public physical gallery. Add/remove a favourite from a card, reload, and confirm the state persists. Repeat in lightbox and confirm card/lightbox state stays synchronized.
3. Repeat on an authorized Smart Gallery item. Confirm the control refers to the canonical source image and remains synchronized when lazy lightbox navigation crosses the initial page/window.
4. Open the private Favourites page from Account. Confirm only currently authorized source photos render, inaccessible saved references disclose no image/gallery metadata, and removing a visible favourite works with and without JavaScript.
5. Verify a viewer cannot add a non-public, unauthorized password/private, expired-share, or NSFW-restricted source image by POSTing its numeric image id directly. Existing Admin access in the same browser must not make that write succeed unless the non-Admin source authorization independently succeeds.
6. Verify the configured `max_viewer_favourites_per_account` limit blocks the next add without deleting/changing existing favourites. Repeated add/remove requests remain idempotent and no duplicate row can exist.
7. Hold both Admin and viewer principals in one browser. Confirm favourite controls do not overlap Admin reorder/Picture Manager controls, viewer mutations never write `user_id`, and Admin authorization remains unchanged.
8. Inspect viewer gallery/account/favourites responses while signed in and confirm personalized responses remain private/no-store. Anonymous public gallery output must contain no viewer email or favourite state and must remain operational if optional viewer favourite storage is unavailable.
9. Inspect Phase 1.1 favourites code to confirm it does not implement collection CRUD/share, public viewer profiles, uploads, comments, open signup, CAPTCHA, OIDC, TOTP, passkeys, or magic-link authentication.

The optional real MySQL/MariaDB concurrency harness remains `tests/viewer_phase07_mysql_concurrency_test.php`. It does not yet contain a dedicated favourite-quota race scenario; if `pdo_mysql` or the configured test DSN is unavailable, report that exact skip rather than claiming live concurrency coverage.

## Phase 1.2 Viewer Account Lifecycle HTTP Wiring

Run the focused lifecycle boundary regression:

```powershell
php tests/viewer_account_lifecycle_phase12_test.php
```

It loads the real Phase 1.2 controller, directly exercises the bounded recent-reauthentication destination parser, audits every imported project function against a real source definition, and verifies the exact lifecycle route surface. Static contract checks then protect viewer CSRF, GET/POST boundaries, no-store classification, password-backed recent reauthentication, remember-me exclusion, password-policy/service delegation, staged/budget-authorized email mail, scanner-safe verification GET, tokenless single-use final email POST, explicit destructive confirmation, service-owned deletion, Admin/viewer principal separation, fail-closed feature/schema behavior, translation alignment, absence of a new migration, and keeps later private-collection/open-signup/public-profile/upload/optional-auth responsibilities out of the Phase 1.2 lifecycle controller.

Manual Phase 1.2 regression on an HTTPS test installation should cover:

1. Sign in as a viewer with Remember me, expire/remove only the ordinary viewer PHP authentication state so remember restoration occurs, then open Change password. Confirm the sensitive action requires the current viewer password and a wrong password fails generically without establishing Admin identity.
2. With a normal recently password-authenticated viewer, change the password. Confirm the old password no longer authenticates, the new password does, viewer sessions/remember credentials follow the Phase 0.7 invalidation contract, favourites remain owned by the same account, and a simultaneous Admin login survives.
3. Start Change email and submit malformed, unchanged, already-used, and valid new addresses. Confirm public errors remain generic where appropriate and the current account email never changes at request time. Confirm mail is attempted only after the existing email-change mail budget authorizes/stages the request.
4. Open the valid email-change verification URL repeatedly with GET. Confirm GET never changes the durable account email. Complete any required recent reauthentication and use the tokenless CSRF-protected confirmation POST exactly once. Replay/stale/superseded/expired confirmation must fail. Confirm login moves from the old verified email to the new one according to service semantics and favourites remain attached to the same viewer id.
5. Hold Admin and viewer principals simultaneously. Repeat password and email changes and confirm `$_SESSION['user_id']`, Admin persistent login, protected-gallery authorization, gallery share grants, and Smart Gallery behavior are unchanged.
6. Open Delete account with GET and confirm no deletion occurs. After recent viewer reauthentication, submit without the explicit destructive confirmation and verify rejection. Then confirm deletion and verify viewer login/sessions/remember/reset/email-change authority and favourites are gone according to the existing lifecycle/cascade design, while the simultaneous Admin principal remains signed in and galleries/images/share links are untouched.
7. Inspect Account, reauthentication, password, email, verification/confirmation, and deletion responses. They must remain private/no-store; token/security pages must retain no-referrer/noindex behavior. Forms must contain only bounded lifecycle destinations, never arbitrary return URLs.
8. Disable viewer accounts and confirm every lifecycle route fails closed and account controls disappear with the viewer account UI. Break/withhold optional viewer lifecycle schema in a test environment and confirm unrelated anonymous public gallery browsing remains operational.
9. Confirm the Phase 1.2 lifecycle controller remains limited to lifecycle actions and does not implement collection CRUD/sharing, public profiles, comments, uploads, open signup, CAPTCHA, OIDC, TOTP, passkeys, or magic-link authentication.

The existing Phase 0.7 service/concurrency tests remain the source of truth for atomic security-version, session/remember invalidation, email replay/race, and account-deletion storage semantics. Run `tests/viewer_phase07_mysql_concurrency_test.php` when `pdo_mysql` and the configured MySQL/MariaDB DSN are actually available; otherwise report the exact `SKIP` reason.

## Phase 2.0 Private Viewer Collections

Run the focused collection boundary regression:

```powershell
php tests/viewer_collections_phase20_test.php
```

It verifies reuse of the existing Phase 0 collection schema, strict current-viewer ownership predicates, viewer CSRF and POST-only mutations, bounded integer IDs, centralized plain-text title validation/escaping, account/collection row locking around quotas, the dedicated collection-creation rate limit, duplicate-safe image insertion, transactional reorder validation, and reference-only delete/remove behavior. It also requires collection detail to batch re-evaluate every stored image through the no-admin-bypass source authorization path, checks dual Admin+viewer coexistence, confirms anonymous public gallery HTML performs no collection lookup, audits imported PHP functions against real definitions, and proves the Phase 2 collection service remains independent from the dedicated Phase 3 sharing authority while public profiles/uploads/optional auth remain absent.

Manual Phase 2.0 regression on an HTTPS test installation should cover:

1. Enable invite-only viewer accounts, sign in as Viewer A, create collections with ordinary Unicode titles and XSS-looking plain text, then verify titles render inertly. Empty, control-character, malformed-UTF-8, and over-120-character titles must be rejected.
2. Create Viewer B. Guess Viewer A collection ids from B and test GET detail plus rename/delete/add/remove/reorder POSTs. Every operation must fail generically without disclosing title, count, owner, timestamps, or item ids.
3. Add an authorized public image, repeat the add, and confirm one row/reference only. Confirm favourites are unchanged. Try a nonexistent/inaccessible image id and confirm no reference is inserted and no source metadata is returned.
4. Add a photo while its source gallery is public, then make that gallery private/password protected or expire/revoke the browser's existing source grant. Reload the collection and confirm the item disappears without title/path/thumbnail/EXIF leakage. Restore the normal source authorization and confirm the still-stored reference becomes visible again.
5. Repeat with a password-unlocked or valid gallery-share-granted source. Confirm only the canonical image id is stored and expiration/revocation of that independent source authority immediately affects collection rendering.
6. Hold Admin and viewer principals in one browser. Open an Admin-visible protected image that the viewer context cannot independently access. Confirm collection Add is absent/rejected and an already-stored item remains hidden. Viewer collection mutations and logout must not modify Admin session/authentication.
7. Fill a collection to `max_viewer_items_per_collection` and an account to `max_viewer_collections_per_account`; the next insert/create must fail without changing existing rows. Confirm the collection-creation rate bucket is enforced independently.
8. Reorder visible items repeatedly. Submit duplicate ids, an id from another collection, malformed ids, and an array above the item quota; each invalid request must leave the previous order unchanged. With temporarily inaccessible items present, confirm their hidden references keep stable ordinal slots while visible items reorder around them.
9. Remove an item and delete a collection. Confirm only collection references disappear; source images, galleries, Smart Galleries, favourites, gallery share links, and Admin authentication remain unchanged. Account password/email changes keep collection ownership, while viewer account deletion follows the existing FK lifecycle.
10. Inspect private collection list/detail/mutation responses for `private, no-store`. Disable viewer accounts and confirm collection UI/routes fail closed while ordinary anonymous galleries still render. Simulate unavailable collection schema and confirm unrelated anonymous browsing remains independent.
11. Inspect the complete route/UI surface and confirm there is no collection share/copy-link action, anonymous collection URL, public viewer profile, upload, comment, TOTP, OIDC, or passkey feature.

Optional real MySQL/MariaDB execution should exercise duplicate-add races, collection/item quota races, reorder consistency, and FK cascade behavior when `pdo_mysql`, `GALLERY_TEST_MYSQL_DSN`, `GALLERY_TEST_MYSQL_USER`, and `GALLERY_TEST_MYSQL_PASSWORD` are available. If they are unavailable, report the exact missing driver/configuration reason; do not report skipped live-DB coverage as passed.

## Pre-Phase 3 Administrator Viewer Account Provisioning

Run the focused account-management regression:

```powershell
php tests/viewer_admin_account_management_test.php
```

It directly loads the new Admin provisioning service and the real viewer account controller, verifies the migration and service-loader order, and protects the direct-create/list/delete boundary. The test requires account-cap locking, normal viewer password hashing, `must_change_password=1` on direct creation, no Admin-principal reuse, no plaintext password persistence/emailing, show-once Admin disclosure, viewer CSRF on the forced first-login POST, no-store behavior, and viewer-only deletion scope. It also verifies `current_viewer()`, normal session establishment, viewer content mutation, and remember-token issue/restore all reject the temporary-password state; successful replacement must clear the flag, increment the security version, revoke old viewer authority, reject temporary-password reuse, and only then establish normal viewer authentication.

Manual regression on an HTTPS test installation should cover:

1. Enable the master viewer feature in **Admin > Features**, then open **Admin > Account > Viewer accounts** while the subordinate viewer frontend mode is disabled. Create a viewer with the temporary-password field blank. Confirm the account is created despite the disabled frontend mode, the generated password is displayed once after redirect, and no viewer login is possible until the subordinate viewer-account mode is enabled.
2. Repeat with an explicit policy-compliant temporary password. Confirm duplicate email, malformed email, weak password, unavailable schema, and installation-cap exhaustion fail without creating a second account.
3. Leave **Send notification** enabled and confirm mail contains the trusted login URL and first-login instruction but not the generated/supplied temporary password. Deliver the temporary password through a separate trusted channel. Refresh/navigate away from the Admin page and confirm the plaintext show-once value is gone.
4. Enable viewer accounts and sign in with the temporary password. Confirm login redirects to `/viewer/first-login`, `current_viewer()` is still absent, no viewer remember credential is issued/restored, and favourites/collections/account pages cannot be used as a normal signed-in viewer before replacement. A simultaneous Admin principal must remain logged in.
5. Submit a weak replacement, mismatched confirmation, and the same temporary password; each must fail while retaining only bounded first-login authority. Submit a new compliant password and confirm the flag clears, the security version advances, old viewer session/remember/reset/email-change authority is revoked, normal viewer login is established, and the temporary password can no longer authenticate.
6. On a direct-created account, use the normal forgotten-password flow instead of completing `/viewer/first-login`. Confirm the scanner-safe reset succeeds through its existing verification path, clears `must_change_password`, and the reset password signs in normally.
7. Delete a direct-created and an invitation-created viewer account from Admin. Confirm an explicit confirmation is required and only the target `viewer_accounts` identity plus viewer-owned dependent state is removed. Photographs, galleries, Smart Galleries, gallery share links, favourites belonging to other viewers, and Admin authentication/session state must remain unchanged.
8. Hold Admin and viewer principals simultaneously, then create/delete a different viewer. Confirm Admin `user_id`, current viewer ownership, protected-gallery authorization, and existing gallery share grants are unaffected.
9. Inspect the direct-provisioning surface and confirm it does not itself add public signup, a public user directory/profile, collection-share authority, uploads/comments, TOTP, OIDC, or passkeys.

## Phase 2.5 Administrator Viewer Account Security Controls

Run the focused security-control regression:

```powershell
php tests/viewer_admin_security_controls_test.php
```

The test directly executes the existing `viewer_account_suspend()`, `viewer_account_restore()`, and `viewer_session_revoke_all()` helpers against a deterministic PDO fixture, so the core suspension/restoration/logout-all assertions are not source-only. It proves security-version rotation, session/remember/reset revocation, dormant collection-share revocation on account-state transition, restoration non-resurrection, `must_change_password` preservation, first-login limited-state invalidation, Admin `user_id` survival, matching viewer-namespace cleanup, other-viewer isolation, favourites/collections preservation, feature-disable operation, and fail-closed schema behavior. Static HTTP checks additionally require Admin auth, Admin CSRF, POST-only action placement, strict positive account IDs without overflow, SQL-free controller wiring, localized state-specific buttons, Admin audit events, and no Phase 3 route/UI expansion.

Manual regression should cover:

1. With viewer accounts enabled, open **Admin > Account > Viewer accounts** and suspend an active viewer. Confirm the row becomes Suspended, the viewer loses authority on the next request, an old Remember me cookie cannot restore the session, and any simultaneous Admin login remains active.
2. Restore that viewer. Confirm the row returns to Active but every pre-suspension viewer session/remember/reset/share capability remains invalid. Sign in normally again and confirm favourites and private collections are still present.
3. Repeat suspend/restore for an administrator-created `must_change_password=1` account. Confirm the temporary password still enters only the existing forced first-login replacement flow after restoration and cannot obtain normal viewer authority before replacement.
4. On an active viewer, choose **Sign out everywhere**. Confirm all viewer devices require a fresh login, the account remains Active, favourites/collections/password/first-login flag are unchanged, and any simultaneous Admin login remains active.
5. Keep the master viewer feature enabled but disable the subordinate viewer frontend from the same Admin viewer page. Confirm Suspend, Restore, Sign out everywhere, and Delete remain available to the administrator while public viewer login remains disabled. Then disable the master feature in **Admin > Features** and confirm the Viewer accounts Admin entry itself disappears and its route is centrally guarded.
6. Simulate missing/unknown viewer lifecycle security schema. Confirm the security mutation fails with a normal operational message while Admin authentication and unrelated anonymous gallery browsing continue.
7. Inspect the Admin page and routes to confirm there is still no Disable action, viewer impersonation, collection Share/Copy link control, anonymous collection route, public viewer profile, upload, TOTP, OIDC, or passkey feature.

No Phase 2.5 migration is expected. Optional live MySQL/MariaDB qualification should add races for suspend versus restore, suspend versus sign-out-all, suspend versus delete, and restore versus delete when the configured external harness is available; an unavailable driver/DSN must be reported as skipped rather than passed.

The optional MySQL/MariaDB harness remains conditional on `pdo_mysql` plus the configured `GALLERY_TEST_MYSQL_*` environment. A missing driver or DSN is a `SKIP`, not a pass. The normal focused/static and complete PHP suites do not require an external database.

## Phase 3.0 Unlisted Read-only Collection Sharing

Run the focused regression test with:

```bash
php tests/viewer_collection_sharing_phase30_test.php
```

The test verifies reuse of the dormant share table without plaintext storage, the existing 32-byte opaque-token/SHA-256 primitives, strict 43-character token syntax before lookup, fixed 30-day expiry, transactional one-active-share replacement, owner/current-viewer and Viewer-CSRF POST boundaries, un-rate-limited revoke, scanner-safe GET exchange, 303 token removal, isolated and capped `viewer_collection_share_grants`, per-request durable grant revalidation, live `viewer_source_images_resolve_authorized()` filtering, explicit no-Admin-bypass behavior, no collection-share hooks in direct media/gallery authorization, lifecycle semantics for suspend/restore/sign-out-all/delete, master-feature default-OFF ownership, independent share-schema failure, XSS/disclosure constraints, maintained language parity, and runtime resolution of new PHP imports. It also executes pure token/session-grant helper behavior without requiring an external PDO driver.

Manual HTTPS qualification should additionally create Share A, open it in two independent browsers/sessions, verify both exchange to token-free URLs, replace with Share B and confirm both existing Share A clean sessions fail on their next request, then revoke Share B and confirm its existing clean session fails likewise. Repeat with a public image that is subsequently made private and with a gallery that becomes password protected. The recipient must lose the item until independently satisfying the existing source-gallery authorization. In a browser that also holds an Admin principal, the shared page must still omit content visible only through Admin bypass, while normal Admin gallery/media routes retain their historical behavior. Verify renaming, adding, removing, and reordering update the shared live view without regenerating the share.

With the global Viewer Accounts master feature OFF, both raw and clean Phase 3 routes must look like ordinary not-found requests and owner share controls are unreachable. With the share table missing/unknown in a controlled schema-failure test, sharing must fail closed while private collections and ordinary public galleries continue to operate. Optional real MySQL/MariaDB race qualification should cover replace/replace, replace/revoke, replace/suspend, replace/delete-collection, replace/delete-account, and exchange/revoke. If `pdo_mysql` or the `GALLERY_TEST_MYSQL_*` harness is unavailable, report that qualification as skipped rather than passed.

## Phase 4.0 Open Registration Policy and Lifecycle Foundations

Run the focused policy regression with:

```bash
php tests/viewer_open_registration_policy_phase40_test.php
```

The Phase 4.0 test proves the backend registration policy recognizes only `disabled`, `invite_only`, and `open`, with invalid values failing closed to `disabled` and the global Viewer Accounts master switch still dominating every subordinate mode. It directly exercises invitation-backed versus open-origin classification from the existing nullable `viewer_invitation_id`, current-mode authorization for both origins, `open -> invite_only` and `open -> disabled` cancellation of only open-origin pending/email-verified rows, invitation preservation, stale-authority cleanup before re-enabling `open`, and fail-safe transition behavior when cleanup cannot complete. Static lifecycle contracts additionally require current-mode authorization in verification validation, explicit confirmation, and final activation before durable account creation; serialized policy re-check during staging admission; no new schema origin column; and no Viewer/Admin principal mixing. The historical temporary Phase 4.0 assertion that no generic route existed is superseded by Phase 4.1 only; all lifecycle/security assertions remain.

## Phase 4.1 Public Verified-email Open Registration HTTP Flow

Run the focused regression test with:

```bash
php tests/viewer_open_registration_http_phase41_test.php
```

The Phase 4.1 test exercises the actual registration service with deterministic staged-row fixtures and verifies: master OFF plus open is unavailable; disabled and invite_only do not expose generic registration; open requires secure transport plus viewer auth/registration storage; `/viewer/register` and `viewer_register` are wired; generic POST passes a null invitation and persists `viewer_invitation_id IS NULL`; the existing IP/subnet/identifier/global registration buckets are consumed; an already-sent valid token is not rotated on duplicate submission; `verification_send_count = 0` may retry; an expired sent token may rotate; invite-backed policy remains valid in invite_only and open; open-origin policy stops after a restrictive mode change; scanner-safe verification and no-auto-login remain; the Admin selector is exactly disabled/invite_only/open and uses the existing lifecycle-aware setting service; Register discovery is open-only; all four language catalogs contain the new strings; and no resend, Turnstile/CAPTCHA, public-profile, or Phase 5 route is added.

Manual qualification should keep the global Viewer Accounts master OFF first and confirm no viewer navigation or direct public registration surface is reachable. Turn the master ON and test each subordinate mode: **Disabled** should keep the viewer frontend unavailable; **Invite only** should retain login and Admin invitation registration with no generic Register link; **Open registration** should show Register on the viewer login page and anonymous public header, accept only email plus Viewer CSRF, return the same generic notice for accepted/suppressed requests, deliver neutral verification mail through the configured transport, and continue through GET validation -> explicit confirmation POST -> password selection -> durable activation -> viewer login. In open mode, verify an Admin invitation still works. After creating an open-origin staged request, switch to Invite only and confirm its old verification link cannot continue, then switch back to Open and confirm the old authority does not resurrect. Double-submit a freshly mailed request and confirm the first emailed link remains valid and no second message is generated by the duplicate path. Phase 4.1 itself intentionally introduced no explicit resend UI/endpoint and no CAPTCHA/Turnstile; Phase 4.2 adds the resend path while preserving every Phase 4.1 duplicate-submit protection.


## Phase 4.2 First-party Verification Resend and Recovery Hardening

Run the focused regression test with:

```bash
php tests/viewer_verification_resend_phase42_test.php
```

The Phase 4.2 test verifies `/viewer/resend` plus `viewer_resend_verification` dispatch, the Viewer/pre-auth CSRF/input contract, and the availability matrix: master OFF or registration `disabled` is unavailable, while `invite_only` and `open` are route-capable when transport/auth/registration storage is healthy. Per-request authority is tested independently: open-origin staging can resend only under `open`; invitation-backed staging can resend in both `invite_only` and `open`; restrictive mode changes block already-prepared open-origin delivery; cancelled/stale state does not resurrect.

The test exercises the existing `viewer_resend_verification_identifier` authorization and verifies delivery still references `viewer_mail_authorize_send()` plus the existing verification mail bucket family. It proves the Phase 4.1 primary token hash/expiry are unchanged by resend preparation, a newly prepared child token is unusable before successful handoff, successful handoff makes both A and B valid, and transport failure leaves A valid while B cannot verify. Independent A-first and B-first confirmation fixtures prove the first successful explicit confirmation transitions the shared registration request and invalidates the sibling. A historical primary-only Phase 4.1 request still validates and confirms after the Phase 4.2 child-table migration.

Static HTTP/security assertions require one generic public response for syntactically valid CSRF-valid submissions, no browser-supplied registration/origin/invitation authority fields, scanner-safe verification GET, no automatic Viewer/Admin identity establishment, no plaintext/hash disclosure, bounded child-token storage/cascade cleanup, translation parity, and no CAPTCHA/Turnstile/reCAPTCHA/hCaptcha, adaptive challenge, remote security API, Composer/npm dependency, or new runtime service.

Manual qualification should confirm a freshly delivered verification link A remains usable after requesting and receiving B, then repeat with B confirmed first. Simulate mail failure for B and confirm A still verifies. Check resend recovery links in relevant registration/verification UI only when the master feature and registration mode make resend available. For a staged open-origin request, change `open -> invite_only` and `open -> disabled` and confirm no resend message is handed to transport; invitation-backed pending staging should resend in `invite_only` and `open`. Let the whole staged request expire and confirm resend does not revive it. Every syntactically valid resend submission should return the same public notice regardless of address/account/request/limiter/mail outcome.

## Phase 4.3 First-party Adaptive Anti-automation Gate

Run the focused regression test with:

```bash
php tests/viewer_anti_automation_phase43_test.php
```

The focused test verifies that `/viewer/register` and `/viewer/resend` receive signed first-party form state and that authoritative action, nonce, issue time, expiry, honeypot metadata, and challenge difficulty cannot be tampered with, crossed between actions, crossed between PHP sessions, or replayed. It verifies the 12-entry session cap and opportunistic expiry cleanup, server-measured form age, randomized empty honeypot behavior, populated-honeypot suppression, clean challenge-free requests, repeated/fast escalation, hard limiter suppression, and existing `viewer_rate_limit_consume()` reuse through `viewer_automation_ip` and `viewer_automation_subnet`. Existing registration, resend-identifier, and verification-mail limiter families remain present and are not replaced.

Proof tests solve one real bounded SHA-256 challenge and reject expired/tampered/replayed authority, invalid proof, challenge limiter denial, and counters beyond the hard ceiling. Static JavaScript checks require the solver to remain a local `public/assets/viewer-anti-automation.js` asset using native `crypto.subtle.digest('SHA-256', ...)`, with no remote import, hashing dependency, browser-fingerprint probes, or unbounded counter loop. The no-JavaScript fallback is checked for Viewer-CSRF presentation, signed/session-bound/short-lived/single-use challenge authority, minimum server-measured challenge age, and existing local limiter use.

Controller-order assertions require Viewer CSRF and local syntax validation before the Phase 4.3 authorization call and require hard suppression to branch before `viewer_registration_request_begin()` / `viewer_registration_verification_resend_prepare()` and therefore before verification-mail authorization/transport. Challenge success still delegates to the Phase 4.0 through 4.2 services. The focused regression protects generic registration/resend results, Phase 4.2 primary token A preservation and sibling-authority independence, current-mode revalidation, invitation-authority independence, no Viewer/Admin principal creation, scanner-safe verification GET, bounded event context, maintained translations, and the zero-third-party runtime contract.

Manual browser qualification should test one ordinary registration and resend request with JavaScript enabled and confirm no challenge on the initial clean path. Submit immediately or repeat from the same client until escalation and confirm the local panel solves via Web Crypto and only then continues to the existing generic workflow. Disable JavaScript or Web Crypto and confirm the explicit local fallback becomes usable only after its server-enforced delay. Populate the randomized hidden field through developer tools and confirm the public result remains generic while no registration/resend or mail work occurs. Confirm no network request is made by the challenge page except normal same-site form/asset requests and configured mail remains the only outbound transport. Repeat open-origin mode changes and Phase 4.2 token-A/token-B verification scenarios to confirm the anti-automation gate never changes registration origin, verification authority, current-mode policy, or first-confirmed-token-wins behavior.

## Phase 4.4 Viewer Registration Security Operations and Phase 4 Closure

Run the focused regression test with:

```bash
php tests/viewer_security_operations_phase44_test.php
```

The focused test verifies that security operations remain inside the existing Admin Viewer-accounts surface and use the established `require_admin()`/administrator identity boundary without adding a public or Viewer metrics route. It verifies that no Phase 4.4 migration, metrics table, event table, limiter table, telemetry coupling, Composer/npm dependency, remote monitoring integration, CAPTCHA service, Redis/Memcached requirement, or new persistent visitor identifier is introduced.

Capability tests cover Viewer Accounts master state, all three effective registration modes, open-registration and resend availability, normalized Phase 4.3 anti-automation configuration, and `available` / `unavailable` / `unknown` storage states. Capacity fixtures verify durable account count/cap and pending registration count/cap, including aggregate open-origin versus invitation-backed staging, without adding email to the operations metrics. Unavailable storage is distinct from a real zero.

Event fixtures use fixed timestamps and prove rolling 24-hour and 7-day counts include only the Phase 4 allowlist and exclude out-of-window/unrelated events. The seven-calendar-day table groups accepted registration requests, verification messages sent, verification resend messages sent, and the documented anti-automation intervention definition (`viewer.automation_challenge_required + viewer.automation_request_suppressed`) by date. The implementation is checked for fixed aggregate SQL rather than an individual-event browser or arbitrary date/report engine.

Limiter fixtures and generated-query assertions protect policy-owned bucket selection and the current-pressure semantics. Active means `last_attempt_at` remains in the configured policy window or `locked_until` remains in the future. Locked means `locked_until > now`. Stale inactive rows and expired locks must not inflate pressure. The registration and verification-mail global-day budgets derive current usage only while `first_attempt_at` remains in the current policy window. Rendering must not call `viewer_rate_limit_consume()`, reset/delete limiter rows, or run maintenance.

Read-only/privacy assertions require the operations service to contain no registration, verification, invitation, anti-automation-ticket, or telemetry mutation path. Rendered operations HTML must not expose IP/IP hash, user-agent/hash, limiter subject hash, request id, event context JSON, verification authority, installation secret, or registration email dimensions. Historical Phase 4.1 generic registration, Phase 4.2 generic resend/token-A/sibling-authority, Phase 4.3 local anti-automation, current-mode revalidation, invitation authority, scanner-safe verification, no-auto-login, and Viewer/Admin principal-boundary regressions remain independently authoritative and are run again in the full suite.

Manual qualification should open **Admin -> Viewer accounts** as an administrator and confirm the new **Viewer security status** panel follows the existing three-state registration selector. Verify status/capacity sections, rolling 24-hour/7-day counts, the seven-day table, fixed limiter-family pressure, and both global-day budget rows. Confirm no identity dimension is shown in those new metrics. Disable or make one backing capability unavailable in a test/staging environment and confirm the affected subsection reports unavailable/unknown rather than zero while the rest of the Admin page remains usable. Repeatedly reload the page and confirm limiter attempts, verification authorities, staged registrations, and Phase 4.3 session challenge authority do not change because of observation.

Phase 4 is considered complete when this focused regression plus the historical Viewer, telemetry, translation, migration, packaging, complete PHP, and Node suites pass.

## Browser upload oversized-single-image batching

For browser-assisted gallery uploads, treat the configured ZIP batch size (24 MB by default) as a soft packing target. A prepared image package is atomic because it contains the original plus all browser-generated thumbnail variants. If one package alone exceeds the target, it must be emitted as a one-image ZIP batch instead of failing. The client and server must still reject a prepared ZIP that exceeds the detected effective PHP upload limit. Multi-image batches must continue splitting at the configured target and maximum-images-per-batch setting. Run `php scripts/check_admin_mutation_contracts.php`, PHP/JavaScript syntax checks, and verify `app/core-manifest.json` after changes to this path.
