# Testing Guide

This guide applies to PHP Gallery Version 0.89.1. Release verification must include the modular runtime boundaries, complete deployment packaging, updater cleanup safety, deferred dashboard maintenance, centralized Settings discovery, the configurable public language selector, hourly automatic-update throttling, and the supported English, Czech, German, and Swedish catalogs.

## Purpose
This project is a plain PHP gallery CMS without a formal browser automation stack. The most reliable testing approach is a mix of fast syntax checks, focused script-level checks, and a repeatable manual smoke-test scenario that exercises the core gallery lifecycle.

## Test Layers

### 1. Syntax Checks
Use these first when touching PHP or JavaScript:

```bash
php -l path/to/file.php
```

For JavaScript, use whatever local parser or linter is available in your environment. Syntax checks catch obvious breakage, but they do not prove the app still behaves correctly.

### 2. Script-Level Tests
The repository uses current direct PHP regression tests under `tests/`. Run the complete isolated suite with:

```bash
php tests/run.php
```

Browser ZIP-import changes must additionally verify stored and Deflate archives, nested image paths, mixed supported and unsupported entries, empty archives, damaged central directories, encrypted/ZIP64 archives, traversal names, hidden `__MACOSX` metadata, oversized entries, expansion-ratio limits, duplicate filenames, and a ZIP selected while browser-assisted upload is unchecked or unsupported. The archive itself must never reach the classic PHP upload request; extracted valid images must still use the existing browser preparation, batching, server validation, thumbnail, and Admin side-panel progress pipeline.

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

The public thumbnail rendering model test covers responsive default/fallback normalization, supported setting persistence, invalid Admin input normalization, the narrow renderer dispatch boundary, the unchanged responsive eager/lazy/fetchpriority thresholds, and progressive small-thumbnail thresholds. The public thumbnail markup test covers complete responsive srcsets, small-only progressive active srcsets, inert larger candidates, WebP/JPEG structures, missing variants, synthetic bounds, intrinsic dimensions, media fallback, warm-up attributes, and selected-gallery NSFW gate ordering. The hero tag Theme model test covers 20-tag and five-row defaults, server-side clamping, display-all and scrollbar booleans, usage/alphabetical mode normalization, Admin persistence wiring, complete server-rendered hero groups, full-width CSS overrides, anonymous/logged-in browser entrypoints, accessible disclosure state, row-based scrollbar activation, and English/Czech public strings. `tests/progressive_thumbnail_renderer_test.mjs` covers browser-independent candidate parsing, smallest-adequate selection, capped DPR width calculation, queue deduplication, visible priority, and the two-worker concurrency bound. DOM intersection, actual browser network order, decode timing, cache reuse, lightbox/maps/votes interaction, hero tag wrapping at real browser widths, and reduced-motion rendering remain manual checks.

These tests are maintained against the current namespaced production code. They are best for pure logic, helper functions, and regression checks that do not require a browser session. A release patch should not be published while `php tests/run.php` reports a failure.

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
php tests/run.php
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
php tests/run.php
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
php tests/run.php
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
php tests/run.php
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

After restoring metadata access, rerun the complete suite. The final Phase 11 release
acceptance baseline is **58/58 PHP regression tests passing**, translation catalogs
aligned across English/Czech/German/Swedish, all changed PHP files passing `php -l`,
the integrity manifest current, the administrator manual rebuilt and visually
verified, and no temporary schema-reliability roadmap present in the repository or
release package.

### 2.1 Release preparation and handoff

Use this order for each release so generated integrity data describes the final tree:

1. Compare the current branch with the previous release tag and review every changed path, including migrations, protected files, package exclusions, translations, browser modules, and documentation.
2. Update `app/bootstrap.php`, `release-metadata.json`, `PATCH_NOTES.md`, and all user/developer documentation. Keep the newest patch-note entry above historical entries and follow `PATCH_NOTES_TEMPLATE.md`.
3. Compile and visually inspect `docs/PHP_Gallery_Manual.pdf` using the commands in `docs/LATEX_BUILD.md`.
4. Run the complete PHP suite, all focused tests relevant to the change, `php -l` on every changed PHP file, JavaScript syntax checks, and `git diff --check`.
5. Run `php scripts/generate_manifest.php`, then `php scripts/generate_manifest.php --check`. Confirm the manifest version matches `CMS_VERSION` and that all managed release files are covered.
6. Run the deployment helper to create the requested local folder or ZIP. The helper packages files only; it does not execute PHP or silently repair a stale manifest. Re-run the manifest check after any source edit made during packaging.
7. Inspect the final diff and archive contents for `config.php`, caches, logs, temporary files, gallery media policy, the current manual PDF, patch notes, and `app/core-manifest.json`. Only then create the release commit/tag and publish the archive.

If a local dependency such as PHP, Node, or a TeX tool is unavailable, record the exact blocked command and environment in the handoff. Do not describe the release as fully verified until that command has been run successfully.

When checking Admin dashboard performance, verify that opening `?page=admin` does not request `admin_dashboard_maintenance` until `#admin-tab-maintenance` is selected. Then verify that the authenticated JSON response replaces the placeholder, nested maintenance tabs initialize, and direct or no-JavaScript fallback links remain usable.

