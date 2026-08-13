# Repository Guidelines

## Project Structure & Module Organization
This repository is a plain PHP 8.0+ gallery CMS with no Composer or Node build. Core application code lives in `app/`, split into `controllers/`, `services/`, and `views/`. Database schema changes live in `database/migrations/` and must be added as new timestamped files. Public web assets are under `public/assets/`; writable runtime data is kept in `cache/`, `galleries/`, and `data/`. Standalone test scripts live in `tests/`. Deployment helpers and CLI utilities are in `scripts/`, with Windows-specific tooling in `winapp/`.

## Build, Test, and Development Commands
- `php -S localhost:8000 -t public public/index.php` - run the app locally with the `public/` directory as the web root.
- `php -S localhost:8000 index.php` - alternate local run mode using the repository root router.
- `php scripts/migrate.php` - apply pending database migrations.
- `php scripts/create_admin.php <username> <password>` - create the first admin account during setup.
- `php tests/gallery_visibility_model_test.php` - run a direct PHP test script.
- `php tests/gallery_branding_model_test.php` - run another standalone test script.

## Coding Style & Naming Conventions
Use `declare(strict_types=1);` in new PHP files and follow the existing 4-space indentation style. Keep functions small and explicit; prefer service helpers over duplicated controller logic. Name controller files by feature area, for example `admin_galleries_edit.php` or `public_media.php`. Migration files must be timestamp-prefixed, such as `202606040001_mobile_webdav_upload_tokens.php`. JavaScript and CSS assets should stay framework-free and be placed in `public/assets/`.

## Testing Guidelines
Tests are plain PHP scripts rather than PHPUnit cases. Keep new tests executable from the command line with `php tests/<name>_test.php`. Favor focused tests that validate a single behavior without requiring a browser or live database unless the feature truly depends on one. When changing schema logic, add or update a migration and include a test where practical.

### Mandatory PHP Syntax Validation
Before handing off or committing any change that creates or modifies PHP files, run `php -l` on every changed PHP file. A convenient Git-aware PowerShell check is:

```powershell
$changedPhp = git diff --name-only --diff-filter=ACMR HEAD -- '*.php'
foreach ($file in $changedPhp) { php -l $file; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }
```

Also include staged PHP files when validating a staged-only change, for example with `git diff --cached --name-only --diff-filter=ACMR -- '*.php'`. Do not rely on visual review, substring assertions, or a focused behavioral test as a substitute for PHP parsing. Pay particular attention after editing nested calls, null-coalescing expressions, concatenated HTML, arrays, and ternaries, where a missing `)`, `]`, quote, or semicolon can take down the whole application.

If `php` is unavailable in the execution environment, do not claim PHP syntax validation passed. State clearly that linting is blocked, perform the strongest available static checks, and tell the user that `php -l` must run before deployment. For changes intended for immediate deployment, treat unavailable PHP linting as an unresolved verification gap rather than silently proceeding as fully verified.

## Admin Side-Panel Interaction Priority
Treat the existing Admin right-side panel as the primary interaction surface for every action launched from that panel. When JavaScript is enabled, panel forms and buttons must complete in place through the existing side-panel/AJAX workflow: keep the panel open, do not navigate to a standalone Admin route, do not change `window.location`, and do not reload the page. A normal POST/redirect route may remain only as a non-JavaScript or direct-page fallback; it must not be the normal behavior of an action initiated inside the panel.

Because side-panel content is injected dynamically, bind handlers in a way that also covers newly rendered panel fragments and prevent generic form handlers from taking over panel-owned actions. When a JavaScript module that owns a panel action changes, update its cache-busting import so deployed browsers cannot continue running an older handler. Add focused regression checks for panel persistence and in-place refresh whenever a side-panel action is added or changed.

Persistent mutations launched from the right-side panel, including review/ignore ledgers and reset actions, must use the JSON/AJAX path as the primary browser pipeline and replace only the owned panel fragment or affected page elements. Treat the POST/redirect implementation as fallback compatibility. For every new panel button, test that the browser URL is unchanged, the panel remains open, and a dynamically re-rendered copy of the same control is still intercepted without rebinding the whole page.

