# Testing Guide

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

Run one focused test directly when diagnosing a failure:

```bash
php tests/gallery_visibility_model_test.php
php tests/browser_upload_settings_test.php
php tests/gallery_public_paths_test.php
php tests/migration_consistency_test.php
php tests/migration_legacy_runner_compatibility_test.php
php tests/database_maintenance_test.php
php tests/database_maintenance_schema_repair_test.php
php tests/updater_safety_model_test.php
php tests/thumbnail_warmup_model_test.php
```

The favorite shortcut test covers zero configured shortcuts, direct gallery links, the optional main-page shortcut, duplicate/missing-gallery cleanup, public visibility filtering, and HTML escaping.
The gallery dates test covers manual date range normalization, reversed-range rejection, public display formatting with en dash separators, rendered date attributes, and branch matching used by scoped EXIF suggestion reviews.
The gallery public-path test covers Czech transliteration, decomposed accents, invisible Unicode characters, HTML entities, hierarchical paths, and sibling slug collisions.
The migration consistency test validates every migration definition, preflights the complete migration set, and proves that old schema_migrations rows remain harmless after obsolete migration files are removed.
The legacy migration-runner compatibility test verifies that PHP repair migrations work both with the current definition-aware runner and with the former SQL-only runner that may still be present during a partial patch deployment.
The database maintenance test covers information_schema normalization, compact and legacy schema detection, SQL-literal reference scoping, obsolete thumbnail objects, orphan and expiry rules, deterministic duplicate survivor selection, protected content/log/telemetry tables, report-only unsupported thumbnail variants, Admin authentication, CSRF, confirmation contracts, and the absence of filesystem cleanup side effects.
The database maintenance schema-repair test uses a mutable PDO fixture to verify audit-table creation, absent thumbnail tables, partially compacted schemas, geometry migration before destructive DDL, obsolete index/foreign-key cleanup, already compact schemas, idempotent retry, and the absence of row or filesystem deletion.
The updater safety test verifies that critical runtime files are required before deployment starts and that valid top-level app entries such as `app/views.php`, `app/views/`, `app/lang/`, and migration support modules are never classified as misplaced project copies.

These tests are maintained against the current namespaced production code. They are best for pure logic, helper functions, and regression checks that do not require a browser session. A release patch should not be published while `php tests/run.php` reports a failure.

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

### Database Maintenance Smoke Test

Use a disposable database or a verified backup when testing destructive actions.

1. Open Admin, Storage statistics, Database maintenance and confirm no full audit runs during ordinary dashboard loading.
2. Run **Inspect database** and verify every active table appears with columns, indexes, foreign keys, storage, policy, migration references, and code-reference counts.
3. Confirm the thumbnail section states that `image_thumbnail_variants` stores metadata only and shows size, format, and status distribution.
4. Run the cleanup dry-run and confirm candidate counts change no data and report zero filesystem deletions.
5. On prepared orphan fixtures, run one confirmed cleanup batch, reload, continue until complete, and rerun to prove idempotency. Confirm the same transaction created a `database_maintenance_audit_log` row containing every removed primary-key identity and the cleanup reason.
6. Confirm valid galleries, images, users, `admin_logs`, every telemetry table, migration audit tables, and unknown tables remain untouched.
7. On a legacy or partially compact thumbnail schema, run the repair dry-run first and confirm it reports the pending migration and exact planned objects without DDL. Then type `REPAIR`, apply the dedicated migration, and reinspect.
8. Select one disposable table for **Refresh database statistics** and verify `ANALYZE TABLE` result messages.
9. Select one disposable table for **Reclaim table space**, preview the selected optimization plan, and confirm no table statement ran. Then type `OPTIMIZE` and verify failure or engine messages are surfaced without claiming guaranteed disk reduction.
10. Confirm no media, thumbnail, or ZIP file is deleted by any database-only action.

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

If the answer is yes to any of those, run the manual smoke test in addition to syntax checks.

## Recommended Habit
Keep a short test note for each significant change:

- what changed
- what you tested
- what you did not test
- any warning signs or follow-up work

That makes regressions easier to track and helps future changes focus on the highest-risk paths first.