### 3. Manual Functional Smoke Tests
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
9. Open an existing gallery that contains photos with EXIF dates, use **Apply to this gallery**, and confirm the From/To fields update without a full page reload when JavaScript is enabled. Repeat from any side-panel editor entry point that exposes the same component. Then confirm the gallery card displays the resulting branch range with the en dash separator.
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
10. Re-run `hero_tag_theme_model_test.php`, `tag_page_theme_model_test.php`, upload/settings tests, telemetry tests and the complete `php tests/run.php` suite.

### Public Thumbnail Rendering Smoke Test

Use a gallery with enough photos to create several viewport lengths. Test with browser DevTools, an empty cache, and a simulated slow connection. Perform the checks both anonymously and while logged in.

1. Leave Admin > Theme > Layout > Public thumbnail rendering on **Responsive browser selection - Default**. Confirm a missing/fresh setting also selects this mode and that switching modes requires no cache or data migration.
2. In the Elements panel, confirm responsive photo cards contain server-rendered `<picture>/<img>` markup and expose their complete available bounded WebP/JPEG `srcset` immediately. The `src` should prefer the 300 px derivative when available.
3. In Network, reload with an empty cache. Confirm the browser directly requests the candidate it selects from the responsive set rather than first requiring JavaScript to discover the image. Native browser behavior may choose a larger candidate immediately.
4. Switch to **Progressive thumbnail sharpening - Beta**. Before browser activation, confirm the live `srcset` contains only the small candidate and larger candidates appear only in `data-progressive-srcset`.
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
11. Run `php tests/hero_tag_theme_model_test.php`, JavaScript syntax checks for `hero-tags.js`, `theme-form.js`, both public entrypoints, and then the full `php tests/run.php` suite.

### Duplicate Photo Detector Smoke Test

1. Apply pending migrations, including `202608080001_duplicate_photo_ledger.php`, then log in as an administrator and open a gallery containing prepared duplicate photos across the selected gallery and one or more nested subgalleries.
2. Open **Find duplicate photos** from the gallery Images section and confirm it uses the existing right-side Admin panel rather than a second modal or standalone route.
3. Confirm **Search all galleries** is unchecked on a fresh detector view. Run local and explicit global scans and verify the scope labels and bounded AJAX progress while the panel remains open.
4. Verify exact SHA-256 and normalized-EXIF possible matches still behave as specified, including different file sizes for valid EXIF candidates and rejection of size-only matches.
5. Confirm completed findings are rendered as deterministic left/right pairs. Verify each side shows image id, filename, file size, dimensions/MIME where stored, EXIF/camera/lens context, and matching signals.
6. Click each gallery title/path and verify it opens the correct public gallery in a new tab. Click each preview, filename, and gallery-relative path and verify it opens the correct public photo context in a new tab. The Admin page and detector panel must remain unchanged.
7. Click **Ignore this pair from now on** on one finding. Verify the action completes through AJAX with no reload/navigation, the right-side panel remains open, the pair disappears immediately, and the ledger count increases.
8. Start a new duplicate search with the same administrator and verify the ignored pair is not shown again while other relationships from the same source group remain eligible.
9. On a left/right pair from different galleries, click **Ignore all from this gallery** on only one side. Verify all currently displayed/future pairs involving that exact gallery are suppressed. Verify a parent or child gallery with a different gallery id is not suppressed automatically.
10. Repeat the exact-gallery action from the opposite side of another pair to confirm left/right controls are independent and the server derives the stored gallery from the submitted result image rather than a browser-provided gallery id.
11. Use **Clear ledger**. Verify it runs through AJAX, the panel stays open, counts return to zero, and a new search can show previously ignored pair/gallery findings again.
12. Confirm ledger decisions are per administrator by testing with a second administrator account when available. One account's ignored pairs/galleries must not suppress another account's results.
13. On a disposable duplicate, press **Delete this** once. Confirm there is no confirmation dialog, no reload/navigation, the browser URL stays unchanged, the panel remains open, and refreshed pair counts/results reflect the deletion.
14. Confirm deletion reuses the existing gallery image mutation semantics for original files, image rows, derivatives, cover references, path safety, and Admin logging. Repeat for a nested subgallery and global-search result.
15. Confirm forged/stale pair IDs, image IDs, moved images outside an immutable local scope, and missing/expired detector jobs are rejected server-side.
16. Disable JavaScript or use the detector route directly and verify normal POST/redirect forms still work as fallback for scan continuation, ledger actions, and explicit deletion. This fallback is not the expected JavaScript interaction path.
17. Run `php tests/duplicate_photo_detector_test.php`, `php tests/duplicate_photo_ledger_test.php`, `php tests/migration_consistency_test.php`, and the full `php tests/run.php` suite.


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

Run `php tests/smart_gallery_rules_test.php` and `php tests/run.php`. Manually create a tag-plus-rating collection, preview and publish it, verify multiple pages, then move one image between three and five stars and confirm membership changes immediately. Test nested logic, deleted references, query-string and clean URLs, search conversion, and inaccessible matching images. Logged-out counts and cards must exclude private, locked, unpublished, share-only, and otherwise inaccessible source galleries.

Test all placement modes: unlisted must remain absent from listings; root must participate in homepage pagination; gallery mode must participate in every selected physical gallery's child pagination. Attach the same Smart Gallery beneath multiple physical galleries, remove it from one, and verify the other assignments remain intact. Disabled or private Smart Galleries must remain absent regardless of placement.

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
