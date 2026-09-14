# TEMP: Strict MVC Refactor Plan

Status: implementation in progress  
Source snapshot: `php-gallery-deploy(20260913-143931).zip`  
Target: PHP Gallery plain-PHP architecture, no framework and no Composer requirement  
Scope: application PHP structure, dependency direction, database access, HTTP handling, rendering, and regression boundaries

## Implementation checkpoint: 2026-09-13

Implementation status after the twelfth checkpoint:

- Stage 0 implemented: strict MVC documentation, executable boundary checker, reviewed legacy baseline, audit integration, and MVC contract tests.
- Stage 1 implemented: public search no longer constructs SQL in services; the search view consumes a controller-prepared view model and no longer performs feature-policy lookup.
- Stage 2 completed: all feature-specific SQL/PDO/database access has been removed from `app/controllers/` and moved behind semantic model/service boundaries.
- Stage 3 completed: feature controllers no longer own HTML/XML presentation markup. HTML pages, fragments, cards, forms, standalone reports, WebDAV XML, shared HTTP fragments, and Admin workspaces migrated during this stage now render through `app/views/`.
- The final Stage 3 sweep completed Smart Galleries, viewer lifecycle/collections/sharing/favourites, gallery discovery, uploads and upload automation, telemetry and maintenance, Gallery Editor components, tags, metadata organizer, public inline editing, Mobile WebDAV, integrity UI, SimBrief tooling, shared HTTP helpers, Admin gallery renderers, Admin Logs fragments, and the standalone telemetry export.
- `tests/stage3_controller_presentation_boundary_test.php` now scans every PHP controller token stream and rejects inline HTML/XML or markup-bearing string literals. This closes the gap where legacy source checks could miss markup assembled into return strings rather than emitted by `echo`.
- The standalone telemetry export is now presentation-owned by `app/views/admin_telemetry.php`. The controller prepares anonymous aggregates and policy state only. A generic `Gallery\Core\format_bytes()` helper centralizes presentation-safe byte formatting while `telemetry_format_bytes()` remains a compatibility wrapper.
- Shared public 503, 404, back-to-top, noindex, and gallery-background head fragments now live in `app/views/http.php`; request/status/header selection remains in `app/controllers/http_helpers.php` and route controllers.
- `app/controllers/admin_gallery_renderers.php` now prepares gallery/tag/select data and delegates markup to `app/views/admin_gallery_renderers.php`, preserving existing compatibility function names for current callers.
- Admin Logs legacy-row/pagination HTML is view-owned. Existing log persistence, archive maintenance, download and mutation behavior remains outside the view.
- Source-contract regressions that intentionally inspected historical controller markup were updated to verify both sides of the new MVC boundary: controller-prepared state/security behavior plus view-owned markup.
- Semantic controller markup sweep is now zero across `app/controllers/`, including markup hidden in returned strings that the original boundary checker did not classify.
- MVC legacy baseline reduced from 4,915 to 2,591 exact occurrences with no newly accepted violations, a net removal of 2,324 legacy boundary violations. This final Stage 3 checkpoint removed another 614 reviewed baseline entries from the previous 3,205 baseline.
- Stage 2 acceptance remains met: executable direct DB/PDO/SQL access is absent from `app/controllers/`.
- Stage 3 acceptance is met: controllers select response formats and prepare view models, while HTML/XML presentation is owned by views. Plain-text, JSON, redirects, headers, and binary transport responses remain controller responsibilities where appropriate.

Stage 4 is complete. The first persistence extraction checkpoint moved 148 reviewed service-layer DB/PDO/SQL occurrences into cohesive model APIs, reducing the MVC legacy baseline from 2,591 to 2,443. The eleventh checkpoint then removed another 412 occurrences through telemetry, upload automation, application settings, tag metadata, gallery mutations, gallery trash, and viewer rate-limit persistence, reducing the baseline to 2,031.

The twelfth checkpoint closes the remaining Stage 4 gallery/image persistence boundary. Public path lookup/regeneration, gallery access-token writes, Picture Manager database copy operations, duplicate-photo detector reads, and EXIF/GPS map persistence now use cohesive model APIs. Filesystem copying, rollback intent, visibility/access policy, Smart Gallery graph validation, request-local caching, and schema fail-closed decisions remain in services. `tests/stage4_gallery_image_persistence_boundary_test.php` permanently protects the primary Stage 4 service inventory against reintroducing SQL literals, direct `db()` calls, or PDO methods.

This checkpoint also extracts Picture Game and thumbnail-metadata persistence opportunistically because their database boundaries were already isolated. Thumbnail request caches, geometry/filesystem validation, schema policy, and upload orchestration remain service-owned; the model accepts only fixed semantic arguments and allowlisted dynamic columns. The reviewed MVC baseline is reduced from 2,031 to 1,858, a further reduction of 173 occurrences and a total reduction of 3,057 from the original 4,915 baseline.

Stage 5 is complete. Smart Gallery definition/placement persistence, relationship-graph source reads, rule-to-query compilation, paginated result queries, card-summary batching, and download membership re-authorization now live in `app/models/smart_galleries.php`; the service retains rule validation, access/NSFW policy, cycle detection, request caching, ranking/presentation policy, and orchestration. SQL fragments no longer cross the Smart Gallery service boundary. Content localization, Theme favorite-gallery lookup, and viewer-favourite persistence were also moved behind semantic model APIs. Viewer-favourite source-image authorization, account mutation policy, security-version checks, and quota selection remain service-owned while the model owns the account row lock and atomic favourite mutation. Existing vote, tag, and Picture Game model boundaries remain green. `tests/stage5_relational_features_persistence_boundary_test.php` permanently rejects SQL/PDO reintroduction and Smart Gallery SQL-fragment APIs in the Stage 5 services.

The reviewed MVC baseline is reduced from 1,858 to 1,720, a further reduction of 138 occurrences and a total reduction of 3,195 from the original 4,915 baseline. The next implementation checkpoint proceeds with Stage 6 viewer/auth identity persistence, preserving token hashing, authorization policy, constant-time comparisons, transaction/lock semantics, cookie ownership, and request/session boundaries.

Stage 6 partial checkpoint: Admin authentication throttle and remember-login persistence, viewer account/session primitives, viewer authentication, viewer token persistence, viewer security-event persistence, source-image authorization, and the full viewer registration/invitation/verification workflow now use semantic model APIs. Cookie, request, user-agent, and PHP session transport ownership is centralized behind the narrow Core identity-context adapter rather than being accessed directly by auth/viewer services. Token generation, hashing, constant-time verification, registration/invitation policy, password policy, rate-limit decisions, account authorization, and security-event policy remain service-owned. Registration row locks, durable request capacity, resend authority, invitation claims, staged verification, and final account activation preserve their existing transactional and compare-and-set semantics in `app/models/viewer_registration.php`. Source-contract tests now verify the two sides of the boundary separately: service policy/orchestration and model persistence/locking.

The reviewed MVC baseline is reduced from 1,720 to 1,238 for this Stage 6 partial checkpoint, removing another 482 reviewed occurrences and 3,677 occurrences in total from the original 4,915 baseline. Stage 6 remains in progress: viewer lifecycle, collections, collection shares, security operations, maintenance, and any remaining viewer/auth persistence or HTTP-boundary debt must still be migrated before the Stage 6 completion contract is enabled.

