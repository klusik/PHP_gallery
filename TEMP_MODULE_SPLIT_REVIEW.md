# Temporary module-split review report

Date: 2026-09-06

Reviewed range: `09002091..be95a1b3578926259c232a6fd1e1c10c2c12630e`

This report records an analysis of the current refactor. It is a handoff for other agents, not evidence that the findings have been fixed. No application code was changed during the review. No commit was created. Revalidate findings against the current checkout before implementing changes.

## Assessment

Available PHP syntax and regression checks passed, but source review found functional regressions caused by moving functions into deeper directories without updating filesystem-relative expressions. The central audit result was **BLOCKED**, because Node.js was unavailable. The refactor should not be treated as fully verified until the path regressions are addressed and the missing verification is completed.

## Reviewed changes

| Commit | Change |
| --- | --- |
| `320e45b` | Split four oversized service modules into part files |
| `c7665fc` | Split the Admin gallery editor into named phases |
| `be95a1b` | Split gallery migration and updater job services |

The range changes 85 files. The working tree was clean before and after the analysis.

## Findings

Line numbers below refer to the reviewed HEAD and may shift after edits.

### 1. High priority: migration job storage moved unintentionally

`app/services/gallery_migration/jobs.php:63` retains `dirname(__DIR__, 2)` after the function moved one directory deeper.

- Previous location: `<repository>/cache/gallery-migrations`
- Current location: `<repository>/app/cache/gallery-migrations`

Existing migration jobs will no longer be found at their original location. New jobs use a different directory, potentially failing on deployments where application files are read-only but the intended cache directory is writable.

The installation identifier has the same problem in `app/services/gallery_migration/versions.php:165`. Its path-dependent hash changes after the refactor. That identifier contributes to migration job identity through `gallery_migration_job_id()` in `app/services/gallery_migration/jobs.php:98`, so the effect extends beyond the storage-directory change.

### 2. High priority: Admin test-run storage moved unintentionally

`app/services/admin_test_runs/paths.php:53` now resolves to `<repository>/app/cache/admin-test-runs`, rather than `<repository>/cache/admin-test-runs`.

This separates existing reports and active runs from the current lookup path and introduces the same application-directory write-permission issue.

### 3. Medium priority: diagnostics inspect incorrect cache, script, and lock paths

Several moved functions retain the old directory depth:

| Location | Consequence |
| --- | --- |
| `app/services/admin_test_run_analysis/cache_analysis.php:105` and line 229 | Default cache inspections target `app/cache` |
| `app/services/admin_test_run_analysis/maintenance_analysis.php:247` | Maintenance CLI availability check targets `app/scripts` |
| `app/services/admin_test_run_analysis/maintenance_analysis.php:310` | Updater CLI availability check targets `app/scripts` |
| `app/services/admin_test_run_analysis/maintenance_analysis.php:351` | Archive-maintenance lock lookup targets the wrong cache directory |
| `app/services/admin_test_run_analysis/maintenance_analysis.php:358` | Telemetry/site-maintenance CLI availability checks target `app/scripts` |
| `app/services/admin_test_runs/snapshot.php:380` | Admin test-run lock snapshot observes the wrong file |
| `app/services/admin_gallery_report/system_summary.php:187` | Gallery report describes the wrong application-cache directory |

These can produce misleading missing-storage, unavailable-CLI, or inactive-lock results despite the actual installation being healthy.

### 4. Medium priority: SQL callsite reporting was not adapted to the split

`app/services/admin_test_runs/recording.php:77` still excludes only the original `admin_test_runs.php` entry file from its backtrace, in addition to `app/database.php`.

It does not exclude the new part files, so internal instrumentation frames can be reported instead of the useful caller. Its path normalization also now uses `app/` as the root, changing reported relative paths and potentially leaving absolute paths for callers outside `app/`.

### 5. Lower priority: browser-upload fallback cache path changed

`app/services/browser_uploads/batch_state.php:60` has the same directory-depth issue in its fallback.