Do not invent browser confirmation dialogs or extra intermediate navigation for an explicitly requested one-click/in-place panel action unless the feature requirements specifically call for confirmation. Destructive operations must still use the existing authentication, CSRF, authorization, path-safety, and mutation services.

## Public Thumbnail Renderer Guidelines
Both public thumbnail renderers are permanent supported pipelines. `responsive` is the safe default and must keep complete server-rendered responsive srcsets plus no-JavaScript behavior. `progressive` is a permanent machine/architecture name even while the Admin control displays a Beta maturity label. Do not introduce temporary, experimental, numbered, or replacement-style renderer identifiers.

Changes to shared thumbnail bundle, bounds, HTML, manifest, warm-up, public-card, or browser lifecycle code must test both renderers. No renderer may weaken gallery/password/NSFW access checks, media authorization, semantic server-rendered image markup, useful alt text, or no-JavaScript navigation. Progressive larger candidates must remain inert until the bounded near-viewport scheduler activates them.

The selected-gallery mode is stored as `public_thumbnail_rendering_mode` with only `responsive` and `progressive` values. Invalid values must fall back to responsive. The Admin-facing Beta wording is UI status only and must not leak into setting keys, PHP/JavaScript identifiers, CSS classes, filenames, data attributes, test names, or architecture terms.

`PATCH_NOTES.md` is intentionally not changed as part of renderer implementation work unless a separate task explicitly requests release notes.

## Release Manifest Handoff Requirement
Every change to an updater-managed release file must refresh `app/core-manifest.json` before a deploy archive, affected-files ZIP, release commit, or handoff is created. Run `php scripts/generate_manifest.php` after the final source edit, then run `php scripts/generate_manifest.php --check`. The deployment helpers enforce this automatically and must never offer a path that skips it. If an affected-files ZIP contains any managed application file whose hash is covered by the manifest, include the freshly generated `app/core-manifest.json` in that ZIP as well. A stale manifest makes an otherwise valid GitHub release deterministically uninstallable.

## Commit & Pull Request Guidelines
Git history uses short, imperative messages, often with a feature prefix, for example `feat(admin): add media renamer workflow` or `Feature selector`. Keep commits focused and descriptive. Pull requests should explain the behavioral change, mention any schema or file-system impact, and include screenshots for UI changes when relevant. Note any setup steps needed to verify the change.

## Security & Configuration Tips
Do not commit `config.php`, local caches, uploads, or generated deploy archives. Use `config.example.php` as the baseline for new environments. Keep access checks in `app/services/gallery_access.php` and route-sensitive logic centralized in controllers and services rather than duplicated in views.

## Patch Notes Guidelines
Patch note generation rules are documented in `PATCH_NOTES_TEMPLATE.md`. When creating release notes, follow that template and the existing `PATCH_NOTES.md` style. Do not edit existing historical entries unless explicitly requested.

## Schema Inspection Reliability Guidelines
Schema capability checks use explicit `available`, `missing`, and `unknown` states at every security-sensitive, mutation-sensitive, and optional presentation/reporting boundary. `app/services/schema_inspection.php` owns observation and request-local caching; feature policy remains in the security/access services, `app/services/mutation_schema_policy.php`, or `app/services/presentation_schema_policy.php` according to risk. Do not reintroduce ambiguous boolean existence checks at a boundary that must distinguish confirmed absence from inspection failure.

NSFW Guard is the first converted security-sensitive caller. Preserve its three-state policy: complete schema uses normal protection, confirmed pre-feature schema uses the documented compatibility path, and unknown inspection state fails public NSFW-sensitive requests with the centralized 503 boundary. Explicit NSFW setting changes require verified schema. Keep System Health diagnostics bounded and never expose raw database exceptions.

