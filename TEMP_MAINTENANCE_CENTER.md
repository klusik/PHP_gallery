# TEMP Maintenance Center implementation status

## Source
- Source of truth: `php-gallery-deploy(20260920-122628).zip`
- Specification: `PHP_GALLERY_MAINTENANCE_CENTER_PROMPT(1).md`
- Repository guidelines read: `AGENTS.md`

## Current stage
Core implementation, Admin UI, runtime translations, focused regression coverage, and permanent architecture documentation are implemented. The latest central quick audit completed without a regression FAIL; final manifest regeneration and the single full audit remain.

## Implemented architecture
- New `maintenance_jobs` persistence migration with durable plan/state JSON, plan hash/revisions, weighted progress, cancellation, bounded errors, history timestamps, and a nullable unique central mutation claim.
- New strict-MVC persistence model in `app/models/maintenance_center.php`; SQL remains model-owned.
- Canonical task registry with dependency ordering, server-side state transitions, required/optional/default selection, risk metadata, weights, and stable analyze/execute adapters.
- Browser-driven `Analyze -> Review -> Execute -> Verify` workflow. Analyze performs bounded read-only subsystem inspection; the only writes during Analyze are Maintenance Center job checkpoints.
- Plan freshness binds application version, applied migration revision, registry revision, and SHA-256 plan content. Normal live row-count changes do not make the plan stale.
- Running/paused jobs own durable `lock_key=central`; automatic `site_maintenance` yields before its first mutation slice while the claim is owned.
- A short-lived MySQL advisory lock serializes individual analysis/execution requests for the same job so two browser tabs cannot advance one checkpoint concurrently.
- Cooperative pause/cancel semantics. Completed work is never represented as rolled back.
- Atomic physical DB cursor/checkpoint persisted before one-table `ANALYZE` / `OPTIMIZE`. Lost-response retry cannot blindly replay that same table.
- Dashboard card, dedicated Maintenance navigation/page, canonical Admin JSON mutation envelope, POST/CSRF/Admin boundaries, weighted progress UI, bounded recent activity/warnings, persisted reload/resume behavior.
- English, Czech, German, and Swedish Maintenance Center catalogs.
- Permanent documentation updated in `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, and `TESTING.md`.

## Reused subsystem owners
- Telemetry: existing resumable rollup/retention maintenance, looped until `has_more=false`.
- Admin logs: existing verified one-day archive/live-retention owner; archive ZIP deletion is not added.
- Gallery Trash: existing reconciliation and policy-expired bounded purge. Maintenance Center is not Empty Trash.
- Security/viewer retention: existing bounded cleanup owner.
- Download/cache: existing manifest, legacy download-artifact, and generated-ZIP cleanup owners.
- Thumbnail metadata: existing safe strong-FK orphan owner extended with an optional bounded deletion limit; central execution uses batches of 250 rows per relation.
- Deep media: optional bounded verification only. Regeneration remains in the dedicated repair workflow.
- Database logical cleanup: existing resumable cleanup owner.
- Database physical maintenance: existing table inventory/policy plus existing ANALYZE/OPTIMIZE owners, exactly one server-selected eligible table per central HTTP step.

## Safety decisions
1. Maintenance Center is core Admin functionality, not a new feature toggle. Existing optional subsystem capabilities remain authoritative and can make individual tasks unavailable.
2. The review plan is presentation data, never authorization. Task keys and physical tables are resolved and revalidated from server registry/inventory at execution time.
3. Unknown/protected/unclassified DB tables are not optimized. Tables at or above the conservative 256 MiB web-maintenance threshold are surfaced/skipped rather than rebuilt blindly on shared hosting.
4. Generic Run Maintenance never applies pending migrations, schema repair, updater installation, arbitrary DDL repair, archive ZIP deletion, or speculative orphan cleanup.
5. Failure after an atomic DB checkpoint reloads the newest persisted row before terminal failure bookkeeping, so the checkpoint cannot be overwritten by stale request state.
6. Completion requires the required verify task and a persisted before/after report. Physical reclaimed bytes are reported only when before/after inventory supports the claim.
7. Maintenance Center job history is retained for 90 days and deleted in bounded batches.

## Focused verification performed while developing new coverage
- The first central quick audit reported two repository-policy failures in newly added assets: JS declaration documentation and an incomplete CSS source header. Both were corrected.
- Focused diagnosis reruns passed:
  - `tests/function_documentation_test.php`
  - `tests/source_header_author_test.php`
- New `tests/maintenance_center_test.php` passes locally and covers registry/state/selection, read-only analysis source boundaries, persisted locking/checkpoints, one-table physical DB orchestration, automatic-maintenance conflict protection, HTTP/CSRF routes, browser orchestration, Dashboard/navigation, migration schema, and translation-key parity.

## Environment limitation
The focused Maintenance Center regression intentionally does not claim that a real MySQL/MariaDB `OPTIMIZE TABLE` rebuild ran in the local environment. Physical rebuild behavior is verified through policy/source contracts here and must receive disposable-production/manual coverage where a real MariaDB installation is available.

## Latest verification checkpoint
- Central quick audit after the core implementation reported no regression FAIL: PHP regression `221 pass / 0 fail`, MVC `0 strict violations`, Node fast `23/23`, WinApp `36/36`, Admin mutation contracts PASS, and runtime hardening PASS.
- The overall quick invocation reached its execution timeout during later syntax coverage. Six PHP tests were reported BLOCKED by environment prerequisites rather than product regressions; no product FAIL was reported.
- Maintenance Center runtime activity/warning text now has explicit EN/CS/DE/SV catalog entries, including completion/skip paths. English fallback remains only as the repository translation-call convention.

## Final handoff verification
- `php scripts/generate_manifest.php`: generated current manifest for application version `0.105.1`, 722 managed files.
- `php scripts/generate_manifest.php --check`: PASS.
- Exactly one final `php scripts/audit.php --profile=full` was invoked.
- The full audit produced no product FAIL before the outer execution limit stopped the process during suite 10/12 (`PHP syntax`):
  - PHP regression: `221 pass / 0 fail / 11 skip / 6 blocked`.
  - MVC layer boundaries: PASS, `0 strict violations`.
  - Source contract inventory: PASS.
  - Changed declaration documentation: BLOCKED only because this deployment ZIP has no Git HEAD/source comparison metadata.
  - Changed runtime policy documentation: BLOCKED for the same missing Git comparison metadata.
  - Node regression: PASS, `24 pass / 0 fail`.
  - WinApp regression: PASS, `36 pass / 0 fail`.
  - Admin mutation contracts: PASS.
  - Runtime hardening audit: PASS.
- PHP regression BLOCKED cases are environment prerequisites: SQLite, ZIP, GD/EXIF. Relevant SKIPs require disposable MySQL/HTTP/Chromium fixtures or unavailable `pdo_mysql`.
- Because the outer command was terminated before suites 10-12 completed, the central full-suite handoff status is accurately **BLOCKED**, not PASS. The full profile was not rerun, preserving the task requirement to invoke it exactly once.

## Remaining handoff step
- Build mandatory affected-files ZIP preserving repository-relative paths.

## Production follow-up hotfix: downloads.cache failure isolation
- Production reported `maintenance_center.failed` with `category=task_failed` and `task=downloads.cache`, while localhost completed the same phase.
- Root-cause review found a real orchestration defect: the original central `downloads.cache` executor invoked manifest-cache cleanup, legacy download-artifact cleanup, and generated-ZIP cleanup in one request. `cleanup_legacy_download_artifact_cache()` intentionally throws when the legacy server ZIP fallback/cache is unavailable; existing `site_maintenance` already isolates that condition as an optional cleanup failure, but Maintenance Center incorrectly allowed it to terminate the whole central job.
- `downloads.cache` now executes exactly one server-owned sub-operation per browser step: `download_manifests`, `legacy_download_artifacts`, then `zip_cache`. Manifest cleanup retains its bounded repeated slices when its scan reports truncation.
- Each independent cache owner is failure-isolated. A hosting-specific unavailable cache owner is skipped with a warning while unrelated central maintenance continues. Partial cleanup already committed by another independent owner is preserved and accurately counted.
- Added bounded diagnostics for cache sub-operation failures: `task`, `operation`, `exception_class`, stable `error_code`, and `duration_ms`. Raw exception messages, SQL, filesystem paths, and traces remain excluded.
- Generic unexpected `maintenance_center.failed` events now also include bounded `exception_class` and classified/stable `error_code`, so future task failures are diagnosable without exposing sensitive runtime details.
- Registry revision bumped to `2026-09-20.2`, forcing re-analysis for plans created under the previous task behavior.
- Added EN/CS/DE/SV runtime strings and regression coverage for stable exception reasons, redaction of raw exception text, and cache-owner failure isolation.
- Follow-up quick audit after the fix: PHP regression `221 pass / 0 fail / 11 skip / 6 blocked`; MVC PASS with `0 strict violations`; Node fast `23/23`; WinApp `36/36`; Admin mutation contracts PASS; runtime hardening PASS. The outer command again hit its environment timeout during later PHP syntax coverage. No product FAIL remained before the timeout.

## Production follow-up final verification
- `php scripts/generate_manifest.php`: regenerated manifest for application version `0.105.1`, 722 managed files.
- `php scripts/generate_manifest.php --check`: PASS before the final audit.
- Exactly one follow-up `php scripts/audit.php --profile=full` was invoked for this hotfix.
- The follow-up full audit reached:
  - PHP regression: `221 pass / 0 fail / 11 skip / 6 blocked`.
  - MVC layer boundaries: PASS, `0 strict violations`.
  - Source contract inventory: PASS.
  - Changed declaration/runtime policy coverage: BLOCKED because the deploy snapshot has no Git comparison metadata.
- The outer runner was then terminated by the execution environment while the full Node regression suite was running, before a Node result or later suites were emitted. The accurate overall follow-up status is therefore **BLOCKED**, not PASS. The full profile was not rerun.