Stage 6 completion checkpoint: viewer lifecycle, collections, collection shares, security operations, and maintenance now also use semantic model APIs. Viewer/auth services no longer own SQL/PDO or direct PHP request/session/cookie transport. Lifecycle password/email/delete transactions, collection ownership/quota/reorder locks, reusable collection-share capability validation, security-operations aggregates, and bounded maintenance cleanup retain their existing service policy and model atomicity. `tests/stage6_identity_auth_boundary_test.php` now enforces the completed identity/auth boundary and all viewer regression tests pass.

The reviewed MVC baseline is reduced from 1,238 to 1,012 for the Stage 6 completion checkpoint, removing another 226 reviewed occurrences in this continuation. Stage 6 removes 708 occurrences in total from its 1,720 starting baseline, and the project has removed 3,903 occurrences from the original 4,915 reviewed baseline. Stage 6 is complete; the next implementation checkpoint proceeds with Stage 7 upload, thumbnail, AI metadata, media, download, and automation boundaries.

## 1. Objective

Move the existing application from a mixed procedural architecture toward a strict, enforceable MVC structure without changing public behavior, URLs, database semantics, Admin workflows, WinApp/API contracts, updater behavior, or shared-hosting compatibility.

The refactor must reuse the existing layer split instead of introducing a second architecture:

- `app/models/`
- `app/services/`
- `app/controllers/`
- `app/views/`
- existing bootstrap, routing, database, migration, helper, and feature-policy infrastructure

The current public-search implementation is the closest existing reference, but it is not yet the final strict-MVC target and must itself be tightened.

This is a structural refactor. It must be implemented in small stages with regression tests and affected-file ZIP checkpoints after each stage.

## 2. Non-goals

This refactor must not:

- add Laravel, Symfony, Slim, Doctrine, Twig, Composer, or another framework;
- convert the codebase to a class-heavy architecture only for architectural purity;
- replace the current function-based module style where functions remain appropriate;
- redesign URLs, routing semantics, feature flags, authentication behavior, Admin UX, public UX, gallery storage, migrations, or API contracts unless required to preserve a clean boundary;
- rewrite stable working subsystems from scratch;
- rename public route names or browser-facing JSON fields without a separate compatibility requirement;
- delete comments or docstrings;
- move SQL mechanically without understanding transaction ownership, locking, schema fallbacks, or failure semantics;
- create duplicate helpers where a suitable existing helper can be centralized or extended.

## 3. Target architecture contract

### 3.1 Allowed dependency direction

The canonical request flow shall become:

```text
Bootstrap / Router
       |
       v
   Controller -------> View
       |
       v
    Service
       |
       v
     Model
       |
       v
 Core DB / filesystem primitives
```

Additional rules:

- Controllers may call services and views.
- Controllers may call narrowly scoped Core HTTP helpers.
- Services may call models, other services, and pure Core helpers.
- Models may call Core database primitives and pure value/path helpers only.
- Views may call presentation-only helpers such as escaping, translation lookup, and URL generation.
- Views must not call models.
- Views must not execute domain services or mutate state.
- Models must never call services, controllers, or views.
- Services must never call controllers or views.
- No upward dependency may be hidden through dynamic function names or generic helper wrappers.

### 3.2 Model responsibilities

`app/models/` owns all application data access.

Allowed:

- SQL strings and SQL fragment construction;
- PDO `prepare`, `query`, `exec`, transactions, row fetching, and persistence;
- database-specific compatibility branches;
- schema-facing reads and writes when they are part of application persistence;
- query-specific mapping of raw rows into stable data arrays;
- data-access helpers shared by multiple services.

Forbidden:

- `$_GET`, `$_POST`, `$_REQUEST`, `$_FILES`, cookies, or HTTP headers;
- HTML rendering;
- redirects;
- feature-page presentation decisions;
- calling service functions;
- user-facing translated messages except data values that are genuinely stored/retrieved.

A model function should receive semantic arguments, not SQL produced by a caller. For example, use `?array $galleryScope` or `?int $galleryId`, not `$whereSql` or `$listingCondition` supplied by a service.

### 3.3 Service responsibilities

`app/services/` owns application use cases and reusable domain policy.

Allowed:

- validation that is independent of HTTP transport;
- authorization policy when given actor/context data;
- orchestration across multiple models;
- transaction intent, while the actual PDO transaction primitive should be exposed through model/data-access functions or a dedicated persistence boundary;
- ranking, sorting, localization orchestration, feature policy, image-processing decisions, filesystem workflows, and domain state transitions;
- returning structured result arrays or documented DTO-like arrays.

Forbidden in the final state:

- SQL strings;
- direct `db()` or PDO access;
- reading request globals;
- emitting headers or status codes;
- `setcookie()`;
- rendering HTML;
- direct redirects;
- choosing a browser template based on raw request state.

Services should not know whether their caller is a normal HTML controller, JSON endpoint, CLI script, maintenance runner, or WinApp/API entry point unless that distinction is itself a domain input.

### 3.4 Controller responsibilities

`app/controllers/` owns the HTTP boundary.

Allowed:

- reading route params, `$_GET`, `$_POST`, `$_FILES`, and relevant server request values;
- request-shape validation and normalization;
- authentication and CSRF boundary checks;
- calling services;
- selecting a view;
- selecting JSON, text, binary, redirect, or error response format;
- setting HTTP status, headers, cookies, and cache policy through shared controller helpers where practical;
- preparing a view-model array from service output.

Forbidden:

- SQL or PDO access;
- database transactions;
- reusable domain/business rules;
- large HTML fragments;
- gallery/image persistence logic;
- filesystem mutation that belongs to a domain service.

HTML controllers should be thin. JSON and binary endpoints may serialize or stream a response directly, but reusable response mechanics should be centralized in existing controller HTTP helpers instead of copied across endpoints.

### 3.5 View responsibilities

`app/views/` owns HTML presentation.

Allowed:

- HTML markup;
- escaping;
- translation lookup;
- URL generation;
- small presentation-only formatting;
- conditional markup based on already prepared view-model values.

Forbidden:

- SQL/PDO;
- request globals;
- session inspection;
- feature-policy decisions that require services;
- persistence;
- authorization decisions;
- redirects, status codes, or response headers;
- filesystem mutation;
- expensive queries hidden in rendering helpers.

A view should ideally receive one documented associative array describing everything required to render the fragment/page.

### 3.6 Core and infrastructure exceptions

Strict MVC does not mean forcing bootstrapping and infrastructure into fake domain models.

The following remain legitimate infrastructure boundaries:

- `app/database.php` for connection creation and low-level DB primitives;
- `app/bootstrap/*` for startup, routing, request lifecycle, session initialization, and dispatch;
- `database/migrations/*` and migration runner infrastructure;
- CLI scripts in `scripts/`;
- pure helpers that do not own feature business logic.

However, feature-specific persistence must not use "infrastructure" as an escape hatch. If a module queries `galleries`, `images`, `users`, `tags`, telemetry rows, logs, viewer accounts, feature state, or another application table, that query belongs in a model module even when the caller is a maintenance or diagnostics service.

## 4. Audit of the current snapshot

The repository already documents MVC as a desired direction in `ARCHITECTURE.md`, and public search already has explicit model, service, controller, and view files plus `tests/public_search_mvc_boundaries_test.php`. This is a useful base.

The current implementation is still heavily mixed.

### 4.1 Layer size

Mechanical snapshot from the current PHP tree:

| Layer | PHP files | Approx. lines |
| --- | ---: | ---: |
| `app/models/` | 4 | 1,170 |
| `app/services/` | 185 | 101,367 |
| `app/controllers/` | 86 | 37,547 |
| `app/views/` | 22 | 7,007 |

The very small model layer compared with the service layer reflects that most existing service modules still contain persistence logic.