The configured `zip_cache_path` branch remains unaffected. The regression applies when that setting is absent or empty, when batch acknowledgment state now goes under `app/cache`.

## Source-comparison evidence

Named function token sequences were compared before and after the six service splits, excluding comments and whitespace:

| Service | Original named functions | Current named functions | Changed or missing |
| --- | ---: | ---: | ---: |
| `admin_gallery_report` | 77 | 77 | 0 |
| `admin_test_run_analysis` | 34 | 34 | 0 |
| `admin_test_runs` | 60 | 60 | 0 |
| `browser_uploads` | 48 | 48 | 0 |
| `gallery_migration` | 77 | 77 | 0 |
| `updates_jobs` | 61 | 61 | 0 |

All 357 original named functions remain present with unchanged token sequences; no named functions were added or removed in those services. This supports the mechanical nature of the moves, but does not establish semantic equivalence: identical `__DIR__` expressions produce different results after moving their containing files. The comparison also does not independently prove import resolution or file-level initialization behavior.

The Admin editor involved a more substantial extraction into helper functions. Its inspected flow retains authentication, early JSON responses, CSRF checks, POST action dispatch, and the shared editor form. Available contracts passed; authenticated browser interaction remains unverified.

## Automated verification

The required central interface was used:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/audit.php --profile=full
```

| Check | Result |
| --- | --- |
| PHP regression | 126 passed, 0 failed, 1 skipped |
| PHP syntax | 544 files passed |
| WinApp regression | 36 passed |
| Admin mutation contracts | Passed |
| Runtime hardening audit | Passed |
| Node regression | BLOCKED: Node.js unavailable |
| JavaScript syntax | BLOCKED: Node.js unavailable |

Overall audit result: **BLOCKED**, not PASS. Duration: 37.02 seconds.

The skipped PHP test was `viewer_phase07_mysql_concurrency_test.php`, because `GALLERY_TEST_MYSQL_DSN` was not configured. PHP checks ran on PHP 8.3.30; this does not independently establish PHP 8.1 runtime compatibility.

Additional read-only checks passed:

- Core manifest freshness: 509 managed files, current.
- Git whitespace check for `09002091..HEAD`.
- No tracked changes after verification.

Audit output:

- `cache/test-audit/latest.md`
- `cache/test-audit/latest.json`
- Original run directory: `cache/test-audit/20260906-141251-85624`

The `latest` files may be overwritten by a subsequent audit. The results above describe the recorded review run.

## Local HTTP smoke checks

Application base URL: `http://localhost:8888/galerie/`.

| Request | Result |
| --- | --- |
| Homepage | HTTP 200; title `MacGallery` |
| Public gallery `index.php?page=gallery&public_path=test-na-macu` | HTTP 200 |
| Admin login `index.php?page=admin_login` | HTTP 200 |
| Anonymous editor `index.php?page=admin_edit_gallery&id=1` | HTTP 302 to login, preserving return URL |

These confirm basic HTTP rendering and anonymous Admin protection. They do not establish that authenticated editor saves, migration resume, browser uploads, report generation, or updater activation work end-to-end. No such persistent operations were performed during the review.

## Suggested follow-up for the implementing agent

1. Revalidate the moved-file root calculations against the current checkout. Preserve the original cache locations and migration instance identity.
2. Review all moved-file `__DIR__` and `__FILE__` uses, not only the examples above.
3. Update callsite filtering to account for the complete instrumentation module and preserve repository-relative diagnostic paths.
4. Add meaningful regression coverage for default path resolution and identity stability across the split. Passing source-text contracts alone did not catch these regressions.
5. Consider whether any jobs or reports have already been written to the unintended locations; inspect before deciding on recovery. Do not delete or relocate runtime data without appropriate scope and authorization.
6. Follow the current `AGENTS.md` verification contract: use the central audit, resolve Node.js availability, and regenerate the core manifest after any managed source edits. Do not duplicate the central runner with manual test loops.

This temporary report records review findings only. It does not authorize a commit, deployment, or runtime-data mutation.