The NSFW service-unavailable boundary must remain route-aware: translated minimal HTML for human pages, JSON for structured endpoints, and plain text for media or crawler responses. Preserve HTTP 503, private no-store caching, retry and noindex headers, request-ID correlation, and the stable bounded security log event. Logging failure must never interfere with the protective public response.

Admin System Health and Runtime Diagnostics must consume the shared bounded NSFW health model. Missing and unknown states require visible action badges; unknown includes only safe suggested checks and request correlation. Keep affected objects restricted to validated table/column identifiers. The generic disabled state applies only when a feature has a real configuration switch; NSFW Guard currently has none.

Phase 8 validated the pilot result shape and naming, public/Admin presentation,
bounded logging, and the request-local query budget. The completed conversion keeps
those structured result names stable unless a concrete future requirement proves a
deficiency. Policy code should prefer the explicit state predicates over repeated
raw array-key checks. Migration execution is a cache invalidation
boundary: successful schema DDL, duplicate-DDL replay, migration-ledger
bootstrap, and successful repair callbacks must clear request-local schema
inspection results before same-process validation. Data-only statements should
not invalidate schema capability state.

Phase 9 converts gallery access/visibility/share-token and administrator
authentication storage. Preserve these policies in future edits:

- gallery access is legacy only when **all** core access columns are confirmed
  absent; aggregate `missing` with a partially applied migration must fail closed;
- visibility may map canonical `unpublished` to historical `draft` only after a
  successful column-definition inspection proves the old vocabulary;
- protected gallery/media/thumb/metadata/download routes must pass the dispatcher
  visibility, access, and NSFW preflight before handler output;
- confirmed missing remember-token storage may degrade only to ordinary PHP-session
  login; unknown storage must not issue/use persistent tokens;
- confirmed missing `users.email` preserves username login, but password reset
  requires verified `users.email` plus `password_reset_tokens`;
- Google OAuth configuration readiness and `user_google_accounts` schema readiness
  are separate decisions; unknown link storage must not be described as merely
  unconfigured;
- share-token generation/use requires verified storage, while revocation may always
  clear a verified validating hash even if optional encrypted display-token storage
  is missing or unknown;
- security schema logs and System Health may contain bounded capability/operation
  identifiers, validated table/column names, safe categories, and request IDs only.
  Never include passwords, hashes that act as credentials, share/reset tokens,
  remember cookies, CSRF values, OAuth secrets, DSNs, raw SQL, raw database
  exception messages, or filesystem paths.

`admin_security_schema_health_statuses()` is the shared Admin registration for
converted security/authentication capabilities. Runtime Diagnostics and dashboard
System Health must consume the same bounded models. Add focused available/missing/
unknown tests for any policy change and keep `tests/run.php` green.

Phase 10 converts destructive and ingestion workflows through
`app/services/mutation_schema_policy.php`. Preserve these mutation rules:

- preflight the complete required schema **before the first irreversible target
  mutation**, including filesystem moves/copies/deletes, credential creation,
  database row deletion, migration target writes, derivative replacement, repair
  DDL, or replacement of active application files;
- `available` preserves the existing operation, `missing` may use only an
  explicitly documented compatibility path or a migration/repair bootstrap, and
  `unknown` must refuse or pause the mutation;
- never use `db_table_exists()` or `db_column_exists()` as authorization to skip
  destructive cleanup, ingestion registration, token handling, or maintenance.
  Those legacy boolean helpers cannot distinguish confirmed absence from an
  inspection failure;
- optional cleanup/metadata columns may be skipped only after a three-state probe
  confirms they are missing. Unknown optional dependencies must stop the operation
  before a partial mutation can be committed;
- token revocation is intentionally narrower than token issuance/authentication.
  Revocation should remain possible whenever the identity and revocation columns
  required to disable the credential are verified, even if unrelated optional or
  authentication columns are missing;
- browser/classic upload, WebDAV PUT, and gallery migration must establish both
  core ingestion readiness and thumbnail-metadata write readiness before source or
  derivative files are committed to the target gallery. A prepared ZIP, WebDAV
  request body, or resumable migration job should remain recoverable on refusal;