### 4.2 Direct DB/PDO use in controllers

The current snapshot contains direct DB/PDO access in 16 controller files, with approximately 110 direct DB/PDO call sites:

- `app/controllers/admin_auth.php`
- `app/controllers/admin_galleries_bulk.php`
- `app/controllers/votes.php`
- `app/controllers/admin_galleries_reorder.php`
- `app/controllers/admin_gallery_renderers.php`
- `app/controllers/admin_images_bulk.php`
- `app/controllers/admin_media_renamer.php`
- `app/controllers/admin_galleries_edit_actions.php`
- `app/controllers/admin_public_inline.php`
- `app/controllers/public_gallery_controls.php`
- `app/controllers/admin_theme_actions.php`
- `app/controllers/admin_images_reorder.php`
- `app/controllers/picture_manager.php`
- `app/controllers/public_gallery_home.php`
- `app/controllers/setup.php`
- `app/controllers/smart_galleries.php`

Examples include direct `UPDATE galleries`, `UPDATE images`, image vote CRUD, user/password-reset persistence, gallery reorder queries, gallery selection queries, and direct Admin log insertion.

These are hard MVC violations and should be eliminated early because controllers are currently bypassing both service orchestration and the model layer.

### 4.3 DB/PDO use in services

The current snapshot contains direct DB/PDO use in 84 service files, approximately 1,240 direct DB/PDO call sites.

Largest persistence-heavy service modules include:

- `app/services/viewer_registration.php`
- `app/services/tag_metadata.php`
- `app/services/smart_galleries.php`
- `app/services/gallery_trash.php`
- `app/services/logs.php`
- `app/services/viewer_accounts.php`
- `app/services/gallery_mutations.php`
- `app/services/viewer_lifecycle.php`
- `app/services/ai_image_analysis.php`
- `app/services/downloads.php`
- `app/services/viewer_authentication.php`
- `app/services/viewer_collections.php`
- `app/services/viewer_tokens.php`
- `app/services/public_paths.php`
- `app/services/telemetry.php`
- `app/services/upload_automation.php`
- `app/services/picture_game.php`
- `app/services/thumbnail_metadata.php`
- `app/services/viewer_collection_shares.php`
- `app/services/auth_persistence.php`
- `app/services/gallery_lookup.php`
- `app/services/navigation_data.php`
- `app/services/image_scanning.php`
- `app/services/media_renamer.php`
- `app/services/thumbnail_maintenance.php`
- `app/services/flight_maps.php`
- `app/services/database_maintenance.php`
- `app/services/gallery_metadata_organizer.php`
- `app/services/viewer_rate_limits.php`

This is the largest part of the refactor. It must be migrated domain by domain rather than by blindly moving all SQL strings at once.

### 4.4 Presentation inside controllers

A mechanical markup scan finds HTML/presentation output in 72 controller files. Some hits are legitimate JSON serialization or tiny response snippets, so the count is not itself a defect count, but the large controller modules clearly combine request handling and view rendering.

High-volume examples:

- `app/controllers/viewer_accounts.php`
- `app/controllers/updates.php`
- `app/controllers/admin_auth.php`
- `app/controllers/admin_logs.php`
- `app/controllers/admin_telemetry.php`
- `app/controllers/admin_media_renamer.php`
- `app/controllers/public_gallery_controls.php`
- `app/controllers/admin_tags.php`
- `app/controllers/admin_theme_layout.php`
- `app/controllers/upload_automation.php`
- `app/controllers/admin_theme_appearance.php`
- `app/controllers/admin_galleries_discovery.php`
- `app/controllers/smart_galleries.php`
- `app/controllers/admin_theme_language.php`
- `app/controllers/admin_theme_media.php`
- `app/controllers/admin_galleries_edit_views.php`
- `app/controllers/public_gallery_page.php`
- `app/controllers/viewer_collections.php`
- `app/controllers/viewer_lifecycle.php`

The names `admin_gallery_renderers.php` and `admin_galleries_edit_views.php` inside the controller layer are themselves signs that view responsibilities have accumulated in the wrong layer.

### 4.5 Request state inside views

Current views directly inspect request globals in at least:

- `app/views/layout.php`
- `app/views/admin_dashboard.php`
- `app/views/admin_dashboard_sections.php`

Examples include reading the current `page`, `maintenance_tab`, and `REQUEST_URI` during rendering. These values should be prepared by the controller and passed to the view.

No direct DB/PDO access was found in the current `app/views/` tree, which is already a useful invariant to preserve.

### 4.6 HTTP behavior inside services

At least 14 service modules directly emit headers/status/cookies, including:

- `app/services/download_artifact_cache.php`
- `app/services/feature_flags/routes.php`
- `app/services/seo_request_guard.php`
- `app/services/logs.php`
- `app/services/downloads.php`
- `app/services/translations.php`
- `app/services/admin_test_runs/lifecycle.php`
- `app/services/browser_thumbnail_rebuild.php`
- `app/services/admin_test_runs/context.php`
- `app/services/download_manifest_cache.php`
- `app/services/viewer_http.php`
- `app/services/auth_persistence.php`
- `app/services/gallery_benchmark.php`
- `app/services/telemetry.php`

The final design should move transport behavior to controllers or existing controller HTTP helpers while keeping domain decisions in services.

### 4.7 HTML rendering inside services

At least 12 service areas contain HTML/presentation behavior. Notable examples:

- `app/services/admin_gallery_report/render.php`
- `app/services/public_render_profiler.php`
- `app/services/thumbnail_bounds.php`
- `app/services/admin_render_profiler.php`
- `app/services/pagination.php`
- `app/services/admin_test_runs/panel.php`
- `app/services/admin_log_archives.php`
- `app/services/feature_flags/routes.php`
- `app/services/gallery_description_layout.php`
- `app/services/updates_patch_notes.php`

Rendering should move to view modules. Services should return data structures needed by those views.

### 4.8 Public search is a good reference, but not yet strict enough

The current search split is significantly better than older features:

- models own actual PDO execution;
- service owns search orchestration/ranking;
- controller owns the JSON endpoint;
- view owns search-bar markup;
- a dedicated regression test protects several boundaries.

Two remaining issues should be corrected before treating search as the canonical template:

1. `app/services/public_search.php` contains `public_search_context_listing_sql_fragment()` and constructs SQL conditions that are passed into model functions. SQL construction belongs entirely in the model. The service should pass semantic scope data.
2. `app/views/public_search.php` calls `public_home_search_enabled()`. The view should receive an already prepared presentation model and should not make a service-level feature decision itself.

The strict-MVC migration should therefore use an improved search implementation as the golden reference, not copy the current implementation verbatim.

## 5. Architectural enforcement strategy

The migration must prevent new violations before old ones are all removed.

A single all-or-nothing MVC test would fail for months and provide no protection. Instead, introduce a repository-wide boundary checker with a temporary explicit baseline.

### 5.1 New boundary checker

Add a checker such as `scripts/check_mvc_boundaries.php` that tokenizes PHP source and detects forbidden dependencies by layer.

Do not rely only on regexes. Use `token_get_all()` where possible to distinguish source from comments/docblocks and reduce false positives.

Minimum rules:

#### Models

Fail on:

- request superglobals;
- `header`, `http_response_code`, `setcookie`;
- HTML output;
- `Gallery\Services`, `Gallery\Controllers`, or `Gallery\Views` dependency.

#### Services

Fail on:

