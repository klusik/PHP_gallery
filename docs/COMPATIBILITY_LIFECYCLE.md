# Compatibility lifecycle and retirement register

Reviewed 2026-10-06 for Issue #73. This is an evidence-backed inventory, not a
guarantee that every path is exercised in production. Approximate eras mean
“visible in current code/evidence by this time”; they do not claim the first
release that introduced the behavior. Usage is marked unknown unless an
existing product metric actually observes adoption.

## Policy

### CI-1 — Local maintainer recovery after CI-first migration

- **Reason / protected environment:** Maintainers may need existing local
  generators/audits when GitHub is genuinely inaccessible or for explicitly
  requested environment-specific diagnosis. Red or slow CI is not inaccessible CI.
- **Owner:** AGENTS.md owns agent execution; RELEASE.md and the existing canonical
  generator/audit scripts own recovery. No parallel runner or test registry exists.
- **Permanent support rationale:** Keep local development and emergency recovery
  possible without treating local PASS as hosted qualification. Ordinary agents
  commit/push only an explicitly authorized working branch; protected release
  workflows own approved promotion/publication. Production-host smoke stays manual.
- **Regression evidence:** `tests/hosted_release_policy_test.mjs` exercises missing,
  stale and red evidence refusal, exact overrides and CI-first/branch contracts
  through the central hosted Node registry. Actual hosted write-mode acceptance
  requires configured server protections and independent environment approval.
- **Usage / introduction:** Local recovery usage is unknown; this policy transition
  was authored for #100/#124 on 2026-10-08, without inferring older adoption.

Compatibility is a supported behavior when users, installed data, older
clients, host configurations, or documented no-JavaScript flows rely on it.
Keep such behavior until its owner demonstrates the retirement condition below.
An unsupported platform policy may be a retirement candidate without implying
that a corresponding production branch exists.

When adding or materially changing a fallback or compatibility branch, record
in the pull request: its reason, the concrete old behavior/version/environment
it protects, its component owner, and the earliest safe retirement condition.
For high-cost or cross-cutting behavior, add or update a record here. State
“unknown” for unmeasured usage or uncertain introduction dates; do not infer
adoption from a code path, log event, or earliest Git path alone. Removal must
include migration and release-note implications where applicable, and a
regression/acceptance proof for the retired scenario. This is review metadata,
not a source parser or a commitment to remove anything on a calendar date.

### UI-3 — Clipboard exposure and writable FileList availability

- **Reason / protected environment:** Desktop browsers or clipboard sources may expose no image files, and older/restricted browsers may not construct a DataTransfer or assign an input FileList. Their established chooser/drop and ordinary no-JavaScript POST uploads remain usable.
- **Owner:** `admin-upload-selection.js` owns clipboard extraction; `admin-upload-queue.js` and `admin-upload-preview.js` own the right-drawer local selection, previews and disposal. Existing upload controllers/services and the side-panel completion owner retain persistence, progress and security.
- **Evidence:** `admin_upload_clipboard_test.mjs` and the registered `admin_upload_clipboard_browser_test.mjs` cover file-only exposure, selection preservation on assignment failure, ordinary multipart submission and dynamic panel controls. The OS/browser matrix in `TESTING.md` remains manual evidence.
- **Usage:** Clipboard API availability and fallback frequency are `unknown`; no new telemetry is collected.
- **Support rationale:** Chooser/drop and no-JavaScript POST remain permanently supported input methods. Clipboard input is additive and may never require permission polling or a second upload endpoint.

### UI-4: Bounded local miniature fallback

- **Reason / protected environment:** A browser may lack `createImageBitmap` or canvas encoding, reject a codec, or receive animated, oversized, unknown or metadata-heavy image data. Preview admission must not narrow the existing server upload formats or replace the original with a thumbnail.
- **Owner:** `admin-upload-thumbnail.js` owns bounded raster admission and serialized decoding; `admin-upload-preview.js` owns format fallback and URL/callback disposal. Upload processing, metadata and authorization remain with the existing transport/service owners.
- **Evidence:** Registered `admin_upload_thumbnail_test.mjs`, the expanded clipboard Chromium fixture, and the real Worker/ZIP operation-key fixture cover fallback, original preservation, resource bounds, reset and uncertain acknowledgment. Actual OS clipboard/browser results remain in the `TESTING.md` manual matrix.
- **Usage:** Decoder support, oversized-image frequency, fallback usage and real browser-process memory are `unknown`; no telemetry is added.
- **Support rationale:** A missing local miniature is permanently allowed for an otherwise uploadable original. Retirement would require a separate accepted browser/format support-policy change, not merely a passing Chromium test.

## Database and migrations

### UI-2 — Authored metadata language and source-field compatibility

- **Reason / protected scenario:** Existing galleries and photos may have no
  translation row or source-language tag. Gallery fields fall back independently;
  an existing photo translation retains blank caption fields. Admin source
  editors must never save a presentation overlay as canonical text.
- **Owner:** `app/services/content_localization.php` and request language policy
  in `app/services/translations.php`. Admin and public browser preferences stay
  independent; explicit language arguments remain supported for existing callers.
- **Evidence / tests:** `content_request_language_test.php`,
  `content_localization_model_test.php`, and
  `content_language_workflow_integration_test.php`; investigation scope is in
  `docs/ISSUE_93_LOCALIZATION.md`.
- **Usage:** Missing authored translations and legacy cookie adoption are unknown.
- **Support rationale:** Source fallback and explicit language arguments are
  permanent compatibility contracts. Legacy cookie removal would require an
  accepted migration of persisted browser preferences and regression proof.