- thumbnail reads may retain documented legacy file-only behavior, but generation,
  metadata repair, variant deletion, and bulk thumbnail deletion must stop on an
  unknown metadata schema before derivative files are changed;
- database cleanup/repair and updater activation may proceed through a confirmed
  missing `schema_migrations` state only where the workflow itself can safely
  bootstrap or apply migrations. Unknown metadata state still blocks the mutation;
- update source validation must require both `schema_inspection.php` and
  `mutation_schema_policy.php` before active application files can be replaced;
- mutation refusal logs use `database.mutation_schema_refused` with only bounded
  capability, state, operation, affected database-object identifiers, and ordinary
  safe request correlation. Never log raw SQL, PDO messages, database credentials,
  API keys, WebDAV secrets, upload paths, migration source paths, or update staging
  paths;
- `admin_mutation_schema_health_statuses()` is the shared System Health and Runtime
  Diagnostics registration for the ten converted Phase 10 capability groups. Keep
  those surfaces synchronized with the policy service and preserve visible Action
  state for `missing` or `unknown` results.

`tests/mutation_schema_policy_test.php` is the focused Phase 10 contract. Extend it
whenever a converted mutation gains a new schema dependency or compatibility path.
Keep optional presentation/reporting policy separate from destructive mutation policy.

Phase 11 optional presentation/reporting callers use `app/services/presentation_schema_policy.php`. Preserve these final rules:

- read-only optional presentation may be omitted for confirmed `missing` schema and may also be omitted for `unknown` only when omission cannot expose protected data, weaken access policy, or authorize a persistent mutation; `unknown` must emit bounded diagnostics and appear in System Health;
- optional-feature writes must never use `unknown` as proof of absence. Use `presentation_schema_assert_write_available()` when the write requires the capability, or `presentation_schema_assert_known()` only for an audited compatibility path where confirmed `missing` safely means no optional persistence;
- GPS/EXIF maps, flight maps/navigation data, voting, Picture Game, lightbox overrides, OpenAI settings, AI image metadata, SimBrief route-map persistence, navigation account/cache storage, telemetry reporting, and the complete Admin report each have named capability status functions; do not collapse them into a generic all-features-ready boolean;
- Picture Game GET state records displayed pairs, so its pair-selection route is a write boundary even though it renders a page. Voting, Picture Game results, telemetry settings/maintenance, navigation account persistence, AI queue changes, and similar state changes require conclusive schema policy;
- gallery creation/import must verify voting storage before enabling voting, and must not silently discard an explicit or metadata-organizer-inherited lightbox override when its schema state is `unknown`;
- metadata-organizer capture-date readiness uses structured inspection of `images.exif_taken_at`; do not restore the legacy boolean column probe;
- navigation token disconnect/revocation may use a narrower verified account-deletion capability, while account creation/token persistence requires the complete account schema;
- SimBrief draft generation remains independent from optional flight-route persistence. Missing/unknown route-map schema may omit only the map write, not the remote draft itself;
- Admin gallery-report schema discovery uses structured named-object inspection for known report dependencies. Its direct `information_schema.TABLES` query is the intentional exception because the report must dynamically enumerate every base table rather than ask whether one known object exists; keep its output generic on query failure;
- System Health presentation definitions must stay lazy. Evaluate a real feature flag first and report `disabled` without metadata probes when the feature is off. Enabled capability resolvers share the request-local schema cache;
- `database.presentation_schema_degraded` may contain only bounded capability/state/operation and validated affected-object names. Never include SQL, raw database exceptions, DSNs, passwords, OAuth/API tokens, cookies, CSRF values, reset/share tokens, or private paths;
- retain the final regression contract in `tests/presentation_schema_policy_test.php`, including the ten-probe first-use voting budget, zero-query lazy registry construction, exact capability registry, source-boundary audits, and redaction checks.

The schema-inspection reliability roadmap is complete. Permanent behavior belongs in `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `README.md`, `TESTING.md`, release notes, and the administrator manual. Do not recreate a temporary phased roadmap in release packages.