- `db()` and direct PDO methods;
- SQL statement literals or SQL-fragment APIs;
- request superglobals;
- `header`, `http_response_code`, redirects, cookie output;
- direct HTML rendering;
- `Gallery\Controllers` or `Gallery\Views` dependency.

#### Controllers

Fail on:

- `db()` or PDO methods;
- SQL literals;
- filesystem/domain mutation not delegated to a service, except explicitly classified transport primitives;
- large HTML output.

JSON encoding, header output, redirects, and binary streaming remain allowed at the controller boundary.

#### Views

Fail on:

- DB/PDO;
- request/session globals;
- models;
- mutating service calls;
- response headers/status/cookies;
- filesystem mutation.

### 5.2 Temporary baseline

Because the existing code has many violations, the first checker version should record a reviewed baseline file containing current known violations.

Rules:

- any new violation not in the baseline fails immediately;
- touched/migrated files must have their baseline entries removed;
- baseline count must only decrease;
- new baseline entries require an explicit architecture justification and should normally be rejected;
- the final stage deletes the baseline and switches to zero-tolerance enforcement.

This makes MVC a live invariant from Stage 0 instead of merely a future objective.

### 5.3 Integration

The checker should run from:

- `scripts/audit.php`;
- release validation;
- the main PHP test runner where practical;
- any existing repository contract/audit workflow used before release.

Add a focused `tests/mvc_layer_contract_test.php` for deterministic architecture assertions.

## 6. Migration stages

Each stage below should be independently releasable and should end with:

1. focused tests;
2. full PHP regression tests;
3. JS tests where affected;
4. PHP syntax checks;
5. MVC boundary checker;
6. updater/release checks when loaders/manifests change;
7. affected-files ZIP checkpoint.

### Stage 0: Freeze the architecture rules and stop new leakage

Goal: make the intended architecture enforceable before moving large amounts of code.

Tasks:

- document the strict layer contract in `ARCHITECTURE.md`;
- update `AGENTS.md` so coding agents must respect the contract;
- update `CODEMAP.md` with the canonical dependency direction;
- add the token-based MVC boundary checker;
- generate and review the initial baseline from the current repository;
- wire the checker into `scripts/audit.php` and release checks;
- add `tests/mvc_layer_contract_test.php`;
- ensure the checker understands valid infrastructure exceptions instead of broad directory exemptions;
- make SQL-in-controller, SQL-in-view, DB-in-view, and model-upward-dependency violations hard failures for newly introduced code immediately.

Acceptance:

- no runtime behavior changes;
- existing tests remain green;
- adding a new `db()->prepare()` to a controller fails the checker;
- adding SQL to a service outside the reviewed baseline fails the checker;
- adding `$_GET` to a view fails the checker;
- adding a service import to a model fails the checker.

### Stage 1: Finish public search as the canonical strict-MVC example

Goal: create one complete, modern feature slice that future refactors can copy safely.

Tasks:

- move `public_search_context_listing_sql_fragment()` and all SQL-fragment generation from `app/services/public_search.php` into the search model layer;
- change model APIs to accept semantic scope inputs instead of SQL fragments;
- keep wildcard/query normalization in the most appropriate pure layer, but do not pass SQL syntax upward;
- make the controller prepare a search-bar view model containing enabled state, URL, ids, labels, phase timings, and context state;
- change `app/views/public_search.php` so it renders only that view model and no longer calls `public_home_search_enabled()`;
- strengthen `tests/public_search_mvc_boundaries_test.php` to prohibit SQL-fragment construction in services and service-policy lookup from the view;
- use this feature in documentation as the reference vertical slice.

Acceptance:

- search behavior, progressive phases, ranking, localization, diagnostics, and browser contract remain unchanged;
- no SQL syntax exists in public-search service files;
- no service call is required by the search view except translation/escaping/URL primitives explicitly classified as presentation helpers;
- all search tests pass.

### Stage 2: Remove direct database access from controllers

Goal: eliminate the most severe layer bypass before larger service cleanup.

Create or extend model APIs for every current direct controller query.

Suggested domain split:

- `app/models/auth.php` for Admin user/password-reset persistence currently in `admin_auth.php` and setup-related user persistence;
- `app/models/galleries.php` for gallery CRUD, visibility, cover, sort/reorder, access-token lookup, and gallery list reads;
- `app/models/images.php` for image metadata, visibility, NSFW flags, reorder, and image-count queries;
- `app/models/votes.php` for vote reads/writes/deletes;
- `app/models/logs.php` for log persistence currently performed directly by Admin media renamer;
- `app/models/smart_galleries.php` for Smart Gallery DB reads that still originate in controllers.

Do not introduce one model function for every SQL statement blindly. Group operations by domain and stable intent.

Controllers should call existing services where a matching use case already exists. If a controller currently contains both SQL and domain behavior, move the use case into a service and let that service call the new model.

Priority order:

1. `votes.php` and reorder controllers because their persistence surface is narrow;
2. `admin_galleries_bulk.php` and `admin_images_bulk.php`;
3. `admin_galleries_edit_actions.php` and `admin_public_inline.php`;
4. `public_gallery_controls.php` and `public_gallery_home.php`;
5. `admin_gallery_renderers.php` data loading;
6. `admin_media_renamer.php` direct log persistence;
7. `smart_galleries.php`;
8. `setup.php`;
9. `admin_auth.php`, with extra care around reset-token invalidation and transactions.

Acceptance:

- zero `db()`, PDO query/prepare/exec, or SQL strings remain in `app/controllers/`;
- controller DB baseline is empty;
- all transactional semantics and affected-row expectations are preserved;
- no new duplicate persistence helper is introduced when an existing service/model can be generalized.

### Stage 3: Extract controller HTML into views, starting with narrow features

Goal: controllers select/render views instead of building page markup themselves.

Start with smaller, isolated surfaces before the largest account/update/admin pages.

First wave:

- votes UI fragments where applicable;
- public tags;
- picture game;
- public gallery lightbox fragments;
- Admin features;
- Admin diagnostics;
- gallery date controls;
- small Admin gallery edit tabs.

For each page/fragment:

1. identify request handling and service calls;
2. prepare a documented `$viewModel` array in the controller;
3. move markup into `app/views/<domain>.php`;
4. keep only response selection and view invocation in the controller;
5. remove any service/database lookup from the new view.

Do not create a generic mega-template engine. Keep normal PHP view functions consistent with the current project.

Acceptance:

- migrated controllers contain no page markup beyond tiny transport-safe JSON/text response payloads;
- migrated views require no request globals;
- browser output remains byte-compatible where practical, semantically compatible otherwise.

### Stage 4: Gallery and image domain persistence extraction

Goal: make the core gallery/image domain follow Controller -> Service -> Model consistently.

Primary service modules to migrate:

- `gallery_mutations.php`
- `gallery_lookup.php`
- `gallery_trash.php`
- `gallery_access.php`
- `gallery_covers.php`
- `gallery_backgrounds.php`
- `gallery_branding.php`
- `gallery_dates.php`
- `gallery_grid.php`
- `gallery_picker.php`
- `gallery_sidecars.php` for DB portions only;
- `public_paths.php` for DB lookup portions;
- `image_scanning.php`
- `gallery_metadata_organizer.php`
- `picture_manager.php`
- `duplicate_photo_detector.php`
- `duplicate_photo_ledger.php`
- `exif.php` for DB persistence portions.

Suggested model modules may be split by cohesive data ownership rather than one-to-one with services:

- galleries;
- images;
- gallery access/security metadata;
- gallery trash;
- duplicate ledger;
- image EXIF/metadata.

Filesystem operations remain service responsibilities unless they are low-level filesystem primitives. Do not put image file movement into database models merely to satisfy a directory rule.