### UI-1 — Breadcrumb preferences missing on upgraded installations

- **Reason / protected scenario:** Existing galleries and installations have no
  breadcrumb preference. Missing or obsolete gallery values inherit the Theme
  default; missing or obsolete Theme values use the built-in `chevron` style.
  Existing route destinations and server-rendered navigation remain available.
- **Owner:** `app/services/breadcrumbs.php`, the shared view, and gallery/Theme
  settings owners. Physical gallery overrides use existing application settings;
  no optional schema probe or manual migration is required.
- **Evidence / tests:** `breadcrumb_component_test.php`,
  `breadcrumb_gallery_settings_test.php`, `breadcrumb_theme_settings_test.php`
  and the registered `breadcrumb_browser_test.mjs`.
- **Usage:** Preference absence and obsolete identifiers are unmeasured (`unknown`).
- **Support rationale:** Missing preferences and unknown style identifiers are
  permanently supported upgrade states. The public gallery helper delegates to
  the shared renderer so existing page composition retains its include contract.

### DB-1 — Legacy migration definition shapes and direct-require runner

- **Reason / protected scenario:** `load_migration_definition()` accepts both
  the historical SQL-list definition and the `{statements, after}` callback
  form. Repair migrations scoped to a PDO and returned an empty list for old
  direct-require runners. This preserves legacy migration consumers while new
  definitions use callback-aware semantics.
- **Era / owner:** Callback API evidence exists by 2026-07-12; original
  introduction is unknown. Owner: `app/migration_definitions.php` and
  `database/migrations/`.
- **Evidence / tests:** `app/migration_definitions.php`,
  `migration_consistency_test.php`,
  `migration_legacy_runner_compatibility_test.php` (repair files and
  conditional maintenance).
- **Usage:** External direct-require runner adoption is unknown.
- **Earliest safe retirement:** Only after the oldest supported installer or
  updater no longer directly requires migration files, all supported runners
  consume callback-aware definitions, and the direct-require contract is
  explicitly retired. Do not mass-convert immutable historical migrations.

### DB-2 — Applied migration ledger rows with no current file

- **Reason / protected scenario:** An already-applied `schema_migrations` row
  remains history even when its migration file has been removed; it must not
  reappear as pending work or be silently discarded.
- **Era / owner:** Era unknown. Owner: `app/migration_definitions.php` and
  `app/migrations.php`.
- **Evidence / tests:** `pending_migration_files()` and migration-history
  handling; `migration_consistency_test.php` uses simulated files and applied
  version arrays to cover unknown historical versions, pending files, and a
  fully applied set. It does not insert live database ledger rows.
- **Usage:** Ledger-row frequency is not measured as a product metric.
- **Lifecycle:** Permanent history protection under the current ledger model.
  Reconsider only as part of an explicit, versioned ledger-compaction design
  with backup/upgrade proof; no current removal is planned.

### DB-3 — Duplicate DDL reconciliation on migration replay

- **Reason / protected scenario:** MySQL/MariaDB DDL can partially persist when
  a process stops before recording the migration. Known duplicate-object errors
  are reconciled on replay so the ledger is recorded only after the resulting
  schema is correct, including non-transactional DDL.
- **Era / owner:** Evidence exists by 2026-04-28; origin unknown. Owner:
  `app/migrations.php`.
- **Evidence / tests:** `apply_migration_statement()` and
  `migration_schema_cache_reset_test.php`.
- **Usage:** Replay events are not an adoption measure.
- **Lifecycle:** Retain for supported MySQL/MariaDB families unless an
  equivalent, equally durable partial-DDL recovery guarantee replaces it.
  A single-engine transaction assumption is not a retirement proof.

### DB-5 — Layout repair before first-install configuration

- **Reason / protected scenario:** `install.php` runs the layout-semantics
  callback before `config.php` and the first administrator exist. The repair must
  use the installer PDO for the shared writer lock and the installer-owned
  `galleries/` root rather than assume application bootstrap has run. Configured
  upgrades retain their configured gallery root and legacy/persistent Trash roots.
- **Era / owner:** Repair for issue #123, reported on 0.124.3. Owner:
  `gallery_description_layout_compatibility.php` and the canonical gallery edit model.
- **Evidence / tests:** `installer_first_install_test.php` exercises config-free
  HTTP installation, busy refusal and retry; `gallery_description_layout_compatibility_test.php`
  retains upgrade, fresh-state and interrupted sidecar/database replay coverage.
- **Usage:** Unknown; the issue documents one failed installation.
- **Lifecycle:** Permanent while standalone pre-configuration installation is
  supported. Retirement requires an equivalent tested installation protocol that
  supplies configuration before callbacks without weakening writer exclusion or replay.

### DB-4 — Unsupported database-floor and former query-workaround rationale

- **Reason / protected scenario:** Historical support notes explain old server
  floors and a former Smart Gallery window-function-avoidance rationale. The
  current support policy lists MySQL 8.4 and MariaDB 10.11/11.4 as required
  maintained workflow representatives; no runtime branch based on server
  version was found.
- **Era / owner:** Policy review is current in `docs/DATABASE_SUPPORT.md`;
  query/index rationale predates this review. Owner: database support policy
  and Smart Gallery query/index owners.
- **Evidence / tests:** `docs/DATABASE_SUPPORT.md`,
  `tests/database_engine_contract_test.php`, direct database workflow.
- **Usage:** Unsupported-version installations are unknown; CI measures only
  the required representative engine tuples.
- **Retirement status:** Explicit policy/documentation candidate, not a proven
  removable runtime branch. Revisit the unsupported floor and former query
  rationale only after correctness, performance, and safety review of the
  query and digest index. Keep current query and index behavior until that
  review supports a change.