Transactions spanning DB and filesystem need explicit orchestration and rollback documentation in services, with DB actions delegated to model functions.

Acceptance:

- migrated gallery/image services contain no SQL/PDO;
- gallery mutation, trash, restore, bulk edit, move/copy, sidecar, and scan regression tests remain green;
- public paths and access behavior remain unchanged.

### Stage 5: Tags, Smart Galleries, localization, favourites, votes, and picture-game domains

Goal: migrate relational feature data with relatively clear boundaries.

Primary modules:

- `tag_metadata.php`
- `smart_galleries.php`
- `content_localization.php`
- `favorite_galleries.php`
- `viewer_favourites.php`
- `votes.php`
- `picture_game.php`
- related gallery/tag presentation helpers.

Create cohesive models for:

- tags and tag relations;
- Smart Gallery definitions/rules/state;
- localization rows;
- favourites;
- votes;
- picture-game persistence.

Service functions should keep rule evaluation and ranking. Models should only retrieve/persist data requested by semantic operations.

Acceptance:

- no SQL in migrated services;
- no service receives or returns SQL fragments;
- existing tag, Smart Gallery, localization, favourite, vote, and picture-game tests remain green.

### Stage 6: Viewer and authentication domain

Goal: migrate the highest security-risk mixed domain carefully and completely.

Primary service modules:

- `viewer_registration.php`
- `viewer_accounts.php`
- `viewer_authentication.php`
- `viewer_lifecycle.php`
- `viewer_tokens.php`
- `viewer_collections.php`
- `viewer_collection_shares.php`
- `viewer_rate_limits.php`
- `viewer_admin_accounts.php`
- `viewer_content_foundations.php`
- `viewer_security_events.php`
- `viewer_security_operations.php`
- `viewer_maintenance.php`
- `auth_persistence.php`
- `auth_throttle.php`
- Admin authentication controller/service paths.

Suggested model domains:

- users/admin users;
- viewer accounts;
- viewer credentials and password state;
- viewer verification/reset/invitation/email-change tokens;
- viewer collections and collection shares;
- viewer rate-limit/security-event persistence;
- remember-me/auth persistence.

Critical rules:

- token hashing and verification remain service/security logic;
- SQL uniqueness/concurrency guarantees remain model/data-layer concerns;
- controllers own HTTP cookies and redirect/status behavior;
- service functions return cookie instructions or auth result data rather than calling `setcookie()` themselves;
- session state should be passed through a narrowly defined request/session adapter or controller-owned operations rather than arbitrary service access to `$_SESSION`;
- preserve constant-time comparisons and existing security hardening.

Acceptance:

- zero SQL/PDO in viewer/auth services;
- no `setcookie()` or request superglobals in viewer/auth services;
- all existing viewer security and lifecycle tests pass, including concurrency-focused tests.

Status: **Complete.** Protected by `tests/stage6_identity_auth_boundary_test.php` and the reviewed MVC baseline at 1,012 occurrences.

### Stage 7: Upload, thumbnail, AI metadata, media, download, and automation domains

Goal: separate persistence, filesystem/media orchestration, and HTTP streaming.

Primary modules:

- `uploads.php`
- `browser_uploads/*`
- `upload_automation.php`
- `thumbnail_metadata.php`
- `thumbnail_generation.php`
- `thumbnail_maintenance.php`
- `thumbnail_bounds.php`
- `ai_image_analysis.php`
- `downloads.php`
- `download_artifact_cache.php`
- `download_manifest_cache.php`
- `download_signatures.php`
- `media_renamer.php`
- `mobile_webdav.php`
- `flight_maps.php`
- `lightbox_metadata.php`
- `public_gallery_media_manifest.php`.

Separation rules:

- DB state and queue/cache metadata goes to models;
- file generation, hashing, ZIP assembly, EXIF parsing, thumbnail generation, and rename workflows remain services;
- actual HTTP streaming headers/status belong in controllers/controller HTTP helpers;
- services return stream descriptors such as path, filename, MIME type, length, cache policy, retry hints, or status intent;
- browser-assisted upload contracts and mutation envelopes must remain unchanged.

Acceptance:

- services can be exercised from CLI/tests without fabricating HTTP globals;
- download and thumbnail services do not emit headers directly;
- existing browser upload, download ZIP/ZIP64, thumbnail, AI, DNG, lightbox, and media tests pass.

### Stage 8: Logs, telemetry, diagnostics, reporting, and maintenance persistence

Goal: migrate operational subsystems without treating them as architectural exceptions.

Primary modules:

- `logs.php`
- `admin_log_archives.php`
- `telemetry.php`
- `telemetry_rollup.php`
- `telemetry_settings.php`
- `admin_dashboard.php`
- `admin_database_usage.php`
- `admin_storage_statistics.php`
- `admin_gallery_report/*`
- `admin_gallery_discovery.php`
- `database_maintenance.php`
- `database_helpers.php`
- `schema_inspection.php`
- `database_observer.php`
- `site_maintenance.php`
- relevant Admin test-run persistence.

Create model modules for operational DB access rather than leaving SQL in "service" because it is diagnostics or maintenance code.

DDL/schema repair may use dedicated model functions with explicit names documenting destructive or repair behavior.

Move report rendering from `app/services/admin_gallery_report/render.php` into `app/views/` and keep report data collection separate from presentation.

Acceptance:

- operational services contain no direct application-table SQL;
- maintenance and diagnostics remain usable on shared hosting;
- schema unknown/missing fail-closed semantics are unchanged;
- reporting output remains equivalent.

### Stage 8 completion checkpoint

Status: **Complete**

Completion results:

- operational persistence for logs, log archives, telemetry/reporting support, Admin dashboard, database/storage diagnostics, Gallery Discovery, Gallery Report, schema inspection, database observer support, Database Maintenance, and Site Maintenance is model-owned;
- `app/services/admin_gallery_report/render.php` is now a non-rendering compatibility marker and the self-contained report HTML lives in `app/views/admin_gallery_report_export.php`;
- report presentation formatters used only by the export view are owned by the view layer and the GPS clustering radius is passed through the report view model rather than imported from the service namespace;
- `tests/stage8_operational_persistence_boundary_test.php` prevents SQL/PDO ownership from returning to the Stage 8 service set and checks Gallery Report presentation ownership;
- the reviewed MVC baseline is reduced from 697 to 475, removing another 222 reviewed occurrences in Stage 8;
- the project has removed 4,440 reviewed occurrences from the original 4,915 baseline, leaving 475 contained legacy occurrences.

Stage 8 is complete. The next implementation checkpoint proceeds with Stage 9 updater and feature-policy HTTP boundary cleanup.

### Stage 9: Updater and feature-policy HTTP boundary cleanup

Goal: separate application policy from transport effects in modules that currently mix both.

Primary areas:

- `app/services/feature_flags/routes.php`;
- `app/services/seo_request_guard.php`;
- updater service modules that inspect request method or emit response behavior;
- translation language-cookie writes;
- admin test-run cookie/header behavior;
- remaining service-level headers/status/cookies.

Approach:

- service/policy functions return a decision object, for example `allowed`, `status`, `representation`, `cache_policy`, `reason`, and safe diagnostics context;
- controllers/bootstrap dispatch applies the HTTP response;
- language services return selected/effective language and cookie intent, while the controller/request boundary writes cookies;
- route capability policy remains centralized, but emitting 403/404 HTML/JSON moves to the HTTP boundary.

Acceptance:

- no feature service emits HTTP headers/status/cookies;
- dispatch/controller code remains the sole web transport owner except bootstrap infrastructure;
- all feature OFF-path, SEO, language, updater, and diagnostics tests remain green.

### Stage 10: Large controller presentation migration

Goal: finish extracting remaining HTML from the largest mixed controllers after their data/service boundaries are stable.

Priority pages:

- `viewer_accounts.php`;
- `admin_auth.php`;
- `updates.php`;
- `admin_logs.php`;
- `admin_telemetry.php`;
- `admin_media_renamer.php`;
- `public_gallery_controls.php`;
- `admin_tags.php`;
- Admin Theme controllers;
- `upload_automation.php`;
- `admin_galleries_discovery.php`;
- `smart_galleries.php`;
- viewer collections/lifecycle pages;
- Admin upload and thumbnail pages.

Consolidate misplaced rendering modules:

- move rendering responsibilities from `app/controllers/admin_gallery_renderers.php` to appropriate `app/views/` modules;
- move rendering responsibilities from `app/controllers/admin_galleries_edit_views.php` and `app/controllers/admin_galleries_edit_page/tab_*.php` into views where those files are actually presentation;
- retain controller wrapper functions temporarily where route/function compatibility requires them, but wrappers must only prepare/call views.

Acceptance:

- HTML presentation exists in `app/views/` rather than feature controllers;
- controller markup baseline reaches zero except explicitly approved tiny text/JSON/binary transport responses.

### Stage 11: Make views request-independent

Goal: make every view renderable from explicit input alone.

Tasks:

- remove `$_GET['page']` reads from `app/views/layout.php`;
- remove `$_GET['maintenance_tab']` from dashboard views;
- remove direct `$_SERVER['REQUEST_URI']` lookup from layout;
- pass current route/page, active Admin section/tab, request target URL, permissions, feature visibility, and current actor presentation data in a layout/view model;
- audit all view calls to services and classify them:
  - keep only pure presentation primitives;
  - move policy/domain lookups to controller/service preparation;
- ensure views never alter session or persistence state.

Acceptance:

- zero request/session globals in `app/views/`;
- zero DB/PDO in views;
- views can be unit-tested with synthetic view-model arrays.

### Stage 12: Split mixed helpers by responsibility

Goal: prevent generic helpers from becoming a new bypass around MVC rules.

Audit:

- `app/helpers*.php`;
- `app/controllers/http_helpers.php`;
- rendering helpers in services;
- mutation helpers;
- request helpers;
- path/URL helpers.

Specific cleanup:

- keep request/response mechanics in controller/Core HTTP helpers;
- move `render_back_to_top_button()` from controller HTTP helpers into a view module;
- make `cms_not_found()` a controller that calls a not-found view rather than embedding HTML;
- keep escaping/URL generation as pure presentation-safe helpers;
- keep mutation-envelope construction separate from actual response emission where possible;
- do not create catch-all `common.php` files that erase ownership boundaries.

Acceptance:

- helper modules have one clear architectural role;
- the MVC checker treats helpers based on declared category, not as a blanket exemption.

### Stage 13: Loader and dependency cleanup

Goal: make module loading reflect the final layer order and make upward dependencies impossible to hide.

Target order:

1. Core/bootstrap primitives;
2. models;
3. services;
4. views;
5. controllers;
6. route dispatch.

Tasks:

- verify `app/models.php` contains every model module;
- verify models do not depend on services being preloaded;
- keep `app/services.php` loading models before services until loaders can be cleanly separated by bootstrap;
- verify `app/views.php` loads only view modules;
- verify `app/controllers.php` loads only controllers;
- remove compatibility includes that load a wrong layer from inside another feature file;
- detect cycles with the MVC checker or a small namespace/use dependency graph test.

Acceptance:

- no Model -> Service -> Model cycles;
- no Service -> View/Controller dependencies;
- no View -> Model dependencies;
- bootstrap order is documented and deterministic.

### Stage 14: Remove compatibility shims and reach zero-baseline strict MVC

Goal: turn the temporary migration architecture into the permanent architecture.

Tasks:

- remove obsolete controller wrapper renderers once all callers use views;
- remove obsolete service persistence wrappers once callers use service -> model paths;
- remove dead SQL helper functions from services;
- remove temporary compatibility imports;
- delete all resolved entries from the MVC baseline;
- once the baseline is empty, remove baseline support from the checker or retain it only as an always-empty assertion;
- update `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `TESTING.md`, `RELEASE.md`, and relevant feature docs;
- update docblock module types where files changed role;
- regenerate any source/docblock manifests used by release tooling.

Final acceptance criteria:

- `app/controllers/`: zero SQL/PDO;
- `app/controllers/`: no domain persistence;
- `app/services/`: zero SQL/PDO and zero SQL-fragment construction;
- `app/services/`: zero HTTP request globals, response headers/status/cookies, and HTML rendering;
- `app/models/`: zero HTTP/presentation/service dependencies;
- `app/views/`: zero DB/PDO, request/session globals, persistence, redirects, headers/status/cookies, and domain-policy calls;
- repository MVC checker passes without legacy exemptions;
- all existing PHP, Node, Admin contract, updater, runtime-hardening, syntax, and release tests pass.

## 7. Recommended model organization

Do not create one model file per controller. Prefer domain-oriented modules that can serve multiple use cases.

A likely end-state model tree could be approximately:

```text
app/models/
  admin_logs.php
  app_settings.php
  auth.php
  content_localization.php
  database_maintenance.php
  duplicate_photos.php
  galleries.php
  gallery_access.php
  gallery_trash.php
  images.php
  media_metadata.php
  public_search.php
  public_search_diagnostics.php
  public_search_progressive.php
  smart_galleries.php
  tags.php
  telemetry.php
  thumbnail_metadata.php
  uploads.php
  viewer_accounts.php
  viewer_collections.php
  viewer_security.php
  viewer_tokens.php
  votes.php
  ...
```

This is directional, not a requirement to create all files immediately. Existing cohesive model modules should be extended instead of duplicated.

## 8. View-model convention

To keep the procedural codebase simple, use documented associative arrays instead of introducing a DTO class hierarchy.

Example shape:

```php
/**
 * @return array{
 *     title: string,
 *     can_edit: bool,
 *     active_tab: string,
 *     galleries: array<int, array<string, mixed>>,
 *     csrf_token: string
 * }
 */