## Transport compatibility

### HTTP-1 — Explicitly trusted TLS-terminating proxies and HTTPS wrappers

- **Reason / protected scenario:** Shared-hosting proxies may deliver browser TLS
  over HTTP to PHP. Core/Admin and Viewer transport use one resolver, accepting
  this topology only with both `security.trusted_proxies` (exact IPv4/IPv6 or CIDR)
  and `security.trusted_proxy_protocol_headers`. Client-IP header opt-in remains
  independent. Direct TLS and HTTP installations retain their existing behavior.
- **Owner:** `app/services/client_ip.php::request_transport_is_https()`;
  `app/helpers_request.php::request_is_https()` and `viewer_request_is_https()`
  retain their established caller entry points. Core loads the service lazily
  before session startup without loading Viewer identity or account storage.
- **Migration / safety:** Previously generic Core HTTPS detection accepted
  forwarded protocol from any peer, including a forged direct-client assertion
  (BH-03). Configure the actual proxy scope and protocol header opt-in before
  upgrading a TLS-terminating installation. The proxy must overwrite enabled
  client-supplied headers. Ambiguous or conflicting enabled values cannot prove
  HTTPS. Existing configured HTTPS base URLs are not downgraded by the resolver.
- **Evidence / tests:** `tests/request_https_proxy_test.php` first reproduced the
  forged HTTPS assertion, then covers direct transport, exact/CIDR proxy trust,
  IPv6, header opt-in/ambiguity, public URL schemes and Admin cookie Secure flags.
  `viewer_authentication_phase06_test.php` retains Viewer policy coverage.
- **Usage:** Proxy topology and wrapper adoption are unknown; no production
  measurement is inferred from source age or test coverage.
- **Support rationale / retirement:** Direct transport, explicitly configured
  proxy hosting and both wrapper entry points are permanent supported contracts.
  Wrapper removal needs complete caller migration and equivalent regression
  coverage. Unrestricted forwarded-header acceptance is unsafe and is retired;
  it is not retained as an implicit configuration fallback.

## Updater and release compatibility


### FILES-1 — Windows layout-migration sidecar sharing locks

- **Reason / protected scenario:** Windows briefly refused atomic replacement of
  an owned `gallery.json` during the release audit. The existing layout-migration
  owner retries the same complete staging file for at most ten attempts with nine
  50 ms pauses; other platforms retain one attempt.
- **Owner:** `app/services/gallery_description_layout_compatibility.php`.
- **Safety:** Recheck the original bytes before each retry, refuse concurrent
  edits or persistent storage refusal, never delete the previous sidecar, retain
  its permission-mode behavior, and remove only the owned staging file. A staging
  file that inherited read-only mode becomes owner-writable only for cleanup.
- **Evidence / tests:** `tests/gallery_description_layout_compatibility_test.php`
  injects a transient refusal, verifies bounded Windows recovery and unchanged
  non-Windows refusal, intervening-edit preservation, read-only refusal and
  staging cleanup; existing rollback and per-document replay cases remain.
- **Usage:** One local native sharing refusal was observed on 2026-10-07 with
  PHP 8.5.10 on Windows; production frequency is unknown.
- **Support rationale / retirement:** Permanent support while Windows is
  supported. Removal requires an alternative atomic commit proven with transient
  locks, persistent refusal, concurrent edits and migration replay.


### UPD-4 — Windows updater checkpoint sharing locks

- **Reason / protected scenario:** Windows readers can briefly deny replacement
  of a just-written updater checkpoint. Retry the same complete staging file for
  at most ten rename attempts with nine 50 ms pauses; never delete the prior
  checkpoint to obtain replacement permission. Other platforms use one attempt.
- **Owner:** `app/services/updates_jobs.php` and
  `app/services/updates_jobs/state.php`, the existing updater state persistence owner.
- **Evidence / tests:** An owned Windows probe refused its 29th consecutive
  checkpoint replacement, then committed after one pause (66 ms observed).
  `tests/updater_resumable_state_machine_test.php` covers complete replacement,
  persistent destination refusal, staging cleanup, prior checkpoint preservation
  on Windows read-only refusal, and 620 resumable archive-entry checkpoints.
- **Usage:** Production sharing-lock frequency is unknown; the local failure and
  successful retry were observed on 2026-10-07 with PHP 8.5.10 on Windows.
- **Support rationale / retirement:** Permanent support for Windows file-sharing
  semantics while Windows is supported. Removal requires a replacement atomic
  persistence mechanism proven with transient locks, persistent refusal and
  existing durable-job recovery; no calendar retirement is assumed.

### UPD-1 — Positive manifest ownership bridge for archives without sidecar

- **Reason / protected scenario:** An incoming production-files sidecar may be
  absent in an older supported archive. The updater then accepts only safe
  positive paths owned by the core manifest and recognized guards. A present
  but invalid sidecar fails closed; absence is not treated as arbitrary trust.
- **Era / owner:** Policy evidence exists by 2026-10-05; oldest supported
  archive is unknown. Owner: `app/release_file_policy.php` and updater archive
  validation.
- **Evidence / tests:** `tests/production_file_policy_test.php` covers valid
  positive membership, invalid-sidecar refusal, older ZIPs, and unlisted
  paths; `tests/updater_safety_model_test.php` covers updater safety.
- **Usage:** Archive-sidecar adoption is not aggregated.
- **Earliest safe retirement:** After the bridge upgrade window is documented
  and every supported installer/updater archive plus prior ownership state has
  valid positive inventory. Do not substitute a calendar date for the
  supported-upgrade floor.

### UPD-2 — Incoming schema-1 companion membership for installed readers

- **Reason / protected scenario:** The installed pre-WinApp reader validates
  every incoming production path before the replacement reader is activated.
  Listing newly distributed WinApp sources in the base list prevents those
  installations from updating. The additive `companion_files` field keeps the
  base readable while current packaging includes the reviewed companions;
  neither reader grants CMS updater ownership to companion files.
- **Era / owner:** The pre-WinApp contract is evidenced by commit `ffe5b5d`
  (CMS version 0.119); the compatible split is introduced in 0.120.1.
  Owner: `app/release_file_policy.php` and
  `scripts/generate_production_files.php`.
- **Evidence / tests:** `tests/updater_inventory_compatibility_test.php` uses
  trusted frozen installed-reader functions to reproduce error reference
  `385BCD5058D6`, validate the real incoming inventory and compare archive
  activation scopes. `tests/production_file_policy_test.php` covers both
  schema-1 layouts, optional-field validation, disjoint package lists and
  private-state/updater-ownership refusal; real deploy regression retains
  companion sources in folder/ZIP packages.
- **Usage:** Supported installed-reader adoption is unknown. One supplied
  online failure reports version 0.119; this is not an aggregate usage metric.
- **Earliest safe retirement:** Only after the supported installed-updater
  floor explicitly excludes the old reader, deployments have a verified
  migration path and incoming archive qualification covers that new floor.
  Existing archives without the optional field remain readable. Never obtain
  compatibility by executing PHP supplied in an incoming archive or granting
  companions authority over installation-owned state.

### UPD-3 — Windows checkout line endings in production inventory verification

- **Reason / protected scenario:** Git may convert the checked-in JSON inventory
  to CRLF on Windows. Comparing those bytes with generated LF text must not
  reject an otherwise identical reviewed production list.
- **Era / owner:** The correction is evidenced by commit `d4cbe89` and shipped
  in CMS 0.121.2. Owner: `scripts/generate_production_files.php` through
  `production_files_inventory_content_matches()`.
- **Evidence / tests:** `tests/production_file_policy_test.php` accepts CRLF
  conversion and still rejects actual content changes. The generator normalizes
  only CRLF to LF; membership, path safety and manifest hashes keep their
  existing strict checks.
- **Usage:** Windows checkout adoption is unknown.
- **Permanent-support rationale:** LF and CRLF are supported Git checkout
  representations. Retire normalization only if support for CRLF checkouts is
  explicitly ended and supported Windows build/packaging environments prove
  canonical LF handling. This is not an archive-integrity normalization rule.

## Translations and language preference

### I18N-1 — PHP catalogs when a language JSON pack is absent

- **Reason / protected scenario:** `translation_load_language()` reads the
  maintained JSON pack first and uses the matching PHP dictionary only when
  that JSON file is absent. A present malformed JSON pack does not silently
  switch formats; it resolves to empty catalog data.
- **Era / owner:** JSON foundation evidence exists by 2026-05-11; the PHP
  fallback's first introduction is unknown. Owner: `app/services/translations.php` and
  `app/lang/`.
- **Evidence / tests:** `tests/translation_catalog_consistency_test.php`
  validates maintained catalogs, but current evidence does not prove it
  executes the absent-JSON PHP fallback.
- **Usage:** Format fallback adoption is unknown.
- **Earliest safe retirement:** Only after supported packages and upgrade
  scenarios guarantee every supported JSON pack, the product owner explicitly
  ends PHP-catalog compatibility, and an absent-pack regression is replaced by
  a documented fail-safe. The current coverage gap must remain explicit.

### I18N-2 — Canonical English and key fallback

- **Reason / protected scenario:** `t()` resolves the active catalog, canonical
  English, caller default, then key so incomplete translations do not erase
  visible labels or break older callers.
- **Era / owner:** Translation foundation is visible by 2026-05-11; exact
  first introduction is unknown. Owner: `app/services/translations.php` and
  `app/lang/en.json`.
- **Evidence / tests:** `tests/translation_catalog_consistency_test.php`
  checks English keys and four maintained catalogs.
- **Usage:** Missing-key frequency is not reported as product adoption.
- **Lifecycle:** Permanent user-facing fallback contract; no removal planned.

### I18N-3 — Legacy shared Admin language cookie/session mirror

- **Reason / protected scenario:** Admin language preference resolution
  preserves the `cms_admin_language` session value, dedicated Admin cookie,
  older shared `cms_language` cookie, and a `cms_language` session mirror.
  Public language preference remains separate and current.
- **Era / owner:** Base translation/session evidence exists by 2026-05-11;
  selector evidence by 2026-08-13. Owner: `app/services/translations.php` and request
  language/session bootstrap.
- **Evidence / tests:** `tests/public_language_preference_test.php` and
  `tests/session_context_test.php` cover Admin/Public separation, mirrors,
  reset, and diagnostics.
- **Usage:** Existing cookies/session values are not counted.
- **Earliest safe retirement:** Stop emitting the shared alias first; preserve
  readers through at least the one-year cookie max-age and supported-session
  horizon, then expire/migrate stored Admin preference while preserving it.
  Never merge the Public dedicated preference into this alias.

## Core runtime and session contracts

### CORE-3 — Maintenance Center full-bootstrap task consumers

- **Reason / protected scenario:** CLI/test consumers that load the complete
  bootstrap already have Maintenance Center subsystem dependencies and may call
  task steps without a request kernel loader. Selective HTTP routes inject one
  for each separate analysis/execution request.