function admin_gallery_page_view_model(...): array
{
    // Service/controller preparation only.
}
```

View functions should then accept the prepared array:

```php
function view_render_admin_gallery_page(array $viewModel): void
{
    // HTML only.
}
```

Do not allow views to lazily fetch missing data. Missing required presentation data should be treated as a caller/test defect.

## 9. Transaction and consistency rules during extraction

Moving SQL must not accidentally change transaction boundaries.

For every SQL extraction:

- identify whether the current code relies on one PDO transaction;
- identify `SELECT ... FOR UPDATE`, uniqueness races, affected-row checks, retry behavior, and deadlock handling;
- preserve operation order;
- preserve failure cleanup and rollback;
- preserve filesystem rollback where DB and filesystem changes are coordinated;
- preserve schema capability/fail-closed checks;
- preserve request-local query caches and observer instrumentation;
- keep logging/telemetry failure behavior best-effort where it is currently best-effort.

A model may expose a higher-level atomic persistence function when splitting it into many tiny calls would break correctness.

## 10. API, WinApp, browser, and route compatibility

This refactor is internal. The following are compatibility boundaries and must remain stable unless a stage explicitly documents a required migration:

- public route names and clean URLs;
- Admin route names;
- AJAX mutation envelopes;
- WinApp upload/API behavior;
- image upload semantics;
- map/GPS/SimBrief workflows;
- download manifest and browser ZIP contracts;
- search JSON/result contracts;
- viewer/account routes and security flows;
- feature registry behavior;
- updater/install state machine;
- gallery sidecar formats;
- existing database migrations and schema meaning.

Tests that protect these contracts must remain in place throughout the refactor.

## 11. Testing strategy per migrated feature

Each migrated vertical slice should get or extend four categories of tests:

### Model test

- verifies reads/writes and edge cases against the DB contract;
- confirms no HTTP/presentation dependency.

### Service test

- verifies domain behavior using semantic inputs;
- confirms service delegates persistence to models;
- confirms no request globals or HTTP output.

### Controller contract test

- verifies request validation, auth/CSRF, response code/shape, redirects, and view-model selection;
- confirms no DB access.

### View test

- renders from explicit view-model input;
- confirms important HTML/browser data attributes;
- confirms no request/DB dependency.

Existing feature regression tests should be reused and extended rather than replaced.

## 12. Refactor safety rules for implementation agents

Every implementation stage must follow these rules:

- read the whole involved workflow before moving code;
- preserve docstrings and meaningful comments;
- do not introduce parallel helper functions when an existing helper can be generalized;
- do not hide forbidden behavior behind a new generic wrapper just to satisfy the checker;
- do not move domain logic into views;
- do not move HTTP logic into models;
- do not move filesystem workflows into DB models;
- do not alter schema as part of a pure architecture extraction unless a real schema issue is separately identified;
- keep route and JSON compatibility;
- prefer semantic function names such as `gallery_model_update_visibility()` over generic `execute_gallery_query()`;
- keep model APIs bounded and typed through docblocks;
- maintain explicit namespace imports so layer dependencies remain inspectable;
- add focused regression coverage before or with each high-risk extraction;
- produce affected-files ZIP after each stage, including partial but coherent checkpoints when work must stop.

## 13. Full current persistence-heavy service inventory

The following current service files contain direct `db()`/PDO-style access and therefore require review during the migration. Some may contain infrastructure-style metadata queries, but each must receive an explicit architectural decision rather than a blanket exemption.

- `app/services/viewer_registration.php`
- `app/services/tag_metadata.php`
- `app/services/smart_galleries.php`
- `app/services/gallery_trash.php`
- `app/services/logs.php`
- `app/services/viewer_accounts.php`
- `app/services/gallery_mutations.php`
- `app/services/viewer_lifecycle.php`
- `app/services/ai_image_analysis.php`
- `app/services/downloads.php`
- `app/services/viewer_authentication.php`
- `app/services/viewer_collections.php`
- `app/services/viewer_tokens.php`
- `app/services/public_paths.php`
- `app/services/telemetry.php`
- `app/services/upload_automation.php`
- `app/services/picture_game.php`
- `app/services/thumbnail_metadata.php`
- `app/services/viewer_collection_shares.php`
- `app/services/auth_persistence.php`
- `app/services/gallery_lookup.php`
- `app/services/navigation_data.php`
- `app/services/image_scanning.php`
- `app/services/media_renamer.php`
- `app/services/thumbnail_maintenance.php`
- `app/services/flight_maps.php`
- `app/services/database_maintenance.php`
- `app/services/gallery_metadata_organizer.php`
- `app/services/viewer_rate_limits.php`
- `app/services/exif.php`
- `app/services/auth_throttle.php`
- `app/services/duplicate_photo_detector.php`
- `app/services/gallery_covers.php`
- `app/services/link_favicons.php`
- `app/services/viewer_favourites.php`
- `app/services/duplicate_photo_ledger.php`
- `app/services/mobile_webdav.php`
- `app/services/admin_log_archives.php`
- `app/services/admin_storage_statistics.php`
- `app/services/google_auth.php`
- `app/services/openai_text_assist.php`
- `app/services/viewer_admin_accounts.php`
- `app/services/viewer_security_operations.php`
- `app/services/votes.php`
- `app/services/app_settings.php`
- `app/services/gallery_access.php`
- `app/services/gallery_sidecars.php`
- `app/services/lightbox_metadata.php`
- `app/services/picture_manager.php`
- `app/services/schema_inspection.php`
- `app/services/site_maintenance.php`
- `app/services/gallery_migration/install.php`
- `app/services/admin_dashboard.php`
- `app/services/admin_database_usage.php`
- `app/services/admin_gallery_report/database_section.php`
- `app/services/browser_uploads/batch_state.php`
- `app/services/content_localization.php`
- `app/services/gallery_dates.php`
- `app/services/telemetry_settings.php`
- `app/services/thumbnail_generation.php`
- `app/services/uploads.php`
- `app/services/database_helpers.php`
- `app/services/gallery_migration/target_setup.php`
- `app/services/admin_gallery_discovery.php`
- `app/services/admin_gallery_report/image_summary.php`
- `app/services/admin_gallery_report/query_helpers.php`
- `app/services/gallery_picker.php`
- `app/services/telemetry_rollup.php`
- `app/services/thumbnail_bounds.php`
- `app/services/viewer_content_foundations.php`
- `app/services/gallery_grid.php`
- `app/services/admin_gallery_report/content_summary.php`
- `app/services/admin_gallery_report/system_summary.php`
- `app/services/browser_uploads/pipeline.php`
- `app/services/database_observer.php`
- `app/services/download_signatures.php`
- `app/services/favorite_galleries.php`
- `app/services/gallery_backgrounds.php`
- `app/services/gallery_branding.php`
- `app/services/gallery_migration/jobs.php`
- `app/services/gallery_migration/recovery.php`
- `app/services/public_gallery_media_manifest.php`
- `app/services/viewer_maintenance.php`
- `app/services/viewer_security_events.php`

## 14. Definition of done

The MVC refactor is complete only when architectural ownership is both true and mechanically enforced.

A feature should be readable from its controller as a clear use case:

1. read/normalize request;
2. authenticate/authorize;
3. call service;
4. receive structured result;
5. choose response or prepare view model;
6. render view or emit JSON/file response.

The service should explain the domain workflow without exposing SQL or HTTP. The model should explain persistence without knowing presentation or transport. The view should explain presentation without fetching data or deciding domain policy.

At the end, inserting SQL directly into a viewer/controller/service, reading `$_GET` from a view, or emitting HTML from a service must fail automated repository checks before the change can be released.

## 15. Stage 7 partial checkpoint - uploads/media/downloads/AI plus source-header normalization

Checkpoint status: **PARTIAL but coherent**.

Completed in this checkpoint:

- normalized first-party source headers and enforced `Author: Rudolf Klusal` with `tests/source_header_author_test.php`;
- moved additional Stage 7 persistence/transport boundaries for uploads, upload automation, thumbnail generation/bounds/maintenance, public media manifests, downloads, download signatures/cache, Mobile WebDAV, Flight Maps, lightbox metadata, Media Renamer, and AI image analysis;
- introduced dedicated model modules where persistence previously remained in services;
- kept filesystem/media orchestration in services and HTTP streaming/transport in controllers;
- moved AI image-analysis queue persistence, row locking, claim/heartbeat state transitions, success completion, retry/failure persistence, and reprocess operations into `app/models/ai_image_analysis.php` while retaining provider policy, token/hash handling, normalization, lease/retry policy, and orchestration in the service;
- refreshed the reviewed MVC baseline from **1012** to **697** occurrences after verifying that no new violations were introduced.

Checkpoint verification:

- source header author audit: PASS (801 first-party source files);
- PHP regression: 148 PASS / 0 FAIL / 1 SKIP / 1 BLOCKED;
- MVC layer boundaries: PASS at 697 / 697;
- Node fast regression: 13 / 13 PASS;
- WinApp regression: 36 / 36 PASS;
- Admin mutation contracts: PASS;
- Runtime hardening: PASS;
- manifest regenerated and current at 626 release files;
- remaining BLOCKED/SKIP coverage is environment-only (`ext-gd`, `pdo_mysql`).

This section is a historical Stage 7 checkpoint. Stage 7 was completed after this checkpoint; the current implementation status is recorded in Section 16 below.


## 16. Stages 7-13 completion checkpoint - 2026-09-14

Checkpoint status: **Stages 7 through 13 complete. Stage 14 active.**

The implementation has progressed beyond the historical Stage 7 checkpoint above. The source tree now has explicit mechanical contracts for the remaining intermediate MVC stages rather than relying only on the migration baseline.

### Stage 7: Complete

- `tests/stage7_media_pipeline_boundary_test.php` passes;
- uploads/media/download/AI persistence boundaries remain model-owned;
- media/file orchestration remains service-owned and HTTP transport remains controller-owned.

### Stage 8: Complete

- `tests/stage8_operational_persistence_boundary_test.php` passes;
- operational/reporting persistence boundaries remain model-owned;
- the Admin Gallery Report standalone HTML export remains view-owned.

### Stage 9: Complete

- `tests/stage9_http_transport_boundary_test.php` passes;
- services no longer emit HTTP headers/status/cookies in the Stage 9 transport scope;
- request bootstrap/controllers apply transport intents and response decisions.

### Stage 10: Complete

- `tests/stage3_controller_presentation_boundary_test.php` passes as the repository-wide controller presentation contract;
- feature controllers no longer contain the migrated HTML presentation blocks protected by that contract;
- renderers are owned by `app/views/` while controllers prepare use-case/view-model input.

### Stage 11: Complete

A new permanent contract, `tests/stage11_view_request_independence_test.php`, now enforces the Stage 11 acceptance boundary.

Completed work includes:

- removed all `$_GET`, `$_POST`, `$_REQUEST`, `$_FILES`, `$_COOKIE`, `$_SERVER`, and `$_SESSION` reads from `app/views/`;
- moved layout page/request-target input into the Core/controller-owned rendering adapter;
- moved dashboard maintenance-tab selection into controller-prepared models;
- moved selected policy/config lookups out of report, migration, upload-settings, and database-maintenance views;
- replaced view usage of the Admin-dashboard byte formatter with the transport/domain-neutral Core `format_bytes()` primitive;
- views now have zero direct DB/PDO/SQL, response-header, filesystem-mutation, and Model dependencies.

At the Stage 11 checkpoint, the remaining `views.service_dependency` entries were intentionally deferred to Stage 14 because request/session and persistence ownership had already been removed from Views. Stage 14 subsequently eliminated that remaining debt and the final baseline is zero.

### Stage 12: Complete

A new permanent contract, `tests/stage12_helper_responsibility_boundary_test.php`, protects helper ownership.

Completed work includes:

- `app/helpers*.php` now contain zero `echo`/`print` presentation output;
- Admin tab/subtab/sidebar/account-notice helper compatibility functions delegate to `app/views/admin_chrome.php` and fail explicitly if the View loader is missing instead of maintaining fallback HTML copies;
- shared header/footer/browser-i18n adapters delegate to `app/views/layout.php` without a second fallback renderer in Core;
- legacy SEO helper renderers delegate to `app/views/seo.php` without fallback markup;
- sitemap XML markup is view-owned by `view_render_sitemap_xml()`;
- `cms_sitemap_xml()` owns the XML `Content-Type`, requests sitemap data from the service layer, and passes it to the View;
- back-to-top and normal 404 markup remain owned by `app/views/http.php`.

This prevents generic helpers from becoming an unscanned presentation bypass around the MVC layers.

### Stage 13: Complete

A new permanent contract, `tests/stage13_loader_dependency_boundary_test.php`, protects loader order and upward-dependency rules.

Completed work includes:

- removed all 16 remaining `Service -> View/Controller` namespace dependencies;
- Gallery Migration services no longer import the gallery-editor controller URL helper;
- SimBrief and gallery-description services no longer call View renderers;
- Google authentication uses service-owned Admin email normalization instead of a controller utility;
- site maintenance invokes password-reset cleanup through service APIs rather than through an Admin controller;
- bootstrap order is mechanically checked as Services -> Views -> Controllers -> routing, with `app/services.php` loading Models before Service modules;
- current MVC scan has zero `models.upward_dependency`, zero `services.upward_dependency`, zero `views.model_dependency`, and zero `controllers.model_dependency`.

### Stage 14: Complete - zero-baseline strict MVC

Stage 14 is complete at the source-architecture boundary. The reviewed baseline has been reduced from 358 remaining occurrences to **0**, and `scripts/check_mvc_boundaries.php` now reports **Current: 0 | Baseline: 0**.

Completed final cleanup includes:

- extracted the remaining navigation-data, favicon, Google-auth, OpenAI-text-assist, and Gallery Migration persistence into Model ownership or existing domain Models;
- removed remaining SQL/PDO construction from Services;
- moved public/admin render profiler, Admin Test Run panel, gallery-date, pagination, sitemap, SEO, and archive-stream presentation/transport responsibilities to Views or Controllers as appropriate;
- removed remaining Service request-global reads in favor of controller/Core request data;
- converted shared layout, Admin dashboard, settings, gallery editor/forms, SEO, gallery descriptions, Trash, database maintenance, upload settings, and other remaining Views to controller/service-prepared view models;
- removed all detected Service presentation output and upward Service dependencies;
- completed loader-order contracts and retained compatibility adapters only where they delegate downward without duplicating presentation or persistence;
- refreshed `scripts/mvc_boundary_baseline.json` to an intentionally empty assertion;
- added/updated Stage 11-13 and repository MVC contracts so the migration cannot silently regress;
- completed the pending gallery-form call-site migration after the zero-baseline scan exposed the final presentation-policy ownership changes;
- fixed a Stage 14 Admin-dashboard runtime regression where `view_render_admin_gallery_report_maintenance_card()` still used its pre-view-model call signature, which could raise a PHP `TypeError` and surface through the generic server-error reference page;
- prevented public shared-header rendering from constructing the Admin sidebar model or evaluating Admin Test Run state for anonymous visitors.

Final architecture state:

- `app/controllers/`: zero scanner-detected SQL/PDO persistence leakage;
- `app/services/`: zero scanner-detected SQL/PDO/SQL fragments, request globals, HTTP transport output, HTML/presentation output, or View/Controller upward dependencies;
- `app/models/`: zero scanner-detected HTTP/presentation/Service upward dependencies;
- `app/views/`: zero scanner-detected DB/PDO/SQL, request/session globals, Model dependencies, HTTP transport, or domain-Service dependencies other than the explicitly presentation-safe translation helper contract;
- MVC checker: **0 current / 0 baseline**;
- latest quick audit before final handoff: **154 PHP pass / 0 fail**, MVC PASS, Node **13/13**, WinApp **36/36**, Admin mutation PASS, runtime hardening PASS, PHP/JS syntax PASS; local `ext-gd` remains BLOCKED and `pdo_mysql` remains SKIP because those extensions are unavailable in the audit container.

The strict MVC refactor is therefore complete. Future work must preserve the zero-baseline contract rather than reintroducing migration debt.