- **Owner:** `app/services/maintenance_center.php` and its analysis/execution
  workers; HTTP injection belongs to `app/controllers/admin_maintenance_center.php`.
- **Evidence / tests:** `tests/maintenance_center_test.php` covers phase-specific
  module selection, controller injection and loading before task callbacks;
  `scripts/runtime_dynamic_dependencies.php` registers the reviewed loader.
- **Usage:** Full-bootstrap consumer adoption is unknown.
- **Earliest safe retirement:** Migrate all supported CLI/test consumers to an
  explicit loader and prove their include contracts before removing the optional
  null-loader path. Never replace selective HTTP loading with umbrella loading.

### CORE-1 — Two-argument ModuleLoader construction

- **Reason / protected scenario:** An optional `null` third file-order argument
  preserves historical two-argument construction for consumers using a
  dependency-first manual plan. The official bridge supplies the compiled
  third order.
- **Era / owner:** The two-argument Core runtime API is visible by 2026-10-05
  (#69); optional third file-order argument is visible by 2026-10-06 (#79).
  Exact originating release is unknown. Owner: `app/runtime/ModuleLoader.php`
  and `app/runtime/bridge.php`.
- **Evidence / tests:** `tests/runtime_kernel_test.php` covers two-argument
  construction and composed plans.
- **Usage:** External constructor usage is unknown.
- **Earliest safe retirement:** After all in-repository consumers use the
  explicit order and the project publishes an intentional unsupported-API
  boundary for external consumers. Do not infer external non-use from local
  search results.

### CORE-2 — Flat session keys and domain-owned semantics

- **Reason / protected scenario:** `app/session_context.php` centralizes
  flat-key get/set/remove access while preserving the existing key names,
  inactive-CLI seeded values, and unrelated sibling keys. Translation,
  Navigraph cache/OAuth, gallery access, NSFW acknowledgement, and flash keep
  domain semantics in their owning services.
- **Era / owner:** The adapter is from 2026-10-06 (#72); underlying session
  keys span older mixed eras. Owner: `app/session_context.php` plus each
  domain session owner.
- **Evidence / tests:** `tests/session_context_test.php` and
  `tests/request_session_helpers_test.php`.
- **Usage:** Key-level usage is repository-observable; external session
  consumers are not measured.
- **Lifecycle:** Established current state contract; no removal planned.
  Redesign requires a versioned namespace, dual-read/migrate-on-write or an
  explicit expiry window, and key-by-key compatibility regression coverage.

## Media and routing

### MEDIA-1 — Responsive and progressive thumbnail renderers

- **Reason / protected scenario:** `responsive` provides complete server
  responsive markup and no-JavaScript behavior; `progressive` provides a
  real small first image and bounded near-viewport sharpening. The selected
  gallery setting accepts both permanent values and normalizes invalid values
  to progressive.
- **Era / owner:** Default-setting migration is 2026-08-20; service path is
  visible by 2026-08-24. Owner: `app/services/public_thumbnail_rendering.php`,
  responsive HTML helpers, and progressive browser modules.
- **Evidence / tests:** `tests/public_thumbnail_rendering_model_test.php`,
  `tests/progressive_thumbnail_renderer_test.mjs`, and the renderer browser
  fixture.
- **Usage:** Configured mode is measurable; aggregate visitor traffic by mode is
  unknown.
- **Lifecycle:** Both machine values and pipelines are permanent supported
  behavior. Keep semantic image markup, alt text, authorization, and no-JS
  behavior in both. No removal planned.

### MEDIA-2 — JPEG compatibility derivatives alongside WebP

- **Reason / protected scenario:** Legacy JPEG derivatives remain available
  alongside WebP for consumers that cannot use WebP. The indexed cleanup
  inventory deletes only registered legacy JPEG derivatives, not originals or
  WebP files.
- **Era / owner:** Evidence exists by 2026-06-08; exact introduction unknown.
  Owner: `app/services/thumbnail_compatibility.php` and thumbnail format/
  metadata services.
- **Evidence / tests:** `tests/thumbnail_compatibility_model_test.php` and
  `tests/thumbnail_format_metadata_consistency_test.php`.
- **Usage:** Inventory count/bytes are measurable; actual served-format need
  is unknown.
- **Earliest safe retirement:** Only after the indexed legacy inventory is
  empty and supported consumers demonstrate that JPEG derivative demand is
  absent. Keep explicit non-destructive inventory and cleanup until then.

### MEDIA-3 — Header-first MIME detection with fileinfo compatibility fallback

- **Reason / protected scenario:** Common JPEG, PNG, GIF, and WebP media is
  identified from its image header before fileinfo is consulted. PHP 8.5.11's
  fileinfo libmagic path allocates a 7 MiB read buffer even for a tiny image.
  The existing fileinfo path remains for supported `image/*` formats such as
  SVG, BMP MIME aliases, and legacy files whose headers are not in the common
  fast-path set; file extensions do not establish image identity.
- **Era / owner:** The header-first path is visible by 2026-10-06; the original
  fileinfo-based behavior predates this review. Owner:
  `app/services/dng_derivatives.php`.
- **Evidence / tests:** `image_source_mime_for_derivatives()` and
  `image_public_display_file()`; `tests/image_decode_pipeline_test.php` covers
  JPEG content with a misleading extension, supported raster MIME values,
  SVG fallback, and rejection of non-image content.
  Upstream PHP 8.5.11 defines the [7 MiB read bound](https://github.com/php/php-src/blob/php-8.5.11/ext/fileinfo/libmagic/file.h#L471)
  and [allocates that buffer before reading the file](https://github.com/php/php-src/blob/php-8.5.11/ext/fileinfo/libmagic/magic.c#L201).
- **Usage:** Per-format media requests and fallback rates are not measured.
- **Lifecycle:** Keep header inspection for recognized raster content and the
  fileinfo compatibility path for other formats and historical MIME aliases.
  Reconsider the fallback only after every supported legacy image format has
  a tested, trustworthy alternative MIME detector; do not replace content
  detection with extension-based guesses.

### MEDIA-4 — Previously emitted SimBrief PDF query URLs

- **Reason / protected scenario:** Earlier OFP View/Download actions emitted
  `index.php?page=media&id=GALLERY_ID&ofp=1`. Bookmarks, saved HTML, and
  unmodified clients may continue using this overloaded image/PDF route.
  Current actions use `gallery_ofp_pdf` with a gallery ID and a same-origin
  URL derived from the actual mount path so visitors on host aliases or
  non-root installations do not cross origins for PDF.js.
- **Owner:** `app/controllers/public_media.php` retains the legacy dispatch;
  `app/controllers/public_gallery_controls.php` authors current links;
  `app/services/simbrief_ofp_attachments.php` enforces the shared gallery
  authorization and local-file integrity policy.
- **Evidence / tests:** `simbrief_ofp_public_http_test.php` exercises anonymous,
  authenticated and denied legacy/current delivery on root/subdirectory
  `index.php` routes. `public_media_authorization_contract_test.php` covers
  the actual password, token, NSFW and visibility policy.
- **Usage:** Unknown; no measurement of historical PDF URL usage is available.
- **Support rationale / retirement:** Keep the old query branch until existing
  links can be migrated or a versioned retirement with real hosted evidence
  is approved. Both routes must remain equally protected; the compatibility
  route must never become a public static-file bypass.

### ROUTE-1 — Query routes and clean URL aliases

- **Reason / protected scenario:** Query routes remain the canonical
  compatibility form. Pretty/clean path mapping is a convenience when rewrite
  rules are available.
- **Era / owner:** Routing path evidence exists by 2026-08-12; exact first
  introduction unknown. Owner: `app/bootstrap/routing.php`, dispatcher, and
  URL producers.
- **Evidence / tests:** `tests/route_reference_integrity_test.php`,
  `tests/public_media_url_rewrite_test.php`,
  `tests/public_media_version_routing_test.php`, and the HTTP-entry fixture
  from #78.
- **Usage:** No aggregate route-form metric is collected; existing access logs
  may provide local evidence.
- **Lifecycle:** Query routing is permanent. Removing clean paths requires an
  explicit public-URL breaking policy and proof for generated/bookmarked URLs.

## WinApp, upload, and downloads

### WINAPP-1 — Query-first upload API and narrow clean-route fallback

- **Reason / protected scenario:** Canonical query POST is tried first because
  some hosting WAFs mishandle clean routes. The clean route is retried only on
  a non-JSON 404; Gallery JSON 404, authorization failures, network failures,
  and transient statuses do not cause route retry. Revocation has its own
  bounded 502/503/504 retry and already-revoked reconciliation.
- **Era / owner:** Upload entry point exists by 2026-05-16; fallback is newer,
  exact introduction unknown. Owner: `winapp/gallery_watch_upload.pyw` and
  Gallery upload controllers.
- **Evidence / tests:** `winapp/tests/test_redesign.py` covers canonical-first,
  multipart 404, revoke 404, JSON 404, transient behavior, and reconciliation.
- **Usage:** Local warning logs exist; aggregate alias adoption is unknown.
- **Earliest safe retirement:** Retire only the alternate route after target
  hosting and the supported client floor guarantee the canonical route. Keep
  query-first and status-classification contracts unless that contract is
  deliberately redesigned.

### WINAPP-2 — Installer Python selection and matching windowless launcher

- **Reason / protected scenario:** Installer startup finds a supported Python
  runtime (`python`/`py`) and pairs the selected interpreter with its matching
  `pythonw.exe` shortcut so the GUI launches without a console.
- **Era / owner:** `.pyw` entry is visible by 2026-05-16; exact installer
  selector introduction unknown. Owner: WinApp installer/launcher scripts.
- **Evidence / tests:** `winapp/tests/test_redesign.py` loads the `.pyw` entry;
  current evidence found no automated installer BAT/shortcut creation test.
- **Usage:** Installed shortcut/runtime adoption is unknown; test coverage gap
  is explicit.
- **Earliest safe retirement:** Only when the supported installer/runtime and
  deployed shortcuts no longer depend on this selection contract and a focused
  installer acceptance test proves the replacement.

### WINAPP-3 — Batch wrapper PATH-based `pythonw` entry

- **Reason / protected scenario:** `run_gallery_watcher.bat` remains a
  separately supported launcher that invokes `pythonw` through PATH; this may
  coexist with installer-created shortcuts that bind a particular runtime.
- **Era / owner:** Visible by 2026-05-16; exact introduction unknown. Owner:
  `winapp/run_gallery_watcher.bat` and WinApp launch documentation.
- **Evidence / tests:** `winapp/tests/test_self_update_smoke.py` checks the
  startup prefix; direct wrapper activation adoption is not measured.
- **Usage:** Unknown.
- **Earliest safe retirement:** Only after support documentation and deployed
  scripts/shortcuts no longer advertise or call the wrapper, with replacement
  launcher evidence on supported Windows installs.

### UPLOAD-1 — Client-side thumbnail negotiation for the local WinApp uploader

- **Reason / protected scenario:** WinApp uses local Pillow derivatives and
  transmits thumbnail IDs with server-side batch work disabled to avoid
  shared-hosting thumbnail CPU bursts. Unsupported HEIC/HEIF/DNG, missing
  Pillow, or local conversion failure remains server-owned.
- **Era / owner:** Watcher/uploader entry exists by 2026-05-16; current
  negotiation detail introduction unknown. Owner: `winapp/uploader/media.py`,
  `gallery_watch_upload.pyw`, `upload_automation.php`, and browser upload
  service.
- **Evidence / tests:** `winapp/tests/test_redesign.py` covers local thumbnail
  and multipart behavior; `tests/browser_upload_settings_test.php` and
  `tests/browser_upload_zip_worker_test.mjs` cover the server path.
- **Usage:** Local warnings may be logged; aggregate client adoption and
  fallback rates are unknown.
- **Earliest safe retirement:** Only after supported deployed clients and all
  accepted formats have a validated replacement, and shared-hosting server
  processing constraints are acceptable. Keep server fallback for unsupported
  formats and local failures.

### DOWNLOAD-1 — Server ZIP generation, immutable cache, and direct/no-JS path

- **Reason / protected scenario:** The server may create and reuse an
  authorized immutable artifact; progressive browser streaming remains the
  modern client path while direct and no-JavaScript requests remain supported.
  Cache health is checked before manifest/build, and every request is
  authorized before artifact reuse. Optional cache failure leaves progressive
  availability intact.
- **Era / owner:** Cache path exists by 2026-09-03; exact direct-path
  introduction unknown. Owner: `app/services/download_artifact_cache.php`,
  download controllers, and `public/assets/gallery-download.js`.
- **Evidence / tests:** `tests/gallery_download_controller_test.php` covers
  early legacy-capability 503 and progressive availability;
  `tests/gallery_download_service_test.php` covers legacy manifest bounds,
  and `tests/gallery_download_manifest_test.php` plus
  `tests/gallery_download_client_test.mjs` cover manifest and client paths.
- **Usage:** `media.download.served` logs are observable but are not a product
  adoption metric for legacy/direct clients.
- **Earliest safe retirement:** Only after the direct/no-JavaScript contract is
  explicitly ended, usage is measured and acceptably low, and an authorized
  replacement remains safe. Do not remove the server fallback because cache
  generation is optional or unhealthy.

## Visual CSS resize across a scriptless preview frame

- **Reason / protected environment:** During a native drag over the scriptless preview iframe, hosted Chromium delivered a trusted held pointer move to the parent overlay after handle capture was lost. The active gesture must continue only through the existing pointerId-guarded transaction while the parent overlay shields hit testing; the implementation does not assume a specific browser cause for capture loss.
- **Owner:** `public/assets/gallery-modules/theme-visual-css-resize.js` owns the existing resize transaction, temporary overlay shield, one overlay move/up/cancel listener set, capture attempt, cancellation and restoration. It does not add a second pointer pipeline or alter public-page interaction outside an active drag.
- **Evidence:** `tests/theme_visual_css_resize_model_test.mjs` covers the bounded transaction model; `tests/theme_visual_editor_browser_test.mjs` exercises native pointer movement, transient preview, commit and Escape restoration. The [W3C Pointer Events capture algorithm](https://www.w3.org/TR/pointerevents3/#setting-pointer-capture) keeps capture pending until subsequent event processing and requires the capture element to be in the pointer's active document. The [W3C cross-iframe capture issue](https://github.com/w3c/pointerevents/issues/493) documents unresolved boundary behavior; the `TESTING.md` browser matrix is the manual evidence owner.
- **Usage:** Unknown; no gesture or browser telemetry is collected.
- **Lifecycle:** Permanent while resize handles are parent-owned and the public preview remains in an iframe. The shield must be enabled only for an active transaction and restored on every commit, cancel, capture failure, frame teardown and workspace cleanup path.

## Visual-preview audience fallback for inaccessible galleries

- **Reason / protected scenario:** An administrator may switch the visual
  workspace to Anonymous while its current gallery requires a password, is
  private, or otherwise fails the ordinary visitor grant. Redirecting only the
  authenticated visual preview to the nearest accessible ancestor or Home keeps
  the workspace useful without rendering the denied gallery document or
  changing normal visitor access behavior. A listed password-gated child may
  remain as a normal locked, no-cover card under the existing public-listing
  policy; private children stay omitted.
- **Owner:** `app/controllers/public_gallery_page.php` selects the destination;
  `app/services/gallery_access.php` walks ancestors and applies the canonical
  no-administrator-bypass visitor policy; the visual editor HUD displays the
  fixed `visual_notice=anonymous_fallback` token as generic localized copy;
  `app/services/seo_request_guard.php` retains it through Home canonicalization
  only alongside the exact visual-preview and anonymous-audience markers.
- **Evidence / tests:** `tests/public_visual_preview_workflow_test.php` covers
  the real HTTP redirect for password-gated and private galleries,
  nearest-ancestor and Home fallback, cyclic ancestry, preserved
  preview/audience/notice markers across canonicalization, no-store behavior,
  locked listed-child rendering, private-child omission, and the unchanged
  ordinary-visitor password gate. `tests/gallery_workflow_browser_test.php`
  exercises the actual authenticated Admin browser flow from the protected
  signed-in gallery to Anonymous, checking the ancestor URL, preview/audience
  markers, generic notice, unchanged CSS draft and Admin URL, zero mutation
  requests, and Back restoration of the signed-in gallery. Separately,
  `tests/theme_visual_editor_browser_test.mjs` uses a controlled synthetic
  iframe redirect to verify draft/history retention, notice display, and token
  removal from later navigation.
- **Usage:** Unknown; the product has no counter for audience-toggle
  fallbacks.
- **Lifecycle:** Permanent while the visual editor offers an Anonymous
  audience toggle and gallery navigation. Retirement requires an explicit
  replacement UX that preserves access enforcement, draft state, and a clear
  explanation when the selected gallery is unavailable.

## Installed CSS and separate public manual overrides

- **Owner and reason:** Theme/Custom CSS preserves the existing `public/assets/custom.css` preset/upload contract while a separate editor owns `public/assets/custom-overrides.css`. Ordinary Theme saves, skin replacement and unrelated resets must not erase manual edits.
- **Protected behavior:** Existing installations retain their appearance when advanced controls are unset or invalid. Manual CSS is an optional final public layer; an absent, invalid-transport or unreadable override file is omitted without blocking public rendering, and the protected editor exposes a bounded error. CSS syntax recovery remains the browser's responsibility. Native editor POST/redirect remains supported with its own CSRF and explicit clear checkbox.
- **Composite background-save compatibility:** The existing `custom_css_overrides_save($text, $revision)` call and `css_override_action=save` route remain CSS-only when no background operation is requested. The visual editor's explicit global Theme target carries `keep`, `replace`, or `remove` plus the opaque background revision; replacement attaches the reviewed File and remove stages only deletion of the global Theme image. The operation preserves `theme_background_source` (the gallery fallback mode), per-gallery `background_source` values and gallery cover assets. Its controller-prepared public context distinguishes the global Theme image, Theme gallery fallback, explicit gallery source, and no image; when an independent gallery-derived layer is active, the visual editor disables the Theme image target on that route and directs the administrator to Home. If background revision inspection is unavailable, only image operations are disabled and the existing CSS-only editor/save path remains usable; a ready empty image state still permits first upload. An unavailable preview URL hides the visual launch without claiming that a preview exists, while the editor controls remain initialized. Owner: `app/services/custom_css.php`, `app/services/custom_css/visual_background_save.php`, `app/services/gallery_backgrounds.php`, and the application-settings model transaction. CSS lock precedes the shared Theme background writer lock. Legacy direct POST/redirect remains supported.
- **Visual-preview stylesheet compatibility:** `app/controllers/shared_layout.php` prepares the marked stylesheet URLs by starting with `Gallery\Core\asset_url()` and then adding the cache revision and visual-preview/audience query context; `app/views/layout.php` only emits that controller model. The canonical helper examines both `SCRIPT_NAME` and `SCRIPT_FILENAME`, preserving the same public asset path for repository-root, `public/` document-root, and subdirectory-mounted deployments. `app/bootstrap/request.php` carries the marker to otherwise unmarked dependent resources only for same-origin, in-mount GET/HEAD requests whose referrer has the exact request origin; ordinary public stylesheet URLs and non-preview requests retain their existing form. `app/services/custom_css.php` owns the fail-closed `@import` and stylesheet-inspection decisions, while dispatch enforces them after preview authorization and before rendering. Keep this request-context bridge limited to those resources and methods; it is not a general route authorization or cross-origin referrer fallback.
- **Evidence:** `tests/custom_css_visual_preview_inspection_test.php`, `tests/public_visual_preview_policy_test.php`, `tests/public_visual_preview_workflow_test.php`, and `tests/public_visual_preview_asset_url_test.php` cover stylesheet inspection, route/resource boundaries, and canonical asset URL composition. The real-route `tests/support/gallery_workflow_browser.js` asserts preview/public `base.css` path and marker behavior, loaded body reset, and strict saved-width parity. Existing `theme_advanced_appearance_test.php`, `custom_css_preservation_test.php`, `app_settings_atomic_activation_model_test.php`, `theme_custom_css_rendering_test.php`, and Appearance/Custom CSS Chromium fixtures cover defaults, independent assets/resets, transaction rollback, failed writes, stale editors, escaping, isolation and header restoration. Installed usage and document-root distribution are **unknown**.
- **Retirement:** Permanent support for installed preset/upload assets, CSS-only callers, direct/no-JavaScript editor forms, and repository-root, `public/` document-root, and subdirectory-mounted installations. Removing any contract requires an explicitly approved migration of saved CSS, supported administrator clients and deployment configurations, plus acceptance evidence; no age-based expiry.

## Retiring a record

Do not delete a compatibility path because its record is old or its branch is
not visible in a local search. The owner should update this entry with the
proof that meets its retirement condition, identify migration and release-note
effects, add or adjust the regression/acceptance evidence, and link the change
that removes the behavior. Permanent records may remain as architectural
contracts rather than accumulating expiry dates.

## Release provenance schema v1 and v_0.125 legacy predecessor

Owner: hosted release-origin/promotion tooling. Schema v1 is permanently supported
for immutable origins, including the already initialized v_0.126. Extended tag,
release-side and tree identities live in separate evidence and future immutable
predecessor records. Never rewrite the existing origin to migrate its schema.
Usage: one confirmed v_0.126 initialization; other usage unknown.

The repository-specific v_0.125 legacy pin protects the actual annotated historical
tag and identical published/release-side tree. Historical qualification is
UNKNOWN_LEGACY. It grants no write/publication authority and cannot qualify any
other predecessor. Retain this historical proof permanently; future initializations
use normal durable qualification evidence. Regression: release_origin_test.mjs and
linear_release_graph_test.mjs, registered in the central audit. Direct CLI release
notes retain their explicit tag-based default; hosted preparation supplies verified
release-side SHA and checks tree equality. Direct CLI usage is unknown.
